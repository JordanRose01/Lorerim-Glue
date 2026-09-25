# PT9 verification - GAME SAFETY + CALIBRATION CORRECTNESS (v0.5.0)

Independent read-only audit of the v0.5.0 fast-ship. Lens: game safety and calibration correctness.
Nothing was changed except this file. Date 2026-09-22.

**VERDICT: FIX FIRST.** No hard rail is broken and nothing found here can damage a save or a quest,
but the calibration engine cannot finish: the leave-dry-run gate contains rows that are
**unreachable by construction**, so the feature can only ever leave dry run through
`bCalibOverride`, which is the one thing the gate exists to avoid. Three independent blocks, one of
them circular.

---

## 0. What passed

| Check | Result |
|---|---|
| `SetStage` / `SetObjectiveCompleted` / `CompleteQuest` / `TIF_` anywhere under `glue\game\` | **0 calls.** One hit, a comment at `LRG_Dialogue.psc:8` |
| `tools\compile.ps1` | **OK** - re-run independently: `compiled: LRG.pex, LRG_Dialogue.pex, LRG_DlgProbe.pex, LRG_DlgUI.pex, LRG_Main.pex, LRG_MCM.pex, LRG_OStim.pex, LRG_PlayerAlias.pex, LRG_Profile.pex` / `0 errors, 0 warnings` |
| Bare `UI.*` in `LRG_DlgProbe.psc` | **0** (confirmed with `(^\|[^A-Za-z0-9_.])UI\.`) |
| Passive calibration writes to the menu | **none.** `CalArm` (no UI at all), `CalPoll` (`ProgressTimerId`, `Subtitle` - reads), `CalLayer` -> `CountRaw`, `EntryText(k,**3**)`, `TextProbeOn`, `EntryRowItem`, `EntryColour` - every one a `UI.Get*`. Mode 4 (the only reading mode that writes `iSelectedIndex`) is never used passively; it is reached only from `CalActiveA4`, which hides first |
| `LRG.cal.*` key set | **complete.** Every key written by the probe appears in `LRG_DlgUI.CalClear()` (mechanical diff: 0 missing). `CalClear` does not touch `LRG.du.*` |
| MCM wiring | **108/108.** Every `SettingBool/Int/Float("k:Section")` in the .psc has a `settings.ini` line, a `config.json` control, a matching control type and a matching `sourceType`. 0 orphan ini keys, 0 orphan controls |
| `iMaxEntries` / `fLineSettle` / `fDecideTimeout` / `iBranchInput` "auto" resolution | deterministic, owner's number is the floor, resolved in `ReadCalibration()` **before** the clamps at `LRG_Dialogue.psc:533-556` |
| `CalMissing()` vs plan 6.5 | **exact.** Eleven rows, same keys, same green conditions, same human names |
| Emergency key mid-active-test | **works.** During A1 the driver is still in `ST_IDLE`, so `HandleEmergencyKey` takes the idle branch, `NeedsUnhide()` sees `LRG_DlgUI.IsHidden()` and calls `DoUnhide("key-idle")`. A1's own `Unhide()` then re-restores the same validated floats - idempotent, benign |
| Hand-back cannot strand or re-greet | **holds.** `HandBack` captures `wasHidden = NeedsUnhide()` before `GoManual`, so exactly one `SendUnhide` fires on every path; `GoManual` re-tests `NeedsUnhide()` (the `_x` itself, not the flags); `MaybeResume` never calls `Activate`, so there is no second greeting, and it is disarmed in dry run (`!sDryRun` in its own condition) |
| NPC-initiated quest talk opening a session by itself | **cannot.** The only open path is `CmdSelectTopic` verb `pick`/`open` -> `StartOpen` -> `DoOpen`, behind `ok=1`, `RefMatches`, and `OpenBlockedReason` (combat, menu mode, scene, OStim scene, sleep, sneak, mount, beast form, distance) |
| Corner hint cost | **one `Debug.Notification`** on a `note=` that rides a command already in flight (`LRG_Main.psc:569-578`); `kind=hint` respects `bQuestHint:Quests` game-side |
| Conversation hold / intimacy | **untouched.** `LRG_OStim.psc` was not modified this round (mtime 02:38, before the lane edits at 04:06-04:27) and is not in any changed-file list. The new `LatStarted` / `NoteSpeechToDriver` calls are appended *after* the existing `NoteConvLine` in each CHIM handler; `ReleaseConvHold` call sites are unchanged |
| Old-server (0.4.1) tolerance | **additive.** `ev=calib` / `ev=lat` fall through the `switch ($ev)` to `lrg_dialogue.php:656` "unknown ev - ignored"; the new `ev=open` keys (`cal=`, `svck=`) are extra `;k=v` pairs. `CalWire()` really contains no `;` |

Note on the live install: the server **has** been deployed since the build reports were written - see
defect 12.

---

## 1. CRITICAL - the dry-run gate is circular: `park` cannot be answered while dry run is on

`CalMissing()` requires `park` to be 1 or 2 (`LRG_DlgProbe.psc:2619-2622`). `park` is written in
exactly one place, `CalParkPoll` (`LRG_DlgProbe.psc:1843`), armed only by `CalPending`
(`LRG_DlgProbe.psc:1814`), called from exactly one site, `StepPending`
(`LRG_Dialogue.psc:2002-2007`).

`ST_PENDING` is entered only by `EnterPendingOrManual`, whose first condition is
`glueOpened && isHidden && crit != 2 && !inScene` (`LRG_Dialogue.psc:1976`). `isHidden` is set true
in exactly two places, `Arm()` line 1434 (guarded by `if !goManual && !sDryRun`, line 1430) and
`MaybeResume()` line 2583 (guarded by `sDryRun` at line 2564).

So `isHidden` is impossible while `sDryRun` is true, `park` is unanswerable while `sDryRun` is true,
and `ReadCalibration()` forces `sDryRun = true` while `park` is unanswered
(`LRG_Dialogue.psc:632-641`). The gate can never be satisfied. `bCalibOverride` is the only exit,
which defeats the whole safety net.

The design itself says both park verdicts are green (build report D6: "BOTH values are green, so the
gate outcome is identical; only the diagnostic differs"), so this row carries no safety information
at all.

**Smallest fix:** delete the `park` block from `CalMissing()` (`LRG_DlgProbe.psc:2619-2622`) and move
`park` to the measurement list in the header comment and in plan 6.5 - it is a diagnostic, exactly
like `x1`. If the row must stay a gate, it has to be answerable from a visible session (arm
`CalPending` from `StepManual` when the engine pushes a list) before dry run can be a precondition
for it.

---

## 2. CRITICAL - the two manual presses that answer `guard`, `route` and `reopen` cannot be run

Three more gate rows come from the two manual presses and from nowhere else: `guard`
(`CalPressClickBody`, line 2425), `route` (lines 2480/2484) and `reopen` (`CalPressReopen`, line
2568). Both presses refuse unless a **Dialogue Menu is open**
(`LRG_DlgProbe.psc:2371-2376` and `2531-2535`).

Both documented routes to them are dead:

* **The MCM buttons.** `LRG_MCM.CalPressClick()` / `CalPressReopen()` (`LRG_MCM.psc:67-86`) run the
  moment the button is clicked, i.e. from inside the MCM, which lives in the Journal/pause menu. The
  Dialogue Menu is necessarily closed at that instant, so `LRG_DlgUI.IsOpen()` is false and the press
  always takes its own refusal branch (`refused=no-menu`, "open a conversation with a long list
  first"). The page's help even instructs the owner to "Stand in front of somebody harmless with a
  long list and press this", which cannot be done.
* **The named fallback.** `config.json` line 191: "every one of them is also reachable from 'Which
  probe press' ... 33, 35, 17, 23 and 36". `RunStep()` refuses everything unless
  `bProbe:Dialogue` is true (`LRG_DlgProbe.psc:288-291`, "probe is off (bProbe)"), and
  `Maintenance()` returns *before* `RegisterForKey(probeKey)` when `bProbe` is 0
  (`LRG_DlgProbe.psc:141-147`). `settings.ini` ships `bProbe = 0` (line 171) and `iKeyProbe = 0`
  (line 120), and neither the Calibration page nor the `iProbePress` help says the probe has to be
  armed and given a key first.

**Smallest fix:** exempt 33-36 from the `bProbe` refusal in `RunStep()` and register `iKeyProbe`
whenever it is > 0 regardless of `bProbe` (move the key block above the `bProbe` test in
`Maintenance()`); then put the probe key + `iProbePress` instruction on the Calibration page. If the
MCM buttons are to stay, they must **arm** the press for the next menu open rather than run it
(`CalPressClick` sets a one-shot flag consumed in `CalArm`), and the notification has to say so.

---

## 3. MAJOR - CAL-2's 200 ms window is almost certainly unreachable, and failing it locks the gate

`CalActiveA1`'s measured window (`LRG_DlgProbe.psc:2254-2262`) is `t0` -> `Hide(1)` -> up to 7
`HolderX()` evaluations -> `SubtitleShown()` -> `Unhide()` -> `t1`. Counting the delayed natives
that really run on CHIM's SWF:

* `Hide(1)`: `IsMenuOpen`, `SwfFamily` (2-3 calls), `GetFloat` x3, `SetFloat` x2, `SetBool` - **~10**
* flash loop: 2 natives per evaluation, 1-7 evaluations - **2-14**
* `SubtitleShown()` - **2**
* `Unhide()`: `IsMenuOpen`, `Guard(false)` (`IsMenuOpen`, `GuardReset`'s `IsMenuOpen`+`SetInt`+`Invoke`, 2x`SetBool`), `HideCursor`, `SwfFamily` (2-3), `SetFloat` x3, `SetBool` x3, `EntryCount` (2), `MenuState` (2), often `ShowList` (2) - **~21-23**

**~35-45 `UI.Get*`/`UI.Set*` calls**, and this project's own cost model says each one is expensive:
`LRG_DlgUI.psc:62-63` states every `UI.Get*/Set*` is a delayed native, and
`ReadCalibration()`'s `cap = (900 * 12) / ms3` (`LRG_Dialogue.psc:600`) is tuned so that the default
16 entries land inside 0.9 s - i.e. ~50 ms per read. 35-45 calls at that rate is 1.5-2 s, an order
of magnitude over 200 ms.

The failure mode is self-locking, not merely unproductive (`LRG_DlgProbe.psc:2282-2284`):

```
if ms > 200
    CalWarn("active hide took " + ms + " ms - over the 200 ms contract, so the answer is not claimed")
    CalActiveDisable("the hide round trip took " + ms + " ms")
```

`ms > 200` is tested **first**, so `hide` is never written even when the list demonstrably came back
(`back == true` is computed at line 2269 and then discarded). `CalActiveRun` still returns true, so
`runs` is spent (line 2141), `calOff` kills the rest of the pass for the game session, and the owner
gets a warning plus a corner notification. After `iCalibRuns` (default 3) loads, the budget is gone
and `hide` - a gate row - is permanently unanswered.

**Smallest fix (does not relax the rail):** shrink the window and instrument before enforcing.
(a) pass the session's already-known `fam` into `Hide`/`Unhide` instead of re-reading `SwfFamily()`
twice (~6 natives); (b) move the flash loop and `SubtitleShown()` out of the measured window (they
are diagnostics, not the contract); (c) **reorder the verdict** so `back` is evaluated before the
overrun test - record `hide = 1` when the list really came back, and keep `CalWarn` +
`CalActiveDisable` for the overrun. Then take one real reading in game and set the constant from it.
As shipped, the contract is asserted rather than measured and its first failure is terminal.

---

## 4. MAJOR - `actn` is written by two collectors with two different meanings

`CalArm` increments `actn` on **every glue-opened session** (`LRG_DlgProbe.psc:1595-1597`):

```
if abGlueOpened
    LRG_DlgUI.CalSetInt("actn", CalG("actn") + 1)
```

`CalOpened` uses the same key as its **bad-open counter** (`LRG_DlgProbe.psc:1982-1988`):

```
if aiTries > 1
    int bad = CalG("actn") + 1
    LRG_DlgUI.CalSetInt("actn", bad)
    if bad == 3
        CalWarn("opening a conversation needed more than one try three times - try bActivateDefaultOnly ...")
```

`LRG_Dialogue.DoOpen()` calls `p.CalOpened(tries, openMs)` at line 1231 and `CalArm` runs on the
same open, so after the **third glue-opened conversation** the warning fires whether or not a single
Activate ever needed a retry, and once `actn > 3` it can never fire again however bad the opens get.
The plan (V05 §423/§437) names `actry`/`actms` only; `actn` is lane A's own key and lane B's
`CalArm` reuses it for something else.

Related: `pt9-build-ui.md`'s NOT DONE line - "An additive optional hook `CalOpened(int, int)` is
built and ready; **lane B does not call it**, so the `bActivateDefaultOnly` warning cannot fire this
round" - is stale. Lane B does call it (`LRG_Dialogue.psc:1231`, and its own deviation D-B11 says
so). The warning can fire, and it fires wrongly.

**Smallest fix:** give `CalOpened` its own key (`actbad`), add it to `CalClear()`.

---

## 5. MAJOR - seven MCM controls send a wire key the server never reads

`LRG_Profile.psc:499-514` adds `qi`, `qig`, `qx`, `sv`, `svx`, `lf`, `tg` to the snapshot precisely
so the new MCM controls are not dead (deviation D-B1). The server's intake whitelist
(`lib/lrg_dialogue.php:297`) is:

```
foreach (['bias' => [-2, 2], 'ql' => [0, 6], 'qi' => [0, 1], 'sv' => [0, 1], 'lf' => [0, 1]] ...
```

`qig`, `qx`, `svx` and `tg` are never parsed anywhere on the server (grep over `lib/` and `tools/`:
only `lrgDlgCfg('truth.gate', ...)` at lines 2041 and 3498, which reads the JSON config, never the
snapshot). The regex `^-?\d{1,2}$` would reject `svx` = "111" even if it were listed.

Dead controls, all of them owner-facing and all of them added this round:
`iQuestInitiativeGap:Quests`, `bQuestSummary:Quests`, `bQuestNext:Quests`,
`bServiceShortcut:Services`, `bCarriageByName:Services`, `bNamePrices:Services`, `bTruthGate:Truth`.
`bTruthGate` is the owner's own "Do not let a wrong number cost me gold" switch from addendum 9c.

They pass `test_mcm_wiring.php` because the test asks only whether *a .psc* reads the id - and one
does, to emit a key nobody consumes. That is the exact scar D-B1 cites, moved one hop down the wire.

**Smallest fix:** extend the `lrgDlgMcm()` whitelist with `qig => [60,1800]` and `tg => [0,1]`, and
add the two shape-byte keys (`qx`, `svx`) the way `chk` is parsed at line 289.

---

## 6. MAJOR - X1 misattributes closes, and reads a count the driver did not refresh

Two independent errors in the one measurement that resolves `iBranchInput`'s "auto"
(`LRG_Dialogue.psc:623-629`).

**(a) Any close inside the window is recorded as "died".** `CalClose` is handed `asWhy` and
`abAnyClick` and ignores both (`LRG_DlgProbe.psc:1948-1950`):

```
if cX1Armed
    CalX1Verdict("died", 0, Ms(cX1At, Utility.GetCurrentRealTime()))
```

`Finish()` classifies the close as `refused` / `asked` / `handoff` / `goodbye` / `external`
(`LRG_Dialogue.psc:2426-2435`). A conversation that ends because the **driver clicked a farewell
entry** - the normal end of a driven session, and one that typically lands 2-4 s after the player's
words, i.e. inside the 6 s window - is recorded as the player's speech having killed the branch.
So is TAB, walking away, combat, and a stale session settled by `CalArm`'s `CalClose("no-close",...)`
after a load. `CalX1Poll` already returns "unknown" when a new layer arrives
(`LRG_DlgProbe.psc:1895-1898`); the close path has no such guard.

**(b) "survived" can be read off a stale count.** `CalX1Poll` judges on `aiCount`, which is
`LRG_Dialogue.lastN`. `lastN` is assigned in `StepListening`, `StepReading`, `StepPending`,
`StepManual` and `DoDump` only (lines 1598, 1624, 1993, 2329, 2709). The X1 window is normally spent
in `ST_DECIDING` (waiting for the server), which never refreshes it, so a list that really died
during the decision is reported as "survived" - the direction that sets `sBranchInput = 1`
("answer by voice"), the less conservative of the two.

**Smallest fix:** (a) in `CalClose`, settle a live window as `"unknown"` unless
`asWhy == "external" && !abAnyClick` - both arguments are already in the signature; (b) have
`CalX1Poll` take one `LRG_DlgUI.EntryCount()` of its own at the 6 s mark (one delayed native, at
most 9 times per install) instead of trusting `cLastN`.

---

## 7. MINOR - `rm == -3` makes the passive probe repeat its warning on every layer, for ever

`CalReadProbe` returns early only for `rm == 3 || rm == 4` (`LRG_DlgProbe.psc:1701-1704`). Once mode
3 has come back empty it stores `rm = -3` and warns (lines 1733-1735). `CalSet` suppresses the log
line on an unchanged value, but `CalWarn` is called unconditionally straight after it, so **every
subsequent layer** re-runs the 4-entry probe plus a `TextProbeOn` (5 extra delayed natives) and
emits the same `CALIB WARNING` - one fire-and-forget HTTP message per line - on exactly the install
that is already struggling to read the menu. Nothing clears `-3` except `CalActiveA4`, which is
opt-in.

Same shape one level up: `LRG_Dialogue.CalibrateRead` logs "read mode 3 is empty and the menu is the
player's" (line 1889) on every `StepManual` poll (2/s) in the same situation. That one pre-dates
v0.5.

**Smallest fix:** add `|| rm == -3` to the early return at line 1702, or cap the samples with a
counter the way `rowN` / `colL` do.

---

## 8. MINOR - with `bCalibPassive = 0` only one active test ever runs per game load

`cActiveRan` (one active test per menu open) is cleared in exactly three places: `Maintenance()`
line 129, `CalArm` line 1576, and `CalClose` line 1952. `CalArm` and `CalClose` are both called only
under `if ... sCalibPassive` (`LRG_Dialogue.psc:1404` and `2465`). Turn the passive switch off and
leave the active one on, and `cActiveRan` stays true after the first test until the next load - and
`CalLogSkip` suppresses `one-per-open` from the log (line 2148), so it is invisible.
`cArmHidden` is likewise never refreshed on that path.

**Smallest fix:** reset the per-open state from a call the driver makes on every arming regardless of
`sCalibPassive` (call `p.CalArm(...)` unconditionally - it writes nothing to the menu - or add a
one-line `CalNewOpen()` beside it).

---

## 9. MINOR - measurements collected and never surfaced; one wire key always 0

Mechanical diff of writers vs readers of `LRG.cal.*`:

* `hidems` - written (`LRG_DlgProbe.psc:2272`), read by nothing. Not in `CalWire()`, not in
  `CalLogSummary()`, not in `CalStatusText()`. The number only survives as `ms=` inside the P6-H2
  probe line.
* `actry`, `actms` - written by `CalOpened`, compared against themselves, and never reported
  anywhere, although V05 §1154 lists them in the key set and §423 names them as P0b's output.
* `parkb`, `parka` - written, never read back (the verdict uses the script members).
* `ms4` - read by `CalWire()` (line 2715), written by nothing; always 0 on the wire, as the build
  report admits.

**Smallest fix:** append `hidems`, `actry`, `actms` to `CalWire()` (it is already the last raw key and
a generic `name:int` parser ignores what it does not know) or to `CalLogSummary()`.

---

## 10. MINOR - the Calibration page over-promises, and `calOff` is reset by unrelated MCM changes

* `config.json` (bCalibActive help): "It switches itself off again after the number of conversations
  below." Nothing ever clears `bCalibActive:Calib`; only the `runs` budget stops the pass
  (`CalActiveWanted`, `LRG_DlgProbe.psc:2065-2072`), and the status line never says the budget is
  spent. The toggle stays on for ever.
* `LRG_DlgProbe.Maintenance()` sets `calOff = false` unconditionally (line 124), and `LRG_MCM`
  calls `Maintenance()` on **any** `:Keys` change as well as on `bProbe` / `bCalibActive`
  (`LRG_MCM.psc:28-43`). Re-binding a hotkey therefore re-enables an active pass that CAL-2's
  overrun had disabled for the session.

**Smallest fix:** say "it stops after this many conversations" in the help; and only reset `calOff`
in `Maintenance()` when the game session tag really changed (`calSid != m.SessionTag()` - `calSid` is
already there and already read at line 135, but nothing compares it).

---

## 11. MINOR - `routed=self` is not evidence of anything

`CalSpeech(1, ...)` sets `cX1Route = "self"` for any NPC speech event inside the window
(`LRG_DlgProbe.psc:1863-1866`). `LRG_Main` forwards **every** CHIM speech event, for every agent, on
`SpeechStarted`, `SpeechStopped` and `TextReceived` (`LRG_Main.psc:683, 717, 749`), and
`LRG_Dialogue.NoteSpeech` filters only on "is a session live", never on the speaker's identity
(line 2596-2607). A bystander CHIM agent talking in the same room produces `routed=self`. Build
report D15 - "'other' is unreachable ... and it is not pretended" - is the opposite of what the code
does: it pretends `self`.

**Smallest fix:** log `routed=npc-spoke` instead of `self`, or add an actor argument to
`NoteSpeech`/`CalSpeech` (both are lane-internal, neither is a frozen signature).

---

## 12. MINOR (docs) - the server WAS deployed and the live database WAS migrated

`pt9-build-server.md` NOT DONE: "The server was NOT deployed, as instructed. ... The live dwemer
database is untouched (public.lrg_prompt still holds 37,561 rows; schema lrg_index does not exist
there)."

Checked on the live distro:

```
/var/www/html/HerikaServer/ext/lorerim_glue/lib/*.php   all dated 2026-09-22 05:17
manifest.json                                           "version": "0.5.0"
lrg_dialogue.php                                        carries the [0.5.0] markers
psql: schemas -> public, lrg_index
psql: select count(*) from lrg_index.lrg_prompt -> 37561
psql: public.lrg_prompt -> relation does not exist
```

The deploy and migration 007 both ran after the report was written. This does not break anything -
the game scripts are 0.5.0 too, so both halves match - but the owner's rollback picture is wrong,
and the scratch database `lrg_t` still exists.

---

## 13. MINOR - four bare `UI.*` calls outside `LRG_DlgUI` (pre-existing)

`LRG_DlgUI.psc:2-4` and `LRG_Dialogue.psc:15` both state that every `UI.*` call and GFx path in the
project lives in `LRG_DlgUI`, and the DLL seam of V04 §11 depends on it. `LRG_Main.psc` has four:

```
324:  if UI.IsTextInputEnabled()
945:  if Utility.IsInMenuMode() || UI.IsTextInputEnabled() || UI.IsMenuOpen("Dialogue Menu")
1135: if Utility.IsInMenuMode() || UI.IsMenuOpen("Dialogue Menu")
1351: if UI.IsMenuOpen("Dialogue Menu") || (dlg && dlg.IsSessionOpen())
```

`LRG_DlgUI.TextInputOn()` and `OtherMenuOpen(name)` exist for exactly these. Two of the four are
present in `LRG_Main.psc.bak-docstrings`, so this is **not a v0.5 regression**; lane A's GATE 4 only
checked `LRG_DlgProbe`.

**Smallest fix:** route the four through `LRG_DlgUI.TextInputOn()` / `OtherMenuOpen("Dialogue Menu")`.

---

## 14. MINOR - A5 leaves a movie clip in the live Dialogue Menu

`CalActiveA5` -> `LRG_DlgUI.CreateProbeClip()` calls
`_root.createEmptyMovieClip("lrgProbe", 9731)` (`LRG_DlgUI.psc:1116-1127`) and never removes it.
The clip is empty and the movie is rebuilt on every open, so nothing is visible - but
`createEmptyMovieClip` replaces whatever already occupies depth 9731 on `_root`, and no one has
checked what a modded `dialoguemenu.swf` puts there. The test's answer is used by nothing in the
feature (it settles design decision D1 plan C).

**Smallest fix:** `UI.Invoke("Dialogue Menu", "_root.lrgProbe.removeMovieClip")` after the read-back,
or move A5 behind its own opt-in.

---

## 15. Traced but clean - detail

* **A1 always gives the menu back.** Between `Hide(1)` (line 2255) and `Unhide()` (2261) there is no
  `return` and no `Utility.Wait*`; the two `WaitMenuMode` loops (D10) are entirely above `t0` and
  skip *without* spending the budget when the list never arrives (lines 2246-2250). `Hide()`
  returning false leaves `hok = 0`, so `Unhide()` writes nothing and reports `2`; a menu that closes
  mid-test gives `LastUnhide() == 3` and the key is simply left unanswered. The one real hazard -
  `lu == 2` - disables the pass and names the emergency key (2289-2291, 2279-2280). Correct, except
  that the `ms > 200` branch pre-empts all of it (defect 3).
* **A4** writes `iSelectedIndex` only behind `Hide(1)` + `Park()`, and restores the selection with a
  **bare** `SelectAndVerify(sel0, -1, "", false)` that authorises no click (lines 2326-2329); the
  `sat` stamp is only written when a topicIndex or a prefix was supplied, so `Click()` would refuse
  with `-3`. If `sel0 < 0` the player's selection is silently left on entry 0 - cosmetic.
* **The active pass never runs on a driven session.** `ActiveCalibSafe()` rejects `crit == 2`,
  `inScene`, `isHidden`, `parked`, `hideFailed` (`LRG_Dialogue.psc:1494`) and `CalActiveWanted()`
  rejects `IsHidden() || cArmHidden`, guards, scenes, outstanding crime gold and the three service
  windows by name (`LRG_DlgProbe.psc:2077-2105`). Because `Arm()` hides at line 1433 *before*
  `CalActiveRun` at 1472, a session the glue will really drive has already set `isHidden` and is
  skipped. In dry run the driver never hides, so the pass runs there - which is the intent.
* **D9 is right and is worth keeping.** Not using `Utility.IsInMenuMode()` as a veto is correct:
  nobody has measured it from inside a Dialogue Menu, and a `true` would silently disable the whole
  pass. Both lanes reached it independently.
* **`ClickAllowed`** still refuses on `readOnly`, guards, scene actors and a crime faction holding
  gold, and `DoClick`/`DoCloseClean`/`DoCloseForce` all go through it
  (`LRG_DlgProbe.psc:1192-1227`). `CalPressClick` sets `readOnly = false` only around
  `CalPressClickBody` and restores it on all three paths (2370, 2372, 2385).
* **`Click()`'s rails are untouched**: freshness stamp (<= 2 s), state read-back, identity pair as
  the last frame-synced work, one click per verified selection, `sat = -1` before the single queued
  `Invoke` (`LRG_DlgUI.psc:519-576`). `StepClicking` still aborts on `ClickResult() <= 0` and
  recovers with `ShowList()` without retrying the other route.
* **`Unhide()`'s family test** is still "only a DIFFERENT KNOWN family may veto" (0 = unknown ->
  restore anyway, reported as `4`), and the stored floats are still validated by `Usable()`.

---

## What to fix before the owner plays

1. Defect 1 (`park` out of the gate, or made answerable in dry run) - otherwise the gate is
   mathematically unsatisfiable.
2. Defect 2 (reach the two manual presses) - otherwise three more rows are unsatisfiable.
3. Defect 3 (reorder A1's verdict, shrink its window, measure before enforcing 200 ms) - otherwise
   the one test that answers `hide` disables itself and burns the budget.
4. Defect 4 (`actn` collision) and defect 5 (dead MCM controls) - both are one-liners and both are
   owner-visible lies.

Defect 6 should be fixed in the same pass; it costs two lines and it is the measurement that decides
how branches are presented.
