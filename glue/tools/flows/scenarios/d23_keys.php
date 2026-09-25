<?php
// PHASE 2 d23 / d24 / d25 - keys from the cache, cache staleness, ranking (design 7.3 rows 23, 24, 25).
require_once __DIR__ . '/../dlg_adapter.php';

fx_scenario('d23', 'cached root -> T-keys; [pt19 v1.0] the cached root opens her list pre-LLM and the model\'s key waits for it (F15); on the open list a key executes do=pick; a key from an older turn is dropped stale', function (FxT $t) {
    $npc = 'Brenna Flowtest';
    $spec = [
        'I need work.' => ['quest' => 'FxWork', 'journal' => 1],
        "I'd like to rent a room." => [],
        'Have you heard any rumours?' => ['shared' => 44],
        'Never mind.' => [],
    ];
    $ent = [
        fxDlgEntry(0, 'I need work.', ['new' => 1]),
        fxDlgEntry(1, "I'd like to rent a room."),
        fxDlgEntry(2, 'Have you heard any rumours?'),
        fxDlgEntry(3, 'Never mind.'),
    ];
    fxDlgReset(fxDlgIndexFrom($spec));
    fxSendSnapshot($npc, fxDlgSnap());
    fxDlgTopics($npc, [], $ent);
    fxDlgEvent($npc, 'closed', ['sid' => 's1', 'why' => 'goodbye', 'pending' => 0]);
    $q = fxDlgSay($npc, fxDlgSnap(), 'Is there work going?');
    $t->must('the cached root produced T-keys', count($q['keys']) >= 3, json_encode(array_keys($q['keys'])));
    // [pt19 v1.0 / S11] <real_business> is SUPPRESSED when <business> is present: its standing rules live in <business>
    $t->must('[S11] with a list known <business> carries the keys and the static <real_business> block is suppressed',
        str_contains($q['volatile'], '<business') && !str_contains($q['static'], '<real_business>'),
        fx_short($q['static'] . ' | ' . $q['volatile'], 240));
    // the outcome rule rides <business> ONLY when an offered entry is a persuasion / threat / bribe (test_dialogue v13j
    // pins the offered case); this list offers none, so it is not said at all
    $t->must('[S11] no check on offer: the "never say whether a persuasion worked" rule is not said at all',
        !str_contains(strtolower($q['static'] . $q['volatile']), 'never say whether a persuasion'), fx_short($q['volatile'], 240));
    $t->must('the entries are shown VERBATIM', in_array('I need work.', array_column($q['keys'], 'text'), true),
        json_encode(array_column($q['keys'], 'text')));
    $t->must('the quest name is only shown because questlog has no row -> it is NOT shown',
        !str_contains($q['volatile'], '{FxWork}'), fx_short($q['volatile'], 300));
    // [pt19 v1.0 / S2.1, model F15] the cached root answers first: her list is opened BEFORE the model runs, and the
    // model's own key on that open turn is held until the list has answered (d21c walks the fast pick that follows)
    $t->must('[S2.1] the cached root opens her list pre-LLM (open_pending)', !empty($q['turn']['open_pending']),
        json_encode($q['turn']['open_pending'] ?? null));
    $key = array_search('I need work.', array_column($q['keys'], 'text'), true);
    $keyName = array_keys($q['keys'])[$key === false ? 0 : $key];
    fxDlgClearQueue();
    $w0 = fxDlgLlm($npc, $keyName);
    $t->must('[F15] the model\'s key on the open turn is HELD - the open for this sentence decides', $w0 === [],
        json_encode(array_map('fxParam', $w0)));

    // her list is on screen now (the open landed): a key executes do=pick
    lrgDlgPut($npc, ['open_pending' => null]);
    fxDlgTopics($npc, ['sid' => 's2', 'gen' => 0], $ent);
    $q2 = fxDlgSay($npc, fxDlgSnap(), 'Is there work going?');
    $key2 = array_search('I need work.', array_column($q2['keys'], 'text'), true);
    $keyName2 = $key2 === false ? 'T1' : (string) array_keys($q2['keys'])[$key2];
    fxDlgClearQueue();
    $w = fxDlgLlm($npc, $keyName2);
    $t->must('the key executed do=pick', count($w) === 1 && fxDlgDo($w[0]) === 'pick', json_encode(array_map('fxParam', $w)));
    $t->must('it carries the list position and topicIndex', count($w) === 1
        && (string) (fxDlgKv($w[0])['pos'] ?? '') === '0' && (string) (fxDlgKv($w[0])['i'] ?? '') === '100',
        count($w) ? fxParam($w[0]) : '-');
    $t->must('txt is a safe prefix with no ; = @ | " ~', count($w) === 1
        && !preg_match('/[;=@|"~]/', (string) (fxDlgKv($w[0])['txt'] ?? 'x')), count($w) ? fxParam($w[0]) : '-');
    $t->must('the same decision was queued on D2 as well', count(fxDlgQueue()) === 1, json_encode(fxDlgQueue()));

    // a key that is not in THIS turn's offer is stale, whatever the model says
    fxDlgSay($npc, fxDlgSnap(), 'Never mind then.');
    $w2 = fxDlgLlm($npc, 'T11');
    $t->must('a key that was never offered this turn is dropped stale', $w2 === [], json_encode(array_map('fxParam', $w2)));
});

fx_scenario('d24', 'staleness: age, a location change and a journal change each invalidate the cached root', function (FxT $t) {
    $npc = 'Brenna Flowtest';
    $mk = static function () use ($npc) {
        fxSendSnapshot($npc, fxDlgSnap());
        fxDlgTopics($npc, [], [fxDlgEntry(0, 'I need work.'), fxDlgEntry(1, 'Never mind.')]);
        fxDlgEvent($npc, 'closed', ['sid' => 's1', 'why' => 'goodbye', 'pending' => 0]);
    };
    fxDlgReset(fxDlgIndexFrom(['I need work.' => [], 'Never mind.' => []]));
    $mk();
    $t->must('a fresh cache gives keys', count(fxDlgSay($npc, fxDlgSnap(), 'Work?')['keys']) > 0);
    fxAdvance(1801);
    $t->must('older than cache.max_age_seconds -> no keys', fxDlgSay($npc, fxDlgSnap(), 'Work?')['keys'] === []);

    fxDlgReset(fxDlgIndexFrom(['I need work.' => [], 'Never mind.' => []]));
    $mk();
    $t->must('same location -> keys', count(fxDlgSay($npc, fxDlgSnap(), 'Work?')['keys']) > 0);
    $t->must('a location change invalidates the cache',
        fxDlgSay($npc, fxDlgSnap(['loc' => 'Flowtest Keep']), 'Work?')['keys'] === []);

    fxDlgReset(fxDlgIndexFrom(['I need work.' => [], 'Never mind.' => []]));
    $mk();
    $GLOBALS['LRG_DLG_TEST_QSIG'] = 99;                      // a new journal row arrived
    $t->must('a journal change (qsig) invalidates the cache', fxDlgSay($npc, fxDlgSnap(), 'Work?')['keys'] === []);
});

fx_scenario('d25', 'ranking: a closed layer sends ALL entries (cap 12, engine order, never bucketed); a root list shows a ranked top 8 + the more-line; filler quests rank down', function (FxT $t) {
    $npc = 'Brenna Flowtest';
    // --- a closed layer: every entry, engine order
    $texts = [];
    for ($i = 0; $i < 14; $i++) { $texts[] = 'Option number ' . $i . ' of this closed layer'; }
    $spec = [];
    foreach ($texts as $x) { $spec[$x] = ['toplevel' => 0]; }
    fxDlgReset(fxDlgIndexFrom($spec), fxDlgLayer($texts));
    $ent = [];
    foreach ($texts as $i => $x) { $ent[] = fxDlgEntry($i, $x); }
    fxDlgTopics($npc, ['layer' => 1], $ent);
    $q = fxDlgSay($npc, fxDlgSnap(), 'The third one.');
    $t->must('a closed layer is capped at 12', count($q['keys']) === 12, count($q['keys']) . ' keys');
    $t->must('a closed layer keeps the ENGINE order (T1 is position 0)',
        (string) ($q['keys']['T1']['text'] ?? '') === $texts[0], (string) ($q['keys']['T1']['text'] ?? ''));
    $t->must('a closed layer is never bucketed into a more-line',
        !preg_match('/\(\+\d+ more/', $q['volatile']), fx_short($q['volatile'], 200));
    $t->must('the state says the NPC is waiting for an answer',
        str_contains($q['volatile'], 'is waiting for an answer'), fx_short($q['volatile'], 160));

    // --- a root list: ranked top 8 + the more-line, filler down, a quest in q up
    $root = ['I have the amulet you wanted.', 'I need work.', 'Have you heard any rumours?',
        'Command: wait here.', 'Command: follow me.', 'Command: trade items.', 'Command: relax.',
        'About the missing dog.', 'Tell me about Whiterun.', 'What is for sale?', 'Goodbye.'];
    $spec2 = [];
    foreach ($root as $x) { $spec2[$x] = []; }
    $spec2['I have the amulet you wanted.'] = ['quest' => 'FxAmulet', 'journal' => 1];
    foreach (['Command: wait here.', 'Command: follow me.', 'Command: trade items.', 'Command: relax.'] as $c) {
        $spec2[$c] = ['quest' => 'SFF_Commands', 'shared' => 60];
    }
    fxDlgReset(fxDlgIndexFrom($spec2));
    $ent2 = [];
    foreach ($root as $i => $x) { $ent2[] = fxDlgEntry($i, $x); }
    fxSendSnapshot($npc, fxDlgSnap());
    fxDlgTopics($npc, ['q' => 'FxAmulet'], $ent2);
    fxDlgEvent($npc, 'closed', ['sid' => 's1', 'why' => 'goodbye', 'pending' => 0]);
    fxDlgEvent($npc, 'facts', ['ref' => '0x00012345', 'q' => 'FxAmulet', 'sp' => 30, 'lvl' => 12, 'perk' => '-']);
    $q2 = fxDlgSay($npc, fxDlgSnap(), 'I have that amulet for you.');
    $t->must('a root list shows at most 8 keys', count($q2['keys']) <= 8, count($q2['keys']) . ' keys');
    $t->must('the more-line names the rest', (bool) preg_match('/\(\+\d+ more/', $q2['volatile']), fx_short($q2['volatile'], 200));
    $shown = array_column($q2['keys'], 'text');
    $t->must('the quest in q is ranked into the head', in_array('I have the amulet you wanted.', $shown, true),
        json_encode($shown));
    $cmds = count(array_filter($shown, static fn($x) => str_starts_with($x, 'Command:')));
    $t->must('a follower command framework is ranked down (at most 2 of its 4 reach the head)', $cmds <= 2, $cmds . ' command entries');
    $t->must('a question shared by more than 20 topics ranks down',
        !in_array('Have you heard any rumours?', array_slice($shown, 0, 2), true), json_encode($shown));
});
