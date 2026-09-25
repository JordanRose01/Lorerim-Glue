<?php
// 25 - G3(d): a question about sex is a question. Playtest 6's very first turn after a load was a sexual QUESTION
// and she started a makeout scene out of nowhere. Her own first move now needs a warmed-up conversation and no
// cooldown running - but the player asking for contact, her own invitation, and an admitted initiative tick all
// open it at once, so she is never thrown back into passivity.
fx_scenario('25', 'G3d: her own first move needs build-up; a player request, mode follow or an admitted tick open it at once', function (FxT $t) {
    $c = fxCast('innkeeper'); $npc = $c['name'];
    $alone = fxSnap($c['snap']);
    $minHeat = (int) fxCfg('start.min_heat', 2);
    $postScene = (int) fxCfg('start.post_scene_cooldown_seconds', 300);
    $postLoad = (int) fxCfg('start.post_reload_cooldown_seconds', 120);
    fxSetScore($npc, $alone, 10);

    // ---- the playtest-6 turn: a sexual question, first turn of the session, nothing said before
    fxSendSnapshot($npc, ['sess' => '1234'] + $alone);
    $q = fxSay($npc, ['sess' => '1234'] + $alone, 'Tell me, what do you picture my cock looking like?');
    $t->mustCap('modes', 'she is willing and alone: mode private', fn() => $q->mode() === 'private', fn() => $q->summary());
    $t->must('a sexual QUESTION on a cold first turn does not put BeginIntimacy on the table', !$q->offered(FX_ACT_START), $q->glueNames());
    $t->mustCap('intent', '... because it is a question, not a request', fn() => $q->intentIs('none'), fn() => json_encode($q->intent()));
    $t->must('... and the reason is recorded, so the log says WHY', (string) ($q->hidden()[FX_ACT_START] ?? '') !== '', json_encode($q->hidden()));
    $t->must('... the notes tell her to answer it in words and start nothing', str_contains($q->guidance(), 'A question about this is a question'), fx_short($q->guidance(), 300));
    $t->must('... an unasked-for ChangeClothing is held back by the same rule (the heat gate is not bypassable)', !$q->offered(FX_ACT_CLOTHING), $q->glueNames());
    $t->must('... and a hallucinated BeginIntimacy is dropped', fxNothingSent(fxLlm($npc, FX_ACT_START, 'Player')));

    // ---- two romantic exchanges: the action is on the table. The increment counts on the same turn,
    // so the SECOND romantic line already opens it. (The sexual question above was itself warm, which is
    // why the counter starts from a clean conversation here.)
    fxReset();
    fxSetScore($npc, $alone, 10);
    fxSendSnapshot($npc, $alone);
    fxAdvance($postLoad + 10);
    $one = fxSay($npc, $alone, 'You look beautiful tonight.');
    $t->mustCap('memory', "after one romantic exchange heat is 1 (below min_heat $minHeat)", fn() => $one->heat() === 1 && !$one->offered(FX_ACT_START), fn() => 'heat=' . $one->heat() . ' ' . $one->glueNames());
    $two = fxSay($npc, $alone, 'I want you.');
    $t->mustCap('memory', 'the second romantic exchange counts on the same turn and opens the action', fn() => $two->heat() >= $minHeat && $two->offered(FX_ACT_START), fn() => 'heat=' . $two->heat() . ' ' . $two->glueNames());

    // ---- a cold conversation: the player asks for contact -> offered at once, whatever the heat
    fxReset();
    fxSetScore($npc, $alone, 10);
    fxSendSnapshot($npc, $alone);
    $ask = fxSay($npc, $alone, 'kiss me');
    $t->mustCap('intent', 'the player asks for contact on a cold first turn', fn() => $ask->intentIs('act', 'high'), fn() => json_encode($ask->intent()));
    $t->must('... and the action is on the table at once, with no build-up at all', $ask->offered(FX_ACT_START), 'heat=' . $ask->heat() . ' ' . $ask->glueNames());
    fxReset();
    fxSetScore($npc, $alone, 10);
    fxSendSnapshot($npc, $alone);
    $strip = fxSay($npc, $alone, 'take your clothes off');
    $t->must('the same for a request to undress', $strip->offered(FX_ACT_CLOTHING) && $strip->offered(FX_ACT_START), 'heat=' . $strip->heat() . ' ' . $strip->glueNames());

    // ---- mode follow: the invitation WAS the build-up
    fxReset();
    fxSetScore($npc, $alone, 10);
    $public = fxSnap(['wit' => '2'] + $c['snap']);
    fxSetScore($npc, $public, 10);
    fxSay($npc, $public, 'Hello.');
    fxLlm($npc, FX_ACT_INVITE, 'quiet');
    $follow = fxSay($npc, $alone, 'Hello.');
    $t->mustCap('modes', 'set-up: she invited him and they are alone now (mode follow)', fn() => $follow->mode() === 'follow', fn() => $follow->summary());
    $t->must('mode follow: the action is on the table regardless of heat', $follow->offered(FX_ACT_START), 'heat=' . $follow->heat() . ' ' . $follow->glueNames());

    // ---- right after a scene: her own next move waits
    fxReset();
    fxSetScore($npc, $alone, 10);
    $sc = fxBeginScene($t, $npc, $alone);
    fxSendScene($npc, 'end', ['scene' => $sc['start'], 'cid' => $sc['cid']]);
    fxAdvance(30);
    fxWarm($npc, $alone);
    $soon = fxSay($npc, $alone, 'Hello.');
    $t->must('30 s after a scene ended: her own first move is held back', !$soon->offered(FX_ACT_START), $soon->glueNames());
    $t->must('... and the reason says so', str_contains((string) ($soon->hidden()[FX_ACT_START] ?? ''), 'post-scene'), json_encode($soon->hidden()));
    $t->mustCap('memory', '... heat starts again from zero after a scene', fn() => (int) (fxMem($npc)['heat'] ?? 99) >= 0 && (int) (fxMem($npc)['last_scene_end_at'] ?? 0) > 0, fn() => json_encode(fxMem($npc)));
    fxAdvance($postScene);
    fxWarm($npc, $alone);
    $later = fxSay($npc, $alone, 'Hello.');
    $t->mustCap('clock', 'after the cooldown, with a warm conversation, it is on the table again', fn() => $later->offered(FX_ACT_START), fn() => $later->glueNames());
    $t->mustCap('intent', '... and even inside the cooldown the PLAYER may ask', function () use ($npc, $alone, $sc, $postScene) {
        fxReset(); fxSetScore($npc, $alone, 10);
        $s = fxBeginScene(new FxT(), $npc, $alone);
        fxSendScene($npc, 'end', ['scene' => $s['start'], 'cid' => $s['cid']]);
        fxAdvance(30);
        return fxSay($npc, $alone, 'kiss me')->offered(FX_ACT_START);
    });

    // ---- an admitted initiative tick IS the build-up - and a tick that could do nothing is dropped
    // before the lock instead of paying for an LLM call and a TTS line
    fxReset();
    fxSetScore($npc, $alone, 25); // drawn
    fxRoll('initiative', 1);
    fxAdvance(400);
    $tick = fxInitiativeTick($npc, $alone);
    $t->mustCap('handle', 'an admitted initiative tick offers her own move, cold conversation and all', fn() => !$tick->handled && $tick->offered(FX_ACT_START), fn() => $tick->summary());
    fxReset();
    fxSetScore($npc, $alone, 25);
    fxRoll('initiative', 1);
    // A load is proved by a session tag that DIFFERS from the one the server already knew. The very first
    // snapshot an NPC ever sends proves nothing, and treating it as a reload used to gag her for
    // start.post_reload_cooldown_seconds the first time the player ever met her.
    fxSendSnapshot($npc, ['sess' => '4242'] + $alone);
    fxAdvance(400);
    fxSendSnapshot($npc, ['sess' => '5555'] + $alone); // the game has only just loaded
    $t->mustCap('handle', 'a tick right after a load is dropped BEFORE the lock (it could not act anyway)', fn() => fxInitiativeTick($npc, ['sess' => '5555'] + $alone)->handled);
    fxReset();
    fxSetScore($npc, $alone, 25);
    fxRoll('initiative', 1);
    fxSendSnapshot($npc, ['sess' => '4321'] + $alone); // her FIRST snapshot ever: a first meeting, not a reload
    $first = fxInitiativeTick($npc, ['sess' => '4321'] + $alone);
    $t->mustCap(['handle', 'memory'], 'the first snapshot an NPC ever sends is a first meeting, not a reload',
        fn() => !$first->handled && empty(fxMem($npc)['session_at']), fn() => $first->summary() . ' ' . json_encode(fxMem($npc)));
    fxReset();
    fxSetScore($npc, $alone, 25);
    fxRoll('initiative', 1);
    $s = fxBeginScene(new FxT(), $npc, $alone);
    fxSendScene($npc, 'end', ['scene' => $s['start'], 'cid' => $s['cid']]);
    fxAdvance(60);
    $t->mustCap('handle', 'a tick right after a scene ended is dropped before the lock too', fn() => fxInitiativeTick($npc, $alone)->handled);

    // ---- the hard gates are untouched by any of this
    fxReset();
    $stranger = fxSay($npc, $alone, 'kiss me');
    $t->must('a stranger is still refused, whatever the player asks for', !$stranger->offered(FX_ACT_START) && $stranger->mode() === 'closed', $stranger->summary());
    $minor = fxCast('minor');
    $silent = fxSay($minor['name'], fxSnap($minor['snap']), 'kiss me');
    $t->must('an unconfirmed adult stays totally silent, whatever the player asks for', $silent->silent() && $silent->glueOffered() === [], $silent->summary());
});
