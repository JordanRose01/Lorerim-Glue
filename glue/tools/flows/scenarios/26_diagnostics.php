<?php
// 26 - G6: after three playtests the glue log still did not say what the player had said, which actions were
// offered, or what the model answered. Every turn now writes three fixed, greppable lines, the timestamps carry
// their UTC offset (CHIM flips the process timezone mid-request), and a command the game never confirmed is named.
/** Everything the plugin logged while $fn ran. */
function fx26_log(callable $fn): string
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

fx_scenario('26', 'G6: three fixed log lines per turn, timezone-independent stamps, and a watchdog for a lost command', function (FxT $t) {
    $c = fxCast('innkeeper'); $npc = $c['name'];
    $alone = fxSnap($c['snap']);
    fxSetScore($npc, $alone, 10);
    $sc = fxBeginScene($t, $npc, $alone);
    $in = $sc['snap'];

    // ---- (a) the turn line
    $log = fx26_log(fn() => fxSay($npc, $in, 'Take off your clothes.'));
    $turnLine = '';
    foreach (explode("\n", $log) as $l) { if (str_contains($l, ' turn npc=')) { $turnLine = $l; } }
    $t->must('a "turn" line is written for a player speech turn in a scene', $turnLine !== '', fx_short($log, 400));
    foreach (['say="', 'intent=', 'conf=', 'offered=', 'hidden=', 'mode=', 'heat=', 'hold='] as $field) {
        $t->must("the turn line carries $field", str_contains($turnLine, $field), $turnLine);
    }
    $t->must('the turn line carries what the player actually said', str_contains($turnLine, 'take off your clothes'), $turnLine);
    $t->must('the turn line carries the recognised intent and its confidence', str_contains($turnLine, 'intent=undress') && str_contains($turnLine, 'conf=high'), $turnLine);
    $t->must('the turn line names which of our actions were offered', (bool) preg_match('/offered=\S*Control/', $turnLine), $turnLine);
    $t->must('the utterance is capped, not dumped whole', (bool) preg_match('/say="[^"]{0,200}"/', $turnLine), $turnLine);

    // ---- (b) the llm line and (c) the net line
    $log = fx26_log(function () use ($npc, $in) { fxSay($npc, $in, 'Take off your clothes.'); fxLlmNothing($npc); });
    // NPC display names contain spaces, so the fields are matched by name, never by \S+
    $t->must('an "llm" line says what the model chose and how much it said', (bool) preg_match('/llm npc=.+ action=\w+ item="[^"]*" spoke=\d+/', $log), fx_short($log, 400));
    $t->must('the llm line reports "none" when the model chose no action', (bool) preg_match('/llm npc=.+ action=none/', $log), fx_short($log, 400));
    $t->mustCap('decorate', 'a "net" line says the net fired, with the command it built', fn() => (bool) preg_match('/net fired \S+ ok=1;/', $log), fn() => fx_short($log, 400));

    $log = fx26_log(function () use ($npc, $in) { fxSay($npc, $in, 'Hello.'); fxLlmNothing($npc); });
    $t->mustCap('decorate', 'and when it does not fire, the line says which precondition failed', fn() => str_contains($log, 'net skipped:') || !str_contains($log, 'net fired'), fn() => fx_short($log, 400));

    // ---- warnings that were invisible in every playtest so far
    $log = fx26_log(fn() => fxSay($npc, $in, ''));
    $t->must('an empty transcript is reported (17 % of playtest 6\'s uploads came back blank)', str_contains($log, 'WARN empty transcript'), fx_short($log, 300));
    $log = fx26_log(fn() => fxTurn($npc, 'narrator_inputtext', 'take your clothes off'));
    $t->must('player speech routed to the Narrator while a scene runs is reported', str_contains($log, 'WARN player speech routed to the Narrator'), fx_short($log, 300));
    $log = fx26_log(function () use ($npc, $in) { fxSay($npc, $in, 'faster'); fxLeadTick($npc, $in); });
    $t->must('a dropped lead tick says why, and how long is left', (bool) preg_match('/lead tick dropped: .*\d+s left/', $log), fx_short($log, 300));

    // ---- timestamps: CHIM flips the process timezone mid-request and never restores it
    $log = fx26_log(fn() => fxSay($npc, $in, 'faster'));
    $t->must('every line carries a UTC offset, so a mismatch is visible instead of silent', (bool) preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2} [+-]\d{2}:\d{2} /m', $log), fx_short($log, 200));
    $stamp = fn(string $s) => preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2} [+-]\d{2}:\d{2})/m', $s, $m) ? $m[1] : '';
    $was = date_default_timezone_get();
    $a = $stamp(fx26_log(fn() => fxSay($npc, $in, 'faster')));
    date_default_timezone_set('UTC');
    $b = $stamp(fx26_log(fn() => fxSay($npc, $in, 'faster')));
    date_default_timezone_set('Europe/Madrid');
    $cLine = $stamp(fx26_log(fn() => fxSay($npc, $in, 'faster')));
    date_default_timezone_set($was);
    $t->must('the stamp does not change when the ambient timezone does (the UTC / UTC+2 bug)', $a !== '' && $a === $b && $b === $cLine, "$a | $b | $cLine");

    // ---- the watchdog: one command in ten passed the gate in playtest 6 and never reached the game
    $confirm = (int) fxCfg('command_confirm_seconds', 20);
    fxSay($npc, $in, 'faster');
    $w = fxReal(fxLlm($npc, FX_ACT_CONTROL, 'faster'));
    $t->mustCap('memory', 'an emitted command is remembered as pending', fn() => count($w) === 1 && is_array(fxMem($npc)['pending_cmd'] ?? null), fn() => json_encode(fxMem($npc)['pending_cmd'] ?? null));
    fxFuncret($w[0], 'The pace is faster now.');
    $t->mustCap('memory', 'the game\'s answer clears it', fn() => !is_array(fxMem($npc)['pending_cmd'] ?? null), fn() => json_encode(fxMem($npc)['pending_cmd'] ?? null));
    fxSay($npc, $in, 'faster');
    fxLlm($npc, FX_ACT_CONTROL, 'faster'); // no funcret this time: the command is lost
    fxAdvance($confirm + 5);
    $log = fx26_log(fn() => fxSay($npc, $in, 'Hello.'));
    $t->mustCap(['memory', 'clock'], 'a command the game never confirmed is named, once, with its age', fn() => (bool) preg_match('/WARN command not confirmed by the game: \S+ do=\S* cid=\S+ age=\d+s/', $log), fn() => fx_short($log, 300));
    $log2 = fx26_log(fn() => fxSay($npc, $in, 'Hello.'));
    $t->mustCap(['memory', 'clock'], '... and not again on every later turn', fn() => !str_contains($log2, 'WARN command not confirmed'), fn() => fx_short($log2, 300));

    // ---- our own code must not produce PHP warnings on a live server
    $t->must('the run produced no PHP notice from the plugin itself', !array_filter(array_keys(Fx::$phpIssues), fn($k) => str_starts_with((string) $k, 'plugin/')),
        implode(' | ', array_slice(array_keys(Fx::$phpIssues), 0, 3)));
});
