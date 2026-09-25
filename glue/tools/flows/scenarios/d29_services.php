<?php
// PHASE 2 d29 / d30 / d30b / d31 - services and same-session continuation, critical sessions, scenes,
// lost layers (design 7.3 rows 29 extended, 30, 30b, 31).
require_once __DIR__ . '/../dlg_adapter.php';

fx_scenario('d29', 'services: a real entry hides CHIM\'s own service actions; with no list they stay; hub + "three nights" in ONE utterance completes under same-session continuation; a check sub-layer, a weak score and a second continuation all execute nothing', function (FxT $t) {
    $npc = 'Brenna Flowtest';
    // --- the hub, at the root
    $spec = ["I'd like to rent a room." => ['resp' => 'Of course.'], 'What have you got for sale?' => [],
        'Never mind.' => []];
    fxDlgCache($npc, $spec);
    $q = fxDlgSay($npc, fxDlgSnap(), 'I would like a room for the night.');
    $t->must('RentRoom / OpenInventory / HireCarriage are hidden while the real entries are known',
        !in_array('RentRoom', $q['enabled'], true) && !in_array('OpenInventory', $q['enabled'], true)
        && !in_array('HireCarriage', $q['enabled'], true), implode(',', $q['enabled']));
    $t->must('ForgiveCrime is hidden ALWAYS while menuless is on (decision D4)',
        !in_array('ForgiveCrime', $q['enabled'], true), implode(',', $q['enabled']));
    $t->must('PayBounty is NOT hidden (it checks gold before PlayerPayCrimeGold)',
        in_array('PayBounty', $q['enabled'], true), implode(',', $q['enabled']));

    // --- [pt19 v1.0 / S2.1] with no list known his service words open her REAL list pre-LLM, and CHIM's own shortcut
    // steps aside for that turn (the real entry is about to win). A different NPC: this one has never been read.
    fxDlgReset();
    $q2 = fxDlgSay('Unknown Flowtest', fxDlgSnap(), 'I would like a room for the night.');
    $t->must('[S2.1] with no list known the service words open her real list pre-LLM and CHIM\'s RentRoom steps aside that turn',
        !empty($q2['turn']['open_pending']) && !in_array('RentRoom', $q2['enabled'], true),
        json_encode([$q2['turn']['open_pending'] ?? null, $q2['enabled']]));
    // ...and when her list cannot be opened (the game refused the last open, S2.3) CHIM's own service actions stay:
    // the shortcut is the fallback
    fxDlgReset();
    lrgDlgPut('Unknown Flowtest', ['open_refused' => ['why' => 'a quest scene is running', 'at' => fxNow()]]);
    $q2b = fxDlgSay('Unknown Flowtest', fxDlgSnap(), 'I would like a room for the night.');
    $t->must('with no list known and the open refused, CHIM\'s own service actions stay',
        empty($q2b['turn']['open_pending']) && in_array('RentRoom', $q2b['enabled'], true), implode(',', $q2b['enabled']));

    // --- the duration layer, discovered INSIDE the session: same-session continuation
    $durations = ['One night. (12 gold)', 'Three nights. (34 gold)', 'A week. (75 gold)', 'Never mind.'];
    $spec2 = [];
    foreach ($durations as $d) { $spec2[$d] = ['toplevel' => 0, 'cost' => lrgPromptCost($d), 'resp' => '']; }
    fxDlgReset(fxDlgIndexFrom($spec2), fxDlgLayer($durations));
    fxSendSnapshot($npc, fxDlgSnap());
    // the player's own words that started the turn already named the value
    fxDlgSay($npc, fxDlgSnap(), 'Rent me a room for three nights.');
    fxDlgClearQueue();
    $ent = [];
    foreach ($durations as $i => $d) { $ent[] = fxDlgEntry($i, $d); }
    $r = fxDlgTopics($npc, ['layer' => 1, 'gen' => 1, 'want' => 1, 'ask' => 'rent me a room for three nights',
        'pg' => 300, 'cid' => 'dc01'], $ent);
    $t->must('the duration layer completes in the SAME session', str_contains($r['echo'], ';do=pick;'),
        fx_short($r['echo'], 200));
    $t->must('it picked "Three nights."', str_contains($r['echo'], ';pos=1;'), fx_short($r['echo'], 200));
    $t->must('gold moves exactly once: one decision, one x', count(fxDlgQueue()) === 1, json_encode(fxDlgQueue()));
    $t->must('the exact price rides along', str_contains($r['echo'], ';cost=34;'), fx_short($r['echo'], 200));

    // a second continuation in the SAME session is refused (depth 1)
    fxDlgClearQueue();
    $r2 = fxDlgTopics($npc, ['layer' => 2, 'gen' => 2, 'want' => 1, 'ask' => 'rent me a room for three nights',
        'pg' => 300, 'cid' => 'dc02'], $ent);
    $t->must('a second priced continuation in one session is refused (depth 1)', fxDlgQueue() === [],
        json_encode(fxDlgQueue()));

    // a CHECK-class sub-layer is never executed by continuation
    $checks = ['Come on, tell me. (Persuade)', 'Never mind.'];
    fxDlgReset(fxDlgIndexFrom([$checks[0] => ['toplevel' => 0, 'kind' => 'persuade', 'variant' => 'success'],
        $checks[1] => ['toplevel' => 0]]), fxDlgLayer($checks));
    fxSendSnapshot($npc, fxDlgSnap());
    fxDlgSay($npc, fxDlgSnap(), 'Come on, tell me what you know.');
    fxDlgClearQueue();
    $r3 = fxDlgTopics($npc, ['layer' => 1, 'gen' => 1, 'want' => 1, 'ask' => 'come on tell me what you know',
        'cid' => 'dc03'], [fxDlgEntry(0, $checks[0]), fxDlgEntry(1, $checks[1])]);
    $t->must('a check sub-layer is never executed from a continuation', fxDlgQueue() === [] && $r3['echo'] === '',
        fx_short($r3['echo'], 200));

    // a weak match executes nothing
    fxDlgReset(fxDlgIndexFrom($spec2), fxDlgLayer($durations));
    fxSendSnapshot($npc, fxDlgSnap());
    fxDlgSay($npc, fxDlgSnap(), 'Something about a place to sleep, maybe.');
    fxDlgClearQueue();
    $r4 = fxDlgTopics($npc, ['layer' => 1, 'gen' => 1, 'want' => 1, 'ask' => 'something about a place to sleep maybe',
        'pg' => 300, 'cid' => 'dc04'], $ent);
    $t->must('a weak match executes nothing', fxDlgQueue() === [], json_encode(fxDlgQueue()));
});

fx_scenario('d30', 'critical: a LETHAL twat entry -> do=show, never a click and never a close; a COSTLY entry stays menuless and its back-out is labelled [leaving now ends this]', function (FxT $t) {
    $npc = 'Sigrun Flowtest';
    // LETHAL: twat resolves to DGCrimeResistArrest
    $spec = ['I submit. Take me to jail.' => ['crit' => 2, 'twat' => 'DGCrimeResistArrest', 'scripted' => 1],
        'You will never take me alive.' => ['crit' => 2, 'twat' => 'DGCrimeResistArrest', 'scripted' => 1],
        'I will pay the fine. (200 gold)' => ['crit' => 2, 'twat' => 'DGCrimeResistArrest', 'cost' => 200]];
    fxDlgCache($npc, $spec, ['crit' => 2, 'pg' => 400]);
    $q = fxDlgSay($npc, fxDlgSnap(['pgold' => 400]), 'I submit, take me in.');
    fxDlgClearQueue();
    $w = fxDlgLlm($npc, array_keys($q['keys'])[0]);
    $t->must('a LETHAL entry produces do=show, never do=pick', count($w) === 1 && fxDlgDo($w[0]) === 'show',
        json_encode(array_map('fxParam', $w)));
    $t->must('do=show carries no position at all', count($w) === 1
        && (string) (fxDlgKv($w[0])['pos'] ?? '') === '-1', count($w) ? fxParam($w[0]) : '-');
    $w2 = fxDlgLlm($npc, 'leave');
    $t->must('a LETHAL session is never left by voice either', count($w2) === 1 && fxDlgDo($w2[0]) !== 'pick',
        json_encode(array_map('fxParam', $w2)));

    // COSTLY: any other twat. Stays menuless, and the back-out entry says what leaving costs.
    $spec2 = ['I am listening.' => ['crit' => 1, 'twat' => 'MQ302WalkAway', 'scripted' => 1],
        'I need more time to think.' => ['crit' => 1, 'twat' => 'MQ302WalkAway']];
    fxDlgCache($npc, $spec2, ['crit' => 1]);
    $q3 = fxDlgSay($npc, fxDlgSnap(), 'I am listening, go on.');
    $t->must('a COSTLY back-out entry is labelled [leaving now ends this]',
        str_contains($q3['volatile'], '[leaving now ends this]'), fx_short($q3['volatile'], 400));
    $w3 = fxDlgLlm($npc, array_keys($q3['keys'])[0], '');
    $t->must('a COSTLY entry stays menuless (it parks first, being commit-class)',
        $w3 === [] || fxDlgDo($w3[0]) === 'pick', json_encode(array_map('fxParam', $w3)));
});

fx_scenario('d30b', 'scene (D-17): with NO list open, a speaker in a scene gets no business action, no block, no PENDING and never triggers lrg_dlgtalk; [pt19 v1.0 / model F6] a scene never switches an OPEN list off', function (FxT $t) {
    $npc = 'Brenna Flowtest';
    $spec = ['I need work.' => [], 'Never mind.' => []];
    fxDlgCache($npc, $spec);
    $t->must('with no scene the action is offered',
        in_array(FX_DLG_ACT, fxDlgSay($npc, fxDlgSnap(), 'Work?')['enabled'], true));
    // [F6] the game says the speaker is in a scene WHILE her list is open: the scene never switches the module off -
    // sj / drv / clicks_ok / session.drive_scene decide (d55 walks the read-only journal-scene case)
    fxDlgTopics($npc, ['scene' => 1, 'gen' => 2], [fxDlgEntry(0, 'I need work.')]);
    $q0 = fxDlgSay($npc, fxDlgSnap(), 'Work?');
    $t->must('[F6] an OPEN list with scene=1 is not switched off: the turn stays on and her list is offered',
        !empty($q0['turn']['on']) && in_array(FX_DLG_ACT, $q0['enabled'], true) && str_contains($q0['volatile'], '<business'),
        json_encode([$q0['turn']['on'] ?? null, $q0['turn']['why'] ?? null]));

    // D-17: NO list open, and the snapshot says the speaker is in a scene
    fxDlgCache($npc, $spec, [], [], 1, true);
    $q = fxDlgSay($npc, fxDlgSnap(['scene' => '1']), 'Work?');
    $t->must('a scene speaker is not offered TakeUpBusiness', !in_array(FX_DLG_ACT, $q['enabled'], true),
        implode(',', $q['enabled']));
    $t->must('a scene speaker gets no <business> block', !str_contains($q['volatile'], '<business'),
        fx_short($q['volatile'], 200));
    $t->must('a scene speaker gets no static rules block either', $q['static'] === '', fx_short($q['static'], 120));
    $t->must('the reason is recorded on the turn', str_contains(json_encode($q['turn']['why'] ?? []), 'scene'),
        json_encode($q['turn']['why'] ?? []));
    $t->must('lrg_dlgtalk is never sent for a scene speaker', fxDlgTalk($npc) === 'handled');
    $w = fxDlgLlm($npc, 'T1');
    $t->must('a hallucinated business action on a scene turn is dropped', $w === [], json_encode(array_map('fxParam', $w)));

    // the same with ostim=1 in the snapshot
    fxDlgCache($npc, $spec, [], [], 1, true);
    $q3 = fxDlgSay($npc, fxDlgSnap(['ostim' => '1']), 'Work?');
    $t->must('ostim=1 in the snapshot also stands the module down', !in_array(FX_DLG_ACT, $q3['enabled'], true),
        implode(',', $q3['enabled']));
});

fx_scenario('d31', '[pt19 v1.0 / S1.3, model F22] a closed menu is closed: why=external pending=1 closes the session (no LOST state), its keys are gone, losses is a log counter only, and two losses hand nothing back (the assisted rail is retired)', function (FxT $t) {
    $npc = 'Hroda Flowtest';
    $texts = ['Whiterun would be a fair trade.', 'The Rift, and nothing less.', 'I need more time.'];
    $spec = [];
    foreach ($texts as $x) { $spec[$x] = ['toplevel' => 0]; }
    fxDlgReset(fxDlgIndexFrom($spec), fxDlgLayer($texts));
    lrgDlgPut('*install*', ['clicks_ok' => 1]);
    fxSendSnapshot($npc, fxDlgSnap());
    $ent = [];
    foreach ($texts as $i => $x) { $ent[] = fxDlgEntry($i, $x); }
    fxDlgTopics($npc, ['layer' => 1, 'sid' => 'sdead', 'gen' => 3], $ent);
    fxDlgEvent($npc, 'closed', ['sid' => 'sdead', 'why' => 'external', 'pending' => 1, 'layer' => 1]);
    $st = fxDlgStateOf($npc);
    $t->must('[F22] the session is CLOSED, never lost', (string) ($st['session']['state'] ?? '') === 'closed',
        (string) ($st['session']['state'] ?? '?'));
    $t->must('pending=1 is counted, as a log counter only', (int) ($st['losses'] ?? 0) === 1, (string) ($st['losses'] ?? '?'));
    $q = fxDlgSay($npc, fxDlgSnap(), 'The Rift then.');
    $t->must('the keys of a closed (non-root) layer are not offered any more', count($q['keys']) === 0, count($q['keys']) . ' keys');
    fxDlgClearQueue();
    $w = fxDlgLlm($npc, 'T2', 'The Rift, then.');
    $t->must('a key the model makes up for the gone layer executes nothing', $w === [] && fxDlgQueue() === [],
        json_encode(array_map('fxParam', $w)));

    // a second loss: the assisted-after-losses rail is retired, so the next OPEN closed layer is still driven
    fxDlgTopics($npc, ['layer' => 1, 'sid' => 'sdead2', 'gen' => 4], $ent);
    fxDlgEvent($npc, 'closed', ['sid' => 'sdead2', 'why' => 'external', 'pending' => 1, 'layer' => 1]);
    $t->must('two losses are counted', (int) (fxDlgStateOf($npc)['losses'] ?? 0) === 2,
        (string) (fxDlgStateOf($npc)['losses'] ?? '?'));
    fxDlgTopics($npc, ['layer' => 1, 'sid' => 'slive', 'gen' => 5], $ent);
    $q2 = fxDlgSay($npc, fxDlgSnap(), 'The Rift, and nothing less.');
    $k2 = array_search('The Rift, and nothing less.', array_column($q2['keys'], 'text'), true);
    fxDlgClearQueue();
    $w2 = fxDlgLlm($npc, $k2 === false ? 'T2' : (string) array_keys($q2['keys'])[$k2], 'The Rift it is.');
    $t->must('after two losses an open closed layer is still driven: the key is picked (never handed back as do=show)',
        count($w2) === 1 && fxDlgDo($w2[0]) === 'pick', json_encode(array_map('fxParam', $w2)));
});
