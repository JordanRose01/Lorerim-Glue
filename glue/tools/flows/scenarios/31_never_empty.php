<?php
// 31 - [pt17-replies / owner addendum 11] NEVER EMPTY.
//
// Captain Aldis, 2026-09-23 20:10:31: "i didn't uh it says it's locked" got a schema-valid JSON whose message was ""
// (audit request 850, grok-4.3 through openrouterjson, strict json_schema) and nothing was spoken: CHIM's stream loop
// never reaches a sentence, returnLines() never runs, call_llm_internal() returns TRUE, the retry seam is consulted only
// for an INVALID reply and nobody had registered one. 17 of 322 logged replies look like that. lib/lrg_replies.php:
//   - the validator (VALIDATE_LLM_OUTPUT_FNCT, per chunk on EVERY LLM turn) rejects a COMPLETE empty reply on a
//     player-speech / lrg_dlgtalk / voiced-funcret turn;
//   - the retry (LLM_RETRY_FNCT) re-asks ONCE with the words rule appended to the last user message, under
//     IN_FALLBACK_MODE (no second player TTS), and only then speaks ONE short floor line through returnLines();
//   - the hook (the LAST post-LLM filter) floors what the validator lets through on purpose, before the actions echo;
//   - a business pick the game answers itself and a reply with words are left alone; the next turn carries one rule.
// Played through the REAL hook files (fxHookTurn / fxFuncretTurn). The LLM and CHIM's returnLines() are the seams
// LRG_TEST_REASK / LRG_TEST_SAY. Every line below is the PLAYER's, a CONFIG floor line, or the game's result - never a
// prompt example line.

fx_scenario('31', 'never empty: a schema-valid reply with no words is caught on the real hook files - rejected, re-asked once with the words rule, then one floor line through returnLines; words + an action and a business pick the game answers itself are left alone; the voiced funcret turn is covered although functions are off; the next turn carries one rule', function (FxT $t) {
    $GLOBALS['LRG_DLG_TEST_CFG'] = ['session.stage_rail' => true];   // [v1.0.1] the rail ships OFF; this scenario tests the rail itself
    $c = fxCast('innkeeper'); $npc = $c['name'];
    $alone = fxSnap($c['snap']);
    fxSay($npc, $alone, 'Hello.');
    // CHIM's own schema and text template (functions/json_response.php:457-520), character-first, as setStructuredOutputTemplate() builds them
    $schema = static function (): void {
        $keys = ['character', 'listener', 'message', 'mood', 'action', 'target', 'item', 'amount'];
        $props = [];
        foreach ($keys as $k) { $props[$k] = ['type' => $k === 'amount' ? 'integer' : 'string', 'description' => $k === 'message' ? 'lines of dialogue' : $k]; }
        $GLOBALS['structuredOutputTemplate'] = ['type' => 'json_schema', 'json_schema' => ['name' => 'response', 'strict' => true,
            'schema' => ['type' => 'object', 'properties' => $props, 'required' => $keys, 'additionalProperties' => false]]];
        $GLOBALS['responseTemplate'] = ['character' => 'x', 'listener' => 'x', 'message' => 'lines of dialogue', 'mood' => 'x', 'action' => 'x', 'target' => 'x', 'item' => 'x', 'amount' => 0];
    };
    // the decoded object as the JSON connector stores it per chunk (openrouterjson.php:1038), complete
    $reply = static function (string $message, string $action = 'Talk', string $item = '') use ($npc): array {
        return ['character' => $npc, 'listener' => 'Flowtest Player', 'message' => $message, 'mood' => 'calm', 'action' => $action, 'target' => 'Flowtest Player', 'item' => $item, 'amount' => 0];
    };

    // ---------------------------------------------------------------- 1. the wiring, through the real hook files
    $r = fxHookTurn($npc, 'inputtext', "i didn't uh it says it's locked");
    $t->must('functions.php / context_pre.php register CHIM\'s VALIDATE_LLM_OUTPUT_FNCT and LLM_RETRY_FNCT (main.php:2825 had nobody on that seam)',
        is_callable($GLOBALS['VALIDATE_LLM_OUTPUT_FNCT'] ?? null) && is_callable($GLOBALS['LLM_RETRY_FNCT'] ?? null));
    $last = $r->closures ? $r->closures[count($r->closures) - 1] : null;
    $t->must('...and the never-empty hook is the LAST post-LLM filter, after Phase 1\'s and Phase 2\'s gates',
        count($r->closures) === 3 && $last === ($GLOBALS['LRG_NE_HOOK'] ?? null) && $r->closures[0] === ($GLOBALS['LRG_POSTGATE'] ?? null), (string) count($r->closures));
    $schema();
    $hook = $GLOBALS['LRG_DLG_JSONHOOK'] ?? null;
    if (is_callable($hook)) { $hook(); }
    $desc = (string) ($GLOBALS['structuredOutputTemplate']['json_schema']['schema']['properties']['message']['description'] ?? '');
    $t->must('the JSON template hook adds the never-empty clause to message\'s DESCRIPTION (a rule) and no schema keyword (strict modes 400 on minLength)',
        str_contains($desc, 'Never empty: at least one short spoken sentence') && !isset($GLOBALS['structuredOutputTemplate']['json_schema']['schema']['properties']['message']['minLength'])
        && str_contains((string) $GLOBALS['responseTemplate']['message'], 'Never empty'), $desc);

    // ---------------------------------------------------------------- 2. the Aldis turn: message "" -> rejected -> re-ask -> one floor line
    $GLOBALS['LAST_LLM_RESPONSE'] = $reply('');
    $t->must('a COMPLETE reply whose message is "" is REJECTED by the validator', $GLOBALS['VALIDATE_LLM_OUTPUT_FNCT']('') === false && (($GLOBALS['LRG_NE_REJECT']['why'] ?? '') === 'empty message'));
    $asked = 0; $nudge = '';
    $GLOBALS['contextData'] = [['role' => 'system', 'content' => 'system'], ['role' => 'user', 'content' => "Write $npc's next dialogue line."]];
    $GLOBALS['LRG_TEST_REASK'] = static function (string $n) use (&$asked, &$nudge): bool {
        $asked++; $nudge = (string) $GLOBALS['contextData'][1]['content'];
        $GLOBALS['LRG_NE_REJECT'] = ['why' => 'empty message', 'action' => 'Talk', 'item' => ''];
        return false;
    };
    $GLOBALS['LLM_RETRY_FNCT']();
    $said = fxSaid();
    $t->must('the retry re-asked ONCE with the words rule appended to the last user message (a rule, no example line), and it came back empty again',
        $asked === 1 && str_contains($nudge, "Write $npc's next dialogue line.\n(Rule for this reply: $npc answers in words") && !str_contains($nudge, '"'), $nudge);
    $t->must('...so exactly ONE floor line reaches the game (returnLines: TTS, the chat row, the ScriptQueue echo - before X-CUSTOM-CLOSE)',
        count($said) === 1 && strlen($said[0]) <= 60 && in_array($said[0], (array) lrgNeCfg('lines.statement'), true), json_encode($said));
    $t->must('...and the turn counts as spoken', lrgSpokenThisTurn() === $said[0]);
    $t->must('...the context was restored and IN_FALLBACK_MODE cleared', (string) $GLOBALS['contextData'][1]['content'] === "Write $npc's next dialogue line." && !isset($GLOBALS['IN_FALLBACK_MODE']));
    $t->mustCap('memory', '...remembered per NPC: lrg_memory.empty_reply n=1', fn() => (int) (fxMem($npc)['empty_reply']['n'] ?? 0) === 1, fn() => json_encode(fxMem($npc)['empty_reply'] ?? null));
    $t->must('the log carries the count line: never-empty npc=<n> ... why=empty message twice said="<line>"',
        str_contains((string) @file_get_contents(fxLogFile()), 'never-empty npc=' . $npc . ' action=Talk why=empty message twice said="' . $said[0] . '"'));

    // ---------------------------------------------------------------- 3. her next line: the rule, once
    fxAdvance(20);
    $r2 = fxHookTurn($npc, 'inputtext', 'well?');
    $t->must('her next player turn carries the rule once: "last reply carried no words ... answers in words"',
        str_contains($r2->volatile, "$npc's last reply carried no words at all") && str_contains($r2->volatile, 'answers in words'), fx_short($r2->volatile, 300));
    $r3 = fxHookTurn($npc, 'inputtext', 'still there?');
    $t->must('...and not again', !str_contains($r3->volatile, 'carried no words'));

    // ---------------------------------------------------------------- 4. words + an action (the barter shape): every filter leaves the action alone, the rail adds nothing
    $r4 = fxHookTurn($npc, 'inputtext', 'what do you got for sale?');
    fxSpoke('Have a look, then.');
    $lines = [fxLine($npc, 'OpenInventory', '')];
    foreach ($r4->closures as $f) { $lines = $f($lines); }
    $t->must('a reply WITH words: OpenInventory goes out untouched and the rail adds no line', in_array(fxLine($npc, 'OpenInventory', ''), $lines, true) && fxSaid() === [], json_encode(array_map('trim', $lines)));
    $schema();
    $GLOBALS['LAST_LLM_RESPONSE'] = $reply('Have a look, then.', 'Trade_Items');
    $t->must('...and the validator passes it', $GLOBALS['VALIDATE_LLM_OUTPUT_FNCT']('Have') === true && !isset($GLOBALS['LRG_NE_REJECT']));

    // ---------------------------------------------------------------- 5. the re-ask that WORKS: her own words, no floor line
    $r5 = fxHookTurn($npc, 'inputtext', 'do you rent rooms?');
    $GLOBALS['talkedSoFar'] = [];   // a fresh request: nothing spoken yet (main.php starts every request so)
    $schema();
    $GLOBALS['LAST_LLM_RESPONSE'] = $reply('');
    $GLOBALS['VALIDATE_LLM_OUTPUT_FNCT']('');
    $GLOBALS['LRG_TEST_REASK'] = static function (string $n): bool { $GLOBALS['talkedSoFar'][] = 'Ten septims a night.'; return true; };
    $GLOBALS['LLM_RETRY_FNCT']();
    $t->must('when the re-ask brings her own words there is NO floor line (addendum 11: in her own words)', fxSaid() === [] && lrgSpokenThisTurn() === 'Ten septims a night.');

    // ---------------------------------------------------------------- 6. the voiced funcret turn: functions OFF, and she still says a line
    $stage = fxSnap($c['snap'] + ['scene' => '1', 'fac' => 'JobBardFaction,BardSingerFaction']);
    $opt = ['enabled' => array_merge(fxEnabledDefault(), ['FollowPlayer'])];
    fxSay($npc, $stage, 'okay so come on follow me now', 'inputtext', $opt);
    $esc = null;
    foreach (fxLlmLines([fxLine($npc, 'FollowPlayer', '')]) as $l) { if (fxCode((string) $l) === 'ExtCmdLRG_Escort') { $esc = (string) $l; } }
    $t->must('set-up: the escort went out in front of CHIM\'s follow', $esc !== null);
    $v = fxFuncretTurn($npc, (string) $esc, 'Error: ' . $npc . ' is in the middle of something she cannot leave (not a performance or idle scene she may leave)');
    $t->must('set-up: the refusal is the VOICED funcret turn', $v->mode() === 'voiced', (string) $v->mode());
    $t->must('...the validator / retry seams are registered on it all the same (context_pre.php runs on every request)',
        is_callable($GLOBALS['VALIDATE_LLM_OUTPUT_FNCT'] ?? null) && is_callable($GLOBALS['LLM_RETRY_FNCT'] ?? null));
    $GLOBALS['FUNCTIONS_ARE_ENABLED'] = false;   // processor/funcret.php:199 (use_functions_again false on every glue row): no post-process hook runs
    $schema();
    $GLOBALS['LAST_LLM_RESPONSE'] = $reply('');
    $t->must('an empty reply on the voiced turn is rejected', $GLOBALS['VALIDATE_LLM_OUTPUT_FNCT']('') === false);
    $GLOBALS['LRG_TEST_REASK'] = static function (string $n): bool { $GLOBALS['LRG_NE_REJECT'] = ['why' => 'empty message', 'action' => 'Talk', 'item' => '']; return false; };
    $GLOBALS['LLM_RETRY_FNCT']();
    $t->must('...and she says one line anyway (a statement line: a funcret asks nothing)', count(fxSaid()) === 1 && in_array(fxSaid()[0], (array) lrgNeCfg('lines.statement'), true), json_encode(fxSaid()));

    // ---------------------------------------------------------------- 7. [pt18-words] NEVER FALSE on the same hook files: General Tullius, 2026-09-23 23:19
    // The facts line said qst=CW00A:0 before and after, no session, no command - and the model swore the player in with words
    // (research/pt18-words.md). The false sentences below are that REAL output being forbidden, never authored dialogue; the
    // floor line is CONFIG text; the truthful reply is what the row itself states.
    $GLOBALS['FUNCTIONS_ARE_ENABLED'] = true;
    fxAdvance(60);
    $tul = 'General Tullius';
    fxSendSnapshot($tul, fxSnap(['fac' => 'CWImperialFaction,CWFieldCOFaction,CWDialogueSoldierFaction', 'sex' => '0', 'loc' => 'Castle Dour', 'ltype' => 'castle']));
    // the game's facts line for him, exactly as 23:19:24 had it, through the real pre-lock hook file (lrg_dlg ev=facts)
    $factsReq = ['lrg_dlg', fxNow(), 100000, FX_CTX . 'ev=facts;npc=' . $tul . ';ref=0x00012345;q=CW00A,CWObj,CWReservations,CW,aaThalmor,MQ101;qobj=-;sp=15;perk=-;lvl=1;wis=0;dlg=0;qal=-;qgiver=0;guard=0;bounty=0;cal=0;sq=-;sqj=0;qst=CW00A:0,CWObj:0,CWReservations:0,CW:0,aaThalmor:1,MQ101:0'];
    $GLOBALS['gameRequest'] = $factsReq; $GLOBALS['HERIKA_NAME'] = $tul;
    $t->must('set-up: the facts line is handled before the lock (ev=facts, qst=CW00A:0)', fxIncludeHook('preprocessing.php', $factsReq) && ((lrgDlgState($tul)['facts']['qst']['CW00A'] ?? -1) === 0), json_encode(lrgDlgState($tul)['facts'] ?? null));
    // the MCM tier as the night had it (ml=0: 0 of 10 conversations measured, the dry run) and no questlog rows (the seam, no DB shape)
    $GLOBALS['LRG_DLG_MCM'] = ['ml' => 0, 'cal' => 0, 'lf' => 1];
    $GLOBALS['LRG_DLG_TEST_QUESTLOG'] = [];
    $r7 = fxHookTurn($tul, 'inputtext', "okay i guess you're the guy i talked to to join the legion");
    $f7 = (array) ($GLOBALS['LRG_DLG_TURN']['faction'] ?? []);
    $t->must('the ask turn is a faction turn: legion, recruiter (Tullius by name), the dialogue turn on', ($f7['asked'] ?? '') === 'legion' && ($f7['role'] ?? '') === 'recruiter' && !empty($GLOBALS['LRG_DLG_TURN']['on']), json_encode($f7));
    $t->must('...its locked line says the game records an enlistment only through his real entry and has recorded none - no meta text about the glue',
        str_contains($r7->static, 'it has recorded none') && str_contains($r7->static, 'never swear him in') && !str_contains($r7->static, 'still learning'), fx_short($r7->static, 500));
    $t->must('...the transformer chain carries the never-false drop under Phase 2\'s mute (context_pre.php chains ours first)',
        isset($GLOBALS['LRG_NF_TRANSFORMER']) && ($GLOBALS['LRG_DLG_PREV_TRANSFORMER'] ?? null) === $GLOBALS['LRG_NF_TRANSFORMER'] && is_callable($GLOBALS['TRANSFORMER_FUNCTION'] ?? null));
    $schema();
    $at7 = (int) @filesize(fxLogFile());
    $GLOBALS['LAST_LLM_RESPONSE'] = $reply('You want to join the Legion? Swear your oath here, or move on. We have a war to fight.');
    $t->must('the validator REJECTS the reply at the oath sentence (why=false claim, class oath, the row\'s CW01A derived 0 from CW00A:0)',
        $GLOBALS['VALIDATE_LLM_OUTPUT_FNCT']('') === false && (($GLOBALS['LRG_NE_REJECT']['why'] ?? '') === 'false claim') && (($GLOBALS['LRG_NE_REJECT']['class'] ?? '') === 'oath')
        && (($GLOBALS['LRG_NE_REJECT']['said'] ?? '') === 'Swear your oath here, or move on.'), json_encode($GLOBALS['LRG_NE_REJECT'] ?? null));
    $log7 = (string) substr((string) @file_get_contents(fxLogFile()), $at7);
    $t->must('...logged with the fact and the reason the owner needs (cal=0, the dry run: ml=0)',
        str_contains($log7, 'never-false npc=General Tullius role=recruiter class=oath said="Swear your oath here, or move on." fact="CW01A:0(derived:CW00A:0<10)<160" verdict=rejected ml=0 cal=0'), fx_short($log7, 400));
    // the retry: the truthful first sentence had already gone to TTS mid-stream (chim.log:20479); the re-ask quotes the false one
    $GLOBALS['talkedSoFar'] = ['You want to join the Legion?'];
    $GLOBALS['contextData'] = [['role' => 'system', 'content' => 'system'], ['role' => 'user', 'content' => "Write $tul's next dialogue line."]];
    $asked = 0; $nudge = '';
    $GLOBALS['LRG_TEST_REASK'] = static function (string $n) use (&$asked, &$nudge): bool {
        $asked++; $nudge = $n;
        $GLOBALS['LRG_NE_REJECT'] = ['why' => 'false claim', 'class' => 'member', 'said' => 'Then you are a Legionnaire.', 'fact' => 'x', 'row' => 'legion', 'role' => 'recruiter', 'action' => 'Talk', 'npc' => 'General Tullius'];
        return false;
    };
    $GLOBALS['LLM_RETRY_FNCT']();
    $said = fxSaid();
    $t->must('the retry re-asked ONCE, quoting the model\'s own sentence as false, the game as the judge, and what he had already said (single quotes, no double quote)',
        $asked === 1 && str_contains($nudge, "the sentence 'Swear your oath here, or move on.' is false") && str_contains($nudge, 'The game, not the story, decides')
        && str_contains($nudge, "already said: 'You want to join the Legion?'") && !str_contains($nudge, '"'), $nudge);
    $t->must('...the second reply was false too, so exactly ONE truthful floor line follows the spoken partial (returnLines: TTS, the chat row, the echo)',
        count($said) === 1 && in_array($said[0], (array) lrgNfCfg('lines.recruiter'), true) && lrgSpokenThisTurn() === 'You want to join the Legion? ' . $said[0], json_encode([$said, lrgSpokenThisTurn()]));
    $t->must('...logged: never-false ... why=false claim twice said="<line>" after=<n> chars', str_contains((string) @file_get_contents(fxLogFile()), 'why=false claim twice said="' . ($said[0] ?? '') . '" after=28 chars'));
    $t->mustCap('memory', '...remembered per NPC: lrg_memory.false_claim n=1, class oath, told=false', fn() => (int) (fxMem($tul)['false_claim']['n'] ?? 0) === 1 && (fxMem($tul)['false_claim']['told'] ?? null) === false, fn() => json_encode(fxMem($tul)['false_claim'] ?? null));
    // his next line: the rule, once
    fxAdvance(20);
    $r8 = fxHookTurn($tul, 'inputtext', 'so am i in or not');
    $t->must('his next player turn carries the rule once: last reply claimed an oath the game had not recorded; that sentence was not spoken; nothing has begun',
        str_contains($r8->volatile, "General Tullius's last reply claimed an oath the game had not recorded; that sentence was not spoken.") && str_contains($r8->volatile, 'Nothing has begun'), fx_short($r8->volatile, 400));
    $schema();
    $GLOBALS['LAST_LLM_RESPONSE'] = $reply('Nothing is settled by talk at this table. Come to me the proper way and we will see.');
    $t->must('a truthful reply on the same turn passes the validator untouched', $GLOBALS['VALIDATE_LLM_OUTPUT_FNCT']('') === true && !isset($GLOBALS['LRG_NE_REJECT']));
    $r9 = fxHookTurn($tul, 'inputtext', 'fine');
    $t->must('...and the rule is not repeated', !str_contains($r9->volatile, 'last reply claimed'));
    $t->must('the hook count is unchanged: never-false rides the existing three post-LLM filters (no fourth closure)', count($r9->closures) === 3, (string) count($r9->closures));
    unset($GLOBALS['LRG_DLG_MCM'], $GLOBALS['LRG_DLG_TEST_QUESTLOG']);
});
