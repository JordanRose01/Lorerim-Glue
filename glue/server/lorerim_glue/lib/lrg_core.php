<?php
/**
 * LoreRim Glue - core library (config, logging, clock / dice seams, state, memory, profiles, interest, hard gates).
 * Shared by the OStim module now and by menuless questing later. Wire and code contract: glue/PROTOCOL.md (v2).
 *
 * Hook files run inside a function scope in HerikaServer, so everything here goes
 * through $GLOBALS and plain functions. Nothing in this file talks to the LLM.
 */

if (defined('LRG_CORE_LOADED')) { return; }
define('LRG_CORE_LOADED', true);
define('LRG_DIR', dirname(__DIR__));
// [0.5.0 fix pass / S-10] kept in lockstep with manifest.json, the way V04 did it. It is printed to the
// owner by tools/flows/run_flows.php and tools/test_latency.php, and it read 0.4.1 while the module the
// deploy installs said 0.5.0.
define('LRG_VERSION', '0.5.6');
// [0.3.1] 3: lrg_romance gains last_affinity / last_affinity_at (migrations/003), so the glue keeps its
// own copy of CHIM's number - CHIM's restoreNPC deletes the live entry on almost every save reload.
define('LRG_SCHEMA_VERSION', 3);

const LRG_ACT_START    = 'ExtCmdLRG_StartIntimacy';
const LRG_ACT_CONTROL  = 'ExtCmdLRG_SceneControl';
const LRG_ACT_CLOTHING = 'ExtCmdLRG_Clothing';
const LRG_ACT_INVITE   = 'ExtCmdLRG_Invite'; // server-only: recorded by the post-LLM gate, never reaches the game
// [0.3] server-only too: the post-LLM gate resolves an act id into an ordinary ExtCmdLRG_SceneControl@do=goto
// line, so a game on script version 200 needs no new branch (V03_DESIGN 1.3 / 6.4).
const LRG_ACT_REQUESTACT = 'ExtCmdLRG_RequestAct';
// [0.5.4 / pt13] server-EMITTED only, never offered to the model and never in the action catalog: the
// post-LLM gate appends it when she has to leave an engine scene to follow, or has to wait / be let go
// (PROTOCOL 10.20). A line of it coming FROM the model is dropped like any unoffered glue code.
const LRG_ACT_ESCORT = 'ExtCmdLRG_Escort';
// [pt18-quest] the click-free quest entry (lib/lrg_factions.php lrgFacQuestNet, LRG_Main.CmdQuestEntry). A guarded define, not a// const: lrg_factions.php (loaded alone on the fast path) and lrg_actions.php define it the same way until this line is deployed.if (!defined('LRG_ACT_QUESTENTRY')) { define('LRG_ACT_QUESTENTRY', 'ExtCmdLRG_QuestEntry'); }
const LRG_PLAYER_SPEECH_TYPES = ['inputtext', 'inputtext_s', 'ginputtext', 'ginputtext_s'];
/** [0.3] Player speech that CHIM routed to the Narrator (main.php:1077-1079 switches functions off for these). */
const LRG_NARRATOR_SPEECH_TYPES = ['narrator_inputtext', 'narrator_inputtext_s'];
const LRG_INTEREST_WORDS = ['indifferent', 'curious', 'interested', 'drawn'];
/** [0.3] Per-scene keys that live inside the lrg_scene_state payload and survive a push (PROTOCOL 5). */
const LRG_SCENE_MEMORY_KEYS = ['_maxtier', '_tier_since', '_climaxes', '_visited', '_acts_done', '_proposals', '_last_prop_at', '_prop_no', '_sess', '_started_at'];

// ---------------------------------------------------------------- config / log
function lrgConfig(): array
{
    static $cfg = null;
    if ($cfg !== null) { return $cfg; }
    $cfg = json_decode((string) @file_get_contents(LRG_DIR . '/config/lrg_config.default.json'), true) ?: [];
    $userFile = LRG_DIR . '/config/lrg_config.json';
    if (is_file($userFile)) {
        $user = json_decode((string) file_get_contents($userFile), true);
        if (is_array($user)) {
            $cfg = lrgMerge($cfg, $user);
        } else {
            lrgLog('config: lrg_config.json is not valid JSON - using defaults');
        }
    }
    return $cfg;
}

/** Recursive merge where lists (status_rules, patterns) are replaced, maps are merged. */
function lrgMerge(array $base, array $over): array
{
    foreach ($over as $k => $v) {
        if (is_array($v) && isset($base[$k]) && is_array($base[$k]) && !array_is_list($v) && !array_is_list($base[$k])) {
            $base[$k] = lrgMerge($base[$k], $v);
        } else {
            $base[$k] = $v;
        }
    }
    return $base;
}

function lrgEnabled(): bool
{
    $c = lrgConfig();
    return !empty($c['enabled']) && empty($c['kill_switch']);
}

/**
 * [0.3 / G6] Timezone-independent. CHIM sets Europe/Madrid at main.php:11 and
 * npc_master.class.php:1370 flips the process to UTC mid-request without restoring it, so date()
 * produced two different clocks in one log file. The zone comes from the config and the OFFSET is
 * printed, so a future mismatch is visible instead of silent. lrgNow() stays the single time source
 * (the flow tests move it). date_default_timezone_set() is never called here: that would change CHIM.
 */
function lrgLog(string $msg, string $cid = ''): void
{
    static $tz = null;
    if ($tz === null) {
        $name = (string) (lrgConfig()['log_timezone'] ?? 'America/New_York');
        try { $tz = new DateTimeZone($name); } catch (Throwable $e) { $tz = new DateTimeZone('UTC'); }
    }
    $when = (new DateTimeImmutable('@' . lrgNow()))->setTimezone($tz);
    $line = $when->format('Y-m-d H:i:s P') . ' ' . ($cid !== '' ? "[cid=$cid] " : '') . $msg . "\n";
    $dir = dirname(LRG_DIR, 2) . '/log';
    if (!is_dir($dir)) { @mkdir($dir, 0770, true); } // @ alone does not silence it: CHIM installs an error handler
    @file_put_contents($dir . '/lorerim_glue.log', $line, FILE_APPEND);
}

function lrgDb()
{
    return $GLOBALS['db'] ?? null;
}

// ---------------------------------------------------------------- test seams (PROTOCOL 7.2)
/** Every time read of the glue goes through here, so the offline flow tests can move the clock. */
function lrgNow(): int
{
    return (int) ($GLOBALS['LRG_TEST_NOW'] ?? time());
}

/** 1..100. Tests pin a roll with $GLOBALS['LRG_TEST_ROLL'][$what] or ['*']. */
function lrgRoll(string $what): int
{
    $t = $GLOBALS['LRG_TEST_ROLL'] ?? null;
    if (is_array($t)) {
        if (isset($t[$what])) { return (int) $t[$what]; }
        if (isset($t['*'])) { return (int) $t['*']; }
    }
    return random_int(1, 100);
}

// ---------------------------------------------------------------- schema
/**
 * [0.4.0] Does this table really exist in the schema the connection is pinned to?
 *
 * WHY THIS EXISTS: switching CHIM playthrough drops and recreates the WHOLE public schema
 * (HerikaServer/lib/playthrough_schema.php: "DROP SCHEMA IF EXISTS public CASCADE" then "CREATE SCHEMA
 * public"), and lib/postgresql.class.php pins search_path to public on connect. Our tables live in public
 * only, so a playthrough restore takes them with it - while the data/.schema_v* marker FILE survives and
 * made the ensure return early for ever. Every state read then threw (caught and logged) and the glue went
 * silent with no way back short of deleting a dotfile nobody knows about. The marker is now only trusted
 * when the table it claims to have created is actually there.
 * Answers per request, so this is at most one extra query per request and none once the answer is yes.
 */
function lrgTableExists(string $table): bool
{
    static $seen = [];
    if (isset($seen[$table])) { return $seen[$table]; }
    $db = lrgDb();
    if (!$db) { return $seen[$table] = false; }
    try {
        $row = $db->fetchOne("SELECT to_regclass(" . $db->escapeLiteral($table) . ") AS t");
        return $seen[$table] = (is_array($row) && ($row['t'] ?? null) !== null && (string) $row['t'] !== '');
    } catch (Throwable $e) {
        // an unknown failure must not make a migration run on every request: treat it as "present"
        lrgLog('table probe for ' . $table . ' failed: ' . $e->getMessage());
        return $seen[$table] = true;
    }
}

/** Idempotent: runs every migrations/*.sql in name order. The same SQL ships for the package channel. */
function lrgEnsureSchema(): void
{
    $marker = LRG_DIR . '/data/.schema_v' . LRG_SCHEMA_VERSION;
    if (is_file($marker) && lrgTableExists('lrg_npc_state')) { return; }
    $db = lrgDb();
    if (!$db) { return; }
    $files = glob(LRG_DIR . '/migrations/*.sql') ?: [];
    sort($files, SORT_STRING);
    foreach ($files as $file) {
        $sql = (string) preg_replace('/^\s*--.*$/m', '', (string) @file_get_contents($file));
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
            $db->execQuery($stmt);
        }
    }
    @mkdir(LRG_DIR . '/data', 0770, true);
    @file_put_contents($marker, date('c'));
    lrgLog('schema ensured (v' . LRG_SCHEMA_VERSION . ', ' . count($files) . ' migration files)');
}

// ---------------------------------------------------------------- k=v payloads
function lrgParseKv(string $s): array
{
    $out = [];
    foreach (explode(';', $s) as $pair) {
        $p = strpos($pair, '=');
        if ($p === false) { continue; }
        $out[trim(substr($pair, 0, $p))] = trim(substr($pair, $p + 1));
    }
    return $out;
}

function lrgKv(array $a): string
{
    $parts = [];
    foreach ($a as $k => $v) {
        $parts[] = $k . '=' . str_replace([';', '=', '@', '|', '"', "\r", "\n"], ' ', (string) $v);
    }
    return implode(';', $parts);
}

/** Request text without the DLL's "(Context location: ..)" prefix. */
function lrgStripContext(string $text): string
{
    return (string) preg_replace('/^\s*\(Context[^)]*\)\s*/i', '', $text);
}

/** The request text without the prefix, lowercased and trimmed. */
function lrgRequestText(): string
{
    return strtolower(trim(lrgStripContext((string) ($GLOBALS['gameRequest'][3] ?? '')), " \t\r\n\"'.()"));
}

// ---------------------------------------------------------------- state store
function lrgStoreNpcState(string $npc, array $kv): void
{
    $db = lrgDb();
    if (!$db || $npc === '') { return; }
    $kv = lrgCarryFol($npc, $kv);
    $db->upsertRowOnConflict('lrg_npc_state', [
        'npc_name' => $npc,
        'payload' => json_encode($kv),
        'updated_at' => lrgNow(),
    ], 'npc_name');
    lrgForgetNpcState($npc);   // [0.5.1 fix pass] the request memo in lrgGetNpcState() is now stale
    lrgNoteSession($npc, (string) ($kv['sess'] ?? ''));
    lrgMoveWatchCheck($npc, $kv); // [0.5.5 / owner addendum 11 (c)]
}

// ---------------------------------------------------------------- [0.5.5 / owner addendum 11] never silent
/** Code defaults of the `voice` config block (lrgVoiceCfg()); the JSON file carries the same keys. */
const LRG_VOICE_DEFAULTS = [
    // a failure of a command the player is waiting on is SPOKEN in CHIM's own funcret turn
    'enabled' => true,
    // her own move on a scene-lead tick stays told-on-her-next-turn (it fails more often by design: nowarp=1)
    'lead_failures' => false,
    // at most one voiced turn per NPC in this many seconds (a compound request that fails twice speaks once)
    'min_gap_seconds' => 8,
    // the token cap of a voiced turn: one short line
    'max_tokens' => 140,
    // CHIM's own ComeCloser / MoveTo / TravelTo / FollowPlayer, judged by the next snapshots
    'watch' => ['enabled' => true, 'min_seconds' => 6, 'max_seconds' => 30, 'near_units' => 350, 'moved_units' => 100],
];

/** One key of the `voice` config block, dotted path, falling back to LRG_VOICE_DEFAULTS. */
function lrgVoiceCfg(string $path, $default = null)
{
    $cur = (array) (lrgConfig()['voice'] ?? []);
    $def = LRG_VOICE_DEFAULTS;
    foreach (explode('.', $path) as $k) {
        $cur = is_array($cur) && array_key_exists($k, $cur) ? $cur[$k] : null;
        $def = is_array($def) && array_key_exists($k, $def) ? $def[$k] : null;
    }
    return $cur ?? $def ?? $default;
}

/**
 * [0.5.5 / addendum 11 (c)] Judge one of CHIM's own movement orders (lrgMoveWatchNote) on the snapshot that
 * just arrived - pre-lock, no LLM, at most one memory write. Evidence of FAILURE, after watch.min_seconds:
 * she is still in an engine scene (the thing that outranks every package, pt13), or - for ComeCloser /
 * FollowPlayer, whose target is the player - she is still more than near_units away and not moved_units
 * closer than when she was told. Evidence of success, or no evidence within max_seconds, ends the watch
 * in silence. A failure becomes `missed`, which her next player turn is told (lrgMissedNote).
 */
function lrgMoveWatchCheck(string $npc, array $kv): void
{
    if ($npc === '' || !lrgEnabled()) { return; }
    $mem = lrgMemGet($npc);
    $w = $mem['move_watch'] ?? null;
    if (!is_array($w)) { return; }
    $age = lrgNow() - (int) ($w['at'] ?? 0);
    $code = (string) ($w['code'] ?? '');
    $cid = (string) ($w['cid'] ?? '');
    if ($age > (int) lrgVoiceCfg('watch.max_seconds', 30)) {
        lrgMemSet($npc, ['move_watch' => null]);
        lrgLog("watch: $code npc=$npc - no evidence either way within {$age}s, nothing is said", $cid);
        return;
    }
    if ($age < (int) lrgVoiceCfg('watch.min_seconds', 6)) { return; }
    $scene = (string) ($kv['scene'] ?? '0') === '1';
    $dist = isset($kv['dist']) && is_numeric($kv['dist']) ? (int) $kv['dist'] : null;
    $dist0 = isset($w['dist0']) && is_numeric($w['dist0']) ? (int) $w['dist0'] : null;
    $near = (int) lrgVoiceCfg('watch.near_units', 350);
    $moved = (int) lrgVoiceCfg('watch.moved_units', 100);
    $toPlayer = in_array(strtolower(str_replace('_', '', $code)), ['comecloser', 'followplayer'], true);
    $why = '';
    if ($scene) { $why = 'still caught up in what was going on there'; }
    elseif ($toPlayer && $dist !== null && $dist > $near && ($dist0 === null || $dist >= $dist0 - $moved)) { $why = 'did not move at all'; }
    if ($why === '') {
        // she is free of any scene; for an order toward the player, she is near him or clearly closer
        if (!$toPlayer || $dist === null || $dist <= $near || ($dist0 !== null && $dist < $dist0 - $moved)) {
            lrgMemSet($npc, ['move_watch' => null]);
            lrgLog("watch: $code npc=$npc - she moved (scene=0" . ($dist !== null ? ", dist $dist" : '') . ')', $cid);
        }
        return;
    }
    $player = trim((string) ($GLOBALS['PLAYER_NAME'] ?? '')) ?: 'the player';
    $what = ['comecloser' => "come closer to $player", 'followplayer' => "go with $player", 'moveto' => 'go over there',
        'travelto' => 'set off on the way'][strtolower(str_replace('_', '', $code))] ?? 'move';
    lrgMemSet($npc, ['move_watch' => null, 'missed' => ['what' => $what, 'why' => $why, 'at' => lrgNow(), 'code' => $code]]);
    lrgLog(sprintf('watch: %s npc=%s FAILED after %ds (%s; scene=%d dist=%s was %s) - told on her next player turn', $code, $npc, $age, $why,
        $scene ? 1 : 0, $dist === null ? '-' : (string) $dist, $dist0 === null ? '-' : (string) $dist0), $cid);
}

/**
 * [0.5.4 / pt13] THE LAST KNOWN fol= OF THIS GAME SESSION, carried inside the snapshot row.
 *
 * Game script 504/505 retires the whole follower block for the rest of the session the moment its
 * snapshot abort rail THINKS a snapshot died in it - and playtest 13 proved that rail fires falsely
 * (six "SNAPSHOT ABORTED" lines, not one Papyrus error in LRG_Main / LRG_Profile / LRG_Followers).
 * From then on every snapshot arrived without fol=, and to the server that read as "nothing is known
 * about followers": the companion block, the hide rule and (0.5.4) the escort's "never for somebody
 * else's follower" rail all went quiet for the session.
 *
 * So the server keeps what the game last said, keyed on the session tag: a snapshot WITH fol= stamps
 * `_fol_last` / `_fol_at` / `_fol_sess`; a snapshot WITHOUT it inherits those three keys from the
 * previous row when that row belongs to the SAME session. lrgFolState() then reads the remembered
 * value (marked `_remembered`) for at most followers.remember_seconds.
 * A snapshot with no `sess` at all (game script 200, the offline fixtures) carries nothing - without
 * a session tag nobody can say whether the facts still belong to the game that is running. A reload
 * re-rolls the tag, so a follower dismissed in another save is never remembered into this one.
 * One SELECT only when fol= is missing (the request memo usually answers it for free).
 */
function lrgCarryFol(string $npc, array $kv): array
{
    unset($kv['_fol_last'], $kv['_fol_at'], $kv['_fol_sess']);   // never trusted from the wire
    $sess = trim((string) ($kv['sess'] ?? ''));
    if ($sess === '') { return $kv; }
    $fol = trim((string) ($kv['fol'] ?? ''));
    if ($fol !== '') {
        return $kv + ['_fol_last' => $fol, '_fol_at' => lrgNow(), '_fol_sess' => $sess];
    }
    try { $prev = lrgGetNpcState($npc); } catch (Throwable $e) { $prev = null; }
    if (!is_array($prev) || trim((string) ($prev['sess'] ?? '')) !== $sess) { return $kv; }
    $last = trim((string) ($prev['fol'] ?? ''));
    $at = lrgNow() - (int) ($prev['_age'] ?? 0);
    if ($last === '') {
        $last = trim((string) ($prev['_fol_last'] ?? ''));
        $at = (int) ($prev['_fol_at'] ?? 0);
        if ((string) ($prev['_fol_sess'] ?? '') !== $sess) { $last = ''; }
    }
    if ($last === '' || $at <= 0) { return $kv; }
    return $kv + ['_fol_last' => $last, '_fol_at' => $at, '_fol_sess' => $sess];
}

/**
 * [0.3 / G4 path 2] The game re-rolls LRG_Main.sessionTag on every load and puts it on every snapshot
 * and every scene push. A snapshot whose sess differs from the open scene row's _sess proves the game
 * was reloaded: close EVERY active row (name-free - the reload may land on a save whose partner was
 * somebody else, which is exactly playtest 6). session_at also feeds the post-reload cooldown (11.1).
 * A missing sess key (game script 200) means "say nothing": nothing is closed and nothing is stamped.
 */
function lrgNoteSession(string $npc, string $sess): void
{
    if ($sess === '' || $npc === '') { return; }
    $reloaded = false;
    $row = lrgGetActiveSceneRaw();
    if ($row !== null && (string) ($row['_sess'] ?? '') !== '' && (string) $row['_sess'] !== $sess) {
        lrgCloseAllScenes('the game was reloaded (session ' . $row['_sess'] . ' -> ' . $sess . ')');
        $reloaded = true;
    }
    $mem = lrgMemGet($npc);
    $had = (string) ($mem['sess'] ?? '');
    if ($had === $sess) { return; }
    // A FIRST MEETING is not a reload. Without a previous tag for this NPC, and without an open row from
    // another session proving the game really was reloaded, there is nothing that could have been
    // reloaded - and stamping session_at anyway held her own first move and her first initiative tick back
    // for start.post_reload_cooldown_seconds the very first time this NPC was ever snapshotted.
    if ($had === '' && !$reloaded) { lrgMemSet($npc, ['sess' => $sess]); return; }
    lrgMemSet($npc, ['sess' => $sess, 'session_at' => lrgNow()]);
}

function lrgStoreScene(string $npc, array $kv): void
{
    $db = lrgDb();
    if (!$db || $npc === '') { return; }
    $kv = lrgTrackProgression($npc, $kv);
    $db->upsertRowOnConflict('lrg_scene_state', [
        'npc_name' => $npc,
        'payload' => json_encode($kv),
        'active' => (($kv['ev'] ?? '') === 'end') ? 0 : 1,
        'updated_at' => lrgNow(),
    ], 'npc_name');
}

/**
 * Progression memory of ONE scene, kept inside the scene row's payload:
 *   _maxtier    highest content tier (0..4) reached so far in this scene
 *   _tier_since when that tier was first reached (the pace clock)
 *   _climaxes   number of ev=climax messages in this thread
 * [0.3] plus the anti-circling and ask-first memory of ONE scene: _visited, _acts_done, _proposals,
 * _last_prop_at, _prop_no, and the session stamp _sess. All of them reset with the scene.
 * Reset when a new scene starts, the previous row was closed, or the correlation id changes.
 */
function lrgTrackProgression(string $npc, array $kv): array
{
    $db = lrgDb();
    $tier = 0;
    if (function_exists('lrgScene') && ($s = lrgScene((string) ($kv['scene'] ?? '')))) { $tier = (int) (LRG_TIERS[$s['tier']] ?? 0); }
    $prev = null;
    $row = $db->fetchOne("SELECT payload, active FROM lrg_scene_state WHERE npc_name=" . $db->escapeLiteral($npc));
    if ($row && (int) ($row['active'] ?? 0) === 1) { $prev = json_decode((string) $row['payload'], true) ?: null; }
    $newScene = ($kv['ev'] ?? '') === 'start' || $prev === null || !isset($prev['_maxtier'])
        || (($kv['cid'] ?? '') !== '' && ($prev['cid'] ?? '') !== '' && $kv['cid'] !== $prev['cid']);
    if ($newScene || $tier > (int) $prev['_maxtier']) {
        $kv['_maxtier'] = $tier; $kv['_tier_since'] = lrgNow();
    } else {
        $kv['_maxtier'] = (int) $prev['_maxtier']; $kv['_tier_since'] = (int) ($prev['_tier_since'] ?? lrgNow());
    }
    // [0.3.1 / R3-O9] _tier_since is the clock of the CURRENT tier, not of the scene: using it as the
    // duration is why a 2.5-minute scene was summarised as "about a minute". _started_at is stamped once,
    // when the scene really begins, and is what the outro and the R1b scene gain measure against.
    if (!array_key_exists('_started_at', $kv)) {
        $kv['_started_at'] = $newScene ? lrgNow() : (int) ($prev['_started_at'] ?? lrgNow());
    }
    $kv['_climaxes'] = ($newScene ? 0 : (int) ($prev['_climaxes'] ?? 0)) + ((($kv['ev'] ?? '') === 'climax') ? 1 : 0);
    // [0.3] the rest of the per-scene memory is carried over verbatim unless the caller already set it
    foreach (['_visited' => [], '_acts_done' => [], '_proposals' => 0, '_last_prop_at' => 0, '_prop_no' => []] as $k => $empty) {
        if (array_key_exists($k, $kv)) { continue; }
        $kv[$k] = $newScene ? $empty : ($prev[$k] ?? $empty);
    }
    if (!array_key_exists('_sess', $kv)) {
        $sess = (string) ($kv['sess'] ?? '');
        $kv['_sess'] = $sess !== '' ? $sess : (string) ($newScene ? '' : ($prev['_sess'] ?? ''));
    }
    return $kv;
}

/** slow | normal | eager: explicit on the profile (status rule / npc override), else from strictness. */
function lrgPace(array $profile): string
{
    $p = strtolower((string) ($profile['pace'] ?? ''));
    if (in_array($p, ['slow', 'normal', 'eager'], true)) { return $p; }
    $s = strtolower((string) ($profile['strictness'] ?? 'moderate'));
    if (str_contains($s, 'strict') || $s === 'guarded') { return 'slow'; }
    return in_array($s, ['relaxed', 'open'], true) ? 'eager' : 'normal';
}

/** Speech manner: profile / override "talk", else strict -> quiet, relaxed / open -> vocal, otherwise normal. */
function lrgTalkStyle(array $profile): string
{
    $t = strtolower((string) ($profile['talk'] ?? ''));
    if (in_array($t, ['quiet', 'normal', 'vocal', 'crude', 'romantic', 'never'], true)) { return $t; }
    $s = strtolower((string) ($profile['strictness'] ?? 'moderate'));
    if (str_contains($s, 'strict') || $s === 'guarded') { return 'quiet'; }
    return in_array($s, ['relaxed', 'open'], true) ? 'vocal' : 'normal';
}

/**
 * Highest tier that may be offered now: the highest tier reached in THIS scene, plus one step once the NPC's
 * pace time at that tier has passed - or at once when the player asks aloud (config) - never more than one step.
 */
function lrgTierCeiling(array $scene, array $profile, bool $playerAsked): array
{
    $cfg = lrgConfig()['scene_progression'] ?? [];
    $max = max(0, min(4, (int) ($scene['_maxtier'] ?? 2)));
    $pace = lrgPace($profile);
    $need = (int) (($cfg['pace_seconds'] ?? [])[$pace] ?? ['slow' => 90, 'normal' => 45, 'eager' => 20][$pace]);
    $open = (lrgNow() - (int) ($scene['_tier_since'] ?? lrgNow())) >= $need || ($playerAsked && ($cfg['player_request_skips_wait'] ?? true));
    return ['reached' => $max, 'ceiling' => min(4, $max + ($open ? 1 : 0)), 'pace' => $pace, 'open' => $open];
}

/**
 * The NPC's last snapshot, MEMOISED FOR THE REQUEST (0.5.1 fix pass). It cannot change inside one
 * request - only lrgStoreNpcState() writes the row, and it drops this NPC's memo when it does - so
 * every caller after the first is free. It matters because 0.5.1 added two more call sites on request
 * types that previously read no state at all (lrgFollowerPolicy() from functions.php at brace depth 0
 * and lrgFolFor() from context_pre.php), i.e. one SELECT each on EVERY request, against CHIM's own
 * sql_ms of 5-13 ms. `_age` is recomputed on every call, so a test that moves LRG_TEST_NOW still sees
 * the snapshot age it expects.
 */
function lrgGetNpcState(string $npc): ?array
{
    if (!array_key_exists($npc, (array) ($GLOBALS['LRG_NPCSTATE_MEMO'] ?? []))) {
        $db = lrgDb();
        if (!$db) { return null; }   // no DB: nothing to memoise, and the call is already free
        $row = $db->fetchOne("SELECT payload, updated_at FROM lrg_npc_state WHERE npc_name=" . $db->escapeLiteral($npc));
        $hit = null;
        if ($row) {
            $kv = json_decode((string) $row['payload'], true) ?: [];
            // Serana Dialogue Expansion marries her to the player through its own faction, not the vanilla one
            $sdeMarried = (string) (lrgConfig()['sde_married_faction'] ?? 'SDE_RMarriedFaction');
            if ($sdeMarried !== '' && stripos(',' . ($kv['fac'] ?? '') . ',', ',' . $sdeMarried . ',') !== false) { $kv['pspouse'] = '1'; }
            $hit = ['kv' => $kv, 'at' => (int) $row['updated_at']];
        }
        $GLOBALS['LRG_NPCSTATE_MEMO'][$npc] = $hit;
    }
    $hit = $GLOBALS['LRG_NPCSTATE_MEMO'][$npc];
    if (!is_array($hit)) { return null; }
    $kv = (array) $hit['kv'];
    $kv['_age'] = lrgNow() - (int) $hit['at'];
    return $kv;
}

/** Drop the request memo for one NPC (or all of them). Called by every writer of lrg_npc_state. */
function lrgForgetNpcState(string $npc = ''): void
{
    if ($npc === '') { $GLOBALS['LRG_NPCSTATE_MEMO'] = []; return; }
    unset($GLOBALS['LRG_NPCSTATE_MEMO'][$npc]);
}

/**
 * [0.3] The newest active scene row with NO side effects and NO staleness rule: the raw fact the
 * session check and the close helpers need. lrgGetActiveScene() is the one that also judges it.
 */
function lrgGetActiveSceneRaw(): ?array
{
    $db = lrgDb();
    if (!$db) { return null; }
    $row = $db->fetchOne("SELECT npc_name, payload, updated_at FROM lrg_scene_state WHERE active=1 AND updated_at > -1 ORDER BY updated_at DESC LIMIT 1");
    if (!$row || (int) ($row['active'] ?? 1) === 0) { return null; }
    $kv = json_decode((string) ($row['payload'] ?? ''), true) ?: [];
    $kv['_npc'] = (string) ($row['npc_name'] ?? '');
    $kv['_age'] = lrgNow() - (int) ($row['updated_at'] ?? 0);
    return $kv['_npc'] === '' ? null : $kv;
}

/**
 * [0.3 / G4] Close THIS NPC's own open scene row (selected by npc_name, so a newer stale row of
 * another NPC cannot hide it). Returns the number of rows closed (0 or 1).
 */
function lrgCloseScenesFor(string $npc, string $why): int
{
    $db = lrgDb();
    if (!$db || $npc === '') { return 0; }
    $row = $db->fetchOne("SELECT payload, active FROM lrg_scene_state WHERE npc_name=" . $db->escapeLiteral($npc));
    if (!$row || (int) ($row['active'] ?? 0) !== 1) { return 0; }
    $kv = json_decode((string) ($row['payload'] ?? ''), true) ?: [];
    lrgStoreScene($npc, ['ev' => 'end'] + $kv);
    lrgLog("scene row for $npc closed: $why");
    return 1;
}

/**
 * [0.3 / G4] Close EVERY open scene row, name-free. Only fetchOne is used (PROTOCOL 5): closing the
 * newest row makes the next read return the one behind it, so the loop terminates by construction.
 */
function lrgCloseAllScenes(string $why): int
{
    $n = 0;
    while ($n < 10 && ($row = lrgGetActiveSceneRaw()) !== null) {
        $npc = (string) $row['_npc'];
        unset($row['_npc'], $row['_age']);
        lrgStoreScene($npc, ['ev' => 'end'] + $row);
        lrgLog("scene row for $npc closed: $why");
        $n++;
    }
    return $n;
}

/** The running player scene, if any (there is only ever one: OStim thread 0). */
function lrgGetActiveScene(): ?array
{
    $db = lrgDb();
    if (!$db) { return null; }
    $stale = (int) (lrgConfig()['scene_stale_seconds'] ?? 900);
    $row = $db->fetchOne("SELECT npc_name, payload, updated_at FROM lrg_scene_state WHERE active=1 AND updated_at > " . (lrgNow() - $stale) . " ORDER BY updated_at DESC LIMIT 1");
    if (!$row) { return null; }
    if ((int) ($row['updated_at'] ?? 0) <= lrgNow() - $stale) { return null; } // also holds when the store cannot filter by time
    $kv = json_decode((string) $row['payload'], true) ?: [];
    // The "end" message can be lost (crash, reload, kill switch). A snapshot of the same NPC
    // that is newer than the scene row and says "not in our scene" (ostim=0) proves the scene
    // is over: close the row instead of claiming a live scene for scene_stale_seconds.
    $st = lrgGetNpcState((string) $row['npc_name']);
    if ($st && ($st['ostim'] ?? '') === '0' && (int) $st['_age'] + 2 < lrgNow() - (int) $row['updated_at']) {
        lrgStoreScene((string) $row['npc_name'], ['ev' => 'end'] + $kv);
        lrgLog('scene row for ' . $row['npc_name'] . ' closed: a newer snapshot says the scene is over');
        return null;
    }
    $kv['_npc'] = $row['npc_name'];
    $kv['_age'] = lrgNow() - (int) $row['updated_at'];
    return $kv;
}

function lrgRomance(string $npc): array
{
    $db = lrgDb();
    $row = $db ? $db->fetchOne("SELECT * FROM lrg_romance WHERE npc_name=" . $db->escapeLiteral($npc)) : null;
    // [0.3.1] last_affinity / last_affinity_at come from migration 003; the defaults keep an older row
    // (and the offline fakes) readable without a special case at every call site.
    return ($row ?: []) + ['npc_name' => $npc, 'stage' => 0, 'scenes' => 0, 'refusals' => 0, 'gold_accepted' => 0,
        'last_scene_at' => 0, 'last_refusal_at' => 0, 'last_affinity' => 0, 'last_affinity_at' => 0];
}

function lrgRomanceBump(string $npc, string $field, int $by = 1): void
{
    $db = lrgDb();
    if (!$db || !in_array($field, ['stage', 'scenes', 'refusals', 'gold_accepted'], true)) { return; }
    $r = lrgRomance($npc);
    $r[$field] = (int) ($r[$field] ?? 0) + $by;
    if ($field === 'scenes') { $r['last_scene_at'] = lrgNow(); }
    if ($field === 'refusals') { $r['last_refusal_at'] = lrgNow(); }
    unset($r['id']);
    $db->upsertRowOnConflict('lrg_romance', $r, 'npc_name');
}

/**
 * [0.3.1 / R1c] Write named columns of lrg_romance without touching the counters. Used for the glue's
 * OWN copy of CHIM's affinity, which is the whole point of R1c: `restoreNPC` (npc_master.class.php:1520,
 * chimRelationshipRestoreQuery) rewrites extended_data.relationships from a history row on EVERY game
 * load, and a history row without the key DELETES the live entry. lrg_romance survived all of that in
 * playtest 7, so it is the store that can be trusted across a reload.
 */
function lrgRomanceSet(string $npc, array $patch): void
{
    $db = lrgDb();
    if (!$db || $npc === '' || !$patch) { return; }
    $r = lrgRomance($npc);
    $changed = false;
    foreach ($patch as $k => $v) {
        if (!in_array($k, ['stage', 'scenes', 'refusals', 'gold_accepted', 'last_scene_at', 'last_refusal_at', 'last_affinity', 'last_affinity_at'], true)) { continue; }
        if ((string) ($r[$k] ?? '') === (string) $v) { continue; }
        $r[$k] = $v;
        $changed = true;
    }
    if (!$changed) { return; }
    unset($r['id']);
    $db->upsertRowOnConflict('lrg_romance', $r, 'npc_name');
}

// ---------------------------------------------------------------- per-NPC memory (lrg_memory, PROTOCOL 5)
/** Payload of lrg_memory for this NPC, [] when none. Keys: interest, interest_score, invite, initiative_at, last_result, lead_idle. */
function lrgMemGet(string $npc): array
{
    $db = lrgDb();
    if (!$db || $npc === '') { return []; }
    $row = $db->fetchOne("SELECT payload FROM lrg_memory WHERE npc_name=" . $db->escapeLiteral($npc));
    if (!$row || !isset($row['payload'])) { return []; }
    $p = is_array($row['payload']) ? $row['payload'] : json_decode((string) $row['payload'], true);
    return is_array($p) ? $p : [];
}

/** Shallow merge into the payload; a null value removes the key. */
function lrgMemSet(string $npc, array $patch): void
{
    $db = lrgDb();
    if (!$db || $npc === '') { return; }
    $cur = lrgMemGet($npc);
    $new = $cur;
    foreach ($patch as $k => $v) {
        if ($v === null) { unset($new[$k]); } else { $new[$k] = $v; }
    }
    if ($new == $cur) { return; } // nothing changed: no write
    $db->upsertRowOnConflict('lrg_memory', [
        'npc_name' => $npc,
        'payload' => json_encode($new === [] ? new stdClass() : $new),
        'updated_at' => lrgNow(),
    ], 'npc_name');
}

/**
 * The invitation this NPC made, if it still stands (PROTOCOL 5): dropped when it expired or when the NPC is
 * no longer willing. Returns the invite array (state pending | followed) or null.
 */
function lrgInviteGet(string $npc, bool $willing, ?array $mem = null): ?array
{
    $mem = $mem ?? lrgMemGet($npc);
    $inv = $mem['invite'] ?? null;
    if (!is_array($inv)) { return null; }
    if (!$willing || lrgNow() > (int) ($inv['expires'] ?? 0)) {
        lrgMemSet($npc, ['invite' => null]);
        lrgLog("invite of $npc dropped: " . ($willing ? 'expired' : 'no longer willing'));
        return null;
    }
    return $inv;
}

// ---------------------------------------------------------------- profiles
function lrgGlob(string $pattern, string $value): bool
{
    return fnmatch(strtolower($pattern), strtolower($value));
}

function lrgAnyGlob(array $patterns, array $values): bool
{
    foreach ($patterns as $p) {
        foreach ($values as $v) {
            if ($v !== '' && lrgGlob($p, $v)) { return true; }
        }
    }
    return false;
}

/** Turn the game's hard facts into a strictness profile using the data-driven rules. */
function lrgBuildProfile(string $npc, array $state): array
{
    $cfg = lrgConfig();
    $factions = array_filter(explode(',', (string) ($state['fac'] ?? '')));
    $class = (string) ($state['class'] ?? '');
    $profile = null;

    foreach (($cfg['status_rules'] ?? []) as $rule) {
        $m = $rule['match'] ?? [];
        $hit = (!empty($m['factions_any']) && lrgAnyGlob($m['factions_any'], $factions))
            || (!empty($m['class_any']) && $class !== '' && lrgAnyGlob($m['class_any'], [$class]))
            || (!empty($m['name_any']) && lrgAnyGlob($m['name_any'], [$npc]));
        if ($hit) { $profile = $rule; break; }
    }
    if ($profile === null) {
        // unknown factions (mod-added): derive from vanilla actor values, never fail
        $fb = $cfg['fallback'] ?? [];
        $moral = max(0, min(3, (int) ($state['moral'] ?? 2)));
        $profile = $fb;
        $profile['id'] = 'fallback';
        $profile['min_affinity'] = (int) ($fb['base_min_affinity'] ?? 25) + $moral * (int) ($fb['per_morality_point'] ?? 7);
        $profile['strictness'] = $moral >= 3 ? 'strict' : ($moral <= 1 ? 'relaxed' : 'moderate');
    }
    $override = $cfg['npc_overrides'][$npc] ?? null;
    if (is_array($override)) {
        $profile = lrgMerge($profile, $override);
        $profile['id'] = ($profile['id'] ?? 'rule') . '+override';
    }
    $profile['privacy'] = ($profile['privacy'] ?? []) + ($cfg['privacy_defaults'] ?? ['max_witnesses' => 0, 'followers_ok' => true]);
    $profile['min_affinity'] = (int) ($profile['min_affinity'] ?? 25);
    return $profile;
}

// ---------------------------------------------------------------- [0.3.1 / R1] CHIM relationships
/** HerikaServer's engine root with a trailing slash, '' when this is not a live server (tests, CLI). */
function lrgEnginePath(): string
{
    $p = (string) ($GLOBALS['ENGINE_PATH'] ?? (defined('ENGINE_PATH') ? (string) constant('ENGINE_PATH') : ''));
    return $p === '' ? '' : rtrim(str_replace('\\', '/', $p), '/') . '/';
}

/**
 * [0.3.1 / R1a - the headline defect of playtest 7] Make RelationshipManager available to US.
 *
 * lrgAffinity() was guarded by class_exists('RelationshipManager'), and that class is loaded ONLY by
 * ext/relationship_system/context_pre.php:32, which main.php requires at :2540 - AFTER the ext prerequest
 * hook (:1117) where lrgPrepareTurn() runs, and requireFilesRecursively walks scandir order, so our own
 * context_pre runs before theirs too. The guard was therefore false at every call site the gate uses: the
 * CHIM term was permanently 0 and every aff= the glue ever logged was snapshot bonuses only (proven in
 * research/pt7-relationship.md: glue logged aff=0 at 17:27-17:28 while CHIM's stored value was +2).
 *
 * Loading it ourselves is safe: both files are plain class definitions with require_once guards, and
 * relationship_system loads them again later without effect. The class_exists guard stays around every
 * call so the offline tests (which define their own stand-in, PROTOCOL 7.3) keep working.
 */
function lrgRelationshipReady(): bool
{
    static $tried = false;
    if (class_exists('RelationshipManager')) { return true; }
    if ($tried) { return false; }
    $tried = true;
    $base = lrgEnginePath();
    if ($base === '') { return false; }
    foreach (['lib/core/npc_master.class.php', 'lib/relationship_manager.php'] as $rel) {
        $f = $base . $rel;
        if (!is_file($f)) { lrgLog("relationship: $rel not found under $base - CHIM affinity stays unread"); return false; }
        try { require_once $f; } catch (Throwable $e) { lrgLog('relationship: loading ' . $rel . ' failed: ' . $e->getMessage()); return false; }
    }
    return class_exists('RelationshipManager');
}

/**
 * [0.3.1 / R1c] CHIM's stored affinity for this NPC towards the player, and - the part
 * getRelationship() cannot express - whether an entry EXISTS at all.
 * RelationshipManager::getRelationship() returns the default ['aff'=>0,'type'=>'neutral'] both when the
 * entry is missing and when it is genuinely 0 (relationship_manager.php:687-703), so a wiped entry became
 * gate reason not_close_enough with nothing in the log to say so. getRelationships() + isset() separates
 * the two. The player's key is always the literal string 'Player' (normalizeTargetName, :186-213).
 * Returns ['found' => bool, 'aff' => int, 'type' => string].
 */
function lrgChimRelationship(string $npc): array
{
    $out = ['found' => false, 'aff' => 0, 'type' => ''];
    if ($npc === '' || !lrgRelationshipReady()) { return $out; }
    try {
        if (method_exists('RelationshipManager', 'getRelationships')) {
            $rels = RelationshipManager::getRelationships($npc);
            if (is_array($rels) && isset($rels['Player']) && is_array($rels['Player'])) {
                return ['found' => true, 'aff' => (int) ($rels['Player']['aff'] ?? 0), 'type' => (string) ($rels['Player']['type'] ?? '')];
            }
            return $out; // the NPC row could not be resolved, or there is no entry for the player
        }
        // a stand-in that only implements the v0.2 reader (the offline flow tests): treat it as present
        $rel = RelationshipManager::getPlayerRelationship($npc);
        if (is_array($rel) && isset($rel['aff'])) { return ['found' => true, 'aff' => (int) $rel['aff'], 'type' => (string) ($rel['type'] ?? '')]; }
    } catch (Throwable $e) {
        lrgLog('relationship lookup failed: ' . $e->getMessage());
    }
    return $out;
}

/**
 * [0.3.1 / R1c] The affinity the gate really uses, with every component named so one log line explains it.
 *
 * chim   CHIM's own number, or null when there is NO entry (not the same as 0)
 * lrg    our last known good value from lrg_romance (survives restoreNPC; see lrgRomanceSet)
 * used   what the gate works with
 * rescue '' | 'missing' (CHIM has no entry) | 'zeroed' (CHIM says 0 but we remember better)
 * The vanilla inputs are added on top exactly as in 0.3 and are never written back to the game.
 */
function lrgAffinityInfo(string $npc, array $state): array
{
    $cfg = lrgConfig()['affinity'] ?? [];
    $chim = lrgChimRelationship($npc);
    $rom = lrgRomance($npc);
    $last = (int) ($rom['last_affinity'] ?? 0);
    $rescue = '';
    if ($chim['found']) {
        $base = (int) $chim['aff'];
        if ($base === 0 && $last > 0 && ($cfg['trust_our_last_known'] ?? true)) { $base = $last; $rescue = 'zeroed'; }
        elseif ($base !== 0) { lrgRomanceSet($npc, ['last_affinity' => $base, 'last_affinity_at' => lrgNow()]); }
    } else {
        $base = ($last !== 0 && ($cfg['trust_our_last_known'] ?? true)) ? $last : 0;
        if ($base !== 0) { $rescue = 'missing'; }
    }
    $rank = (int) ($state['rank'] ?? 0) * (int) ($cfg['vanilla_rank_bonus_per_point'] ?? 6);
    $courting = (($state['courting'] ?? '0') === '1') ? (int) ($cfg['courting_bonus'] ?? 10) : 0;
    $spouse = (($state['pspouse'] ?? '0') === '1') ? (int) ($cfg['player_spouse_bonus'] ?? 60) : 0;
    $used = max(-100, min(100, $base + $rank + $courting + $spouse));
    if ($rescue !== '' && ($cfg['log_rescue'] ?? true)) {
        lrgLog(sprintf('affinity rescued for %s: CHIM %s, our last known %d (used %d)', $npc,
            $rescue === 'missing' ? 'has no entry for the player' : 'reads 0', $last, $used));
    }
    return ['used' => $used, 'chim' => $chim['found'] ? (int) $chim['aff'] : null, 'lrg' => $last,
        'rescue' => $rescue, 'rank' => $rank, 'courting' => $courting, 'spouse' => $spouse, 'type' => (string) $chim['type']];
}

/** CHIM affinity (-100..100) plus read-only vanilla inputs. Vanilla rank is never written. */
function lrgAffinity(string $npc, array $state): int
{
    return (int) lrgAffinityInfo($npc, $state)['used'];
}

/**
 * [0.3.1 / R1b] The owner's manual lock on an NPC's relationships. CHIM honours `relationships_locked`
 * ONLY in the RelationshipLLM (relationship_llm.php:638, :1625 and ext/relationship_system/postrequest
 * .php:219); RelationshipManager::setRelationship / adjustRelationship / parseChanges do NOT check it.
 * So our ext tests it itself before every write - otherwise the glue would quietly overrule an edit the
 * owner made in the NPC manager.
 */
function lrgRelationshipLocked(string $npc): bool
{
    $ext = null;
    $cur = $GLOBALS['CHIM_CORE_CURRENT_NPC_DATA'] ?? null;
    if (is_array($cur) && strcasecmp((string) ($cur['npc_name'] ?? ''), $npc) === 0) { $ext = $cur['extended_data'] ?? null; }
    if ($ext === null) {
        $db = lrgDb();
        $row = $db ? $db->fetchOne('SELECT extended_data FROM core_npc_master WHERE npc_name=' . $db->escapeLiteral($npc)) : null;
        $ext = is_array($row) ? ($row['extended_data'] ?? null) : null;
    }
    if (is_string($ext)) { $ext = json_decode($ext, true); }
    return is_array($ext) && !empty($ext['relationships_locked']);
}

/**
 * [0.3.1 / R1b] Move CHIM's own affinity by $delta, through CHIM's own API, and remember the result.
 *
 * Rules, all of them from research/pt7-relationship.md section 2:
 *  - NEVER pass a $type. adjustRelationship() preserves the existing one, creates the entry when it is
 *    absent and clamps to -100..100. CHIM reserves the romantic-leaning types and blocks promotion into
 *    them below the Fond tier; the glue has no business touching that.
 *  - the owner's `relationships_locked` is honoured here, because CHIM's own setters do not.
 *  - this must run inside a LIVE GAME TURN: writing goes through chimRelationshipTimelineStamp, which
 *    stamps core_npc_master.gamets_last_updated from $GLOBALS['gameRequest'][2] and snapshots to
 *    history. A write with no game timestamp lands on the wrong timeline and the next reload rolls it
 *    straight back.
 * Returns the new affinity, or null when nothing was written.
 */
function lrgAdjustAffinity(string $npc, int $delta, string $why): ?int
{
    if ($npc === '' || $delta === 0) { return null; }
    if (!lrgRelationshipReady() || !method_exists('RelationshipManager', 'adjustRelationship')) {
        lrgLog("relationship: cannot apply $delta to $npc ($why) - RelationshipManager is not available");
        return null;
    }
    if (lrgRelationshipLocked($npc)) {
        lrgLog("relationship: $npc is locked in the NPC manager - the $delta for $why was NOT applied");
        return null;
    }
    $before = lrgChimRelationship($npc);
    try {
        $ok = RelationshipManager::adjustRelationship($npc, 'Player', $delta);
    } catch (Throwable $e) {
        lrgLog('relationship: adjust failed for ' . $npc . ': ' . $e->getMessage());
        return null;
    }
    if (!$ok) { lrgLog("relationship: CHIM refused the $delta for $npc ($why) - the NPC could not be resolved"); return null; }
    $after = lrgChimRelationship($npc);
    $now = $after['found'] ? (int) $after['aff'] : (int) $before['aff'] + $delta;
    lrgRomanceSet($npc, ['last_affinity' => $now, 'last_affinity_at' => lrgNow()]);
    lrgLog(sprintf('relationship: %s -> Player %+d (%s, was %s, now %d, type %s kept)', $npc, $delta, $why,
        $before['found'] ? (string) $before['aff'] : 'no entry', $now, $before['type'] !== '' ? $before['type'] : 'neutral'));
    return $now;
}

/**
 * [0.3.1 / R1d] The one-time repair of an affinity CHIM's own restoreNPC destroyed. Config driven
 * (`relationship.repair`), guarded, idempotent and logged; it does nothing at all unless the owner's
 * config names the NPC. It runs from a live turn, never from the CLI, for the timeline reason above.
 * The marker lives in lrg_memory, so a second run is impossible even across a restart.
 */
function lrgMaybeRepairAffinity(string $npc): void
{
    $cfg = (array) ((lrgConfig()['relationship'] ?? [])['repair'] ?? []);
    if ($npc === '' || empty($cfg['enabled']) || strcasecmp((string) ($cfg['npc'] ?? ''), $npc) !== 0) { return; }
    $mem = lrgMemGet($npc);
    if (!empty($mem['affinity_repaired_at'])) { return; }
    $target = (int) ($cfg['affinity'] ?? 0);
    if (!lrgRelationshipReady() || !method_exists('RelationshipManager', 'setRelationship')) { return; }
    if (lrgRelationshipLocked($npc)) {
        lrgMemSet($npc, ['affinity_repaired_at' => lrgNow()]);
        lrgLog("relationship repair: $npc is locked in the NPC manager - nothing written");
        return;
    }
    $before = lrgChimRelationship($npc);
    if ($before['found'] && (int) $before['aff'] >= $target) {
        lrgMemSet($npc, ['affinity_repaired_at' => lrgNow()]);
        lrgLog(sprintf('relationship repair: %s already reads %d (>= %d) - nothing written', $npc, (int) $before['aff'], $target));
        return;
    }
    try {
        // no $type: setRelationship creates the entry as neutral when it is absent and leaves an
        // existing type alone when null is passed (relationship_manager.php:983-1009)
        $ok = RelationshipManager::setRelationship($npc, 'Player', $target, null);
    } catch (Throwable $e) {
        lrgLog('relationship repair: failed for ' . $npc . ': ' . $e->getMessage());
        return;
    }
    lrgMemSet($npc, ['affinity_repaired_at' => lrgNow()]);
    if (!$ok) { lrgLog("relationship repair: CHIM refused the write for $npc"); return; }
    lrgRomanceSet($npc, ['last_affinity' => $target, 'last_affinity_at' => lrgNow()]);
    lrgLog(sprintf('relationship repair: %s -> Player set to %d (was %s). Reason: %s', $npc, $target,
        $before['found'] ? (string) $before['aff'] : 'no entry', (string) ($cfg['why'] ?? 'owner request')));
}

/**
 * [0.3.1 / R1c] How much their shared history is worth on the interest score. The owner's complaint is
 * that an NPC she has already been with went back to "not close enough" after a reload; CHIM's number is
 * the part that keeps disappearing, so the history the glue counts itself carries part of the weight.
 * Deliberately on the INTEREST side, not folded into affinity: the two stay separable in the log, and a
 * history bonus can never be written back into CHIM's own store.
 */
function lrgHistoryBonus(string $npc, ?array $romance = null): int
{
    $lev = lrgConfig()['leverage'] ?? [];
    $r = $romance ?? lrgRomance($npc);
    $scenes = max(0, (int) ($r['scenes'] ?? 0));
    if ($scenes <= 0) { return 0; }
    $base = (int) ($lev['history_bonus'] ?? 15);
    $extra = (int) ($lev['history_bonus_per_extra'] ?? 5) * ($scenes - 1);
    return max(0, min((int) ($lev['history_bonus_max'] ?? 30), $base + $extra));
}

/** True when the NPC is married to someone who is not the player. */
function lrgMarriedToOther(array $state): bool
{
    return ($state['married'] ?? '0') === '1' && ($state['pspouse'] ?? '0') !== '1';
}

/** 0 unknown / 1 many deeds / 2 Dragonborn - the same tests as lrgRenownWords(). */
function lrgRenownLevel(array $state): int
{
    if (($state['pdb'] ?? '0') === '1') { return 2; }
    return (int) ($state['pquests'] ?? 0) >= (int) (lrgConfig()['leverage']['many_deeds_quests_completed'] ?? 25) ? 1 : 0;
}

/**
 * R2 (PROTOCOL 6.1): how interested this NPC is in the player. The LLM only ever sees the WORD.
 *   score = affinity - effective min_affinity + min(max_bonus, renown bonus by renown_sway + Speech bonus)
 *   willing = score >= 0 (with no renown and no Speech bonus this is exactly v1's affinity >= min_affinity)
 * Hard blocks (married-refuse, never, non-adult) are NOT decided here: they stay hard gates.
 */
function lrgInterest(string $npc, array $state, array $profile): array
{
    $cfg = lrgConfig()['interest'] ?? [];
    $min = (int) ($profile['min_affinity'] ?? 25);
    if (lrgMarriedToOther($state) && ($profile['married_rule'] ?? 'refuse') !== 'refuse') { $min += 20; } // v1 "secret" rule: real trust needed
    $info = lrgAffinityInfo($npc, $state);
    $aff = (int) $info['used'];

    $table = ($cfg['renown_bonus'] ?? []) + ['weak' => [0, 2, 4], 'moderate' => [0, 5, 10], 'strong' => [0, 10, 20]];
    $sway = strtolower((string) ($profile['renown_sway'] ?? 'moderate'));
    $renown = (int) (($table[$sway] ?? $table['moderate'])[lrgRenownLevel($state)] ?? 0);
    $speech = min((int) ($cfg['speech_bonus_max'] ?? 10), 2 * intdiv(max(0, (int) ($state['pspeech'] ?? 0) - 50), 10));
    $bonus = min((int) ($cfg['max_bonus'] ?? 15), $renown + $speech);
    // [0.3.1 / R1c] their own history counts, on top of the renown / speech cap
    $hist = lrgHistoryBonus($npc);

    $score = $aff - $min + $bonus + $hist;
    $th = ($cfg['thresholds'] ?? []) + ['curious' => -25, 'interested' => 0, 'drawn' => 20];
    $word = $score < (int) $th['curious'] ? 'indifferent' : ($score < (int) $th['interested'] ? 'curious' : ($score < (int) $th['drawn'] ? 'interested' : 'drawn'));
    return ['word' => $word, 'score' => $score, 'willing' => $score >= 0, 'may_initiate' => $score >= 0 && $word === 'drawn',
        'affinity' => $aff, 'min' => $min, 'bonus' => $bonus, 'history' => $hist,
        'chim' => $info['chim'], 'lrg' => (int) $info['lrg'], 'rescue' => (string) $info['rescue'],
        'courting' => (int) $info['courting'], 'rank' => (int) $info['rank']];
}

// ---------------------------------------------------------------- [0.4] "everyone has a price"
/**
 * [0.4] The wealth TIER of this NPC: destitute | poor | modest | comfortable | wealthy | noble.
 * The profile's own `wealth` key wins; otherwise the tier her real purse falls into
 * (leverage.gold_tier_by_npc_gold). This is the twin lrgWealthWords() never had - until 0.4 the tier
 * was computed there, used to pick one phrase and thrown away, and the price bands need the tier itself.
 */
function lrgWealthTier(array $profile, array $state): string
{
    $tier = $profile['wealth'] ?? null;
    if (is_string($tier) && $tier !== '') { return strtolower($tier); }
    $tier = 'destitute';
    foreach ((array) ((lrgConfig()['leverage'] ?? [])['gold_tier_by_npc_gold'] ?? []) as $pair) {
        if ((int) ($state['gold'] ?? 0) >= (int) ($pair[0] ?? 0)) { $tier = (string) ($pair[1] ?? $tier); }
    }
    return strtolower($tier);
}

/**
 * [pt15 / OWNER ADDENDA 12e-f] This NPC's DAY WAGE: the gold an hour her work earns (paid_intimacy.wage_tiers)
 * times hours_per_day. It replaces the single gold_per_day_of_wage (35), which priced a jarl's day like a
 * farmhand's. The owner's anchor: "3-10 gold per hour average, 20-40 for skilled / special trades, 40 the
 * top end". Which tier, first hit wins:
 *   1. npc_overrides.<name>.wage_tier - the owner's call for one character;
 *   2. paid_intimacy.wage_tier_by_job - her real trade inside a broad rule (a bard among tavern folk, a smith
 *      or a spell vendor among merchants, a court wizard at court, a guard captain among guards, an
 *      apothecary or a hunter no rule knows). Each row names the rule ids it may refine, so a jarl or a
 *      priest can never be moved by it;
 *   3. the matched status rule's own wage_tier (the fallback block's for an unknown faction);
 *   4. paid_intimacy.default_wage_tier ('common').
 * An unknown or non-positive tier falls back to the default tier and then to 5 gold an hour, never to 0:
 * a zero wage would make everybody free.
 * Returns ['tier' => string, 'hour' => float, 'day' => float, 'from' => override|job|rule|default].
 */
function lrgWageFor(string $npc, array $profile, array $state): array
{
    $all = lrgConfig();
    $cfg = (array) ($all['paid_intimacy'] ?? []);
    $tiers = array_change_key_case((array) ($cfg['wage_tiers'] ?? []), CASE_LOWER);
    $hours = (float) ($cfg['hours_per_day'] ?? 8);
    if ($hours <= 0.0) { $hours = 8.0; }
    $valid = static fn(string $t): bool => $t !== '' && is_numeric($tiers[$t] ?? null) && (float) $tiers[$t] > 0.0;
    $tier = '';
    $from = 'default';

    $ov = ($all['npc_overrides'] ?? [])[$npc] ?? null;
    if (is_array($ov) && is_string($ov['wage_tier'] ?? null) && $valid(strtolower($ov['wage_tier']))) {
        $tier = strtolower($ov['wage_tier']);
        $from = 'override';
    }
    if ($tier === '') {
        $rid = (string) preg_replace('/\+override$/', '', (string) ($profile['id'] ?? ''));
        $factions = array_filter(explode(',', (string) ($state['fac'] ?? '')));
        $class = (string) ($state['class'] ?? '');
        foreach ((array) ($cfg['wage_tier_by_job'] ?? []) as $row) {
            if (!is_array($row) || !$valid(strtolower((string) ($row['tier'] ?? '')))) { continue; }
            if (!empty($row['profiles']) && !in_array($rid, (array) $row['profiles'], true)) { continue; }
            $hit = (!empty($row['factions_any']) && lrgAnyGlob((array) $row['factions_any'], $factions))
                || (!empty($row['class_any']) && $class !== '' && lrgAnyGlob((array) $row['class_any'], [$class]));
            if ($hit) { $tier = strtolower((string) $row['tier']); $from = 'job'; break; }
        }
    }
    if ($tier === '' && is_string($profile['wage_tier'] ?? null) && $valid(strtolower($profile['wage_tier']))) {
        $tier = strtolower($profile['wage_tier']);
        $from = 'rule';
    }
    if ($tier === '') {
        $tier = strtolower((string) ($cfg['default_wage_tier'] ?? 'common'));
        $from = 'default';
    }
    $hour = $valid($tier) ? (float) $tiers[$tier] : 5.0;
    return ['tier' => $tier, 'hour' => $hour, 'day' => round($hour * $hours, 2), 'from' => $from];
}

/**
 * [0.4 / OWNER ADDENDA 6] What a night with this NPC would cost, or why it cannot be bought at all.
 *
 * Design rules that must survive every later edit of this function:
 *  - ONLY `indifferent` and `curious` ever produce a price. A willing NPC (interest score >= 0) is not
 *    for sale, she is interested - which is why this feature can never turn a romance into a purchase.
 *  - the number is her FLOOR, not a fee. She may name any higher figure in her own voice; the server
 *    enforces the floor, the affordability, and the cap of what the PLAYER himself said out loud.
 *  - there is no upper clamp beyond max_price. A jarl at 150000-1500000 septims is unaffordable in
 *    practice and that IS the design ("very immune ... a ridiculous high price"); it is refused on
 *    affordability, with a log line that says so, never with silence.
 *  - none of this is folded into the interest score. It removes exactly one gate reason, which is what
 *    keeps "she may still say no to a rude buyer" true and the log readable.
 *  - [pt15 / addenda 12e-f] a day is HER day: band days x her own day wage (lrgWageFor), not one wage
 *    for all of Skyrim.
 * Return keys: for_sale, free, gold (her floor), token (a gift, only when free), band [lo,hi] in days,
 * days (after every modifier), tier (her WEALTH tier), wage_tier, wage_hour, wage_day, wage_from, infl,
 * stance, secret, renown, repeat, mult, why.
 */
function lrgPriceFor(string $npc, array $state, array $profile, array $interest): array
{
    $cfg = (array) (lrgConfig()['paid_intimacy'] ?? []);
    $word = (string) ($interest['word'] ?? 'indifferent');
    $out = ['for_sale' => false, 'free' => false, 'gold' => 0, 'token' => 0, 'band' => [0.0, 0.0],
        'days' => 0.0, 'tier' => '', 'wage_tier' => '', 'wage_hour' => 0.0, 'wage_day' => 0.0, 'wage_from' => '',
        'infl' => 1.0, 'stance' => $word, 'secret' => false,
        'renown' => 1.0, 'repeat' => 1.0, 'mult' => 1.0, 'why' => ''];

    // ---- step 0: is the subject open at all? A false here means money is NEVER mentioned to her.
    if (empty($cfg['enabled'])) { $out['why'] = 'disabled'; return $out; }
    // MCM off. An ABSENT paidok means ON (an old game script has no such key at all). `ignore_game_switch`
    // is the owner's escape hatch for the missing-ini-key trap: a boolean MCM setting whose settings.ini
    // default line does not exist yet reads as 0/FALSE, so the game would send paidok=0 and the whole
    // feature would be silently off. Flipping this one server key gets it back without touching MCM.
    if ((string) ($state['paidok'] ?? '') === '0' && empty($cfg['ignore_game_switch'])) { $out['why'] = 'disabled'; return $out; }
    if (!empty($profile['never'])) { $out['why'] = 'never'; return $out; }
    if (!empty($profile['not_for_sale'])) { $out['why'] = 'not_for_sale'; return $out; }
    $secret = false;
    if (lrgMarriedToOther($state)) {
        if ((string) ($profile['married_rule'] ?? 'refuse') === 'refuse') { $out['why'] = 'married'; return $out; }
        $secret = true; // "secret": possible, and dearer - she is risking her marriage
    }
    $out['for_sale'] = true;
    $out['secret'] = $secret;

    // [pt15 / addenda 12e-f] HER day wage, from the tier her work puts her in (never 0: see lrgWageFor)
    $wage = lrgWageFor($npc, $profile, $state);
    $perDay = max(1.0, (float) $wage['day']);
    $out['wage_tier'] = $wage['tier'];
    $out['wage_hour'] = $wage['hour'];
    $out['wage_day'] = $perDay;
    $out['wage_from'] = $wage['from'];
    $step = max(1, (int) ($cfg['round_to'] ?? 5));
    $minP = max(0, (int) ($cfg['min_price'] ?? 10));
    $maxP = max($minP, (int) ($cfg['max_price'] ?? 2000000));
    // two independent guards on the multiplier, because a 0 here would silently make everyone free:
    // the game clamps before sending, and absent or <= 0 reads as 1.0 here as well
    $mult = (float) ($cfg['price_multiplier'] ?? 1.0);
    if ($mult <= 0.0) { $mult = 1.0; }
    $pm = (float) ($state['pm'] ?? 0);
    $mult *= ($pm > 0.0 ? $pm : 1.0);
    $out['mult'] = $mult;
    $toGold = static fn(float $days): int => max($minP, min($maxP, (int) (round($days * $perDay * $mult / $step) * $step)));

    // ---- step 1: the band, in DAYS OF WAGE
    $tier = lrgWealthTier($profile, $state);
    $bands = (array) ($cfg['bands_by_wealth'] ?? []);
    $raw = (array) ($profile['price_band'] ?? ($bands[$tier] ?? ($bands['modest'] ?? [4, 15])));
    $band = [max(0.0, (float) ($raw[0] ?? 4)), max(0.0, (float) ($raw[1] ?? 15))];
    if ($band[1] < $band[0]) { $band[1] = $band[0]; }
    $out['tier'] = $tier;
    $out['band'] = $band;

    // ---- step 9 first, because it skips everything else: an owner-pinned figure is the figure
    $pinned = (int) ($profile['price_gold'] ?? 0);
    if ($pinned > 0) {
        $out['gold'] = max($minP, min($maxP, $pinned));
        $out['days'] = $out['gold'] / $perDay;
        $out['stance'] = $word . '/pinned';
        return $out;
    }

    // ---- step 2: where inside the band her stance puts her - or no price at all
    $stance = (array) ($cfg['stance'] ?? []) + ['indifferent' => 'ceiling', 'curious' => 'floor', 'interested' => 'free', 'drawn' => 'free'];
    $where = (string) ($stance[$word] ?? 'free');
    if ($where === 'free') {
        $out['free'] = true;
        $out['token'] = $toGold(min($band[0], max(0.0, (float) ($cfg['token_days'] ?? 0.5))));
        return $out;
    }
    $days = $where === 'ceiling' ? $band[1] : $band[0];

    // ---- step 3: how much coin moves this character (a factor on the DAYS, so "insulting" means
    //              exorbitant, not impossible - which is the owner's whole point)
    $map = (array) ($cfg['money_influence'] ?? []);
    $infl = array_key_exists('money_influence', $profile)
        ? (float) $profile['money_influence']
        : (float) ($map[strtolower((string) ($profile['gold_sway'] ?? 'indifferent'))] ?? 1.6);
    if ($infl <= 0.0) { $infl = 1.0; }
    $out['infl'] = $infl;
    $days *= $infl;

    // ---- step 4: a marriage she would be betraying
    if ($secret) { $days *= max(1.0, (float) ($cfg['secret_factor'] ?? 3.0)); }

    // ---- step 5: status and fame make her cheaper (the other half of the owner's request)
    $rd = (array) ($cfg['renown_discount'] ?? []) + ['weak' => [1.0, 0.97, 0.95], 'moderate' => [1.0, 0.92, 0.85], 'strong' => [1.0, 0.85, 0.75]];
    $sway = strtolower((string) ($profile['renown_sway'] ?? 'moderate'));
    $row = (array) ($rd[$sway] ?? $rd['moderate']);
    $disc = (float) ($row[lrgRenownLevel($state)] ?? 1.0);
    if ($disc > 0.0) { $out['renown'] = $disc; $days *= $disc; }

    // ---- step 6: a standing arrangement is a little cheaper
    if ((int) (lrgRomance($npc)['gold_accepted'] ?? 0) > 0) {
        $out['repeat'] = max(0.01, (float) ($cfg['repeat_factor'] ?? 0.85));
        $days *= $out['repeat'];
    }

    // ---- step 7: never below her own floor. NO upper clamp: "exorbitant" is the point.
    $days = max($days, $band[0]);

    // ---- step 8
    $out['days'] = $days;
    $out['gold'] = $toGold($days);
    return $out;
}

/**
 * [0.4] 'easily' | 'just about' | 'not quite' | 'nowhere near' - the only thing the LLM is ever told
 * about the player's purse. The number itself (pgold) must never reach a prompt.
 */
function lrgPurseWord(int $pgold, int $gold): string
{
    if ($gold <= 0) { return $pgold > 0 ? 'easily' : 'nowhere near'; }
    if ($pgold >= $gold * 3) { return 'easily'; }
    if ($pgold >= $gold) { return 'just about'; }
    return ($pgold * 2 >= $gold) ? 'not quite' : 'nowhere near';
}

/**
 * [0.4] What the PLAYER has actually put on the table this conversation, and whether it clears her floor.
 * ['offer'=>int, 'source'=>none|said|quote|memory|confirm|hypothetical|implied|withdrawn,
 *  'kind'=>''|offer|askprice|haggle|withdraw, 'accepted'=>bool, 'why'=>string,
 *  'firm'=>bool (only a firm figure may ever leave the purse), 'said'=>int (the figure named THIS turn),
 *  'confirm'=>bool (a grey offer she must ask him to confirm), 'pending'=>int (the offer she is waiting on)]
 *
 * Why this does its own recognition (precision pass 7.1): lrgPrepareTurn() calls lrgEvaluateGates()
 * BEFORE lrgRecogniseIntent() - the mode is decided in between - so the offer cannot be read from
 * $turn['intent'] here. lrgIntentMoney() is pure regex with no ctx, no index and no DB, the recogniser
 * is documented as running twice per turn by design, and the normal path produces the same money result
 * a moment later, so the gate, the directive and the turn line can never disagree.
 *
 * [0.5.6 / pt15, owner rulings A-D] THE PENDING OFFER. A grey offer ("if you ... I'd give you a thousand
 * gold", "would fifty do?", "a thousand gold for a night") that clears her floor and his purse is stored
 * in lrg_memory.offer_pending (~2 minutes, confirm_ttl_seconds) and she asks him to confirm it; his "yes"
 * comes back from lrgIntentMoney() as a FIRM offer at the remembered figure (source=confirm), which is
 * then judged exactly like any other firm offer. Every write here is idempotent across the two passes of
 * one request: the pending record carries the request that created it, a confirmation leaves it in place
 * (state=confirmed) and a withdrawal marks it (state=withdrawn) instead of deleting it, so the second pass
 * reads what the first one read.
 *
 * NEVER on an lrg_initiative tick: she cannot accept an offer nobody made.
 */
function lrgPaidOffer(string $npc, array $price, array $state, string $requestType): array
{
    $cfg = (array) (lrgConfig()['paid_intimacy'] ?? []);
    $out = ['offer' => 0, 'source' => 'none', 'kind' => '', 'accepted' => false, 'why' => '',
        'firm' => false, 'said' => 0, 'confirm' => false, 'pending' => 0];
    if (empty($cfg['enabled']) || $npc === '') { $out['why'] = 'disabled'; return $out; }
    $isSpeech = in_array(strtolower($requestType), LRG_PLAYER_SPEECH_TYPES, true);
    $mem = lrgMemGet($npc);

    $money = null;
    if ($isSpeech && function_exists('lrgIntentMoney') && function_exists('lrgIntentClean')) {
        $money = lrgIntentMoney(lrgIntentClean((string) ($GLOBALS['gameRequest'][3] ?? '')), $mem);
    }
    $ttl = max(1, (int) ($cfg['offer_ttl_seconds'] ?? 600));
    $stored = is_array($mem['paid_offer'] ?? null) ? $mem['paid_offer'] : null;
    $keptGold = ($stored !== null && lrgNow() - (int) ($stored['at'] ?? 0) <= $ttl) ? max(0, (int) ($stored['gold'] ?? 0)) : 0;
    $pend = function_exists('lrgPendingOffer') ? lrgPendingOffer($mem) : null;
    $pendAny = function_exists('lrgPendingOffer') ? lrgPendingOffer($mem, true) : null;
    $req = function_exists('lrgMoneyReqKey') ? lrgMoneyReqKey() : '';
    $floor = (int) ($price['gold'] ?? 0);
    $purse = (int) ($state['pgold'] ?? 0); // absent reads as 0: an affordability check that cannot be made never accepts
    if ($pend !== null) { $out['pending'] = (int) $pend['gold']; }

    $saidGold = 0;
    $firm = true;
    $from = '';
    if (is_array($money)) {
        $out['kind'] = (string) ($money['kind'] ?? '');
        $saidGold = max(0, (int) ($money['gold'] ?? 0));
        $firm = !array_key_exists('firm', $money) || !empty($money['firm']);
        $from = (string) ($money['from'] ?? '');
        $out['said'] = $saidGold;
        // ---- he takes it back: the pending offer is marked, and a firm figure he made earlier goes with it
        if ($out['kind'] === 'withdraw') {
            if ($pendAny !== null && (string) ($pendAny['state'] ?? '') !== 'withdrawn') {
                lrgMemSet($npc, ['paid_offer' => null, 'offer_pending' => ['state' => 'withdrawn', 'wreq' => $req,
                    'expires' => min((int) $pendAny['expires'], lrgNow() + 20)] + $pendAny]);
                lrgLog(sprintf('price: %s - the player took back his offer of %d septims', $npc, (int) $pendAny['gold']));
            }
            $out['source'] = 'withdrawn';
            $out['pending'] = 0;
            $out['why'] = 'he took the offer back';
            return $out;
        }
        // 7.2: the turn is prepared TWICE per request (the functions.php hook and prerequest), and
        // lrgMemSet only skips a write when the value is IDENTICAL - a fresh `at` would change every
        // pass. So only a HIGHER amount is ever written, and the first offer keeps its own stamp, which
        // is also what makes the TTL run from when the player first named a figure.
        // [pt15] ... and only a FIRM figure is ever written there: an "if" is never money on the table.
        if ($saidGold > 0 && $firm) {
            if ($saidGold > $keptGold) {
                lrgMemSet($npc, ['paid_offer' => ['gold' => $saidGold, 'at' => $keptGold > 0 ? (int) ($stored['at'] ?? lrgNow()) : lrgNow()]]);
            }
            if ($from === 'confirm' && $pend !== null && (string) ($pend['state'] ?? '') !== 'confirmed') {
                lrgMemSet($npc, ['offer_pending' => ['state' => 'confirmed'] + $pend]);
                lrgLog(sprintf('price: %s - the player confirmed his offer of %d septims: it is firm now', $npc, $saidGold));
            } elseif ($from !== 'confirm' && $pendAny !== null) {
                lrgMemSet($npc, ['offer_pending' => null]);   // a firm figure replaces anything pending
                $out['pending'] = 0;
            }
        }
    }
    if ($saidGold > 0 && $firm && $saidGold >= $keptGold) {
        $out['offer'] = $saidGold;
        $out['source'] = in_array($from, ['quote', 'confirm'], true) ? $from : 'said';
        $out['firm'] = true;
    } elseif ($keptGold > 0) {
        $out['offer'] = $keptGold;
        $out['source'] = 'memory';
        $out['firm'] = true;
    }

    // ---- [pt15, rulings A + C] a grey figure, with no firm one standing: never money on the table
    if ($out['offer'] <= 0 && $saidGold > 0 && !$firm) {
        $out['offer'] = $saidGold;
        $out['source'] = $from !== '' ? $from : 'hypothetical';
        if (empty($price['for_sale'])) { $out['why'] = 'not for sale'; return $out; }
        if (!$isSpeech) { $out['why'] = 'not_player_speech'; return $out; }
        if (!empty($price['free'])) { $out['why'] = 'she wants him anyway - and an "if" is not money'; return $out; }
        if ($saidGold < $floor) { $out['why'] = sprintf('below her floor (%d < %d), and not firm', $saidGold, $floor); return $out; }
        if ($purse < $saidGold) { $out['why'] = sprintf('the player cannot afford it (%d < %d), and not firm', $purse, $saidGold); return $out; }
        // she asks him to confirm, and remembers what she asked about - idempotent across the two passes
        if ($pend === null || (int) $pend['gold'] !== $saidGold) {
            $ttlC = max(10, (int) ($cfg['confirm_ttl_seconds'] ?? 120));
            lrgMemSet($npc, ['offer_pending' => ['gold' => $saidGold, 'npc' => $npc, 'at' => lrgNow(),
                'expires' => lrgNow() + $ttlC, 'req' => $req, 'state' => 'asked']]);
            lrgLog(sprintf('price: %s - the player spoke of %d septims without committing (%s): she asks him to confirm; pending for %ds',
                $npc, $saidGold, $out['source'], $ttlC));
        }
        $out['confirm'] = true;
        $out['pending'] = $saidGold;
        $out['why'] = 'not firm (' . $out['source'] . '): she asks him to confirm';
        return $out;
    }

    if ($out['offer'] <= 0) { $out['why'] = $out['kind'] === '' ? 'nothing was offered' : 'no amount was named'; return $out; }
    if (empty($price['for_sale'])) { $out['why'] = 'not for sale'; return $out; }
    if (!$isSpeech) { $out['why'] = 'not_player_speech'; return $out; }
    if (!empty($price['free'])) { $out['accepted'] = true; $out['why'] = 'she wants him anyway'; return $out; }
    if ($out['offer'] < $floor) { $out['why'] = sprintf('below her floor (%d < %d)', $out['offer'], $floor); return $out; }
    if ($purse < $out['offer']) { $out['why'] = sprintf('the player cannot afford it (%d < %d)', $purse, $out['offer']); return $out; }
    $out['accepted'] = true;
    $out['why'] = 'bought: a firm offer at or above her price, and he has the coin';
    return $out;
}

/**
 * [0.4] Is coin the ONLY thing standing between them? True when the reason list holds `not_close_enough`
 * and nothing else except the two reasons that are about WHERE they are (witnesses, companion_present).
 *
 * This is what makes the feature reachable at all. An indifferent NPC always carries not_close_enough,
 * which puts the turn in mode `closed` (lrgGateMode) - and a closed turn is the one mode with no wording
 * permission, whose boundary text says in so many words that a bigger offer changes nothing. Without
 * this test the player could offer gold and have it work, but could never ASK what it would take, so
 * the price would be a number only the server ever saw.
 * When it is false, coin is never mentioned and no figure is ever named: something a price cannot fix
 * (a marriage she keeps, a fight, a quest scene, a child in the room) is in the way.
 */
function lrgPriceCouldOpen(array $gate): bool
{
    $r = (array) ($gate['reasons'] ?? []);
    if (!in_array('not_close_enough', $r, true)) { return false; }
    return !array_diff($r, ['not_close_enough', 'witnesses', 'companion_present']);
}

/**
 * [0.4] Remember the floor she was just told, so a bare "deal" on the next turn can close it.
 * Idempotent across the two passes of one request: an unchanged figure inside its TTL keeps its own
 * timestamp, so lrgMemSet() writes nothing the second time (7.2, the same trap as paid_offer).
 */
function lrgRememberQuote(string $npc, int $gold): void
{
    if ($npc === '' || $gold <= 0) { return; }
    $ttl = max(1, (int) ((lrgConfig()['paid_intimacy'] ?? [])['quote_ttl_seconds'] ?? 300));
    $q = lrgMemGet($npc)['price_quoted'] ?? null;
    if (is_array($q) && (int) ($q['gold'] ?? 0) === $gold && lrgNow() - (int) ($q['at'] ?? 0) <= $ttl) { return; }
    lrgMemSet($npc, ['price_quoted' => ['gold' => $gold, 'at' => lrgNow()]]);
}

/**
 * [0.4] n_a | not_for_sale | free | needed | accepted | below_floor | unaffordable - for the turn line.
 * [0.5.6 / pt15] + confirm (a grey offer she asks him to confirm) | withdrawn (he took it back).
 */
function lrgPriceDecision(array $price, array $paid): string
{
    if (empty($price['for_sale'])) { return ((int) ($paid['offer'] ?? 0) > 0 || ($paid['kind'] ?? '') !== '') ? 'not_for_sale' : 'n_a'; }
    if (!empty($paid['accepted'])) { return 'accepted'; }
    if ((string) ($paid['kind'] ?? '') === 'withdraw') { return 'withdrawn'; }
    $why = (string) ($paid['why'] ?? '');
    if (str_contains($why, 'afford')) { return 'unaffordable'; }
    if (str_contains($why, 'floor')) { return 'below_floor'; }
    if (!empty($paid['confirm'])) { return 'confirm'; }
    if (!empty($price['free'])) { return 'free'; }
    return 'needed';
}

/** [0.4] The dedicated `price` log line (PROTOCOL 9), written only for a turn that really mentions coin. */
function lrgLogPrice(string $npc, array $state, array $price, array $paid, string $cid = ''): void
{
    $cfg = (array) (lrgConfig()['paid_intimacy'] ?? []);
    if (empty($cfg['log_prices'])) { return; }
    if ((string) ($paid['kind'] ?? '') === '' && (int) ($paid['offer'] ?? 0) <= 0) { return; }
    $num = static fn(float $f): string => rtrim(rtrim(number_format($f, 2, '.', ''), '0'), '.');
    // [pt15 / addenda 12e-f] wage= gph= day_wage= right after the wealth tier: WHOSE day the band's days are
    lrgLog(sprintf('price npc=%s for_sale=%s%s tier=%s wage=%s/%s gph=%s day_wage=%s band=%s-%sd infl=%s stance=%s days=%s floor=%d token=%d secret=%s renown=x%s repeat=x%s mult=%s pgold=%d offer=%d/%s kind=%s decision=%s%s',
        $npc, empty($price['for_sale']) ? 'no' : 'yes', empty($price['for_sale']) ? '(' . (string) ($price['why'] ?? '?') . ')' : '',
        (string) ($price['tier'] ?? '-') ?: '-', (string) ($price['wage_tier'] ?? '') ?: '-', (string) ($price['wage_from'] ?? '') ?: '-',
        $num((float) ($price['wage_hour'] ?? 0)), $num((float) ($price['wage_day'] ?? 0)),
        $num((float) ($price['band'][0] ?? 0)), $num((float) ($price['band'][1] ?? 0)),
        $num((float) ($price['infl'] ?? 1)), (string) ($price['stance'] ?? '-'), $num((float) ($price['days'] ?? 0)),
        (int) ($price['gold'] ?? 0), (int) ($price['token'] ?? 0), empty($price['secret']) ? 'no' : 'yes',
        $num((float) ($price['renown'] ?? 1)), $num((float) ($price['repeat'] ?? 1)), $num((float) ($price['mult'] ?? 1)),
        (int) ($state['pgold'] ?? 0), (int) ($paid['offer'] ?? 0), (string) ($paid['source'] ?? 'none'),
        (string) ($paid['kind'] ?? '-') ?: '-', lrgPriceDecision($price, $paid),
        ($paid['why'] ?? '') !== '' ? ' why="' . str_replace('"', "'", (string) $paid['why']) . '"' : '')
        // [pt15] additive, last: the offer she is waiting for him to confirm
        . ((int) ($paid['pending'] ?? 0) > 0 ? ' pending=' . (int) $paid['pending'] : ''), $cid);
}

// ---------------------------------------------------------------- Layer 1: hard gates
/**
 * Decide whether StartIntimacy may even be OFFERED to the LLM this turn.
 * Returns ok, the blocking reasons (for the NPC's in-character refusal), the profile, the interest,
 * and the parameters the game needs to repeat the privacy check.
 * $typeAdmitted = this non-speech request was admitted by the server itself (an lrg_initiative tick that passed
 * lrgInitiativeAdmit); every other non-speech request type stays blocked, as in v1.
 */
function lrgEvaluateGates(string $npc, string $requestType, bool $typeAdmitted = false): array
{
    $res = ['ok' => false, 'reasons' => [], 'soft' => [], 'profile' => null, 'state' => null, 'affinity' => 0, 'interest' => null];
    if (!lrgEnabled()) { $res['reasons'][] = 'disabled'; return $res; }
    if ($npc === '' || strcasecmp($npc, 'The Narrator') === 0 || strcasecmp($npc, 'Player') === 0) { $res['reasons'][] = 'not_a_person'; return $res; }
    // consent may only originate from the NPC's own reply to the player speaking to them - or from her own admitted move
    if (!$typeAdmitted && !in_array(strtolower($requestType), LRG_PLAYER_SPEECH_TYPES, true)) { $res['reasons'][] = 'not_player_speech'; return $res; }

    $state = lrgGetNpcState($npc);
    $res['state'] = $state;
    $maxAge = (int) (lrgConfig()['snapshot_max_age_seconds'] ?? 90);
    if (!$state || ($state['_age'] ?? 9999) > $maxAge) { $res['reasons'][] = 'no_fresh_snapshot'; return $res; }

    // fail closed on everything the game reported
    if (($state['on'] ?? '0') !== '1')     { $res['reasons'][] = 'feature_off'; }
    if (($state['adult'] ?? '0') !== '1')  { $res['reasons'][] = 'not_adult'; }
    if (($state['combat'] ?? '1') !== '0') { $res['reasons'][] = 'combat'; }
    if (($state['scene'] ?? '1') !== '0')  { $res['reasons'][] = 'quest_scene'; }
    if (($state['ostim'] ?? '1') !== '0')  { $res['reasons'][] = 'already_in_scene'; }
    if (($state['witkid'] ?? '1') !== '0') { $res['reasons'][] = 'child_nearby'; }
    if (lrgGetActiveScene() !== null)      { $res['reasons'][] = 'scene_running'; }
    if (in_array('not_adult', $res['reasons'], true) || in_array('child_nearby', $res['reasons'], true)) {
        return $res; // nothing else is even computed
    }

    $profile = lrgBuildProfile($npc, $state);
    $res['profile'] = $profile;
    if (!empty($profile['never'])) { $res['reasons'][] = 'never'; return $res; }

    $privacy = $profile['privacy'];
    $maxWit = (int) ($privacy['max_witnesses'] ?? 0);
    $followersOk = !empty($privacy['followers_ok']);

    if (lrgMarriedToOther($state)) {
        if (($profile['married_rule'] ?? 'refuse') === 'refuse') {
            $res['reasons'][] = 'married';
        } else { // "secret": possible, but only with real trust (+20, inside lrgInterest) and nobody at all around
            $maxWit = 0; $followersOk = false;
            $res['soft'][] = 'married_secret';
        }
    }
    if ((int) ($state['wit'] ?? 99) > $maxWit) { $res['reasons'][] = 'witnesses'; }
    // [0.5.1 / pt9 section 5] witchim is the player's own companion that the teammate flag does not
    // see - an NPC CHIM half-recruited. She was counted as an ordinary stranger before, which is
    // right for `wit` and wrong for "is one of his own people standing there". An ABSENT witchim
    // (an older game script, or bFollowerAware off) reads as 0, so this can only ever add a reason.
    $companions = (int) ($state['witfol'] ?? 0) + (int) ($state['witchim'] ?? 0);
    if (!$followersOk && $companions > 0) { $res['reasons'][] = 'companion_present'; }

    $interest = lrgInterest($npc, $state, $profile);
    $res['interest'] = $interest;
    $res['affinity'] = $interest['affinity'];
    if (!$interest['willing']) { $res['reasons'][] = 'not_close_enough'; }

    /*
     * [0.4 / OWNER ADDENDA 6] "EVERYONE HAS A PRICE". Coin removes exactly ONE reason from the list
     * above - not_close_enough - AND NOTHING ELSE. never, married (refuse), witnesses,
     * companion_present, child_nearby, not_adult, combat, quest_scene, already_in_scene, scene_running,
     * feature_off and no_fresh_snapshot are untouched, so a jarl with a fortune on the table in a
     * crowded hall is still `witnesses`, and any refusal she gives for a reason of her own stands.
     * The price itself is never folded into the interest score: keeping them apart is what makes
     * "she may still say no to a rude buyer" true and the log readable.
     * [0.5.6 / pt15, owner ruling B] "she can see the gold for herself": only a FIRM offer (said, confirmed,
     * closing her quote, or remembered) at or above her floor AND within his purse is `accepted`, and
     * that alone is the trust - the lifted reason is logged as `bought`. An "if" never lifts anything.
     */
    $res['price'] = lrgPriceFor($npc, $state, $profile, $interest);
    $res['paid'] = lrgPaidOffer($npc, $res['price'], $state, $requestType);
    if (!$interest['willing'] && !empty($res['paid']['accepted'])) {
        $res['reasons'] = array_values(array_diff($res['reasons'], ['not_close_enough']));
        $res['soft'][] = 'paid';
        lrgLog(sprintf('price: bought - %s would take %d septims, the player made a firm offer of %d (%s) and is carrying %d - not_close_enough lifted, nothing else',
            $npc, (int) $res['price']['gold'], (int) $res['paid']['offer'], (string) $res['paid']['source'], (int) ($state['pgold'] ?? 0)));
    }
    lrgLogPrice($npc, $state, $res['price'], $res['paid']);

    $res['game_params'] = ['maxwit' => $maxWit, 'folok' => $followersOk ? 1 : 0];
    $res['ok'] = count($res['reasons']) === 0;
    return $res;
}

/**
 * One mode per turn outside a scene (PROTOCOL 6.2): silent | closed | public | private | follow.
 * ("scene" is decided by lrgPrepareTurn, which knows about the live scene.)
 */
function lrgGateMode(array $gate, ?array $invite): string
{
    $reasons = $gate['reasons'] ?? ['disabled'];
    if (empty($gate['profile']) || empty($gate['state'])
        || array_intersect($reasons, ['disabled', 'not_a_person', 'not_player_speech', 'no_fresh_snapshot', 'feature_off', 'not_adult', 'child_nearby', 'never'])) {
        return 'silent';
    }
    if (array_diff($reasons, ['witnesses', 'companion_present'])) { return 'closed'; } // not willing, or a block that privacy would not solve
    if ($reasons) { return 'public'; }
    return ($invite !== null && ($invite['state'] ?? '') === 'pending') ? 'follow' : 'private';
}

/**
 * R3: where she could suggest going - only places backed by facts from the snapshot (PROTOCOL 6.2).
 * Returns [key => words], keys a subset of home, room, quiet.
 */
function lrgPlaces(array $state): array
{
    $w = (lrgConfig()['invitation']['place_words'] ?? []) + [
        'home_here' => 'a room of their own right here - this is their own place',
        'home' => 'their home, %s',
        'room_rented' => 'the room the player has rented at this inn',
        'room_own_inn' => 'a room upstairs - this is their inn',
        'room' => 'a room at this inn (it would have to be rented first)',
        'quiet' => 'somewhere quiet nearby, away from other people',
        // [0.4] the game says a door is shut somewhere in reach: "a room with a door" is then a real,
        // concrete suggestion rather than a vague "somewhere quiet"
        'quiet_closed' => 'a room here with a door that shuts, away from the people in this room',
    ];
    $places = [];
    $nhome = trim((string) ($state['nhome'] ?? ''));
    $ownsCell = ($state['cellown'] ?? '') === 'npc';
    // home=1 only says the NPC's editor location is the current LOCATION, and a location can be a
    // whole city: "their own place, right here" must not be offered in the open market. It counts
    // only inside a building (or when the NPC really owns this cell).
    $indoor = in_array((string) ($state['ltype'] ?? ''), ['inn', 'phouse', 'house', 'castle', 'temple', 'store', 'guild'], true);
    if (($ownsCell || ($state['home'] ?? '') === '1') && $indoor) {
        $places['home'] = $w['home_here'];
    } elseif ($nhome !== '') {
        $places['home'] = sprintf($w['home'], $nhome);
    }
    if (($state['ltype'] ?? '') === 'inn') {
        $places['room'] = ($state['prent'] ?? '') === '1' ? $w['room_rented'] : ($ownsCell ? $w['room_own_inn'] : $w['room']);
    }
    // [0.4] interior + every door in reach already shut -> name the door, it is the thing that makes
    // the room private and the owner's complaint was exactly that nobody would name it
    $places['quiet'] = (($state['door'] ?? '') === '1' && $indoor) ? $w['quiet_closed'] : $w['quiet'];
    return $places;
}

/** Explicitness level 0..2: scene payload x, else snapshot x, else the config default. */
function lrgExplicitLevel(?array $scene, ?array $state): int
{
    foreach ([$scene['x'] ?? null, $state['x'] ?? null, lrgConfig()['scene_talk']['explicitness'] ?? 2] as $v) {
        if ($v !== null && $v !== '' && is_numeric($v)) { return max(0, min(2, (int) $v)); }
    }
    return 2;
}

/** R4: settings / characters where she keeps it discreet (never coy): very strict profiles, a risky marriage, a court or a temple. */
function lrgIsDiscreet(?array $profile, ?array $state, array $soft = []): bool
{
    $lt = (array) (lrgConfig()['language']['discreet_ltypes'] ?? ['castle', 'temple']);
    return str_contains(strtolower((string) ($profile['strictness'] ?? '')), 'very strict')
        || in_array('married_secret', $soft, true)
        || in_array(strtolower((string) ($state['ltype'] ?? '')), $lt, true);
}

// ---------------------------------------------------------------- words for the LLM
function lrgWealthWords(array $profile, array $state): string
{
    // [0.4] the tier itself now comes from lrgWealthTier(), so the words and the price band can never
    // disagree about how well off she is
    return (lrgConfig()['leverage']['wealth_words'] ?? [])[lrgWealthTier($profile, $state)] ?? 'lives modestly';
}

/**
 * [0.4 / OWNER ADDENDA 4b] One plain clause about the room they are in, or ''. Snapshot facts only.
 * `door=1` is the game saying: the player is in an interior and every door within fDoorRadius is shut.
 * The witness count is already corrected at its source (LRG_Profile.WitnessScan no longer counts an
 * NPC who can see neither of them just for being near when a door is shut), so this changes no gate
 * logic at all - it only stops the model arguing that a closed room is not private.
 */
function lrgPrivacyWords(array $state): string
{
    if ((string) ($state['door'] ?? '') !== '1') { return ''; }
    $alone = (int) ($state['wit'] ?? 99) === 0 && (int) ($state['witfol'] ?? 0) === 0;
    return $alone
        ? 'they are in a closed room with the door shut, and nobody can see or hear them'
        : 'the door of this room is shut';
}

function lrgRenownWords(array $state): string
{
    $r = lrgConfig()['leverage']['renown'] ?? [];
    $lvl = lrgRenownLevel($state);
    if ($lvl === 2) { return $r['dragonborn_known'] ?? 'is known as the Dragonborn'; }
    if ($lvl === 1) { return $r['many_deeds'] ?? 'has a growing reputation'; }
    return $r['unknown'] ?? 'is nobody in particular to them';
}

/** What an interest word means, as a sentence fragment after "<npc> ". */
function lrgInterestWords(string $word): string
{
    $w = (lrgConfig()['interest']['words'] ?? []) + [
        'indifferent' => 'has no romantic or physical interest in the player',
        'curious' => 'has noticed the player and is a little curious, nothing more so far',
        'interested' => 'is attracted to the player and open to more',
        'drawn' => 'wants the player, and is ready to show it',
    ];
    return (string) ($w[$word] ?? $w['indifferent']);
}

// ================================================================== [0.5.1] FOLLOWERS
/*
 * OWNER ADDENDUM 10 / research/pt9-followers.md. Three things live here, and only these three:
 *  (a) reading the snapshot's `fol=` block, which is the ONLY thing the game says about followers;
 *  (b) the <companion_status> prompt block - facts, in words, nothing the game has not confirmed;
 *  (c) lrgFollowerPolicy(), the hide rule that stops CHIM's "join my party" being offered where it
 *      cannot work. It only ever REMOVES from this turn's offer, and it runs at brace depth 0 from
 *      functions.php after both lanes have built theirs, so nothing can put one back.
 *
 * What it does NOT do: talk to the framework. SFF, Quick Follower Commands and Set Follower Home
 * have no listener, no mod event and no server API; the cooperation that is really buildable is
 * "read the truth and use the NPC's own dialogue entry", which is lrg_dialogue.php's follower verbs.
 */

/** Defaults in code + the owner's `followers` block from lrg_config.json. */
function lrgFollowerCfg(string $path, $default = null)
{
    static $cfg = null;
    if ($cfg === null) {
        $cfg = lrgMerge([
            'enabled' => true,
            // (c): MakeFollower is hidden whenever the game says it cannot work. Every one of these is
            // a fact from the snapshot, never a guess.
            'hide_make_follower' => true,
            'hide_when_teammate' => true,
            'hide_when_cap_full' => true,
            'hide_when_ghost' => ['MakeFollower', 'Follow', 'FollowPlayer'],
            // S6: CHIM's own catalog rows that cannot work in this load order. Warned about once per
            // request, never disabled - switching an owner's action off is the owner's call.
            'warn_actions' => ['MakeFollower', 'Follow'],
            'block' => true,
            'max_chars' => 520,
            // [0.5.4 / pt13] how long the last fol= of THIS game session still counts once the game has
            // stopped sending it (its snapshot abort rail retires the block for the session)
            'remember_seconds' => 900,
        ], (array) (lrgConfig()['followers'] ?? []));
    }
    $v = $cfg;
    foreach (explode('.', $path) as $k) {
        if (!is_array($v) || !array_key_exists($k, $v)) { return $default; }
        $v = $v[$k];
    }
    return $v;
}

/**
 * The snapshot's `fol=` value as an array. ABSENT MEANS SAY NOTHING: an older game script, or
 * bFollowerAware switched off, both arrive here as [] and every caller then changes nothing.
 * Shape: fw:sff,mate:1,cff:1,pff:0,wait:0,chim:0,ghost:0[,slot:1/4,cap:1,prim:1]
 */
function lrgFolState(?array $state): array
{
    if (!is_array($state)) { return []; }
    $raw = trim((string) ($state['fol'] ?? ''));
    $remembered = false;
    if ($raw === '') {
        // [0.5.4 / pt13] the game went quiet about followers in the middle of a session (its snapshot
        // abort rail retired the block): the last value the SAME session sent still stands, for a while.
        // lrgCarryFol() only ever stores it next to a session tag, so an old script never gets here.
        $last = trim((string) ($state['_fol_last'] ?? ''));
        $sess = trim((string) ($state['sess'] ?? ''));
        $keep = (int) lrgFollowerCfg('remember_seconds', 900);
        if ($last === '' || $sess === '' || (string) ($state['_fol_sess'] ?? '') !== $sess || $keep <= 0
            || lrgNow() - (int) ($state['_fol_at'] ?? 0) > $keep) { return []; }
        $raw = $last;
        $remembered = true;
    }
    $out = $remembered ? ['_remembered' => 1] : [];
    foreach (explode(',', $raw) as $pair) {
        $p = strpos($pair, ':');
        if ($p === false) { continue; }
        $out[trim(substr($pair, 0, $p))] = trim(substr($pair, $p + 1));
    }
    if (!isset($out['fw'])) { return []; }
    $slot = (string) ($out['slot'] ?? '');
    if ($slot !== '' && strpos($slot, '/') !== false) {
        [$u, $m] = array_pad(explode('/', $slot, 2), 2, '0');
        $out['_used'] = (int) $u;
        $out['_max'] = (int) $m;
    }
    return $out;
}

/** True while the snapshot is fresh enough to act on its follower facts. */
function lrgFolFresh(?array $state): bool
{
    if (!is_array($state)) { return false; }
    $max = (int) (lrgConfig()['snapshot_max_age_seconds'] ?? 90);
    return (int) ($state['_age'] ?? 9999) <= $max;
}

/** The follower facts of the NPC this request is answering as, or []. */
function lrgFolFor(string $npc): array
{
    if ($npc === '') { return []; }
    if (isset($GLOBALS['LRG_TEST_NPCSTATE'])) {
        $kv = (array) ($GLOBALS['LRG_TEST_NPCSTATE'][$npc] ?? []);
    } else {
        try {
            $kv = lrgGetNpcState($npc);
        } catch (Throwable $e) {
            return [];
        }
    }
    if (!is_array($kv) || !lrgFolFresh($kv)) { return []; }
    return lrgFolState($kv);
}

/**
 * (b) <companion_status>. Emitted only when the game really says she travels with the player, and it
 * never duplicates CHIM's own <adventuring_party> block, which keys off conf_opts.CurrentParty and
 * appears on its own once she is a real teammate (pt9 section 2.3).
 * The LAST line is the point of the whole block: the commands are her own dialogue, not the model's
 * to narrate, and not CHIM's shortcut to fire.
 */
function lrgFollowerBlock(string $npc, array $fol): string
{
    if (empty(lrgFollowerCfg('block', true)) || !$fol) { return ''; }
    $fw = (string) ($fol['fw'] ?? 'none');
    if ($fw === 'none') { return ''; }
    $player = (string) ($GLOBALS['PLAYER_NAME'] ?? 'the player');
    $lines = [];
    if ((int) ($fol['ghost'] ?? 0) === 1) {
        // she is NOT a companion of any framework: say so plainly rather than claim a bond
        $lines[] = $npc . ' has been travelling with ' . $player . ', but nothing has been settled'
            . ' properly between them - she is not sworn to him and he cannot give her orders.';
    } elseif ($fw === 'chim') {
        $lines[] = $npc . ' is walking with ' . $player . ' for now. She is not his companion and has'
            . ' made no promise to stay.';
    } else {
        $role = 'his companion';
        if (isset($fol['prim'])) { $role = (int) $fol['prim'] === 1 ? 'his closest companion' : 'one of his companions'; }
        $lines[] = $npc . ' travels with ' . $player . ' as ' . $role . '.';
        if (isset($fol['_used'], $fol['_max']) && (int) $fol['_max'] > 0) {
            $lines[] = 'He has ' . (int) $fol['_used'] . ' of ' . (int) $fol['_max'] . ' companions with him.';
        }
        // [0.5.4] a REMEMBERED fol= (the game stopped sending it this session) is good for who she is,
        // not for what she is doing this minute: the waiting / following line is left out then
        if (empty($fol['_remembered'])) {
            $lines[] = (int) ($fol['wait'] ?? 0) === 1
                ? 'Right now she is waiting where he left her, not following.'
                : 'Right now she is following him.';
        }
        $lines[] = $player . ' can ask her to wait, to follow again, to trade, to do him a favour or to'
            . ' part ways. Those are things she does, not things you describe: never say that one of'
            . ' them has happened unless it has.';
    }
    // [0.5.1 fix pass] SFF keeps this global itself and it is the framework's own answer, NOT the condition the
    // vanilla recruit entry uses: that entry is gated on PlayerFollowerCount == 0, which SFF sets to 1
    // as soon as it has any follower at all, while SFF_CanRecruitMore stays 1 until every slot is
    // used. So cap:0 means "the framework says there is no free slot" - true, and conservative,
    // because it is never 0 while a slot is free.
    if (isset($fol['cap']) && (int) $fol['cap'] === 0) {
        $lines[] = $player . ' cannot take anyone else with him at the moment.';
    }
    // [0.5.1 fix pass] The cap is applied to the BODY, never to the finished string: a raw substr over
    // "<companion_status>\n...\n</companion_status>" cut the closing tag off as soon as a long NPC
    // name met a long player name (the player name appears three times in the block), and the model
    // was handed an unclosed tag. Whole lines are dropped from the end until the body fits.
    $open = "<companion_status>\n";
    $close = "\n</companion_status>";
    $max = (int) lrgFollowerCfg('max_chars', 520);
    $room = $max - strlen($open) - strlen($close);
    while (count($lines) > 1 && strlen(implode("\n", $lines)) > $room) { array_pop($lines); }
    $body = implode("\n", $lines);
    if ($room > 0 && strlen($body) > $room) { $body = rtrim(substr($body, 0, $room)); }
    return $body === '' ? '' : $open . $body . $close;
}

/**
 * (c) The hide rule. Called at brace depth 0 from functions.php, LAST, so it only ever removes.
 * Three independent reasons, each a fact the game sent this moment:
 *   - she is already a teammate of some framework  -> a second recruit is meaningless
 *   - SFF says no slot is free (SFF_CanRecruitMore) -> the framework would refuse the recruit. NOT
 *                                                      the same test as the vanilla recruit entry,
 *                                                      which is gated on PlayerFollowerCount == 0;
 *                                                      this one is the looser of the two, so it only
 *                                                      ever hides an action that really cannot work
 *   - she is a GHOST                                -> the state CHIM's own action created; making it
 *                                                      worse is the one thing that must not happen
 * An absent fol= changes nothing at all.
 */
function lrgFollowerPolicy(): void
{
    if (!lrgEnabled()) { return; }
    if (!function_exists('lrgHideActions')) { return; }
    // [pt19 / script 511] QUIET MODE first: it reads the game's own quiet flag (facts line / snapshot), not fol=,
    // and it runs even with followers.enabled off. It only ever REMOVES, exactly like everything below.
    lrgQuietPolicy((string) ($GLOBALS['HERIKA_NAME'] ?? ''));
    if (empty(lrgFollowerCfg('enabled', true))) { return; }
    lrgFollowerCatalogHealth();
    $npc = (string) ($GLOBALS['HERIKA_NAME'] ?? '');
    $fol = lrgFolFor($npc);
    if (!$fol) { return; }
    $hide = [];
    $why = [];
    if ((int) ($fol['ghost'] ?? 0) === 1) {
        $hide = array_merge($hide, (array) lrgFollowerCfg('hide_when_ghost', []));
        $why[] = 'she is HALF-RECRUITED by CHIM (in the vanilla follower faction, not a teammate, in no'
            . ' framework alias) - the state that makes a dismissal hit the wrong follower';
    }
    if (!empty(lrgFollowerCfg('hide_make_follower', true))) {
        if (!empty(lrgFollowerCfg('hide_when_teammate', true)) && (int) ($fol['mate'] ?? 0) === 1) {
            $hide[] = 'MakeFollower';
            $why[] = 'she already travels with him (' . (string) ($fol['fw'] ?? '?') . ')';
        }
        if (!empty(lrgFollowerCfg('hide_when_cap_full', true)) && isset($fol['cap']) && (int) $fol['cap'] === 0) {
            $hide[] = 'MakeFollower';
            $why[] = 'the follower framework says there is no free slot (SFF_CanRecruitMore = 0)';
        }
    }
    // [pt17] a companion somebody OWNS (SFF, a custom follower's own quest, any teammate) is moved by her framework:
    // CHIM's own movement actions would lay a priority-100 override over it (the sprint-and-stop of playtests 16 / 17),
    // and the game side would only veto it at her next snapshot - so they are not offered at all
    $fw = (string) ($fol['fw'] ?? 'none');
    if (in_array($fw, ['sff', 'custom'], true) || (int) ($fol['mate'] ?? 0) === 1) {
        $hide = array_merge($hide, (array) lrgFollowerCfg('hide_when_owned', ['ComeCloser', 'FollowPlayer', 'Follow', 'MakeFollower', 'MoveTo', 'WaitHere', 'ReturnBackHome']));
        $why[] = 'her framework (' . $fw . ') moves her - CHIM\'s own movement would fight it';
    } elseif ((int) ($fol['chim'] ?? 0) === 1 && (int) ($fol['ghost'] ?? 0) !== 1) {
        // [pt17] CHIM's own follow is already on her: a ComeCloser (FollowSoft: travel, then a 30 s Wait at priority 55) on
        // an NPC 127 units away gave 35 s of standing still and a sprint on 2026-09-23 20:08:39; a second FollowPlayer only
        // re-adds the same package. The escort's "follow me" still reaches the game (lrgEscortPlan), so nothing is lost.
        $hide = array_merge($hide, (array) lrgFollowerCfg('hide_when_following', ['ComeCloser', 'MoveTo', 'FollowPlayer']));
        $why[] = 'CHIM\'s own follow is already on her (fol chim:1) - a second move would only make her stand, then sprint';
    }
    // function_exists: lrgIsOffered() lives in another lane's file. Every caller of this function
    // requires it first, but the guard makes the dependency impossible to break by moving a call.
    if (!function_exists('lrgIsOffered')) { return; }
    $hide = array_values(array_unique(array_filter($hide, static fn($c) => lrgIsOffered((string) $c))));
    if (!$hide) { return; }
    lrgHideActions($hide);
    lrgLog('follower: withheld ' . implode(', ', $hide) . ' from ' . $npc . ' - ' . implode('; ', $why));
}

/**
 * [pt19 / script 511] QUIET MODE, the server half (research/pt19-helgen.md section 7; PROTOCOL 10.28).
 * The GAME decides (LRG_Main.QuietOn(): a curated scripted quest - MQ101 from stage 5 - is running) and says
 * so on the wire: quiet=1 on the facts line (ev=facts; lrgDlgFactsFrom stores it with quiet_at) and on the
 * snapshot. While that flag is fresh for THIS NPC, CHIM's own movement / re-tasking actions
 * (lrg_actions.php LRG_MOVEMENT_ACTIONS, plus quiet.hide) are withheld from the offer - the follower
 * policy's shape exactly: an OFFER change, never a mute. She answers in words, and $GLOBALS['LRG_QUIET']
 * carries the one prompt line context_pre.php voices so the refusal has its reason (NEVER SILENT). One log
 * line per NPC per quiet spell (lrg_memory quiet_held) and one when it is released. Nothing here touches
 * CHIM's agents, its scene refusal or the OStim stop key; the kill switch (lrgEnabled) sits above it.
 */
function lrgQuietCfg(string $path, $default = null)
{
    static $cfg = null;
    if ($cfg === null) {
        $cfg = lrgMerge([
            'enabled' => true,
            'fresh_seconds' => 300,   // how old the last quiet=1 on the facts line may be (the game sends facts every 60 s while looked at)
            'hide' => [],             // withheld IN ADDITION to LRG_MOVEMENT_ACTIONS (which already holds EndConversation)
            'names' => ['MQ101' => 'the Helgen intro'],
            'line' => '<npc> is inside a scripted scene of the game (<quest>) - <npc> cannot be led, followed, sent away or dismissed until it is over, and says so plainly if asked.',
        ], (array) (lrgConfig()['quiet'] ?? []));
    }
    $v = $cfg;
    foreach (explode('.', $path) as $k) {
        if (!is_array($v) || !array_key_exists($k, $v)) { return $default; }
        $v = $v[$k];
    }
    return $v;
}

/** The game's quiet flag for this NPC, fresh: [src => facts|snapshot, id => MQ101|'', label => words for the log] or []. */
function lrgQuietFor(string $npc): array
{
    if ($npc === '') { return []; }
    $now = lrgNow();
    // (1) the facts line (lib/lrg_dialogue.php lrgDlgFactsFrom: quiet + quiet_at). The shipped curated list is MQ101
    // alone, so a fresh quiet=1 while mq101c != 1 is Helgen; the id only chooses the words, never a decision.
    if (function_exists('lrgDlgState')) {
        $facts = (array) (lrgDlgState($npc)['facts'] ?? []);
        if ((int) ($facts['quiet'] ?? 0) === 1 && $now - (int) ($facts['quiet_at'] ?? 0) <= (int) lrgQuietCfg('fresh_seconds', 300)) {
            $id = (isset($facts['mq101']) && (int) ($facts['mq101c'] ?? 0) !== 1) ? 'MQ101' : '';
            return ['src' => 'facts', 'id' => $id, 'label' => $id !== '' ? 'MQ101 stage ' . (int) $facts['mq101'] : 'a scripted intro'];
        }
    }
    // (2) the snapshot: quiet=1 within snapshot_max_age_seconds (lrgFolFresh's own rule)
    if (isset($GLOBALS['LRG_TEST_NPCSTATE'])) {
        $kv = (array) ($GLOBALS['LRG_TEST_NPCSTATE'][$npc] ?? []);
    } else {
        try { $kv = lrgGetNpcState($npc); } catch (Throwable $e) { $kv = null; }
    }
    if (is_array($kv) && (string) ($kv['quiet'] ?? '') === '1' && lrgFolFresh($kv)) {
        return ['src' => 'snapshot', 'id' => '', 'label' => 'a scripted intro'];
    }
    return [];
}

function lrgQuietPolicy(string $npc): void
{
    unset($GLOBALS['LRG_QUIET']);
    if ($npc === '' || empty(lrgQuietCfg('enabled', true))) { return; }
    if (!function_exists('lrgIsOffered') || !function_exists('lrgHideActions') || !defined('LRG_MOVEMENT_ACTIONS')) { return; }
    $q = lrgQuietFor($npc);
    $held = (int) (lrgMemGet($npc)['quiet_held'] ?? 0);
    if (!$q) {
        if ($held > 0) {
            lrgMemSet($npc, ['quiet_held' => null]);
            lrgLog('quiet: released ' . $npc . ' - no fresh quiet=1 from the game any more (CHIM\'s movement actions are back on the table)');
        }
        return;
    }
    $name = (string) (((array) lrgQuietCfg('names', []))[$q['id']] ?? 'a scripted intro of the game');
    $line = trim(str_replace(['<npc>', '<quest>'], [$npc, $name], (string) lrgQuietCfg('line', '')));
    $GLOBALS['LRG_QUIET'] = ['npc' => $npc, 'quest' => $q['label'], 'src' => $q['src'], 'line' => $line];
    $codes = array_values(array_unique(array_merge((array) LRG_MOVEMENT_ACTIONS, array_map('strval', (array) lrgQuietCfg('hide', [])))));
    $hide = array_values(array_filter($codes, static fn($c) => lrgIsOffered((string) $c)));
    if ($hide) { lrgHideActions($hide); }
    if ($held <= 0) {
        lrgMemSet($npc, ['quiet_held' => lrgNow()]);
        lrgLog('quiet: withheld ' . ($hide ? implode(', ', $hide) : 'nothing (no movement action was offered)') . ' from ' . $npc
            . ' (' . $q['label'] . ', from the ' . $q['src'] . ') - she answers in words; the prompt line carries the reason');
    }
}

/**
 * S6, health check only. Two rows of CHIM's catalog cannot work in this load order: MakeFollower ends
 * in a dead `if (nwsFF)` branch (NFF is not installed) and Follow is an unbounded priority-100 package
 * on another actor that nothing ever ends. Nothing is disabled here - the owner switches them off in
 * the CHIM web UI - but a live row is said once, in his log, where he is already looking.
 */
function lrgFollowerCatalogHealth(): void
{
    static $done = false;
    if ($done || !function_exists('herikaGetActionCatalogRow')) { return; }
    $done = true;
    foreach ((array) lrgFollowerCfg('warn_actions', []) as $code) {
        $row = herikaGetActionCatalogRow((string) $code);
        if (!is_array($row)) { continue; }
        $on = $row['is_activated'] ?? $row['isActivated'] ?? null;
        if ($on === null || (string) $on === 'f' || (int) $on === 0) { continue; }
        lrgLog('follower HEALTH: CHIM action ' . (string) $code . ' is still switched ON. '
            . 'This modlist has no Nether\'s Follower Framework, so MakeFollower half-recruits the NPC '
            . 'and Follow is an unbounded package with no end condition. Switch it off in the CHIM web '
            . 'UI (Actions) - see research/pt9-followers.md section 9.1.');
    }
}

/** Serana Dialogue Expansion: coarse words for the count of completed romance quests (snapshot key sde, -1 / absent = say nothing). */
function lrgSdeWords(array $state): string
{
    $n = $state['sde'] ?? '';
    if ($n === '' || !is_numeric($n) || (int) $n < 0) { return ''; }
    $w = (lrgConfig()['sde_words'] ?? []) + [
        '0' => 'their own romance story has not begun yet',
        '1' => 'their romance has only just begun', '2' => 'their romance has only just begun',
        '3' => 'their romance is well along', '4' => 'their romance is well along',
        '5' => 'they are already lovers',
    ];
    return (string) ($w[(string) min(5, (int) $n)] ?? '');
}
