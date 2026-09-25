<?php
// 02 - R9 (2) / R3: an interested NPC in public. No hard move here: she steers toward privacy, the place comes from real
// location facts, and the server remembers the invitation.
fx_scenario('02', 'interested NPC in public -> SuggestPrivacy only, place from location facts, invitation recorded', function (FxT $t) {
    $ttl = (int) fxCfg('invitation.ttl_seconds', 1800);
    $setEq = fn(array $a, array $b) => !array_diff($a, $b) && !array_diff($b, $a);

    // ---- A. market place: she lives elsewhere, this is no inn
    $c = fxCast('commoner'); $npc = $c['name'];
    $snapA = fxSnap(['wit' => '3', 'interior' => '0', 'loc' => 'Flowtest Market', 'ltype' => 'city', 'cellown' => 'none', 'home' => '0', 'nhome' => 'Greywater Farm'] + $c['snap']);
    fxSetScore($npc, $snapA, 10);
    $r = fxSay($npc, $snapA, 'Hello.');
    $t->mustCap('modes', 'A: mode = public', fn() => $r->mode() === 'public', fn() => $r->summary());
    $t->mustCap(['modes', 'interest'], 'A: interest word = interested (score 10)', fn() => $r->interestWord() === 'interested', fn() => $r->summary());
    $t->must('A: gate reason is witnesses, nothing else', $r->reasons() === ['witnesses'], implode(',', $r->reasons()));
    $t->must('A: BeginIntimacy, ChangeClothing, ChangeIntimacy are NOT offered', !$r->offered(FX_ACT_START) && !$r->offered(FX_ACT_CLOTHING) && !$r->offered(FX_ACT_CONTROL), $r->glueNames());
    $t->mustCap('actions_v7', 'A: SuggestPrivacy is the only glue action offered', fn() => $r->glueOffered() === [FX_ACT_INVITE], fn() => $r->glueNames());
    $t->must('A: CHIM\'s own actions stay available in public', $r->offered('Follow') && $r->offered('Talk'));
    $t->mustCap('modes', 'A: places = home + quiet (home: her editor location has a name; no room: this is not an inn)',
        fn() => $setEq($r->places(), ['home', 'quiet']), fn() => 'places=' . implode(',', $r->places()));
    $t->mustCap('modes', 'A: guidance is a <this_moment> block', fn() => str_contains($r->volatile, '<this_moment>'));
    $t->shouldCap('modes', 'A: the guidance names SuggestPrivacy', fn() => str_contains($r->guidance(), FX_NAME[FX_ACT_INVITE]));
    $t->shouldCap('modes', 'A: the guidance names the real place (nhome)', fn() => str_contains($r->guidance(), 'Greywater Farm'), fn() => fx_short($r->volatile, 500));
    $t->shouldCap('modes', 'A: no hard physical move in public: BeginIntimacy is not advertised', fn() => !str_contains($r->volatile, FX_NAME[FX_ACT_START]));
    $t->mustCap('modes', 'A: willing adult in a romantic mode: x follows the MCM level of the snapshot (2)', fn() => $r->x() === 2, fn() => $r->summary());

    $t->must('A: a hallucinated BeginIntimacy in public is dropped', fxLlm($npc, FX_ACT_START, 'Player') === []);
    $t->must('A: a hallucinated ChangeClothing in public is dropped', fxLlm($npc, FX_ACT_CLOTHING, 'undress') === []);

    // R10(c) / PROTOCOL 6.8: SuggestPrivacy is one of her hard moves. A SILENT one is an invitation the
    // owner never hears, and the server would still flip to mode follow and let her act on it later.
    $t->mustCap(['actions_v7', 'memory'], 'A: SuggestPrivacy with an empty message is dropped, and nothing is recorded',
        fn() => fxLlm($npc, FX_ACT_INVITE, 'home', '') === [] && fxInvite($npc) === null && is_array(fxMem($npc)['say_first'] ?? null),
        fn() => json_encode(fxInvite($npc)) . ' say_first=' . json_encode(fxMem($npc)['say_first'] ?? null));

    $wire = fxLlm($npc, FX_ACT_INVITE, 'home', 'one spoken line');
    $t->mustCap('actions_v7', 'A: SuggestPrivacy@home is SERVER-ONLY: nothing reaches the game', fn() => $wire === [], fn() => implode(' ', $wire));
    $t->mustCap(['actions_v7', 'memory'], 'A: invitation recorded: pending, place home, loc, at = now, expires = at + ttl', function () use ($npc, $ttl) {
        $i = fxInvite($npc);
        return $i && ($i['state'] ?? '') === 'pending' && ($i['place'] ?? '') === 'home' && ($i['loc'] ?? '') === 'Flowtest Market'
            && (int) ($i['at'] ?? 0) === fxNow() && (int) ($i['expires'] ?? 0) === fxNow() + $ttl;
    }, fn() => json_encode(fxInvite($npc)) . ' now=' . fxNow());
    $t->mustCap(['actions_v7', 'memory'], 'A: an item that is not backed by facts here (room) never becomes the recorded place', function () use ($npc) {
        $out = fxLlm($npc, FX_NAME[FX_ACT_INVITE], 'room');
        return $out === [] && in_array(fxInvite($npc)['place'] ?? '', ['home', 'quiet'], true);
    }, fn() => json_encode(fxInvite($npc)));
    $t->mustCap(['actions_v7', 'memory'], 'A: display name + JSON parameter work too ({"item":"quiet"})', function () use ($npc) {
        $out = fxLlm($npc, FX_NAME[FX_ACT_INVITE], '{"target":"Player","item":"quiet"}');
        return $out === [] && (fxInvite($npc)['place'] ?? '') === 'quiet' && (fxInvite($npc)['state'] ?? '') === 'pending';
    }, fn() => json_encode(fxInvite($npc)));

    // still public later: still public (follow needs every gate to pass)
    fxAdvance(300);
    $r2 = fxSay($npc, $snapA, 'Hello again.');
    $t->mustCap('modes', 'A: five minutes later, still watched: mode stays public, the invitation stays pending',
        fn() => $r2->mode() === 'public' && (fxInvite($npc)['state'] ?? '') === 'pending', fn() => $r2->summary() . ' ' . json_encode(fxInvite($npc)));
    // ... and it expires
    fxAdvance($ttl + 1);
    $r3 = fxSay($npc, $snapA, 'Hello again.');
    $t->mustCap(['clock', 'memory', 'actions_v7'], 'A: after ttl_seconds the invitation is gone', fn() => fxInvite($npc) === null && empty($r3->turn['invite']), fn() => json_encode(fxInvite($npc)));

    // ---- B. an inn where the player has rented a room; she neither lives nor owns here
    fxReset();
    $c = fxCast('patron'); $npc = $c['name'];
    $snapB = fxSnap(['wit' => '4', 'ltype' => 'inn', 'loc' => 'Flowtest Inn', 'cellown' => 'other', 'home' => '0', 'nhome' => '', 'prent' => '1'] + $c['snap']);
    fxSetScore($npc, $snapB, 10);
    $r = fxSay($npc, $snapB, 'Hello.');
    $t->mustCap('modes', 'B: inn + rented bed, no home facts: places = room + quiet', fn() => $r->mode() === 'public' && $setEq($r->places(), ['room', 'quiet']), fn() => $r->summary() . ' places=' . implode(',', $r->places()));
    $t->shouldCap('modes', 'B: the guidance talks about a room', fn() => (bool) preg_match('/\broom\b/i', $r->volatile));
    $t->mustCap(['actions_v7', 'memory'], 'B: SuggestPrivacy@room is recorded', fn() => fxLlm($npc, FX_ACT_INVITE, 'room') === [] && (fxInvite($npc)['place'] ?? '') === 'room', fn() => json_encode(fxInvite($npc)));
    $t->mustCap(['actions_v7', 'memory'], 'B: "home" is not backed by facts here and is never recorded', function () use ($npc) {
        fxLlm($npc, FX_ACT_INVITE, 'home');
        return (fxInvite($npc)['place'] ?? '') !== 'home';
    }, fn() => json_encode(fxInvite($npc)));

    // ---- C. the innkeeper in her own inn: all three places are real
    fxReset();
    $c = fxCast('innkeeper'); $npc = $c['name'];
    $snapC = fxSnap(['wit' => '2', 'ltype' => 'inn', 'cellown' => 'npc', 'home' => '1', 'nhome' => 'Flowtest Inn', 'prent' => '0'] + $c['snap']);
    fxSetScore($npc, $snapC, 10);
    $r = fxSay($npc, $snapC, 'Hello.');
    $t->mustCap('modes', 'C: she owns and lives in this inn: places = home + room + quiet', fn() => $setEq($r->places(), ['home', 'room', 'quiet']), fn() => 'places=' . implode(',', $r->places()));

    // ---- D. missing location keys = strict reading: only "quiet" is left
    fxReset();
    $c = fxCast('commoner'); $npc = $c['name'];
    $snapD = fxSnap(['wit' => '3', 'loc' => null, 'ltype' => null, 'cellown' => null, 'home' => null, 'nhome' => null, 'prent' => null] + $c['snap']);
    fxSetScore($npc, $snapD, 10);
    $r = fxSay($npc, $snapD, 'Hello.');
    $t->mustCap('modes', 'D: a v1 snapshot without location keys: places = quiet only', fn() => $r->mode() === 'public' && $r->places() === ['quiet'], fn() => $r->summary() . ' places=' . implode(',', $r->places()));
});
