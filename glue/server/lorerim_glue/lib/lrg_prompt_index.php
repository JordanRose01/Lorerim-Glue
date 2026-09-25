<?php
/**
 * LoreRim Glue - Phase 2 PROMPT INDEX (design 4.2, plan 5.1).
 *
 * The index is ADVISORY. Execution is always "click the live entry the engine built". A wrong index can
 * cost one confirmation too many or too few; it can never cause a wrong effect.
 *
 * Built offline by tools/build_prompt_index.py (pure python, read-only, inside WSL) into
 * data/prompt_index.ndjson, then loaded here into Postgres:
 *     php lib/lrg_prompt_index.php load            # load data/prompt_index.ndjson into lrg_prompt
 *     php lib/lrg_prompt_index.php status          # print what the server currently knows
 * Rebuilt by tools/deploy_server.ps1 and whenever the hash of plugins.txt + modlist.txt changes -
 * checked at most once a minute on the fast ev=facts path and NEVER inside an LLM request (the same
 * discipline Phase 1 uses for the scene index, preprocessing.php:19-21).
 *
 * Test seam: $GLOBALS['LRG_TEST_INDEX'] - when it is SET (even to []), this file touches no database at
 * all and answers every lookup from that array. The offline flow tests always set it.
 */

require_once __DIR__ . '/lrg_core.php';

if (!defined('LRG_PROMPT_INDEX_VERSION')) {
    define('LRG_PROMPT_INDEX_VERSION', 1);
}

// ---------------------------------------------------------------- [0.5.0] the index's own schema
/**
 * THE INDEX LIVES IN ITS OWN POSTGRES SCHEMA (plan 9.1 / E4(a), verify report C9).
 * CHIM's playthrough switch drops the whole `public` schema and clones a saved profile back in, and no
 * saved profile carries a Phase 2 table - so 37,561 rows of LOAD-ORDER data were dying on every
 * playthrough switch. Migration 007 MOVES the two tables to `lrg_index` (ALTER TABLE ... SET SCHEMA,
 * never a rebuild). Everything else the glue stores really is playthrough data and stays in `public`.
 * Config: dialogue.index.schema. The name is validated as an identifier before it reaches any SQL.
 */
function lrgPromptSchema(): string
{
    static $s = null;
    if ($s !== null) { return $s; }
    $dlg = (array) (lrgConfig()['dialogue'] ?? []);
    $idx = (array) ($dlg['index'] ?? []);
    $want = trim((string) ($idx['schema'] ?? ''));
    if ($want === '' || !preg_match('/^[a-z_][a-z0-9_]{0,30}$/', $want)) { $want = 'lrg_index'; }
    $s = $want;
    return $s;
}

/**
 * ONE-RELEASE FALLBACK. A server whose code is 0.5 but whose migration has not run yet (the deploy is a
 * separate step, and the owner may restart php-fpm before running it) would answer every lookup with
 * "unindexed" - one confirmation too many on every single entry. So the first qualified SELECT that
 * comes back "relation does not exist" switches this request to `public` and says so once in the log.
 * It is deliberately per-request and not cached to disk: the moment the deploy runs, the next request
 * is back on lrg_index with no intervention.
 */
function lrgPromptSchemaActive(): string
{
    return (string) ($GLOBALS['LRG_PROMPT_SCHEMA_ACTIVE'] ?? lrgPromptSchema());
}

function lrgPromptSchemaFallback(string $why): bool
{
    if (lrgPromptSchemaActive() === 'public') { return false; }
    $GLOBALS['LRG_PROMPT_SCHEMA_ACTIVE'] = 'public';
    lrgLog('prompt index: ' . lrgPromptSchema() . ' is not there yet (' . substr($why, 0, 120)
        . ') - reading public for this request. Run tools/deploy_server.ps1 (migration 007 moves the index).');
    return true;
}

/**
 * True when a database error really is "that table is not in that schema", and not some other failure.
 * [0.5.0 fix pass / S-5] The old pattern matched any 'does not exist', so a missing FUNCTION, COLUMN or
 * SCHEMA flipped the whole request to `public` and printed misleading "run deploy_server.ps1" advice for
 * a fault the deploy cannot fix. Postgres spells the one case we mean 'relation "x" does not exist'
 * (SQLSTATE 42P01), so that is what is matched.
 */
function lrgPromptMissingTable(string $msg): bool
{
    return (bool) preg_match('/(relation\s+.*does not exist|undefined_table|undefined table|42P01)/i', $msg);
}

/**
 * Every index SELECT goes through here. `{s}` in the SQL is the active schema. One retry, and only ever
 * the one retry, when the qualified table is not there and `public` still has it.
 * Returns a list of rows ([] on any other failure - the index is advisory and never fatal).
 */
function lrgPromptFetch(string $sql, bool $one = false): array
{
    $db = lrgDb();
    if (!$db) { return []; }
    for ($try = 0; $try < 2; $try++) {
        $q = str_replace('{s}', lrgPromptSchemaActive(), $sql);
        try {
            $r = $one ? $db->fetchOne($q) : $db->fetchAll($q);
            if ($one) { return is_array($r) && $r ? [$r] : []; }
            return (array) $r;
        } catch (Throwable $e) {
            $msg = $e->getMessage();
            if ($try === 0 && lrgPromptMissingTable($msg) && lrgPromptSchemaFallback($msg)) { continue; }
            lrgLog('prompt index: lookup failed: ' . $msg);
            return [];
        }
    }
    return [];
}

/**
 * Split a migration file into statements. `explode(';', ...)` was enough until 007, whose DO block
 * carries ';' INSIDE a dollar-quoted body - splitting on those would hand Postgres four fragments and
 * the move would never happen. Dollar quoting is the only extra rule this needs; ordinary string
 * literals in these files contain no ';'.
 */
function lrgPromptSqlStatements(string $sql): array
{
    // a UTF-8 BOM in front of CREATE SCHEMA is a Postgres syntax error, and an editor on Windows adds
    // one without being asked. Stripping it here costs nothing and turns a silent no-op into a no-op
    // that cannot happen.
    if (strncmp($sql, "\xEF\xBB\xBF", 3) === 0) { $sql = substr($sql, 3); }
    $sql = (string) preg_replace('/^\s*--.*$/m', '', $sql);
    $out = [];
    $buf = '';
    $tag = '';
    $len = strlen($sql);
    for ($i = 0; $i < $len; $i++) {
        $c = $sql[$i];
        if ($tag === '' && $c === '$' && preg_match('/^\$[A-Za-z_][A-Za-z0-9_]*\$|^\$\$/', substr($sql, $i), $m)) {
            $tag = $m[0];
            $buf .= $tag;
            $i += strlen($tag) - 1;
            continue;
        }
        if ($tag !== '' && $c === '$' && strncmp(substr($sql, $i), $tag, strlen($tag)) === 0) {
            $buf .= $tag;
            $i += strlen($tag) - 1;
            $tag = '';
            continue;
        }
        if ($tag === '' && $c === ';') {
            if (trim($buf) !== '') { $out[] = trim($buf); }
            $buf = '';
            continue;
        }
        $buf .= $c;
    }
    if (trim($buf) !== '') { $out[] = trim($buf); }
    return $out;
}

// ---------------------------------------------------------------- normalisation (the key rule)
/**
 * THE key rule. Mirrored byte for byte by norm_prompt() in tools/build_prompt_index.py - change both.
 *  1 lower  2 drop up to two LEADING (tag)/[tag]  3 drop up to two TRAILING tags (a price tag included)
 *  4 <Token> -> <t>  5 digits -> #  6 keep a-z0-9#<>' and space  7 collapse whitespace
 * Step 3 is what makes the plugin's "(<BribeCost> gold)" and the menu's "(137 gold)" the same key.
 */
function lrgPromptNorm(string $text): string
{
    if ($text === '') { return ''; }
    $s = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
    for ($i = 0; $i < 2; $i++) {
        $s2 = (string) preg_replace('/^\s*[\(\[][^)\]]{1,40}[\)\]]\s*/u', '', $s, 1);
        if ($s2 === $s) { break; }
        $s = $s2;
    }
    for ($i = 0; $i < 2; $i++) {
        $s2 = (string) preg_replace('/\s*[\(\[][^)\]]{1,40}[\)\]]\s*[.!?\x{2026}]*\s*$/u', '', $s, 1);
        if ($s2 === $s) { break; }
        $s = $s2;
    }
    $s = (string) preg_replace('/<[^<>]{1,60}>/u', ' <t> ', $s);
    $s = (string) preg_replace('/\d[\d,\.]*/u', '#', $s);
    $s = (string) preg_replace('/[^a-z0-9#<>\' ]+/u', ' ', $s);
    return trim((string) preg_replace('/\s+/u', ' ', $s));
}

/** The gold price in a prompt: >0 a literal, -1 "the price is a token, read it from the live text", 0 none. */
function lrgPromptCost(string $text): int
{
    if (preg_match('/\(([^()]*?)(\d[\d,\.]*)\s*(gold|septims?)\b[^()]*\)\s*\.?\s*$/i', $text, $m)) {
        return (int) preg_replace('/[^0-9]/', '', $m[2]);
    }
    if (preg_match('/\([^()]*<[^<>]+>[^()]*\b(gold|septims?)\b[^()]*\)\s*\.?\s*$/i', $text)) { return -1; }
    return 0;
}

/**
 * Words that carry meaning, for the token-overlap tier of the matcher.
 * [pt19 v1.0 / spec S4.5, ai R2] `$strict` adds the STRICT list BESIDE the shipped one: question words, modals, fillers and
 * pronouns. It is for DECISIONS only (a "meaning", "shared" or "foreign" word, precision, a park's "named", the pre-LLM open's
 * clauses 4-5 - lib/lrg_dialogue.php); every SCORE keeps the shipped list, so the index, its hash and every measured number
 * of the matcher are untouched. `um er erm hmm ah mm hey` are the owner's STT fillers (research/pt19c-language.md G7):
 * "um, is that it?" must not carry a foreign word against "Is that it?". [pt19c-A fix 1] the STT drops the apostrophe, so the
 * fold-only stems of the contractions (ill im ive id youre dont thats lets ...; "I'll" itself splits into i + ll) are no
 * meaning words either: "yes, Ill help" carries no foreign word "ill" against "What else can I help you with?".
 */
function lrgPromptWords(string $norm, bool $strict = false): array
{
    static $stop = ['the' => 1, 'a' => 1, 'an' => 1, 'i' => 1, 'to' => 1, 'of' => 1, 'and' => 1, 'is' => 1, 'it' => 1,
        'you' => 1, 'your' => 1, 'my' => 1, 'me' => 1, 'do' => 1, 'for' => 1, 'in' => 1, 'on' => 1, 'that' => 1,
        'this' => 1, 'what' => 1, 'was' => 1, 'are' => 1, 'be' => 1, 'have' => 1, 'has' => 1, 'with' => 1,
        'so' => 1, 'but' => 1, 'not' => 1, 'no' => 1, 'yes' => 1, 'd' => 1, 's' => 1, 't' => 1, 'll' => 1, 're' => 1];
    static $strictStop = ['why' => 1, 'how' => 1, 'who' => 1, 'where' => 1, 'when' => 1, 'which' => 1, 'can' => 1,
        'could' => 1, 'would' => 1, 'should' => 1, 'does' => 1, 'mean' => 1, 'now' => 1, 'then' => 1, 'wait' => 1,
        'uh' => 1, 'they' => 1, 'them' => 1, 'these' => 1, 'those' => 1, 'there' => 1, 'here' => 1, 'we' => 1, 'us' => 1,
        'he' => 1, 'she' => 1, 'him' => 1, 'his' => 1, 'her' => 1, 'its' => 1, 'our' => 1, 'their' => 1,
        'um' => 1, 'er' => 1, 'erm' => 1, 'hmm' => 1, 'ah' => 1, 'mm' => 1, 'hey' => 1,
        'ill' => 1, 'im' => 1, 'ive' => 1, 'id' => 1, 've' => 1, 'youre' => 1, 'youll' => 1, 'youve' => 1, 'youd' => 1,
        'dont' => 1, 'doesnt' => 1, 'didnt' => 1, 'cant' => 1, 'wont' => 1, 'isnt' => 1, 'arent' => 1, 'wasnt' => 1,
        'thats' => 1, 'lets' => 1, 'whats' => 1, 'theres' => 1, 'hes' => 1, 'shes' => 1, 'were' => 1, 'theyre' => 1,
        'don' => 1, 'won' => 1, 'isn' => 1, 'aren' => 1, 'wasn' => 1, 'weren' => 1, 'didn' => 1, 'doesn' => 1, 'hasn' => 1,
        'haven' => 1, 'hadn' => 1, 'shouldn' => 1, 'wouldn' => 1, 'couldn' => 1, 'ain' => 1];
    $out = [];
    foreach (preg_split('/[^a-z0-9#]+/', $norm) ?: [] as $w) {
        if ($w !== '' && strlen($w) > 1 && !isset($stop[$w]) && !($strict && isset($strictStop[$w]))) { $out[$w] = 1; }
    }
    return array_keys($out);
}

// ---------------------------------------------------------------- files / status
function lrgPromptIndexFile(): string
{
    return (string) (lrgConfig()['dialogue']['index_file'] ?? (LRG_DIR . '/data/prompt_index.ndjson'));
}

function lrgPromptIndexMarker(): string
{
    return LRG_DIR . '/data/.prompt_index_v' . LRG_PROMPT_INDEX_VERSION;
}

/** Header of the ndjson (line 1) without reading the whole file. [] when there is none. */
function lrgPromptIndexHeader(): array
{
    static $h = null;
    if ($h !== null) { return $h; }
    $h = [];
    $f = @fopen(lrgPromptIndexFile(), 'rb');
    if ($f) {
        $line = (string) fgets($f, 1024 * 512);
        fclose($f);
        $d = json_decode($line, true);
        if (is_array($d) && ($d['_'] ?? '') === 'header') { $h = $d; }
    }
    return $h;
}

/**
 * What the server knows right now. Keys: ok, source, rows, layers, version, built_at, hash, kinds, error.
 * 'source' is 'test' (the seam), 'db' (loaded) or 'file' (built but not loaded) or 'none'.
 */
function lrgPromptIndexStatus(): array
{
    $hdr = lrgPromptIndexHeader();
    $out = ['ok' => false, 'source' => 'none', 'rows' => 0, 'layers' => 0, 'version' => LRG_PROMPT_INDEX_VERSION,
        'built_at' => (int) ($hdr['built_at'] ?? 0), 'hash' => (string) ($hdr['hash'] ?? ''),
        'file_rows' => (int) ($hdr['rows'] ?? 0), 'kinds' => (array) ($hdr['check_kinds'] ?? []),
        'unreadable' => count((array) ($hdr['unreadable'] ?? [])), 'error' => ''];
    if (array_key_exists('LRG_TEST_INDEX', $GLOBALS)) {
        $seam = lrgPromptTestRows();
        $out['source'] = 'test';
        $out['rows'] = count($seam);
        $out['layers'] = count(lrgPromptTestLayers());
        $out['ok'] = true;
        $out['kinds'] = array_values(array_unique(array_filter(array_map(static fn($r) => (string) ($r['kind'] ?? ''), $seam))));
        return $out;
    }
    if (!empty($hdr['FAIL'])) { $out['error'] = (string) $hdr['FAIL']; }
    $db = lrgDb();
    if ($db) {
        $row = lrgPromptFetch('SELECT count(*) AS n FROM {s}.lrg_prompt', true);
        $out['rows'] = (int) (($row[0] ?? [])['n'] ?? 0);
        $row2 = lrgPromptFetch('SELECT count(*) AS n FROM {s}.lrg_prompt_layer', true);
        $out['layers'] = (int) (($row2[0] ?? [])['n'] ?? 0);
        if (!$row) { $out['error'] = $out['error'] ?: ('lrg_prompt is not readable in schema ' . lrgPromptSchemaActive()); }
    }
    // [0.5.0] the schema really in use, and whether a coverage run has ever been made (plan 10.1)
    $out['schema'] = lrgPromptSchemaActive();
    $out['coverage_rows'] = 0;
    $cov = LRG_DIR . '/data/prompt_coverage.csv';
    if (is_file($cov)) {
        $n = 0;
        $f = @fopen($cov, 'rb');
        if ($f) { while (fgets($f) !== false) { $n++; } fclose($f); }
        $out['coverage_rows'] = max(0, $n - 1);          // minus the header line
    }
    $out['source'] = $out['rows'] > 0 ? 'db' : ($out['file_rows'] > 0 ? 'file' : 'none');
    $out['ok'] = $out['rows'] > 0;
    return $out;
}

/**
 * [0.5.0 / E2(b)] "What can I ask you?" when this NPC's list cache is EMPTY. Top-level, non-check,
 * non-critical prompts of the quests the game already said she is part of, least-shared first, so a
 * generic line that 40 topics carry never outranks a real one. The result is APPROXIMATE by
 * construction and the block that renders it says so and forbids executing from it (plan 7.2).
 * Never a fallback for "no quests at all": an empty $quests returns [] and the block is not emitted.
 */
function lrgPromptByQuest(array $quests, int $limit = 6): array
{
    $ids = [];
    foreach ($quests as $q) {
        $q = trim((string) $q);
        if ($q !== '' && $q !== '-') { $ids[strtolower($q)] = $q; }
    }
    if (!$ids) { return []; }
    $limit = max(1, min(20, $limit));
    if (array_key_exists('LRG_TEST_INDEX', $GLOBALS)) {
        $out = [];
        foreach (lrgPromptTestRows() as $r) {
            if ((int) ($r['toplevel'] ?? 0) !== 1) { continue; }
            if ((string) ($r['kind'] ?? '') !== '') { continue; }
            if ((int) ($r['crit'] ?? 0) !== 0) { continue; }
            if ((int) ($r['shared'] ?? 1) > 20) { continue; }
            if (!isset($ids[strtolower((string) ($r['quest'] ?? ''))])) { continue; }
            $out[] = ['txt' => (string) ($r['txt'] ?? ''), 'quest' => (string) ($r['quest'] ?? ''),
                'topic' => (string) ($r['topic'] ?? ''), 'kind' => (string) ($r['kind'] ?? ''),
                'journal' => (int) ($r['journal'] ?? 0), 'shared' => (int) ($r['shared'] ?? 1)];
        }
        usort($out, static fn($a, $b) => ($b['journal'] <=> $a['journal']) ?: ($a['shared'] <=> $b['shared']));
        return array_slice($out, 0, $limit);
    }
    $db = lrgDb();
    if (!$db) { return []; }
    $in = [];
    foreach ($ids as $q) { $in[] = $db->escapeLiteral($q); }
    $rows = lrgPromptFetch('SELECT txt, quest, topic, kind, journal, shared FROM {s}.lrg_prompt'
        . ' WHERE quest IN (' . implode(',', $in) . ") AND toplevel = 1 AND kind = '' AND crit = 0"
        . ' AND shared <= 20 ORDER BY journal DESC, shared ASC LIMIT ' . $limit);
    $out = [];
    foreach ($rows as $r) {
        $out[] = ['txt' => (string) ($r['txt'] ?? ''), 'quest' => (string) ($r['quest'] ?? ''),
            'topic' => (string) ($r['topic'] ?? ''), 'kind' => (string) ($r['kind'] ?? ''),
            'journal' => (int) ($r['journal'] ?? 0), 'shared' => (int) ($r['shared'] ?? 1)];
    }
    return $out;
}

/**
 * [pt19 v1.0 / spec S2.1 clause 5, U8] The JOURNAL rows of these quests, top-level first, at most $cap - read-only. It is
 * what the pre-LLM open matches "her journal-quest rows" against (one round trip per NPC per session, cached by the caller)
 * and, with $cap 1, the answer to "does the index know this quest as a journal quest" (the scene test, spec S1.3). A
 * shared town quest (DialogueWhiterun, journal=0) never appears here: its lines are other NPCs' lines.
 * Returns [['norm','txt','quest','topic','info_key','toplevel','journal','scripted','kind','crit','cost','goodbye'], ...].
 */
function lrgPromptRowsForQuests(array $quests, int $cap): array
{
    $ids = [];
    foreach ($quests as $q) {
        $q = trim((string) $q);
        if ($q !== '' && $q !== '-') { $ids[strtolower($q)] = $q; }
    }
    if (!$ids) { return []; }
    $cap = max(1, min(2000, $cap));
    $shape = static function (array $r): array {
        $flags = is_array($r['flags'] ?? null) ? $r['flags'] : (json_decode((string) ($r['flags'] ?? ''), true) ?: []);
        return ['norm' => (string) ($r['norm'] ?? ''), 'txt' => (string) ($r['txt'] ?? ''), 'quest' => (string) ($r['quest'] ?? ''),
            'topic' => (string) ($r['topic'] ?? ''), 'info_key' => (string) ($r['info_key'] ?? ''),
            'toplevel' => (int) ($r['toplevel'] ?? 0), 'journal' => (int) ($r['journal'] ?? 0),
            'scripted' => (int) ($r['scripted'] ?? 0), 'kind' => (string) ($r['kind'] ?? ''), 'crit' => (int) ($r['crit'] ?? 0),
            'cost' => (int) ($r['cost'] ?? 0), 'goodbye' => (int) ($flags['goodbye'] ?? 0)];
    };
    if (array_key_exists('LRG_TEST_INDEX', $GLOBALS)) {
        $out = [];
        foreach (lrgPromptTestRows() as $r) {
            if ((int) ($r['journal'] ?? 0) !== 1) { continue; }
            if (!isset($ids[strtolower((string) ($r['quest'] ?? ''))])) { continue; }
            if ((string) ($r['norm'] ?? '') === '') { continue; }
            $out[] = $shape($r);
        }
        usort($out, static fn($a, $b) => $b['toplevel'] <=> $a['toplevel']);
        return array_slice($out, 0, $cap);
    }
    $db = lrgDb();
    if (!$db) { return []; }
    $in = [];
    foreach ($ids as $q) { $in[] = $db->escapeLiteral($q); }
    $rows = lrgPromptFetch('SELECT norm, txt, quest, topic, info_key, toplevel, journal, scripted, kind, crit, cost, flags'
        . ' FROM {s}.lrg_prompt WHERE quest IN (' . implode(',', $in) . ") AND journal = 1 AND norm <> ''"
        . ' ORDER BY toplevel DESC LIMIT ' . $cap);
    return array_map($shape, $rows);
}

/** The kinds census the builder found. This is the ANSWER to "which check kinds exist here", never a guess. */
function lrgPromptKinds(): array
{
    $hdr = lrgPromptIndexHeader();
    if (array_key_exists('LRG_TEST_INDEX', $GLOBALS)) {
        $seam = $GLOBALS['LRG_TEST_INDEX'];
        if (is_array($seam) && isset($seam['kinds'])) { return (array) $seam['kinds']; }
    }
    return [
        'check_kinds' => (array) ($hdr['check_kinds'] ?? []),
        'census' => (array) ($hdr['kinds'] ?? []),
        'fn_census' => (array) ($hdr['fn_census'] ?? []),
        'subtypes' => (array) ($hdr['subtypes'] ?? []),
        'pnam_inverted' => (array) ($hdr['pnam_inverted'] ?? []),
        'stats' => (array) ($hdr['stats'] ?? []),
    ];
}

// ---------------------------------------------------------------- the test seam
/** Rows of the in-memory fixture. Accepts a plain list, or ['rows' => [...], 'layers' => [...]]. */
function lrgPromptTestRows(): array
{
    $seam = $GLOBALS['LRG_TEST_INDEX'] ?? [];
    if (!is_array($seam)) { return []; }
    $rows = array_key_exists('rows', $seam) ? (array) $seam['rows'] : (array_is_list($seam) ? $seam : []);
    $out = [];
    foreach ($rows as $r) {
        if (!is_array($r)) { continue; }
        if (!isset($r['norm']) && isset($r['txt'])) { $r['norm'] = lrgPromptNorm((string) $r['txt']); }
        $out[] = $r;
    }
    return $out;
}

function lrgPromptTestLayers(): array
{
    $seam = $GLOBALS['LRG_TEST_INDEX'] ?? [];
    return (is_array($seam) && isset($seam['layers'])) ? (array) $seam['layers'] : [];
}

// ---------------------------------------------------------------- lookup
/**
 * Per text: the best index record, or null ('unindexed'). Lookup order (design 4.2):
 *   1 layer fingerprint, subset-tolerant  2 exact norm  3 token pattern  4 none
 * $npcFacts may carry 'bamt' (GetBribeAmount) to disambiguate a <BribeCost> price, and 'quests' (the csv
 * of quest EditorIDs from the game) to prefer a record of a quest this NPC is actually in.
 * Returns a list in the SAME order as $texts.
 */
function lrgPromptLookup(array $texts, array $npcFacts = []): array
{
    $norms = [];
    foreach ($texts as $i => $t) { $norms[$i] = lrgPromptNorm((string) $t); }
    $rows = lrgPromptRowsFor(array_values(array_filter(array_unique($norms), 'strlen')));
    // [pt19h-grading / G12] the layer tier hears which quests the game says this NPC is in, as tier 2 always did
    $layer = lrgPromptLayerFor($norms, $npcFacts);
    $out = [];
    foreach ($texts as $i => $t) {
        $n = $norms[$i];
        $rec = null;
        if ($n !== '') {
            // 1 the layer wins: it disambiguates a norm that several topics share
            if ($layer !== null && isset($layer['by_norm'][$n])) {
                $rec = $layer['by_norm'][$n];
            }
            // 2 exact norm
            if ($rec === null && isset($rows[$n])) {
                $rec = lrgPromptBest($rows[$n], (string) $t, $npcFacts);
                // [pt19c build fixer] a pick among candidates that disagree on top-level, with nothing (her quest) to say which
                // is on screen, does not VOTE on the list's root / closed standing (lrgPromptLayerKind)
                $tops = array_unique(array_map(static fn($r) => (int) ($r['toplevel'] ?? 0), $rows[$n]));
                if (is_array($rec) && count($tops) > 1 && (string) ($rec['merge'] ?? '') !== 'topic') { $rec['standing'] = 'unsure'; }
            }
            // 3 token pattern
            if ($rec === null) { $rec = lrgPromptByPattern($n, (string) $t, $npcFacts); }
        }
        if (is_array($rec)) {
            $rec['cost_live'] = lrgPromptCost((string) $t);
            if ((int) ($rec['cost'] ?? 0) === -1 && $rec['cost_live'] > 0) { $rec['cost'] = $rec['cost_live']; }
            $rec['matched'] = $rec['matched'] ?? 'norm';
        }
        $out[$i] = is_array($rec) ? $rec : null;
    }
    return $out;
}

/**
 * root | closed | unknown, from the records a whole live list looked up to (design 4.2).
 * [pt19c build fixer] ONE LIST IS ONE LAYER, and only the lines whose standing is certain vote: a record lrgPromptLookup
 * marked standing=unsure (its prompt is a top-level topic AND a linked one somewhere in the load order, and nothing says
 * which is on screen) is skipped while any other line is known. Farengar's ROOT list read as a closed layer because
 * Andrealphus's "Do you need any help in the magical arts?" picked a linked row - and the closed-layer commit rule then
 * made his quest line "Where can I learn more about magic?" ask first.
 */
function lrgPromptLayerKind(array $records): string
{
    $top = $known = 0;
    $anySure = false;
    foreach ($records as $r) {
        if (is_array($r) && (string) ($r['standing'] ?? '') !== 'unsure') { $anySure = true; break; }
    }
    foreach ($records as $r) {
        if (!is_array($r)) { continue; }
        if ($anySure && (string) ($r['standing'] ?? '') === 'unsure') { continue; }
        $known++;
        if ((int) ($r['toplevel'] ?? 0) === 1) { $top++; }
    }
    if ($known === 0) { return 'unknown'; }
    if ($top === $known) { return 'root'; }
    if ($top === 0) { return 'closed'; }
    return $top >= max(1, (int) ceil($known * 0.7)) ? 'root' : 'closed';
}

/** norm => [records]. Serves the test seam without any DB access at all. */
function lrgPromptRowsFor(array $norms): array
{
    $out = [];
    if (!$norms) { return $out; }
    if (array_key_exists('LRG_TEST_INDEX', $GLOBALS)) {
        $want = array_fill_keys($norms, true);
        foreach (lrgPromptTestRows() as $r) {
            $n = (string) ($r['norm'] ?? '');
            if (isset($want[$n])) { $out[$n][] = $r; }
        }
        return $out;
    }
    $db = lrgDb();
    if (!$db) { return $out; }
    $in = [];
    foreach ($norms as $n) { $in[] = $db->escapeLiteral($n); }
    $rows = lrgPromptFetch('SELECT * FROM {s}.lrg_prompt WHERE norm IN (' . implode(',', $in) . ') LIMIT 400');
    foreach ((array) $rows as $r) {
        $r = lrgPromptDecode((array) $r);
        $out[(string) $r['norm']][] = $r;
    }
    return $out;
}

/** jsonb columns arrive as strings from Postgres. */
function lrgPromptDecode(array $r): array
{
    foreach (['flags', 'links', 'conds'] as $k) {
        if (isset($r[$k]) && is_string($r[$k])) { $r[$k] = json_decode($r[$k], true) ?: []; }
    }
    return $r;
}

/**
 * Several topics can carry the same player line (137 speech topics show more than one distinct line and the
 * reverse happens too). Prefer, in order: a quest the game says this NPC is in, a check record, a record with
 * a price that equals the live bamt, then the one that is not top-level (a closed layer is the narrower claim).
 */
function lrgPromptBest(array $recs, string $text, array $npcFacts): ?array
{
    if (!$recs) { return null; }
    if (count($recs) === 1) { return $recs[0]; }
    $quests = array_filter(array_map('strtolower', (array) ($npcFacts['quests'] ?? [])));
    $bamt = (int) ($npcFacts['bamt'] ?? 0);
    $live = lrgPromptCost($text);
    $best = null; $bestScore = -1000;
    foreach ($recs as $r) {
        $s = 0;
        if ($quests && in_array(strtolower((string) ($r['quest'] ?? '')), $quests, true)) { $s += 10; }
        if (($r['kind'] ?? '') !== '') { $s += 4; }
        if ($bamt > 0 && $live > 0 && $live === $bamt && (int) ($r['cost'] ?? 0) !== 0) { $s += 3; }
        if ((int) ($r['toplevel'] ?? 0) === 0) { $s += 1; }
        if ((int) ($r['journal'] ?? 0) === 1) { $s += 1; }
        if ($s > $bestScore) { $bestScore = $s; $best = $r; }
    }
    // [pt19c build fixer] the game says this NPC is in these quests: when some candidates are theirs, the line on her
    // list is one of THOSE rows, and the merge is scoped to their topics (+ every lethal row) - see lrgPromptEscalate
    $own = null;
    if ($quests) {
        foreach ($recs as $r) {
            if (in_array(strtolower((string) ($r['quest'] ?? '')), $quests, true)) { $own[(string) ($r['topic_key'] ?? '')] = 1; }
        }
    }
    if (is_array($best)) { $best = lrgPromptEscalate($best, $recs, $own); }
    return $best;
}

/**
 * FAIL-SAFE MERGE. 1,601 distinct prompt norms are carried by more than one topic in this load order, and
 * for 7 of them one candidate is LETHAL (a DGCrime arrest line) while another is not - "What's the problem"
 * and "You're making a mistake..." among them. Picking the wrong one there would be the only way an
 * advisory index could cause real harm: the glue would treat an arrest layer as ordinary business instead
 * of handing the menu back. So after the preference order has chosen a row, every RISK claim is raised to
 * the strictest value any candidate makes. The index may over-claim risk (that costs one confirmation too
 * many); it may never under-claim it.
 *
 * [pt19c build fixer - the reviewers' ruling on the merge (pt19c-E 5 @merge, pt19c-walkthrough 3, pt19c-F 9.2)]
 * SCOPED TO THE LINE'S OWN TOPIC whenever the caller can tell which topics the line on screen belongs to
 * ($ownTopics, topic_key => 1: the parent INFO's links of a known layer, or the topics of the quests the game says
 * this NPC is in). Borrowing from EVERY INFO of the load order that shares the prompt made plain quest lines ask
 * first (a goodbye lent by MQ201 to Aventus's "Are you all right?"), stopped continuations advancing and lent check
 * kinds - 115 lines of the questline harness. Within scope the merge is unchanged (the engine still picks the INFO
 * of the topic by its conditions), and every LETHAL row (crit 2) of the load order stays in it: the arrest guarantee
 * above is untouched. With no scope (null / empty: nothing tells which topic it is) the merge stays load-order-wide.
 * 'ambiguous' keeps counting every candidate.
 */
function lrgPromptEscalate(array $best, array $recs, ?array $ownTopics = null): array
{
    if (count($recs) < 2) { return $best; }
    $best['ambiguous'] = count($recs);
    if ($ownTopics) {
        $scoped = [];
        foreach ($recs as $r) {
            if ((int) ($r['crit'] ?? 0) >= 2 || isset($ownTopics[(string) ($r['topic_key'] ?? '')])) { $scoped[] = $r; }
        }
        // the chosen row must be one of them; otherwise the scope does not describe this line and the full merge stands
        if ($scoped && isset($ownTopics[(string) ($best['topic_key'] ?? '')])) { $recs = $scoped; $best['merge'] = 'topic'; }
    }
    $flags = (array) ($best['flags'] ?? []);
    foreach ($recs as $r) {
        $best['crit'] = max((int) ($best['crit'] ?? 0), (int) ($r['crit'] ?? 0));
        $best['scripted'] = max((int) ($best['scripted'] ?? 0), (int) ($r['scripted'] ?? 0));
        $best['cost'] = ((int) ($best['cost'] ?? 0) === -1 || (int) ($r['cost'] ?? 0) === -1)
            ? -1 : max((int) ($best['cost'] ?? 0), (int) ($r['cost'] ?? 0));
        if ((string) ($best['kind'] ?? '') === '' && (string) ($r['kind'] ?? '') !== '') { $best['kind'] = (string) $r['kind']; }
        if ((string) ($best['topic_kind'] ?? '') === '' && (string) ($r['topic_kind'] ?? '') !== '') { $best['topic_kind'] = (string) $r['topic_kind']; }
        if ((string) ($best['twat'] ?? '') === '' && (string) ($r['twat'] ?? '') !== '') { $best['twat'] = (string) $r['twat']; }
        foreach (['goodbye', 'walkaway', 'sayonce'] as $f) {
            // the coalesce has to sit INSIDE the cast: (int) binds tighter than ??, so the old spelling
            // evaluated (int)$missingKey first and emitted a PHP warning on a hot path for every candidate
            // row that did not carry all three flags. The merged value was right; the warning printed into
            // the same response body the D1 delivery route echoes the command line into.
            $flags[$f] = max((int) ($flags[$f] ?? 0), (int) (((array) ($r['flags'] ?? []))[$f] ?? 0));
        }
    }
    // 'compound' switches retry-suppression and scoff-first OFF, so the STRICTEST reading is the compound
    // one: never talk the player out of an attempt the engine might pass.
    foreach ($recs as $r) { $best['compound'] = max((int) ($best['compound'] ?? 0), (int) ($r['compound'] ?? 0)); }
    $best['flags'] = $flags;
    return $best;
}

/** Token prompts: the plugin says "How about <Alias=Hold>?", the menu says "How about Riften?". */
function lrgPromptByPattern(string $norm, string $text, array $npcFacts): ?array
{
    $pats = lrgPromptPatterns();
    // [pt19h-grading / G19, COVERAGE G19] EVERY row the line's pattern matches is a candidate, and the tier gets the same
    // preference and fail-safe merge as an exact norm (lrgPromptBest -> lrgPromptEscalate: her quest first, risk never
    // under-claimed) instead of the first pattern in the file. A second key is tried when the first finds nothing: the norm
    // with its tags KEPT - "Recognize this? (Show <Alias.PronounObj=Steward> <Alias=Evidence>)" is longer than the norm's
    // 40-character tag rule, so its pattern keeps "show * *", while the menu's "(Show him the amulet)" is short and dropped.
    $keys = [$norm];
    $kept = lrgPromptNormKeepTags($text);
    if ($kept !== '' && $kept !== $norm) { $keys[] = $kept; }
    foreach ($keys as $key) {
        $hits = [];
        foreach ($pats as $p) {
            $re = (string) ($p['re'] ?? '');
            if ($re !== '' && preg_match($re, $key)) { $hits[] = $p['row']; }
        }
        if (!$hits) { continue; }
        $r = count($hits) > 1 ? lrgPromptBest($hits, $text, $npcFacts) : $hits[0];
        if (!is_array($r)) { continue; }
        $r['matched'] = 'pattern';
        return $r;
    }
    return null;
}

/**
 * [pt19h-grading / G19] lrgPromptNorm WITHOUT its tag steps (2, 3): lower, <Token> -> <t>, digits -> #, the same characters.
 * Only ever a second key for the pattern tier - never the index key (the builder mirrors lrgPromptNorm, not this).
 */
function lrgPromptNormKeepTags(string $text): string
{
    if ($text === '') { return ''; }
    $s = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
    $s = (string) preg_replace('/<[^<>]{1,60}>/u', ' <t> ', $s);
    $s = (string) preg_replace('/\d[\d,\.]*/u', '#', $s);
    $s = (string) preg_replace('/[^a-z0-9#<>\' ]+/u', ' ', $s);
    return trim((string) preg_replace('/\s+/u', ' ', $s));
}

/** Every token pattern, compiled once. On the live server this is one query, cached per request. */
function lrgPromptPatterns(): array
{
    static $pats = null;
    static $sig = null;
    $rows = [];
    if (array_key_exists('LRG_TEST_INDEX', $GLOBALS)) {
        // [pt19h-grading] the test seam can swap its index between checks: the compiled set follows the seam's pattern rows
        foreach (lrgPromptTestRows() as $r) { if ((string) ($r['pattern'] ?? '') !== '') { $rows[] = $r; } }
        $now = md5(serialize(array_map(static fn($r) => [(string) $r['pattern'], (string) ($r['info_key'] ?? '')], $rows)));
        if ($pats !== null && $sig === $now) { return $pats; }
        $sig = $now;
    } elseif ($pats !== null) {
        return $pats;
    } else {
        $rows = array_map('lrgPromptDecode', lrgPromptFetch("SELECT * FROM {s}.lrg_prompt WHERE pattern <> '' LIMIT 4000"));
    }
    $pats = [];
    foreach ($rows as $r) {
        $pat = (string) ($r['pattern'] ?? '');
        if ($pat === '') { continue; }
        // [pt19h-grading / G19] a pattern with no literal word of its own ("*" - Hearthfire's "<Alias=Player>.", the Remote
        // Interactions calls) matches EVERY short line: 24 such rows made any unindexed line up to 40 characters "indexed" as
        // hearthfires.esm:003D89 and graded it plain (the Compelling Tribute accept and blackmail among them). Never a key.
        if (!preg_match('/[a-z0-9]{2,}/', str_replace('*', ' ', $pat))) { continue; }
        // '*' stands for one token's replacement: a short run of words, never the whole line
        // [pt19h-grading / G19] the builder spaced a possessive off its token ("<Alias=Steward>'s" -> "* 's"); the menu writes
        // "Captain Aldis's", so a literal part that opens with " '" may lose that space
        $parts = array_map(static fn($x) => strncmp($x, " '", 2) === 0 ? ' ?' . preg_quote(substr($x, 1), '/') : preg_quote($x, '/'),
            explode('*', $pat));
        $re = '/^' . implode('[a-z0-9#\' ]{0,40}', $parts) . '$/';
        $pats[] = ['re' => $re, 'row' => $r];
    }
    return $pats;
}

/**
 * Subset-tolerant layer match: the whole live list is compared against the known layers. A layer wins when at
 * least min(3, n) of its norms are present and no more than 40 % of the live norms are strangers.
 * Returns ['fingerprint','parent_info','kind','by_norm' => norm => record] or null.
 */
function lrgPromptLayerFor(array $norms, array $npcFacts = []): ?array
{
    $live = array_values(array_filter(array_unique(array_map('strval', $norms)), 'strlen'));
    if (count($live) < 2) { return null; }
    $parents = [];
    $cands = lrgPromptLayerCandidates($live, $parents);
    $bestFp = null; $bestHits = 0;
    foreach ($cands as $fp => $ln) {
        $hits = count(array_intersect($live, $ln));
        $need = min(3, max(2, (int) count($ln)));
        if ($hits >= $need && $hits > $bestHits) { $bestHits = $hits; $bestFp = $fp; }
    }
    if ($bestFp === null) { return null; }
    $ln = $cands[$bestFp];
    if ($bestHits < (int) ceil(count($live) * 0.6)) { return null; }
    // the layer's own records, keyed by norm: a norm that several topics share is now unambiguous
    $rows = lrgPromptRowsFor($ln);
    // [pt19c build fixer] the layer's PARENT INFO links the topics this list shows: a row of one of them is the line on
    // screen (chosen first), and the fail-safe merge is scoped to them (+ every lethal row). An unknown parent, or links
    // that name none of a norm's rows, leave that norm as before: the first row, merged load-order-wide.
    $parent = (string) ($parents[$bestFp] ?? '');
    $own = [];
    foreach (($parent !== '' ? lrgPromptLinksOf($parent) : []) as $tk) { $own[(string) $tk] = 1; }
    // [pt19h-grading / G12, COVERAGE G12] THE LAYER TIER NEVER GRADES A LINE WITH ANOTHER QUEST'S ROW WHEN THE GAME SAYS WHICH IS
    // HERS. A norm set can match another quest's layer ("I'll do it." / "Never mind, maybe later." is Lisbet's layer as well as
    // Delvin's), and then $recs[0] and a load-order-wide merge graded Delvin's accept with Lisbet's crit 1, Tullius's "Yes,
    // sir." with Ulfric's CW00B row and Frea's "Who are you?" with DB02's goodbye. The parent's links still choose first (the
    // pt19c ruling; one of her quests' rows among them before any other); when they name none of a norm's rows, a candidate of
    // a quest this NPC is in (q=, the game's own list) is chosen instead of $recs[0]. The merge is scoped to the narrowest set
    // that names the pick - the parent's links, else her quests' topics (+ every lethal row, lrgPromptEscalate). A pick
    // nothing confirms keeps the load-order-wide merge.
    $quests = array_filter(array_map(static fn($q) => strtolower((string) $q), (array) ($npcFacts['quests'] ?? [])), 'strlen');
    $byNorm = [];
    foreach ($ln as $n) {
        $recs = $rows[$n] ?? [];
        if ($recs) {
            $hers = [];
            if ($quests) {
                foreach ($recs as $cand) {
                    if (in_array(strtolower((string) ($cand['quest'] ?? '')), $quests, true)) { $hers[(string) ($cand['topic_key'] ?? '')] = 1; }
                }
            }
            $pick = $recs[0];
            $bestS = -1;
            foreach ($recs as $cand) {
                $ctk = (string) ($cand['topic_key'] ?? '');
                $s = (isset($own[$ctk]) ? 2 : 0) + (isset($hers[$ctk]) ? 1 : 0);
                if ($s > $bestS) { $bestS = $s; $pick = $cand; }
            }
            // the narrowest scope that names the pick: the parent's links (this very layer's topics), else her quests' topics
            $ptk = (string) ($pick['topic_key'] ?? '');
            $scope = isset($own[$ptk]) ? $own : (isset($hers[$ptk]) ? $hers : []);
            // THE FAIL-SAFE MERGE BELONGS HERE TOO. This is tier 1 and it WINS over tier 2, and every one
            // of the 7 norms in this load order that carry both a LETHAL (crit=2) candidate and a
            // non-lethal one sits inside a known closed layer - so taking $recs[0] raw meant the
            // highest-risk prompts were exactly the ones whose risk was thrown away. With crit flattened
            // to 0 the gate's LETHAL hand-back never fires, lrgDlgIsCommit() loses its crit>=1 hit and the
            // label is not '[leaving now ends this]': an arrest layer reads as ordinary business.
            $r = lrgPromptEscalate($pick, $recs, $scope ?: null);
            $r['matched'] = 'layer';
            $r['layer'] = $bestFp;
            $byNorm[$n] = $r;
        }
    }
    return ['fingerprint' => $bestFp, 'parent_info' => $parent, 'kind' => 'closed', 'by_norm' => $byNorm];
}

/** fingerprint => [norms]; $parents gets fingerprint => parent_info. One query per turn on the live server; the seam
 *  answers in memory. */
function lrgPromptLayerCandidates(array $live, ?array &$parents = null): array
{
    $out = [];
    $parents = [];
    if (array_key_exists('LRG_TEST_INDEX', $GLOBALS)) {
        foreach (lrgPromptTestLayers() as $l) {
            $fp = (string) ($l['fingerprint'] ?? md5(implode('|', (array) ($l['norms'] ?? []))));
            $out[$fp] = array_map('strval', (array) ($l['norms'] ?? []));
            $parents[$fp] = (string) ($l['parent_info'] ?? '');
        }
        return $out;
    }
    $db = lrgDb();
    if (!$db) { return $out; }
    $ors = [];
    foreach (array_slice($live, 0, 6) as $n) { $ors[] = 'norms ? ' . $db->escapeLiteral($n); }
    if (!$ors) { return $out; }
    $rows = lrgPromptFetch('SELECT fingerprint, parent_info, norms FROM {s}.lrg_prompt_layer WHERE '
        . implode(' OR ', $ors) . ' LIMIT 40');
    foreach ($rows as $r) {
        $ns = is_string($r['norms'] ?? null) ? (json_decode((string) $r['norms'], true) ?: []) : (array) ($r['norms'] ?? []);
        $out[(string) $r['fingerprint']] = array_map('strval', $ns);
        $parents[(string) $r['fingerprint']] = (string) ($r['parent_info'] ?? '');
    }
    return $out;
}

/** [pt19c build fixer] The topic keys one INFO links (its choices), from its own index row; [] when it has none. One
 *  primary-key read per layer on the live server, cached for the request; the seam answers in memory. */
function lrgPromptLinksOf(string $infoKey): array
{
    static $cache = [];
    if ($infoKey === '') { return []; }
    $seam = array_key_exists('LRG_TEST_INDEX', $GLOBALS);
    if (!$seam && array_key_exists($infoKey, $cache)) { return $cache[$infoKey]; }
    $links = null;
    if ($seam) {
        foreach (lrgPromptTestRows() as $r) {
            if ((string) ($r['info_key'] ?? '') === $infoKey) { $links = $r['links'] ?? []; break; }
        }
    } else {
        $db = lrgDb();
        if ($db) {
            $row = lrgPromptFetch('SELECT links FROM {s}.lrg_prompt WHERE info_key = ' . $db->escapeLiteral($infoKey) . ' LIMIT 1');
            $links = $row ? ($row[0]['links'] ?? []) : [];
        }
    }
    if (is_string($links)) { $links = json_decode($links, true) ?: []; }
    $out = array_values(array_filter(array_map('strval', (array) $links), 'strlen'));
    if (!$seam) { $cache[$infoKey] = $out; }
    return $out;
}

// ---------------------------------------------------------------- loading
/**
 * Schema for the index tables. Idempotent, like Phase 1's migrations.
 * [0.5.0] 007 only. It is self-sufficient (create schema, MOVE an existing public copy, then
 * CREATE TABLE IF NOT EXISTS the bodies schema-qualified), and running 006 first would recreate an
 * EMPTY public.lrg_prompt next to the real one - which is exactly the state the one-release fallback
 * of lrgPromptFetch() would then read from. 006 stays on disk as the record of what 007 moved.
 */
function lrgPromptEnsureSchema(): void
{
    $db = lrgDb();
    if (!$db) { return; }
    $file = LRG_DIR . '/migrations/007_lrg_prompt_schema.sql';
    // {s} is substituted here, never hard-coded in the .sql, so dialogue.index.schema and the migration
    // can never name two different schemas
    $sql = str_replace('{s}', lrgPromptSchema(), (string) @file_get_contents($file));
    foreach (lrgPromptSqlStatements($sql) as $stmt) {
        try { $db->execQuery($stmt); } catch (Throwable $e) { lrgLog('prompt index schema: ' . $e->getMessage()); }
    }
    unset($GLOBALS['LRG_PROMPT_SCHEMA_ACTIVE']);     // the tables are where they should be again
}

/**
 * Load data/prompt_index.ndjson into lrg_prompt / lrg_prompt_layer. Never called inside a request.
 * Returns ['rows' => n, 'layers' => n, 'error' => ''].
 */
function lrgPromptLoad(?string $file = null): array
{
    $file = $file ?: lrgPromptIndexFile();
    $res = ['rows' => 0, 'layers' => 0, 'error' => '', 'file' => $file];
    $db = lrgDb();
    if (!$db) { $res['error'] = 'no database'; return $res; }
    $f = @fopen($file, 'rb');
    if (!$f) { $res['error'] = 'cannot read ' . $file; return $res; }
    lrgPromptEnsureSchema();
    $hdr = json_decode((string) fgets($f, 1024 * 512), true) ?: [];
    if (($hdr['_'] ?? '') !== 'header') { fclose($f); $res['error'] = 'no header line'; return $res; }
    if ((int) ($hdr['v'] ?? 0) !== LRG_PROMPT_INDEX_VERSION) {
        fclose($f);
        $res['error'] = 'index version ' . (int) ($hdr['v'] ?? 0) . ', this server wants ' . LRG_PROMPT_INDEX_VERSION;
        return $res;
    }
    // the LOADER never falls back: it writes where the migration put the tables, and a deploy that
    // cannot reach that schema must FAIL LOUDLY rather than fill an orphan public copy or, worse,
    // report "37,561 rows loaded" into a schema that does not exist (which is exactly what a silent
    // execQuery() failure looked like before this check).
    $s = lrgPromptSchema();
    try {
        $probe = $db->fetchOne('SELECT to_regclass(' . $db->escapeLiteral($s . '.lrg_prompt') . ') AS t');
        $there = is_array($probe) && ($probe['t'] ?? null) !== null && (string) $probe['t'] !== '';
    } catch (Throwable $e) {
        $there = false;
    }
    if (!$there) {
        fclose($f);
        $res['error'] = 'the index tables are not in schema ' . $s . ' - run migration 007 first'
            . ' (php lib/lrg_prompt_index.php ensure)';
        return $res;
    }
    $db->execQuery('BEGIN');
    $db->execQuery('DELETE FROM ' . $s . '.lrg_prompt');
    $db->execQuery('DELETE FROM ' . $s . '.lrg_prompt_layer');
    $batch = [];
    $flush = static function (array &$batch) use ($db, $s) {
        if (!$batch) { return; }
        $db->execQuery('INSERT INTO ' . $s . '.lrg_prompt (norm, pattern, txt, topic_key, info_key, topic, quest, journal,'
            . ' toplevel, kind, variant, flags, scripted, compound, amulet, twat, crit, cost, links, resp, conds,'
            . ' subtype, plugin, shared, topic_kind, fail_brawl, fail_hard, unreachable) VALUES '
            . implode(',', $batch) . ' ON CONFLICT (info_key) DO NOTHING');
        $batch = [];
    };
    $q = static fn($v) => $db->escapeLiteral((string) $v);
    while (($line = fgets($f)) !== false) {
        $o = json_decode($line, true);
        if (!is_array($o)) { continue; }
        if (($o['_'] ?? '') === 'layer') {
            $db->execQuery('INSERT INTO ' . $s . '.lrg_prompt_layer (fingerprint, parent_info, kind, n, norms) VALUES ('
                . $q($o['fingerprint'] ?? '') . ',' . $q($o['parent_info'] ?? '') . ',' . $q($o['kind'] ?? 'closed') . ','
                . (int) ($o['n'] ?? 0) . ',' . $q(json_encode(array_values((array) ($o['norms'] ?? [])))) . ')'
                . ' ON CONFLICT (fingerprint) DO NOTHING');
            $res['layers']++;
            continue;
        }
        if (!isset($o['norm']) || !isset($o['info_key'])) { continue; }
        $batch[] = '(' . implode(',', [
            $q($o['norm']), $q($o['pattern'] ?? ''), $q(substr((string) ($o['txt'] ?? ''), 0, 160)),
            $q($o['topic_key'] ?? ''), $q($o['info_key']), $q($o['topic'] ?? ''), $q($o['quest'] ?? ''),
            (int) ($o['journal'] ?? 0), (int) ($o['toplevel'] ?? 0), $q($o['kind'] ?? ''), $q($o['variant'] ?? 'na'),
            $q(json_encode((array) ($o['flags'] ?? []))), (int) ($o['scripted'] ?? 0), (int) ($o['compound'] ?? 0),
            (int) ($o['amulet'] ?? 0), $q($o['twat'] ?? ''), (int) ($o['crit'] ?? 0), (int) ($o['cost'] ?? 0),
            $q(json_encode(array_values((array) ($o['links'] ?? [])))), $q(substr((string) ($o['resp'] ?? ''), 0, 220)),
            $q(json_encode((array) ($o['conds'] ?? []))), $q($o['subtype'] ?? ''), $q($o['plugin'] ?? ''),
            max(1, (int) ($o['shared'] ?? 1)), $q($o['topic_kind'] ?? ''), (int) ($o['fail_brawl'] ?? 0),
            (int) ($o['fail_hard'] ?? 0), (int) ($o['unreachable'] ?? 0),
        ]) . ')';
        $res['rows']++;
        if (count($batch) >= 200) { $flush($batch); }
    }
    $flush($batch);
    fclose($f);
    $db->execQuery('COMMIT');
    @mkdir(LRG_DIR . '/data', 0770, true);
    @file_put_contents(lrgPromptIndexMarker(), json_encode(['at' => lrgNow(), 'hash' => (string) ($hdr['hash'] ?? ''),
        'rows' => $res['rows'], 'layers' => $res['layers']]));
    lrgLog('prompt index loaded: ' . $res['rows'] . ' prompts, ' . $res['layers'] . ' layers into schema '
        . $s . ' (built ' . date('c', (int) ($hdr['built_at'] ?? 0)) . ', hash '
        . substr((string) ($hdr['hash'] ?? ''), 0, 8) . ')');
    $res['schema'] = $s;
    return $res;
}

/**
 * Has the load order changed since the index was built? Checked at most once a minute, on the FAST ev=facts
 * path only, and never inside an LLM request. It only ever LOGS and writes a stamp - the rebuild itself is
 * the owner's (deploy_server.ps1), because it needs python inside WSL.
 */
/**
 * [pt17 / orchestrator] The load-order hash, computed EXACTLY as tools/build_prompt_index.py computes it: Python
 * reads plugins.txt and modlist.txt in text mode, so "\r\n" and a lone "\r" become "\n" before the md5. The old
 * raw-bytes md5 here disagreed with the builder on every install whose modlist.txt is CRLF (this one), so the
 * index read as STALE on every facts line all evening (index e756e311, "live" 01414bfe) although it was current.
 */
function lrgPromptLoadOrderHash(string $plugins, string $modlist): string
{
    $n = static fn(string $s): string => str_replace(["\r\n", "\r"], "\n", $s);
    return md5($n($plugins) . "\n" . $n($modlist));
}

function lrgPromptMaybeStale(): ?string
{
    static $checked = 0;
    if (array_key_exists('LRG_TEST_INDEX', $GLOBALS)) { return null; }
    $now = lrgNow();
    $stampFile = LRG_DIR . '/data/.prompt_index_check';
    $last = (int) @file_get_contents($stampFile);
    if ($now - $last < 60) { return null; }
    if ($checked++ > 0) { return null; }
    @mkdir(LRG_DIR . '/data', 0770, true);
    @file_put_contents($stampFile, (string) $now);
    $cfg = (array) (lrgConfig()['mo2'] ?? []);
    $root = (string) ($cfg['root'] ?? '');
    $profile = (string) ($cfg['profile'] ?? 'Ultra');
    if ($root === '') { return null; }
    $p = $root . '/profiles/' . $profile . '/plugins.txt';
    $m = $root . '/profiles/' . $profile . '/modlist.txt';
    if (!is_file($p) || !is_file($m)) { return null; }
    $hash = lrgPromptLoadOrderHash((string) file_get_contents($p), (string) file_get_contents($m));
    $have = (string) (lrgPromptIndexHeader()['hash'] ?? '');
    if ($have !== '' && $hash !== $have) {
        $why = 'prompt index is STALE: the load order changed (index hash ' . substr($have, 0, 8)
            . ', live ' . substr($hash, 0, 8) . ') - run tools/build_prompt_index.py and lrg_prompt_index.php load';
        lrgLog($why);
        return $why;
    }
    return null;
}

/**
 * [0.4 integrator] CLI-only Postgres bootstrap. Inside a request CHIM's core has already built
 * $GLOBALS['db'] and this does nothing; run from the command line (the deploy's `load` step, or a
 * hand `status`) there is no $GLOBALS['db'] at all, so `load` reported "no database" and the index
 * stayed in the file - every lookup would then have read a 30 MB ndjson. Same tiny wrapper and same
 * default DSN as tools/test_prompt_index.php --db; LRG_PGDSN overrides it. It is never reached from
 * a web request, and it only ever returns a connection - the loader does its own BEGIN / COMMIT.
 */
function lrgPromptCliDb(): bool
{
    if (isset($GLOBALS['db'])) { return true; }
    if (!function_exists('pg_connect')) { return false; }
    $dsn = getenv('LRG_PGDSN') ?: 'host=localhost dbname=dwemer user=dwemer password=dwemer';
    $pg = @pg_connect($dsn);
    if (!$pg) { return false; }
    $GLOBALS['db'] = new class ($pg) {
        public function __construct(private $c) { }
        public function escapeLiteral($s) { return pg_escape_literal($this->c, (string) $s); }
        public function execQuery($q) { return @pg_query($this->c, (string) $q) !== false; }
        // [0.5.0] fetchOne / fetchAll THROW on a failed query, exactly as CHIM's own postgresql.class.php
        // does (:390). Swallowing the error here meant a CLI `status` against a server whose migration
        // had not run reported "rows=0" instead of falling back to public and saying so.
        public function fetchOne($q) { $r = @pg_query($this->c, (string) $q); if ($r === false) { throw new Exception('SQL: ' . pg_last_error($this->c)); } return pg_fetch_assoc($r) ?: []; }
        public function fetchAll($q) { $r = @pg_query($this->c, (string) $q); if ($r === false) { throw new Exception('SQL: ' . pg_last_error($this->c)); } return pg_fetch_all($r) ?: []; }
        public function upsertRowOnConflict($t, $d, $k) { return true; }
        public function insert($t, $d) { return true; }
    };
    return true;
}

// ---------------------------------------------------------------- CLI
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    $cmd = strtolower((string) ($argv[1] ?? 'status'));
    if (!lrgPromptCliDb()) { echo "note: no Postgres connection from the command line (LRG_PGDSN overrides the default DSN)\n"; }
    if ($cmd === 'ensure') {                          // [0.5.0] run migration 007 alone (the deploy does this)
        lrgPromptEnsureSchema();
        echo 'schema ensured: ' . lrgPromptSchema() . "\n";
        exit(0);
    }
    if ($cmd === 'load') {
        lrgPromptEnsureSchema();                      // 007 first: a fresh install has no lrg_index yet
        $r = lrgPromptLoad($argv[2] ?? null);
        echo $r['error'] !== '' ? ('FAILED: ' . $r['error'] . "\n")
            : sprintf("loaded %d prompts, %d layers from %s into schema %s\n", $r['rows'], $r['layers'], $r['file'],
                (string) ($r['schema'] ?? '?'));
        exit($r['error'] !== '' ? 1 : 0);
    }
    $s = lrgPromptIndexStatus();
    $h = lrgPromptIndexHeader();
    printf("prompt index v%d  source=%s  schema=%s  rows=%d  layers=%d  file_rows=%d  coverage_rows=%d  built=%s\n",
        $s['version'], $s['source'], (string) ($s['schema'] ?? '-'), $s['rows'], $s['layers'], $s['file_rows'],
        (int) ($s['coverage_rows'] ?? 0), $s['built_at'] ? date('c', $s['built_at']) : '-');
    printf("check kinds found in this load order: %s\n", implode(', ', $s['kinds']) ?: '(none)');
    if ($s['error'] !== '') { echo 'error: ' . $s['error'] . "\n"; }
    if ($h) {
        printf("stats: %s\n", json_encode($h['stats'] ?? []));
        printf("unreadable (engine-only): %d\n", count((array) ($h['unreadable'] ?? [])));
    }
    exit($s['ok'] ? 0 : 1);
}
