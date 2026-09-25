<?php
// 03 - R9 (3) / R3: the same NPC later, alone, inside the expiry: follow-through. After the expiry: ordinary private behaviour.
fx_scenario('03', 'invitation remembered -> alone -> follow-through offered; explicit permission at x=2, not at x=0; expiry', function (FxT $t) {
    fxNeedIndex($t);
    $ttl = (int) fxCfg('invitation.ttl_seconds', 1800);
    $c = fxCast('commoner'); $npc = $c['name'];
    $public = fxSnap(['wit' => '3', 'interior' => '0', 'loc' => 'Flowtest Market', 'ltype' => 'city', 'cellown' => 'none', 'home' => '0', 'nhome' => 'Greywater Farm'] + $c['snap']);
    $alone = fxSnap(['wit' => '0', 'interior' => '1', 'loc' => 'Greywater Farm', 'ltype' => 'house', 'cellown' => 'npc', 'home' => '1', 'nhome' => 'Greywater Farm'] + $c['snap']);
    $invite = function () use ($npc, $public) { // the public part of the story, replayed for every branch
        fxReset();
        fxSetScore($npc, $public, 10); // interested, NOT drawn: whatever she does later is follow-through, not an own move
        fxSay($npc, $public, 'Hello.');
        fxLlm($npc, FX_ACT_INVITE, 'home', 'one spoken line');
        return fxInvite($npc);
    };

    // ---- A. player speech, ten minutes later, at her home
    $made = $invite();
    $t->mustCap(['actions_v7', 'memory'], 'set-up: the invitation is pending', fn() => ($made['state'] ?? '') === 'pending', fn() => json_encode($made));
    fxAdvance(600);
    $r = fxSay($npc, $alone, 'Hello.');
    $t->mustCap(['modes', 'memory'], 'A: mode = follow', fn() => $r->mode() === 'follow', fn() => $r->summary());
    $t->mustCap(['modes', 'memory'], 'A: BeginIntimacy + ChangeClothing offered, SuggestPrivacy and ChangeIntimacy not',
        fn() => $r->glueOffered() === [FX_ACT_START, FX_ACT_CLOTHING], fn() => $r->glueNames());
    $t->mustCap(['modes', 'memory'], 'A: the turn carries the pending invite', fn() => ($r->turn['invite']['state'] ?? '') === 'pending' && ($r->turn['invite']['place'] ?? '') === 'home');
    $t->mustCap(['modes', 'memory'], 'A: x = 2 and the guidance permits explicit wording', fn() => $r->x() === 2 && fxHasExplicitPermission($r->guidance()), fn() => $r->summary());
    $t->shouldCap(['modes', 'memory'], 'A: follow-through guidance names BeginIntimacy', fn() => str_contains($r->volatile, FX_NAME[FX_ACT_START]));
    $t->shouldCap(['modes', 'memory'], 'A: ... and differs from the ordinary private guidance', function () use ($npc, $alone, $r) {
        $keep = $GLOBALS['db']; $GLOBALS['db'] = new FxDb(); // a world in which nothing was ever suggested; same clock, same affinity
        $plain = fxSay($npc, $alone, 'Hello.');
        $GLOBALS['db'] = $keep;
        return $plain->mode() === 'private' && $plain->volatile !== $r->volatile;
    });

    // MCM level 0 must win over everything
    $r0 = fxSay($npc, ['x' => '0'] + $alone, 'Hello.');
    $lvl2 = (string) (fxCfg('scene_talk.explicitness_words', [])[2] ?? '');
    $t->mustCap(['modes', 'memory'], 'A: snapshot x=0: x = 0, still follow', fn() => $r0->mode() === 'follow' && $r0->hasX() && $r0->x() === 0, fn() => $r0->summary());
    $t->mustCap(['modes', 'memory'], 'A: snapshot x=0: the level-2 wording rule is not in the guidance', fn() => $lvl2 === '' || !str_contains($r0->guidance(), $lvl2));
    $t->shouldCap(['modes', 'memory'], 'A: snapshot x=0: no explicit-permission vocabulary at all', fn() => !fxHasExplicitPermission($r0->guidance()), fn() => fx_short($r0->volatile, 400));
    $rn = fxSay($npc, ['x' => null] + $alone, 'Hello.');
    $t->mustCap(['modes', 'memory'], 'A: snapshot without x: the level falls back to scene_talk.explicitness', fn() => $rn->x() === (int) fxCfg('scene_talk.explicitness', 2), fn() => $rn->summary());

    $r = fxSay($npc, $alone, 'Hello.');
    $wire = fxLlm($npc, FX_ACT_START, 'Player');
    $t->must('A: BeginIntimacy passes the gate', count($wire) === 1, implode(' ', $wire));
    $t->mustCap('actions_v7', 'A: param = ok=1;cid;npc + scene;undress;furn;fscene;maxwit;folok', fn() => fxPrefixOk($wire[0] ?? '', $npc)
        && fxShape($wire[0] ?? '', 'scene=[^;]*;undress=[01];furn=[^;]*;fscene=[^;]*;maxwit=\d+;folok=[01]'), fn() => fxParam($wire[0] ?? ''));
    $start = fxParamKv($wire[0] ?? '')['scene'] ?? '';
    $t->must('A: she makes her move at the GENTLE tier (start scene is kissing / affection)', in_array(fxSceneTier($start), ['kissing', 'affection'], true), "scene=$start tier=" . (fxSceneTier($start) ?? 'unknown'));
    $t->mustCap(['modes', 'memory'], 'A: invite state -> followed', fn() => (fxInvite($npc)['state'] ?? '') === 'followed', fn() => json_encode(fxInvite($npc)));

    // ---- B. she acts on her own: an admitted lrg_initiative tick (dice are irrelevant for follow-through)
    $invite();
    fxAdvance(120);
    fxRoll('initiative', 100);
    $tick = fxInitiativeTick($npc, $alone);
    $t->mustCap(['handle', 'memory'], 'B: follow-through tick is admitted even with a failing die', fn() => !$tick->handled && $tick->mode() === 'follow', fn() => $tick->summary());
    $t->mustCap(['handle', 'memory'], 'B: initiative flag set, initiative_at = now', fn() => !empty($tick->turn['initiative']) && (int) (fxMem($npc)['initiative_at'] ?? 0) === fxNow(), fn() => json_encode(fxMem($npc)));
    $t->mustCap(['handle', 'prerequest'], 'B: actions are switched on for this tick; only Talk + BeginIntimacy + ChangeClothing remain', function () use ($tick) {
        $have = array_map('strtolower', $tick->enabled); sort($have);
        $want = array_map('strtolower', [FX_ACT_START, FX_ACT_CLOTHING]); sort($want);
        return $tick->functionsOn && $have === $want;
    }, fn() => 'on=' . ($tick->functionsOn ? 1 : 0) . ' enabled=' . implode(',', $tick->enabled));
    $wire = fxLlm($npc, FX_ACT_CLOTHING, 'undress');
    $t->mustCap(['handle', 'actions_v7'], 'B: ChangeClothing on the tick -> do=undress;who=npc;part=all + the witness limits (outside a scene)',
        fn() => count($wire) === 1 && fxPrefixOk($wire[0], $npc) && fxShape($wire[0], 'do=undress;who=npc;part=all;maxwit=\d+;folok=[01]'), fn() => implode(' ', $wire));
    $t->mustCap(['handle', 'memory'], 'B: invite state -> followed', fn() => (fxInvite($npc)['state'] ?? '') === 'followed', fn() => json_encode(fxInvite($npc)));
    fxAdvance(10);
    $again = fxInitiativeTick($npc, $alone);
    $t->mustCap(['handle', 'memory'], 'B: a second tick 10 s later is dropped (followed, not drawn, inside the 30 s guard)', fn() => $again->handled, fn() => $again->summary());

    // ---- C. after the expiry: ordinary private behaviour
    $invite();
    fxAdvance($ttl + 1);
    fxWarm($npc, $alone); // [0.3] with the invitation gone she is back to needing a warmed-up conversation (11.2)
    $r = fxSay($npc, $alone, 'Hello.');
    $t->mustCap(['modes', 'clock', 'memory'], 'C: ttl passed: mode = private, no invite on the turn, none in memory',
        fn() => $r->mode() === 'private' && empty($r->turn['invite']) && fxInvite($npc) === null, fn() => $r->summary() . ' ' . json_encode(fxInvite($npc)));
    $t->must('C: BeginIntimacy is still offered (she is willing and it is private)', $r->offered(FX_ACT_START), $r->glueNames());
    $tick = fxInitiativeTick($npc, $alone);
    $t->mustCap(['handle', 'clock', 'memory'], 'C: and her tick is dropped: no pending invite, interested but not drawn', fn() => $tick->handled, fn() => $tick->summary());

    // ---- D. one second before the expiry it still counts
    $invite();
    fxAdvance($ttl - 1);
    $r = fxSay($npc, $alone, 'Hello.');
    $t->mustCap(['modes', 'clock', 'memory'], 'D: ttl - 1 s: still follow', fn() => $r->mode() === 'follow', fn() => $r->summary());

    // ---- E. she is no longer willing: the invitation is withdrawn
    $invite();
    fxAdvance(300);
    fxSetScore($npc, $alone, -10);
    $r = fxSay($npc, $alone, 'Hello.');
    $t->mustCap(['modes', 'memory'], 'E: no longer willing: mode closed, nothing offered, invite removed',
        fn() => $r->mode() === 'closed' && $r->glueOffered() === [] && fxInvite($npc) === null, fn() => $r->summary() . ' ' . json_encode(fxInvite($npc)));

    // ---- F. a scene with her that ends clears the invitation
    $invite();
    fxAdvance(60);
    $sc = fxBeginScene($t, $npc, $alone);
    fxSendScene($npc, 'end', ['scene' => $sc['start'], 'cid' => $sc['cid']]);
    $t->mustCap(['modes', 'memory'], 'F: ev=end of a scene with her removes the invitation', fn() => fxInvite($npc) === null, fn() => json_encode(fxInvite($npc)));
});
