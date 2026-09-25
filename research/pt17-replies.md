# pt17 - replies lane: Captain Aldis returned an empty reply (cid s1d2b4c88, 2026-09-23 20:10:31 -04:00)

Investigator notes. No file under glue/ was edited. Log contents below are DATA.

## 1. What happened, end to end (one turn)

| when (-04:00) | where | fact |
|---|---|---|
| 20:10:19 | lorerim_glue.log:3101-3109 | Aldis, "sorry i meant to say i would like to join the legion": dlg turn `faction=legion:redirect`, `dlg lock ... facts=3 chars=564 classes=faction,quest`, reply spoke=98 ("You speak of joining the Legion. Did you approach General Tullius and Legate Rikke in Castle Dour?") |
| 20:10:31 | AIAgent.log:6561 | player, voice: `utterance='i didn t uh it says it s locked'` crosshair=00041FB9 responder='Captain Aldis' (an aside about the locked Castle Dour door, 12 s after the ask) |
| 20:10:31 | lorerim_glue.log:3110-3115 | Phase 1: `turn ... type=inputtext mode=silent ... intent=none`, `gate ... mode=silent reasons=child_nearby wit=3/0`. Phase 2 (cid d704388b0): `dlg turn ... list=none ... on=1 locked=3 faction=legion:redirect:carried`, `dlg json template: action/target/item ahead of message`, `dlg lock npc=Captain Aldis facts=3 chars=564 classes=faction,quest` - the carried block is byte-identical to the ask turn's block (564 chars both) |
| 02:10:31 +02:00 | chim.log:17894-17930 | MAIN lock by inputtext; `[PROMPT-COMPOSITION] ... total_characters 28062, plugin_injections 266`; PRE LLM CALL |
| 02:10:32-33 +02:00 | output_from_llm.log:4323-4337 | **Request ID 850** (audit_request rowid), model `x-ai/grok-4.3` through connector `openrouterjson`, strict `json_schema`: `{"action":"Talk","target":"Jordan","item":"","character":"Captain Aldis","listener":"Jordan","message":"","mood":"assertive","lang":"en","emotion":"calm","emotion_intensity":"moderate","amount":0}` - **message is the empty string**; llm_complete 1255 ms |
| 02:10:33 +02:00 | chim.log:17945-17947 | `openrouterjson: Prepared command payload for Talk` / `Returning command buffer with 0 commands` / lock released |
| 20:10:33 | lorerim_glue.log:3116 | `llm npc=Captain Aldis action=none item="" spoke=0` (lrgLogLlm, lib/lrg_actions.php:3061) - the glue SAW it and did nothing |
| 20:10:33 | output_to_plugin.log (tail) | `Player|ScriptQueue|I didn't uh it says it's locked.//__player_text_only///1.0` followed by NO Aldis line |
| 20:10:33.153 | AIAgent.log:6638 | `End message because X-CUSTOM-CLOSE` - nothing was spoken |

The exact prompt of request 850 is context_sent_to_llm.log:23302-23788 (var_export of the payload). Relevant content:
- system prompt: CHIM's persona blocks, then `<locked_facts>` (the glue's class `faction` + `quest` lines, lib/lrg_dialogue.php:4479-4503 + lib/lrg_factions.php:654-718) INSIDE `<character>` (priority 205, context_pre.php:56). No `<real_business>` (list=none -> lrgDlgStaticGuidance returns '' at lib/lrg_dialogue.php:1817) and no prompt_bottom text from the glue; Phase 1 injected nothing (mode silent, child_nearby).
- history: the two Legion lines and Aldis's answer, then `# Jordan, speaking to Captain Aldis: I didn't uh it says it's locked.`
- user lines: CHIM's `Write Captain Aldis's next dialogue line. Be original, creative ...` + the JSON text template with `"message":"lines of dialogue"`.
- `response_format` = strict json_schema (`'strict' => true`, `additionalProperties false`), properties ordered **action, target, item, character, listener, message, mood, amount, lang, emotion, emotion_intensity** - the glue's decision D3 reorder (lrgDlgJsonTemplate, lib/lrg_dialogue.php:2881-2919, `reorder_json` default true at :62). `message` is `{"type":"string","description":"lines of Captain Aldis's dialogue"}` - **no minLength**, so `""` is schema-valid.
- max_tokens 750, temperature 0.75.

## 2. Why nothing reached the game (CHIM core path, verified in the installed HerikaServer)

1. `lib/data_functions.php:5931-5979` (call_llm_internal stream loop): the connector's `process()` returns the message delta; with message `""` the buffer never reaches INCREMENTAL_SENTENCESIZE, `returnLines()` is never called from the loop.
2. `:5980 if ($outputWasValid && trim($buffer))` - false, so the "REMAINING DATA" `returnLines()` is skipped too. `$talkedSoFar` stays empty.
3. `:6028-6038` processActions -> `action_post_process_fnct_ex` hooks run (the glue's lrgPostProcessActions, lrgDlgPostProcessActions, ...); `:6483` echoes the command lines (none); `:6497 return $outputWasValid` = **true** (the JSON was valid).
4. `main.php:2823-2827` - `LLM_RETRY_FNCT` is consulted only when call_llm returned FALSE; nobody in core or ext sets it anyway (grep: only main.php:2825-2826).
5. `main.php:2830-2846` - `sizeof($talkedSoFar)==0 && sizeof($alreadysent)==0` -> the "Fail request? or maybe an invalid command was issued" branch only inserts a `log` row.
6. `main.php:2922` echoes `X-CUSTOM-CLOSE`. The ext `prepostrequest.php` / `postrequest.php` hooks (main.php:2944-2946) run AFTER the close marker - too late to speak.

So: CHIM core has **no never-empty rail** for a valid JSON with an empty message, and neither does the glue. The glue's only word-checks are R10 (a SELF-initiated glue change with no spoken line is dropped, lib/lrg_actions.php:2129-2135) and the truth gate (drops a money ACTION on an unconfirmed number, never touches words, lib/lrg_dialogue.php:2354-2385; `$reply === ''` -> skipped entirely). The mute transformer (lrgDlgTransformer, :2922-2935) never ran because returnLines never ran.

## 3. Was it the carry, the template, the truth gate, or the model?

- **Truth gate**: no. It only ever removes an action on a price/destination claim; on this turn `$reply` was '' so lrgDlgTruthCheck was not even called.
- **Transformer / mute**: no. Never invoked (see section 2). Note for the fix: on a business turn whose pick WILL be emitted the mute deliberately returns '' and `lrgSpokenThisTurn()` is then '' BY DESIGN - a rail keyed on talkedSoFar alone would misfire there; it must key on the RAW `$GLOBALS['LAST_LLM_RESPONSE']['message']`.
- **JSON template (D3, action ahead of message)**: contributing shape, not proven cause. The model decides `action` before it writes words. Of the 17 empty-message replies in output_from_llm.log, 12 are in the action-first (glue-reordered) shape; 618 was character-first and still empty, so reorder is not the sole cause.
- **The carried faction text**: plausible contributor, not provable from one sample. On the carried turn the block is identical to the ask turn's: "you may not set a time, a place ... Whether he can join right now you do not know unless the game says so" + "Never invent an appointment, a meeting time or place ... never say ... has begun". The player's aside was PRECISELY about the forbidden ground (Castle Dour access / whether he can join now). The block is all prohibition and never says "answer him in words anyway"; nothing tells the model the player has moved on from the ask. Under "say you are not sure rather than name a place" the safest completion for a model that has committed to `Talk` and finds every sentence about the door blocked is `""`.
- **The model / connector**: the primary cause. **17 of 322 logged replies (5.3 %) since the log began have `"message": ""`** (output_from_llm.log, awk over Request IDs): ids 204 209 213 215 224 255 462 465 470 481 494 548 604 618 670 706 850 - Lisette 13x, Beirand 2x (670 Trade_Items, 706 Take_Up_Business), Aldis 1x; actions Talk (465 494 548 604 850), Follow_Jordan (470 481 618), Come_Closer (462), Change_Intimacy (209), Change_Clothing (255), and 4 compact scene-turn replies (204 213 215 224). 16 of the 17 carried no faction text at all. Same model (grok-4.3 via openrouterjson, strict schema). The glue's own log agrees: 12 `spoke=0` lines, 10 of them `action=none` on `type=inputtext` (lorerim_glue.log:1500,1516,1532,1578,1612,1663,1977,2122,2603,3116).

Verdict: a recurring model behaviour that nothing catches; the faction carry and the action-first schema made THIS turn a likely candidate.

Not a contributor: dry run (ml=0 affects business only; the turn had no business), the stale prompt index (the faction lines come from lrg_factions.php's table, not the index), source/deployed drift (every hook and lib file compared identical, `cmp`), the deployed config override (95 bytes, only Lisette min_affinity).

## 4. Where a rail CAN act (ext hooks, verified)

| hook | sees the message? | can still speak? | verdict |
|---|---|---|---|
| `VALIDATE_LLM_OUTPUT_FNCT($chunk)` (data_functions.php:5937) | per streamed delta; `""` is normal mid-stream; the connector object is not passed, no "done" flag | returning false discards the model's action and marks output invalid | cannot detect a FINAL empty message; unusable |
| `LLM_RETRY_FNCT` (main.php:2825) | only after an INVALID output | yes (before :2922) | not reached for a valid JSON; worth registering anyway (an invalid-JSON reply is silent today too) |
| `action_post_process_fnct_ex` (data_functions.php:6036) | yes: `$GLOBALS['LAST_LLM_RESPONSE']` (openrouterjson.php:1038; every JSON connector sets it), `$GLOBALS['talkedSoFar']`, `$GLOBALS['LRG_TURN']`, `$GLOBALS['LRG_DLG_TURN']` | yes: `returnLines([$line])` (chat_helper_functions.php:1285) runs TTS (:1595), appends `$talkedSoFar` (:1635), writes the `chat` eventlog row (:1880-1905), echoes the ScriptQueue line (:1836) - all BEFORE the command echo (:6483) and X-CUSTOM-CLOSE (main.php:2922) | **the place** |
| `TRANSFORMER_FUNCTION` | per sentence, never called when there is none | - | no |
| ext `prepostrequest.php` / `postrequest.php` | yes | no (after X-CUSTOM-CLOSE) | no |
| `PROMPTS[...]['cue']` / injections | prompt side | prevention only | yes, as the second layer |

A second LLM call from inside the hook: a recursive `call_llm_internal()` is what CHIM's own fallback does (data_functions.php:5842-5850), but from inside the post-process hook the OUTER call still echoes its own actions afterwards (double command lines), reruns player TTS, and re-enters DEBUG_DATA - not safe. A glue-owned mini-loop on the connector (`getConnector()->open(); process() until isDone(); close()`) is possible and gives real words, but it is ~50 lines against connector internals and adds ~1.5-2 s on the 5 % of turns; it is an OPTIONAL layer on top of the guaranteed floor below, not the floor.

## 5. The fix (server only, inside glue/)

### 5.1 NEVER-EMPTY rail (new `lib/lrg_replies.php`, registered from `functions.php`)
- Register ONE closure in `$GLOBALS['action_post_process_fnct_ex']` at brace depth 0, LAST of all (after lrgFollowerPolicy, like the hold / follower policies: it has to see both lanes' final output and must run under SHARMAT too). Also `$GLOBALS['LLM_RETRY_FNCT']` = the same function with why=`invalid output` (main.php:2825 - the invalid-JSON silence). Guard `$GLOBALS['LRG_NE_DONE']` so it runs once per request.
- Fires only when ALL hold: request type in `LRG_PLAYER_SPEECH_TYPES` or `lrg_dlgtalk` (or a funcret whose Phase 1 mode is `voiced`); `HERIKA_NAME` is a real NPC (not '', 'Player', 'The Narrator'); `is_array($GLOBALS['LAST_LLM_RESPONSE'])` and `trim((string) $LAST_LLM_RESPONSE['message']) === ''` (the RAW model text - a muted business pick has a non-empty raw message and must NOT trigger it); `lrgSpokenThisTurn() === ''`; Phase 1 mode is not `scene` / `outro` (inside a scene a canned line is worse than a breath; log only there - owner-flippable via config); `never_empty.enabled` (default true).
- Then: speak ONE short line through `returnLines([$line])` when `function_exists('returnLines')`, else through the test seam `$GLOBALS['LRG_TEST_SAY']` (offline tests run without CHIM); the line is chosen from `never_empty.lines.{question|statement}` (short, neutral, non-committal, never pretends to have answered; question = the player's words end in `?` or start with a question word; rotated per NPC through lrg_memory so the same line is not heard twice in a row); log `never-empty npc=<n> action=<code> why=<empty message|invalid output> said="<line>"` with the cid; write `lrg_memory.empty_reply {at, cid, n}` (count for the owner's greps).
- Next turn (prevention, rules only): Phase 2's `lrgDlgVolatileGuidance()` (lib/lrg_dialogue.php:1828) gains a last part when `empty_reply.at` is within `never_empty.note_seconds` (120): "<npc>'s last reply carried no words at all. Whatever the player says now, <npc> answers in words: one or two short spoken sentences, then the action if any." Cleared once told.
- Config block (`config/lrg_config.default.json`, top level `never_empty`): `enabled true, in_scene false, note_seconds 120, max_chars 60, lines {question: [...], statement: [...]}`.
- Order within the batch: `returnLines` from the hook emits the ScriptQueue line before CHIM echoes the command lines (data_functions.php:6483) - the owner's "she says a line before starting anything herself" holds for Follow_Jordan / Come_Closer / Trade_Items / a glue command alike.
- Barter path (lrgDlgServiceNet) untouched: the rail only ADDS a spoken line after every filter has run; OpenInventory still goes out.

### 5.2 Prompt rules (prevention, rules only - PROTOCOL section 0)
- `lrgDlgJsonTemplate()` (lib/lrg_dialogue.php:2892-2900): when it rewrites item/target descriptions, also append to `message`'s description: "Never empty: at least one short spoken sentence, even when the action says it all." Same for `$GLOBALS['responseTemplate']['message']` (the text template). NO `minLength` in the strict schema (OpenAI-style strict mode rejects unsupported keywords; xAI's behaviour unverified) - a description is safe, a keyword is not.
- `lrgFacRule()` (lib/lrg_factions.php:712-718): end with "Answer what he actually says, in words, either way." On a CARRIED turn (`$t['faction']['carried']`), `lrgFacLockedLines()` prefixes the first line with "(background to what he asked a moment ago, not what he is saying now) " and the rule says "his words now may be about something else - answer them". This is the "carry must never make an unrelated line answerless" half.
- Optional, cheap: `lrgDlgLockedBlock()` header (lib/lrg_dialogue.php:4497-4500) keeps its wording; add nothing there (the faction rule already ends the block).

### 5.3 Tests
- `tools/test_gates.php` new section: (a) raw message '' on inputtext -> exactly one line via `LRG_TEST_SAY`, `talkedSoFar` now non-empty, log line, `empty_reply` memory; (b) raw message '' + a CHIM action (Follow) -> the action still passes AND a line is spoken; (c) non-empty raw message but `talkedSoFar` empty (the muted business pick) -> NO line; (d) `lrg_scenetalk` / `rechat` / `funcret` (not voiced) -> no line; (e) mode scene -> no line, log only; (f) `LLM_RETRY_FNCT` registered and speaks with why=invalid output; (g) two consecutive empties -> two different lines; (h) config `enabled=false` -> silent as before (log only).
- `tools/test_dialogue.php` section 13 additions: the carried lines carry the "(background ...)" prefix and the rule ends "answer ... in words"; the ask turn's block does not; `lrgDlgJsonTemplate()` extends the `message` description on a speech turn and leaves the schema keywords alone (no minLength); `lrgDlgVolatileGuidance()` carries the empty_reply note inside note_seconds and not after.
- Flow scenario `tools/flows/scenarios/31_never_empty.php` through the real hook files: player line -> LLM answers `{"action":"Talk","message":""}` -> one ScriptQueue line reaches the game; the barter turn with words -> rail idle and OpenInventory unchanged; adapter: `fxSpoke()` (tools/flows/adapter.php:320-323) must also set `$GLOBALS['LAST_LLM_RESPONSE']['message']` (today only talkedSoFar), and the harness needs the `LRG_TEST_SAY` seam.
- `tools/test_mcm_wiring.php` unaffected (no MCM change). No Papyrus change (CurrentVersion stays 508).

### 5.4 Docs
- PROTOCOL.md: new 10.23 "NEVER EMPTY - the substituted line, the invalid-output retry hook, the carried faction wording"; 10.21 gets a pointer; 10.22 notes the carried prefix and the rule's last sentence.

## 6. Side findings (not this lane, worth a ticket)
- `lrgSpokenThisTurn()` (lib/lrg_actions.php:2396-2404): the documented DEBUG_DATA['response'] fallback is dead - CHIM stores an ARRAY of {raw, processed} there (data_functions.php:5964), and the function returns '' for anything not a string.
- An invalid-JSON reply (`Invalid JSON Output` at data_functions.php:5938) is silent today for the same reason; 5.1 covers it through LLM_RETRY_FNCT.
- The `dlg ml=0: menuless questing is off (or in dry run)` line fired on every turn tonight; the orchestrator already reset bDryRun.

---

# Implementer pass (2026-09-23 21:40-22:15 -04:00) - what was changed and why

File ownership for this lane was `lib/lrg_factions.php`, the faction / quest prompt text, `tools/test_dialogue.php`
section 13 (append only) and this note. Everything else the fix needs is delivered as ONE tested patch,
`research/pt17-replies.patch` (unified diff against the source tree as of 22:10; `patch -p1` from `glue/`; also pasted
into the lane's `papyrus_patch_for_orchestrator`). It was built and run on a WSL copy of the source tree
(`C:\Users\Jordan\AppData\Local\Temp\lrg_test\replies\glue_work`), then applied to a FRESH copy of the source tree
(`glue_verify`) and run again. No Papyrus, MCM or wire change; `CurrentVersion` stays 508.

## 7. Corrections to sections 3-5 (both refuters, verified against output_from_llm.log and the glue log)

- The 17 empties are Lisette 14, Beirand 2, Aldis 1 (not 13 / 2 / 1). 10 of them are in the action-first shape
  (462 465 470 481 494 548 604 670 706 850), not 12: 204 209 213 215 224 255 and 618 are character-first.
- Classes: 6 in-scene replies (204 209 213 215 224 Change_Intimacy; 255 a player-requested undress that passed the
  gate), 1 GLUE-INSTRUCTED (706: the `<business>` block said "With the action leave message empty", the pick was a
  [commits] key, the gate PARKED it and her confirming question - which IS the message - was never spoken), and 10
  ordinary-speech defects.
- The D3 reorder is the dominant measurable correlate, not a "plausible contributor": before the first
  `dlg json template` line (glue log 1499, 09-22 17:26:47) 1 `spoke=0` in 86 speech turns (the in-scene 255); after it
  10 in 126 (7.9 %), 9 on reorder turns, the first (462) on the very first reorder turn. Since the reorder went live
  (id >= 456): action-first 10/100 empty vs character-first 1/41 (Fisher p ~ 0.17 - real, not significant). Over all
  322 replies: action-first + pretty-printed 10/42 (24 %), action-first + compact 0/58, character-first 7/219 (6
  in-scene). Pretty vs compact is interleaved within the same hour and the same model (grok-4.3 in all 91 streamed and
  55 logged requests; `reasoning.effort` = `none` on 55/55), so it is the model's own regime, not a config change.
- The carried faction block stays a single-sample conjecture; it is fixed anyway because it costs nothing (8.1).
- Section 4's table was wrong about the validator seam: `VALIDATE_LLM_OUTPUT_FNCT` CAN judge a final empty message. It
  cannot see the connection handler (a local of `call_llm_internal()`), but it sees `$GLOBALS['LAST_LLM_RESPONSE']`,
  which every JSON connector re-decodes per chunk; "complete" = every schema-required key present, the schema's last
  property present, and `message` not the last key the model wrote (connector/__jpd.php closes a partial object with
  a quote and a brace). And section 4 / 5.1 missed that `action_post_process_fnct_ex` runs only under
  `FUNCTIONS_ARE_ENABLED && $outputWasValid` (data_functions.php:6028) - `processor/funcret.php:199` switches functions
  OFF on every glue voiced follow-up (use_functions_again false, lib/lrg_actions.php:131), so a hook-only rail could
  never fire on the very turn addendum 11 is about. The validator + retry seams run on every LLM turn.
- The registration rationale was wrong too: lrgFollowerPolicy() / lrgDlgHoldMovementPolicy() /
  lrgDlgHideEndConversationOnHold() are pre-LLM ENABLED_FUNCTIONS filters, not post-process entries; the post chain is
  LRG_POSTGATE -> LRG_DLG_POSTGATE -> (new) never-empty hook.

## 8. What was built

### 8.1 Owned files (edited in the source tree; backups in `glue/.backup/pt17-replies/`)

`server/lorerim_glue/lib/lrg_factions.php`
- `lrgFacCarriedLive($t)`: a carried ask on a LIVE turn (the player's own words now, no ask in them, `speech` or
  `talk`) - a rechat carry is not one (the NPC continuing after an audience line has no player words to answer).
- `lrgFacLockedLines()`: on a carried live turn the FIRST line is prefixed
  `(background to what he asked a moment ago, not what he is saying now) ` before the usual
  `of this world, not of this moment: `. The rechat carry and the ask turn keep the plain wording.
- `lrgFacRule()`: every faction turn's rule now ends ` Answer what he says, in words.` (31 characters - test_dialogue
  13(c) holds the whole ask-turn block under 900, and the first wording, "Answer what he actually says, in words,
  either way.", pushed it to 919); on a carried live turn the ending is instead ` His words now may be about something
  else - answer them, in words; the enlistment above is background only.` Rules only, no example line, no angle
  bracket. The rule sits outside the block's 600-char body cap (lrgDlgLockedBlock appends it after the trimmed body).
- Config (in `lrgFacDefaults`, overridable through `dialogue.factions`): `carried_note` (true), `answer_in_words` (true).
- Header docblock item 6 explains the 20:10:31 turn.

`tools/test_dialogue.php` section 13 (i), 18 checks: the ask turn (plain line, short ending, block < 900), the aside
twelve seconds later (carried on a live turn, background mark, the fact intact, the carried rule, no brackets / quotes,
the block < 1050, the turn line unchanged), the rechat carry (plain), a recruiter's carried line (marked, her ml=0
"own dialogue" sentence intact), both switches and their defaults. test_dialogue: 355 -> 373 passed, 0 failed
(source tree, WSL copy).

### 8.2 The patch (`research/pt17-replies.patch`; 11 files)

1. NEW `server/lorerim_glue/lib/lrg_replies.php` - the NEVER-EMPTY rail, three layers, as the refuters asked:
   - VALIDATOR (`VALIDATE_LLM_OUTPUT_FNCT`, chained onto any previous one): on a player-speech / `lrg_dlgtalk` /
     voiced-funcret turn with a real NPC, a COMPLETE decoded reply whose trimmed `message` is '' is REJECTED
     (`$GLOBALS['LRG_NE_REJECT']`, log `never-empty ... verdict=rejected`). Let through: a business pick the game
     answers itself (`lrgDlgWillEmit`; the real line plays, the mute would drop ours), a scene / outro turn (log-only
     unless `never_empty.in_scene`), and with `reask` off an action-bearing reply (a rejection would throw Follow /
     Come_Closer / Trade_Items away). A parked [commits] pick with '' (request 706's shape) IS rejected.
   - RETRY (`LLM_RETRY_FNCT`, chained): `why` = `empty message` | `no reply` (no decoded reply: connector error body,
     the 60 s receive timeout, non-JSON) | `invalid output`. For an empty message it RE-ASKS ONCE
     (`never_empty.reask`, default true): the rule "<npc> answers in words - the message field carries at least one
     short spoken sentence in <npc>'s own voice, then the action if any. An empty message is not an answer." is
     appended to the last user message of `$GLOBALS['contextData']` (the global `call_llm_internal()` hands to
     `open()`, data_functions.php:5771), `call_llm()` runs again under `IN_FALLBACK_MODE` (CHIM's own fallback
     re-entry flag, :5810; it skips processor/player_tts.php on the second pass, :5767) with the connector's per-process
     60 s clock (`patch_openrouter_timeout`) restarted, then the context is restored. Her own words win. Only when the
     second reply is empty too (`empty message twice`) or on `no reply` / `invalid output` does she say ONE floor line
     through `returnLines()` (TTS, `$talkedSoFar`, the chat eventlog row, the ScriptQueue echo - all before
     X-CUSTOM-CLOSE). Sentences already spoken mid-stream -> nothing added (log only).
   - HOOK (appended LAST to `action_post_process_fnct_ex`, after LRG_POSTGATE and LRG_DLG_POSTGATE): floors what the
     validator let through on purpose (an action-bearing '' with `reask` off; a reply whose `message` came last),
     BEFORE the command echo, never touching the action list; idle on an emitted pick (`do=pick` among the final
     lines or `lrgDlgWillEmit`). After `returnLines()` the rail re-reads `lrgSpokenThisTurn()` and logs a dropped line
     as not spoken.
   - Lines: `never_empty.lines.question` (his words ended in `?` / began with a question word) | `statement` |
     `trouble` (no reply / invalid: "Say that again?" - never a line implying she heard and understood); each <=
     `max_chars` (60); rotated per NPC through `lrg_memory.empty_reply {at, cid, n, why, line, i, kind, told}`.
   - `lrgNeNote($npc)`: the next-turn rule for Phase 2's volatile guidance, once, within `note_seconds` (120).
   - Offline seams: `LRG_TEST_SAY` (the spoken line when `returnLines` does not exist; `talkedSoFar` grows the way CHIM's
     returnLines makes it grow), `LRG_TEST_REASK` (stands in for `call_llm`), `LRG_NE_TEST_OVERRIDE` (config).
2. `functions.php`: `require_once lib/lrg_replies.php; lrgNeRegister();` at brace depth 0 right after LRG_DLG_POSTGATE
   (comment corrected: the hide policies below are pre-LLM filters). `context_pre.php`: the same two lines at the top
   (main.php:2540 runs it on every request, before call_llm at :2817) - the voiced funcret turn is covered even if
   ext functions.php were skipped (it is not: functions/functions.php:2754 loads it unconditionally; belt and braces).
3. `lib/lrg_dialogue.php` (the switches lane's file - patch only): (a) `lrgDlgJsonTemplate()` appends
   "Never empty: at least one short spoken sentence, even when the action says it all." to `message`'s DESCRIPTION in
   the strict schema and to the text template - a description, never a keyword (`minLength` would 400 in strict
   modes); (b) `dialogue.reorder_json_scope` = `always` (default, D3 as decided) | `business` (the action-first order
   only when `lrgDlgTurnHasBusiness()`: a list, a service, a follower verb, a FRESH enlistment ask, an arrest, a list /
   next question - the Aldis turn had none of them); (c) the `<business>` line "With the action leave message empty:
   your real answer follows by itself." becomes "Say your one line in message even with the action - never leave
   message empty; when the world answers for you, that line is simply not played." (the mute only silences an EMITTED
   pick; request 706 proved the old line cost a parked pick its question); (d) `lrgDlgVolatileGuidance()` appends
   `lrgNeNote()` (function_exists-guarded) as its last part.
4. `config/lrg_config.default.json`: the `never_empty` block + readme after `voice`.
5. Tests: `test_gates` section 36 (52 checks: registration + chaining + the hook last; the validator on complete /
   incomplete / message-last / character-first / json_object / no-template replies; the re-ask that speaks (context
   nudge, IN_FALLBACK_MODE, restore, no floor line, `reask=spoke` logged, n=1); the re-ask that fails -> exactly one
   QUESTION line, logged `why=empty message twice said="..."`, remembered; a statement line; two consecutive empties ->
   two different lines; `reask=false` (Talk floored at once, Follow let through and floored by the hook with the action
   untouched); the emitted pick (let-through, hook idle) vs the parked [commits] pick (rejected) vs the muted shape
   (idle); rechat / scene tick / initiative / diary / narrator / nobody / a plain funcret (not ours); the VOICED funcret
   turn with FUNCTIONS_ARE_ENABLED=false (rejected, one statement line); `no reply` -> a trouble line; `invalid output`
   after spoken sentences -> nothing added; the scene turn (log-only once, hook logs only; `in_scene=true` speaks and
   keeps the action); `enabled=false`; the note once / not on a rechat / not after note_seconds).
   `test_dialogue` 13 (j) (14 checks: the clause on the description, D3 order intact, no extra keyword, the text
   template, `lrgDlgTurnHasBusiness`, the scope default, the `<business>` wording, the note once / not twice / not
   late - with a tiny lrg_memory stand-in). Flow `31_never_empty.php` through the REAL hook files (20 checks: both
   seams registered, the hook last of three, the JSON template hook's clause, the Aldis turn -> rejected -> one re-ask
   with the rule -> one floor line -> spoken -> remembered -> logged; the next turn's rule once and not again; the
   barter shape with words -> OpenInventory untouched, no line, validator passes; the re-ask that works -> no floor
   line; the voiced funcret turn with functions off -> one line). Flow `16` now asserts three post-filters.
   `tools/flows/adapter.php`: `fxSpoke()` also sets `LAST_LLM_RESPONSE['message']` (the RAW text the rail keys on);
   `fxNeReset()` per hook turn / world reset; `fxSaid()`.
6. `PROTOCOL.md`: new 10.23 (the three layers, the lines / memory / note, the prompt-side prevention, the log, cost and
   limits, tests); a pointer at the end of 10.22 (the carried wording). 10.21's text is unchanged; 10.23
   cross-references it.

### 8.3 Refuter items honoured, and the two not taken literally

- R1.1 the `<business>` line: replaced (3c); the parked-pick test is test_gates 36 (g).
- R1.2 / R2.4 the mute: the validator lets an emitted pick through, the hook stays idle on `do=pick`, and `said=` is
  logged only after `lrgSpokenThisTurn()` grew.
- R1.3 / R2.5 D3: promoted in section 7; the message clause is asserted on reorder turns (13 (j), flow 31); the
  cheaper lever is documented and implemented as `reorder_json_scope` (default unchanged: D3 is an owner-approved
  design decision, and flipping it belongs to the owner or the switches lane, not to this patch).
- R1.4 / R2.5 the numbers: section 7.
- R1.5 / R2.6 the registration: after LRG_DLG_POSTGATE, comment corrected.
- R1.6 the invalid branch: `no reply` and `invalid output` are separate `why`s with the `trouble` lines; the transport
  causes (error body, timeout) cannot be told apart from inside the ext (the connector's buffer is private), so they
  share `no reply` - the log line says so.
- R2.1 / R2.3 the validator seam with completeness and the same exclusions - done; R2.2 the re-ask under
  IN_FALLBACK_MODE - done, floor only on double failure; R2.7 the speculative branch is stated in 10.23 section 5 and
  the voiced-turn test exists (test_gates 36 (i), flow 31 section 6).
- NOT taken: a `minLength` keyword (both refuters agree), and gating the reorder by default (see R2.5 above).

## 9. Test tails (WSL, PHP 8.2.28)

Source tree alone (my two owned files): `php tools/test_dialogue.php --quiet` -> `373 passed, 0 failed` /
`ALL CHECKS PASSED` (baseline before this lane: 355); `php tools/test_gates.php` -> `470 passed, 0 failed` (unchanged).
The patch applied to a FRESH copy of the source tree (22:10): `test_gates` -> `535 passed, 0 failed` (the source had
gained 12 checks from another lane meanwhile; section 36 = 52 of them); `test_dialogue --quiet` -> `385 passed, 0
failed`; `test_mcm_wiring` -> `ALL CHECKS PASSED`; `run_flows.php --strict --only=16,31,29,d32` -> `4 scenarios: 4
passed, 0 FAILED, 0 pending; 88 checks, 0 warnings`. The full strict flow suite on the work copy: `80 scenarios: 80
passed, 0 FAILED, 0 pending; 1393 checks, 0 warnings` / `RESULT: OK` (baseline 79 scenarios).
`tools/test_prompt_index.php` is not affected (the faction table rows are unchanged); it needs
`data/prompt_index.ndjson` in place.

## 10. Owner steps and what is still open

- Nothing to do in game. After the deploy: `grep never-empty lorerim_glue.log` - `verdict=rejected` lines are turns
  the model returned no words, `reask=spoke` the ones her second try answered, `said="..."` the ones that got a floor
  line. Expect roughly 5 % of speech turns until the model side changes.
- Cheaper than any server code, in this order: `dialogue.reorder_json=false` for one session (or
  `dialogue.reorder_json_scope="business"`), `reasoning.effort` above `none` in the openrouterjson extra parameters,
  another model, json_schema off (json_object) for a session - and compare the counts.
- If the floor lines feel wrong in her mouth: edit `never_empty.lines` in `lrg_config.json`; `never_empty.reask=false`
  saves the second call; `never_empty.enabled=false` restores silence.
- Not run in game: `returnLines()` from the retry hook and the `call_llm()` re-entry are verified against the installed
  HerikaServer source (main.php:2825, data_functions.php:5735-5771, :5810, :5767) and offline through the real hook
  files, but one deliberate empty reply in game is the real check.

## 11. Reviewer pass (2026-09-23, after 22:10)

- Re-verified on a fresh copy with the patch applied (WSL php 8.2.28, deployed `data/` beside it): `php -l` clean on all
  11 touched files, config JSON valid, `test_gates` 535/0, `test_dialogue` 385/0, `test_mcm_wiring` 13/0,
  `test_prompt_index` 79/0, `run_flows --strict` 80/80 (1418 checks). Owned diff vs `.backup/pt17-replies/` is the two
  files claimed; nothing outside `glue/` was touched by this lane. No Papyrus, no MCM config change.
- Confirmed against the installed core: the post-process hooks run only when `$outputWasValid`
  (data_functions.php:6029), so a validator rejection can never make the hook floor a line before the re-ask;
  `functions/functions.php` (ext loader) runs before `context_pre.php` (main.php:2540), so the hook really is the last
  filter; `returnLines()` appends to `$talkedSoFar` (chat_helper_functions.php:1635) and the retry seam at
  main.php:2825 runs before the `talkedSoFar` branch and X-CUSTOM-CLOSE. Audit request 850 carries every schema key
  through the trailing `amount`, so `lrgNeComplete()` judges the live reply complete and the VALIDATOR (hence the
  re-ask), not only the hook, fires in production.
- CAVEAT on the owner lever `dialogue.reorder_json=false` (section 10 lists it first): with the reorder OFF the model
  writes `message` before `action`, so at sentence-stream time `lrgDlgTransformer` cannot know the pick will be
  emitted and does NOT mute - and the `<business>` block now tells the model to always write words on a pick. On an
  emitted pick her sentence would be spoken AND the game's real line would play. Use `dialogue.reorder_json_scope="business"`
  instead (business turns keep the action-first order, the mute keeps working); if `reorder_json=false` is ever
  wanted, `lrgDlgBusinessBlock()` should fall back to the old "leave message empty with the action" wording when
  `reorder_json` is off.
- Note: with `never_empty.reask=true` (default) an ACTION-bearing empty reply (Follow / StopFollow / Trade_Items +
  "") is rejected and the action rides the re-ask; if the second reply brings words but drops the action, the action
  is lost. `never_empty.reask=false` keeps the action deterministically (hook floors it with a line before the echo).
- Fixed inline: the comment on `answer_in_words` in `lib/lrg_factions.php` quoted a rule text that does not exist.
