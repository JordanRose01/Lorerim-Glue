<?php
/**
 * LoreRim Glue - THE QUESTLINE COVERAGE HARNESS (menuless questing v1.0; research/pt19-menuless-v1-spec.md section 3,
 * Lane E). It walks the REAL prompt index for the main quest and the guild openings and asserts, for every advancing
 * player line of tools/fixtures/lrg_questline.json, WHICH path the glue takes and that every paraphrase resolves to
 * THAT line and no sibling. No game, no CHIM, no LLM, no database: the server's own functions run through the flow
 * harness seams (tools/flows/harness.php, adapter.php, dlg_adapter.php) exactly as tools/flows/run_flows.php runs them.
 *
 * Usage (inside WSL, from a STAGED copy of glue/ - never inside /var/www):
 *   php tools/test_questline.php                    every beat, the cross-cutting assertions, the single-entry sweep
 *   php tools/test_questline.php --quiet            failures and the table only
 *   php tools/test_questline.php --quest=MQ102      one quest's beats (the cross-cutting assertions still run)
 *   php tools/test_questline.php --beat=<id>        one beat (debugging)
 *   php tools/test_questline.php --table            print only the coverage table
 *   php tools/test_questline.php --stage=B          evaluate with checks.reward.enabled = true (the reward beats only)
 *   php tools/test_questline.php --words            each paraphrase as the model's FREE-TEXT item (intent mode): resolve /
 *                                                   ask / nothing per line - the LLM path's reach, not only its rails; and
 *                                                   every NEVER line the same way: it may ask, it must never be clicked
 *   php tools/test_questline.php --first-evening    section 3.7 only (the owner page's own sentences, on their NPCs; since
 *                                                   pt19h-harness also Lane F's extra-run F. beats and the walkthrough's W.
 *                                                   beats - fe=1 beats of the same fixture)
 *   php tools/test_questline.php --file=<ndjson>    another index (default: the deployed one)
 *   php tools/test_questline.php --fixture=<json>   another fixture, alone (default: tools/fixtures/lrg_questline.json AND its adversarial
 *                                                   companion tools/fixtures/lrg_questline_adversarial.json - research/pt19c-language.md's
 *                                                   attack-mode paraphrases and near-misses on the same beats, with its own known list;
 *                                                   its beats run after the main fixture's and show as "adv:" rows in the table)
 *   php tools/test_questline.php --no-companion     the main fixture only
 *   php tools/test_questline.php --allow-known      the fixture's `known` defects (another lane's, each with its owner) are
 *                                                   reported as KNOWN and do not fail the run - NOT the release gate
 *                                                   (--strict, the old spelling of the default, is still accepted)
 *   php tools/test_questline.php --explain          print how the gate grades every line of each beat's list
 *   php tools/test_questline.php --scoped-merge     MEASUREMENT ONLY (never the gate): cut each beat's seam so the fail-safe
 *                                                   merge (lrgPromptEscalate) sees, for each line on the list, only the rows
 *                                                   of that line's own topic and lethal rows - what the build does once the
 *                                                   merge is scoped to the topic (the beat's quests are not exempt)
 *   php tools/test_questline.php --remeasure=<out>  write a fixture proposal (spec 3.4 step 5): a failing paraphrase whose
 *                                                   measured path is `park` or `words` is re-classed with the matcher's
 *                                                   number; a paraphrase on a target the fail-safe merge over-grades, a click
 *                                                   on another line, a park no assent releases, a click where words were
 *                                                   due are listed for `known` and never moved; a click on the target under
 *                                                   another label (the spec table vs the build) is listed for a human
 *   php tools/test_questline.php --dump=<out>       the failing checks as JSON
 *   php tools/test_questline.php --no-restage       run the plugin in place even from a /mnt/<drive> copy (slow: see below)
 *   php tools/test_questline.php --trace            every step
 *   [pt19h-harness] THE EXTENDED COVERAGE (the rest of the game; tools/flows/questline_adapter.php):
 *   php tools/test_questline.php --extended         tools/fixtures/lrg_questline_extended.json - the coverage team's 2,005 beats
 *                                                   MERGED with the 279 Dark Brotherhood beats, the quarantine list apart:
 *                                                   every `never` / `not_target` row must click nothing on the fast path (a
 *                                                   click fails the run); every `never_red` row is reported with its gap id
 *                                                   (still red / green); every say line is reported by outcome - fast-path
 *                                                   click / her T-key / she asks / nothing / wrong line - with the percentages;
 *                                                   the quarantine is reported, never asserted. [--group=<g>] [--beat=<id>]
 *                                                   [--shard=k/N] [--ext-out=<json>: every line's outcome]
 *   [pt19h-harness r2] THE BASELINES (an earlier run, line by line - the usefulness review's P3):
 *   --words / --first-evening --words compare with tools/fixtures/lrg_questline_words_baseline.json, --extended with
 *                                                   tools/fixtures/lrg_questline_extended_baseline.json (both measured on the 06:00 code of
 *                                                   2026-09-25): every say line whose outcome got WORSE is listed (--extended: by group
 *                                                   and gap); a VERBATIM or FIRST-EVENING line getting worse FAILS the run - unless it now
 *                                                   does what its `via` says, a protected target now asks first (the safety rules allow
 *                                                   it), or the baseline's `accepted` names the change. --words-baseline=<file> /
 *                                                   --ext-baseline=<file> (an --ext-out works) name another; --no-baseline skips it;
 *                                                   --words-out=<file> (merges into an existing words baseline) / --ext-baseline-out=<file>
 *                                                   write this run's outcomes as a baseline, with --baseline-label="<which code>"
 *   php tools/test_questline.php --build-extended=<dir>   (re)write that fixture from the two coverage files in <dir>
 *                                                   (lrg_questline_extended.json, brotherhood.verified.json) and, when it sits
 *                                                   beside them, research/pt19h-measure.json (the per-gap regression rows); no
 *                                                   index needed: the Brotherhood's text templates stay templates. WSL cannot
 *                                                   read the research folder - copy the files to a temp dir first
 * Exit 0 = every beat has its path, every paraphrase resolves, no `words` line clicks anything, every cross-cutting
 * assertion holds (spec 3.1, 3.4 step 6: exit 1 on ANY failure); a failure the fixture lists under `known` (a defect with its
 * owning lane) fails the run with its owner named, and only --allow-known downgrades it to a KNOWN line. Budget: <= 60 s over
 * the real index on WSL (spec 2.2 Lane E) - about 5 s.
 *
 * How one beat runs (spec 3.4): the layer is built ONLY from the index - a closed layer from its layer line
 * (parent_info -> norms, one visible line per linked topic), a single from the parent row's resolved links, a root list
 * from named top-level topics - never from hand-listed siblings. FAST PATH: fxDlgSay (the utterance), fxAdvance(5) (the
 * list arrives AFTER the sentence: utter.at < session.at, as in game), lrg_topics want=1 -> the D1 echo. LLM PATH: the
 * list is on screen, fxDlgSay, the target's T-key forced -> the gate; a PARK is then released by his assent (rotated per
 * paraphrase over the owner's own shapes - "yes", "okay", "yes, I'm sure", "uh yeah sure", "I swear" on an oath - and carried
 * alternately by the model's key and by a words-only reply, where the gate appends the parked pick itself: model F27). A paraphrase
 * RESOLVES when the fast path or the LLM path yields its `via` on the TARGET; a command on any other line, `words` where
 * via != words, or ANY command on the target where via == words is a FAIL - and so is a pick that carries an engine-check
 * kind its own index row does not (the fail-safe merge lends one). Scene beats (sj=1, derived from the owning quest's
 * journal rows in the index, never by hand - capability map U9) run twice: clicks_ok 0 -> nothing is clicked and the
 * read-only clause is in <business>; clicks_ok 1 -> their stage-B class. At clicks_ok 0 the stage rail is computed HERE (model
 * R8 from the entry's grading) and its ONE sentence is pinned: it names exactly the keys that pass, or - where none does -
 * is the U7 sentence (never the false "ask me something simple first"). A linked topic with several prompts shows the one
 * the fixture names (layer.variants, with the plugin evidence), and the built list must account for every line of the
 * layer line. The fail-safe merge's over-grading (a target or sibling graded above its own row) is printed; the fixture
 * asserts the spec path there and lists each moved line as a known @merge defect, and a failure on such a beat prints what
 * the line does with the merge scoped (--scoped-merge measures the whole run that way).
 */

error_reporting(E_ALL);
ini_set('display_errors', 'stderr');
ini_set('memory_limit', '1536M');

require __DIR__ . '/flows/harness.php';
require __DIR__ . '/flows/adapter.php';
require __DIR__ . '/flows/dlg_adapter.php';
require __DIR__ . '/flows/questline_adapter.php';   // [pt19h-harness] --extended / --build-extended

$T0 = microtime(true);
$A = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $a, $m)) { $A[$m[1]] = $m[2] ?? true; }
}
$QUIET = !empty($A['quiet']) || !empty($A['table']);
// spec 3.1: exit 0 = every beat has its path and every paraphrase resolves - so a `known` defect fails by default (the release
// gate, 2.4); --allow-known is the lenient report for a lane whose own work is elsewhere (--strict: the old name of the default)
$STRICT = empty($A['allow-known']);
$GLOBALS['EXPLAIN'] = !empty($A['explain']);
$DUMP = is_string($A['dump'] ?? null) ? (string) $A['dump'] : '';
$REMEASURE = is_string($A['remeasure'] ?? null) ? (string) $A['remeasure'] : '';
$TABLE_ONLY = !empty($A['table']);
$STAGE_B = strtoupper((string) ($A['stage'] ?? 'A')) === 'B';
$WORDS = !empty($A['words']);
$GLOBALS['QL_SCOPED'] = !empty($A['scoped-merge']);
$FE_ONLY = !empty($A['first-evening']);
$ONLY_QUEST = is_string($A['quest'] ?? null) ? (string) $A['quest'] : '';
$ONLY_BEAT = is_string($A['beat'] ?? null) ? (string) $A['beat'] : '';
Fx::$trace = !empty($A['trace']);
$INDEX_FILE = is_string($A['file'] ?? null) ? (string) $A['file'] : '/var/www/html/HerikaServer/ext/lorerim_glue/data/prompt_index.ndjson';
$EXTENDED = !empty($A['extended']);   // [pt19h-harness]
$FIXTURE = is_string($A['fixture'] ?? null) ? (string) $A['fixture']
    : (__DIR__ . '/fixtures/' . ($EXTENDED || isset($A['build-extended']) ? 'lrg_questline_extended.json' : 'lrg_questline.json'));
// [pt19h-harness r2] the baselines (a line whose outcome got WORSE than on the code the baseline was measured on is listed, by gap and
// group; a verbatim or first-evening line getting worse FAILS): --words / --first-evening --words read tools/fixtures/lrg_questline_words_baseline.json,
// --extended reads tools/fixtures/lrg_questline_extended_baseline.json (both measured on the 06:00 code of 2026-09-25) unless --fixture= names
// another fixture; --words-baseline= / --ext-baseline= name another (an --ext-out file works too); --no-baseline skips it. --words-out= /
// --ext-baseline-out= write this run's outcomes as a baseline (--baseline-label= says which code it was measured on)
$NO_BASELINE = !empty($A['no-baseline']);
$WORDS_BASELINE = $NO_BASELINE ? '' : (is_string($A['words-baseline'] ?? null) ? (string) $A['words-baseline']
    : (isset($A['fixture']) ? '' : __DIR__ . '/fixtures/lrg_questline_words_baseline.json'));
$EXT_BASELINE = $NO_BASELINE ? '' : (is_string($A['ext-baseline'] ?? null) ? (string) $A['ext-baseline']
    : (isset($A['fixture']) ? '' : __DIR__ . '/fixtures/lrg_questline_extended_baseline.json'));
$WORDS_OUT = is_string($A['words-out'] ?? null) ? (string) $A['words-out'] : '';
$BASELINE_LABEL = is_string($A['baseline-label'] ?? null) ? (string) $A['baseline-label'] : 'this tree';
if (isset($A['build-extended'])) {
    // [pt19h-harness] the extended fixture from the coverage team's two files (no plugin, no index: its text templates stay templates)
    exit(qlxBuild(is_string($A['build-extended']) ? (string) $A['build-extended'] : '', $FIXTURE));
}

// never against a live server tree: the run writes <plugin>/data markers and the plugin's log (run_flows.php's rule)
$pluginDirEarly = (string) realpath(__DIR__ . '/../server/lorerim_glue');
if ($pluginDirEarly === '' || str_starts_with($pluginDirEarly, '/var/www')) {
    fwrite(STDERR, "test_questline.php must run from a staged copy of glue/ (plugin dir: '" . $pluginDirEarly . "'), never inside HerikaServer.\n");
    exit(3);
}

// ================================================================== output + bookkeeping
final class Ql
{
    public static int $ok = 0;
    public static int $fail = 0;
    public static array $fails = [];        // failure lines, repeated in the summary
    public static array $notes = [];
    public static array $table = [];        // one row per beat
    public static array $perQuest = [];     // quest => [beats, ok, total, path]
    public static array $twin = [];         // [label, will, emitted]
    public static array $words = [];        // --words rows
    public static int $n = 0;               // world counter (sid / cid uniqueness)
    public static array $known = [];        // fixture "known": key => [owner, why]
    public static array $knownSeen = [];
    public static array $knownLines = [];
    public static int $knownHit = 0;
    public static array $dump = [];         // --dump: the failing checks, for re-measuring the fixture
    public static array $overclaim = [];    // targets the fail-safe merge grades above their own index row
    public static array $overBeat = [];     // beat id => true: its target is one of them (--remeasure never re-classes there)
    public static array $measured = [];     // failing paraphrases with their measured path (--remeasure)
    public static array $liveTextIgnored = []; // [pt19h-harness r2] beat id => a live_text on a target row with no token (not shown)
}
function qlOut(string $s): void { global $TABLE_ONLY; if (!$TABLE_ONLY) { echo $s . "\n"; } }
function qlChk(string $name, bool $good, string $detail = '', string $key = ''): bool
{
    global $QUIET, $STRICT;
    $known = $key !== '' ? (Ql::$known[$key] ?? null) : null;
    if ($known !== null) { Ql::$knownSeen[$key] = 1; }
    if ($good) {
        Ql::$ok++;
        if (!$QUIET) { qlOut("  [ok] $name"); }
        if ($known !== null) { qlNote("known entry \"$key\" passes now - remove it from the fixture's known list"); }
        return true;
    }
    if ($known !== null && !$STRICT) {
        // --allow-known: a defect the owning lane has to fix, reported but not counted (the default run - the release gate - fails it)
        Ql::$knownHit++;
        $line = "  [KNOWN " . (string) ($known['owner'] ?? '?') . "] $name" . ($detail !== '' ? '  [' . fx_short($detail, 300) . ']' : '')
            . ' -- ' . fx_short((string) ($known['why'] ?? ''), 300);
        Ql::$knownLines[] = $line;
        qlOut($line);
        return false;
    }
    Ql::$fail++;
    $line = "  [FAIL" . ($known !== null ? ' - KNOWN, owner ' . (string) ($known['owner'] ?? '?') : '') . "] $name" . ($detail !== '' ? '  [' . fx_short($detail, 400) . ']' : '')
        . ($known !== null ? ' -- ' . fx_short((string) ($known['why'] ?? ''), 300) : '');
    Ql::$fails[] = $line;
    Ql::$dump[] = ['check' => $name, 'key' => $key, 'detail' => $detail];
    qlOut($line);
    return false;
}
function qlNote(string $s): void { Ql::$notes[] = $s; }

// ================================================================== the log, read incrementally (the gate's mode lives there)
final class QlLog
{
    private static int $at = 0;
    public static function mark(): void { clearstatcache(); self::$at = (int) @filesize(fxLogFile()); }
    public static function since(): string
    {
        clearstatcache();
        $size = (int) @filesize(fxLogFile());
        if ($size <= self::$at) { return ''; }
        $h = @fopen(fxLogFile(), 'rb');
        if (!$h) { return ''; }
        fseek($h, self::$at);
        $s = (string) stream_get_contents($h);
        fclose($h);
        self::$at = $size;
        return $s;
    }
}

// ================================================================== the index (read once; per-beat seams are cut from it)
final class QlIndex
{
    public array $hdr = [];
    public array $rows = [];       // id => row
    public array $byInfo = [];     // info_key => [ids]
    public array $byNorm = [];     // norm => [ids]
    public array $byTopic = [];    // topic => [ids]
    public array $byTk = [];       // topic_key => [ids]
    public array $byQuest = [];    // quest => [ids]
    public array $layers = [];     // [layer line]
    public array $lByNorm = [];    // norm => [layer idx]
    public array $lByParent = [];  // parent_info => [layer idx]

    public function __construct(string $file)
    {
        $h = @fopen($file, 'rb');
        if (!$h) { throw new RuntimeException("cannot read the index $file"); }
        $this->hdr = json_decode((string) fgets($h, 1024 * 1024), true) ?: [];
        $id = 0;
        while (($l = fgets($h)) !== false) {
            if (strncmp($l, '{"_": "layer"', 13) === 0) {
                $L = json_decode($l, true);
                if (!is_array($L)) { continue; }
                $i = count($this->layers);
                $this->layers[] = $L;
                foreach ((array) $L['norms'] as $n) { $this->lByNorm[(string) $n][] = $i; }
                $this->lByParent[(string) $L['parent_info']][] = $i;
                continue;
            }
            $r = json_decode($l, true);
            if (!is_array($r) || !isset($r['norm'])) { continue; }
            unset($r['conds']);
            $this->rows[$id] = $r;
            $this->byInfo[(string) $r['info_key']][] = $id;
            $this->byNorm[(string) $r['norm']][] = $id;
            $this->byTopic[(string) $r['topic']][] = $id;
            $this->byTk[(string) $r['topic_key']][] = $id;
            $this->byQuest[strtolower((string) $r['quest'])][] = $id;
            $id++;
        }
        fclose($h);
    }
    public function row(?string $info): ?array { $ids = $this->byInfo[(string) $info] ?? []; return $ids ? $this->rows[$ids[0]] : null; }
    public function rowsOfTopic(string $topic): array { return array_map(fn($i) => $this->rows[$i], $this->byTopic[$topic] ?? []); }
    /** The layer line of a parent INFO that carries this norm (layer lines are de-duplicated by norm set: any one of them). */
    public function layerOf(string $parent, string $norm = ''): ?array
    {
        foreach ($this->lByParent[$parent] ?? [] as $li) {
            $L = $this->layers[$li];
            if ($norm === '' || in_array($norm, (array) $L['norms'], true)) { return $L; }
        }
        return null;
    }
    public function layersWithNorm(string $norm): array { return array_map(fn($i) => $this->layers[$i], $this->lByNorm[$norm] ?? []); }
    public function hasJournal(string $quest): bool
    {
        foreach ($this->byQuest[strtolower($quest)] ?? [] as $i) { if ((int) $this->rows[$i]['journal'] === 1) { return true; } }
        return false;
    }
}

// ================================================================== bootstrap (spec 3.2)
/*
 * A staged copy on a Windows mount (/mnt/c, 9p) spends ~95 % of this run appending the plugin's own log line by line
 * (measured: 58 s there, 2.5 s from a native copy). So, unless --no-restage, the plugin tree is copied once to the
 * native temp dir and loaded from there - the same files, byte for byte; only its log and data markers land in /tmp.
 */
$restaged = '';
if (empty($A['no-restage']) && preg_match('~^/mnt/[a-z]/~', $pluginDirEarly)) {
    $dst = rtrim(sys_get_temp_dir(), '/') . '/lrg_questline_' . substr(md5($pluginDirEarly), 0, 10);
    $cp = static function (string $from, string $to) use (&$cp): void {
        @mkdir($to, 0770, true);
        // a mirror: a file gone from the staged tree (a config override, a deleted lib) is gone here too
        foreach (scandir($to) ?: [] as $f) {
            if ($f !== '.' && $f !== '..' && is_file("$to/$f") && !file_exists("$from/$f")) { @unlink("$to/$f"); }
        }
        foreach (scandir($from) ?: [] as $f) {
            if ($f === '.' || $f === '..') { continue; }
            if (is_dir("$from/$f")) { $cp("$from/$f", "$to/$f"); continue; }
            // size AND the source's own mtime (stamped on the copy): the Windows and WSL clocks differ, so "newer than" is no test
            if (!is_file("$to/$f") || filesize("$to/$f") !== filesize("$from/$f") || filemtime("$to/$f") !== filemtime("$from/$f")) {
                @copy("$from/$f", "$to/$f");
                @touch("$to/$f", (int) filemtime("$from/$f"));
            }
        }
    };
    $cp($pluginDirEarly, "$dst/server/lorerim_glue");
    @mkdir("$dst/log", 0770, true);
    @unlink("$dst/log/lorerim_glue.log");
    $restaged = "$dst/server/lorerim_glue";
}
if ($restaged !== '') {
    // fxLoadPlugin(), from the native copy
    Fx::$pluginDir = $restaged;
    require_once Fx::$pluginDir . '/lib/lrg_actions.php';
    @mkdir(LRG_DIR . '/data', 0770, true);
    @touch(LRG_DIR . '/data/.schema_v' . LRG_SCHEMA_VERSION);
} else {
    fxLoadPlugin();
}
fx_install_error_handler();
fxDlgLoad();
$fx = json_decode((string) @file_get_contents($FIXTURE), true);
if (!is_array($fx) || ($fx['_'] ?? '') !== 'lrg_questline') { fwrite(STDERR, "no fixture at $FIXTURE\n"); exit(1); }
/** A fixture's known entries, each shared reason ("@merge": {owner, why} under known_reasons) resolved - one cause, one text. */
function qlKnownOf(array $fx, string $file): array
{
    $known = array_filter((array) ($fx['known'] ?? []), static fn($k) => $k !== '_', ARRAY_FILTER_USE_KEY);
    foreach ($known as $k => $v) {
        $ref = is_string($v) ? $v : (string) ($v['why'] ?? '');
        if (str_starts_with($ref, '@')) {
            $why = (array) (((array) ($fx['known_reasons'] ?? []))[substr($ref, 1)] ?? []);
            if (!$why) { fwrite(STDERR, "known entry \"$k\" of $file names the reason $ref, which known_reasons does not define\n"); exit(1); }
            $known[$k] = array_replace($why, is_array($v) ? array_diff_key($v, ['why' => 1]) : [], ['code' => substr($ref, 1)]);
        }
    }
    return $known;
}
Ql::$known = qlKnownOf($fx, $FIXTURE);
// [pt19c fixer] THE ADVERSARIAL COMPANION rides the regular run: its near-misses (questions, negations, bargains, idioms, STT
// noise) are the false clicks the release gate must see - a line of it that clicks fails the run like any other
$COMPANION = __DIR__ . '/fixtures/lrg_questline_adversarial.json';
$fxAdv = null;
if (!isset($A['fixture']) && empty($A['no-companion']) && $REMEASURE === '' && !$EXTENDED && is_file($COMPANION)) {
    $fxAdv = json_decode((string) file_get_contents($COMPANION), true);
    if (!is_array($fxAdv) || ($fxAdv['_'] ?? '') !== 'lrg_questline') { fwrite(STDERR, "the companion fixture $COMPANION does not parse\n"); exit(1); }
    if ((string) ($fxAdv['index_hash'] ?? '') !== (string) ($fx['index_hash'] ?? '')) {
        fwrite(STDERR, "the companion fixture was measured on another index (" . (string) ($fxAdv['index_hash'] ?? '?') . ")\n"); exit(1);
    }
    foreach (qlKnownOf($fxAdv, $COMPANION) as $k => $v) {
        if (isset(Ql::$known[$k])) { fwrite(STDERR, "known entry \"$k\" is in both fixtures - one line, one fixture\n"); exit(1); }
        Ql::$known[$k] = $v;
    }
}
if (!is_file($INDEX_FILE)) { fwrite(STDERR, "no index at $INDEX_FILE (copy /var/www/html/HerikaServer/ext/lorerim_glue/data next to the staged copy, or pass --file=)\n"); exit(1); }
$t1 = microtime(true);
$IX = new QlIndex($INDEX_FILE);
qlOut(sprintf('LoreRim Glue questline harness - plugin %s; index %s: hash %s, %d rows, %d layer lines (read in %.1fs); fixture v%d, %d beats%s',
    defined('LRG_VERSION') ? LRG_VERSION : '?', $INDEX_FILE, (string) ($IX->hdr['hash'] ?? '?'), count($IX->rows), count($IX->layers),
    microtime(true) - $t1, (int) ($fx['v'] ?? 0), count((array) $fx['beats']), $STAGE_B ? ' - STAGE B' : ''));
if ($restaged !== '') { qlOut("plugin loaded from a native copy of the staged tree: $restaged (--no-restage runs it in place)"); }

// ================================================================== one world
const QL_NOW = 1790300000;

/** A fresh Phase 2 world: empty DB, the clock fixed, the beat's index slice in the seam, the install's clicks_ok. */
function qlWorld(array $seam, int $clicks, array $cfg = []): void
{
    global $STAGE_B;
    fxReset();
    $GLOBALS['LRG_TEST_NOW'] = QL_NOW + (++Ql::$n) * 1000;
    $GLOBALS['PLAYER_NAME'] = 'Testplayer';
    fxDlgReset($seam['rows'], $seam['layers']);
    $GLOBALS['LRG_DLG_STATE'] = [];
    unset($GLOBALS['LRG_DLG_TEST_CFG'], $GLOBALS['LRG_FAC_QE_SENT']);
    if ($STAGE_B) { $cfg['checks.reward.enabled'] = true; }
    if ($cfg) { $GLOBALS['LRG_DLG_TEST_CFG'] = $cfg; }
    lrgDlgPut('*install*', ['clicks_ok' => $clicks]);
}

/** The beat's snapshot + facts line (the game sends both before any list). */
function qlFacts(array $B): void
{
    $f = (array) ($B['facts'] ?? []);
    $snapOver = ['fac' => (string) ($f['fac'] ?? 'CrimeFactionWhiterun'), 'class' => '', 'pgold' => (string) ($f['pg'] ?? 300),
        'pspeech' => (string) ($f['sp'] ?? 30), 'gold' => (string) ($f['gold'] ?? 120),
        'scene' => ($B['_sc'] || !empty($B['amb']) || (string) ($f['sq'] ?? '') !== '') ? '1' : '0'];
    fxSendSnapshot((string) $B['npc'], fxDlgSnap($snapOver));
    $kv = ['q' => (string) ($f['q'] ?? $B['quest'] ?? '')];
    $sq = (string) ($f['sq'] ?? ($B['scene'] ?? ''));
    if ($sq !== '') { $kv['sq'] = $sq; $kv['sqj'] = (string) ($f['sqj'] ?? ($B['_sc'] ? 1 : 0)); }
    foreach (['qst', 'guard', 'bounty', 'cf', 'mq101', 'mq101c', 'mqq', 'qal', 'qgiver', 'dlg', 'cal'] as $k) {
        if (array_key_exists($k, $f)) { $kv[$k] = (string) $f[$k]; }
    }
    fxDlgEvent((string) $B['npc'], 'facts', $kv);
}

/** lrg_topics for the beat's layer. */
function qlTopics(array $B, array $L, int $want, string $cid, array $over = []): array
{
    $f = (array) ($B['facts'] ?? []);
    $kv = array_replace(['sid' => 'ql' . Ql::$n . 'x' . mt_rand(100, 999), 'gen' => 1, 'layer' => $L['kind'] === 'root' ? 0 : 1,
        'origin' => (string) ($B['origin'] ?? 'glue'), 'want' => $want, 'ask' => '', 'cid' => $cid,
        'scene' => ($B['_sc'] || !empty($B['amb'])) ? 1 : 0, 'sj' => $B['_sc'] ? 1 : 0, 'drv' => 1,
        'pg' => (int) ($f['pg'] ?? 300), 'sp' => (int) ($f['sp'] ?? 30), 'bamt' => (int) ($f['bamt'] ?? 0),
        'q' => (string) ($f['q'] ?? $B['quest'] ?? ''), 'crit' => (int) ($f['crit'] ?? 0)], $over);
    $ent = [];
    foreach ($L['entries'] as $i => $e) { $ent[] = fxDlgEntry($i, (string) $e['text'], ['i' => 200 + $i]); }
    return fxDlgTopics((string) $B['npc'], $kv, $ent);
}

/** Every SelectTopic command of a set of wire lines, parsed. */
function qlCmds(array $lines): array
{
    $out = [];
    foreach ($lines as $l) {
        $l = (string) $l;
        if (strpos($l, 'ExtCmdLRG_SelectTopic@') === false) { continue; }
        foreach (preg_split('/\r?\n/', trim($l)) as $one) {
            if (strpos($one, 'ExtCmdLRG_SelectTopic@') === false) { continue; }
            $out[] = fxParamKv($one);
        }
    }
    return $out;
}

/** The gate's mode for the last emit of this npc, from the log text. */
function qlModeFrom(string $log, string $do, int $pos): string
{
    if (preg_match_all('/dlg emit npc=.*? do=(\w+) mode=(\S+) pos=(-?\d+)/', $log, $m, PREG_SET_ORDER)) {
        for ($i = count($m) - 1; $i >= 0; $i--) {
            if ($m[$i][1] === $do && (int) $m[$i][3] === $pos) { return $m[$i][2]; }
        }
    }
    return '?';
}

/** One outcome: ['cmd' => pick|show|leave|none|..., 'pos', 'mode', 'adv', 'kind', 'cost', 'svc', 'line'] */
function qlOutcome(array $cmds, string $log): array
{
    foreach ($cmds as $c) {
        $do = (string) ($c['do'] ?? '');
        if (in_array($do, ['pick', 'show', 'leave'], true)) {
            $pos = (int) ($c['pos'] ?? -1);
            return ['cmd' => $do, 'pos' => $pos, 'mode' => qlModeFrom($log, $do, $pos), 'adv' => isset($c['adv']) ? (int) $c['adv'] : -1,
                'kind' => (string) ($c['kind'] ?? ''), 'cost' => (int) ($c['cost'] ?? 0), 'svc' => (string) ($c['svc'] ?? ''),
                // the price-list slot path decided it (the emit then reads explicit on a commit slot: S4.3 c)
                'slotpath' => str_contains($log, 'named exactly (longest wins)')];
        }
    }
    return ['cmd' => 'none', 'pos' => -1, 'mode' => '', 'adv' => -1, 'kind' => '', 'cost' => 0, 'svc' => '', 'slotpath' => false];
}

/** The class a pick on the target reads as. */
function qlClassOf(array $o, array $te): string
{
    if ($o['cmd'] !== 'pick') { return $o['cmd']; }
    return implode('/', qlLabels($o, $te));
}

/**
 * Every label one pick on the target carries: auto (adv=), explicit (his own plain sentence, incl. an enlistment's exact
 * join line - S4.3 path (b)), single (S4.5), check (the emitted kind is an engine check), pay (a price), slot (the price-list
 * slot path), pick (always). A `via` matches when it is among them; `pick` only when no two-step label is (explicit,
 * single, auto): a plain line picked by intent, kind or the model's key.
 */
function qlLabels(array $o, array $te): array
{
    $l = [];
    if ($o['adv'] >= 0) { $l[] = 'auto'; }
    if (in_array($o['mode'], ['explicit', 'faction'], true)) { $l[] = 'explicit'; }
    if ($o['mode'] === 'single') { $l[] = 'single'; }
    if (in_array((string) $o['kind'], ['persuade', 'bribe', 'intimidate'], true) || in_array((string) ($te['kind'] ?? ''), ['persuade', 'bribe', 'intimidate'], true)) { $l[] = 'check'; }
    if ((int) $o['cost'] > 0) { $l[] = 'pay'; }
    if ($o['mode'] === 'slot' || !empty($o['slotpath']) || ($o['svc'] !== '' && $o['mode'] !== 'kind')) { $l[] = 'slot'; }
    $l[] = 'pick';
    return $l;
}

function qlMatches(string $want, string $got): bool
{
    $l = explode('/', $got);
    if ($want === 'pick') { return in_array('pick', $l, true) && !array_intersect(['explicit', 'single', 'auto'], $l); }
    return in_array($want, $l, true);
}

/**
 * A pick on the target that carries an engine-check kind (persuade / bribe / intimidate) its own index row does not carry - neither
 * the row's kind nor its tag: the kind was borrowed from another INFO with the same prompt (the fail-safe merge). On the wire that
 * is a false grading - the >= 4-word check rail and the check's verdict then run on a line that is no check. '' = honest.
 */
function qlKindLie(array $o, array $row): string
{
    $k = (string) ($o['kind'] ?? '');
    if ($o['cmd'] !== 'pick' || !in_array($k, ['persuade', 'bribe', 'intimidate'], true)) { return ''; }
    if ((string) ($row['kind'] ?? '') !== '' || lrgDlgKindFromTag((string) ($row['txt'] ?? '')) !== '') { return ''; }
    return 'the pick carries kind=' . $k . ', which its own index row does not (borrowed from another INFO with the same prompt)';
}

/** Model 2.3 R8, computed HERE from the live entry's grading (never by asking the rail itself): a line the stage rail lets
 *  through at clicks_ok 0 - indexed, class plain/back, unscripted, no price, no kind, no goodbye. */
function qlR8Passes(array $e): bool
{
    return (int) ($e['indexed'] ?? 0) === 1 && in_array((string) ($e['class'] ?? ''), ['plain', 'back'], true)
        && (int) ($e['scripted'] ?? 0) === 0 && (int) ($e['cost'] ?? 0) === 0 && (string) ($e['kind'] ?? '') === ''
        && (int) ($e['goodbye'] ?? 0) === 0;
}

/**
 * The ONE stage-rail sentence this turn must carry at clicks_ok 0 (model R8 + capability map U7, never false): no rail line when
 * every offered key passes; when none passes, the U7 sentence and never "ask me something simple first" (it could not come true);
 * otherwise "Until he has asked me something simple I can only pick T<a>, T<b>" naming EXACTLY the passing keys.
 * Returns [good, detail, passing keys].
 */
function qlRailSentence(array $turn, string $vol): array
{
    $ok = [];
    $blocked = 0;
    $keys = (array) (($turn['offer'] ?? [])['keys'] ?? []);
    foreach (array_values((array) ($turn['entries'] ?? [])) as $i => $e) {
        if (!isset($keys['T' . ($i + 1)])) { continue; }
        if (qlR8Passes((array) $e)) { $ok[] = 'T' . ($i + 1); } else { $blocked++; }
    }
    $u7 = str_contains($vol, 'I cannot pick any of these for you yet - choose this one yourself, this once');
    $ask = str_contains($vol, 'ask me something simple first');
    $only = preg_match('/Until he has asked me something simple I can only pick ([T0-9, ]+);/', $vol, $m) ? trim($m[1]) : '';
    if ($blocked === 0) {
        return [!$u7 && !$ask, 'every offered key passes the rail: no rail sentence' . ($u7 || $ask ? ' - but one was said' : ''), $ok];
    }
    if (!$ok) {
        return [$u7 && !$ask, 'no offered key passes the rail: the U7 sentence ' . ($u7 ? 'present' : 'ABSENT')
            . ($ask ? ', and the FALSE "ask me something simple first" is said' : ''), $ok];
    }
    return [$only === implode(', ', $ok) && $ask && !$u7, 'keys that pass: ' . implode(', ', $ok) . '; the sentence names '
        . ($only !== '' ? $only : 'NONE') . ($u7 ? ', and the U7 sentence is said too' : ''), $ok];
}

/** The assent that answers her naming question for the $si-th paraphrase (the owner's own shapes, language brief 1.1 / 6.6),
 *  and whether the model re-emits the key (true) or answers in words only (false: the gate appends the parked pick, F27). */
function qlAssent(array $B, int $si): array
{
    $set = (array) ($B['assents'] ?? (preg_match('/^CW01[AB]\.oath/', (string) $B['id'])
        ? ['yes', 'I swear', "yes, I'm sure", 'uh yeah sure'] : ['yes', 'okay', "yes, I'm sure", 'uh yeah sure']));
    return [(string) $set[$si % count($set)], $si % 2 === 0];
}

// ================================================================== building a beat's layer from the index (spec 3.3)
/** The text the game would show for a row: live text for the target, tokens replaced by what the engine prints. */
function qlShown(array $row, array $B, bool $isTarget): string
{
    $live = (array) ($B['live'] ?? []);
    // [pt19h-harness r2] a beat's live_text is the target's text only where the target row carries a token (<Alias...>, <Global...>,
    // <BribeCost>) the engine fills - or where it IS the row's text. Anywhere else it is a copy slip (six Companions radiant declines
    // carried the ACCEPT line's live text), and the row's own text is shown - so the harness never builds a list the game never shows
    if ($isTarget && isset($B['live_text'])) {
        $rt = (string) ($row['txt'] ?? '');
        if (strpos($rt, '<') !== false || lrgPromptNorm((string) $B['live_text']) === lrgPromptNorm($rt)) { return (string) $B['live_text']; }
        if (!isset(Ql::$liveTextIgnored[(string) ($B['id'] ?? '')])) { Ql::$liveTextIgnored[(string) ($B['id'] ?? '')] = (string) $B['live_text']; }
    }
    if (isset($live[(string) $row['norm']])) { return (string) $live[(string) $row['norm']]; }
    $t = (string) $row['txt'];
    if (strpos($t, '<') === false) { return $t; }
    $bamt = (int) (($B['facts'] ?? [])['bamt'] ?? 100);
    $t = (string) preg_replace('/<BribeCost>/i', (string) $bamt, $t);
    // a price global prints a number: Xtended Stay's RoomCost<N>Days grows with N (25 a day), any other price reads 25
    $t = (string) preg_replace_callback('/<Global=[A-Za-z0-9_]*?(\d+)Days>/i', static fn($m) => (string) (25 * (int) $m[1]), $t);
    $t = (string) preg_replace('/<Global=[A-Za-z0-9_]*(Cost|Price|Fare|Gold)[A-Za-z0-9_]*>/i', '25', $t);
    $t = (string) preg_replace('/<Global=[^>]*>/i', '3', $t);
    if (!empty($GLOBALS['QL_ALIAS_NEUTRAL'])) {
        // [pt19h-harness r2] --extended: an alias the fixture gives no live text for prints a NAME the harness cannot know (the item,
        // the place, the person). It is rendered neutrally - a pronoun alias as a neutral pronoun, a name alias as nothing - so no
        // invented word ("Hadvar") is matched against; the beat is flagged `alias` and kept out of the tallies (qlxOneBeat)
        $t = (string) preg_replace("/<Alias(\.ShortName|\.Cap)?=[^>]*>'s\b/i", '', $t);
        $t = (string) preg_replace('/<Alias\.PronounObj=[^>]*>/i', 'them', $t);
        $t = (string) preg_replace('/<Alias\.PronounSubj=[^>]*>/i', 'they', $t);
        $t = (string) preg_replace('/<Alias\.PronounPos[A-Za-z]*=[^>]*>/i', 'their', $t);
        $t = (string) preg_replace('/<Alias\.Pronoun(Ref|Int)[A-Za-z]*=[^>]*>/i', 'themselves', $t);
        $t = (string) preg_replace('/<[^>]*>/', '', $t);
        $t = (string) preg_replace(['/\(\s+/', '/\s+\)/', '/\s+([.,!?;:])/', '/\s{2,}/', '/\(\s*\)/'], ['(', ')', '$1', ' ', ''], $t);
        return trim($t);
    }
    return (string) preg_replace('/<[^>]*>/', 'Hadvar', $t);
}

/**
 * The beat's layer: ['kind', 'entries' => [['text','norm','row','target']], 'ti' => target index, 'err' => why it cannot be
 * built]. closed: the layer line of parent_info (engine order = the parent row's link order when the parent is a row; one
 * visible line per linked topic). single: the parent row's links resolved -> exactly one visible line, or (a prompt-less
 * parent the index has no row for) the target alone, and then NO layer line of the index may carry the target's norm.
 * root: named topics that are real top-level rows (or DialogueGeneric service rows).
 */
function qlLayer(array $B, QlIndex $IX): array
{
    $tg = (array) ($B['target'] ?? []);
    $trow = isset($tg['info_key']) ? $IX->row((string) $tg['info_key']) : null;
    if ($trow === null && isset($tg['topic'])) { $trow = $IX->rowsOfTopic((string) $tg['topic'])[0] ?? null; }
    $out = ['kind' => (string) (($B['layer'] ?? [])['kind'] ?? ''), 'entries' => [], 'ti' => -1, 'err' => '', 'trow' => $trow];
    if ($trow === null) { $out['err'] = 'the target row ' . json_encode($tg) . ' is not in this index'; return $out; }
    if (isset($tg['topic']) && (string) $trow['topic'] !== (string) $tg['topic']) {
        $out['err'] = 'the target info ' . $tg['info_key'] . ' is on topic ' . $trow['topic'] . ', not ' . $tg['topic']; return $out;
    }
    $tnorm = (string) $trow['norm'];
    $lay = (array) ($B['layer'] ?? []);
    $push = function (array $row, bool $isT) use (&$out, $B) {
        $out['entries'][] = ['text' => qlShown($row, $B, $isT), 'norm' => (string) $row['norm'], 'row' => $row, 'target' => $isT];
        if ($isT) { $out['ti'] = count($out['entries']) - 1; }
    };
    // the parent's linked topics -> one visible row each (the target's own row on the target's topic). A linked topic whose INFOs
    // carry DIFFERENT prompts shows ONE of them (its conditions decide): the fixture names it (layer.variants: {topic_key:
    // info_key}); left unnamed, the builder refuses rather than guess a menu the engine may never show (spec 3.3)
    $variants = (array) ($lay['variants'] ?? []);
    $out['collapsed'] = [];
    $fromLinks = function (array $prow) use ($IX, $trow, $push, &$out, $B, $variants): int {
        $n = 0;
        foreach ((array) $prow['links'] as $tk) {
            $cand = array_map(fn($i) => $IX->rows[$i], $IX->byTk[(string) $tk] ?? []);
            $cand = array_values(array_filter($cand, static fn($r) => (string) $r['norm'] !== ''
                && empty(($r['flags'] ?? [])['placeholder'])));
            if (!$cand) { continue; }
            $pick = null;
            foreach ($cand as $r) { if ((string) $r['topic_key'] === (string) $trow['topic_key'] && (string) $r['norm'] === (string) $trow['norm']) { $pick = $r; break; } }
            $row = $pick;
            if ($row === null && (string) ($variants[(string) $tk] ?? '') === '-') {
                // the fixture says (with its evidence in the beat's note) that this topic's conditions hide every INFO at this beat
                foreach ($cand as $r) { $out['collapsed'][(string) $r['norm']] = (string) $tk . ' (hidden)'; }
                continue;
            }
            if ($row === null && isset($variants[(string) $tk])) {
                foreach ($cand as $r) { if ((string) $r['info_key'] === (string) $variants[(string) $tk]) { $row = $r; } }
                if ($row === null) { $out['err'] = 'layer.variants names ' . $variants[(string) $tk] . ', which is no INFO of the linked topic ' . $tk; return $n; }
            }
            $norms = array_values(array_unique(array_map(static fn($r) => (string) $r['norm'], $cand)));
            if ($row === null && count($norms) > 1) {
                $out['err'] = 'the linked topic ' . $tk . ' shows one of ' . count($norms) . ' prompts (' . implode(' | ', array_map(
                    static fn($r) => $r['info_key'] . ' "' . substr((string) $r['txt'], 0, 40) . '"', $cand)) . '): name the one on screen in layer.variants';
                return $n;
            }
            $row = $row ?? $cand[0];
            foreach ($norms as $nn) { if ($nn !== (string) $row['norm']) { $out['collapsed'][$nn] = (string) $tk; } }
            // two linked topics with the SAME prompt (a success / failure pair split over two topics, Innocence Lost's
            // DB01_Persuade_Success / _Fail) are exclusive by their conditions: the menu shows the line once
            $dup = false;
            foreach ($out['entries'] as $di => $de) { if ((string) $de['row']['txt'] === (string) $row['txt']) { $dup = $di; break; } }
            if ($dup !== false) {
                if ($pick !== null) {
                    $out['entries'][$dup] = ['text' => qlShown($row, $B, true), 'norm' => (string) $row['norm'], 'row' => $row, 'target' => true];
                    $out['ti'] = (int) $dup;
                }
                continue;
            }
            $push($row, $pick !== null);
            $n++;
        }
        return $n;
    };
    if ($out['kind'] === 'closed') {
        $parent = (string) ($lay['parent_info'] ?? '');
        $L = $IX->layerOf($parent, $tnorm);
        $prow = $IX->row($parent);
        if ($L === null && $prow !== null && $prow['links']) {
            // layer lines are de-duplicated by their norm set: a parent ROW whose links resolve to exactly the norms of a
            // layer line some other parent carries is that layer (the index's own sibling set, not a hand list)
            $set = [];
            foreach ((array) $prow['links'] as $tk) {
                foreach ($IX->byTk[(string) $tk] ?? [] as $i) { if ((string) $IX->rows[$i]['norm'] !== '') { $set[(string) $IX->rows[$i]['norm']] = 1; } }
            }
            $set = array_keys($set);
            sort($set);
            foreach ($IX->layersWithNorm($tnorm) as $cand) {
                $ns = array_map('strval', (array) $cand['norms']);
                sort($ns);
                if ($ns === $set) { $L = $cand; break; }
            }
        }
        if ($L === null) { $out['err'] = "no live layer line for parent $parent carrying \"$tnorm\""; return $out; }
        if ($prow !== null && $prow['links']) {
            $fromLinks($prow);
            if ($out['err'] !== '') { return $out; }
            $got = array_values(array_unique(array_column($out['entries'], 'norm')));
            if (array_diff($got, (array) $L['norms'])) {
                $out['err'] = 'the parent row\'s links resolve to lines the layer line does not carry: ' . implode(' | ', array_diff($got, (array) $L['norms']));
                return $out;
            }
            // ...and the other way round: every line the layer line carries is on the built list, or is the other prompt of a
            // linked topic already shown (a variant the engine's conditions hide) - never silently dropped
            $lost = array_values(array_diff(array_map('strval', (array) $L['norms']), $got, array_keys($out['collapsed'])));
            if ($lost) {
                $out['err'] = 'the layer line carries ' . count($lost) . ' line(s) the built list lacks: ' . implode(' | ', $lost);
                return $out;
            }
        } else {
            // a prompt-less parent (an NPC line): the layer line's norms, one visible line per topic
            $usedTk = [];
            foreach ((array) $L['norms'] as $n) {
                $cand = array_map(fn($i) => $IX->rows[$i], $IX->byNorm[(string) $n] ?? []);
                if (!$cand) { continue; }
                $isT = (string) $n === $tnorm;
                $r = $isT ? $trow : $cand[0];
                if ($isT) {
                    if (isset($usedTk[(string) $trow['topic_key']])) { $out['err'] = 'the target shares its topic with a sibling'; }
                } elseif (isset($usedTk[(string) $r['topic_key']]) || (string) $r['topic_key'] === (string) $trow['topic_key']) {
                    continue;
                }
                $usedTk[(string) $r['topic_key']] = 1;
                $push($r, $isT);
            }
        }
        if (!empty($lay['entries']) && (int) $lay['entries'] !== count($out['entries'])) {
            $out['err'] = 'the fixture says ' . (int) $lay['entries'] . ' visible lines, the index gives ' . count($out['entries']);
        }
    } elseif ($out['kind'] === 'single') {
        $parent = (string) ($lay['parent_info'] ?? '-');
        $prow = $parent !== '-' ? $IX->row($parent) : null;
        if ($prow !== null) {
            $fromLinks($prow);
            if ($out['err'] !== '') { return $out; }
            if (count($out['entries']) !== 1) {
                $out['err'] = "parent $parent's links resolve to " . count($out['entries']) . ' visible lines, not one: '
                    . implode(' | ', array_column($out['entries'], 'norm'));
            }
        } else {
            if ($parent !== '-') { $out['err'] = "the single's parent $parent is not a row of this index (use '-' for a prompt-less parent)"; return $out; }
            $foreign = $IX->layersWithNorm($tnorm);
            if ($foreign) { $out['err'] = 'a prompt-less single, but ' . count($foreign) . ' layer line(s) carry "' . $tnorm . '" (first: ' . $foreign[0]['parent_info'] . ')'; }
            $push($trow, true);
        }
    } elseif ($out['kind'] === 'root') {
        foreach ((array) ($lay['topics'] ?? []) as $tp) {
            $spec = is_array($tp) ? $tp : ['topic' => (string) $tp];
            $isT = (string) $spec['topic'] === (string) $trow['topic'];
            $rows = $IX->rowsOfTopic((string) $spec['topic']);
            $rows = array_values(array_filter($rows, static fn($r) => (string) $r['norm'] !== ''
                && ((int) $r['toplevel'] === 1 || (string) $r['quest'] === 'DialogueGeneric')));
            if (!$rows) { $out['err'] = 'root topic ' . $spec['topic'] . ' has no top-level row in this index'; return $out; }
            $r = $rows[0];
            if ($isT) { $r = $trow; }
            elseif (isset($spec['info_key'])) { $r = $IX->row((string) $spec['info_key']) ?? $r; }
            $out['entries'][] = ['text' => $isT ? qlShown($r, $B, true) : (isset($spec['live_text']) ? (string) $spec['live_text'] : qlShown($r, $B, false)),
                'norm' => (string) $r['norm'], 'row' => $r, 'target' => $isT];
            if ($isT) { $out['ti'] = count($out['entries']) - 1; }
        }
        if ($out['ti'] < 0) { $out['err'] = 'the root list does not carry the target topic ' . $trow['topic']; }
    } else {
        $out['err'] = 'layer kind must be closed | single | root';
    }
    if ($out['err'] === '' && $out['ti'] < 0) { $out['err'] = 'the target "' . $tnorm . '" is not on the layer built from the index'; }
    return $out;
}

/** The per-beat index slice: every row sharing a norm with the layer (all quests: the fail-safe merge sees them all), the
 *  beat's quests' rows (clause 5 and the journal-scene test read them), and every layer line carrying one of the norms. */
function qlSeam(array $B, array $L, QlIndex $IX, ?bool $scoped = null): array
{
    $scoped = $scoped ?? !empty($GLOBALS['QL_SCOPED']);
    $quests = array_filter(array_merge([(string) ($B['quest'] ?? ''), (string) ($B['scene'] ?? '')],
        array_map('trim', explode(',', (string) (($B['facts'] ?? [])['q'] ?? '')))));
    // --scoped-merge (measurement only): what lrgPromptEscalate sees once it is scoped to the TOPIC (pt19c-E 5.1, fixer round 2):
    // a row carrying one of the list's prompts stays only when it is in that entry's own topic, or lethal (crit 2, load-order-wide).
    // The beat's quests are NOT exempt any more: MQ106KynegroveEntryA1 (0CA633, scripted + goodbye) is MQ106's but another topic,
    // and a quest-wide scope hid that borrowing (reviewer round 2). Rows carrying none of the list's prompts are never read by the
    // merge for this list and stay (clause 5 and the journal-scene test read the beat's quests).
    $own = [];
    foreach ($L['entries'] as $e) {
        if (isset($e['row']['topic_key'])) { $own[(string) $e['norm']][(string) $e['row']['topic_key']] = 1; }
    }
    $keep = static function (array $r) use ($scoped, $own): bool {
        if (!$scoped || !isset($own[(string) $r['norm']])) { return true; }
        return (int) $r['crit'] === 2 || isset($own[(string) $r['norm']][(string) $r['topic_key']]);
    };
    $ids = [];
    foreach ($L['entries'] as $e) {
        foreach ($IX->byNorm[(string) $e['norm']] ?? [] as $i) { if ($keep($IX->rows[$i])) { $ids[$i] = 1; } }
    }
    foreach (array_unique($quests) as $q) { foreach ($IX->byQuest[strtolower($q)] ?? [] as $i) { if ($keep($IX->rows[$i])) { $ids[$i] = 1; } } }
    foreach ((array) ($B['seam_topics'] ?? []) as $tp) { foreach ($IX->byTopic[(string) $tp] ?? [] as $i) { if ($keep($IX->rows[$i])) { $ids[$i] = 1; } } }
    $rows = [];
    foreach (array_keys($ids) as $i) { $rows[] = $IX->rows[$i]; }
    $lset = [];
    foreach ($L['entries'] as $e) { foreach ($IX->lByNorm[(string) $e['norm']] ?? [] as $li) { $lset[$li] = 1; } }
    $layers = [];
    foreach (array_keys($lset) as $li) { $layers[] = $IX->layers[$li]; }
    return ['rows' => $rows, 'layers' => $layers];
}

// ================================================================== the two paths
/** FAST PATH for one utterance in the CURRENT world: say, the list arrives 5 s later with want=1. */
function qlFast(array $B, array $L, ?string $text, array $topicOver = []): array
{
    $npc = (string) $B['npc'];
    QlLog::mark();
    $cid = 'qlf' . Ql::$n . mt_rand(1000, 9999);
    if ($text !== null) {
        $q = fxDlgSay($npc, null, $text);
        $cid = (string) (($q['turn'] ?? [])['cid'] ?? $cid) ?: $cid;
    }
    fxAdvance(5);
    fxDlgClearQueue();
    $r = qlTopics($B, $L, 1, $cid, $topicOver);
    $log = QlLog::since();
    $o = qlOutcome(array_merge(qlCmds([$r['echo']]), qlCmds(array_map(static fn($q) => (string) ($q['action'] ?? ''), $r['queue']))), $log);
    $o['log'] = $log;
    return $o;
}

/** LLM PATH in the CURRENT world: the list is on screen, he says it, the model forces the target's key. */
function qlLlm(array $B, array $L, string $text, string $label, ?string $message = null): array
{
    $npc = (string) $B['npc'];
    $te = $L['entries'][$L['ti']];
    QlLog::mark();
    $q = fxDlgSay($npc, null, $text);
    $turn = (array) ($q['turn'] ?? []);
    $key = '';
    foreach ((array) (($turn['offer'] ?? [])['keys'] ?? []) as $k => $v) {
        if ((int) $v['pos'] === (int) $L['ti'] && (string) $v['norm'] === lrgPromptNorm((string) $te['text'])) { $key = (string) $k; break; }
    }
    if ($key === '') {
        foreach ((array) (($turn['offer'] ?? [])['keys'] ?? []) as $k => $v) { if ((int) $v['pos'] === (int) $L['ti']) { $key = (string) $k; break; } }
    }
    $offered = $key !== '';
    if ($key === '') { $key = 'T' . ($L['ti'] + 1); }
    $msg = $message ?? ('Do you mean it - "' . lrgDlgShownText((string) $te['text']) . '"?');
    $will = $turn ? lrgDlgWillEmit($turn, $key) : false;
    $out = fxDlgLlm($npc, $key, $msg);
    $log = QlLog::since();
    $cmds = qlCmds($out);
    $o = qlOutcome($cmds, $log);
    $emitted = false;
    foreach ($cmds as $c) {
        if (($c['do'] ?? '') === 'pick' && !isset($c['adv'])) { $emitted = true; }
        if (($c['do'] ?? '') === 'leave' && (int) ($c['pos'] ?? -1) >= 0) { $emitted = true; }
    }
    Ql::$twin[] = [$label, $will, $emitted];
    $o['key'] = $key;
    $o['offered'] = $offered;
    $o['volatile'] = (string) $q['volatile'];
    $o['turn'] = $turn;
    $o['log'] = $log;
    $o['parked'] = (string) ((fxDlgStateOf($npc)['parked'] ?? [])['norm'] ?? '');
    return $o;
}

/** The LLM path when the model answers in WORDS ONLY (no SelectTopic item): a parked line his assent released goes out
 *  appended by the gate itself (model F27, mode bare-yes). Table 2.6: that appended pick is WillEmit FALSE - it is not the
 *  model's emission, her line plays - so the twin records it as not emitted. */
function qlLlmNothing(array $B, string $text, string $label, string $message): array
{
    $npc = (string) $B['npc'];
    QlLog::mark();
    $q = fxDlgSay($npc, null, $text);
    $turn = (array) ($q['turn'] ?? []);
    $will = $turn ? lrgDlgWillEmit($turn, '') : false;
    $out = fxDlgLlmNothing($npc, $message);
    $log = QlLog::since();
    $o = qlOutcome(qlCmds($out), $log);
    Ql::$twin[] = [$label . ' (words-only reply)', $will, false];
    $o['log'] = $log;
    $o['turn'] = $turn;
    $o['volatile'] = (string) $q['volatile'];
    $o['parked'] = (string) ((fxDlgStateOf($npc)['parked'] ?? [])['norm'] ?? '');
    return $o;
}

/** A fresh world with the beat's list on screen (the LLM path's starting point). */
function qlOpenWorld(array $B, array $L, array $seam, int $clicks): void
{
    qlWorld($seam, $clicks, (array) ($B['cfg'] ?? []));
    qlFacts($B);
    if (!empty($B['warm_root'])) {
        // 5.3 step 2: her root was read by an E-press and CLOSED - the model's keys come from that cache, no list is on screen
        qlTopics($B, $L, 0, 'qlo' . Ql::$n, ['origin' => 'engine', 'layer' => 0, 'sid' => 'warm' . Ql::$n]);
        fxDlgEvent((string) $B['npc'], 'closed', ['sid' => 'warm' . Ql::$n, 'why' => 'goodbye', 'pending' => 0, 'layer' => 0]);
        fxAdvance(40);
        return;
    }
    qlTopics($B, $L, 0, 'qlo' . Ql::$n);
    fxAdvance(2);
}

// ================================================================== one paraphrase, one beat
/**
 * Resolve one `say` at one clicks_ok value. Returns ['got' => class, 'ok' => bool, 'detail'].
 * $want: the via expected (pick explicit park single auto check pay slot words none).
 */
function qlResolve(array $B, array $L, array $seam, array $s, int $clicks, int $si = 0): array
{
    $r = qlResolve0($B, $L, $seam, $s, $clicks, $si);
    // a pick on the target must not carry a check kind its own row does not (FE.irileth.gate, MQ102.irileth.news: the gate
    // guard's PERSUADE lent by the fail-safe merge) - the path may be right and the wire still false
    if ($r['ok'] && !empty($r['o']) && ($lie = qlKindLie($r['o'], $L['entries'][$L['ti']]['row'])) !== '') {
        return ['got' => $r['got'] . '/kind-lie', 'ok' => false, 'detail' => $r['detail'] . ' - but ' . $lie];
    }
    return $r;
}

function qlResolve0(array $B, array $L, array $seam, array $s, int $clicks, int $si): array
{
    $te = $L['entries'][$L['ti']];
    $want = (string) $s['via'];
    $text = (string) $s['t'];
    $label = $B['id'] . ' "' . $text . '" @' . $clicks;
    // --- the fast path, in a fresh world (each paraphrase is judged on its own; the S4.3 guard across sentences is qlGuards')
    qlWorld($seam, $clicks, (array) ($B['cfg'] ?? []));
    qlFacts($B);
    if (!empty($B['warm_root'])) {
        // 5.3 step 1: an E-press read her root list and closed it; step 2's sentence opens it pre-LLM from that cache (marker=root)
        qlTopics($B, $L, 0, 'qlw' . Ql::$n, ['origin' => 'engine', 'layer' => 0, 'sid' => 'warm' . Ql::$n]);
        fxDlgEvent((string) $B['npc'], 'closed', ['sid' => 'warm' . Ql::$n, 'why' => 'goodbye', 'pending' => 0, 'layer' => 0]);
        fxAdvance(40);
    }
    fxAdvance(3);
    $f = qlFast($B, $L, $text === '' ? null : $text);
    if (!empty($B['warm_root']) && $f['cmd'] === 'pick' && !str_contains($f['log'], 'open marker=root ')) {
        // a fast pick with no list on screen can only come through the pre-LLM open of her cached root (S2.1 clause 3)
        return ['got' => 'no-open', 'ok' => false, 'detail' => 'a fast pick without the pre-LLM open of her cached root (marker=root): ' . qlWhy($f['log'])];
    }
    $fc = $f['cmd'] === 'none' ? 'none' : ($f['pos'] === $L['ti'] ? qlClassOf($f, $te['row']) : 'WRONG pos=' . $f['pos'] . ' ' . $f['cmd']);
    Fx::trace("fast '$text' -> " . $f['cmd'] . ' pos=' . $f['pos'] . ' mode=' . $f['mode'] . ' => ' . $fc);
    if ($f['cmd'] === 'show' && $want === 'show') { return ['got' => 'show', 'ok' => true, 'detail' => 'fast show']; }
    if (str_starts_with($fc, 'auto') && $want === 'words' && qlIsAuto($B)) {
        // an effect-free continuation: his words did not release it, the 2.5 s breath (counted from the END of her line)
        // advances it anyway - "nothing" on an auto beat means no words-release (research/pt19c-language.md 4 legend)
        return ['got' => 'words', 'ok' => true, 'detail' => 'his words released nothing; the breath advances it (adv=' . $f['adv'] . ')'];
    }
    if ($f['cmd'] !== 'none') {
        if ($want === 'words') { return ['got' => $fc, 'ok' => false, 'detail' => 'fast path: a command where only words were due (mode ' . $f['mode'] . ')']; }
        if ($want === 'auto' && isset($s['adv']) && (int) $s['adv'] !== (int) $f['adv']) {
            // the grace itself is part of the contract (S4.6: 2.5 s, the owner page says so)
            return ['got' => $fc, 'ok' => false, 'detail' => 'adv=' . $f['adv'] . ', the fixture says adv=' . (int) $s['adv']];
        }
        return ['got' => $fc, 'ok' => qlMatches($want, $fc), 'o' => $f, 'detail' => 'fast ' . $f['cmd'] . ' pos=' . $f['pos'] . ' mode=' . $f['mode'] . ($f['adv'] >= 0 ? ' adv=' . $f['adv'] : '')];
    }
    if ($want === 'auto') { return ['got' => 'none', 'ok' => false, 'detail' => 'no auto-advance on the fast path: ' . qlWhy($f['log'])]; }
    if ($text === '') { return ['got' => 'none', 'ok' => $want === 'none' || $want === 'words', 'detail' => 'no words, nothing']; }
    // --- the LLM path (fresh world, the list on screen)
    qlOpenWorld($B, $L, $seam, $clicks);
    $g = qlLlm($B, $L, $text, $label);
    $gc = $g['cmd'] === 'none' ? 'none' : ($g['pos'] === $L['ti'] ? qlClassOf($g, $te['row']) : 'WRONG pos=' . $g['pos'] . ' ' . $g['cmd']);
    Fx::trace("llm '$text' key=" . $g['key'] . ' -> ' . $g['cmd'] . ' pos=' . $g['pos'] . ' mode=' . $g['mode'] . ' parked=' . $g['parked'] . ' => ' . $gc);
    if ($g['cmd'] === 'show') { return ['got' => 'show', 'ok' => $want === 'show', 'detail' => 'gate show mode=' . $g['mode']]; }
    if (str_starts_with($gc, 'auto') && $want === 'words' && qlIsAuto($B)) {
        // S4.6 the re-arm: a question turn with no pick re-arms the breath (adv + rearm=1 in the same reply) - no words-release
        return ['got' => 'words', 'ok' => true, 'detail' => 'his words released nothing; the gate re-armed the breath (adv=' . $g['adv'] . ')'];
    }
    if ($g['cmd'] !== 'none') {
        if ($want === 'words') { return ['got' => $gc, 'ok' => false, 'detail' => 'LLM path: the target was clicked where only words were due (mode ' . $g['mode'] . ')']; }
        return ['got' => $gc, 'ok' => qlMatches($want, $gc), 'o' => $g, 'detail' => 'gate ' . $g['cmd'] . ' pos=' . $g['pos'] . ' mode=' . $g['mode'] . ' key=' . $g['key']];
    }
    if ($g['parked'] === (string) $te['norm'] || $g['parked'] === lrgPromptNorm((string) $te['text'])) {
        // the release (S4.4, model 2.5): his assent on the next turn - the owner's own shapes, rotated per paraphrase - carried
        // by the model's key, or (every other paraphrase) by a words-only reply the gate completes itself (F27, mode bare-yes)
        [$yes, $byKey] = qlAssent($B, $si);
        fxAdvance(4);
        $y = $byKey ? qlLlm($B, $L, $yes, $label . ' +' . $yes, 'Then it is done.') : qlLlmNothing($B, $yes, $label . ' +' . $yes, 'Then it is done.');
        $ok = $y['cmd'] === 'pick' && $y['pos'] === $L['ti'] && ($byKey || $y['mode'] === 'bare-yes');
        $got = $ok ? 'park' : 'park-stuck';
        $how = 'parked; "' . $yes . '" (' . ($byKey ? 'the model re-emits the key' : 'a words-only reply, F27') . ') '
            . ($ok ? 'released it (mode ' . $y['mode'] . ')' : 'did not release it: ' . $y['cmd'] . ' pos=' . $y['pos'] . ' mode=' . $y['mode'] . ' | ' . qlWhy($y['log']));
        if ($want === 'park') { return ['got' => $got, 'ok' => $ok, 'o' => $y, 'detail' => $how]; }
        if ($want === 'words') { return ['got' => $got, 'ok' => false, 'detail' => 'a words line parked the target (her question then clicks it on his assent): ' . $how]; }
        return ['got' => $got, 'ok' => false, 'detail' => 'wanted ' . $want . ': ' . $how . ' | ' . qlWhy($g['log'])];
    }
    return ['got' => 'words', 'ok' => $want === 'words', 'detail' => $want === 'words' ? 'nothing clicked' : 'nothing on either path: fast ' . qlWhy($f['log']) . ' | gate ' . qlWhy($g['log'])];
}

/** An auto beat: an unscripted, effect-free single that the breath advances (S4.6). */
function qlIsAuto(array $B): bool
{
    return (string) ($B['expect'] ?? '') === 'auto' || (string) ($B['stageB'] ?? '') === 'auto';
}

/** The gate / fast-path reason line of a log slice, short. */
function qlWhy(string $log): string
{
    $keep = [];
    foreach (preg_split('/\R/', $log) as $l) {
        if (preg_match('/dlg (want=1 npc=[^:]*: (.*)|gate: (.*))$/', $l, $m)) { $keep[] = trim((string) ($m[2] !== '' ? $m[2] : ($m[3] ?? ''))); }
    }
    return fx_short(implode(' / ', array_slice($keep, -2)), 220);
}

/**
 * A never line (spec 3.4 step 4, model 2.4). FAST PATH: no command - on an auto beat the breath may still advance an unreleased
 * continuation (row 22), EXCEPT when his new words are a refusal (step 0: "nothing, and no auto-advance"). LLM PATH (the key
 * forced): nothing on a COMMIT line; on EVERY single-entry key step 0/0b apply (model 2.4 last paragraph) - a refusal clicks
 * nothing and re-arms nothing (row 24 "not a refusal"), a shape refusal clicks nothing but may re-arm the breath (adv + rearm),
 * and only a plain single with neither is the model's reach by design (ai R1); another line is never clicked.
 */
function qlNever(array $B, array $L, array $seam, string $text, int $clicks): array
{
    // step 0/0b, read from the gate's own grading of the entry (a fresh world, the list on screen)
    qlOpenWorld($B, $L, $seam, $clicks);
    $live = null;
    foreach ((array) ((fxDlgStateOf((string) $B['npc'])['session'] ?? [])['entries'] ?? []) as $e) { if ((int) $e['pos'] === (int) $L['ti']) { $live = $e; } }
    $single = $L['kind'] === 'single' && $live;
    $rel = $single ? lrgDlgSingleEntryRelease($live, $text) : ['refused' => false, 'shape' => false, 'step' => ''];
    $refusal = !empty($rel['refused']) && empty($rel['shape']);
    $shape = !empty($rel['refused']) && !empty($rel['shape']);
    $why0 = $single ? ' [S4.5 ' . $rel['step'] . ']' : '';
    qlWorld($seam, $clicks, (array) ($B['cfg'] ?? []));
    qlFacts($B);
    fxAdvance(3);
    $f = qlFast($B, $L, $text);
    $breath = $f['cmd'] === 'pick' && $f['adv'] >= 0 && qlIsAuto($B) && $f['pos'] === $L['ti'];
    if ($f['cmd'] !== 'none' && (!$breath || $refusal)) {
        return ['ok' => false, 'detail' => 'fast path ' . $f['cmd'] . ' pos=' . $f['pos'] . ' mode=' . $f['mode'] . ($f['adv'] >= 0 ? ' adv=' . $f['adv'] : '')
            . ($refusal ? ' - his words REFUSE the line: nothing, and no auto-advance' : '') . $why0];
    }
    qlOpenWorld($B, $L, $seam, $clicks);
    $g = qlLlm($B, $L, $text, $B['id'] . ' never "' . $text . '"');
    $commit = $live && (!empty($live['commit']) || (string) ($live['class'] ?? '') === 'commit');
    if ($g['cmd'] === 'pick' && $g['pos'] !== $L['ti']) { return ['ok' => false, 'detail' => 'key forced: ANOTHER line was clicked, pos=' . $g['pos']]; }
    $rearm = $g['cmd'] === 'pick' && $g['adv'] >= 0 && qlIsAuto($B);
    if ($rearm) {
        return ['ok' => !$refusal, 'detail' => $refusal ? 'a refusal re-armed the breath (adv=' . $g['adv'] . ') - row 24 forbids it' . $why0
            : 'the gate re-armed the breath (adv=' . $g['adv'] . ') - no words-release' . $why0];
    }
    if ($commit) {
        return ['ok' => $g['cmd'] === 'none', 'detail' => 'commit line, key forced: ' . $g['cmd'] . ' mode=' . $g['mode'] . ($g['parked'] !== '' ? ' (parked)' : '') . $why0];
    }
    if ($single) {
        if ($refusal || $shape) { return ['ok' => $g['cmd'] === 'none', 'detail' => 'single, key forced after a ' . ($refusal ? 'refusal' : 'shape refusal') . ': ' . $g['cmd'] . ' mode=' . $g['mode'] . $why0]; }
        return ['ok' => true, 'detail' => 'plain single: the model\'s reach is by design (' . $g['cmd'] . ')' . $why0];
    }
    return ['ok' => true, 'detail' => 'plain line, key forced: ' . $g['cmd'] . ' pos=' . $g['pos']];
}

/** At clicks_ok 0, the list on screen, the target's key forced: nothing is clicked and the one rail sentence is the true one
 *  (qlRailSentence); `rail_names` (texts) must be among the keys it names. Returns [good, detail, the gate outcome]. */
function qlRailBeat(array $B, array $L, array $seam, string $text): array
{
    qlOpenWorld($B, $L, $seam, 0);
    $g = qlLlm($B, $L, $text, $B['id'] . ' rail "' . $text . '"');
    [$sg, $sd, $ok] = qlRailSentence((array) $g['turn'], (string) $g['volatile']);
    $named = [];
    foreach ((array) ($B['rail_names'] ?? []) as $nm) {
        $k = '';
        foreach ($L['entries'] as $i => $e) { if (lrgPromptNorm((string) $e['text']) === lrgPromptNorm((string) $nm)) { $k = 'T' . ($i + 1); } }
        if ($k === '' || !in_array($k, $ok, true)) { $named[] = '"' . $nm . '" (' . ($k ?: 'not listed') . ') is NOT named as pickable'; }
    }
    $closed = (bool) preg_match('/not on the table|nothing like that/i', (string) (preg_match('/^(Until he has asked|I cannot pick any)[^\n]*$/m', (string) $g['volatile'], $mm) ? $mm[0] : ''));
    $good = $g['cmd'] === 'none' && $sg && !$named && !$closed;
    return [$good, 'gate ' . $g['cmd'] . ' / ' . $sd . ($named ? ' / ' . implode('; ', $named) : '') . ($closed ? ' / a CLOSED-LIST word' : ''), $g];
}

// ================================================================== --words: the model's free-text item (intent mode)
function qlWordsMode(array $B, array $L, array $seam, array $s, int $clicks): string
{
    qlOpenWorld($B, $L, $seam, $clicks);
    $npc = (string) $B['npc'];
    QlLog::mark();
    fxDlgSay($npc, null, (string) $s['t']);
    $out = fxDlgLlm($npc, (string) $s['t'], 'Very well.');
    $o = qlOutcome(qlCmds($out), QlLog::since());
    $parked = (string) ((fxDlgStateOf($npc)['parked'] ?? [])['norm'] ?? '');
    // (an effect-free continuation re-armed on an auto beat is the breath, not his words releasing it - S4.6, as qlNever reads it)
    if ($o['cmd'] === 'pick' && $o['adv'] >= 0 && qlIsAuto($B) && $o['pos'] === $L['ti']) { return 'breath'; }
    if ($o['cmd'] === 'pick') { return $o['pos'] === $L['ti'] ? 'resolve' : 'WRONG'; }
    if ($parked !== '' && $parked === (string) $L['entries'][$L['ti']]['norm']) { return 'ask'; }
    if ($o['cmd'] === 'show') { return 'show'; }
    return 'nothing';
}

// ================================================================== [pt19h-harness r2] the baselines (--words here; --extended in the adapter)
/** Outcome ranks, best first: a line whose rank grew is WORSE than on the baseline code. */
const QL_RANK_WORDS = ['resolve' => 0, 'breath' => 0, 'ask' => 2, 'show' => 3, 'nothing' => 4, 'WRONG' => 5];

/** The live entry at a list position (the gate's grading: class, commit, scripted, crit, cost, kind). */
function qlLiveAt(array $entries, int $pos): ?array
{
    foreach ($entries as $e) { if ((int) ($e['pos'] ?? -1) === $pos) { return $e; } }
    return null;
}

/** The words outcome a line's `via` asks for (the fixture's own label): a park line asks, a words line is answered in words, the rest resolve. */
function qlWordsWant(string $via): string
{
    if ($via === 'park') { return 'ask'; }
    if (in_array($via, ['words', 'never', 'none'], true)) { return 'nothing'; }
    return 'resolve';
}

/** A baseline file of this mode (tools/fixtures/lrg_questline_{words,extended}_baseline.json), or null. */
function qlBaselineRead(string $file, string $mode): ?array
{
    if ($file === '' || !is_file($file)) { return null; }
    $j = json_decode((string) @file_get_contents($file), true);
    return is_array($j) && ($j['_'] ?? '') === 'lrg_questline_baseline' && ($j['mode'] ?? '') === $mode && is_array($j['lines'] ?? null) ? $j : null;
}

/** Write a baseline (one line per entry, so a re-baseline diffs line by line). */
function qlBaselineWrite(string $file, string $mode, array $lines, array $meta): void
{
    ksort($lines);
    $F = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
    $hdr = ['_' => 'lrg_questline_baseline', 'v' => 1, 'mode' => $mode] + $meta + ['accepted' => new stdClass()];
    $s = rtrim(substr((string) json_encode($hdr, $F | JSON_PRETTY_PRINT), 0, -1));
    $s .= ",\n    \"lines\": {\n" . implode(",\n", array_map(static fn($a, $b) => '        ' . json_encode((string) $a, $F) . ': ' . json_encode($b, $F), array_keys($lines), $lines)) . "\n    }\n}\n";
    if (json_decode($s, true) === null) { fwrite(STDERR, "the baseline does not re-parse - not written\n"); return; }
    file_put_contents($file, $s);
    echo "baseline written: $file (" . count($lines) . " lines)\n";
}

/** --words: the key of one words row (main / adversarial, beat, say / never, the sentence, clicks_ok). */
function qlWordsKey(array $w): string
{
    return ($w[3] ? 'adv' : 'main') . '|' . $w[0] . '|' . $w[4] . '|' . $w[5] . '|' . $w[6];
}

/**
 * --words against a baseline: every say line whose words outcome got WORSE (resolve > ask > show > nothing > WRONG) is listed; a
 * first-evening or a verbatim line getting worse FAILS - unless it now does what its `via` asks (the fixture's label), or a protected
 * target now asks first (the safety rules allow that; a human confirms), or the baseline file's `accepted` names that change.
 * A `never` row is not compared: a click there already fails the run.
 */
function qlWordsBaseline(array $base, string $file): void
{
    $lines = (array) $base['lines'];
    $acc = (array) ($base['accepted'] ?? []);
    $n = 0; $better = 0; $new = 0; $worse = [];
    foreach (Ql::$words as $w) {
        if (count($w) < 11 || $w[4] !== 'say') { continue; }
        $k = qlWordsKey($w);
        if (!isset($lines[$k])) { $new++; continue; }
        $n++;
        $was = (string) $lines[$k];
        $now = (string) $w[2];
        $ra = QL_RANK_WORDS[$was] ?? 4;
        $rb = QL_RANK_WORDS[$now] ?? 4;
        if ($rb < $ra) { $better++; }
        if ($rb <= $ra) { continue; }
        $why = '';
        if ($now === qlWordsWant((string) $w[9])) { $why = 'it now does what its via (' . $w[9] . ') asks'; }
        elseif ($now === 'ask' && $w[10]) { $why = 'a protected line now asks first (the safety rules allow it; a human confirms)'; }
        elseif ($now === 'nothing' && !empty($w[11])) { $why = 'he cannot pay it: a priced line above his purse is never clicked or offered (never-false)'; }
        elseif (isset($acc[$k]) && (string) (((array) $acc[$k])['now'] ?? '') === $now) { $why = 'accepted in the baseline: ' . (string) (((array) $acc[$k])['why'] ?? ''); }
        $worse[] = [$w, $was, $now, $why, $why === '' && ($w[7] || $w[8])];
    }
    $m = (array) ($base['measured'] ?? []);
    echo "\n--words vs the baseline (" . basename($file) . ': measured on ' . (string) ($m['code'] ?? '?') . ', ' . (string) ($m['at'] ?? '?')
        . "): $n say lines compared, " . count($worse) . " WORSE, $better better, $new not in the baseline\n";
    $tr = [];
    foreach ($worse as $x) { $tr[$x[1] . '->' . $x[2]] = ($tr[$x[1] . '->' . $x[2]] ?? 0) + 1; }
    arsort($tr);
    if ($tr) { echo '  worse by transition: ' . implode(', ', array_map(static fn($k, $v) => "$k $v", array_keys($tr), $tr)) . "\n"; }
    foreach ($worse as [$w, $was, $now, $why, $fails]) {
        printf("  %s %-7s -> %-7s %s%s%s \"%s\" @%d (via %s)%s\n", $fails ? 'FAIL ' : 'worse', $was, $now, $w[3] ? 'adv:' : '', $w[0], $w[7] ? ' [first evening]' : '',
            $w[5], $w[6], (string) $w[9], $why !== '' ? ' - not a failure: ' . $why : ($w[8] ? ' [verbatim]' : ''));
        if ($fails) {
            qlChk(($w[3] ? 'adv:' : '') . $w[0] . ' --words: "' . $w[5] . '" is no worse than on the baseline code', false,
                "was $was, now $now (" . ($w[7] ? 'a first-evening line' : 'a verbatim line') . '; via ' . $w[9] . '); accept it only by re-baselining or '
                . 'an `accepted` entry in ' . basename($file) . ' with the reason', ($w[3] ? 'adv:' : '') . $w[0] . '|words-baseline|' . $w[5]);
        }
    }
}

// ================================================================== a standard beat
function qlBeat(array $B, QlIndex $IX): void
{
    global $WORDS;
    $id = (string) $B['id'];
    $L = qlLayer($B, $IX);
    $B['_sc'] = false;
    $scQuest = (string) ($B['scene'] ?? '');
    if ($scQuest !== '' && (string) ($B['origin'] ?? '') === 'engine' && empty($B['amb'])) { $B['_sc'] = $IX->hasJournal($scQuest); }
    $cls = (string) $B['expect'];
    $row = ['quest' => (string) $B['quest'], 'beat' => $id, 'class' => $cls, 'path' => '-', 'say' => '0/0', 'never' => '-'];
    if ($L['err'] !== '') {
        qlChk("$id: the layer is built from the index", false, $L['err']);
        $row['path'] = 'NO LAYER';
        Ql::$table[] = $row;
        return;
    }
    if (!empty($B['scene']) && !empty($B['sc_expected']) !== $B['_sc']) {
        qlChk("$id: the scene is a journal scene exactly when the fixture says (derived from $scQuest's journal rows)", false,
            'fixture sc=' . (!empty($B['sc_expected']) ? 1 : 0) . ', index says ' . ($B['_sc'] ? 1 : 0));
    }
    $seam = qlSeam($B, $L, $IX);
    $te = $L['entries'][$L['ti']];
    $says = (array) ($B['say'] ?? []);
    // how the gate grades the target (the index lookup, the fail-safe merge, the hub rule) against its own index row: every
    // risk the merge ADDS is recorded - it moves paths (a plain line parks, a continuation stops advancing)
    qlOpenWorld($B, $L, $seam, 1);
    $liveEntries = (array) ((fxDlgStateOf((string) $B['npc'])['session'] ?? [])['entries'] ?? []);
    // (every entry, not only the target: a SIBLING graded above its own row moves the target too - the sibling rule and the
    // root / closed call read the whole list; MG01.learn's target turns commit only through Wuunferth's row beside it)
    foreach ($liveEntries as $e) {
        if (!isset($L['entries'][(int) $e['pos']])) { continue; }
        $r = $L['entries'][(int) $e['pos']]['row'];
        $add = [];
        if ((int) ($e['scripted'] ?? 0) > (int) $r['scripted'] && empty(($r['flags'] ?? [])['invis'])) { $add[] = 'scripted'; }
        if ((int) ($e['goodbye'] ?? 0) > (int) (($r['flags'] ?? [])['goodbye'] ?? 0)) { $add[] = 'goodbye'; }
        if ((int) ($e['walkaway'] ?? 0) > (int) (($r['flags'] ?? [])['walkaway'] ?? 0)) { $add[] = 'walkaway'; }
        if ((int) ($e['crit'] ?? 0) > (int) $r['crit']) { $add[] = 'crit ' . (int) $e['crit']; }
        if ((string) ($e['kind'] ?? '') !== '' && (string) $r['kind'] === '' && lrgDlgKindFromTag((string) $r['txt']) === '') { $add[] = 'kind ' . $e['kind']; }
        if ($add && (string) ($e['topic'] ?? '') !== '' && (string) $e['topic'] !== (string) $r['topic']) { $add[] = 'graded by ' . $e['topic'] . "'s row"; }
        if ($add) {
            Ql::$overclaim[] = sprintf('%s %s"%s": +%s -> class %s%s', $id, (int) $e['pos'] === (int) $L['ti'] ? '' : 'sibling pos' . (int) $e['pos'] . ' ',
                substr((string) $e['text'], 0, 40), implode(' +', $add), (string) $e['class'], !empty($e['commit']) ? ' (commit)' : '');
            Ql::$overBeat[$id] = true;
        }
    }
    // the same beat with the merge scoped (measurement: what a failing paraphrase does once lrgPromptEscalate stops borrowing risk
    // from other topics) - printed beside each failure here, so the owner of the merge sees the path it takes away
    $seamScoped = !empty(Ql::$overBeat[$id]) && empty($GLOBALS['QL_SCOPED']) ? qlSeam($B, $L, $IX, true) : null;
    if ($GLOBALS['EXPLAIN']) {
        foreach ($liveEntries as $e) {
            echo sprintf("    %s pos=%d class=%s commit=%d scripted=%d goodbye=%d walkaway=%d kind=%s cost=%d hub=%d crit=%d topic=%s \"%s\"\n",
                (int) $e['pos'] === (int) $L['ti'] ? '*' : ' ', (int) $e['pos'], (string) $e['class'], !empty($e['commit']) ? 1 : 0,
                (int) ($e['scripted'] ?? 0), (int) ($e['goodbye'] ?? 0), (int) ($e['walkaway'] ?? 0), (string) ($e['kind'] ?? ''),
                (int) ($e['cost'] ?? 0), (int) ($e['hub'] ?? 0), (int) ($e['crit'] ?? 0), (string) ($e['topic'] ?? ''), substr((string) $e['text'], 0, 60));
        }
    }
    if (count($says) < 3 && !in_array($cls, ['none'], true) && empty($B['fe'])) {
        qlChk("$id: at least three paraphrases", false, count($says) . ' given');
    }
    $okN = 0; $tot = 0; $paths = [];
    // the scene beats: clicks_ok 0 first - nothing is clicked and the read-only clause is present (spec 3.4 step 5)
    if ($B['_sc']) {
        foreach ($says as $s) {
            if ((string) $s['t'] === '') { continue; }
            $tot++;
            qlWorld($seam, 0, (array) ($B['cfg'] ?? []));
            qlFacts($B);
            fxAdvance(3);
            $f = qlFast($B, $L, (string) $s['t']);
            qlOpenWorld($B, $L, $seam, 0);
            $g = qlLlm($B, $L, (string) $s['t'], "$id \"" . $s['t'] . '" @0 scene');
            $ro = str_contains($g['volatile'], 'choose it on the list yourself - I cannot pick for you here');
            $good = $f['cmd'] === 'none' && $g['cmd'] === 'none' && $ro;
            if ($good) { $okN++; }
            qlChk("$id @clicks_ok 0 (journal scene, unproven): \"" . $s['t'] . '" clicks nothing and the read-only clause is said', $good,
                'fast ' . $f['cmd'] . ' / gate ' . $g['cmd'] . ' / read-only clause ' . ($ro ? 'present' : 'ABSENT'));
        }
        $paths['scene'] = 1;
    }
    $clickList = array_map('intval', (array) ($B['clicks'] ?? [(int) (($B['facts'] ?? [])['clicks_ok'] ?? 1)]));
    if ($B['_sc']) { $clickList = [1]; }
    // each paraphrase is judged in a fresh world; the S4.3 NEW-utterance guard across sentences is qlGuards()' (one beat per quest)
    foreach ($clickList as $clicks) {
    $sfx = count($clickList) > 1 ? '@' . $clicks : '';
    foreach ($says as $si => $s) {
        $want = (string) $s['via'];
        if ($B['_sc'] && isset($s['viaB'])) { $want = (string) $s['viaB']; }
        $s['via'] = $want;
        $tot++;
        $r = qlResolve($B, $L, $seam, $s, $clicks, $si);
        if (!$r['ok'] && (string) $s['t'] !== '' && $liveEntries) {
            // the real matcher's number for the record (a line that misses a floor is re-classed WITH it, never by lowering it)
            $mm = lrgDlgMatchText((string) $s['t'], array_values(array_filter($liveEntries, static fn($e) => (string) $e['class'] !== 'hidden')));
            if (is_array($mm)) { $r['detail'] .= sprintf(' | s=%.3f m=%.3f top=pos%d', (float) $mm['score'], (float) $mm['margin'], (int) ($liveEntries[$mm['i']]['pos'] ?? -1)); }
        }
        if (!$r['ok'] && $seamScoped !== null) {
            $r2 = qlResolve($B, $L, $seamScoped, $s, $clicks, $si);
            $r['detail'] .= ' | with the merge scoped: ' . $r2['got'] . ($r2['ok'] ? ' - RESOLVES' : ' - still fails');
        }
        if ($r['ok']) { $okN++; $paths[$want] = 1; }
        if (!$r['ok']) { Ql::$measured[] = ['beat' => $id, 'i' => $si, 't' => (string) $s['t'], 'want' => $want, 'got' => (string) $r['got'], 'detail' => (string) $r['detail'], 'over' => !empty(Ql::$overBeat[$id]), 'known' => isset(Ql::$known[$id . '|' . $s['t'] . $sfx])]; }
        qlChk("$id @clicks_ok $clicks: \"" . $s['t'] . "\" -> $want", $r['ok'], 'got ' . $r['got'] . ': ' . $r['detail']
            . (isset($s['note']) ? ' | fixture note: ' . $s['note'] : ''), $id . '|' . $s['t'] . $sfx);
        if ($WORDS && (string) $s['t'] !== '') {
            $w = qlWordsMode($B, $L, $seam, $s, $clicks);
            // [pt19h-harness r2] + [adv, kind, t, clicks, fe, verbatim, via, protected target] for the baseline comparison (qlWordsBaseline)
            Ql::$words[] = [$id, (string) $s['t'], $w, !empty($B['_adv']), 'say', (string) $s['t'], $clicks, !empty($B['fe']),
                qlxSame((string) $s['t'], (string) $te['text']), $want, qlxProtected(qlLiveAt($liveEntries, (int) $L['ti'])),
                (int) ((qlLiveAt($liveEntries, (int) $L['ti']) ?? [])['cost'] ?? 0) > (int) ((($B['facts'] ?? [])['pg']) ?? 300)];
            if ($w === 'WRONG') { qlChk("$id --words: \"" . $s['t'] . '" as the model\'s words never clicks a sibling', false, 'a sibling was clicked', $id . '|' . $s['t']); }
        }
        // the stage rail's sentence at clicks_ok 0 (model R8, capability map U7): the one line she is given names exactly the keys
        // that pass - or, where none does, says so, and never "ask me something simple first" (it could not come true there)
        if ($clicks === 0 && !empty($B['rail']) && (string) $s['t'] !== '') {
            [$rg, $rd] = qlRailBeat($B, $L, $seam, (string) $s['t']);
            qlChk("$id @clicks_ok 0: \"" . $s['t'] . '" - the rail sentence is the true one for this list', $rg, $rd, $id . '|rail|' . $s['t']);
        }
    }
    }
    // `rail0`: every line of a beat that runs at clicks_ok 1 is also said at clicks_ok 0 - nothing clicked on either path, and the
    // true rail sentence (capability map U6: the Whiterun gate's note line "0 -> nothing, with the U7 sentence")
    if (!empty($B['rail0']) && !in_array(0, $clickList, true)) {
        foreach ($says as $s) {
            if ((string) $s['t'] === '') { continue; }
            $tot++;
            qlWorld($seam, 0, (array) ($B['cfg'] ?? []));
            qlFacts($B);
            fxAdvance(3);
            $f = qlFast($B, $L, (string) $s['t']);
            [$rg, $rd, $g] = qlRailBeat($B, $L, $seam, (string) $s['t']);
            $good = $f['cmd'] === 'none' && $g['cmd'] === 'none' && $rg;
            if ($good) { $okN++; }
            qlChk("$id @clicks_ok 0: \"" . $s['t'] . '" clicks nothing and the rail sentence is the true one', $good,
                'fast ' . $f['cmd'] . ' / gate ' . $g['cmd'] . ' / ' . $rd, $id . '|rail0|' . $s['t']);
        }
    }
    $nOk = 0; $nTot = 0;
    foreach ((array) ($B['never'] ?? []) as $nv) {
        $nTot++;
        $r = qlNever($B, $L, $seam, (string) $nv, $clicks);
        if ($r['ok']) { $nOk++; }
        qlChk("$id never: \"$nv\" clicks nothing", $r['ok'], $r['detail'], $id . '|never|' . $nv);
        // [pt19c fixer / adversarial QA "WORDS MODE"] the same line as the model's FREE-TEXT item (no key): it may ask, quoting
        // the line, or do nothing - never click the target or a sibling (the words path has the fast path's guards)
        if ($WORDS) {
            $w = qlWordsMode($B, $L, $seam, ['t' => (string) $nv], $clicks);
            Ql::$words[] = [$id, 'never: ' . $nv, $w, !empty($B['_adv']), 'never', (string) $nv, $clicks, !empty($B['fe']), false, 'never',
                qlxProtected(qlLiveAt($liveEntries, (int) $L['ti']))];
            qlChk("$id --words never: \"$nv\" as the model's words clicks nothing", !in_array($w, ['resolve', 'WRONG'], true),
                'the words path ' . ($w === 'WRONG' ? 'clicked a sibling' : 'clicked the target'), $id . '|words-never|' . $nv);
        }
    }
    $row['path'] = implode('+', array_keys($paths)) ?: '-';
    $row['class'] = $B['_sc'] ? 'scene/' . ($B['stageB'] ?? $cls) : $cls;
    $row['say'] = "$okN/$tot";
    $row['never'] = $nTot ? "$nOk/$nTot" : '-';
    Ql::$table[] = $row;
    $pq = &Ql::$perQuest[(string) $B['quest']];
    $pq = ($pq ?? ['beats' => 0, 'ok' => 0, 'total' => 0, 'nopath' => 0]);
    $pq['beats']++; $pq['ok'] += $okN; $pq['total'] += $tot;
    if ($okN < $tot) { $pq['nopath']++; }
    unset($pq);
}

// ================================================================== special beats (open / entry / words-only / none)
/** A pre-LLM open (spec 3.4 `open`, 3.7 FE.hulda.open): no list, his words -> a do=open queued before the model runs. */
function qlOpenBeat(array $B, QlIndex $IX): void
{
    $id = (string) $B['id'];
    $npc = (string) $B['npc'];
    $B['_sc'] = false;
    $L = ['kind' => 'root', 'entries' => [], 'ti' => -1];
    $rootL = null;
    if (!empty($B['root'])) {
        $rootL = qlLayer(['layer' => ['kind' => 'root', 'topics' => $B['root']], 'target' => $B['root_target']] + $B, $IX);
        if ($rootL['err'] !== '') { qlChk("$id: the root list is built from the index", false, $rootL['err']); return; }
    }
    $seam = $rootL ? qlSeam($B, $rootL, $IX) : qlSeam($B, $L, $IX);
    foreach ((array) ($B['seam_rows'] ?? []) as $ik) { $r = $IX->row((string) $ik); if ($r) { $seam['rows'][] = $r; } }
    $okN = 0; $tot = 0;
    foreach ((array) ($B['passes'] ?? [['name' => '', 'warm' => false]]) as $pass) {
        // a pass may run at its own clicks_ok: model row 1 / F19 - at 0 only a clause whose predicted row passes the stage rail opens
        $pClicks = (int) ($pass['clicks_ok'] ?? (($B['facts'] ?? [])['clicks_ok'] ?? 1));
        foreach ((array) ($pass['say'] ?? $B['say']) as $s) {
            $tot++;
            qlWorld($seam, $pClicks, (array) ($B['cfg'] ?? []));
            qlFacts($B);
            if (!empty($pass['warm']) && $rootL) {
                // an E-press earlier: her root list was read and closed (the cache clause 3 answers from)
                qlTopics($B, $rootL, 0, 'qlw' . Ql::$n, ['origin' => 'engine', 'layer' => 0, 'sid' => 'warm' . Ql::$n]);
                fxDlgEvent($npc, 'closed', ['sid' => 'warm' . Ql::$n, 'why' => 'goodbye', 'pending' => 0, 'layer' => 0]);
                fxAdvance(40);
            }
            fxDlgClearQueue();
            QlLog::mark();
            $q = fxDlgSay($npc, null, (string) $s['t']);
            $log = QlLog::since();
            $opens = array_values(array_filter(fxDlgQueue(), static fn($r) => str_contains((string) ($r['action'] ?? ''), ';do=open;')));
            $want = (string) $s['via'];
            if ($want === 'open') {
                $marker = (string) ($s['marker'] ?? '');
                $mOk = $marker === '' || str_contains($log, 'open marker=' . $marker . ' ');
                $amb = !isset($B['amb']) || !$B['amb'] || (count($opens) === 1 && str_contains((string) $opens[0]['action'], ';amb=1'));
                $bridge = !array_key_exists('bridge', $B) || !$B['bridge']
                    || (str_contains((string) $q['volatile'], 'The list of what') && !str_contains((string) $q['volatile'], 'on screen'));
                $good = count($opens) === 1 && $mOk && $amb && $bridge;
                qlChk("$id" . ($pass['name'] !== '' ? ' (' . $pass['name'] . ')' : '') . ': "' . $s['t'] . '" queues ONE do=open before the model'
                    . ($marker !== '' ? " (marker=$marker)" : '') . (!empty($B['amb']) ? ', amb=1' : '') . (!empty($B['bridge']) ? ', the bridging directive, no state wording' : ''),
                    $good, 'opens=' . count($opens) . ' marker ' . ($mOk ? 'ok' : 'WRONG') . ' amb ' . ($amb ? 'ok' : 'MISSING') . ' bridge ' . ($bridge ? 'ok' : 'MISSING')
                    . ' | ' . fx_short(implode(' / ', array_filter(preg_split('/\R/', $log), static fn($l) => str_contains($l, 'open'))), 300));
            } else {
                // `log`: the reason that must be in the log (F19 at clicks_ok 0: the kind clause matched and was refused, so the
                // turn falls through to 10.15's net - not "no clause matched")
                $why = (string) ($s['log'] ?? '');
                $good = count($opens) === 0 && ($why === '' || str_contains($log, $why));
                qlChk("$id" . ($pass['name'] !== '' ? ' (' . $pass['name'] . ')' : '') . ': "' . $s['t'] . '" opens NOTHING'
                    . ($why !== '' ? " (log: \"$why\")" : ''), $good,
                    'opens=' . count($opens) . ($why !== '' ? ' reason ' . (str_contains($log, $why) ? 'present' : 'ABSENT') : '') . ' '
                    . fx_short(implode(' / ', array_filter(preg_split('/\R/', $log), static fn($l) => str_contains($l, 'open') || str_contains($l, 'pre-LLM'))), 300));
            }
            if ($good) { $okN++; }
        }
    }
    Ql::$table[] = ['quest' => (string) $B['quest'], 'beat' => $id, 'class' => 'open', 'path' => $okN === $tot ? 'open' : '-', 'say' => "$okN/$tot", 'never' => '-'];
    $pq = &Ql::$perQuest[(string) $B['quest']];
    $pq = ($pq ?? ['beats' => 0, 'ok' => 0, 'total' => 0, 'nopath' => 0]);
    $pq['beats']++; $pq['ok'] += $okN; $pq['total'] += $tot; if ($okN < $tot) { $pq['nopath']++; }
    unset($pq);
}

/** 10.26's click-free quest entry (`entry`) and the closed road (`words` with a locked fact): the faction lane, pre-LLM. */
function qlFactionBeat(array $B, QlIndex $IX): void
{
    $id = (string) $B['id'];
    $npc = (string) $B['npc'];
    $B['_sc'] = false;
    $L = ['kind' => 'root', 'entries' => [], 'ti' => -1];
    $seam = qlSeam($B, $L, $IX);
    $okN = 0; $tot = 0;
    foreach ((array) $B['say'] as $s) {
        $tot++;
        qlWorld($seam, (int) (($B['facts'] ?? [])['clicks_ok'] ?? 1), (array) ($B['cfg'] ?? []));
        if (isset($B['mcm'])) { $GLOBALS['LRG_DLG_MCM'] = (array) $B['mcm']; }
        qlFacts($B);
        if (!empty($B['open_refused'])) { lrgDlgPut($npc, ['open_refused' => ['why' => 'a quest scene is running', 'at' => fxNow()]]); }
        fxDlgClearQueue();
        QlLog::mark();
        $q = fxDlgSay($npc, null, (string) $s['t']);
        $out = array_values((array) lrgPostProcessActions(fxDlgLlmNothing($npc, 'one short spoken line')));
        $log = QlLog::since();
        $entries = array_values(array_filter($out, static fn($l) => str_contains((string) $l, 'ExtCmdLRG_QuestEntry@')));
        $opens = array_values(array_filter(fxDlgQueue(), static fn($r) => str_contains((string) ($r['action'] ?? ''), ';do=open;')));
        $block = is_array($q['turn'] ?? null) ? (string) lrgDlgLockedBlock($q['turn']) : '';
        $want = (string) $s['via'];
        if ($want === 'entry') {
            $good = count($entries) === 1 && $opens === [];
            $detail = 'entries=' . count($entries) . ' opens=' . count($opens);
        } elseif ($want === 'open') {
            $good = count($opens) === 1 && $entries === [] && (empty($B['amb']) || str_contains((string) $opens[0]['action'], ';amb=1'));
            $detail = 'opens=' . count($opens) . ' entries=' . count($entries);
        } elseif ($want === 'open|entry') {
            // S2.3 (Lane B) decides WHICH carrier answers the ask after Helgen; the interlock (lrgDlgPreOpen) makes "both"
            // impossible - exactly ONE of the honest open (amb=1) and 10.26's click-free entry goes out
            $good = count($opens) + count($entries) === 1 && ($opens === [] || empty($B['amb']) || str_contains((string) $opens[0]['action'], ';amb=1'));
            $detail = 'opens=' . count($opens) . ' entries=' . count($entries) . ' (one carrier)';
        } else {
            $fact = (string) ($B['expect_fact'] ?? '');
            $good = $entries === [] && $opens === [] && ($fact === '' || stripos($block, $fact) !== false);
            $detail = 'entries=' . count($entries) . ' opens=' . count($opens) . ' fact "' . $fact . '" ' . ($fact === '' || stripos($block, $fact) !== false ? 'present' : 'ABSENT') . ' | ' . fx_short($block, 200);
        }
        if ($good) { $okN++; }
        qlChk("$id: \"" . $s['t'] . "\" -> $want", $good, $detail . ' | ' . fx_short(implode(' / ', array_filter(preg_split('/\R/', $log), static fn($l) => preg_match('/faction|open|entry/', $l))), 260), $id . '|' . $s['t']);
    }
    Ql::$table[] = ['quest' => (string) $B['quest'], 'beat' => $id, 'class' => (string) $B['expect'], 'path' => $okN === $tot ? (string) $B['expect'] : '-', 'say' => "$okN/$tot", 'never' => '-'];
    $pq = &Ql::$perQuest[(string) $B['quest']];
    $pq = ($pq ?? ['beats' => 0, 'ok' => 0, 'total' => 0, 'nopath' => 0]);
    $pq['beats']++; $pq['ok'] += $okN; $pq['total'] += $tot; if ($okN < $tot) { $pq['nopath']++; }
    unset($pq);
}

/**
 * `chain`: ONE sentence, two clicks, on the NPC's real rows (capability map U3, the owner page's "I'd like a room for the night"):
 * his words pick step 1's line on the fast path, and the list that click opens arrives with NO new words - the stored sentence
 * names step 2's slot there (the carriage-style chain across the S4.3 new-utterance guard). Each `purses` entry replays the chain
 * with his purse (pg) and what the LAST click must do: `pick` (the slot, at `cost` septims - under 100 and under a quarter of the
 * purse) or `ask` (F29: at 25 % of his purse or more nothing is clicked; she asks first).
 */
function qlChainBeat(array $B, QlIndex $IX): void
{
    $id = (string) $B['id'];
    $npc = (string) $B['npc'];
    $B['_sc'] = false;
    $Ls = [];
    $rows = [];
    $layers = [];
    foreach ((array) $B['chain'] as $k => $step) {
        $L = qlLayer(['layer' => $step['layer'], 'target' => $step['target']] + $B, $IX);
        if (!qlChk("$id: step " . ($k + 1) . "'s layer is built from the index", $L['err'] === '', $L['err'])) { return; }
        $Ls[] = $L;
        $sm = qlSeam($B, $L, $IX);
        foreach ($sm['rows'] as $r) { $rows[(string) $r['info_key'] . '|' . (string) $r['norm']] = $r; }
        foreach ($sm['layers'] as $ly) { $layers[json_encode($ly['norms']) . (string) $ly['parent_info']] = $ly; }
    }
    $seam = ['rows' => array_values($rows), 'layers' => array_values($layers)];
    $okN = 0; $tot = 0;
    $last = count($Ls) - 1;
    foreach ((array) $B['say'] as $s) {
        foreach ((array) ($B['purses'] ?? [['pg' => (int) (($B['facts'] ?? [])['pg'] ?? 300), 'last' => 'pick']]) as $pu) {
            $tot++;
            $Bp = $B;
            $Bp['facts'] = array_replace((array) ($B['facts'] ?? []), ['pg' => (int) $pu['pg']]);
            qlWorld($seam, (int) (($B['facts'] ?? [])['clicks_ok'] ?? 1), (array) ($B['cfg'] ?? []));
            qlFacts($Bp);
            fxAdvance(3);
            $q = fxDlgSay($npc, null, (string) $s['t']);
            $cid = (string) (($q['turn'] ?? [])['cid'] ?? ('ch' . Ql::$n));
            $sid = 'chain' . Ql::$n;
            $trail = [];
            $good = true;
            foreach ($Ls as $k => $L) {
                fxAdvance($k === 0 ? 5 : 3);
                fxDlgClearQueue();
                QlLog::mark();
                $r = qlTopics($Bp, $L, 1, $cid, ['sid' => $sid, 'gen' => $k + 1, 'layer' => $k === 0 && $L['kind'] === 'root' ? 0 : 1]);
                $o = qlOutcome(array_merge(qlCmds([$r['echo']]), qlCmds(array_map(static fn($x) => (string) ($x['action'] ?? ''), $r['queue']))), QlLog::since());
                $step = (array) $B['chain'][$k];
                $want = $k === $last ? (string) $pu['last'] : 'pick';
                if ($want === 'ask') {
                    $ok = $o['cmd'] === 'none';
                } else {
                    $ok = $o['cmd'] === 'pick' && $o['pos'] === $L['ti'] && (!isset($step['cost']) || (int) $o['cost'] === (int) $step['cost'])
                        && (!isset($step['mode']) || in_array($o['mode'], (array) $step['mode'], true));
                }
                $trail[] = 'step ' . ($k + 1) . ' "' . substr((string) $L['entries'][$L['ti']]['text'], 0, 30) . '": ' . $o['cmd'] . ' pos=' . $o['pos'] . ' mode=' . $o['mode']
                    . ($o['cost'] ? ' cost=' . $o['cost'] : '') . ($ok ? '' : ' (wanted ' . $want . (isset($step['cost']) && $want === 'pick' ? ' cost=' . $step['cost'] : '') . ')');
                if (!$ok) { $good = false; break; }
            }
            if ($good) { $okN++; }
            qlChk("$id (purse " . (int) $pu['pg'] . ' septims): "' . $s['t'] . '" - one sentence, ' . count($Ls) . ' clicks, the last '
                . ((string) $pu['last'] === 'ask' ? 'ASKS first (F29: 25 % of his purse or more)' : 'picked'), $good, implode(' -> ', $trail), $id . '|' . $s['t'] . '|' . (int) $pu['pg']);
        }
    }
    Ql::$table[] = ['quest' => (string) $B['quest'], 'beat' => $id, 'class' => 'chain', 'path' => $okN === $tot ? 'chain' : '-', 'say' => "$okN/$tot", 'never' => '-'];
    $pq = &Ql::$perQuest[(string) $B['quest']];
    $pq = ($pq ?? ['beats' => 0, 'ok' => 0, 'total' => 0, 'nopath' => 0]);
    $pq['beats']++; $pq['ok'] += $okN; $pq['total'] += $tot; if ($okN < $tot) { $pq['nopath']++; }
    unset($pq);
}

/** `none`: the quest has no player prompt at this beat (MQ101's two keep-door rows are scene dialogue). */
function qlNoneBeat(array $B, QlIndex $IX): void
{
    $id = (string) $B['id'];
    $q = (string) $B['quest'];
    $n = count($IX->byQuest[strtolower($q)] ?? []);
    $max = (int) ($B['max_rows'] ?? 0);
    qlChk("$id: $q carries at most $max player row(s) (scene dialogue; the beat lists none)", $n <= $max && empty($B['say']), "rows=$n");
    Ql::$table[] = ['quest' => $q, 'beat' => $id, 'class' => 'none', 'path' => 'none', 'say' => '-', 'never' => '-'];
    $pq = &Ql::$perQuest[$q];
    $pq = ($pq ?? ['beats' => 0, 'ok' => 0, 'total' => 0, 'nopath' => 0]);
    $pq['beats']++;
    unset($pq);
}

// ================================================================== per-quest guards (spec 3.4 step 4, game R4)
/** sj for a beat: an ENGINE session inside a scene whose owning quest has journal rows in the index (capability map U9). */
function qlSceneOf(array $B, QlIndex $IX): bool
{
    $scQuest = (string) ($B['scene'] ?? '');
    return $scQuest !== '' && (string) ($B['origin'] ?? '') === 'engine' && empty($B['amb']) && $IX->hasJournal($scQuest);
}

/** A beat that can carry the per-quest guard pair: a closed or root list (a single advances by itself - S4.6) whose first line
 *  clicks on the FAST path at clicks_ok 1 (pick / explicit; a scene beat by its stage-B class) - or the re-presented half
 *  proves nothing. */
function qlGuardEligible(array $B, QlIndex $IX): bool
{
    if (!empty($B['fe']) || (string) (($B['layer'] ?? [])['kind'] ?? '') === 'single' || empty($B['say'][0])) { return false; }
    $sc = qlSceneOf($B, $IX);
    $cls = $sc ? (string) ($B['stageB'] ?? '') : (string) ($B['expect'] ?? '');
    $v0 = $sc ? (string) ($B['say'][0]['viaB'] ?? $B['say'][0]['via'] ?? '') : (string) ($B['say'][0]['via'] ?? '');
    return in_array($cls, ['pick', 'explicit'], true) && in_array($v0, ['pick', 'explicit'], true) && (string) $B['say'][0]['t'] !== '';
}
/** One beat per quest: the previous utterance re-presented on the next layer clicks nothing; a 300 s-old utterance on an
 *  E-press session clicks nothing. */
function qlGuards(array $B, QlIndex $IX): void
{
    $id = (string) $B['id'];
    $L = qlLayer($B, $IX);
    if ($L['err'] !== '') { return; }
    // a journal-scene beat carries the pair at clicks_ok 1, where it is driven (sj=1 on the wire, as in game)
    $B['_sc'] = qlSceneOf($B, $IX);
    $seam = qlSeam($B, $L, $IX);
    $s = (string) $B['say'][0]['t'];
    // (a) the same sentence, the next layer: nothing
    qlWorld($seam, 1, (array) ($B['cfg'] ?? []));
    qlFacts($B);
    fxAdvance(3);
    $f1 = qlFast($B, $L, $s);
    fxAdvance(2);
    $f2 = qlFast($B, $L, null, ['sid' => 'next' . Ql::$n, 'gen' => 2, 'layer' => 1]);
    qlChk("$id (guard): the sentence that clicked is re-presented on the next layer and clicks nothing (S4.3, F8)",
        $f1['cmd'] === 'pick' && $f2['cmd'] === 'none', 'first ' . $f1['cmd'] . ' pos=' . $f1['pos'] . '; next layer ' . $f2['cmd'] . ' pos=' . $f2['pos'] . ' mode=' . $f2['mode']);
    // (b) a 300 s-old sentence on an E-press session: nothing
    qlWorld($seam, 1, (array) ($B['cfg'] ?? []));
    qlFacts($B);
    fxDlgSay((string) $B['npc'], null, $s);
    fxAdvance(300);
    QlLog::mark();
    fxDlgClearQueue();
    $r = qlTopics($B, $L, 1, 'epress' . Ql::$n, ['origin' => 'engine']);
    $o = qlOutcome(array_merge(qlCmds([$r['echo']]), qlCmds(array_map(static fn($q) => (string) ($q['action'] ?? ''), $r['queue']))), QlLog::since());
    qlChk("$id (guard): a 300 s-old sentence on an E-press session clicks nothing (F7)", $o['cmd'] === 'none', $o['cmd'] . ' pos=' . $o['pos'] . ' mode=' . $o['mode']);
}

// ================================================================== cross-cutting assertions (spec 3.5)
function qlCross(array $fx, QlIndex $IX, array $beats): void
{
    qlOut("\n== cross-cutting assertions (3.5)");
    $x = (array) ($fx['cross'] ?? []);
    // ---- the index hash (a load-order change fails HERE first)
    $hdrHash = (string) ($IX->hdr['hash'] ?? '');
    $st = lrgPromptIndexStatus();   // the seam is set: the header is read from the plugin's configured file
    qlChk('the index header carries a load-order hash', strlen($hdrHash) === 32, $hdrHash);
    // spec 3.2 step 2: the header's `rows` is asserted too (a truncated or half-written index fails here, not beat by beat)
    qlChk('...and its row count (' . (int) ($IX->hdr['rows'] ?? -1) . ') is the number of rows read', (int) ($IX->hdr['rows'] ?? -1) === count($IX->rows),
        'header rows=' . (int) ($IX->hdr['rows'] ?? -1) . ', read ' . count($IX->rows));
    qlChk('...and it equals the hash lrgPromptIndexStatus() reports for the plugin\'s own index file', (string) ($st['hash'] ?? '') === $hdrHash,
        'status=' . (string) ($st['hash'] ?? '') . ' header=' . $hdrHash . ' (copy the deployed data/ next to the staged plugin)');
    qlChk('...and it is the hash the fixture\'s numbers were measured on (re-measure the paraphrases when it moves)',
        (string) ($fx['index_hash'] ?? '') === $hdrHash, 'fixture=' . (string) ($fx['index_hash'] ?? '') . ' index=' . $hdrHash);
    // ---- the stage rail over every standard beat's first line at clicks_ok 0
    $railOk = 0; $railTot = 0;
    foreach ($beats as $B) {
        if (!in_array((string) $B['expect'], ['pick', 'explicit', 'single', 'check', 'pay', 'park', 'scene'], true) || !empty($B['no_rail'])) { continue; }
        $L = qlLayer($B, $IX);
        if ($L['err'] !== '') { continue; }
        $B['_sc'] = false;   // the rail itself, not the scene rule
        $seam = qlSeam($B, $L, $IX);
        $s = (array) ($B['say'][0] ?? []);
        if ((string) ($s['t'] ?? '') === '') { continue; }
        $row = $L['entries'][$L['ti']]['row'];
        qlOpenWorld($B, $L, $seam, 0);
        $sess = (array) (fxDlgStateOf((string) $B['npc'])['session'] ?? []);
        $live = null;
        foreach ((array) ($sess['entries'] ?? []) as $e) { if ((int) $e['pos'] === (int) $L['ti']) { $live = $e; } }
        // model R8 computed HERE from the entry's grading - never by asking the rail (lrgDlgStageRailBlocks) whether it blocks
        $passes = $live !== null && qlR8Passes($live);
        $g = qlLlm($B, $L, (string) $s['t'], (string) $B['id'] . ' rail@0');
        [$sentOk, $sentWhy] = qlRailSentence((array) $g['turn'], (string) $g['volatile']);
        qlWorld($seam, 0, (array) ($B['cfg'] ?? []));
        qlFacts($B);
        $f = qlFast($B, $L, (string) $s['t']);
        $railTot++;
        if ($passes) {
            // a plain indexed line: the rail lets it through - spec 3.5 "a plain indexed beat -> pick": the engine's own words
            // click THAT line on the fast path or, when the fast path leaves it to the model, on the key (never another line)
            // (a first line the fixture itself expects to ask first or to click nothing is held only to "never another line")
            $clicks0 = !in_array((string) ($s['via'] ?? ''), ['park', 'words'], true);
            $good = ($f['cmd'] === 'pick' ? $f['pos'] === $L['ti']
                : (!$clicks0 ? ($g['cmd'] !== 'pick' || $g['pos'] === $L['ti']) : ($f['cmd'] === 'none' && $g['cmd'] === 'pick' && $g['pos'] === $L['ti'])))
                && $sentOk;
            $detail = 'plain indexed: fast ' . $f['cmd'] . ' pos=' . $f['pos'] . ' / gate ' . $g['cmd'] . ' pos=' . $g['pos'] . ' / ' . $sentWhy . ' | ' . qlWhy($g['log']);
        } else {
            $closedWord = (bool) preg_match('/not on the table|nothing like that/i', (string) (preg_match('/^(Until he has asked|I cannot pick any)[^\n]*$/m', $g['volatile'], $mm) ? $mm[0] : ''));
            $good = $f['cmd'] !== 'pick' && $g['cmd'] !== 'pick' && $sentOk && !$closedWord;
            $detail = 'R8 blocks it (' . sprintf('indexed=%d class=%s s%d g%d k=%s cost=%d', (int) ($live['indexed'] ?? 0), (string) ($live['class'] ?? ''),
                (int) ($live['scripted'] ?? 0), (int) ($live['goodbye'] ?? 0), (string) ($live['kind'] ?? ''), (int) ($live['cost'] ?? 0))
                . '): fast ' . $f['cmd'] . ' / gate ' . $g['cmd'] . ' / ' . $sentWhy . ($closedWord ? ' / CLOSED-LIST WORD' : '');
        }
        if ($good) { $railOk++; } else { qlChk('stage rail @clicks_ok 0: ' . $B['id'], false, $detail); }
    }
    qlChk("the stage rail at clicks_ok 0 over every beat's own line (model R8 computed by the harness): what R8 blocks clicks nothing, what it lets"
        . " through is picked on the target, and the ONE rail sentence names exactly the keys that pass - the U7 sentence where none does ($railOk/$railTot)",
        $railOk === $railTot && $railTot > 0, "$railOk/$railTot");
    // ---- a synthetic UNINDEXED line at clicks_ok 0: no pick, the rail line
    $ub = ['id' => 'rail.unindexed', 'quest' => 'X', 'npc' => 'Rail Probe', 'origin' => 'glue', 'facts' => ['clicks_ok' => 0], '_sc' => false];
    $uL = ['kind' => 'root', 'ti' => 0, 'entries' => [['text' => 'Tell me about the old mill by the river bend.', 'norm' => lrgPromptNorm('Tell me about the old mill by the river bend.'), 'row' => ['kind' => '', 'scripted' => 0, 'cost' => 0, 'flags' => []], 'target' => true],
        ['text' => 'Goodbye.', 'norm' => 'goodbye', 'row' => ['kind' => '', 'scripted' => 0, 'cost' => 0, 'flags' => []], 'target' => false]]];
    qlOpenWorld($ub, $uL, ['rows' => [], 'layers' => []], 0);
    $ug = qlLlm($ub, $uL, 'Tell me about the old mill by the river bend.', 'rail.unindexed');
    [$usOk, $usWhy] = qlRailSentence((array) $ug['turn'], (string) $ug['volatile']);
    qlChk('stage rail: an UNINDEXED line at clicks_ok 0 is not clicked, and the true rail sentence is said (nothing here is indexed: U7)', $ug['cmd'] !== 'pick'
        && $usOk && str_contains($ug['volatile'], 'I cannot pick any of these for you yet'), $ug['cmd'] . ' / ' . $usWhy . ' | ' . fx_short($ug['volatile'], 300));
    // ---- LETHAL: guard=1, bounty=200, a DGCrime* layer from the index -> show; the guard report with bounty 0 -> pick
    if (isset($x['lethal'])) {
        $Bx = (array) $x['lethal'] + ['_sc' => false];
        $L = qlLayer($Bx, $IX);
        if (qlChk('LETHAL: the DGCrime layer is built from the index', $L['err'] === '', $L['err'])) {
            $seam = qlSeam($Bx, $L, $IX);
            qlWorld($seam, 1);
            qlFacts($Bx);
            $f = qlFast($Bx, $L, (string) $Bx['say'][0]['t']);
            qlOpenWorld($Bx, $L, $seam, 1);
            $g = qlLlm($Bx, $L, (string) $Bx['say'][0]['t'], 'lethal');
            // F3: only kind=meta makes the game stop driving (StopDriving "lethal"); a show with kind=back would leave the arrest layer driven
            qlChk('LETHAL (guard=1, bounty=200, the DGCrime layer): the gate answers do=show;kind=meta (the menu is his; the game stops driving, F3)',
                $g['cmd'] === 'show' && $g['kind'] === 'meta', $g['cmd'] . ' kind=' . $g['kind'] . ' mode=' . $g['mode']);
            // [pt19c final fixer] spec 3.5 "show on both paths": the fast path answers do=show;kind=meta too (never a silent hand-back)
            qlChk('...and the fast path answers do=show;kind=meta too (spec 3.5: show on both paths; nothing is clicked)', $f['cmd'] === 'show' && $f['kind'] === 'meta', $f['cmd'] . ' kind=' . $f['kind'] . ' pos=' . $f['pos']);
        }
    }
    // ---- a price list (the vanilla carriage layer): exact slot only; a price question clicks nothing
    if (isset($x['price_list'])) {
        $Bx = (array) $x['price_list'] + ['_sc' => false];
        $L = qlLayer($Bx, $IX);
        if (qlChk('price list: the carriage layer is built from the index', $L['err'] === '', $L['err'])) {
            $seam = qlSeam($Bx, $L, $IX);
            foreach ((array) $Bx['say'] as $s) {
                qlWorld($seam, 1);
                qlFacts($Bx);
                $f = qlFast($Bx, $L, (string) $s['t']);
                if ((string) $s['via'] === 'slot') {
                    // the slot path decides (the matcher is bypassed); a scripted-goodbye fare line is a commit, so the slot is his
                    // own plain sentence and the emit reads mode explicit (S4.3 c: >= 2 tokens)
                    qlChk('price list: "' . $s['t'] . '" -> the exact slot (the slot path, the matcher bypassed)', $f['cmd'] === 'pick' && $f['pos'] === $L['ti']
                        && in_array($f['mode'], ['slot', 'explicit'], true) && str_contains($f['log'], 'named exactly (longest wins)'),
                        $f['cmd'] . ' pos=' . $f['pos'] . ' mode=' . $f['mode'] . ' | ' . qlWhy($f['log']));
                } else {
                    qlChk('price list: "' . $s['t'] . '" -> nothing (the similarity matcher is not consulted)', $f['cmd'] === 'none', $f['cmd'] . ' pos=' . $f['pos'] . ' mode=' . $f['mode']);
                }
            }
        }
    }
    // ---- a parked COMMIT single across two turns: her question quoted the oath, then "yes" + a FOREIGN content word. S4.4 says
    // in so many words that this release does NOT test the tail ("her question already named the entry, and a 'yes' with a
    // clause after it is still a yes to it") - unlike S4.5 step 1 on a fresh utterance. So this is a PROBE, never a check:
    // what the build does is printed as a note for the design owner, and nothing here can fail a run (reviewer, pt19c-E)
    foreach ((array) ($x['park_tail'] ?? []) as $pt) {
        $Bx = null;
        foreach ((array) $fx['beats'] as $b) { if ((string) $b['id'] === (string) $pt['beat']) { $Bx = $b; } }
        if ($Bx === null) { qlChk('park tail: beat ' . $pt['beat'] . ' is in the fixture', false); continue; }
        $Bx['_sc'] = false;
        $L = qlLayer($Bx, $IX);
        if ($L['err'] !== '') { qlChk('park tail: ' . $pt['beat'] . ' is built from the index', false, $L['err']); continue; }
        $seam = qlSeam($Bx, $L, $IX);
        qlOpenWorld($Bx, $L, $seam, 1);
        $p1 = qlLlm($Bx, $L, (string) $pt['park'], $pt['beat'] . ' park');
        fxAdvance(4);
        $p2 = qlLlm($Bx, $L, (string) $pt['then'], $pt['beat'] . ' park+tail', 'So be it.');
        qlNote('park tail probe (S4.4 by design, not a check): ' . $pt['beat'] . ' parked on "' . $pt['park'] . '" (' . ($p1['parked'] !== '' ? 'parked' : 'NOT parked')
            . '), then "' . $pt['then'] . '" -> ' . $p2['cmd'] . ($p2['mode'] !== '' ? ' mode=' . $p2['mode'] : '')
            . ' - S4.4 releases a parked entry on a leading assent whatever follows; raise it with the design owner if an oath must refuse a foreign tail');
    }
    // ---- the leave guard: TG00's intro layer -> do=show; Hulda's root list -> do=leave;pos=-1
    foreach ((array) ($x['leave'] ?? []) as $lv) {
        $Bx = (array) $lv + ['_sc' => false];
        $L = qlLayer($Bx, $IX);
        if (!qlChk('leave guard: ' . $Bx['id'] . ' is built from the index', $L['err'] === '', $L['err'])) { continue; }
        $seam = qlSeam($Bx, $L, $IX);
        qlOpenWorld($Bx, $L, $seam, 1);
        QlLog::mark();
        $q = fxDlgSay((string) $Bx['npc'], null, (string) ($Bx['utter'] ?? 'I have to go.'));
        $will = lrgDlgWillEmit((array) $q['turn'], 'LEAVE');
        $out = fxDlgLlm((string) $Bx['npc'], 'LEAVE', 'Farewell.');
        $c = qlCmds($out);
        $o = qlOutcome($c, QlLog::since());
        Ql::$twin[] = ['leave ' . $Bx['id'], $will, $o['cmd'] === 'leave' && $o['pos'] >= 0];
        if ((string) $Bx['expect'] === 'show') {
            // F3: kind=back is the one show the game answers with NO state change (it keeps driving the session)
            qlChk('leave guard: LEAVE on ' . $Bx['id'] . ' -> do=show;kind=back (no back line; a walk-out is listed; the game keeps driving, F3), and her line says leaving is his',
                $o['cmd'] === 'show' && $o['kind'] === 'back' && str_contains((string) $q['volatile'], 'No line here backs out cleanly'),
                $o['cmd'] . ' kind=' . $o['kind'] . ' pos=' . $o['pos'] . ' | ' . fx_short((string) $q['volatile'], 200));
        } else {
            qlChk('leave guard: LEAVE on ' . $Bx['id'] . ' -> do=leave;pos=-1 (the engine cancel)', $o['cmd'] === 'leave' && $o['pos'] === -1, $o['cmd'] . ' pos=' . $o['pos']);
        }
    }
}

// ================================================================== the generic single-entry sweep (game S2)
/** Every index layer the harness can build with exactly ONE visible line whose quest matches the sweep globs: the verbatim
 *  line releases through lrgDlgSingleEntryRelease and "uh what now" does not. Test time only (MQ201-MQ305 get no other
 *  offline coverage before v1.0.1). Singles are the parent rows' links resolved to one visible line. */
function qlSweep(array $fx, QlIndex $IX): void
{
    $sw = (array) (($fx['cross'] ?? [])['sweep'] ?? []);
    $re = (string) ($sw['quest_re'] ?? '/^(MQ|C0|TG|DB|MG|CW|MS)/');
    $neg = (string) ($sw['negative'] ?? 'uh what now');
    $n = 0; $bad = []; $negBad = []; $seen = []; $negSkip = 0;
    foreach ($IX->rows as $prow) {
        if (!$prow['links'] || !preg_match($re, (string) $prow['quest'])) { continue; }
        $vis = [];
        foreach ((array) $prow['links'] as $tk) {
            foreach ($IX->byTk[(string) $tk] ?? [] as $i) {
                $r = $IX->rows[$i];
                if ((string) $r['norm'] === '' || !empty(($r['flags'] ?? [])['placeholder'])) { continue; }
                $vis[(string) $tk] = $r;
                break;
            }
        }
        if (count($vis) !== 1) { continue; }
        $r = array_values($vis)[0];
        if (!preg_match($re, (string) $r['quest']) || isset($seen[(string) $r['info_key']])) { continue; }
        $seen[(string) $r['info_key']] = 1;
        $commit = (int) $r['scripted'] === 1 && (int) (($r['flags'] ?? [])['goodbye'] ?? 0) === 1;
        $e = ['text' => (string) $r['txt'], 'norm' => (string) $r['norm'], 'scripted' => (int) $r['scripted'], 'commit' => $commit ? 1 : 0,
            'class' => $commit ? 'commit' : 'plain', 'indexed' => 1, 'kind' => (string) $r['kind'], 'cost' => (int) $r['cost']];
        $txt = trim((string) preg_replace('/\s*[\(\[][^)\]]*[\)\]]\s*$/', '', (string) $r['txt']));   // the engine's own words, no stage direction
        if ($txt === '' || strpos($txt, '<') !== false) { continue; }
        $n++;
        $rel = lrgDlgSingleEntryRelease($e, $txt);
        if (empty($rel['release'])) { $bad[] = $r['info_key'] . ' "' . substr($txt, 0, 50) . '" (' . $rel['step'] . ')'; }
        // the negative is a filler question; a line it PARAPHRASES ("So what now?": every strict word of it is the line's)
        // is released by it rightly and is not counted
        $fill = ['uh', 'um', 'er', 'erm', 'so', 'well', 'oh'];
        $negT = array_values(array_diff(lrgDlgTokens(lrgPromptNorm($neg)), $fill));
        if ($negT && !array_diff($negT, lrgDlgTokens((string) $r['norm']))) { $negSkip++; continue; }
        $rn = lrgDlgSingleEntryRelease($e, $neg);
        if (!empty($rn['release'])) { $negBad[] = $r['info_key'] . ' "' . substr($txt, 0, 50) . '" (' . $rn['step'] . ')'; }
    }
    qlChk("single-entry sweep: every one of the $n single lines of MQ*/C0*/TG*/DB*/MG*/CW*/MS* releases when said verbatim", $n > 100 && !$bad,
        count($bad) . ' do not: ' . implode('; ', array_slice($bad, 0, 8)));
    qlChk("...and \"$neg\" releases none of them (" . ($n - $negSkip) . " lines; $negSkip it paraphrases are left out)", $n > 100 && !$negBad,
        count($negBad) . ' do: ' . implode('; ', array_slice($negBad, 0, 8)));
}

// ================================================================== --remeasure: a fixture proposal from this run's measurements
/**
 * Writes a copy of the fixture with the re-classings spec 3.4 step 5 allows, and ONLY those: a FAILING paraphrase whose measured
 * outcome is `park` (she asks, his assent releases it) or `words` (nothing is clicked) is re-classed to it; the old `via` is kept
 * as `spec_via` (first re-class only) and the real matcher's number goes into `note`. Everything else is printed, never moved:
 *  - a paraphrase on a target the fail-safe merge grades above its own row (Ql::$overBeat): its path moved because of ANOTHER
 *    INFO's risk, not a floor - a `known` entry for the merge's owner (the harness prints the scoped-merge result beside it);
 *  - a click on another line, a park no assent releases, a click where only words were due, a false kind: defects for `known`;
 *  - a click on the TARGET under another label (explicit / single / check / pay / pick / auto): the spec table and the build
 *    disagree about the line's class - a human reads the index row and decides (it is no floor miss).
 * A beat's class is never moved by this tool. Floors are never touched.
 */
function qlRemeasure(string $fixture, string $out, string $hash): void
{
    $fx = json_decode((string) file_get_contents($fixture), true);
    $ids = [];
    foreach ((array) $fx['beats'] as $bi => $B) { $ids[(string) $B['id']] = $bi; }
    $n = 0; $unsafe = []; $merge = []; $label = []; $known = [];
    foreach (Ql::$measured as $m) {
        $got = (string) $m['got'];
        $bi = $ids[$m['beat']] ?? null;
        if ($bi === null || (string) ($fx['beats'][$bi]['say'][$m['i']]['t'] ?? null) !== $m['t']) { continue; }
        $line = $m['beat'] . ' "' . $m['t'] . '": wanted ' . $m['want'] . ', got ' . $got . ' - ' . fx_short((string) $m['detail'], 200);
        if (!empty($m['known'])) { $known[] = $line; continue; }
        if (!empty($m['over'])) { $merge[] = $line; continue; }
        if (str_starts_with($got, 'WRONG') || $got === 'park-stuck' || str_contains($got, 'kind-lie') || $got === 'no-open'
            || ($m['want'] === 'words' && !in_array($got, ['park', 'words'], true))) {
            $unsafe[] = $line;
            continue;
        }
        if (!in_array($got, ['park', 'words'], true)) { $label[] = $line; continue; }
        $say = &$fx['beats'][$bi]['say'][$m['i']];
        if (!isset($say['spec_via'])) { $say['spec_via'] = $say['via']; }
        $say['via'] = $got;
        $say['note'] = 'measured (index ' . substr($hash, 0, 8) . '): ' . fx_short((string) $m['detail'], 240);
        unset($say);
        $n++;
    }
    file_put_contents($out, json_encode($fx, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    echo "\n--remeasure: $n paraphrase(s) re-classed to park / words into $out (spec 3.4 step 5)\n";
    $print = static function (string $head, array $xs): void {
        if (!$xs) { return; }
        echo "  $head (" . count($xs) . "):\n";
        foreach ($xs as $x) { echo "    $x\n"; }
    };
    $print('NOT moved - already a `known` defect (its owner fixes it; a known line is never re-classed away)', $known);
    $print('NOT moved - the fail-safe merge over-grades the target (a `known` entry for the merge\'s owner)', $merge);
    $print('NOT moved - defects (list them in `known` with their owner)', $unsafe);
    $print('NOT moved - a click on the target under another label (the spec table vs the build: a human decides)', $label);
}

// ================================================================== [pt19h-harness] --extended
if ($EXTENDED) {
    // the extended coverage: its own report, then the run's verdict (never / not_target rows + the twin)
    qlChk('the extended fixture was measured on this index (' . (string) ($fx['index_hash'] ?? '?') . ')', (string) ($fx['index_hash'] ?? '') === (string) ($IX->hdr['hash'] ?? ''),
        'fixture=' . (string) ($fx['index_hash'] ?? '') . ' index=' . (string) ($IX->hdr['hash'] ?? ''));
    // [pt19h-harness r2] an alias the fixture gives no live text for is rendered neutrally, never as an invented name (qlShown)
    $GLOBALS['QL_ALIAS_NEUTRAL'] = true;
    qlxRun($fx, $IX, ['quiet' => $QUIET, 'beat' => $ONLY_BEAT, 'group' => is_string($A['group'] ?? null) ? (string) $A['group'] : '',
        'shard' => is_string($A['shard'] ?? null) ? (string) $A['shard'] : '0/1', 'ext-out' => is_string($A['ext-out'] ?? null) ? (string) $A['ext-out'] : '',
        'baseline' => $EXT_BASELINE, 'baseline-out' => is_string($A['ext-baseline-out'] ?? null) ? (string) $A['ext-baseline-out'] : '',
        'baseline-label' => $BASELINE_LABEL, 'fixture' => $FIXTURE, 'index_hash' => (string) ($IX->hdr['hash'] ?? '')]);
    $badTwin = array_values(array_filter(Ql::$twin, static fn($x) => $x[1] !== $x[2]));
    qlChk('lrgDlgWillEmit === (a pick was emitted) over every gate decision of this run (' . count(Ql::$twin) . ')', !$badTwin,
        count($badTwin) . ' disagree: ' . implode('; ', array_map(static fn($x) => $x[0] . ' will=' . ($x[1] ? 1 : 0) . ' emitted=' . ($x[2] ? 1 : 0), array_slice($badTwin, 0, 6))));
    if (Fx::$phpIssues) {
        echo "\nPHP warnings / notices raised during the run (each is a latent bug on the live server):\n";
        foreach (Fx::$phpIssues as $k => $n) { echo "  {$n}x  $k\n"; }
    }
    if (Ql::$fails && $QUIET) { echo "\nfailures (a `never` / `not_target` row that clicks on the fast path, or a verbatim line worse than on the baseline code):\n" . implode("\n", Ql::$fails) . "\n"; }
    printf("\n%d passed, %d failed  (%.1fs)\n", Ql::$ok, Ql::$fail, microtime(true) - $T0);
    echo Ql::$fail === 0 ? "ALL CHECKS PASSED\n" : "RESULT: FAILED\n";
    exit(Ql::$fail === 0 ? 0 : 1);
}

// ================================================================== run
$beats = [];
$advBeats = [];
foreach (is_array($fxAdv) ? (array) $fxAdv['beats'] : [] as $B) {
    if ($FE_ONLY !== !empty($B['fe'])) { continue; }
    if ($ONLY_QUEST !== '' && strcasecmp((string) $B['quest'], $ONLY_QUEST) !== 0) { continue; }
    if ($ONLY_BEAT !== '' && (string) $B['id'] !== $ONLY_BEAT) { continue; }
    if ($STAGE_B && empty($B['reward'])) { continue; }
    $advBeats[] = $B + ['_adv' => 1];
}
foreach ((array) $fx['beats'] as $B) {
    if ($FE_ONLY !== !empty($B['fe'])) { continue; }
    if ($ONLY_QUEST !== '' && strcasecmp((string) $B['quest'], $ONLY_QUEST) !== 0) { continue; }
    if ($ONLY_BEAT !== '' && (string) $B['id'] !== $ONLY_BEAT) { continue; }
    if ($STAGE_B && empty($B['reward'])) { continue; }
    $beats[] = $B;
}
if ($STAGE_B && !$beats) {
    qlOut('stage B: no reward beat in the fixture - the S6.2 bonus beats ride with gate B (checks.reward.enabled); nothing to evaluate yet');
}
if (!$TABLE_ONLY) { qlOut($FE_ONLY ? "\n== the first evening (3.7)" : "\n== the beats (3.6)"); }
$guardDone = [];
$TP = ['beats' => microtime(true)];
$advFrom = -1;
foreach (array_merge($beats, $advBeats) as $B) {
    if (!empty($B['_adv']) && $advFrom < 0) {
        $advFrom = count(Ql::$table);
        if (!$TABLE_ONLY) { qlOut("\n== the adversarial companion (" . basename($COMPANION) . ')'); }
    }
    if (!$QUIET) { qlOut("\n[" . (!empty($B['_adv']) ? 'adv:' : '') . $B['id'] . '] ' . ($B['npc'] ?? '') . ' / ' . ($B['expect'] ?? '')); }
    $cls = (string) ($B['expect'] ?? '');
    if ($cls === 'none') { qlNoneBeat($B, $IX); continue; }
    if ($cls === 'open' && empty($B['target'])) { qlOpenBeat($B, $IX); continue; }
    if (in_array($cls, ['entry', 'faction-words', 'faction-open'], true)) { qlFactionBeat($B, $IX); continue; }
    if ($cls === 'chain') { qlChainBeat($B, $IX); continue; }
    qlBeat($B, $IX);
    $q = (string) $B['quest'];
    // (the per-quest guard pair is the main fixture's: it asserts the quest's structure, not a phrasing)
    if (empty($B['_adv']) && !isset($guardDone[$q]) && qlGuardEligible($B, $IX)) {
        $guardDone[$q] = 1;
        qlGuards($B, $IX);
    }
}
$TP['beats'] = microtime(true) - $TP['beats'];
$fullRun = !$FE_ONLY && $ONLY_QUEST === '' && $ONLY_BEAT === '' && !$STAGE_B;
if (!$FE_ONLY && $ONLY_BEAT === '' && !$STAGE_B) {
    $allStd = array_values(array_filter((array) $fx['beats'], static fn($b) => empty($b['fe'])));
    if ($ONLY_QUEST !== '') { $allStd = array_values(array_filter($allStd, static fn($b) => strcasecmp((string) $b['quest'], $ONLY_QUEST) === 0)); }
    $t2 = microtime(true);
    qlCross($fx, $IX, $allStd);
    $TP['cross'] = microtime(true) - $t2;
    // quests with no beat that clicked a pick-class line got no guard beat: say so (every quest with one must have its guards)
    foreach (array_unique(array_map(static fn($b) => (string) $b['quest'], $allStd)) as $q) {
        $has = (bool) array_filter($allStd, static fn($b) => (string) $b['quest'] === $q && qlGuardEligible($b, $IX));
        if ($has && !isset($guardDone[$q])) { qlChk("$q: one beat carries the two per-quest guards (3.4 step 4)", false, 'no pick-class beat whose first line clicks'); }
        if (!$has && $ONLY_QUEST === '') { qlNote("$q: no beat whose first line clicks on the fast path - no per-quest guard pair (3.4 step 4)"); }
    }
}
if ($fullRun) { $t2 = microtime(true); qlOut("\n== the generic single-entry sweep (game S2)"); qlSweep($fx, $IX); $TP['sweep'] = microtime(true) - $t2; }
// ---- the twin never disagrees with the gate
$bad = array_values(array_filter(Ql::$twin, static fn($x) => $x[1] !== $x[2]));
if (Ql::$twin) {
    qlChk('lrgDlgWillEmit === (a pick was emitted) over every gate decision of this run (' . count(Ql::$twin) . ')', !$bad,
        count($bad) . ' disagree: ' . implode('; ', array_map(static fn($x) => $x[0] . ' will=' . ($x[1] ? 1 : 0) . ' emitted=' . ($x[2] ? 1 : 0), array_slice($bad, 0, 6))));
}

// ================================================================== the table
echo "\n" . str_pad('quest', 7) . ' | ' . str_pad('beat', 30) . ' | ' . str_pad('class', 16) . ' | ' . str_pad('path', 22) . ' | say ok/total | never ok/total' . "\n";
echo str_repeat('-', 118) . "\n";
foreach (Ql::$table as $ti => $r) {
    if ($advFrom >= 0 && $ti >= $advFrom) { $r['beat'] = 'adv:' . $r['beat']; }
    printf("%-7s | %-30s | %-16s | %-22s | %-12s | %s\n", $r['quest'], $r['beat'], $r['class'], $r['path'], $r['say'], $r['never']);
}
echo "\n";
foreach (Ql::$perQuest as $q => $p) {
    printf("%-7s beats %2d, %s, paraphrases %d/%d\n", $q, $p['beats'], $p['nopath'] ? $p['nopath'] . ' beat(s) with a missing path' : 'every path present', $p['ok'], $p['total']);
}
if ($WORDS && Ql::$words) {
    echo "\n--words (each paraphrase as the model's free-text item; the LLM path feeds the T-key, this is its reach without one):\n";
    $cnt = array_count_values(array_column(Ql::$words, 2));
    ksort($cnt);
    foreach (Ql::$words as $w) { if (!$TABLE_ONLY) { printf("  %-9s %-28s %s\n", $w[2], $w[0], $w[1]); } }
    echo '  totals: ' . implode(', ', array_map(static fn($k, $v) => "$k $v", array_keys($cnt), $cnt)) . "\n";
}
// [pt19h-harness r2] --words against the baseline (a verbatim or first-evening line getting worse fails), and --words-out (a new baseline;
// written into an existing words baseline it MERGES, so the default run and the --first-evening run can be recorded one after the other)
if ($WORDS && Ql::$words && $WORDS_OUT !== '') {
    $wl = [];
    $old = qlBaselineRead($WORDS_OUT, 'words');
    foreach ($old ? (array) $old['lines'] : [] as $k => $v) { $wl[(string) $k] = $v; }
    foreach (Ql::$words as $w) { if (count($w) >= 11) { $wl[qlWordsKey($w)] = (string) $w[2]; } }
    qlBaselineWrite($WORDS_OUT, 'words', $wl, ['measured' => ['code' => $BASELINE_LABEL, 'at' => date('Y-m-d H:i T'), 'index_hash' => (string) ($IX->hdr['hash'] ?? ''),
        'fixture_md5' => (string) @md5_file($FIXTURE), 'companion_md5' => is_array($fxAdv) ? (string) @md5_file($COMPANION) : '',
        'runs' => array_values(array_unique(array_merge((array) (($old['measured'] ?? [])['runs'] ?? []), [$FE_ONLY ? '--first-evening --words' : '--words'])))],
        'about' => 'tools/test_questline.php --words: each say / never line\'s words outcome (resolve / breath / ask / show / nothing / WRONG), keyed '
            . 'main|adv, beat, say|never, the sentence, clicks_ok. A later --words run lists every say line whose outcome got worse and fails a verbatim or '
            . 'first-evening one (research/pt19h-harness.md, round 2). To accept a change: re-baseline with --words-out, or add an `accepted` entry '
            . '{"<key>": {"now": "<outcome>", "why": "<the ruling>"}}',
        'accepted' => (object) (array) ($old['accepted'] ?? [])]);   // a merge keeps the rulings already accepted
}
if ($WORDS && Ql::$words && $WORDS_BASELINE !== '') {
    $wb = qlBaselineRead($WORDS_BASELINE, 'words');
    if ($wb === null) { echo "\n--words: no baseline at $WORDS_BASELINE (nothing compared)\n"; }
    else { qlWordsBaseline($wb, $WORDS_BASELINE); }
}
if (Ql::$liveTextIgnored) {
    echo "\nlive_text not shown (the target row carries no token the engine fills - a fixture copy slip; the row's own text was shown):\n";
    foreach (Ql::$liveTextIgnored as $bid => $lt) { echo "  $bid: \"$lt\"\n"; }
}
if (Fx::$phpIssues) {
    echo "\nPHP warnings / notices raised during the run (each is a latent bug on the live server):\n";
    foreach (Fx::$phpIssues as $k => $n) { echo "  {$n}x  $k\n"; }
}
if (FxDb::$unhandledAll) {
    echo "\nDatabase calls outside PROTOCOL section 5 (the fake could not serve them):\n";
    foreach (array_count_values(array_map(static fn($q) => fx_short((string) $q, 160), FxDb::$unhandledAll)) as $q => $n) { echo "  {$n}x  $q\n"; }
}
if (Ql::$overclaim) {
    echo "\nthe fail-safe merge (lrgPromptEscalate) grades " . count(Ql::$overclaim) . " target line(s) above their own index row - another INFO of"
        . " the load order with the same prompt text lends its risk (it moves paths: a plain line asks first, a continuation stops advancing):\n";
    foreach (Ql::$overclaim as $o) { echo "  $o\n"; }
}
foreach (Ql::$notes as $n) { echo "note: $n\n"; }
if (Ql::$knownLines) {
    echo "\nKNOWN defects (each owned by the lane named; --allow-known did not count them - the default run, the release gate, fails them):\n" . implode("\n", Ql::$knownLines) . "\n";
}
if ($fullRun || $FE_ONLY) {
    foreach (array_keys(Ql::$known) as $k) {
        if (!isset(Ql::$knownSeen[$k]) && ($FE_ONLY === str_starts_with((string) $k, 'FE.'))) { echo "note: known entry \"$k\" matched no check this run - stale, remove it\n"; }
    }
}
if (Ql::$fails && $QUIET) { echo "\nfailures:\n" . implode("\n", Ql::$fails) . "\n"; }
if ($REMEASURE !== '') { qlRemeasure($FIXTURE, $REMEASURE, (string) ($IX->hdr['hash'] ?? '')); }
if ($DUMP !== '') { @file_put_contents($DUMP, json_encode(Ql::$dump, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)); }
printf("\n%d passed, %d failed, %d known  (%.1fs: %s)\n", Ql::$ok, Ql::$fail, Ql::$knownHit, microtime(true) - $T0,
    implode(', ', array_map(static fn($k, $v) => sprintf('%s %.1fs', $k, $v), array_keys($TP), $TP)));
echo Ql::$fail === 0 ? (Ql::$knownHit ? "NOT A RELEASE GREEN (--allow-known): " . Ql::$knownHit . " known defect(s) listed above were not counted\n" : "ALL CHECKS PASSED\n") : "RESULT: FAILED\n";
exit(Ql::$fail === 0 ? 0 : 1);
