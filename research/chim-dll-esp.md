# CHIM 3.3.2 native layer: AIAgent.dll, AIAgent.esp and UI assets (Phase 0 research)

Scope: `F:\Modlists\LoreRim\mods\CHIM` (read-only). Method: `strings -n 6`, `strings -el -n 6` and a follow-up `strings -n 4` pass on `SKSE\Plugins\AIAgent.dll` from WSL; a read-only Python record parser for `AIAgent.esp`; an in-memory zlib decompress + minimal AVM1 disassembly of `Interface\dialoguemenu.swf`; cross-checks against `Source\Scripts\*.psc`, the HerikaServer PHP tree and the live `AIAgent.log`.

Citation conventions
- `DLL@0x2ed8e0` = file offset (hex, from `strings -t x`) inside `F:\Modlists\LoreRim\mods\CHIM\SKSE\Plugins\AIAgent.dll` (size 4,134,912 bytes, sha256 `6417e83dee4c2d2b28fa91bcf19a23d830131f056df69071aab1fadebf8e108f`).
- `X.psc:NN` = `F:\Modlists\LoreRim\mods\CHIM\Source\Scripts\X.psc` line NN.
- `HS/...:NN` = `\\wsl.localhost\DwemerAI4Skyrim3\var\www\html\HerikaServer\...` line NN.
- VERIFIED = literal text copied from an opened source. INFERENCE = my reading of how verified pieces fit together (string adjacency, log wording). Strings prove a literal exists in the binary; they do not prove control flow.

Important limitation: MSVC inlines short string literals (<= ~16 chars) as immediate `mov`s in `.text`. They show up only as fragments (example: `set_stagH9` at DLL@0x1c6f5d is the inlined literal `set_stage`). Therefore short command names, ini keys and config keys can be present in the DLL without appearing as clean strings. Where that matters I cross-checked the Papyrus or PHP side.

---

## Executive summary (10 lines)

1. `AIAgent.dll` is CHIM plugin "Version 3.3.2 (2026-09-02)", built from `D:\wt\chim-332-main-voice-hotfix\Plugin\*.cpp` (26 source files named in strings); `meta.ini` records `version=3.3.2.0`, installer `AIAgent 126330 3.3.2 ...zip`, FOMOD choice "Vanilla SE/AE Skyrim UI" for the "CHIM Player Dialogue Menu" patch.
2. The DLL emits only two literal mod events natively: `AIAgent_PlayerMenuTTSFinished` and `CHIM_PrismaMCMRequest`. The well-known `CHIM_CommandReceived`, `CHIM_CommandReceivedInternal`, `CHIM_NPC`, `CHIM_TextReceived`, `CHIM_SpeechStarted`, `CHIM_SpeechStopped` events are created in Papyrus (`AIAgentAIMind.psc`), which the DLL calls by static function dispatch.
3. Server commands reach Papyrus as: stream line `<Actor>|command|<Name>@<param>` -> DLL `parseCommand` -> (prefix `ExtCmd`) task `DispatchExternalCommand` -> Papyrus `AIAgentAIMind.SendExternalEvent(npcname, command, parm)` -> ModEvent `CHIM_CommandReceived` with three pushed strings. Wire channels `confirmcommand` / `approvedcommand` exist next to `command` and drive a native PrismaUI allow/cancel prompt.
4. CHIM 3.3.2 already captures vanilla dialogue: a `TESTopicInfoEvent` sink forwards NPC lines and the player's chosen line to the server as `chat|...` log events (internal tags `traditional_npc_speech`, `traditional_player_speech`), plus scene chatter as `chat|...background chat...` (`background_dialogue_speech`).
5. CHIM ships `Interface\dialoguemenu.swf` (vanilla-UI variant). It sends `skse.SendModEvent("PlayMenuTopic", <sanitized topic text>)` on topic click and exposes `_root.DialogueMenu_mc.startTopicClickedTimer(arg)`; with `"off"` it immediately calls `GameDelegate.call("TopicClicked", [TopicList.selectedEntry.topicIndex])`. In LoreRim it overrides Norden UI's `dialoguemenu.swf` (CHIM is higher priority).
6. The DLL contains a complete native "QuestProgression" subsystem: it posts `quest_event` / `quest_status` / `quest_action_poll` / `quest_action_ack` JSON to `gamedata.php`, and executes server-approved actions through the static Papyrus bridge `AIAgentQuestProgressionBridge` (SetQuestStage, StartQuest, SetQuestObjective*, ExecuteConsoleCommand, StartScene, ...), including native Story Manager `ActorDialogue` and `ChangeLocation` story events.
7. The server side of that is `HS/lib/chim_quest_engine.php` (3,773 lines) + `HS/data/skyrim_quest_definitions.json` (301 quests, 1,406 beats; triggers `dialogue` 988, `quest_stage` 1124, `dialogue_intent` 43; actions `set_stage` 856, `gate` 516, `set_stage_cascade` 33). Gated by general setting `CHIM_AI_QUEST_PROGRESSION` (default false). This is CHIM's own "menuless questing", by stage-setting, not by executing real TopicInfo result scripts.
8. No DLL string shows a way to programmatically select an arbitrary vanilla topic. The only topic-related native is `AIAgentFunctions.SayTo(Actor source, Actor dest, Form topicToSay)`. The PlayerMenuTTS code only holds and replays the player's own click.
9. `AIAgent.esp` is NOT ESL-flagged (TES4 flags 0x0, HEDR 1.71, 5 masters, 152 records, all 130 new object IDs > 0xFFF). It has 4 quests, 1 DIAL + 1 INFO (a silent goodbye), 9 factions, 3 keywords, 15 packages, 83 NPC templates, and deletes 4 vanilla RELA records.
10. The DLL auto-uploads server plugin packages found under `Data/CHIM/server-plugins` to `ui/api/plugin_packages.php` (`[SERVER_PLUGIN_SYNC]`), and auto-imports `Data/CHIM/*_tradquest.csv`, `*_actions.csv`, `*_oghma.csv`, etc. to `csv_import.php`: a ready-made delivery channel for the glue's server half.

---

## 1. Strings analysis of AIAgent.dll

Counts: 11,337 ASCII strings (n>=6), 22 UTF-16LE strings (n>=6), 55,316 ASCII strings (n>=4). Meaningful `.rdata` strings live between 0x2d4700 and 0x32d6d8; RTTI names from 0x3b0000 up.

### 1.0 Identity, build, source layout (VERIFIED)

```
DLL@0x310be0  2026-09-02
DLL@0x310bf0  CHIM - Skyrim Plugin - Version {} ({})
DLL@0x2d4fe0  C:\wt\chim-beta-332-current-20260902\CHIM\Plugin\build\release\vcpkg_installed\x64-windows-static-md\include\REL/Relocation.h
DLL@0x2d4fa0  SkyrimVR.exe        (UTF-16)      DLL@0x2d4fc0  SkyrimSE.exe (UTF-16)
DLL@0x318590  PrismaUI.dll        (UTF-16)      DLL@0x3185b0  RequestPluginAPI
```
Runtime confirmation: `C:\Users\Jordan\Documents\My Games\Skyrim.INI\SKSE\AIAgent.log:1` = `[Plugin.cpp:5936] CHIM - Skyrim Plugin - Version 3.3.2 (2026-09-02)`.

Source files named in strings (`D:\wt\chim-332-main-voice-hotfix\Plugin\`): Globals.h, ThreadPool.h, Misc.cpp, Misc.h, SPGResponse.cpp, Conf.cpp, Commands.cpp, Papyrus.cpp, md5.cpp, Voicerec.cpp, HTTPUploader.cpp, HerikaEyes.cpp, AudioManager.cpp, SpeakManager.cpp, SpeakManager.h, HTTPManager.cpp, SpatialAwareness.cpp, SpatialSnapshotManager.cpp, PlayerConversationRouter.cpp, Plugin.cpp, ScriptProxy.cpp, PrismaUIBridge.cpp, MusicManager.cpp, VRItemAwareness.cpp, DynamicDiaryBook.cpp, ServerPluginSync.cpp. Libraries: CommonLibSSE-NG, SkyrimScripting Plugin helper, nlohmann json 3.11.2, spdlog/fmt.

Trampoline: the live log shows `Default Trampoline => 0B / 42B`, `14B / 42B`, `28B / 42B` (`AIAgent.log:9-11`), i.e. three 14-byte allocations. INFERENCE: three call/branch hooks on flat Skyrim. Named hook strings: `ProcessorNativeScreenShot::Hook::thunk(struct ID3D11Texture2D *,const char *,enum RE::BSGraphics::TextureFileFormat)` (DLL@0x30ed40), `ProcessorVrVisemePump::InstallHooks` (DLL@0x30ede0), and the log text `VR runtime detected; skipping flat Skyrim native screenshot/dialogue hooks` (DLL@0x312ce0), which states that "dialogue hooks" exist on flat Skyrim.

### 1.1 Mod events (VERIFIED)

Emitted natively (literal event names in the DLL):

| Event | Offset | Evidence |
|---|---|---|
| `AIAgent_PlayerMenuTTSFinished` | DLL@0x2ed8e0 | next to `QueuePlayerMenuFinishedModEvent` (DLL@0x2ed900) and `[PlayerMenuTTS] Mod callback event source unavailable; cannot notify Papyrus` (DLL@0x2ed890). Papyrus handler shape: `Event OnPlayerMenuTTSFinished(String eventName, String strArg, Float numArg, Form sender)` (`AIAgentPapyrusFunctions.psc:1186`), currently unregistered (`:1100`). |
| `CHIM_PrismaMCMRequest` | DLL@0x322800 | next to `Papyrus event interface is unavailable.` (DLL@0x3227b8). Consumer: `RegisterForModEvent("CHIM_PrismaMCMRequest", "OnPrismaMCMRequest")` (`AIAgentMCMConfigScript.psc:799`); `Event OnPrismaMCMRequest(String eventName, String payload, Float numericValue, Form sender)` (`:1201`). |

Listened to natively: RTTI `.?AVPlayerMenuModCallbackEventSink@ProcessorPlayerMenuModEvent@@` (DLL@0x3d1530) implementing `BSTEventSink<SKSE::ModCallbackEvent>` (DLL@0x3d1590); the literal `PlayMenuTopic` sits at DLL@0x30ecc0 followed by `Player TTS for Traditional Dialogue is disabled` / `... could not start`. So the DLL consumes the SWF's `PlayMenuTopic` mod event itself.

NOT in the DLL (grep of the n>=4 dump for `CHIM_`, `CommandReceived`, `TextReceived`, `SpeechStart`, `SpeechStop` returned only the two rows above plus `__CHIM_APPROVED__`): `CHIM_CommandReceived`, `CHIM_CommandReceivedInternal`, `CHIM_NPC`, `CHIM_TextReceived`, `CHIM_SpeechStarted`, `CHIM_SpeechStopped`. These are created in Papyrus; see section 4.

### 1.2 Papyrus: natives registered and script functions called

Registered native class `AIAgentFunctions` (DLL@0x2f6a80). Every native below is declared in `AIAgentFunctions.psc` and its name is present in the DLL (names at DLL@0x2efdf0-0x2f6bc8). Exact signatures (VERIFIED, copied from `AIAgentFunctions.psc`, line numbers in brackets):

```papyrus
int function sendMessage(String a_msg,String a_type) Global Native                       ; [4]
int function sendMessageToActor(String a_msg,String a_type,Actor targetActor) Global Native ; [5]
int function commandEnded(String command) Global Native                                   ; [7]
int function commandEndedForActor(String command,string npc) Global Native                ; [8]
int function recordSoundEx(int bindedKey) Global Native                                   ; [10]
int function stopRecording(int bindedKey) Global Native                                   ; [11]
int function stopAllDialogue() Global Native                                              ; [13]
bool function isGameFocused() Global Native                                               ; [14]
int function setNewActionMode(int mode) Global Native                                     ; [26]
int function logMessage(String a_msg,String type) Global Native                           ; [27]
int function logMessageForActor(String a_msg,String type,String npc) Global Native        ; [28]
int function requestMessage(String a_msg,String type) Global Native                       ; [29]
int function requestMessageForActor(String a_msg,String type,String npc) Global Native    ; [30]
int function setAnimationBusy(int busy,String npc) Global Native                          ; [31]
int function setLocked(int locked,String npc) Global Native                               ; [32] 1 locks agent for talking, 0 releases
int function isActorTalking(String npc) Global Native                                     ; [33]
int function getPlayerBountyForGuard(String guardName) Global Native                      ; [34]
int function requestMoveInventoryItemConfirmation(Actor source, Actor target, Form itemForm, int amount, String realName) Global Native ; [35]
int function requestArrestConfirmation(Actor player, Actor guard, Faction crimeFaction) Global Native ; [36]
int function sendRequest() Global Native                                                  ; [37]
int function shotAndUpload(String hints,int mode) Global Native                           ; [39]
int function startSoulgazeCapture(String hints, int captureType, int renderMode, Actor target = None) Global Native ; [40]
int function isGameVR() Global Native                                                     ; [41]
int function sendLocationFast(Location curr,string tags,Cell referenceCell=None) global Native ; [43]
int function sendFactionFast(Faction factions,string name) global Native                  ; [44]
int function sendNPCFast(Actor akActor) global Native                                     ; [45]
int function setConf(String code,float float_value,int int_value,String string_value) Global Native ; [49]
int function get_conf_i(String code) Global Native                                        ; [52]
int function setAIKeyWord(Actor targetActor) Global Native                                ; [54]
int function setDrivenByAI() Global Native                                                ; [57]
int function setDrivenByAIA(Actor forcedActor,bool salutation) Global Native              ; [58]
int function addBasicProfile(Actor forcedActor) Global Native                             ; [59]
int function removeAgentByName(String name) Global Native                                 ; [60]
Actor function getClosestAgent() Global Native                                            ; [61]
Actor function getAgentByName(String npcName) Global Native                               ; [62]
Actor[] function findAllNearbyAgents() Global Native                                      ; [63]
Actor[] function findAllAgents() Global Native                                            ; [64]
Actor[] function findAllNearbyNonAgents() Global Native                                   ; [65]
Actor[] function findAllNearbyActors(bool onlyBgl) Global Native                          ; [66]
int[] function findAllAgentsFormId() Global Native                                        ; [67]
ObjectReference function getLocationMarkerFor(Location loc) Global Native                 ; [70]
ObjectReference function getWorldLocationMarkerFor(Location loc) Global Native            ; [71]
ObjectReference function getLocationCenterMarker(Location loc,int mode) Global Native     ; [72]
ObjectReference function getNearestDoor() global Native                                   ; [73]
ObjectReference function findLocationsToSafeSpawn(float minDistance,bool restriction=true) global Native ; [74]
int function scanActorsAroundOffline(Actor akActor) global Native                         ; [76]
int function updateRemoteInventory(Actor akActor) global Native                           ; [78]
string function GetLocationSpecialRefsString(int locationFormId) Global Native            ; [80]
ObjectReference function loadReference(int refFormId) global native                       ; [81]
int function PostGameData(string jsonData) global native                                  ; [83]
int Function isUsingFurniture(Actor akActor) global Native                                ; [85]
int Function isInContainer(ObjectReference item) global Native                            ; [86]
int Function SayTo(Actor source,Actor dest,Form topicToSay) global Native                 ; [88]
int function startPlayerMenuDialogueTTS(String fallbackText) Global Native                ; [138]
int function getHerikaFormId() Global Native                                              ; [146]
int function jsonGetInt(string keyName,string jsonString) global native                   ; [151]
int function jsonGetFormId(string keyName,string jsonString) global native                ; [152]
string function jsonGetString(string keyName,string jsonString) global native             ; [153]
float function jsonGetFloat(string keyName,string jsonString) global native               ; [154]
Actor function jsonGetActor(string keyName,string jsonString) global native               ; [155]
ObjectReference function jsonGetReference(string keyName,string jsonString) global native ; [156]
FormList function jsonGetFormList(string keyName,string jsonString) global native         ; [157]
EffectShader function jsonGetEffectShader(string keyName,string jsonString) global native ; [158]
string function GetDoorActivationText(ObjectReference akRef) global native                ; [160]
int function startMusicScene(string songName,string singer) global native                 ; [164]
int function stopMusicScene(string singer) global native                                  ; [165]
int function removeFromRenamedNPCList(Actor akTarget) global native                       ; [166]
```
Plus open-mic, MCM-publish (`beginChimMcmSnapshot`, `publishChimMcmEntry`, `commitChimMcmSnapshot`, `beginChimMcmAgents`, `publishChimMcmAgent`, `commitChimMcmAgents`, `publishChimMcmCommandResult`) and ~45 PrismaUI panel natives (`toggle/show/hide/focus...Panel`, `toggleSettingsMenu`, `getSettingsMenuPendingAction`, `clearSettingsMenuPendingAction`, `toggleMasterMenu`, `isChatboxPanelVisible`, `isChatboxPanelFocused`, `isAnyPrismaHotkeyPanelFocused`) at `AIAgentFunctions.psc:15-25, 91-137`. One extra name in the DLL with no psc declaration: `startPlayerMenuDialogueTTSNative` (DLL@0x2f6548).

`sendMessage` wire types seen in the DLL: `inputtext`, `inputtext_s`, `ginputtext`, `ginputtext_s`, `narrator_inputtext` (DLL@0x2ed398-0x2ed3d8), `player_interrupt` (DLL@0x2eee30), `diary`, `diary_followers`, `diary_nearby` (DLL@0x2eef68-0x2ef048). Native log format for requestMessageForActor: `{}|{}|{}|(Context location: {}){}` (DLL@0x2f00b0).

Papyrus scripts the DLL dispatches INTO (script-name literals, VERIFIED):

| Script literal | Offset | Function-name literals nearby / cross-check |
|---|---|---|
| `AIAgentAIMind` | DLL@0x2dc930 | These global functions of `AIAgentAIMind.psc` have their exact name as a standalone DLL string (automated cross-check of every psc function name against the n>=4 strings dump): AddBounty AddDelayedHint AddDelayedNPC ArrestPlayer AttackTarget BrawlTarget CastConcentrationSpell CastConstantSpell CastSpellOnTarget CheckAndReleaseWalkToTargetNPCs ChimTeleportDoorActivated ComeCloser ConfirmArrestPlayer ConfirmMoveInventoryItem ConsumeItemFeedback Despawn EndConversation EndDialogue EndDialogueClear EndDialogueClearScene EndQuestNotification EquipSpellOnPlayer FakeDialogue FakeDialogueWith FillLogJournal Follow ForgiveCrime GiveItemToTarget HireCarriage HireFerry KillActorTarget LookAt MakeFollower MoveInventoryItem MoveToTarget OpenInventory PayBounty PickupItemFromWorld PlayIdle PrepareForDialog QuestNotifySound RecoverFromCombat ReleaseFromConversation RentRoom SendCellInfo SendExternalEvent SendExternalEventChat SendExternalEventNPC SendInstruction SendInternalEvent SendSuggestion SetDisposition SetQuestTracker ShowDebugNotification ShowTrainingMenu SleepInBed SpawnAndGiveItemToActor SpawnNpcTemplateNearPlayer StartCombat StartQuestNotification StartWait StopCurrent TakeASeat TeleportActorToLocation TravelToLocation TravelToTarget UpdateQuest WaitHere addRenamedKeyword. Also `MoveToPlayer`, `stayAtPlace`, `SpawnAgent`, `SpawnItem`, `SpawnBook`, `CombatPlayer`, `Sandbox`, `BackgroundCmd` (DLL@0x2e6748-0x2e7ac0). |
| `AIAgentScriptProxy` | DLL@0x3180c0 | `ExecuteCommand` (DLL@0x3180b0), `About to call AIAgentScriptProxy` (DLL@0x318088); C++ `void SendAICommand(int, std::string)` (DLL@0x318010), `ScriptProxyRun(const std::string&)` with errors `Failed to parse AI JSON: {}` / `JSON missing cmdID`. Papyrus: `Function ExecuteCommand(int cmdID, string jsonString) global` (`AIAgentScriptProxy.psc:192`). |
| `AIAgentQuestProgressionBridge` | DLL@0x315d50 | all 18 bridge functions are DLL strings (section 1.7). |
| `AIAgentNpcUtil` | DLL@0x2e9bc0 | sits between `StartMusicScene` and `CommandAnimation`; the function it is paired with is not determinable from strings. |
| `AIAgentSoulGazeEffect` | DLL@0x2e7cc8 | after `Soulgaze`. |

Templated C++ dispatcher signatures for the quest bridge (VERIFIED, DLL@0x315cb0-0x3161b0): `DispatchQuestProgressionPapyrusCall<int,int>`, `<int,int,bool>`, `<int,int,bool,bool>`, `<int>`, `<int,int,int>`, `<std::string>`, `<int,std::string,float>`, `<int,bool>` - these match the bridge function parameter lists one to one.

### 1.3 Menus and UI hooks (VERIFIED)

Menu name literals: `Dialogue Menu` (DLL@0x2ed218), `BarterMenu` (DLL@0x30b6f8), `Book Menu` (DLL@0x30b718), `Crafting Menu` (DLL@0x30b728), `Journal Menu` (DLL@0x30b748). Sink: `ProcessorMenu::ProcessorMenuEvent::ProcessEvent(const RE::MenuOpenCloseEvent*, ...)` (DLL@0x30eb00) with logs `[BARTER_MENU] Barter menu opened/closed`, `[CRAFTING_MENU] Crafting Menu opened - capturing inventory snapshot`. `_questreset|{}|{}|` (DLL@0x30eae0) sits directly before that handler (INFERENCE: journal close triggers quest re-send via `FillLogJournal`). `DynamicDiaryBook::OnBookMenuClosed` (DLL@0x32c6c8). Config key `_pause_dialogue_when_menu_open` (DLL@0x2ef790).

Scaleform path used natively: `_root.DialogueMenu_mc.startTopicClickedTimer` (DLL@0x2ed678).

Input: RTTI `BSTEventSink<RE::InputEvent*>` + `ChatboxInputSink@PrismaUIBridge` (DLL@0x3d5178/0x3d51b8), log `[PrismaUIBridge] Chatbox input sink installed at highest priority`. Dialogue-menu user-event names, inlined in `.text` around 0x1cf354-0x1cf972 (fragments: `cancel`, `back`, `accept`, `click`, `select`, `topicclicked`, `enter`, `submit`, `activate`, `ok`, `xbuttona`, `gamepadaccept`) plus clean literals `accept`, `select`, `click`, `topic`, `submit`, `activate` at DLL@0x30c918-0x30c940, and RTTI `PlayerMenuReplayUserEventData` (DLL@0x3d14b0).

PrismaUI views loaded by literal path: `CHIM/history.html`, `CHIM/overlay.html`, `CHIM/diaries.html`, `CHIM/background_life.html`, `CHIM/config_manager.html`, `CHIM/quest_manager.html`, `CHIM/aiview.html`, `CHIM/debugger.html`, `CHIM/status_hud.html`, `CHIM/confirmation.html`, `CHIM/chatbox.html`, `CHIM/settings_menu.html`, `CHIM/master_menu.html`; JS->C++ listeners `chimHistoryCommand`, `chimOverlayCommand`, `chimDiariesCommand`, `chimBackgroundLifeCommand`, `chimConfigManagerCommand`, `chimBrowserCommand`, `chimQuestManagerCommand`, `chimAIViewCommand`, `chimDebuggerCommand`, `chimConfirmationCommand`, `chimChatboxCommand`, `chimSettingsMenuCommand`, `chimSettingsMenuReady`, `chimMasterMenuCommand`, `chimMasterMenuReady` (DLL@0x31e230-0x328de0).

### 1.4 Server endpoints and native request TYPE strings (VERIFIED)

Endpoints: `/HerikaServer/comm.php` (default path, DLL@0x2e14f0), `comm.php`, `stream.php` (DLL@0x2e15f0), `streamv2.php` (DLL@0x305a00), `godmode.php` (DLL@0x3059f0), `gamedata.php` (DLL@0x2df218), `stt.php?stuff`, `vsx.php`, `main.php`, `book.php?title=` (+`&read_request_id=`, `&book_form_id=`, `&gamets=`), `csv_import.php` (+`?type=`, `&filename=`), `itt.php?stuff`, `pic.php?stuff`, `upl.php?stuff`, `/soundcache/`, `/data/books/`, `/music/`, `ui/tools/server_version.php`, `ui/api/chim_overlay.php`, `ui/api/chim_aiview.php?npc_name=`, `ui/api/chim_debugger.php?limit=20`, `ui/api/eventlog.php?limit=`, `ui/api/chim_diaries.php`, `ui/api/chim_diary_audio.php?entry=`, `ui/api/background_life_*.php`, `ui/api/plugin_packages.php`, `home.php`, `control_panel.php`.

HTTP shapes: `GET /{0}?DATA={1} HTTP/1.1` and `GET /{0}?DATA={1}&profile={2} HTTP/1.1` (DLL@0x305518/0x305560); `POST /{} HTTP/1.1` + `Content-Type: application/json` for gamedata (DLL@0x306940); response headers parsed: `X-CUSTOM-CLOSE`, `X-Event-Type:`, `X-Narrator-Display-Name:`.

Pipe-format event templates `type|ts|gamets|data` (complete list of literals found):

```
_quest|{}|{}|{}                  _uquest|{}|{}|{}@{}@{}@{}           _questreset|{}|{}|
quest|{}|{}|(Context location: {}) Quest Updated "{}" new objetive: {}
_speech|{}|{}|{}                 _speech_abort|{}|{}|{}              delete_event|{}|{}|{}: {}
chat|{}|{}|(Context location: {}){}: {}                              (NPC vanilla line)
chat|{}|{}|(Context location: {}){}: {} ({} {})                      (player vanilla line; suffix words: "Talking to" / "Whispering to" / "Shouting to")
chat|{}|{}|(Context location: {} background chat) {}: {}             chat|...background chat) Ghost of {}: {}
chat|{}|{}|(Context location: {}){}: sings "{}"
inputtext|  inputtext_s|  ginputtext|  ginputtext_s|                 player_menu_tts_play|     (+ literal player_menu_tts_prefetch)
infoaction|{}|{}|...  (30+ variants)   funcret|{}|{}|{}   funcret|{}|{}|{} ({})
infoloc| infonpc| infonpc_close| infoitems| infoplayer| infosave|{}|{}  playerinfo| init| wipe|
addnpc|{}|{}|{}@{}   addbgnpc|{}|{}|{}@{}   enable_bg|{}|{}|{}/{:08X}   disable_bg|...   npcvoice_refresh|{}|{}|{}@{:08X}@{}
setconf|{}|{}|CurrentParty@{}   setconf|{}|{}|{}@{:08x}   setconf|{}|{}|chim_mode@{}   setconf|{}|{}|chim_profile_model@{}
request|{}|{}|(Context location: {}, {})   request|...|{}   bored|{}|{}|{}|{}   combatbark|   updateprofiles_batch_async|
location|{}|{}|(Context new location: {}, {})   lockpicked|   itemfound|   itemtransfer|   book|{}|{}|{}
death| (8 variants)  playerdied|  combatend|  combatendmighty|  bleedout|  goodmorning|  goodnight|  waitstart|  waitstop|
npcspellcast|  npc_reanimated|  switchrace|  backgroundaction|  memory|  vision|  diary|{}|{}|{}:{}  force_current_task|
util_location_name|{}|{}|{}/{}/{}/{}/{}/{}//{}/{}/{}/{}/{}   util_faction_name|{}|{}|{:08X}/{}/{}   core_profile_assign|{}|{}|{}
```
Server cross-check for `_uquest`: `explode("@", ...)` -> `[0]`=id_quest, `[2]`=briefing/data, `[3]`=stage (`HS/processor/comm.php:410-423`).

JSON `type` values posted to `gamedata.php` (clean literal in DLL and a `case` in `HS/gamedata.php:79-169`): `equipment`, `inventory`, `skills`, `spells`, `furniture`, `activity_status`, `activity_status_bulk`, `transformation_state`, `transformation_state_bulk`, `loaded_plugins`, `low_process_actors`, `quest_event`, `quest_action_poll`, `quest_action_ack`, `quest_status`, `player_item_acquired`, `player_items_acquired`. (`stats` is accepted by the server and the DLL has `RefreshAIAgentStats`, but the 5-char literal is inlined and not visible as a clean string. The live log confirms `equipment`, `inventory`, `spells`, `loaded_plugins`, `player_items_acquired` posts, `AIAgent.log:272-1102`.) Common keys: `type`, `actor_name`, `actor_type`, `gamets` (DLL@0x2df1c8-0x2df210). Server `case`s with no clean literal in the DLL: `market_stock` (built in Papyrus and sent with `PostGameData`, `AIAgentScriptProxy.psc:1628-1655`), `skyrim_stats` (sender NOT FOUND in the DLL strings or the psc sources), `quest_import_bundled`, `quest_reset_runtime`. `gamedata.php` answers HTTP 400 "Unknown type" for anything else (`HS/gamedata.php:170-174`).

### 1.5 Commands handled natively

Stream line kinds (queue names, DLL@0x2d5d90-0x2d5dd0): `animation`, `command`, `rolecommand`, `animationscripted`, `ScriptQueue`; plus `approvedcommand`, `confirmcommand` (DLL@0x2e5698/0x2e56a8). Server emit formats (VERIFIED): `"{$GLOBALS["HERIKA_NAME"]}|command|$functionCodeName@$parameter\r\n"` (`HS/connector/openrouterjson.php:1118`), `"{actor}|ScriptQueue|subtitle/expression/listener/animation/speech"` (`HS/lib/chat_helper_functions.php:1841`), `"The Narrator|rolecommand|DebugNotification@..."` (`HS/lib/dynamic_update_util.php:17`).

`parseCommand(std::string, std::string)` (DLL@0x2e7d30) - command-name literals in order of appearance DLL@0x2e7e48-0x2e9be8: `ToggleModel`, `AddBounty`, `PayBounty`, `ArrestPlayer`, `ForgiveCrime`, `Attack`, (Brawl, via `command@Brawl@`), `OpenInventory`, `SetCurrentTask`, `MoveTo`, `TravelToRaw`, `TravelTo`, `CheckInventory`, `IncreaseWalkSpeed`, `DecreaseWalkSpeed`, `ReadQuestJournal`, `PlayIdle`, `SearchMemory`, `InspectSurroundings`, `LookAround`, `Inspect`, `TakeASeat`, `GoToSleep`, `WaitHere`, `Surrender`, `UseSoulGaze`, `CastSpell`, `ExtCmd`, `IntCmd`, `WebCmd`, `TakeGoldFromPlayer`, `RentRoom`, `HireCarriage`, `HireFerry`, `FollowPlayer`, `MakeFollower`, `Follow`, `ComeCloser`, `EndConversation`, `ReturnBackHome`, `GiveGoldTo`, `TradeItems`, `Consume`, `GiveItemTo`, `TakeHeldItem`, `PickupItem`, `PlaySong`, `CommandAnimation`; fallthrough log `Command not recognized {}` (DLL@0x2e9c20). Names shorter than 6 chars or inlined may be missing from this list.

`parseRoleCommand(std::string)` (DLL@0x2e66b0) literals DLL@0x2e6698-0x2e7cc8: `spawnCharacter`, `spawnItem`, `spawnBook`, `generateLetter`, `moveToPlayer`, `stayAtPlace`, `TravelTo`, `TeleportNPCRaw`, `TeleportNPC`, `KillTargetRaw`, `SpawnNPCRaw`, `SpawnItemRaw`, `SpawnGoldRaw`, `CombatPlayer`, `Instruction`, `Suggestion`, `Disposition`, `Despawn`, `EndQuest`, `StartQuest`, `UpdateQuest`, `Sandbox`, `ImpersonatePlayer`, `QuestNotifySound`, `UploadBookContentByTitle`, `UploadBookContent`, `RawDebugNotification`, `DebugNotification`, `InternalSetting`, `QuestTrackReference`, `RefreshNPCVoice`, `RenameNPC`, `BackgroundCmd`, `ScriptProxy`, `ShowTrainingMenu`, `Soulgaze`.

Function-result convention (VERIFIED): `command@KillTarget@@Could not kill the target.` (DLL@0x2e6c20), `command@CastSpell@Error: No spell specified`, `command@Consume@#HERIKA_NPC1#@`, sent as `funcret|{}|{}|{}`. Papyrus equivalent: `AIAgentFunctions.logMessageForActor("command@Attack@"+target+"@"+text,"funcret",npc.GetDisplayName())` (`AIAgentAIMind.psc:1083`); command completion: `AIAgentFunctions.commandEndedForActor("MoveTo",npc.GetDisplayName())` (`AIAgentAIMind.psc:306`). Placeholder token: `#HERIKA_NPC1#` (DLL@0x2fd1f0).

Action confirmation (VERIFIED): `processActionConfirmationQueue` (DLL@0x2e5740), `[ACTION_CONFIRMATION] Prisma confirmation unavailable; discarded {} for {}` (DLL@0x2e5780), prompt pieces `Allow `, ` wants to perform `, `this action`, `Cancel`, marker `__CHIM_APPROVED__` (DLL@0x2e1a20), view `CHIM/confirmation.html`, listener `chimConfirmationCommand`, labels `cancelLabel` / `acceptLabel`. Server side: `herikaActionCatalogGetConfirmationCommandChannel($row)` returns `'confirmcommand'` when the action row's `metadata.custom_config.confirmation_required` is true, else `'approvedcommand'` / `'command'` (`HS/lib/core/action_catalog.php:415-449`); help text: "When enabled, CHIM asks for permission before this action executes. Cancelling the prompt silently discards the action." (`:411`). Read-only actions excluded from confirmation: checkinventory, gettime, gettopicinfo, inspect, inspectsurroundings, listinventory, readquestjournal, talk (`:388-397`).

Action target resolution: `resolveExplicitActorTarget(const ActorTargetIdentifierUtils::ParsedTarget&, ...)` with `refid:` prefix (DLL@0x2e5674) and logs `[ACTION_TARGET] Resolved RefID {:08X} to '{}' at {:.1f} units`.

### 1.6 Config keys and ini files (VERIFIED unless marked)

- `AIAgent.ini` under `Data/SKSE/Plugins` (DLL@0x2e1508, 0x2e0ef8). Reader: `bool readIniFile(const std::string&, std::string&, std::string&, std::string&, std::string&)` (DLL@0x2e0f50), i.e. four string outputs; messages `Error: invalid line format in file {}`, `Warning: unknown key {} in file {}`. The key names themselves are NOT FOUND as clean strings (inlined). No `AIAgent.ini` exists in `mods\CHIM`, in `overwrite`, in any `mods\*\SKSE\Plugins` (depth 4) or in `F:\DwemerDistro` (depth 3).
- Fallback chain (VERIFIED): `Using AIAgent.ini configuration`, else auto-discovery `GET /discover HTTP/1.1` to `localhost:7135` ("CHIM.exe proxy", DLL@0x2e1300-0x2e14c8), else `Using default fallback configuration`. Live log: `Using auto-discovered configuration: http://172.31.181.155:8081/HerikaServer/comm.php` (`AIAgent.log:29`).
- `setConf` codes (union of clean DLL strings and the Papyrus callers, `AIAgentMCMConfigScript.psc`): `_animations _auto_hearing_radius_m _autoadd_allraces _autoadd_creature_npcs _autoadd_hostile _bored_period _camera_based_audio _cancel_dialogue_on_combat _capture_background_chat _combat_barks _combat_barks_period _combat_dialogue _curve_legacy_distance _dynamic_profile_period _enable_3d_audio_playback _end_conversation_cooldown _force_mono _godmode _head_voice_volume _history_panel_enabled _history_panel_toggle _invertheadingstate _lip_int _lip_res _maintenance_period _max_distance_inside _max_distance_outside _openmic_enabled _openmic_enddelay _openmic_sensitivity _openmic_toggle_mute _pause_dialogue_when_menu_open _playback_dropoff_inside _playback_dropoff_outside _player_auto_include_radius_m _player_tts_traditional_dialogue _preserve_queue _rechat_policy_asap _restrict_onscene _sgmode _sound_ds _sound_postclip _sound_preclip _sound_volume _spatial_hearing_inside _spatial_hearing_outside _timeout _toggleAddAllNPC`. Unknown code log: `Unknown configuration code: {}` (DLL@0x2efdd0).
- Relevant semantics in log strings: `Setting _restrict_onscene to {}, so AllowActorsOnScene is {}` (DLL@0x2efb70) and `ACTOR IN SCENE (not allowed) by conf: {} {}` (DLL@0x30bd68) - NPCs that are in a Skyrim scene are refused for AI dialogue unless this is relaxed. (OStim actors are typically not in a vanilla Scene, so this is only a note.)
- Data folders read by the DLL: `Data/CHIM` with suffix scan `_bios.csv`, `_actions.csv`, `_oghma.csv`, `_dynamicoghma.csv`, `_descriptions.csv`, `_tradquest.csv`, `_voices.csv` (DLL@0x2dcbe8-0x2de5e8; import type names `biography_import`, `custom_action_import`, `oghma_import`, `dynamic_oghma_import`, `description_import`, `traditional_quest_import`; 10 MB cap); `Data/CHIM/server-plugins` (DLL@0x32ce08); `Data/SKSE/Plugins/CHIM/diaries` (DLL@0x32c188); `Data/textures/AIAgent/Books/`.
- Server plugin sync (VERIFIED): `[SERVER_PLUGIN_SYNC]` strings DLL@0x32ca70-0x32d408; API `ui/api/plugin_packages.php` + `?action=` with `probe`, `start-upload`, `upload-chunk&upload_id=` + `&index=`; JSON keys `name`, `version`, `archive_name`, `size`, `total_chunks`, `upload_id`, `upload_required`, `package`; folder-name regex `^[A-Za-z0-9][A-Za-z0-9 ._-]{0,63}$`, version regex `^[0-9A-Za-z][0-9A-Za-z._+-]{0,63}$` (DLL@0x32c8e8/0x32c910; identical regexes in `HS/lib/plugin_package_manager.php:355,365`); log `... Ignoring {} because package filename is not a valid version: {}` (so the archive file name is the version). Server: archives must be `.dwpkg` or `.zip` (`plugin_package_manager.php:11,138`), must contain `manifest.json` and `checksums.sha256` (`:266`), `schema_version` 4 (`:13`), installs to `ext/<name>` (`:448`), `server.mutable_paths` preserved (`:456`).

### 1.7 Dialogue / topic hooks, quest hooks, activation

Event sinks present (RTTI `CallbackEventSink<...>@Plugin@SkyrimScripting`, DLL@0x3d2050-0x3d2b50): TESCellFullyLoadedEvent, TESLockChangedEvent, TESCellAttachDetachEvent, TESPerkEntryRunEvent, TESActivateEvent, TESDeathEvent, TESCombatEvent, TESHitEvent, **TESTopicInfoEvent**, TESEquipEvent, TESBookReadEvent, **TESSceneEvent**, TESSpellCastEvent, **TESSceneActionEvent**, **TESScenePhaseEvent**, TESMagicEffectApplyEvent, TESActiveEffectApplyRemoveEvent, **TESQuestStageEvent**, TESEnterBleedoutEvent, TESSleepStopEvent, TESSleepStartEvent, TESContainerChangedEvent, TESTrapHitEvent, TESGrabReleaseEvent, TESWaitStartEvent, TESWaitStopEvent, TESSwitchRaceCompleteEvent, TESPackageEvent, TESObjectLoadedEvent, TESFurnitureEvent; plus `MenuOpenCloseEvent`, `ModCallbackEvent`, `InputEvent*`.

Dialogue (VERIFIED strings inside the `TESTopicInfoEvent` lambda, DLL@0x313df0-0x314210):
```
void __cdecl __skyrimScriptingPluginCallback7::<lambda_9>::operator ()(const struct RE::TESTopicInfoEvent *) const
Trying to recover audio files...            Added audiofile for {} length:{}, queue size: {}
[VOICE] Deferred voice sample captured for {} from dialogue: {}
No responses found in dialogue data         Response is null
Shouting to / Whispering to / Talking to
chat|{}|{}|(Context location: {}){}: {} ({} {})        traditional_player_speech
[Chatbox] Pushing player dialogue: {} says: {}
[VOICE] Deferred voice sample captured for {} from dialogue menu: {}
chat|{}|{}|(Context location: {}){}: {}                 audios        traditional_npc_speech
[Chatbox] Pushing NPC dialogue: {} says: {}
```
`RE::BSSimpleList<RE::DialogueResponse*>` is instantiated (DLL@0x317c60), i.e. it walks the INFO's response list. Scene/ambient dialogue: `ProcedureListenToScene` (DLL@0x30cbe8) with `[ProcedureListenToScene] Skipped recognized AI dialogue, actor:{},text:{}`, `background_dialogue_speech`, and `MonitorAllSubtitlesForChatbox` (DLL@0x30cd80) with `subtitle`; gated by `_capture_background_chat`. The tags `traditional_player_speech`, `traditional_npc_speech`, `background_dialogue_speech` do not occur anywhere in HerikaServer PHP (grep), so they are client-side labels (ThreadPool task / chatbox entry type); what the server receives is type `chat`. `chat` is in the server's `$fast_commands` log-only list (`HS/main.php:226-230`), which also honours `$GLOBALS["external_fast_commands"]` (`:232-234`).

NOT FOUND in DLL strings: `MenuTopicManager`, `DialogueItem`, `TopicInfo` (other than the event type), `GetTopicInfo`, any "select topic"/"run topic" wording.

Player menu line ("PlayerMenuTTS", Papyrus.cpp / Plugin.cpp) - VERIFIED strings: `QueuePlayerMenuTopicTimerOff`, `[PlayerMenuTTS] Releasing held topic for '{}' ({})`, `player TTS did not start within 7 seconds`, `RequestPlayerMenuTtsPlayFromServer`, `player_menu_tts_play|`, `Player|ScriptQueue|`, `PumpPlayerMenuDelayedSelectionCapture`, `[PlayerMenuTTS] NPC dialogue advanced before delayed topic capture completed; clearing state`, `[PlayerMenuTTS] Timed out waiting for selected dialogue topic; replaying original click`, `DispatchPlayerMenuDelayedSelection`, `[PlayerMenuTTS] UIMessageQueue unavailable; cannot replay delayed dialogue selection`, `EndPlayerMenuDialogueGate`, `__player_menu_tts`, `player_menu_tts_prefetch`, `[PlayerMenuTTS] Mod callback event source unavailable; native dialogue menu fallback disabled`, `QueueDialogueMenuReleaseAfterStreamTimeout` / `[HTTPManager] Released dialogue menu after stream timeout without a response for {}`. Server handler: `HS/processor/comm.php:1162-1200` (`extractPlayerMenuDialogueLine`, `emitPlayerMenuScriptQueueLine`, `player_tts.php`). Feature switch: `_player_tts_traditional_dialogue` (default off; `AIAgentMCMConfigScript.psc:2096`).

Quest hooks (VERIFIED):
- Journal push: `ProcedureSendActiveQuests` (DLL@0x2dc940) keys `name`, `formId`, `stage`, `currentbrief`, `currentbrief2`, template `_quest|{}|{}|{}`; Papyrus `FillLogJournal` in `AIAgentAIMind` (`AIAgentAIMind.psc:1937`).
- Stage events: `_uquest|{}|{}|{}@{}@{}@{}` and `quest|...Quest Updated "{}" new objetive: {}` (DLL@0x314748/0x314770).
- CHIM's own AI quests: rolecommands `StartQuest`/`EndQuest`/`UpdateQuest`/`QuestTrackReference` -> Papyrus `StartQuestNotification(String title,String taskid)`, `EndQuestNotification`, `UpdateQuest`, `SetQuestTracker(ObjectReference ref)` (`AIAgentAIMind.psc:2783-2964`), using ESP quest `AIAgentTrackerQuest`.
- QuestProgression JSON (DLL@0x30f478-0x310b90). Event envelope keys: `event_source`=`skse_plugin`, `type`=`quest_event`, `event_type`, `payload`. Event types seen as literals: `quest_stage`, `location_entered`, `actor_dead`, `item_acquired`, `item_removed`, `player_items_acquired`, `player_item_acquired`, `player_inventory_sync`. Payload keys: `display_name`, `instance_text`, `reference`, `base_plugin`, `base_form_id`, `base_name`, `quest_plugin`, `quest_form_id`, `quest_editor_id`, `quest_name`, `aliases`, `quest_stage`, `location_name`, `form_type`, `form_type_id`, `gold_value`, `total_value`, `barter_suppressed`, `crafting_active`, `source_form_id`, `source_type`, `source_name`, `source_owner`.
- Action execution. Poll: `quest_action_poll` with `limit`, response `actions`; ack: `quest_action_ack` with `action_id`, `status`, `result`; status: `quest_status` -> `server_config_enable`, log `[QuestProgression] Server global setting changed to {}`; resync: `ScheduleQuestProgressionFullResync(const char*, int, bool)`. `action_type` values and the bridge function each dispatches (literal pairs in order, DLL@0x310118-0x3108b0):

| action_type literal | Papyrus bridge function literal | bridge signature (`AIAgentQuestProgressionBridge.psc`) |
|---|---|---|
| `set_stage` (inlined, DLL@0x1c6f5d) | `SetQuestStage` | `Function SetQuestStage(int questFormId, int stage) Global` [4] |
| `set_objective_completed`, `cross_quest_set_objective_completed` | `SetQuestObjectiveCompleted` | `(int questFormId, int objectiveIndex, bool completed = true)` [11] |
| `set_objective_displayed` (+`displayed`, `force_displayed`) | `SetQuestObjectiveDisplayed` | `(int questFormId, int objectiveIndex, bool displayed = true, bool forceDisplayed = false)` [18] |
| `fail_all_objectives` | `FailAllQuestObjectives` | `(int questFormId)` [34] |
| `cross_quest_start` | `StartQuest` | `(int questFormId)` [41] |
| `start_quest_stage_objective` (+`verify_wait_ms`) | `StartQuestStageObjective` | `(int questFormId, int stage, int objectiveIndex)` [48] |
| `actor_dialogue_start_quest_stage_objective` (+`actor_plugin`, `actor_form_id`, `npc_name`, `story_event_wait_ms`) | native ActorDialogue story event, then `SetQuestStageObjective` | `(int questFormId, int stage, int objectiveIndex)` [25] |
| `change_location_start_quest_stage_objective` (+`location_plugin`, `location_form_id`, `old_location_*`, `native_start_wait_ms`) | native ChangeLocation story event / `native_ensure_start`, then `SetQuestStageObjective` | same |
| `console_command` (`command`) | `ExecuteConsoleCommand` | `(String command)` [61] -> `ConsoleUtil.ExecuteCommand` |
| `console_command_sequence` (`commands` / `command_sequence`) | `ExecuteConsoleCommandSequence` | `(String commands)` [67], separator `\|\|` |
| `stop_quest` | `StopQuest` | `(int questFormId)` [84] |
| `start_scene` | `StartScene` | `(int sceneFormId)` [91] |
| `set_actor_value` (`actor_value`) | `SetActorValue` | `(int actorFormId, string actorValue, float value)` [98] |
| `set_ghost` (`ghost`) | `SetActorGhost` | `(int actorFormId, bool ghost)` [105] |
| `evaluate_package` | `EvaluateActorPackage` | `(int actorFormId)` [112] |
| `remove_item` / `add_item` (`item_form_id`, `count`, `silent`) | `RemoveItemFromPlayer` / `AddItemToPlayer` | `(int itemFormId, int count = 1, bool silent = false)` [119/127] |
| `enable_ref` (`fade_in`) | `EnableReference` | `(int refFormId, bool fadeIn = false)` [135] |
| `set_relationship_rank` (`rank`) | `SetActorRelationshipToPlayer` | `(int actorFormId, int rank)` [142] |

  Failure strings: `unsupported action type`, `invalid action payload`, `papyrus dispatch failed`, `quest progression disabled before execution`, `quest did not reach expected stage` (+`expected_stage`, `verified`, `already_at_stage`). Story events: `ResolveQuestProgressionStoryEventIndex(enum RE::QuestEvent, const char[], unsigned int&)`, `[QuestProgression] Queued ActorDialogue story event actor={:08X} target={:08X} eventIndex={} queuedID={}`, `[QuestProgression] Queued ChangeLocation story event ...`, `begin_startup_quest_called`, `ensure_quest_started_result`, `story teller unavailable`.

Activation (VERIFIED strings in the `TESActivateEvent` lambda, DLL@0x3132c0-0x313638): `Player activated  {:#x}`, `itemfound|...`, mount/unmount `infoaction`, `book|{}|{}|{}`, `infoaction|{}|{}|{} uses {}`, `activate_event`, `Chim Portal activated by player {:#x}` -> Papyrus `ChimTeleportDoorActivated`, `[ACTIVATION] Player activates {} {}  {:X}`, `infoaction|{}|{}|{} activates {}  (hint:{})`. NOT FOUND: any string about blocking or intercepting NPC activation / the E key or suppressing the vanilla Dialogue Menu. Conversation state strings: `InterruptNPC(RE::Actor*, AIAgent*)`, `Setting dialogue busy for  {}, isPlayerTeammate {}`, `Seems {} is talking to {}`, `PrepareForDialog`, `[RECHAT] Avoiding rechat event because player is in dialogue`, `[BORED] Avoiding bored event because player is in dialogue`, `AIAgent::isAvailableforDialog(bool)`.

Other natively produced context worth knowing: `physics_raw` with bone names (VR contact telemetry, DLL@0x2fef30-0x2fefd8), `loaded_plugins` manifest (`plugin_name`, `is_light`, `compile_index`, `small_file_compile_index`, `formid_prefix`; live log `Synced 3491 loaded plugins`, `AIAgent.log:97`), chat modes `STANDARD`, `WHISPER`, `SHOUT` (inlined), `NARRATOR`, `DIRECTOR`, `CHEATMODE`, `AUTOCHAT`, `INJECTION_LOG`, `INJECTION_CHAT` (DLL@0x318788-0x3187f8).

---

## 2. AIAgent.esp (read-only parser output)

Parser: `SCRATCH\esp.py` / `esp2.py` (open in `rb` only). Output kept at `SCRATCH\esp_dump.txt`.

Header (VERIFIED):
```
TES4 flags 0x00000000  ESM(0x1)=0  LOCALIZED(0x80)=0  ESL/light(0x200)=0
HEDR version 1.71  numRecords 152  nextObjectID 0x5A0F1      CNAM = DEFAULT
MAST = Skyrim.esm, Update.esm, Dawnguard.esm, HearthFires.esm, Dragonborn.esm
own (new) records: 130 ; overrides of masters: 4 ; own object-id range 0x0093FC..0x04ADF0 (all > 0xFFF)
```
Not ESL-flagged and not ESL-eligible without compaction. It is a full plugin slot; in this profile it loads directly before `OStim.esp` (`profiles\Ultra\plugins.txt:3412-3414`). Runtime FormIDs therefore depend on load order; CHIM itself resolves by plugin name (`AIAgent.esp` literal DLL@0x2f2540).

Record counts: KYWD 3, FACT 9, SOUN 1, MGEF 1, SPEL 1, BOOK 2, DOOR 1, MISC 4, NPC_ 83, COBJ 1, DIAL 1, INFO 1, QUST 4, PACK 15, FLST 1, VTYP 1, RELA 4, SNDR 1. No GLOB, no SCEN, no DLBR, no PERK, no ACTI.

Quests (local object IDs; prefix is the load-order index):
| FormID | EditorID | Notes |
|---|---|---|
| xx0093FC | `AIAgentPapyrusFunctions` | scripts `AIAgentFunctions` (0 props) and `AIAgentPapyrusFunctions` (props `IntimacySpell` = 0002DD2A, `PlayerRefAlias` = alias 0; a third property did not parse cleanly); DNAM flags 0x0111 (bit 0x0001 = Start Game Enabled; other bits not decoded); alias `PlayerRefAlias` |
| xx009EC2 | `AIAgentMCMConfig` | script `AIAgentMCMConfigScript{controlScript = xx0093FC}`; alias `PlayerAlias` |
| xx01DC70 | `AIADialogueNullReset` | priority 30; owns the only DIAL |
| xx029E82 | `AIAgentTrackerQuest` (FULL "CHIM Quests") | scripts `AIAgentTrackerQuestScript`, `QF_AIAgentTrackerQuest_02029E82`; stage 10; objectives 10 "CHIM Quest is active!", 20 "Find the quest item."; aliases `AIAgentTrackerQuestAlias`, `AIAgentTrackerQuestLegend` |

Dialogue: DIAL xx01DC74 `AIADialogueNullResetTopic` (FULL "Silent bye", subtype `GBYE`, quest xx01DC70) with one INFO xx01DC75 (response text `...`, subrecords ENAM PNAM CNAM TRDT NAM1 NAM2 NAM3 CTDA). VTYP xx01D70E `NullVoiceType`. There are no player-facing topics, no branches, no scenes. The legacy literal `HerikaAASPGDialogueHerika3Branch1Topic` (DLL@0x2e0e68, SPGResponse.cpp) has no matching record in this ESP.

Keywords: `AIAgentMoveLocation` xx021245, `AIAgentNPC` xx0217A8, `AIAgentSandboxLocation` xx020CE3.
Factions: `AIAgentFactionAttack` xx01B6C1, `AIAgentFactionFollow` xx01BC24, `AIAgentFactionMove` xx01A69B, `AIAgentFactionRoleMaster` xx021D0B, `AIAgentFactionSandbox` xx021246, `AIAgentFactionSeat` xx01C6EA, `AIAgentFactionTravel` xx01A69C, `AIAgentFactionWait` xx02021E, `AIAgentPlayerEnemies` xx02F317. (DLL refers to `AIAgentRoleMasterFaction` / `AIAgentFollowFaction` as internal names, DLL@0x312e00/0x312e30, and posts `setconf|..|aiagent_rolemastered_faction@{:08x}`.)
Spell / effect: SPEL xx02839B `AIAgentIntimacyBubbleSpell`; MGEF xx02839A `AIAgentIntimacyBubbleEffect` with script `AIAgentIntimacyBubbleEffect{IntimacySpell, IntimacyEffect}`. The name is CHIM's existing personal-space "intimacy bubble"; it is unrelated to OStim.
FormList: `AIAgentDefaultMasterPackageList` xx0293BF = [0004E4BB, 0003EAB9].
Packages (all with PF_ fragment scripts): `AIAgentAttackPackage`, `AIAgentDoNothing`, `AIAgentFollowPackage`, `AIAgentFollowPackageSoft`, `AIAgentFollowPlayerPackage`, `AIAgentGenericPatrol`, `AIAgentMovePackage`, `AIAgentSandboxPackage`, `AIAgentSandboxWorkPackage`, `AIAgentSeatPackage`, `AIAgentSleepPackage`, `AIAgentTravelPackage`, `AIAgentWaitPackage`, `AIAgentWaitPackageSoft`, `AIAgentSandboxSleepPackage`.
Other: BOOK `AIAGenericDiaryBook`, `AIAGenericNote`; MISC `AIAgentGenericAmulet`, `AIAgentGenericNecklace`, `AIAgentGenericRing`, `AIAgentPreventRename` ("Scroll of Identity"); DOOR `AIAgentGenericPortal` ("Chim Portal"); COBJ `AIAgentGenericConstructible`; SOUN `AIAgentSoundMarker`; SNDR `AIAgentItemMarker`; NPC_ `TheNarrator` xx01F75B, `AIAgentCameraMan` xx0278D6 and 81 `AIAgentTemplate<Race><Sex><Role>` spawn templates.
Master overrides: four RELA records flagged Deleted (0x20): 0001E82B, 0001E82F, 0001E830, 0006136A.

---

## 3. Interface, PrismaUI, AIAgent folders; NND ini; meta.ini

`Interface\` (VERIFIED listing): `dialoguemenu.swf` (24,908 bytes, CWS v10) and `Translations\AIAgent_{czech,english,french,german,italian,japanese,polish,russian,spanish}.txt` (english file is 64 bytes: `$chim_soulgaze_hotkey	SoulGaze`).

Yes, CHIM ships `Interface\dialoguemenu.swf`. `meta.ini:30` records the FOMOD choice: group "Choose one for player dialogue menu patch" -> plugin "Vanilla SE/AE Skyrim UI", step "CHIM Player Dialogue Menu". Conflict in LoreRim: `mods\Norden UI 16x9\Interface\dialoguemenu.swf` (27,123 bytes) is also enabled (`profiles\Ultra\modlist.txt:147`); CHIM is at `modlist.txt:7`. MO2 writes `modlist.txt` highest priority first, so CHIM's vanilla-skin SWF wins over Norden UI's (INFERENCE from file order; not checked in the MO2 GUI). Norden's SWF has extra members CHIM's lacks (`HIDE_TOPICS`, `TOPICS_FONT_SIZE`, `fTopicsXOffset`, `sNewTopicColorSel`, `topicsFadeIn`...), and lacks `PlayMenuTopic` / `SendModEvent` / `skse` / `startTopicClickedTimer`.

What CHIM's SWF adds (VERIFIED by AVM1 disassembly of DoInitAction sprite 36, ops 1217-1411):
```
initMenu():                                   // runs on topic selection
  text = this.TopicListHolder.List_mc.selectedEntry.text
  s = text.split(" (")[0]                     // drop trailing " (...)" annotation
  s = s with each of  ' ' / \ : * ? " < > |  replaced by '_'   (10 split/join pairs)
  skse.SendModEvent("PlayMenuTopic", s)       // 2 args: event name, strArg
startTopicClickedTimer(arg):
  if (arg == "off") { this.timerBool = false;
        gfx.io.GameDelegate.call("TopicClicked", [this.TopicList.selectedEntry.topicIndex]); }
  else { ms = Math.round(len(text.split(" (")[0]) * 60 / 300 * 1000) + 1400;
        this.timer = setTimeout(this, "topicClicked", ms); }
topicClicked(): ... (timerBool guarded; ends in the same GameDelegate "TopicClicked" call)
```
So the vanilla engine callback `TopicClicked(topicIndex)` is deferred until CHIM releases it. Papyrus-side legacy handlers still exist but are unregistered: `Event OnPlayerMenuTopicSelected(String eventName, String strArg, Float numArg, Form sender)` (`AIAgentPapyrusFunctions.psc:1170`), release call `UI.InvokeString("Dialogue Menu", "_root.DialogueMenu_mc.startTopicClickedTimer", "off")` (`:1174`), comment "Traditional dialogue Player TTS is handled in native code. Re-registering the Papyrus bridge here can resume the held topic twice." (`:1097-1098`).

`PrismaUI\views\CHIM\` (names only): aiview, background_life, browser, chatbox, config_manager, confirmation, debugger, diaries, history, master_menu, npc_manager, overlay, quest_manager, settings_menu, status_hud (each .html/.css/.js where applicable), plus `layout.js`, `story_log.js`, `ui_scale.js`, `images\chim-icon.png`, `images\paper.jpg`. No ini/json/txt config files in this folder.

`AIAgent\`: `LICENSE` (MIT, "Copyright (c) 2024 abeiro") and `Textures\AIAgent\Books\.placeholder`. No config files.

`SKSE\Plugins\NPCsNamesDistributor.ini` is a settings file for the separate SKSE mod NPCs Names Distributor (NND; its DLL is in `mods\NPCs Names Distributor\SKSE\Plugins\`). CHIM ships it to force NND's name style: `[General] bEnabled = true`; `[NameContext]` every context (`sCrosshair`, `sCrosshairMinion`, `sSubtitles`, `sDialogue`, `sDialogueHistory`, `sInventory`, `sBarter`, `sEnemyHUD`, `sOther`) = `display`; `[Obscurity] bEnabled = false`; `[DisplayName] iFormat = 3` ("[name] [[title]]"); hotkeys RCtrl+N/O/L/G etc. It overrides LoreRim's own copy (`mods\LoreRim - MCM and INI Settings\...`, `modlist.txt:143`), which used `title/short/full` contexts and `iFormat = 0`. INFERENCE: CHIM identifies NPCs by display name (all pipe events carry names; `getAgentByName`), so it wants one consistent display string in every context; with iFormat 3 an NND-named generic NPC's CHIM name looks like `Name [Title]`.

`meta.ini` (VERIFIED): `gameName=SkyrimSE`, `modid=126330`, `version=3.3.2.0`, `newestVersion=3.2.2.0`, `installationFile=AIAgent 126330 3.3.2 2026-09-06T15-34Z Ks18n09qR.zip`, `repository=Nexus`, `nexusLastModified=2026-09-15T04:40:51Z`. (Nexus's "newest" is older than the installed file, i.e. 3.3.2 is a beta/optional file.)

---

## 4. How the DLL delivers server commands to Papyrus

VERIFIED chain:
1. Server stream line: `<ActorName>|command|<FunctionCodeName>@<parameter>` (or `|confirmcommand|`, `|approvedcommand|`, `|rolecommand|`, `|ScriptQueue|`, `|animation|`, `|animationscripted|`).
2. DLL `parseCommand(std::string, std::string)` / `parseRoleCommand(std::string)` match the name. Built-ins call a Papyrus global on `AIAgentAIMind` by name (VM static dispatch; error strings like `[MakeFollower] About to dispatch Papyrus call!`, `[PickupItem] Failed to dispatch Papyrus call!`).
3. For third-party actions the literals are, in this order (DLL@0x2e8bbc-0x2e8c14): `ExtCmd`, `DispatchExternalCommand`, `SendExternalEvent`, `IntCmd`, `SendInternalEvent`, `WebCmd`. The server doc states the contract: "External/3rd party functions should start with prefix ExtCmd or WebCmd. ExtCmd is for functions whose return value is provided by a Papyrus plugin. WebCmd is for functions which return value is provided by server plugin itself" (`HS/ext/herika_heal/functions.php:7-9`; the event name in that old example, `SPG_CommandReceived`, is outdated).
4. Papyrus creates the mod event (`AIAgentAIMind.psc:1395-1405`):
```papyrus
function SendExternalEvent(String npcname,String command,String parm) global
	int handle = ModEvent.Create("CHIM_CommandReceived")
	if (handle)
		ModEvent.PushString(handle, npcname)
		ModEvent.PushString(handle, command)
		ModEvent.PushString(handle, parm)
		ModEvent.Send(handle)
```
   Siblings: `SendInternalEvent` -> `CHIM_CommandReceivedInternal` (npcname, command, parm) (`:1382-1393`), consumed by `RegisterForModEvent("CHIM_CommandReceivedInternal", "CommandManager")` (`AIAgentPapyrusFunctions.psc:1096`) / `Event CommandManager(String npcname,String  command, String parameter)` (`:1286`, handles `AnimationEvent`, `IntCmd` with `MuteMusic`/`UnmuteMusic`/`InterruptScene`); `SendExternalEventNPC(String npcname,String actionName)` -> `CHIM_NPC` (2 strings, `:1407-1416`); `SendExternalEventChat(String npcname,String text)` -> `CHIM_TextReceived` (2 strings, `:1419-1430`); `CHIM_SpeechStarted` / `CHIM_SpeechStopped` push a Form (the NPC) (`:1719-1722`, `:1790-1793`, `:1845-1848`).
   A listener therefore looks like: `RegisterForModEvent("CHIM_CommandReceived", "OnChimCommand")` + `Event OnChimCommand(String npcname, String command, String parm)`.
5. Completion/result: `AIAgentFunctions.commandEndedForActor(command, npcName)` and a `funcret`: `AIAgentFunctions.requestMessageForActor("command@<Cmd>@<param>@<result text>", "funcret", npcName)` (LLM follow-up) or `logMessageForActor(...)` (log only).
6. Generic engine proxy: rolecommand `ScriptProxy` -> `ScriptProxyRun(json)` needs `cmdID` -> `AIAgentScriptProxy.ExecuteCommand(int cmdID, string jsonString)`; ranges 1-99 Actor, 100-199 ObjectReference, 200-299 FormList, 300-399 EffectShader, 400-499 ActorUtil, 500-599 Faction (`AIAgentScriptProxy.psc:192-208`); target key `targetObjectFormId`. Includes 29 `AllowPCDialogue`, 100 `Activate`, 116 `BlockActivation`, 131 `PlaceAtMe`, 400 `AddPackageOverride`. Papyrus -> server JSON: `AIAgentFunctions.PostGameData(finalData)` using `|` where JSON needs `"` (`AIAgentScriptProxy.psc:1628-1655`; INFERENCE: the native swaps `|` for `"`).

---

## 5. Does CHIM 3.3.x already capture vanilla dialogue / chosen topics, or trigger topics?

VERIFIED - capture:
- NPC vanilla lines: `TESTopicInfoEvent` -> `chat|ts|gamets|(Context location: L)Speaker: line` (+ voice file capture: `audios`, `[VOICE] Deferred voice sample captured ...`).
- Player's chosen line: same event path -> `chat|...|(Context location: L)Player: line (Talking to|Whispering to|Shouting to <NPC>)`; and, when `_player_tts_traditional_dialogue` is on, the SWF's `PlayMenuTopic` text goes to the server as `player_menu_tts_prefetch` / `player_menu_tts_play` (type names) while the click is held, then released.
- Ambient/scene lines: `chat|...(Context location: L background chat) Speaker: line` when `_capture_background_chat` is on; server classifies rows containing "background chat" as `chat_background` (`HS/lib/data_functions.php:915,2556`).
- Quest state: `_quest`, `_uquest`, `quest`, and the JSON `quest_event`/`quest_stage` stream.
What is NOT forwarded (no string evidence): topic FormIDs / INFO FormIDs to the server in the `chat` events (the only `topic {:08X}` strings are local voice-sample logs), the list of currently available topics, or INFO result-script data.

VERIFIED - triggering:
- Only `SayTo(Actor source, Actor dest, Form topicToSay)` (log `[PAPYRUS] Papyrus::SayTo - Source: {} (0x{:08x}), Target: {} (0x{:08x}), Topic: {} (0x{:08x})`, DLL@0x2f38c0). Sole caller: `AIAgentFunctions.SayTo(npc,Game.GetPlayer(),stopSinging)` with `Topic stopSinging=Game.GetForm(0x0005583a) as Topic` (`AIAgentPapyrusFunctions.psc:1323-1327`). Whether this native runs the chosen INFO's script fragments is NOT determinable from strings.
- The PlayerMenuTTS gate can hold and replay the player's own selection (`replaying original click`, `UIMessageQueue`), not choose a topic.
- CHIM's own answer to "quests without the menu" is the QuestProgression engine (stage/objective/console actions from authored JSON), not topic execution.

INFERENCE: with the Dialogue Menu open, a script could set the list selection and call `_root.DialogueMenu_mc.startTopicClickedTimer("off")`, which calls the real engine callback `TopicClicked(topicIndex)`; that would run the genuine topic (conditions, responses, result scripts). This needs the menu open on that NPC and is untested.

---

## Implications for the glue

1. Register for `CHIM_CommandReceived` with a 3-string handler `(npcname, command, parm)`; name every server action with the `ExtCmd` prefix (e.g. `ExtCmdStartIntimacy`); report with `requestMessageForActor("command@<Cmd>@<param>@<text>", "funcret", npcName)` and release with `commandEndedForActor`. Do not look for a native event; the DLL emits none for commands.
2. Hard gate for the intimacy action, layer 1 (free): set `metadata.custom_config.confirmation_required = true` on the action row so the server emits `confirmcommand` and the DLL shows the PrismaUI Allow/Cancel prompt; cancel discards silently. It depends on PrismaUI being loaded (it is: `AIAgent.log:15`); if Prisma is missing the DLL discards the action. Keep the glue's own Papyrus-side gates (player involved, adult, consent flag, not in combat/scene) as layer 2, since `approvedcommand` can bypass the prompt by user setting.
3. Ship the server half through CHIM's own channel: put `<Name>\<version>.zip` (or `.dwpkg`) under `Data\CHIM\server-plugins\` in the glue's MO2 mod; the DLL probes and uploads it at startup and the server installs it to `ext/<Name>`. Requirements to meet: `manifest.json` (schema_version 4, `name`, `version`, `server.mutable_paths`) and `checksums.sha256`. The exact manifest field list should be taken from `HS/lib/plugin_package_manager.php:326-350` before packaging.
4. Menuless questing - decide build-on vs. build-beside. CHIM already has trigger capture (`dialogue_turn`, LLM `dialogue_intent` evaluator, `quest_stage`, `location_entered`, `actor_dead`, items), an action queue with ack/verify, native Story Manager events and a Papyrus bridge. Its coverage is 301 authored vanilla/DLC quests and it sets stages directly, so INFO result scripts that do more than `SetStage` (give items, enable refs, start scenes, set globals/aliases) are only reproduced when the author added matching actions. The glue's "execute the REAL topic effects, data-driven" goal is a different mechanism; the cleanest split is: glue extracts TopicInfo data (conditions, result-script fragments, quest/stage links) offline into data, and either (a) feeds CHIM's engine extra definitions (`*_tradquest.csv` in `Data\CHIM` -> `traditional_quest_import`, or `skyrim_quest_definitions`), or (b) runs its own executor. Avoid double-advancing: if `CHIM_AI_QUEST_PROGRESSION` is enabled, both systems would set stages. Read `server_config_enable` via `quest_status` or the setting row before acting.
5. For running real fragments, the available primitives in CHIM are `console_command` (ConsoleUtil), `set_stage`, `start_scene`; there is no "run INFO fragment" primitive. An SKSE DLL of the glue's own is justified only for that: invoking a TopicInfo's script/`TopicClicked` path without the menu. Test the cheaper Scaleform route first (`startTopicClickedTimer("off")` with a hidden/auto-driven Dialogue Menu).
6. Use the `PlayMenuTopic` mod event as a free signal: any Papyrus script can `RegisterForModEvent("PlayMenuTopic", ...)` and receive the sanitized text of the topic the player clicked (spaces and `/ \ : * ? " < > |` become `_`, text after " (" dropped). It fires only with CHIM's SWF active. Do not call `startTopicClickedTimer` from the glue while CHIM's native gate is active (double release is the bug CHIM's comment warns about).
7. `dialoguemenu.swf` is a three-way concern: CHIM's vanilla-skin file currently overrides Norden UI's in LoreRim. The glue must not ship its own `dialoguemenu.swf`. If the user wants Norden's look back, CHIM's PlayerMenuTTS/`PlayMenuTopic` features stop working (they are optional and default off).
8. Scene awareness messages should go through existing log-only types: `AIAgentFunctions.logMessage(text, "infoaction")` or a custom type registered in `$GLOBALS["external_fast_commands"]` (`HS/main.php:232-234`). Do not use `PostGameData` for custom types: `gamedata.php` rejects unknown `type` with HTTP 400.
9. `AIAgent.esp` is a full (non-ESL) plugin with load-order-dependent FormIDs: reference its forms with `Game.GetFormFromFile(0x0217A8, "AIAgent.esp")` style lookups (e.g. keyword `AIAgentNPC`, faction `AIAgentFactionRoleMaster` 0x021D0B), never hard-coded full IDs. The glue's own ESL plugin should not list `AIAgent.esp` as a master unless it must override a CHIM record (it should not).
10. NPC naming: CHIM + NND `iFormat = 3` means agent names may be `Name [Title]`. Match OStim actors to CHIM agents by Actor form (`getAgentByName` returns Actor; `findAllNearbyAgents`) and send names obtained from `GetDisplayName()`, not base names.
11. While an OStim scene runs, consider `AIAgentFunctions.setLocked(1, npcName)` / `setAnimationBusy(1, npcName)` semantics ("1 locks agent for talking") only if speech must be paused; otherwise leave agents unlocked so scene-aware conversation works. CHIM's automatic events already skip when `player ... scene:{}` is true (`[BORED_TIMER] Skipped - player busy (combat:{} attack:{} sneak:{} scene:{} dialogue:{})`), which refers to vanilla scenes.

---

## Open questions

1. Exact `AIAgent.ini` key names (inlined in the DLL; no ini file on disk). Needs the CHIM docs or a disassembly; not needed while auto-discovery works.
2. Does native `SayTo` (and vanilla `ObjectReference.Say`) execute the selected INFO's begin/end script fragments and honour its conditions? Needs an in-game test; decides whether topic effects can be fired without the menu and without a new DLL.
3. Does driving `_root.DialogueMenu_mc.startTopicClickedTimer("off")` (after setting `TopicList` selection) reliably trigger `TopicClicked` for an arbitrary index while CHIM's native PlayerMenu gate is idle? Untested.
4. Is `CHIM_AI_QUEST_PROGRESSION` enabled in this install? The live `AIAgent.log` contains no `[QuestProgression]` lines, and the server default is false; the settings table was not queried.
5. Which exact payload fields does the server put in a `quest_action_poll` action row (beyond `action_type`, `action_id`)? See `chimQuestEngineFetchPendingActions` (`HS/lib/chim_quest_engine.php:3284-3320`); left to the server-side researcher.
6. Column layout of `*_tradquest.csv` for `traditional_quest_import` (handled by `HS/csv_import.php`); not inspected here.
7. How the DLL maps `confirmcommand` prompts when several actions queue at once, and whether ExtCmd actions (Papyrus-executed) honour it the same way as built-ins. String evidence only shows a queue (`processActionConfirmationQueue`); needs a live test.
8. Short/inlined command names may be missing from the native command list in 1.5; the authoritative list is the server action catalog.
9. The three trampoline hooks' exact targets (screenshot + two dialogue-related?) are not identifiable from strings; relevant only if the glue adds its own DLL hooking the same dialogue functions.
10. The MO2 priority reading (CHIM's `dialoguemenu.swf` beating Norden UI's) is inferred from `modlist.txt` order; confirm in MO2's Conflicts tab.
