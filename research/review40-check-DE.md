# Adversarial check of REVIEW_40_RESPONSE.md - sections D and E (items 19-31 + OStim awareness table)

Status: IN PROGRESS (file is updated after each item; an item missing below = not yet checked).
Verifier scope: read-only. Every correction carries file:line or URL.
Abbreviations: OSTIM = `F:\Modlists\LoreRim\mods\OStim Standalone - Advanced Adult Animation Framework`; HS = `\\wsl.localhost\DwemerAI4Skyrim3\var\www\html\HerikaServer`.

---

## OStim awareness table (section E header)

**Verdict: SOUND WITH CORRECTION** - every name, signature and line number is right; the semantics claimed for `GetScenesInRange` and `NavigateTo` are not.

### Checked (all in installed 7.5.1 sources)

| Claim | Result | Source |
|---|---|---|
| `bool IsRunning(int ThreadID)` :46 | CONFIRMED | OSTIM\Scripts\Source\OThread.psc:46 |
| `Stop(int ThreadID)` :53 | CONFIRMED | OThread.psc:53 |
| `string GetScene(int ThreadID)` :86 | CONFIRMED. Doc: returns "" while the thread is still in startup or ended | OThread.psc:79-86 |
| `NavigateTo(int ThreadID, string SceneID)` :96 | CONFIRMED. Doc: "cancels any currently running navigations / if navigation is not possible instead warps there" | OThread.psc:88-96 |
| `QueueNavigation(int, string, float Duration)` :109 | CONFIRMED (API 7.3.4b). Also falls back to a queued warp | OThread.psc:98-109 |
| `WarpTo(int, string, bool UseFades=False)` :119 | CONFIRMED | OThread.psc:119 |
| `int GetSpeed(int)` :161 / `SetSpeed(int,int)` :170 | CONFIRMED. GetSpeed returns -1 in startup/ended; SetSpeed clamps | OThread.psc:154-170 |
| `Actor[] GetActors(int)` :198, `int GetActorPosition(int, Actor)` :218 | CONFIRMED (-1 if not in thread) | OThread.psc:198, 218 |
| `StallClimax(int)` :234 | CONFIRMED. Doc: does NOT stop auto-climax animations that already started. Undo = `PermitClimax(int, bool PermitActors=false)` :242 | OThread.psc:228-242 |
| `string GetFurnitureType(int)` :277 | CONFIRMED | OThread.psc:277 |
| "player thread is always ID 0" | CONFIRMED | OThread.psc:3 |
| `OMetadata.GetName` / `IsTransition` / `GetDefaultSpeed` / `GetMaxSpeed` / `GetSceneTags` / `GetActorTags(Id, Position)` / `GetActionTypes` | ALL CONFIRMED: lines 38 / 58 / 68 / 77 / 198 / 279 / 1698. Note `GetActorTags` takes a Position argument | OSTIM\Scripts\Source\OMetadata.psc |
| `OThreadBuilder.Create(Actor[])` :46, `SetStartingAnimation` :83, `SetFurniture` :63, `NoFurniture` :189, `Start` :216 | ALL CONFIRMED. `Create` returns -1 if any actor is invalid. Also present and relevant: `NoAutoMode` :148, `NoPlayerControl` :157, `NoPostDialogue` :166 (API 7.4d), `NoUndressing` :176, `UndressActors` :136, `SetDuration` :74, `Cancel` :223 | OSTIM\Scripts\Source\OThreadBuilder.psc |
| `string[] OLibrary.GetScenesInRange(string Id, Actor[] Actors, int Distance = 0)` :43 | Signature + line CONFIRMED (API 7.3.4a) | OSTIM\Scripts\Source\OLibrary.psc:32-43 |
| Mod events `ostim_thread_start`, `ostim_thread_scenechanged`, `ostim_thread_speedchanged`, `ostim_actor_orgasm`, `ostim_thread_end` | ALL FIVE CONFIRMED in the installed list, with signatures (lines 58-67, 69-80, 82-93, 95-107, 141-154) | OSTIM\SKSE\Plugins\OStim\list of mod events.txt |
| `OActor.VerifyActors(Actor[])`, `OUtils.IsChild` | CONFIRMED: OActor.psc:468; OUtils.psc:189-201 | see item 22 |

### Corrections

1. **`GetScenesInRange` is NOT "transitions available from the current node".** Installed doc comment (OLibrary.psc:32-42): "returns a list of scenes in navigation range of the given scene ... Distance, the distance to search in, if 0 will use MCM setting ... an array of qualifying scenes". With the default `Distance = 0` the documented meaning is the whole neighbourhood out to the MCM navigation distance (multi-hop), not the one-hop options. For "what can I go to next" the glue must pass `Distance = 1` explicitly.
2. **`Distance = 0` may return an EMPTY array.** Upstream `main` does not implement the documented MCM substitution: `Papyrus/PapyrusLibrary.h` passes `distance` straight to `ScriptAPI::Library::getNodesInRange` (https://raw.githubusercontent.com/VersuchDrei/OStimNG/main/skse/src/Papyrus/PapyrusLibrary.h), which passes it straight to `Node::getNodesInRange` (https://raw.githubusercontent.com/VersuchDrei/OStimNG/main/skse/src/ScriptAPI/LibraryScript.cpp), whose loop is `for (int i = 0; i < distance; i++)` (https://raw.githubusercontent.com/VersuchDrei/OStimNG/main/skse/src/Graph/Node/NodeNavigation.cpp). With 0 the loop never runs. Whether the shipped 7.5.1 DLL matches `main` is UNVERIFIABLE without running the game, so: never call with the default; always pass an explicit positive distance; make "GetScenesInRange(id, actors, 1) is non-empty for a known hub scene" a first smoke test.
3. **It does not return the intermediate transition nodes.** Same upstream function: `Node* dest = nav.nodes.back();` - one hop = one Navigation (which may fold several transition nodes), and only the final node of each navigation is returned. So the array is "landing scenes", and a landing scene can itself be flagged transition only if a navigation ends on one. The origin is not seeded into the result, but a cycle A->B->A at Distance >= 2 adds A back (the `contains` check is against `nodes`, which does not contain `this`). Result is sorted alphabetically by lowercase id and carries no distance information - to rank by hop count the glue must call with 1, then 2, ... This closes open question A9 in research\ostim-api.md:4074 (on upstream; still to be confirmed on the binary).
4. **Only navigation-level conditions are checked.** Upstream checks `nav.fulfilledBy(actorConditions)`; the `nodeCondition` lambda is unused. A returned id is "routable for these actors", which is what the design needs, but it is not a content filter - the content tier filter must be the glue's own.
5. **"`WarpTo` is never used for conversational changes" is not something the glue controls.** `NavigateTo` itself warps when no route exists (OThread.psc:88-92). To honour the promise the glue must only call `NavigateTo` with a target it has just confirmed is inside `GetScenesInRange(current, actors, N)` (or on the server index path), otherwise the user sees a snap.
6. **Event table omissions that matter to item 27:** `ostim_thread_speedchanged` "gets fired when the speed OR SCENE of a thread changes" (list of mod events.txt:82-84) - every scene change produces two events, so forwarding both to CHIM doubles the traffic. All `ostim_thread_*` events fire for NPC-NPC sub-threads too (file header line 53-56) - the handler must filter `ThreadID == 0`. `ostim_thread_end` carries a JSON payload readable with `OJSON.GetActors/GetScene` (lines 146-153) - useful because `OThread.GetActors(0)` is empty once the thread has ended. `ostim_event` (lines 123-139) has a non-standard 5-argument signature and needs its own handler shape.
7. **Startup window:** with the player in the thread the start is asynchronous (OThread.psc:19; GetScene "" / GetSpeed -1 / GetFurnitureType "" during startup: OThread.psc:84, 159, 275). `OThreadBuilder.Start` returning is not "scene running"; wait for `ostim_thread_start` before any `OThread.*(0)` query or navigation.

### Strongest counter-argument
The "YES, all reachable from Papyrus, no DLL" verdict is right for names, but the one function that carries the "what can happen next" requirement has (a) a default argument whose documented behaviour is absent from upstream source and (b) no edge labels, no hop distance, no transition visibility. Live `GetScenesInRange` is therefore a reachability oracle only; the server-side JSON index (item 25) is not an "enrichment", it is the primary source for labels and routes, and the degraded mode of item 25 ("falls back to live GetScenesInRange + OMetadata") will be materially poorer than the response implies: one native call per neighbour per metadata field, from Papyrus, on every scene change.

