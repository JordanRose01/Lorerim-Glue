# CHIM 3.3.2 game-side Papyrus plumbing (commands, events, locks, dialogue / quest handling)

Scope: `F:\Modlists\LoreRim\mods\CHIM` (AIAgent.esp, `Source\Scripts\*.psc`, `SKSE\Plugins\AIAgent.dll`), cross-checked against HerikaServer at `\\wsl.localhost\DwemerAI4Skyrim3\var\www\html\HerikaServer`. Read-only research, no code written.

Citation conventions
- `X.psc:N` = `F:\Modlists\LoreRim\mods\CHIM\Source\Scripts\X.psc`, line N.
- `DLL:N` = line N of a printable-strings dump of `AIAgent.dll` (made with `grep -a -o -E "[ -~]{4,}"`, kept in SCRATCH as `dll_strings.txt`). DLL strings prove a literal exists in the binary and show its neighbours in the string table; they do NOT prove control flow. Anything concluded from string adjacency is marked INFERENCE.
- `HS/...` = path under the HerikaServer root.
- Coverage note: all 11 requested scripts were opened. `AIAgentAIMind.psc` lines 1-3090 were read line by line; lines 3090-4737 were covered by function-signature grep + grep of every `AIAgentFunctions.*Message*` / `commandEnded*` / `setLocked` / `ModEvent` call plus targeted reads (3487-3530, 3780-3860, 3971-4157, 4495-4550). `AIAgentMCMConfigScript.psc` was covered by structural grep + targeted reads (1-120, 300-600, 766-845, 1195-1270, 1776-1835, 2140-2360). `RealNamesChange.psc` read 34-100 + grep.

## Executive summary (10 lines)

1. The third-party command event in 3.3.2 is **`CHIM_CommandReceived`** with **three string args (npcname, command, parameter)**. It is created in **Papyrus** by `AIAgentAIMind.SendExternalEvent` (AIAgentAIMind.psc:1395-1405); the DLL only calls that global function. `SPG_CommandReceived` does not exist anywhere in the 3.3.2 Papyrus sources, the `.pex` files or the DLL (0 hits) - the only mention is a stale doc comment in `HS/ext/herika_heal/functions.php:14-17`.
2. Routing is by command name inside the DLL's `parseCommand`: `ExtCmd*` (prefix) -> `DispatchExternalCommand` -> `SendExternalEvent` -> `CHIM_CommandReceived`; `IntCmd` (exact name, sub-action in the parameter) and `AnimationEvent` -> `SendInternalEvent` -> `CHIM_CommandReceivedInternal` (handled by CHIM's own `CommandManager`); `WebCmd*` is server-side only; anything else unknown logs `Command not recognized {}` (DLL:45803-45808, 45921). INFERENCE on the exact branch logic, VERIFIED on the literals.
3. Result reporting format is `command@<FunctionCodeName>@<parameter>@<result text>` with message type `funcret` (HS/processor/request.php:8-22). CHIM's own scripts send it with `logMessageForActor(..., "funcret", npcDisplayName)` and then release the actor with `commandEndedForActor("<Command>", npcDisplayName)`.
4. NPC identity on the whole Papyrus<->DLL<->server path is the **display-name string** (`Actor.GetDisplayName()`); all per-actor natives take `String npc`. Renames go through `chim_renamenpc@old@new@formid` (`setconf`) plus StorageUtil `forcedName`/`forced_name`; agents persist in the SKSE cosave.
5. `setLocked(int,String)` = "1 locks agent for talking, 0 releases" (DLL: `setExternalLocked`, `[SPEAKERMANAGER] {} is locked, cannot speak`). `setAnimationBusy(int,String)` marks the agent as animation-busy. CHIM's own Papyrus only ever calls them with 0 (release) in `ResetPackages`/`StopCurrent`; nothing in Papyrus sets 1.
6. Vanilla dialogue is already observed natively: the DLL sinks `TESTopicInfoEvent` and logs both the player topic line and NPC responses as `chat|...` (`traditional_player_speech` / `traditional_npc_speech`), and ships a **replacement `Interface/dialoguemenu.swf`** with a `startTopicClickedTimer` gate used for "Player TTS in traditional dialogue" (`_player_tts_traditional_dialogue`). There is NO Papyrus `OnMenuOpen/OnMenuClose`, no activation blocking, no "never press E" feature in Papyrus.
7. CHIM 3.3.2 already contains a **native quest-progression subsystem**: server `HS/lib/chim_quest_engine.php` (3773 lines, flag `CHIM_AI_QUEST_PROGRESSION`, default off) queues actions; the DLL polls `quest_action_poll`, executes them through the Papyrus-static `AIAgentQuestProgressionBridge` (SetStage, objectives, console commands, story events `ADIA`/`CLOC`, scenes, items...) and acks with `quest_action_ack`. The bridge has no Papyrus callers - DLL only. This overlaps heavily with "menuless questing".
8. A second generic remote-execution path exists: server command `ScriptProxy` -> DLL `SendAICommand` -> `AIAgentScriptProxy.ExecuteCommand(int cmdID, string jsonString)` exposing ~150 Actor/ObjectReference/FormList/EffectShader/ActorUtil/Faction natives (incl. `Activate`=100, `AllowPCDialogue`=29, `BlockActivation`=116, `SetPlayerControls`=68).
9. No OStim / SexLab / adult integration exists in any CHIM script. `AIAgentIntimacyBubbleEffect` is a legacy shim that only sets conversation mode `chim_mode@CLOSE`. The only body-state signal is `player_naked@0|1` (`setconf`).
10. MCM is **SkyUI `SKI_ConfigBase`** (not MCM Helper), version 74, mirrored into a Prisma UI panel via mod event `CHIM_PrismaMCMRequest`. 20 hotkeys registered with plain `RegisterForKey` on the main quest script (`doBinding`..`doBinding20`); 43 literal `setConf` codes in Papyrus (+5 more known only to the DLL).

---

## 1. Plugin command event

### 1.1 Every mod event create/register in the Papyrus sources (VERIFIED, complete grep over `Source\Scripts`)

| Event name | Direction | Where | Args pushed / handler |
|---|---|---|---|
| `CHIM_CommandReceivedInternal` | created in Papyrus, consumed by CHIM | create: AIAgentAIMind.psc:1384; register: AIAgentPapyrusFunctions.psc:1095-1096 | 3 strings; handler `CommandManager` |
| `CHIM_CommandReceived` | created in Papyrus, for third parties | AIAgentAIMind.psc:1397 | 3 strings (npcname, command, parm); **no listener inside CHIM** |
| `CHIM_NPC` | created in Papyrus, for third parties | AIAgentAIMind.psc:1409 | 2 strings (npcname, actionName) |
| `CHIM_TextReceived` | created in Papyrus, for third parties | AIAgentAIMind.psc:1422 | 2 strings (npcname, text) |
| `CHIM_SpeechStarted` | created in Papyrus | AIAgentAIMind.psc:1719, 1790 | 1 Form (npc actor) |
| `CHIM_SpeechStopped` | created in Papyrus | AIAgentAIMind.psc:1845 | 1 Form (npc actor) |
| `CHIM_PrismaMCMRequest` | sent by DLL, consumed by CHIM MCM | AIAgentMCMConfigScript.psc:798-799, handler :1201 | standard SKSE signature |
| `PlayMenuTopic` | legacy, explicitly UNregistered | AIAgentPapyrusFunctions.psc:1099 | handler `OnPlayerMenuTopicSelected` :1170 is dead code |
| `AIAgent_PlayerMenuTTSFinished` | sent by DLL (DLL:46139), explicitly UNregistered in Papyrus | AIAgentPapyrusFunctions.psc:1100 | handler `OnPlayerMenuTTSFinished` :1186 is dead code |
| `CHIM_VRIK_TextChat`, `CHIM_VRIK_VoiceChat`, `CHIM_VRIK_MasterWheel`, `CHIM_VRIK_RoleplayWheel`, `CHIM_VRIK_SettingsWheel`, `CHIM_VRIK_ModeWheel`, `CHIM_VRIK_SoulgazeWheel`, `CHIM_VRIK_ManualAIActivate`, `CHIM_VRIK_HaltAI` | sent by VRIK, consumed by CHIM | names AIAgentPapyrusFunctions.psc:63-71; register :1111-1115 | handler `OnVrikGestureAction` :1133 |

There is no `SendModEvent(...)` call anywhere in the sources; everything uses `ModEvent.Create/Push*/Send`.

Exact source of the three command/notification senders:

```papyrus
; AIAgentAIMind.psc:1382-1430
function SendInternalEvent(String npcname,String command,String parm) global
	int handle = ModEvent.Create("CHIM_CommandReceivedInternal")
	if (handle)
		ModEvent.PushString(handle, npcname)
		ModEvent.PushString(handle, command)
		ModEvent.PushString(handle, parm)
		ModEvent.Send(handle)
		Debug.Trace("[CHIM] Internal command sent "+command+"@"+parm)
	endIf
endFunction

function SendExternalEvent(String npcname,String command,String parm) global
	int handle = ModEvent.Create("CHIM_CommandReceived")
	if (handle)
		ModEvent.PushString(handle, npcname)
		ModEvent.PushString(handle, command)
		ModEvent.PushString(handle, parm)
		ModEvent.Send(handle)
	endIf
endFunction

function SendExternalEventNPC(String npcname,String actionName) global   ; event "CHIM_NPC"
function SendExternalEventChat(String npcname,String text) global        ; event "CHIM_TextReceived"
```

### 1.2 Papyrus or DLL?

VERIFIED: the mod events are **created in Papyrus**. The string `CHIM_CommandReceived` does not occur in `AIAgent.dll`; the DLL contains the *function names* `SendExternalEvent`, `SendInternalEvent`, `SendExternalEventNPC`, `SendExternalEventChat` and the script name `AIAgentAIMind` (DLL:44778, 45805, 45807, 46203, 47387), i.e. it dispatches static Papyrus calls into `AIAgentAIMind`, which then raise the mod event.

DLL string-table neighbourhood (DLL:45803-45808):

```
ExtCmd
DispatchExternalCommand
SendExternalEvent
IntCmd
SendInternalEvent
WebCmd
```

INFERENCE: `parseCommand` (DLL:45678) tests the command name for the prefixes `ExtCmd` / `IntCmd` / `WebCmd`. The server-side doc agrees: "External/3rd party functions should start with prefix ExtCmd or WebCmd. ExtCmd is for functions whose return value is provided by a Papyrus plugin. WebCmd is for functions which return value is provided by server plugin itself" (HS/ext/herika_heal/functions.php:7-9).

`SPG_CommandReceived`: NOT FOUND in `Source\Scripts\*.psc`, `Scripts\*.pex`, or `AIAgent.dll` (`grep -c -a "SPG_"` = 0 for the DLL and for `AIAgentAIMind.pex`). Only present in the outdated example comment HS/ext/herika_heal/functions.php:14-20, which also shows the OLD two-argument handler `(String command, String parameter)`. Do not use it.

`SendExternalEventNPC` sits in the DLL next to `" is now active."` / `" was already active. Removing from CHIM."` inside `setDrivenByAIReal` (DLL:46198-46203). INFERENCE: `CHIM_NPC` is raised when an NPC is added to / removed from the agent list; the actual `actionName` literals were NOT FOUND (not distinguishable in the string dump).

`SendExternalEventChat` sits next to the RECHAT logic and `utterance_id` (DLL:47384-47388). INFERENCE: `CHIM_TextReceived(npcname, text)` fires per NPC utterance received from the server.

### 1.3 Wire format from the server

VERIFIED: `"{$GLOBALS["HERIKA_NAME"]}|command|$functionCodeName@$parameter\r\n"` (HS/connector/openaijson.php:1239, openrouterjson.php:1118, groqjson.php:579, player2json.php:694; special cases HS/main.php:1736 `|command|Halt@`, HS/processor/comm.php:1022 `|command|ToggleModel@$newModel`).

So for `Lydia|command|ExtCmdStartIntimacy@{"target":"Player"}` the listener receives `npcname="Lydia"`, `command="ExtCmdStartIntimacy"`, `parameter="{...}"` (INFERENCE for the exact split of name/command/parameter; the 3-arg push is VERIFIED).

### 1.4 Built-in commands

Handled in **Papyrus** by `CommandManager` (AIAgentPapyrusFunctions.psc:1286-1342) - this is the complete list:

```papyrus
Event CommandManager(String npcname,String  command, String parameter)
```

| command | parameter | effect |
|---|---|---|
| `AnimationEvent` | anim event name | `Debug.SendAnimationEvent(npc, parameter)` unless npc in combat / mounted / flying / unconscious / is player (:1290-1297) |
| `IntCmd` | `MuteMusic` | stores `fVal2:AudioMenu`, sets it 0, adds silence MusicType 0x0001ba72 (:1299-1305) |
| `IntCmd` | `UnmuteMusic` | restores (:1307-1310) |
| `IntCmd` | `InterruptScene` | stops bard songs (`BardSongsScript.StopAllSongs`), `AIAgentFunctions.SayTo(npc, player, Topic 0x0005583a)`, `currentScene.Stop()`, `IdleForceDefaultState`, adds vanilla doNothing package 0x654e2 at priority 99 (:1311-1337) |

Handled in the **DLL** (`parseCommand`), most of which then dispatch to `AIAgentAIMind` / `AIAgentNpcUtil` globals. Command-name literals found in the DLL string table (DLL:45592-45921): `CombatPlayer, Instruction, Suggestion, Disposition, Despawn, EndQuest, StartQuest, UpdateQuest, Sandbox, ImpersonatePlayer, QuestNotifySound, UploadBookContentByTitle, RawDebugNotification, DebugNotification, InternalSetting, QuestTrackReference, RefreshNPCVoice, RenameNPC, BackgroundCmd, ScriptProxy, Soulgaze, Halt, Relax, ToggleModel, Stop, AddBounty, PayBounty, ArrestPlayer, ConfirmArrestPlayer, ForgiveCrime, Attack, Brawl, OpenInventory, SetCurrentTask, MoveTo, TravelToRaw, CheckInventory, IncreaseWalkSpeed, DecreaseWalkSpeed, ReadQuestJournal, PlayIdle, SearchMemory, InspectSurroundings, LookAround, Inspect, TakeASeat, GoToSleep, WaitHere, Surrender, UseSoulGaze, CastSpell, ExtCmd*, IntCmd*, WebCmd*, TakeGoldFromPlayer, ConfirmMoveInventoryItem, MoveInventoryItem, RentRoom, HireCarriage, HireFerry, FollowPlayer, MakeFollower, Follow, ComeCloser, EndConversation, ReturnBackHome, GiveGoldTo, TradeItems, Consume, GiveItemTo, TakeHeldItem, PickupItem, PlaySong, CommandAnimation, SpawnNPCRaw, SpawnItemRaw, SpawnGoldRaw`. (VERIFIED as literals; which are commands vs. Papyrus function names is INFERENCE for a few, e.g. `StartWait`, `SleepInBed` are the Papyrus targets of `WaitHere`/`GoToSleep`.)

`BackgroundCmd` sub-commands parsed in Papyrus (`parameter` split on `/`, AIAgentAIMind.psc:3971-4323): `TravelTo, PickUpItem, SendNote, ReturnHome, StayAtPlace, MoveToPlayer, SeekAndKillPlayer, MoveTo, UpdateInventory, RemoveFromBgL, Track, FindNPC, SleepInBed`.

`ScriptProxy` -> `AIAgentScriptProxy.ExecuteCommand(int cmdID, string jsonString)` (AIAgentScriptProxy.psc:192; DLL:48736-48742 `SendAICommand`, JSON key `cmdID`). JSON keys: `targetObjectFormId` plus per-command Papyrus argument names (`akTarget`, `akItem`, `abSilent`, ...). Full ID table AIAgentScriptProxy.psc:14-163 (1-82 Actor, 100-131 ObjectReference, 200-202 FormList, 300-301 EffectShader, 400/401/490/491 ActorUtil+misc, 500-524 Faction). Note the header comment says `81 = ShowBarterMenu` but the code does 81 = `EvaluatePackage`, 82 = `ShowBarterMenu` (:861-866).

### 1.5 Minimal correct third-party listener skeleton

Pattern copied from how CHIM registers its own handler (AIAgentPapyrusFunctions.psc:1094-1096: Unregister then Register, re-run on every load) and from the 3-string push (AIAgentAIMind.psc:1397-1402). CHIM re-runs its own registration on every load: MCM `OnGameReload` (:1776) -> `getActionMode()` (:786) -> `controlScript.setNewActionMode()` -> `thirdPartyInit()` (AIAgentPapyrusFunctions.psc:1062-1072, 1094). The main quest script also declares `Event OnPlayerLoadGame()` (:119), but that event is only delivered to the player actor / player aliases, so on a Quest script it is presumably never fired (INFERENCE). For the glue: re-register from a player `ReferenceAlias` `OnPlayerLoadGame` (or the MCM `OnGameReload`).

```papyrus
Scriptname GlueCommandListener extends Quest

Event OnInit()
	RegisterEvents()
EndEvent

Function RegisterEvents()          ; also call from a player alias OnPlayerLoadGame
	UnregisterForModEvent("CHIM_CommandReceived")
	RegisterForModEvent("CHIM_CommandReceived", "OnChimCommand")
EndFunction

Event OnChimCommand(String npcname, String command, String parameter)
	if (command != "ExtCmdGlueStartIntimacy")      ; every listener sees every ExtCmd*
		return
	endif
	Actor npc = AIAgentFunctions.getAgentByName(npcname)
	; ... hard gates, do the work ...
	AIAgentFunctions.logMessageForActor("command@" + command + "@" + parameter + "@" + resultText, "funcret", npcname)
	AIAgentFunctions.commandEndedForActor(command, npcname)
EndEvent
```

Caveats (VERIFIED facts behind them): handler arity must be 3 strings, not the legacy 2; the event is broadcast (all listeners get all ExtCmd commands, so filter on `command`); `npcname` is a display name and may be "The Narrator" (DLL:45079, used as default `narratorActorName` in AIAgentAIMind.psc:3487, 4495).

---

## 2. Reporting a command result

Native signatures (AIAgentFunctions.psc:7-8, 27-30):

```papyrus
int function commandEnded(String command)  Global Native
int function commandEndedForActor(String command,string npc)  Global Native
int function logMessage(String a_msg,String type) Global Native			; Send message for logging purposes. Doesn't expect response
int function logMessageForActor(String a_msg,String type,String npc) Global Native
int function requestMessage(String a_msg,String type) Global Native		; Send message (no user input). expects an IA response
int function requestMessageForActor(String a_msg,String type,String npc) Global Native
```

Format (VERIFIED server side, HS/processor/request.php:8-22):

```php
$returnFunction = explode("@", $gameRequest[3]); // Function returns here
$functionCodeName=$returnFunction[1];
// $returnFunction is in the form command@function codename@function parameter@result
```

So: `command@<codename>@<parameter>@<result>`; the after-function cue is looked up as `$PROMPTS["afterfunc"]["cue"][$functionCodeName]` else `["default"]`; `$GLOBALS["FUNCSERV"][$functionCodeName]` is called if defined; `$GLOBALS["FUNCRET"][codename]` can rewrite the request (HS/ext/herika_heal/functions.php:87-114). The result must not contain extra `@` before field 3 (explode is unbounded, so `@` inside the result text lands in `[4]...` and is lost to `$returnFunction[3]`). The literal substring `Error` in the result is how CHIM signals failure (request.php:27; herika_heal `stripos(...,"error")`).

Real usages inside CHIM's own Papyrus (complete list):

```papyrus
; AIAgentAIMind.psc:1083 and :1091
AIAgentFunctions.logMessageForActor("command@Attack@"+akTarget.GetDisplayName()+"@"+npc.GetDisplayName()+combatString+akTarget.GetDisplayName(),"funcret",npc.GetDisplayName())
; :1122 / :1137 (error form)
AIAgentFunctions.logMessageForActor("command@Brawl@"+player.GetDisplayName()+"@Error. Skyrim's vanilla brawl quest is unavailable or already running", "funcret", npc.GetDisplayName())
AIAgentFunctions.commandEndedForActor("Brawl", npc.GetDisplayName())
; :1142, :1214, :1251 more Brawl funcrets
; :3524
AIAgentFunctions.logMessageForActor("command@SpawnNPC@" + templateLabel + "@" + resultText, "funcret", narratorActorName)
; :4517
AIAgentFunctions.logMessageForActor("command@KillTarget@" + targetDisplayName + "@" + resultText, "funcret", narratorActorName)
```

`commandEnded*` usages (complete): `commandEndedForActor("MoveTo",...)` :306; `commandEnded("TakeASeat")` :385; `commandEnded("WaitHere")` :461; `commandEndedForActor("TravelTo",...)` :858; `commandEndedForActor("Brawl",...)` :1106/1123/1138/1174/1215/1300; `commandEndedForActor("Attack",...)` :1360. DLL side: `void EndCommand(string,string)` logs `Releasing actor, command done {} for ` / `No AI actor found, can't end command` (DLL:46017-46019); busy actors are rejected with `[CHIM] Actor {} busy : {}` and `busy with command: {}` (DLL:45715, 45707). So `commandEnded*` = "clear the agent's current-command slot so it can accept new commands".

Observed convention: long-running commands send the `funcret` when the action STARTS (Attack/Brawl) and call `commandEndedForActor` when it FINISHES; outcome text is then sent separately as `infoaction` (:1173, :1299).

Note: 3.3.2 Papyrus never uses `requestMessage(..., "funcret")`; it uses `logMessageForActor(..., "funcret", npc)`. The DLL also builds its own `funcret|{}|{}|{} ({})` lines (DLL:45768). Whether a `logMessage*`-sent `funcret` still triggers the LLM follow-up turn, or whether `requestMessageForActor` is required for that, is an OPEN QUESTION (see end).

---

## 3. Other mod events

See the table in 1.1 - that is the complete set. Additional detail:

- `CHIM_SpeechStarted(Form npc)` is sent from `FakeDialogueWith(Actor npc,Actor listener,int animation,int movehead)` (:1707, "Should be called after NPC starts speech to listener (every sentence)") and `FakeDialogue(Actor npc,int animation,int movehead)` (:1788). `CHIM_SpeechStopped(Form npc)` from `EndDialogue(Actor npc)` (:1841, "Should be called after NPC stops speech"). Listener signature for a Form push: `Event OnX(Form akNpc)`.
- `CHIM_PrismaMCMRequest` handler: `Event OnPrismaMCMRequest(String eventName, String payload, Float numericValue, Form sender)`; payloads: `snapshot`, `agents_refresh`, `set|<key>`, `agents_add_all`, `agents_remove_all`, `agent_add|<name>`, `agent_remove|<name>`, `tool|sync_factions_locations`, `tool|send_voice_samples` (AIAgentMCMConfigScript.psc:1201-1268).
- Non-mod-event DLL->Papyrus channel: the DLL queues "pending settings-menu actions" which Papyrus polls every 0.1 s with `getSettingsMenuPendingAction()` (AIAgentPapyrusFunctions.psc:568-575); action ids `rp_gather, rp_halt, rp_diary_all, rp_diary_narrator, rp_diary_player, rp_update_nearby_profiles, rp_update_narrator, rp_write_diary, rp_update_npc, rp_wait, rp_follow, rp_rename, tools_sync_factions_locations, tools_send_all_voice_samples, sg_soulgaze, sg_photo_zoom, sg_photo, sg_upload, sg_context`, format `actionId` or `actionId|npcName` (:128-295).
- Papyrus -> DLL side channel: `Debug.TraceUser("ChimHTTPSender", "AIAgentRefreshInventory|"+formId)` and `"itempickup|<realts>|<gamets>|<text>"` (AIAgentAIMind.psc:234-243, 270-279, 828-837).
- Vanilla events CHIM's Papyrus listens to: `OnKeyDown/OnKeyUp/OnUpdate` (main quest), `OnTrackedStatsEvent`, `OnPlayerFastTravelEnd`, and on the player alias `OnObjectEquipped/OnObjectUnequipped/OnLocationChange/OnPlayerLoadGame/OnCellLoad/OnUpdate` (AIAgentPlayerScript.psc). Everything else (activate, death, combat, topic info, container, equip, book read, spell cast, sleep, furniture, package, quest) is sunk natively in the DLL (DLL:48543-48704).

---

## 4. Message TYPE literals used from Papyrus

Wire format built by the DLL: `type|localts|gamets|data` (`{}|{}|{}|{}`, DLL:46365; with actor `{}|{}|{}|(Context location: {}){}:{}` DLL:46368). Complete list of literal types in `Source\Scripts` (counts from a scripted extraction; L=logMessage, LA=logMessageForActor, R=requestMessage, RA=requestMessageForActor, S=sendMessage):

| type | fn | meaning | example (file:line) |
|---|---|---|---|
| `""` (empty) / `inputtext_i` | S, sendMessageToActor | player typed text; `_i` variant used with Ctrl = close mode | `SendLegacyTextMessage(messageText,inputType,selectedActor)` AIAgentPapyrusFunctions.psc:779-797 |
| `chatnf_book` | S | summarize the open book (no functions) | `sendMessage("Please, summarize this book i've just found.","chatnf_book")` :411 |
| `diary_nearby` | S | all nearby agents write diary | :192, :362 |
| `diary` | RA | one NPC writes diary (npc "" -> narrator by camera pitch) | :235, :1403, :1413; AIAgentDiaryEffect.psc:8 |
| `diary_narrator` | L | narrator diary | `logMessage("The Narrator","diary_narrator")` :195 |
| `diary_player` | L | player diary | :209 |
| `updateprofiles_batch_async` | L | refresh dynamic profiles, payload = comma list of names | :226, :241, :1420 |
| `updateprofile_narrator` | L | refresh narrator profile | :232 |
| `core_profile_assign` | LA | assign connector profile "1".."4" to NPC | :1507-1513 |
| `togglemodel` | L, LA | cycle LLM model | :441, :444 |
| `setconf` | L | key@value config/state pushes (see 10.3) | `logMessage("chim_mode@"+currentMode,"setconf")` :1589 |
| `snqe` | L | AI-quest (SNQE) control: `start`/`end`/`clean`/`restart` | :1661-1667 |
| `infoaction` | L, LA | narrate a world event into context, no reply | `logMessage("The party traveled for "+h+" hours","infoaction")` :1205 |
| `rpg_lvlup`, `rpg_shout`, `rpg_soul`, `rpg_word` | R | narrator reacts to level up / shout / dragon soul / word | :1213-1222 |
| `location` | L | force location refresh (empty payload) | AIAgentPlayerScript.psc:18 |
| `region` | L | current region/hold name | AIAgentPlayerScript.psc:50-54 |
| `named_cell` | L | cell + door graph row `name/cellId/locId/interior/destCell/destExterior/doorId/worldspace/closed/doorName/x/y` | AIAgentPlayerScript.psc:257, 279, 375, 407, 596 |
| `named_cell_static` | L | `cellId/Name@refId,...` boss containers / hidden activator | AIAgentPlayerScript.psc:315 |
| `util_location_name` | L | location catalogue row; `__CLEAR_ALL__////` resets | AIAgentPapyrusFunctions.psc:1965-1967, 2268; AIAgentAIMind.psc:4674 |
| `util_faction_name` | L | `__CLEAR_ALL__/`, `__VANILLA_SYNC__/` | AIAgentPapyrusFunctions.psc:2147, 2181 |
| `util_location_npc` | L | NPC position tracking row | AIAgentAIMind.psc:4244, 4320 |
| `util_npcname` | L | (dead code, `if (false)`) | AIAgentNpcUtil.psc:335 |
| `status_msg` | L, LA | machine status for server task engine: `started_moving@X`, `reached_destination_player@X`, `reached_destination@X`, `moving@X@taskid`, `spawned@name@formid`, `spawned_item@name@success@ref@formid`, `spawned_item@name@error`, `combat_start@X`, `activator@HEX activated` | AIAgentAIMind.psc:95, 105-107, 881, 2283, 2618, 2647, 2697; AIAgentItemAliasScript.psc:32, 41 |
| `funcret` | LA (L only in a commented line :1095) | command result, see section 2 | AIAgentAIMind.psc:1083 |
| `instruction` | RA, LA | director instruction to an NPC (RA = NPC reacts; LA = context only) | `requestMessageForActor(instruction,"instruction",npc.GetDisplayName())` :2704; LA :1316, :4735 |
| `suggestion` | R, RA | softer instruction | :2712; AIAgentScriptProxy.psc:1404 |
| `chat_nf` | RA | NPC comment without functions | AIAgentAIMind.psc:799, 849 |
| `bored` | R | idle chatter trigger | `requestMessage(player+" calls everyone around","bored")` :3813 |
| `itemfound` | LA | transaction / bounty / arrest / rent / carriage outcome text | AIAgentAIMind.psc:3049-3357 (24 uses) |
| `itemtransfer` | LA | NPC gave item | :150 |
| `itempickup` | LA | NPC picked up item | :280, :838 |
| `npcspellcast` | LA | NPC cast spell | :4403, :4427, :4458 |
| `_questdata` | L | `questEditorId@<ShowFullQuestLog console output>` | `FillLogJournal(int FormId)` :1937-1951 |
| `travelcancel` | LA | travel package interrupted | PF_AIAgentTravelPackage_0301ABFE.psc (Fragment_8) |
| `traveldone`, `welcome`, `command`, `backgroundaction` | - | only in commented-out lines | PF travel fragment; AIAgentAIMind.psc:314, :776; AIAgentPapyrusFunctions.psc:494 |

Types produced only by the DLL (for awareness; DLL:48547-48697, 45577-45914): `chat, infoaction, infoloc, infonpc, location, lockpicked, itemfound, book, death, playerdied, combatend, combatendmighty, npcspellcast, npc_reanimated, _uquest, quest, bleedout, goodmorning, goodnight, itemtransfer, waitstart, waitstop, switchrace, backgroundaction, enable_bg, force_current_task, memory, funcret`, plus JSON posts `quest_event`, `player_inventory_sync`, `activate_event`, `player_equipment`.

---

## 5. setAnimationBusy / setLocked

```papyrus
; AIAgentFunctions.psc:31-33
int function setAnimationBusy(int busy,String npc) Global Native
int function setLocked(int locked,String npc) Global Native; 1 locks agent for talking, 0 releases.
int function isActorTalking(String npc) Global Native
```

VERIFIED usages (complete):
- `ResetPackages(Actor npc)`: `setLocked(0,npc.GetDisplayName())` + `setAnimationBusy(0,npc.GetDisplayName())` (AIAgentAIMind.psc:61-62). `ResetPackages` is called by MoveToTarget, Follow, MakeFollower, StopCurrent, TravelTo*, stayAtPlace, AttackTarget, EndConversation, several BackgroundCmd branches - so **any CHIM movement command or Halt silently releases both flags**.
- `StopCurrent(Actor npc)`: `setLocked(0,...)` (:600).
- `SwordsAndNirnsStop(Actor singer)`: `setLocked(0,...)` (AIAgentNpcUtil.psc:612).
- No Papyrus call ever passes 1. INFERENCE: locks are set natively (e.g. music scene `startMusicScene`, `CommandAnimation`).
- `isActorTalking(name) == 1` is polled (max wait loops) before executing carriage/ferry/bounty/arrest so the NPC finishes speaking first (AIAgentAIMind.psc:3143, 3196, 3249, 3289, 3317, 3364).

DLL evidence of semantics: `Set setExternalLocked  {} {}` (DLL:46383); `[SPEAKERMANAGER {}] {} is locked, cannot speak` (DLL:47361) -> a locked agent's speech is refused/held. `Set animation busy {} {}` (DLL:46377); `[ANIMATION]  Agent {} is not available for animation, current command {},current animation {}` and `CommandAnimation: {} is not available for animation` (DLL:46022, 45920) -> animation-busy blocks CHIM's own emote/idle animation commands. `InspectSurroundings` annotates actors with ` (busy)` (DLL:45934).

Recommended third-party usage (derived, not an official API doc):

```papyrus
; on scene start, for every NPC participant that is an agent
AIAgentFunctions.setAnimationBusy(1, npc.GetDisplayName())   ; stop CHIM idles/AnimationEvent fighting the scene
; do NOT setLocked(1) if the NPC should keep talking during the scene; setLocked(1) mutes the agent
; on scene end / actor removed / scene aborted
AIAgentFunctions.setAnimationBusy(0, npc.GetDisplayName())
AIAgentFunctions.setLocked(0, npc.GetDisplayName())
```

Return values are `int` with undocumented meaning; `Papyrus::setLocked - failed {}` exists (DLL:46385), INFERENCE: fails when the name is not a current agent. The flags are keyed by display name, so call them with the same string `getAgentByName` would resolve.

---

## 6. Agents

Natives (AIAgentFunctions.psc:54-67):

```papyrus
int function setAIKeyWord(Actor targetActor) Global Native
int function setDrivenByAI() Global Native                       ; no arg: closest/crosshair
int function setDrivenByAIA(Actor forcedActor,bool salutation) Global Native
int function addBasicProfile(Actor forcedActor) Global Native    ; profile only (used for offline unique NPC sync), not activation
int function removeAgentByName(String name) Global Native
Actor function getClosestAgent() Global Native
Actor function getAgentByName(String npcName) Global Native
Actor[] function findAllNearbyAgents() Global Native
Actor[] function findAllAgents() Global Native
Actor[] function findAllNearbyNonAgents() Global Native
Actor[] function findAllNearbyActors(bool onlyBgl) Global Native; Only gets actors with BgL flag
int[] function findAllAgentsFormId() Global Native
int function removeFromRenamedNPCList(Actor akTarget)  global native
```

What makes an NPC an agent (VERIFIED):
- `setDrivenByAIA(actor,false)` is a **toggle**: DLL `setDrivenByAIReal(handle,bool,bool,bool,bool)` prints `" is now active."` or `" was already active. Removing from CHIM."` and refuses the player (`{} is  the player, refusing`) (DLL:46198-46203). Used by the "Manual AI Activate" hotkey (AIAgentPapyrusFunctions.psc:458-475), VRIK action, MCM `agent_add|` (with `salutation=true`, AIAgentMCMConfigScript.psc:1241), and after spawning (AIAgentAIMind.psc:2281).
- Auto-activation is native (`addAllNPC`, `[AUTOADD] Fast-promoting crosshair NPC`, DLL:46280-46284), governed by setConf `_toggleAddAllNPC`, `_max_distance_inside/outside`, `_autoadd_hostile`, `_autoadd_creature_npcs`, `_autoadd_allraces`, `_restrict_onscene`.
- Agent membership lives in the DLL's `AIAgentManager` and is persisted in the SKSE cosave (`AgentCountRecord`, `NamesCountRecord`, `[COSAVE] Storing as CHIM-marked NPC`, DLL:48140-48163). It is NOT a faction.
- AIAgent.esp records (parsed from the plugin): KYWD `AIAgentNPC` 0x0217A8, KYWD `AIAgentMoveLocation` 0x021245, KYWD `AIAgentSandboxLocation` 0x020CE3; FACT `AIAgentFactionMove` 0x01A69B, `AIAgentFactionTravel` 0x01A69C, `AIAgentFactionAttack` 0x01B6C1, `AIAgentFactionFollow` 0x01BC24, `AIAgentFactionSeat` 0x01C6EA, `AIAgentFactionWait` 0x02021E, `AIAgentFactionSandbox` 0x021246, `AIAgentFactionRoleMaster` 0x021D0B, `AIAgentPlayerEnemies` 0x02F317; MISC `AIAgentPreventRename` 0x02481F. The factions are **package conditions for the current command**, not an "is agent" marker. INFERENCE: `setAIKeyWord` applies KYWD `AIAgentNPC`; it has no Papyrus caller, so do not rely on the keyword.
- AIAgent.esp is a regular ESP (TES4 flags 0, not ESL) with 5 masters (Skyrim, Update, Dawnguard, HearthFires, Dragonborn). Main quest: QUST `AIAgentPapyrusFunctions` **0x0093FC** (`Game.GetFormFromFile(0x0093fc, "AIAgent.esp")`, AIAgentNpcUtil.psc:292); MCM quest `AIAgentMCMConfig` 0x009EC2; tracker `AIAgentTrackerQuest` 0x029E82. Note `OpenMasterWheelGlobal/OpenModeToggleWheelGlobal/HaltAllNearbyAgentsGlobal` use form **0x00000D62**, which does not exist in this AIAgent.esp (stale; those wrappers no-op) (AIAgentPapyrusFunctions.psc:1979, 2044, 2115).

How to test "is this actor currently an agent" - CHIM's own idiom (AIAgentPapyrusFunctions.psc:862-871):

```papyrus
Actor[] nearbyAgents = AIAgentFunctions.findAllNearbyAgents()
int index = 0
while index < nearbyAgents.Length && !isActivatedTarget
	isActivatedTarget = nearbyAgents[index] == targetActor
	index += 1
endwhile
```

Alternative: `AIAgentFunctions.getAgentByName(actor.GetDisplayName()) == actor` (compare the returned Actor, because names can collide; DLL logs `[PAPYRUS] getAgentByName <{}>, agent exists`, DLL:46461-46462). `findAllAgents()` is flagged by CHIM's authors as dangerous because it can return unloaded actors; they use `findAllAgentsFormId()` + `Game.GetFormEx` instead (AIAgentAIMind.psc:1654-1669).

Identification / renames (VERIFIED):
- Every per-actor call passes `npc.GetDisplayName()`. `getAgentByName` does fuzzy extension: `Matched {} after extending name {}: {}` (DLL:45085).
- Manual rename: `logMessage("chim_renamenpc@"+originalname+"@"+messageText+"@"+targetActor.GetFormId(),"setconf")` + `StorageUtil.SetStringValue(targetActor,"forcedName",messageText)` (AIAgentPapyrusFunctions.psc:260-261, 1469-1470). Server->game `RenameNPC` command renames the ref, adds it to the "Master Faction" and marks it for Background Life (`enable_bg|...`), then calls `addRenamedKeyword` (DLL:45651-45658).
- `addRenamedKeyword(ObjectReference akTarget,string newName)` adds MISC `AIAgentPreventRename` (0x02481f, in-game name "Scroll of Identity" per AIAgentNpcUtil.psc:365) to the inventory and sets StorageUtil `forced_name` (AIAgentAIMind.psc:3817-3824). Spawned NPCs get `SetDisplayName(npcName,1)` + `forced_name` + `isRolemastered=1` (:2145-2165).
- `RealNamesChange.psc` is CHIM's patched copy of the "Real Names Extended" quest script: `ChangeName` refuses to rename when the actor base comes from `AIAgent.esp` or when StorageUtil `forced_name` is non-empty (RealNamesChange.psc:68-88). It otherwise appends the generated name as `newName + " ["+oldName+"]"`. CHIM also ships `SKSE/Plugins/NPCsNamesDistributor.ini` forcing `display` context everywhere and `iFormat = 3` ("[name] [[title]]") so generic NPCs get unique display names (duplicate handling is delegated to NND/Real Names, not solved inside CHIM Papyrus).
- Renamed/tracked NPC names are restored from the cosave on load (`Renamed NPC {:08X} to '{}'`, `Actor ID {:08X} has changed FormID to {:08X}`, DLL:48149-48156).
- Duplicate display names among simultaneously active agents: NOT FOUND any Papyrus-side disambiguation. Risk stands.

---

## 7. Vanilla dialogue handling in Papyrus

VERIFIED negatives (grep over all scripts): no `RegisterForMenu`, no `OnMenuOpen/OnMenuClose`, no `RegisterForCrosshairRef`, no `RegisterForControl`, no `BlockActivation` call (only reachable through ScriptProxy cmd 116), no `AllowPCDialogue` call (only ScriptProxy cmd 29), no `Say(` that is not commented out.

What exists:
- Menu polling helper: `UI.IsMenuOpen("Dialogue Menu")` inside `ShowDebugNotification(String text)` to delay notifications while the dialogue menu is open (AIAgentAIMind.psc:1432-1450). `SafeProcess(bool allowMenuMode=false)` blocks hotkeys in menu mode, Console, Crafting Menu, MessageBoxMenu, ContainerMenu, text input, LootMenu, RaceSex Menu, listmenu (AIAgentPapyrusFunctions.psc:1228-1251).
- "Player TTS in traditional dialogue": `int function startPlayerMenuDialogueTTS(String fallbackText) Global Native` (AIAgentFunctions.psc:138). Papyrus handlers `OnPlayerMenuTopicSelected` / `OnPlayerMenuTTSFinished` (AIAgentPapyrusFunctions.psc:1170-1195) call `UI.InvokeString("Dialogue Menu", "_root.DialogueMenu_mc.startTopicClickedTimer", "off")`, but `thirdPartyInit` explicitly unregisters both events with the comment "Traditional dialogue Player TTS is handled in native code. Re-registering the Papyrus bridge here can resume the held topic twice." (:1097-1100). The native path: DLL intercepts `TopicClicked`, holds the topic, requests `player_menu_tts_play` / `player_menu_tts_prefetch` from the server, then releases via the same `startTopicClickedTimer` invoke or a timeout (DLL:46111-46150, 47912-47922, 48139). Enabled by setConf `_player_tts_traditional_dialogue` (MCM toggle, default false).
- CHIM ships a replacement **`Interface/dialoguemenu.swf`** (the `startTopicClickedTimer` function is not vanilla). In this modlist the only other provider is "Norden UI 16x9/21x9"; CHIM is on line 7 of `profiles\Ultra\modlist.txt` (INFERENCE: MO2 writes that file highest-priority-first, so CHIM's SWF wins the conflict).
- Native capture of vanilla dialogue: `TESTopicInfoEvent` sink logs `chat|{}|{}|(Context location: {}){}: {} ({} {})` for the player's selected line (tag `traditional_player_speech`) and `chat|...{}: {}` for NPC responses (tag `traditional_npc_speech`), and harvests the voice file for cloning (DLL:48612-48637). `ProcedureListenToScene` captures scene/background chatter (`_capture_background_chat`) (DLL:47923-47925).
- `int Function SayTo(Actor source,Actor dest,Form topicToSay) global Native` (AIAgentFunctions.psc:88): only Papyrus usage is the bard interrupt `AIAgentFunctions.SayTo(npc,Game.GetPlayer(),stopSinging)` (AIAgentPapyrusFunctions.psc:1327). Semantics beyond "make source say a Topic form to dest" NOT VERIFIED (does it run the INFO's result script? unknown - see open questions).
- AIAgent.esp contains DIAL `AIADialogueNullResetTopic` 0x01DC74 in QUST `AIADialogueNullReset` 0x01DC70; all Papyrus uses are commented out (AIAgentAIMind.psc:1869-1921).
- Hotkeys related to talking: Text Chat (Prisma chatbox focus), Voice Chat (hold = record, tap = `stopAllDialogue()`, double-tap = make crosshair NPC wait), legacy UIExtensions text entry. Player->NPC targeting uses `Game.GetCurrentCrosshairRef() as Actor` then `sendMessageToActor` (:772-797).
- Nothing aimed at a "never press E"/menuless experience: NOT FOUND in Papyrus. Closest building blocks: DLL `TESActivateEvent` sink (`[ACTIVATION] Player activates {} {}  {:X}`, DLL:48573), ScriptProxy `Activate`(100)/`BlockActivation`(116)/`AllowPCDialogue`(29), and the quest-progression story event `ADIA` (section 8).

---

## 8. Quest handling

Papyrus -> server:
- `FillLogJournal(int FormId)` (AIAgentAIMind.psc:1937-1951): runs console `ShowFullQuestLog <editorId>` via ConsoleUtil, reads it back with `ConsoleUtil.ReadMessage()` and sends `logMessage(questId+"@"+log,"_questdata")`. Called by the DLL from `ProcedureSendActiveQuests` (DLL:44777-44781).
- `OnTrackedStatsEvent(string asStatFilter, int aiStatValue)`: every tracked stat goes out as `logMessage(asStatFilter+"@"+aiStatValue,"setconf")` (AIAgentPapyrusFunctions.psc:1209-1226) - this includes vanilla stats such as quest-completion counters (stat names are engine-defined; not enumerated in source).
- Native: `_uquest|{}|{}|{}@{}@{}@{}` and `quest|{}|{}|(Context location: {}) Quest Updated "{}" new objetive: {}` (DLL:48662-48663), and JSON `quest_event` posts with fields `event_source=skse_plugin, event_type, payload, quest_plugin, quest_form_id, quest_editor_id, quest_name, aliases, quest_stage, location_name, location_entered, actor_dead, player_item(s)_acquired, ...` (DLL:48167-48225).

`AIAgentQuestProgressionBridge` (AIAgentQuestProgressionBridge.psc, 148 lines, `Hidden`, doc string "Static bridge used by the CHIM SKSE plugin to apply server-approved quest actions."). VERIFIED: no Papyrus script references it (grep); only the DLL does (`DispatchQuestProgressionPapyrusCall<...>`, DLL:48713-48723). Exact signatures:

```papyrus
Function SetQuestStage(int questFormId, int stage) Global
Function SetQuestObjectiveCompleted(int questFormId, int objectiveIndex, bool completed = true) Global
Function SetQuestObjectiveDisplayed(int questFormId, int objectiveIndex, bool displayed = true, bool forceDisplayed = false) Global
Function SetQuestStageObjective(int questFormId, int stage, int objectiveIndex) Global
Function FailAllQuestObjectives(int questFormId) Global
Function StartQuest(int questFormId) Global
Function StartQuestStageObjective(int questFormId, int stage, int objectiveIndex) Global
Function ExecuteConsoleCommand(String command) Global                  ; ConsoleUtil.ExecuteCommand
Function ExecuteConsoleCommandSequence(String commands) Global         ; "cmd1||cmd2||..." with 0.25 s waits
Function StopQuest(int questFormId) Global
Function StartScene(int sceneFormId) Global
Function SetActorValue(int actorFormId, string actorValue, float value) Global
Function SetActorGhost(int actorFormId, bool ghost) Global
Function EvaluateActorPackage(int actorFormId) Global
Function RemoveItemFromPlayer(int itemFormId, int count = 1, bool silent = false) Global
Function AddItemToPlayer(int itemFormId, int count = 1, bool silent = false) Global
Function EnableReference(int refFormId, bool fadeIn = false) Global
Function SetActorRelationshipToPlayer(int actorFormId, int rank) Global
```

All take runtime form IDs resolved with `Game.GetForm(id)`. DLL action types that map onto them (literals, DLL:48251-48342): `set_objective_completed`, `cross_quest_set_objective_completed`, `set_objective_displayed`, `fail_all_objectives`, `cross_quest_start`, `start_quest_stage_objective`, `actor_dialogue_start_quest_stage_objective` (sends the **ActorDialogue story-manager event `ADIA`** for actor+player before dispatching stage/objective; DLL:48229-48236, 48276-48284), `change_location_start_quest_stage_objective` (story event `CLOC`), `console_command`, `console_command_sequence`, `stop_quest`, `start_scene`, `set_actor_value`, `set_ghost`, `evaluate_package`, `remove_item`, `add_item`, `enable_ref`, `set_relationship_rank`; else `unsupported action type`. (The literal for the plain set-stage action type was NOT FOUND as a separate string - likely deduplicated.) Poll/ack endpoints: `quest_action_poll` (`limit`, `actions`), `quest_action_ack` (`action_id`, `result`, `applied`, `applied_by`, `verified`, `expected_stage`, `quest did not reach expected stage`), `quest_status`, enable sync `server_config_enable` / `[QuestProgression] Server global setting changed to {}` (DLL:48339-48356). Server side: `HS/lib/chim_quest_engine.php` (3773 lines; `chimQuestEngineFeatureEnabled()` reads general setting / conf_opts `CHIM_AI_QUEST_PROGRESSION`, default false; definitions table `skyrim_quest_definitions`, bundled `HS/data/skyrim_quest_definitions.json`; has "dialogue intent" beat selection `chimQuestEngineSelectDialogueBeatByIntent`, `chimQuestEngineQueueAction`), plus `HS/gamedata.php`. Not analysed further here (server topic).

Important limitation for "real topic effects": the bridge sets stages/objectives directly; it does **not** run a TopicInfo's result-script fragment. Quests whose INFO fragments do more than `SetStage` (give items, set globals, start scenes) would need those effects replicated (the bridge offers console commands, add/remove item, start scene, enable ref for exactly that).

Tracker quest (CHIM's own AI-quest "SNQE" UI, unrelated to vanilla quests):
- `AIAgentTrackerQuestScript extends Quest` (QUST 0x029E82): `SetTrackedReference(ObjectReference newRef)` (forces alias + `PO3_SKSEFunctions.SetObjectiveText(self,"Find "+name,20)`), `SetJournalLog(string messageText)` (objective 10), `OnItemPicked()`, `OnActorDeath()`, `OnItemActivated()`, global `QuestNotifySound()` (AIAgentTrackerQuestScript.psc:6-69). Properties: `ReferenceAlias AIAgentTrackerQuestAlias`, `Alias AIAgentTrackerQuestLegend`.
- Callers: `EndQuestNotification(String title,String taskid)`, `StartQuestNotification(...)`, `UpdateQuest(...)`, `SetQuestTracker(ObjectReference ref)` (AIAgentAIMind.psc:2783-2841, 2964-2993), driven by DLL commands `EndQuest`/`StartQuest`/`UpdateQuest`/`QuestTrackReference`.
- `AIAgentItemAliasScript extends ReferenceAlias`: `OnContainerChanged` -> `OnItemPicked()` when new container is the player; `OnDeath` -> `OnActorDeath()`; `OnActivate`/`OnTrigger` -> `logMessage("activator@"+formidHex+" activated","status_msg")`, `OnItemActivated()`, then `akActionRef.Disable(true)` (AIAgentItemAliasScript.psc:5-44; note it disables the *activating* ref, which for a player activation would be the player - looks like an upstream bug, only reachable for tracked activators).
- SNQE wheel: `logMessage("start"|"end"|"clean"|"restart","snqe")` (AIAgentPapyrusFunctions.psc:1629-1669).

---

## 9. Existing intimacy / adult integration

- `AIAgentIntimacyBubbleEffect extends activemagiceffect` (MGEF 0x02839A, SPEL `AIAgentIntimacyBubbleSpell` 0x02839B): `OnEffectStart` only does `Debug.Trace("[CHIM] Legacy close-mode compatibility effect selected persistent Close conversation mode")`, `AIAgentFunctions.logMessage("chim_mode@CLOSE","setconf")`, `Debug.Notification("[CHIM] Close conversation mode active")`; `OnEffectFinish` only traces (AIAgentIntimacyBubbleEffect.psc:9-18). Properties `IntimacySpell`, `IntimacyEffect`, `mdi`, `mdo` are unused. "Intimacy" here means a *private/close-range conversation bubble*, nothing adult. `Spell Property IntimacySpell` on the main quest script is unused (AIAgentPapyrusFunctions.psc:6).
- Same mode is set by holding Left Ctrl while submitting legacy text chat: `logMessage("chim_mode@CLOSE","setconf")` + type `inputtext_i` (AIAgentPapyrusFunctions.psc:779-782).
- grep `ostim|sexlab|intima|\bsex\b|kiss|aroused|naked|nude|adult|minai|romance` (case-insensitive) over all scripts: only the items above plus `IsActorNakedVanilla(Actor who)` -> `logMessage("player_naked@1"|"player_naked@0","setconf")` on every player equip/unequip; "naked" = no worn item with Skyrim.esm keywords 0x06C0EC or 0x0A8657 (AIAgentPlayerScript.psc:25-43). OStim/SexLab integration: NOT FOUND. No third-party CHIM listener mod exists in this modlist (`grep -r CHIM_CommandReceived --include=*.psc mods` only hits CHIM itself).
- Related conf codes: `chim_mode@<STANDARD|WHISPER|DIRECTOR|CHEATMODE|AUTOCHAT|INJECTION_LOG|INJECTION_CHAT|CLOSE>`, `player_naked@0|1`, native `_restrict_onscene` ("so AllowActorsOnScene is {}", DLL:46340 - controls whether actors currently in a vanilla Scene may be auto-activated/talk).

---

## 10. MCM, hotkeys, setConf

### 10.1 MCM type
`Scriptname AIAgentMCMConfigScript extends SKI_ConfigBase` (:1) -> **SkyUI MCM**, not MCM Helper. `ModName="CHIM"`, pages `Hotkeys, Auto Activate, Behavior, Sound, AI Agents, Tools` (:389-399), `GetVersion()` returns 74 (:593-597), `AIAgentPapyrusFunctions Property controlScript Auto` (:4). All state is mirrored to the Prisma UI panel through natives `beginChimMcmSnapshot / publishChimMcmEntry(page, section, key, label, description, controlType, value, options) / commitChimMcmSnapshot(revision) / publishChimMcmCommandResult(request, succeeded, message)` and the `CHIM_PrismaMCMRequest` event.

### 10.2 Hotkeys
Registered with `RegisterForKey(keycode)` on the main quest script (`AIAgentPapyrusFunctions`, QUST 0x0093FC) via `doBinding..doBinding20`; MCM `OnOptionKeyMapChange` calls `controlScript.removeBinding(old)` then `controlScript.doBindingN(new)` (AIAgentMCMConfigScript.psc:2140-2356). Default only for legacy text chat: `_currentKey = 0x52` (Numpad 0) (AIAgentPapyrusFunctions.psc:9). Dispatch in `OnKeyDown`/`OnKeyUp` (:320-566).

| MCM label (:1278-1303, :1400) | binder | field | action |
|---|---|---|---|
| Text Chat | doBinding17 | `_currentChatboxFocusKey` | `ToggleChatboxFocusAction` (Prisma chat; with Book Menu open -> `chatnf_book`) |
| Voice Chat | doBinding2 | `_currentKeyVoice` | hold>=0.35 s record (`recordSoundEx/stopRecording`), tap `stopAllDialogue()`, double-tap `WaitForCrosshairNpc` |
| Halt AI Actions | doBinding10 | `_currentHaltKey` | `StopCurrent` on crosshair actor or all nearby agents |
| Master Menu | doBinding19 | `_currentMasterMenuKey` | `toggleMasterMenu()` |
| Manual AI Activate | doBinding7 | `_currentCtl` | `setDrivenByAIA(crosshairActor,false)` / `setDrivenByAI()` |
| Soulgaze (`$chim_soulgaze_hotkey`) | doBinding20 | `_currentSoulgazeKey` | tap context capture, double-tap portrait, hold 0.7 s describe |
| Text Chat (Deprecated) | doBinding | `_currentKey` | UIExtensions text entry; hold 0.7 s = wait-here |
| Chatbox View | doBinding16 | `_currentChatboxKey` | `toggleChatboxPanel()` |
| Actions Menu | doBinding18 | `_currentSettingsMenuKey` | `toggleSettingsMenu()` |
| Status, Minihud, Terminator Views | doBinding12 | `_currentOverlayStatusCycleKey` | `cycleOverlayStatusPanels()` |
| History/Diaries | doBinding13 | `_currentHistoryDiariesCycleKey` | `cycleHistoryDiariesPanels()` |
| Browser Beta | doBinding14 | `_currentBrowserKey` | `toggleBrowserPanel()` |
| Logs View (Beta) | doBinding15 | `_currentDebuggerKey` | `toggleDebuggerPanel()` |
| Master Wheel (deprecated) | doBinding11 | `_currentMasterWheel` | UIExtensions wheel |
| Roleplay Wheel (deprecated) | doBinding4 | `_currentDiaryKey` | tap wheel / hold 0.5 s diary_nearby |
| Settings Wheel (deprecated) | doBinding3 | `_currentFollowKey` | profile/LLM wheel |
| Mode Wheel (deprecated) | doBinding8 | `_currentGodmodeKey` | chat-mode wheel / hold = cycle |
| Soulgaze Wheel (deprecated) | doBinding6 | `_currentCSoulgaze` | soulgaze wheel |
| (no MCM row found) | doBinding5 | `_currentCModelKey` | `togglemodel` |
| Mute Open Mic | doBinding9 | `_currentOpenMicMuteKey` | `setConf("_openmic_toggle_mute",1)` |

Left Ctrl (scan 29) and Left Shift (42) are read as modifiers with `Input.IsKeyPressed` in legacy text chat only (:779-785). A third-party mod should use its own `RegisterForKey` on its own script; key events are per-registrant, so there is no conflict mechanism beyond SkyUI's conflict prompt.

### 10.3 setConf codes
Signature: `int function setConf(String code,float float_value,int int_value,String string_value) Global Native` (AIAgentFunctions.psc:49); convenience wrapper `bool Function setConf(String code,float value)` on the main quest (AIAgentPapyrusFunctions.psc:1087-1091); reader `int function get_conf_i(String code) Global Native`.

All literal codes passed to `setConf` in Papyrus (43): `_animations, _auto_hearing_radius_m, _autoadd_allraces, _autoadd_creature_npcs, _autoadd_hostile, _bored_period, _camera_based_audio, _cancel_dialogue_on_combat, _capture_background_chat, _combat_barks, _combat_barks_period, _combat_dialogue, _curve_legacy_distance, _dynamic_profile_period, _enable_3d_audio_playback, _force_mono, _godmode, _head_voice_volume, _invertheadingstate, _lip_int, _lip_res, _maintenance_period, _max_distance_inside, _max_distance_outside, _openmic_enabled, _openmic_enddelay, _openmic_sensitivity, _openmic_toggle_mute, _pause_dialogue_when_menu_open, _playback_dropoff_inside, _playback_dropoff_outside, _player_tts_traditional_dialogue, _rechat_policy_asap, _restrict_onscene, _sgmode, _sound_ds, _sound_postclip, _sound_preclip, _sound_volume, _spatial_hearing_inside, _spatial_hearing_outside, _timeout, _toggleAddAllNPC`.

Codes read with `get_conf_i`: `_auto_hearing_radius_m, _cancel_dialogue_on_combat, _combat_barks, _combat_barks_period, _combat_dialogue, _playback_dropoff_inside, _playback_dropoff_outside, _player_auto_include_radius_m, _player_tts_traditional_dialogue, _sgmode, _spatial_hearing_inside, _spatial_hearing_outside`.

Codes that exist only in the DLL's `setConfReal` (DLL:46285-46359): `_preserve_queue, _end_conversation_cooldown, _history_panel_enabled, _history_panel_toggle, _player_auto_include_radius_m`; unknown codes log `Unknown configuration code: {}` - i.e. **third parties cannot add their own setConf codes**.

Separate mechanism - server-side key@value through `logMessage(..., "setconf")` (goes to HerikaServer, not the DLL): `chim_mode@<MODE>`, `chim_profile_model@1..4`, `chim_context_mode@1`, `chim_renamenpc@old@new@formid`, `player_naked@0|1`, `<TrackedStatName>@<value>`. This IS open-ended on the wire; whether the server accepts arbitrary keys is a server-side question.

StorageUtil keys CHIM uses (for coexistence): global (`None`): `AIAgentAutoFocusOnSit, AIAgentAutoFocusOnSitCameraMan, AIAgentAutoFocusOnSitLastActor, AIAgentNpcWalkNear, AIAgentNpcWalkToTarget, AIAgentWebSockeSTT, AIAgent_CurrentModeIndex, CHIM_ExteriorCellInfo{Active,Cell,Index,Location,ReadyAt}`; per-form: `CHIM_BleedRecovery, CHIM_FollowPlayerActive, CHIM_LastInventoryMenuOpenRealTime, CHIM_Protected, CustomHairColor, LastMoveToLocation, LastTravelToLocation, LastTravelToLocationName, MoveToTargetIntent, OriginalNPC, PackageSoft, PendingGive{FormID,Amount,Item}, PendingPickupItem, PendingPickupItemRef, RNE_Name, RenamedBuffer, WalkToTargetListener, WalkToTargetStartTime, chim_placed_at_container, chim_placed_at_container_ref, chim_track_enabled, forcedName, forced_name, isRolemastered`.

Papyrus dependencies CHIM already hard-requires (so the glue may rely on them): SKSE, PapyrusUtil (`StorageUtil`, `ActorUtil`), powerofthree's Papyrus Extender (`PO3_SKSEFunctions`), ConsoleUtil, UIExtensions, SkyUI; soft: VRIK, RaceMenu, NFF (`nwsFollowerControllerScript`), MfgFix (`MfgConsoleFunc`).

---

## Implications for the glue

1. **Listener**: register `CHIM_CommandReceived` with handler `(String npcname, String command, String parameter)`; Unregister+Register on init and on every game load; filter by exact `command` string; name the functions `ExtCmdGlue...` so the DLL routes them to Papyrus. Do not implement `SPG_CommandReceived`.
2. **Result path**: always answer with `logMessageForActor("command@"+command+"@"+parameter+"@"+result, "funcret", npcname)` (echo the received `parameter` verbatim in field 2; keep `@` out of `result`; start failure text with `Error`), then `commandEndedForActor(command, npcname)` exactly once on every exit path, including gate refusals - otherwise the agent stays "busy with command". For a long scene: funcret at start ("scene started"), `commandEndedForActor` when the scene ends, and scene progress as `infoaction` (context only) or `requestMessageForActor(..., "instruction"|"chat_nf", npc)` when a spoken reaction is wanted.
3. **Hard gates belong in Papyrus/DLL, not the prompt**: the LLM only selects the ExtCmd; the listener must verify adult/consent/eligibility itself (e.g. `!actor.IsChild()`, playable adult race, not in combat/scene, is-agent check, player is the counterpart) before calling OStim, and return an `Error...` funcret when refused.
4. **Busy flags**: `setAnimationBusy(1,name)` for NPC participants at OStim scene start; `setAnimationBusy(0,name)` + `setLocked(0,name)` at end. Avoid `setLocked(1)` unless the NPC must be silent. Be aware that any CHIM movement command / Halt hotkey calls `ResetPackages`, which clears both flags and applies package overrides to the actor mid-scene; consider also keeping the agent's command slot occupied (do not call `commandEndedForActor` until the scene ends) so the DLL rejects new movement commands with `Actor busy`.
5. **Identity**: carry the display name received in `npcname` through the whole transaction and resolve actors with `getAgentByName(npcname)`; verify the returned Actor's form ID against what the glue expects when two NPCs could share a name. Never cache names across renames (`chim_renamenpc`, NND).
6. **Scene awareness feed**: OStim events -> `logMessageForActor(text,"infoaction",npcname)` for silent context, `requestMessageForActor(text,"chat_nf",npcname)` for a function-free spoken reaction. Subscribe to `CHIM_SpeechStarted/Stopped(Form)` if scene pacing should wait for speech; `isActorTalking(name)` is the polling alternative CHIM itself uses.
7. **Menuless questing - build on, do not duplicate, the native quest progression**: evaluate `CHIM_AI_QUEST_PROGRESSION` + `chim_quest_engine.php` + `AIAgentQuestProgressionBridge` first. It already provides conversation-intent -> quest action -> verified stage change with ack, including story-manager `ADIA` events. The glue's data-driven layer could (a) generate `skyrim_quest_definitions` entries for LoreRim mod quests, and/or (b) add a separate executor for INFO result scripts that the bridge cannot express. A Papyrus-only executor cannot run an arbitrary TopicInfo fragment generically (fragments are per-INFO scripts bound to the INFO form, not callable by form ID from Papyrus) - this is the one place an SKSE DLL is clearly justified.
8. **Vanilla dialogue capture is free**: the DLL already forwards every selected player topic and NPC response as `chat` lines, so the server plugin can observe menu-driven quest dialogue without game-side work. CHIM's `dialoguemenu.swf` override must be preserved (do not ship another one).
9. **Generic remote natives**: `ScriptProxy` gives the server `Activate`, `BlockActivation`, `AllowPCDialogue`, `SetPlayerControls`, `AddItem`, `MoveTo`, etc. without new Papyrus. Useful for prototyping, but it has no result channel (getters only `Debug.Trace`), so production features should still use ExtCmd + funcret.
10. **MCM**: CHIM uses SkyUI `SKI_ConfigBase`; the glue can use either SkyUI or MCM Helper independently. It cannot register new `setConf` codes; use its own StorageUtil/MCM state and, for server-visible state, `logMessage("glue_key@value","setconf")` only after confirming the server accepts unknown keys.
11. **Plugin form access**: `Game.GetFormFromFile(0x0093FC, "AIAgent.esp") as AIAgentPapyrusFunctions` is the main quest (not 0xD62). AIAgent.esp is a full ESP; an ESL-flagged glue plugin can master it normally.

## Open questions

1. Does a `funcret` sent with `logMessageForActor` trigger the LLM after-function turn, or is `requestMessageForActor(...,"funcret",npc)` required for that? (3.3.2 Papyrus uses `logMessageForActor`; the legacy doc uses `requestMessage`.) Needs a DLL/server trace or a live test.
2. Exact `actionName` values of `CHIM_NPC` and the precise firing point of `CHIM_TextReceived` (per sentence vs. per response) - not recoverable from strings.
3. Exact split the DLL applies to `Name|command|Code@param` when `param` itself contains `@` or `|` (JSON parameters) before calling `SendExternalEvent`.
4. Return-value semantics of `setLocked`, `setAnimationBusy`, `commandEndedForActor`, and whether `setAnimationBusy(1)` also suppresses lip-sync/look-at or only `CommandAnimation`.
5. Does `AIAgentFunctions.SayTo(source,dest,topic)` execute the chosen INFO's result script / conditions (a possible generic "run real topic effects" primitive), or only play audio? Not determinable from Papyrus.
6. Is `CHIM_AI_QUEST_PROGRESSION` enabled in this install, how many quests does `skyrim_quest_definitions.json` cover, and does the engine handle mod-added (LoreRim) quests? (Server-side research item.)
7. Does HerikaServer accept arbitrary `key@value` `setconf` payloads from third parties, and where are they stored?
8. How CHIM behaves when two active agents share a display name (no Papyrus-side disambiguation found).
9. Whether the DLL keeps an agent's "current command" set for ExtCmd commands at all (i.e. whether `commandEndedForActor` is mandatory for ExtCmd, or only for built-ins) - `EndCommand` strings suggest a generic slot, unverified.
10. `AIAgentItemAliasScript.OnActivate/OnTrigger` disables `akActionRef` (the activator's user) - upstream bug or intended? Irrelevant unless the glue reuses the tracker alias.

---

## Verification (adversarial pass)

Method: every load-bearing claim was re-opened at its cited source (Papyrus `.psc`, the `dll_strings.txt` dump plus direct `grep -a` on `AIAgent.dll`, HerikaServer PHP over the UNC path). AIAgent.esp was re-parsed independently (own read-only script, not the author's). `.pex` parity was checked for the event names. The official modders guide (https://dwemerdynamics.com/chim/modders-guide.html, linked from the mod's own `meta.ini` nexusDescription) was read as an external cross-check. Version sanity: `F:\Modlists\LoreRim\mods\CHIM\meta.ini` `version=3.3.2.0`; DLL build paths `D:\wt\chim-332-main-voice-hotfix\Plugin\...` (DLL:46129, 48735); `*AIAgent.esp` is active at `profiles\Ultra\plugins.txt:3412`, `+CHIM` at `modlist.txt:7`.

### Confirmed (20 of 24 claims fully, 4 partially - see corrections)

- C1 `CHIM_CommandReceived`, 3 x `PushString(npcname, command, parm)` - AIAgentAIMind.psc:1395-1405. Also present in `Scripts/AIAgentAIMind.pex` (2 hits incl. the `...Internal` superstring).
- C2 `CHIM_CommandReceivedInternal` -> `Event CommandManager(String npcname,String  command, String parameter)`; only `AnimationEvent` and `IntCmd` (`MuteMusic`/`UnmuteMusic`/`InterruptScene`) - AIAgentPapyrusFunctions.psc:1094-1096, 1286-1342.
- C4 `SPG_` = 0 hits in DLL, all `.pex`, all `.psc`; stale 2-arg example at HS/ext/herika_heal/functions.php:14-20 (re-read).
- C5 wire format `"{$GLOBALS["HERIKA_NAME"]}|command|$functionCodeName@$parameter\r\n"` - openaijson.php:1239, openrouterjson.php:1118, groqjson.php:579, player2json.php:694, google_openaijson.php:478 (extra), main.php:1736.
- C7 all `funcret` / `commandEnded*` call sites and line numbers (grep reproduced exactly).
- C8 native signatures AIAgentFunctions.psc:31-33; only `0` is ever passed (AIAgentAIMind.psc:61-62, 600; AIAgentNpcUtil.psc:612).
- C9 DLL literals at the cited lines: 46377 `Set animation busy {} {} `, 46383 `Set setExternalLocked  {} {} `, 47361 `[SPEAKERMANAGER {}] {} is locked, cannot speak `, 46022, 45920.
- C11 cosave literals DLL:48140-48163; ESP records re-parsed: TES4 flags 0x0; KYWD 050217A8 `AIAgentNPC`, 05021245 `AIAgentMoveLocation`, 05020CE3 `AIAgentSandboxLocation`; the 9 FACT ids; MISC 0502481F `AIAgentPreventRename`; `setAIKeyWord` has no Papyrus caller (grep).
- C12 rename plumbing (AIAgentPapyrusFunctions.psc:260-261, 1469-1470; AIAgentAIMind.psc:3817-3824; RealNamesChange.psc:68-88).
- C13 no `RegisterForMenu/OnMenuOpen/OnMenuClose/RegisterForCrosshairRef/RegisterForControl`; `BlockActivation`/`AllowPCDialogue` only in ScriptProxy; every `.Say(` is commented; unregister block at AIAgentPapyrusFunctions.psc:1097-1100.
- C15 `AIAgentQuestProgressionBridge` - 18 global functions, signatures identical to the report; no Papyrus caller; DLL:48713-48723.
- C16 DLL quest action-type literals and `quest_action_poll`/`quest_action_ack`/`quest_status`/`server_config_enable` (DLL:48226-48356 re-read). The plain stage action's Papyrus target literal `SetQuestStage` IS present (next to `missing quest or stage`), only its `action_type` string was not isolated.
- C17 `chim_quest_engine.php` = 3773 lines; `chimQuestEngineFeatureEnabled()` :111-169 (general setting -> `$GLOBALS` -> `conf_opts` id `chim_ai_quest_progression`, default false); `chimQuestEngineQueueAction` :1440; `chimQuestEngineSelectDialogueBeatByIntent` :2219; gamedata.php:54-56, 125-146.
- C18 `ExecuteCommand(int cmdID, string jsonString)` :192-208; 29/68/100/116 at the cited lines; 81=`EvaluatePackage`, 82=`ShowBarterMenu` (:861-866); DLL `SendAICommand`, `ScriptProxyRun`, `JSON missing cmdID` (DLL:48736-48742).
- C19 `AIAgentIntimacyBubbleEffect.psc` re-read in full; own case-insensitive grep for `ostim|sexlab|sex|kiss|arous|minai|romance|nsfw|lewd` over Source/Scripts = 0 hits; DLL strings `ostim|sexlab|minai` = 0 hits.
- C20 MCM: `extends SKI_ConfigBase` :1, `ModName="CHIM"` :391, pages :394-399, `return 74` :595, `CHIM_PrismaMCMRequest` :798-799, handler :1201-1268 (payload list exact).
- C21 `_currentKey = 0x52` :9; `removeBinding` :935; `doBinding`..`doBinding20` :942-1059 with exactly the field mapping in the report's table.
- C22 43 distinct literal `setConf("...")` codes (own scripted count = 43); `get_conf_i` list exact; DLL-only codes and `Unknown configuration code: {}` at DLL:46285-46359.
- C23 QUST 050093FC `AIAgentPapyrusFunctions`, 05009EC2 `AIAgentMCMConfig`, 05029E82 `AIAgentTrackerQuest`, 0501DC70 `AIADialogueNullReset`; 5 masters; `0x00000D62` at AIAgentPapyrusFunctions.psc:1979/2044/2115 has no matching record.
- C24 `CHIM_NPC`, `CHIM_TextReceived`, `CHIM_SpeechStarted`/`CHIM_SpeechStopped` (`PushForm`) - AIAgentAIMind.psc:1407-1430, 1719-1723, 1790-1794, 1845-1849.
- Extra spot checks (all match): `FakeDialogueWith(Actor npc,Actor listener, int animation,int movehead)` :1707; `FakeDialogue` :1788; `EndDialogue` :1841; `FillLogJournal(int FormId)` :1937; `EndQuestNotification/StartQuestNotification/UpdateQuest(String title,String taskid)` :2783/2825/2834; `SetQuestTracker(ObjectReference ref)` :2964; `bool Function BackgroundCmd(Form actorForm,string command)` :3971; `AIAgentTrackerQuestScript` members :1-62; `getSettingsMenuPendingAction()` poll :568-575; `isActorTalking` polls :3143-3364; `Debug.TraceUser("ChimHTTPSender", ...)` sites; `dialoguemenu.swf` providers = CHIM + Norden UI 16x9 + Norden UI 21x9 only.

### Corrections

1. **`DispatchExternalCommand` is mis-modelled, and the documented 3.3.x integration route is missing.** The report (summary item 2, section 1.2) treats `DispatchExternalCommand` as an internal C++ hop: `ExtCmd* -> DispatchExternalCommand -> SendExternalEvent -> CHIM_CommandReceived`. The official modders guide documents it as a **Papyrus global function on a third-party bridge script**:
   ```papyrus
   bool Function DispatchExternalCommand(string asNpcName, string asCommand, string asParameter) Global
   ```
   whose body reports back with `AIAgentFunctions.logMessageForActor("command@" + asCommand + "@" + asParameter + "@" + resultText, "funcret", asNpcName)` (URL above; example mod "CHIM-NFF", bridge script `CHIMNFF`, action `ExtCmdCHIMNFF_FollowMe`, metadata `{"dispatch":"plugin_command","source":"CHIM-NFF","integration":"nff","bridge_script":"CHIMNFF","bridge_entrypoint":"DispatchExternalCommand"}`). Consistent local evidence: the literal sits in the DLL exactly where the other *Papyrus function names* sit (DLL:45803-45808), it occurs in **no** CHIM `.psc`/`.pex` (grep = 0), and the server carries `ExtCmdCHIMNFF_*` rows (HS/debug/db_updates.php:745-755; HS/unittests/tests/ActionCatalogTest.php:470-486). So 3.3.2 has (at least) two ExtCmd delivery paths: static call into a bridge script AND the `CHIM_CommandReceived` mod event. The mod-event listener in section 1.5 is real but is not the route the vendor documents.
   NOT FOUND: how the DLL picks the bridge script name. `bridge_script` / `bridge_entrypoint` / `plugin_command` occur nowhere in the DLL strings, and the server never forwards them (`grep DispatchExternalCommand|bridge_entrypoint` over HerikaServer = 0 hits; `bridge_script` is only used as a `source` label, HS/processor/import_files.php:1197-1200). Hypotheses to test in-game (INFERENCE): derived from the code name `ExtCmd<Script>_<Action>`, or from the `<Script>_actions.csv` file name. Until tested, the glue should implement BOTH (a bridge script with `DispatchExternalCommand` and the mod-event listener) and de-duplicate by a per-command guard.
2. **`funcret` semantics are overstated / partly stale.** (a) The `Error` substring check exists only for `Attack` (HS/processor/request.php:26-31), it is not a general failure convention on the server. (b) `$GLOBALS["FUNCRET"]` is dead: the only occurrence in the whole server is the sample that sets it (HS/ext/herika_heal/functions.php:101); core only reads `$GLOBALS["FUNCSERV"]` (request.php:34-35). (c) Missed file: **HS/processor/funcret.php** (required from main.php:2657-2664). The after-function LLM turn happens only if the action-catalog row has `metadata.followup.enabled` = true AND a non-empty `followup.prompt`; otherwise `terminate()` (funcret.php:113-157; config keys `enabled`, `prompt`, `arg_name`, `use_functions_again` in HS/lib/core/action_catalog.php:1284-1310, 1436-1485; chain limit 1, :1312-1315). An action with no catalog row resolves to `[]` -> no follow-up. The result is still logged as an `infoaction` (funcret.php:67-93, 123). Built-in `Attack` ships with `"followup":{...,"enabled":false,...}` (HS/data/core_action_seed.sql), so CHIM's own `logMessageForActor(...,"funcret",...)` usage proves nothing about follow-ups. (d) The tool-call arguments are built by raw interpolation `"{\"$argName\":\"{$returnFunction[2]}\"}"` (funcret.php:165): field 2 must not contain `"` or JSON. The report's advice "echo the received `parameter` verbatim in field 2" is unsafe when the parameter is JSON; send a plain token (e.g. the target name).
3. **Open question 1 is partly answerable.** `comm.php` line 8 is `require($path . "main.php");` and HS/processor/comm.php has no `funcret` branch (grep), so a `funcret` reaches main.php:2657 regardless of whether it was sent with `logMessage*` or `requestMessage*`; the follow-up decision is the catalog config in correction 2. Still unknown: whether the DLL reads/plays the HTTP response of a log-type send.
4. **Open question 7 is answerable: yes.** HS/processor/comm.php:1281-1329: `setconf` does `$vars = explode("@", $gameRequest[3])` and upserts `conf_opts(id=$vars[0], value=$vars[1])` for ANY key (only `chim_renamenpc` has extra handling). Only the first two `@` fields are stored, so values must not contain `@`. Side effect worth knowing: because `chimQuestEngineFeatureEnabled()` falls back to `conf_opts` id `chim_ai_quest_progression` (chim_quest_engine.php:142-165), game-side `setconf` can influence server feature flags when no general-setting row exists.
5. **"`setDrivenByAIA` is a toggle" is labelled VERIFIED but is string-adjacency INFERENCE** (DLL:46198-46203; `setDrivenByAIReal` takes four bools whose meaning is unknown). It is consistent with the MCM only calling it on actors returned by `findAllNearbyNonAgents()` (AIAgentMCMConfigScript.psc:1231-1241), but the glue should never call it without an is-agent check first.
6. **Citation error:** `removeFromRenamedNPCList(Actor akTarget)` is at AIAgentFunctions.psc:166, not inside the quoted 54-67 block. The manual-activate call sites are :469/:474 and :835/:840 (report says 458-475).
7. **`traditional_player_speech` / `traditional_npc_speech` are not server-visible names.** They occur nowhere in HerikaServer (grep over all file types = 0). In the DLL they sit between the `chat|...` format strings and `[Chatbox] Pushing player/NPC dialogue` (DLL:48625-48636; tags at 48627 and 48635), so they are most likely Prisma-chatbox/source tags. VERIFIED only: the server receives type `chat`. Whether a server plugin can tell menu dialogue from AI chat is NOT VERIFIED (Implication 8 is weaker than stated).
8. **`PlayMenuTopic` is not "legacy".** CHIM's `Interface/dialoguemenu.swf` (CWS, decompressed by me) contains the constant run `'PlayMenuTopic','skse','SendModEvent','startTopicClickedTimer','off','TopicClicked'`, i.e. the SWF itself fires SKSE mod event `PlayMenuTopic` on topic click and holds `TopicClicked` until `startTopicClickedTimer("off")`; the DLL has a native sink (`PlayMenuTopic` / `Player TTS for Traditional Dialogue is disabled` / `...could not start`, DLL:48132-48134). Only CHIM's *Papyrus* handler is unregistered. Norden UI's SWF has none of these constants, and CHIM's SWF wins the conflict in this profile, so every topic click in LoreRim goes through this gate. A third-party script can `RegisterForModEvent("PlayMenuTopic", ...)` (standard 4-arg signature; `strArg` = topic text is INFERENCE from AIAgentPapyrusFunctions.psc:1170-1178).
9. **Implication 4 wording:** `ResetPackages` does not "apply" overrides; it REMOVES eleven package overrides (including the vanilla `doNothing` 0x654e2), clears the `MoveTargetKw` linked ref, sets `CHIM_FollowPlayerActive`=0, calls `EvaluatePackage()` and `SheatheWeapon(npc)`, then clears both flags (AIAgentAIMind.psc:41-62). 18 call sites. The disruptive parts for an OStim scene are `EvaluatePackage` + the caller's subsequent new override.
10. **Unverified inference presented as a conclusion:** "a Papyrus-only executor cannot run an arbitrary TopicInfo fragment ... this is the one place an SKSE DLL is clearly justified" (Implication 7) has no source behind it in this report; treat as a hypothesis for the dialogue-execution research topic.

### Additions (facts the report missed that matter for the glue)

1. **Action catalog + game-side data files.** The DLL scans `Data/CHIM` for `*_actions.csv` (`custom_action_import`), plus `_bios.csv`, `_oghma.csv`, `_dynamicoghma.csv`, `_descriptions.csv`, `_tradquest.csv`, `_voices.csv`, and uploads them (DLL:44800-44875; 10 MB cap). Server: `handleCustomActionImport` (HS/processor/import_files.php:1102-1259) into table `public.core_action_custom`; header-mapped columns `code_name, action_name, description, return_message, available_to_npc, available_to_followers, available_to_narrator, is_activated, game_function, parameters_json, metadata, import_version, script_proxy_program`; metadata keys seen: `dispatch` (`plugin_command` | `script_proxy`), `source`, `bridge_script`, `bridge_entrypoint`, `followup{...}`, `cooldown_seconds`, `requirements{activity{current_action_not_in[]}}`, `status`, `builtin`; `import_version` must increase to overwrite. This is how an LLM-selectable `ExtCmdGlue...` action with a follow-up prompt should be declared - no LoreRim plugin edits, no PHP needed for the definition.
2. **`*_tradquest.csv` -> `traditional_quest_import` -> `handleTraditionalQuestImport`** (HS/processor/import_files.php:51, 1021): quest definitions for the native quest engine can be shipped as a game-side data file. Directly relevant to data-driven menuless questing for mod-added quests. Other gamedata types: `loaded_plugins` (server knows the load order), `quest_import_bundled`, `quest_reset_runtime`, `quest_status` (HS/gamedata.php:49-62, 147-160).
3. **JSON helpers for ExtCmd parameters** (no JContainers needed): `jsonGetInt/jsonGetFormId/jsonGetString/jsonGetFloat/jsonGetActor/jsonGetReference/jsonGetFormList/jsonGetEffectShader(string keyName,string jsonString)` AIAgentFunctions.psc:151-158; also `int PostGameData(string jsonData)` :83 (only caller AIAgentScriptProxy.psc:1655), `int isUsingFurniture(Actor)` :85, `sendMessageToActor(String,String,Actor)` :5 (takes an Actor, unlike the name-keyed natives).
4. **Server already has an adult-scene hook.** HS/service/processors/snqe/cmd/main.php:36-45: if `sys_get_temp_dir()/nsfw_scene_active.txt` holds a unix ts younger than 300 s and >= the ts in `nsfw_scene_ended.txt`, the SNQE daemon skips quest-instruction generation ("SexLab/OStim" named in the comment). Nothing in core writes these files (grep). Event type `ext_nsfw_physics_raw` is whitelisted in HS/lib/eventlog_helper.php:30. The glue's server plugin should write the two marker files on scene start/end.
5. **Identity in LoreRim specifically.** CHIM's `SKSE/Plugins/NPCsNamesDistributor.ini` overrides LoreRim's (`LoreRim - MCM and INI Settings`, modlist.txt:143 vs CHIM :7): all `[NameContext]` values `display` (LoreRim: `title/short/full`), `iFormat = 3` (LoreRim: 0). CHIM's Nexus description warns: "LoreRim - Name changes can interfere with automatic character profiles" (meta.ini). The DLL has no NND API reference (grep `NND|NamesDistributor` = 0), so `GetDisplayName()` results under NND are the only identity key - test with generic NPCs.
6. **DLL sinks `MenuOpenCloseEvent` natively** (`ProcessorMenu::ProcessorMenuEvent`, barter/crafting, DLL:48127-48131) and `_pause_dialogue_when_menu_open` exists; Papyrus-side menu handling is absent as the report says, but menu awareness is not absent from CHIM.
7. `Command not recognized {}` and the `ExtCmd/IntCmd/WebCmd` trio were re-verified by direct `grep -a` on the DLL (`SendExternalEvent` 3 hits = base + `NPC` + `Chat` variants; `CHIM_CommandReceived`, `CHIM_NPC`, `CHIM_TextReceived`, `CHIM_Speech*` 0 hits; UTF-16 dump 0 hits) - the "events are raised in Papyrus" conclusion is solid.

8. **The DLL can deliver the server-side plugin too.** `Plugin\ServerPluginSync.cpp` (DLL:50367-50400): scans `Data/CHIM/server-plugins` for per-plugin folders (name regex `^[A-Za-z0-9][A-Za-z0-9 ._-]{0,63}$`) holding a package file whose name is a version (`^[0-9A-Za-z][0-9A-Za-z._+-]{0,63}$`, newest wins), then talks to `ui/api/plugin_packages.php?action=probe|start-upload|upload-chunk` (JSON fields `name`, `version`, `archive_name`, `size`, `total_chunks`, `upload_id`, `index`, `upload_required`). Server side: HS/ui/api/plugin_packages.php:23-64 -> `DwemerPluginPackageManager` (HS/lib/plugin_package_manager.php; also `status`, `packages` actions). So one MO2 mod can carry the ESL, the Papyrus bridge, `Data/CHIM/*_actions.csv` / `*_tradquest.csv`, AND the HerikaServer ext plugin package. Package/archive format NOT examined here (server-plugin research topic).

### Not re-verified
- "No third-party CHIM listener mod in the modlist" (full-modlist `.psc` grep not repeated; only checked that no mod ships a `CHIM` data folder and no other mod overrides `AIAgent*.pex`).
- MCM default values (e.g. `_player_tts_traditional_dialogue` default false) and the per-hotkey behaviour descriptions beyond the binder/field mapping.
- StorageUtil key inventory in 10.3.
