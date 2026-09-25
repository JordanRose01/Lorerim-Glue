<?php
// PHASE 2 d44: THE CONVERSATION HOLD, server half (owner addendum 8, PROTOCOL 1.1 [0.4.1]).
// The game holds the NPC on the spot while a CHIM conversation with her is live and says so on the
// snapshot (hold=1). While it does, EndConversation must not be on the table - it is the one leave
// cause with hard log evidence, it fires ResetPackages + EvaluatePackage and hands her straight back
// to her schedule. Everything else about the turn must be unchanged, and every fall-through (no key,
// hold=0, a stale snapshot, the config off, another NPC) must leave CHIM's own behaviour alone.
require_once __DIR__ . '/../dlg_adapter.php';

fx_scenario('d44', 'conversation hold: EndConversation is withheld only while THIS NPC\'s fresh snapshot says hold=1, and nothing else about the turn changes', function (FxT $t) {
    $npc = 'Brenna Flowtest';
    // [pt19 v1.0] fxDlgCache leaves the list OPEN (on his screen) unless told to close it, and an open session hides
    // EndConversation on its own (d33): the hold is measured with the list closed and its root cached
    fxDlgCache($npc, ['I need work.' => [], 'Never mind.' => []], [], [], 1, true);
    // ...and on words that name nothing on it: a cached root plus his business words opens the list pre-LLM (S2.1), and that
    // open_pending turn hides EndConversation for its own reason - the hold must be seen on its own
    $said = 'How has your day been?';

    // 1. no hold key at all (an older game script): CHIM keeps every one of its own actions
    $q = fxDlgSay($npc, fxDlgSnap(), $said);
    $t->must('with no hold key EndConversation stays offered',
        in_array('EndConversation', $q['enabled'], true), implode(',', $q['enabled']));

    // 2. hold=0 is not a hold
    $q = fxDlgSay($npc, fxDlgSnap(['hold' => '0', 'dist' => '140']), $said);
    $t->must('hold=0 changes nothing', in_array('EndConversation', $q['enabled'], true), implode(',', $q['enabled']));

    // 3. hold=1, fresh: the model cannot choose to leave
    $q = fxDlgSay($npc, fxDlgSnap(['hold' => '1', 'dist' => '140']), $said);
    $t->must('a live hold withholds EndConversation',
        !in_array('EndConversation', $q['enabled'], true), implode(',', $q['enabled']));
    $t->must('... and nothing else is taken away: the business action is still offered',
        in_array(FX_DLG_ACT, $q['enabled'], true), implode(',', $q['enabled']));
    $t->must('... and CHIM\'s ordinary actions are untouched',
        in_array('Talk', $q['enabled'], true) && in_array('Follow', $q['enabled'], true), implode(',', $q['enabled']));
    $t->must('... and the business block is unchanged', str_contains($q['volatile'], '<business'), fx_short($q['volatile'], 160));

    // 4. the same hold, but the snapshot is older than one window: never trust it forever
    fxAdvance(120);
    $GLOBALS['ENABLED_FUNCTIONS'] = null;
    $q = fxDlgSay($npc, null, $said);
    $t->must('a stale hold= is ignored', in_array('EndConversation', $q['enabled'], true), implode(',', $q['enabled']));

    // 5. the hold belongs to ONE NPC: a turn answered as somebody else is untouched
    $other = 'Sella Flowtest';
    fxDlgCache($other, ['I need work.' => [], 'Never mind.' => []], [], [], 1, true);
    fxSendSnapshot($npc, fxDlgSnap(['hold' => '1', 'dist' => '140']));
    $q = fxDlgSay($other, fxDlgSnap(), $said);
    $t->must('another NPC\'s turn keeps EndConversation',
        in_array('EndConversation', $q['enabled'], true), implode(',', $q['enabled']));

    // 6. the config switch really switches it off
    $keep = $GLOBALS['LRG_TEST_NPCSTATE'] ?? null;
    $GLOBALS['LRG_TEST_NPCSTATE'] = [$npc => ['hold' => '1', '_age' => 1]];
    $GLOBALS['ENABLED_FUNCTIONS'] = ['Talk', 'EndConversation'];
    $GLOBALS['HERIKA_NAME'] = $npc;
    lrgDlgHideEndConversationOnHold();
    $t->must('the offline seam reproduces the hide', !in_array('EndConversation', $GLOBALS['ENABLED_FUNCTIONS'], true),
        implode(',', (array) $GLOBALS['ENABLED_FUNCTIONS']));
    $t->must('and it is idempotent - a second call on a list that no longer has it does nothing',
        (static function () { lrgDlgHideEndConversationOnHold(); return !in_array('EndConversation', (array) $GLOBALS['ENABLED_FUNCTIONS'], true); })(),
        implode(',', (array) $GLOBALS['ENABLED_FUNCTIONS']));
    if ($keep === null) { unset($GLOBALS['LRG_TEST_NPCSTATE']); } else { $GLOBALS['LRG_TEST_NPCSTATE'] = $keep; }
});
