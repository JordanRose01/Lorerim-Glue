# pt19c - CHIM integration brief for MENULESS QUESTING v1.0

2026-09-24, the CHIM integration specialist. Read-only: nothing in the glue, CHIM or the server was edited.
Serves spec (`research/pt19-menuless-v1-spec.md` rev 2) S1.3, S2.1-S2.3, S3.3, S4.3-S4.6, S7, S8, S11, S12.

Sources, all read this session (line numbers are of the files as they are today):
- **[S]** HerikaServer core `\\wsl.localhost\DwemerAI4Skyrim3\var\www\html\HerikaServer`: `main.php` (2,949 lines),
  `lib/data_functions.php`, `lib/chat_helper_functions.php`, `functions/functions.php`, `functions/json_response.php`,
  `lib/core/action_catalog.php`, `lib/prompt_injections.php`, `processor/funcret.php`, `processor/comm.php`,
  `processor/player_tts.php`, `connector/openrouterjson.php` (the owner's connector: grok via openrouterjson, strict
  json_schema - pt17-replies).
- **[P]** CHIM 3.3.2 Papyrus `F:\Modlists\LoreRim\mods\CHIM\Source\Scripts`: `AIAgentAIMind.psc`, `AIAgentFunctions.psc`,
  `AIAgentPapyrusFunctions.psc`, `AIAgentMCMConfigScript.psc`, `AIAgentPlayerScript.psc`.
- **[L]** the DLL's own log of the last session `C:\Users\Jordan\Documents\My Games\Skyrim.INI\SKSE\AIAgent.log`
  (2026-09-24 02:41-03:26, 21,989 lines) and `HerikaServer/log/lorerim_glue.log`.
- **[G]** the glue as it stands (script 512, server 0.5.7 + Phase A).

Verdict marks: **VERIFIED** (read in code or log), **INFERRED** (consistent with code and log, needs the DLL source to
prove), **UNPROVEN** (the spec assumes it; nothing shows it). No other ext plugin on this server uses any of the seams
below (grep over `ext/`: no TRANSFORMER / VALIDATE / RETRY / post-process / injection / JSON_TEMPLATE user but the glue),
and CHIM core registers no prompt injection of its own.

---

## 0. The twelve facts that change what the lanes build

1. **D2 is not a 5 s poll.** Every logged D2 copy reached the DLL 0.12-0.53 s after its D1 copy, and D2-only rows from
   background jobs are pushed at arbitrary instants; the "5 s" comes from `processor/comm.php:374-376`, which writes a
   `request` eventlog row only when `time() % 5 == 0`. (section 4) INFERRED ~0.5 s; UNPROVEN mid-request.
2. **A command in the LLM reply reaches the game only after her WHOLE reply is synthesized.** Actions are parsed and
   echoed after the stream loop and after the last sentence's TTS (`data_functions.php:6004-6026`, then `:6029-6488`).
3. **The DLL runs a command 0.2-0.4 s after it arrives, whether or not she is still talking.** Commands are not
   deferred to the end of her speech. (section 4)
4. **Her CHIM voice and the engine's dialogue voice are two unrelated audio paths.** CHIM plays its own WAV buffers
   (`SpeakManager`/`AudioManager`, AIAgent.log:6818-6840); nothing arbitrates against a vanilla line.
5. **The transformer judges a sentence's TEXT, before its TTS.** The mute decision is taken at text time; the audio
   reaches the game one synchronous TTS later (`chat_helper_functions.php:1387-1389` vs `:1622` vs `:1841`).
6. **A validator rejection keeps every sentence already spoken**, drops the rest and ALL actions, then calls
   `LLM_RETRY_FNCT` (`data_functions.php:5937-5942`, `:6004`, `:6029`; `main.php:2822-2828`).
7. **A new player utterance kills the reply in flight** - no more sentences, no `processActions`, no post-process hook,
   no retry (`main.php:201-218` writes `user_input`; `data_functions.php:5988-5998` polls it per chunk and `die()`s).
8. **The LLM request holds a stale copy of `lrg_dialogue` for 5-20 s and writes the WHOLE payload back** over what
   the fast path wrote meanwhile (lost update; section 10 P1). The spec's single fresh read does not cover it.
9. **CHIM does not refuse an action the glue removed from `ENABLED_FUNCTIONS`.** It resolves any ACTIVATED catalog row
   (section 3). Only the strict-schema enum or the glue's own post-gate stops it.
10. **CHIM never voices an `ExtCmdLRG_SelectTopic` funcret** (the row's follow-up is off); each one still takes the MAIN
    lock and a full prompt build before CHIM throws it away (section 5).
11. **The push-to-talk key DOES reach Papyrus** - CHIM's own `AIAgentPapyrusFunctions` registers and handles it (a tap
    silences her, a double tap parks the crosshair NPC) - but CHIM raises no event for it. (section 8)
12. **`NoteSpeech(1)` is not "her speech"**: the glue raises it for ANY NPC's CHIM SpeechStarted, SpeechStopped and
    TextReceived (`LRG_Main.psc:3475, 3512, 3542`), bystanders and rechat included.

---

## 1. One LLM request, in order (`main.php`)

| # | step | where [S] | what the glue does there / consequence |
|---|---|---|---|
| 1 | ext `preprocessing.php` | main.php:192-193 | `lrg_topics` / `lrg_dlg` / `funcret` decided and terminated; runs BEFORE the lock, i.e. concurrently with an in-flight LLM request |
| 2 | `user_input` eventlog row | main.php:201-218 | for inputtext, inputtext_s, ginputtext, ginputtext_s, narrator_inputtext, instruction, init - the row that aborts the previous reply (fact 7) |
| 3 | MAIN semaphore | main.php:227-247 | fast (no lock): `request`, `chat`, `infoaction`, `_speech`...; LOCKED: `funcret`, `rechat`, every LLM type, `lrg_dlgtalk` |
| 4 | functions off unless inputtext*, narrator_inputtext, instruction, welcome, cheatmode | main.php:1077-1098 | rechat: off (main.php:1358) unless `RECHAT_ALLOW_ACTIONS` (false, conf.sample.php:607) |
| 5 | ext `prerequest.php` | main.php:1117 | `lrgPrerequest`, `lrgDlgPrerequest` |
| 6 | `prompt.includes.php` -> `functions/functions.php` | main.php:1615; prompt.includes.php:55 | ext `functions.php` is required at functions.php:2752: `lrgPrepareTurn`, `lrgDlgPrepareTurn` (S2.1's pre-LLM open lives here), hide policies, hook registration. CHIM's final `ENABLED_FUNCTIONS` filter runs AFTER it (functions.php:2780-2803) and CHIM's own post-process closure is appended AFTER it (functions.php:2830) |
| 7 | per-type transformer override | prompt.includes.php:67-68 | replaces `TRANSFORMER_FUNCTION` for a type whose PROMPTS entry has `extra.transformer` (none today; latent: it would silently unchain `LRG_NF_TRANSFORMER`) |
| 8 | CHIM's own pre-LLM command echo (precedent) | main.php:1733-1738 | `NPC|command|Halt@` echoed and flushed before the LLM call |
| 9 | JSON schema + action list | main.php:2474 (json_response.php) | `HOOKS['JSON_TEMPLATE']` (json_response.php:43-51, called :85) = the glue's D3 reorder. The `action` enum `FUNC_LIST` is built from `ENABLED_FUNCTIONS` HERE (json_response.php:225-236) |
| 10 | ext `context_pre.php` | main.php:2540 | injections, transformer chain, never-empty seams |
| 11 | injections rendered | main.php:2553-2566; system prompt 2595-2605 | anything registered later is not in this prompt |
| 12 | funcret branch | main.php:2657-2664 | processor/funcret.php (section 5) |
| 13 | `call_llm()` | main.php:2817; data_functions.php:5734 | section 2 |
| 14 | `LLM_RETRY_FNCT` | main.php:2822-2828 | only when the output was INVALID (not on an abort, not on an empty-but-valid reply) |
| 15 | `X-CUSTOM-CLOSE`, MAIN released | main.php:2922, 2939 | then ext `prepostrequest.php`, `processor/postrequest.php`, ext `postrequest.php` |

## 2. Inside `call_llm`: stream, transformer, validator (S1.3, S2.1, S11)

- Player TTS first: `processor/player_tts.php` runs BEFORE the connector opens (data_functions.php:5767-5769). With a
  player TTS connector the player's line goes through `returnlines()` (player_tts.php:138) - so the transformer chain
  also runs on HIS line; without one it is a text-only ScriptQueue line (player_tts.php:23, :144). **On this install
  player lines are text-only** (`...//__player_text_only///1.0`, AIAgent.log:483).
- Stream loop data_functions.php:5923-6000. `process()` of openrouterjson returns only the NEW part of `message`
  (openrouterjson.php:1017-1018); **the validator receives that delta, not the JSON** - the glue reads
  `$GLOBALS['LAST_LLM_RESPONSE']`, which is assigned only once a `message` key has decoded (openrouterjson.php:1003,
  1038). A 60 s silent stream returns -1 (:944-949).
- `VALIDATE_LLM_OUTPUT_FNCT($chunk)` false -> `$outputWasValid=false`, the unflushed buffer is cleared, the loop ends
  (data_functions.php:5937-5942). Sentences already passed to `returnLines` stay spoken (TTS made, ScriptQueue line
  echoed, `$talkedSoFar` grown); the remaining-buffer flush is skipped (:6004); `processActions` and every hook are
  skipped (:6029); main.php then calls `LLM_RETRY_FNCT` (:2822-2828). A retry reply is heard AFTER the partial one.
- Sentence cut: the first at >= 20 chars (data_functions.php:5929), later ones >= `MINIMUM_SENTENCE_SIZE` 75
  (main.php:9). `returnLines` per sentence (chat_helper_functions.php:1285): transformer :1387-1389 -> a result under 2
  chars is skipped entirely - no TTS, no `$talkedSoFar`, no echo (:1416-1418) -> **synchronous TTS** :1622 ->
  `$talkedSoFar[]` :1634-1636 -> `NPC|ScriptQueue|...` echo + flush :1841, :1870-1871 -> log + `prechat` row.
- Abort: after every streamed line `SELECT ... eventlog WHERE type='user_input' AND ts > <this request's ts>`; a hit
  closes the connector and `die('X-CUSTOM-CLOSE')` (data_functions.php:5988-5998).
- Actions only when `FUNCTIONS_ARE_ENABLED && $outputWasValid` (:6029): `processActions()` (openrouterjson.php:1107-
  1164) -> hooks -> CHIM post-filter -> `actions_issued` rows -> ONE echo of all lines (:6455-6488).
- `$talkedSoFar` = what she actually said (muted and cut sentences absent); `LAST_LLM_RESPONSE['message']` = the raw
  decoded message including sentences the transformer muted or the validator never let out.
- Measured (L): Elrindir, turn line 02:52:43 (glue log 3362), first CHIM audio 02:52:47.852, stream close + the D1
  command 48.164, command executed 48.346 (AIAgent.log ~6800-6857). First audio ~4.8 s after the turn began.

## 3. Actions: resolution, unknown actions, hook order (S1.3, S2.1, S6.1)

- **Unknown action name**: `queueFunctionExecutionCommand` logs `Function not found for X` and returns false - no line,
  no hook ever sees it, no funcret, the model is told nothing (functions.php:2507-2518; "Talk" silently). A missing
  required parameter drops it the same way (:2520-2528).
- **Resolution ignores this turn's offer**: `findFunctionByName` (functions.php:2579) first asks the catalog for an
  ACTIVATED row available in the current mode (action_catalog.php:2525-2548, 2751-2763); `ENABLED_FUNCTIONS` is not
  consulted. Under strict json_schema (openrouterjson.php:650-651) the enum (json_response.php:486-495) keeps a hidden
  code out of the reply; on a `json_object` connector (:653, :772) only the glue's post-gate drops it. The enum is
  built at main.php:2474, so a hide applied later (context_pre) never reaches the model.
- **Wire line**: `NPC|<channel>|<CODE>@<param>` with the CODE, not the display name (functions.php:2533-2543); channel
  `confirmcommand` when the catalog row asks for Prisma confirmation (action_catalog.php:415-445); the SelectTopic row
  is `automatic` (lrg_dialogue.php:3357). Display names: OpenInventory = `TradeItems`, RentRoom, HireCarriage,
  FollowPlayer = `Follow_<player>` (functions.php:1057, 1075, 1076, 1089).
- **Hook order**: `action_post_process_fnct` (unset) then `action_post_process_fnct_ex` in registration order:
  `LRG_POSTGATE`, `LRG_DLG_POSTGATE`, `LRG_NE_HOOK` (glue functions.php) -> **CHIM's core closure** (functions.php:
  2830-2990: drops quest-engine-suppressed actions, executes ScriptProxy actions and Drink / Toast / Train* / SpawnItem
  SERVER-side and removes them) -> **CHIM's ACTION POST-FILTER** (data_functions.php:6042-6452: rewrites the params of
  Attack, GiveItemTo, GiveGoldTo, TradeItems, Follow, MoveTo, Brawl, FollowPlayer, ReturnBackHome, PickupItem, and
  **TakeGoldFromPlayer with an empty/non-numeric amount gets the last number found in the NPC's last 100 speech rows**,
  :6324-6347). The glue's comment "the NE hook is the last entry" (lrg_replies.php:27) is no longer true.
- ExtCmd params pass CHIM opaque (no ExtCmd name is rewritten). CHIM splits action lines on `|` and `@`
  (data_functions.php:6042-6043, 6456-6457) and funcret on `@` (funcret.php `explode("@", ...)`).

## 4. How commands reach the game: D1, D2, the DLL queue (S2.1, S4.6, S8)

- **D1** = a line in the HTTP response the DLL is reading. The DLL reads it incrementally (Elrindir's first sentence
  played before the response closed, above). From the LLM path it is the end of the stream (fact 2); from the fast path
  it is the glue's echo in the `lrg_topics`/`lrg_dlg` reply (lrg_dialogue.php:3102-3105), ~0.15 s (glue log 3433 vs
  AIAgent.log:7824).
- **D2** = a `responselog` row, `sent=0`. On the DLL's `request` message `processor/comm.php:367-379` claims EVERY row
  with `localts <= now+1` atomically (`DataDequeue`, data_functions.php:874-904; no age limit) and echoes
  `actor|action|text` - the glue's row arrives as `npc|command|ExtCmdLRG_SelectTopic@...|` (empty third field; the DLL
  parsed it identically to D1, AIAgent.log:7585).
- **Poll period**: D2 copies arrived 0.28, 0.53, 0.49, 0.12 s after the D1 copy of the same `x` (AIAgent.log
  6847/6863, 7566/7585, 7824/7831, 12128/12145); D2-only `rolecommand` rows written by background jobs were pushed at
  arbitrary instants (9935, 12009, 12828); the DLL main loop ticks every 0.5 s (`[BORED]` lines, Plugin.cpp:2505/2510,
  e.g. 12822-12828). The prior "every ~5 s" (p2-chim-interplay.md:286) read eventlog rows that comm.php:374-376 writes
  only when `time() % 5 == 0`; its own "two per tick" (:466) already meant >= 2 polls in one second. **INFERRED
  ~0.5 s. UNPROVEN for a row inserted in the middle of a long LLM request** - no such sample exists yet.
- **DLL queue**: `Pushed command` -> `[COMMAND_QUEUE] Processing command` 0.18-0.43 s later (6847->6856, 7566->7580,
  12128->12136), logged next to `Speaker manager is busy` (6858, 7562): commands run while she speaks (fact 3).
- **Routing to Papyrus**: ExtCmd* -> the bridge `LRG.DispatchExternalCommand` (row metadata `bridge_script`,
  lrg_dialogue.php:3347-3348) AND the `CHIM_CommandReceived` mod event, raised in Papyrus by
  `AIAgentAIMind.SendExternalEvent` (AIAgentAIMind.psc:1395-1405). `LRG_Main.HandleCommand` de-dups identical
  `npc|cmd|param` within 5 s (LRG_Main.psc:1729-1755); the driver de-dups D1/D2 by `x`.
- A pending command suppresses CHIM's rechat (`Rechat avoided as command pending`, AIAgent.log:545) until
  `commandEndedForActor` (LRG_Main.psc:1602-1605).

## 5. funcret and the follow-up turn (S2.3, S7)

- Game: `logMessageForActor("command@<Code>@<param>@<result>", "funcret", npc)` then `commandEndedForActor`
  (LRG_Main.psc:1594-1605). Server pre-lock: `lrgHandleGameMessage` -> `lrgFuncretVerdict` (lrg_actions.php:346-352),
  which returns `pass` for EVERY SelectTopic funcret (:961-963).
- `funcret` is not a fast command -> it waits for MAIN (up to `SEMAPHORES_TIMEOUT` 300 s, main.php:238-247), main.php
  builds the entire prompt and writes a `funcret` eventlog row (~main.php:1924, excluded from history by
  data_functions.php:917-921), then funcret.php: follow-up disabled or prompt empty -> `terminate()`; else one more
  LLM+TTS turn under MAIN with functions OFF unless `use_functions_again` (funcret.php:134-136, 152-156, 193-201).
- The SelectTopic row: `followup.enabled=false`, `suppress_placeholder_infoaction=true` (lrg_dialogue.php:3359-3360).
  **So no SelectTopic result is ever voiced by CHIM**; it reaches her words only on his NEXT turn (`<what_just_happened>`)
  and the corner note. Today the "again" request (`lrg_dlgtalk`) was the only same-exchange carrier; S2.1 removes it.
- The row is marker-gated: `data/.dlg_actions_v<LRG_DLG_ACTIONS_VERSION>` (=1, lrg_dialogue.php:41, 3313-3332); an edit
  to the row (e.g. dropping `lrg_dlgtalk` from `request_types_any`, :3333) reaches the live catalog only with a bump.

## 6. Prompt injections and the schema (S11)

- `chimRegisterPromptInjection(slot, id, content, priority)` (prompt_injections.php:10-32): same id overwrites; render
  sorts ascending priority then id, drops empties, de-duplicates identical text (:59-85).
- `character_bottom` sits inside `<character>` (main.php:2597); `prompt_bottom` after the action list and nearby
  actors, before paralinguistic tags, rumors, book task (main.php:2600). Both are in the SYSTEM message; history and
  his line come after. Glue today: character_bottom 200 boundaries, 204 companion, 205 locked facts, 210 business rules;
  prompt_bottom 900 moment, 905 quiet, 910 business (context_pre.php:35-93).
- The retry (`LLM_RETRY_FNCT`) reuses the same `$contextData`: the pre-LLM directive and every injection are in the
  re-ask too; only the last user message can be amended.
- Schema: `message` (json_response.php:472) precedes `action` (:486), and so in the `required` list (:509); with strict
  json_schema the model streams keys in that order, so without D3 the action is unknown while sentences stream. The
  `FUNC_LIST` enum is shuffled per request (json_response.php:284).

## 7. Other LLM traffic (S12)

- **rechat**: DLL-driven after a line ends (SpeakManager; AIAgent.log:915, 1698, 4373), `RECHAT_H`=2 rounds at
  `RECHAT_P`=50 % (conf.sample.php:42-43; the owner's live values were not read - conf.php holds keys), budget file
  main.php:1159-1222, functions off. A rechat is a full LLM request: it runs ext `functions.php`
  (`lrgDlgPrepareTurn`) and `context_pre.php`, and it is skipped while a command is pending.
- Optional post-request scene classifier (processor/postrequest.php:100-161), diaries, dynamic profiles (background).
- CHIM's own connector fallback (data_functions.php:5775-5835) is skipped while `IN_FALLBACK_MODE` is set - which the
  glue's re-ask also sets.

## 8. Game side (CHIM 3.3.2 Papyrus) (S1, S4.6, S10)

- **CHIM_SpeechStarted** is created only in `FakeDialogueWith` (AIAgentAIMind.psc:1707-1723) and `FakeDialogue`
  (:1788-1794), which the DLL calls "after NPC starts speech ... (every sentence)" (:1709): only CHIM-voiced lines,
  once per sentence; `CHIM_SpeechStopped` from `EndDialogue` (:1841-1850). Engine lines raise nothing. Both functions
  also run `GetIntoConversation` and `PlaceCam` (camera only when the player sits, :3612-3627).
- Glue mapping: SpeechStarted(player) -> `NoteSpeechToDriver(0)` (LRG_Main.psc:3456-3466); any other NPC -> `(1)`
  (:3475); SpeechStopped(NPC) -> `(1)` (:3512); `CHIM_TextReceived` -> `(1)` (:3542). No speaker filter.
- Player SpeechStarted needs a voiced player line; here player lines are text-only (section 2) and
  `_player_tts_traditional_dialogue` = 0 (AIAgent.log:243; default false, AIAgentMCMConfigScript.psc:277).
- `isActorTalking(String npc)` native (AIAgentFunctions.psc:33); reads 0 between two sentences (the glue's own finding,
  LRG_Main.psc:4225-4234, which pads it with a 4.0 s last-line window).
- **Push-to-talk** (AIAgentPapyrusFunctions.psc): the voice key is registered by `doBinding2` (:947-951);
  `OnKeyDown` (:382-414) -> `BeginVoiceHotkey` (:646-665), refused unless `SafeProcess()` (:1228-1250: not
  `IsInMenuMode`, no Console / Crafting / MessageBox / Container / Loot / listmenu / text input) and `isGameFocused`;
  held >= 0.35 s (:58) -> `recordSoundEx` from `UpdateChatHotkeys` (:743-758); a TAP not followed by a second tap within
  0.35 s -> `AIAgentFunctions.stopAllDialogue()`, "stop current and queued dialogue" (:654-657, :761-764;
  AIAgentMCMConfigScript.psc:818); a double tap -> `StartWait` on the crosshair NPC (:690-711). No mod event anywhere.
  CHIM's default key is -1 (AIAgentMCMConfigScript.psc:254); this install maps DX 29 (AIAgent.log:432, "29 -> 17").
  The Dialogue Menu does not block it (91 turns spoken over an open menu, spec [L]); Barter/Gift/Container do.
- **Commands the model can choose**:
  - OpenInventory (`TradeItems`): non-teammate -> `ShowBarterMenu()`, follower -> `OpenInventory(true)`,
    `OpenInventory2` -> gift menu; a 2.5 s re-open guard keyed on CHIM's OWN calls only (AIAgentAIMind.psc:929-987,
    guard :933-939). Nothing stops the engine's trade entry plus CHIM's barter.
  - RentRoom: checks his gold, sets the innkeeper's `RentRoomScript` bed owner + Variable09, moves `cost` gold, no
    vanilla topic (:3080-3109); the price is the catalog `{{config.cost_gold}}` (functions.php:967).
  - HireCarriage (:3111-): the action text tells the model to accept in one line and END the conversation
    (functions.php:968).
  - EndConversation: releases packages, "cooldown started in C++" (:1629-1648); the NPC "becomes unavailable to talk
    for a short time" (functions.php:1000). It does not close a vanilla menu.
  - FollowPlayer: `FollowPlayerPackage` override, priority 100 (:31, :510-550).
  - GiveGoldTo -> `MoveInventoryItem`: gold goes through a Prisma confirmation (`requestMoveInventoryItemConfirmation`,
    :3047); on accept `ConfirmMoveInventoryItem` does `RemoveItem` then an unconditional `AddItem` (:3071-3072) -
    the spec's [J] is right: it mints gold from a short purse.
  - SpawnItem / Train* / Drink / Toast never reach the DLL as commands: CHIM executes them server-side in its
    post-process closure (functions.php:2860-2990) - after the glue's hooks, so a glue drop prevents them.
- Other natives the lanes may want (semantics beyond the comment UNPROVEN): `stopAllDialogue()` (AIAgentFunctions.psc:13;
  stops everyone's current and queued CHIM lines), `setLocked(int, npc)` "1 locks agent for talking" (:32),
  `requestMessageForActor` (:30, the "again" call S2.1 removes).

## 9. First contact, the pieces we have (S2.1 timeline, INFERRED)

Request start -> lock -> `functions.php` (the pre-LLM D2 insert, early in the ~0.6 s prompt build) -> DLL poll <= 0.5 s
-> COMMAND_QUEUE 0.2-0.4 s -> Papyrus opens the menu -> list read <= 0.3 s -> `lrg_topics want=1` answered on the fast
path, D1 echo ~0.15 s -> click -> engine line. That is ~2-3 s after the request starts. Her first sentence's TEXT
reaches the transformer at LLM TTFT + >= 20 chars (~1.5-2.5 s); its AUDIO at ~4-5 s (Elrindir 4.8 s). So the fast pick
lands at about the moment the mute is decided, and 1-3 s BEFORE her first CHIM audio.

---

## 10. PITFALLS (lane -> what bites)

- **P1 [A] Lost update on `lrg_dialogue`.** `lrgDlgGet` caches the row in `$GLOBALS['LRG_DLG_STATE']` for the whole
  request (lrg_dialogue.php:633-649); `lrgDlgSet` patches THAT copy and upserts the whole payload (:651-670). The LLM
  request primes the cache in `lrgDlgPrepareTurn` and writes at stream end (`lrgDlgEmit`'s `last_exec` :3094-3096, the
  park, `truth_note` :2537/2542, `calib_auto_at` :2578); the fast path (`lrg_topics`/`lrg_dlg`, pre-lock, parallel)
  writes `session`, the list, `gen`, `last_exec` in between. The late write restores the old ones. With the pre-LLM open
  this interleave is the NORMAL case: a landed fast pick's `last_exec` vanishes, S4.3's `utter.at > last_exec.at` and
  the T-key drop read the wrong value. Every read that the fast path may have changed, and every write, in the LLM path
  must bypass the cache (or merge server-side, e.g. `payload || patch`), not only the transformer's `last_exec`.
- **P2 [A] Hides must land before main.php:2474.** `FUNC_LIST` (the enum) is built there. S2.1's
  OpenInventory/RentRoom/HireCarriage hold and S6.1's `hide_reward` belong in `lrgDlgPrepareTurn` (functions.php time);
  anything decided in `context_pre` can only be dropped in the post-gate - and must be, since CHIM would run it (fact 9).
- **P3 [A,B] Hook order.** The glue's three hooks run BEFORE CHIM's core closure and CHIM's param rewrites. A glue
  decision may be undone after it: an action dropped by the quest engine or executed server-side after the NE hook
  assumed it survives; `TakeGoldFromPlayer@` with no amount leaves the glue's truth check and comes out with the last
  number anyone said (data_functions.php:6324-6347). The truth check must drop a TakeGoldFromPlayer without a numeric
  amount.
- **P4 [A] A rejection is not a rewind.** On a validator reject the sentences already echoed were heard; the retry
  speaks after them. Only a rejection in the chunk that COMPLETES a sentence stops that sentence.
- **P5 [A] The abort path runs nothing.** If he speaks again while her reply streams (fact 7) the post-gate, the park
  write, the emit, the never-empty hook and the retry all do not run. Design for "the reply may simply vanish": the
  pre-LLM D2 row is already queued and still lands; `open_pending` stays set; S4.6's re-arm sees a turn with no pick.
- **P6 [A] The mute is a text-time race** (facts 3-5, section 9). A sentence cleared at 2.0 s is heard at ~4.5 s -
  after an engine line that started at ~3 s. Options (none proven): the game stops her queued CHIM lines when it clicks
  a fast pick for an `open_pending` turn (`stopAllDialogue` stops EVERY NPC's lines; `setLocked` untested), or the
  bridging directive asks for a line that is harmless when heard AFTER her real line.
- **P7 [C] `NoteSpeech(1)` is anybody.** Bystanders, rechat rounds and her own `SpeechStopped`/`TextReceived` all
  restart the adv counts (fact 12). Filter by the session speaker. `SpeechStopped` doubling as a stamp is harmless for
  the CHIM anchor (the 1.0 s then counts from her stop) but not for the engine anchor.
- **P8 [C] The engine-line anchor ignores her CHIM voice.** On a fast `adv` pick of an `open_pending` turn her CHIM
  sentences can still be arriving (section 9) and play over the next engine line; a sentence longer than 1.0 + 2.5 s
  started before the click is not caught by the "NoteSpeech(1) younger than 1.0 s" rule. While a reply for this NPC is
  in flight, the first `adv` pick should also require `isActorTalking == 0`. A `rearm=1` pick (D1 at stream end) is
  safe: by then every sentence is already in the DLL's queue.
- **P9 [C,D] The talk key is shared with CHIM.** `iKeyPushToTalk` must equal CHIM's voice key (no Papyrus way to read
  CHIM's: it is a private MCM variable, default -1). A tap (< 0.35 s) makes CHIM `stopAllDialogue()`; a double tap
  parks the crosshair NPC with CHIM's `StartWait`. The glue's `OnKeyDown` fires on all three; it fires even when
  CHIM refuses to record (paused menus). The MCM help "only tells her you are about to speak" is incomplete.
- **P10 [C,F] Paused menus mute the microphone.** After a trade / gift / training click, `SafeProcess` refuses the talk
  key until the window closes - owner page: "close the trade window to talk again".
- **P11 [A] SelectTopic funcrets cost a MAIN slot each.** Every click's funcret passes pre-lock (`pass`), waits behind
  her in-flight reply, builds a full prompt, and is thrown away by funcret.php. Auto-advance multiplies clicks. The glue
  can record and end them pre-lock (`handled`); CHIM would do nothing else with them (section 5).
- **P12 [A] A rechat is a turn too.** `lrgDlgPrepareTurn` and the injections run on rechat, narration and funcret LLM
  requests. S2.1 (pre-LLM open) and S4.6 (re-arm "on the next turn ... when NO pick was emitted") must key on a
  PLAYER speech type (`LRG_PLAYER_SPEECH_TYPES`, lrg_core.php:33), or a bystander's rechat re-arms the auto.
- **P13 [A] Service actions around an open list.** EndConversation starts a DLL talk cooldown while the vanilla list
  stays on screen (hide it whenever a session is open, not only on the hold); HireCarriage's own text ends the
  conversation; CHIM's RentRoom charges without the vanilla topic - if the model picks RentRoom and the fast path
  clicks the inn entry, he pays twice. S2.1's drop covers the `open_pending` turn only.
- **P14 [A,C] D2 rows have no TTL.** A `do=open` queued while the game is not polling (load screen, pause) lands
  whenever polling resumes; the param carries no time. Add an age guard (a `t=` key, or the game refuses an open whose
  cid is older than N s).
- **P15 [A,E] The offline harness is one process for many requests.** Clear `$GLOBALS['LRG_DLG_STATE']` between
  simulated requests and interleave a fast `lrg_topics` between the pre-LLM turn and its post-gate, or P1 stays
  invisible offline.
- **P16 [A] `parked.said`** (S4.4 "named") should be what she SAID (`$talkedSoFar`), not `LAST_LLM_RESPONSE['message']`,
  which still holds muted and cut sentences.
- **P17 [A] Params**: no `@`, `|`, CR/LF in any key value (`ask=`, `txt=`); `@` shifts funcret fields, `|` breaks the
  line split. Keep `ask=` through the existing sanitiser.
- **P18 [A] Catalog row edits need `LRG_DLG_ACTIONS_VERSION` + 1** (section 5), or the live row keeps `lrg_dlgtalk`.

## 11. SPEC CONFLICTS (the spec's assumption about CHIM -> what the code/log says)

- **C1 S2.1 / S12 / 5.x "D2, echoed on the DLL's 5 s poll"; first-contact budget "3-8 s (D2 poll 5 s + ...)".** The
  5 s is a logging artefact (comm.php:374-376); D2 is observed at 0.1-0.5 s (section 4). Expect ~2-3 s after the
  request starts - UNPROVEN until one mid-request D2 row is timed.
- **C2 S2.1 "the mute usually loses ... she says a word, then her real line plays".** With a fast D2 the pick lands at
  text time (a coin toss for the mute) and ~1-3 s before her CHIM audio, so the likely unmuted order is: her real
  engine line starts, then her bridging CHIM sentence plays over it (facts 3-5, section 9). The owner-page wording and
  the directive text need the measured order first. The spec's "first sentence streams ~2-3 s into the request" is text
  time; audio was 4.8 s (Elrindir).
- **C3 S4.6 / S10 "the push-to-talk press never reaches Papyrus".** It reaches CHIM's own Papyrus
  (AIAgentPapyrusFunctions.psc:382-414, 646-758); what is true is that CHIM raises no event, so the glue's own
  `RegisterForKey` stays right. Missing from the spec: tap = CHIM `stopAllDialogue()`, double tap = `StartWait` on the
  crosshair NPC, and CHIM's default key is unset (-1), not 29 (29 is this owner's mapping).
- **C4 S4.6 "a NoteSpeech(1) younger than 1.0 s restarts either count" / "no NoteSpeech(1) in that second".**
  `NoteSpeech(1)` is raised for every NPC's CHIM line, for her SpeechStopped and for TextReceived (LRG_Main.psc:3475,
  3512, 3542), not for "her speech" only; bystander chatter and rechat will delay or restart the auto-advance.
- **C5 S7 "every failure is words" for the game-side SelectTopic errors** (read failed, stale, click did not take,
  nothing came of that, undriven "choose that one on the list yourself", session died "we were interrupted - ask me
  again"). CHIM never voices a SelectTopic funcret (follow-up off, funcret.php terminates), and S2.1 removes the
  `lrg_dlgtalk` "again" request, so these are heard only on his NEXT turn plus the corner note - never "in the same
  exchange" as the table's wording suggests. Pre-LLM foreseen clauses (read-only session, stage rail) are unaffected.
- **C6 S2.1 "the transformer reads last_exec FRESH ... for that one key".** Insufficient: the LLM request's own writes
  replay its stale cache over the fast path's writes (P1). The fresh-read rule must cover the post-gate's reads
  (`last_exec`, `session`, `open_pending`) and every LLM-path write.
- **C7 S12 "LLM calls per player turn: exactly 1".** True for the glue only: CHIM's rechat (2 x 50 %), the optional
  scene classifier and background jobs are extra LLM turns, and each SelectTopic funcret takes a MAIN slot + a prompt
  build (P11). Budget the MAIN-lock occupancy, not only LLM calls.
- **C8 S6.1 truth check on TakeGoldFromPlayer "with an amount no live entry or confirmed fact carries".** An EMPTY
  amount passes the glue and is filled by CHIM afterwards from recent speech (data_functions.php:6324-6347): treat a
  missing amount as unconfirmed.
- **C9 S2.1 "a model-chosen OpenInventory / Rent_Room / Hire_Carriage shortcut is dropped".** The wire carries CODES
  (`OpenInventory`, `RentRoom`, `HireCarriage`); the model writes display names (`TradeItems`, `RentRoom`,
  `HireCarriage`) - hides use codes, post-gate compares normalised codes. And the drop is scoped to the `open_pending`
  turn while the double-charge risk lasts as long as the list is open (P13).
- **C10 S4.6 cites `LRG_Main.psc:3442-3471` and `:4223-4227`.** In script 512 they are 3446-3483 and 4225-4240, and the
  reused logic pads isActorTalking with a 4.0 s last-line window, not 1.0 s; the 1.0 s is only safe for a pick that
  arrived at stream end (P8).

## 12. First-evening checks that settle the UNPROVEN items

1. D2 latency mid-request: glue `emit ... do=open ... x=<x>` (log it with milliseconds) vs AIAgent.log
   `Pushed command,1,ExtCmdLRG_SelectTopic@...x=<x>` - one pair settles C1.
2. Audible order on first contact: AIAgent.log `Queueing new line - Actor: <npc>` / `Starting DownloadAndPlay` times vs
   the driver's `clicked` and `adv anchor: line seen at=` lines (C2, P6, P8).
3. `grep "Generation stopped because user_input"` in the server log: how often a reply (and its pick) is killed (P5).
4. `grep "Audit:Lock acquired by funcret"` next to the next `inputtext` lock: the MAIN cost of click funcrets (P11).
5. `grep "Function not found"` in the server log: actions CHIM dropped before any glue hook saw them (section 3).
