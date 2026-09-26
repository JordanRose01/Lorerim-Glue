<?php
// PHASE 2 d66v - [pt19 v1.0 / spec 2.2 Lane E, 2.3] THE VISIBLE MENU, VOICE-DRIVEN: where the server gate (Lane A) and the game
// driver (Lane C) first meet offline - through the wire contract of S8 (ev=open sj/sq/sqj/drv, lrg_topics sj/drv, ev=result
// auto=1). The list stays on his screen; when his words name an entry the glue clicks it; nothing is hidden, parked or timed out.
// (Scenario id d66v: d66 is 10.27's purchase scenario, d66_purchase.php.) Every sentence below is the PLAYER's (search input) or
// a line of the prompt index; the rows mirror the live index rows they are named after.
require_once __DIR__ . '/../dlg_adapter.php';

/** A list on his screen: rows [text => index overrides], the '*install*' clicks_ok, lrg_topics with the given keys. */
function fxVisList(string $npc, array $spec, array $topicKv = [], int $clicks = 1, array $snapOver = []): array
{
    fxDlgReset(fxDlgIndexFrom($spec));
    lrgDlgPut('*install*', ['clicks_ok' => $clicks]);
    fxSendSnapshot($npc, fxDlgSnap($snapOver));
    $ent = [];
    $i = 0;
    foreach ($spec as $text => $over) { $ent[] = fxDlgEntry($i++, is_int($text) ? (string) $over : (string) $text); }
    return ['ent' => $ent, 'r' => fxDlgTopics($npc, $topicKv + ['layer' => 0, 'gen' => 1], $ent)];
}

/** The key the turn offered for a text, or ''. */
function fxVisKey(array $q, string $text): string
{
    foreach ((array) ($q['keys'] ?? []) as $k => $v) { if ((string) $v['text'] === $text || str_starts_with((string) $v['text'], $text)) { return (string) $k; } }
    return '';
}

fx_scenario('d66v', '[pt19 v1.0 / S1, S3, S8] the visible menu, voice-driven: the explicit Kodlak turn is MUTED (the click is her answer), the stage-rail turn is SPOKEN (nothing clicked), an ambient actor (sj=0) is driven, a journal scene (sj=1) is read-only at clicks_ok 0 and driven at 1, first-contact Kodlak with his sentence 6 s before the list is explicit, drv=0 stays read-only across lists (F1), ev=result auto=1 proves the route, "come with me" to a stranger opens no menu and the escort still goes', function (FxT $t) {
    $GLOBALS['LRG_DLG_TEST_CFG'] = ['session.stage_rail' => true];   // [v1.0.1] the rail ships OFF; this scenario tests the rail itself
    $kodlak = 'Kodlak Flowtest';
    $join = 'I would like to join the Companions.';
    // C00KodlakJoinUpStartTopic (skyrim.esm:0A3E7A): TL, scripted, goodbye -> a commit; two of his More to Say lines beside it
    $spec = [$join => ['toplevel' => 1, 'scripted' => 1, 'quest' => 'C00', 'journal' => 1, 'flags' => ['goodbye' => 1], 'topic' => 'C00KodlakJoinUpStartTopic'],
        'What weapons do you prefer?' => ['toplevel' => 1, 'quest' => 'ACFDialogueWhiterun'],
        'What do you think about Skjor?' => ['toplevel' => 1, 'quest' => 'ACFDialogueWhiterun']];

    // ---- 1. the explicit Kodlak turn at clicks_ok 1: his own plain sentence IS the confirmation, the pick goes out, her line is muted
    fxVisList($kodlak, $spec, ['origin' => 'glue'], 1);
    fxAdvance(2);
    $q = fxDlgSay($kodlak, null, 'I would like to join the Companions.');
    $key = fxVisKey($q, $join);
    $t->must('set-up: his join line is offered as a [commits] key', $key !== '' && str_contains((string) ($q['keys'][$key]['label'] ?? ''), 'commits'),
        json_encode($q['keys']));
    $GLOBALS['LAST_LLM_RESPONSE'] = ['action' => FX_DLG_DISPLAY, 'item' => $key, 'target' => $kodlak, 'message' => 'Then welcome, if you prove worthy.'];
    $t->must('WillEmit is TRUE on the explicit turn (the gate will click)', lrgDlgWillEmit((array) $q['turn'], $key));
    $t->mustCap('dlg_json', 'the transformer MUTES her line: the click itself is her answer (S1.3, the mute)',
        fn() => lrgDlgTransformer('Then welcome, if you prove worthy.') === '', 'her line was not muted');
    $w = fxDlgLlm($kodlak, $key, 'Then welcome, if you prove worthy.');
    $t->must('...and the gate emits ONE do=pick on the join line', count($w) === 1 && fxDlgDo($w[0]) === 'pick'
        && str_contains(fxParam($w[0]), 'txt=I would like to join the Companions'), json_encode(array_map('fxParam', $w)));

    // ---- 2. the stage-rail turn: clicks_ok 0, the same sentence - nothing is clicked, her line is SPOKEN, the rail line rides
    fxVisList($kodlak, $spec, ['origin' => 'glue'], 0);
    fxAdvance(2);
    $q = fxDlgSay($kodlak, null, 'I would like to join the Companions.');
    $key = fxVisKey($q, $join);
    $GLOBALS['LAST_LLM_RESPONSE'] = ['action' => FX_DLG_DISPLAY, 'item' => $key, 'target' => $kodlak, 'message' => 'Choose it yourself this once.'];
    $t->must('stage rail: WillEmit is FALSE (no click is verified on this install and the line is scripted)', !lrgDlgWillEmit((array) $q['turn'], $key));
    $t->mustCap('dlg_json', 'stage rail: her line is SPOKEN (not muted) - the player hears why nothing moved',
        fn() => lrgDlgTransformer('Choose it yourself this once.') === 'Choose it yourself this once.', 'her line was muted on a turn that clicks nothing');
    // (which of the two rail sentences is a never-false rule - capability map U7: his two More to Say lines are plain and pass the
    // rail, so "ask me something simple first" can come true here, and the sentence names exactly T2 and T3; the U7 variant is wrong)
    $t->must('stage rail: the one rail line is in <business>, naming exactly the keys that pass (T2, T3) - not the U7 variant',
        str_contains($q['volatile'], 'Until he has asked me something simple I can only pick T2, T3;')
        && str_contains($q['volatile'], 'ask me something simple first') && !str_contains($q['volatile'], 'I cannot pick any of these for you yet'),
        fx_short($q['volatile'], 500));
    $w = fxDlgLlm($kodlak, $key, 'Choose it yourself this once.');
    $t->must('stage rail: nothing is clicked', !array_filter($w, static fn($l) => in_array(fxDlgDo((string) $l), ['pick', 'leave'], true)),
        json_encode(array_map('fxParam', $w)));

    // ---- 3. an AMBIENT actor (her scene has no journal objective): sj=0 -> driven like a free-standing list
    $hulda = 'Hulda Flowtest';
    $chat = 'Nice inn you have here. Do you get many visitors?';
    $hspec = ['What have you got for sale?' => ['toplevel' => 1, 'scripted' => 1, 'quest' => 'DialogueGeneric'],
        $chat => ['toplevel' => 1, 'quest' => 'ACFDialogueWhiterun', 'topic' => 'ACFDialogueWhiterunHuldaBranchChatTopic', 'info_key' => 'moretosaywhiterun.esp:000940'],
        'Heard any rumors lately?' => ['toplevel' => 1, 'scripted' => 1, 'quest' => 'DarkBrotherhood']];
    fxDlgReset(fxDlgIndexFrom($hspec));
    lrgDlgPut('*install*', ['clicks_ok' => 1]);
    fxSendSnapshot($hulda, fxDlgSnap(['scene' => '1', 'fac' => 'JobInnkeeperFaction,TownWhiterunFaction']));
    fxDlgEvent($hulda, 'facts', ['q' => '-', 'sq' => 'DialogueWhiterunBanneredMareScene3', 'sqj' => '0']);
    fxDlgEvent($hulda, 'open', ['sid' => 'h1', 'origin' => 'engine', 'crit' => 0, 'scene' => 1, 'sj' => 0, 'sq' => 'DialogueWhiterunBanneredMareScene3', 'sqj' => 0, 'drv' => 1]);
    $hent = [];
    foreach (array_keys($hspec) as $i => $tx) { $hent[] = fxDlgEntry($i, (string) $tx); }
    fxDlgSay($hulda, null, 'nice inn you have here');
    fxAdvance(5);
    $r = fxDlgTopics($hulda, ['sid' => 'h1', 'gen' => 1, 'layer' => 0, 'origin' => 'engine', 'want' => 1, 'scene' => 1], $hent);
    $t->must('ambient scene: the session is sj=0 (no journal objective, no journal row in the index)', (int) (fxDlgStateOf($hulda)['session']['sj'] ?? -1) === 0,
        json_encode(array_intersect_key((array) (fxDlgStateOf($hulda)['session'] ?? []), array_flip(['sj', 'sq', 'sqj', 'drv', 'scene']))));
    $t->must('...and his words pick her line on the fast path (D1)', str_contains($r['echo'], ';do=pick;') && str_contains($r['echo'], ';pos=1;'), fx_short($r['echo'], 200));

    // ---- 4. a JOURNAL scene (sj=1): read-only at clicks_ok 0 (the route is unproven), driven at 1 - the same evening
    $iri = 'Irileth Flowtest';
    $news = 'I have news from Helgen. About the dragon attack.';
    $ispec = [$news => ['toplevel' => 0, 'scripted' => 1, 'quest' => 'MQ102', 'journal' => 1, 'flags' => ['goodbye' => 1], 'topic' => 'MQ102IrilethIntroA1'],
        'I have a message from General Tullius.' => ['toplevel' => 0, 'scripted' => 1, 'quest' => 'MQ102', 'journal' => 1, 'flags' => ['goodbye' => 1]]];
    foreach ([0, 1] as $clicks) {
        fxDlgReset(fxDlgIndexFrom($ispec), fxDlgLayer(array_keys($ispec)));
        lrgDlgPut('*install*', ['clicks_ok' => $clicks]);
        fxSendSnapshot($iri, fxDlgSnap(['scene' => '1', 'fac' => 'CrimeFactionWhiterun']));
        fxDlgEvent($iri, 'facts', ['q' => 'MQ102', 'sq' => 'MQ102', 'sqj' => '1']);
        fxDlgEvent($iri, 'open', ['sid' => 'i' . $clicks, 'origin' => 'engine', 'crit' => 0, 'scene' => 1, 'sj' => 1, 'sq' => 'MQ102', 'sqj' => 1, 'drv' => 1]);
        $ient = [fxDlgEntry(0, $news), fxDlgEntry(1, 'I have a message from General Tullius.')];
        fxDlgTopics($iri, ['sid' => 'i' . $clicks, 'gen' => 1, 'layer' => 1, 'origin' => 'engine', 'scene' => 1, 'sj' => 1, 'drv' => 1], $ient);
        fxAdvance(2);
        $q = fxDlgSay($iri, null, 'I have news from Helgen about the dragon attack');
        $key = fxVisKey($q, $news);
        $w = fxDlgLlm($iri, $key !== '' ? $key : 'T1', 'Then speak.');
        if ($clicks === 0) {
            $t->must('journal scene @clicks_ok 0: read-only - no key, nothing clicked, and her words say "choose it on the list yourself"',
                $q['keys'] === [] && $w === [] && str_contains($q['volatile'], 'choose it on the list yourself - I cannot pick for you here'),
                json_encode([$q['keys'], array_map('fxParam', $w)]) . ' ' . fx_short($q['volatile'], 300));
        } else {
            $t->must('journal scene @clicks_ok 1: driven - his plain sentence clicks the news line (explicit)', count($w) === 1 && fxDlgDo($w[0]) === 'pick'
                && str_contains(fxParam($w[0]), 'txt=I have news from Helgen'), json_encode(array_map('fxParam', $w)));
        }
    }

    // ---- 5. first-contact Kodlak: NO session - a fresh world (fxReset clears the flow database, where step 1-2's session was still
    // open; fxDlgReset alone does not). His sentence queues the pre-LLM open (model row 1: N0 -> OP, open_pending = his cid, 2.1),
    // then the list arrives 6 s later carrying that cid (utter.at < session.at, game R4) and the fast path answers it in mode
    // explicit - the open's own last_exec stamp does not block the pick (F8)
    // (his REAL name: the faction lane (10.26) knows Kodlak Whitemane as the Companions' recruiter - under a test name a cold
    // join sentence is a redirect and nothing opens)
    $kReal = 'Kodlak Whitemane';
    fxReset();
    fxDlgReset(fxDlgIndexFrom($spec));
    lrgDlgPut('*install*', ['clicks_ok' => 1]);
    fxSendSnapshot($kReal, fxDlgSnap(['fac' => 'CompanionsFaction']));
    fxDlgClearQueue();
    $t->must('first contact: no session is open for him (a fresh world)', !lrgDlgSessionOpen((array) (fxDlgStateOf($kReal)['session'] ?? [])),
        json_encode(fxDlgStateOf($kReal)['session'] ?? null));
    $mark = fxDlgLogMark();
    $q = fxDlgSay($kReal, null, 'I would like to join the Companions.');
    $cid = (string) ($q['turn']['cid'] ?? '');
    $opens = array_values(array_filter(fxDlgQueue(), static fn($x) => str_contains((string) ($x['action'] ?? ''), ';do=open;')));
    $pend = (array) (fxDlgStateOf($kReal)['open_pending'] ?? []);
    $t->must('first contact: his sentence queues exactly ONE do=open before the model (D2), and open_pending names his turn\'s cid',
        count($opens) === 1 && $cid !== '' && (string) ($pend['cid'] ?? '') === $cid && !empty($q['turn']['open_pending']),
        json_encode(['opens' => array_map(static fn($x) => fxParam((string) $x['action']), $opens), 'cid' => $cid, 'open_pending' => $pend]) . ' | ' . fx_short(fxDlgLogFrom($mark), 300));
    fxAdvance(6);
    fxDlgClearQueue();
    $ent = [];
    foreach (array_keys($spec) as $i => $tx) { $ent[] = fxDlgEntry($i, (string) $tx); }
    $r = fxDlgTopics($kReal, ['sid' => 'k9', 'gen' => 0, 'layer' => 0, 'origin' => 'glue', 'want' => 1, 'cid' => $cid], $ent);
    $log = fxDlgLogFrom($mark);
    $t->must('first contact: the list arrives 6 s after his sentence and the fast path picks the join line (D1)',
        str_contains($r['echo'], ';do=pick;') && str_contains($r['echo'], 'txt=I would like to join the Companions'), fx_short($r['echo'], 220) . ' | ' . fx_short($log, 300));
    $t->must('...in mode explicit: his own plain sentence confirms a commit', (bool) preg_match('/emit npc=Kodlak Whitemane do=pick mode=explicit pos=0/', $log), fx_short($log, 400));

    // ---- 6. the wire: drv=0 keeps a session read-only across its lists (model F1); ev=result auto=1 proves the route
    fxDlgReset(fxDlgIndexFrom($spec));
    lrgDlgPut('*install*', ['clicks_ok' => 1]);
    fxSendSnapshot($kodlak, fxDlgSnap());
    fxDlgEvent($kodlak, 'open', ['sid' => 'v1', 'origin' => 'engine', 'crit' => 0, 'scene' => 0, 'sj' => 0, 'drv' => 0]);
    fxDlgTopics($kodlak, ['sid' => 'v1', 'gen' => 1, 'layer' => 0, 'origin' => 'engine'], $ent);   // no drv= on this list (an older game)
    fxAdvance(2);
    $q = fxDlgSay($kodlak, null, 'I would like to join the Companions.');
    $w = fxDlgLlm($kodlak, 'T1', 'Pick it yourself.');
    $t->must('drv=0 (the game does not drive this session): a list WITHOUT drv= stays read-only - no key, nothing clicked',
        $q['keys'] === [] && $w === [] && (string) ($q['turn']['ro'] ?? '') === 'vis', json_encode([$q['turn']['ro'] ?? null, array_map('fxParam', $w)]));
    lrgDlgPut('*install*', ['clicks_ok' => 0]);
    fxDlgEvent($kodlak, 'result', ['sid' => 'v1', 'gen' => 1, 'cid' => 'r01', 'x' => 'abcdef0123', 'pos' => 1, 'i' => 101, 'kind' => 'plain', 'ok' => 1, 'auto' => 1], 'What weapons do you prefer?');
    $t->must('ev=result auto=1 (a verified click) raises clicks_ok: the route is proven on this install (S3)', lrgDlgClicksOk() >= 1, (string) lrgDlgClicksOk());

    // ---- 7. capability map U1: "come with me" to a stranger on a stage - no menu opens, and the escort still goes out
    $lis = 'Lisette Flowtest';
    fxDlgReset();
    lrgDlgPut('*install*', ['clicks_ok' => 1]);
    $stage = fxSnap(['fac' => 'JobBardFaction,BardSingerFaction', 'scene' => '1', 'wit' => '4', 'sess' => '6083']);
    fxSendSnapshot($lis, $stage);
    fxDlgClearQueue();
    $r = fxHookTurn($lis, 'inputtext', 'come with me', array_merge(fxEnabledDefault(), ['FollowPlayer', FX_DLG_ACT]));
    $opens = array_values(array_filter(fxDlgQueue(), static fn($x) => str_contains((string) ($x['action'] ?? ''), ';do=open;')));
    $t->must('"come with me" to a stranger (no fol=): NO pre-LLM open - the escort owns the sentence (10.20)', $opens === [], json_encode(fxDlgQueue()));
    $w = fxHookLlm($r, []);
    $t->must('...and ExtCmdLRG_Escort is still queued (her reply named no movement)', (bool) array_filter($w, static fn($l) => fxCode((string) $l) === 'ExtCmdLRG_Escort'),
        json_encode(array_map('trim', $w)));
});
