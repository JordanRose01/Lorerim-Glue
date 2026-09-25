<?php
// 27 - [0.5.2 / pt10] THE BLIND TURN: the game has gone quiet and the glue has no fresh facts.
//
// Playtest 10 spent thirty minutes here. LRG_Profile.BuildSnapshot() was being killed by a Papyrus
// error on every call, so no snapshot ever reached the server; the gate read `no_fresh_snapshot`,
// lrgGateMode() turned that into mode `silent`, and `silent` was not in the list that runs the intent
// recogniser. The result was TWO different failures wearing one face:
//   · the right one - no action was offered and nothing was executed (that is the rail working);
//   · the wrong one - "kiss me" / "come hug me" / "take your clothes off" were never even PARSED, so
//     intent=none in every turn line, no heat, no quote memory, no directive, and nothing anywhere
//     said what the player had actually asked for.
// This scenario pins both halves apart: understanding the sentence is required, offering an action is
// still forbidden, and the model is told plainly that the glue is blind instead of being left to fill
// the silence by itself.
/** Everything the plugin logged while $fn ran. */
function fx27_log(callable $fn): string
{
    $file = fxLogFile();
    $before = is_file($file) ? (int) filesize($file) : 0;
    $fn();
    clearstatcache(true, $file);
    if (!is_file($file)) { return ''; }
    $fh = fopen($file, 'rb');
    fseek($fh, $before);
    $out = (string) stream_get_contents($fh);
    fclose($fh);
    return $out;
}

fx_scenario('27', 'pt10: the game goes quiet - the sentence is still understood, no action is ever offered, and the model is told why', function (FxT $t) {
    $c = fxCast('innkeeper'); $npc = $c['name'];
    $alone = fxSnap($c['snap']);
    $maxAge = (int) fxCfg('snapshot_max_age_seconds', 90);
    fxSetScore($npc, $alone, 10);          // willing, alone, private: everything EXCEPT a fresh snapshot
    fxSendSnapshot($npc, $alone);
    fxAdvance($maxAge + 30);               // ... and then the game stops reporting, exactly as in pt10

    // ---- (a) the sentence is understood
    $log = fx27_log(function () use (&$r, $npc) { $r = fxTurn($npc, 'inputtext', 'Take off your clothes.'); });
    $t->mustCap('modes', 'the turn is silent for one reason only: no fresh snapshot', fn() => $r->mode() === 'silent' && $r->reasons() === ['no_fresh_snapshot'], fn() => $r->summary());
    $t->mustCap('intent', 'a clear request is RECOGNISED although the turn is silent (pt10: intent=none for thirty minutes)',
        fn() => $r->intentIs('undress', 'high'), fn() => json_encode($r->intent()));
    $t->mustCap('intent', 'the turn line carries the real intent, so the log says what the player asked for',
        fn() => (bool) preg_match('/ turn npc=.* intent=undress\S* conf=high/', $log), fn() => fx_short($log, 400));

    // ---- (b) ... and NOTHING is offered, sent or executed. This is the half that must not move.
    $t->must('nothing is offered on a blind turn', $r->glueOffered() === [], $r->glueNames());
    $t->must('no explicit wording is permitted (x null) and no <personal_boundaries> block is injected',
        $r->x() === null && $r->static === '' && !fxHasExplicitPermission($r->guidance()), $r->summary() . ' static=' . strlen($r->static));
    $bad = [];
    foreach (FX_GLUE as $code) {
        foreach ([$code, FX_NAME[$code]] as $name) {
            if ($name === FX_NAME[FX_ACT_INVITE] && !Fx::has('actions_v7')) { continue; }
            foreach (['Player', 'undress', 'home', 'P1', 'stop'] as $item) {
                if (fxLlm($npc, $name, $item) !== []) { $bad[] = "$name@$item"; }
            }
        }
    }
    $t->must('every glue action the model could choose is still dropped', $bad === [], implode(' ', array_slice($bad, 0, 6)));
    // the safety net is the one path that acts WITHOUT the model choosing anything. It is bound to a
    // running, confirmed scene, so a recognised intent out of a scene must never wake it.
    $netLog = fx27_log(function () use ($npc) { fxTurn($npc, 'inputtext', 'Take off your clothes.'); fxLlmNothing($npc); });
    $t->mustCap('decorate', 'the safety net does not fire on a blind turn, and says which precondition failed',
        fn() => !str_contains($netLog, 'net fired') && str_contains($netLog, 'net skipped:'), fn() => fx_short($netLog, 400));
    $t->must('nothing at all reached the game', fxNothingSent(fxLlmNothing($npc)));

    // ---- (c) the model is told, plainly and shortly, why the glue is saying nothing
    $t->must('the model is told there are no fresh facts', str_contains($r->volatile, 'no fresh facts'), fx_short($r->volatile, 300));
    $t->must('... and is told to answer in words rather than act anything out',
        (bool) preg_match('/\bin words\b/i', $r->volatile) && (bool) preg_match('/\bdo not act\b/i', $r->volatile), fx_short($r->volatile, 300));
    // [0.5.5 / owner addendum 11 (4)] ... and the request is ANSWERED, never ignored: one "not right now" line with a
    // plain human reason - the plain kind only, no action named, and never a word about the game or missing facts
    $t->must('... in one short block that names no action; the request gets ONE plain "not right now" line, never ignored',
        strlen($r->volatile) <= 1000 && substr_count($r->volatile, '<player_request>') === 1
        && str_contains($r->directive(), 'clothes off') && str_contains($r->directive(), 'one short line') && str_contains($r->directive(), 'never ignoring it')
        && str_contains($r->directive(), 'never a word about the game or missing facts')
        && !preg_match('/\b(BeginIntimacy|ChangeClothing|SuggestPrivacy|ChangeIntimacy|RequestAct)\b/', $r->volatile), fx_short($r->volatile, 900));
    $rq = fxTurn($npc, 'inputtext', 'Hello there.');
    $t->must('... a blind turn with no request carries the note alone', str_contains($rq->volatile, 'no fresh facts') && !$rq->hasDirective(), fx_short($rq->volatile, 300));
    $t->must('... and CHIM\'s own runtime is left exactly as CHIM made it (no token budget, no filter switch)',
        !$r->refusalFilterOff && $r->maxTokens === null, 'filter_off=' . var_export($r->refusalFilterOff, true) . ' max_tokens=' . var_export($r->maxTokens, true));
    $t->must('the owner gets one greppable line with the age of the facts the server does have',
        (bool) preg_match('/silent: no fresh snapshot \(age=(?:\d+s|none)\)/', $log), fx_short($log, 400));

    // ---- (d) an NPC the game has NEVER reported on is not a degradation: she is an ordinary CHIM
    // conversation, and PROTOCOL 6.2's "a silent turn injects nothing at all" has to keep holding.
    fxReset();
    $by = fxCast('bystander');
    $blank = fx27_log(function () use (&$rb, $by) { $rb = fxTurn($by['name'], 'inputtext', 'Take off your clothes.'); });
    $t->mustCap('modes', 'an NPC the game never sent a snapshot for: silent, nothing offered, and NOT one character injected',
        fn() => $rb->mode() === 'silent' && $rb->glueOffered() === [] && $rb->silent(), fn() => $rb->summary() . ' volatile=' . strlen($rb->volatile));
    // the LOG is the owner's diagnostics and costs nothing, so it still names her - with age=none,
    // which is how "the game never reported at all" is told apart from "the game is late".
    $t->must('... though the log still says so, with age=none', (bool) preg_match('/silent: no fresh snapshot \(age=none\)/', $blank), fx_short($blank, 300));

    // ---- (e) stale facts are not facts - but they are still good enough to stay QUIET on. If the last
    // thing the game said was "not a confirmed adult", or that the owner's switch was off, a blind turn
    // is as mute as it is today: no recognition, no note, nothing in the log.
    foreach (['a minor whose snapshot went stale' => ['adult' => '0'], 'the owner\'s intimacy switch was off when the game last reported' => ['on' => '0']] as $label => $over) {
        fxReset();
        $m = $over === ['adult' => '0'] ? fxCast('minor') : $c;
        fxAff($m['name'], 100);
        fxSendSnapshot($m['name'], fxSnap($over + $m['snap']));
        fxAdvance($maxAge + 30);
        $lg = fx27_log(function () use (&$rm, $m) { $rm = fxTurn($m['name'], 'inputtext', 'Take off your clothes.'); });
        $t->mustCap(['modes', 'intent'], "$label: still totally silent - nothing offered, nothing injected, nothing recognised",
            fn() => $rm->mode() === 'silent' && $rm->glueOffered() === [] && $rm->silent() && $rm->intentIs('none'), fn() => $rm->summary() . ' intent=' . json_encode($rm->intent()));
        $t->must("$label: and no blind note in the log", !str_contains($lg, 'silent: no fresh snapshot'), fx_short($lg, 300));
    }

    // ---- (f) the build-up survives the blind stretch. Heat is the conversation's own counter, never a
    // rail (every hard gate is recomputed from the next fresh snapshot), and losing it is why the first
    // good turn after pt10 would still have answered "a question about this is a question".
    fxReset();
    fxSetScore($npc, $alone, 10);
    fxSendSnapshot($npc, $alone);
    fxAdvance($maxAge + 30);
    foreach (['You look beautiful tonight.', 'I want you.'] as $line) { fxTurn($npc, 'inputtext', $line); }
    $t->mustCap('memory', 'two romantic lines during the blind stretch still warm the conversation up',
        fn() => (int) (fxMem($npc)['heat'] ?? 0) >= 2, fn() => json_encode(fxMem($npc)));
    $back = fxSay($npc, $alone, 'Hello.');      // the game starts reporting again
    $t->mustCap('modes', 'the moment a fresh snapshot arrives the note is gone and the ordinary turn is back',
        fn() => $back->mode() === 'private' && !str_contains($back->volatile, 'no fresh facts'), fn() => $back->summary());
    $t->mustCap(['modes', 'memory'], '... and the build-up the blind stretch collected counts, so she is not asked to start from cold',
        fn() => $back->offered(FX_ACT_START) && $back->heat() >= 2, fn() => $back->summary() . ' heat=' . $back->heat());

    // ---- (g) mode `closed` is NOT blindness: pressing someone who has refused must never warm her up.
    fxReset();
    $j = fxCast('jarl');
    fxSetScore($j['name'], fxSnap($j['snap']), -40);           // not close enough: mode closed
    $snapJ = fxSnap($j['snap']);
    foreach (['You look beautiful tonight.', 'I want you.'] as $line) { fxSay($j['name'], $snapJ, $line); }
    $t->mustCap('memory', 'a refusal does not warm up: heat stays at 0 in mode closed, exactly as before',
        fn() => (int) (fxMem($j['name'])['heat'] ?? 0) === 0, fn() => json_encode(fxMem($j['name'])));
});
