# pt8 VERIFY — DESIGN CONFORMANCE + GAME SAFETY (independent, read-only)

Date 2026-09-22. Lens: design conformance + game safety. Target: v0.4.0 as it sits in the tree.
Nothing in this round was changed by me; this file is the only thing I wrote.

**VERDICT: FIX FIRST.** Five majors, six minors. Nothing here is a crash or a save-corrupter and
nothing violates the round's three hard rails (no `SetStage`/`SetObjectiveCompleted`/`CompleteQuest`/
TIF anywhere; dry-run clicks nothing; the driver only ever selects a real entry in a real session).
But **three of the five majors bite at the SHIPPED defaults**, and one of them silently turns the
owner's probe evening into a false green.

---

## 0. What I ran and what it says

| Check | Result |
|---|---|
| `tools/compile.ps1` | **OK.** 9 scripts (`LRG`, `LRG_Dialogue`, `LRG_DlgProbe`, `LRG_DlgUI`, `LRG_Main`, `LRG_MCM`, `LRG_OStim`, `LRG_PlayerAlias`, `LRG_Profile`), 0 errors, 0 warnings, rounds 2, auto-stubs 23. The `IsDriving` error the INTIMACY report describes is **gone** — that report is stale. |
| `tools/esp_dump.py` | `VMAD` of `0x01000800` lists **four** script names (`LRG_Main`, `LRG_OStim`, `LRG_Dialogue`, `LRG_DlgProbe`), 0 properties each; `HEDR` still `1.70 / 3 / 0x00000802`; `PlayerAlias` unchanged; `0x01000801` MCM quest untouched. Matches design §1.1. |
| never-do grep, **sources** | `SetStage`, `SetObjectiveCompleted`, `SetObjectiveDisplayed`, `CompleteQuest`, `TIF_`, `SkipText`, `onCancelPress`, `SetSelectedIndexByMouse`, `startTopicClickedTimer`, `setLocked`, `stopAllDialogue` → **0 call sites**; the only hits are three words inside `LRG_Dialogue.psc:8`'s own never-do comment. |
| never-do grep, **compiled `.pex`** | I scanned all nine `.pex` string tables for those names plus `SetCrimeGold`, `SetBribed`, `SetIntimidated` → **0 hits**. A Papyrus call name always reaches the string table, so this is proof, not a source-level promise. |
| `UI.*` confinement | Word-boundary scan of every `.psc`: every real `UI.*` call is in `LRG_DlgUI.psc` except `LRG_Main.psc:249` and `:751` — the design's own two Phase 1 anchors. `LRG_Dialogue`, `LRG_DlgProbe`, `LRG_OStim`, `LRG_Profile`: **zero**. |
| `.pex` string cap | `compile.ps1`'s own 500-char gate ran and passed (it parses each string table and refuses the build otherwise). |
| docstrings < 300 | Longest per file: `LRG_DlgUI` 288, `LRG_OStim` 284, `LRG_Profile` 273, `LRG_DlgProbe` 268, `LRG_Main` 265, `LRG_Dialogue` 251. All under 300. |
| MCM cross-check | `config.json` parses; 7 pages; 82 control ids; **every id has a `settings.ini` default**, **every ini key has a control**, **no duplicate ids**. Every control's `sourceType` matches the type the script reads it with (`ModSettingBool`↔`SettingBool`, `ModSettingInt`↔`SettingInt`, `ModSettingFloat`↔`SettingFloat`) — checked one by one, including all eight intimacy-handoff keys. |
| §1.11 binding defaults | All present and correct: `bMenuless 0 · bDlgDryRun 1 · iHideMode 1 · bHideCursor 1 · iEngineOpen 0 · iCritical 0 · iSceneGate 0 · iBranchInput 0 · bRewalk 0 (+iRewalkDepth 2) · fSilenceTimeout 45 · fDecideTimeout 4 · fLineSettle 0.4 · fOpenDistance 200 · iClickRoute 0 · iReadMode 0 · iCountMode 0 · iMaxEntries 16 · bIntentOpen 1 · bAllowNullVoice 0 · bActivateDefaultOnly 0 · bIaccToggle 0 · bProbe 0 · bQuestColour 0`, `[Keys] iKeyProbe`, `iKeyLeave`. §11 step 4's four shipping values are exactly these. |
| PROTOCOL §1.6 closed list | Every result string the driver and `LRG_OStim` emit is on it (18/18 checked verbatim, including `a conversation is in progress`, `that is not on the table right now`, `the player does not have that much gold`). |
| Live server state | `/var/www/html/HerikaServer/ext/lorerim_glue` holds the **0.4.0** plugin (all files mtime 2026-09-22 00:44, `lib/lrg_dialogue.php`, `lib/lrg_prompt_index.php`, `lib/lrg_speech.php` present, `data/.actions_v10` written 00:40), `lrg_prompt` holds 37,561 rows. The SERVER report's "No deploy was run" is wrong (see MINOR-6). |

---

## 1. Conformance findings, section by section

### §1.2 `LRG_DlgUI` signatures — CONFORMS
All 29 binding signatures exist with the design's exact shapes: `IsOpen · MenuState · EntryCount ·
SwfFamily · MaxItemsShown · Platform · EntryText(pos,mode) · EntryTopicIndex(pos,mode) ·
EntryIsNew(pos,mode) · EntryColour(row) · EntryRowItem(row) · Subtitle · SubtitlesOn ·
ProgressTimerId · GateArmed · SelectAndVerify(pos,ti,prefix,alsoScroll) · Click(route) · Hide(mode) ·
ReassertHide · HideCursor(bool) · Guard(bool) · GuardReset · GuardPoke · Park · ShowList · Unhide ·
CloseClean · CloseForce(step) · SmartTalkSafe`. The additions (`ClickResult`, `LastUnhide`,
`ForceListState`, `StateWriteBack`, `IsHidden`, the calibration accessors, the probe accessors) are
additive and read-only or void — the DLL seam of §8 is intact.

**Hard rule 9 (float display properties):** `Hide` reads `_x/_y/ExitButton._x` with `UI.GetFloat`
into Papyrus floats (`LRG_DlgUI.psc:478,486,487`), writes with `UI.SetFloat` (`:508,509`),
`ReassertHide` and `Unhide` likewise (`:527-531`, `:626-628`). `HolderXInt()` (`:857`) is the
deliberate wrong-way read for P6's proof and is labelled as such. **Conforms.**

**Hard rule 10 (validate + `fam` tag):** `Usable()` (`:76-81`) is `> -10000.0 && < 10000.0`, which
rejects NaN and both infinities by construction. `Hide` stores `hfam` (`:498`) and `Unhide` refuses
unless `hok == 1 && hfam == SwfFamily()` and all three values are usable (`:623-635`), reporting
`LastUnhide() == 2`. **Conforms.**

**Hard rule 11 (mode 5 read-only):** `SelectAndVerify` and `Click` take no mode and index array
positions only; `SetReadMode` refuses 5 (`:692`). **Conforms** — but see MAJOR-2 for mode **4**,
which is array-indexed *and* writes `iSelectedIndex`, and MINOR-9 for mode 5's `topicIndex`.

**§1.7 click order:** `SelectAndVerify` does step 1 (+2 on route A) and `Click` does
`SetInt eMenuState → GetInt read-back → GetString text prefix → GetInt topicIndex → one Invoke`
(`:418-447`). The identity pair really is the last frame-synced work before the single queued
`Invoke`, and the stamp is consumed (`SSetFloat("sat", -1.0)`, `:438`) before it, so one click per
verified selection by construction. An abort at the read-back or the identity pair returns **before**
any `Invoke` (`clk` −1/−2), and `Click` returns at `clk = -3` **before** it writes `eMenuState`, so a
stale press perturbs nothing. **Conforms, including D-A3.**

**§1.7 route table:** `ChooseRoute()` (`LRG_Dialogue.psc:2524`) = fam 1 → B, fam 2 → A, fam 0 → 0
→ ARMING sets `visible=unknown swf family` → MANUAL. The design's `_player_tts_traditional_dialogue`
column does not change any row, so dropping the read is correct.

### §1.3 state machine — CONFORMS on every state and transition I could test statically
All eleven states exist with the design's names and the transitions match, including: `OnMenuOpen`
starts one loop and a lost loop is taken over rather than ignored (`:1019-1030`); `fDecideTimeout`
**never** closes (`:1462-1469` → `EnterPendingOrManual`, whose only outcomes are PENDING and MANUAL,
`:1510-1524`); PENDING is refused for a scene speaker, a LETHAL session and a visible menu (`:1513`);
an engine push during a park unparks with `ShowList()` and re-reads (`:1526-1540`); RESPONDING has
all six signals and runs the settle poll before ever reporting `ok=0` (`:1699-1780`); CLOSING is
entered only on `do=leave` / the leave key and prefers a real back-out click (`:1877-1887`), releases
`Guard(false)` **while the menu is still open** (`:1892-1896`), and never force-closes; SUSPENDED
calls `ShowList()` on the way back (`:1827-1829`). `CloseForce` has **zero** driver call sites — it
is reached only from `LRG_DlgProbe` P11b. **Conforms.**

**One documented divergence, and it is the right one.** §1.3's ARMING row lists dry-run among the
"→ MANUAL" causes, while §1.10 says dry-run "runs everything, the menu stays VISIBLE, and the final
step logs `WOULD CLICK`". The build follows **§1.10**: dry-run skips `Guard`/`Hide` but stays in the
driving states, and `StepClicking` logs `WOULD CLICK cid= pos= i= kind= route= text=` and *then* goes
MANUAL (`:1631-1640`). §1.3's row is self-contradictory (it would make CLICKING's own dry-run branch
dead code), so this is a design bug, not a build bug. It is worth one line in PROTOCOL.

### §1.5 gates and voice keeper — CONFORMS
`OpenBlockedReason` (`:785-830`) has every row of the table with the exact reason strings, cheapest
natives first, `GetCrimeFaction()` None-guarded in `ClassifyCrit`, the scene gate counting into
`dSceneRefusals`, and `min(fOpenDistance, 0.85 * fAIInDialogueModeWithPlayerDistance)`. `DoOpen`
restores the voice **before** `Activate`, waits up to 3 s, refuses `her voice is not ready` unless
`bAllowNullVoice`, and re-Activates at most three times 1 s apart (`:966-996`). `VkRestore` is
re-run before every click (`:1605`). ARMING logs the `voice of <npc> at open = <EditorID>` self-test
line (`:1195-1200`). **Conforms.**

### §1.6 guard / readiness — CONFORMS
G0 `GuardReset()` is **the first statement of `Arm()`** (`LRG_Dialogue.psc:1063`), before
`ReadSettings`, before the classification, on every branch including dry-run, `bMenuless = 0`,
`forceVisible` and MANUAL — this is exactly §10 rejection 1's placement, and `Arm()` is reached from
`OnMenuOpen` for **every** Dialogue Menu open. G1b `GuardPoke()` is one native on every poll with a
read that counts `dPokesTrue`; the full `Guard(true)` re-arm and `ReassertHide()` are on every third
poll (`:1213-1226`). G3 `Park()` is called only in the click/read window and in PENDING, and is
undone by `Unpark()`/`ShowList()` (`:1350`, `:1429`, `:1516`, `:2011`). The line-settle gate is the
subtitle/timer-id test, not `isActorTalking`, which is demoted to a CHIM-TTS gate taking a **display
name** (`:1602`). Smart Talk is a machine-checked precondition: `smartSafe == false` sends every
session to MANUAL with `visible=smart talk skip` and one notification (`:1152-1154`, `:373-376`) —
stricter than "cannot leave dry-run", which is fine. **Conforms.**

### §1.8 never-do — CONFORMS (see §0 for the evidence)
Also verified: no `RegisterForSingleUpdate` and no `Utility.Wait(` in `LRG_Dialogue` (every wait is
`Utility.WaitMenuMode`), no IACC/Smart Talk setter anywhere (`bIaccToggle` only logs decision D2),
no CHIM notification used as session feedback.

### §1.9 hand-offs — CONFORMS
`Finish()` classifies `refused` (< 1.0 s, nothing executed) · `asked` · `handoff`
(`Utility.IsInMenuMode()` true and `mmBase` was false) · `goodbye` · `external`, counts
`dLayersLost` on a lost pending layer, and there is **no retry** on `refused` (`:1909-1957`).

### §1.11 MCM — CONFORMS (table in §0). Diagnostics counters all exist
(`dOpensNoMatch, dSceneRefusals, dLayersLost, dLayersAssisted, dCountFails, dPokesTrue, dAwards,
dDrifts, dClickAborts`) and are logged by `LogDiagnostics()`. `layers_rewalked` is absent, which the
driver report discloses (server-side counter).

### §1.12 / §11 step 0 probe — COVERS EVERY [U] except one that it now cannot measure
Presses 1–5 plus ids 10–31 cover P0, P0b, P1, P2, P3, P4, P5, P3t, P6, P6f, P6-H1, P7, P7s, P7p,
P8, P8v, P9, P10, P11 (close + re-open), P11b, P12, P13, P14a/c, P15, P16, P17 — and every press
emits at least one greppable `PROBE P<n> k=v` line, including its refusals, through `LRG_Main.LogC`.
Read-only is enforced in code: `ClickAllowed()` (`LRG_DlgProbe.psc:1046`) is the only gate on the
only three mutating helpers, and it refuses on `readOnly`, a guard, a scene actor and outstanding
crime gold. P8v runs before the real click and proves F7's defence. `StateWriteBack` is never given
a negative (hard rule 5 safe). **But see MAJOR-3: P11's central number is now unmeasurable.**

### Intimacy carry-along — CONFORMS except MAJOR-4
- **`pay=` gold** (`LRG_OStim.psc:1864-1890`, `:2083-2095`): affordability is gated **before** the
  start (`StartBlockedReason:2652-2658` → `the player does not have that much gold`); dry-run returns
  before `startPayGold` is ever set and logs `DRY RUN WOULD PAY` (`:1868-1878`); the transfer happens
  once, at the one line where the thread is certainly real, re-checks the purse, and zeroes
  `startPayGold` immediately (`:2094`), so it can never be taken twice. A scene already accepted by
  OStim is never cancelled over gold — it runs free, `paid=0`, and says so in the log. **Correct and
  the safe direction.**
- **Post-scene control** (`TickControls:946-988`, `TickListener:848-944`, `ReleaseListener:802`):
  every exit path goes through `ReleaseListener` — switches off, self-heal, a new scene, she is
  gone/unconscious, combat, player dead, distance, another cell, the player looked at somebody else,
  her goodbye finished, the clock. `ReleaseListener` clears `listenerUntil/Actor/Name` **before** the
  `listenerForced` test, so a stale actor cannot keep the tick alive. The controls repair is
  evidence-gated (camera state 3, or movement/look off) and logs every time it fires.
- **Closed-door privacy**: `LRG_Profile.psc:432-442` and `LRG_OStim.PrivacyBlockedReason:2593-2606`
  compute the same two door arguments, and the game-side re-check passes `abFresh = true`. All three
  MCM keys exist with defaults and are read with the right type.

### Behaviour with the OLD server (0.3.1) — DOES NOT CONFORM, see MAJOR-5.

---

## 2. Defects

### MAJOR-1 — the emergency hotkey is a no-op once the driver is in MANUAL  (driver)
`LRG_Dialogue.Step()`'s emergency branch is the single statement `GoManual("key")`
(`LRG_Dialogue.psc:1228-1231`), and `GoManual` returns immediately when the state is already MANUAL:

```papyrus
Function GoManual(string asWhy)
    if dlgState == ST_MANUAL
        return                     ; LRG_Dialogue.psc:1971-1973
    endif
```

So whenever the driver has already gone MANUAL **while the topic list is still off-screen**, the key
prints `"LoreRim Glue: the menu is yours"` (`:567`) and does nothing else. Two reachable ways in:

1. `DoUnhide()` ran and `LRG_DlgUI.LastUnhide()` returned **2** — no usable stored value, so
   `Unhide()` wrote nothing (`LRG_DlgUI.psc:630-635`). The `ok` test includes
   `SInt("hfam",-1) == SwfFamily()`, and `SwfFamily()` returns **0** whenever `HIDE_TOPICS` is empty
   and `iMaxItemsShown` reads 0 (mid-transition, or a rebuilt movie) — a single unlucky read leaves
   `TopicListHolder._x` at 5000, `isHidden = false`, state MANUAL. `DoUnhide`'s second attempt is
   `ShowList()`/`ForceListState()`, which set `_visible` and the state but **never move `_x` back**.
   The player now has a speaker name, a subtitle and no topics, and the escape key is dead.
2. A `LRG_DlgProbe` press (P6/P7/P7s/P7p) hid or guarded the menu while the driver sat in MANUAL —
   the driver's own `isHidden`/`guarded` are false, so nothing re-asserts and nothing repairs.

This breaks the round's hard rule "the emergency hotkey always gives a working visible vanilla menu"
and design §1.10. **Smallest fix** — repair before the early return, in `GoManual`:

```papyrus
if dlgState == ST_MANUAL
    if LRG_DlgUI.IsHidden() || parked
        DoUnhide(asWhy)
    endif
    return
endif
```

`LRG_DlgUI.IsHidden()` is the StorageUtil flag, so it sees the probe's hide as well as the driver's.

### MAJOR-2 — read mode 4 can be used on a menu the player can still click  (driver)
`readMode` is a script member reset only in `Maintenance()` (`:269`), and both readers skip
calibration once it holds 3 or 4:

```papyrus
; ReadList, LRG_Dialogue.psc:1335
if readMode != 3 && readMode != 4
    CalibrateRead(aiCount)
endif
```

`CalibrateRead` correctly refuses to *choose* mode 4 on a visible menu (`:1424-1427`), but nothing
stops a mode-4 calibration made in an earlier **hidden** session from being reused later on a
**visible** one. `StepManual` — the assisted path that `iEngineOpen = 0` makes the normal case —
then calls `ReadList(n)` (`:1859`) and `LRG_DlgUI.EntryText(0, readMode)` (`:1855`), and mode 4
writes the engine's click target:

```papyrus
; LRG_DlgUI.psc:256 / :273
UI.SetInt("Dialogue Menu", lp + ".iSelectedIndex", aiPos)
```

`ReadList`'s only mode-4 guard is on **parking** (`:1350`), not on the read. The engine pre-selects
an entry on every list (D-14) and `onItemSelect` clicks `iSelectedIndex`, so the player's own E-press
or mouse click would fire the glue's last-read entry — position `cap-1` — instead of the one they
see highlighted. That is a wrong effect from a right-looking screen, which is the one outcome §4.2
and the round's hard rules forbid. It is dormant only for as long as mode 3 works, which is exactly
what P3/P4 have not yet proven. It also contradicts the driver report's own stated deviation
("Read mode 4 is never used while the menu is visible").

**Smallest fix** — two lines. In `ReadList`, immediately after the `CalibrateRead` block, and in
`StepManual` before the `EntryText(0, readMode)` probe:

```papyrus
if readMode == 4 && !isHidden
    readMode = 3      ; never walk iSelectedIndex on a menu the player can still click
endif
```

(If mode 3 genuinely does not work, `ReadList`'s `got == 0` path then reports `read-failed` and the
session degrades to assisted — which is the correct degradation, per §1.7's mode-5 reasoning.)

### MAJOR-3 — P11 can no longer measure the one thing §11 calls load-bearing  (driver / ui)
§11 step 0, row 9: P11 is *"the single most important press in the set for R1: re-open by hand and
read `ALLOW_PROGRESS_DELAY` before anything writes it"*. It cannot do that any more, because the
driver ships **with** the probe this round and arms on every Dialogue Menu open:

```papyrus
Event OnMenuOpen(string asMenuName)      ; LRG_Dialogue.psc:1014
    if asMenuName != MENU_DLG || !attached
        return
    endif
    ...
    RunSession()                          ; -> Arm()

Function Arm()                            ; :1058
    LRG_DlgUI.GuardReset()                ; :1063 — writes 750 before anything else
```

There is no settings gate in front of it (correctly — R1 demands it on every branch). So by the time
the owner presses `iKeyProbe`, `LRG_DlgProbe.PressP11Reopen` (`:716`) reads a value the glue itself
has already normalised, and will log `allow_delay_on_reopen=750` whether or not `_global` survives a
close. A green P11 would be meaningless and the owner would take `GuardReset()` for defensive when
it may be load-bearing.

**Smallest fix** — measure it in `Arm()` itself, where it is free and happens on *every* open:

```papyrus
Function Arm()
    int apd0 = LRG_DlgUI.AllowProgressDelay()   ; the pristine value, before anything writes it
    LRG_DlgUI.GuardReset()
```

and append `+ " apd=" + apd0` to the existing `arming sid=…` `LogC` line (`:1188-1194`). A single
`apd=100000000` anywhere in the log then answers R1 definitively. The probe press can stay as it is.

### MAJOR-4 — design §7.2 item 3 was built by nobody: OStim commands are accepted during a live dialogue session  (intimacy)
§7.2 item 3 is explicit: *"`LRG_OStim.psc` — `StartBlockedReason` and the outside-scene clothing gate
answer `a conversation is in progress` while `dlg.IsSessionOpen()`."* The DRIVER lane handed the edit
off ("owned by the parallel INTIMACY lane"); the INTIMACY lane never took it. `PairBlockedReason`
(`LRG_OStim.psc:2565-2582`) and `StartBlockedReason` (`:2619-2665`) contain no dialogue test at all —
`grep IsSessionOpen LRG_OStim.psc` is empty — and `TickControls`' `Utility.IsInMenuMode()` cannot
substitute, because a Dialogue Menu is **not** menu mode (design D-13).

The rail exists in only one direction: `LRG_Dialogue.OpenBlockedReason` refuses to open a session
during an OStim scene (`:809-814`), but nothing refuses a scene during a session. At the shipped
defaults this is the *likely* path, not an exotic one: with `iEngineOpen = 0` every ordinary E-press
conversation makes `IsSessionOpen()` true, the player speaks to her on push-to-talk mid-conversation,
and the server answers `ExtCmdLRG_StartIntimacy`. The scene then starts with the Dialogue Menu open —
where 13+ installed scripts' hotkeys are asleep because they key on `UI.IsMenuOpen("Dialogue Menu")`
(design F14), OStim's own `OUtils.MenuOpen()` hotkeys included, so the player cannot control the
scene they are in. PROTOCOL §1.6 already carries the reason string, waiting for this edit.

**Smallest fix** — at the top of `StartBlockedReason` and of the outside-scene clothing gate:

```papyrus
LRG_Dialogue dlg = Main().GetDialogue()
if dlg && dlg.IsSessionOpen()
    return "a conversation is in progress"
endif
```

### MAJOR-5 — the game half is NOT additive against server 0.3.1, contrary to PROTOCOL.md  (both)
`PROTOCOL.md:4-8` states that a game script **400** works with server **0.3.1** because
*"its `lrg_topics` / `lrg_dlg` messages are not in `external_fast_commands`, so CHIM's core drops
them as an unknown type"*. Traced on the live install, the opposite happens:

1. A 0.3.1 `preprocessing.php` has no Phase 2 block, so `lrg_topics` / `lrg_dlg` fall into Phase 1's
   `strncmp($lrgType, 'lrg_', 4) === 0` block → `lrgHandleGameMessage()`, which matches no branch and
   ends at `return 'pass';` (`lib/lrg_actions.php:265`).
2. `main.php:243` — `if (!in_array($gameRequest[0],$fast_commands)) {` — the type is **not** in the
   list, so the request **takes the MAIN LLM semaphore** instead of being dropped.
3. `processor/request.php:174` logs `"Request cue is empty!"` and falls back to `TEMPLATE_DIALOG` —
   a full LLM call plus TTS, with the raw `ev=open;sid=d…;origin=engine;…` payload standing in for
   the player's words. (The Phase 1 team documented this fallback itself in `prompts.php:93`.)

And the game sends these unconditionally: `Arm()` calls `SendOpen()` on **every** Dialogue Menu open
regardless of `bMenuless` / `bDlgDryRun` (`LRG_Dialogue.psc:1186`), and `Step()` calls
`HarvestLine()` per poll whenever subtitles are on (`:1267-1269`), which sends one `ev=line` per
subtitle. A server rolled back to 0.3.1 with scripts 400 installed would therefore burn one LLM call
and one TTS per dialogue open and per line of every conversation in the game, and the NPC would read
wire payloads aloud. The owner pays for those tokens.

This is live-safe **today** — the 0.4.0 plugin is already deployed (§0) — but the round's contract
says both directions, and the rollback path is the one an owner reaches for when something breaks.

**Smallest fix** (two parts, both small):
- Correct `PROTOCOL.md` §0: make it a **shipping rule**, in the same shape as 0.3.1's w2 exception —
  *the two halves of 0.4 ship together and the server is deployed first*.
- Add `bDlgWire = 1` to `[Dialogue]` in `settings.ini` **and** a toggle in `config.json` (a missing
  key reads as 0, so the line is mandatory), read it in `ReadSettings()`, and return early from
  `SendTopics`, `SendOpen`, `SendClosed`, `SendUnhide`, `SendResult`, `SendFacts` and `HarvestLine`
  when it is false. A rollback is then one MCM toggle instead of a re-install.

### MINOR-6 — three build reports are contradicted by the tree  (docs)
The release step reads these reports, so they must match what is on disk:
- **INTIMACY** reports `compile.ps1` failing at `LRG_Dialogue.psc(651,13) IsDriving is not a function
  or does not exist`. It now prints **OK** for all nine scripts, 0 errors, 0 warnings.
- **SERVER** reports "No deploy was run, as instructed". `/var/www/html/HerikaServer/ext/lorerim_glue`
  holds the full 0.4.0 plugin — `manifest.json` version `0.4.0`, `lib/lrg_dialogue.php` (98 KB),
  `lib/lrg_prompt_index.php`, `lib/lrg_speech.php`, the Phase 2 `preprocessing.php` block and the
  `external_fast_commands` merge — every file mtime **2026-09-22 00:44**, and `data/.actions_v10`
  written 00:40, which means the new catalog code has also *run* against the live database.
- **SERVER** reports `lib/lrg_actions.php`'s two D-12 edits and `lib/lrg_core.php`'s constants as NOT
  made. Both are present: the pass-through at `lrg_actions.php:1436`, the `lrgRecordResult` guard at
  `:668`, `LRG_VERSION '0.4.0'` at `lrg_core.php:13`, `LRG_ACTIONS_VERSION 10` at `lrg_actions.php:25`
  with the `amount` slot at `:97`.
**Fix:** re-state the three "NOT DONE" lists against the tree before the release gate.

### MINOR-7 — `Finish()` can leave a hidden menu behind at the 900 s cap  (driver)
`RunSession`'s loop condition is `… && (now - openTime) < 900.0` (`:1052`). If it ever expires with
the menu still open, `Finish()` releases the cursor (`:1923-1925`) but never calls `DoUnhide()`, so
the list stays at `_x = 5000` with no loop left to repair it — and with MAJOR-1 unfixed, no working
emergency key either. Reachable only through a hidden state that outlives its own watchdogs (MANUAL
after a `LastUnhide() == 2`).
**Fix:** in `Finish()`, before the cursor release:
`if LRG_DlgUI.IsOpen() && (isHidden || guarded || parked || LRG_DlgUI.IsHidden())
    DoUnhide("watchdog")
endif`

### MINOR-8 — four probe presses end with the menu hidden and guarded and no way back  (ui)
`PressP6` (`LRG_DlgProbe.psc:441-449`), `PressP7` (`:473`), `PressP7s` (`:505`) and `PressP7p`
(`:529-530`) call `Hide`/`Guard(true)`/`HideCursor(true)` and never `Unhide()`; only `PressP16`
(`:1004`) does. Within that open the owner's only escape is TAB (which the design deliberately keeps
working), and the driver cannot help because its own `isHidden`/`guarded` are false. The state does
not survive the close — the movie is rebuilt on every open and `Arm()`'s `GuardReset()` repairs the
delay — so this is a one-open annoyance, not a leak.
**Fix:** end each of those four presses with `LRG_DlgUI.Unhide()` (P7p after its `ShowList()`), or add
press id 32 "give the menu back" to `RunStep`/`StepName`.

### MINOR-9 — `bDlgDryRun` does not gate the probe  (ui)
`DoClick` / `DoCloseClean` / `DoCloseForce` (`:1059-1081`) check only `readOnly` and the
guard/scene/crime matrix. A probe press clicks and closes for real while the module is in dry-run.
That is probably intended (P8/P9 exist to click), and it ships inert behind `bProbe = 0` +
`iKeyProbe = 0`, but nothing says so anywhere the owner will read it.
**Fix:** one sentence in the MCM help for `bProbe`: "probe presses 3, 4, 5 and P11b click and close
for real, whatever `bDlgDryRun` says".

### MINOR-10 — mode 5's `topicIndex` reads a member that does not exist  (ui)
`EntryTopicIndex(aiPos, 5)` returns `UI.GetInt(M, L + ".Entry" + aiPos + ".topicIndex")`
(`LRG_DlgUI.psc:276`). `UpdateList` writes `itemIndex` onto the laid-out clip — which is exactly why
`EntryRowItem()` exists — not `topicIndex`, so this read returns a false **0**, the trap hard rule 6
names. Only the dump and the probe can reach it (mode 5 never feeds a click), so nothing can be
mis-clicked; it just puts a wrong number in the parity log the owner will use to judge T2.
**Fix:** `return -1` for mode 5, or read `EntriesA.<EntryRowItem(aiPos)>.topicIndex`.

### MINOR-11 — `TickControls` can repair controls during a dialogue session  (intimacy)
`TickControls` treats `Utility.IsInMenuMode()` as "a menu legitimately owns the controls"
(`LRG_OStim.psc:970`), but a Dialogue Menu is not menu mode (D-13), so inside the 60 s post-scene
window it may call `Game.EnablePlayerControls()` / `ForceThirdPerson()` while a session is open. The
evidence gate (camera state 3, or movement/look off) makes it unlikely, and the direction is benign,
but it is the same blind spot as MAJOR-4.
**Fix:** add `|| (Main().GetDialogue() && Main().GetDialogue().IsSessionOpen())` to the `:970` test.

---

## 3. What is demonstrably safe, so the owner does not re-litigate it

- **Dry-run clicks nothing.** The only `LRG_DlgUI.Click` call in the driver is `:1662`, and
  `StepClicking`'s dry-run branch returns at `:1640` — before `SelectAndVerify`, before `Click`.
  `CmdSelectTopic`'s assisted branch logs `WOULD CLICK (assisted, nothing driven)` and refuses
  (`:675-684`); `CmdAward` logs `WOULD AWARD` and moves no gold (`:872-877`); `CmdStart`'s `pay=`
  returns before `startPayGold` is set. There is no other path to a click.
- **A wrong match cannot cause a wrong effect.** `SelectAndVerify` aborts on a text-prefix or
  `topicIndex` mismatch and does not stamp; `Click` refuses (`ClickResult() -3`) without a stamp
  younger than 2 s; the stamp is consumed before the `Invoke`; an aborted click is never retried
  through the other route (`:1664-1683`), it goes back to READING once and then gives up with
  `that moment has passed`.
- **`GuardReset()` placement** is exactly §10 rejection 1's: top of `Arm()`, every branch, nowhere
  else; `Guard(false)` is in CLOSING while the menu is open and inside `Unhide()`; no `UI.Set*` is
  attempted from `Maintenance()` or from a close handler.
- **`x` de-duplication** is belt and braces: `LRG_Main.HandleCommand`'s 4-slot / 5 s `cmdKey` ring
  plus `LRG_Dialogue`'s 8-slot `x` ring, and `XPush(reqX)` runs **before** the click (`:1660`), so a
  second delivery of one decision can never reach the menu whatever the click does.
- **Quest-tree work is cheap.** `GetActiveAssociatedQuests` runs only in `SendFacts` (throttled 60 s
  per NPC through an 8-slot ring, only while `dlgState == ST_IDLE`, only for a real CHIM agent) and
  once per ARMING; never per poll and never per frame.
- **`Maintenance()` ordering is right.** `LRG_Main.Maintenance()` calls
  `UnregisterForAllModEvents()` → `RegisterKeys()` (which does `UnregisterForAllKeys()`) → `ost.` →
  `dlg.` → `prb.Maintenance()`, so the probe's key and both modules' menu/mod-event registrations
  survive every load. `GetDialogue()` returning None logs the design's exact sentence and the module
  stays off. The `LRG_Main.Maintenance() -> GetProbe().Maintenance()` call lane A flagged as missing
  **has landed** (`LRG_Main.psc:136-139`).
- **D-13 is narrowed correctly.** `UI.IsTextInputEnabled()` still returns early **unconditionally and
  first** (`LRG_Main.psc:249`); only the `Utility.IsInMenuMode()` half is bypassed, only for
  `keyVanillaMenu`/`keyLeave`, only while `dlg.IsSessionOpen()` (`:252-260`). The visually identical
  statement in `MaybeInitiative` (`:751`) is untouched — `grep IsInMenuMode` shows exactly the two
  statements the design demands.

---

## 4. Pass/fail against the round's hard rules

| Hard rule | Verdict |
|---|---|
| never a TIF fragment / `SetStage` / `SetObjectiveCompleted` / `CompleteQuest` / a faked effect | **PASS** (sources and `.pex` string tables) |
| CHIM's "AI Quest Progression" stays off | **PASS** — nothing in the glue writes a CHIM setting; `SelfTest` only *reads* `get_conf_i` |
| the emergency hotkey always gives a working visible vanilla menu | **FAIL** — MAJOR-1 (and MINOR-7) |
| a wrong match can never cause a wrong effect | **PASS for the driver's own clicks**; **FAIL for the player's** — MAJOR-2 |
| ships in dry-run, nothing is clicked | **PASS** |
| `bMenuless = 0`, `bDlgDryRun = 1`, `iEngineOpen = 0`, `iSceneGate = 0` (§11 step 4) | **PASS** |
| additive in both directions | **FAIL** — MAJOR-5 |

Fix MAJOR-1 through MAJOR-5 (they are 2, 2, 2, 4 and ~10 lines respectively, plus one PROTOCOL
paragraph) and this ships. The probe evening should not start before MAJOR-3 is in, or its most
important number will be a false green.
