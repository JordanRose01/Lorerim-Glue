<?php
// 04 - R9 (4) / R2: alone with an NPC who is not interested: offered only if the gates pass, no invitation, no explicit
// permission. Plus the interest formula of PROTOCOL 6.1 at its boundaries - one formula drives word, willingness and initiative.
fx_scenario('04', 'uninterested NPC alone -> nothing; interest formula boundaries (word / willing / may_initiate)', function (FxT $t) {
    $c = fxCast('commoner'); $npc = $c['name'];
    $alone = fxSnap($c['snap']);

    // ---- curious, alone, best possible setting: still nothing
    fxSetScore($npc, $alone, -10);
    $r = fxSay($npc, $alone, 'Hello.');
    $t->must('alone but not willing: no glue action offered', $r->glueOffered() === [], $r->glueNames());
    $t->mustCap('modes', 'mode = closed, x = null', fn() => $r->mode() === 'closed' && $r->x() === null, fn() => $r->summary());
    $t->must('no explicit-language permission, no invitation guidance', !fxHasExplicitPermission($r->guidance()) && !str_contains($r->guidance(), FX_NAME[FX_ACT_INVITE]));
    $t->must('privacy alone is not a reason: BeginIntimacy / SuggestPrivacy lines are dropped', fxLlm($npc, FX_ACT_START, 'Player') === [] && fxLlm($npc, FX_ACT_INVITE, 'quiet') === []);
    $t->mustCap('memory', 'nothing was recorded as an invitation', fn() => fxInvite($npc) === null);
    fxRoll('initiative', 1);
    $tick = fxInitiativeTick($npc, $alone);
    $t->mustCap('handle', 'her initiative tick is dropped', fn() => $tick->handled, fn() => $tick->summary());

    // ---- the boundary of willingness: score -1 closed, score 0 offered (identical to v1's affinity >= min_affinity)
    fxSetScore($npc, $alone, -1);
    $below = fxSay($npc, $alone, 'Hello.');
    fxSetScore($npc, $alone, 0);
    fxWarm($npc, $alone); // [0.3] her own first move also needs a warmed-up conversation (11.2)
    $at = fxSay($npc, $alone, 'Hello.');
    $t->must('score -1: not offered; score 0: BeginIntimacy offered', !$below->offered(FX_ACT_START) && $at->offered(FX_ACT_START), $below->glueNames() . ' / ' . $at->glueNames());
    $t->mustCap('modes', 'score 0: mode = private, SuggestPrivacy is NOT offered in private', fn() => $at->mode() === 'private' && !$at->offered(FX_ACT_INVITE), fn() => $at->summary());
    $tick = fxInitiativeTick($npc, $alone);
    $t->mustCap('handle', 'score 0 (interested, not drawn): she does not initiate, even with a winning die', fn() => $tick->handled, fn() => $tick->summary());

    // ---- words and flags straight from lrgInterest()
    $profile = null; // built by the adapter
    $want = [-60 => 'indifferent', -26 => 'indifferent', -25 => 'curious', -1 => 'curious', 0 => 'interested', 19 => 'interested', 20 => 'drawn', 45 => 'drawn'];
    $t->mustCap('interest', 'word thresholds -25 / 0 / 20; willing = score >= 0; may_initiate = drawn', function () use ($npc, $alone, $profile, $want) {
        foreach ($want as $score => $word) {
            fxSetScore($npc, $alone, $score);
            $i = fxInterest($npc, $alone);
            if (($i['word'] ?? '') !== $word || (int) ($i['score'] ?? 999) !== $score || (bool) ($i['willing'] ?? null) !== ($score >= 0) || (bool) ($i['may_initiate'] ?? null) !== ($word === 'drawn')) {
                throw new RuntimeException("score $score -> " . json_encode($i) . ", expected word $word");
            }
        }
        return true;
    });

    // ---- bonuses: Speech and renown, capped at interest.max_bonus
    $cap = (int) fxCfg('interest.max_bonus', 15);
    $strong = (array) fxCfg('interest.renown_bonus.strong', [0, 10, 20]);
    $deeds = (int) fxCfg('leverage.many_deeds_quests_completed', 25);
    $score = function (array $over) use ($npc, $c) {
        $s = fxSnap($over + $c['snap']);
        fxSetScore($npc, fxSnap($c['snap']), -15); // base: 15 below her threshold, no bonus
        return (int) (fxInterest($npc, $s)['score'] ?? 999);
    };
    $t->mustCap('interest', 'Speech bonus = min(10, 2 * floor((pspeech - 50) / 10)): 50 -> 0, 59 -> 0, 70 -> 4, 100 -> 10', fn() =>
        $score(['pspeech' => '50']) === -15 && $score(['pspeech' => '59']) === -15 && $score(['pspeech' => '70']) === -11 && $score(['pspeech' => '100']) === -5,
        fn() => json_encode([$score(['pspeech' => '50']), $score(['pspeech' => '59']), $score(['pspeech' => '70']), $score(['pspeech' => '100'])]));
    $t->mustCap('interest', 'renown by renown_sway (commoner = strong): many deeds +' . $strong[1] . ', Dragonborn +' . $strong[2] . ' capped at ' . $cap, fn() =>
        $score(['pquests' => (string) $deeds]) === -15 + min($cap, (int) $strong[1]) && $score(['pdb' => '1']) === -15 + min($cap, (int) $strong[2]),
        fn() => json_encode([$score(['pquests' => (string) $deeds]), $score(['pdb' => '1'])]));
    $t->mustCap('interest', 'renown + Speech together never exceed max_bonus', fn() => $score(['pdb' => '1', 'pspeech' => '100']) === -15 + $cap);
    $t->mustCap('interest', 'a missing pspeech key reads as no bonus (fail closed)', fn() => $score(['pspeech' => null]) === -15);

    // the same bonus opens the gate: 15 below the threshold, famous and silver-tongued -> exactly willing
    fxSetScore($npc, fxSnap($c['snap']), -15);
    $famous = fxSnap(['pdb' => '1', 'pspeech' => '100'] + $c['snap']);
    fxWarm($npc, $famous); // [0.3] 11.2
    $r = fxSay($npc, $famous, 'Hello.');
    $t->mustCap(['interest', 'modes'], 'renown + Speech lift her over the line: BeginIntimacy offered, word = interested', fn() => $r->offered(FX_ACT_START) && $r->interestWord() === 'interested', fn() => $r->summary());
    $t->shouldCap(['interest', 'modes'], 'the LLM is told in WORDS: neither score nor bonus appears as a number next to "interest"', fn() => !preg_match('/interest\w*[^.\n]{0,30}[-+]?\d{1,3}\b/i', $r->guidance()));

    // hard blocks stay hard whatever the score
    $i = null;
    $t->mustCap(['interest', 'modes'], 'a non-adult is never "willing" on a turn, whatever the affinity', function () use ($npc, $c) {
        fxAff($npc, 100);
        $r = fxSay($npc, fxSnap(['adult' => '0', 'pdb' => '1', 'pspeech' => '100'] + $c['snap']), 'Hello.');
        return $r->mode() === 'silent' && $r->glueOffered() === [] && $r->silent();
    });
});
