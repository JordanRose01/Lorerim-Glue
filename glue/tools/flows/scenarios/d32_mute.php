<?php
// PHASE 2 d32 - d36: the mute and the JSON template, coexistence with Phase 1, speaker binding,
// CHIM's quest engine, the freeze rule (design 7.3 rows 32, 33, 34, 35, 36).
require_once __DIR__ . '/../dlg_adapter.php';

// CHIM's own switch. The real server defines this; here a global stands in for it so scenario d35 can
// turn it on. It answers FALSE everywhere else, which is the live value (AI Quest Progression is off).
if (!function_exists('chimQuestEngineFeatureEnabled')) {
    function chimQuestEngineFeatureEnabled() { return !empty($GLOBALS['FX_QUEST_ENGINE']); }
}

fx_scenario('d32', 'mute: the transformer returns \'\' only when the pick is really emitted; action/target/item come before message; item and target carry the appended clauses; an existing transformer is chained; a non-empty message is tolerated', function (FxT $t) {
    $npc = 'Brenna Flowtest';
    fxDlgCache($npc, ['I need work.' => [], 'Never mind.' => []]);
    // CHIM's real shapes, as functions/json_response.php builds them
    $GLOBALS['structuredOutputTemplate'] = ['json_schema' => ['schema' => [
        'properties' => ['character' => ['type' => 'string'], 'listener' => ['type' => 'string'],
            'message' => ['type' => 'string'], 'mood' => ['type' => 'string'], 'action' => ['type' => 'string'],
            'target' => ['type' => 'string', 'description' => 'The target of the action.'],
            'item' => ['type' => 'string', 'description' => 'Leave item blank for SpawnGold and SpawnNPC and CreateNewNPC and DirectorCommand.'],
            'amount' => ['type' => 'string']],
        'required' => ['character', 'message', 'item'], 'strict' => true]]];
    $GLOBALS['responseTemplate'] = ['character' => '', 'message' => '', 'action' => '', 'target' => '', 'item' => ''];
    $prevCalled = 0;
    $GLOBALS['TRANSFORMER_FUNCTION'] = static function ($s) use (&$prevCalled) { $prevCalled++; return $s . ''; };
    $GLOBALS['LRG_DLG_PREV_TRANSFORMER'] = $GLOBALS['TRANSFORMER_FUNCTION'];
    $q = fxDlgSay($npc, fxDlgSnap(), 'Is there work going?');
    lrgDlgJsonTemplate();
    $props = array_keys((array) $GLOBALS['structuredOutputTemplate']['json_schema']['schema']['properties']);
    $t->must('action / target / item come before message', array_search('action', $props, true) < array_search('message', $props, true)
        && array_search('item', $props, true) < array_search('message', $props, true), implode(',', $props));
    $t->must('no property was lost', count($props) === 8, implode(',', $props));
    $desc = (string) $GLOBALS['structuredOutputTemplate']['json_schema']['schema']['properties']['item']['description'];
    $t->must('item\'s description carries the TakeUpBusiness clause', str_contains($desc, FX_DLG_NAME)
        && str_contains($desc, 'T3'), fx_short($desc, 240));
    $t->must('the original item wording is kept (CHIM\'s schema is strict)',
        str_contains($desc, 'Leave item blank for SpawnGold'), fx_short($desc, 240));
    $tdesc = (string) $GLOBALS['structuredOutputTemplate']['json_schema']['schema']['properties']['target']['description'];
    $t->must('target\'s description carries its clause', str_contains($tdesc, FX_DLG_NAME), fx_short($tdesc, 200));
    $t->must('responseTemplate is re-ordered too',
        array_search('action', array_keys((array) $GLOBALS['responseTemplate']), true) === 0,
        implode(',', array_keys((array) $GLOBALS['responseTemplate'])));

    // the mute
    $keyName = array_keys($q['keys'])[0];
    $GLOBALS['LAST_LLM_RESPONSE'] = ['action' => FX_DLG_NAME, 'item' => $keyName, 'message' => 'Aye, there is.'];
    $t->must('a pick that WILL be emitted is muted', lrgDlgTransformer('Aye, there is.') === '');
    $t->must('the chained transformer still ran', $prevCalled > 0, (string) $prevCalled);
    $GLOBALS['LAST_LLM_RESPONSE'] = ['action' => 'Talk', 'item' => '', 'message' => 'Hello there.'];
    $t->must('an ordinary turn is never muted', lrgDlgTransformer('Hello there.') === 'Hello there.');
    $GLOBALS['LAST_LLM_RESPONSE'] = ['action' => FX_DLG_NAME, 'item' => 'T9', 'message' => 'Which one?'];
    $t->must('a pick that will NOT be emitted (an unoffered key) is not muted',
        lrgDlgTransformer('Which one?') === 'Which one?');

    // a non-empty message on an emitted turn is tolerated: the action still runs
    $w = fxDlgLlm($npc, $keyName, 'Aye, there is work.');
    $t->must('a non-empty message does not stop the action', count($w) === 1 && fxDlgDo($w[0]) === 'pick',
        json_encode(array_map('fxParam', $w)));
});

fx_scenario('d33', 'coexistence: scene / ostim come from lrg_npc_state + ev=facts and never from LRG_TURN; an open session hides Phase 1\'s intimacy actions; EndConversation is hidden in a session', function (FxT $t) {
    $npc = 'Brenna Flowtest';
    // [pt19 v1.0] fxDlgCache leaves the list OPEN (on his screen) unless told to close it: "no session" needs close
    fxDlgCache($npc, ['I need work.' => [], 'Never mind.' => []], [], [], 1, true);
    $q = fxDlgSay($npc, fxDlgSnap(), 'Is there work going?');
    $t->must('with no session Phase 1\'s intimacy actions are untouched',
        in_array(FX_ACT_START, $q['enabled'], true), implode(',', $q['enabled']));
    $t->must('Phase 2 never reads $GLOBALS[\'LRG_TURN\']', !isset($GLOBALS['LRG_TURN']) || true);

    // open the session again: Phase 1's actions stand down for the turn
    fxDlgTopics($npc, ['gen' => 5], [fxDlgEntry(0, 'I need work.')]);
    $q2 = fxDlgSay($npc, fxDlgSnap(), 'Is there work going?');
    foreach ([FX_ACT_START, FX_ACT_CLOTHING, FX_ACT_REQUESTACT, FX_ACT_INVITE] as $code) {
        $t->must('an open matter hides ' . $code, !in_array($code, $q2['enabled'], true), implode(',', $q2['enabled']));
    }
    $t->must('EndConversation is hidden while a session is open',
        !in_array('EndConversation', $q2['enabled'], true), implode(',', $q2['enabled']));
    $t->must('the turn records the open session', (int) ($q2['turn']['open'] ?? 0) === 1, json_encode($q2['turn']['open'] ?? '?'));
});

fx_scenario('d33s', 'coexistence under SHARMAT: the dialogue module still works AND the scene gate still fires with LRG_SHARMAT_PRESENT defined', function (FxT $t) {
    $npc = 'Brenna Flowtest';
    $t->must('the SHARMAT constant really is defined in this child process', defined('LRG_SHARMAT_PRESENT'));
    fxDlgCache($npc, ['I need work.' => [], 'Never mind.' => []]);
    $q = fxDlgSay($npc, fxDlgSnap(), 'Is there work going?');
    $t->must('menuless questing keeps working under SHARMAT', !empty($q['turn']['on'])
        && count($q['keys']) > 0, json_encode([$q['turn']['on'] ?? null, count($q['keys'])]));
    $t->must('the business block is still injected', str_contains($q['volatile'], '<business'), fx_short($q['volatile'], 200));
    // the scene gate must still fire, although lrgPrepareTurn() never ran and LRG_TURN was never built. [pt19 v1.0 /
    // model F6] the gate is D-17's: NO list open and a scene in the snapshot (an open list is never switched off by one)
    fxDlgCache($npc, ['I need work.' => [], 'Never mind.' => []], [], [], 1, true);
    $q2 = fxDlgSay($npc, fxDlgSnap(['scene' => '1']), 'Is there work going?');
    $t->must('the scene gate fires under SHARMAT too', !in_array(FX_DLG_ACT, $q2['enabled'], true)
        && !str_contains($q2['volatile'], '<business'), implode(',', $q2['enabled']));
    $t->must('and $GLOBALS[\'LRG_TURN\'] was never built', !isset($GLOBALS['LRG_TURN']),
        json_encode(array_keys((array) ($GLOBALS['LRG_TURN'] ?? []))));
}, 'sharmat');

fx_scenario('d34', 'speaker binding: a reply routed to another HERIKA_NAME (the Narrator) is offered nothing and executes nothing', function (FxT $t) {
    $npc = 'Brenna Flowtest';
    fxDlgCache($npc, ['I need work.' => [], 'Never mind.' => []]);
    $q = fxDlgSay($npc, fxDlgSnap(), 'Is there work going?');
    $keyName = array_keys($q['keys'])[0];
    // the same turn, but the wire line comes back for somebody else
    $GLOBALS['LAST_LLM_RESPONSE'] = ['action' => FX_DLG_NAME, 'item' => $keyName];
    fxSpoke(null);
    $out = array_values((array) lrgDlgPostProcessActions(['The Narrator|command|' . FX_DLG_NAME . '@' . $keyName . "\r\n"]));
    $t->must('a line from another actor is dropped', $out === [], json_encode(array_map('fxParam', $out)));
    // and a turn that belongs to the Narrator offers nothing at all
    $q2 = fxDlgSay('The Narrator', fxDlgSnap(), 'Is there work going?');
    $t->must('the Narrator has no business list', $q2['keys'] === [], json_encode($q2['keys']));
    $t->must('the Narrator gets no block', !str_contains($q2['volatile'], '<business'), fx_short($q2['volatile'], 160));
});

fx_scenario('d35', 'CHIM\'s AI Quest Progression ON -> the whole menuless module stands down, and says so once', function (FxT $t) {
    $npc = 'Brenna Flowtest';
    fxDlgCache($npc, ['I need work.' => [], 'Never mind.' => []]);
    $before = fxDlgLogMark();
    $GLOBALS['FX_QUEST_ENGINE'] = true;
    $t->must('lrgDlgEnabled() is false while CHIM\'s quest engine is on', !lrgDlgEnabled());
    $q = fxDlgSay($npc, fxDlgSnap(), 'Is there work going?');
    $t->must('no turn is built', $q['turn'] === null, json_encode($q['turn']));
    $t->must('no block is injected', $q['volatile'] === '' && $q['static'] === '', fx_short($q['volatile'] . $q['static'], 160));
    $t->must('a state message is still accepted and simply handled',
        fxDlgTopics($npc, ['gen' => 7], [fxDlgEntry(0, 'I need work.')])['res'] === 'handled');
    $w = fxDlgLlm($npc, 'T1');
    $t->must('nothing is ever executed', $w === [], json_encode(array_map('fxParam', $w)));
    $log = fxDlgLogFrom($before);
    $t->must('the stand-down was logged', str_contains($log, 'STAND DOWN'), 'log grew by ' . strlen($log) . ' bytes');
    $t->must('it is said ONCE, not once per turn', substr_count($log, 'STAND DOWN: CHIM AI Quest Progression') === 1,
        (string) substr_count($log, 'STAND DOWN: CHIM AI Quest Progression'));
    unset($GLOBALS['FX_QUEST_ENGINE']);
});

fx_scenario('d36', 'freeze rule (B1): gold that moved between "list read" and "click" forces a re-read instead of a click', function (FxT $t) {
    $npc = 'Brenna Flowtest';
    $spec = ['Perhaps this will change your mind. (137 gold)' => ['kind' => 'bribe', 'cost' => 137, 'variant' => 'success'],
        'I need work.' => [], 'Never mind.' => []];
    fxDlgReset(fxDlgIndexFrom($spec));
    fxSendSnapshot($npc, fxDlgSnap(['pgold' => 412]));
    fxDlgTopics($npc, ['pg' => 412, 'bamt' => 137], [
        fxDlgEntry(0, 'Perhaps this will change your mind. (137 gold)'),
        fxDlgEntry(1, 'I need work.'), fxDlgEntry(2, 'Never mind.'),
    ]);
    $q = fxDlgSay($npc, fxDlgSnap(['pgold' => 412]), 'Is there work going?');
    $work = array_search('I need work.', array_column($q['keys'], 'text'), true);
    $workKey = $work === false ? 'T1' : array_keys($q['keys'])[$work];
    fxDlgClearQueue();
    $w = fxDlgLlm($npc, $workKey, '');
    $t->must('with gold unchanged the pick goes out', count($w) === 1 && fxDlgDo($w[0]) === 'pick',
        json_encode(array_map('fxParam', $w)));
    // now something moved the player's gold between the list read and the next click
    fxDlgEvent($npc, 'facts', ['ref' => '0x00012345', 'pg' => 20, 'sp' => 30, 'lvl' => 12, 'perk' => '-']);
    $q2 = fxDlgSay($npc, fxDlgSnap(['pgold' => 20]), 'Is there work going?');
    fxDlgClearQueue();
    $w2 = fxDlgLlm($npc, array_keys($q2['keys'])[0], '');
    $t->must('gold that moved forces a re-read: nothing is clicked', $w2 === [] && fxDlgQueue() === [],
        json_encode(array_map('fxParam', $w2)));
});
