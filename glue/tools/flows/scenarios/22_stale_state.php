<?php
// 22 - G4: no stale state. Playtest 6 reloaded a save at 19:33:47 and the next spoken turn was still built as an
// in-scene turn ("Error: no scene is running"). Three independent paths close the row now; any one of them alone
// would have fixed it, and the existing snapshot rule stays as a slow backstop.
fx_scenario('22', 'G4: a funcret, a session change and the first push after a load each close a stale scene row', function (FxT $t) {
    $c = fxCast('innkeeper'); $npc = $c['name'];
    $alone = fxSnap($c['snap']);
    fxSetScore($npc, $alone, 10);
    $notInScene = function (string $why) use ($t, $npc, $alone) {
        $r = fxSay($npc, $alone, 'Hello.');
        $t->mustCap('modes', "$why: the next turn is NOT an in-scene turn", fn() => $r->mode() !== 'scene', fn() => $r->summary());
        $t->must("$why: nothing about a scene is described any more", !str_contains($r->guidance(), '<intimate_scene_now>'));
    };

    // ---- path 1: the game answers a command with "no scene is running"
    $sc = fxBeginScene($t, $npc, $alone);
    $in = $sc['snap'];
    fxSay($npc, $in, 'faster');
    $w = fxReal(fxLlm($npc, FX_ACT_CONTROL, 'faster'));
    $t->must('set-up: a scene is running and a command went out', fxSceneActive($npc) && count($w) === 1, implode(' ', $w));
    fxFuncret($w[0], 'Error: no scene is running');
    $t->mustCap('stale', 'the funcret closes the row in the SAME call', fn() => !fxSceneActive($npc), fn() => json_encode(fxSceneRow($npc)));
    $notInScene('after the funcret');

    // ---- path 2: the game was reloaded - a snapshot with a different session tag
    fxReset();
    fxSetScore($npc, $alone, 10);
    $s1 = ['sess' => '4242'] + $alone;
    fxSendSnapshot($npc, $s1);
    // [0.3 / 11.2] the session is brand new, so her own first move waits out the post-reload cooldown
    fxAdvance((int) fxCfg('start.post_reload_cooldown_seconds', 120) + 10);
    $sc = fxBeginScene($t, $npc, $s1);
    $inSess = ['sess' => '4242'] + $sc['snap'];
    fxSendScene($npc, 'change', ['scene' => $sc['start'], 'cid' => $sc['cid'], 'sess' => '4242']);
    $t->must('set-up: a scene is running and the row carries the session tag', fxSceneActive($npc) && (string) (fxSceneRow($npc)['_sess'] ?? '') === '4242', json_encode(fxSceneRow($npc)));
    fxAdvance(20);
    // the reload lands on a save whose partner was somebody else: the close has to be name-free
    fxSendSnapshot('Mikkel Flowtest', fxSnap(['sess' => '7777', 'ostim' => '0'] + fxCast('bystander')['snap']));
    $t->mustCap('stale', 'a snapshot with a NEW session tag closes every open row, whatever NPC it names', fn() => !fxSceneActive($npc), fn() => json_encode(fxSceneRow($npc)));
    $notInScene('after the reload');
    $t->mustCap('memory', 'the reload is remembered, so her own first move waits out the post-reload cooldown', fn() => (int) (fxMem('Mikkel Flowtest')['session_at'] ?? 0) === fxNow(), fn() => json_encode(fxMem('Mikkel Flowtest')));

    // ---- an OLD game (script 200) sends no session tag at all: nothing may be closed on a guess
    fxReset();
    fxSetScore($npc, $alone, 10);
    $sc = fxBeginScene($t, $npc, $alone);
    fxAdvance(20);
    fxSendSnapshot($npc, ['ostim' => '1'] + $alone); // no sess key
    $t->must('a game that sends no session tag never loses its scene (backward compatible)', fxSceneActive($npc), json_encode(fxSceneRow($npc)));

    // ---- path 3: the first push after a load. ev=sync was deliberately NOT put on the wire (an old server
    // would have read it as a fresh open scene); the running branch just marks its ordinary push with sync=1.
    fxSendScene($npc, 'change', ['scene' => $sc['start'], 'cid' => $sc['cid'], 'sync' => '1', 'sess' => '9111']);
    $t->must('a normal push marked sync=1 keeps the scene and stamps the new session', fxSceneActive($npc) && (string) (fxSceneRow($npc)['_sess'] ?? '') === '9111', json_encode(fxSceneRow($npc)));
    fxSendScene($npc, 'end', ['scene' => $sc['start'], 'cid' => $sc['cid']]);
    $t->must('the no-thread branch closes the row with an ordinary ev=end (an old server understands it too)', !fxSceneActive($npc));
    $notInScene('after the load with no thread');

    // ---- path 4 (unchanged): a newer snapshot that says "not in our scene" is still the slow backstop
    fxReset();
    fxSetScore($npc, $alone, 10);
    $sc = fxBeginScene($t, $npc, $alone);
    fxAdvance(60);
    fxSendSnapshot($npc, $alone); // ostim=0
    $t->mustCap('clock', 'a newer snapshot saying "not in a scene" still closes the row', fn() => fxSay($npc, $alone, 'Hello.')->mode() !== 'scene');

    // ---- and the row a scene really owns is never closed by accident
    fxReset();
    fxSetScore($npc, $alone, 10);
    $sc = fxBeginScene($t, $npc, $alone);
    $in = $sc['snap'];
    fxAdvance(20);
    fxSendSnapshot($npc, $in);
    $t->mustCap('modes', 'a running scene with a fresh in-scene snapshot stays a scene', fn() => fxSay($npc, $in, 'Hello.')->mode() === 'scene');
    $w = fxReal(fxLlm($npc, FX_ACT_CONTROL, 'faster'));
    fxFuncret($w[0] ?? '', 'The pace is faster now.');
    $t->must('a successful command changes nothing about the row', fxSceneActive($npc));
});
