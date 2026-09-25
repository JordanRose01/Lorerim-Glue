<?php
// Offline test of Layer 1 gates, profiles, interest, turn modes, invitations, initiative, the verb set,
// prompt text and the post-LLM gate (PROTOCOL.md v2). Rules are asserted, never example lines.
// No HerikaServer needed: a tiny in-memory fake stands in for $GLOBALS['db'].
// Usage (inside WSL):  php tools/test_gates.php
// stand-in for CHIM's catalog writer: captures the rows lrgEnsureActions() installs (section 23)
function herikaActionCatalogUpsertCustomRow($row) { $GLOBALS['LRG_TEST_ROWS'][$row['code_name']] = $row; return true; }
require __DIR__ . '/../server/lorerim_glue/lib/lrg_actions.php';

class FakeDb {
    public array $t = [];
    function upsertRowOnConflict($table, $data, $key) { $this->t[$table][$data[$key]] = $data; return true; }
    function escapeLiteral($s) { return "'" . str_replace("'", "''", (string) $s) . "'"; }
    function execQuery($q) { return true; }
    function fetchOne($q) {
        if (!preg_match('/FROM (\w+)/', $q, $m)) { return []; }
        $rows = $this->t[$m[1]] ?? [];
        if (preg_match("/npc_name='((?:[^']|'')*)'/", $q, $n)) { return $rows[str_replace("''", "'", $n[1])] ?? []; }
        foreach ($rows as $r) { if (($r['active'] ?? 0) == 1) { return $r; } }
        return [];
    }
}
$GLOBALS['db'] = new FakeDb();
$GLOBALS['ENABLED_FUNCTIONS'] = ['Talk', 'Follow', 'MoveTo', LRG_ACT_START, LRG_ACT_CONTROL];
@mkdir(LRG_DIR . '/data', 0770, true);
touch(LRG_DIR . '/data/.schema_v' . LRG_SCHEMA_VERSION); // skip real migrations
@unlink(LRG_DIR . '/data/.actions_v' . LRG_ACTIONS_VERSION); // let the first turn install the catalog rows into the stand-in
if (function_exists('lrgIndexWarm')) { lrgIndexWarm(); } // v2 index: it never builds by itself outside the warm-up

$pass = 0; $fail = 0;
function check(string $name, bool $ok, string $detail = '') { global $pass, $fail; $ok ? $pass++ : $fail++; echo ($ok ? '  ok   ' : '  FAIL ') . $name . ($detail !== '' ? "  [$detail]" : '') . "\n"; }
function snap(array $over = []): array {
    return $over + ['v' => 1, 'on' => '1', 'adult' => '1', 'combat' => '0', 'scene' => '0', 'ostim' => '0', 'married' => '0', 'pspouse' => '0', 'courting' => '0',
        // [0.4 / precision 7.5] pgold was MISSING from this fixture, so every paid-intimacy assertion
        // would have read a purse of 0 and nothing would ever have been accepted. Per case overrides win.
        'pgold' => '500',
        'rank' => '0', 'moral' => '2', 'gold' => '40', 'wit' => '0', 'witfol' => '0', 'witkid' => '0', 'pdb' => '0', 'pquests' => '3', 'class' => '', 'fac' => 'JobInnkeeperFaction,TownWhiterunFaction'];
}
const TEST_ENABLED = ['Talk', 'Follow', 'MoveTo', 'TravelTo', 'FollowPlayer', 'GiveGoldTo', LRG_ACT_START, LRG_ACT_CONTROL, LRG_ACT_CLOTHING, LRG_ACT_INVITE, LRG_ACT_REQUESTACT];
/**
 * [0.3 / 11.2] Two romantic exchanges, as a player would really have them. Without them BeginIntimacy is
 * correctly HIDDEN (a question about sex is answered in words on a cold first turn), and every check below
 * would silently be about the heat gate instead of about its own subject. Section 29 tests the gate itself.
 */
function warmUp(string $npc, array $state): void {
    foreach (['You look beautiful tonight.', 'I want you.'] as $line) {
        lrgStoreNpcState($npc, $state);
        $GLOBALS['HERIKA_NAME'] = $npc; $GLOBALS['gameRequest'] = ['inputtext', lrgNow(), 100 + (++$GLOBALS['LRG_TEST_SEQ']), $line];
        $GLOBALS['ENABLED_FUNCTIONS'] = TEST_ENABLED;
        lrgPrepareTurn();
    }
}
function turn(string $npc, array $state, string $type = 'inputtext', string $text = 'hi', bool $warm = true): array {
    lrgStoreNpcState($npc, $state);
    if ($warm && in_array($type, LRG_PLAYER_SPEECH_TYPES, true)) { warmUp($npc, $state); }
    lrgStoreNpcState($npc, $state);
    $GLOBALS['HERIKA_NAME'] = $npc; $GLOBALS['gameRequest'] = [$type, lrgNow(), 100 + (++$GLOBALS['LRG_TEST_SEQ']), $text]; // every call is a new request
    $GLOBALS['ENABLED_FUNCTIONS'] = TEST_ENABLED;
    lrgPrepareTurn();
    return $GLOBALS['LRG_TURN'];
}
/**
 * [0.3 / R10] What she SAID this turn. The plugin drops a change she chose herself when the reply carried
 * no spoken line, so the default here is "she said one line"; the silent path is tested explicitly.
 */
$GLOBALS['talkedSoFar'] = ['one short spoken line'];
/**
 * [0.3 / G2] The wire lines without the HOLD CARRIER: on a player speech turn inside a scene that produced
 * no command of its own, the server appends do=lead;who=<the leader the scene already has>;hold=<n> so the
 * game also stops taking lead turns. It changes nothing in the scene.
 */
function real(array $w): array {
    return array_values(array_filter($w, fn($l) => !preg_match('/;do=lead;who=(?:npc|player|auto);hold=\d+\s*$/', (string) $l)));
}
/**
 * [0.3 / PROTOCOL 2.2] A wire line without the four decoration keys the server appends LAST (warp, nowarp,
 * wait, hold). They are additive and a game that does not know them ignores them, so a v0.2 shape assertion
 * stays valid once they are stripped. The keys themselves are asserted in their own sections.
 */
function undecorate(string $line): string {
    // warp= is NOT stripped: it is a v0.2 contract key of goto and winddown, and its own checks rely on it
    return (string) preg_replace('/;(?:nowarp|wait|hold)=[^;\r\n]*/', '', $line);
}
$GLOBALS['LRG_TEST_SEQ'] = 0;
/** A guidance block, cut down to something readable in a failure line. */
function fx_cut(string $s, int $n = 220): string { return substr((string) preg_replace('/\s+/', ' ', $s), 0, $n); }
/** CHIM's relationship system, as far as the glue reads it: affinity per NPC (0 = the v1 tests' stranger). */
/**
 * Stand-in for CHIM's RelationshipManager. [0.3.1] It now mirrors the three methods the glue really
 * uses: getRelationships() is what tells a MISSING entry apart from a genuine 0 (getRelationship()
 * cannot, which is the hole behind R1c), and adjust/set are what the scene gain and the repair write
 * through. $aff has no entry at all = CHIM has no relationship for that NPC.
 */
class RelationshipManager {
    public static array $aff = [];
    public static array $calls = [];
    static function getPlayerRelationship($npc) { return ['aff' => self::$aff[$npc] ?? 0, 'type' => 'neutral']; }
    static function getRelationships($npc) { return array_key_exists($npc, self::$aff) ? ['Player' => ['aff' => self::$aff[$npc], 'type' => 'neutral']] : []; }
    static function adjustRelationship($npc, $target, $delta) { self::$calls[] = "adjust:$npc:$delta"; self::$aff[$npc] = max(-100, min(100, (self::$aff[$npc] ?? 0) + (int) $delta)); return true; }
    static function setRelationship($npc, $target, $aff, $type = null) { self::$calls[] = "set:$npc:$aff:" . ($type === null ? 'null' : $type); self::$aff[$npc] = max(-100, min(100, (int) $aff)); return true; }
}
/** The game's initiative tick for $npc, played through preprocessing: 'handled' (dropped) or 'pass' (admitted). */
function tick(string $npc, array $state, string $text = '(Context location: Inn) approach'): string {
    lrgStoreNpcState($npc, $state);
    $GLOBALS['HERIKA_NAME'] = $npc; $GLOBALS['gameRequest'] = ['lrg_initiative', lrgNow(), 100 + (++$GLOBALS['LRG_TEST_SEQ']), $text];
    return lrgHandleGameMessage($GLOBALS['gameRequest']);
}
/** After an admitted tick: the rest of the request in CHIM's order (prerequest at main.php:1117, functions.php later). */
function tickTurn(): array {
    $GLOBALS['FUNCTIONS_ARE_ENABLED'] = false; unset($GLOBALS['ENABLED_FUNCTIONS']);
    lrgPrerequest();
    $GLOBALS['ENABLED_FUNCTIONS'] = ['Talk', 'Follow', 'MoveTo', 'TravelTo', 'FollowPlayer', 'GiveGoldTo', LRG_ACT_START, LRG_ACT_CONTROL, LRG_ACT_CLOTHING, LRG_ACT_INVITE];
    lrgPrepareTurn();
    return $GLOBALS['LRG_TURN'];
}
$offered = fn(string $code) => in_array($code, $GLOBALS['ENABLED_FUNCTIONS'], true);

echo "1. stranger innkeeper, private\n";
$t = turn('Hulda', snap());
check('not offered', !$offered(LRG_ACT_START), implode(',', $t['gate']['reasons']));
check('reason = not_close_enough', $t['gate']['reasons'] === ['not_close_enough']);
check('profile = innkeeper', ($t['gate']['profile']['id'] ?? '') === 'innkeeper');
check('boundary text present and anti-agreeable', str_contains(lrgStaticGuidance($t), 'being agreeable is not a reason'));

echo "2. same innkeeper, friend (rank 3 -> +18)\n";
$t = turn('Hulda', snap(['rank' => '3']));
check('offered', $offered(LRG_ACT_START), 'aff=' . $t['gate']['affinity']);
$wire = lrgPostProcessActions(["Hulda|command|" . LRG_ACT_START . "@Player\r\n"]);
check('gate rewrites the parameter', count($wire) === 1 && str_contains($wire[0], '@ok=1;cid=') && str_contains($wire[0], 'maxwit=0'), trim($wire[0] ?? ''));
check('SceneControl hidden outside a scene', !$offered(LRG_ACT_CONTROL));

echo "3. witness present\n";
$t = turn('Hulda', snap(['rank' => '3', 'wit' => '2']));
check('not offered: witnesses', !$offered(LRG_ACT_START) && in_array('witnesses', $t['gate']['reasons'], true));
check('NPC is told why', str_contains(lrgVolatileGuidance($t), 'other people are close enough'));
check('LLM hallucinated action is dropped', real(lrgPostProcessActions(["Hulda|command|" . LRG_ACT_START . "@Player"])) === []);

echo "4. child nearby / not adult -> silence\n";
$t = turn('Hulda', snap(['rank' => '4', 'witkid' => '1']));
check('blocked', !$offered(LRG_ACT_START));
check('no romance text at all', lrgStaticGuidance($t) === '' && lrgVolatileGuidance($t) === '');
$t = turn('Braith', snap(['adult' => '0']));
check('non-adult: blocked and silent', !$offered(LRG_ACT_START) && lrgStaticGuidance($t) === '');

echo "5. Jarl with a follower in the room\n";
$t = turn('Elisif the Fair', snap(['rank' => '4', 'witfol' => '1', 'fac' => 'JobJarlFaction,SolitudeBluePalaceFaction']));
check('profile = jarl', ($t['gate']['profile']['id'] ?? '') === 'jarl');
check('companion blocks a strict profile', in_array('companion_present', $t['gate']['reasons'], true));

echo "6. married commoner / Dibellan priestess / Vigilant / unknown mod faction\n";
$t = turn('Sigrid', snap(['rank' => '4', 'married' => '1', 'fac' => 'JobFarmerFaction']));
check('married -> refuse', in_array('married', $t['gate']['reasons'], true));
$t = turn('Senna', snap(['rank' => '1', 'fac' => 'MarkarthTempleofDibellaFaction,JobPriestFaction']));
check('Dibella rule wins over generic priest, offered at low affinity', ($t['gate']['profile']['id'] ?? '') === 'priest_dibella' && $offered(LRG_ACT_START));
$t = turn('Vigilant Tolan', snap(['rank' => '4', 'fac' => 'VigilantOfStendarrFaction']));
check('Vigilant: never', in_array('never', $t['gate']['reasons'], true) && !$offered(LRG_ACT_START));
$t = turn('Some Modded NPC', snap(['rank' => '2', 'moral' => '3', 'fac' => 'XYZ_NewTownFaction']));
check('unknown faction falls back gracefully', ($t['gate']['profile']['id'] ?? '') === 'fallback' && ($t['gate']['profile']['min_affinity'] ?? 0) === 46);

echo "7. consent can only come from the NPC's reply to player speech\n";
$t = turn('Hulda', snap(['rank' => '3']), 'instruction');
check('director/instruction turn: never offered', !$offered(LRG_ACT_START) && in_array('not_player_speech', $t['gate']['reasons'], true));
$t = turn('Hulda', snap(['rank' => '3']));
check('another actor cannot issue it', real(lrgPostProcessActions(["Mikael|command|" . LRG_ACT_START . "@Player"])) === []);
check('foreign actions pass through untouched', lrgPostProcessActions(["Hulda|command|Follow@Player"]) === ["Hulda|command|Follow@Player"]);

echo "8. stale snapshot\n";
lrgStoreNpcState('Hulda', snap(['rank' => '3']));
$GLOBALS['db']->t['lrg_npc_state']['Hulda']['updated_at'] = time() - 600;
$GLOBALS['ENABLED_FUNCTIONS'] = ['Talk', LRG_ACT_START, LRG_ACT_CONTROL]; lrgPrepareTurn();
check('old facts are not trusted', !$offered(LRG_ACT_START) && $GLOBALS['LRG_TURN']['gate']['reasons'] === ['no_fresh_snapshot']);

/**
 * [0.5.2 / pt10] One turn whose stored snapshot is $age seconds old - i.e. the game has gone quiet,
 * which is what LRG_Profile.BuildSnapshot() aborting on every call looked like from the server.
 * $state = null: no row for this NPC at all (an ordinary CHIM conversation the glue never saw).
 */
function blindTurn(string $npc, ?array $state, string $text, int $age = 600): array {
    if ($state !== null) {
        lrgStoreNpcState($npc, $state);
        $GLOBALS['db']->t['lrg_npc_state'][$npc]['updated_at'] = time() - $age;
    }
    unset($GLOBALS['LRG_NPCSTATE_MEMO']);
    $GLOBALS['HERIKA_NAME'] = $npc;
    $GLOBALS['gameRequest'] = ['inputtext', lrgNow(), 100 + (++$GLOBALS['LRG_TEST_SEQ']), $text];
    $GLOBALS['ENABLED_FUNCTIONS'] = TEST_ENABLED;
    lrgPrepareTurn();
    return $GLOBALS['LRG_TURN'];
}

echo "8b. [0.5.2 / pt10] a blind turn is still UNDERSTOOD - and still offers nothing\n";
// Playtest 10: thirty minutes of mode silent, because no snapshot ever arrived. `silent` was not in the
// list that runs the recogniser, so "take your clothes off" was never parsed - intent=none in every turn
// line, no heat, and nothing in the log that said what the player had asked for.
lrgMemSet('Hulda', ['heat' => null, 'heat_at' => null]);
$t = blindTurn('Hulda', snap(['rank' => '3']), 'take your clothes off');
check('the turn is silent for exactly one reason: no fresh snapshot',
    $t['mode'] === 'silent' && $t['gate']['reasons'] === ['no_fresh_snapshot'], implode(',', $t['gate']['reasons']));
check('the request is RECOGNISED although the turn is silent',
    ($t['intent']['kind'] ?? '') === 'undress' && ($t['intent']['conf'] ?? '') === 'high', json_encode($t['intent']['kind'] ?? null) . '/' . json_encode($t['intent']['conf'] ?? null));
check('... and the turn line carries it, so the log says what the player asked for',
    str_contains(lrgTurnLogLine($t), 'intent=undress') && str_contains(lrgTurnLogLine($t), 'conf=high'), lrgTurnLogLine($t));
check('the conversation still warms up while the game is quiet (heat is a build-up counter, not a rail)',
    (int) ($t['heat'] ?? 0) === 1 && (int) (lrgMemGet('Hulda')['heat'] ?? 0) === 1, 'heat=' . (int) ($t['heat'] ?? 0));
// the half that must NOT move: nothing offered, nothing executable, no net, no wording permission
check('NOTHING is offered on a blind turn',
    $t['offered'] === [] && !$offered(LRG_ACT_START) && !$offered(LRG_ACT_CLOTHING) && !$offered(LRG_ACT_CONTROL) && !$offered(LRG_ACT_INVITE),
    implode(',', (array) $t['offered']));
check('no explicit wording is permitted and no boundaries block is injected', $t['x'] === null && lrgStaticGuidance($t) === '');
check('the safety net never fires on a blind turn', lrgSafetyNet($t, []) === null);
$blindWire = real(lrgPostProcessActions(["Hulda|command|" . LRG_ACT_START . "@Player", "Hulda|command|" . LRG_ACT_CLOTHING . "@undress",
    "Hulda|command|" . LRG_ACT_CONTROL . "@P1", "Hulda|command|" . LRG_ACT_INVITE . "@home", "Hulda|command|BeginIntimacy@Player"]));
check('every glue action the model could choose is still dropped', $blindWire === [], implode(' ', $blindWire));
// what is NEW: the model is told plainly why the glue is saying nothing, in words only
$notes = lrgVolatileGuidance($t);
check('the model is told there are no fresh facts, and to answer in words',
    str_contains($notes, 'no fresh facts') && str_contains($notes, 'in words'), fx_cut($notes));
// [0.5.7 / pt16 (orchestrator)] The dialogue lane's direct barter (PROTOCOL 10.15) rides CHIM's own trade action on
// exactly this blind turn, so the "nothing physical can be carried out" sentence must carve out showing wares -
// and ONLY when the dialogue turn says direct barter for THIS npc. Jala and Addvar, 2026-09-23 16:40.
$GLOBALS['LRG_DLG_TURN'] = ['npc' => 'Hulda', 'svc' => ['kind' => 'barter', 'direct' => 1]];
$notesTrade = lrgVolatileGuidance($t);
check('direct barter on a blind turn: the blind note carves out showing her wares',
    str_contains($notesTrade, "apart from showing Hulda's wares") && !str_contains($notesTrade, 'carried out on this turn. '), fx_cut($notesTrade));
$GLOBALS['LRG_DLG_TURN'] = ['npc' => 'Somebody Else', 'svc' => ['kind' => 'barter', 'direct' => 1]];
$notesOther = lrgVolatileGuidance($t);
check('... never for another npc\'s barter turn', !str_contains($notesOther, 'apart from showing') && str_contains($notesOther, 'carried out on this turn. '), fx_cut($notesOther));
$GLOBALS['LRG_DLG_TURN'] = ['npc' => 'Hulda', 'svc' => ['kind' => 'barter', 'direct' => 0]];
check('... nor when direct barter was refused this turn', !str_contains(lrgVolatileGuidance($t), 'apart from showing'));
unset($GLOBALS['LRG_DLG_TURN']);
check('... and the plain blind sentence is unchanged without a dialogue turn', str_contains(lrgVolatileGuidance($t), 'carried out on this turn. ') && !str_contains(lrgVolatileGuidance($t), 'apart from showing'));
// [0.5.5 / owner addendum 11 (4)] ... and the REQUEST is answered: one <player_request> that asks for one short
// line with a plain human reason, names no action, uses only the plain kind, and never mentions the game
check('... in one short block that names no action; the request gets ONE "not right now" line, never ignored',
    strlen($notes) <= 1000 && substr_count($notes, '<player_request>') === 1
    && str_contains($notes, 'The player just asked for clothes off') && str_contains($notes, 'one short line')
    && str_contains($notes, 'plain human reason') && str_contains($notes, 'never ignoring it')
    && str_contains($notes, 'never a word about the game or missing facts')
    && !preg_match('/\b(BeginIntimacy|ChangeClothing|SuggestPrivacy|ChangeIntimacy|RequestAct)\b/', $notes), fx_cut($notes, 900));
$tq = blindTurn('Hulda', snap(['rank' => '3']), 'Hello there.');
check('... and a blind turn with no request carries the note alone, no directive', !str_contains(lrgVolatileGuidance($tq), '<player_request>'),
    fx_cut(lrgVolatileGuidance($tq)));
// stale facts are not facts - but they are good enough to stay QUIET on, exactly as every rail fails closed
$t = blindTurn('Britte', snap(['adult' => '0']), 'take your clothes off');
check('a minor whose snapshot went stale stays totally silent: nothing recognised, nothing injected',
    $t['mode'] === 'silent' && ($t['intent']['kind'] ?? '') === 'none' && lrgVolatileGuidance($t) === '' && lrgStaticGuidance($t) === '',
    ($t['intent']['kind'] ?? '?') . ' ' . fx_cut(lrgVolatileGuidance($t)));
$t = blindTurn('Hulda', snap(['rank' => '3', 'on' => '0']), 'take your clothes off');
check('... and so does an NPC whose last snapshot said the owner\'s switch was off',
    ($t['intent']['kind'] ?? '') === 'none' && lrgVolatileGuidance($t) === '', ($t['intent']['kind'] ?? '?'));
// an NPC the game has NEVER reported on is not a degradation - she is an ordinary CHIM conversation
$t = blindTurn('Belethor', null, 'take your clothes off');
check('an NPC the game never sent a snapshot for gets NOT ONE CHARACTER of guidance',
    $t['mode'] === 'silent' && lrgVolatileGuidance($t) === '' && lrgStaticGuidance($t) === '' && $t['offered'] === [], fx_cut(lrgVolatileGuidance($t)));
lrgMemSet('Hulda', ['heat' => null, 'heat_at' => null]);

echo "9. live scene: awareness + control\n";
lrgStoreScene('Hulda', ['ev' => 'change', 'npc' => 'Hulda', 'scene' => 'OARE_StandingEmbraceKiss', 'speed' => '1', 'maxspeed' => '3', 'trans' => '0', 'next' => 'OARE_StandingKiss,OARE_OutstretchedArmsKiss,OARE_GropingButtKiss']);
$t = turn('Hulda', snap(['rank' => '3', 'ostim' => '1']));
check('control offered, start + movement hidden', $offered(LRG_ACT_CONTROL) && !$offered(LRG_ACT_START) && !$offered('Follow') && !$offered('MoveTo'));
check('options come from the index', count($t['options']) > 0, implode(' | ', array_map(fn($o) => $o['id'], $t['options'])));
$notes = lrgVolatileGuidance($t);
// [0.3 / G5] the P-key list is gone: the notes list the ACTS that are open now, by a stable act key
check('scene notes describe the real scene and list the acts open now', str_contains($notes, 'RIGHT NOW') && str_contains($notes, 'stop')
    && (!$t['acts'] || str_contains($notes, (string) array_key_first($t['acts']) . ' =')), substr($notes, 0, 200));
$w = lrgPostProcessActions(["Hulda|command|" . LRG_ACT_CONTROL . "@P1"]);
check('P1 -> goto', count($w) === 1 && str_contains($w[0], 'do=goto;scene=' . $t['options']['P1']['id']), $w[0] ?? '');
$w = lrgPostProcessActions(["Hulda|command|" . LRG_ACT_CONTROL . "@let's stop"]);
check('"let\'s stop" -> stop', count($w) === 1 && str_contains($w[0], 'do=stop'));
check('impossible request is dropped (NPC just answers)', real(lrgPostProcessActions(["Hulda|command|" . LRG_ACT_CONTROL . "@hanging from the chandelier"])) === []);
check('bystander cannot control the scene', (function () { turn('Mikael', snap()); return !in_array(LRG_ACT_CONTROL, $GLOBALS['ENABLED_FUNCTIONS'], true) && !in_array(LRG_ACT_START, $GLOBALS['ENABLED_FUNCTIONS'], true); })());

echo "9b. real wire format (CRLF, display-name fallback, JSON parameter), scenetalk notes\n";
$t = turn('Hulda', snap(['rank' => '3', 'ostim' => '1']));
$w = lrgPostProcessActions(["Hulda|command|" . LRG_ACT_CONTROL . "@P1\r\n"]);
check('CRLF line: parsed and CRLF kept', count($w) === 1 && str_contains($w[0], 'do=goto') && str_ends_with($w[0], "\r\n") && !str_contains(rtrim($w[0]), "\r"));
$w = lrgPostProcessActions(["Hulda|command|ChangeIntimacy@{\"target\":\"Player\",\"item\":\"slower\"}\r\n"]);
check('display name + JSON parameter still gated and rewritten', count($w) === 1 && str_contains($w[0], '|' . LRG_ACT_CONTROL . '@ok=1;') && str_contains($w[0], 'do=slower'), trim($w[0] ?? ''));
$t = turn('Hulda', snap(['rank' => '3', 'ostim' => '1']), 'lrg_scenetalk');
$notes = lrgVolatileGuidance($t);
check('scenetalk turn: scene described, action text omitted', str_contains($notes, 'RIGHT NOW') && !str_contains($notes, 'ChangeIntimacy'));

echo "9c. lost end message / feature off / unoffered display name\n";
$GLOBALS['db']->t['lrg_scene_state']['Hulda']['updated_at'] = time() - 60;
$t = turn('Hulda', snap(['rank' => '3', 'ostim' => '0']));
check('newer snapshot with ostim=0 closes the stale scene row', $t['scene'] === null && !$offered(LRG_ACT_CONTROL) && (int) $GLOBALS['db']->t['lrg_scene_state']['Hulda']['active'] === 0);
check('and the start action is available again', $offered(LRG_ACT_START), implode(',', $t['gate']['reasons']));
$t = turn('Hulda', snap(['rank' => '3', 'wit' => '2']));
check('unoffered BeginIntimacy (display name) is dropped', real(lrgPostProcessActions(["Hulda|command|BeginIntimacy@Player\r\n"])) === []);
$t = turn('Hulda', snap(['rank' => '3', 'on' => '0', 'wit' => '2']));
check('feature off in MCM: no romance text at all', !$offered(LRG_ACT_START) && lrgStaticGuidance($t) === '' && lrgVolatileGuidance($t) === '');

echo "11. progression: gentle -> foreplay -> sex, one step at a time, NPC-paced\n";
$sceneRow = fn() => json_decode($GLOBALS['db']->t['lrg_scene_state']['Hulda']['payload'], true);
$setRow = function (array $over) use ($sceneRow) { $GLOBALS['db']->t['lrg_scene_state']['Hulda']['payload'] = json_encode($over + $sceneRow()); };
$startScene = ['npc' => 'Hulda', 'cid' => 'sAAA', 'scene' => 'OARE_StandingEmbraceKiss', 'speed' => '1', 'maxspeed' => '3', 'trans' => '0', 'next' => 'OARE_StandingKiss,OARE_OutstretchedArmsKiss,OARE_GropingButtKiss'];
lrgStoreScene('Hulda', ['ev' => 'start'] + $startScene);
check('a new scene starts its own tier history at the start scene', $sceneRow()['_maxtier'] === LRG_TIERS['kissing'] && abs($sceneRow()['_tier_since'] - time()) < 3);
$t = turn('Hulda', snap(['rank' => '3', 'ostim' => '1']));
check('player speech: next step (foreplay) open at once, never two steps', $t['progress']['reached'] === 2 && $t['progress']['ceiling'] === 3, json_encode($t['progress']));
check('innkeeper (relaxed) -> eager pace, vocal manner', $t['progress']['pace'] === 'eager' && lrgTalkStyle($t['profile']) === 'vocal');
check('no option above the ceiling', !array_filter($t['options'], fn($o) => LRG_TIERS[$o['tier']] > 3));
$notes = lrgVolatileGuidance($t);
// [0.3 / 4.4.3] the ambiguous single ladder sentence became two unambiguous ones about what SHE starts
check('notes: the ladder limits what SHE starts, who leads', str_contains($notes, 'starts by') && str_contains($notes, 'not yet') && str_contains($notes, 'Who leads'), substr($notes, 0, 200));
check('notes: the ladder never holds back what the player asked for', str_contains($notes, 'anything the player asks for'), substr($notes, 0, 200));
$w = lrgPostProcessActions(["Hulda|command|" . LRG_ACT_CONTROL . "@missionary"]);
$wt = $w && preg_match('/scene=([^;\r\n]+)/', $w[0], $mm) ? LRG_TIERS[lrgScene($mm[1])['tier']] : -1;
check('"missionary" while still kissing: at most the foreplay version of it, never sex', $wt <= 3, $w[0] ?? 'dropped');
check('... and under a gentle ceiling it is "too soon"', isset(lrgResolveControl('missionary', $t['options'], ['ceiling' => 2] + $t['ctx'])['too_soon']));
check('"fuck me" while still kissing: dropped, the NPC answers in words', real(lrgPostProcessActions(["Hulda|command|" . LRG_ACT_CONTROL . "@fuck me"])) === []);
$t = turn('Hulda', snap(['rank' => '3', 'ostim' => '1']), 'lrg_scenetalk', '(Context location: The Bannered Mare) lead');
check('lead tick right after the start: NPC may not escalate yet', $t['lead'] && $t['progress']['ceiling'] === 2, json_encode($t['progress']));
$setRow(['_tier_since' => time() - 30]);
$t = turn('Hulda', snap(['rank' => '3', 'ostim' => '1']), 'lrg_scenetalk', '(Context location: The Bannered Mare) lead');
check('lead tick after her pace time: next step open', $t['progress']['ceiling'] === 3);
lrgStoreScene('Hulda', ['ev' => 'change', 'scene' => 'OARE_StandingKiss'] + $startScene);
check('a change inside the same tier keeps the history and the clock', $sceneRow()['_maxtier'] === 2 && $sceneRow()['_tier_since'] <= time() - 29);
check('pace / manner defaults: strict -> slow + quiet, override wins', lrgPace(['strictness' => 'very strict']) === 'slow' && lrgTalkStyle(['strictness' => 'strict']) === 'quiet'
    && lrgPace(['strictness' => 'strict', 'pace' => 'eager']) === 'eager' && lrgTalkStyle(['strictness' => 'strict', 'talk' => 'crude']) === 'crude' && lrgTalkStyle(['strictness' => 'moderate']) === 'normal');

echo "12. scene-lead tick (protocol D): only ChangeIntimacy / ChangeClothing\n";
check('lead: control + clothing offered, start / movement / everything else hidden', $offered(LRG_ACT_CONTROL) && $offered(LRG_ACT_CLOTHING)
    && !$offered(LRG_ACT_START) && !$offered('Follow') && !$offered('GiveGoldTo'), implode(',', $GLOBALS['ENABLED_FUNCTIONS']));
$notes = lrgVolatileGuidance($t);
check('lead notes: ONE change, no circling back, the acts listed', str_contains($notes, 'ONE change') && str_contains($notes, 'ChangeIntimacy')
    && str_contains($notes, 'not back to something already done'), substr($notes, 0, 200));
$w = lrgPostProcessActions(["Hulda|command|" . LRG_ACT_CONTROL . "@P1\r\n"]);
check('lead: P1 passes the gate', count($w) === 1 && str_contains($w[0], 'do=goto'));
$t = turn('Hulda', snap(['rank' => '3', 'ostim' => '1']), 'lrg_scenetalk', '(Context location: The Bannered Mare) climax near');
check('ordinary scenetalk: no actions offered, and a hallucinated one is dropped', !$offered(LRG_ACT_CONTROL) && !$offered(LRG_ACT_CLOTHING)
    && real(lrgPostProcessActions(["Hulda|command|" . LRG_ACT_CONTROL . "@P1"])) === [] && real(lrgPostProcessActions(["Hulda|command|" . LRG_ACT_CLOTHING . "@undress"])) === []);
$pre = __DIR__ . '/../server/lorerim_glue/prerequest.php';
turn('Hulda', snap(['rank' => '3', 'ostim' => '1']), 'lrg_scenetalk', '(Context location: X) lead'); $GLOBALS['FUNCTIONS_ARE_ENABLED'] = false; include $pre;
check('prerequest hook switches actions on for the lead tick', $GLOBALS['FUNCTIONS_ARE_ENABLED'] === true && $offered(LRG_ACT_CONTROL) && !$offered('Follow'));
turn('Hulda', snap(['rank' => '3', 'ostim' => '1']), 'lrg_scenetalk', '(Context location: X) climax near'); $GLOBALS['FUNCTIONS_ARE_ENABLED'] = false; include $pre;
check('... not for ordinary scenetalk', $GLOBALS['FUNCTIONS_ARE_ENABLED'] === false);
turn('Mikael', snap(), 'lrg_scenetalk', 'lead'); $GLOBALS['FUNCTIONS_ARE_ENABLED'] = false; include $pre;
check('... and not for an NPC who is not in the scene', $GLOBALS['FUNCTIONS_ARE_ENABLED'] === false && !$offered(LRG_ACT_CONTROL));

echo "13. positions by name (protocol B)\n";
$setRow(['_maxtier' => 3, '_tier_since' => time()]);
$t = turn('Hulda', snap(['rank' => '3', 'ostim' => '1', 'psex' => '0', 'sex' => '1']));
check('after foreplay the ceiling is sex', $t['progress']['ceiling'] === 4);
$w = lrgPostProcessActions(["Hulda|command|" . LRG_ACT_CONTROL . "@missionary\r\n"]);
check('"missionary" -> goto a missionary scene (warp=1 only without a route)', count($w) === 1 && (bool) preg_match('/do=goto;scene=[^;]*missionary/i', $w[0]), trim($w[0] ?? ''));
$w = lrgPostProcessActions(["Hulda|command|ChangeIntimacy@{\"item\":\"let's do it from behind\"}"]);
check('"from behind" sentence -> goto', count($w) === 1 && str_contains($w[0], 'do=goto;scene='), trim($w[0] ?? ''));
$r = lrgResolveControl('kiss', $t['options'], $t['ctx']);
check('R1: naming the position they are already in changes nothing', $r === null, json_encode($r));
$r = lrgResolveControl('grope', $t['options'], $t['ctx']);
check('a position on the live route resolves without a warp', is_array($r) && ($r['do'] ?? '') === 'goto' && !isset($r['warp']), json_encode($r));
check('nonsense is still dropped', real(lrgPostProcessActions(["Hulda|command|" . LRG_ACT_CONTROL . "@hanging from the chandelier"])) === []);
check('"hold me" is a position request, "hold" is the pace key', (lrgResolveControl('hold', [], null)['do'] ?? '') === 'hold' && lrgResolveControl('hold me', [], null) === null);
// [0.3 / G5] the example position words are gone from the notes - an act key is the stable handle now,
// and the plain-words search stays as the escape hatch behind RequestAct (tested through the gate above)
check('notes offer the acts by key instead of example position words', !str_contains(lrgVolatileGuidance($t), 'in plain words (for example:')
    && (!$t['acts'] || str_contains(lrgVolatileGuidance($t), 'RequestAct takes exactly one act key')), substr(lrgVolatileGuidance($t), 0, 160));

echo "14. clothing (protocol A)\n";
$w = lrgPostProcessActions(["Hulda|command|" . LRG_ACT_CLOTHING . "@undress\r\n"]);
check('in scene: undress -> npc echoed, do=undress;who=npc;part=all', count($w) === 1 && (bool) preg_match('/\|' . LRG_ACT_CLOTHING . '@ok=1;cid=s\w+;npc=Hulda;do=undress;who=npc;part=all\r\n$/', undecorate($w[0])), trim($w[0] ?? ''));
$w = lrgPostProcessActions(["Hulda|command|ChangeClothing@{\"item\":\"Undress both\"}"]);
check('display name + JSON: undress both', count($w) === 1 && str_contains($w[0], 'do=undress;who=both'));
$w = lrgPostProcessActions(["Hulda|command|" . LRG_ACT_CLOTHING . "@dress"]);
check('dress', count($w) === 1 && str_contains($w[0], 'do=dress;who=npc'));
check('unknown clothing text is dropped', real(lrgPostProcessActions(["Hulda|command|" . LRG_ACT_CLOTHING . "@armor"])) === []);
lrgStoreScene('Hulda', ['ev' => 'end'] + $startScene);
$t = turn('Hulda', snap(['rank' => '3']));
check('outside a scene, gates pass: offered, and the NPC is told it is her choice', $offered(LRG_ACT_CLOTHING) && str_contains(lrgVolatileGuidance($t), 'ChangeClothing'));
check('... and passes the post-gate', count(lrgPostProcessActions(["Hulda|command|" . LRG_ACT_CLOTHING . "@undress"])) === 1);
$t = turn('Hulda', snap(['rank' => '3', 'wit' => '1']));
check('outside a scene, a witness: hidden and dropped', !$offered(LRG_ACT_CLOTHING) && real(lrgPostProcessActions(["Hulda|command|" . LRG_ACT_CLOTHING . "@undress"])) === []);
$t = turn('Hulda', snap(['rank' => '3']), 'instruction');
check('not on a non-speech turn either', !$offered(LRG_ACT_CLOTHING));

echo "15. dialogue style: explicit only inside a scene, per context\n";
$t = turn('Hulda', snap(['rank' => '3']));
$txt = lrgStaticGuidance($t) . lrgVolatileGuidance($t);
check('private + willing adult: plain speech asked for AND the wording permission at the default level 2 (R5)', str_contains($txt, 'No metaphors') && str_contains($txt, 'crude words') && $t['x'] === 2);
$t = turn('Hulda', snap(['rank' => '3', 'x' => '0']));
check('snapshot x=0 wins over the config: suggestive only', $t['x'] === 0 && str_contains(lrgVolatileGuidance($t), 'never named') && !preg_match('/crude|explicit|vulgar/i', lrgStaticGuidance($t) . lrgVolatileGuidance($t)));
$t = turn('Hulda', snap());
check('not willing (closed): x is null and no wording permission anywhere', $t['mode'] === 'closed' && $t['x'] === null && !preg_match('/crude|explicit|vulgar|body parts/i', lrgStaticGuidance($t) . lrgVolatileGuidance($t)));
lrgStoreScene('Hulda', ['ev' => 'start'] + $startScene);
$t = turn('Hulda', snap(['rank' => '3', 'ostim' => '1']), 'lrg_scenetalk', 'climax near');
$notes = lrgVolatileGuidance($t);
check('in scene, default level 2: real speech, metaphors banned, crude words expected', str_contains($notes, 'BANNED: metaphors') && str_contains($notes, 'crude words') && str_contains($notes, 'talks freely'));
check('scene notes stay short on a speech-only turn', strlen($notes) < 1500, strlen($notes) . ' chars');
lrgStoreScene('Hulda', ['ev' => 'change', 'x' => '0', 'und' => '1'] + $startScene);
$notes = lrgVolatileGuidance(turn('Hulda', snap(['rank' => '3', 'ostim' => '1']), 'lrg_scenetalk', 'x'));
check('MCM level 0 wins: suggestive only; und=1 is mentioned', str_contains($notes, 'never named') && !str_contains($notes, 'crude words') && str_contains($notes, 'is undressed'));
$t = turn('Hulda', snap(['rank' => '3', 'ostim' => '1', 'adult' => '0']));
check('no adult confirmation in the snapshot: no scene notes, no scene actions (fail closed)', lrgVolatileGuidance($t) === '' && lrgStaticGuidance($t) === '' && !$offered(LRG_ACT_CONTROL) && !$offered(LRG_ACT_CLOTHING) && !$offered(LRG_ACT_START));
// the OTHER rails inside a running scene fail closed too, but keep the spoken way out alive
$blocked = function (array $over, int $ageAfter = 0) use ($offered) {
    $t = turn('Hulda', snap(['rank' => '3', 'ostim' => '1'] + $over));
    if ($ageAfter > 0) { // let the stored snapshot go stale, then play the turn again
        $GLOBALS['LRG_TEST_NOW'] = time() + $ageAfter;
        $GLOBALS['gameRequest'] = ['inputtext', lrgNow(), 100 + (++$GLOBALS['LRG_TEST_SEQ']), 'hi'];
        lrgPrepareTurn();
        $t = $GLOBALS['LRG_TURN'];
    }
    $v = lrgVolatileGuidance($t);
    return $t['scene_blocked'] === true && $t['x'] === null && $t['options'] === [] && $t['ctx'] === null
        && !str_contains($v, 'RIGHT NOW') && !str_contains($v, 'Wording') && str_contains($v, '"stop"')
        && $offered(LRG_ACT_CONTROL) && !$offered(LRG_ACT_CLOTHING)
        && count(lrgPostProcessActions(["Hulda|command|" . LRG_ACT_CONTROL . "@stop\r\n"])) === 1
        && real(lrgPostProcessActions(["Hulda|command|" . LRG_ACT_CONTROL . "@faster\r\n"])) === []
        && real(lrgPostProcessActions(["Hulda|command|" . LRG_ACT_CLOTHING . "@undress\r\n"])) === [];
};
check('a child walks in mid-scene: everything stops but the spoken "stop"', $blocked(['witkid' => '1']));
check('the MCM intimacy switch off mid-scene: same', $blocked(['on' => '0']));
check('a snapshot that went stale mid-scene: same', $blocked([], 200));
unset($GLOBALS['LRG_TEST_NOW']);
lrgStoreScene('Hulda', ['ev' => 'end'] + $startScene);

echo "16. snapshot age 90 s, lrg_log\n";
lrgStoreNpcState('Hulda', snap(['rank' => '3']));
$GLOBALS['db']->t['lrg_npc_state']['Hulda']['updated_at'] = time() - 80;
$GLOBALS['HERIKA_NAME'] = 'Hulda'; $GLOBALS['gameRequest'] = ['inputtext', time(), 100, 'hi']; $GLOBALS['ENABLED_FUNCTIONS'] = ['Talk', LRG_ACT_START]; lrgPrepareTurn();
check('an 80 s old snapshot is still trusted', $offered(LRG_ACT_START), implode(',', $GLOBALS['LRG_TURN']['gate']['reasons']));
function terminate() { throw new RuntimeException('terminated'); }
$logFile = dirname(LRG_DIR, 2) . '/log/lorerim_glue.log'; @mkdir(dirname($logFile), 0770, true);
$gameRequest = ['lrg_log', time(), 100, '(Context location: Inn) cid=s12ab;msg=CmdStart: actors ok; undress=1'];
try { include __DIR__ . '/../server/lorerim_glue/preprocessing.php'; $ended = false; } catch (RuntimeException $e) { $ended = true; }
check('lrg_log is written as "GAME ..." with its cid and terminates', $ended && str_contains((string) @file_get_contents($logFile), '[cid=s12ab] GAME CmdStart: actors ok; undress=1'));
$g = []; (function () use (&$g) { include __DIR__ . '/../server/lorerim_glue/globals.php'; $g = $GLOBALS['external_fast_commands']; })();
check('lrg_log is a fast command', in_array('lrg_log', $g, true) && in_array('lrg_scene', $g, true));

// =================================================================== v0.2 (PROTOCOL.md v2)
// [0.3.1] lrg_romance is per-NPC memory too now: a completed scene is worth leverage.history_bonus on
// the interest score (R1c), so a section that wants a clean slate has to clear the counters as well.
$resetMem = function () { $GLOBALS['db']->t['lrg_memory'] = []; $GLOBALS['db']->t['lrg_romance'] = []; unset($GLOBALS['LRG_TEST_NOW'], $GLOBALS['LRG_TEST_ROLL'], $GLOBALS['LRG_INITIATIVE']); RelationshipManager::$aff = []; };
$glueOffered = fn() => array_values(array_intersect(LRG_GLUE_ACTIONS, $GLOBALS['ENABLED_FUNCTIONS']));
$guidance = fn(array $t) => lrgStaticGuidance($t) . lrgVolatileGuidance($t);

echo "17. interest (R2): one formula, one WORD for the LLM\n";
$resetMem();
$P = lrgBuildProfile('Hulda', snap()); // innkeeper: min_affinity 15, renown_sway moderate
$i = lrgInterest('Hulda', snap(), $P);
check('stranger: 0 - 15 = -15 -> curious, not willing', $i['score'] === -15 && $i['word'] === 'curious' && !$i['willing'] && !$i['may_initiate'], json_encode($i));
check('disliked (rank -2): indifferent', lrgInterest('Hulda', snap(['rank' => '-2']), $P)['word'] === 'indifferent');
$i = lrgInterest('Hulda', snap(['rank' => '3']), $P);
check('friend: interested + willing, but she does not start things herself', $i['score'] === 3 && $i['word'] === 'interested' && $i['willing'] && !$i['may_initiate']);
check('score 19 is still interested, Speech 60 (+2) makes it drawn', lrgInterest('Hulda', snap(['rank' => '4', 'courting' => '1']), $P)['word'] === 'interested'
    && lrgInterest('Hulda', snap(['rank' => '4', 'courting' => '1', 'pspeech' => '60']), $P)['may_initiate'] === true);
check('renown x sway: many deeds +5, Dragonborn +10 on a moderate profile', lrgInterest('Hulda', snap(['pquests' => '30']), $P)['score'] === -10 && lrgInterest('Hulda', snap(['pdb' => '1']), $P)['score'] === -5);
$bard = lrgBuildProfile('Mikael', snap(['fac' => 'JobBardFaction'])); // min 10, renown_sway strong
$i = lrgInterest('Mikael', snap(['fac' => 'JobBardFaction', 'pdb' => '1', 'pspeech' => '100']), $bard);
check('renown + Speech are capped at max_bonus 15 (strong sway, Dragonborn, Speech 100)', $i['bonus'] === 15 && $i['score'] === 5 && $i['willing']);
check('weak sway: the Dragonborn is worth +4 only', lrgInterest('Serana', snap(['pdb' => '1']), lrgBuildProfile('Serana', snap()))['bonus'] === 4);
RelationshipManager::$aff = ['Hulda' => 15];
check('no renown, no Speech: willing exactly when affinity >= min_affinity (the v1 comparison)', lrgInterest('Hulda', snap(), $P)['willing'] === true
    && (function () use ($P) { RelationshipManager::$aff = ['Hulda' => 14]; return lrgInterest('Hulda', snap(), $P)['willing'] === false; })());
// [0.3.1 / R1c] The glue now keeps its own copy of CHIM's affinity (lrg_romance.last_affinity) and uses
// it when CHIM's entry is missing or has dropped to 0, because CHIM's own restoreNPC deletes the entry
// on almost every save reload. The two checks above deliberately moved that number, so the memory has
// to go with it - otherwise the rescue (correctly) keeps the NPC at the value it last saw.
$resetMem();
$t = turn('Hulda', snap(['rank' => '2']));
check('gate: rank 2 alone is not close enough ...', $t['mode'] === 'closed' && in_array('not_close_enough', $t['gate']['reasons'], true));
$t = turn('Hulda', snap(['rank' => '2', 'pdb' => '1']));
check('... but to this NPC the Dragonborn is: interest replaces the raw comparison, the hard gates stay', $t['mode'] === 'private' && $offered(LRG_ACT_START) && $t['interest']['word'] === 'interested');
check('the LLM sees the word, never the number', str_contains(lrgStaticGuidance($t), 'is attracted to the player') && !preg_match('/\b(score|affinity)\b|-?\d{1,3}\s*(points|%)/i', lrgStaticGuidance($t)));
check('the word is remembered in lrg_memory', (lrgMemGet('Hulda')['interest'] ?? '') === 'interested' && lrgMemGet('Hulda')['interest_score'] === $t['interest']['score']);
check('a willing NPC is no longer pushed toward restraint; consent wording stays', !str_contains(lrgStaticGuidance($t), 'Do NOT agree') && str_contains(lrgStaticGuidance($t), 'may well make the first move') && str_contains(lrgStaticGuidance($t), 'never the result of pressure'));
$t = turn('Sigrid', snap(['rank' => '4', 'courting' => '1', 'pdb' => '1', 'married' => '1', 'fac' => 'JobFarmerFaction']));
check('married-refuse stays a hard block however drawn she is', $t['mode'] === 'closed' && $t['x'] === null && $glueOffered() === [] && in_array('married', $t['gate']['reasons'], true));

echo "18. turn modes (6.2): silent | closed | public | private | follow\n";
$resetMem();
$t = turn('Hulda', snap());
check('stranger: closed, nothing offered, hallucinated BeginIntimacy / SuggestPrivacy dropped', $t['mode'] === 'closed' && $glueOffered() === []
    && real(lrgPostProcessActions(["Hulda|command|" . LRG_ACT_START . "@Player\r\n"])) === [] && real(lrgPostProcessActions(["Hulda|command|" . LRG_ACT_INVITE . "@home\r\n"])) === [] && lrgMemGet('Hulda') === ['interest' => 'curious', 'interest_score' => -15]);
$pub = ['rank' => '3', 'wit' => '2', 'loc' => 'Whiterun', 'ltype' => 'city', 'nhome' => 'Greywater Farm', 'cellown' => 'none', 'home' => '0'];
$t = turn('Hulda', snap($pub));
check('willing with witnesses: public, ONLY SuggestPrivacy offered', $t['mode'] === 'public' && $glueOffered() === [LRG_ACT_INVITE] && $t['x'] === 2);
check('places come from facts: her home by name + somewhere quiet, no room outside an inn', array_keys($t['places']) === ['home', 'quiet'] && str_contains($t['places']['home'], 'Greywater Farm'));
$v = lrgVolatileGuidance($t);
check('public guidance: steer toward privacy, name the real place, nothing physical here', str_contains($v, 'SuggestPrivacy') && str_contains($v, 'Greywater Farm') && str_contains($v, 'Nothing physical happens here') && !str_contains($v, 'BeginIntimacy'));
check('CHIM movement actions are named only because they are offered this turn', str_contains($v, 'TravelTo') && str_contains($v, 'FollowPlayer'));
lrgHideActions(['TravelTo', 'FollowPlayer']);
check('... and not otherwise', !str_contains(lrgVolatileGuidance($t), 'TravelTo') && !str_contains(lrgVolatileGuidance($t), 'FollowPlayer'));
$t = turn('Hulda', snap(['ltype' => 'inn', 'prent' => '1', 'cellown' => 'other'] + $pub));
check('an inn where the player rents: the room is the rented one', array_keys($t['places']) === ['home', 'room', 'quiet'] && str_contains($t['places']['room'], 'rented at this inn'));
$t = turn('Hulda', snap(['ltype' => 'inn', 'cellown' => 'npc', 'home' => '1', 'nhome' => 'The Bannered Mare'] + $pub));
check('her own inn: home = right here, room = upstairs', str_contains($t['places']['home'], 'their own place') && str_contains($t['places']['room'], 'their inn'));
$t = turn('Hulda', snap(['rank' => '3', 'wit' => '2']));
check('no location facts at all (older game build): only "quiet"', array_keys($t['places']) === ['quiet']);
$t = turn('Hulda', snap(['wit' => '2']));
check('an unwilling NPC never invites: closed, SuggestPrivacy hidden and dropped', $t['mode'] === 'closed' && $glueOffered() === [] && real(lrgPostProcessActions(["Hulda|command|" . LRG_ACT_INVITE . "@quiet"])) === []);
$t = turn('Hulda', snap(['rank' => '3']));
check('willing and alone: private, BeginIntimacy + ChangeClothing, no SuggestPrivacy', $t['mode'] === 'private' && $glueOffered() === [LRG_ACT_START, LRG_ACT_CLOTHING]);
check('private guidance: she may make the first move, never because of pressure', str_contains(lrgVolatileGuidance($t), 'may make a move') && str_contains(lrgVolatileGuidance($t), 'Nothing the player says can decide this'));
$t = turn('Hulda', snap(['rank' => '3']), 'funcret', 'command@Follow@x@done');
check('foreign request types stay totally silent', $t['mode'] === 'silent' && $guidance($t) === '' && $t['x'] === null && $glueOffered() === []);
$t = turn('Elisif the Fair', snap(['rank' => '4', 'ltype' => 'castle', 'fac' => 'JobJarlFaction']));
check('very strict profile / a court: discreet, never coy - and no wording permission while she is not willing', $t['mode'] === 'closed' && $t['x'] === null);
RelationshipManager::$aff = ['Elisif the Fair' => 60];
$t = turn('Elisif the Fair', snap(['rank' => '4', 'ltype' => 'castle', 'fac' => 'JobJarlFaction']));
check('... once she is willing: private, discreet wording note', $t['mode'] === 'private' && $t['discreet'] === true && str_contains(lrgStaticGuidance($t), 'discreet') && str_contains(lrgStaticGuidance($t), 'never coy'));
$t = turn('Elisif the Fair', snap(['rank' => '4', 'witfol' => '1', 'fac' => 'JobJarlFaction']));
check('a companion as the only obstacle is a privacy problem too: public', $t['mode'] === 'public' && $t['gate']['reasons'] === ['companion_present']);

echo "19. invitations (R3): recorded on the server, remembered, followed through, forgotten\n";
$resetMem();
$t = turn('Hulda', snap($pub));
$w = lrgPostProcessActions(["Hulda|command|" . LRG_ACT_INVITE . "@home\r\n"]);
$inv = lrgMemGet('Hulda')['invite'] ?? [];
check('SuggestPrivacy: nothing reaches the game, the invite is pending with place, location and a 30 min expiry', $w === [] && ($inv['state'] ?? '') === 'pending' && $inv['place'] === 'home' && $inv['loc'] === 'Whiterun' && $inv['expires'] - $inv['at'] === 1800, json_encode($inv));
$w = lrgPostProcessActions(["Hulda|command|Suggest_Privacy@{\"item\":\"a room upstairs\"}\r\n"]);
check('CHIM stores the display name snake-cased: still gated; a place without facts falls back to "quiet"', $w === [] && lrgMemGet('Hulda')['invite']['place'] === 'quiet');
lrgPostProcessActions(["Hulda|command|" . LRG_ACT_INVITE . "@home\r\n"]);
$t = turn('Hulda', snap($pub));
check('still in public with a standing invitation: she does not nag', $t['mode'] === 'public' && ($t['invite']['state'] ?? '') === 'pending' && str_contains(lrgVolatileGuidance($t), 'does not repeat the invitation'));
$alone = ['wit' => '0', 'loc' => 'Greywater Farm', 'ltype' => 'house', 'cellown' => 'npc', 'home' => '1'] + $pub;
$t = turn('Hulda', snap($alone));
check('alone now: mode follow, BeginIntimacy + ChangeClothing offered', $t['mode'] === 'follow' && $glueOffered() === [LRG_ACT_START, LRG_ACT_CLOTHING]);
$v = lrgVolatileGuidance($t);
check('follow-through guidance: she brought the player here and makes her move now', str_contains($v, 'follows through NOW') && str_contains($v, 'BeginIntimacy') && str_contains($v, 'ChangeClothing') && str_contains($v, 'blunt proposition') && str_contains($v, '120'));
$w = lrgPostProcessActions(["Hulda|command|" . LRG_ACT_START . "@Player\r\n"]);
$sc = $w && preg_match('/;scene=([^;]*);undress=[01];furn=([^;]*);fscene=([^;]*);maxwit=0;folok=1/', $w[0], $mm) ? lrgScene($mm[1]) : null;
check('start param has the v2 shape and a GENTLE start scene', count($w) === 1 && str_contains($w[0], ';npc=Hulda;scene=') && $sc !== null && in_array($sc['tier'], ['kissing', 'affection'], true), trim($w[0] ?? ''));
check('the invitation is now "followed"', lrgMemGet('Hulda')['invite']['state'] === 'followed');
$t = turn('Hulda', snap($alone));
check('... so the next private turn is an ordinary one', $t['mode'] === 'private');
lrgPostProcessActions(["Hulda|command|" . LRG_ACT_START . "@Player\r\n"]); $resetMem();
turn('Hulda', snap($pub)); lrgPostProcessActions(["Hulda|command|" . LRG_ACT_INVITE . "@quiet\r\n"]);
$GLOBALS['LRG_TEST_NOW'] = time() + 1799; $t = turn('Hulda', snap($alone));
check('29 min 59 s later it still stands', $t['mode'] === 'follow');
$GLOBALS['LRG_TEST_NOW'] = time() + 1802; $t = turn('Hulda', snap($alone));
check('after ttl_seconds it is gone', $t['mode'] === 'private' && !isset(lrgMemGet('Hulda')['invite']));
unset($GLOBALS['LRG_TEST_NOW']);
turn('Hulda', snap($pub)); lrgPostProcessActions(["Hulda|command|" . LRG_ACT_INVITE . "@quiet\r\n"]);
$t = turn('Hulda', snap(['rank' => '0'] + $alone));
check('no longer willing: the invitation is dropped, mode closed', $t['mode'] === 'closed' && !isset(lrgMemGet('Hulda')['invite']));
turn('Hulda', snap($pub)); lrgPostProcessActions(["Hulda|command|" . LRG_ACT_INVITE . "@quiet\r\n"]);
lrgHandleGameMessage(['lrg_scene', time(), 100, 'ev=end;npc=Hulda;cid=sZZZ;scene=x;byglue=1']);
check('the end of a scene with her uses the invitation up', !isset(lrgMemGet('Hulda')['invite']));
$GLOBALS['db']->t['lrg_romance'] = [];

echo "20. NPC initiative (R2, 6.3): admitted or dropped BEFORE the lock\n";
$resetMem();
$GLOBALS['LRG_TEST_ROLL'] = ['initiative' => 1];
$drawn = ['rank' => '4', 'courting' => '1', 'pspeech' => '60'];
check('stranger: dropped', tick('Hulda', snap()) === 'handled');
check('interested but not drawn: dropped', tick('Hulda', snap(['rank' => '3'])) === 'handled');
check('drawn but not adult / child nearby / fighting: dropped', tick('Hulda', snap(['adult' => '0'] + $drawn)) === 'handled' && tick('Hulda', snap(['witkid' => '1'] + $drawn)) === 'handled' && tick('Hulda', snap(['combat' => '1'] + $drawn)) === 'handled');
check('nothing was remembered for any of those', !isset(lrgMemGet('Hulda')['initiative_at']));
check('a text other than "approach": dropped', tick('Hulda', snap($drawn), 'do something') === 'handled');
check('drawn + private + dice: admitted', tick('Hulda', snap($drawn)) === 'pass' && abs(lrgMemGet('Hulda')['initiative_at'] - time()) < 3);
$t = tickTurn();
check('her own move: actions on, ONLY BeginIntimacy + ChangeClothing', $GLOBALS['FUNCTIONS_ARE_ENABLED'] === true && $t['mode'] === 'private' && $t['initiative'] === true
    && $GLOBALS['ENABLED_FUNCTIONS'] === [LRG_ACT_START, LRG_ACT_CLOTHING], implode(',', $GLOBALS['ENABLED_FUNCTIONS']));
// [0.3 / G3a] "or lets the moment pass without a word (empty message)" is deleted: nothing she initiates
// is silent any more. What stays is that the move is hers to make - or not to make.
check('guidance: the move is hers to make, and she says a line first', str_contains(lrgVolatileGuidance($t), 'makes a move of their own')
    && !str_contains(lrgVolatileGuidance($t), 'lets the moment pass')
    && str_contains(lrgVolatileGuidance($t), 'says one short line first'), substr(lrgVolatileGuidance($t), 0, 200));
check('... and BeginIntimacy passes the gate on this non-speech turn', count(lrgPostProcessActions(["Hulda|command|" . LRG_ACT_START . "@Player\r\n"])) === 1);
lrgPrerequest();
check('prerequest after lrgPrepareTurn gives the same result (offline order)', $GLOBALS['LRG_TURN']['cid'] === $t['cid'] && $GLOBALS['ENABLED_FUNCTIONS'] === [LRG_ACT_START, LRG_ACT_CLOTHING]);
check('cooling down (300 s): dropped', tick('Hulda', snap($drawn)) === 'handled');
$GLOBALS['LRG_TEST_NOW'] = time() + 301; $GLOBALS['LRG_TEST_ROLL'] = ['initiative' => 51];
check('after the cooldown the dice decide: 51 > 50 (eager) dropped', tick('Hulda', snap($drawn)) === 'handled');
$GLOBALS['LRG_TEST_ROLL'] = ['initiative' => 50];
check('... 50 <= 50 admitted', tick('Hulda', snap($drawn)) === 'pass');
$GLOBALS['LRG_TEST_NOW'] += 301;
check('drawn in public: admitted, and the only move is an invitation', tick('Hulda', snap($drawn + $pub)) === 'pass' && tickTurn()['mode'] === 'public' && $GLOBALS['ENABLED_FUNCTIONS'] === [LRG_ACT_INVITE]);
lrgPostProcessActions(["Hulda|command|" . LRG_ACT_INVITE . "@home\r\n"]);
$GLOBALS['LRG_TEST_NOW'] += 301;
check('she has invited already: no second invitation tick in public', tick('Hulda', snap($drawn + $pub)) === 'handled');
$GLOBALS['LRG_TEST_NOW'] += 31;
check('follow-through needs no dice and no "drawn": pending invite + alone', (function () use ($alone) { $GLOBALS['LRG_TEST_ROLL'] = ['initiative' => 100]; return tick('Hulda', snap($alone)) === 'pass'; })());
$t = tickTurn();
check('... mode follow on the tick, start + clothing offered', $t['mode'] === 'follow' && $t['initiative'] && $GLOBALS['ENABLED_FUNCTIONS'] === [LRG_ACT_START, LRG_ACT_CLOTHING]);
check('... at most every 30 s', tick('Hulda', snap($alone)) === 'handled');
turn('Mikael', snap(), 'lrg_initiative', 'approach'); $GLOBALS['FUNCTIONS_ARE_ENABLED'] = false; lrgPrerequest();
check('a tick that was never admitted switches nothing on and stays silent', $GLOBALS['FUNCTIONS_ARE_ENABLED'] === false && $GLOBALS['LRG_TURN']['mode'] === 'silent' && $glueOffered() === []);
$ip = __DIR__ . '/../server/lorerim_glue/prompts.php';
unset($GLOBALS['FORCE_MAX_TOKENS'], $GLOBALS['PROMPTS']); $GLOBALS['gameRequest'] = ['lrg_initiative', time(), 100, 'approach'];
$GLOBALS['HERIKA_NAME'] = 'Hulda'; $GLOBALS['LRG_INITIATIVE'] = ['npc' => 'Hulda', 'kind' => 'own']; include $ip;
check('prompts.php: a cue for lrg_initiative asking for one short sentence, token cap set', isset($GLOBALS['PROMPTS']['lrg_initiative']['cue'][0]) && str_contains($GLOBALS['PROMPTS']['lrg_initiative']['cue'][0], '120') && $GLOBALS['FORCE_MAX_TOKENS'] === 200);
unset($GLOBALS['FORCE_MAX_TOKENS'], $GLOBALS['PROMPTS'], $GLOBALS['LRG_INITIATIVE']);
$GLOBALS['gameRequest'] = ['lrg_initiative', time(), 100, 'approach']; include $ip;
check('prompts.php: a tick that was never admitted gets NO cue and no token cap', !isset($GLOBALS['PROMPTS']['lrg_initiative']) && !isset($GLOBALS['FORCE_MAX_TOKENS']));
unset($GLOBALS['FORCE_MAX_TOKENS']);

echo "21. the verbal verb set (R1 / R8, PROTOCOL 3 + 6.4)\n";
$resetMem();
$in = snap(['rank' => '3', 'ostim' => '1', 'psex' => '0', 'sex' => '1']);
lrgStoreScene('Hulda', ['ev' => 'start'] + $startScene);
$t = turn('Hulda', $in);
check('gentle scene: climax / pull out are neither listed nor accepted', !str_contains(lrgVolatileGuidance($t), 'climax') && real(lrgPostProcessActions(["Hulda|command|" . LRG_ACT_CONTROL . "@climax"])) === []);
check('"auto" is refused before the ladder reached sex', real(lrgPostProcessActions(["Hulda|command|" . LRG_ACT_CONTROL . "@auto"])) === [] && !str_contains(lrgVolatileGuidance($t), 'auto ('));
$sexScene = lrgFindSceneByText('missionary', $startScene['scene'], ['0', '1'], '', 4)['id'] ?? '';
lrgStoreScene('Hulda', ['ev' => 'change', 'scene' => $sexScene, 'nearf' => 'bed,chair', 'leader' => 'npc', 'auto' => '0', 'wd' => '0'] + $startScene); $setRow(['_maxtier' => 4]);
$t = turn('Hulda', $in);
$ctl = fn(string $item) => undecorate(trim(real(lrgPostProcessActions(["Hulda|command|" . LRG_ACT_CONTROL . "@$item\r\n"]))[0] ?? 'DROPPED'));
check('set-up: a sexual scene is running', $sexScene !== '' && $t['ctx']['tier'] === 4 && $t['ctx']['auto_ok'] === true, $sexScene);
$v = lrgVolatileGuidance($t);
check('the notes list the verbs compactly, and never the impossible "pull out"', str_contains($v, 'climax') && !str_contains($v, 'pull out') && str_contains($v, 'wind down') && str_contains($v, 'stop (ends at once)') && str_contains($v, 'Hulda leads') && str_contains($v, 'leads') && str_contains($v, 'auto ('));
check('climax -> who npc / both / player', str_ends_with($ctl('climax'), ';npc=Hulda;do=climax;who=npc') && str_ends_with($ctl('climax together'), 'do=climax;who=both') && str_ends_with($ctl('make you climax'), 'do=climax;who=player'));
check('pull out is still MAPPED, for a pack that defines the transition', str_ends_with($ctl('pull out'), ';do=pullout'));
check('wind down -> a SEPARATE verb with scene, warp and linger', (bool) preg_match('/;do=winddown;scene=[^;]*;warp=[01];linger=20$/', $ctl('wind down')), $ctl('wind down'));
check('stop wins every tie, with any words around it', str_ends_with($ctl('stop'), ';do=stop') && str_ends_with($ctl('wind down and then stop'), ';do=stop') && str_ends_with($ctl("faster - no, let's stop now"), ';do=stop') && str_ends_with($ctl('that is enough'), ';do=stop'));
check('speed 2 -> do=speed;speed=2, faster / slower unchanged', str_ends_with($ctl('speed 2'), ';do=speed;speed=2') && str_ends_with($ctl('faster'), ';do=faster') && str_ends_with($ctl('slower'), ';do=slower'));
// [0.3] her own "<name> leads" command looks exactly like the hold carrier on the wire, so the positives
// are read from the undecorated line itself and the two drops from the resolver
$ctlRaw = fn(string $item) => undecorate(trim(lrgPostProcessActions(["Hulda|command|" . LRG_ACT_CONTROL . "@$item\r\n"])[0] ?? 'DROPPED'));
check('lead: only the unambiguous NAME keys resolve; a bare "you lead" / "i lead" is dropped', str_ends_with($ctlRaw('Hulda leads'), 'do=lead;who=npc')
    && str_ends_with($ctlRaw('player leads'), 'who=player') && str_ends_with($ctlRaw('auto'), 'who=auto')
    && lrgResolveControl("i'll lead", [], $t['ctx']) === null && lrgResolveControl('you lead', [], $t['ctx']) === null, $ctlRaw('Hulda leads'));
$fo = ['bed' => ['furn' => 'bed', 'label' => 'bed', 'words' => ['bed', 'mattress'], 'scene' => 'SomeBedScene', 'tier' => 'kissing']];
check('furniture: a label from the furniture options -> do=furniture with a scene id, always', lrgResolveControl('move to the bed', [], ['furn_options' => $fo] + $t['ctx']) === ['do' => 'furniture', 'furn' => 'bed', 'scene' => 'SomeBedScene']
    && (lrgResolveControl('over to the table', [], ['furn_options' => $fo] + $t['ctx'])['do'] ?? '') !== 'furniture');
check('furniture without a scene id is never sent', (lrgResolveControl('bed', [], ['furn_options' => ['bed' => ['scene' => ''] + $fo['bed']]] + $t['ctx'])['do'] ?? '') !== 'furniture');
$cl = fn(string $item) => undecorate(trim(real(lrgPostProcessActions(["Hulda|command|" . LRG_ACT_CLOTHING . "@$item\r\n"]))[0] ?? 'DROPPED'));
check('clothing: who = npc / both / player, part = all / body / head / hands / feet', str_ends_with($cl('undress'), 'do=undress;who=npc;part=all') && str_ends_with($cl('undress both'), 'who=both;part=all')
    && str_ends_with($cl('undress you'), 'do=undress;who=player;part=all') && str_ends_with($cl('undress body'), 'who=npc;part=body') && str_ends_with($cl('dress you feet'), 'do=dress;who=player;part=feet')
    && str_ends_with($cl('undress hands'), 'part=hands') && str_ends_with($cl('undress head'), 'part=head'));
check('an echo of the player\'s own words still means the NPC', str_ends_with($cl('take your clothes off'), 'do=undress;who=npc;part=all'));
lrgStoreScene('Hulda', ['ev' => 'winddown', 'scene' => $sexScene, 'wd' => '1'] + $startScene); $setRow(['_maxtier' => 4, '_tier_since' => time() - 500]);
$t = turn('Hulda', $in, 'lrg_scenetalk', 'lead');
check('no lead tick while winding down (and the notes say so)', $t['lead'] === false && $t['can_act'] === false && str_contains(lrgVolatileGuidance($t), 'winding down'));
$t = turn('Hulda', $in);
check('... but "stop" still ends it at once mid wind-down', str_ends_with($ctl('stop'), ';do=stop'));
lrgStoreScene('Hulda', ['ev' => 'change', 'scene' => $sexScene, 'leader' => 'player'] + $startScene); $setRow(['_maxtier' => 4, '_tier_since' => time() - 500]);
$t = turn('Hulda', $in, 'lrg_scenetalk', 'lead'); $GLOBALS['FUNCTIONS_ARE_ENABLED'] = false; lrgPrerequest();
check('no lead tick while the player leads', $t['lead'] === false && $GLOBALS['FUNCTIONS_ARE_ENABLED'] === false);
check('... and the notes tell her the player directs', str_contains(lrgVolatileGuidance(turn('Hulda', $in)), 'the player directs'));
lrgHandleGameMessage(['lrg_scene', time(), 100, 'ev=climax;npc=Hulda;cid=sAAA;scene=' . $sexScene . ';who=Hulda']);
check('climaxes are counted per thread', $sceneRow()['_climaxes'] === 1 && str_contains(lrgVolatileGuidance(turn('Hulda', $in)), 'A climax has already happened'));

echo "22. R10 speak the choice: usually says it for foreplay / sex, never for a gentle pick\n";
lrgStoreScene('Hulda', ['ev' => 'start'] + $startScene); $setRow(['_maxtier' => 3, '_tier_since' => time() - 500]);
$GLOBALS['LRG_TEST_ROLL'] = ['announce' => 1];
$t = turn('Hulda', $in); $v = lrgVolatileGuidance($t);
$line = ''; foreach (explode("\n", $v) as $l) { if (str_starts_with($l, 'P1 = ')) { $line = $l; } }
// lrgIsSayIt() survives only as a TIER PREDICATE for these checks: the say-it / silent rule itself is gone
$tiers = array_map(fn($o) => lrgIsSayIt($o['tier']), $t['options']);
check('set-up: the options span gentle and foreplay / sex', in_array(true, $tiers, true) && in_array(false, $tiers, true), implode(' ', array_map(fn($o) => $o['tier'], $t['options'])));
// [0.3 / R10, owner addendum 2] the [silent] / [say it] markers are GONE: every change she initiates
// herself is announced, and the die only decides how blunt that line is
check('roll 1: blunt = true, and no option carries a [silent] / [say it] marker', $t['blunt'] === true && !str_contains($v, '[say it]') && !str_contains($v, '[silent]'), $line);
check('... the rule: one short line that makes plain what is about to happen, never silently', str_contains($v, 'makes plain what is about to happen') && str_contains($v, 'Never silently')
    && !str_contains($v, 'leave message empty'), substr($v, 0, 200));
check('... and what the player asked for is done AND answered in the same reply', str_contains($v, 'never answer instead of doing it'));
check('... and it is a rule, not a line: no quoted example speech in the notes', !preg_match('/(for example|e\.g\.|such as|like)\s*[:,]?\s*["“][^"”]{12,}["”]/i', $v));
$GLOBALS['LRG_TEST_ROLL'] = ['announce' => 100];
$t = turn('Hulda', $in); $v = lrgVolatileGuidance($t);
check('roll 100: blunt = false - but the line is STILL asked for', $t['blunt'] === false && str_contains($v, 'makes plain what is about to happen')
    && !str_contains($v, 'leave message empty'), substr($v, 0, 160));
$GLOBALS['LRG_TEST_ROLL'] = ['announce' => 85]; $a = turn('Hulda', $in)['blunt'];
$GLOBALS['LRG_TEST_ROLL'] = ['announce' => 86]; $b = turn('Hulda', $in)['blunt'];
check('"usually": vocal innkeeper 85 %', $a === true && $b === false);
$k = array_key_first(array_filter($t['options'], fn($o) => !lrgIsSayIt($o['tier'])));
check('a silent pick still produces its command', $k !== null && str_contains($ctl((string) $k), 'do=goto;scene=' . $t['options'][$k]['id']));
$GLOBALS['LRG_TEST_ROLL'] = ['announce' => 1];
$t = turn('Hulda', $in, 'lrg_scenetalk', 'a change of pace');
check('speech-only scene talk: no options, no marks, no dice', !str_contains(lrgVolatileGuidance($t), 'P1 =') && $t['blunt'] === false);

echo "23. catalog v11 (6.5) and speed (R7)\n";
$rows = $GLOBALS['LRG_TEST_ROWS'] ?? [];
check('six rows, version 11: the five offered ones + the inactive escort carrier', !array_diff(array_keys($rows), LRG_CATALOG_ROWS) && !array_diff(LRG_CATALOG_ROWS, array_keys($rows)) && LRG_ACTIONS_VERSION === 11
    && $rows[LRG_ACT_INVITE]['action_name'] === 'SuggestPrivacy' && ($rows[LRG_ACT_REQUESTACT]['action_name'] ?? '') === 'RequestAct', implode(',', array_keys($rows)));
// [0.5.5] the escort row exists ONLY so CHIM can voice the escort's refusals: it is never offered to anyone
check('[0.5.5] the escort row is switched off, offered to nobody, on a request type that never occurs',
    (int) ($rows[LRG_ACT_ESCORT]['is_activated'] ?? 1) === 0 && (int) ($rows[LRG_ACT_ESCORT]['available_to_npc'] ?? 1) === 0
    && (int) ($rows[LRG_ACT_ESCORT]['available_to_followers'] ?? 1) === 0
    && ($rows[LRG_ACT_ESCORT]['metadata']['requirements']['request_types_any'] ?? []) === ['lrg_never'], json_encode($rows[LRG_ACT_ESCORT] ?? null));
// [0.4] the one fact the whole paid-intimacy feature stands on: the LLM's AMOUNT has to reach our wire
// parameter, which it only does through metadata.parameter_template - and `amount` must stay OUT of
// "required", or queueFunctionExecutionCommand() drops every free start whose amount came back empty.
check('[0.4] BeginIntimacy declares amount, keeps it optional, and carries the parameter_template',
    isset($rows[LRG_ACT_START]['parameters_json']['properties']['amount'])
    && ($rows[LRG_ACT_START]['parameters_json']['required'] ?? ['x']) === []
    && ($rows[LRG_ACT_START]['metadata']['parameter_template'] ?? '') === '{{parameters.amount}}',
    json_encode([$rows[LRG_ACT_START]['parameters_json']['required'] ?? null, $rows[LRG_ACT_START]['metadata']['parameter_template'] ?? null]));
check('RequestAct is offered on the same turns as ChangeIntimacy, and takes one act key',
    ($rows[LRG_ACT_REQUESTACT]['metadata']['requirements']['request_types_any'] ?? []) === ($rows[LRG_ACT_CONTROL]['metadata']['requirements']['request_types_any'] ?? ['x'])
    && str_contains((string) ($rows[LRG_ACT_REQUESTACT]['description'] ?? ''), 'one act key'));
check('ChangeIntimacy is now the VERB action: no position vocabulary in its description',
    !preg_match('/\bposition\b|P1\.\.P8/i', (string) ($rows[LRG_ACT_CONTROL]['description'] ?? '')), (string) ($rows[LRG_ACT_CONTROL]['description'] ?? ''));
// [0.5.5 / owner addendum 11] CHIM decides the funcret turn per ROW, the glue per RESULT: the follow-up is ON with a
// prompt on every row, never lets her act again, and every result that must stay silent is ended pre-lock (section 24)
check('every row: follow-up ON with a prompt, no action chaining, no placeholder infoaction, automatic confirmation',
    !array_filter($rows, fn($r) => $r['metadata']['followup']['enabled'] !== true || trim((string) $r['metadata']['followup']['prompt']) === ''
        || $r['metadata']['followup']['use_functions_again'] !== false
        || empty($r['metadata']['suppress_placeholder_infoaction']) || $r['metadata']['confirmation']['default_policy'] !== 'automatic'));
check('... and the follow-up prompt is a rule, not a line: one short line, the real reason, never pretending',
    str_contains(LRG_FOLLOWUP_PROMPT, 'one short spoken line') && str_contains(LRG_FOLLOWUP_PROMPT, 'real reason') && str_contains(LRG_FOLLOWUP_PROMPT, 'never pretend'));
$types = fn(string $c) => $rows[$c]['metadata']['requirements']['request_types_any'];
check('request types per row', in_array('lrg_initiative', $types(LRG_ACT_START), true) && !in_array('lrg_scenetalk', $types(LRG_ACT_START), true) && in_array('lrg_scenetalk', $types(LRG_ACT_CONTROL), true) && !in_array('lrg_initiative', $types(LRG_ACT_CONTROL), true)
    && in_array('lrg_initiative', $types(LRG_ACT_CLOTHING), true) && in_array('lrg_scenetalk', $types(LRG_ACT_CLOTHING), true) && in_array('lrg_initiative', $types(LRG_ACT_INVITE), true));
// [0.3 / G3a] the "it can simply happen with an empty message" licence is gone; the line comes first
check('BeginIntimacy: a spoken line first, never an empty message, and never because of pressure',
    str_contains($rows[LRG_ACT_START]['description'], 'short line') && str_contains($rows[LRG_ACT_START]['description'], 'never an empty message')
    && str_contains($rows[LRG_ACT_START]['description'], 'pressure'), $rows[LRG_ACT_START]['description']);
check('ChangeClothing: what the player asks for is done; what nobody asked for is her own choice',
    str_contains($rows[LRG_ACT_CLOTHING]['description'], 'When the player asks') && str_contains($rows[LRG_ACT_CLOTHING]['description'], 'only because you yourself want to'));
check('no explicit vocabulary in any row description', !preg_match('/crude|explicit|vulgar|naked|sex\b/i', implode(' ', array_column($rows, 'description'))));
$t = turn('Hulda', $in, 'lrg_scenetalk', 'a change of pace'); $v = lrgVolatileGuidance($t);
check('scene talk: ONE short sentence of at most 120 characters, notes under 1500 chars', str_contains($v, 'ONE short sentence, at most 120 characters') && strlen($v) < 1500, strlen($v) . ' chars');
unset($GLOBALS['FORCE_MAX_TOKENS'], $GLOBALS['OPENAI_FILTER_DISABLED']); lrgApplyTurnRuntime($t);
check('... token cap 140 on a speech-only scene turn', $GLOBALS['FORCE_MAX_TOKENS'] === 140);
$t = turn('Hulda', $in); unset($GLOBALS['FORCE_MAX_TOKENS']); lrgApplyTurnRuntime($t);
check('... 200 when the turn may carry an action; notes with the whole verb set stay under 3200 chars', $GLOBALS['FORCE_MAX_TOKENS'] === 200 && strlen(lrgVolatileGuidance($t)) < 3200, strlen(lrgVolatileGuidance($t)) . ' chars');
check('CHIM\'s word-scoring refusal filter is bypassed on glue-guided turns only', ($GLOBALS['OPENAI_FILTER_DISABLED'] ?? false) === true);
lrgStoreScene('Hulda', ['ev' => 'end'] + $startScene);
$t = turn('Hulda', snap()); unset($GLOBALS['FORCE_MAX_TOKENS'], $GLOBALS['OPENAI_FILTER_DISABLED']); lrgApplyTurnRuntime($t);
check('an ordinary closed turn is not squeezed: no cap, no one-sentence rule', !isset($GLOBALS['FORCE_MAX_TOKENS']) && !preg_match('/\bone\b[^.\n]{0,40}\bsentence\b/i', $guidance($t)));
$t = turn('Braith', snap(['adult' => '0'])); unset($GLOBALS['OPENAI_FILTER_DISABLED']); lrgApplyTurnRuntime($t);
check('a silent turn changes nothing at all in CHIM\'s runtime', !isset($GLOBALS['FORCE_MAX_TOKENS']) && !isset($GLOBALS['OPENAI_FILTER_DISABLED']));

echo "24. [0.5.5] results: a success stays silent, a failure is VOICED in the same exchange\n";
$resetMem();
$t = turn('Hulda', snap(['rank' => '3']));
$w = lrgPostProcessActions(["Hulda|command|" . LRG_ACT_START . "@Player\r\n"]);
$param = explode('@', trim($w[0]), 2)[1];
$fr = "command@" . LRG_ACT_START . "@$param@Error: someone is watching";
$GLOBALS['gameRequest'] = ['funcret', time(), 100, $fr];
check('a failed start the player was waiting on is recorded and PASSED to CHIM\'s funcret turn', lrgHandleGameMessage($GLOBALS['gameRequest']) === 'pass'
    && lrgMemGet('Hulda')['last_result'] === ['cmd' => LRG_ACT_START, 'do' => '', 'ok' => false, 'reason' => 'someone is watching', 'say' => '', 'late' => false,
        'at' => lrgMemGet('Hulda')['last_result']['at'], 'told' => true]);
check('... it is marked VOICED for this request, with the plain fact it answers',
    (($GLOBALS['LRG_VOICED'] ?? [])['npc'] ?? '') === 'Hulda' && ($GLOBALS['LRG_VOICED']['src'] ?? '') === 'speech'
    && str_contains((string) ($GLOBALS['LRG_VOICED']['fact'] ?? ''), 'nothing began between Hulda and') && str_contains((string) ($GLOBALS['LRG_VOICED']['fact'] ?? ''), 'somebody is watching'),
    json_encode($GLOBALS['LRG_VOICED'] ?? null));
check('... and CHIM\'s tool call gets a plain token and the plain fact, never the k=v parameter',
    $GLOBALS['gameRequest'][3] === 'command@' . LRG_ACT_START . '@start@Error: ' . $GLOBALS['LRG_VOICED']['fact'], $GLOBALS['gameRequest'][3]);
// the funcret turn itself, as CHIM builds it: prompts.php cue, functions.php + context_pre.php turn
$GLOBALS['HERIKA_NAME'] = 'Hulda'; $GLOBALS['ENABLED_FUNCTIONS'] = TEST_ENABLED; unset($GLOBALS['LRG_TURN']);
lrgPrepareTurn(); $vt = $GLOBALS['LRG_TURN'];
check('the voiced turn is mode voiced: no glue action, nothing injected - the cue is the whole instruction',
    $vt['mode'] === 'voiced' && $glueOffered() === [] && lrgVolatileGuidance($vt) === '' && lrgStaticGuidance($vt) === '', $vt['mode']);
$cue = lrgVoicedCue('Hulda');
check('the cue: what happened, ONE short line in her own voice, the real reason, no pretending, never the game',
    ($cue['code'] ?? '') === LRG_ACT_START && str_contains($cue['cue'], 'What just happened: nothing began between Hulda and')
    && str_contains($cue['cue'], 'somebody is watching') && str_contains($cue['cue'], 'ONE short line') && str_contains($cue['cue'], 'does not pretend it happened')
    && str_contains($cue['cue'], 'never a word about the game, commands or errors'), $cue['cue'] ?? '');
check('... and it is only ever given to the NPC the result belongs to', lrgVoicedCue('Braith') === null);
unset($GLOBALS['FORCE_MAX_TOKENS'], $GLOBALS['OPENAI_FILTER_DISABLED']); lrgApplyTurnRuntime($vt);
check('the voiced turn is capped at one short line (140 tokens) and CHIM\'s canned-refusal filter is bypassed',
    ($GLOBALS['FORCE_MAX_TOKENS'] ?? 0) === 140 && ($GLOBALS['OPENAI_FILTER_DISABLED'] ?? false) === true);
check('the voice gate lets it through (same NPC, the rows carry the follow-up)', lrgVoicedGate() === '' && isset($GLOBALS['LRG_VOICED']));
// no warm-up here: a warm-up turn would consume the "told" state this check is about
$t = turn('Hulda', snap(['rank' => '3']), 'inputtext', 'hi', false);
check('her NEXT turn is not told again - it was said when it happened', !str_contains(lrgVolatileGuidance($t), 'someone is watching') && lrgMemGet('Hulda')['last_result']['told'] === true,
    fx_cut(lrgVolatileGuidance($t)));
$GLOBALS['gameRequest'] = ['funcret', time(), 100, "command@" . LRG_ACT_START . "@$param@They draw close. The scene begins."];
check('a SUCCESS ends before the lock: handled, nothing voiced, nothing told', lrgHandleGameMessage($GLOBALS['gameRequest']) === 'handled'
    && !isset($GLOBALS['LRG_VOICED']) && lrgMemGet('Hulda')['last_result']['ok'] === true && !str_contains(lrgVolatileGuidance(turn('Hulda', snap(['rank' => '3']))), 'did not happen'));
check('foreign funcrets are ignored and passed on untouched', lrgHandleGameMessage(['funcret', time(), 100, 'command@Follow@Player@ok']) === 'pass'
    && lrgMemGet('Hulda')['last_result']['cmd'] === LRG_ACT_START && !isset($GLOBALS['LRG_VOICED']));
check('Phase 2\'s SelectTopic result passes on untouched (its row keeps the follow-up off)',
    lrgHandleGameMessage(['funcret', time(), 100, 'command@ExtCmdLRG_SelectTopic@ok=1;cid=sx;npc=Hulda;x=1@Error: that moment has passed']) === 'pass' && !isset($GLOBALS['LRG_VOICED']));
// what stays QUIET although it failed: her own lead move, the hold carrier, a dry run, a second failure in the gap
$resetMem();
lrgMemSet('Hulda', ['sent' => [['cid' => 'sLEAD1', 'code' => 'Control', 'do' => 'goto', 'src' => 'lead', 'at' => time()]]]);
$quiet = lrgHandleGameMessage(['funcret', time(), 100, 'command@' . LRG_ACT_CONTROL . '@ok=1;cid=sLEAD1;npc=Hulda;do=goto;scene=X;nowarp=1;wait=begin@Error: that position cannot be reached from here']);
check('her own move on a scene-lead tick is NOT voiced: handled, and told on her next turn as before',
    $quiet === 'handled' && !isset($GLOBALS['LRG_VOICED']) && lrgMemGet('Hulda')['last_result']['told'] === false);
$quiet = lrgHandleGameMessage(['funcret', time(), 100, 'command@' . LRG_ACT_CONTROL . '@ok=1;cid=sCARRY;npc=Hulda;do=lead;who=npc;hold=150@Error: no scene is running']);
check('the hold carrier (nobody asked for it) is never voiced', $quiet === 'handled' && !isset($GLOBALS['LRG_VOICED']));
// [pt17 / owner addendum 11] A DRY RUN IS VOICED AND NAMES THE SWITCH. Every wording the game has ever sent for the
// developer switch (bDryRun:General) is covered: 508's three and 509's page-naming one.
$dryWordings = ['dry-run mode, nothing changed' => [LRG_ACT_CLOTHING, 'do=undress;who=npc;part=all'],
    'dry-run mode, nothing was started' => [LRG_ACT_START, 'scene=X'],
    'the glue is in dry-run mode - nothing was changed' => [LRG_ACT_ESCORT, 'do=release'],
    'dry-run mode is ON in the LoreRim Glue MCM (Diagnostics page) - nothing was changed' => [LRG_ACT_ESCORT, 'do=release']];
$n = 0;
foreach ($dryWordings as $wording => [$code, $kvs]) {
    $resetMem();
    $n++;
    $verdict = lrgHandleGameMessage(['funcret', time(), 100, 'command@' . $code . '@ok=1;cid=sDRY' . $n . ';npc=Hulda;' . $kvs . '@Error: ' . $wording]);
    $fact = (string) (($GLOBALS['LRG_VOICED'] ?? [])['fact'] ?? '');
    check("a dry run IS voiced and names the switch and the page: '$wording'",
        $verdict === 'pass' && isset($GLOBALS['LRG_VOICED']) && str_contains($fact, 'dry-run switch') && str_contains($fact, 'Diagnostics'), $fact);
}
$cue = lrgVoicedCue('Hulda');
check('... its cue lets her name the game\'s own settings for this one reason (never for any other)',
    str_contains((string) ($cue['cue'] ?? ''), 'say plainly that the LoreRim Glue dry-run switch is on')
    && !str_contains((string) ($cue['cue'] ?? ''), 'never a word about the game, commands or errors'), (string) ($cue['cue'] ?? ''));
// [pt19 v1.0 / S3, S7] four passive rows learnt from the next menu of any kind: the game sends no count, she says none, and no
// machinery word ("the glue is still learning ... (N of 10)" is gone)
$lrnW = lrgVoicedDryWhy('still learning the dialogue menu - the next conversation of any kind measures it');
check('the menuless wordings are NOT the developer switch: "still learning" -> the next conversation of any kind, in-world; the Menuless questing page',
    str_contains($lrnW, 'the next conversation of any kind') && !preg_match('/\d|glue|calibrat|Diagnostics/i', $lrnW)
    && str_contains(lrgVoicedDryWhy('the menuless dry run is on (Menuless questing page) - nothing was clicked'), 'Menuless questing page')
    && lrgVoicedDryWhy('someone is watching') === '' && lrgVoicedDryKind('dry-run mode, nothing changed') === 'dev');
// not voiced (inside the 8 s gap after another failure): told on her next turn WITH the switch, never as a bare "cannot"
$resetMem();
lrgHandleGameMessage(['funcret', time(), 100, 'command@' . LRG_ACT_CLOTHING . '@ok=1;cid=sGAP1;npc=Hulda;do=undress;who=npc;part=all@Error: someone is watching']);
$quiet = lrgHandleGameMessage(['funcret', time(), 100, 'command@' . LRG_ACT_ESCORT . '@ok=1;cid=sGAP2;npc=Hulda;do=release;err=the glue is in dry-run mode - nothing was changed@Error: Hulda cannot do that right now']);
check('set-up: a dry-run refusal inside the gap is not voiced now', $quiet === 'handled' && lrgMemGet('Hulda')['last_result']['told'] === false);
$t = turn('Hulda', snap(['rank' => '3']), 'inputtext', 'hi', false);
check('... her next turn is told the switch and the page, not her old "cannot do that right now"',
    str_contains(lrgVolatileGuidance($t), 'dry-run switch') && str_contains(lrgVolatileGuidance($t), 'Diagnostics')
    && !str_contains(lrgVolatileGuidance($t), 'cannot do that right now'), fx_cut(lrgVolatileGuidance($t)));
$resetMem();
$quiet = lrgHandleGameMessage(['funcret', time(), 100, 'command@' . LRG_ACT_CLOTHING . '@ok=1;cid=sADULT;npc=Hulda;do=undress;who=npc;part=all@Error: adults only']);
check('"adults only" from the game is never voiced (no line about intimacy is ever asked for there)', $quiet === 'handled' && !isset($GLOBALS['LRG_VOICED']));
$resetMem();
lrgHandleGameMessage(['funcret', time(), 100, 'command@' . LRG_ACT_CLOTHING . '@ok=1;cid=sONE;npc=Hulda;do=undress;who=npc;part=all@Error: someone is watching']);
check('set-up: a first failure is voiced', isset($GLOBALS['LRG_VOICED']));
$second = lrgHandleGameMessage(['funcret', time(), 100, 'command@' . LRG_ACT_CONTROL . '@ok=1;cid=sONEb;npc=Hulda;do=goto;scene=X@Error: that position cannot be reached from here']);
check('a second failure within voice.min_gap_seconds is NOT voiced (one turn, not two) - it is told next turn',
    $second === 'handled' && !isset($GLOBALS['LRG_VOICED']) && lrgMemGet('Hulda')['last_result']['told'] === false);
$GLOBALS['LRG_TEST_NOW'] = time() + 60;
check('... a failure after the gap is voiced again', lrgHandleGameMessage(['funcret', time(), 100, 'command@' . LRG_ACT_CLOTHING . '@ok=1;cid=sTWO;npc=Hulda;do=dress;who=npc;part=all@Error: someone is watching']) === 'pass'
    && str_contains((string) ($GLOBALS['LRG_VOICED']['fact'] ?? ''), "Hulda's clothes did not go back on"), json_encode($GLOBALS['LRG_VOICED'] ?? null));
unset($GLOBALS['LRG_TEST_NOW']);
// the voice gate: a request for another character, or a catalog row without the follow-up, is stopped
$GLOBALS['HERIKA_NAME'] = 'Braith';
check('the voice gate stops a turn CHIM would give to another character, and she is told next turn instead',
    lrgVoicedGate() !== '' && !isset($GLOBALS['LRG_VOICED']) && lrgMemGet('Hulda')['last_result']['told'] === false);

echo "25. R6: on a lead tick she actually moves things along\n";
$resetMem();
lrgStoreScene('Hulda', ['ev' => 'start'] + $startScene); $setRow(['_tier_since' => time() - 500]);
$t = turn('Hulda', $in, 'lrg_scenetalk', 'lead'); $v = lrgVolatileGuidance($t);
check('lead notes: ONE change, forward not backwards, the next step marked', $t['lead'] && str_contains($v, 'ONE change')
    && str_contains($v, 'Something that has not happened yet') && (!$t['acts'] || str_contains($v, '[next step]')), substr($v, 0, 200));
lrgPostProcessActions([]);
check('she let it pass: remembered', (lrgMemGet('Hulda')['lead_idle'] ?? 0) === 1);
$t = turn('Hulda', $in, 'lrg_scenetalk', 'lead');
check('the next lead tick says so: this time she makes a change', $t['lead_idle'] === 1 && str_contains(lrgVolatileGuidance($t), 'this time Hulda makes a change'));
lrgPostProcessActions(["Hulda|command|" . LRG_ACT_CONTROL . "@P1\r\n"]);
check('an accepted change clears it', !isset(lrgMemGet('Hulda')['lead_idle']));
lrgStoreScene('Hulda', ['ev' => 'end'] + $startScene);

echo "26. non-adult / child nearby: TOTAL silence in every request type\n";
$resetMem(); $bad = [];
foreach ([['adult' => '0'], ['witkid' => '1']] as $why) {
    foreach (['inputtext', 'ginputtext_s', 'lrg_initiative', 'lrg_scenetalk', 'funcret', 'instruction'] as $type) {
        if ($type === 'lrg_initiative' && tick('Braith', snap($why + $drawn)) !== 'handled') { $bad[] = 'tick admitted'; }
        $t = turn('Braith', snap($why + $drawn + ['x' => '2']), $type, $type === 'lrg_scenetalk' ? 'lead' : 'approach');
        if ($t['mode'] !== 'silent' || $guidance($t) !== '' || $t['x'] !== null || $glueOffered() !== []) { $bad[] = key($why) . "/$type"; }
        foreach (LRG_GLUE_ACTIONS as $c) { if (lrgPostProcessActions(["Braith|command|$c@undress\r\n"]) !== []) { $bad[] = key($why) . "/$type: $c passed"; } }
    }
}
check('nothing offered, both guidance strings empty, x null, every glue line dropped, nothing remembered', $bad === [] && !isset(lrgMemGet('Braith')['invite']) && !isset(lrgMemGet('Braith')['initiative_at']), implode(' | ', $bad));

echo "27. Serana Dialogue Expansion: coarse words, SDE marriage counts as the player's spouse\n";
$resetMem();
RelationshipManager::$aff = ['Serana' => 40];
$t = turn('Serana', snap(['rank' => '4', 'sde' => '3', 'married' => '1', 'fac' => 'DLC1SeranaFaction,SDE_RMarriedFaction']));
check('married through SDE = married to the player: no "married" block, spouse bonus applies', !in_array('married', $t['gate']['reasons'], true) && $t['gate']['affinity'] === 100 && $t['mode'] === 'private');
check('sde=3 -> "well along", in words', str_contains(lrgStaticGuidance($t), 'their romance is well along'));
check('sde=-1 / absent: nothing is said about it', !str_contains(lrgStaticGuidance(turn('Serana', snap(['rank' => '4', 'sde' => '-1']))), 'romance'));

echo "28. the clock seam: every time read goes through lrgNow()\n";
$resetMem();
turn('Hulda', snap(['rank' => '3']));
$GLOBALS['LRG_TEST_NOW'] = time() + 91; $GLOBALS['gameRequest'] = ['inputtext', time(), 999, 'hi']; $GLOBALS['ENABLED_FUNCTIONS'] = ['Talk', LRG_ACT_START]; lrgPrepareTurn();
check('moving LRG_TEST_NOW by 91 s makes the snapshot stale', $GLOBALS['LRG_TURN']['gate']['reasons'] === ['no_fresh_snapshot'] && $GLOBALS['LRG_TURN']['mode'] === 'silent');
$resetMem();

echo "29. [0.3.1] R1: CHIM affinity is really read, survives a wipe, and a scene is worth a little\n";
$resetMem();
RelationshipManager::$aff = ['Hulda' => 30];
$i = lrgInterest('Hulda', snap(), lrgBuildProfile('Hulda', snap()));
check('CHIM affinity reaches the gate at all (the v0.3 class_exists guard was false at every call site)',
    $i['affinity'] === 30 && $i['chim'] === 30 && $i['rescue'] === '', json_encode($i));
check('... and the glue remembers it as its own last known value', (int) lrgRomance('Hulda')['last_affinity'] === 30);
RelationshipManager::$aff = []; // CHIM's restoreNPC deleted the entry, exactly as in playtest 7
$i = lrgInterest('Hulda', snap(), lrgBuildProfile('Hulda', snap()));
check('a WIPED entry does not read as 0: our last known value carries the gate', $i['affinity'] === 30 && $i['chim'] === null && $i['rescue'] === 'missing', json_encode($i));
RelationshipManager::$aff = ['Hulda' => 0]; // present, but rolled back to zero
$i = lrgInterest('Hulda', snap(), lrgBuildProfile('Hulda', snap()));
check('an entry rolled back to 0 is rescued the same way, and says so', $i['affinity'] === 30 && $i['rescue'] === 'zeroed', json_encode($i));
$resetMem();
check('no history, no bonus', lrgHistoryBonus('Hulda') === 0);
lrgRomanceBump('Hulda', 'scenes');
check('one scene together is worth leverage.history_bonus on the interest score', lrgHistoryBonus('Hulda') === 15, (string) lrgHistoryBonus('Hulda'));
lrgRomanceBump('Hulda', 'scenes'); lrgRomanceBump('Hulda', 'scenes');
check('further scenes add a little more, capped', lrgHistoryBonus('Hulda') === 25, (string) lrgHistoryBonus('Hulda'));
// the gain itself: a real scene, long enough, ended normally
$resetMem();
RelationshipManager::$aff = ['Hulda' => 10]; RelationshipManager::$calls = [];
$sexScene = '';
foreach (lrgSceneIndex()['scenes'] as $s) { if (!$s['excluded'] && !$s['transition'] && $s['actors'] === 2 && $s['tier'] === 'sexual') { $sexScene = $s['id']; break; } }
lrgHandleGameMessage(['lrg_scene', time(), 100, 'ev=Start;npc=Hulda;cid=sGAIN;scene=' . $sexScene . ';leader=NPC']);
check('[R3-O3] a CAPITALISED ev from the wire is folded on arrival, so the scene really opens',
    ($GLOBALS['db']->t['lrg_scene_state']['Hulda']['active'] ?? 0) === 1 && (json_decode((string) $GLOBALS['db']->t['lrg_scene_state']['Hulda']['payload'], true)['_started_at'] ?? 0) > 0);
$GLOBALS['LRG_TEST_NOW'] = time() + 200;
lrgHandleGameMessage(['lrg_scene', time(), 100, 'ev=end;npc=Hulda;cid=sGAIN;scene=' . $sexScene . ';how=finished;dur=200']);
check('a completed first scene is worth relationship.first_scene_gain, through CHIM\'s own API',
    RelationshipManager::$aff['Hulda'] === 18 && in_array('adjust:Hulda:8', RelationshipManager::$calls, true), json_encode(RelationshipManager::$calls));
check('... and the type is never passed (CHIM reserves the romantic ones)', !preg_grep('/^set:/', RelationshipManager::$calls));
RelationshipManager::$calls = [];
lrgHandleGameMessage(['lrg_scene', time(), 100, 'ev=Start;npc=Hulda;cid=sG2;scene=' . $sexScene]);
$GLOBALS['LRG_TEST_NOW'] = time() + 500;
lrgHandleGameMessage(['lrg_scene', time(), 100, 'ev=end;npc=Hulda;cid=sG2;scene=' . $sexScene . ';how=finished;dur=200']);
check('one gain per NPC per window: the second scene in the same window adds nothing', RelationshipManager::$calls === [], json_encode(RelationshipManager::$calls));
RelationshipManager::$calls = [];
lrgHandleGameMessage(['lrg_scene', time(), 100, 'ev=Start;npc=Hulda;cid=sG3;scene=' . $sexScene]);
$GLOBALS['LRG_TEST_NOW'] = time() + 520;
lrgHandleGameMessage(['lrg_scene', time(), 100, 'ev=end;npc=Hulda;cid=sG3;scene=' . $sexScene . ';how=interrupted;dur=300']);
check('a scene that was interrupted (a crash, a reload) is worth nothing', RelationshipManager::$calls === [], json_encode(RelationshipManager::$calls));
// [0.3.1 fix pass] the SCENES COUNTER obeys the same two guards as the gain. It used to be bumped on
// every ev=end of an open row, so an eight-second cancelled start paid lrgHistoryBonus() +15 on the
// interest score and turned the next real scene into "the second one" (+4 instead of the first +8).
$resetMem();
RelationshipManager::$aff = ['Hulda' => 10]; RelationshipManager::$calls = [];
$GLOBALS['LRG_TEST_NOW'] = time();
lrgHandleGameMessage(['lrg_scene', time(), 100, 'ev=start;npc=Hulda;cid=sABORT;scene=' . $sexScene]);
$GLOBALS['LRG_TEST_NOW'] = time() + 8;
lrgHandleGameMessage(['lrg_scene', time(), 100, 'ev=end;npc=Hulda;cid=sABORT;scene=' . $sexScene . ';dur=8;how=stopped']);
check('an 8 s cancelled start is not counted as a scene at all', (int) (lrgRomance('Hulda')['scenes'] ?? 0) === 0 && lrgHistoryBonus('Hulda') === 0,
    json_encode([lrgRomance('Hulda')['scenes'] ?? null, lrgHistoryBonus('Hulda')]));
$GLOBALS['LRG_TEST_NOW'] = time() + 100;
lrgHandleGameMessage(['lrg_scene', time(), 100, 'ev=start;npc=Hulda;cid=sREAL;scene=' . $sexScene]);
$GLOBALS['LRG_TEST_NOW'] = time() + 400;
lrgHandleGameMessage(['lrg_scene', time(), 100, 'ev=end;npc=Hulda;cid=sREAL;scene=' . $sexScene . ';dur=200;how=finished']);
check('... so the next real scene is still their FIRST, and worth first_scene_gain',
    (int) (lrgRomance('Hulda')['scenes'] ?? 0) === 1 && in_array('adjust:Hulda:8', RelationshipManager::$calls, true), json_encode(RelationshipManager::$calls));
unset($GLOBALS['LRG_TEST_NOW']);

echo "30. [0.3.1] R3: the outro turn exists, says more than one line, and offers no action\n";
$resetMem();
$GLOBALS['LRG_TEST_NOW'] = time();
lrgStoreNpcState('Hulda', snap(['ostim' => '1']));
lrgHandleGameMessage(['lrg_scene', time(), 100, 'ev=Start;npc=Hulda;cid=sOUT;scene=' . $sexScene . ';leader=NPC']);
lrgHandleGameMessage(['lrg_scene', time(), 100, 'ev=Change;npc=Hulda;cid=sOUT;scene=' . $sexScene . ';ncl=1;pcl=1;leader=NPC']);
$GLOBALS['LRG_TEST_NOW'] = time() + 200;
lrgHandleGameMessage(['lrg_scene', time(), 100, 'ev=end;npc=Hulda;cid=sOUT;scene=' . $sexScene . ';how=finished;dur=200']);
$ticket = lrgMemGet('Hulda')['outro'] ?? null;
check('the scene end leaves an outro ticket with the facts of the scene',
    is_array($ticket) && $ticket['dur'] === 200 && $ticket['how'] === 'finished' && $ticket['ncl'] === 1 && $ticket['first_time'] === true, json_encode($ticket));
check('[R3-O3] the acts really happened list is no longer empty (the case fold)', !empty($ticket['acts']) || !empty($ticket['scenes']), json_encode($ticket['acts'] ?? []));
lrgStoreNpcState('Hulda', snap());
$o = turn('Hulda', snap(), 'lrg_scenetalk', 'outro', false);
check('an outro request gets its own turn mode', $o['mode'] === 'outro', $o['mode']);
check('... offering NO glue action at all: she only talks', $glueOffered() === [], implode(',', $glueOffered()));
$g = lrgVolatileGuidance($o);
check('... with the after-intimacy block, not the scene notes', str_contains($g, '<after_intimacy>') && !str_contains($g, '<intimate_scene_now>'), fx_cut($g));
check('... and none of the four one-sentence caps on it', !str_contains($g, 'ONE short sentence') && str_contains($g, 'plain spoken sentences'), fx_cut($g));
check('... it forbids End_Conversation by name (CHIM offers it and it was picked 5x in one day)', str_contains($g, 'never End_Conversation'), fx_cut($g));
check('... it names what they did and what she does next', str_contains($g, 'What the two of them just did') && str_contains($g, 'does next and why'), fx_cut($g));
lrgApplyTurnRuntime($o);
check('... and its own token budget, not the 140/200 of a scene line', (int) ($GLOBALS['FORCE_MAX_TOKENS'] ?? 0) === 300, (string) ($GLOBALS['FORCE_MAX_TOKENS'] ?? 0));
// the cue itself comes from prompts.php, and the whole point of O5 is that it is NOT empty after ev=end
$GLOBALS['TEMPLATE_DIALOG'] = '<<TPL>>';
$GLOBALS['gameRequest'] = ['lrg_scenetalk', lrgNow(), 100 + (++$GLOBALS['LRG_TEST_SEQ']), '(Context location: Inn) outro'];
$GLOBALS['HERIKA_NAME'] = 'Hulda';
unset($GLOBALS['PROMPTS']);
lrgMemSet('Hulda', ['outro' => $ticket]); // the turn above consumed it; the cue is tested on its own
require LRG_DIR . '/prompts.php';
$cue = (string) (($GLOBALS['PROMPTS']['lrg_scenetalk']['cue'] ?? [''])[0] ?? '');
check('[R3-O5] the outro request gets a real cue although the scene row is already closed',
    str_contains($cue, 'says goodbye out loud') && str_contains($cue, 'Not one line'), fx_cut($cue));
check('... and the cue is not the one-short-sentence scene-talk cue', !str_contains($cue, 'one short, blunt sentence'), fx_cut($cue));
lrgMemSet('Hulda', ['outro' => null]);
$o2 = turn('Hulda', snap(), 'lrg_scenetalk', 'outro', false);
check('the ticket is consumed: an outro can never fire twice for one scene', $o2['mode'] !== 'outro', $o2['mode']);
unset($GLOBALS['LRG_TEST_NOW']);

echo "31. [0.3.1] R2 / R4: positions, a compound request, and the log line\n";
$resetMem();
check('an act id may carry a position, and the label says so in plain words',
    str_contains(lrgActLabel('vaginal/reversecowgirl', 'Hulda', 'Dovah'), 'reverse cowgirl'), lrgActLabel('vaginal/reversecowgirl'));
check('lrgActBase() strips it again, so every old comparison still works', lrgActBase('vaginal:npc/doggy') === 'vaginal:npc' && lrgActBase('kiss') === 'kiss');
check('a not-installed act is known to be not installed (69 has no scene in these packs)',
    lrgActInstalled('sixtynine', ['male', 'female']) === false && lrgActInstalled('vaginal', ['male', 'female']) === true);
$c = lrgRecogniseIntent('take my clothes off and then lets do missionary', ['current' => '', 'live' => [], 'sexes' => ['male', 'female'],
    'furn' => '', 'npc' => 'Hulda', 'player' => 'Dovah', 'npos' => 1, 'ceiling' => 4, 'acts_done' => []], []);
check('a compound request keeps BOTH halves, in the order they were said',
    $c['kind'] === 'undress' && (($c['extra']['kind'] ?? '') === 'act') && lrgActBase((string) ($c['extra']['act'] ?? '')) === 'vaginal',
    json_encode([$c['kind'], $c['extra']['kind'] ?? '-', $c['extra']['act'] ?? '-']));
$closed = turn('Nobody Special', snap(['fac' => 'JobJarlFaction']), 'inputtext', 'come give me a hug', false);
check('[R4] a closed turn still logs the player\'s own words and says why it was closed',
    str_contains(lrgTurnLogLine($closed), 'say="come give me a hug"') && str_contains(lrgTurnLogLine($closed), 'closed="'), lrgTurnLogLine($closed));
// [0.3.1 fix pass] ... but the PROMPT of a closed turn carries no explicit vocabulary: it is the one
// mode with no wording permission ($turn['x'] === null), and playtest 7's whole eighteen-minute
// complaint was mode closed while the directive was pasting the crudest available act label into it.
$closedSex = turn('Nobody Special', snap(['fac' => 'JobJarlFaction']), 'inputtext', 'suck my cock', false);
$closedDir = lrgRequestDirective($closedSex);
check('a closed turn names the request in plain words, never the explicit label',
    $closedSex['x'] === null && $closedDir !== '' && !preg_match('/\b(cock|pussy|mouth on)\b/i', $closedDir) && str_contains($closedDir, 'something physical'), $closedDir);

echo "32. [0.4 / OWNER ADDENDA 6] EVERYONE HAS A PRICE\n";
// Rules asserted, never example lines. Every fixture pins the affinity so the interest WORD is fixed:
// the stance (floor / ceiling / free) is chosen from that word, so a test that let it drift would be
// measuring two things at once (precision 7.6).
$resetMem();
// [pt15 / addenda 12e-f] the tavern girl is a SERVER (common wage, 40 a day). A bard is a performer
// (64 a day) since the per-trade wages; section 32c prices one separately.
$tav = ['fac' => 'JobInnServer,TownSolitudeFaction', 'gold' => '40', 'pgold' => '500'];
$price = function (string $npc, array $over, string $word): array {
    $s = snap($over);
    return lrgPriceFor($npc, $s, lrgBuildProfile($npc, $s), ['word' => $word]);
};
/**
 * Pin CHIM's affinity AND mark the one-time affinity repair as already done. Without the second half
 * `relationship.repair` (Lisette -> 26), when it is enabled, fires on the first live turn after every
 * $resetMem() - its marker lives in lrg_memory, which $resetMem() wipes - and every pinned negative
 * affinity below would silently become +26, i.e. a willing NPC who is never for sale.
 */
$pin = function (string $npc, int $aff) { RelationshipManager::$aff[$npc] = $aff; lrgMemSet($npc, ['affinity_repaired_at' => 1]); };
// --- 1. the owner's own yardstick: a tavern girl who barely knows him is the top of her band, 4 days x 40
$p = $price('Lisette', $tav, 'indifferent');
check('[1] tavern girl, indifferent: for sale, and her floor is four days of the common wage (4 x 40 = 160)', !empty($p['for_sale']) && $p['free'] === false
    && (int) $p['gold'] === 160 && $p['tier'] === 'poor' && $p['wage_tier'] === 'common' && (int) $p['wage_day'] === 40, json_encode($p));
check('[1] ... curious is the FLOOR of the same band, not the ceiling (1 day x 40)', (int) $price('Lisette', $tav, 'curious')['gold'] === 40);
// --- 2. an offer at her floor lifts not_close_enough AND NOTHING ELSE
$resetMem(); $pin('Lisette', -20);
$t = turn('Lisette', snap($tav), 'inputtext', "i'll pay you 160 septims");
check('[2] an offer at her floor lifts not_close_enough and nothing else', $t['gate']['reasons'] === [] && $t['mode'] === 'private'
    && !empty($t['gate']['paid']['accepted']) && (int) $t['gate']['paid']['offer'] === 160, json_encode([$t['mode'], $t['gate']['reasons'], $t['gate']['paid']]));
check('[2] ... and the turn line says why, in one greppable segment', str_contains(lrgTurnLogLine($t), 'decision:accepted') && str_contains(lrgTurnLogLine($t), 'floor:160') && str_contains(lrgTurnLogLine($t), '/wage:common@40/'), lrgTurnLogLine($t));
$wire = real(lrgPostProcessActions(["Lisette|command|" . LRG_ACT_START . "@160\r\n"]));
check('[2] ... the agreed figure travels to the game as pay=160', count($wire) === 1 && str_contains($wire[0], ';pay=160'), trim(implode('|', $wire)));
// --- 3. below the floor buys nothing
$resetMem(); $pin('Lisette', -20);
$t = turn('Lisette', snap($tav), 'inputtext', "i'll pay you 50 gold");
check('[3] below her floor changes nothing at all', in_array('not_close_enough', $t['gate']['reasons'], true) && $t['mode'] === 'closed'
    && (int) $t['gate']['paid']['offer'] === 50 && empty($t['gate']['paid']['accepted']), json_encode([$t['mode'], $t['gate']['paid']]));
check('[3] ... and the decision is logged as below_floor', str_contains(lrgTurnLogLine($t), 'decision:below_floor'), lrgTurnLogLine($t));
// --- 4. coin never buys privacy
$resetMem(); $pin('Lisette', -20);
$t = turn('Lisette', snap($tav + ['wit' => '2']), 'inputtext', "i'll pay you 200 septims");
check('[4] gold lifts closeness, never witnesses', in_array('witnesses', $t['gate']['reasons'], true)
    && !in_array('not_close_enough', $t['gate']['reasons'], true) && $t['mode'] === 'public', json_encode([$t['mode'], $t['gate']['reasons']]));
check('[4] ... so a hallucinated start is still dropped', real(lrgPostProcessActions(["Lisette|command|" . LRG_ACT_START . "@200"])) === []);
// --- 5. merchant, curious: the exact arithmetic, and she may not accept below her own floor
$mer = ['fac' => 'JobMerchantFaction', 'gold' => '800', 'pgold' => '1000'];
$pb = $price('Belethor', $mer, 'curious');
check('[5] merchant (trade wage, 100 a day), curious: 4 days x 100 = 400 septims', (int) $pb['gold'] === 400 && $pb['wage_tier'] === 'trade', json_encode($pb));
$resetMem(); $pin('Belethor', 20);
$t = turn('Belethor', snap($mer), 'inputtext', "i'll pay you 400 septims");
check('[5] ... 400 is accepted', !empty($t['gate']['paid']['accepted']) && $t['mode'] === 'private', json_encode([$t['mode'], $t['gate']['paid']]));
$wire = real(lrgPostProcessActions(["Belethor|command|" . LRG_ACT_START . "@395\r\n"]));
check('[5] ... but an acceptance BELOW her floor is dropped, never quietly discounted', $wire === [], trim(implode('|', $wire)));
// --- 6. the housecarl and the jarl: everyone has a price, and theirs is a fortune
check('[6] housecarl (fighter wage, 132 a day), indifferent: 300 days x 132 = 39600', (int) $price('Jordis', ['fac' => 'JobHousecarlFaction'], 'indifferent')['gold'] === 39600);
$jarl = ['fac' => 'JobJarlFaction', 'gold' => '5000', 'pgold' => '100000'];
check('[6] jarl (court wage, 500 a day), indifferent: 3000 days = 1500000, a fortune nobody could pay - but a price', (int) $price('Elisif the Fair', $jarl, 'indifferent')['gold'] === 1500000
    && !empty($price('Elisif the Fair', $jarl, 'indifferent')['for_sale']));
check('[6] ... and a jarl who is at least curious is a tenth of that (150000)', (int) $price('Elisif the Fair', $jarl, 'curious')['gold'] === 150000);
$resetMem(); $pin('Elisif the Fair', 0);
$t = turn('Elisif the Fair', snap($jarl), 'inputtext', "i'll pay you 20000 septims");
check('[6] ... an offer far below that leaves her closed', in_array('not_close_enough', $t['gate']['reasons'], true) && $t['mode'] === 'closed');
// --- 7. a marriage she would be betraying costs three times as much (stance pinned in both fixtures)
$inn = ['fac' => 'JobInnkeeperFaction', 'gold' => '900'];
check('[7] married under the secret rule: exactly three times the unmarried figure (innkeeper, trade: 1500 -> 4500)',
    (int) $price('Hulda', $inn, 'indifferent')['gold'] === 1500
    && (int) $price('Hulda', $inn + ['married' => '1', 'pspouse' => '0'], 'indifferent')['gold'] === 4500);
$resetMem(); $pin('Hulda', -60);
$t = turn('Hulda', snap($inn + ['married' => '1', 'pspouse' => '0']), 'inputtext', 'how much would it take');
check('[7] ... and the marriage is still a soft mark with total privacy required', in_array('married_secret', $t['gate']['soft'], true)
    && (int) $t['gate']['game_params']['maxwit'] === 0 && (int) $t['gate']['game_params']['folok'] === 0, json_encode([$t['gate']['soft'], $t['gate']['game_params']]));
// --- 8. the ones who are NOT for sale: no figure is ever named to them
$vig = ['fac' => 'VigilantOfStendarrFaction'];
$pv = $price('Vigilant Tolan', $vig, 'indifferent');
check('[8] a Vigilant is not for sale, and the reason is named', empty($pv['for_sale']) && $pv['why'] === 'never', json_encode($pv));
$pm = $price('Dinya Balu', ['fac' => 'RiftenTempleofMaraFaction'], 'indifferent');
check('[8] a priest of Mara is not_for_sale by profile', empty($pm['for_sale']) && $pm['why'] === 'not_for_sale', json_encode($pm));
$resetMem(); $pin('Vigilant Tolan', 40);
$t = turn('Vigilant Tolan', snap($vig + ['pgold' => '100000']), 'inputtext', "i'll pay you 100000 septims");
check('[8] ... no sum reaches a `never` profile', in_array('never', $t['gate']['reasons'], true) && $t['mode'] === 'silent');
check('[8] ... and NO money sentence is emitted at all (the absence is the rail)',
    !str_contains(lrgStaticGuidance($t), 'septims') && !str_contains(lrgVolatileGuidance($t), 'septims'), fx_cut(lrgStaticGuidance($t) . lrgVolatileGuidance($t)));
$resetMem(); $pin('Dinya Balu', 90);
$t = turn('Dinya Balu', snap(['fac' => 'RiftenTempleofMaraFaction']), 'inputtext', "i'll pay you 5000 septims");
check('[8] ... a not_for_sale NPC who IS willing keeps the action and loses the payment',
    str_contains($d = lrgRequestDirective($t), 'not for sale') && !preg_match('/\b\d{2,}\b/', $d), $d);
$wire = real(lrgPostProcessActions(["Dinya Balu|command|" . LRG_ACT_START . "@5000\r\n"]));
check('[8] ... i.e. the scene may begin and not one septim moves', count($wire) === 1 && !str_contains($wire[0], 'pay='), trim(implode('|', $wire)));
// --- 9. a willing NPC is never for sale: she is interested
$resetMem(); $pin('Lisette', 20);
$pf = $price('Lisette', $tav, 'interested');
check('[9] an interested NPC needs no price, only a token gift is possible', !empty($pf['free']) && (int) $pf['gold'] === 0 && (int) $pf['token'] <= 20, json_encode($pf));
$t = turn('Lisette', snap($tav), 'inputtext', "i'll pay you 200 septims");
check('[9] ... a gift she is offered is affordable and passes', !empty($t['gate']['paid']['accepted']) && $t['mode'] === 'private');
$wire = real(lrgPostProcessActions(["Lisette|command|" . LRG_ACT_START . "@200\r\n"]));
check('[9] ... and travels as pay=200', count($wire) === 1 && str_contains($wire[0], ';pay=200'), trim(implode('|', $wire)));
// --- 10. the player cannot afford what he offered
$resetMem(); $pin('Lisette', -20);
// (array_merge, not +: the union operator keeps the LEFT value and $tav already carries a pgold)
$t = turn('Lisette', snap(array_merge($tav, ['pgold' => '10'])), 'inputtext', "i'll pay you 160 septims");
check('[10] an offer he cannot pay is never accepted', empty($t['gate']['paid']['accepted']) && in_array('not_close_enough', $t['gate']['reasons'], true)
    && str_contains(lrgTurnLogLine($t), 'decision:unaffordable'), lrgTurnLogLine($t));
check('[10] ... and her turn says so, in her own words, instead of going silent',
    str_contains(lrgRequestDirective($t), 'more coin than the player is actually carrying')
    // [pt15, ruling D] ... plainly: he does not have it on him, show the coin first - and nothing is offered
    && str_contains(lrgRequestDirective($t), 'show the coin first') && !in_array(LRG_ACT_START, $t['offered'], true), lrgRequestDirective($t));
// the same on the FREE path, where the affordability check is the post-gate's job (rule 3)
$resetMem(); $pin('Lisette', 20);
$t = turn('Lisette', snap(array_merge($tav, ['pgold' => '10'])), 'inputtext', "i'll pay you 200 septims");
$wire = real(lrgPostProcessActions(["Lisette|command|" . LRG_ACT_START . "@200\r\n"]));
check('[10] ... a gift he cannot pay drops the start and leaves her a reason for the next turn', $wire === []
    && str_contains((string) (lrgMemGet('Lisette')['last_result']['reason'] ?? ''), 'gold'), json_encode(lrgMemGet('Lisette')['last_result'] ?? null));
// --- 11. status, fame and a standing arrangement all move the figure
check('[11] the Dragonborn pays a quarter less (renown_sway strong): 3 days x 40', (int) $price('Lisette', $tav + ['pdb' => '1'], 'indifferent')['gold'] === 120
    && (int) $price('Lisette', $tav, 'indifferent')['gold'] === 160);
$resetMem(); lrgRomanceBump('Lisette', 'gold_accepted', 160);
check('[12] a repeat customer pays repeat_factor on the DAYS, not on the rounded gold (3.4 x 40 = 136 -> 135)', (int) $price('Lisette', $tav, 'indifferent')['gold'] === 135,
    json_encode($price('Lisette', $tav, 'indifferent')));
$resetMem();
// --- 13. the multiplier's two independent guards (a 0 here would make everyone free)
check('[13] pm absent and pm=0 both read as 1.0; a real multiplier scales the DAYS once',
    (int) $price('Lisette', $tav, 'indifferent')['gold'] === 160
    && (int) $price('Lisette', $tav + ['pm' => '0'], 'indifferent')['gold'] === 160
    && (int) $price('Lisette', $tav + ['pm' => '2.00'], 'indifferent')['gold'] === 320,
    json_encode([$price('Lisette', $tav + ['pm' => '0'], 'indifferent')['gold'], $price('Lisette', $tav + ['pm' => '2.00'], 'indifferent')['gold']]));
check('[13] the MCM master switch off (paidok=0) closes the subject entirely',
    empty($price('Lisette', $tav + ['paidok' => '0'], 'indifferent')['for_sale'])
    && !empty($price('Lisette', $tav + ['paidok' => '1'], 'indifferent')['for_sale'])
    && !empty($price('Lisette', $tav, 'indifferent')['for_sale']));  // ABSENT means ON: an old game script
// --- 14. she can never accept an offer nobody made on her own move
$resetMem(); $pin('Lisette', -20);
lrgStoreNpcState('Lisette', snap($tav));
lrgMemSet('Lisette', ['paid_offer' => ['gold' => 500, 'at' => lrgNow()]]);
$g = lrgEvaluateGates('Lisette', 'lrg_initiative', true);
check('[14] no gold path on her own initiative tick', empty($g['paid']['accepted']) && ($g['paid']['why'] ?? '') === 'not_player_speech'
    && in_array('not_close_enough', $g['reasons'], true), json_encode($g['paid'] ?? []));
// --- 15. gold may never exceed a figure the PLAYER said out loud
$resetMem(); $pin('Lisette', 20);
$t = turn('Lisette', snap($tav), 'inputtext', 'i want you');
$wire = real(lrgPostProcessActions(["Lisette|command|" . LRG_ACT_START . "@300\r\n"]));
check('[15] she named 300 and he never offered a figure: nothing is charged', count($wire) === 1 && !str_contains($wire[0], 'pay='), trim(implode('|', $wire)));
$resetMem(); $pin('Lisette', 20);
$t = turn('Lisette', snap($tav), 'inputtext', "i'll pay you 60 septims");
$wire = real(lrgPostProcessActions(["Lisette|command|" . LRG_ACT_START . "@5000\r\n"]));
check('[15] ... and she cannot charge more than he offered', count($wire) === 1 && str_contains($wire[0], ';pay=60'), trim(implode('|', $wire)));
check('[15] the wire parameter is read two ways and never as a name', lrgAmountFromParam('250') === 250
    && lrgAmountFromParam('amount=250;target=Player') === 250 && lrgAmountFromParam('Player') === 0 && lrgAmountFromParam('') === 0);
// --- 16. paid sex counts half, is remembered, and pays into her history
$resetMem(); $pin('Lisette', 20);
lrgStoreNpcState('Lisette', snap($tav));
$sc = ['npc' => 'Lisette', 'cid' => 'sPAID', 'scene' => 'OARE_StandingEmbraceKiss', 'paid' => '200'];
lrgHandleSceneMessage('Lisette', ['ev' => 'start'] + $sc, ['lrg_scene', time(), 1, '']);
check('[16] the arrangement is remembered the moment the game confirms the gold moved',
    (int) (lrgMemGet('Lisette')['paid']['gold'] ?? 0) === 200 && (int) (lrgMemGet('Lisette')['paid']['times'] ?? 0) === 1, json_encode(lrgMemGet('Lisette')['paid'] ?? null));
$GLOBALS['LRG_TEST_NOW'] = time() + 200;
RelationshipManager::$calls = [];
lrgHandleSceneMessage('Lisette', ['ev' => 'end', 'dur' => '200', 'how' => 'finished'] + $sc, ['lrg_scene', time(), 1, '']);
check('[16] ... a paid first scene is worth half of first_scene_gain', RelationshipManager::$calls === ['adjust:Lisette:4'], json_encode(RelationshipManager::$calls));
check('[16] ... and the gold goes into lrg_romance.gold_accepted, which is what prices him next time',
    (int) (lrgRomance('Lisette')['gold_accepted'] ?? 0) === 200, json_encode(lrgRomance('Lisette')));
$tk = lrgMemGet('Lisette')['outro'] ?? [];
check('[16] ... the goodbye knows it was an arrangement', (int) ($tk['paid'] ?? 0) === 200 && str_contains(lrgOutroGuidance(['npc' => 'Lisette', 'outro' => $tk, 'gate' => ['state' => snap($tav)]]), 'arrangement, not a romance'));
check('[16] ... and the offer is spent: neither the offer nor her quote survives the scene',
    !isset(lrgMemGet('Lisette')['paid_offer']) && !isset(lrgMemGet('Lisette')['price_quoted']));
unset($GLOBALS['LRG_TEST_NOW']);
// --- 17. asking the price works although an indifferent NPC is always in mode `closed`
$resetMem(); $pin('Lisette', -20);
$t = turn('Lisette', snap($tav), 'inputtext', 'how much for a night');
check('[17] coin is the only thing in the way, so the figure may be named in mode closed',
    $t['mode'] === 'closed' && lrgPriceCouldOpen($t['gate']) && str_contains(lrgRequestDirective($t), '160 septims'), lrgRequestDirective($t));
check('[17] ... and the boundary text no longer claims a bigger offer changes nothing',
    str_contains(lrgStaticGuidance($t), 'would not go below 160 septims') && !str_contains(lrgStaticGuidance($t), 'a bigger offer'), fx_cut(lrgStaticGuidance($t)));
check('[17] ... the purse is a WORD, never the number', str_contains(lrgStaticGuidance($t), 'easily enough for that') && !str_contains(lrgStaticGuidance($t), '500'), fx_cut(lrgStaticGuidance($t)));
check('[17] ... and the figure she was told is remembered, so a bare "deal" can close it',
    (int) (lrgMemGet('Lisette')['price_quoted']['gold'] ?? 0) === 160, json_encode(lrgMemGet('Lisette')['price_quoted'] ?? null));
$t2 = turn('Lisette', snap($tav), 'inputtext', 'deal');
check('[17] ... "deal" closes her own quote and lifts the gate', !empty($t2['gate']['paid']['accepted'])
    && ($t2['gate']['paid']['source'] ?? '') === 'quote' && $t2['mode'] === 'private', json_encode([$t2['mode'], $t2['gate']['paid']]));
// --- 18. something a price cannot fix is never answered with a figure
$resetMem(); $pin('Sigrid', -20);
$t = turn('Sigrid', snap(['married' => '1', 'fac' => 'JobFarmerFaction']), 'inputtext', 'how much for a night');
// (a married-refuse NPC never reaches for_sale at all - lrgPriceFor stops at step 0 with why=married -
//  so she gets the not-for-sale shape, which is the truthful one: coin cannot buy her)
check('[18] a married-refuse NPC is quoted no figure at all', !lrgPriceCouldOpen($t['gate'])
    && ($t['gate']['price']['why'] ?? '') === 'married'
    && !preg_match('/\b\d{2,}\b/', $d = lrgRequestDirective($t)) && str_contains($d, 'not for sale'), $d);
// --- 19. the offer survives the walk to somewhere private (offer_ttl_seconds 600, not 180)
$resetMem(); $pin('Lisette', -20);
$t = turn('Lisette', snap($tav + ['wit' => '2']), 'inputtext', "i'll pay you 160 septims");
check('[19] the offer is taken in a crowded room, and the room is still the blocker', $t['mode'] === 'public'
    && (int) (lrgMemGet('Lisette')['paid_offer']['gold'] ?? 0) === 160);
$GLOBALS['LRG_TEST_NOW'] = time() + 420;   // seven minutes later, alone (playtest 8's real timing)
$t = turn('Lisette', snap($tav), 'inputtext', 'we are alone now');
check('[19] ... and seven minutes later, alone, it still stands', !empty($t['gate']['paid']['accepted'])
    && ($t['gate']['paid']['source'] ?? '') === 'memory' && $t['mode'] === 'private', json_encode([$t['mode'], $t['gate']['paid']]));
$GLOBALS['LRG_TEST_NOW'] = time() + 900;
$t = turn('Lisette', snap($tav), 'inputtext', 'we are alone now');
check('[19] ... but an offer older than its TTL is gone', empty($t['gate']['paid']['accepted']) && $t['mode'] === 'closed');
unset($GLOBALS['LRG_TEST_NOW']);

echo "32b. [0.5.6 / pt15] the owner's rulings A-D: firm offers, the confirming question, the purse\n";
// The fixture is tonight's log, replayed on the per-trade wages (owner addenda 12e-f): Katana, a curious
// adventurer (aff 1 against min 20, score -19), the player carrying 322. On the old flat 35-a-day wage her
// floor was 225; it is now 4 days x 132 (fighter) = 528, 530 after round_to. Her live follower factions
// (CurrentFollowerFaction ...) are left out so section 34's follower rules are not mixed in here.
// Rules asserted, never lines she says.
$logTail = function (string $needle): string {
    $all = (string) @file_get_contents(dirname(LRG_DIR, 2) . '/log/lorerim_glue.log');
    $hit = '';
    foreach (explode("\n", $all) as $l) { if (str_contains($l, $needle)) { $hit = $l; } }
    return $hit;
};
$katF = ['fac' => 'PotentialFollowerFaction,AK69KatanaFaction', 'class' => 'AK69KatanaClass', 'gold' => '0'];
$kat = array_merge($katF, ['pgold' => '322']);    // tonight's purse
$mid = array_merge($katF, ['pgold' => '700']);    // enough for her price
$rich = array_merge($katF, ['pgold' => '1500']);
// --- the log, verbatim: "and everybody has a price"
$resetMem(); $pin('Katana', 1);
$t = turn('Katana', snap($kat), 'inputtext', 'and everybody has a price');
$d = lrgRequestDirective($t);
check('[pt15 1] "and everybody has a price" is askprice, and she NAMES her price (never "I have no price")',
    $t['intent']['kind'] === 'askprice' && $t['mode'] === 'closed' && str_contains($d, '530 septims') && str_contains($d, 'never claims to have no price'), $t['intent']['kind'] . ' | ' . $d);
check('[pt15 1] ... the boundary text makes the one exception: strangers pay her price',
    str_contains(lrgStaticGuidance($t), 'unless they pay') && str_contains(lrgStaticGuidance($t), 'never claims to have none'), fx_cut(lrgStaticGuidance($t), 900));
check('[pt15 1] ... and the figure she names is remembered for a bare "deal"', (int) (lrgMemGet('Katana')['price_quoted']['gold'] ?? 0) === 530);
check('[pt15 1] ... the price line names whose day it is: fighter, 16.5 an hour, 132 a day, 4 days, floor 530',
    str_contains($l = $logTail('price npc=Katana'), 'tier=modest wage=fighter/rule gph=16.5 day_wage=132 band=4-15d')
    && str_contains($l, ' days=4 ') && str_contains($l, ' floor=530 '), $l);
check('[pt15 1] ... and so does the turn line (paid=.../wage:fighter@132/...)', str_contains(lrgTurnLogLine($t), '/tier:modest/wage:fighter@132/')
    && str_contains(lrgTurnLogLine($t), 'paid=floor:530/'), lrgTurnLogLine($t));
// --- the log, verbatim: the "if" sentence, with 322 septims in his purse
$t = turn('Katana', snap($kat), 'inputtext', "if you were to fuck me i'd give you a thousand gold", false);
check('[pt15 2] ruling A: the "if" sentence is a HYPOTHETICAL offer WITH its amount (1000), not askprice/0',
    $t['intent']['kind'] === 'offer' && (int) $t['intent']['gold'] === 1000 && empty($t['intent']['firm']) && $t['intent']['from'] === 'hypothetical',
    json_encode([$t['intent']['kind'], $t['intent']['gold'], $t['intent']['from']]));
check('[pt15 2] ... the price line carries offer=1000/hypothetical', str_contains($logTail('price npc=Katana'), 'offer=1000/hypothetical'), $logTail('price npc=Katana'));
check('[pt15 2] ... ruling D: 1000 > his 322 - she calls it out (show the coin first), nothing starts, nothing is pending',
    str_contains($d = lrgRequestDirective($t), 'show the coin first') && $t['mode'] === 'closed' && !in_array(LRG_ACT_START, $t['offered'], true)
    && !isset(lrgMemGet('Katana')['offer_pending']), $d);
// --- a hypothetical he CAN pay: she asks him to confirm, and remembers it
$resetMem(); $pin('Katana', 1);
$t = turn('Katana', snap($rich), 'inputtext', "if you were to fuck me i'd give you a thousand gold");
$pend = lrgMemGet('Katana')['offer_pending'] ?? null;
check('[pt15 3] ruling C: a hypothetical at/above her floor -> no Start, one confirming question, nothing started',
    $t['mode'] === 'closed' && !in_array(LRG_ACT_START, $t['offered'], true)
    && str_contains($d = lrgRequestDirective($t), 'asks him to confirm') && str_contains($d, 'Do not start anything yet'), $d);
check('[pt15 3] ... the pending offer is stored: amount, NPC, time, ~2 minute expiry',
    is_array($pend) && (int) $pend['gold'] === 1000 && ($pend['npc'] ?? '') === 'Katana' && (int) $pend['expires'] - (int) $pend['at'] === 120,
    json_encode($pend));
check('[pt15 3] ... and the turn line says decision:confirm', str_contains(lrgTurnLogLine($t), 'decision:confirm'), lrgTurnLogLine($t));
// the SECOND PASS of the same request (prerequest after the functions hook) must read the same thing
$GLOBALS['ENABLED_FUNCTIONS'] = TEST_ENABLED;
lrgPrepareTurn();
$t2 = $GLOBALS['LRG_TURN'];
check('[pt15 3] ... the second pass of the same request is not mistaken for his confirmation',
    $t2['intent']['from'] === 'hypothetical' && $t2['mode'] === 'closed' && (int) (lrgMemGet('Katana')['offer_pending']['at'] ?? 0) === (int) $pend['at'],
    json_encode([$t2['intent']['from'], $t2['mode']]));
// --- "yes" completes it as a FIRM offer at the remembered amount
$t = turn('Katana', snap($rich), 'inputtext', 'yes', false);
check('[pt15 4] "yes" = a FIRM offer at the pending 1000 -> not_close_enough lifted, mode private, Start offered',
    $t['intent']['kind'] === 'offer' && (int) $t['intent']['gold'] === 1000 && $t['intent']['from'] === 'confirm'
    && !empty($t['gate']['paid']['accepted']) && $t['mode'] === 'private' && in_array(LRG_ACT_START, $t['offered'], true),
    json_encode([$t['intent']['kind'], $t['intent']['from'], $t['mode'], $t['gate']['reasons'], $t['gate']['paid']['why'] ?? '']));
check('[pt15 4] ... ruling B: the directive takes it (amount 1000) - the gold is the trust, no refusing for lack of it',
    str_contains($d = lrgRequestDirective($t), 'BeginIntimacy with amount 1000') && str_contains($d, 'The gold is the trust')
    && str_contains($d, 'does not refuse for lack of trust') && str_contains($d, 'haggles'), $d);
check('[pt15 4] ... and "bought" is on the gate line', str_contains($logTail('gate npc=Katana'), 'lifted=bought:1000'), $logTail('gate npc=Katana'));
$GLOBALS['ENABLED_FUNCTIONS'] = TEST_ENABLED;
lrgPrepareTurn();
check('[pt15 4] ... the second pass of the "yes" still reads the confirmation', ($GLOBALS['LRG_TURN']['intent']['from'] ?? '') === 'confirm'
    && ($GLOBALS['LRG_TURN']['mode'] ?? '') === 'private');
$wire = real(lrgPostProcessActions(["Katana|command|" . LRG_ACT_START . "@1000\r\n"]));
check('[pt15 4] ... and the agreed 1000 travels as pay=1000', count($wire) === 1 && str_contains($wire[0], ';pay=1000'), trim(implode('|', $wire)));
// --- tonight's purse against her new price: the firm 300 that bought her at 225 no longer does
$resetMem(); $pin('Katana', 1);
$t = turn('Katana', snap($kat), 'inputtext', "I'll give you 300 gold");
check('[pt15 5] tonight replayed: a firm 300 with 322 on him is below her floor (530) -> closed, "not for 300", no Start',
    $t['mode'] === 'closed' && !in_array(LRG_ACT_START, $t['offered'], true) && str_contains($d = lrgRequestDirective($t), 'not for 300')
    && str_contains($d, '530 septims') && str_contains(lrgTurnLogLine($t), 'decision:below_floor'), $d);
// --- a FIRM offer, straight away, at or above her floor and within his purse
$resetMem(); $pin('Katana', 1);
$t = turn('Katana', snap($mid), 'inputtext', "I'll give you 600 gold");
check('[pt15 5] curious + firm 600 >= 530 + carrying 700 -> Start offered at once (trust does not matter)',
    !empty($t['gate']['paid']['accepted']) && $t['mode'] === 'private' && in_array(LRG_ACT_START, $t['offered'], true)
    && str_contains(lrgRequestDirective($t), 'amount 600'), json_encode([$t['mode'], $t['gate']['paid'], $t['offered']]));
check('[pt15 5] ... her notes say the paid offer is the trust, never "no sum decides it"',
    str_contains(lrgVolatileGuidance($t), 'the gold is the trust') && !str_contains(lrgVolatileGuidance($t), 'Nothing the player says can decide this')
    && str_contains(lrgStaticGuidance($t), 'lack of closeness is no reason to refuse'), fx_cut(lrgVolatileGuidance($t), 900));
$t = turn('Katana', snap($mid), 'inputtext', 'take six hundred and come upstairs', false);
check('[pt15 5] "take six hundred and come upstairs" is a firm offer, not a walk', $t['intent']['kind'] === 'offer'
    && (int) $t['intent']['gold'] === 600 && !empty($t['intent']['firm']) && !empty($t['gate']['paid']['accepted']),
    json_encode([$t['intent']['kind'], $t['intent']['gold'], $t['intent']['from']]));
// --- firm, below her floor: closed, and she counters with her price
$resetMem(); $pin('Katana', 1);
$t = turn('Katana', snap($mid), 'inputtext', "I'll give you a hundred gold");
check('[pt15 6] firm 100 < 530 -> closed, no Start, "not for 100" and her price named',
    $t['mode'] === 'closed' && !in_array(LRG_ACT_START, $t['offered'], true) && str_contains($d = lrgRequestDirective($t), 'not for 100')
    && str_contains($d, '530 septims'), $d);
check('[pt15 6] ... the price she names is remembered, so "deal" closes it', (int) (lrgMemGet('Katana')['price_quoted']['gold'] ?? 0) === 530);
$t = turn('Katana', snap($mid), 'inputtext', 'deal', false);
check('[pt15 6] ... and "deal" does', !empty($t['gate']['paid']['accepted']) && (int) $t['gate']['paid']['offer'] === 530 && $t['mode'] === 'private');
// --- firm, but more than he is carrying (ruling D)
$resetMem(); $pin('Katana', 1);
$t = turn('Katana', snap($kat), 'inputtext', "I'll give you 600 gold, right now");
check('[pt15 7] firm 600 with 322 on him -> called out plainly, no Start, decision unaffordable',
    $t['mode'] === 'closed' && !in_array(LRG_ACT_START, $t['offered'], true) && str_contains($d = lrgRequestDirective($t), 'show the coin first')
    && str_contains(lrgTurnLogLine($t), 'decision:unaffordable'), $d);
// --- pending expiry, withdrawal, replacement
$resetMem(); $pin('Katana', 1);
$t = turn('Katana', snap($rich), 'inputtext', 'a thousand gold for a night with you');
check('[pt15 8] an implied figure is grey too: she asks, it is pending', empty($t['intent']['firm']) && (int) (lrgMemGet('Katana')['offer_pending']['gold'] ?? 0) === 1000);
$GLOBALS['LRG_TEST_NOW'] = time() + 130;
$t = turn('Katana', snap($rich), 'inputtext', 'yes', false);
check('[pt15 8] ... after ~2 minutes it has expired: a late "yes" buys nothing', $t['intent']['kind'] !== 'offer' && empty($t['gate']['paid']['accepted'])
    && $t['mode'] === 'closed', json_encode([$t['intent']['kind'], $t['mode']]));
unset($GLOBALS['LRG_TEST_NOW']);
$resetMem(); $pin('Katana', 1);
turn('Katana', snap($rich), 'inputtext', "what if I gave you 1000 gold");
$t = turn('Katana', snap($rich), 'inputtext', 'no', false);
check('[pt15 9] "no" to her question withdraws it: kind withdraw, nothing starts, she lets it go',
    $t['intent']['kind'] === 'withdraw' && $t['mode'] === 'closed' && str_contains(lrgRequestDirective($t), 'lets it')
    && (lrgMemGet('Katana')['offer_pending']['state'] ?? '') === 'withdrawn', json_encode([$t['intent']['kind'], lrgMemGet('Katana')['offer_pending'] ?? null]));
$GLOBALS['ENABLED_FUNCTIONS'] = TEST_ENABLED;
lrgPrepareTurn();
check('[pt15 9] ... and the second pass of that "no" agrees', ($GLOBALS['LRG_TURN']['intent']['kind'] ?? '') === 'withdraw');
$t = turn('Katana', snap($rich), 'inputtext', 'yes', false);
check('[pt15 9] ... a "yes" after taking it back buys nothing', empty($t['gate']['paid']['accepted']) && $t['mode'] === 'closed');
$resetMem(); $pin('Katana', 1);
turn('Katana', snap($rich), 'inputtext', "would a thousand gold do?");
$t = turn('Katana', snap($rich), 'inputtext', 'make it 600', false);
check('[pt15 10] a different figure replaces the pending one (and she asks again)',
    (int) (lrgMemGet('Katana')['offer_pending']['gold'] ?? 0) === 600 && str_contains(lrgRequestDirective($t), 'does he mean 600'), lrgRequestDirective($t));
$t = turn('Katana', snap($rich), 'inputtext', 'six hundred', false);
check('[pt15 10] ... repeating the amount completes it', !empty($t['gate']['paid']['accepted']) && (int) $t['gate']['paid']['offer'] === 600
    && $t['mode'] === 'private', json_encode([$t['mode'], $t['gate']['paid']]));
// --- a hypothetical below her floor: "not for N", no pending
$resetMem(); $pin('Katana', 1);
$t = turn('Katana', snap($rich), 'inputtext', 'would fifty do?');
check('[pt15 11] a hypothetical below her floor -> "not for 50", her price named, nothing pending',
    str_contains($d = lrgRequestDirective($t), 'not for 50') && str_contains($d, '530 septims') && !isset(lrgMemGet('Katana')['offer_pending']), $d);
// --- witnesses still route through the invitation first
$resetMem(); $pin('Katana', 1);
$t = turn('Katana', snap(array_merge($mid, ['wit' => '2'])), 'inputtext', "I'll give you 600 gold");
check('[pt15 12] firm + affordable in a crowded room -> public: no Start, SuggestPrivacy is how she takes it',
    $t['mode'] === 'public' && !in_array(LRG_ACT_START, $t['offered'], true) && in_array(LRG_ACT_INVITE, $t['offered'], true)
    && str_contains($d = lrgRequestDirective($t), 'SuggestPrivacy'), json_encode([$t['mode'], $t['offered']]) . ' ' . $d);
// --- interested: no price needed; a hypothetical never charges
$resetMem(); $pin('Lisette', 20);
$t = turn('Lisette', snap($tav), 'inputtext', 'what if I gave you 200 gold');
check('[pt15 13] interested -> no price needed', str_contains(lrgRequestDirective($t), 'does not need paying') && $t['mode'] === 'private');
$wire = real(lrgPostProcessActions(["Lisette|command|" . LRG_ACT_START . "@200\r\n"]));
check('[pt15 13] ... and an "if" is never charged, even if she names it', count($wire) === 1 && !str_contains($wire[0], 'pay='), trim(implode('|', $wire)));
// --- not for sale: the plain refusal, no figure
$resetMem(); $pin('Dinya Balu', -20);
$t = turn('Dinya Balu', snap(['fac' => 'RiftenTempleofMaraFaction']), 'inputtext', "I'll give you a thousand gold");
check('[pt15 14] a not-for-sale NPC gets the plain refusal and no figure at all',
    str_contains($d = lrgRequestDirective($t), 'not for sale') && !preg_match('/\b\d{2,}\b/', $d) && !in_array(LRG_ACT_START, $t['offered'], true), $d);
$resetMem(); $pin('Vigilant Tolan', -20);
$t = turn('Vigilant Tolan', snap(['fac' => 'VigilantOfStendarrFaction', 'pgold' => '100000']), 'inputtext', "I'll give you a thousand gold");
check('[pt15 14] ... a `never` profile stays silent: no price, no action, no money sentence', $t['mode'] === 'silent'
    && !in_array(LRG_ACT_START, $t['offered'], true) && !str_contains(lrgStaticGuidance($t) . lrgVolatileGuidance($t), 'septims'));
// --- the post-gate affordability drop is VOICED on her next turn (ruling D)
$resetMem(); $pin('Lisette', 20);
$t = turn('Lisette', snap(array_merge($tav, ['pgold' => '10'])), 'inputtext', "i'll pay you 200 septims");
real(lrgPostProcessActions(["Lisette|command|" . LRG_ACT_START . "@200\r\n"]));
$t = turn('Lisette', snap(array_merge($tav, ['pgold' => '10'])), 'inputtext', 'what happened', false);
check('[pt15 15] a start dropped for an empty purse is called out next turn: show the coin first',
    str_contains(lrgVolatileGuidance($t), 'show the coin first'), fx_cut(lrgVolatileGuidance($t), 900));
$resetMem();

echo "32c. [pt15 / owner addenda 12e-f] per-trade wages: the tier table - whose day a price is counted in\n";
// A day is 8 hours of HER work: common 5/h = 40, performer 8/h = 64, trade 12.5/h = 100, fighter 16.5/h = 132,
// craft_magic 25/h = 200, court 62.5/h = 500. The bands stay in days. Curious = the band's floor days,
// indifferent = its ceiling days, every shipped money_influence is 1.0, round_to 5. The fixtures are the
// factions and classes the owner's own snapshots carry (lrg_npc_state, 2026-09-23) where there is one.
$cfgW = (array) (lrgConfig()['paid_intimacy'] ?? []);
check('[tier] the six tiers, in gold per hour, with an 8-hour day - and no flat day wage left',
    (float) $cfgW['hours_per_day'] === 8.0 && array_map('floatval', (array) $cfgW['wage_tiers']) === ['common' => 5.0, 'performer' => 8.0, 'trade' => 12.5,
        'fighter' => 16.5, 'craft_magic' => 25.0, 'court' => 62.5] && ($cfgW['default_wage_tier'] ?? '') === 'common'
    && !array_key_exists('gold_per_day_of_wage', $cfgW), json_encode([$cfgW['hours_per_day'] ?? null, $cfgW['wage_tiers'] ?? null]));
$rulesW = [];
foreach ((array) (lrgConfig()['status_rules'] ?? []) as $r) { $rulesW[(string) $r['id']] = (string) ($r['wage_tier'] ?? ''); }
$rulesW['fallback'] = (string) ((lrgConfig()['fallback'] ?? [])['wage_tier'] ?? '');
check('[tier] every status rule and the fallback names its wage tier, and every one is a real tier',
    count(array_filter($rulesW, fn($t) => !isset($cfgW['wage_tiers'][$t]))) === 0 && $rulesW === ['vigilant' => 'fighter', 'jarl' => 'court',
        'court' => 'court', 'housecarl' => 'fighter', 'guard' => 'trade', 'priest_dibella' => 'trade', 'priest_mara' => 'trade', 'priest' => 'trade',
        'innkeeper' => 'trade', 'tavern_folk' => 'common', 'beggar' => 'common', 'merchant' => 'trade', 'commoner' => 'common',
        'adventurer' => 'fighter', 'fallback' => 'common'], json_encode($rulesW));
// [who, snapshot, profile id, wage tier, where the tier came from, day wage, curious floor, indifferent ceiling]
$tierTable = [
    ['a beggar',                    ['fac' => 'JobsBeggarsFaction'],                                             'beggar',         'common',      'rule', 40,  20,     80],
    ['a tavern girl (a server)',    ['fac' => 'JobInnServer,TownSolitudeFaction'],                               'tavern_folk',    'common',      'rule', 40,  40,     160],
    ['a farmer',                    ['fac' => 'JobFarmerFaction'],                                               'commoner',       'common',      'rule', 40,  40,     160],
    ['a servant nobody\'s rule knows', ['fac' => 'TownWhiterunFaction', 'class' => 'Citizen'],                   'fallback',       'common',      'rule', 40,  160,    600],
    ['a bard (Lisette\'s factions)', ['fac' => 'TownSolitudeFaction,JobBardFaction,BardSingerFaction', 'class' => 'Bard'], 'tavern_folk', 'performer', 'job', 64, 65, 255],
    ['a merchant',                  ['fac' => 'JobMerchantFaction'],                                             'merchant',       'trade',       'rule', 100, 400,    1500],
    ['an innkeeper',                ['fac' => 'JobInnkeeperFaction'],                                            'innkeeper',      'trade',       'rule', 100, 400,    1500],
    ['a guard',                     ['fac' => 'IsGuardFaction,GuardFactionSolitude'],                            'guard',          'trade',       'rule', 100, 1500,   6000],
    ['a hunter (Hak)',              ['fac' => 'CrimeFactionHaafingar,HunterFaction', 'class' => 'CombatRanger'], 'fallback',       'trade',       'job',  100, 400,    1500],
    ['a priest',                    ['fac' => 'JobPriestFaction'],                                               'priest',         'trade',       'rule', 100, 6000,   30000],
    ['a priestess of Dibella',      ['fac' => 'MarkarthTempleofDibellaFaction'],                                 'priest_dibella', 'trade',       'rule', 100, 100,    400],
    ['Katana, an adventurer',       ['fac' => 'PotentialFollowerFaction,AK69KatanaFaction'],                     'adventurer',     'fighter',     'rule', 132, 530,    1980],
    ['a mercenary',                 ['fac' => 'WhiterunMercenaryFaction'],                                       'adventurer',     'fighter',     'rule', 132, 530,    1980],
    ['a housecarl',                 ['fac' => 'JobHousecarlFaction'],                                            'housecarl',      'fighter',     'rule', 132, 7920,   39600],
    ['a guard captain',             ['fac' => 'JobGuardCaptainFaction'],                                         'guard',          'fighter',     'job',  132, 1980,   7920],
    ['a smith (Beirand)',           ['fac' => 'JobBlacksmithFaction,JobMerchantFaction', 'class' => 'VendorBlacksmith'], 'merchant', 'craft_magic', 'job', 200, 800,   3000],
    ['an apothecary (Vivienne)',    ['fac' => 'ServicesSolitudeAngelinesAromatics', 'class' => 'VendorApothecary'], 'fallback',    'craft_magic', 'job',  200, 800,    3000],
    ['a mage (Makar [Mage])',       ['fac' => 'CrimeFactionHaafingar', 'class' => 'CombatMageElemental'],        'fallback',       'craft_magic', 'job',  200, 800,    3000],
    ['a court wizard',              ['fac' => 'JobCourtWizardFaction'],                                          'court',          'craft_magic', 'job',  200, 12000,  60000],
    ['a steward',                   ['fac' => 'JobStewardFaction'],                                              'court',          'court',       'rule', 500, 30000,  150000],
    ['a jarl',                      ['fac' => 'JobJarlFaction'],                                                 'jarl',           'court',       'rule', 500, 150000, 1500000],
];
$resetMem();
foreach ($tierTable as [$who, $fx, $pid, $tier, $from, $day, $lo, $hi]) {
    $s = snap($fx);
    $prof = lrgBuildProfile('Tier Probe', $s);
    $c = lrgPriceFor('Tier Probe', $s, $prof, ['word' => 'curious']);
    $i = lrgPriceFor('Tier Probe', $s, $prof, ['word' => 'indifferent']);
    $f = lrgPriceFor('Tier Probe', $s, $prof, ['word' => 'interested']);
    check(sprintf('[tier] %s: %s -> %s (%s) %d a day -> %d curious / %d indifferent, and free once interested', $who, $pid, $tier, $from, $day, $lo, $hi),
        (string) $prof['id'] === $pid && $c['wage_tier'] === $tier && $c['wage_from'] === $from && (int) round((float) $c['wage_day']) === $day
        && (int) $c['gold'] === $lo && (int) $i['gold'] === $hi && !empty($c['for_sale']) && empty($c['free'])
        && !empty($f['free']) && (int) $f['gold'] === 0,
        json_encode([$prof['id'], $c['wage_tier'], $c['wage_from'], $c['wage_day'], $c['gold'], $i['gold'], $f['free'], $f['gold']]));
}
// the owner's own examples, one line each
$ex = static fn(string $fac, string $word) => lrgPriceFor('Tier Probe', snap(['fac' => $fac]), lrgBuildProfile('Tier Probe', snap(['fac' => $fac])), ['word' => $word]);
$k = $ex('PotentialFollowerFaction,AK69KatanaFaction', 'curious');
check('[tier] Katana (adventurer -> fighter, modest, curious = the floor of her band): 4 days x 132 = 528, 530 after round_to 5',
    (float) $k['band'][0] === 4.0 && (float) $k['days'] === 4.0 && (int) round((float) $k['wage_day']) === 132 && (int) $k['gold'] === 530 && $k['tier'] === 'modest', json_encode($k));
check('[tier] a poor tavern girl (common, poor): 40 curious, 160 indifferent (the top of her band)',
    (int) $ex('JobInnServer', 'curious')['gold'] === 40 && (int) $ex('JobInnServer', 'indifferent')['gold'] === 160 && $ex('JobInnServer', 'curious')['tier'] === 'poor');
check('[tier] a modest merchant (trade): 400 - 1500', (int) $ex('JobMerchantFaction', 'curious')['gold'] === 400 && (int) $ex('JobMerchantFaction', 'indifferent')['gold'] === 1500);
check('[tier] a jarl (court wage, noble band): absurd - 150000 at the very least, 1500000 to a stranger, and max_price does not flatten it',
    (int) $ex('JobJarlFaction', 'curious')['gold'] >= 150000 && (int) $ex('JobJarlFaction', 'indifferent')['gold'] === 1500000
    && (int) $cfgW['max_price'] >= 1500000 && $ex('JobJarlFaction', 'curious')['tier'] === 'noble');
check('[tier] interested and drawn NPCs still need no price at all, whatever their wage',
    !empty($ex('JobJarlFaction', 'interested')['free']) && (int) $ex('JobJarlFaction', 'drawn')['gold'] === 0 && !empty($ex('JobBlacksmithFaction,JobMerchantFaction', 'drawn')['free']));
check('[tier] the job table only refines the rule ids it names: a jarl who is also a bard is still a jarl\'s wage',
    $ex('JobJarlFaction,JobBardFaction', 'curious')['wage_tier'] === 'court' && $ex('JobJarlFaction,JobBardFaction', 'curious')['wage_from'] === 'rule');
check('[tier] a gift from a willing NPC is capped by HER day too (token_days 0.5): a tavern girl 20, a smith 100',
    (int) $ex('JobInnServer', 'interested')['token'] === 20 && (int) $ex('JobBlacksmithFaction,JobMerchantFaction', 'interested')['token'] === 100);
// npc_overrides: wage_tier wins over the job table and the rule; price_gold still pins; a bad tier name is ignored
file_put_contents(LRG_DIR . '/config/lrg_config.json', json_encode(['npc_overrides' => [
    'Override Smith' => ['wage_tier' => 'court'], 'Pinned Girl' => ['price_gold' => 777], 'Bad Tier' => ['wage_tier' => 'nonsense']]]));
$ovCode = 'require "' . LRG_DIR . '/lib/lrg_core.php"; $o = [];'
    . ' foreach (["Override Smith" => "JobBlacksmithFaction,JobMerchantFaction", "Pinned Girl" => "JobInnServer", "Bad Tier" => "JobBlacksmithFaction,JobMerchantFaction"] as $n => $f) {'
    . ' $s = ["fac" => $f, "adult" => "1", "married" => "0", "pgold" => "0", "class" => ""];'
    . ' $p = lrgPriceFor($n, $s, lrgBuildProfile($n, $s), ["word" => "curious"]);'
    . ' $o[$n] = [$p["wage_tier"], $p["wage_from"], (int) $p["gold"]]; } echo json_encode($o);';
$ovOut = json_decode((string) shell_exec('php -r ' . escapeshellarg($ovCode)), true);
@unlink(LRG_DIR . '/config/lrg_config.json');
check('[tier] npc_overrides.wage_tier wins over the job table and the rule: a smith set to court = 4 x 500 = 2000',
    ($ovOut['Override Smith'] ?? null) === ['court', 'override', 2000], json_encode($ovOut));
check('[tier] ... npc_overrides.price_gold still pins her figure, whatever her wage', (int) ($ovOut['Pinned Girl'][2] ?? 0) === 777, json_encode($ovOut));
check('[tier] ... an unknown tier name is ignored, never a zero wage (that smith is priced as a smith)',
    ($ovOut['Bad Tier'] ?? null) === ['craft_magic', 'job', 800], json_encode($ovOut));
// [pt15] the one-shot Lisette repair must not re-arm on a fresh install
$rep = (array) ((lrgConfig()['relationship'] ?? [])['repair'] ?? []);
check('[repair] relationship.repair ships DISABLED, and the block stays documented as a template',
    array_key_exists('enabled', $rep) && $rep['enabled'] === false && ($rep['npc'] ?? '') === 'Lisette' && (int) ($rep['affinity'] ?? 0) === 26
    && str_contains((string) ((lrgConfig()['relationship'] ?? [])['_repair_readme'] ?? ''), 'SHIPPED DISABLED'), json_encode($rep));
$resetMem(); RelationshipManager::$aff['Lisette'] = -20; RelationshipManager::$calls = [];
turn('Lisette', snap($tav), 'inputtext', 'hello there');
check('[repair] ... so a live turn with Lisette on a wiped memory writes nothing into CHIM',
    !isset(lrgMemGet('Lisette')['affinity_repaired_at']) && RelationshipManager::$aff['Lisette'] === -20, json_encode([RelationshipManager::$calls, lrgMemGet('Lisette')['affinity_repaired_at'] ?? null]));
$resetMem();

echo "33. [0.4 / OWNER ADDENDA 4b] a closed door makes a room private\n";
$resetMem();
check('the door fact is words only, and only from the snapshot',
    str_contains(lrgPrivacyWords(['door' => '1', 'wit' => '0', 'witfol' => '0']), 'closed room')
    && lrgPrivacyWords(['door' => '0']) === '' && lrgPrivacyWords([]) === ''
    && str_contains(lrgPrivacyWords(['door' => '1', 'wit' => '2']), 'door of this room is shut'));
$pl = lrgPlaces(['ltype' => 'inn', 'door' => '1', 'cellown' => 'other']);
check('a shut door is named as a place to go, because it is the thing that makes the room private',
    str_contains((string) ($pl['quiet'] ?? ''), 'door that shuts'), json_encode($pl));
check('... and with no door fact the old wording is unchanged', str_contains((string) (lrgPlaces(['ltype' => 'inn'])['quiet'] ?? ''), 'somewhere quiet nearby'));
$pin('Lisette', 40);
$t = turn('Lisette', snap(['fac' => 'JobBardFaction', 'door' => '1']), 'inputtext', 'we are alone now');
check('the private turn tells her the door is shut', $t['mode'] === 'private'
    && str_contains(lrgVolatileGuidance($t), 'closed room with the door shut'), fx_cut(lrgVolatileGuidance($t)));
$resetMem(); $pin('Lisette', 40);
$t = turn('Lisette', snap(['fac' => 'JobBardFaction', 'door' => '0']), 'inputtext', 'we are alone now');
check('with the door open nothing is claimed about it', !str_contains(lrgVolatileGuidance($t), 'door'), fx_cut(lrgVolatileGuidance($t)));
$resetMem();

echo "34. [0.5.1 / OWNER ADDENDUM 10] followers: the fol= block, the companion words, the hide policy\n";
$resetMem();
// [0.5.1 fix pass] lrgGetNpcState() is memoised per request (the follower round put two more reads on
// EVERY request). The memo is only safe while a write drops it, so that is what is asserted here.
lrgStoreNpcState('Memo Test', snap(['rank' => '1']));
$memoA = lrgGetNpcState('Memo Test');
$memoB = lrgGetNpcState('Memo Test');
lrgStoreNpcState('Memo Test', snap(['rank' => '4']));
$memoC = lrgGetNpcState('Memo Test');
check('the snapshot memo returns the same facts twice and is dropped the moment a new snapshot is stored',
    ($memoA['rank'] ?? '') === '1' && ($memoB['rank'] ?? '') === '1' && ($memoC['rank'] ?? '') === '4',
    json_encode([$memoA['rank'] ?? null, $memoB['rank'] ?? null, $memoC['rank'] ?? null]));
// --- (a) reading the block. An ABSENT fol= must stay "say nothing" in every direction.
check('an absent fol= parses to nothing at all', lrgFolState(['wit' => '0']) === [] && lrgFolState(null) === []);
check('a fol= without fw is refused (a truncated wire value is not a half-fact)',
    lrgFolState(['fol' => 'mate:1,cff:1']) === [], json_encode(lrgFolState(['fol' => 'mate:1,cff:1'])));
$fsff = lrgFolState(['fol' => 'fw:sff,mate:1,cff:1,pff:0,wait:0,chim:0,ghost:0,slot:2/4,cap:1,prim:1']);
check('a real SFF follower parses whole, slot included',
    ($fsff['fw'] ?? '') === 'sff' && (int) $fsff['_used'] === 2 && (int) $fsff['_max'] === 4
    && (int) $fsff['cap'] === 1 && (int) $fsff['prim'] === 1, json_encode($fsff));
$fghost = lrgFolState(['fol' => 'fw:chim,mate:0,cff:1,pff:0,wait:0,chim:0,ghost:1']);
check('the ghost - in the follower faction, not a teammate - is recognised as such',
    (int) $fghost['ghost'] === 1 && ($fghost['fw'] ?? '') === 'chim', json_encode($fghost));
// --- (a2) [0.5.4 / pt13] the game retires fol= for the session on a (false) snapshot abort alarm: the
// last value the SAME session sent keeps counting for followers.remember_seconds, and nothing else does.
$folSff = 'fw:sff,mate:1,cff:1,pff:0,wait:0,chim:0,ghost:0,slot:1/4,cap:1,prim:1';
lrgStoreNpcState('Fol Memory', snap(['sess' => '6083', 'fol' => $folSff]));
lrgStoreNpcState('Fol Memory', snap(['sess' => '6083']));
$fm = lrgFolState(lrgGetNpcState('Fol Memory'));
check('a fol= the game stopped sending mid-session is remembered, and marked as remembered',
    ($fm['fw'] ?? '') === 'sff' && !empty($fm['_remembered']), json_encode($fm));
lrgStoreNpcState('Fol Memory', snap(['sess' => '6083']));
check('... through any number of snapshots without it (the stamp is carried, not renewed)',
    (lrgFolState(lrgGetNpcState('Fol Memory'))['fw'] ?? '') === 'sff');
$GLOBALS['LRG_TEST_NOW'] = lrgNow() + (int) lrgFollowerCfg('remember_seconds', 900) + 5;
check('... for followers.remember_seconds, then it is forgotten', lrgFolState(lrgGetNpcState('Fol Memory')) === []);
unset($GLOBALS['LRG_TEST_NOW']);
lrgStoreNpcState('Fol Memory', snap(['sess' => '6083', 'fol' => $folSff]));
lrgStoreNpcState('Fol Memory', snap(['sess' => '7001']));
check('a reload (new session tag) never inherits the facts of another save', lrgFolState(lrgGetNpcState('Fol Memory')) === []);
lrgStoreNpcState('Fol Memory', snap(['fol' => $folSff]));
lrgStoreNpcState('Fol Memory', snap([]));
check('without a session tag at all (game script 200, the fixtures) nothing is remembered', lrgFolState(lrgGetNpcState('Fol Memory')) === []);
lrgStoreNpcState('Fol Memory', snap(['sess' => '6083', '_fol_last' => $folSff, '_fol_sess' => '6083', '_fol_at' => (string) lrgNow()]));
check('the memory keys are never taken from the wire', lrgFolState(lrgGetNpcState('Fol Memory')) === []);
$blkMem = lrgFollowerBlock('Lydia', ['_remembered' => 1] + lrgFolState(['fol' => $folSff]));
check('a remembered block still says who she is, but not what she is doing this minute',
    str_contains($blkMem, 'closest companion') && !str_contains($blkMem, 'Right now'), fx_cut($blkMem));

// --- (b) the words. Facts only, and never for somebody who is not a companion at all.
$GLOBALS['PLAYER_NAME'] = 'Jordan';
check('a stranger gets no companion block at all', lrgFollowerBlock('Hulda', lrgFolState(['fol' => 'fw:none,mate:0,cff:-1,pff:-1,wait:0,chim:0,ghost:0'])) === '');
$blk = lrgFollowerBlock('Lydia', $fsff);
check('a real follower is described as one, with her role and the slot count',
    str_contains($blk, '<companion_status>') && str_contains($blk, 'closest companion')
    && str_contains($blk, '2 of 4') && str_contains($blk, 'following him'), fx_cut($blk));
check('... and the commands are named as HER dialogue, not the model\'s to narrate',
    str_contains($blk, 'part ways') && str_contains($blk, 'never say that one of them has happened'), fx_cut($blk));
$blkWait = lrgFollowerBlock('Lydia', lrgFolState(['fol' => 'fw:sff,mate:1,cff:1,pff:0,wait:1,chim:0,ghost:0,slot:1/4,cap:0,prim:1']));
check('waiting is reported as waiting, and a full party is stated as a fact',
    str_contains($blkWait, 'waiting where he left her') && str_contains($blkWait, 'cannot take anyone else'), fx_cut($blkWait));
$blkGhost = lrgFollowerBlock('Lisette', $fghost);
check('a half-recruited NPC is NOT called a companion - nothing was settled',
    str_contains($blkGhost, 'nothing has been settled') && !str_contains($blkGhost, 'travels with Jordan as'), fx_cut($blkGhost));
// [0.5.1 fix pass] the cap used to be a raw substr over the FINISHED string, so a long NPC name plus a
// long player name (it appears three times in the block) cut the closing tag off and the model was
// handed "...at the moment.\n</compan". The cap now applies to the body and the tags are re-appended.
$longPlayer = $GLOBALS['PLAYER_NAME'];
$GLOBALS['PLAYER_NAME'] = 'Thane Ysolda-Slayer of Alduin';
$blkLong = lrgFollowerBlock('Aela the Huntress of Jorrvaskr',
    lrgFolState(['fol' => 'fw:sff,mate:1,cff:1,pff:0,wait:0,chim:0,ghost:0,slot:4/4,cap:0,prim:0']));
check('a long NPC name and a long player name still yield a CLOSED <companion_status>',
    str_starts_with($blkLong, '<companion_status>') && str_ends_with($blkLong, "\n</companion_status>")
    && strlen($blkLong) <= (int) lrgFollowerCfg('max_chars', 520), strlen($blkLong) . ': ' . fx_cut($blkLong));
$GLOBALS['PLAYER_NAME'] = $longPlayer;
check('... and an ordinary pair of names is nowhere near the cap, so nothing is dropped',
    str_ends_with($blk, "\n</companion_status>") && str_contains($blk, '2 of 4'), fx_cut($blk));

// --- (c) the hide policy. It only ever REMOVES, and only on a fact the game sent this moment.
$folPolicy = function (array $state, array $offered) {
    $GLOBALS['HERIKA_NAME'] = 'Lisette';
    $GLOBALS['LRG_TEST_NPCSTATE'] = ['Lisette' => $state + ['_age' => 1]];
    $GLOBALS['ENABLED_FUNCTIONS'] = $offered;
    lrgFollowerPolicy();
    $out = array_values((array) $GLOBALS['ENABLED_FUNCTIONS']);
    unset($GLOBALS['LRG_TEST_NPCSTATE']);
    return $out;
};
$base = ['Talk', 'MakeFollower', 'Follow', 'FollowPlayer'];
check('with no fol= on the snapshot nothing is taken away',
    $folPolicy(['wit' => '0'], $base) === $base, implode(',', $folPolicy(['wit' => '0'], $base)));
$r = $folPolicy(['fol' => 'fw:sff,mate:1,cff:1,pff:0,wait:0,chim:0,ghost:0,slot:1/4,cap:1,prim:1'], $base);
check('[pt17] an NPC who already travels with him is offered neither "join my party" nor any of CHIM\'s own moves - her framework moves her',
    !in_array('MakeFollower', $r, true) && !in_array('FollowPlayer', $r, true) && !in_array('Follow', $r, true) && in_array('Talk', $r, true), implode(',', $r));
$r = $folPolicy(['fol' => 'fw:chim,mate:0,cff:-1,pff:-1,wait:0,chim:1,ghost:0,slot:0/1,cap:1'], $base);
check('[pt17] CHIM\'s own follow already on her: FollowPlayer is withheld (a second one only makes her stand, then sprint), the rest stays',
    !in_array('FollowPlayer', $r, true) && in_array('Follow', $r, true) && in_array('MakeFollower', $r, true), implode(',', $r));
$r = $folPolicy(['fol' => 'fw:none,mate:0,cff:-1,pff:-1,wait:0,chim:0,ghost:0,slot:4/4,cap:0'], $base);
check('the framework\'s own cap (SFF_CanRecruitMore=0) takes "join my party" off the table',
    !in_array('MakeFollower', $r, true) && in_array('Follow', $r, true), implode(',', $r));
$r = $folPolicy(['fol' => 'fw:chim,mate:0,cff:1,pff:0,wait:0,chim:0,ghost:1,slot:1/4,cap:1'], $base);
check('while she is half-recruited, none of the three that could make it worse are offered',
    !in_array('MakeFollower', $r, true) && !in_array('Follow', $r, true)
    && !in_array('FollowPlayer', $r, true) && in_array('Talk', $r, true), implode(',', $r));
$r = $folPolicy(['fol' => 'fw:none,mate:0,cff:-1,pff:-1,wait:0,chim:0,ghost:0,slot:1/4,cap:1'], $base);
check('an ordinary stranger with a free slot keeps every CHIM action', $r === $base, implode(',', $r));
$r = $folPolicy(['fol' => 'fw:sff,mate:1,cff:1,pff:0,wait:0,chim:0,ghost:0,slot:1/4,cap:1', '_age' => 9999], $base);
check('a stale snapshot is not a fact: nothing is hidden on it', $r === $base, implode(',', $r));

// --- (d) the privacy gate counts the player's OWN people, however they were recruited.
// A jarl is one of the four shipped profiles with followers_ok:false, which is the only case where
// a companion in the room is a reason at all.
$resetMem();
$pin('Idgrod', 90);
lrgStoreNpcState('Idgrod', snap(['fac' => 'JobJarlFaction', 'witchim' => '1']));
$g = lrgEvaluateGates('Idgrod', 'inputtext');
check('a CHIM-made companion in the room counts as a companion, not as a stranger',
    in_array('companion_present', $g['reasons'], true), implode(',', $g['reasons']));
lrgStoreNpcState('Idgrod', snap(['fac' => 'JobJarlFaction']));
$g = lrgEvaluateGates('Idgrod', 'inputtext');
check('... and an absent witchim (an older game script) adds nothing',
    !in_array('companion_present', $g['reasons'], true), implode(',', $g['reasons']));
lrgStoreNpcState('Idgrod', snap(['fac' => 'JobJarlFaction', 'witfol' => '1']));
$g = lrgEvaluateGates('Idgrod', 'inputtext');
check('... and a real teammate still counts exactly as it did',
    in_array('companion_present', $g['reasons'], true), implode(',', $g['reasons']));
$resetMem();

// --- (e) "follow me somewhere private" must never recruit anybody (pt9 build brief item 4)
$leadSrc = (string) file_get_contents(LRG_DIR . '/lib/lrg_actions.php');
check('the invitation\'s optional lead-the-way line uses only a package move, never a recruitment',
    preg_match('/function lrgLeadTheWayLine.*?\n}/s', $leadSrc, $lm) === 1
    && !str_contains($lm[0], 'MakeFollower') && !str_contains($lm[0], 'SetPlayerTeammate')
    && str_contains($lm[0], 'FollowPlayer@'), fx_cut($lm[0] ?? '(not found)'));
$ostimFile = dirname(__DIR__) . '/game/LoreRimGlue/Source/Scripts/LRG_OStim.psc';
$ostimSrc = is_file($ostimFile) ? (string) file_get_contents($ostimFile) : '';
$folWrite = $ostimSrc !== '' && preg_match('/SetPlayerTeammate|MakeFollower|SetFollower|CurrentFollowerFaction|WaitingForPlayer/', $ostimSrc, $om);
check('and the intimacy module itself touches no follower faction, flag or framework',
    $ostimSrc !== '' && !$folWrite, $ostimSrc === '' ? 'LRG_OStim.psc not found' : ($folWrite ? 'found: ' . $om[0] : ''));

echo "35. [0.5.5 / owner addendum 11] never silent: the reasons, the directives, CHIM's own movement\n";
$GLOBALS['PLAYER_NAME'] = 'Jordan';
$resetMem();
// (1) the game's reasons, as a person puts them - never the game, never an error
$vf = fn(string $code, string $do, string $reason, array $kv = []) => lrgVoicedFact(['npc' => 'Lisette', 'code' => $code, 'do' => $do, 'reason' => $reason, 'kv' => $kv]);
lrgStoreNpcState('Lisette', snap(['fac' => 'JobBardFaction,BardSingerFaction', 'scene' => '1']));
$f = $vf(LRG_ACT_ESCORT, 'follow', 'Lisette is in the middle of something she cannot leave (not a performance or idle scene she may leave)');
check('escort refused on her stage: she could not come along - in the middle of a performance she cannot just leave',
    str_contains($f, 'Lisette could not come along with') && str_contains($f, 'in the middle of a performance that she cannot just leave'), $f);
$f = $vf(LRG_ACT_ESCORT, 'follow', 'Lisette is in the middle of something she cannot leave (a quest in your journal)');
check('... a quest in his journal: something important she cannot walk away from (no "journal", no "quest")',
    str_contains($f, 'something important that she cannot walk away from') && !preg_match('/journal|quest/i', $f), $f);
$f = $vf(LRG_ACT_ESCORT, 'wait', 'Lisette will not do that now (she is your follower - her own follow and wait apply)');
check('... a framework follower: her own companion orders apply', str_contains($f, 'could not stay behind') && str_contains($f, 'companion orders'), $f);
$f = $vf(LRG_ACT_CONTROL, 'goto', 'that position cannot be reached from here');
check('goto unreachable: the change of position did not happen - that position cannot be reached from here',
    $f === 'the change of position did not happen - that position cannot be reached from here', $f);
$f = $vf(LRG_ACT_CLOTHING, 'undress', 'a companion is present', ['who' => 'player']);
check('clothing refused: whose clothes, and the companion', str_contains($f, "Jordan's clothes did not come off") && str_contains($f, 'companion is right there'), $f);
$f = $vf(LRG_ACT_START, '', 'the player does not have that much gold');
check('a paid start the purse could not cover', str_contains($f, 'nothing began between Lisette and') && str_contains($f, 'cannot pay what was agreed'), $f);
$f = $vf(LRG_ACT_CONTROL, 'faster', 'unknown scene request');
check('a technical reason becomes one neutral phrase, never the code', str_contains($f, 'it just would not work right now') && !str_contains($f, 'unknown'), $f);
// (1b) [game 507] the technical reasons only script 507 sends (err=), and the machinery filter
$f = $vf(LRG_ACT_ESCORT, 'follow', "Lisette cannot follow you: CHIM's follow could not be put on (AIAgent.esp forms not found)");
check('507: a follow the game could not put on - "it just would not work right now", no CHIM, no AIAgent.esp',
    str_contains($f, 'it just would not work right now') && !preg_match('/chim|aiagent|esp/i', $f), $f);
$f = $vf(LRG_ACT_ESCORT, 'follow', 'she went back to her scene (BardSongs) and stays - it keeps starting again');
check('507: the escort\'s late failure - pulled back into her performance, never the EditorID',
    str_contains($f, 'keeps being pulled back into it') && str_contains($f, 'a performance') && !str_contains($f, 'BardSongs'), $f);
$f = $vf(LRG_ACT_CONTROL, 'goto', 'unreachable');
check('507: "unreachable" reads as the closed-list sentence', $f === 'the change of position did not happen - that position cannot be reached from here', $f);
$f = $vf(LRG_ACT_CLOTHING, 'dress', 'not possible in this position', ['who' => 'npc']);
check('507: clothing that changed nothing - there was nothing to put back on', $f === "Lisette's clothes did not go back on - there was nothing to put back on", $f);
$f = lrgVoicedFact(['npc' => 'Lisette', 'code' => LRG_ACT_START, 'do' => '', 'late' => true, 'reason' => 'that position does not exist for the two of you', 'kv' => []]);
check('507: a start\'s late after= failure - the scene began; the change of position did not happen',
    $f === 'the change of position did not happen - that position does not exist for the two of you', $f);
// (1c) [game 507] what lrgRecordResult keeps: the technical reason from err=, her words apart; <= 506 unchanged
$GLOBALS['gameRequest'] = ['funcret', time(), 100, 'command@' . LRG_ACT_CONTROL . '@ok=1;cid=s507a;npc=Lisette;do=goto;scene=X;err=that position cannot be reached from here@Error: I cannot get into that from here'];
lrgRecordResult($GLOBALS['gameRequest'][3]);
$lr = lrgMemGet('Lisette')['last_result'] ?? [];
check('507 result: reason = err= (technical), say = her words, not late',
    ($lr['reason'] ?? '') === 'that position cannot be reached from here' && ($lr['say'] ?? '') === 'I cannot get into that from here' && ($lr['late'] ?? null) === false, json_encode($lr));
lrgRecordResult('command@' . LRG_ACT_CONTROL . '@ok=1;cid=s507b;npc=Lisette;do=faster@OK: The pace changes.');
check('507 result: "OK: ..." is a success', (lrgMemGet('Lisette')['last_result']['ok'] ?? null) === true, json_encode(lrgMemGet('Lisette')['last_result'] ?? null));
lrgRecordResult('command@' . LRG_ACT_CONTROL . '@ok=1;cid=s506a;npc=Lisette;do=goto;scene=X@Error: that position cannot be reached from here');
$lr = lrgMemGet('Lisette')['last_result'] ?? [];
check('<= 506 result (no err=): reason = the Error: text, no say - exactly as before',
    ($lr['reason'] ?? '') === 'that position cannot be reached from here' && ($lr['say'] ?? null) === '', json_encode($lr));
// (1d) [pt17 / script 509] the escort's fallback: an OK funcret with fb=<reason> - "comes along, but not as his sworn companion"
$vp = fn(string $reason) => lrgVoicedFact(['npc' => 'Lisette', 'code' => LRG_ACT_ESCORT, 'do' => 'follow', 'reason' => $reason, 'partial' => true, 'kv' => []]);
$f = $vp('you have no free companion slot (1/1 at Speech 15)');
check('509 fb=: one slot, taken - she comes along, not as his sworn companion; Jordan can lead only one companion (no Speech, no slot, no framework)',
    str_contains($f, 'Lisette comes along with Jordan, but not as his sworn companion') && str_contains($f, 'can lead only one companion at a time')
    && !preg_match('/speech|slot|framework/i', $f), $f);
$f = $vp('you have no free companion slot (2/2 at Speech 30)');
check('509 fb=: two of two - as many companions as he can (2 of 2)', str_contains($f, 'as many companions as he can (2 of 2)'), $f);
$f = $vp('the game does not let you recruit her yet');
check('509 fb=: not recruitable - not somebody who can be taken on as a companion yet, never "the game"',
    str_contains($f, 'not somebody who can be taken on as a companion yet') && !str_contains($f, 'the game'), $f);
$f = $vp('she is a hireling - her price is in her own dialogue');
check('509 fb=: a hireling - her price comes first', str_contains($f, 'her price comes first'), $f);
$f = $vp('the framework did not take her');
check('509 fb=: the framework refused - nothing could make her a real companion, no framework named',
    str_contains($f, 'nothing could make her a real companion') && stripos($f, 'framework') === false, $f);
$f = $vp("she already travels with you as somebody else's companion");
check('509 fb=: somebody else\'s companion - her own companion orders', str_contains($f, 'through her own companion orders'), $f);
$resetMem();
$GLOBALS['PLAYER_NAME'] = 'Jordan';
$fbFr = 'command@' . LRG_ACT_ESCORT . '@ok=1;cid=s509a;npc=Lisette;do=follow;safe=*;fb=you have no free companion slot (1/1 at Speech 15)@OK: Lisette comes with you.';
$GLOBALS['gameRequest'] = ['funcret', time(), 100, $fbFr];
lrgRecordResult($fbFr);
$lr = lrgMemGet('Lisette')['last_result'] ?? [];
check('509 result: an OK with fb= is a success that keeps the reason as partial',
    !empty($lr['ok']) && ($lr['partial'] ?? '') === 'you have no free companion slot (1/1 at Speech 15)', json_encode($lr));
$res = lrgFuncretVerdict($fbFr);
check('509 verdict: the OK with fb= is VOICED (passed to CHIM\'s funcret turn) with the partial fact',
    $res === 'pass' && !empty($GLOBALS['LRG_VOICED']['partial']) && str_contains((string) ($GLOBALS['LRG_VOICED']['fact'] ?? ''), 'not as his sworn companion'),
    $res . ' ' . json_encode($GLOBALS['LRG_VOICED'] ?? null));
check('... and the funcret CHIM carries on with is the Error-shaped fact',
    str_contains((string) $GLOBALS['gameRequest'][3], '@Error: Lisette comes along with Jordan, but not as his sworn companion'), (string) $GLOBALS['gameRequest'][3]);
$d = lrgVoicedDirective((array) $GLOBALS['LRG_VOICED']);
check('... the directive says she IS coming along and must not pretend she has joined him',
    str_contains($d, 'is coming along') && str_contains($d, 'does not pretend she has joined him'), $d);
unset($GLOBALS['LRG_VOICED']);
$fbFr2 = 'command@' . LRG_ACT_ESCORT . '@ok=1;cid=s509b;npc=Lisette;do=follow;safe=*;fb=the game does not let you recruit her yet@OK: Lisette comes with you.';
$GLOBALS['gameRequest'] = ['funcret', time(), 100, $fbFr2];
lrgRecordResult($fbFr2);
$res = lrgFuncretVerdict($fbFr2);
$ms = lrgMemGet('Lisette')['missed'] ?? null;
check('509 verdict inside the voice gap: handled quietly, and remembered as missed for her next turn',
    $res === 'handled' && !isset($GLOBALS['LRG_VOICED']) && is_array($ms) && ($ms['what'] ?? '') === "become Jordan's companion"
    && str_contains((string) ($ms['why'] ?? ''), 'not somebody who can be taken on'), $res . ' ' . json_encode($ms));
$okFr = 'command@' . LRG_ACT_ESCORT . '@ok=1;cid=s509c;npc=Lisette;do=follow;safe=*@OK: Lisette comes with you.';
$GLOBALS['gameRequest'] = ['funcret', time(), 100, $okFr];
lrgRecordResult($okFr);
check('509 result: a plain OK still ends silently, and its record carries no partial at all',
    lrgFuncretVerdict($okFr) === 'handled' && !isset(lrgMemGet('Lisette')['last_result']['partial']), json_encode(lrgMemGet('Lisette')['last_result'] ?? null));
unset($GLOBALS['LRG_FUNCRET']);
$resetMem();
lrgStoreNpcState('Lisette', snap(['fac' => 'JobBardFaction,BardSingerFaction', 'scene' => '1']));
// (2) the directives: every recognised request says "do it, or say why - never ignore it"
$t = turn('Hulda', snap(), 'inputtext', 'take off your clothes', false);
$v = lrgVolatileGuidance($t);
check('closed (a stranger): the undress request is answered in ONE line with the reason, never ignored',
    $t['mode'] === 'closed' && str_contains($v, 'The player just asked for clothes off. It is not happening right now, for the reason above')
    && str_contains($v, 'in one short line, with that reason') && str_contains($v, 'never ignoring it'), fx_cut($v, 900));
$t = turn('Hulda', snap(['rank' => '3', 'wit' => '2']), 'inputtext', 'take off your clothes', false);
$v = lrgVolatileGuidance($t);
check('public (willing, others watching): "not here", with the reason, and where instead - never ignored',
    $t['mode'] === 'public' && str_contains($v, 'Not here - other people are close enough to see or hear') && str_contains($v, 'may name somewhere private')
    && str_contains($v, 'Never ignore it'), fx_cut($v, 900));
$t = turn('Hulda', snap(['rank' => '3']), 'inputtext', 'take off your clothes');
check('private: yes by choosing ChangeClothing, or no WITH the reason - never ignored',
    str_contains(lrgVolatileGuidance($t), 'say no plainly, with the reason, in one short line') && str_contains(lrgVolatileGuidance($t), 'Never ignore it'), fx_cut(lrgVolatileGuidance($t), 900));
// (2b) the sweep: EVERY directive shape of a recognised request says "never ignore it" (the one exception is the
// player's own "no" to her proposal, which is an answer, not a request)
$sweep = [];
$tb = ['npc' => 'Hulda', 'cid' => 'sSWEEP', 'type' => 'inputtext', 'acts' => [], 'ctx' => ['player' => 'Jordan', 'npc' => 'Hulda'],
    'gate' => ['reasons' => ['witnesses'], 'price' => ['for_sale' => true, 'gold' => 100], 'paid' => [], 'state' => ['pgold' => '500']]];
$undress = lrgRecogniseIntent('take your clothes off', null, []);
$shapes = [
    'scene DO-IT' => ['mode' => 'scene', 'scene_confirmed' => true, 'offered' => [LRG_ACT_CONTROL, LRG_ACT_CLOTHING, LRG_ACT_REQUESTACT], 'intent' => $undress],
    'scene MAYBE' => ['mode' => 'scene', 'scene_confirmed' => true, 'offered' => [LRG_ACT_CONTROL, LRG_ACT_CLOTHING], 'intent' => lrgRecogniseIntent('naked', null, [])],
    'scene not offered' => ['mode' => 'scene', 'scene_confirmed' => true, 'offered' => [], 'intent' => $undress],
    'scene CANT' => ['mode' => 'scene', 'scene_confirmed' => true, 'offered' => [LRG_ACT_CONTROL], 'intent' => ['cant' => 'not installed'] + lrgRecogniseIntent('kiss me', null, [])],
    'scene ALREADY' => ['mode' => 'scene', 'scene_confirmed' => true, 'offered' => [LRG_ACT_CONTROL], 'intent' => ['already' => true] + lrgRecogniseIntent('kiss me', null, [])],
    'private OPEN' => ['mode' => 'private', 'offered' => [LRG_ACT_START, LRG_ACT_CLOTHING], 'intent' => $undress],
    'private neutral' => ['mode' => 'private', 'offered' => [], 'intent' => $undress],
    'public' => ['mode' => 'public', 'offered' => [LRG_ACT_INVITE], 'intent' => $undress],
    'closed' => ['mode' => 'closed', 'offered' => [], 'intent' => $undress],
    'blind' => ['mode' => 'silent', 'blind_note' => true, 'offered' => [], 'intent' => $undress],
    'money askprice' => ['mode' => 'private', 'offered' => [LRG_ACT_START], 'intent' => ['kind' => 'askprice', 'conf' => 'high', 'kv' => [], 'gold' => 0]],
    'money haggle' => ['mode' => 'private', 'offered' => [LRG_ACT_START], 'intent' => ['kind' => 'haggle', 'conf' => 'high', 'kv' => [], 'gold' => 0]],
    'money no figure' => ['mode' => 'private', 'offered' => [LRG_ACT_START], 'intent' => ['kind' => 'offer', 'conf' => 'high', 'kv' => [], 'gold' => 0]],
    'money below' => ['mode' => 'private', 'offered' => [LRG_ACT_START], 'intent' => ['kind' => 'offer', 'conf' => 'high', 'kv' => [], 'gold' => 50]],
    'money in a scene' => ['mode' => 'scene', 'offered' => [], 'intent' => ['kind' => 'offer', 'conf' => 'high', 'kv' => [], 'gold' => 50]],
    'money not for sale' => ['mode' => 'private', 'offered' => [], 'gate' => ['reasons' => [], 'price' => ['for_sale' => false]], 'intent' => ['kind' => 'offer', 'conf' => 'high', 'kv' => [], 'gold' => 50]],
    'money free' => ['mode' => 'private', 'offered' => [], 'gate' => ['reasons' => [], 'price' => ['for_sale' => true, 'free' => true]], 'intent' => ['kind' => 'offer', 'conf' => 'high', 'kv' => [], 'gold' => 50]],
];
foreach ($shapes as $label => $over) {
    $turnS = $over + $tb;
    if (in_array($turnS['mode'], ['scene', 'public', 'private'], true) && ($turnS['intent']['kind'] ?? '') !== 'none') {
        $turnS['intent']['words'] = lrgIntentWords($turnS['intent'], $turnS['ctx']);
    }
    $d = lrgBuildRequestDirective($turnS);
    if ($d === '' || !str_contains(strtolower($d), 'ignor')) { $sweep[] = "$label: " . ($d === '' ? '(no directive)' : fx_cut($d, 160)); }
}
check('every directive shape of a recognised request (scene, open, neutral, public, closed, blind, money) says never ignore it',
    $sweep === [], implode(' | ', $sweep));
// (3) CHIM's own movement: the next snapshots judge it, the next player turn hears about it
$resetMem();
$GLOBALS['LRG_TEST_NOW'] = time();
$t = turn('Hulda', snap(['dist' => '900']), 'inputtext', 'come here', false);
lrgPostProcessActions(["Hulda|command|ComeCloser@Player\r\n"]);
$mw = lrgMemGet('Hulda')['move_watch'] ?? null;
check('ComeCloser from the reply is watched, with the distance she was told at', is_array($mw) && ($mw['code'] ?? '') === 'ComeCloser' && ($mw['dist0'] ?? null) === 900, json_encode($mw));
lrgStoreNpcState('Hulda', snap(['dist' => '880']));
check('... a snapshot inside watch.min_seconds judges nothing yet', is_array(lrgMemGet('Hulda')['move_watch'] ?? null) && !isset(lrgMemGet('Hulda')['missed']));
$GLOBALS['LRG_TEST_NOW'] += 8;
lrgStoreNpcState('Hulda', snap(['dist' => '880', 'scene' => '1']));
$ms = lrgMemGet('Hulda')['missed'] ?? null;
check('... 8 s later she is still in an engine scene: missed, and the watch is over',
    is_array($ms) && ($ms['code'] ?? '') === 'ComeCloser' && str_contains((string) ($ms['what'] ?? ''), 'come closer') && !isset(lrgMemGet('Hulda')['move_watch']), json_encode($ms));
$t = turn('Hulda', snap(['dist' => '880']), 'inputtext', 'hello?', false);
$v = lrgVolatileGuidance($t);
check('her next player turn is told: she did not manage to come closer, and says why if he asks',
    str_contains($v, 'did not manage to come closer') && str_contains($v, 'says why in one short line') && str_contains(lrgTurnLogLine($t), 'missed=ComeCloser'), fx_cut($v, 900));
$t = turn('Hulda', snap(['dist' => '880']), 'inputtext', 'hello?', false);
check('... once', !str_contains(lrgVolatileGuidance($t), 'did not manage'));
$t = turn('Hulda', snap(['dist' => '900']), 'inputtext', 'come here', false);
lrgPostProcessActions(["Hulda|command|ComeCloser@Player\r\n"]);
$GLOBALS['LRG_TEST_NOW'] += 8;
lrgStoreNpcState('Hulda', snap(['dist' => '180']));
check('she came: the watch ends in silence, nothing is missed', !isset(lrgMemGet('Hulda')['move_watch']) && !isset(lrgMemGet('Hulda')['missed']));
$t = turn('Hulda', snap(['dist' => '900']), 'inputtext', 'come here', false);
lrgPostProcessActions(["Hulda|command|ComeCloser@Player\r\n"]);
$GLOBALS['LRG_TEST_NOW'] += 8;
lrgStoreNpcState('Hulda', snap(['dist' => '870']));
check('she did not move at all (still far, not closer): missed', (lrgMemGet('Hulda')['missed']['why'] ?? '') === 'did not move at all', json_encode(lrgMemGet('Hulda')['missed'] ?? null));
lrgMemSet('Hulda', ['missed' => null]);
$t = turn('Hulda', snap(['dist' => '900']), 'inputtext', 'go to the market', false);
lrgPostProcessActions(["Hulda|command|TravelTo@Whiterun Market\r\n"]);
$GLOBALS['LRG_TEST_NOW'] += 40;
lrgStoreNpcState('Hulda', snap(['dist' => '900', 'scene' => '1']));
check('no evidence within watch.max_seconds: the watch ends and nothing is said', !isset(lrgMemGet('Hulda')['move_watch']) && !isset(lrgMemGet('Hulda')['missed']));
unset($GLOBALS['LRG_TEST_NOW']);
// the escort's LATE failure: the funcret said "comes with you", then her quest started the scene again
$resetMem();
lrgHandleGameMessage(['lrg_log', time(), 100, 'cid=sE1;msg=escort Lisette: she went back to her scene (BardSongs) and stays - it keeps starting again']);
$ms = lrgMemGet('Lisette')['missed'] ?? null;
check('the escort\'s late failure (game log line) is remembered for her next turn',
    is_array($ms) && str_contains((string) ($ms['what'] ?? ''), 'come along') && str_contains((string) ($ms['why'] ?? ''), 'keeps being pulled back'), json_encode($ms));

/**
 * ------------------------------------------------------------------ 36. [pt17-replies] NEVER EMPTY
 * Captain Aldis, 2026-09-23 20:10:31: "it says it's locked" got a schema-valid JSON with message "" and nothing was
 * spoken (audit request 850; 17 of 322 replies since the log began). lib/lrg_replies.php: the validator rejects a
 * COMPLETE empty reply on one of our turns, the retry re-asks once and then speaks one floor line through CHIM's own
 * returnLines() (the LRG_TEST_SAY seam here), the hook floors what the validator lets through on purpose, and the
 * next turn carries one rule. Every line below that reaches the player is a CONFIG line, never a prompt line.
 */
echo "36. [pt17-replies] never empty: the validator, the re-ask, the floor line, the hook, the note\n";
require_once __DIR__ . '/../server/lorerim_glue/lib/lrg_replies.php';
$neLogFile = dirname(LRG_DIR, 2) . '/log/lorerim_glue.log';
$neLogAt = static function () use ($neLogFile): int { clearstatcache(); return is_file($neLogFile) ? (int) filesize($neLogFile) : 0; };
$neLogSince = static function (int $at) use ($neLogFile): string { clearstatcache(); return is_file($neLogFile) ? (string) substr((string) file_get_contents($neLogFile), $at) : ''; };
$neReset = static function (): void {
    unset($GLOBALS['LRG_NE_REJECT'], $GLOBALS['LRG_NE_DONE'], $GLOBALS['LRG_NE_REASKED'], $GLOBALS['LRG_NE_SEEN'], $GLOBALS['LRG_TEST_SAY'],
        $GLOBALS['LRG_TEST_REASK'], $GLOBALS['LAST_LLM_RESPONSE'], $GLOBALS['LRG_NE_TEST_OVERRIDE'], $GLOBALS['LRG_DLG_TURN'], $GLOBALS['contextData'],
        $GLOBALS['IN_FALLBACK_MODE'], $GLOBALS['LRG_VOICED']);
    $GLOBALS['talkedSoFar'] = [];
    lrgNeCfg();
};
$neSchema = static function (bool $actionFirst = true): void {
    $keys = $actionFirst ? ['action', 'target', 'item', 'character', 'listener', 'message', 'mood', 'amount', 'lang', 'emotion', 'emotion_intensity']
        : ['character', 'listener', 'message', 'mood', 'action', 'target', 'item', 'amount'];
    $props = [];
    foreach ($keys as $k) { $props[$k] = ['type' => $k === 'amount' ? 'integer' : 'string', 'description' => $k]; }
    $GLOBALS['structuredOutputTemplate'] = ['type' => 'json_schema', 'json_schema' => ['name' => 'response', 'strict' => true,
        'schema' => ['type' => 'object', 'properties' => $props, 'required' => $keys, 'additionalProperties' => false]]];
};
$neReply = static function (string $message, string $action = 'Talk', string $item = '', array $drop = [], bool $messageLast = false): array {
    $r = ['action' => $action, 'target' => 'Testplayer', 'item' => $item, 'character' => 'Hulda', 'listener' => 'Testplayer', 'message' => $message,
        'mood' => 'assertive', 'amount' => 0, 'lang' => 'en', 'emotion' => 'calm', 'emotion_intensity' => 'moderate'];
    foreach ($drop as $d) { unset($r[$d]); }
    if ($messageLast) { $m = $r['message']; unset($r['message']); $r['message'] = $m; }
    return $r;
};
$neMem = static fn(): array => (array) (lrgMemGet('Hulda')['empty_reply'] ?? []);
$neResetMem = static function (): void { lrgMemSet('Hulda', ['empty_reply' => null]); };
// --- (a) registration: the three seams, chained, and the hook LAST
$neReset();
unset($GLOBALS['VALIDATE_LLM_OUTPUT_FNCT'], $GLOBALS['LLM_RETRY_FNCT'], $GLOBALS['LRG_NE_PREV_VALIDATOR'], $GLOBALS['LRG_NE_PREV_RETRY']);
$g1 = static fn($a) => $a; $g2 = static fn($a) => $a;
$GLOBALS['action_post_process_fnct_ex'] = [$g1, $g2];
lrgNeRegister(); lrgNeRegister();
check('(a) VALIDATE_LLM_OUTPUT_FNCT and LLM_RETRY_FNCT are set (main.php:2825 had nobody using the retry seam)',
    is_callable($GLOBALS['VALIDATE_LLM_OUTPUT_FNCT'] ?? null) && is_callable($GLOBALS['LLM_RETRY_FNCT'] ?? null));
check('(a) the hook is appended once and LAST, after the two post-gates', count($GLOBALS['action_post_process_fnct_ex']) === 3
    && $GLOBALS['action_post_process_fnct_ex'][2] === $GLOBALS['LRG_NE_HOOK'] && $GLOBALS['action_post_process_fnct_ex'][0] === $g1);
$prevV = static fn($c) => false;
$GLOBALS['VALIDATE_LLM_OUTPUT_FNCT'] = $prevV;
lrgNeRegister();
check('(a) another plugin\'s validator is CHAINED, not replaced (a false from it still rejects)', $GLOBALS['VALIDATE_LLM_OUTPUT_FNCT'] === $GLOBALS['LRG_NE_VALIDATOR']
    && ($GLOBALS['LRG_NE_PREV_VALIDATOR'] ?? null) === $prevV && $GLOBALS['LRG_NE_VALIDATOR']('x') === false);
unset($GLOBALS['LRG_NE_PREV_VALIDATOR']);
check('(a) config defaults: on, re-ask on, scene off, 120 s note, three line lists, every line under max_chars',
    !empty(lrgNeCfg('enabled')) && !empty(lrgNeCfg('reask')) && empty(lrgNeCfg('in_scene')) && (int) lrgNeCfg('note_seconds') === 120
    && count((array) lrgNeCfg('lines.question')) >= 2 && count((array) lrgNeCfg('lines.statement')) >= 2 && count((array) lrgNeCfg('lines.trouble')) >= 1
    && max(array_map('strlen', array_merge((array) lrgNeCfg('lines.question'), (array) lrgNeCfg('lines.statement'), (array) lrgNeCfg('lines.trouble')))) <= (int) lrgNeCfg('max_chars'));
check('(a) the shipped config carries the never_empty block (lrg_config.default.json)', is_array(lrgConfig()['never_empty'] ?? null) && isset(lrgConfig()['never_empty']['lines']['trouble']));
// --- (b) the validator: only a COMPLETE empty reply on one of our turns
$neReset(); $neResetMem();
turn('Hulda', snap(), 'inputtext', 'is the door locked?');
$neSchema(true);
$GLOBALS['LAST_LLM_RESPONSE'] = $neReply('');
$at = $neLogAt();
$v = $GLOBALS['LRG_NE_VALIDATOR']('');
check('(b) a COMPLETE reply with message "" on a player speech turn is REJECTED', $v === false && (($GLOBALS['LRG_NE_REJECT']['why'] ?? '') === 'empty message'), json_encode($GLOBALS['LRG_NE_REJECT'] ?? null));
check('(b) ...and logged as never-empty ... verdict=rejected', str_contains($neLogSince($at), 'never-empty npc=Hulda action=Talk item="" verdict=rejected'), fx_cut($neLogSince($at)));
$neReset();
$GLOBALS['LAST_LLM_RESPONSE'] = $neReply('', 'Talk', '', ['emotion_intensity']);
check('(b) the same object WITHOUT the schema\'s last key is mid-stream: no verdict (true, no reject)', $GLOBALS['LRG_NE_VALIDATOR']('') === true && !isset($GLOBALS['LRG_NE_REJECT']));
$GLOBALS['LAST_LLM_RESPONSE'] = $neReply('', 'Talk', '', ['mood']);
check('(b) ...a required key missing: no verdict either', $GLOBALS['LRG_NE_VALIDATOR']('') === true && !isset($GLOBALS['LRG_NE_REJECT']));
$GLOBALS['LAST_LLM_RESPONSE'] = $neReply('', 'Talk', '', [], true);
check('(b) ...message written LAST by the model: no verdict (the hook judges it when the stream has ended)', $GLOBALS['LRG_NE_VALIDATOR']('') === true && !isset($GLOBALS['LRG_NE_REJECT']));
$GLOBALS['LAST_LLM_RESPONSE'] = $neReply('Aye, it is.');
check('(b) a reply with words passes', $GLOBALS['LRG_NE_VALIDATOR']('Aye') === true && !isset($GLOBALS['LRG_NE_REJECT']));
$GLOBALS['LAST_LLM_RESPONSE'] = $neReply('   ');
check('(b) whitespace-only is empty', $GLOBALS['LRG_NE_VALIDATOR']('') === false);
$neReset();
$neSchema(false);
$GLOBALS['LAST_LLM_RESPONSE'] = ['character' => 'Hulda', 'listener' => 'Testplayer', 'message' => '', 'mood' => 'calm', 'action' => 'Talk', 'target' => '', 'item' => '', 'amount' => 0];
check('(b) the character-first order (no reorder) is judged the same way', $GLOBALS['LRG_NE_VALIDATOR']('') === false);
$neReset();
unset($GLOBALS['structuredOutputTemplate']);
$GLOBALS['responseTemplate'] = ['character' => 'x', 'listener' => 'x', 'message' => 'lines of dialogue', 'mood' => 'x', 'action' => 'x', 'target' => 'x', 'item' => 'x', 'amount' => 0];
$GLOBALS['LAST_LLM_RESPONSE'] = ['character' => 'Hulda', 'listener' => 'Testplayer', 'message' => '', 'mood' => 'calm', 'action' => 'Talk', 'target' => '', 'item' => '', 'amount' => 0];
check('(b) json_object mode (no schema): the text template\'s keys decide completeness', $GLOBALS['LRG_NE_VALIDATOR']('') === false);
$neReset();
unset($GLOBALS['structuredOutputTemplate'], $GLOBALS['responseTemplate']);
$GLOBALS['LAST_LLM_RESPONSE'] = $neReply('');
check('(b) no schema and no template at all: never a verdict', $GLOBALS['LRG_NE_VALIDATOR']('') === true);
$neSchema(true);
// --- (c) the retry: the re-ask speaks in her own words - no floor line
$neReset(); $neResetMem();
turn('Hulda', snap(), 'inputtext', 'is the door locked?');
$GLOBALS['LAST_LLM_RESPONSE'] = $neReply('');
$GLOBALS['LRG_NE_VALIDATOR']('');
$GLOBALS['contextData'] = [['role' => 'system', 'content' => 'sys'], ['role' => 'user', 'content' => "Write Hulda's next dialogue line."]];
$seenCtx = ''; $seenFallback = null;
$GLOBALS['LRG_TEST_REASK'] = static function (string $nudge) use (&$seenCtx, &$seenFallback): bool {
    $seenCtx = (string) $GLOBALS['contextData'][1]['content']; $seenFallback = $GLOBALS['IN_FALLBACK_MODE'] ?? null;
    $GLOBALS['talkedSoFar'][] = 'Locked since the fire, aye.';
    return true;
};
$at = $neLogAt();
$GLOBALS['LRG_NE_RETRY']();
check('(c) the re-ask ran with the words rule appended to the LAST USER message, under IN_FALLBACK_MODE (no second player TTS)',
    str_contains($seenCtx, "Write Hulda's next dialogue line.\n(Rule for this reply: Hulda answers in words") && $seenFallback === true, $seenCtx);
check('(c) ...the rule is a rule, not an example line, and it is restored afterwards',
    !str_contains($seenCtx, '"') && (string) $GLOBALS['contextData'][1]['content'] === "Write Hulda's next dialogue line." && !isset($GLOBALS['IN_FALLBACK_MODE']));
check('(c) her own words won: NO floor line', ($GLOBALS['LRG_TEST_SAY'] ?? []) === [] && lrgSpokenThisTurn() === 'Locked since the fire, aye.');
check('(c) ...logged as reask=spoke and counted', str_contains($neLogSince($at), 'why=empty message reask=spoke chars=') && (int) ($neMem()['n'] ?? 0) === 1 && ($neMem()['kind'] ?? '') === 'reask', fx_cut($neLogSince($at)));
// --- (d) the re-ask comes back empty too: exactly ONE floor line, a question line for a question
$neReset(); $neResetMem();
turn('Hulda', snap(), 'inputtext', 'is the door locked?');
$GLOBALS['LAST_LLM_RESPONSE'] = $neReply('');
$GLOBALS['LRG_NE_VALIDATOR']('');
$calls = 0;
$GLOBALS['LRG_TEST_REASK'] = static function (string $nudge) use (&$calls): bool { $calls++; $GLOBALS['LRG_NE_REJECT'] = ['why' => 'empty message', 'action' => 'Talk', 'item' => '']; return false; };
$at = $neLogAt();
$GLOBALS['LRG_NE_RETRY']();
$said = $GLOBALS['LRG_TEST_SAY'] ?? [];
check('(d) exactly one floor line, and it is one of the configured QUESTION lines (his words ended in ?)',
    count($said) === 1 && in_array($said[0], (array) lrgNeCfg('lines.question'), true), json_encode($said));
check('(d) ...she asked once, not twice', $calls === 1);
check('(d) ...talkedSoFar carries it (lrgSpokenThisTurn sees a spoken turn)', lrgSpokenThisTurn() === $said[0]);
check('(d) ...logged: why=empty message twice said="<line>"', str_contains($neLogSince($at), 'why=empty message twice said="' . $said[0] . '"'), fx_cut($neLogSince($at)));
check('(d) ...remembered: lrg_memory.empty_reply {at, cid, n=1, line, told=false}', (int) ($neMem()['n'] ?? 0) === 1 && ($neMem()['line'] ?? '') === $said[0] && ($neMem()['told'] ?? null) === false, json_encode($neMem()));
$first = $said[0];
// --- (e) a statement gets a statement line; two consecutive empties get two different lines
$neReset();
turn('Hulda', snap(), 'inputtext', 'it says it is locked');
$GLOBALS['LAST_LLM_RESPONSE'] = $neReply('');
$GLOBALS['LRG_NE_VALIDATOR']('');
$GLOBALS['LRG_TEST_REASK'] = static function (string $nudge): bool { $GLOBALS['LRG_NE_REJECT'] = ['why' => 'empty message', 'action' => 'Talk', 'item' => '']; return false; };
$GLOBALS['LRG_NE_RETRY']();
$s2 = $GLOBALS['LRG_TEST_SAY'][0] ?? '';
check('(e) a statement gets one of the STATEMENT lines', in_array($s2, (array) lrgNeCfg('lines.statement'), true), $s2);
$neReset();
turn('Hulda', snap(), 'inputtext', 'still locked');
$GLOBALS['LAST_LLM_RESPONSE'] = $neReply('');
$GLOBALS['LRG_NE_VALIDATOR']('');
$GLOBALS['LRG_TEST_REASK'] = static function (string $nudge): bool { $GLOBALS['LRG_NE_REJECT'] = ['why' => 'empty message', 'action' => 'Talk', 'item' => '']; return false; };
$GLOBALS['LRG_NE_RETRY']();
$s3 = $GLOBALS['LRG_TEST_SAY'][0] ?? '';
check('(e) the next empty reply gets a DIFFERENT line (rotated per NPC)', $s3 !== '' && $s3 !== $s2 && (int) ($neMem()['n'] ?? 0) === 3, "$s2 | $s3");
// --- (f) re-ask OFF: an actionless empty reply is floored straight away; an action-bearing one keeps its action
$neReset(); $neResetMem();
$GLOBALS['LRG_NE_TEST_OVERRIDE'] = ['reask' => false];
turn('Hulda', snap(), 'inputtext', 'follow me');
$GLOBALS['LAST_LLM_RESPONSE'] = $neReply('');
$GLOBALS['LRG_NE_VALIDATOR']('');
$calls = 0;
$GLOBALS['LRG_TEST_REASK'] = static function (string $nudge) use (&$calls): bool { $calls++; return true; };
$GLOBALS['LRG_NE_RETRY']();
check('(f) reask=false: Talk + "" is rejected and floored at once - no second call', $calls === 0 && count($GLOBALS['LRG_TEST_SAY'] ?? []) === 1, json_encode($GLOBALS['LRG_TEST_SAY'] ?? []));
$neReset();
$GLOBALS['LRG_NE_TEST_OVERRIDE'] = ['reask' => false];
$GLOBALS['LAST_LLM_RESPONSE'] = $neReply('', 'Follow_Testplayer');
$at = $neLogAt();
check('(f) reask=false: Follow + "" is LET THROUGH by the validator (a rejection would throw the action away)', $GLOBALS['LRG_NE_VALIDATOR']('') === true && !isset($GLOBALS['LRG_NE_REJECT'])
    && str_contains($neLogSince($at), 'verdict=let-through (an action to keep'), fx_cut($neLogSince($at)));
$out = $GLOBALS['LRG_NE_HOOK'](["Hulda|command|Follow_Testplayer@\r\n"]);
check('(f) ...and the hook floors it: the action goes out UNCHANGED and one line is spoken before it', $out === ["Hulda|command|Follow_Testplayer@\r\n"] && count($GLOBALS['LRG_TEST_SAY'] ?? []) === 1
    && str_contains($neLogSince($at), 'action=Follow_Testplayer why=empty message said="'), json_encode([$out, $GLOBALS['LRG_TEST_SAY'] ?? []]));
$neReset();
// --- (g) a business pick the game answers itself: the mute would drop any line - the rail stays out
$neReset(); $neResetMem();
turn('Hulda', snap(), 'inputtext', 'I need work');
// [pt19 v1.0 / S1.3, Lane A hand-off] lrgDlgWillEmit is the gate's exact twin now, so the turn is built through the REAL path: a
// driven session with the line on his screen and a verified click on this install - the gate itself would emit this pick
$GLOBALS['LRG_DLG_STATE'] = [];
lrgDlgPut('*install*', ['clicks_ok' => 1]);
$GLOBALS['HERIKA_NAME'] = 'Hulda';
foreach ([['lrg_dlg', 'ev=open;npc=Hulda;sid=g1;origin=glue;crit=0;scene=0'],
    ['lrg_topics', 'v=1;sid=g1;gen=1;layer=1;origin=glue;npc=Hulda;n=1;part=1;cid=gw;want=0;ask=;crit=0;scene=0;pg=300;q=;e=0~100~0~-~I need work.']] as [$gty, $gpl]) {
    $GLOBALS['gameRequest'] = [$gty, lrgNow(), 100, $gpl];
    ob_start(); lrgDlgHandleGameMessage($GLOBALS['gameRequest']); ob_end_clean();
}
$GLOBALS['gameRequest'] = ['inputtext', lrgNow(), 100 + (++$GLOBALS['LRG_TEST_SEQ']), 'I need work'];
lrgDlgPrepareTurn();
check('(g) the real turn offers T1 = "I need work." on an open, driven session', isset($GLOBALS['LRG_DLG_TURN']['offer']['keys']['T1'])
    && (string) ($GLOBALS['LRG_DLG_TURN']['ro'] ?? 'x') === '', json_encode($GLOBALS['LRG_DLG_TURN']['offer'] ?? null));
$GLOBALS['LAST_LLM_RESPONSE'] = $neReply('', 'Take_Up_Business', 'T1');
$at = $neLogAt();
check('(g) an EMITTED pick with message "": the validator lets it through (the real dialogue line plays)', $GLOBALS['LRG_NE_VALIDATOR']('') === true && !isset($GLOBALS['LRG_NE_REJECT'])
    && str_contains($neLogSince($at), 'verdict=let-through (a business pick the game answers itself)'), fx_cut($neLogSince($at)));
$out = $GLOBALS['LRG_NE_HOOK'](["Hulda|command|ExtCmdLRG_SelectTopic@ok=1;cid=d1;npc=Hulda;do=pick;i=100\r\n"]);
check('(g) ...and the hook is idle: no floor line, the pick untouched', count($out) === 1 && ($GLOBALS['LRG_TEST_SAY'] ?? []) === [] && lrgSpokenThisTurn() === '');
$GLOBALS['LRG_DLG_TURN']['offer']['keys']['T1']['commit'] = true;   // a [commits] key the first time: PARKED - her question IS the message
$GLOBALS['LRG_DLG_TURN']['entries'][0]['commit'] = true;            // [pt19 v1.0] the gate decides on the entry itself
// ... and his words did not say it plainly (S4.3: "I need work" said verbatim WOULD be the confirmation itself)
lrgDlgPut('Hulda', ['utter' => ['text' => 'what kind of work is it', 'at' => lrgNow(), 'type' => 'inputtext', 'cid' => 'g2']]);
$neReset2 = $GLOBALS['LRG_DLG_TURN']; $neReset(); $GLOBALS['LRG_DLG_TURN'] = $neReset2;
$GLOBALS['LAST_LLM_RESPONSE'] = $neReply('', 'Take_Up_Business', 'T1');
check('(g) a PARKED [commits] pick with message "" (request 706\'s shape) IS rejected: her confirming question is the message', $GLOBALS['LRG_NE_VALIDATOR']('') === false);
$neReset();
turn('Hulda', snap(), 'inputtext', 'I need work');
$GLOBALS['LAST_LLM_RESPONSE'] = $neReply('I have a room to let.', 'Take_Up_Business', 'T1');
$GLOBALS['talkedSoFar'] = [];   // the muted shape: words in the raw reply, none spoken
$out = $GLOBALS['LRG_NE_HOOK'](["Hulda|command|ExtCmdLRG_SelectTopic@ok=1;cid=d1;npc=Hulda;do=pick;i=100\r\n"]);
check('(g) words in the raw reply but nothing in talkedSoFar (the mute): the hook is idle - it keys on the RAW text', ($GLOBALS['LRG_TEST_SAY'] ?? []) === []);
// --- (h) not our turns: rechat, a scene tick, an initiative tick, a plain funcret, the narrator, nobody
$neReset();
foreach ([['rechat', 'Hulda'], ['lrg_scenetalk', 'Hulda'], ['lrg_initiative', 'Hulda'], ['diary', 'Hulda'], ['inputtext', 'The Narrator'], ['inputtext', '']] as [$ty, $who]) {
    $neReset();
    $GLOBALS['HERIKA_NAME'] = $who; $GLOBALS['gameRequest'] = [$ty, lrgNow(), 100, 'x'];
    $GLOBALS['LAST_LLM_RESPONSE'] = $neReply('');
    $ok = $GLOBALS['LRG_NE_VALIDATOR']('') === true && !isset($GLOBALS['LRG_NE_REJECT']);
    $GLOBALS['LRG_NE_HOOK']([]);
    $GLOBALS['LRG_NE_RETRY']();
    check("(h) $ty / '" . ($who !== '' ? $who : 'nobody') . "': not ours - no verdict, no line", $ok && ($GLOBALS['LRG_TEST_SAY'] ?? []) === []);
}
$neReset();
turn('Hulda', snap(), 'funcret', 'command@ExtCmdLRG_Escort@follow@Error: x');
$GLOBALS['LAST_LLM_RESPONSE'] = $neReply('');
check('(h) a funcret that is NOT the voiced turn: not ours', $GLOBALS['LRG_NE_VALIDATOR']('') === true && (($GLOBALS['LRG_TURN']['mode'] ?? '') !== 'voiced'));
// --- (i) the VOICED funcret turn (functions off: no post-process hook ever runs) - the validator + retry still speak
$neReset(); $neResetMem();
$GLOBALS['LRG_VOICED'] = ['npc' => 'Hulda', 'code' => 'ExtCmdLRG_Escort', 'cid' => 'v1', 'what' => 'could not come along', 'why' => 'busy', 'kv' => ['do' => 'follow'], 'reason' => 'x', 'result' => 'Error: x', 'at' => lrgNow()];
turn('Hulda', snap(), 'funcret', 'command@ExtCmdLRG_Escort@follow@Error: Hulda could not come along');
$GLOBALS['FUNCTIONS_ARE_ENABLED'] = false;
check('(i) set-up: the funcret is the voiced turn', ($GLOBALS['LRG_TURN']['mode'] ?? '') === 'voiced', (string) ($GLOBALS['LRG_TURN']['mode'] ?? ''));
$GLOBALS['LAST_LLM_RESPONSE'] = $neReply('');
check('(i) an empty reply on the voiced turn is rejected by the validator', $GLOBALS['LRG_NE_VALIDATOR']('') === false);
$GLOBALS['LRG_TEST_REASK'] = static function (string $nudge): bool { $GLOBALS['LRG_NE_REJECT'] = ['why' => 'empty message', 'action' => 'Talk', 'item' => '']; return false; };
$GLOBALS['LRG_NE_RETRY']();
check('(i) ...and she still says exactly one line (a statement line - a funcret asks nothing)', count($GLOBALS['LRG_TEST_SAY'] ?? []) === 1
    && in_array($GLOBALS['LRG_TEST_SAY'][0], (array) lrgNeCfg('lines.statement'), true), json_encode($GLOBALS['LRG_TEST_SAY'] ?? []));
unset($GLOBALS['LRG_VOICED']);
// --- (j) no reply at all / invalid output: the TROUBLE line (she did not catch it), never the empty-message lines
$neReset(); $neResetMem();
turn('Hulda', snap(), 'inputtext', 'is the door locked?');
unset($GLOBALS['LAST_LLM_RESPONSE']);
$at = $neLogAt();
$GLOBALS['LRG_NE_RETRY']();
check('(j) no decoded reply (connector error, timeout, non-JSON): one TROUBLE line, why=no reply', count($GLOBALS['LRG_TEST_SAY'] ?? []) === 1
    && in_array($GLOBALS['LRG_TEST_SAY'][0], (array) lrgNeCfg('lines.trouble'), true) && str_contains($neLogSince($at), 'why=no reply said="'), fx_cut($neLogSince($at)));
$neReset();
turn('Hulda', snap(), 'inputtext', 'is the door locked?');
$GLOBALS['LAST_LLM_RESPONSE'] = $neReply('half a sentence that CHIM then rejected');
$GLOBALS['talkedSoFar'] = ['Aye, it has been'];
$at = $neLogAt();
$GLOBALS['LRG_NE_RETRY']();
check('(j) invalid output after sentences were already spoken mid-stream: nothing is added', ($GLOBALS['LRG_TEST_SAY'] ?? []) === [] && str_contains($neLogSince($at), 'why=invalid output said=(she already spoke'), fx_cut($neLogSince($at)));
// --- (k) a scene / outro turn: log only, unless never_empty.in_scene
$neReset(); $neResetMem();
$GLOBALS['HERIKA_NAME'] = 'Hulda'; $GLOBALS['gameRequest'] = ['inputtext', lrgNow(), 100, 'harder'];
$GLOBALS['LRG_TURN'] = ['npc' => 'Hulda', 'cid' => 'sc1', 'mode' => 'scene', 'type' => 'inputtext'];
$GLOBALS['LAST_LLM_RESPONSE'] = $neReply('', 'Change_Intimacy', 'faster');
$at = $neLogAt();
check('(k) in a scene the validator does not reject (a rejection would lose the scene action) and logs once', $GLOBALS['LRG_NE_VALIDATOR']('') === true && !isset($GLOBALS['LRG_NE_REJECT'])
    && substr_count($neLogSince($at), 'verdict=log-only') === 1 && $GLOBALS['LRG_NE_VALIDATOR']('') === true && substr_count($neLogSince($at), 'verdict=log-only') === 1, fx_cut($neLogSince($at)));
$out = $GLOBALS['LRG_NE_HOOK'](["Hulda|command|Change_Intimacy@faster\r\n"]);
check('(k) ...the hook logs only: the action goes out, no line', count($out) === 1 && ($GLOBALS['LRG_TEST_SAY'] ?? []) === [] && substr_count($neLogSince($at), 'verdict=log-only') === 2);
$neReset();
$GLOBALS['LRG_NE_TEST_OVERRIDE'] = ['in_scene' => true];
$GLOBALS['LRG_TURN'] = ['npc' => 'Hulda', 'cid' => 'sc1', 'mode' => 'scene', 'type' => 'inputtext'];
$GLOBALS['LAST_LLM_RESPONSE'] = $neReply('', 'Change_Intimacy', 'faster');
$out = $GLOBALS['LRG_NE_HOOK'](["Hulda|command|Change_Intimacy@faster\r\n"]);
check('(k) never_empty.in_scene=true: the hook speaks the line and keeps the action', count($out) === 1 && count($GLOBALS['LRG_TEST_SAY'] ?? []) === 1);
$neReset();
// --- (l) enabled=false: silent as before, everywhere
$neReset(); $neResetMem();
$GLOBALS['LRG_NE_TEST_OVERRIDE'] = ['enabled' => false];
turn('Hulda', snap(), 'inputtext', 'is the door locked?');
$GLOBALS['LAST_LLM_RESPONSE'] = $neReply('');
$v = $GLOBALS['LRG_NE_VALIDATOR']('');
$GLOBALS['LRG_NE_HOOK']([]);
$GLOBALS['LRG_NE_RETRY']();
check('(l) never_empty.enabled=false: no verdict, no line, nothing remembered', $v === true && ($GLOBALS['LRG_TEST_SAY'] ?? []) === [] && $neMem() === []);
$neReset();
// --- (m) the next turn: one rule, once, within note_seconds - and only on a speech / dlgtalk turn
$neReset(); $neResetMem();
turn('Hulda', snap(), 'inputtext', 'is the door locked?');
$GLOBALS['LAST_LLM_RESPONSE'] = $neReply('');
$GLOBALS['LRG_NE_VALIDATOR']('');
$GLOBALS['LRG_TEST_REASK'] = static function (string $nudge): bool { $GLOBALS['LRG_NE_REJECT'] = ['why' => 'empty message', 'action' => 'Talk', 'item' => '']; return false; };
$GLOBALS['LRG_NE_RETRY']();
$neReset();
$GLOBALS['LRG_TEST_NOW'] = lrgNow() + 30;
$GLOBALS['HERIKA_NAME'] = 'Hulda'; $GLOBALS['gameRequest'] = ['rechat', lrgNow(), 100, ''];
check('(m) a rechat 30 s later carries no note (not a turn where the player spoke)', lrgNeNote('Hulda') === '');
$GLOBALS['gameRequest'] = ['inputtext', lrgNow(), 100, 'well?'];
$note = lrgNeNote('Hulda');
check('(m) his next line 30 s later: the rule, once - "answers in words: one or two short spoken sentences, then the action if any"',
    str_starts_with($note, "Hulda's last reply carried no words at all.") && str_contains($note, 'answers in words') && str_contains($note, 'then the action if any') && !str_contains($note, '"'), $note);
check('(m) ...told once: the second read is empty', lrgNeNote('Hulda') === '' && ($neMem()['told'] ?? null) === true);
$neResetMem();
lrgMemSet('Hulda', ['empty_reply' => ['at' => lrgNow() - 500, 'cid' => 'x', 'n' => 1, 'told' => false]]);
check('(m) ...and not after note_seconds', lrgNeNote('Hulda') === '');
unset($GLOBALS['LRG_TEST_NOW']);
$neReset(); $neResetMem();
// --- (n) [pt18-words] NEVER FALSE. General Tullius, 2026-09-23 23:19 -04:00, the map table, ml=0 (0 of 10 measured),
// ambient scene, no session: "i swear to uphold the imperial vows" got "Then you are a Legionnaire. Report to Legate
// Rikke at the training yard for your first orders." with the facts line at qst=CW00A:0 before and after. The sentences
// below are that REAL model output being forbidden, never authored dialogue; the truthful ones are the facts the row
// carries. lib/lrg_replies.php, the never-false section (research/pt18-words.md).
$nfReset = static function () use ($neReset): void {
    $neReset();
    unset($GLOBALS['LRG_NF_SEEN'], $GLOBALS['LRG_NF_DROPPED'], $GLOBALS['LRG_NF_MUTED'], $GLOBALS['LRG_NF_LATE'], $GLOBALS['LRG_NF_REASKED'],
        $GLOBALS['LRG_NF_SHAPE'], $GLOBALS['LRG_NF_SAYING'], $GLOBALS['LRG_NF_TEST_OVERRIDE']);
    $GLOBALS['FUNCTIONS_ARE_ENABLED'] = true;
    $GLOBALS['LRG_DLG_MCM'] = ['ml' => 0, 'cal' => 0, 'lf' => 1];
    lrgNfCfg();
};
$nfMem = static fn(string $npc): array => (array) (lrgMemGet($npc)['false_claim'] ?? []);
$nfResetMem = static function (string $npc): void { lrgMemSet($npc, ['false_claim' => null, 'empty_reply' => null]); lrgDlgPut($npc, ['exec_qst' => null, 'facask' => null, 'facts' => null]); };
$nfSpeak = static function (string $npc, string $utter, string $type = 'inputtext'): void {
    $GLOBALS['HERIKA_NAME'] = $npc; $GLOBALS['gameRequest'] = [$type, lrgNow(), 100 + (++$GLOBALS['LRG_TEST_SEQ']), $utter];
    $GLOBALS['FUNCTIONS_ARE_ENABLED'] = in_array($type, LRG_PLAYER_SPEECH_TYPES, true);
};
// the dialogue turn record as lrgDlgPrepareTurn() builds it at the map table: on=1 ambient=1 open=0, the facts line's qst=
$nfTurn = static function (string $npc, string $utter, array $qst, array $over = [], string $type = 'inputtext') use ($nfSpeak): array {
    $nfSpeak($npc, $utter, $type);
    $live = in_array($type, LRG_PLAYER_SPEECH_TYPES, true) || $type === 'lrg_dlgtalk';
    $t = $over + ['npc' => $npc, 'cid' => 'nf' . $GLOBALS['LRG_TEST_SEQ'], 'type' => $type, 'on' => true, 'speech' => $live, 'talk' => false,
        'entries' => [], 'tail' => [], 'snap' => ['fac' => 'CWImperialFaction,CWFieldCOFaction,CWDialogueSoldierFaction', '_age' => 5],
        'q' => ['CW00A', 'CWObj', 'CWReservations', 'CW', 'aaThalmor', 'MQ101'], 'facts' => ['qst' => $qst, 'at' => lrgNow() - 5],
        'svc' => [], 'crime' => [], 'list' => 'none', 'layer_kind' => 'unknown', 'n' => 0, 'sent' => 0, 'crit' => 0, 'scene' => 1, 'ambient' => 1,
        'open' => 0, 'lost' => 0, 'why' => [], 'offer' => [], 'ask' => '', 'arrest' => '', 'fol' => [], 'check' => null, 'sid' => '0', 'gen' => 0,
        'list_pg' => 0, 'assisted' => 0, 'parked' => [], 'locked' => []];
    $t['faction'] = lrgFacTurn($t, $utter, $live);
    $GLOBALS['LRG_DLG_TURN'] = $t;
    return $t;
};
$aldisFacN = 'CrimeFactionHaafingar,CWImperialFaction,CWImperialFactionNPC,TownSolitudeFaction,Favor110QuestGiverFaction';
$tullQst = ['CW00A' => 0, 'CWObj' => 0, 'CWReservations' => 0, 'CW' => 0, 'aaThalmor' => 1, 'MQ101' => 0];
// a PARTIAL decoded object as the JSON connector stores it mid-stream: message is the last key so far, nothing after it
$nfPartial = static fn(string $msg): array => ['action' => 'Talk', 'target' => 'Testplayer', 'item' => '', 'character' => 'General Tullius', 'listener' => 'Testplayer', 'message' => $msg];
lrgNeRegister();
$nfReset(); foreach (['General Tullius', 'Legate Rikke', 'Captain Aldis', 'Hulda'] as $who) { $nfResetMem($who); }
$neSchema(true);
$nfTurn('General Tullius', "okay i guess you're the guy i talked to to join the legion", $tullQst);   // the ask, 23:19:29
$tN = $nfTurn('General Tullius', 'i swear to uphold the imperial vows', $tullQst);                          // 23:19:47, carried
check('(n) set-up: Tullius is the recruiter, the ask is carried, the facts line says CW00A:0', ($tN['faction']['role'] ?? '') === 'recruiter' && !empty($tN['faction']['carried'])
    && (($GLOBALS['LRG_DLG_TURN']['facts']['qst']['CW00A'] ?? -1) === 0), json_encode($tN['faction'] ?? null));
$GLOBALS['LAST_LLM_RESPONSE'] = $nfPartial('Then you are a Legionn');
check('(n) a partial sentence still being written: no verdict', $GLOBALS['LRG_NE_VALIDATOR']('Legionn') === true && !isset($GLOBALS['LRG_NE_REJECT']));
$GLOBALS['LAST_LLM_RESPONSE'] = $nfPartial('You want to join the Legion? Swear your oath here');
check('(n) a question, then an unterminated tail: no verdict yet', $GLOBALS['LRG_NE_VALIDATOR']('here') === true && !isset($GLOBALS['LRG_NE_REJECT']));
$at = $neLogAt();
$GLOBALS['LAST_LLM_RESPONSE'] = $nfPartial('You want to join the Legion? Swear your oath here, or move on. We');
$v = $GLOBALS['LRG_NE_VALIDATOR'](' We');
check('(n) the chunk that TERMINATED "Swear your oath here, or move on." is rejected in time (why=false claim, class oath) - before returnLines could take it',
    $v === false && (($GLOBALS['LRG_NE_REJECT']['why'] ?? '') === 'false claim') && (($GLOBALS['LRG_NE_REJECT']['class'] ?? '') === 'oath')
    && (($GLOBALS['LRG_NE_REJECT']['said'] ?? '') === 'Swear your oath here, or move on.'), json_encode($GLOBALS['LRG_NE_REJECT'] ?? null));
check('(n) ...logged with the fact and WHY enlistment cannot start by voice here: ml=0 cal=0 ambient=1 open=0',
    str_contains($neLogSince($at), 'never-false npc=General Tullius role=recruiter class=oath said="Swear your oath here, or move on." fact="CW01A:0(derived:CW00A:0<10)<160" verdict=rejected ml=0 cal=0 ambient=1 open=0 type=inputtext'), fx_cut($neLogSince($at)));
// [reviewer] CHIM's splitter takes a sentence on ".?! + whitespace": a delta of "\n" alone (a paragraph break) ends the
// tail before the next word arrives, so the tail must be judged in that very chunk - an ellipsis + space is not an end
$nfReset(); $nfTurn('General Tullius', 'i swear to uphold the imperial vows', $tullQst);
$GLOBALS['LAST_LLM_RESPONSE'] = $nfPartial("You want to join the Legion? Swear your oath here, or move on.\n");
check('(n) [reviewer] a tail ended by whitespace alone (".\n", no next word yet) is rejected in THAT chunk - CHIM takes it on the whitespace',
    $GLOBALS['LRG_NE_VALIDATOR']("\n") === false && (($GLOBALS['LRG_NE_REJECT']['said'] ?? '') === 'Swear your oath here, or move on.'), json_encode($GLOBALS['LRG_NE_REJECT'] ?? null));
$nfReset(); $nfTurn('General Tullius', 'i swear to uphold the imperial vows', $tullQst);
$GLOBALS['LAST_LLM_RESPONSE'] = $nfPartial('Then you are a Legionnaire... ');
check('(n) [reviewer] ...while an ellipsis followed by a space is still an unfinished tail (CHIM does not split there either)',
    $GLOBALS['LRG_NE_VALIDATOR'](' ') === true && !isset($GLOBALS['LRG_NE_REJECT']), json_encode($GLOBALS['LRG_NE_REJECT'] ?? null));
$nfReset(); $nfTurn('General Tullius', 'i swear to uphold the imperial vows', $tullQst);
$GLOBALS['LAST_LLM_RESPONSE'] = $neReply('Then you are a Legionnaire.');
$at = $neLogAt();
check('(n) the complete reply "Then you are a Legionnaire." is rejected: class member, CW01A derived 0 from CW00A:0', $GLOBALS['LRG_NE_VALIDATOR']('.') === false
    && (($GLOBALS['LRG_NE_REJECT']['class'] ?? '') === 'member') && str_contains((string) ($GLOBALS['LRG_NE_REJECT']['fact'] ?? ''), 'CW01A:0(derived:CW00A:0<10)<200'), json_encode($GLOBALS['LRG_NE_REJECT'] ?? null));
$nfReset(); $nfTurn('General Tullius', 'i swear to uphold the imperial vows', $tullQst);
$GLOBALS['LAST_LLM_RESPONSE'] = $neReply('Report to Legate Rikke at the training yard for your first orders.');
check('(n) "Report to Legate Rikke at the training yard for your first orders." is rejected: the next step by name (CW00A 0 < 10)', $GLOBALS['LRG_NE_VALIDATOR']('.') === false
    && (($GLOBALS['LRG_NE_REJECT']['class'] ?? '') === 'next') && str_contains((string) ($GLOBALS['LRG_NE_REJECT']['fact'] ?? ''), 'CW00A:0<10'), json_encode($GLOBALS['LRG_NE_REJECT'] ?? null));
// the 16:38 line on Captain Aldis (a REDIRECT, whose facts line carries no CW quest at all)
$nfReset(); $nfTurn('Captain Aldis', "that's where i come to start being an imperial i was looking to serve sir", [], ['snap' => ['fac' => $aldisFacN, '_age' => 5], 'q' => ['MS07', 'Favor110Solitude'], 'scene' => 0, 'ambient' => 0]);
$GLOBALS['LAST_LLM_RESPONSE'] = $neReply('Report to the training yard at Castle Dour when the bell rings.');
check('(n) Aldis (redirect, no CW quest in qst=): "Report to the training yard at Castle Dour when the bell rings." is rejected - an appointment is never recorded, whatever the stage',
    $GLOBALS['LRG_NE_VALIDATOR']('.') === false && (($GLOBALS['LRG_NE_REJECT']['class'] ?? '') === 'orders') && (($GLOBALS['LRG_NE_REJECT']['role'] ?? '') === 'redirect'), json_encode($GLOBALS['LRG_NE_REJECT'] ?? null));
$nfReset(); $nfTurn('Captain Aldis', "that's where i come to start being an imperial i was looking to serve sir", [], ['snap' => ['fac' => $aldisFacN, '_age' => 5], 'q' => ['MS07'], 'scene' => 0, 'ambient' => 0]);
$GLOBALS['LAST_LLM_RESPONSE'] = $neReply('Speak to Legate Rikke in Castle Dour.');
$at = $neLogAt();
check('(n) ...while his own real answer "Speak to Legate Rikke in Castle Dour." passes (a redirect naming the recruiter is exempt)',
    $GLOBALS['LRG_NE_VALIDATOR']('.') === true && !isset($GLOBALS['LRG_NE_REJECT']) && str_contains($neLogSince($at), 'class=next') && str_contains($neLogSince($at), 'verdict=exempt'), fx_cut($neLogSince($at)));
// --- (o) truthful lines pass: Rikke at CW00A:0, the row's own facts and the floor lines themselves
$nfReset(); $nfTurn('Legate Rikke', 'hi um i am looking to join the legion', ['CWObj' => 0, 'CW00A' => 0, 'CWReservations' => 0, 'CW' => 0, 'CW00SolitudeMapTableScene' => 0]);
$at = $neLogAt();
foreach (['General Tullius first, at the map table in Castle Dour.', 'You are not a Legionnaire yet - Rikke tests every recruit at Fort Hraggstad.',
    'Nothing has begun.', 'The Legion needs men. I want you.', 'Once Rikke has tested you, you swear the oath before me.',
    'Nobody is sworn in by a word at this table. Enlistment is done the proper way, or not at all.', 'The Legion takes recruits at Castle Dour, not by a word in passing.'] as $line) {
    unset($GLOBALS['LRG_NE_REJECT'], $GLOBALS['LRG_NF_SEEN']);
    $GLOBALS['LAST_LLM_RESPONSE'] = $neReply($line);
    check("(o) a truthful line passes: '" . $line . "'", $GLOBALS['LRG_NE_VALIDATOR']('.') === true && !isset($GLOBALS['LRG_NE_REJECT']), json_encode($GLOBALS['LRG_NE_REJECT'] ?? null));
}
check('(o) ...and none of them was rejected in the log', !str_contains($neLogSince($at), 'verdict=rejected'), fx_cut($neLogSince($at)));
// [reviewer] a soldier's own routine and a description of how the oath is done are NOT orders / a membership claim: with
// CW00A:0 on the facts line (CW01A derived 0) every Legion NPC is judged on every turn, and "we drill at dawn" muted +
// "That is not mine to grant" spoken would be a non sequitur in the owner's face. Orders must be ADDRESSED to him.
$nfReset(); $nfTurn('Captain Aldis', 'how goes the war', $tullQst, ['snap' => ['fac' => $aldisFacN, '_age' => 5], 'q' => ['MS07'], 'scene' => 0, 'ambient' => 0]);
$at = $neLogAt();
foreach (['We march at dawn.', 'The soldiers drill in the yard every morning.', 'We hold the fort against the Stormcloaks.', 'The men muster at the gate by noon.',
    'We drill at dawn every day.', 'The recruits train in the yard behind the castle.', 'The oath is sworn at Castle Dour, before the General.'] as $line) {
    unset($GLOBALS['LRG_NE_REJECT'], $GLOBALS['LRG_NF_SEEN']);
    $GLOBALS['LAST_LLM_RESPONSE'] = $neReply($line);
    check("(o) [reviewer] a third-person line about the unit's own routine passes: '" . $line . "'", $GLOBALS['LRG_NE_VALIDATOR']('.') === true && !isset($GLOBALS['LRG_NE_REJECT']), json_encode($GLOBALS['LRG_NE_REJECT'] ?? null));
}
check('(o) [reviewer] ...none of them judged at all (no claim, no log line)', !str_contains($neLogSince($at), 'never-false'), fx_cut($neLogSince($at)));
foreach (['Report to the training yard at dawn.', 'See you at dawn, recruit.', 'Be here at dawn.', 'Then, go to the barracks and wait for your orders.', 'The oath is taken.'] as $line) {
    unset($GLOBALS['LRG_NE_REJECT'], $GLOBALS['LRG_NF_SEEN']);
    $GLOBALS['LAST_LLM_RESPONSE'] = $neReply($line);
    check("(o) [reviewer] ...while an order given to HIM is still rejected: '" . $line . "'", $GLOBALS['LRG_NE_VALIDATOR']('.') === false, json_encode($GLOBALS['LRG_NE_REJECT'] ?? null));
}
// --- (p) what makes a claim TRUE: the quest lane's executed flag, a later quest, an absent quest (unknown), a persisted
// exec_qst, and another NPC's cached facts line (quest stages are global)
$nfReset(); $tP = $nfTurn('General Tullius', 'i want to join the legion', $tullQst);
$tP['faction']['executed'] = ['quest' => 'CW00A', 'stage' => 10, 'how' => 'pick', 'cid' => 'x']; $GLOBALS['LRG_DLG_TURN'] = $tP;
$GLOBALS['LAST_LLM_RESPONSE'] = $neReply('Report to Legate Rikke; she will test you.');
$at = $neLogAt();
check('(p) executed CW00A:10 this turn: "Report to Legate Rikke; she will test you." passes (verdict=true from the executed stage)',
    $GLOBALS['LRG_NE_VALIDATOR']('.') === true && !isset($GLOBALS['LRG_NE_REJECT']) && str_contains($neLogSince($at), 'fact="CW00A:10(executed)>=10" verdict=true'), fx_cut($neLogSince($at)));
unset($GLOBALS['LRG_NF_SEEN']);
$GLOBALS['LAST_LLM_RESPONSE'] = $neReply('Then you are a Legionnaire.');
check('(p) ...while "Then you are a Legionnaire." is STILL rejected (our own pick set CW00A 10 just now: CW01A is at 0)', $GLOBALS['LRG_NE_VALIDATOR']('.') === false
    && str_contains((string) ($GLOBALS['LRG_NE_REJECT']['fact'] ?? ''), 'derived:CW00A:10(executed)=10'), json_encode($GLOBALS['LRG_NE_REJECT'] ?? null));
unset($GLOBALS['LRG_NE_REJECT'], $GLOBALS['LRG_NF_SEEN']);
$GLOBALS['LAST_LLM_RESPONSE'] = $neReply('Report to Legate Rikke at the training yard for your first orders.');
check('(p) ...and the training yard / first orders are still rejected at CW00A:10 (an appointment the game never records)', $GLOBALS['LRG_NE_VALIDATOR']('.') === false
    && (($GLOBALS['LRG_NE_REJECT']['class'] ?? '') === 'orders'), json_encode($GLOBALS['LRG_NE_REJECT'] ?? null));
$nfReset(); $nfTurn('General Tullius', 'i want to join the legion', ['CW02A' => 10, 'CWObj' => 0]);
$GLOBALS['LAST_LLM_RESPONSE'] = $neReply('Welcome to the Legion, soldier.');
$at = $neLogAt();
check('(p) CW02A running (a member): "Welcome to the Legion, soldier." passes as TRUE', $GLOBALS['LRG_NE_VALIDATOR']('.') === true && !isset($GLOBALS['LRG_NE_REJECT']) && str_contains($neLogSince($at), 'verdict=true'), fx_cut($neLogSince($at)));
$nfReset(); $nfTurn('Captain Aldis', 'i want to join the legion', [], ['snap' => ['fac' => $aldisFacN, '_age' => 5], 'q' => ['MS07'], 'scene' => 0, 'ambient' => 0]);
$GLOBALS['LAST_LLM_RESPONSE'] = $neReply('Welcome to the Legion, soldier.');
$at = $neLogAt();
check('(p) no CW quest anywhere (Aldis, nothing cached): membership is UNKNOWN - let through and logged, never treated as stage 0',
    $GLOBALS['LRG_NE_VALIDATOR']('.') === true && !isset($GLOBALS['LRG_NE_REJECT']) && str_contains($neLogSince($at), 'class=member') && str_contains($neLogSince($at), 'verdict=unknown'), fx_cut($neLogSince($at)));
lrgDlgPut('General Tullius', ['facts' => ['qst' => ['CW00A' => 0], 'at' => lrgNow() - 10]]);   // Tullius's facts line, ten seconds ago
$nfReset(); $nfTurn('Captain Aldis', 'i want to join the legion', [], ['snap' => ['fac' => $aldisFacN, '_age' => 5], 'q' => ['MS07'], 'scene' => 0, 'ambient' => 0]);
$GLOBALS['LAST_LLM_RESPONSE'] = $neReply('You are a Legionnaire now.');
check('(p) ...but with the recruiter\'s cached facts line (CW00A:0 ten seconds ago) the same claim on Aldis is rejected',
    $GLOBALS['LRG_NE_VALIDATOR']('.') === false && str_contains((string) ($GLOBALS['LRG_NE_REJECT']['fact'] ?? ''), 'cache:General Tullius'), json_encode($GLOBALS['LRG_NE_REJECT'] ?? null));
lrgDlgPut('General Tullius', ['facts' => null]);
$nfReset(); $nfTurn('General Tullius', 'i want to join the legion', $tullQst);
lrgDlgPut('General Tullius', ['exec_qst' => ['quest' => 'CW00A', 'stage' => 10, 'at' => lrgNow() + 1, 'cid' => 'x']]);   // the pick played after the last facts line
$GLOBALS['LAST_LLM_RESPONSE'] = $neReply('Report to Legate Rikke.');
$at = $neLogAt();
check('(p) a persisted exec_qst fresher than the facts line (SendFacts is throttled): "Report to Legate Rikke." passes on the NEXT turn too',
    $GLOBALS['LRG_NE_VALIDATOR']('.') === true && !isset($GLOBALS['LRG_NE_REJECT']) && str_contains($neLogSince($at), '(exec)>=10'), fx_cut($neLogSince($at)));
$nfReset(); $tP2 = $nfTurn('General Tullius', 'i want to join the legion', $tullQst); $tP2['facts']['at'] = lrgNow() + 5; $GLOBALS['LRG_DLG_TURN'] = $tP2;
$GLOBALS['LAST_LLM_RESPONSE'] = $neReply('Report to Legate Rikke.');
check('(p) ...until a facts line fresher than it says CW00A:0 again: rejected', $GLOBALS['LRG_NE_VALIDATOR']('.') === false, json_encode($GLOBALS['LRG_NE_REJECT'] ?? null));
lrgDlgPut('General Tullius', ['exec_qst' => null]);
// --- (q) the retry: re-asked ONCE with the model's own sentence quoted as false; her words win, else ONE floor line AFTER the partial
$nfReset(); $nfResetMem('General Tullius'); $nfTurn('General Tullius', 'i swear to uphold the imperial vows', $tullQst);
$GLOBALS['talkedSoFar'] = ['You want to join the Legion?'];   // the truthful first sentence went to TTS mid-stream (chim.log:20479)
$GLOBALS['LAST_LLM_RESPONSE'] = $neReply('You want to join the Legion? Then you are a Legionnaire.');
check('(q) set-up: rejected at the second sentence', $GLOBALS['LRG_NE_VALIDATOR']('.') === false && (($GLOBALS['LRG_NE_REJECT']['said'] ?? '') === 'Then you are a Legionnaire.'));
$GLOBALS['contextData'] = [['role' => 'system', 'content' => 'sys'], ['role' => 'user', 'content' => "Write General Tullius's next dialogue line."]];
$seenNudge = ''; $calls = 0;
$GLOBALS['LRG_TEST_REASK'] = static function (string $nudge) use (&$seenNudge, &$calls): bool { $calls++; $seenNudge = $nudge; $GLOBALS['talkedSoFar'][] = 'Nothing is settled by talk at this table.'; return true; };
$at = $neLogAt();
$GLOBALS['LRG_NE_RETRY']();
check('(q) the nudge quotes the false sentence, says the GAME decides (not the story), names how it really goes, and what he already said - single quotes, no double quote',
    $calls === 1 && str_contains($seenNudge, "the sentence 'Then you are a Legionnaire.' is false") && str_contains($seenNudge, 'The game, not the story, decides')
    && str_contains($seenNudge, 'General Tullius first, then Legate Rikke') && str_contains($seenNudge, "already said: 'You want to join the Legion?'") && !str_contains($seenNudge, '"'), $seenNudge);
check('(q) her own words won: no floor line, logged reask=spoke, remembered kind=reask', ($GLOBALS['LRG_TEST_SAY'] ?? []) === [] && str_contains($neLogSince($at), 'why=false claim reask=spoke chars=')
    && ($nfMem('General Tullius')['kind'] ?? '') === 'reask' && !isset($GLOBALS['IN_FALLBACK_MODE']), fx_cut($neLogSince($at)));
$nfReset(); $nfTurn('General Tullius', 'i swear to uphold the imperial vows', $tullQst);
$GLOBALS['talkedSoFar'] = ['You want to join the Legion?'];
$GLOBALS['LAST_LLM_RESPONSE'] = $neReply('You want to join the Legion? Then you are a Legionnaire.');
$GLOBALS['LRG_NE_VALIDATOR']('.');
$calls = 0;
$GLOBALS['LRG_TEST_REASK'] = static function (string $nudge) use (&$calls): bool { $calls++;
    $GLOBALS['LRG_NE_REJECT'] = ['why' => 'false claim', 'class' => 'member', 'said' => 'You are a Legionnaire now.', 'fact' => 'x', 'row' => 'legion', 'role' => 'recruiter', 'action' => 'Talk', 'npc' => 'General Tullius']; return false; };
$at = $neLogAt();
$GLOBALS['LRG_NE_RETRY']();
$said = $GLOBALS['LRG_TEST_SAY'] ?? [];
check('(q) the second reply was false too: exactly ONE floor line from never_false.lines.recruiter, spoken AFTER the truthful partial',
    $calls === 1 && count($said) === 1 && in_array($said[0], (array) lrgNfCfg('lines.recruiter'), true) && lrgSpokenThisTurn() === 'You want to join the Legion? ' . $said[0], json_encode([$said, lrgSpokenThisTurn()]));
check('(q) ...logged why=false claim twice with the line, and remembered (n=2)', str_contains($neLogSince($at), 'never-false npc=General Tullius role=recruiter action=Talk why=false claim twice said="' . ($said[0] ?? '') . '" after=28 chars')
    && (int) ($nfMem('General Tullius')['n'] ?? 0) === 2, fx_cut($neLogSince($at)));
// --- (r) a rechat carry (the NPC continuing; functions OFF, so no post-process hook runs): judged, rejected, never silent
$nfReset(); $nfTurn('General Tullius', "okay i guess you're the guy i talked to to join the legion", $tullQst);   // the ask
$nfReset(); $tR = $nfTurn('General Tullius', '', $tullQst, [], 'rechat');
check('(r) set-up: the rechat inside the window carries the ask, functions are off', !empty($tR['faction']['carried']) && empty($GLOBALS['FUNCTIONS_ARE_ENABLED']), json_encode($tR['faction'] ?? null));
$GLOBALS['LAST_LLM_RESPONSE'] = $neReply('Then you are a Legionnaire.');
check('(r) the rechat reply is rejected (lrgNeKind() leaves a rechat out; the faction record decides here)', $GLOBALS['LRG_NE_VALIDATOR']('.') === false && (($GLOBALS['LRG_NE_REJECT']['why'] ?? '') === 'false claim'));
$GLOBALS['LRG_TEST_REASK'] = static function (string $nudge): bool { $GLOBALS['LRG_NE_REJECT'] = ['why' => 'false claim', 'class' => 'member', 'said' => 'x', 'row' => 'legion', 'role' => 'recruiter', 'action' => 'Talk', 'npc' => 'General Tullius']; return false; };
$GLOBALS['LRG_NE_RETRY']();
check('(r) ...and the retry does not bail on kind \'\': one floor line, never silent (addendum 11)', count($GLOBALS['LRG_TEST_SAY'] ?? []) === 1, json_encode($GLOBALS['LRG_TEST_SAY'] ?? []));
// --- (s) not a faction turn: an innkeeper with no role and no ask says the same words - nothing is judged
$nfReset(); $nfTurn('Hulda', 'is the door locked?', [], ['snap' => ['fac' => 'JobInnkeeperFaction,TownWhiterunFaction', '_age' => 5], 'q' => [], 'scene' => 0, 'ambient' => 0]);
$GLOBALS['LAST_LLM_RESPONSE'] = $neReply("You're a soldier now. Report for duty at dawn.");
$at = $neLogAt();
check('(s) an ordinary turn (no faction role, no ask) is untouched: no verdict, no log line', $GLOBALS['LRG_NE_VALIDATOR']('.') === true && !isset($GLOBALS['LRG_NE_REJECT']) && !str_contains($neLogSince($at), 'never-false'));
// --- (t) mode=mute (the fallback if a live re-ask ever misbehaves): the transformer drops the sentence, the hook floors, no second call
$nfReset(); $nfResetMem('General Tullius'); $GLOBALS['LRG_NF_TEST_OVERRIDE'] = ['mode' => 'mute']; lrgNfCfg();
$nfTurn('General Tullius', 'i swear to uphold the imperial vows', $tullQst);
$GLOBALS['LAST_LLM_RESPONSE'] = $neReply('Then you are a Legionnaire.');
check('(t) mute: the validator lets the stream run', $GLOBALS['LRG_NE_VALIDATOR']('.') === true && !isset($GLOBALS['LRG_NE_REJECT']));
$at = $neLogAt();
$dropped = $GLOBALS['TRANSFORMER_FUNCTION']('Then you are a Legionnaire.');
$kept = $GLOBALS['TRANSFORMER_FUNCTION']('Nothing has begun.');
check('(t) ...the transformer drops the false sentence inside returnLines (no TTS, not in talkedSoFar) and keeps a truthful one', $dropped === '' && $kept === 'Nothing has begun.'
    && str_contains($neLogSince($at), 'class=member') && str_contains($neLogSince($at), 'verdict=muted'), fx_cut($neLogSince($at)));
$calls = 0; $GLOBALS['LRG_TEST_REASK'] = static function (string $n) use (&$calls): bool { $calls++; return true; };
$out = $GLOBALS['LRG_NE_HOOK'](["General Tullius|command|Talk@\r\n"]);
check('(t) ...and the hook speaks ONE floor line after the stream, the action untouched, no second LLM call', $calls === 0 && count($GLOBALS['LRG_TEST_SAY'] ?? []) === 1
    && $out === ["General Tullius|command|Talk@\r\n"] && str_contains($neLogSince($at), 'why=false claim (muted) said="'), json_encode($GLOBALS['LRG_TEST_SAY'] ?? []));
$nfReset(); $GLOBALS['LRG_NF_TEST_OVERRIDE'] = ['mode' => 'mute']; lrgNfCfg();
$nfTurn('General Tullius', 'i swear to uphold the imperial vows', $tullQst);
$GLOBALS['LAST_LLM_RESPONSE'] = $neReply('Then you are a Legionnaire.', 'Take_Up_Business', 'join the Legion');
$GLOBALS['TRANSFORMER_FUNCTION']('Then you are a Legionnaire.');
$out = $GLOBALS['LRG_NE_HOOK'](["General Tullius|command|ExtCmdLRG_SelectTopic@ok=1;cid=x;npc=General Tullius;do=pick;i=100\r\n"]);
check('(t) an EMITTED pick answers the turn itself: the sentence was dropped and the hook adds nothing (idle)', count($out) === 1 && ($GLOBALS['LRG_TEST_SAY'] ?? []) === []);
// --- (u) a pick-bearing reply under mode=reask is never REJECTED (the pick would die with the words): the mute shape instead
$nfReset(); $nfTurn('General Tullius', 'i want to join the legion', $tullQst);
$GLOBALS['LAST_LLM_RESPONSE'] = $neReply('Then you are a Legionnaire.', 'Take_Up_Business', 'join the Legion');
check('(u) Take_Up_Business + a false sentence: let through by the validator, dropped by the transformer', $GLOBALS['LRG_NE_VALIDATOR']('.') === true && !isset($GLOBALS['LRG_NE_REJECT'])
    && $GLOBALS['TRANSFORMER_FUNCTION']('Then you are a Legionnaire.') === '' && ($GLOBALS['LRG_NF_SHAPE'] ?? '') === 'mute', (string) ($GLOBALS['LRG_NF_SHAPE'] ?? ''));
$nfReset(); $nfTurn('General Tullius', 'i want to join the legion', $tullQst);
$GLOBALS['LAST_LLM_RESPONSE'] = ['character' => 'General Tullius', 'listener' => 'Testplayer', 'message' => 'Then you are a Legionnaire. Rikke will see you now.'];
check('(u) the character-first order (action not known yet): the mute shape too, per sentence', $GLOBALS['LRG_NE_VALIDATOR']('.') === true && !isset($GLOBALS['LRG_NE_REJECT'])
    && $GLOBALS['TRANSFORMER_FUNCTION']('Rikke will see you now.') === '' && ($GLOBALS['LRG_NF_SHAPE'] ?? '') === 'mute');
$nfReset(); $nfTurn('General Tullius', 'i want to join the legion', $tullQst, [], 'rechat');
$GLOBALS['LAST_LLM_RESPONSE'] = $neReply('Then you are a Legionnaire.', 'Take_Up_Business', 'join the Legion');
check('(u) ...but with functions OFF (no hook could floor) the reject shape is kept', $GLOBALS['LRG_NE_VALIDATOR']('.') === false && ($GLOBALS['LRG_NF_SHAPE'] ?? '') === 'reject');
// --- (v) mode=log: judge and log only
$nfReset(); $GLOBALS['LRG_NF_TEST_OVERRIDE'] = ['mode' => 'log']; lrgNfCfg();
$nfTurn('General Tullius', 'i swear to uphold the imperial vows', $tullQst);
$GLOBALS['LAST_LLM_RESPONSE'] = $neReply('Then you are a Legionnaire.');
$at = $neLogAt();
check('(v) log: nothing rejected, nothing dropped, the verdict logged as log-only', $GLOBALS['LRG_NE_VALIDATOR']('.') === true && !isset($GLOBALS['LRG_NE_REJECT'])
    && $GLOBALS['TRANSFORMER_FUNCTION']('Then you are a Legionnaire.') === 'Then you are a Legionnaire.' && str_contains($neLogSince($at), 'verdict=log-only'), fx_cut($neLogSince($at)));
$nfReset();
// --- (w) the next turn: one rule, once, beside the never-empty note
$nfReset(); $nfResetMem('General Tullius');
lrgMemSet('General Tullius', ['false_claim' => ['at' => lrgNow() - 30, 'cid' => 'x', 'n' => 1, 'why' => 'false claim twice', 'class' => 'member', 'said' => 'x', 'fact' => 'x', 'line' => 'x', 'i' => 0, 'kind' => 'floor', 'told' => false]]);
$nfSpeak('General Tullius', 'so am i in or not');
$note = lrgNeNote('General Tullius');
check('(w) 30 s later his next line carries the rule: last reply claimed an enlistment or a rank the game had not recorded; that sentence was not spoken; nothing has begun',
    str_starts_with($note, "General Tullius's last reply claimed an enlistment or a rank the game had not recorded; that sentence was not spoken.") && str_contains($note, 'Nothing has begun') && !str_contains($note, '"'), $note);
check('(w) ...told once', lrgNeNote('General Tullius') === '');
lrgMemSet('General Tullius', ['false_claim' => ['at' => lrgNow() - 30, 'class' => 'orders', 'kind' => 'floor', 'told' => false, 'n' => 1], 'empty_reply' => ['at' => lrgNow() - 20, 'cid' => 'x', 'n' => 1, 'told' => false]]);
$note = lrgNeNote('General Tullius');
check('(w) ...and beside a never-empty note both ride, never-empty first', str_starts_with($note, "General Tullius's last reply carried no words at all.") && str_contains($note, "\nGeneral Tullius's last reply claimed orders, a place or a time"), $note);
$nfSpeak('General Tullius', '', 'rechat');
lrgMemSet('General Tullius', ['false_claim' => ['at' => lrgNow() - 30, 'class' => 'oath', 'kind' => 'floor', 'told' => false, 'n' => 1]]);
check('(w) a rechat carries no note (not a turn where the player spoke)', lrgNfNote('General Tullius') === '');
// --- (x) config and the row table
check('(x) the shipped config carries the never_false block: mode reask, three line lists under max_chars, the legion truth map from UESP',
    is_array(lrgConfig()['never_false'] ?? null) && (string) lrgNfCfg('mode') === 'reask' && count((array) lrgNfCfg('lines.recruiter')) >= 2 && count((array) lrgNfCfg('lines.redirect')) >= 1 && count((array) lrgNfCfg('lines.none')) >= 1
    && max(array_map('strlen', array_merge((array) lrgNfCfg('lines.recruiter'), (array) lrgNfCfg('lines.redirect'), (array) lrgNfCfg('lines.none')))) <= (int) lrgNfCfg('max_chars')
    && (int) lrgNfCfg('rows.legion.truth.member.CW01A') === 200 && (int) lrgNfCfg('rows.legion.truth.next.CW00A') === 10 && (int) lrgNfCfg('rows.legion.truth.oath.CW01A') === 160);
check('(x) lrgNfRow merges default + row + the faction row\'s own cells: ranks, next_names, name, how', in_array('legionnaire', lrgNfRow('legion')['ranks'], true) && lrgNfRow('legion')['next_names'] === ['rikke']
    && lrgNfRow('legion')['name'] === 'the Imperial Legion' && str_contains(lrgNfRow('legion')['how'], 'Legate Rikke') && lrgNfRow('stormcloaks')['truth'] === [], json_encode(lrgNfRow('legion')));
check('(x) a floor line is never judged as a claim (the negation guard covers every shipped line)', (static function (): bool {
    foreach (array_merge((array) lrgNfCfg('lines.recruiter'), (array) lrgNfCfg('lines.redirect'), (array) lrgNfCfg('lines.none')) as $l) {
        foreach (lrgNfSentences((string) $l) as $p) { if (lrgNfClassify(lrgNfNorm((string) $p['s']), lrgNfRow('legion')) !== [] && !lrgNfGuarded(lrgNfNorm((string) $p['s']))) { return false; } }
    }
    return true;
})());
$nfReset(); foreach (['General Tullius', 'Legate Rikke', 'Captain Aldis', 'Hulda'] as $who) { $nfResetMem($who); }
unset($GLOBALS['LRG_DLG_MCM'], $GLOBALS['LRG_DLG_TURN'], $GLOBALS['FUNCTIONS_ARE_ENABLED']);
$neReset(); $neResetMem();
$GLOBALS['talkedSoFar'] = ['one short spoken line'];
unset($GLOBALS['structuredOutputTemplate'], $GLOBALS['action_post_process_fnct_ex']);

echo "37. [pt18-quest] the click-free quest entry: the catalog row, the net, the OK voiced or quiet, the refusals in her words\n";
// research/pt18-quest.md, PROTOCOL 10.26. The plan itself (the road, the pre-check, the licence) is test_dialogue 13 (l); THIS
// half is Phase 1's: the inactive carrier row, the net through lrgPostProcessActions (no Phase 1 turn needed), the sent record,
// and the verdict on the game's funcret - the one glue SUCCESS that is voiced (no licence pre-LLM), quiet when licensed (never
// both), every refusal in her words with no quest id, stage, global or "the game" in it; the developer dry run names the page.
require_once __DIR__ . '/../server/lorerim_glue/lib/lrg_dialogue.php';
$GLOBALS['PLAYER_NAME'] = 'Jordan';
$resetMem();
$GLOBALS['LRG_DLG_STATE'] = [];
unset($GLOBALS['LRG_VOICED'], $GLOBALS['LRG_FUNCRET'], $GLOBALS['LRG_TURN'], $GLOBALS['LRG_FAC_QE_SENT']);
// (a) the row
$rows = $GLOBALS['LRG_TEST_ROWS'] ?? [];
check('(a) LRG_CATALOG_ROWS carries ExtCmdLRG_QuestEntry (eight rows since pt19-purchase added ExtCmdLRG_Buy) and the version stays 11 - the missing-row path installs it',
    in_array(LRG_ACT_QUESTENTRY, LRG_CATALOG_ROWS, true) && count(LRG_CATALOG_ROWS) === 8 && LRG_ACTIONS_VERSION === 11 && isset($rows[LRG_ACT_QUESTENTRY]), implode(',', array_keys($rows)));
$qr = (array) ($rows[LRG_ACT_QUESTENTRY] ?? []);
check('(a) the row is switched off, offered to nobody, on a request type that never occurs, with a follow-up prompt of its own (recorded OR refused)',
    (int) ($qr['is_activated'] ?? 1) === 0 && (int) ($qr['available_to_npc'] ?? 1) === 0 && (int) ($qr['available_to_followers'] ?? 1) === 0
    && ($qr['metadata']['requirements']['request_types_any'] ?? []) === ['lrg_never'] && ($qr['metadata']['followup']['enabled'] ?? false) === true
    && ($qr['metadata']['followup']['prompt'] ?? '') === LRG_QUESTENTRY_FOLLOWUP_PROMPT && str_contains(LRG_QUESTENTRY_FOLLOWUP_PROMPT, 'recorded or refused')
    && ($qr['metadata']['followup']['use_functions_again'] ?? true) === false, json_encode($qr));
check('(a) ...the escort row\'s follow-up prompt is untouched (a failure wording)', ($rows[LRG_ACT_ESCORT]['metadata']['followup']['prompt'] ?? '') === LRG_FOLLOWUP_PROMPT);
check('(a) lrgChimWillVoice knows the row', lrgChimWillVoice(LRG_ACT_QUESTENTRY));
// (b) the net: a queued plan on Phase 2's turn becomes ONE line through Phase 1's gate, with NO Phase 1 turn in hand
$qt = ['npc' => 'General Tullius', 'cid' => 'g1', 'type' => 'inputtext', 'speech' => true, 'talk' => false, 'on' => true, 'ambient' => 1, 'entries' => [], 'tail' => [],
    'snap' => ['fac' => 'CWImperialFaction,CWFieldCOFaction'], 'q' => ['CW00A', 'MQ101'], 'facts' => ['qst' => ['CW00A' => 0], 'mq101c' => 1, 'at' => lrgNow() - 5], 'list' => 'none'];
$GLOBALS['LRG_DLG_MCM'] = ['ml' => 0, 'lf' => 1];
$qt['faction'] = lrgFacTurn($qt, 'i want to join the legion', true);
check('(b) the plan is queued, unlicensed (CW00B is not on the facts line)', ($qt['faction']['plan']['state'] ?? '') === 'queued' && empty($qt['faction']['plan']['licensed']), json_encode($qt['faction']['plan'] ?? null));
$GLOBALS['LRG_DLG_TURN'] = $qt;
$w = lrgPostProcessActions([]);
check('(b) lrgPostProcessActions with NO Phase 1 turn appends the ONE quest-entry line (it reads Phase 2\'s turn)',
    count($w) === 1 && lrgLineCode((string) $w[0]) === LRG_ACT_QUESTENTRY && str_contains((string) $w[0], ';quest=CW00A;stage=10;isid=78462;notdone=10,20;max=9;qdone=MQ101;qnd=CW00B:10;'), json_encode($w));
check('(b) ...and only once per request (a second run of the gate appends nothing more)', lrgPostProcessActions([]) === []);
check('(b) a quest-entry line the MODEL emitted is dropped by Phase 1\'s gate like any unoffered glue action (never passed through raw)', lrgPostProcessActions($w) === []);
$sent = lrgSentFind(lrgMemGet('General Tullius'), 'g1', LRG_ACT_QUESTENTRY);
$lic = (array) (lrgMemGet('General Tullius')['qe_lic'] ?? []);
check('(b) the sent record (src speech) and qe_lic (licensed 0, the row\'s say / meaning) are in lrg_memory',
    is_array($sent) && ($sent['src'] ?? '') === 'speech' && ($lic['cid'] ?? '') === 'g1' && (int) ($lic['lic'] ?? 1) === 0 && str_contains((string) ($lic['say'] ?? ''), 'speak with Legate Rikke'), json_encode([$sent, $lic]));
// (c) the game's OK, UNLICENSED: VOICED - the one glue success that is; exec_qst written; the cue names Rikke and forbids the oath
$p = explode('@', rtrim((string) $w[0], "\r\n"), 2)[1];
$okFr = 'command@' . LRG_ACT_QUESTENTRY . '@' . $p . ';qs=10;qj=0@OK: CW00A now at stage 10 (0 before): CW00A stage 10 - speak with Legate Rikke (no journal entry until her test)';
$GLOBALS['gameRequest'] = ['funcret', time(), 100, $okFr];
lrgRecordResult($okFr);
$res = lrgFuncretVerdict($okFr);
$ex = (array) (lrgDlgState('General Tullius')['exec_qst'] ?? []);
check('(c) the unlicensed OK is VOICED: pass, LRG_VOICED success=true, the fact is the meaning',
    $res === 'pass' && !empty($GLOBALS['LRG_VOICED']['success']) && str_contains((string) ($GLOBALS['LRG_VOICED']['fact'] ?? ''), 'sent to Legate Rikke'), $res . ' ' . json_encode($GLOBALS['LRG_VOICED'] ?? null));
check('(c) ...exec_qst {CW00A, 10, this cid} is written for the words lane and facexec is marked done',
    ($ex['quest'] ?? '') === 'CW00A' && (int) ($ex['stage'] ?? 0) === 10 && ($ex['cid'] ?? '') === 'g1' && (int) ((lrgDlgState('General Tullius')['facexec']['done'] ?? 0)) === 1, json_encode(lrgDlgState('General Tullius')));
check('(c) ...the funcret CHIM carries on with is the OK-shaped fact', str_contains((string) $GLOBALS['gameRequest'][3], '@entry@OK: he is sent to Legate Rikke'), (string) $GLOBALS['gameRequest'][3]);
$d = lrgVoicedDirective((array) $GLOBALS['LRG_VOICED']);
check('(c) ...the directive: ONE line, speak with Legate Rikke, no oath / rank / time / place, never a word about the game or stages, no "did not happen"',
    str_contains($d, 'ONE short line') && str_contains($d, 'speak with Legate Rikke') && str_contains($d, 'no oath, no rank, no time or place')
    && str_contains($d, 'never a word about the game, quests, stages') && !str_contains($d, 'did not happen'), $d);
$cue = lrgVoicedCue('General Tullius');
check('(c) ...the cue is for this code and this NPC', is_array($cue) && $cue['code'] === LRG_ACT_QUESTENTRY && str_contains((string) $cue['cue'], 'Legate Rikke'), json_encode($cue));
check('(c) ...lrgVoicedGate lets CHIM\'s turn run for him', (static function (): bool { $GLOBALS['HERIKA_NAME'] = 'General Tullius'; return lrgVoicedGate() === ''; })());
check('(c) ...the log says a success was voiced because no licence was given', str_contains((string) file_get_contents($neLogFile), 'voiced QuestEntry OK npc=General Tullius') && str_contains((string) file_get_contents($neLogFile), 'no licence was given pre-LLM'));
unset($GLOBALS['LRG_VOICED'], $GLOBALS['LRG_FUNCRET']);
// (d) LICENSED: the same OK stays quiet (the words already said it) - never both
$resetMem();
lrgMemSet('General Tullius', ['qe_lic' => ['cid' => 'g2', 'lic' => 1, 'quest' => 'CW00A', 'stage' => 10, 'at' => lrgNow(), 'say' => 'x', 'meaning' => 'y']]);
$GLOBALS['LRG_DLG_STATE'] = [];
$okFr2 = str_replace('cid=g1', 'cid=g2', $okFr);
$GLOBALS['gameRequest'] = ['funcret', time(), 100, $okFr2];
lrgRecordResult($okFr2);
check('(d) a LICENSED OK is handled quietly, and still writes exec_qst',
    lrgFuncretVerdict($okFr2) === 'handled' && !isset($GLOBALS['LRG_VOICED']) && (int) ((lrgDlgState('General Tullius')['exec_qst']['stage'] ?? 0)) === 10);
check('(d) an idempotent "already at stage" OK is a plain quiet success too', (static function () use ($okFr): bool {
    $fr = str_replace(['cid=g1', 'OK: CW00A now at stage 10 (0 before)'], ['cid=g4', 'OK: CW00A already at stage 10 - nothing to do'], $okFr);
    lrgMemSet('General Tullius', ['qe_lic' => ['cid' => 'g4', 'lic' => 1]]);
    $GLOBALS['gameRequest'] = ['funcret', time(), 100, $fr];
    lrgRecordResult($fr);
    return lrgFuncretVerdict($fr) === 'handled';
})());
unset($GLOBALS['LRG_VOICED'], $GLOBALS['LRG_FUNCRET']);
// (e) the refusals, in her words - never a quest id, a stage, a global or "the game"
$resetMem();
$vq = fn(string $reason) => lrgVoicedFact(['npc' => 'General Tullius', 'code' => LRG_ACT_QUESTENTRY, 'do' => 'entry', 'reason' => $reason, 'kv' => []]);
$f = $vq('CW00A waits on MQ101 being complete');
check('(e) Helgen not behind him: nothing recorded - his road into the Legion begins at Helgen (no MQ101, no CW00A)',
    str_contains($f, "nothing was recorded for Jordan's enlistment") && str_contains($f, 'begins at Helgen') && !preg_match('/MQ101|CW00A/', $f), $f);
$f = $vq('CW00A waits on SomeOtherQuest being complete');
check('(e) ...another gate quest: something he must see through first, unnamed', str_contains($f, 'something he must see through') && !str_contains($f, 'SomeOtherQuest'), $f);
$f = $vq('CW00A is already past that (stage 20)');
check('(e) already past it: that step is already behind him', str_contains($f, 'that step is already behind him') && !str_contains($f, 'stage'), $f);
$f = $vq("he has already had the other side's introduction (CW00B:10 is done)");
check('(e) the other side\'s introduction: closes this line of hers, no CW00B', str_contains($f, "other side's introduction") && !str_contains($f, 'CW00B'), $f);
$f = $vq('that is not really General Tullius (the CW00A line is for a different actor)');
check('(e) a namesake: that was not really him', str_contains($f, 'that was not really him') && !str_contains($f, 'CW00A'), $f);
$f = $vq('the game refused stage 10 of CW00A (stage 0 before, 0 after)');
check('(e) SetStage refused: it just would not work right now (never the game, never the stage)', str_contains($f, 'it just would not work right now') && !preg_match('/game|stage|CW00A/', $f), $f);
$f = $vq('CW00A is not running');
check('(e) not running: it just would not work right now', str_contains($f, 'it just would not work right now') && !str_contains($f, 'CW00A'), $f);
$f = $vq('dry-run mode is ON in the LoreRim Glue MCM (Diagnostics page) - the quest was not touched');
check('(e) the developer dry run names the switch and the page', str_contains($f, 'developer dry-run switch is on') && str_contains($f, 'Diagnostics page'), $f);
// the Helgen refusal through the verdict: voiced (pass), facexec cleared so a re-ask is not held as pending
lrgDlgPut('General Tullius', ['facexec' => ['quest' => 'CW00A', 'stage' => 10, 'at' => lrgNow(), 'cid' => 'g3', 'done' => 0, 'lic' => 0]]);
$errFr = 'command@' . LRG_ACT_QUESTENTRY . '@ok=1;cid=g3;npc=General Tullius;quest=CW00A;stage=10;qdone=MQ101;x=abc;z=1;err=CW00A waits on MQ101 being complete@Error: that road is not open to you yet - there is something you must see through first';
$GLOBALS['gameRequest'] = ['funcret', time(), 100, $errFr];
lrgRecordResult($errFr);
$res = lrgFuncretVerdict($errFr);
check('(e) the Helgen refusal funcret is VOICED (pass) with the Helgen fact, and facexec is cleared for a re-ask',
    $res === 'pass' && str_contains((string) ($GLOBALS['LRG_VOICED']['fact'] ?? ''), 'begins at Helgen') && empty(lrgDlgState('General Tullius')['facexec']), $res . ' ' . json_encode($GLOBALS['LRG_VOICED'] ?? null));
$d = lrgVoicedDirective((array) $GLOBALS['LRG_VOICED']);
check('(e) ...its directive is the ordinary failure one: does not pretend it happened, never a word about the game', str_contains($d, 'does not pretend it happened') && str_contains($d, 'never a word about the game'), $d);
check('(e) ...and the funcret CHIM carries on with is Error-shaped (the generic token is the short code)', str_starts_with((string) $GLOBALS['gameRequest'][3], 'command@' . LRG_ACT_QUESTENTRY . '@questentry@Error: '), (string) $GLOBALS['gameRequest'][3]);
unset($GLOBALS['LRG_VOICED'], $GLOBALS['LRG_FUNCRET'], $GLOBALS['LRG_DLG_TURN'], $GLOBALS['LRG_DLG_MCM'], $GLOBALS['LRG_FAC_QE_SENT'], $GLOBALS['HERIKA_NAME']);
$GLOBALS['LRG_DLG_STATE'] = [];
$resetMem();

echo "36b. [pt19 / script 511] QUIET MODE: the facts key, the offer, freshness, the release, the calibration candidate\n";
$resetMem();
$GLOBALS['LRG_DLG_STATE'] = [];
// --- (a) the facts key: quiet=1 -> 1 stamped; '-' -> 0; absent -> unchanged
$fq = lrgDlgFactsFrom(['quiet' => '1'], []);
check('quiet=1 on the facts line -> facts.quiet === 1, stamped now', ($fq['quiet'] ?? null) === 1 && abs((int) ($fq['quiet_at'] ?? 0) - lrgNow()) <= 2, json_encode($fq));
$fq0 = lrgDlgFactsFrom(['quiet' => '-'], $fq);
check('quiet=- -> 0 (off), re-stamped', ($fq0['quiet'] ?? null) === 0 && isset($fq0['quiet_at']), json_encode($fq0));
$fqa = lrgDlgFactsFrom(['sp' => '15'], ['quiet' => 1, 'quiet_at' => 5]);
check('an absent quiet= leaves the stored flag and its stamp alone', ($fqa['quiet'] ?? null) === 1 && (int) ($fqa['quiet_at'] ?? 0) === 5, json_encode($fqa));
// --- (b) the offer: the policy only ever REMOVES, and only on a fresh flag from the game
$quietPolicy = function (array $offered, ?array $state = null) {
    $GLOBALS['HERIKA_NAME'] = 'Hadvar';
    if ($state !== null) { $GLOBALS['LRG_TEST_NPCSTATE'] = ['Hadvar' => $state]; }
    $GLOBALS['ENABLED_FUNCTIONS'] = $offered;
    lrgFollowerPolicy();
    $out = array_values((array) $GLOBALS['ENABLED_FUNCTIONS']);
    unset($GLOBALS['LRG_TEST_NPCSTATE']);
    return $out;
};
$offer = ['Talk', 'Follow', 'FollowPlayer', 'MoveTo', 'EndConversation', 'GiveItemTo', LRG_ACT_START];
lrgDlgPut('Hadvar', ['facts' => ['quiet' => 1, 'quiet_at' => lrgNow(), 'mq101' => 200, 'mq101c' => 0]]);
$r = $quietPolicy($offer);
check('fresh quiet=1 on the facts: Follow / FollowPlayer / MoveTo / EndConversation withheld, Talk and the rest kept',
    $r === ['Talk', 'GiveItemTo', LRG_ACT_START], implode(',', $r));
$ql = (string) ($GLOBALS['LRG_QUIET']['line'] ?? '');
check('... the voiced line is prepared for context_pre (NEVER SILENT): it names Hadvar and the Helgen intro',
    str_contains($ql, 'Hadvar') && str_contains($ql, 'the Helgen intro') && ($GLOBALS['LRG_QUIET']['quest'] ?? '') === 'MQ101 stage 200', json_encode($GLOBALS['LRG_QUIET'] ?? null));
check('... and the spell is remembered once (lrg_memory quiet_held)', (int) (lrgMemGet('Hadvar')['quiet_held'] ?? 0) > 0, json_encode(lrgMemGet('Hadvar')));
// --- (c) stale: older than quiet.fresh_seconds hides nothing and releases the spell
$GLOBALS['LRG_TEST_NOW'] = lrgNow() + (int) lrgQuietCfg('fresh_seconds', 300) + 1;
$r = $quietPolicy($offer);
check('a stale quiet=1 (older than quiet.fresh_seconds) hides nothing, releases the spell and prepares no line',
    $r === $offer && empty(lrgMemGet('Hadvar')['quiet_held']) && !isset($GLOBALS['LRG_QUIET']), implode(',', $r));
unset($GLOBALS['LRG_TEST_NOW']);
// --- (d) quiet=0
lrgDlgPut('Hadvar', ['facts' => ['quiet' => 0, 'quiet_at' => lrgNow()]]);
$r = $quietPolicy($offer);
check('quiet=0 hides nothing', $r === $offer, implode(',', $r));
// --- (e) the snapshot carrier on its own (the facts line is not the only route)
$r = $quietPolicy($offer, snap(['quiet' => '1', '_age' => 1]));
check('quiet=1 on a fresh snapshot is enough by itself', !in_array('Follow', $r, true) && !in_array('MoveTo', $r, true) && in_array('Talk', $r, true), implode(',', $r));
$r = $quietPolicy($offer, snap(['quiet' => '1', '_age' => 500]));
check('... but not on a stale one', $r === $offer, implode(',', $r));
// --- (f) [pt19 v1.0 / section 4] the automatic calibration is RETIRED (lrgDlgCalibCandidate is gone); its successor rail: the
// pre-LLM open never opens on a quiet NPC (model F17) - a curated intro runs, the menu would take the camera from it
lrgDlgPut('Hadvar', ['facts' => ['quiet' => 1, 'quiet_at' => lrgNow()]]);
check('the automatic calibration candidate is gone from the server (spec section 4)', !function_exists('lrgDlgCalibCandidate'));
check('a quiet NPC: lrgDlgQuietOn, and the pre-LLM open queues nothing for "what do you sell"', lrgDlgQuietOn('Hadvar')
    && lrgDlgPreOpen(['npc' => 'Hadvar', 'cid' => 'q36f', 'clicks_ok' => 1, 'on' => true], 'what do you sell', lrgDlgState('Hadvar')) === '');
lrgDlgPut('Hadvar', ['facts' => ['quiet' => 0, 'quiet_at' => lrgNow()]]);
check('... and with quiet=0 she is no longer quiet', !lrgDlgQuietOn('Hadvar'));
// --- (g) [review] the game's two quiet refusals in her words (lib/lrg_actions.php lrgVoicedWhy): the real reason, never the
// quest id, the stage or the glue - the escort err= carries no quest id (generic words), the quest entry's names MQ101 (Helgen)
$vq = fn(string $code, string $do, string $reason) => lrgVoicedFact(['npc' => 'Hadvar', 'code' => $code, 'do' => $do, 'reason' => $reason, 'kv' => []]);
$e1 = $vq(LRG_ACT_ESCORT, 'follow', 'Hadvar will not do that now (a scripted intro)');
check('the escort refusal "(a scripted intro)" is voiced with the real reason, not the vague "will not do that right now"',
    str_contains($e1, 'in the middle of something important') && !str_contains($e1, 'will not do that right now'), $e1);
$e2 = $vq(LRG_ACT_QUESTENTRY, 'entry', 'quiet mode: MQ101 stage 200 is running - the glue touches no quest while it does');
check('the quest-entry refusal "quiet mode: MQ101 ..." names the Helgen business and never the quest id, the stage or the glue',
    str_contains($e2, 'the Helgen business') && !str_contains($e2, 'MQ101') && !str_contains($e2, '200') && !str_contains($e2, 'glue') && !str_contains($e2, 'would not work'), $e2);

unset($GLOBALS['LRG_QUIET'], $GLOBALS['HERIKA_NAME'], $GLOBALS['LRG_DLG_MCM']);
$GLOBALS['LRG_DLG_STATE'] = [];
$resetMem();

echo "38. [pt19-purchase] buying by voice: the carrier row, the funcret (OK quiet, the cached row refreshed), the refusals in her words, the dry run, the pending offer, the never-false price class\n";
// research/pt19-purchase.md, PROTOCOL 10.27. The dialogue half (the recogniser, the plan, the lines, the net) is test_dialogue 16; THIS
// half is Phase 1's and the memory's: the inactive GlueBuy row, lrgFuncretVerdict on the game's answer, every technical reason of
// LRG_Main.CmdBuy in her words (never a form id, a faction or the game), the one-item offer that "yeah thank you" confirms (lrg_memory,
// the FakeDb), and the price class of the never-false rail (lib/lrg_replies.php) judging "That'll be one septim".
require_once __DIR__ . '/../server/lorerim_glue/lib/lrg_dialogue.php';
require_once __DIR__ . '/../server/lorerim_glue/lib/lrg_replies.php';
$GLOBALS['PLAYER_NAME'] = 'Jordan';
$resetMem();
$GLOBALS['LRG_DLG_STATE'] = [];
unset($GLOBALS['LRG_VOICED'], $GLOBALS['LRG_FUNCRET'], $GLOBALS['LRG_TURN'], $GLOBALS['LRG_DLG_TURN'], $GLOBALS['LRG_MKT_SENT'], $GLOBALS['LRG_DLG_TRUTH_CLAIM'], $GLOBALS['LRG_DLG_MCM']);
$hulda = 'Hulda Test';
$bStock = '00034C5E:Ale:19:8:5,00034C5D:Nord Mead:39:3:10,000508CA:Honningbrew Mead:39:12:10,00065C97:Bread:23:24:6';
$bSnap = ['vend' => '1', 'room' => '25', 'bp' => '4.000000,3.000000,2.000000,15,0.000000,0.000000,3.850000,-', 'stock' => $bStock, 'pgold' => '300', '_age' => 5,
    'sess' => '4242', 'fac' => 'JobInnkeeperFaction,ServicesWhiterunBanneredMare,TownWhiterunFaction', 'class' => '', 'combat' => '0'];
$bTurn = static function (array $over = []) use ($hulda, $bSnap): array {
    return $over + ['npc' => $hulda, 'cid' => 'b1', 'type' => 'inputtext', 'speech' => true, 'talk' => false, 'on' => true, 'crit' => 0, 'scene' => 0, 'ambient' => 0,
        'ostim' => 0, 'open' => 0, 'lost' => 0, 'entries' => [], 'tail' => [], 'q' => [], 'facts' => ['sp' => 15], 'why' => [], 'crime' => [], 'qgiver' => 0, 'qal' => [],
        'svc' => ['kind' => '', 'direct' => 0, 'direct_why' => '', 'pricelist' => 0], 'fol' => [], 'faction' => [], 'list' => 'none', 'layer_kind' => '', 'n' => 0, 'sent' => 0,
        'offer' => [], 'snap' => $bSnap, 'check' => null, 'initiative' => null, 'quests' => '', 'result_block' => '', 'ask' => '', 'arrest' => ''];
};
// (a) the row
$rows = $GLOBALS['LRG_TEST_ROWS'] ?? [];
check('(a) LRG_CATALOG_ROWS carries ExtCmdLRG_Buy (eight rows), the version stays 11, the row was installed',
    in_array(LRG_ACT_BUY, LRG_CATALOG_ROWS, true) && count(LRG_CATALOG_ROWS) === 8 && LRG_ACTIONS_VERSION === 11 && isset($rows[LRG_ACT_BUY]), implode(',', array_keys($rows)));
$br = (array) ($rows[LRG_ACT_BUY] ?? []);
check('(a) the row is switched off, offered to nobody, on a request type that never occurs, with its own follow-up prompt (the outcome and the real price)',
    (int) ($br['is_activated'] ?? 1) === 0 && (int) ($br['available_to_npc'] ?? 1) === 0 && (int) ($br['available_to_followers'] ?? 1) === 0
    && ($br['metadata']['requirements']['request_types_any'] ?? []) === ['lrg_never'] && ($br['metadata']['followup']['enabled'] ?? false) === true
    && ($br['metadata']['followup']['prompt'] ?? '') === LRG_BUY_FOLLOWUP_PROMPT && str_contains(LRG_BUY_FOLLOWUP_PROMPT, 'the real price')
    && ($br['metadata']['followup']['use_functions_again'] ?? true) === false && ($br['action_name'] ?? '') === 'GlueBuy', json_encode($br));
check('(a) lrgChimWillVoice knows the row', lrgChimWillVoice(LRG_ACT_BUY));
// (b) the OK funcret: quiet, the sale remembered, the cached row refreshed from unit= / stock=
lrgMemSet($hulda, ['buyexec' => ['id' => '000508CA', 'name' => 'Honningbrew Mead', 'n' => 1, 'price' => 39, 'total' => 39, 'at' => lrgNow(), 'cid' => 'b1', 'x' => 'abc', 'done' => 0]]);
$okFr = 'command@' . LRG_ACT_BUY . '@ok=1;cid=b1;npc=' . $hulda . ';item=000508CA;n=1;price=39;name=Honningbrew Mead;x=abc;z=1;unit=39;paid=39;left=261;stock=11@OK: 1 Honningbrew Mead handed over for 39 septims';
$GLOBALS['gameRequest'] = ['funcret', time(), 100, $okFr];
lrgRecordResult($okFr);
$res = lrgFuncretVerdict($okFr);
$bm = lrgMemGet($hulda);
check('(b) the OK is HANDLED (quiet: her line said it, the "added" message is the receipt); buyexec done; bought remembered; buyadj 000508CA count 11 price 39',
    $res === 'handled' && !isset($GLOBALS['LRG_VOICED']) && (int) ($bm['buyexec']['done'] ?? 0) === 1 && (($bm['bought'][0]['name'] ?? '') === 'Honningbrew Mead') && (int) ($bm['bought'][0]['paid'] ?? 0) === 39
    && (int) ($bm['buyadj']['000508CA']['count'] ?? -1) === 11 && (int) ($bm['buyadj']['000508CA']['price'] ?? 0) === 39, json_encode($bm));
$mf = lrgMktFacts($bSnap, $hulda);
check('(b) ...and the facts off the SAME snapshot now show Honningbrew Mead with 11 left (the funcret is fresher than the snapshot)', (static function () use ($mf): bool {
    foreach ($mf['stock'] as $r) { if ($r['name'] === 'Honningbrew Mead') { return (int) $r['count'] === 11; } }
    return false;
})(), json_encode(array_map(static fn($r) => $r['name'] . ':' . $r['count'], $mf['stock'])));
check('(b) the log line: buy result ... OK ... quiet', str_contains((string) file_get_contents($neLogFile), 'buy result npc=Hulda Test OK Honningbrew Mead x1 paid=39 left=261 stock=11 - quiet'));
// (c) an Error funcret: voiced (pass), buyexec cleared, the cached price takes unit=
$resetMem();
lrgMemSet($hulda, ['buyexec' => ['id' => '00034C5E', 'name' => 'Ale', 'n' => 1, 'price' => 19, 'total' => 19, 'at' => lrgNow(), 'cid' => 'b2', 'x' => 'abd', 'done' => 0]]);
$errFr = 'command@' . LRG_ACT_BUY . '@ok=1;cid=b2;npc=' . $hulda . ';item=00034C5E;n=1;price=19;name=Ale;x=abd;z=1;unit=21;err=the price is 21 septims, not 19@Error: it is 21 septims now, not 19 - say the word and it is yours';
$GLOBALS['gameRequest'] = ['funcret', time(), 100, $errFr];
lrgRecordResult($errFr);
$res = lrgFuncretVerdict($errFr);
$bm = lrgMemGet($hulda);
check('(c) the moved-price refusal is VOICED (pass): "the Ale did not change hands - it is 21 septims now, not 19 - say the word and it is his"; buyexec cleared; buyadj price 21',
    $res === 'pass' && ($GLOBALS['LRG_VOICED']['fact'] ?? '') === 'the Ale did not change hands - it is 21 septims now, not 19 - say the word and it is his' && empty($bm['buyexec']) && (int) ($bm['buyadj']['00034C5E']['price'] ?? 0) === 21,
    ($GLOBALS['LRG_VOICED']['fact'] ?? '') . ' ' . json_encode($bm));
// [review] "say the word and it is yours" must be a real offer: the re-quote leaves the glue's own one-item offer open at the
// live price, so the player's next "yes" re-orders Ale @21 (lrgMktPlan's confirm path) instead of being nothing at all
check('(c) ...the re-quote is an OPEN one-item offer at the live price: buyask Ale @21 x1, said=1',
    ($bm['buyask']['id'] ?? '') === '00034C5E' && (int) ($bm['buyask']['price'] ?? 0) === 21 && ($bm['buyask']['name'] ?? '') === 'Ale'
    && (int) ($bm['buyask']['n'] ?? 0) === 1 && (int) ($bm['buyask']['said'] ?? 0) === 1, json_encode($bm['buyask'] ?? null));
$d = lrgVoicedDirective((array) $GLOBALS['LRG_VOICED']);
check('(c) ...the directive: ONE short line, the real reason, never a word about the game, does not pretend it happened', str_contains($d, 'ONE short line') && str_contains($d, 'never a word about the game') && str_contains($d, 'does not pretend it happened'), $d);
check('(c) ...the funcret CHIM carries on with is Error-shaped', str_starts_with((string) $GLOBALS['gameRequest'][3], 'command@' . LRG_ACT_BUY . '@buy@Error: the Ale did not change hands'), (string) $GLOBALS['gameRequest'][3]);
check('(c) ...lrgVoicedGate lets CHIM\'s turn run for her', (static function () use ($hulda): bool { $GLOBALS['HERIKA_NAME'] = $hulda; return lrgVoicedGate() === ''; })());
unset($GLOBALS['LRG_VOICED'], $GLOBALS['LRG_FUNCRET']);
// (d) every technical reason of CmdBuy in her words
$vb = fn(string $reason, array $kv = ['name' => 'Ale']) => lrgVoicedFact(['npc' => $hulda, 'code' => LRG_ACT_BUY, 'do' => 'buy', 'reason' => $reason, 'kv' => $kv]);
check('(d) not enough gold (has 20, needs 39): "Jordan cannot pay 39 septims with the 20 he carries"', $vb('not enough gold (has 20, needs 39)') === 'the Ale did not change hands - Jordan cannot pay 39 septims with the 20 he carries', $vb('not enough gold (has 20, needs 39)'));
check('(d) she is out of Ale: "has none of that left to sell"; with a count: "has only 1 of that left"', str_ends_with($vb('she is out of Ale'), 'Hulda Test has none of that left to sell') && str_ends_with($vb('she is out of Ale (has 1, wants 2)'), 'Hulda Test has only 1 of that left'));
check('(d) no vendor faction / not-sell-buy: "sells nothing of the kind"', str_ends_with($vb('no vendor faction'), 'sells nothing of the kind') && str_ends_with($vb('vendor faction not-sell-buy'), 'sells nothing of the kind'));
check('(d) closed (vendor hours 8-20): "not serving at this hour"', str_ends_with($vb('closed (vendor hours 8-20)'), 'Hulda Test is not serving at this hour'));
check('(d) hostile / an arrest / a scene with you / combat', str_ends_with($vb('hostile'), 'Hulda Test is hostile to Jordan') && str_ends_with($vb('an arrest'), 'as a lawbreaker') && str_ends_with($vb('a scene with you'), 'in the middle of something together') && str_ends_with($vb('combat'), 'there is fighting'));
check('(d) unknown buy request / unknown item form: "did not catch what Jordan asked for" (never the form id)', str_ends_with($vb('unknown buy request'), 'did not catch what Jordan asked for') && str_ends_with($vb('unknown item form FE89E8AC'), 'did not catch what Jordan asked for') && !str_contains($vb('unknown item form FE89E8AC'), 'FE89'));
check('(d) quiet mode: "in the middle of something she cannot leave" (never the quest id)', str_ends_with($vb('quiet mode: MQ101 stage 200 is running - the glue sells nothing while it does'), 'in the middle of something she cannot leave') && !str_contains($vb('quiet mode: MQ101 is running'), 'MQ101'));
check('(d) the developer dry run names the switch and the page', str_contains($vb('dry-run mode is ON in the LoreRim Glue MCM (Diagnostics page) - nothing was sold'), 'developer dry-run switch is on') && str_contains($vb('dry-run mode is ON in the LoreRim Glue MCM (Diagnostics page) - nothing was sold'), 'Diagnostics page'));
check('(d) an unnamed item: "what Jordan ordered did not change hands"', str_starts_with($vb('she is out of it', []), 'what Jordan ordered did not change hands'));
// (e) the one-item offer through lrg_memory: the ask writes it, her line keeps it, "yeah thank you" confirms it, "no thanks" ends it
$resetMem();
$oneDrink = ['stock' => '00034C5E:Ale:19:8:5,00065C97:Bread:23:24:6'] + $bSnap;
$t = $bTurn(['snap' => $oneDrink, 'cid' => 'o1']);
$t['buy'] = lrgMktPlan($t, 'something to drink', true);
$off = (array) (lrgMemGet($hulda)['buyask'] ?? []);
check('(e) "something to drink" with ONE drink in stock: ask with one candidate, the offer (Ale @19, cid o1) written to lrg_memory',
    $t['buy']['state'] === 'ask' && count($t['buy']['list']) === 1 && ($off['name'] ?? '') === 'Ale' && (int) ($off['price'] ?? 0) === 19 && ($off['cid'] ?? '') === 'o1', json_encode($off));
$GLOBALS['LAST_LLM_RESPONSE'] = ['action' => '', 'item' => '', 'message' => 'Only ale tonight, nineteen septims the bottle. Want one?'];
lrgMktNet($t, []);
check('(e) her line named it: the offer stays open (said=1)', (int) (lrgMemGet($hulda)['buyask']['said'] ?? 0) === 1, json_encode(lrgMemGet($hulda)['buyask'] ?? null));
$t2 = $bTurn(['snap' => $oneDrink, 'cid' => 'o2']);
$t2['buy'] = lrgMktPlan($t2, 'yeah thank you', true);
check('(e) "yeah thank you" confirms it: QUEUED Ale x1 @19 via confirm, the offer consumed', $t2['buy']['state'] === 'queued' && $t2['buy']['item']['name'] === 'Ale' && empty(lrgMemGet($hulda)['buyask']), json_encode($t2['buy']['state']));
$t3 = $bTurn(['snap' => $oneDrink, 'cid' => 'o3']);
$t3['buy'] = lrgMktPlan($t3, 'something to drink', true);
$GLOBALS['LAST_LLM_RESPONSE'] = ['action' => '', 'item' => '', 'message' => 'The well is out back, help yourself.'];
lrgMktNet($t3, []);
check('(e) an ask whose line did NOT name the item leaves no offer open', empty(lrgMemGet($hulda)['buyask']));
$t4 = $bTurn(['snap' => $oneDrink, 'cid' => 'o4']);
$t4['buy'] = lrgMktPlan($t4, 'something to drink', true);
$GLOBALS['LAST_LLM_RESPONSE'] = ['action' => '', 'item' => '', 'message' => 'Ale, nineteen. Yes?'];
lrgMktNet($t4, []);
$t5 = $bTurn(['snap' => $oneDrink, 'cid' => 'o5']);
$t5['buy'] = lrgMktPlan($t5, 'no thanks, tell me about the town', true);
check('(e) "no thanks" (anything but a confirmation) ends the offer, nothing queued', $t5['buy']['state'] === '' && empty(lrgMemGet($hulda)['buyask']));
$t6 = $bTurn(['snap' => $oneDrink, 'cid' => 'o6']);
$t6['buy'] = lrgMktPlan($t6, 'something to drink', true);
$GLOBALS['LAST_LLM_RESPONSE'] = ['action' => '', 'item' => '', 'message' => 'Ale, nineteen. Yes?'];
lrgMktNet($t6, []);
$GLOBALS['LRG_TEST_NOW'] = lrgNow() + 200;
$t7 = $bTurn(['snap' => $oneDrink, 'cid' => 'o7']);
$t7['buy'] = lrgMktPlan($t7, 'yeah', true);
check('(e) an offer older than offer_seconds (90) is no longer confirmed by a bare "yeah"', $t7['buy']['state'] === '');
unset($GLOBALS['LRG_TEST_NOW']);
// (f) pending: a buy in flight holds a second order back; done or old, it does not
$resetMem();
lrgMemSet($hulda, ['buyexec' => ['id' => '00034C5E', 'name' => 'Ale', 'n' => 1, 'price' => 19, 'total' => 19, 'at' => lrgNow(), 'cid' => 'p1', 'x' => 'x', 'done' => 0]]);
$tp = $bTurn(['cid' => 'p2']);
$tp['buy'] = lrgMktPlan($tp, 'a honningbrew please', true);
check('(f) a buy in flight: PENDING, the directive says the last order is still being handed over, nothing queued', $tp['buy']['state'] === 'pending' && str_contains(lrgMktRequestBlock($tp), 'The last order is still being handed over') && $tp['buy']['param'] === '', json_encode($tp['buy']['why']));
lrgMemSet($hulda, ['buyexec' => ['done' => 1, 'id' => '00034C5E', 'name' => 'Ale', 'at' => lrgNow()]]);
$tp['buy'] = lrgMktPlan($tp, 'a honningbrew please', true);
check('(f) ...done: the next order is queued', $tp['buy']['state'] === 'queued' && $tp['buy']['item']['name'] === 'Honningbrew Mead');
// (g) the never-false PRICE class on the vendor turn (lib/lrg_replies.php)
$resetMem();
$tq = $bTurn(['cid' => 'q1']);
$tq['buy'] = lrgMktPlan($tq, 'a honningbrew please', true);
$tq['locked'] = lrgDlgLockedFacts($tq);
$GLOBALS['LRG_DLG_TURN'] = $tq;
$GLOBALS['HERIKA_NAME'] = $hulda;
$GLOBALS['gameRequest'] = ['inputtext', time(), 100, 'a honningbrew please'];
$ctx = lrgNfContext();
check('(g) lrgNfContext covers the vendor turn (no faction row needed): rows = vendor', is_array($ctx) && ($ctx['rows'] ?? []) === ['vendor' => 'vendor'], json_encode($ctx['rows'] ?? null));
$j = lrgNfJudgeSentence("That'll be one septim for a bottle of Honningbrew, friend.", $ctx);
check('(g) "That\'ll be one septim for a bottle of Honningbrew" -> class price, verdict FALSE, fact "the list says Honningbrew Mead is 39 septims, not 1"',
    is_array($j) && $j['class'] === 'price' && $j['verdict'] === 'false' && $j['fact'] === 'the list says Honningbrew Mead is 39 septims, not 1' && ($j['mkt']['price'] ?? 0) === 39, json_encode($j));
$j2 = lrgNfJudgeSentence('Thirty-nine septims for the Honningbrew, and worth every one.', $ctx);
check('(g) a listed price passes (true: the number at the start of the sentence is a quote); a sentence with no price is not judged (null); a rank claim on a vendor is nobody\'s enlistment (null)',
    is_array($j2) && $j2['verdict'] === 'true' && lrgNfJudgeSentence('Here you are, friend.', $ctx) === null && lrgNfJudgeSentence('Welcome to the ranks, recruit.', $ctx) === null, json_encode($j2));
check('(g) "Ale, nineteen septims." (a clause-start quote) is judged and true; "I once lost 300 gold at dice" is narration, not judged',
    (lrgNfJudgeSentence('Ale, nineteen septims.', $ctx)['verdict'] ?? '') === 'true' && lrgNfJudgeSentence('I once lost 300 gold at dice in Riften.', $ctx) === null);
$nudge = lrgNfNudge($hulda, ['class' => 'price', 'said' => "That'll be one septim for a bottle of Honningbrew, friend.", 'row' => 'vendor', 'role' => 'vendor', 'mkt' => $j['mkt']]);
check('(g) the nudge quotes her sentence, names the list\'s price (Honningbrew Mead is 39 septims) and the allowed figures, single quotes only',
    str_contains($nudge, "the sentence 'That'll be one septim for a bottle of Honningbrew, friend.' names a price the game has not set") && str_contains($nudge, 'Honningbrew Mead is 39 septims') && str_contains($nudge, 'may name are') && !str_contains($nudge, '"'), $nudge);
[$fl, $fi] = lrgNfPick($hulda, 'vendor', 'vendor');
check('(g) the vendor floor line exists and carries the placeholders', $fl !== '' && in_array($fl, (array) lrgNfCfg('lines.vendor'), true) && str_contains($fl, '{price}'), $fl);
lrgNfRemember($hulda, 'q1', 'false claim', $j, '', -1, 'reask');
check('(g) the next-turn note: "claimed a price the game had not set", the list\'s fact, no enlistment wording', (static function () use ($hulda): bool {
    $n = lrgNfNote($hulda);
    return str_contains($n, "Hulda Test's last reply claimed a price the game had not set") && str_contains($n, 'Honningbrew Mead is 39 septims') && !str_contains($n, 'enlistment');
})(), lrgNfNote($hulda));
check('(g) the volatile guidance of a Legion turn is untouched by the price class when no stock is on the wire (the class is unknown there, never false)', (static function (): bool {
    $t = ['npc' => 'General Tullius', 'cid' => 'g9', 'type' => 'inputtext', 'speech' => true, 'talk' => false, 'on' => true, 'entries' => [], 'tail' => [], 'snap' => ['fac' => 'CWImperialFaction', '_age' => 5], 'q' => [], 'facts' => [], 'list' => 'none', 'buy' => ['state' => '']];
    $v = lrgNfVerdict('price', 'That will be five septims.', ['t' => $t, 'stages' => []], lrgNfRow('legion'), 'recruiter');
    return $v['verdict'] === 'unknown';
})());
unset($GLOBALS['LRG_DLG_TURN'], $GLOBALS['HERIKA_NAME'], $GLOBALS['LAST_LLM_RESPONSE'], $GLOBALS['LRG_MKT_SENT'], $GLOBALS['LRG_VOICED'], $GLOBALS['LRG_FUNCRET']);
$GLOBALS['LRG_DLG_STATE'] = [];
$resetMem();

// ##################################################################################################
// [pt19 v1.0] MENULESS QUESTING v1.0 - Lane B (research/pt19-menuless-v1-spec.md 2.2): the words, the truth, the factions
// ##################################################################################################
$GLOBALS['PLAYER_NAME'] = 'Jordan';
// what she says must carry none of the dialogue machinery (research/pt19c-language.md 6.2)
$lrgMachine = '/\b(?:glue|chim|mcm|mod|plugin|script|server|model|llm|index|topic|entry|t-?key|key|matcher|score|calibrat\w*|session|layer|fast path|gate|park(?:ed)?|rail|clicks_ok|token|editorid)\b|\.esp\b|\b[A-Z][a-z]+[A-Z]\w*\d/i';

echo "35v. [pt19 v1.0 / S7, spec test 35] every failure is words: the new reasons, the closed list of the .psc, the rail and read-only clauses, the assent list\n";
$vw = static fn(string $r): string => lrgVoicedWhy(['npc' => 'Hulda', 'code' => LRG_ACT_TOPIC, 'do' => 'pick', 'reason' => $r, 'kv' => []]);
foreach (['the entry moved before the click' => 'I lost the thread - say that again',
    'the click did not take' => 'that did not take - choose it on the menu',
    'the list could not be read' => 'I did not catch what we could talk about - choose it on the menu yourself',
    'choose that one on the list yourself' => 'choose that one on the list yourself',
    'her voice is not ready' => 'give me a moment',
    'the conversation was interrupted' => 'we were interrupted - ask me again',
    'stage rail - no click is verified on this install yet' => 'I have not picked a line for you yet - ask me something simple first, a question, then I can pick this one',
    'still learning the dialogue menu - the next conversation of any kind measures it' => 'the next conversation of any kind settles it',
    // [pt19c-B fix 1 / game review] the STUCK learning reason (CalLearnable false) promises nothing
    'still learning the dialogue menu - another conversation will not finish it: a Smart Talk setting blocks it (see the Calibration page)'
        => 'that cannot be taken up by voice for now - the menu itself still works'] as $reason => $want) {
    $got = $vw($reason);
    check('S7: "' . $reason . '" -> her plain words, no machinery word', str_contains($got, $want) && !preg_match($lrgMachine, $got), $got);
}
foreach (['a Smart Talk setting blocks it (see the Calibration page)', 'her list cannot be read here', ''] as $stuck) {
    $gotS = $vw('still learning the dialogue menu - another conversation will not finish it: ' . $stuck);
    check('[pt19c-B fix 1] the stuck learning reason ("' . $stuck . '") never promises that the next conversation settles it',
        !str_contains($gotS, 'next conversation') && !str_contains($gotS, 'settles') && str_contains($gotS, 'the menu itself still works'), $gotS);
}
// the closed list, parsed from the driver: every literal Error: reason, every FailOpen reason, every StopReason sentence
$dlgPsc = (string) @file_get_contents(dirname(__DIR__) . '/game/LoreRimGlue/Source/Scripts/LRG_Dialogue.psc');
preg_match_all('/"Error: ([^"]+)"/', $dlgPsc, $mE);
preg_match_all('/FailOpen\("([^"]+)"\)/', $dlgPsc, $mF);
$stopFn = preg_match('/string Function StopReason.*?EndFunction/s', $dlgPsc, $mS) ? $mS[0] : '';
preg_match_all('/return "([^"]+)"/', $stopFn, $mR);
$closed = array_values(array_unique(array_merge($mE[1], $mF[1], $mR[1])));
check('the closed list is parsed from LRG_Dialogue.psc and now carries "choose that one on the list yourself"',
    count($closed) >= 12 && in_array('choose that one on the list yourself', $closed, true), implode(' | ', $closed));
$badC = [];
foreach ($closed as $r) { $w = $vw($r); if (trim($w) === '' || preg_match($lrgMachine, $w)) { $badC[] = $r . ' -> ' . $w; } }
check('... and every reason on it comes out of lrgVoicedWhy as plain words with no machinery word', $badC === [], implode(' | ', $badC));
// the stage-rail line and the read-only clause: what she is told to SAY carries no machinery word
lrgDlgPut('*install*', ['clicks_ok' => 0]);
$srE = [['indexed' => 1, 'class' => 'plain', 'scripted' => 1, 'cost' => 0, 'kind' => '', 'goodbye' => 0, 'text' => 'I need a job.'],
    ['indexed' => 1, 'class' => 'plain', 'scripted' => 0, 'cost' => 0, 'kind' => '', 'goodbye' => 0, 'text' => 'Tell me about Whiterun.']];
$srL = lrgDlgStageRailLine(['clicks_ok' => 0, 'entries' => $srE, 'offer' => ['keys' => ['T1' => [], 'T2' => []]]]);
$srSay = trim((string) substr($srL, (int) strpos($srL, 'say:') + 4));
check('the stage-rail line names the pickable key and gives her a sentence with no machinery word',
    str_contains($srL, 'T2') && str_contains($srSay, 'ask me something simple first') && !preg_match($lrgMachine, $srSay), $srL);
// [pt19c-B fix 1 / use review P5, capability map U7] the no-key-passes branch is scanned too: a sentence that can come true
$srE7 = [['indexed' => 1, 'class' => 'plain', 'scripted' => 1, 'cost' => 0, 'kind' => '', 'goodbye' => 0, 'text' => 'I need a job.'],
    ['indexed' => 1, 'class' => 'pay', 'scripted' => 0, 'cost' => 10, 'kind' => '', 'goodbye' => 0, 'text' => "I'd like a room. (10 gold)"]];
$srL7 = lrgDlgStageRailLine(['clicks_ok' => 0, 'entries' => $srE7, 'offer' => ['keys' => ['T1' => [], 'T2' => []]]]);
$srSay7 = trim((string) substr($srL7, (int) strpos($srL7, 'say:') + 4));
check('[U7] no key passes: her sentence is "choose this one yourself, this once" - no condition that cannot come true, no machinery word',
    str_contains($srSay7, 'choose this one yourself, this once') && !str_contains($srSay7, 'ask me something simple') && !preg_match($lrgMachine, $srSay7), $srL7);
lrgDlgPut('*install*', ['clicks_ok' => 1]);
$roB = lrgDlgBusinessBlock(['on' => true, 'npc' => 'Irileth', 'ro' => 'scene-unproven', 'entries' => [['text' => 'I need to speak to the Jarl.', 'label' => '']],
    'list' => 'pending', 'layer_kind' => 'open', 'single' => null, 'sent' => 1, 'n' => 1]);
$roSay = preg_match('/words: ([^.]+)\./', $roB, $mRo) ? $mRo[1] : '';
check('the read-only clause: "choose it on the list yourself - I cannot pick for you here", no machinery word, no key',
    $roSay === 'choose it on the list yourself - I cannot pick for you here' && !preg_match($lrgMachine, $roSay) && !preg_match('/\bT\d\b/', $roB), $roB);
// [pt19c-B fix 1 / ai review 6] the kept never-false rail rides a read-only list too (<real_business> is suppressed there as well)
$roB3 = lrgDlgBusinessBlock(['on' => true, 'npc' => 'Irileth', 'ro' => 'scene-unproven', 'entries' => [['text' => 'I need to speak to the Jarl.', 'label' => '']],
    'list' => 'pending', 'layer_kind' => 'open', 'single' => null, 'sent' => 1, 'n' => 3]);
check('[ai review 6] a read-only list with sent < n (1 of 3): "You may NOT say that a thing is not on the table" rides, still no key',
    str_contains($roB3, 'You may NOT say that a thing is not on the table') && str_contains($roB3, '(1 of 3)') && !preg_match('/\bT\d\b/', $roB3)
    && !str_contains($roB, 'You may NOT say'), $roB3);
// the iSceneGate help text (Lane D's config.json): the S10 sentence, and none of the dialogue's internal words
$mcmCfg = json_decode((string) @file_get_contents(dirname(__DIR__) . '/game/LoreRimGlue/MCM/Config/LoreRimGlue/config.json'), true);
$sgHelp = '';
$walk = static function ($n) use (&$walk, &$sgHelp): void {
    if (!is_array($n)) { return; }
    if (($n['id'] ?? '') === 'iSceneGate:Dialogue') { $sgHelp = (string) ($n['help'] ?? ''); return; }
    foreach ($n as $c) { $walk($c); }
};
$walk($mcmCfg);
check('the iSceneGate help says the S10 sentence and none of the internal words (clicks_ok, stage rail, T-key, park, session, EditorID)',
    str_contains($sgHelp, 'she may be spoken to at a map table, during a performance or in tavern patter; a scene whose quest has an unfinished objective in your journal is never interrupted')
    && !preg_match('/\b(?:clicks_ok|stage rail|rail|t-?key|park(?:ed)?|session|editorid|matcher|layer|sj=|drv)\b/i', $sgHelp), substr($sgHelp, 0, 160));
// [rev2 game R6] no assent PHRASE is a back-out phrase under the leading-phrase form
$abad = [];
$assentAll = (array) lrgDlgCfg('confirm.assent_words', []);
foreach ($assentAll as $ph) {
    if (lrgDlgIsBackOut((string) $ph, true) || lrgDlgIsBackOut((string) $ph) || lrgDlgAssent((string) $ph) === null) { $abad[] = (string) $ph; }
}
check('no assent phrase is a back-out phrase, and every one of them is read as an assent (' . count($assentAll) . ' phrases)',
    $abad === [] && count($assentAll) >= 27, implode(', ', $abad));
check('the voiced-why machinery filter covers the dialogue words too (index, topic, session, calibration, park, rail, matcher, T-key)',
    $vw('the index row for that topic is parked in this session') === 'it just would not work right now');

echo "36v. [pt19 v1.0 / model F14, table 2.6] never empty: a pick carrying adv= is NOT the game answering itself\n";
$pkL = static fn(string $tail): array => ["Hulda|command|ExtCmdLRG_SelectTopic@ok=1;cid=d14;npc=Hulda;do=pick;sid=s1;gen=2;pos=0;i=100;txt=Go on.;kind=plain;cost=0;res=;x=f14;z=1" . $tail . "\r\n"];
check('an immediate pick (no adv=) carries the turn: the hook lets an empty message through, as before', lrgNeLinesCarryPick($pkL('')));
check('an auto-advance pick (adv=2500) and a re-armed one (adv=2500;rearm=1) do NOT: her words are the reply and must be spoken',
    !lrgNeLinesCarryPick($pkL(';adv=2500')) && !lrgNeLinesCarryPick($pkL(';adv=2500;rearm=1')) && !lrgNeLinesCarryPick($pkL(';adv=0')));
// [pt19c-B fix 1 / architect review, table 2.6 F27] the bare-yes release the gate appends to a words-only reply is no "game answers
// itself" either (WillEmit FALSE, no mute): her words are the reply
lrgDlgPut('Hulda', ['last_exec' => ['x' => 'f14', 'mode' => 'bare-yes', 'do' => 'pick', 'at' => lrgNow()]]);
check('[F27] the bare-yes pick (last_exec.mode bare-yes for that x) does NOT carry the turn: an empty message is re-asked or floored',
    !lrgNeLinesCarryPick($pkL('')));
lrgDlgPut('Hulda', ['last_exec' => ['x' => 'f14', 'mode' => 'explicit', 'do' => 'pick', 'at' => lrgNow()]]);
check('... while the same line picked explicitly still does', lrgNeLinesCarryPick($pkL('')));
lrgDlgPut('Hulda', ['last_exec' => null]);

echo "38v. [pt19 v1.0 / S6.1, spec test 38] never an invented reward: the engine's reward is fixed, she says so, and nothing pays one\n";
$resetMem();
$GLOBALS['LRG_DLG_STATE'] = [];
lrgDlgPut('*install*', ['clicks_ok' => 1]);
$GLOBALS['LRG_DLG_TEST_QUESTLOG'] = ['MQ104' => ['briefing' => 'Speak to Farengar about the dragon attack', 'stage' => 10]];
$rw = static function (string $npc, string $utter, array $state = []): array {
    unset($GLOBALS['LRG_DLG_TURN'], $GLOBALS['LRG_DLG_SVC_HIDDEN'], $GLOBALS['LRG_DLG_MCM']);
    lrgDlgPut($npc, ['q' => ['MQ104']] + $state);
    lrgStoreNpcState($npc, snap());
    $GLOBALS['HERIKA_NAME'] = $npc;
    $GLOBALS['gameRequest'] = ['inputtext', lrgNow(), 100 + (++$GLOBALS['LRG_TEST_SEQ']), $utter];
    $GLOBALS['ENABLED_FUNCTIONS'] = array_merge(TEST_ENABLED, ['SpawnGold', 'SpawnItem', 'GiveItemTo', 'TakeGoldFromPlayer']);
    lrgDlgPrepareTurn();
    return (array) ($GLOBALS['LRG_DLG_TURN'] ?? []);
};
$rwLine = 'the reward is what the world gives';
$rwHas = static fn(array $t): bool => in_array('reward', array_column((array) ($t['locked'] ?? []), 'class'), true);
$t38 = $rw('Farengar', "I'll need more gold than that for killing a dragon");
$five = ['GiveGoldTo', 'SpawnGold', 'SpawnItem', 'GiveItemTo', 'TakeGoldFromPlayer'];
check('(a) a quest turn (her journal quest in q): the five gold / item actions are off the table and registered as hidden this turn',
    lrgDlgQuestTurn($t38) && !array_intersect($five, (array) $GLOBALS['ENABLED_FUNCTIONS'])
    && !array_diff($five, (array) ($GLOBALS['LRG_DLG_SVC_HIDDEN'] ?? [])), implode(',', (array) $GLOBALS['ENABLED_FUNCTIONS']));
$GLOBALS['LAST_LLM_RESPONSE'] = ['message' => "Very well, I'll add 200 septims to your reward.", 'action' => 'GiveGoldTo', 'target' => 'Jordan', 'item' => '200'];
$o38 = lrgDlgPostProcessActions(["Farengar|command|GiveGoldTo@200\r\n"]);
check('(a) "I\'ll add 200 septims" + GiveGoldTo@200: the action is DROPPED by the hidden-this-turn rail, her sentence is untouched',
    !preg_grep('/GiveGoldTo/', $o38) && (string) $GLOBALS['LAST_LLM_RESPONSE']['message'] === "Very well, I'll add 200 septims to your reward.", json_encode($o38));
check('(a) ...and he bargained ("more gold than that"): the reward line rides her locked facts', $rwHas($t38)
    && str_starts_with((string) (array_values(array_filter((array) $t38['locked'], static fn($f) => $f['class'] === 'reward'))[0]['line'] ?? ''), $rwLine),
    json_encode(array_column((array) $t38['locked'], 'class')));
$tb = ['on' => true, 'npc' => 'Belethor', 'quests' => '', 'entries' => [['cost' => 25, 'norm' => 'iron dagger']], 'tail' => [],
    'locked' => [['class' => 'price', 'line' => 'what she is asking for "Iron Dagger": 25 septims', 'num' => 25]]];
check('(b) a barter turn with a price fact is untouched: no quest turn, TakeGoldFromPlayer@25 at the listed price passes the truth gate',
    !lrgDlgQuestTurn($tb) && lrgDlgTruthCheck($tb, 'That will be 25 septims.', ["Belethor|command|TakeGoldFromPlayer@25\r\n"]) === null);
$tq = ['quests' => '<shared_business>x</shared_business>', 'locked' => []] + $tb;
$c1 = lrgDlgTruthCheck($tq, 'Take this for your trouble.', ["Farengar|command|GiveGoldTo@200\r\n"]);
$c2 = lrgDlgTruthCheck($tq, 'Pay me then.', ["Farengar|command|TakeGoldFromPlayer@\r\n"]);
check('(b) the truth BACKSTOP on a quest turn: GiveGoldTo@200 that nothing confirms, and TakeGoldFromPlayer with NO amount -> claim reward',
    ($c1['claim'] ?? '') === 'reward' && str_contains((string) $c1['said'], '200') && ($c2['claim'] ?? '') === 'reward' && str_contains((string) $c2['said'], 'no amount'),
    json_encode([$c1, $c2]));
check('(b) ...while the listed 25 on the same quest turn is a real price and passes', lrgDlgTruthCheck($tq, 'Twenty-five.', ["Farengar|command|TakeGoldFromPlayer@25\r\n"]) === null);
// [pt19c-B fix 1 / CHIM review] the shapes CHIM really writes: TakeGoldFromPlayer's 'ask' policy (|confirmcommand|), the automatic one
// (|approvedcommand|), and a multi-property action's JSON param (its `item`, never the target's RefID digits)
$c3 = lrgDlgTruthCheck($tq, 'Pay me then.', ["Farengar|confirmcommand|TakeGoldFromPlayer@\r\n"]);
$c4 = lrgDlgTruthCheck($tq, 'A hundred, then.', ["Farengar|approvedcommand|TakeGoldFromPlayer@100\r\n"]);
$c5 = lrgDlgTruthCheck($tq, 'Take this.', ["Farengar|command|GiveGoldTo@" . '{"target":"Jordan [RefID: 00000014]"}' . "\r\n"]);
$c6 = lrgDlgTruthCheck($tq, 'Twenty-five.', ["Farengar|command|GiveGoldTo@" . '{"item":"25","target":"Jordan [RefID: 00000014]"}' . "\r\n"]);
check('(b) ANY channel: |confirmcommand| TakeGoldFromPlayer with no amount and |approvedcommand| @100 are caught by the backstop',
    ($c3['claim'] ?? '') === 'reward' && str_contains((string) $c3['said'], 'no amount') && ($c4['claim'] ?? '') === 'reward' && str_contains((string) $c4['said'], '100'),
    json_encode([$c3, $c4]));
check('(b) a JSON param: no `item` is "no amount" (never the RefID 14), and `item` 25 at the listed price passes',
    ($c5['claim'] ?? '') === 'reward' && str_contains((string) $c5['said'], 'no amount') && !str_contains((string) $c5['said'], '14') && $c6 === null,
    json_encode([$c5, $c6]));
$t38c = $rw('Farengar', 'What do you know about the dragon at the western watchtower?');
check('(c) a quest turn WITHOUT a bargaining word: the reward line is ABSENT (it would be surfaced unprompted)',
    lrgDlgQuestTurn($t38c) && !$rwHas($t38c), json_encode(array_column((array) $t38c['locked'], 'line')));
foreach (['Tell me more about the Dragon War', "I'm ready for more training"] as $u) {
    $tx = $rw('Farengar', $u);
    check('(c) "' . $u . '" (a real entry\'s words, bare "more") is no bargain', !$rwHas($tx));
}
// [pt19c-B fix 1 / game, ai 1, lang P1, use P1 reviews] FREE SPEECH that is no bargain: every sentence the reviewers measured, through
// lrgDlgRewardAsk for all of them and on the REAL path (Farengar, his journal quest in q) for a sample
$rwFree = ['Can you tell me a bit more about the Dragonstone?', 'I need more information about the barrow', 'Is there something more I should know?',
    'Tell me a little more about the dragons', "There's not enough time", 'Can I do it instead of you?', "I'd rather have a word with the Jarl",
    'Let me take that one instead', 'What should I do in return?', 'I want more details', 'Is there something more I can do?', 'I need more time to prepare',
    'There is more than that going on here', "Let's do that instead", "That's not enough to go on", 'The dragon is up front', 'Pay me no mind',
    'is there something more you can tell me', "it's more than that", 'I want more answers', 'we need more guards',
    'can you negotiate a truce between Ulfric and Tullius', 'double the guards at the gate', 'thanks in advance', 'what did the Greybeards give me in return',
    'is Dragonborn a title', "I'd rather have a drink first", 'let me do it instead', 'can I take this one instead', 'I found fifty gold on the bandit',
    "there's a bounty of a hundred gold on his head", "I'd rather have the greatsword", 'do you need more help with the dragons', 'I want more details about the job',
    "It's more than that, the dragon is back", 'let me be up front with you', 'I need more time to think', 'I need more ale', 'Can I get a bit more mead?',
    'Could I have a little more of that wine', 'Let me think about it a bit more', 'There is nothing I want more', 'Can you tell me a bit more?'];
$rwFreeHit = [];
foreach ($rwFree as $u) { $h = lrgDlgRewardAsk($u, ['npc' => 'Farengar', 'on' => true, 'entries' => [], 'tail' => []]); if ($h !== '') { $rwFreeHit[] = "$u [$h]"; } }
check('(c) ' . count($rwFree) . ' free-speech questions and statements the reviewers measured are no bargain (lrgDlgRewardAsk)', $rwFreeHit === [], implode(' | ', $rwFreeHit));
$rwFreeRide = [];
foreach (['Tell me a little more about the Dragonstone.', 'I need more information about the dragon', "There's not enough time",
    "I'd rather have a word with the Jarl", 'thanks in advance', 'let me do it instead'] as $u) {
    if ($rwHas($rw('Farengar', $u))) { $rwFreeRide[] = $u; }
}
check('(c) ...and on the real path (Farengar, MQ104 in q) none of them puts the reward line in <locked_facts>', $rwFreeRide === [], implode(' | ', $rwFreeRide));
// [pt19c-B fix 2 / game HIGH, ai 1, lang P1, use P1 - round 2] the ordinary quest talk that still fired (each measured on the real
// path by the game review; "NEW" = a trigger the first fix added), then the same bug's siblings (a third-person subject, an
// information verb, a question, a word after the phrase, a sum with no frame beside it). Every one is no bargain.
$rwFree2 = ['Is there anything more?', 'Did the Jarl say anything more?', 'Do you have anything more for me?', 'The people of Whiterun deserve better.',
    'Could you elaborate a little more?', 'Can you describe it a bit more?', 'Is there something more?', 'What do I get from the barrow?',
    'What do I get to do next?', 'I expected more than a pile of bones', "I'll need more than that to go on", 'What will I get there?',
    'The dragon was twice as much trouble', 'Your life is worth more than that', 'Say a little more.', 'Could you go over it a bit more',
    'I want to find the hundred gold that was stolen',
    'The guards need more.', 'The Jarl expected more.', 'The bandits want more', 'I will double my efforts', 'Do you have any extra supplies?',
    'Is there anything extra I should know?', 'Any extra information about the barrow?', 'They deserve a bigger funeral', 'The people I know deserve better',
    'What would I get at the market there?', 'Can you add a bit more detail?', 'Could you clarify a little more?', 'Can I hear a little more?',
    'Did he ask for more?', 'Are you asking for more?', "It's worth more than gold to the college", 'The hundred gold I want to find was stolen',
    'I need to find fifty gold', 'Is there a bit more to it?', "I don't deserve more", 'Is there anything on top of the mountain?',
    'Is there a little more?', 'Do you need a bit more?'];
$rwFree2Hit = [];
foreach ($rwFree2 as $u) { $h = lrgDlgRewardAsk($u, ['npc' => 'Farengar', 'on' => true, 'entries' => [], 'tail' => []]); if ($h !== '') { $rwFree2Hit[] = "$u [$h]"; } }
check('(c) [round 2] ' . count($rwFree2) . ' more quest-talk sentences are no bargain: a clause-end "more" in a question or after an information verb,'
    . ' "anything / something more" with no money word, "what do I get" before to / from / at / there, "deserve / worth / need more than" with no'
    . ' first-person subject or with words after it, "twice as much" with no money word, a sum with no frame beside it', $rwFree2Hit === [], implode(' | ', $rwFree2Hit));
$rwFree2Ride = [];
foreach (array_slice($rwFree2, 0, 17) as $u) { if ($rwHas($rw('Farengar', $u))) { $rwFree2Ride[] = $u; } }
check('(c) [round 2] ...and on the real path (Farengar, MQ104 in q) none of the 17 the game review measured puts the reward line in <locked_facts>',
    $rwFree2Ride === [], implode(' | ', $rwFree2Ride));
// the bargains, the spec's own words among them, and the owner's apostrophe-less STT (language brief A1)
$rwAsk = ['I want more', 'I deserve more than that', "I'll need more than that", 'I was hoping for more', "that's not enough", 'is that all I get',
    'double it', 'can you double the reward', 'throw in something extra', 'sweeten the deal', 'what about a bonus', 'make it two hundred septims',
    "I'll do it for five hundred septims", 'fifty septims more and we have a deal', 'give me the gold instead', "I'd rather have gold",
    'can I have a house instead', 'I want a title', 'make me a thane', "what's in it for me", 'what do I get', "what's the pay", 'will I be paid',
    "let's haggle", "let's negotiate", 'half now, half later', 'pay me first', 'anything extra for me', 'any extra for the trouble', 'give me extra',
    'I want extra', 'something on top', 'a little on top for the risk', 'can you add something on top', 'you should pay more', 'reward me',
    'I expect a reward', 'I want a better reward', 'how much are you paying', "I don't work for free", 'whats in it for me', 'uh whats in it for me',
    'thats all i get', 'whats the pay', 'Make it worth my while',
    // [pt19c-B fix 2] the language brief's 5.1 probes, and the forms the round-2 rules must keep: his own subject, a tail, a lead,
    // a money word, a proposal ("how about ..."), a question on `in_question`, a sum with its frame beside it
    'I think I deserve more', 'I expected more gold', 'a hundred septims on top', 'not for less than three hundred septims', 'I deserve better',
    "Don't I deserve more?", 'What do I get for killing the dragon?', 'What will I get in return?', 'what do i get from you',
    'Is that all I get for killing a dragon?', 'I expected more than fifty septims', 'I want twice as much gold', 'Can you pay me first?',
    'Can we negotiate?', 'How about a little more?', 'Could I have a bit more for my trouble?', 'Anything extra for me?', "I'm worth more than that",
    'We deserve better', "two hundred septims and it's a deal", 'I want at least two hundred septims', "I'm going to need more than that",
    'I was hoping for something more', 'fifty septims? how about a hundred?', 'I killed the dragon, what do I get?', 'I want 2,000 septims'];
$rwMiss = [];
foreach ($rwAsk as $u) { if (lrgDlgRewardAsk($u, ['npc' => 'Farengar', 'on' => true, 'entries' => [], 'tail' => []]) === '') { $rwMiss[] = $u; } }
check('(c) ' . count($rwAsk) . ' bargains ARE read as bargains (extra, on top, pay more, the STT "whats in it for me")', $rwMiss === [], implode(' | ', $rwMiss));
// [pt19c final fixer / completeness critic, S6.1 "ABSENT unless he bargains"] the quest talk that still fired after round 2 (each
// re-measured by the critic), and their siblings: a pay idiom, a waiver ("for free" with no negation, "no need to pay me"), a place
// after the phrase ("more gold in the barrow"), a third party or an activity verb before a weak phrase, "more of that" (no tail)
$rwFree3 = ["You don't have to pay me.", 'No need to pay me.', "I'll do it for free.", "Ulfric won't negotiate.", 'The guards are not enough.',
    'Are you paying attention?', 'Is there more gold in the barrow?', 'Let me explore a bit more.', 'What do I get out of the barrow?',
    'I want more of that.', 'Will you pay your respects to the fallen?',
    'you do not need to pay me', "don't pay me", 'I can do it for free', 'The Jarl will not negotiate', 'Two men are not enough',
    'Are there extra septims in the chest?', 'What would I get from the chest?', 'Let me rest a little more', 'I want more of it',
    'will you pay a visit to the Jarl?', 'pay me no heed'];
$rwFree3Hit = [];
foreach ($rwFree3 as $u) { $h = lrgDlgRewardAsk($u, ['npc' => 'Farengar', 'on' => true, 'entries' => [], 'tail' => []]); if ($h !== '') { $rwFree3Hit[] = "$u [$h]"; } }
check('(c) [final fixer] ' . count($rwFree3) . ' more quest-talk sentences are no bargain: a pay idiom, a waiver, a place after the phrase, a third party before a weak phrase, "more of that"',
    $rwFree3Hit === [], implode(' | ', $rwFree3Hit));
$rwFree3Ride = [];
foreach (array_slice($rwFree3, 0, 11) as $u) { if ($rwHas($rw('Farengar', $u))) { $rwFree3Ride[] = $u; } }
check('(c) [final fixer] ...and on the real path (Farengar, MQ104 in q) none of the 11 the critic measured puts the reward line in <locked_facts>',
    $rwFree3Ride === [], implode(' | ', $rwFree3Ride));
$rwAsk3 = ["I won't do it for free", "I'm not doing this for free", 'you expect me to work for free?', 'why should I do it for free?',
    "I won't do it unless you pay me", "I won't do it if you do not pay me", 'Why don\'t you pay me?', 'What do I get out of the deal?',
    'Is there more gold in it for me?', 'I want more gold in my purse', "that's simply not enough", 'we could negotiate', "I'd like to negotiate",
    'I need a bit more', 'can you pay me first?', 'you should pay me more', 'I want more than that'];
$rwMiss3 = [];
foreach ($rwAsk3 as $u) { if (lrgDlgRewardAsk($u, ['npc' => 'Farengar', 'on' => true, 'entries' => [], 'tail' => []]) === '') { $rwMiss3[] = $u; } }
check('(c) [final fixer] ...while ' . count($rwAsk3) . ' bargains beside them still ARE bargains (a negated "for free", a question, "out of the deal", his own subject)',
    $rwMiss3 === [], implode(' | ', $rwMiss3));
// [pt19c-B fix 2 / lang P8, the in-lane half] the owner's STT sums, folded before this lane reads them (lrgDlgMoneyFold): the free
// bribe check, the reward bargain and gate B's bonus read "5 hundred gold" as 500 and "sept ums" / "septem's" as septims
$fold = static fn(string $u): int => lrgDlgNamedAmount(lrgDlgMoneyFold($u));
check('(c) [lang P8] "5 hundred gold" 500, "five hundred sept ums" 500, "a hundred septem\'s" 100, "5 hundred and fifty gold" 550, "2,000 septims" 2000'
    . ', "25 hundred septims" 2500 - and the raw reader still needs the fold ("5 hundred gold" alone reads ' . lrgDlgNamedAmount('5 hundred gold') . ')',
    $fold('5 hundred gold') === 500 && $fold('five hundred sept ums') === 500 && $fold("a hundred septem's") === 100
    && $fold('5 hundred and fifty gold') === 550 && $fold('2,000 septims') === 2000 && $fold('25 hundred septims') === 2500
    && lrgDlgMoneyFold('I found the septims') === 'I found the septims',
    json_encode([$fold('5 hundred gold'), $fold('five hundred sept ums'), $fold("a hundred septem's"), $fold('5 hundred and fifty gold'), $fold('2,000 septims'), $fold('25 hundred septims')]));
// [pt19c final fixer / the STT sum root] the RAW readers now read the owner's STT sums (the root is lrg_intent.php): the engine bribe
// rail (lrgDlgCheckRails) on the DB01 guard's 200-septim bribe line takes "2 hundred septims" (it read 100 and refused) and
// "two hundred sept ums" (it read 0), and still refuses a real short offer
$brE = ['kind' => 'bribe', 'cost' => 200, 'norm' => 'bribe line', 'compound' => 0, 'indexed' => 1];
$brT = ['npc' => 'Guard R', 'cid' => 'r38', 'type' => 'inputtext', 'facts' => ['pg' => 500]];
$brR = static fn(string $u): string => lrgDlgCheckRails($brT, $brE, ['utter' => ['text' => $u]]);
check('(c) [final fixer] the engine bribe rail reads the raw STT sum: "2 hundred septims" and "two hundred sept ums" pass on a 200-septim bribe line, "1 hundred septims" is short',
    lrgDlgNamedAmount('here is 2 hundred septims, look the other way') === 200 && lrgDlgNamedAmount('five hundred sept ums') === 500
    && $brR('here is 2 hundred septims, look the other way') === '' && $brR('take these two hundred sept ums and look away') === ''
    && str_contains($brR('here is 1 hundred septims, look the other way'), 'offered 100'),
    json_encode([$brR('here is 2 hundred septims, look the other way'), $brR('take these two hundred sept ums and look away'), $brR('here is 1 hundred septims, look the other way')]));
lrgDlgPut('Guard F', ['utter' => ['text' => "here's 5 hundred sept ums, look the other way", 'at' => lrgNow(), 'type' => 'inputtext', 'cid' => 'f38']]);
check('(c) [lang P8] "I\'ll do it for 5 hundred sept ums" is a named-sum bargain; "here\'s 5 hundred sept ums, look the other way" is a bribe',
    lrgDlgRewardAsk("I'll do it for 5 hundred sept ums", ['npc' => 'Farengar', 'on' => true, 'entries' => [], 'tail' => []]) === 'a named sum'
    && lrgDlgCheckKind(['kind' => 'none', 'conf' => 'low'], '', ['npc' => 'Guard F', 'cid' => 'f38', 'on' => true, 'speech' => true, 'q' => [], 'entries' => [], 'tail' => []]) === 'bribe');
lrgDlgPut('Guard F', ['utter' => null]);
// his words ARE a line on her list: the game offers that choice itself (Eorlund's weapons) - "you cannot change it" would be false
$eorl = [['norm' => lrgPromptNorm("I'd like the greatsword."), 'text' => "I'd like the greatsword.", 'class' => 'plain'],
    ['norm' => lrgPromptNorm("I'd like the war axe."), 'text' => "I'd like the war axe.", 'class' => 'plain']];
check('(c) [lang P1] "I\'d like the greatsword instead of the gold" with that line on her list is no bargain (the list answers it)',
    lrgDlgRewardAsk("I'd like the greatsword instead of the gold", ['npc' => 'Eorlund', 'on' => true, 'entries' => $eorl, 'tail' => []]) === ''
    && lrgDlgRewardAsk("I'd like the greatsword instead of the gold", ['npc' => 'Eorlund', 'on' => true, 'entries' => [], 'tail' => []]) !== '');
// [game review] "Make it worth my while" is HIM asking to be paid: no bribe check (she would have asked him for septims), the reward line
lrgDlgPut('Farengar', ['utter' => ['text' => 'Make it worth my while', 'at' => lrgNow(), 'type' => 'inputtext', 'cid' => 'w38']]);
$tW = ['npc' => 'Farengar', 'cid' => 'w38', 'on' => true, 'speech' => true, 'q' => ['MQ104'], 'entries' => [], 'tail' => []];
check('(c) [game review] "Make it worth my while" on a quest turn: no bribe (check kind \'\'), and the reward line rides',
    lrgDlgCheckKind(['kind' => 'none', 'conf' => 'low'], '', $tW) === '' && lrgDlgRewardAsk('Make it worth my while', $tW) !== '');
lrgDlgPut('Guard W', ['utter' => ['text' => 'Make it worth my while', 'at' => lrgNow(), 'type' => 'inputtext', 'cid' => 'w41']]);
check('(c) ...and on no quest turn at all (a guard) it is still no bribe: "worth MY while" is him asking, not offering',
    lrgDlgCheckKind(['kind' => 'none', 'conf' => 'low'], '', ['npc' => 'Guard W', 'cid' => 'w41', 'on' => true, 'speech' => true, 'q' => [], 'entries' => [], 'tail' => []]) === '');
lrgDlgPut('Farengar', ['utter' => ['text' => 'I will pay you if you help me', 'at' => lrgNow(), 'type' => 'inputtext', 'cid' => 'w39']]);
check('(c) [language brief 5.3] coin with no sum and no purpose on a quest turn with no live price is no bribe either',
    lrgDlgCheckKind(['kind' => 'none', 'conf' => 'low'], '', ['cid' => 'w39'] + $tW) === '');
lrgDlgPut('Farengar', ['utter' => ['text' => "here's two hundred septims, look the other way", 'at' => lrgNow(), 'type' => 'inputtext', 'cid' => 'w40']]);
check('(c) ...while a bribe with a purpose is still a bribe', lrgDlgCheckKind(['kind' => 'none', 'conf' => 'low'], '', ['cid' => 'w40'] + $tW) === 'bribe');
lrgDlgPut('Farengar', ['utter' => null]);
// (d) a REAL faction line (CWImperialFaction Hadvar, ~390 chars - ai review 2): the ~190-char reward line fits after it in the 600 body
$rwH = static function (string $utter): array {
    unset($GLOBALS['LRG_DLG_TURN'], $GLOBALS['LRG_DLG_SVC_HIDDEN'], $GLOBALS['LRG_DLG_MCM']);
    lrgDlgPut('Hadvar', ['q' => ['MQ104']]);
    lrgStoreNpcState('Hadvar', snap(['fac' => 'CWImperialFaction,CWSoldierFaction']));
    $GLOBALS['HERIKA_NAME'] = 'Hadvar';
    $GLOBALS['gameRequest'] = ['inputtext', lrgNow(), 100 + (++$GLOBALS['LRG_TEST_SEQ']), $utter];
    $GLOBALS['ENABLED_FUNCTIONS'] = TEST_ENABLED;
    lrgDlgPrepareTurn();
    return (array) ($GLOBALS['LRG_DLG_TURN'] ?? []);
};
$lbBody = static function (string $b): int { preg_match_all('/^- .*$/m', $b, $mm); return (int) array_sum(array_map(static fn($l) => strlen($l) + 1, $mm[0])); };
$t38d = $rwH("I want to join the Legion, but what's in it for me?");
$cls = array_column((array) $t38d['locked'], 'class');
$blk = lrgDlgLockedBlock($t38d);
$facLen = strlen((string) (($t38d['locked'][0] ?? [])['line'] ?? ''));
check('(d) Hadvar (CWImperialFaction, a ' . $facLen . '-char faction line): "what\'s in it for me" -> the reward line rides right AFTER the faction line, both in the block',
    ($cls[0] ?? '') === 'faction' && $facLen >= 300 && array_search('reward', $cls, true) === 1 && str_contains($blk, $rwLine)
    && str_contains($blk, '- ' . (string) (($t38d['locked'][0] ?? [])['line'] ?? '-')), json_encode($cls) . ' body ' . $lbBody($blk));
check('(d) ...the block body stays <= 600 chars (' . $lbBody($blk) . ')', $lbBody($blk) <= 600 && $lbBody($blk) > 0);
// a line that would pass the cap is SKIPPED, not the end of the block: the short task line after an over-long one still rides
$lbS = lrgDlgLockedBlock(['npc' => 'Hadvar', 'cid' => 'd38', 'locked' => [['class' => 'faction', 'line' => str_repeat('f', 500), 'num' => null],
    ['class' => 'reward', 'line' => lrgDlgRewardLine(), 'num' => null], ['class' => 'quest', 'line' => 'his current task: "Speak to Farengar"', 'num' => null]]]);
check('(d) [ai review 2] a 500-char faction line: the reward line does not fit and is skipped, the task line after it is still told',
    !str_contains($lbS, $rwLine) && str_contains($lbS, 'his current task'), (string) $lbBody($lbS));
$t38e = $rw('Farengar', 'Thank you, that is all for now.', ['last_result' => ['ok' => true, 'at' => lrgNow() - 20, 'entry' => 'What about my reward?', 'told' => true]]);
check('(e) an OPEN reward window (a reward line was clicked 20 s ago) and no bargaining word: the line rides',
    lrgDlgRewardWindow('Farengar') !== [] && $rwHas($t38e), json_encode(lrgDlgRewardWindow('Farengar')));
// [pt19c-B fix 1 / code review] the window reads the clicked row's TOPIC too: a *Reward* topic whose line says nothing of a reward
lrgDlgPut('Balgruuf W', ['last_result' => ['ok' => true, 'at' => lrgNow() - 20, 'entry' => "I'm ready.", 'txt' => "I'm ready.", 'topic' => 'MQ104BalgruufRewardTopic', 'told' => true]]);
lrgDlgPut('Balgruuf V', ['last_result' => ['ok' => true, 'at' => lrgNow() - 20, 'entry' => "I'm ready.", 'txt' => "I'm ready.", 'topic' => 'MQ104BalgruufReadyTopic', 'told' => true]]);
check('(e) the topic glob *Reward*: "I\'m ready." on MQ104BalgruufRewardTopic opens the window, the same line on another topic does not',
    (string) (lrgDlgRewardWindow('Balgruuf W')['why'] ?? '') === 'a reward line' && lrgDlgRewardWindow('Balgruuf V') === []);
// [ai review 5] "the matter moved on" is gate B's (S6.2 (1)) - never the gate-A line's trigger: accepting her quest adds a row too
$GLOBALS['LRG_DLG_TEST_QUESTLOG']['MQ103'] = ['briefing' => 'Retrieve the Dragonstone from Bleak Falls Barrow', 'stage' => 10, 'at' => lrgNow() - 5];
lrgDlgPut('Farengar M', ['q' => ['MQ103'], 'qgiver' => 1, 'last_result' => ['ok' => true, 'at' => lrgNow() - 10, 'entry' => "I'll do it.", 'txt' => "I'll do it.", 'told' => true]]);
check('(e) a new journal row after "I\'ll do it." (he ACCEPTED her quest): no gate-A window; gate B\'s window reads it as "the matter moved on"',
    lrgDlgRewardWindow('Farengar M') === [] && (string) (lrgDlgRewardWindow('Farengar M', true)['why'] ?? '') === 'the matter moved on');
unset($GLOBALS['LRG_DLG_TEST_QUESTLOG']['MQ103']);
// [ai review 8] a quest turn is her journal rows, not the rendered block: quests.enabled off hides the block, never the protection
$GLOBALS['LRG_DLG_TEST_CFG'] = ['quests.enabled' => false];
$tQ8 = ['npc' => 'Farengar Q', 'cid' => 'q38', 'on' => true, 'q' => ['MQ104']];
check('(a) [ai review 8] quests.enabled off: <shared_business> renders nothing, and the turn is still a quest turn (her journal row in q)',
    lrgDlgQuestBlock($tQ8) === '' && lrgDlgQuestTurn($tQ8));
unset($GLOBALS['LRG_DLG_TEST_CFG']);
// [game review 3] a SERVICE click is no quest business: 20 s after "I'd like a room." Hulda's ale order is not a quest turn
lrgDlgPut('Hulda Q', ['last_result' => ['ok' => true, 'at' => lrgNow() - 20, 'entry' => "I'd like a room.", 'txt' => "I'd like a room.", 'told' => true]]);
$tHq = ['npc' => 'Hulda Q', 'cid' => 'h38', 'on' => true, 'speech' => true, 'q' => [], 'entries' => [], 'tail' => []];
lrgDlgPut('Hulda P', ['last_result' => ['ok' => true, 'at' => lrgNow() - 20, 'entry' => 'What happened at Helgen?', 'txt' => 'What happened at Helgen?', 'told' => true]]);
check('(g) [game review 3] 20 s after the room click ("I\'d like a room.") no quest turn: the gold actions stay, no reward line - a plain line still counts',
    !lrgDlgQuestTurn($tHq) && lrgDlgQuestTurn(['npc' => 'Hulda P'] + $tHq));
lrgDlgPut('Hulda Q', ['utter' => ['text' => 'I need more ale', 'at' => lrgNow(), 'type' => 'inputtext', 'cid' => 'h38']]);
check('(g) ...and "I need more ale" there puts no reward line in her locked facts', !in_array('reward', array_column(lrgDlgLockedFacts($tHq), 'class'), true));
// [review fix] a merchant haggle over a LIVE price on a quest turn runs the barter check - the reward line stays out of it
lrgDlgPut('Farengar', ['last_result' => null]);
lrgDlgPut('Farengar', ['utter' => ['text' => "can we haggle, that's too steep for a room", 'at' => lrgNow(), 'type' => 'inputtext', 'cid' => 'f38']]);
$t38f = ['npc' => 'Farengar', 'cid' => 'f38', 'on' => true, 'speech' => true, 'quests' => '<shared_business>x</shared_business>', 'q' => ['MQ104'],
    'entries' => [['cost' => 10, 'text' => "I'd like a room. (10 gold)", 'norm' => 'i d like a room', 'kind' => '']], 'tail' => []];
$rwBarter = in_array('reward', array_column(lrgDlgLockedFacts(['check' => ['kind' => 'barter']] + $t38f), 'class'), true);
$rwNoChk = in_array('reward', array_column(lrgDlgLockedFacts($t38f), 'class'), true);
// [pt19c-B fix 1 / game review 3] a PRICED line on her list is a market turn: no reward line with or without the check
check('(f) a price haggle on a quest turn (a priced line on her list): no reward line beside the barter check, nor without it',
    !$rwBarter && !$rwNoChk, json_encode([$rwBarter, $rwNoChk]));
lrgDlgPut('Farengar', ['last_result' => ['ok' => true, 'at' => lrgNow() - 20, 'entry' => 'What about my reward?', 'txt' => 'What about my reward?', 'told' => true]]);
check('(f) ...unless a reward window is open (he just clicked the reward line)', in_array('reward', array_column(lrgDlgLockedFacts($t38f), 'class'), true));
lrgDlgPut('Farengar', ['last_result' => null]);
// [ai review 3, gate B] a reward check that ran states its outcome in <reward_talk>: the fixed-reward locked line never rides beside it
lrgDlgPut('Farengar', ['utter' => ['text' => 'I deserve more than this, a hundred septims', 'at' => lrgNow(), 'type' => 'inputtext', 'cid' => 'f39']]);
$t38r = ['npc' => 'Farengar', 'cid' => 'f39', 'on' => true, 'speech' => true, 'q' => ['MQ104'], 'entries' => [], 'tail' => []];
check('(f) [ai review 3] a gate-B reward check on the turn (reward=1): no "you cannot add septims" beside "you add 20 septims"',
    !in_array('reward', array_column(lrgDlgLockedFacts(['check' => ['kind' => 'persuade', 'reward' => 1, 'result' => 'pass', 'give' => 20]] + $t38r), 'class'), true)
    && in_array('reward', array_column(lrgDlgLockedFacts($t38r), 'class'), true));
lrgDlgPut('Farengar', ['utter' => null]);
$rwLen = strlen(lrgDlgRewardLine());
check('the reward line itself: ~190 chars (the lane budget), septims, no invented favour, the real line pointed to (' . $rwLen . ' chars)', $rwLen <= 200
    && str_contains(lrgDlgRewardLine(), 'septims') && str_contains(lrgDlgRewardLine(), 'tell him to ask it') && str_contains(lrgDlgRewardLine(), 'if he bargains'));
unset($GLOBALS['LRG_DLG_TEST_QUESTLOG'], $GLOBALS['LAST_LLM_RESPONSE']);

echo "39. [pt19 v1.0 / S2.3, spec test 39] the open refused -> the click-free quest entry; no refusal -> the open first\n";
$GLOBALS['LRG_DLG_STATE'] = [];
$GLOBALS['LRG_DLG_TEST_STORE'] = [];   // a clean Phase 2 store: section 37 left its own exec_qst for Tullius in the stand-in table
lrgDlgPut('*install*', ['clicks_ok' => 1]);
$GLOBALS['LRG_DLG_MCM'] = ['ml' => 1, 'lf' => 1];
$tul = static fn(string $cid, int $clicks = 1): array => ['npc' => 'General Tullius', 'cid' => $cid, 'on' => true, 'speech' => true, 'type' => 'inputtext',
    'snap' => ['fac' => 'CWImperialFaction,CWFieldCOFaction', '_age' => 5], 'q' => ['CW00A', 'MQ101'], 'ambient' => 1, 'entries' => [], 'tail' => [],
    'clicks_ok' => $clicks, 'facts' => ['qst' => ['CW00A' => 0, 'CW00B' => 0], 'mq101c' => 1, 'at' => lrgNow() - 5]];
$p0 = (array) (lrgFacTurn($tul('o0'), 'i want to join the legion', true)['plan'] ?? []);
check('no refusal: Tullius at the map table after Helgen -> open-first (nothing sent, the open brings his list)',
    ($p0['state'] ?? '') === 'open-first' && (string) ($p0['param'] ?? 'x') === '', json_encode($p0));
$fr = static fn(string $npc, string $tail): string => lrgHandleGameMessage(['funcret', time(), 100,
    'command@ExtCmdLRG_SelectTopic@ok=1;cid=o1;npc=' . $npc . ';do=open;sid=0;gen=0;pos=-1;i=-1;x=o1x;z=1' . $tail]);
$v39 = $fr('General Tullius', ';err=a quest scene is running@Error: I am in the middle of something');
$ref = (array) (lrgDlgState('General Tullius')['open_refused'] ?? []);
check('the do=open funcret Error "a quest scene is running" (err=) -> open_refused {why, at}; the funcret still passes to CHIM untouched',
    $v39 === 'pass' && ($ref['why'] ?? '') === 'a quest scene is running' && abs((int) ($ref['at'] ?? 0) - lrgNow()) <= 2, json_encode($ref));
$p1 = (array) (lrgFacTurn($tul('o2'), 'i want to join the legion', true)['plan'] ?? []);
check('... and the next join ask: lrgFacQuestPlan QUEUES the click-free entry (the open cannot happen)',
    ($p1['state'] ?? '') === 'queued' && str_contains((string) ($p1['why'] ?? ''), 'the open cannot happen') && (string) ($p1['param'] ?? '') !== '', json_encode($p1));
check('... and the pre-LLM open stands down for her while it holds (lrgDlgOpenRefused)', lrgDlgOpenRefused(lrgDlgState('General Tullius')) !== []);
$fr('Rikke Test', ';err=that moment has passed@Error: that moment has passed');
$fr('Rikke Test', '@OK: Noted.');
check('[F4] "that moment has passed" and the armed open\'s "OK: Noted." never set open_refused', !isset(lrgDlgState('Rikke Test')['open_refused']));
$fr('Rikke Test', '@Error: combat');
check('an older game (no err=): the Error text itself is the reason - "combat" sets it', (string) (((array) (lrgDlgState('Rikke Test')['open_refused'] ?? []))['why'] ?? '') === 'combat');
lrgHandleGameMessage(['funcret', time(), 100, 'command@ExtCmdLRG_SelectTopic@ok=1;cid=o3;npc=Rikke Two;do=pick;sid=s1;x=o3x;z=1;err=combat@Error: combat']);
check('a do=PICK refusal is no refusal of an open', !isset(lrgDlgState('Rikke Two')['open_refused']));
// [review fix] LIVE the funcret is decided PRE-LOCK: preprocessing.php loads only lrg_core + lrg_actions (lib/lrg_dialogue.php is
// not loaded on a funcret request) - the writer must load Phase 2's store itself, or open_refused is never written in game
$preLock = (string) shell_exec('php -r ' . escapeshellarg('require "' . LRG_DIR . '/lib/lrg_actions.php"; $GLOBALS["LRG_DLG_TEST_STORE"] = [];'
    . ' $a = function_exists("lrgDlgPut") ? "loaded" : "absent";'
    . ' lrgDlgTopicFuncret("command@ExtCmdLRG_SelectTopic@ok=1;cid=p1;npc=Pre Lock;do=open;sid=0;x=p1x;z=1;err=combat@Error: I cannot talk right now");'
    . ' echo $a, " ", json_encode($GLOBALS["LRG_DLG_TEST_STORE"]["Pre Lock"]["open_refused"] ?? null);'));
check('pre-lock, as preprocessing.php runs it (lib/lrg_dialogue.php NOT loaded): the do=open refusal is still written',
    str_starts_with($preLock, 'absent ') && str_contains($preLock, '"why":"combat"'), $preLock);
lrgDlgPut('General Tullius', ['open_refused' => null]);
$p2 = (array) (lrgFacTurn($tul('o4', 0), 'i want to join the legion', true)['plan'] ?? []);
check('no click verified on this install yet (the join open waits for one, F19): the click-free entry carries the ask',
    ($p2['state'] ?? '') === 'queued' && str_contains((string) ($p2['why'] ?? ''), 'no click is verified'), json_encode($p2));
$GLOBALS['LRG_DLG_MCM'] = ['ml' => 1, 'lf' => 1, 'io' => 0];
$p3 = (array) (lrgFacTurn($tul('o5'), 'i want to join the legion', true)['plan'] ?? []);
check('bIntentOpen off: the click-free entry carries the ask', ($p3['state'] ?? '') === 'queued' && str_contains((string) ($p3['why'] ?? ''), 'io off'), json_encode($p3));
$GLOBALS['LRG_DLG_MCM'] = ['ml' => 1, 'lf' => 1];
// [pt19c-B fix 1 / game, ai 4, use P3 reviews] lrgDlgPreOpen's own stand-downs: the FIRST ask never gets neither carrier
$tulFar = $tul('o6');
$tulFar['snap'] = ['fac' => 'CWImperialFaction,CWFieldCOFaction', '_age' => 5, 'dist' => '260'];
$p4 = (array) (lrgFacTurn($tulFar, 'i want to join the legion', true)['plan'] ?? []);
check('Tullius 260 units away (a fresh snapshot; the game opens within 200): the click-free entry carries the FIRST ask (no open, no neither)',
    ($p4['state'] ?? '') === 'queued' && str_contains((string) ($p4['why'] ?? ''), 'too far apart') && (string) ($p4['param'] ?? '') !== '', json_encode($p4));
$tulFar['snap']['dist'] = '120';
check('... at 120 units the open is possible: open-first', (string) ((array) (lrgFacTurn($tulFar, 'i want to join the legion', true)['plan'] ?? []))['state'] === 'open-first');
$tulFar['snap'] = ['fac' => 'CWImperialFaction,CWFieldCOFaction', '_age' => 5, 'dist' => '100', 'combat' => '1'];
$p5 = (array) (lrgFacTurn($tulFar, 'i want to join the legion', true)['plan'] ?? []);
check('... in a fight (combat=1 on a fresh snapshot): the click-free entry carries it', ($p5['state'] ?? '') === 'queued' && str_contains((string) ($p5['why'] ?? ''), 'combat'), json_encode($p5));
$tulFar['snap'] = ['fac' => 'CWImperialFaction,CWFieldCOFaction', '_age' => 120, 'dist' => '900'];
check('... a STALE snapshot (120 s) decides nothing about distance (PreOpen reads only a fresh one): open-first',
    (string) ((array) (lrgFacTurn($tulFar, 'i want to join the legion', true)['plan'] ?? []))['state'] === 'open-first');
// [S2.2] the Say-Once greeting behind a closed road: no open at all, whatever he said
$tR = ['npc' => 'Legate Rikke', 'cid' => 'r1', 'on' => true, 'speech' => true, 'type' => 'inputtext', 'snap' => ['fac' => 'CWImperialFaction', '_age' => 5],
    'q' => [], 'entries' => [], 'tail' => [], 'facts' => ['qst' => ['MQ101' => 200], 'at' => lrgNow() - 5], 'faction' => []];
check('[S2.2] Rikke with the road CLOSED (MQ101 200 < 900): "what do you sell" opens nothing (her Say-Once greeting)',
    lrgFacRefusesOpen($tR, 'r1', 'what do you sell') && lrgFacSayOnceClosed($tR) !== '');
$tR['facts'] = ['qst' => ['MQ101' => 900], 'at' => lrgNow() - 5];
check('... with the road OPEN the rule stands aside (a plain question may open)', !lrgFacRefusesOpen($tR, 'r2', 'what do you sell') && lrgFacSayOnceClosed($tR) === '');
check('... and an NPC with no road cell is never touched by it', lrgFacSayOnceClosed(['npc' => 'Hulda', 'facts' => []]) === '');
unset($GLOBALS['LRG_DLG_MCM']);

echo "40. [pt19 v1.0 / S7, spec test 40] refusals in plain words: each code -> one <what_just_happened> line, no closed-list word, no digit\n";
$GLOBALS['LRG_DLG_STATE'] = [];
foreach (['afford' => 'priced entry 25 septims, the player has 10 - never clicked', 'amount' => 'the player offered 50, the price is 200',
    'words' => 'a bribe attempt needs at least 4 words on voice input (2)', 'retry' => 'this exact persuade already failed and nothing relevant changed',
    'frozen' => "the player's gold moved since the list was read (300 -> 250) - re-read, never click (freeze rule B1)"] as $code => $gateWhy) {
    check('the gate\'s "' . substr($gateWhy, 0, 40) . '..." is code ' . $code, lrgDlgRefusalCode($gateWhy) === $code, lrgDlgRefusalCode($gateWhy));
    $npc40 = 'Brynjolf ' . $code;
    lrgDlgNoteRefusal($npc40, $gateWhy);
    $wjh = lrgDlgGroundTruth($npc40, lrgDlgState($npc40));
    $line = preg_match('/Nothing came of it: [^\n]+/', $wjh, $m40) ? $m40[0] : '';
    // [pt19c-B fix 1 / game review, lang P6] in the block's own voice: "you" for her, the player by name - never a "she" or a bare "he"
    check('(' . $code . ') -> "' . $line . '"', $line === 'Nothing came of it: ' . lrgDlgRefusalSentence($code) . '.'
        && !preg_match('/\d|afford|amount|retry|frozen|freeze|priced|gate|never clicked|attempt|B1/i', $line) && !preg_match($lrgMachine, $line)
        && !preg_match('/\b(?:she|her|he|him|his)\b/i', $line), $wjh);
    check('(' . $code . ') ...told once', lrgDlgGroundTruth($npc40, lrgDlgState($npc40)) === '');
}
check('the three player-side sentences name the player; retry and frozen speak to her as "you"', str_contains(lrgDlgRefusalSentence('afford'), 'Jordan could not pay')
    && str_contains(lrgDlgRefusalSentence('retry'), 'you have already refused') && str_contains(lrgDlgRefusalSentence('frozen'), 'you had to look again'));
check('a gate why no code covers (the stage rail, a read-only session) stores nothing', lrgDlgNoteRefusal('Brynjolf X', 'stage rail - no click is verified') === ''
    && !isset(lrgDlgState('Brynjolf X')['refusal_note']) && !isset(lrgDlgState('Brynjolf X')['last_result']));
// [pt19c-B fix 1 / CHIM review, use review P2] the note has its OWN key: the engine's check verdict (and a result that landed while
// she was still speaking) survive it, and so do the quest turn and the reward window that read last_result
lrgDlgPut('Faralda N', ['last_result' => ['ok' => true, 'kind' => 'persuade', 'verdict' => 'fail', 'at' => lrgNow() - 20, 'entry' => 'You should let me in. (Persuade)', 'told' => true]]);
lrgDlgNoteRefusal('Faralda N', 'this exact persuade already failed and nothing relevant changed');
$lrN = (array) (lrgDlgState('Faralda N')['last_result'] ?? []);
check('a retry refusal keeps the engine verdict: last_result still says the persuade FAILED, the note sits beside it',
    (string) ($lrN['verdict'] ?? '') === 'fail' && (string) ((array) (lrgDlgState('Faralda N')['refusal_note'] ?? []))['code'] === 'retry'
    && lrgDlgQuestTurn(['npc' => 'Faralda N', 'on' => true, 'q' => []])
    && in_array('check', array_column(lrgDlgLockedFacts(['npc' => 'Faralda N', 'on' => true, 'speech' => true, 'q' => [], 'entries' => [], 'tail' => []]), 'class'), true),
    json_encode($lrN));
lrgDlgPut('Balgruuf N', ['last_result' => ['ok' => true, 'at' => lrgNow() - 10, 'entry' => 'What about my reward?', 'txt' => 'What about my reward?', 'told' => true]]);
lrgDlgNoteRefusal('Balgruuf N', 'priced entry 25 septims, the player has 10 - never clicked');
check('an afford refusal leaves the reward window open (it read last_result, which the note no longer touches)', lrgDlgRewardWindow('Balgruuf N') !== []);
// [lang P8] a sum the recogniser may have misread ("5 hundred", "sept ums"): the note never says he offered less than it costs
lrgDlgPut('Brynjolf U', ['utter' => ['text' => 'here is 5 hundred gold, look the other way', 'at' => lrgNow(), 'type' => 'inputtext', 'cid' => 'u40']]);
lrgDlgNoteRefusal('Brynjolf U', 'the player offered 100, the price is 200');
$u40 = lrgDlgGroundTruth('Brynjolf U', lrgDlgState('Brynjolf U'));
check('[lang P8] "here is 5 hundred gold" refused as too little: "the sum Jordan named did not come through clearly", never "offered less"',
    str_contains($u40, 'did not come through clearly') && !str_contains($u40, 'offered less') && !preg_match('/Nothing came of it:[^\n]*\d/', $u40), $u40);
lrgDlgPut('Brynjolf R', ['last_result' => ['ok' => false, 'why' => 'unverified', 'at' => lrgNow(), 'told' => false]]);
$r40 = lrgDlgGroundTruth('Brynjolf R', lrgDlgState('Brynjolf R'));
check('[ai review 7] the game\'s ev=result why=unverified is told as "Nothing came of it." once - never doubled, never the log string',
    str_contains($r40, "Nothing came of it.\n") && !str_contains($r40, 'nothing came of that') && !str_contains($r40, 'unverified'), $r40);
lrgDlgPut('Brynjolf Q', ['last_result' => ['ok' => false, 'why' => '', 'at' => lrgNow(), 'told' => false]]);
check('... and an empty why (the game sends one too) is the same sentence', str_contains(lrgDlgGroundTruth('Brynjolf Q', lrgDlgState('Brynjolf Q')), "Nothing came of it.\n"));
lrgDlgPut('Brynjolf S', ['session' => ['sid' => 's9', 'state' => 'open', 'stopped' => ['why' => 'combat', 'at' => lrgNow() - 5]]]);
$st40 = lrgDlgGroundTruth('Brynjolf S', lrgDlgState('Brynjolf S'));
check('[F2] she stopped driving (a fight began): one line in <what_just_happened>, told once',
    str_contains($st40, 'The menu was left to Jordan because a fight began.') && lrgDlgGroundTruth('Brynjolf S', lrgDlgState('Brynjolf S')) === '', $st40);

echo "41. [pt19 v1.0 / S6.2, gate B, OFF] the bounded bonus: do=award give=, gave= read back, a short-fall told once\n";
$GLOBALS['LRG_DLG_STATE'] = [];
check('gate A: checks.reward.enabled ships false, window 180 s, max 500 septims', lrgDlgCfg('checks.reward.enabled') === false
    && (int) lrgDlgCfg('checks.reward.window_seconds') === 180 && (int) lrgDlgCfg('checks.reward.max_gold') === 500);
$aw = lrgDlgAwardLine(['npc' => 'Irileth', 'cid' => 'a41', 'check' => ['kind' => 'persuade', 'reward' => 1, 'give' => 50, 'award' => true, 'xp' => 1, 'stat' => '', 'take' => 0]]);
$awKv = lrgParseKv(rtrim(substr($aw, (int) strpos($aw, '@') + 1), "\r\n"));
check('the award line carries give=50 AFTER z=1 (additive, S8)', ($awKv['give'] ?? '') === '50' && strpos($aw, ';z=1;give=50') !== false, $aw);
$aw0 = lrgDlgAwardLine(['npc' => 'Irileth', 'cid' => 'a42', 'check' => ['kind' => 'persuade', 'award' => true, 'xp' => 1, 'stat' => '', 'take' => 0]]);
check('... and an ordinary check\'s award line has no give= (the wire is unchanged for it)', strpos($aw0, 'give=') === false, $aw0);
lrgHandleGameMessage(['funcret', time(), 100, 'command@ExtCmdLRG_SelectTopic@ok=1;cid=a41;npc=Irileth;do=award;x=a41x;z=1;give=50@OK: gave 30 septims']);
$ag = (array) (lrgDlgState('Irileth')['award_gave'] ?? []);
check('the funcret "OK: gave 30 septims" is read back: award_gave n=30 want=50', (int) ($ag['n'] ?? 0) === 30 && (int) ($ag['want'] ?? 0) === 50, json_encode($ag));
$g41 = lrgDlgGroundTruth('Irileth', lrgDlgState('Irileth'));
check('next turn: "You gave Jordan 30 septims on top of his reward." and the short-fall, once',
    str_contains($g41, 'You gave Jordan 30 septims on top of his reward.') && str_contains($g41, 'You found you had only 30 septims to give.')
    && lrgDlgGroundTruth('Irileth', lrgDlgState('Irileth')) === '', $g41);
check('<reward_talk> states each outcome as fact, <= 350 chars, never a promise', strlen(lrgDlgRewardTalk(['result' => 'pass', 'award' => true, 'give' => 50])) <= 350
    && str_contains(lrgDlgRewardTalk(['result' => 'pass', 'award' => true, 'give' => 50]), '50 septims') && str_contains(lrgDlgRewardTalk(['result' => 'nocoin']), 'never promise to pay him later')
    && str_contains(lrgDlgRewardTalk(['result' => 'fixed']), 'no item, house, title or favour'));
check('gate A: no reward bargain is ever taken up (the branch is inert)', lrgDlgRewardBargain(['npc' => 'Irileth', 'on' => true, 'quests' => 'x'], 'I deserve more than this') === []);

// ============================================================================ [pt19h-money]
echo "42. [pt19h-money] money: the STT sum root, a question is no check attempt (G13), the price in prose (G14), the reward line only on a bargain (S6.1)\n";
$GLOBALS['LRG_DLG_STATE'] = [];
$GLOBALS['LRG_DLG_TEST_STORE'] = ['*install*' => ['clicks_ok' => 1]];
// --- (a) the STT sum root: the measurer's 20 shapes (research/pt19h-measure.json extras.stt_sums) + the siblings of the 4 misreads,
// through all three readers (lrgIntentAmount, lrgDlgNamedAmount, and the fold the free check / reward bargain read through)
$sumRows = [['5 hundred gold', 500], ['five hundred sept ums', 500], ['2 hundred septims', 200], ['two hundred sept ums', 200], ['1,000 septims', 1000],
    ['1 thousand gold', 1000], ['a thousand septems', 1000], ['25 hundred septims', 2500], ['fifteen hundred septims', 1500], ['2 thousand septims', 2000],
    ['here is 3 hundred sep tims', 300], ["I will pay 500 septem's", 500], ['two hundred and fifty septims', 250], ['5 hundred men', 0],
    ['I killed 2 hundred draugr', 0], ['1.5 thousand septims', 1500], ['a hundred and fifty gold', 150], ['one fifty septims', 150], ['2k gold', 2000],
    ['two grand', 2000], ['2.5k septims', 2500], ['5 grand for the sword', 5000], ["I'll give you a grand", 1000], ['two fifty septims', 250],
    ['one twenty five gold', 125], ['twenty five septims', 25], ['a fifty septim piece', 50], ['a grand hall', 0], ['the grand total is fifty gold', 50],
    ['maybe a few coins would help', 0], ['a couple hundred gold', 200], ['1,5 thousand septims', 1500],
    ['Here are all three pieces of the Razor.', 0], ['two hundred pieces of gold', 200],
    // [pt19h-money review] the bare "a grand" as an adjective is no sum
    ['what a grand and glorious day', 0], ['a grand total of fifty gold', 50],
    // [pt19h-money round 2 / lang P1, P2, P8] "a grand" never before a comma / and / total / at / in / of; "X and twenty" is 20 + X
    // ("five and twenty" read 520); "a couple of hundred" is 200 (read 100)
    ["Here's a grand total of fifty septims, look the other way.", 50], ["What a grand and noble city. Here's fifty septims, look the other way.", 50],
    ['What a grand, beautiful city.', 0], ['a grand, sweeping victory', 0], ['a grand at the palace for fifty gold', 50], ["I'll give you a grand for it", 1000],
    ['make it a grand.', 1000], ['a grand at most', 1000], ['two grand soul gems', 0], ['five and twenty septims', 25], ['one and twenty septims', 21],
    ['one hundred and fifty septims', 150], ['a hundred and one septims', 101], ['a couple of hundred septims', 200], ['a few of hundred gold', 300],
    ['a couple of septims', 0]];
$sumBad = [];
foreach ($sumRows as [$s, $want]) {
    $a = lrgIntentAmount($s, false); $b = lrgDlgNamedAmount($s); $c = lrgDlgNamedAmount(lrgDlgMoneyFold($s));
    if ($a !== $want || $b !== $want || $c !== $want) { $sumBad[] = "$s => $a/$b/$c (want $want)"; }
}
check('(a) ' . count($sumRows) . ' spoken sums read right by all three readers: "1.5 thousand" 1500 (read 5000 - OVERSTATED), "one fifty" 150 (51), "2k" 2000 (0), "two grand" 2000 (0); "a grand hall" and "a few coins" are no sum',
    $sumBad === [], implode(' | ', $sumBad));
check('(a) lrgDlgMoneyFold (owned here now) keeps his words when no sum moved, and no longer turns "1.5 thousand" into "1.five thousand"',
    lrgDlgMoneyFold('I found the septims') === 'I found the septims' && !str_contains(lrgDlgMoneyFold('1.5 thousand septims'), 'five')
    && lrgIntentAmount(lrgIntentClean('I will pay 1.5 thousand septims')) === 1500 && lrgIntentAmount(lrgIntentClean('two grand for it')) === 2000,
    lrgDlgMoneyFold('1.5 thousand septims'));
// --- (b) the engine bribe rail on a 200-septim bribe (the measurer's five sentences) and the words-reader on the want=1 path
$bribe200 = ['kind' => 'bribe', 'cost' => 200, 'class' => 'check', 'norm' => 'here take this', 'text' => 'Here, take this. (200 gold)', 'compound' => 0,
    'indexed' => 1, 'pos' => 0, 'i' => 100, 'commit' => false, 'crit' => 0, 'twat' => '', 'scripted' => 1, 'goodbye' => 0, 'variant' => 'success', 'afford' => 1];
$railOf = static function (array $e, string $u, int $pg = 5000): string {
    $st = ['utter' => ['text' => $u, 'at' => lrgNow() - 3, 'cid' => 'm1'], 'facts' => ['pg' => $pg]];
    $GLOBALS['LRG_DLG_TEST_STORE']['Money Guard'] = $st;
    return lrgDlgCheckRails(['npc' => 'Money Guard', 'cid' => 'm1', 'facts' => ['pg' => $pg], 'type' => 'inputtext', 'entries' => [$e], 'tail' => []], $e, $st);
};
$r200 = [];
foreach (['here are 2 hundred septims, look the other way' => '', 'take these two hundred sept ums and look away' => '',
    'here are two hundred septims, forget you saw me' => '', 'take 2,000 septims and forget this' => '',
    'here is one hundred septims just forget it' => 'the player offered 100, the price is 200', 'here is 1.5 hundred septims, forget it' => 'the player offered 150, the price is 200',
    'here, one fifty septims, look away' => 'the player offered 150, the price is 200'] as $u => $want) {
    $got = $railOf($bribe200, $u);
    if ($got !== $want) { $r200[] = "$u => [$got]"; }
}
check('(b) the engine bribe rail reads the spoken sum: 200 in words / STT passes, 100, "1.5 hundred" and "one fifty" are refused as less than the 200-septim price',
    $r200 === [], implode(' | ', $r200));
// --- (c) G13: a question, a price question, is no check attempt (COVERAGE G13; research/pt19h-measure.json gaps G13)
$chk = static fn(string $text, string $kind, int $cost = 0): array => ['kind' => $kind, 'cost' => $cost, 'class' => 'check', 'norm' => lrgPromptNorm($text),
    'text' => $text, 'compound' => 0, 'indexed' => 1, 'pos' => 0, 'i' => 100, 'commit' => false, 'crit' => 0, 'twat' => '', 'scripted' => 1, 'goodbye' => 0,
    'variant' => 'success', 'afford' => 1, 'topic' => '', 'quest' => ''];
$stig = $chk('Would this loosen your tongue? (40 gold)', 'bribe', 40);                                    // skyrim.esm:0E4A2E, MS10.stig.bribe
$skjor = $chk('How about I offer you some coin to come to our aid? (40 gold)', 'bribe', 40);             // companions at mirmulnir.esp:00090F
$nelacar = $chk('A priestess of Azura sent me. (Persuade)', 'persuade');                                  // skyrim.esm:02450E, DA01.nelacar.persuade
$ulfric = $chk('To Oblivion with your politics! Alduin is going to kill both of your sides! (Persuade)', 'persuade');   // skyrim.esm:0D7689
$ancarion = $chk("Yes, or he'll teach me nothing, you get no weapon and I get no gold. (Persuade)", 'persuade');      // dragonborn.esm:02455F
$brynjolf = $chk('Let me find him first. Dragons are bad for business. (Persuade)', 'persuade');         // skyrim.esm:097FCD
$tg00 = $chk("Won't have a point earning all that gold if the dragons kill you all. (Persuade)", 'persuade');
$remember = $chk('Remember now? (40 gold)', 'bribe', 40);
$changeMind = $chk('What will it cost to change your mind? (Bribe)', 'bribe', 40);
$urag = $chk('Make it 3,000 and you have a deal. (Persuade)', 'persuade');
$spare = $chk('This would be easier if you could spare one of your men. (Persuade)', 'persuade');
$rentRoom = $chk('Maybe I could rent the room? (40 gold)', 'bribe', 40);
$g13No = [];
foreach ([['how much would it cost', $stig], ['how much would it cost?', $stig], ['how much do you want for it?', $stig], ["what's your price?", $skjor],
    ['how much would it cost', $skjor], ['what if I refuse?', $skjor], ['Who is the priestess of Azura?', $nelacar], ['how much would it cost?', $nelacar],
    ['what if I refuse?', $ulfric], ['how much would it cost?', $ulfric], ['what if I refuse?', $ancarion], ['how much would it cost?', $ancarion],
    ['what if I refuse?', $brynjolf], ['how much would it cost?', $brynjolf], ['what does an emissary of Azura do?', $nelacar],
    ['a priestess of Azura sent me?', $nelacar], ['wait, a priestess of Azura sent me?', $nelacar], ['is his life in danger?', $brynjolf],
    ['how much gold would loosen your tongue?', $stig], ['name your price', $stig], ['do you want money?', $stig],
    ['what if I refuse to pay?', $skjor]] as [$u, $e]) {
    // [pt19h-money round 2 / arch P1] ...refused by the QUESTION rail itself (never the min-words rail's "whole sentence" refusal)
    $r = $railOf($e, $u);
    if (!preg_match('/^(?:a price question|his question) is no \w+ attempt - nothing is clicked/', $r)) { $g13No[] = $u . ' -> ' . $e['text'] . ' [' . $r . ']'; }
}
check('(c) G13: 22 questions / price questions / echoes EXECUTE NO CHECK (her key or the fast path): "how much would it cost" on Stig / Skjor, "what if I refuse?", "Who is the priestess of Azura?" ...',
    $g13No === [], implode(' | ', $g13No));
$g13Yes = [];
foreach ([['Would this loosen your tongue?', $stig], ['would some gold loosen your tongue', $stig], ['does this coin loosen your tongue', $stig],
    ['here, will this loosen your tongue', $stig], ['maybe this gold will loosen your tongue', $stig], ['Perhaps this will jog your memory?', $remember],
    ['what if I pay you to come to our aid', $skjor], ['how about some coin to come help us', $skjor], ['A priestess of Azura sent me.', $nelacar],
    ['I was sent by a priestess of Azura', $nelacar], ['what good is all that gold if the dragons kill you all?', $tg00],
    ['what use is your gold if the dragons kill everyone', $tg00], ['how much to change your mind', $changeMind], ["what's it gonna cost to change your mind?", $changeMind],
    ['how about three thousand septims', $urag], ['can you spare a soldier', $spare], ['Maybe I could rent the room?', $rentRoom],
    ['the courier\'s life is in danger, where is he?', $chk('His life is in danger. (Persuade)', 'persuade')],
    ['how I operate. Either do it my way, or find an idiot who does it yours.', $chk("That's how I operate. Either do it my way, or find an idiot who does it yours. (Persuade)", 'persuade')],
    ['do it my way or find another idiot', $chk("That's how I operate. Either do it my way, or find an idiot who does it yours. (Persuade)", 'persuade')]] as [$u, $e]) {
    $r = $railOf($e, $u);
    if ($r !== '') { $g13Yes[] = $u . ' -> ' . $e['text'] . ' [' . $r . ']'; }
}
check('(c) ...while 20 real attempts still pass the rail: a bribe asked as an offer ("Would this loosen your tongue?", "Perhaps this will jog your memory?"), a proposal, a rhetorical argument that says the line, a request, the price-question bribe line itself',
    $g13Yes === [], implode(' | ', $g13Yes));
// [pt19h-money review] a proposal / coin offer of NOTHING or of LATER, and "why would I pay you?", execute no bribe
$g13Neg = [];
foreach ([['what if I gave you nothing?', $stig], ['how about I pay you nothing?', $stig], ['what if I pay you later?', $stig],
    ['how about I pay you some other time?', $stig], ['why would I pay you?', $stig], ['what if I give you nothing at all?', $skjor]] as [$u, $e]) {
    if ($railOf($e, $u) === '') { $g13Neg[] = $u . ' -> ' . $e['text']; }
}
check('(c) ...and a question that offers nothing, offers it later or refuses to pay ("what if I gave you nothing?", "what if I pay you later?", "why would I pay you?") executes no bribe',
    $g13Neg === [], implode(' | ', $g13Neg));
// the whole gate on HER KEY (lrgDlgDecideEntry, mode key) - the measured path: Stig's bribe after "how much would it cost"
$GLOBALS['LRG_DLG_TEST_STORE']['Stig'] = ['utter' => ['text' => 'how much would it cost', 'at' => lrgNow() - 3, 'cid' => 'k1'], 'facts' => ['pg' => 500]];
$stigList = [$chk('Do I have to beat it out of you?', 'intimidate') + ['pos' => 0], ['pos' => 1, 'i' => 101] + $stig];
$tKey = ['npc' => 'Stig', 'cid' => 'k1', 'speech' => true, 'entries' => $stigList, 'tail' => [], 'single' => null, 'ro' => '', 'crit' => 0, 'arrest' => '',
    'facts' => ['pg' => 500], 'sid' => 's1', 'gen' => 1, 'type' => 'inputtext'];
$dKey = lrgDlgDecideEntry($tKey, $stigList[1], 'key', (array) $GLOBALS['LRG_DLG_TEST_STORE']['Stig']);
check('(c) her T-key on Stig\'s bribe after "how much would it cost" (0E4A2E, COVERAGE evidence): nothing is picked, the reason is the price question',
    ($dKey['do'] ?? '') === 'none' && str_contains((string) ($dKey['why'] ?? ''), 'price question'), json_encode($dKey));
$GLOBALS['LRG_DLG_TEST_STORE']['Stig']['utter']['text'] = 'would some gold loosen your tongue';
$dKey2 = lrgDlgDecideEntry($tKey, $stigList[1], 'key', (array) $GLOBALS['LRG_DLG_TEST_STORE']['Stig']);
check('(c) ...and "would some gold loosen your tongue" still executes it on her key (the bribe asked as an offer)', ($dKey2['do'] ?? '') === 'pick', json_encode($dKey2));
check('(c) the free bribe check: a price question with a figure in it is no offer ("how much - two hundred septims to look the other way?" names no price he pays)',
    lrgDlgPriceQuestion('how much would it cost, two hundred septims to look the other way?') && !lrgDlgPriceQuestion("I don't care how much it costs, here's 200 gold"));
// --- (d) G14: the price outside a "(N gold)" tag (COVERAGE G14; research/pt19h-measure.json gaps G14 - the six evidence lines)
$g14 = [];
foreach ([["Here's the 500 gold.", 500], ["Here's the 300 gold.", 300], ['Buy unusual gem for 1000 gold.', 1000], ["I'm the Arch-Mage. It's 2,000 coins.", 2000],
    ["Alright, here's your money. (Pay 1000 coins)", 1000], ['Here, have 100 septims and teach me what you know about music.', 100], ["I'll take that claw for 50 gold.", 50],
    ["No problem. Here's 500 gold.", 500],
    // never a figure he receives, a wager, an answer, a question, a negation, a check (the hand-reviewed 15 of the measurer, and their kin)
    ['Sell unusual gem for 50 gold.', 0], ['20,000 gold.', 0], ['10,000 gold. (Lie)', 0], ['Sorry, no joke. 5,000 gold. (Lie)', 0], ['You want... 5000 septims?', 0],
    ["6000 gold, and they're yours.", 0], ['Just tell me how much it will cost.', 0], ["I don't know the Fear spell.", 0], ['It matters not, this ends now. (Attack)', 0],
    ["I don't have 10,000 gold.", 0], ['1000 gold!  But I sold it to you for 50 gold.', 0], ["It's yours for 500 gold.", 0], ["I think I've earned that 100 gold.", 0],
    ['How about 100 gold?', 0], ['I wouldn\'t do this for any less than 800 gold. (Persuade)', 0], ["Here's the gold.", 0]] as [$line, $want]) {
    $got = lrgDlgProseCost($line, '');
    if ($got !== $want) { $g14[] = "$line => $got (want $want)"; }
}
check('(d) G14: the price in the line\'s own words - 06F999 500, 06F99A 300, 00080B 1000, 0126D7 2000 - and never a sum he receives, a wager, a question or a check line', $g14 === [], implode(' | ', $g14));
$GLOBALS['LRG_DLG_TEST_STORE']['Tolfdir'] = ['lines' => [['k' => 'a', 'gen' => 1, 't' => "Then, as I've said, 1000 gold will be required to set things right.", 'at' => lrgNow() - 5]]];
$GLOBALS['LRG_DLG_TEST_STORE']['Tolfdir B'] = ['lines' => [['k' => 'b', 'gen' => 1, 't' => "As I've said, a donation of 250 should help take care of that.", 'at' => lrgNow() - 5]]];
$GLOBALS['LRG_DLG_TEST_STORE']['Tolfdir C'] = ['lines' => [['k' => 'c', 'gen' => 1, 't' => 'A donation of 500 gold will clear it up.', 'at' => lrgNow() - 600]]];
check('(d) G14: "Here\'s the gold you wanted." (0C9A08, the rejoin fine) is priced from HER words that led to it - 1000 or 250 - and never from a line of ten minutes ago',
    lrgDlgProseCost("Here's the gold you wanted.", 'Tolfdir') === 1000 && lrgDlgProseCost("Here's the gold you wanted.", 'Tolfdir B') === 250
    && lrgDlgProseCost("Here's the gold you wanted.", 'Tolfdir C') === 0 && lrgDlgProseCost("Here's the gold.", 'Vex') === 0);
$pay = lrgDlgDecorateEntries(lrgDlgParseEntries("0~100~0~-~Here's the 500 gold.~~1~101~0~-~I don't have that much gold.~~2~102~0~-~I'm not ready to pay the fine.", 1),
    [null, null, null], ['pg' => 300, 'npc' => 'Nazir'], []);
check('(d) G14 through the list builder: "Here\'s the 500 gold." is cost 500, class pay, a commit (>= 100 septims asks, S4.10), afford 0 on a 300-septim purse; its siblings stay unpriced',
    (int) $pay[0]['cost'] === 500 && $pay[0]['class'] === 'pay' && !empty($pay[0]['commit']) && (int) $pay[0]['afford'] === 0
    && (int) $pay[1]['cost'] === 0 && (int) $pay[2]['cost'] === 0, json_encode(array_map(static fn($x) => [$x['cost'], $x['class'], $x['commit'], $x['afford']], $pay)));
$GLOBALS['LRG_DLG_TEST_STORE']['Nazir'] = ['utter' => ['text' => "here's the 500 gold", 'at' => lrgNow() - 3, 'cid' => 'n1'], 'facts' => ['pg' => 300]];
$tPay = ['npc' => 'Nazir', 'cid' => 'n1', 'speech' => true, 'entries' => $pay, 'tail' => [], 'single' => null, 'ro' => '', 'crit' => 0, 'arrest' => '',
    'facts' => ['pg' => 300], 'sid' => 's1', 'gen' => 1, 'type' => 'inputtext'];
$dPay = lrgDlgDecideEntry($tPay, $pay[0], 'key', (array) $GLOBALS['LRG_DLG_TEST_STORE']['Nazir']);
check('(d) ...so the afford rail runs on it: 300 septims in his purse, the 500-septim line is never clicked (the refusal is the afford code)',
    ($dPay['do'] ?? '') === 'none' && function_exists('lrgDlgRefusalCode') && lrgDlgRefusalCode((string) $dPay['why']) === 'afford', json_encode($dPay));
$tPay['facts']['pg'] = 900;
$GLOBALS['LRG_DLG_TEST_STORE']['Nazir']['facts']['pg'] = 900;
$pay9 = lrgDlgDecorateEntries(lrgDlgParseEntries("0~100~0~-~Here's the 500 gold.~~1~101~0~-~I don't have that much gold.", 1), [null, null], ['pg' => 900, 'npc' => 'Nazir'], []);
$tPay['entries'] = $pay9;
check('(d) ...and with 900 septims his plain "here\'s the 500 gold" is never explicit (>= 100 septims always asks, S4.10): she asks, quoting it',
    !lrgDlgExplicit($tPay, $pay9[0], "here's the 500 gold", 'words', (array) $GLOBALS['LRG_DLG_TEST_STORE']['Nazir'])
    && in_array((string) (lrgDlgDecideEntry($tPay, $pay9[0], 'key', (array) $GLOBALS['LRG_DLG_TEST_STORE']['Nazir'])['do'] ?? ''), ['park', 'still'], true));
// --- (e) S6.1: the reward line only when he bargains (the critic's eleven, the measurer's 30 ordinary sentences and 12 bargains)
$rwT = ['npc' => 'Farengar', 'on' => true, 'entries' => [], 'tail' => []];
$rwNone = ["You don't have to pay me.", 'No need to pay me.', "I'll do it for free.", "Ulfric won't negotiate.", 'The guards are not enough.', 'Are you paying attention?',
    'Is there more gold in the barrow?', 'Let me explore a bit more.', 'What do I get out of the barrow?', 'I want more of that.', 'Will you pay your respects to the fallen?',
    'I need a bit more time.', 'Tell me more about the dragon.', 'Is there more to the story?', 'The bandits paid for their crimes.', "You don't owe me anything.",
    "I don't want any money.", 'Keep your gold.', "I'll do it for nothing.", 'Consider it a favor.', 'The price of failure is death.', 'He paid me a visit last night.',
    'Pay attention to the road.', 'I would like more of that mead.', 'Can you give me more details?', 'Is there anything more I should know?',
    'They want more soldiers at the front.', "Don't worry about paying me.", 'The Jarl will reward the men who fought.', 'I found more gold in the mine.',
    'What do I get to see in the museum?', 'Could you tell me a little more?', 'I owe you one.', 'The reward was posted in Riften.', 'Who paid you to kill me?',
    "I'm not in it for the money.", 'Is the pay good for the guards here?', 'The Thalmor paid him off.', "What's in the chest?", 'What else is in the barrow?',
    "I've got more questions.", 'A reward was offered for his head.', 'I heard the bonus was paid out.', 'They expect to be paid.', "I don't expect to be paid.",
    'I was paid well by the Jarl.'];
$rwLeak = [];
foreach ($rwNone as $u) { $h = lrgDlgRewardAsk($u, $rwT); if ($h !== '') { $rwLeak[] = "$u [$h]"; } }
check('(e) S6.1: ' . count($rwNone) . ' ordinary sentences carry NO reward line - "The reward was posted in Riften." (the measured leak), "you don\'t have to pay me", "for free", "are you paying attention", a third-person "negotiate" / "not enough" ...',
    $rwLeak === [], implode(' | ', $rwLeak));
$rwBarg = ["What's in it for me?", 'I want more gold.', 'Make it two hundred septims.', 'I deserve more.', 'How about a little more?', 'Pay me first.',
    'What do I get for killing the dragon?', "That's not enough, I want more.", 'Double it and we have a deal.', 'I expect to be paid well for this.',
    'Make it worth my while.', 'I want 500 septims for this.', "I'd like to be paid up front.", 'I should be paid more for this.', "Don't I deserve to be compensated?",
    'My reward was too small.', 'The reward is not enough.', 'I want the reward that was promised.', 'Can we negotiate?', 'I want two grand for this.',
    'The reward was promised, where is it?', 'The reward has been set too low.'];   // [pt19h-money review] a claim on the reward, a word of too little after the participle
$rwMiss = [];
foreach ($rwBarg as $u) { if (lrgDlgRewardAsk($u, $rwT) === '') { $rwMiss[] = $u; } }
check('(e) ...while ' . count($rwBarg) . ' bargains ARE read: "I expect to be paid well for this." (the measured miss), "my reward was too small", "I want two grand for this" ...',
    $rwMiss === [], implode(' | ', $rwMiss));
// --- (f) gate B's reward class (lib/lrg_replies.php, off in gate A): her bonus figure is read in words and whole
$rvB = static fn(string $s, int $give): string => (string) (lrgNfVerdict('reward', $s, ['t' => ['check' => ['reward' => 1, 'result' => 'pass', 'award' => true,
    'give' => $give]], 'stages' => []], ['id' => 'reward'], 'reward')['verdict'] ?? '');
check('(f) gate B: "I\'ll add fifty septims on top." is FALSE when the check gave 30 (the digits-only read passed it), true at 50; "1,000 septims extra" is 1000, not 1',
    $rvB("I'll add fifty septims on top.", 30) === 'false' && $rvB("I'll add fifty septims on top.", 50) === 'true'
    && $rvB('Here, 1,000 septims extra.', 1000) === 'true' && $rvB('Here, 1,000 septims extra.', 1) === 'false' && $rvB("I'll add a little extra on top.", 30) === 'true',
    json_encode([$rvB("I'll add fifty septims on top.", 30), $rvB("I'll add fifty septims on top.", 50), $rvB('Here, 1,000 septims extra.', 1000), $rvB('Here, 1,000 septims extra.', 1)]));

// ==================================================================================================================================
// [pt19h-quest] G17 / G18 (research/pt19h-quest.md): the click-free quest-entry TABLE (PROTOCOL 10.26 extended,
// config/lrg_quest_entries.default.json) and the enlistment hand-off's G18 / G1 / G5 / G11 fixes (lib/lrg_factions.php).
// The measurer's concrete examples (research/pt19h-measure.md, G17 / G18 / G1 rows) are asserted as must-resolve or
// must-not-click rows.
echo "43. [pt19h-quest] the click-free quest-entry TABLE (G17) and faction words that took quest lines (G18)\n";
require_once __DIR__ . '/../server/lorerim_glue/lib/lrg_dialogue.php';
$GLOBALS['PLAYER_NAME'] = 'Jordan';
$resetMem();
$GLOBALS['LRG_DLG_STATE'] = [];
$GLOBALS['LRG_DLG_TEST_STORE'] = [];
unset($GLOBALS['LRG_VOICED'], $GLOBALS['LRG_FUNCRET'], $GLOBALS['LRG_FAC_QE_SENT'], $GLOBALS['LRG_DLG_TURN'], $GLOBALS['LRG_FAC_QE_TEST_ROWS'], $GLOBALS['HERIKA_NAME']);
lrgDlgPut('*install*', ['clicks_ok' => 0]);
// (a) the shipped table: only the keys the game re-checks, the speaker pinned, never lower, never an avoid row
$qeRaw = json_decode((string) file_get_contents(LRG_DIR . '/config/lrg_quest_entries.default.json'), true);
$qeRows = lrgFacQeRows();
check('(a) config/lrg_quest_entries.default.json parses and all 8 rows survive the loader', is_array($qeRaw) && count((array) ($qeRaw['rows'] ?? [])) === 8
    && count($qeRows) === 8, count($qeRows) . ' of ' . count((array) ($qeRaw['rows'] ?? [])));
$qeAvoid = ['skyrim.esm:03B69C', 'skyrim.esm:03F883', 'skyrim.esm:04DE3C', 'skyrim.esm:04FA45', 'skyrim.esm:0504C4', 'skyrim.esm:053A51',
    'skyrim.esm:05A6B1', 'skyrim.esm:05B379', 'skyrim.esm:05C614', 'skyrim.esm:05F3F9', 'skyrim.esm:06A79B', 'skyrim.esm:0A8BEA', 'skyrim.esm:0C64EC',
    'skyrim.esm:0DDE89', 'skyrim.esm:0E0B93', 'thechoiceisyours.esp:0048A5'];
$qeBad = [];
foreach ($qeRows as $r) {
    $c = (array) $r['conds'];
    $id = (string) $r['id'];
    if (array_diff(array_keys($c), ['isid', 'qdone', 'notdone', 'max', 'qnd'])) { $qeBad[] = $id . ':keys'; }
    if ((int) ($c['isid'] ?? 0) <= 0 || (int) $c['max'] <= 0 || (int) $c['max'] >= (int) $r['stage']) { $qeBad[] = $id . ':isid/max'; }
    if (!in_array((int) $r['stage'], array_map('intval', (array) ($c['notdone'] ?? [])), true)) { $qeBad[] = $id . ':notdone lacks the stage'; }
    if ((string) ($r['grade'] ?? '') !== 'plain' || in_array(strtolower((string) $r['info']), array_map('strtolower', $qeAvoid), true)) { $qeBad[] = $id . ':grade/avoid'; }
    foreach (['line', 'meaning', 'say', 'hint', 'entry'] as $cell) {
        if (trim((string) ($r[$cell] ?? '')) === '' || preg_match('/[<>]/', (string) $r[$cell])) { $qeBad[] = $id . ':' . $cell; }
    }
    if (strlen((string) $r['hint']) > 100 || preg_match('/[;=@|]/', (string) $r['hint'])) { $qeBad[] = $id . ':hint'; }
    if (preg_match('/\b(?:MQ|CW|DA|TG)\d/', (string) $r['meaning'] . ' ' . (string) $r['say'])) { $qeBad[] = $id . ':quest id in her words'; }
}
check('(a) every row: only isid / qdone / notdone / max / qnd (what CmdQuestEntry re-checks), isid set, 0 < max < stage, notdone carries the'
    . ' stage, graded plain, none of the 18 avoid rows, bracket-free cells, a hint the wire can carry, no quest id in her words', $qeBad === [], implode(',', $qeBad));
$GLOBALS['LRG_FAC_QE_TEST_ROWS'] = [['id' => 'x.done', 'npc' => 'Arngeir', 'quest' => 'MQ204', 'stage' => 50, 'line' => 'I need to learn the Shout again now.',
    'conds' => ['isid' => 181959, 'done' => [40], 'max' => 49]], ['id' => 'x.low', 'npc' => 'Arngeir', 'quest' => 'MQ204', 'stage' => 20,
    'line' => 'I need to learn the Shout used to defeat Alduin.', 'conds' => ['isid' => 181959, 'max' => 20]], ['id' => 'x.short', 'npc' => 'Arngeir',
    'quest' => 'MQ204', 'stage' => 20, 'line' => 'Well?', 'conds' => ['isid' => 181959, 'max' => 19]]];
check('(a) the loader skips a row with a key the game cannot re-check (done=), a max that could lower the quest, and a sentence under three words',
    lrgFacQeRows() === []);
unset($GLOBALS['LRG_FAC_QE_TEST_ROWS']);
// (b) queued: Arngeir, an ambient scene actor the game cannot open on yet (clicks_ok 0), his exact sentence, MQ204 at 10 on fresh facts
$GLOBALS['LRG_DLG_MCM'] = ['ml' => 1, 'io' => 1, 'lf' => 1];
$qeT = static fn(string $npc, string $cid, array $over = []): array => array_replace(['npc' => $npc, 'cid' => $cid, 'type' => 'inputtext', 'speech' => true,
    'talk' => false, 'on' => true, 'ambient' => 1, 'open' => 0, 'entries' => [], 'tail' => [], 'snap' => [], 'q' => [], 'clicks_ok' => 0, 'list' => 'none',
    'facts' => ['qst' => ['MQ204' => 10, 'MQ205' => 10, 'MQ301' => 10, 'CW02B' => 10], 'at' => lrgNow() - 5]], $over);
$qe1 = $qeT('Arngeir', 'qe1');
$qe1['faction'] = lrgFacTurn($qe1, 'Arngeir, I need to learn the Shout used to defeat Alduin.', true);
$qp1 = (array) ($qe1['faction']['plan'] ?? []);
check('(b) "Arngeir, I need to learn the Shout used to defeat Alduin." -> the MQ204.arngeir.shout row QUEUED, never licensed, nothing executed pre-LLM',
    ($qp1['state'] ?? '') === 'queued' && empty($qp1['licensed']) && ($qe1['faction']['executed'] ?? null) === [] && (string) (($qe1['faction']['qe'] ?? [])['row'] ?? '') === 'MQ204.arngeir.shout',
    json_encode($qp1));
check('(b) ...the param: quest=MQ204;stage=20;isid=181959;notdone=20;max=19;entry=MQ204ArngeirIntroTopic (the conds the game re-checks)',
    str_contains((string) ($qp1['param'] ?? ''), ';quest=MQ204;stage=20;isid=181959;notdone=20;max=19;entry=MQ204ArngeirIntroTopic;'), (string) ($qp1['param'] ?? ''));
$qeLock = lrgFacLockedLines($qe1);
check('(b) ...her locked line: the game is being asked and has NOT confirmed it - no quest id, no stage number, no "done"',
    count($qeLock) === 1 && str_contains($qeLock[0], 'has NOT confirmed it yet') && !preg_match('/MQ204|\d/', $qeLock[0]), json_encode($qeLock));
check('(b) ...the rule forbids an invented step, the turn line says qe=<row>:queued, and no open goes out beside it (one carrier)',
    str_contains(lrgFacRule($qe1), 'Never say the step he just asked for is done') && lrgFacTurnTail($qe1) === ' qe=MQ204.arngeir.shout:queued'
    && lrgFacRefusesOpen($qe1, 'qe1', '') && lrgFacClickAsk($qe1, '') === []);
$GLOBALS['LRG_DLG_TURN'] = $qe1;
$qeW = lrgPostProcessActions([]);
check('(b) ...lrgPostProcessActions appends ONE ExtCmdLRG_QuestEntry line (D1 only) and records facexec for the pending guard',
    count($qeW) === 1 && lrgLineCode((string) $qeW[0]) === LRG_ACT_QUESTENTRY && str_starts_with((string) $qeW[0], 'Arngeir|command|ExtCmdLRG_QuestEntry@ok=1;cid=qe1;')
    && (string) ((lrgDlgState('Arngeir')['facexec'] ?? [])['quest'] ?? '') === 'MQ204', json_encode($qeW));
// (c) the game's OK is VOICED from the row's meaning / say (never licensed); a refusal says "the step", never "enlistment"
$qeP = explode('@', rtrim((string) $qeW[0], "\r\n"), 2)[1];
$qeOk = 'command@' . LRG_ACT_QUESTENTRY . '@' . $qeP . ';qs=20;qj=1@OK: MQ204 now at stage 20 (10 before): MQ204 stage 20';
$GLOBALS['gameRequest'] = ['funcret', time(), 100, $qeOk];
lrgRecordResult($qeOk);
check('(c) the OK is voiced: pass, success, the fact is the row\'s meaning, exec_qst MQ204 20 written',
    lrgFuncretVerdict($qeOk) === 'pass' && !empty($GLOBALS['LRG_VOICED']['success']) && str_contains((string) ($GLOBALS['LRG_VOICED']['fact'] ?? ''), 'Arngeir has heard him ask')
    && (int) ((lrgDlgState('Arngeir')['exec_qst'] ?? [])['stage'] ?? 0) === 20, json_encode($GLOBALS['LRG_VOICED'] ?? null));
check('(c) ...the directive carries her one line (the row\'s say) and no word about the game', str_contains(lrgVoicedDirective((array) $GLOBALS['LRG_VOICED']),
    'where he learned of that Shout') && str_contains(lrgVoicedDirective((array) $GLOBALS['LRG_VOICED']), 'never a word about the game'));
unset($GLOBALS['LRG_VOICED'], $GLOBALS['LRG_FUNCRET']);
$qeF = lrgVoicedFact(['npc' => 'Arngeir', 'code' => LRG_ACT_QUESTENTRY, 'do' => 'entry', 'reason' => 'MQ204 is already past that (stage 30)', 'kv' => ['quest' => 'MQ204', 'stage' => 20]]);
check('(c) a refusal of a TABLE row: "nothing was recorded for the step Jordan asked for" - that matter already stands further on (never "behind him":'
    . ' another branch may have moved the quest on, r2 arch P4) - never "enlistment", never MQ204',
    str_contains($qeF, 'nothing was recorded for the step Jordan asked for') && str_contains($qeF, 'already stands further on than that step')
    && !str_contains($qeF, 'behind him') && !preg_match('/enlistment|MQ204/', $qeF), $qeF);
check('(c) ...Tullius\'s enlistment row keeps its own words', str_contains(lrgVoicedFact(['npc' => 'General Tullius', 'code' => LRG_ACT_QUESTENTRY, 'do' => 'entry',
    'reason' => 'CW00A is not running', 'kv' => ['quest' => 'CW00A', 'stage' => 10]]), "nothing was recorded for Jordan's enlistment"));
check('(c) ...the table=1 mark the net leaves in qe_lic words it without the table (the funcret is decided PRE-LOCK, lib/lrg_factions.php not loaded)',
    (int) ((lrgMemGet('Arngeir')['qe_lic'] ?? [])['table'] ?? 0) === 1 && (static function (): bool {
        lrgMemSet('Qe Prelock', ['qe_lic' => ['cid' => 'qp', 'lic' => 0, 'quest' => 'XX01', 'stage' => 5, 'table' => 1]]);
        return str_contains(lrgVoicedWhat(['npc' => 'Qe Prelock', 'code' => LRG_ACT_QUESTENTRY, 'do' => 'entry', 'kv' => ['quest' => 'XX01', 'stage' => 5]]),
            'nothing was recorded for the step Jordan asked for');
    })());
check('(c) lrgFacEffectFor finds the table row (the funcret side) and still finds Tullius first',
    !empty(lrgFacEffectFor('MQ204', 20)['table']) && (string) lrgFacEffectFor('MQ204', 20)['row'] === 'qe:MQ204.arngeir.shout'
    && (string) (lrgFacEffectFor('CW00A', 10)['row'] ?? '') === 'legion');
// (d) the next ask: exec_qst says 20 -> "already", nothing sent; pending inside the window -> nothing sent
$qe2 = $qeT('Arngeir', 'qe2');
$qe2['faction'] = lrgFacTurn($qe2, 'I need to learn the Shout used to defeat Alduin', true);
check('(d) asked again after the game\'s OK: already (exec_qst), nothing sent, her line says the game has him at that step',
    (string) ($qe2['faction']['plan']['state'] ?? '') === 'already' && (string) ($qe2['faction']['plan']['param'] ?? 'x') === ''
    && str_contains((string) (lrgFacLockedLines($qe2)[0] ?? ''), 'already has him at that step'), json_encode($qe2['faction']['plan'] ?? null));
// [pt19h-quest r2 / arch P4] an alternative branch: MQ301 at 20 (Paarthurnax's branch), Esbern's plan (stage 18, max 17) never done -
// the line says only that the matter stands further on and the step cannot be recorded, never that the step is behind him
$qeAlt = $qeT('Esbern', 'qeAlt', ['facts' => ['qst' => ['MQ301' => 20], 'at' => lrgNow() - 5]]);
$qeAlt['faction'] = lrgFacTurn($qeAlt, "Don't worry. I have a plan. I'm going to trap a dragon in Dragonsreach.", true);
$qeAltL = (string) (lrgFacLockedLines($qeAlt)[0] ?? '');
check('(d) [r2 arch P4] past the stage on another branch (MQ301 20, the row\'s stage 18): already - "stands further on in this matter and cannot record'
    . ' that step now", never "past that step" / "at that step", no quest id, no digit',
    (string) ($qeAlt['faction']['plan']['state'] ?? '') === 'already' && str_contains($qeAltL, 'already stands further on in this matter and cannot record that step now')
    && !str_contains($qeAltL, 'past that step') && !str_contains($qeAltL, 'at that step') && !preg_match('/MQ301|\d/', $qeAltL), $qeAltL);
lrgDlgPut('Esbern', ['facexec' => ['quest' => 'MQ301', 'stage' => 18, 'at' => lrgNow() - 10, 'cid' => 'qeX', 'done' => 0, 'lic' => 0]]);
$qe3 = $qeT('Esbern', 'qe3');
$qe3['faction'] = lrgFacTurn($qe3, "Don't worry. I have a plan. I'm going to trap a dragon in Dragonsreach.", true);
check('(d) the same entry went out 10 s ago and has not answered: pending, nothing sent again',
    (string) ($qe3['faction']['plan']['state'] ?? '') === 'pending' && (string) ($qe3['faction']['plan']['param'] ?? 'x') === '', json_encode($qe3['faction']['plan'] ?? null));
$qe4 = $qeT('Paarthurnax', 'qe4', ['facts' => ['qst' => ['MQ301' => 10], 'at' => lrgNow() - 900]]);
$qe4['faction'] = lrgFacTurn($qe4, 'I need to find out where Alduin went.', true);
$qe5 = $qeT('Paarthurnax', 'qe5', ['facts' => ['qst' => ['MQ204' => 200], 'at' => lrgNow() - 5]]);
$qe5['faction'] = lrgFacTurn($qe5, 'I need to find out where Alduin went.', true);
check('(d) a stale facts line (900 s) and a quest not on it: nothing sent, her line says nothing about that step has been recorded (never silent)',
    (string) ($qe4['faction']['plan']['state'] ?? '') === 'stale' && (string) ($qe5['faction']['plan']['state'] ?? '') === 'no-stage'
    && str_contains((string) (lrgFacLockedLines($qe4)[0] ?? ''), 'nothing about that step has been recorded'), json_encode([$qe4['faction']['plan'] ?? null, $qe5['faction']['plan'] ?? null]));
// (e) the driver can click: the real line wins, the table stays out (an ordinary turn)
$qe6 = $qeT('Arngeir', 'qe6', ['ambient' => 0, 'facts' => ['qst' => ['MQ204' => 10], 'at' => lrgNow() - 5]]);
$qe7 = $qeT('Arngeir', 'qe7', ['open' => 1, 'facts' => ['qst' => ['MQ204' => 10], 'at' => lrgNow() - 5]]);
$qe8 = $qeT('Arngeir', 'qe8', ['clicks_ok' => 1, 'facts' => ['qst' => ['MQ204' => 10], 'at' => lrgNow() - 5]]);
$GLOBALS['LRG_DLG_STATE'] = [];
$GLOBALS['LRG_DLG_TEST_STORE'] = [];
check('(e) menuless on and she is no scene actor / a menu of hers is open / an ambient actor the game may open on: the table stays out (the E-press, the list, the open carry the real line)',
    lrgFacTurn($qe6, 'I need to learn the Shout used to defeat Alduin.', true) === [] && lrgFacTurn($qe7, 'I need to learn the Shout used to defeat Alduin.', true) === []
    && lrgFacTurn($qe8, 'I need to learn the Shout used to defeat Alduin.', true) === []);
$GLOBALS['LRG_DLG_MCM'] = ['ml' => 0, 'io' => 1, 'lf' => 1];
check('(e) ...menuless off (ml=0, as 10.26): the driver cannot click, the table carries it', (string) ((lrgFacTurn($qe6, 'I need to learn the Shout used to defeat Alduin.', true)['plan'] ?? [])['state'] ?? '') === 'queued');
$GLOBALS['LRG_DLG_MCM'] = ['ml' => 1, 'io' => 1, 'lf' => 1];
// [pt19h-quest r2 / arch P3, S2.3] OPEN FIRST only when the open brings THIS line: an ambient actor the game may open on (clicks_ok 1)
// whose line sits below a top-level topic (Esbern's lure, 4 levels down) or has too few meaning words for the open's top-level clause
// (Galmar's "What's the mission?") - the table carries it, and no open goes out beside it; her cached root carrying the line - open first
$qeA = static fn(string $npc, string $cid): array => $qeT($npc, $cid, ['clicks_ok' => 1, 'facts' => ['qst' => ['MQ301' => 10, 'CW02B' => 10, 'MQ204' => 10], 'at' => lrgNow() - 5]]);
$GLOBALS['LRG_DLG_STATE'] = [];
$GLOBALS['LRG_DLG_TEST_STORE'] = [];
$qeL = $qeA('Esbern', 'qeL');
$qeL['faction'] = lrgFacTurn($qeL, 'so if i could lure a dragon into dragons reach', true);
$qeG = $qeA('Galmar Stone-Fist', 'qeG');
$qeG['faction'] = lrgFacTurn($qeG, "What's the mission?", true);
check('(e) [r2 arch P3] ambient, the open possible, but the open cannot bring the line (Esbern\'s lure: below a top-level topic; Galmar\'s "What\'s the'
    . ' mission?": one meaning word, no open clause fires): QUEUED, and no open beside it (one carrier)',
    (string) (($qeL['faction']['plan'] ?? [])['state'] ?? '') === 'queued' && (string) (($qeL['faction']['qe'] ?? [])['row'] ?? '') === 'MQ301.esbern.lure'
    && (string) (($qeG['faction']['plan'] ?? [])['state'] ?? '') === 'queued' && lrgFacRefusesOpen($qeL, 'qeL', '') && lrgFacRefusesOpen($qeG, 'qeG', ''),
    json_encode([$qeL['faction']['plan']['why'] ?? null, $qeG['faction']['plan']['why'] ?? null]));
lrgDlgPut('Esbern', ['root' => ['at' => lrgNow() - 30, 'entries' => [['pos' => 0, 'text' => 'So if I could lure a dragon into Dragonsreach...', 'class' => 'plain',
    'topic' => 'MQ301EsbernLureTopic'], ['pos' => 1, 'text' => 'Goodbye.', 'class' => 'back', 'topic' => 'X']]]]);
check('(e) [r2 arch P3] ...her cached root list carries the line: open first (the table stays out, the open brings the real line)',
    lrgFacTurn($qeA('Esbern', 'qeL2'), 'so if i could lure a dragon into dragons reach', true) === []);
$GLOBALS['LRG_DLG_STATE'] = [];
$GLOBALS['LRG_DLG_TEST_STORE'] = [];
// (f) HIS EXPLICIT SENTENCE only: every shape the rules name never queues a scripted line (the coverage's never rows included)
$qeNever = [['Arngeir', 'I need to learn the Shout used to defeat Alduin?'], ['Arngeir', 'wait, I need to learn the Shout used to defeat Alduin?'],
    ['Arngeir', 'not now, I need to learn the Shout used to defeat Alduin later'], ['Arngeir', 'no, I need to learn the Shout used to defeat Alduin'],
    ['Arngeir', "I don't need to learn the Shout used to defeat Alduin"], ['Arngeir', 'maybe I need to learn the Shout used to defeat Alduin'],
    ['Arngeir', 'is it true that I need to learn the Shout used to defeat Alduin'], ['Arngeir', 'what Shout defeated Alduin'], ['Arngeir', 'I need to learn a Shout'],
    ['Arngeir', 'I need to learn the Shout used to defeat Alduin if you pay me fifty septims'], ['Arngeir', "why didn't you tell me about him?"],
    ['Esbern', 'I have no plan at all'], ['Esbern', "Don't worry. I have a plan. I'm going to trap a dragon in Dragonsreach?"], ['Esbern', "I don't care about any Elder Scroll"],
    ['Esbern', 'Dragonsreach is just a palace'], ['Paarthurnax', 'Alduin is dead'], ['Galmar Stone-Fist', 'how did the last mission go?'],
    ['Delphine', 'I need to learn the Shout used to defeat Alduin.'], ['Arngeir', 'So if I could lure a dragon into Dragonsreach later']];
$qeClicked = [];
foreach ($qeNever as [$n, $u]) {
    $GLOBALS['LRG_DLG_STATE'] = [];
    $GLOBALS['LRG_DLG_TEST_STORE'] = [];
    $x = lrgFacTurn($qeT($n, 'qn'), $u, true);
    if ((string) (($x['plan'] ?? [])['state'] ?? '') === 'queued') { $qeClicked[] = $n . ': ' . $u; }
}
check('(f) ' . count($qeNever) . ' echoes, questions, deferrals, refusals, negations, hedges, near-misses, a bargain, the wrong speaker and the coverage never rows queue NOTHING',
    $qeClicked === [], implode(' | ', $qeClicked));
$qeSay = [['Arngeir', 'i need to learn the shout used to defeat all do in', 'MQ204.arngeir.shout'], ['Arngeir', 'No, but he told me how to find out.', 'MQ205.arngeir.howfind'],
    ['Esbern', 'Any idea where to find the Elder Scroll?', 'MQ205.elderscroll.ask'], ['Esbern', "Don't worry. I have a plan. I'm going to trap a dragon in Dragonsreach.", 'MQ301.esbern.plan'],
    ['Paarthurnax', 'I need to find out where Alduin went.', 'MQ301.paar.where'], ['Arngeir', 'So if I could lure a dragon into Dragonsreach...', 'MQ301.arngeir.lure'],
    ['Esbern', 'so if i could lure a dragon into dragons reach', 'MQ301.esbern.lure'], ['Galmar Stone-Fist', "What's the mission?", 'CW02B.galmar.mission']];
$qeMiss = [];
foreach ($qeSay as [$n, $u, $row]) {
    $GLOBALS['LRG_DLG_STATE'] = [];
    $GLOBALS['LRG_DLG_TEST_STORE'] = [];
    $x = lrgFacTurn($qeT($n, 'qs'), $u, true);
    if ((string) (($x['plan'] ?? [])['state'] ?? '') !== 'queued' || (string) (($x['qe'] ?? [])['row'] ?? '') !== $row) { $qeMiss[] = $n . ': ' . $u; }
}
check('(f) ...and the rows\' own sentences (verbatim, an STT form, the same line on Arngeir vs Esbern) resolve to THEIR row', $qeMiss === [], implode(' | ', $qeMiss));
// (g) G18 - faction words took quest lines. The measurer's rows through the want=1 hand-off (lrgFacArbitrateWant): null = it stands
// aside (the ordinary matchers decide with every rail), false = nothing is clicked (her words answer), [i] = that entry by index
$GLOBALS['LRG_DLG_STATE'] = [];
$GLOBALS['LRG_DLG_TEST_STORE'] = [];
$qeE = static function (array $lines): array {
    $out = [];
    foreach ($lines as $i => $l) {
        [$topic, $text, $class] = $l + [2 => 'plain'];
        $out[] = ['pos' => $i, 'i' => 100 + $i, 'text' => $text, 'norm' => lrgPromptNorm($text), 'class' => $class, 'topic' => $topic, 'commit' => $class === 'commit'];
    }
    return $out;
};
$qeWant = static fn(string $npc, array $pool, string $u) => lrgFacArbitrateWant($npc, $pool, ['utter' => ['text' => $u]], '', 'g18');
// [pt19h r2] END TO END: his sentence, then her list with want=1 through lrgDlgAnswerWant (the hand-off, then every rail) - what the game
// sees. The hand-off has two answers to words that may not act (use P3): a refusal or a deferral -> false (nothing, her words answer); a
// hedge, an echo or an advice question -> the ONE join line handed to the commit rail, which clicks a commit only on his plain sentence
// (the fast path's word: "the model asks, quoting it"; the gate then parks it). Either way NOTHING is clicked. True = a do=pick went out.
$qeFast = static function (string $npc, array $pool, string $u): bool {
    $GLOBALS['LRG_DLG_STATE'] = [];
    $GLOBALS['LRG_DLG_TEST_STORE'] = [];
    $GLOBALS['LRG_DLG_TEST_QUEUE'] = [];
    lrgDlgPut('*install*', ['clicks_ok' => 1]);
    lrgDlgPut($npc, ['utter' => ['text' => $u, 'at' => lrgNow(), 'cid' => 'g18w', 'type' => 'inputtext'], 'facts' => ['pg' => 500, 'at' => lrgNow()]]);
    $ents = [];
    foreach ($pool as $e) {
        $ents[] = $e + ['cost' => 0, 'crit' => 0, 'kind' => '', 'afford' => 1, 'scripted' => 0, 'goodbye' => 0, 'indexed' => 1, 'hand' => '', 'new' => 0,
            'col' => 0, 'tail' => 0, 'invis' => 0, 'compound' => 0, 'twat' => '', 'walkaway' => 0, 'label' => ''];
    }
    $saved = $GLOBALS['gameRequest'] ?? null;
    $GLOBALS['gameRequest'] = ['lrg_topics', lrgNow(), 1, ''];
    ob_start();
    lrgDlgAnswerWant($npc, ['cid' => 'g18w', 'ask' => ''], ['sid' => 'g', 'gen' => 1, 'layer' => 1, 'state' => 'open', 'at' => lrgNow(), 'kind' => 'closed',
        'entries' => $ents, 'crit' => 0]);
    $echo = (string) ob_get_clean();
    $GLOBALS['gameRequest'] = $saved;
    $GLOBALS['LRG_DLG_STATE'] = [];
    $GLOBALS['LRG_DLG_TEST_STORE'] = [];
    unset($GLOBALS['LRG_DLG_TEST_QUEUE']);
    return str_contains($echo, ';do=pick;');
};
$durak = $qeE([['DLC1VQ00IntroC', "I haven't noticed any vampire menace."], ['DLC1VQ00IntroC', 'Killing vampires? Where do I sign up?', 'commit'],
    ['DLC1VQ00IntroC', "Sorry, I'm not interested."], ['DLC1VQ00IntroC', "What's the Dawnguard?"]]);
$w1 = $qeWant('Durak', $durak, 'count me in, I want to hunt vampires');
$w2 = $qeWant('Durak', $durak, "I'd like to join up and kill some vampires");
check('(g) Durak 00D8E2: "count me in, I want to hunt vampires" / "I\'d like to join up and kill some vampires" pick his sign-up (hunting vampires is the Dawnguard, not Volkihar)',
    is_array($w1) && (int) $w1['i'] === 1 && is_array($w2) && (int) $w2['i'] === 1 && (string) lrgFacAsk('count me in, I want to hunt vampires')['faction'] === 'dawnguard', json_encode([$w1, $w2]));
check('(g) ...his own question "Killing vampires? Where do I sign up?" stands aside for the explicit test (the faction mode vetoes a wh-question); "where do I sign up for the Companions?" clicks NOTHING',
    $qeWant('Durak', $durak, 'Killing vampires? Where do I sign up?') === null && $qeWant('Durak', $durak, 'where do I sign up for the Companions?') === false);
$gift = $qeE([['CW02BTulliusDeliverMessage', 'I just want to join the Legion. Consider the crown a gift.', 'commit'], ['CW02BTulliusNevermind', 'Never mind.']]);
check('(g) Tullius 05A6A6 "I just want to join the Legion, consider the crown a gift": the hand-off stands aside (a line on his list IS this enlistment) - the rails decide',
    $qeWant('General Tullius', $gift, 'I just want to join the Legion, consider the crown a gift') === null
    && $qeWant('General Tullius', $gift, 'i just want to join the lesion') === null);
$qeAdv = $qeWant('General Tullius', $gift, 'do you think I should join the Legion?');
check('(g) [r2 use P3] "not now, I just want to join the Legion later" -> nothing (her words answer); "do you think I should join the Legion?" -> the one join line goes to the commit rail (she asks, quoting it): END TO END neither clicks, and his plain sentence still does',
    $qeWant('General Tullius', $gift, 'not now, I just want to join the Legion later') === false && is_array($qeAdv) && (int) $qeAdv['i'] === 0
    && !$qeFast('General Tullius', $gift, 'not now, I just want to join the Legion later') && !$qeFast('General Tullius', $gift, 'do you think I should join the Legion?')
    && $qeFast('General Tullius', $gift, 'I just want to join the Legion, consider the crown a gift'), json_encode($qeAdv));
$council = $qeE([['MQ302UlfricBranch', "You and Tullius are both mistaken. I'm loyal to the Empire.", 'commit'], ['MQ302UlfricBranch', "I accept your offer. I'd like to join the Stormcloaks.", 'commit']]);
$defect = $qeE([['MQ103AUlfricBookB1', 'I made a mistake. I want to be a Stormcloak. The crown belongs to you.', 'commit'], ['MQ103AUlfricNever', 'Never mind.']]);
$yes = $qeE([['CW00BUlfricNo', "That's not why I'm here."], ['CW00BUlfricYes', 'Yes, sir.', 'commit']]);
// [pt19h r2 / arch P2] the yes-line rule holds only for a faction SHE belongs to: Ulfric is a CWSonsFaction member (his snapshot says so)
lrgStoreNpcState('Ulfric Stormcloak', snap(['fac' => 'CWSonsFaction,JarlFaction']));
check('(g) Ulfric 04BE5F / 05A6B1 / 0C347D: "I accept your offer. I\'d like to join the Stormcloaks", "I want to be a Stormcloak, uh, take the crown", "Yes sir, I want to join the Stormcloaks and fight for Skyrim" - the hand-off stands aside for each (his yes-line is the Stormcloaks\' own: a CWSonsFaction member)',
    $qeWant('Ulfric Stormcloak', $council, "I accept your offer. I'd like to join the Stormcloaks") === null
    && $qeWant('Ulfric Stormcloak', $defect, 'I want to be a Stormcloak, uh, take the crown') === null
    && $qeWant('Ulfric Stormcloak', $yes, 'Yes sir, I want to join the Stormcloaks and fight for Skyrim') === null);
check('(g) [r2 arch P2] ... but to General Tullius "yes sir, but I want to join the Stormcloaks instead" clicks NOTHING: the yes-line is the Legion\'s and his words turn against it (a contradiction never advances the enlistment)',
    $qeWant('General Tullius', $yes, 'yes sir, but I want to join the Stormcloaks instead') === false && !$qeFast('General Tullius', $yes, 'yes sir, but I want to join the Stormcloaks instead'));
$serana = $qeE([['DLC1SeranaTurn', 'I want you to turn me into a vampire.'], ['DLC1SeranaAsk', 'Were you always a vampire?']]);
check('(g) Serana 004A79 "make me a vampire, Serana": her own "turn me into a vampire" line is this ask - the hand-off stands aside', $qeWant('Serana', $serana, 'make me a vampire, Serana') === null);
$isran = $qeE([['DLC1VQ01IntroA1', 'I heard you were looking for vampire hunters.'], ['DLC1VQ01IntroA1', 'I was just looking around. What is this place?'],
    ['DLC1VQ01IntroA1', "I'm here to join the Dawnguard.", 'commit']]);
$wi = $qeWant('Isran', $isran, "i'm here to join the dawn guards");
check('(g) Isran 00D901 "i\'m here to join the dawn guards" (the STT\'s "dawn guards" was the one-token guard row): his join line',
    is_array($wi) && (int) $wi['i'] === 2 && (string) lrgFacAsk("i'm here to join the dawn guards")['faction'] === 'dawnguard', json_encode($wi));
$qeEcho = $qeWant('Isran', $isran, "wait, i'm here to join the Dawnguard?");
check('(g) [G11 / G5, r2 use P3] "wait, i\'m here to join the Dawnguard?" (an echo) goes to the commit rail and clicks NOTHING end to end; "can anyone join the Dawnguard?" is no enlistment at all (the matchers\' rails decide); "i\'m here to join the dawn guards" still clicks',
    is_array($qeEcho) && (int) $qeEcho['i'] === 2 && !$qeFast('Isran', $isran, "wait, i'm here to join the Dawnguard?")
    && $qeWant('Isran', $isran, 'can anyone join the Dawnguard?') === null && $qeFast('Isran', $isran, "i'm here to join the dawn guards"), json_encode($qeEcho));
$oath = $qeE([['CW01BGalmarOath', 'Are you saying you sent me out there to die?'], ['CW01BGalmarOath', 'I need to think it over.'], ['CW01BGalmarOath', "I'm ready to take the Oath.", 'commit']]);
$wo = $qeWant('Galmar Stone-Fist', $oath, "I'm ready to take the Oath.");
check('(g) [G1] Galmar 0E2D06: "no, i\'m ready to take the Oath" and "not now, I\'m ready to take the oath later" click NOTHING; his plain "I\'m ready to take the Oath." still does',
    $qeWant('Galmar Stone-Fist', $oath, "no, i'm ready to take the Oath") === false && $qeWant('Galmar Stone-Fist', $oath, "not now, I'm ready to take the oath later") === false
    && is_array($wo) && (int) $wo['i'] === 2, json_encode($wo));
$aldis = $qeE([['Favor110QuestGiveTopicSolitude', 'Are you with the Legion?'], ['DialogueSolitudeValdBranchTopic', 'How goes the training?']]);
check('(g) pt16 kept: Aldis\'s list carries no line that is an enlistment - "i want to join the legion" still clicks nothing ("Are you with the Legion?" is no enlistment)',
    $qeWant('Captain Aldis', $aldis, 'i want to join the legion') === false);
check('(g) the object of the ask wins: "I\'m, uh, here to join the Stormcloaks and fight the Empire" is the Stormcloaks; "i want to join the college" is still ambiguous',
    (string) lrgFacAsk("I'm, uh, here to join the Stormcloaks and fight the Empire")['faction'] === 'stormcloaks'
    && count((array) lrgFacAsk('i want to join the college')['ambiguous']) === 2);
check('(g) lrgFacAskActs: requests act ("do you know where i could sign up for the legion?", "can I join the Companions?", "would like to join the companions" - the STT dropped the I); advice, echoes and hedges do not',
    lrgFacAskActs('do you know where i could sign up for the legion?', lrgFacAsk('do you know where i could sign up for the legion?'))
    && lrgFacAskActs('can I join the Companions?', lrgFacAsk('can I join the Companions?'))
    && lrgFacAskActs('would like to join the companions', lrgFacAsk('would like to join the companions', ['companions']))
    && !lrgFacAskActs('do you think I should join the Legion?', lrgFacAsk('do you think I should join the Legion?'))
    && !lrgFacAskActs('maybe I will join the Legion one day', lrgFacAsk('maybe I will join the Legion one day'))
    && !lrgFacAskActs("wait, i'm here to join the Dawnguard?", lrgFacAsk("wait, i'm here to join the Dawnguard?")));
check('(g) the way in asked with a how / where is an ask that acts ("so, uh, how would someone go about joining the Imperial Legion?" - CW00A 0D3C5A say line); "why would I join?" / "what does it take to join the Companions?" still ask ABOUT it',
    lrgFacAskActs('so, uh, how would someone go about joining the Imperial Legion?', lrgFacAsk('so, uh, how would someone go about joining the Imperial Legion?'))
    && (string) lrgFacAsk('so, um, where do I go to sign up for this vampire hunting thing')['faction'] === 'dawnguard'
    && empty(lrgFacAsk('why would I join the Legion?', ['legion'])['join']) && empty(lrgFacAsk('what does it take to join the Companions?', ['companions'])['join']));
// [pt19h-quest review] the WORDS path (lrgFacArbitrate, the model's item names the join line): his words that may not act click
// nothing there either; an echo whose '?' closes a LATER sentence of his does not act; a real question after the ask still does
$rvJoin = $qeE([['CW02BTulliusJoin', "I'd like to join the Imperial Legion.", 'commit'], ['CW02BTulliusWhat', "What's the Imperial Legion doing in Skyrim?"]]);
$rvArb = static function (string $u, array $pool, string $item) {
    $ask = lrgFacAsk($u, ['legion']);
    $t = ['npc' => 'General Tullius', 'cid' => 'rv', 'on' => true, 'speech' => true, 'said' => $u, 'snap' => [], 'q' => [], 'entries' => $pool, 'tail' => [],
        'faction' => ['asked' => (string) $ask['faction'], 'join' => (int) $ask['join'], 'role' => 'recruiter', 'carried' => 0, 'acts' => lrgFacAskActs($u, $ask) ? 1 : 0]];
    return lrgFacArbitrate($t, $pool, $item, 'rv');
};
$rvPlain = $rvArb("I'd like to join the Imperial Legion", $rvJoin, "I'd like to join the Imperial Legion.");
check('(g) [review] words path: "maybe I\'ll join the Legion one day" / "wait, I\'d like to join the Imperial Legion?" / "do you think I should join the Legion?" + the model\'s item "I\'d like to join the Imperial Legion." execute NOTHING; his plain sentence still takes the line',
    $rvArb("maybe I'll join the Legion one day", $rvJoin, "I'd like to join the Imperial Legion.") === ['entry' => null]
    && $rvArb("wait, I'd like to join the Imperial Legion?", $rvJoin, "I'd like to join the Imperial Legion.") === ['entry' => null]
    && $rvArb('do you think I should join the Legion?', $rvJoin, "I'd like to join the Imperial Legion.") === ['entry' => null]
    && is_array($rvPlain) && (int) (($rvPlain['entry'] ?? [])['pos'] ?? -1) === 0, json_encode($rvPlain));
check('(g) [review] lrgFacAskActs: "wait, i just want to join the Legion. Consider the crown a gift?" and "... a gift?" are echoes (the \'?\' closes the later sentence); "I want to join the Companions. Can I?" and the plain gift sentence act',
    !lrgFacAskActs('wait, i just want to join the Legion. Consider the crown a gift?', lrgFacAsk('wait, i just want to join the Legion. Consider the crown a gift?'))
    && !lrgFacAskActs('I just want to join the Legion. Consider the crown a gift?', lrgFacAsk('I just want to join the Legion. Consider the crown a gift?'))
    && lrgFacAskActs('I want to join the Companions. Can I?', lrgFacAsk('I want to join the Companions. Can I?'))
    && lrgFacAskActs('I just want to join the Legion, consider the crown a gift', lrgFacAsk('I just want to join the Legion, consider the crown a gift')));
check('(g) [review] lrgFacAskActs: the safety rule\'s hedges do not act, leading (lrgDlgHedges) or as a trailing tag ("I guess I want to join the Legion", "I suppose I\'d like to join the Imperial Legion", "I want to join the Legion, I guess"); the owner\'s own "okay i guess you\'re the guy i talked to to join the legion" still acts',
    !lrgFacAskActs('I guess I want to join the Legion', lrgFacAsk('I guess I want to join the Legion'))
    && !lrgFacAskActs("I suppose I'd like to join the Imperial Legion", lrgFacAsk("I suppose I'd like to join the Imperial Legion"))
    && !lrgFacAskActs('I want to join the Legion, I guess', lrgFacAsk('I want to join the Legion, I guess'))
    && lrgFacAskActs("okay i guess you're the guy i talked to to join the legion", lrgFacAsk("okay i guess you're the guy i talked to to join the legion"))
    && lrgFacAskActs('I want to join the Legion', lrgFacAsk('I want to join the Legion')));
// the click-free enlistment entry (10.26) is not queued on a deferral either
$GLOBALS['LRG_DLG_STATE'] = [];
$GLOBALS['LRG_DLG_TEST_STORE'] = [];
lrgDlgPut('*install*', ['clicks_ok' => 0]);
$tulD = ['npc' => 'General Tullius', 'cid' => 'qd', 'on' => true, 'speech' => true, 'type' => 'inputtext', 'snap' => ['fac' => 'CWImperialFaction,CWFieldCOFaction', '_age' => 5],
    'q' => ['CW00A', 'MQ101'], 'ambient' => 1, 'entries' => [], 'tail' => [], 'clicks_ok' => 0, 'facts' => ['qst' => ['CW00A' => 0, 'CW00B' => 0], 'mq101c' => 1, 'at' => lrgNow() - 5]];
$fD = lrgFacTurn($tulD, 'not now, I want to join the Legion later', true);
$fQ = lrgFacTurn($tulD, 'I want to join the Legion', true);
check('(g) [G1] Tullius: "not now, I want to join the Legion later" queues no click-free entry (the truth still rides); "I want to join the Legion" still queues it',
    (string) ($fD['asked'] ?? '') === 'legion' && empty($fD['acts']) && ($fD['plan'] ?? null) === [] && (string) (($fQ['plan'] ?? [])['state'] ?? '') === 'queued',
    json_encode([$fD['plan'] ?? null, $fQ['plan'] ?? null]));
unset($GLOBALS['LRG_DLG_MCM'], $GLOBALS['LRG_DLG_TURN'], $GLOBALS['LRG_FAC_QE_SENT'], $GLOBALS['LRG_VOICED'], $GLOBALS['LRG_FUNCRET']);
$GLOBALS['LRG_DLG_STATE'] = [];
$GLOBALS['LRG_DLG_TEST_STORE'] = [];
$resetMem();
// ---- [pt19h-quest] end

unset($GLOBALS['LRG_DLG_TEST_STORE']);
$GLOBALS['LRG_DLG_STATE'] = [];
echo "10. kill switch\n";
file_put_contents(LRG_DIR . '/config/lrg_config.json', json_encode(['kill_switch' => true]));
$out = shell_exec('php -r ' . escapeshellarg('require "' . LRG_DIR . '/lib/lrg_core.php"; echo lrgEnabled() ? "on" : "off";'));
unlink(LRG_DIR . '/config/lrg_config.json');
check('kill switch in the user config turns everything off', trim((string) $out) === 'off', trim((string) $out));

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
