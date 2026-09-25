<?php
// 19 - G1: reliable voice control. What the player says is recognised deterministically, named to the model as a
// per-turn directive, and - when the model does not carry it out - performed by the server itself.
// The utterances here are the PLAYER's, verbatim from playtest 6. No line an NPC says appears anywhere.
fx_scenario('19', 'G1: the player asks, the directive names it, and the safety net carries it out when the reply does not', function (FxT $t) {
    $c = fxCast('innkeeper'); $npc = $c['name'];
    $alone = fxSnap($c['snap']);
    fxSetScore($npc, $alone, 10);
    $sc = fxBeginScene($t, $npc, $alone);
    $in = $sc['snap'];

    // ---- the request is recognised and named
    $r = fxSay($npc, $in, 'Take off your clothes.');
    $t->mustCap('intent', 'the utterance is recognised: undress, high confidence, her clothes', fn() => $r->intentIs('undress', 'high') && ($r->intent()['kv']['who'] ?? '') === 'npc', fn() => json_encode($r->intent()));
    $t->mustCap('intent', 'the guidance carries a <player_request> block naming ChangeClothing and undress',
        fn() => $r->hasDirective() && str_contains($r->directive(), 'ChangeClothing') && str_contains($r->directive(), 'undress'), fn() => $r->directive());
    $t->mustCap('intent', '... as an order, because a scene is running and the game confirmed it',
        fn() => str_contains($r->directive(), 'Do it now') && str_contains($r->directive(), 'Do not refuse'), fn() => $r->directive());
    $t->mustCap('intent', '... and the block is the LAST thing in the scene notes', fn() => (bool) preg_match('~<player_request>.*</player_request>\s*</intimate_scene_now>\s*$~s', $r->volatile), fn() => fx_short($r->volatile, 200));

    // ---- the model answers with words only: the server carries the request out itself
    $w = fxReal(fxLlmNothing($npc));
    $t->mustCap('decorate', 'the model chose no action -> the net fires exactly one line', fn() => count($w) === 1, fn() => implode(' ', $w));
    $t->mustCap('decorate', '... and it is the request: ChangeClothing, do=undress, who=npc', fn() => fxCode($w[0]) === FX_ACT_CLOTHING && fxDo($w[0]) === 'undress' && (fxParamKv($w[0])['who'] ?? '') === 'npc', fn() => fxParam($w[0]));
    $t->mustCap('decorate', '... a net line is player-requested, so it carries hold= and never wait= / nowarp=',
        fn() => isset(fxParamKv($w[0])['hold']) && !isset(fxParamKv($w[0])['wait']) && !isset(fxParamKv($w[0])['nowarp']), fn() => fxParam($w[0]));

    // ---- the model DID choose it: one line, never two
    $r = fxSay($npc, $in, 'Take off your clothes.');
    $w = fxReal(fxLlm($npc, FX_ACT_CLOTHING, 'undress'));
    $t->must('the model chose the right action -> exactly one line, no duplicate', count($w) === 1 && fxDo($w[0]) === 'undress', implode(' ', $w));

    // ---- the WHO-INVERSION of playtest 6. "your clothes" and "my clothes" mean the opposite in the two
    // mouths, and the model echoes the PLAYER, so it answers "undress you" - who=npc - to a request about
    // the player's own clothes. The verb was right, so nothing noticed and the wrong person was undressed.
    $r = fxSay($npc, $in, 'I want you to take my clothes off.');
    $t->mustCap('intent', 'the player asking about HIS OWN clothes is recognised as who=player', fn() => $r->intentIs('undress', 'high') && ($r->intent()['kv']['who'] ?? '') === 'player', fn() => json_encode($r->intent()));
    $t->mustCap('intent', '... and the directive names the player, not her', fn() => str_contains($r->directive(), 'undress you'), fn() => $r->directive());
    $w = fxReal(fxLlm($npc, FX_ACT_CLOTHING, 'undress'));   // the model echoed the player: who=npc
    $t->mustCap('decorate', '... the model\'s wrong target is corrected from the player\'s own words: one line, who=player',
        fn() => count($w) === 1 && fxCode($w[0]) === FX_ACT_CLOTHING && fxDo($w[0]) === 'undress' && (fxParamKv($w[0])['who'] ?? '') === 'player', fn() => implode(' ', $w));
    $r = fxSay($npc, $in, 'I want you to take my clothes off.');
    $w = fxReal(fxLlmNothing($npc));
    $t->mustCap('decorate', '... and with no action at all the net builds the same line', fn() => count($w) === 1 && (fxParamKv($w[0])['who'] ?? '') === 'player', fn() => implode(' ', $w));

    // ---- the same blind spot on a VALUE: the right verb with the wrong number must not travel
    $r = fxSay($npc, $in, 'speed 3');
    if ($r->intentIs('speed', 'high')) {
        $w = fxReal(fxLlm($npc, FX_ACT_CONTROL, 'pace 1'));
        $t->mustCap('decorate', 'the right verb with the wrong pace is replaced, not accompanied: one line, speed=3',
            fn() => count($w) === 1 && fxDo($w[0]) === 'speed' && (int) (fxParamKv($w[0])['speed'] ?? 0) === 3, fn() => implode(' ', $w));
        $r = fxSay($npc, $in, 'speed 3');
        $w = fxReal(fxLlm($npc, FX_ACT_CONTROL, 'pace 3'));
        $t->mustCap('decorate', '... and the right pace is left alone', fn() => count($w) === 1 && (int) (fxParamKv($w[0])['speed'] ?? 0) === 3, fn() => implode(' ', $w));
    }

    // ---- low confidence never fires the net
    $r = fxSay($npc, $in, 'naked');
    $t->mustCap('intent', 'a bare noun is recognised at LOW confidence', fn() => $r->intentIs('undress', 'low'), fn() => json_encode($r->intent()));
    $t->mustCap('intent', '... the directive only names what the player may have meant, with no order',
        fn() => str_contains($r->directive(), 'may have asked') && !str_contains($r->directive(), 'Do it now'), fn() => $r->directive());
    $t->mustCap('decorate', '... and the net stays out of it: nothing reaches the game', fn() => fxNothingSent(fxLlmNothing($npc)));

    // ---- a question is not a request, and neither is a negated one
    foreach (['should I take this off?', "don't get naked yet", 'What do you want to try?'] as $said) {
        $q = fxSay($npc, $in, $said);
        $t->mustCap('intent', "\"$said\" is not a request: no directive, no net line", fn() => !$q->hasDirective() && fxNothingSent(fxLlmNothing($npc)), fn() => json_encode($q->intent()));
    }

    // ---- amendment: a 5-minute-old scene row must not silence the net. lrg_scene is event driven and
    // de-duplicated, so in a calm stretch - exactly when the player is steering by voice - the row is old.
    // The running scene is confirmed from the periodic SNAPSHOT instead.
    fxAdvance(300);
    fxSendSnapshot($npc, $in); // the game keeps sending snapshots ~ every 20 s
    $r = fxSay($npc, $in, 'Take off your clothes.');
    $w = fxReal(fxLlmNothing($npc));
    $t->mustCap('decorate', 'a 300 s old scene row with a fresh snapshot: the net still fires', fn() => count($w) === 1 && fxDo($w[0]) === 'undress',
        fn() => 'row age ' . (fxNow() - (int) (fxDb()->t['lrg_scene_state'][$npc]['updated_at'] ?? 0)) . 's: ' . implode(' ', $w));

    // ---- amendment: OUTSIDE a running scene the same words never produce an order. Her consent before a
    // scene is absolute (PROTOCOL 0 / owner addendum 3a), and an imperative here would contradict it.
    fxSendScene($npc, 'end', ['scene' => $sc['start'], 'cid' => $sc['cid']]);
    fxAdvance((int) fxCfg('start.post_scene_cooldown_seconds', 300) + 30);
    fxWarm($npc, $alone);
    $out = fxSay($npc, $alone, 'Take off your clothes.');
    $t->mustCap('modes', 'set-up: no scene is running any more', fn() => $out->mode() === 'private', fn() => $out->summary());
    // [0.3.1 fix pass] It may now be an open invitation - "if she wants it too, say the line AND choose
    // ChangeClothing" - which is what pt7 F4 needed; what it may never be is an order, and the sentence
    // that leaves the decision to her has to be in it either way.
    $t->mustCap('intent', 'outside a scene the directive never orders: no "Do it now", no "Do not refuse"',
        fn() => !str_contains($out->directive(), 'Do it now') && !str_contains($out->directive(), 'Do not refuse')
            && (!$out->hasDirective() || str_contains($out->directive(), 'own choice') || str_contains($out->directive(), 'decides that')), fn() => $out->directive());
    $t->mustCap('intent', '... and a clothing request there names ChangeClothing (pt7 F4: it named nothing)',
        fn() => !$out->hasDirective() || str_contains($out->directive(), 'own choice') || str_contains($out->directive(), 'ChangeClothing'), fn() => $out->directive());
    $t->mustCap('decorate', '... and the net never acts outside a running scene', fn() => fxNothingSent(fxLlmNothing($npc)));
    $t->must('... the prompt does not both forbid and order the same thing', !(str_contains($out->guidance(), 'Do it now') && str_contains($out->guidance(), 'Nothing the player says can decide this')));

    // ---- [pt19] the owner's own sentence (2026-09-23 00:17:38, logged intent=none why=negated): an INVITATION frame
    // ("why don't you X", "why not X", "won't you X") is a request, not a refusal - and the aside's "we're" is not
    // "both". Same room, same private mode as above: recognised, named, and still her choice.
    $out = fxSay($npc, $alone, "well we're in a private room already why don't you get naked?");
    $t->mustCap('intent', '[pt19] "why don\'t you get naked" is recognised: undress, high, HER clothes (was none/negated)',
        fn() => $out->intentIs('undress', 'high') && ($out->intent()['kv']['who'] ?? '') === 'npc', fn() => json_encode($out->intent()));
    $t->mustCap('intent', '... the directive names ChangeClothing and leaves it her choice, never an order',
        fn() => !str_contains($out->directive(), 'Do it now') && !str_contains($out->directive(), 'Do not refuse')
            && (!$out->hasDirective() || str_contains($out->directive(), 'own choice') || str_contains($out->directive(), 'ChangeClothing')), fn() => $out->directive());
    $out = fxSay($npc, $alone, "why don't you want to get naked?");
    $t->mustCap('intent', '[pt19] "why don\'t you WANT to get naked?" is a question about her, not a request: no directive',
        fn() => !$out->hasDirective() && ($out->intent()['kind'] ?? '') === 'none', fn() => json_encode($out->intent()));
    $out = fxSay($npc, $alone, "don't get naked");
    $t->mustCap('intent', '[pt19] "don\'t get naked" is still a refusal: no directive', fn() => !$out->hasDirective(), fn() => json_encode($out->intent()));
    // ---- [pt19 pass 2] the refuters' two holes, end to end. An intensified complaint ("why the hell won't you X") is
    // still the question it was, and "get off me" is a dismount - the loose "get ... off" had it undressing the PLAYER
    // in this very room (who=player through "off me", the directive naming ChangeClothing).
    $out = fxSay($npc, $alone, "why the hell won't you strip");
    $t->mustCap('intent', '[pt19] "why the hell won\'t you strip" is a complaint, not a request: no directive',
        fn() => !$out->hasDirective() && ($out->intent()['kind'] ?? '') === 'none', fn() => json_encode($out->intent()));
    $out = fxSay($npc, $alone, "get off me");
    $t->mustCap('intent', '[pt19] "get off me" is a dismount: not an undress, no ChangeClothing directive',
        fn() => ($out->intent()['kind'] ?? '') !== 'undress' && !str_contains($out->directive(), 'ChangeClothing'),
        fn() => json_encode($out->intent()) . ' ' . $out->directive());
});
