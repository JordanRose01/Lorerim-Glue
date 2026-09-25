<?php
// PHASE 2 d56 / d57 / d58 - [0.5.0 PART II] SERVICE DIALOGUE (E6).
// The owner's item 9: bartering, training, inns, carriages, guard / crime - "if this isn't already
// implemented in CHIM". It IS, as shortcut actions, and every one of them is wrong on this load order
// in a checkable way (V05_EXPANSION_PLAN 18.1). These scenarios are the proof of the rule that follows
// from that: use the REAL entry when the list has it, CHIM's shortcut only when no session is possible.
require_once __DIR__ . '/../dlg_adapter.php';

/** The census as it really comes off this load order (build_prompt_index.py --services). */
function fxDlgRealCatalog(): array
{
    return [
        'destinations' => ['Morthal', 'Solitude', 'Windhelm', 'Solitude Lighthouse', 'Dawnstar', 'Riften'],
        'destinations_travel' => ['Morthal', 'Solitude', 'Windhelm', 'Solitude Lighthouse', 'Dawnstar', 'Riften'],
        'skills' => ['Alchemy', 'Alteration', 'Archery', 'Conjuration', 'Enchanting', 'Restoration', 'Smithing'],
        'price_globals' => [['token' => '<Global=KmodFerryCost>', 'plugin' => 'CFTO.esp', 'n' => 44]],
        'crime_topics' => ['DGCrimeResistArrest', 'DGCrimePayFine', 'DGCrimeGoToJail', 'DGCrimeBribe',
            'DGCrimePersuade', 'DGCrimeOrcResistArrest'],
        'service_plugins' => [['plugin' => 'CFTO.esp', 'n' => 296]],
    ];
}

fx_scenario('d56', 'services: the five kinds each use the REAL entry and hide CHIM\'s shortcut that turn; with no list the shortcut survives; the per-kind hide lists are a SUBSET of the shipped ones; and the conversation hold still wins over a service toggle', function (FxT $t) {
    $npc = 'Brenna Flowtest';
    fxDlgServiceCatalog(fxDlgRealCatalog());

    // --- the per-kind lists can never drift from the shipped ones
    // [0.5.1] hide_follower is the THIRD shipped list and it is named here explicitly rather than
    // waived: the `follower` kind may only hide codes that list declares, and hide_follower itself is
    // applied on a narrower condition than hide_chim (see lrgDlgServiceHidePolicy) - the codes still
    // have exactly one source of truth, there are now three sources for three conditions.
    $shipped = array_map('strtolower', array_merge((array) lrgDlgCfg('hide_chim', []),
        (array) lrgDlgCfg('hide_always', []), (array) lrgDlgCfg('hide_follower', [])));
    $perKind = [];
    foreach ((array) lrgDlgCfg('services.kinds', []) as $k => $spec) {
        foreach ((array) ($spec['hide'] ?? []) as $c) { $perKind[] = strtolower((string) $c); }
    }
    $t->must('services.kinds.*.hide is a SUBSET of hide_chim + hide_always + hide_follower (one source of truth)',
        $perKind !== [] && !array_diff(array_unique($perKind), $shipped),
        implode(',', array_diff(array_unique($perKind), $shipped)));
    // and the new list may not quietly widen the OLD condition: nothing in hide_follower may also be
    // in hide_chim, or it would be hidden on any known list after all
    $t->must('hide_follower and hide_chim do not overlap - the narrow condition stays narrow',
        !array_intersect(array_map('strtolower', (array) lrgDlgCfg('hide_follower', [])),
            array_map('strtolower', (array) lrgDlgCfg('hide_chim', []))),
        implode(',', array_intersect(array_map('strtolower', (array) lrgDlgCfg('hide_follower', [])),
            array_map('strtolower', (array) lrgDlgCfg('hide_chim', [])))));

    // --- the five kinds, each on its own real entry
    $kinds = [
        'inn' => ["I'd like to rent a room." => [], 'Never mind.' => []],
        'barter' => ['What have you got for sale?' => [], 'Never mind.' => []],
        'train' => ['Can you train me in Alchemy?' => [], 'Never mind.' => []],
        'carriage' => ["I'd like to hire your carriage." => [], 'Never mind.' => []],
        'ferry' => ["I'd like to hire your boat." => [], 'Never mind.' => []],
    ];
    $said = ['inn' => 'I need a room for the night.', 'barter' => 'What have you got for sale?',
        'train' => 'Can you train me in Alchemy?', 'carriage' => 'Take me to Solitude.',
        'ferry' => 'Hire your boat across, then.'];
    $hidden = ['inn' => 'RentRoom', 'barter' => 'OpenInventory', 'train' => 'Training',
        'carriage' => 'HireCarriage', 'ferry' => 'HireFerry'];
    // FX_CHIM_ACTIONS carries only RentRoom of the service family, so the rest are named explicitly -
    // otherwise "the shortcut is hidden" would pass because it was never on the table to begin with.
    $offered = array_merge(fxEnabledDefault(), [FX_DLG_ACT, 'ForgiveCrime', 'PayBounty', 'OpenInventory',
        'OpenInventory2', 'Training', 'HireCarriage', 'HireFerry', 'Brawl', 'ComeCloser', 'FollowPlayer']);
    foreach ($kinds as $kind => $spec) {
        fxDlgServiceCatalog(fxDlgRealCatalog());
        fxDlgCache($npc, $spec);
        $q = fxDlgSay($npc, fxDlgSnap(), $said[$kind], 'inputtext', $offered);
        $t->must($kind . ': the shortcut really WAS on the table before the rule ran',
            in_array($hidden[$kind], $offered, true), $hidden[$kind]);
        $t->must($kind . ': the real entry is known, so CHIM\'s ' . $hidden[$kind] . ' steps aside',
            !in_array($hidden[$kind], $q['enabled'], true), implode(',', $q['enabled']));
        $t->must($kind . ': the business action IS offered instead',
            in_array(FX_DLG_ACT, $q['enabled'], true), implode(',', $q['enabled']));
    }

    // --- with NO list at all: [pt19 v1.0 / S2.1] his service words open her REAL list pre-LLM and CHIM's shortcut steps
    // aside for that turn; when the list cannot be opened (the game refused the last open, S2.3) the shortcuts survive
    fxDlgReset();
    fxDlgServiceCatalog(fxDlgRealCatalog());
    $q2o = fxDlgSay('Unknown Flowtest', fxDlgSnap(), 'I need a room for the night.', 'inputtext', $offered);
    $t->must('[S2.1] with no list known the service words open her real list pre-LLM and RentRoom steps aside that turn',
        !empty($q2o['turn']['open_pending']) && !in_array('RentRoom', $q2o['enabled'], true), implode(',', $q2o['enabled']));
    fxDlgReset();
    fxDlgServiceCatalog(fxDlgRealCatalog());
    lrgDlgPut('Unknown Flowtest', ['open_refused' => ['why' => 'a quest scene is running', 'at' => fxNow()]]);
    $q2 = fxDlgSay('Unknown Flowtest', fxDlgSnap(), 'I need a room for the night.', 'inputtext', $offered);
    $t->must('with no list known and the open refused, CHIM\'s RentRoom is still there as the fallback',
        in_array('RentRoom', $q2['enabled'], true), implode(',', $q2['enabled']));
    $t->must('... and so are HireCarriage and Training',
        in_array('HireCarriage', $q2['enabled'], true) && in_array('Training', $q2['enabled'], true),
        implode(',', $q2['enabled']));
    $t->must('... and ForgiveCrime is hidden anyway (decision D4: a pardon must be earned)',
        !in_array('ForgiveCrime', $q2['enabled'], true), implode(',', $q2['enabled']));
    $t->must('... and PayBounty is NOT hidden (it checks the gold first)',
        in_array('PayBounty', $q2['enabled'], true), implode(',', $q2['enabled']));

    // --- R21: the HOLD is evaluated last and a service toggle can never put a movement action back
    fxDlgReset();
    fxDlgServiceCatalog(fxDlgRealCatalog());
    $mark = fxDlgLogMark();
    $q3 = fxDlgSay('Unknown Flowtest', fxDlgSnap(['hold' => '1', 'dist' => '140']), 'I need a ride somewhere.',
        'inputtext', $offered);
    if (function_exists('lrgDlgHoldMovementPolicy')) { lrgDlgHoldMovementPolicy(); }
    $en = array_values((array) ($GLOBALS['ENABLED_FUNCTIONS'] ?? []));
    $t->must('with a live hold AND no list AND the shortcut fallback ON, HireCarriage is STILL hidden',
        !in_array('HireCarriage', $en, true), implode(',', $en));
    $log = fxDlgLogFrom($mark);
    $t->must('and the log says the HOLD hid it, not the service rule',
        str_contains($log, 'movement HireCarriage hidden'), fx_short($log, 300));

    // ================================================================= [0.5.0 fix pass / S-2] ml
    // THE SHIPPED STATE IS bMenuless = 0, bDlgDryRun = 1. Until this fix the server hid RentRoom /
    // HireCarriage / HireFerry / Training / OpenInventory anyway, on every ordinary CHIM turn, as soon
    // as it had a cached topic list (kept 1800 s) - and the game then answered CmdSelectTopic with
    // "Error: the feature is switched off". An evening where nothing could be rented, hired, trained or
    // bought by voice at any NPC whose menu had been opened in the last half hour.
    // ml = bMenuless && !bDlgDryRun. ABSENT means 1, so a script-401 payload is unchanged (d50).
    foreach ([['0', false], ['1', true]] as [$ml, $expectHidden]) {
        fxDlgReset();
        fxDlgServiceCatalog(fxDlgRealCatalog());
        fxDlgCache($npc, ["I'd like to rent a room." => [], 'Never mind.' => []]);
        unset($GLOBALS['LRG_DLG_MCM'], $GLOBALS['LRG_DLG_ML_SAID']);
        $qm = fxDlgSay($npc, fxDlgSnap(['ml' => $ml]), 'I need a room for the night.', 'inputtext', $offered);
        $gone = !in_array('RentRoom', $qm['enabled'], true);
        $t->must('ml=' . $ml . ': CHIM\'s RentRoom is ' . ($expectHidden ? 'hidden for the real entry' : 'LEFT ON THE TABLE'),
            $gone === $expectHidden, implode(',', $qm['enabled']));
        $t->must('ml=' . $ml . ': and HireCarriage / Training / OpenInventory agree with it',
            (!in_array('HireCarriage', $qm['enabled'], true)) === $expectHidden
            && (!in_array('Training', $qm['enabled'], true)) === $expectHidden
            && (!in_array('OpenInventory', $qm['enabled'], true)) === $expectHidden,
            implode(',', $qm['enabled']));
        $t->must('ml=' . $ml . ': ForgiveCrime follows the same rule (there is no real entry to replace it with)',
            (!in_array('ForgiveCrime', $qm['enabled'], true)) === $expectHidden, implode(',', $qm['enabled']));
    }
    // ... but an EXPLICIT bServiceShortcut = 0 is an owner instruction, not a promise to replace the
    // shortcut, so it is still honoured with menuless off (svx byte 0 = 0 while ml = 0).
    fxDlgReset();
    fxDlgServiceCatalog(fxDlgRealCatalog());
    fxDlgCache($npc, ["I'd like to rent a room." => [], 'Never mind.' => []]);
    unset($GLOBALS['LRG_DLG_MCM'], $GLOBALS['LRG_DLG_ML_SAID']);
    $qm3 = fxDlgSay($npc, fxDlgSnap(['ml' => '0', 'svx' => '011']), 'I need a room for the night.',
        'inputtext', $offered);
    $t->must('ml=0 but bServiceShortcut explicitly OFF: RentRoom is still hidden, because the owner said so',
        !in_array('RentRoom', $qm3['enabled'], true), implode(',', $qm3['enabled']));
    // and an ABSENT ml still behaves exactly as 0.4.1 did - the additive contract of PROTOCOL v0.5
    fxDlgReset();
    fxDlgServiceCatalog(fxDlgRealCatalog());
    fxDlgCache($npc, ["I'd like to rent a room." => [], 'Never mind.' => []]);
    unset($GLOBALS['LRG_DLG_MCM'], $GLOBALS['LRG_DLG_ML_SAID']);
    $qm2 = fxDlgSay($npc, fxDlgSnap(), 'I need a room for the night.', 'inputtext', $offered);
    $t->must('no ml on the wire at all (a 0.4.1 game script): RentRoom is hidden, exactly as before',
        !in_array('RentRoom', $qm2['enabled'], true), implode(',', $qm2['enabled']));

    // ================================================================= [0.5.7 / pt16] DIRECT BARTER
    // "What do you have for sale?" - Jala, Addvar, Evette San, 2026-09-23 16:40-16:43: five turns, the
    // real entry structurally off (ml=0, dry run forced by the red calibration), CHIM's own Trade_Items
    // on the table every time and chosen by the model on none of them, nothing voiced. The rule now:
    // a barter request that no real entry answers is carried on CHIM's OWN OpenInventory - the directive
    // names it, the post-gate appends it when the reply lacks it, never both, and only while it is
    // really on the table.
    $vend = ['ml' => '0', 'class' => 'VendorFood',
        'fac' => 'CrimeFactionHaafingar,TownSolitudeFaction,JobMerchantFaction,JobStreetVendorFaction,ServicesSolitudeAddvar'];
    $isTrade = static fn(string $l): bool => strcasecmp(fxCode($l), 'OpenInventory') === 0;
    $tradeLines = static fn(array $w): array => array_values(array_filter($w, $isTrade));

    // --- (1) ml=0, a fresh vendor snapshot, the owner's literal words, the model chose nothing
    fxDlgReset();
    fxDlgServiceCatalog(fxDlgRealCatalog());
    unset($GLOBALS['LRG_DLG_MCM'], $GLOBALS['LRG_DLG_ML_SAID']);
    $mark = fxDlgLogMark();
    $qb = fxDlgSay('Addvar Flowtest', fxDlgSnap($vend), 'What do you have for sale?', 'inputtext', $offered);
    $t->must('ml=0, no list: CHIM\'s OpenInventory stays ON the table (it is the fallback, D4)',
        in_array('OpenInventory', $qb['enabled'], true), implode(',', $qb['enabled']));
    $t->must('the turn decided direct barter', (int) (($qb['turn']['svc'] ?? [])['direct'] ?? 0) === 1,
        json_encode($qb['turn']['svc'] ?? null));
    $t->must('the directive names CHIM\'s own trade action, forbids prices and says never ignore it',
        str_contains($qb['volatile'], '<player_request>') && str_contains($qb['volatile'], 'OpenInventory')
        && str_contains($qb['volatile'], 'Name no prices') && str_contains($qb['volatile'], 'Never ignore it'),
        fx_short($qb['volatile'], 400));
    $t->must('and the turn line carries direct=barter for the owner\'s log',
        str_contains(fxDlgLogFrom($mark), 'direct=barter'), fx_short(fxDlgLogFrom($mark), 300));
    $w = fxDlgLlmNothing('Addvar Flowtest', 'Have a look, then.');
    $t->must('the model chose nothing: exactly ONE line goes out, the vanilla barter window on CHIM\'s own code',
        count($w) === 1 && $isTrade($w[0]) && fxParam($w[0]) === 'Flowtest Player',
        json_encode(array_map('trim', $w)));
    $t->must('the log says the net appended it', str_contains(fxDlgLogFrom($mark), 'net: OpenInventory appended'),
        fx_short(fxDlgLogFrom($mark), 400));

    // --- (2) the model DID choose Trade_Items itself: the line passes, nothing is added (never both)
    $qb2 = fxDlgSay('Addvar Flowtest', fxDlgSnap($vend), 'Show me your goods.', 'inputtext', $offered);
    $w2 = fxDlgLlm('Addvar Flowtest', 'Flowtest Player', 'Have a look.', 'Trade_Items', 'OpenInventory');
    $t->must('the model chose it itself: exactly one trade line, not two',
        count($tradeLines($w2)) === 1 && count($w2) === 1, json_encode(array_map('trim', $w2)));

    // --- (3) the STT fragments of that evening are all barter now, and each one carries the window
    foreach (['for sale', 'thing for sale', 'excuse me what do you have for sale?',
        'what have you what have you got for sale sir?'] as $stt) {
        $qs = fxDlgSay('Addvar Flowtest', fxDlgSnap($vend), $stt, 'inputtext', $offered);
        $ws = fxDlgLlmNothing('Addvar Flowtest', 'Fresh from the docks.');
        $t->must("'$stt' -> direct barter and the window", (int) (($qs['turn']['svc'] ?? [])['direct'] ?? 0) === 1
            && count($tradeLines($ws)) === 1, json_encode(array_map('trim', $ws)));
    }
    $qn = fxDlgSay('Addvar Flowtest', fxDlgSnap($vend), 'a for several', 'inputtext', $offered);
    $wn = fxDlgLlmNothing('Addvar Flowtest', 'Sorry?');
    $t->must("'a for several' (a 0.38 s clip) is still nothing: no directive, no window",
        (string) (($qn['turn']['svc'] ?? [])['kind'] ?? '') === '' && $tradeLines($wn) === [], json_encode($wn));

    // --- (4) a BLIND turn: the snapshot is 400 s old (Jala's was 212647 s, Addvar's 42945 s at first
    // contact tonight). The intimacy lane's blind note says nothing physical can be carried out; the
    // barter directive overrides that sentence explicitly and the window still opens.
    fxAdvance(400);
    $mark4 = fxDlgLogMark();
    $qb4 = fxDlgSay('Addvar Flowtest', null, 'What have you got for sale?', 'inputtext', $offered);
    $t->must('a stale / missing snapshot is no reason: still direct',
        (int) (($qb4['turn']['svc'] ?? [])['direct'] ?? 0) === 1, json_encode($qb4['turn']['svc'] ?? null));
    $t->must('the directive says in so many words that it overrides the blind note above it',
        str_contains($qb4['volatile'], 'showing wares is done by the game itself'), fx_short($qb4['volatile'], 400));
    $w4 = fxDlgLlmNothing('Addvar Flowtest', 'Take a look.');
    $t->must('and the window goes out on the blind turn too', count($tradeLines($w4)) === 1, json_encode($w4));

    // --- (5) ml=1 with the REAL entry on her list: OpenInventory is hidden for it and the net stays out
    fxDlgCache('Addvar Flowtest', ['What have you got for sale?' => [], 'Never mind.' => []]);
    fxDlgServiceCatalog(fxDlgRealCatalog());
    unset($GLOBALS['LRG_DLG_MCM'], $GLOBALS['LRG_DLG_ML_SAID']);
    $qb5 = fxDlgSay('Addvar Flowtest', fxDlgSnap(['ml' => '1'] + $vend), 'What do you have for sale?', 'inputtext', $offered);
    $t->must('ml=1 + the real entry known: OpenInventory steps aside for it (D4)',
        !in_array('OpenInventory', $qb5['enabled'], true), implode(',', $qb5['enabled']));
    $t->must('... the direct path stands aside too', (int) (($qb5['turn']['svc'] ?? [])['direct'] ?? 0) === 0
        && (string) (($qb5['turn']['svc'] ?? [])['direct_why'] ?? '') === 'real entry', json_encode($qb5['turn']['svc'] ?? null));
    $t->must('... the <business> block carries the real entry instead of a trade directive',
        str_contains($qb5['volatile'], '<business') && !str_contains($qb5['volatile'], 'Do it now'), fx_short($qb5['volatile'], 300));
    $w5 = fxDlgLlmNothing('Addvar Flowtest', 'What are you after?');
    $t->must('... and no OpenInventory line is appended', $tradeLines($w5) === [], json_encode($w5));

    // --- (6) the guards: a negation, a price question, a companion, a fresh combat snapshot, a non-vendor
    fxDlgReset();
    fxDlgServiceCatalog(fxDlgRealCatalog());
    unset($GLOBALS['LRG_DLG_MCM'], $GLOBALS['LRG_DLG_ML_SAID']);
    $cases = [
        ["Don't sell me anything.", $vend, 'negated'],
        ['How much for your wares?', $vend, 'price question'],
        ['What do you have for sale?', ['mate' => '1'] + $vend, 'companion'],
        ['What do you have for sale?', ['combat' => '1'] + $vend, 'combat'],
        ['What do you have for sale?', ['class' => '', 'fac' => 'TownSolitudeFaction,CrimeFactionHaafingar', 'ml' => '0'], 'not a vendor'],
    ];
    foreach ($cases as [$say, $snapOver, $why]) {
        $qg = fxDlgSay('Addvar Flowtest', fxDlgSnap($snapOver), $say, 'inputtext', $offered);
        $wg = fxDlgLlmNothing('Addvar Flowtest', 'Hm.');
        $svc = (array) ($qg['turn']['svc'] ?? []);
        // [pt19 v1.0 / language brief P5] a NEGATED service sentence is no service request at all (kind ''), so it
        // never reaches the direct path's own 'negated' guard; the behaviour is the same: no direct path, no window
        $whyOk = $why === 'negated'
            ? ((string) ($svc['kind'] ?? '') === '' || (string) ($svc['direct_why'] ?? '') === 'negated')
            : (string) ($svc['direct_why'] ?? '') === $why;
        $t->must("'$say' ($why): no direct path and no window",
            (int) ($svc['direct'] ?? 0) === 0 && $whyOk && $tradeLines($wg) === [],
            json_encode([$qg['turn']['svc'] ?? null, $wg]));
    }
    $qc = fxDlgSay('Addvar Flowtest', fxDlgSnap(['mate' => '1'] + $vend), 'What do you have for sale?', 'inputtext', $offered);
    $t->must('a companion is told, never refused: the directive still names the trade action for her',
        str_contains($qc['volatile'], 'travels with') && str_contains($qc['volatile'], 'OpenInventory'), fx_short($qc['volatile'], 300));
    $qh = fxDlgSay('Addvar Flowtest', fxDlgSnap($vend), 'How much for the salmon?', 'inputtext', $offered);
    $wh = fxDlgLlmNothing('Addvar Flowtest', 'Depends on the day.');
    $t->must('"how much for the salmon" is no barter request at all: nothing appended',
        (string) (($qh['turn']['svc'] ?? [])['kind'] ?? '') === '' && $tradeLines($wh) === [], json_encode($wh));

    // --- (7) bServiceShortcut OFF (svx byte 0) still hides the rooms / rides / training shortcuts with no
    // list - never trade: OpenInventory IS the real window with the real prices, so the window still opens
    $qo = fxDlgSay('Addvar Flowtest', fxDlgSnap(['svx' => '011'] + $vend), 'What do you have for sale?', 'inputtext', $offered);
    $t->must('bServiceShortcut off: RentRoom / HireCarriage / Training are hidden (the owner said so) ...',
        !in_array('RentRoom', $qo['enabled'], true) && !in_array('HireCarriage', $qo['enabled'], true)
        && !in_array('Training', $qo['enabled'], true), implode(',', $qo['enabled']));
    $t->must('... but OpenInventory is not - trade never was a flat-price shortcut',
        in_array('OpenInventory', $qo['enabled'], true), implode(',', $qo['enabled']));
    $wo = fxDlgLlmNothing('Addvar Flowtest', 'Have a look.');
    $t->must('... and the window still opens', count($tradeLines($wo)) === 1, json_encode($wo));
    // OpenInventory really NOT on the table (CHIM's own row deactivated): nothing is smuggled past the offer
    $noTrade = array_values(array_filter($offered, static fn($c) => !in_array($c, ['OpenInventory', 'OpenInventory2'], true)));
    $qo2 = fxDlgSay('Addvar Flowtest', fxDlgSnap($vend), 'What do you have for sale?', 'inputtext', $noTrade);
    $wo2 = fxDlgLlmNothing('Addvar Flowtest', 'Not today.');
    $t->must('OpenInventory not offered at all: the net adds nothing and the directive tells her to say plainly she cannot show them',
        $tradeLines($wo2) === [] && str_contains($qo2['volatile'], 'cannot show any wares'), json_encode([$wo2, fx_short($qo2['volatile'], 200)]));

    // --- (8) the other kinds under ml=0: "do it with CHIM's own action, or say why" - and no wire line of ours
    $qi = fxDlgSay('Addvar Flowtest', fxDlgSnap($vend), 'I need a room for the night.', 'inputtext', $offered);
    $t->must('inn under ml=0 with RentRoom on the table: the directive says "Do it now: choose RentRoom"',
        str_contains($qi['volatile'], 'Do it now') && str_contains($qi['volatile'], 'RentRoom')
        && str_contains($qi['volatile'], 'Never ignore it'), fx_short($qi['volatile'], 300));
    $wi = fxDlgLlmNothing('Addvar Flowtest', 'Ten septims.');
    $t->must('... and the glue itself sends nothing for it (CHIM\'s own RentRoom is the model\'s to choose)', $wi === [], json_encode($wi));
    $qi2 = fxDlgSay('Addvar Flowtest', fxDlgSnap(['ml' => '1'] + $vend), 'I need a room for the night.', 'inputtext', $offered);
    $t->must('inn under ml=1 (no list): no such directive - the real entry path owns it',
        !str_contains($qi2['volatile'], 'RentRoom'), fx_short($qi2['volatile'], 300));
});

fx_scenario('d57', 'the one-word problem (risk R12): on a price list only an EXACT slot name executes, longest wins both ways, an ambiguity asks, a price question does nothing, and no slot match blocks the similarity matcher entirely', function (FxT $t) {
    $npc = 'Kibell Flowtest';
    fxDlgServiceCatalog(fxDlgRealCatalog());
    $dest = ['Morthal. (35 gold)', 'Solitude. (35 gold)', 'Solitude Lighthouse. (35 gold)',
        'Windhelm. (35 gold)', 'Never mind.'];

    // --- "take me to Solitude" -> Solitude, NOT the Lighthouse
    [$ent, $kv] = fxDlgPriceLayer($npc, $dest);
    fxDlgServiceCatalog(fxDlgRealCatalog());
    fxDlgSay($npc, fxDlgSnap(), 'Take me to Solitude.');
    fxDlgClearQueue();
    $r = fxDlgTopics($npc, ['sid' => 'sA', 'want' => 1, 'ask' => 'take me to solitude', 'cid' => 'ds01'] + $kv, $ent);
    $t->must('"take me to Solitude" picks Solitude', str_contains($r['echo'], ';pos=1;'), fx_short($r['echo'], 220));
    $t->must('exactly one decision left the server', count(fxDlgQueue()) === 1, json_encode(fxDlgQueue()));

    // --- "take me to the Solitude Lighthouse" -> the Lighthouse. LONGEST WINS.
    [$ent2, $kv2] = fxDlgPriceLayer($npc, $dest);
    fxDlgServiceCatalog(fxDlgRealCatalog());
    fxDlgSay($npc, fxDlgSnap(), 'Take me to the Solitude Lighthouse.');
    fxDlgClearQueue();
    $r2 = fxDlgTopics($npc, ['sid' => 'sB', 'want' => 1, 'ask' => 'take me to the solitude lighthouse', 'cid' => 'ds02'] + $kv2, $ent2);
    $t->must('"the Solitude Lighthouse" picks the LIGHTHOUSE, not Solitude (longest wins)',
        str_contains($r2['echo'], ';pos=2;'), fx_short($r2['echo'], 220));

    // --- the same pair in the other order in the list: the rule is about tokens, not about position
    $dest2 = ['Solitude Lighthouse. (35 gold)', 'Solitude. (35 gold)', 'Morthal. (35 gold)', 'Never mind.'];
    [$ent3, $kv3] = fxDlgPriceLayer($npc, $dest2);
    fxDlgServiceCatalog(fxDlgRealCatalog());
    fxDlgSay($npc, fxDlgSnap(), 'Take me to Solitude.');
    fxDlgClearQueue();
    $r3 = fxDlgTopics($npc, ['sid' => 'sC', 'want' => 1, 'ask' => 'take me to solitude', 'cid' => 'ds03'] + $kv3, $ent3);
    $t->must('with the Lighthouse listed FIRST, "Solitude" still picks Solitude',
        str_contains($r3['echo'], ';pos=1;'), fx_short($r3['echo'], 220));

    // --- a price question answers and executes NOTHING
    [$ent4, $kv4] = fxDlgPriceLayer($npc, $dest);
    fxDlgServiceCatalog(fxDlgRealCatalog());
    fxDlgSay($npc, fxDlgSnap(), 'How much to Morthal?');
    fxDlgClearQueue();
    $mark = fxDlgLogMark();
    $r4 = fxDlgTopics($npc, ['sid' => 'sD', 'want' => 1, 'ask' => 'how much to morthal', 'cid' => 'ds04'] + $kv4, $ent4);
    $t->must('a price question executes NOTHING', fxDlgQueue() === [] && $r4['echo'] === '',
        json_encode(fxDlgQueue()) . ' ' . fx_short($r4['echo'], 120));

    // --- two places named: she ASKS, she does not guess
    [$ent5, $kv5] = fxDlgPriceLayer($npc, $dest);
    fxDlgServiceCatalog(fxDlgRealCatalog());
    fxDlgSay($npc, fxDlgSnap(), 'Morthal or Windhelm, whichever is nearer.');
    fxDlgClearQueue();
    $r5 = fxDlgTopics($npc, ['sid' => 'sE', 'want' => 1, 'ask' => 'morthal or windhelm', 'cid' => 'ds05'] + $kv5, $ent5);
    $t->must('two destinations named -> nothing is executed', fxDlgQueue() === [], json_encode(fxDlgQueue()));

    // --- NO slot named at all: the similarity matcher is not consulted, even on a near-perfect string
    [$ent6, $kv6] = fxDlgPriceLayer($npc, $dest);
    fxDlgServiceCatalog(fxDlgRealCatalog());
    fxDlgSay($npc, fxDlgSnap(), 'Somewhere up north, I suppose.');
    fxDlgClearQueue();
    $mark2 = fxDlgLogMark();
    $r6 = fxDlgTopics($npc, ['sid' => 'sF', 'want' => 1, 'ask' => 'Morthal', 'cid' => 'ds06'] + $kv6, $ent6);
    $t->must('no slot named by the PLAYER and only the model guessing -> the ask= still has to name one exactly',
        str_contains($r6['echo'], ';pos=0;') || fxDlgQueue() === [], fx_short($r6['echo'], 200));
    // a genuinely unmatchable pair: neither the player nor the model names a slot
    [$ent7, $kv7] = fxDlgPriceLayer($npc, $dest);
    fxDlgServiceCatalog(fxDlgRealCatalog());
    fxDlgSay($npc, fxDlgSnap(), 'Somewhere up north, I suppose.');
    fxDlgClearQueue();
    $r7 = fxDlgTopics($npc, ['sid' => 'sG', 'want' => 1, 'ask' => 'somewhere up north', 'cid' => 'ds07'] + $kv7, $ent7);
    $t->must('a price list with NO slot match executes nothing at all', fxDlgQueue() === [],
        json_encode(fxDlgQueue()));
    $t->must('and the log says the similarity matcher was not consulted',
        str_contains(fxDlgLogFrom($mark2), 'similarity matcher is NOT consulted')
        || str_contains(fxDlgLogFrom($mark2), 'similarity matcher is not consulted'),
        fx_short(fxDlgLogFrom($mark2), 300));

    // --- a negated destination is not an order
    $t->must('"don\'t take me to Morthal" names no slot',
        (static function () use ($ent, $dest) {
            $entries = [];
            foreach ($dest as $i => $d) {
                $entries[] = ['pos' => $i, 'i' => 100 + $i, 'norm' => lrgPromptNorm($d), 'text' => $d,
                    'class' => lrgPromptCost($d) > 0 ? 'pay' : 'back', 'cost' => lrgPromptCost($d)];
            }
            $r = lrgDlgServiceSlot($entries, "don't take me to Morthal", 'carriage');
            return is_array($r) && (string) $r['mode'] === 'none';
        })(), 'negation guard');

    // --- an ordinary sentence layer is NOT a price list, so nothing about matching changes there
    $t->must('a layer of sentences is not a price list and the slot matcher stands aside',
        lrgDlgServiceSlot([
            ['pos' => 0, 'norm' => 'i will kill him for you', 'text' => 'I will kill him for you.', 'class' => 'plain', 'cost' => 0],
            ['pos' => 1, 'norm' => 'i will spare him', 'text' => 'I will spare him.', 'class' => 'plain', 'cost' => 0],
            ['pos' => 2, 'norm' => 'i need more time', 'text' => 'I need more time.', 'class' => 'plain', 'cost' => 0],
        ], 'I will spare him', '') === null, 'expected null');

    // ================================================================= [0.5.0 fix pass / S-1] R12 again
    // EVERY existing check above uses fxDlgRealCatalog()'s four-priced destination list, and that is
    // exactly why 1,083 green checks missed the worst bug of the round: the shipped guard needed TWO
    // priced rows, and the VANILLA carriage list (skyrim.esm:0CDF9E) prices ONE. Six more real layers
    // in this load order are the same shape, including update.esm:002F0E which prices NONE.
    // With the guard off the 0.55 similarity matcher decided, and NEITHER the price-question guard NOR
    // the negation guard ran at all - both live only inside lrgDlgServiceSlot(). Measured on the shipped
    // thresholds: "How much to Morthal?" -> "Morthal." 0.85/0.61, "Don't take me to Morthal." 0.85/0.60,
    // both class=plain, both in want=1's allow list. Gold left the purse and the player was teleported.
    $vanilla = ['Whiterun. (20 gold)', 'Solitude.', 'Morthal.', 'Riften.', 'Windhelm.', 'Markarth.',
        'Dawnstar.', 'Falkreath.', 'Winterhold.', 'Never mind.'];
    $freeList = ['Solitude.', 'Morthal.', 'Riften.', 'Windhelm.', 'Markarth.', 'Never mind.'];

    foreach (['the VANILLA carriage list: 10 entries, ONE priced' => $vanilla,
        'a destination list with NO price on any row at all' => $freeList] as $why => $rows) {
        $entries = [];
        foreach ($rows as $i => $d) {
            $entries[] = ['pos' => $i, 'i' => 200 + $i, 'norm' => lrgPromptNorm($d), 'text' => $d,
                'class' => 'plain', 'cost' => lrgPromptCost($d)];
        }
        $t->must($why . ' IS a price list - the slot guard engages',
            is_array(lrgDlgServiceSlot($entries, 'take me to morthal', 'carriage')), 'returned null');
        $r = lrgDlgServiceSlot($entries, 'How much to Morthal?', 'carriage');
        $t->must($why . ': a price QUESTION executes nothing',
            is_array($r) && (string) $r['mode'] === 'price', json_encode($r));
        $r = lrgDlgServiceSlot($entries, "Don't take me to Morthal.", 'carriage');
        $t->must($why . ': a NEGATED destination names no slot',
            is_array($r) && (string) $r['mode'] === 'none', json_encode($r));
        $r = lrgDlgServiceSlot($entries, 'I do not want to go to Riften.', 'carriage');
        $t->must($why . ': "I do not want to go to Riften" names no slot',
            is_array($r) && (string) $r['mode'] === 'none', json_encode($r));
        $r = lrgDlgServiceSlot($entries, 'Take me to Morthal.', 'carriage');
        $t->must($why . ': and a real order still picks the real entry',
            is_array($r) && (string) $r['mode'] === 'pick' && (string) $r['slot'] === 'morthal',
            json_encode($r));
    }

    // and the same thing on the LIVE want=1 fast path, not just the function
    [$ent8, $kv8] = fxDlgPriceLayer($npc, $vanilla);
    fxDlgServiceCatalog(fxDlgRealCatalog());
    fxDlgSay($npc, fxDlgSnap(), 'How much to Morthal?');
    fxDlgClearQueue();
    $r8 = fxDlgTopics($npc, ['sid' => 'sH', 'want' => 1, 'ask' => 'how much to morthal', 'cid' => 'ds08'] + $kv8, $ent8);
    $t->must('want=1 on a ONE-PRICED carriage list: a price question spends nothing',
        fxDlgQueue() === [] && $r8['echo'] === '', json_encode(fxDlgQueue()) . ' ' . fx_short($r8['echo'], 120));
    [$ent9, $kv9] = fxDlgPriceLayer($npc, $vanilla);
    fxDlgServiceCatalog(fxDlgRealCatalog());
    fxDlgSay($npc, fxDlgSnap(), "Don't take me to Morthal.");
    fxDlgClearQueue();
    $r9 = fxDlgTopics($npc, ['sid' => 'sI', 'want' => 1, 'ask' => "don't take me to morthal", 'cid' => 'ds09'] + $kv9, $ent9);
    $t->must('want=1 on a ONE-PRICED carriage list: a negation teleports nobody',
        fxDlgQueue() === [], json_encode(fxDlgQueue()));

    // --- [S-7] a TRAINING layer carries no visible price at all, so it could never reach the guard
    // either. On a five-skill Requiem trainer the similarity margin between "One-Handed" and
    // "Two-Handed" falls to 0.19 against a floor of 0.15; exact naming has no such cliff.
    $train = ["I'd like training in One-Handed.", "I'd like training in Two-Handed.",
        "I'd like training in Block.", "I'd like training in Archery.", "I'd like training in Sneak.",
        'Never mind.'];
    $tent = [];
    foreach ($train as $i => $d) {
        $tent[] = ['pos' => $i, 'i' => 300 + $i, 'norm' => lrgPromptNorm($d), 'text' => $d,
            'class' => 'service', 'cost' => 0];
    }
    $t->must('a five-skill trainer layer is NOT a slot list (its entries are sentences, not names)',
        lrgDlgServiceSlot($tent, 'train me in one handed', 'train') === null, 'expected null');
    $tent2 = [];
    foreach (['One-Handed.', 'Two-Handed.', 'Block.', 'Archery.', 'Sneak.', 'Never mind.'] as $i => $d) {
        $tent2[] = ['pos' => $i, 'i' => 400 + $i, 'norm' => lrgPromptNorm($d), 'text' => $d,
            'class' => 'service', 'cost' => 0];
    }
    $r = lrgDlgServiceSlot($tent2, 'Train me in One-Handed.', 'train');
    $t->must('a trainer layer of bare SKILL NAMES does reach the guard, priced or not',
        is_array($r) && (string) $r['mode'] === 'pick' && (string) $r['slot'] === 'one handed', json_encode($r));
    $r = lrgDlgServiceSlot($tent2, 'Train me in Two-Handed.', 'train');
    $t->must('... and One-Handed vs Two-Handed is decided by the NAME, never by a 0.19 margin',
        is_array($r) && (string) $r['mode'] === 'pick' && (string) $r['slot'] === 'two handed', json_encode($r));

    // --- [S-3] bCarriageByName OFF degrades a pick to "ask", and NEVER back to similarity
    $vent = [];
    foreach ($vanilla as $i => $d) {
        $vent[] = ['pos' => $i, 'i' => 500 + $i, 'norm' => lrgPromptNorm($d), 'text' => $d,
            'class' => 'plain', 'cost' => lrgPromptCost($d)];
    }
    $GLOBALS['LRG_DLG_MCM'] = ['svbn' => 0] + (array) ($GLOBALS['LRG_DLG_MCM'] ?? []);
    $r = lrgDlgServiceSlot($vent, 'Take me to Morthal.', 'carriage', $npc);
    $t->must('bCarriageByName OFF: naming a destination asks instead of picking - it never falls back'
        . ' to the similarity matcher', is_array($r) && (string) $r['mode'] === 'none', json_encode($r));
    unset($GLOBALS['LRG_DLG_MCM']['svbn']);
    $r = lrgDlgServiceSlot($vent, 'Take me to Morthal.', 'carriage', $npc);
    $t->must('... and with it back ON the same words pick again',
        is_array($r) && (string) $r['mode'] === 'pick', json_encode($r));
});

fx_scenario('d58', 'crime: an arrest is detected three independent ways, the menu comes back visible, "resist arrest" is unselectable at EVERY setting, PayBounty survives and ForgiveCrime never appears', function (FxT $t) {
    $npc = 'Whiterun Guard Flowtest';
    fxDlgServiceCatalog(fxDlgRealCatalog());

    // --- route 1: the entry's own walk-away target is in the census's crime family
    $spec = ['I submit. Take me to jail.' => ['twat' => 'DGCrimeResistArrest', 'scripted' => 1,
        'topic' => 'DGCrimeGoToJail'],
        'You will never take me alive.' => ['twat' => 'DGCrimeResistArrest', 'topic' => 'DGCrimeResistArrest'],
        'I will pay the fine. (200 gold)' => ['cost' => 200, 'topic' => 'DGCrimePayFine']];
    fxDlgCache($npc, $spec, ['pg' => 400]);
    fxDlgServiceCatalog(fxDlgRealCatalog());
    $q = fxDlgSay($npc, fxDlgSnap(['pgold' => 400]), 'I submit, take me in.');
    $t->must('the turn is marked as an arrest', (string) ($q['turn']['arrest'] ?? '') !== '',
        json_encode($q['turn']['arrest'] ?? null));
    fxDlgClearQueue();
    $w = fxDlgLlm($npc, array_keys($q['keys'])[0]);
    $t->must('nothing is chosen by voice - the menu is handed back',
        count($w) === 1 && fxDlgDo($w[0]) === 'show', json_encode(array_map('fxParam', $w)));
    $t->must('ForgiveCrime is never on the table', !in_array('ForgiveCrime', $q['enabled'], true),
        implode(',', $q['enabled']));
    $t->must('PayBounty still is', in_array('PayBounty', $q['enabled'], true), implode(',', $q['enabled']));

    // --- "resist arrest" is refused at EVERY setting, even with the critical rails relaxed
    $resist = null;
    foreach ((array) ($q['turn']['entries'] ?? []) as $e) {
        if (str_contains((string) $e['text'], 'never take me alive')) { $resist = $e; }
    }
    $t->must('the resist entry is recognised as resist-arrest whatever else is set',
        is_array($resist) && lrgDlgIsResistArrest($resist), json_encode($resist['topic'] ?? null));

    // --- route 2: the game says guard=1 and a real bounty, with no crime entry in the list at all.
    // A FRESH NPC each time: a bounty is remembered for the freshness window, which is the point.
    $g2 = 'Solitude Guard Flowtest';
    fxDlgCache($g2, ['Something on your mind?' => [], 'Never mind.' => []]);
    fxDlgServiceCatalog(fxDlgRealCatalog());
    fxDlgEvent($g2, 'facts', ['guard' => 1, 'bounty' => 40, 'cf' => '0x00028848']);
    $q2 = fxDlgSay($g2, fxDlgSnap(), 'Evening.');
    $t->must('guard=1 with a real bounty is an arrest-class session on its own',
        (string) ($q2['turn']['arrest'] ?? '') === 'guard', json_encode($q2['turn']['arrest'] ?? null));

    // --- route 2b: IsGuard() said no, but the crime faction is one CHIM itself calls a guard faction
    $g2b = 'Housecarl Flowtest';
    fxDlgCache($g2b, ['Something on your mind?' => [], 'Never mind.' => []]);
    fxDlgServiceCatalog(fxDlgRealCatalog());
    fxDlgEvent($g2b, 'facts', ['guard' => 0, 'bounty' => 40, 'cf' => '0x00028848']);
    $q2b = fxDlgSay($g2b, fxDlgSnap(), 'Evening.');
    $t->must('a crime faction CHIM calls a guard faction is an arrest too, even when IsGuard() said no',
        in_array((string) ($q2b['turn']['arrest'] ?? ''), ['crimefaction', 'guard'], true)
        || lrgDlgGuardFactions() === [],
        json_encode(['arrest' => $q2b['turn']['arrest'] ?? null, 'chim_ids' => lrgDlgGuardFactions()]));

    // --- bounty absent -> no bounty fact at all, and no claim allowed
    $g3 = 'Riften Guard Flowtest';
    fxDlgCache($g3, ['Something on your mind?' => [], 'Never mind.' => []]);
    fxDlgServiceCatalog(fxDlgRealCatalog());
    $q3 = fxDlgSay($g3, fxDlgSnap(), 'Evening.');
    $t->must('with no guard/bounty keys there is no arrest and no bounty fact',
        (string) ($q3['turn']['arrest'] ?? '') === ''
        && !in_array('bounty', array_column((array) ($q3['turn']['locked'] ?? []), 'class'), true),
        json_encode($q3['turn']['locked'] ?? null));

    // --- a STALE bounty is not a fact either
    $g4 = 'Markarth Guard Flowtest';
    fxDlgCache($g4, ['Something on your mind?' => [], 'Never mind.' => []]);
    fxDlgServiceCatalog(fxDlgRealCatalog());
    fxDlgEvent($g4, 'facts', ['guard' => 1, 'bounty' => 40, 'cf' => '0x00028848']);
    fxAdvance(120);
    $q4 = fxDlgSay($g4, fxDlgSnap(), 'Evening.');
    $t->must('a bounty older than the freshness window is dropped, not repeated',
        !in_array('bounty', array_column((array) ($q4['turn']['locked'] ?? []), 'class'), true),
        json_encode($q4['turn']['locked'] ?? null));

    // --- [0.5.1 pt9 go-live / S1] THE WANT=1 FAST PATH TAKES THE SAME TWO ARREST RAILS.
    // The post-LLM gate above hands the menu back; want=1 runs in preprocessing with no LLM and no turn
    // record, so none of lrgDlgGateItem()'s rails saw it - and on the IDENTICAL layer it emitted
    // do=pick on "I submit, take me to the cells." and jailed the player by voice with no visible menu.
    // crit is graded game-side as IsGuard() AND (engine-opened OR crimeGold > 0), so a glue-opened
    // session with a mod-added guard arrives as crit=0 and could not be relied on to stop it.
    $g5 = 'Markarth Enforcer Flowtest';
    $arrestSpec = ['I submit, take me to the cells.' => ['topic' => 'DGCrimeGoToJail', 'scripted' => 1],
        'You will never take me alive.' => ['topic' => 'DGCrimeResistArrest'],
        'I will pay the fine. (200 gold)' => ['cost' => 200, 'topic' => 'DGCrimePayFine']];
    fxDlgCache($g5, $arrestSpec, ['pg' => 400]);
    fxDlgServiceCatalog(fxDlgRealCatalog());
    fxDlgSay($g5, fxDlgSnap(['pgold' => 400]), 'I submit, take me to the cells.');
    fxDlgClearQueue();
    $mark5 = fxDlgLogMark();
    $r5 = fxDlgTopics($g5, ['sid' => 's5', 'gen' => 1, 'layer' => 0, 'crit' => 0, 'want' => 1, 'pg' => 400,
        'ask' => 'i submit take me to the cells', 'cid' => 'dw-arrest'],
        [fxDlgEntry(0, 'I submit, take me to the cells.'), fxDlgEntry(1, 'You will never take me alive.'),
            fxDlgEntry(2, 'I will pay the fine. (200 gold)')]);
    // [pt19c final fixer / spec 3.5 "show on both paths"] the fast path answers do=show;kind=meta (the menu is his) - never a pick
    $onlyShow = static fn(array $r): bool => !str_contains((string) $r['echo'], 'do=pick') && count(fxDlgQueue()) === 1
        && str_contains((string) (fxDlgQueue()[0]['action'] ?? ''), ';do=show;') && str_contains((string) (fxDlgQueue()[0]['action'] ?? ''), ';kind=meta;');
    $t->must('want=1 never clicks an entry on an arrest layer, even at crit=0 - it answers do=show;kind=meta (the menu is his)',
        $onlyShow($r5),
        json_encode([$r5['echo'], fxDlgQueue()]));
    $t->must('and the log says WHY, in the gate\'s own words',
        str_contains(fxDlgLogFrom($mark5), 'ARREST session')
        || str_contains(fxDlgLogFrom($mark5), 'RESIST-ARREST'), fx_short(fxDlgLogFrom($mark5), 400));

    // the resist row REACHED DIRECTLY on the fast path is refused by its own rail, not by the session's
    $g6 = 'Falkreath Enforcer Flowtest';
    fxDlgCache($g6, $arrestSpec, ['pg' => 400]);
    fxDlgServiceCatalog(fxDlgRealCatalog());
    fxDlgSay($g6, fxDlgSnap(['pgold' => 400]), 'You will never take me alive.');
    fxDlgClearQueue();
    $mark6 = fxDlgLogMark();
    $r6 = fxDlgTopics($g6, ['sid' => 's6', 'gen' => 1, 'layer' => 0, 'crit' => 0, 'want' => 1, 'pg' => 400,
        'ask' => 'you will never take me alive', 'cid' => 'dw-resist'],
        [fxDlgEntry(0, 'You will never take me alive.'), fxDlgEntry(1, 'I submit, take me to the cells.')]);
    $t->must('"resist arrest" is unselectable on the fast path too, at every setting - do=show;kind=meta, never a pick',
        $onlyShow($r6),
        json_encode([$r6['echo'], fxDlgQueue()]));
    $t->note('want=1 arrest refusal logged: ' . fx_short(fxDlgLogFrom($mark6), 200));
});
