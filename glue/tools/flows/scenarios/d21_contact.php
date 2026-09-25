<?php
// PHASE 2 d21 / d21b / d22 - first contact, result duty, and intent mode's class rail (design 7.3 rows 21, 21b, 22).
require_once __DIR__ . '/../dlg_adapter.php';

fx_scenario('d21', 'first contact: no list -> no block; do=open only with bIntentOpen AND a named marker; want=1 answers on BOTH routes with one x', function (FxT $t) {
    $npc = 'Hroda Flowtest';
    fxDlgReset(fxDlgIndexFrom(['I need work.' => [], "I'd like to rent a room." => []]));
    $t->mustCap(['dlg_turn', 'dlg_gate'], 'the turn and the gate are delivered', fn() => true);

    // 1. no list at all -> no <business> block, but the action is still offered (intent mode). No NARROW marker in these
    // words (no kind phrase, no cached root, no verbatim prompt, no journal row of hers): the pre-LLM open stays out.
    $q = fxDlgSay($npc, fxDlgSnap(), 'Have you got any work for me?');
    $t->must('no pre-LLM open on words that name no marker', empty($q['turn']['open_pending']) && fxDlgQueue() === [],
        json_encode(fxDlgQueue()));
    $t->must('no list -> no <business> block', !str_contains($q['volatile'], '<business'), fx_short($q['volatile'], 200));
    // and no static rules block either: with nothing known it would be 70 wasted words on every ordinary
    // CHIM turn. The action row's own description carries the two rules that matter on a cold turn.
    $t->must('no list -> nothing injected at all', $q['static'] === '', fx_short($q['static'], 120));
    $t->must(FX_DLG_NAME . ' is offered on a speech turn', in_array(FX_DLG_ACT, $q['enabled'], true),
        implode(',', $q['enabled']));

    // 2. the model names the business in words, and it carries a marker (a service word)
    $w = fxDlgLlm($npc, 'rent a room for the night');
    $t->must('a named business marker produces do=open', count($w) === 1 && fxDlgDo($w[0]) === 'open',
        json_encode(array_map('fxParam', $w)));
    $t->must('do=open carries ask= and no position', count($w) === 1
        && (string) (fxDlgKv($w[0])['pos'] ?? '') === '-1' && (string) (fxDlgKv($w[0])['ask'] ?? '') !== '',
        count($w) ? fxParam($w[0]) : '-');
    $t->must('the param key order is exactly PROTOCOL v0.4 section 8', count($w) === 1 && fxDlgOrderOk($w[0]),
        count($w) ? fxParam($w[0]) : '-');

    // 3. no marker -> no open for awareness at all (a Say-Once greeting is not spent on nothing)
    fxDlgSay($npc, fxDlgSnap(), 'Fine weather we are having.');
    $w2 = fxDlgLlm($npc, 'the weather today');
    $t->must('no business marker -> nothing is emitted', $w2 === [], json_encode(array_map('fxParam', $w2)));

    // 4. want=1 is answered on BOTH delivery routes with the SAME x
    fxDlgClearQueue();
    $r = fxDlgTopics($npc, ['want' => 1, 'ask' => 'any work', 'cid' => 'dw01'], [
        fxDlgEntry(0, 'I need work.'), fxDlgEntry(1, 'Never mind.'),
    ]);
    $t->must('D1 wrote the command into the same HTTP reply', str_contains($r['echo'], '|command|' . FX_DLG_ACT . '@'),
        fx_short($r['echo'], 160));
    $q2 = fxDlgQueue();
    $t->must('D2 queued exactly one responselog row', count($q2) === 1, json_encode($q2));
    $x1 = preg_match('/;x=([0-9a-f]{10});/', $r['echo'], $m1) ? $m1[1] : '';
    $x2 = preg_match('/;x=([0-9a-f]{10});/', (string) ($q2[0]['action'] ?? ''), $m2) ? $m2[1] : '';
    $t->must('both routes carry the SAME x', $x1 !== '' && $x1 === $x2, "D1=$x1 D2=$x2");
    $t->must('the matched plain entry is picked by position', str_contains($r['echo'], ';do=pick;')
        && str_contains($r['echo'], ';pos=0;'), fx_short($r['echo'], 160));
    $t->must('the D2 row has the production shape (localts, sent=0, actor, action, tag)',
        (int) ($q2[0]['sent'] ?? -1) === 0 && (string) ($q2[0]['actor'] ?? '') === $npc
        && strncmp((string) ($q2[0]['action'] ?? ''), 'command|' . FX_DLG_ACT . '@', 22) === 0
        && (string) ($q2[0]['text'] ?? 'x') === '', json_encode($q2[0] ?? []));
});

fx_scenario('d21b', 'result duty: one decision = one x on both routes, z=1 last so a trailing pipe cannot eat a value', function (FxT $t) {
    $npc = 'Hroda Flowtest';
    fxDlgReset(fxDlgIndexFrom(['I need work.' => []]));
    fxDlgClearQueue();
    $r = fxDlgTopics($npc, ['want' => 1, 'ask' => 'work', 'cid' => 'dw02'], [fxDlgEntry(0, 'I need work.')]);
    $q = fxDlgQueue();
    $t->must('exactly one decision left the server', count($q) === 1 && $r['echo'] !== '', count($q) . ' queued');
    $param = (string) preg_replace('/^command\|' . preg_quote(FX_DLG_ACT, '/') . '@/', '', (string) ($q[0]['action'] ?? ''));
    $t->must('the param ends with the throw-away key z=1', str_ends_with($param, ';z=1'), fx_short($param, 200));
    $t->must('no value in the param contains ; = @ | or a quote',
        !preg_match('/=[^;]*[@|"]/', $param), fx_short($param, 200));
    // a SECOND want=1 for the same list is a new decision with a new x; the GAME's ring of 8 is what makes
    // two DELIVERIES of one x a single execution, and that half is asserted in game (T3 / the x ring).
    fxDlgClearQueue();
    fxDlgTopics($npc, ['want' => 1, 'ask' => 'work', 'cid' => 'dw03', 'gen' => 1], [fxDlgEntry(0, 'I need work.')]);
    $q2 = fxDlgQueue();
    $t->must('a second want=1 is a new decision with its own x', count($q2) === 1
        && (string) ($q2[0]['action'] ?? '') !== (string) ($q[0]['action'] ?? ''), count($q2) . ' queued');
    $t->note('the other half of the result duty (exactly one commandEndedForActor per x, CmdSelectTopic '
        . 'returning inside one frame) is game side and is asserted by the driver, not here');
});

fx_scenario('d22', 'intent mode never executes a check; the list is cached; lrg_dlgtalk is admitted only with a fresh utterance and no open session', function (FxT $t) {
    $npc = 'Hroda Flowtest';
    fxDlgReset(fxDlgIndexFrom([
        'Come on, you can tell me. (Persuade)' => ['kind' => 'persuade', 'variant' => 'success', 'toplevel' => 1],
        'I need work.' => [],
    ]));
    $q = fxDlgSay($npc, fxDlgSnap(), 'Come on, you can tell me what happened here.');
    $t->must('the utterance is remembered for this NPC',
        (string) (fxDlgStateOf($npc)['utter']['text'] ?? '') !== '', json_encode(fxDlgStateOf($npc)['utter'] ?? []));
    fxDlgClearQueue();
    $r = fxDlgTopics($npc, ['want' => 1, 'ask' => 'come on you can tell me what happened here', 'cid' => 'dw04'], [
        fxDlgEntry(0, 'Come on, you can tell me. (Persuade)'), fxDlgEntry(1, 'I need work.'),
    ]);
    $t->must('intent mode did NOT execute the check entry', fxDlgQueue() === [] && $r['echo'] === '',
        fx_short($r['echo'] . ' ' . json_encode(fxDlgQueue()), 200));
    $t->must('the list is cached all the same', count((array) (fxDlgStateOf($npc)['root']['entries'] ?? [])) === 2,
        json_encode(array_keys(fxDlgStateOf($npc))));

    // [pt19 v1.0 / S2.1] lrg_dlgtalk (the "again" turn): the game no longer requests it, and an older script's request is
    // answered pre-lock and costs nothing - open session or not, fresh utterance or not (session.talk_again = false)
    $t->must('lrg_dlgtalk is answered pre-lock while a session is open', fxDlgTalk($npc) === 'handled');
    fxDlgEvent($npc, 'closed', ['sid' => 's1', 'why' => 'goodbye', 'pending' => 0, 'layer' => 0]);
    $t->must('...and with a fresh utterance and no session: no second paid turn (talk_again false)', fxDlgTalk($npc) === 'handled');
});

fx_scenario('d21c', '[pt19 v1.0 / S2.1] first contact never pays a second LLM turn: the pre-LLM open on the narrow marker, the bridging line, the fast pick from his own words, the mute in time (and not when late), the open turn\'s key held or dropped, no OpenInventory beside the open', function (FxT $t) {
    $npc = 'Hulda Flowtest';
    $line = 'Nice inn you have here. Do you get many visitors?';
    $root = ['What have you got for sale?' => ['toplevel' => 1, 'scripted' => 1, 'quest' => 'DialogueGeneric'],
        "I'd like to rent a room. (10 gold)" => ['toplevel' => 1, 'cost' => 10, 'quest' => 'DialogueGeneric'],
        // the live row's topic editor id: clause 4 opens only on a row HER list can carry, and More to Say names her
        $line => ['toplevel' => 1, 'quest' => 'ACFDialogueWhiterun', 'info_key' => 'moretosaywhiterun.esp:000940',
            'topic' => 'ACFDialogueWhiterunHuldaBranchChatTopic'],
        'Heard any rumors lately?' => ['toplevel' => 1, 'scripted' => 1, 'quest' => 'DarkBrotherhood']];
    fxDlgReset(fxDlgIndexFrom($root));
    lrgDlgPut('*install*', ['clicks_ok' => 1]);
    $snap = fxDlgSnap(['fac' => 'JobInnkeeperFaction,TownWhiterunFaction', 'class' => '']);
    $mark = fxDlgLogMark();
    // 1. COLD (no list, no cached root): his sentence IS a verbatim top-level prompt of this load order (clause 4)
    $q = fxDlgSay($npc, $snap, $line);
    $cid = (string) ($q['turn']['cid'] ?? '');
    $open = array_values(array_filter(fxDlgQueue(), static fn($r) => str_contains((string) ($r['action'] ?? ''), ';do=open;')));
    $t->must('the open is queued BEFORE the model runs (D2), on clause toplevel', count($open) === 1 && !empty($q['turn']['open_pending'])
        && str_contains(fxDlgLogFrom($mark), 'open marker=toplevel row=moretosaywhiterun.esp:000940'),
        json_encode([fxDlgQueue(), fx_short(fxDlgLogFrom($mark), 300)]));
    $t->must('...do=open carries his words in ask= and the turn cid', count($open) === 1
        && str_contains((string) $open[0]['action'], ';cid=' . $cid . ';') && str_contains((string) $open[0]['action'], ';ask=Nice inn you have here'),
        json_encode($open));
    $t->must('the bridging directive rides this turn only, <= 220 chars, and no "list is on screen" wording',
        (bool) preg_match('/The list of what [^\n]{0,200}real answer follows from it\./', $q['volatile'], $bm) && strlen($bm[0]) <= 220
        && !str_contains($q['volatile'], 'on screen'), fx_short($q['volatile'], 400));
    $t->must('no lrg_dlgtalk "again" turn is ever needed', fxDlgTalk($npc) === 'handled');
    // 2. the list arrives with the stamped open's cid; the fast path answers from his words
    $GLOBALS['LAST_LLM_RESPONSE'] = ['action' => '', 'item' => '', 'message' => 'Aye, a few.'];
    $t->must('before the list lands her line plays (the late case: nothing to mute yet)',
        lrgDlgTransformer('Aye, a few.') === 'Aye, a few.');
    fxDlgClearQueue();
    $ent = [];
    $i = 0;
    foreach (array_keys($root) as $tx) { $ent[] = fxDlgEntry($i++, (string) $tx); }
    $r = fxDlgTopics($npc, ['want' => 1, 'cid' => $cid, 'layer' => 0, 'gen' => 0, 'ask' => 'Nice inn you have here'], $ent);
    $t->must('the fast path picks his own line on the list (mode intent, D1 + D2)', str_contains($r['echo'], ';do=pick;')
        && str_contains($r['echo'], ';pos=2;'), fx_short($r['echo'], 200));
    // 3. the mute: her bridging sentence streaming AFTER the fast pick landed is muted (the fresh last_exec read)
    $t->must('the muted-in-time case: a sentence after the fast pick landed is muted', lrgDlgTransformer('Aye, a few.') === '');
    // 4. the model's own key on this open turn is DROPPED (the fast pick for this sentence already landed)
    $w = fxDlgLlm($npc, 'T1', 'Aye, a few.');
    $t->must('the reply\'s pick is dropped: the fast pick for this sentence already landed', $w === [], json_encode(array_map('fxParam', $w)));
    // 5. WARM (root cached by an E-press): clause 3 fires first; the reply's key is HELD until the list has answered
    fxDlgEvent($npc, 'closed', ['sid' => 's1', 'why' => 'goodbye', 'pending' => 0, 'layer' => 0]);
    fxAdvance(40);
    $mark2 = fxDlgLogMark();
    $q2 = fxDlgSay($npc, $snap, $line);
    $t->must('warm: the cached root answers first (open marker=root)', !empty($q2['turn']['open_pending'])
        && str_contains(fxDlgLogFrom($mark2), 'open marker=root'), fx_short(fxDlgLogFrom($mark2), 300));
    $t->must('...and the list shows her CACHED root with the bridge (the state says it is being brought up)',
        str_contains($q2['volatile'], 'her list is being brought up') && str_contains($q2['volatile'], 'The list of what'), fx_short($q2['volatile'], 300));
    $w2 = fxDlgLlm($npc, 'T3', 'Aye.');
    $t->must('the model\'s key on the open turn is HELD until the list answers (F15)', $w2 === []
        && str_contains(fxDlgLogFrom($mark2), 'held - the open for this sentence decides'), json_encode(array_map('fxParam', $w2)));
    // 6. "what have you got?" to a vendor: a KIND open; CHIM's OpenInventory is off the table and never appended beside it
    fxAdvance(40);
    lrgDlgPut($npc, ['open_pending' => null, 'root' => null]);
    $q3 = fxDlgSay($npc, $snap, 'what have you got?', 'inputtext', array_merge(fxEnabledDefault(), [FX_DLG_ACT, 'OpenInventory', 'RentRoom']));
    $t->must('"what have you got?" opens her menu by kind (clause kind), and OpenInventory / RentRoom are hidden this turn',
        !empty($q3['turn']['open_pending']) && !in_array('OpenInventory', $q3['enabled'], true) && !in_array('RentRoom', $q3['enabled'], true),
        implode(',', $q3['enabled']));
    $GLOBALS['LAST_LLM_RESPONSE'] = ['action' => 'TradeItems', 'item' => '', 'target' => $npc, 'message' => 'Take a look.'];
    $w3 = array_values((array) lrgDlgPostProcessActions([$npc . '|command|OpenInventory@Flowtest Player' . "\r\n"]));
    $t->must('...a model-chosen OpenInventory is DROPPED and the barter net appends nothing (the real entry is about to win)',
        !array_filter($w3, static fn($l) => stripos((string) $l, 'OpenInventory') !== false), json_encode($w3));
    // 7. an OPEN session: no pre-LLM open at all
    fxDlgTopics($npc, ['layer' => 0, 'gen' => 3, 'sid' => 's3'], $ent);
    fxDlgClearQueue();
    $q4 = fxDlgSay($npc, $snap, $line);
    $t->must('an OPEN session is never opened again', empty($q4['turn']['open_pending']) && fxDlgQueue() === [], json_encode(fxDlgQueue()));
    // 8. the game refused the last open (S2.3): the next turn does not try again, and the direct-barter net comes back
    fxDlgEvent($npc, 'closed', ['sid' => 's3', 'why' => 'goodbye', 'pending' => 0, 'layer' => 0]);
    lrgDlgPut($npc, ['open_refused' => ['why' => 'a quest scene is running', 'at' => fxNow()], 'root' => null]);
    fxDlgClearQueue();
    $q5 = fxDlgSay($npc, $snap, 'what have you got?');
    $t->must('open_refused within 120 s: no pre-LLM open, direct barter again', empty($q5['turn']['open_pending']) && fxDlgQueue() === []
        && !empty($q5['turn']['svc']['direct']), json_encode($q5['turn']['svc'] ?? []));
});
