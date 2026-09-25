<?php
// 06 - R9 (6): married, Vigilant, Jarl with a companion. Status and vows decide before the LLM is even asked.
fx_scenario('06', 'profiles: married (refuse / secret), Vigilant (never), Jarl with a companion', function (FxT $t) {
    // ---- married commoner, rule "refuse": closed whatever the affinity, never an invitation
    $c = fxCast('commoner'); $npc = $c['name'];
    $snap = fxSnap(['married' => '1', 'mate' => 'Husband Flowtest'] + $c['snap']);
    fxSetScore($npc, $snap, 40);
    foreach (['alone' => $snap, 'in public' => ['wit' => '3'] + $snap] as $where => $s) {
        $r = fxSay($npc, $s, 'Hello.');
        $t->must("married (refuse), $where: reason married, nothing offered", in_array('married', $r->reasons(), true) && $r->glueOffered() === [], $r->summary());
        $t->mustCap('modes', "married (refuse), $where: mode closed, x null, no invitation", fn() => $r->mode() === 'closed' && $r->x() === null && !str_contains($r->guidance(), FX_NAME[FX_ACT_INVITE]), fn() => $r->summary());
        $t->must("married (refuse), $where: no explicit permission", !fxHasExplicitPermission($r->guidance()));
    }
    fxRoll('initiative', 1);
    $t->mustCap('handle', 'married (refuse): her initiative tick is dropped although the score says drawn', fn() => fxInitiativeTick($npc, $snap)->handled);
    $t->must('married to the PLAYER is not a block', (function () use ($npc, $c) {
        $s = fxSnap(['married' => '1', 'pspouse' => '1'] + $c['snap']);
        fxSetScore($npc, $s, 10);
        fxWarm($npc, $s); // [0.3] 11.2: her own first move needs a warmed-up conversation
        return fxSay($npc, $s, 'Hello.')->offered(FX_ACT_START);
    })());

    // ---- married tavern patron, rule "secret": +20 on the threshold, nobody at all around, companions count
    fxReset();
    $c = fxCast('patron'); $npc = $c['name'];
    $snap = fxSnap(['married' => '1', 'mate' => 'Husband Flowtest'] + $c['snap']);
    $min = (int) fxProfile($npc, $snap)['min_affinity'];
    fxAff($npc, $min + 10);
    $r = fxSay($npc, $snap, 'Hello.');
    $t->must('married (secret): 10 over the plain threshold is not enough (effective threshold +20)', !$r->offered(FX_ACT_START) && in_array('not_close_enough', $r->reasons(), true), $r->summary());
    fxAff($npc, $min + 25);
    fxWarm($npc, $snap); // [0.3] 11.2
    $r = fxSay($npc, $snap, 'Hello.');
    $t->must('married (secret): 25 over it, alone: offered', $r->offered(FX_ACT_START), $r->summary());
    $t->mustCap('interest', 'married (secret): the interest score uses the EFFECTIVE threshold (25 over -> score 5)', fn() => (int) ($r->turn['interest']['score'] ?? 999) === 5, fn() => json_encode($r->turn['interest'] ?? null));
    $t->shouldCap('modes', 'married (secret): the setting calls for discretion and the guidance says so', fn() => (bool) preg_match('/discre|secret|careful|risk|found out|nobody must/i', $r->guidance()));
    $r = fxSay($npc, ['witfol' => '1'] + $snap, 'Hello.');
    $t->must('married (secret): a companion in the room blocks it (companion_present)', !$r->offered(FX_ACT_START) && in_array('companion_present', $r->reasons(), true), $r->summary());
    $t->mustCap(['modes', 'actions_v7'], 'married (secret) + companion: willing, only privacy is missing -> mode public, SuggestPrivacy only', fn() => $r->mode() === 'public' && $r->glueOffered() === [FX_ACT_INVITE], fn() => $r->summary());
    $wire = (function () use ($npc, $snap) { fxSay($npc, $snap, 'Hello.'); return fxLlm($npc, FX_ACT_START, 'Player'); })();
    $t->must('married (secret): the game gets the strict privacy limits (maxwit=0;folok=0)', count($wire) === 1 && str_contains($wire[0], 'maxwit=0;folok=0'), implode(' ', $wire));

    // ---- Vigilant of Stendarr: never
    fxReset();
    $c = fxCast('vigilant'); $npc = $c['name'];
    $snap = fxSnap($c['snap']);
    fxAff($npc, 100);
    fxRoll('*', 1);
    foreach (['alone' => $snap, 'in public' => ['wit' => '3'] + $snap] as $where => $s) {
        $r = fxSay($npc, $s, 'Hello.');
        $t->must("Vigilant, $where: profile never, nothing offered", in_array('never', $r->reasons(), true) && $r->glueOffered() === [], $r->summary());
        $t->mustCap('modes', "Vigilant, $where: mode silent, both guidance strings empty, x null", fn() => $r->mode() === 'silent' && $r->silent() && $r->x() === null, fn() => $r->summary() . ' static=' . strlen($r->static) . ' volatile=' . strlen($r->volatile));
    }
    $t->must('Vigilant: every glue line is dropped', fxLlm($npc, FX_ACT_START, 'Player') === [] && fxLlm($npc, FX_ACT_INVITE, 'quiet') === [] && fxLlm($npc, FX_ACT_CLOTHING, 'undress') === []);
    $t->mustCap('handle', 'Vigilant: initiative tick dropped', fn() => fxInitiativeTick($npc, $snap)->handled);
    $t->mustCap('memory', 'Vigilant: nothing recorded', fn() => fxInvite($npc) === null);

    // ---- a Jarl, in her hall
    fxReset();
    $c = fxCast('jarl'); $npc = $c['name'];
    $snap = fxSnap($c['snap']);
    fxSetScore($npc, $snap, -30);
    $r = fxSay($npc, $snap, 'Hello.');
    $t->should('cast sanity: profile = jarl', $r->profileId() === 'jarl', $r->profileId());
    $t->must('Jarl, stranger: nothing offered', $r->glueOffered() === [], $r->summary());
    $t->mustCap(['modes', 'interest'], 'Jarl, stranger: closed, indifferent', fn() => $r->mode() === 'closed' && $r->interestWord() === 'indifferent', fn() => $r->summary());

    fxSetScore($npc, $snap, 25);
    $r = fxSay($npc, ['witfol' => '1'] + $snap, 'Hello.');
    $t->must('Jarl, trusted, the player\'s companion in the room: companion_present, no hard move offered', in_array('companion_present', $r->reasons(), true) && !$r->offered(FX_ACT_START) && !$r->offered(FX_ACT_CLOTHING), $r->summary());
    $t->mustCap(['modes', 'actions_v7'], 'Jarl + companion: mode public, only SuggestPrivacy', fn() => $r->mode() === 'public' && $r->glueOffered() === [FX_ACT_INVITE], fn() => $r->summary());
    $t->must('Jarl + companion: a hallucinated BeginIntimacy is dropped', fxLlm($npc, FX_ACT_START, 'Player') === []);
    $t->mustCap(['modes', 'handle', 'actions_v7'], 'Jarl + companion: drawn, so her own tick is admitted - and it can only suggest privacy', function () use ($npc, $snap) {
        fxRoll('initiative', 1);
        $tick = fxInitiativeTick($npc, ['witfol' => '1'] + $snap);
        return !$tick->handled && $tick->mode() === 'public' && $tick->glueOffered() === [FX_ACT_INVITE];
    });
    fxWarm($npc, $snap); // [0.3] 11.2
    $r = fxSay($npc, $snap, 'Hello.');
    $t->must('Jarl, trusted, truly alone: offered', $r->offered(FX_ACT_START), $r->summary());
    $t->shouldCap('modes', 'Jarl in her hall (ltype=castle, very strict): discreet rather than blunt', fn() => (bool) preg_match('/discre|scandal|careful|guarded|reputation|coy|quietly/i', $r->guidance()));
    $wire = fxLlm($npc, FX_ACT_START, 'Player');
    $t->must('Jarl: the game is told that companions are not tolerated (folok=0)', count($wire) === 1 && str_contains($wire[0], 'maxwit=0;folok=0'), implode(' ', $wire));

    // a relaxed profile tolerates the companion
    fxReset();
    $c = fxCast('innkeeper'); $npc = $c['name'];
    $snap = fxSnap(['witfol' => '1'] + $c['snap']);
    fxSetScore($npc, $snap, 10);
    fxWarm($npc, $snap); // [0.3] 11.2
    $r = fxSay($npc, $snap, 'Hello.');
    $t->must('innkeeper with the player\'s companion present: still offered (followers_ok)', $r->offered(FX_ACT_START), $r->summary());
});
