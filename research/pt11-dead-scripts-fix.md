# pt11 - the glue was DEAD, not slow: what the save says, and the boot rail that fixes it

LoreRim Glue, GAME lane. Written 2026-09-22 after playtest 11 (19:29-19:37 local).
Ships as `LRG_Main.CurrentVersion` **503 -> 504**. **NOT installed** - no file under
`F:\Modlists\LoreRim\mods\LoreRim Glue` was written, no profile file was touched (MO2 pid 41132 is
running). `tools\compile.ps1` prints `OK - ... 0 errors, 0 warnings`.

---

## 0. The short version

The owner's report is exactly right and it is worse than "she did not listen": **the game side sent
the server nothing at all.** Not one snapshot, not one log line, not one self-test - at the HTTP
level, zero requests carrying a glue message type in the whole 19:29-19:37 session, while CHIM's own
traffic (533 `comm.php`, 105 `gamedata.php`) went through normally.

The server therefore had no snapshot, `lrgEvaluateGates()` returned `no_fresh_snapshot`, and the
0.5.3 blind-turn fix did exactly what it was built to do: it **understood** every sentence
(`intent=act/oralpenis`, `intent=act/kiss`, `intent=undress/npc`) and refused to act on any of them
because the safety rails fail closed without fresh facts. That is why "suck my dick" and "take off
your clothes" produced words and nothing else. The server lane is not the bug.

And the three fix reports before this one were **chasing the wrong stretch of code**. The save file
settles it (section 1): the payload builder was never reached, `PlaceFacts` never ran, and the three
strings that `pt10-snapshot-fix.md` read as proof that it did are simply the **literal initialisers
compiled into `LRG_Main.psc`**, which are in every save whether the function ran or not.

What the save actually shows is much starker, and it is the finding of this pass:

> After the game load at ~17:17:47 on 22 Sep, **`LRG_Main` received no event of any kind for the
> rest of the session** - no CHIM speech event, no CHIM text event, no crosshair change, no tick,
> no snapshot, no correlation id - across 18 minutes with a CHIM-driven NPC in front of the player.
> The script was alive in the save and completely inert. Playtest 11 loaded that save and
> reproduced it exactly.

The fix is not another guard inside `MaybeSnapshot`. It is to stop running the entire load path as
**one Papyrus stack that any of five other mods can unwind**, and to make the proof of life
independent of the server. 504 does both (section 5).

---

## 1. BLACK BOX - what the saves say

### 1.1 There are no playtest-11 saves

The brief assumed "the newest ones are from 19:3x". There are none. The newest `.ess` anywhere on
the machine is

```
F:\Modlists\LoreRim\profiles\Ultra\saves\Save15_..._SolitudeWinkingSkeever_..._20260922213815_1_1.ess   17:38 local
```

and the whole profile `saves\` folder has mtime 17:38. MO2's own log
(`logs\mo_interface.log`) brackets the session: RootBuilder deployed at 23:30:44 UTC (19:30:44
local) and cleared at 23:37:06 (19:37:06) - **six and a half minutes, and the owner never saved.**
So playtest 11 loaded **Save15**, the last save of playtest 10's silent second session, and that is
the save this section reads.

### 1.2 Method

Written for this pass (read-only; no game file was modified):

* `ess.py` - SSE `.ess` reader: header, screenshot skip, and a pure-Python **LZ4 block**
  decompressor (Save15: 22,890,675 bytes, exact match on the declared length).
* `lrg2.py` - Papyrus global-data reader. The layout had to be re-derived, and two details differ
  from what a naive reader assumes; they are recorded here so nobody spends the afternoon again:
  * the string-table count is a **uint32**, not a uint16 (papyrus version 6);
  * a `TString` is a **uint32** index, a script member is `{TString name, TString typeName}` (the
    type is a *string*, e.g. `Int`, `Actor`, `String[]`), a script instance record is 20 bytes
    `{EID64, TString script, u16, u16=0xFFFF, RefID+1}`, and in the data section a variable is
    `u8 type` + `{null/int/float/bool/string: 4}`, `{ref and OBJECT array: TString + EID64 = 12}`,
    `{primitive array: EID64 = 8}`.
* `pexdump.py` - read-only `.pex` reader (header, string table, debug table, objects, properties,
  function signatures, opcode walk). Used for section 3.
* `bsa.py` - read-only BSA v105 reader, to get `MCM.pex` out of `MCMHelper.bsa`.

All four live in `%TEMP%\lrg_pt11`, outside the project tree.

### 1.3 Which LRG scripts exist in the save

```
LRG_Main (63 members)   LRG_OStim (129)   LRG_Dialogue (202)   LRG_DlgProbe (40)
LRG_MCM (1)             LRG_PlayerAlias (0)
```

No `LRG_Profile` / `LRG_Followers` / `LRG_DlgUI` instance, which is correct - they are
`Hidden` global-only scripts. 63 members is the **501** layout: `snapWhere`, `snapAborts`,
`snapDoorRun/Bad`, `snapFolRun/Bad` are absent, exactly as expected for a save written by the 501
`.pex`. So the save carries 501 state and playtest 11 ran 503 on top of it - a normal, supported
upgrade (Papyrus defaults new members).

### 1.4 `LRG_Main`'s variables in Save15 (17:38) - and in Save14 (17:20)

**They are byte-identical.** Same `sessionTag`, same timestamps, same `None`s. Nothing in
`LRG_Main` changed between 17:20 and 17:38. The values:

| variable | value | what it proves |
|---|---|---|
| `InstalledVersion` | **501** | `Maintenance()` ran (it is the first assignment in the function) |
| `sessionTag` | **2347** | ...and rolled a session tag (line 182) |
| `keyStopScene` | **207** | `RegisterKeys()` ran **and MCM Helper really served our settings**: the code default is `0`, `settings.ini` says `207`, and the save holds `207` |
| `cidCounter` | **0** | `NextCid()` was never called once |
| `watchActor` | None | `Watch()` never ran |
| `lastSnapActor` / `lastSnapTime` / `lastAnySnapTime` | None / 0 / 0 | no snapshot was ever *armed* - these are written the moment `getAgentByName` succeeds |
| `lastMissActor` / `lastMissTime` | None / 0 | ...and `getAgentByName` never returned None for a candidate either |
| `playerTalkTime`, `watchTalkTime`, `latAskStart`, `latAskStop`, `latTextAt`, `latTextNpc` | 0 / "" | **no CHIM speech or text event was ever handled** |
| `tickAt`, `snapTickAt`, `snapTickDue`, `initAt` | 0 | `OnUpdate` never ran - nothing ever asked for a tick |
| `convActor`, `convActive`, `convHeld`, `convPkgOn` | None / false | no conversation hold was ever taken |
| `folFixCount`, `folSeenSlot` | 0 | `NoteFollower` never queued anything |
| `snapBusy` / `snapBusyTime` | **false / 3297.374** | `MaybeSnapshot` was entered at some point and left through an early return. `snapBusyTime` is **not** cleared by `Maintenance()`, so this number can be older than the session |
| `lcValid`, `pcNpc`, `pcCell`, `pcTime` | false / None / None / 0 | **`PlaceFacts()` never ran** |
| `lcFacts`, `pcFacts`, `pcSde` | `loc=;ltype=wild`, `cellown=none;home=0;nhome=`, `sde=-1` | these are the **literal initialisers in `LRG_Main.psc`**, not evidence of a run |

### 1.5 The correction that matters

`pt10-snapshot-fix.md` 1.3 read those last three strings out of the save's **string table** and
concluded "`PlaceFacts` ran to completion, therefore the abort is inside `BuildSnapshot`, therefore
it is the SFF block of `fol=`". Both halves are wrong:

* the SSE Papyrus string table contains **every string of every loaded script**, including
  docstrings - `LogC`'s own 300-character docstring is in Save15's table - so finding a literal
  there proves nothing at all;
* the **variable values** say the opposite: `lcValid=false`, `pcNpc=None`, `pcCell=None`,
  `pcTime=0.0`. `PlaceFacts()` sets all four. It never ran.

502 and 503 were therefore built to survive an abort in a stretch of code that was never reached.
They are not wrong - the rail is sound and stays - but they could not have fixed playtest 10, and
they did not fix playtest 11.

### 1.6 The last thing the server ever heard

`lorerim_glue.log`, every `GAME` line of 22 Sep (there are 17, all inside 82 seconds):

```
17:16:25  dlg self-test v400 ...
17:16:59  dlg self-test v400 ...            <- duplicated: OnInit + OnPlayerLoadGame
17:16:59  dlg self-test v400 ...
17:16:59  dlg self-test chim tts=0 ...
17:17:00  dlg self-test speech globals ...
17:17:00  dlg self-test chim tts=0 ...
17:17:00  dlg self-test speech globals ...
17:17:00  CALIB SUMMARY ...
17:17:01  CALIB SUMMARY ...
17:17:01  CALIB GATE ...
17:17:01  CALIB GATE ...
17:17:01  dlg self-test calibration green=0 ...
17:17:01  dlg self-test calibration green=0 ...
17:17:01  maintenance done, version 501, save 501, session 6043
17:17:47  dlg self-test v400 ...            <- the LAST glue line that has ever reached the server
```

Nothing after 17:17:47. Not in playtest 10's second session, not in playtest 11.

That final line is a **load** (`LRG_Dialogue.Maintenance()` -> `SelfTest()` first `LogC`) and it is
alone. The very next statement in `SelfTest()` is the second `m.LogC(...)`, and Papyrus evaluates a
call's arguments at the call site, so the instructions between the line that arrived and the line
that did not are exactly:

```
AIAgentFunctions.get_conf_i("_player_tts_traditional_dialogue")
AIAgentFunctions.get_conf_i("_capture_background_chat")
AIAgentFunctions.get_conf_i("_restrict_onscene")
AIAgentFunctions.get_conf_i("_openmic_enabled")
AIAgentFunctions.get_conf_i("_player_auto_include_radius_m")
LRG_DlgUI.SubtitlesOn()
LRG_DlgUI.SmartTalkOffender()
```

and the session tag that load rolled is **2347** - the number Save14 and Save15 carry. So the load
that went silent is the 17:17:47 one, it died inside `LRG_Dialogue.SelfTest()`, and `LRG_Main`
never recovered: everything from `prb.Maintenance()` through `Log("maintenance done")`, the
load-time snapshot and `FollowerSweep()` was lost with that one stack.

### 1.7 The part that a single abort does NOT explain, and why 504 looks the way it does

`UnregisterForAllModEvents()`, the four `RegisterForModEvent` calls and `RegisterForCrosshairRef()`
are at `LRG_Main.psc:243-248` - **before** `RegisterKeys()`, which the save proves ran
(`keyStopScene = 207`). The registrations were therefore in place when the stack died. And yet the
save shows **not one event** was handled in the following 18 minutes.

That is one fact this pass cannot close from a save file alone, because Papyrus logging was off
(section 4). Two readings survive:

* **(a)** the events did fire and every one of them was unwound before it wrote anything - every
  handler's first real act is `MaybeSnapshot`, whose first calls are `IsEnabled()` (MCM) and then
  `AIAgentFunctions.getAgentByName`. An abort at `getAgentByName` would leave `snapBusy` **true**;
  the save has it false, which argues against this reading but does not kill it (the last call
  before the save could have been an entry-check early return);
* **(b)** the mod-event registrations did not survive, or CHIM stopped raising them after its own
  load-time reset (`SPGResponse.cpp:239 All queues cleared`, which CHIM logged at 19:33:38.556 -
  about the same moment `OnPlayerLoadGame` runs).

Reading (b) also explains something (a) does not: in playtest 11 **not even the first self-test
line arrived**, although CHIM's log shows no error and CHIM's own traffic was healthy from
19:33:40. A message handed to `logMessageForActor` during the load window is queued inside CHIM,
and CHIM clears its queues at `OnLoadedGame`. `AIAgent.log` contains **no `lrg` string at all** for
the whole session, so either the call never executed or CHIM dropped it before it ever reached
HTTPManager.

504 is built so that **it does not matter which of the two it is**:

* the proof of life no longer depends on CHIM at all (Papyrus log + `Debug.Notification`), and the
  server copy of it is **re-sent** after CHIM is demonstrably up;
* every optional block runs on its **own** Papyrus stack, so an abort anywhere can cost at most
  that one block;
* the event registrations are re-asserted from the tick, not only once at load.

---

## 2. CODE - the 503 load path, traced in order, with every abort point named

`LRG_PlayerAlias.OnPlayerLoadGame()` -> `LRG_Main.Maintenance()`. In 503 that function is **one
stack** from beginning to end, and this is what is on it, in order, before the first thing that
could ever tell anybody it is alive:

| # | line | what runs | can it unwind the load? |
|---|---|---|---|
| 1 | 178-241 | `installedVersion`, `sessionTag`, ~40 field resets, four `new` arrays | no - member writes only |
| 2 | 216 | `Debug.OpenUserLog` | no (vanilla) |
| 3 | 220 | `ReleaseConvHold("the game was loaded")` | **yes** - `ActorUtil.RemovePackageOverride` (PapyrusUtil), `Actor.SetDontMove`, `Actor.EvaluatePackage` on an actor the *save* remembers |
| 4 | 243-248 | `UnregisterForAllModEvents`, 4x `RegisterForModEvent`, `RegisterForCrosshairRef` | no (vanilla/SKSE) |
| 5 | 249 | `RegisterKeys()` | **yes** - four `MCM.GetModSettingInt` calls |
| 6 | 251-254 | `GetOStim()` -> `LRG_OStim.Maintenance()` | **yes** - the largest single block, OStim natives |
| 7 | 258-263 | `GetDialogue()` -> `LRG_Dialogue.Maintenance()` | **yes** - MCM reads, `LRG_DlgUI.ResetCalibration()` (StorageUtil), `RegisterForMenu`, and `SelfTest()` -> 5x `AIAgentFunctions.get_conf_i`, `LRG_DlgUI.SubtitlesOn/SmartTalkOffender` (PapyrusUtil `MiscUtil`) **<- where 17:17:47 died** |
| 8 | 264-275 | `GetProbe()` -> `LRG_DlgProbe.Maintenance()`, `prb.CalWire()`, `dlg.SendCalib("load")` | **yes** |
| 9 | **285** | `Log("maintenance done, version ...")` | this is the first proof of life, and **eight blocks of other mods' code stand in front of it** |
| 10 | 295-300 | `Game.GetCurrentCrosshairRef()` -> `MaybeSnapshot(look, true)` -> `Watch` | yes |
| 11 | 307-315 | `FollowerSweep()` (po3 `GetActorsByProcessingLevel`, SFF) | yes |

The 502 note at `:280-284` says "the proof of life goes out first". It only went in front of **two**
of the eleven steps. Steps 3, 5, 6, 7 and 8 - five blocks reaching into PapyrusUtil, MCM Helper,
OStim, StorageUtil and CHIM - still run before anything is logged, and any one of them silences the
whole mod for the rest of the session. That is the defect.

**There is also no tick registration anywhere in `Maintenance()`.** `OnUpdate` only ever starts
because some event calls `RequestTick`, and every one of those call sites is downstream of the
handlers that were not firing. So once the load path dies, nothing can restart the mod short of
another load - which matches the save exactly (`tickAt = snapTickAt = snapTickDue = 0.0`).

### 2.1 Why the speech path sent nothing either

`Log()` -> `LogC()`:

```
if !SettingBool("bDebugLog:General", true)   -> MCM.IsInstalled() + MCM.GetModSettingBool
Debug.TraceUser(...)                          -> nothing, Papyrus logging was off
if !IsEnabled()                               -> two more MCM reads
AIAgentFunctions.logMessageForActor(...)      -> CHIM
```

So **every** glue line, including the abort lines the 502/503 rail exists to print, depends on MCM
Helper answering and on CHIM accepting the message. Two of the three transports the glue has are
therefore behind the very things most likely to be broken, and the third (`Debug.TraceUser`) writes
to a Papyrus log the modlist ships switched off.

`SendNpcMessage` (the snapshot) is gated on `IsEnabled()` alone, and `IsEnabled()` is
`SettingBool("bEnabled:General", true) && !SettingBool("bKillSwitch:General", false)`.
`SettingBool` returns `MCM.GetModSettingBool(...)` whenever `MCM.IsInstalled()`, and **MCM Helper
returns `false` for a key it does not know** - it never falls back to the caller's default. One
missing or unregistered key therefore switches the whole mod off in silence. It did not happen in
playtest 10 (`keyStopScene = 207` in the save proves the store was serving us), but it is a live
trap and 504 closes it (section 5.4).

### 2.2 The installed build really is 503

SHA-256 of all ten `.pex` in `F:\Modlists\LoreRim\mods\LoreRim Glue\Scripts` against
`glue\game\LoreRimGlue\Scripts`: **ten of ten identical**. `meta.ini` 19:19, `.pex` 19:17.
So "the new scripts did not load" is not the explanation; 503 was installed and ran.

---

## 3. NATIVES - every declaration checked against the installed `.pex`. No mismatch.

`pexdump.py` reads the real compiled function tables (name, return type, parameter types and the
`Global` / `Native` flag bits) out of the `.pex` files the game actually loads, and
`verify_natives.py` compares them with every `Script.Function(` call site in the glue's ten sources.

| script | where the game gets it | result |
|---|---|---|
| `AIAgentFunctions` | `mods\CHIM\Scripts\AIAgentFunctions.pex` | all 10 called functions present and identical - `Int logMessageForActor(String, String, String) Global Native`, `Actor getAgentByName(String) Global Native`, `Int commandEndedForActor(String, String) Global Native`, `Int get_conf_i(String) Global Native`, `Actor[] findAllNearbyAgents() Global Native`, `Int isActorTalking(String)`, `Int requestMessageForActor(String,String,String)`, `Int setAnimationBusy(Int,String)`, `Int setDrivenByAI()`, `Int setDrivenByAIA(Actor,Bool)` |
| `AIAgentAIMind` | `mods\CHIM\Scripts\AIAgentAIMind.pex` | `Int stayAtPlace(Actor, Int, String) Global` - ok |
| `MCM` (MCM Helper) | **inside `MCMHelper.bsa`** (extracted for this check) | all four called functions identical to `tools\stubs\MCM.psc`: `Bool IsInstalled()`, `Int GetModSettingInt(String,String)`, `Bool GetModSettingBool(String,String)`, `Float GetModSettingFloat(String,String)`, all `Global Native` |
| `MCM_ConfigBase` | `MCMHelper.bsa` | `extends SKI_ConfigBase`; the two events `LRG_MCM` overrides exist |
| `PO3_SKSEFunctions` | `mods\powerofthree's Papyrus Extender\Scripts` | all 9 called functions identical |
| `SFF_SKSE` | `mods\Simple Follower Framework\Scripts` | `Bool IsVanillaFollower(Actor) Global Native`, `Int GetMaxFollowers() Global Native` - identical |
| `dialoguefollowerscript` | `mods\Simple Follower Framework\Scripts` | the five signatures in `tools\stubs\DialogueFollowerScript.psc` all present |
| `StorageUtil`, `MiscUtil`, `ActorUtil` | `mods\PapyrusUtil SE ...\Scripts` | identical |
| `OThread`, `OThreadBuilder`, `OActor`, `OActorUtil`, `OLibrary`, `OMetadata`, `OJSON`, `OFurniture` | `mods\OStim Standalone ...\Scripts` | identical (46 functions) |
| `SurvivalModeImprovedApi` | `mods\Survival Mode Improved - SKSE\Scripts` | identical |
| `ModEvent` | SKSE | identical |

**Exactly one copy of each of those `.pex` exists in the whole mod list**, and none of them is
shadowed by `overwrite\Scripts` (which holds only `CoreImpactFramework.pex`). So a signature
mismatch is **not** the cause of playtest 11, and the `tools\stubs\` headers are all correct. This
also means the hypothesis "a native the glue declares does not exist, so the caller aborts with no
log" is now closed - it was the last cheap explanation still standing.

(The one thing this cannot rule out is a native that is *declared* correctly and *throws* at
runtime for a reason of its own - `get_conf_i` before CHIM's config is loaded is the obvious
candidate given 1.6. That is what the boot rail in section 5 is for.)

---

## 4. PAPYRUS LOGGING - why there is no `Papyrus.0.log`, and the exact fix for the owner

### 4.1 What is on disk

* `F:\Modlists\LoreRim\profiles\Ultra\Skyrim.ini` (mtime 18:37, before the 19:29 launch) has a
  single, well-formed `[Papyrus]` section at line 185: `bEnableLogging=1`, `bEnableTrace=1`,
  `bLoadDebugInformation=1`. There is no duplicate `[Papyrus]` section anywhere in the file.
* `C:\Users\Jordan\Documents\My Games\Skyrim Special Edition\Skyrim.ini` (mtime 18:40) carries the
  same three lines.
* `...\Skyrim Special Edition\Logs\` exists, **created 19:31:56**, and is **empty** - no `Script\`
  subfolder, no `Papyrus.0.log`. A whole-disk search finds no `Papyrus*.log` written today.
* 19:31:56 is the same second as `SKSE\PapyrusTweaks.log` and `SKSE\PapyrusUtilTFC.log`, i.e. SKSE
  plugin-load time. **`Logs\` was created by an SKSE plugin, not by the Papyrus VM.** The VM creates
  `Logs\Script\` itself the moment logging is on; it never did.
* The folder is writable: creating `Logs\Script` by hand succeeds (this pass created it - harmless,
  and it removes one failure mode).
* No mod in the list ships an MO2 `INI Tweaks` folder (checked), so nothing was merged in at launch;
  and MO2 did not rewrite the profile ini at launch (its mtime is still 18:37, while
  `initweaks.ini`, which MO2 *does* rewrite, is 19:30).
* `Papyrus Tweaks NG` is installed and its config
  (`mods\LoreRim - MCM and INI Settings\SKSE\Plugins\PapyrusTweaks.ini`) contains **nothing** that
  disables logging; it sets `bEnableDebugInformation = true` and `bEnableDocStrings = true`, which
  is the opposite.

### 4.2 The one file that is *proven* to be read, and the one that is not

`F:\Modlists\LoreRim\profiles\Ultra\skyrimcustom.ini` contains

```
[General]
bUseMyGamesDirectory=1
sLocalSavePath=__MO_Saves\
```

and the local-saves mechanism demonstrably works (the saves are in the profile's `saves\`, and
`Documents\My Games\Skyrim Special Edition\__MO_Saves` exists). **SkyrimCustom.ini is therefore
certainly being read by the game.** Nothing on disk proves the same for the profile `Skyrim.ini`,
and `SkyrimCustom.ini` is read *after* `Skyrim.ini` in any case, so it is the file that wins.

`skyrimcustom.ini` has **no `[Papyrus]` section at all.**

### 4.3 The fix, for the OWNER to apply with MO2 CLOSED

Do not edit anything while MO2 is open. Close MO2 and SkyrimSE, then:

1. Back up: copy `F:\Modlists\LoreRim\profiles\Ultra\skyrimcustom.ini` to
   `skyrimcustom.ini.bak-papyrus`.
2. Append this block to the **end** of `F:\Modlists\LoreRim\profiles\Ultra\skyrimcustom.ini`
   (CRLF line endings, same as the rest of the file):

```
[Papyrus]
bEnableLogging=1
bEnableTrace=1
bLoadDebugInformation=1
bEnableProfiling=0
fPostLoadUpdateTimeMS=2000
iMaxAllocatedMemoryBytes=500000
```

3. Leave the `[Papyrus]` block that is already in `profiles\Ultra\Skyrim.ini` exactly as it is -
   the two agree, so whichever file the game reads, logging is on.
4. Launch through MO2, load a save, and check that
   `C:\Users\Jordan\Documents\My Games\Skyrim Special Edition\Logs\Script\Papyrus.0.log`
   exists and is growing. If it still does not appear, the next thing to test is MO2's
   `profile_local_inis`: set the profile to **not** use local INI files for one run
   (Tools -> Profiles -> uncheck "Use profile-specific Game INI files"), so the game reads
   `Documents\My Games\Skyrim Special Edition\Skyrim.ini`, which already has the block.

With that log on, the first `[LRG]` line in it settles section 1.7 in one sentence, and any abort
prints the exact script, function and line.

---

## 5. THE FIX - 504: a boot rail, and three transports that do not depend on anybody

**Nothing about the wire changed.** No payload key was added, removed or reordered; the server is
untouched; `PROTOCOL.md` is unchanged. Everything below is `LRG_Main.psc` plus one key in the MCM
`settings.ini` and one row in `config.json`.

### 5.1 `Maintenance()` is now unkillable, and it is over in a few hundred instructions

The new `Maintenance()` does only things that **cannot** call into another mod, in the order of what
must survive:

1. the member resets (as before), plus `snapBusyTime = 0.0` - the one timestamp the 0.5.3 resets
   forgot, which is why Save15 still carries `3297.374` from a launch that had long ended;
2. **the boot queue is armed first**: `bootStep = 1` + `RegisterForSingleUpdate(0.4)`, so that not
   even the six lines below it can stop the load;
3. `UnregisterForAllModEvents()` + the four `RegisterForModEvent` + `RegisterForCrosshairRef()` -
   vanilla/SKSE, and the life of the whole feature, so they stay inline (and the heartbeat
   re-asserts them every two minutes anyway);
4. `BootAnnounce()` - the proof of life, through three vanilla natives and nothing else:
   `Debug.Trace` (-> `Papyrus.0.log`), `Debug.TraceUser` (-> the mod's own user log) and
   `Debug.Notification` (`LoreRim Glue v504 loaded - session <tag>`), the notification gated by
   `bBootNotify`, which is **`true` in code** and is only ever turned off, never on, by the MCM.

That is all. Every one of the eleven steps in section 2's table that reaches into another mod has
been moved out. `LogC()` was reordered for the same reason: `Debug.Trace` / `Debug.TraceUser` now
run **before** the `bDebugLog` MCM read and before the CHIM send, so with Papyrus logging on every
glue line is in `Papyrus.0.log` even when MCM Helper and CHIM are both broken.

### 5.2 The boot queue: one step per tick, and the next tick is armed BEFORE the step runs

Papyrus has no try/catch, and an error unwinds the *whole* stack - so the only way to make one
block unable to kill another is to put them on **different stacks**. `OnUpdate` gives a fresh stack
every time, so the boot steps are run one per tick:

```
Function BootTick()
    int s = bootStep
    bootStep = s + 1          ; advanced BEFORE the step: an abort cannot repeat it
    bootWhere = s
    if s < BOOT_LAST
        RegisterForSingleUpdate(0.2)   ; the NEXT tick is armed BEFORE the step runs
    endif
    ; ... exactly one step, then bootDone = s
EndFunction
```

If step *s* is unwound by another mod's native, `bootStep` is already *s+1* and the timer is
already armed, so step *s+1* runs 0.2 s later regardless. The next step also sees
`bootDone != bootWhere` and logs **one** line naming the block that died:

```
GAME BOOT STEP ABORTED: 6 (LRG_OStim.Maintenance) - the rest of the load continued
```

The steps, in order:

| step | what | if it dies |
|---|---|---|
| 1 | `RegisterKeys()` | keys are dead this session, nothing else is |
| 2 | `ReleaseConvHold("the game was loaded")` | a stale hold survives, nothing else is |
| 3 | `Log("maintenance done, version 504, save N, session T")` (first attempt) | step 4 still runs |
| 4 | `LRG_OStim.Maintenance()` | the intimacy module is off this session |
| 5 | `LRG_Dialogue.Maintenance()` (this is where 17:17:47 died) | the questing module is off this session |
| 6 | `LRG_DlgProbe.Maintenance()` + `dlg.SendCalib("load")` | the calibration is off this session |
| 7 | the load-time crosshair snapshot + `Watch` | one snapshot lost |
| 8 | `FollowerSweep()` | ghost repair is off this session |
| 9 | **the version line again**, ~25 s after the load, with `boot steps 10, aborted N` on the end | - |
| 10 | the MCM read (`bBootNotify`, `bHeartbeat`, and the canary line) | the two toggles keep their saved values, which are `true` on a fresh install |

The last step has nobody behind it to notice that it died, so the **first heartbeat** checks
`bootDone != bootWhere` once and prints the same line if step 10 was the one that went.

Steps 9 and 10 are the two that answer section 1.7 without needing to know which reading is right:
9 re-sends the proof of life **after** CHIM's load-time `All queues cleared`, so a version line
dropped inside CHIM's queue at load is not lost; 10 re-registers the events and puts the mod on its
own recurring tick, so it can no longer be left inert by one bad load.

### 5.3 The tick now keeps the mod alive by itself

`Heartbeat()` is the **first** statement of `OnUpdate`, before the conversation hold, the follower
repair, `LRG_OStim.Tick()` and `SnapTick()` - i.e. before anything that can be unwound. While
`bHeartbeat:General` is on (true in code) it beats every 30 s whether or not there is anything to
watch, asks for its next tick **before** it touches a single native, and every fourth beat
re-asserts `RegisterForCrosshairRef` and the four CHIM mod-event registrations.

That is what makes "the glue went permanently inert in the middle of a session" impossible to
repeat. In 0.5.3 every `RequestTick` call site lived inside a handler that was not running, so once
the load path died there was nothing left that could restart the quest's timer - which is precisely
the state Save14 and Save15 record (`tickAt = snapTickAt = snapTickDue = initAt = 0.0`). Worst case
now, the mod is back 30 s later. The cost while idle is one `OnUpdate` and two float compares per
30 s.

### 5.4 MCM can no longer silence the mod

MCM Helper's `GetModSetting*` returns `0` / `false` / `0.0` for a key it does not have, and
`SettingBool` handed that straight back instead of its caller's default - so one missing key in a
hand-copied `settings.ini` would switch the whole mod off with no message anywhere.

504 adds a **canary**. `settings.ini` gains `iSettingsVersion = 504` under `[General]`, and
`SettingsLive()` reads it once per load:

* `MCM.IsInstalled() == false` -> code defaults (as before);
* `MCM.GetModSettingInt(ModName, "iSettingsVersion:General") <= 0` -> MCM Helper does not have our
  settings at all, so **every** `Setting*` call returns the caller's default, the mod stays fully
  on, and one line says so:
  `MCM Helper has no settings for LoreRimGlue (iSettingsVersion=0) - running on built-in defaults`;
* otherwise, MCM is authoritative exactly as before.

The canary is not a menu option, so nothing the owner can do in the MCM can set it to 0.

### 5.5 Files changed

| file | change |
|---|---|
| `game\LoreRimGlue\Source\Scripts\LRG_Main.psc` | `CurrentVersion` 503 -> **504**, `BOOT_LAST = 10`; new members `bootStep` / `bootWhere` / `bootDone` / `bootAborts` / `bootAt` / `bootNotify` / `hbOn` / `hbAt` / `hbCount` / `mcmLive` / `mcmChecked`; new functions `BootAnnounce`, `BootStepName`, `BootReportAbort`, `BootTick`, `Heartbeat`, `SettingsLive`; `Maintenance()` reduced to resets + arming the queue + registrations + announce; `OnUpdate` runs `Heartbeat()` then the boot queue before anything else; `LogC` traces before the MCM gate; `SettingBool/Int/Float` go through `SettingsLive()`; `snapBusyTime` is reset on load |
| `game\LoreRimGlue\MCM\Config\LoreRimGlue\settings.ini` | `iSettingsVersion = 504` (the canary) under `[General]`, plus `bBootNotify = 1` and `bHeartbeat = 1` |
| `game\LoreRimGlue\MCM\Config\LoreRimGlue\config.json` | a "Proof of life" header with the `bBootNotify:General` and `bHeartbeat:General` toggles on the General page (the file still parses as JSON - asserted by the patch) |
| `glue\README.md` | the `LRG_Main.CurrentVersion` marker 503 -> **504** and a note saying what changed. `manifest.json` and `LRG_VERSION` stay at **0.5.3** on purpose: the wire and the server are untouched, and the installer is the only thing that reads `manifest.json` |

Nine of the ten `.psc` are byte-identical to what is installed; `LRG_Main.psc` is the only source
that moved. No other script's behaviour can have changed.

### 5.6 Verification

`tools\compile.ps1` -> `OK - .pex files copied to game\LoreRimGlue\Scripts (only the glue's own
scripts); compiler reported 0 errors, 0 warnings`, 2 rounds, 22 auto-stubs, no denylist hit, no
over-long `.pex` string.

The claims above were then checked **in the compiled bytecode**, with the read-only disassembler
written for this pass:

```
LRG_Main.Maintenance   (95 instructions, and not one call into another mod)
   ...
   82 L295  assign      bootStep 1
   85 L298  callmethod  RegisterForSingleUpdate self 0.4      <- the queue is armed FIRST
   87 L299  assign      tickAt
   88 L300  callmethod  UnregisterForAllModEvents self
   89 L301  callmethod  RegisterForModEvent CHIM_CommandReceived OnChimCommand
   ... 90-92 the other three CHIM events ...
   93 L305  callmethod  RegisterForCrosshairRef self
   94 L306  callmethod  BootAnnounce self                     <- the last thing, and it ends there

LRG_Main.BootTick   (165 instructions)
    0 L364  assign      S bootStep
    8 L369  assign      bootStep S+1                          <- advanced BEFORE the step
   30 L382  callmethod  RegisterForSingleUpdate self wait     <- next tick armed BEFORE the step
   37 L394  callmethod  RegisterForSingleUpdate self 5.0      <- ...and the timer before step 10
   41 L397  callmethod  BootReportAbort self bootWhere
   43 L399  assign      bootWhere S
   46 L401  callmethod  RegisterKeys self                     <- step 1, the first risky instruction
   ...
  164 L471  assign      bootDone S

LRG_Main.OnUpdate
    0 L781  assign      tickAt 0.0
    1 L786  callmethod  Heartbeat self                        <- first
    4 L788  callmethod  BootTick self
    5 L789  return

LRG_Main.Heartbeat
   10 L493  callmethod  RequestTick self 30.0                 <- the timer is renewed on EVERY tick
   18 L495  return                                            <- ...before the 25 s beat guard
   26 L500  callmethod  RegisterForCrosshairRef self          <- every fourth beat
   27 L501  callmethod  RegisterForModEvent CHIM_CommandReceived OnChimCommand

LRG_Main.LogC
   10 L699  callstatic  debug Trace tline                     <- before the MCM read
   12 L700  callstatic  debug TraceUser tline
   13 L701  callmethod  SettingBool bDebugLog:General True
   42 L715  callstatic  aiagentfunctions logMessageForActor
```

The `RequestTick` at `Heartbeat:10` is deliberately **before** the beat guard: `OnUpdate` is often
woken by another requester (`LRG_OStim`'s 0.8 s state pushes, the 20 s snapshot refresh) that then
finds nothing due and asks for nothing back, and without that line the quest would lose its timer
the first time that happened - which is the same hole in a smaller form. `RequestTick` keeps the
earliest wanted time, so it never delays anybody.

`Maintenance` contains exactly three static calls (`Utility.RandomInt`,
`Utility.GetCurrentRealTime`, `Debug.OpenUserLog`) and no method call on any object but `self`, so
there is no longer any way for another mod to unwind a load before the queue is armed.

**Not installed.** MO2 is running (pid 41132), so nothing was copied into
`F:\Modlists\LoreRim\mods\LoreRim Glue`: `Scripts\LRG_Main.pex` is still the 19:17 (503) file,
`MCM\Config\LoreRimGlue\settings.ini` is still the 06:59 file and `meta.ini` is still 19:19.
No profile file was touched either.

### 5.7 One thing this pass changed outside the project

`C:\Users\Jordan\Documents\My Games\Skyrim Special Edition\Logs\Script\` did not exist and now
does (created empty, by hand). It is the folder Skyrim writes `Papyrus.0.log` into; creating it
removes one possible failure mode for section 4 and cannot affect anything else.

---

## 6. What the owner should do next

1. Apply the `skyrimcustom.ini` `[Papyrus]` block in 4.3 **with MO2 closed**.
2. Install 504 (`tools\install_mo2.ps1` with MO2 and Skyrim closed), or hand-copy
   `Scripts\LRG*.pex`, `Source\Scripts\LRG*.psc` and `MCM\Config\LoreRimGlue\*`.
3. Load a save. **Within a second you should see a corner message
   `LoreRim Glue v504 loaded - session NNNN`.** That message does not touch CHIM, MCM, OStim,
   PapyrusUtil or the server: if it does not appear, the scripts are not running at all and nothing
   else in this report applies.
4. `lorerim_glue.log` should then show `maintenance done, version 504, ...` and, about 25 s later,
   the same line again ending in `boot steps 10, aborted N`. **If only the second one arrives, the
   first was eaten by CHIM's load-time queue clear and section 1.7 reading (b) is confirmed.** If
   `aborted` is not 0, the `BOOT STEP ABORTED` lines name every block that died and the Papyrus log
   names the exact function and line.
5. Talk to anyone: `lrg_npcstate` traffic should start immediately, and the server's gate line
   should read `mode=private|public|closed|follow ... profile=<word>` instead of
   `mode=silent reasons=no_fresh_snapshot`.
