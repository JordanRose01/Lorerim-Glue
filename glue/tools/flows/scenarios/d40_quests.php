<?php
// PHASE 2 d40 / d41 - quest-tree awareness and "everyone has a price"
// (V04_BUILD_PLAN sections 7 and 8, owner addenda 4 and 6).
require_once __DIR__ . '/../dlg_adapter.php';

fx_scenario('d40', 'quest-tree block: q + qobj join questlog, bookkeeping quests are dropped, tags are rewritten, the CURRENT objective only, at most 3 lines, and nothing from skyrim_quest_definitions', function (FxT $t) {
    $npc = 'Brenna Flowtest';
    fxDlgReset(fxDlgIndexFrom(['I have what you asked for.' => ['quest' => 'FxDog', 'journal' => 1], 'Never mind.' => []]));
    $GLOBALS['LRG_DLG_TEST_QUESTLOG'] = [
        'FxDog' => ['briefing' => 'Find the missing dog for <Alias=QuestGiver>.', 'stage' => 20, 'at' => fxNow()],
        'FxRing' => ['briefing' => 'Bring <Global=RingCount> rings to the <Alias=Fence> in Riften.', 'stage' => 10, 'at' => fxNow() - 50],
        'FxOld' => ['briefing' => 'Speak to the steward.', 'stage' => 5, 'at' => fxNow() - 500],
        '000FCQuestStatus' => ['briefing' => 'bookkeeping row that must never be shown', 'stage' => 1, 'at' => fxNow()],
        'WIChangeLocation04' => ['briefing' => 'another bookkeeping row', 'stage' => 1, 'at' => fxNow()],
        'FxNotMine' => ['briefing' => 'A quest this NPC has nothing to do with.', 'stage' => 1, 'at' => fxNow()],
    ];
    $GLOBALS['LRG_DLG_TEST_QSIG'] = 5;
    fxSendSnapshot($npc, fxDlgSnap());
    fxDlgEvent($npc, 'facts', ['ref' => '0x00012345', 'q' => 'FxDog,FxRing,FxOld,000FCQuestStatus,WIChangeLocation04',
        'qobj' => 'FxDog:20:1.100,FxRing:10:2.100', 'sp' => 30, 'lvl' => 12, 'perk' => '-']);
    $st = fxDlgStateOf($npc);
    $t->must('qobj was parsed into per-quest stages, with the EditorID case preserved',
        isset($st['qobj']['FxDog']['stage']) && (int) $st['qobj']['FxDog']['stage'] === 20,
        json_encode($st['qobj'] ?? []));
    $t->must('q keeps its case too (questlog.id_quest is case sensitive)',
        in_array('FxDog', (array) ($st['q'] ?? []), true), json_encode($st['q'] ?? []));
    fxDlgTopics($npc, ['q' => 'FxDog,FxRing,FxOld,000FCQuestStatus,WIChangeLocation04'],
        [fxDlgEntry(0, 'I have what you asked for.'), fxDlgEntry(1, 'Never mind.')]);
    fxDlgEvent($npc, 'closed', ['sid' => 's1', 'why' => 'goodbye', 'pending' => 0]);
    $q = fxDlgSay($npc, fxDlgSnap(), 'About that dog of yours.');
    $vol = $q['volatile'];
    $t->must('<shared_business> is injected', str_contains($vol, '<shared_business>'), fx_short($vol, 400));
    $t->must('<Alias=QuestGiver> became the NPC\'s own name', str_contains($vol, 'Find the missing dog for ' . $npc),
        fx_short($vol, 400));
    $t->must('another <Alias=X> became "the x"', str_contains($vol, 'the fence'), fx_short($vol, 400));
    $t->must('<Global=...> became "some"', str_contains($vol, 'some rings'), fx_short($vol, 400));
    $t->must('a bookkeeping quest is never shown', !str_contains($vol, 'bookkeeping'), fx_short($vol, 400));
    $t->must('a quest NOT in q is never mentioned (no spoiler feed)',
        !str_contains($vol, 'nothing to do with'), fx_short($vol, 400));
    $t->must('at most 3 objective clauses', substr_count($vol, 'unfinished business') <= 3,
        (string) substr_count($vol, 'unfinished business'));
    $t->must('no stage number reaches the prompt', !preg_match('/\bstage\b|\b:20\b/i', $vol), fx_short($vol, 400));
    $t->must('the quest name in braces IS shown now that questlog has a row', str_contains($vol, '{FxDog}'),
        fx_short($vol, 400));

    // no q -> no block at all (not an empty block)
    fxDlgReset(fxDlgIndexFrom(['I need work.' => []]));
    $GLOBALS['LRG_DLG_TEST_QUESTLOG'] = ['FxDog' => ['briefing' => 'Find the dog.', 'stage' => 20, 'at' => fxNow()]];
    fxSendSnapshot($npc, fxDlgSnap());
    fxDlgTopics($npc, [], [fxDlgEntry(0, 'I need work.')]);
    fxDlgEvent($npc, 'closed', ['sid' => 's1', 'why' => 'goodbye', 'pending' => 0]);
    $q2 = fxDlgSay($npc, fxDlgSnap(), 'Work?');
    $t->must('with an empty q the block is ABSENT, not empty', !str_contains($q2['volatile'], '<shared_business>'),
        fx_short($q2['volatile'], 200));
});

fx_scenario('d41', '"everyone has a price": ONE implementation (lrgPriceFor), the band comes from the wage anchor, a poor commoner is cheap and a jarl is ridiculous, a Vigilant is never for sale, interest makes it free, and the MCM knobs really move it', function (FxT $t) {
    fxDlgReset();
    $t->mustCap('dlg_price', 'lrgPriceFor is delivered', fn() => true);
    // 0.4.0 fix pass: Phase 2's own lrgDlgPrice() was a SECOND implementation that no production code
    // called. It is gone, and this scenario now measures the function the live turn really uses.
    $t->must('there is exactly one price implementation left', !function_exists('lrgDlgPrice'),
        'lrgDlgPrice() still exists - the two will drift again');
    // [pt15 / owner addenda 12e-f] one wage PER TRADE (paid_intimacy.wage_tiers x hours_per_day); with no
    // profile the anchor is the default tier's day, the owner's common wage of 5 gold an hour
    $wage = lrgDlgWage();
    $t->must('the default wage anchor is the common wage: 5 gold/hour x 8 hours = 40 a day', $wage === 40, (string) $wage);
    $t->must('the single flat day wage is gone: one wage per trade',
        !array_key_exists('gold_per_day_of_wage', (array) (lrgConfig()['paid_intimacy'] ?? []))
        && (float) (((lrgConfig()['paid_intimacy'] ?? [])['wage_tiers'] ?? [])['court'] ?? 0) > (float) (((lrgConfig()['paid_intimacy'] ?? [])['wage_tiers'] ?? [])['common'] ?? 0),
        json_encode((lrgConfig()['paid_intimacy'] ?? [])['wage_tiers'] ?? null));

    // The interest WORD is passed in, exactly as tools/test_gates.php section 32 does it: the stance
    // (floor / ceiling / free) is chosen from that word, so letting it drift would measure two things.
    $price = static function (string $who, array $snapOver = [], string $word = 'indifferent') {
        $c = fxCast($who);
        $snap = fxSnap($c['snap'] + $snapOver);
        fxSendSnapshot($c['name'], $snap);
        $profile = lrgBuildProfile($c['name'], $snap);
        $state = lrgGetNpcState($c['name']) ?: $snap;
        return [$c['name'], lrgPriceFor($c['name'], (array) $state, (array) $profile, ['word' => $word]), $profile];
    };

    // a poor commoner: a few days' wage, and the number is real gold
    [$nCom, $pCom] = $price('commoner', ['gold' => '40']);
    $t->must('a commoner IS for sale', !empty($pCom['for_sale']) && empty($pCom['free']), json_encode($pCom));
    $t->must('her floor is days of wage, not hundreds of them',
        (int) $pCom['gold'] > 0 && (int) $pCom['gold'] <= 60 * $wage,
        json_encode([$pCom['gold'], $pCom['band'], $pCom['days']]));
    $t->must('the band really is expressed in DAYS and the gold follows from the anchor',
        (float) $pCom['band'][0] > 0 && (float) $pCom['days'] >= (float) $pCom['band'][0],
        json_encode([$pCom['band'], $pCom['days'], $pCom['gold']]));
    [, $pComCur] = $price('commoner', ['gold' => '40'], 'curious');
    $t->must('curious is the FLOOR of the same band and indifferent the ceiling',
        (int) $pComCur['gold'] < (int) $pCom['gold'], json_encode([$pComCur['gold'], $pCom['gold']]));

    // a jarl: ridiculous, exactly as the owner asked
    [$nJarl, $pJarl, $profJarl] = $price('jarl', ['gold' => '9000']);
    $t->must('a jarl is orders of magnitude more expensive than a commoner',
        (int) $pJarl['gold'] >= 10 * max(1, (int) $pCom['gold']), json_encode([$pCom['gold'], $pJarl['gold']]));
    $t->must('a jarl\'s day is a court day (500), a farmhand\'s a common one (40)',
        (int) round((float) $pJarl['wage_day']) === 500 && (int) round((float) $pCom['wage_day']) === 40,
        json_encode([$pJarl['wage_tier'], $pJarl['wage_day'], $pCom['wage_tier'], $pCom['wage_day']]));
    $wJarl = lrgDlgWage($profJarl, $nJarl, fxCast('jarl')['snap']);
    $t->must('the free-conversation bribe reads the SAME day wage as her price (her own trade)',
        $wJarl === (int) round((float) $pJarl['wage_day']), json_encode([$wJarl, $pJarl['wage_day']]));
    $t->must('a jarl\'s band is in the hundreds of days of wage', (float) $pJarl['band'][1] >= 300,
        json_encode($pJarl['band']));
    $t->must('... and she is still FOR SALE - "everyone has a price"', !empty($pJarl['for_sale']), json_encode($pJarl));

    // tavern folk: the cheap end
    [, $pBeg] = $price('patron', ['gold' => '5']);
    $t->must('a tavern patron is cheaper than a jarl', (int) $pBeg['gold'] < (int) $pJarl['gold'],
        json_encode([$pBeg['gold'], $pJarl['gold']]));

    // a Vigilant of Stendarr is never for sale at any price
    [, $pVig] = $price('vigilant');
    $t->must('a Vigilant is not for sale at any price and the reason is named',
        empty($pVig['for_sale']) && in_array((string) $pVig['why'], ['never', 'not_for_sale'], true), json_encode($pVig));
    $t->must('... and NO figure is produced for her at all (the absence is the rail)',
        (int) $pVig['gold'] === 0 && (int) $pVig['token'] === 0, json_encode($pVig));

    // genuinely interested -> no price at all (the owner's rule)
    [, $pFree] = $price('commoner', ['gold' => '40'], 'interested');
    $t->must('a genuinely interested NPC needs no price at all',
        !empty($pFree['free']) && (int) $pFree['gold'] === 0, json_encode($pFree));
    $t->must('... only a token gift, capped well below her band', (int) $pFree['token'] <= 2 * $wage,
        json_encode([$pFree['token'], $wage]));

    // the MCM knobs: pm (fPriceMultiplier) and paidok (bPaidIntimacy) reach this function, which is the
    // whole reason the deleted twin was the wrong one - it read neither.
    $c = fxCast('commoner');
    $snapPm = fxSnap($c['snap'] + ['gold' => '40', 'pm' => '2.0']);
    fxSendSnapshot($c['name'], $snapPm);
    $pPm = lrgPriceFor($c['name'], (array) (lrgGetNpcState($c['name']) ?: $snapPm),
        lrgBuildProfile($c['name'], $snapPm), ['word' => 'indifferent']);
    $t->must('the MCM price multiplier rescales the whole economy from one knob',
        (int) $pPm['gold'] > (int) $pCom['gold'], json_encode([$pCom['gold'], $pPm['gold']]));
    $snapOff = fxSnap($c['snap'] + ['gold' => '40', 'paidok' => '0']);
    fxSendSnapshot($c['name'], $snapOff);
    $pOff = lrgPriceFor($c['name'], (array) (lrgGetNpcState($c['name']) ?: $snapOff),
        lrgBuildProfile($c['name'], $snapOff), ['word' => 'indifferent']);
    $t->must('and the MCM master toggle switches the whole subject off',
        empty($pOff['for_sale']) && (string) $pOff['why'] === 'disabled', json_encode($pOff));

    $t->must('how much coin moves this character is a real factor on the days',
        (float) $pCom['infl'] > 0 && (float) $pJarl['infl'] > 0, json_encode([$pCom['infl'], $pJarl['infl']]));
    $t->note('the ACCEPTANCE half of this feature - BeginIntimacy\'s amount slot, the post-gate drop of an '
        . 'unaffordable or below-floor offer, pay= on ExtCmdLRG_StartIntimacy and the halved relationship '
        . 'gain - lives in lib/lrg_actions.php and LRG_OStim.psc. tools/test_gates.php section 32 asserts '
        . 'the exact arithmetic of every tier; this scenario asserts the RULES through the flow harness.');
});
