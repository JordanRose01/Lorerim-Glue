<?php
// 01 - R9 (1): a stranger. Nothing is offered, the guidance is the refusal stance, no explicit-language permission, pressure changes nothing.
fx_scenario('01', 'stranger -> not offered, refusal guidance, no explicit permission', function (FxT $t) {
    $c = fxCast('innkeeper'); $npc = $c['name'];
    $snap = fxSnap($c['snap']); // alone, indoors, x=2 in the MCM: the best possible setting - and still a stranger
    fxSetScore($npc, $snap, -15);

    $r = fxSay($npc, $snap, 'Hello.');
    $t->should('cast sanity: profile = ' . $c['profile'], $r->profileId() === $c['profile'], $r->profileId());
    $t->mustCap('modes', 'mode = closed', fn() => $r->mode() === 'closed', fn() => $r->summary());
    $t->mustCap(['modes', 'interest'], 'interest word is curious (score -15), the LLM sees a word and never a number',
        fn() => $r->interestWord() === 'curious' && !preg_match('/-15\b/', $r->guidance()), fn() => $r->summary());
    $t->must('no glue action is offered', $r->glueOffered() === [], $r->glueNames());
    $t->must('gate reason: not_close_enough', in_array('not_close_enough', $r->reasons(), true), implode(',', $r->reasons()));
    $t->mustCap('modes', 'x = null (no explicit wording this turn)', fn() => $r->x() === null, fn() => $r->summary());
    $t->must('boundaries block is injected', str_contains($r->static, '<personal_boundaries>'));
    $t->should('... and it carries the refusal stance', (bool) preg_match('/refus|declin|the answer is no|not a reason/i', $r->static));
    $t->must('no explicit-language permission anywhere in the guidance', !fxHasExplicitPermission($r->guidance()), fn() => fx_short($r->guidance(), 400));
    $t->must('no invitation guidance: SuggestPrivacy is not mentioned', !str_contains($r->guidance(), FX_NAME[FX_ACT_INVITE]));
    $t->should('BeginIntimacy is not advertised either', !str_contains($r->guidance(), FX_NAME[FX_ACT_START]));

    $t->must('hallucinated BeginIntimacy (code) is dropped', fxLlm($npc, FX_ACT_START, 'Player') === []);
    $t->must('hallucinated BeginIntimacy (display name) is dropped', fxLlm($npc, FX_NAME[FX_ACT_START], 'Player') === []);
    $t->must('hallucinated ChangeClothing is dropped', fxLlm($npc, FX_ACT_CLOTHING, 'undress') === []);
    $t->must('hallucinated SuggestPrivacy (code) is dropped', fxLlm($npc, FX_ACT_INVITE, 'home') === []);
    $t->mustCap('actions_v7', 'hallucinated SuggestPrivacy (display name) is dropped', fn() => fxLlm($npc, FX_NAME[FX_ACT_INVITE], 'quiet') === []);
    $t->mustCap('memory', '... and no invitation was recorded', fn() => fxInvite($npc) === null, fn() => json_encode(fxMem($npc)));
    $t->must('a foreign action of the same turn passes untouched', fxLlmLines([fxLine($npc, 'Follow', 'Player')]) === [fxLine($npc, 'Follow', 'Player')]);

    // pressure never forces a yes: the server does not read the player's words, so asking again cannot change what is offered
    $same = true;
    foreach (['Please.', 'I will pay double.', 'I asked nicely three times.'] as $text) {
        fxAdvance(15);
        $again = fxSay($npc, $snap, $text);
        $same = $same && $again->glueOffered() === [] && !fxHasExplicitPermission($again->guidance()) && ($again->mode() ?? 'closed') === 'closed';
    }
    $t->must('asking again, flattery or a bigger offer: still nothing offered, still closed', $same);
    $t->should('the guidance says a refusal is final for this conversation', (bool) preg_match('/final/i', $r->static));

    // colder still
    fxSetScore($npc, $snap, -45);
    $cold = fxSay($npc, $snap, 'Hello.');
    $t->mustCap(['modes', 'interest'], 'score -45 -> indifferent, closed', fn() => $cold->interestWord() === 'indifferent' && $cold->mode() === 'closed', fn() => $cold->summary());

    // a stranger in public is closed, not "public": unwilling NPCs do not invite
    fxSetScore($npc, $snap, -15);
    $pub = fxSay($npc, fxSnap(['wit' => '3'] + $c['snap']), 'Hello.');
    $t->mustCap('modes', 'stranger with witnesses: mode closed (never public), SuggestPrivacy not offered',
        fn() => $pub->mode() === 'closed' && $pub->glueOffered() === [], fn() => $pub->summary());

    // the pre-lock admission drops her initiative tick at zero cost
    fxRoll('initiative', 1);
    $tick = fxInitiativeTick($npc, $snap);
    $t->mustCap('handle', 'initiative tick of a not-willing NPC is dropped before the lock', fn() => $tick->handled, fn() => $tick->summary());
});
