<?php
// PHASE 2 d42 / d43 - HOW WIDE IS THE FREE-CONVERSATION CHECK NET?
//
// Owner addendum 5b/7 asks for persuasion, deception and intimidation "fully working in any situation".
// The 0.4.0 review found that two of the owner's own three examples produced no check at all and that
// recall on natural phrasings was 6 of 18, because recognition rested on ~30 literal phrases, the
// negation blocker threw away every lie ("that bounty is not mine", "I have never set foot in Riften")
// and there was no haggling kind. These two scenarios are the regression fence for that fix: they are
// written in the OWNER'S OWN words, not in the patterns' words, so widening the patterns to fit a
// sentence would show up here as a sentence that is no longer covered.
require_once __DIR__ . '/../dlg_adapter.php';

/** One free-conversation turn with NO engine entry at all: the check path, and nothing else. */
function fxChkTurn(string $npc, string $line, int $sp = 40): array
{
    fxDlgReset();                       // empty index = no engine entry can win
    fxSendSnapshot($npc, fxDlgSnap(['pspeech' => (string) $sp]));
    fxDlgEvent($npc, 'facts', ['ref' => '0x00012345', 'sp' => $sp, 'lvl' => 14, 'perk' => '-', 'wis' => 1,
        'pg' => 420, 'sg' => '10,25,50,75,100']);
    fxDlgClearQueue();
    return fxDlgSay($npc, fxDlgSnap(['pspeech' => (string) $sp]), $line);
}

fx_scenario('d42', 'the owner\'s own three free-conversation examples (addendum 5): a merchant haggle, a lie to a guard and a threat to a bandit each produce a REAL check the server decides', function (FxT $t) {
    foreach ([
        ['Belethor Flowtest', 'merchant haggle', 'barter',
            'Come on, knock the price down - twenty septims for the lot and we both walk away happy.'],
        ['Guard Flowtest', 'lie to a guard', 'deceive',
            'That bounty is not mine. You have the wrong man - I have never set foot in Riften.'],
        ['Bandit Flowtest', 'threat to a bandit', 'intimidate',
            'Drop the axe and walk away, or I will put you in the ground where you stand.'],
    ] as [$npc, $what, $kind, $line]) {
        $q = fxChkTurn($npc, $line);
        $c = $q['turn']['check'] ?? null;
        $t->mustCap('dlg_checks', $what . ': an attempt was recognised at all',
            fn() => is_array($c) && (string) ($c['kind'] ?? '') !== '', json_encode($c));
        $t->mustCap('dlg_checks', $what . ': and as the right kind (' . $kind . ')',
            fn() => is_array($c) && (string) ($c['kind'] ?? '') === $kind, json_encode($c));
        $t->mustCap('dlg_checks', $what . ': the SERVER decided the outcome, not the model',
            fn() => is_array($c) && in_array((string) ($c['result'] ?? ''), ['pass', 'fail', 'ask'], true), json_encode($c));
        $t->mustCap('dlg_checks', $what . ': the threshold used was the LIVE Speech global, not the fallback',
            fn() => is_array($c) && (string) ($c['thr_src'] ?? '') === 'live', json_encode($c));
        $t->mustCap('dlg_checks', $what . ': no threshold, skill number or rule reaches the prompt',
            fn() => !preg_match('/\bspeechcraft\b|\bthreshold\b|\bspeech(?:very)?(?:easy|hard|average)\b/i', $q['volatile']),
            fx_short($q['volatile'], 240));
        // the hard rail of the whole round: a free check is XP + affinity + memory, never an engine flag
        $t->mustCap('dlg_checks', $what . ': nothing that sets an engine flag or a stage is ever emitted',
            fn() => !preg_match('/SetBribed|SetIntimidated|SetCrimeGold|SetStage|CompleteQuest/i',
                implode(' ', array_map('strval', fxDlgQueue()))), json_encode(fxDlgQueue()));
    }
});

fx_scenario('d43', 'recognition recall of the free-conversation checks on natural phrasings, in the owner\'s words rather than the patterns\'', function (FxT $t) {
    // A NEGATIVE STATEMENT IS AN ATTEMPT. Most of these lies are negations, and the first version of the
    // recogniser refused every one of them for containing "not" or "never".
    $cases = [
        ['persuade',   'Come on, you can tell me what happened to the girl.'],
        ['persuade',   'Look, I only need the name. You have known me for years.'],
        ['persuade',   'I am begging you - just tell me where he went.'],
        ['persuade',   'You know I am good for it. Let me have the key.'],
        ['persuade',   'Be reasonable about this, nobody has to get hurt.'],
        ['intimidate', 'Talk, or I will break your arm right here.'],
        ['intimidate', 'You do not want me as an enemy. Talk. Now.'],
        ['intimidate', 'I have killed men for less than what you just said.'],
        ['intimidate', 'Drop the axe and walk away, or I will put you in the ground.'],
        ['deceive',    'Trust me, I am with the city guard on this matter.'],
        ['deceive',    'That bounty is not mine. You have the wrong man.'],
        ['deceive',    'I have never set foot in Riften in my life.'],
        ['deceive',    'I am the new steward, the Jarl sent word ahead of me.'],
        ['bribe',      'Here is two hundred septims. Look the other way.'],
        ['bribe',      'Would a little coin help you forget my face?'],
        ['barter',     'Come on, knock the price down - twenty septims for the lot.'],
        ['barter',     'That is daylight robbery. Give me a better price.'],
        ['barter',     'I will give you thirty for it, not a coin more.'],
    ];
    $npc = 'Hroda Flowtest';
    $hits = 0;
    $miss = [];
    foreach ($cases as [$want, $line]) {
        $c = fxChkTurn($npc, $line, 60)['turn']['check'] ?? null;
        $got = is_array($c) ? (string) $c['kind'] : '-';
        if ($got === $want) { $hits++; } else { $miss[] = $want . ' read as ' . $got . ': "' . $line . '"'; }
    }
    $t->mustCap('dlg_checks', 'every one of the 18 natural phrasings is recognised as the intended kind',
        fn() => $hits === count($cases), $hits . '/' . count($cases) . ' - ' . implode(' | ', $miss));

    // ... and the things that are NOT attempts still are not. A hypothetical asks, it does not try.
    foreach ([
        'Should I threaten him, do you think?',
        'What if I offered him gold for it?',
        'He said "give me fifty septims or else" and I laughed at him.',
    ] as $line) {
        $c = fxChkTurn($npc, $line, 60)['turn']['check'] ?? null;
        $t->mustCap('dlg_checks', 'a hypothetical or a quoted line is not an attempt: "' . fx_short($line, 44) . '"',
            fn() => !is_array($c), json_encode($c));
    }

    // and "everyone has a price" is not a bribe check: coin offered for HER runs the price path, and only
    // coin with a PURPOSE ("look the other way") is a bribe
    $c = fxChkTurn($npc, "I will pay you two hundred septims, if you are willing.", 60)['turn']['check'] ?? null;
    $t->mustCap('dlg_checks', 'coin offered for her is the price path, never a bribe check',
        fn() => !is_array($c) || (string) ($c['kind'] ?? '') !== 'bribe', json_encode($c));
});
