<?php
// PHASE 2 d68 - [pt19 v1.0.1 / S6.2, gate B] THE BOUNDED BONUS: after a real reward, inside the window, the player asks for more
// in his own words; a real Speech check runs; the transfer is do=award;...;give=N out of HER purse (never CHIM's GiveGoldTo,
// which mints gold from a short purse). Gate A ships with checks.reward.enabled = false, so this scenario is PENDING until
// gate B switches it on (spec 2.4) - and PENDING, not FAIL, while a function it needs is not delivered. Every sentence is the
// PLAYER's. Each outcome is PINNED, never merely allowed (reviewer pt19c-E, game P4): a pass gives exactly one award of N
// septims and tells her that N; a failed check, an empty purse and a second ask give none, and the fact she is handed says so -
// she is never told she adds septims she does not. And her WORDS are judged too (reviewer pt19c-E round 2, game P4): after a
// failed check the model's "Very well - a hundred septims, from my own purse." is a septims promise nothing grants - the never-false
// rail's `reward` class (lib/lrg_replies.php, Lane B) must reject it before it is spoken, and on a pass a sum other than N likewise.
require_once __DIR__ . '/../dlg_adapter.php';

/** A world where Balgruuf's reward was just given by a driven click: the reward window is open, the talk has closed, and his
 *  Speech ($sp) is on the facts line. $gold = HER purse (the snapshot). Returns the npc name. */
function fx68World(array $rows, string $reward, int $sp, string $gold): string
{
    $npc = 'Balgruuf Flowtest';
    fxReset();
    fxDlgReset($rows);
    lrgDlgPut('*install*', ['clicks_ok' => 1]);
    fxSendSnapshot($npc, fx68Snap($gold));
    fxDlgEvent($npc, 'facts', ['q' => 'MQ104', 'sp' => (string) $sp]);
    fxDlgTopics($npc, ['sid' => 'b1', 'gen' => 1, 'layer' => 1], [fxDlgEntry(0, $reward)]);
    fxDlgEvent($npc, 'result', ['sid' => 'b1', 'gen' => 1, 'cid' => 'rw1', 'x' => 'abcdef0999', 'pos' => 0, 'i' => 100, 'kind' => 'plain', 'ok' => 1,
        'gold' => 0, 'auto' => 0], $reward);
    // the reward line was the layer's last: the talk closes (left open, the next turn re-arms that single and no check runs at all);
    // his Speech arrives again with the facts (lrg_topics carried its own sp)
    fxDlgEvent($npc, 'closed', ['sid' => 'b1', 'why' => 'goodbye', 'pending' => 0, 'layer' => 1]);
    fxDlgEvent($npc, 'facts', ['q' => 'MQ104', 'sp' => (string) $sp]);
    fxAdvance(10);
    return $npc;
}

/** Her snapshot with HER purse = $gold (fxDlgSnap's own defaults win its array union, so gold= is set here, not through it). */
function fx68Snap(string $gold): array
{
    return fxSnap(['pgold' => '300', 'pspeech' => '30', 'gold' => $gold]);
}

/**
 * CHIM's validator (VALIDATE_LLM_OUTPUT_FNCT = lrgNeValidate, which runs the never-false rail first) on ONE reply of hers, in
 * production's order: the ask turn is the current one (after fxDlgSay), the stream is judged BEFORE the post-gate. The reply is
 * the object the JSON connector decodes per chunk, with every key of CHIM's schema and `message` not last, so it is complete (flow
 * 31 / d66_purchase's shape). Returns ['ok' => false when the stream is rejected, 'reject' => LRG_NE_REJECT, 'log' => the
 * never-false log lines]. Leaves no seam behind for the post-gate.
 */
function fx68Judge(string $npc, string $line): array
{
    $keys = ['character', 'listener', 'message', 'mood', 'action', 'target', 'item', 'amount'];
    $props = [];
    foreach ($keys as $k) { $props[$k] = ['type' => $k === 'amount' ? 'integer' : 'string', 'description' => $k]; }
    $GLOBALS['structuredOutputTemplate'] = ['type' => 'json_schema', 'json_schema' => ['name' => 'response', 'strict' => true,
        'schema' => ['type' => 'object', 'properties' => $props, 'required' => $keys, 'additionalProperties' => false]]];
    unset($GLOBALS['LRG_NE_REJECT'], $GLOBALS['LRG_NE_DONE'], $GLOBALS['LRG_NF_SHAPE']);
    $GLOBALS['LRG_NF_SEEN'] = [];
    $mark = fxDlgLogMark();
    $GLOBALS['LAST_LLM_RESPONSE'] = ['character' => $npc, 'listener' => 'Testplayer', 'message' => $line, 'mood' => 'calm',
        'action' => 'Talk', 'target' => 'Testplayer', 'item' => '', 'amount' => 1];
    $ok = (bool) lrgNeValidate('');
    $out = ['ok' => $ok, 'reject' => (array) ($GLOBALS['LRG_NE_REJECT'] ?? []),
        'log' => implode("\n", array_values(array_filter(fxDlgLogLines($mark), static fn($l) => str_contains($l, 'never-false'))))];
    unset($GLOBALS['LRG_NE_REJECT'], $GLOBALS['LRG_NF_SHAPE'], $GLOBALS['structuredOutputTemplate'], $GLOBALS['LAST_LLM_RESPONSE']);
    $GLOBALS['LRG_NF_SEEN'] = [];
    return $out;
}

/** His ask inside the window: the turn (check + her pre-LLM facts) and every award the reply (D1, ONE post-gate run - as in
 *  production) and the D2 queue carry, keyed by x (the driver de-dups D1 / D2 by x: one x = one transfer). $herLine (or a
 *  callable of the turn's check) is her reply: judged by the never-false rail first, then through the post-gate; each of $judge
 *  (the same shapes) is a reply the model MIGHT have given instead - judged by the rail on this turn only. */
function fx68Ask(string $npc, string $gold, string $ask, $herLine, array $judge = []): array
{
    fxDlgClearQueue();
    $q = fxDlgSay($npc, fx68Snap($gold), $ask);
    $c = (array) (($q['turn'] ?? [])['check'] ?? []);
    $herLine = is_callable($herLine) ? (string) $herLine($c) : (string) $herLine;
    $nf = [];
    foreach (array_merge($judge, [$herLine]) as $j) {
        $j = is_callable($j) ? (string) $j($c) : (string) $j;
        $nf[$j] = fx68Judge($npc, $j);
    }
    $out = array_values((array) fxDlgLlmNothing($npc, $herLine));
    $lines = array_merge(array_values(array_filter($out, static fn($l) => str_contains((string) $l, ';do=award;'))),
        array_values(array_filter(array_map(static fn($r) => (string) ($r['action'] ?? ''), fxDlgQueue()), static fn($l) => str_contains($l, ';do=award;'))));
    $byX = [];
    foreach ($lines as $l) { $kv = fxDlgKv($l); $byX[(string) ($kv['x'] ?? '')] = (int) ($kv['give'] ?? 0); }
    return ['q' => $q, 'check' => $c, 'out' => $out, 'awards' => $byX, 'her' => $herLine, 'nf' => $nf,
        'talk' => preg_match('~<reward_talk>(.*?)</reward_talk>~s', (string) $q['volatile'], $m) ? trim($m[1]) : ''];
}

/** Did the never-false rail reject this reply as a false bonus (class reward, with $fact), and log it as rejected? */
function fx68Rejected(array $j, string $npc, string $fact): bool
{
    return !$j['ok'] && (($j['reject']['why'] ?? '') === 'false claim') && (($j['reject']['class'] ?? '') === 'reward')
        && str_contains((string) ($j['reject']['fact'] ?? ''), $fact)
        && str_contains($j['log'], 'never-false npc=' . $npc . ' role=reward class=reward') && str_contains($j['log'], 'verdict=rejected');
}

fx_scenario('d68', '[pt19 v1.0.1 / S6.2 - gate B] the bounded bonus in septims: only inside a reward window, only on his own ask, a real check, give=N = min(named, her purse, a day of her wage, 500) out of her purse, once per quest; outside the window a haggle is no check; a failed check, an empty purse or a second ask give nothing and she is told so', function (FxT $t) {
    fxDlgReset();
    if (empty(lrgDlgCfg('checks.reward.enabled', false))) {
        $t->pending('gate B: the bounded bonus (S6.2)', 'checks.reward.enabled is false - v1.0.1 switches it on after one green evening (do=award and ev=result have run once in game; spec 2.4)');
        return;
    }
    // the never-false rail lives in lib/lrg_replies.php, which functions.php / context_pre.php load in production
    if (is_file(Fx::$pluginDir . '/lib/lrg_replies.php')) { require_once Fx::$pluginDir . '/lib/lrg_replies.php'; }
    $need = ['lrgDlgRewardWindow', 'lrgDlgNamedAmount', 'lrgDlgCheckKind', 'lrgDlgCheck', 'lrgDlgRewardAmount', 'lrgDlgRewardTalk',
        'lrgNeValidate', 'lrgNfValidate'];
    $missing = array_values(array_filter($need, static fn($f) => !function_exists($f)));
    if ($missing) {
        $t->pending('gate B: the bounded bonus (S6.2)', 'not delivered: ' . implode('(), ', $missing) . '()');
        return;
    }
    $reward = 'I killed the dragon. I think I deserve a reward.';
    $rows = fxDlgIndexFrom([$reward => ['toplevel' => 0, 'quest' => 'MQ104', 'journal' => 1, 'topic' => 'MQ104BalgruufOutroA3',
        'resp' => 'You have my thanks. Take this, as a token of my gratitude.']]);
    $ask = 'I deserve more than this, a hundred septims';
    $js = static fn(array $r): string => json_encode(['check' => array_intersect_key($r['check'], array_flip(['kind', 'result', 'why', 'reward', 'give', 'award', 'mem'])),
        'awards' => $r['awards'], 'talk' => $r['talk'],
        'never_false' => array_map(static fn($j) => ['ok' => $j['ok'], 'class' => $j['reject']['class'] ?? '', 'fact' => $j['reject']['fact'] ?? '',
            'log' => fx_short($j['log'], 200)], $r['nf'] ?? [])]);

    // 1. outside any reward window a haggle is no check (a merchant haggle is never turned into one)
    $npc = 'Balgruuf Flowtest';
    fxReset();
    fxDlgReset($rows);
    lrgDlgPut('*install*', ['clicks_ok' => 1]);
    fxSendSnapshot($npc, fx68Snap('800'));
    fxDlgEvent($npc, 'facts', ['q' => 'MQ104', 'sp' => '100']);
    $r = fx68Ask($npc, '800', $ask, 'The reward is what it is.');
    $t->must('outside the window: "' . $ask . '" runs no reward check and awards nothing', $r['awards'] === [] && empty($r['check']['reward']), $js($r));

    // 2. a PASS (Speech 100): exactly ONE do=award (one x, D1 and D2 de-dup by it) of N septims, 0 < N <= 100 (his named sum),
    //    <= her purse, <= 500; the fact she is handed names the same N; CHIM's GiveGoldTo never carries it
    $npc = fx68World($rows, $reward, 100, '800');
    $t->must('a driven click on the reward row opens the reward window', !empty(lrgDlgRewardWindow($npc)));
    // her reply names the N the check granted; the reply she might have given instead names another sum (N + 100)
    $sayN = static fn(int $n): string => 'Very well - ' . $n . ' septims, from my own purse.';
    $r = fx68Ask($npc, '800', $ask, static fn(array $c) => $sayN((int) ($c['give'] ?? 0)), [static fn(array $c) => $sayN((int) ($c['give'] ?? 0) + 100)]);
    $give = $r['awards'] ? (int) array_values($r['awards'])[0] : 0;
    $t->must('inside the window, Speech 100: a real persuade check at reward stakes PASSES', ($r['check']['result'] ?? '') === 'pass' && !empty($r['check']['reward']), $js($r));
    $t->must('...and exactly ONE do=award;...;give=N goes out, 0 < N <= 100 (his named sum) <= her purse (800) <= 500',
        count($r['awards']) === 1 && $give > 0 && $give <= 100, $js($r));
    $t->must('...and the fact she is handed names the same ' . $give . ' septims (she says what really moves)',
        $give > 0 && str_contains($r['talk'], 'you add ' . $give . ' septims'), $js($r));
    $t->must('...and CHIM\'s GiveGoldTo never carries it (it mints gold from a short purse)',
        !array_filter($r['out'], static fn($l) => stripos((string) $l, 'GiveGoldTo') !== false), json_encode($r['out']));
    $t->must('...and her words naming that N ("' . $sayN($give) . '") pass the never-false rail (a true bonus is spoken)',
        $give > 0 && !empty($r['nf'][$sayN($give)]['ok']), $js($r));
    $t->must('...while a reply naming ANOTHER sum ("' . $sayN($give + 100) . '") is REJECTED before it is spoken (never-false, class reward, "the bonus is '
        . $give . ' septims", logged verdict=rejected) - Lane B\'s reward class (lib/lrg_replies.php)',
        $give > 0 && isset($r['nf'][$sayN($give + 100)]) && fx68Rejected($r['nf'][$sayN($give + 100)], $npc, 'the bonus is ' . $give . ' septims'), $js($r));
    // 3. ONCE per quest: a second ask in the same window gives nothing, and she is told the reward is fixed
    fxAdvance(10);
    $r = fx68Ask($npc, '800', 'I deserve more than this, fifty septims', 'I have given what I will.');
    $t->must('once per quest: a second ask in the same window awards NOTHING, and her fact says the reward is fixed (no promise)',
        $r['awards'] === [] && ($r['check']['result'] ?? '') === 'fixed' && str_contains($r['talk'], 'The reward is fixed'), $js($r));

    // 4. a FAILED check (Speech 5): no award, and her fact says he did not move her - never "you add N septims". Her REPLY is the
    //    model ignoring that fact (reviewer round 2, game P4): "Very well - a hundred septims, from my own purse." promises septims
    //    nothing grants. The post-gate sends no award for it (her words are not an action) - and the never-false rail must stop
    //    the words themselves; "I'll add a hundred septims on top." (the shape the reward class was written for) is the control.
    $npc = fx68World($rows, $reward, 5, '800');
    $false = 'Very well - a hundred septims, from my own purse.';
    $control = "I'll add a hundred septims on top.";
    $r = fx68Ask($npc, '800', $ask, $false, [$control]);
    $t->must('Speech 5: the check FAILS, no do=award goes out, and her fact says he did not move her (nothing promised)',
        ($r['check']['result'] ?? '') === 'fail' && $r['awards'] === [] && str_contains($r['talk'], 'He did not move you')
        && !str_contains($r['talk'], 'you add'), $js($r));
    $t->must('...and her false promise "' . $false . '" moves no gold: no do=award, no GiveGoldTo',
        $r['awards'] === [] && !array_filter($r['out'], static fn($l) => stripos((string) $l, 'GiveGoldTo') !== false), json_encode($r['out']));
    $t->must('...and "' . $false . '" is REJECTED before it is spoken (never-false, class reward, "no bonus was granted", logged verdict=rejected) - never false: she promises no septims the check did not grant (Lane B\'s reward class, lib/lrg_replies.php)',
        fx68Rejected($r['nf'][$false], $npc, 'no bonus was granted'), $js($r));
    $t->must('...as "' . $control . '" is (the rail runs on this turn - the control)',
        fx68Rejected($r['nf'][$control], $npc, 'no bonus was granted'), $js($r));

    // 5. an EMPTY purse: no check at all, no award, and her fact says she carries no coin and must never promise it later
    $npc = fx68World($rows, $reward, 100, '0');
    $r = fx68Ask($npc, '0', $ask, 'I have nothing to give you.');
    $t->must('her purse is empty: no award, and her fact says she carries no coin and never promises to pay later',
        ($r['check']['result'] ?? '') === 'nocoin' && $r['awards'] === [] && str_contains($r['talk'], 'You carry no coin')
        && str_contains($r['talk'], 'never promise'), $js($r));

    // 6. the cap: a thousand septims asked of a purse of 40 -> at most 40 (min(named, purse, a day's wage, 500))
    $npc = fx68World($rows, $reward, 100, '40');
    $r = fx68Ask($npc, '40', 'I deserve more than this, a thousand septims', 'Here - all I carry.');
    $give = $r['awards'] ? (int) array_values($r['awards'])[0] : 0;
    $t->must('the cap: a thousand septims asked, her purse 40 -> one award of 0 < N <= 40, and her fact names that N',
        count($r['awards']) === 1 && $give > 0 && $give <= 40 && str_contains($r['talk'], 'you add ' . $give . ' septims'), $js($r));
});
