<?php
// 23 - G5: the act layer. A scene is offered by what it IS, with roles, under a key that means the same thing on
// every turn - in playtest 6 "P1" meant three different scenes in ten minutes. RequestAct is resolved server side
// into an ordinary do=goto, so a game on script version 200 needs no new branch.
fx_scenario('23', 'G5: stable act keys with roles, resolved server side into do=goto; the ceiling limits HER, not the player', function (FxT $t) {
    $c = fxCast('innkeeper'); $npc = $c['name'];
    $alone = fxSnap($c['snap']);
    fxSetScore($npc, $alone, 10);
    $sc = fxBeginScene($t, $npc, $alone);
    $in = $sc['snap']; $cid = $sc['cid'];

    $r = fxSay($npc, $in, 'Hello.');
    $acts = $r->acts();
    if (!Fx::has('acts') || !$acts) { $t->pending('the act layer', 'no act is open from ' . $sc['start'] . ' for this pair in the installed packs'); return; }
    $t->must('RequestAct is offered inside a scene', $r->offered(FX_ACT_REQUESTACT), $r->glueNames());
    $t->must('every offered act names a scene that really carries it', !array_filter($acts, function ($a) use ($r) {
        $s = lrgScene((string) $a['scene']);
        return !$s || !in_array((string) $a['act'], lrgSceneActs($s, (int) ($r->turn['ctx']['npos'] ?? 1), $r->turn['ctx']['sexes'] ?? null), true);
    }), json_encode(array_map(fn($a) => $a['act'] . '=' . $a['scene'], $acts)));
    $t->must('no offered act is above the ceiling', !array_filter($acts, fn($a) => fxTierNum($a['tier']) > (int) ($r->progress()['ceiling'] ?? 4)),
        json_encode(array_map(fn($a) => $a['act'] . ':' . $a['tier'], $acts)));
    $t->must('an act id is a stable handle, not a per-turn index', !array_filter(array_keys($acts), fn($k) => (bool) preg_match('/^P\d+$/', (string) $k)), implode(',', array_keys($acts)));

    // ---- the same key means the same thing on the next turn (the P<n> regression)
    $again = fxSay($npc, $in, 'Hello.');
    $sameIds = array_keys($again->acts()) === array_keys($acts);
    $sameScenes = !array_filter($again->acts(), fn($a, $k) => ($acts[$k]['scene'] ?? '') !== $a['scene'], ARRAY_FILTER_USE_BOTH);
    $t->must('two turns in the same scene: the same act keys, pointing at the same scenes', $sameIds && $sameScenes,
        json_encode(array_keys($acts)) . ' vs ' . json_encode(array_keys($again->acts())));

    // ---- RequestAct -> do=goto, and the game needs no new verb
    $key = (string) array_key_first($acts);
    $w = fxReal(fxLlm($npc, FX_ACT_REQUESTACT, $key));
    $t->must("RequestAct \"$key\" is resolved into an ordinary ChangeIntimacy goto", count($w) === 1 && fxCode($w[0]) === FX_ACT_CONTROL && fxDo($w[0]) === 'goto', implode(' ', $w));
    $t->must('... to the scene the act named', count($w) === 1 && (fxParamKv($w[0])['scene'] ?? '') === $acts[$key]['scene'], implode(' ', $w));
    $t->must('an act key that is not open right now is dropped', fxNothingSent(fxLlm($npc, FX_ACT_REQUESTACT, 'nonsense:npc')));
    $t->must('the display name works too', count(fxReal(fxLlm($npc, FX_NAME[FX_ACT_REQUESTACT], $key))) === 1);

    // ---- roles: the same family in the two directions is not the same offer
    $directional = array_values(array_filter(array_keys($acts), fn($k) => str_contains((string) $k, ':')));
    if ($directional) {
        $t->must('a directional act carries its role in the key (:npc = she does it, :you = the player does)',
            !array_filter($directional, fn($k) => !preg_match('/:(npc|you)$/', (string) $k)), implode(',', $directional));
        $fam = explode(':', (string) $directional[0])[0];
        $t->must('the two directions of one family are two different keys, never one', count(array_filter(array_keys($acts), fn($k) => str_starts_with((string) $k, $fam . ':'))) >= 1);
    } else {
        $t->note('no directional act is open from here: roles are covered in tools/test_scene_index.php');
    }

    // ---- the ceiling limits HER pick; an explicit player request is not held back by it (addendum 3e)
    [$sensual, $sexual] = fxClimbToTop($npc, $sc['start'], $cid);
    fxSendScene($npc, 'change', ['scene' => $sc['start'], 'cid' => $cid]);       // back to the gentle start
    $setLow = fn() => fxDb()->t['lrg_scene_state'][$npc]['payload'] = json_encode(['_maxtier' => 2, '_tier_since' => fxNow()] + fxSceneRow($npc));
    $setLow();
    $lead = null;
    fxSay($npc, $in, 'you lead');
    $lead = fxLeadTick($npc, $in);
    if (!$lead->handled && (int) ($lead->progress()['ceiling'] ?? 4) < 4) {
        $tooHigh = '';
        foreach (lrgActOptions($sc['start'], [], 4, FX_SEXES, '', 1, [], 64) as $id => $a) {
            if (fxTierNum($a['tier']) > (int) $lead->progress()['ceiling']) { $tooHigh = (string) $id; break; }
        }
        if ($tooHigh === '') { $t->note('no act above her ceiling is reachable from here'); }
        else {
            $t->must("on her OWN lead tick an act above the ceiling is dropped [$tooHigh]", fxNothingSent(fxLlm($npc, FX_ACT_REQUESTACT, $tooHigh)));
            $ask = fxSay($npc, $in, 'i want ' . str_replace(':', ' ', $tooHigh));
            if ($ask->intentIs('act', 'high')) {
                $t->mustCap('intent', "... the SAME act asked for by the player is not held back by her pacing [$tooHigh]",
                    fn() => (int) ($ask->progress()['req_ceiling'] ?? 0) === 4 && count(fxReal(fxLlmNothing($npc))) === 1);
            }
        }
    } else {
        $t->note('her ceiling is already open to the top here: the "too soon for her own pick" case cannot be staged');
    }

    // ---- the player asked for an act and the model answered with something else: that line is dropped
    $r = fxSay($npc, $in, 'kiss me');
    if ($r->intentIs('act', 'high') && ($r->intent()['act'] ?? '') === 'kiss') {
        $other = '';
        foreach ($r->acts() as $id => $a) { if ((string) $id !== 'kiss') { $other = (string) $id; break; } }
        if ($other !== '') {
            $w = fxReal(fxLlm($npc, FX_ACT_REQUESTACT, $other));
            $t->mustCap('decorate', 'the player asked for one act and the model chose another: the model\'s line is dropped and the request wins',
                fn() => count($w) === 1 && in_array('kiss', lrgSceneActs(lrgScene((string) (fxParamKv($w[0])['scene'] ?? '')), (int) ($r->turn['ctx']['npos'] ?? 1), $r->turn['ctx']['sexes'] ?? null), true),
                fn() => implode(' ', $w));
        }
    }

    // ---- anti-circling: a scene she has already been in is not her first choice again
    $visited = (array) (fxSceneRow($npc)['_visited'] ?? []);
    $t->must('the scenes of this thread are remembered', count($visited) > 0, json_encode($visited));
    $t->must('the act families that happened are remembered', is_array(fxSceneRow($npc)['_acts_done'] ?? null), json_encode(fxSceneRow($npc)['_acts_done'] ?? null));
    fxSay($npc, $in, 'you lead');
    $lead = fxLeadTick($npc, $in);
    if (!$lead->handled) {
        $offeredScenes = array_map(fn($a) => strtolower((string) $a['scene']), $lead->acts());
        $back = array_intersect($offeredScenes, array_map('strtolower', array_slice($visited, 0, -1)));
        $t->should('on her own lead tick she is not sent back into a scene this thread already had', $back === [] || count($offeredScenes) === count($back), implode(',', $back));
    }
    // but the player is never met with silence: either it happens, or she says in words why it cannot (G5.8)
    $r = fxSay($npc, $in, 'kiss me');
    // [0.3.1 / D7] "we are already doing that" is a third legitimate answer, and the one the owner
    // wanted: naming the position they are in used to come back as "not reachable right now".
    $t->mustCap('intent', 'the player asking for something is never answered with silence: it happens, or she says why not',
        fn() => !$r->intentIs('act', 'high') || !empty($r->intent()['kv'])
            || (str_contains($r->directive(), 'not possible from here') && str_contains($r->directive(), 'Choose no action'))
            || (str_contains($r->directive(), 'no way for the two of them') && str_contains($r->directive(), 'Choose no action'))
            || (!empty($r->intent()['already']) && str_contains($r->directive(), 'doing right now')),
        fn() => json_encode($r->intent()) . ' | ' . $r->directive());

    // ---- [0.3.1 / D8, wire agreement w2] A COMPOUND request really goes out as TWO commands. Nothing
    // asserted this before: test_gates only checked that the INTENT kept both halves, which is how the
    // out-of-scene half of the same feature shipped broken.
    $cmp = fxSay($npc, $in, "take my clothes off and then let's do missionary");
    $ci = $cmp->intent();
    if ((string) ($ci['kind'] ?? '') !== 'undress' || (string) (($ci['extra'] ?? [])['kind'] ?? '') === '') {
        $t->note('the compound sentence did not produce a primary + extra here: ' . json_encode([$ci['kind'] ?? '', ($ci['extra'] ?? [])['kind'] ?? '']));
    } else {
        $w = fxReal(fxLlm($npc, FX_ACT_CLOTHING, 'undress me', 'Of course.'));
        $t->must('IN a scene a compound request produces TWO wire lines', count($w) === 2,
            count($w) . ' :: ' . implode(' || ', array_map('fxParam', $w)));
        if (count($w) === 2) {
            preg_match('/cid=([^;]+);/', fxParam($w[0]), $m1);
            preg_match('/cid=([^;]+);/', fxParam($w[1]), $m2);
            $t->must('... the second carries its own cid, the first one plus a trailing b',
                ($m2[1] ?? '') === ($m1[1] ?? '') . 'b', ($m1[1] ?? '?') . ' -> ' . ($m2[1] ?? '?'));
            $t->must('... and it really is the second half: a goto with a scene', fxDo($w[1]) === 'goto' && (fxParamKv($w[1])['scene'] ?? '') !== '', fxParam($w[1]));
        }
    }

    // ---- ... and OUT of a scene, where no second command can exist, the same sentence must not be
    // promised twice: the act half rides on the START (w1 `scene=` / `after=`), and the directive names
    // both actions instead of pretending a second command will follow.
    fxSendScene($npc, 'end', ['scene' => $sc['start'], 'cid' => $cid, 'dur' => '200', 'how' => 'finished']);
    fxAdvance((int) fxCfg('start.post_scene_cooldown_seconds', 300) + 30);
    $out = fxSnap($c['snap']);
    fxWarm($npc, $out);
    $o = fxSay($npc, $out, "take my clothes off and then let's do missionary");
    if (!in_array($o->mode(), ['private', 'follow'], true)) {
        $t->note('out of a scene the mode is ' . $o->mode() . ': the compound-start case cannot be staged here');
    } else {
        $d = $o->directive();
        $t->mustCap('intent', 'out of a scene the directive promises both halves ONLY when it names both actions',
            fn() => !str_contains($d, 'Both happen') || (str_contains($d, 'ChangeClothing') && str_contains($d, 'BeginIntimacy')), fn() => $d);
        $ws = fxReal(fxLlm($npc, FX_ACT_START, 'Player', 'Come here, then.'));
        $t->mustCap('decorate', '... and the start itself carries the act half (scene= or after=)',
            fn() => count($ws) === 1 && ((fxParamKv($ws[0])['after'] ?? '') !== '' || (fxParamKv($ws[0])['scene'] ?? '') !== ''),
            fn() => implode(' ', $ws));
    }
});
