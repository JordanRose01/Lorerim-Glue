<?php
// 20 - G2: no autopilot. When the player speaks, she stops taking turns of her own - and the tick is dropped
// BEFORE CHIM's request lock, so a held tick costs no LLM call, no TTS and no tokens.
fx_scenario('20', 'G2: the player steers - her lead ticks are held, the hold travels to the game, "you lead" hands it back', function (FxT $t) {
    $c = fxCast('innkeeper'); $npc = $c['name'];
    $alone = fxSnap($c['snap']);
    fxSetScore($npc, $alone, 10);
    $sc = fxBeginScene($t, $npc, $alone);
    $in = $sc['snap'];
    $hold = (int) fxCfg('lead.hold_seconds', 150);
    $speechHold = (int) fxCfg('lead.speech_hold_seconds', 45);
    $t->must('config: the hold after a request is longer than the hold after plain speech', $hold > $speechHold, "$hold / $speechHold");

    // ---- a REQUEST starts the long hold
    fxSay($npc, $in, 'faster');
    $t->mustCap('memory', 'a recognised request writes player_request_at', fn() => (int) (fxMem($npc)['player_request_at'] ?? 0) === fxNow(), fn() => json_encode(fxMem($npc)));
    fxAdvance(10);
    $lead = fxLeadTick($npc, $in);
    $t->mustCap('handle', 'a lead tick 10 s later is dropped before the lock: no turn, no guidance, no tokens',
        fn() => $lead->handled && $lead->turn === null && $lead->volatile === '', fn() => $lead->summary());
    fxAdvance($hold + 10);
    $lead = fxLeadTick($npc, $in);
    $t->mustCap(['handle', 'clock'], 'after the hold has passed she may lead again', fn() => !$lead->handled && !empty($lead->turn['lead']), fn() => $lead->summary());

    // ---- plain speech starts the SHORT hold: without two clocks she could never lead again at
    // playtest-6 cadence (one utterance every ~50 s)
    fxSay($npc, $in, 'That was good.');
    $t->mustCap('memory', 'plain speech writes player_spoke_at, not player_request_at', fn() => (int) (fxMem($npc)['player_spoke_at'] ?? 0) === fxNow()
        && (int) (fxMem($npc)['player_request_at'] ?? 0) < fxNow(), fn() => json_encode(fxMem($npc)));
    fxAdvance($speechHold + 5);
    $lead = fxLeadTick($npc, $in);
    $t->mustCap(['handle', 'clock'], 'a lead tick after the short hold is served, although the long hold would still run', fn() => !$lead->handled, fn() => $lead->summary());

    // ---- "you lead" hands the lead over at once
    fxSay($npc, $in, 'faster');
    $t->mustCap('handle', 'set-up: she is held again', fn() => fxLeadTick($npc, $in)->handled);
    $y = fxSay($npc, $in, 'you lead');
    $t->mustCap('intent', '"you lead" is recognised as handing over the lead', fn() => $y->intentIs('lead_npc', 'high'), fn() => json_encode($y->intent()));
    $t->mustCap('memory', '... and it CLEARS both clocks instead of starting a hold', fn() => empty(fxMem($npc)['player_request_at']) && empty(fxMem($npc)['player_spoke_at']), fn() => json_encode(fxMem($npc)));
    $t->mustCap('handle', '... so the very next lead tick is served', fn() => !fxLeadTick($npc, $in)->handled);

    // ---- every command emitted on a player speech turn carries the hold to the game
    fxSay($npc, $in, 'faster');
    $w = fxReal(fxLlm($npc, FX_ACT_CONTROL, 'faster'));
    $t->mustCap('decorate', "a command emitted on a player speech turn carries hold=$hold", fn() => count($w) === 1 && (int) (fxParamKv($w[0])['hold'] ?? 0) === $hold, fn() => implode(' ', $w));

    // ---- a turn that emits nothing still tells the game the player is steering - with the CURRENT leader
    fxSendScene($npc, 'change', ['scene' => $sc['start'], 'cid' => $sc['cid'], 'leader' => 'player']);
    fxAdvance($hold);
    fxSay($npc, $in, 'That was good.');
    $carrier = fxLlmNothing($npc);
    $t->mustCap('decorate', 'a turn with no command appends the hold carrier', fn() => count($carrier) === 1 && fxDo($carrier[0]) === 'lead', fn() => implode(' ', $carrier));
    $t->mustCap('decorate', '... with the leader the scene ALREADY has - never flipping a player-led scene back to her',
        fn() => (fxParamKv($carrier[0])['who'] ?? '') === 'player' && (int) (fxParamKv($carrier[0])['hold'] ?? 0) > 0, fn() => fxParam($carrier[0]));
    $t->mustCap('decorate', '... and it is throttled: the next turn does not pay for it again', fn() => (function () use ($npc, $in) {
        fxSay($npc, $in, 'Good.');
        return fxLlmNothing($npc) === [];
    })());

    // ---- "you lead" must reach the GAME as a hand-over, not as a gag. LRG_OStim.CmdLead clears its own
    // leadHoldUntil and then re-applies whatever hold= the same command carries, so a decorated hand-over
    // would block her for the whole hold period - the named G2 control doing the opposite of what it says.
    // The server's own clocks being cleared is not enough: the game's lead tick is the only thing that ever
    // produces a lead turn.
    fxSay($npc, $in, 'faster');                                   // the long hold is running
    $y = fxSay($npc, $in, 'you lead');
    $t->mustCap('intent', 'set-up: "you lead" is recognised as the hand-over', fn() => $y->intentIs('lead_npc', 'high'), fn() => json_encode($y->intent()));
    foreach ([['the model chose it', fn() => fxLlm($npc, FX_ACT_CONTROL, "$npc leads")], ['the net built it', fn() => fxLlmNothing($npc)]] as [$how, $play]) {
        $w = array_values(array_filter($play(), fn($l) => fxDo($l) === 'lead'));
        $t->mustCap('decorate', "the hand-over reaches the game ($how)", fn() => count($w) === 1 && (fxParamKv($w[0])['who'] ?? '') === 'npc', fn() => implode(' ', $w));
        $t->mustCap('decorate', "... and carries NO hold=, or the game would re-apply it ($how)", fn() => !isset(fxParamKv($w[0])['hold']), fn() => fxParam($w[0]));
        if ($how === 'the model chose it') { fxSay($npc, $in, 'faster'); fxSay($npc, $in, 'you lead'); }
    }
    // a lead flip the model chose on its own, while the player is steering, still carries the hold
    fxSay($npc, $in, 'faster');
    $flip = array_values(array_filter(fxLlm($npc, FX_ACT_CONTROL, "$npc leads"), fn($l) => fxDo($l) === 'lead'));
    $t->mustCap('decorate', 'a lead flip nobody asked for still carries the hold', fn() => count($flip) === 1 && (int) (fxParamKv($flip[0])['hold'] ?? 0) === $hold, fn() => implode(' ', $flip));

    // ---- the no-op hold carrier must never cancel a running wind-down: the game's CmdLead cancels one
    // unconditionally, so the player who asks to wind down and then says anything would never see it end
    fxSendScene($npc, 'change', ['scene' => $sc['start'], 'cid' => $sc['cid'], 'wd' => '1']);
    fxAdvance($hold);
    fxSay($npc, $in, 'That was good.');
    $t->mustCap('decorate', 'no hold carrier is sent while the scene is winding down', fn() => fxLlmNothing($npc) === [], fn() => implode(' ', fxLlmNothing($npc)));
    fxSendScene($npc, 'change', ['scene' => $sc['start'], 'cid' => $sc['cid'], 'wd' => '0']);

    // ---- a NON-lead lrg_scenetalk must keep reaching the LLM: it is her speech moment, not a lead turn
    $talk = fxSceneTalk($npc, $in, 'a quiet moment');
    $t->mustCap('handle', 'ordinary scene talk is never dropped by the hold', fn() => !$talk->handled && $talk->mode() === 'scene' && $talk->volatile !== '', fn() => $talk->summary());

    // ---- a request inside the scene never has to wait for her pacing (owner addendum 3e)
    $r = fxSay($npc, $in, 'fuck me');
    $t->mustCap('intent', 'an explicit act request lifts the request ceiling to the top, her own ceiling is untouched',
        fn() => !$r->intentIs('act', 'high') || ((int) ($r->progress()['req_ceiling'] ?? 0) === 4), fn() => json_encode($r->progress()));
});
