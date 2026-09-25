<?php
// 10 - R9 (10), a rail: the player can always stop. "stop" ends the scene immediately and wins every tie;
// a wind-down is a SEPARATE request and never replaces or delays a stop.
fx_scenario('10', 'wind-down is separate from stop; stop always maps to stop (every state, any surrounding words)', function (FxT $t) {
    $c = fxCast('innkeeper'); $npc = $c['name'];
    $alone = fxSnap($c['snap']);
    fxSetScore($npc, $alone, 10);
    $sc = fxBeginScene($t, $npc, $alone);
    $in = $sc['snap']; $cid = $sc['cid'];
    // [0.3] the param may now carry the additive decoration keys (hold=), so the verb is read, not anchored
    $isStop = fn(array $w) => count($w) === 1 && fxCode($w[0]) === FX_ACT_CONTROL && fxDo($w[0]) === 'stop';
    $stopItems = ['stop', 'Stop.', 'STOP', "let's stop", 'please stop now', 'stop, I mean it', '{"target":"Player","item":"stop"}'];
    $tieItems = ['faster... no, stop', 'P1 stop', 'wind down and then stop', 'stop winding down', 'hold on, stop', 'climax then stop', 'you lead, stop'];

    $r = fxSay($npc, $in, 'Hello.');
    $t->must('gentle tier, player speech: every wording of stop -> do=stop', !array_filter($stopItems, fn($i) => !$isStop(fxLlm($npc, FX_ACT_CONTROL, $i))),
        implode(' | ', array_filter($stopItems, fn($i) => !$isStop(fxLlm($npc, FX_ACT_CONTROL, $i)))));
    $t->must('stop wins every tie: another verb or an option key next to it changes nothing', !array_filter($tieItems, fn($i) => !$isStop(fxLlm($npc, FX_ACT_CONTROL, $i))),
        implode(' | ', array_filter($tieItems, fn($i) => !$isStop(fxLlm($npc, FX_ACT_CONTROL, $i)))));
    $t->must('stop via the display name works', $isStop(fxLlm($npc, FX_NAME[FX_ACT_CONTROL], 'stop')));
    $t->should('the notes tell her to stop at once when the player asks or seems unwilling', (bool) preg_match('/\bstop\b[^\n]{0,80}\b(at once|immediately|no argument|right away)\b|\b(at once|immediately)\b[^\n]{0,80}\bstop\b/i', $r->volatile), fx_short($r->volatile, 300));

    // [0.3 / G2] a lead tick is dropped before the lock while the player is steering; handing her the
    // lead in words is what clears that hold
    fxSay($npc, $in, 'you lead');
    $lead = fxLeadTick($npc, $in);
    $t->must('lead tick: she can stop too', !$lead->handled && $isStop(fxReal(fxLlm($npc, FX_ACT_CONTROL, 'stop'))));

    // ---- wind-down: its own verb, its own shape, never a stop
    fxSay($npc, $in, 'Hello.');
    $linger = max(5, min(120, (int) fxCfg('winddown.linger_seconds', 20)));
    foreach (['wind down', 'wind down now'] as $item) {
        $t->mustCap('actions_v7', "\"$item\" -> do=winddown;scene=<id or empty>;warp=<0|1>;linger=$linger - and NOT do=stop", function () use ($npc, $item, $linger) {
            $w = fxLlm($npc, FX_ACT_CONTROL, $item);
            if (count($w) !== 1 || !fxShape($w[0], 'do=winddown;scene=[^;]*;warp=[01];linger=' . $linger) || str_contains($w[0], 'do=stop')) { throw new RuntimeException($w ? fxParam($w[0]) : 'dropped'); }
            return true;
        });
    }
    $t->shouldCap('actions_v7', 'spelling variants "winddown" / "wind-down" are understood', fn() => str_contains(fxLlm($npc, FX_ACT_CONTROL, 'winddown')[0] ?? '', 'do=winddown') && str_contains(fxLlm($npc, FX_ACT_CONTROL, 'wind-down')[0] ?? '', 'do=winddown'));
    $t->shouldCap('actions_v7', 'the notes offer the wind-down as an option of its own', fn() => (bool) preg_match('/wind[ -]?down/i', fxSay($npc, $in, 'Hello.')->volatile));

    // ---- the game starts winding down; stop still ends it at once
    $w = fxLlm($npc, FX_ACT_CONTROL, 'wind down');
    if ($w) { fxFuncret($w[0], 'Winding down.'); }
    fxSendScene($npc, 'winddown', ['scene' => $sc['start'], 'cid' => $cid, 'wd' => '1', 'speed' => '0']);
    $t->must('ev=winddown keeps the scene row active (a wind-down is not an end)', fxSceneActive($npc), json_encode(fxSceneRow($npc)));
    $r = fxSay($npc, $in, 'Hello.');
    $t->must('mid wind-down, player speech: ChangeIntimacy is still offered', $r->offered(FX_ACT_CONTROL), $r->summary());
    $t->must('mid wind-down: stop -> do=stop, at once', $isStop(fxLlm($npc, FX_ACT_CONTROL, 'stop')) && $isStop(fxLlm($npc, FX_ACT_CONTROL, 'enough, stop')));
    $t->shouldCap('actions_v7', 'mid wind-down: the notes say the scene is winding down', fn() => (bool) preg_match('/wind|coming down|afterglow|eas(e|ing)/i', $r->volatile));
    $t->mustCap('actions_v7', 'mid wind-down: a goto still resolves (the game cancels the wind-down on it)', function () use ($npc, $r) {
        $k = array_key_first($r->options());
        return $k === null ? true : count(fxLlm($npc, FX_ACT_CONTROL, (string) $k)) === 1;
    });

    // ---- at every rung of the ladder
    [$sensual, $sexual] = fxClimbToTop($npc, $sc['start'], $cid);
    if ($sexual !== '') {
        fxSay($npc, $in, 'Hello.');
        $t->must('top tier: stop -> do=stop', $isStop(fxLlm($npc, FX_ACT_CONTROL, 'stop')));
        fxSendScene($npc, 'change', ['scene' => $sexual, 'cid' => $cid, 'stall' => '1', 'auto' => '1', 'leader' => 'auto']);
        fxSay($npc, $in, 'Hello.');
        $t->must('climax held back + OStim auto mode on: stop -> do=stop', $isStop(fxLlm($npc, FX_ACT_CONTROL, 'stop')));
        fxSendScene($npc, 'change', ['scene' => $sexual, 'cid' => $cid, 'trans' => '1']);
        fxSay($npc, $in, 'Hello.');
        $t->must('in the middle of a transition: stop -> do=stop', $isStop(fxLlm($npc, FX_ACT_CONTROL, 'stop')));
    } else {
        $t->pending('stop at the top tier', 'no sexual-tier scene for this pair in the installed packs');
    }
    // a scene started from OStim's own UI (not by the glue) can be stopped by voice as well
    fxSendScene($npc, 'end', ['scene' => $sc['start'], 'cid' => $cid]);
    fxAdvance(5);
    fxSendSnapshot($npc, $in);
    fxSendScene($npc, 'start', ['scene' => $sc['start'], 'cid' => 'sadopted1', 'byglue' => '0', 'auto' => '1', 'leader' => 'auto']);
    fxSay($npc, $in, 'Hello.');
    $t->must('an adopted thread (byglue=0, auto mode): stop -> do=stop', $isStop(fxLlm($npc, FX_ACT_CONTROL, 'stop')));

    // ---- after the end nothing of this is left
    $stop = fxLlm($npc, FX_ACT_CONTROL, 'stop');
    fxFuncret($stop[0] ?? fxLine($npc, FX_ACT_CONTROL, 'ok=1;cid=x;do=stop'), 'The scene ends.');
    fxSendScene($npc, 'end', ['scene' => $sc['start'], 'cid' => 'sadopted1']);
    $t->must('after ev=end: the scene row is closed', !fxSceneActive($npc));
});
