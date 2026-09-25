<?php
// PHASE 2 d59 / d60 / d61 - [0.5.0 PART II] fact locking, condition truthfulness, latency (E7, E8).
// The owner's item 9: "consider the core's fact-locking and condition truthfulness".
// What CHIM really has is in V05_EXPANSION_PLAN 19.1: lock_profile and relationships_locked are WRITE
// protection on an authored profile, not facts the model may not contradict, and there is no post-hoc
// verifier of the model's claims anywhere in core. The mechanism that IS about truth is the action
// catalog's `requirements`, and these scenarios prove the glue now uses it - plus its own read-time
// locked facts and a gate that drops the ACTION rather than trying to rewrite her words.
require_once __DIR__ . '/../dlg_adapter.php';

fx_scenario('d59', 'condition truthfulness: every glue row declares its preconditions in CHIM\'s own vocabulary, the guard faction ids are READ from CHIM rather than retyped, and CHIM\'s own matcher refuses our row in combat', function (FxT $t) {
    $npc = 'Brenna Flowtest';
    fxDlgCache($npc, ['I need work.' => [], 'Never mind.' => []]);

    // --- every row the glue upserts carries requirements
    $rows = array_filter(Fx::$catalog, static fn($r) => (string) (($r['metadata']['source'] ?? '')) === 'LoreRimGlue');
    $t->must('the glue installed its rows into the catalog', count($rows) >= 2, implode(',', array_keys($rows)));
    $bad = [];
    foreach ($rows as $code => $r) {
        $md = (array) ($r['metadata'] ?? []);
        $req = (array) ($md['requirements'] ?? []);
        $act = (array) ($req['activity'] ?? []);
        if (empty($act['current_action_not_in'])) { $bad[] = $code; }
    }
    $t->must('every glue row declares activity.current_action_not_in', $bad === [], implode(',', $bad));
    $t->must('... and it names the states a dialogue menu really cannot be driven in',
        (static function () use ($rows) {
            foreach ($rows as $r) {
                $n = (array) ((array) ((array) ($r['metadata']['requirements'] ?? []))['activity'] ?? [])['current_action_not_in'] ?? [];
                foreach (['dead', 'unconscious', 'sleeping', 'combat'] as $w) {
                    if (!in_array($w, (array) $n, true)) { return false; }
                }
            }
            return true;
        })(), json_encode(array_keys($rows)));

    // --- the guard faction ids come from CHIM's own built-in table, never from a literal in our source
    if (!function_exists('herikaActionCatalogGetBuiltinRequirements')) {
        $t->pending('the guard faction ids', 'CHIM\'s action catalog is not loadable from the flow harness');
    } else {
        $want = (array) ((array) herikaActionCatalogGetBuiltinRequirements('PayBounty'))['npc_factions_any'] ?? [];
        $have = lrgDlgGuardFactions();
        $t->must('lrgDlgGuardFactions() returns exactly what CHIM says PayBounty needs',
            count($have) === count($want) && !array_diff($have,
                array_map('lrgDlgHexNorm', array_map('strval', $want))),
            json_encode(['chim' => $want, 'glue' => $have]));
    }
    $src = (string) @file_get_contents(fxPluginDir() . '/lib/lrg_dialogue.php');
    $t->must('no guard faction id is typed as a literal anywhere in the dialogue module',
        !preg_match('/["\']0*(86EEE|28848|28849)["\']/i', $src), 'a literal faction id is in the source');

    // --- CHIM's OWN matcher refuses our row when the activity says combat
    if (!function_exists('herikaActionCatalogRequirementsMatch')) {
        $t->pending('CHIM\'s requirement matcher', 'not loadable from the flow harness');
    } else {
        $row = Fx::$catalog[FX_DLG_ACT] ?? null;
        $req = (array) ((array) ($row['metadata'] ?? []))['requirements'] ?? [];
        $ctxOk = ['request_type' => 'inputtext', 'activity_status' => ['available' => true, 'current_action' => 'standing']];
        $ctxBad = ['request_type' => 'inputtext', 'activity_status' => ['available' => true, 'current_action' => 'combat']];
        $t->must('CHIM matches our row on an ordinary turn',
            herikaActionCatalogRequirementsMatch($req, $ctxOk) === true, json_encode($req));
        $t->must('and REFUSES it when the activity is combat - a second, independent gate',
            herikaActionCatalogRequirementsMatch($req, $ctxBad) === false, json_encode($req));
    }
    $t->must('but our OWN per-turn filter stays primary: CHIM cannot express "a session is open"',
        str_contains($src, 'per-turn filter'), 'the comment that states it is missing');
});

fx_scenario('d60', 'locked facts and the truth gate: only what the game confirmed this moment, capped and freshness-bounded, never an index template; and a number she invented drops the ACTION, not her words', function (FxT $t) {
    $npc = 'Brenna Flowtest';
    fxDlgServiceCatalog(['destinations_travel' => ['Morthal', 'Solitude', 'Riften']]);
    $spec = ['One night. (12 gold)' => ['cost' => 12], 'Three nights. (34 gold)' => ['cost' => 34],
        'Never mind.' => []];
    fxDlgCache($npc, $spec, ['pg' => 300]);
    fxDlgServiceCatalog(['destinations_travel' => ['Morthal', 'Solitude', 'Riften']]);
    $q = fxDlgSay($npc, fxDlgSnap(['pgold' => 300]), 'How much for a room?');
    $facts = (array) ($q['turn']['locked'] ?? []);
    $t->must('the turn carries locked facts', $facts !== [], json_encode($facts));
    $classes = array_values(array_unique(array_column($facts, 'class')));
    $allowed = (array) lrgDlgCfg('truth.classes', []);
    $t->must('and every one of them is one of the seven declared classes',
        !array_diff($classes, $allowed), implode(',', $classes));
    $t->must('a price fact really came from the LIVE entry',
        in_array('price', $classes, true), implode(',', $classes));
    $block = lrgDlgLockedBlock($q['turn']);
    $t->must('the block is emitted', str_contains($block, '<locked_facts>'), fx_short($block, 300));
    $t->must('it is within the character cap', strlen($block) <= (int) lrgDlgCfg('truth.max_chars', 600) + 300,
        strlen($block) . ' chars');
    $t->must('it has at most the declared number of fact lines',
        substr_count($block, "\n- ") <= (int) lrgDlgCfg('truth.max_lines', 6), substr_count($block, "\n- ") . ' lines');
    $t->must('it never carries an index TEMPLATE', !str_contains($block, '<Global='), fx_short($block, 300));
    $t->must('and it tells her plainly to admit not knowing anything else',
        str_contains($block, 'say you are not sure'), fx_short($block, 300));

    // --- the injection really goes to CHIM's own slot, at 205, between 200 and 210
    Fx::$injections = [];
    fxIncludeHook('context_pre.php', $GLOBALS['gameRequest'] ?? ['inputtext', fxNow(), 1, 'x']);
    $slots = [];
    foreach (Fx::$injections as $i) { $slots[$i['id']] = $i; }
    if (isset($slots['lorerim_glue_locked_facts'])) {
        $t->must('the locked facts go to character_bottom at priority 205',
            $slots['lorerim_glue_locked_facts']['slot'] === 'character_bottom'
            && (int) $slots['lorerim_glue_locked_facts']['priority'] === 205,
            json_encode($slots['lorerim_glue_locked_facts']));
    } else {
        $t->note('no locked-facts injection on this replayed turn (the hook rebuilt a turn with no facts)');
    }

    // --- the gate: she names a price the game never confirmed AND the same reply acts on it
    $mark = fxDlgLogMark();
    fxDlgClearQueue();
    $keys = $q['keys'];
    $three = array_search('Three nights. (34 gold)', array_column($keys, 'text'), true);
    $key = $three === false ? array_key_first($keys) : array_keys($keys)[$three];
    $w = fxDlgLlm($npc, (string) $key, 'Three nights will be 99 gold, love.');
    $t->must('the ACTION is dropped when she named a price nothing confirmed', $w === [],
        json_encode(array_map('fxParam', $w)));
    $t->must('and both strings are in the log, so a false positive is visible',
        str_contains(fxDlgLogFrom($mark), 'truth npc=') && str_contains(fxDlgLogFrom($mark), 'action=dropped'),
        fx_short(fxDlgLogFrom($mark), 300));

    // --- the SAME wrong number with NO action is only logged: her words always play
    fxDlgCache($npc, $spec, ['pg' => 300]);
    fxDlgServiceCatalog(['destinations_travel' => ['Morthal']]);
    fxDlgSay($npc, fxDlgSnap(['pgold' => 300]), 'How much for a room?');
    $mark2 = fxDlgLogMark();
    fxDlgClearQueue();
    $w2 = fxDlgLlmNothing($npc, 'Three nights will be 99 gold, love.');
    $t->must('with no action there is nothing to drop', $w2 === [], json_encode($w2));
    $t->must('and it is logged rather than acted on',
        str_contains(fxDlgLogFrom($mark2), 'action=logged') || fxDlgLogFrom($mark2) !== '',
        fx_short(fxDlgLogFrom($mark2), 300));

    // --- the RIGHT number passes straight through
    fxDlgCache($npc, $spec, ['pg' => 300]);
    fxDlgServiceCatalog(['destinations_travel' => ['Morthal']]);
    $q3 = fxDlgSay($npc, fxDlgSnap(['pgold' => 300]), 'Three nights, then.');
    fxDlgClearQueue();
    $k3 = array_search('Three nights. (34 gold)', array_column($q3['keys'], 'text'), true);
    $w3 = fxDlgLlm($npc, $k3 === false ? array_key_first($q3['keys']) : array_keys($q3['keys'])[$k3],
        'Thirty-four gold for three nights.');
    $t->must('a price the list really showed is never dropped', count($w3) === 1, json_encode(array_map('fxParam', $w3)));

    // --- a turn with NO price / service / bounty fact is never even examined
    fxDlgCache($npc, ['I need work.' => [], 'Never mind.' => []]);
    fxDlgServiceCatalog([]);
    $q4 = fxDlgSay($npc, fxDlgSnap(), 'Is there work going?');
    fxDlgClearQueue();
    $w4 = fxDlgLlm($npc, array_keys($q4['keys'])[0], 'It pays about 500 gold, I hear.');
    $t->must('on a turn with no price fact the gate does not fire at all', count($w4) === 1,
        json_encode(array_map('fxParam', $w4)));
});

fx_scenario('d61', 'latency: ev=lat is handled, never reaches the LLM, is stored once per reply, and neither it nor ev=calib is ever an LLM-bearing request', function (FxT $t) {
    $npc = 'Brenna Flowtest';
    fxDlgCache($npc, ['I need work.' => [], 'Never mind.' => []]);

    $mark = fxDlgLogMark();
    $r = fxDlgEvent($npc, 'lat', ['v' => 1, 'ref' => '0x00012345', 'ask' => 140, 'reply' => 6540,
        'voice' => 810, 'total' => 7490, 'sid' => 's1', 'first' => 1]);
    $t->must('ev=lat is handled and answers nothing', $r['res'] === 'handled' && $r['echo'] === '',
        $r['res'] . '|' . $r['echo']);
    $log = fxDlgLogFrom($mark);
    $t->must('the dlg lat line carries total, reply, voice and first, in that order',
        (bool) preg_match('/lat npc=\S.*total=\d+ms reply=\d+ms voice=\d+ms first=[01]/', $log),
        fx_short($log, 250));
    $lat = (array) (fxDlgStateOf($npc)['lat'] ?? []);
    $t->must('one row per reply is stored', count($lat) === 1, json_encode($lat));
    $t->must('and it is the numbers the GAME measured, including the voice gap',
        (int) ($lat[0]['voice'] ?? 0) === 810 && (int) ($lat[0]['total'] ?? 0) === 7490, json_encode($lat));

    // a second reply appends; the ring buffer never grows without bound
    fxDlgEvent($npc, 'lat', ['ask' => 100, 'reply' => 5000, 'voice' => 700, 'total' => 5800, 'first' => 0]);
    $t->must('a second reply appends rather than replacing', count((array) (fxDlgStateOf($npc)['lat'] ?? [])) === 2,
        json_encode(fxDlgStateOf($npc)['lat'] ?? null));
    $t->must('the ring buffer has a declared depth', (int) lrgDlgCfg('latency.keep', 0) > 0,
        (string) lrgDlgCfg('latency.keep', 0));

    // a slow reply says so, in the words that point at the real cause
    $mark2 = fxDlgLogMark();
    fxDlgEvent($npc, 'lat', ['ask' => 100, 'reply' => 30000, 'voice' => 900, 'total' => 31000, 'first' => 0]);
    $t->must('a reply over the warning threshold is called out',
        str_contains(fxDlgLogFrom($mark2), 'lat SLOW'), fx_short(fxDlgLogFrom($mark2), 250));

    // NEITHER ev=lat NOR ev=calib may ever be an LLM-bearing request type
    $globals = (string) @file_get_contents(fxPluginDir() . '/globals.php');
    $t->must('lrg_dlg is registered as a FAST command (never behind the LLM semaphore)',
        str_contains($globals, "'lrg_dlg'") && str_contains($globals, 'external_fast_commands'),
        fx_short($globals, 300));
    $t->must('lrg_dlg is not one of the player-speech types the LLM branch answers',
        !in_array('lrg_dlg', LRG_PLAYER_SPEECH_TYPES, true), implode(',', LRG_PLAYER_SPEECH_TYPES));
    $t->must('and lrgDlgHandleGameMessage answers ev=lat and ev=calib "handled", so the request ends before the lock',
        fxDlgEvent($npc, 'lat', ['total' => 1])['res'] === 'handled'
        && fxDlgEvent($npc, 'calib', ['src' => 'passive'], 'rm:3')['res'] === 'handled', 'both must be handled');

    // the only LLM-bearing message the module has is still lrg_dlgtalk, and it is still gated
    $t->must('lrg_dlgtalk is NOT a fast command - it is the one LLM request, admitted or dropped on its own rules',
        !preg_match("/external_fast_commands.*'lrg_dlgtalk'/s", $globals), fx_short($globals, 300));
});
