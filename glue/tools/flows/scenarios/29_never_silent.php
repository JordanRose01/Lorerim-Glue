<?php
// 29 - [0.5.5 / owner addendum 11] NEVER SILENT.
//
// "well if she's busy she should say that then and it will be fine, she never told me she was busy, she just
// didn't do anything." Until 0.5.4 every glue result ended in silence: the follow-up of every glue row was off,
// so a refused escort, clothes the game would not take off or an unreachable position were a corner note at
// best, and she said nothing until the player spoke again. This scenario plays each of those as the WHOLE
// request CHIM makes of the game's funcret (fxFuncretTurn: preprocessing -> prompts -> functions -> context_pre):
//   - a FAILURE the player was waiting on passes on to CHIM's own funcret turn, whose cue says plainly what did
//     not happen and why, and asks for ONE short line in her own voice - never the game, never pretending;
//   - a SUCCESS (and every result that must stay silent) ends before the lock, exactly as quiet as before;
//   - every recognised request's directive says "do it, or say why - never ignore it" (closed, public, blind);
//   - CHIM's own movement that visibly failed is told on her next turn.
// Every sentence below is the PLAYER's (search input) or the game's closed result list, never a line an NPC says.

/** The escort lines among what reached the game. */
function fx29_escort(array $w): ?string
{
    foreach ($w as $l) { if (fxCode((string) $l) === 'ExtCmdLRG_Escort') { return (string) $l; } }
    return null;
}

fx_scenario('29', 'owner addendum 11: a refused escort, clothes that would not come off and an unreachable position are SPOKEN in the same exchange (CHIM\'s own funcret turn), a success stays silent, and every request is answered - closed, public, blind - never ignored', function (FxT $t) {
    // ---------------------------------------------------------------- 1. the escort, refused on her stage (voiced)
    $npc = 'Lisette Flowtest';
    $opt = ['enabled' => array_merge(fxEnabledDefault(), ['FollowPlayer'])];
    $stage = fxSnap(['fac' => 'JobBardFaction,BardSingerFaction', 'scene' => '1', 'wit' => '4', 'sess' => '6083']);
    $r = fxSay($npc, $stage, 'okay so come on follow me now', 'inputtext', $opt);
    $d = $r->directive();
    $t->must('the follow directive: do it now, never ignore it, she may say she needs a moment - and she never makes up a reason to stay',
        str_contains($d, 'choose Follow_Flowtest Player') && str_contains($d, 'never ignore it') && str_contains($d, 'she needs a moment')
        && str_contains($d, 'Do not make up a reason to stay'), fx_short($d, 500));
    $esc = fx29_escort(fxLlmLines([fxLine($npc, 'FollowPlayer', '')]));
    $t->must('set-up: the escort line went out in front of CHIM\'s follow', $esc !== null);
    $v = fxFuncretTurn($npc, (string) $esc, 'Error: Lisette Flowtest is in the middle of something she cannot leave (not a performance or idle scene she may leave)');
    $t->must('the refusal is NOT ended before the lock: it goes on to CHIM\'s funcret turn', !$v->handled && !$v->ended, $v->funcret);
    $t->must('... which is a VOICED turn: she only talks, no glue action, no intimacy text', $v->mode() === 'voiced' && $v->glueOffered() === [] && $v->volatile === '' && $v->static === '',
        $v->summary() . ' volatile=' . strlen($v->volatile));
    $t->must('the cue says what happened, plainly: she could not come along - in the middle of a performance she cannot just leave',
        str_contains($v->cue, 'Lisette Flowtest could not come along with Flowtest Player') && str_contains($v->cue, 'in the middle of a performance that she cannot just leave'), fx_short($v->cue, 500));
    $t->must('... ONE short line in her own voice, the real reason, never the game, never pretending it happened',
        str_contains($v->cue, 'ONE short line') && str_contains($v->cue, "Lisette Flowtest's own voice") && str_contains($v->cue, 'never a word about the game, commands or errors')
        && str_contains($v->cue, 'does not pretend it happened'), fx_short($v->cue, 500));
    $t->must('one short line: the token cap is set, and CHIM\'s canned-refusal filter cannot swap her reason for its own line',
        $v->maxTokens === 140 && $v->refusalFilterOff, 'max=' . var_export($v->maxTokens, true) . ' filter_off=' . var_export($v->refusalFilterOff, true));
    $t->must('CHIM\'s tool call carries a plain token and the plain fact - not the k=v parameter, not "safe=" patterns',
        str_starts_with($v->funcret, 'command@ExtCmdLRG_Escort@follow@Error: Lisette Flowtest could not come along') && !str_contains($v->funcret, 'safe=') && !str_contains($v->funcret, 'cid='),
        $v->funcret);
    $t->mustCap('memory', 'it counts as told: her next turn does not repeat it', fn() => (fxMem($npc)['last_result']['told'] ?? null) === true, fn() => json_encode(fxMem($npc)['last_result'] ?? null));
    fxAdvance(12);
    $next = fxSay($npc, $stage, 'hello?', 'inputtext', $opt);
    $t->must('... and it does not', !str_contains($next->volatile, 'did not happen') && !str_contains($next->volatile, 'cannot leave'), fx_short($next->volatile, 300));
    $ok = fxFuncretTurn($npc, (string) $esc, 'Lisette Flowtest stops what she was doing and comes with you.');
    $t->must('an escort SUCCESS - even one that stopped her scene - ends before the lock: no funcret turn, no cue', $ok->handled && $ok->cue === '' && fxVoiced() === null);

    // ---------------------------------------------------------------- 2. clothes that would not come off (voiced), and a quiet success
    $c = fxCast('innkeeper'); $n2 = $c['name'];
    $alone = fxSnap($c['snap']);
    fxSetScore($n2, $alone, 10);
    fxWarm($n2, $alone);
    fxSay($n2, $alone, 'Take off your clothes.');
    $w = fxLlm($n2, FX_ACT_CLOTHING, 'undress');
    $t->must('set-up: ChangeClothing went out', count($w) === 1 && fxCode($w[0]) === FX_ACT_CLOTHING, json_encode(array_map('trim', $w)));
    $ok = fxFuncretTurn($n2, $w[0] ?? '', 'Brenna Flowtest undresses.');
    $t->must('QUIET SUCCESS: what the player asked for happened - ended before the lock, nothing voiced, no cue', $ok->handled && $ok->cue === '' && fxVoiced() === null);
    $t->mustCap('memory', '... and recorded as before', fn() => (fxMem($n2)['last_result']['ok'] ?? null) === true, fn() => json_encode(fxMem($n2)['last_result'] ?? null));
    fxSay($n2, $alone, 'Take off your clothes.');
    $w = fxLlm($n2, FX_ACT_CLOTHING, 'undress');
    $v = fxFuncretTurn($n2, $w[0] ?? '', 'Error: someone is watching');
    $t->must('the game refuses: VOICED - her clothes did not come off, somebody is watching',
        !$v->handled && !$v->ended && $v->mode() === 'voiced' && str_contains($v->cue, "Brenna Flowtest's clothes did not come off - somebody is watching"), fx_short($v->cue, 400));
    // [pt17 / owner addendum 11] the DEVELOPER dry run (bDryRun:General) is voiced too, and names the switch and the
    // page: 2026-09-23 the owner had it on by mistake and seven correct requests were refused without a word
    fxAdvance(12);
    fxSay($n2, $alone, 'Take off your clothes.');
    $w = fxLlm($n2, FX_ACT_CLOTHING, 'undress');
    $v = fxFuncretTurn($n2, $w[0] ?? '', 'Error: dry-run mode is ON in the LoreRim Glue MCM (Diagnostics page) - nothing was changed');
    $t->must('a DRY-RUN refusal is VOICED (it used to be the one refusal kept silent on purpose)',
        !$v->handled && !$v->ended && $v->mode() === 'voiced', $v->summary());
    $t->must('...and the cue names the switch and the page, and lets her say so out of character for this one reason',
        str_contains($v->cue, 'dry-run switch') && str_contains($v->cue, 'Diagnostics')
        && str_contains($v->cue, 'say plainly that the LoreRim Glue dry-run switch is on'), fx_short($v->cue, 500));
    fxAdvance(12);
    fxSay($n2, $alone, 'Take off your clothes.');
    $w = fxLlm($n2, FX_ACT_CLOTHING, 'undress');
    $v = fxFuncretTurn($n2, $w[0] ?? '', 'Error: dry-run mode, nothing changed');
    $t->must('...508\'s old wording is voiced the same way (an old game script with the new server)',
        $v->mode() === 'voiced' && str_contains($v->cue, 'dry-run switch'), fx_short($v->cue, 300));

    // ---------------------------------------------------------------- 3. an unreachable position inside a scene (voiced)
    fxReset();
    fxSetScore($n2, $alone, 10);
    $sc = fxBeginScene($t, $n2, $alone);
    $r = fxSay($n2, $sc['snap'], 'Hello.');
    $w = fxLlm($n2, FX_ACT_CONTROL, (string) array_key_first($r->options()));
    $t->must('set-up: a goto went out', count(fxReal($w)) === 1 && fxDo(fxReal($w)[0]) === 'goto', json_encode(array_map('trim', $w)));
    $v = fxFuncretTurn($n2, fxReal($w)[0] ?? '', 'Error: that position cannot be reached from here');
    $t->must('goto unreachable: VOICED, although a scene is running - and no scene notes on that turn, only the cue',
        $v->mode() === 'voiced' && $v->volatile === '' && str_contains($v->cue, 'the change of position did not happen - that position cannot be reached from here'), fx_short($v->cue, 400));
    // her OWN move on a scene-lead tick stays quiet: told on her next turn, as before
    fxAdvance(200);
    $lead = fxLeadTick($n2, $sc['snap']);
    $t->must('set-up: her lead tick is admitted and may act', !$lead->handled && !empty($lead->turn['lead']), $lead->summary());
    $wl = fxReal(fxLlm($n2, FX_ACT_CONTROL, (string) array_key_first($lead->options())));
    $t->must('set-up: her own goto went out', count($wl) === 1 && fxDo($wl[0]) === 'goto', json_encode(array_map('trim', $wl)));
    $q = fxFuncretTurn($n2, $wl[0] ?? '', 'Error: that position cannot be reached from here');
    $t->must('her own lead move that failed is NOT voiced (nobody was waiting on it): ended before the lock', $q->handled && fxVoiced() === null);
    $told = fxSay($n2, $sc['snap'], 'Hello.');
    $t->must('... it is told on her next player turn instead, once', str_contains($told->volatile, 'did not happen (that position cannot be reached from here)'), fx_short($told->volatile, 500));

    // ---------------------------------------------------------------- 4. the rows predate 0.5.5: never a turn CHIM will not run
    fxReset();
    fxSetScore($n2, $alone, 10);
    fxWarm($n2, $alone);
    fxSay($n2, $alone, 'Take off your clothes.');
    $w = fxLlm($n2, FX_ACT_CLOTHING, 'undress');
    $keep = Fx::$catalog[FX_ACT_CLOTHING] ?? null;
    $t->must('set-up: the glue installed its ChangeClothing row with the follow-up on', is_array($keep) && ($keep['metadata']['followup']['enabled'] ?? null) === true);
    Fx::$catalog[FX_ACT_CLOTHING]['metadata']['followup']['enabled'] = false; // an install whose rows are still v10
    $v = fxFuncretTurn($n2, $w[0] ?? '', 'Error: someone is watching');
    Fx::$catalog[FX_ACT_CLOTHING] = $keep;
    $t->must('a row without the follow-up: context_pre.php ends the request (CHIM would say nothing anyway)', $v->ended && fxVoiced() === null);
    $told = fxSay($n2, $alone, 'Hello.');
    $t->must('... and she is told on her next turn instead - never lost', str_contains($told->volatile, 'did not happen (someone is watching)'), fx_short($told->volatile, 500));

    // ---------------------------------------------------------------- 5. every request is answered: closed, public, blind
    $s = fxCast('commoner');
    $sSnap = fxSnap($s['snap']);
    fxSetScore($s['name'], $sSnap, -20);
    $r = fxSay($s['name'], $sSnap, 'Take off your clothes.');
    $t->must('closed (a stranger): the reason is given, in one short line, never ignored',
        $r->mode() === 'closed' && str_contains($r->volatile, 'Intimacy is not possible right now because')
        && str_contains($r->directive(), 'It is not happening right now, for the reason above') && str_contains($r->directive(), 'one short line')
        && str_contains($r->directive(), 'never ignoring it'), fx_short($r->volatile, 700));
    fxSetScore($s['name'], $sSnap, 10);
    $r = fxSay($s['name'], ['wit' => '3'] + $sSnap, 'Take off your clothes.');
    $t->must('public (willing, the room is watching): "not here", with that reason, and where instead - never ignored',
        $r->mode() === 'public' && str_contains($r->directive(), 'Not here - other people are close enough to see or hear')
        && str_contains($r->directive(), 'Never ignore it'), fx_short($r->directive(), 500));
    fxAdvance((int) fxCfg('snapshot_max_age_seconds', 90) + 30);
    $r = fxTurn($s['name'], 'inputtext', 'Take off your clothes.');
    $t->must('blind (no fresh snapshot): one "not right now" line with a human reason, never the game, never ignored, no action',
        $r->mode() === 'silent' && $r->glueOffered() === [] && str_contains($r->directive(), 'Nothing can be set up or carried out on this turn')
        && str_contains($r->directive(), 'never a word about the game or missing facts') && str_contains($r->directive(), 'never ignoring it'), fx_short($r->volatile, 700));

    // ---------------------------------------------------------------- 6. CHIM's own movement that visibly failed
    fxReset();
    $m = fxCast('commoner'); $mn = $m['name'];
    $far = fxSnap(['dist' => '900'] + $m['snap']);
    fxSetScore($mn, $far, -20);
    fxSay($mn, $far, 'come over here', 'inputtext', ['enabled' => array_merge(fxEnabledDefault(), ['ComeCloser'])]);
    $w = fxLlmLines([fxLine($mn, 'ComeCloser', 'Flowtest Player')]);
    $t->must('CHIM\'s ComeCloser goes out untouched', count($w) === 1 && fxCode($w[0]) === 'ComeCloser');
    fxAdvance(8);
    fxSendSnapshot($mn, ['scene' => '1', 'dist' => '880'] + $far);   // 8 s later: still caught in an engine scene
    $r = fxSay($mn, ['dist' => '880'] + $far, 'hello?');
    $t->must('her next player turn is told she did not manage to come closer, and to say why if he asks',
        str_contains($r->volatile, 'did not manage to come closer') && str_contains($r->volatile, 'says why in one short line'), fx_short($r->volatile, 500));
    $r = fxSay($mn, ['dist' => '880'] + $far, 'hello?');
    $t->must('... once', !str_contains($r->volatile, 'did not manage'));
    fxSay($mn, $far, 'come over here', 'inputtext', ['enabled' => array_merge(fxEnabledDefault(), ['ComeCloser'])]);
    fxLlmLines([fxLine($mn, 'ComeCloser', 'Flowtest Player')]);
    fxAdvance(8);
    fxSendSnapshot($mn, ['dist' => '150'] + $far);                   // she came
    $r = fxSay($mn, ['dist' => '150'] + $far, 'hello?');
    $t->must('she came: nothing is said about it', !str_contains($r->volatile, 'did not manage'), fx_short($r->volatile, 300));
    // the escort's LATE failure: the game said "comes with you", then her quest started the scene again - twice
    fxGameMessage(['lrg_log', fxNow(), 100000, 'cid=sE29;msg=escort ' . $npc . ': she went back to her scene (BardSongs) and stays - it keeps starting again']);
    $r = fxSay($npc, $stage, 'hello?', 'inputtext', $opt);
    $t->must('the escort\'s late failure (only in the game\'s log) is told on her next turn: she did not manage to come along',
        str_contains($r->volatile, 'did not manage to come along') && str_contains($r->volatile, 'keeps being pulled back'), fx_short($r->volatile, 600));
});
