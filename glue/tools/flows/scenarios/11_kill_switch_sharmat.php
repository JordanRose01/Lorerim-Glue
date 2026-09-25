<?php
// 11 - R9 (11), rails: the global kill switch and the SHARMAT guard silence EVERYTHING. Each needs process-wide state
// (a cached config / a constant), so each runs in its own child process (run_flows.php, fxVariants()).

/** The same short story for every "off" variant: the best possible case for romance - and nothing may come of it. */
function fx11_story(FxT $t, string $label, bool $viaHooks): void
{
    $c = fxCast('innkeeper'); $npc = $c['name'];
    $alone = fxSnap($c['snap']);
    fxSetScore($npc, $alone, 40); // drawn
    fxRoll('*', 1);               // every die succeeds
    $play = function (string $type, string $text, array $snap) use ($npc, $viaHooks): FxTurnResult {
        if (!$viaHooks) { return fxSay($npc, $snap, $text, $type); }
        $snap['npc'] = $npc;
        fxIncludeHook('globals.php', ['lrg_npcstate', fxNow(), 100000, fxKvString($snap)]);
        fxIncludeHook('preprocessing.php', ['lrg_npcstate', fxNow(), 100000, fxKvString($snap)]);
        return fxHookTurn($npc, $type, $text);
    };
    $answer = fn(FxTurnResult $r, string $code, string $item) => $viaHooks ? fxHookLlm($r, [fxLine($npc, $code, $item)]) : fxLlm($npc, $code, $item);
    $noOk = fn(array $lines) => !array_filter($lines, fn($l) => str_contains((string) $l, 'ok=1'));

    $t->must("$label: a state message still terminates before the lock", $viaHooks
        ? fxIncludeHook('preprocessing.php', ['lrg_npcstate', fxNow(), 100000, fxKvString(['npc' => $npc] + $alone)])
        : fxSendSnapshot($npc, $alone) === 'handled');

    $bad = [];
    $turns = [['inputtext', 'Hello.', $alone], ['inputtext', 'Hello.', ['wit' => '3'] + $alone], ['lrg_initiative', FX_CTX . 'approach', $alone],
        ['lrg_scenetalk', FX_CTX . 'lead', ['ostim' => '1'] + $alone], ['lrg_scenetalk', FX_CTX . 'a quiet moment', ['ostim' => '1'] + $alone]];
    foreach ($turns as [$type, $text, $snap]) {
        $r = $play($type, $text, $snap);
        if ($r->handled) { continue; }
        $tag = "$type" . (($snap['wit'] ?? '0') !== '0' ? ' (public)' : '');
        if ($r->glueOffered() !== []) { $bad[] = "$tag: offered " . $r->glueNames(); }
        if (!$r->silent()) { $bad[] = "$tag: guidance injected (" . strlen($r->guidance()) . ' chars)'; }
        if ($r->x() !== null) { $bad[] = "$tag: x=" . $r->x(); }
        if (Fx::has('modes') && !$viaHooks && $r->mode() !== 'silent') { $bad[] = "$tag: mode=" . $r->mode(); }
        if ($type !== 'inputtext' && $r->functionsOn) { $bad[] = "$tag: actions were switched on"; }
        foreach ([[FX_ACT_START, 'Player'], [FX_NAME[FX_ACT_START], 'Player'], [FX_ACT_CLOTHING, 'undress'], [FX_ACT_CONTROL, 'P1'], [FX_ACT_INVITE, 'home']] as [$code, $item]) {
            $out = $answer($r, $code, $item);
            if (!$noOk($out)) { $bad[] = "$tag: $code reached the game AUTHORISED: " . fx_short(implode(' ', $out), 120); }
            elseif ($out !== [] && !$viaHooks) { $bad[] = "$tag: $code was not dropped"; }
        }
    }
    $t->must("$label: nothing offered, nothing injected, x null, no glue command is ever authorised (ok=1) - speech, public, initiative, lead, scene talk", $bad === [], implode(' | ', array_slice($bad, 0, 6)));

    $tick = $play('lrg_initiative', FX_CTX . 'approach', $alone);
    $t->mustCap('handle', "$label: the initiative tick of a drawn NPC with a winning die is dropped before the lock", fn() => $tick->handled, fn() => $tick->summary());
    $t->mustCap('memory', "$label: nothing was recorded", fn() => fxInvite($npc) === null && empty(fxMem($npc)['initiative_at']), fn() => json_encode(fxMem($npc)));
    $t->must("$label: nothing was written to CHIM's event log", Fx::$events === []);

    $before = (int) @filesize(fxLogFile());
    $viaHooks ? fxIncludeHook('preprocessing.php', ['lrg_log', fxNow(), 100000, 'cid=soff1;msg=flowtest off-variant']) : fxGameMessage(['lrg_log', fxNow(), 100000, 'cid=soff1;msg=flowtest off-variant']);
    clearstatcache();
    $t->must("$label: game diagnostics (lrg_log) are still written", str_contains((string) @file_get_contents(fxLogFile(), false, null, $before), '[cid=soff1] GAME flowtest off-variant'));

    // [0.5.5 / owner addendum 11] every glue row has the follow-up on now, so the glue itself must end a FAILED
    // result here too: switched off (or SHARMAT) means silent, never a voiced funcret turn
    $fr = ['funcret', fxNow(), 100000, 'command@' . FX_ACT_CLOTHING . '@ok=1;cid=soff2;npc=' . $npc . ';do=undress;who=npc;part=all@Error: someone is watching'];
    $ended = $viaHooks ? fxIncludeHook('preprocessing.php', $fr) : fxGameMessage($fr) === 'handled';
    $t->must("$label: a failed glue command is never voiced - its funcret ends before the lock", $ended && fxVoiced() === null);
}

fx_scenario('11a', 'kill switch in the user config: everything silent', function (FxT $t) {
    $t->must('variant set-up: the plugin reports itself disabled', fxCfg('kill_switch', false) === true);
    fx11_story($t, 'kill switch', false);
    $t->should('kill switch: snapshots are not even stored', fxDb()->payload('lrg_npc_state', fxCast('innkeeper')['name']) === []);
}, 'killswitch');

fx_scenario('11b', 'SHARMAT guard (another intimacy plugin is installed): everything silent, through the real hook files', function (FxT $t) {
    $t->must('variant set-up: LRG_SHARMAT_PRESENT is defined', defined('LRG_SHARMAT_PRESENT'));
    fx11_story($t, 'SHARMAT, hook files', true);
}, 'sharmat');

fx_scenario('11d', 'SHARMAT guard, defence in depth: the LIBRARY itself goes silent (PROTOCOL 6.2 lists SHARMAT under mode silent)', function (FxT $t) {
    // 11b proves the production path (hook files) is silent. This one calls lrgPrepareTurn() / lrgPostProcessActions() directly,
    // the way any future hook or tool might: with the guard only in the hook files, such a caller would get a live romantic turn.
    $t->must('variant set-up: LRG_SHARMAT_PRESENT is defined', defined('LRG_SHARMAT_PRESENT'));
    if (!Fx::has('modes')) { $t->pending('library level', 'needs modes: in v1 only the hook files check the guard'); return; }
    fx11_story($t, 'SHARMAT, library level', false);
}, 'sharmat');

fx_scenario('11c', 'initiative switched off in the config: no own moves; speech-driven follow-through still works', function (FxT $t) {
    $t->mustCap('handle', 'variant set-up: initiative.enabled = false', fn() => fxCfg('initiative.enabled', true) === false);
    $c = fxCast('innkeeper'); $npc = $c['name'];
    $alone = fxSnap($c['snap']);
    fxSetScore($npc, $alone, 40);
    fxRoll('*', 1);
    $t->mustCap('handle', 'a drawn NPC, alone, winning die: the tick is dropped', fn() => fxInitiativeTick($npc, $alone)->handled);
    $r = fxSay($npc, ['wit' => '2'] + $alone, 'Hello.');
    fxLlm($npc, FX_ACT_INVITE, 'quiet');
    fxAdvance(60);
    $t->shouldCap(['handle', 'memory'], 'a follow-through tick is dropped too (the switch covers every tick)', fn() => fxInitiativeTick($npc, $alone)->handled);
    $r = fxSay($npc, $alone, 'Hello.');
    $t->mustCap(['modes', 'memory'], 'player speech still gets the follow-through', fn() => $r->mode() === 'follow' && $r->offered(FX_ACT_START), fn() => $r->summary());
}, 'initiative_off');
