<?php
/**
 * LoreRim Glue - flow-test harness (no game, no HerikaServer, no LLM).
 *
 * This file knows NOTHING about the glue plugin. It provides:
 *   - the CHIM surface the plugin touches, as recording stubs (terminate, prompt injection, action catalog,
 *     logEvent, RelationshipManager, $GLOBALS['db'])
 *   - an in-memory database that serves the query shapes PROTOCOL.md section 5 allows
 *   - scenario / check bookkeeping with three outcomes per check: ok, FAIL, PENDING
 *
 * Everything that knows plugin function names, state keys or prompt markers lives in adapter.php.
 * All test text in this folder is clinical on purpose: rules and markers only, never example dialogue.
 */

// ------------------------------------------------------------------ CHIM stubs
final class FxTerminated extends RuntimeException {}
final class FxPending extends RuntimeException {}

if (!function_exists('terminate')) {
    /** CHIM lib/auditing.php:26 - ends the request. Here: unwinds to the driver. */
    function terminate(...$ignored) { throw new FxTerminated('terminate()'); }
}
if (!function_exists('chimRegisterPromptInjection')) {
    /** CHIM lib/prompt_injections.php:10 */
    function chimRegisterPromptInjection(string $slot, string $id, $content, int $priority = 100): bool
    {
        Fx::$injections[] = ['slot' => $slot, 'id' => $id, 'text' => (string) $content, 'priority' => $priority];
        return true;
    }
}
if (!function_exists('logEvent')) {
    /** CHIM lib/chat_helper_functions.php:5345 - the event log that feeds context, memory and diary. */
    function logEvent($dataArray, $forcePeople = '') { Fx::$events[] = $dataArray; return true; }
}
if (!function_exists('herikaActionCatalogUpsertCustomRow')) {
    /** CHIM lib/core/action_catalog.php:3379 */
    function herikaActionCatalogUpsertCustomRow($row) { Fx::$catalog[(string) ($row['code_name'] ?? '')] = $row; return true; }
    /** CHIM lib/core/action_catalog.php:2494 */
    function herikaGetActionCatalogRow($codeName) { return Fx::$catalog[(string) $codeName] ?? null; }
    /** CHIM lib/core/action_catalog.php:2016 */
    function herikaActionCatalogResetCache() { return true; }
}
if (!function_exists('herikaActionCatalogGetBuiltinRequirements')) {
    /**
     * [0.5.0] CHIM lib/core/action_catalog.php:1145 - the ONE place CHIM states which factions make an
     * NPC a guard. Read out of the INSTALLED server when it is there, so a flow run and production
     * cannot drift; [] when it is not (a checkout on a machine with no HerikaServer), which the
     * scenario reports as a note rather than pretending to have checked.
     */
    function herikaActionCatalogGetBuiltinRequirements($codeName)
    {
        static $tab = null;
        if ($tab === null) {
            $tab = [];
            $f = '/var/www/html/HerikaServer/lib/core/action_catalog.php';
            $src = is_readable($f) ? (string) @file_get_contents($f) : '';
            if ($src !== '' && preg_match('/function\s+herikaActionCatalogGetBuiltinRequirements.*?\$requirements\s*=\s*\[(.*?)\n    \];/s', $src, $m)) {
                if (preg_match_all("/'([A-Za-z0-9_]+)'\s*=>\s*\[(.*?)\n        \]/s", $m[1], $rows, PREG_SET_ORDER)) {
                    foreach ($rows as $r) {
                        $req = [];
                        if (preg_match("/'npc_factions_any'\s*=>\s*\[([^\]]*)\]/", $r[2], $g)) {
                            preg_match_all("/'([0-9A-Fa-f]+)'/", $g[1], $ids);
                            $req['npc_factions_any'] = $ids[1];
                        }
                        if (preg_match("/'current_action_not_in'\s*=>\s*\[([^\]]*)\]/", $r[2], $g)) {
                            preg_match_all("/'([a-z]+)'/", $g[1], $st);
                            $req['activity']['current_action_not_in'] = $st[1];
                        }
                        if ($req) { $tab[$r[1]] = $req; }
                    }
                }
            }
        }
        return $tab[(string) $codeName] ?? [];
    }
    /**
     * [0.5.0] A faithful double of CHIM lib/core/action_catalog.php:1834 for the SUBSET the glue uses:
     * request_types_any and activity.current_action_not_in / current_action_in. Everything else matches.
     * It exists so d59 can prove that CHIM's own machinery refuses our row in combat without dragging
     * the whole HerikaServer bootstrap into a flow run.
     */
    function herikaActionCatalogRequirementsMatch($requirements, $context)
    {
        $requirements = is_array($requirements) ? $requirements : [];
        if (!$requirements) { return true; }
        $context = is_array($context) ? $context : [];
        $types = array_map('strtolower', (array) ($requirements['request_types_any'] ?? []));
        if ($types && !in_array(strtolower(trim((string) ($context['request_type'] ?? ''))), $types, true)) { return false; }
        $act = (array) ($requirements['activity'] ?? []);
        $status = (array) ($context['activity_status'] ?? []);
        $cur = strtolower(trim((string) ($status['current_action'] ?? '')));
        $not = array_map('strtolower', (array) ($act['current_action_not_in'] ?? []));
        if ($not && $cur !== '' && in_array($cur, $not, true)) { return false; }
        $in = array_map('strtolower', (array) ($act['current_action_in'] ?? []));
        if ($in && !in_array($cur, $in, true)) { return false; }
        return true;
    }
}
if (!class_exists('RelationshipManager')) {
    /** CHIM relationship_system: affinity -100..100. Tests set RelationshipManager::$aff['<npc>']. */
    class RelationshipManager
    {
        public static array $aff = [];
        public static array $unknownCalls = [];
        public static function getPlayerRelationship($npc) { return ['aff' => (int) (self::$aff[(string) $npc] ?? 0)]; }
        public static function __callStatic($name, $args) { self::$unknownCalls[$name] = true; return null; }
    }
}

/**
 * In-memory stand-in for $GLOBALS['db']. Serves exactly the shapes PROTOCOL.md section 5 allows:
 *   fetchOne("... FROM <table> WHERE npc_name='<name>'")           -> that row or []
 *   fetchOne("... FROM <table> WHERE active=1 AND updated_at > N") -> newest active row or []
 *   fetchOne("... FROM <table> WHERE active=0 ORDER BY updated_at DESC LIMIT 1") -> newest closed row or []
 *   fetchOne("SELECT to_regclass('<table>') AS t")                 -> the schema-marker probe: always present
 *   upsertRowOnConflict(table, data, key)                          -> merge by key (columns not given are kept, as in SQL)
 *   execQuery("DELETE FROM <table> WHERE npc_name='<name>'")       -> removes the row
 *   execQuery("UPDATE <table> SET col=val[, ...] WHERE npc_name='<name>'")
 * Anything else is recorded in $unhandled and reported at the end of the run (it would hit the real DB untested).
 */
final class FxDb
{
    public array $t = [];
    public array $unhandled = [];
    public static array $unhandledAll = []; // across every scenario of this process
    public array $log = [];

    public function escapeLiteral($s) { return "'" . str_replace("'", "''", (string) $s) . "'"; }

    public function upsertRowOnConflict($table, $data, $key)
    {
        $k = (string) ($data[$key] ?? '');
        $this->t[$table][$k] = $data + ($this->t[$table][$k] ?? []);
        return true;
    }

    private function nameIn(string $q): ?string
    {
        return preg_match("/npc_name\s*=\s*'((?:[^']|'')*)'/i", $q, $m) ? str_replace("''", "'", $m[1]) : null;
    }

    private function rowsFor(string $q): array
    {
        // [0.4.0 fix pass] the schema-marker probe of lrgTableExists(): "SELECT to_regclass('<t>') AS t".
        // It has no FROM clause. The fake answers "the table is there", which is what every flow assumes
        // (they all run with the marker file touched). PROTOCOL section 5 documents the shape.
        if (preg_match("/^\s*SELECT\s+to_regclass\(\s*'((?:[^']|'')*)'\s*\)/i", trim($q), $g)) {
            return [['t' => str_replace("''", "'", $g[1])]];
        }
        if (!preg_match('/\bFROM\s+"?(\w+)"?/i', $q, $m)) { $this->miss($q); return []; }
        $rows = $this->t[$m[1]] ?? [];
        $name = $this->nameIn($q);
        if ($name !== null) { return isset($rows[$name]) ? [$rows[$name]] : []; }
        // a read of any other shape must be REPORTED, not answered with the first row of the table:
        // scenario 00's PROTOCOL section 5 guard could otherwise never fail
        if (preg_match("/\bWHERE\s+md5\s*=\s*'((?:[^']|'')*)'/i", $q, $h)) {
            $md5 = str_replace("''", "'", $h[1]);
            foreach ($rows as $r) { if (strcasecmp((string) ($r['md5'] ?? ''), $md5) === 0) { return [$r]; } }
            return [];
        }
        // [0.4 integrator] The scene-row shape is `WHERE active=<0|1>` with an OPTIONAL `AND updated_at > N`.
        // active=1 (+ the window) is the [v1] open-scene read; active=0 ... ORDER BY updated_at DESC LIMIT 1
        // is the narrator-warn read of lrgWarnNarratorAfterScene() (lib/lrg_actions.php, Phase 1 code that
        // predates this round), which asks "which scene ended most recently, of ANY NPC" - the partner's
        // name is exactly what a Narrator-routed request is missing. Both are served here rather than
        // whitelisted, so the code path is really exercised. PROTOCOL section 5 documents both shapes.
        if (!preg_match('/\bWHERE\s+active\s*=\s*([01])\b(?:\s+AND\s+updated_at\s*>\s*-?\d+)?/i', $q, $a)) { $this->miss($q); return []; }
        $rows = array_values($rows);
        $want = (int) $a[1];
        $rows = array_values(array_filter($rows, fn($r) => (int) ($r['active'] ?? 0) === $want));
        if (preg_match('/updated_at\s*>\s*(-?\d+)/i', $q, $u)) { $rows = array_values(array_filter($rows, fn($r) => (int) ($r['updated_at'] ?? 0) > (int) $u[1])); }
        if (preg_match('/ORDER BY\s+updated_at\s+DESC/i', $q)) { usort($rows, fn($a, $b) => (int) ($b['updated_at'] ?? 0) <=> (int) ($a['updated_at'] ?? 0)); }
        return $rows;
    }

    public function fetchOne($q) { $this->log[] = (string) $q; $r = $this->rowsFor((string) $q); return $r[0] ?? []; }
    public function fetchAll($q) { $this->log[] = (string) $q; return $this->rowsFor((string) $q); }

    public function execQuery($q)
    {
        $q = trim((string) $q);
        $this->log[] = $q;
        // [0.5.0] DO joins the migration shapes: 007_lrg_prompt_schema.sql moves the index tables with a
        // dollar-quoted DO block (ALTER TABLE ... SET SCHEMA, guarded by to_regclass). The fake answers
        // "applied" exactly as it does for CREATE / ALTER. PROTOCOL section 5 documents the shape.
        if (preg_match('/^(CREATE|ALTER|COMMENT|DROP INDEX|BEGIN|COMMIT|DO\s*\$)/i', $q)) { return true; }
        $name = $this->nameIn($q);
        if (preg_match('/^DELETE\s+FROM\s+"?(\w+)"?/i', $q, $m) && $name !== null) { unset($this->t[$m[1]][$name]); return true; }
        if (preg_match('/^UPDATE\s+"?(\w+)"?\s+SET\s+(.+?)\s+WHERE\s/is', $q, $m) && $name !== null && isset($this->t[$m[1]][$name])) {
            foreach (preg_split('/,(?=(?:[^\']*\'[^\']*\')*[^\']*$)/', $m[2]) as $set) {
                if (preg_match("/^\s*\"?(\w+)\"?\s*=\s*(?:'((?:[^']|'')*)'|(-?[\d.]+))\s*$/", $set, $s)) {
                    $this->t[$m[1]][$name][$s[1]] = isset($s[3]) && $s[3] !== '' ? $s[3] + 0 : str_replace("''", "'", $s[2]);
                } else { $this->miss($q); }
            }
            return true;
        }
        $this->miss($q);
        return true;
    }

    public function __call($name, $args) { $this->miss("method $name()"); return []; }
    private function miss(string $what): void { $this->unhandled[] = $what; self::$unhandledAll[] = $what; }

    /** Test helper: decoded jsonb payload of one row, [] when none. */
    public function payload(string $table, string $npc): array
    {
        $p = $this->t[$table][$npc]['payload'] ?? null;
        if (is_array($p)) { return $p; }
        return is_string($p) ? (json_decode($p, true) ?: []) : [];
    }
}

// ------------------------------------------------------------------ bookkeeping
final class Fx
{
    public static array $scenarios = [];   // id => ['title','fn','variant']
    public static array $injections = [];
    public static array $events = [];
    public static array $catalog = [];
    public static array $phpIssues = [];   // "file:line message" => count
    public static array $caps = [];        // capability => bool (filled by the adapter)
    public static array $capWhy = [];      // capability => what is missing, in words
    public static array $seenScenes = [];  // every scene id the plugin offered or put on the wire during this process => where
    public static bool $trace = false;
    public static string $pluginDir = '';

    public static function trace(string $msg): void { if (self::$trace) { echo '      . ' . str_replace("\n", "\n        ", rtrim($msg)) . "\n"; } }
    public static function has($caps): bool
    {
        foreach ((array) $caps as $c) { if (empty(self::$caps[$c])) { return false; } }
        return true;
    }
    public static function missing($caps): array { return array_values(array_filter((array) $caps, fn($c) => empty(self::$caps[$c]))); }
}

function fx_scenario(string $id, string $title, callable $fn, string $variant = 'main'): void
{
    Fx::$scenarios[$id] = ['title' => $title, 'fn' => $fn, 'variant' => $variant];
}

/** One scenario's checks. must = the contract says so; should = a reasonable expectation beyond its letter (warning only). */
final class FxT
{
    public array $checks = []; // ['level' => must|should, 'status' => ok|fail|pending, 'name', 'detail']
    public array $notes = [];

    private function record(string $level, string $name, $ok, $detail, array $needs): bool
    {
        $status = 'ok'; $text = '';
        $missing = Fx::missing($needs);
        if ($missing) {
            $status = 'pending';
            $text = 'needs ' . implode(', ', array_map(fn($c) => $c . ' (' . (Fx::$capWhy[$c] ?? 'not delivered') . ')', $missing));
        } else {
            try {
                $val = $ok instanceof Closure ? $ok() : $ok;
                if (!$val) { $status = 'fail'; $text = (string) ($detail instanceof Closure ? $detail() : $detail); }
            } catch (FxPending $e) {
                $status = 'pending'; $text = $e->getMessage();
            } catch (Throwable $e) {
                if ($e instanceof FxTerminated) { throw $e; }
                if (preg_match('/Call to undefined function (lrg\w+)\(\)/', $e->getMessage(), $m)) {
                    $status = 'pending'; $text = 'plugin function ' . $m[1] . '() is not delivered yet';
                } else {
                    $status = 'fail'; $text = get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine();
                }
            }
        }
        $this->checks[] = ['level' => $level, 'status' => $status, 'name' => $name, 'detail' => $text];
        $tag = ['ok' => '  ok   ', 'pending' => '  pend ', 'fail' => $level === 'must' ? '  FAIL ' : '  warn '][$status];
        echo $tag . $name . ($text !== '' ? '  [' . fx_short($text) . ']' : '') . "\n";
        return $status === 'ok';
    }

    public function must(string $name, $ok, $detail = ''): bool { return $this->record('must', $name, $ok, $detail, []); }
    public function should(string $name, $ok, $detail = ''): bool { return $this->record('should', $name, $ok, $detail, []); }
    /** Evaluated only when every capability in $caps is present; otherwise the check is PENDING with the reason. */
    public function mustCap($caps, string $name, Closure $ok, $detail = ''): bool { return $this->record('must', $name, $ok, $detail, (array) $caps); }
    public function shouldCap($caps, string $name, Closure $ok, $detail = ''): bool { return $this->record('should', $name, $ok, $detail, (array) $caps); }
    /** A check that cannot be made with the data at hand (for example: the installed packs have no such scene). */
    public function pending(string $name, string $reason): void
    {
        $this->checks[] = ['level' => 'must', 'status' => 'pending', 'name' => $name, 'detail' => $reason];
        echo '  pend ' . $name . '  [' . fx_short($reason) . "]\n";
    }
    public function note(string $msg): void { $this->notes[] = $msg; echo '  note ' . $msg . "\n"; }

    public function outcome(): string
    {
        $fail = 0; $pend = 0;
        foreach ($this->checks as $c) {
            if ($c['status'] === 'fail' && $c['level'] === 'must') { $fail++; }
            if ($c['status'] === 'pending') { $pend++; }
        }
        return $fail ? 'FAIL' : ($pend ? 'PENDING' : 'PASS');
    }
    public function count(string $status, ?string $level = null): int
    {
        return count(array_filter($this->checks, fn($c) => $c['status'] === $status && ($level === null || $c['level'] === $level)));
    }
}

function fx_short(string $s, int $max = 260): string
{
    $s = trim((string) preg_replace('/\s+/', ' ', $s));
    return strlen($s) > $max ? substr($s, 0, $max - 3) . '...' : $s;
}

/** PHP warnings / notices raised while a scenario runs are collected, de-duplicated and listed at the end. */
function fx_install_error_handler(): void
{
    set_error_handler(static function (int $no, string $msg, string $file = '', int $line = 0): bool {
        if (!(error_reporting() & $no)) { return true; } // silenced with @
        $where = (str_contains($file, DIRECTORY_SEPARATOR . 'flows' . DIRECTORY_SEPARATOR) ? 'flows/' : 'plugin/') . basename($file) . ':' . $line;
        $key = $where . '  ' . $msg;
        Fx::$phpIssues[$key] = (Fx::$phpIssues[$key] ?? 0) + 1;
        return true;
    });
}

/**
 * Run the registered scenarios of one variant. Returns [id => ['title','outcome','fail','pending','warn','ok','checks','notes']].
 * $knownPending: [id => reason] - a FAIL of a listed scenario is reported as PENDING (the reason is printed).
 */
function fx_run(string $variant, array $only, array $knownPending, callable $reset): array
{
    $results = [];
    foreach (Fx::$scenarios as $id => $sc) {
        $id = (string) $id; // PHP turns the array key "16" into an int
        if ($sc['variant'] !== $variant) { continue; }
        if ($only && !in_array($id, $only, true)) { continue; }
        echo "\n[$id] " . $sc['title'] . "\n";
        $t = new FxT();
        $reset();
        try {
            ($sc['fn'])($t);
        } catch (FxPending $e) {
            $t->pending('scenario stopped early', $e->getMessage());
        } catch (Throwable $e) {
            if (preg_match('/Call to undefined function (lrg\w+)\(\)/', $e->getMessage(), $m)) {
                $t->pending('scenario stopped early', 'plugin function ' . $m[1] . '() is not delivered yet');
            } else {
                $t->must('scenario ran to the end', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
            }
        }
        $outcome = $t->outcome();
        if ($outcome === 'FAIL' && isset($knownPending[$id])) {
            $outcome = 'PENDING';
            echo '  pend (known) ' . $knownPending[$id] . "\n";
            foreach ($t->checks as &$c) { // the failing checks of a known-pending scenario are reported as pending, with the reason in front
                if ($c['status'] === 'fail' && $c['level'] === 'must') { $c['status'] = 'pending'; $c['detail'] = 'KNOWN: ' . $knownPending[$id] . ' | ' . $c['detail']; }
            }
            unset($c);
        } elseif ($outcome === 'PASS' && isset($knownPending[$id])) {
            $t->note('listed as known-pending but it passes now: remove it from fxKnownPending() in adapter.php');
        }
        $results[$id] = ['title' => $sc['title'], 'outcome' => $outcome, 'fail' => $t->count('fail', 'must'), 'pending' => $t->count('pending'),
            'warn' => $t->count('fail', 'should'), 'ok' => $t->count('ok'), 'checks' => $t->checks, 'notes' => $t->notes];
        echo "  => $outcome\n";
    }
    return $results;
}
