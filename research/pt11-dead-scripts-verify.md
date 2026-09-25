# pt11 VERIFY - the dead-scripts diagnosis re-derived, 504 checked, and installed by hand

LoreRim Glue, GAME lane. Written 2026-09-22 after `research/pt11-dead-scripts-fix.md`.
Role: verifier + installer. Every claim below was re-derived from the raw evidence with tools
written for this pass; nothing was taken from the fixer's report on trust.

**Result: the diagnosis holds, the fix is sound, and 504 is now INSTALLED** (22 files, SHA-256
verified, MO2 was open and Skyrim was not; no profile file was touched). Five defects are recorded
in section 6 - none of them blocks the playtest, one of them is worth a two-line patch before the
next build.

---

## 0. What the owner needs, in four lines

1. **Proof of life:** load a save and a corner message says
   `LoreRim Glue v504 loaded - session NNNN`. It needs no CHIM, no MCM Helper, no server, no
   Papyrus log. **If it does not appear, the scripts are not running at all** and nothing else in
   this report applies.
2. **Papyrus log:** `Logs\Script\` now exists (that was the one thing actually missing). Launch
   once and look for `Papyrus.0.log`; only if it is still absent do the ini work in section 5.
3. **Save:** load the newest one, `Save15 ... 20260922213815` (17:38). It is safe - see section 4.
4. Then talk to anyone. `lorerim_glue.log` should start showing `lrg_npcstate` traffic and the gate
   line should stop saying `no_fresh_snapshot`.

---

## 1. ROOT CAUSE - re-derived, independently, from four sources

### 1.1 The server lane is innocent (Apache, first hand)

`/var/log/apache2/other_vhosts_access.log`, decoded per request (the glue's payloads ride inside
CHIM's base64 `DATA=` parameter, so every request's tokens were base64-decoded and searched for
`lrg_`):

| 22 Sep, hour | requests | of which carrying an `lrg_` message |
|---|---|---|
| 15:00 | 104 | **0** |
| 16:00 | 52 | **0** |
| 17:00 | 4,403 | **16** |
| 19:00 (playtest 11) | 699 | **0** |

21 Sep for scale: 25,948 requests, **772** carrying `lrg_`.

So in playtest 11 the game sent the server **not one glue message of any type**, while CHIM's own
traffic (533 `comm.php` + 105 `gamedata.php` in the same window) was completely healthy. The
server's `no_fresh_snapshot` is the correct answer to an empty mailbox, and `age=76,704 s` dates the
last snapshot it ever received to **21 Sep ~22:18**. The 0.5.3 blind-turn work is visible and
working in the same log - `say="get naked" intent=undress/npc conf=high` - and it refused to act
only because the adults-only rails fail closed without fresh facts. **Confirmed: the server lane is
not the bug.**

### 1.2 The last thing the server ever heard (glue log, first hand)

`lorerim_glue.log` line 1495 is the last `GAME` line in the file:

```
2026-09-22 17:17:47 -04:00 GAME dlg self-test v400 menuless=0 dryrun=1 wire=1 ...
```

and the line before it, at 17:17:01, is `maintenance done, version 501, save 501, session 6043`.
Nothing from the game after 17:17:47 - not in the rest of that session, not in playtest 11.
**Confirmed.**

### 1.3 The save says the mod was alive and inert (own `.ess` reader)

Written for this pass and used read-only: an SSE `.ess` reader with a pure-Python LZ4 block
decompressor, a global-data-table walker and a Papyrus-section parser. Save15 decompressed to
22,890,675 bytes - an exact match for the length declared in its own header - and the Papyrus record
(global data type 1001, 15,807,443 bytes) parsed cleanly end to end: 56,624 strings, 9,181 script
definitions, 143,383 script instances, every offset landing where the previous structure ended.
Layout notes for whoever comes next: the string-table count is a **uint32**, a **TString is a
uint32** (a uint16 read walks off the rails at the second script), a script instance is 20 bytes
`{EID64, TString, u16, 0xFFFF, RefID}`, and a script-data record is `{EID64, 13 bytes, u32 count,
variables}` with variables `u8 type + {int/float/bool/string/null: 4}`, `{object: TString+EID64}`,
`{array: EID64}`.

`LRG_Main` in **Save15 (17:38) and Save14 (17:20) is identical**, 63 members (the 501 layout):

```
InstalledVersion 501     sessionTag 2347       keyStopScene 207      latSent TRUE
cidCounter 0             watchActor None       watchTalkTime 0.0     playerTalkTime 0.0
lastSnapActor None       lastSnapTime 0.0      lastAnySnapTime 0.0   lastMissActor None
latAskStart 0.0          latAskStop 0.0        latTextAt 0.0         latTextNpc ""
tickAt 0.0               snapTickAt 0.0        snapTickDue 0.0       initAt 0.0
convActor None           convActive false      convHeld false        folFixCount 0
lcValid false            pcNpc None            pcCell None           pcTime 0.0
snapBusy false           snapBusyTime 3297.374
lcFacts "loc=;ltype=wild"  pcFacts "cellown=none;home=0;nhome="  pcSde "sde=-1"
```

**Confirmed, all of it**, including the correction of `pt10-snapshot-fix.md`: I read the three
strings straight out of the **compiled `.pex` variable table** with an independent Papyrus
disassembler -

```
lcFacts  String  init=('str', 'loc=;ltype=wild')
pcFacts  String  init=('str', 'cellown=none;home=0;nhome=')
pcSde    String  init=('str', 'sde=-1')
```

- so they are literal initialisers that are in every save whether `PlaceFacts()` ran or not, and the
variable **values** (`lcValid=false`, `pcNpc=None`, `pcCell=None`, `pcTime=0.0`) say it never did.
502 and 503 were built to survive an abort in a stretch of code that was never reached.

`keyStopScene = 207` really does kill the "MCM Helper answers 0" theory *for that load*, and the
chain is tight: the 17:17:47 self-test comes from `LRG_Dialogue.Maintenance()`, which in 501 runs
**after** `RegisterKeys()`, so `RegisterKeys()` completed at that load and MCM answered 207 (the
in-code default is 0). It does not prove MCM stayed live afterwards, which is why 504's canary is
still worth having.

### 1.4 NEW - the save dates itself, and it goes further than the fix report

`snapBusyTime = 3297.374` is `Utility.GetCurrentRealTime()`, which restarts at 0 on every launch.
MO2's own logs bracket every launch:

| launch (usvfs log) | local start | last write | max real time |
|---|---|---|---|
| `usvfs-2026-09-21_20-54-48` | 21 Sep 16:54:48 | 22:18 | ~19,000 s |
| `usvfs-2026-09-22_21-11-44` | 22 Sep **17:11:44** | 17:47 | **~2,100 s** |
| `usvfs-2026-09-22_23-29-53` | 22 Sep **19:29:53** | 19:37 | **~450 s** |

3,297 s cannot belong to either 22 Sep launch. **Confirmed: it is a leftover from 21 Sep**, and
`Maintenance()` never cleared it - exactly as 504 now does.

And that turns `snapBusyTime` into a tripwire, because `MaybeSnapshot()` sets `snapBusy = true` and
`snapBusyTime = now` **before its first foreign call** (bytecode: the flag is taken at the top,
`IsEnabled()` and `getAgentByName` come after). Since the stamp still reads 3297.374 in a save
written at 17:38:

> **`MaybeSnapshot()` was not entered once in the whole 17:11-17:47 session** - not by the load,
> not by a crosshair change, not by a CHIM speech or text event - although the server log shows the
> owner in live conversation with Lisette from 17:26:46 through 17:38 (turns at 17:26:46, 17:28:15,
> 17:28:52, 17:29:14 and 77 log lines in the 17:3x bucket, her voice playing each time).

That narrows the fix report's open question (its section 1.7). Reading **(a)** - "the events fired
and were unwound inside `MaybeSnapshot`" - cannot hold for the crosshair path: `OnCrosshairRefChange`
is `cast → if npc → MaybeSnapshot`, with **no foreign call in between**, so a crosshair event that
fired would have written the stamp. Over half an hour of play, the crosshair was on an actor at
least once. What survives is reading **(b): after that load the quest stopped receiving events at
all.** Which is precisely what 504's heartbeat is built to break out of (it re-asserts
`RegisterForCrosshairRef` and the four CHIM registrations every fourth beat, i.e. every two
minutes).

Caveat, stated plainly: the save was written by the **501** `.pex`, and the placement of the
`snapBusy` stamp was read from the 503 source. The 0.5.2/0.5.3 notes only ever moved `snapWhere`,
not the flag, so the inference is sound as far as the record goes - but it is an inference.

### 1.5 The natives (spot-check, own `.pex` reader)

Every high-risk declaration compared against the compiled function table of the `.pex` the game
actually loads (name, return type, parameter types, Global|Native flags = 3):

```
AIAgentFunctions  logMessageForActor Int(String,String,String) GN   getAgentByName Actor(String) GN
                  get_conf_i Int(String) GN   commandEndedForActor Int(String,String) GN
                  findAllNearbyAgents Actor[]() GN   isActorTalking Int(String) GN
                  requestMessageForActor Int(String,String,String) GN  setAnimationBusy Int(Int,String) GN
MCM               IsInstalled Bool() GN   GetModSettingInt Int(String,String) GN
                  GetModSettingBool Bool(String,String) GN   GetModSettingFloat Float(String,String) GN
SFF_SKSE          IsVanillaFollower Bool(Actor) GN   GetMaxFollowers Int() GN
PO3_SKSEFunctions GetActorsByProcessingLevel Actor[](Int) GN
```

All identical to `tools\stubs\MCM.psc` and to the glue's call sites, and exactly one copy of each
`.pex` exists in the mod list (`overwrite\Scripts` holds only `CoreImpactFramework.pex`).
**Confirmed: no signature mismatch.**

---

## 2. THE FIX - checked against the task's four conditions

### 2.1 "The version line is the first statement and its transport cannot be gated off"

Read out of the **compiled** `LRG_Main.pex`, not the source:

```
LRG_Main.Maintenance   95 instructions; the complete list of calls it makes:
   Utility.RandomInt   Utility.GetCurrentRealTime   Debug.OpenUserLog
   self.RegisterForSingleUpdate (instr 85, L298)   self.UnregisterForAllModEvents
   self.RegisterForModEvent x4   self.RegisterForCrosshairRef   self.BootAnnounce (instr 94, last)
LRG_Main.BootAnnounce  calls: Debug.Trace, Debug.TraceUser, Debug.Notification - and nothing else
```

Not one call into another mod, and the update timer is armed (instr 85) before the registrations
(88-93) and before the announce (94). `Debug.Trace` and `Debug.TraceUser` are **unconditional**;
only `Debug.Notification` is behind `bootNotify`, which the compiled variable table initialises to
**True** -

```
bootNotify  Bool  init=('bool', True)
hbOn        Bool  init=('bool', True)
```

- so a save that predates the variable, a missing `settings.ini` and a broken MCM Helper all still
show the corner note. **Condition met**, with one caveat in defect D4.

`LogC` was reordered as claimed: `Debug.Trace` (instr 10) and `Debug.TraceUser` (12) run **before**
`SettingBool("bDebugLog…")` (13) and long before `AIAgentFunctions.logMessageForActor` (42).

### 2.2 "Every load-path block is guarded"

```
LRG_Main.BootTick   165 instructions
    0  assign S <- bootStep              ; s
    8  assign bootStep <- S+1            ; ADVANCED before anything runs
   30  RegisterForSingleUpdate(wait)     ; next tick armed (normal path)
   37  RegisterForSingleUpdate(5.0)      ; ... and on the last step, before step 10 runs
   41  BootReportAbort(bootWhere)        ; the previous step's obituary
   43  assign bootWhere <- S
   46  RegisterKeys()                    ; <- the FIRST instruction that can be unwound
```

Every foreign-mod block is now one step of its own, each behind a None guard: step 4 `if ost`,
step 5 `if dlg … else Log(...)`, step 6 `if prb` + `if d2 && prb.CalWire() != ""`, step 7
`if look`. **No new None dereference** anywhere in the diff. `Heartbeat` is the first call in
`OnUpdate` (instr 1, before `BootTick` at 4) and renews the timer at instr 10, **before** the 25 s
beat guard at 11-18. **Condition met.**

The diff is confined to `LRG_Main.psc`: the other nine sources are byte-identical to what was
installed, and `LoreRimGlue.esp` is byte-identical too (verified with `cmp`).

### 2.3 `tools\compile.ps1`

Run here, from the project:

```
rounds: 2 ; auto-stubs created: 22
compiled: LRG.pex, LRG_Dialogue.pex, LRG_DlgProbe.pex, LRG_DlgUI.pex, LRG_Followers.pex,
          LRG_Main.pex, LRG_MCM.pex, LRG_OStim.pex, LRG_PlayerAlias.pex, LRG_Profile.pex
OK - .pex files copied to game\LoreRimGlue\Scripts (only the glue's own scripts);
     compiler reported 0 errors, 0 warnings
```

The installed `LRG_Main.pex` reports `CurrentVersion -> return 504` and `BOOT_LAST -> return 10`
in its property getters, 80 variables, 78 functions. **Condition met.**

### 2.4 `tools\esp_dump.py`

```
QUST LRG_MainQuest  VMAD scripts=['LRG_Main','LRG_OStim','LRG_Dialogue','LRG_DlgProbe']
                    alias obj: alias=0 scripts=['LRG_PlayerAlias']
QUST LRG_MCMQuest   VMAD scripts=['LRG_MCM']  alias 0 SKI_PlayerLoadGameAlias
```

Four scripts on the quest, the player alias still carries `LRG_PlayerAlias` (the one that calls
`Maintenance()` on load). The save agrees from the other side: all four instances carry the same
RefID `0x01ABF01`. **Condition met.**

---

## 3. INSTALLED - by hand, hashed

MO2 was running (pid 41132) and **Skyrim was not** (checked immediately before copying), so
`tools\install_mo2.ps1` was not used and nothing outside `F:\Modlists\LoreRim\mods\LoreRim Glue`
was written. The previous contents of the three folders were backed up first to
`%TEMP%\lrg_vrf\backup-503-204352` (22 files).

Copied and then **SHA-256 compared file by file, source against destination: 22 files, 0
mismatches**:

| what | files |
|---|---|
| `Scripts\LRG*.pex` | 10 (`LRG_Main.pex` = `9b26de3b26dbead4…`) |
| `Source\Scripts\LRG*.psc` | 10 (`LRG_Main.psc` = `32c9800bf20da200…`) |
| `MCM\Config\LoreRimGlue\` | 2 (`config.json`, `settings.ini`) |

`LoreRimGlue.esp` was not touched (already identical). `meta.ini` still says `version=0.5.3` - left
alone deliberately, as the wire and the server did not change; the corner note is the only in-game
way to tell 504 from 503.

**MCM keys.** The installed `config.json` declares **115** control ids; the installed `settings.ini`
contains **all 115** plus the canary `iSettingsVersion:General`. Nothing is missing in either
direction, `config.json` parses as JSON, the new "Proof of life" header with
`bBootNotify:General` and `bHeartbeat:General` is on the General page, `iSettingsVersion = 504`,
`bBootNotify = 1`, `bHeartbeat = 1`. No user override exists anywhere
(`MCM\Settings\LoreRimGlue.ini` does not exist on the machine), so MCM Helper will read these
defaults fresh.

No shadowing: exactly one copy of each `LRG*.pex` exists in the whole mod list, and
`overwrite\Scripts` contains only `CoreImpactFramework.pex`.

---

## 4. WHICH SAVE TO LOAD

**Load the newest one and do not worry about it:**

```
F:\Modlists\LoreRim\profiles\Ultra\saves\
   Save15_EB82A32C_0_4A6F7264616E_SolitudeWinkingSkeever_000103_20260922213815_1_1.ess   (17:38)
```

There are no playtest-11 saves - the owner never saved in those six minutes - so Save15 is also the
save playtest 11 ran on.

**A save written by the dead build is harmless here**, and that is not a guess: the whole content of
`LRG_Main` in Save15 is 501's virgin state plus three values (`InstalledVersion`, `sessionTag`,
`keyStopScene`), and `Maintenance()` overwrites or resets every one of the rest on load - including
`snapBusyTime`, which 504 adds to the list. The 17 new 504 variables are not in the save at all, so
the VM gives each its compiled-in initial value, which for the two that matter is `true`
(`bootNotify`, `hbOn`). Nothing stale can survive into the next session.

Save14 (17:20) would work equally well; there is no reason to prefer it.

---

## 5. PAPYRUS LOGGING - what is actually wrong, and the order to try things in

I re-checked every claim in section 4 of the fix report and **one piece of its reasoning is wrong**,
so the owner's steps are re-ordered here.

**Verified on disk (read-only, nothing edited - MO2 is open):**

* `profiles\Ultra\settings.ini` -> `LocalSettings=true`, `LocalSaves=true`. So MO2 serves the
  profile's ini files to the game.
* `profiles\Ultra\Skyrim.ini` (mtime 18:37, i.e. **before** the 19:29 launch) - plain ASCII, CRLF,
  **one** `[Papyrus]` section at line 185, complete:
  `bEnableLogging=1 / bEnableProfiling=0 / bEnableTrace=1 / bLoadDebugInformation=1 /
  fPostLoadUpdateTimeMS=2000 / iMaxAllocatedMemoryBytes=500000`. No duplicate section anywhere in
  the file (all 25 section headers listed and checked).
* `Documents\My Games\Skyrim Special Edition\Skyrim.ini` carries the same three switches.
* `profiles\Ultra\skyrimcustom.ini` (mtime 20 Sep) has **no `[Papyrus]` section at all**.
* `initweaks.ini` contains only `[Archive] bInvalidateOlderFiles=1`. No mod ships an MO2
  "INI Tweaks" folder.
* Papyrus Tweaks NG's config (`mods\LoreRim - MCM and INI Settings\SKSE\Plugins\PapyrusTweaks.ini`)
  contains nothing that disables logging; it sets `bEnableDebugInformation = true` and
  `bEnableDocStrings = true`.
* `C:\Users\Jordan\OneDrive\Documents\My Games\Skyrim Special Edition` exists but is **stale**
  (30 Jun 2025) - the live path really is `C:\Users\Jordan\Documents\My Games\…`, where SKSE
  plugins wrote ~200 logs at 19:30-19:33 today.
* There is **no `Papyrus.0.log` anywhere** under `C:\Users\Jordan`.
* `Documents\My Games\Skyrim Special Edition\Logs\Script\` **exists now** and is empty - the
  previous pass created it by hand; it did not exist during any playtest.

**The correction.** `SkyrimCustom.ini` overrides Skyrim.ini **per key**, not per file: a section it
does not contain cannot switch off a block that Skyrim.ini does contain. Since `skyrimcustom.ini`
has no `[Papyrus]` section, it cannot be the reason logging is off, and adding the block there is
belt-and-braces rather than a fix. The one thing that was demonstrably missing is the
`Logs\Script` directory, which now exists.

**So, in this order (MO2 CLOSED for anything that edits a file):**

1. **Just launch and look.** `Logs\Script\` exists now. Load a save, play for a minute, then check
   `C:\Users\Jordan\Documents\My Games\Skyrim Special Edition\Logs\Script\Papyrus.0.log`.
   In most setups that is the whole fix.
2. If it is still missing - close MO2 and SkyrimSE, copy
   `F:\Modlists\LoreRim\profiles\Ultra\skyrimcustom.ini` to `skyrimcustom.ini.bak-papyrus`, and
   append (CRLF, at the end of the file):

   ```
   [Papyrus]
   bEnableLogging=1
   bEnableTrace=1
   bLoadDebugInformation=1
   bEnableProfiling=0
   fPostLoadUpdateTimeMS=2000
   iMaxAllocatedMemoryBytes=500000
   ```

   Leave the block already in `profiles\Ultra\Skyrim.ini` exactly as it is - the two agree, so
   whichever file the game reads, logging is on. (Equivalent and safer: make the same edit through
   MO2's own **Tools -> INI Editor**, which writes the file MO2 actually serves.)
3. If it is *still* missing, run once with MO2 -> Tools -> Profiles -> **uncheck "Use
   profile-specific Game INI files"**: the game then reads
   `Documents\My Games\Skyrim Special Edition\Skyrim.ini`, which already has the block.

**None of this blocks the playtest.** The corner note in step 0 of this report needs no log at all;
the log only turns "a block died" into "this function, this line".

---

## 6. DEFECTS

**D1 - the idle cost of the heartbeat is understated, and it wakes two other scripts.**
The fix report says the heartbeat costs "one `OnUpdate` and two float compares per 30 s". All four
glue scripts sit on **one** quest form (the save gives all four instances RefID `0x01ABF01`), and
`OnUpdate` is delivered per form - which is the project's own documented model ("LRG_Main owns the
one `RegisterForSingleUpdate` of this quest form … this module registers no timer and only
piggybacks on the event", `LRG_Dialogue.psc:17`). So a guaranteed 30 s tick now also runs
`LRG_Dialogue.OnUpdate` -> `VkSweep()` = `AIAgentFunctions.findAllNearbyAgents()` plus up to 24
`VkRecord` calls, **every 30 s, for ever**, where before it only ran when something else had asked
for a tick. Probably affordable, but it is new permanent background work and it should be named.

**D2 - the heartbeat disarms the MCM's calibration button.**
`LRG_DlgProbe.OnUpdate` -> `CalShotFire()` clears `cShot` and `cShotBusy` **unconditionally on
entry** (`LRG_DlgProbe.psc:242-247`), and the intended path is "arm it in the MCM -> open the
dialogue menu -> `OnMenuOpen` schedules the fire". With 504 an `OnUpdate` now arrives within 30 s
guaranteed, so an armed press will almost always be thrown away before the owner can open a
conversation. Pre-existing hole, newly load-bearing. Two-line fix, next build:
`if !cShotBusy \n return \n endif` before the `cShot = 0` at the top of `CalShotFire()`.

**D3 - boot step 9's 25 s wait is not actually enforced.**
`BootTick` computes `wait = (bootAt + 25.0) - now` when arming step 9, but the step body itself
never checks the clock; any `RequestTick` from elsewhere during the load window re-registers the
form's single update for a shorter delay, `OnUpdate` fires, and step 9 runs early - which defeats
its one purpose (re-sending the version line *after* CHIM's load-time `All queues cleared`). The
same early return also suspends the normal fan-out (`LRG_OStim.Tick`, `SnapTick`) for up to ~25 s
after a load whenever nothing else asks for a tick. Neither is dangerous; both deserve a `now >=
bootAt + 25.0` guard on step 9 in the next build.

**D4 - "the mod stays fully on" is not quite true when the canary trips.**
When `SettingsLive()` returns false every `Setting*` answers with the *caller's* default, and three
of those differ from `settings.ini`:

| key | in code | in settings.ini |
|---|---|---|
| `iKeyStopScene:Keys` | **0 (unbound)** | **207 (End)** |
| `iCalibRuns:Calib` | 3 | 5 |
| `iMaxEntries:Dialogue` | 16 | 0 |

The first one matters: PROTOCOL rests the non-adult and blocked-scene cases on "the stop hotkey
stays", and in the fallback it would not exist. One-word fix: make the in-code default for
`iKeyStopScene` **207**, so the canary's promise is real. (149 `Setting*` call sites were compared
against `settings.ini`; these three are the only mismatches.)

**D5 - the canary rests on an unverified assumption about MCM Helper.**
`iSettingsVersion` is deliberately on no MCM page, so the canary only works if MCM Helper's setting
store serves keys that exist in `settings.ini` but in no `config.json` control. Nothing on this
machine proves that it does (before 504 every one of the 115 keys was on a page, so there is no
precedent to read). If it does not, `SettingsLive()` returns false for ever: the mod runs fully on
its code defaults - safe, and one log line says so - but **every MCM setting the owner changes is
then ignored**, and D4's stop hotkey goes with it. Cheap insurance: fix D4, and consider mirroring
`iSettingsVersion` onto the Calibration page as a read-only text row so the value is unambiguously
registered.

**Not a defect, but worth knowing:** every `.pex` embeds its compilation timestamp, so re-running
`compile.ps1` changes all ten hashes even though only `LRG_Main.psc` moved. "Nine of ten sources
byte-identical" is confirmed at the **source** level; do not expect the installed `.pex` hashes to
match an older build's.

---

## 7. What is still not known

* **Which block actually killed the 17:17:47 load.** The abort is somewhere between
  `LRG_Dialogue.SelfTest()`'s first `LogC` and `Log("maintenance done")` - a stretch that in 503
  covers `get_conf_i` x5, `LRG_DlgUI.SubtitlesOn/SmartTalkOffender`, the rest of
  `LRG_Dialogue.Maintenance` and `LRG_DlgProbe.Maintenance`. 504 makes it survivable and will name
  it in one line (`BOOT STEP ABORTED: 5 (LRG_Dialogue.Maintenance …)`), and the Papyrus log will
  give the exact function and line.
* **Why the quest then received nothing at all for 18 minutes.** Section 1.4 rules the
  "unwound inside `MaybeSnapshot`" reading out for the crosshair path and leaves "the registrations
  stopped delivering". 504 does not need the answer - it re-asserts them every two minutes - but
  the first `Papyrus.0.log` from a healthy run will settle it.
* **504 has not run in the game.** Nothing here is a playtest.
