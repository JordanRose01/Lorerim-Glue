# P2 - CHIM 3.3.2 interplay with a REAL (hidden) dialogue session

Round: Phase 2 research (read-only). Date 2026-09-21. Author scope: what CHIM's game plugin and server do while the
engine's Dialogue Menu session is open, and what the glue must do on both sides so voice/text conversation keeps working.

Conventions: **[V]** = verified in a source I opened (file:line, DLL offset, disassembly address, DB query, URL).
**[I]** = inference / design reasoning. **NOT FOUND** = searched, nothing (locations stated).
Paths: `CHIM\` = `F:\Modlists\LoreRim\mods\CHIM\`; `HS/` = `/var/www/html/HerikaServer/` inside WSL distro `DwemerAI4Skyrim3`
(`\\wsl.localhost\DwemerAI4Skyrim3\var\www\html\HerikaServer\`). DLL = `CHIM\SKSE\Plugins\AIAgent.dll` (4,134,912 bytes, build
path strings `D:\wt\chim-332-main-voice-hotfix\Plugin\...`). "DLL@0x..." = file offset of a string; "rva 0x..." = code address
(image base 0x180000000, `.text` rva = file offset + 0xC00). Disassembly was done read-only with `objdump -D -b binary
-m i386:x86-64` inside WSL on the file under `/mnt/f` (scripts in `%TEMP%\p2chim\`, see Appendix B).
Live owner settings quoted below come from `Documents\My Games\Skyrim.INI\SKSE\AIAgent.log` (session 2026-09-21 06:28).

---

## 0. Executive summary - what changes the design

1. **Input is NOT blocked by the Dialogue Menu.** Every CHIM chat hotkey is gated by `SafeProcess()`
   (`AIAgentPapyrusFunctions.psc:1228-1251`), whose only relevant test is `Utility.IsInMenuMode()`; the CK wiki text says
   "Dialogue, because the game isn't paused, will not put the game into 'menu mode'". The native recorder has no menu gate.
   So push-to-talk, the legacy text box and the Prisma chatbox hotkey all pass while a (hidden) session is open. [V]
2. **But CHIM actively kills vanilla dialogue when the player speaks to an NPC.** For every streamed player request the DLL runs
   `QueueInterruptNPC` -> `InterruptNPC(actor, agent)`, which calls `actor->PauseCurrentDialogue()` (vtable +0x278 = vfunc 0x4F)
   and then `Actor::EndDialogue()` (vtable +0x6D0 = vfunc 0xDA) on the addressed NPC (rva 0x52959 / 0x52962, caller rva 0x152c37). [V]
   Consequence: a session that is open when the player's utterance is dispatched gets its current line cut and its dialogue state
   ended by CHIM. **"Keep the hidden session open for the whole conversation" (flow B) is incompatible with CHIM**, and the
   unavoidable case (a multi-layer branch waiting for the player's spoken choice) is THE gating experiment of Phase 2 (X1 below).
3. **CHIM's "player is in dialogue" test is `MenuTopicManager+0xB1` (`menuOpen`).** While any session (hidden or not) is open, the DLL
   suppresses bored events and rechat (NPC-to-NPC follow-ups) - good, no ambient chatter during a session. `isAvailableforDialog`
   only LOGS "SEEMS x is talking to y" and continues, so the session NPC still answers CHIM requests. [V]
4. **CHIM's SWF really is a click gate**: `onSelectionClick` no longer calls `TopicClicked`; it sends mod event `PlayMenuTopic` and waits.
   The engine click only happens in `startTopicClickedTimer("off")` / `topicClicked()`. The DLL's native sink releases immediately
   when `_player_tts_traditional_dialogue` is 0 (owner's live value: 0). The glue can also call
   `UI.InvokeString("Dialogue Menu", "_root.DialogueMenu_mc.startTopicClickedTimer", "off")` itself - this is the exact call CHIM's own
   Papyrus uses (`AIAgentPapyrusFunctions.psc:1174`) and it fires `GameDelegate.call("TopicClicked", [TopicList.selectedEntry.topicIndex])`. [V]
5. **Ground truth of what was said already reaches the server** in two forms per vanilla line: an `eventlog` row of type `chat` whose data starts
   with `(Context location: ...)` and a `speech` table row whose `topic` column carries the DLL tag `traditional_player_speech` /
   `traditional_npc_speech`. Both depend on the MCM toggle "Vanilla Dialogue" (`_capture_background_chat`, default ON, live ON). [V]
6. **The action's wire line is emitted only after ALL speech of the reply has been synthesised** (measured 3-40 s of TTS on this machine).
   On a SelectTopic turn the LLM's `message` must be empty/very short or the click arrives many seconds late and CHIM's TTS overlaps the
   real voiced line (CHIM audio is its own XAudio voice + FaceGen lock, not the engine's dialogue channel). [V measurements / I overlap]
7. **Recommended flow = C-with-cache**: LLM first (one action, `item` = option key if a cached list exists, else the intent in words),
   session opened only to execute, list re-read after every response and cached server-side per NPC for the next turn; snapshots are
   never taken speculatively. Rationale in section 4.
8. **Quest data**: table `quests` is EMPTY on this install; `questlog` (from `_uquest`) has 299 rows keyed by quest EDITOR ID with the
   current objective text, but 48 rows still contain unresolved `<Alias=...>` / `<Global=...>` tags and there is no giver column data.
   Relevance to the current NPC must therefore come from the game (PO3 `GetActiveAssociatedQuests(ref)` -> editor IDs in the glue's
   NPC snapshot), joined to `questlog.id_quest`. [V]
9. `CHIM_AI_QUEST_PROGRESSION` is `false` live; the glue's PHP can call `chimQuestEngineFeatureEnabled()` directly (loaded at `main.php:53`,
   before ext hooks) and warn. [V]
10. Extra CHIM hazard found on the way: `EndDialogueClearScene` (Papyrus) stops/restarts an NPC's scene or even the quest that owns the NPC's
    current package ("can break quests", `AIAgentAIMind.psc:1874-1906`). It is only reachable for NPCs that were "on scene"; the owner's
    live `_restrict_onscene = 1` (NPC Scene Safety ON) keeps CHIM away from scene actors. The glue should check that setting and warn if 0. [V]

---

## 1. INPUT during a session

### 1.1 Papyrus-side gate (all hotkeys)
`CHIM\Source\Scripts\AIAgentPapyrusFunctions.psc:1228-1251` [V]:
```papyrus
Bool Function SafeProcess(bool allowMenuMode = false)
	If (allowMenuMode || !Utility.IsInMenuMode()) \
	&& (!UI.IsMenuOpen("Console")) && (!UI.IsMenuOpen("Crafting Menu")) && (!UI.IsMenuOpen("MessageBoxMenu")) \
	&& (!UI.IsMenuOpen("ContainerMenu")) && (!UI.IsTextInputEnabled()) && (!UI.IsMenuOpen("LootMenu")) \
	&& (!UI.IsMenuOpen("RaceSex Menu")) && (!UI.IsMenuOpen("listmenu"))
```
- "Dialogue Menu" is not in the list; the only test that could trip is `Utility.IsInMenuMode()`.
- CK wiki (BellCube mirror) https://papyrus.bellcube.dev/skyrimse/script/utility/function/isinmenumode/ : "Menu mode is basically defined as whenever
  the game is paused..." and "Dialogue, because the game isn't paused, will not put the game into 'menu mode'." [V-doc]. CHIM's own comment agrees
  in spirit (":1238 IsInMenuMode to block when game is paused with menus open").
- Nothing in the Ultra profile turns the Dialogue Menu into a pausing menu: `profiles\Ultra\modlist.txt` has no "Hold on a sec - Pause Dialogue Menus" and no Skyrim Souls RE (grep); the dialogue-related
  entries are `+Fuz Ro D-oh` :3680, `+Improved Alternate Conversation Camera` :3710, `+Smart Talk (Dialogue Menu Enhancer)` :3843, `+Lingering Subtitles Fix` :3962, `+To Your Face SE` :3984. Smart Talk's
  `[DialoguePause]` ini section is a delay between lines, not a game pause (`SmartTalk.ini:119-126`). [V] If such a mod were ever added, `IsInMenuMode()` would turn true in dialogue and ALL CHIM input would be blocked.
- `UI.IsTextInputEnabled()` is false in a plain dialogue session [I] (no text field in DialogueMenu).
- Users of `SafeProcess()`: `BeginTextHotkey` :611-628, `BeginVoiceHotkey` :646-665, `FinishVoiceHotkey` :682-699, `WaitForCrosshairNpc` :702,
  `UpdateChatHotkeys` :716-765 (**resets the gesture and stops a running recording if `SafeProcess()` turns false mid-hold**, :724-727),
  `TriggerTextChatAction` :767 (opens `UIExtensions` `UITextEntryMenu`), `TriggerVoiceChatAction` :799, diary / soulgaze / wheels.
  Prisma panels use `ShouldBlockPrismaMenuHotkey()` :310-318 = `!SafeProcess()` and no Prisma panel focused.
- All of them additionally require `AIAgentFunctions.isGameFocused()` (native, `AIAgentFunctions.psc:14`; window focus [I]).
- CHIM's key bindings are private script variables (`_currentKeyVoice`, `_currentKey`, ... `:9-10`, set by `doBinding2` etc. :942-1058); there is
  no property/StorageUtil mirror -> **the glue cannot read which key is push-to-talk** (NOT FOUND: any getter). If the glue ever needs to know
  "the player started recording", the owner has to mirror the key in the glue's MCM.

### 1.2 Native side
- Recorder: `VoiceRecordThread(int)` DLL@0x2fd3f0 with `Key initially pressed`, `Recording stopped - key released` - polls the raw key; no menu/pause/dialogue
  string anywhere near it [V by absence, strings 0x2fcce0-0x2fd630]. Open-mic loop `openMicMonitoringLoop` DLL@0x2eddc8: no menu/dialogue gating strings [V by absence].
  Owner's live value: `Setting _openmic_enabled to false` (AIAgent.log:281) -> **the owner uses push-to-talk / text, not open mic.**
- `MenuTopicManager` is touched by the DLL in exactly these places (all call sites of the singleton getter rva 0xd640, which carries Address Library IDs
  0x7DB8F=514959 / 0x61ECB=401099 = `RELOCATION_ID(514959, 401099)`, plus inlined copies) [V]:
  bored gate (rva 0x20676c), rechat gates (0x1438a1, 0x14870a), `PumpPlayerMenuDelayedSelectionCapture` (0x20ad92), the player-menu-TTS helper (0xd6f7a/0xd74c0),
  the `TESTopicInfoEvent` handler (0x1b995a, reads `+0x68 speaker`), and the quest-progression actor resolver (0x1f18d0/0x1f1d50). **No input path reads it.**

### 1.3 What `_pause_dialogue_when_menu_open` really is
- MCM label "Pause Dialogue on Game Pause" - "Enable to pause dialogue during game pauses. Disable to allow dialogue to continue during game pauses."
  (`AIAgentMCMConfigScript.psc:875, 1392, 2830-2832`); default `_pauseDialogueStateDefault = false` (:276); live `Setting _pause_dialogue_when_menu_open to 0` (AIAgent.log:273). [V]
- Native effect: the playback loop pauses CHIM's own TTS voice while the game is paused (`game_paused_check` / `Pausing audio...game is paused` / `Resuming audio...game unpaused`,
  DLL@0x3026e8-0x302768) and the manager loop has `Game paused - timers paused` (DLL@0x30e280). [V strings; the conf->loop wiring is I]
- **It has nothing to do with the Dialogue Menu** (which never pauses the game). It only matters when something pausing is opened on top:
  the Prisma chatbox ("Chatbox focused - game paused (quickFocus={})", DLL@0x327940), `UITextEntryMenu`, Journal, MCM.

### 1.4 Prisma chatbox / text entry over a hidden Dialogue Menu
- Hotkey path is not blocked (1.1). Focusing the chatbox pauses the game [V string]. While paused, the engine's voiced line and CHIM audio both halt and resume afterwards [I].
- Input routing: the DLL installs `ChatboxInputSink@PrismaUIBridge` "at highest priority" (chim-dll-esp.md 1.3) - keystrokes typed into the chatbox should not reach the hidden
  DialogueMenu [I - must be tested: X4].
- Z-order/focus of Prisma's focus menu versus DialogueMenu (menuDepth 3): NOT FOUND in any source -> test X4.

### 1.5 The REAL input hazard is the hidden menu itself (from CHIM's SWF, which is the active one)
Sibling disassembly `research\p2_swf_disasm_chim.txt:336-355, 427-463` (cross-checked: identifiers present in the raw decompressed SWF, Appendix B) [V]:
- `onMouseDown` -> `onItemSelect({mouseClick:true})` -> if `bAllowProgress` and `eMenuState == TOPIC_LIST_SHOWN(1)` -> `onSelectionClick(true)`; if state is
  `SHOW_GREETING(0)` or `TOPIC_CLICKED(2)` -> `SkipText()` (= `GameDelegate.call("SkipText")`).
- `handleInput`: TAB -> `onCancelPress()` (greeting: SkipText; otherwise `StartHideMenu` -> `GameDelegate.call("CloseMenu")`); other nav keys go to the list when state is 1.
- DialogueMenu runs in the engine's Menu Mode control context (dialogue-engine.md 2.4: "context = kMenuMode") -> Accept (E/Enter/A), Cancel (Tab/B), W/S/arrows all reach the SWF [I from context].
=> While the menu is hidden, **a stray left click or E selects the highlighted topic, a click during a line SKIPS the line (Smart Talk's ini warns a skipped line may
not run its fragment), Tab closes the session (walk-away).** The push-to-talk key must not be E/Tab/Enter/W/A/S/D/arrows/mouse buttons, and the driver needs an input
neutraliser while hidden. Candidates for the UI-path probe (all [I], none tested): `_root.DialogueMenu_mc.bFadedIn=false` (disables `handleInput`, the same flag `StartHideMenu`
uses), forcing `eMenuState` to `TRANSITIONING(3)` while idle (makes `onItemSelect` a no-op), `TopicList.disableInput` (`bDisableInput`/`disableInput` exist in the SWF). The engine
re-drives the state machine after every response (`DoShowDialogueList` -> `ShowDialogueList` sets 3, the timeline then sets 1), so whatever is chosen must be re-asserted per layer.

### 1.6 Options for the design (evidence-based)
| Option | Works with CHIM? | Evidence |
|---|---|---|
| Keep sessions SHORT: open only to execute a chosen topic, read the next layer, close unless a choice layer is pending | Yes - the only variant that avoids `InterruptNPC` hitting an active line | 2.1 |
| Keep one hidden session for the whole conversation | No - every player utterance makes the DLL call `PauseCurrentDialogue` + `EndDialogue` on the speaker; player is frozen in Menu Mode with live menu input | 2.1, 1.5 |
| Voice input while a choice layer is pending (branch) | Input works; effect of `EndDialogue` on the open session UNKNOWN -> X1 | 2.1 |
| Text via Prisma chatbox / UITextEntryMenu during a session | Hotkeys pass; pauses the game; focus/z-order untested -> X4 | 1.3, 1.4 |

---

## 2. OUTPUT during a session

### 2.1 CHIM interrupts vanilla dialogue when the player addresses an NPC  (decisive)
Disassembly of `InterruptNPC(class RE::Actor *, class AIAgent *)` (function start rva 0x52890; name string DLL@0x2dca48) [V]:
```
528fb  lea rcx,"void __cdecl InterruptNPC(class RE::Actor *,class AIAgent *)"
52906  lea rcx,"Setting dialogue busy for  {}, isPlayerTeammate {}"      ; log
52953  mov rax,[r14] ; mov rcx,r14
52959  call qword ptr [rax+0x278]        ; vfunc 0x4F = TESObjectREFR::PauseCurrentDialogue (po3 fork name: StopCurrentDialogue)
5295f  mov rcx,r14
52962  call 0x27c640                     ; thunk: jmp [vtbl + (runtime==VR ? 0x6E0 : 0x6D0)]  -> vfunc 0xDA = Actor::EndDialogue
5296d  call 0x4eea0 ... 52a22 lea rcx,"Seems {} is talking to {} "      ; log only
52aec  call qword ptr [rax+0x250]        ; current-scene check
52c4c  "PrepareForDialog" / 52ca1 "AIAgentAIMind"                        ; Papyrus AIAgentAIMind.PrepareForDialog(npc) (:1821 "Before LLM response. User just talked.")
```
- vfunc indices: `PauseCurrentDialogue // 4F`, `EndDialogue(); // 0DA` - https://raw.githubusercontent.com/CharmedBaryon/CommonLibSSE-NG/main/include/RE/A/Actor.h (fetched this session) [V].
- Only caller: rva 0x152c37 inside `QueueInterruptNPC::<lambda_1>` (HTTPManager.cpp; strings DLL@0x304f50/0x304fe0; RTTI DLL@0x3c0610 gives the signature
  `QueueInterruptNPC(RE::Actor*, std::shared_ptr<AIAgent>, const std::string&, bool)`), queued from `HTTPManager::stream` /
  `streamInternal` (request kinds `HTTPStream`, `HTTPStreamGodMode`, `HTTPStreamRechat`). The only skip path found is for AUTOMATIC events
  (`[AUTO_ELIGIBILITY] Skipping automatic dialogue interrupt for {} (reason={})`, DLL@0x305030); the lambda also bails out when the actor is no longer valid. [V]
- Live proof of ordering: AIAgent.log:365 `Setting dialogue busy for  Lisette` at 06:29:05.897, :368 `[sendMsgStream] Starting sendMsgStream for speaker: Lisette` at 06:29:05.953 -
  i.e. the interrupt runs when the request is DISPATCHED, before the LLM answers. [V]
- What `Actor::EndDialogue()` does to an open MenuTopicManager session (menu stays open with a dead speaker? closes? walk-away fires?): **NOT FOUND**
  (CommonLib gives only the declaration). -> experiment X1. What `PauseCurrentDialogue` does to the End fragment of the line it cuts: NOT FOUND -> X2.

Design consequences:
1. Never have a session open at the moment a player utterance is dispatched, except when a choice layer is pending (then nothing is playing, only `EndDialogue` matters -> X1).
2. While a glue-clicked vanilla response is PLAYING, a new player utterance will cut it (possibly skipping its End fragment, X2). The glue cannot block CHIM's hotkeys.
   Mitigation [I]: keep responses uninterrupted by UX (a HUD cue "listening to <NPC>" while `IsInDialogueWithPlayer`/voice is active), and server-side: if an `inputtext*`
   arrives while the glue has a click in flight (`lrg_topic_exec` open), log it and re-read the list afterwards instead of assuming the fragment ran.
3. This CHIM behaviour exists today without the glue (talking over any vanilla quest line) - worth one line in the owner's notes.

### 2.2 Does CHIM still answer while the NPC is in vanilla dialogue?  Yes.
`AIAgent::isAvailableforDialog(bool)` (rva ~0x202300-0x202d40) returns false for: form not found, `{} is on conversation cooldown`, combat/attack/killmove with combat dialogue off,
`ACTOR IN SCENE (not allowed) by conf: {} {}` (`xor al,al` at rva 0x20299f), `{} is sleeping` (0x202c4c). The branch `SEEMS {} is talking to {} ` (rva 0x202a74) only logs and
falls through to the next test (no `xor al,al`, continues at 0x202ae4). [V disassembly]
- Owner's live value `Setting _restrict_onscene to 1, so AllowActorsOnScene is false` (AIAgent.log:307) -> NPCs inside a vanilla Scene are refused (good for quest scenes).
  MCM "NPC Scene Safety" (`AIAgentMCMConfigScript.psc:857, 1351, 2886`).

### 2.3 Bored / rechat are suppressed while a session is open
All three sites do `call 0xd640` (MenuTopicManager::GetSingleton) then test `byte [rax+0xB1]` (= `menuOpen`, dialogue-engine.md 2.4) [V]:
- rva 0x20676c -> `[BORED] Avoiding bored event because player is in dialogue` (DLL@0x30dd80); timer variant `[BORED_TIMER] Skipped - player busy (... dialogue:{})`.
- rva 0x1438a1 -> `[RECHAT {}] Avoiding rechat, player is in dialog`; rva 0x14870a -> `[RECHAT] Avoiding rechat event because player is in dialogue`.
=> During a hidden session no ambient CHIM chatter starts and other NPCs do not chime in after the session NPC's CHIM line. After the session closes they resume. No glue work needed.
   Side effect to remember: while a session is open, CHIM's multi-NPC rechat after the player's line is off.

### 2.4 CHIM speech versus the vanilla line (overlap, lips, queue)
- CHIM plays TTS through its own `AudioManager` source voice and drives the face itself (`get_facegen_anim_data`, `FaceGen lock acquired for expression override`, DLL@0x302650-0x303e10);
  it is not an engine dialogue line, so the engine will happily play a voiced INFO at the same time -> audible overlap and two writers on the face [I from V strings].
- CHIM solves the opposite order itself (2.1: it stops the vanilla line before it speaks). The glue must solve its own order: **do not click while the NPC is still speaking CHIM audio.**
  Tools [V]: `int function isActorTalking(String npc)` (`AIAgentFunctions.psc:33`), mod events `CHIM_SpeechStarted(Form)` / `CHIM_SpeechStopped(Form)` raised in Papyrus
  (`AIAgentAIMind.psc:1719, 1790, 1845`), `int function setLocked(int locked,String npc)` ":1 locks agent for talking, 0 releases" (`AIAgentFunctions.psc:32`; DLL log
  `[SPEAKERMANAGER {}] {} is locked, cannot speak`), `int function stopAllDialogue()` ":Stop speech and pending replies without changing NPC actions or conversation history" (:13).
- Wire order [V `HS/lib/data_functions.php:6004-6040` + pt2-latency.md]: speech sentences are TTS-synthesised and echoed as they stream; `processActions()` and the
  `action_post_process_fnct_ex` closures run after the loop; the command line is echoed last. Measured on this machine: LLM 2-3 s, TTS 3-10 s normal, 24-43 s under GPU contention.
  => on a SelectTopic turn the reply text is pure delay. An empty `message` is tolerated: `process()` returns "" (not -1), `$outputWasValid` stays true, actions are still processed
  (`data_functions.php:5937-5946, 6029`) [V code path; not exercised live].
- Template order LIVE on this install is `character, listener, mood, action, target, item, amount, message` (last template in `HS/log/context_sent_to_llm.log`, line 23245ff; code
  `HS/functions/json_response.php:375-397`, the branch taken when `$FEATURES["MISC"]["JSON_DIALOGUE_FORMAT_REORDER"]` is false = default, `conf.sample.php:600`) - `action` precedes
  `message`, so a prompt rule "if action is SelectTopic, message = \"\"" is easy for the model to follow. CAUTION: with `JSON_DIALOGUE_FORMAT_REORDER=true` the code puts `message` BEFORE `action`
  (`json_response.php:349-373`; the setting's own description in `conf_schema.json:84` claims the opposite) - the glue should read that global and warn, because then the model has already written its whole
  spoken reply before it picks the action. Core has a hard-coded precedent (`action=="Inspect"` with a target returns ""
  speech, `HS/connector/openrouterjson.php:1004-1009`) but **no hook** to do the same for a plugin action (hooks found: `JSON_TEMPLATE`, `XTTS_TEXTMODIFIER`, `BIOGRAPHY_BUILDER`,
  `VALIDATE_LLM_OUTPUT_FNCT`, `action_post_process_fnct(_ex)`). `$GLOBALS['FORCE_MAX_TOKENS']` exists (used by the installed glue) as a blunt cap.

### 2.5 How CHIM logs the vanilla player topic and NPC response (ground truth for the glue)
Source: the DLL's `TESTopicInfoEvent` handler `__skyrimScriptingPluginCallback7::<lambda_9>` (rva ~0x1b9700-0x1bb600; it reads `MenuTopicManager+0x68 speaker`) [V strings + xrefs]:
```
chat|{ts}|{gamets}|(Context location: {loc}){Player}: {topic text} ({Talking to|Whispering to|Shouting to} {NPC})     DLL@0x313fe0   tag "traditional_player_speech"
_speech|{ts}|{gamets}|{json: listener, location, speech, speaker, debug:"traditional_player_speech", ...}             DLL@0x2fec10 xref rva 0x1baebf
infonpc|{ts}|{gamets}|... (beings in range: ...)                                                                      xref rva 0x1bb336
chat|{ts}|{gamets}|(Context location: {loc}){NPC}: {response text}                                                    DLL@0x314198   tag "traditional_npc_speech" (+ key "audios")
```
plus chatbox pushes (`[Chatbox] Pushing player dialogue: {} says: {}` / `Pushing NPC dialogue`) and voice-sample capture (`[VOICE] Deferred voice sample captured for {} from dialogue menu: {}`).
The only place an INFO FormID appears is a log line (`(topic {:08X})`); **the payload carries no FormID** (answers open experiment E10: no).
Ambient lines use a different template: `chat|..|(Context location: {} background chat) {}: {}` (DLL@0x30ccb8).

Server side [V]:
- `chat` is a fast command (`HS/main.php:227-230`), handled by the log-only branch `HS/main.php:949-985` -> `logEvent($gameRequest)` -> `terminate()`. Rows logged this way get `delivery_state` NULL,
  which the context filter treats as visible (`chimBuildChatDeliveryStateSql($column, $states, $legacyDefault = 'spoken')`, `HS/lib/chat_helper_functions.php:4217`; live DB: all 6 `chat_background` rows have NULL).
- `logEvent` normalises the type (`chimNormalizeLoggedEventType`): data containing ` background chat)` -> type `chat_background` (`HS/lib/chat_helper_functions.php:5015`,
  `unittests/tests/EventTypeClassificationTest.php:40-52`); everything else stays `chat`.
- The server itself documents the convention: `HS/main.php:1355-1356` - `(type='chat' and ... and data like '(Context%')` with the comment "chat entries starting by "(Context%" are standard skyrim dialogue".
- `_speech` handler `HS/processor/comm.php:626-690`: inserts into table `speech(speaker, listener, speech, location, companions, audios, topic, utterance_id, ...)` with
  `topic = $speech["debug"]` (+ `|spatial:<reason>`). So vanilla lines are selectable with `speech.topic LIKE 'traditional_%'`.
- Live DB (read-only SELECT, 2026-09-21): `eventlog` has 51 `chat`, 6 `chat_background` rows; **no traditional-dialogue row exists yet** (the owner has not used the vanilla menu with CHIM running).
  Real ambient sample, confirming the location part of the shape: `(Context location: The Winking Skeever ,Hold: Haafingar background chat) Corpulus Vinius: Come on in. We got warm food, warm drinks, and warm beds.`
  => expected traditional shapes [I from V templates]: `(Context location: The Winking Skeever ,Hold: Haafingar)Corpulus Vinius: <line>` and
  `(Context location: The Winking Skeever ,Hold: Haafingar)Jordan: <prompt> (Talking to Corpulus Vinius)` - note: **no space after `)`** in the traditional templates. First probe run must confirm (X3).
- Capture switch: MCM "Vanilla Dialogue" - "When on, vanilla dialogue-menu conversations and nearby ambient NPC chatter are added to AI context. When off, neither is captured"
  (`AIAgentMCMConfigScript.psc:851, 2838-2840`); conf `_capture_background_chat`, default true (:96, :278), live `Setting _capture_background_chat to true` (AIAgent.log:275).
  The glue can read it: `AIAgentFunctions.get_conf_i("_capture_background_chat")` (`get_conf_i` :52; the MCM itself reads other keys this way, :485-494) - **must be 1, else warn.**

What the glue does with it:
- After a click, the server plugin reads `speech` rows with `topic LIKE 'traditional_%'` and `localts >= click_ts` (or the matching `chat` rows) as the authoritative transcript: which prompt the
  engine registered and what the NPC actually answered (success vs failure INFO of a persuade/bribe/intimidate is visible here as text).
- Duplicate-line hygiene [I]: the player's own words are already in the log as `inputtext*`; the engine then adds the vanilla prompt as a second player line. Either keep both (harmless, slightly odd) or
  drop/replace the `chat` player row in the glue's `preprocessing.php` hook (runs at `main.php:193`, before the fast-command log branch; the installed glue already `terminate()`s custom types there)
  when it matches the option text of the in-flight click. Never drop the NPC row.

### 2.6 Conversation cooldown etc.
`{} is on conversation cooldown` (conf `_end_conversation_cooldown`) only follows CHIM's own `EndConversation` action; the glue should `unsetFunction('EndConversation')`-style hide it on a turn where a
choice layer is pending [I] (the installed glue already lists it among `LRG_MOVEMENT_ACTIONS`).

---

## 3. The player-TTS click gate in CHIM's `dialoguemenu.swf`

Facts [V - `research\p2_swf_disasm_chim.txt:480-517` (sibling disassembly), raw listing re-checked: inside `onSelectionClick` the only pushes are `timerBool`, the frame label `topicClicked`, `initMenu`;
the string `TopicClicked` (GameDelegate call) occurs only in `startTopicClickedTimer` and `topicClicked`; my own byte scan of the decompressed SWF: `TopicClicked` x1, `PlayMenuTopic` x1, `SendModEvent` x1]:
```
onSelectionClick(abMouseClick): timerBool = true; [SetSelectedIndexByMouse]; eMenuState = TOPIC_CLICKED; play "topicClicked"; TextCopy...; initMenu()
initMenu():                     skse.SendModEvent("PlayMenuTopic", <selectedEntry.text up to " (", sanitised>)          <- NO TopicClicked here
startTopicClickedTimer(id):     if (id == "off") { timerBool = false; GameDelegate.call("TopicClicked", [TopicList.selectedEntry.topicIndex]) }
                                else { timer = setTimeout(this, "topicClicked", round(words*60/300*1000)+1400) }
topicClicked():                 timerBool = false; GameDelegate.call("TopicClicked", [TopicList.selectedEntry.topicIndex])
DoShowDialogueList(...):        only acts when timerBool == false
```
Release side [V]:
- Papyrus handlers exist (`OnPlayerMenuTopicSelected` / `OnPlayerMenuTTSFinished`, `AIAgentPapyrusFunctions.psc:1170-1195`) but are UNREGISTERED in 3.3.2
  (:1097-1100 "Traditional dialogue Player TTS is handled in native code. Re-registering the Papyrus bridge here can resume the held topic twice.").
- Native: `PlayerMenuModCallbackEventSink@ProcessorPlayerMenuModEvent` (RTTI DLL@0x3d1530) listens for `PlayMenuTopic` (DLL@0x30ecc0); next string `Player TTS for Traditional Dialogue is disabled`
  (DLL@0x30ecd0) = immediate release path; release = Scaleform invoke of `_root.DialogueMenu_mc.startTopicClickedTimer` (DLL@0x2ed678; `[PlayerMenuTTS] Releasing held topic for '{}' ({})`).
  With the feature ON: request `player_menu_tts_prefetch` / `player_menu_tts_play` (server `HS/processor/comm.php:1162-1216`, synthesises the player's line, echoes
  `Player|ScriptQueue|...//__player_menu_tts///1.0`, does NOT `logEvent`), watchdogs `player TTS did not start within 7 seconds`, `[PlayerMenuTTS] Timed out waiting for selected dialogue topic; replaying original click`,
  `[HTTPManager] Released dialogue menu after stream timeout without a response for {}`.
- A second, input-level fallback exists for unpatched/VR menus (`PlayerMenuReplayUserEventData`, user-event names `accept/click/select/...`, `DispatchPlayerMenuDelayedSelection`) - it reacts to real input events only [I];
  a Scaleform invoke from Papyrus does not pass through it.
- Default OFF (`_playerTtsTraditionalDialogueStateDefault = false`, MCM :277; reset handler :2094-2097); live OFF (AIAgent.log:274).

Behaviour on a scripted click:
| Route | What happens | Depends on `_player_tts_traditional_dialogue` |
|---|---|---|
| R1: invoke `_root.DialogueMenu_mc.onSelectionClick` (DSN-style) | SWF holds, fires `PlayMenuTopic`; DLL releases at once when the feature is 0; when 1 the player's TTS voice SPEAKS the vanilla prompt (the player already said it aloud) and the click waits for that audio (up to the 7 s watchdog) | Yes |
| R2: set the selection, then `UI.InvokeString("Dialogue Menu", "_root.DialogueMenu_mc.startTopicClickedTimer", "off")` | Direct `GameDelegate.call("TopicClicked", [selectedEntry.topicIndex])`; no mod event, no TTS, `timerBool=false` so the next list shows | No |
R2 is exactly CHIM's own release call (`:1174`), so it is the best-evidenced Papyrus click path available; it skips the SWF's visual state change (`eMenuState=TOPIC_CLICKED`), which is irrelevant while hidden but must
be checked against `DoShowDialogueList`'s state test in the probe [I]. On Norden UI's SWF (if CHIM ever loses the file conflict) `startTopicClickedTimer` does not exist -> the driver must feature-detect
(`UI.GetBool/GetString` on a CHIM-only member, or test R2 then fall back to R1) [I].

What to set / tell the owner:
- Keep **Player TTS for Traditional Dialogue = OFF** (it is). Glue reads `AIAgentFunctions.get_conf_i("_player_tts_traditional_dialogue")` at session start; if > 0: use R2 only, and show one MCM warning
  ("CHIM would voice every quest line you pick a second time").
- Keep **Vanilla Dialogue (capture) = ON** (2.5). Keep **NPC Scene Safety = ON** (2.2). "Pause Dialogue on Game Pause" is irrelevant to sessions (1.3).
- The entry text the SWF holds is `TopicList.entryList[i].text / .topicIsNew / .topicIndex` with `TopicList = TopicListHolder.List_mc` (`p2_swf_disasm_chim.txt:289, 381-399`) - exact `UI.GetString` paths belong to the UI-path probe.

---

## 4. ACTIONS - offering "select a vanilla topic" inside CHIM's constraints

### 4.1 Constraints (all verified earlier, re-checked where cited)
- Strict JSON: only `target`, `item`, `amount` can be filled (server-lifecycle-actions.md 2.3; installed glue relies on it, `ext/lorerim_glue/lib/lrg_actions.php:8`).
- Actions only on `inputtext, inputtext_s, ginputtext, ginputtext_s, narrator_inputtext, instruction, welcome, cheatmode` (`HS/main.php:1077-1079`) - re-read this session [V].
- ext `functions.php` runs after `ENABLED_FUNCTIONS` is loaded and before the final filter; post-LLM gate `$GLOBALS["action_post_process_fnct_ex"][]` sees full wire lines
  (`HS/lib/data_functions.php:6029-6040`) [V]. Installed glue already uses both (`ext/lorerim_glue/functions.php`).
- `metadata.followup`: a funcret follow-up = a second LLM+TTS request under the MAIN semaphore (pt2-latency.md: 8.3 s) -> **off** for this action; the vanilla line IS the reaction.
- The game polls the server queue (`request`) every ~5 s (eventlog `request` rows at 5 s spacing, live DB) -> an asynchronous server->game push costs up to 5 s. Synchronous replies are needed.

### 4.2 The action (fits)
One catalog row, same pattern as the installed `ExtCmdLRG_SceneControl`:
- code `ExtCmdLRG_SelectTopic`; LLM-facing name e.g. `TakeUpBusiness`; parameter `item` (string, required); `requirements.request_types_any` = the player-speech types; `followup.enabled=false`;
  `confirmation.default_policy=automatic` (irreversible options use the design's two-step confirm instead); `suppress_placeholder_infoaction=true`.
- `item` = an option key `T1..T8` when the prompt contains a list for this NPC, otherwise the player's intent in a few plain words ("accept the job", "ask for work", "try to bribe him").
- Prompt block (volatile -> `prompt_bottom`): `<business_with_player gen="s12-l0-g7">T1: ... | T2: ... (persuade) | T3: ... (bribe, 250 gold) ... </business_with_player>` + rules: use the action only when the
  player actually said that; **leave `message` empty when you use it** (the character's real line follows); never announce the outcome of persuade/bribe/intimidate.
- Post-LLM gate: drop unless (a) offered this request, (b) key exists in the offered gen OR intent text non-empty, (c) request type allowed, (d) speaker == list owner. Rewrite the parameter to
  `gen:key:refid` so the game can reject stale gens.
- **Service / money arbitration (LoreRim + Requiem compatibility).** CHIM ships its own re-implementations of vanilla services as LLM actions; live catalog (`combined_core_action`, read-only SELECT):
  `RentRoom` (active), `HireCarriage` (active), `Training` (active), `TradeItems` (in the live action list), `TakeGoldFromPlayer` (row exists, `is_activated=false` on this install), `GiveGoldTo` (active).
  `AIAgentAIMind.RentRoom(Actor player, Actor innkeeper, int cost, String playerName)` (`AIAgentAIMind.psc:3080-3108`) sets the bed owner, `Variable09` and moves a CONFIG cost of gold itself - it never runs the
  innkeeper's real INFO, so anything LoreRim/Requiem/survival/inn mods hang on that topic (price globals, fragments, quest hooks) is skipped. Rule [I]: when the engine's list for this NPC contains the real service or
  bribe entry, the glue hides the overlapping CHIM action for that turn (`lrgHideActions([...])` pattern already in the installed plugin) so the player is not charged twice and modded prices/effects apply;
  when no list is known, leave CHIM's actions alone.

### 4.3 The timing problem, precisely
- The player's utterance travels **DLL -> server directly** (voice: `VoiceRecordThread` -> STT -> `inputtext_s`; text: `sendMessageToActor`). Papyrus never sees it and cannot delay it.
- The topic list exists **only while a session is open**, and a Papyrus-only glue can read it only from the SWF.
- The LLM call starts ~0.5 s after the request arrives (pt2-latency.md: pre-LLM 0.47-0.56 s).
=> For the list to be in THIS turn's prompt it must already sit in the server DB when the request arrives, i.e. it must have been read in an EARLIER session. There is no game-side "conversation start"
event before dispatch: the first hook is `InterruptNPC`/`PrepareForDialog` at dispatch, and that is native->`AIAgentAIMind` (a global function of CHIM's script, not an event the glue can subscribe to).
`CHIM_TextReceived` / `CHIM_SpeechStarted` fire only when the reply comes back [V `AIAgentAIMind.psc:1419-1430, 1719`].

### 4.4 Candidate flows (latencies from this machine's measurements: STT ~1 s [I], LLM 2-3 s, TTS 3-10 s normal, fast glue message ~8-70 ms, game poll 5 s)
**(A) Snapshot at conversation start (open hidden, read, close), refresh after each execution.**
- Trigger problem: no pre-dispatch event (4.3). The only candidates are the glue's 20 s crosshair snapshot (would open sessions on every NPC the player looks at - unacceptable) or a mirrored
  push-to-talk key (owner must configure; opens a session on EVERY voice line to anyone, mostly for nothing).
- Race: open (~0.1-0.5 s [I]) + greeting (1.5-4 s; whether `PopulateDialogueList` arrives before the greeting ends is untested) + read + send, against "player finishes speaking + STT" (2-5 s). Often lost.
- Side effects per snapshot: the greeting is voiced while the player is talking into the mic; Say-Once greetings / greeting fragments are consumed (legitimately - it IS a real conversation start - but for nothing);
  `GetTalkedToPC` flips; dialogue camera + Menu-Mode freeze; closing = Goodbye bark; and if the utterance lands while the snapshot session is still open, CHIM's `InterruptNPC` hits it (2.1).
- One LLM call when it works. **Rejected as the primary flow.**

**(B) Keep the hidden session open for the whole conversation.** List always known, one LLM call, no re-open cost. But: every utterance -> `PauseCurrentDialogue` + `EndDialogue` on the speaker (2.1);
the player stands frozen in Menu Mode with a live invisible menu (1.5) for minutes; CHIM actions that move the NPC (Follow, Trade, ...) fight the dialogue package; bored/rechat off the whole time (2.3);
engine Goodbye INFOs close it anyway. **Rejected.**

**(C) Two-pass: LLM decides "this is business", then the glue opens a session and resolves the entry.**
1. Turn arrives; prompt has no list (first contact) -> LLM returns `ExtCmdLRG_SelectTopic`, `item` = intent words, `message` = "". Command reaches the game ~STT 1 s + LLM 2.5-3 s.
2. Game: wait until `isActorTalking(npc)==0`, `Activate` -> hide -> wait for the list -> read N entries.
3. Game -> server, synchronous: `requestMessageForActor("<gen>|<refid>|T1=...|T2=...", "lrg_topics", npc)`; the glue's `preprocessing.php` matches intent -> entry and answers IN THE SAME HTTP RESPONSE with a wire line
   (`<npc>|command|ExtCmdLRG_ClickTopic@gen:idx`) and terminates. Matching ladder: exact/lexical -> TXT2VEC similarity (installed, ~0.1 s [I]) -> Fast-connector side call (~1-2 s) -> "no match".
   **[I - unproven]**: that the DLL executes command lines in the reply to a CUSTOM request type answered from `preprocessing.php`. Server-side precedents [V]: `togglemodel` - sent by Papyrus with the
   fire-and-forget `AIAgentFunctions.logMessage("Model change requested","togglemodel")` (`AIAgentPapyrusFunctions.psc:441`) - is answered with `echo "{$GLOBALS["HERIKA_NAME"]}|command|ToggleModel@$newModel\r\n"`
   (`HS/processor/comm.php:1019-1024`) and `ToggleModel` is in the DLL's `parseCommand` literal list (chim-dll-esp.md 1.5), i.e. the DLL does parse `|command|` lines in the body of a non-LLM reply;
   `just_say` returns speech lines (`comm.php:1219-1223`); `terminate()` closes any reply with `X-CUSTOM-CLOSE` (`HS/lib/auditing.php:26-45`). Whether `ExtCmd*` commands take the same route when the reply
   belongs to `logMessageForActor`/`requestMessageForActor` of an unknown type is the thing to prove - probe X5. If it does not work, fall back to the 5 s queue or to a tiny LLM request type.
4. Game clicks (R2), the real line plays, glue re-reads the next layer, sends it (`lrg_topics`, fast) and closes if the layer is empty/top-level and no choice is pending.
- Added latency over a normal CHIM turn: session open + greeting wait (0.5-4 s) + match (0.1-2 s). End-to-end from end of speech to the NPC's real line: **~5-9 s**, comparable to today's 5-11 s CHIM turn because the TTS is skipped.
- Failure modes: false positive (session opened, nothing matches -> greeting + goodbye bark for nothing; mitigate with a minimum similarity and by telling the LLM what the NPC is known to deal in);
  false negative (player retries or uses the emergency menu); greeting plays on open (acceptable: it happens right after the player addressed the NPC, and it is logged as the NPC's line);
  close = Goodbye bark (skip by staying silent is NOT possible; acceptable once per business exchange); walk-away topics only fire when a choice layer is abandoned.

**Recommended: C with a server-side list cache ("C+")** - the first business exchange with an NPC costs the two-pass; from then on the server holds that NPC's last top-level list
(keyed by refID; stored with `gen`, gamets, location and `max(questlog.rowid)` as a staleness signature) and injects it BEFORE the LLM call, so later turns are single-pass with exact keys (`item="T3"`), and the NPC can
bring business up unprompted. Lists are harvested for free from every session that happens anyway: glue-opened executions, the post-response re-read, and ENGINE-opened sessions (ForceGreet, guards, couriers)
through the same handler. Execution always re-validates against the live list by text; mismatch -> re-read -> re-match (step 3) -> else report `stale`. Invalidate the cache on a new `questlog` row, location change,
or age. **Snapshots are never taken speculatively, so Say-Once greetings are never burnt for awareness alone.**

### 4.5 Branch layers (choice pending) under C+
After a click the next layer is read and sent; if it contains real choices the session STAYS OPEN (closing = walk-away) and the server injects that layer for the next utterance. The player then speaks ->
CHIM dispatch -> `InterruptNPC` on the session NPC (2.1). Nothing is playing, so only `Actor::EndDialogue()` matters. **X1 decides**: if the session survives, voice-driven branches work; if it dies, the fallback for
choice layers is to UN-HIDE the vanilla menu for that layer only (the watchdog path the design already has), or to let the player answer with a glue hotkey wheel - still no per-quest data.

---

## 5. QUEST AWARENESS

### 5.1 What the server stores (live, read-only SELECTs 2026-09-21)
- `quests` (from `_quest` JSON, `HS/processor/comm.php:381-406`: `name, briefing<-currentbrief, data<-currentbrief2, stage, giver_actor_id<-data.questgiver, id_quest<-formId, status`): **0 rows.**
  The DLL procedure exists (`ProcedureSendActiveQuests`, DLL@0x2dc940; AIAgent.log shows it logging `Quest  The Man Who Cried Wolf` etc.) but nothing is stored on this install
  (server ignores entries with empty `currentbrief`). Do not build on it.
- `questlog` (from `_uquest|..|{id}@{?}@{briefing}@{stage}`, `comm.php:410-431`; DLL template DLL@0x314748, sent from the `TESQuestStageEvent` path): **299 rows, 116 distinct quests**; `id_quest` = quest EDITOR ID
  (`MS07`, `_ME_QuestWhiterunFindTheDog`, `DialogueSolitudeGuardIntro`, `REQ_*`, mod quests included), `briefing` = current objective text, `stage`; `name/editor_id/giver_actor_id` are never filled (0 rows).
  **48 rows still carry unresolved tags**: `<Alias=QuestGiver>`, `<Alias.ShortName=Item>`, `<Global=NN01Count>` ... -> CHIM's DLL does NOT resolve objective text replacement (answers REVIEW_40 item 5's open point for `_uquest`).
  It also contains non-journal bookkeeping quests (`000FCQuestStatus...`).
- `quest` request (`quest|..|(Context location: L) Quest Updated "{}" new objetive: {}`, DLL@0x314770) only becomes a narrator comment when enabled; live `QUEST_COMMENT=false` (conf_opts) and there are no `quest` rows in `eventlog`.
- `<active_quests>` reaches only followers/Narrator (server-quest-dialogue.md item 9) - ordinary NPCs see nothing today.

### 5.2 Injecting only what is relevant to THIS NPC, current objective only
1. Relevance comes from the game, not from text matching: the glue's existing NPC snapshot (`lrg_npcstate`) adds `q=<editorID>,<editorID>...` from
   `PO3_SKSEFunctions.GetActiveAssociatedQuests(ObjectReference akRef, Bool abAllowEmptyStages = True)` (`PO3_SKSEFunctions.psc:812`, returns quests where the ref fills an alias) +
   `PO3_SKSEFunctions.GetFormEditorID` (used by CHIM itself, `AIAgentPlayerScript.psc:292`) / SKSE `Quest.GetID()` (used at `AIAgentAIMind.psc:1941`). Cap ~6.
2. Server joins on `questlog.id_quest`, takes the newest row per quest (`DISTINCT ON (id_quest) ... ORDER BY id_quest, rowid DESC`), keeps only quests that have a row (= have shown an objective), drops bookkeeping
   quests (no displayed objective / name pattern), and rewrites leftovers: `<Alias=QuestGiver>` -> "the quest giver" (or the NPC's own name when the snapshot says this NPC fills an alias of that quest),
   other `<Alias=X>` -> "the X", `<Global=...>` -> "some". No stage numbers, no future stages, nothing from CHIM's `skyrim_quest_definitions` (`npc_facts` there contain later beats = spoilers).
3. The strongest relevance signal is the engine's own list: a cached/just-read topic list for this NPC (4.4) already says what business exists; objectives only add "why".
4. Inject in `prompt_bottom` as words: `<shared_business>You and the player have unfinished business: "Return the dog to <you>".</shared_business>`.

### 5.3 Feeding the result of a clicked topic back as ground truth (next turn)
Server plugin stores `click_ts`/`gen` when it emits the click. On the next request for that NPC it collects, newer than `click_ts`:
(a) `speech` rows with `topic LIKE 'traditional_%'` (what was really said, 2.5), (b) new `questlog` rows (objective changes; `_uquest` is a fast command, typically logged within ~10-70 ms of the stage event),
(c) the new layer/list. It injects one block: `<what_just_happened>You said: "...". The player's journal now says: "...". </what_just_happened>` and tells the LLM these are facts.
No glue journal walker is needed; stage-only changes without an objective produce no `_uquest` text and are simply invisible (acceptable).

---

## 6. CHIM's beta "AI Quest Progression" must stay OFF - detection

- Setting: general setting `CHIM_AI_QUEST_PROGRESSION` (table `general_settings`), fallbacks `$GLOBALS['CHIM_AI_QUEST_PROGRESSION']` and `conf_opts.chim_ai_quest_progression`
  (`HS/lib/chim_quest_engine.php:110-168` `chimQuestEngineFeatureEnabled()`). Live: `CHIM_AI_QUEST_PROGRESSION | false`, `CHIM_PLAYER_ONLY_QUEST_ADVANCEMENT | true`. [V]
- `lib/chim_quest_engine.php` is required at `HS/main.php:53`, i.e. before the ext `globals.php` hook (main.php:54 per the installed glue's header) -> the glue can simply call
  `function_exists('chimQuestEngineFeatureEnabled') && chimQuestEngineFeatureEnabled()` once per request (it caches in a static). [V]
- When true: (1) do not offer `ExtCmdLRG_SelectTopic` for quests present in `skyrim_quest_definitions` with `active=true` - or simpler and safer: stand the whole menuless module down; (2) log one line to `lorerim_glue.log`;
  (3) surface it in game with the wire line CHIM itself uses for notices: `"The Narrator|rolecommand|DebugNotification@..."` (`HS/lib/dynamic_update_util.php:17`) - rate-limited to once per session.
- Game side cross-check (optional): the DLL logs `[QuestProgression] Server global setting changed to {}` and polls `quest_status` (`HS/gamedata.php:160-163`); the glue has no Papyrus accessor for it -> server-side detection is the one to build.

---

## 7. Settings checklist for the glue's MCM / self-test
`int function get_conf_i(String code) Global Native` (`AIAgentFunctions.psc:52`). Which codes the native answers was checked in the DLL: the function body (rva ~0xe5c00-0xe6910, ends with
`Unknown configuration code: {}`) contains literal refs to `_player_tts_traditional_dialogue` (rva 0xe65f4), `_pause_dialogue_when_menu_open` (0xe65c3), `_rechat_policy_asap`, ... and INLINED 8-byte immediates
`_capture`+`_backgro`+`und_chat` (rva 0xe6635), `_restric`+`t_onscen` (0xe6694), `_openmic`+`_enabled` (0xe6717). Each of these strings occurs in code exactly twice: once here, once in `setConf` (rva 0xf7xxx). [V]

| CHIM setting (conf key) | Needed | Live now | How the glue reads it |
|---|---|---|---|
| Vanilla Dialogue (`_capture_background_chat`) | 1 | true (log:275) | `AIAgentFunctions.get_conf_i("_capture_background_chat")` [V key handled] |
| Player TTS for Traditional Dialogue (`_player_tts_traditional_dialogue`) | 0 (or use click route R2) | 0 (log:274) | `get_conf_i("_player_tts_traditional_dialogue")` [V; CHIM's MCM does the same, :494] |
| NPC Scene Safety (`_restrict_onscene`) | 1 | 1 (log:307) | `get_conf_i("_restrict_onscene")` [V key handled] |
| Open mic (`_openmic_enabled`) | either; if ON the mic hears the vanilla line (speakers) | false (log:281) | `get_conf_i("_openmic_enabled")` [V key handled] |
| Pause Dialogue on Game Pause (`_pause_dialogue_when_menu_open`) | irrelevant | 0 (log:273) | - |
| `CHIM_AI_QUEST_PROGRESSION` | false | false | server: `chimQuestEngineFeatureEnabled()` |
| `$FEATURES["MISC"]["JSON_DIALOGUE_FORMAT_REORDER"]` | false (action before message) | false/unset (live template order) | server: `$GLOBALS["FEATURES"]["MISC"][...]` |
| Push-to-talk key | not E/Tab/Enter/W/A/S/D/arrows/mouse | unknown (private var) | owner tells the glue |
Return convention of `get_conf_i` for the boolean keys (0/1) is [I]; the first probe should print the four values once.

---

## 8. Experiments this report adds (ordered by value; X1-X4 need NO glue code)
- **X1 (gate for voice-driven branches):** open the normal vanilla menu with a quest NPC, wait until the topic list shows, then talk to that NPC through CHIM push-to-talk. Observe: does the menu close? can a topic still be
  clicked afterwards and does it work? Then repeat at a choice layer (e.g. a yes/no). AIAgent.log will show `Setting dialogue busy for  <npc>` and
  `[LISTENER-RESOLVE] Selected '<npc>' via <how> ...` (DLL@0x307a00 region) - the second line also answers whether CHIM still targets the session NPC when the crosshair is unavailable in Menu Mode [I: it may fall back to nearest/gaze].
- **X2:** click a topic whose response ends with a stage change (any "I'll do it" line), and speak via CHIM DURING the NPC's line. Did the line cut? Did the quest still advance (End fragment)?
- **X3:** pick any vanilla topic once with CHIM running, then `SELECT type,data FROM eventlog WHERE type='chat' AND data LIKE '(Context%' ORDER BY rowid DESC LIMIT 4;` and
  `SELECT speaker,listener,speech,topic FROM speech WHERE topic LIKE 'traditional_%' ORDER BY rowid DESC LIMIT 4;` - confirms the exact row shapes of 2.5 (spacing, player suffix, one row per response or per INFO).
- **X4:** with the vanilla menu open: press the CHIM text key (UITextEntryMenu) and the Prisma chatbox key. Do they open, take focus, and does typing leak into the dialogue list (selection moves / Tab closes)?
- **X5 (probe build):** does the DLL execute a `|command|` line returned in the HTTP reply to `requestMessageForActor(..., "lrg_topics", npc)` answered from `preprocessing.php`? If not, measure the 5 s queue path.
- **X6 (probe build):** click route R2 (`startTopicClickedTimer "off"` after setting the selection) versus R1; does the next list show when `eMenuState` was never set to TOPIC_CLICKED?
- **X7 (probe build):** does `isActorTalking(npc)` / `setLocked(1,npc)` reliably keep CHIM audio off the NPC for the duration of a vanilla response?

---

## Appendix A - small corrections to earlier reports
- dialogue-engine.md 2.8 / E10: the `TESTopicInfoEvent` handler logs vanilla lines as `chat` (+ `_speech`, + one `infonpc` "beings in range" record), not as `infonpc|...` dialogue records; no INFO FormID in any payload.
- CHIM's `SayTo` native builds the console command: strings `SayTo ` + `{:x}` next to `Papyrus::SayTo` (rva ~0xd7f85-0xd8023) - same technique as OStim; resolves the "mechanism NOT FOUND" note in dialogue-engine.md section 3.
- `OSANative.EndPlayerDialogue()` (installed OStim, `OSANative.psc:66`, global native, no doc comment) is a second Papyrus-callable "end the player's dialogue" candidate next to the SWF's `onCancelPress`; its implementation was not read (GitHub code search needs login; guessed file path 404).

## Appendix B - reproduce (all read-only; files under `%TEMP%\p2chim\`)
- `dllstr.py` -> `dll.txt` (offset + ASCII strings >= 5). `pe.py` (section table, string xref scan for `48/4C 8D /r` RIP-relative LEA). `callers.py <rva>` (E8 rel32 call sites). `imm.py <hex>` (imm32 scan, used for Address Library IDs).
- `dis.sh START STOP OUT` = `objdump -D -b binary -m i386:x86-64 -M intel --adjust-vma=0xC00 --start-address=.. --stop-address=.. /mnt/f/.../AIAgent.dll`; `ann.py` annotates `# 0x...` operands with the string at that rva.
- DB: `PGOPTIONS='-c default_transaction_read_only=on' psql -h localhost -U dwemer -d dwemer` (SELECT only) - scripts `db1.sh`..`db4.sh`.
- SWF: `swfchk.py` (zlib-decompress CWS, count identifier occurrences): `TopicClicked` 1, `topicClicked` 2, `PlayMenuTopic` 1, `SendModEvent` 1, `startTopicClickedTimer` 1, `timerBool` 1, `entryList` 4,
  `doSetSelectedIndex` 3, `SetSelectedTopic` 2, `disableInput` 3, `bDisableInput` 3, `bAllowProgress` 1, `eMenuState` 1.


---

## Verification (adversarial pass)

Date 2026-09-21. Independent re-check of the 15 load-bearing claims: every cited Papyrus / PHP / ini line re-opened, the DLL re-disassembled with my OWN scripts
(`%TEMP%\p2verify\`: `pe.py` section table, `disann.py` objdump + RIP-relative string annotation, `callers.py` E8/E9 + imm32 scan, `xref.py` LEA xrefs, `globref.py`
global readers/writers, `swfchk.py` own CWS decompress + identifier counts, `db1.sh`/`db2.sh` read-only SELECTs, `g15.py` tally of real LLM outputs), the CK-wiki and
CommonLibSSE-NG pages re-fetched. PE check: `.text` va 0x1000 raw 0x400 (delta 0xC00), `.rdata` delta 0x1A00, file size 4,134,912 - the report's address arithmetic is right.
**[V]** = I saw it myself this pass. **[I]** = inference.

### V.1 Verdict per claim

| # | Claim (short) | Verdict | What I re-checked |
|---|---|---|---|
| 1 | Hotkeys gated only by `SafeProcess()`/`IsInMenuMode()`; Dialogue Menu is not menu mode | CONFIRMED | `AIAgentPapyrusFunctions.psc:1228-1251`, :310-318, :382-446, :611-823 read in full; grep of all CHIM scripts for `"Dialogue Menu"`: only :1174/1182/1192/1193 and `AIAgentAIMind.psc:1438` (see V.3-8). Wiki page re-fetched: both quoted sentences are there verbatim. `modlist.txt`: no pause-dialogue / Skyrim Souls mod; CHIM (:8) outranks Norden UI 16x9 (:148), Norden 21x9 is disabled (:30), LoreRim Glue (:2) ships no `dialoguemenu.swf`. |
| 2 | `InterruptNPC` = `PauseCurrentDialogue` + `EndDialogue` at dispatch | CONFIRMED, one sub-claim WRONG, one big omission | rva 0x52959 `call [rax+0x278]`, 0x52962 `call 0x27c640`; thunk body `mov edx,0x6d0 / mov ecx,0x6e0 / cmp byte [rax+0x118],4 / cmove / jmp [vtbl+rdx]` [V]. Only E8 caller of 0x52890 = 0x152c37 [V]. Actor.h re-fetched: `PauseCurrentDialogue(void) override; // 04F`, `SKYRIM_REL_VR_VIRTUAL void EndDialogue(); // 0DA` [V]. Log :365/:368 [V]. **Wrong:** "only skip path is AUTO_ELIGIBILITY" - see V.2-C. **Omitted:** the same function also swaps the NPC's voice type - see V.3-1. |
| 3 | "in dialogue" = `MenuTopicManager+0xB1`; bored/rechat suppressed; no input path reads it | CONFIRMED | getter 0xd640 carries 0x7DB8F/0x61ECB (=514959/401099, matches `RELOCATION_ID(514959, 401099)` in MenuTopicManager.h, re-fetched). Call sites 0xd6f7a, 0x1438a1, 0x14870a, 0x20676c, 0x20ad92 + imm copies 0xd74c0, 0x1b995a, 0x1f18d0, 0x1f1d50 - identical to my scan. 0x1438a6 stores the byte to `[rsp+0x5c]`, tested at 0x143929 -> "Avoiding rechat, player is in dialog". Name `menuOpen // B1` is from powerof3/CommonLibSSE (NG calls it `unkB1`) [V both headers]. **Live proof it fires:** AIAgent.log:1778-1809 + :1788 (V.2-A). |
| 4 | NPC in vanilla dialogue is still "available" | CONFIRMED | 0x202300-0x202d40: `xor al,al` after form-not-found (0x202415), cooldown (0x20256d), not-actor (0x2025fc), ACTOR IN SCENE (0x20299f), sleeping (0x202c4c); no `xor al,al` between the SEEMS string (0x202a74) and the sleeping block; scene test = `call [rax+0x250]` = vfunc 0x4A `GetCurrentScene` (TESObjectREFR.h re-fetched). Log :307 [V]. |
| 5 | `_pause_dialogue_when_menu_open` semantics | CONFIRMED | MCM :276, :875, :1392, :2830-2832; log :273; Papyrus comment `AIAgentPapyrusFunctions.psc:315` "Prisma focus pauses the game too" corroborates the chatbox string. |
| 6 | CHIM SWF is a click gate; `startTopicClickedTimer("off")` is CHIM's own release | CONFIRMED | Own decompress (CWS v10, 24,908 -> 77,300 bytes): NUL-terminated `TopicClicked` x1, `PlayMenuTopic` x1, `SendModEvent` x1, `startTopicClickedTimer` x1, `timerBool` x1 - same as Appendix B. Raw listing: `"TopicClicked"` pushed only at 3B5B and 3C8D (= `startTopicClickedTimer`, `topicClicked`); `"timerBool"` at 319E (DoShowDialogueList), 370D (onSelectionClick), 3B30, 3C62, 3D6D. Path string `_root.DialogueMenu_mc.startTopicClickedTimer` is literally in `AIAgentPapyrusFunctions.psc:1174/1182/1193`. `UI.InvokeString` exists (`UI.psc:93`). |
| 7 | Hidden menu still reacts to mouse / Tab | CONFIRMED with a nuance | Raw listing of `handleInput` (2CC9): `if (bFadedIn && IsKeyPressed(details)) { TAB -> onCancelPress; else if ((nav != UP && nav != DOWN) \|\| eMenuState == TOPIC_LIST_SHOWN) list.handleInput }` - so ENTER/accept is forwarded to the list in EVERY state, only UP/DOWN are state-gated (report said "other nav keys go to the list when state is 1"). See V.3-6 for the second Tab path. |
| 8 | Vanilla lines reach the server as `chat` "(Context location..." + `speech.topic = traditional_*` | Templates CONFIRMED; two citations off; the "no rows because the owner never used the menu" inference is REFUTED | Templates/tags/xrefs [V]: player template rva 0x3159e0 xref 0x1bab8a, tag xref 0x1bae31, `_speech` 0x1baebf, `infonpc` 0x1bb336. See V.2-A/B. |
| 9 | Action line after all TTS; empty message tolerated; "action before message" live | First half CONFIRMED; the ORDER claim is WRONG where it matters | `data_functions.php:5931-6040` [V]. See V.2-D. |
| 10 | 5 s queue poll; synchronous command precedent | CONFIRMED | `request` rows localts ...135/...140/...145/...150/...155 (two per tick) [V]; `comm.php:1019-1024`, `auditing.php:26-45`, `AIAgentPapyrusFunctions.psc:441` [V]. |
| 11 | `quests` empty; `questlog` 299/116/48; name/giver never filled | CONFIRMED | My SELECT: `0 \| 299 \| 116 \| 48`, `0 \| 0`; samples identical; `comm.php:381-431` [V]. |
| 12 | CHIM re-implements services; RentRoom bypasses the INFO | CONFIRMED, one NAME wrong | `AIAgentAIMind.psc:3080-3108` [V]. Live rows: `RentRoom` t, `HireCarriage` t, `Training` t, `GiveGoldTo` t, `TakeGoldFromPlayer` f. **There is no `TradeItems` row**: the live row is code_name `OpenInventory`, LLM name `Trade_Items`, active. `lrgHideActions()` filters `ENABLED_FUNCTIONS` by code_name (`lrg_actions.php:88-95`), so the code to hide is `OpenInventory` (the installed `LRG_MOVEMENT_ACTIONS` already lists both spellings). |
| 13 | `CHIM_AI_QUEST_PROGRESSION` false; detectable via `chimQuestEngineFeatureEnabled()` | CONFIRMED | `chim_quest_engine.php:111-168`, `main.php:53` then `:54` ext `globals.php`; `general_settings` rows (columns are `id,value,description,updated_at`) [V]. |
| 14 | `get_conf_i` handles the five keys | CONFIRMED | Same function as the `get_conf_i` registration string (0xe5f5d): literals `_pause_dialogue_when_menu_open` 0xe65c3, `_player_tts_traditional_dialogue` 0xe65f4; movabs immediates `_capture`/`_backgro`/`und_chat` 0xe6633-0xe6659, `_restric`/`t_onscen` 0xe6692-0xe66a5, `_openmic`/`_enabled` 0xe6715-0xe6731; falls to "Unknown configuration code" 0xe68f4 [V]. |
| 15 | `EndDialogueClearScene` can restart scene / owning quest | CONFIRMED | `AIAgentAIMind.psc:1874-1906` incl. the author's "can break quests"; called from `ManagerMainQueue::threadFunction` (0x209c61/0x209cf4). The agent's "was on scene" byte is set in `InterruptNPC` itself (`mov [rsi+0x6f],1` at 0x52b28 when `GetCurrentScene()` != null) [V]. |

Further names spot-checked and found correct: `AIAgentFunctions.psc:13,14,32,33,52`; `AIAgentAIMind.psc:1419-1430, 1719, 1790, 1821, 1845, 1941`; `AIAgentPlayerScript.psc:292`;
`PO3_SKSEFunctions.psc:812` (`Quest[] Function GetActiveAssociatedQuests(ObjectReference akRef, Bool abAllowEmptyStages = True)`); `OSANative.psc:66`; `SmartTalk.ini:119-126` (+ the
fragment warning at `iPapyrusHandle`); MCM :96, :277, :278, :485-494, :851, :857, :1351, :2094-2097, :2838-2840, :2886; `main.php:193, 227-230, 949-985, 1077-1079, 1355-1356`;
`comm.php:626-690` (`$topic = $speech["debug"]` is line 669), `:1162-1216`, `:1219-1223`; `chat_helper_functions.php:4217`; `dynamic_update_util.php:17`; `gamedata.php:160-163`;
`openrouterjson.php:1004-1009`; `json_response.php:349-397`; modlist lines 3680/3710/3843/3962.
Citations that are off: (a) `chimNormalizeLoggedEventType` is in `HS/lib/core/event_type.php:11-25`, not `chat_helper_functions.php:5015` (that line is the same
`' background chat)'` test inside the audience builder); (b) `conf.sample.php` / `conf_schema.json` live in `HS/conf/` (`HS/conf/conf.sample.php:600`, `HS/conf/conf_schema.json:84`);
(c) the NPC-response half of the `TESTopicInfoEvent` lambda is NOT inside "rva ~0x1b9700-0x1bb600": the NPC template is referenced at rva 0x1bc89b and `traditional_npc_speech` at
0x1bcc75; the lambda body runs from ~0x1b98d0 to ~0x1bd155 (its `__FUNCTION__` string is still referenced at 0x1bd064).

### V.2 Corrections

**A. "The owner has not used the vanilla menu with CHIM running" is false - and that one real session produced NO captured rows.** [V]
AIAgent.log:1774 `Player activated  0x198a2` at 06:30:49.280; 0x000198A2 is Lisette's RefID (addnpc line :293, routing lines :355/:824 `crosshair=000198A2`). From 06:30:49.38 to 06:30:53.39
the DLL logged `[BORED] Avoiding bored event because player is in dialogue` every 0.5 s (:1778-1809) and `[RECHAT 35720] Avoiding rechat, player is in dialog` (:1788) - i.e.
`MenuTopicManager.menuOpen` was true for ~4 s with `_capture_background_chat` = true (:275). DB, read-only, window localts 1789986635-1789986670: no `chat` row starting `(Context location:` for
Lisette or the player, no `speech` row with `topic LIKE 'traditional_%'` (the only `(Context` chat in the window is Pantea Ateia's ambient `chat_background`, rowid 18508), and the DLL log has
no `[Chatbox] Pushing NPC dialogue` / `[VOICE] ... from dialogue menu` line. So either no greeting INFO was selected/spoken, or the handler dropped it silently.
A plausible cause is V.3-1 (Lisette had been addressed through CHIM 70 s earlier and her voice type was only "restored" at 06:30:56.406, :1814) [I].
=> Section 2.5 / 5.3 ("read `speech` rows `traditional_%` as the authoritative transcript") rests on templates only; the single live data point contradicts it. X3 must be run FIRST and
twice: on an NPC CHIM never talked to, and on an NPC right after a CHIM exchange. Build a glue-side fallback regardless: the driver can read the response text itself from the SWF
(`ShowDialogueText(astrText)` -> `SubtitleText.SetText`, `p2_swf_disasm_chim.txt:363-365`; exact `UI.GetString` member path to be probed) and send it with `logMessageForActor`.

**B. Range/attribution fixes for 2.5** - see "citations that are off" (b)(c) above. Substance unchanged.

**C. `InterruptNPC` has more skip paths than stated, all in `HTTPManager::stream(std::string, RE::Actor*, int)` (rva 0x163cbe-0x163e71) [V]:**
(1) `test esi,esi / jle` - rechat depth > 0 -> `[HTTPStream] Rechat skips dialogue interrupt for {}` (live: log :3229, :4059, ...); (2) byte `[rsp+0x40]` -> `[HTTPStream] Combat bark skips
dialogue interrupt for {}`; (3) if the request PAYLOAD string (first argument, r15) is >= 10 chars and CONTAINS the substring `suggestion` (0x163d16-0x163d34) the whole interrupt block is
jumped over; (4) a string-equality test between a global name (singleton 0x13eff0 +0x130) and the actor name skips it; (5) then `QueueInterruptNPC` (0x155010) -> lambda: when the captured
"automatic" byte (`[rdi+0x38]`) is set, an eligibility call (0x189990) returning a reason skips (`[AUTO_ELIGIBILITY] ...`), otherwise `InterruptNPC` runs; when it is clear `InterruptNPC` runs
unconditionally. The claim is still true for what matters (every player utterance, depth 0): the live log has 15 `[Misc.cpp:605] Setting dialogue busy for  <npc>` lines = exactly the 15 `inputtext`
requests; the 6 `rechat` requests log `Rechat skips dialogue interrupt`, the 4 `narrator_inputtext` requests have no actor.
**Design consequence the report missed:** flow C step 3 sends `requestMessageForActor(..., "lrg_topics", npc)` WHILE the hidden session is open. If that native goes through
`HTTPManager::stream` with the NPC as actor (its signature and the request-kind names say it does; not traced to the end - [I]) CHIM will `PauseCurrentDialogue` + `EndDialogue` + null-voice
the session NPC at that very moment, i.e. the glue would kill its own session. X5 must therefore (a) grep AIAgent.log for `Setting dialogue busy for  <npc>` after the glue's request,
(b) test the `logMessageForActor` route too (the `togglemodel` precedent is a `logMessage`, `AIAgentPapyrusFunctions.psc:441`), and (c) note that a payload containing `suggestion` skips the interrupt.

**D. "Live template order ... action before message" is true only for the PROMPT TEXT; the enforced schema and every real output have `message` BEFORE `action`.** [V]
The live request also carries `response_format = {type: json_schema, strict: true}` built by `HS/functions/json_response.php:457-522` with HARD-CODED property order
`character, listener, message, mood, action, target, item, amount` (+lang/emotion...), sent when the connector's `json_schema` flag is on (`connector/openrouterjson.php:650-651`); it is
NOT affected by `JSON_DIALOGUE_FORMAT_REORDER`. `HS/log/context_sent_to_llm.log` (last request, ~line 23262ff) shows exactly that schema, and **52 of 52** dialogue objects in
`HS/log/output_from_llm.log` have the key order `character, listener, message, mood, action, target, item, ...` (the other 2 are `character, action, target`). So Grok writes its whole spoken
reply BEFORE it chooses the action - the situation section 2.4 warns about for `REORDER=true` is already the live one, and "leave `message` empty when you use SelectTopic" is NOT easy for the
model (it has not decided yet). `message` is `required` but may be `""`.
Fix available [V hook, I effect]: `chimRefreshJsonResponseState()` runs `setStructuredOutputTemplate()` and THEN `ext/*/json_response_custom.php` (first load) and every
`$GLOBALS["HOOKS"]["JSON_TEMPLATE"][]` callable (`json_response.php:43-51, 61-86`). On turns where `ExtCmdLRG_SelectTopic` is offered the glue can rebuild
`$GLOBALS["structuredOutputTemplate"]["json_schema"]["schema"]["properties"]` (and `required`) with `action, target, item` ahead of `message`, and mirror that in `$GLOBALS["responseTemplate"]`.
That the provider emits keys in schema order is inference, but it is what the 52/52 tally shows (outputs follow the schema order, not the prompt-text order). Until proven, keep the
two-pass design tolerant of a non-empty `message` (drop/skip its TTS server-side when the action is SelectTopic instead of trusting the model to leave it empty).

### V.3 Facts the report MISSED that change what gets built

**1. CHIM swaps the NPC's voice type to `NullVoiceType` on every player utterance and before every CHIM line; a cleaner restores it later.** [V - highest impact]
- Global rva 0x3db9a0 is filled at OnDataLoaded/OnNewGame/OnSaveGame with `LookupForm(0x1D70E, "AIAgent.esp")` (0x1fe8c5-0x1fe8d9, 0x1fda80, 0x1fa015) = VTYP xx01D70E `NullVoiceType`
  (chim-dll-esp.md:297; MinAI uses the same form, minai-reference.md:384).
- Readers (`globref.py`): **`InterruptNPC` 0x52bac-0x52bc3**, `SpeakManager::process` 0x141fdb-0x141ff7, `setDrivenByAIReal` 0xfb456-0xfb46d. All three do
  `rbx = NullVoiceType (formType 0x62 check); rax = 0x27c710(actor); mov [rax+0x58], rbx`. 0x27c710 = `GetActorBase()` (returns the base only if formType == 0x2B NPC_). `TESNPC+0x58` =
  `TESActorBaseData::voiceType` (TESActorBase.h `public TESActorBaseData, // 030` + TESActorBaseData.h `BGSVoiceType* voiceType; // 28 - VTCK`, both re-fetched). In `InterruptNPC` the write is
  unconditional and sits right after `EndDialogue`.
- Restore: `ManagerMainQueue::threadFunction` writes `[base+0x58]` back at 0x20a1c6 / 0x20a4b8 with `[CLEANER] Clean: / Dirty: Restoring voice for {} {:#x}`; original saved at agent creation
  (`Actor {} , storing original voice {:08X}`, xref 0x1d317c). Live timing: Lisette last addressed 06:29:38.4 (:834), `Restoring voice for Lisette 0x13add` at 06:30:56.4 (:1814) = ~78 s.
- Why it matters: under flow C the glue opens the REAL session a few seconds after the player spoke to that NPC through CHIM, i.e. always inside the window in which the NPC's BASE voice
  type is `NullVoiceType`. Expected effects [I, engine behaviour]: (a) the engine builds the voice-file path from the speaker's voice type -> no file -> the "real voiced line" is SILENT
  (Fuz Ro D-oh timing + subtitle only); (b) every `GetIsVoiceType` condition on the subject is evaluated against `NullVoiceType`, so greeting / shared / generic INFO selection changes - this
  hits exactly the owner's persuade / intimidate / bribe requirement wherever the responses are voice-type gated (the sibling dump `p2_dialogue_overrides.json` alone contains 63
  `GetIsVoiceType` conditions) and all generic NPCs (guards, innkeepers, merchants).
- What to build: before `Activate`, the driver must make sure the NPC's base voice type is the original one. Papyrus has `ActorBase.GetVoiceType()` / `ActorBase.SetVoiceType(VoiceType)`
  (SKSE `ActorBase.psc:104-105`; CHIM uses it itself, `AIAgentNpcUtil.psc:135`). The glue should cache each NPC's original voice in its NPC snapshot whenever the current one is not
  `Game.GetFormFromFile(0x01D70E, "AIAgent.esp")`, re-apply it immediately before opening a session and re-check it before every click; CHIM re-nulls it whenever the agent speaks a CHIM line
  (`SpeakManager::process`) and on the next utterance (`InterruptNPC`), which is one more reason never to click while CHIM audio is playing. Self-test line for the MCM: "voice type of <npc>
  at session open = <editor id>". New experiment **X0 (before X1)**: talk to an NPC through CHIM, open the vanilla menu within 30 s: is the greeting voiced? does a voice-type-gated topic
  appear? then repeat after the `[CLEANER] ... Restoring voice` line.

**2. Live LLM output order is message-then-action** - see V.2-D (hook to fix it exists).

**3. A real vanilla session left no `chat`/`speech` rows** - see V.2-A (needs X3 twice + glue-side subtitle fallback).

**4. The glue's own streamed request can trigger `InterruptNPC` on the session NPC** - see V.2-C.

**5. X6 can be answered statically for the list refresh, and R2 has two traps.** [V raw listing 3171ff, 2FDE, 12349, 16548]
`DoShowDialogueList`: `if (timerBool == false) { if (eMenuState == TOPIC_CLICKED || (eMenuState == SHOW_GREETING && entryList.length > 0)) ShowDialogueList(abNewList, abNewList && eMenuState == TOPIC_CLICKED); ExitButton._visible = !abHideExitButton }`.
`PopulateDialogueLists` has NO `timerBool` / state gate, so after an R2 click (state left at 1) the entry array is still refreshed for the next layer - reading works. But (a) the state then stays
`TOPIC_LIST_SHOWN` during the NPC's response, so a stray click/accept while the line plays goes to `onSelectionClick` (a second topic click + `PlayMenuTopic`) instead of `SkipText`; set
`eMenuState` yourself (2 before the click, as the SWF does) if the probe shows `UI.SetInt` reaches it; (b) `ClearList()` is only `EntriesA.splice(0, length)` - it does NOT reset
`iSelectedIndex`, and `PopulateDialogueLists` calls `SetSelectedTopic` only when its last argument != -1, so `selectedEntry` (= `EntriesA[iSelectedIndex]`) can be stale or undefined on a new
layer. The clean selector is the SWF's own `DialogueCenteredList.SetSelectedTopic(aiTopicIndex)` (sets `iSelectedIndex`, `iHighlightedIndex`, `iScrollPosition` by matching `topicIndex`, no
events, no mouse): `UI.InvokeInt("Dialogue Menu", "_root.DialogueMenu_mc.TopicList.SetSelectedTopic", topicIndex)` (`UI.psc:91`; `TopicList` is a plain member assigned in the constructor,
`p2_swf_disasm_chim.txt:289`) then R2. `entryList` / `selectedEntry` / `selectedIndex` are `addProperty` getters; the underlying plain fields are `EntriesA`, `iSelectedIndex` - the UI-path
probe should try the plain fields first (`...List_mc.EntriesA.<i>.text`), getters second [I: whether GFx `GetVariable` runs AS2 getters is not established here].

**6. Tab has a second path that `bFadedIn` does not cover.** [V] `InitExtensions` registers `GameDelegate.addCallBack("Cancel", this, "onCancelPress")` (`p2_swf_disasm_chim.txt:298`): when the engine
raises its Cancel user event it calls `onCancelPress` directly, not through `handleInput`, so `bFadedIn=false` cannot be the whole neutraliser; and `onMouseDown` -> `onItemSelect` is a `Mouse`
listener, also outside `handleInput` (gated only by `bAllowProgress`, which the engine re-arms 750 ms after `NotifyVoiceReady`). `TopicList.disableInput` only gates the list's own
`handleInput` and the mouse wheel (12355-12357, 16533-16535). => the neutraliser needs at least state (`eMenuState=3`) for clicks AND something for Cancel; plan for a game-side answer
(e.g. tolerate Tab = walk-away and recover) rather than assuming a SWF flag will do it.

**7. Player-speech routing has no notion of "the NPC I am in dialogue with".** [V strings + live log] Router reasons in the DLL: `explicit_narrator_mode, explicit_narrator_name,
explicit_ui_target, explicit_npc_name, true_crosshair, narrator_camera_gesture, bare_hey_fov, nearest_eligible, no_eligible_npc` (DLL@0x30a728-0x30a7d8) - nothing derived from
`MenuTopicManager.speaker`. Live: four voice lines clearly meant for Lisette were answered by The Narrator because `crosshair=00000000 ... reason=no_eligible_npc` (AIAgent.log:1185, 1411,
1648, 1851; whisper mode, radius 196). If the crosshair ref is empty while the Dialogue Menu holds the camera [I - X1 must log it], a spoken branch choice can be routed to the Narrator or to
the nearest other agent. The server plugin must therefore check that the responding `HERIKA_NAME` is the list owner before offering / executing `SelectTopic` (the report's gate (d) covers
execution; add the same test to the OFFER), and the glue should tell the owner that saying the NPC's name or using the chatbox target makes routing deterministic.

**8. `AIAgentAIMind.ShowDebugNotification` blocks up to 4 s while "Dialogue Menu" is open** (`AIAgentAIMind.psc:1432-1450`, `while UI.IsMenuOpen("Dialogue Menu") && safety < 40 ... WaitMenuMode(0.1)`).
Every CHIM Papyrus path that notifies (`ConsumeItemFeedback`, top-left notices, the `rolecommand|DebugNotification` line proposed in section 6) is delayed for the length of a hidden session,
up to 4 s, on CHIM's script thread. Do not use CHIM notifications as session feedback; use the glue's own HUD call.

**9. `setDrivenByAIReal` (agent activation with salutation) also calls `Actor::EndDialogue()` + null-voice + `PrepareForDialog`** (second caller of thunk 0x27c640 at rva 0xfb451, same function
as `{} IS NOW DRIVEN BY AI`, `Are you ok?` / `im_alive`). Agents are auto-added in the background (`Auto-adding <npc>`, Papyrus.cpp:1866 in the live log); the salutation branch is the only
one that ends dialogue (flag at `[rsp+0x40]`, 0xfb32c). Low risk, but the driver should treat "session died for no reason" as a normal, recoverable event.

**10. `$GLOBALS["external_fast_commands"]`** (`main.php:233-234`) lets an extension add request types to the fast (no MAIN semaphore) list - unused by the installed glue, not needed
while `preprocessing.php` terminates custom types at :193, but it is the documented way if a glue type ever has to fall through to `comm.php`.

Not resolved (say so rather than guess): what `Actor::EndDialogue()` does to an open MenuTopicManager session (X1 stands); whether the `TESTopicInfoEvent` lambda has a silent skip for
agents that are "busy"/null-voiced (early branches at 0x1b9a59 `cmp byte [r14+0x14],0` = event type, and agent lookups 0x999f0/0x87db0, were not decoded); what the second
`call [rax+0x278]` at rva 0x1f5373 (quest-progression code) operates on; whether `requestMessageForActor` reaches `HTTPManager::stream` (V.2-C).

### V.4 Confidence
High for everything marked [V] above (each item was reproduced from the binary / source / DB / log this pass, with two independent decodes for the PE mapping and the Address-Library
IDs). Medium for the engine-behaviour consequences of the NullVoiceType swap (silent line, INFO selection) - the swap itself is certain, its in-game effect needs experiment X0.
Order of experiments is now: **X0 (voice type) -> X3 x2 (capture) -> X1 -> X5 (with the interrupt check) -> X6/R2 with `SetSelectedTopic`**.


---

## Verification (adversarial pass) - run 2 (second, independent verifier)

Date 2026-09-21. The section above was written by an earlier run of this same verification task. I did NOT take it on trust: I re-did the checks
with my own tooling and also audited the first pass's corrections, because they are now load-bearing too.
Own scripts (read-only, `%TEMP%\p2v3\`): `pe.py` (PE header / section table), `da.py` (objdump + string annotation driven by the PARSED section table, not
hard-coded deltas), `calls.py` (E8/E9 callers + RIP-relative data refs), `fn.py` (function bounds from `.pdata`, chained unwind followed), `sx.py`
(string -> code xrefs), `esp.py` (plugin record dump), `swf2.py` (own AS2 walker: CWS inflate, tag walk, ConstantPool/Push decode, DefineFunction2 bodies),
`db.sh` (SELECT only, `default_transaction_read_only=on`), `t1..t16.sh`.
Hand sanity checks of the script output: section table `.text va 0x1000 raw 0x400`, `.rdata va 0x2d5000 raw 0x2d3600`, `.pdata raw 0x3d8a00`, file 4,134,912 bytes
(same as both earlier decodes); movabs immediates decoded by hand (`0x657275747061635f` = "_capture", `0x6f72676b6361625f` = "_backgro",
`0x746168635f646e75` = "und_chat"); `AIAgent.esp` record xx01D70E dumped = `VTYP`, `EDID NullVoiceType`; my SWF walker's offsets
(`"TopicClicked"` pushed at 0x3B5B and 0x3C8D only) equal the sibling listing's. **[V]** = seen by me in this run. **[I]** = inference.

### W.1 Verdict per claim (15)

| # | Verdict | Independent evidence this run |
|---|---|---|
| 1 | CONFIRMED | `AIAgentPapyrusFunctions.psc:1228-1251` read; wiki page re-fetched (both sentences verbatim); `modlist.txt` grep: no Souls/pause mod; only 3 loose `Interface\dialoguemenu.swf` providers exist (CHIM, Norden UI 16x9, Norden UI 21x9) and CHIM is modlist line 8 (higher than both). |
| 2 | CONFIRMED except the "only skip path" sub-claim (first pass V.2-C is right) | 0x52959 `call [rax+0x278]`, 0x52962 `call 0x27c640`; thunk = `mov edx,0x6d0 / mov ecx,0x6e0 / cmp byte [rax+0x118],4 / cmove / jmp [vtbl+rdx]`; only E8 caller of 0x52890 is 0x152c37; Actor.h re-fetched (`// 04F`, `// 0DA`, `GetCurrentScene // 04A`); log :365/:368. |
| 3 | CONFIRMED | getter 0xd640 holds 0x7DB8F/0x61ECB; callers 0xd6f7a, 0x1438a1, 0x14870a, 0x20676c, 0x20ad92; `cmp byte [rax+0xb1]` at 0x206771 / 0x14870f, `movzx [rax+0xb1]` at 0x1438a6; NG header `RELOCATION_ID(514959, 401099)` + `unkB1`, po3 header `menuOpen // B1` (both fetched). |
| 4 | CONFIRMED | `xor al,al` at 0x202415, 0x20256d, 0x2025fc, 0x20299f, 0x202c4c only; after the SEEMS string (0x202a74) the flow goes 0x202ad4 -> 0x202ae4 -> sleeping test; no false return in between. |
| 5 | CONFIRMED | MCM :276, :875, :1392; log :273. |
| 6 | CONFIRMED | own AS2 walk: `onSelectionClick` = `timerBool=true` ... `initMenu()`; `initMenu` = `skse.SendModEvent("PlayMenuTopic", text)`; `startTopicClickedTimer("off")` = `timerBool=false; GameDelegate.call("TopicClicked",[TopicList.selectedEntry.topicIndex])`; `topicClicked()` same. Papyrus :1174/:1182/:1193 literal path; only CHIM's scripts in the whole mods tree touch `"Dialogue Menu"` through `UI.*` (grep over all `*.psc`). |
| 7 | CONFIRMED (with the first pass's nuance) | own decode of `handleInput`: compiled from `if ((nav != UP && nav != DOWN) \|\| eMenuState == TOPIC_LIST_SHOWN) pathToFocus[0].handleInput(...)`. |
| 8 | Templates CONFIRMED; "no rows because the menu was never used" is NOT supportable (see W.2-A) | whole handler is ONE function 0x1b98d0-0x1bd2f4 (`.pdata`); player template xref 0x1bab8a, tag 0x1bae31, `_speech` 0x1baebf / 0x1bcd03, `infonpc` 0x1bb336, NPC template 0x1bc89b, NPC tag 0x1bcc75; DB: 0 `traditional%` speech rows, 0 `(Context%` chat rows of type `chat`. |
| 9 | First half CONFIRMED; ORDER claim WRONG in practice (first pass V.2-D is right) | `data_functions.php` 5975/6021 `returnLines`, 6030 `processActions`, 6036 post hooks; `json_response.php:457-522` schema order `character, listener, message, mood, action, target, item, amount`, `required` has `message`; connector :650-651; my own tally of `output_from_llm.log`: 28 + 24 = **52 of 54** objects are message-before-action (other 2: `character, action, target`). |
| 10 | CONFIRMED | `request` rows ...140/...145/...150/...155 (two per tick); `comm.php:1019-1024`; `auditing.php:26-45`. Extra: `togglemodel` is ALSO sent with `logMessageForActor` (:444), not only `logMessage` (:441). |
| 11 | CONFIRMED | `0 \| 299 \| 116 \| 48`; samples identical. |
| 12 | CONFIRMED, name wrong | there is no `TradeItems` code; live row is `OpenInventory` / `Trade_Items` (active); also `OpenInventory2` / `Accept_Gift`, `CheckInventory`; `TakeGoldFromPlayer` f, `SpawnGold` f. `AIAgentAIMind.psc:3080-3108` read. |
| 13 | CONFIRMED | `main.php:53` then ext `globals.php`; `general_settings` rows. |
| 14 | CONFIRMED | 0xe65c3, 0xe65f4 literals; movabs at 0xe6633/0xe6646/0xe6659, 0xe6692/0xe66a5, 0xe6715/0xe6731; `Unknown configuration code` 0xe68f4; `__FUNCTION__` string `Papyrus::get_conf_i` at 0xe68e8. |
| 15 | CONFIRMED | `AIAgentAIMind.psc:1874-1906` read (comment says "about 90 seconds after last speech"). |

Tally: 11 confirmed as stated, 4 confirmed in substance with a wrong sub-claim (2, 8, 9, 12). Nothing invented was found: every path, function name, line
number and rva I re-opened exists. Further names spot-checked OK: `AIAgentFunctions.psc:13,14,27-30,32,33,52,138`; `PO3_SKSEFunctions.psc:486, 812`;
SKSE `UI.psc:47-111` (`InvokeString` :93, `InvokeInt` :91, `GetString` :74, `SetBool` :57, `SetInt` :58); SKSE `ActorBase.psc:104-105`;
`AIAgentNpcUtil.psc:135`; `AIAgentAIMind.psc:1438-1439`; `dynamic_update_util.php:17`; `gamedata.php:160-163`; `openrouterjson.php:1004-1009`;
`comm.php:669, 1162`; `chim_quest_engine.php:110ff`; `lrg_actions.php:88-95`. The first pass's citation fixes (a)(b)(c) are right
(`chimNormalizeLoggedEventType` = `HS/lib/core/event_type.php:11`; `HS/conf/conf.sample.php:600`; `HS/conf/conf_schema.json:84`).

### W.2 Audit of the first pass's corrections / additions

**A (real session, no captured rows) - facts CONFIRMED, conclusion softened.** Log :1774 `Player activated  0x198a2`, `[BORED] Avoiding ... player is in dialogue`
from 06:30:49.381 to 06:30:53.388, `[RECHAT 35720] Avoiding rechat, player is in dialog` :1788; DB window localts 1789986640-1789986665 holds no traditional row
(only Pantea Ateia's `chat_background`, rowid 18508). What this proves: a Dialogue Menu session with Lisette existed for ~4 s and produced nothing. It does NOT
prove the capture is broken: nothing in the log shows that any INFO was spoken in those 4 s (no subtitle line for Lisette; the player may have left at once).
So "the owner never used the menu" is refuted, "capture does not work" is open. X3 (twice) stays the first data experiment; the subtitle fallback is cheap insurance.

**C (more skip paths in `HTTPManager::stream`) - CONFIRMED** by my own listing of 0x163cbe-0x163e8e: `test esi,esi / jle` -> "Rechat skips dialogue interrupt";
`cmp byte [rsp+0x40],0` -> "Combat bark skips ..." (the byte is set at 0x1635f3-0x163625 when the payload starts with `combatbark|`); payload >= 10 chars containing
`suggestion` (0x163d16) -> jumps past the interrupt; name-equality test (singleton 0x13eff0 +0x130) -> skip; else `call 0x155010` (QueueInterruptNPC) at 0x163e6c.
`QueueInterruptNPC` has a second caller, 0x168e43 inside `streamInternal` (0x164280-0x16931b) - that is the path player speech takes (live log: `[HTTPStream] Streaming for actor` appears
only for rechat; player lines log `[sendMsgStream]`), so both stream entry points interrupt.

**D (message before action) - CONFIRMED** (W.1 row 9). Build consequence stands: reorder the schema through `JSON_TEMPLATE` / `json_response_custom.php` on turns that offer
`ExtCmdLRG_SelectTopic`, AND make the server tolerant of a non-empty `message` (skip its TTS) - do not rely on the model.

**V.3-1 (NullVoiceType swap) - CONFIRMED, and it is the biggest item.** Writer 0x1fe8c5-0x1fe8d9: `mov edx,0x1d70e` + `"AIAgent.esp"` -> `mov [0x3db9a0],rax`; readers found by my ref scan:
0x52bac (`InterruptNPC`), 0xfb456, 0x141fdb (+ 0x1fa029, 0x1fda94 writers). In `InterruptNPC`: `mov rbx,[0x3db9a0]; cmp byte [rbx+0x1a],0x62; ...; call 0x27c710; mov [rax+0x58],rbx`
with no branch around it (every path from 0x52967 reaches 0x52bac). 0x27c710 returns the base only when `formType == 0x2B`. `TESActorBase`: `TESActorBaseData // 030`;
`TESActorBaseData`: `BGSVoiceType* voiceType; // 28 - VTCK` (both headers fetched) -> base+0x58. Plugin decode: `AIAgent.esp` xx01D70E = `VTYP` `NullVoiceType`.
Timing nuance: restores come from a periodic `[CLEANER]` sweep (log 06:29:01, 06:29:31, 06:30:02, 06:30:56, 06:34:01, 06:36:07, 06:38:22). Lisette: last interrupt 06:29:38.4 -> restore 06:30:56.4 (78 s);
another restore came 10 s after an interrupt (06:38:11.8 -> 06:38:22.1) inside a mass sweep with many "not present" lines (cell change [I]). CHIM's own comment says "about 90 seconds after last speech"
(`AIAgentAIMind.psc:1876`). The glue cannot wait that long -> it must restore the voice type itself before `Activate` (SKSE `ActorBase.SetVoiceType`, :105) exactly as the first pass says.
The in-game effect (silent line, `GetIsVoiceType` false) is still [I] until X0.

**V.3-5 (`SetSelectedTopic`) - CONFIRMED with a NEW trap**, see W.3-2. **V.3-6** `GameDelegate.addCallBack("Cancel", this, "onCancelPress")` is in the sibling listing :298 (not re-decoded by me).
**V.3-8** `AIAgentAIMind.psc:1438-1439` confirmed. **V.3-9** second caller of the `EndDialogue` thunk at 0xfb451 confirmed (`calls.py 0x27c640` -> 0x52962, 0xfb451), followed by the same null-voice write at 0xfb456-0xfb46d.
**V.3-7** router reason strings not re-decoded; the live log does contain 6 `reason=no_eligible_npc/nearest_eligible/explicit*` lines.

### W.3 NEW facts (not in the report, not in the first pass) that change what gets built

**1. The open question "does `requestMessageForActor` reach `HTTPManager::stream`?" is answered: YES. [V]**
`Papyrus::requestMessageForActor` = 0xebfd0-0xecd09 (`.pdata`; `__FUNCTION__` string xref 0xec17f). It formats `"{}|{}|{}|(Context location: {}){}"` (0xec9e2) and calls 0x1633f0 (0xeca2d);
0x1633f0 is a 0x6e-byte wrapper: copy string, `xor r8d,r8d`, `call 0x163460` = `HTTPManager::stream(payload, actor, 0)`. (A second branch, 0xecbc5 -> 0x163320 -> `streamInternal`, is the no-actor route;
a "Diary request with no target" goes through `sendMessageReal`.) `Papyrus::logMessageForActor` (0xe8a60-0xe90e2) instead calls 0x1590e0 = `HTTPManager::log(std::string, RE::Actor*)` / 0x158c90 (`HTTPLogWithForcedActor`) - no interrupt code on that path.
Inside `stream`, the request TYPE (text before the first `|`) is classified by 0x154800: it returns 1 only for the exact strings `inputtext`, `inputtext_s`, `ginputtext`, `ginputtext_s`, `narrator_inputtext`;
`stream` stores the NEGATION (`xor al,1` at 0x1635cf) as the "automatic" flag. Every other type - including any glue type such as `lrg_topics` - is an AUTOMATIC event, and for those:
 (a) gate 0x154220 -> eligibility 0x189990 can return a reason string - `cooldown`, `hostile`, `combat`, `restrained`, `unconscious`, `sleeping`, `scene`
     (`scene` only when the global byte 0x3bd2ce = AllowActorsOnScene is 0, i.e. NPC Scene Safety ON = the owner's setting), `invalid_actor` - and then `stream` logs
     `[AUTO_ELIGIBILITY] Suppressing automatic event for {} (reason={})` (0x1639ab) and RETURNS (0x163a83-0x163abc): **the request is silently never sent.** Types `diary` and `rechat` are special-cased;
     one agent flag (`[agent+0x6d]`, not decoded) exempts. A second check on the reply side exists: `[AUTO_ELIGIBILITY] Dropping automatic streamed response for {} (event={}, reason={})` (xref 0x160edd).
 (b) if eligible, `QueueInterruptNPC` runs with automatic=1; its lambda (0x152990) re-checks eligibility (`cmp byte [rdi+0x38]` at 0x152a74) and otherwise calls `InterruptNPC`
     = `PauseCurrentDialogue` + `EndDialogue` + NullVoiceType on the TARGET NPC.
 Consequences: (i) flow C step 3 as written (`requestMessageForActor(..., "lrg_topics", npc)` while the hidden session is open) makes CHIM end the glue's own session and null the speaker's voice -
 do NOT use `requestMessageForActor` with the session NPC for glue plumbing; (ii) the same call is silently dropped for sleeping / fighting / restrained / cooldown NPCs and - with Scene Safety ON - for NPCs
 in a Scene, which is exactly the "scene player-dialogue" case the handler is supposed to cover; (iii) the installed Phase-1 glue already uses `requestMessageForActor`
 (`LRG_Main.psc:619`, `LRG_OStim.psc:2055, 2097`) and is subject to the same suppression and interrupt (information for the other team; I touched none of their files).
 Candidate transports for the synchronous step, in order: `logMessageForActor` (no interrupt; whether command lines in its HTTP reply are executed is still X5 - precedent: `togglemodel` is sent with
 `logMessage` AND `logMessageForActor`, `AIAgentPapyrusFunctions.psc:441, 444`, and answered with a `|command|` line); a payload that contains the substring `suggestion`
 (binary-verified skip of the interrupt block, but an undocumented quirk - last resort). X5 must grep AIAgent.log for `Setting dialogue busy for  <npc>` and `[AUTO_ELIGIBILITY]` after every glue request.
 Commands are executed only from `ManagerMainQueue::threadFunction` (sole caller of the command executor 0x9e3f0 is 0x2064d5), i.e. reply lines are queued first - so "synchronous" still means "next main-queue tick" (0.5 s cadence in the live log).

**2. `SetSelectedTopic(aiTopicIndex)` falls back to entry 0 when the index is not in the list. [V own decode, sprite-54 init tag, 0x12AFF-0x12B92]**
It first sets `iSelectedIndex = 0; iScrollPosition = 0`, then loops `EntriesA` and only on a `topicIndex` match sets `iScrollPosition`, `iSelectedIndex`, `iHighlightedIndex`. A stale/unknown index therefore
selects the FIRST topic, and R2 (`startTopicClickedTimer("off")` -> `TopicList.selectedEntry.topicIndex`) would click it. The driver must read the selection back
(`...TopicList.selectedEntry.topicIndex` and `.text`, or `iSelectedIndex`) and compare with the intended entry BEFORE releasing the click; mismatch = abort, never click.
`selectedEntry` = `EntriesA[iSelectedIndex]`, `entryList` = `EntriesA`, `ClearList()` = `EntriesA.splice(0,length)` - all confirmed by my decode.

**3. Reading through AS2 getters with `UI.Get*` is established practice in THIS mod list. [V usage, I mechanism]** Three installed mods read `...itemList.selectedEntry.formId` with `UI.GetInt`
(`YEET - Store Quest Items\...\yeetQuestAliasScript.psc:35`, `Dynamic Crafting Animations\...\CA_PlayerAliasScript.psc:306`, `Biggie Traits\...\Traits_ResetMenuScript.psc:37`); `selectedEntry` is an `addProperty` getter
in the same Shared list class family as CHIM's SWF (`__get__selectedEntry`, my decode). So the probe should try `_root.DialogueMenu_mc.TopicList.selectedEntry.text` / `.topicIndex` first-class, not as a long shot.
What remains unproven is ARRAY indexing in a GFx path (`EntriesA.0.text` vs another syntax) - NOT FOUND in any installed script (two greps over every `*.psc` under `mods\`: a `UI.Get*/Set*` string literal containing `.<digits>.` or `[<digits>]` - no match;
any `UI.*(` line mentioning `entryList` / `EntriesA` / `_entryList` - no match); the probe must establish it,
with `SetSelectedTopic(i)` + `selectedEntry.text` as the index-free fallback for enumerating entries (select each `topicIndex` in turn and read it back - needs a known index range; `topicIndex` values are the
engine's, not 0..n-1, so this fallback only works once indices are known). Both `_root.DialogueMenu_mc.TopicList` (member assigned in the constructor) and
`_root.DialogueMenu_mc.TopicListHolder.List_mc` (timeline instance names) are valid spellings of the list; try the timeline path if the member path returns nothing.

**4. The owner's push-to-talk key IS visible: DX scan code 29 = Left Ctrl. [V]** `[Voicerec.cpp:422] Using mapped key code: 29 -> 17` on every recording (AIAgent.log:329, 802, 1154, ... 1823).
It is none of E/Tab/Enter/WASD/arrows/mouse, so the report's key-collision worry does not apply to the current binding. Papyrus still cannot read it; the self-test can only tell the owner.

**5. Player-input types are an exact, closed list in the DLL** (W.3-1): anything the glue sends is "automatic" by definition. Conversely the server enables actions only for those same types + `instruction, welcome, cheatmode`
(`main.php:1077-1079`), so a glue request type can never carry LLM actions AND is always eligibility-gated on the game side. Keep glue requests for plumbing only.

### W.4 Net effect on the build list (in priority order)
1. Driver pre-open step: restore the NPC's real voice type (cache it whenever it is not `AIAgent.esp|0x01D70E`), re-check before every click (first pass) - X0 first.
2. Glue plumbing during a session must use a transport that does not call `InterruptNPC` (W.3-1); X5 now has three things to log: reply command executed? `Setting dialogue busy`? `[AUTO_ELIGIBILITY]`?
3. Click path = `SetSelectedTopic(topicIndex)` -> READ BACK -> `startTopicClickedTimer("off")`; set `eMenuState` to 2 around the click if `UI.SetInt` reaches it (first pass V.3-5a).
4. Server: schema reorder hook + tolerate non-empty `message` on SelectTopic turns; hide `OpenInventory` (not `TradeItems`), `RentRoom`, `HireCarriage`, `Training`, `GiveGoldTo` when the engine list has the real entry.
5. Transcript: do not depend on `traditional_*` rows until X3 shows them; have the driver send the subtitle text itself.

### W.5 Confidence
High for every [V] item (binary, source, DB and log each re-read this run with tools written from scratch; results agree with two earlier independent decodes wherever they overlap).
Medium for engine-side consequences that no source can show (effect of `EndDialogue` on an open menu session, effect of NullVoiceType on INFO selection and audio, GFx array-path syntax,
whether `HTTPManager::log` replies feed the command queue) - these are exactly X0, X1, X5 and the UI-path probe.
