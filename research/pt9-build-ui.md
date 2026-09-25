# pt9 — LANE A (GAME-UI) build report, v0.5.0 round

**Lane:** A — GAME-UI. **Files owned and written:**
`glue/game/LoreRimGlue/Source/Scripts/LRG_DlgUI.psc`,
`glue/game/LoreRimGlue/Source/Scripts/LRG_DlgProbe.psc`.
**Nothing else was opened for writing.** No other lane's file, no `compile.ps1`, no
`make_esp.py`, no MCM file, no server file, no plan file.

**Scope built:** `V05_EXPANSION_PLAN.md` §11 = **E1 (a)** passive calibration, **(b)** passive X1,
**(c)** the opt-in active pass, **(d)** the two manual presses, the `LRG.cal.*` store, the readers
the driver and the MCM use, and presses **33–36**. **Part II (§18–§26) adds nothing to lane A by
design (§22) and nothing from it was built here.**

---

## 0. Status — both gates green

| Gate (plan §11.3) | Result |
|---|---|
| `tools/compile.ps1` prints **OK** | **OK** — `LRG.pex, LRG_Dialogue.pex, LRG_DlgProbe.pex, LRG_DlgUI.pex, LRG_Main.pex, LRG_MCM.pex, LRG_OStim.pex, LRG_PlayerAlias.pex, LRG_Profile.pex`; "compiler reported 0 errors, 0 warnings". Longest `.pex` string: `LRG_DlgUI` **286**, `LRG_DlgProbe` **274** (limit 500) |
| A key table: which probe writes each key, passive or active, green condition | §2 below |
| A hand-traced proof that `CalActiveRun()` calls `Unhide()` on **every** exit path | §4 below |
| `grep -c "UI\." LRG_DlgProbe.psc` = 0 | **0 bare `UI.*` calls.** (The literal grep in the plan counts 312, because `LRG_DlgUI.` contains the substring `UI.`; the check that means what the plan means is `grep -cE "(^\|[^A-Za-z0-9_])UI\." ` → **0**) |
| No `LRG_Dialogue` reference | 0 in code. Five mentions, all in comments/docstrings (two were already there). Proven by the isolation build, which compiles both files with `LRG_Dialogue.psc` **absent** |

**Mid-round note on the batch.** At the time lane A finished its first pass, `compile.ps1` failed
on `LRG_Dialogue.psc` (`SendCalib`, `SendResume`) and `LRG_Main.psc` (`SendLat`) — lane B's files,
mid-edit. Lane A therefore compiled its own two files in isolation exactly the way `compile.ps1`
does (same compiler, same imports, same auto-stub loop, a declaration-only `LRG_Main` stand-in in
`%TEMP%\lrg_lane_a`, nothing committed). **Lane B's files have since landed and the full batch is
green**, so both results are reported: isolation **LANE A OK**, batch **OK**.

**Offline checks.** 44 static assertions over the two files (no bare `UI.*`; every key the probe
writes is forgettable by `CalClear()`; no `return` and no wait inside either hide window; the
eleven gate names; both `CalPoll` samplers reachable; the X1 threshold simulated on 9 cases;
`CalWire` free of `; | @ "`; no pre-existing `LRG_DlgUI` signature removed or renamed; every
function §11.2 freezes present; presses 33–36 in both tables; the `readOnly` discipline). **All 44
pass.** The checker is scratch (`%TEMP%\lrg_test\lane_a_check.py`, run under
`wsl -d DwemerAI4Skyrim3`) and is **not** part of the project — lane C owns `tools/`.

---

## 1. `LRG_DlgUI.psc` — the only change is the second store

Plan §11.1, followed exactly. Added, next to the existing `SInt`/`SSetInt` family:

```
int    CalInt(string asKey, int aiDefault)        Global
       CalSetInt(string asKey, int aiValue)       Global
float  CalFloat(string asKey, float afDefault)    Global
       CalSetFloat(string asKey, float afValue)   Global
string CalStr(string asKey, string asDefault)     Global
       CalSetStr(string asKey, string asValue)    Global
       CalClear()                                 Global
       CalClearList(string[] asKeys)              Global   ; one block of CalClear's key list
```

Prefix `"LRG.cal."`, `Form` key `None`, same mechanism and same reason as `LRG.du.*` (a Papyrus
**global** function cannot see a script variable). The long explanation went into the existing
`;/ … /;` header block, not into a docstring.

**No UI function was added, changed or removed.** Asserted statically: the 76 pre-existing
function names are all still present and nothing new appears beyond the eight above. The DLL seam
of `V04_BUILD_PLAN` §11 is untouched.

`CalClear()` names its keys explicitly, in four 12-element blocks handed to `CalClearList()`
(Papyrus has no array literal, and one 48-line list would run long). A static check asserts that
**every key `LRG_DlgProbe` ever writes appears in `CalClear()`** — today 38 keys, all covered.

---

## 2. The key table (the gate's second item)

**Eleven gate rows, twelve gate keys** (`apd` and `reopen` are two keys on one row) — plan §6.5.

| Key | Written by | Pass | Values | Green when | Human name in `CALIB GATE missing=` |
|---|---|---|---|---|---|
| `cm` | `CalLayer` → mirrors `LRG_DlgUI.CountMode()` | passive | 1 / 2 / 3, −1 = none works | 1–3 | counting |
| `rm` | `CalLayer`→`CalReadProbe`; `CalActiveA4` | passive (+active for 4) | 3, 4, 0, **−3** = mode 3 empty → A4 wanted | 3 or 4 | reading |
| `timer` | `CalPoll` samples, `CalClose` decides; `CalPressClick` can settle it | passive / manual | 1 = per line, 2 = per session, 0 | 1 or 2 | progress timer |
| `hide` | `CalActiveA1` | **active** | 1 ok, 2 unhide refused, 3 stored value unusable | 1 | hide round trip |
| `guard` | `CalPressClick` | **manual** | 1 nothing moved, 2 something moved | 1 | input guard |
| `park` | `CalPending` arms, `CalParkPoll` decides | passive | 1 dropped+recovered, 2 deferred | 1 or 2 | parked push |
| `route` | `CalPressClick` | **manual** | 1 = A, 2 = B, 0 not proven | 1 or 2 | click route |
| `rb` | `CalActiveA2` | **active** | 1 reliable, 2 unreliable | 1 | state read-back |
| `apd` | `CalArm` (pristine), `CalPressReopen` | passive / manual | 750 clean, anything else verbatim | `== 750` | vanilla menu after a hide |
| `reopen` | `CalPressReopen` | **manual** | 1 / 0 | 1 | *(same row as `apd`)* |
| `st` | `CalArm` → `SmartTalkSafe()` | passive | 1 safe / 0 | 1 | Smart Talk settings |
| `fam` | `CalArm` | passive | 1 gate SWF, 2 gate-less; 0 is never written | 1 or 2 | menu layout |

**Measurements — never gates.** They change behaviour, not safety.

| Key | Written by | Pass | Meaning |
|---|---|---|---|
| `x1` / `x1s` / `x1d` / `x1u` | `CalSpeech` → `CalX1Poll`/`CalClose` → `CalX1Verdict` | passive | 1 survives, 2 dies, 0 undecided; plus the running counts. Verdict needs `x1s+x1d ≥ 3` **and** one ≥ 3× the other |
| `lp` | `CalLayer` | passive | list-path spelling 1 / 2 |
| `row` / `rowN` | `CalRowProbe` | passive | 1 = row ≠ position demonstrated, 2 = equal so far; ≤ 6 long lists sampled |
| `col` / `colL` | `CalColourProbe` | passive | 1 = `0xFFD966` seen, 2 = never over 20 layers |
| `inj` | `CalActiveA5` | active | 1 a clip can be created, 2 it cannot |
| `apdr` | `CalActiveA3` | active | 1 `GuardReset()` really writes 750, 2 it does not |
| `pay` | `CalPayload` | passive | largest layer's wire cost, both parts |
| `ms3` / `tail` | `CalNoteReadCost` | passive | ms for 12 / 24 entry reads, kept at the **worst** seen |
| `flash` / `hidems` | `CalActiveA1` | active | frames to `_x > 4000`; the measured round trip |
| `poke` | `CalPressClick` | manual | how often `bAllowProgress` was found true under the guard |
| `items` / `plat` | `CalArm` | passive | `iMaxItemsShown`, `iPlatform` |
| `cmx` | `CalLayer` | passive | bitmask of which count modes work (bit 8 = the census ran); once per install |
| `apdb` | `CalArm`, `CalPressReopen` | passive / manual | how many times a **leaked** `ALLOW_PROGRESS_DELAY` was seen. Evidence only — it does not itself hold the gate red |
| `parkb` / `parka` | `CalPending` | passive | the counts either side of a parked push |
| `runs` / `armed` | `CalActiveRun`, `CalArmBudget` | — | the active budget, and whether the switch has been armed since |
| `actn` / `actry` / `actms` | `CalArm`, `CalOpened` | passive | glue-initiated opens; **`actry`/`actms` are 0 this round — see §5, D7** |
| `dirty` | every writer | — | "something changed since the driver last asked" |

---

## 3. The public surface (the contract lane B builds against)

Every signature of plan §11.2 exists **verbatim** and lane B is already calling all of them
(`LRG_Dialogue.psc:384, 468, 588–626, 1404–1406, 1472–1473, 1591, 1721, 2002, 2463–2464, 2600,
2816, 2927–2945`; `LRG_MCM.psc:49, 72, 82, 113`). Two **additive** functions were added — they
break nothing and lane B is free to ignore them:

* `Function CalOpened(int aiTries, int aiMs)` — P0b's passive half. §6.1 asks for `DoOpen()`'s
  retry count and the ms to `IsOpen()`, and the frozen `CalArm` signature has no channel for them.
  Optional: if it is never called, `actry`/`actms` stay 0 and the `bActivateDefaultOnly` warning
  simply never fires. **Lane B does not call it today** — see §5, D7.
* `bool Function CalDirty(bool abClear)` — wire **W1** sends `ev=calib` only when a key *changed*.
  **Lane B solved this differently and better**: it snapshots `CalWire()` at `Arm()`
  (`:1405`) and compares at `Finish()` (`:2464`). `CalDirty()` is therefore present and unused;
  it costs nothing and remains available.

---

## 4. Hand-traced proof: the menu always comes back

The only two calibration paths that hide anything are `CalActiveA1` (the hide round trip) and
`CalActiveA4` (read mode 4). Both are written to the same shape, and a static check asserts it:
**between `LRG_DlgUI.Hide(` and `LRG_DlgUI.Unhide()` there is not one `return` statement and not
one `Utility.Wait*`.**

### `CalActiveRun()` → every path

| # | Path | Was anything hidden? | Give-back |
|---|---|---|---|
| 1 | `CalActiveWanted()` false (off / budget spent / disabled / nothing left / closed / driver already hiding / no speaker / guard / scene / crime gold / service window / one-per-open) | **no** | nothing to give back; `CalLogSkip()` writes the interesting refusals |
| 2 | `CalNextActive()` → A2, A3 or A5 | **no** — none of the three hides. A2 writes `eMenuState` and puts it back (+`ShowList()` if it had been 1); A3 writes `ALLOW_PROGRESS_DELAY = 750`; A5 creates `_root.lrgProbe` | n/a |
| 3 | A1/A4 preflight: the list never arrives in 1.0 s, or the menu closed | **no** — the wait is *above* `Hide()` | returns `false`, the budget is **not** spent |
| 4 | A1/A4 body | yes | see below |

### Inside `CalActiveA1()` — the measured window

```
t0   = GetCurrentRealTime()
hid  = LRG_DlgUI.Hide(1)                 <-- may return FALSE
       while flash < 6 && !(HolderX() > 4000.0) : flash += 1      <-- bounded, 6 iterations
subS = LRG_DlgUI.SubtitleShown()
       LRG_DlgUI.Unhide()                <-- UNCONDITIONAL, reached on every path
t1   = GetCurrentRealTime()
```

| Case | What happens | Menu state afterwards |
|---|---|---|
| `Hide()` returns **false** (a stored float was NaN or absurd — rule 10) | nothing was moved; `Unhide()` still runs, finds `hok == 0`, writes no position, restores the three `_visible` flags, clears `hid` | **never hidden.** Verdict `hide = 3`, and because the `!hid` test comes **before** the `lu == 2` test, the pass is *not* disabled — nothing is stuck |
| The menu **closes** between `Hide()` and `Unhide()` | `Unhide()` sees `!IsMenuOpen`, sets `hid = 0`, reports `LastUnhide() = 3` | menu gone; the key is left unanswered and `CALIB WARNING the menu closed during the hide test` is logged |
| Normal | `Unhide()` restores the three validated floats, makes menu/subtitle/speaker visible, re-shows the list | back. `hide = 1` when `_x < 4000`, `EntryCount() > 0` and the menu is open |
| `LastUnhide() == 2` (no usable stored value) | the position was **not** written back | `hide = 2` **and** `CalActiveDisable()`. If `_x` is still > 4000 a loud `CALIB WARNING` plus a corner note tell the owner to use the emergency key or probe press 32 |
| The round trip measured **> 200 ms** | CAL-2 breached | `CALIB WARNING`, `CalActiveDisable()`, and the key is **left unanswered rather than claimed** (plan §6.3, verbatim) |

`CalActiveA4()` is the same shape: `Hide(1)` → `Park()` if the state is 1 → `EntryText(0, 4)` →
a **bare** `SelectAndVerify(sel0, -1, "", false)` (no topicIndex, no prefix, so it authorises no
click) → `ShowList()` → `Unhide()`. No `return` and no wait between `Hide` and `Unhide`.

**Four further give-backs already existed and still work:** `GiveMenuBack()` at the end of every
hiding press, probe press **32**, `LRG_Main.OpenVanillaDialogue()` (the emergency key), and the
driver's own unhide on every exit path. `CalPressClick` routes through `GiveMenuBack()` from a
single linear body with **no `return` statement at all** (asserted statically).

**CAL-1 is kept.** Nothing automatic clicks: the whole active set writes only display properties,
`eMenuState`, `iSelectedIndex` (A4 only, on a hidden menu, put straight back) and `_root.lrgProbe`.
`LRG_DlgUI.Click()`, `CloseClean()` and `CloseForce()` are called from **no** calibration path —
only from the shipped presses and from `CalPressClick`, which goes through `DoClick()` and its
refusal matrix like every other click.

---

## 5. Deviations from the plan, and why

| # | Plan says | Built | Why |
|---|---|---|---|
| **D1** | §11.1: keys are named explicitly "because StorageUtil has no prefix enumeration" | Named explicitly, as asked | The *reason* is wrong: PapyrusUtil does ship `ClearAllPrefix` / `ClearObjIntValuePrefix` (`StorageUtil.psc:461, 475`) and one call would do it. The explicit list was kept anyway because it is the one auditable statement of what the key set *is* — and a static check now uses it to prove `CalForget()` can forget everything the probe writes |
| **D2** | §6.1: `CalLayer` "calls `CountRawOn(1/2/3, ListPath())` on both list spellings, exactly the loop `EntryCount()` already runs" | Mirrors `LRG_DlgUI.CountMode()` / `ListPathId()` — **zero reads** — and runs a three-read census **once per install** into `cmx` | Same information, a fraction of the cost. `EntryCount()` performs that loop anyway on every poll and stores the winner; re-running it per layer would pay twice for one answer (risk R2) |
| **D3** | §6.1: `rm` proven by reading `EntryText(0..3, 3)` + `EntryTopicIndex` + `EntryIsNew` | `LRG_DlgUI.ReadMode()` is taken as proof when it is 3 or 4 (it is only ever stored after a non-empty position-0 text); the four-read probe runs only when it is still 0, and reads **text only** | The topicIndex and `topicIsNew` reads add nothing to the verdict, and a `topicIndex` of 0 is legitimate (hard rule 6), so it could not be used as evidence anyway |
| **D4** | §6.1: `row` measured with `EntryText(row, 5)` **and** `EntryRowItem(row)` | `EntryRowItem` only | Only `itemIndex` answers "is a screen row the same thing as an array position". Saves 4 reads per sampled layer |
| **D5** | §6.1: `ms3`/`tail` timed "around the driver's own `ReadList()` loop and around the tail loop" | Timed around lane A's **own** 4-read probe and extrapolated to 12 and 24 reads; kept at the **worst** value seen | `CalLayer` is called *after* `ReadList()` has finished, so the driver's loop cannot be timed from inside it, and the frozen signature carries no duration. The extrapolation is what `iMaxEntries`' auto formula needs (`900 / ms_per_read`), and taking the worst sample is the safe direction for a cap |
| **D6** | §6.1: `park` = 1 dropped / 2 deferred | Same values, but decided as: **2** when the pushed list was already countable during the park (`parka > 0`), **1** when it only became readable after the driver unparked and re-showed | A hook that fires *at the moment the driver detected the push* cannot replay what happened before it. **Both values are green**, so the gate outcome is identical; only the diagnostic differs. Stated plainly rather than guessed |
| **D7** | §6.1: `actry`/`actms` from `DoOpen()`'s retry loop, with a `bActivateDefaultOnly` warning after three bad opens | `CalOpened(tries, ms)` exists and does exactly that, but **lane B does not call it**, so both keys are **0** and the warning cannot fire this round | The frozen `CalArm` signature has no channel for them. Neither key is a gate. One line in `DoOpen()` — `p.CalOpened(tries, Ms(t0, now))` — turns it on whenever lane B wants it |
| **D8** | §6.3: `rb` 1/2, `apdr` **1/0**, `inj` **1/0** | `apdr` and `inj` use **1 = yes, 2 = tried and the answer is no** | With 0 meaning both "not tried" and "no", a negative result would put the test back in the queue on every single conversation until the budget was gone. Diagnostic keys only; a `:2` on the wire reads as "answered, negative" |
| **D9** | §6.3: A1/A4 never run while "a service menu is open (`Utility.IsInMenuMode()`)" | `BarterMenu`, `GiftMenu` and `Training Menu` are asked for **by name**; `IsInMenuMode()` is not used as a veto | Nobody has yet measured what `IsInMenuMode()` returns from *inside* a Dialogue Menu on this install — that is probe P0's own open question (`menumode=`). A `true` there would silently disable the entire active pass and nothing would ever say why. Lane B reached the same conclusion independently (`LRG_Dialogue.ActiveCalibSafe`, `:1489-1498`) |
| **D10** | §6.3: A1 runs from `Arm()` | Unchanged, but A1/A4 **wait up to 1.0 s** (in menu mode, 0.1 s steps) for `EntryCount() > 0` *before* `t0` | At `Arm()` the list is often not up yet, and A1's own verdict needs entries. The wait is entirely **above** the measured window, so CAL-2 is untouched, and if the list never arrives the test is skipped **without spending the budget** |
| **D11** | §6.3: the flash loop counts "frames to `_x > 4000`" | Capped at **6** iterations (the shipped P6 uses 10) | Each `HolderX()` is a delayed native inside the 200 ms window. 6 is enough to see a 1–2 frame flash and leaves the budget for `Unhide()` |
| **D12** | §6.4: P7's verdict from `SelectedIndex`, `Subtitle`, `ProgressTimerId`, `AllowProgress` | The **subtitle is excluded from the verdict** and logged separately as `subchg=` | She may simply speak a line during the owner's three seconds of mashing, which moves the subtitle with no input having got through. `SelectedIndex` and the progress-timer id only move when something really drove the menu |
| **D13** | §11.4: four status strings | Same four, but a green line **appends** the X1 watch when it is still undecided (`green - 11 of 11 … - still watching branch survival (2 survived, 1 died, needs 3)`) | "Green" is the message the owner needs first — it is what lets them leave dry run — and X1 is not a gate. Clipped to 200 characters |
| **D14** | §5: `CALIB X1 … sid=<sid>` | `sid=-` | The session id belongs to `LRG_Dialogue`, and `CalSpeech(int, float)` is frozen without it. The line still carries the layer, both counts, `talking=` and `routed=` |
| **D15** | §6.2: `routed=<self\|other\|none>` | `self` or `none` only | `CalSpeech` carries no name, so lane A cannot tell "another NPC" from "this one". Lane B forwards only while a session is open on this NPC, so a line inside the window *is* this NPC — `other` is unreachable from here and is not pretended |
| **D16** | §5: `CALIB set <key>=<value> …` | As specified, **plus** one extra `CALIB WARNING` shape for `CalForget()` | A wipe of everything has no sensible `key=value` form, and a warning is greppable |
| **D17** | W1's example `k=` body (22 pairs) | 26 pairs — `row`, `pay`, `poke`, `apdr` appended after `col` | The body is `name:int` pairs for a generic parser; unknown names are ignored. ~250 characters, still safe as the LAST raw key |
| **D18** | W1's example includes `ms4:78` | `ms4` is emitted but is always **0** | The only mode-4 read this round happens inside A4's 200 ms window, and one read is too noisy to extrapolate a per-12 figure from. Probe press 14 (`P3t`) still measures it properly on demand |

---

## 6. Cross-lane notes (lane B / lane C may want these)

1. **`CalOpened()` is waiting for one line.** `p.CalOpened(tries, ms)` in `DoOpen()`'s retry loop
   fills `actry`/`actms` and arms the `bActivateDefaultOnly` warning (§6.1's P0b passive half).
   Nothing breaks while it is uncalled.
2. **`CalPoll`'s free-value fix.** Lane B reads the timer id on its own `pollCount % 5`
   (`LRG_Dialogue.psc:1588`), while lane A counts *calls to `CalPoll`* — the two clocks drift,
   because polls in `ST_IDLE` advance one and not the other. `CalPoll` therefore now uses **any**
   value it is handed, whatever poll it is, and reads for itself only when handed `-1`. The
   one-extra-native budget is preserved by a `tookRead` flag: never two reads on one poll.
3. **`apd` can go green again after a leak.** The gate is `apd == 750`, per plan. A leak is
   recorded permanently in `apdb`, logged as a `CALIB WARNING` naming R1, and carried on the
   summary line — but a later clean 750 does re-open the gate. If that is the wrong call, the
   one-line change is in `CalMissing()`.
4. **`route` is never downgraded.** A refused or unproven click press does **not** write 0 over a
   route an earlier press proved (`CalPressClickBody`), so "try the other click" cannot cost the
   owner an answer they already have.
5. **The active budget restarts on the switch, not on a load.** `CalArmBudget()` (called from
   `Maintenance()`) zeroes `runs` only on the `0 → 1` edge of `bCalibActive:Calib`. Lane B's
   `OnSettingChange` → `p.Maintenance()` (task B14) is what makes that edge visible; without it the
   budget only restarts on the next game load after the toggle.
6. **`CalStatusText()` reads `SmartTalk.ini`** when `st != 1` (one file read). Fine from
   `OnConfigOpen()`; do not put it in a loop. `CalLogSummary()` never reads the file.
7. **MCM range.** `iProbePress` must become **0–36** (`config.json`, lane B task B15); 33–36 are
   labelled in `StepName()` and dispatched in `RunStep()`.

---

## 7. What is *not* built, and what it would cost

* **`actry` / `actms`** — one line in lane B's `DoOpen()` (§6, note 1).
* **`ms4`** — needs a second mode-4 read inside A4's window; not worth the CAL-2 budget. Probe
  press 14 still measures it.
* **Nothing here needs a DLL, an SWF compiler or any install** — plan §1.4 confirmed. Every call
  used is already in the shipped `LRG_DlgUI` surface plus `StorageUtil`, `MiscUtil`,
  `AIAgentFunctions.isActorTalking` (`CHIM/Source/Scripts/AIAgentFunctions.psc:33`), `StringUtil`,
  `Utility` and `Debug`. No new stub and no new import: `compile.ps1` is unchanged.

---

## 8. What the owner will see

Nothing, until they ask for it. With `bCalibPassive:Calib` on (its default) the calibration rides
the driver's existing loop, reads only, and goes quiet key by key as the answers come in. The
active pass does nothing at all until `bCalibActive:Calib` is switched on, and then shows
*"LoreRim Glue: calibrating - one moment"* and *"LoreRim Glue: menu back"* once per conversation
for `iCalibRuns` conversations. The two presses are unchanged from the plan's evening (§24 steps 5
and 6). If the hide round trip ever goes wrong, the corner note says *"list still hidden - press
your emergency dialogue key"* and the log says the same in words.
