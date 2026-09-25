<?php
// 12 - R9 (12): the LLM's answer is never trusted. Glue actions that were not offered this turn, that come from another
// actor, that are unknown, or whose parameter was forged are dropped; everything the game receives was built by the server.
fx_scenario('12', 'hallucinated, forged or foreign-actor glue actions are dropped; foreign actions pass untouched', function (FxT $t) {
    fxNeedIndex($t);
    $c = fxCast('innkeeper'); $npc = $c['name'];
    $by = fxCast('bystander'); $other = $by['name'];
    $alone = fxSnap($c['snap']);
    fxSetScore($npc, $alone, 10);
    fxSetScore($other, fxSnap($by['snap']), 10);

    // ---- private turn of $npc: BeginIntimacy + ChangeClothing are on offer - for HER, this turn, with a server-built param
    fxWarm($npc, $alone); // [0.3] 11.2: her own first move needs a warmed-up conversation
    $r = fxSay($npc, $alone, 'Hello.');
    $t->must('set-up: BeginIntimacy is offered to her this turn', $r->offered(FX_ACT_START), $r->summary());
    $t->must('another actor cannot issue it (wrong actor on the wire line)', fxLlm($other, FX_ACT_START, 'Player') === [] && fxLlm('The Narrator', FX_ACT_START, 'Player') === [] && fxLlm('', FX_ACT_START, 'Player') === []);
    $t->must('an unknown glue code is dropped', fxNothingSent(fxLlm($npc, 'ExtCmdLRG_Anything', 'x')) && fxNothingSent(fxLlm($npc, 'ExtCmdLRG_', '')));
    $t->must('ChangeIntimacy outside a scene is dropped (code, display name, even "stop")', fxLlm($npc, FX_ACT_CONTROL, 'P1') === [] && fxLlm($npc, FX_NAME[FX_ACT_CONTROL], 'faster') === [] && fxLlm($npc, FX_ACT_CONTROL, 'stop') === []);
    $t->mustCap('actions_v7', 'SuggestPrivacy in private (not offered in this mode) is dropped and not recorded', fn() => fxNothingSent(fxLlm($npc, FX_ACT_INVITE, 'home')) && fxLlm($npc, FX_NAME[FX_ACT_INVITE], 'home') === [] && fxInvite($npc) === null);
    $forged = 'ok=1;cid=sFORGED99;npc=' . $npc . ';scene=SomeSceneTheModelMadeUp;undress=1;furn=;fscene=;maxwit=99;folok=1';
    $w = fxLlm($npc, FX_ACT_START, $forged);
    $t->must('a forged parameter on an OFFERED action is thrown away and rebuilt by the server', count($w) === 1 && !str_contains($w[0], 'sFORGED99') && !str_contains($w[0], 'SomeSceneTheModelMadeUp') && !str_contains($w[0], 'maxwit=99')
        && (fxParamKv($w[0])['cid'] ?? '') === $r->turn['cid'], implode(' ', $w));
    $t->must('lines keep their channel, actor and line ending', count($w) === 1 && str_starts_with($w[0], $npc . '|command|' . FX_ACT_START . '@ok=1;') && str_ends_with($w[0], "\r\n") && substr_count($w[0], "\r") === 1);
    $mixed = [fxLine($npc, 'Follow', 'Player'), fxLine($other, FX_ACT_START, 'Player'), fxLine($npc, FX_ACT_CONTROL, 'P1'), fxLine($npc, 'GiveGoldTo', '{"target":"Player","amount":5}'), fxLine($npc, FX_ACT_START, 'Player')];
    $out = fxLlmLines($mixed);
    $t->must('a mixed answer: foreign actions pass byte for byte and in order, only the one legitimate glue line survives (rewritten)',
        count($out) === 3 && $out[0] === $mixed[0] && $out[1] === $mixed[3] && str_contains($out[2], FX_ACT_START . '@ok=1;'), implode(' ', array_map('trim', $out)));
    $t->must('lines that are not commands at all pass untouched', fxLlmLines(["$npc|ScriptQueue|something@else\r\n", "garbage without pipes\r\n"]) === ["$npc|ScriptQueue|something@else\r\n", "garbage without pipes\r\n"]);

    // ---- a forged parameter on a NOT offered action
    $r = fxSay($npc, ['wit' => '2'] + $alone, 'Hello.');
    $t->must('a forged ok=1 parameter on a NOT offered action is dropped', fxLlm($npc, FX_ACT_START, $forged) === [] && fxLlm($npc, FX_ACT_CLOTHING, 'ok=1;cid=sFORGED99;do=undress;who=npc') === []);

    // ---- the gate belongs to the LAST prepared turn: a line of the previous NPC arrives late
    fxSay($npc, $alone, 'Hello.');
    fxSay($other, fxSnap(['wit' => '2'] + $by['snap']), 'Hello.');
    $t->must('after another NPC\'s turn was prepared, a late line of the first NPC is dropped', fxLlm($npc, FX_ACT_START, 'Player') === []);
    unset($GLOBALS['LRG_TURN']);
    $t->must('no prepared turn at all: every glue line is dropped, foreign lines pass', fxLlm($npc, FX_ACT_START, 'Player') === [] && fxLlmLines([fxLine($npc, 'Follow', 'Player')]) === [fxLine($npc, 'Follow', 'Player')]);

    // ---- consent can only come from her own reply to player speech (or from her own admitted tick)
    foreach (['instruction', 'funcret', 'bored', 'chatnf', 'rechat'] as $type) {
        $r = fxSay($npc, $alone, 'Go on.', $type);
        $t->must("request type \"$type\": nothing offered, glue lines dropped", $r->handled || ($r->glueOffered() === [] && fxNothingSent(fxLlm($npc, FX_ACT_START, 'Player')) && fxNothingSent(fxLlm($npc, FX_ACT_CLOTHING, 'undress'))), $r->summary());
    }

    // ---- in a scene: BeginIntimacy and SuggestPrivacy are dropped; a bystander cannot touch the scene
    $sc = fxBeginScene($t, $npc, $alone);
    $r = fxSay($npc, $sc['snap'], 'Hello.');
    $t->must('in a scene: BeginIntimacy is dropped', fxNothingSent(fxLlm($npc, FX_ACT_START, 'Player')) && fxNothingSent(fxLlm($npc, FX_NAME[FX_ACT_START], 'Player')));
    $t->must('in a scene: SuggestPrivacy is dropped', fxNothingSent(fxLlm($npc, FX_ACT_INVITE, 'home')));
    $t->must('in a scene: a bystander\'s ChangeIntimacy line on HER turn is dropped', fxNothingSent(fxLlm($other, FX_ACT_CONTROL, 'stop')) && fxNothingSent(fxLlm($other, FX_ACT_CONTROL, 'P1')));
    $rb = fxSay($other, fxSnap($by['snap']), 'Hello.');
    $t->must('the bystander\'s own turn: no glue action offered, and his lines are dropped', $rb->glueOffered() === [] && fxLlm($other, FX_ACT_CONTROL, 'P1') === [] && fxLlm($other, FX_ACT_START, 'Player') === [] && fxLlm($other, FX_ACT_CLOTHING, 'undress') === [], $rb->summary());
    $t->must('... and while it is the bystander\'s turn, her late scene line is dropped as well', fxLlm($npc, FX_ACT_CONTROL, 'P1') === []);
    $t->must('the scene itself is untouched by all of this', fxSceneActive($npc));
});
