<?php
/**
 * LoreRim Glue - offline flow tests (R9). Scripted end-to-end conversations at plugin level:
 * no game, no HerikaServer, no real LLM. Written against glue/PROTOCOL.md v2.
 *
 * Usage (inside WSL, from the staged copy - the distro cannot see the project root):
 *   php tools/flows/run_flows.php                 every scenario, plus the child-process variants
 *   php tools/flows/run_flows.php --only=03,07    some scenarios (ids = file-name prefixes)
 *   php tools/flows/run_flows.php --trace         also print every played step and the volatile guidance
 *   php tools/flows/run_flows.php --strict        PENDING checks and warnings fail the run too (release gate)
 *   php tools/flows/run_flows.php --list          list scenarios
 *   php tools/flows/run_flows.php --allow-empty-index   scene scenarios become PENDING instead of FAIL when no pack can be read
 *
 * Check levels:  FAIL = the contract says otherwise.  warn = reasonable expectation beyond the letter of the contract.
 *                pend = cannot be judged yet (a contract function is not delivered, or the installed packs lack the data).
 * Exit code: 0 = no FAIL (and, with --strict, nothing pending and no warning); 1 otherwise.
 *
 * Variants run in child processes because they need process-wide state: a kill-switch config (lrgConfig() caches),
 * the SHARMAT constant, initiative switched off. The config override is written to <plugin>/config/lrg_config.json
 * of the STAGED copy only, an existing file is restored afterwards, and a live /var/www tree is refused.
 */

error_reporting(E_ALL);
ini_set('display_errors', 'stderr');

require __DIR__ . '/harness.php';
require __DIR__ . '/adapter.php';

$args = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $a, $m)) { $args[$m[1]] = $m[2] ?? true; }
}
$variant = is_string($args['variant'] ?? null) ? $args['variant'] : 'main';
$only = is_string($args['only'] ?? null) ? array_filter(array_map('trim', explode(',', $args['only']))) : [];
$strict = !empty($args['strict']);
Fx::$trace = !empty($args['trace']);
$GLOBALS['FX_ALLOW_EMPTY_INDEX'] = !empty($args['allow-empty-index']);

foreach (glob(__DIR__ . '/scenarios/*.php') as $file) { require $file; }
ksort(Fx::$scenarios, SORT_STRING);

if (!empty($args['list'])) {
    foreach (Fx::$scenarios as $id => $sc) { printf("%-4s %-16s %s\n", $id, $sc['variant'], $sc['title']); }
    exit(0);
}

// never against a live server tree: the run touches <plugin>/data (schema marker = "skip the migrations") and the plugin's log
$pluginDirEarly = (string) realpath(__DIR__ . '/../../server/lorerim_glue');
if ($pluginDirEarly === '' || str_starts_with($pluginDirEarly, '/var/www')) {
    fwrite(STDERR, "run_flows.php must run from a staged copy of glue/ (plugin dir: '" . $pluginDirEarly . "'), never inside HerikaServer.\n");
    exit(3);
}
// a config override left behind by an interrupted run would silently switch the plugin off for every later run
if (is_file($pluginDirEarly . '/config/.flowtest_override')) {
    @unlink($pluginDirEarly . '/config/lrg_config.json');
    @unlink($pluginDirEarly . '/config/.flowtest_override');
    echo "note: removed a config override left behind by an interrupted flow run\n";
}

// ------------------------------------------------------------------ variant set-up (before the plugin loads)
$restore = null;
if ($variant !== 'main') {
    $def = fxVariants()[$variant] ?? null;
    if ($def === null) { fwrite(STDERR, "unknown variant $variant\n"); exit(1); }
    foreach (($def['define'] ?? []) as $name => $value) { if (!defined($name)) { define($name, $value); } }
    if (!empty($def['config'])) {
        $pluginDir = realpath(__DIR__ . '/../../server/lorerim_glue');
        $file = $pluginDir . '/config/lrg_config.json';
        if (str_starts_with((string) $pluginDir, '/var/www')) { fwrite(STDERR, "refusing to write a config override into a live server tree: $pluginDir\n"); exit(3); }
        // a config override is written for a few seconds: never into the project source tree, where a
        // Ctrl-C (register_shutdown_function does not run) would leave the plugin switched off
        $tmp = realpath(sys_get_temp_dir()) ?: sys_get_temp_dir();
        $staged = str_starts_with((string) $pluginDir, $tmp) || (bool) preg_match('~/(temp|tmp)/~i', (string) $pluginDir);
        if (!$staged && empty($args['allow-in-place'])) {
            fwrite(STDERR, "the config-override variants only run from a staged copy in a temp folder (pass --allow-in-place to override): $pluginDir\n");
            exit(3);
        }
        $had = is_file($file); $old = $had ? (string) file_get_contents($file) : '';
        @file_put_contents($pluginDir . '/config/.flowtest_override', (string) getmypid());
        $restore = static function () use ($file, $had, $old, $pluginDir) { if ($had) { file_put_contents($file, $old); } else { @unlink($file); } @unlink($pluginDir . '/config/.flowtest_override'); };
        register_shutdown_function($restore);
        $user = $had ? (json_decode($old, true) ?: []) : [];
        file_put_contents($file, json_encode(array_replace_recursive($user, $def['config'])));
    }
}

fxLoadPlugin();
fx_install_error_handler();
echo 'LoreRim Glue flow tests - plugin ' . (defined('LRG_VERSION') ? LRG_VERSION : '?') . ', actions v' . (defined('LRG_ACTIONS_VERSION') ? LRG_ACTIONS_VERSION : '?')
    . ', variant ' . $variant . "\nplugin dir: " . fxPluginDir() . "\n";

fxReset();
if ($variant === 'main') {
    $t0 = microtime(true);
    $idx = fxWarmIndex();
    printf("scene index: %d scenes (%.1fs)%s\n", $idx['scenes'], microtime(true) - $t0, $idx['scenes'] ? '' : '  <-- EMPTY: check mo2.root / mo2.profile in the config and that /mnt/f is mounted');
}
fxProbeCapabilities();
echo 'contract capabilities: ' . implode('  ', array_map(fn($c) => $c . '=' . (Fx::$caps[$c] ? 'yes' : 'NO'), array_keys(Fx::$caps))) . "\n";

$results = fx_run($variant, $only, fxKnownPending(), 'fxReset');
if ($restore) { $restore(); }

if (!empty($args['json'])) { // child mode: hand the results to the parent
    echo "\n@@FXJSON@@" . json_encode(['results' => $results, 'php' => Fx::$phpIssues, 'db' => FxDb::$unhandledAll]) . "\n";
    exit(0);
}

// ------------------------------------------------------------------ child-process variants
if ($variant === 'main' && empty($args['no-variants'])) {
    foreach (array_keys(fxVariants()) as $v) {
        $ids = array_map('strval', array_keys(array_filter(Fx::$scenarios, fn($s) => $s['variant'] === $v)));
        if ($only) { $ids = array_values(array_intersect($ids, $only)); }
        if (!$ids) { continue; }
        echo "\n---------------- variant '$v' (child process) ----------------\n";
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --variant=' . escapeshellarg($v) . ' --json' . (Fx::$trace ? ' --trace' : '')
            . (empty($args['allow-in-place']) ? '' : ' --allow-in-place')
            . ($only ? ' --only=' . escapeshellarg(implode(',', $only)) : '') . ' 2>&1';
        $out = (string) shell_exec($cmd);
        $pos = strrpos($out, '@@FXJSON@@');
        $child = $pos === false ? null : json_decode(trim(substr($out, $pos + 10)), true);
        echo $pos === false ? $out : substr($out, 0, $pos);
        if (!is_array($child)) {
            foreach ($ids as $id) { $results[$id] = ['title' => Fx::$scenarios[$id]['title'], 'outcome' => 'FAIL', 'fail' => 1, 'pending' => 0, 'warn' => 0, 'ok' => 0, 'checks' => [], 'notes' => ['the child process produced no result']]; }
            continue;
        }
        $results += $child['results'];
        foreach ($child['php'] as $k => $n) { Fx::$phpIssues[$k] = (Fx::$phpIssues[$k] ?? 0) + $n; }
        FxDb::$unhandledAll = array_merge(FxDb::$unhandledAll, $child['db']);
    }
}

// ------------------------------------------------------------------ summary
ksort($results, SORT_STRING);
echo "\n================ summary ================\n";
$tot = ['PASS' => 0, 'FAIL' => 0, 'PENDING' => 0]; $warn = 0; $checks = 0;
foreach ($results as $id => $r) {
    $tot[$r['outcome']]++; $warn += $r['warn']; $checks += $r['ok'] + $r['fail'] + $r['pending'] + $r['warn'];
    printf("%-8s [%s] %s  (%d ok, %d fail, %d pending, %d warn)\n", $r['outcome'], $id, $r['title'], $r['ok'], $r['fail'], $r['pending'], $r['warn']);
    foreach ($r['checks'] as $c) {
        if ($c['status'] === 'ok') { continue; }
        $tag = $c['status'] === 'pending' ? 'pend' : ($c['level'] === 'must' ? 'FAIL' : 'warn');
        echo "           $tag  " . $c['name'] . ($c['detail'] !== '' ? '  [' . fx_short($c['detail'], 200) . ']' : '') . "\n";
    }
}
if (Fx::$phpIssues) {
    echo "\nPHP warnings / notices raised while the scenarios ran (each is a latent bug on the live server):\n";
    foreach (Fx::$phpIssues as $k => $n) { echo "  {$n}x  $k\n"; }
}
if (FxDb::$unhandledAll) {
    echo "\nDatabase calls outside the shapes PROTOCOL.md section 5 allows (the fake could not serve them):\n";
    foreach (array_count_values(array_map(fn($q) => fx_short($q, 160), FxDb::$unhandledAll)) as $q => $n) { echo "  {$n}x  $q\n"; }
}
printf("\n%d scenarios: %d passed, %d FAILED, %d pending; %d checks, %d warnings\n", count($results), $tot['PASS'], $tot['FAIL'], $tot['PENDING'], $checks, $warn);
if (!$results) { echo "RESULT: FAILED - no scenario ran" . ($only ? ' (nothing matches --only=' . implode(',', $only) . ')' : '') . "\n"; exit(1); }
// the gate fails on the things this runner itself calls bugs, not only on a failed check
$why = [];
if ($tot['FAIL'] > 0) { $why[] = $tot['FAIL'] . ' scenario(s) failed'; }
if (FxDb::$unhandledAll !== []) { $why[] = count(FxDb::$unhandledAll) . ' database call(s) outside PROTOCOL section 5'; }
if ($strict && $tot['PENDING'] > 0) { $why[] = $tot['PENDING'] . ' pending (--strict)'; }
if ($strict && $warn > 0) { $why[] = $warn . ' warning(s) (--strict)'; }
if ($strict && Fx::$phpIssues !== []) { $why[] = array_sum(Fx::$phpIssues) . ' PHP notice(s) (--strict)'; }
$bad = $why !== [];
echo $bad ? 'RESULT: FAILED - ' . implode('; ', $why) . "\n" : ($tot['PENDING'] ? "RESULT: OK so far (pending items are listed above)\n" : "RESULT: OK\n");
exit($bad ? 1 : 0);
