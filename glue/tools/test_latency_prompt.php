<?php
/**
 * [PT9 / latency] How MANY CHARACTERS the glue adds to a CHIM prompt, per turn mode.
 *
 * Read-only measurement, no game, no HerikaServer, no LLM: it replays the same fixtures the flow tests
 * use (tools/flows/harness.php + adapter.php) and prints the size of the two prompt injections
 * (lrgStaticGuidance -> character_bottom, lrgVolatileGuidance -> prompt_bottom) for each turn mode.
 *
 * Usage (inside WSL, from a staged copy):
 *     php tools/test_latency_prompt.php            the table
 *     php tools/test_latency_prompt.php --json     machine-readable (for a before / after diff)
 *     php tools/test_latency_prompt.php --dump=scene   print one fixture's text in full
 *
 * TOKENS: PHP has no tokenizer here, so the token column is the standard chars/4 estimate and is only
 * ever used for the DELTA between two runs of this same file. PT9 measured the real figure on the live
 * server (plugin_injections: 176 tok median of 5,755, p90 798): 1 token ~ 4.0 characters held there too.
 */

error_reporting(E_ALL);
ini_set('display_errors', 'stderr');

require __DIR__ . '/flows/harness.php';
require __DIR__ . '/flows/adapter.php';

$args = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $a, $m)) { $args[$m[1]] = $m[2] ?? true; }
}
$asJson = !empty($args['json']);
$dump = is_string($args['dump'] ?? null) ? $args['dump'] : '';

fxLoadPlugin();
fxReset();
$idx = fxWarmIndex();
fxProbeCapabilities();
fxReset();

$rows = [];
/** One fixture: label => [static, volatile] of the turn it built. */
function lat_row(string $label, callable $fn): void
{
    global $rows;
    fxReset();
    try {
        $r = $fn();
    } catch (Throwable $e) {
        $rows[$label] = ['static' => 0, 'volatile' => 0, 'total' => 0, 'tokens' => 0, 'note' => 'SKIPPED: ' . $e->getMessage(), 'text' => ''];
        return;
    }
    if (!$r instanceof FxTurnResult) {
        $rows[$label] = ['static' => 0, 'volatile' => 0, 'total' => 0, 'tokens' => 0, 'note' => 'no turn', 'text' => ''];
        return;
    }
    $s = strlen($r->static); $v = strlen($r->volatile);
    $rows[$label] = ['static' => $s, 'volatile' => $v, 'total' => $s + $v, 'tokens' => (int) ceil(($s + $v) / 4),
        'note' => $r->handled ? 'dropped before the lock (0 LLM call)' : ('mode=' . ($r->mode() ?? '-')), 'text' => $r->static . $r->volatile];
}

// ---------------------------------------------------------------- the fixtures (one per turn mode)
$inn = fxCast('innkeeper'); $innName = $inn['name'];
$com = fxCast('commoner');  $comName = $com['name'];

lat_row('closed  (stranger, refused)', function () use ($comName, $com) {
    $snap = fxSnap($com['snap'] + ['wit' => '2']);
    fxAff($comName, -10);
    return fxSay($comName, $snap, 'You look well today.');
});

lat_row('public  (willing, witnesses)', function () use ($innName, $inn) {
    $snap = fxSnap($inn['snap'] + ['wit' => '2']);
    fxSetScore($innName, $snap, 40);
    return fxSay($innName, $snap, 'I would like some time with you.');
});

lat_row('private (willing, alone)', function () use ($innName, $inn) {
    $snap = fxSnap($inn['snap']);
    fxSetScore($innName, $snap, 40);
    fxWarm($innName, $snap);
    return fxSay($innName, $snap, 'We are alone now.');
});

lat_row('follow  (invitation accepted)', function () use ($innName, $inn) {
    $snap = fxSnap($inn['snap'] + ['wit' => '2']);
    fxSetScore($innName, $snap, 40);
    fxWarm($innName, $snap);
    fxMemSet($innName, ['invite' => ['place' => 'room', 'loc' => 'Flowtest Inn', 'at' => fxNow(), 'expires' => fxNow() + 3600]]);
    return fxSay($innName, fxSnap($inn['snap']), 'Here we are.');
});

lat_row('initiative tick (private)', function () use ($innName, $inn) {
    $snap = fxSnap($inn['snap']);
    fxSetScore($innName, $snap, 60);
    fxWarm($innName, $snap);
    fxRoll('initiative', 1);
    return fxInitiativeTick($innName, $snap);
});

// --- scene fixtures need the real scene index (the installed OStim packs)
$sceneSetUp = static function (string $npc, array $snap): array {
    if (count(lrgSceneIndex()['scenes'] ?? []) === 0) { throw new RuntimeException('scene index empty'); }
    fxSetScore($npc, $snap, 60);
    fxWarm($npc, $snap);
    fxSay($npc, $snap, 'Come here.');
    $wire = fxLlm($npc, FX_ACT_START, 'Player');
    if (count($wire) !== 1) { throw new RuntimeException('BeginIntimacy did not pass the gate'); }
    $kv = fxParamKv($wire[0]);
    $start = (($kv['furn'] ?? '') !== '' && ($kv['fscene'] ?? '') !== '') ? $kv['fscene'] : (string) ($kv['scene'] ?? '');
    if ($start === '') { $start = fxStartScene(); }
    fxFuncret($wire[0], 'They draw close. The scene begins.');
    $in = ['ostim' => '1'] + $snap;
    fxSendSnapshot($npc, $in);
    $cid = (string) ($kv['cid'] ?? 'lat00001');
    fxSendScene($npc, 'start', ['scene' => $start, 'cid' => $cid, 'furn' => (string) ($kv['furn'] ?? '')]);
    [$sensual, $sexual] = fxClimbToTop($npc, $start, $cid);
    return ['cid' => $cid, 'snap' => $in, 'scene' => $sexual ?: ($sensual ?: $start)];
};

lat_row('scene   (player speaks, acts offered)', function () use ($innName, $inn, $sceneSetUp) {
    $snap = fxSnap($inn['snap']);
    $s = $sceneSetUp($innName, $snap);
    return fxSay($innName, $s['snap'], 'Faster.');
});

lat_row('scene   (player speaks, no request)', function () use ($innName, $inn, $sceneSetUp) {
    $snap = fxSnap($inn['snap']);
    $s = $sceneSetUp($innName, $snap);
    return fxSay($innName, $s['snap'], 'You feel good.');
});

lat_row('scene   (lead tick)', function () use ($innName, $inn, $sceneSetUp) {
    $snap = fxSnap($inn['snap']);
    $s = $sceneSetUp($innName, $snap);
    fxAdvance(400); // past lead.hold_seconds / speech_hold_seconds
    return fxLeadTick($innName, $s['snap']);
});

lat_row('scene   (scene-talk tick)', function () use ($innName, $inn, $sceneSetUp) {
    $snap = fxSnap($inn['snap']);
    $s = $sceneSetUp($innName, $snap);
    fxAdvance(400);
    return fxSceneTalk($innName, $s['snap'], 'the position just changed');
});

lat_row('outro   (after the scene ended)', function () use ($innName, $inn, $sceneSetUp) {
    $snap = fxSnap($inn['snap']);
    $s = $sceneSetUp($innName, $snap);
    fxAdvance(120);
    fxSendScene($innName, 'end', ['scene' => $s['scene'], 'cid' => $s['cid'], 'how' => 'finished', 'ncl' => '1', 'pcl' => '1', 'dur' => '240']);
    return fxSceneTalk($innName, ['ostim' => '0'] + $s['snap'], 'outro');
});

// ---------------------------------------------------------------- output
if ($dump !== '') {
    foreach ($rows as $label => $r) {
        if (stripos($label, $dump) !== false) { echo "==== $label ====\n" . $r['text'] . "\n"; }
    }
    exit(0);
}
if ($asJson) {
    $out = [];
    foreach ($rows as $k => $r) { unset($r['text']); $out[$k] = $r; }
    echo json_encode(['scenes' => (int) ($idx['scenes'] ?? 0), 'rows' => $out], JSON_PRETTY_PRINT) . "\n";
    exit(0);
}
echo 'LoreRim Glue - injected prompt size per turn (plugin ' . (defined('LRG_VERSION') ? LRG_VERSION : '?')
    . ', scene index ' . (int) ($idx['scenes'] ?? 0) . " scenes)\n\n";
printf("%-38s %9s %9s %9s %9s  %s\n", 'turn', 'static', 'volatile', 'chars', '~tokens', 'note');
$tot = 0; $n = 0;
foreach ($rows as $label => $r) {
    printf("%-38s %9d %9d %9d %9d  %s\n", $label, $r['static'], $r['volatile'], $r['total'], $r['tokens'], $r['note']);
    if ($r['total'] > 0) { $tot += $r['total']; $n++; }
}
printf("\n%-38s %29d %9d  (%d fixtures)\n", 'MEAN per turn', $n ? (int) round($tot / $n) : 0, $n ? (int) round($tot / $n / 4) : 0, $n);
