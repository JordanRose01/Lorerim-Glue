<?php
/**
 * LoreRim Glue - GROK vs DEEPSEEK, WITH NUMBERS (plan 20.5 / E8).
 *
 * The owner asked whether to move the in-game reply off Grok 4.3 and onto DeepSeek V4 Flash. That is a
 * question with an answer, not an opinion, so this measures it on HIS connectors, with HIS API key, on
 * prompts the shape of HIS turns.
 *
 * WHAT THE LOGS ALREADY SAY (read from 8,680 [PERF] lines, 86 with a real LLM call):
 *   a normal spoken turn is ~7.73 s total, ~6.54 s of it the model, ~0.61 s everything the server does.
 *   Our own prompt blocks are 58 ms of that. The model is 85-95 % of the wait; the glue is not the
 *   problem, and shrinking prompts would win tens of milliseconds against a 6.5-second call.
 *
 * THE TRAP, AND IT IS THE WHOLE REASON THIS FILE EXISTS:
 *   connector 10 (Grok 4.3) carries {"reasoning":{"effort":"none"}} with extra_parameters_enabled.
 *   connector 1 (DeepSeek V4 Flash) is marked reasoning_model = 1 and its metadata is EMPTY.
 *   Switching the Standard LLM from 10 to 1 as-is risks a model that THINKS before every line of dialogue,
 *   which would make her SLOWER, not faster - and it would look like the glue's fault.
 *   Copy the reasoning setting across first, then measure.
 *
 * Usage (inside WSL, on the server or a staged copy):
 *   php tools/bench_llm.php --dry                          plan the run, spend nothing
 *   php tools/bench_llm.php --list                         the connectors, their models and their metadata
 *   php tools/bench_llm.php --n 12 --connectors 10,1,2,3   the real thing
 *   php tools/bench_llm.php --n 8 --connectors 10,1 --from-log /var/www/html/HerikaServer/log/lorerim_glue.log
 *   php tools/bench_llm.php --out research/llm_bench.json
 *
 * IT CHANGES NO SETTING. It reads core_llm_connector and core_api_badge and it POSTs to the provider.
 *
 * WHICH SETTING IS THE SWITCH (0.5.0 release pass - the earlier note named the wrong one):
 *   the ~6.5 s NPC reply comes from the PROFILE's Standard LLM, core_profiles.llm_primary_id
 *   (CHIM UI: Settings -> the active profile -> "Standard LLM"). On this install profile 1 has
 *   llm_primary_id = 10, i.e. Grok 4.3. THAT is the row to change (10 -> 1) to move the in-game
 *   reply onto DeepSeek V4 Flash.
 *   CORE_CONNECTOR_DIRECTOR in general_settings is a SEPARATE switch: it drives Director Mode,
 *   not the ordinary spoken reply. It also happens to be 10 here, which is why the two were
 *   confused. Changing it alone would not move reply latency at all.
 * Nothing in the glue is tied to a model.
 */

error_reporting(E_ALL);
ini_set('display_errors', 'stderr');

$root = dirname(__DIR__);
require_once $root . '/server/lorerim_glue/lib/lrg_prompt_index.php';   // brings lrg_core + the CLI db

$args = [];
$flat = array_slice($argv, 1);
for ($i = 0; $i < count($flat); $i++) {
    $a = (string) $flat[$i];
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $a, $m)) {
        $v = $m[2] ?? null;
        if ($v === null && isset($flat[$i + 1]) && strncmp((string) $flat[$i + 1], '--', 2) !== 0) { $v = $flat[++$i]; }
        $args[$m[1]] = $v ?? true;
    }
}
$n = max(1, min(60, (int) ($args['n'] ?? 8)));
$dry = !empty($args['dry']);
$out = is_string($args['out'] ?? null) ? $args['out'] : ($root . '/../research/llm_bench.json');

echo "LoreRim Glue - LLM bench (read-only; it changes no setting)\n";
echo "NOTE: on this install the MODEL is 85-95% of the wait. A normal spoken turn measured\n";
echo "      ~7.73 s total, ~6.54 s of it the LLM, ~0.61 s everything the server does (our own\n";
echo "      prompt blocks: 58 ms). Prompt tuning cannot win what a faster model can.\n";
echo "WARNING: DeepSeek V4 Flash is marked reasoning_model=1 with EMPTY metadata, while Grok 4.3\n";
echo "      carries {\"reasoning\":{\"effort\":\"none\"}}. Copy that setting across BEFORE switching the\n";
echo "      profile's Standard LLM, or you may be paying for thinking you cannot hear.\n\n";

if (!lrgPromptCliDb()) {
    fwrite(STDERR, "no Postgres connection (LRG_PGDSN overrides the default DSN)\n");
    exit(2);
}
$db = lrgDb();

// ------------------------------------------------------------------ the connectors, read-only
$cons = [];
try {
    $rows = (array) $db->fetchAll('SELECT c.id, c.label, c.model, c.url, c.provider, c.driver,'
        . ' c.reasoning_model, c.max_tokens, c.enforce_json, c.json_schema, c.temperature, c.metadata,'
        . ' b.api_key FROM core_llm_connector c LEFT JOIN core_api_badge b ON b.id = c.api_badge_id'
        . ' ORDER BY c.id');
} catch (Throwable $e) {
    fwrite(STDERR, 'cannot read core_llm_connector: ' . $e->getMessage() . "\n");
    exit(2);
}
foreach ($rows as $r) { $cons[(int) $r['id']] = $r; }
$director = '';
try {
    $d = $db->fetchOne("SELECT value FROM general_settings WHERE id = 'CORE_CONNECTOR_DIRECTOR'");
    $director = (string) (is_array($d) ? ($d['value'] ?? '') : '');
} catch (Throwable $e) { /* not fatal */ }
// The row that really decides the in-game reply. Read beside the director so --list shows both and
// nobody has to guess which one the recommendation means.
$primary = '';
$profileId = '';
try {
    $p = $db->fetchOne('SELECT id, llm_primary_id FROM core_profiles ORDER BY id LIMIT 1');
    if (is_array($p)) {
        $primary = (string) ($p['llm_primary_id'] ?? '');
        $profileId = (string) ($p['id'] ?? '');
    }
} catch (Throwable $e) { /* not fatal */ }

if (!empty($args['list']) || $cons === []) {
    printf("%-4s %-24s %-34s %-6s %-9s %s\n", 'id', 'label', 'model', 'reason', 'suppress', 'assigned');
    foreach ($cons as $id => $c) {
        $md = is_string($c['metadata'] ?? '') ? (json_decode((string) $c['metadata'], true) ?: []) : (array) ($c['metadata'] ?? []);
        $sup = !empty($md['extra_parameters_enabled'])
            && isset($md['extra_parameters']['reasoning']) ? 'YES' : 'no';
        $role = [];
        if ((string) $id === $primary) { $role[] = 'core_profiles.llm_primary_id (Standard LLM) <- THE REPLY'; }
        if ((string) $id === $director) { $role[] = 'CORE_CONNECTOR_DIRECTOR (Director Mode)'; }
        printf("%-4d %-24s %-34s %-6s %-9s %s\n", $id, substr((string) $c['label'], 0, 24),
            substr((string) $c['model'], 0, 34), ((int) ($c['reasoning_model'] ?? 0) === 1 ? 'yes' : 'no'),
            $sup, implode(' + ', $role));
    }
    echo "\nprofile " . ($profileId !== '' ? $profileId : '?') . ': llm_primary_id = '
        . ($primary !== '' ? $primary : '?') . "   <- the Standard LLM: change THIS to move reply latency\n";
    echo 'general_settings CORE_CONNECTOR_DIRECTOR = ' . ($director !== '' ? $director : '?')
        . "   <- Director Mode only; changing it does not touch the spoken reply\n";
    if ($cons === []) { exit(2); }
    if (!empty($args['list'])) { exit(0); }
}

$want = [];
// default to the connector that really answers the player: the profile's Standard LLM
foreach (explode(',', (string) ($args['connectors'] ?? ($primary !== '' ? $primary : ($director !== '' ? $director : '10')))) as $x) {
    $x = (int) trim($x);
    if ($x > 0 && isset($cons[$x])) { $want[] = $x; }
    elseif ($x > 0) { fwrite(STDERR, "no connector with id $x - skipped\n"); }
}
if (!$want) { fwrite(STDERR, "no usable connector ids\n"); exit(2); }

// ------------------------------------------------------------------ the prompts
/**
 * Real turns, scrubbed. --from-log harvests the player's own utterances out of the glue's own log
 * (the `utter` / `dlgtalk` lines), which is the closest thing to a captured prompt that exists without
 * storing prompts anywhere. With no log it falls back to a fixed set of the shapes this module really
 * produces: an ordinary turn, a business turn with a <business> block, and a price-list turn.
 */
$utters = [];
$logFile = is_string($args['from-log'] ?? null) ? $args['from-log'] : '';
if ($logFile !== '' && is_readable($logFile)) {
    foreach (preg_split('/\R/', (string) file_get_contents($logFile)) ?: [] as $line) {
        if (preg_match('/said: "([^"]{8,120})"/', $line, $m)) { $utters[] = $m[1]; }
    }
    $utters = array_values(array_unique($utters));
}
if (!$utters) {
    $utters = ['Is there work going?', 'I need a room for the night.', 'Take me to Solitude.',
        'Come on, you can tell me.', 'What can I ask you?', 'How much to Morthal?',
        'Can you train me in Alchemy?', 'What have you got for sale?', 'I will pay the fine.',
        'What should I do next?', 'Do you know anything about the mill?', 'Here, fifty septims.'];
}
shuffle($utters);
$utters = array_slice(array_values($utters), 0, $n);
while (count($utters) < $n) { $utters[] = $utters[count($utters) % max(1, count($utters))]; }

$system = "You are Brenna, an innkeeper in Whiterun. Answer in character, one or two short sentences.\n"
    . "<real_business>\nJobs, quests, payments, favours, access, secrets, services and arrests are settled\n"
    . "by the world, not by talk. You can only grant, accept, refuse or conclude such a thing with the\n"
    . "action TakeUpBusiness.\n</real_business>\n"
    . "<business for=\"Brenna\" state=\"things the player can raise\">\n"
    . "T1 I need work.\nT2 [a service] I'd like to rent a room.\nT3 Have you heard any rumours?\n"
    . "T4 [leave] Never mind.\n</business>\n"
    . 'Reply as strict JSON: {"action":"","target":"","item":"","message":"","mood":""}';

// ------------------------------------------------------------------ the run plan
printf("%d prompt(s) x %d connector(s), INTERLEAVED (never in blocks, so provider-side variance cannot\n"
    . "decide the winner). Source of prompts: %s\n\n", count($utters), count($want),
    $logFile !== '' && is_readable($logFile) ? ('the glue log ' . $logFile) : 'the built-in turn shapes');
foreach ($want as $id) {
    $c = $cons[$id];
    $md = is_string($c['metadata'] ?? '') ? (json_decode((string) $c['metadata'], true) ?: []) : (array) ($c['metadata'] ?? []);
    $sup = !empty($md['extra_parameters_enabled']) && isset($md['extra_parameters']['reasoning']);
    printf("  connector %-3d %-22s %-32s reasoning_model=%d suppression=%s%s\n", $id,
        substr((string) $c['label'], 0, 22), substr((string) $c['model'], 0, 32),
        (int) ($c['reasoning_model'] ?? 0), $sup ? 'set' : 'NONE',
        ((int) ($c['reasoning_model'] ?? 0) === 1 && !$sup) ? '   <-- may think before every line' : '');
    if (trim((string) ($c['api_key'] ?? '')) === '') {
        printf("       WARNING: no API key is attached to this connector (core_api_badge)\n");
    }
}
echo "\n";

if ($dry) {
    echo "--dry: nothing was sent and nothing was spent. Drop --dry to measure.\n";
    exit(0);
}
if (!function_exists('curl_init')) { fwrite(STDERR, "php-curl is not available\n"); exit(2); }

// ------------------------------------------------------------------ one call, streamed, TTFT measured
function benchCall(array $c, string $system, string $user): array
{
    $md = is_string($c['metadata'] ?? '') ? (json_decode((string) $c['metadata'], true) ?: []) : (array) ($c['metadata'] ?? []);
    $body = [
        'model' => (string) $c['model'],
        'messages' => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $user]],
        'max_tokens' => (int) ($c['max_tokens'] ?: 750),
        'temperature' => (float) ($c['temperature'] ?: 0.7),
        'stream' => true,
        'usage' => ['include' => true],
    ];
    if (!empty($c['enforce_json'])) { $body['response_format'] = ['type' => 'json_object']; }
    // the connector's OWN extra parameters, exactly as CHIM would send them - this is the whole point
    if (!empty($md['extra_parameters_enabled']) && is_array($md['extra_parameters'] ?? null)) {
        $body = array_replace($body, (array) $md['extra_parameters']);
    }
    $t0 = microtime(true);
    $ttft = null;
    $text = '';
    $usage = [];
    $ch = curl_init((string) $c['url']);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json',
            'Authorization: Bearer ' . trim((string) ($c['api_key'] ?? '')),
            'X-Title: LoreRim Glue bench'],
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$ttft, &$text, &$usage, $t0) {
            foreach (preg_split('/\R/', (string) $chunk) ?: [] as $line) {
                if (strncmp($line, 'data: ', 6) !== 0) { continue; }
                $p = substr($line, 6);
                if (trim($p) === '[DONE]') { continue; }
                $j = json_decode($p, true);
                if (!is_array($j)) { continue; }
                if (isset($j['usage'])) { $usage = (array) $j['usage']; }
                $d = (string) ($j['choices'][0]['delta']['content'] ?? '');
                if ($d !== '') {
                    if ($ttft === null) { $ttft = (microtime(true) - $t0) * 1000.0; }
                    $text .= $d;
                }
            }
            return strlen((string) $chunk);
        },
    ]);
    $okCurl = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $total = (microtime(true) - $t0) * 1000.0;
    // strict-JSON action compliance: does it parse, and does it carry the shape the post-gate needs?
    $json = json_decode(trim($text), true);
    $jsonOk = is_array($json) && array_key_exists('message', $json);
    $actionOk = $jsonOk && array_key_exists('action', $json);
    return ['ok' => $okCurl !== false && $code >= 200 && $code < 300 && $text !== '',
        'http' => $code, 'err' => $err, 'ttft_ms' => $ttft === null ? null : round($ttft),
        'total_ms' => round($total), 'chars' => strlen($text), 'json' => $jsonOk ? 'ok' : 'bad',
        'action_key' => $actionOk ? 1 : 0, 'usage' => $usage,
        'cost_usd' => (float) ($usage['cost'] ?? 0), 'tokens_out' => (int) ($usage['completion_tokens'] ?? 0)];
}

// ------------------------------------------------------------------ interleaved
$results = [];
foreach ($utters as $i => $u) {
    foreach ($want as $id) {
        $r = benchCall($cons[$id], $system, $u);
        $r['prompt'] = substr($u, 0, 60);
        $results[$id][] = $r;
        printf("  [%2d/%2d] connector %-3d %-28s ttft=%-7s total=%-7s json=%-4s out=%-5s %s\n",
            $i + 1, count($utters), $id, substr((string) $cons[$id]['label'], 0, 28),
            $r['ttft_ms'] === null ? '-' : ($r['ttft_ms'] . 'ms'), $r['total_ms'] . 'ms', $r['json'],
            (string) $r['tokens_out'], $r['ok'] ? '' : ('FAILED http=' . $r['http'] . ' ' . substr($r['err'], 0, 60)));
    }
}

// ------------------------------------------------------------------ the table
function med(array $v) { sort($v); $n = count($v); return $n ? ($n % 2 ? $v[intdiv($n, 2)] : (int) (($v[$n / 2 - 1] + $v[$n / 2]) / 2)) : 0; }
echo "\n================ result ================\n";
printf("%-4s %-24s %8s %8s %8s %8s %7s %10s\n", 'id', 'label', 'ttft', 'total', 'p90', 'tok/turn', 'json%', 'usd/turn');
$summary = [];
foreach ($want as $id) {
    $rs = (array) ($results[$id] ?? []);
    $good = array_values(array_filter($rs, static fn($r) => $r['ok']));
    $tt = array_values(array_filter(array_map(static fn($r) => $r['ttft_ms'], $good), static fn($x) => $x !== null));
    $to = array_map(static fn($r) => (int) $r['total_ms'], $good);
    sort($to);
    $p90 = $to ? $to[max(0, (int) ceil(count($to) * 0.9) - 1)] : 0;
    $jsonOk = count(array_filter($good, static fn($r) => $r['json'] === 'ok'));
    $row = ['id' => $id, 'label' => (string) $cons[$id]['label'], 'model' => (string) $cons[$id]['model'],
        'n' => count($rs), 'ok' => count($good), 'ttft_ms' => med($tt), 'total_ms' => med($to),
        'p90_ms' => $p90, 'tokens_out' => med(array_map(static fn($r) => (int) $r['tokens_out'], $good)),
        'json_pct' => $good ? round(100.0 * $jsonOk / count($good), 1) : 0.0,
        'cost_usd_per_turn' => $good ? round(array_sum(array_map(static fn($r) => (float) $r['cost_usd'], $good)) / count($good), 6) : 0.0,
        'reasoning_model' => (int) ($cons[$id]['reasoning_model'] ?? 0)];
    $summary[] = $row;
    printf("%-4d %-24s %8s %8s %8s %8d %6.1f%% %10.6f\n", $id, substr($row['label'], 0, 24),
        $row['ttft_ms'] . 'ms', $row['total_ms'] . 'ms', $row['p90_ms'] . 'ms', $row['tokens_out'],
        $row['json_pct'], $row['cost_usd_per_turn']);
}
echo "\nRead it against the install's own baseline: the LLM is ~6.54 s of a ~7.73 s spoken turn today.\n";
echo "The switch, when you decide: core_profiles.llm_primary_id - the active profile's Standard LLM in\n";
echo "the CHIM UI. CORE_CONNECTOR_DIRECTOR is Director Mode and does not change the spoken reply.\n";
echo "Copy the reasoning suppression onto whichever connector you pick first.\n";

@mkdir(dirname($out), 0775, true);
@file_put_contents($out, json_encode(['_' => 'llm_bench', 'at' => date('c'), 'n' => count($utters),
    'director' => $director, 'llm_primary_id' => $primary, 'profile_id' => $profileId,
    'baseline' => ['turn_total_s' => 7.73, 'llm_s' => 6.54, 'server_s' => 0.61,
        'prompt_ready_ms' => 58], 'summary' => $summary, 'runs' => $results], JSON_PRETTY_PRINT));
echo "\nwrote $out\n";
exit(0);
