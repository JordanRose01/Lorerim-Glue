<?php
/**
 * [0.4.2 / PT9] The pacing rails of lib/lrg_latency.php: WHEN an unsolicited turn of hers is allowed to
 * cost the player a wait. Nothing here is about what she may say - that is tools/test_gates.php and the
 * flow scenarios, and none of their rules is touched by this file.
 *
 * Usage (inside WSL, from a staged copy):  php tools/test_latency.php
 *
 * The rails answer questions about REAL elapsed time and REAL concurrency, so they are inert under the
 * offline harness's simulated clock unless a test asks for them ($GLOBALS['LRG_LATENCY_LIVE']). THAT is
 * what the last section checks: without the opt-in, an existing flow fixture behaves exactly as before.
 */

error_reporting(E_ALL);
ini_set('display_errors', 'stderr');

require __DIR__ . '/flows/harness.php';
require __DIR__ . '/flows/adapter.php';
require __DIR__ . '/flows/dlg_adapter.php';   // [pt19 v1.0] section 6b: the Phase 2 fixtures (fxDlgReset / fxDlgRow / fxDlgTopics)

fxLoadPlugin();
fx_install_error_handler();

$pass = 0; $fail = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? '  ok   ' : '  FAIL ') . $name . ($detail !== '' && !$ok ? "  [$detail]" : '') . "\n";
}

const LAT_NPC = 'Brenna Flowtest';
/** A scene with LAT_NPC is running and the snapshot is fresh; the clock starts at a known second. */
function lat_scene(): void
{
    fxReset();
    $GLOBALS['LRG_LATENCY_LIVE'] = 1;
    unset($GLOBALS['LRG_TEST_LOCK_BUSY']);
    fxSendSnapshot(LAT_NPC, fxSnap(['ostim' => '1']));
    fxSendScene(LAT_NPC, 'start', ['scene' => 'OARE_LatencyFixture', 'cid' => 'sLAT0001']);
}
/** One game -> server message through the real pre-lock entry point: 'handled' = dropped, 'pass' = it goes to the LLM. */
function lat_msg(string $type, string $text): string { return fxGameMessage([$type, fxNow(), 100000, $text], LAT_NPC); }
/** ADMISSION ONLY (the pre-lock hook), with no turn built behind it. */
function lat_admit(string $moment = 'the position just changed'): string { return lat_msg('lrg_scenetalk', FX_CTX . $moment); }
/**
 * A WHOLE scene-talk turn: admission, and - when it is admitted - the turn build that really produces
 * the line (context_pre.php -> lrgPrepareTurn). Since the 0.5.0 reconciliation the interval clock is
 * armed in the second half, not the first, so a rail test has to play both.
 */
function lat_talk(string $moment = 'the position just changed'): string
{
    return fxSceneTalk(LAT_NPC, fxSnap(['ostim' => '1']), $moment)->handled ? 'handled' : 'pass';
}
function lat_said(string $what = 'come here'): string { return lat_msg('inputtext', $what); }

echo "LoreRim Glue - PT9 latency rails (plugin " . (defined('LRG_VERSION') ? LRG_VERSION : '?') . ")\n";
$gap = (int) fxCfg('scene_talk.server_min_gap_seconds', 45);
$quiet = (int) fxCfg('scene_talk.player_quiet_seconds', 10);

echo "\n1. an ordinary in-scene scene-talk tick\n";
lat_scene();
check('a first tick in a quiet scene is admitted', lat_talk() === 'pass');
check('... and the next one, right behind it, is dropped (server interval ' . $gap . ' s)', lat_talk() === 'handled');
fxAdvance($gap + 1);
check('... and admitted again once the interval has passed', lat_talk() === 'pass');

lat_scene();
lat_said();
check('the player just spoke: her tick is dropped (quiet window ' . $quiet . ' s)', lat_talk() === 'handled');
fxAdvance($quiet - 1);
check('... still dropped one second before the window is over', lat_talk() === 'handled');
fxAdvance(2);
check('... admitted once the player has been quiet for ' . $quiet . ' s', lat_talk() === 'pass');

lat_scene();
check('a climax line may ignore the interval', lat_talk() === 'pass' && lat_talk('a peak was just reached') === 'pass');
lat_scene();
lat_said();
check('... but never the player: a climax line is dropped too while the player is talking', lat_talk('a peak was just reached') === 'handled');

// [0.5.0 reconciliation / verify D10] ADMISSION IS NOT A SPOKEN TURN. The clock used to be armed the
// moment the pre-lock hook let a tick through, so a tick that was admitted and then refused downstream
// (prompts.php re-checks the live scene, the snapshot's age, the adult flag, the child radius and the
// owner's switch before it defines a cue at all) blocked the next 45 s for a line nobody ever heard.
lat_scene();
check('an ADMITTED tick that never becomes a turn does not arm the interval', lat_admit() === 'pass' && (int) (fxMem(LAT_NPC)['scenetalk_at'] ?? 0) === 0);
check('... so the next tick is still admitted', lat_admit() === 'pass');
check('... and a real turn behind an admitted tick DOES arm it', lat_talk() === 'pass' && (int) (fxMem(LAT_NPC)['scenetalk_at'] ?? 0) === fxNow());

echo "\n2. the MAIN lock (a request is already in flight)\n";
lat_scene();
fxAdvance(600);
$GLOBALS['LRG_TEST_LOCK_BUSY'] = true;
check('MAIN busy: the tick is dropped before the lock', lat_talk() === 'handled');
check('MAIN busy: her lead tick is dropped too', lat_msg('lrg_scenetalk', FX_CTX . 'lead') === 'handled');
check('MAIN busy: an initiative tick is refused with that reason', (function () {
    $a = lrgInitiativeAdmit(LAT_NPC, 'approach');
    return empty($a['admit']) && str_contains((string) $a['why'], 'MAIN lock');
})());
check('MAIN busy: the player\'s own line is NEVER dropped', lat_said() === 'pass');
unset($GLOBALS['LRG_TEST_LOCK_BUSY']);
check('MAIN free again: nothing is dropped for that reason', lrgMainLockBusy() === null || lrgMainLockBusy() === false);

echo "\n3. the outro is never gated\n";
lat_scene();
lat_said();                       // the player is talking: every ordinary tick is dropped right now
$GLOBALS['LRG_TEST_LOCK_BUSY'] = true;
check('the closing line of a scene passes the rails (busy lock, player talking)', lat_msg('lrg_scenetalk', FX_CTX . 'outro') === 'pass');
unset($GLOBALS['LRG_TEST_LOCK_BUSY']);

echo "\n4. her own move outside a scene (lrg_initiative)\n";
fxReset();
$GLOBALS['LRG_LATENCY_LIVE'] = 1;
$snap = fxSnap([]);
fxSendSnapshot(LAT_NPC, $snap);
fxMemSet(LAT_NPC, ['heat' => 1]);   // the glue already tracks her: a row exists (see the laziness check below)
lat_said();
check('the player spoke a moment ago: her initiative tick is refused for that reason', (function () {
    $a = lrgInitiativeAdmit(LAT_NPC, 'approach');
    return empty($a['admit']) && str_contains((string) $a['why'], 'quiet window');
})());
check('... and the clock really was written outside a scene', (int) (fxMem(LAT_NPC)['player_spoke_at'] ?? 0) === fxNow());
fxAdvance((int) fxCfg('initiative.player_quiet_seconds', 10) + 1);
check('... once the player is quiet the quiet rail no longer refuses it', (function () {
    $a = lrgInitiativeAdmit(LAT_NPC, 'approach');
    return !str_contains((string) $a['why'], 'quiet window');
})());

// [0.5.0 reconciliation / verify D11] THE CLOCK IS LAZY. The first version upserted, so every NPC the
// player ever spoke to got an lrg_memory row - a table that grows with the population of Skyrim to
// protect a rail worth 0.1 s in the measured session. It is now UPDATE-ONLY.
fxReset();
$GLOBALS['LRG_LATENCY_LIVE'] = 1;
fxSendSnapshot('Passing Stranger', fxSnap([]));
fxGameMessage(['inputtext', fxNow(), 100000, 'good morning'], 'Passing Stranger');
check('an NPC the glue does not track gets NO memory row from a player line', fxMem('Passing Stranger') === []);
check('... and her own tick is still refused, by the gates rather than by the clock', (function () {
    $a = lrgInitiativeAdmit('Passing Stranger', 'approach');
    return empty($a['admit']) && !str_contains((string) $a['why'], 'quiet window');
})());

echo "\n5. inert under the offline harness (no opt-in): the flow fixtures behave exactly as before\n";
lat_scene();
unset($GLOBALS['LRG_LATENCY_LIVE']);      // the flow scenarios never set it
lat_said();
check('a tick in the same simulated second as a player line still passes', lat_talk() === 'pass');
check('... and so does the one right behind it', lat_talk() === 'pass');
$GLOBALS['LRG_TEST_LOCK_BUSY'] = true;
check('the lock seam is ignored as well', lat_talk() === 'pass');
unset($GLOBALS['LRG_TEST_LOCK_BUSY']);
check('an initiative tick is not refused for a latency reason', (function () {
    $a = lrgInitiativeAdmit(LAT_NPC, 'approach');
    return !str_contains((string) $a['why'], 'quiet window') && !str_contains((string) $a['why'], 'MAIN lock');
})());

echo "\n6. [0.5.5 / owner addendum 11] what NEVER SILENT costs, and what it saves\n";
// The glue's own share is the pre-lock verdict on a funcret (lrgFuncretVerdict): a success - by far the
// most common result - now ENDS there instead of passing on to wait for CHIM's MAIN lock and a full prompt
// build before funcret.php ended it (pt9-latency.md: 24 funcrets, 255 ms median under MAIN, 8.2 s worst
// case of lock wait). A failure the player was waiting on costs ONE LLM + TTS turn, the one CHIM already
// runs for a funcret with a follow-up; it replaces a silence, and nothing else the glue does calls an LLM.
fxReset();
fxSetScore(LAT_NPC, fxSnap([]), 10);
fxWarm(LAT_NPC, fxSnap([]));
fxSay(LAT_NPC, fxSnap([]), 'Take off your clothes.');
$latW = fxLlm(LAT_NPC, FX_ACT_CLOTHING, 'undress');
check('set-up: a ChangeClothing command went out', count($latW) === 1);
$latN = 200;
$t0 = microtime(true);
for ($i = 0; $i < $latN; $i++) { fxGameMessage(['funcret', fxNow(), 100000, 'command@' . FX_ACT_CLOTHING . '@' . fxParam($latW[0]) . '@' . LAT_NPC . ' undresses.']); }
$okMs = (microtime(true) - $t0) * 1000 / $latN;
$t0 = microtime(true);
for ($i = 0; $i < $latN; $i++) {
    fxAdvance(10); // past voice.min_gap_seconds, so every one of them is judged "voice it"
    fxGameMessage(['funcret', fxNow(), 100000, 'command@' . FX_ACT_CLOTHING . '@' . fxParam($latW[0]) . '@Error: someone is watching']);
}
$errMs = (microtime(true) - $t0) * 1000 / $latN;
// Most of it is lrgLog() appending to lorerim_glue.log: on the staged copy that file lives on /mnt/c (9P), where
// one append costs about a millisecond; on the server the log is on the distro's own disk.
printf("       pre-lock verdict per funcret (in-memory DB, log on the staging disk): success %.3f ms, voiced failure %.3f ms\n", $okMs, $errMs);
check('the pre-lock verdict on a SUCCESS costs a few ms, not the 255 ms median it used to spend under the MAIN lock', $okMs < 25.0, sprintf('%.3f ms', $okMs));
check('the pre-lock verdict on a voiced FAILURE costs a few ms too (the one LLM turn after it is CHIM\'s own)', $errMs < 25.0, sprintf('%.3f ms', $errMs));
fxAdvance(10);
check('a success really ends before the lock, a failure really goes on to the funcret turn',
    fxGameMessage(['funcret', fxNow(), 100000, 'command@' . FX_ACT_CLOTHING . '@' . fxParam($latW[0]) . '@' . LAT_NPC . ' undresses.']) === 'handled'
    && fxGameMessage(['funcret', fxNow(), 100000, 'command@' . FX_ACT_CLOTHING . '@' . fxParam($latW[0]) . '@Error: someone is watching']) === 'pass');


echo "\n6b. [pt19 v1.0 / S2.1, S10] the pre-LLM open: what the narrow marker costs on HIS sentence (the spec's 'section 6')\n";
// Numbered 6b so the shipped section 6 keeps its number. What is timed is the glue's OWN share of the pre-LLM path:
// lrgDlgBusinessMarker(narrow) - clause 1 (join), 2 (a service kind), 3 (her cached root, F16), 4 (a verbatim top-level
// prompt with >= 3 strict words that <= 3 topics carry - pt19c-A fix 1), 5 (her journal-quest rows) - over a real-sized row set through the in-memory index seam.
// The Postgres round trips are NOT in these numbers (offline): clause 4's exact-norm lookup is the one the marker already
// ran before v1.0, clause 5's row set is ONE round trip per NPC per session and is then cached in a row of its own (lrgDlgQRowsKey).
fxDlgReset();
$latNpc = 'Hulda Latency';
$latRoot = ['What have you got for sale?', "I'd like to rent a room. (10 gold)", 'Nice inn you have here. Do you get many visitors?',
    'Heard any rumors lately?', 'Can I get a drink?', 'Tell me about Whiterun.', 'What do you know about the Companions?', 'Goodbye.'];
$latIdx = [];
// (the inn line carries the live row's topic editor id: clause 4 opens only on a row HER list can carry - it names her)
foreach ($latRoot as $i => $tx) {
    $latIdx[] = fxDlgRow($tx, ['quest' => 'LatGeneric', 'info_key' => 'lat.esp:root' . $i] + ($i === 2 ? ['topic' => 'ACFDialogueWhiterunHuldaBranchChatTopic'] : []));
}
$latVerbs = ['bring', 'find', 'carry', 'deliver', 'return', 'recover', 'steal', 'guard', 'escort', 'repair'];
$latThings = ['the shield', 'the amulet', 'the letter', 'the ledger', 'the horn', 'the claw', 'the crown', 'the map', 'the sword', 'the ring'];
$latPlaces = ['to Riverwood', 'from the barrow', 'to the Jarl', 'past the gate', 'before nightfall', 'to the temple'];
for ($i = 0; $i < 299; $i++) {   // + the shield row = open.qrows_cap (300): the most her quests can put in front of the matcher
    $tx = sprintf('I will %s %s %s, as you asked.', $latVerbs[$i % 10], $latThings[intdiv($i, 10) % 10], $latPlaces[intdiv($i, 100) % 6] . ($i >= 100 ? ' ' . $i : ''));
    $latIdx[] = fxDlgRow($tx, ['quest' => 'LatJournal', 'journal' => 1, 'toplevel' => $i % 4 === 0 ? 1 : 0, 'info_key' => 'lat.esp:j' . $i]);
}
$latIdx[] = fxDlgRow('I have your shield.', ['quest' => 'LatJournal', 'journal' => 1, 'info_key' => 'lat.esp:shield']);
for ($i = 0; $i < 1500; $i++) {  // other speakers' lines: clause 4 scans past them for his exact sentence
    $latIdx[] = fxDlgRow(sprintf('Filler line number %d about %s.', $i, $latThings[$i % 10]), ['quest' => 'LatOther' . ($i % 40), 'toplevel' => $i % 2]);
}
$GLOBALS['LRG_TEST_INDEX'] = ['rows' => $latIdx, 'layers' => []];
$latSnap = fxDlgSnap(['fac' => 'JobInnkeeperFaction,TownWhiterunFaction', 'class' => '']);
fxSendSnapshot($latNpc, $latSnap);
lrgDlgPut($latNpc, ['q' => ['LatJournal']]);
$latT = ['npc' => $latNpc, 'cid' => 'lat6b', 'q' => ['LatJournal'], 'snap' => $latSnap, 'faction' => [], 'clicks_ok' => 1];
$latSay = ['Nice inn you have here, do you get many visitors?', 'I saw a dragon near the river', 'I have your shield', 'what have you got?'];
/** ms per marker call, averaged over $n rounds of every sentence; $cold clears her q-row cache (and her root) before each call. */
$latRun = static function (bool $cold, int $n) use ($latNpc, $latT, $latSay): array {
    $sum = 0.0; $max = 0.0; $calls = 0; $clauses = [];
    for ($k = 0; $k < $n; $k++) {
        foreach ($latSay as $s) {
            if ($cold) { lrgDlgPut(lrgDlgQRowsKey($latNpc), ['qrows' => null]); }
            $t0 = hrtime(true);
            $c = lrgDlgBusinessMarker($latT, $s, true, $hit);
            $ms = (hrtime(true) - $t0) / 1e6;
            $sum += $ms; $max = max($max, $ms); $calls++;
            $clauses[$s] = $c;
        }
    }
    return ['avg' => $sum / max(1, $calls), 'max' => $max, 'clauses' => $clauses];
};
$latCold = $latRun(true, 25);
fxDlgTopics($latNpc, ['sid' => 'lat1', 'layer' => 0, 'gen' => 0, 'want' => 0], array_map(static fn($i, $tx) => fxDlgEntry($i, $tx), array_keys($latRoot), $latRoot));
fxDlgEvent($latNpc, 'closed', ['sid' => 'lat1', 'why' => 'goodbye', 'pending' => 0, 'layer' => 0]);
lrgDlgBusinessMarker($latT, 'hello there traveller', true, $hit);   // primes the q-row cache (one read per session)
$latWarm = $latRun(false, 100);
printf("       marker path per sentence (in-memory index seam, %d rows; 300 journal rows for her quest): warm %.3f ms avg / %.3f ms max, cold %.3f ms avg / %.3f ms max\n",
    count($latIdx), $latWarm['avg'], $latWarm['max'], $latCold['avg'], $latCold['max']);
check('set-up: the four sentences reach the clauses they are meant to (cold: toplevel, none, qrows, kind)',
    array_values($latCold['clauses']) === ['toplevel', '', 'qrows', 'kind'], json_encode($latCold['clauses']));
check('set-up: warm, her cached root answers the inn line (root) and the q-row cache is primed',
    array_values($latWarm['clauses']) === ['root', '', 'qrows', 'kind'] && count((array) (lrgDlgGet(lrgDlgQRowsKey($latNpc))['qrows']['rows'] ?? [])) === 300
    && lrgDlgBusinessMarker($latT, 'I have your shield', true, $hit) === 'qrows' && (string) $hit['row'] === 'lat.esp:shield',
    json_encode($latWarm['clauses']));
check('WARM (root + q-row cache primed): the marker path costs <= 5 ms per sentence (spec S10)', $latWarm['avg'] <= 5.0, sprintf('%.3f ms', $latWarm['avg']));
check('COLD (no q-row cache: the row set is read and cached on this call): < 30 ms per sentence, asserted separately (spec S10)',
    $latCold['avg'] < 30.0, sprintf('%.3f ms', $latCold['avg']));
if (Fx::$phpIssues) {
    echo "\nPHP warnings / notices:\n";
    foreach (Fx::$phpIssues as $k => $n) { echo "  {$n}x  $k\n"; }
}
echo "\n$pass passed, $fail failed\n";
exit($fail || Fx::$phpIssues ? 1 : 0);
