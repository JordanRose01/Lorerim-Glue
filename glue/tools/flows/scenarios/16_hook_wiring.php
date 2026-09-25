<?php
// 16 - the same short story once more, but through the REAL hook files in CHIM's real order (globals -> preprocessing -> [lock]
// -> request-type whitelist -> prerequest -> prompts -> functions -> context_pre -> LLM -> registered post-filters).
// Catches wiring mistakes the function-level scenarios cannot see: a hook that forgets to call the library, a wrong slot,
// a filter that is not registered, a tick that is admitted but never gets its actions switched on.
fx_scenario('16', 'hook wiring: the real hook files in CHIM order give the same offers, injections and gate as the library calls', function (FxT $t) {
    fxNeedIndex($t);
    $c = fxCast('innkeeper'); $npc = $c['name'];
    $alone = fxSnap($c['snap']);
    fxSetScore($npc, $alone, 25);
    $state = function (string $type, array $kv) use ($npc): bool { // a state message through globals.php + preprocessing.php; true = terminated
        $req = [$type, fxNow(), 100000, fxKvString($kv)];
        $GLOBALS['HERIKA_NAME'] = 'Somebody Else';
        fxIncludeHook('globals.php', $req);
        return fxIncludeHook('preprocessing.php', $req);
    };
    $slots = fn() => array_column(Fx::$injections, 'slot', 'id');

    $t->must('preprocessing.php terminates lrg_npcstate and stores it', $state('lrg_npcstate', ['npc' => $npc] + $alone) && isset(fxDb()->t['lrg_npc_state'][$npc]));
    $t->mustCap('index_v3', 'the fast snapshot path never builds the scene index synchronously when it is fresh (well under a second)', function () use ($state, $npc, $alone) {
        $t0 = microtime(true); $state('lrg_npcstate', ['npc' => $npc] + $alone);
        return microtime(true) - $t0 < 1.0;
    });

    // ---- private speech turn
    fxWarm($npc, $alone); // [0.3] 11.2: her own first move needs a warmed-up conversation
    $r = fxHookTurn($npc, 'inputtext', 'Hello.');
    $lib = fxSay($npc, $alone, 'Hello.');
    $t->must('speech turn: hooks offer what the library offers', $r->glueOffered() === $lib->glueOffered() && $r->offered(FX_ACT_START), $r->glueNames() . ' vs ' . $lib->glueNames());
    $r = fxHookTurn($npc, 'inputtext', 'Hello.');
    $t->must('context_pre.php registers the boundaries at character_bottom and the moment at prompt_bottom', in_array('character_bottom', $slots(), true) && in_array('prompt_bottom', $slots(), true)
        && str_contains($r->static, '<personal_boundaries>') && str_contains($r->volatile, '<this_moment>'), json_encode($slots()));
    // [0.4.0] TWO filters now, and the ORDER is the contract (PHASE2_DESIGN 6.1): Phase 1's gate first -
    // it passes ExtCmdLRG_SelectTopic through untouched (integrator edit D-12) - then Phase 2's.
    // Each is registered exactly once per filter list; a second registration would let a gate see its own output.
    $t->must('functions.php registers each post-LLM filter exactly once (Phase 1, Phase 2, then the never-empty rail [pt17-replies])',
        count($r->closures) === 3 && $r->closures[0] === ($GLOBALS['LRG_POSTGATE'] ?? null)
        && $r->closures[1] === ($GLOBALS['LRG_DLG_POSTGATE'] ?? null) && $r->closures[2] === ($GLOBALS['LRG_NE_HOOK'] ?? null), (string) count($r->closures));
    fxSpoke(null); // [0.3 / R10] she says her line in the same reply, or the start does not happen at all
    $w = fxHookLlm($r, [fxLine($npc, 'Follow', 'Player'), fxLine($npc, FX_NAME[FX_ACT_START], 'Player')]);
    $t->must('the registered filter rewrites BeginIntimacy and leaves Follow alone', count($w) === 2 && $w[0] === fxLine($npc, 'Follow', 'Player') && str_contains($w[1], FX_ACT_START . '@ok=1;'), implode(' ', array_map('trim', $w)));

    // ---- public: invitation through the hooks, stripped from the wire
    $state('lrg_npcstate', ['npc' => $npc, 'wit' => '2'] + $alone);
    $r = fxHookTurn($npc, 'inputtext', 'Hello.');
    $t->mustCap(['modes', 'actions_v7'], 'public turn through the hooks: only SuggestPrivacy; the invite line is recorded and removed from the wire', function () use ($r, $npc) {
        $w = fxHookLlm($r, [fxLine($npc, FX_ACT_INVITE, 'quiet'), fxLine($npc, 'Talk', 'x')]);
        return $r->glueOffered() === [FX_ACT_INVITE] && $w === [fxLine($npc, 'Talk', 'x')] && (fxInvite($npc)['state'] ?? '') === 'pending';
    }, fn() => $r->summary() . ' ' . json_encode(fxInvite($npc)));

    // ---- her own tick through the hooks: admitted in preprocessing, actions switched on in prerequest, offer pruned in functions
    fxAdvance(60);
    fxRoll('initiative', 100); // follow-through needs no dice
    $state('lrg_npcstate', ['npc' => $npc] + $alone);
    $tick = fxHookTurn($npc, 'lrg_initiative', FX_CTX . 'approach');
    $t->mustCap(['handle', 'memory'], 'initiative tick through the hooks: admitted (follow-through), actions ON, only Talk + BeginIntimacy + ChangeClothing, guidance injected', function () use ($tick) {
        $have = array_map('strtolower', $tick->enabled); sort($have);
        $want = array_map('strtolower', [FX_ACT_START, FX_ACT_CLOTHING]); sort($want);
        return !$tick->handled && $tick->functionsOn && $have === $want && str_contains($tick->volatile, '<this_moment>') && !empty($tick->prompts['cue']);
    }, fn() => $tick->summary() . ' on=' . ($tick->functionsOn ? 1 : 0) . ' ' . implode(',', $tick->enabled));
    $state('lrg_npcstate', ['npc' => $npc, 'adult' => '0'] + $alone);
    $drop = fxHookTurn($npc, 'lrg_initiative', FX_CTX . 'approach');
    $t->mustCap('handle', 'preprocessing.php TERMINATES a tick that is not admitted (no lock, no LLM): unconfirmed adult', fn() => $drop->handled);

    // ---- scene: start -> lead tick -> ordinary talk, all through the hooks
    $state('lrg_npcstate', ['npc' => $npc] + $alone);
    $r = fxHookTurn($npc, 'inputtext', 'Hello.');
    $w = fxHookLlm($r, [fxLine($npc, FX_ACT_START, 'Player')]);
    $start = fxParamKv($w[0] ?? '')['scene'] ?? fxStartScene();
    $in = ['ostim' => '1'] + $alone;
    $state('lrg_npcstate', ['npc' => $npc] + $in);
    $t->must('preprocessing.php terminates lrg_scene and the start is logged once', $state('lrg_scene', ['ev' => 'start', 'npc' => $npc, 'cid' => 'shook1', 'scene' => $start, 'speed' => '1', 'maxspeed' => '3', 'trans' => '0', 'byglue' => '1', 'x' => '2']) && fxSceneActive($npc) && count(Fx::$events) === 1);
    $lead = fxHookTurn($npc, 'lrg_scenetalk', FX_CTX . 'lead');
    $have = array_map('strtolower', $lead->enabled); sort($have);
    $want = array_map('strtolower', Fx::has('actions_v8') ? [FX_ACT_CONTROL, FX_ACT_CLOTHING, FX_ACT_REQUESTACT] : [FX_ACT_CONTROL, FX_ACT_CLOTHING]); sort($want);
    $t->must('lead tick through the hooks: prerequest.php switches actions on, functions.php leaves the in-scene actions', $lead->functionsOn && $have === $want, 'on=' . ($lead->functionsOn ? 1 : 0) . ' ' . implode(',', $lead->enabled));
    $t->must('lead tick: scene notes are injected at prompt_bottom, nothing romantic at character_bottom needs to be there', str_contains($lead->volatile, '<intimate_scene_now>'));
    $t->must('lead tick: CHIM\'s expressive idle is suppressed (it would break the pose)', !empty($GLOBALS['SCRIPTLINE_ANIMATION_SENT']));
    $w = fxHookLlm($lead, [fxLine($npc, FX_ACT_CONTROL, 'stop')]);
    $t->must('lead tick: the registered filter lets "stop" through as do=stop', count($w) === 1 && (bool) preg_match('/;do=stop$/', fxParam($w[0])), implode(' ', $w));
    $talk = fxHookTurn($npc, 'lrg_scenetalk', FX_CTX . 'a change of pace');
    $t->must('ordinary scene talk through the hooks: actions stay OFF (CHIM will not even parse actions), notes still injected', !$talk->functionsOn && fxHookLlm($talk, [fxLine($npc, FX_ACT_CONTROL, 'P1')]) === [] && str_contains($talk->volatile, '<intimate_scene_now>'));

    // ---- the game's answer comes back as a funcret. [0.5.5] A SUCCESS ends in preprocessing, before the lock (the
    // follow-up is on for every glue row now, so the glue itself ends every result that must stay silent)
    $t->must('preprocessing.php ends a glue SUCCESS before the lock (no funcret turn), and lets a funcret that is not ours pass',
        !$state('funcret', []) && fxIncludeHook('preprocessing.php', ['funcret', fxNow(), 100000, 'command@' . FX_ACT_CONTROL . '@ok=1;cid=shook1;npc=' . $npc . ';do=stop@The scene ends.']));
    $t->mustCap(['memory', 'actions_v7'], '... after recording it', fn() => (fxMem($npc)['last_result']['do'] ?? '') === 'stop' && (fxMem($npc)['last_result']['ok'] ?? null) === true, fn() => json_encode(fxMem($npc)['last_result'] ?? null));
    $t->must('lrg_scene ev=end through the hooks closes the row', $state('lrg_scene', ['ev' => 'end', 'npc' => $npc, 'cid' => 'shook1', 'scene' => $start, 'byglue' => '1']) && !fxSceneActive($npc));

    // ---- a non-adult through the hooks: nothing is injected at all
    $m = fxCast('minor');
    fxAff($m['name'], 100);
    $state('lrg_npcstate', ['npc' => $m['name']] + fxSnap($m['snap']));
    $r = fxHookTurn($m['name'], 'inputtext', 'Hello.');
    $t->must('non-adult through the hooks: zero injections, zero glue actions, and the filter drops everything', Fx::$injections === [] && $r->glueOffered() === []
        && fxHookLlm($r, [fxLine($m['name'], FX_ACT_START, 'Player'), fxLine($m['name'], FX_ACT_INVITE, 'home'), fxLine($m['name'], FX_ACT_CLOTHING, 'undress')]) === [], json_encode($slots()) . ' ' . $r->glueNames());
    // R5 is NOT a global mode: whatever the plugin switches in CHIM's runtime for a romantic turn, it must leave a silent turn exactly as CHIM made it
    $t->must('non-adult through the hooks: CHIM\'s own refusal filter and token budget are left untouched', !$r->refusalFilterOff && $r->maxTokens === null, 'filter_off=' . var_export($r->refusalFilterOff, true) . ' max_tokens=' . var_export($r->maxTokens, true));
    $v = fxCast('vigilant');
    fxAff($v['name'], 100);
    $state('lrg_npcstate', ['npc' => $v['name']] + fxSnap($v['snap']));
    $r = fxHookTurn($v['name'], 'inputtext', 'Hello.');
    $t->must('profile "never" through the hooks: nothing injected, nothing offered, CHIM\'s runtime untouched', Fx::$injections === [] && $r->glueOffered() === [] && !$r->refusalFilterOff && $r->maxTokens === null);
    $by = fxCast('bystander');
    $r = fxHookTurn($by['name'], 'inputtext', 'Hello.'); // nobody the game ever sent a snapshot for: an ordinary CHIM conversation
    $t->must('an NPC without any snapshot (ordinary CHIM talk): nothing injected, nothing offered, CHIM\'s runtime untouched', Fx::$injections === [] && $r->glueOffered() === [] && !$r->refusalFilterOff && $r->maxTokens === null);
});
