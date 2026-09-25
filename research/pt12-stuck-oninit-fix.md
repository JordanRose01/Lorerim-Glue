# pt12 - a deadlock saved inside Save14/Save15 froze the glue: new quest form + trivial OnInit (script 505)

LoreRim Glue, GAME lane. Written 2026-09-23 after the playtest of 23 Sep 00:14-00:19.
Role: FIXER. `LRG_Main.CurrentVersion` **504 -> 505**. **NOT installed** - nothing under
`F:\Modlists\LoreRim\mods\LoreRim Glue` and no profile file was written (MO2 pid 41132 is running;
SkyrimSE was not running at any point of this pass). `tools\compile.ps1` prints `OK`.

---

## 0. The short version

**504 never ran at all, and no script change could have made it run on those saves.** The owner's
two newest saves (Save14 17:20, Save15 17:38, both 22 Sep) carry two Papyrus stacks that have been
frozen against each other since playtest 10. Every load resumes them - still frozen - and every
event of the glue's main quest (185 crosshair changes, every CHIM speech/text event, three
`OnPlayerLoadGame` calls) queues up behind them for ever. 504's new `Maintenance()` was one of the
queued calls. The log has no `[LRG] ... v504` line because not one line of 504 ever executed.

The deadlock (section 1): `LRG_Dialogue.OnInit` -> `Maintenance` -> `SelfTest` -> `LRG_Main.LogC` ->
`Actor.GetDisplayName`, on one stack; `LRG_PlayerAlias.OnPlayerLoadGame` -> `LRG_Main.Maintenance` ->
`LRG_Dialogue.Maintenance()`, on the other. A script is closed to every other stack until its
`OnInit` returns, so the second stack waits for `LRG_Dialogue` while holding `LRG_Main`, and the
first cannot come back into `LRG_Main.LogC` from the native because `LRG_Main` is held. Neither
moves again. Papyrus writes both into every save and resumes them with **their own saved bytecode**
("using version from save") - so replacing the `.pex` changes nothing for those instances.

505 therefore does four things:

1. **New form.** `LRG_MainQuest` moves from object id 0x800 to **0x802** (plugin form `0x01000802`,
   in game `FECF6802`). The old record is gone from the plugin, so on load the save's old instances
   are unbound, their stacks can only ever touch those dead instances, and the quest starts with four
   fresh script instances. The MCM quest keeps 0x801.
2. **Trivial OnInit everywhere.** `LRG_Main.OnInit` sets one member and arms `RegisterForSingleUpdate`;
   `LRG_OStim`, `LRG_Dialogue`, `LRG_DlgProbe` `OnInit` only `RegisterForModEvent("LRG_Boot", ...)`.
   Verified in the compiled bytecode (section 4.3).
3. **Asynchronous module boot with a watchdog.** `LRG_Main`'s boot queue no longer *calls* the three
   modules - it *asks* them with an `LRG_Boot` mod event, each boots on its own stack and calls back
   `BootConfirm`. Until a module confirms, `GetOStim()` / `GetDialogue()` / `GetProbe()` answer None,
   so `LRG_Main` can never be parked waiting on one of them. No confirmation within 20 s ->
   `BOOT STALL: <script>` (trace first), the module is left out for that load, and everything else
   carries on.
4. **Native-free log path.** `LogC` no longer calls `Game.GetPlayer().GetDisplayName()`; the name is
   cached once by boot step 1 (default `"Player"`). Nothing runs before the `Debug.Trace`.

---

## 1. The evidence, read first hand

Source: `C:\Users\Jordan\Documents\My Games\Skyrim Special Edition\Logs\Script\Papyrus.0.log`
(10,904 lines, opened 23 Sep 00:14:22, last write 00:19). Save loaded: Save15 (the CHIM lines at the
load say `SolitudeWinkingSkeever`, which is Save15's cell; Save14 is `SolitudeWorld`).

### 1.1 The load (lines 929-930)

```
00:16:40 Warning: Function LRG_Main..LogC in stack frame 3 in stack 2353 differs from the in-game resource files - using version from save
00:16:40 Warning: Function LRG_Main..Maintenance in stack frame 1 in stack 78350 differs from the in-game resource files - using version from save
```

Two glue stacks came **out of the save**, and the VM keeps running the old bytecode for their frames.

### 1.2 The dump (lines 3020-3034 summary, 6506-6668 detail; repeated unchanged at 10642-10832, 00:18:45)

```
Suspended stack count is over our warning threshold, dumping stacks:
Event: LRG_Dialogue.OnInit, Frequency: 1
Event: LRG_Main.OnChimSpeechStopped, Frequency: 2
Event: LRG_PlayerAlias.OnPlayerLoadGame, Frequency: 3
Event: LRG_Main.OnChimSpeechStarted, Frequency: 5
Event: LRG_Main.OnChimTextReceived, Frequency: 6
Event: LRG_Main.OnCrosshairRefChange, Frequency: 185
Event: LRG_Dialogue.OnCrosshairRefChange, Frequency: 187
```

Stack **2353** - `State: Waiting on other stack for return`, `Type: Initialization Event`,
`Return register: "Jordan"`:

```
[ (00000014)].Actor.GetDisplayName()             <native>  IP 0
[LRG_MainQuest (FECF6800)].LRG_Main.LogC()        <savegame>  asMsg = "dlg self-test v400 menuless=0 dryrun=1 ..."
[LRG_MainQuest (FECF6800)].LRG_Dialogue.SelfTest()     LRG_Dialogue.psc line 444
[LRG_MainQuest (FECF6800)].LRG_Dialogue.Maintenance()  LRG_Dialogue.psc line 391
[LRG_MainQuest (FECF6800)].LRG_Dialogue.OnInit()       LRG_Dialogue.psc line 315
```

Stack **78350** - `State: Waiting on other stack for call`, `Return register: [LRG_Dialogue ...]`:

```
[LRG_MainQuest (FECF6800)].LRG_Main.Maintenance()   <savegame>  IP 4298  [::temp0]: 2347  [dlg]: LRG_Dialogue  [prb]: None
[alias PlayerAlias on quest LRG_MainQuest (FECF6800)].LRG_PlayerAlias.OnPlayerLoadGame()  line 7
```

Every other glue stack in the dump (`80262`, `85813`, the 185 + 187 crosshair calls, the CHIM events)
is `(requested call)` / `Waiting on other stack for call` with frame count 0: queued, never started.
Not one `[LRG]` trace line exists in the whole log (the only two `[LRG]` strings are temporaries
inside stack 2353's dump).

### 1.3 Why it is a deadlock, not a slow stack

* 2353's native has **already returned** (`Return register: "Jordan"`); it only needs `LRG_Main` back
  to continue in `LogC`. It says `Waiting on other stack for return`.
* 78350 is **inside** `LRG_Main.Maintenance` (so it holds `LRG_Main`) and is trying to call
  `dlg.Maintenance()`. It says `Waiting on other stack for call`, return register `LRG_Dialogue`.
* `LRG_Dialogue` is not free because 2353 is its `OnInit` ("Initialization Event"). Papyrus pauses any
  other script's call into a script until that script's `OnInit` has finished - the Creation Kit
  documents this for `OnInit`. So 78350 waits for 2353 to finish `OnInit`, and 2353 waits for 78350 to
  release `LRG_Main`. That is a cycle; nothing in the VM breaks it. The second dump, a minute later,
  shows both exactly as before.
* The 185 `LRG_Main.OnCrosshairRefChange` requested calls queue on `LRG_Main` (held by 78350); the
  187 `LRG_Dialogue` ones and the three `OnPlayerLoadGame` calls queue on the paused `LRG_Dialogue` /
  `LRG_Main`. 504's `Maintenance` is what `OnPlayerLoadGame` would have called - it never started.

### 1.4 When it was born

`[::temp0]: 2347` in 78350's `Maintenance` frame is `Utility.RandomInt(1000, 9999)`, i.e. the session
tag that load rolled - and 2347 is exactly the `sessionTag` that pt11's save reader found in **both**
Save14 and Save15 (`research/pt11-dead-scripts-verify.md` 1.3). So the frozen pair was created by
the load at ~17:17 on 22 Sep (playtest 10; the last `GAME` line the server ever received, 17:17:47,
is the first `LogC` of that `SelfTest` - the very line stack 2353 is parked in) and was written into
both saves made after it. pt11 1.6 also shows the same race at 17:16:59, where `OnInit` and
`OnPlayerLoadGame` both ran the self-test and happened to get through - it is a coin flip, and at
17:17:47 it lost. Save1 / Save3 (21 Sep, alternate-start cell) are older than the pair.

### 1.5 What pt11 got right and what it missed

pt11's diagnosis "after the 17:17 load the quest received nothing" is exactly right, and its reading
(b) "the quest stopped receiving events" is what this is - but the cause is not lost registrations or
an unwound stack: the events **were** delivered and are sitting in the queue, behind a lock that can
never be released. 504's boot rail (one step per `OnUpdate`, the heartbeat, the trace-first `LogC`)
is sound and stays in 505; it simply never got a chance to run on those saves.

---

## 2. The fix

### 2.1 New main-quest form (`tools\make_esp.py`)

```
MAIN_QUEST_ID = 0x802      # was the old id - RETIRED_IDS guards it
MCM_QUEST_ID  = 0x801      # unchanged: config.json names formId 0x801 for its CallFunction/PropertyValue rows
NEXT_OBJECT_ID = 0x803     # HEDR
```

plus two asserts (neither id is retired; all three are in the ESL object range). The plugin was
regenerated (`game\LoreRimGlue\LoreRimGlue.esp`, `Seq\LoreRimGlue.seq`) - see 4.2 for the byte diff.

**Why this frees the saves.** A Papyrus script instance is bound to a form. When a save is loaded
and a form it references no longer exists in any plugin, the instances that were bound to it are
left unbound (the log will warn about them once), and nothing can deliver an event to them any
more - there is no form to register on, no alias to fill, no `OnPlayerLoadGame`. The two frozen
stacks reference only those old instances (`LRG_Main`, `LRG_Dialogue`, `LRG_PlayerAlias` of the old
quest). Whether the VM discards them on load or keeps them suspended, they hold locks on objects
nobody uses; they cannot hold the new ones. The new `LRG_MainQuest` (0x802) is a start-game-enabled
quest the save has never seen, so the engine starts it on load and creates **four fresh script
instances plus a fresh alias instance**, whose first act is the trivial `OnInit` below. `LRG.GetMain()`
now resolves 0x802, so CHIM's `ExtCmd` bridge and `LRG_MCM` reach the new instance. Reusing the old
id later would bind the frozen instances again, which is why `make_esp.py` keeps it in `RETIRED_IDS`.

**What state is lost - and it was checked in the saves, not assumed.** Only the glue's own
per-instance runtime state of the old quest: `LRG_Main` / `LRG_OStim` / `LRG_Dialogue` /
`LRG_DlgProbe` variables (session tag, cached key codes, snapshot throttles, diagnostics counters,
the voice-keeper cache of `LRG_Dialogue`, the probe's per-session flags). Every one of these is
re-initialised on each load anyway, except the three things that would leave the world changed if a
new instance forgot them. Those were read out of **both** saves with the pt11 save reader (object
arrays decoded this time):

| state that would outlive a lost instance | Save14 | Save15 |
|---|---|---|
| conversation hold (`convHeld`, `convActive`, `convActor`, `convPkgOn`) - SetDontMove / package override | false / false / None / false | same |
| outro hold (`outroActive`, `outroActor`) | false / None | same |
| glue-stripped clothes (`cloNpc`, `cloNpcItems[]`, `cloPlayerItems[]`, `baseValid`) | None, all None, all None, false | same |
| emptied hands (`prepActor[]`) | [None, None] | same |
| listener / scene (`listenerForced`, `startedByGlue`) | false / false | same |

So nothing physical is left behind in the world. **Not lost:** MCM settings (MCM Helper keeps them per
mod name in `MCM\Settings\LoreRimGlue.ini`; pt11 found no user override exists anyway, and the MCM
quest 0x801 with `LRG_MCM` is untouched), the calibration and every per-NPC value (`StorageUtil`,
keyed on `None` or on the NPC - checked: no call keys anything on the quest form), and everything the
server keeps. The first 505 session also rebuilds `installedVersion` (0 -> 505) and re-reads keys
from MCM at boot step 2.

### 2.2 Trivial OnInit

| script | `OnInit` now (bytecode, complete) |
|---|---|
| `LRG_Main` | `assign initBoot True` ; `RegisterForSingleUpdate(0.5)` |
| `LRG_Dialogue` | `RegisterForModEvent("LRG_Boot", "OnLrgBoot")` |
| `LRG_DlgProbe` | `RegisterForModEvent("LRG_Boot", "OnLrgBoot")` |
| `LRG_OStim` | `RegisterForModEvent("LRG_Boot", "OnLrgBoot")` (it had no OnInit before) |
| `LRG_PlayerAlias`, `LRG_MCM`, the three Global-only scripts | no `OnInit` of ours |

`LRG_Main`'s first `OnUpdate` sees `initBoot` and runs `Maintenance()` there, on an ordinary stack
(504's `Maintenance` was already trivial: member resets, arm the queue, registrations, the corner
note). `OnPlayerLoadGame` is unchanged and still calls `Maintenance()` on every load; if both happen
on one load, `Maintenance` simply runs twice (it is idempotent).

### 2.3 The boot queue, 11 steps (`LRG_Main.BootTick`)

| step | what | notes |
|---|---|---|
| 1 | cache the player's display name for `LogC` | the only actor native in the queue, on its own stack |
| 2 | `RegisterKeys()` | |
| 3 | `ReleaseConvHold("the game was loaded")` | |
| 4 | `maintenance done, version 505, ...` | |
| 5 | **ask** `LRG_OStim` | `BootAsk(0)`: `LRG_Boot` mod event (who, session) and return |
| 6 | **ask** `LRG_Dialogue` | `BootAsk(1)` |
| 7 | **ask** `LRG_DlgProbe` | `BootAsk(2)` |
| 8 | load-time crosshair snapshot | waits (0.25 s re-ticks) until `LRG_OStim` confirmed or stalled, so a save made mid-scene is not reported as "no scene" |
| 9 | `FollowerSweep()` | |
| 10 | the version line again, at load + 25 s, now ending `modules ok/ok/ok, stalled 0` | **waits for its time** if woken early (pt11 verifier D3) |
| 11 | settings read (`bBootNotify`, `bHeartbeat`, the canary) | |

Each module's entry point is `Event OnLrgBoot(string asWho, int aiSess)`: it ignores any name but its
own, runs its old `Maintenance()`, sets `booted = true`, then calls `LRG_Main.BootConfirm(i, aiSess)`
(a confirmation from an earlier load is ignored by session tag). `LRG_Dialogue` runs its
`SelfTest()` **after** the confirmation (it is diagnostics; the 0.5.4 freeze sat in it), and handles
`asWho == "calib"`: `LRG_Main` sends that once both `LRG_Dialogue` and `LRG_DlgProbe` confirmed, and
the dialogue module then sends v0.5 W1's `ev=calib` on its own stack (504 did it from step 6).

**Why a mod event and not a call.** The two frozen stacks show what a direct call does when the
target is not free: the *caller* waits, holding its own script, and everything queued on that
script waits with it. A mod event is queued by SKSE and delivered to the module on a new stack;
`LRG_Main` returns at once. Mod-event registrations are per form, which is why the one
`RegisterForModEvent("LRG_Boot", "OnLrgBoot")` in `LRG_Main.Maintenance()` (and in the heartbeat's
re-registration) reaches `OnLrgBoot` in all three modules - the same per-form model the project
already relies on for `UnregisterForAllModEvents` and `OnUpdate`. The modules' own `OnInit`
registration is a harmless second registration for the very first load.

**Guards against running before the boot.** `LRG_Dialogue`'s four event handlers (`OnUpdate`,
`OnLrgDlgPump`, `OnMenuOpen`, `OnCrosshairRefChange`) test `booted` instead of `attached`;
`LRG_DlgProbe.OnUpdate` tests `booted`; `LRG_OStim.Tick()` tests `booted`. And `LRG_Main` itself never
calls a module that has not confirmed this load: `GetOStim()` / `GetDialogue()` / `GetProbe()` return
None until `BootConfirm`, and every caller already treats None as "not there". A CHIM command that
arrives in that second answers `Error: the glue is still starting after the load - say it again in a
moment` (`ModDownWhy`) instead of the misleading "OStim is not installed". Non-forced snapshots are
skipped while `LRG_OStim` is still booting (time-bounded to 25 s even if no tick ever came), because
without it a snapshot would claim "no scene".

### 2.4 The watchdog (`LRG_Main.BootWatch`, every `OnUpdate`)

Reads `LRG_Main`'s own members only - never a module's (a read of another script is a call). A module
asked more than 20 s ago (`BOOT_STALL_SECS`) without a confirmation is marked stalled, logged once
through `Log()`/`LogC` (whose `Debug.Trace` is instruction 10, with no call before it):

```
[LRG] BOOT STALL: LRG_Dialogue did not confirm its boot within 20 s - the rest of the glue carries on without it this session
```

plus a corner note (`LoreRim Glue: LRG_Dialogue did not start (BOOT STALL)`, behind `bBootNotify`), and
the boot event is sent once more in case the first was lost. A late confirmation turns the module
back on (`BOOT LATE: ... it is back on`). While anything is pending the watchdog asks for a tick at
the deadline, so a stall is reported even with the heartbeat switched off. The queue never waits on
it (step 8 waits for `LRG_OStim` at most until the watchdog calls it), and the tick and the snapshot
never call into a stalled module.

### 2.5 Native-free log path

`LogC`: string building -> `Debug.Trace` -> `Debug.TraceUser` -> MCM gate -> CHIM send. The actor name
for the CHIM send is `playerName` (member, default `"Player"`, set by boot step 1). No actor native
anywhere in `LogC` (bytecode, 4.3).

### 2.6 Also in this build (small, same functions)

* **pt11 verifier D2 fixed**: `LRG_DlgProbe.OnUpdate` fires `CalShotFire()` only when `cShotBusy`
  (a fire that `OnMenuOpen` scheduled). The 30 s heartbeat used to throw away an armed calibration
  press.
* **pt11 verifier D3 fixed**: the second version line (step 10) waits until load + 25 s even when
  another requester or the watchdog wakes the quest early.

---

## 3. Files changed

| file | change |
|---|---|
| `glue\tools\make_esp.py` | `MAIN_QUEST_ID = 0x802`, `NEXT_OBJECT_ID = 0x803`, `RETIRED_IDS`, range asserts |
| `glue\game\LoreRimGlue\LoreRimGlue.esp` | regenerated: main quest `0x01000802`, HEDR next id 0x803 (3 bytes differ, 4.2) |
| `glue\game\LoreRimGlue\Seq\LoreRimGlue.seq` | regenerated: `0x01000802, 0x01000801` |
| `Source\Scripts\LRG.psc` | `GetMain()` -> `GetFormFromFile(0x00000802, "LoreRimGlue.esp")` |
| `Source\Scripts\LRG_Main.psc` | 505; OnInit; `initBoot`; boot table (`modSt`, `modAsk`), `BootAsk`/`SendBoot`/`BootConfirm`/`BootWatch`/`BootStall`, `ModReady`/`ModPending`/`ModAttached`/`ModStateText`/`ModDownWhy`; gated accessors; 11-step queue; `playerName`; D3 guard; snapshot deferral |
| `Source\Scripts\LRG_Dialogue.psc` | trivial OnInit, `OnLrgBoot` (boot + `calib`), `booted`, SelfTest after confirm, handler guards |
| `Source\Scripts\LRG_DlgProbe.psc` | trivial OnInit, `OnLrgBoot`, `booted`, `OnUpdate` guard (+ D2) |
| `Source\Scripts\LRG_OStim.psc` | new trivial OnInit, `OnLrgBoot`, `booted`, `Tick()` guard |
| `Scripts\LRG*.pex` | all ten recompiled (every `.pex` embeds its build time, so all ten hashes change) |
| `glue\README.md` | version marker 504 -> 505, a 505 note, the ESP paragraph (0x802) |

Unchanged (byte-identical to the installed 504 sources): `LRG_DlgUI`, `LRG_Followers`, `LRG_MCM`,
`LRG_PlayerAlias`, `LRG_Profile`; `MCM\Config\LoreRimGlue\config.json` and `settings.ini` (no new MCM
key); the server; `PROTOCOL.md` (no wire change - `manifest.json` / `LRG_VERSION` stay 0.5.3).
Originals of every changed file were copied to this session's scratchpad (`backup504\`) first.

---

## 4. Verification

### 4.1 `tools\compile.ps1`

```
rounds: 2 ; auto-stubs created: 22
compiled: LRG.pex, LRG_Dialogue.pex, LRG_DlgProbe.pex, LRG_DlgUI.pex, LRG_Followers.pex, LRG_Main.pex, LRG_MCM.pex, LRG_OStim.pex, LRG_PlayerAlias.pex, LRG_Profile.pex
OK - .pex files copied to game\LoreRimGlue\Scripts (only the glue's own scripts); compiler reported 0 errors, 0 warnings
```

Longest docstring in any glue `.pex`: 290 chars (pre-existing, `LRG_MCM`); none of the new ones is
near 300; no `.pex` string over 500.

### 4.2 The plugin (`tools\esp_dump.py`)

```
TES4 flags=00000200   HEDR version=1.70 records=3 nextObjectId=00000803   MAST Skyrim.esm
QUST formid=01000802  EDID LRG_MainQuest
   VMAD scripts=['LRG_Main', 'LRG_OStim', 'LRG_Dialogue', 'LRG_DlgProbe']  (props=0 each)
   alias obj: formid=01000802 alias=0 scripts=['LRG_PlayerAlias']   bytes consumed 108/108
QUST formid=01000801  EDID LRG_MCMQuest
   VMAD scripts=['LRG_MCM']   alias obj: formid=01000801 alias=0 scripts=['SKI_PlayerLoadGameAlias']
SEQ: 02 08 00 01  01 08 00 01   (0x01000802, 0x01000801)
```

The 504 generator was first re-run and reproduces the shipped 504 ESP and SEQ byte for byte. Against
that, the new ESP is the same 683 bytes with **exactly three bytes different**: HEDR next id
(offset 38, 02 -> 03), the main quest's record form id (offset 185, 00 -> 02) and its alias object's
form id inside the VMAD (offset 301, 00 -> 02). The `LRG_MCMQuest` record is byte-identical (235
bytes). `0x01000800` occurs nowhere in the new plugin.

### 4.3 Bytecode (own disassembler, `%TEMP%\lrg_pt12\chk.py` / `chk2.py`)

```
every OnInit in every glue .pex - complete:
  LRG_Dialogue.OnInit  callmethod RegisterForModEvent self "LRG_Boot" "OnLrgBoot"
  LRG_DlgProbe.OnInit  callmethod RegisterForModEvent self "LRG_Boot" "OnLrgBoot"
  LRG_Main.OnInit      assign initBoot True ; callmethod RegisterForSingleUpdate self 0.5
  LRG_OStim.OnInit     callmethod RegisterForModEvent self "LRG_Boot" "OnLrgBoot"
LRG_Main.LogC: instructions 0-9 are strcat/assign/cmp/jmp only; 10 = Debug.Trace; GetDisplayName anywhere in LogC: False
LRG_Main: no call of <object>.Maintenance() on anything but self; BootTick's only non-self call is
          GetDisplayName on the player (step 1)
LRG.GetMain:  callstatic game GetFormFromFile 2050 "LoreRimGlue.esp"      (2050 = 0x802)
LRG_Main getters: CurrentVersion 505, BOOT_LAST 11, BOOT_AGAIN 10, BOOT_STALL_SECS 20.0, BOOT_EVENT "LRG_Boot"
OnLrgBoot params in all three modules: (String asWho, Int aiSess) - matches ModEvent.PushString + PushInt
LRG_Main vars: playerName "Player", initBoot False, bootNotify True, hbOn True
```

### 4.4 Offline tests (run from a staged copy under `%TEMP%\lrg_pt12\stage`)

* `tools\test_mcm_wiring.php` - `3 passed, 0 failed - ALL CHECKS PASSED` (it reads every `.psc`)
* `tools\test_gates.php` - `332 passed, 0 failed` (section 32 reads `LRG_OStim.psc`)
* flows `d50` (parses `LRG_Main.psc`) and `d53` - `2 passed, 0 FAILED, 40 checks, 0 warnings`

---

## 5. Grep for the old form id after the change (whole `glue\` folder)

Text, pattern `0x0*800\b|1000800|00000800|x800\b|CF6800|\b0800\b`, case-insensitive, all files
except the binary `.pex/.esp/.seq/.nif` (exact output):

```
./PHASE2_DESIGN.md:117:... Verify with `tools/esp_dump.py`: the `VMAD` of `0x01000800` must list **four** script names.
./PHASE2_DESIGN.md:1084:| **5** | INTEGRATOR | ... `esp_dump.py` shows four script names on `0x01000800` |
./tools/make_esp.py:24:RETIRED_IDS = (0x800,)  # the pre-0.5.5 LRG_MainQuest - never reuse
./V04_BUILD_PLAN.md:166:  **every** script on quest `0x01000800`. Handle only your own keys/menus and return at once otherwise.
./V04_BUILD_PLAN.md:511:(`:55`). **Verify with `tools/esp_dump.py`: the `VMAD` of `0x01000800` must list four script names.**
./V04_BUILD_PLAN.md:608:`compile.ps1` prints OK · `esp_dump.py` shows four script names on `0x01000800` · the pre-flight passes ·
./V04_BUILD_PLAN.md:1471:| **4** | **B** | ... `esp_dump.py` shows **four** script names on `0x01000800` |
./V04_BUILD_PLAN.md:1515:python glue\tools\esp_dump.py glue\game\LoreRimGlue\LoreRimGlue.esp   # VMAD of 0x01000800 = 4 script names
./V05_EXPANSION_PLAN.md:64:`LRG_MainQuest 0x800` (`make_esp.py:49-50`). Because `make_esp.py` does not change, **`esp_dump.py`
```

* `PHASE2_DESIGN.md`, `V04_BUILD_PLAN.md`, `V05_EXPANSION_PLAN.md` are the historical build plans of
  0.4 / 0.5 (dated 21-22 Sep) - left as written.
* `tools/make_esp.py:24` is the **intentional retirement guard** (the generator asserts the main quest
  never goes back to that id). It is a guard, not a reference.
* No hit in any `.psc`, in `README.md`, in `PROTOCOL.md`, in `OVERRIDES.md`, in `MCM\Config` (its
  `formId` entries are all `0x801`), in `tools\*.ps1|*.php` or in `server\`.

Binary: all 31 shipped files under `game\LoreRimGlue` except `meshes\` (`.esp`, `.seq`, `.pex`,
`.json`, `.ini`, `.psc`) scanned for the little-endian `0x01000800` and, in `.pex`, for the int
constant 0x800: **0 hits**. `LRG.pex` does contain the int constant 0x802.

---

## 6. For the owner / the installer

**Install (not done here):** hand-copy into `F:\Modlists\LoreRim\mods\LoreRim Glue`, SHA-256 verified -
`LoreRimGlue.esp`, `Seq\LoreRimGlue.seq`, `Scripts\LRG*.pex` (10), `Source\Scripts\LRG*.psc` (10).
The **ESP and SEQ are part of this build** (504's install did not touch them). The MCM config files
did not change. STOP if SkyrimSE is running; no profile file needs touching (same plugin name, same
load order, still ESL-flagged).

SHA-256 (first 16 hex) of the build in `glue\game\LoreRimGlue`: `LoreRimGlue.esp 82a20db39da4dbf4`,
`Seq\LoreRimGlue.seq a881f50a2b2b30a3`, `LRG_Main.pex cf40857a7731a6c5`, `LRG.pex 93097362c2f0a9de`,
`LRG_Dialogue.pex 085788b59ad583e1`, `LRG_DlgProbe.pex ed3febf93c435543`, `LRG_OStim.pex d95258ce5d19b6f4`
(the installer must hash the full files itself).

**Which save:** any - Save15 is fine. The frozen pair belongs to the old form and cannot touch the new
quest. On the first load expect, once: Papyrus warnings about `LRG_*` instances / stacks of the old
`LRG_MainQuest (FECF6800)` that can no longer be bound (that is the old quest being dropped), then:

1. within ~1 s a corner note **`LoreRim Glue v505 loaded - session NNNN`** (the quest is new to the
   save, so this comes from `OnInit` -> first `OnUpdate` -> `Maintenance`; on later loads from
   `OnPlayerLoadGame`);
2. in `Papyrus.0.log`: `[LRG] LoreRim Glue v505 loaded - save 505, session NNNN`, then
   `[LRG] maintenance done, version 505, ...`, and at ~25 s
   `[LRG] maintenance done, ... boot steps 11, aborted 0, modules ok/ok/ok, stalled 0`;
3. the same lines as `GAME` in `lorerim_glue.log`, then `lrg_npcstate` traffic as soon as you look at
   a CHIM NPC.

A `BOOT STALL: <script>` line names a module that did not come up; the rest of the glue works without
it and the line says which one to look at. `modules ...` in the 25 s line gives the state of all three.

If the owner wants a tidy save afterwards, saving once on 505 is enough for play; the old unbound
instances (and, if the VM keeps them, the two frozen stacks) are dead weight only. Removing them from
a save is an optional ReSaver clean-up, not something the glue needs.

---

## 7. Risks and what is not known

* **Mod-event scope.** The boot request relies on SKSE mod-event registrations being per form (one
  registration in `LRG_Main.Maintenance()` reaching `OnLrgBoot` in the three modules). That is how the
  project already treats `UnregisterForAllModEvents` (pt8), and each module registers the same event
  again in its own `OnInit`, which covers the first load in any case. If it were wrong, the symptom
  is unmistakable: on a **second** load, `BOOT STALL` for all three modules and `modules
  STALL/STALL/STALL` in the 25 s line - with the rest of the glue (snapshots, CHIM intake) still
  working. It is not expected.
* **The 20 s threshold** is a guess for this heavy list; a module that is merely slow gets `BOOT
  STALL` and then `BOOT LATE ... back on` - no harm beyond a few seconds without it.
* **Still not tested in the game.** Everything above is compile, bytecode and offline-test evidence.
  The Papyrus log of the next session is the test: it now has the logging it needs.
* **Out of scope, noted.** `FollowerSweep` / the follower repair still call Simple Follower
  Framework's `DialogueFollowerScript` directly from `LRG_Main`'s own stacks (steps 9 and the tick).
  A frozen script there would park `LRG_Main` the same way; nothing suggests it is, and changing it
  was not part of this pass.
