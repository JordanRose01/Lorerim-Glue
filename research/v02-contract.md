# v0.2 contract - architect report (2026-09-21)

Deliverable: `glue/PROTOCOL.md` (about 210 lines, binding). Every OStim `file:line` cited there was re-checked by script against the installed sources (42 of 42 match). This file holds the evidence and the reasoning behind it.
No code was written, nothing was deployed, no file outside `glue/PROTOCOL.md` and this report was touched.

Sources read: every `.psc` of the glue, every server hook + `lib/*` + config + migration, `compile.ps1`, `deploy_server.ps1`,
`test_gates.php` (harness part), `PLAYTEST2_NOTES.md`, `README.md`, `research/pt2-*.md`, `REFINE_REPORT.md` (grep), `ostim-api.md` (grep only).
Installed sources checked for every name used in the contract: OStim 7.5.1 `Scripts\Source` (OThread, OThreadBuilder, OActor, OActorUtil,
OFurniture, OLibrary, OMetadata, OSequence, OUndress, OPlayerThread, OSANative, OSexIntegrationMain) + `SKSE\Plugins\OStim\*.txt`;
SKSE `Keyword / Location / Cell / ObjectReference / Form.psc`; `PO3_SKSEFunctions.psc`; CHIM `AIAgentFunctions.psc`;
HerikaServer 3.3.2 `main.php`, `lib/data_functions.php`, `connector/openrouterjson.php`, `functions/json_response.php`,
`processor/funcret.php`, `lib/core/action_catalog.php`; Survival Mode Improved, Survival Control Panel, Serana Dialogue Expansion (+ Romance), Xtended Stay.

## 1. Existing protocol as found (v1) - documented in PROTOCOL.md sections 1, 2, 1.6
All keys, commands and result strings in PROTOCOL.md marked [v1] were taken from the code, not from the README. Small discrepancies found
(cosmetic, for the owners of those files):
- `LRG_Main.psc:366` comment still says snapshots expire after 45 s (server default is 90). `README.md` "What is installed" says two catalog rows; there are three.
- `lrg_core.php:13` `LRG_VERSION` is `0.1.0` while the build is 0.1.1.
- Table `lrg_turn` (migration 001) is created but never read or written. The contract leaves it unused and adds `lrg_memory`.
- `LRG_OStim.PushState` de-duplicates on `event|scene|speed|extra|und`. GAME: the new keys (`leader`, `auto`, `wd`, `furn`, `undp`) must be part of that key or their changes are swallowed.
- `LRG_OStim.MaybeSceneTalk` / `CmdControl` comments say "the funcret follow-up line speaks for this moment". With follow-ups off the reason changes (the NPC already spoke in the same turn, R10) but the behaviour (no scene talk right after a command) stays right.
- `lrgResolveControl` treats `finish` / `end` / `enough` as stop. With the new `climax` verb "finish" is ambiguous; the rail says stop wins, the notes give the LLM the exact key `climax`. BEHAVIOUR may drop `finish` from the stop pattern; it must not weaken `stop`.
- Every `time()` in `lrg_core.php` / `lrg_actions.php` (`_age`, `_tier_since`, stale scene, ceiling) has to become `lrgNow()` or the flow tests cannot move the clock.

## 2. OStim 7.5.1 API audit -> verb set (R1 / R8)
What a player can do with OStim's own UI / keys (from `OSexIntegrationMain.psc` key properties 172-344 and the in-scene UI) and the verbal equivalent:
| OStim UI / key | native(s) in the installed source | verdict |
|---|---|---|
| start scene (KeyMap) | `OThreadBuilder.Create`:46, `SetStartingAnimation`:83, `SetFurniture`:63, `NoFurniture`:189, `NoUndressing`:176, `NoPostDialogue`:166, `Start`:216; `OActorUtil.ToArray`:128, `Sort`:142; `OActor.VerifyActors`:468 | verb `start` (BeginIntimacy) |
| navigate to a scene (menu, search key) | `OThread.NavigateTo`:96 (warps silently when no route), `WarpTo`:119, `QueueNavigation`:109, `QueueWarp`:131; oracle `OLibrary.GetScenesInRange`:43; `OMetadata.IsTransition`:58, `GetActorCount`:86 | verb `goto` by key or by name |
| speed up / down keys | `OThread.GetSpeed`:161, `SetSpeed`:170; `OMetadata.GetMaxSpeed`:77 | `faster / slower / speed` |
| end key | `OThread.Stop`:53 | `stop` (immediate, rail) |
| pull-out key | `OThread.AutoTransition(tid, "pullout")`:141 (type name from the Phase 0 upstream reading; returns false when the scene defines none) | `pullout`, reports an error when false |
| climax control | `OThread.StallClimax`:234, `PermitClimax`:242, `IsClimaxStalled`:251; `OActor.Climax(Act, IgnoreStall)`:99, `GetTimesClimaxed`:108 | `hold / release / climax` |
| auto-mode toggle key (ControlToggleKey) | `OThread.IsInAutoMode`:305, `StartAutoMode`:312, `StopAutoMode`:321; builder `NoAutoMode`:148 | `lead` = npc / player / auto |
| undress / redress | `OActor.Undress`:200, `Redress`:207, `UndressPartial(Act, Mask)`:216, `RedressPartial`:225 (OActor.psc:3-5: all OActor calls are no-ops for actors not in a scene) | `undress / dress`, whole or by slot mask; outside a scene the glue's own slot strip (exists) |
| furniture choice | `OFurniture.FindFurniture`:41, `FindFurnitureOfType`:55, `GetFurnitureType`:16, `IsChildOf`:28; `OThread.GetFurnitureType`:277, `ChangeFurniture`:288 (API 7.3.2); event `ostim_furniturechanged` (list of mod events.txt) | start-on-furniture + `furniture` verb |
| alignment menu, free camera, hide UI | none. Only key properties (`AlignmentKey`, `FreecamKey`, `HideUIKey`); OSANative / OSKSE have no matching function | DROPPED |
| add actor | `OSANative.AddActor`:146 is legacy | DROPPED (2-actor scope) |
Decisions and why:
- **Furniture without message boxes.** Phase 0 (ostim-api.md:4029, upstream `handleFurniture`) shows the box only appears when neither `noFurniture` nor `furniture` is set. So `SetFurniture(builder, ref)` is as safe as today's `NoFurniture`. The game searches with `FindFurniture` (closest free ref per type) and matches the type string, because whether `FindFurnitureOfType("bed")` also returns sub-types (singlebed / doublebed / bedroll) is not documented.
- **ChangeFurniture always gets a scene id.** With an empty id OStim picks a random start scene for that furniture, which could jump the progression ladder. The server therefore only offers a furniture move when the index has a scene for it under the current ceiling.
- **Auto mode.** Phase 0 inference from upstream: after `NoAutoMode` even `StartAutoMode` does nothing. So the glue must NOT call `NoAutoMode`; instead it calls `StopAutoMode(0)` when a glue-started thread starts (auto mode waits `AutoModeAnimDurationMin` seconds before its first move, so there is no race). `lead=auto` later calls `StartAutoMode`. Offered only after the ladder reached tier 4 so OStim's auto navigation cannot skip steps. Needs an in-game check (see 6).
- **Wind-down** is a separate verb: navigate to `lrgPickAfterglowScene()` (exists, unused so far), slow to speed 0, linger, then `Stop`. A Tick timer is preferred over `QueueNavigation`'s duration because it stays cancellable. `stop` is untouched.
- **`NoPostDialogue`** (API 7.4d) added to the start recipe: OStim's own voiced after-scene lines would talk over CHIM.
- **Leaving furniture** dropped: `ChangeFurniture(None)` is undocumented; the furniture hierarchy (`doublebed -> bed -> none`, furniture types README) already lets a bed thread route to no-furniture scenes.
- OStim MCM settings that interact but must not be written (rail): end-on-climax options (`OStimEndOn*Orgasm`, 876-930) can end the thread before a wind-down; `OStimRemoveWeaponsAtStart` (991) is the global switch behind the crash path. Owner notes only.

## 3. Snapshot additions - feasibility (each checked against the declaring file)
| fact | verdict | evidence |
|---|---|---|
| explicitness `x` | trivial | already sent in `lrg_scene` (LRG_OStim.psc:944) |
| location name | feasible, 2 natives | `ObjectReference.GetCurrentLocation()` ObjectReference.psc:242; `Form.GetName()` Form.psc:127 |
| inn / house / castle ... (`ltype`) | feasible | `Keyword.GetKeyword(string)` Keyword.psc:13 (SKSE), `Form.HasKeyword` Form.psc:10. All 13 `LocType*` EditorIDs used were found in `Stock Game\Data\Skyrim.esm` |
| NPC owns the cell | feasible | `Cell.GetActorOwner()` Cell.psc:4, `GetFactionOwner()` Cell.psc:7 |
| NPC lives here / where she lives | feasible | `ObjectReference.GetEditorLocation()` ObjectReference.psc:251; `Location.IsSameLocation` Location.psc:27 |
| owned bed nearby | feasible | `OFurniture.FindFurniture`:41 + `ObjectReference.GetActorOwner()`:215 / `GetFactionOwner()`:254 |
| player has a rented room | feasible, best effort | the INSTALLED `RentRoomScript.psc` (mod Xtended Stay, enabled, line 23) does `Bed.SetActorOwner(Game.GetPlayer().GetActorBase())` and line 103 gives it back. Scan: `PO3_SKSEFunctions.FindAllReferencesOfFormType(ref, 40, radius)` (PO3:804; 40 = Furniture), owner test first, bed-type test only on hits. Only when `ltype=inn`, cached per cell. Not proven in game |
| followers present | exists (`witfol`) | - |
| Survival cold exemption | REAL HOOK EXISTS | `SurvivalModeImprovedApi.RestoreColdLevel(float)` global native, `Survival Mode Improved - SKSE\Source\Scripts\SurvivalModeImprovedApi.psc:4`, comment: "should be used to restore needs independently of core mod functionality". Mod enabled (modlist.txt:1173). Game-only, nothing on the wire. The right amount per 5 s tick is unknown (ini: `fColdToRestoreInWarmArea=1.5` per SMI update): GAME exposes it as an MCM value. Survival Control Panel's `Survival.psc` has only feature toggles, nothing per-actor |
| Serana Dialogue Expansion state | READABLE, coarse | no globals exist; state is quest progress. Romance plugin quests `SDE_R001..SDE_R005`, `SDE_RMQ201` and faction `SDE_RMarriedFaction` (EditorIDs found in `SeranaDialogueExpansion - Romance.esp`); `SDER_QF_SDE_R005_0517230E.psc:88-90` sets Serana's relationship rank to 4. So: `rank` (already sent) = lover, `fac` (already sent; po3 Tweaks is installed, so mod EditorIDs resolve) = married, new `sde` = number of completed romance quests. Stage meanings are not documented, so nothing finer is claimed |

## 4. CHIM facts verified for this contract
- **Silence is really silent (R10).** The JSON schema requires `message` as a string with no minimum length (`functions/json_response.php:472-517`). An empty message: `openrouterjson.php:1003-1045` returns an empty buffer; `lib/data_functions.php:6004` skips `returnLines` when the buffer is empty, so no TTS call; actions are still processed (`:6029-6042`); `main.php:2831-2846` handles "AI only issued commands" by logging only - the filler line there is commented out. No stray line, no TTS, the command still goes out.
- **Follow-up off = no second LLM call.** `processor/funcret.php:131-136`: `followup.enabled` false -> `terminate()`. Consequence handled in the contract: results are recorded from the funcret in `preprocessing` (`last_result`), errors are told to the NPC on her next turn, and the game shows a notification.
- **A plugin can drop a request for free.** `main.php:193` (ext `preprocessing.php`) runs before the MAIN semaphore (`:243`). This is what makes the `lrg_initiative` tick cheap: the server admits or drops it there.
- **Actions on non-speech requests.** `main.php:1078` switches functions off for unknown types, `:1117` runs ext `prerequest.php` right after (already used by the lead tick). `request_types_any` is a hard filter (`lib/core/action_catalog.php:1864-1867`), so each row must list `lrg_initiative` / `lrg_scenetalk` where needed (PROTOCOL 6.5).
- Custom request types reach the server (settled in `refine-integration.md` row 1). `shell_exec` is available to the web PHP (CHIM's own `lib/background_processor.php:96` uses it) - basis for the detached index warm-up; a synchronous fallback on the fast path is specified anyway. PHP is 8.2.28.
- Leads for BEHAVIOUR's R5 audit (not findings): `connector/openrouterjson.php:661-665` and `:1358-1362` already send `BLOCK_NONE` safety settings on the Gemini path; `lib/chat_helper_functions.php:1383` swaps in the `ERROR_OPENAI_POLICY` line (defined at `prompts/command_prompt.php:66`) when it detects a provider policy response - the condition above that line needs reading; the summary / diary / player connectors and per-row `confirmation.default_policy` still need reading.

## 5. The design decisions that matter most
1. NPC initiative (R2) rides on a new request type `lrg_initiative`: the game ticks cheaply for the watched NPC only, the server admits (drawn + dice, or pending invite + private) or drops before the lock. No LLM cost for uninterested NPCs, no game-side knowledge of interest needed.
2. Invitations (R3) are a fourth, SERVER-ONLY action `ExtCmdLRG_Invite` ("SuggestPrivacy"): machine-readable, recorded in `lrg_memory.invite` with a TTL, stripped from the wire so no Papyrus is needed. Text parsing of NPC lines was rejected as unreliable.
3. Interest is one formula with config knobs (PROTOCOL 6.1) and the same score drives the word the LLM sees, `willing` (who may invite) and the closeness gate. With no renown and no Speech bonus it reduces exactly to today's `affinity >= min_affinity`, so the 80 existing gate tests keep their meaning.
4. One `mode` per turn (`silent / closed / public / private / follow / scene`) defines offered actions and guidance; explicit wording is tied to `x !== null` (adult + willing + a romantic mode), never global.
5. Location facts come from real game data (location keyword, cell owner, editor location, rented bed), all verified.
6. All four rows lose the follow-up (R7); results flow back through `funcret` -> `last_result`; `npc=` is added to every param because `HERIKA_NAME` is not reliable at `main.php:193`.
7. The scene index never builds inside a web request; rebuild = detached CLI started from the fast snapshot message or by `deploy_server.ps1`; stale index is served meanwhile; the 24 h rule goes.
8. R10 is a tier rule plus a per-style dice roll (`announce`), not a hard rule, matching the owner's "they don't always have to say something"; gentle options are always silent.
9. Test seams `lrgNow()`, `lrgRoll()`, `lrgHandleGameMessage()`, `lrgPrerequest()`, `lrgMemGet()` make the whole plugin playable offline; FLOWTESTS touches nothing else.
10. File ownership: one shared file remains (`lrg_config.default.json`), split by section with an edit discipline, exactly as the brief assigned it. Moving SCENEINDEX's sections into a second file was rejected because it would need a loader change in BEHAVIOUR's `lrgConfig()` before SCENEINDEX could test anything. Mitigation: every new key has an in-code default and BEHAVIOUR guards new scene-index calls with `function_exists()`.

## 6. Not proven / needs an in-game check
- `NoAutoMode` blocking `StartAutoMode`, and `StopAutoMode` at thread start being early enough (upstream inference).
- `AutoTransition(0, "pullout")` type name (upstream reading, not in the installed docs). The error path covers a wrong name.
- `ChangeFurniture` behaviour when the actors are far from the new furniture (teleport vs walk) and whether it fades.
- Rented-room detection (`prent`) and `FindFurniture` cost inside a 20 s snapshot in a crowded inn. If it proves slow, GAME should compute `nearf` / `bedown` only on forced snapshots and on cell change.
- `RestoreColdLevel` amount per tick.
- CHIM's "conversation cooldown" may swallow `lrg_initiative` requests the same way it may swallow scene talk (README known gap); AIAgent.log will show it.
- A `terminate()` answer to `requestMessageForActor` (dropped initiative tick) is assumed harmless for the DLL, as it is for every other terminated request type; not observed live.

## 7. Owner-side notes to carry into the release notes (nothing here is changed by the glue)
- PocketTTS off the game's GPU (CPU backend, several threads) or an FPS cap; the 404ing `google/gemma-3n-e4b-it` fast connector (pt2-latency.md).
- OStim MCM: end-on-climax options decide whether a wind-down can happen after a climax; the furniture options no longer matter for glue-started scenes.
- Default for `scene_start.prefer_furniture` is `bed`: a scene starts on a nearby free bed only when a gentle start scene exists for that bed type, otherwise standing as today; set `never` to always start standing and move to the bed by voice.
