# OStim scene data format + statistical index of the installed scene packs (OStim 7.5.1, OARE 1.52.1, OStim Community Resource 1.17.6)

Phase 0 research, read-only. Companion files in this folder: `ostim_scene_stats.py` (indexer), `ostim_scene_stats.json` (full index: 607 scenes, 1629 edges, per-scene records, graph stats).

Legend: **[V]** verified in a local file (path:line given) - **[V-src]** verified in the upstream OStim source on GitHub, branch `main` (URL given; INFERENCE that it matches the installed 7.5.1 DLL) - **[I]** inference - **[S]** computed by `ostim_scene_stats.py` from the installed files.

Path shorthands used below:
- `OSTIM` = `F:\Modlists\LoreRim\mods\OStim Standalone - Advanced Adult Animation Framework`
- `OARE` = `F:\Modlists\LoreRim\mods\Open Animations Romance and Erotica for OStim Standalone`
- `OCR` = `F:\Modlists\LoreRim\mods\OStim Community Resource`
- `SRC` = `https://raw.githubusercontent.com/VersuchDrei/OStimNG/main/skse/src`

## Executive summary (10 lines)

1. A scene is one JSON file anywhere under `Data\SKSE\Plugins\OStim\scenes`; the **scene id is the file name without `.json`** (folders ignored, ids matched case-insensitively). Only three enabled mods ship OStim data: OStim (280 scenes), OARE (287), OCR (40) = **607 scenes, 0 duplicates, 0 parse errors, 0 dangling navigation references**.
2. A scene with a scene-level `"destination"` is a **transition**: played once for `length` seconds, then the thread moves to the destination; its `navigations` list is ignored. 114 of 607 scenes are transitions (OStim 31, OARE 47, OCR 36); 63 of them also carry actions (e.g. OARE's one-shot kiss returns to its hub after 5 s).
3. Edges are declared inside `navigations[]` either as `{"destination": X}` (this -> X, 1475 uses) or `{"origin": X}` (X -> this, 41 uses, all of them OARE hooking itself into OStim idles with the label "Access Open Animations RE..."). Scene-level `origin` on a transition is supported but unused by every installed pack.
4. OStim buckets scenes by (furniture master type, actor count); navigation requires equal actor count and furniture types in a sub/supertype relation. The big bucket is `none|2`: 434 scenes, one giant strongly connected component of 381 scenes. OARE <-> OStim are connected both ways through hub idles (41 edges in, 19 back).
5. The OStim native API already path-finds: `OThread.NavigateTo(ThreadID, SceneID)` runs a BFS (`Node::getRoute`) limited by MCM `NavigationDistanceMax` (default 5, range 0-20) and **warps if no route** - but that BFS knows nothing about content tiers, so the glue still needs its own constrained path-finder when it must stay non-explicit.
6. Content split (action-definition tags): 231 scenes contain a `sexual` action, 67 only `sensual`/`romantic` ones, 86 only untagged actions, 223 no actions. Glue-side tiers (script convention): neutral 207, affection 76, kissing 33, sensual 60, sexual 231.
7. 109 two-actor scenes are affection/kissing tier (OStim 24, OARE 57, OCR 28). Every standing kiss/hug scene can reach every cuddle scene **and come back** using only declared edges, even when intermediate nodes are restricted to neutral/affection/kissing scenes (216/216 pairs both directions; 156/216 when action-less "staging" idles of explicit branches are also excluded - only the from-behind hug/cuddle scenes drop out).
8. Metadata is incomplete for romance: 20+ OARE cuddle/embrace/caress scenes declare **no action at all** (only the OARE scene tag `cuddling` or the id says what they are); OCR's kiss/hug/court scenes declare only an undress action. An index must combine actions + scene tags + id/name tokens.
9. OARE is pure data (scenes, 1566 hkx + 5 FNIS behaviours, 254 icons, one `SKSE\Plugins\Devour\OARE.json`); no plugin, no scripts, no MCM. Its LICENSE forbids reusing the animations in other packs/frameworks and forbids modifying icons or editing scene JSON to use other icons; referencing scene ids from another mod is not restricted by its text.
10. OCR ships 40 sequences (`sequences\*.json`) that wrap its one-shot interaction scenes (kiss, hug, hold hands, court, chatter, conversation loop) and a working Papyrus starter (`OCR_OStimUtil.StartOstimSequence`) - a ready-made template for the glue's non-explicit "start intimacy" entry point.

---

## 1. OStim scene JSON schema

### 1.1 Where definitions live [V]

`OSTIM\SKSE\Plugins\OStim\` contains (file counts): `scenes` 280, `actions` 86, `action tags` 23, `furniture types` 17, `actor properties` 7, `equip objects` 19, `events` 10, `facial expressions` 80, `voice sets` 3, `options` 2, plus documentation: `scenes README.txt`, `actions README.txt`, `furniture types README.txt`, `actor properties README.txt`, `sequences README.txt`, `events README.txt`, `settings README.txt`, `list of commonly used scene tags.txt`, `list of commonly used actor tags.txt`, `list of mod events.txt`, `list of annotations.txt`, `list of API forms.txt`, `API version README.txt`.
OCR adds `actions` 27, `facial expressions` 19, `scenes` 40, `sequences` 40. OARE adds `scenes` 287 only. No other mod in `F:\Modlists\LoreRim\mods` has an `SKSE\Plugins\OStim` folder, and `overwrite` has none. All three are enabled in `profiles\Ultra\modlist.txt` (lines 4-6).

Translations for `$keys`: `OSTIM\Interface\translations\OScenes_ENGLISH.txt` (scene names), `ONav_ENGLISH.txt` (navigation labels), UTF-16LE, `key<TAB>text`. Parameterised labels: key `$ostim_nav_hold_t{}` with text containing `{{}}`; a scene writes `$ostim_nav_hold_t{1}` and OStim later replaces `{1}` with the name of the actor at index 1 (`ONav_ENGLISH.txt` header comment lines 1-12).

### 1.2 Scene id rules [V] `OSTIM\SKSE\Plugins\OStim\scenes README.txt:3-11`

- "The sceneID ... is the scenes filename without the .json extension." Subfolders are parsed but "folder names are not part of the sceneID".
- Same file name twice = one overwrites the other in unpredictable order. Folder/file names must not start with `OStim` for third parties (integrity check).
- [V-src] ids are stored lower-cased and looked up lower-cased, i.e. **case-insensitive**: `node->lowercase_id = node->scene_id = filename; StringUtil::toLower(&node->lowercase_id);` (`SRC/Graph/GraphTable/GraphTableSetupNodes.cpp`) and `Node* GraphTable::getNodeById(std::string id) { StringUtil::toLower(&id); auto iter = nodes.find(id); ...` (`SRC/Graph/GraphTable/GraphTableNodes.cpp`). [S] 6 references in the installed data differ in letter case from the file name (one OStim file is literally named `Ostim2PStandingKneelingJerkToFaceMF.json`).
- [V-src] Files are parsed with strict nlohmann JSON (`json::parse(ifs, nullptr, false)`; malformed -> `"file {} is malformed"` and skipped) - `SRC/Util/JsonFileLoader.h`. No comments / trailing commas allowed. [S] all 607 installed files are strict JSON.
- [V-src] **JSON keys are case-sensitive** (`json.contains("modpack")`). OARE, OCR and 22 OStim files spell it `"modPack"`, so OStim never reads their modpack string (cosmetic only) [I]. One actor uses `"lookleft"` (ignored). All other keys in all packs use the README spelling.

### 1.3 Scene-level fields [V] `scenes README.txt:15-38`; observed counts [S] over 607 files

| key | type | default | files using it | notes |
|---|---|---|---|---|
| `name` | $string | `$<sceneID>` lookup [V-src] | 607 | display name; OStim uses `$OStim2P...` keys, OARE/OCR plain English |
| `modpack` | string | - | 258 (`modpack`) + 349 (`modPack`, ignored by loader) | display only |
| `length` | float seconds | warning if missing | 607 | loop length; for transitions = play time. min 0.75 / mean 2.6 / max 17.5 |
| `destination` | string sceneID | - | 114 | presence makes the scene a **transition**; `navigations` then ignored |
| `origin` | string sceneID | - | 0 | transition only: adds edge origin -> this; may then also carry `priority`, `description`, `icon`, `border`, `noWarnings` at scene level |
| `navigations` | list of objects | [] | 576 | see 1.4 |
| `speeds` | list of objects | - | 607 (1 file has an empty list: `OARE_ActionFemaleCaressHoldHands`) | see 1.5 |
| `defaultSpeed` | int index | 0 | 289 | |
| `noRandomSelection` | bool | false | 329 present, 43 true (OCR 40, OARE 3 `OARE_DevourDrained*`) | excluded from random picks / auto mode |
| `furniture` | string furniture type | `none` | 133 | see 1.7 |
| `offset` | {x,y,z,r} | - | 40 (the OStim bed scenes) | |
| `scaleOffsetWithFurniture` | bool | - | 40 | **not in the README**; appears together with `offset` in bed scenes (`...\OStimDoubleBedLeft2P\MF\idle\OStimDoubleBedLeft2PBothSittingMF.json:49-54`) |
| `tags` | list of strings | [] | 460 | lower-cased by loader; see 2.4 |
| `autoTransitions` | map type -> sceneID | {} | 287 present, all empty | scene-level auto transitions unused; actor-level ones are used |
| `actors` | list of objects | - | 607 | see 1.6 |
| `actions` | list of objects | [] | 478 | see 1.8 |
| `fadeOnEntry` | bool | - | 0 | [V-src] read by the loader (`json.contains("fadeOnEntry")`), not in README, unused by installed packs |

### 1.4 Navigation fields [V] `scenes README.txt:40-72`

| key | type | default | uses [S] | meaning |
|---|---|---|---|---|
| `destination` | sceneID | - | 1475 | adds option **this -> destination** ("preferred way ... within an animation pack") |
| `origin` | sceneID | - | 41 | adds option **origin -> this** to a scene of another pack without overwriting it; "never use origin and destination at the same time" |
| `priority` | int | 0 | 1184 | ascending sort of menu options. Conventions in idles: 0 other idles, 1000 romantic, 1500 vampire bites, 2000 undressing, 2500 bathing, 3000 sexual. In other scenes: -1000 return to idle, 0 detail change, 1000 positional change, 2000 action change, 3000 climax |
| `description` | $string | destination scene id [V-src] | 1516 | menu label; 1515 of 1629 unique edges have one, 826 contain `{0}`/`{1}` actor-name placeholders [S] |
| `icon` | string | auto | 1515 | path under `Interface/OStim/icons`, `.dds` appended |
| `border` | hex RGB | `ffffff` | 295 | OARE colour-codes categories: `fce5cd` neutral, `dd7e5e` caressing, `e05a4e` erotic (`OARE\...\Standing_apart\OARE_Standing.json:46,79,152`) |
| `noWarnings` | bool | false | 555 | suppress "doesn't exist" log lines for optional packs |

The `priority` convention is a usable machine signal: on an idle hub, options with priority ~1000 are romantic, ~2000 undressing, ~3000 sexual (OStim follows it; OARE uses 1-4 positional, 1000-1008 caress, 3000+ erotic, 4000+ undressing, 5000+ vampire - `OARE_Standing.json:42-217`).

### 1.5 Speed fields [V] `scenes README.txt:74-79`

`animation` (string, 1351 uses; the animation event sent to actor i is `<animation>_<i>`), `playbackSpeed` (float, default 1.0, 812), `displaySpeed` (float, 1084). [S] 370 scenes have 1 speed, 236 have 3-5 speeds (mostly tier-4 scenes), 1 has 0.

### 1.6 Actor fields [V] `scenes README.txt:81-119`; counts [S] over 1229 actor records

| key | uses | meaning |
|---|---|---|
| `type` | 690 | actor type, default `npc` (only `npc` occurs) |
| `intendedSex` | 1229 | `male` / `female` / anything else = any (default any). OStim scenes use male/female; OARE mostly `any` |
| `tags` | 1229 | posture tags, see 2.5 |
| `feetOnGround` | 1225 | heel-offset handling; defaults true for `standing`/`squatting` |
| `scale` 901, `scaleHeight` 670, `sosBend` 503 | | body scaling / SoS bend (-9..9, -10 flaccid) |
| `lookUp` 621, `lookLeft` 210, `lookRight` 4, `lookDown` 0 | | eye direction mfg |
| `autoTransitions` | 568 present, 10 non-empty | map type -> sceneID. Used keys: `climax` (6), `devour0` (3), `devour1` (1) |
| `expressionAction` 8, `noStrip` 2, `animationIndex` 1, `singleSpeed` 1, `requirements` 1 | | `requirements` example: `inwater` on `OStim1PBathingHubStandingM` |
| `underlyingExpression`, `expressionOverride`, `offset` | 0 | documented, unused |

Actor index semantics [V] `actions README.txt:126-135`: action `actor`/`target` are designed "around consensual vanilla sex"; in all OStim MF scenes index 0 is the male-intended slot and index 1 the female-intended slot [S] (208 `MF`, 0 `FM`).

### 1.7 Furniture types [V] `furniture types README.txt:1-25`, files in `OSTIM\SKSE\Plugins\OStim\furniture types\`

- Hierarchy through `"supertype"`; "scenes on benches will also play chair animations"; a navigation from a chair scene to a bench scene "will only show up if the scene is playing on a bench" (README lines 3-4). `"supertype": "none"` means the no-furniture scenes are the supertype (`bed.json:3`).
- Fields: `name`, `priority`, `listIndividually`, `ignoreMarkerOffsetX/Y/Z`, `offsetX/Y/Z`, `rotation`, `multiply scale`, `offsetX/Y/ZGlobal`, `supertype`, `conditions[]` (`anykeyword`, `keywordblacklist`, `formlist`, `formblacklist`, `markercount`, `cellname`). Files additionally contain an undocumented `"faction"` form (e.g. `bed.json:5-9` OStimBedFaction `OStim.esp` `0xEEF`).
- Installed hierarchy: see table in 2.3. Beds: scenes are authored per concrete subtype and side (`doublebed` / `singlebed`, `Left`/`Right` in the id); `bedroll` has no scenes of its own and falls back to `bed` -> `none`.

### 1.8 Actions inside scenes, action definitions, action tags

Scene action record [V] `scenes README.txt:121-131`: `type` (string, 628 uses), `actor` (int, 628), `target` (int, default = actor, 596), `performer` (int, default = actor, 63), `muted`, `doPeaks`, `peaksAnnotated` (0 uses).

Action definition file = `actions\<type>.json` [V] `actions README.txt:13-22`: `info`, `aliases` (list), `actor` / `target` / `performer` role objects (`stimulation`, `maxStimulation`, `fullStrip`, `moan`, `talk`, `muffled`, `expressionOverride`, `requirements`, `strippingSlots`, `faction`, `statFaction`, ... `ints/floats/strings` custom maps, `toySlot`), `sounds`, `peak`, `tags` ("commonly used are oral, playful, seductive, sensual and sexual"). "all string values are converted to lower case when parsed" (line 11).

- Aliases [V] e.g. `actions\kissing.json:3-5` `"aliases":["kiss"]`, `actions\hugging.json:3-5` `["hug"]`, `actions\holdinghand.json:2-6` `["holdhand","holdhands","holdinghands"]`, `actions\cuddling.json:3-5` `["cuddle"]`. [S] 214 aliases over 113 definitions (OStim 86 + OCR 27 files); scene files really use aliases (`hug`, `cunnilingus`, `lickingvagina`, `vampirebite`, `cuddle`, ...), so any index **must resolve aliases** (the script does; the map is in `ostim_scene_stats.json` -> `action_alias_map`).
- [V-src] `getActionAlias` lower-cases and maps, unknown types fall back: `logger::warn("No action found for {} using default", type); return &actions.at("default");` (`SRC/Graph/GraphTable/GraphTableActions.cpp`). [S] OARE uses two undefined types: `sleeping` (5 records) and `teasing` (2).
- Action tags used for classification [V]: `kissing.json:60-63` = `romantic`,`sensual`; `hugging.json:12-14` = `sensual`; `cuddling.json:12-14` = `sensual`; `holdinghand.json:24-26` = `romantic`. `pattinghead`, `strokinghead`, `holdingbody`, `holdinghead`, `spectating`, `mounting`, `leglocking` have **no tags** [S]. Every penetrative/oral/manual genital action carries `sexual`. Some tags are prefixed with `-` (`-penilestimulation`, `-vaginalstimulation`); meaning NOT FOUND in the shipped docs (looked in all README files of `OSTIM\SKSE\Plugins\OStim`).
- `action tags\<tag>.json` attach factions / stat factions to every action with that tag (e.g. `action tags\romantic.json:3-22` OStimRomanticMateFaction `0xFB7`; `sensual.json` `0xFB4`; `sexual.json` `0xF8D`, all `OStim.esp`). These factions are a cheap runtime signal for "what kind of action is this actor in right now".
- Requirements come from `actor properties\*.json` [V]: every NPC gets `anus, foot, hand, mouth, nipple, penis` (`OStimNPC.json:9-16`), females add `breast, vagina` (`OStimFemale.json`), plus `testicles` (schlongified), `vampire`, `inwater`, tail/beast files.

### 1.9 How transitions work, and destination-form vs origin-form edges

[V] `scenes README.txt:20-25`: `"destination"` - "adding this property turns this node into a transition, that means it will be played once and then automatically moves to the destination scene; if this property is filled the navigations property will be ignored". `"origin"` (scene level) - "if the transition is already in the navigations of the origin scene this field doesn't have to (and shouldn't) be filled".

[V-src] loader (`SRC/Graph/GraphTable/GraphTableSetupNodes.cpp`):
```cpp
if (json.contains("destination")) {
    if (json["destination"].is_string()) {
        node->isTransition = true;
        navigations.push_back({.origin = node->scene_id, .destination = json["destination"]});
        if (json.contains("origin")) { ... RawNavigation navigation = {.origin = json["origin"], .destination = node->scene_id}; ... }
    }
} else {
    if (json.contains("navigations")) { ... RawNavigation navigation = {.origin = node->scene_id, .destination = node->scene_id};
        // "destination" overwrites navigation.destination, "origin" overwrites navigation.origin
```
So each `navigations[]` entry starts as `this -> this`; `destination` replaces the head, `origin` replaces the tail.

[V-src] `GraphTable::addNavigations` (`SRC/Graph/GraphTable/GraphTableNodes.cpp`) then, for every raw edge:
1. resolves both ends with `getNodeById` (case-insensitive); missing end -> warning unless `noWarnings`, edge dropped;
2. drops the edge unless `start->furnitureType->isChildOf(destination->furnitureType) || destination->...->isChildOf(start->...)`;
3. drops it unless `start->actors.size() == destination->actors.size()`;
4. warns (does not drop) when actor types differ;
5. skips duplicates (same final or first node already present on that start node);
6. **folds transition chains**: `while (currentNode && currentNode->isTransition) { navigation.nodes.push_back(currentNode); ... next = getNodeById(nav.destination) ... }` then pushes the first non-transition node. A `Navigation` is therefore `[transition*, final loop scene]`; the player sees one menu option. Raw navigations are stable-sorted by `priority` first.

Concrete examples [V]:
- Hub with destination-form options: `OSTIM\...\scenes\OStim2P\MF\idle\OStim2PStandingApartMF.json:10-95` (14 options; tags `idle`,`intro` lines 96-99).
- Pure transition: `...\idle\OStim2PStandingApartToHoldingMF.json:10` `"destination": "OStim2PStandingHoldingMF"`, length 1.5, no actions.
- Origin-form hook: `OARE\SKSE\Plugins\OStim\scenes\Standing_apart\OARE_Standing.json:6-23` three entries `"origin": "OStim2PStandingApartMF"` / `...ApartMM` / `...CloseFF`, description `"Access Open Animations RE..."`, priority 500; lines 24-41 give the three return edges in destination form.
- **Transition that carries an action (one-shot act)**: `OARE\...\Standing_apart\OARE_ActionMaleKiss.json:4-5,42-48` length 5, `"destination": "OARE_Standing"`, action `kissing` 0->1. Entered from `OARE_Standing` ("Kiss {1}.", priority 1004, `OARE_Standing.json:106-113`) and returns to it automatically. [S] 14 such self-returning navigations exist in the collapsed graph.
- Auto transitions: actor-level `"autoTransitions": {"climax": "OStim2PMissionaryClimaxMF"}` (`...\sexual\Missionary\OStim2PMissionaryMF.json:87-89`); triggered by OStim itself or by `OThread.AutoTransition` / `AutoTransitionForActor` (`OSTIM\Scripts\Source\OThread.psc:141,152`). OARE's `devour0`/`devour1` auto transitions point at legacy ids such as `OpS|Sta!Sta|Ap|StandingDevourVampireMale` (`OARE_Standing.json:242-257`) that do not exist as scene files [S] - they resolve to nothing at runtime ([V-src] `Thread::autoTransition` returns false when `getNodeById` fails).

### 1.10 Automatic tags [V] + [V-src]

`list of commonly used scene tags.txt:4,6,12`: `gay` / `lesbian` added automatically when there is more than one actor and all `intendedSex` are male / female; `transition` added automatically for transitions. Confirmed in loader: `node->tryAddTag("transition")`, `tryAddTag("gay")`, `tryAddTag("lesbian")`. The script reports explicit tags only (OARE additionally writes `transition` by hand on 36 files).

### 1.11 Runtime behaviour that matters to the glue

- **Default start scene** [V-src] `SRC/Core/ThreadStarter/PlayerThreadStarter.cpp` `handleStartingNode`: tag = MCM `useIntroScenes` ? `"intro"` : `"idle"`; furniture `none` -> random node with that tag and any actor tagged `standing`; `bed` -> tag and no standing actor; other furniture -> tag only; none found -> notification "no starting animation found". [S] Only OStim scenes carry `intro` (74); OARE hubs carry `idle` only.
- **Native routing** [V-src] `SRC/Core/Thread/ThreadNavigation.cpp` + `SRC/Graph/Node/NodeNavigation.cpp`: `Thread::navigateTo` -> `m_currentNode->getRoute(MCM::MCMTable::navigationDistanceMax(), getActorConditions(), node)`; empty route -> `warpTo(node, useAutoModeFades || node->fadeOnEntry)`. `Node::getRoute` is a level-by-level BFS over folded navigations, skipping navigations not `fulfilledBy(actorConditions)`, stopping when `navigation.nodes.back() == destination`; intermediate loop scenes are held 500 ms, transitions for their `animationLengthMs`. Consequences: (1) distance is counted in folded navigations; (2) a **transition can never be a route target** (it is never `nodes.back()`), so `NavigateTo(<transition id>)` always warps straight into it; (3) no notion of content tier.
- MCM `NavigationDistanceMax`: default 5, range 0-20 [V] `OSTIM\Scripts\Source\OSexIntegrationMCM.psc:1060-1061`; stored in global `OStimNavigationDistanceMax` (`OSexIntegrationMain.psc:465-471`).
- **Actor gating** [V-src] `SRC/Trait/Condition.cpp` `ActorCondition::fulfills`: type must match; if MCM `unrestrictedNavigation` -> true; if MCM `intendedSexOnly` and both sexes known and different -> false; every requirement of the scene slot must be in the actor's requirement set. Female with schlong + MCM `futaUseMaleRole` -> sex treated as agender.
- Papyrus entry points (all `Global Native`, [V] `OSTIM\Scripts\Source\`):
```papyrus
; OThread.psc
int Function QuickStart(Actor[] Actors, string StartingAnimation = "", ObjectReference FurnitureRef = None)   ; :29
string Function GetScene(int ThreadID)                                                                       ; :86
Function NavigateTo(int ThreadID, string SceneID)                                                            ; :96  "if navigation is not possible instead warps there"
Function QueueNavigation(int ThreadID, string SceneID, float Duration)                                       ; :109
Function WarpTo(int ThreadID, string SceneID, bool UseFades = False)                                         ; :119
Function QueueWarp(int ThreadID, string SceneID, float Duration)                                             ; :131
bool Function AutoTransition(int ThreadID, string Type)                                                      ; :141
bool Function AutoTransitionForActor(int ThreadID, int Index, string Type)                                   ; :152
Function PlaySequence(int ThreadID, string Sequence, bool NavigateTo = false, bool UseFades = false)         ; :181
string Function GetFurnitureType(int ThreadID)                                                               ; :277
; OLibrary.psc
string[] Function GetAllScenes()                                                                             ; :30
string[] Function GetScenesInRange(string Id, Actor[] Actors, int Distance = 0)                              ; :43  (0 = MCM setting)
string Function GetRandomSceneWithAnyActionCSV(Actor[] Actors, string Types)                                 ; :719
string Function GetRandomFurnitureSceneWithAnyActionCSV(Actor[] Actors, string FurnitureType, string Types)  ; :773
string Function GetRandomSceneWithAnySceneTagCSV(Actor[] Actors, string Tags)                                ; :107
; OMetadata.psc
string Function GetName(string Id)                          ; :38
bool Function IsTransition(string Id)                       ; :58
int Function GetActorCount(string Id)                       ; :86
string[] Function GetSceneTags(string Id)                   ; :198
string[] Function GetActorTags(string Id, int Position)     ; :279
int Function FindAnyActionCSV(string Id, string Types)      ; :403
; OThreadBuilder.psc
int Function Create(Actor[] Actors)                                                                          ; :46
Function SetFurniture(int BuilderID, ObjectReference FurnitureRef)                                           ; :63
Function SetStartingAnimation(int BuilderID, string Animation)                                               ; :83
Function AddStartingAnimation(int BuilderID, string Animation, float Duration = 0.0, bool NavigateTo = false); :95
Function SetStartingSequence(int BuilderID, string Sequence)                                                 ; :106
Function EndAfterSequence(int BuidlerID)                                                                     ; :126
Function NoAutoMode(int BuilderID) ; :148   Function NoPlayerControl(int BuilderID) ; :157
Function NoUndressing(int BuilderID) ; :176  Function NoFurniture(int BuilderID) ; :189
int Function Start(int BuilderID)                                                                            ; :216
; OFurniture.psc
string Function GetFurnitureType(ObjectReference FurnitureRef)                                               ; :16
bool Function IsChildOf(string SuperType, string SubType)                                                    ; :28
ObjectReference[] Function FindFurniture(int ActorCount, ObjectReference CenterRef, float Radius, float SameFloor = 0.0) ; :41
; OActorUtil.psc
Actor[] Function Sort(Actor[] Actors, Actor[] DominantActors, int PlayerIndex = -1)                          ; :142
```
- Mod events for live scene awareness [V] `OSTIM\SKSE\Plugins\OStim\list of mod events.txt`: `ostim_start` (:6-12), `ostim_scenechanged` with `SceneID` in the string arg (:14-23), `ostim_end` with a JSON string readable via `OJSON.GetActors(Json)` / `OJSON.GetScene(Json)` (:39-51), and thread-generic `ostim_thread_start`, `ostim_thread_scenechanged` (SceneID, ThreadID) (:69-80), `ostim_thread_speedchanged`, `ostim_actor_orgasm`, `ostim_furniturechanged`, `ostim_event`, `ostim_thread_end`. "the thread containing the player will always have the ThreadID 0" (`OThread.psc:3`).

### 1.12 Sequences [V] `OSTIM\SKSE\Plugins\OStim\sequences README.txt:1-12`

`sequences\<id>.json`: `scenes` = list of `{ "id": sceneID, "duration": seconds (optional, else the scene's length) }`, `tags`. Example `OCR\SKSE\Plugins\OStim\sequences\OCR_MF_Kiss1.json`: `OCR_MF_BasicIdle` 1 s -> `OCR_MF_Kiss1` -> `OCR_MF_BasicIdle` 0.5 s.

---

## 2. Parsing script and full statistical index

`ostim_scene_stats.py` (this folder) - Python 3.9+, stdlib only, never writes under the mods root.

```
# inside WSL (DwemerAI4Skyrim3 has Python 3.11.2)
python3 ostim_scene_stats.py                          # reads /mnt/f/Modlists/LoreRim/mods, writes ostim_scene_stats.json next to itself
python3 ostim_scene_stats.py --mods-root <dir> --out <file>
```
Practical note: this workspace lives under a packaged-app redirected `AppData\Roaming\Claude`; from WSL the same folder is `/mnt/c/Users/Jordan/AppData/Local/Packages/Claude_pzs8sxrjxfjjc/LocalCache/Roaming/Claude/scratch-workspaces/.../research/` (the plain `/mnt/c/Users/Jordan/AppData/Roaming/Claude/...` path does not exist for WSL).

What it does: loads translations, action definitions (+aliases) and furniture types from the three packs; parses every scene (tolerant loader, records raw key spellings); builds declared edges exactly as the README/loader define them (transition -> destination, optional scene-level origin, `navigations[].destination`, `navigations[].origin`; `navigations` ignored on transitions; ids case-insensitive); computes per-pack stats, global + per-(furniture, actor count) graph stats (weak components, Tarjan SCCs, hubs, in/out-degree 0, reachability from the group's idle/intro scenes), an OStim-style collapsed graph with distance histograms, glue-side intensity tiers, the romantic-scene connectivity test, and a neutral one-line description per scene.

Top-level keys of `ostim_scene_stats.json`: `key_census_raw_spelling`, `action_definitions`, `action_alias_map`, `furniture_types`, `duplicate_scene_ids`, `parse_problems`, `packs.{OStim,OARE,OCR}`, `all`, `graph_global`, `graph_collapsed_ostim_style`, `graph_groups_furniture_x_actor_count`, `romantic`, `sequences`, `edges[]` (`src`,`dst`,`forms`,`description`,`priority`), `scenes{id -> pack,file,name_en,length,is_transition,destination,furniture,tags,actor_count,sex_config,class,tier,tier_name,staging,explicit_out_share,action_tags,description,actors[],actions[[type,actor,target,performer]]}`.

Deviation from OStim to be aware of: the script reads keys case-insensitively (so it sees `modPack`); it does not evaluate actor conditions (sex / requirements) and does not drop edges for furniture incompatibility (none of the installed edges violates it: all 28 cross-furniture edges are bench<->chair or bed<->none).

### 2.1 Pack summary

| pack | scene files | transitions | non-transition | transitions with actions | transitions using scene-level origin | noRandomSelection=true | 1-speed scenes | multi-speed scenes | 0-speed scenes | length s (min / mean / max) |
|---|---|---|---|---|---|---|---|---|---|---|
| OStim | 280 | 31 | 249 | 10 | 0 | 0 | 190 | 90 | 0 | 0.75 / 2.6 / 17.5 |
| OARE | 287 | 47 | 240 | 17 | 0 | 3 | 140 | 146 | 1 | 0.75 / 2.271 / 14 |
| OCR | 40 | 36 | 4 | 36 | 0 | 40 | 40 | 0 | 0 | 2.2 / 5.26 / 15 |
| all | 607 | 114 | 493 | 63 | 0 | 43 | 370 | 236 | 1 | 0.75 / 2.619 / 17.5 |

All 607 files parsed as strict JSON (parse problems: 0). Duplicate scene ids across packs: 0. Modpack strings: `Open Animations Romance and Erotica` x287; `OStim` x280; `OStim Community Resource` x40.


### 2.2 Actor counts and sex configurations

| actors per scene | OStim | OARE | OCR | all |
|---|---|---|---|---|
| 2 | 235 | 277 | 40 | 552 |
| 1 | 19 | 10 | 0 | 29 |
| 3 | 12 | 0 | 0 | 12 |
| 4 | 10 | 0 | 0 | 10 |
| 5 | 4 | 0 | 0 | 4 |


Sex configuration = concatenated `intendedSex` per actor index (M = male, F = female, A = any / not specified).

| sex config | OStim | OARE | OCR | all |
|---|---|---|---|---|
| MF | 208 | 88 | 0 | 296 |
| AA | 1 | 140 | 38 | 179 |
| MA | 0 | 33 | 2 | 35 |
| M | 9 | 10 | 0 | 19 |
| AF | 0 | 16 | 0 | 16 |
| FF | 14 | 0 | 0 | 14 |
| MM | 12 | 0 | 0 | 12 |
| F | 10 | 0 | 0 | 10 |
| FFF | 3 | 0 | 0 | 3 |
| MFF | 3 | 0 | 0 | 3 |
| MMF | 3 | 0 | 0 | 3 |
| MMM | 3 | 0 | 0 | 3 |
| FFFF | 2 | 0 | 0 | 2 |
| MFFF | 2 | 0 | 0 | 2 |
| MFFFF | 2 | 0 | 0 | 2 |
| MMFF | 2 | 0 | 0 | 2 |
| MMMF | 2 | 0 | 0 | 2 |
| MMMM | 2 | 0 | 0 | 2 |
| MMMMF | 2 | 0 | 0 | 2 |


### 2.3 Furniture

| scene `furniture` | OStim | OARE | OCR | all |
|---|---|---|---|---|
| none | 193 | 241 | 40 | 474 |
| chair | 16 | 8 | 0 | 24 |
| doublebed | 20 | 0 | 0 | 20 |
| singlebed | 20 | 0 | 0 | 20 |
| shelf | 8 | 11 | 0 | 19 |
| table | 9 | 10 | 0 | 19 |
| cookingpot | 6 | 10 | 0 | 16 |
| bench | 3 | 7 | 0 | 10 |
| wall | 5 | 0 | 0 | 5 |


Furniture x actor-count groups (this is exactly how OStim buckets nodes: `nodeList[furnitureType->getMasterTypeInternal()][actorCount]`):

| furniture\|actors | OStim | OARE | OCR | all |
|---|---|---|---|---|
| none\|2 | 163 | 231 | 40 | 434 |
| doublebed\|2 | 20 | 0 | 0 | 20 |
| singlebed\|2 | 20 | 0 | 0 | 20 |
| chair\|2 | 10 | 8 | 0 | 18 |
| none\|1 | 8 | 10 | 0 | 18 |
| table\|2 | 7 | 10 | 0 | 17 |
| shelf\|2 | 5 | 11 | 0 | 16 |
| cookingpot\|2 | 4 | 10 | 0 | 14 |
| bench\|2 | 3 | 7 | 0 | 10 |
| none\|4 | 10 | 0 | 0 | 10 |
| none\|3 | 8 | 0 | 0 | 8 |
| chair\|3 | 4 | 0 | 0 | 4 |
| none\|5 | 4 | 0 | 0 | 4 |
| shelf\|1 | 3 | 0 | 0 | 3 |
| wall\|2 | 3 | 0 | 0 | 3 |
| chair\|1 | 2 | 0 | 0 | 2 |
| cookingpot\|1 | 2 | 0 | 0 | 2 |
| table\|1 | 2 | 0 | 0 | 2 |
| wall\|1 | 2 | 0 | 0 | 2 |


Furniture type definitions shipped by OStim (`furniture types/*.json`):

| type | supertype | priority | listIndividually | resolution chain (type -> supertypes) |
|---|---|---|---|---|
| alchemytable | table | 10 | False | alchemytable -> table |
| bed | none | 0 | True | bed -> none |
| bedroll | bed | 30 | False | bedroll -> bed -> none |
| bench | chair | 5 | True | bench -> chair |
| chair | None | 0 | False | chair |
| cookingpot | None | 10 | False | cookingpot |
| doublebed | bed | 20 | False | doublebed -> bed -> none |
| enchantingtable | table | 10 | False | enchantingtable -> table |
| shelf | None | 10 | False | shelf |
| singlebed | bed | 10 | False | singlebed -> bed -> none |
| table | None | 0 | False | table |
| tableleanmarker | table | 10 | False | tableleanmarker -> table |
| tableleanmarkerbbls | tableleanmarker | 20 | False | tableleanmarkerbbls -> tableleanmarker -> table |
| wall | None | 10 | False | wall |
| wardrobe | wall | 0 | False | wardrobe -> wall |
| wardrobethick | wardrobe | 10 | False | wardrobethick -> wardrobe -> wall |
| wardrobethin | wardrobe | 10 | False | wardrobethin -> wardrobe -> wall |


### 2.4 Scene tags (explicit tags only; OStim adds `transition`, `gay`, `lesbian` automatically at load)

| scene tag | OStim | OARE | OCR | all |
|---|---|---|---|---|
| oare | 0 | 271 | 0 | 271 |
| idle | 127 | 50 | 0 | 177 |
| intro | 74 | 0 | 0 | 74 |
| cuddling | 0 | 41 | 0 | 41 |
| transition | 0 | 36 | 0 | 36 |
| missionary | 9 | 15 | 0 | 24 |
| cowgirl | 8 | 13 | 0 | 21 |
| doggystyle | 11 | 9 | 0 | 20 |
| undressing | 6 | 7 | 0 | 13 |
| prone | 7 | 4 | 0 | 11 |
| action | 0 | 8 | 0 | 8 |
| footfetish | 0 | 6 | 0 | 6 |
| reversecowgirl | 3 | 3 | 0 | 6 |
| facesitting | 2 | 0 | 0 | 2 |
| blowjob | 0 | 1 | 0 | 1 |
| spanking | 0 | 1 | 0 | 1 |
| spooning | 0 | 1 | 0 | 1 |


### 2.5 Actor tags

| actor tag | OStim | OARE | OCR | all |
|---|---|---|---|---|
| standing | 274 | 246 | 80 | 600 |
| kneeling | 99 | 86 | 0 | 185 |
| sitting | 94 | 83 | 0 | 177 |
| lyingback | 66 | 59 | 0 | 125 |
| facingaway | 64 | 48 | 0 | 112 |
| onbottom | 49 | 0 | 0 | 49 |
| ontop | 49 | 0 | 0 | 49 |
| lyingside | 6 | 31 | 0 | 37 |
| lyingfront | 17 | 17 | 0 | 34 |
| squatting | 14 | 15 | 0 | 29 |
| allfours | 15 | 10 | 0 | 25 |
| bendover | 11 | 13 | 0 | 24 |
| oare | 0 | 12 | 0 | 12 |
| drowsy | 4 | 5 | 0 | 9 |
| suspended | 0 | 7 | 0 | 7 |
| climaxing | 0 | 3 | 0 | 3 |
| nostrip | 0 | 2 | 0 | 2 |
| lying | 1 | 0 | 0 | 1 |
| spreadlegs | 0 | 1 | 0 | 1 |


Actor `type` values: `npc` x1229


### 2.6 Action types (canonical name after alias resolution; count = action records)

| action type | OStim | OARE | OCR | all records | scenes containing it | action-definition tags |
|---|---|---|---|---|---|---|
| vaginalsex | 43 | 78 | 0 | 121 | 121 | intercourse,-penilestimulation,sexual,vaginalpenetration,vaginalstimulation |
| undress_head_minus_circlet_target | 0 | 0 | 76 | 76 | 38 |  |
| kissing | 14 | 32 | 0 | 46 | 46 | romantic,sensual |
| holdingbody | 36 | 0 | 0 | 36 | 29 |  |
| blowjob | 15 | 14 | 0 | 29 | 29 | fellatio,oral,penilestimulation,sexual |
| handjob | 9 | 10 | 0 | 19 | 19 | penilestimulation,sexual |
| holdingleg | 19 | 0 | 0 | 19 | 19 |  |
| holdinghand | 13 | 5 | 0 | 18 | 18 | romantic |
| gropingbreast | 7 | 9 | 0 | 16 | 16 | sensual |
| malemasturbation | 12 | 3 | 0 | 15 | 15 | sexual |
| audiblebreathing | 0 | 14 | 0 | 14 | 14 |  |
| gropingbutt | 8 | 6 | 0 | 14 | 14 | sensual |
| holdinghead | 12 | 2 | 0 | 14 | 14 |  |
| spectating | 14 | 0 | 0 | 14 | 13 |  |
| facial | 10 | 3 | 0 | 13 | 13 | cumshot,sexual |
| mounting | 13 | 0 | 0 | 13 | 13 |  |
| hugging | 11 | 1 | 0 | 12 | 8 | sensual |
| boobjob | 2 | 9 | 0 | 11 | 11 | penilestimulation,sexual |
| undress_full_target | 0 | 9 | 0 | 9 | 9 |  |
| cumonchest | 5 | 2 | 0 | 7 | 7 | cumshot,sexual |
| holdinghip | 7 | 0 | 0 | 7 | 7 | romantic |
| vulvaleating | 3 | 4 | 0 | 7 | 7 | vulvalstimulation,cunnilingus,oral,sexual |
| vulvallicking | 5 | 2 | 0 | 7 | 7 | vulvalstimulation,cunnilingus,licking,oral,sexual |
| buttjob | 4 | 2 | 0 | 6 | 6 | penilestimulation,sexual |
| holdingthigh | 6 | 0 | 0 | 6 | 6 | romantic |
| cuddling | 5 | 0 | 0 | 5 | 4 | sensual |
| femalemasturbation | 3 | 2 | 0 | 5 | 5 | sexual |
| holdingarm | 5 | 0 | 0 | 5 | 5 |  |
| sleeping | 0 | 5 | 0 | 5 | 4 | NO DEFINITION FILE -> OStim falls back to `default` |
| vampirebiting | 2 | 3 | 0 | 5 | 5 | seductive,sensual |
| cumonbutt | 4 | 0 | 0 | 4 | 4 | cumshot,sexual |
| cumonvulva | 4 | 0 | 0 | 4 | 4 | cumshot,sexual |
| holdingchin | 4 | 0 | 0 | 4 | 4 | romantic |
| vaginalfingering | 1 | 3 | 0 | 4 | 4 | fingering,sexual,vaginalstimulation |
| footjob | 0 | 3 | 0 | 3 | 3 | penilestimulation,sexual |
| penilelicking | 1 | 2 | 0 | 3 | 3 | fellatio,licking,oral,penilestimulation,sexual |
| suckingnipple | 1 | 2 | 0 | 3 | 3 | nipplestimulation,oral,oralnipplestimulation,sexual |
| vulvalrubbing | 1 | 2 | 0 | 3 | 3 | vulvalstimulation,sexual |
| analfingering | 2 | 0 | 0 | 2 | 2 | analstimulation,fingering,sexual |
| grindingpenis | 0 | 2 | 0 | 2 | 2 | grinding,penilestimulation,sexual,-vaginalstimulation |
| kissingneck | 0 | 2 | 0 | 2 | 2 | romantic,sensual |
| leglocking | 2 | 0 | 0 | 2 | 2 |  |
| pattinghead | 1 | 1 | 0 | 2 | 2 |  |
| strokinghead | 1 | 1 | 0 | 2 | 2 |  |
| teasing | 0 | 2 | 0 | 2 | 2 | NO DEFINITION FILE -> OStim falls back to `default` |
| bathing | 1 | 0 | 0 | 1 | 1 |  |
| gropingtesticles | 1 | 0 | 0 | 1 | 1 | sexual |
| kissingfoot | 1 | 0 | 0 | 1 | 1 | sensual |
| lickingear | 0 | 1 | 0 | 1 | 1 | sensual |
| pullinghair | 1 | 0 | 0 | 1 | 1 | sensual |
| rubbingpenisagainstface | 0 | 1 | 0 | 1 | 1 | -penilestimulation,sexual |
| sleepingsound | 0 | 1 | 0 | 1 | 1 |  |
| tailjob | 1 | 0 | 0 | 1 | 1 | penilestimulation,sexual |
| thighjob | 0 | 1 | 0 | 1 | 1 | penilestimulation,sexual |
| ticklingfoot | 0 | 1 | 0 | 1 | 1 |  |
| undress_foot_item_target | 0 | 1 | 0 | 1 | 1 |  |
| undress_head_item_target | 0 | 1 | 0 | 1 | 1 |  |


Alias spellings actually used in scene files (raw `type` -> canonical): `hug`->`hugging`, `cunnilingus`->`vulvaleating`, `lickingvagina`->`vulvallicking`, `vampirebite`->`vampirebiting`, `lickingpenis`->`penilelicking`, `rubbingclitoris`->`vulvalrubbing`, `suckingnipples`->`suckingnipple`, `cuddle`->`cuddling`, `leglock`->`leglocking`, `ticklingfeet`->`ticklingfoot`


Unresolved action types (no action file, no alias): `sleeping` x5, `teasing` x2


Scenes per action-definition tag (a scene counts once per tag):

| action tag | OStim | OARE | OCR | all |
|---|---|---|---|---|
| sexual | 94 | 137 | 0 | 231 |
| vaginalstimulation | 44 | 81 | 0 | 125 |
| -penilestimulation | 43 | 79 | 0 | 122 |
| intercourse | 43 | 78 | 0 | 121 |
| vaginalpenetration | 43 | 78 | 0 | 121 |
| sensual | 39 | 51 | 0 | 90 |
| romantic | 34 | 38 | 0 | 72 |
| penilestimulation | 28 | 42 | 0 | 70 |
| oral | 25 | 24 | 0 | 49 |
| fellatio | 16 | 16 | 0 | 32 |
| cumshot | 14 | 3 | 0 | 17 |
| vulvalstimulation | 9 | 8 | 0 | 17 |
| cunnilingus | 8 | 6 | 0 | 14 |
| licking | 6 | 4 | 0 | 10 |
| fingering | 3 | 3 | 0 | 6 |
| seductive | 2 | 3 | 0 | 5 |
| nipplestimulation | 1 | 2 | 0 | 3 |
| oralnipplestimulation | 1 | 2 | 0 | 3 |
| -vaginalstimulation | 0 | 2 | 0 | 2 |
| analstimulation | 2 | 0 | 0 | 2 |
| grinding | 0 | 2 | 0 | 2 |


### 2.7 Classification

By action-definition tags (`class`): `sexual` = at least one action whose definition has tag `sexual`; `sensual_romantic` = none sexual but at least one `sensual`/`romantic`; `other_actions_only`; `no_actions`.

| class | OStim | OARE | OCR | all |
|---|---|---|---|---|
| sexual | 94 | 137 | 0 | 231 |
| no_actions | 129 | 92 | 2 | 223 |
| other_actions_only | 28 | 20 | 38 | 86 |
| sensual_romantic | 29 | 38 | 0 | 67 |


Glue-side intensity tier (script convention, see section 5a): 0 neutral, 1 affection, 2 kissing, 3 sensual (groping / undressing / vampire / spank), 4 sexual.

| tier | OStim | OARE | OCR | all |
|---|---|---|---|---|
| 0_neutral | 142 | 53 | 12 | 207 |
| 1_affection | 15 | 37 | 24 | 76 |
| 2_kissing | 9 | 20 | 4 | 33 |
| 3_sensual | 20 | 40 | 0 | 60 |
| 4_sexual | 94 | 137 | 0 | 231 |


Same, non-transition scenes only:

| tier | OStim | OARE | OCR | all |
|---|---|---|---|---|
| 0_neutral | 126 | 42 | 4 | 172 |
| 1_affection | 12 | 29 | 0 | 41 |
| 2_kissing | 9 | 18 | 0 | 27 |
| 3_sensual | 9 | 18 | 0 | 27 |
| 4_sexual | 93 | 133 | 0 | 226 |


### 2.8 Raw JSON key census (exact spelling as found in files)

- **scene keys**: `actors` x607, `length` x607, `name` x607, `speeds` x607, `navigations` x576, `actions` x478, `tags` x460, `modPack` x349, `noRandomSelection` x329, `defaultSpeed` x289, `autoTransitions` x287, `modpack` x258, `furniture` x133, `destination` x114, `offset` x40, `scaleOffsetWithFurniture` x40

- **navigation keys**: `description` x1516, `icon` x1515, `destination` x1475, `priority` x1184, `noWarnings` x555, `border` x295, `origin` x41

- **speed keys**: `animation` x1351, `displaySpeed` x1084, `playbackSpeed` x812

- **actor keys**: `intendedSex` x1229, `tags` x1229, `feetOnGround` x1225, `scale` x901, `type` x690, `scaleHeight` x670, `lookUp` x621, `autoTransitions` x568, `sosBend` x503, `lookLeft` x210, `expressionAction` x8, `lookRight` x4, `noStrip` x2, `animationIndex` x1, `lookleft` x1, `requirements` x1, `singleSpeed` x1

- **action keys**: `actor` x628, `type` x628, `target` x596, `performer` x63


autoTransition keys on actors: [['climax', 6], ['devour0', 3], ['devour1', 1]] ; on scenes: []. autoTransition targets total 10, of which pointing to non-existent scene ids: `OpS|LyB!Sit|BoJ|AceLyingBoobjob_MaleClimax`, `OpS|Sit!Sit|Ap|SittingVampireFemale`, `OpS|Sta!Kne|Ap|MaleStandingFemaleKneelingDevourVampireFemale`, `OpS|Sta!Kne|BoJ|AceStandingBoobjob_MaleClimax`, `OpS|Sta!Sta|Ap|StandingDevourVampireFemale`, `OpS|Sta!Sta|Ap|StandingDevourVampireMale`


### 2.9 Navigation graph - global

| metric | value |
|---|---|
| nodes (scenes) | 607 |
| unique directed edges | 1629 |
| edge declarations | 1630 |
| declarations by form | destination x1475, transition x114, origin x41 |
| edges by pack pair | OStim->OStim x975, OARE->OARE x558, OStim->OARE x41, OCR->OCR x36, OARE->OStim x19 |
| edges between different actor counts | 0 |
| edges between different furniture | bench->chair x10, chair->bench x10, doublebed->none x2, none->doublebed x2, none->singlebed x2, singlebed->none x2 |
| references whose letter case differs from the file name | 6 |
| dangling references (origin/destination not installed) | 0 |
| weakly connected components | 20 (sizes [434, 28, 19, 19, 18, 17, 16, 14, 10, 8, 4, 4, 3, 3, 2, 2, 2, 2, 1, 1]) |
| strongly connected components with >1 node (sizes) | [421, 28, 18, 17, 16, 14, 10, 8, 4, 4, 4, 3, 3, 2, 2, 2, 2, 2] |
| in-degree 0 scenes | 45 |
| out-degree 0 scenes | 4 |


Top 25 hubs by degree (in + out, unique edges):

| scene | pack | degree | out | in | tier |
|---|---|---|---|---|---|
| OARE_Standing | OARE | 51 | 25 | 26 | 0 |
| OARE_StandingHolding | OARE | 34 | 17 | 17 | 0 |
| OStim2PStandingApartMF | OStim | 33 | 15 | 18 | 0 |
| OStim2PStandingSquattingMF | OStim | 29 | 8 | 21 | 0 |
| OStim2PDoggyIdleMF | OStim | 28 | 5 | 23 | 0 |
| OARE_StandingHoldingFromBehind | OARE | 26 | 13 | 13 | 0 |
| OStim2PStandingHoldingMF | OStim | 26 | 9 | 17 | 0 |
| OStim2PSittingMF | OStim | 25 | 11 | 14 | 0 |
| OStim2PStandingKneelingMF | OStim | 25 | 9 | 16 | 0 |
| OStim2PStandingHoldingFromBehindMF | OStim | 23 | 11 | 12 | 0 |
| OARE_Sitting | OARE | 22 | 11 | 11 | 0 |
| OARE_SittingMaleApproach | OARE | 22 | 10 | 12 | 0 |
| OStim2PMissionaryIdleMF | OStim | 22 | 6 | 16 | 0 |
| OStim2PKneelingJerkToFaceMF | OStim | 21 | 9 | 12 | 4 |
| OARE_SpooningIdle | OARE | 20 | 9 | 11 | 1 |
| OARE_SittingFemaleApproach | OARE | 19 | 8 | 11 | 0 |
| OARE_ChairIdle | OARE | 18 | 9 | 9 | 0 |
| OCR_FM_BasicIdle | OCR | 18 | 0 | 18 | 0 |
| OCR_MF_BasicIdle | OCR | 18 | 0 | 18 | 0 |
| OStim2PKneelingBlowjobMF | OStim | 18 | 8 | 10 | 4 |
| OStim2PStandingBehindJerkToButtMF | OStim | 18 | 6 | 12 | 4 |
| OARE_FemaleAllFours | OARE | 17 | 7 | 10 | 0 |
| OARE_MaleStandingFemaleKneeling | OARE | 17 | 8 | 9 | 0 |
| OStim2PMissionaryMF | OStim | 17 | 8 | 9 | 4 |
| OStim2PStandingBehindFuckMF | OStim | 17 | 9 | 8 | 4 |


In-degree 0 (cannot be navigated TO; only startable / warpable), excluding the 38 OCR one-shot interaction scenes which are all in-degree 0 by design: `OARE_AceBackItUp`, `OARE_ActionFemaleCaressHoldHands`, `OARE_CowgirlCircularGrind`, `OARE_GoToReverseCowgirlMounted1`, `OARE_HandOnHeadBlowjob`, `OStim2PStandingSquattingToStandingApartMF`, `Ostim2PStandingKneelingJerkToFaceMF`


Out-degree 0 (dead ends): `OCR_FM_BasicIdle`, `OCR_FM_StandingConversationLoop`, `OCR_MF_BasicIdle`, `OCR_MF_StandingConversationLoop`


### 2.10 Navigation graph - per (furniture, actor count) group

| group | nodes | intra edges | cross out | cross in | transitions | weak comps [sizes] | largest SCC | in-deg 0 | out-deg 0 | not reachable from the group's idle/intro scenes | top hubs (degree) |
|---|---|---|---|---|---|---|---|---|---|---|---|
| bench\|2 | 10 | 19 | 10 | 10 | 0 | 2 [8, 2] | 5 | 0 | 0 | 0 | OARE_BenchBothSittingFaceEachother (6), OStimBench2PBothSittingMM (5), OStimBench2PBothSittingMF (5), OStimBench2PBothSittingFF (5) |
| chair\|1 | 2 | 2 | 0 | 0 | 0 | 1 [2] | 2 | 0 | 0 | 0 | OStimChair1PSittingM (2), OStimChair1PSittingF (2) |
| chair\|2 | 18 | 54 | 10 | 10 | 0 | 1 [18] | 18 | 0 | 0 | 0 | OStimChair2PSittingStandingMF (14), OARE_ChairIdle (14), OStimChair2PSittingStandingFF (10), OStimChair2PSittingStandingMM (8) |
| chair\|3 | 4 | 12 | 0 | 0 | 0 | 1 [4] | 4 | 0 | 0 | 0 | OStimChair3PSittingStandingMMM (6), OStimChair3PSittingStandingMMF (6), OStimChair3PSittingStandingMFF (6), OStimChair3PSittingStandingFFF (6) |
| cookingpot\|1 | 2 | 2 | 0 | 0 | 0 | 1 [2] | 2 | 0 | 0 | 0 | OStimCookingPot1PCookingM (2), OStimCookingPot1PCookingF (2) |
| cookingpot\|2 | 14 | 35 | 0 | 0 | 0 | 1 [14] | 14 | 0 | 0 | 0 | OARE_CookingPotIdle (13), OStimCookingPot2PBehindMF (8), OARE_CookingPotMaleApproach (8), OARE_CookingPotSex (7) |
| doublebed\|2 | 20 | 46 | 2 | 2 | 0 | 1 [20] | 20 | 0 | 0 | 0 | OStimDoubleBedRight2PSittingStandingMF (10), OStimDoubleBedRight2PBothSittingMF (10), OStimDoubleBedLeft2PSittingStandingMF (10), OStimDoubleBedLeft2PBothSittingMF (10) |
| none\|1 | 18 | 39 | 0 | 0 | 3 | 1 [18] | 18 | 0 | 0 | 0 | OARE_MaleSoloStandingIdle (10), OStim1PStandingM (8), OARE_MaleSoloLyingIdle (7), OARE_MaleSleepingBack (6) |
| none\|2 | 434 | 1156 | 4 | 4 | 111 | 5 [394, 19, 19, 1, 1] | 381 | 45 | 4 | 53 | OARE_Standing (51), OARE_StandingHolding (34), OStim2PStandingApartMF (33), OStim2PStandingSquattingMF (29) |
| none\|3 | 8 | 32 | 0 | 0 | 0 | 1 [8] | 8 | 0 | 0 | 0 | OStim3PStandingMMM (8), OStim3PStandingMMF (8), OStim3PStandingMFF (8), OStim3PStandingFFF (8) |
| none\|4 | 10 | 49 | 0 | 0 | 0 | 1 [10] | 10 | 0 | 0 | 0 | OStim4PStandingMMMF (11), OStim4PLyingMMMF (11), OStim4PStandingMMFF (10), OStim4PStandingMFFF (10) |
| none\|5 | 4 | 8 | 0 | 0 | 0 | 1 [4] | 4 | 0 | 0 | 0 | OStim5PStandingMMMMF (5), OStim5PLyingMMMMF (5), OStim5PStandingMFFFF (3), OStim5PLyingMFFFF (3) |
| shelf\|1 | 3 | 4 | 0 | 0 | 0 | 1 [3] | 3 | 0 | 0 | 0 | OStimShelf1PStandingF (4), OStimShelf1PStandingM (2), OStimShelf1PBendOverF (2) |
| shelf\|2 | 16 | 40 | 0 | 0 | 0 | 1 [16] | 16 | 0 | 0 | 0 | OARE_ShelfIdle (10), OStimShelf2PBehindMF (9), OStimShelf2PBehindFF (9), OStimShelf2PBehindMM (6) |
| singlebed\|2 | 20 | 46 | 2 | 2 | 0 | 1 [20] | 20 | 0 | 0 | 0 | OStimSingleBedRight2PSittingStandingMF (10), OStimSingleBedRight2PBothSittingMF (10), OStimSingleBedLeft2PSittingStandingMF (10), OStimSingleBedLeft2PBothSittingMF (10) |
| table\|1 | 2 | 2 | 0 | 0 | 0 | 1 [2] | 2 | 0 | 0 | 0 | OStimTable1PSittingM (2), OStimTable1PSittingF (2) |
| table\|2 | 17 | 47 | 0 | 0 | 0 | 1 [17] | 17 | 0 | 0 | 0 | OARE_TableIdleFacingEachOther (12), OARE_TableIdleSittingFemaleHolding (11), OStimTable2PStandingLeaningMF (10), OStimTable2PStandingLeaningFF (10) |
| wall\|1 | 2 | 2 | 0 | 0 | 0 | 1 [2] | 2 | 0 | 0 | 0 | OStimWall1PLeaningM (2), OStimWall1PLeaningF (2) |
| wall\|2 | 3 | 6 | 0 | 0 | 0 | 1 [3] | 3 | 0 | 0 | 0 | OStimWall2PStandingLeaningMM (4), OStimWall2PStandingLeaningMF (4), OStimWall2PStandingLeaningFF (4) |


`none|2` scenes not reachable from any idle/intro scene of the group, excluding OCR: `OARE_AceBackItUp`, `OARE_AceRearKneelingSex`, `OARE_AceRearKneelingSexVariation`, `OARE_ActionFemaleCaressHoldHands`, `OARE_CowgirlCircularGrind`, `OARE_GoToReverseCowgirlMounted1`, `OARE_HandOnHeadBlowjob`, `OARE_ProneSexEmbraceFemaleLegsIn`, `OARE_ProneSexEmbraceFemaleLegsOut`, `OARE_ProneSexFemaleLegsIn`, `OARE_ProneSexFemaleLegsOut`, `OStim2PStandingSquattingToStandingApartMF`, `Ostim2PStandingKneelingJerkToFaceMF`


Cross-group edges exist only between a furniture type and its super/sub type: bench->chair x10, chair->bench x10, doublebed->none x2, none->doublebed x2, none->singlebed x2, singlebed->none x2


### 2.11 OStim-style collapsed graph (transition chains folded into one navigation, as `GraphTable::addNavigations` does)

Non-transition nodes: 493, navigations: 1498, navigations that return to their own start through a transition (e.g. one-shot kiss/caress actions): 14.


Scenes reachable within N navigations (this is the unit `Node::getRoute` counts against the MCM `NavigationDistanceMax`, default 5), ignoring actor conditions:

| start scene | <=0 | <=1 | <=2 | <=3 | <=4 | <=5 | <=6 | <=7 | <=8 | <=9 | <=10 | <=11 |
|---|---|---|---|---|---|---|---|---|---|---|---|---|
| OStim2PStandingApartMF | 1 | 16 | 59 | 151 | 243 | 290 | 326 | 338 | 345 | 349 | 350 | 350 |
| OARE_Standing | 1 | 14 | 63 | 135 | 225 | 309 | 336 | 345 | 349 | 350 | 350 | 350 |
| OStim2PStandingKissEmbraceMF | 1 | 10 | 25 | 67 | 118 | 195 | 262 | 307 | 333 | 344 | 349 | 350 |
| OARE_SpooningIdle | 1 | 10 | 33 | 83 | 174 | 274 | 337 | 350 | 350 | 350 | 350 | 350 |
| OStim2PBothLyingMF | 1 | 6 | 30 | 100 | 196 | 285 | 330 | 347 | 350 | 350 | 350 | 350 |
| OStimDoubleBedLeft2PBothSittingMF | 1 | 7 | 24 | 73 | 153 | 243 | 312 | 336 | 345 | 349 | 350 | 350 |
| OStimChair2PSittingStandingMF | 1 | 9 | 20 | 24 | 27 | 28 | 28 | 28 | 28 | 28 | 28 | 28 |

Reading: the 350 reachable loop scenes = the `none|2` main component plus the 40 bed scenes (the bed scenes are only really available when the thread has a bed). With the default limit of 5, OStim's own `NavigateTo` walks to 290-309 of 350 scenes from the two main standing hubs and warps to the rest; from a kiss scene only 195 of 350 are within 5. The chair/bench bucket (28 loop scenes) is closed: no declared edge leaves it.


### 2.12 ID naming conventions

- **OStim**: prefixes [['(no underscore)', 280]]; max id length 48; ids with characters outside [A-Za-z0-9_]: 0; most common first token after the prefix: O x279, Ostim x1

- **OARE**: prefixes [['OARE_', 287]]; max id length 42; ids with characters outside [A-Za-z0-9_]: 0; most common first token after the prefix: Standing x30, Sitting x20, Ace x17, Go x17, Male x13, Cowgirl x12, Shelf x11, Spooning x11, Cooking x10, Table x10, F x9, Missionary x9

- **OCR**: prefixes [['OCR_', 40]]; max id length 31; ids with characters outside [A-Za-z0-9_]: 0; most common first token after the prefix: NPC x18, FM x11, MF x11

- OStim regex `^OStim(?P<furn>[A-Za-z]*?)(?P<n>\d)P(?P<desc>.+?)(?P<sex>[MF]{1,5})$` matches 279 of 280 OStim ids. Furniture token: (none) x192, Chair x16, DoubleBedLeft x10, DoubleBedRight x10, SingleBedLeft x10, SingleBedRight x10, Table x9, Shelf x8, CookingPot x6, Wall x5, Bench x3. Sex suffix: MF x208, FF x14, MM x12, F x10, M x9, FFF x3, MFF x3, MMF x3, MMM x3, FFFF x2, MFFF x2, MFFFF x2, MMFF x2, MMMF x2, MMMM x2, MMMMF x2.


OARE scene folders (folder names are NOT part of the scene id):

| OARE folder | scene files |
|---|---|
| Furniture | 46 |
| Standing_holding | 34 |
| Standing_apart | 26 |
| Standing_holding_from_behind | 26 |
| Male_approach_female_all-fours | 20 |
| Female_approach_mounted_female | 19 |
| Both_sitting_female_approach | 15 |
| Male_approach_female_spread_legs | 15 |
| Male_standing_female_kneeling | 13 |
| Both_sitting_male_approach | 12 |
| Spooning | 11 |
| Vampire | 11 |
| Solo | 10 |
| Male_standing_female_squatting | 8 |
| Female_approach_reverse_mounted_female | 7 |
| Both_sitting | 6 |
| Male_approach_female_lying_to_the_side | 5 |
| Female_approach_mounted_female_feet_on_chest | 3 |


OStim scene folders:

| OStim folder | scene files |
|---|---|
| OStim2P | 163 |
| OStim4P | 10 |
| OStimChair2P | 10 |
| OStimDoubleBedLeft2P | 10 |
| OStimDoubleBedRight2P | 10 |
| OStimSingleBedLeft2P | 10 |
| OStimSingleBedRight2P | 10 |
| OStim1P | 8 |
| OStim3P | 8 |
| OStimTable2P | 7 |
| OStimShelf2P | 5 |
| OStim5P | 4 |
| OStimChair3P | 4 |
| OStimCookingPot2P | 4 |
| OStimBench2P | 3 |
| OStimShelf1P | 3 |
| OStimWall2P | 3 |
| OStimChair1P | 2 |
| OStimCookingPot1P | 2 |
| OStimTable1P | 2 |
| OStimWall1P | 2 |


Sequences shipped (all by OCR): 40. Example `OCR_MF_Kiss1` = [{"id": "OCR_MF_BasicIdle", "duration": 1}, {"id": "OCR_MF_Kiss1", "duration": null}, {"id": "OCR_MF_BasicIdle", "duration": 0.5}]


---

## 3. Non-explicit / romantic scenes and how they connect

Selection rule [S]: >= 2 actors and glue tier 1 (affection) or 2 (kissing). Tier 1 = action in {`holdinghand`,`hugging`,`cuddling`,`pattinghead`,`strokinghead`,`holdingchin`,`spooning`,`massaging`} or OARE scene tag `cuddling` or id matching kiss/hug/cuddl/caress/embrace/handhold/flirt/court/lappillow; tier 2 = any of {`kissing`,`frenchkissing`,`kissingcheek`,`kissinghand`,`kissingneck`,`3pp_kissing`} or "kiss" in the id. Any groping / undressing / vampire / spank content pushes a scene to tier 3, any `sexual`-tagged action to tier 4.

Total tier-1/tier-2 scenes with >= 2 actors: **109** (OStim 24, OARE 57, OCR 28). Breakdown by (pack, tier, furniture, transition?):

| pack | tier | furniture | kind | count |
|---|---|---|---|---|
| OARE | 1 | bench | loop | 1 |
| OARE | 1 | cookingpot | loop | 2 |
| OARE | 1 | none | loop | 20 |
| OARE | 1 | none | transition | 8 |
| OARE | 1 | shelf | loop | 4 |
| OARE | 1 | table | loop | 2 |
| OARE | 2 | bench | loop | 1 |
| OARE | 2 | cookingpot | loop | 1 |
| OARE | 2 | none | loop | 13 |
| OARE | 2 | none | transition | 2 |
| OARE | 2 | shelf | loop | 2 |
| OARE | 2 | table | loop | 1 |
| OCR | 1 | none | transition | 24 |
| OCR | 2 | none | transition | 4 |
| OStim | 1 | none | loop | 12 |
| OStim | 1 | none | transition | 3 |
| OStim | 2 | doublebed | loop | 2 |
| OStim | 2 | none | loop | 5 |
| OStim | 2 | singlebed | loop | 2 |


40 representative ids (no-furniture looping scenes first). `T` = one-shot transition that returns to `destination`. `name/tag only` = the scene declares no romantic action; it was recognised from the OARE scene tag `cuddling` or from its id.

| scene id | pack | tier | furn. | sex cfg | T -> destination | declared actions | in/out | tier<=2 out-neighbours |
|---|---|---|---|---|---|---|---|---|
| OARE_SpooningIdle | OARE | 1 | none | AA |  | (none: name/tag only) | 11/9 | OARE_Sitting, OARE_SittingFemaleApproach, OARE_SittingMaleApproach, OARE_SpooningCuddleKiss, OARE_SpooningCuddling1 |
| OARE_HoldingChin | OARE | 1 | none | AA |  | (none: name/tag only) | 3/3 | OARE_HoldingChinHug, OARE_HoldingChinKiss, OARE_StandingHolding |
| OARE_PrincessCarryHandOnFace | OARE | 1 | none | AA |  | (none: name/tag only) | 3/3 | OARE_PrincessCarryEmbrace, OARE_PrincessCarryKiss, OARE_PrincessCarryNeckKiss |
| OARE_SpooningCuddling1 | OARE | 1 | none | AA |  | (none: name/tag only) | 4/2 | OARE_SpooningCuddling2, OARE_SpooningIdle |
| OARE_StandingEmbraceKiss | OARE | 2 | none | AA |  | kissing | 3/3 | OARE_OutstretchedArmsKiss, OARE_StandingKiss |
| OARE_StandingKiss | OARE | 2 | none | AA |  | kissing | 3/3 | OARE_HoldingWaistKiss, OARE_StandingEmbraceKiss, OARE_StandingHolding |
| OARE_CuddleFromBehind | OARE | 1 | none | AA |  | (none: name/tag only) | 2/2 | OARE_HugFromBehind, OARE_StandingHoldingFromBehind |
| OARE_LapPillow | OARE | 1 | none | AA |  | (none: name/tag only) | 2/2 | OARE_LapPillowHeadStroke, OARE_Sitting |
| OARE_OutstretchedArmsKiss | OARE | 2 | none | AA |  | kissing | 2/2 | OARE_StandingEmbraceKiss |
| OARE_PrincessCarryEmbrace | OARE | 1 | none | AA |  | (none: name/tag only) | 2/2 | OARE_PrincessCarryHandOnFace, OARE_StandingHolding |
| OARE_SpooningCuddleKiss | OARE | 2 | none | AA |  | kissing | 2/2 | OARE_SpooningIdle |
| OARE_StandingHandHolding | OARE | 1 | none | AA |  | holdinghand | 2/2 | OARE_GoBackStandingHandHolding, OARE_StandingTwoHandsHolding |
| OARE_StandingHug | OARE | 1 | none | MF |  | hugging | 2/2 | OARE_StandingHandOnHeadCuddle, OARE_StandingHolding |
| OARE_StandingTwoHandsHolding | OARE | 1 | none | AA |  | holdinghand | 2/2 | OARE_StandingHandHolding, OARE_StandingTwoHandsHoldingKiss |
| OARE_SpooningCuddling2 | OARE | 1 | none | AA |  | (none: name/tag only) | 1/2 | OARE_SpooningCuddling1, OARE_SpooningCuddling3 |
| OARE_SpooningCuddling3 | OARE | 1 | none | AA |  | (none: name/tag only) | 1/2 | OARE_SpooningCuddling1, OARE_SpooningCuddling4Kiss |
| OStim2PStandingKissMF | OStim | 2 | none | MF |  | holdingbody,holdinghead,holdinghip,kissing | 8/9 | OStim2PStandingHandHoldingKissMF, OStim2PStandingHoldingChinKissMF, OStim2PStandingHoldingMF, OStim2PStandingKissEmbraceMF, OStim2PStandingKissHoldingHipMF |
| OStim2PStandingHoldingChinKissMF | OStim | 2 | none | MF |  | holdingchin,holdinghand,kissing | 6/10 | OStim2PStandingHandHoldingKissMF, OStim2PStandingHoldingChinMF, OStim2PStandingHoldingMF, OStim2PStandingKissEmbraceMF, OStim2PStandingKissHoldingHipMF |
| OStim2PStandingKissEmbraceMF | OStim | 2 | none | MF |  | hugging,kissing | 4/9 | OStim2PStandingHandHoldingKissMF, OStim2PStandingHoldingChinKissMF, OStim2PStandingHoldingMF, OStim2PStandingKissHoldingHipMF, OStim2PStandingKissMF |
| OStim2PStandingKissHoldingHipMF | OStim | 2 | none | MF |  | holdinghip,kissing | 4/9 | OStim2PStandingHandHoldingKissMF, OStim2PStandingHoldingChinKissMF, OStim2PStandingHoldingMF, OStim2PStandingKissEmbraceMF, OStim2PStandingKissMF |
| OStim2PStandingHandHoldingKissMF | OStim | 2 | none | MF |  | holdinghand,kissing | 6/6 | OStim2PStandingApartMF, OStim2PStandingHandHoldingHighMF, OStim2PStandingKissMF, OStim2PStandingSquattingMF |
| OStim2PKneelingLyingFF | OStim | 1 | none | FF |  | holdinghand | 4/5 | OARE_Sitting, OStim2PKneelingLyingFrontMM, OStim2PSittingFF, OStim2PSittingMF, OStim2PStandingCloseFF |
| OStim2PStandingCuddleFromBehindMF | OStim | 1 | none | MF |  | cuddling | 4/5 | OStim2PStandingHoldingFromBehindMF, OStim2PStandingHugFromBehindMF |
| OStim2PStandingHoldingChinHugMF | OStim | 1 | none | MF |  | holdingchin,hugging | 3/6 | OStim2PStandingCuddleHoldingHeadMF, OStim2PStandingHoldingChinKissMF, OStim2PStandingHoldingChinMF, OStim2PStandingHoldingMF, OStim2PStandingHugMF |
| OStim2PStandingHugFromBehindMF | OStim | 1 | none | MF |  | hugging | 4/5 | OStim2PStandingCuddleFromBehindMF, OStim2PStandingHoldingFromBehindMF |
| OStim2PBothLyingMF | OStim | 1 | none | MF |  | holdinghand | 3/5 | OARE_SpooningIdle, OStim2PDoggyIdleMF, OStim2PLyingKneelingMF, OStim2PLyingOnTopMF, OStim2PSittingMF |
| OStim2PStandingHoldingChinMF | OStim | 1 | none | MF |  | holdingarm,holdingchin,holdinghand | 3/4 | OStim2PStandingHoldingChinKissMF, OStim2PStandingHoldingChinToHoldingChinHugMF, OStim2PStandingHoldingMF, OStim2PStandingSquattingMF |
| OStim2PStandingHugMF | OStim | 1 | none | MF |  | hugging | 3/4 | OStim2PStandingCuddleHoldingHeadMF, OStim2PStandingHoldingChinHugMF, OStim2PStandingHoldingMF, OStim2PStandingSquattingMF |
| OStim2PLyingOnTopMF | OStim | 1 | none | MF |  | holdinghand,strokinghead | 2/4 | OARE_SpooningIdle, OStim2PBothLyingMF, OStim2PDoggyIdleMF, OStim2PSittingMF |
| OStim2PStandingHandHoldingLowMF | OStim | 1 | none | MF |  | holdinghand | 3/3 | OStim2PStandingHandHoldingHighMF, OStim2PStandingHandHoldingToApartMF, OStim2PStandingHeadPattingMF |
| OARE_ActionFemaleCaressCheekStroke | OARE | 1 | none | AA | T -> OARE_Standing | (none: name/tag only) | 1/1 | OARE_Standing |
| OARE_ActionFemaleCaressHug | OARE | 1 | none | AA | T -> OARE_Standing | (none: name/tag only) | 1/1 | OARE_Standing |
| OARE_ActionFemaleFlirt | OARE | 1 | none | AA | T -> OARE_Standing | (none: name/tag only) | 1/1 | OARE_Standing |
| OARE_ActionFemaleKiss | OARE | 2 | none | AA | T -> OARE_Standing | kissing | 1/1 | OARE_Standing |
| OARE_TableIdleSittingFemaleHolding | OARE | 1 | table | AA |  | (none: name/tag only) | 6/5 | OARE_TableIdleSittingFemale |
| OARE_ShelfReadingTogether | OARE | 1 | shelf | AA |  | (none: name/tag only) | 3/3 | OARE_ShelfFemaleBookIdle, OARE_ShelfReadingEyeContact, OARE_ShelfReadingTogetherTA |
| OARE_BenchSitting | OARE | 1 | bench | AA |  | (none: name/tag only) | 3/2 | OARE_ChairIdle |
| OCR_MF_CaressHoldHands | OCR | 1 | none | AA | T -> OCR_MF_BasicIdle | undress_head_minus_circlet_target | 0/1 | OCR_MF_BasicIdle |
| OCR_MF_CaressHug | OCR | 1 | none | AA | T -> OCR_MF_BasicIdle | undress_head_minus_circlet_target | 0/1 | OCR_MF_BasicIdle |
| OCR_MF_Kiss1 | OCR | 2 | none | AA | T -> OCR_MF_BasicIdle | undress_head_minus_circlet_target | 0/1 | OCR_MF_BasicIdle |


Connectivity test (declared edges only, transitions followed through their `destination`):

- sources (standing, 2 actors, no furniture, tier 1-2, kiss / hug / embrace): 24 -> `OARE_ActionFemaleCaressHug`, `OARE_ActionFemaleKiss`, `OARE_ActionMaleCaressHug`, `OARE_ActionMaleKiss`, `OARE_GentleEmbrace`, `OARE_HoldingChinHug`, `OARE_HoldingChinKiss`, `OARE_HoldingWaistKiss`, `OARE_HugFromBehind`, `OARE_OutstretchedArmsKiss`, `OARE_StandingEmbraceCute`, `OARE_StandingEmbraceKiss`, `OARE_StandingHug`, `OARE_StandingKiss`, `OARE_StandingTwoHandsHoldingKiss`, `OStim2PStandingHandHoldingKissMF`, `OStim2PStandingHoldingChinHugMF`, `OStim2PStandingHoldingChinKissMF`, `OStim2PStandingHoldingChinToHoldingChinHugMF`, `OStim2PStandingHugFromBehindMF`, `OStim2PStandingHugMF`, `OStim2PStandingKissEmbraceMF`, `OStim2PStandingKissHoldingHipMF`, `OStim2PStandingKissMF`

- targets (cuddle scenes): 9 -> `OARE_CuddleFromBehind`, `OARE_SpooningCuddleKiss`, `OARE_SpooningCuddling1`, `OARE_SpooningCuddling2`, `OARE_SpooningCuddling3`, `OARE_SpooningCuddling4Kiss`, `OARE_StandingHandOnHeadCuddle`, `OStim2PStandingCuddleFromBehindMF`, `OStim2PStandingCuddleHoldingHeadMF`

| constraint on intermediate nodes | source->target pairs reachable | target->source pairs reachable | of |
|---|---|---|---|
| none (any scene) | 216 | 216 | 216 |
| tier <= 3 (no scene containing a `sexual` action) | 216 | 216 | 216 |
| tier <= 2 (neutral / affection / kissing only) | 216 | 216 | 216 |
| strict: tier <= 2 and not a `staging` scene | 156 | 156 | 216 |


`staging` (script convention) = a tier-0 looping scene that is really the positional idle of an explicit branch (stored per scene as `staging`, with `explicit_out_share`): it has a suggestive posture tag (`allfours`, `bendover`, `spreadlegs`, `ontop`, `onbottom`) or >= 40 % of its folded navigations end in a tier-4 scene. 38 scenes are flagged: `OARE_AceLyingBoobjob_AfterClimax`, `OARE_AceStandingBoobjob_AfterClimax`, `OARE_ChairIdleFemaleFacingAway`, `OARE_ChairKneelingFemale`, `OARE_FemaleAllFours`, `OARE_MaleStandingFemaleKneeling`, `OARE_MaleStandingFemaleSquatting`, `OARE_MountedFemale`, `OARE_ReverseMountedFemale`, `OARE_ShelfIdleBentOver`, `OARE_SittingFemaleApproach`, `OARE_SpreadLegsFemale`, `OARE_StandingHoldingFromBehind`, `OStim2PCowgirlIdleMF`, `OStim2PDoggyIdleMF`, `OStim2PMissionaryIdleMF`, `OStim2PReverseCowgirlIdleMF`, `OStim2PSidewaysIdleMF`, `OStim2PSittingFemaleInMF`, `OStim2PStandingHoldingFromBehindMF`, `OStim2PStandingKneelingMF`, `OStim2PStandingSquattingMF`, `OStimChair2PSittingOnLapMF`, `OStimDoubleBedLeft2PSittingKneelingMF`, `OStimDoubleBedLeft2PSittingOnLapMF`, `OStimDoubleBedLeft2PStandingAllFoursMF`, `OStimDoubleBedRight2PSittingKneelingMF`, `OStimDoubleBedRight2PSittingOnLapMF`, `OStimDoubleBedRight2PStandingAllFoursMF`, `OStimShelf1PBendOverF`, `OStimShelf2PBendOverFF`, `OStimShelf2PBendOverMF`, `OStimSingleBedLeft2PSittingKneelingMF`, `OStimSingleBedLeft2PSittingOnLapMF`, `OStimSingleBedLeft2PStandingAllFoursMF`, `OStimSingleBedRight2PSittingKneelingMF`, `OStimSingleBedRight2PSittingOnLapMF`, `OStimSingleBedRight2PStandingAllFoursMF`


Tier<=2 induced subgraph: 316 nodes, 785 edges. Its 2-actor weak components: [161, 21, 19, 19, 14, 13, 9, 3, 1, 1] ; strong components >1: [151, 21, 14, 13, 9, 3].


Raw hop counts under the tier<=2 constraint (each transition node counts as a hop here): forward min 1 / mean 7.1 / max 13 ; back min 1 / mean 7.0 / max 12.


Worked examples (shortest paths, tier<=2 constraint unless noted):

- `OStim2PStandingKissEmbraceMF` => `OARE_SpooningCuddling1`
  - forward (tier<=2): OStim2PStandingKissEmbraceMF > OStim2PStandingHoldingMF > OStim2PSittingMaleInMF > OStim2PBothLyingMF > OARE_SpooningIdle > OARE_SpooningCuddling1
  - back (tier<=2): OARE_SpooningCuddling1 > OARE_SpooningIdle > OARE_Sitting > OARE_Standing > OStim2PStandingApartMF > OStim2PStandingApartToHoldingMF > OStim2PStandingHoldingMF > OStim2PStandingKissMF > OStim2PStandingKissEmbraceMF
  - forward (strict, no staging scenes): OStim2PStandingKissEmbraceMF > OStim2PStandingHoldingMF > OStim2PSittingMaleInMF > OStim2PBothLyingMF > OARE_SpooningIdle > OARE_SpooningCuddling1
  - back (strict): OARE_SpooningCuddling1 > OARE_SpooningIdle > OARE_Sitting > OARE_Standing > OStim2PStandingApartMF > OStim2PStandingApartToHoldingMF > OStim2PStandingHoldingMF > OStim2PStandingKissMF > OStim2PStandingKissEmbraceMF
  - unconstrained back path for comparison: OARE_SpooningCuddling1 > OARE_SpooningIdle > OARE_Sitting > OARE_Standing > OStim2PStandingApartMF > OStim2PStandingApartHandjobMF > OStim2PStandingHandjobRKissMF > OStim2PStandingKissMF > OStim2PStandingKissEmbraceMF

- `OARE_StandingEmbraceKiss` => `OARE_SpooningCuddling1`
  - forward (tier<=2): OARE_StandingEmbraceKiss > OARE_StandingKiss > OARE_StandingHolding > OARE_GoBackStandingHolding > OARE_Standing > OARE_StandingGoToSitting > OARE_Sitting > OARE_SpooningIdle > OARE_SpooningCuddling1
  - back (tier<=2): OARE_SpooningCuddling1 > OARE_SpooningIdle > OARE_Sitting > OARE_Standing > OARE_GoToStandingHolding > OARE_StandingHolding > OARE_StandingKiss > OARE_StandingEmbraceKiss
  - forward (strict, no staging scenes): OARE_StandingEmbraceKiss > OARE_StandingKiss > OARE_StandingHolding > OARE_GoBackStandingHolding > OARE_Standing > OARE_StandingGoToSitting > OARE_Sitting > OARE_SpooningIdle > OARE_SpooningCuddling1
  - back (strict): OARE_SpooningCuddling1 > OARE_SpooningIdle > OARE_Sitting > OARE_Standing > OARE_GoToStandingHolding > OARE_StandingHolding > OARE_StandingKiss > OARE_StandingEmbraceKiss
  - unconstrained back path for comparison: OARE_SpooningCuddling1 > OARE_SpooningIdle > OARE_Sitting > OARE_Standing > OARE_GoToStandingHolding > OARE_StandingHolding > OARE_StandingKiss > OARE_StandingEmbraceKiss

- `OARE_HugFromBehind` => `OARE_CuddleFromBehind`
  - forward (tier<=2): OARE_HugFromBehind > OARE_CuddleFromBehind
  - back (tier<=2): OARE_CuddleFromBehind > OARE_HugFromBehind
  - forward (strict, no staging scenes): OARE_HugFromBehind > OARE_CuddleFromBehind
  - back (strict): OARE_CuddleFromBehind > OARE_HugFromBehind
  - unconstrained back path for comparison: OARE_CuddleFromBehind > OARE_HugFromBehind

- `OARE_ActionFemaleCaressHug` => `OStim2PStandingCuddleHoldingHeadMF`
  - forward (tier<=2): OARE_ActionFemaleCaressHug > OARE_Standing > OStim2PStandingApartMF > OStim2PStandingApartToHoldingMF > OStim2PStandingHoldingMF > OStim2PStandingHugMF > OStim2PStandingCuddleHoldingHeadMF
  - back (tier<=2): OStim2PStandingCuddleHoldingHeadMF > OStim2PStandingHoldingMF > OStim2PSittingMaleInMF > OStim2PStandingApartMF > OARE_Standing > OARE_ActionFemaleCaressHug
  - forward (strict, no staging scenes): OARE_ActionFemaleCaressHug > OARE_Standing > OStim2PStandingApartMF > OStim2PStandingApartToHoldingMF > OStim2PStandingHoldingMF > OStim2PStandingHugMF > OStim2PStandingCuddleHoldingHeadMF
  - back (strict): OStim2PStandingCuddleHoldingHeadMF > OStim2PStandingHoldingMF > OStim2PSittingMaleInMF > OStim2PStandingApartMF > OARE_Standing > OARE_ActionFemaleCaressHug
  - unconstrained back path for comparison: OStim2PStandingCuddleHoldingHeadMF > OStim2PStandingHoldingMF > OStim2PSittingMaleInMF > OStim2PStandingApartMF > OARE_Standing > OARE_ActionFemaleCaressHug

- `OStim2PStandingHoldingChinHugMF` => `OARE_SpooningCuddleKiss`
  - forward (tier<=2): OStim2PStandingHoldingChinHugMF > OStim2PStandingHoldingMF > OStim2PSittingMaleInMF > OStim2PBothLyingMF > OARE_SpooningIdle > OARE_SpooningCuddleKiss
  - back (tier<=2): OARE_SpooningCuddleKiss > OARE_SpooningIdle > OARE_Sitting > OARE_Standing > OStim2PStandingApartMF > OStim2PStandingApartToHoldingMF > OStim2PStandingHoldingMF > OStim2PStandingHugMF > OStim2PStandingHoldingChinHugMF
  - forward (strict, no staging scenes): OStim2PStandingHoldingChinHugMF > OStim2PStandingHoldingMF > OStim2PSittingMaleInMF > OStim2PBothLyingMF > OARE_SpooningIdle > OARE_SpooningCuddleKiss
  - back (strict): OARE_SpooningCuddleKiss > OARE_SpooningIdle > OARE_Sitting > OARE_Standing > OStim2PStandingApartMF > OStim2PStandingApartToHoldingMF > OStim2PStandingHoldingMF > OStim2PStandingHugMF > OStim2PStandingHoldingChinHugMF
  - unconstrained back path for comparison: OARE_SpooningCuddleKiss > OARE_SpooningIdle > OARE_Sitting > OARE_Standing > OStim2PStandingApartMF > OStim2PStandingApartHandjobMF > OStim2PStandingHoldingMF > OStim2PStandingHugMF > OStim2PStandingHoldingChinHugMF

- `OStim2PStandingHoldingChinHugMF` => `OStim2PStandingCuddleHoldingHeadMF`
  - forward (tier<=2): OStim2PStandingHoldingChinHugMF > OStim2PStandingCuddleHoldingHeadMF
  - back (tier<=2): OStim2PStandingCuddleHoldingHeadMF > OStim2PStandingHoldingChinHugMF
  - forward (strict, no staging scenes): OStim2PStandingHoldingChinHugMF > OStim2PStandingCuddleHoldingHeadMF
  - back (strict): OStim2PStandingCuddleHoldingHeadMF > OStim2PStandingHoldingChinHugMF
  - unconstrained back path for comparison: OStim2PStandingCuddleHoldingHeadMF > OStim2PStandingHoldingChinHugMF


**Answer to the question**: yes. Using only declared edges one can go from a standing kiss/embrace into other scenes and on to a cuddle, and back, in both packs and across packs, without ever entering a scene that contains a sexual action - and even without entering any groping/undressing scene. The canonical cross-pack route is: standing kiss -> `OStim2PStandingHoldingMF` -> sit -> `OStim2PBothLyingMF` -> (origin-form edge) `OARE_SpooningIdle` -> `OARE_SpooningCuddling1`; return: `OARE_SpooningIdle` -> `OARE_Sitting` -> `OARE_Standing` -> `OStim2PStandingApartMF` -> hold transition -> `OStim2PStandingHoldingMF` -> kiss. Caveats: (1) equal-length alternatives through tier-4 scenes exist (see the "unconstrained" lines), so an unconstrained BFS - including OStim's own `getRoute` - may pick them; (2) measured in folded navigations (OStim's unit) the canonical route is 5 forward but 7 back, and the OARE-internal one 6 each way - i.e. beyond the default `NavigationDistanceMax` = 5, where `OThread.NavigateTo` warps instead of walking; (3) "tier 0" is only as good as the metadata: 38 action-less positional idles (e.g. the kneeling / all-fours / mounted idles, the bed "on lap" idles, and both "holding from behind" hubs) are really the staging poses of explicit branches. With those excluded (`strict` row) 156 of 216 pairs still connect both ways; **all 60 failures involve the four "from behind" hug/cuddle scenes** (`OARE_HugFromBehind`, `OARE_CuddleFromBehind`, `OStim2PStandingHugFromBehindMF`, `OStim2PStandingCuddleFromBehindMF`), whose only entrance is a from-behind hub with 45-46 % tier-4 exits (just over the script's 40 % threshold - a policy knob). Every face-to-face kiss/hug scene reaches every spooning / standing cuddle scene and back under the strict rule (strict forward length min 1 / mean 6.9 / max 12 raw hops); (4) OCR's 38 one-shot interaction scenes are islands (in-degree 0, they end in `OCR_*_BasicIdle`, which has out-degree 0) - they are meant to be played as sequences, not navigated to.

---

## 4. OARE specifics

- **Identity / version** [V] `OARE\meta.ini:2-4,8,30`: `gameName=SkyrimSE`, `modid=98732`, `version=1.52.1.0`, `installationFile=Open Animations RE for OStim Standalone-98732-1-52-1-1743384014.7z`; FOMOD choice recorded (line 30): "Install custom OARE icons". Author per LICENSE line 1: "Copyright acenetizen 2024". Requirements per the stored Nexus description: OStim, OStim Community Resource (Nexus 106519), Nemesis run. (OCR: `OCR\meta.ini` `modid=106519`, `version=1.17.6.0`. OStim: `OSTIM\meta.ini` `modid=98163`, `version=7.5.1.0`.)
- **Folder structure** [V]: `LICENSE`; `Interface\OStim\icons\OARE\` (251 dds) + `Interface\OStim\icons\OStim\symbols\` (3 dds overriding OStim's alignment/search/settings symbols); `meshes\0SA\mod\0Sex\anim\{OpS,OpS_furniture,OpS_interaction,OpS_solo,redressing}` (1174 hkx, legacy OSA layout); `meshes\OARE_animations\<branch>\...` (392 hkx, newer content); `meshes\actors\character\animations\openani_{main,furniture,interaction,redress,solo}\FNIS_*_List.txt` + `behaviors\FNIS_openani_*_Behavior.hkx` (5); `SKSE\Plugins\OStim\scenes\<18 branch folders>` (287 json); `SKSE\Plugins\Devour\OARE.json`. **No .esp/.esl, no scripts, no MCM, no ini.** Generated behaviour output lives in the separate mod `Nemesis Output - OStim`.
- **Scene branches** (folder = design branch, not part of the id): `Standing_apart` 26, `Standing_holding` 34, `Standing_holding_from_behind` 26, `Both_sitting` 6, `Both_sitting_male_approach` 12, `Both_sitting_female_approach` 15, `Male_standing_female_squatting` 8, `Male_standing_female_kneeling` 13, `Male_approach_female_spread_legs` 15, `Male_approach_female_lying_to_the_side` 5, `Male_approach_female_all-fours` 20 (incl. subfolder `50`), `Female_approach_mounted_female` 19, `Female_approach_mounted_female_feet_on_chest` 3, `Female_approach_reverse_mounted_female` 7, `Spooning` 11, `Furniture` 46 (chair 8, bench 7, table 10, shelf 11, cookingpot 10), `Vampire` 11, `Solo` 10 (male only).
- **Conventions** [S]/[V]: every id starts with `OARE_`; hubs are tagged `idle` (+`oare`), hand-written `transition` tag on 36 files, `action` tag on the 8 one-shot `OARE_Action*` transitions, `cuddling` tag on 41 non-explicit scenes; `GoTo*` / `GoBack*` prefixes mark positional transitions; `Ace*` = contributed set. Actors are mostly `intendedSex: "any"` (140 of 277 two-actor scenes `AA`, 88 `MF`, 33 `MA`, 16 `AF`), i.e. OARE relies on action requirements rather than intended sex. Animation event names are legacy OSA style (`_Ho-Standing`, `_Ap-MalePC_Kiss1`).
- **Entry points into OARE** = the 41 origin-form edges listed in `ostim_scene_stats.json` (`edges[]` with `forms` containing `origin`): from `OStim2PStandingApartMF/MM`, `OStim2PStandingCloseFF` -> `OARE_Standing`; `OStim2PSittingMF`, `OStim2PKneelingLyingFF`, `...FrontMM` -> `OARE_Sitting`; `OStim2PBothLyingMF`, `OStim2PLyingOnTopMF` -> `OARE_SpooningIdle`; lying/kneeling idles -> `OARE_SittingMaleApproach` / `OARE_SittingFemaleApproach`; `OStim1PStandingM` -> `OARE_MaleSoloStandingIdle`; chair/bench/table/shelf/cookingpot idles -> the matching `OARE_<Furniture>Idle*`. 19 destination-form edges lead back.
- **Config**: the only config-like file is `OARE\SKSE\Plugins\Devour\OARE.json` (lists `baseScenes*` / `replacerScenes*` with legacy `OpS|...` ids for a "Devour" vampire mod - irrelevant unless that mod is installed; a case-insensitive search of `profiles\Ultra\modlist.txt` for "devour" finds only the unrelated `Soul Devourer Ring` (line 1721), so no consumer of this file is installed [I]). OStim-side behaviour (intended-sex-only, navigation distance, intro scenes) is OStim MCM state, not OARE.
- **Data quality quirks** [S]: `OARE_ActionFemaleCaressHoldHands` has `"speeds": []` and in-degree 0 (dead stub - never select it); in-degree 0 also for `OARE_AceBackItUp`, `OARE_CowgirlCircularGrind`, `OARE_GoToReverseCowgirlMounted1`, `OARE_HandOnHeadBlowjob`; undefined action types `sleeping`, `teasing`; six auto-transition targets with non-existent legacy ids.
- **LICENSE** [V] `OARE\LICENSE` (custom licence, 33 lines). Relevant clauses: line 6 "The content" = "all digital materials included in this release, including animations and icons (.dds files)"; line 10 personal and commercial use allowed; line 11 "The animations included in this release may not be reused in other animation sets for the OStim framework or as part of the animations that come with the OStim framework itself. It also may not be used for other animation frameworks."; line 14 "Users are not allowed to modify the icons (.dds files) and are not allowed to modify scene data (.json) to use other icons."; lines 18-21 attribution, share-alike, disclosure of source/work files and notice retention when distributing modified versions; line 30 automatic termination on breach.
  - **What this means for integration (not redistribution)** [I]: the glue never copies, modifies or redistributes OARE files; it only reads the user's installed JSON at index time and passes scene ids to OStim at run time. None of the restrictions (reuse of animations in other packs/frameworks, icon/JSON modification, share-alike on modified copies) is triggered by that. Do **not** ship OARE scene JSON, icons, hkx, or an edited copy of them; do **not** patch OARE JSON on disk (even an "origin"-style hook should live in the glue's own scene files - which the glue does not need at all). Shipping a derived *index* of the user's own installation generated locally is not distribution; shipping a pre-built index of OARE metadata inside the glue's release is a grey area -> generate it on the user's machine (the script already does). This is an engineering reading, not legal advice.

---

## 5. Recommended generic, data-driven algorithms

All of these operate on the index produced at install/start-up time by a port of `ostim_scene_stats.py` (PHP on HerikaServer reading `/mnt/f/...` is possible, or ship the Python and store the JSON/DB rows), never on hard-coded scene ids. Hard-coded ids are only acceptable as *preferences* that are validated against the index.

### 5a. Choosing a gentle starting scene valid for given actors / furniture

Inputs: ordered actors (use `OActorUtil.Sort(Actors, DominantActors, PlayerIndex)` or replicate: male-intended slot first), each actor's sex, thread furniture type `F` (`OFurniture.GetFurnitureType(ref)`; `none` when no furniture), max tier `T` (start with 0).
```
chain(F)      = [F, supertype(F), ...]            # from furniture_types; e.g. doublebed -> bed -> none
candidates    = scenes where  not is_transition
                          and actor_count == len(actors)
                          and furniture in chain(F)          # prefer the most specific type first
                          and tier <= T and staging == false     # staging: see section 3 (positional idles of explicit branches)
                          and noRandomSelection == false
                          and n_speeds >= 1                  # excludes the dead stub
                          and out_degree > 0                 # excludes OCR idles used only by sequences
                          and sex_ok(scene, actors)          # each slot: intendedSex in (any, actor sex)
                          and ("idle" in tags or "intro" in tags)
score         = +4 furniture == F exactly, +3 has tag "intro", +2 all actors tagged "standing" (when F == none;
                for bed prefer NOT standing - mirrors OStim's own rule), +2 hub degree (log), +1 number of tier<=2
                out-neighbours, -5 if any requirement of its actions is not met
pick          = argmax(score), ties broken randomly
```
With the installed data this yields, for a no-furniture pair: `OStim2PStandingApartMF` (MF, tags idle+intro, 15 out-edges incl. hold / hold hand / head pat / the OARE hub), `OStim2PStandingApartMM`, `OStim2PStandingCloseFF` for same-sex pairs, or `OARE_Standing` (any/any, 25 out-edges of which 9 are tier 1-2 one-shot or looping affection options). For furniture: `OStimBench2PBothSittingMF`, `OStimChair2PSittingStandingMF`, `OStimTable2PStandingLeaningMF`, `OStimDoubleBedLeft2PBothSittingMF` etc. (full lists in section 2.10 / the JSON). Then start with `OThreadBuilder.Create` -> `SetStartingAnimation(id)` (+ `SetFurniture` or `NoFurniture`, `NoAutoMode` so OStim's auto mode does not escalate on its own, optionally `NoUndressing`) -> `Start`. Run-time validation: after `Start`, confirm with `OThread.GetScene(0)`; optionally pre-validate with `OLibrary.GetScenesInRange(id, Actors, 1)` being non-empty.
Fallback: if no candidate, call `OThread.QuickStart(Actors)` with no animation and let OStim pick its own `intro`/`idle` scene, then read `GetScene`.

For a purely non-explicit "moment" (kiss / hug / hold hands, then end), prefer OCR sequences: `OThreadBuilder.SetStartingSequence(BuilderID, "OCR_MF_Kiss1")` + `EndAfterSequence` + `NoFurniture` - exactly what `OCR\Source\Scripts\OCR_OStimUtil.psc:6-23` does. Sequence ids: `OCR_{MF|FM}_{CaressCheekStroke|CaressFail|CaressHoldHands|CaressHug|Chatter|ChatterFail|Court|CourtFail|Kiss1|StandingConversation|StandingConversationLoop}` and `OCR_NPC_{MF|FM}_...` (NPC-initiated variants; no conversation ones). OCR picks `MF` when the player is male, `FM` otherwise (`OCR_OStimSequencesUtil.psc:11-18`).

### 5b. Path-finding between two scenes over declared navigations

Graph: nodes = scenes of the thread's bucket (same actor count; furniture in `chain(F)` both ends compatible); edges = index `edges[]`. Treat a transition as an ordinary node with exactly one out-edge (its `destination`) - or pre-fold chains like OStim does; both give the same routes.

```
route(src, dst, maxTier, actors):
    allowed(n) = tier(n) <= maxTier and sex_ok(n, actors) and req_ok(n, actors) and n_speeds(n) >= 1
                 and not (maxTier <= 2 and staging(n))        # optional strict mode; src and dst are always allowed
    if dst is a transition: remember it, set dst' = a predecessor hub of dst (in_from), route to dst', then append dst
    Dijkstra from src over allowed nodes with edge cost:
        1                       for a loop scene
        0.25 + length/10        for a transition (they are short and look natural)
        +3                      if tier(next) > tier(current) + 1      (avoid sudden escalation)
        +1                      if the edge leaves the current pack     (keeps visual style consistent; optional)
    plain BFS is enough if costs are not wanted (the graph has 607 nodes / 1629 edges; BFS is microseconds)
```
Execution options, in order of preference:
1. **Hop-by-hop under glue control**: for each loop scene on the route call `OThread.QueueNavigation(0, sceneId, holdSeconds)`; adjacent hops always resolve through OStim's own `getRoute` at distance 1, including the folded transition animation. This is the only way to guarantee the tier constraint.
2. **Delegate**: if `folded_distance(src,dst) <= NavigationDistanceMax` (read the global `OStimNavigationDistanceMax`) and *every* shortest folded route is within `maxTier`, a single `OThread.NavigateTo(0, dst)` is fine.
3. **Fallback = warp**: when no allowed route exists (different weak component, e.g. OCR islands, 3+ actor idles, or the constraint cuts the graph) use `OThread.WarpTo(0, dst, true)` (fade) - posture jumps are hidden by the fade. Use it also when the route is longer than ~6 loop scenes; walking 8 scenes at >= 0.5 s each plus transitions is slower and stranger than a fade.
4. Different furniture bucket (e.g. move from standing to a bed): there are declared edges `none <-> doublebed/singlebed` via `OStim2PSittingMF` (4 each way) that only appear when the thread already has a bed; otherwise `OThread.ChangeFurniture(ThreadID, FurnitureRef, SceneID)` (`OThread.psc:288`) then route inside the new bucket.
Guards: never route *to* in-degree-0 scenes (list in 2.9) - warp to them or skip them; re-plan on every `ostim_scenechanged` because the player can still click the OStim menu; treat "scene changed to something not on my route" as user override, not an error.

### 5c. Mapping a natural-language request onto the index

Two-stage, deterministic-first:
1. **Intent extraction by the LLM into a closed vocabulary**, not free text: `{act: <canonical action type | null>, scene_tag: <tag | null>, posture: {actor0?: tag, actor1?: tag}, who: initiator index, furniture: <type | null>, intensity: tier cap, direction: "escalate" | "deescalate" | "stay" | "stop"}`. The vocabulary is generated from the index: canonical action types present in >= 1 installed scene (57), their aliases (214, already natural words: "kiss", "hug", "cuddle", "holdhands", "spoon", "massage", ...), scene tags (17), actor posture tags (19), furniture types (9 in use). Give the LLM only the subset allowed by the current consent/tier gate so it cannot select above the gate.
2. **Resolution against the index**: candidate set = scenes in the current bucket, `tier <= cap`, whose `actions[]` contain `act` with matching actor/target orientation when `who` is given (action `actor` index = initiator for one-sided actions such as `hugging`, `cuddling`; `kissing` / `holdinghand` are two-sided per their `info` strings, `actions\kissing.json:2`, `actions\holdinghand.json:7`), and/or matching `scene_tag` / posture tags. Because metadata is incomplete (section 3), extend matching with (a) OARE scene tag `cuddling`, (b) tokenised ids / English names (`name_en`), (c) the **navigation descriptions of edges leaving the current scene** - these are the labels a human would click ("Kiss {1}.", "hold {1}s hand", "Hug {1}.", "Sit down with {1}.", "Return...") and 1515 of 1629 edges have one. Rank: current-scene out-edges first (distance 1), then by route cost from 5b, then hub-ness; pick the best and execute via 5b. Placeholders `{0}`/`{1}` tell who acts on whom: in OARE, labels starting with "({1})" are initiated by actor 1.
3. Relative requests ("something gentler", "go back", "lie down", "stop"): gentler = nearest node with lower tier (BFS over allowed nodes); "go back" = the out-edge with priority -1000 / description matching return; posture change = nearest tier-capped node whose actor tags contain the requested posture; "stop" = `OThread.Stop(0)`.
Never let the LLM emit raw scene ids; if it must choose among candidates, present at most ~8 numbered neutral descriptions (5d) and accept an index.

### 5d. Short neutral human-readable scene descriptions from metadata

Template (implemented as `describe()` in the script; stored per scene as `description`):
```
<N> actors: actor i (<intendedSex>[, <posture tags>]); ... | [furniture: <type>] | actions: <type> a->t, ... | [one-shot transition (<length>s) -> <destination>] | tags: ...
```
Examples produced from the installed data:
- `OStim2PStandingApartMF` -> "2 actors: actor 0 (male, standing); actor 1 (female, standing) | no actions (idle/pose) | tags: idle,intro"
- `OStim2PStandingKissEmbraceMF` -> "2 actors: ... standing ... | actions: kissing 0->1, hugging 0->1, hugging 1->0"
- `OARE_ActionMaleKiss` -> "... | actions: kissing 0->1 | one-shot transition (5s) -> OARE_Standing | tags: action,oare"
- `OARE_SpooningCuddling1` -> "2 actors: actor 0 (any, lyingside); actor 1 (any, lyingside) | no actions (idle/pose) | tags: cuddling,oare" (name_en "Lying Cuddling")
For prompts, render a sentence instead: substitute actor names for indices, map posture tags to words (`lyingside` -> "lying on their side", `facingaway` -> "facing away"), map action types through a small neutral lexicon keyed by canonical type ("kissing" -> "are kissing", "holdinghand" -> "are holding hands", tier-4 types -> one clinical phrase per action-tag family such as "intercourse", "oral contact", "manual stimulation" chosen from the action-definition tags `intercourse`, `oral`, `fellatio`, `cunnilingus`, `fingering`, `penilestimulation`), append the English scene name when it adds information, and append furniture. Where a scene has no actions and no informative tags, fall back to the English name from `OScenes_ENGLISH.txt` / the `name` field (565 of 607 scenes resolve to a readable English name; the 40 OCR scenes use their id as name and 2 OStim scenes reference a missing translation key).

---

## Implications for the glue (concrete recommendations)

1. **Build the index on the user's machine** at server-plugin install / on demand (scan every `mods\*\SKSE\Plugins\OStim\scenes` of the active profile or, more robustly, ask the game side for `OLibrary.GetAllScenes()` and reconcile). Store scenes, edges, tiers, descriptions in the HerikaServer DB; key everything by lower-cased scene id.
2. **Resolve action aliases and use action-definition tags** for the hard gate: a scene is "explicit" iff any action's definition carries `sexual` (231 scenes). Add the glue tiers on top for finer steps; treat tier 3 (groping / undressing / vampire / spank) as gated too, and treat the 38 `staging` idles (tier 0 by metadata, but positional poses of explicit branches - list in section 3) as gated for non-explicit sessions. Do not trust scene tags alone (only 460 of 607 files have any, and OStim's are positional, not content ratings).
3. **Hard-gate in code, not in the prompt**: the LLM receives only candidates at or below the currently consented tier; the game side re-checks the tier of any scene id it is asked to play against a table shipped from the server (or recomputed via `OMetadata.FindAnyActionCSV(id, <sexual action list>) == -1`).
4. **Start gentle**: use 5a (tier-0 idle hub, `NoAutoMode`, consider `NoUndressing`) or an OCR sequence with `EndAfterSequence` for a one-off kiss/hug. OCR scenes need no navigation at all and leave the actors dressed except head gear (`undress_head_minus_circlet_target`).
5. **Navigate with `OThread.QueueNavigation` hop-by-hop along a glue-computed route** when staying within a tier matters; use `OThread.NavigateTo` only when every short route is acceptable; fall back to `OThread.WarpTo(.., true)`. Remember `NavigateTo(<transition id>)` warps.
6. **One-shot acts are transitions**: to "kiss once" from `OARE_Standing`, navigate to `OARE_ActionMaleKiss` / `OARE_ActionFemaleKiss` (they return by themselves after 5 s); to "keep kissing", pick a looping scene (`OARE_StandingKiss`, `OStim2PStandingKissMF`, ...). The index flag `is_transition` + `destination` tells the two apart.
7. **Scene awareness**: subscribe to `ostim_scenechanged` / `ostim_thread_scenechanged`, look the id up in the index, send the 5d description + tier + available out-edge labels (tier-capped) to CHIM as context. Also send `ostim_start` / `ostim_end`.
8. **Actor order matters**: index slot 0 is the male-intended / "initiator" slot in OStim's MF scenes; decide who is slot 0 before starting (`OActorUtil.Sort`), and phrase descriptions from `actors[]` of the running thread (`OThread.GetActors`, `GetActorPosition`).
9. **Respect OARE's licence**: read-only use of the installed files and ids; ship no OARE assets, no edited OARE JSON, no prebuilt OARE index.
10. **Be robust to pack changes**: nothing above depends on OARE being present; with only OStim installed the same algorithms work on 280 scenes (24 affection/kissing scenes). With more packs the origin-form edges automatically extend the graph.

## Open questions

1. Does the installed OStim 7.5.1 DLL match GitHub `main` for the quoted loader/routing code (folded navigations, `getRoute`, strict key case)? Verify in game: `OLibrary.GetScenesInRange("OStim2PStandingApartMF", actors, 1)` should return folded targets such as `OStim2PStandingHoldingMF` rather than the transition id.
2. Current MCM state in this LoreRim profile: `NavigationDistanceMax`, `UseIntroScenes`, intended-sex-only, unrestricted navigation, auto-mode settings (they change which scenes OStim itself will pick and whether `NavigateTo` walks or warps). Not readable from files without a save; read the globals at run time.
3. Behaviour of `NavigateTo`/`WarpTo` into a transition while the player thread has player control: does the thread reliably continue to `destination` (expected from `isTransition` handling in `Thread::ChangeNode`, not opened)?
4. Meaning of action tags prefixed with `-` (`-penilestimulation`, `-vaginalstimulation`) - NOT FOUND in shipped docs; check `Graph/Action` sources (`mergeTags`).
5. How OStim orders actors when the player is female and the NPC male for `any/any` OARE scenes (slot assignment decides who "initiates" in labels such as "({1}) Kiss {0}."); `OActorUtil.Sort` doc comment was not opened in full.
6. Whether LoreRim intends same-sex pairs to use OARE's `any/any` scenes with intended-sex-only ON (OStim's own FF/MM coverage is thin: 14 FF and 12 MM two-actor scenes, 25 of the 26 tagged `idle`, none above tier 1).
7. Is `OARE_StandingHoldingFromBehind`-style posture acceptable as "gentle" for the project's consent model, or should "facingaway" postures require a higher tier? (Policy decision, the index exposes the tag.)
8. The Devour integration file references scene ids that do not exist in this install; confirm no mod in LoreRim consumes `SKSE\Plugins\Devour\OARE.json`.
