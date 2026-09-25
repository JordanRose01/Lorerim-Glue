<?php
// PHASE 2 d65 - [pt18-quest] THE ROAD, AND THE CLICK-FREE QUEST ENTRY (research/pt18-quest.md, PROTOCOL 10.26).
//
// Playtest 2026-09-23 23:15-23:21, the owner's words: "they were understanding we were doing the quest but the quest
// wasnt showing up in my quests and it wasnt advancing the quest". Three enlistment asks to Legate Rikke and General
// Tullius were words only by construction (ml=0, an ambient map-table scene the game never opens on, no model item), and
// the truth the refuters established closes the road anyway: on this Alternate Perspective save MQ101 (Helgen) is at
// stage 0, AP pins GLOB MQQuickstart at 7.0 and its winning Tullius greet needs < 7 - every line of his that sets CW00A
// stage 10 is unreachable until Helgen has been played. This scenario replays the evening end to end through the real
// hook files and then plays the road AFTER Helgen: the AP join line's stage (206783 = SetStage 10) goes out as ONE
// ExtCmdLRG_QuestEntry line (D1 only), the game's OK is VOICED (no licence was given pre-LLM), a refusal is voiced in
// her words, the developer dry run names its page. Every sentence below is the PLAYER's (search input) or the GAME's.
require_once __DIR__ . '/../dlg_adapter.php';

fx_scenario('d65', '[pt18-quest] the road runs through Helgen first (nothing sent, the Helgen line, Rikke never sends him to a dead end); after Helgen the AP join line\'s stage goes out once as ExtCmdLRG_QuestEntry, the game\'s OK is voiced, a refusal is voiced in her words, the dry run names its page', function (FxT $t) {
    $tullius = 'General Tullius';
    $rikke = 'Legate Rikke';
    fxDlgReset();
    $GLOBALS['LRG_DLG_MCM'] = ['ml' => 0, 'lf' => 1, 'cal' => 0];
    // both live in the map-table scene: snapshot scene=1, facts sq=CW00SolitudeMapTableScene sqj=0 -> ambient (on=1, no open)
    $snapT = fxDlgSnap(['fac' => 'CWImperialFaction,CWFieldCOFaction,CWDialogueSoldierFaction', 'class' => '', 'scene' => '1']);
    $snapR = fxDlgSnap(['fac' => 'CWImperialFaction,CWFieldCOFaction,CWDialogueSoldierFaction', 'class' => '', 'scene' => '1']);
    $amb = ['dlg' => '0', 'cal' => '0', 'sq' => 'CW00SolitudeMapTableScene', 'sqj' => '0'];
    $blockOf = static function (?array $turn): string { return is_array($turn) ? (string) lrgDlgLockedBlock($turn) : ''; };

    // ---------------------------------------------------------------- 1. 23:19:13 Rikke, before any Tullius facts line: the road is UNKNOWN
    fxSendSnapshot($rikke, $snapR);
    fxDlgEvent($rikke, 'facts', ['q' => 'CW00A,CW', 'qst' => 'CW00A:0,CW:0'] + $amb);
    $r = fxDlgSay($rikke, $snapR, "hi um i'm looking to join the legion");
    $f = (array) ($r['turn']['faction'] ?? []);
    $t->must('Rikke, road unknown: recruiter, ambient, no plan, nothing queued', ($f['role'] ?? '') === 'recruiter' && !empty($r['turn']['ambient'])
        && (($f['road']['state'] ?? '') === 'unknown') && ($f['plan'] ?? ['x']) === [] && fxDlgQueue() === [], json_encode($f));
    $b = $blockOf($r['turn']);
    $t->must('...her block keeps "General Tullius first, at the map table" but adds that Helgen comes before all of it, unconfirmed',
        str_contains($b, 'General Tullius first, at the map table in Castle Dour') && str_contains($b, 'Helgen comes before all of it') && str_contains($b, 'has not told you whether that is behind him'), fx_short($b, 700));
    $t->must('...the model only talked: nothing reaches the game from either gate', fxDlgLlmNothing($rikke, 'General Tullius first, at the map table.') === [] && lrgPostProcessActions([]) === []);

    // ---------------------------------------------------------------- 2. 23:19:24-29 Tullius: his facts line carries MQ101:0 -> the road is CLOSED
    fxSendSnapshot($tullius, $snapT);
    fxDlgEvent($tullius, 'facts', ['q' => 'CW00A,CWObj,CWReservations,CW,aaThalmor,MQ101', 'qst' => 'CW00A:0,CWObj:0,CWReservations:0,CW:0,aaThalmor:1,MQ101:0'] + $amb);
    $r = fxDlgSay($tullius, $snapT, "okay i guess you're the guy i talked to to join the legion");
    $f = (array) ($r['turn']['faction'] ?? []);
    $t->must('Tullius: the road is CLOSED from his own facts line (qst MQ101:0), the plan says closed, no executed flag',
        ($f['road']['state'] ?? '') === 'closed' && str_contains((string) ($f['road']['src'] ?? ''), 'qst') && ($f['plan']['state'] ?? '') === 'closed' && ($f['executed'] ?? ['x']) === [], json_encode($f));
    $b = $blockOf($r['turn']);
    $t->must('...his block is the Helgen line: the road runs through Helgen first, no line of his opens before it, nothing recorded, Helgen first',
        str_contains($b, "the Legion's road runs through Helgen first") && str_contains($b, 'until Helgen is behind him') && str_contains($b, 'tell him so plainly in one line (Helgen first)'), fx_short($b, 700));
    $t->must('...and no "settled in your own dialogue", no oath, no map table; the rule is still there',
        !str_contains($b, 'own dialogue') && !str_contains($b, 'map table') && str_contains($b, 'Never invent an appointment'), fx_short($b, 700));
    $t->must('...the turn line: faction=legion:recruiter:road=closed:qe=closed', str_contains(lrgDlgTurnLine($r['turn']), ' faction=legion:recruiter:road=closed:qe=closed'), lrgDlgTurnLine($r['turn']));
    $w = fxDlgLlmNothing($tullius, 'You want to join the Legion? Swear your oath here, or move on.');
    $w1 = lrgPostProcessActions($w);
    $t->must('...NOTHING reaches the game: no quest entry, no open, no D2 row (the model\'s own words are the never-false lane\'s)', $w1 === [] && fxDlgQueue() === [], json_encode($w1));
    // 23:19:47 the carried oath turn
    $r = fxDlgSay($tullius, $snapT, 'i swear to uphold the imperial vows');
    $f = (array) ($r['turn']['faction'] ?? []);
    $t->must('the oath turn 18 s later: carried, road closed, no plan, nothing sent', !empty($f['carried']) && ($f['road']['state'] ?? '') === 'closed' && ($f['plan'] ?? ['x']) === []
        && lrgPostProcessActions(fxDlgLlmNothing($tullius, 'Then you are a Legionnaire.')) === [], json_encode($f));
    $t->must('...its block is the Helgen line marked background', str_contains($blockOf($r['turn']), '(background to what he asked a moment ago') && str_contains($blockOf($r['turn']), 'Helgen first'), fx_short($blockOf($r['turn']), 600));

    // ---------------------------------------------------------------- 3. 23:20:06 Rikke again: Tullius's cached facts line closes her road too
    $r = fxDlgSay($rikke, $snapR, 'so can i join or not');
    $f = (array) ($r['turn']['faction'] ?? []);
    $b = $blockOf($r['turn']);
    $t->must('Rikke, 40 s later (carried): her road is closed through Tullius\'s cached line - she names Helgen, never "Tullius first"',
        ($f['road']['state'] ?? '') === 'closed' && str_starts_with((string) ($f['road']['src'] ?? ''), 'cache:General Tullius') && str_contains($b, 'Helgen first') && !str_contains($b, 'at the map table'), json_encode($f) . ' ' . fx_short($b, 500));
    $t->must('...nothing reaches the game', lrgPostProcessActions(fxDlgLlmNothing($rikke, 'Helgen first.')) === [] && fxDlgQueue() === []);

    // fxDlgReset() clears Phase 2's per-request cache, not the (in-memory) lrg_dialogue table: the rows this scenario
    // wrote for him (facask / facexec / exec_qst) are cleared by hand, as test_gates' $nfResetMem does
    $fresh = static function () use ($tullius, $rikke): void {
        fxDlgReset();
        $GLOBALS['LRG_DLG_MCM'] = ['ml' => 0, 'lf' => 1, 'cal' => 0];
        foreach ([$tullius, $rikke] as $who) { lrgDlgPut($who, ['exec_qst' => null, 'facexec' => null, 'facask' => null, 'facts' => null]); }
        fxMemSet($tullius, ['voiced_at' => null, 'qe_lic' => null]);
    };

    // ---------------------------------------------------------------- 4. AFTER HELGEN: MQ101 complete on a fresh facts line -> the AP line's stage goes out ONCE
    $fresh();
    fxSendSnapshot($tullius, $snapT);
    fxDlgEvent($tullius, 'facts', ['q' => 'CW00A,CW,MQ101', 'qst' => 'CW00A:0,CW:0,MQ101:900'] + $amb);
    $r = fxDlgSay($tullius, $snapT, 'i want to join the legion');
    $f = (array) ($r['turn']['faction'] ?? []);
    $t->must('after Helgen (qst MQ101:900 = complete): the road is open and the plan is QUEUED, unlicensed (CW00B not on the facts line)',
        ($f['road']['state'] ?? '') === 'open' && ($f['plan']['state'] ?? '') === 'queued' && empty($f['plan']['licensed']) && ($f['executed'] ?? ['x']) === [], json_encode($f['plan'] ?? null));
    $b = $blockOf($r['turn']);
    $t->must('...his block says the game is being asked this moment and has NOT confirmed it - do not say it is done',
        str_contains($b, 'is being asked this moment to record CW00A stage 10') && str_contains($b, 'has NOT confirmed it yet'), fx_short($b, 700));
    $w = lrgPostProcessActions(fxDlgLlmNothing($tullius, 'Very well. We shall see what you are made of.'));
    $t->must('ONE ExtCmdLRG_QuestEntry line goes out through Phase 1\'s gate after the model\'s own reply, and NO D2 row',
        count($w) === 1 && fxCode($w[0]) === 'ExtCmdLRG_QuestEntry' && fxDlgQueue() === [], json_encode(array_map('trim', $w)));
    $kv = $w ? fxParamKv($w[0]) : [];
    $t->must('...its param: ok=1;cid=<turn cid>;npc;quest=CW00A;stage=10;isid=78462;notdone=10,20;max=9;qdone=MQ101;qnd=CW00B:10;entry=CW00TulliusGreetTalkRikkateAP;hint;x;z=1',
        ($kv['cid'] ?? '') === (string) ($r['turn']['cid'] ?? '?') && ($kv['quest'] ?? '') === 'CW00A' && ($kv['stage'] ?? '') === '10' && ($kv['isid'] ?? '') === '78462'
        && ($kv['notdone'] ?? '') === '10,20' && ($kv['max'] ?? '') === '9' && ($kv['qdone'] ?? '') === 'MQ101' && ($kv['qnd'] ?? '') === 'CW00B:10'
        && ($kv['entry'] ?? '') === 'CW00TulliusGreetTalkRikkateAP' && str_contains((string) ($kv['hint'] ?? ''), 'Legate Rikke') && ($kv['z'] ?? '') === '1', fxParam($w[0] ?? ''));
    $t->must('...a second run of the gate in the same request adds nothing more', lrgPostProcessActions([]) === []);
    $t->must('...and a quest-entry line the MODEL emitted is dropped like any unoffered glue action', lrgPostProcessActions($w) === []);
    $fx = (array) (fxDlgStateOf($tullius)['facexec'] ?? []);
    $t->must('...facexec holds it as pending (unlicensed)', ($fx['quest'] ?? '') === 'CW00A' && (int) ($fx['stage'] ?? 0) === 10 && (int) ($fx['done'] ?? 1) === 0 && (int) ($fx['lic'] ?? 1) === 0, json_encode($fx));
    // a re-ask before the answer: pending, nothing sent again
    $r2 = fxDlgSay($tullius, $snapT, 'well, can i join the legion');
    $t->must('a re-ask before the game answered: pending, nothing sent again', (($r2['turn']['faction']['plan']['state'] ?? '') === 'pending')
        && lrgPostProcessActions(fxDlgLlmNothing($tullius, 'Patience.')) === [], json_encode($r2['turn']['faction']['plan'] ?? null));

    // ---------------------------------------------------------------- 5. the game's OK: VOICED (no licence was given pre-LLM), exec_qst written
    $fr = fxFuncretTurn($tullius, $w[0], 'OK: CW00A now at stage 10 (0 before): CW00A stage 10 - speak with Legate Rikke (no journal entry until her test)');
    $t->must('the OK funcret is NOT ended before the lock: it is the one glue success that is voiced', !$fr->handled && !$fr->ended, $fr->summary());
    $t->must('...the funcret CHIM carries on with is OK-shaped with the meaning', str_contains($fr->funcret, '@entry@OK: he is sent to Legate Rikke'), $fr->funcret);
    $t->must('...the cue says: ONE line, speak with Legate Rikke, no oath / rank / time / place, never a word about the game or stages',
        str_contains($fr->cue, 'ONE short line') && str_contains($fr->cue, 'speak with Legate Rikke') && str_contains($fr->cue, 'no oath, no rank, no time or place') && str_contains($fr->cue, 'never a word about the game, quests, stages'), fx_short($fr->cue, 500));
    $ex = (array) (fxDlgStateOf($tullius)['exec_qst'] ?? []);
    $t->must('...exec_qst {CW00A, 10} is in Phase 2\'s state for the never-false judge, facexec is done',
        ($ex['quest'] ?? '') === 'CW00A' && (int) ($ex['stage'] ?? 0) === 10 && (int) ((fxDlgStateOf($tullius)['facexec']['done'] ?? 0)) === 1, json_encode(fxDlgStateOf($tullius)));
    // a re-ask after the OK: already, nothing sent
    $r3 = fxDlgSay($tullius, $snapT, 'i want to join the legion');
    $b3 = $blockOf($r3['turn']);
    $t->must('a re-ask after the OK: already - "the game has already recorded CW00A stage 10", nothing sent',
        (($r3['turn']['faction']['plan']['state'] ?? '') === 'already') && str_contains($b3, 'has already recorded CW00A stage 10') && lrgPostProcessActions(fxDlgLlmNothing($tullius, 'Rikke. Go.')) === [], fx_short($b3, 500));

    // ---------------------------------------------------------------- 6. a refusal from the game (the road closed after all): voiced in her words, facexec cleared
    $fresh();
    fxSendSnapshot($tullius, $snapT);
    fxDlgEvent($tullius, 'facts', ['q' => 'CW00A,CW,MQ101', 'qst' => 'CW00A:0,CW:0,MQ101:900'] + $amb);
    fxDlgSay($tullius, $snapT, 'i want to join the legion');
    $w = lrgPostProcessActions(fxDlgLlmNothing($tullius, 'We shall see.'));
    $t->must('set-up: the line went out again in a fresh world', count($w) === 1 && fxCode($w[0]) === 'ExtCmdLRG_QuestEntry');
    // the voice gap (voice.min_gap_seconds, 8 s) would keep a second voiced turn in the same second quiet: this is a new exchange
    fxMemSet($tullius, ['voiced_at' => null]);
    $lineErr = str_replace(';z=1', ';z=1;err=CW00A waits on MQ101 being complete', (string) $w[0]);
    $fr = fxFuncretTurn($tullius, $lineErr, 'Error: that road is not open to you yet - there is something you must see through first');
    $t->must('the Helgen refusal is VOICED: not ended, the cue names Helgen and no quest id, and does not pretend it happened',
        !$fr->handled && !$fr->ended && str_contains($fr->cue, 'begins at Helgen') && !preg_match('/MQ101|CW00A/', $fr->cue) && str_contains($fr->cue, 'does not pretend it happened'), fx_short($fr->cue, 500));
    $t->must('...facexec is cleared, so a re-ask is not held as pending', empty(fxDlgStateOf($tullius)['facexec']), json_encode(fxDlgStateOf($tullius)['facexec'] ?? null));
    // the developer dry run: voiced, names the switch and the page
    $fresh();
    fxSendSnapshot($tullius, $snapT);
    fxDlgEvent($tullius, 'facts', ['q' => 'CW00A,CW,MQ101', 'qst' => 'CW00A:0,CW:0,MQ101:900'] + $amb);
    fxDlgSay($tullius, $snapT, 'i want to join the legion');
    $w = lrgPostProcessActions(fxDlgLlmNothing($tullius, 'We shall see.'));
    fxMemSet($tullius, ['voiced_at' => null]);
    $lineDry = str_replace(';z=1', ';z=1;err=dry-run mode is ON in the LoreRim Glue MCM (Diagnostics page) - the quest was not touched', (string) ($w[0] ?? ''));
    $fr = fxFuncretTurn($tullius, $lineDry, 'Error: I cannot - the LoreRim Glue dry-run switch is on in its settings, on the Diagnostics page');
    $t->must('the developer dry run refusal is voiced and names the Diagnostics page', !$fr->handled && !$fr->ended && str_contains($fr->cue, 'Diagnostics page'), fx_short($fr->cue, 400));
    unset($GLOBALS['LRG_DLG_MCM']);
});
