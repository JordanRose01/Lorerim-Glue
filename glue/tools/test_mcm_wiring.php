<?php
/**
 * LoreRim Glue - EVERY MCM CONTROL IS REALLY READ SOMEWHERE (plan 9.3 / E4(c)).
 *
 * The scar this exists for: seven MCM controls shipped in 0.4.0 doing nothing at all, because they had
 * no reader - the game read them and never sent them, or the server used its own default and never
 * looked. `fOutroHold`, `fConvHoldFar` and `iQuestLines` were all found the same way, by accident,
 * after the owner had already played with them. And because a MISSING settings.ini key reads as
 * 0 / FALSE in this project, a control with no ini line ships silently switched OFF.
 *
 * Run (no database, no game, no server):
 *     php tools/test_mcm_wiring.php
 *     php tools/test_mcm_wiring.php --quiet      only the failures and the summary
 *     php tools/test_mcm_wiring.php --lane-d-only
 *         [pt19 v1.0 staging] Lane D (probe + MCM) lands BEFORE Lane C (the driver). With this flag a
 *         check whose only evidence is a reader in ANOTHER lane's .psc (LRG_Dialogue / LRG_Main /
 *         LRG_Profile: a retired id still read there, a new id not read yet, the retired dry-run
 *         auto-clear) prints "[pending C]" instead of failing. Without it (the release gate) every
 *         such line is a FAIL. Lane D's own files are strict either way. [pt19 fix 2] Section 11 also
 *         reads the server's string literals; a finding there prints "[pending B]" (or A) the same way.
 * Exit 0 = every id has an ini line and a reader, and every retired id is gone.
 *
 * It only READS lane B's files. Three ways an id can be satisfied, and the report says which:
 *   a  the exact "key:Section" string appears in a .psc under game/LoreRimGlue/Source/Scripts/
 *   b  the id is in SERVER_KNOBS below AND that wire key is written by a .psc and read by a .php
 *   c  the id is in GAME_ONLY below, with a one-line reason
 */

error_reporting(E_ALL);
ini_set('display_errors', 'stderr');

$root = dirname(__DIR__);
$args = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $a, $m)) { $args[$m[1]] = $m[2] ?? true; }
}
$quiet = !empty($args['quiet']);

$cfgFile = $root . '/game/LoreRimGlue/MCM/Config/LoreRimGlue/config.json';
$iniFile = $root . '/game/LoreRimGlue/MCM/Config/LoreRimGlue/settings.ini';
$scriptDir = $root . '/game/LoreRimGlue/Source/Scripts';
$serverDir = $root . '/server/lorerim_glue';

/**
 * An MCM id whose VALUE travels to the server on a wire key instead of being read by a Papyrus script.
 * Both halves are asserted: a .psc must WRITE the key and a .php must READ it.
 */
$SERVER_KNOBS = [
    'bFreeChecks:Checks' => 'chk',
    'bCheckHostility:Checks' => 'chk',
    'iCheckBias:Checks' => 'bias',
    'iQuestLines:Quests' => 'ql',
    'bIntentOpen:Dialogue' => 'io',
    // [pt19 v1.0] iBranchInput (bi), bRewalk (rw) and iRewalkDepth (rwd) are retired (spec S10 / section 4)
    'bPaidIntimacy:Intimacy' => 'paidok',
    'fPriceMultiplier:Intimacy' => 'pm',
    // [0.5.0] the four this round adds. [pt19 v1.0 fix 1] bQuestInitiative (qi) and iQuestInitiativeGap (qig)
    // are retired with the server's quest-initiative block (spec section 4 / S9: lrgDlgInitiativeCandidate,
    // <she_may_raise>, quests.initiative.*) - a control that nothing acts on is a dead switch. Both are in
    // $REMOVED (section 10), which also asserts no script writes their wire keys any more.
    'bServiceDialogue:Services' => 'sv',
    'bLockedFacts:Truth' => 'lf',
    // [0.5.0 fix pass / S-3] The seven that were dead. LRG_Profile.psc was already writing qig / qx /
    // svx / tg and no .php read any of them, so the controls changed nothing and the server quietly kept
    // its own config defaults - the E4(c) scar this file exists to prevent, shipped again.
    // bTruthGate used to be mapped to 'lf' here ("the same carrier"), which was the bug: the gate really
    // was consulted through bLockedFacts, so bLockedFacts OFF disabled the truth gate and bTruthGate did
    // nothing. They are two controls on two carriers now.
    'bQuestSummary:Quests' => 'qx',       // qx byte 0
    'bQuestNext:Quests' => 'qx',          // qx byte 1
    'bServiceShortcut:Services' => 'svx', // svx byte 0
    'bCarriageByName:Services' => 'svx',  // svx byte 1
    'bNamePrices:Services' => 'svx',      // svx byte 2
    'bTruthGate:Truth' => 'tg',
    // [0.5.0 release pass / S-2] bMenuless is read by LRG_Dialogue too, so path (a) alone used to
    // satisfy it - and that hid the real defect: the server had no way to know whether the driver
    // could run a hidden session, so it hid CHIM's own RentRoom / HireCarriage / Training /
    // OpenInventory shortcuts on every turn while the game refused CmdSelectTopic. Asserting the
    // wire half here is what keeps `ml` alive.
    'bMenuless:Dialogue' => 'ml',
    // [0.5.1 / owner addendum 10] The two follower controls whose VALUE has to reach the server.
    // bFollowerAware carries the whole fol= block (and witchim): with it off the game says nothing
    // about followers at all and the server reads an absent key as "say nothing". bFollowerVerbsReal
    // is the server-side routing decision, so it needs its own carrier for exactly the reason sv / lf
    // / ml do - the choice is made on ordinary CHIM turns where no dialogue message is ever sent.
    // The other three (bFollowerRepair, iFollowerRepairMode, bFollowerHoldSkip) are GAME behaviour
    // and are satisfied by path (a).
    'bFollowerAware:Followers' => 'fol',
    'bFollowerVerbsReal:Followers' => 'fv',
];

/** An id that is deliberately game-side only, with the reason it is not wired to anything else. */
$GAME_ONLY = [
    // [pt19 v1.0, spec S10] an ini line with NO MCM control on purpose; section 3 asserts its ini line and its reader
    'bDriveSceneMenus:Dialogue' => 'ini line only, no MCM control (spec S10): drives an engine-opened journal-scene menu once the route is proven by a real click; the server kill switch is dialogue.session.drive_scene',
];

$ok = 0;
$fail = 0;
$rows = [];
function bad(string $msg): void { global $fail; $fail++; echo "  [FAIL] $msg\n"; }
function good(string $msg): void { global $ok, $quiet; $ok++; if (!$quiet) { echo "  [ok] $msg\n"; } }
/** [pt19 v1.0] Lane D's own scripts: strict in every mode. Evidence found only elsewhere may be "[pending C]" (or the
 * lane that owns the file: fix 2, "[pending B]" / "[pending A]" for a server string). */
$D_FILES = ['LRG_DlgProbe.psc', 'LRG_DlgUI.psc', 'LRG_MCM.psc'];
$laneDOnly = !empty($args['lane-d-only']);
$pendingN = 0;
function pending(string $msg, string $lane = 'C'): void
{
    global $laneDOnly, $pendingN;
    if ($laneDOnly) { $pendingN++; echo "  [pending $lane] $msg\n"; } else { bad($msg); }
}

// ------------------------------------------------------------------ load the three sources
if (!is_file($cfgFile)) { fwrite(STDERR, "no config.json at $cfgFile\n"); exit(2); }
if (!is_file($iniFile)) { fwrite(STDERR, "no settings.ini at $iniFile\n"); exit(2); }
$cfgRaw = (string) file_get_contents($cfgFile);
// MCM Helper writes (and tolerates) a UTF-8 BOM; json_decode() does not.
if (strncmp($cfgRaw, "\xEF\xBB\xBF", 3) === 0) { $cfgRaw = substr($cfgRaw, 3); }
$cfg = json_decode($cfgRaw, true);
if (!is_array($cfg)) { fwrite(STDERR, 'config.json is not valid JSON: ' . json_last_error_msg() . "\n"); exit(2); }

/** Every "key:Section" id in the file, in order, with the page it sits on. */
$ids = [];
$walk = function ($node, string $page = '') use (&$walk, &$ids) {
    if (!is_array($node)) { return; }
    if (isset($node['pageDisplayName']) && is_string($node['pageDisplayName'])) { $page = $node['pageDisplayName']; }
    if (isset($node['id']) && is_string($node['id']) && strpos($node['id'], ':') !== false) {
        $ids[$node['id']] = ['page' => $page, 'type' => (string) ($node['type'] ?? '?'),
            'text' => (string) ($node['text'] ?? ''), 'help' => (string) ($node['help'] ?? '')];
    }
    foreach ($node as $v) { if (is_array($v)) { $walk($v, $page); } }
};
$walk($cfg);

/** settings.ini as [Section][key] => raw value. */
$ini = [];
$section = '';
foreach (preg_split('/\R/', (string) file_get_contents($iniFile)) as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === ';' || $line[0] === '#') { continue; }
    if (preg_match('/^\[(.+)\]$/', $line, $m)) { $section = trim($m[1]); continue; }
    if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$/', $line, $m)) { $ini[$section][$m[1]] = trim($m[2]); }
}

/**
 * [0.5.0 fix pass / S-4] Papyrus source with its COMMENTS REMOVED.
 *
 * The gate could be defeated by a bare mention: path (a) below ends in a substring search over every
 * .psc concatenated, comments included, so writing `; bFoo:Bar is handled elsewhere` in a comment was
 * enough to make a genuinely dead control pass. Mutation-tested three ways against a staged copy: a dead
 * control fails (correct), the same dead control with its id in a ';' comment PASSED (the bug), and a
 * live control that loses its ini line fails (correct).
 *
 * Quotes have to be honoured, because the wire keys themselves live inside string literals that START
 * with a semicolon - `s += ";qig=" + qig` - and a naive strip-to-end-of-line would delete the very
 * evidence path (b) looks for. Papyrus has no escape sequence inside a string literal, so a plain quote
 * toggle is exact. `;/ ... /;` block comments are removed first.
 */
function lrgStripPapyrusComments(string $src): string
{
    $out = '';
    $n = strlen($src);
    $inStr = false;
    for ($i = 0; $i < $n; $i++) {
        $c = $src[$i];
        if ($inStr) {
            $out .= $c;
            if ($c === '"') { $inStr = false; }
            elseif ($c === "\n") { $inStr = false; }      // an unterminated literal never spans a line
            continue;
        }
        if ($c === '"') { $inStr = true; $out .= $c; continue; }
        if ($c === ';') {
            if ($i + 1 < $n && $src[$i + 1] === '/') {     // ;/ block comment /;
                $end = strpos($src, '/;', $i + 2);
                if ($end === false) { break; }
                // keep the newlines so line-oriented reading of the result still lines up
                $out .= str_repeat("\n", substr_count(substr($src, $i, $end + 2 - $i), "\n"));
                $i = $end + 1;
                continue;
            }
            $end = strpos($src, "\n", $i);                 // ; line comment
            if ($end === false) { break; }
            $out .= "\n";
            $i = $end;
            continue;
        }
        $out .= $c;
    }
    return $out;
}

$psc = '';
$pscByFile = [];   // [pt19 v1.0] basename => comment-stripped source, so a finding can name its file (and its lane)
foreach (glob($scriptDir . '/*.psc') ?: [] as $f) {
    $pscByFile[basename($f)] = lrgStripPapyrusComments((string) file_get_contents($f));
    $psc .= "\n" . $pscByFile[basename($f)];
}
/** The scripts whose comment-stripped source matches $re, split into [Lane D's files, everybody else's]. */
function pscWhere(string $re): array
{
    global $pscByFile, $D_FILES;
    $inD = [];
    $other = [];
    foreach ($pscByFile as $bn => $src) {
        if (preg_match($re, $src) === 1) {
            if (in_array($bn, $D_FILES, true)) { $inD[] = $bn; } else { $other[] = $bn; }
        }
    }
    return [$inD, $other];
}
/** The body of one Papyrus function (header to EndFunction) out of a comment-stripped source, '' if absent. */
function pscBody(string $src, string $name): string
{
    return preg_match('/^[ \t]*(?:\w+(?:\[\])?[ \t]+)?Function[ \t]+' . preg_quote($name, '/') . '[ \t]*\((.*?)^[ \t]*EndFunction/ms', $src, $m) === 1 ? $m[1] : '';
}
$php = '';
foreach (array_merge(glob($serverDir . '/*.php') ?: [], glob($serverDir . '/lib/*.php') ?: []) as $f) {
    $php .= "\n" . (string) file_get_contents($f);
}
if (trim($psc) === '') { fwrite(STDERR, "no .psc files under $scriptDir\n"); exit(2); }
if (trim($php) === '') { fwrite(STDERR, "no .php files under $serverDir\n"); exit(2); }

printf("MCM wiring: %d controls in config.json, %d ini sections, %d psc bytes, %d php bytes\n",
    count($ids), count($ini), strlen($psc), strlen($php));

// ------------------------------------------------------------------ 1. every id has a settings.ini line
echo "\n1. every control has a settings.ini line (a MISSING key reads as 0/FALSE in this project)\n";
$missingIni = [];
foreach ($ids as $id => $meta) {
    [$key, $sec] = array_pad(explode(':', $id, 2), 2, '');
    if (!isset($ini[$sec][$key])) { $missingIni[] = $id; }
}
if ($missingIni) {
    bad(count($missingIni) . ' control(s) have no settings.ini line, so they ship switched OFF: '
        . implode(', ', array_slice($missingIni, 0, 12)) . (count($missingIni) > 12 ? ' ...' : ''));
} else {
    good('all ' . count($ids) . ' controls have a settings.ini line');
}

// ------------------------------------------------------------------ 2. every id is read somewhere
echo "\n2. every control is read somewhere (a .psc, a wire key, or a declared game-only reason)\n";
$dead = [];
foreach ($ids as $id => $meta) {
    $how = '';
    // [0.5.0 fix pass / S-3] (b) IS EVALUATED FIRST, and there is no fallback to (a) from it. An id in
    // SERVER_KNOBS is one whose VALUE has to reach the server; being named in a .psc proves only that
    // the script read the setting, not that anybody acted on it. That ordering is exactly what hid
    // bTruthGate: it is in this map, but LRG_Profile.psc names "bTruthGate:Truth" in a SettingBool call,
    // so path (a) matched first and the wire half was never tested - while no .php read `tg` at all.
    if (isset($SERVER_KNOBS[$id])) {
        $k = $SERVER_KNOBS[$id];
        // the real emission shape, in every script that has one: `s += ";<key>=" + value`
        $written = strpos($psc, '";' . $k . '="') !== false
            || preg_match('/"[^"\n]*;' . preg_quote($k, '/') . '="/', $psc) === 1;
        $read = strpos($php, "'" . $k . "'") !== false || strpos($php, '"' . $k . '"') !== false;
        if ($written && $read) {
            $how = 'b: wire key ' . $k . ' (written by a script, read by the server)';
        } elseif (!$written) {
            $dead[$id] = 'the wire key "' . $k . '" is never written by any .psc';
        } else {
            $dead[$id] = 'the wire key "' . $k . '" is never read by any .php';
        }
    } elseif (strpos($psc, '"' . $id . '"') !== false || strpos($psc, "'" . $id . "'") !== false
        || strpos($psc, $id) !== false) {
        $how = 'a: read by a script';
    } elseif (isset($GAME_ONLY[$id])) {
        $how = 'c: game-only - ' . $GAME_ONLY[$id];
    } else {
        $dead[$id] = 'nothing reads it';
    }
    $rows[$id] = ['page' => $meta['page'], 'type' => $meta['type'], 'how' => $how,
        'ini' => in_array($id, $missingIni, true) ? 'MISSING' : 'yes'];
}
if ($dead) {
    // [pt19 v1.0] the two controls this round adds are READ by Lane C (LRG_Dialogue / LRG_Main), which lands after D
    foreach ($dead as $id => $why) {
        if (in_array($id, ['bAutoAdvance:Dialogue', 'iKeyPushToTalk:Dialogue'], true)) { pending($id . ' - ' . $why . ' (Lane C reads it)'); }
        else { bad($id . ' - ' . $why); }
    }
} else {
    good('all ' . count($ids) . ' controls have a reader');
}

// ------------------------------------------------------------------ 3. the server-knob map is honest
echo "\n3. the server-knob map names only ids that really exist\n";
$ghost = array_values(array_diff(array_keys($SERVER_KNOBS), array_keys($ids)));
if ($ghost) {
    // not fatal while lane B is still adding this round's page, but it must be visible
    echo '  [note] the map names ' . count($ghost) . ' id(s) config.json does not have yet: '
        . implode(', ', $ghost) . "\n";
} else {
    good('every id in the server-knob map exists in config.json');
}
// [pt19 v1.0] a GAME_ONLY id with no MCM control is an ini-only game setting (bDriveSceneMenus): it must have its
// ini line and a script that reads it - otherwise the map names a ghost
foreach (array_diff(array_keys($GAME_ONLY), array_keys($ids)) as $gid) {
    [$gk, $gs] = array_pad(explode(':', $gid, 2), 2, '');
    if (!isset($ini[$gs][$gk])) { bad("GAME_ONLY names $gid, which has neither an MCM control nor a settings.ini line"); continue; }
    [$gD, $gOther] = pscWhere('/"' . preg_quote($gid, '/') . '"/');
    if ($gD || $gOther) { good("$gid is an ini-only game setting (no MCM control on purpose) and " . implode(', ', array_merge($gD, $gOther)) . ' reads it'); }
    else { pending("$gid has its ini line but no script reads it yet (Lane C: LRG_Dialogue)"); }
}

// ------------------------------------------------------------------ 4. no ini key without a control
echo "\n4. no settings.ini key without a control (a leftover key is a control that was removed)\n";
$orphans = [];
foreach ($ini as $sec => $keys) {
    foreach ($keys as $k => $_) {
        if (!isset($ids[$k . ':' . $sec]) && !isset($GAME_ONLY[$k . ':' . $sec])) { $orphans[] = $k . ':' . $sec; }
    }
}
if ($orphans) {
    echo '  [note] ' . count($orphans) . ' ini key(s) with no control: '
        . implode(', ', array_slice($orphans, 0, 12)) . (count($orphans) > 12 ? ' ...' : '') . "\n";
} else {
    good('no orphaned settings.ini keys');
}

// ------------------------------------------------------------------ the table
if (!$quiet) {
    echo "\n5. the table: every control, its page, and what reads it\n";
    printf("  %-34s %-22s %-9s %-8s %s\n", 'id', 'page', 'type', 'ini', 'read by');
    foreach ($rows as $id => $r) {
        printf("  %-34s %-22s %-9s %-8s %s\n", $id, substr((string) $r['page'], 0, 22), $r['type'], $r['ini'],
            $r['how'] !== '' ? $r['how'] : ($dead[$id] ?? '?'));
    }
}

// ------------------------------------------------------------------ 6. MCM Helper schema
// 2026-09-23 crash: six "type": "button" controls. MCM Helper (Exit-9B/MCM-Helper docs/config.schema.json)
// knows only the types below; an unknown type makes ContentHandler::EndObject fail the WHOLE file, and on
// this install its error reporter then threw a fmt::format_error and took the game down at load. A button
// is a "text" control with an "action"; action.form is the STRING "Plugin.esp|0x800", never an object.
if (!$quiet) { echo "\n6. MCM Helper schema (types and actions)\n"; }
$allowedTypes = ['empty', 'header', 'text', 'toggle', 'hiddenToggle', 'slider', 'stepper', 'menu', 'enum', 'color', 'keymap', 'input'];
$allowedActions = ['CallFunction', 'CallGlobalFunction', 'SendEvent', 'RunConsoleCommand'];
$schemaBad = 0;
foreach ($cfg['pages'] ?? [] as $pg) {
    $pname = (string) ($pg['pageDisplayName'] ?? '?');
    foreach ($pg['content'] ?? [] as $ctl) {
        if (!is_array($ctl)) { continue; }
        $t = (string) ($ctl['type'] ?? '');
        $label = $pname . ' / ' . (string) ($ctl['id'] ?? $ctl['text'] ?? '?');
        if (!in_array($t, $allowedTypes, true)) { $schemaBad++; bad("unsupported control type '$t' at $label - MCM Helper fails the whole file"); }
        if (isset($ctl['action'])) {
            $a = $ctl['action'];
            if (!is_array($a)) { $schemaBad++; bad("action is not an object at $label"); continue; }
            $at = (string) ($a['type'] ?? '');
            if (!in_array($at, $allowedActions, true)) { $schemaBad++; bad("unknown action type '$at' at $label"); }
            if (in_array($at, ['CallFunction', 'CallGlobalFunction'], true) && trim((string) ($a['function'] ?? '')) === '') { $schemaBad++; bad("action without a function at $label"); }
            if (isset($a['form']) && (!is_string($a['form']) || !preg_match('/^[^|]+\.es[pml]\|(0x)?[0-9A-Fa-f]{1,8}$/i', $a['form']))) { $schemaBad++; bad("action.form must be the string 'Plugin.esp|0x800' at $label"); }
            if ($t !== 'text') { $schemaBad++; bad("an action belongs on a 'text' control, not '$t', at $label"); }
        }
        // valueOptions: sourceType must be one MCM Helper knows (2026-09-23 second crash: "PropertyValue"
        // is not a type - it is PropertyValueString / Int / Float / Bool), and sourceForm is the same
        // "Plugin.esp|0x800" STRING as action.form.
        if (isset($ctl['valueOptions']) && is_array($ctl['valueOptions'])) {
            $vo = $ctl['valueOptions'];
            $st = (string) ($vo['sourceType'] ?? '');
            $allowedSources = ['ModSettingBool', 'ModSettingInt', 'ModSettingFloat', 'ModSettingString', 'PropertyValueBool', 'PropertyValueInt', 'PropertyValueFloat', 'PropertyValueString', 'GlobalValue'];
            if ($st !== '' && !in_array($st, $allowedSources, true)) { $schemaBad++; bad("unknown sourceType '$st' at $label - MCM Helper fails the whole file"); }
            if (isset($vo['sourceForm']) && (!is_string($vo['sourceForm']) || !preg_match('/^[^|]+\.es[pml]\|(0x)?[0-9A-Fa-f]{1,8}$/i', $vo['sourceForm']))) { $schemaBad++; bad("sourceForm must be the string 'Plugin.esp|0x800' at $label"); }
            if ($t === 'text' && $st !== '' && $st !== 'PropertyValueString' && $st !== 'ModSettingString') { $schemaBad++; bad("a text control may only read a string source (PropertyValueString / ModSettingString) at $label"); }
            if ($t === 'text' && isset($vo['sourceForm']) && !isset($vo['scriptName'])) { $schemaBad++; bad("a text control with sourceForm also needs scriptName, or MCM Helper 1.6.2 reads the property from an empty script name and shows a blank line, at $label"); }
            if ($t === 'text' && isset($vo['propertyName']) && !isset($vo['scriptName']) && !isset($vo['sourceForm'])) { $schemaBad++; bad("a text control reading a property should name scriptName explicitly at $label"); }
        }
    }
}
if ($schemaBad === 0) { good('every control type and action is one MCM Helper accepts'); }

// ------------------------------------------------------------------ 7. [pt17] the two dry-run switches
// 2026-09-23 19:57: told to "leave dry run on", the owner met "Dry-run mode" on page 1 (the developer switch, OFF)
// before the menuless-questing one on page 7, switched it on, and seven correct requests were refused in silence.
// The developer switch now lives at the bottom of the Diagnostics page, its text starts with DEV, each help names
// the other one, and no two toggles may ever share a label again.
// [pt19 v1.0, spec S3.4] The menuless dry run ships OFF and is never forced or cleared by the game: the auto-clear
// (ReadCalibration (e)/(e0) and its MCM Helper write), bDlgDryRunHold and the emergency-key condition are retired.
echo "\n7. the two dry-run switches are told apart; quest talk ships on, the menuless dry run ships OFF and nothing writes it\n";
$dev = $ids['bDryRun:General'] ?? null;
$dlg = $ids['bDlgDryRun:Dialogue'] ?? null;
if ($dev === null || $dlg === null) {
    bad('bDryRun:General or bDlgDryRun:Dialogue is missing from config.json');
} else {
    if ($dev['page'] === 'Diagnostics' && str_starts_with($dev['text'], 'DEV')) { good('bDryRun:General sits on the Diagnostics page and its text starts with DEV'); }
    else { bad('bDryRun:General must sit on the Diagnostics page with a text starting "DEV" (page ' . $dev['page'] . ', text "' . $dev['text'] . '")'); }
    $pages = array_column($cfg['pages'] ?? [], 'pageDisplayName');
    $last = end($pages);
    if ($last === 'Diagnostics' && isset($cfg['pages'][count($pages) - 1]['content']) && ($cfg['pages'][count($pages) - 1]['content'][array_key_last($cfg['pages'][count($pages) - 1]['content'])]['id'] ?? '') === 'bDryRun:General') {
        good('... and it is the LAST control of the LAST page - nobody meets it before the switch they were looking for');
    } else { bad('bDryRun:General must be the last control of the last page (Diagnostics)'); }
    if (str_contains($dev['help'], 'Menuless questing') && str_contains($dev['help'], 'NPCs will SAY')) { good('its help names the other switch and says NPCs will say it is on'); }
    else { bad('bDryRun:General help must name the Menuless questing dry run and say that NPCs will SAY it is on'); }
    if (str_contains($dev['help'], 'the one that ships on')) { bad('bDryRun:General help still says the menuless dry run "is the one that ships on" - it ships OFF in v1.0'); }
    else { good('... and it no longer claims the menuless dry run ships on'); }
    if (str_starts_with($dlg['text'], 'Menuless questing') && str_contains($dlg['help'], 'Diagnostics')) { good('bDlgDryRun:Dialogue starts with "Menuless questing" and its help names the Diagnostics switch'); }
    else { bad('bDlgDryRun:Dialogue must start with "Menuless questing" and its help must name the Diagnostics page (text "' . $dlg['text'] . '")'); }
    if (str_contains($dlg['help'], 'ships OFF') && !str_contains($dlg['help'], 'CLEARS ITSELF') && !str_contains($dlg['help'], 'Keep the menuless dry run')) {
        good('... and it says it ships OFF, and no longer that it clears itself or that a hold switch exists');
    } else { bad('bDlgDryRun:Dialogue help must say it "ships OFF" and must not say it CLEARS ITSELF or name "Keep the menuless dry run" (spec S3.4)'); }
}
$texts = [];
$dupes = [];
foreach ($ids as $id => $meta) {
    if ($meta['type'] !== 'toggle') { continue; }
    $k = strtolower(trim($meta['text']));
    if ($k === '') { continue; }
    if (isset($texts[$k])) { $dupes[] = $id . ' = ' . $texts[$k]; }
    $texts[$k] = $id;
}
if ($dupes) { bad('two toggles share a label: ' . implode(', ', $dupes)); } else { good('no two toggles share a label'); }
// [pt19 v1.0, spec S10 / 2.4 gate A] the shipped defaults
$ship = ['General' => ['bDryRun' => '0'],
    'Dialogue' => ['bMenuless' => '1', 'bDlgDryRun' => '0', 'bAutoAdvance' => '1', 'iSceneGate' => '1', 'iTailMax' => '16',
        'bDriveSceneMenus' => '1', 'iKeyPushToTalk' => '29']];
$shipBad = [];
$shipTxt = [];
foreach ($ship as $sec => $keys) {
    foreach ($keys as $k => $want) {
        $shipTxt[] = "$k=$want";
        if ((string) ($ini[$sec][$k] ?? '(missing)') !== $want) { $shipBad[] = "$k:$sec=" . (string) ($ini[$sec][$k] ?? '(missing)') . " (want $want)"; }
    }
}
if ($shipBad) { bad('settings.ini ships the wrong defaults: ' . implode(', ', $shipBad)); }
else { good('settings.ini ships ' . implode(', ', $shipTxt) . ' - quest talk by voice is on out of the box, both dry runs off'); }
if (strpos($psc, 'SettingBool("bMenuless:Dialogue", true)') !== false) {
    good('the driver\'s in-code default of bMenuless equals the ini line (true)');
    // LRG_Profile.psc's snapshot copy is another lane's file: a leftover false there is reported, not failed
    if (strpos($psc, 'SettingBool("bMenuless:Dialogue", false)') !== false) { echo "  [note] a .psc still reads bMenuless:Dialogue with the default false (LRG_Profile.psc ml=) - make it true, or read LRG_Dialogue.MenulessLive()\n"; }
} else { bad('LRG_Dialogue.psc must read bMenuless:Dialogue with the default true - the in-code default equals the ini line'); }
[$acD, $acOther] = pscWhere('/SetModSetting\w*\s*\([^)\n]*bDlgDryRun:Dialogue/');
if ($acD) { bad('a Lane D script writes bDlgDryRun:Dialogue through MCM.SetModSetting* (' . implode(', ', $acD) . ') - spec S3.4: never forced, never cleared'); }
if ($acOther) { pending('the dry-run auto-clear still writes bDlgDryRun:Dialogue through MCM.SetModSetting* in ' . implode(', ', $acOther) . ' - spec S3.4 deletes ReadCalibration (e)/(e0) and its MCM Helper write'); }
if (!$acD && !$acOther) { good('no script writes bDlgDryRun:Dialogue - the menuless dry run is the owner\'s own toggle (spec S3.4)'); }

// ------------------------------------------------------------------ 8. [pt19 v1.0] the calibration is four passive rows
// Spec S3.1: cm rm fam st, learned from the first menu of any kind; timer / x1 / apd / ms3 / tail / col / row / actms are
// measurements; the probe presses and key, the opt-in active pass, the manual presses, the automatic calibration session
// and the rows hide / guard / rb / reopen / park are retired. Spec S3.2: the route is proven by the driver's first click
// (CalSet src "live" -> CalRouteLive) and CalForget tells the server (ev=calib reset=1). Spec S8: ev=calib k= carries
// exactly cm rm fam st route timer x1 apd ms3 tail.
echo "\n8. [v1.0] the calibration: four passive rows, the route proven by doing, the presses and the automatic session gone\n";
if (isset($ini['Calib'])) { bad('settings.ini still has a [Calib] section: ' . implode(', ', array_keys($ini['Calib']))); }
else { good('settings.ini has no [Calib] section'); }
$calibIds = array_values(array_filter(array_keys($ids), fn($i) => str_ends_with($i, ':Calib')));
if ($calibIds) { bad('config.json still has :Calib controls: ' . implode(', ', $calibIds)); } else { good('config.json has no :Calib control'); }
$calPage = null;
foreach ($cfg['pages'] ?? [] as $pg) { if (($pg['pageDisplayName'] ?? '') === 'Calibration') { $calPage = $pg; } }
if ($calPage === null) {
    bad('config.json has no Calibration page');
} else {
    $shape = [];
    foreach ($calPage['content'] ?? [] as $ctl) { $shape[] = ($ctl['type'] ?? '?') . ':' . ($ctl['text'] ?? '?'); }
    $wantShape = ['header:Where it stands', 'text:Calibration status', 'text:Forget everything it learned'];
    if ($shape === $wantShape) { good('the Calibration page is exactly: Where it stands / Calibration status / Forget everything it learned'); }
    else { bad('the Calibration page must be exactly ' . implode(' | ', $wantShape) . ' (got ' . implode(' | ', $shape) . ')'); }
    foreach ($calPage['content'] ?? [] as $ctl) {
        if (($ctl['text'] ?? '') === 'Calibration status') {
            $vo = (array) ($ctl['valueOptions'] ?? []);
            if (($vo['scriptName'] ?? '') === 'LRG_MCM' && ($vo['propertyName'] ?? '') === 'CalStatus') { good('the status line reads LRG_MCM.CalStatus (scriptName named)'); }
            else { bad('the Calibration status text must read scriptName LRG_MCM, propertyName CalStatus'); }
        }
        if (($ctl['text'] ?? '') === 'Forget everything it learned') {
            $a = (array) ($ctl['action'] ?? []);
            if (($a['type'] ?? '') === 'CallFunction' && ($a['function'] ?? '') === 'CalForget') { good('"Forget everything it learned" calls LRG_MCM.CalForget'); }
            else { bad('"Forget everything it learned" must be a CallFunction of CalForget'); }
        }
    }
}
$mcmSrc = $pscByFile['LRG_MCM.psc'] ?? '';
if ($mcmSrc !== '' && preg_match('/^[ \t]*Function[ \t]+CalForget[ \t]*\(/m', $mcmSrc) === 1 && preg_match('/^[ \t]*string[ \t]+Property[ \t]+CalStatus\b/mi', $mcmSrc) === 1) {
    good('LRG_MCM.psc keeps CalForget and the CalStatus property');
} else { bad('LRG_MCM.psc must keep Function CalForget() and string Property CalStatus'); }
$retiredFns = ['CalShotFire', 'CalShotArm', 'StepName', 'RunStepByName', 'RunStep', 'ClickWindow', 'ClickAllowed', 'DoClick',
    'DoCloseClean', 'DoCloseForce', 'CalPending', 'CalParkPoll', 'CalNextActive', 'CalActiveName', 'CalActiveWanted',
    'CalActiveDisable', 'CalActiveRun', 'CalLogSkip', 'CalActiveLog', 'CalActiveA1', 'CalActiveA2', 'CalActiveA3',
    'CalActiveA4', 'CalActiveA5', 'CalPressClick', 'CalPressClickB', 'CalPressClickBody', 'CalClickRefusal', 'CalPressReopen',
    'CalPressSmartTalk', 'CalPressForceClose', 'CalReopenProof', 'CalServiceNpc', 'CalAutoWanted', 'CalAutoRefusal',
    'CalAutoClickWanted', 'CalAutoOther', 'CalAutoNoteOpen', 'CalAutoRun', 'CalArmBudget', 'CalRunsWord', 'SetCalAbort',
    'CalAbortWanted'];
$stillDefined = [];
foreach ($retiredFns as $fn) {
    [$fD, $fO] = pscWhere('/^[ \t]*(?:\w+(?:\[\])?[ \t]+)?(?:Function|Event)[ \t]+' . $fn . '[ \t]*\(/m');
    foreach (array_merge($fD, $fO) as $bn) { $stillDefined[] = "$fn ($bn)"; }
}
[$pD, $pO] = pscWhere('/^[ \t]*(?:\w+[ \t]+)?Function[ \t]+Press\w*[ \t]*\(/m');
foreach (array_merge($pD, $pO) as $bn) { $stillDefined[] = "Press* ($bn)"; }
if ($stillDefined) { bad('retired calibration / probe functions are still defined: ' . implode(', ', $stillDefined)); }
else { good('the presses, the probe key and shots, the active pass, the automatic session, PENDING and the abort flag are defined nowhere'); }
$probe = $pscByFile['LRG_DlgProbe.psc'] ?? '';
// the frozen probe API (spec 2.2, Lane D -> Lane C), signatures unchanged, plus what LRG_Dialogue already calls
$api = ['Function Maintenance()', 'Function CalArm(int aiApd, int aiFam, int aiItems, int aiPlat, bool abHidden, bool abGlueOpened)',
    'Function CalLayer(int aiTotal, int aiHead, int aiReadMode)', 'Function CalReadProbe(int aiTotal, int aiHead, int aiReadMode)',
    'Function CalNoteReadCost(int aiMs, int aiReads)', 'Function CalPoll(int aiState, int aiCount, int aiTimerId, string asSubtitle)',
    'Function CalSpeech(int aiWho, float afAt)', 'Function CalX1Poll(int aiCount)',
    'Function CalClose(string asWhy, int aiLayer, bool abPending, bool abAnyClick)',
    'Function CalSet(string asKey, int aiValue, string asSrc, int aiSamples)', 'int Function CalGet(string asKey, int aiDefault)',
    'int Function CalG(string asKey)', 'string Function CalMissing()', 'bool Function CalGreen()', 'int Function CalAnswered()',
    'string Function CalStatusText()', 'string Function CalWire()', 'Function CalLogSummary()', 'Function CalForget()',
    'bool Function CalDirty(bool abClear)', 'Function CalNewOpen(bool abHidden)',
    'Function CalOpened(int aiTries, int aiMs)', 'Function CalPayload(int aiChars, bool abPart2, int aiTailChars)',
    'bool Function CalRouteLive()', 'bool Function CalLearnable()'];
$flat = preg_replace('/[ \t]+/', ' ', $probe);
$apiMissing = [];
foreach ($api as $sig) { if (preg_match('/^ ?' . preg_quote($sig, '/') . ' ?$/mi', $flat) !== 1) { $apiMissing[] = $sig; } }
if ($apiMissing) { bad('the frozen probe API is broken in LRG_DlgProbe.psc - missing or changed: ' . implode('; ', $apiMissing)); }
else { good('LRG_DlgProbe.psc exports the frozen probe API (' . count($api) . ' signatures, CalRouteLive and CalLearnable added)'); }
$missBody = pscBody($probe, 'CalMissing');
preg_match_all('/CalAdd\(out, "([^"]+)"\)/', $missBody, $mm);
preg_match_all('/CalG\("(\w+)"\)/', $missBody, $mk);
if ($mm[1] === ['counting', 'reading', 'menu layout', 'Smart Talk settings'] && $mk[1] === ['cm', 'rm', 'fam', 'st']) {
    good('CalMissing() is the four rows: counting (cm), reading (rm), menu layout (fam), Smart Talk settings (st)');
} else { bad('CalMissing() must name exactly counting / reading / menu layout / Smart Talk settings over cm rm fam st (got names ' . implode(',', $mm[1]) . '; keys ' . implode(',', $mk[1]) . ')'); }
if (str_contains(pscBody($probe, 'CalAnswered'), 'return 4') && str_contains($probe, ' of 4')) { good('CalAnswered() counts N of 4 and the status line says "of 4"'); }
else { bad('CalAnswered() must count out of 4 and CalStatusText() must say "N of 4"'); }
$stBody = pscBody($probe, 'CalStatusText');
if (str_contains($stBody, '4 of 4 learned, route proven by ') && str_contains($stBody, '"learning: "') && str_contains($stBody, ' of 4 - talk to anyone once')) {
    good('the status line reads "4 of 4 learned, route proven by N click(s)" / "learning: N of 4 - talk to anyone once" (spec S10)');
} else { bad('CalStatusText() must produce "4 of 4 learned, route proven by N click(s)" and "learning: N of 4 - talk to anyone once" (spec S10)'); }
// [pt19 fix 1] after a route flip the server's clicks_ok is still set and the next pick may be any line, so the
// not-proven text may not promise "the first simple line"; a row talking cannot fix is named, not "talk to anyone once"
$stuckBody = pscBody($probe, 'CalStuckWhy');
if (!str_contains($stBody, 'first simple line') && str_contains($stBody, 'CalStuckWhy()') && str_contains(pscBody($probe, 'CalLearnable'), 'CalStuckWhy()')
    && str_contains($stuckBody, 'CalG("cm") == -1') && str_contains($stuckBody, 'CalG("rmN") >= 3') && str_contains($stuckBody, 'CalG("famu")')) {
    good('the status line names what talking cannot fix (cm=-1, rm=-3 x3, an unknown interface) and CalLearnable() says so to the driver; no "first simple line"');
} else { bad('CalStatusText() must name what talking cannot fix through CalStuckWhy() (cm=-1, rm=-3 after rmN>=3, famu), CalLearnable() must use it, and the not-proven text must not say "first simple line"'); }
$wireBody = pscBody($probe, 'CalWire');
preg_match_all('/CalG\("(\w+)"\)/', $wireBody, $wk);
preg_match_all('/"[,]?(\w+):"/', $wireBody, $wn);
$wireWant = ['cm', 'rm', 'fam', 'st', 'route', 'timer', 'x1', 'apd', 'ms3', 'tail'];
if ($wk[1] === $wireWant && $wn[1] === $wireWant) { good('CalWire() (ev=calib k=) carries exactly ' . implode(' ', $wireWant) . ' (spec S8)'); }
else { bad('CalWire() must carry exactly ' . implode(' ', $wireWant) . ' (spec S8) - got names ' . implode(' ', $wn[1]) . ' / keys ' . implode(' ', $wk[1])); }
$resetBody = pscBody($probe, 'CalSendReset');
if (str_contains(pscBody($probe, 'CalForget'), 'CalSendReset()') && str_contains($resetBody, '"ev=calib;') && str_contains($resetBody, ';reset=1;')
    && str_contains($resetBody, ';k=" + CalWire()') && str_contains($resetBody, 'bDlgWire:Dialogue')) {
    good('CalForget() sends ev=calib reset=1 (spec S3.2 / S8: the server clears clicks_ok)');
} else { bad('CalForget() must send ev=calib ...;reset=1;... (CalSendReset) so the server clears clicks_ok (spec S3.2)'); }
$setBody = pscBody($probe, 'CalSet');
if (str_contains($setBody, 'CalRouteProof(') && str_contains(pscBody($probe, 'CalRouteProof'), '"live"') && str_contains(pscBody($probe, 'CalRouteLive'), '"rproof"')
    && str_contains($setBody, '"CALIB set route src=live route="')) {
    good('the route is proven by doing: CalSet(route, r, "live") records rproof / rclicks, CalRouteLive() reads them, and the proof logs "CALIB set route src=live" (spec S3.2, 5.3 step 2)');
} else { bad('CalSet must record the live route proof (CalRouteProof), CalRouteLive() must read rproof, and a new proof must log the literal "CALIB set route src=live" (spec S3.2 / 5.3 step 2)'); }
$retiredKeys = ['hide', 'guard', 'rb', 'reopen', 'park', 'runs', 'armed', 'auto', 'autob', 'gopen', 'apdr', 'inj', 'ms4', 'flash', 'hidems', 'poke'];
$ui = $pscByFile['LRG_DlgUI.psc'] ?? '';
foreach (['CalClear', 'CalFileKeys'] as $fnName) {
    preg_match_all('/\w+\[\d+\] = "(\w+)"/', pscBody($ui, $fnName), $km);
    $left = array_values(array_intersect($km[1], $retiredKeys));
    $need = array_values(array_diff(['cm', 'rm', 'fam', 'st', 'route', 'rproof', 'rclicks'], $km[1]));
    if (!$left && !$need) { good("LRG_DlgUI.$fnName() names the v1.0 key set (" . count($km[1]) . ' keys, rproof / rclicks in, no retired key)'); }
    else { bad("LRG_DlgUI.$fnName() - retired keys still named: " . implode(' ', $left) . '; missing: ' . implode(' ', $need)); }
}
// [pt19 fix 1] first-evening step 1 promises the corner note. An install that learned the menu before v1.0 restores
// its rows from the install file at load, so a note on the red -> green edge alone would never fire there: the
// note is said once per INSTALL (gnote, in the store, the install file and CalClear) from CalSet's edge AND from
// the first list of a game session whose gate is already green (CalLayer).
$gnBody = pscBody($probe, 'CalGreenNote');
preg_match_all('/\w+\[\d+\] = "(\w+)"/', pscBody($ui, 'CalClear'), $gc);
preg_match_all('/\w+\[\d+\] = "(\w+)"/', pscBody($ui, 'CalFileKeys'), $gf);
if (str_contains($gnBody, 'CalG("gnote")') && str_contains($gnBody, '"gnote", 1') && str_contains($gnBody, 'the dialogue menu is learned (4 of 4)')
    && str_contains(pscBody($probe, 'CalLayer'), 'CalGreenNote(') && str_contains($setBody, 'CalGreenNote(')
    && in_array('gnote', $gc[1], true) && in_array('gnote', $gf[1], true)) {
    good('the "menu is learned (4 of 4)" note is said once per install, also when the rows were restored (gnote: store, install file, CalClear)');
} else { bad('the "menu is learned (4 of 4)" note must be said once per install from CalSet AND CalLayer, keyed on gnote in the store, CalFileKeys and CalClear (5.3 step 1)'); }
if (strpos($psc, 'Function CalFileSync') !== false && strpos($psc, 'Function CalFileRestore') !== false) { good('the calibration is persisted to an install file (CalFileSync / CalFileRestore)'); }
else { bad('the install-file persistence is incomplete (CalFileSync / CalFileRestore)'); }
foreach (['lrgDlgCalibCandidate', 'lrgDlgCalibPick'] as $sfn) {
    if (strpos($php, 'function ' . $sfn) !== false) { echo "  [note] the server still defines $sfn (Lane A deletes it, spec section 4)\n"; }
}

// ------------------------------------------------------------------ 9. [pt19 v1.0] the controls of spec S10, exactly
echo "\n9. [v1.0, spec S10] the kept and new controls: exact labels, pages, help texts that must be true, in-code defaults\n";
$labels = ['bMenuless:Dialogue' => 'Quest talk without the menu', 'bDlgDryRun:Dialogue' => 'Menuless questing: dry run (nothing is clicked)',
    'bDlgWire:Dialogue' => 'Send quest talk to the server',
    'iSceneGate:Dialogue' => 'NPCs inside a quest scene', 'bIntentOpen:Dialogue' => 'May open a conversation to find out',
    'fDecideTimeout:Dialogue' => 'How long it waits for the server', 'fLineSettle:Dialogue' => 'Quiet time before the first click',
    'fOpenDistance:Dialogue' => 'How close she has to be', 'iClickRoute:Dialogue' => 'Which click the menu takes',
    'iKeyLeave:Keys' => 'Leave the conversation', 'bAllowNullVoice:Dialogue' => 'Allow a silent conversation',
    'bActivateDefaultOnly:Dialogue' => 'Plain activation', 'iKeyDumpTopics:Keys' => 'Dump topics (test tool)',
    'bAutoAdvance:Dialogue' => 'Let her carry on by herself when there is only one thing to say',
    'iKeyPushToTalk:Dialogue' => 'Your talk key (CHIM\'s push-to-talk)'];
$labelBad = [];
foreach ($labels as $lid => $want) {
    if (!isset($ids[$lid])) { $labelBad[] = "$lid is missing"; }
    elseif ($ids[$lid]['text'] !== $want) { $labelBad[] = "$lid reads \"" . $ids[$lid]['text'] . "\" (want \"$want\")"; }
}
if ($labelBad) { bad('spec S10 labels: ' . implode('; ', $labelBad)); } else { good('all ' . count($labels) . ' spec S10 labels are exact'); }
$aa = $ids['bAutoAdvance:Dialogue'] ?? null;
if ($aa && $aa['page'] === 'Menuless questing' && $aa['type'] === 'toggle') { good('bAutoAdvance:Dialogue is a toggle on the Menuless questing page'); }
else { bad('bAutoAdvance:Dialogue must be a toggle on the Menuless questing page'); }
$pt = $ids['iKeyPushToTalk:Dialogue'] ?? null;
if ($pt && $pt['page'] === 'Keys' && $pt['type'] === 'keymap') { good('iKeyPushToTalk:Dialogue is a keymap on the Keys page'); }
else { bad('iKeyPushToTalk:Dialogue must be a keymap on the Keys page (spec S10, rev1 ai R4)'); }
if ($pt && str_contains($pt['help'], 'only tells her you are about to speak, so she waits instead of carrying on by herself')) { good('... and its help says what it does (spec S10 wording)'); }
else { bad('iKeyPushToTalk:Dialogue help must say "only tells her you are about to speak, so she waits instead of carrying on by herself"'); }
$sg = $ids['iSceneGate:Dialogue'] ?? null;
if ($sg && str_contains($sg['help'], 'a scene whose quest has an unfinished objective in your journal')) { good('the iSceneGate help names the real test: "a scene whose quest has an unfinished objective in your journal" (rev2 game R1)'); }
else { bad('iSceneGate:Dialogue help must contain "a scene whose quest has an unfinished objective in your journal" (spec S10, rev2 game R1)'); }
// [pt19 fix 2] S10's "Guards, arrests and bounties" stays on the page as a TEXT row (no id, no switch): a guard who has
// something against you is never driven (S1.1: "the speaker is not crit 2", no setting) and every pick on a crit-2 or
// arrest-class session is refused at every setting (S4.9, lrg_dialogue.php gate), so iCritical / bCrimeManual could
// change nothing the owner sees - both are in $REMOVED (section 10). The row must still tell him the rule.
$guardRow = null;
foreach ($cfg['pages'] ?? [] as $pg) {
    foreach ($pg['content'] ?? [] as $ctl) {
        if (is_array($ctl) && str_starts_with((string) ($ctl['text'] ?? ''), 'Guards, arrests and bounties')) { $guardRow = [$pg['pageDisplayName'] ?? '?', $ctl]; }
    }
}
if ($guardRow && $guardRow[0] === 'Menuless questing' && ($guardRow[1]['type'] ?? '') === 'text' && !isset($guardRow[1]['id'])
    && !isset($guardRow[1]['valueOptions']) && !isset($guardRow[1]['action'])
    && str_contains((string) ($guardRow[1]['help'] ?? ''), 'nothing on it is ever picked for you, at any setting')
    && str_contains((string) ($guardRow[1]['help'] ?? ''), 'Resisting arrest is never picked for you')) {
    good('"Guards, arrests and bounties" is a text row on the Menuless questing page (no id, no switch) whose help states the fixed rule');
} else {
    bad('"Guards, arrests and bounties" must be a text row (no id, no valueOptions, no action) on the Menuless questing page whose help says "nothing on it is ever picked for you, at any setting" and "Resisting arrest is never picked for you"');
}
if (!isset($ids['bDriveSceneMenus:Dialogue'])) { good('bDriveSceneMenus:Dialogue has no MCM control, on purpose (spec S10)'); }
else { bad('bDriveSceneMenus:Dialogue must NOT be an MCM control (spec S10: an ini line only)'); }
foreach (['bAutoAdvance:Dialogue' => '/Setting(?:Bool\s*\(\s*"bAutoAdvance:Dialogue"\s*,\s*true|Int\s*\(\s*"bAutoAdvance:Dialogue"\s*,\s*1)\s*\)/',
    'iKeyPushToTalk:Dialogue' => '/SettingInt\s*\(\s*"iKeyPushToTalk:Dialogue"\s*,\s*29\s*\)/',
    'bDriveSceneMenus:Dialogue' => '/Setting(?:Bool\s*\(\s*"bDriveSceneMenus:Dialogue"\s*,\s*true|Int\s*\(\s*"bDriveSceneMenus:Dialogue"\s*,\s*1)\s*\)/'] as $rid => $re) {
    [$rD, $rO] = pscWhere($re);
    if ($rD || $rO) { good("$rid is read with the in-code default equal to its ini line (" . implode(', ', array_merge($rD, $rO)) . ')'); }
    else { pending("$rid is not read with the in-code default equal to its ini line by any script (Lane C: LRG_Dialogue / LRG_Main)"); }
}
// nothing the owner reads may point at a retired control or promise retired behaviour
$retiredPhrases = ['Open vanilla dialogue (emergency)', 'Run the probe press', 'Arm the probe', 'Which probe press', 'Keep the menuless dry run',
    'Let me leave dry run anyway', 'Calibrate on the next few conversations', 'Measure the menu by itself', 'Learn from ordinary conversations',
    'Let it adjust the waiting times', 'CLEARS ITSELF', "the probe's", 'Hide the cursor', 'Walk back to a lost choice', 'Conversations the game starts',
    'How long she waits for your answer', 'Choices inside a conversation', 'Say when I have to choose by hand', 'Carry on without the menu afterwards',
    'Touch the dialogue camera mod', 'Use the quest colour hint', 'What is hidden', 'Hiding the menu', 'First-playtest probe',
    'the Calibration page is green', 'Calibration page says green', 'ten calibration', 'of 10', 'automatic calibration',
    'really starts over on every save',
    // [pt19 fix 1] v1.0 never takes the list away, so it cannot "come back" or be "handed" to him; no control
    // is called "consequential choices"; the not-proven status may not promise "the first simple line"
    'menu comes back', 'hands you the menu', 'hand you the menu', 'Hand me the menu', 'consequential choices', 'first simple line',
    // [pt19 fix 2] the retired guard pair: no help may point at them or promise a pick in a guard conversation
    'Let her pick there too', 'Always leave an arrest to me', 'allows picks in those conversations'];
$stale = [];
foreach ($cfg['pages'] ?? [] as $pg) {
    foreach ($pg['content'] ?? [] as $ctl) {
        if (!is_array($ctl)) { continue; }
        $blob = (string) ($ctl['text'] ?? '') . ' || ' . (string) ($ctl['help'] ?? '') . ' || '
            . implode(' | ', array_map('strval', (array) ($ctl['valueOptions']['options'] ?? [])));
        foreach ($retiredPhrases as $ph) {
            if (stripos($blob, $ph) !== false) { $stale[] = ($pg['pageDisplayName'] ?? '?') . ' / ' . (string) ($ctl['id'] ?? $ctl['text'] ?? '?') . ': "' . $ph . '"'; }
        }
    }
}
if ($stale) { bad('config.json still points at retired controls or behaviour: ' . implode('; ', $stale)); }
else { good('no MCM label, option or help text names a retired control or promises retired behaviour (' . count($retiredPhrases) . ' phrases checked)'); }
// [pt19 fix 1] the owner's rule: the currency is SEPTIMS in every owner-facing string. Only the game's own menu text,
// quoted as "(25 gold)", may say gold. settings.ini's comments are read by the owner too.
$goldHits = [];
foreach ($cfg['pages'] ?? [] as $pg) {
    foreach ($pg['content'] ?? [] as $ctl) {
        if (!is_array($ctl)) { continue; }
        $strs = array_merge([(string) ($ctl['text'] ?? ''), (string) ($ctl['help'] ?? '')], array_map('strval', (array) ($ctl['valueOptions']['options'] ?? [])));
        foreach ($strs as $str) {
            if (preg_match('/\bgold\b/i', (string) preg_replace('/\(\d+ gold\)/i', '', $str)) === 1) {
                $goldHits[] = ($pg['pageDisplayName'] ?? '?') . ' / ' . (string) ($ctl['id'] ?? $ctl['text'] ?? '?');
            }
        }
    }
}
foreach (preg_split('/\R/', (string) file_get_contents($iniFile)) as $ln => $line) {
    if (preg_match('/\bgold\b/i', (string) preg_replace('/\(\d+ gold\)/i', '', $line)) === 1) { $goldHits[] = 'settings.ini line ' . ($ln + 1); }
}
if ($goldHits) { bad('an owner-facing string says "gold" - the currency is septims: ' . implode('; ', array_unique($goldHits))); }
else { good('every MCM label, option, help text and settings.ini comment says septims, never gold'); }

// ------------------------------------------------------------------ 10. [pt19 v1.0] every retired id is gone
// Spec 2.2 Lane D: "removed ids must be absent from config.json, settings.ini AND every .psc". A .psc match is on the
// comment-stripped source (a history note in a comment is not a reader); the maps of this test count as well.
echo "\n10. [v1.0, spec S10 / section 4] every retired id is gone from config.json, settings.ini, this test's maps and every .psc\n";
$REMOVED = ['iEngineOpen:Dialogue', 'iBranchInput:Dialogue', 'fSilenceTimeout:Dialogue', 'iHideMode:Dialogue', 'bHideCursor:Dialogue',
    'bRewalk:Dialogue', 'iRewalkDepth:Dialogue', 'bHandBackNote:Dialogue', 'bResumeAfterChoice:Dialogue', 'bProbe:Dialogue',
    'iProbePress:Dialogue', 'bIaccToggle:Dialogue', 'bQuestColour:Dialogue', 'bDlgDryRunHold:Dialogue', 'iKeyProbe:Keys',
    'iKeyVanillaMenu:Keys', 'bCalibPassive:Calib', 'bCalibActive:Calib', 'iCalibRuns:Calib', 'bCalibAuto:Calib',
    'iCalibAutoRuns:Calib', 'bAutoTimings:Calib', 'bCalibOverride:Calib',
    // [pt19 fix 1] retired with the server's quest-initiative block (spec section 4 / S9)
    'bQuestInitiative:Quests', 'iQuestInitiativeGap:Quests',
    // [pt19 fix 2] the guard pair: S1.1 drives no crit-2 speaker at any setting and S4.9 / the server gate refuse every
    // pick on a crit-2 or arrest-class session at any setting, so neither could change anything (section 9 keeps the
    // rule on the page as a text row)
    'iCritical:Dialogue', 'bCrimeManual:Services'];
$clean = 0;
foreach ($REMOVED as $rid) {
    [$rk, $rs] = array_pad(explode(':', $rid, 2), 2, '');
    $where = [];
    if (isset($ids[$rid])) { $where[] = 'config.json'; }
    foreach ($ini as $s => $keys) { if (isset($keys[$rk])) { $where[] = "settings.ini [$s]"; } }
    if (isset($SERVER_KNOBS[$rid]) || isset($GAME_ONLY[$rid])) { $where[] = 'this test\'s maps'; }
    [$inD, $inOther] = pscWhere('/' . preg_quote($rid, '/') . '/');
    if ($where || $inD) { bad("$rid is retired but still in " . implode(', ', array_merge($where, $inD))); }
    if ($inOther) { pending("$rid is retired but a script still reads it: " . implode(', ', $inOther)); }
    if (!$where && !$inD && !$inOther) { $clean++; }
}
if ($clean === count($REMOVED)) { good('all ' . count($REMOVED) . ' retired ids are gone everywhere'); }
// [pt19 fix 1] "no dead wire key": the retired pair's carriers (LRG_Profile ;qi= / ;qig=) go with the controls
[$wD, $wOther] = pscWhere('/"[^"\n]*;qig?="/');
if ($wD) { bad('a Lane D script still writes the retired wire key qi= / qig=: ' . implode(', ', $wD)); }
if ($wOther) { pending('the retired quest-initiative wire keys qi= / qig= are still written by ' . implode(', ', $wOther) . ' (Lane C: LRG_Profile.psc)'); }
if (!$wD && !$wOther) { good('no script writes the retired quest-initiative wire keys qi= / qig='); }
elseif (!$quiet) { echo '  [info] ' . $clean . ' of ' . count($REMOVED) . " retired ids are gone everywhere\n"; }

// ------------------------------------------------------------------ 11. [pt19 fix 2] the calibration count is "N of 4"
// S3.1 / S3.4: four passive rows, so CalAnswered() is 0..4 and the probe sends cal= 0..4. A sentence that still says
// "N of 10" tells the owner (a corner note) or the NPC (a refusal line) something false, and a parser that still wants
// "(N of 10)" silently drops the count. Only STRING LITERALS count - a history note in a comment is not a sentence anybody
// hears: Papyrus string literals read by a one-pass scanner over the RAW source, PHP through its tokenizer. (Not the
// comment-stripped copy: a {docstring} may hold a ';' - LRG_Dialogue's CalAnsweredNow does - and the line-comment strip
// then eats its closing brace.) Lane D's scripts are strict; another lane's file is "[pending <lane>]" with --lane-d-only
// and a FAIL in the release gate.
echo "\n11. [v1.0, spec S3.1 / S3.4] no string the owner or the NPC hears (or a parser reads) counts the calibration \"of 10\"\n";
/** Every string literal of a Papyrus source as [text, line]: strings, ;/ /; and ; comments and {docstrings} in ONE pass. */
function lrgPapyrusStrings(string $src): array
{
    $out = [];
    $n = strlen($src);
    $line = 1;
    for ($i = 0; $i < $n; $i++) {
        $c = $src[$i];
        if ($c === "\n") { $line++; continue; }
        if ($c === '"') {                                   // a literal never spans a line; no escapes in Papyrus
            $e = $i + 1;
            while ($e < $n && $src[$e] !== '"' && $src[$e] !== "\n") { $e++; }
            $out[] = [substr($src, $i, $e - $i + 1), $line];
            $i = ($e < $n && $src[$e] === "\n") ? $e - 1 : $e;
            continue;
        }
        $close = null;
        if ($c === ';' && $i + 1 < $n && $src[$i + 1] === '/') { $close = '/;'; }
        elseif ($c === ';') { $close = "\n"; }
        elseif ($c === '{') { $close = '}'; }
        if ($close === null) { continue; }
        $e = strpos($src, $close, $i + 1);
        if ($e === false) { break; }
        if ($close === "\n") { $e--; }                      // leave the newline to the line counter
        $line += substr_count(substr($src, $i, $e - $i + 1), "\n");
        $i = $e + strlen($close) - 1;
    }
    return $out;
}
$tenRe = '/\bof\s+10\b|\bten calibration/i';
$tenD = [];
$tenOther = [];
foreach (glob($scriptDir . '/*.psc') ?: [] as $f) {
    $bn = basename($f);
    foreach (lrgPapyrusStrings((string) file_get_contents($f)) as [$lit, $ln]) {
        if (preg_match($tenRe, $lit) !== 1) { continue; }
        if (in_array($bn, $D_FILES, true)) { $tenD[] = "$bn:$ln"; } else { $tenOther[] = "$bn:$ln"; }
    }
}
if ($tenD) { bad('a Lane D script still counts the calibration "of 10" in a string: ' . implode(', ', $tenD)); }
if ($tenOther) { pending('a driver string still counts the calibration "of 10" (S3.4 texts: "N of 4"): ' . implode(', ', $tenOther) . ' (Lane C)'); }
$phpLane = ['lrg_dialogue.php' => 'A', 'lrg_prompt_index.php' => 'A', 'lrg_factions.php' => 'B', 'lrg_speech.php' => 'B',
    'lrg_replies.php' => 'B', 'lrg_actions.php' => 'B'];
$tenPhp = [];
if (!function_exists('token_get_all')) {
    bad('the PHP tokenizer is not available, so the server\'s string literals cannot be checked for "of 10"');
} else {
    foreach (array_merge(glob($serverDir . '/*.php') ?: [], glob($serverDir . '/lib/*.php') ?: []) as $f) {
        foreach (token_get_all((string) file_get_contents($f)) as $tok) {
            if (is_array($tok) && in_array($tok[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)
                && preg_match($tenRe, $tok[1]) === 1) {
                $tenPhp[$phpLane[basename($f)] ?? 'server'][] = basename($f) . ':' . $tok[2];
            }
        }
    }
    foreach ($tenPhp as $lane => $hits) {
        pending('a server string still counts the calibration "of 10" (cal= is 0..4 now): ' . implode(', ', array_unique($hits)) . ($lane === 'server' ? ' (its owner)' : " (Lane $lane)"), $lane);
    }
}
if (!$tenD && !$tenOther && !$tenPhp) { good('every calibration count the owner or the NPC hears says "of 4" (no "of 10" string left in any .psc or server .php)'); }

printf("\n%d passed, %d failed%s\n", $ok, $fail, $laneDOnly ? sprintf(', %d pending another lane', $pendingN) : '');
echo $fail === 0 ? ($laneDOnly && $pendingN > 0 ? "LANE D CHECKS PASSED - $pendingN check(s) wait for another lane (C, or A/B for a server string; run without --lane-d-only for the release gate)\n" : "ALL CHECKS PASSED\n") : "RESULT: FAILED\n";
exit($fail === 0 ? 0 : 1);
