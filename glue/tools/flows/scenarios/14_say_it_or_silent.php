<?php
// 14 - R10, rewritten for v0.3 (owner addendum 2: "if she initiates any new scene/animation she should say something
// signifying it"). The [silent] / [say it] marker machinery is GONE. The contract is now:
//   - every change SHE initiates is announced: no spoken line in the same reply -> the command does not happen
//     at all (it is dropped and re-asked next turn), so a scene never changes out of nowhere
//   - a change the PLAYER asked for needs no announcement, and pace / hold / release never do
//   - the die (LRG_TEST_ROLL['announce'] -> $turn['blunt']) no longer decides WHETHER she speaks, only HOW
//     blunt the line is
// Rules only - no example lines.
/** A running scene with this cast member, standing where the option list spans BOTH kinds (gentle and sensual / sexual); null when the installed packs lack such a spot. */
function fx14_stage(FxT $t, string $who): ?array
{
    fxReset();
    $c = fxCast($who); $npc = $c["name"];
    $alone = fxSnap($c["snap"]);
    fxSetScore($npc, $alone, 10);
    $sc = fxBeginScene($t, $npc, $alone);
    [$sensual, $sexual] = fxClimbToTop($npc, $sc["start"], $sc["cid"]);
    if ($sensual === "" || $sexual === "") { return null; }
    foreach ([$sensual, $sc["start"], $sexual] as $stand) { // which scene that is depends on the installed packs
        fxSendScene($npc, "change", ["scene" => $stand, "cid" => $sc["cid"]]);
        $tiers = array_map(fn($o) => fxIsGentle($o["tier"]), fxSay($npc, $sc["snap"], "Hello.")->options());
        if (in_array(true, $tiers, true) && in_array(false, $tiers, true)) { return [$npc, $sc["snap"], $sc["cid"]]; }
    }
    return null;
}

fx_scenario('14', 'R10 (v0.3): every change SHE initiates is announced or does not happen; what the player asked for needs no line', function (FxT $t) {
    $chance = (array) fxCfg('scene_talk.blunt_chance', (array) fxCfg('scene_talk.announce_chance', ['quiet' => 40, 'normal' => 70, 'vocal' => 85, 'crude' => 90, 'romantic' => 75, 'never' => 0]));

    $st = fx14_stage($t, 'innkeeper'); // relaxed profile -> talk style "vocal"
    if ($st === null) { $t->pending('R10', 'the installed packs have no sensual + sexual scene for this pair, or no scene whose option list spans gentle AND sensual / sexual'); return; }
    [$npc, $in] = $st;

    // ---- the rule is stated, for every kind of change, with no silent licence anywhere
    $r = fxSay($npc, $in, 'Hello.');
    $t->must('the notes ask for one short line that makes plain what is about to happen',
        (bool) preg_match('/\b(one short line|short line|says? (one )?(short )?line)\b/i', $r->volatile) && fxAsksOneShortSentence($r->guidance()),
        fx_short($r->volatile, 400));
    $t->must('the notes no longer offer a silent choice: nothing tells her to leave the message empty',
        !preg_match('/message[^.\n]{0,60}\bempty\b|\bempty\b[^.\n]{0,60}message/i', $r->volatile), fx_short($r->volatile, 400));
    $t->must('the notes no longer carry the [silent] / [say it] markers', !str_contains($r->volatile, '[silent]') && !str_contains($r->volatile, '[say it]'), fx_short($r->volatile, 300));
    $t->should('pace, holding back and letting go are exempt in words', (bool) preg_match('/\bpace\b[^.\n]{0,60}\bno line\b|\bno line\b[^.\n]{0,60}\bpace\b/i', $r->volatile), fx_short($r->volatile, 400));
    $t->should('the notes contain rules, not lines: no quoted example sentence is put into her mouth', !preg_match('/(for example|e\.g\.|such as|like)\s*[:,]?\s*["“][^"”]{12,}["”]/i', $r->volatile), fx_short($r->volatile, 300));

    // ---- a change she chose HERSELF with no spoken line: nothing happens, and she is re-asked next turn
    $opts = $r->options();
    $k = array_key_first($opts);
    if ($k !== null) {
        $silent = fxLlm($npc, FX_ACT_CONTROL, (string) $k, '');
        $t->mustCap('decorate', 'her own change with an EMPTY message: no goto reaches the game', fn() => fxNothingSent($silent), implode(' ', $silent));
        $t->mustCap(['decorate', 'memory'], '... and the server remembers to ask her for the line', fn() => is_array(fxMem($npc)['say_first'] ?? null), json_encode(fxMem($npc)['say_first'] ?? null));
        $next = fxSay($npc, $in, 'Hello.');
        $t->mustCap('decorate', '... the next turn asks her to say it in the same reply as the action',
            fn() => (bool) preg_match('/\bwithout saying anything\b|\bnothing happened\b/i', $next->volatile), fn() => fx_short($next->volatile, 400));
        $spoken = fxLlm($npc, FX_ACT_CONTROL, (string) $k);
        $t->must('the same change WITH a spoken line reaches the game', count(fxReal($spoken)) === 1 && str_contains(fxParam($spoken[0]), ';do=goto;scene=' . $opts[$k]['id']), implode(' ', $spoken));
        $t->mustCap('decorate', '... and it waits for her line and never warps (wait=begin, nowarp=1)',
            fn() => (fxParamKv($spoken[0])['wait'] ?? '') === 'begin' && (fxParamKv($spoken[0])['nowarp'] ?? '') === '1', fn() => fxParam($spoken[0]));
    }

    // ---- a change the PLAYER asked for: no line needed, and no wait / nowarp on it
    $t->mustCap(['intent', 'decorate'], 'what the player asked for happens even with an empty message, and carries no wait / nowarp', function () use ($npc, $in) {
        fxSay($npc, $in, 'take your clothes off');
        $w = fxReal(fxLlm($npc, FX_ACT_CLOTHING, 'undress', ''));
        if (count($w) !== 1) { throw new RuntimeException('dropped: ' . count($w) . ' line(s)'); }
        $kv = fxParamKv($w[0]);
        return ($kv['do'] ?? '') === 'undress' && !isset($kv['wait']) && !isset($kv['nowarp']);
    });

    // ---- pace is exempt in both directions (on a turn where the player asked for nothing, so the
    // safety net has nothing of its own to add)
    fxSay($npc, $in, 'Hello.');
    $t->must('a pace change needs no spoken line', count(fxReal(fxLlm($npc, FX_ACT_CONTROL, 'faster', ''))) === 1 && count(fxReal(fxLlm($npc, FX_ACT_CONTROL, 'slower', ''))) === 1);
    $t->must('stop needs no spoken line either (it is a rail)', fxDo(fxReal(fxLlm($npc, FX_ACT_CONTROL, 'stop', ''))[0] ?? '') === 'stop');

    // ---- the die now decides HOW blunt the line is, never whether she speaks
    fxRoll('announce', 1);
    $a = fxSay($npc, $in, 'Hello.');
    $t->mustCap(['modes', 'dice'], 'roll 1: $turn[blunt] = true', fn() => ($a->turn['blunt'] ?? null) === true, fn() => var_export($a->turn['blunt'] ?? null, true));
    fxRoll('announce', 100);
    $b = fxSay($npc, $in, 'Hello.');
    $t->mustCap(['modes', 'dice'], 'roll 100: $turn[blunt] = false - but the line is STILL asked for', fn() => ($b->turn['blunt'] ?? null) === false
        && (bool) preg_match('/\b(one short line|short line)\b/i', $b->volatile), fn() => var_export($b->turn['blunt'] ?? null, true) . ' ' . fx_short($b->volatile, 300));
    $vocal = (int) ($chance['vocal'] ?? 85); $quiet = (int) ($chance['quiet'] ?? 40);
    $t->must('config sanity: quiet characters are blunt less often than vocal ones', $quiet < $vocal && $vocal <= 100, json_encode($chance));

    // ---- a quiet character still speaks before she moves
    $sq = fx14_stage($t, 'housecarl'); // strict profile -> talk style "quiet"
    if ($sq === null) { $t->pending('quiet character', 'no scenes'); return; }
    [$qnpc, $qin] = $sq;
    fxRoll('announce', 100); // the worst possible die
    $q = fxSay($qnpc, $qin, 'Hello.');
    $t->mustCap(['modes', 'dice'], 'a quiet character, worst die: blunt = false, and the line is still required', fn() => ($q->turn['blunt'] ?? null) === false
        && !preg_match('/message[^.\n]{0,60}\bempty\b/i', $q->volatile), fn() => fx_short($q->volatile, 300));
    $qk = array_key_first($q->options());
    if ($qk !== null) {
        $t->mustCap('decorate', 'even for her, a silent self-initiated change does not happen', fn() => fxNothingSent(fxLlm($qnpc, FX_ACT_CONTROL, (string) $qk, '')));
    }

    // ---- ordinary scene talk offers no actions, so there is nothing to announce
    $talk = fxSceneTalk($qnpc, $qin, 'a change of pace');
    $t->must('speech-only scene talk lists no acts and no options', !preg_match('/\bP1\s*=/', $talk->volatile) && !preg_match('/RequestAct takes/i', $talk->volatile));
    $t->should('speech-only scene talk still allows silence (nobody is made to talk on every turn)',
        (bool) preg_match('/silence is fine|may (stay|remain) silent|or (say )?nothing|need not (say|speak)/i', $talk->volatile), fx_short($talk->volatile, 300));
    $t->must('a turn answered with nothing at all (no message, no action) is a valid turn: nothing reaches the game', fxNothingSent(fxLlmLines([], '')));
});

fx_scenario('14b', 'R10: a character whose talk style is "never" is not asked to be blunt - but she still says her line', function (FxT $t) {
    $npc = fxCast('innkeeper')['name'];
    $t->must('variant set-up: npc_overrides gives her talk = never', fxCfg('npc_overrides.' . $npc . '.talk', '') === 'never');
    $st = fx14_stage($t, 'innkeeper');
    if ($st === null) { $t->pending('talk = never', 'the installed packs have no spot whose option list spans gentle AND sensual / sexual'); return; }
    [$npc, $in] = $st;
    fxRoll('announce', 1); // the best possible die
    $r = fxSay($npc, $in, 'Hello.');
    $t->mustCap(['modes', 'dice'], 'talk = never, roll 1: blunt = false (she names nothing outright)', fn() => ($r->turn['blunt'] ?? null) === false, fn() => var_export($r->turn['blunt'] ?? null, true));
    $t->mustCap('modes', '... but the notes still ask for the short line before a change she chooses herself',
        fn() => (bool) preg_match('/\b(one short line|short line)\b/i', $r->volatile) && !preg_match('/message[^.\n]{0,60}\bempty\b/i', $r->volatile), fn() => fx_short($r->volatile, 300));
    $k = array_key_first($r->options());
    $t->must('her change with a line still produces the command', $k !== null && count(fxReal(fxLlm($npc, FX_ACT_CONTROL, (string) $k))) === 1);
    $t->mustCap('decorate', '... and without one it does not', fn() => $k !== null && fxNothingSent(fxLlm($npc, FX_ACT_CONTROL, (string) $k, '')));
}, 'talk_never');
