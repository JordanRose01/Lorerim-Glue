# CHIM official documentation (web) vs. the local 3.3.2 install - plugin-developer surface, 3.3.x features, adult-content guidance

Research date: 2026-09-21. Read-only. Local server: `\\wsl.localhost\DwemerAI4Skyrim3\var\www\html\HerikaServer` (`.version_number.txt` = `3.3.2`, `.version.txt` = `2026090214`). Local game mod: `F:\Modlists\LoreRim\mods\CHIM` (`meta.ini:4` version=3.3.2.0; DLL build path string `D:\wt\chim-332-main-voice-hotfix\Plugin\...`, build date string `2026-09-02`).

Abbreviations: HS = HerikaServer root. DLL@0x... = ASCII string found at that file offset of `F:\Modlists\LoreRim\mods\CHIM\SKSE\Plugins\AIAgent.dll` (string extraction only, no disassembly).

## Executive summary (10 lines)

1. Official dev docs exist in three places: `dwemerdynamics.com/chim/modders-guide.html` (current, detailed), GitHub `docs/` folders added 2026-09-19 to the `unstable` branches of HerikaServer and CHIM (newer than the local 3.3.2), and the old hostwiki (unreachable from my tools; only search snippets).
2. CHIM ALREADY SHIPS the headline feature in beta: "Traditional Quests" / "Menuless Questing (BETA)" = setting `CHIM_AI_QUEST_PROGRESSION` (default false; false on this install), 301 bundled quest skeletons (`HS/data/skyrim_quest_definitions.json`), server engine `HS/lib/chim_quest_engine.php`, DLL poller (`quest_action_poll` / `quest_action_ack`) and Papyrus bridge `AIAgentQuestProgressionBridge.psc`.
3. That engine is stage-level and hand-authored (keyword / LLM-intent "beats" -> `Quest.SetStage`, objectives, items); it does NOT execute real dialogue INFO result scripts; mod quests "aren't automatically detected" (docs). There is an undocumented data-mod import `Data/CHIM/*_tradquest.csv` for adding definitions.
4. Game-side custom action contract is verified from upstream C++ and DLL strings: command `ExtCmd<BridgeScript>_<Action>` -> static Papyrus call `<BridgeScript>.DispatchExternalCommand(npc, command, parameter)`; fallback mod event `CHIM_CommandReceived` (3 strings). `WebCmd*` = server-resolved; `IntCmd*` = internal event.
5. Result contract: bridge reports `command@<code>@<param>@<result>` with type `funcret`; LLM follow-up only happens if the action's catalog metadata has `followup.enabled` + `followup.prompt` (otherwise `terminate()`).
6. Actions are now DB/catalog-driven (`core_action`, `core_action_custom`, `_actions.csv`), with built-in Prisma Accept/Decline confirmation; code names `acceptsex`, `kiss`, `makelove`, `removeclothes`, `sexaction` default to "ask".
7. Breaking vs MinAI-era: `SPG_CommandReceived`/`SPGPapFunctions` names are gone; `$GLOBALS["FUNCRET"]` has no consumer in 3.3.2; `ext/*/functions.php` is still loaded but is no longer documented; MinAI repo now returns 404.
8. Documented-but-missing locally: `NpcMaster::getPluginData/setPluginData/deletePluginData` + `core_npc_master.plugin_extended_data`, `lib/playthrough_policy.php`, `docs/` folder (all post-3.3.2 `unstable`).
9. Adult content: no official policy page; but the OFFICIAL plugin repository features an NSFW OStim/SexLab plugin (SHARMAT, `aiagent_nsfw`). Core prompts contain no content filter. The connector UI warns about OpenAI/Anthropic/Google ToS; only Google models get `safety_settings` BLOCK_NONE (opt-in `block_none`).
10. User's connectors are all `openrouterjson`; "Grok 4.3" (`x-ai/grok-4.3`) uses `extra_parameters` = `{"reasoning":{"effort":"none"}}`, `enforce_json=1`, `json_schema=1`. Nothing server-side blocks explicit lines; any blocking would come from the model/provider.

---

## 1. Official documentation pages found (URL + summary)

### 1.1 `https://dwemerdynamics.com/chim/modders-guide.html` (current "Modders Guide") - VERIFIED (full page text read)

Sections: Custom CHIM Data Mods; Creating Plugins; Action Editor; Per-NPC Plugin Data; Biography Voice Filters.

Data mods (`Data/CHIM`, top level only, real `.csv`, reject >10 MB, warn >5 MB). Documented suffixes:

```
_bios.csv  _oghma.csv  _dynamicoghma.csv  _descriptions.csv  _actions.csv  _voices.csv (local only)
```

Server plugins: live in `HerikaServer/ext/<plugin-name>`; short quote: "loads plugin files by filename". `manifest.json` fields documented:

```
name, description, config_url, config_url_target, git_repo, default_channel, channels, version, schema_version (use 2 for current update flow)
```

Documented hook files / APIs (exact names):

```
globals.php            early global setup
preprocessing.php      early request preprocessing
prerequest.php         before the main request processor (before processor/comm.php)
context_pre.php        before the system prompt is built (example mutates $GLOBALS["PROMPT_NEARBY_SECTIONS"])
context.php            after the system prompt is built ($head[] already has the system message)
context_building.php   alters $GLOBALS["CONTEXT_BUILDING_DATA"]
prompts.php, dialogue_prompt.php   extend/override prompt text
prepostrequest.php, postrequest.php   after request processing
json_response_custom.php   JSON response template shaping
chimRegisterPromptInjection()        slots: character_bottom, prompt_bottom; lower priority renders first
chimRegisterActorProfileEnricher()   callback($actorName, $actorType, $context) -> line or list of lines
```

The guide states AI actions are no longer a hook file: "Current action loading is data-driven rather than a separate plugin hook file." Actions go through the Action Editor / action catalog / `_actions.csv`.

`_actions.csv` columns documented:

```
code_name, action_name, description, return_message,
available_to_npc, available_to_followers, available_to_narrator,
is_activated, game_function, parameters_json, metadata, import_version, script_proxy_program
```

Two execution models: "plugin-command" (game-side Papyrus/DLL bridge) vs "script-proxy" (server runs `script_proxy_program`: `switch_on`/`cases`, `commands` [`cmd_id`, `args`, `delay_seconds`], `db_inserts`, `npc_metadata_updates`). Default: if `script_proxy_program` present and no `metadata.dispatch` -> `script_proxy`, else `plugin_command`.

Return-message placeholders: `#HERIKA_NAME# #PLAYER_NAME# #TARGET# #LOCATION# #ITEM# #AMOUNT# #RESULT#`.

Shared action config (Advanced Options): Follow-up Enabled, Require Confirmation (Prisma Accept/Decline; decline hard-cancels, no replacement AI reply), Follow-up Argument Name, Follow-up Prompt, Allow Follow-up Actions (chain limit currently one). Per-action fields via `metadata.editor_fields`; requirement example `npc_name_in_action_config_list`; parameter template example `{{config.cost_gold}}`, `{{parameter_target}}`.

Official game-side bridge example (CHIM-NFF; `CHIM-NFF/CHIM/nff_actions.csv`, `CHIM-NFF/Source/Scripts/CHIMNFF.psc`). Action row metadata and Papyrus entry point as documented:

```
code_name:  ExtCmdCHIMNFF_FollowMe
metadata:   {"dispatch":"plugin_command","source":"CHIM-NFF","integration":"nff","bridge_script":"CHIMNFF","bridge_entrypoint":"DispatchExternalCommand"}
```
```papyrus
bool Function DispatchExternalCommand(string asNpcName, string asCommand, string asParameter) Global
    ...
    AIAgentFunctions.logMessageForActor("command@" + asCommand + "@" + asParameter + "@" + resultText, "funcret", asNpcName)
```

Packaging: GitHub release asset `<PACKAGE_NAME>.tar.gz` or `.tar`; `migrations/NNN_*.sql` (idempotent, `plugins` PostgreSQL schema, tracked in `plugins.plugin_migrations`); bundling with a Skyrim mod under `Data/CHIM/server-plugins/<plugin-name>` as versioned `.zip`/`.dwpkg`; repository listing = PR to `ui/data/plugin_repository.json` on branch `unstable`.

Per-NPC plugin data: `NpcMaster` methods `getPluginData`, `setPluginData`, `deletePluginData` (see 3.2: missing locally).

### 1.2 `https://dwemerdynamics.com/chim/plugins.html` - VERIFIED
User-facing list: CHIM Custom, CHIM Twitch Bot, CHIM Mind Map, CHIM-BES, CHIM-BiSR. States plugins live in `HerikaServer/ext` and use the `plugins` schema. No developer API detail. (Does not list SHARMAT, which is only in the in-server repository JSON.)

### 1.3 `https://dwemerdynamics.com/chim/roleplay-settings.html` - VERIFIED
Sections include "Traditional Quests" and "AI Quest Manager". Key statements (paraphrased; one short quote each): supported vanilla + official DLC quests can be started/advanced through AI conversation; radiant quests unsupported; definitions reviewable under Immersion -> AI Quest Manager -> Traditional Quests; modded quests can be added manually but "aren't automatically detected"; can be disabled globally; "Skyrim always stays the authority on quest state". AI Quest Manager = separate generated-questline system (SNQE). Director Scenes documented here too.

### 1.4 `https://dwemerdynamics.com/chim/ingame-settings.html` - VERIFIED
MCM/Prisma: toggles "Enable AI Actions", "Enable Animations", "Player TTS for Traditional Dialogue"; "NPC Scene Safety" (prevents AI NPCs in traditional dialogue scenes from auto-responding); Prisma hotkeys (Master Menu, CHIM Chat, Chatbox View / Context Window, Actions Menu, History/Diaries, Browser, Logs); chat modes Standard, Whisper, Close, Shout, Narrator, Director, Cheat Mode, Auto Chat; prefixes `%` whisper and `#` cheat; "Conversation capture" control to include ordinary Skyrim dialogue in recent context.

### 1.5 `https://dwemerdynamics.com/chim/configuration.html`, `llm.html`, `faq.html`, `index.html` - VERIFIED
- configuration: Action Editor - actions can run automatically or wait for Accept/Decline in Prisma.
- llm: providers (Local KoboldCPP/LM Studio, OpenRouter, OpenAI, Google, Groq, Nano-GPT, Player2); drivers "OpenAI JSON", "Google OpenAI JSON", "Groq JSON"; advanced options "Reasoning Model Fix", "Enforce JSON", "JSON Schema", "Prefill JSON", "Disable Streaming". No model recommendations, no content-filter discussion.
- faq: nothing on NSFW, MinAI, plugins, refusals.
- index: feature list incl. quest progression via dialogue, action permissions (automatic / confirmation / disabled), in-game settings hub.

### 1.6 GitHub `Dwemer-Dynamics/HerikaServer` branch `unstable`, `docs/` (PR #101, merged 2026-09-19; NEWER than local)
- `docs/custom-plugins.md` (https://raw.githubusercontent.com/Dwemer-Dynamics/HerikaServer/unstable/docs/custom-plugins.md): hook table identical to 1.1 with callers (`main.php`, `lib/data_functions.php`, `prompts/prompts.php`, `prompts/dialogue_prompt.php`, `functions/json_response.php`); warns "Do not assume every endpoint executes every hook"; warns that bundled `ext/` examples may reference old Papyrus names; package manager `lib/plugin_package_manager.php` accepts `.dwpkg`/`.zip` schema version 4 with layout:
```
manifest.json            {"schema_version":4,"name":"ExamplePlugin","version":"1.0.0","server":{"mutable_paths":["config.json"]}}
checksums.sha256         SHA-256 line for every non-directory entry except itself
server/                  installs into ext/<name>/  (manifest.json, hook files, migrations/001_initial.sql ...)
```
  separate from the older tarball installer `ext/generic_installer.php` ("Do not mix their layouts"). Game discovery path: `Data/CHIM/server-plugins/<package>/<version>.dwpkg`; transfer contract = `ui/api/plugin_packages.php` + `Plugin/ServerPluginSync.cpp`.
- `docs/plugin-npc-data.md`: `core_npc_master.plugin_extended_data` JSONB; `$npcMaster->setPluginData($npcId, 'chim_custom', [...])`, `getPluginData($npcId, ns)`, `deletePluginData($npcId, ns)`; plugin IDs match `[a-z][a-z0-9_-]{0,63}`; not auto-injected into prompts.
- `docs/agent-guide.md`: request flow `comm.php` -> `main.php` -> `lib/runtime_bootstrap.php`, `processor/`; actions in `lib/core/action_catalog.php`, `functions/functions.php`, `functions/json_response.php`; default log `/var/www/html/HerikaServer/log/chim.log`.
- `docs/building.md`: PHP 8.2+, PHPUnit 11; DB tests drop/recreate `testdb` (never run on the user's runtime).

### 1.7 GitHub `Dwemer-Dynamics/CHIM` branch `unstable`, `AIAgent/docs/CHIM/` (PR #240, merged 2026-09-19)
- `custom-plugins.md` (https://raw.githubusercontent.com/Dwemer-Dynamics/CHIM/unstable/AIAgent/docs/CHIM/custom-plugins.md): "Commands shaped as `ExtCmd<BridgeScript>_<Action>` attempt a static `DispatchExternalCommand` call"; `AIAgentFunctions.sendMessageToActor` takes an Actor (name-only routing unsafe with duplicate names); do not replace `AIAgentFunctions.pex`; old names `SPG_CommandReceived` / `SPGPapFunctions` must not be copied.
- `agent-guide.md`: source map (`Plugin/Commands.cpp`, `Plugin/Papyrus.cpp`, `Plugin/SPGResponse.cpp`, `Plugin/PrismaUIBridge.cpp`, `Plugin/Conf.cpp`...); connection discovery order: `Data/SKSE/Plugins/AIAgent.ini`, launcher at `127.0.0.1:7135`, fallback `127.0.0.1:8081/HerikaServer/comm.php`.
- Repo README: monorepo (`AIAgent/` = former aiagent-aiff, `Plugin/` = former HerikaAI-NG), GPL-3.0. GitHub Releases page is empty (no changelog there).
- `AIAgent/Optional/`: `TraditionalDialoguePlayerTTS` and `-VR` = optional `Interface/dialoguemenu.swf` overrides for "Player TTS for Traditional Dialogue".

### 1.8 Upstream C++ (read verbatim) `https://raw.githubusercontent.com/Dwemer-Dynamics/CHIM/unstable/Plugin/Commands.cpp`
ExtCmd / IntCmd / WebCmd branch (condensed; identifiers exact):

```cpp
} else if (command.rfind("ExtCmd", 0) == 0) {
    responsePop("command");
    auto npc = agentPtr->getActorName();
    auto separatorPos = localCommand.find('_', 6);
    if (separatorPos != std::string::npos && separatorPos > 6) {
        std::string bridgeScript = localCommand.substr(6, separatorPos - 6);
        // args: (npc, localCommand, localParameter) - three strings
        dispatched = VM->DispatchStaticCall(bridgeScript, "DispatchExternalCommand", bridgeArgs, callback);
    }
    if (!dispatched) { VM->DispatchStaticCall("AIAgentAIMind", "SendExternalEvent", fallbackArgs, callback); }
} else if (command.rfind("IntCmd", 0) == 0) {
    VM->DispatchStaticCall("AIAgentAIMind", "SendInternalEvent", args, callback);
} else if (command.rfind("WebCmd", 0) == 0) {
    HTTPManager::log(std::format("funcret|{}|{}|{}", ts, gamets, "command@" + command + "@" + trim(parameter) + "@"));
}
```
Local 3.3.2 DLL contains the same literals in the same order: `ExtCmd` DLL@0x2e8bbc, `DispatchExternalCommand` @0x2e8bc8, `SendExternalEvent` @0x2e8be0, `IntCmd` @0x2e8bf4, `SendInternalEvent` @0x2e8c00, `WebCmd` @0x2e8c14, `funcret|{}|{}|{}` @0x2e6c50.

### 1.9 Old wiki `https://dwemerdynamics.hostwiki.io/en/CHIM-Plugins` - NOT READABLE
WebFetch: TLS error; browser navigation refused; web.archive.org blocked. Only search-engine snippets: manifest example (`"name":"twitch-bot"`, `"schema_version":2`), "bind to the CHIM_CommandReceived event", `RegisterForModEvent("CHIM_CommandReceived", "MyCommand")`, ExtCmd vs WebCmd explanation matching `HS/ext/herika_heal/functions.php`. Treat as UNVERIFIED secondary.

### 1.10 Nexus `https://www.nexusmods.com/skyrimspecialedition/mods/126330` - NOT READABLE (HTTP 403 for description and `?tab=logs`)
Search snippet only: "Menuless Questing (BETA)" = progress most vanilla quests through AI dialogue without the standard dialogue menu; Prisma UI provides chatbox, settings, diaries, logs and action confirmations. 3.3.x changelog text: NOT FOUND.

### 1.11 Third-party prior art
- SHARMAT `https://github.com/Wondernuttz/Sharmat-Alpha` (`manifest.json`: `"name":"aiagent_nsfw"`, `"version":"3.1.9.3"`, `"schema_version":2`, `config_url` `/HerikaServer/ext/aiagent_nsfw/config_manager.php`). Root hook files present: `context.php, context_building.php, context_pre.php, functions.php, postrequest.php, prepostrequest.php, preprocessing.php, prerequest.php, prompts.php`. Its `functions.php` uses legacy globals `F_NAMES, F_TRANSLATIONS, FUNCTIONS, FUNCSERV, ENABLED_FUNCTIONS, DEFINED_FUNCTIONS, action_post_process_fnct_ex, HOOKS.JSON_TEMPLATE, AVOID_LLM_CALL, COOLDOWNMAP, PATCH_ACTION_ALL_ACTORS` and 36 `ExtCmd*` code names (e.g. `ExtCmdStopScene`); event types named in its `PROMPT_FLOWCHARTS.md`: `chatnf_sl`, `ext_nsfw_sexcene`, `ext_nsfw_npc_scene`; consent tools `AcceptSex` / `RefuseSex`. Game side: `mod/AIAgentNSFW.esp`, `Scripts`, `Source/Scripts`, `Seq`, `SKSE/Plugins/StorageUtilData`.
- MinAI `https://github.com/MinLL/MinAI` (+ `ModdersGuide.md`, releases): HTTP 404 on 2026-09-21. Search snippet says project is no longer maintained. MinAI API details: NOT FOUND.

---

## 2. CHIM 3.3.x feature set relevant to the glue

Release notes proper: NOT FOUND (Nexus 403, GitHub releases empty). Facts below are from official doc pages plus the local 3.3.2 source.

### 2.1 Quest progression ("Traditional Quests", "Menuless Questing (BETA)") - VERIFIED locally

Settings (`HS/conf/conf_schema.json:61-62`, `HS/conf/conf.sample.php:61-62`, `HS/lib/core/prisma_settings_catalog.php:68-69`, labels `HS/lib/settings.php:378-379`):
```
CHIM_AI_QUEST_PROGRESSION            boolean, default false   "AI Quest Progression (Beta)"
CHIM_PLAYER_ONLY_QUEST_ADVANCEMENT   boolean, default true
```
Stored in `public.general_settings`; this install: `CHIM_AI_QUEST_PROGRESSION=false`, `CHIM_PLAYER_ONLY_QUEST_ADVANCEMENT=true` (SELECT on 2026-09-21). Read by `chimQuestEngineFeatureEnabled()` `HS/lib/chim_quest_engine.php:111` and `chimQuestEnginePlayerOnlyAdvancementEnabled()` `:173`.

Data: `HS/data/skyrim_quest_definitions.json` (1.3 MB): 300 `"skeleton_type":"quest"` + 1 `"radiant_template"`; plugins referenced: Skyrim.esm, Dragonborn.esm, Dawnguard.esm. DB has 301 rows, all active (`public.skyrim_quest_definitions`). Tables: `skyrim_quest_definitions(quest_key, quest_editor_id, title, source_plugin, source_form_id, source_path, skeleton jsonb, active, ...)`, `skyrim_quest_instances`, `skyrim_quest_beat_state`, `skyrim_quest_events(id, quest_key, event_type, event_source, npc_name, location_name, gamets, payload_json, ...)`, `skyrim_quest_action_outbox(id, quest_key, beat_id, action_type, action_gamets, payload_json, status, result_json, created_at, updated_at, applied_at)`. Schema file `HS/data/chim_quest_engine.sql`.

Skeleton shape (from the bundled JSON, first entry `BardsCollegeLute`):
```
quest_key, skeleton_type, quest_form_id "0x000D93FA", quest_plugin, quest_editor_id, title, description,
natural_start {enabled, beats[], requires[]},
npc_facts { "<NPC name>": { base_facts[], beat_facts{ BEAT_ID: [] } } },
beats[] { id, focus_npc, comment, action{type,...}, downstream[], triggers[], trigger_mode "any"|"all",
          prerequisites[], allow_natural_start, start_conditions[] }
```
Type vocabulary counted in the bundled JSON: triggers/conditions `quest_stage` (1124), `dialogue` (988: `keywords[]`, `min_matches`), `quest_beat_fired` (130), `dialogue_intent` (43), `stage_not_done`, `stage_done`, `quest_completed`, `quest_not_started`, `item_acquired`, `actor_alive`; actions `set_stage` (867), `gate` (516), `set_objective_displayed` (211), `set_objective_completed` (188), `stop_quest` (169), `set_stage_cascade` (33), `remove_item` (9), `add_item` (4), `set_relationship_rank` (2), `enable_ref`, `cross_quest_start`, `actor_dialogue_start_quest_stage_objective`. Trigger evaluation: `chimQuestEngineTriggerMatchesEvent()` `HS/lib/chim_quest_engine.php:2423` (types `dialogue`, `quest_stage`, `location_entered`, `actor_dead`, `item_acquired`); LLM intent selection `chimQuestEngineSelectDialogueBeatByIntent()` `:2219`.

Flow (VERIFIED call sites):
1. After every NPC reply, `HS/lib/chat_helper_functions.php:1945-1950` calls `chimQuestEngineHandleLiveDialogueTurn($npcName, $npcResponseText, array $gameRequest)` (`chim_quest_engine.php:3204`), which builds a `dialogue_turn` payload (`event_source 'live_dialogue'`, `npc_name`, `player_text`, `npc_text`, `player_driven`, `request_type`, `gamets`, `ts`, `location_name`, `listener`) and calls `chimQuestEngineHandleEvent('dialogue_turn', $payload)` (`:3139`). With player-only on, only request types `inputtext`, `inputtext_s`, `ginputtext`, `ginputtext_s` qualify (`:3219-3224`).
2. Fired beats queue game actions: `chimQuestEngineQueueAction($questKey, $beatId, $actionType, array $payload, $gamets = null)` `:1440` into `skyrim_quest_action_outbox`.
3. The DLL polls `HS/gamedata.php` (JSON POST, `type` field): `quest_event` `:125`, `quest_action_poll` `:132` -> `{ok, actions:[{id, quest_key, beat_id, action_type, action_gamets, payload, status,...}]}` (`chimQuestEngineFetchPendingActions($limit = 25)` `:3284`, returns empty when the feature is off), `quest_action_ack` `:139` (`action_id`, `status`, `result`), plus `quest_import_bundled`, `quest_reset_runtime`, `quest_status`, `player_item_acquired`, `player_items_acquired`. Matching DLL strings: `quest_event` @0x30f498, `quest_action_poll` @0x310ae0, `quest_action_ack` @0x310920, `quest progression disabled before execution` @0x3108f0.
4. The DLL applies actions through static Papyrus calls on `AIAgentQuestProgressionBridge` (DLL@0x315d50). Bridge functions (`F:\Modlists\LoreRim\mods\CHIM\Source\Scripts\AIAgentQuestProgressionBridge.psc`, all `Global`):
```papyrus
Function SetQuestStage(int questFormId, int stage)                                   ; :4
Function SetQuestObjectiveCompleted(int questFormId, int objectiveIndex, bool completed = true)   ; :11
Function SetQuestObjectiveDisplayed(int questFormId, int objectiveIndex, bool displayed = true, bool forceDisplayed = false) ; :18
Function SetQuestStageObjective(int questFormId, int stage, int objectiveIndex)      ; :25
Function FailAllQuestObjectives(int questFormId)                                     ; :34
Function StartQuest(int questFormId)                                                 ; :41
Function StartQuestStageObjective(int questFormId, int stage, int objectiveIndex)    ; :48
Function ExecuteConsoleCommand(String command)                                       ; :61 (ConsoleUtil)
Function ExecuteConsoleCommandSequence(String commands)                              ; :67 ("||" separated)
Function StopQuest(int questFormId)                                                  ; :84
Function StartScene(int sceneFormId)                                                 ; :91
Function SetActorValue(int actorFormId, string actorValue, float value)              ; :98
Function SetActorGhost(int actorFormId, bool ghost)                                  ; :105
Function EvaluateActorPackage(int actorFormId)                                       ; :112
Function RemoveItemFromPlayer(int itemFormId, int count = 1, bool silent = false)    ; :119
Function AddItemToPlayer(int itemFormId, int count = 1, bool silent = false)         ; :127
Function EnableReference(int refFormId, bool fadeIn = false)                         ; :135
Function SetActorRelationshipToPlayer(int actorFormId, int rank)                     ; :142
```
   DLL action-type strings adjacent to those function names (DLL@0x310118-0x3108d0; mapping by adjacency = INFERENCE): `set_objective_completed`, `cross_quest_set_objective_completed`, `set_objective_displayed`, `fail_all_objectives`, `cross_quest_start`, `start_quest_stage_objective` (`verify_wait_ms`), `actor_dialogue_start_quest_stage_objective` (`actor_plugin`, `actor_form_id`, `npc_name`, `story_event_wait_ms`; sends a Story Manager `ADIA` ActorDialogue event, DLL@0x30fbe0), `change_location_start_quest_stage_objective` (`CLOC`), `console_command`, `console_command_sequence`, `stop_quest`, `start_scene`, `set_actor_value`, `set_ghost`, `evaluate_package`, `remove_item`, `add_item`, `enable_ref`, `set_relationship_rank`; failure strings `quest did not reach expected stage`, `unsupported action type`.
5. Prompt side: `chimQuestEngineBuildPromptContext($npcName, $locationName = '')` `:3687` appended to the character block (`HS/main.php:2569-2575`); per-turn action suppression `chimQuestEngineApplyActionSuppressionsForTurn()` (`main.php:2467`) and post-filter drop (`HS/functions/functions.php:2846-2852`).

Adding definitions:
- UNDOCUMENTED data-mod path: DLL scans `Data/CHIM` for suffix `_tradquest.csv` (DLL@0x2de1c8) and uploads as `traditional_quest_import` (DLL@0x2dcb68). Server importer `traditionalQuestImportFromCsvData($csvData, $filename = '')` `HS/processor/import_files.php:852`; header must contain `skeleton_json` (aliases `definition_json`, `raw_json`) or `beats_json`; optional columns `quest_key, quest_editor_id, title, quest_plugin, quest_form_id, description, npc_facts_json, natural_start_json, active`; upsert via `chimQuestEngineUpsertDefinition(array $definition, $sourcePath = '')` `chim_quest_engine.php:884`.
- UI: `HS/ui/addons/snqe/traditional_quests.php` (AI Quest Manager -> Traditional Quests).

INFERENCE: the engine never runs a dialogue INFO's result fragment; it sets stages/objectives and hand-listed `downstream` side effects. Quests whose progression lives in INFO fragments other than SetStage need manual downstream actions. This is the gap the glue's "real topic effects" feature would fill.

### 2.2 Vanilla ("traditional") dialogue handling - VERIFIED from Papyrus + DLL strings
- DLL sinks `RE::TESTopicInfoEvent` (DLL@0x313df0) and logs vanilla lines to the server as `chat|...` events; chatbox tags `traditional_player_speech` @0x314018 / `traditional_npc_speech` @0x3141c8; menu sink string `TopicClicked` @0x30ee58.
- Player TTS for Traditional Dialogue: conf `_player_tts_traditional_dialogue` (DLL@0x2ef7e0); Papyrus `Event OnPlayerMenuTopicSelected(String eventName, String strArg, Float numArg, Form sender)` `AIAgentPapyrusFunctions.psc:1170` calls `AIAgentFunctions.startPlayerMenuDialogueTTS(strArg)` and releases the menu with `UI.InvokeString("Dialogue Menu", "_root.DialogueMenu_mc.startTopicClickedTimer", "off")`; mod events `PlayMenuTopic`, `AIAgent_PlayerMenuTTSFinished` (now native; Papyrus unregisters them at `:1099-1100`). Server event types `player_menu_tts_prefetch`, `player_menu_tts_play` (`HS/processor/comm.php:1162`).
- The installed mod ships `F:\Modlists\LoreRim\mods\CHIM\Interface\dialoguemenu.swf` (the optional TraditionalDialoguePlayerTTS override) - a potential UI conflict with any LoreRim dialogue-menu mod (INFERENCE; MO2 priority not checked here).
- "NPC Scene Safety" MCM option exists to stop AI NPCs auto-responding during vanilla scenes (doc 1.4).

### 2.3 "Never press E" goal
Official wording found: index page - start/advance supported vanilla quests through natural dialogue "instead of navigating quest menus"; Nexus snippet "Menuless Questing (BETA)". No statement found about replacing ALL vanilla dialogue (services, generic topics, mod quests). NOT FOUND: any official generic INFO/topic execution mechanism.

### 2.4 Prisma UI chatbox - VERIFIED
Views in `F:\Modlists\LoreRim\mods\CHIM\PrismaUI\views\CHIM\`: `chatbox, confirmation, quest_manager, settings_menu, master_menu, history, overlay, diaries, browser, aiview, debugger, status_hud, npc_manager, background_life, config_manager`. Papyrus natives (`AIAgentFunctions.psc:124-131`): `toggleChatboxPanel, showChatboxPanel, hideChatboxPanel, focusChatboxPanel, unfocusChatboxPanel, isChatboxPanelVisible, isChatboxPanelFocused, isAnyPrismaHotkeyPanelFocused`; `toggleSettingsMenu`, `toggleMasterMenu` (`:133-137`). MCM bridge mod event `CHIM_PrismaMCMRequest` -> `OnPrismaMCMRequest` (`AIAgentMCMConfigScript.psc:799`). DLL: `PrismaUIBridge::SetChatboxGameplayInputSuppressed`, chatbox input sink "installed at highest priority" (DLL@0x3189f0) - relevant if the glue needs its own key handling while the chatbox is focused.

### 2.5 Action system changes / "new action mode"
- `int function setNewActionMode(int mode) Global Native` (`AIAgentFunctions.psc:26`) is simply the "Enable AI Actions" toggle: MCM `keyName == "enable_ai_actions"` -> `controlScript.setNewActionMode(enabled as Int)` (`AIAgentMCMConfigScript.psc:1075-1077`, also `:789-791`, `:2373-2375`). It is not a new dispatch protocol.
- Catalog tables: `public.core_action` (53 built-ins here: dispatch counts plugin_command 41, rolecommand 6, script_proxy 4, server_action 2) and `public.core_action_custom` (written by `herikaActionCatalogUpsertCustomRow($row)` `HS/lib/core/action_catalog.php:3379`). No `ExtCmd*`/`WebCmd*` rows exist on this install; `plugins` schema exists but is empty.
- Confirmation channel: `herikaActionCatalogGetConfirmationCommandChannel($row)` `action_catalog.php:415` returns `'command' | 'confirmcommand' | 'approvedcommand'` (DLL strings `confirmcommand` @0x2e56a8, `approvedcommand` @0x2e5698, `__CHIM_APPROVED__` @0x2e1a20, `processActionConfirmationQueue` @0x2e5740). Default policy `herikaActionCatalogGetDefaultConfirmationPolicy($codeName)` `:364`: 'ask' for `arrestplayer, acceptsex, kiss, makelove, removeclothes, sexaction, takehelditem, takegoldfromplayer`. Override via `metadata.custom_config.confirmation_required` or `metadata.confirmation.default_policy` (`:421-448`). Confirmation only applies to rows with `game_function` true (`:381-400`).
- Availability gates: `herikaActionCatalogRequirementsMatch($requirements, $context)` `:1834` keys: `requires_rolemaster, requires_training_service, hide_in_rechat, show_only_in_rechat, request_types_any, request_types_none, npc_names_any, npc_name_in_config_list, npc_name_in_action_config_list, npc_factions_any, npc_factions_all, activity`; plus `cooldown_seconds`.
- Follow-up after result: `HS/processor/funcret.php:101-157`: payload split on `@` = `command@<codeName>@<param>@<result>`; config from `herikaActionCatalogGetResolvedFollowupConfig($functionCodeName)`; if `followup.enabled` false or `followup.prompt` empty -> `terminate()` (no LLM call); `use_functions_again` bounded by chain limit.
- Server->game out-of-band commands: `SkyrimCommandBuilder::send($cmd, $localts = null)` `HS/lib/scriptproxy_papyrus.php:872` inserts into `responselog` (`actor 'rolemaster'`, `action 'rolecommand|ScriptProxy@'.$json`, `sent 0`); executed game-side by `AIAgentScriptProxy.ExecuteCommand(int cmdID, string jsonString)` (`AIAgentScriptProxy.psc:192`; id ranges Actor 1-99, ObjectReference 100-199, FormList 200-299, EffectShader 300-399, ActorUtil 400, Faction 500-524; NO Quest/Topic commands).

### 2.6 NSFW / intimacy settings in core
- No NSFW/explicitness setting in `conf_schema.json` or core prompts (grep for nsfw/explicit/censor in `HS/prompts`, `conf_schema.json`, `settings_presets.php`: NOT FOUND).
- `AIAgentIntimacyBubbleEffect.psc` is only a legacy shim that selects Close conversation mode: `AIAgentFunctions.logMessage("chim_mode@CLOSE","setconf")` (`:11`).
- Core is aware of adult-plugin names: default-ask list above; event type `ext_nsfw_physics_raw` hidden from the event log (`HS/lib/eventlog_helper.php:30`); raw VR telemetry type `physics_raw` is ignored unless an extension renames it in `preprocessing.php` (`HS/main.php:195-199`, `:231`).
- Official in-server repository features SHARMAT: `HS/ui/data/plugin_repository.json:99-117` (`"name":"aiagent_nsfw"`, `"featured": true`, description mentions consent-driven scenes with OStim + SexLab).

### 2.7 Breaking changes vs. MinAI-era plugin API (VERIFIED locally unless noted)
| Old (Herika / early CHIM) | 3.3.2 status | Evidence |
|---|---|---|
| Mod event `SPG_CommandReceived`, script `SPGPapFunctions` | Gone. Use `CHIM_CommandReceived` / `AIAgentFunctions`. Bundled example still shows old names and is entirely inside a `/*** ... ***/` comment | `HS/ext/herika_heal/functions.php:3-117`; upstream doc 1.7 |
| Callback `Event X(String command, String parameter)` (2 args) | Now 3 strings: npcname, command, parm | `AIAgentAIMind.psc:1395-1405` |
| `$GLOBALS["FUNCRET"][code]` callable | No consumer anywhere in HS (only defined in the dead example) | grep `FUNCRET` over HS `*.php` |
| `$GLOBALS["PROMPTS"]["afterfunc"]["cue"][code]` | Still read | `HS/processor/request.php:14-18` |
| `$GLOBALS["FUNCSERV"][code]` callable | Still called on funcret | `HS/processor/request.php:34-35` |
| `ext/<plugin>/functions.php` with `FUNCTIONS/F_NAMES/F_TRANSLATIONS/ENABLED_FUNCTIONS` | Still loaded (`requireFunctionFilesRecursively`), merged with catalog rows; but UNDOCUMENTED now (docs say data-driven only) | `HS/functions/functions.php:2688-2704`, `:2752-2767`; `action_catalog.php:2876-2962` |
| Follow-up LLM reply after any funcret | Now opt-in per action via catalog `metadata.followup` | `HS/processor/funcret.php:131-157` |
| Name-only actor routing | Discouraged; `sendMessageToActor(String,String,Actor)` exists | `AIAgentFunctions.psc:5`; doc 1.7 |
| Tarball installer only | Plus schema-v4 `.dwpkg` package manager + game-side auto-sync | `HS/lib/plugin_package_manager.php:13` (`SCHEMA_VERSION = 4`), DLL@0x32ce08 `Data/CHIM/server-plugins` |
MinAI's own documented API could not be re-read (repo 404), so the "relied on" column is reconstructed from the bundled example + SHARMAT's still-working legacy usage (INFERENCE for MinAI specifically).

---

## 3. Cross-check: documented vs. local source

### 3.1 Documented AND present locally (VERIFIED)
| Item | Local evidence |
|---|---|
| `requireFilesRecursively($dir,$name)` | `HS/lib/data_functions.php:7729` (does `require_once` INSIDE a function with only `global $gameRequest;` -> hook files run in function scope; use `$GLOBALS[...]`) |
| `globals.php` | `HS/main.php:54` (right after `lib/chim_quest_engine.php` is loaded) |
| `preprocessing.php` | `HS/main.php:193` |
| `prerequest.php` | `HS/main.php:1117` (immediately before `processor/comm.php` `:1124`) |
| `context_pre.php` | `HS/main.php:2540` (then `PROMPT_NEARBY_SECTIONS` re-synced `:2543-2545`) |
| `context.php` | `HS/main.php:2648` |
| `context_building.php` | `HS/lib/data_functions.php:3217` (`$GLOBALS["CONTEXT_BUILDING_DATA"]`) |
| `prompts.php` | `HS/prompts/prompts.php:285` (also `HS/lang/de/prompts.php:262`) |
| `dialogue_prompt.php` | `HS/prompts/dialogue_prompt.php:370` |
| `json_response_custom.php` | `HS/functions/json_response.php:81` (one-time, flag `CHIM_JSON_RESPONSE_EXT_LOADED`) |
| `prepostrequest.php`, `postrequest.php` | `HS/main.php:2944`, `:2946` |
| `chimRegisterPromptInjection(string $slot, string $id, $content, int $priority = 100): bool` | `HS/lib/prompt_injections.php:10`; content may be string, array of lines, or callable `($slot, $context)` (`:34-57`); rendered by `chimRenderPromptInjections("character_bottom"/"prompt_bottom", $promptInjectionContext)` `HS/main.php:2553-2566`; context keys `game_request, herika_name, narrator_name, player_name` (`:2547-2552`) |
| `chimRegisterActorProfileEnricher(string $id, callable $callback, int $priority = 100): bool` | `HS/lib/prompt_injections.php:87`; invoked as `($actorName, $actorType, $context)` with `$actorType` "player" or "npc" (`HS/lib/data_functions.php:1085`, `:1227`) |
| `_actions.csv` columns | `HS/processor/import_files.php:1147-1258` (same 13 names); seed `HS/data/core_action_seed.sql:3-17` |
| Script proxy runner | `herikaActionCatalogRunScriptProxyProgram($program, $context)` `action_catalog.php:3786`; `herikaActionCatalogExecuteScriptProxyAction($action)` `:3809` |
| Tarball installer + migrations | `HS/ext/generic_installer.php:6-84`; `HS/ui/server_plugin_installer.php:241-266` (`plugins.plugin_migrations(plugin_name, migration_name, executed_at)`) |
| Schema-v4 package manager | `HS/lib/plugin_package_manager.php:13,57,138,266,328,341`; `HS/ui/api/plugin_packages.php`; `HS/unittests/tests/PluginPackageManagerTest.php` |
| Plugin repository JSON | `HS/ui/data/plugin_repository.json` |
| `AIAgentFunctions.sendMessageToActor` | `AIAgentFunctions.psc:5` |
| ExtCmd static dispatch + fallback | DLL strings (1.8) + `AIAgentAIMind.psc:1395-1405` |
| All 120 natives declared in `AIAgentFunctions.psc` | every name found as a string in the 3.3.2 DLL (scripted check, 0 missing) |

Mod events emitted by CHIM Papyrus (exact, `AIAgentAIMind.psc`):
```papyrus
function SendInternalEvent(String npcname,String command,String parm) global      ; :1382  ModEvent "CHIM_CommandReceivedInternal"
function SendExternalEvent(String npcname,String command,String parm) global      ; :1395  ModEvent "CHIM_CommandReceived"  (PushString x3)
function SendExternalEventNPC(String npcname,String actionName) global            ; :1407  ModEvent "CHIM_NPC"              (PushString x2)
function SendExternalEventChat(String npcname,String text) global                 ; :1419  ModEvent "CHIM_TextReceived"     (PushString x2)
```
Reference handler signature: `Event CommandManager(String npcname,String  command, String parameter)` (`AIAgentPapyrusFunctions.psc:1286`, registered `:1096`). DLL references `SendExternalEventNPC` @0x2ee988 and `SendExternalEventChat` @0x3044c0.

Core messaging natives (exact, `AIAgentFunctions.psc`):
```papyrus
int function sendMessage(String a_msg,String a_type) Global Native                          ; :4
int function sendMessageToActor(String a_msg,String a_type,Actor targetActor) Global Native ; :5
int function commandEnded(String command)  Global Native                                    ; :7
int function commandEndedForActor(String command,string npc)  Global Native                 ; :8
int function logMessage(String a_msg,String type) Global Native                             ; :27
int function logMessageForActor(String a_msg,String type,String npc) Global Native          ; :28
int function requestMessage(String a_msg,String type) Global Native                         ; :29
int function requestMessageForActor(String a_msg,String type,String npc) Global Native      ; :30
int function setAnimationBusy(int busy,String npc) Global Native                            ; :31
int function setLocked(int locked,String npc) Global Native                                 ; :32
int function isActorTalking(String npc) Global Native                                       ; :33
int function stopAllDialogue() Global Native                                                ; :13
int function PostGameData(string jsonData) global native                                    ; :83
int Function SayTo(Actor source,Actor dest,Form topicToSay) global Native                   ; :88
Actor function getAgentByName(String npcName) Global Native                                 ; :62
```

### 3.2 Documented but MISSING locally (post-3.3.2 `unstable`)
- `NpcMaster::getPluginData`, `setPluginData`, `deletePluginData`, column `core_npc_master.plugin_extended_data`: grep over HS `*.php`/`*.sql` = no matches; `information_schema` shows no `%plugin%` column on `core_npc_master`. Do not use until the server is updated.
- `HS/docs/` folder, expanded `AGENTS.md` (local `HS/AGENTS.md` has only the 4 Prisma-parity bullets), `lib/playthrough_policy.php` (absent).
- Game mod: no `docs/CHIM` folder in `F:\Modlists\LoreRim\mods\CHIM`.
- `plugins.plugin_migrations` table does not exist yet on this install (created lazily by the installer).

### 3.3 Present locally but UNDOCUMENTED on the official pages
- `ext/*/functions.php` legacy action hook (2.7).
- `$GLOBALS["HOOKS"]` registries: `JSON_TEMPLATE` (`HS/functions/json_response.php:45-46`), `BIOGRAPHY_BUILDER` (`HS/lib/data_functions.php:7620`), `XTTS_TEXTMODIFIER` (tts connectors).
- `$GLOBALS["action_post_process_fnct"]` / `$GLOBALS["action_post_process_fnct_ex"][]` action post-filters (`HS/lib/data_functions.php:6031-6037`; core example `HS/functions/functions.php:2830`), `$GLOBALS["AVOID_LLM_CALL"]` (`HS/main.php:2789`), `$GLOBALS["PATCH_ACTION_ALL_ACTORS"]` (`data_functions.php:6464`), `$GLOBALS["FORCE_MAX_TOKENS"]` (`HS/connector/openrouterjson.php:622`).
- `$GLOBALS["external_fast_commands"]` (array of extra event-type strings merged into `$fast_commands`, i.e. types that skip the MAIN semaphore; `HS/main.php:227-237`; must be set before that point, so from `globals.php` or `preprocessing.php`). Core also special-cases raw plugin types `ext_held_item_raw`, `ext_vr_item_raw` (`HS/main.php:937`).
- `_tradquest.csv` / `traditional_quest_import` data-mod type (2.1) - not in the Modders Guide list.
- The whole `gamedata.php` JSON endpoint and its `quest_*` types; `AIAgentFunctions.PostGameData(string jsonData)`.
- Mod events `CHIM_NPC`, `CHIM_TextReceived`, `CHIM_CommandReceivedInternal`, `CHIM_PrismaMCMRequest`.
- `AIAgentQuestProgressionBridge` and `AIAgentScriptProxy` command-id tables.
- Action `metadata.requirements` key list and confirmation policy defaults (2.5).
- `IntCmd` prefix (only in C++/Papyrus).

### 3.4 Doc/source mismatches worth knowing
- Official CHIM-NFF example reports results with `logMessageForActor(..., "funcret", npc)`, while the old bundled example used `requestMessage(..., "funcret")`. Which native makes the DLL play a follow-up reply was not verified (see Open questions).
- Web guide says `schema_version: 2`; GitHub doc says schema 4 for `.dwpkg`. Both are right: 2 = tarball/`generic_installer.php` flow, 4 = `plugin_package_manager.php` flow.

---

## 4. Official guidance on adult content, prompt content filters, LLM settings

- Dedicated policy/guidance page for adult plugins: NOT FOUND (modders guide, plugins, FAQ, LLM, roleplay pages checked).
- De facto stance: SHARMAT (`aiagent_nsfw`) is listed and `featured` in the server's own repository catalogue (`HS/ui/data/plugin_repository.json:99-117`); core ships 'ask'-by-default confirmation for adult action code names (`action_catalog.php:367-376`).
- Only explicit warning in the product: connector editor note - "OpenAI, Anthropic and Google have started to enforce stricter terms of service" regarding NSFW, linking `https://openrouter.ai/terms#_6_-prohibited-conduct_` (`HS/ui/core/llm_connectors.php:431`, `:1762`).
- Prompt-level filters: none found in core prompts (`HS/prompts/*.php`), config schema or presets. The only "profanity" option is for an STT provider (`conf_schema.json:479`).
- Connector behaviour (`HS/connector/openrouterjson.php`): request body `:626-643` (`model, messages, stream, max_tokens, temperature, top_k, top_p, min_p, top_a, presence_penalty, frequency_penalty, repetition_penalty, stop ['USER'], transforms []`); arbitrary extra body params from connector metadata via `chimGetEnabledConnectorExtraParameters()` (`:645-647`, defined `HS/lib/core/llm_connector.class.php:36`, needs `extra_parameters_enabled`); JSON enforcement `:649-655` (`response_format` json_schema or json_object); Google-only `safety_settings` BLOCK_NONE when metadata `block_none` is set and model matches `/google|gemini/i` (`:657-668`); provider routing `provider.order/sort/ignore/quantizations/max_price` (`:721-743`); reasoning handling incl. special cases for `x-ai/grok-4.1-fast`, `grok-3-mini`, `x-ai/grok-4` (`:670-695`). No moderation/refusal detection or retry logic was found in this connector (grep for moderation/refus/content_filter/finish_reason: no matches).
- This install's connectors (SELECT, keys excluded): ids 1-7 and 10, all `driver=openrouterjson`, `enforce_json=1`, `json_schema=1`; models `deepseek/deepseek-v4-flash`, `google/gemini-2.5-flash-lite`, `z-ai/glm-5.2`, `deepseek/deepseek-v4-pro`, `mistralai/mistral-small-3.2-24b-instruct`, `mistralai/ministral-8b-2512`, `google/gemma-3n-e4b-it`, and id 10 "Grok 4.3" `x-ai/grok-4.3` with metadata `{"extra_parameters":{"reasoning":{"effort":"none"}},"disable_streaming":false,"remove_action_prompt":false,"extra_parameters_enabled":true}`.

---

## Implications for the glue (concrete recommendations)

1. Do not build menuless questing from zero. Decide between (a) feeding CHIM's own quest engine (generate `*_tradquest.csv` rows or call `chimQuestEngineUpsertDefinition()` from auto-extracted plugin data, then rely on CHIM's outbox -> `AIAgentQuestProgressionBridge`) and (b) a parallel glue pipeline for real INFO execution. (a) inherits intent matching, prompt facts, rollback and Prisma UI for free but is stage-level and only active when `CHIM_AI_QUEST_PROGRESSION` is on; (b) is needed for INFO result scripts. A hybrid is plausible: glue executes the real INFO game-side, CHIM's `quest_stage` triggers observe the resulting stage ("Skyrim stays the authority").
2. If both run, avoid double advancement: CHIM fires `set_stage` from the same player utterance. Either keep CHIM's feature off for quests the glue owns (per-definition `active=false`) or gate the glue on `chimQuestEngineFeatureEnabled()`.
3. Game-side actions: name them `ExtCmd<GlueScript>_<Action>` with exactly one script-name segment before the first `_` (C++ uses `find('_', 6)`), and implement `bool Function DispatchExternalCommand(string asNpcName, string asCommand, string asParameter) Global` in `<GlueScript>.psc`. Keep a `CHIM_CommandReceived` listener only as fallback. No SKSE DLL is needed for the action round trip.
4. Report results as `command@<code>@<param>@<result>` with type `funcret` for the acting NPC (official pattern: `AIAgentFunctions.logMessageForActor`). Set `metadata.followup = {enabled:true, prompt:"...", arg_name:"target", use_functions_again:false}` if an in-character reaction is wanted; otherwise the server terminates silently.
5. Register actions the documented way: ship `Data/CHIM/<glue>_actions.csv` (bump `import_version` on change) and/or have the ext plugin call `herikaActionCatalogUpsertCustomRow()`. Avoid the legacy `functions.php` globals for new code (undocumented, though SHARMAT proves it still works).
6. Hard gate for "start intimacy": stack three layers that already exist - (i) server-side availability via `metadata.requirements` (`npc_names_any` / `npc_factions_any` / `request_types_any`) or by only upserting/enabling the action when the glue's adult+consent check passes in `prerequest.php`/`context_pre.php`; (ii) `metadata.confirmation.default_policy = "ask"` so Prisma shows Accept/Decline (requires `game_function` true); (iii) Papyrus re-validation inside `DispatchExternalCommand` (adult, non-child race, consent flag) returning an error result.
7. Scene awareness text: use `chimRegisterPromptInjection('prompt_bottom', 'glue.ostim_scene', <callable>, 100)` and `chimRegisterActorProfileEnricher('glue.ostim_actor', fn($actorName,$actorType,$context))` from `globals.php`; both exist in 3.3.2. Remember hook files execute in function scope.
8. Game -> server state (INFERENCE from SHARMAT's `ext_nsfw_*` types and core's `physics_raw` comment; not an officially documented contract): send a custom event type through `AIAgentFunctions.logMessage(json, "ext_glue_*")`, register the type in `$GLOBALS["external_fast_commands"]` from `globals.php`, consume it in `preprocessing.php`/`prerequest.php` and end the request there so it never reaches the LLM path; or use a plugin-owned HTTP endpoint under `ext/<glue>/api/` (CHIM-Custom pattern). Do not depend on `NpcMaster::*PluginData` (absent in 3.3.2); use own tables in schema `plugins`.
9. Server -> game without an LLM turn: `SkyrimCommandBuilder::send()` (ScriptProxy ids) or emit an action line; for quest-type effects CHIM's outbox (`chimQuestEngineQueueAction`) already supports `console_command`, `start_scene`, `set_stage`, but only while the quest feature is enabled.
10. Packaging: ship the server half inside the mod at `Data/CHIM/server-plugins/<name>/<version>.dwpkg` (schema 4, `checksums.sha256`, `server/` payload) so CHIM 3.3.2 auto-installs it; keep a `manifest.json` with `schema_version` 2 fields too if a GitHub tarball channel is wanted.
11. Explicitness setting: implement purely as prompt text (injection slots) plus a per-connector recommendation. Nothing in CHIM filters output; with OpenRouter the provider/model decides. For Grok via OpenRouter no special body flag exists in the connector; `extra_parameters` is the supported way to pass any provider-specific field. Warn users that Google/OpenAI/Anthropic models may refuse or violate ToS (mirrors CHIM's own UI note); only Gemini gets `block_none`.
12. Prior art/conflict: SHARMAT already occupies `ext/aiagent_nsfw`, `ExtCmd*` names, `AcceptSex`/`RefuseSex`, `ext_nsfw_*` event types. Use a distinct namespace (code names, event types, table prefix) and detect its presence.
13. Dialogue UI: CHIM's optional `Interface/dialoguemenu.swf` is installed here; any glue approach that drives or suppresses the Dialogue Menu must coexist with it and with "Player TTS for Traditional Dialogue" (`startTopicClickedTimer` hold/release).

## Open questions

1. Does a `funcret` sent with `logMessageForActor` (official CHIM-NFF pattern) cause the DLL to fetch/play the follow-up reply, or is `requestMessageForActor` needed when `followup.enabled` is true? (Check `Plugin/Papyrus.cpp` / test in game.)
2. Exact 3.3.0-3.3.2 changelog text (Nexus 403). Anything about quest engine limitations or planned generic INFO execution is unknown.
3. Does `DispatchStaticCall` return false when `<BridgeScript>` has no `DispatchExternalCommand` (so the mod-event fallback reliably fires), or only when the VM is unavailable? Matters only if relying on the fallback.
4. How well does CHIM's quest engine behave on LoreRim (quests edited by LoreRim plugins; form IDs are vanilla masters so probably fine) - untested; feature is currently off.
5. Whether `_tradquest.csv` import overwrites user-edited definitions on each game start (importer upserts unconditionally; no `import_version` guard seen for quests, unlike actions).
6. Old hostwiki plugin page content (SSL failure) and MinAI's ModdersGuide (404) could not be read; the MinAI-era API table is reconstructed.
7. OpenRouter-side moderation for `x-ai/grok-4.3` (provider policy) is outside CHIM; not researched here beyond CHIM's connector code.
8. Whether upstream `unstable` (post-3.3.2) changes the ExtCmd branch relative to the installed DLL: string order matches, but the 3.3.2 binary was not disassembled.
