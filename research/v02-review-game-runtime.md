# v0.2 - ADVERSARIAL REVIEW of the GAME builder's work (lens: correctness + runtime)

Reviewer role: read-only for project files. Only this file was written.
Reviewed: `glue/game/LoreRimGlue/Source/Scripts/{LRG,LRG_Main,LRG_OStim,LRG_Profile,LRG_MCM,LRG_PlayerAlias}.psc`,
`MCM/Config/LoreRimGlue/{config.json,settings.ini}`, `tools/compile.ps1`, `research/v02-game.md`,
against `glue/PROTOCOL.md` v2, the INSTALLED OStim 7.5.1 / SKSE / po3 / CHIM / SMI sources, and the server's
current emitter and reader (`server/lorerim_glue/lib/lrg_actions.php`, `lrg_core.php`, `lrg_scene_index.php`).

**VERDICT: SHIP AFTER FIXES.**

---

## 0. What I ran myself

| check | result |
|---|---|
| `tools/compile.ps1` | **OK** - `rounds: 2 ; auto-stubs created: 23`, 6 `.pex` produced and copied. Re-run from a clean `$env:TEMP\lrg_build`. |
| `php -l` over every `.php` in the staged plugin (PHP **8.2.28**) | 0 syntax errors |
| `tools/test_gates.php` | **196 passed, 0 failed** |
| `tools/test_scene_index.php` | **ALL CHECKS PASSED** (peak 9.0 MB) |
| `tools/flows/run_flows.php` | **23 scenarios, 466 checks, 0 failed, 0 warnings** |
| NEW: cross-side `ParamGet` parity test (my own, `%TEMP%\lrg_paramget.php`) | **57/57**. Faithful PHP port of `LRG_Main.ParamGet` (LRG_Main.psc:407-422) run against every exact parameter shape in PROTOCOL 2 line 59 plus adversarial cases (`scene` vs `fscene`, an NPC literally named `Do`, empty trailing value). No parsing defect. |
| API sweep: every external static call in the 6 `.psc` files (94 distinct) vs the installed declaring source | **all found, all signatures match** (details in section 2) |
| MCM key sweep | 27 used in script == 27 `id` in `config.json` == 27 in `settings.ini` (script-compared) - claim confirmed |
| Closed-list sweep of every `ReportResult` string | all inside PROTOCOL 1.6; `NavigateBlockedReason`'s internal `"unreachable"` never reaches the wire (handled at LRG_OStim.psc:1309 and :1457) |
| Explicit-dialogue scan of the changed game files | clean |

---

## 1. Claims that are TRUE (spot-checked, not taken on trust)

- **Single update registration.** Binary scan of `LoreRimGlue.esp` shows two quests: `LRG_MainQuest` carries `LRG_Main` + `LRG_OStim` + the `LRG_PlayerAlias` alias script; `LRG_MCMQuest` carries `LRG_MCM` alone. `LRG_OStim` has no `OnUpdate` and never calls `RegisterForSingleUpdate` - only `LRG_Main.RequestTick` does. No conflict. The starvation guard at LRG_Main.psc:298-303 is correct in both directions (earliest wins; a due-but-undelivered tick is not pushed back until it is 3 s overdue).
- **ESL finding is real.** `SurvivalModeImproved.esp` header flags = `0x200` (ESL bit set). `Game.GetModByName` would answer 255, so PROTOCOL 4.3 is wrong and `Game.IsPluginInstalled` (SKSE `Game.psc:296`) is the right call. `SurvivalModeImprovedApi.pex` is installed and `RestoreColdLevel(float)` is declared at `Source/Scripts/SurvivalModeImprovedApi.psc:4`.
- **`pullout` is dead on this install** - I re-ran the scan independently: `grep -ril "pullout"` over all three installed scene packs (280 + 40 + 287 json) returns **zero files**. `OThread.AutoTransition` (OThread.psc:141) returns true only "if the transition exists", so the verb can never succeed. See D1 - the problem is that the server still offers it.
- **`RentRoomScript.psc:23`** `Bed.SetActorOwner(Game.GetPlayer().GetActorBase())` confirmed, and `ClearRoom()` (`:103`) resets it, so `prent` self-clears. Good.
- **Event signatures** all match `SKSE/Plugins/OStim/list of mod events.txt`: `ostim_thread_start` / `_scenechanged` / `_speedchanged` / `ostim_actor_orgasm` / `ostim_furniturechanged` / `ostim_thread_end` (`string, string, float ThreadID, Form`). CHIM's `CHIM_CommandReceived(str,str,str)`, `CHIM_TextReceived(str,str)`, `CHIM_SpeechStarted(Form)`, `CHIM_SpeechStopped(Form)` confirmed at `AIAgentAIMind.psc:1397 / 1422 / 1719,1790 / 1845`.
- **R7 speed claims hold on the CHIM side**: `processor/funcret.php:134` `if (!$followupEnabled) { terminate(); }` - a disabled follow-up really does end the request with no second LLM call; `main.php:2831` handles "AI only issued commands" without returning any line, so an action with an empty `message` produces no TTS. `ext/*/preprocessing.php` runs at `main.php:193`, the MAIN semaphore is taken at `main.php:243` - a dropped `lrg_initiative` tick really is free.
- **Rails that hold:** `stop` is the first branch of `CmdControl` (LRG_OStim.psc:1258), before the partner check and before dry-run; it also cancels a wind-down; a stop arriving while OStim is still building the thread is honoured at `ostim_thread_start` (:1641); the hotkey path `StopScene` (:1611) works from both states. `CmdStart` and `CmdClothing` both re-check `LRG_Profile.IsAdult` on both actors. No lead tick while `leader != npc`, `lastAuto`, or `windDown` (:2066-2071), double-covered by `lrgLeadAllowed` (lrg_actions.php:155). No state leak I could construct: `ResetState` releases `setAnimationBusy(0, ..)` on every exit path (start refusal, prep failure, builder failure, start timeout, thread end, game load), and the new 2.5 s "thread gone without an end event" net (Tick:1847) closes the last hole.
- **Papyrus string comparison is case-insensitive** (`StringUtil.psc` header) - this happens to make `ok.Find(asTarget)` in `NavigateBlockedReason` and `GetFurnitureType(ref) == asType` tolerant of case drift between the server index and OStim. Fine, but it is luck, not design.

---

## 2. API sweep result

94 distinct external static calls. Every one found in the installed declaring file with a matching signature:

`OThread` Stop:53 GetScene:86 NavigateTo:96 WarpTo:119 AutoTransition:141 AutoTransitionForActor:152 GetSpeed:161 SetSpeed:170 GetActors:198 GetActorPosition:218 StallClimax:234 PermitClimax:242 IsClimaxStalled:251 GetFurnitureType:277 ChangeFurniture:288 IsInAutoMode:305 StartAutoMode:312 StopAutoMode:321 IsRunning:46 ·
`OThreadBuilder` Create:46 SetFurniture:63 SetStartingAnimation:83 NoPostDialogue:166 NoUndressing:176 NoFurniture:189 Start:216 ·
`OActor` Climax:99 GetTimesClimaxed:108 Undress:200 Redress:207 UndressPartial:216 RedressPartial:225 IsInOStim:448 VerifyActors:468 ·
`OFurniture` GetFurnitureType:16 IsChildOf:28 FindFurniture:41 ·
`OActorUtil` EmptyArray:109 ToArray:128 Sort:142 · `OLibrary` GetScenesInRange:43 · `OMetadata` IsTransition:58 GetMaxSpeed:77 GetActorCount:86 GetSceneTags:198 GetActionTypes:1698 · `OJSON` GetScene:22 ·
`SurvivalModeImprovedApi` RestoreColdLevel:4 ·
`AIAgentFunctions` (declarations use lowercase `function`, which is why a naive grep misses them) commandEndedForActor:8 logMessageForActor:28 requestMessageForActor:30 setAnimationBusy:31 isActorTalking:33 getAgentByName:62 ·
`PO3_SKSEFunctions` GetEquippedAmmo:61 GetFormEditorID:486 GetActorsByProcessingLevel:546 GetFormFromEditorID:558 FindAllReferencesOfFormType:804 ·
SKSE `Game.IsPluginInstalled:296 GetFormFromFile:105 QueryStat:189 GetCurrentCrosshairRef:449`, `SKSE.GetPluginVersion:20`, `UI.IsMenuOpen:47 IsTextInputEnabled:111`, `Keyword.GetKeyword:13`, `Quest.GetQuest:247 IsCompleted:76`, `Actor.GetWornForm:866 GetEquippedObject:875 IsEquipped:370 GetLeveledActorBase:272`, `Armor.GetSlotMask:116`, `Cell.GetActorOwner:4 GetFactionOwner:7`, `ObjectReference.GetActorOwner:215 GetCurrentLocation:242 GetEditorLocation:251 GetFactionOwner:254 IsFurnitureInUse:417`, `Location.IsSameLocation:27`, `StringUtil.Substring` (len 0 = to end, confirmed in the header comment - `ParamGet` depends on this and gets it right).

**One caveat:** `MCM.psc` and `MCM_ConfigBase.psc` are **hand-written stubs** in `tools/stubs/`, not the real MCM Helper SDK (it is not on disk). The signatures match the published MCM Helper API and playtest 1 proved the reads work at runtime, but this is the one API surface in the build that is not verified against an installed source. Not a defect; a standing risk worth one line in the README.

---

## 3. DEFECTS

### D1 - MEDIUM/HIGH. The `pullout` verb is guaranteed to fail on this install, and the server still offers it every sexual turn

*Evidence.* No `pullout` string exists in any installed OStim / OARE / Community Resource scene file (my own grep, 0 hits over 607 scenes), so `OThread.AutoTransition(0,"pullout")` and `AutoTransitionForActor` always return false. `LRG_OStim.CmdPullOut` (LRG_OStim.psc:1404-1417) therefore always answers `Error: not possible in this position`.
The builder listed this in *notDone* but nothing was done about the **offer**: `lib/lrg_actions.php:890` still appends `'pull out'` to the verb list shown to the LLM whenever the current tier is `sexual`, `:83` names it in the action description, `:644` still maps it.
*Why it is worse than in v1.* Both the failure notification and the "tell her once" mechanism are NEW this round: `LRG_Main.ReportResult:344` now pops `Debug.Notification("LoreRim Glue: not possible in this position")`, and `lrg_actions.php:246` writes `last_result` with `told=false`, so her **next reply is spent apologising for it** - one extra sentence of TTS under the MAIN lock, i.e. it also costs R7.
*Failure scenario.* Any scene that reaches tier `sexual`; the model picks `pull out` (it is in the printed verb list); the player sees a red-herring error notification and the NPC's next line is consumed explaining a failure that can never not happen.
*Smallest correct fix (BEHAVIOUR owns the file).* Delete line 890 of `lib/lrg_actions.php` and remove `pull out` from the `parameters_json` text at `:83`. Leave `lrgResolveControl`'s mapping and the game verb in place for packs that define the transition. **The game side needs no change.**

### D2 - MEDIUM. "If I say a position it should go to that position" (R1) still has a refusal path, because the two route oracles disagree

*Evidence.* The server marks a goto as warp-eligible only when the target is neither in the live 1-hop list nor in its own walk: `lrg_actions.php:667-674`, where the walk is `lrgSceneWalk($ctx['current'], $ctx['live'], (int)$ctx['ceiling'], 3, ...)` - **3 hops and filtered by the progression ceiling**. The game refuses when the target is not in `OLibrary.GetScenesInRange(cur, actors, iNavigationReach)` (default 5) **and** `warp != 1`: `LRG_OStim.psc:1305-1321` with `NavigateBlockedReason:1601-1607`.
The sets are not nested. A target the server reaches through its index graph (so it sends **no** `warp`), but which OStim will not route to for these two actors, produces `Error: that position cannot be reached from here` - the exact outcome the owner said must not happen.
*Extra evidence that refusing is the wrong default:* `OThread.NavigateTo` itself "if navigation is not possible instead warps there" (OThread.psc:89-91) - un-faded. So the choice is not "smooth vs. jump", it is "faded jump vs. refusal".
*Failure scenario.* Player names a position that appears in the scene notes' "or any position in plain words" list; the index says it is 2 hops away through a scene OStim will not use for an F/F pair; the game declines, the notification fires, the player repeats the request and it declines again.
*Smallest correct fix (game side, 3 lines, LRG_OStim.psc:1309-1316):* when `why == "unreachable"`, warp whenever `bAllowWarp:Intimacy` is on, whether or not the server asked:
```papyrus
if why == "unreachable"
    if m.SettingBool("bAllowWarp:Intimacy", true)    ; the server already validated actor count, tier and furniture
        useWarp = true
        why = ""
    else
        why = "that position cannot be reached from here"
    endif
endif
```
This keeps the contract's `warp` key meaningful (the server still *asks* for a warp when it knows there is no route) and turns the last refusal into a faded jump. *Integrator decision:* alternatively set `warp=1` server-side for anything not in `$ctx['live']`; either fixes it, do not do both silently.

### D3 - MEDIUM. "faster" in the first seconds of a scene sets the pace to the MINIMUM

*Evidence.* `OThread.GetSpeed` "returns -1 if the thread is still in startup or ended" (OThread.psc:155-160). `CmdControl` reaches the pace branch as soon as `OThread.IsRunning(0)` is true (LRG_OStim.psc:1247), which is **before** the first scene is set - the builder knows this, because `CmdPullOut:1392`, `CmdFurniture:1521` and `NavigateBlockedReason:1585` all guard `OThread.GetScene(0) == ""` with the closed-list reason `the scene is still starting`. The pace branch (:1278-1296) has no such guard:
```papyrus
int speed = OThread.GetSpeed(0)      ; -1 during startup
if what == "faster"
    speed += 1                       ; 0  -> the SLOWEST speed
elseif what == "slower"
    speed -= 1                       ; -2 -> clamped to 0
```
`OMetadata.GetMaxSpeed("")` is also called with an empty scene id in the same window.
*Failure scenario.* The player (or the NPC on her lead tick) says "faster" within a second or two of `The scene begins.`; the pace silently drops to 0 and the result string still says `The pace changes.` - no error, no log line, nothing to diagnose from.
*Smallest correct fix.* First statement of the pace branch:
```papyrus
string cur = OThread.GetScene(0)
if cur == ""
    m.ReportResult(asNpcName, asCommand, asParam, "Error: the scene is still starting")
    return
endif
```
(`the scene is still starting` is already in PROTOCOL 1.6 and already used by three other verbs, so no contract change.)

### D4 - MEDIUM. R3's remembered invitation outlives the game-side watch by 6x, so "once they are alone she makes her move" mostly will not happen on her own

*Evidence.* The server keeps an invitation for `invitation.ttl_seconds` = **1800 s** (PROTOCOL 5; `lrg_actions.php:546`) and a follow-through tick needs only a 30 s gap (`:288`). But the game only ever sends `lrg_initiative` for `watchActor`, and `SnapTick` drops the watch at `LRG_Main.psc:541`:
```papyrus
keep = fresh || (initiativeOn && dist <= 600.0 && sinceTalk < 300.0 && sinceTalk >= 0.0)
```
`sinceTalk` counts from her **last spoken line**. So five minutes after she invites you, the watch dies and she can never take the initiative again; only the player speaking to her re-opens the follow-through. And with `invitation.lead_the_way` default **false** (PROTOCOL 8; `lrg_actions.php:588`) she does not walk there with you, so the five minutes have to cover "leave the tavern hall, cross town, enter her house".
*Failure scenario.* The owner's own R3 script: innkeeper invites him to her room in public -> he finishes his drink, goes upstairs (>5 min of real time) -> arrives alone -> she says nothing and never will unless he opens his mouth first. The invitation is still pending server-side the whole time.
*Smallest correct fix (game side, LRG_Main.psc:541).* Drop the `sinceTalk` term from the initiative branch - PROTOCOL 6.3 already guarantees a tick for an NPC with nothing pending is dropped before the MAIN lock at zero LLM cost, so the only cost of keeping the watch alive is one cheap request per `fInitiativeInterval` (default 90 s):
```papyrus
keep = fresh || (initiativeOn && dist <= 600.0)
```
The watch still ends the moment she is unloaded, dead, out of 600 units, or the module is switched off. If that feels too open, bound it by a new MCM value rather than a hard-coded 300.

### D5 - LOW (rails, defence in depth). `ExtCmdLRG_SceneControl` is the one command that does not re-check the adult gate

*Evidence.* PROTOCOL 2: "The game re-checks every hard gate itself." `CmdStart` does (`StartBlockedReason` -> `PairBlockedReason`, LRG_OStim.psc:695); `CmdClothing` does (`:1123`); `CmdControl` (`:1236-1339`) never calls `LRG_Profile.IsAdult` for any verb, including the new `climax`.
*Failure scenario.* Only reachable through an ADOPTED thread (one started from OStim's own UI) whose partner is not an adult. OStim's own `OActor.VerifyActors` blocks children, and the server would refuse anyway because the forced snapshot at `OnOStimThreadStart:1666` carries `adult=0` -> mode `silent`. So this is unreachable in practice; it is reported because it is the single gate the contract names that the game does not repeat.
*Smallest correct fix.* After the partner check in `CmdControl` (i.e. after :1269, so `stop` stays exempt):
```papyrus
if partner && (!LRG_Profile.IsAdult(partner) || !LRG_Profile.IsAdult(Game.GetPlayer()))
    m.ReportResult(asNpcName, asCommand, asParam, "Error: adults only")
    return
endif
```

### D6 - LOW (R6). The game does not enforce "start gentle" when the server sends an empty `scene`

*Evidence.* `CmdStart:519-523`: with no furniture ref and `sceneId == ""`, only `OThreadBuilder.NoFurniture(builder)` is called and **`SetStartingAnimation` is skipped**, so OStim chooses the starting animation itself - any tier. `scene=""` is reachable: the server's fallback is `lrgPickStartScene($sexes)` (`lrg_actions.php:515`), which returns `''` when `lrgSceneIndex()['scenes']` is empty (`lrg_scene_index.php:738-745`), and PROTOCOL 7.1 says `lrgSceneIndex()` **never builds outside the CLI** and serves the empty index when the file is missing - exactly the state right after a deploy whose warm-up step failed.
*Failure scenario.* Warm-up fails silently on a deploy; the first scene the owner starts opens at whatever OStim picks, which may be tier 4. The rails say fail closed; this fails open.
*Smallest correct fix.* In `CmdStart`, before `OThreadBuilder.Start`, refuse when neither id resolved:
```papyrus
if !furnRef && sceneId == ""
    m.LogC(cid, "start refused: the server named no start scene (index empty?)", asNpcName)
    m.ReportResult(asNpcName, asCommand, asParam, "Error: the scene did not start")
    ResetState()
    RestoreWeapons()
    baseValid = false
    return
endif
```

### D7 - LOW (efficiency, hot-ish path). Furniture lookup does by hand what one native already does

*Evidence.* `FindFurnitureRef` (LRG_OStim.psc:358-375) calls `OFurniture.FindFurniture` and then up to 24 `OFurniture.GetFurnitureType` calls to find one type. `OFurniture.FindFurnitureOfType(string Type, ObjectReference CenterRef, float Radius, float SameFloor)` exists in the installed source (`OFurniture.psc:55`, API 7.1e) and does it in one native. `FindFurnitureRef` runs on every start with furniture and every `furniture` verb.
Separately, `ScanNearFurniture`'s loop condition `(n < 6 || nfBed == None)` (`:299`) keeps calling `GetFurnitureType` all the way to index 24 whenever no bed is in reach - not the "<= 17 `GetFurnitureType`" stated in `research/v02-game.md` section "Snapshot v=2".
*Smallest correct fix.* Use `FindFurnitureOfType` in `FindFurnitureRef` (note: it may also match subtypes, which for `SetFurniture`/`ChangeFurniture` is acceptable or better); cap `ScanNearFurniture` at `i < 18` and correct the claim in the report.

### D8 - LOW (tooling). `compile.ps1` prints OK without ever checking the compiler messages

*Evidence.* `tools/compile.ps1:79` computes `$errors` and **never uses it**. Success is decided only by "every target `.pex` exists" (`:82`). The claim "final run prints OK (6 pex, 0 errors, 0 warnings)" in `research/v02-game.md` is not something the script measures; a warning, or an error in a non-target file, is invisible.
*Smallest correct fix.* Before the copy:
```powershell
if ($errors) { "--- compiler messages (unique) ---"; $errors | Sort-Object -Unique }
if ($errors | Where-Object { $_ -match "(?i)\berror\b" }) { exit 1 }
```

### D9 - LOW (wire hygiene). `CleanForWire` gives up after 40 replacements

*Evidence.* `LRG_Main.ReplaceChar:263` loops `while i >= 0 && guard < 40` and then returns `out + rest` - the tail is returned **unreplaced**, so a value with more than 40 of one forbidden character still carries `;` / `|` / `@` / `"` onto the wire, which PROTOCOL 0 forbids. `CleanForWire` is applied before the 300-char clamp in `LogC:241-244`, so a 300-char log line with many semicolons is the realistic case; the server would then mis-split it in `lrgParseKv` (`lrg_core.php:111`). Low impact because `lrg_log`'s `msg` is last and allowed to contain `;` anyway.
*Smallest correct fix.* `guard < 300`, or clamp `asMsg` to 300 characters **before** calling `CleanForWire`.

### D10 - LOW (reporting). An aborted start is reported to the server as a success

*Evidence.* `OnOStimThreadStart:1639` reports `They draw close. The scene begins.` and only then honours `abortStart` and calls `OThread.Stop(0)` (`:1641-1644`). The server writes `last_result ok=true` for `ExtCmdLRG_StartIntimacy` (`lrg_actions.php:244-247`) for a scene that never ran.
*Smallest correct fix.* Move the `ReportResult` below the abort test and, in the aborted branch, report `Error: the scene did not start` (closed list).

### D11 - LOW (cost). The witness scan's outer loop is uncapped, and the new initiative tick makes it run more often

*Evidence.* `LRG_Profile.WitnessScan:136` - `while i < near.Length && checked < 40`. Only the *expensive* work is capped; `a.GetDistance(akPlayer)` runs for **every** actor in `GetActorsByProcessingLevel(0)`, which in a LoreRim city is routinely 60-100. That is 60-100 natives per snapshot, every 20 s per watched NPC, plus one forced extra scan per initiative tick (`MaybeInitiative:611-615` only reuses a snapshot younger than 5 s, and with a 20 s refresh and a 90 s tick that window is usually missed).
*Smallest correct fix.* `while i < near.Length && i < 80 && checked < 40`.

---

## 4. Requirements traced end to end (hunt item 5)

| req | delivered? | the one example I traced |
|---|---|---|
| **R1** menuless OStim | **mostly** | `do=goto;scene=X` -> `CmdControl:1305` -> `NavigateBlockedReason` -> `OThread.NavigateTo`. Works. But **D2** (a position can still be refused) and **D1** (`pull out` always fails) are both R1 gaps. Every other verb traced clean: `climax` -> `PermitClimax` + `OActor.Climax` per actor (`:1377-1383`); `furniture` -> `FindFurnitureRef` + `ChangeFurniture(0, ref, scene)` (`:1547`); `lead auto` -> `StartAutoMode` (`:1565`); `undress ... part=hands` -> `ScenePartMask` skips a robe that also covers slot 0x4 (`:906-924`) -> `UndressPartial`. |
| **R2** NPC-initiated romance | **yes, game side** | `SnapTick -> MaybeInitiative:579-620` checks all 11 PROTOCOL 1.5 conditions cheapest-first and sends `requestMessageForActor("approach","lrg_initiative",name)`; the server admits or drops it before the lock (`lrgInitiativeAdmit`, verified against `main.php:193/243`). |
| **R3** public -> private -> follow-through | **partial** | The public half works (`places` derives from `ltype`/`nhome`/`cellown`/`prent`, all emitted in contract order at `LRG_Main.PlaceFacts:677`). The follow-through half is time-limited by **D4**. |
| **R4/R5** direct, not poetic; no SFW blocks | server-side; game contributes `x=` correctly in both `lrg_npcstate` (`PlaceFacts:677`) and `lrg_scene` (`PushState:2009`), MCM relabelled Suggestive/Direct/Explicit with an R5 help text (`config.json:58`). Nothing in the game files sanitises. |
| **R6** progression | **yes, with D6** | The ladder is server-side; the game contributes by not calling `NoAutoMode` and instead calling `StopAutoMode` at `ostim_thread_start` (`:1650`) so OStim cannot walk the ladder itself. The one hole is an empty `scene` (D6). |
| **R7** speed | **yes, game side** | Nothing on the game side adds an LLM call; `MaybeSceneTalk` is gated by chance + a 25 s gap + `isActorTalking` and is skipped entirely during a wind-down or a transition (`PushState:2015`). Note the start still costs ~0.6-8.4 s of `Utility.Wait` inside `PrepareWeapons` - that is after the LLM turn, not under the lock, so it is latency the player feels but not CHIM lock time. |
| **R8** bridge completeness | **yes** | furniture start + `ChangeFurniture`; `climax`; wind-down as a separate verb that `stop` overrides; auto-mode interplay with a 5 s poll; `setAnimationBusy(1)` re-asserted at start / every scene change / every ~5 s / before each lead request; redress baseline; SMI cold hook on a real native; SDE read via `SDE_R001..R005`. All verified against installed sources. |
| **R9** offline flow tests | **server only** | 23 scenarios pass, but they exercise the server's *emitter*; nothing exercises the game's *parser*. I closed that gap myself for this review (`ParamGet` parity, 57/57) - it should become a checked-in test, see forIntegrator. |
| **R10** speak the choice | server-side; the game's contribution is correct: after any `CmdControl`/`CmdClothing` it sets `lastTalkTime = now` (`:1270`, `:1161`) so a silent pick is not followed by an unprompted scene-talk line. |

---

## 5. Things I looked hard at and found NOT to be defects

- `ParamGet` (LRG_Main.psc:407): the `";" + asParams` trick correctly prevents `;fscene=` matching `;scene=`, and the `stop == start` / `start >= len` pair correctly distinguishes "empty value" from Papyrus's "len 0 = to the end". 57/57 in my port.
- `RequestTick` / `OnUpdate` / `SnapTick` interplay: no double registration, no starvation, no lost tick; `Utility.Wait` inside `FinishThread` runs on the caller's stack and every post-wait action is re-guarded by `if !starting && !OThread.IsRunning(0)` (`:1784`, `:1789`).
- Array safety: `EnsureArrays` is called before every indexed write; `RestoreWeapons`, `RedressRemembered`, `RedressBaseline` and `HoldsPlayerClothes` all guard `.Length`; the 5-slot -> 8-slot upgrade path re-dresses first. No `None` dereference found - every `GetEditorLocation`, `GetParentCell`, `GetCurrentLocation`, `GetLeveledActorBase`, `akStore[i]` and `refs[i]` result is tested before use.
- Save/load: real-time stamps are all reset in `Maintenance` (both scripts), weapons and glue-removed clothes are restored on load when no thread is running, and `baseValid` is dropped unless the same glue thread continues.
- `lrg_scene` de-duplication cannot swallow a second `ev=start` (it is preceded by `ResetState`, which clears `lastSentKey`) nor a second climax (`ncl`/`pcl` are in the key).
- Migrations `001`/`002` are idempotent and `lrgEnsureSchema` runs them in name order; nothing re-run-unsafe.
- No explicit example dialogue anywhere in the game-side files, MCM strings or `research/v02-game.md`.

---

## 6. Verdict

**SHIP AFTER FIXES.** The game half is in good shape structurally: it compiles clean, every one of the 94 external calls matches the installed declaring source, the wire parser is provably correct against every shape the server emits, the rails that matter (adults-only on start and clothing, willingness via the server gate, `stop` first and unconditional, kill switch, player always a participant) hold from every state I could construct, and I could not build a `None` dereference, an uninitialised array, a lost update registration or a state leak across load/crash/timeout. What stops it from being a plain SHIP is that three of the owner's headline requirements are not delivered as the report claims: `pull out` is offered to the model on every sexual turn and is physically incapable of succeeding on this install, so the owner will meet it as an error notification plus a wasted NPC line (D1); "if I say a position it should go to that position" still has a live refusal path because the server's 3-hop ceiling-filtered walk and OStim's 5-hop actor-filtered oracle are not nested (D2); and the invitation the server remembers for thirty minutes can only be acted on by the NPC for five (D4), which is precisely the R3 scenario the owner wrote out. Add the silent wrong-direction "faster" at scene start (D3) and these four are a short, cheap, well-localised fix list - D2, D3, D4 and D6 are four small edits in two Papyrus files, D1 is one deleted line on the server. None of them requires a redesign, and none of them is a crash, which is why this is not REWORK.
