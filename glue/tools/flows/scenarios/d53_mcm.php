<?php
// PHASE 2 d53 / d54 / d55 - [0.5.0] the MCM-wiring gate, answering in words, the hand-back.
// V05_EXPANSION_PLAN sections 7.2, 7.3, 8, 9.3, 14.
require_once __DIR__ . '/../dlg_adapter.php';

fx_scenario('d53', 'every MCM control has a settings.ini line and a reader (test_mcm_wiring.php as a release gate: the seven-dead-controls class)', function (FxT $t) {
    $tool = dirname(__DIR__, 2) . '/test_mcm_wiring.php';
    if (!is_file($tool)) { $t->pending('the MCM wiring gate', 'tools/test_mcm_wiring.php is not delivered'); return; }
    $out = [];
    $rc = 0;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tool) . ' --quiet 2>&1', $out, $rc);
    $txt = implode("\n", $out);
    $t->must('test_mcm_wiring.php exits 0 - every control has an ini line and a reader', $rc === 0,
        fx_short($txt, 400));
    $t->must('it really inspected the shipped files (a non-trivial control count)',
        (bool) preg_match('/MCM wiring: (\d+) controls/', $txt, $m) && (int) $m[1] > 40, fx_short($txt, 200));

    // ======================================================== [0.5.0 fix pass / S-3 + S-4] behaviour
    // The gate above answers "does something read this id". These answer the question that actually
    // matters and that the gate cannot ask: does turning the control off CHANGE WHAT THE SERVER DOES.
    // Seven controls failed that in the shipped build - the game was already sending qig / qx / svx /
    // tg and no .php read any of them - and the release gate passed anyway.
    $npc = 'Brenna Flowtest';
    $spec = ['I need work.' => ['quest' => 'FxWork', 'journal' => 1], 'Never mind.' => []];

    // --- bQuestSummary (qx byte 0) and bQuestNext (qx byte 1)
    foreach ([['11', true, true], ['01', false, true], ['10', true, false], ['00', false, false]] as [$qx, $sum, $next]) {
        fxDlgCache($npc, $spec);
        unset($GLOBALS['LRG_DLG_MCM']);
        $a = fxDlgSay($npc, fxDlgSnap(['qx' => $qx]), 'What can I ask you?');
        $t->must('qx=' . $qx . ': <what_you_can_ask> is ' . ($sum ? 'built' : 'suppressed'),
            str_contains($a['volatile'], '<what_you_can_ask') === $sum, fx_short($a['volatile'], 200));
        $GLOBALS['LRG_DLG_TEST_QUESTLOG'] = ['FxWork' => ['briefing' => 'Speak to the Jarl of Whiterun.',
            'stage' => 20, 'at' => fxNow()]];
        fxDlgEvent($npc, 'facts', ['q' => 'FxWork']);
        unset($GLOBALS['LRG_DLG_MCM']);
        $b = fxDlgSay($npc, fxDlgSnap(['qx' => $qx]), 'What should I do next?');
        $t->must('qx=' . $qx . ': <your_quests> is ' . ($next ? 'built' : 'suppressed'),
            str_contains($b['volatile'], '<your_quests>') === $next, fx_short($b['volatile'], 200));
        unset($GLOBALS['LRG_DLG_TEST_QUESTLOG']);
    }

    // --- [pt19 v1.0 / section 4] iQuestInitiativeGap (qig) and bQuestInitiative (qi) are retired with <she_may_raise>:
    // no server code reads them any more (a dead control is the scar this scenario exists for - test_mcm_wiring lists
    // them as removed ids)
    $t->must('the quest-initiative block and its gap are gone from the server',
        !function_exists('lrgDlgInitiativeGap') && !function_exists('lrgDlgInitiativeCandidate') && lrgDlgCfg('quests.initiative') === null);

    // --- bServiceShortcut (svx byte 0): with NO list, OFF hides the shortcut, ON leaves it.
    // [pt19 v1.0] under ml=0: with the driver live (ml=1) "I need a room" opens her real menu before the model runs
    // (S2.1) and the shortcut steps aside for that turn - bServiceShortcut is about the no-session world.
    $offered = array_merge(fxEnabledDefault(), [FX_DLG_ACT, 'RentRoom', 'HireCarriage', 'Training',
        'OpenInventory', 'ForgiveCrime', 'PayBounty']);
    foreach ([['111', true], ['011', false]] as [$svx, $survives]) {
        fxDlgReset();
        unset($GLOBALS['LRG_DLG_MCM'], $GLOBALS['LRG_DLG_ML_SAID']);
        $c = fxDlgSay('Unknown Flowtest', fxDlgSnap(['svx' => $svx, 'ml' => '0']), 'I need a room for the night.',
            'inputtext', $offered);
        $t->must('svx=' . $svx . ': with no list at all CHIM\'s RentRoom ' . ($survives ? 'survives' : 'is hidden too'),
            in_array('RentRoom', $c['enabled'], true) === $survives, implode(',', $c['enabled']));
    }

    // --- bNamePrices (svx byte 2) and the per-kind never_quote_price, in one place
    unset($GLOBALS['LRG_DLG_MCM']);
    $GLOBALS['LRG_DLG_MCM'] = ['svnp' => 1];
    $t->must('bNamePrices ON: a carriage layer may quote the price its list showed',
        lrgDlgMayQuotePrice(['npc' => $npc, 'svc' => ['kind' => 'carriage']]), 'expected true');
    $t->must('... but a TRAINING layer never may - services.kinds.train.never_quote_price',
        !lrgDlgMayQuotePrice(['npc' => $npc, 'svc' => ['kind' => 'train']]), 'expected false');
    $GLOBALS['LRG_DLG_MCM'] = ['svnp' => 0];
    $t->must('bNamePrices OFF: not even a real carriage fare is said out loud',
        !lrgDlgMayQuotePrice(['npc' => $npc, 'svc' => ['kind' => 'carriage']]), 'expected false');
    unset($GLOBALS['LRG_DLG_MCM']);

    // --- bTruthGate (tg) is its OWN control. It used to be wired to bLockedFacts, so switching
    // bLockedFacts off silently disabled the truth gate as well - the reverse of both help texts.
    $src = (string) @file_get_contents(dirname(__DIR__, 3) . '/server/lorerim_glue/lib/lrg_dialogue.php');
    $t->must('the truth gate reads tg, not lf', str_contains($src, "lrgDlgMcm('tg', 1,"), 'no tg reader');
    $t->must('and bLockedFacts still gates the <locked_facts> block itself',
        (bool) preg_match("/lrgDlgMcm\('lf',[^\n]*truth\.locked_facts/", $src), 'lf reader moved');
    $t->must('nothing reads lf as the truth gate any more',
        !str_contains($src, "lrgDlgMcm('lf', 1,"), 'lf is still standing in for tg');

    // --- S-4: the gate itself cannot be satisfied by a bare mention in a Papyrus ';' comment
    $t->must('the wiring gate strips Papyrus comments before it looks for an id',
        str_contains((string) @file_get_contents($tool), 'lrgStripPapyrusComments'), 'no comment stripper');
});

fx_scenario('d54', '"what can I ask you" and "what should I do next" are answered in WORDS: the cache verbatim when it exists, an explicitly approximate list when it does not, and never a stage number', function (FxT $t) {
    $npc = 'Brenna Flowtest';
    $spec = ['I need work.' => ['quest' => 'FxWork', 'journal' => 1],
        "I'd like to rent a room." => [], 'Have you heard any rumours?' => [], 'Never mind.' => []];

    // --- the recogniser, on the owner's own phrasings and on near-misses
    $hits = ['What can I ask you?', 'what can i ask you about', 'Anything I should know?',
        'Got any work?', 'Do you have work for me?', 'What is there to do around here?'];
    foreach ($hits as $h) {
        $t->must('"' . $h . '" is recognised as a LIST question', lrgDlgAskKind($h) === 'list', lrgDlgAskKind($h));
    }
    $nexts = ['What should I do next?', 'Where was I?', 'what am i supposed to do now',
        'Remind me what I was doing.', 'Where do I go now?'];
    foreach ($nexts as $h) {
        $t->must('"' . $h . '" is recognised as a NEXT question', lrgDlgAskKind($h) === 'next', lrgDlgAskKind($h));
    }
    $misses = ['Can I ask you something personal?', 'What do you sell?', 'Do you know what happened here?',
        'I need work.', 'Where is the inn?', 'Next time, then.'];
    foreach ($misses as $h) {
        $t->must('"' . $h . '" is NOT one of the two questions', lrgDlgAskKind($h) === '', lrgDlgAskKind($h));
    }

    // --- certain=1: the cache, verbatim
    fxDlgCache($npc, $spec);
    $q = fxDlgSay($npc, fxDlgSnap(), 'What can I ask you?');
    $t->must('with a cached list she answers from it, marked certain', str_contains($q['volatile'], 'certain="1"'),
        fx_short($q['volatile'], 400));
    $t->must('and the entries are VERBATIM', str_contains($q['volatile'], '- I need work.'),
        fx_short($q['volatile'], 400));
    $t->must('and she is told not to read it out as a list',
        str_contains($q['volatile'], 'do not read the list out'), fx_short($q['volatile'], 400));

    // --- certain=0: no cache at all, only the quests the game says she is in.
    // A FRESH NPC: a cached root really does survive a reset, and that is the behaviour under test.
    $cold = 'Sella Flowtest';
    fxDlgReset([fxDlgRow('I hear the mill needs a hand.', ['quest' => 'FxWork', 'journal' => 1, 'toplevel' => 1,
        'shared' => 2])], []);
    fxSendSnapshot($cold, fxDlgSnap());
    fxDlgEvent($cold, 'facts', ['q' => 'FxWork']);
    $q2 = fxDlgSay($cold, fxDlgSnap(), 'What can I ask you?');
    $t->must('with no cache the answer is explicitly UNCERTAIN', str_contains($q2['volatile'], 'certain="0"'),
        fx_short($q2['volatile'], 400));
    $t->must('and it forbids quoting the guesses and forbids the action',
        str_contains($q2['volatile'], 'Never quote these word for word')
        && str_contains($q2['volatile'], 'never use'), fx_short($q2['volatile'], 500));
    $t->must('an uncertain answer can execute NOTHING: no keys were offered from it',
        !str_contains($q2['volatile'], "\nT1 "), fx_short($q2['volatile'], 300));

    // --- the index fallback never quotes a TEMPLATE
    $cold2 = 'Hroda Flowtest';
    fxDlgReset([fxDlgRow('Morthal. (<Global=KmodFerryCost> gold)', ['quest' => 'FxRide', 'journal' => 1,
        'toplevel' => 1, 'shared' => 1])], []);
    fxSendSnapshot($cold2, fxDlgSnap());
    fxDlgEvent($cold2, 'facts', ['q' => 'FxRide']);
    $q3 = fxDlgSay($cold2, fxDlgSnap(), 'Anything I should know?');
    $t->must('an index row that is a TEMPLATE is never offered as a thing she has',
        !str_contains($q3['volatile'], '<Global='), fx_short($q3['volatile'], 300));

    // --- "what should I do next": the journal, current objective only
    fxDlgCache($npc, $spec);
    $GLOBALS['LRG_DLG_TEST_QUESTLOG'] = ['FxWork' => ['briefing' => 'Speak to the Jarl of Whiterun.',
        'stage' => 20, 'at' => fxNow()]];
    fxDlgEvent($npc, 'facts', ['q' => 'FxWork']);
    $q4 = fxDlgSay($npc, fxDlgSnap(), 'What should I do next?');
    $t->must('she answers from the journal', str_contains($q4['volatile'], '<your_quests>'),
        fx_short($q4['volatile'], 400));
    $t->must('with the current objective, quoted', str_contains($q4['volatile'], 'Speak to the Jarl of Whiterun'),
        fx_short($q4['volatile'], 400));
    $t->must('and she is told never to mention a stage or a number',
        str_contains($q4['volatile'], 'Never mention a stage'), fx_short($q4['volatile'], 400));
    $t->must('the block itself contains no stage number', !preg_match('/stage\s*\d/i', $q4['volatile']),
        fx_short($q4['volatile'], 300));
    unset($GLOBALS['LRG_DLG_TEST_QUESTLOG']);
});

fx_scenario('d55', '[pt19 v1.0 / S1.3] the FORESEEN hand-back: a READ-ONLY session (the game does not drive it, a journal-quest scene before this install\'s first click, drive_scene off) shows the list as facts and her words say "choose it on the list yourself" the same turn; nothing is picked; the scene rail drives it once a click is proven', function (FxT $t) {
    $GLOBALS['LRG_DLG_TEST_CFG'] = ['session.stage_rail' => true];   // [v1.0.1] the rail ships OFF; this scenario tests the rail itself
    $npc = 'Hroda Flowtest';
    $texts = ['Whiterun would be a fair trade.', 'The Rift, and nothing less.', 'I need more time.'];
    $spec = [];
    foreach ($texts as $x) { $spec[$x] = ['toplevel' => 0]; }
    $ent = [];
    foreach ($texts as $i => $x) { $ent[] = fxDlgEntry($i, $x); }
    $roClause = 'choose it on the list yourself - I cannot pick for you here';
    $session = static function (array $open, int $clicks) use ($npc, $spec, $texts, $ent): void {
        fxDlgReset(fxDlgIndexFrom($spec), fxDlgLayer($texts));
        lrgDlgPut('*install*', ['clicks_ok' => $clicks]);
        fxSendSnapshot($npc, fxDlgSnap());
        fxDlgEvent($npc, 'open', $open + ['sid' => 's1', 'origin' => 'engine']);
        fxDlgTopics($npc, ['layer' => 1, 'gen' => 1], $ent);
    };

    // --- an ordinary driven closed layer: no clause, keys offered
    $session(['drv' => 1], 1);
    $q = fxDlgSay($npc, fxDlgSnap(), 'The Rift then.');
    $t->must('an ordinary driven layer gets NO read-only clause and offers its keys',
        !str_contains($q['volatile'], $roClause) && count($q['keys']) === 3, fx_short($q['volatile'], 400));
    // the retired rails change nothing any more: losses, iBranchInput, X1
    lrgDlgPut($npc, ['losses' => 5]);
    fxDlgEvent($npc, 'calib', ['src' => 'passive', 'gate' => 0], 'x1:2,rm:3,cm:1');
    fxDlgTopics($npc, ['layer' => 1, 'gen' => 2, 'bi' => 2], $ent);
    $qr = fxDlgSay($npc, fxDlgSnap(), 'The Rift, and nothing less.');
    $wr = fxDlgLlm($npc, 'T2', 'The Rift it is.');
    $t->must('two losses, bi=2 and x1=2 no longer hand anything back: the key is picked (no assisted / bi / x1 rail)',
        count($wr) === 1 && fxDlgDo($wr[0]) === 'pick' && !str_contains($qr['volatile'], $roClause), json_encode(array_map('fxParam', $wr)));

    // --- drv=0 (the game does not drive it): read-only
    $session(['drv' => 0], 1);
    $mark = fxDlgLogMark();
    $q2 = fxDlgSay($npc, fxDlgSnap(), 'The Rift then.');
    $t->must('drv=0: the list is shown as facts (no keys) and the clause rides this very turn',
        str_contains($q2['volatile'], $roClause) && $q2['keys'] === [] && str_contains($q2['volatile'], '- The Rift, and nothing less.'),
        fx_short($q2['volatile'], 500));
    fxDlgClearQueue();
    $w2 = fxDlgLlm($npc, 'T2', 'Choose it on the list yourself.');
    $t->must('...a key the model makes up anyway is not emitted; nothing is queued', $w2 === [] && fxDlgQueue() === [],
        json_encode(array_map('fxParam', $w2)));
    $w2b = fxDlgLlm($npc, 'The Rift, and nothing less', 'Choose it on the list yourself.');
    $t->must('...nor words: the log says gate: read-only session why=vis', $w2b === []
        && str_contains(fxDlgLogFrom($mark), 'read-only session why=vis'), fx_short(fxDlgLogFrom($mark), 300));
    $r = fxDlgTopics($npc, ['layer' => 1, 'gen' => 3, 'want' => 1, 'ask' => 'the rift', 'cid' => 'dro1'], $ent);
    $t->must('...and want=1 picks nothing on it either', $r['echo'] === '', fx_short($r['echo'], 200));

    // --- a journal-quest scene before this install's first click: read-only; after it: the scene rail drives it
    $session(['scene' => 1, 'sj' => 1, 'sq' => 'MQ102', 'sqj' => 1, 'drv' => 1], 0);
    $q3 = fxDlgSay($npc, fxDlgSnap(), 'The Rift then.');
    $t->must('sj=1 with clicks_ok 0: read-only (why=scene-unproven), the clause rides the turn',
        str_contains($q3['volatile'], $roClause) && $q3['keys'] === [] && (string) ($q3['turn']['ro'] ?? '') === 'scene-unproven',
        json_encode($q3['turn']['ro'] ?? null));
    $session(['scene' => 1, 'sj' => 1, 'sq' => 'MQ102', 'sqj' => 1, 'drv' => 1], 1);
    $q4 = fxDlgSay($npc, fxDlgSnap(), 'The Rift, and nothing less.');
    $w4 = fxDlgLlm($npc, 'T2', 'The Rift.');
    $t->must('sj=1 with clicks_ok 1: the scene rail drives it - a plain pick goes out', count($w4) === 1 && fxDlgDo($w4[0]) === 'pick'
        && !str_contains($q4['volatile'], $roClause), json_encode(array_map('fxParam', $w4)));
    // --- a crit 2 session: the lethal clause ("choose that yourself") and do=show
    $session(['drv' => 1, 'crit' => 2], 1);
    fxDlgTopics($npc, ['layer' => 1, 'gen' => 5, 'crit' => 2], $ent);
    $q5 = fxDlgSay($npc, fxDlgSnap(), 'The Rift then.');
    $w5 = fxDlgLlm($npc, 'T2', 'Choose that yourself.');
    $t->must('crit 2: the clause "choose that yourself" and the gate answers do=show kind=meta (never a click)',
        str_contains($q5['volatile'], 'choose that yourself') && count($w5) === 1 && fxDlgDo($w5[0]) === 'show'
        && (string) (fxDlgKv($w5[0])['kind'] ?? '') === 'meta', json_encode(array_map('fxParam', $w5)));

    // --- ev=unhide now says WHICH layer went back
    $mark = fxDlgLogMark();
    fxDlgEvent($npc, 'unhide', ['why' => 'lethal', 'sid' => 's1', 'layer' => 2, 'n' => 3, 'kind' => 'closed',
        'resume' => 1]);
    $log = fxDlgLogFrom($mark);
    $t->must('the hand-back is logged with layer, n, kind and resume',
        str_contains($log, 'layer=2') && str_contains($log, 'kind=closed') && str_contains($log, 'resume=1'),
        fx_short($log, 300));
    $t->must('and it also writes the handback line the owner greps for',
        str_contains($log, 'handback npc='), fx_short($log, 300));
});
