# MinAI as a reference implementation (CHIM + OStim) - Phase 0 research

Research date: 2026-09-21. Method: WEB ONLY (GitHub web pages, api.github.com, raw.githubusercontent.com) through a fetch tool that transcribes pages via a small model. Nothing was cloned or downloaded. A handful of claims were cross-checked READ-ONLY against the local CHIM / OStim / HerikaServer installs (marked LOCAL).

IMPORTANT CAVEATS ON EVIDENCE QUALITY
- The canonical repo `github.com/MinLL/MinAI` returns HTTP 404 today (repo page, README, releases, API all 404; MinLL's public repo list no longer contains MinAI). All source evidence below therefore comes from the surviving fork network:
  - `id1001-gthb/MinAI_1001` (branch `main2patch`, pushed 2026-07-15, the only maintained continuation; plugin version `2.1.3-dev8a`) - PRIMARY SOURCE.
  - `programotter/MinAI` (branch `main`, snapshot pushed 2024-09-24; GitHub now treats it as the fork-network root) - used for history only.
  - `vrelk/MinAI` (branch `main`, pushed 2025-07-04, close to MinLL's last upstream state) - used for history only.
- Identifiers and code blocks were reproduced by the fetch tool verbatim, but its LINE NUMBERS ARE APPROXIMATE (it visibly duplicated/skipped numbers in long files). Treat "~Lnnn" as a locator hint; the function name + URL is the authoritative citation.
- URL shorthand used below:
  - `RAW/` = `https://raw.githubusercontent.com/id1001-gthb/MinAI_1001/main2patch/`
  - `PSC/` = `RAW/Scripts/Source/`
  - `PLG/` = `RAW/minai_plugin/`

---

## Executive summary (10 lines)

1. MinAI = `MinAI.esp` + ~35 Papyrus scripts (`minai_*.psc`) + a HerikaServer ext plugin folder `ext/minai_plugin/`. NO SKSE DLL of its own. Hard deps: JContainers, PapyrusUtil, PO3 Papyrus Extender, Papyrus Tweaks NG, SPID.
2. It listens to `CHIM_CommandReceived` (handler `CommandDispatcher(String speakerName, String command, String parameter)`). Older snapshot used `AIFF_CommandReceived`. `SPG_CommandReceived` is NOT FOUND anywhere in MinAI or in local CHIM 3.3.2 sources. LOCAL CHIM sends `CHIM_CommandReceived` (AIAgentAIMind.psc:1397).
3. OStim side: registers 5 OStim mod events (`ostim_thread_start`, `ostim_thread_scenechanged`, `ostim_thread_speedchanged`, `ostim_actor_orgasm`, `ostim_thread_end`) to ONE handler `OStimManager(string eventName, string strArg, float numArg, Form sender)`. Starts scenes with `OThreadBuilder.create` -> `SetStartingAnimation` -> `Start`; changes scenes with `OThread.QueueNavigation(ThreadID, newScene, 2.5)`. It does NOT use OStim's navigation graph; it picks a random scene by action/tag CSV via `OLibrary`.
4. Game -> server state uses only stock CHIM message types: `setconf` (key/value into `conf_opts`, keys `_minai_<actor>//<var>`), plus custom fast types (`updateThreadsDB`, `registeraction`, `storecontext`, `minai_init`) declared through `$GLOBALS["external_fast_commands"]` (LOCAL main.php:233 supports this).
5. Scene awareness server side: table `minai_threads` (one row per OStim/SexLab thread) + table `minai_scenes_descriptions` keyed by `ostim_id`/`sexlab_id` with `{actor0}`/`{actor1}` placeholders (~3.4k hand/LLM-written rows shipped as CSV), with a FALLBACK string built in Papyrus from `OMetadata.GetSceneTags` + `OMetadata.GetActionTypes`. Description is written into the event log as `info_sexscenechange` wrapped in `<SEX_SCENARIO>#SEX_SCENARIO #ID_<thread>: ...`; `context.php` prunes all but the latest per thread.
6. In-scene speech is request-driven from Papyrus: custom request types `sextalk_scenechange|speedincrease|speeddecrease|climax|climaxchastity|end|ambient`, throttled by ONE global real-time cooldown `config.commentsRate` (default 15 s), bypassed for climax/end; transitions skipped via `OMetadata.isTransition`; ambient timer via `RegisterForSingleUpdate(commentsRate)`. Server blocks radiant/rechat during scenes.
7. Actions: ~35 `ExtCmd*` sex commands registered twice (Papyrus action registry with exponential backoff; PHP `directRegisterAction` adds to `$GLOBALS["FUNCTIONS"]`, `F_NAMES`, `F_TRANSLATIONS`, `FUNCRET`, `ENABLED_FUNCTIONS`). Gating = arousal threshold (default 0), `enableAISex`, not-in-combat, factions `NoActionsFaction/NoNSFWActionsFaction/NoSexActionsFaction`, child check, `allowSexTransitions`. NO relationship/consent gate. Non-consensual content is supported - incompatible with our "consenting adult" rule; do not inherit.
8. funcret format seen: `"command@<ExtCmdName>@@<text>"` sent with `AIAgentFunctions.logMessageForActor(msg, "funcret", speakerName)`; only the clothing commands send it. Sex-start commands send an `info_sexscene` event instead.
9. Compatibility: fork `2.1.3-dev8a` claims "Compatibility with CHIM v3.1.x ... Not compatible with previous CHIM versions"; nothing claims 3.2/3.3. The fork PATCHES HerikaServer core `main.php` via `utils/xtra/m_patch.py` and replaces the whole system prompt (`$GLOBALS["head"]`) - fragile; LOCAL main.php:2148-2149 already contains a "Legacy plugin prompts can break rechat" reset of `$GLOBALS['action_prompts']`, i.e. CHIM core actively defends against this style.
10. License: NONE in any fork (API `license: null`, no LICENSE file) => all rights reserved. Reuse IDEAS only; do not copy code, prompt text, or the scene-description CSVs. MinAI does essentially NOTHING for vanilla dialogue/quest execution (a debug-only dialogue-detection effect and CHIM's own current-task context) - menuless questing is greenfield.

---

## 1. Repo layout

### 1.0 Repo status (VERIFIED)
- `https://github.com/MinLL/MinAI` -> HTTP 404 (also `/blob/main/README.md`, `/releases`, `/releases/tag/2.0.0`, `https://api.github.com/repos/MinLL/MinAI`). `https://github.com/MinLL?tab=repositories` lists SkyrimNet repos but no MinAI.
- `https://api.github.com/repos/id1001-gthb/MinAI_1001`: `default_branch: main2patch`, `license: None`, `pushed_at: 2026-07-15T16:58:12Z`, `fork: true`, `parent: programotter/MinAI`, `has_issues: false`.
- `https://api.github.com/repos/programotter/MinAI`: `default_branch: main`, `license: null`, `pushed_at: 2024-09-24T12:18:05Z`, `fork: false`, `forks_count: 23`, `has_issues: false`.
- Fork README (`RAW/README.md`, per `https://github.com/id1001-gthb/MinAI_1001`): original developer discontinued the project and started SkyrimNet; id1001 maintains the fork.
- `PLG/manifest.json` (verbatim):
```json
{
    "name":"MinAI",
    "description":"Extension for CHIM that expands its capabilities and optionally adds NSFW integrations.",
    "config_url":"/HerikaServer/ext/minai_plugin/index.html",
    "mod_download_url":"https://github.com/id1001-gthb/MinAI_1001/releases/download/minai_plugin_web_213dev7g/MinAI-2.1.3g.zip",
    "git_repo":"https://github.com/MinLL/MinAI",
    "version":"2.1.3-dev8a"
}
```

### 1.1 Top level (source: `https://api.github.com/repos/id1001-gthb/MinAI_1001/git/trees/main2patch?recursive=1`, `truncated: false`)
```
.github/workflows/main.yml
Data/minai/sexlab_descriptions.json
Scripts/            (*.pex)
Scripts/Source/     (*.psc)
mantella_files/     (config.ini, prompts_vanilla.txt)
minai_plugin/       (HerikaServer ext plugin)
MinAI.esp
MinAI_DISTR.ini     (SPID)
README.md  FAQ.md  ModdersGuide.md  nsfw.md  nsfw_Scenes.md
BUG_REPORT_TEMPLATE.md  CHIM_BETA_TESTING.md  MINAI_BETA_TESTING.md
```
No LICENSE / COPYING file. No DLL anywhere in the tree (VERIFIED: no `SKSE/` folder in tree listing).

`RAW/MinAI_DISTR.ini` (verbatim):
```
Spell = 0x0917~MinAI.esp|ActorTypeNPC,-PlayerKeyword,-Stray Cat
Keyword = 0x920~MinAI.esp|Lucien Flavius
```
(INFERENCE: spell 0x0917 is the per-NPC "context/sapience" cloak ability distributed to all NPCs by SPID.)

### 1.2 Game side - Papyrus scripts (all in `PSC/`)
```
minai_AIFF  minai_AmbientSexTalk  minai_Arousal  minai_BleedoutDetectionEffect
minai_CombatDetectionEffect  minai_CombatManager  minai_Config  minai_ContextEffect
minai_Crime  minai_DeviousStuff  minai_DialogueDetectionEffect  minai_DirtAndBlood
minai_EnvironmentalAwareness  minai_FertilityMode  minai_FillHerUp  minai_Followers
minai_ItemCommands  minai_MainQuestController  minai_Mantella  minai_NPCRelations
minai_PlayerScript  minai_Relationship  minai_Reputation  minai_SapienceController
minai_SapienceEffect  minai_SceneDetectionEffect  minai_Sex  minai_SexAwareness
minai_SexOstim  minai_SexSexlab  minai_SexUtil  minai_Survival
minai_ToggleSapienceEffect  minai_Util  minai_VR
```
plus overridden third-party scripts shipped in the mod: `BakaSnareTrap, BakaTrapDeathWorm, BakaTrapDeathWormVore, BakaTrapMimic, Dirty_CleaningYoSelf, MantellaConversation, RealNamesChange` (pitfall: MinAI overwrites other mods' scripts).

Architecture (VERIFIED from casts in `PSC/minai_Sex.psc` `Maintenance`): most scripts are attached to ONE quest (form `0x0802` in MinAI.esp) and reach each other with `(Self as Quest) as minai_X`; config is a separate form `Game.GetFormFromFile(0x0912, "MinAI.esp") as minai_Config`; followers `0x0913`; ambient sex talk `0x0E88`; confirm message `0x0914`; OStim/SexLab toggle GlobalVariable `0x0906`.

### 1.3 Server side - `ext/minai_plugin/` hook files
Hook files that HerikaServer auto-includes by file name (LOCAL: `requireFilesRecursively(__DIR__."/ext/", "<name>")` at HerikaServer main.php:54 `globals.php`, :193 `preprocessing.php`, :1117 `prerequest.php`, :2540 `context_pre.php`, :2648 `context.php`, :2944 `prepostrequest.php`, :2946 `postrequest.php`; prompts/prompts.php:285 `prompts.php`; prompts/dialogue_prompt.php:370 `dialogue_prompt.php`; functions/json_response.php:81 `json_response_custom.php`; lib/data_functions.php:3217 `context_building.php`).

MinAI ships these of them:
| MinAI file | Role (VERIFIED from fetched source) |
|---|---|
| `PLG/globals.php` | sets defaults, `$GLOBALS["external_fast_commands"]`, requires `config.base.php`/`config.php`, copies `config.base.php` -> `config.php` if missing |
| `PLG/preprocessing.php` | sets `$GLOBALS["minai_skip_processing"]` for fast commands; rewrites request types `minai_diary`->`diary`, `minai_updateprofile`->`updateprofile` (+ `_player` variants call `SetNarratorProfile()`) |
| `PLG/prerequest.php` | large: config validation, NPC profile fallback, magic-event blacklist, item-command post-processing, context trimming (fetch tool refused verbatim dump; summary only) |
| `PLG/context_pre.php` | `get_player_data()`, `get_narrator_data()`, `get_NPC_data($GLOBALS["HERIKA_NAME"])`; if `IsRadiant()` or `IsSexActive()` sets `$GLOBALS["BORED_EVENT_SERVERSIDE"]=false; $GLOBALS["RANDOM_NARATION"]=false` |
| `PLG/context.php` | rebuilds `$GLOBALS['head']`, prunes `#SEX_SCENARIO` / `#SEX_INFO` / `#PHYSICS_INFO` lines from `$GLOBALS["contextDataFull"]`, calls `UpdateSystemPrompt()` |
| `PLG/prompts.php` | defines `$GLOBALS["PROMPTS"][...]` keys; requires `sexPrompts.php`, `customintegrations.php`, `prompts/*.php` |
| `PLG/dialogue_prompt.php` | builds `$GLOBALS["TEMPLATE_DIALOG"]`; swaps to scene-specific variants when `getScene($currentName)` is truthy |
| `PLG/functions.php` | loads `functions/*.php` modules conditionally; purges commands from `$GLOBALS["ENABLED_FUNCTIONS"]` |
| `PLG/json_response_custom.php` | adds keys to `$GLOBALS["responseTemplate"]`; sets `$GLOBALS["ENFORCE_ACTIONS_PROMPT"]=true` |
| `PLG/command_prompt_custom.php` | custom command prompt (not audited) |
| `PLG/postrequest.php` | effectively empty (comments only) |
| NOT present | no `comm.php`, no `context_building.php`, no `prepostrequest.php` in the tree |

Other notable server files: `customintegrations.php` (`ProcessIntegrations()` - the custom message-type router), `updateThreadsDB.php`, `sexPrompts.php`, `speakStylesPrompts/*.php` (12 styles), `utils/sex_utils.php`, `scene_fallback_process.php`, `importDataToDB.php`, `sceneDescriptionsDBImport/*.csv` (22 files), `xPersonalitiesDBImport/*.csv`, `contextbuilders/**`, `functions/action_builder.php`, `functions/sex.php`, `api/*.php` + `*.html` (config web UI), `utils/xtra/m_patch.py` + `*.src/*.rpl` (CORE PATCHER, see section 5), `m_init.sh` (log trimming; deletes `context_for_*` logs and trims Apache logs with `sed -i`).

---

## 2. OStim integration - Papyrus side

Scripts: `PSC/minai_Sex.psc` (framework-agnostic controller + event handlers), `PSC/minai_SexOstim.psc` (OStim calls), `PSC/minai_SexUtil.psc`, `PSC/minai_AmbientSexTalk.psc`, `PSC/minai_AIFF.psc` (CHIM bridge), `PSC/minai_MainQuestController.psc` (request throttle).

### 2.1 Event registration (`PSC/minai_Sex.psc`, `Maintenance`, ~L59-63)
```papyrus
  RegisterForModEvent("ostim_thread_start", "OStimManager")
  RegisterForModEvent("ostim_thread_scenechanged", "OStimManager")
  RegisterForModEvent("ostim_thread_speedchanged", "OStimManager")
  RegisterForModEvent("ostim_actor_orgasm", "OStimManager")
  RegisterForModEvent("ostim_thread_end", "OStimManager")
```
OStim detection: `if Game.GetModByName("OStim.esp") != 255` -> `bHasOstim = True`; runtime selector `bool function useOstim()` = `bHasOstim && minai_UseOStim.GetValue() == 1.0`.
LOCAL cross-check: all five event names exist in `...\OStim Standalone...\SKSE\Plugins\OStim\list of mod events.txt` lines 58, 69, 82, 95, 141.

### 2.2 The single handler (`PSC/minai_Sex.psc`, ~L443-477, verbatim)
```papyrus
Event OStimManager(string eventName, string strArg, float numArg, Form sender)
  int ostimTid = numArg as int
  ; ostim thread with index 0 is reserved for player scenes
  bool playerInvolved = sexUtil.isPlayerInvolved(ostimTid, ostimType)
  bool isRunning = OThread.IsRunning(ostimTid)
  string sceneId = strArg
  ...
  if (eventName == "ostim_thread_start")
    ostim.resetPrevSpeed(ostimTid)
    if isRunning
      onSexStart(ostimTid, ostimType)
  elseif (eventName == "ostim_thread_scenechanged")
    ostim.resetPrevSpeed(ostimTid)
    if isRunning
      ; we don't want to catch transition scenes ...
      if(OMetadata.isTransition(sceneId))
        return
      endif
      onSceneChange(ostimTid, ostimType)
  elseif (eventName == "ostim_thread_speedchanged")
    int newSpeed = strArg as int
    int prevSpeed = ostim.getPrevSpeed(ostimTid)
    if(!prevSpeed || prevSpeed == -1 || prevSpeed == newSpeed)
      ostim.setPrevSpeed(ostimTid, newSpeed)
      return
    endif
    Actor[] actors = OThread.GetActors(ostimTid)
    bool increase = newSpeed > prevSpeed
    if isRunning
      sexTalkSpeedChange(sexUtil.GetWeightedRandomActorToSpeak(actors, bHasOstim), playerInvolved, ostimType, increase)
      ostim.setPrevSpeed(ostimTid, newSpeed)
  elseif (eventName == "ostim_actor_orgasm")
    Actor OrgasmedActor = sender as Actor
    if(OrgasmedActor != PlayerRef)
      sexTalkClimax(OrgasmedActor, playerInvolved, ostimType)
    endif
  elseif (eventName == "ostim_thread_end")
    ostim.removePrevSpeed(ostimTid)
    onSexEnd(ostimTid, ostimType)
  EndIf
EndEvent
```
Facts encoded there (VERIFIED as MinAI's assumptions): `numArg` = thread id; `strArg` = scene id for scenechanged and the NEW SPEED (as string) for speedchanged; `sender` = the actor for orgasm.
LOCAL cross-check against OStim 7.5.1 `SKSE\Plugins\OStim\list of mod events.txt`: :66 `Event OStimThreadStart(string EventName, string StrArg, float ThreadID, Form Sender)`; :79 `Event OStimThreadSceneChanged(string EventName, string SceneID, float ThreadID, Form Sender)` ("required API version: 7.2d (33)"); :91 `Event OStimThreadSpeedChanged(string EventName, string strArg, float ThreadID, Form Sender)` - doc says it "gets fired when the speed or scene of a thread changes" (API 7.3) and its example reads the speed with `OThread.GetSpeed(ThreadID as int)`; the doc does NOT define `strArg` as the speed, so MinAI's `strArg as int` is UNDOCUMENTED behaviour - do not rely on it; :105 `Event OStimActorOrgasm(string EventName, string SceneID, float ThreadID, Form Sender)` with `Sender as Actor`; :150 `Event OStimThreadEnd(string EventName, string Json, float ThreadID, Form Sender)` (Json thread info, API 7.3.1). The same file also documents `ostim_event` (:135, `Event OStimEvent(int ThreadID, string Type, Form EventActor, Form EventTarget, Form EventPerformer)`) and `ostim_furniturechanged` (:119), which MinAI does not use. Player thread is assumed to be thread id 0 (`PSC/minai_SexUtil.psc` `getPlayerThread`: `if(OThread.isRunning(0)) return 0`).
Speed debounce: a JContainers `JMap` `{threadId: prevSpeed}` (`jThreadsPrevSpeedsMap`, `setPrevSpeed/getPrevSpeed/resetPrevSpeed/removePrevSpeed` in `PSC/minai_SexOstim.psc` ~L260-274). Speed is reset to -1 on start and on every scene change so that default-speed differences between scenes are not reported as a speed change.

### 2.3 Starting a scene (`PSC/minai_SexOstim.psc`, ~L19-34, verbatim)
```papyrus
int function StartOstim(actor[] actors, string tags = "")
  int playerIndex = actors.Find(PlayerRef)
  if playerIndex > -1
    actors = OActorUtil.SelectIndexAndSort(actors, PapyrusUtil.ActorArray(1))
  else
    actors = OActorUtil.Sort(actors, PapyrusUtil.ActorArray(1))
  endif
  int builderID = OThreadBuilder.create(actors)
  string newScene = getSceneByActionsOrTags(actors, tags, true)
  OThreadBuilder.SetStartingAnimation(builderID, newScene)
  int newThreadID = OThreadBuilder.Start(builderID)
  return newThreadID
endfunction
```
Only three `OThreadBuilder` functions are used: `create`, `SetStartingAnimation`, `Start`. No furniture, no `SetDominantActors`, no `NoAutoMode`, no metadata, no undress options. Solo: `OThread.QuickStart(OActorUtil.ToArray(akSpeaker))` (`minai_Sex.Start1pSex`). An experimental direct start by scene id exists in the Mantella legacy path: `OThread.Quickstart(OActorUtil.ToArray(akSpeaker, akTarget), "OARE_HoldingChinKiss")`.
LOCAL cross-check (OStim 7.5.1 sources): `OThreadBuilder.psc:46 int Function Create(Actor[] Actors) Global Native`, `:83 Function SetStartingAnimation(int BuilderID, string Animation) Global Native`, `:216 int Function Start(int BuilderID) Global Native`; `OThread.psc:29 int Function QuickStart(Actor[] Actors, string StartingAnimation = "", ObjectReference FurnitureRef = None) Global Native`; `OActorUtil.psc:142 Sort(Actor[] Actors, Actor[] DominantActors, int PlayerIndex = -1)`, `:152 SelectIndexAndSort(Actor[] Actors, Actor[] DominantActors)`.

Scene selection (`getSceneByActionsOrTags`, ~L126-154): maps a coarse tag through `ConvertTagsOstim` then
```papyrus
newScene = OLibrary.GetRandomSceneWithAnyActionCSV(actors, tags)
if(newScene == "")
  newScene = OLibrary.GetRandomSceneWithAnySceneTagCSV(actors, tags)
...
if(newScene == "" && useRandom) newScene = OLibrary.GetRandomScene(actors)
```
`ConvertTagsOstim` mapping table (verbatim keys -> OStim action/tag CSV): `anal->analsex`, `vaginal->vaginalsex`, `oral->deepthroat`, `fingering->vaginalfingering`, `cunnilingus->cunnilingus,lickingvagina,oralfingering`, `breastfeeding->suckingnipple`, `chestcum->cumonchest`, `pussy->rubbingclitoris`, `vampire->vampirebite`, `hugging->cuddling`, `kissing->frenchkissing`. Other tags pass through unchanged (`blowjob, handjob, footjob, boobjob, facial, rimjob, missionary, cowgirl, reversecowgirl, doggystyle, facesitting, "sixtynine,69", "grindingpenis,buttjob", thighjob`).
LOCAL cross-check: `OLibrary.psc:59 GetRandomScene`, `:107 GetRandomSceneWithAnySceneTagCSV(Actor[] Actors, string Tags)`, `:719 GetRandomSceneWithAnyActionCSV(Actor[] Actors, string Types)` exist.

### 2.4 Navigating / speed / stop (`PSC/minai_SexOstim.psc`)
```papyrus
function Navigate(int ThreadID, string newScene)
  OThread.QueueNavigation(ThreadID, newScene, 2.5)
endfunction
```
(commented-out alternates: `OThread.WarpTo(ThreadID, SceneID)`, `OThread.NavigateTo(ThreadID, SceneID)`, and an auto-mode stop/start dance). Transition while a scene is running is refused unless `config.allowSexTransitions`; joining a running scene is refused unless `config.allowActorsToJoinSex`, and "join" is implemented by `OThread.Stop(ThreadID)` + wait loop `while(OThread.isRunning(ThreadID)) Utility.Wait(0.2)` + `StartOstim(currentActors)` (max 5 actors).
Speed: `SpeedUp` = `OThread.SetSpeed(ThreadID, OThread.GetSpeed(ThreadID)+1)` bounded by `OMetadata.GetMaxSpeed(sceneId)`; `SlowDown` bounded at 0. Stop: `OThread.Stop(ActiveOstimThreadID)` guarded by `OThread.IsRunning`. Thread lookup: `OActor.GetSceneID(actor)` (`-1`/negative = none), `OActor.IsInOStim(actor)`.
VERIFIED ABSENCE: no call to any OStim navigation-graph API (no `OMetadata`/`OThread` navigation-option enumeration) in `minai_SexOstim.psc`, `minai_Sex.psc`, `minai_SexUtil.psc`. MinAI never asks OStim "which scenes are reachable from here".

### 2.5 Reporting to the server
Three channels, all via `AIAgentFunctions` wrappers in `PSC/minai_AIFF.psc` (`AILogMessage(msgText,msgType)` -> `AIAgentFunctions.logMessage`; `AILogMessageForActor` -> `logMessageForActor`; `AIRequestMessage` -> `requestMessage`; `AIRequestMessageForActor` -> `requestMessageForActor`; also `setConf`, `setAnimationBusy`, `findAllNearbyAgents`, `getAgentByName`, `removeAgentByName`, `setDrivenByAIA`, `recordSoundEx`, `stopRecording`). LOCAL: `AIAgentFunctions.psc:28 logMessageForActor(String a_msg,String type,String npc)`, `:30 requestMessageForActor(...)`, `:31 setAnimationBusy(int busy,String npc)`.

(a) Thread table sync - message type `updateThreadsDB` (`minai_Sex.UpdateThreadTable(string type, string framework = "ostim", int ThreadID = -1)`, ~L560-604). `type` in `startthread | scenechange | speedchange | end | clean`. Payload is hand-concatenated JSON:
```papyrus
string jsonToSend = "{ \"type\": \""+type+"\", \"framework\": \""+framework+"\", \"threadId\": "+ThreadID+", \"maleActors\": \""+maleActorsString+"\", \"femaleActors\": \""+femaleActorsString+"\", \"victimActors\": \""+victimActorsString+"\", \"scene\": \""+sceneId+"\""
if(fallback != "")
  jsonToSend += ", \"fallback\": \""+fallback+"\""
endif
jsonToSend += "}"
aiff.AILogMessage("command@ExtCmdUpdateThreadsTable@"+ jsonToSend +"@", "updateThreadsDB")
```
`fallback` for OStim (`minai_SexOstim.buildSceneFallbackDescription`, ~L220-230) = `sexUtil.buildSceneString(sceneId, actorString, eventType, sceneTagsString, actionString)` where tags = `OMetadata.GetSceneTags(sceneId)` and actions = `OMetadata.GetActionTypes(sceneId)` joined by ", ". `buildSceneString` output shapes (verbatim fragments): `"<actors> begin sex scene: <sceneId>. Scene can be described with this tags: <tags>. These actions happen in a scene: <actions>"` / `"<actors> changed the scene to <...>"`.

(b) Coarse on/off flag: `SetSexSceneState("on"|"off")` -> `aiff.AILogMessage("sexscene@" + sexState,"setconf")` (comment: "we need it for some native logic of AIFF"). Also `aiff.setAnimationBusy(1, name)` for each NPC participant on scene change and `AIFF.SetAnimationBusy(0, name)` at end; `AIFF.ChillOut()` when player involved (body is effectively a no-op in this fork).

(c) Speech requests - custom request types (see 2.6) via `Main.RequestLLMResponseNPC("", "", speakerName, chatType)` which sends `minAIFF.AIRequestMessageForActor(speaker + "@" + target + "@" + eventLine, type, target)`; i.e. payload `"@<speakerName>@"`, type `sextalk_*`, responding actor = the chosen NPC.
Plain log events: `main.RegisterEvent("<names> started having sex. (<tags>) ", "info_sexscene")` (`RegisterEvent` force-prefixes `info_` and defaults to `info_sexscene`).

Message types used by the sex module (complete list, VERIFIED): `setconf`, `updateThreadsDB`, `funcret`, `info_sexscene`, `sextalk_scenechange`, `sextalk_speedincrease`, `sextalk_speeddecrease`, `sextalk_climax`, `sextalk_climaxchastity`, `sextalk_end`, `sextalk_ambient`, `chatnf_vr_1`, `chatnf_invite`. Other MinAI types: `minai_init`, `registeraction`, `storecontext`, `storetattoodesc`, `minai_storeitem`, `minai_storeitem_batch`, `minai_diary`, `minai_updateprofile`, `minai_narrator_talk`, `minai_dungeon_master`, `minai_combatenddefeat`, `minai_force_rechat`, `minai_clearinventory`, `minai_tntr_*`, `npc_talk`.

### 2.6 Throttling / cooldowns (all VERIFIED)
1. In-scene speech - one GLOBAL timestamp (`minai_Sex.SexTalk`, ~L625-637):
```papyrus
Function SexTalk(actor speaker, string chatType, bool hasPlayer, string framework, bool ignoreSexTalkCooldown = false)
  if !bHasAIFF || !speaker
    return
  EndIf
  bool anyThreadWithPlayer = sexUtil.getPlayerThread(framework) != -1
  if(anyThreadWithPlayer && !hasPlayer && config.prioritizePlayerThread)
    return
  endif
  float currentTime = Utility.GetCurrentRealTime()
  if currentTime - lastSexTalk > config.commentsRate || ignoreSexTalkCooldown
    lastSexTalk = currentTime
    Main.RequestLLMResponseNPC("", "", speakerName, chatType)
  else
    Main.Debug("SexTalk - THROTTLED")
```
   Bypass flags: climax uses `config.forceOrgasmComment`, end uses `config.forcePostSceneComment` (both default true).
2. A second global throttle sits underneath: `minai_MainQuestController.RequestLLMResponseNPC` drops (does not queue) the request unless `currentTime - lastRequestTime > config.requestResponseCooldown` (default 10.0 s). `RequestLLMResponse`/`RequestLLMResponseFromActor` downgrade to a logged event (`RegisterEvent`) when throttled; `RequestLLMResponseNPC` silently drops.
3. Ambient chatter: `minai_AmbientSexTalk.OnSexStart` -> `RegisterForSingleUpdate(config.commentsRate)`; `OnUpdate` picks the player thread (if `prioritizePlayerThread`) or a random thread, picks `MinaiUtil.getRandomActor(actors)`, calls `sex.sexTalkAmbient(...)`, re-arms only while threads exist. (BUG visible in source: SexLab branch assigns `jThreadsArray = jOstimThreadsArray`.)
4. Speaker choice: `minai_SexUtil.GetWeightedRandomActorToSpeak(actor[] actors, bool bHasOstim = false)` - never the player, skips `OActor.IsMuted(currActor)`, gender-weighted by `config.genderWeightComments` (default 50). The end-of-scene speaker is chosen at START and stored in `actorToSayOnEndMap` because actors may be gone at `ostim_thread_end`.
5. Transitions are ignored (`OMetadata.isTransition(sceneId)`), speed events debounced (2.2).
6. Server side: radiant / `minai_force_rechat` requests are killed during scenes (`PLG/customintegrations.php` ~L404-495: `if (IsSexActive()) { ... $MUST_DIE = true; }`), and `context_pre.php` disables `BORED_EVENT_SERVERSIDE` / `RANDOM_NARATION`.
7. Action spam: exponential backoff per action (section 3.2).

Config defaults (`PSC/minai_Config.psc`, VERIFIED): `commentsRate = 15.0` ("Comments rate"), `requestResponseCooldown = 10.0`, `maxThreads = 5.0` (help text: "Ostim usually crashes at 6+"), `genderWeightComments = 50.0`, `prioritizePlayerThread = true`, `enableAmbientComments = true`, `forceOrgasmComment = true`, `forcePostSceneComment = true`, `confirmSex = False`, `allowSexTransitions = False`, `allowActorsToJoinSex = False`, `enableAISex` (property init `False`, `enableAISexDefault = true`; MCM label "Enable NPC -> NPC Sex"), `arousalForSex = 0.0`, `arousalForHarass = 0.0`, `trackVictimAwareness = True`, `highFrequencyUpdateInterval = 5.0`, `preserveQueue = True`.

---

## 3. Server side

### 3.1 Custom message routing
`PLG/globals.php` (~L82-92, verbatim):
```php
$GLOBALS["external_fast_commands"] = [
    "minai_init", "storecontext", "registeraction", "updatethreadsdb", "storetattoodesc",
    "minai_storeitem", "minai_storeitem_batch", "minai_diary", "minai_updateprofile"
];
```
LOCAL HerikaServer main.php:233-234 merges this into `$fast_commands` (`if (isset($GLOBALS["external_fast_commands"])) { $fast_commands = array_merge(...) }`) - so the mechanism exists in our CHIM 3.x server.
Router: `function ProcessIntegrations()` in `PLG/customintegrations.php` (required from `PLG/prompts.php`). It lower-cases the type into `$s_type`, handles the branch, sets `$MUST_DIE=true`, then at the end: semaphore cleanup + `die('X-CUSTOM-CLOSE');`. Branch for scenes: `} else if ($s_type == "updatethreadsdb") { updateThreadsDB(); $MUST_DIE=true;`.
`storecontext` parsing: `$vars=explode("@",$GLOBALS["gameRequest"][3]);` -> `modName, eventKey, eventValue, npcName, ttl` -> `$db->upsertRowOnConflict('custom_context', [...,'expiresAt' => time() + $ttl,...], 'modname, eventkey')`.
`registeraction` parsing: `actionName@actionPrompt@enabled@ttl@targetDescription@targetEnum@npcName` -> `$db->delete("custom_actions", ...)` + `$db->insert('custom_actions', ...)`.

Actor state store (no custom handler needed): Papyrus `SetActorVariable(Actor akActor, string variable, string value)` -> `AILogMessage("_minai_" + actorName + "//" + variable + "@" + value, "setconf")`. LOCAL HerikaServer processor/comm.php:1281-1329: `setconf` does `$vars = explode("@", $gameRequest[3]);` then `$db->upsertRow('conf_opts', ['id' => $vars[0], 'value' => $vars[1]], "id='{$confOptId}'")` and `$MUST_END = true`. Server reads with `GetActorValue($name, $key, ...)` = `SELECT * FROM conf_opts WHERE (LOWER(id)=LOWER('_minai_{$e_name}//{$e_key}'))` and `IsEnabled($name,$key)` (value === 'true'), both with a per-request cache (`BuildActorValueCache`). Mod presence: `SetModAvailable("Ostim", bHasOstim)` -> key `_minai_<player>//mod_Ostim`; server `IsModEnabled($mod)` = `IsEnabled($GLOBALS['PLAYER_NAME'], "mod_{$mod}")`.

### 3.2 How sex actions are registered
Game side (`minai_Sex.Maintenance`, ~L109-144) - one call per command:
```papyrus
aiff.RegisterAction("ExtCmdStartVaginal", "StartVaginal", "Sex Position", "Sex", 1, 5, 2, 5, 300, (bHasSexlab || bHasOstim))
aiff.RegisterAction("ExtCmdSpeedUpSex", "SpeedUpSex", "Sex Intensity", "Sex", 1, 3, 1, 1, 300, (bHasOstim))
```
Signature (`PSC/minai_AIFF.psc` ~L1022):
```papyrus
Function RegisterAction(string actionName, string mcmName, string mcmDesc, string mcmPage, int enabled, float interval, float exponent, int maxInterval, float decayWindow, bool hasMod, bool forceUpdate=false)
```
Stored as a JContainers JMap per action in `actionRegistry`. On every dispatched command `ExecuteAction(actionName)` computes `nextExecution = lastExecuted + (interval * Math.pow(exponent, currentInterval))`, increments `currentInterval` up to `maxInterval`, and pushes the availability to the server: `AILogMessage("_minai_ACTION//" + actionName + "@" + isEnabled, "setconf")`. `ResetActionBackoff` (called periodically via `UpdateActions`) re-enables after cooldown and resets after `decayWindow` s idle. For sex commands that is 5 s base, x2 per use, up to 2^5, reset after 300 s.

Server side (`PLG/functions/action_builder.php`, `directRegisterAction`, verbatim core):
```php
function directRegisterAction($actionName, $displayName, $description, $enableCondition, $genderDescriptions = [], $required = [], $customDescription = null) {
    if ($enableCondition === false) { return; }
    ...
    $GLOBALS["F_NAMES"][$actionName] = $displayName;
    $GLOBALS["F_TRANSLATIONS"][$actionName] = $finalDescription;
    $functionParams = [ "type" => "object", "properties" => [ "target" => [
            "type" => "string", "description" => "Target NPC, Actor, or being", "enum" => $nearby ] ] ];
    $GLOBALS["FUNCTIONS"][] = [ "name" => $displayName, "description" => $finalDescription, "parameters" => $functionParams, ];
    $GLOBALS["FUNCRET"][$actionName] = $GLOBALS["GenericFuncRet"];
    RegisterAction($actionName);
}
```
`$nearby` = `$GLOBALS["nearby"]` = `explode(",", GetActorValue("PLAYER", "nearbyActors", true))` (`PLG/utils/init_common_variables.php`). A fluent `ActionBuilder` class also exists (`create`, `withDescription`, `withParameter($name,$type,$description,$enum=[],$required=false)`, `isNSFW`, `withEnableCondition`, `withGenderDescription`, `withReturnFunction`, `register`, `startBatchRegistration`, `completeBatchRegistration`), helper `registerMinAIAction($actionName, $displayName = null)`.
`RegisterAction($actionName)` (`PLG/util.php` ~L558): adds to `$GLOBALS["ENABLED_FUNCTIONS"][]` only if `IsActionEnabled($actionName)`; `IsActionEnabled` preloads `SELECT * FROM conf_opts WHERE (id ILIKE '_minai_ACTION//%') AND (LOWER(value)='true')`.
Third-party actions: `custom_actions` table rows are registered by `RegisterThirdPartyActions()` (called in `functions.php`; definition not audited).

### 3.3 Command names (`ExtCmd*`) in the sex module (VERIFIED, both sides unless noted)
```
ExtCmdRemoveClothes  ExtCmdPutOnClothes  ExtCmdMasturbate  ExtCmdStartThreesome  ExtCmdStartOrgy  ExtCmdEndSex
ExtCmdStartBlowjob  ExtCmdStartAnal  ExtCmdStartVaginal  ExtCmdStartHandjob  ExtCmdStartFootjob  ExtCmdStartBoobjob
ExtCmdStartCunnilingus  ExtCmdStartFacial  ExtCmdStartCumonchest  ExtCmdStartRubbingclitoris  ExtCmdStartDeepthroat
ExtCmdStartRimjob  ExtCmdStartFingering  ExtCmdStartMissionarySex  ExtCmdStartCowgirlSex  ExtCmdStartReverseCowgirl
ExtCmdStartDoggystyle  ExtCmdStartFacesitting  ExtCmdStart69Sex  ExtCmdStartGrindingSex  ExtCmdStartThighjob
ExtCmdStartCuddleSex  ExtCmdStartKissingSex  ExtCmdSpeedUpSex  ExtCmdSlowDownSex
ExtCmdFollow  ExtCmdStopFollowing            (registered in the sex script, page "General")
ExtCmdStartAggressive                         (PHP side only: functions/sex.php)
ExtCmdUpdateThreadsTable                      (not an LLM action: literal inside the updateThreadsDB payload)
```
Third-party convention (`RAW/ModdersGuide.md`): action `testaction` arrives as command `ExtCmdtestaction`.

### 3.4 Gating (VERIFIED)
Module loading (`PLG/functions.php` ~L99-129) via `$GLOBALS["function_eligibility_cache"]`:
```php
"in_no_actions_faction" => IsInFaction($GLOBALS["HERIKA_NAME"], "NoActionsFaction"),
"in_no_nsfw_faction"    => IsInFaction($GLOBALS["HERIKA_NAME"], "NoNSFWActionsFaction"),
"in_no_sex_faction"     => IsInFaction($GLOBALS["HERIKA_NAME"], "NoSexActionsFaction"),
"enable_sex"            => ShouldEnableSexFunctions($GLOBALS["HERIKA_NAME"]),
"enable_pre_sex"        => ShouldEnablePreSexFunctions($GLOBALS["HERIKA_NAME"]),
"enable_harass"         => ShouldEnableHarassFunctions($GLOBALS["HERIKA_NAME"]),
"use_devious_narrator"  => ShouldUseDeviousNarrator()
```
`ShouldEnableSexFunctions($name)` (`PLG/util.php` ~L449): false unless `IsModEnabled("Sexlab") || IsModEnabled("Ostim")`; false unless `IsEnabled("PLAYER", "enableAISex")`; `GetActorArousal($name) >= GetMinArousalForSex()`; not `IsEnabled($name, "inCombat")`; if already `IsActorInSexScene($name)` then requires `AreSexTransitionsAllowed($GLOBALS["PLAYER_NAME"])`. `GetActorArousal` returns 100 when no arousal value exists; `GetMinArousalForSex` reads player key `arousalForSex` (default 0) -> by default the arousal gate is always open.
`functions/sex.php` sub-gates: `$pre_sexEnabled` (clothes), `$sexEnabled`, `$activeSexEnabled = $sexEnabled && IsSexActive()`, `$femaleActionsEnabled`, `$maleActionsEnabled` (from `$GLOBALS["target_gender"]`/`$GLOBALS["herika_gender"]`), `$ostimEnabled = $activeSexEnabled && IsModEnabled("Ostim")` (speed commands). Global kill: `if ($GLOBALS["disable_nsfw"]) return;`.
Child protection: server `PLG/utils/init_common_variables.php` - `if (IsChildActor($GLOBALS['HERIKA_NAME']) || IsChildActor($GLOBALS["target"])) { $GLOBALS["disable_nsfw"] = true; }` with `IsChildActor($name)` = `str_contains(GetActorValue($name, "Race"), "child") || IsEnabled($name, "isChild")`. Papyrus `minai_Sex.CommandDispatcher`: `if (akTarget.IsChild()) ... return` - NOTE it checks only `akTarget`, not `akSpeaker`.
Player consent: optional message box `minai_ConfirmSexMsg.Show()` when `config.confirmSex && player in scene` (default OFF). Physical preconditions: `sexUtil.CanAnimate` = not None, not `IsOnMount()`, (SexLab: not already active).
NOT FOUND: any relationship-rank / affinity / consent-of-NPC gate for sex actions (looked in `functions/sex.php`, `util.php` `ShouldEnable*`, `functions.php`). Victim/aggressor handling exists instead (`victim_actors` column, `trackVictimAwareness`, `$GLOBALS["NPC_react_to_non_consensual_acts"]`).
Command purging while in a scene (`PLG/functions.php` ~L147-201): with `$inScene = IsSexActiveSpeaker();` it removes from `$GLOBALS["ENABLED_FUNCTIONS"]`: `Attack, ExchangeItems, Brawl, AttackHunt, Hunt, Fight, OpenInventory, OpenInventory2, ExtCmdStartBathing, StartBathing, LetsRelax, GoToSleep, TravelTo, LeadTheWayTo, IncreaseWalkSpeed, DecreaseWalkSpeed, FollowPlayer, ReturnBackHome, TakeASeat, ComeCloser` (+ anything in `$GLOBALS["commands_to_purge"]`).

### 3.5 Scene context injection and scene description
Tables (`PLG/importDataToDB.php`, `PLG/updateThreadsDB.php`, `PLG/utils/sex_utils.php`):
```sql
CREATE TABLE IF NOT EXISTS minai_scenes_descriptions (
    ostim_id character varying(256), sexlab_id character varying(256),
    created_at timestamp ..., updated_at timestamp ..., description text )
CREATE TABLE IF NOT EXISTS minai_x_personalities ( id character varying(256) PRIMARY KEY, x_personality JSONB, created_at ..., updated_at ... )
-- minai_threads columns (from insert/update code): thread_id, curr_scene_id, prev_scene_id, female_actors, male_actors, victim_actors, framework, fallback
```
CSV header for description imports: `"ostim_id","sexlab_id","description"`; 22 CSVs incl. `oare_10_24_2024.csv` (~550 rows, ids like `OARE_AceLyingFingering`, `OARE_ActionMaleKiss`, `OARE_GoBackStandingHandHolding`), `ostim_10_15_2024.csv`, `oareHalloween_...`, `oa3pp_...`, `billyy_...` etc. `RAW/nsfw.md` states "3467 ostim/sexlab scene descriptions (as of 10/24/2024)". Placeholders `{actor0}`, `{actor1}`... are replaced in framework actor order (`replaceActorsNamesInSceneDesc`); `RAW/nsfw_Scenes.md`: OStim order is `[male0, male1, female0, female1]`, SexLab is females first. Descriptions were LLM-generated from structured prompts; a web UI (`scene_descriptions.html`, `api/scenes.php`, `api/generateDescription.php` with POST field `descriptionPrompt`, connector `$GLOBALS["CONNECTORS_DIARY"]`) lets users author more.
Flow on `updateThreadsDB` (`function updateThreadsDB()`): parse `$param = explode("@", $gameRequest[3])[2]` -> `json_decode`; replace "The Narrator" with player name; post-process `fallback` through `scene_fallback_process.php` (strips ~30 pack/author brand tokens with `str_ireplace`, expands shorthand such as `MF`, `3P`, `DP` via a `strtr` dictionary); `startthread`/`scenechange` -> update (`prev_scene_id = curr`, `curr_scene_id = '$scene'`, `fallback`) or `upsertRowOnConflict('minai_threads', $insertData, 'thread_id')`; then
```php
$scene = getScene("", $threadId);
$sceneDesc = $scene["description"] ?? "";
addSexEventsToEventLog($sceneDesc, $threadId);
// = logEvent(['info_sexscenechange', $gameRequest[1], $gameRequest[2], "<SEX_SCENARIO>#SEX_SCENARIO #ID_$threadId: " . $sceneDesc . "</SEX_SCENARIO>"]);
```
`end` -> `$db->delete("minai_threads", "thread_id = $threadId")`; `clean` -> `DELETE FROM minai_threads` (sent from Papyrus on every game load).
`getScene($actor, $threadId = null)` (`PLG/utils/sex_utils.php`): by thread id, or by regex match of the actor name inside the comma-separated `male_actors/female_actors/victim_actors` columns (`~* '(,|^)\\s*$actor\\s*(,|$)'`) or a LIKE on `fallback`. Description precedence: DB description by `LOWER(ostim_id) = LOWER('$currSceneId')` -> else generic sentence + participants list wrapped in `<scene_participants_having_sex>` + `fallback`. Returns the row plus `actors`, `description`.
Context usage: (1) the `info_sexscenechange` event-log line becomes part of dialogue history; `PLG/context.php` keeps only the LAST `#SEX_SCENARIO` line per `#ID_<thread>` (and drops it entirely once `getScene("", $threadId)` is null), last 1 `#SEX_INFO`, last 3 `#PHYSICS_INFO`. (2) `PLG/sexPrompts.php` fills `$GLOBALS["SEX_SCENE_CONTEXT"]` and the `sextalk_*` prompt cues. (3) `BuildCharacterStateContext` adds "<name> having sex now." when `IsInScene($character)`; `BuildArousalContext` adds `<arousal_status>` (stage = arousal/10). (4) `addXPersonality` injects orientation / preferences JSON from `minai_x_personalities` into `$GLOBALS["HERIKA_SEX_PERSONALITY"]`. (5) `dialogue_prompt.php` swaps `$GLOBALS["TEMPLATE_DIALOG"]` to scene variants.
Context-builder framework (reusable idea): `ContextBuilderRegistry::getInstance()->register($id, ['section'=>..., 'header'=>..., 'description'=>..., 'priority'=>100, 'is_nsfw'=>false, 'enabled'=>true, 'builder_callback'=>'Fn'])`, builders receive `$params['herika_name']`, `$params['player_name']`; sections: character, status, interaction, environment, misc.

### 3.6 In-scene dialogue prompts
`PLG/sexPrompts.php` defines for the 7 keys `sextalk_climaxchastity, sextalk_climax, sextalk_scenechange, sextalk_speedincrease, sextalk_speeddecrease, sextalk_end, sextalk_ambient` entries of shape `["cue" => [ ...strings... ]]` in `$GLOBALS["PROMPTS"]`. If the speaker is in a scene: `getXPersonality`, `determineSpeakStyle($HerikaName, $scene, $jsonXPersonality)` (returns `style` + `role`), then one of 12 setters (`setDirtyTalkPrompts`, `setSweeTalkPrompts`, `setSensualWhisperingPrompts`, `setDominantTalkPrompts`, `setSubmissiveTalkPrompts`, `setVictimTalkPrompts`, `setAggressorTalkPrompts`, `setTeasingTalkPrompts`, `setEroticStorytellingPrompts`, `setBreathlessGaspsPrompts`, `setSultrySeductionPrompts`, `setPlayfulBanterPrompts`), each `function setXPrompts($currentName)` with gender-conditional `array_push` of cues. Frequency is NOT controlled here; it is entirely the Papyrus throttle (2.6) plus the radiant kill switch.

---

## 4. CHIM command event + result reporting

- Current (fork + `vrelk/MinAI`): `PSC/minai_AIFF.psc` `Maintenance`:
```papyrus
  RegisterForModEvent("CHIM_CommandReceived", "CommandDispatcher")
  RegisterForModEvent("CHIM_TextReceived", "OnTextReceived")
  RegisterForModEvent("CHIM_NPC", "OnAIActorChange")
```
  Handler signatures: `Event CommandDispatcher(String speakerName, String command, String parameter)`, `Event OnTextReceived(String speakerName, String sayLine)`, `Event OnAIActorChange(string npcName, string actionName)`. `minai_Followers.psc` (separate quest) registers `CHIM_CommandReceived` itself. `minai_Sex`, `minai_ItemCommands` etc. define their own `Event CommandDispatcher(...)` without registering; INFERENCE: because they are attached to the same Quest form as `minai_AIFF`, the form-level registration delivers the event to every attached script with that handler name.
- Older snapshot `https://raw.githubusercontent.com/programotter/MinAI/main/Scripts/Source/minai_AIFF.psc` (Sept 2024): `RegisterForModEvent("AIFF_CommandReceived", "CommandDispatcher")`.
- `SPG_CommandReceived`: NOT FOUND in any MinAI file opened, NOT FOUND in LOCAL `F:\Modlists\LoreRim\mods\CHIM\Source\Scripts` (grep `SPG_` = 0 hits). LOCAL AIAgentAIMind.psc:1395-1405 `SendExternalEvent(String npcname,String command,String parm)` creates `"CHIM_CommandReceived"` with three PushString; :1384 `"CHIM_CommandReceivedInternal"`; :1409 `"CHIM_NPC"`; :1422 `"CHIM_TextReceived"`.
- AIAgent presence/version check used by MinAI: `Game.GetModByName("AIAgent.esp") != 255`; `AIAssisted = Game.GetFormFromFile(0x217a8,"AIAgent.esp") as Keyword` (fatal if missing); `NullVoiceType = Game.GetFormFromFile(0x01D70E, "AIAgent.esp") as VoiceType`.
- Dispatch flow: `minai_AIFF.CommandDispatcher` -> `ExecuteAction(command)` (backoff bookkeeping + `setconf` push) -> `SetContext(akActor)`; in parallel `minai_Sex.CommandDispatcher` resolves `akSpeaker = aiff.AIGetAgentByName(speakerName)`, `akTarget = aiff.AIGetAgentByName(parameter)` (falls back to `PlayerRef` when the name does not resolve), child check, then `StartSexOrSwitchTo(akSpeaker, akTarget, "<tag>")`.
- funcret format (VERIFIED, `minai_Sex.CommandDispatcher`):
```papyrus
aiff.AILogMessageForActor("command@ExtCmdRemoveClothes@@"+speakerName+" removes clothes and armor","funcret",speakerName)
aiff.AILogMessageForActor("command@ExtCmdPutOnClothes@@"+speakerName+" puts on clothes and armor","funcret",speakerName)
```
  i.e. `command@<CommandName>@<parameter (empty here)>@<result text>`, type `funcret`, sent with the LOG variant (no LLM follow-up requested). Sex-start / end / speed commands send NO funcret; their feedback is the `info_sexscene` event + the later `info_sexscenechange` line + a `sextalk_*` request. Server return hook: `$GLOBALS["FUNCRET"][$actionName] = $GLOBALS["GenericFuncRet"]` where
```php
$GLOBALS["GenericFuncRet"] = function($gameRequest) {
    $GLOBALS["FORCE_MAX_TOKENS"] = 512;
    if (stripos($gameRequest[3], "error") !== false) {
        return ["argName" => "target", "request" => "{$GLOBALS["HERIKA_NAME"]} says sorry about unable to complete the task. {$GLOBALS["TEMPLATE_DIALOG"]}"];
    } else {
        return ["argName" => "target"];
    }
};
```
- Public mod-event API for other mods (`RAW/ModdersGuide.md`, handlers in `minai_MainQuestController.psc`): `MinAI_RegisterEvent(eventLine, eventType)`, `MinAI_RequestResponse(eventLine, eventType, targetName)`, `MinAI_RequestResponseDialogue(speakerName, eventLine, targetName)`, `MinAI_SetContext(modName, eventKey, eventValue, ttl)`, `MinAI_SetContextNPC(modName, eventKey, eventValue, npcName, ttl)`, `MinAI_RegisterAction(actionName, actionPrompt, mcmDescription, targetDescription, targetEnum, enabled, cooldown, ttl)`, `MinAI_RegisterActionNPC(..., npcName, ...)`.

---

## 5. Targeted CHIM versions, 3.x notes, pitfalls

Version claims (`PLG/version_changelog.txt`, verbatim): "MinAI server plugin version 2.1.3-dev8a - Compatibility with CHIM v3.1.x. Tested with CHIM v3.1.0, 3.1.1, 3.1.2, 3.1.3. Not compatible with previous CHIM versions." (release page `https://github.com/id1001-gthb/MinAI_1001/releases` adds 3.1.4). dev7g: "Compatibility with CHIM v2.5.x ... Should be compatible with CHIM v1.3.5.x, v2.1.x, v2.2.2, v2.3.x., v2.4.x"; "Requires a new version of MinAI mod, download and install MinAI v2.3.1g.zip". Papyrus `minai_MainQuestController.GetVersion()` returns `200`. NOT FOUND: any statement about CHIM/AIAgent 3.2.x or 3.3.x, or DwemerDistro 3.2.0. (Release-page years printed by the fetch tool looked wrong; API says last push 2026-07-15.)
Original upstream: README in forks has no CHIM version pin; search-engine snippet of the deleted upstream README says the project "is no longer maintained" and points to SkyrimNet.

Pitfalls to avoid (each VERIFIED in source unless marked):
1. CORE PATCHING. On `minai_init`, if CHIM or MinAI version changed, `customintegrations.php` runs `utils/xtra/m_patch_all.sh` -> `python3 m_patch.py main.php main.php.src main.php.rpl`, which comments out this HerikaServer core line: `$GLOBALS['action_prompts']=[];` (src file comment: "MinAI prompts are breaking rechat actor adressing"). LOCAL main.php:2148-2149 has the same reset (comment now reads "Legacy plugin prompts can break rechat actor addressing"), i.e. core and plugin fight over a global. Never patch core; never depend on globals that core resets.
2. SYSTEM-PROMPT TAKEOVER. `context.php` rebuilds `$GLOBALS['head']` and `UpdateSystemPrompt()` rewrites `$GLOBALS["head"][0]`, zeroes `$GLOBALS["COMMAND_PROMPT"]`; `json_response_custom.php` rewrites `responseTemplate`. This is why every CHIM release breaks MinAI ("Not compatible with previous CHIM versions", "reset configuration to defaults twice"). The glue should only APPEND context, never rebuild.
3. Hard-coded absolute paths (`/var/www/html/HerikaServer/lib/data_functions.php`, `.../ext/minai_plugin/scene_fallback_process.php`) and shell scripts that delete/trim server logs (`m_init.sh`).
4. Hand-built JSON in Papyrus with no escaping (`jsonToSend`): an actor name or scene id containing `"` or `@` corrupts the payload (CHIM splits payloads on `@`; the server does `explode("@", ...)[2]`).
5. Actor lists stored as comma-joined display names and matched by regex/LIKE; `getScene` even UPDATEs rows while reading. Name collisions/renames (real-names mods) break it. Prefer FormIDs / CHIM agent names in normalised rows.
6. Player thread assumed to be OStim thread 0.
7. Papyrus child check covers only the target; NPC-to-NPC and non-consensual scenes are first-class. Defaults are permissive (`arousalForSex = 0`, `confirmSex = False`).
8. ~35 near-identical functions in the LLM tool list (token cost, mis-selection); all share the same "target" enum. The coarse tag -> random scene mapping means the LLM cannot pick a specific scene and descriptions may not match what plays.
9. `RequestLLMResponseNPC` silently DROPS throttled requests; one global cooldown shared by all threads/events means a speed-change comment can starve a climax comment unless the force flags are on.
10. Joining a scene = stop + restart (visible reset); `maxThreads` note "Ostim usually crashes at 6+".
11. Ships overrides of other mods' scripts (`MantellaConversation`, `RealNamesChange`, `Dirty_*`, `Baka*`) - conflict risk in a Wabbajack list.
12. FAQ (`RAW/FAQ.md`): setup loop / empty action registry = missing Papyrus Tweaks NG etc.; "NSFW actions are only enabled if you have either Sexlab or ostim installed"; most behaviour complaints are model-dependent. README requirement list: Papyrus Tweaks NG, JContainers SE, powerofthree's Papyrus Extender, SPID.
13. Ambient-talk SexLab bug (wrong array variable) and `skipSexTalk` statement without assignment in `onSceneChange` - evidence that the code is not a safe copy source even technically.

---

## 6. License and what is safe to reuse

- VERIFIED: no license. GitHub API `license: null`/`None` for `programotter/MinAI`, `id1001-gthb/MinAI_1001` and every fork listed by `https://api.github.com/repos/programotter/MinAI/forks`; recursive tree listings contain no `LICENSE`/`COPYING`; README/FAQ/ModdersGuide contain no permissions statement. Default copyright applies -> no right to copy, modify or redistribute code, prompt texts, the ESP, or the scene-description CSV/JSON data. The upstream repo being deleted does not change that.
- Safe to reuse as IDEAS (patterns, not expression):
  1. One Papyrus handler for the five OStim thread events; `numArg` thread id; skip `OMetadata.IsTransition`; per-thread previous-speed map reset on scene change.
  2. Thread-table mirror on the server with event types start / scenechange / end / clean-on-load; choose the end-of-scene speaker at start.
  3. Two-tier description: curated per-scene text keyed by OStim scene id with `{actorN}` placeholders, falling back to metadata-derived text (tags + action types).
  4. Tagging event-log lines with a marker + thread id and pruning all but the newest per thread at context-build time.
  5. Stock `setconf` as a zero-server-code key/value channel with a namespaced key scheme; per-request cache on the PHP side.
  6. `external_fast_commands` + early terminate for non-LLM custom messages.
  7. Per-action exponential backoff with decay, mirrored to the server as an enabled flag, so the LLM simply stops seeing an action on cooldown.
  8. Purging unrelated actions (travel, inventory, combat) from the tool list while a scene runs; killing radiant/bored/rechat generation during scenes.
  9. Real-time cooldown for event-driven comments with "force" exceptions for climax/end; ambient single-update timer that re-arms only while scenes exist; never pick the player or an OStim-muted actor as speaker.
  10. Optional message-box confirmation before any player-involved start.
  11. Opt-out factions checked server-side (`No...ActionsFaction` naming convention) and a registry/priority model for context builders.
  12. Public ModEvent API so other mods can push context/actions without PHP.

---

## 7. Vanilla dialogue / quest awareness in MinAI

VERIFIED findings:
- `PSC/minai_DialogueDetectionEffect.psc` (ActiveMagicEffect): `OnEffectStart` only logs `"Dialogue Detection: (<name>) entered dialogue with player"`; source comment: "No way to abort dialogue right now, will add expose a native function for this later." `OnEffectFinish` logs "left dialogue". No topic, no quest, no server message.
- `PSC/minai_SceneDetectionEffect.psc`: on start/finish `aiff.SetActorVariable(akTarget, "scene", akTarget.GetCurrentScene())` - only records that an actor is in a vanilla Scene (used to keep AI NPCs from interfering with scripted scenes; INFERENCE on purpose).
- Server: `BuildCurrentTaskContext` (`PLG/contextbuilders/context_modules/core_context.php`, id `current_task`, section `interaction`, priority 25) just formats CHIM core's `DataGetCurrentTask()` output. `location_context_details.php` + changelog "Special location details awareness including puzzle solutions" and "Player titles and achievements exposed in context: Thane, Champion, Divine deeds" are static/flag-based lore hints, not quest-stage driven.
- `minai_Config.preserveQueue` ("CHIM Config - Preserve Dialogue Queue") concerns CHIM's speech queue, not vanilla dialogue.
- NOT FOUND (looked in `minai_MainQuestController.psc`, `minai_AIFF.psc`, both detection effects, `dialogue_prompt.php`, `prompts.php`, `customintegrations.php` branch list, README, ModdersGuide): any enumeration of dialogue topics/TopicInfos, any execution of topic result scripts, `SetStage`/`SetObjectiveDisplayed` driven by the LLM, any quest-stage table, any dialogue-menu suppression.
Conclusion: MinAI offers no prior art for "menuless questing"; the only relevant lesson is its admission that Papyrus alone cannot abort/drive the dialogue menu (argues for an SKSE component or a CHIM-native facility).

---

## Implications for the glue (concrete recommendations)

1. Game-side command intake: `RegisterForModEvent("CHIM_CommandReceived", "<Handler>")` with `Event <Handler>(String speakerName, String command, String parameter)` - confirmed against LOCAL AIAgentAIMind.psc:1397. Re-register on every game load. Ignore `SPG_*` / `AIFF_*` names. Resolve actors with `AIAgentFunctions.getAgentByName(name)`; do NOT silently fall back to the player when the target name fails to resolve (MinAI does) - fail closed and send an error funcret.
2. Result reporting: use `command@<Cmd>@<param>@<text>` with type `funcret`. Unlike MinAI, send a funcret for EVERY outcome of the start-intimacy action (started / refused by gate X / no scene found) so the LLM never narrates a scene that did not start; include the word "error" only if we also register a FUNCRET closure that reacts to it. Decide log vs request variant deliberately (request = NPC speaks about the result).
3. Hard gate in Papyrus, not just in PHP: both actors non-child (`IsChild()` on speaker AND target, plus race/keyword checks), player must be one of the two actors, target not in combat / not on mount / not already `OActor.IsInOStim`, consent state from our own relationship data, and a default-ON confirmation message box. Keep server-side gating too (do not expose the action at all when gates fail), mirroring via `setconf`.
4. Use ONE `StartIntimacy`-style action (+ at most `Navigate`, `ChangeSpeed`, `EndScene`) with enum parameters, instead of MinAI's 35 functions. Build the navigation enum from OStim's real navigation options for the current node (MinAI never did this) so every option the LLM sees is actually reachable; navigate with `OThread.NavigateTo` / `OThread.QueueNavigation(ThreadID, SceneID, Duration)` (LOCAL OThread.psc:96, :109).
5. Scene start: `OThreadBuilder.Create` -> optional `SetStartingAnimation` -> `Start` is sufficient and confirmed present in OStim 7.5.1; sort with `OActorUtil.SelectIndexAndSort` when the player participates. Prefer a deterministic intro/idle starting scene from OARE over MinAI's random pick.
6. Scene awareness: copy the PATTERN only - Papyrus handler for the 5 events; skip transitions; debounce speed; send start/scenechange/end/clean. Send machine data (scene id, thread id, speed, actor FormIDs + CHIM names, action types with actor/target indices, tags) and render the description in PHP from OStim metadata; keep an optional override table keyed by scene id. Do NOT ship or import MinAI's CSVs (no license).
7. Transport: (a) small state -> `setconf` with OUR prefix (e.g. `_glue_...`; never `_minai_` to avoid collisions if MinAI is ever installed); (b) structured scene updates -> our own type listed in `$GLOBALS["external_fast_commands"]` (globals.php hook), handled in `preprocessing.php`, then terminate cleanly. Avoid `@` and quotes in payload fields (or hex/base64-encode the JSON), since both CHIM and MinAI-style parsers split on `@`.
8. Context: append a single, replace-in-place "current scene" block (own marker + thread id, prune older ones in `context.php`), never rebuild `$GLOBALS["head"]`, never patch core files, no absolute paths (use `__DIR__`).
9. Speech pacing: per-thread (not global) real-time cooldown, default ~15 s, forced events for climax and end, queue-latest instead of drop, ambient timer only while the player thread runs, never choose the player or a muted actor; disable radiant/bored generation while the player thread is active.
10. While a scene runs, remove travel/inventory/combat actions from `ENABLED_FUNCTIONS` for participants (MinAI's list in 3.4 is a good checklist), but keep our navigation/end actions.
11. Menuless questing gets nothing from MinAI; plan it as original work and expect to need native (SKSE) help for dialogue-menu control and topic-info execution.

## Open questions

1. Was MinLL/MinAI deleted or made private, and did upstream ever carry a license or a final changelog? (404 today; archive.org not reachable with the available tools.)
2. Exact conditions under which AIAgent 3.3.2 raises `CHIM_CommandReceived` (all unknown commands vs only `ExtCmd*`), and whether the DLL or Papyrus calls `SendExternalEvent` (LOCAL grep found only the definition at AIAgentAIMind.psc:1395; callers are presumably native) - to be settled by the CHIM plumbing research.
3. Does CHIM 3.3.2 still honour `$GLOBALS["FUNCRET"][<cmd>]` closures and `F_NAMES/F_TRANSLATIONS/FUNCTIONS/ENABLED_FUNCTIONS` exactly as MinAI uses them (MinAI only claims 3.1.x)? Needs verification against LOCAL `functions/functions.php`.
4. Does CHIM's native `sexscene@on|off` `setconf` key still have meaning in AIAgent 3.3.2 / HerikaServer 3.x (MinAI comment: "native logic of AIFF")?
5. `prerequest.php`, `command_prompt_custom.php`, `contextbuilders.php`, `RegisterThirdPartyActions()`, `determineSpeakStyle`, `IsExplicitScene`, `ShouldEnableHarassFunctions` were not dumped verbatim (tool summarised/refused); not needed for the glue but unverified in detail.
6. Whether form-level `RegisterForModEvent` really fans out to all scripts on the quest (MinAI relies on it) - trivial to test in-game; safer to register in each script.
7. RESOLVED IN PART: OStim's own `list of mod events.txt` confirms thread id in `numArg`, scene id in `strArg` for scenechanged/orgasm, actor in `Sender` for orgasm, but does NOT document `strArg` as the speed for `ostim_thread_speedchanged` (MinAI assumes it). The glue should call `OThread.GetSpeed(ThreadID)` inside the handler. Remaining question: does `ostim_thread_speedchanged` also fire on every scene change in 7.5.1 (doc text says "speed or scene")? If yes, the handler must de-duplicate against the scenechanged event (MinAI's prev-speed reset trick addresses exactly this).
