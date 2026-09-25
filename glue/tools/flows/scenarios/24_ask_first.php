<?php
// 24 - R11: before a NEW sexual act SHE asks instead of doing it, in her own words, and only a yes from the player
// makes it happen. A no is remembered for the rest of the scene so she does not nag. Rules only - no example lines.
/**
 * Put the thread in a scene from which a SEXUAL act she has not had yet is reachable, and lift her ladder
 * memory to the top so the ceiling is not what stops her. Returns the scene id, or null when the installed
 * packs offer no such spot. The scene payload is written through the game's own ev=change message.
 */
function fx24_stage(string $npc, array $sc, string $cid): ?string
{
    $candidates = [$sc['start']];
    foreach (lrgSceneWalk($sc['start'], [], 4, 3, FX_SEXES, '') as $w) { $candidates[] = (string) $w['scene']['id']; }
    foreach ($candidates as $id) {
        $sexualActs = array_filter(lrgActOptions($id, [], 4, FX_SEXES, '', 1, [], 64), fn($a) => $a['tier'] === 'sexual');
        if (!$sexualActs) { continue; }
        fxSendScene($npc, 'change', ['scene' => $id, 'cid' => $cid]);
        // her own ceiling must be open to the top, or the act would be "too soon" rather than "ask first"
        $row = fxSceneRow($npc);
        fxDb()->t['lrg_scene_state'][$npc]['payload'] = json_encode(['_maxtier' => 4, '_tier_since' => fxNow(), '_acts_done' => []] + $row);
        return $id;
    }
    return null;
}
fx_scenario('24', 'R11: she asks before a new sexual act; only a yes makes it happen, and a no is remembered', function (FxT $t) {
    $c = fxCast('innkeeper'); $npc = $c['name'];
    $alone = fxSnap($c['snap']);
    fxSetScore($npc, $alone, 10);
    $sc = fxBeginScene($t, $npc, $alone);
    $in = $sc['snap']; $cid = $sc['cid'];
    $cfg = (array) fxCfg('lead.ask_first', []);
    $minAct = (int) ($cfg['min_act_seconds'] ?? 75);

    // ---- stand where a SEXUAL act is the next thing she could pick, and where the scene has not had
    // that act family yet. Which scene that is depends entirely on the installed packs.
    $stage = fx24_stage($npc, $sc, $cid);
    if ($stage === null) { $t->pending('R11', 'the installed packs offer no NEW sexual act from any scene reachable in this thread'); return; }
    fxAdvance($minAct + 5);

    fxSay($npc, $in, 'you lead');
    $lead = fxLeadTick($npc, $in);
    if ($lead->handled || $lead->propose() === null) {
        $t->pending('R11 proposal', $lead->handled ? 'the lead tick was held' : 'no NEW sexual act is reachable from ' . $stage . ' (nothing to ask about)');
        return;
    }
    $prop = $lead->propose();
    $t->must('she wants a NEW sexual act and asks instead of doing it', (string) ($prop['act'] ?? '') !== '', json_encode($prop));
    $t->must('the notes tell her to ask in her own words and choose no action', str_contains($lead->volatile, 'asks for it in') && str_contains($lead->volatile, 'chooses no action'), fx_short($lead->volatile, 300));
    $t->must('... and to wait for a yes', str_contains($lead->volatile, 'only happens if the player says yes'), fx_short($lead->volatile, 300));
    $t->must('RequestAct is hidden on a proposal turn - but ChangeIntimacy stays, so "stop" never disappears',
        !$lead->offered(FX_ACT_REQUESTACT) && $lead->offered(FX_ACT_CONTROL), $lead->glueNames());
    $t->must('the reason is logged with the hidden action', (string) ($lead->hidden()[FX_ACT_REQUESTACT] ?? '') !== '', json_encode($lead->hidden()));

    // ---- she said nothing: she did not really ask, so nothing is PENDING. The cooldown and the per-scene
    // cap still count it, though: they count the ASKING, which cost a turn either way, and counting only
    // the spoken ones let her pick the same act again on the very next turn for nothing.
    $tierSince = (int) (fxSceneRow($npc)['_tier_since'] ?? 0);
    fxLlmNothing($npc, '');
    $t->mustCap('memory', 'she said nothing: no proposal is remembered (she did not ask)', fn() => !is_array(fxMem($npc)['proposal'] ?? null), fn() => json_encode(fxMem($npc)['proposal'] ?? null));
    $t->must('... but the asking is counted', (int) (fxSceneRow($npc)['_proposals'] ?? 0) === 1 && (int) (fxSceneRow($npc)['_last_prop_at'] ?? 0) === fxNow(), json_encode(fxSceneRow($npc)));
    $t->must('... and recording it does not restart the scene\'s own clocks (the row is stored without the game\'s ev)',
        (int) (fxSceneRow($npc)['_tier_since'] ?? 0) === $tierSince && (int) (fxSceneRow($npc)['_climaxes'] ?? 0) === 0 && fxSceneActive($npc), json_encode(fxSceneRow($npc)));
    fxSay($npc, $in, 'you lead');
    $soon = fxLeadTick($npc, $in);
    $t->must('... so she cannot ask again on the very next turn', $soon->handled || $soon->propose() === null, json_encode($soon->propose()));

    // ---- she asked: it is remembered, and the scene counts it
    fxAdvance((int) ($cfg['cooldown_seconds'] ?? 120) + 10);
    fxSay($npc, $in, 'you lead');
    $lead = fxLeadTick($npc, $in);
    if ($lead->handled || $lead->propose() === null) { $t->pending('R11 recording', 'the second proposal turn could not be staged'); return; }
    $asked = (string) $lead->propose()['act'];
    fxLlmNothing($npc);
    $t->mustCap('memory', 'she asked: the proposal is remembered with the act and a deadline', fn() => (string) ((fxMem($npc)['proposal'] ?? [])['act'] ?? '') === $asked
        && (int) ((fxMem($npc)['proposal'] ?? [])['expires'] ?? 0) > fxNow(), fn() => json_encode(fxMem($npc)['proposal'] ?? null));
    $t->must('the scene counts the proposal and remembers when it was made', (int) (fxSceneRow($npc)['_proposals'] ?? 0) === 2 && (int) (fxSceneRow($npc)['_last_prop_at'] ?? 0) === fxNow(), json_encode(fxSceneRow($npc)));

    // ---- while it is pending she takes no lead turns at all (inside its ttl, which is what "pending" means)
    fxAdvance(min(30, (int) ($cfg['ttl_seconds'] ?? 90) - 10));
    $t->mustCap('handle', 'while a proposal is pending her lead tick is dropped before the lock', fn() => fxLeadTick($npc, $in)->handled);

    // ---- the player says no: it is remembered for the rest of the scene
    $no = fxSay($npc, $in, 'not now');
    $t->mustCap('intent', '"not now" is understood as the answer to her question', fn() => $no->intentIs('no', 'high') && ($no->intent()['act'] ?? '') === $asked, fn() => json_encode($no->intent()));
    $t->mustCap('memory', '... the proposal is cleared', fn() => !is_array(fxMem($npc)['proposal'] ?? null));
    $t->must('... the act is remembered as refused for the rest of this scene', in_array($asked, (array) (fxSceneRow($npc)['_prop_no'] ?? []), true), json_encode(fxSceneRow($npc)['_prop_no'] ?? null));
    $t->mustCap('intent', '... and the notes tell her to let it go', fn() => str_contains($no->guidance(), 'does not bring it up again'), fn() => fx_short($no->guidance(), 200));
    $t->mustCap('decorate', '... nothing is sent to the game on a no', fn() => fxNothingSent(fxLlmNothing($npc)));

    fxAdvance((int) ($cfg['cooldown_seconds'] ?? 120) + 10);
    fxSay($npc, $in, 'you lead');
    $lead2 = fxLeadTick($npc, $in);
    if (!$lead2->handled) {
        $t->must('she never proposes the refused act again this scene', $lead2->propose() === null || (string) $lead2->propose()['act'] !== $asked, json_encode($lead2->propose()));
    }

    // ---- a yes carries it out
    fxReset();
    fxSetScore($npc, $alone, 10);
    $sc = fxBeginScene($t, $npc, $alone);
    $in = $sc['snap']; $cid = $sc['cid'];
    if (fx24_stage($npc, $sc, $cid) === null) { $t->pending('R11 yes', 'no spot with a new sexual act in the second run'); return; }
    fxAdvance($minAct + 5);
    fxSay($npc, $in, 'you lead');
    $lead = fxLeadTick($npc, $in);
    if ($lead->handled || $lead->propose() === null) { $t->pending('R11 yes', 'no proposal could be staged in the second run'); return; }
    $asked = (string) $lead->propose()['act'];
    fxLlmNothing($npc);
    $yes = fxSay($npc, $in, 'yes');
    $t->mustCap('intent', 'a yes is understood as the answer, carrying the act she asked about', fn() => $yes->intentIs('yes', 'high') && ($yes->intent()['act'] ?? '') === $asked, fn() => json_encode($yes->intent()));
    $w = fxReal(fxLlmNothing($npc));
    $t->mustCap('decorate', '... and the server carries it out even if the model chose no action', fn() => count($w) === 1 && fxDo($w[0]) === 'goto', fn() => implode(' ', $w));
    $t->mustCap('memory', '... the proposal is cleared once answered', fn() => !is_array(fxMem($npc)['proposal'] ?? null));

    // ---- an unrelated yes two turns later must not execute a sex act nobody was answering about
    fxSay($npc, $in, 'you lead');
    $lead = fxLeadTick($npc, $in);
    if (!$lead->handled && $lead->propose() !== null) {
        fxLlmNothing($npc);
        fxSay($npc, $in, 'That was good.');
        fxSay($npc, $in, 'Tell me about the inn.');
        $late = fxSay($npc, $in, 'yes');
        $t->mustCap('intent', 'a "yes" three turns after she asked is not an answer to her question any more', fn() => !$late->intentIs('yes'), fn() => json_encode($late->intent()));
    }
});
