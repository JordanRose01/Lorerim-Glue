<?php
// PHASE 2 d37 / d38 / d39 - the two speech-check mechanisms (V04_BUILD_PLAN section 6, owner addenda 5 and 7).
require_once __DIR__ . '/../dlg_adapter.php';

fx_scenario('d37', 'ENGINE speech check: scoff-first parks once then executes; a compound entry executes at once; attempts suppresses the retry until sp changes; the label comes from the index kind', function (FxT $t) {
    $npc = 'Hroda Flowtest';
    // the shown text is the index's FAILURE variant and that INFO is scripted -> scoff-first
    $text = 'Tell me, or I will beat it out of you. (Brawl)';
    $spec = [$text => ['kind' => '', 'topic_kind' => 'intimidate', 'variant' => 'failure', 'scripted' => 1,
        'fail_brawl' => 1], 'Never mind.' => []];
    fxDlgCache($npc, $spec, ['wis' => 0]);
    $q = fxDlgSay($npc, fxDlgSnap(), 'Talk, or I will break your arm right here.');
    $t->must('the label is a threat and names the brawl consequence',
        str_contains($q['volatile'], '[threat - refusing means a brawl]'), fx_short($q['volatile'], 300));
    $t->must('the model is never told a threshold or a chance',
        !preg_match('/\b(chance|threshold|percent|speechcraft)\b/i', $q['volatile']), fx_short($q['volatile'], 300));
    fxDlgClearQueue();
    $w1 = fxDlgLlm($npc, array_keys($q['keys'])[0], 'You would not dare.');
    $t->must('scoff-first parks the first selection', $w1 === [], json_encode(array_map('fxParam', $w1)));
    $q2 = fxDlgSay($npc, fxDlgSnap(), 'I mean it. Talk right now or I will.');
    $w2 = fxDlgLlm($npc, array_keys($q2['keys'])[0], '');
    $t->must('the second selection executes', count($w2) === 1 && fxDlgDo($w2[0]) === 'pick',
        json_encode(array_map('fxParam', $w2)));
    $t->must('kind travels as the INDEX kind, not the text tag',
        count($w2) === 1 && (string) (fxDlgKv($w2[0])['kind'] ?? '') === 'intimidate', count($w2) ? fxParam($w2[0]) : '-');

    // compound: scoff-first and retry suppression are OFF, so it executes at once
    $spec2 = ['You are simply too weak to follow your heart. (Intimidate)' =>
        ['kind' => 'intimidate', 'variant' => 'success', 'compound' => 1, 'scripted' => 1], 'Never mind.' => []];
    fxDlgCache($npc, $spec2, ['wis' => 0]);
    $q3 = fxDlgSay($npc, fxDlgSnap(), 'You are too weak to follow your own heart.');
    $w3 = fxDlgLlm($npc, array_keys($q3['keys'])[0], '');
    $t->must('a compound entry is executed at once (the engine may pass it on a weapon skill)',
        count($w3) === 1 && fxDlgDo($w3[0]) === 'pick', json_encode(array_map('fxParam', $w3)));

    // attempts memory: a failed norm is not re-clickable until something changed
    fxDlgEvent($npc, 'result', ['sid' => 's1', 'gen' => 0, 'cid' => 'dr10', 'x' => 'aaaaaaaaa1', 'pos' => 0,
        'i' => 100, 'kind' => 'intimidate', 'ok' => 1, 'dI' => 0, 'sp' => 30, 'wis' => 0],
        'You are simply too weak to follow your heart. (Intimidate)');
    $st = fxDlgStateOf($npc);
    $t->must('the attempt is remembered with its verdict', count((array) ($st['attempts'] ?? [])) === 1,
        json_encode($st['attempts'] ?? []));
    $t->must('a no-delta intimidation on a failure variant is not silently called a pass',
        in_array((string) ($st['last_result']['verdict'] ?? ''), ['fail', 'unknown'], true),
        (string) ($st['last_result']['verdict'] ?? '?'));
});

fx_scenario('d38', 'FREE-CONVERSATION check: at most one do=award, the outcome is fact, the same trick is refused with the remembered result, a deceive after a caught lie auto-fails, and no engine flag or stage is ever emitted', function (FxT $t) {
    $npc = 'Hroda Flowtest';
    fxDlgReset();                      // no list at all: this is the no-engine-entry case
    fxSendSnapshot($npc, fxDlgSnap(['pspeech' => 80]));
    fxDlgEvent($npc, 'facts', ['ref' => '0x00012345', 'sp' => 80, 'lvl' => 20, 'perk' => '-', 'wis' => 1,
        'pg' => 300, 'sg' => '10,25,50,75,100']);
    $t->mustCap('dlg_checks', 'lrgDlgCheck is delivered', fn() => true);
    fxDlgClearQueue();
    $q = fxDlgSay($npc, fxDlgSnap(['pspeech' => 80]), 'Come on, you can tell me what happened to the girl.');
    $c = $q['turn']['check'] ?? null;
    $t->must('an attempt was recognised', is_array($c) && (string) ($c['kind'] ?? '') !== '', json_encode($c));
    $t->must('the kind is persuade', is_array($c) && (string) $c['kind'] === 'persuade', json_encode($c));
    $t->must('the threshold came from the LIVE Speech globals the game sent',
        is_array($c) && (string) ($c['thr_src'] ?? '') === 'live', json_encode($c['thr_src'] ?? '?'));
    $t->must('Speech 80 against this band passes', is_array($c) && (string) $c['result'] === 'pass', json_encode($c));
    $t->must('the outcome is handed over as FACT', str_contains($q['volatile'], '<what_just_happened>')
        && str_contains(strtolower($q['volatile']), 'convinced'), fx_short($q['volatile'], 300));
    $t->must('no threshold, skill number or rule reaches the prompt',
        !preg_match('/\b(75|threshold|speechcraft|band)\b/i', $q['volatile']), fx_short($q['volatile'], 300));
    $out = fxDlgLlmNothing($npc, 'Very well. She went east.');
    $t->must('exactly one do=award line is emitted', count($out) === 1 && fxDlgDo($out[0]) === 'award',
        json_encode(array_map('fxParam', $out)));
    $t->must('do=award carries xp=1 and no position at all', count($out) === 1
        && (string) (fxDlgKv($out[0])['xp'] ?? '') === '1' && (string) (fxDlgKv($out[0])['pos'] ?? '') === '-1'
        && (string) (fxDlgKv($out[0])['sid'] ?? '') === '0', count($out) ? fxParam($out[0]) : '-');
    $t->must('stat= is empty by default (Beneficial Speech Checks would pay out on that delta)',
        count($out) === 1 && (string) (fxDlgKv($out[0])['stat'] ?? 'x') === '', count($out) ? fxParam($out[0]) : '-');
    $t->must('no SetBribed / SetIntimidated / SetCrimeGold / stage is ever emitted',
        !preg_match('/SetBribed|SetIntimidated|SetCrimeGold|SetStage|setobjective/i', implode('', $out)),
        implode(' ', $out));
    $t->must('it is queued on D2 as well', count(fxDlgQueue()) === 1, json_encode(fxDlgQueue()));

    // the same trick again, inside checks.memory_seconds: the remembered result, not a fresh roll
    $q2 = fxDlgSay($npc, fxDlgSnap(['pspeech' => 80]), 'Come on, you can tell me what happened to the girl.');
    $c2 = $q2['turn']['check'] ?? null;
    $t->must('the same trick is answered from memory', is_array($c2) && (string) ($c2['mem'] ?? '') === 'hit',
        json_encode($c2['mem'] ?? '?'));
    $t->must('the memory hit carries the remembered result', is_array($c2) && (string) $c2['result'] === 'pass',
        json_encode($c2));
    // ---- [0.5.1 pt9 go-live / S8] A REMEMBERED PASS PAYS NOTHING --------------------------------
    // The result was recorded, but award was still set on EVERY pass - so one passing sentence granted
    // Speech XP for ever, and on a memory hit N collapses to 0, which meant a repeated bribe kept the
    // verdict 'pass' while take= went to zero: the same guard bribed free from the second try onward.
    $t->must('a remembered pass grants no XP and no stat', is_array($c2) && empty($c2['award'])
        && (int) ($c2['xp'] ?? 0) === 0, json_encode($c2));
    fxDlgClearQueue();
    $out2 = fxDlgLlmNothing($npc, 'I have told you already.');
    $t->must('...and emits no do=award at all the second time', $out2 === [],
        json_encode(array_map('fxParam', $out2)));
    $t->must('...on neither delivery route', fxDlgQueue() === [], json_encode(fxDlgQueue()));
    // a THIRD identical turn is the same: this is a latch, not a one-off
    $q2b = fxDlgSay($npc, fxDlgSnap(['pspeech' => 80]), 'Come on, you can tell me what happened to the girl.');
    $t->must('and it stays that way on every repeat', empty(($q2b['turn']['check'] ?? [])['award']),
        json_encode($q2b['turn']['check'] ?? null));
    // the bribe half of the same bug: a remembered bribe never takes gold a second time
    $bribe = 'Bribetest Flowtest';
    fxDlgReset();
    fxSendSnapshot($bribe, fxDlgSnap(['pspeech' => 80]));
    fxDlgEvent($bribe, 'facts', ['ref' => '0x00012345', 'sp' => 80, 'lvl' => 20, 'perk' => '-', 'wis' => 1,
        'pg' => 900, 'sg' => '10,25,50,75,100']);
    $b1 = fxDlgSay($bribe, fxDlgSnap(['pspeech' => 80, 'pgold' => 900]),
        'Here is 200 gold, just look the other way about the bounty.');
    $cb1 = $b1['turn']['check'] ?? null;
    $t->must('the first bribe is a real attempt that costs the named amount',
        is_array($cb1) && (string) $cb1['kind'] === 'bribe'
        && ((string) $cb1['result'] !== 'pass' || (int) $cb1['take'] > 0), json_encode($cb1));
    $b2 = fxDlgSay($bribe, fxDlgSnap(['pspeech' => 80, 'pgold' => 900]),
        'Here is 200 gold, just look the other way about the bounty.');
    $cb2 = $b2['turn']['check'] ?? null;
    $t->must('the SAME bribe repeated never passes for free: it awards nothing',
        is_array($cb2) && empty($cb2['award']) && (int) ($cb2['take'] ?? 0) === 0, json_encode($cb2));

    // a deceive against an NPC who already caught a lie auto-fails
    fxDlgReset();
    fxSendSnapshot($npc, fxDlgSnap(['pspeech' => 100]));
    fxDlgEvent($npc, 'facts', ['ref' => '0x00012345', 'sp' => 100, 'lvl' => 20, 'perk' => '-', 'wis' => 1,
        'pg' => 300, 'sg' => '10,25,50,75,100']);
    $st = fxDlgStateOf($npc);
    lrgDlgSet($npc, ['checks' => ['deceive|deadbeef01' => ['kind' => 'deceive', 'result' => 'fail',
        'at' => fxNow(), 'sp' => 5, 'wis' => 1, 'pg' => 1, 'aff' => 99, 'tries' => 1]]]);
    $q3 = fxDlgSay($npc, fxDlgSnap(['pspeech' => 100]), 'Trust me, I am with the city guard on this matter.');
    $c3 = $q3['turn']['check'] ?? null;
    $t->must('a deceive after a caught lie auto-fails whatever the Speech skill',
        is_array($c3) && (string) $c3['kind'] === 'deceive' && (string) $c3['result'] === 'fail'
        && (string) ($c3['mem'] ?? '') === 'auto-fail', json_encode($c3));
    $out3 = fxDlgLlmNothing($npc, 'I do not believe you.');
    $t->must('a failed check awards nothing at all', $out3 === [], json_encode(array_map('fxParam', $out3)));

    // an ENGINE entry of the same kind always wins: no free check at all
    fxDlgCache($npc, ['Come on, you can tell me. (Persuade)' => ['kind' => 'persuade', 'variant' => 'success'],
        'Never mind.' => []]);
    fxDlgEvent($npc, 'facts', ['ref' => '0x00012345', 'sp' => 80, 'lvl' => 20, 'perk' => '-', 'wis' => 1,
        'pg' => 300, 'sg' => '10,25,50,75,100']);
    $q4 = fxDlgSay($npc, fxDlgSnap(['pspeech' => 80]), 'Come on, you can tell me what happened here.');
    $t->must('a listed engine check stands the free check down entirely', ($q4['turn']['check'] ?? null) === null,
        json_encode($q4['turn']['check'] ?? null));
});

fx_scenario('d39', 'intimidation immunity: wis=0 cannot pass, a jarl and a Vigilant are never intimidated, a failure drops affinity exactly once, and hostility only with the toggle on', function (FxT $t) {
    $jarl = fxCast('jarl');
    $vig = fxCast('vigilant');
    $plain = fxCast('commoner');

    // wis=0 -> the engine's own verdict says no, and no roll can overturn it
    fxDlgReset();
    fxSendSnapshot($plain['name'], fxSnap($plain['snap'] + ['pspeech' => 100]));
    fxDlgEvent($plain['name'], 'facts', ['ref' => '0x00012345', 'sp' => 100, 'lvl' => 60, 'perk' => '-',
        'wis' => 0, 'pg' => 5000, 'sg' => '10,25,50,75,100']);
    fxAff($plain['name'], 0);
    $q = fxDlgSay($plain['name'], fxSnap($plain['snap'] + ['pspeech' => 100]), 'Do it now, or I will break your arm.');
    $c = $q['turn']['check'] ?? null;
    $t->must('wis=0 cannot pass at any skill level', is_array($c) && (string) $c['kind'] === 'intimidate'
        && (string) $c['result'] === 'fail', json_encode($c));
    $t->must('the reason names the engine\'s own verdict', is_array($c)
        && str_contains((string) $c['why'], "engine's own verdict"), json_encode($c['why'] ?? ''));
    $t->must('the failure is told as fact and never softened',
        str_contains(strtolower($q['volatile']), 'not afraid'), fx_short($q['volatile'], 260));
    $t->must('hostility is OFF by default', is_array($c) && empty($c['hostility']), json_encode($c));

    // a jarl is immune whatever the engine says
    foreach ([$jarl, $vig] as $who) {
        fxDlgReset();
        fxSendSnapshot($who['name'], fxSnap($who['snap'] + ['pspeech' => 100]));
        fxDlgEvent($who['name'], 'facts', ['ref' => '0x00012345', 'sp' => 100, 'lvl' => 60, 'perk' => '-',
            'wis' => 1, 'pg' => 5000, 'sg' => '10,25,50,75,100']);
        $q2 = fxDlgSay($who['name'], fxSnap($who['snap'] + ['pspeech' => 100]), 'Do it now, or I will break your arm.');
        $c2 = $q2['turn']['check'] ?? null;
        $t->must($who['profile'] . ' is never intimidated, even with wis=1',
            is_array($c2) && (string) $c2['result'] === 'fail', json_encode($c2));
        $t->must($who['profile'] . '\'s reason says who she is, not a number',
            is_array($c2) && !preg_match('/\d{2,}/', (string) $c2['why']), json_encode($c2['why'] ?? ''));
    }

    // a failure drops affinity exactly ONCE per attempt
    fxDlgReset();
    fxSendSnapshot($plain['name'], fxSnap($plain['snap'] + ['pspeech' => 100]));
    fxDlgEvent($plain['name'], 'facts', ['ref' => '0x00012345', 'sp' => 100, 'lvl' => 60, 'perk' => '-',
        'wis' => 0, 'pg' => 5000, 'sg' => '10,25,50,75,100']);
    fxAff($plain['name'], 40);
    $before = RelationshipManager::$aff[$plain['name']] ?? 0;
    $mark = fxDlgLogMark();
    fxDlgSay($plain['name'], fxSnap($plain['snap'] + ['pspeech' => 100]), 'Do it now, or I will break your arm.');
    $after1 = RelationshipManager::$aff[$plain['name']] ?? 0;
    $log1 = fxDlgLogFrom($mark);
    $mark2 = fxDlgLogMark();
    fxDlgSay($plain['name'], fxSnap($plain['snap'] + ['pspeech' => 100]), 'Do it now, or I will break your arm.');
    $after2 = RelationshipManager::$aff[$plain['name']] ?? 0;
    $log2 = fxDlgLogFrom($mark2);
    $t->must('a failed check asks Phase 1 to drop affinity exactly ONCE',
        substr_count($log1, 'check failed') === 1, (string) substr_count($log1, 'check failed'));
    $t->must('the SAME trick a second time asks for nothing at all',
        substr_count($log2, 'check failed') === 0, (string) substr_count($log2, 'check failed'));
    $t->must('and affinity is untouched the second time', $after2 === $after1, "$after1 -> $after2");
    $t->note('the flow harness\'s RelationshipManager stub has no adjustRelationship(), so Phase 1\'s '
        . 'lrgAdjustAffinity() logs and returns null here (' . $before . ' -> ' . $after1 . '). The CALL is '
        . 'what this scenario can prove offline; the live drop is T6 in game.');
});
