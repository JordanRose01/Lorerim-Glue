# pt9-build-driver — LANE B (driver + integrator), v0.5.0 round

**Status: DONE.** `compile.ps1` prints **OK** (0 errors, 0 warnings, all 9 `.pex` produced).
All 21 lane-B tasks (B1–B21) are built. Nothing in the never-do list, the emergency key or the
dry-run default was weakened — the dry-run default got *stronger* (it is now refused away, not just
defaulted on).

Lane B owns and edited: `LRG_Dialogue.psc`, `LRG_Main.psc`, `LRG_Profile.psc`, `LRG_MCM.psc`,
`MCM/Config/LoreRimGlue/config.json`, `MCM/Config/LoreRimGlue/settings.ini`,
`tools/stubs/MCM_ConfigBase.psc`.
**`tools/make_esp.py` was NOT edited** (no script is added to the ESP), so `esp_dump.py` was not
re-run and the `Seq` file is untouched, exactly as §0.1 requires. No other lane's file was opened
for writing.

Absolute paths:

* `C:\Users\Jordan\AppData\Roaming\Claude\scratch-workspaces\81ce68fa-07b1-4b77-bf43-737182b06d3c\6e558c34-6aca-475c-b527-d967c1b190e9\scratch-2026-09-21-8ca12a\glue\game\LoreRimGlue\Source\Scripts\LRG_Dialogue.psc`
* `…\Source\Scripts\LRG_Main.psc`
* `…\Source\Scripts\LRG_Profile.psc`
* `…\Source\Scripts\LRG_MCM.psc`
* `…\game\LoreRimGlue\MCM\Config\LoreRimGlue\config.json`
* `…\game\LoreRimGlue\MCM\Config\LoreRimGlue\settings.ini`
* `…\glue\tools\stubs\MCM_ConfigBase.psc`

---

## 0. Work list, as built

| # | Task | Where it landed |
|---|---|---|
| B1 | `CurrentVersion` 401 → **500**, header rewritten to PROTOCOL v0.5 | `LRG_Main.psc:20`, header block |
| B2 | CHIM speech forwarded to the driver for X1 | `LRG_Main.NoteSpeechToDriver()`, called from all three handlers; `LRG_Dialogue.NoteSpeech()` |
| B3 | Corner note gated on `bQuestHint` | `LRG_Main.HandleCommand()`, the shipped `note=` branch |
| B4 | `do=release` | `LRG_Dialogue.CmdSelectTopic()`, first branch after `do=award` |
| B5 | The seven passive calibration hooks (+ the eighth, `CalOpened`) | `Arm`, `Step`, `ReadList`, `StepPending`, `NoteSpeech`, `Finish`, `SendTopics`, `DoOpen` |
| B6 | `auto` resolution + the dry-run refusal | `LRG_Dialogue.ReadCalibration()`, called from `ReadSettings()` |
| B7 | `HandBack()` + resume + `ev=resume` | `HandBack`, `HandBackKind`, `MaybeResume`, `SendResume` |
| B8 | `ev=unhide` gains `layer= n= kind= resume=` | `SendUnhide()` |
| B9 | `SendCalib(src)` + `cal=` on `ev=open` | `SendCalib()`, `CalWireShort()` |
| B10 | `qal=` / `qgiver=` on `ev=facts` | `QalCsv()` |
| B11 | `qi=` on the snapshot | `LRG_Profile.BuildSnapshot()` |
| B12 | `dRewalks` / `dRewalkAborts` / `dResumes` | `DiagValue(9/10/11)`, `LogDiagnostics()` |
| B13 | MCM Calibration page + every key with its `settings.ini` line | `config.json`, `settings.ini` |
| B14 | MCM buttons + `CalStatus` property | `LRG_MCM.psc`, `tools/stubs/MCM_ConfigBase.psc` |
| B15 | `iMaxEntries` 0–24, `iProbePress` 0–36, six help corrections | `config.json` |
| B16 | `guard=` / `bounty=` / `cf=` on `ev=facts` | `CrimeWire()` |
| B17 | `svck=` — the price-list test | `SvcScan`, `SvcKindOf`, `WordCount`, `EntryCost` |
| B18 | `ev=lat` | `LRG_Main.LatStarted()` + `LRG_Dialogue.SendLat()` |
| B19 | `sv=` / `lf=` on the snapshot | `LRG_Profile.BuildSnapshot()` |
| B20 | The arrest hand-back through E3's `HandBack()` | `Arm()`'s `vis` chain + `BeginLeave()` |
| B21 | MCM "Services and truth" + "Diagnostics" pages | `config.json`, `settings.ini` |

---

## 1. GATE — the four things lane B had to prove

### 1.1 `compile.ps1` prints OK

```
rounds: 2 ; auto-stubs created: 22
compiled: LRG.pex, LRG_Dialogue.pex, LRG_DlgProbe.pex, LRG_DlgUI.pex, LRG_Main.pex,
          LRG_MCM.pex, LRG_OStim.pex, LRG_PlayerAlias.pex, LRG_Profile.pex
OK - .pex files copied to game\LoreRimGlue\Scripts (only the glue's own scripts);
     compiler reported 0 errors, 0 warnings
```

The full batch compiles: **no isolation build was needed.** Lane A landed its
`LRG_DlgProbe` calibration surface (`CalArm … CalPressReopen`, plus three ADDITIVE helpers) while
lane B was writing, and every signature matches plan §11.2 byte for byte. No declaration-only
stand-in was created and nothing temporary was committed. Papyrus rules kept: every docstring is
under 300 characters, long notes are `;/ … /;` block comments, and the `.pex` string guard passed
(no string over 500).

### 1.2 Every new MCM id, its `settings.ini` line, and the reader

Checked mechanically with lane B's own copy of what `test_mcm_wiring.php` (lane C, E4c) will
assert — **108 ids, 0 without an ini line, 0 unread**:

```
ids checked: 108
--- no settings.ini line ---                                      (none)
--- not read by any .psc ---                                      (none)
```

| MCM id | `settings.ini` | default | Read by |
|---|---|---|---|
| `bCalibPassive:Calib` | `[Calib] bCalibPassive = 1` | 1 | `LRG_Dialogue.ReadSettings` → gates all seven hooks |
| `bCalibActive:Calib` | `[Calib] bCalibActive = 0` | 0 | `LRG_DlgProbe.CalActiveWanted/CalArmBudget` (lane A); `LRG_MCM.OnSettingChange` re-arms it in-session |
| `iCalibRuns:Calib` | `[Calib] iCalibRuns = 3` | 3 | `LRG_DlgProbe.CalActiveWanted` (budget); `LRG_Dialogue.SelfTest` prints it |
| `bAutoTimings:Calib` | `[Calib] bAutoTimings = 1` | 1 | `LRG_Dialogue.ReadCalibration` (the two timing floors) |
| `bCalibOverride:Calib` | `[Calib] bCalibOverride = 0` | 0 | `LRG_Dialogue.ReadCalibration` (the refusal) |
| `bQuestInitiative:Quests` | `[Quests] bQuestInitiative = 0` | 0 | `LRG_Profile` → wire `qi=` (W7) |
| `iQuestInitiativeGap:Quests` | `[Quests] iQuestInitiativeGap = 600` | 600 | `LRG_Profile` → wire `qig=` (**lane B addition**, §4) |
| `bQuestHint:Quests` | `[Quests] bQuestHint = 1` | 1 | `LRG_Main.HandleCommand` (the `kind=hint` note) |
| `bQuestSummary:Quests` | `[Quests] bQuestSummary = 1` | 1 | `LRG_Profile` → wire `qx=` first digit (**lane B addition**) |
| `bQuestNext:Quests` | `[Quests] bQuestNext = 1` | 1 | `LRG_Profile` → wire `qx=` second digit (**lane B addition**) |
| `bHandBackNote:Dialogue` | `[Dialogue] bHandBackNote = 1` | 1 | `LRG_Dialogue.HandBack` (the corner note) |
| `bResumeAfterChoice:Dialogue` | `[Dialogue] bResumeAfterChoice = 1` | 1 | `LRG_Dialogue.MaybeResume` |
| `bServiceDialogue:Services` | `[Services] bServiceDialogue = 1` | 1 | `LRG_Dialogue.SvcScan`; `LRG_Profile` → wire `sv=` (W11) |
| `bServiceShortcut:Services` | `[Services] bServiceShortcut = 1` | 1 | `LRG_Profile` → wire `svx=` digit 1 (**lane B addition**) |
| `bCarriageByName:Services` | `[Services] bCarriageByName = 1` | 1 | `LRG_Profile` → wire `svx=` digit 2 (**lane B addition**) |
| `bNamePrices:Services` | `[Services] bNamePrices = 1` | 1 | `LRG_Profile` → wire `svx=` digit 3 (**lane B addition**) |
| `bCrimeManual:Services` | `[Services] bCrimeManual = 1` | 1 | `LRG_Dialogue.Arm` (the LETHAL rule) |
| `bLockedFacts:Truth` | `[Truth] bLockedFacts = 1` | 1 | `LRG_Profile` → wire `lf=` (W11) |
| `bTruthGate:Truth` | `[Truth] bTruthGate = 1` | 1 | `LRG_Profile` → wire `tg=` (**lane B addition**) |
| `bLatencyLog:Diagnostics` | `[Diagnostics] bLatencyLog = 1` | 1 | `LRG_Main.LatStarted` |
| `iLatencyWarnMs:Diagnostics` | `[Diagnostics] iLatencyWarnMs = 9000` | 9000 | `LRG_Main.LatStarted` (with a 0 → 9000 fallback) |

Changed existing lines: `[Dialogue] iMaxEntries = 16` → **`= 0`** (0 now means "measured", §3.4 of
the plan). No other ini line changed value.

`sCalibStatus:Calib` is deliberately **not** an id: it is a read-only `text` control bound to
`LRG_MCM.CalStatus` through `sourceType: PropertyValue`. Giving it an id would demand an ini line
for a value no ini can hold. See deviation D-B5.

### 1.3 Hand-traced proof: the emergency key gives back a working menu in EVERY state

The key's route is unchanged (`LRG_Main.OnKeyDown` → `ReleaseConvHold` →
`LRG_Dialogue.HandleEmergencyKey()`; false → `OpenVanillaDialogue()`). What changed is that every
hand-back now runs through `HandBack()`, so the trace has to cover the two new states as well.

| State when the key is pressed | What happens | Menu back? |
|---|---|---|
| `ST_IDLE`, nothing open | `HandleEmergencyKey` → `!IsSessionOpen` → `dlgState == ST_IDLE` so no `HardReset` → `LRG_DlgUI.IsOpen()` false → `forceVisible = true`, return **false** → `LRG_Main.OpenVanillaDialogue()` activates the crosshair NPC | ✔ a fresh visible menu |
| `ST_IDLE` but a list is still parked (probe, failed restore) | `NeedsUnhide()` is true via `IsHidden()` **or** `HolderX() > 4000` → `DoUnhide("key-idle")` → `Unhide` → `ShowList` → `ForceListState` → honest TAB warning | ✔ |
| stale state, loop stack lost > 10 s | `!IsSessionOpen` and `dlgState != ST_IDLE` → `HardReset()` (`Unhide`, flags cleared, session cleared) → then the `NeedsUnhide()` test above | ✔ |
| loop alive, **LISTENING / READING / DECIDING / PENDING / CLICKING / RESPONDING** | `reqEmergency = true` → next `Step()` (≤ 0.1 s, ≤ 0.5 s in PENDING) → `HandBack("key", false)` | see below |
| loop alive, **SUSPENDED** | `Step()` runs the request block **before** the state switch, so `reqEmergency` is consumed exactly as above | ✔ |
| loop alive, **CLOSING** | same; `HandBack` is reached and `GoManual` transitions out of CLOSING | ✔ |
| **NEW: ST_MANUAL after a hand-back**, nothing hidden | `HandBack("key", false)` → `manualResume := false` (disarmed, the player asked for control) → `GoManual` sees `ST_MANUAL` → `NeedsUnhide()` false → returns → `SendUnhide("key")` | ✔ the menu was already his |
| **NEW: ST_MANUAL after a hand-back**, something still parked | `GoManual`'s `ST_MANUAL` branch → `NeedsUnhide()` true → `DoUnhide("key")` | ✔ |
| **NEW: ST_READING after a resume** (`MaybeResume` hid it again) | `isHidden`/`guarded` true → `HandBack("key")` → `Guard(false)` → `GoManual` → not MANUAL → `NeedsUnhide()` true → `DoUnhide` | ✔ |
| during lane A's active calibration (menu hidden ≤ 200 ms inside `Arm`) | the loop is not yet polling, so the key waits one frame; lane A's CAL-2 restores unconditionally, and the `ST_IDLE`/parked rows above are the second net | ✔ |

**Two holes found and closed while tracing** (both were introduced by `HandBack`, both are now
fixed in this round's code):

1. `HandBack()` releases `guarded` *before* calling `GoManual()`. `GoManual`'s non-MANUAL branch
   tested the three flags (`isHidden || guarded || parked`), so a guarded-but-not-hidden layer
   could have skipped the restore. `GoManual` now tests **`NeedsUnhide()`**, the same predicate its
   MANUAL branch already used — and `NeedsUnhide()` ends on `HolderX() > 4000`, the one thing that
   cannot lie.
2. `HandBack()` calls `ClearRequest(false)`, which sets `reqReported = true`. A decision that was
   in flight (e.g. the CLICKING watchdog) would have been dropped with **no funcret at all** and
   CHIM would have waited forever. `HandBack` now emits `"Error: that moment has passed"` for an
   unreported `x` **before** clearing — so "exactly one funcret per x" survives the hand-back.
   The two sites that earn a better message (`do=show`, the dry-run `WOULD CLICK`) call
   `ReportOnce()` first, and `ReportOnce` is then a no-op inside `HandBack`.

### 1.4 Hand-traced proof: `ev=lat` sends at most ONE message per reply

State: `latAskStart`, `latAskStop`, `latTextAt`, `latTextNpc`, `latSent` (starts **true** = "no
reply is in flight"). All five are reset in `Maintenance()`, because `GetCurrentRealTime()`
restarts at 0 on every launch.

| Event | Effect |
|---|---|
| `CHIM_SpeechStarted(player)` | `latAskStart = now` |
| `CHIM_SpeechStopped(player)` | `latAskStop = now`; **`latSent = false`** (arms one measurement); `latTextAt = 0`; `latTextNpc = ""` |
| `CHIM_TextReceived(npc)` | `if !latSent && latTextAt <= 0` → `latTextAt = now`, `latTextNpc = npc`. The **first** text of a reply is the mark; later sentences of the same reply cannot move it, which is exactly the TTS gap this measurement exists to show |
| `CHIM_SpeechStarted(npc)` | `LatStarted()` |

`LatStarted()` in order:

1. `if latSent || latTextAt <= 0 || latAskStop <= 0` → **return** (nothing armed).
2. **`latSent = true` immediately**, before every other test. This is the once-per-reply rule: any
   later sentence of the same reply, and any refusal below, consumes the arming rather than
   retrying.
3. staleness: `> 60 s` since the text, or `> 180 s` since the player stopped → return, no message.
4. `bLatencyLog:Diagnostics` off, or `bDlgWire:Dialogue` off → return, no message.
5. `latTextNpc != who` → return (her words and this voice are two different people).
6. one `SendLat()`, one `LAT` log line, and a `LAT SLOW` line + corner note only over the warn
   threshold.

**The case the gate names — "the text arrives but the voice never starts":** step 4 of the table is
never reached, `latSent` stays `false`, and **no message is sent at all**. The stamps are not left
dangling: the next `CHIM_SpeechStopped(player)` clears `latTextAt` and re-arms cleanly, and if her
voice starts much later for an unrelated line, step 3 (60 s) or step 5 (name mismatch) refuses it.
CHIM raises `SpeechStarted` once per **sentence**, and every sentence after the first hits step 1.

Measured cost: one `lrg_dlg` message per reply, no LLM, and the plan's own measurement of that
request class is 2–7 ms server-side.

---

## 2. What each block actually does

### E1 — the self-calibrating driver

**The seven hooks** (`sCalibPassive` gates every one; each is `if p` guarded and passes values the
driver already holds):

| Hook | Call site | Extra cost |
|---|---|---|
| `CalArm(apd0, fam, items, plat, hidden, glueOpened)` | `Arm()`, straight after the pristine `apd0` read and `GuardReset()` | 0 |
| `CalPoll(state, n, timerId, sub)` | end of `Step()` | ≤ 1 native, and only every 5th poll, and only while `timer` is still unanswered (asked once per session into `calTimerWanted`) |
| `CalLayer(nTotal, eHead, readMode)` | `ReadList()`, **only** on the branch where the signature changed | lane A's census, once per layer |
| `CalPending(before, after, changed)` | `StepPending()`'s `pushed` branch | 0 |
| `CalSpeech(who, at)` | `NoteSpeech()` ← `LRG_Main.NoteSpeechToDriver()` ← all three CHIM events | 0 |
| `CalClose(why, layer, pending, anyClick)` | `Finish()` | 0 |
| `CalPayload(chars, part2, tailChars)` | `SendTopics()`, on the payload it just built | 0 |
| *(+)* `CalOpened(tries, ms)` | `DoOpen()`, after the Activate retry loop | 0 — P0b's passive half |

`n` for `CalPoll` is the **live** `EntryCount()`, captured into `lastN` at the five places the
driver already reads it, so the hook adds no count read of its own. `sub` is the subtitle
`HarvestLine()` already read this poll (`calSub`).

**The active pass** runs from `Arm()` after `SendOpen()` and the arming log, **before the state is
set** — and, importantly, on **both** branches, because the owner's evening is three *ordinary*
conversations with one switch on, not sessions the glue is driving. `ActiveCalibSafe()` adds only
the facts lane A cannot see from outside (`crit == 2`, `inScene`, `isHidden`, `parked`,
`hideFailed`, no speaker). It deliberately does **not** test `Utility.IsInMenuMode()`, matching
lane A's own reasoning: nobody has measured what it returns from inside a Dialogue Menu, and a
`true` would silently kill the whole pass.

**`auto` resolution** (`ReadCalibration()`, inside `ReadSettings()`'s 5-second cache, so the probe
is asked at most every 5 s):

| Setting | MCM value wins | `0`/auto resolves to |
|---|---|---|
| `iReadMode` | 3 / 4 | `CalGet("rm")` when 3 or 4; otherwise today's live `CalibrateRead()` |
| `iCountMode` | 1–3 | `CalGet("cm")` → `LRG_DlgUI.SetCountMode()` |
| `iClickRoute` | 1 / 2 | `CalGet("route")` in `ChooseRoute()`, **before** the `fam` table; 0 still never guesses |
| `iMaxEntries` | 1–24 | `900 * 12 / ms3`, clamped 4–24 (one layer's read under ~0.9 s) |
| `fLineSettle` | the MCM value is the **floor** | `timer == 2` → at least 0.8 s; `timer == 1` → at least 0.3 s |
| `fDecideTimeout` | floor | at least `1.5 + (ms3 + tail)/1000` |
| `iBranchInput` | 1 / 2 | `x1 == 1` → by voice; `x1 == 2` → show me the choice |
| `iHideMode` | — | no auto, as specified |

**The refusal**, in `ReadCalibration()` right after `sDryRun` is read:

```
dlg dry-run FORCED: calibration is not green - missing <csv> (Calibration page has an override)
dlg dry-run released: the calibration is green
```

plus a one-off `Debug.Notification`. `bMenuless` itself is never forced. `bCalibOverride:Calib` is
the documented way past it. **If `LRG_DlgProbe` is not attached at all**, the gate declares itself
green and logs why once — a missing script must never lock the owner out of a feature (plan R3).

`SelfTest()` now calls lane A's `CalLogSummary()` (the `CALIB SUMMARY` / `CALIB GATE` pair) and
adds the driver's own half: green, missing, rm/cm/route/x1, whether dry-run is being forced, the
override, the active budget, and the two passive switches. It runs on every load and on every
topic-dump press.

### E2 — quest awareness, game half

`QalCsv()` walks `PO3_SKSEFunctions.GetRefAliases(npc)` → `Alias.GetOwningQuest()` /
`Alias.GetName()`, keeps at most **6** aliases whose quest `IsActive()` and has a non-empty
EditorID, `CleanForWire`s each name, strips the two separators this value uses (`:` and `,`), and
clips to 24 characters. `qgiver` is set in the same pass. Both ride `ev=facts`, which is already
throttled to 60 s per NPC.

`CrimeWire()` is the W10 block: **one** `GetCrimeFaction()` decides it. No crime faction →
`;guard=0;bounty=0;cf=-` and that is the whole cost. Otherwise `IsGuard()` and `GetCrimeGold()`.

The corner hint is the shipped dormant `note=` path, now gated game-side on `bQuestHint:Quests`
**only for `kind=hint`** — any other note (a refusal reason, a service note) is not a quest hint
and is still shown, which is what that branch was built for in 0.4.

### E3 — the hand-back and the resume

`HandBack(why, mayResume)` is the single path. Order: close any in-flight decision with a funcret →
`ClearRequest` → release the guard while the menu is still open → arm/disarm resume → remember
`manualAt` / `manualSig` → `GoManual()` (which unhides whenever `NeedsUnhide()`) → one `ev=unhide`
either way → corner note when `bHandBackNote` → the log line:

```
handback sid=<sid> why=<reason> layer=<n> n=<n> kind=<root|closed|unknown> note=<0|1> resume_armed=<0|1>
resumed  sid=<sid> layer=<n> after=<ms>
```

Every former `GoManual` call site is routed: `key`, `branch_show` (was `show`), `read-failed` ×3,
`watchdog` ×5, `unverified` ×2, `lethal` (was `critical`), `dryrun` (the WOULD-CLICK hand-back).
`GoManual()` stays as the primitive `HandBack` uses.

`MaybeResume()` runs from `StepManual()` only after `ReadList()` reports a **changed signature** —
i.e. the player really clicked something. Gates: `manualResume` ∧ `bResumeAfterChoice` ∧ `bMenuless`
∧ `!bDlgDryRun` ∧ `!inScene` ∧ `crit != 2` ∧ `smartSafe` ∧ **calibration green** ∧ `IsOpen()` ∧
`eHead > 0` ∧ `sig != manualSig`. It re-guards and re-hides, and if `Hide()` refuses it releases the
guard and leaves the menu with the player — a session without a working restore is never started.
**No `Activate` is called, so there is no second greeting.**

### E4/E6 — `do=release` and the arrest rail

`do=release` is handled before every other verb, with its own `x` de-duplication, and is gated by
the hold's own switch and **nothing else**: not by `bMenuless` (it has nothing to do with the menu)
and not by dry-run (leaving her frozen is the unsafe state). `ReleaseConvHold()` is already
idempotent. A 0.4 script answers one `Error: unknown command`, which is what the plan expects.

The arrest rail: `Arm()`'s visibility chain now reads
`crit == 2 && (sCritical == 0 || sCrimeManual)` → `vis = "lethal"`, and that branch calls E3's
`HandBack("lethal", false)` — **no new hand-back code**, and `why=lethal` is in the log for test
V6. `BeginLeave()`'s `crit == 2` path is the same call. `resist arrest` is not selectable at any
setting because nothing in the driver selects on a LETHAL session at all.

### E6 — the price-list test (`svck=`)

`SvcScan()` runs once per changed layer. It counts entries of ≤ 3 words (price parenthetical
excluded, char scan capped at 40) and how many of those carry a parsed cost, then reads a **single
concatenated haystack** of the first 8 entries for the keyword kind — about two dozen native `Find`
calls per layer instead of several hundred, each keyword tried in its sentence-middle and
sentence-initial spelling because Papyrus has no `ToLower`. Order: **crime first** (the one kind
where being wrong matters), then train, inn, ferry, carriage, barter.

A session remembers the kind its **root** proved (`sessSvc`), because a CFTO destination layer says
nothing about carriages — "Morthal. (35 gold)" has no word to go on. A closed price list with no
word of its own inherits the session's kind. Nothing in the driver branches on any of it; the log
line is

```
SVC npc=<name> kind=<inn|carriage|ferry|train|barter|crime|-> layer=<n> priced=<n>/<n>
```

and the server re-derives and reports drift.

### E8 — `ev=lat`

`ask` / `reply` / `voice` / `total` are defined in §3 (deviation D-B2 explains `ask`). One send per
reply from `LRG_Main.LatStarted()`, formatted by `LRG_Dialogue.SendLat()` where every other `ev=`
lives. Log:

```
LAT npc=<name> ask=<ms> reply=<ms> voice=<ms> total=<ms> first=<0|1>
LAT SLOW npc=<name> total=<ms> over=<iLatencyWarnMs>
```

`first=1` marks the **L1 cost**: `Finish()` calls `LRG_Main.NoteFirstBusiness(npc)` in the same
place it sends `lrg_dlgtalk` (the extra paid reply `bIntentOpen` buys on first contact), and the
reply that follows within 120 s is flagged. That is the first time the owner can *see* what that
setting costs him instead of reading about it.

---

## 3. Deviations from the plan — every one, with its reason

| # | Deviation | Why | Risk if lane C disagrees |
|---|---|---|---|
| **D-B1** | **Four additive snapshot keys the plan does not list: `qig=`, `qx=`, `svx=`, `tg=`** (beside W7's `qi=` and W11's `sv=`/`lf=`) | §3.3 and §21.2 mandate MCM controls for `iQuestInitiativeGap`, `bQuestSummary`, `bQuestNext`, `bServiceShortcut`, `bCarriageByName`, `bNamePrices`, `bTruthGate` — but the wire gives none of them a carrier, so all seven would be **dead controls**, which is the exact scar E4(c) exists to prevent. Each follows the shipped `chk=` idiom (`qx`/`svx` are digit strings, `tg` is its own key so `lf` keeps its `0|1` shape byte for byte). Absent → the server keeps its config default | None: unknown keys are ignored on both sides. Lane C may read them or not |
| **D-B2** | **`ask=` is the player's own line as CHIM voiced it (speech-started → speech-stopped), not "player stopped → request left"** | The game has exactly three CHIM speech events and none of them marks the request leaving. `reply`, `voice` and `total` are **exactly** §20.4's definitions (`total = reply + voice`, zeroed at the player's last word). `ask` is the nearest honest observable and it is the part of the wait his own line accounts for | Cosmetic: `ask` is diagnostic and `total` is unaffected |
| **D-B3** | **`svck=` also rides `lrg_topics` part=1** (before the LAST `e=`), as well as `ev=open` per W13 | `ev=open` is sent from `Arm()`, **before a single entry has been read**, so its `svck` can only ever be `-` on a fresh session. The `lrg_topics` copy is the one that describes the layer it belongs to. The `ev=open` key is still sent exactly as W13 specifies | None: additive, `e=` stays last, unknown key ignored |
| **D-B4** | **The `ev=lat` corner note is a direct `Debug.Notification`**, not the `note=` path | `note=` is a *server→game* key on a command; at the moment her voice starts there is no command in flight to hang it on, and inventing one would be a second message per reply — the opposite of E8 | None |
| **D-B5** | **The calibration status line has no `id`** — it is a `text` control bound to `LRG_MCM.CalStatus` via `sourceType: PropertyValue` + `sourceForm LoreRimGlue.esp\|0x801` | A read-only string has no `settings.ini` line to have, so giving it the id `sCalibStatus:Calib` would fail lane C's own "every id has an ini line" assertion. The same picture is in the log as `CALIB SUMMARY` / `CALIB GATE` on every load | None |
| **D-B6** | **The five buttons use the object form `"form": {"pluginName": …, "formId": "0x801"}`**, not §3.2's string `"LoreRimGlue.esp\|0x801"` | The object shape is MCM Helper's documented one and matches `sourceForm`. **Unproven in game either way** — the guaranteed fallback is intact and named on the page itself: presses 33, 35, 17, 23, 36 | If the buttons turn out dead in game, delete the five controls; nothing else depends on them |
| **D-B7** | **`dLayersAssisted` is no longer incremented by `Arm()`'s assisted arming**, only by the one transition `GoManual()` makes (which after this round is only ever reached through `HandBack()`) | §12 B12 requires exactly this. The counter now means "layers handed back mid-session", which is what its name promises; what kind of session it is, is on the arming line as `visible=<reason>` | Counter values drop against 0.4 logs — expected, not a regression |
| **D-B8** | **`dRewalks` / `dRewalkAborts` count picks the server labels `kind=rewalk`** | The re-walk is the server's (it replays steps as ordinary picks); the game has no way to recognise one otherwise. Costs one string compare per click and stays at 0 for as long as lane C never labels one — which is the honest reading of "off by default". `layers_rewalked=` in `dlg diagnostics` now prints a real number instead of the literal `server-side` | None: if lane C never sends `kind=rewalk`, the counters read 0 and the line is still honest |
| **D-B9** | **`ev=calib` on load is sent from `LRG_Main.Maintenance()` after `prb.Maintenance()`**, not from `LRG_Dialogue.Maintenance()` | `LRG_Main` calls the driver's `Maintenance()` **before** the probe's, and the probe resets its per-session state and re-arms the active budget in its own. Sending after it means `runs=` on the wire is the post-reset value | None |
| **D-B10** | **Two hardening fixes inside `GoManual()` / `HandBack()`** (see §1.3): `NeedsUnhide()` instead of the three flags, and a funcret for an in-flight decision before `ClearRequest` | Both are holes `HandBack` would otherwise have opened. Neither weakens a rail; both strengthen one | None |
| **D-B11** | **Lane A's three ADDITIVE helpers are used**: `CalDirty(bool)` for W1's "has anything changed", `CalOpened(tries, ms)` for P0b's passive half, `CalLogSummary()` in `SelfTest()` | Lane A shipped them explicitly for the driver and documented them as such. `CalDirty` is cheaper and more accurate than comparing `CalWire()` before and after | They are in lane A's shipped file; if lane A removes one the compile fails loudly |

**Nothing else deviates.** The wire additions W1–W14 are implemented as written (with D-B2/D-B3
noted above), the file ownership was respected exactly, and no rail was relaxed.

---

## 4. Wire, as the game now emits it

```
lrg_dlg  ev=open   …;bi=;rw=;rwd=;io=;cal=rm3cm1rt2g1;svck=<kind|->
lrg_dlg  ev=unhide …;why=;layer=<n>;n=<n>;kind=<root|closed|unknown>;resume=<0|1>
lrg_dlg  ev=resume sid=;ref=;npc=;layer=<n>;after=<ms>
lrg_dlg  ev=calib  v=1;ref=;npc=;src=<load|passive>;gate=<0|1>;runs=<n>;miss=<csv|->;k=<raw LAST>
lrg_dlg  ev=facts  …;qal=<QuestID:Alias,…|->;qgiver=<0|1>;guard=<0|1>;bounty=<int>;cf=<hex8|->
lrg_dlg  ev=lat    v=1;ref=;npc=;ask=;reply=;voice=;total=;sid=<sid|0>;first=<0|1>
lrg_topics …;svck=<kind|->;e=<LAST>
lrg_npcstate …;ql=;qi=;qig=;qx=;sv=;svx=;lf=;tg=;dist=;hold=;…
ExtCmdLRG_SelectTopic  do=release   -> "Noted."
```

`k=` on `ev=calib` is **raw and LAST** by contract and is never `CleanForWire`d; lane A's
`CalWire()` guarantees it holds no `;`. Every other value goes through `CleanForWire`.

## 5. What lane B did NOT do, and what is left for the owner

* `make_esp.py` untouched → **no ESP regeneration, no `esp_dump.py` run**, per §0.1.
* `manifest.json` → 0.5.0 and `PROTOCOL.md` → v0.5 are **lane C's** (C1, C17). Not touched.
* `test_mcm_wiring.php` is lane C's file and does not exist yet; lane B ran the equivalent
  assertions itself (§1.2) and they pass on all 108 ids.
* The five MCM buttons are **unproven in game** (D-B6). The page itself names the slider fallback,
  so the evening works either way.
* `bMenuless` still ships **0** and `bDlgDryRun` still ships **1** — and dry run is now *refused
  away* until the Calibration page is green, which is a stronger default than 0.4.1 had.
* If the owner runs with CHIM's player TTS off, no `CHIM_SpeechStarted/Stopped` ever fires for the
  player, so `ev=lat` has no zero to measure from and stays silent. Worth one line in the notes.

---

# PT9 FIX PASS — GAME SIDE (lane B fix round, 2026-09-22)

Scope: `glue\game\LoreRimGlue\**` only (Papyrus sources + `MCM\Config\LoreRimGlue\config.json`
+ `settings.ini`). `tools\make_esp.py` was in scope and is **not touched**, so no ESP regeneration
and no `esp_dump.py` run. Versions: manifest 0.5.0 / `LRG_Main.CurrentVersion` 500 / PROTOCOL v0.5
(additive only) are lane C's and were left alone by this pass.

This section is appended as the work goes, so the order below is the order the defects were fixed.

## 0. The defect list this pass owns

| # | Grade | One line | Verdict |
|---|-------|----------|---------|
| C1 | critical | the leave-dry-run gate is circular (`park`) | fixed |
| C2 | critical | the two manual calibration presses cannot be run | fixed |
| M1 | major | CAL-2's 200 ms verdict is tested before the evidence, so `hide` is never recorded | fixed |
| M2 | major | `actn` written by two collectors with two meanings | fixed |
| M3 | major | the X1 recorder misattributes closes and reads a stale count | fixed |
| M4 | major | seven MCM controls dead (`qig`/`qx`/`svx`/`tg` have no server reader) | game half already correct - see §9 |
| m1 | minor | `rm == -3` re-probes and re-warns on every layer for ever | fixed |
| m2 | minor | only one active test per game load when `bCalibPassive = 0` | fixed |
| m3 | minor | `hidems` / `actry` / `actms` collected and never surfaced; `ms4` has no writer | fixed |
| m4 | minor | `bCalibActive` help lies; `calOff` reset by any `:Keys` change | fixed |
| m5 | minor | X1 `routed=self` claimed for any NPC speech | fixed |
| m6 | minor | four bare `UI.*` calls outside `LRG_DlgUI` | fixed |
| m7 | minor | A5 leaves a movie clip at depth 9731 for ever | fixed |
| m8 | minor | nothing tells the owner the active pass stopped because the budget is spent | fixed |
| m9 | minor | the corner note points at a Calibration button that does not exist | fixed |

## 1. Files touched

| File | What changed |
|---|---|
| `glue\game\LoreRimGlue\Source\Scripts\LRG_DlgProbe.psc` | C1, C2, M1, M2, M3, m1, m2, m3, m5, m8, m9 - the calibration engine |
| `glue\game\LoreRimGlue\Source\Scripts\LRG_DlgUI.psc` | m7 (A5 clip removal), two new store keys in `CalClear()` |
| `glue\game\LoreRimGlue\Source\Scripts\LRG_Dialogue.psc` | m2 (`CalNewOpen`), one log-spam minor in `CalibrateRead()` |
| `glue\game\LoreRimGlue\Source\Scripts\LRG_Main.psc` | m6 - the four bare `UI.*` calls |
| `glue\game\LoreRimGlue\Source\Scripts\LRG_MCM.psc` | m9 - the sixth Calibration button (`CalPressClickB`) |
| `glue\game\LoreRimGlue\MCM\Config\LoreRimGlue\config.json` | C2, m4/m8, m9 - the Calibration page and two Dialogue-page helps |
| `glue\game\LoreRimGlue\MCM\Config\LoreRimGlue\settings.ini` | `iCalibRuns` 3 -> 5, the `iKeyProbe` and `iProbePress` comments |
| `glue\game\LoreRimGlue\Scripts\*.pex` | recompiled |

`tools\make_esp.py` was in scope and is **untouched**: no ESP change, so no `esp_dump.py` run and
no new form. The sixth button needed no form - MCM Helper reaches `LRG_MCM` by the existing
`0x801` and a function name. `compile.ps1` prints **OK, 0 errors, 0 warnings**, three times over
the round. `config.json` re-parses: 108 ids, all unique - unchanged, so `test_mcm_wiring.php`'s
input set is the same one lane C last saw.

---

## 2. The two criticals

### C1 - the gate could never go green (`park`)

`park` was one of eleven gate rows and **only a hidden, glue-driven session can write it**:

```
CalMissing():2619   park must be 1 or 2
  <- CalPending()   the only writer
  <- StepPending()  the only call site           (LRG_Dialogue 2005)
  <- ST_PENDING     needs glueOpened && isHidden (1976)
  <- isHidden       only set behind !sDryRun     (1430, and MaybeResume 2576 -> 2564)
  <- sDryRun        forced back ON by ReadCalibration() while the gate is red (632-641)
```

So the row demanded the one kind of session the row itself forbade, and the only exit was
`bCalibOverride` - switching the safety net off in order to satisfy the safety net. The row also
carries **no safety information**: both verdicts (1 = the pushed list arrived, 2 = it was dropped
and re-pushed) are green, which lane A's own deviation D6 says in as many words.

**Fixed** by deleting the four lines from `CalMissing()`. `park` is still collected by
`CalPending` / `CalParkPoll`, still cleared by `CalClear()`, and still rides `CalWire()` - it is
now a MEASUREMENT, next to `x1`, `col`, `row`, `inj`, `pay`, `ms3`, `tail`. The gate is **ten
rows**: counting, reading, progress timer, hide round trip, input guard, click route, state
read-back, vanilla menu after a hide, Smart Talk settings, menu layout. `CalAnswered()` returns
out of 10, `CalStatusText()` says "of 10", `CALIB SUMMARY` says `answered=N/10`, and the file
header's gate paragraph was rewritten. `CalGreen()` is unchanged in shape - still
`CalMissing() == ""`.

PLAYTEST9 section 3 step 4 and section 6 ("when it reads green, set bMenuless = 1") are reachable
again. The evening is now nine rows ordinary play answers plus the ones the buttons arm - see C2.

### C2 - neither manual press could be run

Two walls, both hit at once:

1. **The buttons.** `LRG_MCM` calls `p.CalPressClick(false)` / `p.CalPressReopen()` synchronously
   from inside the MCM. Both began with "refuse unless `LRG_DlgUI.IsOpen()`". An MCM page is open
   **only while the Dialogue Menu is closed**, so both refused every time, on every install.
2. **The documented fallback.** `config.json` named presses 33/35/17/23/36 on the slider as the
   way out, but `RunStep()` refused everything unless `bProbe:Dialogue = 1`, and `Maintenance()`
   returned before `RegisterForKey` when `bProbe` was 0. Shipped defaults: `bProbe = 0`,
   `iKeyProbe = 0`. Neither is mentioned on the Calibration page.

**Fixed three ways, all of them:**

* **The buttons now ARM a one-shot** (`CalShotArm`). With the menu closed the press records what
  was asked for (`cShot` 1 = click route A, 2 = route B, 3 = the re-open read), tells the owner in
  the corner what to go and do, logs `CALIB armed press <n>`, and returns. The next
  `OnMenuOpen("Dialogue Menu")` schedules `RegisterForSingleUpdate(0.5)`; `OnUpdate` waits up to
  3 s for the topic list to exist and then calls the very same press, which now sees an open menu
  and runs its normal body. State is per GAME session (members, reset in `Maintenance()` and
  `CalForget()`), single-use, and times out after 600 s.
* **`RunStep()` lets 33-36 through with `bProbe = 0`** - those four are the Calibration page's own
  tests, and the calibration is designed to run with the probe disarmed. Everything else still
  refuses with "probe is off (bProbe)".
* **The key is registered whenever `iKeyProbe > 0`**, `bProbe` or not (the block moved above the
  `bProbe` return). The menu listener and the `PlayMenuTopic` mod event stay behind `bProbe`:
  those are the measuring probe proper.

Refusals kept, every one: `ClickAllowed()` (guard / in a scene / crime gold), menu open, entries
present, a read mode that works, `readOnly` restored on every path, and the unconditional
`GiveMenuBack()` at the end of the click body. **Two added:** the armed fire steps aside when
`LRG_DlgUI.IsHidden()` (the driver is holding that list - rule 10 forbids two owners of one
restore), and `cShotRun` stops a press started by the fire from arming itself again if the menu
shuts under it. `CalPressReopen()` keeps its own `!IsOpen()` refusal, because its mash loop treats
"the menu closed" as "something moved" and would otherwise have recorded `reopen=1` against a menu
that was never open.

Hard rail check: CAL-1 says *nothing auto-calibrating may click an entry*. Nothing auto-calibrating
does. The one-shot is a human pressing a button and being told to go and talk to somebody; the
click happens in the conversation he was sent to have, and the log says so on both halves.

---

## 3. The three majors

### M1 - CAL-2's verdict judged the clock before the evidence

`CalActiveA1` tested `ms > 200` **first**. By the project's own cost model that branch always
wins: every `UI.Get*/Set*` is a delayed native (`LRG_DlgUI` 62-63), the driver sizes its own entry
cap at `(900*12)/ms3` (~16 entries in 0.9 s, i.e. tens of ms per UI call), and the shipped window
held ~30-35 of them. So on every machine: the list demonstrably came back (`back` was computed at
2269 and thrown away), `hide` was never written, the budget was spent, the pass was disabled for
the session, and the gate row stayed red for ever.

**Fixed in three parts.**

1. **Evidence first, clock second.** The `stillOpen` / `hid` / `lu` / `back` ladder runs first and
   writes `hide`; the overrun test follows and only warns + `CalActiveDisable`s. **The 200 ms
   contract is not relaxed** - an overrun still stops the whole active pass for the game session,
   which is CAL-2's teeth. It just no longer throws away the answer it measured.
2. **The window is shorter.** Two things sat between `Hide()` and `Unhide()` that are not part of
   the round trip: a six-iteration `HolderX()` loop and `SubtitleShown()` - up to seven extra
   frames with the player's topic list off screen, for measurements the feature does not use. The
   loop keeps its exact meaning (`flash` = reads that still saw the list in place) with a cap of
   **one**; `sub_shown_while_hidden` is no longer taken here and the `P6-H2` line prints `-` for
   it. Probe press 15 (`PressP6`, H1) still measures it for anyone who wants it.
3. **The constant can now be set from a real reading.** `hidems` reaches `CalWire()` and
   `CALIB SUMMARY`, so the next round can read the true cost off this install instead of guessing.
   `Hide`/`Unhide` were NOT given a `fam` argument - those signatures are frozen and every caller
   would have had to change; the two `SwfFamily()` re-reads stay.

**`CalActiveA4` had the identical shape and is fixed identically** - and it mattered more: `rm` is
a gate row, and on an install where mode 3 reads empty A4 is its *only* answer, so an overrun
branch tested first would have locked the gate shut on exactly that install. A4 also gained the
`stillOpen` guard A1 already had (a menu that closed mid-test is not proof that mode 4 is
unreadable) and now writes `ms4`, which was on the wire with **no writer anywhere in the project**;
it is timed the way `ms3` is - cost per twelve reads - so the two are comparable.

**The test ORDER changed too** (a separate major). `CalNextActive()` ran A2, A3, A5, A1, A4 -
cheapest first - with a budget of 3, so the two tests that hide anything were never reached:
`hide` (a gate row) stayed red while `apdr` and `inj` (measurements) were answered on every
install. New order: **A2 (rb, gate), A4 (rm, gate, queued only when mode 3 read empty), A1 (hide,
gate), A3 (apdr), A5 (inj)** - all three gate-row tests before the two measurements, and A4 ahead
of A1 so a hide-overrun disable cannot starve the other gate row. `iCalibRuns` ships **5** and the
in-code default in `CalActiveWanted()` matches.

Expected shape of the owner's evening now: conversation 1 answers `rb`, conversation 2 answers
`hide` (and, if the round trip really costs more than 200 ms, warns and stops the pass for that
play session - with the answer kept). `apdr` and `inj` follow in a later session; neither hides
anything, so neither can overrun.

### M2 - `actn` had two meanings

`CalArm` did `actn += 1` on **every** glue-opened session; `CalOpened` read `actn + 1` and warned
at exactly 3. So "try bActivateDefaultOnly" fired on the third glue-opened conversation whether or
not a single `Activate` had ever needed a second try - and, because the test is `== 3`, could then
never fire again. **Fixed:** `CalOpened` counts into its own key `actbad`, added to
`LRG_DlgUI.CalClear()`. `actn` keeps its own meaning and gains a reader again - it is the
denominator the warning now prints ("3 times out of N").

### M3 - the X1 recorder blamed the driver's own clicks, and read a stale count

Two bugs in the one collector whose verdict decides `iBranchInput` for the whole install:

* `CalClose` **ignored the `asWhy` and `abAnyClick` it was handed** and recorded `died` for any
  session end inside the 6 s window - including the driver's own farewell click, a refusal, a
  hand-back and a handoff to a shop. **Fixed:** only `asWhy == "external" && !abAnyClick` is
  evidence; everything else settles as `unknown` (bucket `x1u`, which already existed and is
  excluded from the 3-sample verdict). The `no-close` path in `CalArm` settles as `unknown` too,
  which is right - a session that was never closed proves nothing.
* `CalX1Poll` judged survival from `LRG_Dialogue.lastN`, which is assigned in five places, **none
  of them in `StepDeciding` / `StepSuspended` / `StepResponding`** - exactly where those six
  seconds are normally spent. **Fixed:** one fresh `LRG_DlgUI.EntryCount()` at the 6 s mark
  (0 when the menu is closed). Bounded by construction: the window fires once and disarms.

---

## 4. The minors

| # | Fix |
|---|---|
| m1 | `CalReadProbe` returns early once `rm == -3` **and** the new counter `rmN` has reached 3. The cheap recovery above it (`LRG_DlgUI.ReadMode()`, a StorageUtil read) is deliberately left in place, so a mode the UI layer proves later is still picked up at once - which a bare `|| rm == -3` early return would have thrown away. The `CalWarn` fires on the first sample only. `rmN` is in `CalClear()`. |
| m2 | New `LRG_DlgProbe.CalNewOpen(bool abHidden)` - two member assignments, no menu write, no store write - called from `LRG_Dialogue.Arm()` on the `else` branch of `if sCalibPassive`. `cActiveRan` and `cArmHidden` are now cleared on every arming, so `bCalibPassive = 0` + `bCalibActive = 1` no longer means one active test per game **load**. |
| m3 | `hidems`, `actry`, `actms` appended to `CalWire()` (last, so a truncation anywhere would cost the three least important pairs); `hidems` and `runs` added to `CALIB SUMMARY`; `ms4` given its writer in A4. |
| m4 | `Maintenance()` resets the per-game-session state - `calOff` included - **only when `m.SessionTag() != calSid`**. `calSid` was written at :135 for exactly this comparison and never read. The MCM calls `Maintenance()` on any `:Keys` change and on two toggles, and each of those used to re-enable a pass a CAL-2 overrun had disabled. `CalArmBudget()` still runs on **every** call, because it is keyed on the switch's own 0 -> 1 edge and the MCM is what delivers that edge. |
| m5 | `CalSpeech` logs `routed=npc-spoke`, not `routed=self`. `LRG_Main` forwards every CHIM NPC speech event for every agent and `NoteSpeech` filters only on "is a session live", so all the event ever proved is that *some* NPC spoke inside the window - which is what deviation D15 promised the recorder would not overstate. |
| m6 | `LRG_Main` 324/945/1135/1351 now go through `LRG_DlgUI.TextInputOn()` and `LRG_DlgUI.OtherMenuOpen("Dialogue Menu")`. **Every `UI.*` call in the project is back inside `LRG_DlgUI`** - `grep -n "UI\." LRG_Main.psc` returns nothing, and the DLL seam of build plan 11 has one file to replace again. |
| m7 | `LRG_DlgUI.CreateProbeClip()` invokes `_root.lrgProbe.removeMovieClip` after the read-back. `createEmptyMovieClip` REPLACES whatever holds depth 9731, and the clip was left in the live Dialogue Menu of an ordinary conversation for ever, for an answer nothing uses. One extra native, once per install, on an opt-in test. |
| m8 | New `CalRunsWord()` on the status line: "active pass used N of M, untick and tick it again for more", or "the active pass is stopped for this game session, see the log" when `calOff`. The missing-rows list is what gets clipped if the 200-character line runs long, never the note. `bCalibActive`'s help no longer claims it switches itself off. |
| m9 | Sixth Calibration button, **1b. The click test, the other way round** -> `LRG_MCM.CalPressClickB()` -> `p.CalPressClick(true)`. The corner note now says "use button 1b, the other click, on the Calibration page", which exists; `config.json`'s claim that "the status line offers the other click" is gone. |
| extra | `LRG_Dialogue.CalibrateRead()`'s "read mode 3 is empty and the menu is the player's" line is logged **once per session** (`readNoteLogged`). `StepManual` calls `CalibrateRead` on every poll while `readMode` is not 3/4, so on a visible session with an unreadable list it wrote one fire-and-forget HTTP log line per poll, for the whole conversation. |

---

## 5. M4 - the seven dead MCM controls: the game half is already right

This one is `both`, and **the game half needs no change**. `LRG_Profile.psc` writes all four
carriers correctly and additively (a missing key means "keep the server's own config default", so
a 0.4 script still changes nothing). Verified by reading, line by line:

| Key | Written at | Shape on `lrg_npcstate` | MCM controls behind it |
|---|---|---|---|
| `qi` | `LRG_Profile.psc:499` | `0/1` | `bQuestInitiative:Quests` |
| `qig` | `:500-506` | 2-4 digits, already clamped **60..1800** in Papyrus, 0 -> 600 | `iQuestInitiativeGap:Quests` |
| `qx` | `:507-508` | 2 digits, `<summary><next>` | `bQuestSummary`, `bQuestNext` |
| `sv` | `:509` | `0/1` | `bServiceDialogue:Services` |
| `svx` | `:510-512` | 3 digits, `<shortcut><byname><prices>` | `bServiceShortcut`, `bCarriageByName`, `bNamePrices` |
| `lf` | `:513` | `0/1` | `bLockedFacts:Truth` |
| `tg` | `:514` | `0/1` | `bTruthGate:Truth` |

So `qig` arrives pre-clamped and `qx` / `svx` are fixed-width digit strings, exactly the `chk`
idiom `lrgDlgMcmFromSnapshot()` already uses. **The whole of M4 is server-side**, and it is lane
C's to do, unchanged from the defect text:

* `lrg_dialogue.php:288-302` - `lrgDlgMcmFromSnapshot()` parses only `chk, bias, ql, qi, sv, lf`.
  Add `'tg'=>[0,1]` to the foreach; add a 2-4 digit branch for `qig` (clamp 60..1800 again
  server-side, do not trust the wire); add digit-string branches for `qx` (2) and `svx` (3).
* Then consult them at the seven sites that read config directly today:
  `lrgDlgCfg('quests.initiative.gap_seconds')` :2850 / :2912, `quests.summary.enabled` :2738,
  `quests.next.enabled` :2785, `services.shortcut_fallback` :3242, `truth.gate` :2041 / :3498 -
  each with the same `lrgDlgMcm($k, <config default>, $npc)` pattern `qi` / `sv` / `lf` already use.
* `bCarriageByName` and `bNamePrices` have **no server site at all** yet - they need one, or they
  are dead whatever the wire carries.
* `test_mcm_wiring.php:130-152` - evaluate `SERVER_KNOBS` **before** path (a), otherwise the
  "the id string appears in a .psc" test matches first and the wire half is never checked. That is
  why `bTruthGate:Truth` passes today while `tg` has no reader. Add the seven ids to the map.

Nothing in this lane's files needs to change when that lands: the wire keys are already there and
already additive in both directions.

---

## 6. Deviations from the defect list as written

| # | Deviation | Why |
|---|---|---|
| **D-F1** | `Hide()` / `Unhide()` did **not** get a `fam` argument. The two `SwfFamily()` re-reads stay inside the measured window | Those signatures are frozen (`LRG_DlgUI` is the DLL seam) and every caller in the project would have had to change. The window still shrank by up to seven delayed natives, and the reorder - not the shrink - is what actually fixes the defect |
| **D-F2** | The 200 ms constant was **not** re-set from a reading | It cannot be: no A1 has ever run in this game. Instead `hidems` now rides `CalWire()` and `CALIB SUMMARY`, so the first evening produces the number and the next round can set it. The rail is untouched in the meantime |
| **D-F3** | `park` was **not** kept as a gate row with a second arming site in `StepManual` | The defect offered that as the alternative if the row had to stay. It does not have to: D6 says both verdicts are green, so it carries no safety information, and `StepManual`'s list is one the player can click - not a held park, so it would not be the same measurement |
| **D-F4** | `CalReadProbe` got a **counter** (`rmN`), not the bare `|| rm == -3` early return | The bare return also skips the cheap `LRG_DlgUI.ReadMode()` recovery above the probe, which is a StorageUtil read and the path by which a mode proved later (by A4, or by the driver's own `CalibrateRead`) gets picked up. The counter caps the expensive part and the warning, which is what the defect was about, and the defect named it as the alternative |
| **D-F5** | `CalNextActive()` puts **A4 before A1**, not just `hide` before `apdr` | `rm` is a gate row too and A4 is its only answer. With A1 first and A1 overrunning (which disables the pass for the session), an install whose mode 3 reads empty would have starved A4 for ever - the same defect in a different key |
| **D-F6** | `CalActiveA4` got the same reorder + a `stillOpen` guard, although the defect only named A1 | Identical code shape, on a gate row, with no other way to answer it. Leaving it would have shipped the fixed bug next to the unfixed one |
| **D-F7** | The MCM buttons arm a one-shot **and** 33-36 were exempted from `bProbe` **and** the key is registered unconditionally | The defect offered these as alternatives ("Better: ..."). They are complementary: the arming makes the buttons work, and the other two make the page's own documented fallback true |
| **D-F8** | `actn` gained a reader (the warning's denominator) instead of a wire key | M2 left it write-only, which is the same smell m3 is about. The denominator is where it is actually useful, and `CalWire()` did not have to grow a fourth pair |
| **D-F9** | The "read mode 3 is empty" log line in `LRG_Dialogue.CalibrateRead()` is now once per session | Named in m1's evidence as the same shape pre-dating v0.5. One member, one branch |
| **D-F10** | Two Calibration buttons (held-mouse, force-close) still need `bProbe = 1` and the probe key | They have to be pressed DURING a conversation while the owner holds the mouse, so arming them is not the same test. Both are marked Optional and nothing in the feature uses their answers. Their help text now names the real route (probe key + slider 17 / 23) instead of implying the button works |

---

## 7. What an owner sees that he did not see yesterday

* The Calibration status line can reach **green**, and says `N of 10`, not `N of 11`.
* Pressing **1. The click test** puts "now open a conversation with an innkeeper - the click test
  runs by itself" in the corner and writes `CALIB armed press 1` to the log. The test then runs on
  that conversation. Same for **1b** and **2**.
* A new button **1b** for the other click route, which the failure note has always pointed at.
* `bCalibActive` runs the two tests the gate needs **first**, on the first two conversations, out
  of five instead of three.
* When the budget is gone the status line says so, and how to run another set.
* `CALIB SUMMARY` carries `hidems=` and `runs=`; `ev=calib`'s `k=` carries `hidems`, `actry`,
  `actms` and a real `ms4`.
* Nothing extra is on by default: `bMenuless = 0`, `bDlgDryRun = 1`, `bCalibActive = 0`,
  `bProbe = 0`, `iKeyProbe = 0`, `iProbePress = 0` - all unchanged.

## 8. Not done in this lane

* **M4's server half** (section 5) - `lrg_dialogue.php` and `test_mcm_wiring.php` are lane C's.
* **No install.** `install_mo2.ps1` was not run; the `.pex` files are built into
  `glue\game\LoreRimGlue\Scripts` and nothing was copied to `F:\Modlists`.
* **`manifest.json` 0.5.0 / `LRG_Main.CurrentVersion` 500 / `PROTOCOL.md` v0.5** are lane C's and
  were left alone. `CurrentVersion` in `LRG_Main.psc` is untouched by this pass.
* **Nothing is tested in game.** Every claim above is from the source and the compiler.
