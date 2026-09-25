<?php
// PHASE 2 d69 - [pt19 v1.0 / S4.1, S4.2, model F3, F20] A WALK-AWAY FLAG GUARDS LEAVING A LIST, NEVER CLICKING. Asked to leave
// a layer that has no line backing out cleanly while a line there walks out on her, the glue clicks nothing (do=show kind=back:
// the game answers OK and keeps driving, F3), her line says leaving is his to do, and her line is spoken (WillEmit false). A back
// line is clicked when one is listed; a root list is left with the engine cancel. The walk-away line itself is clickable on his
// own plain sentence (F20). The rows mirror the live index rows named; every sentence is the PLAYER's.
require_once __DIR__ . '/../dlg_adapter.php';

/** A layer on his screen at clicks_ok 1 (rows [text => overrides]), then his sentence and the model's LEAVE. */
function fxWalkLeave(string $npc, array $spec, int $layer, string $say, string $item = 'LEAVE'): array
{
    $texts = array_keys($spec);
    fxDlgReset(fxDlgIndexFrom($spec), $layer ? fxDlgLayer($texts) : []);
    lrgDlgPut('*install*', ['clicks_ok' => 1]);
    fxSendSnapshot($npc, fxDlgSnap());
    $ent = [];
    foreach ($texts as $i => $tx) { $ent[] = fxDlgEntry($i, (string) $tx); }
    fxDlgTopics($npc, ['sid' => 'w1', 'gen' => 1, 'layer' => $layer, 'origin' => 'engine'], $ent);
    fxAdvance(2);
    $q = fxDlgSay($npc, null, $say);
    if ($item !== 'LEAVE') {
        foreach ((array) $q['keys'] as $k => $v) { if (str_starts_with((string) $v['text'], $item)) { $item = (string) $k; break; } }
    }
    $will = is_array($q['turn'] ?? null) ? lrgDlgWillEmit((array) $q['turn'], $item) : false;
    $w = fxDlgLlm($npc, $item, 'As you wish.');
    return ['q' => $q, 'w' => $w, 'will' => $will];
}

fx_scenario('d69', '[pt19 v1.0 / S4.2, F3, F20] the leave guard: no back line + a walk-away line -> do=show kind=back, never the engine cancel, her line spoken and saying leaving is his; a back line is clicked; a root list is left with pos=-1; the walk-away line itself is clickable on his plain sentence (walk-away is no commit)', function (FxT $t) {
    // ---- 1. Brynjolf's intro layer (TG00, parent 04FCDB): both lines walk away (TG00BrynjolfWalkAwayTopic), none backs out
    $bry = 'Brynjolf Flowtest';
    $intro = ["I'm sorry, what?" => ['toplevel' => 0, 'quest' => 'TG00', 'journal' => 1, 'flags' => ['walkaway' => 1], 'crit' => 1, 'twat' => 'TG00BrynjolfWalkAwayTopic'],
        "Actually, I'm looking for this old guy hiding out in Riften." => ['toplevel' => 0, 'quest' => 'TG00', 'journal' => 1, 'flags' => ['walkaway' => 1], 'crit' => 1, 'twat' => 'TG00BrynjolfWalkAwayTopic']];
    $r = fxWalkLeave($bry, $intro, 1, 'I have to go.');
    $cmd = $r['w'] ? fxDlgKv($r['w'][0]) : [];
    $t->must('LEAVE on a guarded layer: do=show kind=back - nothing is clicked, the engine cancel (pos=-1) is never sent',
        count($r['w']) === 1 && ($cmd['do'] ?? '') === 'show' && ($cmd['kind'] ?? '') === 'back', json_encode(array_map('fxParam', $r['w'])));
    $t->must('...her <business> says leaving is his to do, and that it ends things with her',
        str_contains($r['q']['volatile'], 'No line here backs out cleanly; leaving is his to do by hand, and it ends things with her'), fx_short($r['q']['volatile'], 500));
    $t->must('...and WillEmit is FALSE: her line is spoken (the menu is already visible, OK: The menu is shown)', $r['will'] === false);

    // ---- 2. the walk-away line itself: his plain sentence clicks it - a walk-away flag guards LEAVING, never clicking (F20)
    $r2 = fxWalkLeave($bry, $intro, 1, "I'm sorry, what?", "I'm sorry, what?");
    $t->must('his own sentence "I\'m sorry, what?" clicks the walk-away line (no commit: unscripted, no goodbye)',
        count($r2['w']) === 1 && fxDlgDo($r2['w'][0]) === 'pick' && str_contains(fxParam($r2['w'][0]), "txt=I'm sorry, what"), json_encode(array_map('fxParam', $r2['w'])));
    $t->must('...and the label never calls it [commits]', !str_contains((string) ($r2['q']['volatile'] ?? ''), "[commits - cannot be undone] I'm sorry, what"), fx_short($r2['q']['volatile'], 400));

    // ---- 3. a layer WITH a back line: LEAVE clicks that line (pos >= 0)
    $nirya = 'Nirya Flowtest';
    $spell = ['Okay, this is for the spell. (Give 30 gold)' => ['toplevel' => 0, 'quest' => 'MG01', 'journal' => 1, 'scripted' => 1, 'flags' => ['goodbye' => 1], 'cost' => 30],
        "Never mind, I'm not interested." => ['toplevel' => 0, 'quest' => 'MG01', 'journal' => 1]];
    $r3 = fxWalkLeave($nirya, $spell, 1, 'no thanks, I have to go');
    $kv3 = $r3['w'] ? fxDlgKv($r3['w'][0]) : [];
    $t->must('LEAVE with a back line listed: do=leave on "Never mind, I\'m not interested." (pos 1), not the engine cancel',
        count($r3['w']) === 1 && ($kv3['do'] ?? '') === 'leave' && (int) ($kv3['pos'] ?? -1) === 1, json_encode(array_map('fxParam', $r3['w'])));
    $t->must('...and that back click IS emitted (WillEmit true: the click is her answer)', $r3['will'] === true);

    // ---- 4. a root list (Hulda's): nothing walks out, no back line - the engine cancel ends the talk (pos=-1)
    $hulda = 'Hulda Flowtest';
    $root = ['What have you got for sale?' => ['toplevel' => 1, 'scripted' => 1, 'quest' => 'DialogueGeneric'],
        'Nice inn you have here. Do you get many visitors?' => ['toplevel' => 1, 'quest' => 'ACFDialogueWhiterun'],
        'Heard any rumors lately?' => ['toplevel' => 1, 'scripted' => 1, 'quest' => 'DarkBrotherhood']];
    $r4 = fxWalkLeave($hulda, $root, 0, 'I have to go.');
    $kv4 = $r4['w'] ? fxDlgKv($r4['w'][0]) : [];
    $t->must('LEAVE on a root list with nothing walking out: do=leave;pos=-1 (the engine cancel)', count($r4['w']) === 1 && ($kv4['do'] ?? '') === 'leave'
        && (int) ($kv4['pos'] ?? 0) === -1, json_encode(array_map('fxParam', $r4['w'])));
    $t->must('...and no leave-guard line rides that turn', !str_contains($r4['q']['volatile'], 'No line here backs out cleanly'), fx_short($r4['q']['volatile'], 300));

    // ---- 5. Delphine's walk-out ("I don't have time for this.", scripted + goodbye, the walk-away TOPIC of its parent): his plain
    // sentence is the confirmation (F20: the always-asks row is price / follower dismiss / never_auto, not walk-away)
    $del = 'Delphine Flowtest';
    $horn = ['I just came here for the horn.' => ['toplevel' => 0, 'quest' => 'MQ106', 'journal' => 1, 'scripted' => 1, 'flags' => ['walkaway' => 1], 'twat' => 'MQ106DelphineIntroExclusiveA3'],
        "I don't have time for this." => ['toplevel' => 0, 'quest' => 'MQ106', 'journal' => 1, 'scripted' => 1, 'flags' => ['goodbye' => 1]],
        'Who are you?' => ['toplevel' => 0, 'quest' => 'MQ106', 'journal' => 1, 'scripted' => 1]];
    $r5 = fxWalkLeave($del, $horn, 1, "I don't have time for this.", "I don't have time for this.");
    $t->must('"I don\'t have time for this." said plainly clicks the walk-out line at once (explicit, F20)', count($r5['w']) === 1 && fxDlgDo($r5['w'][0]) === 'pick',
        json_encode(array_map('fxParam', $r5['w'])));
    $r6 = fxWalkLeave($del, $horn, 1, 'I have to go.');
    $kv6 = $r6['w'] ? fxDlgKv($r6['w'][0]) : [];
    $t->must('...while LEAVE on that layer is guarded (a line there walks out): do=show kind=back', count($r6['w']) === 1 && ($kv6['do'] ?? '') === 'show',
        json_encode(array_map('fxParam', $r6['w'])));
});
