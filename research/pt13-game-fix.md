# pt13 - GAME lane: the array-None errors, the false abort detector, and "follow me" for a bard on her stage (script 506)

LoreRim Glue, GAME lane. Written 2026-09-23 after playtest 13 (23 Sep 01:31-01:45, script 505).
`LRG_Main.CurrentVersion` **505 -> 506**. **NOT installed**: nothing under
`F:\Modlists\LoreRim\mods\LoreRim Glue` and no profile file was written. MO2 (pid 41132) was running;
SkyrimSE was not running at any point. `tools\compile.ps1` prints `OK`.

---

## 0. The short version

1. **The 246 error blocks were not natives returning None.** They came from the glue's own
   `arr == None` / `arr != None` tests. The Papyrus compiler turns those into
   `cast <array-typed temp> None` and the game's VM refuses to cast None to any array type. The same
   error fired on calls that did return data. The stacks were not aborted either: every
   `facts npc=... q=aaLisette,aaLisetteIdle` line was written by the same call, right after its three
   errors. One real consequence: `QobjCsv` lost the objectives of every quest after the first. All six
   such sites are fixed (`!arr` / `if arr`), and a scan of all ten `.pex` files finds none left.
2. **The abort detector was reporting slow steps as aborted.** Papyrus lets other stacks into a script
   whenever it calls out (a native, another script). Playtest 13's stack dumps show **four snapshot
   attempts in flight at the same moment** (01:35:47, all inside `WitnessScan`, stage 3). The 0.5.4
   boot queue kept one "last finished" number for all steps, and steps overlap. Also, a Papyrus error
   does not unwind the stack: the call fails and the function goes on. Rebuilt with per-attempt
   tokens and per-step state. Nothing is called lost until it has been silent for 60 s (boot step) or
   45 s (snapshot). A late return takes the verdict back. `fol=` / `door=` are retired only after
   three lost attempts **in a row** with that block open.
3. **"Follow me" for Lisette**: a running engine scene outranks CHIM's priority-100 follow package.
   New command **`ExtCmdLRG_Escort` (do=follow | wait | release)** in `LRG_Main.HandleCommand` ends a
   *safe* scene the non-destructive way. For bards that is `BardSongsScript.StopAllSongs()`, verified
   in USSEP's shipped script. For anything else it is `Scene.Stop()`. It adds no do-nothing package.
   Then it puts on CHIM's own follow package if CHIM's is not already on. It never acts on a quest
   with an open journal objective, a main, faction or courier quest, a recruited follower, or a
   hostile, fighting or arresting NPC. The wire matches the server lane's flow `28_escort.php` exactly.

---

## 1. `LRG_Dialogue`: the None-to-array casts

### 1.1 What the log says

`Papyrus.0.log` (73,698 lines, 01:31-01:44). Every error on `LRG_MainQuest (FECF6802)`:

| error | count | stack (log line) |
|---|---|---|
| Cannot cast from None to Quest[] | 42 | `QuestCsv` 3638, `QobjCsv` 3668 |
| Mismatched types assigning to variable named "::temp577" | 25 | `QobjCsv` 3682 |
| Cannot cast from None to Alias[] | 21 | `QalCsv` 3398 |
| Cannot cast from None to Int[] | 18 | `QobjCsv` 3682 |
| Cannot cast from None to Actor[] | 17 | `VkSweep` 3581 |

There were no other errors on the new quest. The only other `LRG_*` frames in the file are
**stack dumps** ("Suspended stack count is over our warning threshold"), not errors (section 2.1).
Separately, the frozen pair from the old form still appears in every dump as `<nullptr form> (FECF6800)`,
as pt12 predicted: it is unbound and harmless.

### 1.2 Which native returned None? None of them

The compiled bytecode of 505 shows where the cast really is (own `.pex` reader, `vpex.py`):

```
QuestCsv   0 L3638 cast ::temp559 akNpc
           1 L3638 callstatic po3_sksefunctions GetActiveAssociatedQuests ::temp560 ::temp559 True
           2 L3638 assign qs ::temp560
           3 L3639 cast ::temp560 None        <- "if qs == None": None cast INTO a Quest[] register
           4 L3639 cmp_eq ::temp561 qs ::temp560
```

The native writes straight into its typed register with no cast. The only cast in those lines is
the compiler's `cast <quest[] temp> None` for the None comparison. The log reports the line before
it: all five reported lines are exactly one less than the line of that cast (3581/3582,
3638/3639, 3668/3669, 3682/3683, 3398/3399). Three facts prove the natives were not the cause:

* **The error fires when the native returned data.** Lisette `q=aaLisette,aaLisetteIdle`,
  Corpulus `q=WITavern,BQ01,DialogueSolitude` and Katana's five quests all logged the Quest[] error
  in the same call that produced that list.
* **"Mismatched types" = quests - 1, in every call.** Lisette with 4 quests: 3. Katana with 5: 4.
  Corpulus with 3: 2. Single-quest NPCs: 0. Quest-less NPCs (Makar, Licinia, Marnest): no Int[]
  error at all, because the loop never reached the objectives native. The failed cast leaves
  `::temp577` holding an untyped None, so every later `GetAllQuestObjectives` result bounces off it.
* **The stack was not aborted.** `SendFacts` went on after `QuestCsv`'s error to `QobjCsv` (next line)
  and `QalCsv`, and wrote its `facts ...` line. A Papyrus error aborts one call and the function
  continues. The same log shows it for another mod: `HD1GoreFiller.OnPlayerLoadGame` errors on line
  379 and again on line 380.

**Real damage:** `qobj=` only ever carried the first quest's objectives. `q=`, the voice keeper and
`qal=` still worked: the comparison came out "not equal" and the loops ran. `qal=-` on every NPC is
therefore either a data result or something else. 506 logs `al=<aliases po3 reported>` on the facts
line so the next playtest can tell which.

### 1.3 The fix and the sweep

Compiled `if !arr` gives `not tmp arr` and `if arr` gives `jmpf arr`: the VM's own truth test of an
array (false for None and for an empty array), which cannot fail. This was checked on a test compile
with the same compiler. Changed sites:

| file | function | was | now |
|---|---|---|---|
| LRG_Dialogue | `VkSweep` (findAllNearbyAgents) | `near == None` | `!near` |
| LRG_Dialogue | `QuestCsv` (GetActiveAssociatedQuests) | `qs == None` | `!qs` |
| LRG_Dialogue | `QobjCsv` (quests) | `qs == None` | `!qs` |
| LRG_Dialogue | `QobjCsv` (GetAllQuestObjectives) | `objs != None` | `if objs` |
| LRG_Dialogue | `QalCsv` (GetRefAliases) | `al == None` | `!al` (+ `qalRaw`) |
| LRG_Dialogue | `HasActiveJournalQuest` | `qs == None` | `!qs` (did not run in pt13) |

**The whole script set, checked in bytecode rather than by grep.** I listed every call in all ten
`.pex` whose result register is an array (29 sites), and every `cast/assign/cmp_eq` where a None
literal meets an array-typed operand. Before: exactly the six above. After: **0**.
(`as Actor[]` casts do not exist in the sources: Papyrus cannot cast between array types.) The other
array natives never compare with None and ran without errors in pt13 (WitnessScan, DoorState, the
OStim thread arrays). The three snapshot-path ones still got a neutral guard
(`n = 0; if arr; n = arr.Length`, same output): `LRG_Profile.FactionList`, `DoorState`,
`WitnessScan`, and `LRG_Main.FollowerSweep` (`if !near return`). `LRG_OStim` is untouched.

---

## 2. The abort detector (boot rail and snapshot rail)

### 2.1 Why every pt13 "ABORTED" line was false

* **No error anywhere in `LRG_Main`, `LRG_Profile` or `LRG_Followers`**, as shown above. Yet the log
  had `BOOT STEP ABORTED: 1, 2, 8, 9` and six `SNAPSHOT ABORTED` lines. The one at 01:33:08 retired
  `fol= / witchim` for the session.
* **Papyrus is not one stack per script.** When a function calls out of its script (any native on an
  actor, `LRG_Profile.BuildSnapshot`, `LRG_OStim`, CHIM, MCM Helper), other stacks may enter (CK
  wiki "Threading Notes"). The stack dumps of pt13 show it directly. At 01:35:47 four stacks
  (90781, 90783, 90808, 90819), started by `OnChimTextReceived` x2, `OnCrosshairRefChange` and
  `OnChimSpeechStarted`, are all inside `MaybeSnapshot` line 2527 -> `BuildSnapshot` 492 ->
  `WitnessScan`. That is **stage 3 with the fol breadcrumb up**: four attempts in flight at once.
  Dumps at 01:33:43 and 01:34:45 show the same (`BuildSnapshot` -> `IsAdult`).
* **Snapshot rail (0.5.2-0.5.5):** one flag and one stamp for all attempts. A second event that
  arrived while the first waited in a native found `snapBusy` set and `snapWhere > 0` and declared an
  abort. It retired the optional block that the first attempt had open, cleared the flag and went
  ahead, so more attempts overlapped. That produced the pt13 lines: stage 23 (in `IsActorInOurScene`),
  stage 3 (the fol breadcrumb, so fol= retired on the first suspicion), stage 1 x3, then the
  sixth-line cap.
* **Boot rail (0.5.4/0.5.5):** the next tick is armed 0.25 s after a step begins, so a slow step
  overlaps the next one. `bootDone` was ONE number for all steps:
  * step 2 found step 1 (`GetDisplayName` while the VM was crowded right after the load) still out and
    said "ABORTED 1";
  * step 3 said the same of RegisterKeys (four MCM Helper reads);
  * step 9 said it of the load-time snapshot (step 8);
  * step 8 then finished after step 9 and wrote `bootDone = 8` over it, so step 10, 22 s later, said
    "ABORTED 9".

  A small model of the queue (`%TEMP%\lrg_pt13\rail_sim.py`) reproduces that shape with the old logic.

### 2.2 The 0.5.6 design

**Boot rail** (`LRG_Main` 453-712):

* `BootTick` makes every call it needs first (the clock, `ModPending`). Then it reads, tests and
  advances `bootStep` with no call in between (checked in the bytecode), so no step can run twice.
  Step 8's "wait for LRG_OStim" and step 10's "wait for its time" no longer claim the step; they only
  re-arm the tick.
* Per-step state `bootSt[1..11]` (0 not begun, 1 running, 2 returned, 3 judged lost) and
  `bootBegan[]`, plus a boot generation `bootGen` that each `Maintenance()` bumps *first*. A step from
  an earlier boot never marks this one, and one step's late return can no longer overwrite another's.
* `BootJudge` (every boot tick and every heartbeat, one compare while nothing runs) is the only place
  a step is called lost: **running for >= 60 s** (`BOOT_LOST_SECS`).
  Line: `BOOT STEP LOST: <n> (<what it calls>) began N s ago and has not returned - it is stuck in that call`.
* A lost step that returns later logs `BOOT STEP LATE: <n> ... returned after N s - it was slow, not
  lost`, and the verdict is undone.
* Step 9 (FollowerSweep) **no longer borrows the snapshot rail**. A lost step 9 counts one toward
  retiring fol=; a clean one resets that count.
* Step names now say what runs, for example
  `FollowerSweep (po3 GetActorsByProcessingLevel + LRG_Followers.IsGhost per actor)` and
  `RegisterKeys (four MCM Helper reads, then RegisterForKey)`.
* The step-10 line now ends
  `still running <steps>, lost N, modules ..., stalled N, snapshots in flight skipped N, lost N`.

**Snapshot rail** (`LRG_Main` 3022-3320, `LRG_Profile.BuildSnapshot`):

* Each attempt takes the rail with its own token (`snapTok`, `snapRunTok`, `snapRunAt`). The test and
  the token write are one uninterrupted stretch (bytecode checked: no call between them).
* Every stamp and breadcrumb (`SnapStage`, `SnapDoorTry`, `SnapFolTry`) carries the token and is
  ignored unless its attempt holds the rail. `BuildSnapshot` got a trailing `int aiTok = 0` and all
  15 of its rail calls pass it.
* A non-forced event that finds the rail held **skips**, and `snapSkips` counts it: that is not an
  error. A forced snapshot waits up to 0.5 s and then goes ahead with its own token.
  `BeginConvHold` passes the new `abNoWait = true`, so no wait ever runs inside a CHIM speech event.
* **Lost** = the holder has been silent for **45 s** (`SNAP_LOST_SECS`). The next attempt frees the
  rail and logs
  `SNAPSHOT LOST: an attempt began N s ago in <stage words> (stage n) and has not returned - <what>`.
  If that attempt ever returns, `SNAPSHOT LATE: ... it was slow, not dead` rolls its counts back and
  can switch the block back on.
* **Retirement:** `door=` and `fol= / witchim` go off only when that block was open in **3 lost
  attempts in a row** (`SNAP_RETIRE_AFTER`). One clean pass through the block resets the count.
  A lost attempt with no optional block open is reported as the core path, as before.
* Stage names now name the calls, for example `the witness scan (po3 GetActorsByProcessingLevel,
  LRG_Followers.FacCurrentFollower)`.

**Why no in-flight attempt can be read as aborted:** a verdict needs the attempt's own state still
"running" 45 s / 60 s after its own start. Completion is recorded under the attempt's own token or
step number (and boot generation), so nobody else's completion can overwrite it. The claims are
atomic (bytecode). Model check: the playtest-13 durations produce 6 false reports with the old
logic and 0 with the new. 2,000 random duration sets with every step under 60 s: 0 reports. A step
that never returns: `LOST` at the next heartbeat after 60 s, then `LATE` if it ever comes back.
**Protection kept:** a genuinely stuck attempt (like pt12's frozen pair) is still found, reported
with its stage, and no longer blocks the rail forever. A block that keeps getting stuck is still
switched off.

---

## 3. "Follow me" for an NPC her own scene holds: `ExtCmdLRG_Escort`

### 3.1 Wire (server -> game), as the server lane emits it (`flows/scenarios/28_escort.php`)

```
<npc>|command|ExtCmdLRG_Escort@ok=1;cid=<turn cid>;npc=<name>;do=follow|wait|release;safe=<patterns>
```

`safe=` is the server's `escort.safe_quests` (default `BardSongs,BardSongsInstrumental,*Idle*,*Sandbox*,WI*`).
It is optional: if absent, the game uses the same built-in default (`ESCORT_SAFE_DEFAULT`). Patterns
use the server's syntax, `*` for any run and `?` for one character, case-insensitive. A pattern with
fewer than two literal characters matches nothing (`*` can never make every scene fair game). The
game works when the command arrives alone, and when it arrives before or after CHIM's own line (the
server puts it in front).

**funcret:** success is a plain sentence: `Lisette stops what she was doing and comes with you.`,
`Lisette comes with you.`, `Lisette waits here.`, `Lisette goes back to her own business.` A refusal
is `Error: ...`, which is also the corner note through `ReportResult` / `bNotifyErrors`:
`Error: Lisette is in the middle of something she cannot leave (a quest in your journal)`, or
`Error: Lisette will not do that now (hostile|combat|an arrest|she is your follower - her own follow and wait apply|a scene with you)`.

### 3.2 do=follow (`CmdEscort` 1451, `EscortFollow` 1524)

1. Guards (`EscortRefuseReason`): not the player, alive/enabled/3D-loaded, **not a teammate and not
   SFF** (`LRG_Followers.IsSff`), not hostile (`IsHostileToActor`, `GetCombatState`), no combat on
   either side, no arrest (`ConvIsArrest`, reused), not in the glue's own OStim scene. Dry run: logs
   and answers "nothing was changed".
2. If `GetCurrentScene()` is set, `EscortSceneVerdict(owningQuest)` runs these checks in order:
   * no quest or no EditorID: refuse;
   * **deny list first**: `MQ*,DLC1MQ*,DLC2MQ*,DLC1VQ*,CW*,DB*,TG*,MG*,C0*,DA*,*Courier*,*ForceGreet*,*Arrest*,Windhelm*,Winterhold*`
     (game-side, no safe= list can get past it);
   * **open journal objective** (po3 `GetAllQuestObjectives` + displayed, not completed, not failed): refuse;
   * **safe list**: the quest must match it.

   `bEscortStopScene:Followers` (new MCM toggle, default on) can turn the whole scene stop off.
3. `EscortStopScene`, the non-destructive stop:
   * bard quests (`BardSongs*`): `(owner as BardSongsScript).StopAllSongs()`. If the owning quest does
     not carry the script (e.g. `BardSongsInstrumental`), it falls back to `Game.GetForm(0x00074A55)`
     after checking its `GetID() == "BardSongs"` (the quest CHIM stops), and sends
     `IdleForceDefaultState` if she is not seated, as CHIM does;
   * then `Scene.Stop()` if the scene is still playing;
   * **no do-nothing package, ever.**
4. Frees a conversation hold on her. Takes back a `WaitingForPlayer` the glue wrote.
5. The follow: if `CHIM_FollowPlayerActive != 1`, it calls `LRG_Followers.ChimFollowPlayer` (CHIM's
   forms, CHIM's recipe: FollowFaction 0x1BC24 rank 1, `AddPackageOverride(FollowPlayerPackage
   0x2226D, 100, 0)`, `CHIM_FollowPlayerActive=1`, `EvaluatePackage`) and records `LRG_EscortApplied`
   (plus `LRG_EscortFaction` if the glue added the faction). Otherwise it only calls
   `EvaluatePackage()`.
6. Log: `escort Lisette: stopped scene BardSongsInstrumental (BardSongsScript.StopAllSongs + Scene.Stop) and following - CHIM's follow was already on`.
7. **Follow-up** (`TickEscort` 1868, on the quest's own tick): at +2.5 s and again 6 s later.
   * If the scene has started again and is still safe, it is ended again, at most twice.
   * If it keeps coming back, or the new scene is not safe, she is left in it:
     `escort X: she went back to her scene (Q) and stays - ...` plus the corner note
     *"X is in the middle of something she cannot leave"*.
   * Free twice in a row: `escort X: free of her scene - following`.

### 3.3 do=wait / do=release

* **wait** (1584): same guards; never on a teammate or SFF follower. The old `WaitingForPlayer` is
  stored (`LRG_EscortWaitPrev`), then `SetActorValue("WaitingForPlayer", 1)`. If the glue itself put
  a follow on, it is taken off, or she would follow while "waiting". Then `EvaluatePackage`.
* **release** (1607) only undoes the glue's own edits, so it needs no guards: the follow package plus
  `CHIM_FollowPlayerActive=0` if `LRG_EscortApplied`, the follow faction if the glue added it, and
  the old `WaitingForPlayer`. Then `EvaluatePackage`. A follow CHIM put on by itself is left alone:
  it is CHIM's to end.
* **Self-heal:** `NoteFollower` (snapshot path, at most every 20 s per NPC) takes back both glue edits
  once she becomes the player's teammate, because her framework owns follow and wait from then on.

### 3.4 Verified, not assumed

* `BardSongsScript` on this install is **USSEP's**. `Unofficial Skyrim Special Edition Patch.bsa`
  holds both `scripts\bardsongsscript.pex` and `scripts\source\bardsongsscript.psc`. No loose copy
  exists in any mod folder, and no other BSA of an enabled Ultra mod has one (read-only BSA scan).
* From the shipped `.pex`: `BardSongsScript extends Quest`, `StopAllSongs()` takes no parameters and
  returns nothing. It sets `StopSong`, stops all 20 song scenes and sets `Playing = 0`.
  `StopSong = True` is what keeps the song's end fragment (`PlaySong(ChangeSettings=False)`) from
  starting the next song. A bare `Scene.Stop()` would not stop it.
* The compile had no declarations for either type. Vanilla `Scene` was an auto-stub, empty. Two
  compile-only stubs were added in the existing `tools\stubs` pattern:
  * `tools\stubs\Scene.psc`: `GetOwningQuest`, `IsPlaying`, `Stop`, all native;
  * `tools\stubs\BardSongsScript.psc`: `StopAllSongs()`.

  Both were added to the deploy deny list in `tools\compile.ps1`, so a `.pex` of either can never ship.
* Bytecode of the escort code: the only package write is `ActorUtil.RemovePackageOverride`
  (release/wait of the glue's own follow). The only add is inside `LRG_Followers.ChimFollowPlayer`.
  The form ids are 0x2226D, 0x1BC24 and 0x74A55 as intended.
* The matcher was ported line by line to Python with Papyrus `Substring` and `==` semantics:
  40,000 random glob cases against `fnmatch` gave 0 mismatches. `WITavern`, `aaLisetteIdle`,
  `BardSongs*`, `WIChangeLocation01` are safe. `WICourier`, `MQ101`, `DLC1VQ01`, `DB02Idle`, `TGIdleScene`
  and `WindhelmSceneX` are denied. `DialogueSolitude`, `BQ01` and `AK69KatanaFollowQuest` are not listed.

---

## 4. Files changed

| file | change |
|---|---|
| `game\LoreRimGlue\Source\Scripts\LRG_Main.psc` | 506; boot rail (per-step state, claim, generation, judge/late); snapshot rail (tokens, skip, lost/late, 3-in-a-row); step and stage names; FollowerSweep off the rail + array guard; `ExtCmdLRG_Escort` (CmdEscort + 13 helpers + TickEscort); `MaybeSnapshot(..., abNoWait)`; escort self-heal in `NoteFollower` |
| `game\LoreRimGlue\Source\Scripts\LRG_Dialogue.psc` | the six None tests -> `!arr` / `if arr`; `qalRaw` and `al=` in the facts log line |
| `game\LoreRimGlue\Source\Scripts\LRG_Profile.psc` | `BuildSnapshot(..., int aiTok = 0)` passes the token on all 15 rail calls; neutral array guards in FactionList / DoorState / WitnessScan |
| `game\LoreRimGlue\MCM\Config\LoreRimGlue\config.json` | Followers page: `bEscortStopScene:Followers` toggle (BOM and CRLF preserved; parses as JSON) |
| `game\LoreRimGlue\MCM\Config\LoreRimGlue\settings.ini` | `[Followers] bEscortStopScene = 1`; `iSettingsVersion = 506` (canary, only tested > 0) |
| `game\LoreRimGlue\Scripts\*.pex` | all ten recompiled |
| `tools\stubs\Scene.psc`, `tools\stubs\BardSongsScript.psc` | **new**, compile-only (outside the nominal lane; needed to compile the escort) |
| `tools\compile.ps1` | deny list + `"Scene", "BardSongsScript"` (one line, outside the nominal lane) |

Unchanged: `LRG.psc`, `LRG_DlgProbe`, `LRG_DlgUI`, `LRG_Followers`, `LRG_MCM`, `LRG_OStim`, `LRG_PlayerAlias`,
`LoreRimGlue.esp` (`82a20db39da4dbf4`), `Seq\LoreRimGlue.seq` (`a881f50a2b2b30a3`), `make_esp.py`, the server.
The 505 originals were backed up to `%TEMP%\lrg_pt13\backup505\` first.

## 5. Verification

* `tools\compile.ps1`: `compiled: [all 10] ... OK - ... 0 errors, 0 warnings`, 21 auto-stubs (Scene
  is no longer one).
* Bytecode (`%TEMP%\lrg_pt13\verify506.py`):
  * None-to-array sites in all 10 `.pex`: **0** (6 before);
  * `CurrentVersion` 506;
  * `HandleCommand` compares `"ExtCmdLRG_Escort"` and calls `CmdEscort`;
  * every `MaybeSnapshot` / `BuildSnapshot` rail call carries the token;
  * the removed members (`snapBusy`, `snapBusyTime`, `snapAborts`, `bootWhere`, `bootDone`, `bootAborts`) are gone;
  * the longest docstring is 290 characters (pre-existing, `LRG_Dialogue.ServiceMenuOpen`), so no new one comes near 300.
* Offline suite on a staged copy (`%TEMP%\lrg_test\game13`, WSL; it includes the server lane's current
  work). The built index files, which are not in the repo, were copied from the earlier stage:
  * test_gates 339/0
  * test_intent 255/0
  * test_phrases 31/0
  * test_scene_index ALL PASSED
  * test_dialogue 174/0
  * test_prompt_index 67/0
  * test_mcm_wiring 3/0
  * test_services 35/0
  * test_latency 26/0
  * `flows\run_flows.php --strict`: 76 scenarios, 0 failed, 0 warnings, including the server lane's `28_escort`.

## 6. For the installer and the next playtest

**Install (not done here):** copy by hand into `F:\Modlists\LoreRim\mods\LoreRim Glue` and verify
with SHA-256:
* `Scripts\LRG*.pex` (**all ten together**: `MaybeSnapshot` and `BuildSnapshot` gained a parameter,
  and a mixed 505/506 set would fail on the call);
* `Source\Scripts\LRG*.psc`;
* `MCM\Config\LoreRimGlue\config.json` and `settings.ini`.

The ESP and SEQ did not change. STOP if SkyrimSE runs. No profile file is involved.

First 16 hex characters of SHA-256 (the installer must hash the full files):

| file | SHA-256 (first 16 hex) |
|---|---|
| `LRG_Main.pex` | `7f6082a31ace5f2a` |
| `LRG_Dialogue.pex` | `12d6ed17fcdb45d4` |
| `LRG_Profile.pex` | `55ca07e25c5c2092` |
| `LRG_OStim.pex` | `625e39deeee45e12` |
| `LRG.pex` | `57b70e00fa855562` |
| `config.json` | `f3d03d4e30fd4c61` |
| `settings.ini` | `330718aa09ba0e78` |

**What to look for in the next Papyrus.0.log:**

* no `Cannot cast from None` and no `Mismatched types` on FECF6802;
* facts lines with `al=<n>`;
* no `BOOT STEP ABORTED` / `SNAPSHOT ABORTED` (those strings no longer exist); `LOST` or `LATE` lines only for something genuinely stuck;
* step-10 line: `still running none, lost 0, ... snapshots in flight skipped N, lost 0`.

On "follow me" at a performing bard, expect:

```
[LRG] cid=... command ExtCmdLRG_Escort from Lisette ... do=follow;safe=...
[LRG] cid=... escort Lisette: stopped scene <Quest> (BardSongsScript.StopAllSongs + Scene.Stop) and following - ...
[LRG] cid=... escort Lisette: free of her scene - following
```

If her idle quest keeps restarting the scene, you will instead see `... she went back to her scene
(...) and stays - it keeps starting again` plus the corner note.

## 7. Open points

* **`WI*` is a case-insensitive prefix.** Papyrus has no case-sensitive string compare (SKSE's
  `AsOrd` upper-cases), so `WI*` would also match `Wi...` quests. `Windhelm*` and `Winterhold*` are on
  the deny list for that reason, and the journal test covers the rest.
* **Unknown until played:** which quest owns Lisette's stage scene (`BardSongs`,
  `BardSongsInstrumental` or `aaLisetteIdle`), and whether FDE's `aaLisetteIdle` restarts it. The log
  line names the quest, and the follow-up tick handles one or two restarts.
* `glue\README.md`'s version marker still says 505 (outside this lane).
* The player phrases that logged `intent=none` are the server lane's
  (`lrgIntentEscort`, already in its flow 28).
