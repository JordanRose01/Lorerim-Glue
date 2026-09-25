# Lane A (GAME-UI) build report — LoreRim Glue v0.4.0, Phase 2 menuless questing

Builder: lane A (GAME-UI). Date 2026-09-21. Files owned and written this round:

* `glue/game/LoreRimGlue/Source/Scripts/LRG_DlgUI.psc` — the single place every `UI.*` call and every
  GFx path string in the project lives (design §1.2, the DLL seam of §11). 145 `UI.*` call sites.
* `glue/game/LoreRimGlue/Source/Scripts/LRG_DlgProbe.psc` — the first-playtest probe (design §1.12,
  plan §10.2), shipped before the driver.

Status: **DONE — both scripts built and compiling; `compile.ps1` prints `OK`.** Nothing was run in game
(the probe is the owner's evening; every press's exact log line is specified below).

One shared file was edited out of necessity — `glue/tools/compile.ps1` (lane B's file), one `foreach`
block plus a comment — because `LRG_DlgUI` cannot compile without it. See §5 deviation **D-A2**; lane B
must keep it.

---

## 0. The two facts that changed what had to be written

### 0.1 A `Hidden` script with global functions CANNOT hold state — proven with the compiler

Design §1.2 and plan §3.1 acceptance criterion 2 both say `LRG_DlgUI` remembers `iReadMode` / `iCountMode`
"in script-local state" and stores the hide originals in script variables (`storedXok`). **Papyrus forbids
this**: a `Global` function has no `self` and cannot see a script variable. Measured, not assumed:

```
Scriptname LRGT_StateTest Hidden
int mode = 0
int Function GetMode() Global
    return mode
EndFunction
```
compiled with the project's own compiler (`Nemesis_Engine\Papyrus Compiler\PapyrusCompiler.exe`):
```
LRGT_StateTest.psc(6,8): variable mode is undefined
LRGT_StateTest.psc(6,1): cannot return a none from getmode, the types do not match
Batch compile of 1 files finished. 0 succeeded, 1 failed.
```

So the state the binding signatures require — `EntryCount()` with no mode argument, `Hide()`/`Unhide()`
with no arguments, `SetReadMode`/`ReadMode`, `SetCountMode`/`CountMode` — has to live somewhere outside
the script. **Chosen fallback: `StorageUtil` (PapyrusUtil), global keys (`Form` key `None`)**, which is
the only persistent, script-instance-free store reachable from a global function with the plugins already
installed. The signatures of §1.2 are unchanged, so the DLL seam is unaffected. PapyrusUtil SE is
installed and enabled in the Ultra profile (`modlist.txt:4022`, plus `PapyrusUtil TFC Fix` at `:3968`),
and `StorageUtil` calls are ordinary (non-delayed) natives, so they cost no frame.

Keys, all `LRG.du.*` with `Form` key `None`: `cm` count mode, `rm` read mode, `lp` list spelling,
`hid`/`hok`/`hfam`/`hmd`/`hx`/`hy`/`hex` the hide state, `sti`/`stx`/`sat` the verified selection stamp,
`clk` the last click outcome, `unh` the last unhide outcome, `sto` the Smart Talk offender.

(The alternative — moving the state into `LRG_Dialogue` (lane B) — would have changed the frozen
signatures and created a cross-lane requirement that §2 of the plan does not carry.)

### 0.2 `compile.ps1` does NOT import PapyrusUtil — plan §1.3 is wrong on this point

Plan §1.3 states "Compile imports already cover SKSE, PO3, CHIM and PapyrusUtil (`compile.ps1:52-56`)".
The real import list at `compile.ps1:50-57` is `$src`, SKSE, PO3, CHIM, `$stubs` — **no PapyrusUtil**, and
`MiscUtil` is used nowhere in Phase 1. Without a fix, `MiscUtil.FileExists` (the binding mechanism for
`SmartTalkSafe()`, design §1.2/§1.6, D-21) makes the compiler emit `unknown type miscutil`, which
`compile.ps1`'s auto-stub loop answers with an EMPTY `Scriptname miscutil extends Form Hidden` stub — and
the build then fails on the unknown function instead of on the unknown type.

Importing PapyrusUtil's whole `Scripts\Source` folder does not work either: `MiscUtil.psc` has non-native
legacy bodies that call `Debug.TraceStack`, which the project's compile-only `Debug` stub does not declare
(`tools/stubs/Debug.psc` has `Trace`, `TraceUser`, `OpenUserLog`, `CloseUserLog`, `Notification`,
`MessageBox`, `SendAnimationEvent`). Measured:
```
...PapyrusUtil...\MiscUtil.psc(86,1): variable Debug is undefined
```
Fix used: **`compile.ps1`'s own `Export-NativeDeclarations` idiom** (already used for the OStim API and
`SurvivalModeImprovedApi`), applied to `MiscUtil` and `StorageUtil`, so only their `global native`
declaration lines enter the build. Exact edit in §5 (**D-A2**).

---

## 1. `LRG_DlgUI.psc` — what was built

The constants of design §1.2 are written out literally in each function (a helper that returned them
would add a Papyrus call to each of the 56 reads a layer costs, and this file is the only place they are
allowed to appear anyway); they are also documented in the header block.

### 1.1 Every binding §1.2 signature, as implemented

| design §1.2 | built | notes on the body |
|---|---|---|
| `bool IsOpen()` | yes | `UI.IsMenuOpen(M)` |
| `int MenuState()` | yes | **−1 when the menu is closed** (0 would read as "speaking"); 0/2 speaking, 1 ready, 3 animating-or-parked |
| `int EntryCount()` | yes | mode-calibrated A/B/C over **both** list spellings; `0` = closed or empty, **`−1` = open, a text is readable, no mode works** (lane B's `why=no-count`) |
| `int SwfFamily()` | yes | `HIDE_TOPICS != ""` → 2, else `iMaxItemsShown` 8 → 1 / 13 → 2, else 0; falls back to the other list spelling when the first read is 0, because an unknown family means MANUAL |
| `int MaxItemsShown()` | yes | |
| `int Platform()` | yes | |
| `string EntryText(pos, mode)` | yes | 3 = `EntriesA.<pos>.text`, 4 = write `iSelectedIndex` + `selectedEntry.text`, 5 = `Entry<row>.textField.text` (read-only). Any other mode value falls back to 3 |
| `int EntryTopicIndex(pos, mode)` | yes | same three modes. **−1 = the read could not be made**; a topicIndex of 0 is legitimate |
| `bool EntryIsNew(pos, mode)` | yes | `GetBool` on `topicIsNew` |
| `int EntryColour(row)` | yes | `Entry<row>.textField.textColor`, −1 when unreadable |
| `int EntryRowItem(row)` | yes | `Entry<row>.itemIndex`, −1 when unreadable |
| `string Subtitle()` | yes | |
| `bool SubtitlesOn()` | yes | `Utility.GetINIBool("bDialogueSubtitles:Interface")` |
| `int ProgressTimerId()` | yes | −1 when closed, so "changed" is never faked by a close |
| `bool GateArmed()` | yes | `timerBool`, family 1 only |
| `bool SelectAndVerify(pos, ti, prefix, alsoScroll)` | yes | writes `iSelectedIndex` (+ `iScrollPosition` on route A), verifies **text prefix first, then topicIndex**, and **stamps the verified identity** for `Click()` (see §4 **C-A1**) |
| `Function Click(route)` | yes | route 2 = B, route 1 = A; the exact §1.7 ordering, identity pair last, **exactly one queued `Invoke`**; any failed check clicks nothing |
| `bool Hide(mode)` | yes | stores `_x`/`_y`/`ExitButton._x` as validated **floats**; an unusable read hides nothing and returns false; refuses to overwrite its own stored originals when the list is already off-screen in this open (§4 **B-A1**) |
| `Function ReassertHide()` | yes | negated comparison, so a NaN read also triggers the rewrite |
| `Function HideCursor(bool)` | yes | `"Cursor Menu"`, `_root.mc_Cursor._visible` |
| `Function Guard(bool)` | yes | ON = `SetInt ALLOW_PROGRESS_DELAY 100000000` **then** `Invoke StartProgressTimer` then `bDisableInput = true`; OFF = `GuardReset()` + `bDisableInput = false` + `bAllowProgress = true` |
| `Function GuardReset()` | yes | `SetInt 750` + `Invoke StartProgressTimer`; idempotent, two natives |
| `Function GuardPoke()` | yes | one native, deliberately **without** an `IsOpen()` test |
| `Function Park()` | yes | writes 3 only when the state reads 1 |
| `Function ShowList()` | yes | `InvokeBool ShowDialogueList false` |
| `Function Unhide()` | yes | the §1.10 order; refuses an unusable stored value and reports it through `LastUnhide()` |
| `Function CloseClean()` | yes | `Invoke StartHideMenu` |
| `Function CloseForce(step)` | yes | **probe-only, stated in a `;` comment at the function** as the plan requires, not only in the docstring |
| `bool SmartTalkSafe()` | yes | one read of `Data/SKSE/Plugins/SmartTalk.ini` through `MiscUtil.FileExists`/`ReadFromFile`; false on any non-zero offender or an unreadable file |

### 1.2 Functions added beyond §1.2 (all additive; no binding signature changed)

Needed either by the plan itself or because `LRG_DlgProbe` may not contain a `UI.*` call.

| added | why |
|---|---|
| `string SmartTalkOffender()` | plan §3.1 item 6: names the **first offending setting** for lane B's one notification; `"unreadable"` when the ini cannot be read, `""` when safe |
| `int SmartTalkSetting(key, default)` | the MCM help quotes `iPapyrusHandle` (live 3, never to be changed); P7s logs it |
| `int ReadMode()` / `SetReadMode(int)` / `int CountMode()` / `SetCountMode(int)` | plan §3.1 item 2 (lane B persists them into MCM). `SetReadMode` **refuses 5** |
| `int ReadModeAuto()` / `int CalibrateReadMode()` | the §1.7 calibration ("mode 3, else mode 4"), so lane B does not re-implement it |
| `Function ResetCalibration()` | a new game session, or a probe re-run |
| `string ListPath()` / `int ListPathId()` | which of the two list spellings is live (internal + diagnostics) |
| `int CountRaw(int mode)` | **P2** logs all three count modes on the same list |
| `int ClickResult()` | `Click()` is void in the frozen signature and cannot report an abort. 1/2 = invoked route B/A, 0 = never tried, −1 read-back failed, −2 identity mismatch, −3 no fresh verified selection, −4 closed or unknown route |
| `int LastUnhide()` | 1 = restored, **2 = no usable stored value ⇒ lane B reports `why=no-stored-x`**, 3 = menu closed |
| `bool IsHidden()` | whether this script is currently holding the list off-screen |
| `Function ForceListState()` | the **second** un-park attempt of §1.10 step 4 (`SetInt eMenuState 1`), which §1.2 describes but names no primitive for |
| `int StateWriteBack(int)` | **P10**'s write-then-read-back pair; no `Invoke`, no click |
| `bool TextInputOn()` | so a key handler outside this file never needs `UI.IsTextInputEnabled()` |
| `bool OtherMenuOpen(name)` | **P15** watches `BarterMenu` without a `UI.*` call of its own |
| `HolderXInt`, `HolderX`, `HolderY`, `ExitButtonX`, `ExitButtonShown`, `SubtitleShown`, `SpeakerNameShown`, `CursorShown`, `AllowProgress`, `AllowProgressDelay`, `DisableInput`, `SelectedIndex`, `SelectedTopicIndex`, `SelectedText`, `HideTopicsVar` | the raw values the probe's binding log lines need (P2, P6, P7, P7s, P11). All read-only |
| `bool CreateProbeClip()` / `string ProbeClipName()` | **P12** (`InvokeStringA _root.createEmptyMovieClip`) |
| `bool Usable(float)`, `TextProbeOn`, `CountRawOn`, `IniInt`, `IniDigitsAt`, `SInt`…`SSetStr` | internal helpers (Papyrus has no private scope for a global function) |

### 1.3 Hard rules 1–11 of design §1.2 — compliance

| rule | how it is met |
|---|---|
| 1 no `SkipText` / `onCancelPress` / `SetSelectedIndexByMouse` | none of the three strings appears in the file |
| 2 never `startTopicClickedTimer` after `onSelectionClick` | `startTopicClickedTimer` is never referenced at all |
| 3 never a truthy argument to `onSelectionClick` | `UI.InvokeBool(..., false)`, the explicit form |
| 4 never `_visible`/`_alpha` to hide the topic list | the list is moved with `_x`; `_visible` is used only on `DialogueMenu_mc` (hide mode 2, probe), `SubtitleText`, `SpeakerName`, the read of `ExitButton` and the cursor |
| 5 never a negative value through `UI.SetInt` on a UInt32 member | the only `SetInt`s are `iSelectedIndex`, `iScrollPosition`, `eMenuState`, `ALLOW_PROGRESS_DELAY`, all non-negative |
| 6 getter by ActionScript type | `GetString` text/HIDE_TOPICS, `GetInt` topicIndex/eMenuState/length/iSelectedIndex/textColor/itemIndex/iMaxItemsShown/iPlatform/iAllowProgressTimerID/ALLOW_PROGRESS_DELAY, `GetBool` topicIsNew/timerBool/bAllowProgress/bDisableInput/_visible, `GetFloat` every `_x`/`_y` and `iMaxScrollPosition`. **A topicIndex of 0 is never treated as a failure** (`EntryTopicIndex` returns −1 for "could not read", and a read is validated by a positive count plus a non-empty text) |
| 7 every wait is `Utility.WaitMenuMode` | `LRG_DlgUI` contains **no wait at all**; the probe uses only `Utility.WaitMenuMode` |
| 8 `Set*` targets must exist / a `Set*` reaches nothing when closed | every function tests `IsOpen()` first, except `GuardPoke()` (deliberate: one native) and `HideCursor` |
| 9 display properties through `GetFloat`/`SetFloat` into a float | all reads and all writes of `_x`/`_y` are `GetFloat`/`SetFloat`. The only `GetInt` of an `_x` is `HolderXInt()`, which exists **to demonstrate the trap in P6** |
| 10 a stored original is usable only if finite, not NaN, `abs() < 10000`, tagged with `fam` | `Usable()` is the single test `v > -10000.0 && v < 10000.0` — NaN and both infinities fail every comparison, so one test covers all three conditions; `hfam` is stored beside the values and re-checked in `Unhide()`. `Hide()` returns false and hides nothing otherwise; `Unhide()` refuses to write and `LastUnhide()` says 2 |
| 11 mode 5 is read-only | mode 5 appears only in the `EntryText`/`EntryTopicIndex`/`EntryIsNew` reads, `EntryColour` and `EntryRowItem`. `SelectAndVerify` and `Click` take array positions only, and `SetReadMode(5)` is refused |

### 1.4 The safety contract with the menu closed, and the cost

Every function returns `false` / `0` / `""` / `−1` and writes nothing when the menu is closed.
`EntryCount()` returns **0** when closed and **−1 only** when the menu is open, a text is readable and no
count mode works — exactly the split lane B's `why=no-count` vs `why=no-entries` needs. No function waits,
loops unboundedly or raises a notification (`Debug.Notification` appears only in the probe, which is
owner-driven by design).

A calibrated `EntryCount()` costs one `StorageUtil` read (not a delayed native) plus one `UI.GetInt`. When
the calibrated mode reports 0 it does **one** cheap text probe and returns 0 instead of re-running the
six-read calibration — without that test every empty 0.1 s poll during a response would cost eight
delayed natives.

---

## 2. `LRG_DlgProbe.psc` — the press table lane B builds the MCM page from

`Scriptname LRG_DlgProbe extends Quest`. Output **only** through `LRG_Main.LogC(cid, msg, npc)`. Every
line is `PROBE P<n> ` + the binding `k=v` pairs of plan §10.2, then ` at=<real time to 0.01 s> npc=<display
name>`, so a press is answerable from the log alone. The whole set is
`grep "GAME .*PROBE P" lorerim_glue.log`.

`LRG_Main.CleanForWire` turns `;` into `,`, `|` into `/`, `@` into " at " and `"` into `'` on the way out,
so **texts are quoted with single quotes** (`text='...'`, not `text="..."`) and no probe line ever
contains a semicolon. `LogC` truncates at 300 characters, which is why a few presses emit two lines with
the same prefix.

### 2.1 Button ids — `RunStep(int)`, labels from `StepName(int)`

Plan §9.3's list is a subset; this is the complete set. Ids 1–5 are also the cycling `iKeyProbe` presses,
in this order, wrapping. **Every other id is a button only** and can never fire by accident.

| id | label | presses answered | read-only |
|---|---|---|---|
| 1 | Run read phase | P2, P3, P4, P5, P3t | yes |
| 2 | Run hide + guard | P6 (H2), P7 | yes |
| 3 | Run click, chosen route | P8v then P8 | **no** |
| 4 | Run click, other route | P9 | **no** |
| 5 | Close | P11 close half | **no** (`CloseClean`) |
| 10 | P0 environment, before CHIM spoke | P0 | yes |
| 11 | P0 environment, after CHIM spoke | P0 (`phase=after-chim`) | yes |
| 12 | P0b Activate default vs default-only | P0b | **no** (opens and closes sessions) |
| 13 | P1 state and timer id sampling | P1 | yes |
| 14 | P3t read timing only | P3t | yes |
| 15 | P6 H1 full hide comparison | P6 `hide=H1` | yes |
| 16 | P6f family-2 run (CHIM SWF hidden in MO2) | P6f | yes |
| 17 | P7s Smart Talk hold test | P7s | yes |
| 18 | P7p park, courier push | P7p `push=courier` | yes |
| 19 | P7p park, greeting push | P7p `push=greeting` | yes |
| 20 | P8v wrong topicIndex defence | P8v | **no** (it must attempt a click to prove the refusal) |
| 21 | P10 state read-back gate | P10 | yes (writes `eMenuState`, never clicks) |
| 22 | **P11 re-open read — run FIRST after a hand re-open** | P11 reopen half | yes |
| 23 | P11b CloseForce, harmless layer only | P11b | **no** (`CloseForce`) |
| 24 | P12 helper-SWF injection | P12 | yes |
| 25 | P13 wire cost and payload length | P13 | yes |
| 26 | P14 delivery route a (logMessageForActor) | P14 | yes |
| 27 | P14 delivery route c (requestMessageForActor) | P14 | yes (costs one LLM request) |
| 28 | P15 barter menu states | P15 | yes |
| 29 | P16 idle auto-close 30/60/120 s | P16 | yes |
| 30 | P17 row colours and itemIndex | P17 | yes |
| 31 | Reset read/count calibration | — | yes |

`RunStepByName(string)` exists for an MCM button that can only pass a string. Every `RunStep` refuses with
one notification while `bProbe:Dialogue` is 0.

### 2.2 Read-only is enforced in code, not by discipline

A member `bool readOnly` is set at the top of every press. The **only three** helpers that can perturb a
session — `DoClick`, `DoCloseClean`, `DoCloseForce` — call `ClickAllowed()`, which refuses while
`readOnly` is true **and** refuses on the read-only rows of the matrix: `IsGuard()`,
`GetCurrentScene() != None`, or a crime faction with `GetCrimeGold() > 0` (the local duplicate of the
driver's LETHAL test — the type `LRG_Dialogue` is never referenced, so lane A compiles alone).
`CloseForce` is reachable from P11b and nowhere else, and P11b itself refuses on the same three tests.

### 2.3 The log lines, as implemented (deviations from plan §10.2 flagged)

Binding keys are present, in order, at the front of every line; extra keys follow.

* **P0** — two lines. Line 1 exactly as §10.2 (`menumode subs ptts capbg onscene openmic autoradius
  vt_base vt_lev phase`); the voice EditorID is read on **both** bases (`GetActorBase()` and
  `GetLeveledActorBase()`) through `PO3_SKSEFunctions.GetFormEditorID`. Line 2 is `PROBE P0 open= fam=
  maxitems= platform= rm= cm= stsafe= stoff= agent= phase=` — the self-test block of design §1.11 minus
  the five Speech globals, which lane B owns (a `GlobalVariable` read is not a UI call and belongs in the
  driver).
* **P0b** — as §10.2, three tries per mode, `CloseClean` between tries.
* **P1** — batched, 4 batches × 12 samples 0.1 s apart: `PROBE P1 n=12 t=<12 ms values> st=<12>
  timerid=<12> sub='<24>'`. This is the plan's own batched form.
* **P2** — as §10.2 plus ` n= cm= lp=`. `firstlist_ms` is measured from the press, or from the `Activate`
  when the press opened the session itself.
* **P3 / P4 / P5** — one line per entry, up to 12 / 12 / `iMaxItemsShown`. **All reads happen first and
  the lines are emitted afterwards** (plan: "batch a sample set, then log"), 0.05 s apart so the
  fire-and-forget transport is not flooded. The P4 pass restores the original `iSelectedIndex` afterwards
  with a bare `SelectAndVerify(sel0, -1, "", false)`, which does **not** authorise a click.
* **P3t** — one line per mode plus a third line carrying `rm= cm= n= rows=`. `tail24_ms` is a real
  24-position texts-only pass in mode 3 (reads past the end still cost their frame, which is the
  measurement wanted).
* **P6 / P6f / P6-H1** — two lines. Line 1 exactly as §10.2 (`hide=H1|H2 x_int x_float y_float sub_shown
  cursor reached1 flash_frames`); `x_int` and `x_float` are read **before** hiding and `x_float` prints
  `nan` for a non-finite value — rule 9's and rule 10's demonstration in one line. Line 2 adds `hidok=
  fam= exitbtn= speaker= st= n=`. `flash_frames` = polls until the holder really reads off-screen.
* **P7** — as §10.2 plus ` sel0= sel1= dis=`; a 15 s input window. **`skipped` is a heuristic**: the
  subtitle or the progress timer id moved during the window. `clicks=3 e=3 space=3 wheel=3` is the
  instruction the press gave the owner, not a measurement. `GuardPoke()` is deliberately **not** called
  in P7 — that is P7s's question.
* **P7s** — as §10.2 plus ` offender= papyrushandle=`.
* **P7p** — as §10.2 plus ` st=`. A 30 s window with `GuardPoke()` on every poll, then `ShowList()` and a
  3 s watch for the deferred list.
* **P8 / P9** — **two lines** (the 300-char cap): line 1 `route clicked_pos ti timerid=a->b modevent
  next_layer responses` plus ` went= sigchg=`; line 2 `PROBE P8 cnt=<18 samples> gate=<18 samples>`.
  Sampling is 0.1 s × 50 = 5 s. `->` replaces the plan's `→` so the grep stays ASCII. `modevent` is the
  `PlayMenuTopic` mod event, registered by the probe itself.
* **P8v** — as §10.2 plus ` clk= real_ti=`. It selects the right position with a **wrong** topicIndex and
  then really calls `Click()`, so the line proves `verified=0 clicked=0`.
* **P10** — as §10.2 plus ` st_before= st_after=`; the state is put back afterwards, and `ShowList()`
  runs if it had been 1.
* **P11** — **three lines**, one owner action each: `phase=close` (`closeclean_ms onmenuclose_seen
  closed`), `phase=reopen` (`reopen_visible allow_delay_on_reopen x_restored onmenuopen_seen
  onmenuclose_seen` plus ` x= st= n= ap= hidflag=`), `phase=reopen-clicks` (`click_mouse click_e
  click_enter`). One line cannot span two owner actions and stay under 300 characters. The reopen press
  **writes nothing**, so `allow_delay_on_reopen` is the pristine value.
  **`click_mouse` / `click_e` / `click_enter` are measured indirectly**: a three-second guided window per
  input, where "clicked" means the layer signature, the entry count or the progress timer id moved, or the
  menu closed. Papyrus cannot observe a mouse button.
* **P11b** — as §10.2. **`walkaway_line` is always `-1`**: whether a farewell line played cannot be read
  from Papyrus (there is no engine-dialogue-audio getter, and `isActorTalking` only ever sees CHIM's own
  TTS). The press notifies the owner to listen and the owner writes it into the playtest notes. Step 2
  needs a hand re-open after step 1 closed the menu, so P11b is pressed twice.
* **P12 / P13 / P14 / P15 / P16 / P17** — as §10.2. P13 sends the real payload (head, plus a `part=2` tail
  when `n > 16`) so the server log can confirm it arrived intact, and adds ` tail_chars= n=`.
  **P14 logs `arrived_ms=-1` by design**: the probe does **not** register `CHIM_CommandReceived`, because
  a second callback for that event on the same form could replace Phase 1's own and break every command.
  The arrival is therefore the next `GAME ... command ExtCmdLRG_SelectTopic ... param=` line, which
  `LRG_Main.HandleCommand` already logs for every incoming command (`LRG_Main.psc:407`) — it carries the
  same `x` the press printed, so the two lines pair up. Until lane B's `HandleCommand` branch exists the
  command is answered `Error: unknown command`, which is harmless and also logged.

### 2.4 What the probe assumes of the rest of the project

* `LRG_Main.LogC`, `NextCid`, `CleanForWire`, `ReplaceChar`, `SendNpcMessage`, `SettingBool`, `SettingInt`
  — all exist today and are called as they are.
* `LRG_Main.RequestTick` is **not** used: no press needs a tick, everything runs inside the key event with
  `Utility.WaitMenuMode`. A press therefore blocks this form's event queue for as long as its window
  lasts (P16 is 120 s by design). That is acceptable for an owner-driven probe, and is why the long
  presses are buttons rather than hotkey phases.
* `OnInit()` calls `Maintenance()`, and `Maintenance()` is idempotent. **Until lane B's
  `LRG_Main.Maintenance()` edit lands, the probe's key and its menu / mod-event registrations are lost on
  every game load**, because `LRG_Main.Maintenance()` calls the form-wide `UnregisterForAllModEvents()`
  and `UnregisterForAllKeys()`. With the edit in place (`GetProbe().Maintenance()` after
  `ost.Maintenance()`) it re-arms on every load.
* `iKeyProbe` absent from `settings.ini` reads as 0 ⇒ no registration, no crash. `bProbe = 0` disarms the
  key **and** makes every `RunStep` refuse.
* The probe registers `RegisterForMenu("Dialogue Menu")` (for `onmenuopen_seen` / `onmenuclose_seen`,
  E-R2) and `RegisterForModEvent("PlayMenuTopic", "OnLrgProbePlayMenuTopic")` — CHIM-SWF-only, and no
  other glue script registers it.

---

## 3. Gate evidence (commands run, with their output)

Lane A gate (plan §3.4). Windows PowerShell 5.1, no `&&`.

```
powershell -File glue\tools\compile.ps1
rounds: 2 ; auto-stubs created: 23
  associationtype, class, effectshader, encounterzone, explosion, furniture, hazard, idle,
  imagespacemodifier, impactdataset, key, light, locationreftype, message, package, projectile,
  scene, static, topic, visualeffect, voicetype, wordofpower, worldspace
compiled: LRG.pex, LRG_DlgProbe.pex, LRG_DlgUI.pex, LRG_Main.pex, LRG_MCM.pex, LRG_OStim.pex,
          LRG_PlayerAlias.pex, LRG_Profile.pex
OK - .pex files copied to game\LoreRimGlue\Scripts (only the glue's own scripts); compiler reported
     0 errors, 0 warnings
```

* **No unexpected auto-stub.** The same command with the two new scripts moved out of the folder produces
  the **identical** 23-stub list, so lane A adds **zero** stubs. `scene` and `voicetype` were already in
  the baseline (they come from `ObjectReference.psc` / `Race.psc`), exactly as plan §1.3 predicted.
* **`compile.ps1` needs no target-list edit**: it takes its targets from
  `Get-ChildItem $src -Filter *.psc` (`compile.ps1:61`), so new files are picked up automatically. Only
  the import/declaration edit of **D-A2** was necessary.
* **No `.pex` string over 500 chars** (compile.ps1's own guard) — measured directly:
  `LRG_DlgUI.pex` 509 strings, longest **286**; `LRG_DlgProbe.pex` 896 strings, longest **266**. Every
  `{docstring}` in both files is under 295 characters (longest 288 and 268), so the Papyrus rule of plan
  §1.4 holds with margin.
* **`LRG_DlgUI` compiles with zero calls into `LRG_Dialogue`**, and the probe does not reference the type
  either: the only two hits of the string in both files are inside `;` comments.
  `Select-String -Pattern 'LRG_Dialogue'` → 2 matches, both comment lines, **0 code references**.
* **No `UI.*` call in `LRG_DlgProbe.psc`**:
  `Select-String -CaseSensitive -Pattern '(^|[^A-Za-z0-9_])UI\.'` → **0**.
* **Every `UI.*` call in the project is in `LRG_DlgUI.psc`** (145 of them). The only other matches in the
  whole project are Phase 1's two pre-existing statements in `LRG_Main.psc` — `:209`
  `if Utility.IsInMenuMode() || UI.IsTextInputEnabled()` and `:680` the `MaybeInitiative` twin, which are
  the plan's own §1.2 anchors and must stay. Lane A added none.

**A correction to the gate command itself.** Plan §3.4 and appendix B give
`grep -c "UI\." LRG_DlgProbe.psc` == 0 / `findstr /C:"UI." … # must be empty`. **That test is not
satisfiable by any script that calls `LRG_DlgUI`**, because the substring `UI.` occurs inside every
`LRG_DlgUI.Foo()` call (172 naive matches here). The intended check needs a word boundary. Use one of:

```
powershell -Command "(Select-String -CaseSensitive -Path <file> -Pattern '(^|[^A-Za-z0-9_])UI\.').Count"
grep -cE "(^|[^A-Za-z0-9_])UI\." <file>        # inside WSL
```

**Build state note.** Lane B's `LRG_Dialogue.psc` and its `LRG_Main.psc` edits landed in the shared
`Source\Scripts` folder while this lane was finishing. The output quoted above is the **final** state:
`compile.ps1` compiles all **nine** scripts (`LRG_Dialogue.pex` included) and prints `OK`, with the same
23-stub baseline. (Mid-round there were two transient windows where a full run stopped on their
work-in-progress file — `mismatched input 'state'`, then `Maintenance is not a function` while
`LRG_Dialogue.psc` was briefly absent from a copy. Lane A's evidence was taken from an isolated run of the
unmodified `compile.ps1` over a copy of the folder in those windows; lane A never touched their file.)

**The seam is already in use and agrees.** Lane B's driver calls exactly the contract of §4:
`LRG_DlgUI.SelectAndVerify(pos, idx, txt, route == 1)` → `Click(route)` → `ClickResult()` with no wait in
between (`LRG_Dialogue.psc:1586-1607`), treats `cr <= 0` as "nothing was clicked" and recovers with
`ShowList()`, uses `LastUnhide() == 2` for `why=no-stored-x` (`:1937`), `ForceListState()` as the second
attempt (`:1949`), `SetReadMode`/`SetCountMode`/`ReadMode`/`CountMode` for MCM persistence and
`SmartTalkOffender()` in its one notification (`:374`). No signature needs to change.

---

## 4. Things found while building that the other lanes need (corrections)

**B-A1 — the double-`Hide()` trap (fixed here, but lane B must know why).** `Hide()` stores the live
`TopicListHolder._x` as "the original". If `Hide()` is called a second time while the list is already
off-screen, the stored original would become our own `5000.0`, and `Unhide()` would then faithfully
"restore" the list to off-screen — the emergency key handing back a menu with a speaker name, a subtitle
and no topics, which is the exact failure the key exists to prevent. `Hide()` now detects
`_x > 4000 && hid == 1 && hok == 1 && hfam == fam`, calls `ReassertHide()` and returns true **without**
re-storing. Lane B may therefore call `Hide()` as often as it likes.

**C-A1 — `Click()` refuses unless `SelectAndVerify()` verified an identity within 2 seconds.** §1.7
requires the identity pair to be the **last** frame-synced work before the queued `Invoke`, but the frozen
`Click(int)` signature carries no identity. `SelectAndVerify` therefore *stamps* the verified
`topicIndex` + text prefix, and `Click()` re-checks them after the state read-back, immediately before the
`Invoke`. Consequences for lane B:
* call `SelectAndVerify(pos, ti, txtPrefix, route == 1)` and then `Click(route)` — in that order, with no
  intervening `SelectAndVerify`;
* a `SelectAndVerify(pos, -1, "", false)` is a **bare selection write that does not authorise a click**
  (`ClickResult()` would be −3);
* the stamp is consumed by one `Invoke`, so a second `Click()` without a new `SelectAndVerify` does
  nothing (`−3`) — one click per verified selection, by construction;
* `ClickResult()` is how lane B learns an abort happened: `−1` read-back, `−2` stale identity, `−3` no
  fresh selection, `−4` closed/unknown route. All of them mean **nothing was clicked**.

**C-A2 — on an aborted click the menu keeps the click state.** The `eMenuState` write happens before the
identity pair (that is the binding §1.7 order). If the identity check then fails, the state stays at 2
(route B) or 1 (route A) with nothing clicked. `ShowList()` is the recovery, exactly as §1.10 step 4 says;
lane B's READING path must do it rather than assume the state was rolled back.

**C-A3 — `ref=` is hex in wire v0.4 but decimal in Phase 1.** Plan §2.1 specifies `ref=<hex>` for
`lrg_topics` / `lrg_dlg`, while Phase 1's snapshot sends `;ref=` as a **decimal** int
(`LRG_Profile.psc:331`). The probe's P13/P14 payload follows the v0.4 spec and sends 8-digit uppercase
hex. Lane B and lane C must agree on this before the driver ships, or every `ref` comparison fails.

**C-A4 — `SwfFamily()` can read 0 before `EntryCount()` has run** on a load order where the fallback list
spelling is the live one, because the family test uses `iMaxItemsShown` on the list path. It now retries
the other spelling, but the robust order in ARMING is still: `GuardReset()` → `EntryCount()` (which
calibrates the path) → `SwfFamily()` → route choice.

---

## 5. Deviations from the plan / design, and why

| id | deviation | why, and what it costs |
|---|---|---|
| **D-A1** | `LRG_DlgUI`'s remembered state lives in **`StorageUtil`** (PapyrusUtil), not in script variables | §0.1: a Papyrus global function cannot see a script variable — proven with the compiler. No signature changed. Cost: a dependency on PapyrusUtil (installed and enabled) and per-save (not per-profile) persistence. Lane B still owns MCM persistence, and `ResetCalibration()` exists for a fresh game session |
| **D-A2** | **`glue/tools/compile.ps1` was edited** (lane B's file): a `foreach` over `MiscUtil` and `StorageUtil` through the existing `Export-NativeDeclarations` helper | §0.2: without it `LRG_DlgUI` cannot compile at all. The edit is additive, sits beside the identical OStim and Survival-Mode blocks, and changes nothing else. **Lane B must keep it** (see §6) |
| **D-A3** | `SelectAndVerify` stamps the identity and `Click()` re-verifies it | §4 **C-A1**: the frozen signatures cannot carry the expectation, and §1.7 demands the identity pair be last |
| **D-A4** | Functions added beyond §1.2 (§1.2 table above), including `ClickResult()`, `LastUnhide()`, `ForceListState()`, `StateWriteBack()`, `SmartTalkOffender()`, the calibration accessors and 15 read-only probe accessors | the plan asks for several of them by name; the rest exist because the probe may contain no `UI.*` call and `Click()`/`Unhide()` are void. Nothing existing was renamed, so the DLL seam is intact |
| **D-A5** | `MenuState()` returns **−1** when the menu is closed (design implies a raw `GetInt`, which would give 0) | 0 means "speaking" and would be read as a live state. `EntryCount()`'s 0/−1 split is as specified |
| **D-A6** | `EntryCount()` does not re-calibrate on every empty read; it believes a calibrated mode unless a text is readable | §1.4: otherwise every empty 0.1 s poll would cost eight delayed natives. A broken mode is still caught within one poll |
| **D-A7** | The probe's texts are quoted with `'` (`text='…'`) rather than `"`, and `→` is written `->` | `LRG_Main.CleanForWire` replaces `"` on the wire; ASCII keeps the grep simple |
| **D-A8** | P6/P8/P9/P0/P3t/P11 emit **two or three** lines instead of one | `LogC` truncates at 300 characters, and P11's three lines each correspond to a separate owner action |
| **D-A9** | `walkaway_line` in P11b is always `-1`; `click_mouse/e/enter` in P11 are inferred from layer movement | neither is observable from Papyrus. Both are flagged in the line itself so the owner knows what the value means |
| **D-A10** | P14 reports `arrived_ms=-1`; arrival is read from `LRG_Main`'s own command log line | §2.3: registering a second `CHIM_CommandReceived` callback on this form could replace Phase 1's and break every command. Not worth the risk for one measurement that the log already carries |
| **D-A11** | The probe's press ids extend plan §9.3's button list (10–31 instead of ten named buttons) | §9.3 lists only the presses it happened to name; every press in design §1.12 needs a button. `StepName(int)` supplies the labels so lane B can build the page mechanically |
| **D-A12** | Papyrus has no `\r` escape, so CR is `StringUtil.AsChar(13)`, and the Smart Talk ini is scanned with `StringUtil.Find` rather than `StringUtil.Split` | the compiler rejects `"\r"` (measured), and an SKSE string array caps at 128 entries while `SmartTalk.ini` has more lines than that |

**Nothing in this lane needed an install.** No C++ tooling, no SKSE plugin build, no AS2/SWF compiler, no
new mod. PapyrusUtil was already installed and enabled; only the compiler's declaration list had to learn
about it. The SWF-injection question is untouched and stays decision **D1 plan C** — **P12** measures
whether it is even possible (`UI.InvokeStringA _root.createEmptyMovieClip` + a read-back of
`_root.lrgProbe._name`), so the answer is ready if `P3` ever fails. For completeness, the two options if
`P3`/`P4` both fail (plan §11): the **helper-SWF route** needs an AS2 build tool (MTASC or FFDec) and
would plug into `EntryText`/`EntryCount` with no driver change, gaining a one-call list read; the
**Papyrus-only fallback** that exists today is mode 5, which indexes screen rows and therefore cannot feed
a click — so "only P5 works" is a dead end, not a degradation, and goes to the owner as **D1**.

---

## 6. `forIntegrator` notes

1. **Keep the `compile.ps1` edit (D-A2).** If it is reverted, `LRG_DlgUI` stops compiling with
   `unknown type miscutil` / `unknown type storageutil` and the auto-stub loop hides the real cause behind
   an empty stub. The block is marked with a comment naming this round. Plan §1.3's claim that PapyrusUtil
   is already imported should be corrected for the next round.
2. **Call `GetProbe().Maintenance()` from `LRG_Main.Maintenance()`** (plan §4.4 edit 2), after the
   existing `UnregisterForAllModEvents()` / `RegisterKeys()`. Without it the probe is dead after every
   game load (§2.4).
3. **`iKeyProbe:Keys` and `bProbe:Dialogue` both need their `settings.ini` default lines** (`0` and `0`),
   or the missing-key rule reads them as 0 anyway — which is the safe default here, but the MCM page then
   shows nothing.
4. **MCM buttons**: use `RunStep(int)` with the ids of §2.1, labels from `StepName(int)`. Ids 3, 4, 5, 12,
   20 and 23 can perturb a session (they click, close or force-close) and should be visually separated
   from the read-only ones. Id **22** must be pressed **first** after a hand re-open, or something else
   will have written `ALLOW_PROGRESS_DELAY` before it is read.
5. **Lane B's driver contract with this file**, in one place: `GuardReset()` at the top of every ARMING →
   `EntryCount()` (calibrates path and count mode) → `SwfFamily()` → route → `Hide(iHideMode)` (false ⇒
   run assisted) → `Guard(true)` → `GuardPoke()` every poll, `Guard(true)` + `ReassertHide()` every third
   → `Park()` only inside the click window → `SelectAndVerify(...)` → `Click(route)` → `ClickResult()` →
   settle poll → `Unhide()` + `LastUnhide()` for the `why`. `CloseForce` is never called by the driver.
6. **`SmartTalkSafe()` is `false` on this machine today** (`bSkipImmediateOnInput = 1`,
   `bHoldToSkip = 1`, `bSkipOnInteraction = 0` — re-read this round at `SmartTalk.ini:92,95,104`), so the
   hard precondition of plan §13 is not met until the owner sets the first two to 0.
   `SmartTalkOffender()` returns the name for the notification, and `SmartTalkSetting("iPapyrusHandle", -1)`
   reads the setting the help text says never to change (live **3**, `:116`).
7. **`ref=` hex vs decimal** — §4 **C-A3**, needs a decision between lanes B and C.
8. Lane A wrote nothing under `F:\Modlists\**`, touched no OStim / OARE / LoreRim / CHIM / HerikaServer
   file, no other mod's settings file, and ran neither Nemesis nor LOOT. The only files created or
   changed: the two `.psc`, their two `.pex` in `glue\game\LoreRimGlue\Scripts`, the `compile.ps1` block,
   and this report.

---

## 7. The `[U]`s this lane leaves open, and which press answers each

Lane A builds the instrument; every one of these is answered by a press, not by an argument.

| open question | press that answers it | what a red result means |
|---|---|---|
| Do numeric GFx array-index paths resolve on this menu at all? | **P3** (`EntriesA.<i>.text`), **P4** as the alternative | both red ⇒ decision **D1**; "only P5 works" is a failure, not a fallback (mode 5 is screen rows) |
| Does any entry-count mode work, and what is `iPlatform`? | **P2** (`cntA cntB cntC platform`) | all three 0 with a readable text ⇒ `EntryCount()` returns −1, `why=no-count`, and **D1** |
| Which list spelling is live? | **P2**'s `lp=` | `lp=2` means the fallback spelling won; everything already adapts |
| Does `ALLOW_PROGRESS_DELAY` survive a close? | **P11** id 22 (`allow_delay_on_reopen`, must read **750**) | `100000000` makes `GuardReset()` the only thing between the owner and permanently unclickable vanilla dialogue |
| Does `iAllowProgressTimerID` move per line or per session? | **P1** | per session ⇒ the line-settle gate falls back to `MenuState() == 1` + the subtitle test |
| Is an engine-pushed list dropped or deferred while parked? | **P7p** (ids 18, 19) | dropped ⇒ the park stays inside the click window only |
| Do hide, store and un-hide work on SWF family 2? | **P6f** (id 16), with CHIM's `dialoguemenu.swf` hidden in MO2 | a `nan` `x_float` proves rule 10's refusal path is load-bearing |
| Is the `GetInt`-vs-`GetFloat` trap real here? | **P6**'s `x_int` vs `x_float` on the same property | they differ ⇒ rule 9 is confirmed in the field |
| Is the guard sufficient without G1b? | **P7**; then **P7s** for Smart Talk's native write | `found_true > 0` in P7s ⇒ the ini precondition (D-21) is the only real fix |
| Is the state read-back gate reliable? | **P10** (id 21) | any `read != 2` ⇒ `Click()` aborts with `ClickResult() == -1`, which is the safe direction |
| Does a scripted click behave like a human one? | **P8** / **P9** (`responses=1`, `next_layer=1`, `timerid` moved, `cnt` collapse) | more than one response ⇒ a double `TopicClicked`; stop and report |
| Does the stale defence hold? | **P8v** (id 20), must read `verified=0 clicked=0` | anything else is a release blocker |
| Is helper-SWF injection possible? | **P12** (id 24) | only needed if P3 and P4 both fail |
| What does a full payload really cost, and does it arrive? | **P13** (id 25) + the server log | over ~2,400 chars ⇒ lower `iMaxEntries` / the tail cap |
| Which delivery route works, and how fast? | **P14** (ids 26, 27) + the paired `GAME command` log line | a P14a failure makes a business turn 11–14 s instead of 6–9 s |
| Do `OnMenuOpen` / `OnMenuClose` still fire under the hide (E-R2)? | **P11** `onmenuopen_seen` / `onmenuclose_seen` | red ⇒ the driver cannot rely on menu events while hidden |
| Does a script-side close fire the walk-away path (E-R7)? | **P11b** (id 23), harmless closed layer only — and the owner's ears for `walkaway_line` | `CloseForce` stays probe-only regardless |
| Is Smart Talk's quest colour readable at all? | **P17** (id 30) | never seeing 16767334 ⇒ `bQuestColour` stays 0 (it may simply be inert) |
| Does the engine's aware-player timer close a hidden idle session? | **P16** (id 29) | closes at 30 s ⇒ `fSilenceTimeout` must drop below it |
| Barter / SUSPENDED behaviour | **P15** (id 28) | `showlist_needed=1` ⇒ the driver must `ShowList()` after a service menu |
| Which actor base does CHIM null? | **P0** ids 10 and 11 (`vt_base` vs `vt_lev`, before and after a CHIM exchange) | decides the voice keeper's target |
| Is the Dialogue Menu a pausing menu here? | **P0**'s `menumode` | a 1 would kill every CHIM hotkey and change D-13's scope |
| `Activate` default vs default-only | **P0b** (id 12) | sets `bActivateDefaultOnly` |

**Pass set for the Papyrus-only route** (unchanged from plan §10.2): P1, P2 with at least one count mode,
(P3 **or** P4), P6-H2, P7, P7p, (P8 **or** P9), P10, **P11**. Anything red goes to **D1** or to the owner,
never into the driver.
