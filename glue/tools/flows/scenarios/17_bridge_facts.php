<?php
// 17 - R8 on the server side: the new facts of snapshot v=2 and lrg_scene reach the turn the way PROTOCOL.md says -
// stale facts are not trusted, who leads decides whether a lead tick may act, climaxes are counted, Serana Dialogue
// Expansion's progress arrives as WORDS, and a missing key always means the strict reading.
fx_scenario('17', 'bridge facts: stale snapshot, leader / auto / wind-down vs lead ticks, climax count, SDE words, clothing state', function (FxT $t) {
    fxNeedIndex($t);
    $c = fxCast('innkeeper'); $npc = $c['name'];
    $alone = fxSnap($c['snap']);
    fxSetScore($npc, $alone, 10);

    // ---- facts older than snapshot_max_age_seconds are not facts
    $maxAge = (int) fxCfg('snapshot_max_age_seconds', 90);
    fxSendSnapshot($npc, $alone);
    fxAdvance($maxAge + 30);
    $r = fxTurn($npc, 'inputtext', 'Hello.');
    $t->mustCap('clock', 'a snapshot older than snapshot_max_age_seconds: nothing offered, reason no_fresh_snapshot', fn() => $r->glueOffered() === [] && $r->reasons() === ['no_fresh_snapshot'], fn() => $r->summary());
    // [0.5.2 / pt10] The one thing a blind turn now DOES say. Playtest 10 spent thirty minutes here and
    // the model was given no hint at all that the glue had gone blind, so it answered as though things
    // were happening. The note names no action and permits no wording: the <personal_boundaries> block,
    // which is where her standing, her price and the explicitness live, stays empty.
    $t->mustCap(['clock', 'modes'], '... mode silent, no explicit wording (x null), and no boundaries block',
        fn() => $r->mode() === 'silent' && $r->x() === null && $r->static === '' && !fxHasExplicitPermission($r->guidance()), fn() => $r->summary() . ' static=' . strlen($r->static));
    $t->mustCap(['clock', 'modes'], '... and the model is told plainly that there are no fresh facts, in words only',
        fn() => str_contains($r->volatile, 'no fresh facts') && !$r->hasDirective()
            && !preg_match('/\b(BeginIntimacy|ChangeClothing|SuggestPrivacy|ChangeIntimacy|RequestAct)\b/', $r->volatile), fn() => fx_short($r->volatile, 300));
    fxAdvance(-1 * 40);
    fxWarm($npc, null); // [0.3] 11.2 - without refreshing the snapshot: its age is what this check is about
    $r = fxTurn($npc, 'inputtext', 'Hello.');
    $t->mustCap('clock', 'ten seconds inside the limit it is still trusted', fn() => $r->offered(FX_ACT_START), fn() => $r->summary());

    // ---- inside a scene the licensing snapshot must be fresh too (PROTOCOL section 0: adult=1 on a FRESH snapshot)
    $stale = fxBeginScene($t, $npc, $alone);
    fxAdvance($maxAge + 30);
    $r = fxTurn($npc, 'inputtext', 'What now?'); // deliberately WITHOUT a new snapshot
    $t->mustCap('clock', 'a snapshot that went stale INSIDE a scene: no scene awareness, no wording, no action',
        fn() => $r->x() === null && $r->options() === [] && !fxHasExplicitPermission($r->guidance())
            && !str_contains($r->volatile, 'RIGHT NOW') && fxLlm($npc, FX_ACT_CONTROL, 'faster') === [], fn() => $r->summary());
    $t->mustCap('clock', '... but the spoken "stop" still reaches the game', fn() => count(fxLlm($npc, FX_ACT_CONTROL, 'stop')) === 1);
    fxReset();
    fxAff($npc, 100);
    fxSetScore($npc, $alone, 10);

    // ---- who leads decides whether a lead tick may act (the game should not even send one; a stray one must be harmless)
    $sc = fxBeginScene($t, $npc, $alone);
    $in = $sc['snap']; $cid = $sc['cid'];
    $lead = fxLeadTick($npc, $in);
    $t->must('leader=npc (default): the lead tick may act', !empty($lead->turn['lead']) && $lead->offered(FX_ACT_CONTROL) && $lead->functionsOn, $lead->summary());
    foreach (['leader=player' => ['leader' => 'player'], 'leader=auto, auto=1 (OStim drives)' => ['leader' => 'auto', 'auto' => '1'], 'wd=1 (winding down)' => ['wd' => '1']] as $label => $kv) {
        fxSendScene($npc, 'change', ['scene' => $sc['start'], 'cid' => $cid] + $kv);
        $stray = fxLeadTick($npc, $in);
        $t->shouldCap('actions_v7', "a stray lead tick while $label: no actions, actions stay off", fn() => $stray->glueOffered() === [] && !$stray->functionsOn && fxLlm($npc, FX_ACT_CONTROL, 'P1') === [], fn() => $stray->summary());
        $say = fxSay($npc, $in, 'Hello.');
        $t->must("player speech while $label: the player can still direct and stop", $say->offered(FX_ACT_CONTROL) && str_contains(fxLlm($npc, FX_ACT_CONTROL, 'stop')[0] ?? '', ';do=stop'), $say->summary());
    }
    fxSendScene($npc, 'change', ['scene' => $sc['start'], 'cid' => $cid, 'leader' => 'player']);
    $say = fxSay($npc, $in, 'Hello.');
    $t->shouldCap('actions_v7', 'leader=player: the notes tell her the player directs', fn() => (bool) preg_match('/player (directs|leads|is leading|decides)/i', $say->volatile), fn() => fx_short($say->volatile, 300));

    // ---- climaxes are counted per thread
    [$sensual, $sexual] = fxClimbToTop($npc, $sc['start'], $cid);
    if ($sexual !== '') {
        // [0.3 / G5.9] landing on a new scene writes one neutral line of its own, so the climax messages
        // are judged against the count they started from, not against 1
        $before = count(Fx::$events);
        fxSendScene($npc, 'climax', ['scene' => $sexual, 'cid' => $cid, 'who' => $npc, 'ncl' => '1']);
        fxSendScene($npc, 'climax', ['scene' => $sexual, 'cid' => $cid, 'who' => 'Flowtest Player', 'ncl' => '1', 'pcl' => '1']);
        $t->mustCap('actions_v7', 'two ev=climax messages: _climaxes = 2 in the scene row, ladder memory untouched', fn() => (int) (fxSceneRow($npc)['_climaxes'] ?? -1) === 2 && (int) fxSceneRow($npc)['_maxtier'] === 4, fn() => json_encode(array_intersect_key(fxSceneRow($npc), ['_climaxes' => 1, '_maxtier' => 1])));
        $t->must('ev=climax keeps the scene active and is NOT written to CHIM\'s event log', fxSceneActive($npc) && count(Fx::$events) === $before, json_encode(array_slice(Fx::$events, $before)));
        fxAdvance(120);
        $lead = fxLeadTick($npc, $in);
        $t->shouldCap('actions_v7', 'after a climax the lead notes bring up the wind-down', fn() => (bool) preg_match('/wind[ -]?down/i', $lead->volatile));
    } else {
        $t->pending('climax count', 'no sexual-tier scene for this pair in the installed packs');
    }

    // ---- clothing state is told as a fact
    fxSendScene($npc, 'change', ['scene' => $sc['start'], 'cid' => $cid, 'und' => '1', 'undp' => 'all']);
    $a = fxSay($npc, $in, 'Hello.')->volatile;
    fxSendScene($npc, 'change', ['scene' => $sc['start'], 'cid' => $cid, 'und' => '0', 'undp' => '']);
    $b = fxSay($npc, $in, 'Hello.')->volatile;
    $t->should('und=1 / undp=all changes what the notes say about her clothes', $a !== $b && (bool) preg_match('/undressed|naked|bare/i', $a) && !preg_match('/undressed|naked/i', $b));
    fxSendScene($npc, 'end', ['scene' => $sc['start'], 'cid' => $cid]);

    // ---- Serana Dialogue Expansion: the completed-quest count arrives as words, in the stable block, for willing or not
    fxReset();
    $s = 'Companion Flowtest'; // the game only computes sde for one follower; the server just reads the key, so an invented adult will do
    $base = fxSnap(['fac' => 'PotentialFollowerFaction,CurrentFollowerFaction']);
    fxSetScore($s, $base, -10);
    $none = fxSay($s, ['sde' => '-1'] + $base, 'Hello.');
    $zero = fxSay($s, ['sde' => '0'] + $base, 'Hello.');
    $five = fxSay($s, ['sde' => '5'] + $base, 'Hello.');
    $miss = fxSay($s, ['sde' => null] + $base, 'Hello.');
    $t->mustCap('actions_v7', 'sde=-1 and a missing sde key read the same (say nothing); sde=0 and sde=5 each change the stable block', fn() => $none->static === $miss->static && $zero->static !== $none->static && $five->static !== $zero->static,
        fn() => strlen($none->static) . '/' . strlen($miss->static) . '/' . strlen($zero->static) . '/' . strlen($five->static));
    $t->mustCap('actions_v7', 'the fact lives in the STABLE block (cache friendly); the volatile block does not move with it', fn() => $zero->volatile === $five->volatile);
    $t->mustCap('actions_v7', 'it is information, not permission: not willing stays closed at sde=5, nothing offered, x null', fn() => $five->glueOffered() === [] && ($five->mode() ?? 'closed') === 'closed' && $five->x() === null, fn() => $five->summary());
    $t->shouldCap('actions_v7', 'told as words: the count itself does not appear', fn() => !preg_match('/\bsde\b|\b5 (of|quests?)\b/i', $five->static));
    $kid = fxSay('Flowtest Minor', ['adult' => '0', 'sde' => '5'] + $base, 'Hello.');
    $t->must('sde on an unconfirmed adult changes nothing: silent', $kid->silent() && $kid->glueOffered() === []);
});
