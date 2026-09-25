# OStim Standalone 7.5.1 - Papyrus API, mod events, navigation and settings (from the INSTALLED LoreRim files)

Research date: 2026-09-21. Phase 0, read-only. No code written, nothing under `F:\Modlists` or the WSL distro was modified.

## Executive summary (10 lines)

1. Installed OStim is **7.5.1** (`meta.ini` `version=7.5.1.0`; DLL `FileVersion 7.5.1.0`; the main script hard-checks `SKSE.GetPluginVersion("OStim") == 0x07050010`). DLL is built on CommonLibSSE-NG 7.1.0 (Address-Library, multi-runtime); the LoreRim runtime is 1.6.1170 (skse64.log `01064920`). API version == plugin version (`OSexIntegrationMain.GetAPIVersion()`).
2. 29 `.psc` files, **630 native global functions**. The modern API is 100 % static (`Global Native`): `OThread`, `OThreadBuilder`, `OPlayerThread`, `OActor`, `OActorUtil`, `OLibrary` (97), `OMetadata` (280), `OSequence`, `OFurniture`, `OJSON`, `OEvent`, `OActionData`. `OSexIntegrationMain` is now a thin legacy wrapper over these plus the MCM settings as properties backed by GlobalVariables.
3. The player thread is **always ThreadID 0**; NPC threads have positive IDs. `OThreadBuilder.Start()` / `OThread.QuickStart()` return `0` for a player thread *immediately* - the real start is asynchronous (waits until no actor is in dialogue, may show message boxes, may fade). Confirm with `ostim_thread_start` (numArg 0) or poll `OThread.IsRunning(0)`.
4. Recommended start: `OThreadBuilder.Create(actors)` -> `SetStartingAnimation` -> `SetFurniture` **or** `NoFurniture` -> optional `SetDominantActors / UndressActors / NoAutoMode / NoPlayerControl / NoPostDialogue / SetMetadataCSV` -> `Start`. If a starting animation is set, OStim does **not** sort actors and does **not** show add-actor / role prompts; you must order actors yourself (`OActorUtil.Sort`). Passing explicit furniture or `NoFurniture` avoids the bed/furniture message boxes (important for a "menuless" integration).
5. Navigation: `OThread.NavigateTo` = graph route with transition scenes, falls back to a warp if no route within the MCM "navigation distance"; `QueueNavigation` = same but appended after the running navigation with a dwell time; `WarpTo` / `QueueWarp` = snap (optional fade); `PlaySequence`, `AutoTransition(type)`, `ChangeFurniture` also move the thread. `OLibrary.GetScenesInRange(scene, actors, distance)` exposes OStim's own navigation graph neighbourhood.
6. 15 mod-event names are in the DLL (`ostim_prestart, ostim_thread_start, ostim_start, ostim_scenechanged, ostim_scenechanged_<id>, ostim_animationchanged, ostim_thread_scenechanged, ostim_thread_speedchanged, ostim_totalend, ostim_end, ostim_orgasm, ostim_thread_end, ostim_furniturechanged, ostim_actor_orgasm, ostim_spank`) plus `ostim_event` sent from `OSKSE.psc`. End events carry a JSON string readable with `OJSON.GetActors/GetScene/GetMetadata`.
7. Scene search: `OLibrary.GetRandomSceneSuperloadCSV(...)` (45 optional CSV filters: furniture type, scene tags, per-actor tags, action types with actor/target/performer constraints, white/blacklists). Metadata: `OMetadata.GetName, IsTransition, GetActorCount, GetDefaultSpeed, GetMaxSpeed, GetSceneTags, GetActorTags, GetActionTypes/Actors/Targets/Performers, GetAutoTransitionForActor, HasRequirement ...`. **There is no OMetadata furniture-type getter**; furniture type is only available per running thread (`OThread.GetFurnitureType`) or per reference (`OFurniture.GetFurnitureType`).
8. Actor validity: `OActor.VerifyActors(Actor[])` (native), `OThreadBuilder.Create` returns -1 for an invalid actor, `OActorUtil.GetActorsInRangeV2(..., OStimActorsOnly = true)`, `OUtils.IsChild(actor)` (Papyrus: `Actor.IsChild()` OR race/name contains "Child"). The DLL's eligibility = not disabled, not deleted, not child, not dead, has an OStim actor type (perk `OStimNPCCondition`: ActorTypeNPC keyword, not ManakinRace, not child), not already in a thread.
9. Settings live in GlobalVariables in `OStim.esp` (readable/writable through `OSexIntegrationMain` properties) and are exported/imported as JSON at `Documents\My Games\Skyrim Special Edition\OStim\mcmsettings.json` (legacy `JCUser\OstimMCMSettings.json`); the file does not exist yet on this machine (OStim has not been run since it was added to the profile). Auto-mode, end-on-orgasm, undress, furniture and role settings are all overridable per-thread via the builder flags, except end-on-orgasm (global only; work around with `OThread.StallClimax`).
10. OStim Community Resource 1.17.6 = shared romance-mod assets (extra action/expression/scene/sequence JSONs, NPC assets, "private cell" and attraction/commitment quests) with thin Papyrus helpers (`OCR_OStimUtil.StartOstimSequence`, `OCR_OStimScenesUtil.OCR_StartScene*`) that simply call `OThreadBuilder` / `OThread.QuickStart`.

## Conventions used in this report

| Abbrev | Meaning |
|---|---|
| `OST\` | `F:\Modlists\LoreRim\mods\OStim Standalone - Advanced Adult Animation Framework\` |
| `SRC\` | `OST\Scripts\Source\` (29 `.psc`, all 29 have a compiled `.pex` in `OST\Scripts\`) |
| `DATA\` | `OST\SKSE\Plugins\OStim\` (README / list files and JSON data folders) |
| `OCR\` | `F:\Modlists\LoreRim\mods\OStim Community Resource\` |
| DLL-str N | line N of `strings -a -n 4 OStim.dll` run inside WSL on `/mnt/f/.../SKSE/Plugins/OStim.dll` (8 852 480 bytes, dated 2026-09-02). Line numbers only document ordering/adjacency. |
| UPSTREAM | `https://raw.githubusercontent.com/VersuchDrei/OStimNG/main/skse/src/...` fetched 2026-09-21 through WebFetch. The DLL embeds the same source paths (`C:\Git\GitHub\OStimNG\skse\src\...`) and, where checked, the same strings in the same order, but `main` is **not guaranteed** to be byte-identical to the 7.5.1 build. Everything tagged UPSTREAM is therefore "VERIFIED in upstream source, INFERRED for the installed binary". |

VERIFIED = copied from an installed file I opened. INFERENCE = my reasoning; flagged explicitly.

No other mod in `F:\Modlists\LoreRim\mods\*\Scripts\` overrides `OThread.pex`, `OActor.pex`, `OUndress.pex` or `OSexIntegrationMain.pex` (Glob check) - the API below is what actually loads.

---

## 1. Every Papyrus script and function

### 1.0 Script inventory (VERIFIED, counts machine-extracted - full signature index with line numbers is in **Appendix A**)

| Script | Kind | Functions | Purpose |
|---|---|---|---|
| `OThread` | static | 37 native | start (QuickStart), query and control any thread by ThreadID |
| `OThreadBuilder` | static | 19 native + `Example` | build a thread with options, then `Start` |
| `OPlayerThread` | static | 1 native | `SetPlayerControl(bool)` for thread 0 |
| `OActor` | static | 41 native + 4 deprecated Papyrus | per-actor state inside a scene: excitement, climax, expressions, mute, undress, equip objects, metadata, `IsInOStim`, `GetThreadID`, `VerifyActors` |
| `OActorUtil` | static | 12 native + 2 Papyrus | `HasSchlong`, perk-condition checks, `SayTo/SayAs`, arrays, `Sort`, `GetActorsInRangeV2`, `ActorsToNames` |
| `OLibrary` | static | 97 native | scene library search (`GetRandomScene*`, `GetAllScenes`, `GetScenesInRange`) |
| `OMetadata` | static | 280 native + 2 deprecated | per-scene metadata getters / action finders / custom action data |
| `OSequence` | static | 12 native | random sequence search |
| `OFurniture` | static | 7 native | furniture type / find furniture / offsets |
| `OJSON` | static | 3 native | decode the JSON string of `ostim_end` / `ostim_thread_end` |
| `OEvent` | static | 1 native | `IsChildOf(SuperType, SubType)` for event types |
| `OActionData` | static | 3 native | does a Skyrim actor fulfil an action's actor/target/performer conditions |
| `OCSV` | static | 10 Papyrus | build/split CSV lists (`,`) and matrices (`;`) |
| `OIntUtil` | static | 1 native | `CreateArray(int Size, int Filler = 0)` |
| `OUtility` | static | 3 native | `Translate`, `ShuffleFormArray`, `GetQuestsWithGlobal` |
| `OUtils` | static | 54 Papyrus | `GetOStim()`, `IsChild`, misc helpers (uses PapyrusUtil, JContainers, NiOverride, MiscUtil) |
| `OData` | static | 34 native | INTERNAL: settings import/export/reset, stimulation tables, `ReloadScene` |
| `OSettings` | static | 33 native | INTERNAL: data-driven MCM pages |
| `OUndress` | static | 4 native + 7 Papyrus | INTERNAL hook: override `UsePapyrusUndressing()` to take over undressing |
| `OSKSE` | static | 10 Papyrus | INTERNAL: called *by the DLL* (`SendOStimEvent`, fades, NiOverride relays) |
| `OSANative` | static | 42 native + 10 Papyrus | legacy OSA natives (sex/race lookup, FindBed, mutex `TryLock/Unlock`, `RandomInt`...) |
| `OAIUtils` | static | 3 Papyrus | legacy auto-mode scene pickers built on the superload search (good worked examples) |
| `OStimAddon` | `Extends Quest Hidden` | 11 | legacy base class for addons (`InstallAddon`, `RegisterForOEvent`, event stubs) |
| `OStimSubthread` | `extends ReferenceAlias` | 27 | legacy NPC-thread wrapper; "use OThread instead" |
| `OStimPlayerAliasScript` | `Extends ReferenceAlias` | 2 | `OnPlayerLoadGame` -> `OStim.OnLoadGame()` |
| `OSexIntegrationMain` | `Extends Quest` (form `0x000801` in `Ostim.esp`) | ~125 functions/events + ~150 properties | MCM settings as properties + deprecated wrappers |
| `OBarsScript`, `OSexBar` | Quest / SKI_WidgetBase | 19 / 11 | excitement bar widgets |
| `OSexIntegrationMCM` | `Extends SKI_ConfigBase` | 71 top-level | MCM |

Get the main quest script: `OUtils.GetOStim()` = `game.GetFormFromFile(0x000801, "Ostim.esp") as OSexIntegrationMain` (`SRC\OUtils.psc:3-6`).

### 1.1 OThread (`SRC\OThread.psc`) - header: "the thread containing the player will always have the ThreadID 0 / NPC on NPC threads will always have positive ThreadIDs / required API Version: 7.0 (29)" (lines 1-7)

```papyrus
int Function QuickStart(Actor[] Actors, string StartingAnimation = "", ObjectReference FurnitureRef = None) Global Native ; L29  start without builder; async if player involved; returns ThreadID or -1
bool Function IsRunning(int ThreadID) Global Native                                  ; L46  thread still running
Function Stop(int ThreadID) Global Native                                            ; L53  ends the thread
int Function GetThreadCount() Global Native                                          ; L60  number of running threads incl. player thread
int[] Function GetAllThreadIDs() Global Native                                       ; L69  all running thread IDs (API 7.3.4)
string Function GetScene(int ThreadID) Global Native                                 ; L86  current scene id; "" during startup / after end
Function NavigateTo(int ThreadID, string SceneID) Global Native                      ; L96  graph navigation; cancels running navigation; warps if no route
Function QueueNavigation(int ThreadID, string SceneID, float Duration) Global Native ; L109 navigate after the current navigation is done; Duration = seconds to stay before next queued step (API 7.3.4b)
Function WarpTo(int ThreadID, string SceneID, bool UseFades = False) Global Native   ; L119 snap to scene; cancels running navigation
Function QueueWarp(int ThreadID, string SceneID, float Duration) Global Native       ; L131 warp after current navigation is done (API 7.3.4c)
bool Function AutoTransition(int ThreadID, string Type) Global Native                ; L141 play the scene-level auto transition of that type; true if it exists and was played
bool Function AutoTransitionForActor(int ThreadID, int Index, string Type) Global Native ; L152 same, actor-level
int Function GetSpeed(int ThreadID) Global Native                                    ; L161 speed index; -1 during startup / after end
Function SetSpeed(int ThreadID, int Speed) Global Native                             ; L170 set speed index (clamped)
Function PlaySequence(int ThreadID, string Sequence, bool NavigateTo = false, bool UseFades = false) Global Native ; L181 play a sequence (API 7.1e)
Actor[] Function GetActors(int ThreadID) Global Native                               ; L198
Actor Function GetActor(int ThreadID, int Index) Global Native                       ; L208
int Function GetActorPosition(int ThreadID, Actor Act) Global Native                 ; L218 index of actor, -1 if not in thread
Function StallClimax(int ThreadID) Global Native                                     ; L234 block climaxes for all actors (incl. auto-climax animations not yet started)
Function PermitClimax(int ThreadID, bool PermitActors = false) Global Native         ; L242 undo StallClimax (optionally also the per-actor stalls)
bool Function IsClimaxStalled(int ThreadID) Global Native                            ; L251
ObjectReference Function GetFurniture(int ThreadID) Global Native                    ; L268 None if no furniture
string Function GetFurnitureType(int ThreadID) Global Native                         ; L277 "" during startup / after end
Function ChangeFurniture(int ThreadID, ObjectReference FurnitureRef, string SceneID = "") Global Native ; L288 move running scene to other furniture (API 7.3.2)
bool Function IsInAutoMode(int ThreadID) Global Native                               ; L305
Function StartAutoMode(int ThreadID) Global Native                                   ; L312
Function StopAutoMode(int ThreadID) Global Native                                    ; L321 manual mode; NPC threads must then be driven externally
bool Function HasMetadata(int ThreadID, string Metadata) Global Native               ; L339 thread metadata = free-form string tags
Function AddMetadata(int ThreadID, string Metadata) Global Native                    ; L347
string[] Function GetMetadata(int ThreadID) Global Native                            ; L356
bool Function HasMetaFloat(int ThreadID, string MetaID) Global Native                ; L369
float Function GetMetaFloat(int ThreadID, string MetaID) Global Native               ; L381
Function SetMetaFloat(int ThreadID, string MetaID, float Value) Global Native        ; L392
bool Function HasMetaString(int ThreadID, string MetaID) Global Native               ; L405
string Function GetMetaString(int ThreadID, string MetaID) Global Native             ; L417
Function SetMetaString(int ThreadID, string MetaID, string Value) Global Native      ; L428
Function CallEvent(int ThreadID, string EventName, int Actor, int Target = -1, int Performer = -1) Global Native ; L448 fire an OStim event (-> ostim_event mod event even if no event JSON exists)
```

All 37 names are present as native registration strings in the DLL (DLL-str 97785-97822 block: `OThread ... Stop GetThreadCount QuickStart IsRunning QueueNavigation WarpTo GetAllThreadIDs NavigateTo AutoTransitionForActor GetSpeed QueueWarp AutoTransition ... CallEvent`).

### 1.2 OThreadBuilder (`SRC\OThreadBuilder.psc`) - "the BuilderID is most likely not going to be the same as the thread id" (L7)

```papyrus
int Function Create(Actor[] Actors) Global Native                                    ; L46  new builder; -1 if at least one actor is invalid
Function SetDominantActors(int BuilderID, Actor[] Actors) Global Native              ; L55  dominants; all others count as submissive
Function SetFurniture(int BuilderID, ObjectReference FurnitureRef) Global Native     ; L63
Function SetDuration(int BuilderID, float Duration) Global Native                    ; L74  seconds, then thread ends (API 7.1)
Function SetStartingAnimation(int BuilderID, string Animation) Global Native         ; L83  resets earlier starting-animation changes
Function AddStartingAnimation(int BuilderID, string Animation, float Duration = 0.0, bool NavigateTo = false) Global Native ; L95 append a start scene (API 7.1e)
Function SetStartingSequence(int BuilderID, string Sequence) Global Native           ; L106 (API 7.1)
Function ConcatStartingSequence(int BuilderID, string Sequence, bool NavigateTo = false) Global Native ; L117 (API 7.2)
Function EndAfterSequence(int BuidlerID) Global Native                               ; L126 end thread when starting animations are through (param name typo is in the source)
Function UndressActors(int BuilderID) Global Native                                  ; L136 full strip at start regardless of MCM
Function NoAutoMode(int BuilderID) Global Native                                     ; L148 never auto mode (also for NPCxNPC => must be driven manually)
Function NoPlayerControl(int BuilderID) Global Native                                ; L157 disable player control (player thread only) (API 7.1)
Function NoPostDialogue(int BuilderID) Global Native                                 ; L166 disable post-scene dialogue (API 7.4d 0x07040004)
Function NoUndressing(int BuilderID) Global Native                                   ; L176 no undressing at all; overrules UndressActors (API 7.1)
Function NoFurniture(int BuilderID) Global Native                                    ; L189 never offer/auto-select furniture (API 7.2); pointless with SetFurniture
Function SetMetadata(int BuilderID, string[] Metadata) Global Native                 ; L197
Function SetMetadataCSV(int BuilderID, string Metadata) Global Native                ; L205
int Function Start(int BuilderID) Global Native                                      ; L216 returns the thread id
Function Cancel(int BuilderID) Global Native                                         ; L223 dispose builder
Function Example(Actor[] Actors)                                                     ; L14  non-global documentation example (see section 3)
```

### 1.3 OPlayerThread (`SRC\OPlayerThread.psc`, API 7.3.4d)

```papyrus
Function SetPlayerControl(bool Control) Global Native   ; L13 enables/disables manual control of the player thread at runtime
```

### 1.4 OActor (`SRC\OActor.psc`) - "all of these only affect actors that are currently in a scene" (L3)

```papyrus
float Function GetExcitement(Actor Act) Global Native                                ; L23  0..100
Function SetExcitement(Actor Act, float Excitement) Global Native                    ; L32  clamped 0..100
Function ModifyExcitement(Actor Act, float Excitement, bool RespectMultiplier = false) Global Native ; L42
float Function GetExcitementMultiplier(Actor Act) Global Native                      ; L51
Function SetExcitementMultiplier(Actor Act, float Multiplier) Global Native          ; L59
Function StallClimax(Actor Act) Global Native                                        ; L74
Function PermitClimax(Actor Act) Global Native                                       ; L81
bool Function IsClimaxStalled(Actor Act, bool CheckThread = true) Global Native      ; L91
Function Climax(Actor Act, bool IgnoreStall = false) Global Native                   ; L99  force a climax
int Function GetTimesClimaxed(Actor Act) Global Native                               ; L108 in the current scene
Function SetExpressionsEnabled(Actor Act, bool Enabled, bool AllowOverride = true) Global Native ; L127 (API 7.3.4d)
float Function PlayExpression(Actor Act, string Expression) Global Native            ; L138 returns duration from the event json
Function ClearExpression(Actor Act) Global Native                                    ; L145
bool Function HasExpressionOverride(Actor Act) Global Native                         ; L154
Function Mute(Actor Act) Global Native                                               ; L169 stop moans/talk
Function Unmute(Actor Act) Global Native                                             ; L176
bool Function IsMuted(Actor Act) Global Native                                       ; L185
Function Undress(Actor Act) Global Native                                            ; L200
Function Redress(Actor Act) Global Native                                            ; L207
Function UndressPartial(Actor Act, int Mask) Global Native                           ; L216 armor slot mask
Function RedressPartial(Actor Act, int Mask) Global Native                           ; L225
Function RemoveWeapons(Actor Act) Global Native                                      ; L232
Function AddWeapons(Actor Act) Global Native                                         ; L239
bool Function EquipObject(Actor Act, string Type) Global Native                      ; L262 OStim equip objects ("SKSE/plugins/OStim/equip objects")
Function UnequipObject(Actor Act, string Type) Global Native                         ; L270
bool Function IsObjectEquipped(Actor Act, string Type) Global Native                 ; L280
bool Function SetObjectVariant(Actor Act, string Type, string Variant, float Duration = 0.0) Global Native ; L292
Function UnsetObjectVariant(Actor Act, string Type) Global Native                    ; L300
bool Function AutoTransition(Actor Act, string Type) Global Native                   ; L318 actor-level auto transition
bool Function HasMetadata(Actor Act, string Metadata) Global Native                  ; L338 (API 7.3.2)
Function AddMetadata(Actor Act, string Metadata) Global Native                       ; L348
string[] Function GetMetadata(Actor Act) Global Native                               ; L359
bool Function HasMetaFloat(Actor Act, string MetaID) Global Native                   ; L372
float Function GetMetaFloat(Actor Act, string MetaID) Global Native                  ; L384
Function SetMetaFloat(Actor Act, string MetaID, float Value) Global Native           ; L395
bool Function HasMetaString(Actor Act, string MetaID) Global Native                  ; L408
string Function GetMetaString(Actor Act, string MetaID) Global Native                ; L420
Function SetMetaString(Actor Act, string MetaID, string Value) Global Native         ; L431
bool Function IsInOStim(Actor Act) Global Native                                     ; L448 actor currently in any OStim scene
int Function GetThreadID(Actor Act) Global Native                                    ; L459 -1 if not in a scene (API 7.3.5c)
bool Function VerifyActors(Actor[] Actors) Global Native                             ; L468 true if ALL actors are eligible for OStim scenes
; deprecated Papyrus wrappers: UpdateExpression L480, HasSchlong L484, SortActors(Actor[] Actors, int PlayerIndex = -1) L488, GetSceneID L492
```

### 1.5 OActorUtil (`SRC\OActorUtil.psc`)

```papyrus
bool Function HasSchlong(Actor Act) Global Native                                    ; L26  SoS/TNG aware, else actor sex
bool Function FulfillsCondition(Actor Act, Perk Condition) Global Native             ; L39  run a perk's condition functions on the actor (actor need not have the perk)
bool Function FulfillsAnyCondition(Actor Act, Perk[] Conditions) Global Native       ; L52
bool Function FulfillsAllConditions(Actor Act, Perk[] Conditions) Global Native      ; L65
Function SayTo(Actor Act, Actor Target, Topic Dialogue) Global Native                ; L82
Function SayAs(Actor Act, Actor Target, Topic Dialogue, VoiceType Voice) Global Native ; L94
Actor[] Function EmptyArray() Global Native                                          ; L109
Actor[] Function CreateArray(int Size, Actor Filler = None) Global Native            ; L119
Actor[] Function ToArray(Actor One = None, Actor Two = None, Actor Three = None, Actor Four = None, Actor Five = None, Actor Six = None, Actor Seven = None, Actor Eight = None, Actor Nine = None, Actor Ten = None) Global Native ; L128 drops None entries
Actor[] Function Sort(Actor[] Actors, Actor[] DominantActors, int PlayerIndex = -1) Global Native ; L142 dominants first, then schlong-havers first, stable; obeys MCM 2-actor role settings if PlayerIndex = -1
Actor[] Function SelectIndexAndSort(Actor[] Actors, Actor[] DominantActors) Global    ; L152 Papyrus; MAY SHOW the role-selection Message (OStimRoleSelectionMessage) depending on MCM
Actor[] Function GetActorsInRangeV2(ObjectReference Center, float Range, bool IncludeCenter = false, bool IncludePlayer = true, bool OStimActorsOnly = false, Perk Condition = None) Global Native ; L189 (API 7.3.4)
string[] Function ActorsToNames(Actor[] Actors) Global Native                        ; L200
Actor[] Function GetActorsInRange(ObjectReference Center, float Range, bool IncludeCenter = false, bool IncludePlayer = true, Perk Condition = None) Global ; L211 deprecated wrapper
```

### 1.6 OFurniture (`SRC\OFurniture.psc`), OJSON, OEvent, OActionData, OSequence

```papyrus
; OFurniture
string Function GetFurnitureType(ObjectReference FurnitureRef) Global Native          ; L16 OStim furniture type id, "none" if not valid furniture
bool Function IsChildOf(string SuperType, string SubType) Global Native               ; L28 furniture type hierarchy (API 7.3.4a)
ObjectReference[] Function FindFurniture(int ActorCount, ObjectReference CenterRef, float Radius, float SameFloor = 0.0) Global Native ; L41 closest free ref of EACH furniture type, sorted by distance
ObjectReference Function FindFurnitureOfType(string Type, ObjectReference CenterRef, float Radius, float SameFloor = 0.0) Global Native ; L55 (API 7.1e)
float[] Function GetOffset(ObjectReference FurnitureRef) Global Native                ; L64 {x,y,z,rotation,scale}
int Function GetSceneID(ObjectReference FurnitureRef) Global Native                   ; L75 ThreadID using that furniture, -1 if none (name says "SceneID" but it is the thread id)
Function ResetClutter(ObjectReference CenterRef, float Radius) Global Native          ; L83
; OJSON  (for the strArg of ostim_end / ostim_thread_end)
Actor[] Function GetActors(string Json) Global Native                                 ; L13
string Function GetScene(string Json) Global Native                                   ; L22
string[] Function GetMetadata(string Json) Global Native                              ; L33 (API 7.3.4d)
; OEvent
bool Function IsChildOf(string SuperType, string SubType) Global Native               ; L16
; OActionData (API 7.3.5)
bool Function FulfillsActorConditions(string ActionType, Actor Act) Global Native     ; L29
bool Function FulfillsTargetConditions(string ActionType, Actor Act) Global Native    ; L39
bool Function FulfillsPerformerConditions(string ActionType, Actor Act) Global Native ; L49
; OSequence (API 7.1): GetRandomSequence(Actor[] Actors) L22, GetRandomFurnitureSequence(Actor[] Actors, string FurnitureType) L32,
;   GetRandom[Furniture]SequenceWith{SequenceTag | AnySequenceTag[CSV] | AllSequenceTags[CSV]} L50-L146 (exact signatures in Appendix A)
```

Installed furniture type ids (`DATA\furniture types\*.json` file names): `alchemytable, bed, bedroll, bench, chair, cookingpot, doublebed, enchantingtable, shelf, singlebed, table, tableleanmarker, tableleanmarkerBBLS, wall, wardrobe, wardrobethick, wardrobethin`. `"none"` = no furniture and is the supertype of default scenes (`DATA\furniture types README.txt:24`).

### 1.7 OLibrary (`SRC\OLibrary.psc`, 97 natives) - see section 5 for the families; every signature + line is in Appendix A.

### 1.8 OMetadata (`SRC\OMetadata.psc`, 280 natives) - see section 5; every signature + line is in Appendix A.

### 1.9 OSexIntegrationMain (`SRC\OSexIntegrationMain.psc`) - legacy wrappers worth knowing (all VERIFIED bodies)

| Legacy call (line) | Body / modern equivalent |
|---|---|
| `Bool Function StartScene(Actor Dom, Actor Sub, Bool zUndressDom = False, Bool zUndressSub = False, Bool zAnimateUndress = False, String zStartingAnimation = "", Actor zThirdActor = None, ObjectReference Bed = None, Bool Aggressive = False, Actor AggressingActor = None)` (L2901) | builder: `Create(OActorUtil.ToArray(dom, sub, zThirdActor))`, `SetFurniture`, `SetStartingAnimation`, `UndressActors` if either undress flag, `SetDominantActors([AggressingActor])`, `NoPlayerControl` if `DisableOSAControls` was set and the player is involved; returns `Start(...) >= 0` |
| `Function Masturbate(Actor Masturbator, Bool zUndress = False, Bool zAnimUndress = False, ObjectReference MBed = None)` (L2927) | builder with one actor |
| `Function EndAnimation(Bool SmoothEnding = True)` (L2872), `Function ForceStop()` (L2763) | `OThread.Stop(0)` |
| `Bool Function AnimationRunning()` (L2702) | `OThread.IsRunning(0)` |
| `string function GetCurrentAnimationSceneID()` (L2739) | `OThread.GetScene(0)` |
| `Function TravelToAnimation(String Animation)` (L2747), `TravelToAnimationIfPossible` (L2743) | both `OThread.NavigateTo(0, Animation)` |
| `Function WarpToAnimation(String Animation)` (L2751) | `OThread.WarpTo(0, Animation)` |
| `Actor[] Function GetActors()` (L2799) | returns `_Actors` cached in the `ostim_start` handler (L1965-1969) - NOT live |
| `Actor Function GetActor(int Index)` (L2803), `GetDomActor/GetSubActor/GetThirdActor` (L2497-2507) | `OThread.GetActor(0, i)` |
| `Bool Function IsActorActive(Actor Act)` (L2698) | `OActor.IsInOStim(Act)` |
| `Bool Function IsActorInvolved(actor act)` (L2755) | `OThread.GetActors(0).Find(act) >= 0` |
| `Float Function GetActorExcitement(Actor Act)` L2678, `SetActorExcitement` L2682, `AddActorExcitement` L2686 | `OActor.GetExcitement / SetExcitement / ModifyExcitement` |
| `Int Function GetCurrentAnimationSpeed()` L2715, `SetCurrentAnimationSpeed(Int InSpeed)` L2782, `IncreaseAnimationSpeed()` L2727, `DecreaseAnimationSpeed()` L2731, `GetCurrentAnimationMaxSpeed()` L2723 | `OThread.GetSpeed/SetSpeed(0, ..)`, `OMetadata.GetMaxSpeed(OThread.GetScene(0))` |
| `Function SetOrgasmStall(Bool Set)` L2852, `Bool Function GetOrgasmStall()` L2860 | `OThread.StallClimax(0)` / `PermitClimax(0)` / `IsClimaxStalled(0)` |
| `Function Climax(Actor Act)` L2790, `Function Orgasm(Actor Act)` L2794 | `OActor.Climax(Act, false)` |
| `Int Function GetTimesOrgasm(Actor Act)` L2650 | `OActor.GetTimesClimaxed` |
| `bool Function IsChild(actor act)` L2636 | `OUtils.IsChild(Act)` |
| `Int Function GetAPIVersion()` L2493 | `SKSE.GetPluginVersion("OStim")` |
| `Bool Function IsSceneAggressiveThemed()` L1768, `Actor Function GetAggressiveActor()` L1782, `bool Function IsVictim(actor act)` L1796 | any scene actor has actor tag `"dominant"` |
| `Bool Function IsVaginal()` L2527 / `IsOral()` L2531 | `OMetadata.FindAction(OThread.GetScene(0), "vaginalsex"/"blowjob") != -1` |
| `Function AddSceneMetadata(string MetaTag)` L2832, `HasSceneMetadata` L2836, `GetAllSceneMetadata` L2840 | `OThread.Add/Has/GetMetadata(0, ..)` |
| property `PauseAI` L2411 | get = `OThread.IsInAutoMode(0)`; set true = `StartAutoMode(0)`, false = `StopAutoMode(0)` (name is inverted history) |
| property `ForceCloseOStimThread` L2368 | setting true calls `EndAnimation(false)` |
| property `bool DisableOSAControls = false Auto` L2469 | consumed once by the next legacy `StartScene` -> `NoPlayerControl` |
| `float Function GetTimeSinceStart()` L2848 | real-time seconds since `ostim_start` |

`OnLoadGame()` (L2124-2175) registers `ostim_start/ostim_end/ostim_orgasm`, checks the DLL version, detects `Schlongs of Skyrim.esp` / `TheNewGentleman.esp`, and calls `OData.ImportSettings()` when `AutoImportSettings` is on. `OStimOrgasm` (L1975-1993) forwards a `FertilityModeAddSperm` mod event when the orgasming actor is the *actor* of a `vaginalsex` action.

### 1.10 Other scripts (one line each; signatures in Appendix A)

- `OUtils`: `OSexIntegrationMain Function GetOStim() Global` L3; `bool Function IsChild(actor act) Global` L189; `Bool Function AppearsFemale(Actor Act) Global` L375; `Function Lock(string mutex_key, float spinlockRate = 0.1) Global` L425 (spin-lock over `OSANative.TryLock`); `bool Function MenuOpen() global` L352; `string[] Function StringArray(...)` L471; NPC data helpers via StorageUtil; `KeycodeToKey` uses JContainers.
- `OCSV`: `ToCSVList(string[] Values)`, `FromCSVList(string Values)`, `CreateCSVList(int Size, string Filler)`, `CreateSingleCSVListEntry(int Index, string Entry)`, `ConcatCSVLists(string ListA, string ListB)` and the `...Matrix/Matrices` variants using `;` (L13-L111).
- `OData` (internal): `Function ResetSettings()` L55, `Function ExportSettings()` L57, `Function ImportSettings()` L59, `int Function GetUndressingSlotMask()` L8, `Function SetUndressingSlotMask(int Mask)` L10, `string[] Function GetActions()` L32, `string[] Function GetEvents()` L43, stimulation getters/setters L34-L52, `Function ReloadScene(string sceneId)` L70 (hot-reload one scene JSON), `string Function Localize(string Text)` L62.
- `OSettings` (internal, API 7.3.1): page/group/setting enumeration and `SetSettingValue / SetSettingIndex / SetSettingText / SetSettingKey / ToggleSetting / ClickSetting` by (Page, Group, Setting) index.
- `OUndress`: `bool Function UsePapyrusUndressing() Global` L16 (returns false; override point), `AnimateRedress` L31, `Undress(int ThreadId, Actor Act)` L141, `Redress` L168, `UndressPartial` L188, `RedressPartial` L215; natives `CanUndress(Form Item)` L247 (honours keywords `OStimNoStrip` / `SexLabNoStrip` and the MCM slot mask), `IsWig` L260, `GetWornItems(Actor Act)` L269, `TrimArmorArray` L278. "Do not call these functions, use the OActor.UndressX functions" (L2).
- `OSKSE`: `Function SendOStimEvent(int ThreadId, string Type, Actor eventActor, Actor eventTarget, Actor eventPerformer) Global` L96 (creates mod event `ostim_event`), `FadeToBlack(float FadeDuration)` L75, `FadeFromBlack` L87, `SayPostDialogue(...)` L61, `UIExtMessageBox(string Caption, string[] Options)` L113 (UIExtensions list menu, used by the DLL when message-box options exceed the vanilla limit - INFERENCE from name/strings `UIExtMessageBox`, `UIExtensions.esp` DLL-str 96887-96888).
- `OSANative`: `ActorBase Function GetLeveledActorBase(Actor Act)` L15, `Int Function GetSex(ActorBase Base)` L12, `Race Function GetRace(ActorBase Base)` L13, `Actor[] Function GetActors(ObjectReference CenterRef = None, Float Radius = 0.0)` L20, `ObjectReference[] Function FindBed(ObjectReference CenterRef, Float Radius = 1000.0, Float SameFloor = 0.0)` L128, `Bool Function TryLock(String a_lock)` L177, `Function Unlock(String a_lock)` L179, `Int Function RandomInt(Int Min = 0, Int Max = 100)` L164, `Function SendEvent(Form FormRef, String Evnt)` L169, `Function EndPlayerDialogue()` L66, `Function ToggleCombat(bool a_enable)` L157, `Bool Function DetectionActive()` L159, `string Function GetSceneIdFromAnimId(string Id)` L186, `int Function GetSpeedFromAnimId(string Id)` L187.
- `OStimAddon`: `Function InstallAddon(string name)` L18, `Function RegisterForOEvent(string EventName)` L124 = `RegisterForModEvent(EventName, EventName)`; stub handlers `OStim_PreStart, OStim_Start, OStim_AnimationChanged, OStim_SceneChanged, OStim_Orgasm, OStim_End` all `(string eventName, string strArg, float numArg, Form sender)` L129-L145.
- `OStimSubthread` (legacy; "don't use the subthread script, use OThread instead", `OSexIntegrationMain.psc:2876`): `StartSubthreadScene(actor dom, actor sub = none, actor zThirdActor = none, string startingAnimation = "", ObjectReference furnitureObj = none, bool withAI = true, bool isAggressive = false, actor aggressingActor = none)` L233; sends `ostim_subthread_start/end/orgasm`.
- Quirk: `OMetadata.FindActionSuperloadCSV` / `FindActionsSuperloadCSV` (L3328, L3332) are declared **without `Global`** in the shipped source; treat as unusable and call the `...v2` natives (L1647, L1681).

---

## 2. Mod events

Sources: `DATA\list of mod events.txt` (official list, 154 lines), DLL strings, `SRC\OSKSE.psc`, `SRC\OStimSubthread.psc`, UPSTREAM `GameAPI/GameEvents.cpp`.

DLL-str 96913-96934, contiguous and in this order (VERIFIED): `ostim_prestart, ostim_thread_start, ostim_start, ostim_scenechanged_, ostim_scenechanged, ostim_animationchanged, ostim_thread_scenechanged, scene, ostim_thread_speedchanged, metadata, actors, normal, originator, ostim_totalend, ostim_end, ostim_orgasm, ostim_thread_end, ostim_furniturechanged, ostim_actor_orgasm, ostim_spank, spank, SendOStimEvent`. This is exactly the literal order in UPSTREAM `GameEvents.cpp`, so the argument mapping below (taken from that file) is very likely what the installed DLL does.

All standard events use the 4-arg handler `Event X(string EventName, string StrArg, float NumArg, Form Sender)`.

| Event name | Threads | Sender | StrArg | NumArg | Fires when | In README list | In DLL |
|---|---|---|---|---|---|---|---|
| `ostim_prestart` | player only | main quest | `""` | 0 | immediately before `ostim_start` (legacy) | no | yes 96913 |
| `ostim_start` | player only | main quest | `""` | 0 | main thread starts | yes (L6-12) | yes 96915 |
| `ostim_thread_start` | all | main quest | `""` | ThreadID | any thread starts | yes (L58-67) | yes 96914 |
| `ostim_scenechanged` | player only | main quest | SceneID | 0 | scene of main thread changes | yes (L14-23) | yes 96917 |
| `ostim_scenechanged_<SceneID>` | player only | main quest | `""` | 0 | same moment; event name is suffixed with the scene id (legacy) | no | yes 96916 (prefix `ostim_scenechanged_`) |
| `ostim_thread_scenechanged` | all | main quest | SceneID | ThreadID | scene of a thread changes (API 7.2d) | yes (L69-80) | yes 96919 |
| `ostim_animationchanged` | player only | main quest | SceneID | speed index | speed changes on main thread (legacy) | no | yes 96918 |
| `ostim_thread_speedchanged` | all | main quest | README: unused; UPSTREAM: `std::to_string(speed)` | ThreadID | "speed or scene of a thread changes" (API 7.3) - read speed with `OThread.GetSpeed(ThreadID as int)` | yes (L82-93) | yes 96921 |
| `ostim_orgasm` | player only | the actor | SceneID | actor index | an actor on main thread climaxes | yes (L25-37) | yes 96928 |
| `ostim_actor_orgasm` | all | the actor | SceneID | ThreadID | any actor climaxes | yes (L95-107) | yes 96931 |
| `ostim_furniturechanged` | all | new furniture ref | furniture type id | ThreadID | furniture changes during a thread | yes (L109-121) | yes 96930 |
| `ostim_end` | player only | main quest | JSON | -1 (UPSTREAM) | main thread ends | yes (L39-51) | yes 96927 |
| `ostim_totalend` | player only | main quest | `""` | 0 | right after `ostim_end` (legacy) | no | yes 96926 |
| `ostim_thread_end` | all | main quest | JSON | ThreadID | any thread ends | yes (L141-154) | yes 96929 |
| `ostim_spank` | player only | target actor | `""` | 0 | an OStim event that is a child of `spank` fires (legacy) | no | yes 96932 |
| `ostim_event` | all | - (ModEvent.Create) | custom signature | - | any OStim event (annotation `OStimEvent.x` or `OThread.CallEvent`) | yes (L123-139) | no - created in `SRC\OSKSE.psc:96-104`, the DLL dispatches the static call `OSKSE.SendOStimEvent` (DLL-str 96934) |

`ostim_event` handler signature (VERIFIED, `DATA\list of mod events.txt:135`):

```papyrus
RegisterForModEvent("ostim_event", "OStimEvent")
Event OStimEvent(int ThreadID, string Type, Form EventActor, Form EventTarget, Form EventPerformer)
```

Installed event types (`DATA\events\*.json`): `spank, spankleft, spankright, wash, washback, washchest, washface, washfeet, washhands, washhead`.

End-event JSON (keys VERIFIED in DLL-str 96920-96925; structure from UPSTREAM): `{"scene": <last scene id>, "actors": [...], "metadata": [...], "originator": "normal" | <other>}`. Decode only through `OJSON.GetActors / GetScene / GetMetadata`.

Papyrus-side legacy events: `ostim_subthread_start`, `ostim_subthread_end`, `ostim_subthread_orgasm` (`SRC\OStimSubthread.psc:56,67,256`) - only when someone uses the legacy subthread alias API. `ostim_thirdactor_join` / `ostim_thirdactor_leave` are still *registered* by `OBarsScript.psc:258-259` but the strings do not exist in the DLL -> never fired in 7.5.1 (VERIFIED absence in strings dump). `ostim_toast` is internal (`OSexIntegrationMain.psc:2069`).

Climax flow (UPSTREAM `Core/ThreadActor/ThreadActorClimax.cpp`): excitement reaches 100 -> if stalled (actor or thread) the orgasm is parked until permitted; else if MCM "auto climax animations" is on and the scene has an actor auto-transition `"climax"` it navigates there and the climax happens on the `OStimClimax` annotation; then `timesClimaxed++`, schlong-actors set thread speed 0, -250 stamina, **`ostim_orgasm` / `ostim_actor_orgasm` fire**, then the end-on-orgasm settings may call `setStopTimer(4000)` (thread ends about 4 s later).

INFERENCE: mod-event names are `BSFixedString`s (case-insensitive), so `OStimAddon`'s `OStim_PreStart`/`OStim_Start` handler names registering via `RegisterForOEvent("ostim_start")` style calls work regardless of case.

---

## 3. Starting a player scene

### 3.1 Recommended (builder) - VERIFIED from `SRC\OThreadBuilder.psc:14-35`

```papyrus
Function Example(Actor[] Actors)
	int BuilderID = OThreadBuilder.Create(Actors)                                           ; necessary
	string SceneID = OLibrary.GetRandomSceneSuperloadCSV(Actors, AnyActionType = "intercourse") ; optional (NB: "intercourse" is not an installed action id - see 5.3)
	OThreadBuilder.SetStartingAnimation(BuilderID, SceneID)
	OThreadBuilder.UndressActors(BuilderID)                                                 ; optional
	OThreadBuilder.NoFurniture(BuilderID)                                                   ; optional
	int ThreadID = OThreadBuilder.Start(BuilderID)                                          ; necessary
EndFunction
```

### 3.2 What actually happens on `Start` (UPSTREAM `Papyrus/PapyrusThreadBuilder.h`, `Core/ThreadStarter/ThreadStarter.cpp`, `PlayerThreadStarter.cpp`; messages VERIFIED in DLL-str 96781-96819)

1. `Create` returns -1 if any actor fails `isEligible` (section 7). `Start` re-checks every actor: not eligible -> notification `"actor <name> is not eligible for OStim"`; not 3D-loaded -> `"actor <name> is not loaded"`; duplicate -> `"duplicate actor in list: <name>"`; each returns **-1**.
2. If starting nodes were set but the actors do not fulfil a node's requirements, **all starting nodes are silently dropped** (log only: `actor's dont fulfill requirements of scene {}`) and OStim falls back to choosing its own start scene.
3. If the player is in the actor list: `startPlayerThread(params); return 0;` - i.e. **`Start` returns 0 before anything has started**. A detached thread waits (250 ms poll) until **no actor `isInDialogue`**, then continues on the game thread.
4. Furniture (only if not `NoFurniture`, no `SetFurniture`, and MCM `UseFurniture` on):
   - *with* a starting animation: only a **bed** is searched (`FurnitureSearchDistance`, same-floor 96). If found and MCM `SelectFurniture` is on -> **message box `$ostim_message_use_bed` (Yes/No)**; otherwise the bed is used automatically.
   - *without* a starting animation: `Furniture::selectFurniture(...)` -> may show the **furniture selection message box** (`$ostim_message_select_furniture`).
5. Without a starting animation only: MCM `AddActorsAtStart` -> **message box `$ostim_message_add_actor`**; then role selection **message box `$ostim_message_select_position`** if `PlayerSelectRoleStraight/Gay/Threesome` applies; then `ActorUtil::sort(actors, dominantActors, playerIndex)`; then a random start scene with scene tag `"intro"` (MCM `UseIntroScenes`) or `"idle"` (standing actor for no furniture, non-standing for beds). No scene -> notification `"no starting animation found"` and nothing starts.
6. **With a starting animation: steps 5 are skipped entirely - no add-actor prompt, no role prompt, NO SORTING.** Actor index i in your array = scene actor position i.
7. `startInner`: if MCM `UseFades` -> fade to black, 700 ms, start, 550 ms, fade in; else start immediately. Then `ostim_prestart`, `ostim_start`, `ostim_thread_start` fire.

Consequences for ordering/roles (VERIFIED doc + UPSTREAM): position 0 is conventionally the "dom"/schlong-haver (`GetDomActor()` = `GetActor(0)`, `OSexIntegrationMain.psc:2497`); `OActorUtil.Sort` puts dominants first, then actors with schlong first, stable, and "obeys the MCM settings for two actor scenes if no player index is passed" (`OActorUtil.psc:130-142`) - that is where `PlayerAlwaysDomStraight/SubStraight/DomGay/SubGay` take effect. Scene JSON `actors[i].intendedSex` and `requirements` (`DATA\scenes README.txt:83-115`) are what `OLibrary.*(Actors, ...)` checks against the array **in the order given**, so sort first, then search, then `SetStartingAnimation`.

Undressing: default = MCM (`AlwaysUndressAtAnimStart`, `AutoUndressIfNeeded`, `PartialUndressing`, slot mask); `UndressActors` forces full strip at start; `NoUndressing` disables all undressing and overrules `UndressActors` (`OThreadBuilder.psc:128-176`). Per-scene-actor `"noStrip": true` exists in scene JSON.

Player control vs auto mode: default = MCM (`UseAIControl` = always auto, `UseAIMasturbation` = solo, `UseAINonAggressive` = "vanilla"/consensual; UPSTREAM `evaluateAutoMode`). `NoAutoMode` sets a thread flag; UPSTREAM `startAutoMode()` returns early when that flag is set, so **after `NoAutoMode` even `OThread.StartAutoMode` will not enable auto mode** (INFERENCE for the installed build). `NoPlayerControl` removes the navigation UI/keys for the player; `OPlayerThread.SetPlayerControl(bool)` toggles it at runtime.

Other start routes: `OThread.QuickStart(Actors, StartingAnimation, FurnitureRef)` (same `startThread` path, no flags); `SetStartingSequence` + `EndAfterSequence` for scripted one-shot sequences (used by OCR). `SetDuration(seconds)` arms a stop timer.

### 3.3 Legacy way

`OUtils.GetOStim().StartScene(Dom, Sub, zUndressDom, zUndressSub, zAnimateUndress, zStartingAnimation, zThirdActor, Bed, Aggressive, AggressingActor)` (`OSexIntegrationMain.psc:2901-2924`, comment: "You probably want to call OThread.QuickStart, a Builder is only needed for more complex parameters") - it is just the builder; `zAnimateUndress` and `Aggressive` are ignored. `Masturbate(...)` L2927. NPC scenes: `OStimSubthread.StartSubthreadScene` (legacy).

There is also a native C++ plugin API in the DLL (VERIFIED strings 97205-97254): exports `RequestPluginAPI_Scene` (`OstimNG_API::Scene::SceneInterface::StartScene / StartCoupleScene / StartThreesomeScene / StartFoursomeScene`) and `RequestPluginAPI_Thread` (`OstimNG_API::Thread::ThreadInterface::NavigateToScene, NavigateToSearchResult, RegisterEventCallback, RegisterControlCallback, SetExternalUIEnabled` + unregister variants). Headers were not inspected; only relevant if an SKSE DLL is ever justified.

---

## 4. Navigation functions - exact semantics

VERIFIED doc comments (`SRC\OThread.psc`) + UPSTREAM `Core/Thread/ThreadNavigation.cpp`.

| Function | Follows graph with transitions? | Behaviour |
|---|---|---|
| `OThread.NavigateTo(ThreadID, SceneID)` | **Yes** | Clears the node queue ("cancels any currently running navigations"). Computes `currentNode->getRoute(MCM navigationDistanceMax, actorConditions, target)`. Route found -> plays node 1 now and queues the rest (each transition scene plays for its own duration). **No route -> `warpTo(target, useAutoModeFades OR target.fadeOnEntry)`** ("if navigation is not possible instead warps there"). Same scene -> no-op. Unknown SceneID or thread -> silently nothing. For the player thread the first hop is faded if MCM `UseAutoFades` is on. |
| `OThread.QueueNavigation(ThreadID, SceneID, Duration)` | **Yes** | If nothing is running behaves like `NavigateTo` (and marks the thread "in sequence"). Otherwise routes from the *last queued node* and appends; `Duration` (seconds, truncated to int before x1000) = dwell time in the target before further queued steps. No route -> queues a warp entry. Same target as queue tail -> adds to its duration. |
| `OThread.WarpTo(ThreadID, SceneID, UseFades = False)` | **No (snap)** | Clears the queue, `ChangeNode(target)` directly; with `UseFades` on the player thread: fade out, 700 ms, change, 550 ms, fade in. No requirement/route checks at this level. |
| `OThread.QueueWarp(ThreadID, SceneID, Duration)` | **No (snap)** | Warp after the running navigation finishes; if none running behaves like `WarpTo`. (UPSTREAM passes `duration` into the `useFades` parameter of `warpTo` when nothing is queued - a non-zero duration therefore fades on the player thread; treat as a quirk.) |
| `OThread.PlaySequence(ThreadID, Sequence, NavigateTo = false, UseFades = false)` | optional | Requires the sequence to be fulfilled by the actors, clears the queue; with `NavigateTo` tries a graph route to the first sequence scene, else warps (optionally faded); then queues the remaining sequence entries with their durations. Combined with builder `EndAfterSequence` the thread stops at the end. |
| `OThread.AutoTransition(ThreadID, Type)` / `AutoTransitionForActor(ThreadID, Index, Type)` / `OActor.AutoTransition(Act, Type)` | direct edge | Looks up `autoTransitions[Type]` on the current scene (or scene actor) and `ChangeNode`s to it; returns false if a navigation queue is pending, the type is not defined, or the scene is missing. Known types used by the DLL: `"climax"` (auto climax animations) and `"pullout"` (pull-out key) (UPSTREAM). Query with `OMetadata.GetAutoTransitionForActor(Id, Position, Type)`. |
| `OThread.ChangeFurniture(ThreadID, FurnitureRef, SceneID = "")` | n/a | Moves the thread to another furniture; random start scene for that furniture if `SceneID` is empty. Fires `ostim_furniturechanged`. |
| `OThread.SetSpeed(ThreadID, Speed)` | n/a | Speed index inside the current scene, clamped (`0..OMetadata.GetMaxSpeed`). Fires `ostim_thread_speedchanged` (+ `ostim_animationchanged` for thread 0). |
| `OThread.Stop(ThreadID)` | n/a | Ends the thread (UPSTREAM: `stopFaded()` - honours MCM `UseFades` on the player thread, so the end is not instantaneous; redress/cleanup then `ostim_end`, `ostim_totalend`, `ostim_thread_end`). |
| legacy `TravelToAnimation`, `TravelToAnimationIfPossible`, `WarpToAnimation` | - | wrappers (section 1.9) |

Transition scenes: a scene JSON with `"destination"` "will be played once and then automatically moves to the destination scene" (`DATA\scenes README.txt:20-22`); they get the scene tag `transition` automatically (`DATA\list of commonly used scene tags.txt:12`) and `OMetadata.IsTransition(Id)` returns true. During a navigated route the thread therefore emits one `ostim_thread_scenechanged` per hop (transition ids included) - the glue must ignore/skip scenes where `OMetadata.IsTransition` is true when describing the scene to the LLM.

The navigation graph itself: scene JSON `navigations[] {destination | origin, priority, description, icon, border, noWarnings}` (`DATA\scenes README.txt:40-72`). From Papyrus the only graph query is:

```papyrus
string[] Function GetScenesInRange(string Id, Actor[] Actors, int Distance = 0) Global Native   ; OLibrary.psc:43 (API 7.3.4a) scenes in navigation range of Id that the actors qualify for; Distance 0 = MCM NavigationDistanceMax
string[] Function ScenesToNames(string[] Ids) Global Native                                     ; OMetadata.psc:49 display names
```

MCM `OStimNavigationDistanceMax` (property `NavigationDistanceMax`, `OSexIntegrationMain.psc:465-473`) bounds both `NavigateTo` routing and `GetScenesInRange` default; `OStimUnrestrictedNavigation` (L1629) is a debug toggle.

---

## 5. Scene search and metadata

### 5.1 OLibrary families (all `string ... (Actor[] Actors, ...) Global Native`, return scene id or `""`)

Name grammar (VERIFIED over all 97 signatures): `GetRandom[Furniture]SceneWith<Filter>[CSV]`. The `Furniture` variants insert `string FurnitureType` as 2nd parameter. Array variants take `string[]`, `CSV` variants take `"a,b,c"`; list-of-lists parameters use `;` between lists (`OLibrary.psc:9-18`). "bed scenes are not considered furniture scenes" (L4).

| Family | Filters | Lines (non-furniture / furniture) |
|---|---|---|
| base | `GetAllScenes()` L30, `GetScenesInRange` L43, `GetRandomScene(Actors)` L59, `GetRandomFurnitureScene(Actors, FurnitureType)` L69 | |
| scene tag | `WithSceneTag(.., string Tag)`, `WithAnySceneTag[CSV]`, `WithAllSceneTags[CSV]` | L87-127 / L139-183 |
| single actor tag | `WithSingleActorTag(.., int Position, string Tag)`, `WithAnySingleActorTag[CSV]`, `WithAllSingleActorTags[CSV]` | L202-246 / L259-307 |
| multi actor tag (Tags[i] applies to Actors[i]) | `WithMultiActorTagForAny[CSV]`, `...ForAll[CSV]`, `WithAnyMultiActorTagForAnyCSV`, `...ForAllCSV`, `WithAllMultiActorTagsForAnyCSV`, `...ForAllCSV` | L329-399 / L411-488 (furniture variant L444 is spelled `GetRandomFurnitureSceneWithMultiActorTagForAlLCSV` - capital L typo is part of the real name; Papyrus is case-insensitive so it is harmless) |
| scene tag AND multi actor tag | `With{Any|All}SceneTag[s]And{Any|All}MultiActorTag[s]For{Any|All}CSV(.., string SceneTags, string ActorTags)` | L507-584 / L597-681 |
| action | `WithAction(.., string Type)`, `WithAnyAction[CSV]`, `WithAllActions[CSV]` | L699-739 / L751-795 |
| action for actor | `WithActionForActor(.., int Position, string Type)`, `WithAnyActionForActor[CSV]`, `WithAllActionsForActor[CSV]` | L814-858 / L871-919 |
| action for target | `...ForTarget...` | L938-982 / L995-1043 |
| action for actor and target | `WithActionForActorAndTarget(.., int ActorPosition, int TargetPosition, string Type)` + Any/All[CSV] | L1063-1111 / L1125-1177 |
| superload | see below | L1243 |

```papyrus
string Function GetRandomSceneSuperloadCSV(Actor[] Actors, string FurnitureType = "", string AnySceneTag = "", string AllSceneTags = "", string SceneTagWhitelist = "", string SceneTagBlacklist = "", string AnyActorTagForAny = "", string AnyActorTagForAll = "", string AllActorTagsForAny = "", string AllActorTagsForAll = "", string ActorTagWhitelistForAny = "", string ActorTagWhitelistForAll = "", string ActorTagBlacklistForAny = "", string ActorTagBlacklistForAll = "", string AnyActionType = "", string AnyActionActor = "", string AnyActionTarget = "", string AnyActionPerformer = "", string AnyActionMatesAny = "", string AnyActionMatesAll = "", string AnyActionParticipantAny = "", string AnyActionParticipantAll = "", string AllActionTypes = "", string AllActionActors = "", string AllActionTargets = "", string AllActionPerformers = "", string AllActionMatesAny = "", string AllActionMatesAll = "", string AllActionParticipantsAny = "", string AllActionParticipantsAll = "", string ActionWhitelistTypes = "", string ActionWhitelistActors = "", string ActionWhitelistTargets = "", string ActionWhitelistPerformers = "", string ActionWhitelistMatesAny = "", string ActionWhitelistMatesAll = "", string ActionWhitelistParticipantsAny = "", string ActionWhitelistParticipantsAll = "", string ActionBlacklistTypes = "", string ActionBlacklistActors = "", string ActionBlacklistTargets = "", string ActionBlacklistPerformers = "", string ActionBlacklistMatesAny = "", string ActionBlacklistMatesAll= "", string ActionBlacklistParticipantsAny = "", string ActionBlacklistParticipantsAll = "") Global Native   ; OLibrary.psc:1243
```

Parameter meaning is documented at `OLibrary.psc:1187-1241` ("parameters given as "" will be ignored"; `FurnitureType ""` "means it's a no-furniture-scene"; mate = actor or target of an action; participant = actor, target or performer). Worked examples of real superload calls: `SRC\OAIUtils.psc:34-44` (foreplay: action blacklist `"analsex,tribbing,vaginalsex"`, `AnyActorTagForAny = OCSV.CreateCSVMatrix(Actors.Length, "standing")` when no furniture, `ActorTagBlacklistForAll = ...("standing")` for `"bed"`), L47+ (main: `AnyActionType = "analsex,vaginalsex"`), L83 `GetPulledOutVersion`.

Scenes flagged `"noRandomSelection": true` in JSON are never returned by random selection (`DATA\scenes README.txt:29`).

### 5.2 OMetadata getters (all `(string Id, ...) Global Native`)

```papyrus
string Function GetName(string Id) Global Native                                      ; L38  display name (API 7.3.4a)
string[] Function ScenesToNames(string[] Ids) Global Native                           ; L49
bool Function IsTransition(string Id) Global Native                                   ; L58
int Function GetDefaultSpeed(string Id) Global Native                                 ; L68  usually 1 if the scene has an idle speed else 0
int Function GetMaxSpeed(string Id) Global Native                                     ; L77  index of fastest speed
int Function GetActorCount(string Id) Global Native                                   ; L86
string Function GetAnimationId(string Id, int Index) Global Native                    ; L97  animation event base name of a speed (append _0,_1.. per actor)
bool Function HasRequirement(string Id, int Position, string Requirement) Global Native ; L110 (+ HasAnyRequirement[CSV] L123/L136, HasAllRequirements[CSV] L149/L162)
string Function GetAutoTransitionForActor(string Id, int Position, string Type) Global Native ; L181 destination scene id or ""
string[] Function GetSceneTags(string Id) Global Native                               ; L198 (+ HasSceneTag L208, HasAnySceneTag[CSV] L218/L228, HasAllSceneTags[CSV] L238/L248, GetSceneTagOverlap[CSV] L258/L268)
string[] Function GetActorTags(string Id, int Position) Global Native                 ; L279 (+ HasActorTag L290, HasAnyActorTag[CSV] L301/L312, HasAllActorTags[CSV] L323/L334, GetActorTagOverlap[CSV] L345/L356)
bool Function HasActions(string Id) Global Native                                     ; L373
int Function FindAction(string Id, string Type) Global Native                         ; L383 index of first action of that type or -1 (+ FindAnyAction[CSV], FindActions, FindAllActions[CSV] L393-433)
string[] Function GetActionTypes(string Id) Global Native                             ; L1698
string Function GetActionType(string Id, int Index) Global Native                     ; L1709
int[] Function GetActionActors(string Id) Global Native                               ; L1718
int Function GetActionActor(string Id, int Index) Global Native                       ; L1729
int[] Function GetActionTargets(string Id) Global Native                              ; L1738
int Function GetActionTarget(string Id, int Index) Global Native                      ; L1749
int[] Function GetActionPerformers(string Id) Global Native                           ; L1758
int Function GetActionPerformer(string Id, int Index) Global Native                   ; L1769
string[] Function GetActionTags(string Id, int Index) Global Native                   ; L1789 action tags come from the ACTION json, not the scene
string[] Function GetAllActionsTags(string Id) Global Native                          ; L1799 de-duplicated
int Function FindActionSuperloadCSVv2(string Id, string ActorPositions = "", ... ) Global Native   ; L1647 (24 optional filters; full text in Appendix A)
int[] Function FindActionsSuperloadCSVv2(string Id, ...) Global Native                ; L1681
```

Finder families (each with first/any/all + array/CSV variants, returning action index or `int[]`): `...ForActor[s]` L452-596, `...ForTarget[s]` L615-759, `...ForPerformer[s]` L778-922, `...ForActorAndTarget / ...ForActorsAndTargets` L942-1099, `...ForMate / ...ForMatesAny / ...ForMatesAll` L1120-1352, `...ForParticipant / ...ParticipantsAny / ...ParticipantsAll` L1373-1605. Action-tag predicates L1810-1967. Custom action data (`Has/Get/Is CustomAction{Actor|Target|Performer}{Int|Float|String}[List]...`) L1986-3210; scene-actor aggregates `GetCustomSceneActor{Int|Float}{Min|Max|Sum|Product}` L3232-3317.

**NOT FOUND**: any `OMetadata` function returning a scene's furniture type, modpack name, length/duration, or its navigation edges (grep `Furniture` in `OMetadata.psc` = 0 matches). Furniture type is obtainable only via `OThread.GetFurnitureType(ThreadID)`, `OFurniture.GetFurnitureType(ref)`, or by searching with a `FurnitureType` filter. Those fields exist in the scene JSON (`"furniture"`, `"modpack"`, `"length"`, `"navigations"`), so a server-side index built from the JSON files is the way to get them.

### 5.3 Vocabulary (VERIFIED from installed data)

- Scene tags (`DATA\list of commonly used scene tags.txt`): `cowgirl, doggystyle, facesitting, gay (auto), idle, lesbian (auto), missionary, prone, reversecowgirl, sixtynine, spitroast, transition (auto)`; `intro` is used by the DLL for start scenes (UPSTREAM).
- Actor tags (`DATA\list of commonly used actor tags.txt`): `allfours, bendover, drowsy, facingaway, handstanding, kneeling, lyingback, lyingfront, lyingside, onbottom, ontop, sitting, sleeping, spreadlegs, squatting, standing, suspended, upsidedown`; additionally `dominant` / `aggressor` are checked by the main script (L1774, L2549).
- Action type ids = file names in `DATA\actions\` (86 files: `analfingering ... washing`, e.g. `vaginalsex, analsex, blowjob, handjob, kissing, frenchkissing, hugging, cuddling, holdinghand, vampirebiting, spanking, spectating, ...`). Actions define `"aliases"` (`DATA\actions README.txt:15`; e.g. `lickingnipple.json` aliases `licknipple, licknipples, lickingnipples`), which is why OStim's own scripts search for ids such as `"cunnilingus"` or `"lickingnipples"` that are not file names. INFERENCE: getters return the canonical id, searches accept aliases. OCR adds 27 more action ids (section 10). Whether `"intercourse"` (used in the builder Example) resolves was NOT verified.
- Actor requirements (`DATA\actor properties\OStimNPC.json`): `anus, foot, hand, mouth, nipple, penis` (+ `mouthbeast` for beast races, `OStimBeastRace.json`).
- Installed base scene packs: 280 scene JSONs under `DATA\scenes\OStim{1P..5P, Bench2P, Chair1P-3P, CookingPot1P-2P, DoubleBedLeft/Right2P, Shelf1P-2P, SingleBedLeft/Right2P, Table1P-2P, Wall1P-2P}` (OARE and OCR add more; not counted here). Scene id = file name without `.json`; folder names are not part of the id (`DATA\scenes README.txt:3-4`).

---

## 6. Thread state and control (cheat sheet)

| Need | Call |
|---|---|
| player thread id | constant `0` (`OThread.psc:3`) |
| is a player scene running | `OThread.IsRunning(0)` (UPSTREAM: true as soon as the thread object exists; `GetScene(0)` may still be `""` "if the thread is still in startup") |
| which thread is an actor in | `OActor.GetThreadID(Act)` (-1 none); `OActor.IsInOStim(Act)` |
| current scene / speed | `OThread.GetScene(tid)`, `OThread.GetSpeed(tid)` (-1 in startup), `OMetadata.GetMaxSpeed(scene)`, `OMetadata.GetDefaultSpeed(scene)` |
| actors / position | `OThread.GetActors(tid)`, `OThread.GetActor(tid, i)`, `OThread.GetActorPosition(tid, Act)` |
| furniture | `OThread.GetFurniture(tid)`, `OThread.GetFurnitureType(tid)`, `OThread.ChangeFurniture(...)` |
| excitement | `OActor.GetExcitement / SetExcitement / ModifyExcitement(Act, x, RespectMultiplier)`, `Get/SetExcitementMultiplier`; mirrored in faction rank `OStimExcitementFaction` (0xD93) and `OStimTimeUntilClimaxFaction` (0xE4A: seconds to climax, 101 = >100 s, -1 = cannot climax in this scene) (`DATA\list of API forms.txt`) |
| climax | `OActor.Climax(Act, IgnoreStall)`, `OActor.GetTimesClimaxed(Act)` (faction `OStimTimesClimaxedFaction` 0xE49), `OActor.StallClimax/PermitClimax/IsClimaxStalled(Act, CheckThread)`, `OThread.StallClimax/PermitClimax(tid, PermitActors)/IsClimaxStalled(tid)` |
| auto mode | `OThread.IsInAutoMode / StartAutoMode / StopAutoMode(tid)` |
| player control | `OPlayerThread.SetPlayerControl(bool)` |
| tag the thread | builder `SetMetadataCSV("glue,chim")`; `OThread.AddMetadata/HasMetadata/GetMetadata`, `SetMetaString/GetMetaString/SetMetaFloat/GetMetaFloat` (metadata array is returned in the end-event JSON via `OJSON.GetMetadata`) |
| end now | `OThread.Stop(tid)` (faded for the player thread if MCM `UseFades`); thread also auto-ends when any actor enters combat or dies, NPC threads when leaving the player's cell, and on the `SetDuration` timer (UPSTREAM `Thread::loop`) |
| other API factions | `OStimActorCountFaction` 0xECA, `OStimSchlongifiedFaction` 0xE9C, `OStimInWaterFaction` 0xEDF; formlists `OStatActorList` 0x815, `OStatPlayerPartnerList` 0xA03 (all in `OStim.esp`; ranks are mirrors, read-only) |

---

## 7. Actor validity checks

VERIFIED Papyrus surface:

```papyrus
bool Function VerifyActors(Actor[] Actors) Global Native            ; OActor.psc:468  "verifies if all of the given actors are eligible for OStim scenes"
int Function Create(Actor[] Actors) Global Native                   ; OThreadBuilder.psc:46  "returns -1 if at least one of the actors is invalid"
Actor[] Function GetActorsInRangeV2(ObjectReference Center, float Range, bool IncludeCenter = false, bool IncludePlayer = true, bool OStimActorsOnly = false, Perk Condition = None) Global Native ; OActorUtil.psc:189 "OStimActorsOnly, if true only actors that qualify for OStim scenes"
bool Function IsChild(actor act) Global                             ; OUtils.psc:189 (also OSexIntegrationMain.IsChild L2636)
bool Function FulfillsCondition(Actor Act, Perk Condition) Global Native ; OActorUtil.psc:39
```

`OUtils.IsChild` body (VERIFIED L189-201): `act.IsChild()` OR the string `OSANative.GetName(act) + MiscUtil.GetRaceEditorID(race)` contains `"Child"`; its comment: "some mods unset this flag. Note: OSA will automatically fail start scenes with child actors, so you don't have to use this to filter actors beforehand". Requires PapyrusUtil's `MiscUtil`.

DLL rule (UPSTREAM `Core/Core.cpp`; message `" is not eligible for OStim"` VERIFIED DLL-str 96808):

```cpp
bool isEligible(GameAPI::GameActor actor) {
    return !actor.isDisabled() && !actor.isDeleted() && !actor.isChild() && !actor.isDead() &&
           ActorProperties::ActorPropertyTable::getActorType(actor) != "" &&
           !ThreadManager::GetSingleton()->findActor(actor);
}
```

`isChild()` = engine `Actor::IsChild()` (UPSTREAM `GameActor.h`). The actor type comes from `DATA\actor properties\OStimNPC.json` -> perk `OStimNPCCondition` (`OStim.esp` 0xE42). I parsed the perk's CTDA records read-only (VERIFIED raw data): `func 560 (param 0x00013794) == 1`, `func 69 (param 0x0010760A) == 0`, `func 365 == 0`. INFERENCE (function-index names from memory of the CK condition table): HasKeyword(ActorTypeNPC) AND NOT GetIsRace(ManakinRace) AND NOT IsChild. So creatures/non-ActorTypeNPC, children, dead, disabled, deleted actors and **actors already in another thread** all fail; `VerifyActors` is therefore also a "is this actor free" check.

Not covered by OStim: age semantics beyond the child flag (e.g. "teen" follower mods, child-race replacers that clear the flag and do not have "Child" in race EditorID/name), consent, essential/quest state, combat state at start (combat only *ends* running threads). The glue's hard gate must add its own checks.

---

## 8. Settings

Storage (VERIFIED + UPSTREAM):
- Live values = GlobalVariables in `OStim.esp`, exposed as typed properties on `OSexIntegrationMain` (`SRC\OSexIntegrationMain.psc:52-1669`; pattern `GlobalVariable Property OStimXxx Auto` + `Bool/Int/Float Property Yyy` get/set). Read/write from Papyrus: `OUtils.GetOStim().EndOnPlayerOrgasm = false`, or `Game.GetFormFromFile` on the global. Addon settings pages are data-driven JSON in `Data/SKSE/Plugins/OStim/settings` bound to globals (`DATA\settings README.txt`; path string DLL-str 97929).
- Export/import JSON: UPSTREAM `Util.cpp` `documents_path()/"OStim/"/"mcmsettings.json"` = `%USERPROFILE%\Documents\My Games\Skyrim Special Edition\OStim\mcmsettings.json` (GOG: `...Skyrim Special Edition GOG`), legacy fallback `...\JCUser\OstimMCMSettings.json`; sibling files `alignment.json`, `config.json`, `ui_settings.json` (all five strings VERIFIED DLL-str 98199-98208). Triggered by `OData.ExportSettings()/ImportSettings()/ResetSettings()` and the `AutoExportSettings`/`AutoImportSettings` toggles (import runs in `OnLoadGame`, L2170-2174). **On this machine `Documents\My Games\Skyrim Special Edition\OStim\` does not exist yet** and no `mcmsettings.json` exists anywhere under `F:\Modlists\LoreRim\{mods,overwrite,profiles}` (checked) - OStim was added to profile `Ultra` at 01:43 and the last SKSE log is 01:14, i.e. the game has not run with it; all settings are ESP defaults until first run. Under MO2 this Documents path is not virtualised (INFERENCE), so it is shared by all profiles.
- JSON keys (VERIFIED DLL-str 96481-96599), e.g.: `SetUseFades, SetUseIntroScenes, addActorsAtStart, SetAIControl, SetForceAIForMasturbation, SetForceAIIfAttacking, SetForceAIIfAttacked, SetForceAIInConsensualScenes, navigationDistanceMax, autoModeLimitToNavigationDistance, SetUseAutoFades, autoModeAnimDurationMin/Max, SetEnableFurniture, SetSelectFurniture, SetFurnitureSearchDistance, endOnPlayerOrgasm, SetEndOnOrgasm, SetEndOnSubOrgasm, SetEndOnBothOrgasm, SetAutoClimaxAnims, SetAlwaysUndressAtStart, SetRemoveWeaponsAtStart, SetUndressIfNeed, SetPartialUndressing, SetAnimateRedress, undressWigs, SetUndressingSlotMask, PlayerAlwaysDomStraight, PlayerAlwaysSubStraight, PlayerAlwaysDomGay, PlayerAlwaysSubGay, playerSelectRoleStraight, playerSelectRoleGay, playerSelectRoleThreesome, SetOnlyGayAnimsInGayScenes, useSoSSex, useTNGSex, futaUseMaleRole, playerDialogue, unrestrictedNavigation, NPCSceneDuration, endNPCSceneOnOrgasm`.

Settings that matter to an integration (property name -> GlobalVariable; line in `OSexIntegrationMain.psc`):

| Property | Global | Line | Effect | Per-thread override |
|---|---|---|---|---|
| `UseAIControl` | `OStimUseAutoModeAlways` | 476 | player thread always auto mode | builder `NoAutoMode`; runtime `Start/StopAutoMode` |
| `UseAIMasturbation` / `UseAIPlayerAggressor` / `UseAIPlayerAggressed` / `UseAINonAggressive` | `OStimUseAutoModeSolo / Dominant / Submissive / Vanilla` | 490-544 | auto mode by scene kind | same |
| `AutoModeLimitToNavigationDistance`, `UseAutoFades`, `AutoModeAnimDurationMin/Max`, `AutoModeForeplayChance`, `...Threshold...`, `AutoModePulloutChance` | `OStimAutoMode*` | 547-683 | auto-mode pacing; `UseAutoFades` also fades `NavigateTo` on the player thread | - |
| `NavigationDistanceMax` | `OStimNavigationDistanceMax` | 465 | max route length for `NavigateTo`/`GetScenesInRange` | `GetScenesInRange(.., Distance)` only |
| `EndOnPlayerOrgasm`, `EndOnMaleOrgasm`, `EndOnFemaleOrgasm`, `EndOnAllOrgasm` | `OStimEndOn*Orgasm` | 876-930 | thread stops ~4 s after the matching climax | none (global). Workarounds: `OThread.StallClimax`, or temporarily set the property |
| `EndNPCSceneOnOrgasm`, `NPCSceneDuration` | | 440-462 | NPC threads only | `SetDuration` |
| `AutoClimaxAnimations` | `OStimAutoClimaxAnimations` | 960 | navigate to the `"climax"` auto transition at 100 excitement | - |
| `AlwaysUndressAtAnimStart`, `RemoveWeaponsAtStart`, `AutoUndressIfNeeded`, `PartialUndressing`, `RemoveWeaponsWithSlot`, `FullyAnimateRedress`, `UndressWigs`; slot mask via `OData.Get/SetUndressingSlotMask` (default `0x3D8BC39D`, UPSTREAM) | `OStimUndress*` | 977-1079 | undress rules | builder `UndressActors` / `NoUndressing`; runtime `OActor.Undress*` |
| `UseFurniture`, `SelectFurniture`, `FurnitureSearchDistance`, `ResetClutter`, `BedRealignment`, `BedOffset` | `OStimUseFurniture ...` | 1365-1445 | furniture auto-use and the selection **message boxes** | builder `SetFurniture` / `NoFurniture` |
| `AddActorsAtStart` | `OStimAddActorsAtStart` | 109 | add-actor **message box** at start (only when no starting animation) | pass a starting animation (OCR instead toggles the global temporarily, `OCR_OStimScenesUtil.psc:221-226`) |
| `PlayerAlwaysDomStraight/SubStraight/DomGay/SubGay`, `PlayerSelectRoleStraight/Gay/Threesome` | `OStimPlayerAlways*`, `OStimPlayerSelectRole*` | 1098-1194 | sorting of 2-actor scenes / role **message box** (only when OStim sorts, i.e. no starting animation, or when you call `OActorUtil.Sort`/`SelectIndexAndSort`) | `OActorUtil.Sort(.., PlayerIndex)` |
| `IntendedSexOnly` | `OStimIntendedSexOnly` | 1084 | restrict scenes to intended sexes | - |
| `UseSoSSex`, `UseTNGSex`, `FutaUseMaleRole/Excitement/Climax` | | 1219-1287 | who counts as schlong-haver | - |
| `UseFades`, `UseIntroScenes` | `OStimUseFades`, `OStimUseIntroScenes` | 81-107 | fade on start/stop; intro vs idle start scene | - |
| `PlayerDialogue`, `MoanVolume`, `...DialogueCountdown...` | | 1487-1624 | OStim's own voiced dialogue/moans during scenes (may collide with CHIM TTS) | `OActor.Mute/Unmute` |
| `CustomTimescale`, `SlowMoOnOrgasm`, `BlurOnOrgasm`, `UseFreeCam`, `ForceFirstPersonAfter` | | 71, 932-958, 689, 723 | cosmetic, but timescale/slow-mo affect anything time-based | - |
| keys: `KeyMap` (scene start, default-registered via `RegisterForKey`), `KeyNPCStart`, `KeyEnd`, `SpeedUpKey`, `SpeedDownKey`, `PullOutKey`, `ControlToggleKey`, `SearchKey`, `AlignmentKey`, `HideUIKey`, `FreecamKey` | `OStimKey*` | 172-344 | hotkeys | - |

---

## 9. Version

- `OST\meta.ini`: `modid=98163`, `version=7.5.1.0`, `installationFile=OStim Standalone 98163 7.5.1 2026-09-02T11-29Z HZCMOyQBQ.zip`.
- DLL version resource (UTF-16 strings): `FileVersion 7.5.1.0`, `ProductVersion 7.5.1`, `ProductName OStim`. `OStim.dll` 8 852 480 bytes, 2026-09-02; `OStim.pdb` shipped alongside.
- `SRC\OSexIntegrationMain.psc:2125-2130`: `int PluginVersion = SKSE.GetPluginVersion("OStim")` ... `ElseIf PluginVersion != 0x07050010` -> "being overwritten with an old version" message box. Encoding per `DATA\API version README.txt:13-22`: major(2 hex) minor(2) patch(3) hotfix(1) -> `0x07050010` = 7.5.1 (no hotfix).
- API-version function: `Int Function GetAPIVersion()` (`OSexIntegrationMain.psc:2493`, instance; `OUtils.GetOStim().GetAPIVersion()`) returns the same value; static alternative `SKSE.GetPluginVersion("OStim")`. Old integer scheme: 26 = old OStim ... 33 = Standalone 7.2d (`DATA\API version README.txt:1-9`); `OStimAddon.RequiredVersion = 24` default. Highest "required API version" mentioned in the shipped `.psc`: 7.4d `0x07040004` (`NoPostDialogue`), 7.3.5c `0x07030053` (`OActor.GetThreadID`).
- Runtime: DLL statically links "CommonLibSSE-NG 7.1.0" (DLL-str 94209) and loads Address Library by runtime (`Data/SKSE/Plugins/version-{}.bin`, `versionlib-{}.bin`, VR csv; DLL-str 96478-96480) -> one binary for SE 1.5.97 / AE 1.6.x / VR. LoreRim runtime VERIFIED from `Documents\My Games\Skyrim Special Edition\SKSE\skse64.log` line 1: `SKSE64 runtime: initialize (version = 2.2.6 01064920 ...)` = 1.6.1170. `OStim.esp` masters: `Skyrim.esm`, `HearthFires.esm`. In profile `Ultra`: `+OStim Standalone...`, `+OStim Community Resource`, `+Open Animations Romance and Erotica...`, `+Nemesis Output - OStim` (modlist.txt 2-6), plugins `*OStim.esp`, `*OStimCommunityResource.esp` (plugins.txt 3413-3414). No `OStim.log` exists yet (never run).
- Hard dependencies visible in scripts: SKSE, PapyrusUtil (`MiscUtil`, `StorageUtil`, `PapyrusUtil`), SkyUI (`SKI_ConfigBase`, `SKI_WidgetBase`), NiOverride/RaceMenu, JContainers (`OUtils.KeycodeToKey`), optional UIExtensions, ConsoleUtil (`SetGameSpeed`).

---

## 10. OStim Community Resource (OCR)

`OCR\meta.ini`: Nexus mod 106519, `version=1.17.6.0`. Plugin `OStimCommunityResource.esp` (+ `.seq`). It is a shared asset/utility base for OStim romance/quest mods: (a) extra OStim data - 27 action JSONs (`3pp_*` third-person-perspective variants, `ejaculation_on_*`, `internalclimax`, `audiblebreathing`, `lickingear`, `openmouth`, `tongueout`, `sleepingsound`, `undress_*_target`), 10 facial-expression JSONs, scene JSONs and **sequences** `OCR_{MF,FM,NPC_MF,NPC_FM}_{CaressCheekStroke, CaressFail, CaressHoldHands, CaressHug, Chatter, ChatterFail, Court, CourtFail, Kiss1, StandingConversation, StandingConversationLoop}` under `OCR\SKSE\Plugins\OStim\`; (b) animation and NPC-asset meshes/textures; (c) Papyrus quests for "private cell" visits (inn/camp, follower handling, skinny dipping, smart undressing), NPC attraction and commitment scoring, multi-partner candidate selection, and sequence playback. 22 `.psc`: 11 functional + 8 `OCR_TIF__*` topic-info fragments + 3 `QF_OCR_*` quest fragments.

Functional scripts (signatures in Appendix B): `OCR_OStimUtil` (`Function StartOstimSequence(Actor[] actors, string sequenceName, bool exitOnEnd = true, ObjectReference furnObj = none)` L6 - canonical builder+sequence example: `Create` -> `SetStartingSequence` -> `EndAfterSequence` -> `NoFurniture`/`SetFurniture` -> `Start`), `OCR_OStimScenesUtil` (`OCR_StartScene(actor InvitedNPC)` L29 and `OCR_StartScene2P..6P` - all end in `OThread.QuickStart(Actors)` with `PlayerRef` at index 0; temporarily zeroes `OStimAddActorsAtStart` and restores it on `ostim_end` via `OCR_RestoreAddActorsAtStart`), `OCR_OStimSequencesUtil` (`string Function GetSequence(string actionType)` L11, `GetSequenceNPC` L102 and one bool helper per sequence), `OCR_AttractionUtil` (`float Function CalculateNPCAttraction(actor actor1)` L93, `int Function GetAttractivenessThreshold(Actor actor1)` L262 ...), `OCR_CommitmentUtil` (`int function CalculateNPCCommitment(actor actor1)` L60, `AssignSocialClass` L106), `OCR_PrivateCellsUtil`, `OCR_PrivateCells_PlayerDialogue`, `OCR_PrivateCells_FollowDialogue`, `OCR_GlobalFunctions` (`AdvanceTimeByHours(...) global` L3), `OCR_RestoreAddActorsAtStart`, `OCR_UndressInWater`. None of them add natives; all are instance scripts on OCR quests (not a stable public API).

---

## Implications for the glue (recommendations)

1. **Use only the static API** (`OThread/OThreadBuilder/OActor/OActorUtil/OLibrary/OMetadata/OFurniture/OJSON/OPlayerThread`). No property on OStim quests is needed except MCM reads via `OUtils.GetOStim()`. No SKSE DLL is justified by OStim: everything needed (start, navigate, query, events) is reachable from Papyrus.
2. **Hard gate before `Create`** (all must pass): target is not the player, `!OUtils.IsChild(npc)` AND `!npc.IsChild()`, own adult checks (race/EditorID/keyword allow-list - OStim's child test is flag-based), `OActor.VerifyActors([player, npc])` (covers dead/disabled/non-NPC/already-in-scene), both 3D-loaded, neither in combat, `!OThread.IsRunning(0)`. Treat `Create == -1` and `Start == -1` as refusal and report the reason back to the server.
3. **Menuless start recipe**: `actors = OActorUtil.Sort(OActorUtil.ToArray(player, npc), OActorUtil.EmptyArray())` (or an explicit `PlayerIndex`; never `SelectIndexAndSort`, it opens a Message) -> pick furniture yourself (`OFurniture.FindFurnitureOfType("bed", player, radius, 96.0)` or `FindFurniture`) -> `ft = OFurniture.GetFurnitureType(ref)` -> `scene = OLibrary.GetRandomSceneSuperloadCSV(actors, FurnitureType = ft-or-"", AnySceneTag = "idle" | chosen tags / AnyActionType = ...)` -> builder: `SetStartingAnimation(scene)`, `SetFurniture(ref)` **or** `NoFurniture()`, `SetMetadataCSV("<glue tag>")`, optionally `NoPlayerControl()` (fully conversational) and `NoAutoMode()` (LLM drives navigation; remember it also blocks later `StartAutoMode`) -> `Start`. Because a starting animation is set, no add-actor, role or (with explicit furniture choice) bed prompt can appear.
4. **Confirm start asynchronously**: register `ostim_thread_start` (numArg == 0) / `ostim_start`; add a timeout (the DLL waits while any actor `isInDialogue`, so never call `Start` from inside a vanilla dialogue the player is still in without expecting a delay; with CHIM's non-menu conversation this should not trigger, but verify in game). Failure modes are only notifications (`"no starting animation found"`), not return codes.
5. **Scene awareness**: subscribe to `ostim_thread_scenechanged` (filter ThreadID 0), `ostim_thread_speedchanged`, `ostim_actor_orgasm`, `ostim_thread_end`, optionally `ostim_event` and `ostim_furniturechanged`. On scene change build a compact, clinical descriptor from `OMetadata.GetName`, `GetSceneTags`, `GetActorTags(i)`, `GetActionTypes` + `GetActionActor/Target/Performer`, `IsTransition` (skip transitions), `OThread.GetSpeed`/`GetMaxSpeed`, `OActor.GetExcitement`. Debounce: a navigated route emits one event per hop.
6. **Conversational navigation with OStim's own graph**: candidate list = `OLibrary.GetScenesInRange(current, actors, distance)` -> names via `OMetadata.ScenesToNames`, enrich with tags/actions, drop `IsTransition`, send to the server; LLM picks an id; execute `OThread.NavigateTo(0, id)` (transitions play; falls back to a warp beyond `NavigationDistanceMax`). For "something with X" intents use the superload search with `FurnitureType = OThread.GetFurnitureType(0)` and then `NavigateTo`. Use `QueueNavigation` for multi-step plans, `SetSpeed` for pace, `AutoTransition(0, "climax")`/`OActor.Climax` for finish, `OThread.Stop(0)` to end. Because `OMetadata` lacks furniture/navigation/duration getters, consider a server-side index of the scene JSON files (another agent is already producing `research\ostim_scene_stats.json`).
7. **Keep OStim from ending the scene under the LLM**: `EndOn*Orgasm` are global; either leave user settings alone and accept the ~4 s auto-stop, or `OThread.StallClimax(0)` and release deliberately. Do not silently rewrite user MCM globals; if needed, save/restore like OCR does for `OStimAddActorsAtStart`.
8. **Audio collision**: OStim voices moans/dialogue (`PlayerDialogue`, voice sets) and has post-scene dialogue; for CHIM TTS consider builder `NoPostDialogue()` and `OActor.Mute(npc)` while the NPC speaks (then `Unmute`).
9. **Robust end handling**: threads also end on combat/death/timer/player key. Always reconcile on `ostim_thread_end` (numArg 0) and use `OJSON.GetMetadata(json)` to recognise threads the glue started; never assume the glue is the only starter (OCR, hotkey `KeyMap`, other mods).
10. Version guard at init: `SKSE.GetPluginVersion("OStim") >= 0x07040004` (needs `NoPostDialogue`; `GetThreadID` needs `0x07030053`). Compile against the shipped `SRC\*.psc` headers.

## Open questions

1. UPSTREAM `main` vs installed 7.5.1 binary: event argument details (`ostim_end` numArg -1, speed as StrArg of `ostim_thread_speedchanged`), the `NoAutoMode`-blocks-`StartAutoMode` behaviour, `QueueWarp` fade quirk and the dialogue-wait at start need an in-game confirmation (or a tag-exact source checkout, which was out of scope: no cloning allowed).
2. Does CHIM's conversation state count as `isInDialogue` for the DLL (which would delay the asynchronous player-thread start)? Needs an in-game test.
3. CK condition function indices 560 / 69 / 365 in `OStimNPCCondition` were named from memory (HasKeyword / GetIsRace / IsChild); confirm in xEdit. Also confirm 0x0010760A = ManakinRace.
4. Does the alias system apply to searches (`AnyActionType = "intercourse"` / `"cunnilingus"`) and do getters return canonical ids? Check `DATA\actions\*.json` aliases vs `OMetadata.GetActionTypes` output in game.
5. Actual MCM values LoreRim/the user will run with are unknown - `mcmsettings.json` does not exist yet; defaults live in `OStim.esp` globals (not dumped here). Which of `UseFades`, `SelectFurniture`, `AddActorsAtStart`, `EndOn*Orgasm`, `UseAIControl` are on by default should be read from the ESP or after first launch.
6. Exact semantics of `OThread.IsRunning(0)` during the asynchronous pre-start window (before `ThreadManager::startThread`) - likely false until the thread object exists; the glue needs its own "start pending" state.
7. The native C++ API headers (`OstimNG_API::Scene` / `::Thread`, interface versions, `ThreadEvent` enum values) were only seen as symbol strings; inspect upstream `ModAPI/` headers if a DLL route is ever considered.
8. OARE 1.52.1 scene/tag inventory and its navigation hubs are out of scope here (covered by the scene-stats task).

---

## Appendix A - OStim Standalone: complete generated signature index

Machine-extracted from the installed `.psc` files (every column-0 line containing `Function`, `Event` or `Property`; `; L<n>` = source line; text after the dash = first line of the function's doc comment where one exists). Counts in the headings are heuristic (keyword after the closing parenthesis). Property get/set bodies and MCM state events (indented) are intentionally omitted.

### OThread  (`...\OStim Standalone - Advanced Adult Animation Framework\Scripts\Source\OThread.psc`, 448 lines; native=37, global-Papyrus=0, instance/other=0)

```papyrus
; L29 - starts a new thread without the need of a thread builder, but only limited parameters
int Function QuickStart(Actor[] Actors, string StartingAnimation = "", ObjectReference FurnitureRef = None) Global Native
; L46 - checks if the thread is still running
bool Function IsRunning(int ThreadID) Global Native
; L53 - ends the thread
Function Stop(int ThreadID) Global Native
; L60 - return the number of currently running threads, including the player thread if it is running
int Function GetThreadCount() Global Native
; L69 - returns a list of all currently running thread IDs
int[] Function GetAllThreadIDs() Global Native
; L86 - returns the scene id of the scene that is currently running in the thread
string Function GetScene(int ThreadID) Global Native
; L96 - tries to naviate the thread to a new scene
Function NavigateTo(int ThreadID, string SceneID) Global Native
; L109 - tries to navigate the thread to a new scene after the currently running navigation is done
Function QueueNavigation(int ThreadID, string SceneID, float Duration) Global Native
; L119 - warps the thread to a new scene
Function WarpTo(int ThreadID, string SceneID, bool UseFades = False) Global Native
; L131 - warps the thread to a new scene after the currently running navigation is done
Function QueueWarp(int ThreadID, string SceneID, float Duration) Global Native
; L141 - plays the auto transition for the thread
bool Function AutoTransition(int ThreadID, string Type) Global Native
; L152 - plays the auto transition for the actor
bool Function AutoTransitionForActor(int ThreadID, int Index, string Type) Global Native
; L161 - returns the speed index at which the thread is currently running
int Function GetSpeed(int ThreadID) Global Native
; L170 - sets the speed index at which the thread will run
Function SetSpeed(int ThreadID, int Speed) Global Native
; L181 - plays the sequence on the thread
Function PlaySequence(int ThreadID, string Sequence, bool NavigateTo = false, bool UseFades = false) Global Native
; L198 - returns the actors of the thread
Actor[] Function GetActors(int ThreadID) Global Native
; L208 - returns the actor at the given index
Actor Function GetActor(int ThreadID, int Index) Global Native
; L218 - returns the index of the actor in the thread
int Function GetActorPosition(int ThreadID, Actor Act) Global Native
; L234 - prevents all actors in the thread from climaxing, including the prevention of auto climax animations
Function StallClimax(int ThreadID) Global Native
; L242 - permits the actors in the thread to climax again (as in it undoes StallClimax)
Function PermitClimax(int ThreadID, bool PermitActors = false) Global Native
; L251 - checks if this actor is currently prevented from climaxing
bool Function IsClimaxStalled(int ThreadID) Global Native
; L268 - returns the furniture object used by the thread
ObjectReference Function GetFurniture(int ThreadID) Global Native
; L277 - returns the furniture type used in the thread
string Function GetFurnitureType(int ThreadID) Global Native
; L288 - moves the scene to a new furniture object
Function ChangeFurniture(int ThreadID, ObjectReference FurnitureRef, string SceneID = "") Global Native
; L305 - checks if the thread is currently running in automatic mode
bool Function IsInAutoMode(int ThreadID) Global Native
; L312 - sets the thread to automatic mode
Function StartAutoMode(int ThreadID) Global Native
; L321 - sets the thread to manual mode
Function StopAutoMode(int ThreadID) Global Native
; L339 - checks if the thread has a specific metadata
bool Function HasMetadata(int ThreadID, string Metadata) Global Native
; L347 - adds metadata to the thread
Function AddMetadata(int ThreadID, string Metadata) Global Native
; L356 - returns a list of all metadata of the thread
string[] Function GetMetadata(int ThreadID) Global Native
; L369 - checks if the thread has a float value for the key
bool Function HasMetaFloat(int ThreadID, string MetaID) Global Native
; L381 - returns the threads float value for the key
float Function GetMetaFloat(int ThreadID, string MetaID) Global Native
; L392 - sets the threads float value for the key
Function SetMetaFloat(int ThreadID, string MetaID, float Value) Global Native
; L405 - checks if the thread has a string value for the key
bool Function HasMetaString(int ThreadID, string MetaID) Global Native
; L417 - returns the threads string value for the key
string Function GetMetaString(int ThreadID, string MetaID) Global Native
; L428 - sets the threads string value for the key
Function SetMetaString(int ThreadID, string MetaID, string Value) Global Native
; L448 - calls the event for the thread, events and their properties can be defined in data/SKSE/plugins/OStim/events
Function CallEvent(int ThreadID, string EventName, int Actor, int Target = -1, int Performer = -1) Global Native
```

### OThreadBuilder  (`...\OStim Standalone - Advanced Adult Animation Framework\Scripts\Source\OThreadBuilder.psc`, 223 lines; native=19, global-Papyrus=0, instance/other=1)

```papyrus
; L14 - example function to show the use of the OThreadBuilder script
Function Example(Actor[] Actors)
; L46 - creates a a new thread builder
int Function Create(Actor[] Actors) Global Native
; L55 - sets the actors to be dominant in the scene
Function SetDominantActors(int BuilderID, Actor[] Actors) Global Native
; L63 - sets the furniture to use in the thread
Function SetFurniture(int BuilderID, ObjectReference FurnitureRef) Global Native
; L74 - sets the duration of the thread (in seconds), when this duration is over the thread ends
Function SetDuration(int BuilderID, float Duration) Global Native
; L83 - sets the starting animation of the scene
Function SetStartingAnimation(int BuilderID, string Animation) Global Native
; L95 - adds another animation to the list of starting animations
Function AddStartingAnimation(int BuilderID, string Animation, float Duration = 0.0, bool NavigateTo = false) Global Native
; L106 - sets a sequence as the starting animations of the scene
Function SetStartingSequence(int BuilderID, string Sequence) Global Native
; L117 - adds another sequence to the list of starting animations
Function ConcatStartingSequence(int BuilderID, string Sequence, bool NavigateTo = false) Global Native
; L126 - sets the thread to end when the starting animations have played through
Function EndAfterSequence(int BuidlerID) Global Native
; L136 - sets the thread to strip all actors on start
Function UndressActors(int BuilderID) Global Native
; L148 - disables auto mode for the scene
Function NoAutoMode(int BuilderID) Global Native
; L157 - disables player control for the scene, does nothing on NPCxNPC scenes
Function NoPlayerControl(int BuilderID) Global Native
; L166 - disables post scene dialogue for the thread
Function NoPostDialogue(int BuilderID) Global Native
; L176 - disables all undressing during the scene, no matter the MCM settings
Function NoUndressing(int BuilderID) Global Native
; L189 - disables furniture for the scene
Function NoFurniture(int BuilderID) Global Native
; L197 - sets the metadata of the thread
Function SetMetadata(int BuilderID, string[] Metadata) Global Native
; L205 - sets the metadata of the thread
Function SetMetadataCSV(int BuilderID, string Metadata) Global Native
; L216 - starts the thread
int Function Start(int BuilderID) Global Native
; L223 - disposes of the thread builder, freeing up the id again
Function Cancel(int BuilderID) Global Native
```

### OPlayerThread  (`...\OStim Standalone - Advanced Adult Animation Framework\Scripts\Source\OPlayerThread.psc`, 13 lines; native=1, global-Papyrus=0, instance/other=0)

```papyrus
; L13 - enables/disables the manual control of the thread
Function SetPlayerControl(bool Control) Global Native
```

### OActor  (`...\OStim Standalone - Advanced Adult Animation Framework\Scripts\Source\OActor.psc`, 494 lines; native=41, global-Papyrus=4, instance/other=0)

```papyrus
; L23 - returns the actors current excitement level
float Function GetExcitement(Actor Act) Global Native
; L32 - sets the excitement of an actor
Function SetExcitement(Actor Act, float Excitement) Global Native
; L42 - modifies the excitement of the actor by the given value
Function ModifyExcitement(Actor Act, float Excitement, bool RespectMultiplier = false) Global Native
; L51 - returns the excitement multiplier for the actor, by default this is the setting in the MCM
float Function GetExcitementMultiplier(Actor Act) Global Native
; L59 - sets the excitement multiplier for the actor
Function SetExcitementMultiplier(Actor Act, float Multiplier) Global Native
; L74 - prevents this actor from climaxing, including the prevention of auto climax animations
Function StallClimax(Actor Act) Global Native
; L81 - permits this actor to climax again (as in it undoes StallClimax)
Function PermitClimax(Actor Act) Global Native
; L91 - checks if this actor is currently prevented from climaxing
bool Function IsClimaxStalled(Actor Act, bool CheckThread = true) Global Native
; L99 - causes the actor to have a climax
Function Climax(Actor Act, bool IgnoreStall = false) Global Native
; L108 - returns the amount of climaxes the actor had in the current scene
int Function GetTimesClimaxed(Actor Act) Global Native
; L127 - enables or disabled expressions for the given actor
Function SetExpressionsEnabled(Actor Act, bool Enabled, bool AllowOverride = true) Global Native
; L138 - plays the facial expression event on the actor
float Function PlayExpression(Actor Act, string Expression) Global Native
; L145 - resets the factial expression to the underlying expression based on the scenes actions, clearing all event expressions
Function ClearExpression(Actor Act) Global Native
; L154 - checks if the actor has an expression override (e.g. is performing an oral action or has their mouth otherwise open)
bool Function HasExpressionOverride(Actor Act) Global Native
; L169 - mutes an actor, preventing them from moaning and talking
Function Mute(Actor Act) Global Native
; L176 - unmutes an actor, enabling them to moan and talk again
Function Unmute(Actor Act) Global Native
; L185 - checks if an actor is muted
bool Function IsMuted(Actor Act) Global Native
; L200 - fully undresses the actor
Function Undress(Actor Act) Global Native
; L207 - redresses all items that were undressed during the current scene
Function Redress(Actor Act) Global Native
; L216 - undresses all items on the actor that overlap with the given slot mask
Function UndressPartial(Actor Act, int Mask) Global Native
; L225 - redresses all items that were undressed during the current scene and overlap with the given slot mask
Function RedressPartial(Actor Act, int Mask) Global Native
; L232 - removes the weapons
Function RemoveWeapons(Actor Act) Global Native
; L239 - adds back the weapons that were removed during the current scene
Function AddWeapons(Actor Act) Global Native
; L262 - equips an object on an actor
bool Function EquipObject(Actor Act, string Type) Global Native
; L270 - unequips an object on from actor
Function UnequipObject(Actor Act, string Type) Global Native
; L280 - checks if an object type is currently equipped on an actor
bool Function IsObjectEquipped(Actor Act, string Type) Global Native
; L292 - sets the variant of an object on an actor
bool Function SetObjectVariant(Actor Act, string Type, string Variant, float Duration = 0.0) Global Native
; L300 - sets the objects variant back to the default one
Function UnsetObjectVariant(Actor Act, string Type) Global Native
; L318 - plays the auto transition for the actor
bool Function AutoTransition(Actor Act, string Type) Global Native
; L338 - checks if the actor has a specific metadata
bool Function HasMetadata(Actor Act, string Metadata) Global Native
; L348 - adds metadata to the actor
Function AddMetadata(Actor Act, string Metadata) Global Native
; L359 - returns a list of all metadata of the actor
string[] Function GetMetadata(Actor Act) Global Native
; L372 - checks if the actor has a float value for the key
bool Function HasMetaFloat(Actor Act, string MetaID) Global Native
; L384 - returns the actors float value for the key
float Function GetMetaFloat(Actor Act, string MetaID) Global Native
; L395 - sets the actors float value for the key
Function SetMetaFloat(Actor Act, string MetaID, float Value) Global Native
; L408 - checks if the actor has a string value for the key
bool Function HasMetaString(Actor Act, string MetaID) Global Native
; L420 - returns the actors string value for the key
string Function GetMetaString(Actor Act, string MetaID) Global Native
; L431 - sets the actors string value for the key
Function SetMetaString(Actor Act, string MetaID, string Value) Global Native
; L448 - checks if the actor is currently involved in an OStim scene
bool Function IsInOStim(Actor Act) Global Native
; L459 - gets the thread ID of the thread involding the actor
int Function GetThreadID(Actor Act) Global Native
; L468 - verifies if all of the given actors are eligible for OStim scenes
bool Function VerifyActors(Actor[] Actors) Global Native
; L480
Function UpdateExpression(Actor Act) Global
; L484
bool Function HasSchlong(Actor Act) Global
; L488
Actor[] Function SortActors(Actor[] Actors, int PlayerIndex = -1) Global
; L492
int Function GetSceneID(Actor Act) Global
```

### OActorUtil  (`...\OStim Standalone - Advanced Adult Animation Framework\Scripts\Source\OActorUtil.psc`, 213 lines; native=12, global-Papyrus=2, instance/other=0)

```papyrus
; L26 - checks if the actor has a schlong
bool Function HasSchlong(Actor Act) Global Native
; L39 - checks if the actor fulfills the condition functions of the perk
bool Function FulfillsCondition(Actor Act, Perk Condition) Global Native
; L52 - checks if the actor fulfills the condition functions of any of the perks
bool Function FulfillsAnyCondition(Actor Act, Perk[] Conditions) Global Native
; L65 - checks if the actor fulfills the condition functions of all of the perks
bool Function FulfillsAllConditions(Actor Act, Perk[] Conditions) Global Native
; L82 - says the dialogue topic the the target actor
Function SayTo(Actor Act, Actor Target, Topic Dialogue) Global Native
; L94 - says the dialogue topic to the target actor
Function SayAs(Actor Act, Actor Target, Topic Dialogue, VoiceType Voice) Global Native
; L109 - returns a size zero array of type Actor
Actor[] Function EmptyArray() Global Native
; L119 - returns an actor array of the desired size
Actor[] Function CreateArray(int Size, Actor Filler = None) Global Native
; L128 - creates an array out of the given actors, sorts out none entires
Actor[] Function ToArray(Actor One = None, Actor Two = None, Actor Three = None, Actor Four = None, Actor Five = None, Actor Six = None, Actor Seven = None, Actor Eight = None, Actor Nine = None, Actor Ten = None) Global Native
; L142 - sorts all dominant actors to the front of the array and all non dominant actors to the back
Actor[] Function Sort(Actor[] Actors, Actor[] DominantActors, int PlayerIndex = -1) Global Native
; L152 - pops up the index selection for the player depending on the MCM settings and then sorts the actors according to the selected value
Actor[] Function SelectIndexAndSort(Actor[] Actors, Actor[] DominantActors) Global
; L189 - gets all actors in range around the center
Actor[] Function GetActorsInRangeV2(ObjectReference Center, float Range, bool IncludeCenter = false, bool IncludePlayer = true, bool OStimActorsOnly = false, Perk Condition = None) Global Native
; L200 - converts an array of actors to an array of their names
string[] Function ActorsToNames(Actor[] Actors) Global Native
; L211
Actor[] Function GetActorsInRange(ObjectReference Center, float Range, bool IncludeCenter = false, bool IncludePlayer = true, Perk Condition = None) Global
```

### OLibrary  (`...\OStim Standalone - Advanced Adult Animation Framework\Scripts\Source\OLibrary.psc`, 1243 lines; native=97, global-Papyrus=0, instance/other=0)

```papyrus
; L30 - returns the list of all scenes
string[] Function GetAllScenes() Global Native
; L43 - returns a list of scenes in navigation range of the given scene
string[] Function GetScenesInRange(string Id, Actor[] Actors, int Distance = 0) Global Native
; L59 - returns a random scene applicable for the actors
string Function GetRandomScene(Actor[] Actors) Global Native
; L69 - returns a random furniture scene applicable for the actors
string Function GetRandomFurnitureScene(Actor[] Actors, string FurnitureType) Global Native
; L87 - returns a random scene applicable for the actors with a scene tag
string Function GetRandomSceneWithSceneTag(Actor[] Actors, string Tag) Global Native
; L97 - returns a random scene applicable for the actors with any of a list of scene tags
string Function GetRandomSceneWithAnySceneTag(Actor[] Actors, string[] Tags) Global Native
; L107 - same as GetRandomSceneWithAnyTag, except tags are given in a csv-string
string Function GetRandomSceneWithAnySceneTagCSV(Actor[] Actors, string Tags) Global Native
; L117 - returns a random scene applicable for the actors with all of a list of scene tags
string Function GetRandomSceneWithAllSceneTags(Actor[] Actors, string[] Tags) Global Native
; L127 - same as GetRandomSceneWithAllTags, except tags are given in a csv-string
string Function GetRandomSceneWithAllSceneTagsCSV(Actor[] Actors, string Tags) Global Native
; L139 - returns a random furniture scene applicable for the actors with a scene tag
string Function GetRandomFurnitureSceneWithSceneTag(Actor[] Actors, string FurnitureType, string Tag) Global Native
; L150 - returns a random furniture scene applicable for the actors with any of a list of scene tags
string Function GetRandomFurnitureSceneWithAnySceneTag(Actor[] Actors, string FurnitureType, string[] Tags) Global Native
; L161 - same as GetRandomFurnitureSceneWithAnyTag, except tags are given in a csv-string
string Function GetRandomFurnitureSceneWithAnySceneTagCSV(Actor[] Actors, string FurnitureType, string Tags) Global Native
; L172 - returns a random furniture scene applicable for the actors with all of a list of scene tags
string Function GetRandomFurnitureSceneWithAllSceneTags(Actor[] Actors, string FurnitureType, string[] Tags) Global Native
; L183 - same as GetRandomFurnitureSceneWithAllTags, except tags are given in a csv-string
string Function GetRandomFurnitureSceneWithAllSceneTagsCSV(Actor[] Actors, string FurnitureType, string Tags) Global Native
; L202 - returns a random scene applicable for the actors with a tag for a single actor
string Function GetRandomSceneWithSingleActorTag(Actor[] Actors, int Position, string Tag) Global Native
; L213 - returns a random scene applicable for the actors with any of a list of tags for a single actor
string Function GetRandomSceneWithAnySingleActorTag(Actor[] Actors, int Position, string[] Tags) Global Native
; L224 - same as GetRandomSceneWithAnySingleActorTag, except tags are given in a csv-string
string Function GetRandomSceneWithAnySingleActorTagCSV(Actor[] Actors, int Position, string Tags) Global Native
; L235 - returns a random scene applicable for the actors with all of a list of tags for a single actor
string Function GetRandomSceneWithAllSingleActorTags(Actor[] Actors, int Position, string[] Tags) Global Native
; L246 - same as GetRandomSceneWithAllSingleActorTags, except tags are given in a csv-string
string Function GetRandomSceneWithAllSingleActorTagsCSV(Actor[] Actors, int Position, string Tags) Global Native
; L259 - returns a random furniture scene applicable for the actors with a tag for a single actor
string Function GetRandomFurnitureSceneWithSingleActorTag(Actor[] Actors, string FurnitureType, int Position, string Tag) Global Native
; L271 - returns a random furniture scene applicable for the actors with any of a list of tags for a single actor
string Function GetRandomFurnitureSceneWithAnySingleActorTag(Actor[] Actors, string FurnitureType, int Position, string[] Tags) Global Native
; L283 - same as GetRandomFurnitureSceneWithAnySingleActorTag, except tags are given in a csv-string
string Function GetRandomFurnitureSceneWithAnySingleActorTagCSV(Actor[] Actors, string FurnitureType, int Position, string Tags) Global Native
; L295 - returns a random furniture scene applicable for the actors with all of a list of tags for a single actor
string Function GetRandomFurnitureSceneWithAllSingleActorTags(Actor[] Actors, string FurnitureType, int Position, string[] Tags) Global Native
; L307 - same as GetRandomFurnitureSceneWithAllSingleActorTags, except tags are given in a csv-string
string Function GetRandomFurnitureSceneWithAllSingleActorTagsCSV(Actor[] Actors, string FurnitureType, int Position, string Tags) Global Native
; L329 - returns a random scene applicable for the actors with at least one actor having the respective actor tag
string Function GetRandomSceneWithMultiActorTagForAny(Actor[] Actors, string[] Tags) Global Native
; L339 - same as GetRandomSceneWithMultiActorTagForAny, except tags are passed as a csv-string
string Function GetRandomSceneWithMultiActorTagForAnyCSV(Actor[] Actors, string Tags) Global Native
; L349 - returns a random scene applicable for the actors with all actors having the respective actor tag
string Function GetRandomSceneWithMultiActorTagForAll(Actor[] Actors, string[] Tags) Global Native
; L359 - same as GetRandomSceneWithMultiActorTagForAll, except tags are passed as a csv-string
string Function GetRandomSceneWithMultiActorTagForAllCSV(Actor[] Actors, string Tags) Global Native
; L369 - returns a random scene applicable for the actors with at least one actor having at least one of the respective actor tags
string Function GetRandomSceneWithAnyMultiActorTagForAnyCSV(Actor[] Actors, string Tags) Global Native
; L379 - returns a random scene applicable for the actors with all actors having at least one of the respective actor tags
string Function GetRandomSceneWithAnyMultiActorTagForAllCSV(Actor[] Actors, string Tags) Global Native
; L389 - returns a random scene applicable for the actors with at least one actor having all of the respective actor tags
string Function GetRandomSceneWithAllMultiActorTagsForAnyCSV(Actor[] Actors, string Tags) Global Native
; L399 - returns a random scene applicable for the actors with all actors having all of the respective actor tags
string Function GetRandomSceneWithAllMultiActorTagsForAllCSV(Actor[] Actors, string Tags) Global Native
; L411 - returns a random furniture scene applicable for the actors with at least one actor having the respective actor tag
string Function GetRandomFurnitureSceneWithMultiActorTagForAny(Actor[] Actors, string FurnitureType, string[] Tags) Global Native
; L422 - same as GetRandomFurnitureSceneWithMultiActorTagForAny, except tags are passed as a csv-string
string Function GetRandomFurnitureSceneWithMultiActorTagForAnyCSV(Actor[] Actors, string FurnitureType, string Tags) Global Native
; L433 - returns a random furniture scene applicable for the actors with all actors having the respective actor tag
string Function GetRandomFurnitureSceneWithMultiActorTagForAll(Actor[] Actors, string FurnitureType, string[] Tags) Global Native
; L444 - same as GetRandomFurnitureSceneWithMultiActorTagForAll, except tags are passed as a csv-string
string Function GetRandomFurnitureSceneWithMultiActorTagForAlLCSV(Actor[] Actors, string FurnitureType, string Tags) Global Native
; L455 - returns a random furniture scene applicable for the actors with at least one actor having at least one of the respective actor tags
string Function GetRandomFurnitureSceneWithAnyMultiActorTagForAnyCSV(Actor[] Actors, string FurnitureType, string Tags) Global Native
; L466 - returns a random furniture scene applicable for the actors with all actors having at least one of the respective actor tags
string Function GetRandomFurnitureSceneWithAnyMultiActorTagForAllCSV(Actor[] Actors, string FurnitureType, string Tags) Global Native
; L477 - returns a random furniture scene applicable for the actors with at least one actor having all of the respective actor tags
string Function GetRandomFurnitureSceneWithAllMultiActorTagsForAnyCSV(Actor[] Actors, string FurnitureType, string Tags) Global Native
; L488 - returns a random furniture scene applicable for the actors with all actors having all of the respective actor tags
string Function GetRandomFurnitureSceneWithAllMultiActorTagsForAllCSV(Actor[] Actors, string FurnitureType, string Tags) Global Native
; L507 - returns a random scene applicable for the actors with any of a list of scene tags and at least one actor having at least one of the respective actor tags
string Function GetRandomSceneWithAnySceneTagAndAnyMultiActorTagForAnyCSV(Actor[] Actors, string SceneTags, string ActorTags) Global Native
; L518 - returns a random scene applicable for the actors with all of a list of scene tags and at least one actor having at least one of the respective actor tags
string Function GetRandomSceneWithAllSceneTagsAndAnyMultiActorTagForAnyCSV(Actor[] Actors, string SceneTags, string ActorTags) Global Native
; L529 - returns a random scene applicable for the actors with any of a list of scene tags and all actors having at least one of the respective actor tags
string Function GetRandomSceneWithAnySceneTagAndAnyMultiActorTagForAllCSV(Actor[] Actors, string SceneTags, string ActorTags) Global Native
; L540 - returns a random scene applicable for the actors with all of a list of scene tags and all actors having at least one of the respective actor tags
string Function GetRandomSceneWithAllSceneTagsAndAnyMultiActorTagForAllCSV(Actor[] Actors, string SceneTags, string ActorTags) Global Native
; L551 - returns a random scene applicable for the actors with any of a list of scene tags and at least one actor having all of the respective actor tags
string Function GetRandomSceneWithAnySceneTagAndAllMultiActorTagsForAnyCSV(Actor[] Actors, string SceneTags, string ActorTags) Global Native
; L562 - returns a random scene applicable for the actors with all of a list of scene tags and at least one actor having all of the respective actor tags
string Function GetRandomSceneWithAllSceneTagsAndAllMultiActorTagsForAnyCSV(Actor[] Actors, string SceneTags, string ActorTags) Global Native
; L573 - returns a random scene applicable for the actors with any of a list of scene tags and all actors having all of the respective actor tags
string Function GetRandomSceneWithAnySceneTagAndAllMultiActorTagsForAllCSV(Actor[] Actors, string SceneTags, string ActorTags) Global Native
; L584 - returns a random scene applicable for the actors with all of a list of scene tags and all actors having all of the respective actor tags
string Function GetRandomSceneWithAllSceneTagsAndAllMultiActorTagsForAllCSV(Actor[] Actors, string SceneTags, string ActorTags) Global Native
; L597 - returns a random furniture scene applicable for the actors with any of a list of scene tags and at least one actor having at least one of the respective actor tags
string Function GetRandomFurnitureSceneWithAnySceneTagAndAnyMultiActorTagForAnyCSV(Actor[] Actors, string FurnitureType, string SceneTags, string ActorTags) Global Native
; L609 - returns a random furniture scene applicable for the actors with all of a list of scene tags and at least one actor having at least one of the respective actor tags
string Function GetRandomFurnitureSceneWithAllSceneTagsAndAnyMultiActorTagForAnyCSV(Actor[] Actors, string FurnitureType, string SceneTags, string ActorTags) Global Native
; L621 - returns a random furniture scene applicable for the actors with any of a list of scene tags and all actors having at least one of the respective actor tags
string Function GetRandomFurnitureSceneWithAnySceneTagAndAnyMultiActorTagForAllCSV(Actor[] Actors, string FurnitureType, string SceneTags, string ActorTags) Global Native
; L633 - returns a random furniture scene applicable for the actors with all of a list of scene tags and all actors having at least one of the respective actor tags
string Function GetRandomFurnitureSceneWithAllSceneTagsAndAnyMultiActorTagForAllCSV(Actor[] Actors, string FurnitureType, string SceneTags, string ActorTags) Global Native
; L645 - returns a random furniture scene applicable for the actors with any of a list of scene tags and at least one actor having all of the respective actor tags
string Function GetRandomFurnitureSceneWithAnySceneTagAndAllMultiActorTagsForAnyCSV(Actor[] Actors, string FurnitureType, string SceneTags, string ActorTags) Global Native
; L657 - returns a random furniture scene applicable for the actors with all of a list of scene tags and at least one actor having all of the respective actor tags
string Function GetRandomFurnitureSceneWithAllSceneTagsAndAllMultiActorTagsForAnyCSV(Actor[] Actors, string FurnitureType, string SceneTags, string ActorTags) Global Native
; L669 - returns a random furniture scene applicable for the actors with any of a list of scene tags and all actors having all of the respective actor tags
string Function GetRandomFurnitureSceneWithAnySceneTagAndAllMultiActorTagsForAllCSV(Actor[] Actors, string FurnitureType, string SceneTags, string ActorTags) Global Native
; L681 - returns a random furniture scene applicable for the actors with all of a list of scene tags and all actors having all of the respective actor tags
string Function GetRandomFurnitureSceneWithAllSceneTagsAndAllMultiActorTagsForAllCSV(Actor[] Actors, string FurnitureType, string SceneTags, string ActorTags) Global Native
; L699 - returns a random scene applicable for the actors with an action
string Function GetRandomSceneWithAction(Actor[] Actors, string Type) Global Native
; L709 - returns a random scene applicable for the actors with any of a list of actions
string Function GetRandomSceneWithAnyAction(Actor[] Actors, string[] Types) Global Native
; L719 - same as GetRandomSceneWithAnyAction, except types are passed in a csv-string
string Function GetRandomSceneWithAnyActionCSV(Actor[] Actors, string Types) Global Native
; L729 - returns a random scene applicable for the actors with all of a list of actions
string Function GetRandomSceneWithAllActions(Actor[] Actors, string[] Types) Global Native
; L739 - same as GetRandomSceneWithAllActions, except types are passed in a csv-string
string Function GetRandomSceneWithAllActionsCSV(Actor[] Actors, string Types) Global Native
; L751 - returns a random furniture scene applicable for the actors with an action
string Function GetRandomFurnitureSceneWithAction(Actor[] Actors, string FurnitureType, string Type) Global Native
; L762 - returns a random furniture scene applicable for the actors with any of a list of actions
string Function GetRandomFurnitureSceneWithAnyAction(Actor[] Actors, string FurnitureType, string[] Types) Global Native
; L773 - same as GetRandomFurnitureSceneWithAnyAction, except types are passed in a csv-string
string Function GetRandomFurnitureSceneWithAnyActionCSV(Actor[] Actors, string FurnitureType, string Types) Global Native
; L784 - returns a random furniture scene applicable for the actors with all of a list of actions
string Function GetRandomFurnitureSceneWithAllActions(Actor[] Actors, string FurnitureType, string[] Types) Global Native
; L795 - same as GetRandomFurnitureSceneWithAllActions, except types are passed in a csv-string
string Function GetRandomFurnitureSceneWithAllActionsCSV(Actor[] Actors, string FurnitureType, string Types) Global Native
; L814 - returns a random scene applicable for the actors with an action of an actor
string Function GetRandomSceneWithActionForActor(Actor[] Actors, int Position, string Type) Global Native
; L825 - returns a random scene applicable for the actors with any of a list of actions of an actor
string Function GetRandomSceneWithAnyActionForActor(Actor[] Actors, int Position, string[] Types) Global Native
; L836 - same as GetRandomSceneWithAnyActionForActor, except types are passed as csv-string
string Function GetRandomSceneWithAnyActionForActorCSV(Actor[] Actors, int Position, string Types) Global Native
; L847 - returns a random scene applicable for the actors with all of a list of actions of an actor
string Function GetRandomSceneWithAllActionsForActor(Actor[] Actors, int Position, string[] Types) Global Native
; L858 - same as GetRandomSceneWithAllActionsForActor, except types are passed as csv-string
string Function GetRandomSceneWithAllActionsForActorCSV(Actor[] Actors, int Position, string Types) Global Native
; L871 - returns a random furniture scene applicable for the actors with an action of an actor
string Function GetRandomFurnitureSceneWithActionForActor(Actor[] Actors, string FurnitureType, int Position, string Type) Global Native
; L883 - returns a random furniture scene applicable for the actors with any of a list of actions of an actor
string Function GetRandomFurnitureSceneWithAnyActionForActor(Actor[] Actors, string FurnitureType, int Position, string[] Types) Global Native
; L895 - same as GetRandomFurnitureSceneWithAnyActionForActor, except types are passed as csv-string
string Function GetRandomFurnitureSceneWithAnyActionForActorCSV(Actor[] Actors, string FurnitureType, int Position, string Types) Global Native
; L907 - returns a random furniture scene applicable for the actors with all of a list of actions of an actor
string Function GetRandomFurnitureSceneWithAllActionsForActor(Actor[] Actors, string FurnitureType, int Position, string[] Types) Global Native
; L919 - same as GetRandomFurnitureSceneWithAllActionsForActor, except types are passed as csv-string
string Function GetRandomFurnitureSceneWithAllActionsForActorCSV(Actor[] Actors, string FurnitureType, int Position, string Types) Global Native
; L938 - returns a random scene applicable for the actors with an action of a target
string Function GetRandomSceneWithActionForTarget(Actor[] Actors, int Position, string Type) Global Native
; L949 - returns a random scene applicable for the actors with any of a list of actions of a target
string Function GetRandomSceneWithAnyActionForTarget(Actor[] Actors, int Position, string[] Types) Global Native
; L960 - same as GetRandomSceneWithAnyActionForTarget, except types are passed as csv-string
string Function GetRandomSceneWithAnyActionForTargetCSV(Actor[] Actors, int Position, string Types) Global Native
; L971 - returns a random scene applicable for the actors with all of a list of actions of a target
string Function GetRandomSceneWithAllActionsForTarget(Actor[] Actors, int Position, string[] Types) Global Native
; L982 - same as GetRandomSceneWithAllActionsForTarget, except types are passed as csv-string
string Function GetRandomSceneWithAllActionsForTargetCSV(Actor[] Actors, int Position, string Types) Global Native
; L995 - returns a random furniture scene applicable for the actors with an action of a target
string Function GetRandomFurnitureSceneWithActionForTarget(Actor[] Actors, string FurnitureType, int Position, string Type) Global Native
; L1007 - returns a random furniture scene applicable for the actors with any of a list of actions of a target
string Function GetRandomFurnitureSceneWithAnyActionForTarget(Actor[] Actors, string FurnitureType, int Position, string[] Types) Global Native
; L1019 - same as GetRandomFurnitureSceneWithAnyActionForTarget, except types are passed as csv-string
string Function GetRandomFurnitureSceneWithAnyActionForTargetCSV(Actor[] Actors, string FurnitureType, int Position, string Types) Global Native
; L1031 - returns a random furniture scene applicable for the actors with all of a list of actions of a target
string Function GetRandomFurnitureSceneWithAllActionsForTarget(Actor[] Actors, string FurnitureType, int Position, string[] Types) Global Native
; L1043 - same as GetRandomFurnitureSceneWithAllActionsForTarget, except types are passed as csv-string
string Function GetRandomFurnitureSceneWithAllActionsForTargetCSV(Actor[] Actors, string FurnitureType, int Position, string Types) Global Native
; L1063 - returns a random scene applicable for the actors with an action of an actor and a target
string Function GetRandomSceneWithActionForActorAndTarget(Actor[] Actors, int ActorPosition, int TargetPosition, string Type) Global Native
; L1075 - returns a random scene applicable for the actors with any of a list of actions of an actor and a target
string Function GetRandomSceneWithAnyActionForActorAndTarget(Actor[] Actors, int ActorPosition, int TargetPosition, string[] Types) Global Native
; L1087 - same as GetRandomSceneWithAnyActionForActorAndTarget, except types are passed as csv-string
string Function GetRandomSceneWithAnyActionForActorAndTargetCSV(Actor[] Actors, int ActorPosition, int TargetPosition, string Types) Global Native
; L1099 - returns a random scene applicable for the actors with all of a list of actions of an actor and a target
string Function GetRandomSceneWithAllActionsForActorAndTarget(Actor[] Actors, int ActorPosition, int TargetPosition, string[] Types) Global Native
; L1111 - same as GetRandomSceneWithAllActionsForActorAndTarget, except types are passed as csv-string
string Function GetRandomSceneWithAllActionsForActorAndTargetCSV(Actor[] Actors, int ActorPosition, int TargetPosition, string Types) Global Native
; L1125 - returns a random furniture scene applicable for the actors with an action of an actor and a target
string Function GetRandomFurnitureSceneWithActionForActorAndTarget(Actor[] Actors, string FurnitureType, int ActorPosition, int TargetPosition, string Type) Global Native
; L1138 - returns a random furniture scene applicable for the actors with any of a list of actions of an actor and a target
string Function GetRandomFurnitureSceneWithAnyActionForActorAndTarget(Actor[] Actors, string FurnitureType, int ActorPosition, int TargetPosition, string[] Types) Global Native
; L1151 - same as GetRandomFurnitureSceneWithAnyActionForActorAndTarget, except types are passed as csv-string
string Function GetRandomFurnitureSceneWithAnyActionForActorAndTargetCSV(Actor[] Actors, string FurnitureType, int ActorPosition, int TargetPosition, string Types) Global Native
; L1164 - returns a random furniture scene applicable for the actors with all of a list of actions of an actor and a target
string Function GetRandomFurnitureSceneWithAllActionsForActorAndTarget(Actor[] Actors, string FurnitureType, int ActorPosition, int TargetPosition, string[] Types) Global Native
; L1177 - same as GetRandomFurnitureSceneWithAllActionsForActorAndTarget, except types are passed as csv-string
string Function GetRandomFurnitureSceneWithAllActionsForActorAndTargetCSV(Actor[] Actors, string FurnitureType, int ActorPosition, int TargetPosition, string Types) Global Native
; L1243 - returns a random scene matching the given conditions
string Function GetRandomSceneSuperloadCSV(Actor[] Actors, string FurnitureType = "", string AnySceneTag = "", string AllSceneTags = "", string SceneTagWhitelist = "", string SceneTagBlacklist = "", string AnyActorTagForAny = "", string AnyActorTagForAll = "", string AllActorTagsForAny = "", string AllActorTagsForAll = "", string ActorTagWhitelistForAny = "", string ActorTagWhitelistForAll = "", string ActorTagBlacklistForAny = "", string ActorTagBlacklistForAll = "", string AnyActionType = "", string AnyActionActor = "", string AnyActionTarget = "", string AnyActionPerformer = "", string AnyActionMatesAny = "", string AnyActionMatesAll = "", string AnyActionParticipantAny = "", string AnyActionParticipantAll = "", string AllActionTypes = "", string AllActionActors = "", string AllActionTargets = "", string AllActionPerformers = "", string AllActionMatesAny = "", string AllActionMatesAll = "", string AllActionParticipantsAny = "", string AllActionParticipantsAll = "", string ActionWhitelistTypes = "", string ActionWhitelistActors = "", string ActionWhitelistTargets = "", string ActionWhitelistPerformers = "", string ActionWhitelistMatesAny = "", string ActionWhitelistMatesAll = "", string ActionWhitelistParticipantsAny = "", string ActionWhitelistParticipantsAll = "", string ActionBlacklistTypes = "", string ActionBlacklistActors = "", string ActionBlacklistTargets = "", string ActionBlacklistPerformers = "", string ActionBlacklistMatesAny = "", string ActionBlacklistMatesAll= "", string ActionBlacklistParticipantsAny = "", string ActionBlacklistParticipantsAll = "") Global Native
```

### OMetadata  (`...\OStim Standalone - Advanced Adult Animation Framework\Scripts\Source\OMetadata.psc`, 3334 lines; native=280, global-Papyrus=0, instance/other=2)

```papyrus
; L38 - gets the name of the scene
string Function GetName(string Id) Global Native
; L49 - turns a list of scene ids into a list of their display names
string[] Function ScenesToNames(string[] Ids) Global Native
; L58 - checks if the scene is a transition
bool Function IsTransition(string Id) Global Native
; L68 - returns the index of the default speed of the scene
int Function GetDefaultSpeed(string Id) Global Native
; L77 - returns the index of the fastest speed of the scene
int Function GetMaxSpeed(string Id) Global Native
; L86 - returns the actor count of the scene
int Function GetActorCount(string Id) Global Native
; L97 - returns the id of the animation of a speed
string Function GetAnimationId(string Id, int Index) Global Native
; L110 - checks if the scene actor requires the requirement
bool Function HasRequirement(string Id, int Position, string Requirement) Global Native
; L123 - checks if the scene actor requires at least one of the requirements
bool Function HasAnyRequirement(string Id, int Position, string[] Requirements) Global Native
; L136 - same as HasAnyRequirement, except requirements are passed as a csv-string
bool Function HasAnyRequirementCSV(string Id, int Position, string Requirements) Global Native
; L149 - checks if the scene actor requires all of the requirements
bool Function HasAllRequirements(string Id, int Position, string[] Requirements) Global Native
; L162 - same as HasAllRequirements, except requirements are passed as a csv-string
bool Function HasAllRequirementsCSV(string Id, int Position, string Requirements) Global Native
; L181 - returns the auto transition of the respective type for the scene actor
string Function GetAutoTransitionForActor(string Id, int Position, string Type) Global Native
; L198 - returns all tags for a scene
string[] Function GetSceneTags(string Id) Global Native
; L208 - checks if a scene has a tag
bool Function HasSceneTag(string Id, string Tag) Global Native
; L218 - checks if a scene has at least one of a list of tags
bool Function HasAnySceneTag(string Id, string[] Tags) Global Native
; L228 - same as HasAnySceneTag, except tags are passed as a csv-string
bool Function HasAnySceneTagCSV(string Id, string Tags) Global Native
; L238 - checks if a scene has all of a list of tags
bool Function HasAllSceneTags(string Id, string[] Tags) Global Native
; L248 - same as HasAllSceneTags, except tags are passed as a csv-string
bool Function HasAllSceneTagsCSV(string Id, string Tags) Global Native
; L258 - returns all scene tags that overlap with the list
string[] Function GetSceneTagOverlap(string Id, string[] Tags) Global Native
; L268 - same as GetSceneTagOverlap, except tags are passed as a csv-string
string[] Function GetSceneTagOverlapCSV(string Id, string Tags) Global Native
; L279 - returns all tags for a scene actor
string[] Function GetActorTags(string Id, int Position) Global Native
; L290 - checks if a scene actor has a tag
bool Function HasActorTag(string Id, int Position, string Tag) Global Native
; L301 - checks if an scene actor has at least one of a list of tags
bool Function HasAnyActorTag(string Id, int Position, string[] Tags) Global Native
; L312 - same as HasAnyActorTags, except tags are passed as a csv-string
bool Function HasAnyActorTagCSV(string Id, int Position, string Tags) Global Native
; L323 - checks if a scene actor has all of a list of tags
bool Function HasAllActorTags(string Id, int Position, string[] Tags) Global Native
; L334 - same as HasAllActorTags, except tags are passed as a csv-string
bool Function HasAllActorTagsCSV(string Id, int Position, string Tags) Global Native
; L345 - returns all scene actor tags that overlap with the list
string[] Function GetActorTagOverlap(string Id, int Position, string[] Tags) Global Native
; L356 - same as GetActorTagOverlap, except tags are passed as a csv-string
string[] Function GetActorTagOverlapCSV(string Id, int Position, string Tags) Global Native
; L373 - checks if the scene has at least one action
bool Function HasActions(string Id) Global Native
; L383 - returns the first occurance of an action in a scene
int Function FindAction(string Id, string Type) Global Native
; L393 - returns the first occurance of any of a list of actions in a scene
int Function FindAnyAction(string Id, string[] Types) Global Native
; L403 - same as FindAnyAction, excepts types are passed as a csv-string
int Function FindAnyActionCSV(string Id, string Types) Global Native
; L413 - returns all occurances of an action in a scene
int[] Function FindActions(string Id, string Type) Global Native
; L423 - returns all occurances of any of a list of actions in a scene
int[] Function FindAllActions(string Id, string[] Types) Global Native
; L433 - same as FindAllActions, except types are passed as a csv-string
int[] Function FindAllActionsCSV(string Id, string Types) Global Native
; L452 - returns the first occurance of an action from an action actor
int Function FindActionForActor(string Id, int Position, string Type) Global Native
; L463 - returns the first occurance of any of a list of actions from an action actor
int Function FindAnyActionForActor(string Id, int Position, string[] Types) Global Native
; L474 - same es FindAnyActionForActor, except types are passed as a csv-string
int Function FindAnyActionForActorCSV(string Id, int Position, string Types) Global Native
; L485 - returns all occurances of an action from an action actor
int[] Function FindActionsForActor(string Id, int Position, string Type) Global Native
; L496 - returns all occurances of any of a list of actions from an action actor
int[] Function FindAllActionsForActor(string Id, int Position, string[] Types) Global Native
; L507 - same es FindAllActionsForActor, except types are passed as a csv-string
int[] Function FindAllActionsForActorCSV(string Id, int Position, string Types) Global Native
; L519 - returns the first occurance of an action from a list of action actors
int Function FindActionForActors(string Id, int[] Positions, string Type) Global Native
; L530 - same es FindActionForActors, except positions are passed as a csv-string
int Function FindActionForActorsCSV(string Id, string Positions, string Type) Global Native
; L541 - returns the first occurance of any of a list of actions from a list of action actors
int Function FindAnyActionForActors(string Id, int[] Positions, string[] Types) Global Native
; L552 - same es FindAnyActionForActors, except positions and types are passed as a csv-string
int Function FindAnyActionForActorsCSV(string Id, string Positions, string Types) Global Native
; L563 - returns all occurances of an action from a list of action actors
int[] Function FindActionsForActors(string Id, int[] Positions, string Type) Global Native
; L574 - same es FindActionsForActors, except positions are passed as a csv-string
int[] Function FindActionsForActorsCSV(string Id, string Positions, string Type) Global Native
; L585 - returns all occurances of any of a list of actions from a list of action actors
int[] Function FindAllActionsForActors(string Id, int[] Positions, string[] Types) Global Native
; L596 - same es FindAllActionsForActors, except positions and types are passed as a csv-string
int[] Function FindAllActionsForActorsCSV(string Id, string Positions, string Types) Global Native
; L615 - returns the first occurance of an action from an action target
int Function FindActionForTarget(string Id, int Position, string Type) Global Native
; L626 - returns the first occurance of any of a list of actions from an action target
int Function FindAnyActionForTarget(string Id, int Position, string[] Types) Global Native
; L637 - same as FindAnyActionForTarget, except types are passed as a csv-string
int Function FindAnyActionForTargetCSV(string Id, int Position, string Types) Global Native
; L648 - returns all occurances of an action from an action target
int[] Function FindActionsForTarget(string Id, int Position, string Type) Global Native
; L659 - returns all occurances of any of a list of actions from an action target
int[] Function FindAllActionsForTarget(string Id, int Position, string[] Types) Global Native
; L670 - same as FindAllActionsForTarget, except types are passed as a csv-string
int[] Function FindAllActionsForTargetCSV(string Id, int Position, string Types) Global Native
; L682 - returns the first occurance of an action from a list of action targets
int Function FindActionForTargets(string Id, int[] Positions, string Type) Global Native
; L693 - same as FindActionForTargets, except positions are passed as a csv-string
int Function FindActionForTargetsCSV(string Id, string Positions, string Type) Global Native
; L704 - returns the first occurance of any of a list of actions from a list of action targets
int Function FindAnyActionForTargets(string Id, int[] Positions, string[] Types) Global Native
; L715 - same as FindAnyActionForTargets, except positions and types are passed as a csv-string
int Function FindAnyActionForTargetsCSV(string Id, string Positions, string Types) Global Native
; L726 - returns all occurances of an action from a list of action targets
int[] Function FindActionsForTargets(string Id, int[] Positions, string Type) Global Native
; L737 - same as FindActionsForTargets, exvcept positions are passed as a csv-string
int[] Function FindActionsForTargetsCSV(string Id, string Positions, string Type) Global Native
; L748 - returns all occurances of any of a list of actions from a list of action targets
int[] Function FindAllActionsForTargets(string Id, int[] Positions, string[] Types) Global Native
; L759 - same as FindAllActionsForTargets, except positions and types are passed as a csv-string
int[] Function FindAllActionsForTargetsCSV(string Id, string Positions, string Types) Global Native
; L778 - returns the first occurance of an action from an action performer
int Function FindActionForPerformer(string Id, int Position, string Type) Global Native
; L789 - returns the first occurance of any of a list of actions from an action performer
int Function FindAnyActionForPerformer(string Id, int Position, string[] Types) Global Native
; L800 - same as FindAnyActionForPerformer, except types are passed as a csv-string
int Function FindAnyActionForPerformerCSV(string Id, int Position, string Types) Global Native
; L811 - returns all occurances of an action from an action performer
int[] Function FindActionsForPerformer(string Id, int Position, string Type) Global Native
; L822 - returns all occurances of any of a list of actions from an action performer
int[] Function FindAllActionsForPerformer(string Id, int Position, string[] Types) Global Native
; L833 - same as FindAllActionsForPerformer, except types are passed as a csv-string
int[] Function FindAllActionsForPerformerCSV(string Id, int Position, string Types) Global Native
; L845 - returns the first occurance of an action from a list of action performers
int Function FindActionForPerformers(string Id, int[] Positions, string Type) Global Native
; L856 - same as FindActionForPerformers, except positions are passed as a csv-string
int Function FindActionForPerformersCSV(string Id, string Positions, string Type) Global Native
; L867 - returns the first occurance of any of a list of actions from a list of action performers
int Function FindAnyActionForPerformers(string Id, int[] Positions, string[] Types) Global Native
; L878 - same as FindAnyActionForPerformers, except positions and types are passed as a csv-string
int Function FindAnyActionForPerformersCSV(string Id, string Positions, string Types) Global Native
; L889 - returns all occurances of an action from a list of action performers
int[] Function FindActionsForPerformers(string Id, int[] Positions, string Type) Global Native
; L900 - same as FindActionsForPerformers, except positions are passed as a csv-string
int[] Function FindActionsForPerformersCSV(string Id, string Positions, string Type) Global Native
; L911 - returns all occurances of any of a list of actions from a list of action performers
int[] Function FindAllActionsForPerformers(string Id, int[] Positions, string[] Types) Global Native
; L922 - same as FindAllActionsForPerformers, except positions and types are passed as a csv-string
int[] Function FindAllActionsForPerformersCSV(string Id, string Positions, string Types) Global Native
; L942 - returns the first occurance of an action from an action actor and target
int Function FindActionForActorAndTarget(string Id, int ActorPosition, int TargetPosition, string Type) Global Native
; L954 - returns the first occurance of any of a list of actions from an action actor and target
int Function FindAnyActionForActorAndTarget(string Id, int ActorPosition, int TargetPosition, string[] Types) Global Native
; L966 - same es FindAnyActionForActorAndTarget, except types are passed as a csv-string
int Function FindAnyActionForActorAndTargetCSV(string Id, int ActorPosition, int TargetPosition, string Types) Global Native
; L978 - returns all occurances of an action from an action actor and target
int[] Function FindActionsForActorAndTarget(string Id, int ActorPosition, int TargetPosition, string Type) Global Native
; L990 - returns all occurances of any of a list of actions from an action actor and target
int[] Function FindAllActionsForActorAndTarget(string Id, int ActorPosition, int TargetPosition, string[] Types) Global Native
; L1002 - same es FindAllActionsForActorAndTarget, except types are passed as a csv-string
int[] Function FindAllActionsForActorAndTargetCSV(string Id, int ActorPosition, int TargetPosition, string Types) Global Native
; L1015 - returns the first occurance of an action from a list of action actors and a list of action targets
int Function FindActionForActorsAndTargets(string Id, int[] ActorPositions, int[] TargetPositions, string Type) Global Native
; L1027 - same es FindActionForActorsAndTargets, except positions are passed as a csv-string
int Function FindActionForActorsAndTargetsCSV(string Id, string ActorPositions, string TargetPositions, string Type) Global Native
; L1039 - returns the first occurance of any of a list of actions from a list of action actors and a list of action targets
int Function FindAnyActionForActorsAndTargets(string Id, int[] ActorPositions, int[] TargetPositions, string[] Types) Global Native
; L1051 - same es FindAnyActionForActorsAndTargets, except positions and types are passed as a csv-string
int Function FindAnyActionForActorsAndTargetsCSV(string Id, string ActorPositions, string TargetPositions, string Types) Global Native
; L1063 - returns all occurances of an action from a list of action actors and a list of action targets
int[] Function FindActionsForActorsAndTargets(string Id, int[] ActorPositions, int[] TargetPositions, string Type) Global Native
; L1075 - same es FindActionsForActorsAndTargets, except positions are passed as a csv-string
int[] Function FindActionsForActorsAndTargetsCSV(string Id, string ActorPositions, string TargetPositions, string Type) Global Native
; L1087 - returns all occurances of any of a list of actions from a list of action actors and a list of action targets
int[] Function FindAllActionsForActorsAndTargets(string Id, int[] ActorPositions, int[] TargetPositions, string[] Types) Global Native
; L1099 - same es FindAllActionsForActorsAndTargets, except positions and types are passed as a csv-string
int[] Function FindAllActionsForActorsAndTargetsCSV(string Id, string ActorPositions, string TargetPositions, string Types) Global Native
; L1120 - returns the first occurance of an action from an action mate
int Function FindActionForMate(string Id, int Position, string Type) Global Native
; L1131 - returns the first occurance of any of a list of actions from an action mate
int Function FindAnyActionForMate(string Id, int Position, string[] Types) Global Native
; L1142 - same as FindAnyActionForMate, except types are passed as a csv-string
int Function FindAnyActionForMateCSV(string Id, int Position, string Types) Global Native
; L1153 - returns all occurances of an action from an action mate
int[] Function FindActionsForMate(string Id, int Position, string Type) Global Native
; L1164 - returns all occurances of any of a list of actions from an action mate
int[] Function FindAllActionsForMate(string Id, int Position, string[] Types) Global Native
; L1175 - same as FindAllActionsForMate, except types are passed as a csv-string
int[] Function FindAllActionsForMateCSV(string Id, int Position, string Types) Global Native
; L1187 - returns the first occurance of an action with at least one action mate in a given list
int Function FindActionForMatesAny(string Id, int[] Positions, string Type) Global Native
; L1198 - same as FindActionForMatesAny, except positions are passed as a csv-string
int Function FindActionForMatesAnyCSV(string Id, string Positions, string Type) Global Native
; L1209 - returns the first occurance of any of a list of actions with at least one action mate in a given list
int Function FindAnyActionForMatesAny(string Id, int[] Positions, string[] Types) Global Native
; L1220 - same as FindAnyActionForMatesAny, except positions and types are passed as a csv-string
int Function FindAnyActionForMatesAnyCSV(string Id, string Positions, string Types) Global Native
; L1231 - returns all occurances of an action with at least one action mate in a given list
int[] Function FindActionsForMatesAny(string Id, int[] Positions, string Type) Global Native
; L1242 - same as FindActionsForMatesAny, except positions are passed as a csv-string
int[] Function FindActionsForMatesAnyCSV(string Id, string Positions, string Type) Global Native
; L1253 - returns all occurances of any of a list of actions with at least one action mate in a given list
int[] Function FindAllActionsForMatesAny(string Id, int[] Positions, string[] Types) Global Native
; L1264 - same as FindAllActionsForMatesAny, except positions and types are passed as a csv-string
int[] Function FindAllActionsForMatesAnyCSV(string Id, string Positions, string Types) Global Native
; L1275 - returns the first occurance of an action with all action mates in a given list
int Function FindActionForMatesAll(string Id, int[] Positions, string Type) Global Native
; L1286 - same as FindActionForMatesAll, except positions are passed as a csv-string
int Function FindActionForMatesAllCSV(string Id, string Positions, string Type) Global Native
; L1297 - returns the first occurance of any of a list of actions with all action mates in a given list
int Function FindAnyActionForMatesAll(string Id, int[] Positions, string[] Types) Global Native
; L1308 - same as FindAnyActionForMatesAll, except positions and types are passed as a csv-string
int Function FindAnyActionForMatesAllCSV(string Id, string Positions, string Types) Global Native
; L1319 - returns all occurances of an action with all action mates in a given list
int[] Function FindActionsForMatesAll(string Id, int[] Positions, string Type) Global Native
; L1330 - same as FindActionsForMatesAll, except positions are passed as a csv-string
int[] Function FindActionsForMatesAllCSV(string Id, string Positions, string Type) Global Native
; L1341 - returns all occurances of any of a list of actions with all action mates in a given list
int[] Function FindAllActionsForMatesAll(string Id, int[] Positions, string[] Types) Global Native
; L1352 - same as FindAllActionsForMatesAll, except positions and types are passed as a csv-string
int[] Function FindAllActionsForMatesAllCSV(string Id, string Positions, string Types) Global Native
; L1373 - returns the first occurance of an action from an action participant
int Function FindActionForParticipant(string Id, int Position, string Type) Global Native
; L1384 - returns the first occurance of any of a list of actions from an action participant
int Function FindAnyActionForParticipant(string Id, int Position, string[] Types) Global Native
; L1395 - same as FindAnyActionForParticipant, except types are passed as a csv-string
int Function FindAnyActionForParticipantCSV(string Id, int Position, string Types) Global Native
; L1406 - returns all occurances of an action from an action participant
int[] Function FindActionsForParticipant(string Id, int Position, string Type) Global Native
; L1417 - returns all occurances of any of a list of actions from an action participant
int[] Function FindAllActionsForParticipant(string Id, int Position, string[] Types) Global Native
; L1428 - same as FindAllActionsForParticipant, except types are passed as a csv-string
int[] Function FindAllActionsForParticipantCSV(string Id, int Position, string Types) Global Native
; L1440 - returns the first occurance of an action with at least one action participant in a given list
int Function FindActionForParticipantsAny(string Id, int[] Positions, string Type) Global Native
; L1451 - same as FindActionForParticipantsAny, except positions are passed as a csv-string
int Function FindActionForParticipantsAnyCSV(string Id, string Positions, string Type) Global Native
; L1462 - returns the first occurance of any of a list of actions with at least one action participant in a given list
int Function FindAnyActionForParticipantsAny(string Id, int[] Positions, string[] Types) Global Native
; L1473 - same as FindAnyActionForParticipantsAny, except positions and types are passed as a csv-string
int Function FindAnyActionForParticipantsAnyCSV(string Id, string Positions, string Types) Global Native
; L1484 - returns all occurances of an action with at least one action participant in a given list
int[] Function FindActionsForParticipantsAny(string Id, int[] Positions, string Type) Global Native
; L1495 - same as FindActionsForParticipantsAny, except positions are passed as a csv-string
int[] Function FindActionsForParticipantsAnyCSV(string Id, string Positions, string Type) Global Native
; L1506 - returns all occurances of any of a list of actions with at least one action participant in a given list
int[] Function FindAllActionsForParticipantsAny(string Id, int[] Positions, string[] Types) Global Native
; L1517 - same as FindAllActionsForParticipantsAny, except positions and types are passed as a csv-string
int[] Function FindAllActionsForParticipantsAnyCSV(string Id, string Positions, string Types) Global Native
; L1528 - returns the first occurance of an action with all action participants in a given list
int Function FindActionForParticipantsAll(string Id, int[] Positions, string Type) Global Native
; L1539 - same as FindActionForParticipantsAll, except positions are passed as a csv-string
int Function FindActionForParticipantsAllCSV(string Id, string Positions, string Type) Global Native
; L1550 - returns the first occurance of any of a list of actions with all action participants in a given list
int Function FindAnyActionForParticipantsAll(string Id, int[] Positions, string[] Types) Global Native
; L1561 - same as FindAnyActionForParticipantsAll, except positions and types are passed as a csv-string
int Function FindAnyActionForParticipantsAllCSV(string Id, string Positions, string Types) Global Native
; L1572 - returns all occurances of an action with all action participants in a given list
int[] Function FindActionsForParticipantsAll(string Id, int[] Positions, string Type) Global Native
; L1583 - same as FindActionsForParticipantsAll, except positions are passed as a csv-string
int[] Function FindActionsForParticipantsAllCSV(string Id, string Positions, string Type) Global Native
; L1594 - returns all occurances of any of a list of actions with all action participants in a given list
int[] Function FindAllActionsForParticipantsAll(string Id, int[] Positions, string[] Types) Global Native
; L1605 - same as FindAllActionsForParticipantsAll, except positions and types are passed as a csv-string
int[] Function FindAllActionsForParticipantsAllCSV(string Id, string Positions, string Types) Global Native
; L1647 - returns the first occurance of any of a list of actions matching the given conditions
int Function FindActionSuperloadCSVv2(string Id, string ActorPositions = "", string TargetPositions = "", string PerformerPositions = "", string MatePositionsAny = "", string MatePositionsAll = "", string ParticipantPositionsAny = "", string ParticipantPositionsAll = "", string Types = "", string AnyActionTag = "", string AllActionTags = "", string ActionTagWhitelist = "", string ActionTagBlacklist = "", string AnyCustomIntRecord = "", string AllCustomIntRecords = "", string AnyCustomFloatRecord = "", string allCustomFloatRecords = "", string anyCustomStringRecord = "", string allCustomStringRecords = "", string AnyCustomIntListRecord = "", string AllCustomIntListRecords = "", string AnyCustomFloatListRecord = "", string AllCustomFloatListRecords = "", string AnyCustomStringListRecord = "", string AllCustomStringListRecords = "") Global Native
; L1681 - returns all occurances of any of a list of actions matching the given conditions
int[] Function FindActionsSuperloadCSVv2(string Id, string ActorPositions = "", string TargetPositions = "", string PerformerPositions = "", string MatePositionsAny = "", string MatePositionsAll = "", string ParticipantPositionsAny = "", string ParticipantPositionsAll = "", string Types = "", string AnyActionTag = "", string AllActionTags = "", string ActionTagWhitelist = "", string ActionTagBlacklist = "", string AnyCustomIntRecord = "", string AllCustomIntRecords = "", string AnyCustomFloatRecord = "", string allCustomFloatRecords = "", string anyCustomStringRecord = "", string allCustomStringRecords = "", string AnyCustomIntListRecord = "", string AllCustomIntListRecords = "", string AnyCustomFloatListRecord = "", string AllCustomFloatListRecords = "", string AnyCustomStringListRecord = "", string AllCustomStringListRecords = "") Global Native
; L1698 - return all action types for a scene
string[] Function GetActionTypes(string Id) Global Native
; L1709 - returns the action type for an action in a scene
string Function GetActionType(string Id, int Index) Global Native
; L1718 - returns all actions actors in a scene
int[] Function GetActionActors(string Id) Global Native
; L1729 - returns the actor of an action in a scene
int Function GetActionActor(string Id, int Index) Global Native
; L1738 - returns all actions targets
int[] Function GetActionTargets(string Id) Global Native
; L1749 - returns the target of an action in a scene
int Function GetActionTarget(string Id, int Index) Global Native
; L1758 - returns all actions performers
int[] Function GetActionPerformers(string Id) Global Native
; L1769 - returns the performer of an action in a scene
int Function GetActionPerformer(string Id, int Index) Global Native
; L1789 - returns all tags for an action in a scene
string[] Function GetActionTags(string Id, int Index) Global Native
; L1799 - returns all tags for all actions in the scene
string[] Function GetAllActionsTags(string Id) Global Native
; L1810 - checks if an action has a tag
bool Function HasActionTag(string Id, int Index, string Tag) Global Native
; L1820 - checks if any action in the scene has a tag
bool Function HasActionTagOnAny(string Id, string Tag) Global Native
; L1831 - checks if an action has at least one of a list of tags
bool Function HasAnyActionTag(string Id, int Index, string[] Tags) Global Native
; L1842 - same as HasAnyActionTag, except tags are passed as a csv-string
bool Function HasAnyActionTagCSV(string Id, int Index, string Tags) Global Native
; L1852 - checks if any action in the scene has at least one of a list of tags
bool Function HasAnyActionTagOnAny(string Id, string[] Tags) Global Native
; L1862 - same as HasAnyActionTagOnAny, except tags are passed as a csv-string
bool Function HasAnyActionTagOnAnyCSV(string Id, string Tags) Global Native
; L1873 - checks if an action has all of a list of tags
bool Function HasAllActionTags(string Id, int Index, string[] Tags) Global Native
; L1884 - same as HasAllActionTags, except tags are passed as a csv-string
bool Function HasAllActionTagsCSV(string Id, int Index, string Tags) Global Native
; L1894 - checks if any action in the scene has all of a list of tags
bool Function HasAllActionTagsOnAny(string Id, string[] Tags) Global Native
; L1904 - same as HasAllActionTagsOnAny, except tags are passed as a csv-string
bool Function HasAllActionTagsOnAnyCSV(string Id, string Tags) Global Native
; L1914 - checks if all actions in the scene together have all of a list of tags
bool Function HasAllActionTagsOverAll(string Id, string[] Tags) Global Native
; L1924 - same as HasAllActionTagsOverAll, except tags are passed as a csv-string
bool Function HasAllActionTagsOverAllCSV(string Id, string Tags) Global Native
; L1935 - returns all action tags that overlap with the list
string[] Function GetActionTagOverlap(string Id, int Index, string[] Tags) Global Native
; L1946 - same as GetActionTagOverlap, except tags are passed as a csv-string
string[] Function GetActionTagOverlapCSV(string Id, int Index, string Tags) Global Native
; L1957 - returns all actions tags of all actions that overlap with with the list
string[] Function GetActionTagOverlapOverAll(string Id, string[] Tags) Global Native
; L1967 - same as GetActioNTagOverlap, except tags are passed as a csv-string
string[] Function GetActionTagOverlapOverAllCSV(string Id, string Tags) Global Native
; L1986 - checks if the action has a custom int record defined for the action actor
bool Function HasCustomActionActorInt(string Id, int Index, string Record) Global Native
; L1998 - returns the custom int record defined for the action actor
int Function GetCustomActionActorInt(string Id, int Index, string Record, int Fallback = 0) Global Native
; L2010 - checks if the custom int record defined for the action actor is a specific value
bool Function IsCustomActionActorInt(string Id, int Index, string Record, int Value) Global Native
; L2021 - checks if the action has a custom float record defined for the action actor
bool Function HasCustomActionActorFloat(string Id, int Index, string Record) Global Native
; L2033 - returns the custom float record defined for the action actor
float Function GetCustomActionActorFloat(string Id, int Index, string Record, float Fallback = 0.0) Global Native
; L2045 - checks if the custom float record defined for the action actor is a specific value
bool Function IsCustomActionActorFloat(string Id, int Index, string Record, float Value) Global Native
; L2056 - checks if the action has a custom string record defined for the action actor
bool Function HasCustomActionActorString(string Id, int Index, string Record) Global Native
; L2068 - returns the custom string record defined for the action actor
string Function GetCustomActionActorString(string Id, int Index, string Record, string Fallback = "") Global Native
; L2080 - checks if the custom string record defined for the action actor is a specific value
bool Function IsCustomActionActorString(string Id, int Index, string Record, string Value) Global Native
; L2091 - checks if the action has a custom int list record defined for the action actor
bool Function HasCustomActionActorIntList(string Id, int Index, string Record) Global Native
; L2102 - returns the custom int list record defined for the action actor
int[] Function GetCustomActionActorIntList(string Id, int Index, string Record) Global Native
; L2113 - checks if the custom int list record defined for the action actor contains a value
bool Function CustomActionActorIntListContains(string Id, int Index, string Record, int Value) Global Native
; L2124 - checks if the custom int list record defined for the action actor contains any of a list of values
bool Function CustomActionActorIntListContainsAny(string Id, int Index, string Record, int[] Values) Global Native
; L2135 - same as CustomActionActorIntListContainsAny, except values are passed as a csv-string
bool Function CustomActionActorIntListContainsAnyCSV(string Id, int Index, string Record, string Values) Global Native
; L2146 - checks if the custom int list record defined for the action actor contains all of a list of values
bool Function CustomActionActorIntListContainsAll(string Id, int Index, string Record, int[] Values) Global Native
; L2157 - same as CustomActionActorIntListContainsAll, except values are passed as a csv-string
bool Function CustomActionActorIntListContainsAllCSV(string Id, int Index, string Record, string Values) Global Native
; L2168 - returns all entries of a custom int list defined for the action actor that overlap with the list
int[] Function GetCustomActionActorIntListOverlap(string Id, int Index, string Record, int[] Values) Global Native
; L2179 - same as GetCustomActionActorIntListOverlap, except values are passed as a csv-string
int[] Function GetCustomActionActorIntListOverlapCSV(string Id, int Index, string Record, string Values) Global Native
; L2190 - checks if the action has a custom float list record defined for the action actor
bool Function HasCustomActionActorFloatList(string Id, int Index, string Record) Global Native
; L2201 - returns the custom float list record defined for the action actor
float[] Function GetCustomActionActorFloatList(string Id, int Index, string Record) Global Native
; L2212 - checks if the custom float list record defined for the action actor contains a value
bool Function CustomActionActorFloatListContains(string Id, int Index, string Record, float Value) Global Native
; L2223 - checks if the custom float list record defined for the action actor contains any of a list of values
bool Function CustomActionActorFloatListContainsAny(string Id, int Index, string Record, float[] Values) Global Native
; L2234 - same as CustomActionActorFloatListContainsAny, except values are passed as a csv-string
bool Function CustomActionActorFloatListContainsAnyCSV(string Id, int Index, string Record, string Values) Global Native
; L2245 - checks if the custom float list record defined for the action actor contains all of a list of values
bool Function CustomActionActorFloatListContainsAll(string Id, int Index, string Record, float[] Values) Global Native
; L2256 - same as CustomActionActorFloatListContainsAll, except values are passed as a csv-string
bool Function CustomActionActorFloatListContainsAllCSV(string Id, int Index, string Record, string Values) Global Native
; L2267 - returns all entries of a custom float list defined for the action actor that overlap with the list
float[] Function GetCustomActionActorFloatListOverlap(string Id, int Index, string Record, float[] Values) Global Native
; L2278 - same as GetCustomActionActorFloatListOverlap, except values are passed as a csv-string
float[] Function GetCustomActionActorFloatListOverlapCSV(string Id, int Index, string Record, string Values) Global Native
; L2289 - checks if the action has a custom string list record defined for the action actor
bool Function HasCustomActionActorStringList(string Id, int Index, string Record) Global Native
; L2300 - returns the custom string list record defined for the action actor
string[] Function GetCustomActionActorStringList(string Id, int Index, string Record) Global Native
; L2311 - checks if the custom string list record defined for the action actor contains a value
bool Function CustomActionActorStringListContains(string Id, int Index, string Record, string Value) Global Native
; L2322 - checks if the custom string list record defined for the action actor contains any of a list of values
bool Function CustomActionActorStringListContainsAny(string Id, int Index, string Record, string[] Values) Global Native
; L2333 - same as CustomActionActorStringListContainsAny, except values are passed as a csv-string
bool Function CustomActionActorStringListContainsAnyCSV(string Id, int Index, string Record, string Values) Global Native
; L2344 - checks if the custom string list record defined for the action actor contains all of a list of values
bool Function CustomActionActorStringListContainsAll(string Id, int Index, string Record, string[] Values) Global Native
; L2355 - same as CustomActionActorStringListContainsAll, except values are passed as a csv-string
bool Function CustomActionActorStringListContainsAllCSV(string Id, int Index, string Record, string Values) Global Native
; L2366 - returns all entries of a custom string list defined for the action actor that overlap with the list
string[] Function GetCustomActionActorStringListOverlap(string Id, int Index, string Record, string[] Values) Global Native
; L2377 - same as GetCustomActionActorStringListOverlap, except values are passed as a csv-string
string[] Function GetCustomActionActorStringListOverlapCSV(string Id, int Index, string Record, string Values) Global Native
; L2397 - checks if the action has a custom int record defined for the action target
bool Function HasCustomActionTargetInt(string Id, int Index, string Record) Global Native
; L2409 - returns the custom int record defined for the action target
int Function GetCustomActionTargetInt(string Id, int Index, string Record, int Fallback = 0) Global Native
; L2421 - checks if the custom int record defined for the action target is a specific value
bool Function IsCustomActionTargetInt(string Id, int Index, string Record, int Value) Global Native
; L2432 - checks if the action has a custom float record defined for the action target
bool Function HasCustomActionTargetFloat(string Id, int Index, string Record) Global Native
; L2444 - returns the custom float record defined for the action target
float Function GetCustomActionTargetFloat(string Id, int Index, string Record, float Fallback = 0.0) Global Native
; L2456 - checks if the custom float record defined for the action target is a specific value
bool Function IsCustomActionTargetFloat(string Id, int Index, string Record, float Value) Global Native
; L2467 - checks if the action has a custom string record defined for the action target
bool Function HasCustomActionTargetString(string Id, int Index, string Record) Global Native
; L2479 - returns the custom string record defined for the action target
string Function GetCustomActionTargetString(string Id, int Index, string Record, string Fallback = "") Global Native
; L2491 - checks if the custom string record defined for the action target is a specific value
bool Function IsCustomActionTargetString(string Id, int Index, string Record, string Value) Global Native
; L2502 - checks if the action has a custom int list record defined for the action target
bool Function HasCustomActionTargetIntList(string Id, int Index, string Record) Global Native
; L2513 - returns the custom int list record defined for the action target
int[] Function GetCustomActionTargetIntList(string Id, int Index, string Record) Global Native
; L2524 - checks if the custom int list record defined for the action target contains a value
bool Function CustomActionTargetIntListContains(string Id, int Index, string Record, int Value) Global Native
; L2535 - checks if the custom int list record defined for the action target contains any of a list of values
bool Function CustomActionTargetIntListContainsAny(string Id, int Index, string Record, int[] Values) Global Native
; L2546 - same as CustomActionTargetIntListContainsAny, except values are passed as a csv-string
bool Function CustomActionTargetIntListContainsAnyCSV(string Id, int Index, string Record, string Values) Global Native
; L2557 - checks if the custom int list record defined for the action target contains all of a list of values
bool Function CustomActionTargetIntListContainsAll(string Id, int Index, string Record, int[] Values) Global Native
; L2568 - same as CustomActionTargetIntListContainsAll, except values are passed as a csv-string
bool Function CustomActionTargetIntListContainsAllCSV(string Id, int Index, string Record, string Values) Global Native
; L2579 - returns all entries of a custom int list defined for the action target that overlap with the list
int[] Function GetCustomActionTargetIntListOverlap(string Id, int Index, string Record, int[] Values) Global Native
; L2590 - same as GetCustomActionTargetIntListOverlap, except values are passed as a csv-string
int[] Function GetCustomActionTargetIntListOverlapCSV(string Id, int Index, string Record, string Values) Global Native
; L2601 - checks if the action has a custom float list record defined for the action target
bool Function HasCustomActionTargetFloatList(string Id, int Index, string Record) Global Native
; L2612 - returns the custom float list record defined for the action target
float[] Function GetCustomActionTargetFloatList(string Id, int Index, string Record) Global Native
; L2623 - checks if the custom float list record defined for the action target contains a value
bool Function CustomActionTargetFloatListContains(string Id, int Index, string Record, float Value) Global Native
; L2634 - checks if the custom float list record defined for the action target contains any of a list of values
bool Function CustomActionTargetFloatListContainsAny(string Id, int Index, string Record, float[] Values) Global Native
; L2645 - same as CustomActionTargetFloatListContainsAny, except values are passed as a csv-string
bool Function CustomActionTargetFloatListContainsAnyCSV(string Id, int Index, string Record, string Values) Global Native
; L2656 - checks if the custom float list record defined for the action target contains all of a list of values
bool Function CustomActionTargetFloatListContainsAll(string Id, int Index, string Record, float[] Values) Global Native
; L2667 - same as CustomActionTargetFloatListContainsAll, except values are passed as a csv-string
bool Function CustomActionTargetFloatListContainsAllCSV(string Id, int Index, string Record, string Values) Global Native
; L2678 - returns all entries of a custom float list defined for the action target that overlap with the list
float[] Function GetCustomActionTargetFloatListOverlap(string Id, int Index, string Record, float[] Values) Global Native
; L2689 - same as GetCustomActionTargetFloatListOverlap, except values are passed as a csv-string
float[] Function GetCustomActionTargetFloatListOverlapCSV(string Id, int Index, string Record, string Values) Global Native
; L2700 - checks if the action has a custom string list record defined for the action target
bool Function HasCustomActionTargetStringList(string Id, int Index, string Record) Global Native
; L2711 - returns the custom string list record defined for the action target
string[] Function GetCustomActionTargetStringList(string Id, int Index, string Record) Global Native
; L2722 - checks if the custom string list record defined for the action target contains a value
bool Function CustomActionTargetStringListContains(string Id, int Index, string Record, string Value) Global Native
; L2733 - checks if the custom string list record defined for the action target contains any of a list of values
bool Function CustomActionTargetStringListContainsAny(string Id, int Index, string Record, string[] Values) Global Native
; L2744 - same as CustomActionTargetStringListContainsAny, except values are passed as a csv-string
bool Function CustomActionTargetStringListContainsAnyCSV(string Id, int Index, string Record, string Values) Global Native
; L2755 - checks if the custom string list record defined for the action target contains all of a list of values
bool Function CustomActionTargetStringListContainsAll(string Id, int Index, string Record, string[] Values) Global Native
; L2766 - same as CustomActionTargetStringListContainsAll, except values are passed as a csv-string
bool Function CustomActionTargetStringListContainsAllCSV(string Id, int Index, string Record, string Values) Global Native
; L2777 - returns all entries of a custom string list defined for the action target that overlap with the list
string[] Function GetCustomActionTargetStringListOverlap(string Id, int Index, string Record, string[] Values) Global Native
; L2788 - same as GetCustomActionTargetStringListOverlap, except values are passed as a csv-string
string[] Function GetCustomActionTargetStringListOverlapCSV(string Id, int Index, string Record, string Values) Global Native
; L2807 - checks if the action has a custom int record defined for the action performer
bool Function HasCustomActionPerformerInt(string Id, int Index, string Record) Global Native
; L2819 - returns the custom int record defined for the action performer
int Function GetCustomActionPerformerInt(string Id, int Index, string Record, int Fallback = 0) Global Native
; L2831 - checks if the custom int record defined for the action performer is a specific value
bool Function IsCustomActionPerformerInt(string Id, int Index, string Record, int Value) Global Native
; L2842 - checks if the action has a custom float record defined for the action performer
bool Function HasCustomActionPerformerFloat(string Id, int Index, string Record) Global Native
; L2854 - returns the custom float record defined for the action performer
float Function GetCustomActionPerformerFloat(string Id, int Index, string Record, float Fallback = 0.0) Global Native
; L2866 - checks if the custom float record defined for the action performer is a specific value
bool Function IsCustomActionPerformerFloat(string Id, int Index, string Record, float Value) Global Native
; L2877 - checks if the action has a custom string record defined for the action performer
bool Function HasCustomActionPerformerString(string Id, int Index, string Record) Global Native
; L2889 - returns the custom string record defined for the action performer
string Function GetCustomActionPerformerString(string Id, int Index, string Record, string Fallback = "") Global Native
; L2901 - checks if the custom string record defined for the action performer is a specific value
bool Function IsCustomActionPerformerString(string Id, int Index, string Record, string Value) Global Native
; L2912 - checks if the action has a custom int list record defined for the action performer
bool Function HasCustomActionPerformerIntList(string Id, int Index, string Record) Global Native
; L2923 - returns the custom int list record defined for the action performer
int[] Function GetCustomActionPerformerIntList(string Id, int Index, string Record) Global Native
; L2934 - checks if the custom int list record defined for the action performer contains a value
bool Function CustomActionPerformerIntListContains(string Id, int Index, string Record, int Value) Global Native
; L2945 - checks if the custom int list record defined for the action performer contains any of a list of values
bool Function CustomActionPerformerIntListContainsAny(string Id, int Index, string Record, int[] Values) Global Native
; L2956 - same as CustomActionPerformerIntListContainsAny, except values are passed as a csv-string
bool Function CustomActionPerformerIntListContainsAnyCSV(string Id, int Index, string Record, string Values) Global Native
; L2967 - checks if the custom int list record defined for the action performer contains all of a list of values
bool Function CustomActionPerformerIntListContainsAll(string Id, int Index, string Record, int[] Values) Global Native
; L2978 - same as CustomActionPerformerIntListContainsAll, except values are passed as a csv-string
bool Function CustomActionPerformerIntListContainsAllCSV(string Id, int Index, string Record, string Values) Global Native
; L2989 - returns all entries of a custom int list defined for the action performer that overlap with the list
int[] Function GetCustomActionPerformerIntListOverlap(string Id, int Index, string Record, int[] Values) Global Native
; L3000 - same as GetCustomActionPerformerIntListOverlap, except values are passed as a csv-string
int[] Function GetCustomActionPerformerIntListOverlapCSV(string Id, int Index, string Record, string Values) Global Native
; L3011 - checks if the action has a custom float list record defined for the action performer
bool Function HasCustomActionPerformerFloatList(string Id, int Index, string Record) Global Native
; L3022 - returns the custom float list record defined for the action performer
float[] Function GetCustomActionPerformerFloatList(string Id, int Index, string Record) Global Native
; L3033 - checks if the custom float list record defined for the action performer contains a value
bool Function CustomActionPerformerFloatListContains(string Id, int Index, string Record, float Value) Global Native
; L3045 - checks if the custom float list record defined for the action performer contains any of a list of values
bool Function CustomActionPerformerFloatListContainsAny(string Id, int Index, string Record, float[] Values) Global Native
; L3057 - same as CustomActionPerformerFloatListContainsAny, except values are passed as a csv-string
bool Function CustomActionPerformerFloatListContainsAnyCSV(string Id, int Index, string Record, string Values) Global Native
; L3069 - checks if the custom float list record defined for the action performer contains all of a list of values
bool Function CustomActionPerformerFloatListContainsAll(string Id, int Index, string Record, float[] Values) Global Native
; L3081 - same as CustomActionPerformerFloatListContainsAll, except values are passed as a csv-string
bool Function CustomActionPerformerFloatListContainsAllCSV(string Id, int Index, string Record, string Values) Global Native
; L3093 - returns all entries of a custom float list defined for the action performer that overlap with the list
float[] Function GetCustomActionPerformerFloatListOverlap(string Id, int Index, string Record, float[] Values) Global Native
; L3105 - same as GetCustomActionPerformerFloatListOverlap, except values are passed as a csv-string
float[] Function GetCustomActionPerformerFloatListOverlapCSV(string Id, int Index, string Record, string Values) Global Native
; L3116 - checks if the action has a custom string list record defined for the action performer
bool Function HasCustomActionPerformerStringList(string Id, int Index, string Record) Global Native
; L3127 - returns the custom string list record defined for the action performer
string[] Function GetCustomActionPerformerStringList(string Id, int Index, string Record) Global Native
; L3138 - checks if the custom string list record defined for the action performer contains a value
bool Function CustomActionPerformerStringListContains(string Id, int Index, string Record, string Value) Global Native
; L3150 - checks if the custom string list record defined for the action performer contains any of a list of values
bool Function CustomActionPerformerStringListContainsAny(string Id, int Index, string Record, string[] Values) Global Native
; L3162 - same as CustomActionPerformerStringListContainsAny, except values are passed as a csv-string
bool Function CustomActionPerformerStringListContainsAnyCSV(string Id, int Index, string Record, string Values) Global Native
; L3174 - checks if the custom string list record defined for the action performer contains all of a list of values
bool Function CustomActionPerformerStringListContainsAll(string Id, int Index, string Record, string[] Values) Global Native
; L3186 - same as CustomActionPerformerStringListContainsAll, except values are passed as a csv-string
bool Function CustomActionPerformerStringListContainsAllCSV(string Id, int Index, string Record, string Values) Global Native
; L3198 - returns all entries of a custom string list defined for the action performer that overlap with the list
string[] Function GetCustomActionPerformerStringListOverlap(string Id, int Index, string Record, string[] Values) Global Native
; L3210 - same as GetCustomActionPerformerStringListOverlap, except values are passed as a csv-string
string[] Function GetCustomActionPerformerStringListOverlapCSV(string Id, int Index, string Record, string Values) Global Native
; L3232 - gets the minimum custom int that any action has defined for this scene actor
int Function GetCustomSceneActorIntMin(string Id, int Position, string Record, int Fallback = 0) Global Native
; L3244 - gets the maximum custom int that any action has defined for this scene actor
int Function GetCustomSceneActorIntMax(string Id, int Position, string Record, int Fallback = 0) Global Native
; L3256 - gets the sum of all custom ints that any action has defined for this scene actor
int Function GetCustomSceneActorIntSum(string Id, int Position, string Record, int StartValue = 0) Global Native
; L3268 - gets the product of all custom ints that any action has defined for this scene actor
int Function GetCustomSceneActorIntProduct(string Id, int Position, string Record, int StartValue = 1) Global Native
; L3281 - gets the minimum custom float that any action has defined for this scene actor
float Function GetCustomSceneActorFloatMin(string Id, int Position, string Record, float Fallback = 0.0) Global Native
; L3293 - gets the maximum custom float that any action has defined for this scene actor
float Function GetCustomSceneActorFloatMax(string Id, int Position, string Record, float Fallback = 0.0) Global Native
; L3305 - gets the sum of all custom floats that any action has defined for this scene actor
float Function GetCustomSceneActorFloatSum(string Id, int Position, string Record, float StartValue = 0.0) Global Native
; L3317 - gets the product of all custom floats that any action has defined for this scene actor
float Function GetCustomSceneActorFloatProduct(string Id, int Position, string Record, float StartValue = 1.0) Global Native
; L3328
int Function FindActionSuperloadCSV(string Id, string ActorPositions = "", string TargetPositions = "", string PerformerPositions = "", string MatePositionsAny = "", string MatePositionsAll = "", string ParticipantPositionsAny = "", string ParticipantPositionsAll = "", string Types = "")
; L3332
int[] Function FindActionsSuperloadCSV(string Id, string ActorPositions = "", string TargetPositions = "", string PerformerPositions = "", string MatePositionsAny = "", string MatePositionsAll = "", string ParticipantPositionsAny = "", string ParticipantPositionsAll = "", string Types = "")
```

### OSequence  (`...\OStim Standalone - Advanced Adult Animation Framework\Scripts\Source\OSequence.psc`, 146 lines; native=12, global-Papyrus=0, instance/other=0)

```papyrus
; L22 - returns a random sequence applicable for the actors
string Function GetRandomSequence(Actor[] Actors) Global Native
; L32 - returns a random furniture sequence applicable for the actors
string Function GetRandomFurnitureSequence(Actor[] Actors, string FurnitureType) Global Native
; L50 - returns a random sequence applicable for the actors with a sequence tag
string Function GetRandomSequenceWithSequenceTag(Actor[] Actors, string Tag) Global Native
; L60 - returns a random sequence applicable for the actors with any of a list of sequence tags
string Function GetRandomSequenceWithAnySequenceTag(Actor[] Actors, string[] Tags) Global Native
; L70 - same as GetRandomSequenceWithAnySequenceTag, except tags are given in a csv-string
string Function GetRandomSequenceWithAnySequenceTagCSV(Actor[] Actors, string Tags) Global Native
; L80 - returns a random sequence applicable for the actors with all of a list of sequence tags
string Function GetRandomSequenceWithAllSequenceTags(Actor[] Actors, string[] Tags) Global Native
; L90 - same as GetRandomSequenceWithAllSequenceTags, except tags are given in a csv-string
string Function GetRandomSequenceWithAllSequenceTagsCSV(Actor[] Actors, string Tags) Global Native
; L102 - returns a random furniture sequence applicable for the actors with a sequence tag
string Function GetRandomFurnitureSequenceWithSequenceTag(Actor[] Actors, string FurnitureType, string Tag) Global Native
; L113 - returns a random furniture sequence applicable for the actors with any of a list of sequence tags
string Function GetRandomFurnitureSequenceWithAnySequenceTag(Actor[] Actors, string FurnitureType, string[] Tags) Global Native
; L124 - same as GetRandomFurnitureSequenceWithAnySequenceTag, except tags are given in a csv-string
string Function GetRandomFurnitureSequenceWithAnySequenceTagCSV(Actor[] Actors, string FurnitureType, string Tags) Global Native
; L135 - returns a random furniture sequence applicable for the actors with all of a list of sequence tags
string Function GetRandomFurnitureSequenceWithAllSequenceTags(Actor[] Actors, string FurnitureType, string[] Tags) Global Native
; L146 - same as GetRandomFurnitureSequenceWithAllSequenceTags, except tags are given in a csv-string
string Function GetRandomFurnitureSequenceWithAllSequenceTagsCSV(Actor[] Actors, string FurnitureType, string Tags) Global Native
```

### OFurniture  (`...\OStim Standalone - Advanced Adult Animation Framework\Scripts\Source\OFurniture.psc`, 85 lines; native=7, global-Papyrus=0, instance/other=0)

```papyrus
; L16 - returns the type of the furniture
string Function GetFurnitureType(ObjectReference FurnitureRef) Global Native
; L28 - checks if the furniture type is a child of the other type
bool Function IsChildOf(string SuperType, string SubType) Global Native
; L41 - returns the closest object reference of each furniture that that is not occupied or reserved
ObjectReference[] Function FindFurniture(int ActorCount, ObjectReference CenterRef, float Radius, float SameFloor = 0.0) Global Native
; L55 - searches for the closest furniture of the specified type that is not occupied or reserved
ObjectReference Function FindFurnitureOfType(string Type, ObjectReference CenterRef, float Radius, float SameFloor = 0.0) Global Native
; L64 - returns an array of five elements {x, y, z, rotation, scale} for the actor offset as defined in the furniture type of the reference
float[] Function GetOffset(ObjectReference FurnitureRef) Global Native
; L75 - gets the scene ID of the scene involding the furniture
int Function GetSceneID(ObjectReference FurnitureRef) Global Native
; L83 - resets all clutter in an area
Function ResetClutter(ObjectReference CenterRef, float Radius) Global Native
```

### OJSON  (`...\OStim Standalone - Advanced Adult Animation Framework\Scripts\Source\OJSON.psc`, 33 lines; native=3, global-Papyrus=0, instance/other=0)

```papyrus
; L13 - gets all the actors that were involved in the thread
Actor[] Function GetActors(string Json) Global Native
; L22 - gets the scene that was played by the thread
string Function GetScene(string Json) Global Native
; L33 - gets the metadata that was attached to the thread
string[] Function GetMetadata(string Json) Global Native
```

### OEvent  (`...\OStim Standalone - Advanced Adult Animation Framework\Scripts\Source\OEvent.psc`, 16 lines; native=1, global-Papyrus=0, instance/other=0)

```papyrus
; L16 - checks if the event is a child of the other event
bool Function IsChildOf(string SuperType, string SubType) Global Native
```

### OActionData  (`...\OStim Standalone - Advanced Adult Animation Framework\Scripts\Source\OActionData.psc`, 49 lines; native=3, global-Papyrus=0, instance/other=0)

```papyrus
; L29 - checks if a Skyrim actor fulfills the conditions of the action actor
bool Function FulfillsActorConditions(string ActionType, Actor Act) Global Native
; L39 - checks if a Skyrim actor fulfills the conditions of the action target
bool Function FulfillsTargetConditions(string ActionType, Actor Act) Global Native
; L49 - checks if a Skyrim actor fulfills the conditions of the action performer
bool Function FulfillsPerformerConditions(string ActionType, Actor Act) Global Native
```

### OCSV  (`...\OStim Standalone - Advanced Adult Animation Framework\Scripts\Source\OCSV.psc`, 111 lines; native=0, global-Papyrus=10, instance/other=0)

```papyrus
; L13 - collection of csv utility functions
string Function ToCSVList(string[] Values) Global
; L17
string[] Function FromCSVList(string Values) Global
; L21
string Function CreateCSVList(int Size, string Filler) Global
; L36
string Function CreateSingleCSVListEntry(int Index, string Entry) Global
; L49
string Function ConcatCSVLists(string ListA, string ListB) Global
; L67
string Function ToCSVMatrix(string[] Values) Global
; L71
string[] Function FromCSVMatrix(string Values) Global
; L75
string Function CreateCSVMatrix(int Size, string Filler) Global
; L90
string Function CreateSingleCSVMatrixEntry(int Index, string Entry) Global
; L103
string Function ConcatCSVMatrices(string MatrixA, string MatrixB) Global
```

### OIntUtil  (`...\OStim Standalone - Advanced Adult Animation Framework\Scripts\Source\OIntUtil.psc`, 16 lines; native=1, global-Papyrus=0, instance/other=0)

```papyrus
; L16 - returns a dynamic length array of time int
int[] Function CreateArray(int Size, int Filler = 0) Global Native
```

### OUtility  (`...\OStim Standalone - Advanced Adult Animation Framework\Scripts\Source\OUtility.psc`, 57 lines; native=3, global-Papyrus=0, instance/other=0)

```papyrus
; L22 - loads a translation from the translation files in the Interface folder
string Function Translate(string Text) Global Native
; L39 - shuffles an array of forms, randomizing the order
Form[] Function ShuffleFormArray(Form[] Array) Global Native
; L57 - returns a list of all quests that have the given global in their text display globals
Quest[] Function GetQuestsWithGlobal(GlobalVariable Tag) Global Native
```

### OUtils  (`...\OStim Standalone - Advanced Adult Animation Framework\Scripts\Source\OUtils.psc`, 599 lines; native=0, global-Papyrus=54, instance/other=0)

```papyrus
; L3
OSexIntegrationMain Function GetOStim() Global
; L8
Function Console(String In) Global
; L12
int Function GetTimeOfDay() global ; 0 - day | 1 - morning/dusk | 2 - Night
; L25
float Function GetCurrentHourOfDay() global
; L34
Function RegisterForOUpdate(form f) Global
; L38
function StoreNPCDataFloat(actor npc, string keys, Float num) Global
; L43
Float function GetNPCDataFloat(actor npc, string keys) Global
; L47
function StoreNPCDataInt(actor npc, string keys, int num) Global
; L52
Int function GetNPCDataInt(actor npc, string keys) Global
; L56
function StoreNPCDataBool(actor npc, string keys, bool value) Global
; L67
Bool function GetNPCDataBool(actor npc, string keys) Global
; L74
Bool Function IntArrayContainsValue(Int[] Arr, Int Val) Global
; L78
Bool Function StringArrayContainsValue(String[] Arr, String Val) Global
; L82
bool Function StringContains(string str, string contains) Global
; L86
bool Function IsModLoaded(string ESPFile) Global
; L90
float[] Function GetNodeLocation(actor act, string node) Global
; L96
int Function GetFloatMin(float[] arr) Global
; L110
int Function GetFloatMax(float[] arr) Global
; L124
float Function ThreeDeeDistance(float[] pointSet1, float[] pointSet2) Global
; L128
Function DisplayTextBanner(String Txt) Global
; L132
Function DisplayToastText(String Txt, float lengthOfTime) Global
; L153
Function HideTutorialText() Global
; L162
string Function KeycodeToKey(int keycode) Global
; L167
int Function KeyToKeycode(string p_key) Global
; L173
string Function GetButtontag(int keycode) Global
; L189
bool Function IsChild(actor act) Global
; L205
Actor[] Function BaseArrToActorArr(ActorBase[] base) Global
; L220
actor Function GetNPC(int id, string source = "skyrim.esm") Global
; L251
float Function GetOriginalScale(objectreference obj) Global
; L255
Float Function TrigAngleZ(Float GameAngleZ) Global
; L262
int[] Function BoolArrToIntArr(bool[] arr) Global
; L275
form[] Function ObjRefArrToFormArr(objectreference[] arr) Global
; L288
ObjectReference[] Function FormArrToObjRefArr(form[] arr) Global
; L301
Bool Function ChanceRoll(Int Chance) Global ; input 60: 60% of returning true
; L305
string function FormatToDecimalPlace(float num, int DecimalPlacesToShow) Global
; L314
string function PadString(string str, int to, int side = 0, string char = " ") Global
; L341
form Function GetFormFromFile(int aiFormID, string asFilename) global
; L352
bool Function MenuOpen() global
; L356
actor[] Function FilterToPlayerFollowers(actor[] acts) Global
; L370
bool Function IsInFirstPerson() global
; L375
Bool Function AppearsFemale(Actor Act) Global
; L380
Function SetSkyUIWidgetsVisible(bool visible) Global
; L391
Bool Function IsNaked(Actor NPC) global
; L395
Function SetUIVisible(bool visible) Global
; L399
bool Function IsUIVisible() Global
; L403
objectreference Function GetBlankObject() Global
; L407
Actor[] Function ShuffleActorArray(Actor[] arr) Global
; L425
Function Lock(string mutex_key, float spinlockRate = 0.1) Global
; L431
string[] Function BlowjobClasses() Global
; L442
string[] Function HandjobClasses() Global
; L452
string[] Function CunnilingusClasses() Global
; L461
string[] Function VagPlayClasses() Global
; L471
string[] Function StringArray(string one = "", string two = "", string three = "", string four = "", string five = "", string six = "", string seven = "", string eight = "", string nine = "", string ten = "") Global
; L580
Function RestoreOffset(Actor act, float offset) Global
```

### OData  (`...\OStim Standalone - Advanced Adult Animation Framework\Scripts\Source\OData.psc`, 70 lines; native=34, global-Papyrus=0, instance/other=0)

```papyrus
; L8 - bunch of native functions for data serialization
int Function GetUndressingSlotMask() Global Native
; L10
Function SetUndressingSlotMask(int Mask) Global Native
; L13
string[] Function PairsToNames(string[] Pairs) Global Native
; L16
string[] Function GetEquipObjectTypes() Global Native
; L18
string[] Function GetEquipObjectPairs(int FormID, string Type) Global Native
; L20
string Function GetEquipObjectName(int FormID, string Type) Global Native
; L22
Function SetEquipObjectID(int FormID, string Type, string ID) Global Native
; L25
string[] Function GetVoiceSetPairs() Global Native
; L27
string Function GetVoiceSetName(int FormID) Global Native
; L29
Function SetVoiceSet(int FormID, string Voice) Global Native
; L32
string[] Function GetActions() Global Native
; L34
float Function GetActionStimulation(int Role, int FormID, string Actn) Global Native
; L35
Function SetActionStimulation(int Role, int FormID, string Actn, float Stimulation) Global Native
; L36
float Function GetActionMaxStimulation(int Role, int FormID, string Actn) Global Native
; L37
Function SetActionMaxStimulation(int Role, int FormID, string Actn, float Stimulation) Global Native
; L38
float Function GetActionDefaultStimulation(int Role, string Actn) Global Native
; L39
Function ResetActionStimulation(int Role, int FormID, string Actn) Global Native
; L40
float Function GetActionDefaultMaxStimulation(int Role, string Actn) Global Native
; L41
Function ResetActionMaxStimulation(int Role, int FormID, string Actn) Global Native
; L43
string[] Function GetEvents() Global Native
; L45
float Function GetEventStimulation(int Role, int FormID, string Evt) Global Native
; L46
Function SetEventStimulation(int Role, int FormID, string Evt, float Stimulation) Global Native
; L47
float Function GetEventMaxStimulation(int Role, int FormID, string Evt) Global Native
; L48
Function SetEventMaxStimulation(int Role, int FormID, string Evt, float Stimulation) Global Native
; L49
float Function GetEventDefaultStimulation(int Role, string Evt) Global Native
; L50
Function ResetEventStimulation(int Role, int FormID, string Evt) Global Native
; L51
float Function GetEventDefaultMaxStimulation(int Role, string Evt) Global Native
; L52
Function ResetEventMaxStimulation(int Role, int FormID, string Evt) Global Native
; L55
Function ResetSettings() Global Native
; L57
Function ExportSettings() Global Native
; L59
Function ImportSettings() Global Native
; L61
string Function ToLocalizedString(string Text) Global Native
; L62
string Function Localize(string Text) Global Native
; L70 - hot-reloads a single scene json file from disk, updating it in-place
Function ReloadScene(string sceneId) Global Native
```

### OSettings  (`...\OStim Standalone - Advanced Adult Animation Framework\Scripts\Source\OSettings.psc`, 50 lines; native=33, global-Papyrus=0, instance/other=0)

```papyrus
; L9 - internal script, not meant for external use
Function MenuOpened() Global Native
; L11
int Function GetSettingPageCount() Global Native
; L12
string Function GetSettingPageName(int Page) Global Native
; L13
int Function GetSettingPageDisplayOrder(int Page) Global Native
; L15
int Function GetSettingGroupCount(int Page) Global Native
; L16
string Function GetSettingGroupName(int Page, int Group) Global Native
; L17
int Function GetSettingGroupDisplayOrder(int Page, int Group) Global Native
; L19
int Function GetSettingCount(int Page, int Group) Global Native
; L20
string Function GetSettingName(int Page, int Group, int Setting) Global Native
; L21
string Function GetSettingTooltip(int Page, int Group, int Setting) Global Native
; L22
int Function GetSettingType(int Page, int Group, int Setting) Global Native
; L23
bool Function IsSettingEnabled(int Page, int Group, int Setting) Global Native
; L25
bool Function IsSettingActivatedByDefault(int Page, int Group, int Setting) Global Native
; L26
bool Function IsSettingActivated(int Page, int Group, int Setting) Global Native
; L27
bool Function ToggleSetting(int Page, int Group, int Setting) Global Native
; L29
float Function GetDefaultSettingValue(int Page, int Group, int Setting) Global Native
; L30
float Function GetCurrentSettingValue(int Page, int Group, int Setting) Global Native
; L31
float Function GetSettingValueStep(int Page, int Group, int Setting) Global Native
; L32
float Function GetMinSettingValue(int Page, int Group, int Setting) Global Native
; L33
float Function GetMaxSettingValue(int Page, int Group, int Setting) Global Native
; L34
bool Function SetSettingValue(int Page, int Group, int Setting, float Value) Global Native
; L36
int Function GetDefaultSettingIndex(int Page, int Group, int Setting) Global Native
; L37
int Function GetCurrentSettingIndex(int Page, int Group, int Setting) Global Native
; L38
string Function GetCurrentSettingOption(int Page, int Group, int Setting) Global Native
; L39
string[] Function GetSettingOptions(int Page, int Group, int Setting) Global Native
; L40
bool Function SetSettingIndex(int Page, int Group, int Setting, int Index) Global Native
; L42
string Function GetDefaultSettingText(int Page, int Group, int Setting) Global Native
; L43
string Function GetCurrentSettingText(int Page, int Group, int Setting) Global Native
; L44
bool Function SetSettingText(int Page, int Group, int Setting, string Text) Global Native
; L46
int Function GetDefaultSettingKey(int Page, int Group, int Setting) Global Native
; L47
int Function GetCurrentSettingKey(int Page, int Group, int Setting) Global Native
; L48
bool Function SetSettingKey(int Page, int Group, int Setting, int KeyCode) Global Native
; L50
bool Function ClickSetting(int Page, int Group, int Setting) Global Native
```

### OUndress  (`...\OStim Standalone - Advanced Adult Animation Framework\Scripts\Source\OUndress.psc`, 278 lines; native=4, global-Papyrus=7, instance/other=0)

```papyrus
; L16 - if you change the return value of this function to true OStim will invoke the papyrus undressing functions of this script
bool Function UsePapyrusUndressing() Global
; L31 - starts an animated redress sequence
Function AnimateRedress(Actor Act, bool IsFemale, Armor[] Armors, Form[] Weapons) Global
; L106 - gets invoked by AnimateRedress to avoid code duplication
Function PlayRedressAnimation(Actor Act, String Animation, float AnimationLength, float DressPoint, Armor[] Armors, int SlotMask) Global
; L141 - undresses the actor
Armor[] Function Undress(int ThreadId, Actor Act) Global
; L168 - redresses the actor
Armor[] Function Redress(int ThreadId, Actor Act, Armor[] UndressedItems) Global
; L188 - undresses the given slots on the actor
Armor[] Function UndressPartial(int ThreadId, Actor Act, int SlotMask) Global
; L215 - redresses the given slots on the actor
Armor[] Function RedressPartial(int ThreadId, Actor Act, Armor[] UndressedItems, int SlotMask) Global
; L247 - checks if an item can be undressed
bool Function CanUndress(Form Item) Global Native
; L260 - checks if an item is a wig
bool Function IsWig(Actor Act, Armor Item) Global Native
; L269 - returns all armor pieces the actor currently has equipped
Armor[] Function GetWornItems(Actor Act) Global Native
; L278 - removes all None entries from the array and cuts down the size
Armor[] Function TrimArmorArray(Armor[] Items) Global Native
```

### OSKSE  (`...\OStim Standalone - Advanced Adult Animation Framework\Scripts\Source\OSKSE.psc`, 137 lines; native=0, global-Papyrus=10, instance/other=0)

```papyrus
; L17 - returns the value of the RaceMenu height slider added by XPMSSE
float Function GetRmScale(Actor Act, bool IsFemale) Global
; L29 - do NOT ever call this, the .dll caches if the offset is currently removed or not
Function UpdateHeelOffset(Actor Act, float Offset, bool Add, bool Remove, bool IsFemale) Global
; L50 - relays the ApplyNodeOverrides call through Papyrus
Function ApplyNodeOverrides(Actor Act) Global
; L61 - makes the actor say the dialogue after a short delay
Function SayPostDialogue(Actor Act, Actor Target, Topic Dialogue, VoiceType Voice, float Delay) Global
; L75 - fades the game to a blackscreen
Function FadeToBlack(float FadeDuration) Global
; L87 - fades the game from a blackscreen back to normal
Function FadeFromBlack(float FadeDuration) Global
; L96
Function SendOStimEvent(int ThreadId, string Type, Actor eventActor, Actor eventTarget, Actor eventPerformer) Global
; L109
Function ShowBars() Global
; L113
int Function UIExtMessageBox(string Caption, string[] Options) Global
; L133
string Function UIExtTextInput() Global
```

### OSANative  (`...\OStim Standalone - Advanced Adult Animation Framework\Scripts\Source\OSANative.psc`, 190 lines; native=42, global-Papyrus=10, instance/other=0)

```papyrus
; L12
Int Function GetSex(ActorBase Base) Global Native
; L13
Race Function GetRace(ActorBase Base) Global Native
; L14
VoiceType Function GetVoiceType(ActorBase Base) Global Native
; L15
ActorBase Function GetLeveledActorBase(Actor Act) Global Native
; L20
Actor[] Function GetActors(ObjectReference CenterRef = None, Float Radius = 0.0) Global Native
; L23
Function SetPositionEx(Actor Act, Float X, Float Y, Float Z) Global Native
; L29
Actor Function GetActorFromBase(ActorBase Act) Global Native
; L35
ActorBase[] Function LookupRelationshipPartners(Actor FirstActor, AssociationType RelationshipType) Global Native
; L37
actor[] Function SortActorsByDistance(ObjectReference from, actor[] actors) Global Native
; L38
actor[] Function RemoveActorsWithGender(actor[] actors, int gender) Global Native
; L40
form[] Function GetEquippedAmmo(actor act) Global Native
; L42
bool Function IsWig(Actor act, Armor item) Global Native
; L44
Function SetFaceLight (string stateVal, Actor act, string light) Global Native
; L45
Function ApplyFaceLight (string stateVal, Actor act) Global
; L49
Function FireDebugOption(string stateVal) Global
; L53
Function FireDebugActorOption(string stateVal, Actor act) Global
; L64
Function Control(Int direction, Int glyph) Global Native
; L66
Function EndPlayerDialogue() Global Native
; L77
Bool Function SetFace(Actor Act, Int Mode, Int ID, Int Value) Global Native
; L78
Int Function GetFace(Actor Act, Int Mode, Int ID) Global Native
; L81
Bool Function ResetFace(Actor Act) Global
; L86
Bool Function SetFacePhoneme(Actor Act, Int ID, Int Value) Global
; L89
Bool Function SetFaceModifier(Actor Act, Int ID, Int Value) Global
; L94
Int Function GetFacePhoneme(Actor Act, Int ID) Global
; L97
Int Function GetFaceModifier(Actor Act, Int ID) Global
; L100
Int Function GetFaceExpression(Actor Act) Global
; L105
Int Function GetFaceExpressionID(Actor Act) Global
; L118
Int Function GetFormID(Form FormRef) Global Native
; L119
Float Function GetWeight(Form FormRef) Global Native
; L120
String Function GetName(Form FormRef) Global Native
; L121
String Function GetDisplayName(ObjectReference ObjectRef) Global Native
; L128
ObjectReference[] Function FindBed(ObjectReference CenterRef, Float Radius = 1000.0, Float SameFloor = 0.0) Global Native
; L130
Float[] Function GetCoords(ObjectReference ObjectRef) Global Native
; L133
Float Function GetScaleFactor(ObjectReference ObjectRef) Global Native
; L135
ObjectReference	Function GetLocationMarker(location loc) Global Native
; L137
form[] Function RemoveFormsBelowValue(form[] forms, int goldvalue) Global Native
; L146
Function AddActor(int stageId, Actor Act) Global Native
; L148
Function RemoveActor(int stageId) Global Native
; L157
Function ToggleCombat(bool a_enable) Global Native
; L159
Bool Function DetectionActive() Global Native
; L162
Function PrintConsole(String a_str) Global Native
; L164
Int Function RandomInt(Int Min = 0, Int Max = 100) Global Native
; L165
Float Function RandomFloat(Float Min = 0.0, Float Max = 1.0) Global Native
; L169
Function SendEvent(Form FormRef, String Evnt) Global Native
; L177
Bool Function TryLock(String a_lock) Global Native
; L179
Function Unlock(String a_lock) Global Native
; L183
String Function Translate(String a_key) Global Native
; L184
Function SetLocale(String a_locale = "") Global Native
; L186
string Function GetSceneIdFromAnimId(string Id) Global Native
; L187
int Function GetSpeedFromAnimId(string Id) Global Native
; L188
string Function GetAnimClass(string Id) Global Native
; L190
Function SetGlyph(int Glyph) Global Native
```

### OAIUtils  (`...\OStim Standalone - Advanced Adult Animation Framework\Scripts\Source\OAIUtils.psc`, 97 lines; native=0, global-Papyrus=3, instance/other=0)

```papyrus
; L5
String Function GetRandomForeplayAnimation(Actor[] Actors, string FurnitureType, int Aggressor = -1, bool isAggressorFemale, bool isLesbian, bool isGay) Global
; L47
String Function GetRandomSexAnimation(Actor[] Actors, string FurnitureType, int Aggressor = -1, bool isLesbian, bool isGay) Global
; L83
String Function GetPulledOutVersion(Actor[] Actors, string FurnitureType, string SceneID, string[] positionTags) Global
```

### OStimAddon  (`...\OStim Standalone - Advanced Adult Animation Framework\Scripts\Source\OStimAddon.psc`, 145 lines; native=0, global-Papyrus=0, instance/other=11)

```papyrus
; L18
Function InstallAddon(string name)
; L49
OSexIntegrationMain property ostim auto
; L50
actor property PlayerRef auto
; L51
string property AddonName auto
; L66
int property RequiredVersion = 24 auto
; L70
string[] property RegisteredEvents auto
; L74
bool property LoadGameEvents = true auto
; L86
Event OnGameLoad() ; You can either extend this, or not extend it and use RegisteredEvents
; L92
Event OnInit()
; L105
Function RegisterSavedEvents()
; L124
Function RegisterForOEvent(string EventName)
; L129
Event OStim_PreStart(string eventName, string strArg, float numArg, Form sender)
; L132
Event OStim_Start(string eventName, string strArg, float numArg, Form sender)
; L135
Event OStim_AnimationChanged(string eventName, string strArg, float numArg, Form sender)
; L138
Event OStim_SceneChanged(string eventName, string strArg, float numArg, Form sender)
; L141
Event OStim_Orgasm(string eventName, string strArg, float numArg, Form sender)
; L144
Event OStim_End(string eventName, string strArg, float numArg, Form sender)
```

### OStimSubthread  (`...\OStim Standalone - Advanced Adult Animation Framework\Scripts\Source\OStimSubthread.psc`, 263 lines; native=0, global-Papyrus=0, instance/other=27)

```papyrus
; L33
int property id auto
; L43
Event OnInit()
; L49
Event OnEnd(String EventName, String StrArgs, Float EndingThreadID, Form Sender)
; L62
Event OnOrgasm(String EventName, String SceneID, Float OrgasmThreadID, Form OrgasmedActor)
; L81
Function StartAI()
; L85
bool Function IsInUse()
; L89
int Function GetScenePassword()
; L93
ObjectReference Function GetFurniture()
; L97
Bool Function AnimationRunning()
; L101
Actor Function GetActor(int Index)
; L105
Actor[] Function GetActors()
; L120
float Function GetHighestExcitement()
; L147
Function AdjustAnimationSpeed(float amount)
; L151
Function IncreaseAnimationSpeed()
; L155
Function DecreaseAnimationSpeed()
; L159
Function SetCurrentAnimationSpeed(Int InSpeed)
; L173
bool Function StartScene(actor dom, actor sub = none, actor third = none, float time = 120.0, ObjectReference bed = none, bool isAggressive = false, actor aggressingActor = none, bool LinkToMain = false)
; L177
Float Function GetActorExcitement(Actor Act) ; at 100, Actor orgasms
; L181
Function SetActorExcitement(Actor Act, Float Value)
; L185
Function AddActorExcitement(Actor Act, Float Value)
; L189
Bool Function DidAnyActorDie()
; L201
Bool Function IsAnyActorInCombat()
; L213
Function runOsexCommand(string cmd)
; L216
Function AutoIncreaseSpeed()
; L220
Function Orgasm(Actor Act)
; L224
Function EndAnimation()
; L228
Function WarpToAnimation(String Animation)
; L233
bool Function StartSubthreadScene(actor dom, actor sub = none, actor zThirdActor = none, string startingAnimation = "", ObjectReference furnitureObj = none, bool withAI = true, bool isAggressive = false, actor aggressingActor = none)
```

### OStimPlayerAliasScript  (`...\OStim Standalone - Advanced Adult Animation Framework\Scripts\Source\OStimPlayerAliasScript.psc`, 13 lines; native=0, global-Papyrus=0, instance/other=2)

```papyrus
; L3
OSexIntegrationMain Property OStim Auto
; L5
Event OnInit()
; L9
Event OnPlayerLoadGame()
```

### OSexIntegrationMain  (`...\OStim Standalone - Advanced Adult Animation Framework\Scripts\Source\OSexIntegrationMain.psc`, 2936 lines; native=0, global-Papyrus=1, instance/other=124)

```papyrus
; L32
string[] Property POSITION_TAGS Auto
; L38
Faction Property OStimNoFacialExpressionsFaction Auto
; L39
Faction Property OStimExcitementFaction Auto
; L41
GlobalVariable Property OStimImprovedCamSupport Auto
; L42
bool Property EnableImprovedCamSupport
; L52
int Property InstalledVersion Auto
; L57
GlobalVariable Property OStimResetPosition Auto
; L58
Bool Property ResetPosAfterSceneEnd
; L71
GlobalVariable Property OStimCustomTimeScale Auto
; L72
Int Property CustomTimescale
; L81
GlobalVariable Property OStimUseFades Auto
; L82
bool Property UseFades
; L95
GlobalVariable Property OStimUseIntroScenes Auto
; L96
bool Property UseIntroScenes
; L109
GlobalVariable Property OStimAddActorsAtStart Auto
; L110
bool Property AddActorsAtStart
; L124
GlobalVariable Property OStimOnlyLightInDark Auto
; L125
bool Property LowLightLevelLightsOnly
; L139
GlobalVariable Property OStimAutoExportSettings Auto
; L140
bool Property AutoExportSettings
; L153
GlobalVariable Property OStimAutoImportSettings Auto
; L154
bool Property AutoImportSettings
; L172
GlobalVariable Property OStimKeyUp Auto
; L173
int Property KeyUp
; L182
GlobalVariable Property OStimKeyDown Auto
; L183
int Property KeyDown
; L192
GlobalVariable Property OStimKeyLeft Auto
; L193
int Property KeyLeft
; L202
GlobalVariable Property OStimKeyRight Auto
; L203
int Property KeyRight
; L212
GlobalVariable Property OStimKeyYes Auto
; L213
int Property KeyYes
; L222
GlobalVariable Property OStimKeyEnd Auto
; L223
int Property KeyEnd
; L232
GlobalVariable Property OStimKeyToggle Auto
; L233
int Property KeyToggle
; L242
GlobalVariable Property OStimKeySceneStart Auto
; L243
int Property KeyMap
; L256
GlobalVariable Property OStimKeyNPCStart Auto
; L257
int Property KeyNPCStart
; L266
GlobalVariable Property OStimKeySpeedUp Auto
; L267
Int Property SpeedUpKey
; L276
GlobalVariable Property OStimKeySpeedDown Auto
; L277
Int Property SpeedDownKey
; L286
GlobalVariable Property OStimKeyPullOut Auto
; L287
Int Property PullOutKey
; L296
GlobalVariable Property OStimKeyAutoMode Auto
; L297
Int Property ControlToggleKey
; L306
GlobalVariable Property OStimKeyFreeCam Auto
; L307
int property FreecamKey
; L316
GlobalVariable Property OStimKeySearch Auto
; L317
int Property SearchKey
; L326
GlobalVariable Property OStimKeyAlignment Auto
; L327
int Property AlignmentKey
; L336
GlobalVariable Property OStimKeyHideUI Auto
; L337
int Property HideUIKey
; L347
GlobalVariable Property OStimUseRumble Auto
; L348
Bool Property UseRumble
; L365
GlobalVariable Property OStimAutoSpeedControl Auto
; L366
Bool Property EnableActorSpeedControl
; L379
GlobalVariable Property OStimAutoSpeedControlIntervalMin Auto
; L380
int Property AutoSpeedControlIntervalMin
; L394
GlobalVariable Property OStimAutoSpeedControlIntervalMax Auto
; L395
int Property AutoSpeedControlIntervalMax
; L409
GlobalVariable Property OStimAutoSpeedControlExcitementMin Auto
; L410
int Property AutoSpeedControlExcitementMin
; L424
GlobalVariable Property OStimAutoSpeedControlExcitementMax Auto
; L425
int Property AutoSpeedControlExcitementMax
; L440
GlobalVariable Property OStimNPCSceneDuration Auto
; L441
int Property NPCSceneDuration
; L450
GlobalVariable Property OStimEndNPCSceneOnOrgasm Auto
; L451
Bool Property EndNPCSceneOnOrgasm
; L465
GlobalVariable Property OStimNavigationDistanceMax Auto
; L466
int Property NavigationDistanceMax
; L476
GlobalVariable Property OStimUseAutoModeAlways Auto
; L477
Bool Property UseAIControl
; L490
GlobalVariable Property OStimUseAutoModeSolo Auto
; L491
Bool Property UseAIMasturbation
; L504
GlobalVariable Property OStimUseAutoModeDominant Auto
; L505
Bool Property UseAIPlayerAggressor
; L518
GlobalVariable Property OStimUseAutoModeSubmissive Auto
; L519
Bool Property UseAIPlayerAggressed
; L532
GlobalVariable Property OStimUseAutoModeVanilla Auto
; L533
Bool Property UseAINonAggressive
; L547
GlobalVariable Property OStimAutoModeLimitToNavigationDistance Auto
; L548
Bool Property AutoModeLimitToNavigationDistance
; L561
GlobalVariable Property OStimUseAutoModeFades Auto
; L562
Bool Property UseAutoFades
; L575
GlobalVariable Property OStimAutoModeAnimDurationMin Auto
; L576
int Property AutoModeAnimDurationMin
; L590
GlobalVariable Property OStimAutoModeAnimDurationMax Auto
; L591
int Property AutoModeAnimDurationMax
; L605
GlobalVariable Property OStimAutoModeForeplayChance Auto
; L606
int Property AutoModeForeplayChance
; L615
GlobalVariable Property OStimAutoModeForeplayThresholdMin Auto
; L616
int Property AutoModeForeplayThresholdMin
; L630
GlobalVariable Property OStimAutoModeForeplayThresholdMax Auto
; L631
int Property AutoModeForeplayThresholdMax
; L645
GlobalVariable Property OStimAutoModePulloutChance Auto
; L646
int Property AutoModePulloutChance
; L655
GlobalVariable Property OStimAutoModePulloutThresholdMin Auto
; L656
int Property AutoModePulloutThresholdMin
; L670
GlobalVariable Property OStimAutoModePulloutThresholdMax Auto
; L671
int Property AutoModePulloutThresholdMax
; L689
GlobalVariable Property OStimUseFreeCam Auto
; L690
bool Property UseFreeCam
; L703
GlobalVariable Property OStimFreeCamFOV Auto
; L704
int Property FreecamFOV
; L713
GlobalVariable Property OStimFreeCamSpeed Auto
; L714
float Property FreecamSpeed
; L723
GlobalVariable Property OStimForceFirstPersonOnEnd Auto
; L724
Bool Property ForceFirstPersonAfter
; L737
GlobalVariable Property OStimUseScreenShake Auto
; L738
Bool Property UseScreenShake
; L754
GlobalVariable Property OStimMaleSexExcitementMult Auto
; L755
float Property MaleSexExcitementMult
; L764
GlobalVariable Property OStimFemaleSexExcitementMult Auto
; L765
float Property FemaleSexExcitementMult
; L774
GlobalVariable Property OStimExcitementDecayRate Auto
; L775
float Property ExcitementDecayRate
; L784
GlobalVariable Property OStimExcitementDecayGracePeriod Auto
; L785
int Property ExcitementDecayGracePeriod
; L794
GlobalVariable Property OStimPostOrgasmExcitement Auto
; L795
int Property PostOrgasmExcitement
; L804
GlobalVariable Property OStimPostOrgasmExcitementMax Auto
; L805
int Property PostOrgasmExcitementMax
; L817
GlobalVariable Property OStimEnablePlayerBar Auto
; L818
bool Property EnablePlayerBar
; L831
GlobalVariable Property OStimEnableNpcBar Auto
; L832
bool Property EnableNpcBar
; L845
GlobalVariable Property OStimAutoHideBars Auto
; L846
Bool Property AutoHideBars
; L859
GlobalVariable Property OStimMatchBarColorToGender Auto
; L860
Bool Property MatchBarColorToGender
; L876
GlobalVariable Property OStimEndOnPlayerOrgasm Auto
; L877
Bool Property EndOnPlayerOrgasm
; L890
GlobalVariable Property OStimEndOnMaleOrgasm Auto
; L891
Bool Property EndOnMaleOrgasm
; L904
GlobalVariable Property OStimEndOnFemaleOrgasm Auto
; L905
Bool Property EndOnFemaleOrgasm
; L918
GlobalVariable Property OStimEndOnAllOrgasm Auto
; L919
Bool Property EndOnAllOrgasm
; L932
GlobalVariable Property OStimSlowMotionOnOrgasm Auto
; L933
Bool Property SlowMoOnOrgasm
; L946
GlobalVariable Property OStimBlurOnOrgasm Auto
; L947
Bool Property BlurOnOrgasm
; L960
GlobalVariable Property OStimAutoClimaxAnimations Auto
; L961
bool Property AutoClimaxAnimations
; L977
GlobalVariable Property OStimUndressAtStart Auto
; L978
Bool Property AlwaysUndressAtAnimStart
; L991
GlobalVariable Property OStimRemoveWeaponsAtStart Auto
; L992
Bool Property RemoveWeaponsAtStart
; L1005
GlobalVariable Property OStimUndressMidScene Auto
; L1006
Bool Property AutoUndressIfNeeded
; L1019
GlobalVariable Property OStimPartialUndressing Auto
; L1020
Bool Property PartialUndressing
; L1033
GlobalVariable Property OStimRemoveWeaponsWithSlot Auto
; L1034
int Property RemoveWeaponsWithSlot
; L1043
GlobalVariable Property OStimAnimateRedress Auto
; L1044
Bool Property FullyAnimateRedress
; L1057
GlobalVariable Property OStimUndressWigs Auto
; L1058
Bool Property UndressWigs
; L1074
GlobalVariable Property OStimUsePapyrusUndressing Auto
; L1075
Bool Property UsePapyrusUndressing
; L1084
GlobalVariable Property OStimIntendedSexOnly Auto
; L1085
Bool Property IntendedSexOnly
; L1098
GlobalVariable Property OStimPlayerAlwaysDomStraight Auto
; L1099
Bool Property PlayerAlwaysDomStraight
; L1112
GlobalVariable Property OStimPlayerAlwaysSubStraight Auto
; L1113
Bool Property PlayerAlwaysSubStraight
; L1126
GlobalVariable Property OStimPlayerAlwaysDomGay Auto
; L1127
Bool Property PlayerAlwaysDomGay
; L1140
GlobalVariable Property OStimPlayerAlwaysSubGay Auto
; L1141
Bool Property PlayerAlwaysSubGay
; L1154
GlobalVariable Property OStimPlayerSelectRoleStraight Auto
; L1155
Bool Property PlayerSelectRoleStraight
; L1168
GlobalVariable Property OStimPlayerSelectRoleGay Auto
; L1169
Bool Property PlayerSelectRoleGay
; L1182
GlobalVariable Property OStimPlayerSelectRoleThreesome Auto
; L1183
Bool Property PlayerSelectRoleThreesome
; L1196
Message Property OStimRoleSelectionMessage Auto
; L1197
GlobalVariable Property OStimRoleSelectionCount Auto
; L1202
GlobalVariable Property OStimUnequipStrapOnIfNotNeeded Auto
; L1203
bool Property UnequipStrapOnIfNotNeeded
; L1219
GlobalVariable Property OStimUseSoSSex Auto
; L1220
bool Property UseSoSSex
; L1233
GlobalVariable Property OStimUseTNGSex Auto
; L1234
bool Property UseTNGSex
; L1247
GlobalVariable Property OStimFutaUseMaleRole Auto
; L1248
bool Property FutaUseMaleRole
; L1261
GlobalVariable Property OStimFutaUseMaleExcitement Auto
; L1262
bool Property FutaUseMaleExcitement
; L1275
GlobalVariable Property OStimFutaUseMaleClimax Auto
; L1276
bool Property FutaUseMaleClimax
; L1292
GlobalVariable Property OStimDisableScaling Auto
; L1293
bool Property DisableScaling
; L1306
GlobalVariable Property OStimDisableSchlongBending Auto
; L1307
bool Property DisableSchlongBending
; L1320
GlobalVariable Property OStimAlignmentGroupBySex Auto
; L1321
bool Property AlignmentGroupBySex
; L1334
GlobalVariable Property OStimAlignmentGroupByHeight Auto
; L1335
bool Property AlignmentGroupByHeight
; L1348
GlobalVariable Property OStimAlignmentGroupByHeels Auto
; L1349
bool Property AlignmentGroupByHeels
; L1365
GlobalVariable Property OStimUseFurniture Auto
; L1366
bool Property UseFurniture
; L1379
GlobalVariable Property OStimSelectFurniture Auto
; L1380
bool Property SelectFurniture
; L1393
GlobalVariable Property OStimFurnitureSearchDistance Auto
; L1394
int Property FurnitureSearchDistance
; L1403
GlobalVariable Property OStimResetClutter Auto
; L1404
bool Property ResetClutter
; L1417
GlobalVariable Property OStimResetClutterRadius Auto
; L1418
int Property ResetClutterRadius
; L1427
GlobalVariable Property OStimBedRealignment Auto
; L1428
float Property BedRealignment
; L1437
GlobalVariable Property OStimBedOffset Auto
; L1438
float Property BedOffset
; L1447
Message Property OStimBedConfirmationMessage Auto
; L1448
Message Property OStimFurnitureSelectionMessage Auto
; L1449
GlobalVariable[] Property OStimFurnitureSelectionButtons Auto
; L1454
GlobalVariable Property OStimExpressionDurationMin Auto
; L1455
int Property ExpressionDurationMin
; L1469
GlobalVariable Property OStimExpressionDurationMax Auto
; L1470
int Property ExpressionDurationMax
; L1487
GlobalVariable Property OStimMoanIntervalMin Auto
; L1488
int Property MoanIntervalMin
; L1502
GlobalVariable Property OStimMoanIntervalMax Auto
; L1503
int Property MoanIntervalMax
; L1517
GlobalVariable Property OStimMoanVolume Auto
; L1518
float Property MoanVolume
; L1527
GlobalVariable Property OStimMaleDialogueCountdownMin Auto
; L1528
int Property MaleDialogueCountdownMin
; L1542
GlobalVariable Property OStimMaleDialogueCountdownMax Auto
; L1543
int Property MaleDialogueCountdownMax
; L1557
GlobalVariable Property OStimFemaleDialogueCountdownMin Auto
; L1558
int Property FemaleDialogueCountdownMin
; L1572
GlobalVariable Property OStimFemaleDialogueCountdownMax Auto
; L1573
int Property FemaleDialogueCountdownMax
; L1587
GlobalVariable Property OStimPlayerDialogue Auto
; L1588
bool Property PlayerDialogue
; L1601
GlobalVariable Property OStimPreventSameVoiceCrossTalk Auto
; L1602
bool Property PreventSameVoiceCrossTalk
; L1616
GlobalVariable Property OStimSoundVolume Auto
; L1617
float Property SoundVolume
; L1629
GlobalVariable Property OStimUnrestrictedNavigation Auto
; L1630
bool Property UnrestrictedNavigation
; L1643
GlobalVariable Property OStimNoFacialExpressions Auto
; L1644
bool Property NoFacialExpressions
; L1657
GlobalVariable Property OStimFixDarkFace Auto
; L1658
bool Property FixDarkFace
; L1674
Perk Property OStimNPCCondition Auto
; L1678
Actor Property PlayerRef Auto
; L1680
Bool Property UndressDom Auto
; L1681
Bool Property UndressSub Auto
; L1683
OBarsScript Property OBars Auto
; L1687
quest property subthreadquest auto
; L1702
Event OnInit()
; L1717
OBarsScript Function GetBarScript()
; L1721
Bool Function ActorHasFacelight(Actor Act)
; L1742
Function LightActor(Actor Act, Int Pos, Int Brightness) ; pos 1 - ass, pos 2 - face | brightness - 0 = dim
; L1768
Bool Function IsSceneAggressiveThemed() ; if the entire situation should be themed aggressively
; L1782
Actor Function GetAggressiveActor()
; L1796
bool Function IsVictim(actor act)
; L1804
Actor Function GetSexPartner(Actor Char)
; L1819
Function PlayAnimationSequence(String[] list)
; L1823
function FadeFromBlack(float time = 4.0)
; L1827
function FadeToBlack(float time = 1.25)
; L1833
Bool Function IsFemale(Actor Act)
; L1842
Bool Function AppearsFemale(Actor Act)
; L1847
String[] Function GetScene()
; L1852
Function HideAllSkyUIWidgets() ; DEPRECIATED
; L1856
Function ShowAllSkyUIWidgets()
; L1861
Function ModifyStimMult(actor act, float by)
; L1868
bool Function IsBeingStimulated(Actor act)
; L1882
ObjectReference Function FindBed(ObjectReference CenterRef, Float Radius = 0.0)
; L1913
Bool Function SameFloor(ObjectReference BedRef, Float Z, Float Tolerance = 128.0)
; L1917
Bool Function CheckBed(ObjectReference BedRef, Bool IgnoreUsed = True)
; L1943
Float Function GetCurrentStimulation(Actor Act) ; how much an Actor is being stimulated in the current animation
; L1948
float Function GetHighestExcitement()
; L1965
Event OStimStart(String EventName, String sceneId, Float index, Form Sender)
; L1971
Event OStimEnd(String EventName, String sceneId, Float index, Form Sender)
; L1975
Event OStimOrgasm(String EventName, String sceneId, Float index, Form Sender)
; L1997
Function MuteFaceData(Actor Act)
; L2001
Function UnMuteFaceData(Actor Act)
; L2005
Bool Function FaceDataIsMuted(Actor Act)
; L2023 - plays the event expression and if it is valid resets the expression when it's over
Function SendExpressionEvent(Actor Act, string EventName)
; L2050
Function Console(String In) Global
; L2054
Bool Function ChanceRoll(Int Chance) ; input 60: 60% of returning true ;DEPRECIATED - moving to outils in future ver
; L2061
Function ShakeController(Float Power, Float Duration = 0.1)
; L2067
Function DisplayToastAsync(string txt, float lengthOftime)
; L2078
Event DisplayToastEvent(string txt, float time)
; L2082
Bool Function GetGameIsVR()
; L2087
Function Profile(String Name = "")
; L2104
Bool Property SoSInstalled Auto
; L2105
Bool Property TNGInstalled Auto
; L2112
int Function RandomInt(int min = 0, int max = 100) ;DEPRECIATED - moving to osanative in future ver
; L2116
Function Startup()
; L2124
Function OnLoadGame()
; L2189
Faction Property NVCustomOrgasmFaction Auto
; L2191
bool Property UseAINPConNPC
; L2199
bool Property ShowTutorials
; L2207
int Property DefaultFOV
; L2215
bool Property HideBarsInNPCScenes
; L2223
bool Property UseStrongerUnequipMethod
; L2231
bool Property TossClothesOntoGround
; L2239
bool Property OrgasmIncreasesRelationship
; L2247
bool Property UseNativeFunctions
; L2255
float Property SexExcitementMult
; L2264
bool Property UseBed
; L2273
bool Property AllowUnlimitedSpanking
; L2281
bool Property OnlyGayAnimsInGayScenes
; L2290
bool Property EndOnDomOrgasm
; L2299
bool Property EndOnSubOrgasm
; L2308
bool Property RequireBothOrgasmsToFinish
; L2317
Bool Property EnableDomBar
; L2326
Bool Property EnableSubBar
; L2335
Bool Property EnableThirdBar
; L2344
Bool Property UseBrokenCosaveWorkaround
; L2352
Bool Property EndAfterActorHit
; L2360
int Property AiSwitchChance
; L2368
Bool property ForceCloseOStimThread
; L2379
bool Property SkipEndingFadein
; L2387
bool Property Installed
; L2395
Bool Property BlockVRInstalls
; L2403
Bool Property DisableStimulationCalculation
; L2411
Bool Property PauseAI
; L2424
bool Property GetInBedAfterBedScene
; L2432
Bool property EndedProper
; L2440
int Property DomLightPos = 0 Auto
; L2441
int Property SubLightPos = 0 Auto
; L2442
int Property DomLightBrightness = 0 Auto
; L2443
int Property SubLightBrightness = 0 Auto
; L2445
Bool Property MuteOSA
; L2469
bool Property DisableOSAControls = false Auto
; L2471
bool Property EquipStrapOnIfNeeded
; L2479
bool Property UnequipStrapOnIfInWay
; L2493 - returns the current API version
Int Function GetAPIVersion()
; L2497
Actor Function GetDomActor()
; L2501
Actor Function GetSubActor()
; L2505
Actor Function GetThirdActor()
; L2509
ObjectReference Function GetBed()
; L2513
bool Function SoloAnimsInstalled()
; L2519
bool Function ThreesomeAnimsInstalled()
; L2527
Bool Function IsVaginal()
; L2531
Bool Function IsOral()
; L2536
Actor Function GetCurrentLeadingActor()
; L2544
Bool Function GetCurrentAnimIsAggressive()
; L2558
Actor Function GetMostRecentOrgasmedActor()
; L2563
Bool Function IsNaked(Actor NPC)
; L2568
Function PrintBedInfo(ObjectReference Bed)
; L2577
Bool Function IsPlayerInvolved()
; L2583
Bool Function IsNPCScene()
; L2589
Int Function GetMaxSpanksAllowed()
; L2593
Function ToggleFreeCam(Bool On = True)
; L2596
Function RemapStartKey(Int zKey)
; L2600
Function RemapFreecamKey(Int zKey)
; L2604
Function RemapControlToggleKey(Int zKey)
; L2608
Function RemapSpeedUpKey(Int zKey)
; L2612
Function RemapSpeedDownKey(Int zKey)
; L2616
Function RemapPullOutKey(Int zKey)
; L2620
Bool Function IntArrayContainsValue(Int[] Arr, Int Val)
; L2624
Bool Function StringArrayContainsValue(String[] Arr, String Val)
; L2628
bool Function StringContains(string str, string contains)
; L2632
bool Function IsModLoaded(string ESPFile)
; L2636
bool Function IsChild(actor act)
; L2640
Int Function GetSpankCount() ;
; L2644
Function SetGameSpeed(String In)
; L2650
Int Function GetTimesOrgasm(Actor Act)
; L2654
Int Function GetTimeScale()
; L2658
Function SetTimeScale(Int Time)
; L2663
Function ShowBars()
; L2678
Float Function GetActorExcitement(Actor Act)
; L2682
Function SetActorExcitement(Actor Act, Float Value)
; L2686
Function AddActorExcitement(Actor Act, Float Value)
; L2690
bool function IsInFreeCam()
; L2694
int Function GetScenePassword()
; L2698
Bool Function IsActorActive(Actor Act)
; L2702
Bool Function AnimationRunning()
; L2706
Bool Function IsSoloScene()
; L2710
Bool Function IsThreesome()
; L2715
Int Function GetCurrentAnimationSpeed()
; L2719
Bool Function AnimationIsAtMaxSpeed()
; L2723
Int Function GetCurrentAnimationMaxSpeed()
; L2727
Function IncreaseAnimationSpeed()
; L2731
Function DecreaseAnimationSpeed()
; L2735
String Function GetCurrentAnimation()
; L2739
string function GetCurrentAnimationSceneID()
; L2743
Function TravelToAnimationIfPossible(String Animation)
; L2747
Function TravelToAnimation(String Animation)
; L2751
Function WarpToAnimation(String Animation)
; L2755
Bool Function IsActorInvolved(actor act)
; L2763
Function ForceStop()
; L2767
Bool Function IsBed(ObjectReference Bed)
; L2774
Bool Function IsBedRoll(objectReference Bed)
; L2778
Function AdjustAnimationSpeed(float amount)
; L2782
Function SetCurrentAnimationSpeed(Int InSpeed)
; L2786
Function SetDefaultSettings()
; L2790
Function Climax(Actor Act)
; L2794
Function Orgasm(Actor Act)
; L2799
Actor[] Function GetActors()
; L2803
Actor Function GetActor(int Index)
; L2807
Bool Function UsingBed()
; L2812
Bool Function UsingFurniture()
; L2816
string Function GetFurnitureType()
; L2820
ObjectReference Function GetFurniture()
; L2824
float Function GetStimMult(Actor Act)
; L2828
Function SetStimMult(Actor Act, Float Value)
; L2832
Function AddSceneMetadata(string MetaTag)
; L2836
bool Function HasSceneMetadata(string MetaTag)
; L2840
string[] Function GetAllSceneMetadata()
; L2844
Float Function GetTimeSinceLastPlayerInteraction()
; L2848
float Function GetTimeSinceStart()
; L2852
Function SetOrgasmStall(Bool Set)
; L2860
Bool Function GetOrgasmStall()
; L2864
bool Function AutoTransitionForActor(Actor Act, string Type)
; L2868
bool Function AutoTransitionForPosition(int Position, string Type)
; L2872
Function EndAnimation(Bool SmoothEnding = True)
; L2877
OStimSubthread Function GetUnusedSubthread()
; L2892
OStimSubthread Function GetSubthread(int id)
; L2901
Bool Function StartScene(Actor Dom, Actor Sub, Bool zUndressDom = False, Bool zUndressSub = False, Bool zAnimateUndress = False, String zStartingAnimation = "", Actor zThirdActor = None, ObjectReference Bed = None, Bool Aggressive = False, Actor AggressingActor = None)
; L2927
Function Masturbate(Actor Masturbator, Bool zUndress = False, Bool zAnimUndress = False, ObjectReference MBed = None)
```

### OBarsScript  (`...\OStim Standalone - Advanced Adult Animation Framework\Scripts\Source\OBarsScript.psc`, 264 lines; native=0, global-Papyrus=0, instance/other=19)

```papyrus
; L14
OSexIntegrationMain Property OStim Auto
; L17
OSexBar Property DomBar Auto
; L18
OSexBar Property SubBar Auto
; L19
OSexBar Property ThirdBar Auto
; L33
Event OnInit()
; L41
Function InititializeAllBars()
; L47
Function InitBar(OSexBar Bar, Int ID)
; L69
Function SetBarVisible(OSexBar Bar, Bool Visible)
; L79
Function ColorBar(OSexBar Bar, Bool Female = True, Bool Schlong = True, Int ColorZ = -1)
; L96
Bool Function IsBarVisible(OSexBar Bar)
; L100
Function SetBarPercent(OSexBar Bar, Float Percent)
; L104
Function ForceBarPercent(OSexBar Bar, Float Percent)
; L108
float Function GetBarPercent(OSexBar Bar)
; L112
Function FlashBar(OSexBar Bar)
; L116
Event OstimStart(String eventName, String strArg, Float numArg, Form sender)
; L175
Event OStimOrgasm(String eventName, String strArg, Float numArg, Form sender)
; L198
Event OstimThirdJoin(String eventName, String strArg, Float numArg, Form sender)
; L206
Event OstimThirdLeave(String eventName, String strArg, Float numArg, Form sender)
; L212
bool Function IsBarEnabled(Actor Act)
; L224
Function SetBarFullnessProper()
; L234
Function AddBarFullness(Int Bar, Float Amount)
; L244
Float Function GetBarCorrectnessDifference(Int BarID)
; L254
Function OnGameLoad()
```

### OSexBar  (`...\OStim Standalone - Advanced Adult Animation Framework\Scripts\Source\OSexBar.psc`, 240 lines; native=0, global-Papyrus=0, instance/other=11)

```papyrus
; L20
Bool Property FadedOut Auto
; L22
Float Property Width
; L36
Float Property Height
; L50
Int Property PrimaryColor
; L64
Int Property SecondaryColor
; L75
Int Property FlashColor
; L89
String Property FillDirection
; L103
Float Property Percent
; L123
Event OnWidgetReset()
; L151
String Function GetWidgetSource()
; L156
String Function GetWidgetType()
; L160
Bool Function IsExtending()
; L164
Function SetPercent(Float a_percent, Bool a_force = False)
; L175
Function ForcePercent(Float a_percent)
; L180
Function StartFlash(Bool a_force = False)
; L187
Function ForceFlash()
; L192
Function SetColors(Int a_primaryColor, Int a_secondaryColor = -1, Int a_flashColor = -1)
; L207
Function TransitionColors(Int a_primaryColor, Int a_secondaryColor = -1, Int a_flashColor = -1, Int a_duration = 1000)
; L232
Function SetBarVisible(bool Visible)
```

### OSexIntegrationMCM  (`...\OStim Standalone - Advanced Adult Animation Framework\Scripts\Source\OSexIntegrationMCM.psc`, 3466 lines; native=0, global-Papyrus=0, instance/other=71)

```papyrus
; L11
OsexIntegrationMain Property Main Auto
; L29
Event OnInit()
; L33
Function Init()
; L41
int Function GetVersion()
; L45
Event OnVersionUpdate(int Version)
; L49
Function SetupPages()
; L87
Event OnConfigRegister()
; L91
Event OnConfigOpen()
; L99
Event OnConfigClose()
; L105
Event OnPageReset(String Page)
; L177
Event OnOptionHighlight(int Option)
; L193
Event OnOptionSelect(int Option)
; L207
Event OnOptionSliderOpen(int Option)
; L217
Event OnOptionSliderAccept(int Option, float Value)
; L227
Event OnOptionMenuOpen(int Option)
; L237
Event OnOptionMenuAccept(int Option, int Index)
; L247
Event OnOptionDefault(int Option)
; L257
Function OnSlotSelect(int Option)
; L273
Function OnSlotMouseOver(int option)
; L292
Event OnGameReload()
; L297
Function AddColoredHeader(String In)
; L312
Function ExportSettings()
; L320
Function ImportSettings(bool default = false)
; L340
Function DrawGeneralPage()
; L570
Function DrawControlsPage()
; L862
Function DrawAutoControlPage()
; L1323
Function DrawCameraPage()
; L1421
Function DrawExcitementPage()
; L1471
Function OnOptionHighlightExcitement(int Option)
; L1479
Function OnOptionSliderOpenExcitement(int Option)
; L1493
Function OnOptionSliderAcceptExcitement(int Option, float Value)
; L1715
Function DrawGenderRolesPage()
; L2071
Function DrawFurniturePage()
; L2234
Function DrawUndressingPage()
; L2384
Function DrawExpressionPage()
; L2504
Function DrawSoundPage()
; L2540
Function OnOptionHighlightSound(int Option)
; L2550
Function OnOptionSelectSound(int Option)
; L2560
Function OnOptionMenuOpenSound(int Option)
; L2566
Function OnOptionMenuAcceptSound(int Option, int Index)
; L2572
Function OnOptionDefaultSound(int Option)
; L2739
Function DrawAlignmentPage()
; L2855
Function DrawActorsPage()
; L2897
Function OnOptionHighlightActors(int Option)
; L2924
Function OnOptionSelectActors(int Option)
; L2928
Function OnOptionSliderOpenActors(int Option)
; L2966
Function OnOptionSliderAcceptActors(int Option, float Value)
; L2997
Function OnOptionMenuOpenActors(int Option)
; L3025
Function OnOptionMenuAcceptActors(int Option, int Index)
; L3071
Function OnOptionDefaultActors(int Option)
; L3138
Function DrawDebugPage()
; L3145
Function OnOptionHighlightDebug(int Option)
; L3155
Function OnOptionSelectDebug(int Option)
; L3178
Function OpenEquipObjectMenu(int FormID, string Type)
; L3185
Function SetEquipObjectID(int Option, int FormID, string Type, int Index)
; L3190
Function SetEquipObjectIDST(int FormID, string Type, int Index)
; L3195
Function SetEquipObjectIDToDefault(int Option, int FormID, string Type)
; L3204
Function SetEquipObjectIDToDefaultST(int FormID, string Type)
; L3216
Function OpenVoiceSetMenu(int FormID)
; L3223
Function SetVoiceSet(int Option, int FormID, int Index)
; L3228
Function SetVoiceSetToDefault(int Option, int FormID)
; L3254
Function DrawPage(int Page)
; L3260
Function DrawPageTopToBottom(int Page)
; L3328
Function OnOptionHighlightRefactored(int Option)
; L3333
Function OnOptionSelectRefactored(int Option)
; L3353
Function OnOptionSliderOpenRefactored(int Option)
; L3364
Function OnOptionSliderAcceptRefactored(int Option, float Value)
; L3376
Function OnOptionMenuOpenRefactored(int Option)
; L3386
Function OnOptionMenuAcceptRefactored(int Option, int Index)
; L3398
Event OnOptionInputAccept(int Option, string Text)
; L3410
Event OnOptionKeyMapChange(int Option, int KeyCode, string ConflictControl, string ConflictName)
; L3422
Function OnOptionDefaultRefactored(int Option)
```

## Appendix B - OStim Community Resource: generated signature index (non-fragment scripts)

### OCR_OStimUtil  (`...\OStim Community Resource\Source\Scripts\OCR_OStimUtil.psc`, 23 lines; native=0, global-Papyrus=0, instance/other=1)

```papyrus
; L3
Actor Property PlayerRef Auto
; L6
Function StartOstimSequence(Actor[] actors, string sequenceName, bool exitOnEnd = true, ObjectReference furnObj = none)
```

### OCR_OStimScenesUtil  (`...\OStim Community Resource\Source\Scripts\OCR_OStimScenesUtil.psc`, 233 lines; native=0, global-Papyrus=0, instance/other=12)

```papyrus
; L3
Actor Property PlayerRef Auto
; L4
Faction Property OCR_Lover_AcceptsMultiplePartnersFaction Auto
; L5
Faction Property OCR_OStimScenes_3PPCandidateFaction  Auto
; L6
Faction Property OCR_OStimScenes_ChosenCandidateFaction  Auto
; L7
GlobalVariable Property OCR_OStimScenes_3PPCandidateAmount  auto
; L8
GlobalVariable Property OStimAddActorsAtStart  auto
; L9
Message Property OCR_ScenesUtil_3PP  Auto
; L10
Message Property OCR_ScenesUtil_3PPCandidateSelect  Auto
; L11
Message Property OCR_ScenesUtil_3PPHowManyActors  Auto
; L12
Message Property OCR_ScenesUtil_4PCandidateSelect  Auto
; L13
Message Property OCR_ScenesUtil_5PCandidateSelect  Auto
; L14
Quest Property OCR_OStimScenesUtil_RestoreAddActorsAtStart  Auto
; L15
Quest Property OCR_OStimScenes_3PPCandidateAliases  Auto
; L16
Quest Property OCR_OStimScenes_4PCandidateAliases  Auto
; L17
Quest Property OCR_OStimScenes_5PCandidateAliases  Auto
; L18
ReferenceAlias Property FivePCandidate0  Auto
; L19
ReferenceAlias Property FivePCandidate1  Auto
; L20
ReferenceAlias Property FourPCandidate0  Auto
; L21
ReferenceAlias Property FourPCandidate1  Auto
; L22
ReferenceAlias Property FourPCandidate2  Auto
; L23
ReferenceAlias Property OCRSceneNPC  Auto
; L24
ReferenceAlias Property ThreePCandidate0  Auto
; L25
ReferenceAlias Property ThreePCandidate1  Auto
; L26
ReferenceAlias Property ThreePCandidate2  Auto
; L27
ReferenceAlias Property ThreePCandidate3  Auto
; L29
Function OCR_StartScene(actor InvitedNPC)
; L85
Function Select3PCandidate(int numOfCandidates)
; L97
Function Select4PCandidates(int numOfCandidates)
; L121
Function Select5PCandidates(int numOfCandidates)
; L156
Function InitializeThreePCandidates(ReferenceAlias[] threePCandidates)
; L163
function OCR_StartScene2P(actor InvitedNPC)
; L172
function OCR_StartScene3P(actor actor1, actor actor2, actor actor3)
; L182
function OCR_StartScene4P(actor actor1, actor actor2, actor actor3, actor actor4)
; L194
function OCR_StartScene5P(actor actor1, actor actor2, actor actor3, actor actor4, actor actor5)
; L208
function OCR_StartScene6P(actor actor1, actor actor2, actor actor3, actor actor4, actor actor5, actor actor6)
; L221
Function DisableAddActors()
; L228
function StartSceneReset()
```

### OCR_OStimSequencesUtil  (`...\OStim Community Resource\Source\Scripts\OCR_OStimSequencesUtil.psc`, 172 lines; native=0, global-Papyrus=0, instance/other=22)

```papyrus
; L3
Actor Property PlayerRef Auto
; L4
OCR_OStimUtil Property Util Auto
; L11
string Function GetSequence(string actionType)
; L20
bool Function CaressCheekStroke(actor actor1)
; L27
bool Function CaressFail(actor actor1)
; L34
bool Function CaressHoldHands(actor actor1)
; L41
bool Function CaressHug(actor actor1)
; L48
bool Function Chatter(actor actor1)
; L55
bool Function ChatterFail(actor actor1)
; L62
bool Function Court(actor actor1)
; L69
bool Function CourtFail(actor actor1)
; L76
bool Function Kiss1(actor actor1)
; L83
bool Function StandingConversation(actor actor1)
; L90
bool Function StandingConversationLoop(actor actor1)
; L102
string Function GetSequenceNPC(string actionType)
; L111
bool Function CaressCheekStrokeNPC(actor actor1)
; L118
bool Function CaressFailNPC(actor actor1)
; L125
bool Function CaressHoldHandsNPC(actor actor1)
; L132
bool Function CaressHugNPC(actor actor1)
; L139
bool Function ChatterNPC(actor actor1)
; L146
bool Function ChatterFailNPC(actor actor1)
; L153
bool Function CourtNPC(actor actor1)
; L160
bool Function CourtFailNPC(actor actor1)
; L167
bool Function Kiss1NPC(actor actor1)
```

### OCR_AttractionUtil  (`...\OStim Community Resource\Source\Scripts\OCR_AttractionUtil.psc`, 434 lines; native=0, global-Papyrus=0, instance/other=7)

```papyrus
; L3
Actor Property PlayerRef Auto
; L4
Message Property OCR_BaseAttractiveness_Start  Auto
; L5
Message Property OCR_BaseAttractiveness_Physically  Auto
; L6
Message Property OCR_BaseAttractiveness_Socially  Auto
; L7
GlobalVariable Property OCR_AttractivenessBase  Auto
; L8
GlobalVariable Property OCR_CurrentAttraction  Auto
; L9
Faction Property OCR_Trait_EnthusiastArcane auto
; L10
Faction Property OCR_Trait_EnthusiastEscapade auto
; L11
Faction Property OCR_Trait_EnthusiastMartial auto
; L12
Quest Property MG08  Auto
; L13
Quest Property C06 Auto
; L14
Quest Property DB11 Auto
; L15
Quest Property TGLeadership Auto
; L16
Quest Property Favor250 Auto
; L17
Quest Property Favor252 Auto
; L18
Quest Property Favor253 Auto
; L19
Quest Property Favor254 Auto
; L20
Quest Property Favor255 Auto
; L21
Quest Property Favor256 Auto
; L22
Quest Property Favor257 Auto
; L23
Quest Property Favor258 Auto
; L24
Quest Property FreeformRiftenThane Auto
; L25
Faction Property CrimeFactionEastmarch  Auto
; L26
Faction Property CrimeFactionFalkreath Auto
; L27
Faction Property CrimeFactionHaafingar Auto
; L28
Faction Property CrimeFactionHjaalmarch Auto
; L29
Faction Property CrimeFactionPale Auto
; L30
Faction Property CrimeFactionReach Auto
; L31
Faction Property CrimeFactionRift Auto
; L32
Faction Property CrimeFactionWhiterun Auto
; L33
Faction Property CrimeFactionWinterhold Auto
; L34
SPELL Property WerewolfChange  Auto
; L35
Keyword Property Vampire  Auto
; L36
Quest Property MQ305 Auto
; L37
Race Property ArgonianRace  Auto
; L38
Race Property BretonRace Auto
; L39
Race Property DarkElfRace Auto
; L40
Race Property HighElfRace Auto
; L41
Race Property ImperialRace Auto
; L42
Race Property KhajiitRace Auto
; L43
Race Property NordRace Auto
; L44
Race Property OrcRace Auto
; L45
Race Property RedguardRace Auto
; L46
Race Property WoodElfRace Auto
; L47
Faction Property OCR_SocialClass_Other Auto
; L48
Faction Property OCR_SocialClass_CitizenNoble Auto
; L49
Faction Property OCR_SocialClass_SoldierOrGuard Auto
; L50
Faction Property OCR_SocialClass_CitizenMiddle Auto
; L51
Faction Property OCR_SocialClass_CitizenLow Auto
; L52
Faction Property OCR_SocialClass_CitizenLowest Auto
; L53
Faction Property FavorJobsBeggarsFaction Auto
; L54
Faction Property FavorJobsDrunksFaction Auto
; L55
Faction Property JobAnimalTrainerFaction Auto
; L56
Faction Property JobApothecaryFaction Auto
; L57
Faction Property JobBardFaction Auto
; L58
Faction Property JobBlacksmithFaction Auto
; L59
Faction Property JobCarriageFaction Auto
; L60
Faction Property JobCourtWizardFaction Auto
; L61
Faction Property JobFarmerFaction Auto
; L62
Faction Property JobFenceFaction Auto
; L63
Faction Property JobFletcherFaction Auto
; L64
Faction Property JobGuardCaptainFaction Auto
; L65
Faction Property JobHostlerFaction Auto
; L66
Faction Property JobHousecarlFaction Auto
; L67
Faction Property JobInnkeeperFaction Auto
; L68
Faction Property JobInnServer Auto
; L69
Faction Property JobJarlFaction Auto
; L70
Faction Property JobJewelerFaction Auto
; L71
Faction Property JobJusticiar Auto
; L72
Faction Property JobLumberjackFaction Auto
; L73
Faction Property JobMerchantFaction Auto
; L74
Faction Property JobMinerFaction Auto
; L75
Faction Property JobMiscFaction Auto
; L76
Faction Property JobOrcChiefFaction Auto
; L77
Faction Property JobPriestFaction Auto
; L78
Faction Property JobRentRoomFaction Auto
; L79
Faction Property JobStewardFaction Auto
; L80
Faction Property MarkarthWarrensTenantsFaction Auto
; L81
Class Property Citizen  Auto
; L82
Class Property GuardOrc1H  Auto
; L83
Class Property GuardOrc2H Auto
; L84
Class Property SoldierImperialNotGuard  Auto
; L85
Class Property SoldierSonsSkyrimNotGuard  Auto
; L87
function GetAttraction(actor actor1)
; L93
float Function CalculateNPCAttraction(actor actor1)
; L216
Function InitialAttractivenessQuestionnaire()
; L262
int Function GetAttractivenessThreshold(Actor actor1)
; L374
Function GiveRandomEnthusiastTrait(Actor actor1)
; L389
float Function GetHighestSkill(float skill1, float skill2, float skill3, float skill4, float skill5, float skill6)
; L409
float Function CalculateSkillAttractivenessBonus(Actor actor1)
```

### OCR_CommitmentUtil  (`...\OStim Community Resource\Source\Scripts\OCR_CommitmentUtil.psc`, 180 lines; native=0, global-Papyrus=0, instance/other=5)

```papyrus
; L3
Actor Property PlayerRef Auto
; L4
Class Property Citizen  Auto
; L5
Class Property GuardOrc1H  Auto
; L6
Class Property GuardOrc2H Auto
; L7
Class Property SoldierImperialNotGuard  Auto
; L8
Class Property SoldierSonsSkyrimNotGuard  Auto
; L9
Faction Property FavorJobsBeggarsFaction Auto
; L10
Faction Property FavorJobsDrunksFaction Auto
; L11
Faction Property JobAnimalTrainerFaction Auto
; L12
Faction Property JobApothecaryFaction Auto
; L13
Faction Property JobBardFaction Auto
; L14
Faction Property JobBlacksmithFaction Auto
; L15
Faction Property JobCarriageFaction Auto
; L16
Faction Property JobCourtWizardFaction Auto
; L17
Faction Property JobFarmerFaction Auto
; L18
Faction Property JobFenceFaction Auto
; L19
Faction Property JobFletcherFaction Auto
; L20
Faction Property JobGuardCaptainFaction Auto
; L21
Faction Property JobHostlerFaction Auto
; L22
Faction Property JobHousecarlFaction Auto
; L23
Faction Property JobInnServer Auto
; L24
Faction Property JobInnkeeperFaction Auto
; L25
Faction Property JobJarlFaction Auto
; L26
Faction Property JobJewelerFaction Auto
; L27
Faction Property JobJusticiar Auto
; L28
Faction Property JobLumberjackFaction Auto
; L29
Faction Property JobMerchantFaction Auto
; L30
Faction Property JobMinerFaction Auto
; L31
Faction Property JobMiscFaction Auto
; L32
Faction Property JobOrcChiefFaction Auto
; L33
Faction Property JobPriestFaction Auto
; L34
Faction Property JobRentRoomFaction Auto
; L35
Faction Property JobStewardFaction Auto
; L36
Faction Property OCR_Lover_AcceptsMultiplePartnersFaction Auto
; L37
Faction Property OCR_Lover_Commitment  Auto
; L38
Faction Property OCR_SocialClass_CitizenLow Auto
; L39
Faction Property OCR_SocialClass_CitizenLowest Auto
; L40
Faction Property OCR_SocialClass_CitizenMiddle Auto
; L41
Faction Property OCR_SocialClass_CitizenNoble Auto
; L42
Faction Property OCR_SocialClass_Other Auto
; L43
Faction Property OCR_SocialClass_SoldierOrGuard Auto
; L44
GlobalVariable Property OCR_Commitment_PlayerIsInExclusiveRelationship  Auto
; L45
GlobalVariable Property OCR_Commitment_PlayerIsInNonexclusiveRelationship  Auto
; L46
Keyword Property OCR_AliasIsFilled  Auto
; L47
Quest Property OCR_Commitment_PlayerIsInExclusiveRelationshipQST Auto
; L48
Quest Property OCR_Commitment_PlayerIsInNonexclusiveRelationshipQST Auto
; L49
Race Property OrcRace Auto
; L50
Race Property OrcRaceVampire Auto
; L51
ReferenceAlias Property ExclusiveRelationshipSubjectAlias  Auto
; L52
ReferenceAlias Property NonexclusiveRelationshipSubjectAlias  Auto
; L54
function GetCommitment(actor actor1)
; L60
int function CalculateNPCCommitment(actor actor1)
; L106
function AssignSocialClass(actor actor1)
; L148
function UpdateGlobalVariable_PlayerIsInExclusiveRelationship()
; L165
function UpdateGlobalVariable_PlayerIsInNonexclusiveRelationship()
```

### OCR_PrivateCellsUtil  (`...\OStim Community Resource\Source\Scripts\OCR_PrivateCellsUtil.psc`, 219 lines; native=0, global-Papyrus=0, instance/other=5)

```papyrus
; L3
Actor Property PlayerRef Auto
; L4
Armor Property OCR_InvisibleEquipment_Armor  Auto
; L5
Faction Property OCR_Lover_AcceptsMultiplePartnersFaction Auto
; L6
GlobalVariable Property OCR_RomanceProgression_NoMoreInThisInstance  auto
; L7
Message Property OCR_GoToPrivateCell_FollowersMSG  Auto
; L8
Message Property OCR_GoToPrivateCell_LoversMSG  Auto
; L9
MiscObject Property Gold001  Auto
; L10
ObjectReference Property OCR_XMarker_NPC_Camp  Auto
; L11
ObjectReference Property OCR_XMarker_NPC_Inn  Auto
; L12
ObjectReference Property OCR_XMarker_Player_Camp  Auto
; L13
ObjectReference Property OCR_XMarker_Player_Inn  Auto
; L14
ObjectReference Property OCR_XMarker_Return  Auto
; L15
Quest Property OCR_PrivateCells_EndVisit  Auto
; L16
Quest Property OCR_PrivateCells_FollowerAliases  Auto
; L17
Quest Property OCR_PrivateCells_LoverAliases  Auto
; L18
Quest Property OCR_PrivateCells_PlayerDialogueQST  Auto
; L19
ReferenceAlias Property AliasFollower0  Auto
; L20
ReferenceAlias Property AliasFollower1  Auto
; L21
ReferenceAlias Property AliasFollower2  Auto
; L22
ReferenceAlias Property AliasFollower3  Auto
; L23
ReferenceAlias Property AliasFollower4  Auto
; L24
ReferenceAlias Property AliasFollower5  Auto
; L25
ReferenceAlias Property AliasFollower6  Auto
; L26
ReferenceAlias Property AliasFollower7  Auto
; L27
ReferenceAlias Property AliasFollower8  Auto
; L28
ReferenceAlias Property AliasFollower9  Auto
; L29
ReferenceAlias Property AliasLover0  Auto
; L30
ReferenceAlias Property AliasLover1  Auto
; L31
ReferenceAlias Property AliasLover2  Auto
; L32
ReferenceAlias Property AliasLover3  Auto
; L33
ReferenceAlias Property AliasLover4  Auto
; L34
ReferenceAlias Property AliasLover5  Auto
; L35
ReferenceAlias Property AliasLover6  Auto
; L36
ReferenceAlias Property AliasLover7  Auto
; L37
ReferenceAlias Property AliasLover8  Auto
; L38
ReferenceAlias Property AliasLover9  Auto
; L39
ReferenceAlias Property InvitedNPC  Auto
; L41
function FollowerCamping(actor actor1)
; L66
function GoToPrivateCell_Camp(actor actor1)
; L115
function GoToPrivateCell_Inn(actor actor1)
; L152
function EndVisit()
; L215
function EndVisitActorProcedures(actor actor1)
```

### OCR_PrivateCells_PlayerDialogue  (`...\OStim Community Resource\Source\Scripts\OCR_PrivateCells_PlayerDialogue.psc`, 109 lines; native=0, global-Papyrus=0, instance/other=5)

```papyrus
; L3
Actor Property PlayerRef Auto
; L4
Keyword Property ArmorCuirass Auto
; L5
Keyword Property ClothingBody Auto
; L6
ObjectReference Property OCR_XMarker_Return  Auto
; L7
Spell Property OCR_SkinnyDippingEndSpell Auto
; L8
Spell Property OCR_SkinnyDippingSpell Auto
; L9
Idle Property OCR_FemaleUndressingGlovesAnimation  Auto
; L10
Idle Property OCR_FemaleUndressingTopAnimation  Auto
; L11
Idle Property OCR_FemaleUndressingHeadAnimation  Auto
; L12
Idle Property OCR_FemaleUndressingFeetAnimation  Auto
; L13
Idle Property OCR_FemaleUndressingBottomAnimation  Auto
; L15
function PlayerDialogue_EndVisit()
; L19
Function AnimatedUndressSlot(Actor target, int slot, Idle anim, float duration, float undressTime = 1.5)
; L26
Function SmartUndressing(Actor target)
; L69
function SkinnyDipping(actor actor1)
; L105
function SkinnyDippingEnd(actor actor1)
```

### OCR_PrivateCells_FollowDialogue  (`...\OStim Community Resource\Source\Scripts\OCR_PrivateCells_FollowDialogue.psc`, 30 lines; native=0, global-Papyrus=0, instance/other=1)

```papyrus
; L3
Faction Property OCR_PrivateCellFollowingFaction Auto
; L4
ReferenceAlias Property AliasFollowing  Auto
; L6
Function PrivateCell_SetFollower(actor InvitedNPC, bool setFollower)
```

### OCR_GlobalFunctions  (`...\OStim Community Resource\Source\Scripts\OCR_GlobalFunctions.psc`, 52 lines; native=0, global-Papyrus=1, instance/other=0)

```papyrus
; L3
function AdvanceTimeByHours(float hoursToSkip, GlobalVariable kGameHour, GlobalVariable kGameDay, GlobalVariable kGameDaysPassed, GlobalVariable kGameMonth, GlobalVariable kGameYear) global
```

### OCR_RestoreAddActorsAtStart  (`...\OStim Community Resource\Source\Scripts\OCR_RestoreAddActorsAtStart.psc`, 13 lines; native=0, global-Papyrus=0, instance/other=2)

```papyrus
; L3
GlobalVariable Property OStimAddActorsAtStart  auto
; L4
Quest Property OCR_OStimScenesUtil_RestoreAddActorsAtStart  Auto
; L6
Function Restore()
; L10
Event OStimEnd(string eventName, string strArg, float numArg, Form sender)
```

### OCR_UndressInWater  (`...\OStim Community Resource\Source\Scripts\OCR_UndressInWater.psc`, 57 lines; native=0, global-Papyrus=0, instance/other=5)

```papyrus
; L3
Actor Property PlayerRef Auto
; L4
Armor Property OCR_InvisibleEquipment_Armor  Auto
; L5
Keyword Property ArmorCuirass Auto
; L6
Keyword Property ClothingBody Auto
; L8
function OnTriggerEnter(ObjectReference akActivator)
; L23
function OnTriggerLeave(ObjectReference akActivator)
; L32
function UnequipMain(actor actor1)
; L40
function UnequipMisc(actor actor1)
; L52
function UnequipWeapons(actor actor1)
```

---

## Verification (adversarial pass)

Verifier run 2026-09-21, read-only. Method: every cited installed file was re-opened (Read/Grep); the DLL strings were re-extracted independently (own Python extractor, printable runs >= 4 bytes: 103 305 lines, identical line numbering to the report's `strings -a -n 4` dump); `OStim.esp` was re-parsed independently (TES4 / PERK / GLOB / QUST records); the UPSTREAM files were re-fetched from `https://raw.githubusercontent.com/VersuchDrei/OStimNG/main/skse/src/...`. Upstream `main` contains `ThreadManager::migrateThread` with the same log strings that are in the installed DLL (DLL-str 96753-96778), which is additional evidence that `main` is close to the 7.5.1 build. It is still not proof of byte identity, so everything tagged UPSTREAM remains "inferred for the installed binary".

### Confirmed claims (all 24 load-bearing claims hold; claims 12, 23 and 24 need the small corrections listed further down)

| # | Claim | Result | Evidence re-opened |
|---|---|---|---|
| 1 | 7.5.1, `0x07050010` hard check, API version == plugin version | CONFIRMED | `meta.ini:4`; DLL UTF-16 `FileVersion 7.5.1.0` / `ProductVersion 7.5.1`; `OSexIntegrationMain.psc:2125-2130`, `:2493-2495`; `API version README.txt:11-22` |
| 2 | player thread = ThreadID 0, NPC threads positive | CONFIRMED | `OThread.psc:3-4` |
| 3 | 29 psc / 29 pex, 630 natives, per-script counts, no overriding mod | CONFIRMED | regex count over `Scripts\Source` = 630 (OThread 37, OThreadBuilder 19, OActor 41, OActorUtil 12, OLibrary 97, OMetadata 280, OSANative 42, OData 34, OSettings 33, OSequence 12, OFurniture 7, OUndress 4, OJSON 3, OActionData 3, OUtility 3, OEvent 1, OIntUtil 1, OPlayerThread 1); Glob `mods\*\Scripts\{OThread,OActor,OUndress,OSexIntegrationMain,OThreadBuilder,OLibrary,OMetadata,OActorUtil,OSKSE,OUtils}.pex` = OStim mod only; `overwrite\Scripts` holds only `CoreImpactFramework.pex` |
| 4 | builder sequence, `Create` -1, BuilderID != ThreadID | CONFIRMED | `OThreadBuilder.psc:7,14-35,44-46,213-216` |
| 5 | `Start` returns 0 immediately for player threads; asynchronous dialogue wait / furniture / message boxes / fade | CONFIRMED (upstream) | `ThreadStarter.cpp` (`startPlayerThread(params); return 0;`), `PlayerThreadStarter.cpp` (250 ms `getInDialogue` poll, then `runSynced(handleFurniture)`); DLL-str 96781, 96794-96797, 96807-96813, 96819 |
| 6 | starting animation => no add-actor prompt, no role prompt, no sorting | CONFIRMED (upstream), with the caveat in Addition A3 | `PlayerThreadStarter.cpp`: `handleActorAdding` and `handleActorSorting` both short-circuit to `startInner` on `!params.startingNodes.empty()`; `OThread.psc:20` |
| 7 | with a starting animation only a bed is searched; `SelectFurniture` => Yes/No box; `SetFurniture` / `NoFurniture` avoid it | CONFIRMED (upstream) | `handleFurniture`: `if (!params.noFurniture && !params.furniture && MCM::MCMTable::useFurniture())`, bed branch `showMessageBox("$ostim_message_use_bed", {"$ostim_message_yes", "$ostim_message_no"}, ...)`; `OThread.psc:21`; `OThreadBuilder.psc:178-189` |
| 8 | NavigateTo / QueueNavigation / WarpTo / QueueWarp semantics | CONFIRMED | `OThread.psc:88-131`; upstream `ThreadNavigation.cpp` (`clearNodeQueue()` first; empty route -> `warpTo(node, MCM::MCMTable::useAutoModeFades() OR node->fadeOnEntry)`; `queueWarp` passes `duration` into the bool parameter of `warpTo`); upstream `PapyrusThread.h` (`(int) duration * 1000`) |
| 9 | only Papyrus graph query = `OLibrary.GetScenesInRange(string Id, Actor[] Actors, int Distance = 0)` | CONFIRMED | `OLibrary.psc:32-43` |
| 10 | 15 DLL mod-event names + `ostim_event` created in Papyrus | CONFIRMED | DLL-str 96913-96932 (no other `ostim_` literal exists apart from `$ostim_*` translation keys); `OSKSE.psc:96-104` |
| 11 | event argument mapping | CONFIRMED | `list of mod events.txt:22,35,47,66,79,91,105,119,135,150`; upstream `GameEvents.cpp` |
| 12 | end-event JSON keys + OJSON decoders | CONFIRMED but incomplete - see Correction C1 | DLL-str 96920-96925; `OJSON.psc:13,22,33`; upstream `sendEndEvent` |
| 13 | `ostim_thirdactor_join/leave` are registered but never sent | CONFIRMED | `OBarsScript.psc:258-259`; case-insensitive search for `thirdactor` in the DLL dump = 0 hits; no `SendModEvent` for it in any shipped psc |
| 14 | `GetRandomSceneSuperloadCSV` signature, 45 optional filters, `,` and `;` separators | CONFIRMED (parameter by parameter) | `OLibrary.psc:9-18,1187-1243`; `OAIUtils.psc:5-97` |
| 15 | OMetadata getter list; no furniture / duration / navigation getter | CONFIRMED | `OMetadata.psc:38-383,1698-1799`; regex `furniture|duration|length|navigat|modpack` over OMetadata.psc = 0 matches |
| 16 | `VerifyActors`, `Create` -1, `GetActorsInRangeV2`, `OUtils.IsChild` body | CONFIRMED | `OActor.psc:461-468`; `OActorUtil.psc:174-189`; `OUtils.psc:189-201`. Note `StringContains` = `StringUtil.Find(...) != -1` (`OUtils.psc:82-84`), i.e. a case-insensitive substring test for "child" over actor NAME + race EditorID (false positives such as a name containing "child" are possible) |
| 17 | DLL eligibility rule; perk `OStimNPCCondition` CTDAs | CONFIRMED | upstream `Core.cpp` `isEligible`; own ESP parse: PERK `0x02000E42`, EDID `OStimNPCCondition`, CTDA `func=560 p1=0x00013794 comp=1.0`, `func=69 p1=0x0010760A comp=0.0`, `func=365 comp=0.0`, operator byte 0 (== / AND), run-on Subject; `actor properties\OStimNPC.json:2-7` |
| 18 | climax / stop / auto-mode / player-control functions | CONFIRMED | `OThread.psc:53,234,242,251,305,312,321`; `OActor.psc:23-108`; `OPlayerThread.psc:13` |
| 19 | end-on-orgasm = global properties; ~4 s stop; no builder override | CONFIRMED | `OSexIntegrationMain.psc:876-930`; upstream `ThreadActorClimax.cpp` (`thread->setStopTimer(4000);` five times, next to `endOnAllOrgasm / endOnPlayerOrgasm / endOnMaleOrgasm / endOnFemaleOrgasm / endNPCSceneOnOrgasm`); OThreadBuilder has no such native |
| 20 | settings JSON path; file absent on this machine | CONFIRMED | DLL-str 98198-98208; upstream `Util.cpp`; `Documents\My Games\Skyrim Special Edition\` contains only `Photos, SKSE, Saves, __MO_Saves` and the two ini files |
| 21 | auto mode decided by MCM; `NoAutoMode` also blocks a later `StartAutoMode` | CONFIRMED (upstream) | `ThreadAutoControl.cpp`: `if ((threadFlags & ThreadFlag::NO_AUTO_MODE) == ThreadFlag::NO_AUTO_MODE) { return; }` inside `startAutoMode()`; `evaluateAutoMode()` checks `useAutoModeAlways`, `useAutoModeSolo` (1 actor), `useAutoModeVanilla` |
| 22 | runtime 1.6.1170 / SKSE 2.2.6; CommonLibSSE-NG 7.1.0; enabled in profile; never run | CONFIRMED | `skse64.log:1` (`version = 2.2.6 01064920`); DLL-str 94209, 96478-96480; `modlist.txt:2,4,5,6`; `plugins.txt:3413-3414`; no `OStim.log` in `...\SKSE\`; skse64.log mtime 01:14 is older than the mod folder mtime 01:43 |
| 23 | OCR 1.17.6 contents and thin helpers | CONFIRMED except two counts - see Correction C3 | `OCR\meta.ini:3-4`; `OCR_OStimUtil.psc:6-23`; `OCR_OStimScenesUtil.psc:163-226`; no DLL in OCR |
| 24 | native C++ API exports | CONFIRMED (the string block is 97209-97254; 97205-97208 are fmt-library messages) | DLL-str 97209-97254 |

Additional random spot checks (all MATCH source text and line number): `OFurniture.FindFurnitureOfType` L55; `OSANative.FindBed` L128, `TryLock` L177, `GetSceneIdFromAnimId` L186; `OData.ExportSettings / ImportSettings / ResetSettings` L57 / L59 / L55, `ReloadScene` L70; `OUndress.UsePapyrusUndressing` L16, `CanUndress` L247; all 12 `OSequence` natives (L22-L146); `OLibrary.GetRandomFurnitureSceneWithMultiActorTagForAlLCSV` L444 (the typo is real); `OStimAddon.InstallAddon` L18, `RegisterForOEvent` L124 and the six stub events L129-L144; all 10 `OCSV` names L13-L103; the 3 `OUtility` natives L22 / L39 / L57; `OMetadata.FindActionSuperloadCSV[s]` L3328 / L3332 really lack `Global`; `OSexIntegrationMain.StartScene` L2901-2924, `PauseAI` L2411-2422, `ForceCloseOStimThread` L2368 and every property name used in the section 8 table; the form ids in `list of API forms.txt`; and DLL native-name strings for `VerifyActors, GetScenesInRange, NoPostDialogue, SetPlayerControl, GetThreadID, QueueNavigation, QueueWarp, GetAllThreadIDs, GetRandomSceneSuperloadCSV, FindActionSuperloadCSVv2, GetActorsInRangeV2, SetMetadataCSV, EndAfterSequence, ChangeFurniture, CallEvent` (the psc headers are not ahead of the DLL). No misspelled, wrong-case or mis-attributed API name was found anywhere in the report.

### Corrections

**C1 - The end-event `originator` is not only "normal": running threads can be MIGRATED (sections 2 and 6 and Implication 9 are incomplete).**
The installed DLL contains `bool Threading::ThreadManager::migrateThread(__int64, std::vector<GameAPI::GameActor>, std::function<void(int)>, int)` and its log strings `migrating thread {} with {} actors - COMPLETE STOP THEN START`, `Actor count changed from {} to {}, finding appropriate starting node`, `Actor count unchanged ({}), will restore node {} with speed {}`, `Player being removed - restoring camera/UI settings`, `successfully migrated thread {} to thread {}`, plus the literals `swap` and `remove` (DLL-str 96753-96779). Upstream `ThreadManager.cpp`: `originator = "add"` / `"remove"` / `"swap"`, then `oldThread->setThreadEndOriginator(originator);`; upstream `Thread.cpp`: `GameAPI::GameEvents::sendEndEvent(m_threadId, this, getGameActors(), threadEndOriginator)`. Consequence: when actors are added, removed or swapped in a running scene, the old thread fully stops (`ostim_end` / `ostim_thread_end` fire with `"originator":"add"`, `"remove"` or `"swap"`) and a new thread is started after a delay (a new player thread is again ID 0 and `ostim_thread_start` fires again). Whether builder metadata is carried over to the new thread was NOT verified. `OJSON` has no originator getter, so the glue must substring-test the raw JSON StrArg (for example `StringUtil.Find(Json, "\"originator\":\"normal\"") >= 0`) or debounce "end immediately followed by start" before it tells the server that the scene is over. (`add` has 3 characters and cannot appear in a min-length-4 strings dump; it is upstream-only evidence.)

**C2 - Section 5.3: `"intercourse"` and `"cunnilingus"` are ACTION TAGS, not aliases; the "NOT verified" note can be resolved.**
The report missed the data folder `DATA\action tags\` (23 files: `analpenetration, analstimulation, anilingus, cumshot, cunnilingus, fellatio, fingering, fisting, grinding, intercourse, licking, nipplestimulation, oral, oralnipplestimulation, penilestimulation, romantic, seductive, sensual, sexual, toying, vaginalpenetration, vaginalstimulation, vulvalstimulation`). `actions\vaginalsex.json:170-176` has `"tags": ["intercourse", "-penilestimulation", "sexual", "vaginalpenetration", "vaginalstimulation"]` (its alias list, lines 2-4, is only `"sex"`); `intercourse` is also a tag of `analsex, analtailsex, tribbing, vaginaltailsex`. `cunnilingus` is a tag of `vulvaleating.json` and `vulvallicking.json:125-131` (whose aliases are `clitorallicking, lickclitoris, lickingclitoris, lickingpussy, ...`). Upstream `Papyrus/PapyrusLibrary.h` matches an action-type filter with `action.isType(types[i]) || action.attributes->hasTag(types[i])`, so every `...ActionType...` search parameter accepts action ids, aliases AND action tags (INFERRED for the binary, but it is the only reading under which the shipped `OThreadBuilder.Example` and `OAIUtils` calls can work). `lickingnipples` IS an alias (`lickingnipple.json:2-6`). For the glue the action-tag vocabulary (`romantic, sensual, seductive, sexual, oral, intercourse, ...`) is the right clinical, coarse-grained level for LLM prompts and for search intents; read it with `OMetadata.GetActionTags(Id, Index)` (L1789) and `OMetadata.GetAllActionsTags(Id)` (L1799).

**C3 - OCR counts.** `OCR\SKSE\Plugins\OStim\facial expressions` holds **19** JSONs (`3pp_kissing1-6, 3pp_kissing11, 3pp_kissing12, internalclimax1-6, OCR_moan1-4, tongueout`), not 10. Sequences: **40** files, not 44 - the `OCR_NPC_MF_*` and `OCR_NPC_FM_*` sets have no `StandingConversation` / `StandingConversationLoop` (only `OCR_MF_*` and `OCR_FM_*` do). 27 actions and 40 scene JSONs confirmed.

**C4 - Section 2: "This is exactly the literal order in UPSTREAM GameEvents.cpp" is overstated.** The SET of literals is identical; the ORDER is not. DLL: `ostim_prestart, ostim_thread_start, ostim_start, ostim_scenechanged_, ostim_scenechanged, ostim_animationchanged, ostim_thread_scenechanged`. Source: `ostim_prestart, ostim_start, ostim_thread_start, ostim_scenechanged, ostim_scenechanged_ + id, ostim_thread_scenechanged, ostim_animationchanged` (the compiler pools strings in its own order). The argument mapping is corroborated independently by the shipped `list of mod events.txt`, so nothing functional changes; only the stated basis was too strong. Firing order at start per upstream source: `ostim_prestart` -> `ostim_start` -> `ostim_thread_start`.

**C5 - Implication 6 ("use the superload search with `FurnitureType = OThread.GetFurnitureType(0)`") under-selects on beds.** Upstream `PapyrusThread.h`: `GetFurnitureType` returns `thread->getFurnitureTypeInternal()->getListTypeInternal()->id`, i.e. the LIST type: `"bed"` for any bed (only `furniture types\bed.json` has `"listIndividually": true`; `doublebed.json`, `singlebed.json`, `bedroll.json` have `"supertype": "bed"`), and `"none"` (not `""`) for a running thread without furniture. Upstream `PapyrusFurniture.h`: `OFurniture.GetFurnitureType(ref)` returns the SPECIFIC type id (`Furniture::FurnitureTable::getFurnitureType(furnitureRef, false)->id`, e.g. `doublebed`). Upstream `GraphTable::getRandomNode` accepts a node when `furnitureType->isChildOf(node->furnitureType)`: a search with type X returns scenes of X and of all SUPERTYPES of X, never of subtypes. The installed base pack has 20 scenes with `"furniture": "doublebed"`, 20 with `"singlebed"` and none typed `"bed"`. Therefore search with the specific type - `OFurniture.GetFurnitureType(OThread.GetFurniture(0))` - to reach bed-size-specific scenes; a search with `"bed"` only reaches `bed` + `none` scenes. Upstream `FurnitureTable::getFurnitureType(std::string)` lower-cases and maps `""`, `"none"` and unknown ids to the `none` type, so `""` and `"none"` are interchangeable in searches. (All INFERRED for the binary; cheap to confirm in game.)

### Additions (facts the report missed that matter for the glue)

- **A1 - ESP default values of the prompt / ending globals (resolves Open question 5 as far as defaults go).** Own read-only parse of the GLOB records in `OStim.esp`: `OStimUseFades=1`, `OStimUseIntroScenes=1`, `OStimAddActorsAtStart=1`, `OStimUseFurniture=1`, `OStimSelectFurniture=1`, `OStimFurnitureSearchDistance=15`, `OStimEndOnPlayerOrgasm=0`, **`OStimEndOnMaleOrgasm=1`**, `OStimEndOnFemaleOrgasm=0`, `OStimEndOnAllOrgasm=0`, `OStimUseAutoModeAlways / Solo / Dominant / Submissive / Vanilla = 0`, `OStimNavigationDistanceMax=5`, `OStimAutoClimaxAnimations=1`, `OStimPlayerSelectRoleStraight=0`, **`OStimPlayerSelectRoleGay=1`**, `OStimPlayerSelectRoleThreesome=0`, `OStimPlayerAlwaysDomStraight=0`, `OStimPlayerAlwaysSubStraight=0`, `OStimIntendedSexOnly=1`, `OStimPlayerDialogue=1`, `OStimAutoImportSettings=0`, `OStimAutoExportSettings=0` (137 GLOBs in total). So out of the box: every message box the report lists IS armed (add actor, use bed / select furniture, same-sex role select); start and stop are faded (at least 1.25 s latency on start); the player thread is NOT in auto mode (navigation must come from the player UI or from the glue); and the scene auto-ends about 4 s after the first climax of a schlong-having actor. A LoreRim patch could in principle override GLOBs; none is expected because OStim was added by the user after the list was installed.
- **A2 - `OStim.esp` is ESL-flagged** (TES4 record flags `0x200`, HEDR version 1.7, masters `Skyrim.esm` and `HearthFires.esm`); all API form ids are below 0xFFF, so `Game.GetFormFromFile(0x801, "OStim.esp")` style lookups are the right access pattern and nothing in the glue may assume a full-slot load-order byte for OStim forms. Quest `0x801` has EDID `OSexIntegrationMainQuest`.
- **A3 - The "no prompts when a starting animation is set" guarantee has a hole.** Upstream `startThread` clears ALL starting nodes when the actors do not fulfil the requirements of one node (`params.startingNodes.clear();`, log only, DLL-str 96813), and with an empty list the full prompt path runs (furniture selection, add actor, role selection, sorting, random intro / idle). An unknown scene id only logs `animation {} could not be found` (DLL-str 96801) and likewise leaves the list empty. So an LLM- or server-supplied scene id must never be passed straight to `SetStartingAnimation`: obtain it from an `OLibrary` search made with the same actor array in the same order, otherwise message boxes can appear despite the recipe. `SetFurniture` / `NoFurniture` still suppress the furniture prompts in that case; to be fully prompt-proof the glue would additionally have to save, zero and restore `OStimAddActorsAtStart` and `OStimPlayerSelectRole*` the way OCR does (`OCR_OStimScenesUtil.psc:221-226`).
- **A4 - Second silent failure mode: `main thread already running`** (DLL-str 96748; upstream `ThreadManager::startThreadNoLock`: `if (m_threadMap.contains(0)) { GameAPI::Game::notification("main thread already running"); return -1; }`). Because `Start` / `QuickStart` has already returned 0, the Papyrus caller never sees this -1. The glue must check `OThread.IsRunning(0)` itself before starting AND keep its own "start pending" flag, because `IsRunning` upstream is just "a thread object exists in ThreadManager" (`PapyrusThread.h`), so `IsRunning(0)` stays FALSE during the whole asynchronous pre-start window (dialogue wait, message boxes, 700 ms fade). This answers Open question 6.
- **A5 - What the dialogue wait tests.** Upstream `GameActor.h`: `isInDialogue()` is `IsInDialogueWithPlayer(nullptr, 0, form)`, the engine condition function, evaluated for every actor in the list. CHIM conversation without the vanilla dialogue menu should not set it (INFERENCE, still test in game), but a start issued while a vanilla dialogue menu is open with the target NPC is held until the menu closes, with no timeout. Relevant for menuless questing, where the glue may be executing real topic infos around the same time.
- **A6 - Auto-stop conditions (upstream `Thread.cpp` loop; confirms report section 6):** `isInCombat() || isDead()` for any actor; `!playerThread && !GetActor(0)->getActor().isInSameCell(player)`; `stopTimer`. `OThread.Stop` calls `thread->stopFaded()`.
- **A7 - The native C++ API has an "external UI" mode**: `ThreadInterface::SetExternalUIEnabled(bool)`, `RegisterControlCallback(void (*)(Controls, unsigned int, void*), void*)`, `NavigateToSearchResult(unsigned int, const char*)`, `RegisterEventCallback(void (*)(ThreadEvent, unsigned int, void*), void*)` (DLL-str 97231-97253; log text `External UI {}` with `enabled` / `disabled`). Not needed for the Papyrus route, but it is the only hook that would let a DLL replace the OStim navigation UI instead of merely hiding it with `NoPlayerControl`.
- **A8 - `OThread.QueueNavigation` / `QueueWarp` durations are truncated to whole seconds** (upstream `(int) duration * 1000`), so sub-second dwell values become 0.
- **A9 - Not verified by either pass (keep as open questions):** whether `GetScenesInRange` returns transition nodes and / or the origin node and whether transition hops count toward `Distance` (the upstream `Graph::Node::getNodesInRange` body could not be retrieved); whether builder metadata survives a thread migration; every UPSTREAM-only behaviour against the shipped binary.

### Verifier confidence

High for everything sourced from installed files: all 24 load-bearing claims plus about 40 further names, signatures and line numbers were re-checked and zero API-name errors were found. Medium for UPSTREAM-derived behaviour: it was re-fetched and is consistent, and the strings embedded in the DLL match upstream `main` including the recent `migrateThread` code, but a tag-exact 7.5.1 source was not available.
