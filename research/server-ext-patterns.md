# HerikaServer ext/ plugins as patterns: relationship_system, time_awareness, xLifeLink_plugin + plugin packaging

Phase 0 research, read-only. Server inspected: DwemerDistro WSL distro `DwemerAI4Skyrim3`, `/var/www/html/HerikaServer`.
Path shorthand used below: `HS/` = `/var/www/html/HerikaServer/` (Windows: `\\wsl.localhost\DwemerAI4Skyrim3\var\www\html\HerikaServer\`).
Every name below was copied from an opened file; `file:line` follows each claim. Items marked **INFERENCE** are reasoning, not source text.

## Executive summary (10 lines)

1. Plugins are plain folders `HS/ext/<name>/`; core loads hook files **by filename** with `requireFilesRecursively()` (`HS/lib/data_functions.php:7729-7747`). Hook files run **inside that function's scope** (only `global $gameRequest` is declared), so everything else must be reached via `$GLOBALS[...]`. Load order = `scandir` order of folder names.
2. Hook points in `HS/main.php`: `globals.php` :54, `preprocessing.php` :193, `prerequest.php` :1117, `context_pre.php` :2540 (before system prompt build), `context.php` :2648 (after), `prepostrequest.php` :2944, `postrequest.php` :2946 (after `X-CUSTOM-CLOSE` flush :2922). Others: `prompts.php`, `dialogue_prompt.php`, `context_building.php`, `json_response_custom.php`, `functions.php`.
3. relationship_system stores everything in `core_npc_master.extended_data -> 'relationships'` (JSONB map keyed by target name; player key is the literal `"Player"`). No relationship table exists; the only plugin tables are two queues. API = `RelationshipManager` in `HS/lib/relationship_manager.php`.
4. Scale is -100..+100, 11 tiers (`Bonded`..`Hostile`); 32 built-in types incl. `romantic`, `crush`, `ex`; **no `lover`/`spouse` type** - `lover`, `married`, `marriage`, `romance` are input aliases of `romantic`; spouse-ness lives in the free-text `relation` field (`"wife"`, `"husband"`, `"lover"`). 1293 `bio_templates` rows pre-seed structured NPC->NPC relationships (157 `romantic`, 32 `crush`).
5. The relationship LLM can **never persist a promotion into a romantic-leaning type**: `rebaseRelationshipChange()` blocks it unconditionally (`relationship_llm.php:1708-1717`). Romantic types toward the Player therefore only come from the UI editor, templates, or a plugin calling `RelationshipManager::setRelationship()`.
6. Non-blocking LLM work: `postrequest.php` only UPSERTs a row in `relationship_eval_queue`; a detached PHP daemon (`worker.php --daemon`, spawned with `proc_open` from `context_pre.php`, double-forked with `pcntl_fork`/`posix_setsid`) polls every 2 s and calls the connector chosen by `RELLLM_CONNECTOR` via `$driver->fast_request()`.
7. Live state on this machine: `RELATIONSHIP_SYSTEM_ENABLED=true`, `RELLLM_CONNECTOR=5` (Mistral Small 3.2 24B via openrouterjson), `RELATIONSHIP_UPDATE_CHANCE=50`; `core_npc_master` has 0 rows (fresh DB), worker has never run.
8. Reads for gating: `RelationshipManager::getPlayerRelationship($npcName)` -> `['aff','type','tier', ...]`. Writes: `setRelationship()/adjustRelationship()`; generic `NpcMaster::updateByArray()` silently **preserves** relationship keys unless wrapped in `chimRunWithRelationshipExtendedDataWrite()`. Direct writes skip the advisory lock and the `relationships_locked` user lock - the glue must add both.
9. Text injection: `context_pre.php` appends to `$GLOBALS["HERIKA_PERS"]` / `$GLOBALS["COMMAND_PROMPT"]`; official registry `chimRegisterPromptInjection($slot,$id,$content,$priority)` with slots `character_bottom` / `prompt_bottom`; `time_awareness/context.php` prepends to `$GLOBALS["request"]` (and is hard-disabled with `if (false && ...)`).
10. Packaging: three installers. (a) GitHub tarball installer `HS/ui/server_plugin_installer.php` (wipes the plugin folder on update), (b) legacy `HS/ext/generic_installer.php` (unlinked), (c) **new game-bundled sync**: `AIAgent.dll` scans `Data/CHIM/server-plugins`, uploads `.dwpkg`/`.zip` to `HS/ui/api/plugin_packages.php` -> `DwemerPluginPackageManager` (schema_version 4, `checksums.sha256`, `server/` payload, `mutable_paths` preserved, SQL `migrations/` tracked in `plugins.plugin_migrations`). No install/uninstall PHP hooks exist anywhere.

---

## 0. Hook loader mechanics (context for every question)

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
```

VERIFIED consequences:
- Hook files execute in **function scope**. `$gameRequest` is available as a true global (and is a reference: `$GLOBALS["gameRequest"] = &$gameRequest;` `HS/main.php:135-136`). Any other main.php variable (`$request`, `$head`, `$contextDataFull`, `$db`, `$currentNpcData` ...) must be accessed as `$GLOBALS["..."]`. Example of the trap: `relationship_system/postrequest.php:146` tests `isset($currentNpcData["npc_name"])`, which can never be set in that scope; it falls through to `$GLOBALS["HERIKA_NAME"]`.
- Recursive: a hook file in any sub-folder of the plugin is also picked up (e.g. `ext/x/sub/context.php`). Do not name helper files after hook names.
- `require_once` => each hook file runs at most once per PHP request.
- Order between plugins = `scandir()` ascending byte order of folder names (uppercase before lowercase). `relationship_system` runs before `time_awareness` before `xLifeLink_plugin`. A glue folder that must see `HERIKA_PERS` *after* relationship injection needs a name sorting after `relationship_system` (INFERENCE from scandir semantics).

Hook call sites (all VERIFIED):

| Hook filename | Call site | When |
|---|---|---|
| `globals.php` | `HS/main.php:54` (also several `HS/ui/...` test/API pages, e.g. `ui/api/chim_diary_audio.php:35`) | after libs loaded, before request parsed (`$gameRequest` not yet set) |
| `preprocessing.php` | `HS/main.php:193` | after `$gameRequest = explode("|", $receivedData)` (:135), DB connected (:166); comment at :195 says extensions may rename request types here |
| `prerequest.php` | `HS/main.php:1117` | after profile/NPC selection (`HERIKA_NAME`, `CACHE_PARTY`, `IS_NPC` set :1103-1111), **before** `processor/comm.php` (:1124) which handles all non-LLM events |
| `context_pre.php` | `HS/main.php:2540` | before system prompt string is assembled (:2595-2602) |
| `context.php` | `HS/main.php:2648` | after `$head[]` system message is built (:2636), before the user prompt `$prompt[]` is built from `$request` (:2659-2745) |
| `prepostrequest.php` | `HS/main.php:2944` | after `echo 'X-CUSTOM-CLOSE'` + flush (:2922-2926) and semaphore release (:2939-2940) |
| (core) `processor/postrequest.php` | `HS/main.php:2945` | |
| `postrequest.php` | `HS/main.php:2946` | last thing in the request |
| `prompts.php` | `HS/prompts/prompts.php:285` (and `HS/lang/de/prompts.php:262`) | after core `$PROMPTS` and lang override |
| `dialogue_prompt.php` | `HS/prompts/dialogue_prompt.php:370` | |
| `context_building.php` | `HS/lib/data_functions.php:3217` | may rewrite `$GLOBALS["CONTEXT_BUILDING_DATA"]` (history array), returned at :3221 |
| `json_response_custom.php` | `HS/functions/json_response.php:81` | once (`CHIM_JSON_RESPONSE_EXT_LOADED`); plus callable list `$GLOBALS["HOOKS"]["JSON_TEMPLATE"]` :45-48 |
| `functions.php` | `HS/functions/functions.php:2754` via `requireFunctionFilesRecursively($folderPath)` (:2688-2704) | after core `F_NAMES` etc. are published (:1138-1140) |

The official modders guide lists the same hook names (https://dwemerdynamics.com/chim/modders-guide.html, fetched via WebFetch; content summarised by the fetch model, consistent with local source).

`ignore_user_abort(true); set_time_limit(1200);` at `HS/main.php:106-107` is what lets post-request hooks keep running. `terminate()` (`HS/lib/auditing.php:26-48`) echoes `X-CUSTOM-CLOSE`, flushes, releases semaphores `MAIN`,`ADDNPC`,`VSX`, then `die()`.

---

## 1. relationship_system: what it does, storage, scale, types, change rules, injection, async, toggle

### 1.1 What it is
Bundled extension (no `manifest.json`, so it is not listed in the Server Plugins table; `HS/ui/server_plugins.php:454-455` only lists folders with a manifest). Files (`HS/ext/relationship_system/`): `README.md`, `context_pre.php`, `postrequest.php`, `async_queue.php`, `worker.php`, `relationship_llm.php`, `event_baseline.php`, `npc_save_handler.php`, `relationship_editor.php`, `analyze_relationships.php`, `batch_analyze.php`, `batch_build.php`, `comm.php`. Core logic class lives **outside** ext: `HS/lib/relationship_manager.php` (`class RelationshipManager`, all static).

Stale docs warning (VERIFIED by diff against code): `README.md:153-165` shows a 9-tier table (Devoted/Attached/Fond/Warm...) and `README.md:55` names `context.php`; the code uses 11 tiers and the file is `context_pre.php`. `context_pre.php:5-13` header also claims it processes the queue - it does not; only `worker.php:145-146` calls `_relProcessQueue` / `_relProcessInitQueue`. `ext/relationship_system/comm.php` is a 14-line stray copy of the legacy entry point (requires `conf/conf.php` relative to its own dir, which does not exist) - ignore it.

### 1.2 Tables and columns (VERIFIED with `\d` on the live DB)

Relationship data itself:
```
public.core_npc_master
  id integer PK, npc_name text UNIQUE NOT NULL, ..., relationships text (legacy free text),
  metadata jsonb, extended_data jsonb, refid varchar(16), gender text, race text,
  gamets_last_updated numeric, lock_profile integer, ...
public.core_npc_master_history   (same columns + history_id PK, npc_id, created timestamp)
  index idx_core_npc_master_history_restore_v2 (npc_id, gamets_last_updated DESC NULLS LAST, created DESC, history_id DESC)
```
Keys inside `extended_data` owned by the system (`HS/lib/core/npc_master.class.php:603-610`):
```
relationships, relationships_analyzed, relationships_inferred,
relationships_last_eval, relationships_model, relationships_updated
```
plus the user lock `relationships_locked` (bool) (`npc_save_handler.php:56-58`, `postrequest.php:215-224`) and the history marker `_chim_history_source` (`'relationship'|'infosave'`).

Shape of `extended_data.relationships` (VERIFIED from `relationship_llm.php:1545-1597`, `relationship_manager.php:277-332`, and live `bio_templates` rows):
```json
{ "Player":        {"aff": 45, "type": "platonic", "note": "...", "best": "...", "best_delta": 15,
                    "worst": "...", "worst_delta": -20, "relation": "employer", "custom_info": "..."},
  "Danica Pure-Spring": {"aff": 70, "type": "professional", "relation": "superior and fellow healer", "note": "..."},
  "Stormcloak":    {"aff": -60, "type": "enemy"} }
```
Targets may be NPC names, factions/groups/concepts, or the canonical player key `"Player"`. `inferred_from` is added by transitive inference (`relationship_llm.php:793-797`).

Queue tables (created lazily by the plugin itself, not by migrations - `async_queue.php:349-375`, `:558-583`):
```sql
CREATE TABLE IF NOT EXISTS relationship_eval_queue (
    id SERIAL PRIMARY KEY, npc_id INTEGER NOT NULL UNIQUE, eval_data JSONB NOT NULL,
    created_at TIMESTAMP DEFAULT NOW(), retry_count INTEGER DEFAULT 0, last_error TEXT)
CREATE TABLE IF NOT EXISTS relationship_init_queue (
    id SERIAL PRIMARY KEY, npc_id INTEGER NOT NULL UNIQUE, init_data JSONB NOT NULL,
    created_at TIMESTAMP DEFAULT NOW(), retry_count INTEGER DEFAULT 0, last_error TEXT)
```
Live DB note: `relationship_eval_queue` currently **lacks `last_error`** (older table); `_relEnsureRetryColumns()` (`async_queue.php:331-343`) adds it on first retry.

Other tables touched: `prompts (prompt_key PK, default_prompt, custom_prompt, description, ...)` keys `rel_llm_analysis`, `rel_llm_evaluation`, `rel_llm_npc_to_npc` (present live), `rel_tier_reference` (not present live -> hard-coded default `relationship_manager.php:547-558`); `audit_request` (each LLM call logged, `relationship_llm.php:278-287`); `speech` (listener lookup, `postrequest.php:55-61`); `eventlog` (event baseline, `event_baseline.php:223-233`); `conf_opts` id `chim_character_facts_sources` (see 1.7); `core_player` id `rel_batch_cancel` (`batch_build.php:62-66`).

Seed data: `combined_bio_templates.relationships` (text holding a JSON object) is read by `NpcMaster::fetchTemplateRow` (`npc_master.class.php:870`) and turned into `extended_data.relationships` by `normalizeNpcDataForPersistence()` when the text starts with `{` (`npc_master.class.php:718-735`, `:766-787`). Live `bio_templates`: 1293/1293 rows JSON-shaped; type histogram top: professional 971, platonic 572, familial 451, enemy 421 ... `romantic` 157, `crush` 32, `ex` 4; `relation` values for romantic rows: husband 47, wife 43, lover 15, secret lover 9 ... (read-only SELECT, this session). These are NPC->NPC; NPC->Player starts absent (= neutral 0).

### 1.3 Scale and tiers
```php
// HS/lib/relationship_manager.php:405-417
public static function getTierLabel($score) {
    if ($score >= 91) return "Bonded";
    if ($score >= 76) return "Devoted";
    if ($score >= 56) return "Fond";
    if ($score >= 31) return "Friendly";
    if ($score >= 6) return "Acquaintance";
    if ($score >= -5) return "Neutral";
    if ($score >= -30) return "Wary";
    if ($score >= -55) return "Cold";
    if ($score >= -75) return "Resentful";
    if ($score >= -90) return "Hateful";
    return "Hostile";
}
```
Affinity is always clamped `max(-100, min(100, ...))` (`relationship_manager.php:927`, `:1002`; `relationship_llm.php:1503`, `:1695`).
Legacy mapping to Skyrim-style rank -4..+4: `RelationshipManager::affinityToLegacyRank($affinity)` (`relationship_manager.php:717-729`; >=88 => 4 "Lover", >=63 => 3, >=38 => 2, >=13 => 1, >=-12 => 0 ...). No caller found outside the class (grep).

### 1.4 Enumerated types
```php
// HS/lib/relationship_manager.php:48-54
const TYPES = [
    'romantic', 'platonic', 'familial', 'professional', 'rival', 'enemy', 'neutral',
    'nemesis', 'estranged', 'transactional', 'protective', 'indebted', 'fanatical',
    'mentor', 'student', 'servant', 'client', 'patron', 'crush', 'ex',
    'betrayed', 'suspicious', 'admirer', 'jealous', 'fearful', 'obsessed',
    'awed', 'contempt', 'pitying', 'grateful', 'curious', 'dismissive'
];
// :58-66
const TYPE_ALIASES = [
    'romance' => 'romantic', 'marriage' => 'romantic', 'married' => 'romantic',
    'lover' => 'romantic', 'lovers' => 'romantic', 'betrayal' => 'betrayed', 'enemies' => 'enemy'
];
```
- **`romantic`: yes. `lover` / `spouse`: not types.** `lover` is only an alias folded into `romantic`; `spouse` is not even an alias. Marital/lover status is carried by the free-text `relation` field, rendered in context as `Type/relation` e.g. `Romantic/wife` (`relationship_manager.php:784-788`, `:817-821`).
- Custom types: any `/^[a-z][a-z0-9_-]{0,49}$/` string already present in that NPC's map counts as player-created and selectable (`getCustomRelationshipTypes`, `:255-275`; `canonicalizeRelationshipType($type, $allowedCustomTypes = [])`, `:222-249`). Live templates already contain off-list types (`predatory`, `respect`, `kinship`, `companion`, `avoidant`).
- "Romantic-leaning" set used by gates: `['romantic', 'crush', 'admirer', 'obsessed', 'infatuated', 'lover']` (`relationship_llm.php:1513`, `:1710`).
- Dynamic-eval prompt restricts LLM-settable types to `romantic, platonic, familial, professional, rival, enemy, crush, ex, betrayed` (`relationship_llm.php:1329-1331`, `:1030-1033`).

### 1.5 How values change
Master gates in `postrequest.php`: `RELATIONSHIP_SYSTEM_ENABLED` (:40), skip `The Narrator` (:161), skip NPCs with `extended_data.relationships_locked` (:215-224), then:

**Mode 1 - `RELLLM_CONNECTOR > 0` (live config):**
1. `RelationshipManager::shouldRunAutomaticEvaluation()` rolls `random_int(1,100) <= $GLOBALS['RELATIONSHIP_UPDATE_CHANCE']` (default 50; 0 = never, 100 = always) (`relationship_manager.php:164-179`, call at `postrequest.php:231`).
2. Build `$context` = `dialogue` (`$GLOBALS["talkedSoFar"]`), `events` (`content` of each `$GLOBALS["BUFFER"]` item), `player_action` (only for request types `inputtext, inputtext_s, ginputtext, ginputtext_s`, from `$gameRequest[3]` with `Name:` prefix and `(Talking to ...)` suffix stripped), `director_instruction` (type `instruction`), `nearby_npcs` (`$GLOBALS["CACHE_PEOPLE"]` comma list), `listener_name` (`postrequest.php:241-286`). Listener comes from `$GLOBALS["SCRIPTLINE_LISTENER_ATOMIC"]` > `$GLOBALS["SCRIPTLINE_LISTENER"]` > `speech` table (:172-184).
3. `_relQueueEvaluation([...])` (`postrequest.php:325-334`; `async_queue.php:47`) -> `INSERT ... ON CONFLICT (npc_id) DO UPDATE` with player-action priority (:87-101). One pending row per NPC.
4. Worker -> `RelationshipLLM::evaluateContext($npcId, $npcResponse, $context = [])` (`relationship_llm.php:867`) (Player listener) or `evaluateNpcToNpcContext($speakerNpcId, $listenerNpcId, $dialogue, $context = [])` (:1089). LLM must answer `{"changes": {"Player": {"delta": X, "type"?: "...", "reason": "brief"}}}` (:1028-1037).
5. `applyChanges($npcId, $changes, $currentRels)` (private, :1403-1678):
   - blocked target titles list (`dragonborn`, `thane`, `listener`, ... :1422-1436); `normalizeTargetName()` folds player aliases to `"Player"`;
   - zero-delta / neutral no-ops ignored (:1482-1487);
   - `newAff = clamp(oldAff + delta)`;
   - **earned-romance gate**: promotion into a romantic-leaning type needs `newAff >= 56` and non-empty reason (:1509-1522);
   - no explicit type and current type `neutral` => auto type from affinity via `inferTypeFromAffinity()` (:1787-1806): `>=6 platonic`, `<=-6 wary`, `<=-30 rival`, `<=-55 enemy` (note: `wary` is not in `TYPES`);
   - notes: `note` updated when `|delta| >= 3` or empty; `best`/`best_delta` when `delta >= 10` and >= previous; `worst`/`worst_delta` when `delta <= -10`; `relation` set once (:1552-1586);
   - persistence under `pg_advisory_lock(1001000000 + npcId)` (:71-81, :1611), re-fetch row, abort if JSON corrupt or `relationships_locked` (:1617-1629), **rebase each delta onto the fresh row** with `rebaseRelationshipChange()` (:1684-1765), write via `chimRunWithRelationshipExtendedDataWrite(...)` + `NpcMaster::updateByArray`, set `relationships_last_eval`, then `chimRelationshipTimelineStamp($npcId)` (:1654-1667).
   - **`rebaseRelationshipChange()` blocks every romantic promotion** regardless of affinity: `if (in_array($requestedType, $romanticTypes, true) && !$freshIsRomantic) { $rebased['type'] = $freshType; }` with the comment "Romantic types are player-set, not model-assigned" (:1708-1717). Since `$existingRels[$target] = $rebasedRel` (:1651) is what is saved, the persisted type never becomes romantic through the LLM path, even though `$applied[...]['type']` and the `TYPE CHANGE` log line may say so. (VERIFIED code reading; behavioural confirmation in-game is an open question.)
6. Magnitude guidance given to the LLM: +/-1 normal chat, +/-2-3 notable, +/-5-10 meaningful help/gifts/insults, +/-15-25 saving life/violence/betrayal, +/-50+ extreme (`relationship_llm.php:1311-1318`).

**Mode 2 - no `RELLLM_CONNECTOR`:** conversation model emits `#REL:Name=+/-N#` and `#TYPE:Name=Type#`; parsed synchronously by `RelationshipManager::parseChanges($aiResponse, $npcName)` (`relationship_manager.php:899-978`; regexes `/#REL:([^=]+)=([+-]?\d+)#/`, `/#TYPE:([^=]+)=([a-zA-Z][a-zA-Z0-9_-]{0,49})#/`). Instructions injected by `getSystemPromptAddition()` (:1040-1066). No romance gate in this path.

Other writers: UI editor (`npc_save_handler.php`, fields `$_POST['relationships_jsonb']`, `$_POST['relationships_locked']`; included from `HS/ui/core/npc_master.php:584-585, 610-611, 705-706`), `HS/ui/api/chim_npc_manager.php:816`, `inferTransitiveRelationships($npcId)` (`relationship_llm.php:735`; `transitiveAff = intval(my*their/200)`, kept if `abs>=15`, capped +/-50, never overwrites existing), NPC creation seed from templates (1.2). `analyzeNpc($npcId, $forceReanalyze = false)` is now a **no-op** returning `['ok'=>true,'skipped'=>true,...]` (:294-316); private `runAnalysis()` (:321) has no caller.

Timeline coupling (important): `chimRelationshipTimelineStamp($npcId)` (`npc_master.class.php:282-359`) stamps `gamets_last_updated` from `$GLOBALS['gameRequest'][2]` (fallback `DataLastKnownGameTS()`) and snapshots to `core_npc_master_history` via `backupNpcById($id,'relationship')`. On save-load `NpcMaster::restoreNPC($timestamp)` (:1400+) and `chimRelationshipRestoreQuery($timestamp)` / `chimRelationshipFutureClearQuery($timestamp)` (:180, :237; called :1520, :1577) roll relationships back to the loaded save's game time unless `NEVER_CLEAR_RELATIONSHIP_DATA` is true (`conf_schema.json:117`).

### 1.6 How it injects context
`context_pre.php` (runs at `main.php:2540`):
```php
// ext/relationship_system/context_pre.php:150-196 (abridged, exact lines)
$npcName = $GLOBALS["HERIKA_NAME"] ?? null;
$nearbyNpcs = array_map('trim', explode(',', $GLOBALS["CACHE_PEOPLE"]));          // :159
$knownRels = RelationshipManager::getRelationships($npcName);                      // :167 (scan $GLOBALS["HERIKA_CONTEXT"] for mentions)
$relationshipContext = RelationshipManager::buildContext($npcName, $relevantNpcs); // :185
$GLOBALS["HERIKA_PERS"] .= "\n\n" . $relationshipContext;                          // :192
// Mode 2 only:
$GLOBALS["COMMAND_PROMPT"] .= "\n\n" . $relationshipInstructions;                  // :208
```
`HERIKA_PERS` lands inside `<character>...</character>` (`main.php:2597`); `COMMAND_PROMPT` inside `<general_instructions>` (`main.php:2599`).

`buildContext($npcName, $nearbyNpcs = [])` (`relationship_manager.php:746-836`) output, tier-only mode (RELLLM set):
```
[TIER REFERENCE - Adjust behavior toward NPCs based on tier]
HOSTILE: ... (11 lines, prompt key rel_tier_reference or default :547-558)

[Lydia's RELATIONSHIPS]
<PlayerName>: Fond (Romantic/wife) - <worst>; <best> | <note>
<NearbyNpc>: Wary (Rival)
```
Mode 2 format: `sprintf("%s: %+d (%s, %s)", $name, $aff, $tier, $typeStr)` (:797, :826). The Player line is **always** present (defaults `Neutral (Neutral)`), other lines only for nearby/mentioned NPCs that have an entry. Heading via `buildRelationshipHeading()` (:838-846). Rolemaster variant: `buildDirectorContext($npcsInScene = [], $mentionedNpcs = [])` (:1079).

### 1.7 Async queue + worker (non-blocking LLM calls)
- **Queueing**: `postrequest.php` runs after the response was flushed (`main.php:2922-2926`) and only does one UPSERT (`async_queue.php:87-101`). No LLM call happens in the web request in Mode 1.
- **Worker start**: `context_pre.php:38-41` calls `_relEnsureWorkerRunning()` on every LLM request when `RELLLM_CONNECTOR > 0`. Logic (:50-147): fast path PID file `HS/log/relationship_worker.pid` + `/proc/{pid}`; fallback `pgrep -a php | grep 'worker.php.*--daemon'`; else spawn:
  ```php
  $cmd = "/usr/bin/php " . escapeshellarg($workerPath) . " --daemon";      // :104
  $descriptors = [0 => ['file','/dev/null','r'], 1 => ['file',$logTarget,'a'], 2 => ['file',$logTarget,'a']];
  $proc = proc_open($cmd, $descriptors, $pipes); proc_close($proc);          // :113-116
  usleep(200000);                                                            // :123
  ```
  Log: `HS/log/relationship_worker.log`. "no cron, no sudo" (:37).
- **Daemonization** (`worker.php`): args `--daemon`, `--interval=N` (default 2 s, :24-31); `pcntl_fork()` -> parent writes child PID to pid file and exits (:43-53); child `posix_setsid()`, reopens stdio to the log (:55-77); `register_shutdown_function` removes the pid file (:86-94).
- **Bootstrap inside the daemon** (:96-135): sets `$GLOBALS['ENGINE_PATH']`, requires `conf/conf.php`, `lib/postgresql.class.php` (`$GLOBALS['db'] = new sql();`), `lib/core/api_badge.class.php`, `lib/logger.php`, `lib/settings.php`, calls `chimLoadGeneralSettingsIntoGlobals()`, sets `HERIKA_NAME='Worker'`, loads `PLAYER_NAME` from `conf_opts WHERE id='PLAYER_NAME'`. General settings are read **once at daemon start**: a changed `RELLLM_CONNECTOR` *id* needs a worker restart (INFERENCE from the code path). The connector *row* is re-read from the DB on every batch because `_relProcessQueue()` does `new RelationshipLLM()` -> `readOne($connectorId)` each call (`async_queue.php:167-170`), so edits to that connector's model/temperature are picked up live.
- **Loop** (:144-184): `processOneBatch()` = `_relProcessQueue(10)` + `_relProcessInitQueue(5)`; if nothing processed `sleep($interval)`; on exception `sleep($interval*2)`.
- **Queue processing** (`async_queue.php:147-322`): rows ordered `COALESCE(retry_count,0) ASC, created_at ASC`; success => DELETE; failure => `retry_count+1`, `last_error`; abandoned after `REL_QUEUE_MAX_RETRIES` = 3 (:378). `_relRequireLlmSuccess($result, $operation)` converts non-`ok` results into retryable exceptions (:24-33).
- **Connector used** (`relationship_llm.php:185-207`):
  ```php
  require_once $GLOBALS['ENGINE_PATH'] . "lib/core/llm_connector.class.php";
  $llmConnector = new LLMConnector();
  $connectorId = $GLOBALS['RELLLM_CONNECTOR'] ?? 0;
  if ($connectorId > 0) { $this->connector = $llmConnector->readOne($connectorId); }
  if (empty($this->connector)) { $connectors = $llmConnector->readAll(); $this->connector = $connectors[0]; } // fallback: first connector
  $this->driver = $llmConnector->getConnector($this->connector);
  ```
  Request wrapper `makeSafeRequest($messages, $params, $context)` (:224-250): saves `$GLOBALS["CONNECTOR"]`, `$llmConnector->setOldGlobals($this->connector)`, `return $this->driver->fast_request($messages, $params, $context);`, restores globals in `finally`. Params used: `["MAX_TOKENS" => 512]` (eval), `1024` (analysis); call names `"relationship_eval"`, `"relationship_npc_to_npc"`, `"relationship_llm"`. Driver signature: `public function fast_request($contextData, $customParms,$callName='')` (`HS/connector/openrouterjson.php:1179`, also openaijson :1281, groqjson :616, player2json :734; `google_openaijson.php:509` has no `$callName`). `LLMConnector` methods: `readAll()` :141, `readOne($id)` :147, `getById($id)` :160, `setOldGlobals($currentConnectorData)` :260, `getConnector($currentConnectorData)` :425 (`HS/lib/core/llm_connector.class.php`).
- **Prompts** are DB-overridable: `loadPrompt($promptKey, $fallback)` reads `SELECT custom_prompt, default_prompt FROM prompts WHERE prompt_key = ...` (:105-131).
- **Extension facts for the evaluator** (data-driven, designed for third parties, works in the daemon): `conf_opts` row `id='chim_character_facts_sources'`, value = JSON array of `{table, name_column, facts:{label: sql_expression}, skip_values:{label:[values]}}` (`relationship_llm.php:138-184`). For each source it runs `SELECT (<expr>) AS f0, ... FROM {table} WHERE {name_column} = '<npcName>'` and prepends `Character facts for <npc> (persistent profile - respect these when judging relationship changes): label: value; ...` to the eval context (:349, :892, :1126-1127). Table/column names are sanitised to `[A-Za-z0-9_]` (so **schema-qualified names like `plugins.x` are stripped to `pluginsx`** - a facts table must be reachable via `search_path public`); the SQL expressions are not sanitised. Row is absent on this install.

### 1.8 Enabled by default? Toggle?
- Setting `RELATIONSHIP_SYSTEM_ENABLED`: boolean, scope global, UI title "Relationship System" (`HS/conf/conf_schema.json:116`); catalog default `true` (`HS/lib/core/prisma_settings_catalog.php:89`); it is the availability switch paired with `RELLLM_CONNECTOR` (`HS/lib/settings.php:58-70`). **Live value: `true`** (`public.general_settings`, SELECT this session).
- Storage: table `public.general_settings (id text PK, value text, description text, updated_at timestamp)`; every row is loaded into `$GLOBALS[id]` each request by `chimLoadGeneralSettingsIntoGlobals()` (`HS/lib/settings.php:1138-1150`; `main.php:30` bootstrap option `'load_general_settings' => true`). Both hook files bail with `if (empty($GLOBALS['RELATIONSHIP_SYSTEM_ENABLED'])) { return; }` (`context_pre.php:28`, `postrequest.php:40`).
- Toggle in the web UI Global Settings (`HS/ui/global_settings.php:158-161`), also profile-overrideable (no `profile_overrideable:false` in schema, unlike `NEVER_CLEAR_RELATIONSHIP_DATA`). Programmatic: `chimSetGeneralSetting('RELATIONSHIP_SYSTEM_ENABLED', false)` (`settings.php:961`).
- Related: `RELLLM_CONNECTOR` (live `5` = "Mistral Small 3.2 24B", driver `openrouterjson`; sample default `conf.sample.php:181`), `RELATIONSHIP_UPDATE_CHANCE` (live `50`; `conf.sample.php:182`), `NEVER_CLEAR_RELATIONSHIP_DATA` (live `false`).
- `RelationshipManager` read/write methods do **not** check the toggle - data stays readable when the system is disabled (it just stops evolving and stops being injected).

---

## 2. Reading and writing relationship data from a third-party plugin

### 2.1 Read (gating)
```php
require_once $GLOBALS["ENGINE_PATH"] . "lib/relationship_manager.php";

// HS/lib/relationship_manager.php
public static function resolveNpcByName($npcName)                 // :597  -> core_npc_master row|null (exact, status-suffix stripped, ucfirst, case-insensitive, in-range bridge)
public static function getRelationships($npcName)                 // :671  -> normalized map [target => rel]
public static function getRelationship($npcName, $targetName)     // :687  -> ['aff'=>int,'type'=>string,'tier'=>string, + note/best/worst/relation if stored]
public static function getPlayerRelationship($npcName)            // :709  -> getRelationship($npcName, 'Player')
public static function getTierLabel($score)                       // :405
public static function normalizeTargetName($targetName)           // :186  ('player','the player','dragonborn','#player_name#','{player_name}', PLAYER_NAME) => 'Player'
public static function canonicalizeRelationshipType($type, $allowedCustomTypes = [])  // :222
public static function normalizeRelationshipMap($relationships)   // :277
public static function affinityToLegacyRank($affinity)            // :717
```
Default when no entry: `['aff' => 0, 'type' => 'neutral', 'tier' => 'Neutral']` (:698-702). Direction matters: the map on NPC X is how **X feels about** the target. The player has no row; "Player feels about X" is not tracked.

Equivalent SQL (read-only, VERIFIED column names):
```sql
SELECT id, npc_name, gender, race, refid,
       (extended_data->'relationships'->'Player'->>'aff')::int AS aff,
       extended_data->'relationships'->'Player'->>'type'       AS type,
       extended_data->'relationships'->'Player'->>'relation'   AS relation,
       COALESCE((extended_data->>'relationships_locked')::boolean, false) AS locked
FROM core_npc_master WHERE npc_name = '<escaped name>' LIMIT 1;
```
Prefer the PHP API: it normalises legacy aliases (`romance`->`romantic`), player-name keys, and rename-mod name drift.

### 2.2 Write
```php
public static function setRelationship($npcName, $targetName, $affinity, $type = null)  // :983  absolute set, clamps, aliases type, allows custom type (<=50 chars)
public static function adjustRelationship($npcName, $targetName, $delta)                // :1031 getRelationship + setRelationship(current+delta, current type)
public static function mergeAiRelationshipMap($existingRelationships, $incomingRelationships, $replaceExisting = false) // :337
```
Both go through the sanctioned path: `chimRunWithRelationshipExtendedDataWrite(function () {... $npcMaster->updateByArray([...]) ...})` then `chimRelationshipTimelineStamp($npcData['id'])` (:1012-1020), so changes appear in the NPC page "Recent Relationship Changes" and follow save/load rollback.

**The guard you must know** (`npc_master.class.php:583-628`): any `NpcMaster::update()/updateByArray()` that includes `extended_data` has the six relationship keys overwritten with the *current DB values* unless `$GLOBALS['CHIM_ALLOW_RELATIONSHIP_EXTENDED_DATA_WRITE']` is truthy. The only supported way to set it:
```php
// HS/lib/core/npc_master.class.php:363-378
function chimRunWithRelationshipExtendedDataWrite($callback)   // sets the flag, runs $callback(), restores flag in finally
```
Corollary (useful): a plugin can store **its own keys** in `extended_data` with a plain `updateByArray` and cannot accidentally damage relationships.

What `setRelationship()/adjustRelationship()` do **not** do (VERIFIED by reading :983-1035):
1. no `pg_advisory_lock` - read-modify-write of the whole JSON can race the worker daemon (separate process). The worker rebases onto a fresh row under lock `1001000000 + npc_id`, so take the same lock around plugin writes:
   `SELECT pg_advisory_lock(1001000000 + <id>)` ... `SELECT pg_advisory_unlock(1001000000 + <id>)` via `$GLOBALS['db']->execQuery()` (pattern `relationship_llm.php:71-95`; session-level lock on the non-persistent `pg_connect` link, `HS/lib/postgresql.class.php:14`).
2. no `relationships_locked` check - user-locked NPCs would be overwritten. Check `extended_data.relationships_locked` first (pattern `postrequest.php:215-224`).
3. no `note`/`best`/`worst`/`relation` handling, no auto-evolve from `neutral`, no romance gate. To set a `note` ("helped recover the family sword") do a custom write mirroring `relationship_llm.php:1636-1667`.
4. no toggle check.

Non-write alternatives that cooperate with the plugin instead of bypassing it:
- register a facts source in `conf_opts.chim_character_facts_sources` (1.7) so the relationship LLM sees glue state when judging deltas;
- let the quest-help event reach the evaluator naturally: it reads `$GLOBALS["BUFFER"][*]['content']` and `talkedSoFar` (`postrequest.php:244-256`). What populates `BUFFER` was not traced (open question).

Skyrim-side rank: a script-proxy wrapper `SetRelationshipRank(string $targetObjectFormId, string $akOther, int $aiRank): array` exists at `HS/lib/scriptproxy_papyrus.php:410` (not investigated here).

---

## 3. time_awareness/context.php - exact append mechanism

```php
// HS/ext/time_awareness/context.php:104-113
// FORCED DISABLED: ensure TIME_AWARENESS never triggers
if (false && isset($GLOBALS["TIME_AWARENESS"]) && $GLOBALS["TIME_AWARENESS"]) {
    $additionalPrompt = getTimePrompt();
	if (strlen($additionalPrompt) > 0) {
		$GLOBALS["request"]="{$additionalPrompt} {$GLOBALS['request']}";
	}
}
```
- Variable: **`$GLOBALS["request"]`** - a plain **string** (the final instruction/cue message), not an array. The plugin **prepends** `"(<sentence>) "` to it. `getTimeText()` wraps the sentence in parentheses (:68-69).
- Why it works from `context.php`: that hook runs at `main.php:2648`, after the system message (`$head[]`, :2636) but before `$request` is copied into the last prompt message: `$prompt[] = array('role' => $LAST_ROLE, 'content' => $request);` (`main.php:2745`; other branches :2659, :2669, :2676, :2685). `$request` is set earlier by `HS/processor/request.php` (e.g. :73, :160, :172, :175). Modifying `HERIKA_PERS`/`COMMAND_PROMPT` in `context.php` is too late (COMMAND_PROMPT is even blanked at :2641-2644).
- The feature is **dead code in this build** (`if (false && ...)`); only the pattern is reusable. It only acted on request types `rechat, radiant, inputtext, inputtext_s, ginputtext, ginputtext_s` (:20) and skipped names containing `narrator`/`player`/`prisoner` (:88-89). Helpers used: `DataLastKnownGameTS()`, `GetFirstTimeMet($player,$npc)`, `GetLastInteraction($player,$npc)`, `convert_gamets2seconds()` (from `lib/data_functions.php`, `lib/utils_game_timestamp.php`, hard-required by absolute path :3-4).
- `manifest.json`: `{"name":"Time Awareness","description":"...","version":"2.0.0"}`. The folder is hidden from and undeletable in the plugin manager (`HS/ui/server_plugins.php:431`, `:528`).

Where text can be appended - summary of VERIFIED sinks:

| Sink | Shape | Set from hook | Lands in |
|---|---|---|---|
| `$GLOBALS["HERIKA_PERS"]` | string, `.=` | `context_pre.php` | `<character>` (`main.php:2597`) |
| `$GLOBALS["COMMAND_PROMPT"]` | string, `.=` | `context_pre.php` | `<general_instructions>` (:2599) |
| `$GLOBALS["PROMPT_NEARBY_SECTIONS"]` | string | `context_pre.php` (re-synced :2542-2545) | nearby actors section |
| `chimRegisterPromptInjection('character_bottom'|'prompt_bottom', $id, $content, $priority)` | registry `$GLOBALS['PROMPT_INJECTIONS'][$slot][$id] = ['id','content','priority']` | any hook up to `context_pre.php` | :2553-2566, :2597/:2600 |
| `chimRegisterActorProfileEnricher($id, callable $cb, $priority)` ; cb `($actorName, $actorType, $context)` | `$GLOBALS['PROMPT_ACTOR_PROFILE_ENRICHERS']` | early hook | per-actor profile lines (`data_functions.php:1085`, `:1227`) |
| `$GLOBALS["request"]` | string, prepend/append | `context.php` | final cue message |
| `$GLOBALS["CONTEXT_BUILDING_DATA"]` | array of history entries (`role`/`content`, internal `_g`) | `context_building.php` | dialogue history |

```php
// HS/lib/prompt_injections.php
function chimRegisterPromptInjection(string $slot, string $id, $content, int $priority = 100): bool            // :10
function chimRenderPromptInjections(string $slot, array $context = []): string                                // :59
function chimRegisterActorProfileEnricher(string $id, callable $callback, int $priority = 100): bool          // :87
function chimBuildActorProfileEnrichmentText(string $actorName, string $actorType, array $context = []): string // :107
```
`$content` may be string, array of lines, or callable `($slot, $context)`; context keys passed by main.php: `game_request`, `herika_name`, `narrator_name`, `player_name` (`main.php:2547-2552`). Lower priority renders first; duplicates removed (:84). Loaded from `data_functions.php:16`, i.e. available in every hook including `globals.php`.

---

## 4. xLifeLink prerequest.php + globals.php - what prerequest hooks can do

Status: effectively inert. Both files start with `if (isset($GLOBALS["FEATURES"]["MISC"]["LIFE_LINK_PLUGIN"])) { return; }` (`globals.php:3-5`, `prerequest.php:4-6`) and `conf.sample.php:605` sets `$FEATURES["MISC"]["LIFE_LINK_PLUGIN"]=false;` - `isset(false)` is true, so they return immediately. `manifest.json`: `{"name":"LifeLink","description":"LifeLink is loaded. Currently not used."}` (no version). It also targets a legacy model (per-NPC `conf/conf_<md5(name)>.php` files, table `json_personalities`) that predates `core_npc_master`.

What the code demonstrates (patterns):
- **globals.php = register overrides early**: replaces a core prompt (`$GLOBALS["UPDATE_PERSONALITY_PROMPT"] = "..."`, :9-42) and installs a **callable hook** `$GLOBALS["CustomUpdateProfileFunction"] = function($content) {...}` (:48-51) consumed by core at `HS/processor/comm.php:2347-2348` (`if (array_key_exists(...) && is_callable(...)) $responseParsed["HERIKA_DYNAMIC"] = $GLOBALS["CustomUpdateProfileFunction"]($buffer);`). Note `$gameRequest` does not exist yet at `globals.php` time.
- **prerequest.php = mutate per-request state before anything is processed**: reads `$GLOBALS["HERIKA_NAME"]`, bails for `"The Narrator"` (:12-16), supports MinAI "realnames" `Name [Real Name]` (:21-26), queries the DB (`getJSONPersonality()`, `util.php:8-16`), then **overwrites `$GLOBALS["HERIKA_PERS"]`** (:140) and even rewrites the NPC conf file with `file_put_contents` (:142-146). Uses `getcwd()` instead of `__DIR__` to survive symlinked dev checkouts (:33-41). Guards helper definitions with `if (!function_exists('GetConfigPath'))` because MinAI defines the same name.

Capabilities of a `prerequest.php` hook (VERIFIED from call-site position `main.php:1117` and loader semantics):
- **Mutate the request**: yes. `$gameRequest` is declared `global` by the loader and is the same array core uses next (`processor/comm.php` is required at :1124). Core itself rewrites `$gameRequest[3]` just above (:1094, :1100). A hook may change `$gameRequest[0]` (type) or `$gameRequest[3]` (payload). Earlier alternative: `preprocessing.php` (:193), where core explicitly expects extensions to rename types (comment :195-196).
- **Short-circuit**: yes. Call `terminate();` (`lib/auditing.php:26`) or `die()`; core uses exactly that pattern a few lines later (:1132, :1147, :1152). After `terminate()` no LLM call, no `postrequest` hooks.
- **Handle custom request types from the game**: a hook can test `$gameRequest[0]`, do DB work, optionally echo a response line, and `terminate()` before `comm.php` sees an unknown type (INFERENCE: nothing in the loader prevents it; exact wire format of responses was not part of this topic).
- **Set state for later hooks** through `$GLOBALS` (e.g. `HERIKA_PERS`, `PROMPT_HEAD`). main.php runs at PHP global scope (entry point `HS/comm.php:7-8` does `$FUNCTIONS_ARE_ENABLED=false; require($path . "main.php");`), so its top-level variables such as `$FUNCTIONS_ARE_ENABLED` (`main.php:100`, `:1087`, `:1092`, `:1098`) are reachable as `$GLOBALS["FUNCTIONS_ARE_ENABLED"]` (main.php itself uses that spelling at :83).
- Cannot see: the built system prompt, LLM output, `talkedSoFar` (not produced yet).
- Runs for **every** request type including high-frequency non-LLM events (`request`, `infonpc`, `infonpc_close`, per comment `main.php:1119-1122`) - keep it cheap, exit early on irrelevant types.

Patterns worth copying: early feature-flag return; narrator guard; `function_exists` guards; callable-in-`$GLOBALS` extension points; `util.php` helper file required with a non-hook name. Patterns **not** to copy: `addslashes` for SQL (`util.php:9-10`), writing PHP conf files, relative `require_once("util.php")`, absolute `/var/www/html/...` requires (time_awareness).

---

## 5. Plugin configuration patterns

Where settings live in this codebase (VERIFIED):

| Store | Schema | API | Notes |
|---|---|---|---|
| `public.general_settings` | `id text PK, value text, description text, updated_at` | `chimGetGeneralSetting(string $id, string $default = ''): string` :907, `chimGetGeneralSettingBool` :919, `...Int` :927, `...Float` :935, `chimGetAllGeneralSettings(): array` :943, `chimSetGeneralSetting(string $id, $value, ?string $description = null): bool` :961 (`HS/lib/settings.php`) | all rows auto-exported to `$GLOBALS[id]`; ids with `@` become nested arrays (`chimAssignNestedGlobalValueToGlobals` :1041). Typed via `HS/conf/conf_schema.json`. Side effect: unknown row ids also appear in the per-profile override catalog (`chimGetOverrideableGeneralSettingsCatalog` merges `array_keys($rowMap)`, :525-529) |
| `public.conf_opts` | `id text PK, value text` | raw SQL (`SELECT value FROM conf_opts WHERE id='...'`) | runtime KV/state (live ids: `PLAYER_NAME` lookup in worker, `chim_mode`, `RECHAT_P`, `CHIM_GAME_LAST_ACTIVITY_TS`, `<npc>_is_rolemastered`, `Network/HOST_IP` ...). Used for the data-driven plugin registration `chim_character_facts_sources` |
| `public.prompts` | `prompt_key PK, default_prompt, custom_prompt, description, created_at, updated_at` | pattern `relationship_llm.php:105-131` | user-editable prompt text with shipped default + override |
| `conf/conf.php` + `conf/conf.sample.php` | PHP vars | imported by `chimRuntimeImportConfigVariables()` (only `/^[A-Z0-9_]+$/` names, `runtime_bootstrap.php:98-109`) | legacy; README still says "or add to conf/conf.php" |
| `plugins` Postgres schema | created at every bootstrap: `CREATE SCHEMA IF NOT EXISTS plugins` + `SET search_path TO public` (`runtime_bootstrap.php:134-148`) | plugin-owned tables, **must be schema-qualified** (`plugins.my_table`) because the connection forces `search_path public` (`postgresql.class.php:28`) | official recommendation per modders guide; live schema exists and is empty |
| `core_npc_master.extended_data` / `.metadata` (jsonb) | per-NPC | `NpcMaster::getExtendedData($currentNpcData): array` :1172, `getMetadata($currentNpcData): array` :1195, `updateByArray($data)` :571 | per-NPC plugin state that should **roll back with saves** (see 1.5 timeline) |
| files inside the plugin folder | any | - | ext tree is `dwemer:www-data`, mode `rwxrws---`/`rw-rw----` (writable by Apache). Survives `.dwpkg` updates only if listed in `mutable_paths`; **destroyed** by the GitHub installer update (section 6) and by "Delete Plugin" |

No bundled plugin ships a JSON config file; none uses `general_settings`/`conf_opts` for its own options except relationship_system (which is first-party and has its keys hard-wired into core UI lists: `settings.php:269-279`, `global_settings.php:102-103,158-161`, `prisma_settings_catalog.php:27,88-89`).

How UI pages are exposed:
- **Third-party route**: `manifest.json` field `config_url` -> "Plugin Page" button that does `window.open(config_url, '_blank')` in Server Plugins (`HS/ui/server_plugins.php:459`, `:505-506`; page reachable at `ui/core/config_hub.php?tab=serverplugins`). Without `config_url` the row shows "No Plugin Page". (`config_url_target` is mentioned by the online guide but is not read anywhere in this server build - grep.)
- **relationship_editor.php is not a generic mechanism**: it is hard-wired by core: `HS/ui/core/npc_master.php:3183-3189` does `if (file_exists(__DIR__."/../../ext/relationship_system/relationship_editor.php")) { $npcListRows = $data; include(...); $data = $npcListRows; }`; it expects the parent's `$editItem` (`relationship_editor.php:21-24`) and posts hidden fields consumed by `npc_save_handler.php` (included at `npc_master.php:584-585, 610-611, 705-706`). Its "Build with AI" button fetches `../../ext/relationship_system/analyze_relationships.php` (:850). Bulk build goes through `HS/ui/api/relationship_batch_build.php:14` -> `batch_build.php`. There is no plugin slot in the navbar or NPC modal (`grep ext/ HS/ui/tmpl` = no matches).
- **Standalone plugin pages/endpoints bootstrap themselves**:
  ```php
  // pattern: ext/relationship_system/analyze_relationships.php:24-40, batch_analyze.php:21-31
  $enginePath = __DIR__ . '/../../';
  $GLOBALS["ENGINE_PATH"] = $enginePath;
  require_once $enginePath . "lib/runtime_bootstrap.php";
  chimRuntimeBootstrap($enginePath, [
      'load_general_settings' => true, 'load_stt_connector' => false,
      'load_itt_connector' => false,   'load_player_name' => true,
  ]);
  ```
  Options understood (`runtime_bootstrap.php:151-185`): `run_db_updates` (default true), `load_general_settings` (default true), `load_stt_connector` (default true), `load_itt_connector` (default true), `load_tts_connector` (false|true|driver string), `load_player_name`, `load_narrator`. `chimRuntimeBootstrapIfNeeded()` :220 for files that may run inside or outside main.php. Full UI chrome pattern: `include ui/tmpl/head.html`, `ui/tmpl/navbar.php`, `ui/css/main.css`, `?embed=1` support (`server_plugins.php:21-31`). `$db` helper methods: `fetchOne`, `fetchAll`, `query`, `execQuery`, `insert`, `insertReturningId`, `update`, `updateRow`, `upsertRowOnConflict`, `delete`, `escape`, `escapeLiteral` (`HS/lib/postgresql.class.php:128-667`).

How a new plugin should ship editable default JSON config - recommendation grounded in the above:
1. Ship **read-only defaults** in the package, e.g. `ext/<plugin>/defaults/config.default.json` (always overwritten on update = desired).
2. Keep **user edits elsewhere**: preferred `plugins.<plugin>_config (key text PK, value jsonb)` created by `migrations/001_*.sql`; alternative a file `ext/<plugin>/conf/config.json` declared in manifest `server.mutable_paths` (works only for the `.dwpkg` channel - test `PluginPackageManagerTest::testServerActivationPreservesDeclaredMutableFiles` :127-147).
3. Load = deep-merge(defaults, user overrides) once per request in `globals.php`, publish as one `$GLOBALS["<PLUGIN>_CONF"]` array.
4. Provide `config_url` page with a JSON editor + "reset to defaults".
5. Avoid `general_settings` for plugin keys unless you want them exposed as per-profile overrides; avoid `conf/conf.php`.

---

## 6. Packaging

### 6.1 Runtime layout expectation (all channels)
```
HS/ext/<PluginName>/
  manifest.json            # needed to be listed in the plugin manager
  globals.php | preprocessing.php | prerequest.php | context_pre.php | context.php
  prepostrequest.php | postrequest.php | prompts.php | dialogue_prompt.php
  context_building.php | json_response_custom.php | functions.php      # all optional, matched by exact filename, any depth
  migrations/001_*.sql     # optional, run once each, alphabetical
  composer.json            # optional, GitHub installers run `/usr/bin/composer --no-ansi -v install`
  <anything else>          # pages, workers, lang/, defaults/
```
`manifest.json` fields actually read by `HS/ui/server_plugins.php:456-481`: `name`, `description`, `config_url`, `version`, `git_repo`, `mod_download_url` (token `<version>`), `channel`, `channels`, `default_channel`, `display_name`, `featured`, `icon`, `schema_version` (**must equal 2** for Update/Switch buttons, :507). Installer adds `channel`, `channel_label`, `git_repo` to the installed manifest (`server_plugin_installer.php:356-361`). Bundled examples (all three folders are filtered out of the table at `server_plugins.php:431`): `herika_heal/manifest.json` = `{"name":"herika_heal","description":"Example Plugin to show how CHIM API works. Does not actually work.","version":"1.0.0"}`, `time_awareness/manifest.json` (name/description/version `2.0.0`), `xLifeLink_plugin/manifest.json` (name/description only).

Uninstall = "Delete Plugin" button -> recursive delete of `ext/<folder>` (`server_plugins.php:409-417`). **There are no install / uninstall / upgrade PHP hooks in any channel.** Tables and `plugin_migrations` rows remain after delete, so migrations do not re-run on reinstall (`generic_installer.php:45-48`). Any setup beyond SQL must be done lazily at runtime (pattern: relationship_system creates its queue tables on first failure, `async_queue.php:107-111`).

### 6.2 `HS/ext/generic_installer.php` (legacy; no remaining caller - grep for `generic_installer` finds nothing)
- Inputs `$_GET["PACKAGE_NAME"]`, `$_GET["GITHUB_REPO"]` (:88-89). Downloads `https://github.com/<repo>/releases/latest/download/<PACKAGE_NAME>.tar.gz` then `.tar` (:91-92), extracts **over** `ext/<PACKAGE_NAME>` with `tar xvfz ... --strip-components=1` (:343-344).
- Version check: local `manifest.json` `version` (required, :238-240) vs GitHub `contents/manifest.json` (:250-267), `version_compare(... '>')` (:294).
- Migrations: `runDatabaseMigrations($targetDir)` (:137-197) - `migrations/*.sql`, sorted, tracked in **`plugin_migrations` in the public schema** (`plugin_name, migration_name, executed_at`, PK(plugin_name, migration_name)); whole file sent to one `pg_query`. DB creds hard-coded `dwemer/dwemer@localhost:5432/dwemer` (:97-104).
- Composer install if `composer.json` exists (:360-371).
- Release convention documented in header (:75-81): asset named `<PACKAGE_NAME>.tar.gz`, numeric tag, bump `manifest.json` version.

### 6.3 `HS/ui/server_plugin_installer.php` (current GitHub channel installer)
- Params `PLUGIN_ID`, `PACKAGE_NAME` (`/^[A-Za-z0-9_.-]+$/`), `GITHUB_REPO` (`owner/repo`), `CHANNEL`, `FORCE` (:381-385, :215-221). Repository catalogue `HS/ui/data/plugin_repository.json` (`plugins.<id>.{name, description, git_repo, github_url, mod_download_url, display_name, featured, icon, default_channel, channels{<id>:{label, branch, package_source: release|branch, package_urls[], package_url, manifest_url, archive_strip_components, allow_force}}}`); URL tokens `<package> <repo> <channel> <branch> <version>` (:86-93, :417-419).
- Install: download -> extract into staging `ext/.<pkg>-install-<uniq>` -> require root `manifest.json` -> **`chimPluginInstallerRemoveDirectory($targetDir)` then `rename(staging, target)`** (:363-369) -> migrations tracked in **`plugins.plugin_migrations`** (:232-276) -> composer (:278-296). Consequence: any user-edited file inside the plugin folder is lost on every update/channel switch.
- Listing in the built-in repository requires a PR to `Dwemer-Dynamics/HerikaServer` editing `ui/data/plugin_repository.json` (modders guide URL above; link shown in UI at `server_plugins.php:548`).
- Noted prior art in the shipped catalogue: entry `sharmat` = package `aiagent_nsfw`, repo `Wondernuttz/Sharmat-Alpha`, described as an adult-content framework for CHIM with consent-driven scenes for OStim and SexLab and relationship-aware behaviour (`plugin_repository.json`). Core already carries accommodations for it (`relationship_llm.php:1352-1355` mentions external "eligibility gates" matching on relationship `type`; featured-plugin styling in `server_plugins.php:93-201`). Not installed here. Overlaps the glue's intimacy feature - worth a separate review.

### 6.4 `HS/lib/plugin_package_manager.php` - `DwemerPluginPackageManager` (game-bundled server plugins)
Purpose (UI text, `server_plugins.php:231-238`): "Automatic Game Plugin Sync - CHIM transfers bundled server plugins automatically when a save is loaded. No manual upload is required."

Game side (VERIFIED strings in `F:\Modlists\LoreRim\mods\CHIM\SKSE\Plugins\AIAgent.dll`, source file name `Plugin\ServerPluginSync.cpp` @0x32c938): scans **`Data/CHIM/server-plugins`** (@0x32ce08), log tags `[SERVER_PLUGIN_SYNC]`: "Ignoring unsafe plugin folder name", "{} has multiple packages; using newest file {}", "Ignoring {} because package filename is not a valid version: {}", "Found {} bundled server plugin(s)", "Startup sync was incomplete; retrying once in 15 seconds"; talks to `ui/api/plugin_packages.php` `?action=` `probe` / `start-upload` / `upload-chunk&upload_id=...&index=...` with User-Agent `CHIM Server Plugin Sync/1.0`. **INFERENCE** from those strings + server checks ("name does not match its game-side plugin folder", "version does not match its game-side filename", `plugin_package_manager.php:96-101`): layout is `Data/CHIM/server-plugins/<PluginName>/<version>.dwpkg` (or `.zip`). No mod in the current LoreRim install ships this folder, and `HS/data/plugin_packages/` does not exist yet (never synced).

Server API `HS/ui/api/plugin_packages.php` (no authentication in the file): `POST ?action=probe {name, version}` -> `{ok, package:{name, requested_version, installed_version, upload_required, reason: current|version_changed|not_installed}}`; `POST ?action=start-upload {name, version, archive_name, size, total_chunks}` -> `{upload:{upload_id,next_index}}`; `POST ?action=upload-chunk&upload_id=&index=` raw body; `GET ?action=status&job_id=`; `GET ?action=packages`.

Archive contract (`plugin_package_manager.php`):
```
constants: SCHEMA_VERSION = 4; MAX_ENTRIES = 5000; MAX_UNCOMPRESSED_BYTES = 1 GiB;
           MAX_ARCHIVE_BYTES = 512 MiB; MAX_UPLOAD_CHUNK_BYTES = 1572864; extensions ['dwpkg','zip']   (:11-17)
zip root:
  manifest.json       # {"schema_version": 4, "name": "...", "version": "...", "server": {"mutable_paths": ["conf/config.json", ...]}, "description"?: "..."}
  checksums.sha256    # one line per file: <64 hex><space>[*]<path>; must cover EVERY file except itself; no extras (:390-421)
  server/...          # payload; becomes HS/ext/<name>/ ; at least one file required; NO other top-level payload allowed
                      #   (a 'game/...' entry is rejected: "Unsupported package payload", :343-350; test :74-82)
rules: >= 3 entries; no symlinks; no duplicate paths; no absolute/backslash/'.'/'..' paths (:306-324)
name:    /^[A-Za-z0-9][A-Za-z0-9 ._-]{0,63}$/, no trailing dot/space (:353-361)   -> folder name under ext/ (spaces allowed)
version: /^[0-9A-Za-z][0-9A-Za-z._+-]{0,63}$/ (:363-368)
```
Activation (`activateServerComponent`, :444-481): copy `mutable_paths` from old install into the staged payload (`preserveMutablePaths` :483-499) -> move old `ext/<name>` to `data/plugin_packages/backups/<job>/<name>` -> rename staged `server/` to `ext/<name>` -> `runMigrations()` (:501-538): `ext/<name>/migrations/*.sql` sorted, inside **one transaction**, tracked in `plugins.plugin_migrations(plugin_name, migration_name, executed_at)`; on any error the new folder is moved to `failed/` and the backup restored ("Server activation rolled back"). State recorded in `data/plugin_packages/packages/<sha256(lowercase name)>.json` with a per-file sha256 ledger. Same version => `upload_required=false` (no re-upload). Note the inner `server/manifest.json` is what the plugin-manager table reads after activation (test builds one at `PluginPackageManagerTest.php:197`); it should carry `name/description/version/config_url`.

Three migration ledgers exist: `public.plugin_migrations` (legacy generic_installer), `plugins.plugin_migrations` (both current installers). Live DB: neither table exists yet; schema `plugins` exists.

---

## 7. Language / lang overrides

Core mechanism (VERIFIED): setting `CORE_LANG` (`conf_schema.json:80`, select over folders in `HS/lang/`; present: `de es fr pl`, each with `command_prompt.php`, `functions.php`, `prompts.php`). Includes:
- `HS/functions/functions.php:1116-1120` requires `lang/<CORE_LANG>/functions.php`, which assigns **locals** `$F_TRANSLATIONS_LOCAL["Inspect"]="..."`, `$F_RETURNMESSAGES_LOCAL[...]`, `$F_NAMES_LOCAL[...]` (e.g. `lang/es/functions.php:5-40`); immediately afterwards core publishes `$GLOBALS["F_TRANSLATIONS"] = $F_TRANSLATIONS_LOCAL; $GLOBALS["F_RETURNMESSAGES"] = ...; $GLOBALS["F_NAMES"] = $F_NAMES_LOCAL;` (:1138-1140).
- `HS/prompts/prompts.php:279-281` requires `lang/<CORE_LANG>/prompts.php`, then plugin `prompts.php` files (:285). `HS/prompts/command_prompt.php:69-71` same for command prompt.

Plugin side:
- Plugin `functions.php` runs **after** the lang file (`functions.php:2754`), in function scope, so it writes straight to globals - reference example (entirely commented out in the shipped file, wrapped in `/*** ... ***/`) `HS/ext/herika_heal/functions.php:39-114`:
  ```php
  $GLOBALS["F_NAMES"]["ExtCmdHeal"]="Heal";
  $GLOBALS["F_TRANSLATIONS"]["ExtCmdHeal"]="Heals target using magic spell";
  $GLOBALS["FUNCTIONS"][] = ["name" => $GLOBALS["F_NAMES"]["ExtCmdHeal"], "description" => $GLOBALS["F_TRANSLATIONS"]["ExtCmdHeal"], "parameters" => [...]];
  $GLOBALS["ENABLED_FUNCTIONS"][]="ExtCmdHeal";
  $GLOBALS["PROMPTS"]["afterfunc"]["cue"]["ExtCmdHeal"]="...{$GLOBALS["TEMPLATE_DIALOG"]}";
  $GLOBALS["FUNCSERV"]["ExtCmdHeal"]=function() { global $gameRequest,$returnFunction,$db,$request; ... };
  $GLOBALS["FUNCRET"]["ExtCmdHeal"]=function($gameRequest) { ...; return ["argName"=>"target", "request"=>"...", "useFunctionsAgain"=>...]; };
  ```
  Naming rule from that file (:7-9): third-party function code names start with `ExtCmd` (result supplied by a Papyrus plugin via `funcret`) or `WebCmd` (result computed server-side). Comment "Can be overwrited by LANG" (:37, :41) - but because core lang files load *before* plugin functions and only fill `*_LOCAL` arrays, a core `lang/xx/functions.php` cannot override a plugin's names in this build; the plugin must localise itself (INFERENCE from load order :1116 vs :2754). Note newer builds also have a DB action catalogue (`herikaActionCatalogApplyRowsToRuntimeFunctions()`, `functions.php:2765`; table `core_action`) that can rename/disable actions - outside this topic.
- The only real plugin-localisation example is time_awareness: English strings are defined as local variables, then
  ```php
  // HS/ext/time_awareness/context.php:40-47
  $s_lang = strtolower(substr(($GLOBALS['CORE_LANG'] ?? ""), 0, 2));
  if ((strlen($s_lang) == 2) && ($s_lang != 'en')) {
      $ta_translation_file = __DIR__."/lang/".$s_lang."/ta_translation.php";
      if (file_exists($ta_translation_file)) { include($ta_translation_file); }
  }
  ```
  and `lang/<xx>/ta_translation.php` simply re-assigns the same variable names (`$sl_never_met`, `$sl_first_time`, `$sl_first_time_1h`, `$sl_hours_ago`, `$sl_days_ago`, `$sl_weeks_ago`, `$sl_months_ago`, `$sl_years_ago`), using the including scope's `$npc`, `$player`, `$inGameHours`, `$inGameDays` (`lang/es/ta_translation.php:4-11`). Plain `include` (not `_once`) so it works on repeated calls. Files shipped: `lang/{de,es,fr,pl}/ta_translation.php`.
- Plugin prompts: write `$GLOBALS["PROMPTS"]["<request_type>"] = ["cue"=>[...], "player_request"=>[...], "extra"=>[...]]` from `ext/<plugin>/prompts.php` (shape from `HS/prompts/prompts.php:255-276`); runs after the core lang override so the plugin wins.
- relationship_system has no localisation; its LLM prompts are user-editable through the `prompts` table instead.

---

## Implications for the glue (concrete recommendations)

1. **Folder name and load order**: pick a name that sorts after `relationship_system` if any hook wants to read/alter the relationship block in `HERIKA_PERS` (e.g. `zz_lorerim_glue` is ugly; `sks_glue` > `relationship_system` also works since `s` > `r`). Never name helper files `context.php`, `globals.php`, `functions.php`, `prompts.php` etc. anywhere in the tree.
2. **Write hook files for function scope**: use `$GLOBALS[...]` for everything except `$gameRequest`; guard every helper with `function_exists`; wrap bodies in `try { } catch (\Throwable $e) { error_log(...) }` - an uncaught error in a hook kills the player's turn.
3. **Hard gate for the intimacy action (server side)**: in `functions.php` (action registration) and again when the action result arrives, compute eligibility from `RelationshipManager::getPlayerRelationship($GLOBALS["HERIKA_NAME"])`: e.g. `aff >= threshold` AND `type in ('romantic','crush')` or a configurable list, plus adult check from NPC data (`core_npc_master.race/gender` and glue-side game data). Only add the action to `$GLOBALS["FUNCTIONS"]`/`ENABLED_FUNCTIONS` when the gate passes, so the LLM cannot select it otherwise. Because the relationship LLM never promotes to romantic types, decide explicitly how a Player romance starts: (a) player sets it in the NPC editor, (b) glue promotes via `setRelationship($npc,'Player',$aff,'romantic')` after an in-fiction milestone with its own stricter rule, or (c) gate on affinity tier alone. Make this a config choice; default to the most conservative.
4. **Writing relationship changes (quest help etc.)**: wrap in advisory lock `1001000000 + npc_id`, skip when `relationships_locked`, prefer small deltas consistent with the evaluator's scale (+5..+10 meaningful help, +15..+25 life-saving), and write a `note` so the conversation model sees *why* (`buildContext` prints `best/worst | note`). Use `adjustRelationship()` for the simple case; custom write through `chimRunWithRelationshipExtendedDataWrite()` + `chimRelationshipTimelineStamp()` when setting notes. Expect rollback on save-load - that is desirable for quest rewards.
5. **Per-NPC glue state that must follow saves** (consent given, scene history, quest-dialogue progress mirrors): store under a namespaced key in `core_npc_master.extended_data` (e.g. `glue`), written with plain `updateByArray` (relationship keys are auto-preserved). State that must NOT roll back (config, caches, OStim scene catalogue, dialogue-topic index) goes to `plugins.*` tables via `migrations/`.
6. **Scene awareness text**: use `chimRegisterPromptInjection('character_bottom', 'glue.scene', fn($slot,$ctx) => ..., 50)` from `context_pre.php` (or earlier) for state, and `prompt_bottom` for behavioural instructions; use the `$GLOBALS["request"]` prepend trick from `context.php` only for one-shot, turn-specific cues (e.g. "(the scene just advanced to <node>)").
7. **Custom game->server messages** (scene start/stop/node change, topic lists for menuless questing): handle in `preprocessing.php`/`prerequest.php` by `$gameRequest[0]`, persist, then `terminate()` so `comm.php` never sees unknown types; keep the hook O(1) for foreign types because it runs on every `request`/`infonpc` poll.
8. **Background LLM work** (e.g. classifying dialogue topics, summarising quest branches): copy the relationship pattern wholesale - UPSERT queue table keyed by subject, detached `worker.php --daemon` spawned with `proc_open` + `pcntl_fork` + pid file under `HS/log/`, `LLMConnector::readOne($id)` + `getConnector()` + `setOldGlobals()` save/restore + `fast_request($messages, ["MAX_TOKENS"=>N], "glue_call")`, results logged to `audit_request`. Let the user pick the connector id in the glue config (default: reuse `$GLOBALS['RELLLM_CONNECTOR']`). Re-read config each loop iteration to avoid the stale-settings issue.
9. **Make the relationship evaluator aware of glue facts** cheaply by registering `chim_character_facts_sources` (JSON in `conf_opts`) pointing at a **public-schema view/table** with a name column - schema-qualified names are stripped by the sanitizer.
10. **Config**: shipped `defaults/*.json` (read-only) + user overrides in `plugins.glue_config` (or `conf/*.json` declared in `server.mutable_paths`), merged in `globals.php`; a `config_url` page bootstrapped with `chimRuntimeBootstrap()`.
11. **Distribution**: the `.dwpkg` game-bundled channel fits a Wabbajack/MO2 modlist best - one MO2 mod containing ESL + scripts + `CHIM/server-plugins/<Name>/<version>.dwpkg`; AIAgent 3.3.2 uploads it on save load, migrations run transactionally, user config preserved through `mutable_paths`, no GitHub dependency. Build step must generate `checksums.sha256` covering every file and put a plugin-manager `manifest.json` (with `config_url`, `version`) inside `server/`. Keep an `ext/`-folder manual install as fallback. Bump the version for every change (same version = no re-upload).
12. **No lifecycle hooks**: do all non-SQL setup idempotently at runtime (`CREATE TABLE IF NOT EXISTS` fallbacks are acceptable, as relationship_system does), and document manual cleanup SQL for uninstall.
13. **Localisation**: keep all LLM-facing strings in one PHP array file and apply the time_awareness `lang/<xx>/` include pattern keyed on `substr($GLOBALS['CORE_LANG'],0,2)`; put long prompts in the `prompts` table (default + custom) if user editing is desired.

## Open questions

1. `.dwpkg` game-side layout is inferred from DLL strings and server error messages; confirm exact folder/filename rules (`Data/CHIM/server-plugins/<Name>/<version>.dwpkg`?), trigger timing ("when a save is loaded"), and whether MO2's virtual filesystem path is what the DLL scans. No sample package exists on this machine.
2. Does `rebaseRelationshipChange()`'s unconditional romantic-promotion block behave as read (needs an in-game test with a Fond+ NPC)? It conflicts with the evaluator prompt that invites `crush`/`romantic`.
3. What populates `$GLOBALS["BUFFER"]` (events fed to the relationship evaluator) - can the glue add an event line there, or should it insert into `eventlog` instead?
4. Wire format for replying to a custom request type handled in `prerequest.php` before `terminate()` (what AIAgent.dll expects back) - belongs to the game-protocol topic.
5. How the action catalogue (`core_action`, `herikaActionCatalogApplyRowsToRuntimeFunctions`, `Data/CHIM/*_actions.csv` import mentioned by the online guide) interacts with `ext/<plugin>/functions.php` registration; which one is preferred in 3.3.x for a hard-gated action.
6. `ui/api/plugin_packages.php` has no auth in-file; is the HerikaServer UI reachable beyond localhost in DwemerDistro (security posture for any glue endpoints)?
7. Worker lifecycle: `worker.php:20` says it is killed by a trap in `start.sh` (not located in this pass); confirm how a second plugin daemon should be stopped on distro shutdown.
8. SHARMAT (`aiagent_nsfw`) overlap: licence, maturity, and whether the glue should interoperate with or replace it; it appears to gate on relationship `type`.
9. Whether `RELATIONSHIP_SYSTEM_ENABLED` per-profile overrides are applied before `context_pre.php` for every NPC (assumed yes via profile override loading, not traced).
10. `wary` is assigned by `inferTypeFromAffinity()` but is not in `TYPES`; `canonicalizeRelationshipType('wary')` returns null unless already present as a custom type - harmless, but gating code should treat unknown types as non-romantic rather than error.

---

## Verification (adversarial pass)

Second agent, read-only. Every cited source was re-opened (UNC path to the WSL distro, `AIAgent.dll` string dump, read-only `SELECT`s through `psql`, WebFetch of the modders guide). `HS/` = `/var/www/html/HerikaServer/`. Result: 25 load-bearing claims checked - 23 confirmed as written, 2 confirmed in substance but with a wrong detail (worker fork, xLifeLink), plus 5 corrections to statements in the report body and 15 additions.

### Confirmed claims (brief)

1. Loader `requireFilesRecursively($dir,$name)` - exact text, only `global $gameRequest`, `require_once`, scandir order (`HS/lib/data_functions.php:7729-7747`).
2. All hook call sites and line numbers (`HS/main.php:54,193,1117,1124,2540,2648,2922-2926,2944-2946`; `prompts/prompts.php:285`; `prompts/dialogue_prompt.php:370`; `lib/data_functions.php:3217`; `functions/json_response.php:81`; `functions/functions.php:2754`).
3. Six relationship keys in `extended_data` (`lib/core/npc_master.class.php:603-610`), `relationships_locked`, literal `"Player"` key (`lib/relationship_manager.php:186-213`), queue DDL (`async_queue.php:349-375`); live `relationship_eval_queue` indeed lacks `last_error`, `relationship_init_queue` has it.
4. 11 tiers, thresholds exactly as quoted (`relationship_manager.php:405-417`); README 9-tier table stale (`README.md:153-165`).
5. `TYPES` has exactly 32 entries; `TYPE_ALIASES` exactly as quoted (`:48-66`); `Type/relation` rendering (`:784-788`).
6. Live `bio_templates`: 1293/1293 JSON-shaped; romantic=157, crush=32, ex=4; romantic `relation` husband 47, wife 43, lover 15, secret lover 9 (re-ran the SELECTs). `combined_bio_templates` is a VIEW (custom rows override base rows by `npc_name`).
7. `rebaseRelationshipChange()` unconditional romantic block + `$existingRels[$target] = $rebasedRel` (`relationship_llm.php:1708-1717`, `:1651`); first-stage gate `newAff < 56 || reason === ''` (`:1509-1522`). Scope caveat in correction C2.
8. postrequest gates and UPSERT (`postrequest.php:40,161,215-224,231,325-334`; `async_queue.php:87-101`; `relationship_manager.php:164-179`).
9. Worker spawn logic, pid file, pgrep fallback, `proc_open` descriptors, `usleep(200000)`, bootstrap list, batch sizes 10/5, 2 s interval, `REL_QUEUE_MAX_RETRIES` 3 - all exact. Wrong detail: see C3.
10. `initConnector()` / `makeSafeRequest()` / `logToAudit()` / `loadPrompt()` exact (`relationship_llm.php:105-131,185-207,224-250,262-288`); `fast_request($contextData, $customParms,$callName='')` at `connector/openrouterjson.php:1179` (google_openaijson lacks `$callName`, `:509`).
11. Live settings re-read: `RELATIONSHIP_SYSTEM_ENABLED=true`, `RELLLM_CONNECTOR=5` (label "Mistral Small 3.2 24B", driver `openrouterjson`, model `mistralai/mistral-small-3.2-24b-instruct`), `RELATIONSHIP_UPDATE_CHANCE=50`, `NEVER_CLEAR_RELATIONSHIP_DATA=false`, `core_npc_master` 0 rows, no worker process, no `log/relationship_worker.*`. `general_settings(id,value,description,updated_at)` confirmed; catalog default true (`prisma_settings_catalog.php:89`); availability map (`settings.php:58-70`); `chimLoadGeneralSettingsIntoGlobals()` (`:1138-1150`) -> `chimGeneralSettingsToLegacyGlobals()` (`:1017-1037`).
12. Read API signatures and default `['aff'=>0,'type'=>'neutral','tier'=>'Neutral']` (`relationship_manager.php:597-711`).
13. Write API (`:983-1035`): no lock, no `relationships_locked` check, no note handling - confirmed.
14. Guard `preserveRelationshipExtendedDataOnGenericUpdate()` and `chimRunWithRelationshipExtendedDataWrite($callback)` (`npc_master.class.php:363-378,554,583-628`).
15. Timeline stamp / restore / future-clear functions and call sites (`:180-275,282-359,1400-1595`).
16. Context injection lines (`context_pre.php:185-208`), heading builder (`relationship_manager.php:838-846`), `<character>` / `<general_instructions>` placement (`main.php:2597-2599`). Note the `COMMAND_PROMPT` append happens only when RELLLM is unset AND `COMMAND_PROMPT` is non-empty (`context_pre.php:203-209`).
17. time_awareness prepend to `$GLOBALS["request"]`, `if (false && ...)` (`ext/time_awareness/context.php:106-113`; the report's code-block header says 104-113, the block is 106-113).
18. Prompt registry signatures exact (`lib/prompt_injections.php:10,59,87,107`); only slots `character_bottom` and `prompt_bottom` are rendered anywhere (`main.php:2554,2565` are the only `chimRenderPromptInjections` callers); guide lists the same two functions and slots.
19. xLifeLink inert in behaviour; `CustomUpdateProfileFunction` consumer at `processor/comm.php:2347-2348`; `terminate()` exact (`lib/auditing.php:26-48`). Wrong detail: see C4.
20. `chim_character_facts_sources` mechanism and sanitizer (`relationship_llm.php:138-184`, uses at `:349,892,1126-1127`); row absent live.
21. Plugin manager manifest fields, hidden folders, delete = `rrmdir` (`ui/server_plugins.php:409-417,431,454-540`); editor hard-wired (`ui/core/npc_master.php:584-585,610-611,705-706,3183-3186`).
22. GitHub installer wipe-then-rename and `plugins.plugin_migrations` (`ui/server_plugin_installer.php:232-276,319-379`).
23. `DwemerPluginPackageManager` constants, manifest rules (strict integer `schema_version === 4`), `server/`-only payload, checksum coverage, mutable paths, backup/rollback, single-transaction migrations (`lib/plugin_package_manager.php:11-17,242,266-276,326-351,390-421,444-538`); API actions (`ui/api/plugin_packages.php:27-62`); tests (`PluginPackageManagerTest.php:74,110,120,127,149`).
24. DLL strings re-dumped: `D:\wt\chim-332-main-voice-hotfix\Plugin\ServerPluginSync.cpp` @0x32c938, `ui/api/plugin_packages.php` @0x32cac0, `Data/CHIM/server-plugins` @0x32ce08, `probe`/`start-upload`/`upload-chunk&upload_id=`/`&index=`, UTF-16 `CHIM Server Plugin Sync/1.0` @0x32caf0. No mod folder under `F:\Modlists\LoreRim\mods\*` contains a `CHIM` data directory at all; `HS/data/plugin_packages` absent.
25. Localisation pattern (`ext/time_awareness/context.php:31-47`, `lang/es/ta_translation.php:4-11`), core lang load order (`functions/functions.php:1116-1120,1138-1140,2754`), herika_heal body entirely inside `/*** ... ***/`.

### Corrections

**C1 - "NPC->Player starts absent (= neutral 0)" (section 1.2) is wrong for roughly 60-100 named NPCs.**
Live `public.bio_templates` (read-only SELECT, this pass): 57 rows carry a literal `"Player"` key in `relationships`; further keys `Dragonborn` (24 rows), `The Dragonborn` (10), `Player Character` (6) are folded into `"Player"` on every read by `normalizeTargetName()` (`relationship_manager.php:192-210`, applied in `normalizeRelationshipMap()` `:288`). Type histogram of the 57 literal `Player` entries: grateful 18 (aff 45..85), enemy 8, professional 5, betrayed 4, admirer 3 (40..55), plus one each of **romantic (aff 55)**, **obsessed (aff 65)**, protective, mentor, etc. So a freshly created NPC can already be `romantic`/`obsessed`/`admirer` toward the Player, and can already be at Fond+ affinity, purely from the template seed (`npc_master.class.php:718-735`). The glue's gate must not assume "romantic toward Player implies the user or the glue set it".

**C2 - Executive summary item 5 over-generalises the sources of romantic types.**
The unconditional block is confirmed for the dynamic evaluator only (`applyChanges` -> `rebaseRelationshipChange`). Other LLM-driven writers have no such gate: (a) "Build with AI" `ext/relationship_system/analyze_relationships.php:277` lists `romantic` in `$validTypes` and `:291` accepts any `/^[a-z]+$/` type; its output is returned to the editor form and saved by the user; (b) Mode 2 `#TYPE:Name=Romantic#` (`relationship_manager.php:939-955`) has no romance gate (the report says so in 1.5 but not in the summary); (c) template seeds (C1). Net: "romantic" in the DB means "template, user, Build-with-AI, Mode-2 model, or a plugin" - not "player-set".

**C3 - The worker is not double-forked.**
`worker.php` calls `pcntl_fork()` once (`:45`), the parent writes the pid and exits (`:49-53`), the child calls `posix_setsid()` (`:57`) and reopens stdio. There is no second fork. Detachment = `proc_open`+`proc_close` by Apache, one fork, `setsid`. (Executive summary item 6 says "double-forked"; key claim says "double-detaches".) If `pcntl_fork` is unavailable the script silently runs undetached in the foreground (`:43` guard).

**C4 - xLifeLink `prerequest.php` does not start with the feature guard.**
Line 2 is `require_once("util.php");`, the guard is at `:4-6`. So `util.php` is loaded on every `main.php` request and defines these global functions: `getJSONPersonality`, `updatePhpVariable`, `buildPersonalityLine`, `buildPersonalityLineList`, `buildPersonality`, `parseUpdate`, `getCurrentRelationships` (`ext/xLifeLink_plugin/util.php:8,25,38,42,56,66,154`). The glue must not declare functions with these names. Inertness also depends on `conf.sample.php` being loaded before `conf.php` by `chimRuntimeBootstrap()` (`lib/runtime_bootstrap.php:194-203`); `conf/conf.php` itself contains no `FEATURES` key (grep).

**C5 - `$GLOBALS["FUNCRET"]` (section 7 code block) is dead in this build.**
Grep for `FUNCRET` over the whole server finds only `ext/herika_heal/functions.php:101` (inside the comment). Live consumers are only: `$PROMPTS["afterfunc"]["cue"][$functionCodeName]` (`processor/request.php:14-18`, fallback key `default`) and `$GLOBALS["FUNCSERV"][$functionCodeName]` called with no arguments on a `funcret` request (`processor/request.php:34-35`). `funcret` payload format is `command@<function codename>@<parameter>@<result>` (`request.php:10-11,22`). The Papyrus names inside that example comment are also stale: `SPG_CommandReceived` / `SPGPapFunctions.requestMessage` do not exist in AIAgent 3.3.2 sources; the shipped names are ModEvent `CHIM_CommandReceived` (`F:\Modlists\LoreRim\mods\CHIM\Source\Scripts\AIAgentAIMind.psc:1397`), `CHIM_CommandReceivedInternal` (`:1384`; registered by `AIAgentPapyrusFunctions.psc:1096`) and `int function requestMessage(String a_msg,String type) Global Native` (`AIAgentFunctions.psc:29`), `requestMessageForActor(String a_msg,String type,String npc)` (`:30`). Do not copy names from `herika_heal`.

**C6 - Section 0 "trap" example is slightly misdescribed.**
`relationship_system/postrequest.php` resolves the NPC name first from `$GLOBALS["CHIM_CORE_CURRENT_NPC_DATA"]["npc_name"]` (`:143-145`); only the middle branch `isset($currentNpcData["npc_name"])` (`:146`) is dead in hook scope. It does not normally fall through to `HERIKA_NAME`. `$GLOBALS["CHIM_CORE_CURRENT_NPC_DATA"]` and `$GLOBALS["HERIKA_ID"]` (`:199`) are the reliable per-request NPC handles for glue hooks.

**C7 - "extended_data rolls back with saves" (section 5 table, implication 5) needs three qualifications.**
(a) `restoreNPC()` replaces whole rows only `WHERE COALESCE(lock_profile,0)=0 AND COALESCE(gamets_last_updated,0)>0` (`npc_master.class.php:1414-1416`); for locked profiles only the six relationship keys are rolled back (`chimRelationshipRestoreQuery`, all non-Narrator rows), so plugin keys on `lock_profile=1` NPCs do NOT roll back. (b) With `NEVER_CLEAR_RELATIONSHIP_DATA=false`, unlocked rows that have no eligible history snapshot are deleted and not re-inserted (DELETE `:1413-1427` + inner `JOIN LATERAL` `:1482-1492`) - plugin state on NPCs first met after the loaded save disappears with the row. (c) Snapshots are only written by `backupAllNpcs($gameRequest[2])` on the game's `infosave` request (`processor/comm.php:1331-1337`) and by `backupNpcById($id,'relationship')`; a plain plugin write creates none, so rollback granularity is "last game save / last relationship change", which is the desired behaviour but worth knowing. `restoreNPC($gameRequest[2])` runs on the `init` request (`processor/comm.php:104,218-221`).

Minor: grep for `generic_installer` is not empty (hits in `ui/css/main.css` and `.gitignore`), but no PHP caller exists - conclusion stands. `normalizeTargetName()` alias list in section 2.1 is abridged; full list: `player`, `the player`, `player character`, `the player character`, `dragonborn`, `the dragonborn`, `#player_name#`, `{player_name}`, plus lower-cased `PLAYER_NAME`. Mode-2 prompt text still advertises the stale 9-tier names (`relationship_manager.php:1044`).

### Additions (facts the report missed that matter for the glue)

A1. **Atomic per-key writers exist** - prefer them over read-modify-write `updateByArray` for plugin state:
```php
// HS/lib/core/npc_master.class.php
public function updateExtendedKeysByName(string $npcName, array $setValues = [], array $unsetKeys = []): bool   // :1271
public function updateMetadataKeysByName(string $npcName, array $setValues = [], array $unsetKeys = []): bool   // :1207
```
They build one `UPDATE ... SET extended_data = jsonb_set(...)` (null value = unset key), so they cannot clobber concurrent worker writes to other keys. They bypass the relationship guard (raw SQL) - never pass the six relationship keys through them.

A2. **Save/load lifecycle is visible to plugins**: request type `init` = game load (`processor/comm.php:104`; core deletes `memory`/`memory_summary` rows with `gamets > $gameRequest[2]` `:159-160`, runs `restoreNPC` `:221`, and hard-codes `DELETE FROM relationship_eval_queue/relationship_init_queue` `:224-232`); request type starting with `infosave` = game save (`:1331`). A glue `prerequest.php` runs before `comm.php` (`main.php:1117` vs `:1124`), so it can watch `$gameRequest[0]` for `init`/`infosave` to clear its own queues and roll back gamets-stamped `plugins.*` rows. Wire format of every request: base64 in the query string -> `type|localts|gamets|payload` (`main.php:90-93,135`).

A3. **Plugin `functions.php` details needed for a hard-gated action**: it is pulled in unconditionally via `HS/prompt.includes.php:55` from `main.php:1615` - after `prerequest.php`/`comm.php`, before `context_pre.php`; plugin `prompts.php` loads there too (`prompt.includes.php:19`). `requireFunctionFilesRecursively($dir)` (`functions/functions.php:2688-2704`) declares NO `global $gameRequest` - use `$GLOBALS["gameRequest"]`. `prompt.includes.php:8-15` rewrites `ginputtext`->`inputtext` and `ginputtext_s`->`inputtext_s` (sets `$GLOBALS["OVERRIDE_DIALOGUE_TARGET"]`), and `main.php:1616` lowercases the type. Immediately after the plugin scan, `herikaActionCatalogApplyRowsToRuntimeFunctions()` (`functions.php:2765`; body `lib/core/action_catalog.php:2876-2962`) rebuilds `$GLOBALS["FUNCTIONS"]`: a plugin entry survives only if `getFunctionCodeName($entry['name'])` resolves through `$GLOBALS["F_NAMES"]` (`:2892-2895`), DB rows (view `public.combined_core_action`, tables `core_action`, `core_action_custom`) override name/description/parameters for the same code name and entries are de-duplicated by display name (`:2932-2961`). Therefore always set `$GLOBALS["F_NAMES"]["ExtCmd..."]` and pick a display name not used by any catalogue row.

A4. **`chimRegisterPromptInjection` gotchas** (`lib/prompt_injections.php`): slot and id are normalised to lower-case `[a-z0-9_.:-]` (`:3-8`); content is executed if `is_callable($content)` (`:36-38`) - a one-word string that equals a PHP function name (e.g. `"date"`, `"system"`) would be CALLED with `($slot,$context)`. Always pass a Closure or an array of lines, never a bare user/LLM-derived string. Identical rendered texts are de-duplicated (`:84`).

A5. **Actor profile enrichers** run inside `DataLastInfoFor()` (called at `main.php:2068`), only when prompt-context option `enabled_nearby_actor_subsections` / `custom_state` is on (`lib/data_functions.php:1002,1084,1226`). Register them in `globals.php`/`preprocessing.php`/`prerequest.php` (context_pre.php at :2540 is too late). Context passed: player -> `["source"=>"nearby_actors"]`; NPC -> `source`, `metadata`, `npc_data` (`:1085-1087,1227-1231`). Returned lines are joined with `". "`.

A6. `extensionCharacterFacts()` caches the `conf_opts` row in `static $sources` (`relationship_llm.php:146-154`). In the long-running worker this means a newly registered/changed `chim_character_facts_sources` is ignored until the worker restarts (it respawns on the next LLM request after the process in `log/relationship_worker.pid` exits).

A7. `RelationshipManager::setRelationship()` returns `true` even when the UPDATE failed (`:1012-1025`); it returns `false` only for an unresolvable NPC. `resolveNpcByName()` returns null for `The Narrator`. Check the DB yourself if the write is load-bearing.

A8. `LLMConnector::getConnector()` has side effects: `$GLOBALS["PATCH_PROMPT_ENFORCE_ACTIONS"] = false; $GLOBALS["COMMAND_PROMPT_ENFORCE_ACTIONS"] = "";` (`lib/core/llm_connector.class.php:435-436`). `setOldGlobals()` only writes `$GLOBALS["CONNECTOR"][<driver>][...]` (`:260-422`), so saving/restoring `$GLOBALS["CONNECTOR"]` is sufficient if the glue ever calls an LLM inside a web request.

A9. The relationship daemon appends two lines to `log/relationship_worker.log` on every loop iteration, i.e. every 2 s while idle (`worker.php:167,171`) - do not copy that into a glue worker. The daemon bootstraps from `conf/conf.php` only (no `conf.sample.php`, no hooks, no `chimRuntimeBootstrap`), so plugin globals defined in `globals.php` do not exist inside it.

A10. **Game-side package rules (from DLL strings, this pass)**: folder-name regex `^[A-Za-z0-9][A-Za-z0-9 ._-]{0,63}$` @0x32c8e8 and version regex `^[0-9A-Za-z][0-9A-Za-z._+-]{0,63}$` @0x32c910 (identical to the server's), newest file chosen via `directory_entry::last_write_time` @0x32c8c0, precondition "Configured server path does not contain comm.php" @0x32ca70, JSON keys `name`/`version`/`archive_name`/`size`/`total_chunks`/`upload_required`/`upload_id`/`complete`. Trigger: DLL label `startup` @0x32d3b8 and message "Startup sync was incomplete; retrying once in 15 seconds"; the modders guide says "When Skyrim starts, CHIM uploads and installs the package only when the connected server needs that version" and "Place the package under `Data/CHIM/server-plugins/<plugin-name>`. Use a versioned `.zip` or `.dwpkg` package" - this contradicts the server UI text "when a save is loaded" (`ui/server_plugins.php:234`). The literal `dwpkg` does not occur in the DLL (ASCII or UTF-16), so game-side extension filtering is UNVERIFIED; the server enforces the extension on `archive_name` (`plugin_package_manager.php:136-139`). Open question 1 stays open for the exact `<version>.<ext>` filename rule.

A11. GitHub-installer staging folders are created INSIDE `ext/` as `.<pkg>-install-<uniq>` (`ui/server_plugin_installer.php:326`) and the hook loader does not skip dot-folders (`data_functions.php:7735`). During an install/update window a game request can load the same hook file from staging and from the live folder -> "Cannot redeclare" fatal unless every function is `function_exists`-guarded. The `.dwpkg` channel stages under `data/plugin_packages/staging` and is not affected.

A12. Plugin-manager buttons: "Update"/"Switch" need `config_url` non-empty AND `schema_version == 2` AND `git_repo`; "Download Skyrim Modfile" (`mod_download_url`) also only renders when `config_url` is set (`ui/server_plugins.php:505-521`). The installer is called with the manifest `name` as `PACKAGE_NAME`, so for the GitHub channel `name` must equal the ext folder name and match `/^[A-Za-z0-9_.-]+$/`.

A13. `.dwpkg` migrations run on a separate `pg_connect` inside one `BEGIN/COMMIT` (`plugin_package_manager.php:513-531`): migration files must not contain their own transaction control or `CREATE INDEX CONCURRENTLY`, and must schema-qualify (`plugins.`) because that connection does not force `search_path`. The main `sql` class shares one static connection per PHP process (`lib/postgresql.class.php:6,14`) with `SET search_path TO public` (`:28`); advisory locks taken on it are session-level.

A14. `normalizeNpcDataForPersistence()` converts a `relationships`/`npc_relationships` key in any `create()/update()` payload into `extended_data.relationships` and, if the payload has no `extended_data`, builds a fresh `extended_data` containing only that key (`npc_master.class.php:718-738`). Never include a `relationships` key in glue `updateByArray` payloads.

A15. SHARMAT evidence in core: the evaluator prompt comment says earlier wording meant "the sex-eligibility gates never matched" (`relationship_llm.php:1352-1355`), i.e. an existing third-party plugin already gates on `type == 'romantic'`-style values. Combined with C1/C2, a type-only gate is weak; combine type + affinity tier + glue-side consent state + adult check.

### Confidence

High for server-side names, signatures and line numbers (all re-read in source; live DB facts re-queried). Medium for the game-bundled `.dwpkg` channel: server contract verified in code and tests, game-side behaviour only from DLL strings and one guide sentence, never observed running.
