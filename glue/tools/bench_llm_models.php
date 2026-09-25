<?php
/**
 * [0.4.2 / PT9] MODEL BENCHMARK - what a model swap would really buy, measured, not quoted.
 *
 * Runs a SYNTHETIC turn that mirrors one real CHIM request - a ~6,000-token prompt in CHIM's section
 * shape, streaming on, strict JSON with an action schema, max_tokens 200, reasoning effort none - through
 * the OWNER'S OWN OpenRouter connector (his key, read from core_api_badge, never printed) and measures:
 *
 *   ttft    time to the first content token
 *   speech  time to the first FINISHED sentence inside the "message" field - what the player waits for,
 *           because CHIM only hands a sentence to TTS when it is complete
 *   total   time to the last token
 *   json    did the reply parse as the strict schema, and did it choose the one right action
 *   cost    the provider's own usage figures, and the list price applied to a REAL CHIM turn
 *
 * NOTHING of the owner's data is sent: the prompt is invented here, in this file, and no game, log or
 * database text of his ever reaches a provider. Nothing is written anywhere, and no CHIM setting is read
 * except the API key and the model ids.
 *
 * IT IS NOT A TEST, AND IT IS NOT IN THE TEST SUITE. It was called tools/test_latency_models.php until
 * the 0.5.0 reconciliation, which is how it ended up one careless `for f in tools/test_*.php` away from
 * a release pass spending the owner's money. Hence the name it has now, and hence the default:
 *
 *   **--dry is the DEFAULT. Nothing is sent, and nothing is spent, unless --live is passed.**
 *
 * Usage (inside WSL):
 *     php tools/bench_llm_models.php                          DRY: build and price everything, send nothing
 *     php tools/bench_llm_models.php --live                   the default shortlist, 6 requests each - COSTS MONEY
 *     php tools/bench_llm_models.php --live --n=3             fewer requests per model
 *     php tools/bench_llm_models.php --live --models=a,b      a different shortlist (ids as OpenRouter writes them)
 *     php tools/bench_llm_models.php --live --out=run.json    keep every raw measurement for re-checking
 *
 * With --live this COSTS REAL MONEY: n x models requests of ~6 k prompt tokens. The run prints what it
 * spent, from the provider's own usage figures. Keep --n small.
 *
 * WHAT THE NUMBERS CAN AND CANNOT CARRY (research/pt9-latency-verify.md D6): n is 6 per model by
 * default, sequential, over a shared network. That separates $8.39 from $1.76 per 1,000 turns and 6/6
 * from 4/6 strict-JSON compliance; it does NOT separate 0.79 s from 0.92 s. Every median is printed
 * with its n, the median prompt tokens are printed per model (tokenizers differ, characters do not),
 * and --out keeps the raw samples so a conclusion can be re-checked without paying again.
 */

error_reporting(E_ALL);
ini_set('display_errors', 'stderr');

$args = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $a, $m)) { $args[$m[1]] = $m[2] ?? true; }
}
$n = max(1, min(6, (int) ($args['n'] ?? 6)));          // the round's cap: at most 6 requests per model
// [0.5.0 reconciliation] SPENDING IS OPT-IN. --dry is honoured for anyone who still types it, but the
// only thing that makes this file send a request is --live (or --go). A run that is merely started -
// by a person, by a release script, by a `for f in tools/*.php` - costs nothing.
$dry = empty($args['live']) && empty($args['go']);
$rawOut = is_string($args['out'] ?? null) ? $args['out'] : '';
$raw = [];
$models = is_string($args['models'] ?? null)
    ? array_values(array_filter(array_map('trim', explode(',', $args['models']))))
    : [
        'x-ai/grok-4.3',                 // configured, connector 10 (the baseline to beat)
        'deepseek/deepseek-v4-flash',    // configured, connector 1 - the model the owner asked about
        'google/gemini-3.1-flash-lite',  // lowest time-to-first-token class, Google-only routing
        'qwen/qwen3.8-flash',            // ONE endpoint (Alibaba): the most predictable p90 of the cheap ones
        'z-ai/glm-5.3-flash',            // cheapest of the shortlist
        'openai/gpt-5.4-nano',           // low-latency tier with reliable strict JSON
    ];
$models = array_slice($models, 0, 6);                   // the round's cap: at most 6 models

// PT9's measured profile of ONE real player turn on this install (research/pt9-latency.md section 1)
const LAT_REAL_PROMPT_TOKENS = 6543;
const LAT_REAL_COMPLETION_TOKENS = 83;

// ---------------------------------------------------------------- the synthetic turn (invented here)
/**
 * CHIM's prompt is roughly: roleplay_instructions 208 tok, character 1,629, actions 1,217,
 * nearby_actors 784, knowledge 662, world 52, plugin_injections 176 - about 5.8-6.5 k in total.
 * This rebuilds that SHAPE out of invented material of the same size.
 */
function latPrompt(): string
{
    $npc = 'Halvard Stoneveil';
    $player = 'Ashen';
    $town = 'Greyhollow';
    $inn = 'The Gilded Flask';
    $s = [];
    $s[] = "<roleplay_instructions>\nYou are $npc, a person in the town of $town. Answer the player, called $player, in your own voice."
        . " Speak like a real person: plain everyday words, one or two short sentences, never poetry, never narration, never stage directions."
        . " You are an adult talking to an adult and blunt language is allowed. You never describe your own feelings from outside."
        . " You answer with ONE JSON object and nothing else. The \"message\" field is what you say out loud."
        . " The \"action\" field is one action from the list, or \"none\". Choose an action only when it is what the player just asked for.\n</roleplay_instructions>";

    $bio = "$npc is the ferryman of $town, forty-one, born in a fishing village on the northern coast. He took the ferry over from his aunt after"
        . " the flood year and has run it since. He knows every sandbar between the falls and the delta, drinks little, talks less, and is owed money"
        . " by half the town. He dislikes the new toll clerk, likes the baker's daughter's bread and not her singing, and keeps a dog named Pike."
        . " He is honest about weather and dishonest about fish. He has never left the province and says he does not intend to.";
    $s[] = "<character>\n" . str_repeat($bio . ' ', 22) . "\n</character>";

    $actions = [
        'TravelTo' => 'Lead the way to a named place the two of you can walk to. "item" is the place.',
        'FollowPlayer' => 'Walk with the player until told otherwise.',
        'WaitHere' => 'Stay where you are.',
        'TradeItems' => 'Open your goods so the player can buy or sell.',
        'GiveItem' => 'Hand the player something you carry. "item" is what you hand over.',
        'TakeASeat' => 'Sit down at the nearest seat.',
        'Inspect' => 'Look at something nearby and say what you see. "item" is the thing.',
        'CastLight' => 'Make light so the two of you can see.',
        'EndConversation' => 'Stop talking and go back to your work. Only when the player is plainly finished.',
        'OpenGate' => 'Unlock and open the river gate. "item" is the gate.',
        'HireFerry' => 'Take the player across the water for the usual fare.',
        'ShowRoute' => 'Point out a route on the map. "item" is the destination.',
        'FetchRope' => 'Get the rope from the jetty locker.',
        'CallDog' => 'Whistle for Pike.',
        'None' => 'Say something and do nothing at all. This is the ordinary answer.',
    ];
    $a = [];
    foreach ($actions as $name => $desc) { $a[] = "- $name: $desc"; }
    $s[] = "<actions>\n" . implode("\n", $a) . "\n" . str_repeat("Choose at most one action per reply, and only when the player asked for it. An action with an empty message is never right. ", 22) . "\n</actions>";

    $people = ['Rulf the toll clerk, who counts twice and smiles once', 'Berit the baker, up since the fourth bell',
        'Sanne, a carter waiting for the tide', 'Odd Fisk, drunk and singing', 'a hound belonging to nobody',
        'two guards of the north watch, bored', 'Maelis, who reads the river stones', 'a pedlar with wet boots'];
    $near = [];
    foreach ($people as $p) { $near[] = "- $p. " . str_repeat('They have been standing about for a while and have nothing to do with the player right now. ', 6); }
    $s[] = "<nearby_actors>\n" . implode("\n", $near) . "\n</nearby_actors>";

    $facts = ["$inn is the inn on the square, three streets up from the jetty, with a green door.",
        'The river gate is shut at dusk and opened at the fourth bell.',
        'The falls are two days upriver and nobody takes a boat past them.',
        'The toll is four coppers a crossing, six with a cart.',
        'The north watch changes at noon and at midnight.',
        'The flood year took the old jetty and half the lower street.'];
    $k = [];
    foreach ($facts as $f) { $k[] = '- ' . $f . ' ' . str_repeat('This is common knowledge in the town and nobody would think it worth saying twice. ', 5); }
    $s[] = "<knowledge>\n" . implode("\n", $k) . "\n</knowledge>";

    $s[] = "<world>\nLocation: the jetty at $town. Time: late afternoon, the eleventh of Rain's Hand. Weather: wind off the water, no rain.\n</world>";

    $hist = [];
    for ($i = 1; $i <= 18; $i++) {
        $hist[] = "$player: " . ['Any word from upriver?', 'How is the water today?', 'Is the gate open?', 'Busy morning?'][$i % 4];
        $hist[] = "$npc: " . ['Nothing worth the telling.', 'Running high, but it will drop.', 'Shut till the bell.', 'Quiet enough.'][$i % 4];
    }
    $s[] = "<history>\n" . implode("\n", $hist) . "\n</history>";

    return implode("\n\n", $s);
}

/** CHIM's strict action schema, the same shape connector/openrouterjson.php sends. */
function latSchema(): array
{
    return ['type' => 'json_schema', 'json_schema' => ['name' => 'npc_reply', 'strict' => true, 'schema' => [
        'type' => 'object', 'additionalProperties' => false,
        'properties' => [
            'message' => ['type' => 'string', 'description' => 'What you say out loud, one or two short sentences.'],
            'action' => ['type' => 'string', 'description' => 'One action name from the list, or none.'],
            'target' => ['type' => 'string', 'description' => 'Who the action is aimed at, or an empty string.'],
            'item' => ['type' => 'string', 'description' => 'The action\'s one value, or an empty string.'],
        ],
        'required' => ['message', 'action', 'target', 'item'],
    ]]];
}

/** The player's line, and the one action that answers it. */
const LAT_SAY = 'Enough talking. Walk me up to the Gilded Flask, would you?';
function latCorrect(array $j): bool
{
    $act = strtolower(trim((string) ($j['action'] ?? '')));
    $val = strtolower((string) ($j['item'] ?? '') . ' ' . (string) ($j['target'] ?? ''));
    return $act === 'travelto' && str_contains($val, 'flask');
}

// ---------------------------------------------------------------- the owner's key (never printed)
function latKey(): string
{
    $out = (string) shell_exec("PGPASSWORD=dwemer psql -h localhost -U dwemer -d dwemer -A -t -c \"select api_key from core_api_badge where label ilike 'openrouter' and coalesce(api_key,'') <> '' limit 1\" 2>/dev/null");
    return trim($out);
}

/** OpenRouter's public price list (no key, no account data). [id => [prompt per token, completion per token]] */
function latPrices(array $ids): array
{
    $ch = curl_init('https://openrouter.ai/api/v1/models');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30]);
    $d = json_decode((string) curl_exec($ch), true);
    curl_close($ch);
    $out = [];
    foreach (($d['data'] ?? []) as $m) {
        if (in_array($m['id'] ?? '', $ids, true)) {
            $out[$m['id']] = [(float) ($m['pricing']['prompt'] ?? 0), (float) ($m['pricing']['completion'] ?? 0), (int) ($m['context_length'] ?? 0)];
        }
    }
    return $out;
}

/** The moment the first sentence of the "message" field is complete - what CHIM hands to TTS first. */
function latFirstSentence(string $acc): bool
{
    if (!preg_match('/"message"\s*:\s*"/', $acc, $m, PREG_OFFSET_CAPTURE)) { return false; }
    $rest = substr($acc, $m[0][1] + strlen($m[0][0]));
    $rest = str_replace(['\\"', '\\\\'], ['', ''], $rest);
    return (bool) preg_match('/[.!?](\s|$)|"/', $rest);
}

/** One streamed request. Returns timings, the reply and the provider's usage. */
function latRun(string $model, string $key, string $system, string $say): array
{
    $body = json_encode([
        'model' => $model,
        'messages' => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $say]],
        'stream' => true,
        'max_tokens' => 200,
        'response_format' => latSchema(),
        'reasoning' => ['effort' => 'none', 'exclude' => true],
        'usage' => ['include' => true],
    ]);
    $t0 = microtime(true);
    $r = ['ttft' => null, 'speech' => null, 'total' => null, 'text' => '', 'usage' => [], 'error' => '', 'provider' => '', 'reasoning' => 0];
    $acc = '';
    $raw = ''; // a provider that refuses the request answers with an ordinary JSON body, not an SSE stream
    $ch = curl_init('https://openrouter.ai/api/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $key,
            'X-Title: LoreRim Glue latency benchmark', 'HTTP-Referer: https://localhost/lorerim-glue'],
        CURLOPT_TIMEOUT => 120,
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_WRITEFUNCTION => static function ($ch, $chunk) use (&$r, &$acc, &$raw, $t0) {
            foreach (explode("\n", $chunk) as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, ':')) { continue; }
                if (!str_starts_with($line, 'data: ')) { $raw .= $line; continue; }
                $data = substr($line, 6);
                if ($data === '[DONE]') { continue; }
                $j = json_decode($data, true);
                if (!is_array($j)) { continue; }
                if (isset($j['error'])) { $r['error'] = (string) ($j['error']['message'] ?? 'error'); continue; }
                if (isset($j['provider']) && $r['provider'] === '') { $r['provider'] = (string) $j['provider']; }
                $delta = $j['choices'][0]['delta'] ?? [];
                if (isset($delta['reasoning']) && $delta['reasoning'] !== '') { $r['reasoning']++; }
                $c = (string) ($delta['content'] ?? '');
                if ($c !== '') {
                    if ($r['ttft'] === null) { $r['ttft'] = microtime(true) - $t0; }
                    $acc .= $c;
                    if ($r['speech'] === null && latFirstSentence($acc)) { $r['speech'] = microtime(true) - $t0; }
                }
                if (isset($j['usage'])) { $r['usage'] = $j['usage']; }
            }
            return strlen($chunk);
        },
    ]);
    curl_exec($ch);
    if (curl_errno($ch)) { $r['error'] = $r['error'] ?: curl_error($ch); }
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($r['error'] === '' && $acc === '' && $raw !== '') {
        $j = json_decode($raw, true);
        $r['error'] = 'HTTP ' . $code . ': ' . substr((string) (is_array($j) ? ($j['error']['message'] ?? $raw) : $raw), 0, 200);
    }
    $r['total'] = microtime(true) - $t0;
    $r['text'] = $acc;
    return $r;
}

function latMedian(array $v): float
{
    $v = array_values(array_filter($v, static fn($x) => $x !== null));
    if (!$v) { return 0.0; }
    sort($v);
    $c = count($v);
    return $c % 2 ? (float) $v[intdiv($c, 2)] : ((float) $v[$c / 2 - 1] + (float) $v[$c / 2]) / 2;
}

// ---------------------------------------------------------------- run
$system = latPrompt();
$approxTok = (int) ceil(strlen($system) / 4);
$prices = latPrices($models);
echo "LoreRim Glue - PT9 model benchmark\n";
echo 'synthetic prompt: ' . strlen($system) . " characters (~$approxTok tokens), strict JSON action schema, stream on, max_tokens 200\n";
echo 'player line: "' . LAT_SAY . "\"  ->  the one right answer is TravelTo / The Gilded Flask\n";
echo "models: " . count($models) . ", requests per model: $n\n";
echo $dry
    ? "MODE: DRY (the default) - nothing is sent and nothing is spent. Pass --live to run it for real.\n\n"
    : "MODE: LIVE - this run WILL spend money on the owner's OpenRouter key.\n\n";

$key = $dry ? 'dry' : latKey();
if ($key === '') { fwrite(STDERR, "no OpenRouter key found in core_api_badge - nothing was sent\n"); exit(2); }

$rows = [];
$spent = 0.0;
foreach ($models as $model) {
    $ttft = []; $speech = []; $total = []; $ok = 0; $bad = []; $prov = []; $pt = []; $ct = []; $think = 0;
    for ($i = 0; $i < $n && !$dry; $i++) {
        $r = latRun($model, $key, $system, LAT_SAY);
        if ($r['error'] !== '') { $bad[] = $r['error']; continue; }
        $ttft[] = $r['ttft']; $speech[] = $r['speech']; $total[] = $r['total'];
        if ($r['provider'] !== '') { $prov[$r['provider']] = true; }
        $j = json_decode(trim($r['text']), true);
        if (is_array($j) && isset($j['message'], $j['action']) && latCorrect($j)) { $ok++; }
        elseif (!is_array($j)) { $bad[] = 'not JSON'; }
        else { $bad[] = 'action=' . (string) ($j['action'] ?? '?') . ' item=' . (string) ($j['item'] ?? '?'); }
        $pt[] = (int) ($r['usage']['prompt_tokens'] ?? 0);
        $ct[] = (int) ($r['usage']['completion_tokens'] ?? 0);
        $think += (int) ($r['usage']['completion_tokens_details']['reasoning_tokens'] ?? 0) + $r['reasoning'];
        $spent += (float) ($r['usage']['cost'] ?? 0);
        // [0.5.0 reconciliation / verify D6] keep the SAMPLES, not just the medians: a table nobody can
        // re-check without paying again is not evidence. No reply text is kept - only its verdict.
        $raw[] = ['model' => $model, 'i' => $i, 'ttft' => $r['ttft'], 'speech' => $r['speech'], 'total' => $r['total'],
            'provider' => $r['provider'], 'json_ok' => is_array($j) && isset($j['message'], $j['action']) && latCorrect($j),
            'prompt_tokens' => (int) ($r['usage']['prompt_tokens'] ?? 0), 'completion_tokens' => (int) ($r['usage']['completion_tokens'] ?? 0),
            'cost' => (float) ($r['usage']['cost'] ?? 0)];
        usleep(300000); // be polite to the provider between requests
    }
    [$pp, $pc] = $prices[$model] ?? [0.0, 0.0];
    $rows[] = [
        'model' => $model,
        'ttft' => latMedian($ttft), 'speech' => latMedian($speech), 'total' => latMedian($total),
        'max' => $total ? max($total) : 0.0,
        'ok' => $ok, 'n' => count($total), 'bad' => array_slice(array_unique($bad), 0, 2),
        'prov' => implode('+', array_keys($prov)),
        'think' => $think,
        'per1k' => (LAT_REAL_PROMPT_TOKENS * $pp + LAT_REAL_COMPLETION_TOKENS * $pc) * 1000,
        'ptok' => (int) latMedian($pt), 'ctok' => (int) latMedian($ct),
    ];
}

printf("%-30s %4s %7s %7s %7s %7s %8s %6s %7s %-22s %s\n", 'model', 'n', 'ttft', 'speech', 'total', 'worst', '$/1k', 'json', 'ptok', 'provider', 'note');
foreach ($rows as $r) {
    printf("%-30s %4d %6.2fs %6.2fs %6.2fs %6.2fs %8s %3d/%-2d %7d %-22s %s\n", $r['model'], $r['n'], $r['ttft'], $r['speech'], $r['total'], $r['max'],
        '$' . number_format($r['per1k'], 2), $r['ok'], $r['n'], $r['ptok'], $r['prov'] ?: '-',
        ($r['think'] > 0 ? 'THINKS FIRST. ' : '') . implode(' | ', $r['bad']));
}
echo "\nttft = first token, speech = first finished sentence in \"message\" (what the player waits for), \$/1k = list price on a real CHIM turn ("
    . LAT_REAL_PROMPT_TOKENS . ' prompt / ' . LAT_REAL_COMPLETION_TOKENS . " completion tokens)\n";
echo "n is the number of SUCCESSFUL requests behind each median. At n=6 this table separates cost and\n"
    . "strict-JSON compliance; it does NOT separate two models a tenth of a second apart (verify D6).\n";
echo "ptok = the provider's own median prompt_tokens: the same characters tokenise differently per model.\n";
if ($rawOut !== '' && $raw) {
    if (@file_put_contents($rawOut, json_encode(['prompt_chars' => strlen($system), 'n' => $n, 'samples' => $raw], JSON_PRETTY_PRINT)) !== false) {
        echo "raw samples written to $rawOut\n";
    } else {
        fwrite(STDERR, "could not write $rawOut\n");
    }
}
printf($dry ? "DRY RUN: nothing was sent, \$%.4f spent.\n" : "this benchmark spent \$%.4f in total (provider's own usage figures)\n", $spent);
