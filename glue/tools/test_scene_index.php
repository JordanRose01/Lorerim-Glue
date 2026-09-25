<?php
// Offline test: builds the scene index from the real MO2 mods folder, prints the data the owner asked for, and asserts.
// Usage (inside WSL):  php tools/test_scene_index.php            (add --quiet to print only the checks)
// Read-only with respect to the modlist; writes only server/lorerim_glue/data/scene_index*.json and small marker files there.
// The request texts below are SEARCH INPUT (short position / act words a player would say), not dialogue.
require __DIR__ . '/../server/lorerim_glue/lib/lrg_scene_index.php';

$fails = 0;
$quiet = in_array('--quiet', $argv, true);
function check(string $what, bool $ok, string $detail = ''): void { global $fails; if (!$ok) { $fails++; } printf("  [%s] %s%s\n", $ok ? 'ok' : 'FAIL', $what, (!$ok && $detail !== '') ? "  <- $detail" : ''); }
function say(string $line = ''): void { global $quiet; if (!$quiet) { echo $line . "\n"; } }
/** [0.3] Why this scene may never be offered or sent, '' = it is fine. */
function fxSceneProblemLocal(string $id): string {
    $s = lrgScene($id);
    if (!$s) { return 'not in the index'; }
    if (!empty($s['excluded'])) { return 'excluded'; }
    if ((string) ($s['hard'] ?? '') !== '') { return 'hard list: ' . $s['hard']; }
    if ((int) ($s['actors'] ?? 0) !== 2) { return 'actors=' . ($s['actors'] ?? '?'); }
    return !empty($s['transition']) ? 'a transition' : '';
}
function ms(float $t): float { return round((microtime(true) - $t) * 1000, 1); }
$GLOBALS['LRG_TEST_NO_SPAWN'] = true; // lrgIndexMaybeRebuildAsync() decides, but starts no process in this test
$data = LRG_DIR . '/data';
$PAIRS = ['MF' => ['male', 'female'], 'FF' => ['female', 'female'], 'MM' => ['male', 'male']];

// ------------------------------------------------------------------ A. build, warm-up, cost
echo "A. build and cost\n";
$r = lrgIndexWarm(true);
$meta = lrgIndexMeta();
printf("  forced build: ok=%s scenes=%d %.1fs (of which %.1fs is_dir over %d enabled mods), %d pack folder(s), index file %d KB\n", var_export($r['ok'], true), $r['scenes'], $r['seconds'],
    $meta['scan_seconds'] ?? 0, $meta['mods'] ?? 0, $meta['packs'] ?? 0, filesize(lrgIndexPath()) / 1024);
check('forced warm-up builds', $r['ok'] && !$r['skipped'] && $r['scenes'] > 100);
$t = microtime(true); $r2 = lrgIndexWarm(); $warm2 = ms($t);
check("second warm-up is skipped as up to date ($warm2 ms, " . count($meta['dirs']) . ' watched paths)', $r2['ok'] && $r2['skipped'] && $r2['scenes'] === $r['scenes']);
$raw = (string) file_get_contents(lrgIndexPath());
$best = INF; for ($i = 0; $i < 5; $i++) { $t = microtime(true); json_decode($raw, true); $best = min($best, microtime(true) - $t); }
lrgIndexForget();
$t = microtime(true); $idx = lrgSceneIndex(); $first = ms($t);
$t = microtime(true); for ($i = 0; $i < 1000; $i++) { lrgSceneIndex(); } $later = (microtime(true) - $t);
printf("  per request: json_decode %.1f ms; first lrgSceneIndex() (read + decode + filter) %.1f ms; later calls %.4f ms\n", $best * 1000, $first, $later);
$t = microtime(true); $st = lrgIndexStatus(); printf("  lrgIndexStatus(): %.1f ms %s\n", ms($t), json_encode($st));
check('status after a build: not stale, not building, no error', !$st['stale'] && !$st['building'] && $st['error'] === '' && $st['scenes'] === count($idx['scenes']));
$t = microtime(true); lrgIndexSignature($meta['dirs']); printf("  signature (modlist + %d paths on the Windows drive): %.1f ms - at most once a minute, only on the fast state message\n", count($meta['dirs']), ms($t));
check('the signature watches a handful of paths, not every scene folder', count($meta['dirs']) <= 20, (string) count($meta['dirs']));

// ------------------------------------------------------------------ B. never build inside a request
echo "B. non-blocking index\n";
$metaRaw = (string) file_get_contents(lrgIndexMetaPath());
$GLOBALS['LRG_INDEX_NO_BUILD'] = true; // behave like a web request from here on
$t = microtime(true); $r3 = lrgIndexMaybeRebuildAsync();
check('fresh index + checked a moment ago: the trigger does nothing (' . ms($t) . ' ms, no Windows-drive access)', $r3 === false && ms($t) < 50);
// (1) no index file at all
rename(lrgIndexPath(), lrgIndexPath() . '.keep');
lrgIndexForget();
$t = microtime(true); $e = lrgSceneIndex(); $tEmpty = ms($t);
check("no cache file: the getter returns the empty index at once ($tEmpty ms) instead of building", $e['scenes'] === [] && $tEmpty < 100 && !is_file(lrgIndexPath()));
check('... and leaves a "rebuild wanted" flag', is_file("$data/.index_wanted") && lrgIndexStatus()['stale']);
check('... every lookup degrades quietly on the empty index', lrgScene('OARE_Missionary') === null && lrgSceneOptions('x', [], 4, 8) === [] && lrgPickStartScene(['male', 'female']) === ''
    && lrgFindSceneByText('missionary', '', ['male', 'female'], '', 4) === null && lrgPickAfterglowScene('x', []) === ''
    // [0.3.1] lrgPickStart() also reports the requested act and the additive `after` scene (w1)
    && array_intersect_key(lrgPickStart(['male', 'female'], ['doublebed']), ['scene' => 1, 'furn' => 1, 'fscene' => 1, 'after' => 1]) === ['scene' => '', 'furn' => '', 'fscene' => '', 'after' => '']
    && lrgPickStart(['male', 'female'], [], 'vaginal/doggy')['scene'] === ''
    && lrgFindActScene('vaginal', ['male', 'female'], '', 4) === null && lrgActInstalled('vaginal', ['male', 'female']) === false
    && lrgFurnitureOptions(['doublebed'], 'x', null, 4, '') === []);
unset($GLOBALS['LRG_TEST_SPAWNED']);
check('... the next fast state message starts the detached CLI warm-up (flag beats the 60 s throttle)', lrgIndexMaybeRebuildAsync() === true
    && str_contains((string) ($GLOBALS['LRG_TEST_SPAWNED'] ?? ''), 'lrg_scene_index.php') && str_contains((string) $GLOBALS['LRG_TEST_SPAWNED'], ' warm'), (string) ($GLOBALS['LRG_TEST_SPAWNED'] ?? ''));
rename(lrgIndexPath() . '.keep', lrgIndexPath());
@unlink("$data/.index_wanted");
// (2) stale index: served as it is, rebuild only requested
$m = json_decode($metaRaw, true); $m['sig'] = 'changed-modlist'; $m['built'] = time() - 3 * 86400;
file_put_contents(lrgIndexMetaPath(), json_encode($m));
$mtime = filemtime(lrgIndexPath());
lrgIndexForget();
$t = microtime(true); $s = lrgSceneIndex(); $tStale = ms($t);
check("stale cache: served unchanged ($tStale ms), nothing rebuilt in the request", count($s['scenes']) === count($idx['scenes']) && filemtime(lrgIndexPath()) === $mtime);
@unlink("$data/.index_checked"); unset($GLOBALS['LRG_TEST_SPAWNED']);
check('... the trigger notices the changed signature and asks for a rebuild', lrgIndexMaybeRebuildAsync() === true && isset($GLOBALS['LRG_TEST_SPAWNED']) && lrgIndexStatus()['stale']);
// (3) age alone never makes it stale (the 24 h rule is gone)
$m = json_decode($metaRaw, true); $m['built'] = time() - 30 * 86400;
file_put_contents(lrgIndexMetaPath(), json_encode($m));
@unlink("$data/.index_checked"); unset($GLOBALS['LRG_TEST_SPAWNED']);
check('a 30 day old index with an unchanged signature is NOT rebuilt', lrgIndexMaybeRebuildAsync() === false && !isset($GLOBALS['LRG_TEST_SPAWNED']) && !lrgIndexStatus()['stale']);
// (4) a failed build is not retried on every snapshot
$m = json_decode($metaRaw, true); $m['error'] = 'cannot read modlist'; $m['attempt'] = time(); $m['sig'] = '';
file_put_contents(lrgIndexMetaPath(), json_encode($m));
@unlink("$data/.index_checked"); touch("$data/.index_wanted"); unset($GLOBALS['LRG_TEST_SPAWNED']);
check('after a failed build the trigger backs off (10 minutes)', lrgIndexMaybeRebuildAsync() === false && !isset($GLOBALS['LRG_TEST_SPAWNED']) && lrgIndexStatus()['error'] !== '');
@unlink("$data/.index_wanted");
// (5) a build in progress is respected
$lock = fopen("$data/.index_lock", 'c');
if ($lock && flock($lock, LOCK_EX | LOCK_NB)) {
    $b = lrgIndexBuilding();
    if ($b) { check('a held build lock reads as "building" and a second warm-up is skipped', lrgIndexStatus()['building'] && lrgIndexWarm(true)['skipped'] === true); }
    else { echo "  [--] file locks are not enforced on this file system (Windows drive staging); skipped\n"; }
    flock($lock, LOCK_UN);
}
if ($lock) { fclose($lock); }
file_put_contents(lrgIndexMetaPath(), $metaRaw);
file_put_contents("$data/.index_checked", 'fresh');
unset($GLOBALS['LRG_INDEX_NO_BUILD']);
lrgIndexForget();
$idx = lrgSceneIndex();
check('restored: index current again', count($idx['scenes']) === $r['scenes'] && !lrgIndexStatus()['stale']);
check('the 24 h expiry is gone from the code', !str_contains((string) file_get_contents(LRG_DIR . '/lib/lrg_scene_index.php'), '86400'));

// ------------------------------------------------------------------ C. tiers and content filter
echo "C. tiers and content filter levels\n";
$tiers = []; $transitions = 0; $packs = [];
foreach ($idx['scenes'] as $s) { $tiers[$s['tier']] = ($tiers[$s['tier']] ?? 0) + 1; $transitions += $s['transition'] ? 1 : 0; $packs[$s['pack']] = ($packs[$s['pack']] ?? 0) + 1; }
ksort($tiers);
say("  scenes " . count($idx['scenes']) . ", transitions $transitions, tiers " . json_encode($tiers));
say("  packs " . json_encode($packs));
say("  furniture supertypes " . json_encode($idx['furn'] ?? []));
$usable = function (string $filter) use ($idx, $PAIRS): array {
    $n = 0; $list = []; $u = array_fill_keys(array_keys($PAIRS), [0, 0]);
    foreach ($idx['scenes'] as $s) {
        if ($s['hard'] !== '' || ($filter === 'standard' && $s['taste'])) { $n++; $list[] = $s['id']; continue; }
        if ($s['actors'] !== 2 || $s['transition']) { continue; }
        foreach ($PAIRS as $p => $sx) { if (lrgSceneSexOk($s, $sx)) { $u[$p][0]++; $u[$p][1] += $s['tier'] === 'sexual' ? 1 : 0; } }
    }
    return [$n, $u, $list];
};
[$nOpen, $uOpen, $lOpen] = $usable('open');
[$nStd, $uStd, $lStd] = $usable('standard');
foreach (['open' => [$nOpen, $uOpen], 'standard' => [$nStd, $uStd]] as $f => [$n, $u]) {
    printf("  %-8s excludes %2d of %d; usable two-actor scenes (sexual): %s\n", $f, $n, count($idx['scenes']), implode('  ', array_map(fn($p) => "$p {$u[$p][0]} ({$u[$p][1]})", array_keys($u))));
}
say("  'open' excludes (hard list, code): " . ($lOpen ? implode(' ', $lOpen) : 'nothing - no installed scene is forced / creature / necro / gore / non-adult'));
$groups = [];
$c = lrgConfig()['scene_index'];
foreach ($idx['scenes'] as $s) {
    if (!$s['taste']) { continue; }
    $hay = strtolower($s['id'] . ' ' . implode(' ', $s['tags']) . ' ' . implode(' ', $s['actions']));
    $g = 'other';
    foreach (['vampire feeding' => 'vampire|drained|devour', 'foot play / tickling' => 'foot|feet|tickl', 'spanking' => 'spank', 'face sitting' => 'facesit|facerid', 'rough oral' => 'facefuck|mouthfuck', 'hair pulling' => 'pullinghair', 'tail play' => 'tail'] as $name => $re) {
        if (preg_match("/$re/", $hay)) { $g = $name; break; }
    }
    $groups[$g][] = $s['id'] . '[' . $s['tier'] . ($s['transition'] ? ',transition' : '') . (!empty($s['special']) ? ',live-only' : '') . ']';
}
say("  'standard' additionally excludes (taste lists, config):");
foreach ($groups as $g => $l) { say("    $g (" . count($l) . '): ' . wordwrap(implode(' ', $l), 170, "\n      ")); }
check('no installed scene trips the hard list (it guards future packs)', $nOpen === 0, implode(' ', $lOpen));
check('standard excludes the 0.1 taste set (38 scenes), open none of them', $nStd === 38 && $nOpen === 0, "$nStd / $nOpen");
check('open gives every pair more usable scenes than standard', $uOpen['MF'][0] > $uStd['MF'][0] && $uOpen['FF'][0] >= $uStd['FF'][0] && $uOpen['MM'][0] >= $uStd['MM'][0]);
// hard list unit checks on synthetic scenes (nothing like this is installed)
$syn = fn(array $o) => $o + ['id' => 'X', 'name' => 'X', 'tags' => [], 'actor_tags' => [], 'actions' => [], 'types' => [], 'tier' => 'sexual'];
check('hard: tag "aggressive"', lrgSceneHardExcluded($syn(['tags' => ['aggressive']])) !== '');
check('hard: id word "Forced" (CamelCase) and "noncon" inside a word', lrgSceneHardExcluded($syn(['id' => 'PackForcedDoggyMF'])) !== '' && lrgSceneHardExcluded($syn(['id' => 'pack_nonconsensual_01'])) !== '');
check('hard: a non-humanoid actor type', lrgSceneHardExcluded($syn(['types' => ['wolf']])) !== '');
check('hard: a sleeping participant in a sexual scene, but not in a gentle one', lrgSceneHardExcluded($syn(['actions' => ['sleeping', 'vaginalsex']])) !== '' && lrgSceneHardExcluded($syn(['actions' => ['sleeping'], 'tier' => 'affection'])) === '');
check('hard: ordinary scenes pass ("Drape", "Grapes" are not hard words)', lrgSceneHardExcluded($syn(['id' => 'OARE_DrapedOverGrapesMF', 'tags' => ['missionary']])) === '');
check('config cannot switch the hard list off (it is not read from the config)', !preg_match('/lrgConfig\(\)[^;]*hard/i', (string) file_get_contents(LRG_DIR . '/lib/lrg_scene_index.php')));
// switching the level needs no rebuild
$GLOBALS['LRG_TEST_CONTENT_FILTER'] = 'grounded'; lrgIndexForget();
$nEx = count(array_filter(lrgSceneIndex()['scenes'], fn($s) => $s['excluded']));
$foot = lrgFindSceneByText('footjob', 'OARE_StandingEmbraceKiss', $PAIRS['MF'], '', 4);
check('"grounded" / "standard": taste scenes are excluded at once, without a rebuild, and cannot be found by name', $nEx === 38 && $foot === null, "$nEx");
$GLOBALS['LRG_TEST_CONTENT_FILTER'] = 'nonsense-value'; lrgIndexForget();
check('an unknown level reads as the stricter "standard"', lrgContentFilter() === 'standard');
unset($GLOBALS['LRG_TEST_CONTENT_FILTER']); lrgIndexForget(); $idx = lrgSceneIndex();
check('default level is "open": nothing excluded, a niche scene is reachable when asked for by name', lrgContentFilter() === 'open' && !array_filter($idx['scenes'], fn($s) => $s['excluded'])
    && ($f = lrgFindSceneByText('footjob', 'OARE_StandingEmbraceKiss', $PAIRS['MF'], '', 4)) && in_array('footjob', lrgScene($f['id'])['actions'], true));
$liveOnly = array_filter($idx['scenes'], fn($s) => !empty($s['special']));
say('  live-only scenes (need a vampire / tail / water, which the snapshot cannot prove): ' . count($liveOnly));
$vamp = 'OStim2PStandingVampireBiteFemaleMF';
if (lrgScene($vamp)) {
    check('a vampire scene is never picked from the index alone ...', lrgFindSceneByText('bite', 'OStim2PStandingKissMF', $PAIRS['MF'], '', 4) === null);
    $hit = lrgFindSceneByText('bite', 'OStim2PStandingKissMF', $PAIRS['MF'], '', 4, [strtolower($vamp)]);
    check('... only when OStim itself lists it as reachable', $hit !== null && $hit['id'] === $vamp);
}

echo "  tier sanity:\n";
$gentle = ['kissing', 'kissingneck', 'kissingcheek', 'kissinghand', 'hug', 'cuddling', 'sleeping', 'pattinghead', 'strokinghead', 'bathing',
    'holdinghand', 'holdingarm', 'holdingbody', 'holdingchin', 'holdinghead', 'holdinghip', 'audiblebreathing', 'sleepingsound', 'spectating'];
$bad = [];
foreach ($idx['scenes'] as $s) { if ($s['tier'] === 'sexual' && $s['actions'] && !array_diff($s['actions'], $gentle)) { $bad[] = $s['id']; } }
check('no scene with only gentle actions is tiered sexual', !$bad, implode(' ', $bad));
$bad = [];
foreach ($idx['scenes'] as $s) { if ($s['actors'] > 1 && LRG_TIERS[$s['tier']] < LRG_TIERS['sensual'] && array_intersect($s['actor_tags'], ['allfours', 'bendover', 'spreadlegs'])) { $bad[] = $s['id']; } }
check('no all-fours / bent-over / spread-legs scene passes as gentle, with or without a harmless action', !$bad, implode(' ', $bad));
$bad = [];
foreach ($idx['scenes'] as $s) { foreach ($s['actions'] as $a) { if (in_array('sexual', $idx['actions'][$a]['tags'] ?? [], true) && $s['tier'] !== 'sexual') { $bad[] = $s['id']; } } }
check('every scene with a sexual-tagged action is tiered sexual', !$bad, implode(' ', $bad));
foreach (['OStim2PStandingKissMF' => 'kissing', 'OARE_SpooningSleeping' => 'affection', 'OARE_GentleEmbrace' => 'affection', 'OStim2PDoggyIdleMF' => 'sensual', 'OStim2PStandingSpankIdleMF' => 'sensual'] as $id => $want) {
    if ($s = lrgScene($id)) { check("$id is $want (got {$s['tier']})", $s['tier'] === $want); }
}

// ------------------------------------------------------------------ D. pairs, start scene, options
echo "D. pairs, start scenes, options\n";
foreach ($PAIRS + ['FM (game order psex=1,sex=0)' => [1, 0]] as $label => $sexes) {
    $n = 0; $sx = 0;
    foreach ($idx['scenes'] as $s) { if ($s['actors'] === 2 && !$s['excluded'] && !$s['transition'] && lrgSceneSexOk($s, $sexes)) { $n++; $sx += $s['tier'] === 'sexual' ? 1 : 0; } }
    say("  $label: $n usable two-actor scenes ($sx sexual)");
}
foreach (['OStim2PMissionaryMF', 'OARE_StandingSexForwardBend'] as $id) {
    if ($s = lrgScene($id)) { check("$id usable by MF, not by FF or MM", lrgSceneSexOk($s, ['female', 'male']) && !lrgSceneSexOk($s, ['female', 'female']) && !lrgSceneSexOk($s, ['male', 'male'])); }
}
$bj = array_filter($idx['scenes'], fn($s) => in_array('boobjob', $s['actions'], true) && !$s['transition']);
check('a scene that needs breasts (OStim actor property "breast" = female only) is never offered to two men', $bj && !array_filter($bj, fn($s) => lrgSceneSexOk($s, ['male', 'male'])));
check('null sexes = no filtering (backward compatible)', lrgSceneSexOk(lrgScene('OStim2PMissionaryMF') ?? ['actors' => 2], null));
foreach ($PAIRS as $label => $sexes) {
    $start = lrgPickStartScene($sexes);
    say("  == $label: start scene '$start'" . ($start !== '' ? ' = ' . lrgDescribeScene(lrgScene($start)) : ''));
    check("$label has a gentle standing start scene without furniture", $start !== '' && in_array(lrgScene($start)['tier'], ['kissing', 'affection'], true) && in_array(lrgScene($start)['furniture'], ['', 'none'], true));
    if ($start === '') { continue; }
    foreach ([2 => 'gentle ceiling', 3 => 'sensual ceiling', 4 => 'sexual ceiling'] as $maxTier => $tl) {
        $opts = lrgSceneOptions($start, [], $maxTier, 8, $sexes, '');
        say("    options from the start, $tl:");
        foreach ($opts as $k => $o) { say(sprintf("      %s  %-66s hops=%d id=%s", $k, $o['label'], $o['hops'], $o['id'])); }
        $wrong = array_filter($opts, fn($o) => !lrgSceneSexOk(lrgScene($o['id']), $sexes) || !in_array(lrgScene($o['id'])['furniture'], ['', 'none'], true) || LRG_TIERS[$o['tier']] > $maxTier);
        check("$label options ($tl): some, all fit the pair, none above the ceiling, none on furniture", count($opts) > 0 && !$wrong);
        if ($maxTier > LRG_TIERS[lrgScene($start)['tier']]) {
            $firstTier = LRG_TIERS[array_values($opts)[0]['tier']];
            $hasStep = (bool) array_filter(lrgSceneWalk($start, [], $maxTier, 3, $sexes, ''), fn($w) => LRG_TIERS[$w['scene']['tier']] === $maxTier);
            check("$label options ($tl): the next step is on the list and listed first" . ($hasStep ? '' : ' (no such scene within reach - skipped)'), !$hasStep || $firstTier === $maxTier);
        }
    }
    $walk = lrgSceneWalk($start, [], LRG_TIERS['kissing'], 6, $sexes, '');
    $cuddles = array_filter($walk, fn($w) => in_array('cuddling', $w['scene']['tags'], true));
    $back = 0;
    foreach (array_slice(array_keys($cuddles), 0, 40) as $ck) { $back += isset(lrgSceneWalk($ck, [], LRG_TIERS['kissing'], 6, $sexes, '')[strtolower($start)]) ? 1 : 0; }
    say(sprintf("    gentle graph: %d scenes reachable within the gentle tiers, %d cuddle scenes, %d of them lead back to the start", count($walk), count($cuddles), $back));
    check("$label gentle graph reaches cuddle scenes and returns", count($cuddles) > 0 && $back > 0);
}
$start = lrgPickStartScene($PAIRS['MF']);
$all = lrgSceneOptions($start, [], 4, 50, $PAIRS['MF'], '');
$hop1 = array_values(array_filter($all, fn($o) => $o['hops'] === 1));
if (count($hop1) >= 2) {
    $o = lrgSceneOptions($start, [strtoupper($hop1[0]['id'])], 4, 50, $PAIRS['MF'], '');
    check('short live list is authoritative for hop 1 (case-insensitive)', count(array_filter($o, fn($x) => $x['hops'] === 1)) === 1);
    $o = lrgSceneOptions($start, array_map(fn($i) => "Filler$i", range(1, 24)), 4, 50, $PAIRS['MF'], '');
    check('a full (truncated) live list does not wipe the options', count($o) > 0);
}

// ------------------------------------------------------------------ E. furniture
echo "E. furniture (OStim supertypes: a thread on a double bed plays doublebed, bed and no-furniture scenes)\n";
say("  chain(doublebed) = " . implode(' -> ', lrgFurnitureChain('doublebed')) . ";  chain(bench) = " . implode(' -> ', lrgFurnitureChain('bench')) . ";  chain('') = " . implode(' -> ', lrgFurnitureChain('')));
$bed = lrgScene('OStimDoubleBedLeft2PBothSittingMF');
if ($bed) {
    check('bed scene refused without furniture', !lrgSceneFurnitureOk($bed, '') && !lrgSceneFurnitureOk($bed, 'none'));
    check('bed scene allowed on a double bed, refused on a chair and on a single bed', lrgSceneFurnitureOk($bed, 'doublebed') && !lrgSceneFurnitureOk($bed, 'chair') && !lrgSceneFurnitureOk($bed, 'singlebed'));
    check('floor scene allowed on any bed, refused on a chair', lrgSceneFurnitureOk(lrgScene('OARE_GentleEmbrace'), 'doublebed') && lrgSceneFurnitureOk(lrgScene('OARE_GentleEmbrace'), 'bedroll') && !lrgSceneFurnitureOk(lrgScene('OARE_GentleEmbrace'), 'chair'));
    check('furniture null = no filtering (backward compatible)', lrgSceneFurnitureOk($bed, null));
}
check('a synthetic scene for the supertype "bed" is valid on doublebed / singlebed / bedroll, not on a chair or the floor',
    lrgSceneFurnitureOk(['furniture' => 'bed'], 'doublebed') && lrgSceneFurnitureOk(['furniture' => 'bed'], 'singlebed') && lrgSceneFurnitureOk(['furniture' => 'bed'], 'bedroll')
    && !lrgSceneFurnitureOk(['furniture' => 'bed'], 'chair') && !lrgSceneFurnitureOk(['furniture' => 'bed'], ''));
$d = fn(string $id) => $id === '' ? "''" : $id . ' = ' . lrgDescribeScene(lrgScene($id));
foreach ($PAIRS as $pl => $sx) {
    foreach ([[], ['doublebed'], ['singlebed'], ['bedroll'], ['chair', 'table', 'doublebed'], ['chair', 'table']] as $near) {
        $p = lrgPickStart($sx, $near);
        say(sprintf("  %s near=[%-21s] -> furn=%-10s fscene=%s", $pl, implode(',', $near), $p['furn'] === '' ? "''" : $p['furn'], $d($p['fscene'])));
        $hasBed = (bool) array_filter($near, 'lrgIsBedFamily');
        $ok = $p['scene'] === lrgPickStartScene($sx) && ($hasBed ? ($p['furn'] !== '' && lrgIsBedFamily($p['furn']) && $p['fscene'] !== '') : ($p['furn'] === '' && $p['fscene'] === ''));
        if ($ok && $p['fscene'] !== '') {
            $fs = lrgScene($p['fscene']);
            $ok = in_array($fs['tier'], ['kissing', 'affection'], true) && lrgSceneFurnitureOk($fs, $p['furn']) && lrgSceneSexOk($fs, $sx) && $fs['actors'] === 2 && !$fs['transition']
                && !(in_array($fs['furniture'], ['', 'none'], true) && in_array('standing', $fs['actor_tags'], true));
        }
        check("$pl near [" . implode(',', $near) . ']: ' . ($hasBed ? 'starts on the bed with a gentle sitting / lying scene, standing fallback kept' : 'default "bed" preference: no bed in reach = standing start'), $ok);
    }
}
say("  the ladder on a double bed:");
foreach ($PAIRS as $pl => $sx) {
    $p = lrgPickStart($sx, ['doublebed']);
    say("    $pl gentle start: " . $d($p['fscene']));
    foreach ([2 => 'gentle', 3 => 'sensual', 4 => 'sexual'] as $ceil => $cl) {
        $o = lrgSceneOptions($p['fscene'], [], $ceil, 8, $sx, 'doublebed');
        say("      ceiling $cl:");
        foreach ($o as $k => $x) { say(sprintf("        %s %-72s hops=%d %s", $k, $x['label'], $x['hops'], $x['id'])); }
        $bad = array_filter($o, fn($x) => !lrgSceneFurnitureOk(lrgScene($x['id']), 'doublebed') || LRG_TIERS[$x['tier']] > $ceil || !lrgSceneSexOk(lrgScene($x['id']), $sx));
        check("$pl bed ladder, ceiling $cl: options exist, all playable on the double bed, none above the ceiling", count($o) > 0 && !$bad);
    }
    $walkAll = lrgSceneWalk($p['fscene'], [], 4, 5, $sx, 'doublebed');
    check("$pl bed ladder reaches sensual and sexual scenes", (bool) array_filter($walkAll, fn($w) => $w['scene']['tier'] === 'sensual') && (bool) array_filter($walkAll, fn($w) => $w['scene']['tier'] === 'sexual'));
}
say("  moving to furniture in a running scene (lrgFurnitureOptions):");
$near = ['doublebed', 'singlebed', 'chair', 'bench', 'table', 'shelf', 'wall', 'cookingpot', 'bedroll'];
foreach ([['MF', 'OARE_StandingEmbraceKiss', 2, ''], ['MF', 'OARE_SpooningIdle', 2, ''], ['MF', 'OARE_Missionary', 4, ''], ['MF', 'OStimDoubleBedLeft2PCowgirlMF', 4, 'doublebed'], ['FF', 'OARE_StandingEmbraceKiss', 2, ''], ['MM', 'OARE_SimpleStandingHandjob', 4, '']] as [$pl, $cur, $ceil, $tf]) {
    if (!lrgScene($cur)) { continue; }
    $o = lrgFurnitureOptions($near, $cur, $PAIRS[$pl], $ceil, $tf);
    say("    $pl in $cur, ceiling $ceil, thread furniture '" . $tf . "':");
    foreach ($o as $type => $x) { say(sprintf("      %-10s %-16s words=%-30s -> %s [%s]", $type, $x['label'], implode('/', $x['words']), $x['scene'], $x['tier'])); }
    $bad = array_filter($o, fn($x) => $x['scene'] === '' || LRG_TIERS[$x['tier']] > $ceil || !lrgSceneFurnitureOk(lrgScene($x['scene']), $x['furn']) || !lrgSceneSexOk(lrgScene($x['scene']), $PAIRS[$pl]) || !$x['words'] || $x['label'] === '');
    $labels = array_column($o, 'label');
    check("$pl from $cur: every option has a scene id it can play there, under the ceiling, one option per spoken label", count($o) > 0 && !$bad && count($labels) === count(array_unique($labels)));
    if ($tf !== '') { check("... never the thread's own furniture or another bed", !array_filter(array_keys($o), 'lrgIsBedFamily')); }
}
$o = lrgFurnitureOptions(['doublebed'], 'OARE_SpooningIdle', $PAIRS['MF'], 2, '');
check('a lying floor scene moves to the bed as itself (same scene id)', ($o['doublebed']['scene'] ?? '') === 'OARE_SpooningIdle');
$o = lrgFurnitureOptions(['doublebed'], 'OARE_StandingEmbraceKiss', $PAIRS['MF'], 2, '');
check('a standing scene does not: nobody stands on the mattress', isset($o['doublebed']) && $o['doublebed']['scene'] !== 'OARE_StandingEmbraceKiss' && !in_array('standing', lrgScene($o['doublebed']['scene'])['actor_tags'], true));
check('no furniture in reach / unknown type = no option', lrgFurnitureOptions([], 'OARE_SpooningIdle', $PAIRS['MF'], 4, '') === [] && lrgFurnitureOptions(['nosuchtype', 'none', ''], 'OARE_SpooningIdle', $PAIRS['MF'], 4, '') === []);
$r = lrgFindSceneByText('cowgirl', 'OStimDoubleBedLeft2PBothSittingMF', $PAIRS['MF'], 'doublebed', 4);
check('text search on a double bed returns a scene that plays there', $r && empty($r['too_soon']) && lrgSceneFurnitureOk(lrgScene($r['id']), 'doublebed'));
$r = lrgFindSceneByText('sit on the bed', $start, $PAIRS['MF'], '', 4);
check('no furniture in the thread = no furniture scene', $r === null || !empty($r['too_soon']) || in_array(lrgScene($r['id'])['furniture'], ['', 'none'], true));
$r = lrgFindSceneByText('cowgirl', 'OStimChair2PSittingOnLapMF', $PAIRS['MF'], 'chair', 4);
check('on a chair only chair scenes are found (a chair has no "none" supertype)', $r === null || !empty($r['too_soon']) || lrgScene($r['id'])['furniture'] === 'chair');

// ------------------------------------------------------------------ F. positions by name
echo "F. positions by name (plain requests -> scene, per pair and ceiling; K = kissing, S = sensual, X = sexual)\n";
$queries = ['missionary', 'cowgirl', 'ride me', 'get on top', 'reverse cowgirl', 'from behind', 'doggy style', 'on all fours', 'bend over', 'lie on your stomach', 'spooning', 'lie on your side',
    'standing up', 'sit on my lap', 'sit down', 'lie down with me', 'on your back', 'on your knees', 'pick me up', 'carry me', 'oral', 'use your mouth', 'go down on me', 'blowjob', 'lick me',
    'handjob', 'use your hand', 'finger me', 'touch me', 'grope', 'play with my breasts', 'grab my ass', 'between your breasts', 'thighs', 'sixty nine', 'anal', 'sex', 'make love to me',
    'kiss me', 'make out', 'kiss my neck', 'cuddle', 'hold me', 'hug me', 'hold my hand', 'rest your head on my lap', 'sleep', 'something else', 'foreplay', 'against the wall', 'dance with me', 'hanging from the chandelier'];
$t0 = microtime(true); $n = 0; $res = [];
foreach ($PAIRS as $pl => $sexes) {
    $from = lrgPickStartScene($sexes);
    say("  == $pl, from $from, no furniture");
    foreach ($queries as $q) {
        $cells = [];
        foreach ([2 => 'K', 3 => 'S', 4 => 'X'] as $ceil => $cl) {
            $x = lrgFindSceneByText($q, $from, $sexes, '', $ceil); $n++;
            $res[$pl][$q][$ceil] = $x;
            $cells[] = $cl . ': ' . str_pad($x === null ? '-' : (!empty($x['too_soon']) ? 'too soon (' . $x['tier'] . ')' : $x['id'] . ' [' . $x['tier'] . ']'), 44);
            if ($x !== null && empty($x['too_soon'])) {
                $sc = lrgScene($x['id']);
                if (LRG_TIERS[$sc['tier']] > $ceil || !lrgSceneSexOk($sc, $sexes) || $sc['excluded'] || $sc['transition'] || !in_array($sc['furniture'], ['', 'none'], true) || $sc['actors'] !== 2 || !empty($sc['special'])) {
                    check("$pl '$q' ceiling $ceil returns a legal scene", false, $x['id']);
                }
            }
        }
        say(sprintf("    %-28s %s", $q, implode(' ', $cells)));
    }
}
printf("  %d searches: %.0f ms (%.2f ms each)\n", $n, (microtime(true) - $t0) * 1000, (microtime(true) - $t0) * 1000 / $n);
check('every result in the matrix is legal for its pair, ceiling and (no) furniture', true);
$is = fn($x, callable $f) => $x !== null && empty($x['too_soon']) && $f(lrgScene($x['id']));
$soon = fn($x) => $x !== null && !empty($x['too_soon']);
$R = $res['MF'];
check('MF X: "missionary" -> a sexual scene tagged missionary', $is($R['missionary'][4], fn($s) => $s['tier'] === 'sexual' && in_array('missionary', $s['tags'], true)));
check('MF X: "cowgirl" / "ride me" / "get on top" -> tagged cowgirl', $is($R['cowgirl'][4], fn($s) => in_array('cowgirl', $s['tags'], true)) && $is($R['ride me'][4], fn($s) => in_array('cowgirl', $s['tags'], true)) && $is($R['get on top'][4], fn($s) => in_array('cowgirl', $s['tags'], true)));
check('MF X: "reverse cowgirl" -> tagged reversecowgirl', $is($R['reverse cowgirl'][4], fn($s) => in_array('reversecowgirl', $s['tags'], true)));
check('MF X: "from behind" / "doggy style" / "on all fours" -> a sexual scene from behind', $is($R['from behind'][4], fn($s) => $s['tier'] === 'sexual' && (in_array('doggystyle', $s['tags'], true) || preg_match('/behind|rear/i', $s['id'])))
    && $is($R['doggy style'][4], fn($s) => in_array('doggystyle', $s['tags'], true)) && $is($R['on all fours'][4], fn($s) => $s['tier'] === 'sexual' && (in_array('allfours', $s['actor_tags'], true) || in_array('doggystyle', $s['tags'], true))));
check('MF X: "bend over" -> a bent-over scene, not a niche one', $is($R['bend over'][4], fn($s) => (in_array('bendover', $s['actor_tags'], true) || stripos($s['id'], 'bend') !== false) && empty($s['taste'])));
check('MF X: "lie on your stomach" -> prone / lying on the front', $is($R['lie on your stomach'][4], fn($s) => in_array('prone', $s['tags'], true) || in_array('lyingfront', $s['actor_tags'], true)));
check('MF: "spooning" -> a spooning scene at every ceiling', $is($R['spooning'][2], fn($s) => stripos($s['id'], 'spooning') !== false) && $is($R['spooning'][4], fn($s) => stripos($s['id'], 'spooning') !== false));
check('MF X: "oral" / "use your mouth" / "go down on me" -> an oral act', !array_filter(['oral', 'use your mouth', 'go down on me'], fn($q) => !$is($R[$q][4], fn($s) => (bool) array_intersect($s['actions'], ['blowjob', 'vulvaleating', 'vulvallicking', 'penilelicking']))));
check('MF X: "handjob" / "use your hand" -> handjob or fingering; "finger me" -> fingering', $is($R['handjob'][4], fn($s) => in_array('handjob', $s['actions'], true)) && $is($R['use your hand'][4], fn($s) => (bool) array_intersect($s['actions'], ['handjob', 'vaginalfingering']))
    && $is($R['finger me'][4], fn($s) => in_array('vaginalfingering', $s['actions'], true)));
check('MF X: "between your breasts" -> boobjob; "thighs" -> thighjob', $is($R['between your breasts'][4], fn($s) => in_array('boobjob', $s['actions'], true)) && $is($R['thighs'][4], fn($s) => in_array('thighjob', $s['actions'], true)));
check('MF X: "sex" / "make love to me" -> intercourse', $is($R['sex'][4], fn($s) => in_array('vaginalsex', $s['actions'], true)) && $is($R['make love to me'][4], fn($s) => in_array('vaginalsex', $s['actions'], true)));
check('MF S: "touch me" / "grope" / "play with my breasts" / "grab my ass" -> groping (sensual)', !array_filter(['touch me', 'grope', 'play with my breasts', 'grab my ass'], fn($q) => !$is($R[$q][3], fn($s) => $s['tier'] === 'sensual' && (bool) array_intersect($s['actions'], ['gropingbreast', 'gropingbutt']))));
check('MF S: "foreplay" -> a sensual scene; K: too soon', $is($R['foreplay'][3], fn($s) => $s['tier'] === 'sensual') && $soon($R['foreplay'][2]));
// R1: naming what they are already doing must not move them, so "kiss me" is asked from a scene that is NOT a kiss
$kissFrom = 'OStim2PMissionaryMF';
$kiss = fn(string $q, int $ceil) => lrgFindSceneByText($q, $kissFrom, $PAIRS['MF'], '', $ceil);
check('MF: "kiss me" / "make out" -> a kissing-tier scene even under a sexual ceiling', $is($kiss('kiss me', 4), fn($s) => $s['tier'] === 'kissing') && $is($kiss('make out', 4), fn($s) => $s['tier'] === 'kissing') && $is($R['kiss my neck'][2], fn($s) => in_array('kissingneck', $s['actions'], true)));
check('MF R1: naming the position they are already in resolves to nothing at all', $R['kiss me'][4] === null && lrgFindSceneByText('missionary', 'OStim2PMissionaryMF', $PAIRS['MF'], '', 4) === null
    && $is(lrgFindSceneByText('cowgirl', 'OStim2PMissionaryMF', $PAIRS['MF'], '', 4), fn($s) => $s['tier'] === 'sexual'));
check('MF: "cuddle" / "hold me" / "hug me" -> affection tier', !array_filter(['cuddle', 'hold me', 'hug me'], fn($q) => !$is($R[$q][4], fn($s) => $s['tier'] === 'affection')));
check('MF: "hold my hand" -> holding hands; "carry me" / "pick me up" -> a carry scene; "sleep" -> sleeping', $is($R['hold my hand'][2], fn($s) => in_array('holdinghand', $s['actions'], true))
    && $is($R['carry me'][2], fn($s) => stripos($s['id'], 'carry') !== false) && $is($R['pick me up'][2], fn($s) => stripos($s['id'], 'carry') !== false) && $is($R['sleep'][2], fn($s) => in_array('sleeping', $s['actions'], true)));
check('MF: "rest your head on my lap" -> lap pillow', $is($R['rest your head on my lap'][2], fn($s) => stripos($s['id'], 'lappillow') !== false));
check('not installed / not a position -> null: "sixty nine", "anal", "against the wall" (no wall in the thread), "dance with me", nonsense',
    !array_filter(['sixty nine', 'anal', 'against the wall', 'dance with me', 'hanging from the chandelier'], fn($q) => $R[$q][4] !== null));
echo "  too soon instead of a wrong scene:\n";
$acts = ['oral', 'use your mouth', 'go down on me', 'blowjob', 'lick me', 'handjob', 'use your hand', 'finger me', 'between your breasts', 'thighs', 'sex', 'make love to me'];
$bad = [];
foreach ($PAIRS as $pl => $_) { foreach ($acts as $q) { foreach ([2, 3] as $ceil) { $x = $res[$pl][$q][$ceil]; if ($x !== null && empty($x['too_soon'])) { $bad[] = "$pl/$q/$ceil=" . $x['id']; } } } }
check('an act word under a kissing or sensual ceiling is "too soon" (or unknown for the pair) for MF, FF and MM - never a lower scene that shares a word', !$bad, implode(' ', $bad));
check('MF K: missionary, cowgirl, doggy style, reverse cowgirl, grope, touch me -> too soon', !array_filter(['missionary', 'cowgirl', 'doggy style', 'reverse cowgirl', 'grope', 'touch me'], fn($q) => !$soon($R[$q][2])));
check('MF S: missionary / cowgirl / doggy style -> the staging idle of that position (one step), X -> the act', !array_filter(['missionary', 'cowgirl', 'doggy style'], fn($q) => !$is($R[$q][3], fn($s) => $s['tier'] === 'sensual') || !$is($R[$q][4], fn($s) => $s['tier'] === 'sexual')));
$x = lrgFindSceneByText('titjob', $start, $PAIRS['MF'], '', 2);
check('"titjob" under a gentle ceiling: too soon, not the after-climax idle that carries the word', $soon($x));
$x = lrgFindSceneByText('boobjob', $start, $PAIRS['MF'], '', 4);
check('"boobjob": the act itself, not a climax scene', $is($x, fn($s) => in_array('boobjob', $s['actions'], true) && !in_array('climaxing', $s['actor_tags'], true) && stripos($s['id'], 'climax') === false));
echo "  other pairs:\n";
$F = $res['FF']; $M = $res['MM'];
check('FF: positions that need a man do not exist for the pair -> null (missionary, cowgirl, doggy style, blowjob, handjob, between your breasts)', !array_filter(['missionary', 'cowgirl', 'doggy style', 'blowjob', 'handjob', 'between your breasts'], fn($q) => $F[$q][4] !== null));
check('FF X: "oral" / "lick me" / "go down on me" -> cunnilingus; "finger me" / "use your hand" -> fingering; "sex" -> some sexual scene the pair can be in',
    !array_filter(['oral', 'lick me', 'go down on me'], fn($q) => !$is($F[$q][4], fn($s) => (bool) array_intersect($s['actions'], ['vulvaleating', 'vulvallicking'])))
    && $is($F['finger me'][4], fn($s) => in_array('vaginalfingering', $s['actions'], true)) && $is($F['use your hand'][4], fn($s) => in_array('vaginalfingering', $s['actions'], true)) && $is($F['sex'][4], fn($s) => $s['tier'] === 'sexual'));
check('MM X: "oral" / "blowjob" -> blowjob; "handjob" -> handjob; "finger me" -> null; "sex" -> some sexual scene the pair can be in',
    $is($M['oral'][4], fn($s) => (bool) array_intersect($s['actions'], ['blowjob', 'penilelicking'])) && $is($M['blowjob'][4], fn($s) => in_array('blowjob', $s['actions'], true)) && $is($M['handjob'][4], fn($s) => in_array('handjob', $s['actions'], true))
    && $M['finger me'][4] === null && $is($M['sex'][4], fn($s) => $s['tier'] === 'sexual'));
check('MM: "play with my breasts" never resolves to a breast scene (needs a female body)', !$is($M['play with my breasts'][4], fn($s) => in_array('gropingbreast', $s['actions'], true) || in_array('boobjob', $s['actions'], true)));
$named = [];
foreach (['FF' => 'male', 'MM' => 'female'] as $pl => $word) {
    foreach ($queries as $q) { foreach ([2, 3, 4] as $ceil) { $x = $res[$pl][$q][$ceil]; if ($x && empty($x['too_soon']) && in_array($word, lrgIdWords($x['id'] . ' ' . lrgScene($x['id'])['name']), true)) { $named["$pl/$q"] = $x['id']; } } }
}
say('  scenes named for the other sex that still won (only when nothing else matches): ' . ($named ? json_encode($named) : 'none'));
check('gentle requests of FF / MM pairs do not land on a scene named for the other sex', !array_filter(array_keys($named), fn($k) => preg_match('~/(kiss me|make out|cuddle|hold me|hug me|lie down with me|sit down|standing up|spooning)$~', $k)));
echo "  wording:\n";
$x = lrgFindSceneByText('kiss me under the stars tonight', $kissFrom, $PAIRS['MF'], '', 4);
check('words no scene knows are ignored ("kiss me under the stars tonight" -> kissing)', $is($x, fn($s) => $s['tier'] === 'kissing'));
check('"<act> standing" for a pair without that act is null, not just any standing scene', lrgFindSceneByText('blowjob standing', $start, $PAIRS['FF'], '', 4) === null);
$x = lrgFindSceneByText('standing blowjob', $start, $PAIRS['MF'], '', 4);
check('two words narrow the result ("standing blowjob" -> standing + blowjob)', $is($x, fn($s) => in_array('blowjob', $s['actions'], true) && in_array('standing', $s['actor_tags'], true)));
$walk = lrgSceneWalk('OStim2PMissionaryMF', [], 4, 3, $PAIRS['MF'], '');
$x = lrgFindSceneByText('something else', 'OStim2PMissionaryMF', $PAIRS['MF'], '', 4, array_keys($walk));
check('"something else" -> a scene that is routable right now', $is($x, fn($s) => isset($walk[strtolower($s['id'])])));
$cow = array_values(array_filter($idx['scenes'], fn($s) => in_array('cowgirl', $s['tags'], true) && $s['tier'] === 'sexual' && !$s['transition'] && lrgSceneSexOk($s, $PAIRS['MF']) && in_array($s['furniture'], ['', 'none'], true)));
if (count($cow) >= 2) {
    $pick = strtolower(end($cow)['id']);
    $x = lrgFindSceneByText('cowgirl', $start, $PAIRS['MF'], '', 4, [$pick]);
    $y = lrgFindSceneByText('cowgirl', $start, $PAIRS['MF'], '', 4);
    check('$prefer (routable now) wins among equal matches', $x && ($x['id'] === end($cow)['id'] || $x['id'] === $y['id']) && $x['score'] >= $y['score']);
}
check('empty / stop-word-only text finds nothing', lrgFindSceneByText('', $start, $PAIRS['MF'], '', 4) === null && lrgFindSceneByText('the', $start, $PAIRS['MF'], '', 4) === null && lrgFindSceneByText('  ...  ', $start, $PAIRS['MF'], '', 4) === null);
check('unknown current scene still searches (two actors assumed)', lrgFindSceneByText('missionary', 'NoSuchScene', $PAIRS['MF'], '', 4) !== null);
$gentleWords = lrgPositionWords($start, $PAIRS['MF'], '', LRG_TIERS['kissing']);
$allWords = lrgPositionWords($start, $PAIRS['MF'], '', 4);
say("  example words for the scene notes, gentle ceiling: " . implode(', ', $gentleWords));
say("  example words for the scene notes, no ceiling:     " . implode(', ', $allWords));
say("  example words, FF no ceiling: " . implode(', ', lrgPositionWords($start, $PAIRS['FF'], '', 4)) . ";  MM: " . implode(', ', lrgPositionWords($start, $PAIRS['MM'], '', 4)));
check('example words respect the ceiling and the pair', $gentleWords && !in_array('missionary', $gentleWords, true) && in_array('missionary', $allWords, true) && count($allWords) <= 10
    && !in_array('missionary', lrgPositionWords($start, $PAIRS['FF'], '', 4), true));

// ------------------------------------------------------------------ G. wind-down
echo "G. wind-down (lrgPickAfterglowScene) from the sexual scenes of each pair, with and without furniture\n";
$indeg = [];
foreach ($idx['scenes'] as $s) { foreach ($s['to'] as $to) { $indeg[strtolower($to)] = ($indeg[strtolower($to)] ?? 0) + 1; } }
foreach ($PAIRS as $pl => $sx) {
    foreach (['' => 'no furniture', 'doublebed' => 'double bed', 'singlebed' => 'single bed', 'bedroll' => 'bedroll', 'chair' => 'chair', 'bench' => 'bench', 'table' => 'table', 'shelf' => 'shelf', 'cookingpot' => 'cooking pot'] as $furn => $fl) {
        $hubs = [];
        foreach ($idx['scenes'] as $k => $s) {
            if ($s['tier'] !== 'sexual' || $s['excluded'] || $s['transition'] || $s['actors'] !== 2 || !empty($s['special']) || !lrgSceneSexOk($s, $sx) || !lrgSceneFurnitureOk($s, $furn)) { continue; }
            $hubs[$k] = $indeg[$k] ?? 0;
        }
        if (!$hubs) { say(sprintf("  %s %-12s no sexual scene for this pair here", $pl, $fl)); continue; }
        arsort($hubs);
        $routed = 0; $warp = 0; $none = []; $hopsum = 0; $badPick = []; $ex = [];
        foreach (array_keys($hubs) as $i => $k) {
            $id = $idx['scenes'][$k]['id'];
            $a = lrgPickAfterglow($id, [], $sx, $furn);
            if ($a['scene'] === '') { $none[] = $id; continue; }
            if ($a['routed']) { $routed++; $hopsum += $a['hops']; } else { $warp++; }
            $g = lrgScene($a['scene']);
            if (LRG_TIERS[$g['tier']] > LRG_TIERS['kissing'] || LRG_TIERS[$g['tier']] < LRG_TIERS['affection'] || !lrgSceneSexOk($g, $sx) || !lrgSceneFurnitureOk($g, $furn) || $g['excluded'] || $g['transition']
                || (lrgIsBedFamily($furn) && in_array($g['furniture'], ['', 'none'], true) && in_array('standing', $g['actor_tags'], true) && !$a['routed'])) { $badPick[] = "$id->{$a['scene']}"; }
            if ($i < 2) { $ex[] = $id . ' -> ' . $a['scene'] . ($a['routed'] ? " ({$a['hops']} hops)" : ' (no route: warp)'); }
        }
        say(sprintf("  %s %-12s %3d sexual scenes: %3d routed (avg %.1f hops), %2d warp, %d none   e.g. %s", $pl, $fl, count($hubs), $routed, $routed ? $hopsum / $routed : 0, $warp, count($none), implode(';  ', $ex)));
        $isBedOrFloor = $furn === '' || lrgIsBedFamily($furn);
        check("$pl $fl: every pick is a gentle scene the pair can be in there" . ($isBedOrFloor ? ', and every sexual scene has one' : ''), !$badPick && (!$isBedOrFloor || !$none), implode(' ', array_merge($badPick, $none)));
        if ($isBedOrFloor) {
            $top = array_slice(array_keys($hubs), 0, 12); $topRouted = 0;
            foreach ($top as $k) { $topRouted += lrgPickAfterglow($idx['scenes'][$k]['id'], [], $sx, $furn)['routed'] ? 1 : 0; }
            check("$pl $fl: the " . count($top) . " best-connected sexual hubs reach their wind-down over a real route ($topRouted)", $topRouted >= (int) ceil(count($top) * 0.75));
        }
    }
}
check('afterglow of a cuddle scene is empty (already there)', lrgPickAfterglowScene('OARE_GentleEmbrace', [], null) === '');
check('afterglow of an unknown scene is empty', lrgPickAfterglowScene('NoSuchScene', [], null) === '');
check('lrgPickAfterglowScene() is lrgPickAfterglow()["scene"]', lrgPickAfterglowScene('OARE_Missionary', [], $PAIRS['MF'], '') === lrgPickAfterglow('OARE_Missionary', [], $PAIRS['MF'], '')['scene']);
foreach (['OStimDoubleBedLeft2PCowgirlMF', 'OStimDoubleBedLeft2PKneelingBlowjobMF'] as $id) {
    if (lrgScene($id)) { $a = lrgPickAfterglow($id, [], $PAIRS['MF'], 'doublebed'); check("bed-edge scene $id winds down on the bed -> {$a['scene']}" . ($a['routed'] ? " ({$a['hops']} hops)" : ' (warp)'), $a['scene'] !== '' && lrgSceneFurnitureOk(lrgScene($a['scene']), 'doublebed')); }
}

// ------------------------------------------------------------------ H. robustness
echo "H. robustness\n";
check('JSON with BOM', lrgJsonDecodeLoose("\xEF\xBB\xBF{\"a\":1}") === ['a' => 1]);
check('JSON with comments and trailing comma', lrgJsonDecodeLoose("{ // note\n \"a\": \"x // not a comment\", /* block */ \"b\": [1,2,], }") === ['a' => 'x // not a comment', 'b' => [1, 2]]);
check('broken JSON returns null', lrgJsonDecodeLoose('{nope') === null);
check('scene lookup ignores case', lrgScene(strtoupper($start)) !== null);
check('sexes as words, letters or the game\'s 0 / 1', lrgNormSexes(['M', 'female']) === ['male', 'female'] && lrgNormSexes([1, 0]) === ['female', 'male'] && lrgNormSexes(['', '-1']) === ['any', 'any'] && lrgNormSexes([]) === null);
check('furniture list from the game: lowercased, de-duplicated, "none" and blanks dropped', lrgNormFurnitureList(['DoubleBed', ' doublebed', 'none', '', 'Chair']) === ['doublebed', 'chair']);
check('furniture wording: supertype fallback and unknown types', lrgFurnitureLabel('doublebed') === 'the bed' && in_array('bed', lrgFurnitureWords('singlebed'), true) && lrgFurnitureLabel('hayloft') === 'the hayloft' && lrgFurnitureWords('hayloft') === ['hayloft']);
$v2 = ['id' => 'Old', 'name' => 'Old', 'pack' => 'x', 'actors' => 2, 'sex' => ['any', 'any'], 'furniture' => '', 'transition' => false, 'tags' => [], 'actor_tags' => ['standing'], 'actions' => ['kissing'], 'to' => [], 'need' => ['any', 'any'], 'tier' => 'kissing', 'excluded' => false];
check('a scene digest of the previous index format (no hard / taste / special / types keys) is still usable', lrgSceneSexOk($v2, ['male', 'female']) && lrgSceneFurnitureOk($v2, 'doublebed') && lrgDescribeScene($v2) !== '' && lrgSceneNameFits($v2, ['male', 'male']));
$t = microtime(true);
$missing = is_file('/mnt/lrg_no_such_drive/profiles/Ultra/modlist.txt');
printf("  is_file on an absent mo2.root: %.2f ms (a wrong path costs one failed warm-up per 10 minutes on the state path, never an LLM turn)\n", (microtime(true) - $t) * 1000);
$out = []; $rc = 0;
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(LRG_DIR . '/lib/lrg_scene_index.php') . ' status', $out, $rc);
check('CLI entry: php lib/lrg_scene_index.php status', $rc === 0 && is_array(json_decode($out[0] ?? '', true)) && json_decode($out[0], true)['scenes'] === count($idx['scenes']), implode(' ', $out));
$out = []; exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/warm_index.php'), $out, $rc);
check('CLI entry: php tools/warm_index.php (up to date -> skipped, exit 0)', $rc === 0 && str_contains($out[0] ?? '', 'up to date'), implode(' ', $out));
if (in_array('--spawn', $argv, true)) { // the real thing: a detached rebuild, started the way the web server starts it (takes one build, ~6 s)
    $m = json_decode((string) file_get_contents(lrgIndexMetaPath()), true); $before = (int) $m['built']; $m['sig'] = 'force-stale'; $m['built'] = 1;
    file_put_contents(lrgIndexMetaPath(), json_encode($m));
    @unlink("$data/.index_checked"); unset($GLOBALS['LRG_TEST_NO_SPAWN']);
    $t = microtime(true); $started = lrgIndexMaybeRebuildAsync(); $tSpawn = ms($t);
    $done = false;
    for ($i = 0; $i < 120 && !$done; $i++) { usleep(250000); $mm = lrgIndexMeta(); $done = $mm && (int) $mm['built'] >= $before && ($mm['sig'] ?? '') !== 'force-stale'; }
    check("detached rebuild: the trigger returned after $tSpawn ms, the background process finished the index " . round(microtime(true) - $t, 1) . ' s later', $started && $tSpawn < 500 && $done);
    $GLOBALS['LRG_TEST_NO_SPAWN'] = true;
}
printf("  peak memory %.1f MB\n", memory_get_peak_usage() / 1048576);

// ------------------------------------------------------------------ I. [0.3] the act layer: who does what to whom
echo "\nI. acts and roles (index v4)\n";
$withRoles = array_filter($idx['scenes'], fn($s) => !empty($s['roles']));
check('digested scenes keep their roles ([action, actorSlot, targetSlot])', count($withRoles) > 0 && count($withRoles) > count($idx['scenes']) / 4,
    count($withRoles) . ' of ' . count($idx['scenes']) . ' scenes carry roles');
check('a role triple is [name, int, int]', (function () use ($withRoles) {
    $r = reset($withRoles)['roles'][0] ?? null;
    return is_array($r) && count($r) === 3 && is_string($r[0]) && is_int($r[1]) && is_int($r[2]);
})(), json_encode(reset($withRoles)['roles'][0] ?? null));
check('the index format version was bumped, so the cache really rebuilds', LRG_INDEX_VERSION === 4 && (int) ($idx['v'] ?? 0) === 4);
$families = [];
foreach ($idx['scenes'] as $s) {
    foreach ((array) ($s['roles'] ?? []) as $r) { $fam = lrgActFamily((string) $r[0]); if ($fam !== '') { $families[$fam] = ($families[$fam] ?? 0) + 1; } }
}
arsort($families);
say('  act families found in the installed packs: ' . implode(', ', array_map(fn($f, $n) => "$f($n)", array_keys($families), $families)));
check('the act table covers the vocabulary of the installed packs', count($families) >= 6, implode(',', array_keys($families)));
$unmapped = [];
foreach ($idx['scenes'] as $s) {
    foreach ((array) ($s['roles'] ?? []) as $r) { if (lrgActFamily((string) $r[0]) === '') { $unmapped[strtolower((string) $r[0])] = true; } }
}
say('  action names no family knows (reachable by plain words only): ' . (count($unmapped) ? implode(', ', array_slice(array_keys($unmapped), 0, 12)) . (count($unmapped) > 12 ? ', ...' : '') : 'none'));

// direction: the SAME scene read from the two actor positions gives the mirrored roles
$directional = null;
foreach ($idx['scenes'] as $s) {
    if (!empty($s['excluded']) || !empty($s['transition']) || (int) $s['actors'] !== 2) { continue; }
    $a = lrgSceneActs($s, 1, null);
    $b = lrgSceneActs($s, 0, null);
    if ($a !== $b && array_filter($a, fn($x) => str_contains((string) $x, ':'))) { $directional = [$s, $a, $b]; break; }
    }
if ($directional === null) {
    check('a directional scene exists in the installed packs', false, 'none found: roles cannot be asserted');
} else {
    [$s, $a, $b] = $directional;
    check('lrgSceneActs() flips the direction with the NPC\'s actor position (' . $s['id'] . ')', $a !== $b, json_encode($a) . ' vs ' . json_encode($b));
    $mirror = array_map(fn($x) => str_contains($x, ':npc') ? str_replace(':npc', ':you', $x) : str_replace(':you', ':npc', $x), $a);
    sort($mirror); $bs = $b; sort($bs);
    check('... and the two readings are exactly each other\'s mirror', $mirror === $bs, json_encode($mirror) . ' vs ' . json_encode($bs));
}
// an ASYMMETRIC scene: the direction has to come from the candidate's OWN slot sexes, not from the
// current thread's slot number, or half the directional acts would be offered the wrong way round
$asym = null;
foreach ($idx['scenes'] as $s) {
    if (!empty($s['excluded']) || !empty($s['transition']) || (int) $s['actors'] !== 2) { continue; }
    $sex = array_values((array) ($s['sex'] ?? []));
    if (count($sex) !== 2 || $sex[0] === $sex[1] || in_array('any', $sex, true)) { continue; }
    if (!array_filter(lrgSceneActs($s, 1, null), fn($x) => str_contains((string) $x, ':'))) { continue; }
    $asym = $s; break;
}
if ($asym === null) {
    say('  note: no asymmetric two-actor scene with a directional act is installed');
} else {
    $femaleIsSlot = array_search('female', array_values((array) $asym['sex']), true);
    // the NPC is female (psex=0 male player, sex=1 female NPC): the direction must follow HER slot,
    // whatever slot number the current thread happens to use
    $fromSexes = lrgSceneActs($asym, 0, ['0', '1']);
    $fromSlot = lrgSceneActs($asym, (int) $femaleIsSlot, null);
    sort($fromSexes); sort($fromSlot);
    check('an asymmetric scene (' . $asym['id'] . '): the sexes decide the direction, not the thread\'s slot number',
        $fromSexes === $fromSlot, json_encode($fromSexes) . ' vs ' . json_encode($fromSlot));
}
// lrgActOptions never offers an act the chosen scene does not have
$start = lrgPickStartScene(['0', '1']);
$acts = $start !== '' ? lrgActOptions($start, [], 4, ['0', '1'], '', 1, [], 32) : [];
check('lrgActOptions offers acts from ' . ($start ?: '(no start scene)'), $start === '' || count($acts) > 0, implode(',', array_keys($acts)));
check('every offered act is really in the scene it names', !array_filter($acts, function ($a) {
    $s = lrgScene((string) $a['scene']);
    return !$s || !in_array((string) $a['act'], lrgSceneActs($s, 1, ['0', '1']), true);
}), json_encode(array_map(fn($a) => $a['act'] . '=' . $a['scene'], $acts)));
check('no offered act is excluded or a transition', !array_filter($acts, fn($a) => fxSceneProblemLocal((string) $a['scene']) !== ''),
    implode(' ', array_map(fn($a) => $a['scene'] . ':' . fxSceneProblemLocal((string) $a['scene']), $acts)));
check('the acts already happening in the current scene are not offered as a change', !array_intersect(array_keys($acts), lrgSceneActs(lrgScene($start) ?: [], 1, ['0', '1'])));
check('an act above the ceiling is not offered', !array_filter(lrgActOptions($start, [], 2, ['0', '1'], '', 1, [], 32), fn($a) => LRG_TIERS[$a['tier']] > 2));
check('a visited scene is pushed behind an unvisited one', (function () use ($start, $acts) {
    if (count($acts) < 2) { return true; }
    $first = (string) reset($acts)['scene'];
    $with = lrgActOptions($start, [], 4, ['0', '1'], '', 1, [$first], 32);
    return !$with || (string) reset($with)['scene'] !== $first || count($with) === 1;
})());
check('spoken words map to an act id', lrgMatchAct('kiss me', []) === 'kiss' && lrgMatchAct('fuck me', []) === 'vaginal' && lrgMatchAct('nothing here', []) === '');
check('a role word picks the direction', str_ends_with(lrgMatchAct('suck my cock', []), ':npc') && str_ends_with(lrgMatchAct('let me suck you', []), ':you'));
check('an act label never contains a placeholder once the names are known', !str_contains(lrgActLabel('oralvulva:npc', 'Hulda', 'Jordan'), '%'), lrgActLabel('oralvulva:npc', 'Hulda', 'Jordan'));

echo $fails === 0 ? "ALL CHECKS PASSED\n" : "$fails CHECK(S) FAILED\n";
exit($fails === 0 ? 0 : 1);
