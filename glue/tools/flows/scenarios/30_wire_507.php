<?php
// 30 - [0.5.5 + game script 507] NEVER SILENT on the wire the game really sends now.
//
// Script 507 marks every result: "OK: <neutral>" for a success, "Error: <her words>" for a failure, with the
// TECHNICAL reason (PROTOCOL 1.6's closed list) appended to field 2 as err=<reason>, and late=1 on a second
// funcret for a command that was already answered OK: and came undone (the escort's scene came back, an after=
// position that cannot follow a start). Flow 29 plays the <= 506 shape; this one plays the 507 shape through
// the same real hook files (fxFuncretTurn: preprocessing -> prompts -> functions -> context_pre) and checks that
// the server classifies on err=, never on her words: the hard rails stay quiet, the machinery never reaches her
// mouth, a late failure is spoken once and only once, and her next-turn note carries her words.
// Every sentence below is the PLAYER's (search input) or the game's result text, never a line an NPC says.

/** A wire line with game-appended keys (err= / late=) after the echoed parameter. */
function fx30_with(string $line, string $extra): string { return rtrim($line, "\r\n") . $extra; }

function fx30_escort(array $w): ?string
{
    foreach ($w as $l) { if (fxCode((string) $l) === 'ExtCmdLRG_Escort') { return (string) $l; } }
    return null;
}

fx_scenario('30', 'game 507 wire: OK: / Error: <her words> + err=<technical> + late=1 - the server classifies on err=, the hard rails stay quiet, the machinery never reaches her mouth, a late failure is spoken exactly once', function (FxT $t) {
    // ---------------------------------------------------------------- 1. escort refused: a quest in the journal (507 shape)
    $npc = 'Lisette Flowtest';
    $opt = ['enabled' => array_merge(fxEnabledDefault(), ['FollowPlayer'])];
    $stage = fxSnap(['fac' => 'JobBardFaction,BardSingerFaction', 'scene' => '1', 'wit' => '4', 'sess' => '6083']);
    fxSay($npc, $stage, 'follow me', 'inputtext', $opt);
    $esc = fx30_escort(fxLlmLines([fxLine($npc, 'FollowPlayer', '')]));
    $t->must('set-up: the escort line went out', $esc !== null);
    $v = fxFuncretTurn($npc, fx30_with((string) $esc, ';err=Lisette Flowtest is in the middle of something she cannot leave (a quest in your journal)'),
        'Error: Lisette Flowtest is in the middle of something for your quest and cannot leave');
    $t->must('a refusal in the 507 shape is VOICED: passed on to CHIM\'s funcret turn, mode voiced',
        !$v->handled && !$v->ended && $v->mode() === 'voiced', $v->summary());
    $t->must('the cue is built from err= (the technical reason): something important she cannot walk away from - never "quest" or "journal"',
        str_contains($v->cue, 'Lisette Flowtest could not come along with Flowtest Player - she is in the middle of something important that she cannot walk away from')
        && stripos($v->cue, 'quest') === false && stripos($v->cue, 'journal') === false, fx_short($v->cue, 500));
    $t->must('CHIM\'s tool call: a plain token and the plain fact - no err=, no k=v, no pattern list',
        str_starts_with($v->funcret, 'command@ExtCmdLRG_Escort@follow@Error: ') && !str_contains($v->funcret, 'err=') && !str_contains($v->funcret, 'safe='), $v->funcret);
    $t->mustCap('memory', 'last_result keeps the technical reason (every server match is on it) and her words apart',
        fn() => (fxMem($npc)['last_result']['reason'] ?? '') === 'Lisette Flowtest is in the middle of something she cannot leave (a quest in your journal)'
            && (fxMem($npc)['last_result']['say'] ?? '') === 'Lisette Flowtest is in the middle of something for your quest and cannot leave'
            && (fxMem($npc)['last_result']['told'] ?? null) === true,
        fn() => json_encode(fxMem($npc)['last_result'] ?? null));

    // ---------------------------------------------------------------- 2. escort success "OK: ..." and its LATE failure (late=1)
    fxAdvance(20);
    fxSay($npc, $stage, 'come with me', 'inputtext', $opt);
    $esc = (string) fx30_escort(fxLlmLines([fxLine($npc, 'FollowPlayer', '')]));
    $ok = fxFuncretTurn($npc, $esc, 'OK: Lisette Flowtest stops what she was doing and comes with you.');
    $t->must('"OK: ..." is a QUIET success: ended before the lock, nothing voiced', $ok->handled && $ok->cue === '' && fxVoiced() === null);
    fxAdvance(7);
    $late = fxFuncretTurn($npc, fx30_with($esc, ';late=1;err=she went back to her scene (BardSongs) and stays - it keeps starting again'),
        'Error: Lisette Flowtest had to go back to her performance');
    $t->must('the LATE failure (same cid, late=1) is VOICED: she says why herself, a few seconds after "comes with you"',
        !$late->handled && $late->mode() === 'voiced', $late->summary());
    $t->must('... in plain words: she keeps being pulled back into it - never the quest\'s EditorID',
        str_contains($late->cue, 'could not come along with Flowtest Player') && str_contains($late->cue, 'keeps being pulled back into it')
        && !str_contains($late->cue, 'BardSongs') && !str_contains($late->cue, 'went back to her scene'), fx_short($late->cue, 500));
    // the same failure also reaches the server as the game's log line (bDebugLog on): it must not be told again
    fxGameMessage(['lrg_log', fxNow(), 100000, 'cid=' . (fxParamKv($esc)['cid'] ?? '') . ';msg=escort ' . $npc . ': she went back to her scene (BardSongs) and stays - it keeps starting again']);
    $t->mustCap('memory', 'the log line after a voiced late failure leaves no `missed` note behind', fn() => (fxMem($npc)['missed'] ?? null) === null,
        fn() => json_encode(fxMem($npc)['missed'] ?? null));
    fxAdvance(10);
    $next = fxSay($npc, $stage, 'hello?', 'inputtext', $opt);
    $t->must('... and her next turn does not repeat it (no "did not manage", no "did not happen")',
        !str_contains($next->volatile, 'did not manage') && !str_contains($next->volatile, 'did not happen'), fx_short($next->volatile, 400));

    // the other order: the log line first (missed), then the late funcret - spoken once, the note is cleared
    fxAdvance(20);
    fxSay($npc, $stage, 'come with me', 'inputtext', $opt);
    $esc = (string) fx30_escort(fxLlmLines([fxLine($npc, 'FollowPlayer', '')]));
    fxFuncretTurn($npc, $esc, 'OK: Lisette Flowtest stops what she was doing and comes with you.');
    fxAdvance(7);
    fxGameMessage(['lrg_log', fxNow(), 100000, 'cid=x;msg=escort ' . $npc . ': she went back to her scene (BardSongs) and stays - it keeps starting again']);
    $t->mustCap('memory', 'set-up: the log line alone leaves a `missed` note', fn() => is_array(fxMem($npc)['missed'] ?? null));
    $late = fxFuncretTurn($npc, fx30_with($esc, ';late=1;err=she went back to her scene (BardSongs) and stays - it keeps starting again'),
        'Error: Lisette Flowtest had to go back to her performance');
    $t->must('log line first, late funcret second: still VOICED once', $late->mode() === 'voiced');
    $t->mustCap('memory', '... and the `missed` note is gone, so the next turn does not say it again', fn() => (fxMem($npc)['missed'] ?? null) === null,
        fn() => json_encode(fxMem($npc)['missed'] ?? null));

    // a late failure that is NOT voiced (another failure was spoken 2 s earlier): told on her next turn ONCE, one note
    fxAdvance(20);
    fxSay($npc, $stage, 'come with me', 'inputtext', $opt);
    $esc = (string) fx30_escort(fxLlmLines([fxLine($npc, 'FollowPlayer', '')]));
    fxFuncretTurn($npc, fx30_with($esc, ';err=Lisette Flowtest will not do that now (combat)'), 'Error: Lisette Flowtest cannot, not in the middle of a fight');
    fxAdvance(2);
    $q = fxFuncretTurn($npc, fx30_with($esc, ';late=1;err=she went back to her scene (BardSongs) and stays - it keeps starting again'),
        'Error: Lisette Flowtest had to go back to her performance');
    $t->must('set-up: a second failure inside voice.min_gap_seconds is quiet (ended before the lock)', $q->handled && fxVoiced() === null);
    fxGameMessage(['lrg_log', fxNow(), 100000, 'cid=x;msg=escort ' . $npc . ': she went back to her scene (BardSongs) and stays - it keeps starting again']);
    fxAdvance(3);
    $next = fxSay($npc, $stage, 'hello?', 'inputtext', $opt);
    $t->must('her next turn carries ONE note about it, not two (the `missed` note; the last-result note steps aside)',
        substr_count($next->volatile, 'did not manage to come along') === 1 && !str_contains($next->volatile, 'last tried did not happen'), fx_short($next->volatile, 600));

    // ---------------------------------------------------------------- 3. the hard rails stay quiet in the 507 shape
    $c = fxCast('innkeeper'); $n2 = $c['name'];
    $alone = fxSnap($c['snap']);
    fxSetScore($n2, $alone, 10);
    fxWarm($n2, $alone);
    fxSay($n2, $alone, 'Take off your clothes.');
    $w = fxLlm($n2, FX_ACT_CLOTHING, 'undress');
    $a = fxFuncretTurn($n2, fx30_with($w[0] ?? '', ';err=adults only'), 'Error: no, I will not do that');
    $t->must('"adults only" arrives as her words + err=adults only: NOT voiced (the rail is read from err=, not from her words)', $a->handled && fxVoiced() === null);
    fxAdvance(10);
    fxSay($n2, $alone, 'Take off your clothes.');
    $w = fxLlm($n2, FX_ACT_CLOTHING, 'undress');
    $d = fxFuncretTurn($n2, fx30_with($w[0] ?? '', ';err=dry-run mode, nothing changed'), 'Error: I cannot do that right now');
    // [pt17 / owner addendum 11] a dry run in the 507 shape IS voiced now: the technical err= is read, the switch and the
    // page are named (2026-09-23: seven refusals under the developer switch, not a word about why)
    $t->must('a dry run in the 507 shape: VOICED, from err= - the cue names the switch and the page',
        !$d->handled && $d->mode() === 'voiced' && str_contains($d->cue, 'dry-run switch') && str_contains($d->cue, 'Diagnostics'), fx_short($d->cue, 300));
    fxAdvance(10);
    fxSay($n2, $alone, 'Take off your clothes.');
    $w = fxLlm($n2, FX_ACT_CLOTHING, 'undress');
    $n = fxFuncretTurn($n2, fx30_with($w[0] ?? '', ';err=not possible in this position'), 'Error: I have nothing left to take off');
    $t->must('clothes that did not change (507: "nothing left to take off"): VOICED with the true reason, not "not possible in this position"',
        $n->mode() === 'voiced' && str_contains($n->cue, "Brenna Flowtest's clothes did not come off - there was nothing left to take off"), fx_short($n->cue, 400));

    // ---------------------------------------------------------------- 4. the machinery never reaches her mouth
    fxAdvance(10);
    $startLine = fxLine($n2, 'ExtCmdLRG_StartIntimacy', 'ok=1;cid=s30w;npc=' . $n2 . ';scene=X;undress=1;furn=;fscene=;maxwit=0;folok=0;wait=end');
    $wp = fxFuncretTurn($n2, fx30_with($startLine, ";err=OStim's 'Remove Weapons at Start' is on and would crash the game - turn it off in OStim's MCM (Undressing page)"),
        'Error: I cannot, not right now');
    $t->must('OStim\'s weapon setting refused a start: VOICED as "it just would not work right now" - no OStim, no MCM, no crash',
        $wp->mode() === 'voiced' && str_contains($wp->cue, 'it just would not work right now')
        && stripos($wp->cue, 'ostim') === false && stripos($wp->cue, 'mcm') === false && stripos($wp->cue, 'crash') === false, fx_short($wp->cue, 400));
    fxAdvance(10);
    $pay = fxFuncretTurn($n2, fx30_with($startLine, ';err=the player does not have that much gold'), 'Error: you do not have that much gold');
    $t->must('a paid start the purse cannot cover: VOICED, he cannot pay what was agreed', $pay->mode() === 'voiced'
        && str_contains($pay->cue, 'nothing began between Brenna Flowtest and Flowtest Player - Flowtest Player cannot pay what was agreed'), fx_short($pay->cue, 400));
    fxAdvance(10);
    $af = fxFuncretTurn($n2, fx30_with($startLine, ';late=1;err=that position cannot be reached from here'), 'Error: I cannot get into that from here');
    $t->must('the start\'s LATE after= failure: the scene did begin - "the change of position did not happen", never "nothing began"',
        $af->mode() === 'voiced' && str_contains($af->cue, 'the change of position did not happen - that position cannot be reached from here')
        && !str_contains($af->cue, 'nothing began'), fx_short($af->cue, 400));

    // ---------------------------------------------------------------- 5. inside a scene: "no scene is running" still closes the row
    fxReset();
    fxSetScore($n2, $alone, 10);
    $sc = fxBeginScene($t, $n2, $alone);
    $t->must('set-up: the scene row is live', fxSceneActive($n2));
    $r = fxSay($n2, $sc['snap'], 'faster');
    $w = fxReal(fxLlm($n2, FX_ACT_CONTROL, 'faster'));
    $t->must('set-up: a pace change went out', count($w) === 1 && fxDo($w[0]) === 'faster', json_encode(array_map('trim', $w)));
    $fast = fxFuncretTurn($n2, $w[0] ?? '', 'OK: The pace changes.');
    $t->must('"faster" answered "OK: The pace changes.": QUIET, exactly as before', $fast->handled && fxVoiced() === null);
    fxSay($n2, $sc['snap'], 'faster');
    $w = fxReal(fxLlm($n2, FX_ACT_CONTROL, 'faster'));
    $ns = fxFuncretTurn($n2, fx30_with($w[0] ?? '', ';err=no scene is running'), 'Error: we are not doing anything right now');
    $t->must('"no scene is running" read from err=: the scene row is closed at once (G4), and it is voiced',
        !fxSceneActive($n2) && $ns->mode() === 'voiced', 'active=' . var_export(fxSceneActive($n2), true) . ' ' . $ns->summary());

    // ---------------------------------------------------------------- 6. her own lead move: quiet, then told next turn in HER words
    fxReset();
    fxSetScore($n2, $alone, 10);
    $sc = fxBeginScene($t, $n2, $alone);
    fxAdvance(200);
    $lead = fxLeadTick($n2, $sc['snap']);
    $wl = fxReal(fxLlm($n2, FX_ACT_CONTROL, (string) array_key_first($lead->options())));
    $t->must('set-up: her own goto went out', count($wl) === 1 && fxDo($wl[0]) === 'goto', json_encode(array_map('trim', $wl)));
    $q = fxFuncretTurn($n2, fx30_with($wl[0] ?? '', ';err=that position cannot be reached from here'), 'Error: I cannot get into that from here');
    $t->must('her own lead move that failed: quiet (nobody was waiting on it)', $q->handled && fxVoiced() === null);
    $told = fxSay($n2, $sc['snap'], 'Hello.');
    $t->must('... told on her next player turn, once, in the words the game chose for her',
        str_contains($told->volatile, 'did not happen (I cannot get into that from here)'), fx_short($told->volatile, 500));
});
