# HerikaServer request lifecycle, plugin hooks and the AI action system

Phase 0 research report. READ-ONLY investigation; nothing under F:\Modlists, F:\DwemerDistro or the WSL distro was modified (DB access was SELECT / `\d` only).

Path shorthand used for citations: `HS/` = `/var/www/html/HerikaServer/` inside WSL distro `DwemerAI4Skyrim3`
(Windows: `\\wsl.localhost\DwemerAI4Skyrim3\var\www\html\HerikaServer\`). Citations are `HS/<file>:<line>`.
Installed server build: `HS/.version.txt` = `2026090214`, `HS/.version_number.txt` = `3.3.2`. PHP 8.2.28, Apache mpm_prefork, PostgreSQL db `dwemer`.

Every identifier below was copied from an opened source. Statements marked **[INFERENCE]** are reasoning on top of verified code, not something a source states directly.

---

## Executive summary (10 lines)

1. Every game request is `GET .../comm.php?DATA=<base64 "type|ts|gamets|data|...">&profile=<md5(npc name)>`; `main.php` is one 2949-line top-level script, so its "locals" (`$gameRequest`, `$request`, `$head`, `$contextDataFull`, `$PROMPTS`, `$FUNCTIONS_ARE_ENABLED`, ...) ARE `$GLOBALS[...]`; hook files run inside a function scope and must use `$GLOBALS[...]` (only `$gameRequest` is pre-declared global).
2. Ext hooks that really exist (12): `globals.php`, `preprocessing.php`, `prerequest.php`, `dialogue_prompt.php`, `prompts.php`, `functions.php`, `context_building.php`, `json_response_custom.php`, `context_pre.php`, `context.php`, `prepostrequest.php`, `postrequest.php`. **`comm.php` is NOT a hook** (no include site; `ext/relationship_system/comm.php` is a stale copy of the legacy entry point). `terminate()` = `die()`, so post hooks never run on early-exit paths.
3. The action system is now DB-driven ("action catalog": tables `core_action` + `core_action_custom`, view `combined_core_action`). All 5 supported LLM drivers are JSON-mode; the LLM picks an action by filling the `"action"` field of one JSON object; there is no OpenAI tool-calling path in use.
4. The action list is frozen when `functions/json_response.php` is first loaded (`main.php:2474`). To offer/hide an action per request/per NPC you must act BEFORE that line: (a) catalog `metadata.requirements` (`request_types_any`, `npc_names_any`, `npc_factions_any/all`, `activity`, `hide_in_rechat`, `cooldown_seconds`, ...), (b) conditional code in ext `functions.php`, (c) `unsetFunction($code)` from `prerequest.php`-or-later-but-before-2474 hooks. In-core precedent: `chimQuestEngineApplyActionSuppressionsForTurn()`.
5. Server-side HARD GATE exists: `$GLOBALS["action_post_process_fnct_ex"][]` closures receive the final wire lines and may drop/rewrite them before they are echoed to the game. A second, game-side gate exists: wire channel `confirmcommand` (player confirmation prompt) driven by catalog metadata.
6. Wire line for an action: `<Actor>|<command|confirmcommand|approvedcommand>|<CodeName>@<param-or-JSON>\r\n`; speech: `<Actor>|ScriptQueue|subtitle/expression/listener/animation/phonetic/volume/rechatTarget/utteranceId\r\n`; stream ends with `X-CUSTOM-CLOSE`.
7. `funcret` (`funcret|ts|gamets|command@Code@param@result`) only produces a follow-up LLM reply if the action has a catalog row with `metadata.followup.enabled` + non-empty `followup.prompt`; otherwise the result is just logged as an `infoaction` event and the request terminates. **`$GLOBALS["FUNCRET"]` is dead code** in this build; `$GLOBALS["FUNCSERV"]` and `$GLOBALS["PROMPTS"]["afterfunc"]["cue"][Code]` still work.
8. Any request type not consumed earlier falls into the LLM path, so a plugin can define its own LLM-triggering event simply by adding `$GLOBALS["PROMPTS"]["<type>"]`; types starting with `info` are log-only. Actions are only allowed for `inputtext(_s)`, `ginputtext(_s)`, `narrator_inputtext`, `instruction`, `welcome`, `cheatmode` (+ `rechat` if `RECHAT_ALLOW_ACTIONS`).
9. Connector = profile slot 1-4 (Standard / **Fast** / Powerful / Experimental) chosen globally by `conf_opts.chim_profile_model`; a plugin can swap connector, `FORCE_MAX_TOKENS`, temperature for one request through globals before `call_llm()`; side-calls use `$driver->fast_request($messages, ['MAX_TOKENS'=>N], 'label')`.
10. One SysV semaphore (`MAIN`) serialises every non-"fast" request (one LLM generation at a time, 300 s wait); a new player input aborts in-flight generations. CHIM already ships an **AI quest-progression engine** (`lib/chim_quest_engine.php`, `set_stage` outbox) and its plugin repository lists a third-party OStim/SexLab NSFW plugin (`aiagent_nsfw` "SHARMAT") - both overlap the glue's scope and must be evaluated before building.

---

## 1. Request lifecycle and ext hooks

### 1.1 Entry points (VERIFIED)

| File | What it does | Cite |
|---|---|---|
| `comm.php` | `$FUNCTIONS_ARE_ENABLED=false; require main.php` ("Legacy entry point") | `HS/comm.php:6-8` |
| `stream.php` | same, `$FUNCTIONS_ARE_ENABLED=false` | `HS/stream.php:5-8` |
| `streamv2.php` | "Legacy actions entry point": sets `$FUNCTIONS_ARE_ENABLED=true` when `DMgetCurrentModel()` is one of `openai, openaijson, google_openaijson, web_connector, koboldcppjson, openrouterjson`, else false | `HS/streamv2.php:21-54` |
| `main.php` | directly callable; `if (!isset($FUNCTIONS_ARE_ENABLED)) $FUNCTIONS_ARE_ENABLED=false;` | `HS/main.php:100-102` |
| `gamedata.php` | JSON POST endpoint (equipment, inventory, `activity_status`, `quest_event`, `quest_action_poll`, `quest_action_ack`, ...). **No ext hooks are loaded here.** | `HS/gamedata.php:48-62, 79-175` |
| `csv_import.php` -> `processor/import_files.php` | multipart CSV imports incl. `custom_action_import` | `HS/processor/import_files.php:16-58` |

Evidence on which endpoint the DLL uses: Apache vhost log on this install shows only `GET //HerikaServer/comm.php` (225) and `POST //HerikaServer/gamedata.php` (29) so far (no player speech has happened yet on this fresh DB). Strings inside `AIAgent.dll` show `HTTPManager::sendMsgStream(...)` next to the literals `godmode.php` and `streamv2.php`, and `Conf` next to `/HerikaServer/comm.php`, `AIAgent.ini`, `stream.php`. **[INFERENCE]** streamed LLM requests (player speech etc.) go to `streamv2.php` (=> actions enabled), fire-and-forget events go to `comm.php`. Needs confirmation by the game-side agent (see Open questions).

### 1.2 Request parsing (VERIFIED)

```php
// HS/main.php:90-93
if (strpos($_SERVER["QUERY_STRING"],"&")===false)
    $receivedData = mb_scrub(base64_decode(substr($_SERVER["QUERY_STRING"],5)));
else
    $receivedData = mb_scrub(base64_decode(substr($_SERVER["QUERY_STRING"],5,strpos($_SERVER["QUERY_STRING"],"&")-5)));
// HS/main.php:135-136
$gameRequest = explode("|", $receivedData);
$GLOBALS["gameRequest"] = &$gameRequest;
// HS/main.php:864   // $gameRequest = type of message|localts|gamets|data
```

`$gameRequest` layout:

| idx | meaning | cite |
|---|---|---|
| `[0]` | request type, lower-cased (`main.php:149`, again at `:1616`) | |
| `[1]` | `ts` (plugin local timestamp; used for the "newer user_input" abort check) | `main.php:208`, `lib/data_functions.php:5990` |
| `[2]` | `gamets` (game timestamp) | `main.php:209` |
| `[3]` | data. Player speech = `"<PlayerName>: text"`; funcret = `command@<CodeName>@<param>@<result>` | `processor/comm.php:15-19`, `processor/funcret.php:101`, `processor/request.php:22` |
| `[4]` | optional base64(JSON) "routing snapshot" for player input: keys `people`/`companions`, `present_actors`, `source`=`plugin_player_routing_v2`, `chat_shortcut_routed`, `player_mood`, `player_mood_custom`. For `bored` it is the seed actor name. | `main.php:139`, `lib/chat_helper_functions.php:2966-3011`, `main.php:1019` |

`$_GET["profile"]` = `md5(<npc name>)`; row is looked up with `NpcMaster::getByMD5()` (`main.php:511-512`, `lib/core/npc_master.class.php:481-492`). `md5('The Narrator')` selects the narrator (`main.php:364`).
In every `$gameRequest` element, runs of whitespace are collapsed and `#HERIKA_NPC1#` is replaced by `HERIKA_NAME` (`main.php:856-860`).

### 1.3 Ordered lifecycle of `main.php` (VERIFIED, line numbers are `HS/main.php`)

| # | Lines | Step |
|---|---|---|
| 1 | 13-53 | constants, `chimRuntimeBootstrap()` (general settings, `PLAYER_NAME`, narrator), libs (`data_functions.php` pulls in `lib/prompt_injections.php` at `lib/data_functions.php:16`) |
| 2 | **54** | **HOOK `globals.php`** |
| 3 | 57-64 | core classes (`LLMConnector`, `NpcMaster`, `CoreProfile`, `SemaphoreManager`, ...) - *not yet loaded when `globals.php` runs* |
| 4 | 88-163 | parse request, `$GLOBALS["runid"]`, `$talkedSoFar`, `$alreadysent`, `$overrideParameters=array()`, `$LAST_ROLE="user"`; deprecated `update*` types exit |
| 5 | 166-190 | DB connect; `processor/chim_modes.php` (modes STANDARD/WHISPER/CLOSE/NARRATOR/DIRECTOR/CHEATMODE/AUTOCHAT/INJECTION_LOG/INJECTION_CHAT from `conf_opts.chim_mode`; sets `$GLOBALS["CHIM_EXECUTION_MODE"]`) |
| 6 | **193** | **HOOK `preprocessing.php`** (then `physics_raw` terminates if not renamed, 197-199) |
| 7 | 201-224 | for `inputtext, inputtext_s, ginputtext, ginputtext_s, narrator_inputtext, instruction, init`: insert eventlog row `type='user_input'` (the generation-abort marker) |
| 8 | 227-258 | fast-command list (+ `$GLOBALS["external_fast_commands"]`), **`SemaphoreWait("MAIN", ...)`** for everything else |
| 9 | 276 | `processor/misc.php` (`delete_event`, `biography_import`, `oghma_import` ... terminate) |
| 10 | 283-309 | player respeech (`**` prefix) |
| 11 | 314-772 | narrator init; **profile load**: `NpcMaster::getByMD5`, `CoreProfile::getById`, `LLMRandomizer::getConnectorSlot/getConnectorIdForSlot`, `LLMConnector::getById`, `->setOldGlobals()`, `NpcMaster::setOldGlobalsFromCurrentNpcData()`; sets `$GLOBALS["CHIM_CORE_CURRENT_NPC_DATA"]`, `["CHIM_CORE_CURRENT_PROFILE_DATA"]`, `["CHIM_CORE_CURRENT_CONNECTOR_DATA"]`, `HERIKA_NAME`, `HERIKA_PERS`, `PROMPT_HEAD`, bio globals |
| 12 | 776-803 | empty-input abort; `#` prefix => `cheatmode` (+`FUNCTIONS_ARE_ENABLED=true`) |
| 13 | 817 | `$GLOBALS["active_profile"]=md5($GLOBALS["HERIKA_NAME"])` |
| 14 | 867-1074 | per-type early exits / cooldowns: `diary` cooldown (`DIARY_COOLDOWN`, per NPC, in `conf_opts`), `npcspellcast`, held-item events, **log-only list** (949-985), `playerinfo/newgame`, `bored` (chance + redirect to rolemaster), `combatbark` cooldown (`COMBAT_BARK_COOLDOWN`, default 90 s) |
| 15 | 1078-1101 | **action gating by request type** (see section 3) |
| 16 | 1103-1111 | `$GLOBALS["CACHE_PARTY"]`, `$GLOBALS["IS_NPC"]` (false when speaker is in the follower party) |
| 17 | **1117** | **HOOK `prerequest.php`** |
| 18 | 1124 | `processor/comm.php` - all non-LLM request handlers; sets `$MUST_END` |
| 19 | 1127-1361 | rechat/narration budget logic; `$sqlfilter`; rechat sets `$FUNCTIONS_ARE_ENABLED=false` (1358) |
| 20 | 1367-1508 | `narrator_welcome` / `narrator_quest_comment` profile loading |
| 21 | 1510-1529 | `if ($MUST_END) { echo 'X-CUSTOM-CLOSE'; terminate(); }`, `INJECTION_LOG` terminate |
| 22 | 1548-1610 | `$GLOBALS["DIRECT_NARRATOR_DIALOGUE"]`, narrator re-sync |
| 23 | **1615** | `require prompt.includes.php` => (a) `prompts/prompts.php` -> first `require_once("dialogue_prompt.php")` -> **HOOK `dialogue_prompt.php`** (`HS/prompts/dialogue_prompt.php:370`), then `$PROMPTS=array(...)`, then **HOOK `prompts.php`** (`HS/prompts/prompts.php:285`); (b) `$FUNCTION_PARM_MOVETO`, `$FUNCTION_PARM_INSPECT` (`HS/prompt.includes.php:30-39`); (c) `prompts/command_prompt.php` (`$COMMAND_PROMPT=""`, `$COMMAND_PROMPT_FUNCTIONS`, `$DIALOGUE_TARGET`, `$ERROR_OPENAI`); (d) `require_once functions/functions.php` -> **HOOK `functions.php`** (`HS/functions/functions.php:2754`); (e) `$PROMPTS[type]["extra"]` => `FORCE_MOOD`, `FORCE_MAX_TOKENS`, `TRANSFORMER_FUNCTION` (`HS/prompt.includes.php:62-74`) |
| 24 | 1621-1658 | conditional injection of `Train<Skill>` action (in-core example of per-NPC conditional offering) |
| 25 | 1708 | `processor/request.php` builds `$request` (the cue) and may overwrite `$gameRequest[3]` from `$PROMPTS[type]["player_request"]`; for `funcret` runs `$GLOBALS["FUNCSERV"][code]`; for `instruction`/`suggestion` requires `functions/functions_instruction.php` |
| 26 | 1735-1740 | STOPALL magic word => `echo "{$GLOBALS["HERIKA_NAME"]}|command|Halt@\r\n"` |
| 27 | 1742-1858 | `CACHE_PEOPLE`, `CACHE_LOCATION`, audience scoping |
| 28 | 1863-1943 | **eventlog insert of the incoming request** (all types except `diary`, `cheatmode`) |
| 29 | 1946-2027 | `PROMPTS[type]["extra"]["dontuse"]` => terminate; RPG-comment cooldown (60 s global); `NARRATOR_TALKS` |
| 30 | 2031-2074 | history: `DataLastDataExpandedFor()` -> ... -> `replaceRoles()` -> **HOOK `context_building.php`** (`HS/lib/data_functions.php:3216-3221`); `$contextDataWorld = DataLastInfoFor("", -2,true)` |
| 31 | 2077-2127 | `COMMAND_PROMPT .=` current task; memory offer (`offerMemory`) => `$memoryInjectionCtx`; whisper/close notes |
| 32 | 2139-2219 | rechat action handling when `$GLOBALS["RECHAT_ALLOW_ACTIONS"]` (uses `unsetFunction()`) |
| 33 | 2248-2326 | minime command assist, Oghma (`processor/oghma.php`) |
| 34 | 2339-2456 | `$contextDataFull = array_merge($contextDataWorld, $contextDataHistoric)`; `$dynamicBiography = buildDynamicBiography($GLOBALS)`; `$worldPrompt`; player bio; middle-term memory; rumors; book task |
| 35 | 2466-2471 | `chimQuestEngineApplyActionSuppressionsForTurn($GLOBALS["HERIKA_NAME"], $GLOBALS["CACHE_LOCATION"])` |
| 36 | **2474** | `require_once functions/json_response.php` => `chimRefreshJsonResponseState(true)` => `setActions()`, `setResponseTemplate()`, `setStructuredOutputTemplate()`, `setGBNFGrammar()`, then **HOOK `json_response_custom.php`** (`HS/functions/json_response.php:81`, once), then `$GLOBALS["HOOKS"]["JSON_TEMPLATE"]` callbacks. **Action list is frozen here.** |
| 37 | 2489-2498 | `$nearbySections = $GLOBALS["PROMPT_NEARBY_SECTIONS"]`; `$actionsList = $GLOBALS["PROMPT_ACTIONS_LIST"]` |
| 38 | **2540** | **HOOK `context_pre.php`** (then only `PROMPT_NEARBY_SECTIONS` is re-synced, 2543-2545) |
| 39 | 2547-2579 | prompt injections rendered: `chimRenderPromptInjections("character_bottom", ...)`, `("prompt_bottom", ...)`; quest-engine context; `OGHMA_HINT` |
| 40 | 2581-2637 | system prompt assembled -> `$head[] = ['role'=>'system','content'=>$systemPrompt]`; then `$GLOBALS["COMMAND_PROMPT"] = ""` (2639-2645) |
| 41 | **2648** | **HOOK `context.php`** |
| 42 | 2657-2763 | call building: `funcret` -> `processor/funcret.php`; else `$prompt[] = ['role'=>$LAST_ROLE,'content'=>$request]` (+memory), `$contextData = array_merge($head, $contextDataFull, $prompt)` |
| 43 | 2789-2801 | `$GLOBALS["AVOID_LLM_CALL"]` => terminate; `diary` => `generateFollowerDiary()` and terminate |
| 44 | **2817** | `$outputWasValid = call_llm();` (`HS/lib/data_functions.php:5734-6498`): connector `open()`, stream loop -> `returnLines($sentences)` (TTS + `ScriptQueue` lines), then `processActions()` + post-filters + echo of command lines |
| 45 | 2823-2828 | `$GLOBALS["LLM_RETRY_FNCT"]()` if output invalid |
| 46 | 2922-2940 | `echo 'X-CUSTOM-CLOSE'`, flush, `SemaphoreManager::release("MAIN")` |
| 47 | **2944** | **HOOK `prepostrequest.php`** |
| 48 | 2945 | `processor/postrequest.php` (minime topics, post memory, scene classifier, ...) |
| 49 | **2946** | **HOOK `postrequest.php`** |

### 1.4 The hook loader (VERIFIED)

```php
// HS/lib/data_functions.php:7729-7747
function requireFilesRecursively($dir,$name) {
    global $gameRequest;
    $files = scandir($dir);
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') { continue; }
        $path = $dir . '/' . $file;
        if (is_dir($path)) {
            requireFilesRecursively($path,$name);
        } elseif (is_file($path) && $file === $name) {
            require_once($path);
        }
    }
}
// HS/functions/functions.php:2688-2704  (same, for 'functions.php', WITHOUT "global $gameRequest")
function requireFunctionFilesRecursively($dir)
```

Consequences (VERIFIED from the code above):
- Hook files execute in **function scope**. Only `$gameRequest` is a declared global (and only in `requireFilesRecursively`, not in `requireFunctionFilesRecursively`). Everything else must be reached through `$GLOBALS["..."]` / `global`. Because `main.php` runs at top level, all of its variables are in `$GLOBALS` (e.g. `$GLOBALS["request"]`, `$GLOBALS["head"]`, `$GLOBALS["contextDataFull"]`, `$GLOBALS["PROMPTS"]`, `$GLOBALS["FUNCTIONS_ARE_ENABLED"]`, `$GLOBALS["actionsList"]`, `$GLOBALS["db"]`, `$GLOBALS["talkedSoFar"]`, `$GLOBALS["overrideParameters"]`). Precedent: `ext/time_awareness/context.php:111` writes `$GLOBALS["request"]`.
- The scan is **recursive over all sub-folders** of `ext/`; any file anywhere under `ext/<plugin>/**` whose basename equals a hook name is loaded. Never give a library/config file a hook name.
- `require_once` => each hook file runs at most once per request (matters for `context_building.php`, since `replaceRoles()` may run more than once).
- Load order = `scandir()` order (alphabetical by folder name).
- There is **no enabled/disabled flag**: presence in `ext/` = active.

### 1.5 Hook-by-hook reference

| Hook file | Include site | When | What exists / may be mutated |
|---|---|---|---|
| `globals.php` | `HS/main.php:54` (also loaded by some UI/test pages, e.g. `HS/ui/api/tts_pronunciation_preview.php:62`) | before request parse, before core classes (`main.php:57-64`) | Exists: `$GLOBALS["db"]` (created by `chimRuntimeBootstrap`, `lib/runtime_bootstrap.php:209-212`, which also runs `CREATE SCHEMA IF NOT EXISTS plugins` at `:134-147`), general settings globals, `PLAYER_NAME`, `ENGINE_PATH`, functions from `lib/data_functions.php`, `lib/prompt_injections.php`, `lib/chim_quest_engine.php`, `Logger`. Use for: `chimRegisterPromptInjection()`, `chimRegisterActorProfileEnricher()`, `$GLOBALS["HOOKS"]["BIOGRAPHY_BUILDER"][name]`, `$GLOBALS["HOOKS"]["JSON_TEMPLATE"][]`, `$GLOBALS["external_fast_commands"]` (must be set here - it is read at `main.php:233`), constants/feature flags. `$gameRequest`/`HERIKA_NAME` are NOT meaningful yet. Precedent: `ext/xLifeLink_plugin/globals.php`. |
| `preprocessing.php` | `HS/main.php:193` | after parse + DB + chim_modes, **before** the `user_input` marker, **before the MAIN semaphore**, before profile load | `$gameRequest` (mutable; "Extensions may rename it during preprocessing", `main.php:195-196`), `$GLOBALS["db"]`, `$GLOBALS["CHIM_EXECUTION_MODE"]`, `$_GET["profile"]`. `HERIKA_NAME` still = default/narrator. Best place to swallow a custom high-frequency event: handle it and call `terminate()` (no lock taken yet). |
| `prerequest.php` | `HS/main.php:1117` | after profile/connector load, after type gating of `$FUNCTIONS_ARE_ENABLED`, before `processor/comm.php` | `HERIKA_NAME`, `PLAYER_NAME`, `CHIM_CORE_CURRENT_NPC_DATA/PROFILE_DATA/CONNECTOR_DATA`, `HERIKA_PERS`, `PROMPT_HEAD`, `HERIKA_*` bio globals, `IS_NPC`, `CACHE_PARTY`, `$GLOBALS["FUNCTIONS_ARE_ENABLED"]` (can be flipped here), `$gameRequest` (rename type / rewrite data). `FUNCTIONS`/`ENABLED_FUNCTIONS`/`PROMPTS` do NOT exist yet. MAIN semaphore is held (unless type is fast). Precedent: `ext/xLifeLink_plugin/prerequest.php`. |
| `dialogue_prompt.php` | `HS/prompts/dialogue_prompt.php:370` | inside step 23, before `$PROMPTS` is built | `$GLOBALS["TEMPLATE_DIALOG"]`, `$GLOBALS["TEMPLATE_ACTION"]`, `$GLOBALS["MAXIMUM_WORDS"]`, `$GLOBALS["MEMORY_STATEMENT"]` |
| `prompts.php` | `HS/prompts/prompts.php:285` | right after `$PROMPTS` is defined | `$GLOBALS["PROMPTS"][<type>] = ["cue"=>[...], "player_request"=>[...], "extra"=>["mood"=>..,"force_tokens_max"=>N,"transformer"=>callable,"dontuse"=>bool]]` (structure documented at `HS/prompts/dialogue_prompt.php:4-8`; `extra` consumed at `HS/prompt.includes.php:62-74`). Also `["afterfunc"]["cue"][<CodeName>]`. |
| `functions.php` | `HS/functions/functions.php:2754` | after `$GLOBALS["ENABLED_FUNCTIONS"]` was loaded from the catalog (2744-2748), **before** catalog rows are merged into runtime (2757-2767) and before the enabled-filter (2790-2804) | `$GLOBALS["F_NAMES"]`, `["F_TRANSLATIONS"]`, `["F_RETURNMESSAGES"]`, `["FUNCTIONS"]`, `["ENABLED_FUNCTIONS"]`, `["FUNCTION_PARM_INSPECT"]`, `["FUNCTION_PARM_MOVETO"]`, `["PROMPTS"]`, `["FUNCSERV"]`, `["action_post_process_fnct_ex"]`, plus everything from `prerequest`. `$gameRequest` is NOT auto-global here - use `$GLOBALS["gameRequest"]`. Note: core `functions.php` is `require_once`d, so it runs once per request. |
| `context_building.php` | `HS/lib/data_functions.php:3217` (inside `replaceRoles($lastDialogFull,$actor,$lastNelements)`, called from `DataLastDataExpandedFor()` at `:3238`, called from `main.php:2040-2044`) | while the history window is built | `$GLOBALS["CONTEXT_BUILDING_DATA"]` = array of `['role'=>..., 'content'=>..., '_g'=>gamets]`; the (possibly modified) array is what is returned (`:3221`). |
| `json_response_custom.php` | `HS/functions/json_response.php:81` | once, at first load of `json_response.php` (= `main.php:2474` in the normal flow), after `setActions()/setResponseTemplate()/setStructuredOutputTemplate()/setGBNFGrammar()` | `$GLOBALS["responseTemplate"]`, `$GLOBALS["structuredOutputTemplate"]`, `$GLOBALS["FUNC_LIST"]`, `$GLOBALS["grammar"]`, `$GLOBALS["PROMPT_ACTIONS_LIST"]`. Lost again if something later calls `chimRefreshJsonResponseState()` (narrator refresh path) - for durable edits prefer `$GLOBALS["HOOKS"]["JSON_TEMPLATE"][] = function(){...}` which is re-applied on every refresh (`json_response.php:43-51, 85`). |
| `context_pre.php` | `HS/main.php:2540` | after action list + all context pieces are computed, before the system prompt string is assembled | Mutable and still effective: `$GLOBALS["PROMPT_HEAD"]`, `["HERIKA_PERS"]`, `["COMMAND_PROMPT"]`, `["dynamicBiography"]`, `["worldPrompt"]`, `["PROMPT_NEARBY_SECTIONS"]` (re-synced), `["actionsList"]` (the local copy - `PROMPT_ACTIONS_LIST` is NOT re-read), `["rumorsText"]`, `["OGHMA_HINT"]`, `["contextDataFull"]`, `["memoryInjectionCtx"]`, `["request"]`; `chimRegisterPromptInjection()` still works (rendered at 2553-2566). Precedent: `ext/relationship_system/context_pre.php`. |
| `context.php` | `HS/main.php:2648` | after `$head` (system message) exists, before `$contextData` merge | `$GLOBALS["head"][0]["content"]` (full system prompt), `["contextDataFull"]`, `["request"]` (the cue, last message), `["LAST_ROLE"]`, `["FORCE_MAX_TOKENS"]`, `["overrideParameters"]`, connector globals, `["AVOID_LLM_CALL"]`. `COMMAND_PROMPT` has been blanked. Precedent: `ext/time_awareness/context.php`. |
| `prepostrequest.php` | `HS/main.php:2944` | after `X-CUSTOM-CLOSE` was sent and MAIN lock released | `$GLOBALS["talkedSoFar"]` (spoken sentences), `["alreadysent"]` (command lines), `["LAST_LLM_RESPONSE"]` (parsed JSON of the reply, set in connector `process()`), `["DEBUG_DATA"]` |
| `postrequest.php` | `HS/main.php:2946` | after core `processor/postrequest.php` | same. Precedent: `ext/relationship_system/postrequest.php` (queues async work only). |
| `comm.php` | **NOT FOUND** - no `requireFilesRecursively(..., "comm.php")` anywhere (grep over all `*.php`); `processor/comm.php` contains no ext include. `ext/relationship_system/comm.php` is a byte-for-byte style copy of the legacy entry point and is never included by core. | | Use `preprocessing.php` / `prerequest.php` for custom request handling. |

Both post hooks are skipped whenever `terminate()` was called (it `die()`s: `HS/lib/auditing.php:26-48`) - i.e. for all non-LLM requests, cooldown exits, funcret without follow-up, etc.

Other extension points that are not files (VERIFIED):

```php
// HS/lib/prompt_injections.php:10,59,87
function chimRegisterPromptInjection(string $slot, string $id, $content, int $priority = 100): bool   // slots rendered by core: "character_bottom", "prompt_bottom" (main.php:2553-2566); $content = string | string[] | callable($slot,$context)
function chimRenderPromptInjections(string $slot, array $context = []): string
function chimRegisterActorProfileEnricher(string $id, callable $callback, int $priority = 100): bool  // callback($actorName, $actorType /* "player"|"npc" */, $context) -> string|string[]; used for nearby-actor lines (lib/data_functions.php:1085,1227)
// context passed to injection callbacks (main.php:2547-2552): game_request, herika_name, narrator_name, player_name
$GLOBALS["HOOKS"]["BIOGRAPHY_BUILDER"][<name>] = function(&$dynamicBio, $currentNpcData) {...}   // lib/data_functions.php:7620-7649
$GLOBALS["HOOKS"]["JSON_TEMPLATE"][] = function() {...}                                           // functions/json_response.php:43-51
$GLOBALS["HOOKS"]["XTTS_TEXTMODIFIER"][]                                                          // tts/*.php
$GLOBALS["action_post_process_fnct"] (single) / $GLOBALS["action_post_process_fnct_ex"][] (list)   // lib/data_functions.php:6031-6039
$GLOBALS["VALIDATE_LLM_OUTPUT_FNCT"]($chunk) -> bool ; $GLOBALS["LLM_RETRY_FNCT"]()               // lib/data_functions.php:5937 ; main.php:2825
$GLOBALS["AVOID_LLM_CALL"]=true                                                                   // main.php:2789
$GLOBALS["external_fast_commands"] = ["mytype", ...]                                              // main.php:233-235
$GLOBALS["TRANSFORMER_FUNCTION"], $GLOBALS["FORCE_MOOD"], $GLOBALS["FORCE_MAX_TOKENS"]            // prompt.includes.php:62-68
```

System prompt skeleton (for knowing where injected text lands), `HS/main.php:2595-2602`:

```
<roleplay_instructions> PROMPT_HEAD </roleplay_instructions> {worldPrompt}
<character> HERIKA_PERS {dynamicBiography}{latestDiaryContext}{character_bottom injections} </character> {<knowledge>OGHMA_HINT</knowledge>}
<general_instructions> COMMAND_PROMPT </general_instructions>{actionsList}{nearbySections}{prompt_bottom injections}{paralinguistic}
{rumorsText}
{bookReadingTaskText}
```
Placeholders `#PLAYER_NAME#`, `#HERIKA_NAME#`, `#NARRATOR_NAME#` are substituted afterwards (`main.php:2619-2628`). Message list sent to the LLM = `[system] + contextDataFull (world info + history) + [memory] + [cue ($request) as role $LAST_ROLE] + [connector-added user message with the JSON template]`.

---

## 2. The action system

### 2.1 Roles of the globals (VERIFIED)

| Global | Role | Cite |
|---|---|---|
| `$GLOBALS["ENABLED_FUNCTIONS"]` | list of **code names** allowed this request. Seed list at `functions.php:17-69`, then replaced by `herikaLoadEnabledActionCodesForMode($isNpcMode, true)` when the catalog DB is ready (`:2744-2748`). Checked again in `setActions()` (`json_response.php:225`). | `HS/functions/functions.php`, `HS/functions/json_response.php` |
| `$GLOBALS["FUNCTIONS"]` | list of OpenAI-style definitions `["name"=>displayName,"description"=>..,"parameters"=>["type"=>"object","properties"=>[...],"required"=>[...]]]`. Built at `:1179-1951`, merged with catalog rows by `herikaActionCatalogApplyRowsToRuntimeFunctions()` (`lib/core/action_catalog.php:2876-2962`), filtered against `ENABLED_FUNCTIONS` at `:2790-2804`, reindexed `:2814`. | |
| `$GLOBALS["F_NAMES"]` | map **codeName => display name shown to / returned by the LLM** (e.g. `OpenInventory => Trade_Items`). `getFunctionCodeName($name)` resolves display->code (`functions.php:2008-2091`, has a static per-request cache). Catalog rows overwrite entries (`action_catalog.php:2912`). | |
| `$GLOBALS["F_TRANSLATIONS"]` | map codeName => description used in the prompt (`herikaGetPromptActionDescription()`, `functions.php:2317-2331`). | |
| `$GLOBALS["F_RETURNMESSAGES"]` | map codeName => return-message template used for the `infoaction` log line on funcret (`functions.php:2130-2188`); placeholders `#TARGET# #ITEM# #AMOUNT# #LOCATION# #HERIKA_NAME# #PLAYER_NAME# #RESULT#`. | |
| `$GLOBALS["BASE_FUNCTIONS"]` | codeName => definition copy (`functions.php:1966-1971`, rebuilt `action_catalog.php:2960`). | |
| `$GLOBALS["FUNCTION_PARM_INSPECT"]` | `DataPosibleInspectTargets()` + `PLAYER_NAME`: names of nearby actors; used as `enum` for target params and printed as "available targets" for Attack/Brawl (`json_response.php:237-239`). | `HS/prompt.includes.php:36-39` |
| `$GLOBALS["FUNCTION_PARM_MOVETO"]` | `DataPosibleMoveToTargets()` + `PLAYER_NAME` (actor-only move targets). | `HS/prompt.includes.php:30-33` |
| `$GLOBALS["FUNCSERV"][code]` | optional callable run when a `funcret` for `code` arrives: `call_user_func_array($GLOBALS["FUNCSERV"][$functionCodeName],[])` - may rewrite `$GLOBALS["request"]`, `$gameRequest[3]`. (Not called for `Attack`.) | `HS/processor/request.php:26-38` |
| `$GLOBALS["FUNCRET"][code]` | **DEAD in this build.** Only occurrence is the commented-out example `ext/herika_heal/functions.php:101`; `processor/funcret.php` no longer reads it (grep `\["FUNCRET"\]` over all php). Its job (argName / request rewrite / useFunctionsAgain) moved to catalog `metadata.followup`. | |
| `$GLOBALS["PROMPTS"]["afterfunc"]["cue"][code]` | cue text used as `$request` for a funcret of `code` (else `["default"]`). Still honoured. | `HS/processor/request.php:14-18`, `HS/prompts/prompts.php:158-172` |
| `$GLOBALS["FUNC_LIST"]` | display names offered this request (+`"Talk"`), shuffled; feeds `responseTemplate["action"]`, schema `enum`, GBNF grammar. | `HS/functions/json_response.php:236,282-284` |
| `$GLOBALS["PROMPT_ACTIONS_LIST"]` | `<available_actions_list>` text block. | `json_response.php:215-282` |
| `$GLOBALS["FUNCTIONS_ARE_ENABLED"]` | master switch for offering + parsing actions. | section 3 |

Note: `ext/herika_heal/functions.php` is **entirely inside one `/*** ... ***/` comment** (opens line 3, closes line 117) - it is inert documentation (its manifest says "Does not actually work"). It documents the legacy API: prefixes `ExtCmd` (result provided by a Papyrus plugin) / `WebCmd` (result provided by server plugin), and the legacy mod event name `SPG_CommandReceived`. The current Papyrus source uses `ModEvent.Create("CHIM_CommandReceived")` with 3 pushed strings `(npcname, command, parm)` (`F:\Modlists\LoreRim\mods\CHIM\Source\Scripts\AIAgentAIMind.psc:1395-1405`).

### 2.2 The action catalog (DB) - the current primary mechanism (VERIFIED)

Tables `public.core_action` (shipped, 53 rows here) and `public.core_action_custom` (overrides/imports, 2 rows here: `EquipGear`, `ReadBook`); view `public.combined_core_action` = custom rows + base rows not shadowed by a custom row with the same lower(code_name). Columns (identical in both tables; `\d` output):

```
id, code_name varchar(128) UNIQUE, action_name varchar(255), description text, return_message text,
available_to_npc bool, available_to_followers bool, available_to_narrator bool, is_activated bool,
parameters_json jsonb, metadata jsonb, game_function bool, import_version bigint,
script_proxy_program jsonb, created_at, updated_at
```

Loading: `herikaGetActionCatalogRowsByCode()` (`action_catalog.php:2436-2492`, cached in `$GLOBALS["HERIKA_ACTION_CATALOG_ROWS_BY_CODE"]`; reset with `herikaActionCatalogResetCache()`).

Enabled set for the request:

```php
// HS/lib/core/action_catalog.php:2665-2694
function herikaLoadEnabledActionCodesForMode($isNpc, $applyRequirements = false)
//   skip if !is_activated
//   skip if $applyRequirements && !herikaActionCatalogRowMatchesRequirements($row)
//   narrator mode -> available_to_narrator ; $isNpc -> available_to_npc ; else -> available_to_followers
```
`$isNpc` = `$GLOBALS["IS_NPC"]` = speaker is NOT in the current follower party (`main.php:1103-1111`).

`metadata` keys understood by the server (all VERIFIED in `action_catalog.php` unless noted):

| Key | Meaning | Cite |
|---|---|---|
| `dispatch` | `plugin_command` (default; sent to game), `script_proxy` (executed by server through ScriptProxy, not sent as command), `rolecommand`, `server_action`, `server_query` (last two => not a game function) | `:1235-1244, 1504-1508, 3396-3398` |
| `requirements.requires_rolemaster` | bool must equal NPC rolemaster state | `:1843-1848` |
| `requirements.requires_training_service` | NPC `extended_data.class.teaches` present | `:1850-1855` |
| `requirements.hide_in_rechat` / `show_only_in_rechat` | vs request type in `rechat`,`narration` | `:1857-1862` |
| `requirements.request_types_any` / `request_types_none` | list (array or CSV string) compared to `strtolower($GLOBALS["gameRequest"][0])` | `:1864-1872` |
| `requirements.npc_names_any` | list compared to `strtolower(HERIKA_NAME)` | `:1874-1877` |
| `requirements.npc_name_in_config_list`, `npc_name_in_action_config_list` | NPC-name allow-list taken from a config value / the action's own `custom_config` | `:1879-1894, 1628-1693` |
| `requirements.npc_factions_any` / `npc_factions_all` | faction FormIDs (e.g. `["0005091B"]` on `RentRoom`) checked with `NpcMaster` | `:1896-1912, 1695-1739` |
| `requirements.activity` | object: `require_available`, `require_fresh`, bools `is_in_combat is_attacking is_moving is_running is_sneaking is_sitting is_sleeping is_unconscious is_dead is_weapon_drawn`, `current_action`, `current_action_in`, `current_action_not_in`, `use_type`, `use_type_in`, `use_type_not_in` - evaluated against NPC `metadata.activity_status` (pushed by the game via `gamedata.php` types `activity_status` / `activity_status_bulk`; "fresh" = younger than 45000 ms) | `:1741-1832`, `HS/lib/core/activity_status.php:301,392-438` |
| `cooldown_seconds` | in-game seconds since this NPC last issued the action (table `actions_issued`) | `:1964-1986, 2005-2011` |
| `followup` | `{enabled, prompt, arg_name, use_functions_again}`; user overrides live in `custom_config` as `followup_enabled`, `followup_arg_name`, `followup_prompt`, `followup_use_functions_again` | `:1284-1310, 1436-1485` |
| `confirmation.default_policy` | `ask` / `automatic`; user override `custom_config.confirmation_required` (bool) or legacy `custom_config.confirmation_policy` (`default|ask|automatic`) | `:364-449` |
| `editor_fields` | array of field defs (`key,label,type,default,help,format,minimum,...`) rendered by the web Action Editor; values stored in `metadata.custom_config`; readable with `herikaActionCatalogGetCustomConfigValue($codeName,$configKey,$default)` | `:451-662, 2644-2663` |
| `parameter_template` | template resolved with `{{config.x}}`, `{{parameters.x}}`, `{{parameter_target}}` to build the wire parameter | `HS/functions/functions.php:137-184` |
| `suppress_placeholder_infoaction`, `debug_notification`, `top_left_notification` | logging / in-game notification on funcret | `HS/processor/funcret.php:2-21, 50-65` |
| `source`, `builtin`, `status`, `import_type`, `import_version`, `import_filename`, `bridge_script` | bookkeeping | `HS/processor/import_files.php:1197-1221` |

`script_proxy_program` JSON DSL (VERIFIED `action_catalog.php:3556-3839`): `{"switch_on": "<context path>", "cases": {"<value>": {program}, "__default": {program}}, "commands":[{"cmd_id":N,"args":{...},"delay_seconds":N}], "db_inserts":[{"table":..,"data":{..}}], "npc_metadata_updates":{...}}` with `{{...}}` templates over context keys `actor_name, actor_refid, actor_furniture, action_name, full_call, parameter_raw, parameter_target, parameters, config, request_ts, game_ts, local_ts, player_name, player_refid, cache_people_limited, cache_location, cache_party, toast_delay_seconds, local_ts_ms`. Commands are queued to the game as `rolemaster|rolecommand|ScriptProxy@{"cmdID":N,...}` rows in `responselog` (`HS/lib/scriptproxy_papyrus.php:858-887`). The ScriptProxy builder has Actor / ObjectReference / FormList / EffectShader / ActorUtil / Faction command groups (`scriptproxy_papyrus.php:16-33`); **no Quest / SetStage command was found in it**.

How rows get into the catalog (VERIFIED):
1. **Game-side CSV**: files `*_actions.csv` at top level of `Data/CHIM` are uploaded by the DLL on startup as CSV import type `custom_action_import` (documented at https://dwemerdynamics.com/chim/modders-guide.html; server handler `handleCustomActionImport($csvData,$timestamp,$game_timestamp,$filename)` at `HS/processor/import_files.php:1102-1330`). Columns read: `code_name, action_name, description, return_message, available_to_npc, available_to_followers, available_to_narrator, is_activated (default 1), game_function (default 1), parameters_json, metadata, import_version, script_proxy_program`. A row only overwrites an existing custom row if `import_version` is higher (`:1261-1271`); rows from the same `import_filename` that disappeared from the CSV are deleted (`:1282-1296`).
2. **PHP**: `herikaActionCatalogUpsertCustomRow($row)` (`action_catalog.php:3379-3467`, `INSERT ... ON CONFLICT (code_name) DO UPDATE`), and toggles/config via `herikaActionCatalogUpsertCustomToggle($codeName,$enabled)`, `herikaActionCatalogUpsertCustomConfig($codeName,$configValues)`, `...UpsertCustomParameters`, `...UpsertCustomTextFields`.
3. Plugin package `migrations/*.sql` (section 6).
4. Web UI "Action Editor".

Display names are normalised by `herikaNormalizeActionCatalogDisplayActionName()` (`action_catalog.php:704-728`): HERIKA_NAME->`Npc`, PLAYER_NAME->`Player`, spaces/dashes/camelCase boundaries -> `_` (so `GiveGoldTo` is shown as `Give_Gold_To`; `action_name` may contain `#PLAYER_NAME#`, e.g. `Arrest_#PLAYER_NAME#`). This normalisation is applied to catalog rows, **not** to names a plugin pushes only into `$GLOBALS["FUNCTIONS"]`.

### 2.3 How the action list is rendered into the prompt (VERIFIED)

`setActions()` (`HS/functions/json_response.php:194-286`):
- For request types `narration` and `vision`: `FUNC_LIST=["Talk"]`, return.
- `chimShouldExposePromptActions()` (`:29-41`) = false for `vision`; true for direct narrator dialogue; else `$GLOBALS["FUNCTIONS_ARE_ENABLED"]`.
- Output block:

```
<available_actions_list>
{COMMAND_PROMPT_FUNCTIONS}                         <- "\n\n#Available Actions\nUse if your character needs to perform an action:" (prompts/command_prompt.php:29)
AVAILABLE ACTION: {name} ({description})           <- one line per $GLOBALS["FUNCTIONS"] entry whose code is in ENABLED_FUNCTIONS
...                                                   (Attack/Brawl also get "(available targets: a,b,c)"; a dozen built-ins get hard-coded extra usage text)
AVAILABLE ACTION: Talk (default action, used when no other action is suitable)
</available_actions_list>
```
This block is `$actionsList` and is placed right after `</general_instructions>` in the system prompt (`main.php:2599-2600`).

JSON template requested from the LLM (`setResponseTemplate()`, `json_response.php:289-425`; default variant):

```php
$GLOBALS["responseTemplate"] = [
    "character"=>$GLOBALS["HERIKA_NAME"],
    "listener"=>$listenerDesc,
    "mood"=>$moodDescription,
    "action"=>implode("|",$GLOBALS["FUNC_LIST"]),
    "target"=>"action target actor (prefer exact Name [RefID: XXXXXXXX] from people_present, ...",
    "item"=>"item identifier (...)",
    "amount"=>"quantity to give or spawn only when the chosen action supports it. ...",
    "message"=>$messageDescription
];   // + "lang" when LANG_LLM_XTTS; + "emotion","emotion_intensity" when $GLOBALS['use_emotions_expression'] (main.php:19 sets true); + response_tone_* for Zonos TTS
```
The connector appends it as a final user message: `"... Use ONLY this JSON object to give your answer. Do not send any other characters outside of this JSON structure : \n".json_encode($GLOBALS["responseTemplate"])` (`HS/connector/openrouterjson.php:343-346`). If the connector row has `enforce_json` it also sends `response_format`: `$GLOBALS["structuredOutputTemplate"]` when `json_schema` is on (strict schema, `additionalProperties:false`, `action` = `enum` of `FUNC_LIST`, required `character, listener, message, mood, action, target, item` [+ `lang`, `emotion`, `emotion_intensity`]) else `{"type":"json_object"}` (`openrouterjson.php:649-655`, `json_response.php:457-522`). All 8 connectors configured on this install are `openrouterjson` with `enforce_json=1, json_schema=1`.

**Consequence (VERIFIED + [INFERENCE])**: the only parameter slots the LLM can fill are `target`, `item`, `amount`. `buildFunctionParameterValueFromResponse()` (`functions.php:2420-2456`) reads `$parsedResponse[<propertyName>]` for each property of the action definition; with the strict schema a property with any other name can never be present. So custom actions must name their parameters `target` / `item` / `amount` (or extend both templates through a `JSON_TEMPLATE` hook / `json_response_custom.php`).

### 2.4 Function-calling vs JSON mode (VERIFIED)

`LLMConnector::setOldGlobals()` / `getConnector()` only know drivers `openaijson`, `openrouterjson`, `google_openaijson`, `groqjson`, `player2json` (`HS/lib/core/llm_connector.class.php:260-440`) - all JSON-response drivers. `processActions()` still contains an "Old function scheme" branch (`$this->_functionName`, `openrouterjson.php:1111-1129`) but the normal path is the JSON one:

```php
// HS/connector/openrouterjson.php:1131-1147
$parsedResponse=__jpd_decode_lazy($this->_buffer);
if (isset($parsedResponse[0]["action"])) $parsedResponse=$parsedResponse[0];
if (!isset($parsedResponse["target"])) $parsedResponse["target"] = "";
$executionContext = buildFunctionExecutionContextFromResponse($parsedResponse);
queueFunctionExecutionCommand($this->_commandBuffer, $alreadysent, $executionContext, "openrouterjson");
```
- `buildFunctionExecutionContextFromResponse($parsedResponse)` (`functions.php:2458-2505`): `action` -> `getFunctionCodeName()` -> `findFunctionByName()` (catalog row first, then `$GLOBALS["FUNCTIONS"]`, `functions.php:2579-2669`) -> parameter: single-property action => that property's value (fallback `target`); multi-property action => associative array => `json_encode` (`buildFunctionExecutionParameter`, `:2563-2577`; or `parameter_template`).
- `queueFunctionExecutionCommand(&$commandBuffer,&$alreadySent,$executionContext,$connectorName,$actorName=null)` (`:2507-2552`): ignores `Talk`/unknown; drops the command when the action has required params and the value is empty; de-duplicates by md5; builds the wire line (next section).
- While streaming, `process()` extracts `message` incrementally for TTS, and sets `$GLOBALS["SCRIPTLINE_LISTENER"]`, `["SCRIPTLINE_ANIMATION"]`, `["SCRIPTLINE_EXPRESSION"]`, `["LLM_LANG"]`, `["LAST_LLM_RESPONSE"]` (`openrouterjson.php:1003-1039`). Special case: `action=="Inspect"` with a target suppresses speech (`:1006-1009`).

### 2.5 Exact wire lines (VERIFIED)

```php
// action (HS/functions/functions.php:2531-2542)
$commandStr = $actorName . "|" . $commandChannel . "|" . $functionCodeName . "@" . strval($executionContext["parameter_string"] ?? "") . "\r\n";
//   $actorName      = $GLOBALS["HERIKA_NAME"]
//   $commandChannel = "command" | "confirmcommand" | "approvedcommand"   (herikaActionCatalogGetConfirmationCommandChannel, action_catalog.php:415-449)
//   $functionCodeName = CODE name (e.g. GiveGoldTo), not the display name
// emitted by: echo implode("\r\n", $actions)."\r\n";   (HS/lib/data_functions.php:6483) - AFTER all speech lines of the reply; also appended to log/output_to_plugin.log

// speech (HS/lib/chat_helper_functions.php:1841)
echo "{$outBuffer["actor"]}|ScriptQueue|$responseForSubtitles/{$GLOBALS["SCRIPTLINE_EXPRESSION"]}/{$GLOBALS["SCRIPTLINE_LISTENER_ATOMIC"]}/{$GLOBALS["SCRIPTLINE_ANIMATION"]}/$responseTextPhonetic/$volumeBoost/{$GLOBALS["SCRIPTLINE_RECHAT_TARGET"]}/{$currentUtteranceId}\r\n";

// queued out-of-band lines returned on the game's "request" poll (HS/processor/comm.php:367-372)
echo "{$responseData["actor"]}|{$responseData["action"]}|{$responseData["text"]}\r\n";     // rows of table responselog (localts,sent,actor,text,action,tag,rowid), claimed by DataDequeue()
//   e.g. action = 'rolecommand|ScriptProxy@{json}', 'rolecommand|DebugNotification@text', 'rolecommand|ShowTrainingMenu@Name', 'rolecommand|RenameNPC@0xREFID@Name'

// misc
echo "{$GLOBALS["HERIKA_NAME"]}|command|Halt@\r\n";            // main.php:1736
echo $notificationSpeaker . "|rolecommand|DebugNotification@" . $debugNotificationText . PHP_EOL;   // processor/funcret.php:181
echo 'X-CUSTOM-CLOSE'.PHP_EOL;                                  // end of every response (main.php:2922, auditing.php:27)
header("X-Event-Type: narration");                              // main.php:1335 ; header('X-Narrator-Display-Name: ...') main.php:39
```
`confirmcommand` / `approvedcommand` / `rolecommand` / `ScriptQueue` literals are all present in `AIAgent.dll` (string scan). Confirmation only applies when the catalog row has `game_function=true` and the code is not in the read-only list; default policy is `ask` for codes (lower-case) `arrestplayer, acceptsex, kiss, makelove, removeclothes, sexaction, takehelditem, takegoldfromplayer` (`action_catalog.php:364-400`) - evidence that an adult-action plugin ecosystem already uses these code names.

Post-processing chain before the echo (`HS/lib/data_functions.php:6029-6483`):
1. `$actions=$connectionHandler->processActions();`
2. `$GLOBALS["action_post_process_fnct"]($actions)` (single, optional)
3. each `$GLOBALS["action_post_process_fnct_ex"][]` closure: `function(array $actions): array` - each element is the full wire string. Core closure (`functions.php:2830+`) drops quest-suppressed actions, runs `herikaActionCatalogExecuteScriptProxyAction($action)` (and removes the action if executed server-side), and handles Drink/Toast/Train*/etc.
4. built-in target clean-ups (Attack, GiveItemTo, GiveGoldTo, TradeItems, Follow, TravelTo->TravelToRaw, MoveTo, Brawl, TakeGoldFromPlayer, PickupItem)
5. insert into `actions_issued(action, fullcall, actorname, ts, gamets, localts, original)`
6. echo.

Closures registered from an ext `functions.php` run **before** the core closure (the ext scan at `:2754` precedes the core `[]=` at `:2830`).

### 2.6 `funcret` processing (VERIFIED)

Game sends `funcret|ts|gamets|command@<CodeName>@<param>@<resultText>` (Papyrus: `AIAgentFunctions.logMessageForActor("command@"+cmd+"@"+parm+"@"+result, "funcret", npcName)` per the modders guide).
1. `funcret` is not in the action-enabled type list => `$FUNCTIONS_ARE_ENABLED=false` (`main.php:1078-1080`), not a fast command => takes the MAIN lock.
2. `processor/request.php:8-38`: `$request = $PROMPTS["afterfunc"]["cue"][$functionCodeName] ?? ["default"]`; `Attack` + "Error" special case; else `FUNCSERV` callable.
3. The funcret request is logged to `eventlog` (type `funcret`).
4. `main.php:2657-2664` -> `processor/funcret.php`:
   - `$followupConfig = herikaActionCatalogGetResolvedFollowupConfig($functionCodeName)` (needs a **catalog row**; returns `[]` otherwise; `UseSoulGaze` hard-disabled).
   - `chimLogFuncretResultInfoAction(...)` writes an `infoaction` event built from the return-message template (or `"<npc> issued ACTION <name>: <result>"`; if result starts with `error`: `"<npc> issued ACTION, but <result>"`) - so the outcome reaches future context even without a follow-up.
   - `if (!$followupEnabled) terminate();` and `if ($followupPrompt === '') terminate();` (`:134-157`).
   - else `$request = "({$followupPrompt}) {$request}"`, builds assistant `tool_calls` + `role: tool` messages, `$contextData = array_merge($head, $contextDataFull, $functionCalled, $returnFunctionArray)`.
   - `use_functions_again` (chain limit `herikaActionCatalogGetFollowupChainLimit()` = 1) => `$GLOBALS["FUNCTIONS_ARE_ENABLED"]=true`.
   **[INFERENCE]** because this flag is raised after `json_response.php` was already evaluated with actions off, the follow-up prompt contains no action list and no `enum`; chained actions are parsed but not advertised.
5. `call_llm()` -> spoken follow-up line.

Built-in follow-up defaults: `action_catalog.php:1383-1434` (e.g. disabled for `Attack, Consume, FollowPlayer, ForgiveCrime, GiveGoldTo, GiveItemTo, HireCarriage, HireFerry, MoveTo, RentRoom, TakeGoldFromPlayer`).

### 2.7 MOST IMPORTANT - offering / hiding an action per request / per NPC

Where `ENABLED_FUNCTIONS` / `FUNCTIONS` are filtered (all VERIFIED):

| Where | What |
|---|---|
| `functions.php:2744-2748` | DB load with requirements + mode flags (`herikaLoadEnabledActionCodesForMode($isNpcMode, true)`) |
| `functions.php:2783-2788` | `TakeHeldItem` removed unless `HeldItems::hasHeldItems()` |
| `functions.php:2790-2804` | every `FUNCTIONS` entry whose code is not in `ENABLED_FUNCTIONS` is removed |
| `functions.php:2706-2720` | `function unsetFunction($functionCodename)` - removes code from `ENABLED_FUNCTIONS` and prunes `FUNCTIONS` |
| `functions/functions_instruction.php:30-32` | `unsetFunction("ComeCloser"); unsetFunction("IncreaseWalkSpeed"); unsetFunction("DecreaseWalkSpeed");` for `instruction`/`suggestion` |
| `main.php:1621-1658` | conditional ADD: `Train<Skill>` pushed into `FUNCTIONS`/`ENABLED_FUNCTIONS`/`F_NAMES` only if `Training` enabled and NPC `extended_data.class.teaches` set |
| `main.php:2142-2216` | rechat: `unsetFunction(...)` x8, conditional `TravelTo` clone for NPCs |
| `main.php:2466-2471` + `lib/chim_quest_engine.php:3658-3684` | **per-turn, per-NPC, per-location suppression**: `chimQuestEngineApplyActionSuppressionsForTurn()` calls `unsetFunction($actionCode)` and records `$GLOBALS['CHIM_QUEST_SUPPRESSED_ACTIONS']`; the core post-filter then also drops that action if the LLM emits it anyway (`functions.php:2846-2852`) |
| `json_response.php:225` | final check `in_array($fname,$GLOBALS["ENABLED_FUNCTIONS"])` when rendering |
| `json_response.php:203-206` | `narration`/`vision` => Talk only |

Available techniques for a plugin, in order of preference:

**A. Declarative (no PHP): catalog row requirements.** Register the action (CSV in `Data/CHIM` or `herikaActionCatalogUpsertCustomRow`) with e.g.
`"metadata": {"dispatch":"plugin_command","requirements":{"request_types_any":["inputtext","inputtext_s"],"hide_in_rechat":true,"activity":{"current_action_not_in":["dead","unconscious","sleeping","combat","attacking"]},"npc_factions_any":["<formid>"]},"cooldown_seconds":N,"confirmation":{"default_policy":"ask"},"followup":{"enabled":true,"prompt":"...","arg_name":"target"}}`. Evaluated per request in `herikaActionCatalogRowMatchesRequirements()`. Limitation: requirement vocabulary is fixed (no arbitrary predicate, no "player is the listener", no per-NPC consent flag other than name lists / factions / activity).

**B. Procedural: ext `functions.php`.** Runs with full speaker context (`HERIKA_NAME`, `CHIM_CORE_CURRENT_NPC_DATA`, `gameRequest`, DB). Either (i) push a runtime-only definition when the gate passes (legacy API still works: set `F_NAMES[code]`, `F_TRANSLATIONS[code]`, `F_RETURNMESSAGES[code]`, append to `FUNCTIONS`, append code to `ENABLED_FUNCTIONS`), or (ii) for a catalog-backed action remove the code from `$GLOBALS["ENABLED_FUNCTIONS"]` when the gate fails (the merge at `:2765` re-adds the definition to `FUNCTIONS`, but the filter at `:2790-2804` then removes it again because the code is no longer enabled).

**C. Late veto: `unsetFunction("<Code>")`** from any code that runs after core `functions.php` and before `main.php:2474`. No ext hook file sits in that window except ext `functions.php`/`prompts.php` themselves (both inside step 23) - i.e. `prerequest.php` is too early (function undefined, lists not built) and `context_pre.php` is too late for the JSON template/enum. **[INFERENCE]** a `context_pre.php` plugin could still call `unsetFunction()` followed by `chimRefreshJsonResponseState()` and then overwrite `$GLOBALS["actionsList"] = $GLOBALS["PROMPT_ACTIONS_LIST"]`; this is not done anywhere in core.

**D. Hard gate after the LLM: `$GLOBALS["action_post_process_fnct_ex"][]`** - drop the wire line if server-side preconditions fail. This is independent of what the prompt offered and is the correct place for non-negotiable checks.

**E. Game-side confirmation**: `confirmcommand` channel via catalog confirmation policy.

Runtime-only (technique B-i) actions have two functional gaps versus catalog rows (VERIFIED): no funcret follow-up (section 2.6) and no confirmation channel / script-proxy / cooldown / editor UI. A hybrid (catalog row for metadata + ext `functions.php` for the dynamic gate) gets both.

---

## 3. Which request types call the LLM, and which have actions

Rule (VERIFIED): a request reaches `call_llm()` unless something terminated it earlier. There is no whitelist - **unknown types fall through the `processor/comm.php` elseif chain (`$MUST_END` stays false) into the LLM path**; `processor/request.php:159-177` takes the cue from `$PROMPTS[type]["cue"]` (random element), optionally overwrites `$gameRequest[3]` from `["player_request"]`, and falls back to `TEMPLATE_DIALOG` with a warning if no cue exists.

Never reach the LLM:
- fast / log-only list at `main.php:949-985`: `info, infonpc, infonpc_close, infoloc, infoitems, chatme, chat, infoaction, death, itemfound, travelcancel, infoplayer, status_msg, util_npcname, bleedout, spellcast, backgroundaction, reanimate, itempickup, npc_reanimated, region` (duplicate suppression 5 s / 2 s for infonpc/infoloc/infoitems).
- `processor/comm.php` handlers that set `$MUST_END`: `init` (may convert to `narrator_welcome`), `wipe`, `request` (poll queue), `_quest, _uquest, _questdata, _questreset, _speech_abort, _speech, book, contentbook, togglemodel, death, location, force_current_task, recover_last_task, player_menu_tts_prefetch, player_menu_tts_play, just_say` (speaks `$gameRequest[3]` verbatim through TTS: `returnLines([trim($gameRequest[3])])`, `comm.php:1219-1223`), `playerdied`, `setconf` (writes `conf_opts` from `key@value`, `comm.php:1281-1329`), `infosave`, **anything starting with `info`** (`comm.php:1343-1347`: `logEvent` only), `npcvoice_refresh, addnpc*, addbgnpc*, util_location_name, util_faction_name, util_location_npc, enable_bg/disable_bg, updateprofile*, waitstart, goodnight, waitstop, diary_narrator, diary_player, diary_nearby, core_profile_assign, named_cell*, switchrace, snqe`, `itemtransfer`; `quest` => only continues as `narrator_quest_comment` when enabled.
- `playerinfo`, `newgame` (log + terminate, `main.php:988-994`); `npcspellcast`; `ext_held_item_raw`, `ext_vr_item_raw`; `physics_raw`; `bored` unless the narrator bored flow is active (otherwise handed to the rolemaster service and terminated, `main.php:998-1033`); `diary` (own generator, `main.php:2795-2801`); any type with `PROMPTS[type]["extra"]["dontuse"]` true.

Reach the LLM (types with cues in `prompts/prompts.php` or special handling): `inputtext, inputtext_s, ginputtext, ginputtext_s` (renamed to `inputtext(_s)` with `OVERRIDE_DIALOGUE_TARGET`, `prompt.includes.php:7-15`), `narrator_inputtext, instruction, suggestion, welcome, cheatmode, rechat, narration, continue, continue_group, funcret` (only with follow-up), `chatnf*`, `chatnf_book`, `combatend, combatendmighty, combatbark, goodmorning, lockpicked, afterattack, memory, vision, chatsimfollow, traveldone, rpg_lvlup, rpg_shout, rpg_soul, rpg_word, narrator_welcome, narrator_quest_comment`, plus any plugin-defined type.

Actions enabled? (VERIFIED `main.php:1078-1101`, `1358`, `2142-2143`, `1598`, `json_response.php:203-211`)

| Type | Actions |
|---|---|
| `inputtext, inputtext_s, ginputtext, ginputtext_s, welcome` | value set by the entry script (true via `streamv2.php` with a JSON driver; false via `comm.php`/`stream.php`/direct `main.php`) |
| `narrator_inputtext`, `instruction`, `cheatmode` (incl. `#` prefix and CHEATMODE mode) | forced **true** |
| `suggestion` | forced false |
| every other type (incl. plugin-defined types, `funcret`, `chatnf`) | forced **false** at `main.php:1078-1080` |
| `rechat` | false (`:1358`) unless profile setting `RECHAT_ALLOW_ACTIONS` (`:2142-2143`, with a reduced list) |
| `narration`, `vision` | Talk only regardless |
| `funcret` | true only for parsing when `followup.use_functions_again` (see 2.6) |

**[INFERENCE]** a plugin can turn actions on for its own request type by setting `$GLOBALS["FUNCTIONS_ARE_ENABLED"]=true` in `prerequest.php` (runs at `:1117`, after the forced-false at `:1078-1080`; nothing later resets it for a non-rechat type). Not done anywhere in core/ext, so untested.

---

## 4. Connector / model selection

VERIFIED:
- Tables: `core_llm_connector(id,label,metadata jsonb,url,model,provider,driver,reasoning_model,max_tokens,enforce_json,prefill_json,api_badge_id,json_schema,temperature,presence_penalty,frequency_penalty,repetition_penalty,top_p,top_k,min_p,top_a,service)`; `core_profiles(id,label,default_npc,default_narrator,tts_connector_id,itt_connector_id,llm_primary_id,llm_secondary_id,llm_tertiary_id,llm_quaternary_id,llm_formatter_id,llm_fallback_id,metadata jsonb,diary_connector_id,slot,prompt)`; NPC -> profile via `core_npc_master.profile_id`.
- Slot names: `1 => 'Standard', 2 => 'Fast', 3 => 'Powerful', 4 => 'Experimental'` (`HS/lib/llm_randomizer.php:231-239`); slot->column map `llm_primary_id, llm_secondary_id, llm_tertiary_id, llm_quaternary_id` (`:188-193`). So **yes, there is a "Fast" connector slot (slot 2 = `llm_secondary_id`)**.
- Which slot: `LLMRandomizer::getConnectorSlot($profileData,&$npcData,$npcMaster)` - when the profile's random mode is off it returns the **global** slot `conf_opts.chim_profile_model` (1-4, default 1) (`:48-54,137-146`); when on it rotates per NPC. `LLMRandomizer::getConnectorIdForSlot($profileData,$slot)` falls back to the first configured slot; a Player2 force switch (`conf_opts.PLAYER2_FORCE_ALL_LLM`) overrides everything. There is no per-request-type slot selection in core, except dedicated global connectors for side jobs (`CORE_CONNECTOR_DIRECTOR=1, CORE_CONNECTOR_PLAYER=2, CORE_CONNECTOR_SUMMARY=4, CORE_CONNECTOR_MEDIUMTERM=4, CORE_CONNECTOR_SCENECLASSIFIER=7, CORE_CONNECTOR_PROFILES=1, CORE_CONNECTOR_BGL=1, RELLLM_CONNECTOR=5`, `conf/conf.sample.php:165-181`) and the diary connector.
- This install: profile 1 "Default Profile" = primary 10 (Grok 4.3), secondary/Fast 1 (DeepSeek V4 Flash), tertiary 3 (GLM 5.2), quaternary 4 (DeepSeek V4 Pro), no fallback; all `openrouterjson`, `max_tokens` 750 (Gemma 3N E4B: 128).
- Automatic fallback: `core_profiles.llm_fallback_id` + profile metadata `LLM_FALLBACK_ENABLED` on connection failure / HTTP >= 300 (`lib/data_functions.php:5775-5919`).
- `call_llm_internal()` uses whatever is in `$GLOBALS["CHIM_CORE_CURRENT_CONNECTOR_DATA"]` at call time: `$connector->getConnector($GLOBALS["CHIM_CORE_CURRENT_CONNECTOR_DATA"])` then `$connectionHandler->open($contextData,$overrideParameters)` (`:5751-5771`).

Per-request overrides a plugin can apply (VERIFIED mechanisms):

| Knob | How | Cite |
|---|---|---|
| max tokens | `$GLOBALS["FORCE_MAX_TOKENS"]=N;` (wins over everything; `0` = remove the limit in most drivers) or `$GLOBALS["overrideParameters"]["MAX_TOKENS"]=N;` or `PROMPTS[type]["extra"]["force_tokens_max"]` | `connector/openrouterjson.php:618-624,714-717`; `prompt.includes.php:65-66`; all `*json.php` drivers implement both |
| model (same connector) | `$GLOBALS["overrideParameters"]["model"]="vendor/model";` (`init_connector`: "We shoud be able to overwrite model.") | `openrouterjson.php:272-276` |
| temperature / penalties / top_* / `json_schema` / `ENFORCE_JSON` | write `$GLOBALS["CONNECTOR"][$driver][key]` after `setOldGlobals()` and before `call_llm()`; in-core helper pattern: `chimQuestEnginePushConnectorOverrides($driverName, array $overrides)` / `chimQuestEnginePopConnectorOverrides($driverName, array $snapshot)` | `openrouterjson.php:587-616`; `lib/chim_quest_engine.php:2037-2078` |
| whole connector | repeat the core pattern: `$c=new LLMConnector(); $d=$c->getById($id); $c->setOldGlobals($d); $GLOBALS["CHIM_CORE_CURRENT_CONNECTOR_DATA"]=$d;` with `$id = LLMRandomizer::getConnectorIdForSlot($GLOBALS["CHIM_CORE_CURRENT_PROFILE_DATA"], 2)` for "Fast" | pattern at `main.php:1583-1595`, `lib/chat_helper_functions.php:3989-4014` |
| extra body params | connector row `metadata.extra_parameters` (+`extra_parameters_enabled`) are merged into the request body | `llm_connector.class.php:5-48`, `openrouterjson.php:645-647` |

In `open()` (the streaming path) `$customParms` is only consulted for `model`, `models`, `MAX_TOKENS`; the generic "copy every custom parm into the body" loop exists only in `fast_request()` (`openrouterjson.php:1350-1352`).

Side calls (non-streaming, do not speak): `$driver->fast_request($contextData, $customParms, $callName='')` returns the content string (`openrouterjson.php:1179-1497`). Verified in-core usage pattern for a low-temperature classifier: `chimQuestEngineSelectDialogueBeatByIntent()` (`lib/chim_quest_engine.php:2219-2336`): push overrides `temperature 0.1, reasoning_model false`, `fast_request($messages, array('MAX_TOKENS' => 220), 'quest_intent')`, pop overrides, parse JSON, confidence threshold.

---

## 5. Per-NPC profiles

VERIFIED storage:
- Table `public.core_npc_master` columns: `id, npc_name (UNIQUE), npc_favorite, lock_profile, prompt_head, npc_static_bio, oghma_knowledge_tags, emote_moods, personality, relationships, occupation, appearance, skills, speechstyle, goals, voiceid, metadata jsonb, gender, race, refid varchar(16), profile_id -> core_profiles.id, dynamic_profile int, extended_data jsonb, md5, gamets_last_updated, core, base, tags`. History/backups: `core_npc_master_history`, `npc_profile_backup`. Other related tables present: `dynamic_bio`, `bio_templates(_custom)`, `npc_templates*`, `json_personalities`, `core_player`, `core_narrator`. (Table is empty on this fresh install.)
- Legacy per-NPC conf files `conf/conf_<md5>.php` are migrated into the table on first use and moved to `conf/.old/` (`main.php:411-507`). `conf/conf.sample.php` documents the legacy globals (`HERIKA_PERS`, `HERIKA_PERSONALITY`, `HERIKA_SPEECHSTYLE`, `HERIKA_DYNAMIC`, `DYNAMIC_PROFILE`, `DYNAMIC_PROFILE_FIELDS = ["personality","speechstyle","goals"]`, `DYNAMIC_PROMPT_*`).
- `addnpc` request populates `base, gender, race, refid`, `metadata.skills.*`, `metadata.equipment.*`, `metadata.stats.*`, display-name aliases (`processor/comm.php:1403-1529`). **No age / child flag exists server-side** (grep for "child" in main/processor/functions/npc_master/action_catalog: no hit) - adult verification must come from the game side.
- Row -> globals (`NpcMaster::setOldGlobalsFromCurrentNpcData`, `lib/core/npc_master.class.php:1023-1154`): `npc_name->HERIKA_NAME`, `prompt_head->PROMPT_HEAD`, `npc_static_bio->HERIKA_BACKGROUND`, `oghma_knowledge_tags->OGHMA_KNOWLEDGE`, `personality->HERIKA_PERSONALITY`, `occupation->HERIKA_OCCUPATION`, `appearance->HERIKA_APPEARANCE`, `skills->HERIKA_SKILLS`, `speechstyle->HERIKA_SPEECHSTYLE`, `goals->HERIKA_GOALS`, `emote_moods->EMOTEMOODS`, `core->HERIKA_PERS` (`"Roleplay as {name}.\n{core}"`), `dynamic_profile->DYNAMIC_PROFILE`, `lock_profile->LOCK_PROFILE`; `HERIKA_RELATIONSHIPS` is unset. **Every non-empty key of `metadata` and of `extended_data` (except reserved `middle_term_memory, middle_term_enabled, individual_memory_enabled, background_life_goals, chim_core_migrated` and narrator-managed keys) is copied into `$GLOBALS[key]`** through `chimApplyOverrideValueToGlobals()` (`lib/settings.php:1083-1113`; keys containing `@` or space address nested arrays). Profile `metadata` is applied the same way first (`core_profiles.class.php:280-308`), NPC values win.
- Biography text for the prompt: `buildDynamicBiography($GLOBALS)` (`lib/data_functions.php:7230+`) + `BIOGRAPHY_BUILDER` hooks.

How a plugin reads the current speaker (VERIFIED APIs):

```php
$GLOBALS["HERIKA_NAME"]; $GLOBALS["PLAYER_NAME"];
$GLOBALS["CHIM_CORE_CURRENT_NPC_DATA"]      // full row array (main.php:489,629,680); unset for narrator-scoped requests (main.php:1596)
$GLOBALS["CHIM_CORE_CURRENT_PROFILE_DATA"]; $GLOBALS["CHIM_CORE_CURRENT_CONNECTOR_DATA"];
$npcMaster = new NpcMaster();
$npcMaster->getByName($npcName); ->getByMD5($md5Hash); ->getByRefId($refid); ->getById($id); ->getAll($where = "TRUE");
$npcMaster->getMetadata($row): array; ->getExtendedData($row): array;
$npcMaster->updateMetadataKeysByName(string $npcName, array $setValues = [], array $unsetKeys = []): bool   // jsonb_set per key
$npcMaster->updateExtendedKeysByName(string $npcName, array $setValues = [], array $unsetKeys = []): bool
$npcMaster->getNpcFactions(array $npcData, bool $activeOnly = true): array; ->isNpcInFaction($npcData, $factionFormId);
chimApplyNpcMetadataUpdatesByName(string $npcName, array $updates): bool      // lib/core/activity_status.php:223
herikaActionCatalogGetCurrentNpcLookup()  // ['npc_master','npc_data','metadata','extended'] for HERIKA_NAME, cached (action_catalog.php:1543-1577)
```
Generic key/value store: table `conf_opts(id text PK, value text)`; the game can write it with a `setconf` request (`key@value`).

---

## 6. manifest.json, discovery, settings UI, packaging

### 6.1 Discovery / enable / disable (VERIFIED)
- A plugin is any folder under `HS/ext/`; hook files are picked up by name (section 1.4). There is no registry and **no enable/disable switch**; the Plugins page only offers Delete (`rrmdir` of the folder) (`HS/ui/server_plugins.php:409-420`). `xLifeLink_plugin`, `herika_heal`, `time_awareness` are hidden from that page (`:431`).
- The Plugins page lists folders that contain `manifest.json` (`:453-456`). Remote catalogue: `HS/ui/data/plugin_repository.json` (`plugins.<id>` = `name, description, git_repo, github_url, mod_download_url, default_channel, channels{<id>:{label,branch,package_source,package_urls,allow_force,manifest_url}}, display_name, featured, icon`).

### 6.2 manifest.json keys read by the server (VERIFIED `ui/server_plugins.php:456-481, 507`)
`name`, `description`, `config_url`, `version`, `git_repo`, `mod_download_url` (supports `<version>` placeholder), `channel`, `default_channel`, `channels`, `display_name`, `featured`, `icon` (URL or path relative to the plugin folder), `schema_version` (`==2` enables the Update / Switch-channel buttons when `git_repo` is set). The modders guide additionally documents `config_url_target` (usually `_blank`). Shipped examples are minimal: `{"name","description","version"}` (`ext/herika_heal/manifest.json`, `ext/time_awareness/manifest.json`).

### 6.3 Settings UI (VERIFIED)
Yes: `config_url` makes the Plugins page show a "Plugin Page" button that opens that URL in a new tab (`server_plugins.php:505-506`); guide example: `"config_url":"/HerikaServer/ext/CHIM-Twitch-Bot/index.php"`. The page is ordinary PHP served by Apache from the plugin folder; it must bootstrap the DB itself. Core offers no settings framework for ext plugins - persist in `conf_opts`, own tables (migrations; core pre-creates a Postgres schema named `plugins` for this, `lib/runtime_bootstrap.php:142`), or files in the plugin folder (declare them in `server.mutable_paths` so package upgrades keep them). Separately, per-action settings can be exposed in the core Action Editor with `metadata.editor_fields` (section 2.2). `AGENTS.md` notes profile metadata with unknown keys is preserved "for plugin compatibility".

### 6.4 `ext/generic_installer.php` (GitHub installer) (VERIFIED `:75-104, 137-197, 226-330`)
- Called as `generic_installer.php?PACKAGE_NAME=<name>&GITHUB_REPO=<owner/repo>`; downloads `https://github.com/<repo>/releases/latest/download/<PACKAGE_NAME>.tar.gz` (fallback `.tar`) into `ext/<PACKAGE_NAME>/`.
- Version check: local `ext/<name>/manifest.json` `version` vs `manifest.json` at repo root (GitHub contents API), `version_compare`.
- Expects: release asset named exactly `<PACKAGE_NAME>.tar.gz`; release tag numeric (e.g. `1.0.3`); `manifest.json` with `version`; optional `migrations/*.sql` executed once each in alphabetical order, tracked in `public.plugin_migrations(plugin_name, migration_name, executed_at)` (DDL should be idempotent).
- Newer channel-aware flow goes through `ui/server_plugin_installer.php` (URL built at `server_plugins.php:395`; not analysed further).

### 6.5 `lib/plugin_package_manager.php` - `.dwpkg` packages pushed from the game side (VERIFIED)
- Class `DwemerPluginPackageManager`; `SCHEMA_VERSION = 4`; extensions `dwpkg`, `zip`; limits: 5000 entries, 1 GiB uncompressed, 512 MB archive, 1.5 MB upload chunk (`:11-17`).
- HTTP API `HS/ui/api/plugin_packages.php` (the literal `ui/api/plugin_packages.php` is present in `AIAgent.dll`): `?action=probe` POST `{name,version}` -> `{upload_required, reason: current|version_changed|not_installed}`; `start-upload` POST `{name,version,archive_name,size,total_chunks}`; `upload-chunk` POST raw body with `upload_id`, `index` (strictly in order); `status` GET `job_id`; `packages` GET.
- Archive must contain at top level `manifest.json`, `checksums.sha256`, and a non-empty `server/` tree; **any other path is rejected** ("Unsupported package payload"); no symlinks, no absolute/`..` paths, no backslashes (`:234-351`).
- `manifest.json` required keys: `schema_version` (must equal 4), `name` (`/^[A-Za-z0-9][A-Za-z0-9 ._-]{0,63}$/`, not ending in dot/space), `version` (`/^[0-9A-Za-z][0-9A-Za-z._+-]{0,63}$/`), `server` (object; optional `server.mutable_paths` = array of relative paths preserved across upgrades) (`:326-378`). "Uploaded package name does not match its game-side plugin folder" / "version does not match its game-side filename" (`:96-101`) => the game-side artefact is named by plugin folder + version.
- `checksums.sha256`: lines `<64 hex>  [*]<path>` covering every file except itself, no extras (`:390-421`).
- Activation: `server/` is moved to `HS/ext/<name>/` (previous version moved to `data/plugin_packages/backups/<job>/<name>`, rollback on failure), then `ext/<name>/migrations/*.sql` run inside one transaction, tracked in **`plugins.plugin_migrations`** (schema `plugins`, different from the generic installer's `public.plugin_migrations`) (`:444-538`). State files under `HS/data/plugin_packages/{archives,backups,failed,jobs,packages,staging,uploads}`. On this install that directory does not exist yet (no package ever installed).
- The third-party repo `Wondernuttz/Sharmat-Alpha` contains a `dwemer-package.json` at its root (seen on GitHub) - **[INFERENCE]** a build descriptor for this packaging flow; its format was not inspected.

---

## 7. Rate limiting / locking / queueing

VERIFIED:
1. **Global MAIN semaphore.** `if (!in_array($gameRequest[0],$fast_commands)) SemaphoreWait("MAIN", $semaphore_timeout /*300*/, 1003, null)` (`main.php:243-249`). Implementation: SysV `sem_get(abs(crc32("MAIN")))` (default max_acquire 1), non-blocking `sem_acquire` polled every ~51 ms, gives up after `SEMAPHORES_TIMEOUT` = 300 s and then `terminate()`s (`lib/semaphore_manager.class.php:42-89`). Released at `main.php:2939` or inside `terminate()`. => **every LLM-bound request (and every non-fast custom type, and `funcret`) is processed strictly one at a time, server-wide, FIFO-ish by polling luck.** A request stuck behind a 10-20 s generation simply waits.
2. Fast commands bypass the lock: `addnpc, addbgnpc, updateprofile, updateprofile_narrator, diary, diary_narrator, diary_player, _quest, setconf, request, _speech, infoloc, infonpc, infonpc_close, infoaction, status_msg, delete_event, itemfound, _questdata, _uquest, location, _questreset, chat, bleedout, waitstart, waitstop, util_location_name, util_faction_name, spellcast, npcspellcast, updateprofiles_batch_async, core_profile_assign, switchrace, combatbark, util_location_npc, enable_bg, region, named_cell, snqe, named_cell_static, player_menu_tts_prefetch, player_menu_tts_play, physics_raw` + `$GLOBALS["external_fast_commands"]` (`main.php:227-237`). `preprocessing.php` runs before the lock for all types.
3. **Player input pre-empts generation.** Player-input types (+`instruction`, `init`) insert a `user_input` eventlog row (`main.php:201-218`); while streaming, each loop iteration checks `select rowid as N from eventlog where type='user_input' and ts>$gameRequest[1] LIMIT 1` and on a hit closes the connector and `die('X-CUSTOM-CLOSE')` (`lib/data_functions.php:5989-5999`). Rechat waiting on the lock aborts the same way (`main.php:1228-1239`). => any plugin-originated LLM request in flight is killed the moment the player speaks (and note: `instruction` requests themselves count as "user input" and kill older generations).
4. Cooldowns: `diary` per NPC (`DIARY_COOLDOWN`, default 30; sample conf 120); `combatbark` global (`COMBAT_BARK_COOLDOWN`, 90 s); RPG comment events global hard 60 s (`RPG_COMMENT_LAST_TIMESTAMP`, types `combatend, combatendmighty, bleedout, rpg_lvlup, rpg_shout, rpg_word, rpg_soul, lockpicked, goodmorning` + bleedout instructions); `bored` chance `BORED_EVENT`; action `cooldown_seconds` (in-game time); rechat budget `RECHAT_H` rounds x `RECHAT_P` % with a 120 s temp-file state; `END_CONVERSATION_COOLDOWN=60`. All stored in `conf_opts`.
5. Duplicate suppression for `infonpc/infoloc/infonpc_close` (5 s) and `infoitems` (2 s) (`main.php:953-970`).
6. Outbound queue: table `responselog`; the game polls with `request`; `DataDequeue(time()+1)` claims rows atomically (`FOR UPDATE SKIP LOCKED`), rows can be scheduled in the future via `localts` (`lib/data_functions.php:874-907`, `scriptproxy_papyrus.php:872-887`).
7. LLM timeouts: `HTTP_TIMEOUT` (sample 15 s) but `max(HTTP_TIMEOUT, 30 | 90 for reasoning models)`; 60 s no-data watchdog in `process()` (`openrouterjson.php:285, 827, 945-950`). `set_time_limit(1200)`, `ignore_user_abort(true)` (`main.php:106-107`).
8. Apache prefork `MaxRequestWorkers 150`; PHP `max_execution_time 0`. No HTTP-level rate limiter was found. Each LLM reply also costs TTS generation per sentence inside `returnLines()` before the line is sent.
9. DLL side (string evidence only): `Preventing recursive rechat, depth limit reached`, `Task cancelled ...`, `No data received for {} seconds` => the client also cancels/limits; details belong to the game-side report.

---

## Implications for the glue (concrete recommendations)

1. **Register glue actions in the action catalog, not only in PHP.** Ship `Data/CHIM/<glue>_actions.csv` from the game-side mod (auto-imported as `custom_action_import`, versioned by `import_version`) or upsert from the ext plugin with `herikaActionCatalogUpsertCustomRow()`. Only catalog rows get: funcret follow-up replies, the `confirmcommand` channel, cooldowns, declarative requirements, Action-Editor toggles/fields. Use underscore display names (`Start_Intimacy` style is what the normaliser would produce anyway) and parameters named only `target` / `item` / `amount`.
2. **Layer the consent / adult hard gate at three independent points**: (a) offer-time: ext `functions.php` removes the code from `$GLOBALS["ENABLED_FUNCTIONS"]` unless the server-side preconditions hold (speaker is an NPC with a game-verified adult/eligible flag stored by the game in NPC `metadata`/`conf_opts`, listener is the player, request type is `inputtext`/`inputtext_s`, not rechat, not narrator); (b) post-LLM: a closure in `$GLOBALS["action_post_process_fnct_ex"][]` that re-checks and drops the wire line; (c) game-side: Papyrus re-validates before starting any scene, and set `metadata.confirmation.default_policy="ask"` so the DLL uses `confirmcommand`. The server has no age data, so the authoritative adult check must be game-side (race/child flag) and the server must default to "not eligible" when the flag is missing. Avoid reusing the code names `acceptsex, kiss, makelove, removeclothes, sexaction` (already claimed by another ecosystem in core's confirmation list) - use a unique prefix.
3. **Scene awareness**: push scene state from Papyrus with a log-only request type (anything starting with `info`, or `infoaction`) so it lands in context without an LLM call or the MAIN lock; keep the *current* scene snapshot in `conf_opts` via `setconf` or NPC `metadata` and inject it with `chimRegisterPromptInjection('prompt_bottom', 'glue.scene', callable)` from `globals.php`. Do not put volatile state into NPC `metadata` keys that collide with globals (every metadata key becomes `$GLOBALS[key]`) - namespace them (e.g. `GLUE_*`).
4. **Scene navigation as actions**: offer navigation actions only while a scene is active (gate in ext `functions.php` on the stored scene state; optionally `requirements.activity.current_action_in` if the game reports a custom `current_action`). Render the currently reachable graph edges into the action description or a prompt injection and pass the chosen edge in `target`; validate the edge again in the post-filter and in Papyrus.
5. **Throttled in-scene dialogue**: every LLM-bound request serialises on the MAIN semaphore and is killed by newer player input. Send at most one in-scene comment request at a time, only after the previous `X-CUSTOM-CLOSE`, with a game-side minimum interval; define a dedicated type via ext `prompts.php` (`$GLOBALS["PROMPTS"]["glue_scene_comment"] = ["cue"=>[...], "player_request"=>[...], "extra"=>["force_tokens_max"=>N]]`), and in `prerequest.php` switch that type to the profile's Fast slot (pattern in section 4) and keep actions off (default). High-frequency telemetry should be consumed in `preprocessing.php` + `terminate()` or registered in `$GLOBALS["external_fast_commands"]` so it never touches the lock.
6. **Menuless questing**: evaluate CHIM's built-in AI quest engine first (`$CHIM_AI_QUEST_PROGRESSION`, `lib/chim_quest_engine.php`, tables `skyrim_quest_definitions/instances/beat_state/events/action_outbox`, `gamedata.php` types `quest_event`, `quest_action_poll`, `quest_action_ack`, CSV import `traditional_quest_import`). It advances quests by queueing `set_stage` / `set_stage_cascade` actions that the game polls and acknowledges, selected by keyword triggers plus a low-temperature `fast_request` intent classifier with confidence threshold - i.e. it sets stages rather than executing real TopicInfo result scripts. The glue's "execute the real dialogue effects" approach is different; reuse its proven patterns: side-call classifier (`chimQuestEngineSelectDialogueBeatByIntent`), per-turn action suppression, outbox + ack protocol, `CHIM_PLAYER_ONLY_QUEST_ADVANCEMENT`-style guard. `just_say` can voice an exact vanilla line through the NPC's TTS without an LLM call. The ScriptProxy command set has no quest commands, so topic execution needs the glue's own Papyrus/SKSE side, triggered by a `plugin_command` action or a `responselog` `rolecommand` line.
7. **Packaging**: simplest dev loop = a folder `ext/<glue>/` with `manifest.json` (`name, description, version, config_url`). For distribution through MO2 use the `.dwpkg` flow (schema 4, `server/` payload, `checksums.sha256`, optional `server.mutable_paths`, `migrations/*.sql` -> `plugins.plugin_migrations`). Keep hook-named files only at the plugin root and never reuse those basenames in sub-folders.
8. **Robustness**: hook files run in function scope - always use `$GLOBALS`; guard every hook with a cheap early `return` when the request is irrelevant (hooks run for *every* request, including the ~1/s `request` poll for `globals.php`/`preprocessing.php`/`prerequest.php`); never `die()` without `terminate()` (lock release); remember post hooks do not run on terminated requests.

---

## Open questions

1. Which PHP endpoint does `AIAgent.dll` 3.3.2 use for `inputtext` / `instruction` / plugin-originated `requestMessage` calls - `streamv2.php` (actions on) or `comm.php` (actions off)? String evidence points to `streamv2.php` for `sendMsgStream`; only `comm.php` has been hit on this install so far. Confirm from the game-side analysis or by observing one spoken line in the Apache log. This decides whether NPC actions are offered at all for normal player speech and for which Papyrus API calls.
2. Exact client behaviour of `confirmcommand` / `approvedcommand` (UI shown, what is sent back on cancel, does a `funcret` follow) and of the `CHIM_CommandReceived` mod event for unknown code names - game-side topic.
3. Does setting `$GLOBALS["FUNCTIONS_ARE_ENABLED"]=true` in `prerequest.php` for a plugin-defined request type work end-to-end (the client must also accept command lines on that request)? Untested inference.
4. For `funcret` follow-ups with `use_functions_again`, the action list appears not to be advertised (flag raised after `json_response.php` ran). Confirm on a live request (`log/context_sent_to_llm.log`) before relying on chained actions.
5. How is NPC `metadata.activity_status.current_action` produced by the DLL, and can a mod inject a custom value (needed for declarative "in scene" requirements)? Otherwise gate procedurally.
6. `ui/server_plugin_installer.php` and the channel/`package_urls` flow, and the format of `dwemer-package.json`, were not analysed; needed only if the glue is to be listed in the CHIM plugin repository.
7. Prior art overlap: repository entry `aiagent_nsfw` ("SHARMAT", `Wondernuttz/Sharmat-Alpha`, OStim + SexLab, consent-driven, uses hooks `preprocessing, prerequest, context_pre, context, context_building, functions, prompts, postrequest`). Decide whether the glue should coexist with, depend on, or replace it; its action code names and request types were not inspected (not installed here; nothing was downloaded).
8. The quest engine's definition format, bundled definitions and LoreRim compatibility (is `CHIM_AI_QUEST_PROGRESSION` usable with modded quests?) - covered by another research topic; this report only establishes that it exists and how it is wired.
9. `conf_opts.chim_profile_model` is absent on this fresh DB (defaults to slot 1); which MCM/Prisma control writes it, and whether the player is expected to switch slots in-game, affects the "force Fast slot" recommendation.
