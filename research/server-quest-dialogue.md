# CHIM 3.3.x: what already exists for quests and menuless dialogue (server side + game bridge)

Scope: HerikaServer in WSL distro `DwemerAI4Skyrim3` (`/var/www/html/HerikaServer`, cited below as `HS/...`), CHIM game mod at `F:\Modlists\LoreRim\mods\CHIM` (cited as `CHIM/...`), read-only. DLL facts come from printable strings extracted from `CHIM/SKSE/Plugins/AIAgent.dll` (no disassembly), so they prove that a name exists in the binary, not how it is used. Every VERIFIED item has a file:line. INFERENCE items are labelled.

## Executive summary (10 lines)

1. CHIM 3.3.2 ships a complete, **beta, default-OFF** feature called "CHIM AI Quest Progression" (`CHIM_AI_QUEST_PROGRESSION=false`): `HS/lib/chim_quest_engine.php` (3774 lines) + 5 Postgres tables + DLL poller + `AIAgentQuestProgressionBridge.psc`.
2. It is for **vanilla quest progression through free AI conversation**, not AI-generated quests (that is a separate system, SNQE). It is **data-driven but hand/LLM-authored per quest**: a "skeleton" JSON per quest with "beats"; 301 definitions are bundled (218 Skyrim.esm, 26 Dawnguard.esm, 57 Dragonborn.esm; 300 quests + 1 radiant template).
3. Mechanism: after each player-driven AI turn the server matches the player's text against the current legal beats (keyword substring first, then a strict LLM intent classifier with confidence threshold), then queues actions in `skyrim_quest_action_outbox`; the DLL polls `gamedata.php` (`quest_action_poll`), executes them through the Papyrus bridge, and acks (`quest_action_ack`).
4. **It never touches dialogue topics.** It calls `Quest.SetStage()` / objectives / items directly. TopicInfo result scripts, scene starts, alias fills done by INFO fragments are NOT executed; skeleton authors replicate side effects by hand (`remove_item`, `add_item`, `enable_ref`, `set_relationship_rank`, `cross_quest_start`). This is the exact gap the glue's "menuless questing" fills.
5. "Server-approved" = an action exists only because the server's beat engine queued it after gates (feature flag, player-only, focus NPC, prerequisites, conditions, required item, intent confidence). The DLL executes only outbox rows and reports `verified` results.
6. Vanilla NPC lines ARE captured into context: the DLL logs them as eventlog type `chat` with data beginning `(Context location: ...)`; `main.php:1356` documents "chat entries starting by (Context% are standard skyrim dialogue".
7. `startPlayerMenuDialogueTTS` is only player-voice TTS for a clicked vanilla menu topic (CHIM's `dialoguemenu.swf` fires ModEvent `PlayMenuTopic` and holds the topic until TTS ends). It does not select topics.
8. No mechanism maps free conversation onto vanilla dialogue TOPIC selection anywhere (server grep and Papyrus grep: NOT FOUND). Closest things: the beat engine (stage-setting) and the DLL's Story Manager "ActorDialogue"/"ChangeLocation" story-event trigger for starting radiant quests.
9. Quest awareness in prompts is thin: `<active_quests>` (max 8 quests, name + current objective text) goes only to party followers and The Narrator when `CURRENT_TASK` is on; ordinary NPCs only get `<quest_context>` facts from the skeletons (when the beta feature is on) plus `quest` eventlog rows they witnessed.
10. Overlapping director systems exist: Rolemaster "Director Mode" (LLM issues `rolecommand|Instruction@...` + `<scene_notes>`), narrator bored/random narration/quest comments, SNQE AI quests. The glue must not enable/duplicate the beat engine for the same quest, must not replace `dialoguemenu.swf`, and should reuse ext hooks, `chimRegisterPromptInjection`, `responselog`, `ext_*` event types and `PostGameData`.

---

## 1. Server-side counterpart of AIAgentQuestProgressionBridge: "CHIM AI Quest Progression"

### 1.1 Grep result map (VERIFIED)

`grep -ri SetQuestStage|QuestProgression|quest_progress|questaction|aiquest|StartQuestStageObjective|ExecuteConsoleCommand` over the server: 46 hits in 16 files. None of the Papyrus function names (`SetQuestStage`, `StartQuestStageObjective`, `ExecuteConsoleCommand`) appear on the server; the server speaks in snake_case **action types** and the DLL maps them to the bridge. Relevant files:

| File | Role |
|---|---|
| `HS/lib/chim_quest_engine.php` | the whole engine (all `chimQuestEngine*` functions) |
| `HS/data/chim_quest_engine.sql` | DDL for the 5 `skyrim_quest_*` tables |
| `HS/data/skyrim_quest_definitions.json` | 1.32 MB bundled skeletons (301 entries) |
| `HS/gamedata.php:27,125-163,517` | JSON endpoint used by the DLL (events in, actions out, acks) |
| `HS/main.php:53,2466-2471,2569-2575` | per-turn action suppression + prompt context injection |
| `HS/lib/chat_helper_functions.php:1292,1643,1945-1951` | feeds each finished NPC reply into the engine (`returnLines`) |
| `HS/functions/functions.php:2846-2852` | drops suppressed LLM actions |
| `HS/processor/import_files.php:51,852-1014` | `traditional_quest_import` CSV importer |
| `HS/processor/comm.php:161-163,312-314` | runtime reset on `init` / `wipe` |
| `HS/ui/addons/snqe/traditional_quests.php` | web UI tab "Traditional Quests" |
| `HS/conf/conf.sample.php:61-62`, `HS/conf/conf_schema.json:61-62`, `HS/lib/settings.php:254-255,378-379`, `HS/ui/global_settings.php:115-116` | settings |
| `HS/debug/tool_quest_giver.php`, `HS/ui/addons/aiscript/*` | LEGACY "aiquest" scripted AI quests; tables `aiquest`/`aiquests_template` do NOT exist in this DB (SELECT on information_schema returned no rows) - dead code |

### 1.2 What it is for (VERIFIED)

```
$CHIM_AI_QUEST_PROGRESSION=false; //Enable CHIM AI quest progression. Allows you to progress regular Skyrim quests with AI dialogue. Most vanilla non radiant quests are supported. Open the AI Quest Manager in Immersion for more info.
$CHIM_PLAYER_ONLY_QUEST_ADVANCEMENT=true; //When enabled, only direct player dialogue can fire CHIM AI quest beats and queue quest stage actions. Disable to let NPC responses and game interaction events advance or start quest beats.
```
`HS/conf/conf.sample.php:61-62`. UI label: `'CHIM AI Quest Progression (Beta)'` (`HS/ui/global_settings.php:115`). Live values in this install (SELECT on `general_settings`): `CHIM_AI_QUEST_PROGRESSION=false`, `CHIM_PLAYER_ONLY_QUEST_ADVANCEMENT=true`.

Flag resolution order in `chimQuestEngineFeatureEnabled()` (`chim_quest_engine.php:111-170`): `general_settings` row via `chimGetGeneralSettingRow/Bool` -> `$GLOBALS['CHIM_AI_QUEST_PROGRESSION']` -> `conf_opts` id `chim_ai_quest_progression` -> false. When disabled: `chimQuestEngineHandleEvent` returns `{'ok':true,'disabled':true}` (3141-3148), `chimQuestEngineFetchPendingActions` returns `[]` (3286-3288), `chimQuestEngineBuildPromptContext` returns `''` (3689-3691). So **the outbox does not drain at all while the feature is off**.

### 1.3 Data model (VERIFIED, `HS/data/chim_quest_engine.sql:192-252`)

```
skyrim_quest_definitions (quest_key text PK, quest_editor_id text, title text, source_plugin text, source_form_id text, source_path text, skeleton jsonb, active boolean default true, created_at, updated_at)
skyrim_quest_instances   (quest_key text PK -> definitions ON DELETE CASCADE, quest_editor_id text, run_state text default 'inactive', current_stage integer, last_gamets bigint, state_json jsonb, ...)
skyrim_quest_beat_state  (quest_key -> instances, beat_id text, fired boolean, fired_order integer, fired_gamets bigint, evidence_json jsonb, PK(quest_key, beat_id))
skyrim_quest_events      (id bigserial PK, quest_key -> definitions ON DELETE SET NULL, event_type text, event_source text, npc_name text, location_name text, gamets bigint, payload_json jsonb, created_at)
skyrim_quest_action_outbox (id bigserial PK, quest_key text NOT NULL -> instances ON DELETE CASCADE, beat_id text, action_type text, action_gamets bigint, payload_json jsonb, status text default 'pending', result_json jsonb, created_at, updated_at, applied_at)
```
(Older names `chim_quest_*` are renamed by the same script, lines 1-190.) `run_state` values used: `inactive`, `running`, `completed`, `failed`. `state_json` default shape (`chimQuestEngineDefaultState`, 364-375):
```php
array('has_items'=>array(),'dead_actors'=>array(),'entered_locations'=>array(),'current_stage'=>null,'radiant_aliases'=>array(),'last_dialogue'=>array())
```
Live DB state: 301 definitions, 301 instances all `inactive`, outbox/events empty, all loaded from `.../data/skyrim_quest_definitions.json`.

Definitions are bootstrapped when the table is empty (`chimQuestEngineMaybeBootstrapBundledDefinitions`, 588-606) from `chimQuestEngineBundledDefinitionFiles()` (418-436): `HS/data/skyrim_quest_definitions.json` plus a developer POC glob `HS/CHIM QUEST TRIGGERS/02_GCH_SkyrimMod_POC/GCH - POC TEST/SKSE/Plugins/GCH_Skeletons/quests/*.json` (folder not present in this install).

#### Skeleton (definition) schema - is there a per-quest knowledge base of stages? YES, but hand-authored

Top-level keys observed in all 301 entries: `skeleton_type` (`quest` | `radiant_template`), `quest_form_id` ("0x000D93FA" - local form id hex string), `quest_plugin`, `quest_editor_id`, `title`, `description`, `natural_start` {`enabled`,`beats`[],`requires`[]}, `npc_facts`, `beats`, `quest_key`; optional `external_ids` (7 entries, e.g. `{"source":"local-esm:Skyrim.esm QUST","matched_by":"quest_editor_id"}` - INFERENCE: an offline generator matched editor IDs against the ESMs), `radiant_template` (1 entry).

Beat keys: `id`, `focus_npc` (or `focus_npcs`[]), `comment`, `intent_summary`, `action`, `downstream`[], `triggers`[], `trigger_mode` (`any`|`all`), `prerequisites`[] (beat ids), `allow_natural_start`, `start_conditions`[], `conditions`[], `required_item` {name,plugin,form_id}, `suppress_actions`[], `intent_min_confidence`.

Counts from the bundled file (script over the JSON): 1406 beats. Trigger types: `quest_stage` 1124, `dialogue` 988, `dialogue_intent` 43, `item_acquired` 1. Primary action types: `set_stage` 856, `gate` 516 (observational, no game effect), `set_stage_cascade` 33, `actor_dialogue_start_quest_stage_objective` 1. Downstream action types: `set_objective_displayed` 211, `set_objective_completed` 188, `stop_quest` 169, `set_stage` 11, `remove_item` 9, `add_item` 4, `set_relationship_rank` 2, `enable_ref` 1, `cross_quest_start` 1. `suppress_actions` values used: only `GiveGoldTo`, `TakeGoldFromPlayer`, `OpenInventory` (46 beats each). 236/301 quests have `natural_start.enabled=true`; 5 quests have no dialogue trigger at all. `npc_facts`: 656 NPC entries, 4239 fact strings, ~334k chars.

Exact payload shapes copied from the file:
```json
{"type":"set_stage","quest_form_id":"0x000D93FA","quest_plugin":"Skyrim.esm","stage":20}
{"type":"set_objective_displayed","quest_form_id":"0x000D93FA","quest_plugin":"Skyrim.esm","objective_index":20}
{"type":"set_objective_completed","quest_form_id":"0x000D93FA","quest_plugin":"Skyrim.esm","objective_index":40}
{"type":"stop_quest"}
{"type":"set_stage_cascade","quest_form_id":"0x0001CEF4","quest_plugin":"Skyrim.esm","stages":[15,20]}
{"type":"remove_item","target":"player","plugin":"Skyrim.esm","item_form_id":"0x0005BF2E","count":1,"silent":false}
{"type":"add_item","target":"player","plugin":"Skyrim.esm","item_form_id":"0x000AD8DE","count":1,"silent":false}
{"type":"enable_ref","plugin":"Skyrim.esm","comment":"Enable LucanClaw display","form_id":"0x000AC9B6"}
{"type":"set_relationship_rank","rank":1,"plugin":"Skyrim.esm","target":"player","actor_form_id":"0x0001347A"}
{"type":"cross_quest_start","quest_plugin":"Skyrim.esm","quest_form_id":"0x0003DD29"}
{"type":"actor_dialogue_start_quest_stage_objective","quest_plugin":"Skyrim.esm","quest_form_id":"0x0007105B","actor_name":"Amren","stage":10,"objective_index":10,"story_event_wait_ms":2500,"verify_wait_ms":3000}
{"type":"dialogue","keywords":["Finn's Lute","stolen lute","why are you sad","thieves","lute"],"min_matches":1}
{"type":"quest_stage","quest_editor_id":"BardsCollegeLute","min_stage":40}
{"type":"dialogue_intent","intent":"...","requires_explicit_commitment":true,"min_confidence":0.82,"examples_yes":[...],"examples_no":[...]}
{"type":"item_acquired","plugin":"{QuestItem.plugin}","form_id":"{QuestItem.form_id}"}
{"type":"quest_not_started","quest_editor_id":"Favor204"}   {"type":"stage_done","stage":10}   {"type":"stage_not_done","stage":20}
```
`npc_facts` shape: `{ "<NPC display name>": { "base_facts": [..], "beat_facts": { "<BEAT_ID>": [..] } } }`.

Condition types the engine understands (`chimQuestEngineConditionSatisfied`, 1483-1553): `actor_alive`, `actor_dead`, `stage_done`, `stage_not_done`, `quest_stage`, `quest_started`, `quest_not_started`, `quest_completed`, `quest_beat_fired`, `quest_beat_not_fired`; unknown types evaluate true.

Radiant template (one: `Favor204`): `radiant_template` {`enabled`,`required_aliases`,`instance_key_aliases`,`alias_synonyms`}; placeholders `{QuestGiver}`, `{QuestItem.plugin}`, `{QuestItem.form_id}` are substituted from the `aliases` array the DLL sends with a `quest_stage` event (`chimQuestEngineInstantiateRadiantDefinition`, 925-978; `chimQuestEngineReplaceRadiantPlaceholders`, 817-843). A concrete definition+instance is upserted per alias combination.

Coverage (VERIFIED from the file): main quest MQ101-MQ305 + MQPaarthurnax (20 entries), Companions C00-C06/CR12/CR13, Civil War CW*, Daedric DA01-DA16, Dark Brotherhood DB01-DB11 + side contracts, Dawnguard DLC1*, Dragonborn DLC2*, dungeon quests dun*, Favor*, Freeform*, MS*, MG*, TG* etc. All are Bethesda masters only. **No LoreRim mod quest is covered**; the only radiant coverage is Favor204.

Second, older per-quest knowledge store (VERIFIED): `oghma_dynamic (id, id_quest, stage, topic, topic_desc, knowledge_class, topic_desc_basic, knowledge_class_basic, tags, category)`; `syncQuestWithOghma($questId,$stage)` (`HS/processor/dynamicoghma.php:3`) copies matching rows into the `oghma` lore table whenever the DLL sends `_uquest` (`HS/processor/comm.php:410-431`). Table is empty in this install; fed by `*_dynamicoghma.csv` imports.

### 1.4 End-to-end flow (VERIFIED unless marked)

**A. Game -> server events.** DLL POSTs JSON to `gamedata.php` (string `gamedata.php` in DLL). `gamedata.php:49-62` actorless types: `quest_event`, `quest_action_poll`, `quest_action_ack`, `quest_import_bundled`, `quest_reset_runtime`, `quest_status`. Handler (`gamedata.php:125-163`):
```php
case 'quest_event':       chimQuestEngineHandleEvent($data['event_type'] ?? '', (isset($data['payload']) && is_array($data['payload'])) ? $data['payload'] : $data)
case 'quest_action_poll': array('ok'=>true,'actions'=>chimQuestEngineFetchPendingActions($data['limit'] ?? 25))
case 'quest_action_ack':  chimQuestEngineAcknowledgeAction($data['action_id'] ?? 0, $data['status'] ?? 'applied', $data['result'] ?? array())
case 'quest_import_bundled' / 'quest_reset_runtime' / 'quest_status'
```
Event types the engine consumes (`chimQuestEngineApplyEventToState`, 2743-2814; `chimQuestEngineTriggerMatchesEvent`, 2423-2485): `quest_stage`, `location_entered`, `actor_dead`, `item_acquired`, `item_removed`, `player_inventory_sync`, `dialogue_turn` (+ internal `dialogue_turn_intent`). DLL strings present: `quest_event`, `quest_stage`, `location_entered`, `actor_dead`, `item_acquired`, `player_inventory_sync`, `TESQuestStageEvent` sink, `[QuestProgression] Running full resync ({}, includeInventory={})`. Payload fields read by the server: `quest_editor_id`, `quest_plugin`|`plugin`, `quest_form_id`|`form_id`, `stage`, `gamets`, `aliases[]` ({`name`,`is_actor`,`type`,`base_plugin`|`plugin`,`base_form_id`|`form_id`,`display_name`|`base_name`|`instance_text`}), `npc_name`, `location_name`|`location`, `items[]` ({`plugin`|`source_plugin`,`baseid`|`form_id`,`count`}). `player_inventory_sync` is raised server-side from the normal player inventory upload (`gamedata.php:517` -> `chimQuestEngineSyncPlayerInventory`).

**B. Conversation -> beat.** `returnLines()` (`HS/lib/chat_helper_functions.php:1285`) collects every emitted NPC sentence in `$chimQuestDialogueParts` (1292, 1643) and at the end calls (1945-1951):
```php
chimQuestEngineHandleLiveDialogueTurn($GLOBALS["HERIKA_NAME"] ?? '', implode(' ', $chimQuestDialogueParts), $GLOBALS["gameRequest"] ?? array());
```
`chimQuestEngineHandleLiveDialogueTurn($npcName, $npcResponseText, array $gameRequest)` (3204-3263): ignores `The Narrator`/`Player`; player-driven request types = `inputtext`, `inputtext_s`, `ginputtext`, `ginputtext_s`; with player-only OFF also `instruction`, `suggestion`, `rechat`, `continue`, `continue_group`, `combatbark`. Player text = `chimQuestEngineExtractPlayerUtterance($gameRequest)` (3178-3201: strips `Name:` prefix, `(context ...)`, `(talking to ...)`). Emits event `dialogue_turn` with payload `{event_source:'live_dialogue', npc_name, player_text, npc_text, player_driven, request_type, gamets, ts, location_name, listener}`.

`chimQuestEngineHandleEvent($eventType, array $payload)` (3139-3175): save-rewind guard `chimQuestEngineRollbackRuntimeToGamets($payload['gamets'])` (2982-3025: if incoming gamets < max stored gamets, DELETE later outbox/beat_state/events rows and rebuild instances); expand radiant templates; for `dialogue_turn` keep only definitions that reference the NPC (`chimQuestEngineFilterDefinitionsForEvent`, 1115-1141: NPC name must be a key of `npc_facts` or a beat `focus_npc`); per definition insert event row + `chimQuestEngineHandleEventForDefinition` (3028-3136).

Per definition: apply event to state -> `chimQuestEngineRehydrateBeatStateFromStage` (1367: backfills beats whose `set_stage` stage <= current stage, so progress made through normal menus is recognised) -> `chimQuestEngineEventCanFireBeats` (2833-2846: with player-only ON only `dialogue_turn` with `player_driven` may fire beats; game events only update state) -> up to 10 passes over unfired beats checking, in order, `chimQuestEngineBeatAllowedForRuntime` (1774: inactive quests only start through natural-start beats + `natural_start.requires` + `start_conditions`), `chimQuestEngineBeatPrerequisitesMet`, `chimQuestEngineConditionsMet`, `chimQuestEngineRequiredItemMet`, `chimQuestEngineBeatShouldFire` (2488). Keyword trigger = normalized substring match, `min_matches` default 1 (`chimQuestEngineKeywordMatchCount`, 2362-2386; normalization 245-270).

**C. How the LLM triggers it.** The roleplay LLM does NOT call an action/tool for this. If no keyword beat fired on a `dialogue_turn`, `chimQuestEngineSelectDialogueBeatByIntent` (2219-2336) makes a **second, separate LLM call**: `$driver->fast_request($messages, array('MAX_TOKENS'=>220), 'quest_intent')` on the current connector (temperature 0.1), max `$GLOBALS['CHIM_QUEST_DIALOGUE_INTENT_MAX_CALLS'] ?? 3` calls per turn (one per candidate quest), candidates = all currently legal dialogue beats for that NPC (`chimQuestEngineBuildDialogueIntentCandidates`, 1947). System prompt (2092-2106) begins "You are a strict quest dialogue intent evaluator for Skyrim." and demands `{"selected_beat_id": null, "confidence": 0.0, "reason": "brief reason"}`. Accepted only if `confidence >= threshold` (beat `intent_min_confidence` / trigger `min_confidence` / default 0.80; `chimQuestEngineBeatIntentThreshold`, 1924-1944). Beat is then fired as event `dialogue_turn_intent` with evidence `evaluation_mode='llm_fallback'`.

**D. Firing -> outbox.** `chimQuestEngineFireBeat` (2609-2740) marks the beat, then queues the primary action (except `gate`) and each `downstream` action whose `conditions` hold via `chimQuestEngineQueueResolvedAction` (2577-2606) -> `chimQuestEngineQueueAction($questKey,$beatId,$actionType,array $payload,$gamets)` (1440-1462) = INSERT into `skyrim_quest_action_outbox` with status `pending`. Payload = the action object + `quest_key`, `quest_editor_id`, `quest_plugin`, `quest_form_id`, `beat_id`, `gamets`, optional `source_action_type`, and `index` copied from `objective_index`.

**E. Wire format to the DLL.** Poll response (`chimQuestEngineFetchPendingActions`, 3284-3319), oldest first, limit 1..100:
```json
{"ok":true,"actions":[{"id":1,"quest_key":"...","beat_id":"...","action_type":"set_stage","action_gamets":123,"payload":{...},"status":"pending","created_at":"...","applied_at":null}]}
```
Ack request: `{"type":"quest_action_ack","action_id":N,"status":"applied"|<other>,"result":{...}}`. `chimQuestEngineAcknowledgeAction` (3418-3464): status `applied` -> for the three "start" action types the server state is only updated if `result.verified` is truthy, using `result.stage`/`result.gamets` (3322-3362); any other status -> the beat is un-fired so it can be retried (`chimQuestEngineResetFailedAcknowledgedActionBeat`, 3365-3415).

Action types present as strings in the DLL: `set_stage`, `set_objective_completed`, `set_objective_displayed`, `stop_quest`, `fail_all_objectives`, `start_quest_stage_objective`, `actor_dialogue_start_quest_stage_objective`, `change_location_start_quest_stage_objective`, `cross_quest_start`, `cross_quest_set_objective_completed`, `console_command`, `console_command_sequence`, `start_scene`, `set_actor_value`, `set_ghost`, `evaluate_package`, `remove_item`, `add_item`, `enable_ref`, `set_relationship_rank`. Payload/result field names present: `quest_plugin`, `quest_form_id`, `stage`, `objective_index`, `index`, `completed`, `displayed`, `force_displayed`, `command`, `commands`, `item_form_id`, `count`, `silent`, `form_id`, `fade_in`, `actor_form_id`, `actor_plugin`, `actor_name`, `actor_value`, `value`, `ghost`, `rank`, `location_form_id`, `old_location_form_id`, `old_location_plugin`, `story_event_wait_ms`, `verify_wait_ms`, `verified`, `applied_by`, `quest_found`, `quest_state_before`, `quest_state_after_story_event`, `quest_state_after_native_start`, `story_event_index`, `story_event_queued_id`, `actor_resolution_error`, `actor_resolution_fallback`. DLL symbols: `QueueQuestProgressionAction(const nlohmann::json&)`, `DispatchQuestProgressionPapyrusCall<...>(const char*, ...)`, `SendQuestProgressionActorDialogueStoryEvent`, `SendQuestProgressionChangeLocationStoryEvent`, `ResolveQuestProgressionStoryEventIndex(enum RE::QuestEvent, ...)`, log `[QuestProgression] Queued ActorDialogue story event actor={:08X} target={:08X} eventIndex={} queuedID={}`. INFERENCE: each action type maps 1:1 onto the same-purpose function in `CHIM/Source/Scripts/AIAgentQuestProgressionBridge.psc` (e.g. `set_stage` -> `SetQuestStage(int questFormId, int stage)`, `console_command_sequence` -> `ExecuteConsoleCommandSequence(String commands)` which splits on `||` with 0.25 s waits, lines 67-82), and the `*_start_quest_stage_objective` types first send a Story Manager event so SM-started quests fill their aliases, then fall back to `StartQuestStageObjective`. INFERENCE: the DLL learns the on/off state from `quest_status` (`enabled` key, 3497-3520) - log string `[QuestProgression] Server global setting changed to {}`.

Note: bundled definitions never use `console_command*`, `start_scene`, `set_actor_value`, `set_ghost`, `evaluate_package` - the DLL supports more than the data uses.

**F. Prompt side effects when enabled.** (a) `chimQuestEngineBuildPromptContext($npcName,$locationName)` (3687-3773) appended to `$dynamicBiography` inside `<character>` (`main.php:2569-2575`): for each active definition whose `npc_facts` has this NPC, `base_facts` + `beat_facts` of fired beats, plus the hint "The player's current words or possessions may be relevant to this quest. Respond naturally if they discuss it." when a dialogue beat is pending. Output wrapper: `\n<quest_context>\n#Quest-sensitive facts\nQuest: <title>\n- fact ...\n</quest_context>`. (b) `chimQuestEngineApplyActionSuppressionsForTurn` (3658-3684, called `main.php:2466`) removes `suppress_actions` codes from the offered action list via `unsetFunction()` and `functions.php:2846-2852` drops them if the LLM still emits them.

**G. Reset.** `chimQuestEngineResetRuntime(true)` on game `init` and `wipe` (`comm.php:161,312`); combined with the gamets rollback in (B).

### 1.5 UI pages and authoring path (VERIFIED)

- Web: `HS/ui/addons/snqe/hub.php:15-43` "AI Quest Manager" with tabs AI Quest Manager (SNQE), Item Types, NPC Templates, NPC Own Templates, Outfits, Server Logs, **Traditional Quests** (`traditional_quests.php`): list/filter, per-quest modal of beats, POST actions `toggle_active`, `reset_quest_state`, `reset_all_state` (373-412), CSV upload `submit_tradquest_csv`, example download `?action=download_example_tradquest_csv`, JSON `?action=quest_detail&quest_key=`.
- Mod-author drop-in: "Manual upload uses the same format as auto imports from Data/CHIM/*_tradquest.csv. Required format: quest_key, quest_editor_id, title, quest_plugin, quest_form_id, active, skeleton_json." (`traditional_quests.php:848`). DLL strings: `_tradquest.csv`, `Found traditional quest import file: {}`, `FindTraditionalQuestImportFiles`, `Data/CHIM`. Server importer `traditionalQuestImportFromCsvData($csvData,$filename)` (`import_files.php:852`) accepts columns `skeleton_json`|`definition_json`|`raw_json` or `beats_json` (+ `npc_facts_json`, `natural_start_json`, `description`, `active`), upload endpoint `HS/csv_import.php:52` type `traditional_quest_import`.
- In-game PrismaUI `CHIM/PrismaUI/views/CHIM/quest_manager.js:4-5` talks only to `/ui/addons/snqe/index.php` and `cmd/agent0.php` = SNQE (AI-generated quests), not the traditional engine.

### 1.6 Limits (VERIFIED from code/data unless marked)

1. Beta, off by default; per-quest authored; Bethesda masters only; one radiant template.
2. Sets stages directly. No TopicInfo/INFO fragment, no dialogue-driven scene, no speech-check branch, no barter/gold exchange (it actively suppresses `GiveGoldTo`/`TakeGoldFromPlayer`/`OpenInventory` instead), no dialogue-set globals/aliases/factions unless hand-replicated as downstream actions.
3. NPC identity = display-name string compare (`strcasecmp` on `focus_npc` / `npc_facts` key, 1093-1112) - breaks under NPC renamers/duplicate names.
4. One beat per event; up to 3 extra LLM calls per player turn; matching runs AFTER the NPC reply was generated, so the reply cannot know whether the beat fired (it only sees facts of previously fired beats).
5. INFERENCE (code reading): `chimQuestEngineFireBeat` reads `$action['stage']` for `set_stage_cascade` (2656) but 9 of 33 cascade actions in the bundled data carry `stages:[...]` and no `stage` -> target stage -1 -> no stage action queued for those beats.
6. INFERENCE (code reading): `chimQuestEngineReferencedBeatFired` queries table `skyrim_quest_beat_states` (1649,1663) while the DDL/DB table is `skyrim_quest_beat_state` -> `quest_beat_fired` is always false.
7. `gamedata.php` has no ext hook; unknown `type` -> HTTP 400 (170-174).

---

## 2. How CHIM handles vanilla dialogue today

**NPC vanilla lines -> context: YES, type `chat`.** DLL format strings: `chat|{}|{}|(Context location: {}){}: {}`, `chat|{}|{}|(Context location: {}){}: {} ({} {})`, `chat|{}|{}|(Context location: {} background chat) {}: {}`; DLL registers `BSTEventSink<TESTopicInfoEvent>` and logs `[Chatbox] Pushing NPC dialogue: {} says: {}`. Server: `chat` is a log-only fast command (`main.php:227-228`, `949-984` -> `logEvent($gameRequest)`), and the context query keeps it (`data_functions.php:2587`). `main.php:1355-1356`:
```php
$sqlfilter=" and (type in ('prechat',...) or (type='chat' and {$visibleChatStateSql} and data like '(Context%') )";  // Use prechat
// chat entries starting by "(Context%" are standard skyrim dialogue
```
Rows containing `background chat` are classed `BACKDIAG` (`data_functions.php:2556`). AI-generated lines are also stored as `chat` (plus `prechat`) by `returnLines` (`chat_helper_functions.php:1890-1940`); `_speech` (`comm.php:626-962`) only marks them `delivery_state='spoken'` and fills table `speech`.

**Player's chosen menu topic: captured by the game side for TTS; eventlog type NOT VERIFIED.** `CHIM/Interface/dialoguemenu.swf` (decompressed in memory) contains `SendModEvent`, `PlayMenuTopic`, `TopicClicked`, `startTopicClickedTimer`, `SetSelectedTopic`. DLL: `PlayerMenuModCallbackEventSink`, `[PlayerMenuTTS] Releasing held topic for '{}' ({})`, `[PlayerMenuTTS] Timed out waiting for selected dialogue topic; replaying original click`, `[Chatbox] Pushing player dialogue: {} says: {}`, request types `player_menu_tts_prefetch` / `player_menu_tts_play|`. Server branch `comm.php:1162-1216` only synthesizes/caches player TTS (`soundcache/md5(line).wav`) and echoes `Player|ScriptQueue|{subtitle}//__player_menu_tts///1.0` (`comm.php:96`); it does **not** call `logEvent`. Whether the DLL separately logs the player's topic text as a `chat` row could not be confirmed (eventlog in this install has 14 rows, none of type chat). Looked in: `comm.php`, `main.php`, DLL strings, unittests.

**`startPlayerMenuDialogueTTS`** (`CHIM/Source/Scripts/AIAgentFunctions.psc:138`): `int function startPlayerMenuDialogueTTS(String fallbackText) Global Native`. Used by `OnPlayerMenuTopicSelected(String eventName, String strArg, Float numArg, Form sender)` (`AIAgentPapyrusFunctions.psc:1170-1184`): if MCM conf `_player_tts_traditional_dialogue` <= 0 it immediately releases the menu with `UI.InvokeString("Dialogue Menu", "_root.DialogueMenu_mc.startTopicClickedTimer", "off")`; else starts TTS and releases on ModEvent `AIAgent_PlayerMenuTTSFinished` (1186-1195). In 3.3.2 the Papyrus path is unregistered - "Traditional dialogue Player TTS is handled in native code. Re-registering the Papyrus bridge here can resume the held topic twice." (`AIAgentPapyrusFunctions.psc:1097-1100`). MCM text: "Will play whatever PlayerTTS is selected for traditional dialogue ... requires the optional regular or VR dialogue menu interface patch." (`AIAgentMCMConfigScript.psc:2835`). Purpose = voice the player's clicked vanilla line; nothing more.

**Other greps.** `SayTo`: `int Function SayTo(Actor source,Actor dest,Form topicToSay) global Native` (`AIAgentFunctions.psc:88`; DLL log `[PAPYRUS] Papyrus::SayTo - Source ... Topic: {} (0x{:08x})`), used once to make a singer say a stop-singing Topic (`AIAgentPapyrusFunctions.psc:1323-1327`). It makes an actor speak a Topic; it is not player topic selection. "greeting", "press E", `TopicInfo` on the server: NOT FOUND (server hits for "topic" are SNQE `CreateTopic` knowledge topics and action `GetTopicInfo`, `action_catalog.php:1400`). `AIAgentScriptProxy.psc` command table (lines 15-163) has `29 = AllowPCDialogue`, `116 = BlockActivation` but no Quest/Topic commands. `ShowDebugNotification` waits while `UI.IsMenuOpen("Dialogue Menu")` (`AIAgentAIMind.psc:1438`); DLL conf `_pause_dialogue_when_menu_open`.

**LoreRim conflict fact.** `dialoguemenu.swf` is provided by `CHIM` and `Norden UI 16x9`; in `profiles/Ultra/modlist.txt` CHIM is line 7, Norden UI 16x9 line 147 (MO2: earlier line = higher priority) -> CHIM's SWF wins. `Smart Talk (Dialogue Menu Enhancer)` is enabled at line 3842.

---

## 3. Existing mapping from free conversation to vanilla topic selection

**NOT FOUND.** Searched server for `topic_?info|TopicInfo|dialogue_topic|say_topic|StartDialogue|topicid` (only SNQE/knowledge-topic hits) and Papyrus sources for `TopicInfo|GetTopic|OnTopic|IsInDialogueWithPlayer|GetDialogueTarget`.

Nearest existing mechanisms and their gaps:

| Mechanism | How it works | Gap vs. menuless questing |
|---|---|---|
| Beat engine (section 1) | free text -> keyword/LLM intent -> authored beat -> `SetStage` etc. | never selects a topic; INFO result scripts not run; per-quest authoring; vanilla only |
| Story-event start (`actor_dialogue_start_quest_stage_objective`, `change_location_...`) | DLL queues Story Manager ActorDialogue/ChangeLocation event, waits `story_event_wait_ms`, verifies, falls back to native start | only starts quests; used by 1 definition |
| `rehydrate from stage` (1367) | recognises progress made via real menus | passive only |
| Player menu TTS | voices clicked topic | no selection logic |

Reusable ideas: candidate-set + strict classifier + confidence threshold + "prefer null" prompt; gamets rollback; ack/verify outbox; `suppress_actions`.

---

## 4. Quest awareness in prompts

1. **`<active_quests>` / `<current_plans>`** - `DataGetCurrentTask()` (`HS/lib/data_functions.php:3615-3675`): ephemeral `currentmission` row, else `<current_plans>` (Current/Previous from `currentmission`), then `SELECT distinct name, briefing as description,gamets FROM quests order by gamets desc LIMIT 8` rendered as `## {name}: {briefing}`. Appended to `COMMAND_PROMPT` only when `$GLOBALS["CURRENT_TASK"]` is true, request is not `diary`, and `(!$GLOBALS["IS_NPC"]) || HERIKA_NAME=="The Narrator"` (`main.php:2077-2087`); `IS_NPC=false` only for members of the party conf (`main.php:1103-1111`). Default `$CURRENT_TASK=false` (`conf.sample.php:65`). Subsections toggleable (`settings.php:726-735`). So: followers + narrator only, <= 8 lines.
2. **Source tables.** `quests` filled by DLL `_quest` JSON (`comm.php:381-406`; fields `formId,name,currentbrief,currentbrief2,stage,status,data.questgiver`; DLL `ProcedureSendActiveQuests`, wiped by `_questreset`), `_questdata` sets `briefing2`; `questlog` appended by `_uquest` (`id@?@briefing@stage`, `comm.php:410-431`). Live: `quests`=0 rows, `questlog`=133 rows with editor IDs of LoreRim/CC quests (e.g. `MGR20 | 0 | Return the book to Urag gro-Shub`).
3. **`ReadQuestJournal` action** (`functions.php:959`: "Only use if #PLAYER_NAME# explicitly asks about a quest...") -> `DataQuestJournal($quest)` (`data_functions.php:1969`) returns JSON of all `quests` rows + current task. In `core_action`: activated, available to npc/followers/narrator.
4. **`quest` eventlog rows**: DLL `quest|{}|{}|(Context location: {}) Quest Updated "{}" new objetive: {}` -> logged (`comm.php:1045-1059`), shown in history as subtype `QUEST` spoken by `narratorci` (`data_functions.php:2559,2764`) to actors in the row's `people` list; optional narrator quest comment (`quest_comment_enabled`, `quest_comment_chance` 10, `quest_comment_cooldown` 3 min; `comm.php:1068-1127`).
5. **`<quest_context>`** from skeleton `npc_facts` (section 1.4 F) - any NPC named in a skeleton, only with the beta flag on.
6. Dynamic Oghma per quest stage (section 1.3).

There is no per-NPC "this NPC has quest dialogue for you" signal and no objective list for non-follower NPCs.

---

## 5. Director / narrator / story-beat systems and hooks

- **Rolemaster "Director Mode"** - chat mode `DIRECTOR` (`HS/processor/chim_modes.php:113-131`) runs `php /var/www/html/HerikaServer/service/manager.php rolemaster instruction "<wish>" notify`; also LLM action `DirectorCommand` (`functions.php:974,3240-3290` -> `herikaQueueNarratorDirectorCommand($directive)` :870; in `core_action` but `is_activated=false`, narrator-only). Task registry `$GLOBALS["TASKS"]["rolemaster"]["fn"]` (`service/processors/rolemaster/entrypoint.php:3-4`), sub-commands `instruction`, `suggestion`, `impersonation`, `spawn`, `smart_impersonation`. Output channels (`cmd/instruction.php:339-378`): INSERT into `responselog(localts,sent,actor,text,action,tag)` with `actor='rolemaster'`, `action="rolecommand|Instruction@{character}@{text} (must use ACTION ...)@{taskId}"`, and INSERT into `rolemaster(localts,ttl,type,data)` with `type='scenenote'`, `ttl=300`. Scene notes are injected for every NPC as `<scene_notes>` (`data_functions.php:1548-1560`); `rolemaster` also stores `type='story_summary'` (`instruction.php:78`). Connector setting `CORE_CONNECTOR_DIRECTOR` / `CORE_CONNECTOR_DIRECTOR_ENABLED` (live: `1` / `true`).
- **Bored events** -> rolemaster (`main.php:1019-1031`, `lib/rolemaster_bored.php`) or narrator (`NARRATOR_BORED_EVENT_ACTIVE`, `main.php:321-338`).
- **Narrator**: random narration (`main.php:1256-1349`, prompt key `random_narration_prompt`, header `X-Event-Type: narration`), quest comments, welcome, `narrator_inputtext` mode.
- **SNQE** (Skyrim Narrative Quest Engine, `service/processors/snqe/`, API doc `lib/api_doc.php`): LLM-written AI quests with `CreateNPC`, `CreateItem`, `CreateTopic`, `SpawnNPC`, `CheckNPCSpawn`...; tables `sneq_quests`, `sneq_quests_saved`, `quest_*` reference tables; game event `snqe` with `START|END|CLEAN|RESTART` (`comm.php:2632-2700`); Papyrus `StartQuestNotification/EndQuestNotification/UpdateQuest/SetQuestTracker` (`AIAgentAIMind.psc:2783-2964`) and `AIAgentTrackerQuestScript.psc`.
- **Background Life** (`lib/background_life_*.php`) - off-screen NPC activity.
- **Generic hooks** (VERIFIED): ext files auto-required by name - `globals.php` (`main.php:54`), `preprocessing.php` (:193), `prerequest.php` (:1117), `context_pre.php` (:2540), `context.php` (:2648), `prepostrequest.php` (:2944), `postrequest.php` (:2946), `context_building.php` (`data_functions.php:3217`), `json_response_custom.php` (`json_response.php:81`); `$GLOBALS["external_fast_commands"]` (`main.php:233`); `$GLOBALS["EXT_CONTEXT_SQL_FILTER1/2"]` (`data_functions.php:2538-2539`); eventlog types `ext_%` are kept in history as subtype `PLUGIN` (`data_functions.php:2576`); `chimRegisterPromptInjection(string $slot, string $id, $content, int $priority = 100): bool` with slots `character_bottom` and `prompt_bottom` rendered at `main.php:2553-2566` (`lib/prompt_injections.php:10,59`); `chimRegisterActorProfileEnricher(string $id, callable $callback, int $priority = 100)` (:87); `service/manager.php:31` auto-loads any `service/processors/*/entrypoint.php`; Papyrus `int function PostGameData(string jsonData) global native` (`AIAgentFunctions.psc:83`); `logEvent($dataArray,$forcePeople='')` (`chat_helper_functions.php:5345`). DLL string `Data/CHIM/server-plugins` exists (purpose not investigated here).

---

## 6. Implications for the glue: ADD vs REUSE vs AVOID

### Must ADD (nothing in CHIM does this)
1. **Topic discovery + execution on the game side**: enumerate the currently valid player topics/INFOs for the dialogue target (conditions evaluated by the engine), and execute the chosen INFO for real (result fragments, next-topic links, scene/quest effects). CHIM has no API for either -> this is the justified SKSE C++ part; Papyrus alone cannot enumerate conditioned TopicInfos.
2. **A generic (data-driven) candidate matcher on the server**: candidates = live topic list from the game (prompt text + INFO id), not authored beats. Borrow CHIM's pattern: legal candidate set -> strict classifier -> confidence threshold -> null by default.
3. **Own transport for candidates/choices**: `gamedata.php` rejects unknown types and has no ext hook; use an ext-owned endpoint under `ext/<glue>/` and/or custom request types registered through `$GLOBALS["external_fast_commands"]` + `preprocessing.php`, with `ext_*` eventlog types for history.
4. **Own state with gamets rollback + ack/verify**, modelled on `chimQuestEngineRollbackRuntimeToGamets` and the outbox `verified` ack; reset on `init`/`wipe`.
5. **Per-NPC quest-dialogue awareness in the prompt** (available topics, objectives involving this NPC) via `chimRegisterPromptInjection('character_bottom'| 'prompt_bottom', ...)` or `context_pre.php`.
6. Decide the reply-ordering problem CHIM left unsolved: choose the topic BEFORE generating the NPC reply (so the reply can paraphrase the real INFO response), not after.

### Can REUSE
- Vanilla NPC lines already arrive as `chat` rows `(Context location: ...)Name: line` - the executed INFO's spoken response will enter context with no extra work.
- `questlog`/`quests` tables and `_uquest` stream (editor ID + stage + objective text, includes LoreRim mod quests) as the generic "what is active" feed.
- `responselog` queue (`actor|action|text` lines returned on `request` polls) for server->game commands; `rolemaster` `scenenote` rows for short-lived global scene guidance.
- `fast_request($messages, array('MAX_TOKENS'=>N), '<tag>')` connector pattern with temporary connector overrides (`chimQuestEnginePushConnectorOverrides` / `Pop`, 2037-2078) for cheap classifier calls.
- `unsetFunction($codeName)` to hide conflicting LLM actions during a quest hand-off turn.
- `AIAgentFunctions.PostGameData`, `jsonGet*` helpers, `SayTo`.
- Optionally, as a fallback for quests whose topics cannot be executed: the bundled skeleton file is a ready stage map for 300 vanilla quests (read-only use).

### Must AVOID colliding with
- Do not turn on `CHIM_AI_QUEST_PROGRESSION` for quests the glue drives: both would advance the same quest (double `SetStage`, duplicate item removal). If the user enables it, the glue should detect it (SELECT `general_settings`/`quest_status`) and either stand down per quest or require `active=false` on those definitions (user action in the Traditional Quests tab).
- Do not write into `skyrim_quest_*` tables or reuse the outbox as a command channel: FK to `skyrim_quest_instances`, drained only when the beta flag is on, and acks mutate CHIM's beat state.
- Do not ship or patch `Interface/dialoguemenu.swf` (CHIM's copy carries `PlayMenuTopic`/`startTopicClickedTimer`; the native Player-menu TTS gate holds the clicked topic). If the glue selects topics programmatically while `_player_tts_traditional_dialogue` is on, expect the DLL gate (`[PlayerMenuTTS] ... replaying original click`) to interact - test with it both on and off.
- Do not reuse names: gamedata types `quest_*`, event types `dialogue_turn*`, tables `skyrim_quest_*`/`quest_*`/`sneq_*`, prompt tags `<quest_context>`, `<active_quests>`, `<current_plans>`, `<scene_notes>`, globals `CHIM_QUEST_SUPPRESSED_ACTIONS*`, Papyrus script `AIAgentQuestProgressionBridge`.
- NPC identity: use form IDs, not display names (CHIM's name matching is its weak point).
- Extra LLM calls per turn add latency on top of CHIM's up-to-3 intent calls when both run.

## Open questions
1. Does the DLL log the player's clicked vanilla topic text to eventlog (type `chat`?) or only to the PrismaUI chatbox? Needs one in-game test + `SELECT type,data FROM eventlog`.
2. Exact DLL mapping action type -> bridge function and the semantics of `applied_by`/`verified` (strings only; would need runtime logs in `Documents/My Games/.../SKSE/AIAgent.log`).
3. Where exactly the DLL scans for `*_tradquest.csv` - UI text says `Data/CHIM/`; not confirmed from the binary beyond the string `Data/CHIM`.
4. What `Data/CHIM/server-plugins` does (possible official route to ship the glue's server ext from the game mod folder).
5. Whether the DLL exposes any native for Story Manager events or TopicInfo to Papyrus (none in `AIAgentFunctions.psc`); assume no.
6. Is the 9x `stages` cascade / `skyrim_quest_beat_states` naming issue fixed upstream in later CHIM builds? (Affects only the fallback use of skeletons.)

---

## Verification (adversarial pass)

Method: every load-bearing claim was re-opened at its cited file:line (UNC reads of `HS/...`, direct reads of `CHIM/...`), the bundled JSON was re-counted with an independent script, the live DB was re-queried (SELECT / `\d` only, peer auth as `postgres`, no password used), `AIAgent.dll` printable strings and raw byte sequences were re-extracted, and both `dialoguemenu.swf` files were zlib-decompressed in memory. DLL build identified by strings `3.3.2`, `D:\wt\chim-332-main-voice-hotfix\Plugin\Plugin.cpp`, `2026-09-02`. Nothing was written outside this file and SCRATCH.

### Confirmed claims (19 of 23 as stated; 4 need the corrections below)

- C1 flag default OFF + setting text: `HS/conf/conf.sample.php:61-62`, `HS/conf/conf_schema.json:61-62`, `HS/ui/global_settings.php:115-116`; live `general_settings` (columns are `id,value,description,updated_at`): `CHIM_AI_QUEST_PROGRESSION=false`, `CHIM_PLAYER_ONLY_QUEST_ADVANCEMENT=true`.
- C2 inert when off: `chim_quest_engine.php:3141-3148`, `3206-3208`, `3268-3270`, `3286-3288`, `3689-3691`. Nuance: `chimQuestEngineAcknowledgeAction` (3418) and `chimQuestEngineResetRuntime` (3467) are NOT flag-gated.
- C3 five tables / columns / FKs: `HS/data/chim_quest_engine.sql:192-252` exact. Live: only `skyrim_quest_*` tables exist (no `aiquest*`, no `chim_quest_*`).
- C4 counts: re-counted = 301 entries (218/26/57), 300 `quest` + 1 `radiant_template` (`Favor204`), 1406 beats; live DB 301 definitions all `active`, 301 instances all `inactive`, outbox/events/beat_state = 0 rows.
- C5 action-type counts exact (856/516/33/1; downstream 211/188/169/11/9/4/2/1/1). Bridge script read in full (149 lines): it only calls `SetStage`, `SetObjectiveCompleted/Displayed`, `FailAllObjectives`, `Start`, `Stop`, `Scene.Start`, `SetActorValue`, `SetGhost`, `EvaluatePackage`, `Add/RemoveItem`, `Enable`, `SetRelationshipRank`, `ConsoleUtil.ExecuteCommand`. No Topic/TopicInfo use.
- C7 classifier: `fast_request($messages, array('MAX_TOKENS' => 220), 'quest_intent')` at 2294; overrides pushed at 2285-2291 (`temperature 0.1`, `reasoning_model false`); limit `CHIM_QUEST_DIALOGUE_INTENT_MAX_CALLS ?? 3` at 2239; threshold default 0.80 at 1942 / 2314; prompt text 2092-2106 exact.
- C8 wire format: `gamedata.php:49-62,125-163` exact; poll row keys at 3304-3314 exact.
- C10 DLL action types: confirmed and strengthened (see Additions A4).
- C11 CSV import: `traditional_quests.php:848` text exact; `import_files.php:51,852-1014`; `csv_import.php:52`.
- C12 `<quest_context>` + suppression: 3687-3773, 3658-3684, `main.php:2466-2471,2569-2575`, `functions/functions.php:2846-2852`; `suppress_actions` values = `GiveGoldTo`/`TakeGoldFromPlayer`/`OpenInventory` x46 each.
- C13 vanilla lines as `chat` rows: `main.php:1355-1356`, `main.php:227-231,949-984`, `data_functions.php:2587`; `chimBuildChatDeliveryStateSql` = `COALESCE(delivery_state,'spoken') IN ('emitted','spoken')` (`chat_helper_functions.php:4217-4244`), so DLL-written rows with NULL `delivery_state` are visible.
- C14 `int function startPlayerMenuDialogueTTS(String fallbackText) Global Native` (`AIAgentFunctions.psc:138`); `AIAgentPapyrusFunctions.psc:1097-1100,1170-1195` exact; SWF strings `SendModEvent`, `PlayMenuTopic`, `startTopicClickedTimer`, `skse` exist in CHIM's SWF and are absent from Norden UI's; `comm.php:96,1162-1216` has no `logEvent`.
- C15 no topic mechanism: server grep = 10 hits in 4 files, none dialogue-topic related; Papyrus hits are commented-out lines plus the `stopSinging` Topic (`AIAgentPapyrusFunctions.psc:1323-1327`); `AIAgentScriptProxy.psc` has no Quest/Topic/Scene command.
- C16 `<active_quests>`: `main.php:2077-2087,1103-1111`, `data_functions.php:3615-3675`, `conf.sample.php:65`. Nuance: `IS_NPC=false` for EVERY actor when the party conf does not decode to an array (`main.php:1110-1111`).
- C18 Director Mode: `chim_modes.php:113-131`, `instruction.php:339-378`, `data_functions.php:1548-1560`. Citation note: `functions/functions.php:870-879` is `herikaQueueNarratorDirectorCommand`, not the scene-note injection.
- C20 `gamedata.php` has no ext hook (no `requireFiles`/`ext` reference in it or in `lib/runtime_bootstrap.php`); default branch 170-174 returns HTTP 400.
- C21 modlist: line 7 `+CHIM`, line 147 `+Norden UI 16x9`, line 3842 `+Smart Talk (Dialogue Menu Enhancer)`. Also present but disabled: line 29 `-Norden UI 21x9` (third loose `Interface/dialoguemenu.swf`).
- C22 rollback: 2982-3025, called at 3154; `comm.php:161-163,312-314`.
- C23 both defects reproduced (see Correction 5 for the understated impact).
- Spot-checked names, all correct: `chimQuestEngineBeatFocusNpcMatches` (1093), `chimQuestEngineDefaultState` (364-375), `chimQuestEngineRehydrateBeatStateFromStage` (1367), `DataDequeue` (`data_functions.php:874`), `DataQuestJournal` (1969), `syncQuestWithOghma($questId, $stage)` (`dynamicoghma.php:3`), `chimRegisterPromptInjection` / `chimRegisterActorProfileEnricher` (`prompt_injections.php:10,87`), `unsetFunction($functionCodename)` (`functions.php:2706`), `logEvent($dataArray,$forcePeople='')` (5345), `service/manager.php:31`, `rolemaster/entrypoint.php:3-4`, `AIAgentAIMind.psc:1438,2783,2825,2834,2964`, `AIAgentMCMConfigScript.psc:2835`, `quest_manager.js:4-5` (full path is `/ui/addons/snqe/cmd/agent0.php`), `hub.php:41-42`, `_questreset` (`comm.php:583-585`), quest-comment settings (`comm.php:1072-1080`).

### Corrections

1. **C6 / section 1.4 B - the engine is NOT invoked once per turn.** `returnLines($sentences)` is called once per streamed sentence chunk (`HS/lib/data_functions.php:5975` inside the stream loop, again at 6021 for the remainder), and each call ends with `chimQuestEngineHandleLiveDialogueTurn` (`chat_helper_functions.php:1945-1951`). So the engine runs mid-stream, several times per reply, each time with only that chunk as `npc_text` and the same `player_text`. The classifier `fast_request` therefore blocks the streaming loop (added latency before the next sentence is emitted). Limits item 4 ("matching runs AFTER the NPC reply was generated") should read "after the first emitted chunk".
2. **Limits item 4 "One beat per event" is wrong.** `chimQuestEngineHandleEventForDefinition` fires every eligible beat in a `do/while` of up to 10 passes (`chim_quest_engine.php:3065-3097`); only the LLM-intent fallback is limited to one beat (3100-3119).
3. **C9 "failed ack un-fires the beat" holds only for three action types.** `chimQuestEngineResetFailedAcknowledgedActionBeat` returns early unless the type is `start_quest_stage_objective`, `actor_dialogue_start_quest_stage_objective` or `change_location_start_quest_stage_objective` (3372 with 2565-2574). For `set_stage` (856 of 1406 beats) server state is committed optimistically when the beat fires (2648-2653, 2584-2586) and a failed ack rolls nothing back. Also the ack reply is `{'success':true,'id':N,'status':...}` (3462), not `ok`.
4. **C19 / section 6 "Can REUSE ... `AIAgentFunctions.PostGameData`" contradicts C20.** INFERENCE, strong: `PostGameData` targets `gamedata.php`. Evidence: DLL strings `PostGameData`, `HTTPManager::postGameData*`, `gamedata.php`; the only Papyrus caller builds `|type|:|market_stock|` (`AIAgentScriptProxy.psc:1628-1655`) and `market_stock` is a `gamedata.php` case (line 116). Unknown types get HTTP 400, so the glue cannot use `PostGameData` for its own payloads. Use `logMessage` / `requestMessage` / `sendMessage` style natives (`AIAgentFunctions.psc:4-5,27-30`) with a custom request type registered in `$GLOBALS["external_fast_commands"]`, or an ext-owned endpoint.
5. **C23 impact understated.** `quest_beat_fired` is used by `natural_start.requires` in 130 of 301 bundled definitions (95 with `natural_start.enabled=true`; example `C01` requires `{"type":"quest_beat_fired","quest_key":"c00_takeuparms","beat_id":"QUEST_COMPLETE"}`). Because `chimQuestEngineReferencedBeatFired` checks the non-existent table `skyrim_quest_beat_states` (1649, 1663; the only two occurrences on the server), those quests can never be conversation-started from the inactive state; they are only recognised after the game itself emits a `quest_stage` event (`chimQuestEngineBeatAllowedForRuntime`, 1777-1785). Additionally 8 downstream objective actions use the key `objective` instead of `objective_index`/`index`; the server copies only `objective_index` to `index` (2600-2602). Whether the DLL reads `objective` is NOT VERIFIED (no standalone `objective` string in the binary).
6. **Section 2: the `({} {})` chat format is most likely the PLAYER's menu line, not an NPC line.** INFERENCE from string adjacency inside the `TESTopicInfoEvent` handler region of the DLL: `Shouting to | Whispering to | Talking to | chat|{}|{}|(Context location: {}){}: {} ({} {}) | debug | traditional_player_speech | [Chatbox] Pushing player dialogue: {} says: {}` and then `chat|{}|{}|(Context location: {}){}: {} | audios | traditional_npc_speech | [Chatbox] Pushing NPC dialogue: {} says: {}`. This suggests Open question 1 is "yes, as `chat` rows shaped `(Context location: X)Player: text (Talking to NPC)`". Neither `traditional_player_speech` nor `traditional_npc_speech` occurs on the server (grep: no matches), and the live eventlog has no `chat` rows, so an in-game test is still needed.
7. **C17 "ALL quests" is an inference.** Verified: `_uquest` handler (`comm.php:410-431`), DLL format `_uquest|{}|{}|{}@{}@{}@{}`, live `questlog` = 133 rows / 94 distinct ids including mod and CC editor IDs (`REQ_Quest_Installation`, `WTKillThalmor`, `ccBGSSSE069_Quest`, `MGR20`). Not verified: that every quest update is sent. Live `quests` = 0 rows, so the `_quest` JSON stream is unobserved here. Key mismatch to plan for: `questlog.id_quest` holds the editor ID string, `quests.id_quest` holds `formId` from the `_quest` JSON (`comm.php:387-398`). `questlog` is not pruned on `init`.
8. **Section 1.4 E: `set_stage` is not a contiguous string in the DLL** (0 raw hits); it exists only as the inlined 8-byte immediate `set_stag` at file offset 0x1c6f5d. `gate` and `set_stage_cascade` are absent (expected: resolved server-side). `quest_import_bundled` and `quest_reset_runtime` have 0 hits, so this DLL never sends them; of the six `quest_*` gamedata types it uses `quest_event`, `quest_action_poll`, `quest_action_ack`, `quest_status`.
9. **Section 6 reuse of `responselog` / `rolemaster` needs a caveat:** both tables are fully deleted on every game `init` (`comm.php:138-139`, `" 1=1 "`), and `DataDequeue` claims rows with `sent=0` for whichever `request` poll arrives first (`data_functions.php:874-907`). `service/manager.php:31` scans `service/processors/` only, so it is not an `ext/` hook.

### Additions (facts the report missed)

- **A1. Official route to ship the server ext from the game mod folder (answers Open question 4).** DLL `ServerPluginSync.cpp` strings: `Data/CHIM/server-plugins`, `ui/api/plugin_packages.php`, `?action=`, `probe`, `start-upload`, `upload-chunk&upload_id=`, `&index=`, `[SERVER_PLUGIN_SYNC] Installed {} {}`, `... has multiple packages; using newest file {}`, `... package filename is not a valid version: {}`. Server: `HS/ui/api/plugin_packages.php:27-62` (actions `probe`, `start-upload`, `upload-chunk`, `status`, `packages`) and `HS/lib/plugin_package_manager.php`: extensions `dwpkg`|`zip` (:11), `SCHEMA_VERSION = 4` (:13), archive must contain `manifest.json` + `checksums.sha256` (:266) and a `server/` payload, nothing else (:342-349); manifest requires `name`, `version`, `server` (:331) with optional `server.mutable_paths`; name regex `/^[A-Za-z0-9][A-Za-z0-9 ._-]{0,63}$/` (:355), version regex `/^[0-9A-Za-z][0-9A-Za-z._+-]{0,63}$/` (:365); payload is installed to `HerikaServer/ext/<name>` with backup and rollback (:444-481); `migrations/*.sql` run once each, tracked in `plugins.plugin_migrations` (:501-538). INFERENCE: layout is `Data/CHIM/server-plugins/<PluginFolder>/<version>.dwpkg`.
- **A2. `_tradquest.csv` auto-upload path (answers Open question 3).** DLL `DetectAndUploadImportDataFiles` strings in order: `Starting CSV import data detection...`, `Data/CHIM`, `No import data CSV files found in CHIM directory`, `FindTraditionalQuestImportFiles`, `_tradquest.csv`; upload via `csv_import.php` + `?type=` + `&filename=` with type `traditional_quest_import`.
- **A3. More ext hooks.** `ext/**/functions.php` is auto-required by `requireFunctionFilesRecursively` (`functions/functions.php:2688-2704,2752-2754`), the way an ext registers LLM actions (example `HS/ext/herika_heal/functions.php`); `$GLOBALS["action_post_process_fnct_ex"][]` post-filter closures can drop or rewrite LLM-emitted actions server-side (`functions.php:2828-2857`), the natural place for a hard gate; `$GLOBALS["HOOKS"]["BIOGRAPHY_BUILDER"]` callables `(&$dynamicBio, $currentNpcData)` (`data_functions.php:7620-7649`); `$GLOBALS["HOOKS"]["JSON_TEMPLATE"]` (`json_response.php:45-46`); ext files `prompts.php` (`prompts/prompts.php:285`) and `dialogue_prompt.php` (`prompts/dialogue_prompt.php:370`); prompt-injection `content` may be a callable `($slot, $context)` with context keys `game_request`, `herika_name`, `narrator_name`, `player_name` (`prompt_injections.php:36-38`, `main.php:2547-2552`). Existing ext plugins usable as templates: `herika_heal`, `relationship_system`, `time_awareness`, `xLifeLink_plugin`.
- **A4. DLL action-type to bridge-function mapping is visible as an interleaved string table** (upgrades the report's INFERENCE to strong evidence; region 0x310100-0x310900): `SetQuestStage`; `set_objective_completed` + `cross_quest_set_objective_completed` -> `SetQuestObjectiveCompleted`; `set_objective_displayed` (`displayed`, `force_displayed`) -> `SetQuestObjectiveDisplayed`; `fail_all_objectives` -> `FailAllQuestObjectives`; `start_quest_stage_objective` -> `StartQuestStageObjective`; `change_location_start_quest_stage_objective` -> `SetQuestStageObjective`; `console_command` -> `ExecuteConsoleCommand`; `console_command_sequence` (`commands` | `command_sequence`) -> `ExecuteConsoleCommandSequence`; `stop_quest` -> `StopQuest`; `start_scene` -> `StartScene`; `set_actor_value` -> `SetActorValue`; `set_ghost` -> `SetActorGhost`; `evaluate_package` -> `EvaluateActorPackage`; `remove_item`/`add_item` -> `RemoveItemFromPlayer`/`AddItemToPlayer`; `enable_ref` -> `EnableReference`; `set_relationship_rank` -> `SetActorRelationshipToPlayer`. Extra field names not in the report: `runtime_quest_form_id`, `runtime_actor_form_id`, `runtime_item_form_id`, `runtime_ref_form_id`, `runtime_scene_form_id`, `runtime_location_form_id`, `requested_actor_form_id`, `requested_actor_name`, `resolved_actor_name`, `expected_stage`, `native_start_wait_ms`, `already_at_stage`, `native_ensure_start`, `location_plugin`, `npc_name`; story-event codes `ADIA`, `CLOC`; failure texts `unsupported action type`, `quest progression disabled before execution`, `quest did not reach expected stage`.
- **A5. `<quest_context>` leaks facts for quests that have not started.** `chimQuestEngineBuildPromptContext` has no `run_state` check (3703-3733): when the flag is on, `base_facts` of every active definition naming the NPC are injected even while the instance is `inactive`.
- **A6. With player-only ON (default), the 1124 `quest_stage` triggers never fire beats** (`chimQuestEngineEventCanFireBeats`, 2833-2846); game events only update state and rehydrate. The keyword trigger matches `player_text` only (2436); `npc_text` is used only inside the classifier prompt.
- **A7. INFERENCE for the glue:** because the DLL sinks `TESTopicInfoEvent` and writes `chat` rows for both sides of traditional dialogue, a topic executed programmatically through the real dialogue system should be logged to context with no extra server work, while a path that bypasses the dialogue system (running a fragment directly) will not be; this needs a runtime test. CHIM's SWF also overrides Norden UI's dialogue-menu skin in this profile (CHIM line 7 above Norden UI line 147), a visible side effect to mention to the user.

### Verifier confidence

High for the server-side and Papyrus facts (each re-read at the cited lines, counts reproduced exactly, DB re-queried). Medium for anything about DLL behaviour: strings and their adjacency prove names exist, not control flow, so Corrections 4 and 6 and Additions A1 (folder layout), A4 and A7 remain inferences until checked against `AIAgent.log` at runtime.
