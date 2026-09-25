# pt10 - why no snapshot left the game, and the rail that stops it happening again

LoreRim Glue, game side. Written 2026-09-22 after playtest 10 (17:14-17:47 local).
Fix shipped as `LRG_Main.CurrentVersion` **501 -> 502**. **Not installed** - MO2 untouched, no
profile file touched. `tools\compile.ps1` prints `OK - ... 0 errors, 0 warnings`.

---

## 0. The short version

The NPC ignored every command because **the server never had a snapshot**, and it never had one
because **`LRG_Profile.BuildSnapshot()` was being killed by a Papyrus runtime error on every single
call**. Papyrus has no try/catch: an error unwinds the *whole stack*, so the send never happened
**and** everything the CHIM event was going to do after the snapshot - the watch, the conversation
hold, the latency mark, the OStim pacing - was lost with it. That is one defect producing all four
of the owner's complaints at once.

With no snapshot the server's gate returns `no_fresh_snapshot`, `lrgGateMode()` turns that into
`mode=silent`, and silent hides `BeginIntimacy` / `Clothing` / `SuggestPrivacy` **and skips the
intent recogniser altogether**. So "kiss me", "come hug me", "take your clothes off" could not be
recognised, could not be acted on, and the model was left with nothing but words - exactly what the
owner saw ("the ai seemed very very interested in pursuing that" but nothing happened).

---

## 1. The evidence, in the order it was established

### 1.1 The game really did send nothing

`/var/log/apache2/other_vhosts_access.log`, counted by the base64 prefix of each message type:

| day | total requests | `lrg_npcstate` | `lrg_log` | `lrg_scene` | `lrg_dlg` |
|---|---|---|---|---|---|
| 21 Sep | 25948 | **394** | 266 | 94 | 0 |
| 22 Sep | 4559 | **0** | 15 | 0 | 1 |

All 16 glue messages of 22 Sep are timestamped **17:16:25 - 17:17:47**, i.e. the load sequence.
Nothing between 17:17:47 and the crash at 17:47:30 - and that is 30 minutes with an NPC in front of
the player the whole time.

### 1.2 The premise in the brief is wrong: 0.4, 0.4.1 and 0.5.0 never ran in game either

`maintenance done, version N` in `lorerim_glue.log` (the only honest record of which `.pex` ran):

```
21 Sep 17:03 - 19:33   version 200 / 300
21 Sep 21:47 - 22:00   version 310        <- the last session that ever produced a snapshot
22 Sep 17:17           version 501
```

There is no 400, no 401, no 500. `version 310` is **v0.3.1** (`PLAYTEST7_NOTES.md:3`). So the
untested delta is not "v0.5.1 on top of a working v0.4.1" - it is **310 -> 501**, four releases at
once. The last known-good snapshot on the wire confirms it (yesterday, 22:00, session 9760):

```
v=2;npc=Lisette;...;interior=1;wit=0;witfol=0;witkid=0;plvl=1;...;sess=9760;class=Bard;fac=...
```

no `door=`, no `witchim=`, no `paidok=`, no `dist=`, no `hold=`, no `qi=`, no `fol=`. That payload
is 1003 bytes including the `lrg_npcstate|ts|gamets|` header, so **size is not the problem** (the
0.5.1 payload is ~190 bytes longer; Apache's own limit is 8190 and a too-long GET would have been
logged as a 414 - there is no request at all).

### 1.3 It is an abort, not a quiet early return - proved from the save file

`Save15_..._SolitudeWinkingSkeever_..._20260922213815_1_1.ess` (17:38, mid-playtest) was
decompressed (LZ4, 22890675 bytes, exact match on the declared length) and its Papyrus string table
read. `LRG_Main`'s string members are there with values:

```
lcFacts = "loc=;ltype=wild"
pcFacts = "cellown=none;home=0;nhome="
pcSde   = "sde=-1"
```

`LRG_Main.PlaceFacts()` is called from **exactly one place**: as the last argument of the
`LRG_Profile.BuildSnapshot(...)` call inside `MaybeSnapshot`. Papyrus evaluates arguments before it
enters the callee, so:

* `PlaceFacts` ran to completion -> `MaybeSnapshot` got past `IsEnabled()`, past `IsDead()`, past
  the 1500-unit test and past `AIAgentFunctions.getAgentByName()` (so CHIM *did* know the NPC -
  `AIAgent.log` confirms `Auto-adding Lisette` and `Total AI Agents 17`);
* `BuildSnapshot` was therefore entered;
* nothing was ever sent.

And the stack really was unwound rather than returning: `MaybeSnapshot` only has one exit after
that point, and both `OnCrosshairRefChange` and `OnChimSpeechStarted` call `Watch()` on the next
line **unconditionally**. `Watch()` sets `watchActor`, `watchActor` drives `SnapTick`, and
`SnapTick` is what logs `initiative tick`. Yesterday: **19** `initiative tick` lines. Today: **0**.
Same for `conv hold ... on reason=a line` (0), and for the `LAT npc=` line (0). Everything below
the `MaybeSnapshot` call in those three event handlers simply did not happen.

`loc=` and `nhome=` being *empty* dates the one surviving `PlaceFacts` run to a load (no current
location yet) - and it is byte-identical in Save14 (17:20) and Save15 (17:38), i.e. it was never
refreshed again in either session although the player changed cell and NPC. That is the same
statement from the other side: after the first abort, no call ever got that far again.

### 1.4 What is left after elimination

Everything that was on the snapshot path at version 310 is exonerated by the 394 snapshots of
21 Sep. Of what 0.4 / 0.4.1 / 0.5 / 0.5.1 added, only three things leave the glue's own scripts:

| added | what it touches | verdict |
|---|---|---|
| `door=` (0.4) | `LRG_OStim.DoorFacts` -> `LRG_Profile.DoorState` -> po3 `FindAllReferencesOfFormType` + `GetOpenState` | **eliminated**: `DoorState` returns at `!c.IsInterior()` before any po3 call, and session 1 played outdoors in Solitude (Save14 = `SolitudeWorld`) with just as many snapshots: zero |
| `witchim` (0.5.1) | `LRG_Followers.FacCurrentFollower()` = `Game.GetForm(0x0005C84E)`, then `GetFactionRank` | cannot fail - both are vanilla and None-guarded |
| `fol=` (0.5.1) | `LRG_Followers.FolState()` -> po3 `GetFormFromEditorID`, **`SFF_SKSE.IsVanillaFollower()`**, **`SFF_SKSE.GetMaxFollowers()`**, `GlobalVariable.GetValue`, and (followers only) `DialogueFollowerScript.SFF_IsPrimaryFollower()` | **the only candidate left** |

The rest of the 0.4/0.5 additions are `MCM.GetModSetting*` reads and `GetDistance` - the same calls
`Log()` itself makes, and `Log()` worked.

And `fol=` is new in a much stronger sense than "shipped in 0.5.1": **it had never executed at all
until the build the owner played.** `SffPresent()` probes the EditorID `SFF_CanRecruitMore`; every
0.5.1 build before the 09:25 go-live pass spelled it `SFFCanRecruitMore` (the VMAD *property* name,
which resolves to nothing), so the whole SFF half of `LRG_Followers` was dead code. The go-live fix
corrected the spelling - `Simple Follower Framework.esp` really does carry `SFF_CanRecruitMore`
(GLOB 05000001), verified in the plugin - and that is the moment those two SKSE natives started
being called on **every snapshot for every NPC**.

Checks that did **not** find a fault, and are worth recording so nobody re-runs them:

* `SFF_SKSE.pex` signatures read out of the shipped file: `Bool IsVanillaFollower(Actor a)`,
  `Int GetMaxFollowers()` - identical to the stub `compile.ps1` generates.
* `dialoguefollowerscript.pex` really does export `SFF_IsPrimaryFollower(Actor)`, `SetFollower`,
  `IsManagedFollower`, `SFF_GetTrackedFollowerCount`, `SFF_GetMaxFollowersSafe` - the hand-written
  header in `tools\stubs\` matches all five. (SFF's *own* `Source\Scripts\DialogueFollowerScript.psc`
  is the stale vanilla script, as the header already says.)
* `skse64.log`: `plugin SimpleFollowerFramework.dll (... ) loaded correctly (handle 261)`, and the
  DLL's string table carries `SFF_SKSE`, `IsVanillaFollower`, `GetMaxFollowers`.
* Only one copy of each SFF `.pex` exists in the whole mod list; `overwrite\Scripts` holds nothing
  but `CoreImpactFramework.pex`; `+Simple Follower Framework` is enabled in `Ultra\modlist.txt`.
* po3: `GetFormFromEditorID` is definitely registered - `LRG_Profile.IsMarried()` has used it since
  0.2 and snapshots worked; `po3_Tweaks.log` shows `Installed editorID cache`.

**Honest statement of confidence.** The *location* is certain: the stack is unwound inside
`BuildSnapshot`, after `PlaceFacts` and before the send. The *instruction* is not directly observed
- Papyrus logging was off for this playtest - and by elimination it is the SFF block of `fol=`.
The build below no longer depends on that being right: it survives an abort **anywhere** in the
payload and names the stage in the log, and the owner's Papyrus log (now on) will print the exact
function and line the first time it happens.

### 1.5 Why the second half of the session logged nothing at all

There were two Skyrim processes: one around 17:14-17:21 (all 16 glue messages, `maintenance done,
version 501, save 501, session 6043` at 17:17:01, `Save14` written 17:20) and the one the owner
actually played, 17:22:08 - 17:47:30 (`AIAgent.log` starts 17:22:09, crash log says uptime
00:25:22, `Save15` written 17:38).

The second process produced **no** glue line at all, not even `maintenance done`. Two things
combine: CHIM only registered its agents at 17:25:23, and `logMessageForActor` at load lands before
that; and **every other log point in the glue sits downstream of `MaybeSnapshot`** (`initiative
tick` needs `watchActor`, `conv hold` needs `NoteConvLine`, `LAT` needs `latTextAt`, `result <cmd>`
needs a command the server never sent). One aborting call therefore silences the entire game side.
That is fixed twice over below - the version line now goes out *first*, and the bookkeeping no
longer sits behind the snapshot.

### 1.6 Everything the brief asked to be checked, checked

* **Install completeness.** `F:\Modlists\LoreRim\mods\LoreRim Glue` has all ten `.pex`, all ten
  `.psc`, `LoreRimGlue.esp`, `Seq\LoreRimGlue.seq`, `MCM\Config\LoreRimGlue\config.json` +
  `settings.ini`, the SKSE ini/body files and `meta.ini`. The ten installed `.psc` are **byte
  identical** to the project sources, and the `.pex` (09:25-09:26) are newer than every `.psc`.
  Nothing is missing from the hand-copied installs.
* **ESP attachment** (`tools\esp_dump.py`): `LRG_MainQuest` (01000800) carries `LRG_Main`,
  `LRG_OStim`, `LRG_Dialogue`, `LRG_DlgProbe` plus alias script `LRG_PlayerAlias`;
  `LRG_MCMQuest` carries `LRG_MCM` + `SKI_PlayerLoadGameAlias`. Unchanged and correct.
* **Does `LRG_Followers` need a quest?** **No.** It is `Scriptname LRG_Followers Hidden` with
  nothing but `Global` functions - like `LRG_Profile` and `LRG_DlgUI`. It is never instantiated
  (confirmed: the save's Papyrus table contains `LRG_Main`, `LRG_OStim`, `LRG_Dialogue` and **no**
  `LRG_Followers` / `LRG_Profile` / `LRG_DlgUI` entry, which is exactly right). Deploying
  `LRG_Followers.pex` is all it needs, and that is done.
* **MCM.** Installed `config.json` / `settings.ini` are byte-identical to the project's, and carry
  `bPrivacyDoors = 1`, `bFollowerAware = 1`, `bFollowerRepair = 1`, `bFollowerVerbsReal = 1`,
  `bFollowerHoldSkip = 1`, `bEnabled = 1`, `bDebugLog = 1`. So both optional blocks were live.
* **The OnUpdate tick.** Nothing wrong with it - but note it can do nothing useful while
  `watchActor` is None, and `watchActor` is set by `Watch()`, which the abort was eating.

---

## 2. The fix (game side only; wire unchanged)

Papyrus cannot catch an error, so the only way to make a block unable to kill the snapshot is to
**notice that it did and stop running it**. Three fields on `LRG_Main`, one member write per step:

* `snapWhere` - how far the payload got. Stamped as it is built.
* `snapDoorRun` / `snapFolRun` - true *while* an optional block is running. Still true when the next
  snapshot starts = that block unwound the last one.

`MaybeSnapshot` now opens with: if `snapBusy` is still set and `snapWhere > 0`, the previous attempt
was killed. `SnapReportAbort()` then logs one line and retires the guilty block for the session:

```
SNAPSHOT ABORTED in the follower block (fol=) (stage 7): fol= / witchim are off for this session
```

and the **very next event gets a full snapshot minus that one block** - it no longer waits out the
30 s `snapBusy` window either. `door=` and `fol=` are both keys the server already reads as "say
nothing" when absent, so losing one costs a hint, never a turn. If the abort happens where no
optional block was running, the line says so and asks for the Papyrus log:

```
SNAPSHOT ABORTED in the witness scan (stage 3): no optional block was running - this is the CORE
payload, please send the Papyrus log
```

The `Bad` flags are cleared by `Maintenance()`, so a new `.pex` always gets a fresh try (cost: at
most one snapshot per load per block). The abort line itself is capped at six per session - two is
the normal worst case, and a core fault would otherwise write one per attempt all evening.

Changed files:

| file | change |
|---|---|
| `game\LoreRimGlue\Source\Scripts\LRG_Main.psc` | `CurrentVersion` 501 -> **502**; the three new fields; `SnapStage` / `SnapDoorOk` / `SnapDoorTry` / `SnapFolOk` / `SnapFolTry` / `SnapStageName` / `SnapReportAbort`; abort detection at the top of `MaybeSnapshot`; stage 1 / 11 / 0 around the build and the send; `Maintenance()` resets the new fields **and now logs `maintenance done` BEFORE `FollowerSweep()` and the crosshair snapshot**; `FollowerAware()` also honours `SnapFolOk()`; `OnChimSpeechStarted` / `OnChimTextReceived` do the latency, driver and OStim bookkeeping **before** `MaybeSnapshot` instead of after |
| `game\LoreRimGlue\Source\Scripts\LRG_Profile.psc` | `LRG.GetMain()` moved to the top of `BuildSnapshot`; stages 2-10 stamped; the door block gated on `m.SnapDoorOk()` and bracketed by `SnapDoorTry`; `folAware` gated on `m.SnapFolOk()`; the witness scan and `fol=` bracketed by `SnapFolTry` |
| `game\LoreRimGlue\Source\Scripts\LRG_Followers.psc` | the heavy SFF reads split out into `SffBlock(Actor) Global` - every value taken into a local and range-checked, the `DialogueFollowerScript` cast behind a `Quest` None test, `""` returned whenever SFF is not really there; `FolState` is now the vanilla facts + `SffBlock` |
| `glue\README.md` | version marker 501 -> 502 with a note that the wire and the server are unchanged |

The payload is **byte-identical** on a healthy install: no key added, none removed, none reordered.
Verified against the compiled `.pex` (read back with a read-only PEX disassembler): the stage marks
land exactly where the source puts them, `SnapDoorOk` gates `DoorFacts`, and `SnapFolTry(true)`
brackets both `WitnessScan` and `lrg_followers.FolState`.

The reordering of the two speech handlers is the second half of the fix and matters on its own:
even one aborted snapshot used to cost that event's conversation hold (which is why "follow me took
a while to get going" and "she would not stay" arrived together with the missing snapshots). Only
`Watch()` and `NoteConvLine()` still sit behind the call, because both are keyed on
`lastSnapActor`.

`tools\compile.ps1`: **`OK - .pex files copied ... 0 errors, 0 warnings`**, 22 auto-stubs, no
denylist hit, no over-long `.pex` string.

---

## 3. The second bug - SERVER side, NOT touched here

`lib\lrg_actions.php:1120`:

```php
if (in_array($mode, ['closed', 'public', 'private', 'follow'], true)
    && in_array($type, LRG_PLAYER_SPEECH_TYPES, true)) {
    ...
    $turn['intent'] = lrgRecogniseIntent((string) ($GLOBALS['gameRequest'][3] ?? ''), $ctx, $mem);
```

`silent` is not in that list, so **the intent recogniser never runs on a silent turn** - and
`lrgGateMode()` returns `silent` for `no_fresh_snapshot` (`lrg_core.php:1222`). That is the
confirmation the brief asked for: `intent=none/-` for "kiss me", "come hug me", "follow me" and
"take your clothes off" is not a recogniser failure, it is the recogniser never being called.

It is a second bug because the two consequences are not the same thing:

* **not offering an action** without a fresh snapshot is correct and must stay - the adults-only /
  privacy rails are computed from the snapshot and they fail closed;
* **not understanding the sentence** is not a safety property. `heat`, `askprice` / `haggle`
  memory, the `say_first` re-ask and the proposal bookkeeping all hang off `$turn['intent']`, so a
  silent stretch also loses all the state that would have made the *next* turn work, and nothing in
  the log ever says what the player asked for.

Suggested shape for whoever owns the server lane: run `lrgRecogniseIntent()` on every player-speech
turn (it is one regex pass and it already takes a context that can be empty), keep `heat` bumping
behind `mode != 'silent'` exactly as now, and let the turn line carry `intent=` even when nothing
is offered. Nothing about which actions are offered changes.

While in the log, two more server-side observations from this session, neither fixed here:

* `follower HEALTH: CHIM action MakeFollower / Follow is still switched ON` fires **twice every five
  seconds** (240+ identical pairs between 15:02 and 15:05). It is a real warning with a real fix
  (switch both off in the CHIM web UI) but it needs a "once per session" latch.
* `crash-2026-09-22-17-47-30.log` is **not** the glue: `OStim.dll` ->
  `ThreadActor::removeWeapons` -> `GameActor::unequipWeaponry` -> `Actor::UnequipItem` with
  `rdi = 0x1`, on a thread started for Lisette. Worth noting that yesterday's log has
  `OStim 'Remove Weapons at Start' was ON - turned OFF by the glue` and today it never ran, because
  that line lives in the scene-start path the dead snapshot never reached.

---

## 4. What to do next (nothing is installed)

1. Close MO2 and Skyrim, run `tools\install_mo2.ps1` - it has not run since Sunday and it is also
   what refreshes `meta.ini`.
2. Load and check `lorerim_glue.log` for `maintenance done, version 502`. If it says 501 the new
   `.pex` did not load.
3. Talk to anyone. Expect either `lrg_npcstate` traffic within a second or two, or **one** line
   beginning `SNAPSHOT ABORTED in` followed by normal traffic from then on.
4. Papyrus logging is now on (`Skyrim.ini [Papyrus] bEnableLogging=1 bEnableTrace=1
   bLoadDebugInformation=1`, edited 17:50 today). If the abort line appears, the matching
   `Documents\My Games\Skyrim Special Edition\Logs\Script\Papyrus.0.log` entry names the exact
   function and line - that turns "by elimination it is the SFF block" into a fact, and the block
   can then be fixed rather than retired.
