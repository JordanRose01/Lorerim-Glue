<?php
// 15 - R2: NPC-initiated romance. The game only ticks; the SERVER admits or drops the tick before the MAIN lock (PROTOCOL 6.3),
// so an uninterested NPC costs no LLM call and no TTS. Admission = follow-through, or own move (drawn + cooldown + pace dice).
fx_scenario('15', 'lrg_initiative admission: drawn + cooldown + pace dice, every hard gate, zero cost when dropped', function (FxT $t) {
    $cool = (int) fxCfg('initiative.cooldown_seconds', 300);
    $pct = (array) fxCfg('initiative.chance_percent', ['slow' => 15, 'normal' => 30, 'eager' => 50]);
    $c = fxCast('innkeeper'); $npc = $c['name']; // relaxed -> eager pace
    $alone = fxSnap($c['snap']);
    $talkLike = function (FxTurnResult $r, array $codes): bool {
        $have = array_map('strtolower', $r->enabled); sort($have);
        $want = array_map('strtolower', $codes); sort($want);
        return $have === $want;
    };

    // ---- own move, in private
    fxSetScore($npc, $alone, 25); // drawn
    fxRoll('initiative', 1);
    $tick = fxInitiativeTick($npc, $alone);
    $t->mustCap('handle', 'drawn, alone, winning die: admitted', fn() => !$tick->handled, fn() => $tick->summary());
    $t->mustCap(['handle', 'modes'], 'mode = private, initiative flag set, interest word = drawn', fn() => $tick->mode() === 'private' && !empty($tick->turn['initiative']) && $tick->interestWord() === 'drawn', fn() => $tick->summary());
    $t->mustCap(['handle', 'prerequest'], 'actions switched on for the tick; only Talk + BeginIntimacy + ChangeClothing remain', fn() => $tick->functionsOn && $talkLike($tick, [FX_ACT_START, FX_ACT_CLOTHING]), fn() => 'on=' . ($tick->functionsOn ? 1 : 0) . ' ' . implode(',', $tick->enabled));
    $t->mustCap(['handle', 'memory'], 'initiative_at = now', fn() => (int) (fxMem($npc)['initiative_at'] ?? 0) === fxNow(), fn() => json_encode(fxMem($npc)));
    $t->mustCap(['handle', 'modes'], 'her guidance: the move is HERS to make or not - willing, never obliged; the LLM decides whether and how', fn() => $tick->volatile !== '' && str_contains($tick->volatile, '<this_moment>'));
    $t->mustCap(['handle', 'modes'], 'explicit wording is available to her at the MCM level (x = 2): she is an interested adult in a romantic mode', fn() => $tick->x() === 2);
    $w = fxLlm($npc, FX_ACT_START, 'Player');
    $t->mustCap('handle', 'BeginIntimacy chosen on her own tick passes the gate, gentle start', fn() => count($w) === 1 && in_array(fxSceneTier(fxParamKv($w[0])['scene'] ?? ''), ['kissing', 'affection'], true), fn() => implode(' ', $w));

    // ---- cooldown and dice
    fxAdvance(100);
    $t->mustCap(['handle', 'clock'], 'second tick 100 s later: dropped (cooldown ' . $cool . ' s)', fn() => fxInitiativeTick($npc, $alone)->handled);
    $t->mustCap(['handle', 'memory'], 'a dropped tick leaves initiative_at untouched', fn() => (int) (fxMem($npc)['initiative_at'] ?? 0) === fxNow() - 100, fn() => json_encode(fxMem($npc)) . ' now=' . fxNow());
    fxAdvance($cool - 100);
    fxRoll('initiative', (int) $pct['eager'] + 1);
    $t->mustCap(['handle', 'clock', 'dice'], 'cooldown over, die one above her chance (eager ' . $pct['eager'] . '): dropped', fn() => fxInitiativeTick($npc, $alone)->handled);
    fxRoll('initiative', (int) $pct['eager']);
    $t->mustCap(['handle', 'clock', 'dice'], 'die exactly at her chance: admitted (roll <= chance)', fn() => !fxInitiativeTick($npc, $alone)->handled);

    // pace decides the chance: a slow character
    $h = fxCast('housecarl'); $hn = $h['name']; $hs = fxSnap($h['snap']);
    fxSetScore($hn, $hs, 25);
    fxRoll('initiative', (int) $pct['slow'] + 1);
    $t->mustCap(['handle', 'dice'], 'slow pace (strict profile): die ' . ((int) $pct['slow'] + 1) . ' is dropped, where an eager character would have moved', fn() => fxInitiativeTick($hn, $hs)->handled);
    fxRoll('initiative', (int) $pct['slow']);
    $t->mustCap(['handle', 'dice'], 'slow pace: die ' . (int) $pct['slow'] . ' is admitted', fn() => !fxInitiativeTick($hn, $hs)->handled);

    // ---- own move, in public: she can only suggest privacy
    fxReset();
    fxSetScore($npc, $alone, 25);
    fxRoll('initiative', 1);
    $pub = fxInitiativeTick($npc, ['wit' => '2'] + $alone);
    $t->mustCap(['handle', 'modes', 'actions_v7'], 'drawn, in public: admitted, mode public, only Talk + SuggestPrivacy', fn() => !$pub->handled && $pub->mode() === 'public' && $talkLike($pub, [FX_ACT_INVITE]) && $pub->functionsOn, fn() => $pub->summary() . ' ' . implode(',', $pub->enabled));
    $t->mustCap(['handle', 'memory', 'actions_v7'], 'her own invitation is recorded like any other', fn() => fxLlm($npc, FX_ACT_INVITE, 'room') === [] && (fxInvite($npc)['state'] ?? '') === 'pending', fn() => json_encode(fxInvite($npc)));
    $t->mustCap(['handle', 'modes'], 'a hard move on a public tick is dropped', fn() => fxLlm($npc, FX_ACT_START, 'Player') === [] && fxLlm($npc, FX_ACT_CLOTHING, 'undress') === []);

    // ---- not drawn: no own move
    fxReset();
    fxRoll('initiative', 1);
    foreach ([19 => 'interested', -5 => 'curious', -40 => 'indifferent'] as $score => $word) {
        fxSetScore($npc, $alone, $score);
        $t->mustCap('handle', "score $score ($word): dropped", fn() => fxInitiativeTick($npc, $alone)->handled);
    }
    $t->mustCap(['handle', 'memory'], 'none of the dropped ticks wrote initiative_at', fn() => empty(fxMem($npc)['initiative_at']), fn() => json_encode(fxMem($npc)));

    // ---- every hard gate, with a drawn NPC and a winning die
    fxSetScore($npc, $alone, 25);
    $blocks = ['adult=0' => ['adult' => '0'], 'feature off in the MCM (on=0)' => ['on' => '0'], 'combat' => ['combat' => '1'], 'quest scene' => ['scene' => '1'], 'already in an OStim thread' => ['ostim' => '1'],
        'child nearby' => ['witkid' => '1'], 'adult key missing' => ['adult' => null], 'combat key missing' => ['combat' => null]];
    foreach ($blocks as $label => $over) {
        $t->mustCap('handle', "hard gate [$label]: dropped", fn() => fxInitiativeTick($npc, fxSnap($over + $c['snap']))->handled);
    }
    $t->mustCap(['handle', 'clock'], 'no fresh snapshot (the last one is 200 s old): dropped', function () use ($npc, $alone) {
        fxSendSnapshot($npc, $alone);
        fxAdvance(200);
        return fxTurn($npc, 'lrg_initiative', FX_CTX . 'approach')->handled;
    });
    $t->mustCap('handle', 'no snapshot at all: dropped', fn() => fxTurn('Unknown Flowtest', 'lrg_initiative', FX_CTX . 'approach')->handled);
    $t->mustCap('handle', 'the text must be exactly "approach" (after the DLL\'s context prefix); the bare word works too', function () use ($npc, $alone) {
        fxSendSnapshot($npc, $alone);
        $bare = !fxTurn($npc, 'lrg_initiative', 'approach')->handled;
        fxMemSet($npc, ['initiative_at' => null]);
        fxSendSnapshot($npc, $alone);
        $other = fxTurn($npc, 'lrg_initiative', FX_CTX . 'something else');
        return $bare && ($other->handled || $other->glueOffered() === []);
    });
    fxNeedIndex($t);
    $t->mustCap('handle', 'a scene is running (with anyone): dropped', function () use ($npc, $alone, $t) {
        fxReset(); fxRoll('initiative', 1);
        fxSetScore($npc, $alone, 25);
        $by = fxCast('patron'); fxSetScore($by['name'], fxSnap($by['snap']), 25);
        fxBeginScene($t, $by['name'], fxSnap($by['snap']));
        return fxInitiativeTick($npc, $alone)->handled;
    });
    $t->must('ticks, dropped or admitted, write nothing to CHIM\'s event log (only the one scene start of the last step is there)', count(Fx::$events) <= 1, json_encode(Fx::$events));
});
