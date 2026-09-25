<?php
/**
 * LoreRim Glue - [0.5.0 / PT9] THE LATENCY RAILS.  (header said 0.4.2; corrected in the 0.5.0 fix pass)
 *
 * Measured on the owner's own session (research/pt9-latency.md): the player hears the NPC ~7.4 s after
 * releasing push-to-talk, 21 s at p90. The glue's own share of that is 151.9 s over 71 player lines, and
 * 74 % of it is ONE thing: an in-scene `lrg_scenetalk` turn that takes CHIM's MAIN lock and holds it for
 * a whole LLM + TTS stretch (7.9 s median, 18.9 s p90, 22.6 s max) - sometimes right on top of the line
 * the player just spoke. Her `lrg_initiative` lead-in has the same shape (3 of 12 spoke, 7.0-10.4 s each).
 *
 * Nothing here changes WHAT she may say: every rail, every gate and the owner's explicitness are
 * untouched. All this file decides is WHEN an unsolicited turn of hers is allowed to cost the player a
 * wait - and it decides it BEFORE CHIM's MAIN semaphore (main.php:243), so a dropped tick costs zero
 * LLM tokens, zero TTS and zero lock time.
 *
 * Three rails, in the order they are cheapest to test:
 *   1. MAIN is busy          somebody else (almost always the player's own line) holds the lock right
 *                            now: her tick would queue behind it and then delay everything behind IT.
 *   2. the player just spoke the player is in the middle of a conversation (scene_talk.player_quiet_seconds)
 *   3. too soon              a minimum interval between two unsolicited spoken turns (server_min_gap_seconds),
 *                            measured on the SERVER, behind the game's own MCM gap and dice.
 * The outro (text "outro") is never gated here: it is the one closing line of a whole scene, it fires at
 * most once per scene, and the game is holding the NPC still while it waits for it.
 */

require_once __DIR__ . '/lrg_core.php';

/**
 * Are the pacing rails in force? They answer questions about REAL elapsed time and REAL concurrency, so
 * they are inert wherever the glue is driven by the offline harness's simulated clock
 * ($GLOBALS['LRG_TEST_NOW'], PROTOCOL 7.2): a flow fixture deliberately plays a tick in the same
 * simulated second as a player line, which is exactly the shape these rails drop, and a contract test
 * about WHAT she may say must not start failing because of WHEN it plays its steps.
 * A test that wants the rails asks for them with $GLOBALS['LRG_LATENCY_LIVE'] (tools/test_latency.php).
 * On the live server neither global is ever set, so every rail is always in force there.
 */
function lrgLatencyLive(): bool
{
    return !empty($GLOBALS['LRG_LATENCY_LIVE']) || !isset($GLOBALS['LRG_TEST_NOW']);
}

/** Latency knobs of one section, with the contract defaults behind them. */
function lrgLatencyCfg(string $section): array
{
    $def = [
        'scene_talk' => ['server_min_gap_seconds' => 45, 'player_quiet_seconds' => 10, 'skip_when_busy' => true,
            'gap_exempt_moments' => ['peak']],
        'lead' => ['skip_when_busy' => true],
        'initiative' => ['player_quiet_seconds' => 10, 'skip_when_busy' => true],
    ][$section] ?? [];
    return ((array) (lrgConfig()[$section] ?? [])) + $def;
}

/**
 * Is CHIM's MAIN semaphore held by somebody else RIGHT NOW? true = busy, false = free, null = cannot tell.
 *
 * CHIM's lock is a System V semaphore keyed `abs(crc32("MAIN"))` (lib/semaphore_manager.class.php:12-30),
 * taken at main.php:243 and held for the whole LLM + TTS stretch. Every ext hook that can reach this
 * function runs at main.php:193 - BEFORE this request takes the lock - so a non-blocking acquire answers
 * exactly one question: "is another request in flight?".
 *
 * The probe takes the lock for microseconds and releases it in the same statement sequence; it never
 * holds it across anything that can block. If the release were ever missed, PHP's own auto_release frees
 * it at the end of the request (sem_get's default), so a stuck lock is not a failure mode here.
 * Answered once per request.
 *
 * CLI (the offline tests) returns null unless $GLOBALS['LRG_FORCE_LOCK_PROBE'] is set: a flow run on the
 * owner's machine must not become flaky because he happens to be playing while it runs.
 */
function lrgMainLockBusy(): ?bool
{
    if (isset($GLOBALS['LRG_TEST_LOCK_BUSY'])) { return (bool) $GLOBALS['LRG_TEST_LOCK_BUSY']; } // test seam, never cached
    static $answer = 'unset';
    if ($answer !== 'unset') { return $answer; }
    $answer = null;
    if (!lrgLatencyLive() || PHP_SAPI === 'cli') { return $answer; }
    if (!function_exists('sem_get') || !function_exists('sem_acquire') || !function_exists('sem_release')) { return $answer; }
    $sem = @sem_get(abs(crc32('MAIN')));
    if ($sem === false) { return $answer; }
    try {
        if (@sem_acquire($sem, true) !== true) { return $answer = true; }
    } catch (Throwable $e) {
        return $answer; // unknown: never drop a turn because the probe itself failed
    }
    @sem_release($sem);
    return $answer = false;
}

/** '' = this unsolicited turn may speak; otherwise the reason it must not, in words for the log. */
function lrgTickBusyReason(string $section): string
{
    $cfg = lrgLatencyCfg($section);
    if (empty($cfg['skip_when_busy'])) { return ''; }
    return lrgMainLockBusy() === true ? 'another request holds the MAIN lock (it would queue behind a player line)' : '';
}

/** How long ago the player last said anything to this NPC, -1 = never (both clocks, PROTOCOL 7.2). */
function lrgPlayerSpokeAgo(string $npc, ?array $mem = null): int
{
    $mem = $mem ?? lrgMemGet($npc);
    $at = max((int) ($mem['player_spoke_at'] ?? 0), (int) ($mem['player_request_at'] ?? 0));
    return $at > 0 ? max(0, lrgNow() - $at) : -1;
}

/**
 * [PT9] Why an ORDINARY in-scene scene-talk tick must not fire right now ('' = it may). The lead tick has
 * its own, older hold (lrgLeadHoldLeft); the outro is never asked about here.
 *
 * $moment is the wire text the game sent ("the position just changed", "a peak was just reached", ...):
 * a moment listed in gap_exempt_moments still has to pass the other two rails, but not the interval.
 */
function lrgSceneTalkHoldLeft(string $moment = '', string $npc = ''): string
{
    if (!lrgLatencyLive()) { return ''; }
    $scene = lrgGetActiveScene();
    if ($scene === null) { return ''; } // no live scene: prompts.php refuses the cue by itself, nothing to gate
    $npc = $npc !== '' ? $npc : (string) $scene['_npc'];
    $cfg = lrgLatencyCfg('scene_talk');
    $busy = lrgTickBusyReason('scene_talk');
    if ($busy !== '') { return $busy; }
    $mem = lrgMemGet($npc);
    $quiet = (int) ($cfg['player_quiet_seconds'] ?? 10);
    $ago = lrgPlayerSpokeAgo($npc, $mem);
    if ($quiet > 0 && $ago >= 0 && $ago < $quiet) { return 'the player spoke ' . $ago . 's ago (quiet window ' . $quiet . 's)'; }
    $gap = (int) ($cfg['server_min_gap_seconds'] ?? 45);
    $last = (int) ($mem['scenetalk_at'] ?? 0);
    if ($gap > 0 && $last > 0 && lrgNow() - $last < $gap) {
        $m = strtolower(lrgStripContext($moment));
        foreach ((array) ($cfg['gap_exempt_moments'] ?? []) as $word) {
            $word = strtolower(trim((string) $word));
            if ($word !== '' && str_contains($m, $word)) { return ''; } // a climax is worth its own line
        }
        return 'her last spoken scene turn was ' . (lrgNow() - $last) . 's ago (minimum ' . $gap . 's)';
    }
    return '';
}

/** Remember that she has just been given a spoken turn inside the scene (lead ticks count too). */
function lrgNoteSceneTalk(string $npc): void
{
    if ($npc === '') { return; }
    lrgMemSet($npc, ['scenetalk_at' => lrgNow()]);
}
