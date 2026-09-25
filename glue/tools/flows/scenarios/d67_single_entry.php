<?php
// PHASE 2 d67 - [pt19 v1.0 / S4.5, S4.6] A SINGLE-ENTRY LAYER NEVER ASKS: his new words release it, an effect-free continuation
// advances by itself after the breath, and nothing older than the last click may act on it ("assignment waits"). The rows mirror
// the live index rows named (skyrim.esm); every sentence is the PLAYER's.
require_once __DIR__ . '/../dlg_adapter.php';

/** One closed layer (a single line or more) on his screen at clicks_ok 1; returns the entries. */
function fxSingleList(string $npc, array $spec, string $sid, int $gen, array $kv = []): array
{
    $ent = [];
    $i = 0;
    foreach ($spec as $text => $over) { $ent[] = fxDlgEntry($i++, (string) $text); }
    return ['ent' => $ent, 'r' => fxDlgTopics($npc, ['sid' => $sid, 'gen' => $gen, 'layer' => 1, 'origin' => 'glue'] + $kv, $ent)];
}

fx_scenario('d67', '[pt19 v1.0 / S4.5, S4.6] single-entry layers: the assignment WAITS (his intro sentence clicked the intro, the B1 layer that follows is not released by the same words), his own words release it, "uh what now" does not; an unscripted single advances by itself (adv, the breath counted from the END of her line) and a question to her re-arms it; a commit single releases on the line and parks on a foreign word', function (FxT $t) {
    $far = 'Farengar Flowtest';
    $intro = 'Have you learned anything about the Dragons? Do you need any help?';
    $b1 = 'So what do you need me to do?';
    // MQ103FarengarIntroTopic 0D50E8 (plain, unscripted) - MQ103FarengarIntroB1 0D50E1 (scripted, no goodbye: a non-commit single)
    $rows = fxDlgIndexFrom([
        $intro => ['toplevel' => 0, 'quest' => 'MQ103', 'journal' => 1, 'topic' => 'MQ103FarengarIntroTopic'],
        $b1 => ['toplevel' => 0, 'quest' => 'MQ103', 'journal' => 1, 'scripted' => 1, 'topic' => 'MQ103FarengarIntroB1'],
        'What do you think of the war?' => ['toplevel' => 1, 'quest' => 'ACFDialogueWhiterun'],
    ]);

    // ---- 1. "assignment waits": his sentence clicks the intro line; the B1 single that follows is NOT released by those same words
    fxDlgReset($rows);
    lrgDlgPut('*install*', ['clicks_ok' => 1]);
    fxSendSnapshot($far, fxDlgSnap(['fac' => 'CourtWizardFaction']));
    $q = fxDlgSay($far, null, 'Have you learned anything about the dragons? Do you need any help?');
    fxAdvance(5);
    $r = fxDlgTopics($far, ['sid' => 'f1', 'gen' => 0, 'layer' => 0, 'origin' => 'glue', 'want' => 1, 'cid' => (string) ($q['turn']['cid'] ?? 'f')],
        [fxDlgEntry(0, $intro), fxDlgEntry(1, 'What do you think of the war?')]);
    $t->must('his sentence picks the intro line (D1)', str_contains($r['echo'], ';do=pick;') && str_contains($r['echo'], ';pos=0;'), fx_short($r['echo'], 200));
    fxAdvance(3);
    $mark = fxDlgLogMark();
    $r2 = fxSingleList($far, [$b1 => []], 'f1', 1, ['want' => 1]);
    $t->must('the assignment WAITS: the B1 single arriving after that click is not released by the same words (nothing on D1)',
        !str_contains($r2['r']['echo'], ';do=pick;'), fx_short($r2['r']['echo'], 200) . ' | ' . fx_short(fxDlgLogFrom($mark), 300));
    $t->must('...and it does not advance by itself either: it is scripted (the breath never clicks a scripted line)',
        !str_contains($r2['r']['echo'], ';adv='), fx_short(fxDlgLogFrom($mark), 300));
    // his NEW words release it (S4.5 step 2, the line said)
    fxAdvance(4);
    $q = fxDlgSay($far, null, 'so what do you need me to do');
    fxAdvance(2);
    $mark = fxDlgLogMark();
    $r3 = fxSingleList($far, [$b1 => []], 'f1', 2, ['want' => 1, 'cid' => (string) ($q['turn']['cid'] ?? 'f')]);
    $t->must('his NEW words release the single (mode single, no question asked)', str_contains($r3['r']['echo'], ';do=pick;')
        && (bool) preg_match('/emit npc=Farengar Flowtest do=pick mode=single pos=0/', fxDlgLogFrom($mark)), fx_short(fxDlgLogFrom($mark), 400));
    // "uh what now" releases nothing (step 6: no content, and a scripted single waits)
    fxReset();
    fxDlgReset($rows);
    lrgDlgPut('*install*', ['clicks_ok' => 1]);
    fxSendSnapshot($far, fxDlgSnap());
    $q = fxDlgSay($far, null, 'uh what now');
    fxAdvance(3);
    $r4 = fxSingleList($far, [$b1 => []], 'f2', 1, ['want' => 1, 'cid' => (string) ($q['turn']['cid'] ?? 'f')]);
    $t->must('"uh what now" releases nothing on the scripted single', !str_contains($r4['r']['echo'], ';do=pick;'), fx_short($r4['r']['echo'], 200));

    // ---- 2. an UNSCRIPTED single (MQ104BalgruufOutroC1 "The Greybeards?", walk-away, no script): the breath advances it
    fxReset();
    $jarl = 'Balgruuf Flowtest';
    $grey = 'The Greybeards?';
    $grows = fxDlgIndexFrom([$grey => ['toplevel' => 0, 'quest' => 'MQ104', 'journal' => 1, 'flags' => ['walkaway' => 1], 'crit' => 1, 'topic' => 'MQ104BalgruufOutroC1']]);
    fxDlgReset($grows);
    lrgDlgPut('*install*', ['clicks_ok' => 1]);
    fxSendSnapshot($jarl, fxDlgSnap());
    $r5 = fxSingleList($jarl, [$grey => []], 'g1', 1, ['want' => 1]);
    $t->must('no new words: the continuation advances by itself (adv=2500 - the grace counts from the END of her line, in game)',
        str_contains($r5['r']['echo'], ';do=pick;') && str_contains($r5['r']['echo'], ';adv=2500'), fx_short($r5['r']['echo'], 220));
    $t->must('...with no rearm=1: the re-arm follows only a question turn of his (S4.6)', !str_contains($r5['r']['echo'], ';rearm=1'), fx_short($r5['r']['echo'], 220));
    fxDlgReset($grows);
    lrgDlgPut('*install*', ['clicks_ok' => 1]);
    fxSendSnapshot($jarl, fxDlgSnap());
    fxSingleList($jarl, [$grey => []], 'g2', 1);
    fxAdvance(2);
    $q = fxDlgSay($jarl, null, 'who are the Greybeards');
    $w = fxDlgLlm($jarl, 'T1', 'Masters of the Voice.');
    $t->must('"who are the Greybeards" releases it (containment, and greybeards is a shared strict word): mode single',
        count($w) === 1 && fxDlgDo($w[0]) === 'pick' && !str_contains(fxParam($w[0]), ';adv='), json_encode(array_map('fxParam', $w)));
    // a question to her on a STATEMENT continuation (MQ102ALegionOath1, unscripted, walk-away): step 0 refuses it by shape, and the
    // gate re-arms the breath in the same reply (adv + rearm=1): the oath still advances after her answer (S4.6, model row 24)
    fxReset();
    $oath1 = '"Upon my honor I do swear undying loyalty to the Emperor, Titus Mede II..."';
    fxDlgReset(fxDlgIndexFrom([$oath1 => ['toplevel' => 0, 'quest' => 'CW01A', 'journal' => 1, 'flags' => ['walkaway' => 1], 'crit' => 1,
        'twat' => 'MQ102AJoinLegionInterrupted', 'topic' => 'MQ102ALegionOath1']]));
    lrgDlgPut('*install*', ['clicks_ok' => 1]);
    fxSendSnapshot('Tullius Flowtest', fxDlgSnap());
    fxSingleList('Tullius Flowtest', [$oath1 => []], 'q1', 1);
    fxAdvance(2);
    fxDlgSay('Tullius Flowtest', null, 'what does that mean?');
    $w = fxDlgLlm('Tullius Flowtest', 'T1', 'It means you serve the Empire.');
    $t->must('a question to her on a statement continuation releases nothing by his words; the gate re-arms the breath (adv + rearm=1, S4.6)',
        count($w) === 1 && str_contains(fxParam($w[0]), ';adv=') && str_contains(fxParam($w[0]), ';rearm=1'), json_encode(array_map('fxParam', $w)));

    // ---- 3. a COMMIT single (MQ102ALegionOath4, scripted + goodbye): the line releases it, a foreign word parks it
    $tul = 'Tullius Flowtest';
    $oath = '"Long live the Emperor! Long live the Empire!"';
    $orows = fxDlgIndexFrom([$oath => ['toplevel' => 0, 'quest' => 'CW01A', 'journal' => 1, 'scripted' => 1, 'flags' => ['goodbye' => 1], 'topic' => 'MQ102ALegionOath4']]);
    $cases = [['long live the Emperor', 'release'], ['long live Ulfric', 'park'], ['yes, long live Ulfric', 'park'], ['wait, what does that mean?', 'nothing']];
    foreach ($cases as [$say, $want]) {
        fxReset();   // each sentence on its own list: a park of the one before must not answer for it (the cross-turn case is test_questline's)
        fxDlgReset($orows);
        lrgDlgPut('*install*', ['clicks_ok' => 1]);
        fxSendSnapshot($tul, fxDlgSnap());
        fxSingleList($tul, [$oath => []], 'o1', 1);
        fxAdvance(2);
        fxDlgSay($tul, null, $say);
        $w = fxDlgLlm($tul, 'T1', 'Say it, then: "Long live the Emperor! Long live the Empire!"?');
        $parked = (string) ((fxDlgStateOf($tul)['parked'] ?? [])['norm'] ?? '');
        if ($want === 'release') {
            $t->must("commit single: \"$say\" releases the oath line (S4.5 step 2, precision 1.0)", count($w) === 1 && fxDlgDo($w[0]) === 'pick', json_encode(array_map('fxParam', $w)));
        } elseif ($want === 'park') {
            $t->must("commit single: \"$say\" clicks nothing - a foreign word parks it and she asks, quoting the line", $w === [] && $parked !== '', json_encode([array_map('fxParam', $w), $parked]));
        } else {
            $t->must("commit single: \"$say\" clicks nothing (S4.5 step 0: a question to her)", $w === [] || !array_filter($w, static fn($l) => fxDlgDo((string) $l) === 'pick' && !str_contains(fxParam((string) $l), ';adv=')),
                json_encode(array_map('fxParam', $w)));
        }
    }
});
