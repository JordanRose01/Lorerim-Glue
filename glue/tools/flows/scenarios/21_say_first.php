<?php
// 21 - G3 / R10 (owner addenda 1 and 2): she speaks before she moves. A scene, a position, undressing or a move
// to furniture that SHE chose does not happen at all unless her reply carried a spoken line; the decision is made
// on the RESOLVED command, never on a marker the model saw. What the player asked for is exempt, and so is pace.
fx_scenario('21', 'G3 / R10: a change she chose with no spoken line does not happen; the game is told to wait for her voice', function (FxT $t) {
    $c = fxCast('innkeeper'); $npc = $c['name'];
    $alone = fxSnap($c['snap']);
    fxSetScore($npc, $alone, 10);
    fxWarm($npc, $alone);
    $first = fxSay($npc, $alone, 'Hello.');
    $t->must('set-up: BeginIntimacy is on the table', $first->offered(FX_ACT_START), $first->summary());

    // ---- BeginIntimacy with no spoken line: the scene does not begin
    $silent = fxReal(fxLlm($npc, FX_ACT_START, 'Player', ''));
    $t->mustCap('decorate', 'BeginIntimacy with an empty message: nothing reaches the game', fn() => $silent === [], implode(' ', $silent));
    $t->mustCap(['decorate', 'memory'], '... and the server remembers to ask her for the line', fn() => is_array(fxMem($npc)['say_first'] ?? null), fn() => json_encode(fxMem($npc)['say_first'] ?? null));
    $reask = fxSay($npc, $alone, 'Hello.');
    $t->mustCap('decorate', '... the next turn asks her, once, to say it in the same reply as the action',
        fn() => (bool) preg_match('/without saying anything|nothing happened/i', $reask->guidance()), fn() => fx_short($reask->guidance(), 300));

    // ---- G3(a)/(b): a start is NEVER exempt, not even when the player asked for contact. This was the
    // commonest path of all - "kiss me" / "get naked" made the start count as player-requested, the
    // say-it-first check was skipped, and the game then waited out its whole budget for a line that was
    // never coming and began anyway. A scene that begins out of nowhere is the playtest-6 complaint.
    foreach (['kiss me', 'get naked', 'I want you right now'] as $said) {
        $ask = fxSay($npc, $alone, $said);
        if (!$ask->offered(FX_ACT_START)) { continue; }
        $t->mustCap('decorate', "\"$said\" + BeginIntimacy with an empty message: the scene still does not begin",
            fn() => fxReal(fxLlm($npc, FX_ACT_START, 'Player', '')) === [], fn() => json_encode($ask->intent()));
    }
    $ask = fxSay($npc, $alone, 'kiss me');
    $t->mustCap('decorate', '... and with her line it begins at once', fn() => count(fxReal(fxLlm($npc, FX_ACT_START, 'Player'))) === 1);

    // ---- with a line it happens, and the game is told to let her finish first
    fxSay($npc, $alone, 'Hello.');
    $w = fxReal(fxLlm($npc, FX_ACT_START, 'Player'));
    $t->must('BeginIntimacy WITH a spoken line reaches the game', count($w) === 1 && fxCode($w[0]) === FX_ACT_START, implode(' ', $w));
    $t->mustCap('decorate', '... carrying wait=end, so OStim\'s intro fade cannot cut across her line', fn() => (fxParamKv($w[0])['wait'] ?? '') === 'end', fn() => fxParam($w[0]));
    $t->must('... and no hold: nothing is running yet', !isset(fxParamKv($w[0])['hold']), fxParam($w[0]));

    // ---- now inside a scene
    fxFuncret($w[0], 'They draw close. The scene begins.');
    $in = ['ostim' => '1'] + $alone;
    fxSendSnapshot($npc, $in);
    $kv = fxParamKv($w[0]);
    $start = (($kv['furn'] ?? '') !== '' && ($kv['fscene'] ?? '') !== '') ? $kv['fscene'] : (string) ($kv['scene'] ?? '');
    $cid = (string) $kv['cid'];
    fxSendScene($npc, 'start', ['scene' => $start, 'cid' => $cid, 'furn' => (string) ($kv['furn'] ?? '')]);
    fxNeedIndex($t);

    $r = fxSay($npc, $in, 'Hello.');
    $opts = $r->options();
    $k = array_key_first($opts);
    if ($k === null) { $t->pending('a position she could choose', 'the installed packs offer no option from ' . $start); return; }

    // ---- a position SHE chose: no line, no change
    $silent = fxReal(fxLlm($npc, FX_ACT_CONTROL, (string) $k, ''));
    $t->mustCap('decorate', 'a position she chose with an empty message: nothing reaches the game', fn() => $silent === [], implode(' ', $silent));
    $t->mustCap(['decorate', 'memory'], '... and it does NOT count as "she let the moment pass" (that would be two contradictory pressures next turn)',
        fn() => (int) (fxMem($npc)['lead_idle'] ?? 0) === 0, fn() => json_encode(fxMem($npc)['lead_idle'] ?? null));
    $spoken = fxReal(fxLlm($npc, FX_ACT_CONTROL, (string) $k));
    $t->must('the same position WITH a line reaches the game', count($spoken) === 1 && fxDo($spoken[0]) === 'goto', implode(' ', $spoken));
    $t->mustCap('decorate', '... carrying wait=begin and nowarp=1: her own move waits for her voice and never fade-jumps',
        fn() => (fxParamKv($spoken[0])['wait'] ?? '') === 'begin' && (fxParamKv($spoken[0])['nowarp'] ?? '') === '1' && !isset(fxParamKv($spoken[0])['warp']), fn() => fxParam($spoken[0]));

    // ---- the same position asked for by the PLAYER: no line needed, no wait, no nowarp
    $id = (string) $opts[$k]['id'];
    $label = (string) $opts[$k]['label'];
    $asked = fxSay($npc, $in, 'go to ' . $label);
    $w2 = fxReal(fxLlm($npc, FX_ACT_CONTROL, (string) $k, ''));
    $t->mustCap('intent', 'the same change, asked for by the player: it happens even with an empty message', fn() => count($w2) === 1 && fxDo($w2[0]) === 'goto', fn() => implode(' ', $w2) . ' | intent ' . json_encode($asked->intent()));
    $t->mustCap(['intent', 'decorate'], '... and carries no wait and no nowarp: the player waits for nobody', fn() => !isset(fxParamKv($w2[0])['wait']) && !isset(fxParamKv($w2[0])['nowarp']), fn() => fxParam($w2[0]));

    // ---- undressing herself is a hard move too; pace never is
    fxSay($npc, $in, 'Hello.');
    $t->mustCap('decorate', 'she undresses herself with no line: nothing happens', fn() => fxNothingSent(fxLlm($npc, FX_ACT_CLOTHING, 'undress', '')));
    $u = fxReal(fxLlm($npc, FX_ACT_CLOTHING, 'undress'));
    $t->mustCap('decorate', '... with a line it happens, and waits for her voice (wait=begin)', fn() => count($u) === 1 && (fxParamKv($u[0])['wait'] ?? '') === 'begin', fn() => implode(' ', $u));
    fxSay($npc, $in, 'Hello.');
    foreach (['faster', 'slower', 'hold', 'release'] as $verb) {
        $p = fxReal(fxLlm($npc, FX_ACT_CONTROL, $verb, ''));
        $t->must("\"$verb\" is not a new scene: no line needed, no wait key", count($p) === 1 && !isset(fxParamKv($p[0])['wait']), implode(' ', $p));
    }
    $t->must('stop is never held back for a line', fxDo(fxReal(fxLlm($npc, FX_ACT_CONTROL, 'stop', ''))[0] ?? '') === 'stop');
});
