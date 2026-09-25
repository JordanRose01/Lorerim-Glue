<?php
// PHASE 2 d66 - [pt19-purchase] BUYING FOOD AND DRINK BY VOICE (research/pt19-purchase.md, PROTOCOL 10.27).
//
// Playtest 2026-09-24 02:52-02:55, the owner's words: "i ordered a beer/ale ... she noticed i was trying to buy ... gave me a
// price (too cheap ... 1-3 septims ... her in-menu prices were like 19 for a mead) but she couldn't seem to give me the drink".
// Hulda's turns: "i'll have uh l please" / "uh some beer" / "yeah thank you" -> "That'll be one septim for a bottle of
// Honningbrew" and no bottle. Three defects stacked: no lane recognised an order, nothing could hand over stock from the
// inn's merchant chest (the DLL ships her PERSONAL inventory; Give_Item_To is bound to it), and no rail judged the price.
// The "19" was Ale (value 5 x 3.85 at Speech 15), not a mead (10 x 3.85 = 39) - the investigator's scan had dropped the
// 4-byte "Ale" name. This scenario replays the evening through the real hook files with the Bannered Mare's chest on the
// snapshot as the game now sends it, then plays every refusal. Every sentence below is the PLAYER's (search input), the
// GAME's, or the model's REAL wrong output being forbidden - never authored dialogue.
require_once __DIR__ . '/../dlg_adapter.php';

fx_scenario('d66', '[pt19-purchase] an order of food or drink: the snapshot\'s real stock, "uh some beer" -> Ale, ONE ExtCmdLRG_Buy after her line, the OK quiet and the count refreshed, an invented price ("one septim") re-asked and never sold under, a price question sells nothing, too little coin, the game\'s refusals in her words, the dry run names its page, a non-vendor sells nothing, no stock falls back to the barter window, the calibration never opens on an order', function (FxT $t) {
    $hulda = 'Hulda Flowtest';
    fxDlgReset();
    $GLOBALS['LRG_DLG_MCM'] = ['ml' => 0, 'lf' => 1, 'cal' => 3];
    $GLOBALS['LRG_DLG_TEST_QUESTLOG'] = [];
    // REQ_VendorChest_Inn_Whiterun as the game sends it: Ale 5, Nord Mead 10, Honningbrew Mead 10, Bread 6, Bread Half 3,
    // Village White Wine 20 septims of value; Speech 15, no price perk -> x3.85 -> 19 / 39 / 39 / 23 / 12 / 77
    $stock = '00034C5E:Ale:19:8:5,00034C5D:Nord Mead:39:3:10,000508CA:Honningbrew Mead:39:12:10,00065C97:Bread:23:24:6,00065C98:Bread Half:12:4:3,000C5348:Village White Wine:77:2:20';
    $bp = '4.000000,3.000000,2.000000,15,0.000000,0.000000,3.850000,-';
    $inn = ['fac' => 'JobInnkeeperFaction,ServicesWhiterunBanneredMare,TownWhiterunFaction', 'class' => '', 'vend' => '1', 'room' => '25', 'bp' => $bp, 'stock' => $stock, 'pgold' => '300', 'pspeech' => '15'];
    $snap = fxDlgSnap($inn);
    $facts = ['q' => '-', 'qst' => '-', 'dlg' => '0', 'cal' => '3', 'sq' => '-', 'sqj' => '0', 'sp' => '15'];
    $blockOf = static function (?array $turn): string { return is_array($turn) ? (string) lrgDlgLockedBlock($turn) : ''; };
    $enabled = array_merge(fxEnabledDefault(), [FX_DLG_ACT, 'OpenInventory', 'GiveItemTo', 'RentRoom']);

    // ---------------------------------------------------------------- 1. 02:53:39 "i'll have uh l please": an order with nothing recognisable -> she names what she has
    fxSendSnapshot($hulda, $snap);
    fxDlgEvent($hulda, 'facts', $facts);
    $r = fxDlgSay($hulda, $snap, "i'll have uh l please", 'inputtext', $enabled);
    $b = (array) ($r['turn']['buy'] ?? []);
    $t->must('"i\'ll have uh l please": the plan ASKS, listing the three drinks with their prices (Ale 19, Nord Mead 39, Honningbrew Mead 39)',
        ($b['state'] ?? '') === 'ask' && count((array) ($b['list'] ?? [])) === 3 && ($b['list'][0]['name'] ?? '') === 'Ale' && (int) ($b['list'][0]['price'] ?? 0) === 19, json_encode($b['list'] ?? null));
    $t->must('...the directive: name what you have from this list with the prices, ask which, sell nothing yet',
        str_contains($r['volatile'], 'Name what you have from this list with the prices - Ale (19 septims), Nord Mead (39 septims), Honningbrew Mead (39 septims)') && str_contains($r['volatile'], 'Sell nothing yet'), fx_short($r['volatile'], 600));
    $lb = $blockOf($r['turn']);
    $t->must('...the locked facts: what Hulda sells at the price she asks (six rows, drinks first), a room here costs 25 septims, Jordan is carrying 300 septims (the gold class lives again)',
        str_contains($lb, 'what Hulda Flowtest sells, at the price Hulda Flowtest asks: Ale 19 septims (8 left); Nord Mead 39 septims (3 left); Honningbrew Mead 39 septims (12 left)')
        && str_contains($lb, 'a room here costs 25 septims') && str_contains($lb, 'is carrying 300 septims'), fx_short($lb, 700));
    $t->must('...Give_Item_To is off the table (it can never hand over stock); the barter window stays (an ask may show the wares)',
        !in_array('GiveItemTo', $r['enabled'], true) && in_array('OpenInventory', $r['enabled'], true), implode(',', $r['enabled']));
    $t->must('...the turn line says buy=ask', str_contains(lrgDlgTurnLine($r['turn']), ' buy=ask'), lrgDlgTurnLine($r['turn']));
    $w = fxDlgLlmNothing($hulda, 'Ale, Nord Mead or Honningbrew, friend. Which will it be?');
    $t->must('...the model only talked: nothing goes out - no buy line, no calibration open (candidate = buying), no D2 row', $w === [] && fxDlgQueue() === [], json_encode($w));

    // ---------------------------------------------------------------- 2. 02:54:23 "uh some beer": beer -> the one row with the token ale -> ONE ExtCmdLRG_Buy after her line
    $r = fxDlgSay($hulda, $snap, 'uh some beer', 'inputtext', $enabled);
    $b = (array) ($r['turn']['buy'] ?? []);
    $t->must('"uh some beer": QUEUED Ale x1 @19 (the class word beer -> Ale, resolved from the real stock)', ($b['state'] ?? '') === 'queued' && ($b['item']['name'] ?? '') === 'Ale' && (int) ($b['price'] ?? 0) === 19 && (int) ($b['total'] ?? 0) === 19, json_encode($b['item'] ?? null));
    $t->must('...the directive: Flowtest Player ordered 1 Ale. Do it: ONE short line handing it over for 19 septims - that price and no other number; the game takes the coin; choose no action',
        str_contains($r['volatile'], 'Flowtest Player ordered 1 Ale. Do it: say ONE short line handing it over for 19 septims - that price and no other number.') && str_contains($r['volatile'], 'The game itself takes the coin'), fx_short($r['volatile'], 600));
    $t->must('...Give_Item_To AND the barter window are off the table on a queued order (the carrier is the route)', !in_array('GiveItemTo', $r['enabled'], true) && !in_array('OpenInventory', $r['enabled'], true), implode(',', $r['enabled']));
    $t->must('...the locked facts carry the order: Flowtest Player ordered 1 Ale: 19 septims in all', str_contains($blockOf($r['turn']), 'Flowtest Player ordered 1 Ale: 19 septims in all'), fx_short($blockOf($r['turn']), 600));
    $t->must('...the turn line: buy=queued:Ale@19', str_contains(lrgDlgTurnLine($r['turn']), ' buy=queued:Ale@19'), lrgDlgTurnLine($r['turn']));
    $w = fxDlgLlmNothing($hulda, 'Ale it is. Nineteen septims, friend.');
    $t->must('ONE ExtCmdLRG_Buy line goes out through Phase 2\'s gate after her own line, and NO D2 row',
        count($w) === 1 && fxCode($w[0]) === 'ExtCmdLRG_Buy' && fxDlgQueue() === [], json_encode(array_map('trim', $w)));
    $kv = $w ? fxParamKv($w[0]) : [];
    $t->must('...its param: ok=1;cid=<turn cid>;npc=Hulda Flowtest;item=00034C5E;n=1;price=19;name=Ale;x=<10 hex>;z=1',
        ($kv['cid'] ?? '') === (string) ($r['turn']['cid'] ?? '?') && ($kv['npc'] ?? '') === $hulda && ($kv['item'] ?? '') === '00034C5E' && ($kv['n'] ?? '') === '1' && ($kv['price'] ?? '') === '19'
        && ($kv['name'] ?? '') === 'Ale' && strlen((string) ($kv['x'] ?? '')) === 10 && ($kv['z'] ?? '') === '1', fxParam($w[0] ?? ''));
    $t->must('...a second run of the gate in the same request adds nothing more', lrgDlgPostProcessActions([]) === []);
    $t->must('...a buy line the MODEL emitted is dropped by Phase 1\'s gate like any unoffered glue action', lrgPostProcessActions($w) === []);
    $ex = (array) (fxMem($hulda)['buyexec'] ?? []);
    $t->must('...buyexec holds it as pending in lrg_memory', ($ex['name'] ?? '') === 'Ale' && (int) ($ex['done'] ?? 1) === 0 && ($ex['cid'] ?? '') === (string) ($r['turn']['cid'] ?? '?'), json_encode($ex));
    // a second order before the answer: pending, nothing sent again
    $r2 = fxDlgSay($hulda, $snap, 'and a bread', 'inputtext', $enabled);
    $t->must('a second order before the game answered: PENDING, nothing sent again', (($r2['turn']['buy']['state'] ?? '') === 'pending') && fxDlgLlmNothing($hulda, 'One thing at a time.') === [], json_encode($r2['turn']['buy']['state'] ?? null));

    // ---------------------------------------------------------------- 3. the game's OK: quiet, the count refreshed
    $lineOk = str_replace(';z=1', ';z=1;unit=19;paid=19;left=281;stock=7', (string) $w[0]);
    $fr = fxFuncretTurn($hulda, $lineOk, 'OK: 1 Ale handed over for 19 septims');
    $t->must('the OK funcret is ended before the lock (quiet: her line said it; the vanilla "Ale added" message is the receipt)', $fr->handled, $fr->summary());
    $m = fxMem($hulda);
    $t->must('...remembered: bought Ale for 19, buyexec done, the cached row Ale = 7 left', (($m['bought'][0]['name'] ?? '') === 'Ale') && (int) ($m['buyexec']['done'] ?? 0) === 1 && (int) ($m['buyadj']['00034C5E']['count'] ?? -1) === 7, json_encode($m));
    $r = fxDlgSay($hulda, $snap, 'a honningbrew then', 'inputtext', $enabled);
    $b = (array) ($r['turn']['buy'] ?? []);
    $t->must('"a honningbrew then": QUEUED Honningbrew Mead @39, and the SAME snapshot now shows Ale with 7 left (the funcret is fresher than the snapshot)',
        ($b['state'] ?? '') === 'queued' && ($b['item']['name'] ?? '') === 'Honningbrew Mead' && (int) ($b['price'] ?? 0) === 39
        && (static function () use ($b): bool { foreach ((array) ($b['facts']['stock'] ?? []) as $row) { if ($row['name'] === 'Ale') { return (int) $row['count'] === 7; } } return false; })(), json_encode($b['facts']['stock'] ?? null));

    // ---------------------------------------------------------------- 4. the invented price, on the real hook files: "That'll be one septim" is re-asked, and nothing is sold under it
    fxMemSet($hulda, ['buyexec' => null]);
    $GLOBALS['FUNCTIONS_ARE_ENABLED'] = true;
    fxSendSnapshot($hulda, $snap);
    $rh = fxHookTurn($hulda, 'inputtext', 'a honningbrew please', $enabled);
    $dt = (array) ($GLOBALS['LRG_DLG_TURN'] ?? []);
    $t->must('through the real hook files: the turn is queued for Honningbrew Mead @39 and the validator seam is registered', (($dt['buy']['state'] ?? '') === 'queued') && is_callable($GLOBALS['VALIDATE_LLM_OUTPUT_FNCT'] ?? null), json_encode($dt['buy']['state'] ?? null));
    // CHIM's own schema and the decoded object as the JSON connector stores it per chunk (flow 31)
    $keys = ['character', 'listener', 'message', 'mood', 'action', 'target', 'item', 'amount'];
    $props = [];
    foreach ($keys as $k) { $props[$k] = ['type' => $k === 'amount' ? 'integer' : 'string', 'description' => $k]; }
    $GLOBALS['structuredOutputTemplate'] = ['type' => 'json_schema', 'json_schema' => ['name' => 'response', 'strict' => true, 'schema' => ['type' => 'object', 'properties' => $props, 'required' => $keys, 'additionalProperties' => false]]];
    $reply = static function (string $message) use ($hulda): array {
        return ['character' => $hulda, 'listener' => 'Jordan', 'message' => $message, 'mood' => 'calm', 'action' => 'Talk', 'target' => 'Jordan', 'item' => '', 'amount' => 1];
    };
    $at = (int) @filesize(fxLogFile());
    $GLOBALS['LAST_LLM_RESPONSE'] = $reply("That'll be one septim for a bottle of Honningbrew, friend.");
    $t->must('the validator REJECTS the reply at the price sentence (why=false claim, class price, the number WORD read)',
        $GLOBALS['VALIDATE_LLM_OUTPUT_FNCT']('') === false && (($GLOBALS['LRG_NE_REJECT']['why'] ?? '') === 'false claim') && (($GLOBALS['LRG_NE_REJECT']['class'] ?? '') === 'price')
        && str_contains((string) ($GLOBALS['LRG_NE_REJECT']['fact'] ?? ''), 'Honningbrew Mead is 39 septims, not 1'), json_encode($GLOBALS['LRG_NE_REJECT'] ?? null));
    $log = (string) substr((string) @file_get_contents(fxLogFile()), $at);
    $t->must('...logged: never-false npc=Hulda Flowtest role=vendor class=price ... verdict=rejected', str_contains($log, 'never-false npc=Hulda Flowtest role=vendor class=price') && str_contains($log, 'verdict=rejected'), fx_short($log, 400));
    $GLOBALS['talkedSoFar'] = [];
    $GLOBALS['contextData'] = [['role' => 'system', 'content' => 'system'], ['role' => 'user', 'content' => "Write $hulda's next dialogue line."]];
    $asked = 0; $nudge = '';
    $GLOBALS['LRG_TEST_REASK'] = static function (string $n) use (&$asked, &$nudge): bool { $asked++; $nudge = $n; return true; };
    $GLOBALS['LLM_RETRY_FNCT']();
    $t->must('the retry re-asked ONCE, quoting her own sentence as a price the game has not set and naming the list\'s price (Honningbrew Mead is 39 septims)',
        $asked === 1 && str_contains($nudge, "the sentence 'That'll be one septim for a bottle of Honningbrew, friend.' names a price the game has not set") && str_contains($nudge, 'Honningbrew Mead is 39 septims') && !str_contains($nudge, '"'), $nudge);
    // the second net: had the wrong number reached the post-gate, the truth gate flags it and the buy net WITHHOLDS the line
    unset($GLOBALS['LRG_NE_REJECT'], $GLOBALS['LRG_NF_SHAPE']);
    $GLOBALS['LRG_NF_SEEN'] = [];
    fxSpoke("That'll be one septim for a bottle of Honningbrew, friend.");
    $at = (int) @filesize(fxLogFile());
    $w = lrgDlgPostProcessActions([]);
    $log = (string) substr((string) @file_get_contents(fxLogFile()), $at);
    $t->must('...and had it reached the post-gate: the truth gate logs the claim and the buy net WITHHOLDS the line - nothing is sold under a wrong number',
        $w === [] && str_contains($log, 'truth npc=Hulda Flowtest claim=price said="1 septims"') && str_contains($log, 'WITHHELD: she quoted 1 septims'), fx_short($log, 500));
    // the truthful reply on the same request: the validator passes it and the line goes out
    unset($GLOBALS['LRG_NE_REJECT'], $GLOBALS['LRG_NF_SHAPE'], $GLOBALS['LRG_MKT_SENT']);
    $GLOBALS['LRG_NF_SEEN'] = [];
    $GLOBALS['LAST_LLM_RESPONSE'] = $reply('Honningbrew, thirty-nine septims. Here.');
    $t->must('a truthful reply (thirty-nine septims) passes the validator', $GLOBALS['VALIDATE_LLM_OUTPUT_FNCT']('') === true && !isset($GLOBALS['LRG_NE_REJECT']));
    fxSpoke('Honningbrew, thirty-nine septims. Here.');
    $w = lrgDlgPostProcessActions([]);
    $t->must('...and ONE ExtCmdLRG_Buy for Honningbrew Mead @39 goes out with it', count($w) === 1 && fxCode($w[0]) === 'ExtCmdLRG_Buy' && (fxParamKv($w[0])['item'] ?? '') === '000508CA' && (fxParamKv($w[0])['price'] ?? '') === '39', json_encode(array_map('trim', $w)));
    unset($GLOBALS['LRG_TEST_REASK'], $GLOBALS['contextData']);
    $GLOBALS['FUNCTIONS_ARE_ENABLED'] = false;

    // ---------------------------------------------------------------- 5. "how much for a mead": a quote, nothing sold; too little coin: nothing sold
    fxMemSet($hulda, ['buyexec' => null]);
    $r = fxDlgSay($hulda, $snap, 'how much for a mead', 'inputtext', $enabled);
    $t->must('"how much for a mead": QUOTE with the two meads, the directive answers with the listed price only, nothing goes out',
        (($r['turn']['buy']['state'] ?? '') === 'quote') && str_contains($r['volatile'], 'Answer with the listed price only - Nord Mead (39 septims), Honningbrew Mead (39 septims)') && fxDlgLlmNothing($hulda, 'Thirty-nine either way.') === [], fx_short($r['volatile'], 400));
    $poor = fxDlgSnap($inn);
    $poor['pgold'] = '20';   // fxDlgSnap's own defaults win over an override: set the purse after
    $r = fxDlgSay($hulda, $poor, 'a honningbrew please', 'inputtext', $enabled);
    $t->must('with 20 septims: UNAFFORDABLE - "Flowtest Player cannot pay 39 septims with the 20 he carries", nothing goes out',
        (($r['turn']['buy']['state'] ?? '') === 'unaffordable') && str_contains($r['volatile'], 'Flowtest Player cannot pay 39 septims with the 20 he carries') && fxDlgLlmNothing($hulda, 'Not with that purse.') === [], fx_short($r['volatile'], 400));

    // ---------------------------------------------------------------- 6. the game's refusals, voiced in her words; the dry run names its page
    fxSendSnapshot($hulda, $snap);
    $r = fxDlgSay($hulda, $snap, 'a honningbrew please', 'inputtext', $enabled);
    $w = fxDlgLlmNothing($hulda, 'Thirty-nine septims. Here you are.');
    $t->must('set-up: the line went out again', count($w) === 1 && fxCode($w[0]) === 'ExtCmdLRG_Buy');
    fxMemSet($hulda, ['voiced_at' => null]);
    $lineErr = str_replace(';z=1', ';z=1;unit=39;err=not enough gold (has 20, needs 39)', (string) $w[0]);
    $fr = fxFuncretTurn($hulda, $lineErr, 'Error: you cannot pay 39 septims with the 20 you carry');
    $t->must('the game\'s "not enough gold" is VOICED: not ended, the cue says the Honningbrew Mead did not change hands and Jordan cannot pay 39 septims with the 20 he carries; buyexec cleared',
        !$fr->handled && !$fr->ended && str_contains($fr->cue, 'the Honningbrew Mead did not change hands') && str_contains($fr->cue, 'cannot pay 39 septims with the 20 he carries') && empty(fxMem($hulda)['buyexec']), fx_short($fr->cue, 500));
    $r = fxDlgSay($hulda, $snap, 'a honningbrew please', 'inputtext', $enabled);
    $w = fxDlgLlmNothing($hulda, 'Thirty-nine septims.');
    fxMemSet($hulda, ['voiced_at' => null]);
    $lineMoved = str_replace(';z=1', ';z=1;unit=41;err=the price is 41 septims, not 39', (string) $w[0]);
    $fr = fxFuncretTurn($hulda, $lineMoved, 'Error: it is 41 septims now, not 39 - say the word and it is yours');
    $t->must('a moved price is re-quoted, never charged: "it is 41 septims now, not 39 - say the word and it is his"; the cached row takes 41',
        !$fr->handled && str_contains($fr->cue, 'it is 41 septims now, not 39 - say the word and it is his') && (int) (fxMem($hulda)['buyadj']['000508CA']['price'] ?? 0) === 41, fx_short($fr->cue, 400));
    $r = fxDlgSay($hulda, $snap, 'a honningbrew please', 'inputtext', $enabled);
    $t->must('...and the next order of it is planned at the LIVE 41 (the re-ask quotes the real number)', (int) ($r['turn']['buy']['price'] ?? 0) === 41 && str_contains($r['volatile'], 'for 41 septims'), json_encode($r['turn']['buy']['price'] ?? null));
    $w = fxDlgLlmNothing($hulda, 'Forty-one, then.');
    fxMemSet($hulda, ['voiced_at' => null]);
    $lineDry = str_replace(';z=1', ';z=1;err=dry-run mode is ON in the LoreRim Glue MCM (Diagnostics page) - nothing was sold', (string) $w[0]);
    $fr = fxFuncretTurn($hulda, $lineDry, 'Error: I cannot - the LoreRim Glue dry-run switch is on in its settings, on the Diagnostics page');
    $t->must('the developer dry run refusal is voiced and names the Diagnostics page', !$fr->handled && !$fr->ended && str_contains($fr->cue, 'Diagnostics page'), fx_short($fr->cue, 400));
    fxMemSet($hulda, ['buyexec' => null, 'buyadj' => null]);

    // ---------------------------------------------------------------- 7. a non-vendor sells nothing; no stock falls back to the barter window
    $nazeem = 'Nazeem Flowtest';
    fxSendSnapshot($nazeem, fxDlgSnap(['fac' => 'TownWhiterunFaction', 'class' => '', 'vend' => '0']));
    $r = fxDlgSay($nazeem, fxDlgSnap(['fac' => 'TownWhiterunFaction', 'class' => '', 'vend' => '0']), 'a mead please', 'inputtext', $enabled);
    $t->must('a non-vendor (vend=0): NOT-VENDOR - "Nazeem Flowtest sells nothing", no price, no action, nothing goes out',
        (($r['turn']['buy']['state'] ?? '') === 'not-vendor') && str_contains($r['volatile'], 'Nazeem Flowtest sells nothing') && fxDlgLlmNothing($nazeem, 'Do I look like a barmaid?') === [], fx_short($r['volatile'], 400));
    $addvar = 'Addvar Flowtest';
    $stall = fxDlgSnap(['fac' => 'JobMerchantFaction,ServicesSolitudeAddvar', 'class' => 'VendorFood', 'combat' => '0']);
    fxSendSnapshot($addvar, $stall);
    $r = fxDlgSay($addvar, $stall, "i'll have a salmon", 'inputtext', $enabled);
    $w = fxDlgLlmNothing($addvar, 'Have a look at what I have.');
    $t->must('a vendor with NO stock on the wire: NO-STOCK falls back to the pt16 barter route - svc=barter direct, CHIM\'s OpenInventory appended, no refusal',
        (($r['turn']['buy']['state'] ?? '') === 'no-stock') && (($r['turn']['svc']['kind'] ?? '') === 'barter') && !empty($r['turn']['svc']['direct']) && count($w) === 1 && fxCode($w[0]) === 'OpenInventory'
        && str_contains($r['volatile'], 'asked to see what Addvar Flowtest has for sale'), json_encode([$r['turn']['buy']['state'] ?? null, $r['turn']['svc'] ?? null, array_map('trim', $w)]));
    unset($GLOBALS['LRG_DLG_MCM'], $GLOBALS['LRG_DLG_TEST_QUESTLOG']);
});
