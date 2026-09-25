<?php
// 18 - R7: the scene index is NEVER built inside an LLM request. A rebuild may only ever be started from the
// fast lrg_npcstate path (a detached process), a stale index is served meanwhile, and there is no 24 h expiry.
// The seam $GLOBALS['LRG_TEST_NO_SPAWN'] (set in fxReset) records the command instead of starting a process.
fx_scenario('18', 'R7: no LLM request can start a scene-index build; a stale index is still served', function (FxT $t) {
    fxNeedIndex($t);
    if (!function_exists('lrgIndexStatus') || !function_exists('lrgIndexMaybeRebuildAsync')) {
        throw new FxPending('the non-blocking index functions (PROTOCOL 7.1) are not delivered by this plugin');
    }
    $c = fxCast('innkeeper'); $npc = $c['name'];
    $alone = fxSnap($c['snap']);
    fxSetScore($npc, $alone, 10);
    $dir = fxPluginDir() . '/data';

    $t->must('the no-spawn seam is on for every flow run: a build can be decided but never started',
        !empty($GLOBALS['LRG_TEST_NO_SPAWN']));

    // ---- an LLM-triggering turn must not decide, start or wait for a build
    @unlink($dir . '/.index_checked');
    unset($GLOBALS['LRG_TEST_SPAWNED']);
    $sc = fxBeginScene($t, $npc, $alone);
    $in = $sc['snap'];
    unset($GLOBALS['LRG_TEST_SPAWNED']);       // the snapshot inside fxBeginScene is allowed to
    @unlink($dir . '/.index_checked');
    $speech = fxSay($npc, $in, 'What now?');
    $after = $GLOBALS['LRG_TEST_SPAWNED'] ?? null;
    $t->must('a player-speech turn inside a scene starts no build', $after === null, (string) $after);
    $t->must('... and does not report one as running', empty(lrgIndexStatus()['building']));
    unset($GLOBALS['LRG_TEST_SPAWNED']);
    @unlink($dir . '/.index_checked');
    fxLeadTick($npc, $in);
    $t->must('a lead tick starts no build', ($GLOBALS['LRG_TEST_SPAWNED'] ?? null) === null, (string) ($GLOBALS['LRG_TEST_SPAWNED'] ?? ''));
    unset($GLOBALS['LRG_TEST_SPAWNED']);
    @unlink($dir . '/.index_checked');
    fxInitiativeTick($npc, $alone);
    $t->must('an initiative tick starts no build', ($GLOBALS['LRG_TEST_SPAWNED'] ?? null) === null, (string) ($GLOBALS['LRG_TEST_SPAWNED'] ?? ''));

    // ---- the fast state path is the ONE place a rebuild may be decided
    $t->must('the scene options of that turn came from the served index', $speech->options() !== [] || $speech->handled);
    unset($GLOBALS['LRG_TEST_SPAWNED']);
    @unlink($dir . '/.index_checked');
    $meta = $dir . '/scene_index.meta.json';
    $saved = is_file($meta) ? (string) file_get_contents($meta) : null;
    if ($saved !== null) {
        $m = json_decode($saved, true) ?: [];
        $m['sig'] = 'deliberately-wrong-signature';
        file_put_contents($meta, json_encode($m));
    }
    // through the REAL preprocessing.php hook: PROTOCOL 7.2 puts lrgIndexMaybeRebuildAsync() there
    $snapMsg = $alone; $snapMsg['npc'] = $npc;
    fxIncludeHook('preprocessing.php', ['lrg_npcstate', fxNow(), 100000, fxKvString($snapMsg)]);
    $spawned = $GLOBALS['LRG_TEST_SPAWNED'] ?? null;
    $t->must('a stale signature on the FAST state path does decide a rebuild', $spawned !== null && str_contains((string) $spawned, 'warm'), (string) $spawned);
    $t->must('... and the rebuild is detached, not run inside the request', str_contains((string) $spawned, '&'), (string) $spawned);

    // ---- while it is stale the cached index is still served
    $r = fxSay($npc, $in, 'What now?');
    $t->must('a stale index is served, not rebuilt: the scene is still described and options are listed',
        $r->options() !== [] && str_contains($r->volatile, '<intimate_scene_now>'), $r->summary());
    if ($saved !== null) { file_put_contents($meta, $saved); }
    unset($GLOBALS['LRG_TEST_SPAWNED']);
    @unlink($dir . '/.index_checked');
});
