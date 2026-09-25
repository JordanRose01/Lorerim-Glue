<?php
// 05 - R9 (5), the first rail: adults only, failing closed. No confirmed adult, or a child nearby -> TOTAL silence:
// nothing offered, both guidance strings empty, x null, nothing recorded - in every request type.
fx_scenario('05', 'non-adult / child nearby / unconfirmed -> total silence in every request type, nothing recorded', function (FxT $t) {
    $types = [
        ['inputtext', 'Hello.'], ['inputtext_s', 'Hello.'], ['ginputtext', 'Hello.'],
        ['lrg_initiative', FX_CTX . 'approach'], ['lrg_scenetalk', FX_CTX . 'lead'], ['lrg_scenetalk', FX_CTX . 'a quiet moment'],
        ['instruction', 'Walk to the door.'], ['funcret', 'command@Follow@Player@ok'], ['bored', ''],
    ];
    $cases = [
        'adult=0'            => [fxCast('minor')['name'], fxSnap(fxCast('minor')['snap'])],
        'adult key missing'  => [fxCast('minor')['name'], fxSnap(['adult' => null] + fxCast('minor')['snap'])],
        'adult=0, best case otherwise (rank 4, spouse flag, famous player)' => [fxCast('minor')['name'], fxSnap(['rank' => '4', 'pspouse' => '1', 'pdb' => '1', 'pspeech' => '100'] + fxCast('minor')['snap'])],
        'willing adult, child nearby (witkid=1)' => [fxCast('innkeeper')['name'], fxSnap(['witkid' => '1'] + fxCast('innkeeper')['snap'])],
        'willing adult, witkid key missing'      => [fxCast('innkeeper')['name'], fxSnap(['witkid' => null] + fxCast('innkeeper')['snap'])],
    ];
    foreach ($cases as $label => [$npc, $snap]) {
        fxReset();
        fxAff($npc, 100);          // the relationship system would say "adores the player": it must not matter
        fxRoll('*', 1);            // every die succeeds: it must not matter
        $bad = [];
        foreach ($types as [$type, $text]) {
            $r = fxSay($npc, $snap, $text, $type);
            if ($r->handled) { continue; } // dropped before the lock: as silent as it gets
            if ($r->glueOffered() !== []) { $bad[] = "$type: offered " . $r->glueNames(); }
            if (!$r->silent()) { $bad[] = "$type: guidance not empty"; }
            if ($r->x() !== null) { $bad[] = "$type: x=" . $r->x(); }
            if (Fx::has('modes') && $r->mode() !== 'silent') { $bad[] = "$type: mode=" . $r->mode(); }
            if (fxHasExplicitPermission($r->guidance())) { $bad[] = "$type: explicit permission"; }
            foreach (FX_GLUE as $code) {
                foreach ([$code, FX_NAME[$code]] as $name) {
                    if ($name === FX_NAME[FX_ACT_INVITE] && !Fx::has('actions_v7')) { continue; } // a v6 plugin does not know that display name
                    foreach (['Player', 'undress', 'home', 'P1', 'stop'] as $item) {
                        $out = fxLlm($npc, $name, $item);
                        // "stop" may always reach the game when a scene is recorded; here there is none, so nothing may pass
                        if ($out !== []) { $bad[] = "$type: $name@$item was not dropped"; }
                    }
                }
            }
        }
        $t->must("$label: nothing offered, guidance '', x null, every glue line dropped - in all " . count($types) . ' request types', $bad === [], implode(' | ', array_slice($bad, 0, 6)));
        $t->mustCap('handle', "$label: her initiative tick is dropped before the lock", function () use ($npc, $snap) { return fxInitiativeTick($npc, $snap)->handled; });
        $t->mustCap('memory', "$label: nothing recorded (no invite, no initiative_at)", fn() => fxInvite($npc) === null && empty(fxMem($npc)['initiative_at']), fn() => json_encode(fxMem($npc)));
        $t->shouldCap('memory', "$label: not even an interest word is computed and stored", fn() => !isset(fxMem($npc)['interest']), fn() => json_encode(fxMem($npc)));
        $t->must("$label: nothing was written to CHIM's event log", Fx::$events === [], json_encode(Fx::$events));
    }

    // a scene row for a non-adult (forged, stale or from OStim's own UI) gives no scene awareness and no scene actions
    fxReset();
    fxNeedIndex($t);
    $npc = fxCast('minor')['name'];
    fxAff($npc, 100);
    $snap = fxSnap(['ostim' => '1'] + fxCast('minor')['snap']);
    fxSendSnapshot($npc, $snap);
    fxSendScene($npc, 'start', ['scene' => fxStartScene(), 'byglue' => '0']);
    $bad = [];
    foreach ([['inputtext', 'Hello.'], ['lrg_scenetalk', FX_CTX . 'lead'], ['lrg_scenetalk', FX_CTX . 'a quiet moment']] as [$type, $text]) {
        $r = fxSay($npc, $snap, $text, $type);
        if ($r->handled) { continue; }
        if ($r->glueOffered() !== [] || !$r->silent() || $r->x() !== null || $r->functionsOn && $type === 'lrg_scenetalk') { $bad[] = "$type: " . $r->summary(); }
        if (fxLlm($npc, FX_ACT_CONTROL, 'P1') !== [] || fxLlm($npc, FX_ACT_CLOTHING, 'undress') !== []) { $bad[] = "$type: a scene action passed"; }
    }
    $t->must('a live scene row with an unconfirmed adult: no scene notes, no scene actions, lead tick does not switch actions on', $bad === [], implode(' | ', $bad));

    // the OTHER rails inside a running scene: a child walks in, the owner flips the MCM switch, the NPC is
    // opted out, or the snapshot goes stale. Fail closed - but the spoken "stop" must keep working.
    $inn = fxCast('innkeeper');
    $blocked = [
        'a child walks in mid-scene'        => ['witkid' => '1'],
        'the MCM intimacy switch goes off'  => ['on' => '0'],
    ];
    foreach ($blocked as $label => $over) {
        fxReset();
        fxNeedIndex($t);
        $npc = $inn['name'];
        fxAff($npc, 100);
        fxRoll('*', 1);
        fxSendSnapshot($npc, fxSnap(['ostim' => '1'] + $inn['snap']));
        fxSendScene($npc, 'start', ['scene' => fxStartScene()]);
        $snap = fxSnap(['ostim' => '1'] + $over + $inn['snap']);
        $r = fxSay($npc, $snap, 'What now?');
        $bad = [];
        if ($r->x() !== null) { $bad[] = 'x=' . $r->x(); }
        if ($r->options() !== []) { $bad[] = 'options are still listed'; }
        if (fxHasExplicitPermission($r->guidance())) { $bad[] = 'explicit permission'; }
        if (str_contains($r->volatile, 'RIGHT NOW')) { $bad[] = 'the scene is still described'; }
        if (fxLlm($npc, FX_ACT_CONTROL, 'faster') !== []) { $bad[] = 'a pace change passed'; }
        if (fxLlm($npc, FX_ACT_CLOTHING, 'undress') !== []) { $bad[] = 'an undress passed'; }
        $t->must("$label: no scene awareness, no wording, no action", $bad === [], implode(' | ', $bad));
        $t->must("$label: the spoken \"stop\" still reaches the game", count(fxLlm($npc, FX_ACT_CONTROL, 'stop')) === 1);
        $t->must("$label: her initiative tick is dropped", fxInitiativeTick($npc, $snap)->handled);
    }
    // the same for a profile that is opted out of intimacy entirely, inside a scene started from OStim's own menu
    fxReset();
    fxNeedIndex($t);
    $vig = fxCast('vigilant');
    fxAff($vig['name'], 100);
    fxSendSnapshot($vig['name'], fxSnap(['ostim' => '1'] + $vig['snap']));
    fxSendScene($vig['name'], 'start', ['scene' => fxStartScene(), 'byglue' => '0']);
    $r = fxSay($vig['name'], fxSnap(['ostim' => '1'] + $vig['snap']), 'What now?');
    $t->must('an NPC who is opted out stays out even inside a menu-started scene', $r->x() === null && $r->options() === []
        && !fxHasExplicitPermission($r->guidance()) && fxLlm($vig['name'], FX_ACT_CONTROL, 'faster') === [], $r->summary());
    $t->must('... and the spoken "stop" still works for her', count(fxLlm($vig['name'], FX_ACT_CONTROL, 'stop')) === 1);
    $t->must('... and nothing about that scene was written to CHIM\'s event log beyond the start line', count(Fx::$events) <= 1, json_encode(Fx::$events));

    // an adult bystander while that row exists gets nothing of the scene either
    fxReset();
    fxNeedIndex($t);
    $npc = fxCast('minor')['name'];
    fxAff($npc, 100);
    fxSendSnapshot($npc, fxSnap(['ostim' => '1'] + fxCast('minor')['snap']));
    fxSendScene($npc, 'start', ['scene' => fxStartScene(), 'byglue' => '0']);
    $by = fxCast('bystander');
    $r = fxSay($by['name'], fxSnap($by['snap']), 'Hello.');
    $t->must('a bystander is offered nothing while someone else\'s scene row is active', $r->glueOffered() === [], $r->glueNames());
    $t->must('... and the bystander\'s guidance does not describe that scene', !str_contains($r->volatile, '<intimate_scene_now>'));
});
