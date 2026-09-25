<?php
// PHASE 2 d20 / d20b - the wire (design 7.3 rows 20, 20b). No CHIM, no LLM, no game.
require_once __DIR__ . '/../dlg_adapter.php';

fx_scenario('d20', 'wire: lrg_topics parsing, ; and = inside a text, e last, ~~ splitting, the entry cap', function (FxT $t) {
    $npc = 'Brenna Flowtest';
    fxDlgReset(fxDlgIndexFrom([
        'I need work.' => ['quest' => 'FxWork', 'journal' => 1],
        "What's the word; any news = at all?" => [],
        'Never mind.' => [],
    ]));
    $t->mustCap('dlg_wire', 'lrgDlgHandleGameMessage handles lrg_topics', fn() => true);
    $r = fxDlgTopics($npc, ['n' => 3], [
        fxDlgEntry(0, 'I need work.', ['new' => 1]),
        fxDlgEntry(1, "What's the word; any news = at all?"),
        fxDlgEntry(2, 'Never mind.'),
    ]);
    $t->must('the request is handled (never enters the dialogue pipeline)', $r['res'] === 'handled', $r['res']);
    $sess = (array) ($r['state']['session'] ?? []);
    $e = (array) ($sess['entries'] ?? []);
    $t->must('three entries arrived', count($e) === 3, 'got ' . count($e));
    $t->must('a text containing ; and = survives intact',
        (string) ($e[1]['text'] ?? '') === "What's the word; any news = at all?", (string) ($e[1]['text'] ?? ''));
    $t->must('pos / topicIndex / new are parsed', (int) ($e[0]['pos'] ?? -1) === 0 && (int) ($e[0]['i'] ?? -1) === 100
        && (int) ($e[0]['new'] ?? 0) === 1, json_encode(array_intersect_key((array) ($e[0] ?? []), array_flip(['pos', 'i', 'new']))));
    $t->must('the index classified the entries', (string) ($e[0]['quest'] ?? '') === 'FxWork'
        && (int) ($e[0]['indexed'] ?? 0) === 1, json_encode([$e[0]['quest'] ?? '', $e[0]['indexed'] ?? '']));
    $t->must('a back-out entry is class back', (string) ($e[2]['class'] ?? '') === 'back', (string) ($e[2]['class'] ?? ''));
    $t->must('the list is remembered as the ROOT cache', isset($r['state']['root']['entries']),
        implode(',', array_keys($r['state'])));
    $t->must('nothing was queued: want=0 asks for no decision', fxDlgQueue() === [], json_encode(fxDlgQueue()));

    // an entry whose text contains a bare ~~ cannot exist: the game replaces ~ with -. Prove the split anyway.
    fxDlgClearQueue();
    $r2 = fxDlgTopics($npc, ['gen' => 1, 'n' => 1], [fxDlgEntry(0, 'A-B and C-D')]);
    $t->must('one entry, no spurious split', count((array) ($r2['state']['session']['entries'] ?? [])) === 1,
        json_encode($r2['state']['session']['entries'] ?? []));
});

fx_scenario('d20b', 'payload: n is the FULL count in both parts, the tail arrives as part=2, head+tail are ranked together', function (FxT $t) {
    $npc = 'Brenna Flowtest';
    $texts = [];
    for ($i = 0; $i < 24; $i++) { $texts[] = 'Matter number ' . $i . ' about the ' . str_repeat('long ', 3) . 'business'; }
    $texts[19] = 'I saw a Missives board notice about a bounty';
    $spec = [];
    foreach ($texts as $x) { $spec[$x] = []; }
    $spec['I saw a Missives board notice about a bounty'] = ['quest' => 'MissivesFx', 'journal' => 1, 'new' => 1];
    fxDlgReset(fxDlgIndexFrom($spec));
    // the game caps the head at iMaxEntries AND at 1,600 raw chars; n stays the full EntryCount()
    $head = [];
    for ($i = 0; $i < 16; $i++) { $head[] = fxDlgEntry($i, $texts[$i]); }
    $r1 = fxDlgTopics($npc, ['n' => 24, 'part' => 1], $head);
    $t->must('part=1 keeps n = the full count', (int) ($r1['state']['session']['n'] ?? 0) === 24,
        (string) ($r1['state']['session']['n'] ?? '?'));
    $t->must('part=1 sent 16', (int) ($r1['state']['session']['sent'] ?? 0) === 16, (string) ($r1['state']['session']['sent'] ?? '?'));
    // with only the head known, the NPC may NOT deny that a matter exists (CMP-M4)
    $q1 = fxDlgSay($npc, fxDlgSnap(), 'Any bounty notices around here?');
    $t->must('while sent < n the NPC may NOT deny the matter exists',
        str_contains(strtolower($q1['volatile']), 'may not say that a thing is not on the table'), fx_short($q1['volatile'], 400));
    $tail = [];
    for ($i = 16; $i < 24; $i++) { $tail[] = $i . '~-~-~-~' . $texts[$i]; }
    $r2 = fxDlgTopics($npc, ['n' => 24, 'part' => 2], $tail);
    $e = (array) ($r2['state']['session']['entries'] ?? []);
    $t->must('head and tail are re-assembled into 24 entries', count($e) === 24, 'got ' . count($e));
    $t->must('n is still the full count after the tail', (int) ($r2['state']['session']['n'] ?? 0) === 24,
        (string) ($r2['state']['session']['n'] ?? '?'));
    $t->must('tail entries are marked tail=1', (int) ($e[20]['tail'] ?? 0) === 1, json_encode($e[20] ?? []));

    // ranking runs over head UNION tail: the position-19 Missives topic must reach the block
    $q = fxDlgSay($npc, fxDlgSnap(), 'Any bounty notices around here?');
    $joined = strtolower($q['volatile']);
    $t->must('ranking runs over head UNION tail: the position-19 Missives topic reaches the block',
        str_contains($joined, 'missives board notice'), fx_short($q['volatile'], 400));
    $t->must('with the whole list known the prohibition is gone',
        !str_contains($joined, 'may not say that a thing is not on the table'), fx_short($q['volatile'], 200));
    $t->must('the (+N more) line names the rest', (bool) preg_match('/\(\+\d+ more/', $q['volatile']), fx_short($q['volatile'], 300));
});
