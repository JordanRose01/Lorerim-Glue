<?php
// PHASE 2 d50 / d51 / d52 - [0.5.0] the additive wire, NPC initiative, the hold's movement half.
// V05_EXPANSION_PLAN sections 2, 7.1, 9.2, 14.
require_once __DIR__ . '/../dlg_adapter.php';

fx_scenario('d50', 'wire v0.5 is additive in BOTH directions: a 0.5 server handles ev=calib / ev=resume / ev=lat and the new keys, and a script-401 payload with none of them behaves exactly as 0.4.1 did', function (FxT $t) {
    $npc = 'Brenna Flowtest';
    fxDlgCache($npc, ['I need work.' => [], 'Never mind.' => []]);

    // --- (a) every new ev is HANDLED and none of them reaches the LLM branch
    $mark = fxDlgLogMark();
    // [pt19 v1.0 / S8] k= is the shrunk ten-row set (cm rm fam st route timer x1 apd ms3 tail); an OLD k= with the retired
    // rows (hide guard rb reopen ...) still parses - every pair is stored as sent, nothing reads the retired ones
    $r1 = fxDlgEvent($npc, 'calib', ['v' => 1, 'ref' => '0x00012345', 'src' => 'passive', 'gate' => 1,
        'miss' => '-'], 'cm:1,rm:3,fam:1,st:1,route:2,timer:1,x1:1,apd:750,ms3:1,tail:16');
    $t->must('ev=calib is handled', $r1['res'] === 'handled', $r1['res']);
    $r1b = fxDlgEvent($npc, 'calib', ['v' => 1, 'ref' => '0x00012345', 'src' => 'passive', 'gate' => 1,
        'runs' => 3, 'miss' => '-'], 'rm:3,cm:1,lp:1,fam:1,route:2,apd:750,timer:1,hide:1,guard:1,park:1,rb:1,st:1,x1:1,reopen:1,items:8,plat:1');
    $t->must('...and an older script\'s ten-row k= with the retired rows still parses (handled, stored as sent)',
        $r1b['res'] === 'handled' && (int) (fxDlgStateOf('*install*')['calib']['k']['hide'] ?? -1) === 1, json_encode(fxDlgStateOf('*install*')['calib'] ?? null));
    $r1 = fxDlgEvent($npc, 'calib', ['v' => 1, 'ref' => '0x00012345', 'src' => 'passive', 'gate' => 1,
        'miss' => '-'], 'cm:1,rm:3,fam:1,st:1,route:2,timer:1,x1:1,apd:750,ms3:1,tail:16');
    $r2 = fxDlgEvent($npc, 'resume', ['sid' => 's1', 'layer' => 1, 'after' => 2400]);
    $t->must('ev=resume is handled (v1.0 never resumes: a quiet logging stub for version skew)', $r2['res'] === 'handled', $r2['res']);
    $r3 = fxDlgEvent($npc, 'lat', ['v' => 1, 'ref' => '0x00012345', 'ask' => 120, 'reply' => 6540,
        'voice' => 810, 'total' => 7470, 'sid' => 's1', 'first' => 0]);
    $t->must('ev=lat is handled', $r3['res'] === 'handled', $r3['res']);
    $t->must('none of the three produced a command line',
        $r1['echo'] === '' && $r2['echo'] === '' && $r3['echo'] === '',
        $r1['echo'] . '|' . $r2['echo'] . '|' . $r3['echo']);
    $globals = (string) @file_get_contents(fxPluginDir() . '/globals.php');
    $t->must('lrg_dlg stays a FAST command, so none of them can wait on the LLM semaphore',
        str_contains($globals, "'lrg_dlg'") && str_contains($globals, 'external_fast_commands'),
        fx_short($globals, 300));
    $t->must('and lrg_dlgtalk is deliberately NOT one - it is the module\'s single LLM request',
        !preg_match("/external_fast_commands.*'lrg_dlgtalk'/s", $globals), fx_short($globals, 300));

    // --- (b) the calibration is stored against the INSTALL, not against the NPC
    $inst = fxDlgStateOf('*install*');
    $t->must('the calibration is stored against *install* (it is a property of the install)',
        is_array($inst['calib'] ?? null) && (int) ($inst['calib']['k']['rm'] ?? 0) === 3,
        json_encode($inst['calib'] ?? null));
    // [pt19 v1.0] the per-NPC copy of gate / x1 fed the retired X1 hand-back clause; nothing reads it, so none is kept
    $t->must('no per-NPC calibration copy any more (its only reader, the X1 hand-back, is retired) and no runs= key',
        !isset(fxDlgStateOf($npc)['cal']) && !isset($inst['calib']['runs']), json_encode([fxDlgStateOf($npc)['cal'] ?? null, $inst['calib'] ?? null]));
    $latRing = (array) (fxDlgStateOf($npc)['lat'] ?? []);
    $latRow = (array) ($latRing[0] ?? []);
    $t->must('the latency row landed in the ring buffer',
        count($latRing) === 1 && (int) ($latRow['total'] ?? 0) === 7470, json_encode($latRing));

    // --- (c) the new KEYS on existing messages
    fxDlgEvent($npc, 'facts', ['q' => 'FxWork', 'qal' => 'FxWork:QuestGiver,FxOther:Innkeeper',
        'qgiver' => 1, 'guard' => 1, 'bounty' => 40, 'cf' => '0x00028848']);
    $st = fxDlgStateOf($npc);
    $t->must('qal= parsed into at most 6 quest:alias pairs', count((array) ($st['qal'] ?? [])) === 2,
        json_encode($st['qal'] ?? null));
    $t->must('qgiver= parsed', (int) ($st['qgiver'] ?? -1) === 1, json_encode($st['qgiver'] ?? null));
    $t->must('guard= / bounty= / cf= parsed into the crime facts',
        (int) ($st['crime']['bounty'] ?? 0) === 40 && (int) ($st['crime']['guard'] ?? 0) === 1,
        json_encode($st['crime'] ?? null));
    fxDlgEvent($npc, 'open', ['sid' => 's9', 'cal' => 'rm3cm1rt2g1', 'svck' => 'pricelist']);
    $t->must('cal= on ev=open resolved into four numbers on the INSTALL row (a diagnostic the log prints)',
        (int) (fxDlgStateOf('*install*')['cal']['rm'] ?? 0) === 3 && (int) (fxDlgStateOf('*install*')['cal']['rt'] ?? 0) === 2,
        json_encode(fxDlgStateOf('*install*')['cal'] ?? null));
    $t->must('a nonsense cal= is dropped WHOLE rather than half-read',
        lrgDlgParseCal('!!!') === [] && lrgDlgParseCal('-') === [], json_encode(lrgDlgParseCal('!!!')));

    // --- (d) a script-401 payload: none of the new keys, and the turn is exactly what 0.4.1 built.
    // A FRESH NPC: the bounty written above is a fact with a freshness window, and remembering it for
    // 45 s is the behaviour, not a leak.
    $old = 'Sella Flowtest';
    fxDlgCache($old, ['I need work.' => [], 'Never mind.' => []]);
    $q = fxDlgSay($old, fxDlgSnap(), 'Is there work going?');
    $t->must('a 401 payload still produces the business block', str_contains($q['volatile'], '<business'),
        fx_short($q['volatile'], 120));
    $t->must('and no v0.5 block appears when nothing asked for one',
        !str_contains($q['volatile'], '<she_may_raise') && !str_contains($q['volatile'], '<what_you_can_ask')
        && !str_contains($q['volatile'], '<her_list'), fx_short($q['volatile'], 300));
    fxDlgClearQueue();
    $w = fxDlgLlm($old, array_keys($q['keys'])[0]);
    $t->must('the emitted line still carries PROTOCOL 10.4\'s key order up to z=1',
        count($w) === 1 && fxDlgOrderOk($w[0]), count($w) ? fxParam($w[0]) : '-');

    // --- (e) an unknown ev is logged as "not an error" and still answers handled
    $mark2 = fxDlgLogMark();
    $r4 = fxDlgEvent($npc, 'somethingnew', ['x' => 1]);
    $t->must('an unknown ev is handled, not an error', $r4['res'] === 'handled', $r4['res']);
    $t->must('and it says so in the log',
        str_contains(fxDlgLogFrom($mark2), 'this is not an error'), fx_short(fxDlgLogFrom($mark2), 200));

    // --- (f) [pt19 v1.0 / S8] the v1.0 keys, both directions, and an old payload still parses
    $mark3 = fxDlgLogMark();
    fxDlgEvent($npc, 'open', ['sid' => 's10', 'origin' => 'engine', 'scene' => 1, 'z' => 1, 'sj' => 1, 'sq' => 'MQ102', 'sqj' => 1,
        'drv' => 1, 'hid' => 0]);
    $s10 = (array) (fxDlgStateOf($npc)['session'] ?? []);
    $t->must('ev=open sj=1 sq= sqj= drv= hid=0 are parsed onto the session, and the log line names the basis',
        (int) ($s10['sj'] ?? -1) === 1 && (string) ($s10['sq'] ?? '') === 'MQ102' && (int) ($s10['sqj'] ?? -1) === 1 && (int) ($s10['drv'] ?? -1) === 1
        && (bool) preg_match('/open npc=Brenna Flowtest sid=s10 .* scene=1 sq=MQ102 sqj=1 sj=1\(game\) drv=1 hid=0/', fxDlgLogFrom($mark3)),
        json_encode($s10) . ' ' . fx_short(fxDlgLogFrom($mark3), 300));
    fxDlgEvent($npc, 'open', ['sid' => 's11', 'origin' => 'glue', 'scene' => 0]);
    $s11 = (array) (fxDlgStateOf($npc)['session'] ?? []);
    $t->must('an OLD ev=open without sj / drv still parses: drv defaults to 1, sj from scene && !ambient (0 here)',
        (int) ($s11['drv'] ?? -1) === 1 && (int) ($s11['sj'] ?? -1) === 0, json_encode($s11));
    fxDlgTopics($npc, ['sid' => 's11', 'gen' => 2, 'layer' => 1, 'drv' => 0, 'hc' => 1], [fxDlgEntry(0, 'I need work.'), fxDlgEntry(1, 'Never mind.')]);
    $s11b = (array) (fxDlgStateOf($npc)['session'] ?? []);
    $t->must('lrg_topics drv=0 wins over the carried verdict (F1); hc=1 stamps his own click (F9)',
        (int) ($s11b['drv'] ?? -1) === 0 && (int) (fxDlgStateOf($npc)['last_click']['at'] ?? 0) === fxNow(), json_encode([$s11b['drv'] ?? null, fxDlgStateOf($npc)['last_click'] ?? null]));
    $r5 = fxDlgEvent($npc, 'stopped', ['sid' => 's11', 'why' => 'combat']);
    $t->must('ev=stopped (StopDriving, F2) is handled and makes the session read-only',
        $r5['res'] === 'handled' && (string) ((fxDlgStateOf($npc)['session']['stopped'] ?? [])['why'] ?? '') === 'combat', json_encode(fxDlgStateOf($npc)['session'] ?? null));
    lrgDlgPut('*install*', ['clicks_ok' => 0]);
    $mark4 = fxDlgLogMark();
    fxDlgEvent($npc, 'result', ['sid' => 's11', 'gen' => 2, 'x' => 'a1b2c3d4e5', 'pos' => 0, 'i' => 100, 'kind' => 'plain', 'ok' => 1, 'auto' => 1], 'I need work.');
    $t->must('ev=result auto=1 is parsed, an ok result counts: clicks_ok 1, the log line quotes it',
        (int) (fxDlgStateOf('*install*')['clicks_ok'] ?? 0) === 1 && (int) (fxDlgStateOf($npc)['last_result']['auto'] ?? 0) === 1
        && str_contains(fxDlgLogFrom($mark4), ' auto=1 clicks_ok=1 '), fx_short(fxDlgLogFrom($mark4), 300));
    fxDlgEvent($npc, 'calib', ['v' => 1, 'ref' => '00000000', 'src' => 'forget', 'gate' => 0, 'reset' => 1, 'miss' => 'cm rm fam st'], 'cm:0');
    $t->must('ev=calib reset=1 clears clicks_ok (S3.2)', (int) (fxDlgStateOf('*install*')['clicks_ok'] ?? -1) === 0, json_encode(fxDlgStateOf('*install*')));
    // server -> game: res= is sent EMPTY, adv= / rearm= / amb= ride after z=1
    lrgDlgPut('*install*', ['clicks_ok' => 1]);
    fxDlgClearQueue();
    $line = (string) lrgDlgEmit($npc, ['do' => 'pick', 'sid' => 's11', 'gen' => 2, 'pos' => 0, 'i' => 100, 'txt' => 'I need work.',
        'kind' => 'plain', 'res' => 'pass', 'adv' => 2500, 'rearm' => 1], 'dw50', 'rearm', null);
    $param = (string) ((fxDlgQueue()[0] ?? [])['action'] ?? $line);
    $t->must('res= is sent EMPTY (the slot kept), adv=2500 then rearm=1 after z=1',
        str_contains($param, ';res=;') && (bool) preg_match('/;z=1(;note=[^;]*)?(;svc=[a-z]+)?;adv=2500;rearm=1$/', $param), $param);
    fxDlgClearQueue();
    lrgDlgEmit($npc, ['do' => 'open', 'sid' => '0', 'gen' => 0, 'pos' => -1, 'i' => -1, 'txt' => '', 'kind' => 'plain', 'amb' => 1], 'dw51', 'open', null);
    $t->must('do=open on an ambient actor carries amb=1 after z=1', str_ends_with((string) ((fxDlgQueue()[0] ?? [])['action'] ?? ''), ';amb=1'),
        json_encode(fxDlgQueue()));
});

fx_scenario('d51', '[pt19 v1.0 / section 4] NPC initiative is retired: <she_may_raise> never appears, an older script\'s qi=1 changes nothing, and no initiative state is written', function (FxT $t) {
    $npc = 'Brenna Flowtest';
    $spec = [
        'I hear the mill needs a hand.' => ['quest' => 'FxWork', 'journal' => 1, 'new' => 1],
        'Come on, tell me. (Persuade)' => ['kind' => 'persuade', 'new' => 1],
        'Three nights. (34 gold)' => ['cost' => 34, 'new' => 1],
        'Never mind.' => [],
    ];
    fxDlgCache($npc, $spec, [], []);
    $GLOBALS['LRG_DLG_TEST_QUESTLOG'] = ['FxWork' => ['briefing' => 'Speak to the miller.', 'stage' => 10, 'at' => fxNow()]];
    $q = fxDlgSay($npc, fxDlgSnap(), 'Cold out there.');
    $q2 = fxDlgSay($npc, fxDlgSnap(['qi' => '1', 'qig' => '60']), 'Cold out there.');
    $t->must('she never raises anything herself, with or without the retired qi=1 on the snapshot',
        !str_contains($q['volatile'], '<she_may_raise') && !str_contains($q2['volatile'], '<she_may_raise'), fx_short($q2['volatile'], 300));
    $t->must('no initiative state is written', !isset(fxDlgStateOf($npc)['init_at']) && !isset(fxDlgStateOf($npc)['init_said']),
        json_encode(array_keys(fxDlgStateOf($npc))));
    $t->must('the retired wire keys are not read (qi / qig reach no MCM tier)',
        lrgDlgMcm('qi', 'absent', $npc) === 'absent' && lrgDlgMcm('qig', 'absent', $npc) === 'absent');
    unset($GLOBALS['LRG_DLG_TEST_QUESTLOG']);
});

fx_scenario('d52', 'the hold\'s movement half: hold_move_actions EQUALS LRG_Main.CONV_MOVE_ACTIONS; a live hold hides them unless the player asked her to move, and then do=release goes FIRST', function (FxT $t) {
    $npc = 'Brenna Flowtest';
    // 1. the two lists are one source of truth
    $psc = fxConvMoveActions();
    $cfg = (array) lrgDlgCfg('hold_move_actions', []);
    $t->must('CONV_MOVE_ACTIONS was parsed out of the shipped .psc', count($psc) >= 10, implode(',', $psc));
    $t->must('hold_move_actions is EXACTLY LRG_Main.CONV_MOVE_ACTIONS, same spellings',
        $psc === $cfg, 'psc=' . implode(',', $psc) . ' cfg=' . implode(',', $cfg));

    // 2. a live hold and no movement words: the actions are not on the table.
    // FX_CHIM_ACTIONS does not carry ComeCloser / FollowPlayer, so they are offered explicitly - a hide
    // test that passes because the action was never there proves nothing.
    $offered = array_merge(fxEnabledDefault(), [FX_DLG_ACT, 'ComeCloser', 'FollowPlayer', 'HireCarriage']);
    fxDlgCache($npc, ['I need work.' => [], 'Never mind.' => []]);
    $q = fxDlgSay($npc, fxDlgSnap(['hold' => '1', 'dist' => '140']), 'Is there work going?', 'inputtext', $offered);
    if (function_exists('lrgDlgHoldMovementPolicy')) { lrgDlgHoldMovementPolicy(); }
    $en = array_values((array) ($GLOBALS['ENABLED_FUNCTIONS'] ?? []));
    $t->must('a live hold withholds ComeCloser and FollowPlayer',
        !in_array('ComeCloser', $en, true) && !in_array('FollowPlayer', $en, true), implode(',', $en));
    $t->must('... and nothing else is taken away: Talk is still there',
        in_array('Talk', $en, true), implode(',', $en));

    // 3. the player DID ask her to move: the actions stay, and do=release is prepended
    $q2 = fxDlgSay($npc, fxDlgSnap(['hold' => '1', 'dist' => '140']), 'Come here and follow me a moment.',
        'inputtext', $offered);
    if (function_exists('lrgDlgHoldMovementPolicy')) { lrgDlgHoldMovementPolicy(); }
    $en2 = array_values((array) ($GLOBALS['ENABLED_FUNCTIONS'] ?? []));
    $t->must('when the player asked her to move the actions stay offered',
        in_array('ComeCloser', $en2, true), implode(',', $en2));
    fxDlgClearQueue();
    $GLOBALS['LAST_LLM_RESPONSE'] = ['action' => 'ComeCloser', 'item' => '', 'target' => $npc, 'message' => 'Coming.'];
    $out = array_values((array) lrgDlgPostProcessActions([$npc . "|command|ComeCloser@\r\n"]));
    $t->must('do=release is PREPENDED, before CHIM\'s own movement action',
        count($out) === 2 && str_contains($out[0], ';do=release;') && str_contains($out[1], 'ComeCloser'),
        json_encode($out));
    $t->must('the release carries kind=hold and no position at all',
        count($out) === 2 && str_contains($out[0], ';kind=hold;') && str_contains($out[0], ';pos=-1;'),
        count($out) ? fxParam($out[0]) : '-');

    // 4. no hold at all changes nothing
    unset($GLOBALS['LRG_DLG_HOLD_MOVE']);
    fxDlgCache($npc, ['I need work.' => [], 'Never mind.' => []]);
    $q3 = fxDlgSay($npc, fxDlgSnap(), 'Come here a moment.', 'inputtext', $offered);
    if (function_exists('lrgDlgHoldMovementPolicy')) { lrgDlgHoldMovementPolicy(); }
    $en3 = array_values((array) ($GLOBALS['ENABLED_FUNCTIONS'] ?? []));
    $t->must('with no hold key nothing is hidden and nothing is released',
        in_array('ComeCloser', $en3, true) && !isset($GLOBALS['LRG_DLG_HOLD_MOVE']), implode(',', $en3));

    // 5. a negated request is not a request
    $t->must('"don\'t follow me" is not a movement request',
        (static function () use ($npc) {
            lrgDlgPut($npc, ['utter' => ['text' => "don't follow me, stay put", 'at' => fxNow()]]);
            return lrgDlgPlayerAskedToMove($npc) === '';
        })(), 'negation guard');
});
