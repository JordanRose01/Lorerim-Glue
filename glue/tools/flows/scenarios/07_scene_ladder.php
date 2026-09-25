<?php
// 07 - R9 (7) / R6: the ladder. Start gentle -> the option list respects the ceiling -> the lead tick offers only the glue's
// in-scene actions -> one step up after the pace time, never two.
fx_scenario('07', 'scene ladder: gentle start, ceiling respected, lead tick = in-scene actions only, one step per pace time', function (FxT $t) {
    $c = fxCast('innkeeper'); $npc = $c['name'];
    $alone = fxSnap($c['snap']);
    fxSetScore($npc, $alone, 10);
    $sc = fxBeginScene($t, $npc, $alone);
    $in = $sc['snap']; $cid = $sc['cid'];
    $startTier = fxTierNum(fxSceneTier($sc['start']));
    $t->must('the scene starts gentle (kissing / affection)', in_array(fxSceneTier($sc['start']), ['kissing', 'affection'], true), $sc['start'] . ' = ' . (fxSceneTier($sc['start']) ?? 'unknown'));
    $t->must('the start event is on CHIM\'s event log as ONE neutral line', count(Fx::$events) === 1 && !fxHasExplicitPermission((string) (Fx::$events[0][3] ?? '')), json_encode(Fx::$events));
    $t->must('ladder memory starts at the start scene\'s tier', (int) (fxSceneRow($npc)['_maxtier'] ?? -1) === $startTier, json_encode(fxSceneRow($npc)));
    $above = fn(FxTurnResult $r) => array_filter($r->options(), fn($o) => fxTierNum($o['tier']) > (int) ($r->progress()['ceiling'] ?? 4));

    // ---- player speech right after the start
    $r = fxSay($npc, $in, 'Hello.');
    $t->mustCap('modes', 'speech turn in the scene: mode = scene', fn() => $r->mode() === 'scene', fn() => $r->summary());
    // [0.3] the in-scene offer is ChangeIntimacy (the verbs) + ChangeClothing + RequestAct (the acts)
    $inScene = Fx::has('actions_v8') ? [FX_ACT_CONTROL, FX_ACT_CLOTHING, FX_ACT_REQUESTACT] : [FX_ACT_CONTROL, FX_ACT_CLOTHING];
    $t->must('the in-scene actions are offered; BeginIntimacy, SuggestPrivacy and movement are not', $r->glueOffered() === $inScene && !$r->offered('Follow') && !$r->offered('MoveTo') && !$r->offered('TakeASeat'), $r->glueNames() . ' | ' . implode(',', $r->enabled));
    $p = $r->progress();
    $t->must('reached = start tier; first ceiling = reached + 0 or + 1', (int) ($p['reached'] ?? -1) === $startTier && in_array((int) ($p['ceiling'] ?? -1) - $startTier, [0, 1], true), json_encode($p));
    $t->must('options come from the index and none is above the ceiling', count($r->options()) > 0 && !$above($r), implode(' | ', array_map(fn($o) => $o['id'] . ':' . $o['tier'], $r->options())));
    // [0.3] the P-key list is gone from the notes: acts are offered by act key through RequestAct
    $t->must('scene notes: <intimate_scene_now>, the acts open right now listed by key, stop named',
        str_contains($r->volatile, '<intimate_scene_now>') && (bool) preg_match('/\bstop\b/', $r->volatile)
        && (!Fx::has('actions_v8') || !$r->acts() || (bool) preg_match('/\b' . preg_quote((string) array_key_first($r->acts()), '/') . '\s*=/', $r->volatile)),
        fx_short($r->volatile, 400));
    $t->should('scene notes state the ladder as a limit on what SHE starts', (bool) preg_match('/\b(starts?|own next step)\b/i', $r->volatile));

    // two steps up is "too soon" for HER OWN pick: dropped, the NPC answers in words
    $word = fxTooSoonWord($sc['start'], (int) ($p['ceiling'] ?? 4));
    if ($word === '') { $t->pending('a request above the ceiling is dropped', 'no configured position word resolves above the ceiling for this pair in the installed packs'); }
    else { $t->must('a request above the ceiling is dropped (the word is discovered from the config, not written here)', fxNothingSent(fxLlm($npc, FX_ACT_CONTROL, $word))); }

    // ---- lead tick right away: she may act, but not escalate yet.
    // [0.3 / G2] After the player speaks she keeps out of the way, so a lead tick would be dropped before
    // the lock. Handing her the lead in words is what clears that hold without moving the clock.
    fxSay($npc, $in, 'you lead');
    $lead = fxLeadTick($npc, $in);
    $t->must('lead tick right after the start: ceiling = reached (no escalation before her pace time)', !empty($lead->turn['lead']) && (int) $lead->progress()['ceiling'] === $startTier, json_encode($lead->progress()));
    $have = array_map('strtolower', $lead->enabled); sort($have);
    $want = array_map('strtolower', $inScene); sort($want);
    $t->must('lead tick offers ONLY the in-scene actions, and actions are switched on for it', $have === $want && $lead->functionsOn, 'on=' . ($lead->functionsOn ? 1 : 0) . ' ' . implode(',', $lead->enabled));
    $t->must('lead tick: nothing above the ceiling is listed', !$above($lead));
    $other = fxLeadTick($npc, $in, ['order' => 'prerequest_first']);
    $h2 = array_map('strtolower', $other->enabled); sort($h2);
    $t->must('same offer when prerequest runs BEFORE the functions hook (CHIM\'s real order)', $h2 === $want && $other->functionsOn, implode(',', $other->enabled));

    // ---- ordinary scene talk: speech only
    $talk = fxSceneTalk($npc, $in, 'a change of pace');
    $t->must('ordinary scene talk: no actions, actions stay off, a hallucinated one is dropped', $talk->glueOffered() === [] && !$talk->functionsOn && fxNothingSent(fxLlm($npc, FX_ACT_CONTROL, 'P1')) && fxNothingSent(fxLlm($npc, FX_ACT_CLOTHING, 'undress')), $talk->summary());
    $t->must('... but the scene is described to her', str_contains($talk->volatile, '<intimate_scene_now>'));

    // ---- after her pace time: exactly one step
    $pace = (string) ($lead->progress()['pace'] ?? 'normal');
    $wait = (int) fxCfg("scene_progression.pace_seconds.$pace", ['slow' => 90, 'normal' => 45, 'eager' => 20][$pace] ?? 45);
    fxAdvance($wait + 1);
    $lead = fxLeadTick($npc, $in);
    $t->mustCap('clock', "lead tick after her pace time ($pace, {$wait}s): ceiling = reached + 1, exactly", fn() => (int) $lead->progress()['reached'] === $startTier && (int) $lead->progress()['ceiling'] === $startTier + 1, fn() => json_encode($lead->progress()));
    $t->mustCap('clock', '... and no option above it', fn() => !$above($lead));
    $pick = null;
    foreach ($lead->options() as $k => $o) { if (fxTierNum($o['tier']) === $startTier + 1) { $pick = $k; break; } }
    if ($pick === null) {
        $t->pending('she takes the step: P-key of the next tier -> goto', 'the index offers no option exactly one tier above ' . fxSceneTier($sc['start']) . ' from ' . $sc['start'] . (Fx::has('clock') ? '' : ' (and the clock seam is missing)'));
        return;
    }
    $wire = fxLlm($npc, FX_ACT_CONTROL, $pick);
    $picked = $lead->options()[$pick]['id'];
    // [0.3] a step SHE chose carries nowarp=1 (never a fade jump on her own move) and wait=begin
    $t->must("she takes the step: $pick -> do=goto;scene=<that id>", count($wire) === 1 && fxShape($wire[0], 'do=goto;scene=' . preg_quote($picked, '/')), implode(' ', $wire));
    $t->mustCap('decorate', 'her own step never warps and waits for her line: nowarp=1, wait=begin, no warp key',
        fn() => (fxParamKv($wire[0])['nowarp'] ?? '') === '1' && (fxParamKv($wire[0])['wait'] ?? '') === 'begin' && !isset(fxParamKv($wire[0])['warp']), fn() => fxParam($wire[0]));
    fxFuncret($wire[0], 'Moving into a new position.');
    fxSendScene($npc, 'change', ['scene' => $picked, 'cid' => $cid]);
    $t->must('the game reports the new scene: ladder memory moves up one tier and the pace clock restarts', (int) fxSceneRow($npc)['_maxtier'] === $startTier + 1 && (int) fxSceneRow($npc)['_tier_since'] === fxNow(), json_encode(array_intersect_key(fxSceneRow($npc), ['_maxtier' => 1, '_tier_since' => 1])) . ' now=' . fxNow());

    // ---- never two: the very next tick may not climb again
    $next = fxLeadTick($npc, $in);
    $t->must('the very next lead tick: ceiling = the new tier, not one more', (int) $next->progress()['reached'] === $startTier + 1 && (int) $next->progress()['ceiling'] === $startTier + 1, json_encode($next->progress()));
    fxAdvance($wait + 1);
    $later = fxLeadTick($npc, $in);
    $t->mustCap('clock', 'after another pace time: one more step, capped at the top tier', fn() => (int) $later->progress()['ceiling'] === min(4, $startTier + 2), fn() => json_encode($later->progress()));

    // a change inside the same tier keeps history and clock
    $since = (int) fxSceneRow($npc)['_tier_since'];
    fxAdvance(5);
    fxSendScene($npc, 'change', ['scene' => $picked, 'cid' => $cid, 'speed' => '2']);
    $t->must('a change inside the same tier keeps _maxtier and the pace clock', (int) fxSceneRow($npc)['_maxtier'] === $startTier + 1 && (int) fxSceneRow($npc)['_tier_since'] === $since);
    // going back down never lowers what was reached
    fxSendScene($npc, 'change', ['scene' => $sc['start'], 'cid' => $cid]);
    $t->must('returning to a gentler scene does not lower what was reached', (int) fxSceneRow($npc)['_maxtier'] === $startTier + 1);

    // ---- the end
    fxSendScene($npc, 'end', ['scene' => $sc['start'], 'cid' => $cid]);
    // [0.3 / G5.9-10] the event log now also carries the neutral landing lines; the LAST one is the summary
    $last = (string) (Fx::$events[count(Fx::$events) - 1][3] ?? '');
    $t->must('ev=end closes the scene row and is logged as one neutral line', !fxSceneActive($npc) && count(Fx::$events) >= 2 && !fxHasExplicitPermission($last), $last);
    // [0.3 / 11.2] her own first move is held back for post_scene_cooldown_seconds after a scene ended
    fxAdvance((int) fxCfg('start.post_scene_cooldown_seconds', 300) + 30);
    fxWarm($npc, $alone);
    $after = fxSay($npc, $alone, 'Hello.');
    $t->must('after the end: ChangeIntimacy is gone and a late ChangeIntimacy line is dropped; BeginIntimacy is possible again', !$after->offered(FX_ACT_CONTROL) && fxNothingSent(fxLlm($npc, FX_ACT_CONTROL, 'P1')) && $after->offered(FX_ACT_START), $after->summary());
    $t->mustCap('modes', 'after the end: mode = private', fn() => $after->mode() === 'private', fn() => $after->summary());

    // a new scene starts its own history
    $sc2 = fxBeginScene($t, $npc, $alone);
    $t->must('a new scene starts its own ladder', (int) fxSceneRow($npc)['_maxtier'] === fxTierNum(fxSceneTier($sc2['start'])));
});
