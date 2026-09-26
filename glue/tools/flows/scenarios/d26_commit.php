<?php
// PHASE 2 d26 / d27 / d28 - two-step confirmation, persuade, bribe (design 7.3 rows 26, 27, 28).
require_once __DIR__ . '/../dlg_adapter.php';

/**
 * Build one NPC's list out of [text => index overrides].
 * [pt19 v1.0 / S1] THE LIST IS ON HIS SCREEN: the session stays OPEN (a visible vanilla menu he may click, voice-driven) -
 * the world every gate scenario built on this helper means by "a list the model picks a key from". $close = true gives the
 * cached ROOT of a closed session instead (the pre-LLM open then answers his next sentence first, S2.1 - d21 covers it).
 * [pt19 v1.0 / S3.3] on an install whose click route is PROVEN ($clicks = clicks_ok on the '*install*' row, 1 by default):
 * these scenarios test what the gate does after the first verified click. The stage rail (clicks_ok 0) has its own
 * scenario (d26b) and test_dialogue v24.
 */
function fxDlgCache(string $npc, array $spec, array $topicKv = [], array $layers = [], int $clicks = 1, bool $close = false): void
{
    fxDlgReset(fxDlgIndexFrom($spec), $layers);
    lrgDlgPut('*install*', ['clicks_ok' => $clicks]);
    fxSendSnapshot($npc, fxDlgSnap());
    $ent = [];
    $i = 0;
    foreach ($spec as $text => $over) {
        if (is_int($text)) { $text = (string) $over; }
        $ent[] = fxDlgEntry($i++, (string) $text, ['new' => (int) (($over['new'] ?? 0))]);
    }
    fxDlgTopics($npc, $topicKv, $ent);
    if ($close && (int) ($topicKv['layer'] ?? 0) === 0) {
        fxDlgEvent($npc, 'closed', ['sid' => (string) ($topicKv['sid'] ?? 's1'), 'why' => 'goodbye', 'pending' => 0]);
    }
}

fx_scenario('d26', 'commit: his own plain sentence IS the confirmation (S4.3); otherwise the first selection PARKS and is not muted, her naming question + a bare "yes" releases it (S4.4), a fuller answer releases it, a refusal un-parks, silence and the same words never confirm, 60 s expires it', function (FxT $t) {
    $npc = 'Sigrun Flowtest';
    $spec = [
        'I will kill him for you.' => ['scripted' => 1, 'flags' => ['goodbye' => 1]],
        'Let him go free.' => ['scripted' => 1, 'flags' => ['goodbye' => 1]],
        'I need more time.' => [],
    ];
    // the kill entry by TEXT, never by rank: these blocks turn on which entry is parked
    $killOf = static function (array $q): string {
        $at = array_search('I will kill him for you.', array_column($q['keys'], 'text'), true);
        return $at === false ? (string) array_key_first($q['keys']) : (string) array_keys($q['keys'])[$at];
    };
    $park = static function () use ($npc, $spec, $killOf): array {
        fxDlgCache($npc, $spec);
        lrgDlgPut($npc, ['parked' => null]);          // a clean slate: each block is about ONE park
        $q = fxDlgSay($npc, fxDlgSnap(), 'kill him');   // 2 tokens: not his plain sentence (S4.3 needs min(4, the line's))
        return ['q' => $q, 'w' => fxDlgLlm($npc, $killOf($q), 'You would kill him for me, then?')];
    };

    // ---- (1) S4.3: HIS OWN PLAIN SENTENCE IS STEP ONE - one utterance, no question
    fxDlgCache($npc, $spec);
    // his sentence IS the line (1.0, margin 0.46 over "Let him go free."); his sentence CARRYING the line with more words is
    // his choice too (0.907, margin 0.40); a paraphrase that never says it ("I will deal with him for you", 0.783) ends up
    // under the 0.25 margin (0.248) and parks - the floors are never lowered for a test line
    $q = fxDlgSay($npc, fxDlgSnap(), 'I will kill him for you.');
    $keys = $q['keys'];
    $t->must('a scripted + Goodbye entry is labelled as committing',
        str_contains((string) ($keys[$killOf($q)]['label'] ?? ''), 'commits'), json_encode($keys[$killOf($q)] ?? []));
    $t->must('the <business> rule asks her to QUOTE the choice in its own words (S4.4)',
        str_contains($q['volatile'], 'quoting the choice in its own words'), fx_short($q['volatile'], 400));
    fxDlgClearQueue();
    $GLOBALS['LAST_LLM_RESPONSE'] = ['action' => FX_DLG_DISPLAY, 'item' => $killOf($q)];
    $t->mustCap('dlg_json', 'his plain sentence: the pick WILL be emitted, so her line is muted (WillEmit = the gate)',
        fn() => lrgDlgTransformer('So be it.') === '', 'the transformer did not mute an explicit commit');
    $w0 = fxDlgLlm($npc, $killOf($q), 'So be it.');
    $t->must('"I will kill him for you." executes at ONCE (mode explicit)', count($w0) === 1 && fxDlgDo($w0[0]) === 'pick',
        json_encode(array_map('fxParam', $w0)));
    fxDlgCache($npc, $spec);
    lrgDlgPut($npc, ['parked' => null]);
    $qw = fxDlgSay($npc, fxDlgSnap(), 'I will kill him for you, you have my word.');
    $ww = fxDlgLlm($npc, $killOf($qw), 'So be it.');
    $t->must('...his sentence carrying the line with more words executes at once too (0.907, margin 0.40)',
        count($ww) === 1 && fxDlgDo($ww[0]) === 'pick', json_encode(array_map('fxParam', $ww)));
    fxDlgCache($npc, $spec);
    lrgDlgPut($npc, ['parked' => null]);
    $qp = fxDlgSay($npc, fxDlgSnap(), 'I will deal with him for you.');
    $wp = fxDlgLlm($npc, $killOf($qp), 'You would do that for me?');
    $t->must('...a paraphrase under the explicit margin (0.248 over the other line) PARKS instead', $wp === [],
        json_encode(array_map('fxParam', $wp)));

    // ---- (2) not his plain sentence: the FIRST selection parks, her question is heard
    $p = $park();
    $t->must('the FIRST selection of a non-explicit answer is not emitted', $p['w'] === [], json_encode(array_map('fxParam', $p['w'])));
    $t->must('the park is remembered with her question (parked.said)', str_contains((string) (fxDlgStateOf($npc)['parked']['said'] ?? ''), '?'),
        json_encode(fxDlgStateOf($npc)['parked'] ?? []));
    $GLOBALS['LAST_LLM_RESPONSE'] = ['action' => FX_DLG_NAME, 'item' => $killOf($p['q'])];
    $t->mustCap('dlg_json', 'the parking turn is NOT muted (her question must be heard)',
        fn() => lrgDlgTransformer('Are you certain?') === 'Are you certain?', 'the transformer muted a parked turn');

    // ---- (3) S4.4 THE BARE YES: her question named the line (a "?" within 60 s) -> "yes" releases
    $q2 = fxDlgSay($npc, fxDlgSnap(), 'Yes.');
    $w2 = fxDlgLlm($npc, $killOf($q2), '');
    $t->must('a bare "yes" to her naming question executes the parked line', count($w2) === 1 && fxDlgDo($w2[0]) === 'pick',
        json_encode(array_map('fxParam', $w2)));
    $t->must('the park was cleared', (array) (fxDlgStateOf($npc)['parked'] ?? []) === [], json_encode(fxDlgStateOf($npc)['parked'] ?? []));

    // ---- (4) model F27: "yes" answered in WORDS only (no key) - the parked line goes out with her reply, not muted
    $park();
    fxDlgSay($npc, fxDlgSnap(), 'Okay.');
    $w4 = fxDlgLlmNothing($npc, 'Then it is done.');
    $sel = array_values(array_filter($w4, static fn($l) => str_contains((string) $l, FX_DLG_ACT . '@')));
    $t->must('"okay" with no key from the model: the gate appends the parked pick (mode bare-yes)',
        count($sel) === 1 && fxDlgDo($sel[0]) === 'pick', json_encode(array_map('fxParam', $w4)));

    // ---- (5) another key replaces the park
    $p5 = $park();
    $first = (string) (fxDlgStateOf($npc)['parked']['norm'] ?? '');
    $q5 = fxDlgSay($npc, fxDlgSnap(), 'spare him');
    $spare = array_search('Let him go free.', array_column($q5['keys'], 'text'), true);
    $w5 = fxDlgLlm($npc, $spare === false ? 'T2' : array_keys($q5['keys'])[$spare], 'You would let him go?');
    $t->must('a different key replaces the park instead of releasing the old one',
        $w5 === [] && (string) (fxDlgStateOf($npc)['parked']['norm'] ?? '') === 'let him go free',
        'park is ' . (string) (fxDlgStateOf($npc)['parked']['norm'] ?? '') . ' ' . json_encode(array_map('fxParam', $w5)));
    // ...and when the other line is picked OUTRIGHT (his own sentence), the stale park goes with the click
    $park();
    $q5b = fxDlgSay($npc, fxDlgSnap(), 'Let him go free.');
    $spare = array_search('Let him go free.', array_column($q5b['keys'], 'text'), true);
    $w5b = fxDlgLlm($npc, $spare === false ? 'T2' : array_keys($q5b['keys'])[$spare], 'As you wish.');
    $t->must('another line picked outright (explicit) drops the stale park - a later "yes" can never release it',
        count($w5b) === 1 && str_contains(fxParam($w5b[0]), 'txt=Let him go free') && empty(fxDlgStateOf($npc)['parked']),
        json_encode([array_map('fxParam', $w5b), fxDlgStateOf($npc)['parked'] ?? null]));

    // ---- (6) 60 s expiry
    $park();
    fxAdvance(61);
    $q6 = fxDlgSay($npc, fxDlgSnap(), 'Yes, I am certain about that.');
    $w6 = fxDlgLlm($npc, $killOf($q6));
    $t->must('a park older than confirm.park_seconds does not release (it parks afresh)', $w6 === [], json_encode(array_map('fxParam', $w6)));

    // ---- (7) a single non-assent token is not a confirmation; a bare assent to a line she did NOT name is not either
    $park();
    $q7 = fxDlgSay($npc, fxDlgSnap(), 'Hmm');
    $w7 = fxDlgLlm($npc, $killOf($q7));
    $t->must('a single-token non-assent is refused as a confirmation', $w7 === [], json_encode(array_map('fxParam', $w7)));
    fxDlgCache($npc, $spec);
    lrgDlgPut($npc, ['parked' => null]);
    $q7b = fxDlgSay($npc, fxDlgSnap(), 'kill him');
    fxDlgLlm($npc, $killOf($q7b), 'The Jarl sits in Dragonsreach.');   // not a question, names nothing
    $q7c = fxDlgSay($npc, fxDlgSnap(), 'Yes');
    $w7c = fxDlgLlm($npc, $killOf($q7c));
    $t->must('"yes" after a line that named nothing (two commits on the layer) stays parked', $w7c === [], json_encode(array_map('fxParam', $w7c)));

    // ---- (A) the player says no IN WORDS - it must un-park, not commit
    $pA = $park();
    $t->must('it is parked before the refusal', (string) (fxDlgStateOf($npc)['parked']['norm'] ?? '') !== '',
        json_encode(fxDlgStateOf($npc)['parked'] ?? []));
    $markA = fxDlgLogMark();
    $qA2 = fxDlgSay($npc, fxDlgSnap(), 'No, forget it, I have changed my mind entirely.');
    $wA = fxDlgLlm($npc, $killOf($qA2));
    $t->must('a refusal in words does NOT release the park', $wA === [], json_encode(array_map('fxParam', $wA)));
    $t->must('...and the park is dropped rather than left armed for the next turn',
        (array) (fxDlgStateOf($npc)['parked'] ?? []) === [], json_encode(fxDlgStateOf($npc)['parked'] ?? []));
    $t->must('...and the log says a refusal was heard', str_contains(fxDlgLogFrom($markA), 'refuses'), fx_short(fxDlgLogFrom($markA), 300));
    // "yes, but not now" is a refusal too (S4.4: the refusal is checked FIRST)
    $park();
    fxDlgSay($npc, fxDlgSnap(), 'Yes, but not now.');
    $wA3 = fxDlgLlmNothing($npc, 'As you like.');
    $t->must('"yes, but not now" un-parks (the refusal wins over the leading yes)',
        !array_filter($wA3, static fn($l) => str_contains((string) $l, ';do=pick;')) && (array) (fxDlgStateOf($npc)['parked'] ?? []) === [],
        json_encode([array_map('fxParam', $wA3), fxDlgStateOf($npc)['parked'] ?? []]));

    // ---- (B) no player turn at all: a rechat / lrg_dlgtalk request must never be a confirmation
    $pB = $park();
    $t->must('it is parked before the poll', (string) (fxDlgStateOf($npc)['parked']['norm'] ?? '') !== '',
        json_encode(fxDlgStateOf($npc)['parked'] ?? []));
    fxDlgClearQueue();
    $t->must('lrg_dlgtalk is answered pre-lock and costs nothing (session.talk_again = false, S2.1)', fxDlgTalk($npc) === 'handled');
    fxDlgSay($npc, fxDlgSnap(), 'again', 'rechat');
    $wB = fxDlgLlm($npc, $killOf($pB['q']));
    $t->must('silence is not a confirmation: a non-speech turn executes nothing', $wB === [] && fxDlgQueue() === [],
        json_encode([array_map('fxParam', $wB), fxDlgQueue()]));
    $t->must('...and it is still parked, waiting for him to actually answer',
        (string) (fxDlgStateOf($npc)['parked']['norm'] ?? '') !== '', json_encode(fxDlgStateOf($npc)['parked'] ?? []));

    // ---- (C) the same sentence arriving again is not a second answer either
    $pC = $park();
    $qC2 = fxDlgSay($npc, fxDlgSnap(), 'kill him');
    $wC = fxDlgLlm($npc, $killOf($qC2));
    $t->must('the same words repeated are not a confirmation of themselves', $wC === [], json_encode(array_map('fxParam', $wC)));

    // ---- (D) a real, different, affirmative answer still executes (so the refusals cannot pass by breaking the release)
    $park();
    $qD2 = fxDlgSay($npc, fxDlgSnap(), 'Yes, I am certain. Do it.');
    $wD = fxDlgLlm($npc, $killOf($qD2));
    $t->must('a real spoken agreement still executes on the second selection', count($wD) === 1 && fxDlgDo($wD[0]) === 'pick',
        json_encode(array_map('fxParam', $wD)));
});

fx_scenario('d26b', '[pt19 v1.0 / S3.3] the stage rail: before this install\'s first verified click only an indexed plain line is clicked; the prompt says so ONCE, in her words, and the corner note once per session; the first ok result lifts it', function (FxT $t) {
    $GLOBALS['LRG_DLG_TEST_CFG'] = ['session.stage_rail' => true];   // [v1.0.1] the rail ships OFF; this scenario tests the rail itself
    $npc = 'Sigrun Flowtest';
    $spec = [
        'I will kill him for you.' => ['scripted' => 1, 'flags' => ['goodbye' => 1]],
        'Tell me about the war.' => [],
        'I need more time.' => [],
    ];
    fxDlgCache($npc, $spec, [], [], 0);
    $q = fxDlgSay($npc, fxDlgSnap(), 'tell me about the war');
    $t->must('clicks_ok 0: ONE rail line naming the keys that may be picked, no machinery word',
        (bool) preg_match('/Until he has asked me something simple I can only pick (T\d(, )?)+; for anything else say: I have not picked a line for you yet/', $q['volatile'])
        && !preg_match('/\b(menu|click|gate)\b/i', (string) (preg_match('/Until he has asked[^\n]*/', $q['volatile'], $mm) ? $mm[0] : '')),
        fx_short($q['volatile'], 500));
    $plain = array_search('Tell me about the war.', array_column($q['keys'], 'text'), true);
    $w = fxDlgLlm($npc, array_keys($q['keys'])[$plain === false ? 1 : $plain]);
    $t->must('...an indexed plain line IS picked under the rail', count($w) === 1 && fxDlgDo($w[0]) === 'pick', json_encode(array_map('fxParam', $w)));
    $q2 = fxDlgSay($npc, fxDlgSnap(), 'I will kill him for you, you have my word.');
    $kill = array_search('I will kill him for you.', array_column($q2['keys'], 'text'), true);
    $markR = fxDlgLogMark();
    $w2 = fxDlgLlm($npc, array_keys($q2['keys'])[$kill === false ? 0 : $kill], 'So be it.');
    $sel = array_values(array_filter($w2, static fn($l) => str_contains((string) $l, ';do=pick;')));
    $note = array_values(array_filter($w2, static fn($l) => str_contains((string) $l, ';do=noop;') && str_contains((string) $l, ';note=')));
    $t->must('...a scripted commit is NOT picked (even said plainly), the log says "gate: stage rail"',
        $sel === [] && str_contains(fxDlgLogFrom($markR), 'gate: stage rail'), json_encode(array_map('fxParam', $w2)));
    $t->must('...the corner note rides once for the session', count($note) === 1, json_encode(array_map('fxParam', $w2)));
    fxDlgEvent($npc, 'result', ['sid' => 's1', 'gen' => 0, 'cid' => 'dr26', 'x' => 'abcdef0126', 'pos' => 1, 'i' => 101,
        'kind' => 'plain', 'ok' => 1], 'Tell me about the war.');
    $t->must('an ok result counts: clicks_ok 1 on the *install* row', (int) (fxDlgStateOf('*install*')['clicks_ok'] ?? 0) === 1,
        json_encode(fxDlgStateOf('*install*')));
    $q3 = fxDlgSay($npc, fxDlgSnap(), 'I will kill him for you, you have my word.');
    $t->must('...and the rail line is gone', !str_contains($q3['volatile'], 'Until he has asked me'), fx_short($q3['volatile'], 300));
    fxDlgEvent($npc, 'calib', ['v' => 1, 'ref' => '00000000', 'src' => 'forget', 'gate' => 0, 'reset' => 1, 'miss' => 'cm rm fam st'],
        'cm:0,rm:0,fam:0,st:0,route:0');
    $t->must('ev=calib reset=1 (Forget everything it learned) closes it again: clicks_ok 0', (int) (fxDlgStateOf('*install*')['clicks_ok'] ?? -1) === 0,
        json_encode(fxDlgStateOf('*install*')));
});

fx_scenario('d27', 'persuade: the label comes from the INDEX kind although the text says (Intimidate); the min-words rule; a FAILED result is stated as fact and the line is quoted; the retry is suppressed until sp changes; suppression is OFF for compound', function (FxT $t) {
    $npc = 'Hroda Flowtest';
    // the live list shows the topic's FAILURE variant (the engine already evaluated the check when it built
    // the list). That INFO implements no check of its own, so kind='' and topic_kind carries the topic's.
    $text = 'You will tell me what I want to know. (Intimidate)';
    $spec = [$text => ['kind' => '', 'topic_kind' => 'persuade', 'variant' => 'failure',
        'resp' => 'I will tell you nothing.'], 'Never mind.' => []];
    fxDlgCache($npc, $spec);
    $q = fxDlgSay($npc, fxDlgSnap(), 'Be reasonable and hear me out for one moment.');
    $t->must('the label is [persuasion attempt], from the index kind, not from the (Intimidate) tag',
        str_contains($q['volatile'], '[persuasion attempt]'), fx_short($q['volatile'], 300));
    $t->must('the shown FAILURE variant is still recognised as part of that check',
        (string) ($q['turn']['entries'][0]['kind'] ?? '') === 'persuade'
        && (int) ($q['turn']['entries'][0]['own_check'] ?? 1) === 0, json_encode($q['turn']['entries'][0] ?? []));
    $t->must('the leading tag is dropped from the shown text but the rest is verbatim',
        str_contains($q['volatile'], 'You will tell me what I want to know.'), fx_short($q['volatile'], 300));

    // the min-words rule
    $q2 = fxDlgSay($npc, fxDlgSnap(), 'Talk');
    $w2 = fxDlgLlm($npc, array_keys($q2['keys'])[0]);
    $t->must('a three-word voice attempt is refused', $w2 === [], json_encode(array_map('fxParam', $w2)));

    // a failed result is stated as FACT, with the real line quoted
    fxDlgEvent($npc, 'result', ['sid' => 's1', 'gen' => 0, 'cid' => 'dr01', 'x' => 'abcdef0123', 'pos' => 0,
        'i' => 100, 'kind' => 'persuade', 'ok' => 1, 'dP' => 0, 'dB' => 0, 'dI' => 0, 'gold' => 0,
        'bribed' => 0, 'intim' => 0, 'sp' => 30, 'wis' => 0], $text);
    $q3 = fxDlgSay($npc, fxDlgSnap(), 'So?');
    $t->must('<what_just_happened> is injected once', str_contains($q3['volatile'], '<what_just_happened>'),
        fx_short($q3['volatile'], 400));
    $t->must('the result is stated as fact, never as a rule or a number',
        !preg_match('/\b(threshold|speechcraft \d|you would have needed)\b/i', $q3['volatile']), fx_short($q3['volatile'], 300));
    $q4 = fxDlgSay($npc, fxDlgSnap(), 'So?');
    $t->must('it is told exactly once', !str_contains($q4['volatile'], '<what_just_happened>'), fx_short($q4['volatile'], 200));

    // the retry is suppressed while nothing relevant changed
    $q5 = fxDlgSay($npc, fxDlgSnap(), 'Be reasonable and hear me out for one moment.');
    $key = array_search('You will tell me what I want to know.', array_column($q5['keys'], 'text'), true);
    $keyName = $key === false ? 'T1' : array_keys($q5['keys'])[$key];
    $w5 = fxDlgLlm($npc, $keyName);
    $t->must('a failed attempt is not re-clickable while nothing changed', $w5 === [], json_encode(array_map('fxParam', $w5)));
    // ... and it IS re-clickable once Speech moved
    fxDlgEvent($npc, 'facts', ['ref' => '0x00012345', 'sp' => 60, 'lvl' => 12, 'perk' => '-', 'wis' => 0]);
    $q6 = fxDlgSay($npc, fxDlgSnap(), 'Be reasonable and hear me out for one moment.');
    $w6 = fxDlgLlm($npc, array_keys($q6['keys'])[$key === false ? 0 : $key]);
    $t->must('once Speech moved the attempt is allowed again', count($w6) === 1, json_encode(array_map('fxParam', $w6)));

    // suppression is OFF for a compound entry (a weapon skill passes INSTEAD of the check)
    $spec2 = ['Malacath has a greater destiny for you. (Persuade)' => ['kind' => 'persuade',
        'topic_kind' => 'persuade', 'variant' => 'failure', 'compound' => 1], 'Never mind.' => []];
    fxDlgCache($npc, $spec2);
    fxDlgEvent($npc, 'result', ['sid' => 's1', 'gen' => 0, 'cid' => 'dr02', 'x' => 'abcdef0124', 'pos' => 0,
        'i' => 100, 'kind' => 'persuade', 'ok' => 1, 'sp' => 30, 'wis' => 0], 'Malacath has a greater destiny for you. (Persuade)');
    $q7 = fxDlgSay($npc, fxDlgSnap(), 'Be reasonable and hear me out for one moment.');
    $w7 = fxDlgLlm($npc, array_keys($q7['keys'])[0]);
    $t->must('retry suppression and scoff-first are OFF for a compound entry', count($w7) === 1,
        json_encode(array_map('fxParam', $w7)));
});

fx_scenario('d28', 'bribe: the price is told and the first selection parks; agreed -> executed with cost; pg < N never; an offer below N never; the gold actions are hidden on that turn', function (FxT $t) {
    $npc = 'Brenna Flowtest';
    $text = 'Perhaps this will change your mind. (137 gold)';
    $spec = [$text => ['kind' => 'bribe', 'variant' => 'success', 'cost' => 137], 'Never mind.' => []];
    fxDlgCache($npc, $spec, ['pg' => 412, 'bamt' => 137]);
    $q = fxDlgSay($npc, fxDlgSnap(['pgold' => 412]), 'Here, take this and look the other way.');
    $t->must('the label names the real price and what the player has',
        (bool) preg_match('/\[bribe: costs 137 septims; the player has \d+\]/', $q['volatile']), fx_short($q['volatile'], 300));
    $t->must('GiveGoldTo and TakeGoldFromPlayer are hidden while a priced entry is listed',
        !in_array('GiveGoldTo', $q['enabled'], true) && !in_array('TakeGoldFromPlayer', $q['enabled'], true),
        implode(',', $q['enabled']));
    fxDlgClearQueue();
    $w = fxDlgLlm($npc, array_keys($q['keys'])[0], 'A hundred and thirty-seven, and not a septim less.');
    $t->must('nothing is executed on the first selection: her price IS the two-step', $w === [],
        json_encode(array_map('fxParam', $w)));
    $q2 = fxDlgSay($npc, fxDlgSnap(['pgold' => 412]), 'Agreed, a hundred and thirty-seven septims it is.');
    $w2 = fxDlgLlm($npc, array_keys($q2['keys'])[0], '');
    $t->must('after the player agrees it executes', count($w2) === 1 && fxDlgDo($w2[0]) === 'pick',
        json_encode(array_map('fxParam', $w2)));
    $t->must('cost carries the real price', count($w2) === 1 && (string) (fxDlgKv($w2[0])['cost'] ?? '') === '137',
        count($w2) ? fxParam($w2[0]) : '-');
    $t->must('kind=bribe travels with it', count($w2) === 1 && (string) (fxDlgKv($w2[0])['kind'] ?? '') === 'bribe',
        count($w2) ? fxParam($w2[0]) : '-');

    // the player cannot pay -> never, at any step
    fxDlgCache($npc, $spec, ['pg' => 50, 'bamt' => 137]);
    $q3 = fxDlgSay($npc, fxDlgSnap(['pgold' => 50]), 'Agreed, a hundred and thirty-seven septims it is.');
    $t->must('the label says the player cannot pay', str_contains($q3['volatile'], 'cannot pay'), fx_short($q3['volatile'], 260));
    $w3 = fxDlgLlm($npc, array_keys($q3['keys'])[0]);
    $t->must('pg < N is never clicked', $w3 === [], json_encode(array_map('fxParam', $w3)));

    // an offer BELOW her price is never clicked either
    fxDlgCache($npc, $spec, ['pg' => 412, 'bamt' => 137]);
    $q4 = fxDlgSay($npc, fxDlgSnap(['pgold' => 412]), 'I will give you 50 gold and that is generous.');
    $w4 = fxDlgLlm($npc, array_keys($q4['keys'])[0]);
    $t->must('an amount below the real price is never clicked', $w4 === [], json_encode(array_map('fxParam', $w4)));
});
