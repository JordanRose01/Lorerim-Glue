<?php
// 13 - R9 (13) / R7: speed. Intimate, initiative and scene turns ask for ONE short sentence; command results flow back
// through funcret into lrg_memory.last_result. [0.5.5] A success ends before the lock (no follow-up LLM call); a failure
// the player was waiting on is voiced once, in CHIM's own funcret turn (scenario 29 plays that turn).
fx_scenario('13', 'speed: one short sentence on intimate turns, a success ends pre-lock, a failure is voiced once, results via last_result', function (FxT $t) {
    fxNeedIndex($t);
    $c = fxCast('innkeeper'); $npc = $c['name'];
    $alone = fxSnap($c['snap']);
    fxSetScore($npc, $alone, 40); // drawn
    $max = fxMaxChars();

    // ---- the catalog rows, as the plugin hands them to CHIM (captured by the harness stub)
    Fx::$catalog = [];
    fxSay($npc, $alone, 'Hello.'); // lrgPrepareTurn() installs / repairs the rows when CHIM's catalog lacks them
    $rows = Fx::$catalog;
    $t->must('the plugin installs its action rows into CHIM\'s catalog', isset($rows[FX_ACT_START], $rows[FX_ACT_CONTROL], $rows[FX_ACT_CLOTHING]), implode(',', array_keys($rows)));
    // [0.3] v8 adds the fifth row, RequestAct (server-only, resolved into do=goto by the gate)
    $t->mustCap('actions_v7', 'the catalog has every glue row, display names as in PROTOCOL 6.5', fn() => count(array_intersect(array_keys($rows), FX_GLUE)) === (Fx::has('actions_v8') ? 5 : 4)
        && !array_filter(FX_GLUE, fn($code) => ($rows[$code]['action_name'] ?? '') !== FX_NAME[$code]), fn() => json_encode(array_map(fn($r) => $r['action_name'] ?? '?', $rows)));
    // [0.5.5 / owner addendum 11] CHIM decides its funcret turn per ROW, the glue per RESULT: the follow-up is ON with a
    // prompt on every row and never chains actions; every result that must stay silent ends pre-lock (below, and 29)
    $t->mustCap('actions_v7', 'followup ON with a prompt on EVERY glue row, never chaining actions (a failure is voiced; a success ends pre-lock)',
        fn() => !array_filter($rows, fn($r) => ($r['metadata']['followup']['enabled'] ?? false) !== true || trim((string) ($r['metadata']['followup']['prompt'] ?? '')) === ''
            || ($r['metadata']['followup']['use_functions_again'] ?? true) !== false),
        fn() => implode(', ', array_keys(array_filter($rows, fn($r) => ($r['metadata']['followup']['enabled'] ?? false) !== true))) . ' have no follow-up');
    $t->must('every row: automatic confirmation, placeholder infoaction suppressed, dispatched through the LRG bridge script', !array_filter($rows, fn($r) => ($r['metadata']['confirmation']['default_policy'] ?? '') !== 'automatic'
        || empty($r['metadata']['suppress_placeholder_infoaction']) || ($r['metadata']['bridge_script'] ?? '') !== 'LRG' || ($r['metadata']['bridge_entrypoint'] ?? '') !== 'DispatchExternalCommand'));
    $types = fn(string $code) => array_map('strtolower', (array) ($rows[$code]['metadata']['requirements']['request_types_any'] ?? []));
    $hasAll = fn(array $have, array $want) => !array_diff($want, $have);
    $t->mustCap('actions_v7', 'request_types_any (a hard CHIM filter): BeginIntimacy = speech + lrg_initiative', fn() => $hasAll($types(FX_ACT_START), array_merge(FX_SPEECH_TYPES, ['lrg_initiative'])) && !in_array('lrg_scenetalk', $types(FX_ACT_START), true));
    $t->must('request_types_any: ChangeIntimacy = speech + lrg_scenetalk', $hasAll($types(FX_ACT_CONTROL), array_merge(FX_SPEECH_TYPES, ['lrg_scenetalk'])) && !in_array('lrg_initiative', $types(FX_ACT_CONTROL), true));
    $t->mustCap('actions_v7', 'request_types_any: ChangeClothing = speech + lrg_scenetalk + lrg_initiative', fn() => $hasAll($types(FX_ACT_CLOTHING), array_merge(FX_SPEECH_TYPES, ['lrg_scenetalk', 'lrg_initiative'])));
    $t->mustCap('actions_v7', 'request_types_any: SuggestPrivacy = speech + lrg_initiative', fn() => $hasAll($types(FX_ACT_INVITE), array_merge(FX_SPEECH_TYPES, ['lrg_initiative'])) && !in_array('lrg_scenetalk', $types(FX_ACT_INVITE), true));
    $t->must('no row description or parameter hint contains explicit-permission vocabulary (rows are visible on EVERY turn CHIM offers them)',
        !array_filter($rows, fn($r) => fxHasExplicitPermission(json_encode([$r['description'] ?? '', $r['parameters_json'] ?? '']))));

    // ---- the prompt cues CHIM uses for the glue's own request types (hook level: prompts.php)
    $sc = fxBeginScene($t, $npc, $alone);
    $in = $sc['snap'];
    $cueText = fn(FxTurnResult $r) => implode("\n", array_map('strval', (array) ($r->prompts['cue'] ?? [])));
    $lead = fxHookTurn($npc, 'lrg_scenetalk', FX_CTX . 'lead');
    $talk = fxHookTurn($npc, 'lrg_scenetalk', FX_CTX . 'a change of pace');
    $t->must('lrg_scenetalk has a cue and a player_request entry (CHIM cannot build the request without them)', !empty($lead->prompts['cue']) && !empty($lead->prompts['player_request']) && !empty($talk->prompts['cue']));
    $t->must('scene talk + lead tick: a token cap is set as the safety net behind the instruction (TTS time is linear in text, under CHIM\'s lock)', $talk->maxTokens !== null && $lead->maxTokens !== null, 'talk=' . var_export($talk->maxTokens, true) . ' lead=' . var_export($lead->maxTokens, true));
    $t->should('... and it is tight: <= 250 tokens (120 characters of speech plus the JSON envelope and one action)', (int) $talk->maxTokens <= 250 && (int) $lead->maxTokens <= 250, 'talk=' . var_export($talk->maxTokens, true) . ' lead=' . var_export($lead->maxTokens, true));
    $t->mustCap('actions_v7', 'scene talk + lead tick: cue / notes ask for ONE short sentence', fn() => fxAsksOneShortSentence($cueText($talk) . $talk->volatile) && fxAsksOneShortSentence($cueText($lead) . $lead->volatile), fn() => fx_short($cueText($talk), 300));
    $t->mustCap('actions_v7', "scene turns name the length limit scene_talk.max_chars ($max)", fn() => str_contains($cueText($talk) . $talk->volatile, (string) $max) && str_contains($cueText($lead) . $lead->volatile, (string) $max));
    $t->should('scene notes stay short on a speech-only turn (< 1500 chars: prompt size is latency too)', strlen($talk->volatile) < 1500, strlen($talk->volatile) . ' chars');
    $t->must('cues carry no explicit-permission vocabulary themselves (the level lives in the gated notes)', !fxHasExplicitPermission($cueText($talk) . $cueText($lead)));
    $say = fxSay($npc, $in, 'Hello.');
    $t->mustCap('actions_v7', 'player speech inside the scene: the notes ask for one short sentence too', fn() => fxAsksOneShortSentence($say->volatile) && str_contains($say->volatile, (string) $max), fn() => fx_short($say->volatile, 300));
    fxSendScene($npc, 'end', ['scene' => $sc['start'], 'cid' => $sc['cid']]);

    fxAdvance(400);
    fxRoll('initiative', 1);
    fxSendSnapshot($npc, $alone);
    $tick = fxHookTurn($npc, 'lrg_initiative', FX_CTX . 'approach');
    $t->mustCap('handle', 'lrg_initiative (admitted): cue + player_request exist, token budget capped, one short sentence asked', fn() => !$tick->handled && !empty($tick->prompts['cue']) && !empty($tick->prompts['player_request'])
        && $tick->maxTokens !== null && $tick->maxTokens <= 250 && fxAsksOneShortSentence($cueText($tick) . $tick->volatile), fn() => $tick->summary() . ' tokens=' . var_export($tick->maxTokens, true) . ' cue=' . fx_short($cueText($tick), 200));
    $t->mustCap('handle', 'lrg_initiative: the cue itself is neutral (no explicit vocabulary, no place, no act)', fn() => !fxHasExplicitPermission($cueText($tick)));
    $t->must('glue request types are NOT fast commands (they need the LLM); state messages are', (function () {
        $fast = array_map('strtolower', (array) ($GLOBALS['external_fast_commands'] ?? []));
        return in_array('lrg_npcstate', $fast, true) && in_array('lrg_scene', $fast, true) && in_array('lrg_log', $fast, true) && !in_array('lrg_scenetalk', $fast, true) && !in_array('lrg_initiative', $fast, true);
    })(), implode(',', (array) ($GLOBALS['external_fast_commands'] ?? [])));

    // follow-through on player speech is an intimate turn as well
    fxReset();
    fxSetScore($npc, $alone, 10);
    fxSay($npc, ['wit' => '2'] + $alone, 'Hello.');
    fxLlm($npc, FX_ACT_INVITE, 'quiet');
    fxAdvance(60);
    $follow = fxSay($npc, $alone, 'Hello.');
    $t->mustCap(['modes', 'memory'], 'follow-through turn: one short sentence asked', fn() => $follow->mode() === 'follow' && fxAsksOneShortSentence($follow->volatile), fn() => fx_short($follow->volatile, 300));
    $plain = fxSay(fxCast('commoner')['name'], fxSnap(fxCast('commoner')['snap']), 'Hello.');
    $t->should('an ordinary closed turn is NOT squeezed into one sentence (that would make every NPC curt)', !fxAsksOneShortSentence($plain->guidance()));

    // ---- results: a success is recorded and ends pre-lock; a failure the player was waiting on is VOICED at once
    fxReset();
    fxSetScore($npc, $alone, 10);
    $sc = fxBeginScene($t, $npc, $alone);
    $t->mustCap(['memory', 'actions_v7'], 'a successful start is recorded: last_result {cmd, ok=true}', fn() => (fxMem($npc)['last_result']['ok'] ?? null) === true
        && str_contains((string) (fxMem($npc)['last_result']['cmd'] ?? ''), 'StartIntimacy'), fn() => json_encode(fxMem($npc)['last_result'] ?? null));
    $r = fxSay($npc, $sc['snap'], 'Hello.');
    $w = fxLlm($npc, FX_ACT_CONTROL, (string) array_key_first($r->options()));
    $reason = 'that position cannot be reached from here';
    $res = $w ? fxFuncret($w[0], "Error: $reason") : 'no command';
    $t->mustCap(['memory', 'actions_v7'], '[0.5.5] a failed goto on a speech turn: passed to CHIM\'s funcret turn, last_result = {do=goto, ok=false, reason, told=true} - attributed through the echoed npc= (HERIKA_NAME is a decoy here)', function () use ($npc, $res, $reason) {
        $l = fxMem($npc)['last_result'] ?? [];
        return $res === 'pass' && ($l['ok'] ?? null) === false && ($l['do'] ?? '') === 'goto' && str_contains((string) ($l['reason'] ?? ''), $reason) && ($l['told'] ?? null) === true && (int) ($l['at'] ?? 0) === fxNow();
    }, fn() => $res . ' ' . json_encode(fxMem($npc)['last_result'] ?? null));
    $t->mustCap(['memory', 'actions_v7'], '... and nothing was recorded for the decoy name', fn() => fxMem('Somebody Else') === []);
    fxAdvance(10);
    $next = fxSay($npc, $sc['snap'], 'Hello.');
    $t->mustCap(['memory', 'actions_v7'], '... it was said when it happened, so her next turn does not repeat it', fn() => !str_contains($next->volatile, $reason), fn() => fx_short($next->volatile, 400));
    $t->must('a funcret never reaches CHIM\'s event log through the glue', !array_filter(Fx::$events, fn($e) => str_contains(json_encode($e), 'ExtCmdLRG_')));
});
