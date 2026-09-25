<?php
// Offline test of the v0.3 intent recogniser (G1): the PLAYER's own words -> kind, confidence, command.
// Usage (inside WSL):  php tools/test_intent.php        (add --quiet to print only the checks)
//
// THE TABLE IS THE PLAYTEST-6 REPLAY. Every utterance in section A is a verbatim transcript of what the
// OWNER said in playtest 6 (or an utterance the round prompt names), so each row is a regression test for
// a failure that really happened. These are the PLAYER's lines - search input, the one thing a recogniser
// cannot be tested without. No line an NPC says is ever written into a test, a prompt or a config.
//
// No HerikaServer, no DB, no LLM. Section C needs the real scene index (built from the installed packs).
require __DIR__ . '/../server/lorerim_glue/lib/lrg_actions.php';

$fails = 0; $pass = 0;
$quiet = in_array('--quiet', $argv, true);
function check(string $what, bool $ok, string $detail = ''): void {
    global $fails, $pass;
    $ok ? $pass++ : $fails++;
    printf("  [%s] %s%s\n", $ok ? 'ok' : 'FAIL', $what, (!$ok && $detail !== '') ? "  <- $detail" : '');
}
function say(string $line = ''): void { global $quiet; if (!$quiet) { echo $line . "\n"; } }
$GLOBALS['LRG_TEST_NO_SPAWN'] = true;
$GLOBALS['PLAYER_NAME'] = 'Flowtest Player';

// ------------------------------------------------------------------ A. the playtest-6 replay table
echo "A. playtest-6 replay: what the player said -> what the server understood\n";
$prop = ['proposal' => ['act' => 'vaginal', 'at' => lrgNow(), 'expires' => lrgNow() + 90, 'turns' => 0]];
// [utterance, kind, conf, extra assertions => value, note]
$rows = [
    ['I want you to get naked.',            'undress', 'high', ['who' => 'npc'],    'frame 2'],
    ['Take off your clothes.',              'undress', 'high', ['who' => 'npc'],    'frame 1 + frame 4'],
    ['Take your clothes off.',              'undress', 'high', ['who' => 'npc'],    'the 19:38:17 turn that warped into sex instead'],
    ['I want you to take my clothes off.',  'undress', 'high', ['who' => 'player'], 'THE WHO-INVERSION FIX'],
    ['I want to see you naked.',            'undress', 'high', ['who' => 'npc'],    ''],
    ['get naked',                           'undress', 'high', ['who' => 'npc'],    ''],
    ['naked',                               'undress', 'low',  ['who' => 'npc'],    'bare noun: the safety net must NOT fire'],
    ['Tell me, what do you picture my cock looking like?', 'none', 'none', [],      'a question, not a request'],
    ['Hey Lazette, do you wanna follow me?', 'none', 'none', [],                    'mangled name, not a request'],
    ['kiss me',                             'act',   'high', ['act' => 'kiss'],     ''],
    ['fuck me from behind',                 'act',   'high', ['act' => 'vaginal'],  ''],
    ['I wanna you doggy style.',            'act',   'high', ['act' => 'vaginal'],  'speech-to-text dropped the verb'],
    ['i want to bend you over',             'act',   'high', ['act' => 'vaginal'],  'through the bendover synonym'],
    ['faster',                              'faster', 'high', ['do' => 'faster'],   ''],
    ['slow down',                           'slower', 'high', ['do' => 'slower'],   ''],
    ['stop',                                'stop',  'high', ['do' => 'stop'],      ''],
    ["don't stop",                          'none',  'none', [],                    'a negated stop is NOT a stop'],
    ['you lead',                            'lead_npc',    'high', ['do' => 'lead', 'who' => 'npc'],    ''],
    ['surprise me',                         'lead_npc',    'high', ['do' => 'lead', 'who' => 'npc'],    'must not become a random position (@any)'],
    ["I'll lead",                           'lead_player', 'high', ['do' => 'lead', 'who' => 'player'], ''],
    ['should I take this off?',             'none', 'none', [],                     'owner addendum 3d, verbatim'],
    ["don't get naked yet",                 'none', 'none', [],                     'owner addendum 3d, verbatim'],
    ['can you take your clothes off',       'undress', 'high', ['who' => 'npc'],    'the polite-request frame beats the question blocker'],
    ['put your clothes back on',            'dress', 'high', ['who' => 'npc'],      'must not fall through to the undress branch'],
    ['',                                    'none', 'none', [],                     'an empty transcript is reported, not guessed at'],
    ['What do you want to try?',            'none', 'none', [],                     'a question, from the playtest'],
];
foreach ($rows as [$text, $kind, $conf, $want, $note]) {
    $r = lrgRecogniseIntent($text, null, []);
    $ok = $r['kind'] === $kind && $r['conf'] === $conf;
    foreach ($want as $k => $v) {
        // [0.3.1] an act id may now carry the position the player named ("vaginal/doggy"): the
        // expectation names the FAMILY, and a position on top of it is exactly what R2 asked for
        $ok = $ok && (($k === 'act') ? (function_exists('lrgActBase') ? lrgActBase($r['act']) === $v : $r['act'] === $v)
            : (string) ($r['kv'][$k] ?? '') === $v);
    }
    check(sprintf('"%s" -> %s/%s%s%s', $text === '' ? '(empty)' : $text, $kind, $conf, $want ? ' ' . json_encode($want) : '', $note !== '' ? "   ($note)" : ''),
        $ok, sprintf('got %s/%s act=%s kv=%s why=%s', $r['kind'], $r['conf'], $r['act'], json_encode($r['kv']), $r['why']));
}
check('an empty transcript says so, so the log can warn about it', lrgRecogniseIntent('', null, [])['why'] === 'empty transcript');

// ------------------------------------------------------------------ B. answers to her proposal (R11)
say();
echo "B. yes / no, only against a proposal she really made\n";
$y = lrgRecogniseIntent('yes', null, $prop);
check('"yes" while a proposal is pending -> yes/high, carrying the pending act', $y['kind'] === 'yes' && $y['conf'] === 'high' && $y['act'] === 'vaginal', json_encode($y));
$n = lrgRecogniseIntent('not now', null, $prop);
check('"not now" while a proposal is pending -> no/high, carrying the act (it is remembered)', $n['kind'] === 'no' && $n['conf'] === 'high' && $n['act'] === 'vaginal', json_encode($n));
check('"yes" with NO proposal pending is not an instruction', lrgRecogniseIntent('yes', null, [])['kind'] === 'none');
check('"no" with NO proposal pending is not an instruction', lrgRecogniseIntent('no', null, [])['kind'] === 'none');
$stale = ['proposal' => ['act' => 'vaginal', 'at' => lrgNow() - 500, 'expires' => lrgNow() - 100, 'turns' => 0]];
check('an expired proposal does not turn a later "yes" into a sex act', lrgRecogniseIntent('yes', null, $stale)['kind'] === 'none');
// A HEDGED yes is not a yes. This is the one path in v0.3 where a single mis-read word makes a sex act
// happen, and the old pattern only anchored the first word: "okay, hold on" - the player asking her to
// WAIT - answered yes to vaginal sex. An utterance that carries more than the affirmation now falls
// through to the ordinary scan, where its own words decide.
foreach (['yes, but not now', 'yes, not yet', 'okay, hold on', 'sure, in a minute', 'ok, so what were you saying',
          'go ahead and tell me more', 'yes but hold on a moment'] as $hedged) {
    $h = lrgRecogniseIntent($hedged, null, $prop);
    check("\"$hedged\" is NOT a yes to her proposal", $h['kind'] !== 'yes', json_encode($h));
}
check('"okay, hold on" is read as what it is: hold', lrgRecogniseIntent('okay, hold on', null, $prop)['kind'] === 'hold', json_encode(lrgRecogniseIntent('okay, hold on', null, $prop)));
check('"yes, but not now" is read as the refusal it is', lrgRecogniseIntent('yes, but not now', null, $prop)['kind'] === 'no');
foreach (['yes', 'yeah', 'sure', 'ok', 'do it', 'go ahead', 'gods yes', 'yes please', 'alright'] as $plain) {
    check("\"$plain\" on its own is still a yes", lrgRecogniseIntent($plain, null, $prop)['kind'] === 'yes', json_encode(lrgRecogniseIntent($plain, null, $prop)));
}

// ------------------------------------------------------------------ C. the stop rail, written for speech
say();
echo "C. the stop rail: a standalone stop only (it is spoken English, not the model's keyword)\n";
foreach (['stop' => true, 'Stop.' => true, 'STOP' => true, 'please stop' => true, 'stop it' => true,
          "we should stop" => true, "that's enough" => true, 'enough' => true, 'no more' => true, 'end this' => true,
          'stop teasing me and fuck me' => false, "I can't get enough of you" => false, "don't stop" => false,
          'stop talking and kiss me' => false] as $text => $isStop) {
    check(sprintf('"%s" %s a stop', $text, $isStop ? 'IS' : 'is NOT'), lrgIntentStop(lrgIntentClean($text)) === $isStop);
}
$mix = lrgRecogniseIntent('stop teasing me and fuck me', null, []);
check('"stop teasing me and fuck me" is the act, not the end of the scene', $mix['kind'] === 'act' && $mix['conf'] === 'high', json_encode($mix));
check('"I can\'t get enough of you" is nothing at all', lrgRecogniseIntent("I can't get enough of you", null, [])['kind'] === 'none');
check('"that\'s enough" is a stop', lrgRecogniseIntent("that's enough", null, [])['kind'] === 'stop');
// LRG_INTENT_FRAME1 is ^-anchored, so a request that does not OPEN the sentence never counted as framed
// and the stop rail won: "that's enough, fuck me" ended the scene. The frame is now judged on what is
// left after the stop clause as well.
foreach (["that's enough, fuck me" => 'act', 'enough of this, faster' => 'faster',
          "that's enough talking, kiss me" => 'act'] as $text => $kind) {
    $m = lrgRecogniseIntent($text, null, []);
    check("\"$text\" is the request, not the end of the scene", $m['kind'] === $kind && $m['conf'] === 'high', json_encode($m));
}
// ... and a genuine stop that happens to contain an act word is still a stop: the remainder carries no
// request frame, so nothing overrides the rail.
foreach (["stop, I can't take any more of this fucking", 'that\'s enough kissing', 'no more, I am done',
          'stop', "that's enough"] as $text) {
    check("\"$text\" is still a stop", lrgRecogniseIntent($text, null, [])['kind'] === 'stop', json_encode(lrgRecogniseIntent($text, null, [])));
}
// A negation governs the words it is attached to, not the whole utterance: "don't stop" is refused by the
// stop rail, and the request that follows it must not be thrown away with it.
$dsh = lrgRecogniseIntent("don't stop, harder", null, []);
check('"don\'t stop, harder" keeps the "harder"', $dsh['kind'] === 'faster' && $dsh['conf'] === 'high', json_encode($dsh));
check('"don\'t slow down" is still nothing: one clause, and the negation governs it', lrgRecogniseIntent("don't slow down", null, [])['kind'] === 'none', json_encode(lrgRecogniseIntent("don't slow down", null, [])));
$dsk = lrgRecogniseIntent("don't stop, kiss me", null, []);
check('"don\'t stop, kiss me" keeps the framed clause at high confidence', $dsk['kind'] === 'act' && $dsk['conf'] === 'high', json_encode($dsk));
check('"don\'t get naked yet" is still not a request', lrgRecogniseIntent("don't get naked yet", null, [])['kind'] === 'none');
// Generic words that carry no act of their own are out of the ACTS TABLE (feel / taste / mouth / arms /
// neck / inside), so they no longer name an act family by themselves.
// KNOWN RESIDUAL, reported to the next stage: lrgMatchAct() step 3 also reads scene_index.synonyms, where
// 'feel' and 'arms' are still keys (they are needed there by the position search), so "can you feel that?"
// can still reach an act. Removing them from the acts table is what this round's defect asked for; the
// synonyms are SCENEINDEX vocabulary that test_scene_index.php asserts on ('kiss my neck', 'use your
// mouth'), so they are deliberately left alone here.
foreach (['feel', 'taste', 'mouth', 'arms', 'neck', 'inside'] as $w) {
    $hit = false;
    foreach (lrgActsTable() as $f) { $hit = $hit || in_array($w, array_map('strtolower', (array) ($f['words'] ?? [])), true); }
    check("the act table no longer treats \"$w\" as a spoken name for an act", !$hit);
}

// ------------------------------------------------------------------ D. who the player means (the inversion)
say();
echo "D. whose clothes: the PLAYER is the speaker, so \"my\" and \"your\" mean the opposite of the model's item\n";
foreach (['take my clothes off' => 'player', 'undress me' => 'player', 'get my boots off' => 'player', 'take mine off' => 'player',
          'take your clothes off' => 'npc', 'get naked' => 'npc', 'strip' => 'npc', 'take it off' => 'npc',
          'let\'s both get undressed' => 'both', 'undress us' => 'both'] as $text => $who) {
    check(sprintf('"%s" -> %s', $text, $who), lrgIntentClothingWho(lrgIntentClean($text)) === $who, lrgIntentClothingWho(lrgIntentClean($text)));
}
check('the model\'s own item keeps the NPC reading ("take your clothes off" = she does)', (lrgResolveClothing('take your clothes off')['who'] ?? '') === 'npc');
check('... and "my shirt" in the model\'s item now means the player, not her', (lrgResolveClothing('undress my shirt')['who'] ?? '') === 'player');
foreach (['take off my boots' => 'feet', 'take your gloves off' => 'hands', 'get that helmet off' => 'head', 'take your shirt off' => 'body', 'get naked' => 'all'] as $text => $part) {
    check(sprintf('"%s" -> part %s', $text, $part), lrgIntentPart(lrgIntentClean($text)) === $part);
}

// ------------------------------------------------------------------ E. blockers
say();
echo "E. what is NOT a request (owner addendum 3d)\n";
foreach (['what if I asked you to strip' => 'hypothetical', 'imagine we were naked' => 'hypothetical',
          'you said you wanted to kiss me' => 'quoted', 'remember when we kissed' => 'quoted',
          "don't take your clothes off" => 'negated', 'not yet' => 'negated', 'maybe later' => 'negated',
          'should I take this off?' => 'question', 'what do you want to try?' => 'question', 'do you like kissing?' => 'question'] as $text => $why) {
    check(sprintf('"%s" is blocked as %s', $text, $why), lrgIntentBlocked(lrgIntentClean($text)) === $why, lrgIntentBlocked(lrgIntentClean($text)));
}
check('a polite request is not a question', lrgIntentBlocked(lrgIntentClean('can you take your clothes off?')) === '');
check('an imperative with a question mark is not a question', lrgIntentBlocked(lrgIntentClean('kiss me?')) === '');
check('a lead-in does not hide a question ("Tell me, what ...")', lrgIntentBlocked(lrgIntentClean('Tell me, what do you like?')) === 'question');

// ------------------------------------------------------------------ F. order of resolution
say();
echo "F. the fixed order: dress before undress, undress before act, lead before act\n";
check('"put your clothes back on" is dress, never undress', lrgRecogniseIntent('put your clothes back on', null, [])['kind'] === 'dress');
check('"get naked" is undress, never a position', lrgRecogniseIntent('get naked', null, [])['kind'] === 'undress');
check('"you decide" hands over the lead, it is not a random position', lrgRecogniseIntent('you decide', null, [])['kind'] === 'lead_npc');
check('"do whatever you want" hands over the lead', lrgRecogniseIntent('do whatever you want', null, [])['kind'] === 'lead_npc');
check('"let me lead" takes it back', lrgRecogniseIntent('let me lead', null, [])['kind'] === 'lead_player');
check('speech prefixes are stripped ("Jordan: faster")', lrgRecogniseIntent('Jordan: faster', null, [])['kind'] === 'faster');
check('the DLL context prefix is stripped', lrgRecogniseIntent('(Context location: The Bannered Mare) faster', null, [])['kind'] === 'faster');

// ------------------------------------------------------------------ G. with the real index: an act becomes a scene
say();
echo "G. against the installed packs: an act id resolves to a scene of that act\n";
lrgIndexWarm();
$idx = lrgSceneIndex();
if (count($idx['scenes'] ?? []) === 0) {
    check('the scene index has scenes', false, (string) ($idx['error'] ?? 'empty'));
} else {
    $sexes = ['0', '1'];
    // a sexual-tier two-person scene to stand in, and the context a scene turn would build
    $start = lrgPickStartScene($sexes);
    $ctx = ['current' => $start, 'live' => [], 'sexes' => $sexes, 'furn' => '', 'ceiling' => 4, 'req_ceiling' => 4,
        'tier' => 2, 'reached' => 2, 'furn_options' => [], 'player' => 'Flowtest Player', 'npc' => 'Flowtest NPC', 'npos' => 1, 'auto_ok' => false];
    check('set-up: a standing start scene exists', $start !== '', 'none');
    $acts = lrgActOptions($start, [], 4, $sexes, '', 1, [], 64);
    check('acts are open from the start scene', count($acts) > 0, json_encode(array_keys($acts)));
    $hasVaginal = isset($acts['vaginal']);
    if ($hasVaginal) {
        foreach (['fuck me from behind', 'i want to bend you over', 'I wanna you doggy style.'] as $text) {
            $r = lrgRecogniseIntent($text, $ctx, []);
            $sceneOk = ($r['kv']['scene'] ?? '') !== '' && in_array('vaginal', lrgSceneActs(lrgScene($r['kv']['scene']), 1, $sexes), true);
            check(sprintf('"%s" -> act vaginal, do=goto, a scene that really carries that act', $text),
                lrgActBase($r['act']) === 'vaginal' && ($r['kv']['do'] ?? '') === 'goto' && $sceneOk, json_encode($r['kv']) . ' act=' . $r['act']);
        }
        $bend = lrgRecogniseIntent('i want to bend you over', $ctx, []);
        $plain = lrgRecogniseIntent('fuck me', $ctx, []);
        check('the words the player used pick the scene: "bend you over" and a plain "fuck me" are not forced onto the same one',
            ($bend['kv']['scene'] ?? 'a') !== ($plain['kv']['scene'] ?? 'b') || lrgSameShape(lrgScene((string) $bend['kv']['scene']), lrgScene((string) $plain['kv']['scene'])),
            ($bend['kv']['scene'] ?? '-') . ' vs ' . ($plain['kv']['scene'] ?? '-'));
    } else {
        say('  note: the installed packs offer no vaginal act from the start scene - the act rows are covered in section A');
    }
    // the ceiling never blocks the player, only her own pacing
    $low = ['ceiling' => 2, 'req_ceiling' => 2] + $ctx;
    $r = lrgRecogniseIntent('kiss me', $low, []);
    check('an act at the ceiling resolves under a low ceiling too', $r['kind'] === 'act' && $r['act'] === 'kiss', json_encode($r['kv']));
    // furniture needs the options of the turn
    $fo = ['bed' => ['furn' => 'bed', 'label' => 'the bed', 'words' => ['bed', 'mattress'], 'scene' => $start, 'tier' => 'kissing']];
    $f = lrgRecogniseIntent("let's move to the bed", ['furn_options' => $fo] + $ctx, []);
    check('"let\'s move to the bed" -> furniture/high, do=furniture with a scene id', $f['kind'] === 'furniture' && $f['conf'] === 'high'
        && ($f['kv']['do'] ?? '') === 'furniture' && ($f['kv']['scene'] ?? '') !== '', json_encode($f));
    check('a bare furniture word is recognised, but only at low confidence', (function () use ($fo, $ctx) {
        $b = lrgRecogniseIntent('bed', ['furn_options' => $fo] + $ctx, []);
        return $b['kind'] === 'furniture' && $b['conf'] === 'low';
    })());
    // climax only once things are at least sensual
    check('"cum inside me" below the sensual tier is not a climax', lrgRecogniseIntent('cum inside me', $ctx, [])['kind'] !== 'climax');
    $hot = ['tier' => 4] + $ctx;
    $cx = lrgRecogniseIntent('cum inside me', $hot, []);
    check('"cum inside me" at the sexual tier -> climax, and the player is the one finishing', $cx['kind'] === 'climax' && ($cx['kv']['who'] ?? '') === 'player', json_encode($cx));
}

// ------------------------------------------------------------------ H. the directive
say();
echo "H. the per-turn directive: an order only inside a confirmed scene\n";
$turnBase = ['npc' => 'Flowtest NPC', 'mode' => 'scene', 'scene_blocked' => false, 'scene_confirmed' => true,
    'offered' => [LRG_ACT_CONTROL, LRG_ACT_CLOTHING, LRG_ACT_REQUESTACT], 'acts' => [], 'ctx' => ['player' => 'Flowtest Player', 'npc' => 'Flowtest NPC']];
$d = lrgRequestDirective(['intent' => lrgRecogniseIntent('take your clothes off', null, [])] + $turnBase);
check('in a confirmed scene, a high-confidence request is an ORDER naming action and value',
    str_contains($d, '<player_request>') && str_contains($d, 'Do it now') && str_contains($d, 'ChangeClothing') && str_contains($d, 'undress') && str_contains($d, 'Do not refuse'), $d);
$d = lrgRequestDirective(['intent' => lrgRecogniseIntent('naked', null, [])] + $turnBase);
check('a LOW-confidence match is never an order: it only names what the player may have meant',
    str_contains($d, 'may have asked') && !str_contains($d, 'Do it now') && !str_contains($d, 'Do not refuse'), $d);
$d = lrgRequestDirective(['intent' => lrgRecogniseIntent('take your clothes off', null, [])] + ['mode' => 'private'] + $turnBase);
// [0.3.1 fix pass] Outside a scene a clothing request now gets the SAME open invitation an act request
// got in this round (pt7 F4: "take off your clothes", willing, private, ChangeClothing on the table,
// and the LLM used no action - because the directive said only "her own choice"). It is still not an
// order: the consent sentence has to be there, and neither imperative may be.
check('OUTSIDE a scene the same words are never an order: her consent before a scene is absolute',
    (str_contains($d, "own choice") || str_contains($d, 'decides that')) && !str_contains($d, 'Do it now') && !str_contains($d, 'Do not refuse'), $d);
check('... and a clothing request there names ChangeClothing, so she can say yes by choosing it',
    str_contains($d, 'ChangeClothing') && str_contains($d, 'undress') && str_contains($d, 'does not want it'), $d);
$d = lrgRequestDirective(['intent' => lrgRecogniseIntent('take your clothes off', null, [])] + ['scene_confirmed' => false] + $turnBase);
check('a scene the game has not confirmed gets no order either', !str_contains($d, 'Do it now'), $d);
$cant = lrgRecogniseIntent('kiss me', null, []);
$cant['cant'] = 'not from here';
$d = lrgRequestDirective(['intent' => $cant, 'acts' => [['name' => 'kissing'], ['name' => 'holding each other']]] + $turnBase);
check('when the GAME cannot do it, she says so in a few words and names what is possible',
    str_contains($d, 'not possible from here') && str_contains($d, 'kissing') && str_contains($d, 'Choose no action'), $d);
$d = lrgRequestDirective(['intent' => lrgRecogniseIntent('not now', null, $prop)] + $turnBase);
check('a no to her proposal: she lets it go and does not bring it up again', str_contains($d, 'said no') && str_contains($d, 'does not bring it up again'), $d);
check('nothing recognised -> no directive at all', lrgRequestDirective(['intent' => lrgNoIntent()] + $turnBase) === '');
// a directive names an action and a VALUE in quotes ("faster"); it never quotes a sentence for her to say
check('the directive contains rules, never a line she is told to say',
    !preg_match('/"[^"]{16,}"/', lrgRequestDirective(['intent' => lrgRecogniseIntent('faster', null, [])] + $turnBase)));

// ------------------------------------------------------------------ I. [0.4] "everyone has a price"
say();
echo "I. money in the player's own words (OWNER ADDENDA 6)\n";
// [utterance, kind, gold, note]. gold === null means "do not care".
$quote = ['price_quoted' => ['gold' => 150, 'at' => lrgNow()]];
$money = [
    ['how much for a night?',            'askprice', 0,    [], 'the question blocker is LIFTED for askprice'],
    ['name your price',                  'askprice', 0,    [], ''],
    ['what would it take?',              'askprice', 0,    [], ''],
    ['what do you charge',               'askprice', 0,    [], ''],
    ["I'll pay you 200 septims",         'offer',    200,  ['firm' => true], ''],
    ["here's fifty gold",                'offer',    50,   ['firm' => true], 'a spelled-out number beside a money word'],
    ["I've got a thousand septims",      'offer',    1000, ['firm' => false], '[pt15] an implied figure: she asks him to confirm'],
    ['would you for a hundred septims?', 'offer',    100,  ['firm' => false], 'a question, but a number AND a money word - grey: she asks'],
    // [pt15, owner ruling A] a hypothetical keeps its figure, and stays NON-committing
    ['what if I gave you 500 gold',      'offer',    500,  ['firm' => false], 'a hypothetical NEVER commits gold - but it carries its figure'],
    ["you said you'd take 100",          'none',     0,    [], 'quoted'],
    ["I'm not paying you",               'none',     0,    [], 'negated'],
    ['fifty',                            'none',     0,    [], 'A NUMBER ALONE IS NEVER AN OFFER'],
    ['200',                              'none',     0,    [], 'nor a bare numeral'],
    ["that's too much",                  'haggle',   0,    [], ''],
    ['come on, meet me in the middle',   'haggle',   0,    [], ''],
    ['can you do better than that',      'haggle',   0,    [], ''],
    ['take my gold',                     'offer',    0,    [], 'a bare offer, amount unnamed'],
    ['money is no object',               'offer',    0,    [], ''],
    // [0.4.0 fix pass] was an "acceptable miss" (offer/0). It is now correctly REFUSED: the goods test
    // sees "for the room". Commerce is not a proposition - see lrgIntentGoodsTalk().
    ["I'll pay for the room, not for you", 'none',   0,    [], 'buying a room is not buying her'],
    ['here is two hundred septims for the room and the food', 'none', 0, [], 'traced live: this invited BeginIntimacy with amount 200'],
    ['I will give you thirty gold for the sword', 'none', 0, [], 'traced live: this was answered with "below what she would take"'],
    ['how much do you want for it',       'none',     0,    [], 'traced live: a jarl was made to name 105000 septims'],
    ['I will give you thirty for it, not a coin more', 'none', 0, [], 'a figure tied to an object pronoun is a purchase'],
    ['come on, knock the price down - twenty septims for the lot', 'none', 0, [], 'a merchant haggle, not her price'],
    ["what's your price",                 'askprice', 0,    [], 'no object named: still her price'],
    ["I'll pay you well for it",          'offer',    0,    [], 'no figure and no asking verb: in her company "it" is what he wants'],
    ["I'll pay you 200 to suck my cock", 'offer',    200,  ['extra' => 'act'], 'the money hit is PRIMARY, the act goes to extra'],
    ['keep your gold',                   'none',     0,    [], 'the player refusing to pay is not an offer'],
    // [pt19] an invitation frame is not a negation in the money pre-scan either (lrgIntentNegated() is shared):
    // the figure carries exactly the weight its un-framed twin carries ("take fifty septims" is implied, "take my
    // fifty septims" is firm), and a question about her wishes stays blocked
    ["why don't you take fifty septims",  'offer',    50,   ['firm' => false], '[pt19] a figure, no commitment: she asks him to confirm'],
    ["why don't you take my fifty septims and come upstairs", 'offer', 50, ['firm' => true], '[pt19] "take my ... septims" is firm, framed or not'],
    ["why don't you want my fifty septims", 'none',   0,    [], '[pt19] a question about her wishes stays negated'],
];
foreach ($money as [$text, $kind, $gold, $want, $note]) {
    $r = lrgRecogniseIntent($text, null, []);
    $ok = $r['kind'] === $kind && (int) ($r['gold'] ?? 0) === $gold;
    if ($kind !== 'none') { $ok = $ok && $r['conf'] === 'high'; }
    if (isset($want['extra'])) { $ok = $ok && (string) (($r['extra'] ?? [])['kind'] ?? '') === $want['extra']; }
    if (isset($want['firm'])) { $ok = $ok && (!empty($r['firm'])) === $want['firm']; }
    check(sprintf('"%s" -> %s/%d%s', $text, $kind, $gold, $note !== '' ? "   ($note)" : ''), $ok,
        sprintf('got %s/%s gold=%d extra=%s firm=%s why=%s', $r['kind'], $r['conf'], (int) ($r['gold'] ?? 0),
            (string) (($r['extra'] ?? [])['kind'] ?? '-'), !empty($r['firm']) ? 'yes' : 'no', $r['why']));
}
$deal = lrgRecogniseIntent('deal', null, $quote);
check('"deal" closes the figure SHE named a moment ago', $deal['kind'] === 'offer' && (int) $deal['gold'] === 150 && $deal['from'] === 'quote', json_encode($deal));
check('... and with nothing quoted it closes nothing', lrgRecogniseIntent('deal', null, [])['kind'] === 'none');
$old = ['price_quoted' => ['gold' => 150, 'at' => lrgNow() - 9999]];
check('... nor does it close a figure from an hour ago', lrgRecogniseIntent('deal', null, $old)['kind'] === 'none');
check('the money kinds are NOT actionable, so the safety net can never move a septim',
    !array_intersect(LRG_INTENT_MONEY, LRG_INTENT_ACTIONABLE)
    && !array_intersect(LRG_INTENT_MONEY, (array) (lrgConfig()['intent']['net_kinds'] ?? [])));
check('a money hit resolves to no command at all', lrgRecogniseIntent("I'll pay you 200 septims", null, [])['kv'] === []);
// the spelled-out number grammar, on its own
foreach (['fifty' => 50, 'a hundred' => 100, 'two hundred' => 200, 'a couple hundred' => 200, 'a few hundred' => 300,
          'a thousand' => 1000, 'five thousand' => 5000, 'twenty five' => 25, 'ninety' => 90, 'and' => 0, 'a' => 0] as $w => $v) {
    check("the number words: \"$w\" = $v", lrgWordAmount($w) === $v, (string) lrgWordAmount($w));
}
// the directive shapes: words only, and never a figure for somebody who has no price
$mt = ['npc' => 'Lisette', 'cid' => 'x', 'type' => 'inputtext', 'acts' => [], 'ctx' => ['player' => 'Dovah'],
    'scene_confirmed' => false, 'offered' => [LRG_ACT_START]];
$g = ['reasons' => ['not_close_enough'], 'state' => ['pgold' => '500'],
    'price' => ['for_sale' => true, 'free' => false, 'gold' => 110], 'paid' => ['offer' => 0, 'accepted' => false]];
$d = lrgBuildRequestDirective(['mode' => 'closed', 'gate' => $g, 'intent' => lrgRecogniseIntent('how much', null, [])] + $mt);
check('askprice names her floor once, and orders no action', str_contains($d, '110 septims') && str_contains($d, 'Choose no action'), $d);
$d = lrgBuildRequestDirective(['mode' => 'scene', 'gate' => $g, 'intent' => lrgRecogniseIntent("I'll pay you 200 septims", null, [])] + $mt);
check('inside a running scene money changes NOTHING and names no figure', str_contains($d, 'nothing is bought or sold')
    && !preg_match('/\b\d{2,}\b/', $d), $d);
$gNo = ['reasons' => [], 'state' => [], 'price' => ['for_sale' => false, 'why' => 'never'], 'paid' => ['offer' => 200]];
$d = lrgBuildRequestDirective(['mode' => 'private', 'gate' => $gNo, 'intent' => lrgRecogniseIntent("I'll pay you 200 septims", null, [])] + $mt);
check('a not-for-sale NPC is never quoted a figure, not even one she would refuse',
    str_contains($d, 'not for sale') && !preg_match('/\b\d{2,}\b/', $d), $d);
$gOk = ['reasons' => [], 'state' => ['pgold' => '500'], 'price' => ['for_sale' => true, 'free' => false, 'gold' => 110],
    'paid' => ['offer' => 200, 'accepted' => true]];
$d = lrgBuildRequestDirective(['mode' => 'private', 'gate' => $gOk, 'intent' => lrgRecogniseIntent("I'll pay you 200 septims", null, [])] + $mt);
// [pt15, owner ruling B] "trust does not matter once he makes a FIRM offer at or above her price AND has the coin":
// the directive now takes it (or haggles upward) instead of "if she wants to ... nobody but her decides"
check('a clearing FIRM offer: take it (amount 200) or haggle upward - the gold is the trust',
    str_contains($d, 'amount 200') && str_contains($d, 'The gold is the trust') && str_contains($d, 'does not refuse for lack of trust')
    && str_contains($d, 'haggles upward') && !str_contains($d, 'If not, say no'), $d);

// ------------------------------------------------------------------ I2. [0.5.6 / pt15] the owner's rulings A-D
say();
echo "I2. [pt15] tonight's log, firm vs grey offers, the confirming question, number words\n";
// [utterance, mem, kind, gold, firm, from, note]
$pend = ['offer_pending' => ['gold' => 1000, 'npc' => 'Katana', 'at' => lrgNow(), 'expires' => lrgNow() + 120, 'req' => 'other-request', 'state' => 'asked']];
$q225 = ['price_quoted' => ['gold' => 225, 'at' => lrgNow()]];
$pt15 = [
    // tonight's log, verbatim
    ['and everybody has a price',                            [],     'askprice', 0,    false, '',             'THE LOG 1: was intent=none'],
    ["if you were to fuck me i'd give you a thousand gold",  [],     'offer',    1000, false, 'hypothetical', 'THE LOG 2: was askprice/0 "no amount was named"'],
    ["If you were to fuck me, I'd give you a thousand gold.", [],    'offer',    1000, false, 'hypothetical', 'same, as the transcript punctuates it'],
    // askprice
    ["everybody's got a price",                              [],     'askprice', 0,    false, '',             ''],
    ['what would it take',                                   [],     'askprice', 0,    false, '',             ''],
    ['how much',                                             [],     'askprice', 0,    false, '',             ''],
    ['name your price',                                      [],     'askprice', 0,    false, '',             ''],
    // firm
    ["I'll give you a thousand gold",                        [],     'offer',    1000, true,  'said',         ''],
    ["I'll give you a thousand",                             [],     'offer',    1000, true,  'said',         'a commitment verb makes the bare figure an amount'],
    ["here's five hundred",                                  [],     'offer',    500,  true,  'said',         ''],
    ['five hundred septims, right now',                      [],     'offer',    500,  true,  'said',         ''],
    ['take three hundred and come upstairs',                 [],     'offer',    300,  true,  'said',         ''],
    ["here's 500 septims, if you want it",                   [],     'offer',    500,  true,  'said',         'a courtesy "if" is not a condition'],
    ["I'll pay you 300 gold - would that do?",               [],     'offer',    300,  true,  'said',         'a tag question is not a condition'],
    ["I'll pay you 1,000 gold",                              [],     'offer',    1000, true,  'said',         '1,000'],
    ["I'll give you 1k",                                     [],     'offer',    1000, true,  'said',         '1k'],
    ['i will pay you two hundred and fifty septims',         [],     'offer',    250,  true,  'said',         'two hundred and fifty'],
    ["and I'll give you fifty",                              [],     'offer',    50,   true,  'said',         'a leading "and" no longer hides the figure'],
    // grey: hypothetical / implied / "would N do?"
    ['would fifty do?',                                      [],     'offer',    50,   false, 'hypothetical', ''],
    ['is 200 enough?',                                       [],     'offer',    200,  false, 'implied',      ''],
    ['a thousand gold for a night with you',                 [],     'offer',    1000, false, 'implied',      ''],
    ['1k gold',                                              [],     'offer',    1000, false, 'implied',      ''],
    ['what if I gave you a hundred septims',                 [],     'offer',    100,  false, 'hypothetical', ''],
    // her "you mean it?" is pending (1000): the answers
    ['yes',                                                  $pend,  'offer',    1000, true,  'confirm',      'ruling C: yes completes it at the remembered amount'],
    ['deal',                                                 $pend,  'offer',    1000, true,  'confirm',      ''],
    ['done',                                                 $pend,  'offer',    1000, true,  'confirm',      ''],
    ["that's right",                                         $pend,  'offer',    1000, true,  'confirm',      ''],
    ['a thousand',                                           $pend,  'offer',    1000, true,  'confirm',      'repeating the amount'],
    ['yes, a thousand, right now',                           $pend,  'offer',    1000, true,  'confirm',      ''],
    ['no, i mean it',                                        $pend,  'offer',    1000, true,  'confirm',      '"no" that means yes'],
    ['no',                                                   $pend,  'withdraw', 0,    false, 'pending',      'ruling C: no cancels it'],
    ['forget it',                                            $pend,  'withdraw', 0,    false, 'pending',      ''],
    ['five hundred',                                         $pend,  'offer',    500,  false, 'implied',      'a different amount replaces it (she asks again)'],
    ['five hundred gold, right now',                         $pend,  'offer',    500,  true,  'said',         'a different FIRM amount replaces it outright'],
    // counter-offers against her quote (225)
    ['how about 150',                                        $q225,  'offer',    150,  false, 'implied',      'a counter'],
    ['150',                                                  $q225,  'offer',    150,  false, 'implied',      'a bare counter while her figure is on the table'],
    ["fine, I'll pay it",                                    $q225,  'offer',    225,  true,  'quote',        'taking her figure'],
    ['give me two minutes',                                  $q225,  'none',     0,    false, '',             'a number in passing is still not money'],
    // no context: still nothing
    ['yes',                                                  [],     'none',     0,    false, '',             'a yes with nothing pending buys nothing'],
    ['take two steps back',                                  [],     'none',     0,    false, '',             'not a hand-over'],
    ["here's two options",                                   [],     'none',     0,    false, '',             'not a hand-over'],
    ['take one for the team',                                [],     'none',     0,    false, '',             'a figure under 10 with no coin word is not a price'],
    ["I'd pay to see that",                                  [],     'none',     0,    false, '',             'an idiom, not an offer (no "you")'],
    ["I'm giving up",                                        [],     'none',     0,    false, '',             'not an offer'],
];
foreach ($pt15 as [$text, $m, $kind, $gold, $firm, $from, $note]) {
    $r = lrgRecogniseIntent($text, null, $m);
    $ok = $r['kind'] === $kind && (int) ($r['gold'] ?? 0) === $gold;
    if ($kind === 'offer') { $ok = $ok && (!empty($r['firm'])) === $firm && (string) ($r['from'] ?? '') === $from; }
    if ($kind === 'withdraw') { $ok = $ok && (string) ($r['from'] ?? '') === $from; }
    check(sprintf('"%s"%s -> %s/%d%s%s', $text, $m ? ' [' . implode(',', array_keys($m)) . ']' : '', $kind, $gold,
        $kind === 'offer' ? ($firm ? ' FIRM' : ' grey') : '', $note !== '' ? "   ($note)" : ''), $ok,
        sprintf('got %s gold=%d firm=%s from=%s why=%s', $r['kind'], (int) ($r['gold'] ?? 0), !empty($r['firm']) ? 'yes' : 'no', (string) ($r['from'] ?? ''), $r['why']));
}
// the request that CREATED the pending offer can never confirm it (the second pass of the same line)
$saveG = [$GLOBALS['HERIKA_NAME'] ?? null, $GLOBALS['gameRequest'] ?? null];
$GLOBALS['HERIKA_NAME'] = 'Katana'; $GLOBALS['gameRequest'] = ['inputtext', 1, 2, 'a thousand'];
$self = ['offer_pending' => ['gold' => 1000, 'at' => lrgNow(), 'expires' => lrgNow() + 120, 'req' => lrgMoneyReqKey(), 'state' => 'asked']];
check('the line that created the pending offer is never read as its own confirmation', (string) (lrgRecogniseIntent('a thousand', null, $self)['from'] ?? '') !== 'confirm');
[$GLOBALS['HERIKA_NAME'], $GLOBALS['gameRequest']] = $saveG;
if ($GLOBALS['HERIKA_NAME'] === null) { unset($GLOBALS['HERIKA_NAME']); }
if ($GLOBALS['gameRequest'] === null) { unset($GLOBALS['gameRequest']); }
$exp = ['offer_pending' => ['gold' => 1000, 'at' => lrgNow() - 200, 'expires' => lrgNow() - 80, 'req' => 'x', 'state' => 'asked']];
check('an expired pending offer is not confirmed by a late "yes"', lrgRecogniseIntent('yes', null, $exp)['kind'] === 'none');
$wd = ['offer_pending' => ['gold' => 1000, 'at' => lrgNow(), 'expires' => lrgNow() + 20, 'req' => 'x', 'state' => 'withdrawn', 'wreq' => 'y']];
check('a withdrawn offer is not confirmed by a later "yes"', lrgRecogniseIntent('yes', null, $wd)['kind'] === 'none');
// number words and forms, on their own
foreach (['two hundred and fifty' => 250, 'five hundred' => 500, 'one thousand five hundred' => 1500, 'fifteen hundred' => 1500,
          'a hundred and fifty' => 150] as $w => $v) {
    check("[pt15] the number words: \"$w\" = $v", lrgWordAmount($w) === $v, (string) lrgWordAmount($w));
}
foreach (['1,000 gold' => 1000, '1k gold' => 1000, 'five hundred septims' => 500, '2,500 septims' => 2500] as $w => $v) {
    check("[pt15] the amount forms: \"$w\" = $v", lrgIntentAmount(lrgIntentClean($w)) === $v, (string) lrgIntentAmount(lrgIntentClean($w)));
}
// the directive shapes for the grey and the unaffordable offer
$gc = ['reasons' => ['not_close_enough'], 'state' => ['pgold' => '1500'], 'price' => ['for_sale' => true, 'free' => false, 'gold' => 225],
    'paid' => ['offer' => 1000, 'accepted' => false, 'firm' => false, 'confirm' => true, 'why' => 'not firm (hypothetical): she asks him to confirm']];
$d = lrgBuildRequestDirective(['mode' => 'closed', 'gate' => $gc, 'intent' => lrgRecogniseIntent("if you were to fuck me i'd give you a thousand gold", null, [])] + $mt);
check('[pt15] a grey offer at/above her floor: ONE confirming line, nothing started', str_contains($d, 'asks him to confirm')
    && str_contains($d, 'does he mean 1000') && str_contains($d, 'Do not start anything yet') && str_contains($d, 'Choose no action'), $d);
check('[pt15] ... and it never quotes a line for her to say', !preg_match('/"[^"]{16,}"/', $d), $d);
$gu = ['paid' => ['offer' => 1000, 'accepted' => false, 'firm' => true, 'why' => 'the player cannot afford it (322 < 1000)']] + $gc;
$d = lrgBuildRequestDirective(['mode' => 'closed', 'gate' => $gu, 'intent' => lrgRecogniseIntent("I'll give you a thousand gold", null, [])] + $mt);
check('[pt15] a firm offer he cannot pay: called out plainly - show the coin first', str_contains($d, 'show the coin first') && str_contains($d, 'Nothing begins'), $d);
$gb = ['paid' => ['offer' => 100, 'accepted' => false, 'firm' => true, 'why' => 'below her floor (100 < 225)']] + $gc;
$d = lrgBuildRequestDirective(['mode' => 'closed', 'gate' => $gb, 'intent' => lrgRecogniseIntent("I'll give you a hundred gold", null, [])] + $mt);
check('[pt15] a firm offer below her floor: not for 100 - her price named', str_contains($d, 'not for 100') && str_contains($d, '225 septims'), $d);
$d = lrgBuildRequestDirective(['mode' => 'closed', 'gate' => $gc, 'intent' => lrgRecogniseIntent('everybody has a price', null, [])] + $mt);
check('[pt15] askprice while for sale: she names 225 and never claims to have no price', str_contains($d, '225 septims')
    && str_contains($d, 'never claims to have no price') && !str_contains($d, 'refuse the question'), $d);

// ------------------------------------------------------------------ J. [0.5.4] escort: follow / wait / release
say();
echo "J. [0.5.4 / pt13] follow / wait / release - outside a scene with her, in every mode\n";
// the context lrgPrepareTurn hands the recogniser OUTSIDE a scene with her: escort => true
$ectx = ['current' => '', 'live' => [], 'sexes' => ['0', '1'], 'furn' => '', 'tier' => 0, 'player' => 'Flowtest Player',
    'npc' => 'Lisette', 'npos' => 1, 'ceiling' => 4, 'req_ceiling' => 4, 'reached' => 4, 'furn_options' => [], 'acts' => [],
    'nearf' => [], 'acts_done' => [], 'auto_ok' => false, 'escort' => true];
$escort = [
    // [utterance, do ('' = no escort at all), why-if-none, note]
    ['uh i need you to come with me and talk to me in private for a moment', 'follow', '', 'PLAYTEST 13, 01:43:10 - logged intent=none'],
    ['okay so come on follow me now',       'follow', '',         'PLAYTEST 13, 01:43:38 - logged intent=none'],
    ['hey are you gonna follow me?',        '',       'question', 'PLAYTEST 13, 01:44:01 - a question stays a question'],
    ['follow me',                           'follow', '', ''],
    ['Follow me.',                          'follow', '', 'punctuation and case'],
    ['come with me',                        'follow', '', ''],
    ['come along',                          'follow', '', ''],
    ['come on',                             'follow', '', 'bare: nothing else said'],
    ['come on then',                        'follow', '', ''],
    ['walk with me',                        'follow', '', ''],
    ['come with me somewhere private',      'follow', '', 'walking somewhere private is not a proposition'],
    ['come with me upstairs',               'follow', '', ''],
    ['come upstairs with me',               'follow', '', ''],
    ['come with me to my room',             'follow', '', ''],
    ["let's go upstairs",                   'follow', '', ''],
    ['will you follow me?',                 'follow', '', 'a polite request is a request'],
    ["why don't you come with me",          'follow', '', 'a polite request, not a negation'],
    ["why not come with me",                'follow', '', '[pt19] the invitation frame is a polite request (was blocked as a question)'],
    ["why not wait here",                   'wait',   '', '[pt19]'],
    ["how about you come with me",          'follow', '', ''],
    ["won't you come with me",              '',       '', "[pt19] documented, not changed: the escort clause's own won't blocker still stands"],
    ['wait here',                           'wait',   '', ''],
    ['stay here',                           'wait',   '', ''],
    ['stay put',                            'wait',   '', ''],
    ['can you wait here for me?',           'wait',   '', ''],
    ['i need you to wait here',             'wait',   '', 'she is the one asked to wait'],
    ['you can go',                          'release', '', ''],
    ['you can go back',                     'release', '', ''],
    ['you can go back to singing now',      'release', '', ''],
    ['stop following me',                   'release', '', 'a gerund is not the stop rail'],
    ["you don't have to follow me anymore", 'release', '', 'its own negation is the release'],
    ["don't follow me",                     '',       'negated',  'a negation stays a negation'],
    ["don't wait here",                     '',       'negated',  ''],
    ['do you want to follow me',            '',       'question', 'owner phrase-matrix row Y4, unchanged'],
    ['are you coming with me?',             '',       'question', ''],
    ['what if you came with me',            '',       'hypothetical', ''],
    ['she said follow me',                  '',       'quoted', ''],
    ['oh come on',                          '',       '',         'exasperation, not a request'],
    ['come on, you cannot be serious',      '',       '',         'not a bare "come on"'],
    ["I'll wait here",                      '',       '',         'the PLAYER waits'],
    ['i want to stay here',                 '',       '',         'the PLAYER stays'],
    ['they will follow me',                 '',       '',         'somebody else follows'],
    ['you can go first',                    '',       '',         'lead the way, not a release'],
    ['come here',                           '',       '',         'CHIM\'s ComeCloser, not following'],
    ["let's go again",                      '',       '',         'still the scene\'s "go again"'],
];
foreach ($escort as [$text, $do, $why, $note]) {
    $r = lrgRecogniseIntent($text, $ectx, []);
    $ok = $do === ''
        ? ($r['kind'] !== LRG_INTENT_ESCORT && ($why === '' || $r['why'] === $why))
        : ($r['kind'] === LRG_INTENT_ESCORT && $r['conf'] === 'high' && (string) ($r['kv']['do'] ?? '') === $do);
    check(sprintf('"%s" -> %s%s', $text, $do === '' ? 'no escort' . ($why !== '' ? " ($why)" : '') : "escort/$do high",
        $note !== '' ? "   ($note)" : ''), $ok, sprintf('got %s/%s conf=%s why=%s', $r['kind'], json_encode($r['kv']), $r['conf'], $r['why']));
}
$c = lrgRecogniseIntent("don't wait here, come with me instead", $ectx, []);
check('"don\'t wait here, come with me instead": the negation governs its own clause, the order stands',
    $c['kind'] === LRG_INTENT_ESCORT && ($c['kv']['do'] ?? '') === 'follow', json_encode($c['kv']));
$c = lrgRecogniseIntent('come with me and take your clothes off', $ectx, []);
check('a second request in the same breath is carried as extra (named, never promised)',
    $c['kind'] === LRG_INTENT_ESCORT && (string) (($c['extra'] ?? [])['kind'] ?? '') === 'undress', json_encode($c['extra'] ?? []));
$c = lrgRecogniseIntent('wait here, I will be back', $ectx, []);
check('"wait here, I will be back" grows no scene "hold" extra', $c['kind'] === LRG_INTENT_ESCORT && empty($c['extra']), json_encode($c['extra'] ?? []));
$c = lrgRecogniseIntent('(Context location: The Winking Skeever, Solitude) Flowtest Player: come with me', $ectx, []);
check('the DLL context prefix (with its own comma) and the speaker prefix are stripped first',
    $c['kind'] === LRG_INTENT_ESCORT && ($c['kv']['do'] ?? '') === 'follow', json_encode($c));
check('the words for the directive say it plainly', lrgRecogniseIntent('follow me', $ectx, [])['words'] === 'you to come along');
// INSIDE a scene with her the escort reading does not exist: the scene's own verbs keep their words
$sctx = $ectx; unset($sctx['escort']); $sctx['tier'] = LRG_TIERS['sexual'] ?? 4;
check('inside a scene "wait here" is still the scene\'s hold', lrgRecogniseIntent('wait here', $sctx, [])['kind'] === 'hold');
check('inside a scene "come with me" is still a climax', lrgRecogniseIntent('come with me', $sctx, [])['kind'] === 'climax');
check('with no context at all (the pre-lock scene path) nothing is an escort', lrgRecogniseIntent('follow me', null, [])['kind'] !== LRG_INTENT_ESCORT);
// the escort-only recogniser: the silent modes the game KNOWS the reason for get no sexual reading at all
$e = lrgRecogniseEscort('follow me');
check('escort-only: "follow me" is recognised', $e['kind'] === LRG_INTENT_ESCORT && ($e['kv']['do'] ?? '') === 'follow', json_encode($e));
foreach (['fuck me', 'take off your clothes', "I'll pay you 200 septims", 'kiss me'] as $s) {
    $e = lrgRecogniseEscort($s);
    check("escort-only: \"$s\" has no reading at all", $e['kind'] === 'none' && $e['kv'] === [], json_encode($e));
}
check('the escort kind is none of the scene\'s actionable kinds and no net kind',
    !in_array(LRG_INTENT_ESCORT, LRG_INTENT_ACTIONABLE, true)
    && !in_array(LRG_INTENT_ESCORT, (array) (lrgConfig()['intent']['net_kinds'] ?? []), true));

// the directive shapes. Seams: LRG_TEST_NPCSTATE is the snapshot, ENABLED_FUNCTIONS is what CHIM offers.
$GLOBALS['PLAYER_NAME'] = 'Flowtest Player';
$lisette = ['scene' => '1', 'adult' => '1', 'on' => '1', 'wit' => '5', 'fac' => 'JobBardFaction', 'mate' => '0', '_age' => 5];
$et = static function (string $mode, string $say, array $state, array $offered) use ($ectx): string {
    $GLOBALS['LRG_TEST_NPCSTATE'] = ['Lisette' => $state];
    $GLOBALS['ENABLED_FUNCTIONS'] = $offered;
    $in = $mode === 'silent' ? lrgRecogniseEscort($say) : lrgRecogniseIntent($say, $ectx, []);
    $turn = ['npc' => 'Lisette', 'cid' => 'x', 'type' => 'inputtext', 'mode' => $mode, 'intent' => $in, 'acts' => [], 'ctx' => $ectx,
        'gate' => ['reasons' => ['quest_scene', 'witnesses'], 'state' => $state], 'offered' => [], 'escort' => lrgEscortFacts('Lisette')];
    $d = lrgBuildRequestDirective($turn);
    unset($GLOBALS['LRG_TEST_NPCSTATE']);
    return $d;
};
$d = $et('closed', 'okay so come on follow me now', $lisette, ['Talk', 'FollowPlayer']);
check('closed (quest scene, witnesses): "follow me" names Follow_<player> plainly and asks for ONE short line',
    str_contains($d, 'choose Follow_Flowtest Player in this same reply') && str_contains($d, 'one short line')
    && str_contains($d, 'This is not intimacy') && !str_contains($d, 'It is not happening'), $d);
check('... and says the game takes her off the stage first (she is in an engine scene)',
    str_contains($d, 'takes Lisette away from what she is busy with first'), $d);
$d = $et('silent', 'follow me', $lisette + ['adult' => '0'], ['Talk', 'FollowPlayer']);
check('silent for a reason the game knows: the escort directive is still built', str_contains($d, 'Follow_Flowtest Player'), $d);
$d = $et('closed', 'follow me', $lisette, ['Talk']);
check('FollowPlayer not offered, but she is on a stage: no action named, the game walks her itself',
    !str_contains($d, 'Follow_') && str_contains($d, 'The game makes Lisette walk') && str_contains($d, 'choose no action'), $d);
$d = $et('closed', 'follow me', ['scene' => '0'] + $lisette, ['Talk']);
check('FollowPlayer not offered and no scene: she answers, and does not pretend to walk',
    str_contains($d, 'Nothing can make Lisette walk') && !str_contains($d, 'Follow_'), $d);
$d = $et('private', 'wait here', $lisette, ['Talk', 'FollowPlayer', 'WaitHere']);
check('wait: nothing to choose (WaitHere is off in the catalog), the glue keeps her, and not Follow_ either',
    str_contains($d, 'the game stops Lisette following Flowtest Player by itself') && str_contains($d, 'choose no movement action this turn - not Follow_Flowtest Player either'), $d);
$d = $et('public', 'you can go back to singing now', $lisette, ['Talk', 'FollowPlayer']);
check('release: the glue lets her go, one short line, no movement action',
    str_contains($d, 'the game lets Lisette go by itself') && str_contains($d, 'choose no movement action'), $d);
$d = $et('closed', 'wait here', ['_age' => 9999] + $lisette, ['Talk']);
check('no recent snapshot: nothing is promised for wait', str_contains($d, 'There is no action for it this turn'), $d);
$sff = ['fol' => 'fw:sff,mate:1,cff:1,pff:0,wait:0,chim:0,ghost:0,slot:1/4,cap:1,prim:1'] + $lisette;
$d = $et('closed', 'follow me', $sff, ['Talk', 'FollowPlayer']);
// [pt17 / script 509] a companion of Simple Follower Framework is routed (escort.sff_owned): the GAME makes her follow again
// through SFF's own calls (LRG_Main.EscortFollow -> LRG_Followers.SffWait), so the directive names nothing to choose
check('a companion of a follower framework: the game itself (SFF) makes her follow again, no CHIM action is named',
    str_contains($d, 'the game itself makes Lisette follow Flowtest Player again') && str_contains($d, 'there is nothing to choose for it')
    && str_contains($d, 'choose no movement action') && !str_contains($d, 'Follow_'), $d);
$GLOBALS['LRG_DLG_TURN'] = ['npc' => 'Lisette', 'scene' => 0, 'fol' => ['verbs' => ['follow', 'wait'], 'asked' => 'follow', 'resolved' => 1]];
$d = $et('closed', 'follow me', ['scene' => '0'] + $lisette, ['Talk']);
check('the menuless follower kind is live: the real entry is named, through <follower_commands>',
    str_contains($d, '<follower_commands>') && !str_contains($d, 'Follow_'), $d);
unset($GLOBALS['LRG_DLG_TURN'], $GLOBALS['ENABLED_FUNCTIONS']);

// ------------------------------------------------------------------ K. [pt19] an invitation is a request, not a negation
say();
echo "K. [0.5.8 / pt19] \"why don't you X\" asks, \"don't X\" refuses (2026-09-23 00:17:38: logged intent=none why=negated)\n";
// the helpers on their own
check('lrgIntentUninvite: an invitation frame reads as "please"', lrgIntentUninvite("well why don't you get naked") === 'well please get naked', lrgIntentUninvite("well why don't you get naked"));
check('... "why not X" and "won\'t you X" too', lrgIntentUninvite("why not strip? won't you strip?") === 'please strip? please strip?', lrgIntentUninvite("why not strip? won't you strip?"));
check('... but not before a stative verb, and never "why won\'t you"',
    lrgIntentUninvite("why don't you want to strip") === "why don't you want to strip" && lrgIntentUninvite("why won't you strip") === "why won't you strip");
// [pt19 pass 2] the complaint keeps its reading however it is intensified: a "won't you" is a frame only when no
// "why" stands earlier in ITS clause (the clause-anchored \K in LRG_INTENT_INVITE - PCRE2 10.42 has no
// variable-length lookbehind); a "why" in the PREVIOUS clause does not reach it
check('... nor "why the hell won\'t you" / "why on earth won\'t you" (an intensified complaint is still a complaint)',
    lrgIntentUninvite("why the hell won't you strip") === "why the hell won't you strip"
    && lrgIntentUninvite("why on earth won t you get naked") === "why on earth won t you get naked",
    lrgIntentUninvite("why the hell won't you strip") . ' | ' . lrgIntentUninvite("why on earth won t you get naked"));
check('... while "well, won\'t you strip" and "why? won\'t you strip" (the why in an earlier clause) are invitations',
    lrgIntentUninvite("well, won't you strip") === "well, please strip" && lrgIntentUninvite("why? won't you strip") === "why? please strip",
    lrgIntentUninvite("well, won't you strip") . ' | ' . lrgIntentUninvite("why? won't you strip"));
check('... and "why not NOT X" is the double negative it reads as', lrgIntentUninvite("why not not get naked") === "why not not get naked", lrgIntentUninvite("why not not get naked"));
check('lrgIntentNegated: an invitation is not a negation, a refusal and a double negative are',
    !lrgIntentNegated("why don't you get naked") && lrgIntentNegated("don't get naked") && lrgIntentNegated("why don't you not get naked"));
check('lrgIntentBlocked: the owner sentence is not blocked at all', lrgIntentBlocked(lrgIntentClean("well we're in a private room already why don't you get naked?")) === '',
    lrgIntentBlocked(lrgIntentClean("well we're in a private room already why don't you get naked?")));
$invite = [
    // [utterance, kind, conf, who, note]
    ["well we're in a private room already why don't you get naked?", 'undress', 'high', 'npc', 'THE OWNER SENTENCE, verbatim (was none/negated; who=both in the first prototype)'],
    ["we're in a private room already, why don't you get naked?",     'undress', 'high', 'npc', 'the aside as its own clause'],
    ["why don't you get naked",                    'undress', 'high', 'npc',    ''],
    ["why don't you just get naked",               'undress', 'high', 'npc',    '"just" is an intensifier, not a stative verb'],
    ["why don't you get naked already",            'undress', 'high', 'npc',    ''],
    ["why don't ya get naked",                     'undress', 'high', 'npc',    ''],
    ["why don't you strip",                        'undress', 'high', 'npc',    ''],
    ["why don't you strip naked for me",           'undress', 'high', 'npc',    ''],
    ["why dont you get naked",                     'undress', 'high', 'npc',    'no apostrophe'],
    ["why don t you get naked",                    'undress', 'high', 'npc',    'speech-to-text dropped the apostrophe (was blocked as a question)'],
    ["why not get undressed?",                     'undress', 'high', 'npc',    '"why not" used to be blocked as a question'],
    ["won't you take your clothes off?",           'undress', 'high', 'npc',    '"won\'t you" used to be blocked as a question'],
    ["won t you strip",                            'undress', 'high', 'npc',    'speech-to-text (was undress/LOW: no frame)'],
    ["how about you take it all off",              'undress', 'high', 'npc',    'unchanged'],
    ["would you get naked for me?",                'undress', 'high', 'npc',    'unchanged'],
    ["why don't you lose the clothes",             'undress', 'high', 'npc',    '"lose the clothes" joins the family'],
    ["why don't we both get naked",                'undress', 'high', 'both',   ''],
    ["why don't we get naked",                     'undress', 'high', 'both',   '"we" in the request itself IS both'],
    ["why don't you undress me",                   'undress', 'high', 'player', 'the who-inversion holds through the frame'],
    ["so, why don't you strip for me?",            'undress', 'high', 'npc',    'a lead-in'],
    ["hey, why don't you get naked and lie down on the bed", 'undress', 'high', 'npc', 'a compound: the first request is the primary'],
    ["we have the room to ourselves, why don't you strip",   'undress', 'high', 'npc',  'the aside\'s "we" is not "both"'],
    ["we're alone now, take your clothes off",               'undress', 'high', 'npc',  'no invitation frame: the aside\'s "we\'re" is still not "both" (was both)'],
    ["we re alone now take your clothes off",                'undress', 'high', 'npc',  'speech-to-text "we re", no comma (was both)'],
    ["we are alone now take your clothes off",               'undress', 'high', 'npc',  '(was both)'],
    ["take my clothes off, why don't you",                   'undress', 'high', 'player', 'a TRAILING tag: the words before it are the request'],
    ["take my clothes off why don't you",                    'undress', 'high', 'player', 'the same without the comma'],
    ["strip for me, why don't you",                          'undress', 'high', 'npc',  ''],
    ["let's both get undressed",                             'undress', 'high', 'both', 'unchanged'],
    ["why don't you get dressed",                            'dress',   'high', 'npc',  'the frame works for the other direction too'],
];
foreach ($invite as [$text, $kind, $conf, $who, $note]) {
    $r = lrgRecogniseIntent($text, null, []);
    check(sprintf('"%s" -> %s/%s who=%s%s', $text, $kind, $conf, $who, $note !== '' ? "   ($note)" : ''),
        $r['kind'] === $kind && $r['conf'] === $conf && (string) ($r['kv']['who'] ?? '') === $who,
        sprintf('got %s/%s kv=%s why=%s', $r['kind'], $r['conf'], json_encode($r['kv']), $r['why']));
}
// what is STILL blocked: every true negation, a double negative, and a question that only LOOKS like an invitation
// ("why don't you WANT / EVER / HAVE ... X" asks about her - the first prototype read "why don't you have any
// clothes on?" as a high-confidence order to put clothes ON)
foreach (["don't get naked", "do not strip", "never take your clothes off", "i don't want you to get naked", "don't get naked yet",
          "don't take your clothes off", "why don't you not get naked", "why don't you want to get naked?", "why don't you want to fuck me?",
          "why don't you ever get naked", "why don't you have any clothes on?", "why don't you want me to take your clothes off",
          "why don't you just admit you want to fuck me"] as $text) {
    $r = lrgRecogniseIntent($text, null, []);
    check(sprintf('"%s" is still a negation', $text), $r['kind'] === 'none' && $r['why'] === 'negated', sprintf('got %s/%s why=%s', $r['kind'], $r['conf'], $r['why']));
}
foreach (["why won't you get naked?", "why won't you fuck me", "why won t you strip"] as $text) {
    $r = lrgRecogniseIntent($text, null, []);
    check(sprintf('"%s" is a complaint: a question, not a request', $text), $r['kind'] === 'none' && $r['why'] === 'question', sprintf('got %s/%s why=%s', $r['kind'], $r['conf'], $r['why']));
}
// the neighbours that must not move
check('"don\'t stop, harder" still keeps the "harder"', lrgRecogniseIntent("don't stop, harder", null, [])['kind'] === 'faster');
check('"don\'t slow down" stays blocked', lrgRecogniseIntent("don't slow down", null, [])['why'] === 'negated');
check('"why don\'t you stop" is a stop (a soft stop ends the scene - owner decision, see pt19-recogniser.md)',
    lrgRecogniseIntent("why don't you stop", null, [])['kind'] === 'stop', json_encode(lrgRecogniseIntent("why don't you stop", null, [])));
check('"why don\'t we stop" is a stop', lrgRecogniseIntent("why don't we stop", null, [])['kind'] === 'stop');
check('"why don\'t you stop teasing me and fuck me" is the act (the gerund guard)', lrgRecogniseIntent("why don't you stop teasing me and fuck me", null, [])['kind'] === 'act');
check('"why don\'t you tell me about yourself" is nothing', lrgRecogniseIntent("why don't you tell me about yourself", null, [])['kind'] === 'none');
check('"why don\'t you sit down" is nothing', lrgRecogniseIntent("why don't you sit down", null, [])['kind'] === 'none');
check('"why not?" on its own is nothing', lrgRecogniseIntent('why not?', null, [])['kind'] === 'none');
check('"how about we talk" is nothing', lrgRecogniseIntent('how about we talk', null, [])['kind'] === 'none');
check('"would you ever get naked" stays hypothetical', lrgRecogniseIntent('would you ever get naked', null, [])['why'] === 'hypothetical');
check('"not now" with no proposal pending is nothing', lrgRecogniseIntent('not now', null, [])['kind'] === 'none');
// a clause that merely OPENS with "not" is NOT a negation: speech-to-text gives no commas, so there is no clause
// to rescue, and the request after it must survive (a clause-initial `^not` blocker was tried and dropped for this)
$r = lrgRecogniseIntent('not so fast kiss me first', null, []);
check('"not so fast kiss me first" keeps the kiss (no comma: the whole line is scanned)', $r['kind'] === 'act' && str_starts_with((string) $r['act'], 'kiss'), json_encode($r));
$r = lrgRecogniseIntent('not so rough slow down', null, []);
check('"not so rough slow down" keeps the pace control', $r['kind'] === 'slower', json_encode($r));
$r = lrgRecogniseIntent('not like that from behind', null, []);
check('"not like that from behind" keeps the position', $r['kind'] === 'act' && str_starts_with((string) $r['act'], 'vaginal'), json_encode($r));
$r = lrgRecogniseIntent('not naked', null, []);
check('"not naked" is never an order (a bare noun: low at most)', $r['conf'] !== 'high', json_encode($r));
$r = lrgRecogniseIntent('not now follow me', $ectx, []);
check('"not now follow me" (no comma) is still the escort', $r['kind'] === LRG_INTENT_ESCORT && ($r['kv']['do'] ?? '') === 'follow', json_encode($r));
// the undress family, her and him: the shedding verbs ("lose / ditch / shed / remove / drop" + a garment), the
// garment "dress" (which the dress branch read as "get dressed": "take off my dress" was a confident order for
// HER to get DRESSED), "bare" in its narrow form, and every garment after "my" naming the player
foreach (['strip' => 'npc', 'strip naked' => 'npc', 'get naked' => 'npc', 'undress' => 'npc', 'get undressed' => 'npc',
          'take it all off' => 'npc', 'lose the clothes' => 'npc', 'bare yourself' => 'npc', 'bare it all' => 'npc', 'take your clothes off' => 'npc',
          'get out of those clothes' => 'npc', 'ditch the armor' => 'npc', 'remove your armour' => 'npc', 'drop your pants' => 'npc',
          'lose the dress' => 'npc', 'take off your dress' => 'npc', 'drop your dress' => 'npc', 'remove your dress' => 'npc',
          'remove your hood' => 'npc', 'shed those robes' => 'npc',
          'strip me' => 'player', 'strip me naked' => 'player', 'get me naked' => 'player', 'undress me' => 'player',
          'take it all off me' => 'player', 'lose my clothes' => 'player', 'bare me' => 'player', 'take my clothes off' => 'player',
          'take off my dress' => 'player', 'take my gloves off' => 'player', 'remove my armour' => 'player'] as $text => $who) {
    $r = lrgRecogniseIntent($text, null, []);
    check(sprintf('"%s" -> undress/high who=%s', $text, $who), $r['kind'] === 'undress' && $r['conf'] === 'high' && (string) ($r['kv']['who'] ?? '') === $who,
        sprintf('got %s/%s kv=%s why=%s', $r['kind'], $r['conf'], json_encode($r['kv']), $r['why']));
}
check('"remove your armour" names the body slot', (lrgRecogniseIntent('remove your armour', null, [])['kv']['part'] ?? '') === 'body');
check('"remove your hood" names the head', (lrgRecogniseIntent('remove your hood', null, [])['kv']['part'] ?? '') === 'head');
check('"put your dress back on" is still a dress', lrgRecogniseIntent('put your dress back on', null, [])['kind'] === 'dress');
check('"get dressed" is still a dress, high', lrgRecogniseIntent('get dressed', null, [])['kind'] === 'dress' && lrgRecogniseIntent('get dressed', null, [])['conf'] === 'high');
check('"drop to your knees" is not an undress (no garment)', lrgRecogniseIntent('drop to your knees', null, [])['kind'] !== 'undress');
check('"drop your gear" is not an undress (a loot line)', lrgRecogniseIntent('drop your gear', null, [])['kind'] !== 'undress');
check('"lose them" is not an undress', lrgRecogniseIntent('lose them', null, [])['kind'] !== 'undress');
check('"bare with me" (the bear/bare slip) is never an order', lrgRecogniseIntent('bare with me', null, [])['conf'] !== 'high', json_encode(lrgRecogniseIntent('bare with me', null, [])));
// [pt19 pass 2] the refuters' holes in the first pass. (a) An intensified complaint is still a complaint: the first
// pass guarded only the adjacent "why won't you", so "why the hell won't you strip" became a high-confidence undress
// and "why the fuck won't you fuck me" an act. A "won't you" is a frame only when no "why" stands earlier in ITS clause.
foreach (["why the hell won't you strip", "why on earth won't you get naked", "why in the world won't you get naked",
          "why the fuck won't you fuck me", "why in gods name won t you strip", "why is it that every time i ask won't you strip"] as $text) {
    $r = lrgRecogniseIntent($text, null, []);
    check(sprintf('"%s" is a complaint: a question, not a request', $text), $r['kind'] === 'none' && $r['why'] === 'question', sprintf('got %s/%s why=%s', $r['kind'], $r['conf'], $r['why']));
}
$r = lrgRecogniseIntent("why not not get naked", null, []);
check('"why not not get naked" (a double negative) is a question, not an undress', $r['kind'] === 'none' && $r['why'] === 'question', json_encode($r));
// ... while a "won't you" whose "why" sits in an EARLIER clause, or has none at all, is the invitation it is
foreach (["well won't you strip", "we're alone now, won't you take your clothes off", "why? won't you strip", "it's late, won t you get naked"] as $text) {
    $r = lrgRecogniseIntent($text, null, []);
    check(sprintf('"%s" -> undress/high who=npc', $text), $r['kind'] === 'undress' && $r['conf'] === 'high' && ($r['kv']['who'] ?? '') === 'npc', json_encode($r));
}
// (b) "get off me" is a dismount: the loose "get ... off" read it as the PLAYER stripping (who=player through "off me"),
// and in a private room the directive would have told the model to undress him. A "get off" with nothing between the
// words is never an undress; "get <something> off" and "get off <garment>" keep their reading.
foreach (['get off me', 'get off of me', 'get off', "why don't you get off me", "why don't you get off of me", 'get off the bed', 'get off my lap'] as $text) {
    $r = lrgRecogniseIntent($text, null, []);
    check(sprintf('"%s" is a dismount, not an undress', $text), $r['kind'] !== 'undress', json_encode($r));
}
foreach (['get it off me' => 'player', 'get everything off' => 'npc', 'get those off' => 'npc', 'get off those clothes' => 'npc',
          'get your clothes off' => 'npc', 'get my boots off' => 'player', 'get that armour off' => 'npc'] as $text => $who) {
    $r = lrgRecogniseIntent($text, null, []);
    check(sprintf('"%s" -> undress/high who=%s (a "get <something> off" keeps its reading)', $text, $who),
        $r['kind'] === 'undress' && $r['conf'] === 'high' && (string) ($r['kv']['who'] ?? '') === $who, sprintf('got %s/%s kv=%s why=%s', $r['kind'], $r['conf'], json_encode($r['kv']), $r['why']));
}
// (c) KNOWN RESIDUAL, made visible (refuter 2): "take me" is in the STRONG act regex (lrg_scene_index.php, the config's
// strong words), so "take me to morthal" / "take me home" - a carriage line - is an unmistakable sex act today, and the
// invitation frame gives its framed twin exactly the same reading (before the frame it was none/negated). These rows pin
// the EQUIVALENCE (framed == un-framed), not the reading: the "take me <place>" guard belongs to the act lane, and the
// dialogue lane's lrgDlgNegatedAt() (LRG_DLG_NEG_WORDS has don't) still reads the framed carriage line as negated.
foreach (['take me to morthal', 'take me home'] as $twin) {
    $a = lrgRecogniseIntent("why don't you " . $twin, null, []); $b = lrgRecogniseIntent($twin, null, []);
    check(sprintf('"why don\'t you %s" resolves exactly as "%s" (today %s/%s %s) - KNOWN RESIDUAL, act lane', $twin, $twin, $b['kind'], $b['conf'], (string) $b['act']),
        $a['kind'] === $b['kind'] && $a['conf'] === $b['conf'] && (string) $a['act'] === (string) $b['act'], json_encode([$a, $b]));
}

// [pt19c final fixer / the STT sum root] the owner's STT sums, read by the ROOT reader itself (no fold by the caller needed)
foreach (['5 hundred gold' => 500, 'five hundred sept ums' => 500, '2 hundred septims' => 200, "a hundred septem's" => 100,
    '5 hundred and fifty gold' => 550, '2,000 septims' => 2000, '25 hundred septims' => 2500, 'here is 200 septums' => 200,
    'Two Hundred Gold' => 200, 'fifty sep tims' => 50] as $w => $v) {
    check("[pt19c final fixer] the STT sum \"$w\" = $v (lrgIntentAmount and lrgDlgNamedAmount)", lrgIntentAmount($w) === $v
        && (!function_exists('lrgDlgNamedAmount') || lrgDlgNamedAmount($w) === $v), lrgIntentAmount($w) . ' / ' . (function_exists('lrgDlgNamedAmount') ? lrgDlgNamedAmount($w) : '-'));
}
check('[pt19c final fixer] ...and a number with no money word is still no amount ("5 hundred men", "the 2 hundred guards")',
    lrgIntentAmount('5 hundred men') === 0 && lrgIntentAmount('the 2 hundred guards') === 0);

say();
printf("%d passed, %d failed\n", $pass, $fails);
if ($fails === 0) { echo "OK\n"; }
exit($fails === 0 ? 0 : 1);
