# HerikaServer 3.3.2 - event ingestion, storage, context, memory, DB schema

Research target: `/var/www/html/HerikaServer` inside WSL distro `DwemerAI4Skyrim3` (read through `\\wsl.localhost\DwemerAI4Skyrim3\var\www\html\HerikaServer`). Below, `HS/` means that root. Server version files: `HS/.version_number.txt` = `3.3.2`, `HS/.version.txt` = `2026090214`. All line numbers are from the files as they exist on this machine on 2026-09-21. DB facts come from read-only `psql` (`\d`, `SELECT`) with `default_transaction_read_only=on`. AIAgent.dll facts come from an ASCII strings dump of `F:\Modlists\LoreRim\mods\CHIM\SKSE\Plugins\AIAgent.dll` (file `dll_strings.txt` in SCRATCH, produced by a sibling agent; cited as `DLL-strings:<line>`).

Conventions: **VERIFIED** = copied from a source I opened. **INFERENCE** = my reasoning on top of verified facts.

## Executive summary (10 lines)

1. Every game request is `type|ts|gamets|data[|extra]` base64-encoded in the query string of `HS/comm.php` / `stream.php` / `streamv2.php`, which all `require main.php`; `main.php:135` does `explode("|", ...)`, so **payloads must never contain `|`**. Non-LLM types are handled in `processor/comm.php` (included at `main.php:1124`) and end with `$MUST_END=true`.
2. `ext/relationship_system/comm.php` is **not a hook**; it is a copy of the legacy entry point. The real ext hooks are files named `globals.php`, `preprocessing.php`, `prerequest.php`, `context_pre.php`, `context.php`, `context_building.php`, `prepostrequest.php`, `postrequest.php`, `prompts.php`, `dialogue_prompt.php`, `json_response_custom.php`, plus function files; all are loaded with `requireFilesRecursively()` = `require_once` **inside a function scope** (only `$gameRequest` is global there; use `$GLOBALS[...]` and `terminate()`).
3. Events live in `public.eventlog` (`type,data,sess,gamets,localts,ts,rowid,people,location,party,utterance_id,delivery_state`). An NPC sees a row only if its name is in the pipe-delimited `people` column (`|A|B|`). Helper: `logEvent($dataArray,$forcePeople='')`.
4. Context classification is in `buildHistoricContext()` (`lib/data_functions.php:2516`): `info*`→narrator context info; `ext_%`→subtype `PLUGIN` with **role = the type name** (dropped by the JSON connectors unless a plugin `context_building.php` rewrites the role to `user`); a fixed blacklist of types never reaches context.
5. `infoaction` is the only "free" narrative type that is fast-pathed, shown in normal context **and** in the rechat whitelist (`main.php:1355`), but it is **excluded from diaries** (`dynamic_update_util.php:1175`). `info_<x>` / `ext_<x>` types appear in diaries but not in rechat context.
6. Long-term memory does **not** read eventlog: view `memory_v` = `memory` table + `speech` table + eventlog rows of type `death`/`location` only. To get an OStim scene into summaries call `logMemory(...)` with a `(Context location: ...)` prefix; chatty events that are only `logEvent`-ed never pollute memory.
7. Structured data: `AIAgentFunctions.PostGameData(json)` → `HS/gamedata.php`, a **closed `switch`** on `type` (unknown type = HTTP 400, no ext hook). `sendNPCFast`→`addnpc|...`, `addBasicProfile`→`addbgnpc|...`, `scanActorsAroundOffline`→(inferred) gamedata `low_process_actors`, `sendFactionFast`→`util_faction_name|...`, `sendLocationFast`→`util_location_name|...`. Server already stores per NPC: gender, race, base, refid, skills, equipment, inventory, stats, factions(+rank), class(+training), mods, last_coords/location, activity status, relationships (extended_data). Vanilla relationship rank / marriage are NOT stored.
8. Server→game push exists and is simple: insert a row in `responselog` (`sent=0`); the game polls with `request` and receives `actor|action|text`. `SkyrimCommandBuilder::send()` (`lib/scriptproxy_papyrus.php:872`) wraps this as `rolecommand|ScriptProxy@{json}` (generic Papyrus RPC).
9. **CHIM 3.3.2 already ships an "AI Quest Progression" engine** (`lib/chim_quest_engine.php`, tables `skyrim_quest_*`, 301 bundled vanilla quest skeletons, DLL-side `[QuestProgression]` executor polling `gamedata.php` `quest_action_poll`). It is currently **disabled** (`general_settings.CHIM_AI_QUEST_PROGRESSION=false`). It sets stages directly (it does not run real TopicInfo result scripts) and covers only Skyrim.esm/Dawnguard/Dragonborn. The glue must coexist with it (keep it off, or reuse its outbox).
10. DB: PostgreSQL `host=localhost dbname=dwemer user=dwemer password=dwemer` (hard-coded at `lib/postgresql.class.php:8`; `conf/conf.php` is 0 bytes). 82 tables + 9 views in `public`, schema `plugins` (empty; reserved for plugin migrations), schema `chim_meta`. The DB is a fresh install (eventlog 14 rows, `core_npc_master` 0 rows).

---

## 1. Request types in `processor/comm.php`, and the ext hook contract

### 1.1 Transport and parsing (VERIFIED)

`HS/comm.php:1-12` (legacy entry point):
```php
$path = dirname((__FILE__)) . DIRECTORY_SEPARATOR;
$FUNCTIONS_ARE_ENABLED=false;
require($path . "main.php");
```
`HS/stream.php` is identical; `HS/streamv2.php` sets `$FUNCTIONS_ARE_ENABLED=true` for JSON connectors (`openai`, `openaijson`, `google_openaijson`, `web_connector`, `koboldcppjson`, `openrouterjson`) then requires `main.php`. DLL endpoint strings: `/HerikaServer/comm.php` (DLL-strings:45030), `stream.php` (45036), `streamv2.php` (47472), `gamedata.php` (44909).

`main.php:90-93` decodes the request:
```php
if (strpos($_SERVER["QUERY_STRING"],"&")===false)
    $receivedData = mb_scrub(base64_decode(substr($_SERVER["QUERY_STRING"],5)));
else
    $receivedData = mb_scrub(base64_decode(substr($_SERVER["QUERY_STRING"],5,strpos($_SERVER["QUERY_STRING"],"&")-5)));
```
`main.php:135-149`:
```php
$gameRequest = explode("|", $receivedData);
$GLOBALS["gameRequest"] = &$gameRequest;
...
$requestRoutingSnapshot = chimDecodePlayerRoutingSnapshotField($gameRequest[4] ?? "");
...
$gameRequest[0] = strtolower($gameRequest[0]); // Who put 'diary' uppercase?
```
So: `$gameRequest[0]`=type (lower-cased), `[1]`=ts, `[2]`=gamets, `[3]`=data, `[4]`=optional routing snapshot / CSV payload. `$_GET["profile"]` = md5 of the NPC name (`NpcMaster::create` sets `md5($data["npc_name"])`, `lib/core/npc_master.class.php:454`; lookup `getByMD5`, `:481`). **A `|` inside the data field truncates it** (INFERENCE from the explode; consistent with the comment at `processor/comm.php:1541` "You cannot use | as separator, because it's already used as primary request separator").

Order of processing in `main.php` (VERIFIED):

| main.php line | step |
|---|---|
| 54 | `requireFilesRecursively(__DIR__."/ext/","globals.php");` |
| 154-163 | `updateequipment/updateinventory/updateskills/updatestats` → prints `DEPRECATED...` and `exit` (so the comm.php branches for them are dead code) |
| 193 | ext `preprocessing.php` hooks |
| 197-199 | `physics_raw` → `terminate()` unless an extension renamed it in preprocessing |
| 201-224 | for `inputtext, inputtext_s, ginputtext, ginputtext_s, narrator_inputtext, instruction, init` insert an eventlog row `type='user_input'` (abort marker) |
| 227-237 | `$fast_commands` list; merged with `$GLOBALS["external_fast_commands"]`; non-fast types block on `SemaphoreWait("MAIN", ...)` (`:243`) |
| 276 | `processor/misc.php`: `delete_event`, `biography_import`, `oghma_import`, `dynamic_oghma_import` (each `terminate()`s) |
| 343-775 | profile loading from `$_GET["profile"]` (sets `$GLOBALS["HERIKA_NAME"]`, `CHIM_CORE_CURRENT_NPC_DATA`, `CHIM_CORE_CURRENT_PROFILE_DATA`, `CHIM_CORE_CURRENT_CONNECTOR_DATA`) |
| 867 | `diary` |
| 911-935 | `npcspellcast` → optional `logEvent`, `terminate()` |
| 937-944 | `ext_held_item_raw`, `ext_vr_item_raw` → `HeldItems::processEventRequest` → `logEvent(...)`, `terminate()` |
| 949-985 | **log-only types**: `info, infonpc, infonpc_close, infoloc, infoitems, chatme, chat, infoaction, death, itemfound, travelcancel, infoplayer, status_msg, util_npcname, bleedout, spellcast, backgroundaction, reanimate, itempickup, npc_reanimated, region` → dedupe (`infonpc/infoloc/infonpc_close` 5 s, `infoitems` 2 s) → `logEvent($gameRequest)` (or `processor/background_event.php` for `backgroundaction`/`npc_reanimated`) → `terminate()` |
| 988-994 | `playerinfo`, `newgame` → `logEvent`, `terminate()` |
| 998-1034 | `bored` |
| 1037-1074 | `combatbark` (cooldown; logs an `infoaction`) |
| 1117 | ext `prerequest.php` hooks |
| **1124** | `require(__DIR__.DIRECTORY_SEPARATOR."processor".DIRECTORY_SEPARATOR."comm.php");` |
| 1510-1524 | `if ($MUST_END) { echo 'X-CUSTOM-CLOSE'.PHP_EOL; ... terminate(); }` |
| 1615 | `prompt.includes.php` (prompts, ext `prompts.php`, functions, ext function files) |
| 1708 | `processor/request.php` (builds `$request` cue from `$PROMPTS[type]`) |
| 1863-1943 | generic eventlog insert of the LLM-triggering request (all types except `diary`, `cheatmode`) |
| 2037-2064 | historic context + short-term memory |
| 2540 / 2648 | ext `context_pre.php` / `context.php` hooks |
| 2944-2946 | ext `prepostrequest.php`, `processor/postrequest.php`, ext `postrequest.php` |

`$fast_commands` (`main.php:227-231`), copied:
```php
$fast_commands = ["addnpc","addbgnpc","updateprofile","updateprofile_narrator","diary","diary_narrator","diary_player","_quest","setconf","request","_speech","infoloc","infonpc","infonpc_close",
    "infoaction","status_msg","delete_event","itemfound","_questdata","_uquest","location","_questreset","chat","bleedout","waitstart","waitstop",
    "util_location_name","util_faction_name","spellcast","npcspellcast","updateprofiles_batch_async","core_profile_assign","switchrace","combatbark",
    "util_location_npc","enable_bg","region","named_cell","snqe","named_cell_static","player_menu_tts_prefetch","player_menu_tts_play",
    "physics_raw"];
if (isset($GLOBALS["external_fast_commands"])) {
    $fast_commands = array_merge($fast_commands, $GLOBALS["external_fast_commands"]);
}
```

### 1.2 Every type handled in `processor/comm.php` (VERIFIED; line = start of branch)

`$MUST_END = false;` is reset at `comm.php:7`. Matching is `==` unless "prefix" is noted (`strpos($gameRequest[0], "x") === 0`).

| line | type | what it does |
|---|---|---|
| 104 | `init` | Game load. Ignores gamets `10000000`. Dragon-Break snapshot, then deletes rows with `gamets>=` load point from `eventlog, speech, currentmission, diarylog, books, actions_issued, moods_issued, rumors, named_cell, sneq_quests_saved, bgl_history, memory_summary, memory`; clears `responselog`, `rolemaster`; `chimQuestEngineResetRuntime(true)`; stores `conf_opts.plugin_dll_version`; `NpcMaster->restoreNPC(gamets)`; clears `relationship_eval_queue/init_queue`; `SNQEQuestManager::load_quests`; optional narrator welcome. |
| 301 | `wipe` | New game: deletes everything from `eventlog, quests, speech, currentmission, diarylog, books, memory_summary, memory`. |
| 367 | `request` | Poll. `DataDequeue(time()+1)` → echoes `actor|action|text\r\n` for each `responselog` row with `sent=0`. Logs itself every ~5 s. |
| 381 | `_quest` | JSON quest snapshot → `quests` table (see section 5). |
| 410 | `_uquest` | `@`-split quest update → `questlog` insert + `syncQuestWithOghma()`. |
| 435 | `_questdata` | `id@briefing2` → `quests.briefing2`. |
| 451/456/538 | `updateequipment`, `updateinventory`, `updateskills` | deprecated (never reached, see main.php:154). |
| 461 | `itemtransfer` | Parses `"A gave N Item to B"`; adjusts `metadata.inventory` of both NPCs. |
| 543 | `updatestats` | `name@level@hp@hpmax@mp@mpmax@sp@spmax@scale` → `metadata.stats` (never reached, main.php:154). |
| 583 | `_questreset` | `delete from quests`. |
| 589 | `_speech_abort` | JSON `{utterance_ids:[]}` → `eventlog.delivery_state='aborted'` for matching `chat` rows. |
| 626 | `_speech` | JSON spoken-line report → insert into `speech`; marks matching `chat` eventlog rows `delivery_state='spoken'`; whisper-mode conf_opts. |
| 964 | `book` | insert `books(title)` + eventlog. |
| 991 | `contentbook` | insert `books(content)` + eventlog. |
| 1019 | `togglemodel` | `DMtoggleModel()`, echoes `command|ToggleModel@...`. |
| 1041 | `death` | no-op (and `death` is already consumed at main.php:949). |
| 1045 | `quest` | human-readable quest notification; filters; `logEvent`; optional narrator quest comment (`narrator_quest_comment`). |
| 1136 | `location` | sets `CACHE_LOCATION`, `logEvent`. |
| 1141 | `force_current_task` | insert into `currentmission`. |
| 1155 | `recover_last_task` | delete last `currentmission`. |
| 1162 | `player_menu_tts_prefetch`, `player_menu_tts_play` | TTS of the vanilla dialogue-menu line; echoes `Player|ScriptQueue|...//__player_menu_tts///1.0`. |
| 1219 | `just_say` | `returnLines([trim($gameRequest[3])])` (TTS a literal line as current NPC). |
| 1225 | `playerdied` | rollback to last `infosave` (note: selects column `gamets` but tests `["ts"]` at `:1240-1241`, so the body never runs - minor upstream bug). |
| 1281 | `setconf` | `key@value` → `conf_opts` upsert; special `chim_renamenpc@old@new@formid`. |
| 1331 | prefix `infosave` | `logEvent`; `backupAllNpcs(gamets)`; `SNQEQuestManager::save_quests`. |
| **1343** | **prefix `info`** | `logEvent($gameRequest); $MUST_END = true;` - any type starting with `info` not caught earlier. |
| 1349 | prefix `npcvoice_refresh` | `name@refid@voiceid` → `core_npc_master.voiceid`. |
| 1403 | prefix `addnpc` / `addbgnpc` | NPC registration (see section 3). |
| 1788 | prefix `util_location_name` | `/`-split location record → `locations`. |
| 1851 | prefix `util_faction_name` | `formid/name/vendorContainer` → `factions` (`__CLEAR_ALL__`, `__VANILLA_SYNC__`). |
| 1878 | prefix `util_location_npc` | NPC coordinates → `metadata.last_coords`. |
| 2028 | prefix `enable_bg` / `disable_bg` | `extended_data.background_life_enabled`. |
| 2066 | prefix `updateprofile_narrator` | narrator dynamic profile. |
| 2089 | prefix `updateprofiles_batch_async` | queue dynamic profile batch. |
| 2169 | prefix `updateprofile` | legacy single dynamic profile. |
| 2390 | prefix `waitstart` | `conf_opts.last_waitstart`; `processAutoDiary($gameRequest,"waitstart")`. |
| 2411 | prefix `goodnight` | eventlog insert; `processAutoDiary($gameRequest,"goodnight")`. |
| 2434 | prefix `waitstop` | inserts `info_timeforward` ("N hours have passed. Current date/time: ..."). |
| 2455 | prefix `diary_narrator` | `generateFollowerDiary("The Narrator", ...)`. |
| 2483 | prefix `diary_player` | `generatePlayerDiary(...)`. |
| 2521 | prefix `diary_nearby` | `processNearbyDiary($gameRequest,"manual_nearby")`. |
| 2529 | prefix `core_profile_assign` | assign profile slot to NPC. |
| 2553 | prefix `named_cell_static` | statics list → `named_cell`. |
| 2586 | prefix `named_cell` | cell/door record → `named_cell`. |
| 2625 | prefix `switchrace` | `logEvent`. |
| 2632 | prefix `snqe` | `START/END/CLEAN/RESTART` of the SNQE (AI-generated side quest) agents. |
| 2722/2750 | (flags) | `TRIGGER_NARRATOR_WELCOME` / `TRIGGER_NARRATOR_QUEST_COMMENT` rewrite `$gameRequest[0]` and set `$MUST_END=false`. |

Any type **not** matched anywhere falls through to the LLM pipeline (needs `$PROMPTS[type]["cue"]`, else `processor/request.php:174` logs "Request cue is empty!" and uses `TEMPLATE_DIALOG`).

### 1.3 The ext hook contract (VERIFIED)

`HS/ext/relationship_system/comm.php` content (whole file, 15 lines) is just the legacy entry point:
```php
/* Legacy entry point */
$path = dirname((__FILE__)) . DIRECTORY_SEPARATOR;
require_once($path . "conf/conf.php");
$FUNCTIONS_ARE_ENABLED=false;
require($path . "main.php");
```
There is **no** `requireFilesRecursively(..., "comm.php")` call anywhere in the server (grep over all `*.php`). A "comm hook" does not exist in 3.3.2.

Loader (`lib/data_functions.php:7729-7747`):
```php
function requireFilesRecursively($dir,$name) {
    global $gameRequest;
    $files = scandir($dir);
    foreach ($files as $file) {
        ...
        if (is_dir($path)) {
            requireFilesRecursively($path,$name);
        } elseif (is_file($path) && $file === $name) {
            require_once($path);
        }
    }
}
```
Consequences (INFERENCE from PHP semantics, consistent with how shipped plugins are written): hook files execute in the function's local scope; `$gameRequest` is the only imported global (it is a reference to `$GLOBALS["gameRequest"]`, so assigning `$gameRequest[0]` works); everything else must go through `$GLOBALS[...]` (`$GLOBALS["db"]`, `$GLOBALS["HERIKA_NAME"]`, `$GLOBALS["COMMAND_PROMPT"]`...). `$MUST_END` is not reachable - a hook that fully handles a request must call `terminate()` (`lib/auditing.php:26`: echoes `X-CUSTOM-CLOSE`, flushes, releases semaphores `MAIN/ADDNPC/VSX`, `die()`). Because of `require_once`, each hook file runs at most once per PHP request (relevant for `context_building.php`, which is triggered from `replaceRoles()` and therefore only on the first `DataLastDataExpandedFor()` call of a request).

All hook file names and their include sites:

| hook file name | include site | purpose / what is available |
|---|---|---|
| `globals.php` | `main.php:54` (also several `ui/*` scripts) | earliest; DB not yet in `$db` local but `$GLOBALS["db"]` exists (bootstrap `lib/runtime_bootstrap.php:210`). Register `$GLOBALS["external_fast_commands"]`, prompt injections, actor enrichers, `EXT_CONTEXT_SQL_FILTER1/2`. Example: `ext/xLifeLink_plugin/globals.php`. |
| `preprocessing.php` | `main.php:193` | after DB connect + chim modes, before fast/semaphore decision. Can rename `$gameRequest[0]` / rewrite `[3]` (the `physics_raw` comment at `main.php:195-199` documents this pattern). |
| `prerequest.php` | `main.php:1117` | after profile load (`HERIKA_NAME` valid), right before `processor/comm.php`. Example: `ext/xLifeLink_plugin/prerequest.php`. |
| `prompts.php` | `prompts/prompts.php:285` | add/override `$GLOBALS["PROMPTS"][type]`. |
| `dialogue_prompt.php` | `prompts/dialogue_prompt.php:370` | dialogue template overrides. |
| `functions.php` | `functions/functions.php:2754` `requireFunctionFilesRecursively($folderPath)` (defined `:2688`, matches file name `functions.php`, `require_once`, also function scope) | action definitions (`$GLOBALS["FUNCTIONS"][]`, `ENABLED_FUNCTIONS`, `F_NAMES`, `F_TRANSLATIONS`, `FUNCSERV`, `FUNCRET`, `PROMPTS["afterfunc"]["cue"][code]`); example `ext/herika_heal/functions.php` (fully commented out). Code-name prefixes `ExtCmd*` (result from Papyrus via `funcret`) / `WebCmd*` (result computed on server), `ext/herika_heal/functions.php:7-9`. |
| `json_response_custom.php` | `functions/json_response.php:81` | custom JSON response schema. |
| `context_building.php` | `lib/data_functions.php:3217` (inside `replaceRoles`) | mutate `$GLOBALS["CONTEXT_BUILDING_DATA"]` (the final historic window array of `['role','content','_g']`). |
| `context_pre.php` | `main.php:2540` | before system prompt assembly; mutate `$GLOBALS["HERIKA_PERS"]`, `$GLOBALS["COMMAND_PROMPT"]`, `$GLOBALS["PROMPT_NEARBY_SECTIONS"]`. Example: `ext/relationship_system/context_pre.php:192,208`. |
| `context.php` | `main.php:2648` | after `$head[]` system prompt is built; example `ext/time_awareness/context.php` mutates `$GLOBALS["request"]`. |
| `prepostrequest.php` | `main.php:2944` | after response was streamed and semaphores released. |
| `postrequest.php` | `main.php:2946` | after `processor/postrequest.php`. Example: `ext/relationship_system/postrequest.php`. |

Prompt-injection API (`lib/prompt_injections.php`), rendered at `main.php:2553-2566` and placed into the system prompt at `main.php:2597-2600`:
```php
function chimRegisterPromptInjection(string $slot, string $id, $content, int $priority = 100): bool   // :10
function chimRenderPromptInjections(string $slot, array $context = []): string                         // :59
function chimRegisterActorProfileEnricher(string $id, callable $callback, int $priority = 100): bool   // :87
function chimBuildActorProfileEnrichmentText(string $actorName, string $actorType, array $context = []): string // :107
```
Slots actually rendered: `"character_bottom"` (inside `<character>`, `main.php:2554`) and `"prompt_bottom"` (after nearby sections, `main.php:2565`). `$content` may be a string, array of strings, or `callable($slot, $context)`; context keys: `game_request, herika_name, narrator_name, player_name` (`main.php:2547-2552`). Actor enrichers are called as `callback($actorName, $actorType, $context)` and return string|array; used for the player at `lib/data_functions.php:1085` with `$actorType="player"`, `["source"=>"nearby_actors"]`, gated by prompt context option `enabled_nearby_actor_subsections/custom_state`.

Plugin packaging (`lib/plugin_package_manager.php`): class `DwemerPluginPackageManager`, `SCHEMA_VERSION = 4` (`:13`), archive `.dwpkg`/`.zip` containing `manifest.json` (required keys `name`, `version`, `server`; `server.mutable_paths` array; `schema_version` must equal 4 - `:326-351`), `checksums.sha256`, payload under `server/` which is moved to `HS/ext/<name>` (`:444-481`); `server/migrations/*.sql` run once each inside a transaction and are tracked in `plugins.plugin_migrations(plugin_name, migration_name, executed_at)` (`:501-538`). Shipped ext folders only carry a simple `manifest.json` `{name, description, version}` (e.g. `ext/time_awareness/manifest.json`). Manual install = drop a folder in `HS/ext/`.

---

## 2. Event storage and how events become LLM context

### 2.1 `eventlog` (VERIFIED via `\d public.eventlog`)

```
type           character varying(128)
data           text
sess           text
gamets         bigint   not null
localts        bigint   not null
ts             bigint
rowid          bigint   not null default nextval('eventlog_rowid_seq')
people         text
location       text
party          text
utterance_id   text
delivery_state text
PK eventlog_primary(rowid); btree(type); btree(delivery_state); btree(gamets) WHERE gamets>0;
btree(gamets DESC, ts DESC); gin(people gin_trgm_ops); gin(data gin_trgm_ops); btree(utterance_id)
```
View `eventlog_view` adds `sk_date, sk_long_date, sk_days, gregorian_date` via SQL functions `convert_gamets2skyrim_date(gamets)` etc. Time unit: 1 gamets tick = `0.0000024` game hours (used everywhere, e.g. `comm.php:2438`).

Writers:
* `logEvent($dataArray,$forcePeople='')` - `lib/chat_helper_functions.php:5345-5453`. `$dataArray = [0=>type, 1=>ts, 2=>gamets, 3=>data, 4=>sess (default 'pending'), 5=>array of extra columns]`. Fixes `gamets<5` with `DataLastKnownGameTS()`. Normalises type with `chimNormalizeLoggedEventType()` (`lib/core/event_type.php:11`; only effect: `chat` + "background chat)" marker → `chat_background`). Fills `people` = `$forcePeople` if given, else scoped via `buildScopedPeopleForEvent(type, data, HERIKA_NAME, fallback)` (`:5247`); fills `location` from `$GLOBALS["CACHE_LOCATION"]`/`DataLastKnownLocation()` and `party` from `DataGetCurrentPartyConf()`.
* `main.php:1924-1940` generic insert for LLM-triggering requests (`sess` = `'web'`/`'cli'`).
* direct `$db->insert('eventlog', ...)` in `processor/comm.php` (init, wipe, book, goodnight, `info_timeforward`...), `gamedata.php:603` (`itemfound`).

Real rows on this machine (fresh DB): `infonpc_close` data `Shazdehviir//Jay` people `|Shazdehviir|Jay|`; `infoplayer` data `level:1,name:"Jay",race:"Nord",gender:"male"`; `infoitems` data `(items in range:0x92317E94:0x64B3B:Roasted Salmon Fillet,...`; `itemfound` data `Jay found 5 Soul Gem I - Petty`.

People scoping matters: `isStrictSpatialPeopleModeEnabled()` returns `true` unconditionally (`chat_helper_functions.php:4385-4390`); for an unknown type, `buildScopedPeopleFromSpatialEvidence()` (`:4940`) tries to extract participant names from the text, else falls back. **Always pass `$forcePeople`** (format `|Name1|Name2|`) for plugin events so the right NPCs (and only they) see them.

### 2.2 Pipeline from eventlog to prompt (VERIFIED)

`main.php:2037-2064`:
```php
if (($GLOBALS["HERIKA_NAME"]=="The Narrator"))
    $contextDataHistoric = DataLastDataExpandedFor("", $lastNDataForContext * -1,$sqlfilter);
else ...
    $contextDataHistoric = DataLastDataExpandedFor("{$GLOBALS["HERIKA_NAME"]}", $lastNDataForContext * -1,$sqlfilter);
...
$contextDataHistoric = chimAttachShortTermMemoryToWindow($contextDataHistoric, $GLOBALS["HERIKA_NAME"], $sqlfilter, ...);
$contextDataWorld = DataLastInfoFor("", -2,true);
```
`$lastNDataForContext` = `CONTEXT_HISTORY` (conf_opts value here: `50`; diary uses `CONTEXT_HISTORY_DIARY`=`100`).

`DataLastDataExpandedFor($actor, $lastNelements = -10,$sqlfilter="")` (`lib/data_functions.php:3225`) = `buildHistoricContext` (`:2516`) → `compactHistoricContext` (`:2975`) → `replaceRoles` (`:3158`, which fires the `context_building.php` hook and sets `$GLOBALS["CONTEXT_WINDOW_FLOOR"]`).

`$sqlfilter`:
* default (`main.php:1361`): `" and type<>'prechat' "`.
* for `rechat` and `narration` (`main.php:1355`) a **whitelist**:
```php
$sqlfilter=" and (type in ('prechat','inputtext','ginputtext','infonpc','infonpc_close','logaction','infoaction','death','itemfound','innerchat') or (type='chat' and {$visibleChatStateSql} and data like '(Context%') )";
// chat entries starting by "(Context%" are standard skyrim dialogue
```
* diaries (`lib/dynamic_update_util.php:397` player, `:1175` NPC): `" and type<>'prechat' and type<>'itemfound' and type<>'infoaction' and type<>'npcspellcast' "`; nearby diary (`:110`): `" and type<>'prechat'"`.

The SQL in `buildHistoricContext` (`lib/data_functions.php:2551-2605`), copied:
```sql
case
  when type='infoaction' and a.data like '#%MEMORY%' then 'MEMORY'
  when type='infoaction' and a.data like '<memory>%' then 'MEMORY'
  when type like 'info%' or type like 'funcret%' or type like 'location%' then 'CONTEXTI'
  when a.type='chat_background' or a.data like '%background chat%' then 'BACKDIAG'
  when type='book' then 'BOOKEVT'
  when type='contentbook' then 'BOOKEVT'
  when type='quest' then 'QUEST'
  when type='itemfound' then 'ITEM'
  when type='rpg_word' then 'RPG_WORD'
  when type='rpg_lvl' then 'RPG_LVL'
  when type='rpg_shout' then 'RPG_SHOUT'
  when type='death' then 'RPG_DEATH'
  when type='welcome' then 'RPG_SPAWN'
  when type='bleedout' then 'RPG_DEFEAT'
  when type='waitstart' then 'CONTEXTI'
  when type='waitstop' then 'CONTEXTI'
  when type='spellcast' then 'CONTEXTI'
  when type='npcspellcast' then 'CONTEXTI'
  when type='reanimate' then 'CONTEXTI'
  when type='info_timeforward' then 'TIMELAPSE'
  when type='backgroundaction' then 'CONTEXTI'
  when type='innerchat' then 'BGLCHAT'
  when type='ext_held_item_pickup' or type='ext_held_item_drop' then 'HELD_ITEM'
  when type like 'ext_%' then 'PLUGIN'
  else ''
end as subtype, a.data as data, gamets, localts, type, location
FROM eventlog a WHERE
type<>'combatend'
and type<>'bored' and type<>'init' and type<>'infoloc' and type<>'info' and type<>'funcret' and type<>'book'
and type<>'addnpc' and type<>'infonpc' and type<>'infoitems'
and type<>'updateprofile' and type<>'rechat' and type<>'setconf' and  type<>'status_msg'  and type<>'user_input'
and type<>'infonpc_close' and type<>'instruction'
and type<>'request' and type<>'playerinfo' and type<>'im_alive' and type<>'region' and type<>'named_cell'
AND type<>'narrator_welcome'
and (type<>'chat' or {$visibleChatStateSql})
AND type<>'funccall' AND type<>'togglemodel'
{$removeBooks} {$sqlfilter} {$ext_sqlfilter1}
AND ( people like '%|$actorEscaped|%' or people like '$actorEscaped'
   or people like '%|$actorEscaped (busy)|%' or people like '%|$actorEscaped (hostile)|%'
   or people like '%|$actorEscaped (in combat)|%' or people like '%|$actorEscaped (restrained)|%'
   or type='info_timeforward' )
{$ext_sqlfilter2}
or (type='ext_held_item_pickup' or type='ext_held_item_drop')
ORDER BY gamets desc, ts desc, rowid desc LIMIT {$nRecordsLimit} OFFSET 0
```
with `$ext_sqlfilter1 = $GLOBALS["EXT_CONTEXT_SQL_FILTER1"] ?? ""`, `$ext_sqlfilter2 = $GLOBALS["EXT_CONTEXT_SQL_FILTER2"] ?? ""` (`:2538-2539`) - **plugin-settable SQL fragments**; `$nRecordsLimit = 32 + (2 * abs($lastNelements))` (`:2524`); `$removeBooks = "and type<>'contentbook' "` unless request is `chatnf_book`. Post-filters: `$GLOBALS["EVENT_TYPE_FILTER"]` (general setting, comma list) via `chimFilterRowsByEventType` (`:2616`), and `herikaShouldExcludeEventFromPromptContext()` (`:2464`; drops CSV-import types, `npcvoice_refresh`, `status_msg` starting `csv_import@`).

Role assignment (`:2712-2803`), in order: `logaction` rows are skipped unless JSON `character` == current NPC, then role `assistant`; `vision`→`user`; subtype `MEMORY`→`memory` (only the most recent one is kept, `:2917-2935`); data starting `"<HERIKA_NAME>:"`→`assistant`; data starting `"<PLAYER_NAME>:"`→`player`; `"The Narrator:"` + type `chat`→`narratorchat`; `BACKDIAG`/`BGLCHAT`→`backgroundchat`; `BOOKEVT`, `CONTEXTI`, `QUEST`, `ITEM`, `RPG_*`→`narratorci` (CONTEXTI rows containing `"should not be visible"` are skipped; `RPG_DEATH`, `RPG_DEFEAT`, `TIMELAPSE` are upper-cased); `HELD_ITEM`→`narratorci` with suffix `" Holding it in hand (held item)"` and only the newest pickup within the first 5 rows survives (`:2636-2656`); **`PLUGIN` → `$speaker = $row["type"];`** (`:2796-2797`); everything else → `npc`. Also injected: location-change lines (`LOCATION CHANGE ...`), time-jump lines (`>5 h` minor timelapse, `>36 h` "A MAJOR TIME JUMP HAS OCCURRED"), optional `--- <time category> ---` dividers when `PROMPT_TIMESTAMP` is on; consecutive `info_timeforward` rows are merged; `(Context location: ...)` is stripped from data (`:2684-2686`). Then `consolidateEvents()` (`:2184`) collapses repeated events, and `HIDE_AMBIENT_COMBAT` drops `death` rows containing "has killed".

`replaceRoles()` (`:3158-3189`) maps `player`, `npc*`, `backgroundchat`, `narratorci`, `narratorchat`, `narratorloc` → `user`. **It does not touch other roles**, so a `PLUGIN` row keeps role `ext_<type>`. In `connector/openrouterjson.php:409-527` only roles `system`, `user`, `assistant`, `tool` are copied into the outgoing message list - there is no `else` branch - so an untouched `ext_*` role entry is silently dropped (unless it happens to be the very last element, `:391-394`). INFERENCE: the intended contract is that the owning plugin rewrites these entries in its `context_building.php` hook (`$GLOBALS["CONTEXT_BUILDING_DATA"]`), which also lets it collapse/format them (the same idea core uses for `HELD_ITEM`). Diaries are not affected by the role: `generateFollowerDiary` concatenates `content` of every entry (`dynamic_update_util.php:1182-1186`).

The "world" block: `DataLastInfoFor("", -2, true)` (`lib/data_functions.php:977`) builds the nearby-actors section from the newest `infonpc_close` event (`DataBeingsInCloseRange()`, `:4622-4675`, parses `A/B/C` or `|A|B|`, strips state suffixes like `(busy)`, skips dead/disabled and a list of animals) plus each NPC's `core_npc_master` profile/metadata.

Legacy `DataLastDataFor($actor, $lastNelements = -10)` (`:909`) still exists (used by older paths); it prefixes `'The Narrator:'` for `info%`, `death%`, `funcret%`, `location%`, `chat_background` and excludes `combatend, bored, init, lockpicked, infonpc, infoloc, infoitems, info, funcret, quest, user_input, funccall, togglemodel`.

### 2.3 Summary table: type prefix → visibility

| type | normal NPC context | rechat/narration context | NPC/player diary context | `memory_v` (long-term) | fast (no MAIN semaphore) | handled without plugin code |
|---|---|---|---|---|---|---|
| `infoaction` | yes (`narratorci`) | **yes** (whitelisted) | **no** (`type<>'infoaction'`) | no | yes | yes (main.php:949) |
| `info_<custom>` (any `info*` not blacklisted) | yes (`narratorci`) | no | yes | no | no, unless added to `external_fast_commands` | yes (comm.php:1343) |
| `info`, `infoloc`, `infonpc`, `infonpc_close`, `infoitems` | **no** (blacklisted; used only for world/nearby block) | whitelisted for `infonpc*` but still killed by the blacklist | no | no | yes | yes |
| `ext_<custom>` | only if plugin rewrites role in `context_building.php` | no | yes (content only) | no | only via `external_fast_commands` | **no** - unknown types fall into the LLM path, plugin must intercept |
| `chat` (NPC line; vanilla lines start with `(Context location:`) | yes if `delivery_state` visible | only vanilla `(Context%` lines | yes | via `speech`, not eventlog | yes | yes |
| `logaction` | yes, only for the acting NPC (`assistant`) | yes | yes | no | n/a (server-generated) | - |
| `death`, `location` | yes | `death` yes | yes | **yes** (only eventlog types in `memory_v`) | `location` yes | yes |
| `itemfound` | yes | yes | no | no | yes | yes |
| `quest` | yes (`QUEST`→`narratorci`) | no | yes | no | no | yes |
| `user_input`, `request`, `rechat`, `instruction`, `status_msg`, `setconf`, `addnpc`, `playerinfo`, `init`, `funcret`, `funccall`, `book`, `bored`, `combatend`, `region`, `named_cell`, `im_alive`, `togglemodel`, `updateprofile`, `narrator_welcome` | never | never | never | no | - | - |

### 2.4 Memory and diary (VERIFIED)

Tables: `memory(speaker,message,session,uid serial,listener,localts,gamets,momentum,rowid,event varchar(64),ts)`, `memory_summary(gamets_truncated,n,packed_message,summary,classifier,uid,rowid,embedding vector(384),companions,embedding768 vector(768),tags,scope,native_vec tsvector)`, `diarylog(ts,sess,topic,content,tags,people,localts,location,gamets,rowid)`, `speech(sess,speaker,speech,location,listener,topic,localts,gamets,ts,rowid,companions,audios,utterance_id,mood,emotion,emotion_intensity)`.

View `memory_v` (definition from `\d+`):
```sql
SELECT memory.message, memory.uid, memory.gamets, '-' AS speaker, '-' AS listener, memory.ts
  FROM memory
 WHERE memory.message !~~ 'Dear Diary%' AND memory.message <> '' AND memory.event <> 'backgroundlife_diary'
UNION
SELECT '(Context Location:' || speech.location || ') ' || speech.speaker || ': ' || speech.speech,
       speech.rowid::integer, speech.gamets, speech.speaker, speech.listener, speech.ts
  FROM speech WHERE speech.speech <> ''
UNION
SELECT eventlog.data, eventlog.rowid::integer, eventlog.gamets, '-', '-', eventlog.ts
  FROM eventlog WHERE eventlog.type = ANY (ARRAY['death','location'])
ORDER BY gamets, ts;
```
Writer for `memory`: `logMemory($speaker, $listener, $message, $momentum, $gamets,$event,$ts)` (`lib/chat_helper_functions.php:1955`). Existing `event` values in code: `diary_intent` (`processor/request.php:98-100`), `first_met` (`data_functions.php:6530`), `nearby_diary`, `player_diary`, `auto_diary` (`dynamic_update_util.php:211,544,1283`), plus `diary`, `backgroundlife_diary` (queried at `data_functions.php:4149`).

`speech` is written only from `_speech` requests (`comm.php:675-691`), JSON fields read: `listener, speaker, speech, location, companions[], audios, utterance_id, debug, distance, spatial_volume, spatial_reason`. The DLL sends `_speech|{}|{}|{}` (DLL-strings:46805) for both AI lines and vanilla lines (`debug` = `traditional_player_speech` / `traditional_npc_speech`, DLL-strings:48627/48635; vanilla lines are also posted as `chat|{}|{}|(Context location: {}){}: {} ({} {})`, DLL-strings:48625, from a `RE::TESTopicInfoEvent` sink, DLL-strings:48612).

Summarisation: `PackIntoSummary($onlyMissingDiary=false)` (`data_functions.php:4135-4274`) groups `memory_v` rows newer than the last summary into "queues" cut by (a) time bucket `round(gamets/($pfi))` where `$pfi = AUTO_CREATE_SUMMARY_INTERVAL * 100000` (setting = 10) and (b) **location key** = regex on the message: `(?i)\(context\s+(?:new\s+)?location:\s*([^,\)]+)` or `(?i)\(at\s+([^\)]+)\)`; a row with no location key always starts its own queue (`when location_key is null then 1`); queues with fewer than `AUTO_CREATE_SUMMARY_MIN_EVENTS` (default 5) rows are discarded (`having count(*)>=$minEventsPerSummary`); only queues older than 1 game hour are packed. Inserted with `classifier='dialogue'`, `summary=NULL`, `scope='global'`. It is driven by `service/processors/middleterm/entrypoint.php:316-333` → `php debug/util_memory_subsystem.php compact embed 1`, which LLM-summarises `packed_message` (prompt key `memory_subsystem_summary` / `summary_prompt` in table `prompts`), fills `summary`, `tags`, `native_vec`, embeddings, and computes `companions` from `eventlog.people` in the same time window, keeping names that appear more than once **and** occur in `packed_message` (`debug/util_memory_subsystem.php:129-160`).

Recall: `offerMemory($gameRequest, $useLocationContext = false)` (`chat_helper_functions.php:2604`) for `inputtext*`, `rechat`, `narration`, `continue*` (`main.php:2093`); injects `<memory> X remembers this: [...] </memory>` and persists it as an `infoaction` for that NPC only (`main.php:2331-2337`). Filtering per NPC: `dataGetMemoryCompanionConditionSql()` (`companions LIKE '%|Name|%'`, `data_functions.php:4888`) and scope (`dataGetMemoryScopeConditionSql`, `:4878`). Short-term memory: `DataShortTermMemoryFor($actor, $sqlfilter = "")` (`:4966`) when `SHORT_TERM_MEMORY_ENABLED`; middle-term digest lives in `core_npc_master.extended_data.middle_term_memory`.

Diary: `generateFollowerDiary($followerName, $gameRequest, $eventType)` (`dynamic_update_util.php:1057`) builds "Recent context" from `DataLastDataExpandedFor(HERIKA_NAME, -CONTEXT_HISTORY_DIARY, $sqlfilter)`, calls the profile's `diary_connector_id` LLM, inserts into `diarylog` (`people` = NPC name, `tags` = `"Auto-diary,$eventType"`), then `logMemory($followerName, $followerName, $buffer, $momentum, gamets, 'auto_diary', ts)`. Triggers: `waitstart`, `goodnight` (`processAutoDiary`, `:557`, all NPCs from `DataBeingsInCloseRange()` with cooldown `DIARY_COOLDOWN`, default 30 s), `diary_nearby`, `diary_narrator`, `diary_player`, and the `diary` request. Diary rows enter `memory_summary` with classifier `diary` (`data_functions.php:4258-4268`).

### 2.5 How the glue should introduce OStim events (RECOMMENDATION, built on the verified facts above)

Game side sends one compact line per transition with `AIAgentFunctions.logMessage(String a_msg, String type)` (`F:\Modlists\LoreRim\mods\CHIM\Source\Scripts\AIAgentFunctions.psc:27`), e.g. type `ext_glue_ostim`, data = small JSON **without `|`** (scene id, action-type names, tag list, actor names, phase start/change/end).

Server plugin `HS/ext/<glue>/`:
1. `globals.php`: `$GLOBALS["external_fast_commands"][] = "ext_glue_ostim";` (avoid blocking on the MAIN semaphore while an LLM answer streams) - must append, not overwrite, because other plugins may use the same global.
2. `preprocessing.php` (or `prerequest.php` if `HERIKA_NAME` is needed): if `$gameRequest[0]==="ext_glue_ostim"`: decode, upsert the *current scene state* into `conf_opts` (pattern used by core `HeldItems`: key `player_held_item_state`, JSON value, TTL; `lib/vr_items.php:5-7, 90-118`), and log exactly **one** human-readable line per meaningful transition:
   * scene start / end → `logEvent([ 'infoaction', ts, gamets, $text, 'pending' ], $forcePeople)` so it is visible in normal and rechat context immediately; `$forcePeople = "|Player|NpcA|"` (+ bystanders you want to be aware).
   * position/"scene changed" chatter → do **not** log each one. Keep only the state row in `conf_opts` and render the live state with `chimRegisterPromptInjection("prompt_bottom", "glue_ostim_scene", callable)`; alternatively log as `ext_glue_ostim_change` and, in `context_building.php`, keep only the newest one and rewrite its role to `user` (same algorithm core uses for `ext_held_item_pickup`). Either way these rows never reach `memory_v`.
   * then `terminate();`.
3. On scene end additionally write **one** memory row so normal summarisation and diaries pick it up: `logMemory($playerName, $npcName, "(Context location: {$loc}) The Narrator: <one clinical sentence>", time(), $gamets, 'glue_ostim_scene', $ts);` - the `(Context location: X` prefix must use the same leading location token as the surrounding `speech.location` values (take `$GLOBALS["CACHE_LOCATION"]`/`DataLastKnownLocation()` and keep the text up to the first comma) or the row forms its own 1-row queue and is discarded by the `>= 5` rule. For diaries, also log one `info_glue_scene` (or reuse the `ext_` row) because diaries exclude `infoaction`.
4. To keep something out of memory: never call `logMemory`, never emit `_speech`, and do not use types `death`/`location`. To keep it out of NPC context too: use a blacklisted type such as `status_msg`, or set `EXT_CONTEXT_SQL_FILTER1`.

---

## 3. Structured data posted by the game

### 3.1 `PostGameData(json)` → `HS/gamedata.php` (VERIFIED)

Papyrus: `int function PostGameData(string jsonData) global native` (`AIAgentFunctions.psc:83`). Server: POST only (`gamedata.php:31`), body JSON, required `type`; unless the type is "actorless", `actor_name` and `actor_type` are required (`:65-72`). Actorless list (`:49-62`): `market_stock, activity_status_bulk, transformation_state_bulk, loaded_plugins, quest_event, quest_action_poll, quest_action_ack, quest_import_bundled, quest_reset_runtime, quest_status, player_item_acquired, player_items_acquired`. `default:` → HTTP 400 `"Bad Request: Unknown type"` (`:170-174`). **No ext hook exists in gamedata.php** - a plugin cannot add a type without editing a core file.

| `type` | handler (gamedata.php) | payload keys read | target |
|---|---|---|---|
| `equipment` | `handleEquipmentUpdate` :190 | `equipment{slot:{name,baseid,keywords[]}}` | player → `core_player` key `equipment` (JSON); NPC → `core_npc_master.metadata.equipment` (`slot`, `slot_baseid`, `slot_keywords`) |
| `inventory` | `handleInventoryUpdate` :484 | `items[{name,baseid,count,keywords[],goldvalue}]`, `gamets` | player → `core_player.inventory` + `chimQuestEngineSyncPlayerInventory`; NPC → `metadata.inventory`, `metadata.last_inventory_update_gamets`; side effect `market_cache` |
| `skills` | `handleSkillsUpdate` :676 | `skills{name:float}` | `core_player.skills` / `metadata.skills` |
| `stats` | `handleStatsUpdate` :731 | `stats{level,health,health_max,magicka,magicka_max,stamina,stamina_max,scale}` | `core_player.stats` / `metadata.stats` |
| `spells` | `handleSpellsUpdate` :786 | `spells[{name,baseid,casting_type,delivery}]` (NPC only) | `metadata.spells`, `metadata.spells_updated` |
| `skyrim_stats` | `handleSkyrimStatsUpdate` :824 | `stats{key:value}` | `core_player` rows + `conf_opts` rows (e.g. `Quests Completed`) |
| `furniture` | `handleFurnitureUpdate` :241 | `furniture,timestamp,gamets` | `metadata.activity_status` |
| `activity_status` / `activity_status_bulk` | :257 / :269 | sanitised by `chimSanitizeActivityStatusPayload` (`lib/core/activity_status.php:127`): `current_action, current_use, use_type, furniture_name, attack_target, is_in_combat, is_attacking, is_moving, is_running, is_sneaking, is_sitting, is_sleeping, is_unconscious, is_restrained, is_dead, is_weapon_drawn, timestamp, gamets`; bulk uses `statuses[]` | `metadata.activity_status`, `current_action`, `furniture`, `use_type`, `activity_status_timestamp` |
| `transformation_state` / `_bulk` | :292 / :315 | `states[]` | `metadata.transformation_state...` / `core_player.transformation_state` |
| `market_stock` | :867 | `faction, list[{itemid,name,count,gold,enchantment}], player_rank` | `factions.stock/gold/player_rank`, `market_cache`, `descriptions_custom` |
| `loaded_plugins` | :349 | `plugins[]` | `game_plugins` (3491 rows here: `plugin_name,is_light,compile_index,small_file_compile_index,partial_index,formid_prefix`) |
| `low_process_actors` | :955 | `actors_nearby[{formId,name}], gamets` | `metadata.low_process_actors` (last 5 snapshots) |
| `player_item_acquired` / `player_items_acquired` | :549 / :616 | `name,count,gold_value,total_value,source_name,source_owner,source_type,barter_suppressed,crafting_active,gamets` | eventlog `itemfound` if value ≥ `CHIM_ITEM_PICKUP_EVENTLOG_MIN_VALUE` (500) |
| `quest_event` | :125 | `event_type`, `payload{}` | `chimQuestEngineHandleEvent` (section 5.3) - returns JSON |
| `quest_action_poll` | :132 | `limit` | returns `{ok, actions:[...]}` from `skyrim_quest_action_outbox` |
| `quest_action_ack` | :139 | `action_id,status,result{}` | `chimQuestEngineAcknowledgeAction` |
| `quest_import_bundled`, `quest_reset_runtime`, `quest_status` | :147-163 | - | quest engine admin |

Helper used by these handlers: `chimApplyNpcMetadataUpdatesByName(string $npcName, array $updates): bool` (`lib/core/activity_status.php:223`), `NpcMaster::updateMetadataKeysByName(string $npcName, array $setValues = [], array $unsetKeys = []): bool` (`npc_master.class.php:1207`, atomic `jsonb_set`), `NpcMaster::updateExtendedKeysByName(...)` (`:1271`). NPC rows are only updated if the NPC already exists (`getByName`), i.e. after an `addnpc`.

### 3.2 Native senders → request types (VERIFIED from DLL strings + server parsers)

| Papyrus native (`AIAgentFunctions.psc`) | wire format (DLL string) | server parser |
|---|---|---|
| `int function sendNPCFast(Actor akActor)` :45 | `addnpc|{}|{}|{}@{}` (DLL-strings:46232; `sendNPCFast` symbols at 46728-46734) | `comm.php:1403` |
| `int function scanActorsAroundOffline(Actor akActor)` :76 | INFERENCE: JSON POST to `gamedata.php` with `type` `low_process_actors`, keys `actor_name, actor_type, actors_nearby, gamets` (DLL-strings:44903-44909, directly after the `[LOW ACTOR] GetLowProcessActorNamesFromRef ...` log strings). The native's own symbol strings (46718-46719) carry no wire format, so the link native→payload is by function purpose, not proven. | `gamedata.php:955` `handleLowProcessActorsUpdate` → `metadata.low_process_actors[gamets] = {REFIDHEX: name}` (last 5 snapshots) |
| `int function addBasicProfile(Actor forcedActor)` :59 | `addbgnpc|{}|{}|{}@{}` (DLL-strings:46722, located between the `addBasicProfileReal(...)` signature string 46720 and `addBasicProfile` 46723 - adjacency-based INFERENCE) | `comm.php:1403` with `$offline=true` (no first-met memory, no greeting, no relationship init queue) |
| `int function sendFactionFast(Faction factions,string name)` :44 | `util_faction_name|{}|{}|{:08X}/{}/{}` (46597) | `comm.php:1851` → `factions(formid,name,vendor_cont)` |
| `int function sendLocationFast(Location curr,string tags,Cell referenceCell=None)` :43 | `util_location_name|{}|{}|{}/{}/{}/{}/{}/{}//{}/{}/{}/{}/{}` (46588) | `comm.php:1788` → `locations` |
| `int function updateRemoteInventory(Actor akActor)` :78 | JSON POST to `gamedata.php`, `type` `inventory` (INFERENCE: strings `inventory` 48407, `gamedata.php` 44909; comment in psc "This will send data to server") | `gamedata.php:484` |
| `int function logMessage(String a_msg,String type)` :27 | `type|ts|gamets|a_msg` to comm.php, no response expected | whole main.php flow |
| `int function requestMessage(String a_msg,String type)` :29 | same, expects an AI response | whole main.php flow |
| `int function setConf(String code,float float_value,int int_value,String string_value)` :49 | `setconf` | `comm.php:1281` → `conf_opts` |

`addnpc` payload (`@`-separated, indices from `comm.php:1411-1693`): `0` display name, `1` base (profile) name, `2` gender, `3` race, `4` refid, `5-22` skills (`archery, block, onehanded, twohanded, conjuration, destruction, restoration, alteration, illusion, heavyarmor, lightarmor, lockpicking, pickpocket, sneak, speech, smithing, alchemy, enchanting`), `23-32` equipment `name^baseid` (`helmet, armor, boots, gloves, amulet, ring, cape, backpack, left_hand, right_hand`), `33-40` stats (`level, health, health_max, magicka, magicka_max, stamina, stamina_max, scale`), `41` mods `#`-list, `42` factions `formID:rank[:Plugin.esp/LocalFormId]#...`, `43` class `className:formID:trainSkill:trainLevel`. Stored as: columns `gender, race, refid, base`; `metadata.skills/equipment/stats/mods/current_display_name/display_name_aliases`; `extended_data.factions[] = {formid, rank, name, plugin?, local_formid?, stable_key?}` (name resolved from table `factions`, else `'Unknown Faction'`); `extended_data.class = {name, formid, teaches?, max_training_level?}`. Then `import_rules` are applied (regex match on name/race/gender/base/faction/mods → set `profile_id` and arbitrary columns/metadata, `comm.php:1620-1668`).

### 3.3 What the server already knows per NPC (VERIFIED)

`core_npc_master` columns: `id, npc_name (unique), npc_favorite, lock_profile, prompt_head, npc_static_bio, oghma_knowledge_tags, emote_moods, personality, relationships (text), occupation, appearance, skills, speechstyle, goals, voiceid, metadata jsonb, gender, race, refid varchar(16), profile_id → core_profiles(id), dynamic_profile, extended_data jsonb, md5, gamets_last_updated, core, base, tags`. History/rollback copy: `core_npc_master_history` (+ `history_id, npc_id, created`).

| datum | where | source |
|---|---|---|
| gender, race, base name, refid | columns | `addnpc` |
| class (+ trainer skill/level) | `extended_data.class` | `addnpc[43]` |
| factions with rank | `extended_data.factions[]`; helpers `NpcMaster::getNpcFactions(array $npcData, bool $activeOnly = true): array` (:1701), `isNpcInFaction($npcData, $factionFormId)` (:1729) | `addnpc[42]` |
| skills, equipment, stats (HP/MP/SP, level, scale), spells, inventory | `metadata.*` | `addnpc`, `gamedata.php` |
| location / coordinates | `metadata.last_coords` (`[x,y,z,locName]`, `last_updated, location_formid, world, in_interior, real_coords, rx, ry, rz, running_package_id, location_name, state`), `last_coords_history` | `util_location_npc` (`comm.php:1878-2003`) |
| current activity | `metadata.activity_status`, `current_action`, `furniture`, `use_type` | gamedata |
| werewolf / vampire-lord form | `metadata.transformation_state...` | gamedata |
| CHIM relationship (affinity/tier/type toward player and NPCs) | `extended_data.relationships` (JSON; class `RelationshipManager`, `lib/relationship_manager.php`; enabled: `RELATIONSHIP_SYSTEM_ENABLED=true`) and free-text column `relationships` | LLM-evaluated |
| mods the NPC comes from | `metadata.mods` | `addnpc[41]` |
| **vanilla relationship rank, marriage/spouse, "is adult/child"** | **NOT FOUND** - grep for `relationship_rank|RelationshipRank|spouse|married|marriage` in `lib, processor, functions, ext, prompts` only hits the LLM relationship system and `SkyrimCommandBuilder->Actor->SetRelationshipRank` (a *setter* command, `scriptproxy_papyrus.php:410`). No child/age flag is stored either (race string only). | must be supplied by the glue |

Player: `core_player(id text pk, value text)` key/value (class `Player`, `lib/core/player.class.php`: `get(string $key): ?string`, `set(string $key, string $value): bool`, `getJson(string $key): ?array`, `setJson(string $key, array $data): bool`, `getBool`, `getInt`, `getAll`, `setMultiple`, `delete`, `exists`), keys seen in code: `equipment, inventory, skills, stats, transformation_state, appearance, bio_known_by_all`, Skyrim stat names. `$GLOBALS["PLAYER_NAME"]` is loaded by bootstrap option `load_player_name`.

---

## 4. Database

### 4.1 Credentials (VERIFIED)

`HS/conf/conf.php` is **0 bytes**; defaults come from `conf/conf.sample.php` (bootstrap loads sample then conf, `lib/runtime_bootstrap.php:193-201`). The connection string is hard-coded:
```php
// lib/postgresql.class.php:8
private $connString = "host=localhost dbname=dwemer user=dwemer password=dwemer connect_timeout=90";
```
(same literal creds in `processor/dynamicoghma.php:7-12` and `lib/plugin_package_manager.php:513`). `pg_isready` → `localhost:5432 - accepting connections`. `search_path` forced to `public` (`postgresql.class.php:28`). Extensions in use: `pg_trgm` (gin_trgm_ops indexes), `vector` (pgvector columns).

`sql` class API (`lib/postgresql.class.php`): `insert($table, $data)` :128, `insertReturningId($table, $data, $idColumn = 'id')` :160, `query($query)` :199, `delete($table, $where = "FALSE")` :229, `truncate(...)` :255, `update($table, $set, $where = "FALSE")` :283, `execQuery($sqlquery)` :307, `fetchAll($q,$log=false)` :358, `fetchOne($q)` :400, `fetchArray($res)` :433, `escape($string)` :439, `escapeLiteral($string)` :448, `updateRow($table, $data, $where)` :457, `upsertRow($table, $data, $where)` :494, `upsertRowTrx($table, $data, $whereCondition)` :560, `upsertRowOnConflict($tableName, $data, $conflictTarget)` :667. Instance: `$GLOBALS["db"]`.

### 4.2 Relations (VERIFIED `\dt`, `\dv`, `\dn`)

Schemas: `public`, `plugins` (no tables yet; `plugins.plugin_migrations` is created on first package migration), `chim_meta` (`settings(key text pk, value text)`).

82 tables in `public`: `actions_issued, animations, animations_custom, audit_memory, audit_request, bgl_history, bio_templates, bio_templates_custom, books, conf_opts, core_action, core_action_custom, core_api_badge, core_itt_connector, core_llm_connector, core_narrator, core_npc_master, core_npc_master_history, core_player, core_profiles, core_stt_connector, core_tts_connector, core_tts_fallback, core_tts_pronunciation, currentmission, database_versioning, descriptions, descriptions_custom, diarylog, dynamic_bio, eventlog, faction_vanilla, factions, game_plugins, general_settings, global_settings_presets, import_rules, json_personalities, locations, log, market_cache, master_packages, memory, memory_summary, moods_issued, named_cell, npc_profile_backup, npc_templates, npc_templates_custom, npc_templates_trl, npc_templates_v2, oghma, oghma_dynamic, physical_npc_diaries, profile_settings_presets, prompts, quest_asset_group_members, quest_asset_groups, quest_asset_imports, quest_asset_packs, quest_assets, quest_item_types, quest_npc_own_templates, quest_npc_templates, quest_outfits, questlog, quests, relationship_eval_queue, relationship_init_queue, responselog, rolemaster, rumors, skyrim_quest_action_outbox, skyrim_quest_beat_state, skyrim_quest_definitions, skyrim_quest_events, skyrim_quest_instances, sneq_quests, sneq_quests_saved, speech, translations, visual_context`.

9 views: `combined_animations, combined_bio_templates, combined_core_action, combined_descriptions, combined_npc_templates, eventlog_view, locations_v, memory_v, speech_view`.

Row counts today: eventlog 14, speech 0, memory 0, memory_summary 0, diarylog 0, quests 0, questlog 133, core_npc_master 0, conf_opts 21, responselog 0, factions 0, locations 0, core_action 53, core_action_custom 2, skyrim_quest_definitions 301, skyrim_quest_instances 301, skyrim_quest_action_outbox 0, game_plugins 3491, general_settings 70, prompts 62.

### 4.3 Column lists of the important tables (VERIFIED `\d`)

(eventlog, speech, memory, memory_summary, diarylog: see sections 2.1 and 2.4; core_npc_master: section 3.3.)

```
conf_opts:        id text PK, value text
general_settings: id text PK, value text, description text default '', updated_at timestamp default CURRENT_TIMESTAMP
core_player:      id text PK, value text
core_narrator:    id text PK, value text
prompts:          prompt_key varchar(128) PK, default_prompt text not null, custom_prompt text, description text, created_at, updated_at
responselog:      localts bigint not null, sent bigint not null, actor text, text text, action text, tag varchar(256), rowid bigint (seq)
quests:           ts text not null, sess varchar(1024), id_quest varchar(1024) not null, name text, editor_id text, giver_actor_id text,
                  reward text, target_id text, is_unique boolean, mod text, stage integer, briefing text, briefing2 text,
                  localts bigint not null, gamets bigint not null, data text, status text, rowid bigint (seq)   -- no PK
questlog:         same columns as quests (all nullable), rowid integer PK
currentmission:   sess varchar(1024), description text, localts bigint, gamets bigint, ts bigint, rowid bigint PK
core_profiles:    id PK, label, default_npc, default_narrator, tts_connector_id, itt_connector_id, llm_primary_id, llm_secondary_id,
                  llm_tertiary_id, llm_quaternary_id, llm_formatter_id, llm_fallback_id, metadata jsonb, diary_connector_id, slot (unique), prompt
core_action / core_action_custom: id, code_name varchar(128) unique, action_name varchar(255), description text, return_message text,
                  available_to_npc bool, available_to_followers bool, available_to_narrator bool, is_activated bool default true,
                  parameters_json jsonb, metadata jsonb, game_function bool default true, import_version bigint,
                  script_proxy_program jsonb, created_at, updated_at
                  (view combined_core_action = custom rows UNION ALL base rows not overridden by code_name)
actions_issued:   action, fullcall, actorname, ts numeric, localts numeric, gamets numeric, original text, rowid PK
moods_issued:     sess, speaker, mood, listener, localts, gamets, ts, rowid PK, emotion, emotion_intensity
rolemaster:       localts bigint, ttl bigint, type varchar(128), data text, rowid PK
rumors:           id, gamets, ts, hold, content, type, rumor_length_days
factions:         name text, formid text PK, vendor_cont text, stock jsonb, gold numeric, player_rank numeric, localts bigint
faction_vanilla:  name, formid
locations:        name, formid bigint, region, hold, tags, factions, is_interior int, vanilla_location bool, coords point, refs text,
                  cleared bool, updated_at, world, chim_added int     (view locations_v hides formid 102771 unless cleared)
named_cell:       id bigint, cell_name, location_id, interior, dest_door_cell_id, dest_door_exterior, door_id bigint, vanilla_cell bool,
                  statics_list, worldspace, closed, door_name, door_x, door_y, gamets; PK(id, door_id)
game_plugins:     plugin_name PK, is_light bool, compile_index int, small_file_compile_index int, partial_index int, formid_prefix text, updated_at
books:            sess, title, content, localts, gamets, ts, rowid PK
visual_context:   id, subject_type default 'scene', subject_key, subject_name, plugin, baseid, refid, cell_id, location_name, image_path,
                  image_sha256, description, perspective, provider, model, metadata jsonb, locked, active, user_edited, captured_at, updated_at
bgl_history:      rowid identity, npc, gamets, ts, localts, data, category
physical_npc_diaries: npc_name PK, title, last_diary_localts, created_at, updated_at
relationship_eval_queue: id, npc_id unique, eval_data jsonb, created_at, retry_count
relationship_init_queue: id, npc_id unique, init_data jsonb, created_at, retry_count, last_error
import_rules:     id, description, match_name, match_race, match_gender, match_base, match_faction, match_mods text[], action jsonb,
                  profile → core_profiles(id), priority int, enabled bool
oghma:            topic PK, topic_desc, native_vector tsvector, knowledge_class, topic_desc_basic, knowledge_class_basic, tags, category,
                  aliases text default '', vector384 vector(384)
oghma_dynamic:    id, id_quest varchar(1024), stage int, topic, topic_desc, knowledge_class, topic_desc_basic, knowledge_class_basic, tags, category
database_versioning: tablename text PK, version bigint
audit_request:    request, result, created_at, rowid PK, url, connector, usage jsonb, response
audit_memory:     input, keywords, rank_any, rank_all, memory, time, created_at
log:              localts, prompt, response, url, rowid
dynamic_bio:      id, prompt
sneq_quests:      quest_id PK, code, quest_run_state, quest_data jsonb, created_at, updated_at, title, stage
sneq_quests_saved: same + gamets bigint, state text, history_id PK
skyrim_quest_definitions: quest_key text PK, quest_editor_id text, title text, source_plugin, source_form_id, source_path,
                  skeleton jsonb default '{}', active bool default true, created_at, updated_at
skyrim_quest_instances:   quest_key PK → definitions ON DELETE CASCADE, quest_editor_id, run_state text default 'inactive',
                  current_stage int, last_gamets bigint, state_json jsonb, created_at, updated_at
skyrim_quest_events:      id bigserial PK, quest_key → definitions ON DELETE SET NULL, event_type text, event_source, npc_name,
                  location_name, gamets, payload_json jsonb, created_at
skyrim_quest_beat_state:  PK(quest_key, beat_id), quest_key → instances CASCADE, fired bool, fired_order int, fired_gamets bigint,
                  evidence_json jsonb, created_at, updated_at
skyrim_quest_action_outbox: id bigserial PK, quest_key text not null → instances CASCADE, beat_id, action_type text not null,
                  action_gamets bigint, payload_json jsonb, status text default 'pending', result_json jsonb, created_at, updated_at, applied_at
```
`conf_opts` ids present now: `BORED_EVENT, CHIM_GAME_LAST_ACTIVITY_TS, CHIM_GAME_SESSION_STARTED_TS, COMBAT_BARK_COOLDOWN, CONTEXT_HISTORY(50), CONTEXT_HISTORY_DIARY(100), CONTEXT_HISTORY_DYNAMIC_PROFILE(50), MAX_WORDS_LIMIT, Network/HOST_IP, Network/WSL_IP, PLAYER2_*, QUEST_COMMENT, Quests Completed, RECHAT_ALLOW_ACTIONS(true), RECHAT_H(2), RECHAT_P(50), RPG_COMMENTS_CHANCE, core_action_legacy_user_pref_imported, player_naked`. Other ids used by code: `plugin_dll_version`, `chim_mode`, `chim_whisper_people/target/updated`, `last_waitstart`, `last_narrator_welcome`, `QUEST_COMMENT_LAST_TIMESTAMP`, `RPG_COMMENT_LAST_TIMESTAMP`, `COMBAT_BARK_LAST_TIMESTAMP`, `DIARY_LAST_TIMESTAMP_PLAYER_<name>`, `book_reading_state`, `player_held_item_state`, `aiagent_rolemastered_faction`.

Notable `general_settings` values: `CHIM_AI_QUEST_PROGRESSION=false`, `CHIM_PLAYER_ONLY_QUEST_ADVANCEMENT=true`, `COMPACT_CHAT_ENABLED=true`, `RELATIONSHIP_SYSTEM_ENABLED=true`, `RELLLM_CONNECTOR=5`, `SCENE_CLASSIFIER_ENABLED=true`, `FEATURES@MEMORY_EMBEDDING@ENABLED=true`, `...@USE_TEXT2VEC=true`, `...@AUTO_CREATE_SUMMARYS=true`, `...@AUTO_CREATE_SUMMARY_INTERVAL=10`, `EVENT_TYPE_FILTER=''`, `PROMPT_CONTEXT_OPTIONS={json}`, `PLAYER_RESPEECH=true`, `OPEN_RECHAT=true`, `RECHAT_MODE=random`.

53 built-in actions in `core_action` (code_name | LLM name): `AddBounty, ArrestPlayer, Attack, Brawl, CastSpell, CheckInventory, ComeCloser, Consume, CreateNewNPC, DecreaseWalkSpeed, DirectorCommand, Drink, EndConversation, EndRitualCeremony, EquipGear, Follow, FollowPlayer, ForgiveCrime, GiveGoldTo, GiveItemTo, GoToSleep, HireCarriage, HireFerry, IncreaseWalkSpeed, Inspect, InspectSurroundings, KillTarget, LeadTheWayTo, MakeFollower, MoveTo, OpenInventory(Trade_Items), OpenInventory2(Accept_Gift), PayBounty, PickupItem, ReadBook, ReadQuestJournal, Relax, RentRoom, ReturnBackHome, SheatheWeapon, SpawnGold, SpawnItem, SpawnNPC, StartRitualCeremony, StopWalk, Surrender, TakeASeat, TakeGoldFromPlayer, TakeHeldItem, TeleportNPC, Toast, Training, TravelTo, UseSoulGaze, WaitHere`. There is a DB-backed custom action table (`core_action_custom`, 2 rows) with a `script_proxy_program jsonb` column (details belong to the actions research topic).

---

## 5. Quests

### 5.1 Arrival (VERIFIED)

| wire | DLL string | server | storage |
|---|---|---|---|
| `_quest|ts|gamets|{json}` | `_quest|{}|{}|{}` 44786; JSON keys `currentbrief`, `currentbrief2` 44784-5 | `comm.php:381-406` | delete+insert in `quests`: `name`←`name`, `briefing`←`currentbrief`, `data`←`json_encode(currentbrief2)`, `stage`←`stage`, `giver_actor_id`←`data.questgiver`, `id_quest`←`formId`, `status`←`status`; ignored if `currentbrief` empty |
| `_questdata|..|id@briefing2` | - | `comm.php:435` | `quests.briefing2` |
| `_questreset|ts|gamets|` | 48126 (sent on menu events) | `comm.php:583` | `delete from quests` (journal is re-sent in bulk) |
| `_uquest|..|id@?@briefing@stage` | `_uquest|{}|{}|{}@{}@{}@{}` 48662 | `comm.php:410-431` | insert `questlog(id_quest=[0], briefing=[2], data=[2], stage=[3])`, then `syncQuestWithOghma(id, stage)` (`processor/dynamicoghma.php:3`) which copies matching `oghma_dynamic(id_quest,stage)` rows into `oghma` (quest-stage-gated lore). Sample questlog rows: `MGR20 | 0 | Return the book to Urag gro-Shub`, `REQ_Quest_Installation | 10`. |
| `quest|..|(Context location: X) Quest Updated "N" new objetive: O` | 48663 | `comm.php:1045` | eventlog `quest` (skips `New quest ""` and `Storyline Tracker`), optional narrator comment (`quest_comment_enabled`, chance, cooldown; becomes request `narrator_quest_comment`) |
| gamedata `quest_event` | `quest_event` 48169; event types `quest_stage` 48183, `location_entered` 48185, `actor_dead` 48186, `item_acquired` 48672, `player_inventory_sync` 48208 | `gamedata.php:125` | `skyrim_quest_events` + quest engine state |

### 5.2 Rendering into context (VERIFIED)

* `DataGetCurrentTask()` (`data_functions.php:3615-3675`): `<current_plans>` from `currentmission` (last 2 game hours) and
  ```
  <active_quests>
  #Active Quests
  ## {name}: {briefing}
  </active_quests>
  ```
  from `SELECT distinct name, briefing as description, gamets FROM quests order by gamets desc LIMIT 8`. Appended to `COMMAND_PROMPT` only when `$GLOBALS["CURRENT_TASK"]` is truthy **and** the speaker is a party member (not `IS_NPC`) or the Narrator (`main.php:2077-2087`); ordinary NPCs do not get the quest list.
* `DataQuestJournal($quest)` (`:1969`) → JSON of all quests for the `ReadQuestJournal` action.
* eventlog `quest` rows → `narratorci` lines in history.
* `chimQuestEngineBuildPromptContext($npcName, $locationName = '')` (`chim_quest_engine.php:3687`) → `<quest_context>#Quest-sensitive facts ...` appended to the dynamic biography at `main.php:2569-2575` (only when the engine is enabled).

### 5.3 The built-in "AI Quest Progression" engine (VERIFIED; important overlap with "menuless questing")

* Feature flag: `chimQuestEngineFeatureEnabled()` (`chim_quest_engine.php:111`) reads general setting `CHIM_AI_QUEST_PROGRESSION` (currently `false`), fallback `$GLOBALS`, fallback `conf_opts.chim_ai_quest_progression`. `chimQuestEnginePlayerOnlyAdvancementEnabled()` (`:173`) → `CHIM_PLAYER_ONLY_QUEST_ADVANCEMENT` (true).
* Definitions: 301 rows, all active, `source_plugin`: `Skyrim.esm` 218, `Dragonborn.esm` 57, `Dawnguard.esm` 26 (no LoreRim mod quests). Bundled file `HS/data/skyrim_quest_definitions.json` (`:421`). Importable via request type `traditional_quest_import` (CSV with `skeleton_json`/`beats_json`, `processor/import_files.php:852-1013`; DLL string 44803) and `chimQuestEngineUpsertDefinition(array $definition, $sourcePath = '')` (`:884`).
* Skeleton keys: `quest_key, quest_editor_id, quest_plugin, quest_form_id, title, description, skeleton_type, natural_start{beats[],enabled,requires[]}, npc_facts{npc:{base_facts[],beat_facts{beatId:[]}}}, beats[]` (+ rare `external_ids`, `radiant_template`). Beat keys: `id, action{type,...}, downstream[], triggers[], trigger_mode, prerequisites[], focus_npc, comment` (+ `allow_natural_start, start_conditions, suppress_actions, intent_summary, required_item, conditions, focus_npcs`). Trigger types in data: `quest_stage` 1124, `dialogue` (keyword match) 988, `dialogue_intent` (LLM-judged, `intent, examples_yes/no, min_confidence, requires_explicit_commitment`) 43, `item_acquired` 1. `action.type`: `set_stage` 856, `gate` 516, `set_stage_cascade` 33, `actor_dialogue_start_quest_stage_objective` 1. `downstream[].type`: `set_objective_displayed` 211, `set_objective_completed` 188, `stop_quest` 169, `set_stage` 11, `remove_item` 9, `add_item` 4, `set_relationship_rank` 2, `cross_quest_start` 1, `enable_ref` 1.
* Runtime: after every AI reply `returnLines()` calls `chimQuestEngineHandleLiveDialogueTurn($npcName, $npcResponseText, array $gameRequest)` (`chat_helper_functions.php:1945-1950`; engine `:3204`) → event `dialogue_turn` with `player_text`, `npc_text` → `chimQuestEngineHandleEvent($eventType, array $payload = array())` (`:3139`) → beats fire → `chimQuestEngineQueueAction($questKey, $beatId, $actionType, array $payload, $gamets = null)` (`:1440`) inserts into `skyrim_quest_action_outbox`. Also suppresses conflicting LLM actions for the turn (`chimQuestEngineApplyActionSuppressionsForTurn`, `main.php:2467`).
* Delivery: the DLL polls `gamedata.php` with `{"type":"quest_action_poll","limit":N}` and acks with `quest_action_ack` (`action_id`, `status`, `result{verified, stage, ...}`), executing natively/through a Papyrus bridge. Action types present in the DLL (DLL-strings:48258-48340): `set_objective_completed, cross_quest_set_objective_completed, set_objective_displayed, fail_all_objectives, cross_quest_start, start_quest_stage_objective, actor_dialogue_start_quest_stage_objective` (queues an `ADIA` ActorDialogue story event), `change_location_start_quest_stage_objective` (`CLOC` story event), `console_command, console_command_sequence, stop_quest, start_scene, set_actor_value, set_ghost, evaluate_package, remove_item, add_item, enable_ref, set_relationship_rank`; bridge function names `SetQuestStage, SetQuestObjectiveCompleted, SetQuestObjectiveDisplayed, FailAllQuestObjectives, StartQuestStageObjective, SetQuestStageObjective, ExecuteConsoleCommand, ExecuteConsoleCommandSequence, StopQuest, StartScene, SetActorValue, SetActorGhost, EvaluateActorPackage, RemoveItemFromPlayer, AddItemToPlayer, EnableReference, SetActorRelationshipToPlayer`; guard string `quest progression disabled before execution` and `[QuestProgression] Server global setting changed to {}` (the DLL mirrors the server flag via `quest_status`). The literal `set_stage` does **not** occur anywhere in the DLL strings dump (grep over the whole file: 0 hits), although the server queues `action_type='set_stage'` (`chim_quest_engine.php:2648-2653`) and the DLL has a `SetQuestStage` bridge name with error text `missing quest or stage` (DLL-strings:48254-48256). Short literals can be compiled into immediate compares, so absence is not proof; how the DLL matches `set_stage` is NOT VERIFIED.
* Other quest system: **SNQE** (`service/processors/snqe`, tables `sneq_quests*`) = LLM-generated side quests, driven by `snqe` requests and `rolecommand|StartQuest@...`, `EndQuest@`, `QuestTrackReference@`, `moveToPlayer@`, `spawnCharacter@` etc. Unrelated to vanilla quests.

---

## 6. Helper functions a plugin should reuse

Logging / reading events
```php
function logEvent($dataArray,$forcePeople='')                                   // lib/chat_helper_functions.php:5345
function logMemory($speaker, $listener, $message, $momentum, $gamets,$event,$ts) // lib/chat_helper_functions.php:1955
function chimGenerateUtteranceId()                                               // lib/chat_helper_functions.php:5336
function buildScopedPeopleForEvent($eventType, $eventData, $listenerName, $fallbackPeople = "") // :5247
function normalizePeoplePipeList($peopleNames)                                   // lib/chat_helper_functions.php:2831
function DataLastDataFor($actor, $lastNelements = -10)                           // lib/data_functions.php:909
function DataLastDataExpandedFor($actor, $lastNelements = -10,$sqlfilter="")     // lib/data_functions.php:3225
function buildHistoricContext($actor, $lastNelements = -10,$sqlfilter="")        // lib/data_functions.php:2516
function DataLastInfoFor($actorBeingCalled, $lastNelements = -2,$addNPCDescriptions=false,$excludeBusy=false,$excludeFarAway=false) // :977
function DataBeingsInCloseRange($excludeFarAway=false, $includeBusy=false)       // lib/data_functions.php:4622  -> "|A|B|"
function DataBeingsInRange()                                                     // lib/data_functions.php:4476
function DataLastKnownLocation()                                                 // lib/data_functions.php:3778  (raw "(Context location: ..." string)
function DataLastKnownLocationHuman($hold=false,$cached=false)                   // lib/data_functions.php:4016
function DataLastKnownGameTS()                                                   // lib/utils_game_timestamp.php:761
function DataSpeechJournal($topic,$limit=50)                                     // lib/data_functions.php:3481
function DataDiaryLog($topic)                                                    // lib/data_functions.php:3521
function DataGetCurrentPartyConf()                                               // lib/data_functions.php:4434
function GetLastInteraction($s_player_name, $s_npc_name)                         // lib/data_functions.php:6632
function convert_gamets2skyrim_date($gamets) / convert_gamets2skyrim_long_date($gamets) // lib/utils_game_timestamp.php:290 / :202
function gamets2days_between($gamets_start, $gamets_end) / gamets2seconds_between(...)  // lib/utils_game_timestamp.php:339 / :354
function terminate()                                                             // lib/auditing.php:26
function returnLines($lines,$writeOutput=true)                                   // lib/chat_helper_functions.php:1285 (TTS + stream lines as current NPC)
```

Settings
```php
// general_settings (typed, shown in UI/Prisma catalog) - lib/settings.php
function chimGetGeneralSettingRow(string $id): array                       // :888
function chimGetGeneralSetting(string $id, string $default = ''): string   // :907
function chimGetGeneralSettingBool(string $id, bool $default = false): bool // :919
function chimGetGeneralSettingInt(string $id, int $default = 0): int       // :927
function chimGetGeneralSettingFloat(string $id, float $default = 0.0): float // :935
function chimSetGeneralSetting(string $id, $value, ?string $description = null): bool // :961
// conf_opts (free key/value; also what Papyrus setConf() writes) - lib/game_activity.php
function chimGameActivityGetOption(string $id, string $default = ''): string // :6
function chimGameActivitySetOption(string $id, string $value): bool          // :18
// or directly:
$GLOBALS["db"]->upsertRowOnConflict('conf_opts', ['id'=>$k,'value'=>$v], 'id');   // pattern at processor/comm.php:183-190
$GLOBALS["db"]->fetchOne("SELECT value FROM conf_opts WHERE id='...'");
```
General settings are also exported to `$GLOBALS` at bootstrap (`chimLoadGeneralSettingsIntoGlobals()`, `runtime_bootstrap.php:165`), e.g. `$GLOBALS['RELATIONSHIP_SYSTEM_ENABLED']`. Note `AGENTS.md`: settings shown in `ui/global_settings.php` must also be added to `lib/core/prisma_settings_catalog.php` - a plugin should keep its settings in its own keys and not touch those files.

Current speaker / profile (globals set by `main.php:343-775`)
```php
$GLOBALS["HERIKA_NAME"]                      // current NPC name ("The Narrator" for narrator)
$GLOBALS["PLAYER_NAME"]
$GLOBALS["CHIM_CORE_CURRENT_NPC_DATA"]       // core_npc_master row (array)      main.php:489
$GLOBALS["CHIM_CORE_CURRENT_PROFILE_DATA"]   // core_profiles row                main.php:457
$GLOBALS["CHIM_CORE_CURRENT_CONNECTOR_DATA"] // core_llm_connector row           main.php:494
$GLOBALS["IS_NPC"]                           // true = not in party              main.php:1103-1111
$GLOBALS["CACHE_PEOPLE"], ["CACHE_LOCATION"], ["CACHE_PARTY"], ["gameRequest"], ["FUNCTIONS_ARE_ENABLED"]
$_GET["profile"]                             // md5(npc_name)
```
`NpcMaster` (`lib/core/npc_master.class.php`): `getByName($npcName)` :468, `getByMD5($md5Hash)` :481, `getByRefId($npcName)` :495, `getById($id)` :460, `getAll($where = "TRUE")` :503, `updateByArray($data)` :571, `getExtendedData($currentNpcData): array` :1172, `setExtendedData($currentNpcData, array $data)` :1188, `getMetadata($currentNpcData): array` :1195, `setMetadata($currentNpcData, array $data)` :1200, `updateMetadataKeysByName(...)` :1207, `updateExtendedKeysByName(...)` :1271, `getNpcFactions(...)` :1701, `isNpcInFaction($npcData, $factionFormId)` :1729, `renameNPC($oldname, $newname)` :1615, `backupAllNpcs($timestamp)` :1363, `restoreNPC($timestamp)` :1400. Beware: `metadata`/`extended_data` are snapshotted on `infosave` and restored on `init` (save/load consistent) - a good home for per-NPC glue state (e.g. consent flags), using the atomic `update*KeysByName` methods to avoid read-modify-write races with gamedata updates.

Server → game push (outside an LLM response) - **yes, there is a queue**
```php
// queue: table responselog; consumer: request type "request" (processor/comm.php:367-378)
$responseDataMl = DataDequeue(time() + 1);
foreach ($responseDataMl as $responseData) {
    echo "{$responseData["actor"]}|{$responseData["action"]}|{$responseData["text"]}\r\n";
}
function DataDequeue($timestamp = 0)   // lib/data_functions.php:874  (atomic claim: UPDATE ... SET sent=1 ... FOR UPDATE SKIP LOCKED)

// producer pattern (processor/comm.php:1303-1313, :1721-1731):
$GLOBALS["db"]->insert('responselog', [
    'localts' => time(),          // may be in the future to delay delivery (DataDequeue filters localts<=now+1)
    'sent'    => 0,
    'actor'   => "rolemaster",
    'text'    => '',
    'action'  => "rolecommand|Instruction@{$npcName}@{$instructionText}@0",
    'tag'     => ''
]);
```
`rolecommand|...` verbs used by core code (all VERIFIED in the cited files): `Instruction@npc@text@questId`, `Suggestion@npc@text@questId` (`lib/rolemaster_helpers.php:1413`), `DebugNotification@text`, `RenameNPC@0xREFID@name`, `RefreshNPCVoice@refid@name` (`processor/misc.php:338`, echoed directly), `BackgroundCmd@refid@Track/|TravelTo/x|Teleport/x|MoveToPlayer`, `ShowTrainingMenu@..`, `UploadBookContent@..`, `spawnCharacter@..`, `spawnItem@..`, `spawnItemNPC@..`, `spawnBook@..`, `generateLetter@..`, `moveToPlayer@npc@quest@intent`, `StartQuest@title@stage`, `EndQuest@..`, `QuestTrackReference@formid`, `TravelTo@npc@cell@quest`, `CombatPlayer@npc@quest`, `ImpersonatePlayer@speech@inputtext`, and **`ScriptProxy@{json}`**. Plain `command|X@...` lines are also echoed in-band (e.g. `{$HERIKA_NAME}|command|Halt@`, `main.php:1736`; `command|ToggleModel@`, `comm.php:1022`). Delayed delivery helper: `extended_data.pending_delayed_event` is posted to `responselog` once speech has been idle 15 s (`service/processors/middleterm/entrypoint.php:35-72`).

Generic Papyrus RPC: `class SkyrimCommandBuilder` (`lib/scriptproxy_papyrus.php:14`), `build(int $cmdID, array $params): array` (:858), `send($cmd,$localts = null)` (:872) → `responselog.action = 'rolecommand|ScriptProxy@'.json_encode($cmd)`. Groups: `->Actor` (cmdID 1-80: `SetActorValue, Kill, ModActorValue, DamageActorValue, RestoreActorValue, ForceActorValue, AddPerk, RemovePerk, AddSpell, RemoveSpell, AddShout, ..., EquipItem, UnequipItem, AddToFaction, RemoveFromFaction, SetFactionRank, ModFactionRank, EnableAI, AllowPCDialogue, SetAlert, StartSneaking, Dismount, OpenInventory, PlayIdle, PlayIdleWithTarget, SetAlpha, SetGhost, SetUnconscious, StartCombat, StopCombat, SetExpressionOverride, SetLookAt, SetHeadTracking, SetDontMove, KeepOffsetFromActor, SetOutfit, SetRace, SetRestrained, Resurrect, SetPlayerControls, PathToReference, DrawWeapon, SheatheWeapon, UnequipAll, SetRelationshipRank(43), RemoveFromAllFactions, EvaluatePackage, ShowBarterMenu`), `->ObjectReference` (100-131: `Activate, AddItem, RemoveItem, Enable, Disable, Lock, Unlock, SetOpen, AddToMap, EnableFastTravel, SetLockLevel, SetScale, SetPosition, SetAngle, MoveTo, ..., PlaceAtMe`), `->FormList` (200-202), `->EffectShader` (300-301), `->ActorUtil` (400-492: `AddPackageOverride, SetLinkedRef, SpawnDoor, PrintLinkedRef, setDrivenByAIA`), `->Faction` (500-524). First argument is always `string $targetObjectFormId` (e.g. `"0x00000014"`, constant `PLAYER_REFID`). **No Quest/Topic group exists** (no SetStage/Say here). The game-side executor is the Papyrus script the header calls `AIAgentScriptProxy` (NOT opened by me - belongs to the game-side research).

Second push channel: `skyrim_quest_action_outbox` polled through `gamedata.php` (`quest_action_poll`), only active when `CHIM_AI_QUEST_PROGRESSION` is on, FK requires an existing `skyrim_quest_instances.quest_key`.

Misc: `Logger::info/debug/warn/error/trace` (`lib/logger.php`), `audit_log($fromFile='')` (`lib/auditing.php:17`), `SemaphoreWait(string $semaphore_id, int $timeout = 300, int $tick_time = 1003, $callback = null): bool` (`lib/semaphore_manager.class.php:114`), `createProfile($npcname, $FORCE_PARMS = [], $overwrite = false, $baseprofile = '')` (`data_functions.php:6982`), `make_replacements_bracketed($text)` (`chat_helper_functions.php:5545`; `{LOCATION} {PLAYER_NAME} {HERIKA_NAME} {NARRATOR_NAME} {TEMPLATE_DIALOG}`).

---

## Implications for the glue (concrete recommendations)

1. **Ship the server side as `HS/ext/<glue>/` with `manifest.json`, `globals.php`, `preprocessing.php` (or `prerequest.php`), `context_building.php`, `prompts.php`, a functions file, and `migrations/*.sql` creating tables in schema `plugins`** (e.g. `plugins.glue_dialogue_topics`, `plugins.glue_scene_state`). Do not edit core files; `gamedata.php` cannot be extended, so all custom game→server traffic must use `logMessage`/`requestMessage` with custom types.
2. **Type naming**: use `ext_glue_*` for raw/structured telemetry that the plugin consumes and terminates; re-emit human-readable lines as `infoaction` (live awareness incl. rechat) and, where diary visibility is wanted, as `info_glue_*`. Register every custom type in `$GLOBALS["external_fast_commands"]` (append) so telemetry never waits on the MAIN semaphore (default timeout 300 s).
3. **Payload hygiene**: no `|` anywhere in data; keep JSON compact; avoid `@` if you ever route through `setconf`; names in `people` must match `core_npc_master.npc_name`/display names exactly and be wrapped `|A|B|`.
4. **Scene awareness without pollution**: keep the *current* OStim scene state in one `conf_opts` row (or a `plugins.*` table) and render it per request with `chimRegisterPromptInjection("prompt_bottom", ...)` (or `"character_bottom"` for participants only) - the callable receives `game_request`, `herika_name`, `player_name`; clear it on scene end and on `init`/`wipe` (check `$gameRequest[0]` in `preprocessing.php`). Log only start/end (+ maybe climax) to eventlog. This gives immediate awareness, zero memory pollution, and no dependency on the `CONTEXT_HISTORY` window.
5. **Memory/diary integration**: one `logMemory()` row per finished scene with a `(Context location: <token>)` prefix and an `event` tag like `glue_ostim_scene`; do not use `_speech`. Summaries will then merge it with surrounding dialogue; `companions` is computed from `eventlog.people` of the same time window, so make sure the start/end eventlog rows carry the participants in `people` and that the memory sentence contains the NPC's name (the companion algorithm requires the name to occur in `packed_message`).
6. **Hard gating of the intimacy action must not rely on server data alone**: the server has race/gender/factions/class but **no adult/child flag, no vanilla relationship rank, no marriage state**. The game side must evaluate `IsChild()`, relationship rank, faction/keyword blacklists and send the verdict (e.g. `metadata.glue_intimacy_eligible` via an `ext_glue_npc` event handled with `NpcMaster::updateMetadataKeysByName`), and re-check in Papyrus at execution time. Server-side, only expose the action to the LLM when the flag is true for `$GLOBALS["CHIM_CORE_CURRENT_NPC_DATA"]` and the request type is player-driven (`inputtext*`).
7. **Server→game commands** for scene navigation or topic execution that are not part of an LLM reply: insert into `responselog` (`actor` = NPC name or `"rolemaster"`, `action` = your own verb). Whether AIAgent forwards unknown `rolecommand|<verb>` lines to Papyrus as a mod event must be confirmed by the game-side research; the safe verbs today are `Instruction`, `Suggestion`, `DebugNotification`, `ScriptProxy`. Delivery latency = the DLL's `request` poll interval.
8. **Menuless questing vs. the built-in quest engine**: keep `CHIM_AI_QUEST_PROGRESSION=false` while the glue drives real TopicInfos, otherwise both systems may advance the same vanilla quest (the engine fires on every player-driven AI reply via `returnLines`). Worth reusing from it: the `dialogue_intent` design (LLM judge with `examples_yes/no`, `min_confidence`, `requires_explicit_commitment`), the outbox/ack/verify loop, per-turn action suppression, and rollback on `init` (`chimQuestEngineResetRuntime`, `chimQuestEngineRollbackRuntimeToGamets`). Its `<quest_context>` facts could be fed by the glue for mod quests through `chimQuestEngineUpsertDefinition()` later, but that is optional.
9. **Vanilla lines spoken because of glue-triggered topics will probably be captured automatically** by the DLL's `TESTopicInfoEvent` sink as `chat` + `_speech` (`traditional_npc_speech`) - INFERENCE; if confirmed in-game, the glue must not log them a second time, and they will already flow into memory via `speech`.
10. **Quest data for the LLM**: non-follower NPCs never see `<active_quests>`; if quest-giver NPCs need to know which of *their* topics are currently available, the glue must inject that itself (prompt injection keyed on `HERIKA_NAME`), sourced from a game-side topic scan posted as `ext_glue_topics`.
11. **Save/load safety**: everything the glue stores must be rolled back on `init` (load) like core does by `gamets` (`comm.php:126-160`). Either store a `gamets` column and delete `gamets >= $gameRequest[2]` in a `preprocessing.php` branch for `init`, or keep state in `core_npc_master.metadata/extended_data` (restored by `restoreNPC`).
12. **DB access for tooling**: `psql -h localhost -U dwemer -d dwemer` (password `dwemer`) inside the distro; the DB is fresh, so there is no legacy data to migrate, and the first in-game session will populate `core_npc_master`, `factions`, `locations`.

## Open questions

1. Does AIAgent.dll forward an unknown `rolecommand|<Verb>@...` (or `command|ExtCmd...`) received from the `request` poll to Papyrus as a mod event (`SPG_CommandReceived`-style, as described in `ext/herika_heal/functions.php:13-31`)? Needed to define the server→game verb set for scene navigation / topic execution. (Game-side research.)
2. What exactly does `AIAgentScriptProxy` accept for `rolecommand|ScriptProxy@{json}` and is it extensible (no Quest/Topic commands exist server-side)?
3. Which `profile` hash does the DLL attach to `logMessage()` calls (needed to know whether `HERIKA_NAME` is meaningful inside `prerequest.php` for custom log types)? If none, the default/narrator profile path at `main.php:509-775` is taken.
4. The `request` poll interval of the DLL (latency of the `responselog` channel) - NOT FOUND server-side.
5. Does the DLL's `TESTopicInfoEvent` sink fire for programmatically invoked topics (e.g. `Say`/`SayTo`/forced greet), so that glue-executed vanilla lines are auto-logged as `chat`/`_speech`?
6. `set_stage` action type: the server queues it (`chim_quest_engine.php:2648-2653`) but the literal has 0 hits in the whole DLL strings dump; how the DLL maps it to its `SetQuestStage` bridge is NOT VERIFIED. Only matters if the glue wants to reuse the outbox. Also unverified: which native emits `addbgnpc` (adjacency says `addBasicProfile`) and what `scanActorsAroundOffline` posts (purpose says gamedata `low_process_actors`).
7. Exact behaviour of `buildScopedPeopleFromSpatialEvidence()`/`extractGenericEventParticipants()` for free-text custom events when `$forcePeople` is not supplied (I recommend always supplying it; not traced to the end).
8. Whether other connectors than `openrouterjson.php` also drop unknown roles (only that connector was read; `openaijson.php`, `koboldcppjson.php`, `google_openaijson.php`, `groqjson.php`, `player2json.php` not checked).
9. `playerdied` branch in `processor/comm.php:1240-1242` looks like a no-op (selects `gamets`, tests `["ts"]`); confirm before relying on death rollback semantics for glue state.
10. Size limits of the base64 query-string transport (Apache `LimitRequestLine`) for larger JSON payloads such as a topic list - NOT checked; large uploads in core use `$gameRequest[4]` CSV (`oghma_import`) over the same GET channel, which suggests a generous limit, but the number is unknown.

---

## Verification (adversarial pass)

Performed 2026-09-21 by a second agent. Method: every one of the 25 load-bearing claims was re-opened at the cited file:line (UNC path to `HS/`, `dll_strings.txt` in SCRATCH, read-only `psql` with `default_transaction_read_only=on`). `HS/` = `/var/www/html/HerikaServer`. Nothing was written anywhere except this section.

### Result: 24 of 25 claims confirmed, 1 materially wrong (claim 10), plus 4 smaller corrections to the report body

### Confirmed claims (brief)

1. `explode("|")` parsing, `[4]` optional, no re-join anywhere - `HS/main.php:90-93,135-149`; grep for `implode("|"` in `main.php`/`processor/*.php` only hits `main.php:1852` (CACHE_PEOPLE). Comment at `HS/processor/comm.php:1541` verbatim.
2. `comm.php` required at `HS/main.php:1124` after `prerequest.php` hooks (`:1117`); `$MUST_END=false` reset at `HS/processor/comm.php:7`; `if ($MUST_END)` block at `HS/main.php:1510-1524`.
3. `HS/ext/relationship_system/comm.php` = legacy entry point (15 lines, verbatim). Full grep of `requireFilesRecursively(` call sites matches the report's list exactly (additionally `HS/lang/de/prompts.php:262` and `HS/lang/de/functions.php:783` load the same hooks for the German language pack).
4. Loader `HS/lib/data_functions.php:7729-7747` (`global $gameRequest;` + `require_once`), `terminate()` at `HS/lib/auditing.php:26-48` (releases `MAIN`, `ADDNPC`, `VSX`).
5. `$GLOBALS["external_fast_commands"]` merge at `HS/main.php:233-235`; `SemaphoreWait("MAIN", $semaphore_timeout, 1003, null)` at `:244`; the 300 s comes from `$GLOBALS["SEMAPHORES_TIMEOUT"] = 300;` (`HS/main.php:17`, read at `:239`).
6. `info` prefix branch `HS/processor/comm.php:1343-1347` verbatim; log-only list `HS/main.php:949-985` verbatim.
7. `\d public.eventlog` re-run: 12 columns and 8 indexes exactly as reported. People filter `HS/lib/data_functions.php:2590-2601`.
8. `logEvent($dataArray,$forcePeople='')` `HS/lib/chat_helper_functions.php:5345-5453`, array layout confirmed (`[4]??'pending'`, `[5]` extra columns).
9. `buildHistoricContext` CASE / blacklist / `EXT_CONTEXT_SQL_FILTER1/2` (`:2538-2539`, `:2551-2605`), `PLUGIN` -> `$speaker = $row["type"];` (`:2796-2797`) - copied SQL in section 2.2 is character-accurate.
11. Rechat whitelist `HS/main.php:1355`; diary filters `HS/lib/dynamic_update_util.php:397,1175`; nearby diary `:110`.
12. `\d+ public.memory_v` re-run: definition matches (note exact literal is `'(Context Location:'` - capital L, no space before the location text).
13. `PackIntoSummary` `HS/lib/data_functions.php:4135-4274`: regexes, `is_new_queue` rules, `having count(*)>=$minEventsPerSummary`, default 5, `$pfi = interval*100000` all confirmed. `general_settings` has `...AUTO_CREATE_SUMMARY_INTERVAL=10`; no `AUTO_CREATE_SUMMARY_MIN_EVENTS` row exists, so the code default 5 applies.
14. `logMemory(...)` `HS/lib/chat_helper_functions.php:1955-1972` (note: it writes `$momentum` into both `session` and `momentum`). `generateFollowerDiary` `HS/lib/dynamic_update_util.php:1057`, `diarylog` insert `:1262`, `logMemory(... 'auto_diary' ...)` `:1283`.
15. `HS/gamedata.php:49-62,79-175` closed switch, `default:` -> 400; grep for `requireFilesRecursively|/ext/` in `gamedata.php`: 0 hits.
16. `addnpc` indices 0-43 confirmed line by line (`HS/processor/comm.php:1411-1693`); wire uses `/` inside the stable reference, server converts with `strtr($parts[2], ["/" => "|"])` (`:1563`). DLL strings `addnpc|{}|{}|{}@{}` 46232, `addbgnpc|{}|{}|{}@{}` 46722.
17. Grep re-run over `lib, processor, functions, ext, prompts` (added `IsChild|is_child`): only the hits the report lists. `core_npc_master` columns re-listed from `information_schema` - identical to section 3.3.
18. `request` branch `HS/processor/comm.php:367-378`; `DataDequeue` `HS/lib/data_functions.php:874-907` (`FOR UPDATE SKIP LOCKED` + `UPDATE ... RETURNING`); it is the only caller of `DataDequeue` in the server.
19. `SkyrimCommandBuilder` `HS/lib/scriptproxy_papyrus.php:14-35`, `build` `:858`, `send` `:872-887`; grep `Quest|Topic|SetStage|Say` in that file: 0 hits; the six public groups are exactly `Actor, ObjectReference, FormList, EffectShader, ActorUtil, Faction`.
20. Quest engine: all 12 function names/lines re-grepped and correct (`:111, :173, :569, :884, :1440, :2982, :3139, :3204, :3284, :3418, :3467, :3497, :3658, :3687`); DB: 301 definitions = 218/57/26, `CHIM_AI_QUEST_PROGRESSION=false`; trigger/action type counts re-computed from `skeleton->'beats'` and identical (1124/988/43/1 and 856/516/33/1). DLL strings 48169, 48254-48355 confirmed; `set_stage` really has 0 hits (case-insensitive) in the dump.
21. `_quest`/`_uquest`/`_questdata`/`_questreset` branches `HS/processor/comm.php:381-448,583-586`; `DataGetCurrentTask()` `HS/lib/data_functions.php:3615-3675`; gate `HS/main.php:2077-2087` (also requires `$gameRequest[0] != "diary"`).
22. `TESTopicInfoEvent` sink lambda string at DLL-strings:48612 and RTTI `BSTEventSink<TESTopicInfoEvent>` at 54653; `_speech|{}|{}|{}` 46805; the only `speech` insert in non-test code is `HS/processor/comm.php:675-691`. (See correction C4 for the exact wire formats; the hook-to-string link is by adjacency in the string pool, which is reasonable but still an inference.)
23. `HS/lib/prompt_injections.php` read in full: four signatures and lines exact; rendered slots `character_bottom` (`HS/main.php:2554`) and `prompt_bottom` (`:2565`), placed at `:2597-2600`. The file is loaded from `HS/lib/data_functions.php:16`, i.e. before the `globals.php` hooks run, so calling `chimRegisterPromptInjection()` from `globals.php` is safe.
24. `HS/lib/postgresql.class.php:8` verbatim; 82 tables / 9 views / schemas `chim_meta, plugins, public`; `plugins` has 0 tables; `HS/lib/plugin_package_manager.php:501-538` verbatim.
25. `HS/lib/settings.php:888,907,919,927,935,961` and `HS/lib/game_activity.php:6-29` exact; `setconf` branch `HS/processor/comm.php:1281-1329`.

Additional random spot-checks (all correct): 10 Papyrus native signatures in `AIAgentFunctions.psc` (:27,29,43,44,45,49,59,76,78,83); 18 `NpcMaster` methods with line numbers; 16 `sql` class methods; 10 `Player` class methods; `returnLines` :1285, `offerMemory` :2604, `normalizePeoplePipeList` :2831, `isStrictSpatialPeopleModeEnabled` :4385-4390 (really `return true;`), `buildScopedPeopleFromSpatialEvidence` :4940, `buildScopedPeopleForEvent` :5247, `make_replacements_bracketed` :5545; 16 `Data*` helpers in `data_functions.php`; 5 functions in `utils_game_timestamp.php`; `SemaphoreWait` signature `semaphore_manager.class.php:114`; `chimNormalizeLoggedEventType` `lib/core/event_type.php:11`; `chimSanitizeActivityStatusPayload` :127 / `chimApplyNpcMetadataUpdatesByName` :223; `SCHEMA_VERSION = 4` `plugin_package_manager.php:13`; `playerdied` bug at `comm.php:1240-1241` (selects `gamets`, tests `["ts"]`) is real. Only deviation found: `$GLOBALS["db"] = new sql();` is at `runtime_bootstrap.php:211`, not `:210` (off by one, `:210` is the `if`).

### Corrections

**C1 (claim 10, exec-summary item 4, section 2.2 last paragraph, section 2.3 row `ext_<custom>`) - "unrewritten `ext_*` rows are dropped" is WRONG for the configuration that is active on this machine.**
The connector analysis is correct as far as it goes (`HS/connector/openrouterjson.php:391-527` has no `else` for unknown roles). But the history window normally never reaches the connector as separate messages. `general_settings.COMPACT_CHAT_ENABLED=true` here (and `chimCompactChatEnabled()` defaults to `true` when the global is unset, `HS/lib/compact_context_history.php:10-18`). For every speaker except The Narrator (`chimShouldCompactNpcContextHistory`, `:22-30`), `HS/main.php:2344-2350` does:
```php
$compactHistoryBlock = chimFormatCompactNpcContextHistory($contextDataHistoric, (string)($GLOBALS["HERIKA_NAME"] ?? ""));
$contextDataHistoric = [];
```
and `HS/main.php:2637` appends that block to the system prompt (`chimAppendCompactHistoryToPrompt`). Inside `chimFormatCompactNpcContextHistory` (`HS/lib/compact_context_history.php:185-258`) roles `assistant`, `user`, `tool` get special formatting and **every other role falls into the generic branch at `:242-251`, which keeps the entry** as a `# <whitespace-collapsed content>` line. Nothing between `DataLastDataExpandedFor` and that call filters by role (`chimAttachShortTermMemoryToWindow` `data_functions.php:5058-5080` only strips `_g`; `filterHistoricContextForNarratorVisibility` `chat_helper_functions.php:5306-5334` only filters narrator-directed lines).
Consequence: an eventlog row of type `ext_glue_*` whose `people` matches the NPC **is shown to that NPC's LLM verbatim** (raw JSON included) in compact mode; it is dropped only for The Narrator or when `COMPACT_CHAT_ENABLED=false`. The glue must therefore either (a) never `logEvent` raw telemetry (handle + `terminate()` in `preprocessing.php`, as section 2.5 already proposes), or (b) remove/rewrite such rows in `context_building.php`, or (c) exclude them with `$GLOBALS["EXT_CONTEXT_SQL_FILTER1"] = " and type not like 'ext\\_glue\\_%' "`. Rewriting the role to `user` is NOT required for visibility in compact mode; it only matters for the Narrator / non-compact path. Also relevant: before that, `compactHistoricContext` (`data_functions.php:3112-3113,3143-3144`) passes PLUGIN-role content through `moveDialogueTargetSuffixToEnd()` (`:2051`), which collapses all whitespace, and drops entries whose content is empty (`:3148-3151`).

**C2 (section 1.3 hook table, row `globals.php`) - wrong example attribution.** The row says "Register `$GLOBALS["external_fast_commands"]`, prompt injections, actor enrichers, `EXT_CONTEXT_SQL_FILTER1/2`. Example: `ext/xLifeLink_plugin/globals.php`". That file (read in full, 51 lines) only sets `$GLOBALS["UPDATE_PERSONALITY_PROMPT"]` and `$GLOBALS["CustomUpdateProfileFunction"]`. A grep over `HS/ext` for `external_fast_commands|EXT_CONTEXT_SQL_FILTER|CONTEXT_BUILDING_DATA|chimRegister` returns **0 hits**, and over the whole server these names occur only at their definition/consumption sites (`main.php:233-237`, `data_functions.php:2538-2539,3216-3221`, `prompt_injections.php`). These extension points exist in core but are exercised by no shipped plugin and no unit test found - treat them as untested API. The hook files that actually ship are only: `herika_heal/functions.php` (commented out), `relationship_system/context_pre.php`, `relationship_system/postrequest.php`, `time_awareness/context.php`, `xLifeLink_plugin/globals.php`, `xLifeLink_plugin/prerequest.php`.

**C3 (section 1.3, prompt-injection paragraph) - incomplete: actor enrichers are also called for NPCs.** Besides the player call at `HS/lib/data_functions.php:1085`, `chimBuildActorProfileEnrichmentText($npcName, "npc", ["source" => "nearby_actors", "metadata" => $metaData, "npc_data" => $currentNpcData])` is called for every nearby NPC at `HS/lib/data_functions.php:1227-1231` (same `$nearbyActorsIncludeCustomState` gate). So an enricher registered with `chimRegisterActorProfileEnricher()` receives `$actorType` `"player"` or `"npc"`, and for NPCs gets the full `core_npc_master` row and decoded metadata in `$context` - the natural place to surface per-NPC glue state (e.g. "currently engaged in a scene with X") to bystanders.

**C4 (claim 22 / section 2.4) - two different wire formats, not one.** DLL-strings:48625 `chat|{}|{}|(Context location: {}){}: {} ({} {})` is followed by `debug` / `traditional_player_speech` (48626-48627) and the three strings `Shouting to` / `Whispering to` / `Talking to` precede it (48622-48624): this is the **player's** menu line, with a `(Talking to X)` suffix. The **NPC's** vanilla line is DLL-strings:48633 `chat|{}|{}|(Context location: {}){}: {}` (no suffix), followed by `audios` / `traditional_npc_speech` (48634-48635). Server side, the `debug` value is stored in `speech.topic` (`HS/processor/comm.php:669-688`), so vanilla lines are identifiable later with `speech.topic LIKE 'traditional_%'`.

**C5 (Implications item 1) - `migrations/*.sql` and "manual install = drop a folder" are mutually exclusive.** `runMigrations()` is private and only reached from `activateServerComponent()` (`HS/lib/plugin_package_manager.php:444-481,501-538`), i.e. only when the plugin is installed as a `.dwpkg`/`.zip` package through the package manager. A folder copied into `HS/ext/` never has its migrations executed. If the glue is to support manual installation it must create its tables itself (idempotent `CREATE TABLE IF NOT EXISTS plugins.<t>` guarded by a cheap existence check). Schema `plugins` is guaranteed to exist: `chimRuntimeEnsurePluginSchema()` runs `CREATE SCHEMA IF NOT EXISTS plugins` on every bootstrap (`HS/lib/runtime_bootstrap.php:134-147,214`).

### Additions (facts the report missed that matter for the glue)

A1. **Server-side action veto hook exists.** `$GLOBALS["action_post_process_fnct_ex"][] = function($actions) {...}` (registered by core at `HS/functions/functions.php:2830`; consumed at `HS/lib/data_functions.php:6029-6039` as `foreach (... as $postFilterFunc) $actions=$postFilterFunc($actions);`, plus the single-callable legacy `$GLOBALS["action_post_process_fnct"]` at `:6031-6033`). `$actions` is the array of `|`-delimited action strings produced by `$connectionHandler->processActions()` before they are sent to the game. This is the right place for the server half of the "hard gate" on the intimacy action (drop the action unless the eligibility flag is set and the request is player-driven).

A2. **Actions are not limited to player-driven requests on this install.** `HS/main.php:1078-1080` disables functions for everything except `inputtext, inputtext_s, ginputtext, ginputtext_s, narrator_inputtext, instruction, welcome, cheatmode`, but `HS/main.php:2139-2218` re-enables them for `rechat`/`narration` when `$GLOBALS["RECHAT_ALLOW_ACTIONS"]` is truthy - and `conf_opts.RECHAT_ALLOW_ACTIONS` = `true` here. Core removes only a fixed list there (`unsetFunction("OpenInventory")`, `TravelTo`, `ComeCloser`, `IncreaseWalkSpeed`, `DecreaseWalkSpeed`, `OpenInventory2`, `FollowPlayer`, `:2161-2168`); a plugin action survives. `instruction` requests (server/rolemaster-initiated) also force `$FUNCTIONS_ARE_ENABLED=true` (`:1091-1092`). The glue must call `unsetFunction($functionCodename)` (`HS/functions/functions.php:2706`) for its intimacy action whenever `$gameRequest[0]` is not in `inputtext, inputtext_s, ginputtext, ginputtext_s`, and enforce the same in A1.

A3. **`globals.php` hooks run before the request is parsed.** They are included at `HS/main.php:54`, while `$gameRequest` is only assigned at `:135`; inside `globals.php` `$gameRequest` is `null` (the `global` import just creates the empty global). `globals.php` is also included by several UI/API scripts (`HS/ui/api/tts_pronunciation_preview.php:62`, `HS/ui/api/chim_diary_audio.php:35`, `HS/ui/tests/*.php`) outside any game request. Keep `globals.php` to pure registration (fast-command list, injections, enrichers, SQL filters); branch on the request type in `preprocessing.php` (`main.php:193`, first point where `$gameRequest[0]` is valid and lower-cased, `$db`/`$GLOBALS["db"]` connected, no semaphore taken yet).

A4. **`sql` helper limits for schema-qualified tables.** `search_path` is forced to `public` (`HS/lib/postgresql.class.php:28` and again in `chimRuntimeEnsurePluginSchema`), so plugin tables must always be written as `plugins.<table>`. `insert()` (`:128-158`) and `upsertRowOnConflict()` (`:667-714`) interpolate the table name raw, so `plugins.glue_x` works; `insertReturningId()` (`:160-197`) validates identifiers with `/^[A-Za-z_][A-Za-z0-9_]*$/` and **rejects a dotted name** (returns 0). `upsertRowOnConflict` escapes values with `pg_escape_literal`; `insert` uses `pg_query_params`; `fetchAll/fetchOne/update/delete` take raw SQL - escape with `$db->escape()`.

A5. **`logEvent` ignores `$forcePeople` for three types.** After people scoping, `death`, `itemfound` and `chat_background` are unconditionally overwritten with `DataBeingsInCloseRange(...)` (`HS/lib/chat_helper_functions.php:5422-5432`). `infoaction`, `info_*` and `ext_*` honour `$forcePeople`. Also `logEvent` uses `global $db` - fine from `preprocessing.php` onwards (main.php sets `$db` at `:166`).

A6. **`PackIntoSummary`: a mismatching row does more damage than being dropped.** Because `is_new_queue` is 1 when `location_key<>prev_location_key` *and* when `location_key is null` (`HS/lib/data_functions.php:4217-4224`), a single `logMemory` row whose location key differs from its neighbours (or has none) starts a new queue for itself **and** forces the next dialogue row to start another queue. It splits the surrounding conversation in two; either half can then fall under the 5-row minimum and be discarded. The scene-end memory row must therefore reproduce the neighbours' key exactly: the key is the text after `(Context location:` up to the first `,` or `)`, lower-cased and whitespace-normalised; for `speech` rows the view builds it from `speech.location` as `'(Context Location:' || location || ') '`. Also: the view filter `memory.event <> 'backgroundlife_diary'` silently excludes rows with `event IS NULL`, so direct inserts into `memory` must always set `event`.

A7. **`restoreNPC` rollback is coarser than "save/load consistent".** On `init`, `HS/lib/core/npc_master.class.php:1400-1511` deletes every `core_npc_master` row that is not The Narrator, has `lock_profile=0` and `gamets_last_updated>0`, then re-inserts the newest `core_npc_master_history` row with `gamets_last_updated <= load gamets`. Effects for glue state kept in `metadata`/`extended_data`: (1) state reverts to the last `infosave` snapshot at or before the loaded gamets, not to the load point; (2) NPCs with `lock_profile=1` are never rolled back; (3) NPCs never snapshotted (`gamets_last_updated` NULL/0, i.e. added since the last save) keep their current state; (4) NPCs whose only snapshots are newer than the loaded save are deleted and come back blank on the next `addnpc`. Eligibility/consent flags must therefore be re-sent by the game after every load and after every `addnpc`, never assumed present. `addnpc` itself is safe for foreign keys: it does read-modify-write of the whole `metadata`/`extended_data` (`comm.php:1466,1670-1695`), so unknown keys survive, but it is not atomic with concurrent `updateMetadataKeysByName` calls.

A8. **`init` details for a glue reset branch.** `init` with `$gameRequest[2] == "10000000"` is ignored by core (`HS/processor/comm.php:107-111`) and must be ignored by the glue too; `$gameRequest[3]` of `init` is the DLL version string (stored as `conf_opts.plugin_dll_version`, `:182-191`); `init` is not a fast command, so it waits on the MAIN semaphore, but a `preprocessing.php` hook sees it before that wait. `init` also wipes `responselog` entirely (`:138`), so any queued glue command is lost on load.

A9. **The quest-engine outbox cannot be reused while the engine is off.** `chimQuestEngineFetchPendingActions()` returns `[]` when `chimQuestEngineFeatureEnabled()` is false (`HS/lib/chim_quest_engine.php:3284-3288`), `chimQuestEngineHandleEvent()` short-circuits with `'disabled' => true` (`:3139-3148`), and the DLL has its own guard string `quest progression disabled before execution` (DLL-strings:48339). The exec-summary wording "keep it off, or reuse its outbox" is an either/or: reusing the outbox (which does offer `console_command`, `console_command_sequence`, `start_scene`, `set_relationship_rank`, DLL-strings:48295-48336) requires turning the whole engine on, including its 301 vanilla skeletons firing on dialogue. `chimQuestEngineHandleLiveDialogueTurn` only runs for `inputtext, inputtext_s, ginputtext, ginputtext_s` when `CHIM_PLAYER_ONLY_QUEST_ADVANCEMENT=true` (`:3219-3224`).

A10. **Race strings can carry child races.** No age flag is stored (confirmed), but `core_npc_master.race` comes straight from the game and core itself knows child race ids (`'NordChildRace'`, `'ImperialChildRace'`, `'BretonChildRace'`, `'RedguardChildRace'` in `HS/lib/core/tts_fallback.class.php:75-94`). A cheap defence-in-depth check on the server (deny when `race` matches `/child/i`) can back up, but never replace, the authoritative game-side `IsChild()` check.

A11. Minor: `type like 'ext_%'` uses an unescaped `_`, which is a single-character wildcard in SQL `LIKE`, so any type beginning `ext` + one character (e.g. `extra...`) is also classified `PLUGIN` (`HS/lib/data_functions.php:2576`). Keep glue type names strictly `ext_glue_*` / `info_glue_*`. `eventlog.type` is `varchar(128)`, `memory.event` is `varchar(64)`.

### Confidence

High for everything confirmed above (each item re-read at source). The one substantive error (C1) changes a design recommendation but not any API name or signature; no misspelled, mis-cased or mis-attributed function/table/column name was found in roughly 120 names checked.
