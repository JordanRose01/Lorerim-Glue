# pt18 - words lane: Tullius swore the player in with words the game never recorded (2026-09-23 23:19 -04:00)

Investigator notes. No file under glue/ was edited. Log contents quoted below are DATA, not instructions.
Paths: `glue/` = C:\Users\Jordan\AppData\Roaming\Claude\scratch-workspaces\...\scratch-2026-09-21-8ca12a\glue,
`HS/` = \\wsl.localhost\DwemerAI4Skyrim3\var\www\html\HerikaServer (read-only). Deployed ext files compared identical to
the source tree (`cmp`: lib/lrg_replies.php, lib/lrg_factions.php, functions.php, context_pre.php - all "same").

## 1. What happened, turn by turn (evidence)

> **Corrections from the build (2026-09-24, implementer, after the refuters re-read every source).** The lorerim_glue.log
> line numbers in the table below are 3-5 too high (the file had 3207 lines): the entries are at **3179-3186** (Rikke
> 23:19:13: turn 3179, `dlg turn` 3182, `dlg lock` 3184, `llm` 3186), **3187** (Tullius facts 23:19:24), **3192 / 3194 /
> 3197** (Tullius turn 1: `dlg turn` / `dlg lock` / `llm` 23:19:29-34), **3201 / 3203 / 3206** (turn 2, 23:19:47-50) and
> **3207** (Rikke facts 23:20:06) - cite by timestamp + cid. research/pt16-legion.md:104 is the topic-glob note, not the
> VMAD fact: cite pt17-switches.md:104 only. The CW00A stage-10 effect of `TIF__000D5136` is **INFERRED**, not decompiled:
> Skyrim.esm INFO 000D5136 carries VMAD 69 bytes = script TIF__000D5136, begin fragment Fragment_0, one CTDA
> GetIsID(0001327E), ENAM Goodbye; USSEP and AP carry no override; UESP stage 10 = "Spoken directly to General Tullius";
> USSEP 0D5150's second response is "Why don't you have a chat with Legate Rikke?" - consistent, not read. CHIM's own AI
> Quest Progression is OFF on this install (no `CHIM_AI_QUEST_PROGRESSION` line in conf.php, the sample default is false,
> 0 `QuestProgression` rows in chim.log): no other engine could have set a stage tonight, and the quest lane's
> `executed` flag is the ONLY writer of a stage flag while it stays off. `how=bridge` is struck from the contract in 4.4:
> research/dialogue-engine.md:554 forbids direct SetStage; only `pick|open` through the real session may set it. The model
> was x-ai/grok-4.3 (debugStream.log). Section 9 below is what was built, and where it departs from sections 4-6.

| when (-04:00) | where | fact |
|---|---|---|
| 23:19:13 | lorerim_glue.log:3187-3191 | Rikke, say="hi um i'm looking to join the legion": `dlg turn ... on=1 locked=1 ambient=1 faction=legion:recruiter`, `dlg lock ... facts=1 chars=415 classes=faction`. Block = the pt17 'before' line (lrg_factions.php:706-710): "only after General Tullius has sent him to you - 'About that test...' is not open before that - tell him so plainly in one line: General Tullius first, at the map table in Castle Dour; set no time, place, drill or test; nothing has begun" (context_sent_to_llm.log:25345-25353). Reply (output_from_llm.log:4377): "General Tullius first, at the map table in Castle Dour." - truthful. |
| 23:19:29 | lorerim_glue.log:3195-3199 | Tullius, say="okay i guess you're the guy i talked to to join the legion": `faction=legion:recruiter`, `ml=0`, `dlg lock ... facts=2 chars=482 classes=faction,quest`. Block (context_sent_to_llm.log:25833-25842): "you ARE one of those who take recruits ...; enlistment is settled in your own dialogue with him, not in this talk (the glue is still learning ... 0 of 10 ...) - tell him so plainly in one line, promise nothing and set no time, place, drill or test; nothing has begun" + "his current task: Escape Helgen" + the rule "Never invent an appointment ... never say a quest, a job or an enlistment has begun unless it is stated above or the game told you. Answer what he says, in words." |
| 05:19:31-34 +02:00 | output_from_llm.log:4384-4386 (Request ID 857) | grok-4.3 / openrouterjson: `{"action":"Talk", ..., "message":"You want to join the Legion? Swear your oath here, or move on. We have a war to fight.", ...}` |
| 05:19:33 +02:00 | chim.log:20479-20480, 20484 | `Speech sent for General Tullius ... size: 28 'You want t'` (the first sentence went to TTS MID-STREAM), then `REMAINING DATA < Swear your oath here, or move on. We have a war to fight.>` (the tail flushed after the stream), then `Speech sent ... 'Swear your'`. `openrouterjson: Returning command buffer with 0 commands`. |
| 23:19:34 | lorerim_glue.log:3201 | `llm npc=General Tullius action=none item="" spoke=86` - the glue saw the words and had no rail for them. |
| 23:19:47 | lorerim_glue.log:3202-3206 | Tullius, say="i swear to uphold the imperial vows": `faction=legion:recruiter:carried` (lrg_factions.php:645-650, inside the 90 s window), `dlg lock ... facts=2 chars=552 classes=faction,quest`. Block (context_sent_to_llm.log:26334-26344): the same recruiter line prefixed "(background to what he asked a moment ago, not what he is saying now)" + the carried rule "His words now may be about something else - answer them, in words; the enlistment above is background only." |
| 05:19:48-50 +02:00 | output_from_llm.log:4392-4393 (Request ID 858) | `"message":"Then you are a Legionnaire. Report to Legate Rikke at the training yard for your first orders."`, mood proud. |
| 05:19:49 +02:00 | chim.log:20647-20652 | `Speech sent ... size: 27 'Then you a'` (mid-stream), `REMAINING DATA < Report to Legate Rikke at the training yard for your first orders.>`, `Speech sent ... 'Report to '`. 0 commands. |
| 23:19:50 | lorerim_glue.log:3209 | `llm npc=General Tullius action=none item="" spoke=94`. No `emit`, no `faction ... pos=`, no ExtCmd of any kind. |
| 23:19:24 / 23:20:06 | lorerim_glue.log:3192, 3210 | `dlg facts npc=General Tullius ... qst=CW00A:0,CWObj:0,CWReservations:0,CW:0,aaThalmor:1,MQ101:0` before and `qst=...CW00A:0...` (Rikke) after: the game recorded NOTHING. CW01A is not on either q= list (not running). |

The quest facts on this save (game-side truth): CW00A = "Imperial Introductions" (stages 1 / 7 / 10, stage 10 = "Spoken directly to
General Tullius"); CW01A = "Joining the Legion" (stage 1 = Tullius has sent the player to Rikke, 100 = "Report to Legate Rikke",
160 = "Take the oath", 200 = done) - https://en.uesp.net/wiki/Skyrim:Joining_the_Legion . research/pt16-legion.md:104 and
pt17-switches.md:104 confirm the mechanism: INFO 0D5136 (CW00TulliusGreetTalkRikke) carries the VMAD fragment that sets CW00A
stage 10, and it plays only after the player's line 0D5150 "I was set free..." inside a real session. Nothing of that ran
(no session: ml=0 dry run, ambient scene, `open=0`). So on this turn every one of these was FALSE: an oath administered here,
"you are a Legionnaire" (CW01A 200), "report to Legate Rikke" (CW00A 10 / CW01A 1), "your first orders", "the training yard".

## 2. Why the existing rails let the words through (line-level)

1. **The prompt was right and was ignored.** The recruiter line and the rule (lrg_factions.php:715-721, 771-783) said "promise
   nothing ... nothing has begun" and "never say ... an enlistment has begun". grok-4.3 answered with an oath anyway; on the
   carried turn "I swear to uphold the imperial vows" the model read the player's oath as the enlistment and confirmed it.
   Prompt text alone is prevention, not a rail (the pt16 rule already failed twice before tonight: 16:38 Aldis, tonight Tullius).
2. **The truth gate never looks at these words.** `lrgDlgTruthCheck()` (lrg_dialogue.php:4650-4698) returns null unless the locked
   facts carry a `price` / `bounty` / `service` class (line 4661) and only ever drops a MONEY action (4689-4699,
   lrgDlgActsOnMoney). lrg_factions.php:46-47 states it: "The truth gate never acts on this class". PROTOCOL 10.17 (c): "We cannot
   rewrite her sentence" - true for the post-gate, which runs after the words were spoken (data_functions.php:6022-6037).
3. **The never-empty rail validates emptiness only.** `lrgNeValidate()` (lrg_replies.php:236-268) returns true the moment
   `$msg !== ''` (line 244). Its retry (271-297) and floor (355-387) are reachable only through `LRG_NE_REJECT` / an invalid reply.
4. **Nothing on the game side could have contradicted him**: no command went out (lrg log 3201/3209 `action=none`; chim.log
   "Returning command buffer with 0 commands"), so no funcret, no voiced correction, no journal entry.

## 3. The one place a rail is IN TIME for words: CHIM's stream loop (verified in the installed core)

`HS/lib/data_functions.php:5934-5937` (call_llm_internal):
```
$tmpData=$connectionHandler->process();
if ($tmpData==-1 || (isset($GLOBALS["VALIDATE_LLM_OUTPUT_FNCT"]) && !$GLOBALS["VALIDATE_LLM_OUTPUT_FNCT"]($tmpData))) {
    Logger::warn("Invalid JSON Output."); $outputWasValid=false; $buffer=""; $breakFlag=true;
} else { $buffer.= $tmpData; ... }
```
then (5947-5975) `findFastSentencePosition($buffer)` -> `returnLines($sentences)` (TTS + chat row + ScriptQueue echo, chat_helper_functions.php:1285).
The connector (`HS/connector/openrouterjson.php:1003-1041`) re-decodes its whole buffer on every SSE line, sets
`$GLOBALS["LAST_LLM_RESPONSE"] = $finalData` (line 1038, the PARTIAL message included) and only then returns the message delta.

Consequences, in order, per chunk: (1) LAST_LLM_RESPONSE already holds the message up to and including this chunk; (2) the
validator runs; (3) only if it returned true is the chunk appended and a sentence possibly spoken. So a validator that judges the
partial message REJECTS BEFORE the offending sentence can reach returnLines - the sentence terminator arrives in some chunk, and in
that very iteration the validator sees the complete sentence first. After a rejection: the loop breaks, the "REMAINING DATA"
flush is skipped (`if ($outputWasValid && trim($buffer))`, 6002), processActions is skipped (6022), `close()` runs (6493),
`call_llm()` returns false and `main.php:2823-2827` calls `LLM_RETRY_FNCT` - the seam lrg_replies.php already owns.
What cannot be undone: sentences spoken BEFORE the false one (tonight's "You want to join the Legion?" - truthful, fine).

Two more facts that shape the design:
- **The never-empty path has never fired live**: `grep never-empty lorerim_glue.log` = 0 rows, `grep -c "Invalid JSON Output"
  chim.log` = 0. The reject -> re-ask -> floor chain is proven only by test_gates 36 / flow 31 (offline seams). The never-false
  rail would be its first live use. Keep a `mode` knob (section 5.6) so the owner can fall back to `mute` (no second LLM call).
- The alternative in-time mechanism is `TRANSFORMER_FUNCTION` (per sentence inside returnLines, before TTS; lrgDlgTransformer at
  lrg_dialogue.php:3058-3070 is the existing mute). It can DROP a false sentence without aborting the stream. The task mandates the
  validator -> re-ask -> floor mechanism (her own words beat a canned line, addendum 11); the transformer is the `mute` mode.

## 4. The design: NEVER FALSE (server only, no wire change, no Papyrus change)

### 4.1 Scope - which turns
Only a FACTION turn: `$GLOBALS['LRG_DLG_TURN']['faction']['join']` set (lrgFacTurn, lrg_factions.php:618-651): the ask turn
(recruiter / redirect / none), the carried live turn and the carried rechat turn (the 16:38 double-down came on a rechat, which
lrgNeKind() deliberately does not cover - so the never-false kind is decided by the faction record, not by lrgNeKind: request
type in LRG_PLAYER_SPEECH_TYPES + lrg_dlgtalk + factions.rechat_types, a real NPC, glue on, `never_false.enabled`).
A business pick the game answers itself (lrgNeWillEmitPick) is not exempt here: an intent-mode faction pick is NOT muted
(lrgDlgWillEmit needs a T-key), so her words play before the real line - they must still be true.

### 4.2 Classes, word lists, regexes (per complete sentence; case-insensitive)
Judge = split the partial message into sentences (`[.!?]+` + space/end); judge every terminated sentence on every chunk; judge
the unterminated tail too once the decoded object is COMPLETE (lrgNeComplete, lrg_replies.php:218-234 - in both key orders the
keys after `message` are present only when the message is final; the REMAINING-DATA flush comes after the loop, so this is in time).

Guards, applied first, per sentence: NEGATION (any of: not, n't, never, no one, nobody, nothing, none, without, until, unless,
only after, only once, before that, yet, cannot, can't, won't) -> never a claim ("You are not a Legionnaire yet", "Nothing has
begun", the floor lines themselves). QUESTION (`?` at the end) -> never a claim for classes member / orders.

| class | generic patterns (a few regexes + a word list) | row cells (lrgFacDefaults) |
|---|---|---|
| `member` (enlisted / oath accepted / rank / welcome) | `/\b(?:you(?:'re\| are)(?: now\| hereby)?\|consider yourself\|that makes you) (?:a \|an \|one of )?(?:<ranks>\|one of us\|enlisted\|sworn in\|in the <faction words>)\b/`; `/\bwelcome (?:to\|aboard\|into) the <faction words>\b/`, `/\bwelcome,? (?:<ranks>)\b/`; `/\b(?:your oath is\|i accept your oath\|oath (?:accepted\|taken\|sworn))\b/`; `/\byou(?:'ve\| have) (?:sworn\|taken the oath\|enlisted\|joined)\b/`; `/\bi (?:name\|make\|appoint) you\b/`; `/\byou(?:'re\| are) in\.?$/` | `ranks`: legion [legionnaire, auxiliary, soldier, recruit, imperial soldier]; stormcloaks [stormcloak, soldier]; companions [shield-brother, shield-sister, whelp]; college [apprentice]; dawnguard [dawnguard]; default [member, recruit] |
| `oath` (he administers or sets the oath NOW) | imperative or immediate only: sentence starts with `(swear\|take\|say\|speak\|recite\|repeat)` + `\b(?:your\|the\|an\|this)? ?(?:oath\|vows?)\b`, or the pattern carries `(here\|now\|to me\|before me\|at once\|today)`; `/\brepeat after me\b/`; `/\braise your (?:right )?hand\b/`. "Once Rikke has tested you, you swear the oath" (an explanation of the test) passes: no imperative, no now/here. | - |
| `orders` (a posting / next step / time / place the game did not set) | `/\b(?:report\|present yourself\|go\|get\|head\|proceed) (?:to\|for) (?:the )?(?:training yard\|barracks\|drill\|muster\|fort\|camp\|garrison\|quartermaster\|duty)\b/`; `/\bfor your (?:first \|new )?(?:orders\|posting\|assignment\|duties\|drill\|training)\b/`; `/\byour (?:first \|new )?(?:orders\|posting\|assignment) (?:are\|is\|will be)\b/`; `/\breport for duty\b/`; a TIME `(?:at\|by\|when) (?:dawn\|first light\|sunrise\|sundown\|nightfall\|noon\|midnight\|the bell(?: rings)?)` in the same sentence as `(report\|be there\|meet\|drill\|training\|test\|muster)`; `/\b(?:your\|the) (?:training\|test\|trial\|drill\|service\|enlistment\|quest\|posting) (?:begins\|starts\|has begun\|has started\|is set\|is arranged)\b/`; `/\b(?:the quest\|it) (?:begins\|has begun\|starts) (?:now\|today\|here)\b/` | `next_names`: legion {`legate rikke` -> orders}: `/\b(?:report\|go\|speak\|talk\|present yourself\|see) to (?:legate )?rikke\b/`, `/\brikke (?:will\|'ll) (?:see\|take\|test\|have) you\b/`. Never a claim: the entry name (`general tullius`) - Rikke's truthful "General Tullius first" and "Report to General Tullius" must pass. |

"join" alone, "the Legion needs men", "I want you", "there is a test at Fort Hraggstad", "Rikke tests every recruit" pass (no
claim of state). Tonight's three sentences: "Swear your oath here, or move on." -> oath (imperative start); "Then you are a
Legionnaire." -> member (`you are a legionnaire`); "Report to Legate Rikke at the training yard for your first orders." -> orders
(three hits: `report to ... rikke`, `to the training yard`, `for your first orders`). 16:38's "Report to the training yard at
Castle Dour when the bell rings" -> orders (place + time).

### 4.3 What makes a claim TRUE: the game's own stages, or the quest lane's flag this turn
Per row a `truth` map = class -> {quest -> minimum stage}: legion `orders: {CW00A: 10, CW01A: 1}`, `oath: {CW01A: 160}`,
`member: {CW01A: 200}` (UESP + pt16-legion.md:104). A class is allowed when `$t['facts']['qst'][quest] >= stage` (the facts line
the game sends, lrgDlgFactsFrom lrg_dialogue.php:797-806, copied into the turn at 1584/1596) for any listed quest, OR when
`$t['faction']['executed']` meets it (4.4). A row without a `truth` map allows nothing unless executed. An NPC whose `qst=` lacks
the quest = stage 0 (fail closed; limit noted in section 8).

### 4.4 The contract with the quest lane: `$turn['faction']['executed']`
`$GLOBALS['LRG_DLG_TURN']['faction']['executed'] = ['quest' => 'CW00A', 'stage' => 10, 'how' => 'bridge'|'pick'|'open', 'cid' => <cid>]`
- Absent / `[]` (lrgFacTurn initialises none): nothing was executed - every claim is judged against `qst=` alone.
- Set ONLY by the quest lane, ONLY BEFORE `call_llm()` (inside lrgDlgPrepareTurn's faction block at lrg_dialogue.php:1720-1722, or
  context_pre.php before the injections) - because the words stream before any post-process hook runs, a flag set post-LLM is too
  late for the words and the game's own line must then be the confirmation. It means: "this turn the lane has already queued a
  stage change the game will apply" (the click-free start through CHIM's bridge - AIAgentQuestProgressionBridge.psc:4
  `SetQuestStage(int questFormId, int stage)` - or a do=pick / do=open on the real join entry decided from the PLAYER's words).
- The judge treats it as a stage fact: effective stage = max(qst[quest], executed.stage when executed.quest === quest). With
  executed CW00A:10, "Report to Legate Rikke - she will test you" is allowed (orders), "Then you are a Legionnaire" is still
  rejected (member needs CW01A 200) - which is the truth.
- lrgFacLockedLines() adds, when set, one line: "the game has just recorded: <quest> stage <n> - <meaning from the row: he is sent
  to Legate Rikke> - you may say so", so the confirming sentence is prompted, not merely tolerated.
- Log tail: ` faction=legion:recruiter[:carried][:executed=CW00A:10]` (lrgFacTurnTail, lrg_factions.php:937-944).
Document in PROTOCOL 10.22 (a new bullet) and in the new 10.25.

### 4.5 The three layers, mirroring lrg_replies.php
1. VALIDATOR: in `lrgNeValidate()` (lrg_replies.php:236-268), after the `LRG_NE_REJECT/DONE` and kind checks and BEFORE the empty
   check (line 243): `$bad = lrgNfJudge(); if ($bad) { $GLOBALS['LRG_NE_REJECT'] = ['why' => 'false claim', 'class' => .., 'said' => <sentence>, 'action' => .., 'item' => ..]; lrgLog('never-false npc=.. class=.. said=".." fact=".." verdict=rejected'); return false; }`.
   lrgNfJudge() reads `$GLOBALS['LAST_LLM_RESPONSE']['message']` (partial), `$GLOBALS['LRG_DLG_TURN']` (faction, facts.qst),
   remembers the sentences already judged this turn (`LRG_NF_SEEN`) so each is judged once.
2. RETRY: in `lrgNeRetry()` (271-297): `$why === 'false claim'` -> re-ask ONCE (`LRG_NF_REASKED`) through the existing
   `lrgNeReask()` with lrgNfNudge() instead of lrgNeNudge(); the second reply is judged by the same validator; if it is rejected
   again (`false claim twice`) or nothing came back -> floor.
3. FLOOR: `lrgNeFloor()` (355-387) gets kind `false`: it must NOT take the "she already spoke ... nothing added" exit at 362-367
   (a truthful partial such as "You want to join the Legion?" may already have played - the correction is appended after it), picks
   from `never_false.lines.<role>` (recruiter / redirect / none; optional per-row `floor` cell wins), speaks through lrgNeSay()
   (returnLines: TTS, chat row, ScriptQueue echo, before X-CUSTOM-CLOSE), logs `never-false npc=.. why=false claim twice said=".."`.
   HOOK (lrgNeHook, 336-352): log-only `verdict=late` when a false tail slipped through in the message-LAST key order (the only
   shape the validator cannot judge in time) - the words were spoken, so nothing is added; the next-turn note covers it.
   Next turn (optional, cheap): lrgNfNote() beside lrgNeNote() in lrgDlgVolatileGuidance (lrg_dialogue.php:1955-1958):
   "<npc>'s last reply claimed an enlistment/oath/orders the game had not recorded; it was not said. Nothing has begun."

The re-ask nudge (appended to the last user message, exactly as lrgNeReask does, under IN_FALLBACK_MODE so player TTS is not
repeated; rules, with the model's OWN offending sentence quoted because the task requires it - it is state, not an authored
example): `(Rule for this reply: the sentence '<said>' is false - the game has recorded no enlistment, no oath, no rank and no
orders for <player>; nothing has begun. <npc> may want the recruit and may explain how enlistment really goes (<row.how>), but
may not swear him in, give him a rank, accept an oath, give orders or name where or when to report. [<npc> already said: '<spoken
so far>' - continue from it without repeating it.] Answer in words, in <npc>'s own voice.)`

Floor lines (SPOKEN text, config, owner-editable, <= 90 chars, rotated per NPC like never_empty): recruiter: "Nobody is sworn in
by a word at this table. Enlistment is done properly, or not at all." / "I take no oath here. It is done the proper way." ;
redirect: "That is not mine to grant. Enlistment is done properly, by the ones who take recruits." ; none: "I cannot enlist
anyone. Nothing has begun." When the quest lane's click-free start is possible on that turn, the lane's path runs and the flag
makes the confirming sentence legal - the floor is never reached.

### 4.6 Config (`never_false`, top level, beside `never_empty`, defaults in code `lrgNfDefaults()`)
`enabled true, mode reask|mute|log (reask), classes [member, oath, orders], max_chars 90, note_seconds 120, lines {recruiter, redirect, none}`;
`dialogue.factions.rows.<id>.{truth, ranks, next_names, floor}` (lrgMerge: maps merge, lists replace). `mode=mute` = the
TRANSFORMER path: the false sentence is dropped (returns '') and the floor line is spoken by the hook - no second LLM call, never
aborts a stream (the safe fallback if the first live re-ask misbehaves). `mode=log` = today's behaviour plus the log line.

### 4.7 Log
`never-false npc=<n> class=<member|oath|orders> said="<sentence, 60>" fact="<quest>:<stage>[/executed]" verdict=rejected|allowed(stage)|allowed(executed)|late|log-only`
and `never-false npc=<n> why=false claim reask=spoke chars=<n>` / `why=false claim twice said="<line>"`. `grep never-false` = how often the model does it.

## 5. The prompt-side rule (lrg_factions.php lrgFacRule, 771-783) - concrete and short
Replace the body (keep the pt17 endings intact: "Answer what he says, in words." / the carried clause; 13(i) asserts them):
```
You MAY: say you want him, that the Legion needs men, and how enlistment really goes (<row.how>).
You MAY NOT: swear him in, accept or administer an oath, give him a rank (recruit, auxiliary, soldier,
legionnaire), give orders, invent a place, time, drill or test, say he is in, welcome him to the Legion, or say a
quest or training has begun - the game recorded none of it; only what is stated above is true.
```
(~330 chars, replacing ~250; the ask-turn block stays under test_dialogue 13(c)'s 900-char bound - the rule sits outside the
600-char body cap, lrgDlgLockedBlock 4636-4638.) Ranks and the faction name come from the row (`ranks`, `name`) so the same rule
reads right for the Companions or the College. On an `executed` turn the MAY list gains "and say what the game just recorded".

## 6. Files to touch (smallest complete fix, all under glue/)
- NEW `server/lorerim_glue/lib/lrg_truth_words.php` (~250 lines): lrgNfDefaults/lrgNfCfg (config + `LRG_NF_TEST_OVERRIDE` seam),
  lrgNfKind(), lrgNfSentences(), lrgNfJudge() -> `['class','said','fact']|null`, lrgNfNudge(), lrgNfFloorLine(), lrgNfTransformer()
  (mode mute), lrgNfNote(). Pure functions over `$GLOBALS['LAST_LLM_RESPONSE']` and `$GLOBALS['LRG_DLG_TURN']`.
- `lib/lrg_replies.php`: require the new file; lrgNeValidate (+6 lines before the empty check), lrgNeRetry (the `false claim`
  branch, +8), lrgNeFloor (kind `false`: skip the already-spoke exit, pick from never_false.lines, +10), lrgNeHook (late log, +4),
  lrgNeRegister (chain the mute transformer when mode=mute, +5). Header comment: one paragraph.
- `lib/lrg_factions.php`: legion row `truth`, `ranks`, `next_names` (+ ranks on the other rows); lrgFacTurn: `'executed' => []`
  in both return shapes; lrgFacLockedLines: the executed line; lrgFacRule: section 5; lrgFacTurnTail: `:executed=Q:S`.
- `lib/lrg_dialogue.php`: lrgDlgVolatileGuidance: `lrgNfNote()` beside lrgNeNote (1955-1958). Nothing else.
- `config/lrg_config.default.json`: `_never_false_readme` + `never_false` block after `never_empty` (line 121). UTF-8, LF.
- `context_pre.php` / `functions.php`: no change (lrgNeRegister() already runs on every request; the new file rides its require).
- `PROTOCOL.md`: 10.22 gains the `executed` bullet; new 10.25 "NEVER FALSE" (the loop-order proof of section 3, the classes, the
  contract, config, log, tests). `README.md`: one line. Version markers: none (server only; no wire, no Papyrus - CurrentVersion stays).
- Tests: `tools/test_gates.php` section 37 (use section 36's $neSchema/$neReply/$neReset seams + a faction turn built like
  test_dialogue 13's `$ft()` set into `$GLOBALS['LRG_DLG_TURN']`): (a) the three Tullius sentences, streamed as partials
  ("Then you are a Legionn" no verdict; "Then you are a Legionnaire." rejected with class=member, LRG_NE_REJECT.why='false claim';
  "You want to join the Legion? Swear your oath here" rejected with class=oath; "Report to Legate Rikke at the training yard for your
  first orders." -> orders); (b) truthful lines pass: "General Tullius first, at the map table in Castle Dour.", "You are not a
  Legionnaire yet - Rikke tests every recruit at Fort Hraggstad.", "Nothing has begun.", "The Legion needs men. I want you.";
  (c) executed CW00A:10 -> "Report to Legate Rikke; she will test you." passes (allowed(executed)) while "Then you are a
  Legionnaire." is still rejected; qst CW01A:200 -> "Welcome to the Legion, soldier." passes (allowed(stage)); (d) the retry: the
  re-ask nudge quotes the sentence and the rule and (after a spoken truthful partial) the already-said text; a second false reply
  -> ONE floor line from never_false.lines.recruiter appended AFTER the spoken partial, log `why=false claim twice`; (e) not a
  faction turn -> no verdict ("you're a soldier" said by an innkeeper on an ordinary turn passes); rechat carry IS judged;
  mode=mute drops the sentence via the transformer and floors; mode=log only logs. `tools/test_dialogue.php` 13 (k): the new rule
  wording, ranks/how from the row, executed locked line, the 900-char bound, no `<>` (13(i)'s no-`"` check stays for the rule; the
  NUDGE test allows the single-quoted model sentence). Run: WSL over a copy under C:\Users\Jordan\AppData\Local\Temp\lrg_test\words\.

## 7. Cost
No new LLM call on an ordinary turn; the judge is a handful of regexes over <= 500 chars per chunk. On a false claim: one
aborted stream + one re-ask (~1.5-2 s under the MAIN lock) and, only if that is false too, one short TTS line.

## 8. Risks and open questions
1. First live use of the reject -> re-ask path (never fired in production; section 3). Mitigation: `mode` knob; in-game check with
   the exact three lines; the log lines make every verdict visible.
2. Sentences spoken before the false one stay spoken (by design); the nudge tells the model what was already said.
3. The message-LAST key order (reorder_json off + a model that writes message last) can only be logged (`late`) for a
   terminator-less tail; terminated sentences are still caught in time.
4. False negatives are tolerated by design (negation arriving after the phrase, unusual phrasings); false positives are limited to
   the listed patterns on faction turns; a truthful claim on a REDIRECT whose `qst=` lacks the quest (a guard welcoming a real
   legionnaire) would be rejected - option: consult the row's recruiters' cached facts (`lrgDlgState('General Tullius')['facts']['qst']`)
   as a second stage source. Left out of the smallest fix; noted for the builder.
5. PROTOCOL 0 ("no example lines"): the nudge quotes the model's own sentence and the tests quote the three real Tullius lines -
   real output being forbidden, not authored dialogue. Single quotes, so 13(i)'s no-`"` rule wording check keeps its meaning.
6. Open: does the quest lane decide the click-free start from the player's words BEFORE call_llm() (then the flag works as
   specified) or only after the model's reply (then the words of that turn cannot confirm it; the game's own line must)? Open: an
   MCM toggle (bNeverFalse:Truth) - config-only now, MCM later (config.json + settings.ini + test_mcm_wiring). Open: extend the
   classes to non-faction quest-start claims ("the quest begins") on quest turns - the word lists are built to take a fourth class.

## 9. BUILD (implementer, 2026-09-24) - what was built, and where it departs from sections 4-6

Files touched (all under glue/, backups in glue/.backup/pt18-words/): `server/lorerim_glue/lib/lrg_replies.php` (the never-false
section at the end + the seams), `lib/lrg_factions.php` (lrgFacLockedLines wording, the executed line, lrgFacRule's executed
clause, lrgFacRecruiterWho), `config/lrg_config.default.json` (`_never_false_readme` + `never_false` after `never_empty`; UTF-8,
no BOM, LF), `tools/test_gates.php` (section 36 (n)-(x), 49 checks), `tools/test_dialogue.php` (13 (k), 14 checks),
`tools/flows/scenarios/31_never_empty.php` (step 7, 14 checks). NOT touched (file ownership): lrg_dialogue.php (lrgNfNote rides
lrgNeNote instead, which lrgDlgVolatileGuidance already calls), lrgFacTurn / lrgFacTurnTail / the faction table rows (the quest
lane's - patches in the build report), PROTOCOL.md / README.md (text in the build report). No new file: the plan's
`lrg_truth_words.php` lives inside lrg_replies.php, which already owns the three seams.

### 9.1 The refuters' must_change items, and how each was honoured
1. **Citations** - the correction block at the top of section 1 (line numbers, pt17-switches.md:104, TIF__000D5136 INFERRED,
   CHIM AI Quest Progression off, x-ai/grok-4.3). `how=bridge` struck: `executed.how` is `pick|open` only.
2. **The truth source** - a quest absent from every source is UNKNOWN: the claim passes with `verdict=unknown` (logged), never
   stage 0. Sources, freshest per quest: the facts line `qst=` (facts.at), `$turn['faction']['executed']` (this turn, wins),
   a persisted `lrgDlgState(npc)['exec_qst'] {quest, stage, at, cid}` while `at` > facts.at (SendFacts is throttled 60 s and
   never sent while a session is open - refuter 2's staleness point), and the row's recruiters' cached facts lines / exec_qst
   within `never_false.cache_seconds` (1800) - quest stages are global, so Tullius's line ten seconds ago is truth on Aldis.
   `derive` closes the one hole UNKNOWN would leave open tonight: CW01A / CW02A are at 0 while CW00A runs below 10, and also
   when OUR OWN pick has just set CW00A to exactly 10 (src executed/exec) - so at 23:19 "Then you are a Legionnaire." is
   FALSE (`CW01A:0(derived:CW00A:0<10)<200`), and after a real enlistment (CW01A gone from qst=, CW02A running) "Welcome to
   the Legion" is TRUE via CW02A:10 - never the mirror-image lie. The six-slot cap (QstCsvOf `used < 6`, lrgDlgFactsFrom
   `count($qst) < 6`) is documented for PROTOCOL 10.25; the game-side change (row quests first in QstCsvOf, CurrentVersion
   510) is proposed to the orchestrator, not made here.
3. **The truth map itself, corrected from UESP** (https://en.uesp.net/wiki/Skyrim:Joining_the_Legion): CW01A stage 1 is
   "Clear out Fort Hraggstad" (Rikke has set the test), NOT "Tullius sends the player to Rikke"; 100 = "Report to Legate
   Rikke"; 160 = "Take the oath"; 200 = done. https://en.uesp.net/wiki/Skyrim:The_Jagged_Crown_(Imperial): CW02A stage 10 =
   the first assignment. So: `next {CW00A:10, CW01A:100, CW02A:10}`, `oath {CW01A:160}`, `member {CW01A:200, CW02A:10}`,
   `allow {'fort hraggstad|the fort|the bandits' => {CW01A:1}}`. The plan's `orders {CW00A:10, CW01A:1}` was wrong twice over.
4. **`orders` split from `next`** (refuter 1 item 6): `next` = the recruiter's next step BY NAME ("report / go / speak to
   (Legate) Rikke", "Rikke will see you"), judged by stage, RECRUITER only - a redirect's / a guard's "speak to Legate Rikke in
   Castle Dour" is his own real line and is EXEMPT (refuter 2 item 4). `orders` = an appointment the game never records
   (training yard, barracks, drill, muster, quartermaster, "for your first orders", "report for duty", a time + a report
   verb, "your training begins", "clear out the fort"): FALSE whatever the stage and whatever the role, unless the row's
   `allow` map names the phrase for a stage. So at CW00A:10 "Report to Legate Rikke; she will test you." passes and "Report to
   Legate Rikke at the training yard for your first orders." is still rejected (test_gates 36 (p)). The 16:38 Aldis line is
   rejected on a redirect with no CW quest anywhere (36 (n)).
5. **Guards** (refuter 2 item 4): negation anywhere (not / never / nobody / nothing / none / without / until / unless / only
   after / yet / cannot / every n't contraction / "no oath|rank|orders|..."), a conditional at the START of the sentence or of a
   clause (if / once / when / should / unless / provided / after / "so long as"), a modal of doubt anywhere (would / could /
   might / perhaps / maybe / one day), a question mark. "when" MID-sentence is not a guard on purpose: "Report to the training
   yard at Castle Dour when the bell rings" must stay caught. Every shipped floor line passes the guard (36 (x)).
6. **Coverage** (refuter 2 item 3): the judge runs on every player-speech / lrg_dlgtalk / rechat turn where the dialogue turn
   is on and lrgFacRoles() gives this NPC a recruiter or redirect role for a row, OR a faction ask is on the turn (role none
   included) - not only inside the 90 s ask window. A rechat has kind '' in lrgNeKind() and lrgNeRetry() bailed there: the
   false-claim branch now runs BEFORE that bail, so a rechat is rejected, re-asked and floored (36 (r)). The refuter's
   "mute on rechat" was NOT taken, for a verified reason: main.php:1078 switches functions off for a rechat, so no
   post-process hook runs and a muted sentence would have no floor - silence, an addendum 11 defect. Functions-off turns
   always take the reject shape (36 (u)).
7. **A pick must never die with the words** (refuter 1 item 5): the SHAPE of the reply decides the layer, once per request
   (`LRG_NF_SHAPE`): a reply whose action is a dialogue pick (LRG_DLG_GATE_NAMES), or whose action is not known yet (the
   character-first key order), or `mode=mute`, is never rejected - lrgNfTransformer(), chained ONCE per process onto CHIM's
   TRANSFORMER_FUNCTION (context_pre.php then chains lrgDlgTransformer onto ours, so ours runs first), drops the false
   sentence inside returnLines (no TTS, not in talkedSoFar), the stream and the pick go on, and lrgNfHook() speaks the floor
   line after the stream - idle when an emitted pick answers the turn itself (36 (t), (u); the transformer chain order is
   asserted in flow 31 step 7). Everything else (a live turn, the action known and not a pick; every functions-off turn) takes
   the reject shape: the validator stops the stream in the chunk that completed the sentence.
8. **The nudge overrides CHIM core's roleplay instructions** (refuter 2 item 7): "The game, not the story, decides what has
   happened, and it has recorded no enlistment, no oath, no rank and no orders for <player> - his words do not make it so;
   nothing has begun." + the row's `how` + the ranks + what she already said + "Answer in words, in <npc>'s own voice."
   Single quotes only (13(i)'s no-`"` check keeps its meaning; 36 (q) and flow 31 assert it).
9. **The recruiter's ml=0 line** (refuter 1 item 4, refuter 2 item 7): no more "settled in your own dialogue with him, not in
   this talk" and no more "(the glue is still learning ... 0 of 10 ...)" in the prompt. Now: "you are one of those who take
   recruits for the Imperial Legion (General Tullius first, then Legate Rikke, both in Castle Dour in Solitude) - but not by
   words: the game records an enlistment only when he takes your real entry in your own dialogue menu, and it has recorded
   none, whatever he says or swears here; nothing has begun - tell him so plainly in one line (he must come to you the
   proper way), promise nothing, and never swear him in, take his oath, give him a rank, orders, a place, a time, a drill or
   a test" (test_dialogue 13 (k)). The learning counter stays in the `dlg ml=0` log line. The pt17 Rikke-before-Tullius line
   and the ml=1 line are unchanged (they were truthful and worked).
10. **"Say exactly why not"** (refuter 2 item 6): every `never-false` verdict line carries `ml=<0|1> cal=<n|-> ambient=<0|1>
   open=<0|1> type=<t>` - tonight's would read `ml=0 cal=0 ambient=1 open=0`; the floor lines name the real reason in her
   words (nothing is settled by talk here / done the proper way / not mine to grant). A per-ml0 floor variant was not added:
   the WHY for the owner is the log line, the in-game words are hers.
11. **The default mode** (refuter 2 item 8): `reask` stays the default (addendum 11: her own words), with three safety rails:
   the mute shape wherever a rejection could cost an action, the reject shape wherever no hook could floor, and the
   `mode=mute` knob (no second call, ever) for the owner if a live re-ask misbehaves. The offline tests drive the whole
   reject -> LLM_RETRY_FNCT -> re-ask -> floor chain through the real hook files (flow 31 step 7) with a stub connector; the
   real call_llm() re-entry cannot run without CHIM and stays the one untested seam (risk 8.1).
12. **lrgFacRule did NOT grow** (the plan's MAY / MAY NOT rewrite): test_dialogue 13(c)/(i) hold the redirect ask-turn block
   under 900 chars and it sits at 898 (measured), so the rule keeps its exact length; the concrete prohibitions live in the
   recruiter's line, in the nudge and in the validator that judges the words themselves. The rule gains one clause ONLY on an
   executed turn ("What the game has just recorded (stated above) you may say - that, and nothing beyond it.").

### 9.2 The contract with the quest lane (for PROTOCOL 10.22 / 10.25)
- `$GLOBALS['LRG_DLG_TURN']['faction']['executed'] = ['quest' => 'CW00A', 'stage' => 10, 'how' => 'pick'|'open', 'cid' => <cid>]`
  is READ by the judge and by lrgFacLockedLines / lrgFacRule; nothing in this lane writes it. It must be set BEFORE call_llm()
  (inside lrgDlgPrepareTurn's faction block, or context_pre.php before the injections) - the words stream before any post-process
  hook runs. `how=bridge` does not exist (dialogue-engine.md:554).
- `lrgDlgState(npc)['exec_qst'] = ['quest', 'stage', 'at', 'cid']` is READ while `at` is newer than `facts.at` and within
  `cache_seconds`; the quest lane writes it when the game's result confirms the pick played (funcret), never on emission alone.
- lrgFacTurn should initialise `'executed' => []` in both return shapes and lrgFacTurnTail should append `:executed=<Q>:<S>`
  (exact patches in the build report); the judge and the lines already tolerate an absent key.

### 9.3 Tests (all green on the WSL copy under lrg_test/words: test_gates 585/0, test_dialogue 403/0, flows 80/80, 1432 checks)
test_gates 36 (n) the three exact Tullius sentences streamed as partials (the terminating chunk is rejected; a partial and a
question + tail get no verdict), the log line with ml=0 cal=0 ambient=1 open=0, the 16:38 Aldis line on a redirect, the
guard's exempt redirect; (o) seven truthful lines incl. the shipped floor lines; (p) executed, CW02A, unknown, cache, exec_qst,
the fresher facts line; (q) the nudge and the two retry outcomes; (r) the rechat carry; (s) an ordinary turn; (t) mode mute;
(u) the pick / unknown-action / functions-off shapes; (v) mode log; (w) the note beside the never-empty note; (x) config + rows.
test_dialogue 13 (k): the ml=0 line, the executed line and clause, the bounds. Flow 31 step 7: the whole chain through the real
hook files (preprocessing.php ev=facts, functions.php, context_pre.php): reject -> nudge -> floor after the partial -> the
note next turn -> a truthful reply passes -> three post-LLM filters unchanged.

### 9.4 Risks added or changed by the build
- A negation or a conditional anywhere in the sentence makes it "no claim" (false negatives are tolerated by design): "You are a
  Legionnaire now, not a farmhand" passes. A modal ("perhaps you will be a Legionnaire one day") passes too.
- `member` on a role with NO CW quest anywhere (a guard, nothing cached, no ask to a recruiter this session) is UNKNOWN and
  passes with the log line - the price of never denying a real enlistment. The recruiters' cache closes it the moment the
  player has spoken to Tullius or Rikke.
- The staged flow copy shows an ORDERING artefact unrelated to this lane: run with `--only=31,16` scenario 16 leaves
  `talkedSoFar` set and flow 31 step 2 then fails; `--only=31` and the full suite pass. fxReset() (adapter.php, not mine)
  could unset `talkedSoFar`.
