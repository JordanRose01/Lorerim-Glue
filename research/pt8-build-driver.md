# pt8 — LANE B build report: GAME-DRIVER + INTEGRATOR (menuless questing, v0.4.0)

Builder: lane B. Date 2026-09-21. Status: **BUILT, compiles, ships inert.**
Plan followed: `glue/V04_BUILD_PLAN.md` §0, §1, §2, §4, §6 (lane-B half), §7, §8.5, §9, §13.
Design read in full: `glue/PHASE2_DESIGN.md` §0.0, §0.2, §1.1–§1.12, §2.3–§2.7, §3, §6, §7.1–§7.4, §11.
Nothing in this lane was run in game. **Nothing clicks anything on the shipped defaults.**

---

## 0. Files this lane touched

| File | What |
|---|---|
| `glue/game/LoreRimGlue/Source/Scripts/LRG_Dialogue.psc` | **NEW**, ~3,000 lines — the design §1.3 state machine, `CmdSelectTopic`, `CmdAward`, the emergency/leave/dump keys, the voice keeper, the Speech-global drift check, the wire senders |
| `glue/game/LoreRimGlue/Source/Scripts/LRG_Main.psc` | six anchored edits (§4.4) + `keyLeave` |
| `glue/tools/make_esp.py` | the one anchored edit at `:44`; `LoreRimGlue.esp` + `Seq/` regenerated |
| `glue/game/LoreRimGlue/MCM/Config/LoreRimGlue/config.json` | new page **Menuless questing** (45 rows) + 2 rows on the existing Intimacy page + a help update on the Keys page |
| `glue/game/LoreRimGlue/MCM/Config/LoreRimGlue/settings.ini` | new `[Dialogue]` (26), `[Checks]` (4), `[Quests]` (2), `[Keys]` +2, `[Intimacy]` +2 — **every key has its default line, zeroes included** |
| `glue/tools/deploy_server.ps1` | the anchor pre-flight + the prompt-index build/load step (both new) |

**Not touched** (other lanes own them this round): `LRG_DlgUI.psc`, `LRG_DlgProbe.psc` (lane A —
both had already landed and compile, so **no temporary stub was ever created or committed**),
`LRG_OStim.psc`, `LRG_Profile.psc`, every `server/` file, `PROTOCOL.md`, `compile.ps1`,
`esp_dump.py`, `tools/stubs/`, `LRG_MCM.psc`.

---

## 1. Lane gate — every check, with its output

```
powershell -File glue\tools\compile.ps1
  rounds: 2 ; auto-stubs created: 23   (… scene, topic, voicetype …)
  compiled: LRG.pex, LRG_Dialogue.pex, LRG_DlgProbe.pex, LRG_DlgUI.pex, LRG_Main.pex,
            LRG_MCM.pex, LRG_OStim.pex, LRG_PlayerAlias.pex, LRG_Profile.pex
  OK - .pex files copied …; compiler reported 0 errors, 0 warnings

py glue\tools\make_esp.py glue\game\LoreRimGlue   -> wrote LoreRimGlue.esp (683 bytes) and Seq/…
py glue\tools\esp_dump.py  …\LoreRimGlue.esp
  QUST 01000800 VMAD scripts=['LRG_Main', 'LRG_OStim', 'LRG_Dialogue', 'LRG_DlgProbe']   (FOUR)
  HEDR version=1.70 records=3 nextObjectId=00000802      (unchanged, no new form, no new master)

findstr /C:"LRG_DlgProbe" glue\tools\make_esp.py | count   -> 1
grep -n IsInMenuMode LRG_Main.psc
  244:  … Only the Utility.IsInMenuMode() half is bypassed, only for      <- block comment
  252:  if Utility.IsInMenuMode()                                          <- EDITED (OnKeyDown)
  751:  if Utility.IsInMenuMode() || UI.IsTextInputEnabled() || UI.IsMenuOpen("Dialogue Menu")
                                                                           <- MaybeInitiative, UNTOUCHED
  => exactly two statements, one edited and one not (the third hit is prose)

real UI.* calls in LRG_Dialogue.psc  -> 0   (5 hits are the words "the UI." / "UI.Set*" in comments)
real UI.* calls in LRG_DlgProbe.psc  -> 0   (lane A's gate, re-verified here)
SetStage / SetObjectiveCompleted / CompleteQuest / SetBribed / SetIntimidated / SetCrimeGold /
SkipText / onCancelPress / RegisterForSingleUpdate / Utility.Wait( in LRG_Dialogue.psc -> 0 CALLS
  (the only hits are the never-do list in the header comment and one comment at CLOSING)
docstrings over 300 chars -> 0 ; longest string literal -> 81 chars (compile.ps1's 500 gate passed)

deploy_server.ps1 anchor pre-flight (run in isolation against glue\server\lorerim_glue):
  "anchor pre-flight: 8 anchors, one match each"                    exit 0
  negative test (one bogus + one over-matching anchor):
  ANCHOR PRE-FLIGHT FAILED - nothing was deployed:
    globals.php: anchor matches 0 times (want 1) - negative test: THIS ANCHOR DOES NOT EXIST
    globals.php: anchor matches 13 times (want 1) - multi-match test: lrg      exit 1

MCM cross-check (script: every page id vs every settings.ini key):
  JSON OK, pages: 7 — General 7, Intimacy 25, Initiative 3, Scene talk 16, Survival 3, Keys 5,
  Menuless questing 45 rows
  duplicate ids: []   malformed ids: []
  ids with NO settings.ini default: []      ini keys not on any page: []
```

**T2 (dump parity on 10 NPCs) and T3 (dry-run WOULD CLICK) are in-game and belong to the owner**
(plan §13 step 5). Everything they need is built: the dump key is real and also writes the
diagnostics, and `WOULD CLICK cid=… pos=… i=… kind=… route=… text=…` is one greppable line.

### 1.1 Anchor line numbers had ALREADY drifted again — anchors, never numbers

Measured this round, in the files as they are now (the plan's own "fresh" hints in brackets):

| file | anchor | now | plan's hint |
|---|---|---|---|
| `lib/lrg_actions.php` | D-12 pass-through anchor | **:1427** | :1300 |
| `lib/lrg_actions.php` | the turn/SHARMAT drop | **:1431** | :1304 |
| `lib/lrg_actions.php` | `lrgRecordResult` guard | **:664** | :608 |
| `lib/lrg_actions.php` | `gate: dropped unknown glue action` | **:1569** | :1437 |
| `globals.php` | the fast-command merge | **:10** | :8 |
| `preprocessing.php` | the Phase 1 `lrg_` block | **:34** | :18 |
| `LRG_Main.psc` | `CurrentVersion` | :15 | :15 |

`lrg_actions.php` is now **2,743 lines** (the plan measured 2,454) because the intimacy lane is
editing it in parallel. The pre-flight is therefore not defensive, it is load-bearing: a
line-numbered patch applied to that file today edits the wrong statement.
**Note for lane C:** the design's `if (!$turn || … defined('LRG_SHARMAT_PRESENT')) {` matches once,
but the short form `defined('LRG_SHARMAT_PRESENT')) {` matches **6 times** in that file — anchor on
the full statement.

---

## 2. What was built, against the rules that may not be softened

`LRG_Dialogue extends Quest`. Public surface exactly as design §7.1, plus `CmdAward` (§6.9) which
`CmdSelectTopic` routes `do=award` into, so `LRG_Main` gained no second branch:
`Maintenance` · `CmdSelectTopic` · `CmdAward` · `HandleEmergencyKey` · `HandleLeaveKey` ·
`DumpTopics` · `IsSessionOpen` · `SessionSpeaker` (+ `IsDriving`, `DiagValue`, `LogDiagnostics`).

| rule (plan §4.1) | how it is met |
|---|---|
| **1** `GuardReset()` is ARMING step 0 on **every** branch | `Arm()`'s first statement, before `ReadSettings()`, before the family reads, on dry-run, `bMenuless=0`, `forceVisible`, LETHAL and scene branches alike. **Plus the hole I found and closed:** `OnMenuOpen` used to `return` while `loopRunning` was true, so a loop whose stack had been dumped would have made every later open skip ARMING — and therefore skip `GuardReset()`. It now takes the open over when `loopBeat` is more than 5 s stale, logs it, and arms |
| **2** classify BEFORE hiding; LETHAL never guarded | `crit = ClassifyCrit()` and `inScene = GetCurrentScene() != None` run before the hide block; a LETHAL session with `iCritical = 0` becomes MANUAL and `Guard(true)` is never reached. `Guard`, `Hide`, `HideCursor`, `Park`, `ReassertHide`, `GuardPoke` are **all** gated on `guarded`/`isHidden`, so nothing in MANUAL or dry-run ever touches the guard |
| **3** LETHAL = `IsGuard()` **AND** (engine-opened **OR** crime gold > 0) | `ClassifyCrit()`, with `GetCrimeFaction()` None-guarded before `GetCrimeGold()`. The game only ever reports `crit` 0 or 2; COSTLY is the server's grade and the game never auto-closes, times out or force-closes **any** session |
| **4** a scene speaker never enters PENDING, never triggers `lrg_dlgtalk` | `vis = "scene"` → MANUAL at ARMING; `EnterPendingOrManual()` requires `!inScene`; `Finish()`'s `talkNeeded` requires `!inScene` |
| **5** `fDecideTimeout` never closes a session | `StepDeciding` → `EnterPendingOrManual()`; its only outcomes are PENDING and MANUAL |
| **6** PENDING parks only in the click window, unparks the instant the list moves | `Park()` is called in exactly two places, both inside "layer read → click decided": `EnterPendingOrManual` and the mode-4 read. `StepPending` compares `EntryCount()` and `EntryText(0)` every 0.5 s and calls `ShowList()` the moment either moves, then re-reads and only re-parks after the new layer is read |
| RESPONDING: six signals, `ok=0` never over a delta, no un-hide before the settle poll, never a second click, `CloseForce` never from the driver | all six are implemented (`EntryCount()==0`, `ProgressTimerId()` changed, another menu, `isActorTalking` 0→1, a new subtitle, the close via loop exit → `Finish`). `ok = verified \|\| rsSignal \|\| moved`; the settle poll runs **before** any `GoManual`; one `Click()` per decision; `CloseForce` appears nowhere in the driver |
| the freeze rule (B1) | gold + Speechcraft are captured with every new layer signature and re-read in CLICKING; a change sends the driver back to READING (at most twice, then it proceeds rather than looping) |
| `GuardPoke()` every poll, full re-arm + `ReassertHide()` every third | `Step()`, gated on `guarded \|\| isHidden`. It also reads `AllowProgress()` first, which is what finally makes `guard_pokes_that_found_true` a real number |

**`CmdSelectTopic` contains no `Utility.Wait*` of any kind** (grep: 0) and returns inside one frame:
it validates, stamps a request the loop consumes, and hands the `Activate` to a private SKSE mod
event. **Exactly one `funcret` per `x`**: a second delivery of the same `x` is dropped silently
before anything runs, so `commandEndedForActor` fires once per `x` in total.

### 2.1 The one thing lane A gave me that changed a correctness decision

`LRG_DlgUI.Click()` is void in the frozen §1.2 signature, but lane A added **`ClickResult()`**
(1/2 = invoked, −1 read-back failed, −2 identity mismatch, −3 no fresh verified selection, −4
closed). The driver now **checks it after every click**: a negative result means *nothing was
clicked*, so it recovers with `ShowList()`, counts a `click_abort`, and treats it as `stale` — one
re-read, then `Error: that moment has passed`. Without that check the driver would have reported
"the matter is raised" for a click that never happened, and `ev=result ok=1` would have been a lie.
Also adopted: `SmartTalkOffender()` (names the offending ini setting in the notification and the
self-test), `ForceListState()` (the design's "manual `eMenuState = 1`" second attempt, which §1.2
names but defines no primitive for), `LastUnhide() == 2` → `ev=unhide;why=no-stored-x`,
`AllowProgress()` (the poke counter), `OtherMenuOpen(name)` (so `other=` carries *which* menu),
`ReadMode`/`SetReadMode`/`CountMode`/`SetCountMode`/`ResetCalibration` (the MCM can now force a
mode, and a new game session starts uncalibrated).

---

## 3. Wire v0.4 — what the game side now actually sends (for the `PROTOCOL.md` merge)

Every send goes through `LRG_Main.SendNpcMessage` → `logMessageForActor`. `requestMessageForActor`
is used **only** for `lrg_dlgtalk`, **only** with no session open, never for a scene speaker.

* **`lrg_topics`** — key order exactly as plan §2.1: `v,sid,gen,layer,origin,ref,npc,st,n,sent,part,
  cid,want,ask,crit,scene,fam,sub,[pg,bamt,]vt,q,sp,perk,lvl,e`. `n` is always the full
  `EntryCount()`; `e` is capped by **both** `iMaxEntries` and **1,600 raw chars**, and the remainder
  goes out as a `part=2` message of texts only (`<pos>~-~-~-~<text>`). `pg`/`bamt` appear only when
  a text carries a gold word. `origin` ∈ `glue|engine|dump`. Sent when a signature changed, after a
  click, on a dump, and on `want`.
* **`lrg_dlg`** — `ev=open|line|result|closed|unhide|facts` with the keys of plan §2.1.
  `ev=facts` carries `ref,npc,q,vt,dlg,sp,perk,lvl,wis,qobj` (throttled 60 s per NPC in an 8-slot
  ring, never while a session is open, skipped for a non-CHIM actor).
* **`lrg_dlgtalk`** — emitted from `Finish()` when a **glue-opened** session closed with no click,
  no scene, `bMenuless` on and `why != refused`.
* **`ExtCmdLRG_SelectTopic`** — `do=pick|open|leave|show|noop|award`. Drops: `ok!=1`, a repeated
  `x` (silently), a `ref` that is not this actor, an older `gen` on the live `sid` (one `want=1`
  re-match + `that moment has passed`), age > 20 s, a session with another speaker, and a session
  the glue is not driving.
* New `funcret` reasons used: `they cannot talk right now` · `her voice is not ready` · `that is not
  on the table right now` · `that moment has passed` · `not enough gold` · `dry-run mode, nothing
  was clicked` · `a conversation is in progress` · `combat` · `too far apart` · `a quest scene is
  running` · `a scene is already running` · `the actor could not be found` · `not authorised by the
  server gate` · `the feature is switched off`. Success strings: `The matter is raised.` · `The
  conversation is left.` · `The menu is shown.` · `Noted.`

**Three small shapes the merge must write down exactly as built:**
1. `ref` and `vt` are sent as **eight upper-case hex digits, no `0x`** (`Hex8`, built on
   `Math.LogicalAnd`/`RightShift` so a FormID above 0x7FFFFFFF cannot come out signed).
   **Incoming** `ref` is parsed tolerantly: `0x`-prefixed hex, bare hex **or decimal**, because
   Phase 1's `lrg_npcstate` sends `ref` in **decimal** (`LRG_Profile.psc:331`). An **empty** `ref`
   is refused (a missing key fails closed).
2. `ev=result` sends `dur` as a **float in seconds** (e.g. `dur=3.240000`), and `other=` carries a
   short word — `barter|training|gift|container|messagebox|crafting|book|journal|custom|menu`.
3. `e=` texts have `|`→`/`, `~`→`-`, `"`→`'` and newlines→space replaced, then clip 140. The design
   says "only `|` and `~`", but PROTOCOL §0's own ground rule forbids `"` in any value and the
   payload travels as a base64 GET; the ground rule wins. Nothing else about the text is touched,
   so dump parity is unaffected.

---

## 4. Deviations from the plan, and why (a review should check these first)

| # | Deviation | Why |
|---|---|---|
| **D1** | **`LRG_OStim.psc` was NOT edited** (plan §4.5 / §8.4: the `a conversation is in progress` refusals and `pay=`) | The round's own ownership rules give `LRG_OStim.psc` to the parallel INTIMACY lane with an explicit "do NOT edit". Editing it would have clobbered a builder working in the same file in the same minute. **§6 below is the hand-off**: both edits, verbatim, with anchors that match once today |
| **D2** | **`PROTOCOL.md` was not rewritten to v0.4** | The plan puts the merge in the integrator step *after all three lanes report*, and this lane's file list does not contain it. §3 above is the complete game-side delta to merge |
| **D3** | **Probe presses are an MCM setting, not MCM buttons** | I checked all 58 `CallFunction` actions in 14 installed MCM configs: **every one targets a function on the mod's own MCM config script** (`Load`, `Default`, `LoadDefault`); none passes a `form`/`script`. Lane A's press API is `LRG_DlgProbe.RunStep(int)`, and `LRG_MCM.psc` is in **no** lane's file list this round. So the page carries `iProbePress` (0 = the key cycles phases 1–5, any other value = lane A's `RunStep` id verbatim, 0–31 slider) and `iKeyProbe`. **Two one-line wiring options for the release step are in §6** |
| **D4** | Two rows were added to the existing **Intimacy** page and the dump key's help text on the **Keys** page was updated | §9.4 puts `bPaidIntimacy`/`fPriceMultiplier` on the Intimacy page and §4.7 makes the dump key real. I read "do not touch other pages" as "do not restructure existing controls": no existing control was moved, retyped or renamed |
| **D5** | `iKeyLeave` / `iKeyProbe` appear **only** on the new page, and the Keys page got a pointer line instead | Their ini section is `[Keys]` either way (§9.3). Showing the same control id on two pages would be a duplicate MCM id, and a config MCM Helper refuses to parse takes the *whole* menu down, including the pages that work today |
| **D6** | **Read mode 4 is never used while the menu is visible** | Mode 4 walks `iSelectedIndex`. On a menu the player can still click (MANUAL, assisted, dry-run, dump) moving the selection means the player's own E-press could click an entry the glue chose. Visible + mode 3 empty ⇒ the list is not read, `why=read-failed` once, harvesting continues. In a hidden session mode 4 behaves exactly as designed, parked first |
| **D7** | `ev=unhide` is sent only when something was really handed back (or on `do=show`), not for every MANUAL | A LETHAL/scene/assisted session never hid anything; `ev=open` already carries `crit`, `scene` and `origin`, so a second message would be noise the server has to de-duplicate |
| **D8** | CLOSING is entered **only** on an explicit `do=leave` or the leave key — not on "a finished root list" (design §1.3) | "Finished" is not observable from Papyrus without guessing, and the engine's own Goodbye closes those sessions anyway. Refusing to guess keeps COSTLY sessions safe by construction |
| **D9** | `do=award` is gated by `bFreeChecks` + kill switch + dry-run, **not** by `bMenuless` | It touches no menu. Tying the free-conversation effect to the menuless switch would make speech checks impossible for an owner who wants the checks without menuless questing, and §6.9's "the module" is the checks module |
| **D10** | The session loop lives in `OnMenuOpen`; a private SKSE mod event (`LRG_DlgPump`) provides the one extra stack (open + voice sweep); `OnUpdate` is handled **opportunistically** | `RegisterForSingleUpdate` is per form and belongs to `LRG_Main`, and `RequestTick` clamps to 0.2 s while the design wants a 0.1 s poll. A mod event costs nothing when idle and cannot clobber Phase 1's timer. If `OnUpdate` never reaches this script, only the 30 s voice sweep is lost — the keeper still records from the crosshair, from ARMING and from `ev=facts` |
| **D11** | `layers_rewalked` and `checks_run` / `checks_passed` / `paid_scenes` are **not** counted game-side; `awards` and `click_aborts` are counted instead | The re-walk is a server-side replay of `path[]`, the free checks are decided server-side, and `pay=` is the intimacy lane's. The MCM help says where those numbers live |
| **D12** | With `iCritical = 1` a LETHAL session is hidden **and** guarded | The design's "LETHAL skips `Guard(true)` entirely" exists so the player can click "I submit"; with `iCritical = 1` the menu is not handed back at all, so an unguarded hidden menu would be strictly worse. It ships `0`, so this path is dormant, and ARMING logs `crit=2` either way |

---

## 5. Open `[U]` items this lane depends on, and what answers each

Nothing here is a blocker for the build; each is a calibration the owner's probe settles. The
driver refuses to guess in every one of these cases (MANUAL with the menu visible) rather than
clicking on trust.

| what the driver needs | answered by | what it does until then |
|---|---|---|
| does any count mode work, and `iPlatform` | **P2** | `EntryCount() == -1` for 3 s ⇒ MANUAL `why=read-failed`, `count_mode_failures` moves |
| which read mode returns every text | **P3 / P4** (P5 is read-only) | calibrates mode 3, then mode 4 only when hidden; mode 0 ⇒ MANUAL |
| `iMaxEntries` / tail cap from measured cost | **P3t** | 16 / 40, both MCM-settable |
| does `iAllowProgressTimerID` move per line or per session | **P1** | the line-settle gate uses the subtitle when subtitles are on and the timer id when they are not; if the timer id turns out to be per session, subtitles alone still gate it |
| hide mode, the `GetInt` `(UInt32)` trap, family 2 | **P6 / P6f** | `iHideMode = 1`; `Hide()` returning false ⇒ nothing is hidden, session assisted |
| is the guard sufficient; can `GuardPoke` hold Smart Talk | **P7 / P7s** | `SmartTalkSafe()` is a **hard precondition**: false ⇒ the module stays visible, one notification names the setting |
| is an engine push during a park dropped or deferred | **P7p** | the park is held only inside the click window and released on any change in count or signature |
| route table, and RESPONDING signals 3 and 4 | **P8 / P9** | route from the SWF family, `0 ⇒ MANUAL + a warning`, one click per decision, never the other route |
| the state read-back gate | **P10** | lane A's `Click()` does the read-back and reports −1; the driver treats it as "nothing clicked" |
| does `_global` survive a close (is `GuardReset()` load-bearing) | **P11** | it is called unconditionally at every ARMING either way |
| which close primitives are safe | **P11b** (probe only) | the driver never force-closes; escalation ends at MANUAL |
| payload length and `part=2` | **P13** | 1,600-char cap + entry cap, both enforced before the send |
| which delivery route works, and how fast | **P14** | the executed ring of 8 de-duplicates whichever arrives first; a P14a failure only costs latency |
| the SUSPENDED logic around `BarterMenu` | **P15** | entered on `Utility.IsInMenuMode()` against an ARMING baseline (`mmBase`), so a P0 surprise cannot make every session suspend; on return `ShowList()` then re-read |
| the engine's idle auto-close | **P16** | closing is normal: `ev=closed why=external`, `layers_lost` moves, never an error |
| is the quest colour readable at all | **P17** | `bQuestColour = 0`; `col` is `-` in every entry until it is on |
| is the Dialogue Menu menu mode here | **P0** | the D-13 edit bypasses only the `IsInMenuMode` half, and only for the two dialogue keys while a session is open; `UI.IsTextInputEnabled()` still returns early **unconditionally** |
| `Activate` vs `Activate(player, true)` | **P0b** | `bActivateDefaultOnly = 0`, 3 tries 1 s apart, then `they cannot talk right now` |
| **X1** (does a session survive the player speaking) | owner, first | `iBranchInput 0`, `bRewalk 0`; a lost layer is `ev=closed pending=1` and the server decides |
| does `wis` match the engine's in-dialogue verdict | **T6 / E18** | `wis` is reported as a fact, never used by the game to decide anything |
| does `AdvanceSkill` outside dialogue pay Requiem's rate | **T6b** | `CmdAward` replicates `FavorDialogueScript` exactly and reads `SpeechSkillMult` **live** (never 75); `mult = 0` ⇒ no XP and a log line |
| `qobj` cost on a follower with six quests | **T2** | `ev=facts` logs its own build time in ms beside the dump |

---

## 6. `forIntegrator` — the four things the release step must do

**(a) `LRG_OStim.psc`, two anchored edits (they belong to the intimacy lane's file, so they are
written out rather than applied):**

1. Anchor `string Function StartBlockedReason(string asNpcName, string asParam)` (hint `:2256`) and
   the outside-scene clothing gate in `CmdClothing` (hint `:2676`). After the existing kill-switch
   and adult tests and **before** the distance and privacy scans, insert:
   ```papyrus
   LRG_Dialogue dlg = Main().GetDialogue()
   if dlg && dlg.IsSessionOpen()
   	return "a conversation is in progress"
   endif
   ```
   (`IsSessionOpen()` is already safe to call from anywhere: it returns false as soon as the loop's
   stack has been stale for 10 s, so it can never block intimacy permanently.)
2. §8.4's `pay=`: parse in `CmdStart` before the gates, clamp `0..100000`; charge in
   `BeginStartThread` **immediately before `OThreadBuilder.Start`** with
   `player.RemoveItem(gold, n, true, npc)` (gold = `Game.GetFormFromFile(0x0000000F, "Skyrim.esm")`,
   cached); `player.GetItemCount(gold) < n` ⇒ refuse the whole start with `Error: not enough gold`;
   dry-run logs `DRY RUN WOULD PAY <n> to <npc>` and charges nothing; `pay=0`/absent = today.

**(b) Probe buttons — pick one, both are one line:**
* *preferred:* in `LRG_DlgProbe.OnKeyDown`, before the phase cycle:
  `int sel = m.SettingInt("iProbePress:Dialogue", 0)` and `if sel > 0 : RunStep(sel) : return`.
  The MCM slider then IS the button list, and the ini default (0) keeps today's cycling behaviour.
* *or:* add `Function P(int aiId)` to `LRG_MCM.psc` calling `LRG.GetMain().GetProbe().RunStep(aiId)`
  and give each press a `"type": "text"` row with
  `"action": {"type": "CallFunction", "function": "P", "params": [<id>]}`.

**(c) `PROTOCOL.md` → v0.4** from §3 above plus lanes A and C's halves. Additive only; both halves
interoperate: a server 0.4.0 against script 310 never receives `lrg_topics` (no session state
exists) and a script 400 against server 0.3.1 gets its messages dropped as an unknown type, finds
no `lrg_dlgtalk` cue (⇒ `TEMPLATE_DIALOG`, an ordinary reply) and therefore never leaves
assisted/dry-run. Neither half requires the other.

**(d) Server-side MCM knobs have no wire carrier this round.** `bFreeChecks`, `iCheckBias`,
`bCheckHostility`, `iQuestLines`, `bRewalk`, `iRewalkDepth`, `iBranchInput` and `bIntentOpen` are
consumed (wholly or partly) by the **server**, and §2 admits no key for them — I did not invent
one. The game honours the ones it can (`bFreeChecks` and `bCheckStats` gate `do=award`,
`bQuestAware` gates `q`/`qobj`, the rest are logged in the self-test line at every load). Their
server-side twins live in lane C's `config/lrg_dialogue.default.json`; until a v0.5 key exists,
**keeping the two in step is an owner/integrator task**, and the MCM help says so for the ones a
player would otherwise expect to matter.

**(e) Small notes.** `LRG_Main.Maintenance()` now logs
`LRG_Dialogue not attached - use a new game or a save made before the glue was installed` and stays
off rather than crashing (attachment on an existing save is `[I]`). `GetProbe()` is None-guarded
the same way. My edits make the project require lane A's two files to exist — which `make_esp.py`
now also assumes, by design.

---

## 7. The install question (plan §11) — nothing in this lane needs one

**No step of this lane required a DLL, an SKSE plugin build, an AS2/SWF compiler or any other
install, and none was attempted.** Every capability came from sources already on disk, re-verified
this round: `Math.LeftShift/RightShift/LogicalAnd` (`Math.psc:43-45`) for the hex formatter,
`PO3_SKSEFunctions.GetActiveAssociatedQuests` (`:812`) / `GetAllQuestObjectives` (`:1007`) /
`GetFormEditorID` (`:486`) / `IntToString` (`:1094`, present but unused — `Hex8` keeps the format
under our control), `Quest.GetID` (`:250`) / `GetCurrentStageID` (`:60`) /
`IsObjectiveDisplayed|Completed|Failed` (`:82/:79/:85`), `Actor.WillIntimidateSucceed` (`:704`) /
`GetBribeAmount` (`:175`) / `IsBribed` (`:352`) / `IsIntimidated` (`:397`) / `GetGoldAmount` (`:252`)
/ `GetLeveledActorBase` (`:272`), `ActorBase.GetVoiceType/SetVoiceType` (`:104-105`),
`Faction.GetCrimeGold` (`:7`), `Game.AdvanceSkill` (`:10`) / `QueryStat` (`:189`) / `IncrementStat`
(`:140`) / `GetDialogueTarget` (`:446`), `FormList.GetSize/GetAt` (`:11/:14`),
`ModEvent.Create/Send` (`:32/:36`), `ObjectReference.RemoveItem` (`:505`). `Scene` and `VoiceType`
were auto-stubbed by `compile.ps1`'s unknown-type loop exactly as the plan predicted.
Python 3.12.10 **is** reachable on Windows through the `py` launcher, so `make_esp.py` and
`esp_dump.py` ran natively — no WSL staging was needed for the ESP.

**What a DLL would still buy**, unchanged from plan §11 and all of it behind lane A's frozen
signatures, so the driver would not notice: FormID identity per entry (today: text → index), a
one-call list read and a "list changed" event (today: N frame-synced gets and a 0.1 s poll), exact
INFO tracking (today: subtitle + stat/gold deltas + six signals), first-frame hiding (today: a 1–2
frame flash of the exit button), no-session awareness (today: harvest only), and the **session
shield** — the one thing only a DLL can give, and only a live question if X1 comes back "dies".
Per D1 the question does not go to the owner until probe **P3** has actually failed.

---

## 8. Shipped defaults — what happens on the owner's next load

`bMenuless 0` · `bDlgDryRun 1` · `iEngineOpen 0` · `iSceneGate 0` · `iCritical 0` · `bRewalk 0` ·
`iHideMode 1` · `bHideCursor 1` · `iReadMode 0` · `iCountMode 0` · `iMaxEntries 16` · `iTailMax 40` ·
`fSilenceTimeout 45` · `fDecideTimeout 4` · `fLineSettle 0.4` · `fOpenDistance 200` ·
`bIntentOpen 1` · `bAllowNullVoice 0` · `bActivateDefaultOnly 0` · `bIaccToggle 0` ·
`bQuestColour 0` · `bProbe 0` · `iProbePress 0` · `bFreeChecks 1` · `iCheckBias 0` ·
`bCheckStats 0` · `bCheckHostility 0` · `bQuestAware 1` · `iQuestLines 3` · `iKeyLeave 0` ·
`iKeyProbe 0` · `bPaidIntimacy 1` · `fPriceMultiplier 1.0`.

Behaviour with those values: **every conversation is the normal visible menu.** The driver arms
(which only ever *repairs* the guard), classifies, sends `ev=open`, reads the list the engine built,
forwards new subtitles as `ev=line`, sends `lrg_topics origin=engine;want=0`, and sends `ev=closed`
with the Speech-global drift. It never hides, never guards, never parks, never clicks, never closes.
Every `ExtCmdLRG_SelectTopic` is refused with `the feature is switched off`. `ev=facts` flows on
crosshair changes so quest awareness works from day one. The order to turn things on is plan §13
step 5–6: bind the emergency key → `bMenuless 1` (still dry-run, read the `WOULD CLICK` lines) →
`bDlgDryRun 0` only after `SmartTalkSafe()` is true and **P11** has been read → then one knob at a
time, each judged on its counter.

---

# pt8 — GAME-SIDE FIX PASS (appended 2026-09-22)

Lane: game-side fixer. Files allowed: `glue/game/LoreRimGlue/**` and `glue/tools/make_esp.py` only.
The server half of the "both" defects is the parallel server fixer's; `PROTOCOL.md` is outside this
lane and its two needed edits are written out below as a handover.

Status: **DONE.** Every defect in this lane is fixed; `compile.ps1` prints OK and `esp_dump.py`
validates an unchanged `.esp`.

## Defect ledger

| # | sev | defect | lane | state |
|---|---|---|---|---|
| D1 | major | emergency hotkey is a no-op once in MANUAL | game | **FIXED** + root cause in `LRG_DlgUI.Unhide()` |
| D2 | major | read mode 4 writes iSelectedIndex on a visible menu | game | **FIXED** (driver + 2 more sites in the probe) |
| D3 | major | probe P11 can no longer measure ALLOW_PROGRESS_DELAY | game | **FIXED** (`apd=` on every arming line) |
| D4 | major | OStim start / clothing gates have no `dlg.IsSessionOpen()` test | game | **FIXED** (3 call sites) |
| D5 | major | game half is not additive against server 0.3.1 | both | **game half FIXED** (`bDlgWire`); PROTOCOL text handed over |
| D6 | minor | `Finish()` can leave a hidden menu behind at the 900 s cap | game | **FIXED** |
| D7 | minor | four probe presses end hidden / guarded | game | **FIXED** + new press 32 |
| D8 | minor | `bDlgDryRun` does not gate the probe (documentation) | game | **FIXED** (MCM help + ini comment) |
| D9 | minor | `EntryTopicIndex` mode 5 reads a member that does not exist | game | **FIXED** |
| D10 | minor | `TickControls` can repair controls during a live session | game | **FIXED** |
| D11 | major | seven MCM controls change nothing; `bFreeChecks` half-wired | both | **game half FIXED** (wire carriers); server must read them |
| D12 | minor | PROTOCOL 10.4 `res=` is read by nobody | server/doc | not this lane - confirmed from the game side |
| D13 | major | emergency key does not give back a PROBE-hidden menu | game | **FIXED** (same predicate as D1) |
| D14 | major | ordinary money talk read as paying for sex | server | not this lane |
| D15 | minor | `iHideMode` 0-based MCM enum vs 1-based code | game | **FIXED** (+1 mapping, ini ships 0) |

## Result: 12 of 13 game-lane defects fixed, all gates green

```
powershell -File glue\tools\compile.ps1
  rounds: 2 ; auto-stubs created: 23
  compiled: LRG.pex, LRG_Dialogue.pex, LRG_DlgProbe.pex, LRG_DlgUI.pex, LRG_Main.pex,
            LRG_MCM.pex, LRG_OStim.pex, LRG_PlayerAlias.pex, LRG_Profile.pex
  OK - .pex files copied ...; compiler reported 0 errors, 0 warnings

py glue\tools\esp_dump.py glue\game\LoreRimGlue\LoreRimGlue.esp
  QUST 01000800 VMAD scripts=['LRG_Main','LRG_OStim','LRG_Dialogue','LRG_DlgProbe']
  HEDR version=1.70 records=3 nextObjectId=00000802     (UNCHANGED - make_esp.py was not edited,
  no new script, no new form, no new master; the .esp is still 683 bytes)

docstrings over 300 chars / literals over 400 chars: 0
MCM cross-check: JSON OK - page controls 83, ini defaults 83, duplicate ids [], malformed ids [],
  page ids with NO ini default [], ini lines with NO page control [],
  ini lines NO script reads []            <- was [bCheckHostility, iCheckBias, iQuestLines]
  (the one "read with no ini default" hit, fAIInDialogueModeWithPlayerDistance, is
   Game.GetGameSettingFloat at LRG_Dialogue.psc:840 - a Skyrim game setting, not an MCM one)

never-do list in LRG_Dialogue.psc: SetStage / SetObjectiveCompleted / CompleteQuest / SetBribed /
  SetIntimidated / SetCrimeGold / SkipText / onCancelPress -> 0 calls (unchanged)
real UI.* calls in LRG_Dialogue.psc and LRG_DlgProbe.psc -> 0 (design 1.2 holds; every new UI
  primitive went into LRG_DlgUI)
```

Nothing was installed and nothing was run in game. `install_mo2.ps1` was not called.
No C++ / SKSE / SWF tooling was needed for any of these fixes, so there is no DLL-vs-fallback
choice to record this round.

---

### D1 + D13 - the emergency key always gives a working visible menu

Three edits, one new predicate, one root-cause fix.

1. **`LRG_Dialogue.NeedsUnhide()`** (new, just above `GoManual`) answers "is a list still being held
   away from the player?" from four independent sources: the driver's own `isHidden / guarded /
   parked`, `LRG_DlgUI.IsHidden()` (the StorageUtil flag, which is how it sees a hide the **probe**
   made), and finally `LRG_DlgUI.HolderX() > 4000.0` - the `_x` itself, the one thing that cannot
   lie. That last test is what makes it see a **failed** `Unhide()`: `Unhide()` clears `hid`
   unconditionally (`LRG_DlgUI.psc:640`), so after a refused restore the flag says "not hidden"
   while the list sits at 5000. A fix that only tested `IsHidden()` would still have missed it.
2. **`GoManual`** no longer returns blind when already `ST_MANUAL`; it repairs first
   (`if NeedsUnhide() / DoUnhide(asWhy) / endif`). This is the path `Step()`'s emergency branch
   takes.
3. **`HandleEmergencyKey`**, `!IsSessionOpen()` branch: after the existing `HardReset()`, if the
   menu is open and `NeedsUnhide()`, it notifies, calls `DoUnhide("key-idle")` and **returns true**
   - returning false would have made `LRG_Main.OpenVanillaDialogue()` fire `npc.Activate()` on top
   of an already-open menu. Its docstring was corrected to match.
4. **Root cause, `LRG_DlgUI.Unhide()`**: the restore gate was `SInt("hfam",-1) == SwfFamily()`.
   `SwfFamily()` returns **0 = unknown** whenever `HIDE_TOPICS` is empty *and* `iMaxItemsShown`
   reads 0, so an unreadable family refused the restore and stranded the list. 0 now means "no
   objection"; only a **different KNOWN** family vetoes it. The validated-float guard (hard rules
   9/10) is untouched. `LastUnhide()` gained code **4** = "restored although the family could not
   be read"; `DoUnhide` logs that case, and every caller still only tests `== 2`.
5. `DoUnhide` now ends with an honest check: if the holder is *still* off-screen after both
   attempts it logs `WARNING unhide could not bring the topic list back on screen` and shows
   `press TAB to close this menu`.

### D2 - read mode 4 can no longer touch a visible menu

`readMode == 4 && !isHidden -> readMode = 3` in **`ReadList`** (right after the `CalibrateRead`
block, before the park test) and in **`StepManual`** (before the `EntryText(0, readMode)` probe).
`ReadList`'s existing `got == 0` path then degrades the session to assisted, which is the correct
degradation. This also covers the **shipped default**: in dry-run `Arm()` skips the hide block, so
`isHidden` is false while the whole machine runs - mode 4 would have been writing `iSelectedIndex`
on the very menu the owner is being asked to click by hand.

Two more mode-4 writes on a clickable menu were found in the probe while checking this, and closed
the same way: `LRG_DlgProbe.PressP13` (`ReadModeAuto()`) and `LRG_DlgProbe.TopicsPayload` (:1237).
The deliberate mode-3-vs-4 timing comparison in `PressP3t` was left alone - measuring mode 4 is
that press's entire purpose.

### D3 - P11's number is now free and unconditional

`Arm()` reads `int apd0 = LRG_DlgUI.AllowProgressDelay()` as its **first** statement, before
`GuardReset()`, and the existing `arming sid=...` LogC line ends with `+ " apd=" + apd0`. One
native, every open, no settings gate. A single `apd=100000000` in the log answers R1; `apd=750`
everywhere means the poisoned value never survives a close. `PressP11Reopen` was left exactly as it
is, so the owner still has the hand-opened pristine reading as a cross-check.

### D4 - OStim can no longer start behind a live Dialogue Menu

New `LRG_OStim.DialogueBusy(LRG_Main)` (None-safe: a load order without the module reads false).
Called from:
* **`StartBlockedReason`**, directly after `IsIntimacyEnabled()` and **before** the `ok=1` gate - so
  `ExtCmdLRG_StartIntimacy` is refused with the exact reason string PROTOCOL 1.6 already carries:
  `a conversation is in progress`.
* **`CmdClothing`**, the outside-scene `undress` gate, ahead of `PairBlockedReason` /
  `PrivacyBlockedReason`.
* **`TickControls`** (this is D10): merged into the `player.GetCurrentScene() != None ||
  Utility.IsInMenuMode()` test, because a Dialogue Menu is not menu mode (D-13) and the post-scene
  repair would otherwise call `Game.EnablePlayerControls()` / `ForceThirdPerson()` during a live
  hidden conversation.

### D5 - `bDlgWire`, the one-toggle rollback (game half)

New member `sWire`, read as `bDlgWire:Dialogue` (default true) in `ReadSettings`, logged on the
self-test line as `wire=`. **Every** game -> server Phase 2 emitter returns early when it is false:
`SendTopics`, `SendOpen`, `SendClosed`, `SendUnhide`, `SendResult`, `SendFacts`, `HarvestLine`, and
`Finish()`'s `talkNeeded` (the `lrg_dlgtalk` request, which is an LLM turn by design). The two probe
presses that send `lrg_topics` for real, **P13** and **P14**, refuse and log
`note=bDlgWire is off, nothing was sent`.
`settings.ini` carries `bDlgWire = 1` with its comment (a missing key reads as 0 = silent, so the
line is mandatory) and `config.json` carries the toggle on the Menuless questing page.

**Handover - `PROTOCOL.md` section 0 is outside this lane and is still wrong.** Its claim
(`PROTOCOL.md:4-8`) that "CHIM's core drops them as an unknown type" does not hold: a 0.3.1
`preprocessing.php` routes every `lrg_` type into `lrgHandleGameMessage()`, which returns `pass`,
after which `main.php:243` takes the MAIN semaphore and `processor/request.php:174` falls back to
`TEMPLATE_DIALOG`. Suggested replacement, in the shape 0.3.1 already uses for w2:

> **One exception, and it is a SHIPPING rule, not a wire rule:** `lrg_topics` and `lrg_dlg` are
> added to `external_fast_commands` by the 0.4.0 server. Against a server still on **0.3.1** they
> are not fast commands, so each one takes the MAIN semaphore and is answered as an ordinary
> conversation turn - one LLM call plus TTS per dialogue open and per subtitle line, with the raw
> payload read aloud as the player's words. **The two halves of 0.4 therefore ship together,
> server first**; a game script 400 in front of an older server must have `bDlgWire = 0`
> (Menuless questing -> "Send quest talk to the server"), which stops every one of these messages
> at the source. Everything else in the round remains additive in both directions.

### D6 - `Finish()` cannot leave a hidden menu behind

Before the cursor release: `if LRG_DlgUI.IsOpen() && NeedsUnhide() / DoUnhide("watchdog") / endif`,
with the old `isHidden || guarded || parked` captured into `hadHide` first so the cursor release
still happens. This is the 900 s absolute-cap path (`RunSession`'s `go` test), the one way the loop
ends with the menu still open.

### D7 - no probe press ends with the menu held

New `LRG_DlgProbe.GiveMenuBack(string)`: `Unhide()`, settle 0.2 s, `ShowList()` if entries exist and
the state is not 1; a no-op on a closed menu. Called at the end of **P6**, **P7**, **P7s** and
**P7p** (after its own `ShowList()`), and exposed as **press id 32, "Give the menu back (undo a
press that hid it)"**.
One deliberate exception: `PressP6` gained a fourth argument `abGiveBack`, passed **false** only
inside composite step 2, because `PressP7` measures input against a list at `_x = 5000` (where a
mouse click cannot reach it) and then gives the menu back for both. Steps 15 and 16 pass true.
Un-hiding P6 before P7 would have turned P7's "does a click get through the guard" test into a real
topic activation.

### D8 - the probe's dry-run blind spot is now written where the owner reads it

The `bProbe` help on the Menuless questing page now ends with: *"the probe presses 3, 4, 5 and 'P11b
CloseForce' click a real topic and close a real conversation for real, whatever the dry run toggle
above says - dry run does not gate the probe."* The same sentence is in `settings.ini` next to
`iProbePress`, whose press-id list now also names 32. No code gate was added: P8/P9/P11b exist in
order to click, and they already ship inert behind `bProbe = 0` + `iKeyProbe = 0`.

### D9 - `EntryTopicIndex` mode 5

Mode 5 read `Entry<row>.topicIndex`, a member `UpdateList` never writes, so it returned a false 0
into the T2 parity log. It now goes through the row's real array position:
`EntryRowItem(aiPos)` -> `EntriesA.<item>.topicIndex`, and returns **-1** when the row cannot be
resolved.

### D10 - see D4 (`TickControls`).

### D11 - the seven dead MCM controls now have wire carriers (game half)

They travel the way `paidok` / `pm` already do - additively, an absent key meaning "keep the
server's own default", so neither half requires the other.

| MCM control | key | carrier |
|---|---|---|
| `bFreeChecks:Checks` | `chk=` digit 1 | `lrg_npcstate` snapshot (`LRG_Profile.Snapshot`) |
| `bCheckHostility:Checks` | `chk=` digit 2 | same |
| `iCheckBias:Checks` | `bias=` (clamped -2..2) | same |
| `iQuestLines:Quests` | `ql=` (clamped 1..3, a missing 0 -> 3) | same |
| `iBranchInput:Dialogue` | `bi=` | `ev=open` **and** `lrg_topics` (`LRG_Dialogue.DlgCfgWire()`) |
| `bRewalk:Dialogue` | `rw=` | same |
| `iRewalkDepth:Dialogue` | `rwd=` | same |
| `bIntentOpen:Dialogue` | `io=` | same |

Example snapshot fragment: `...;paidok=1;pm=1.00;chk=10;bias=0;ql=3;...`
Example `ev=open` / `lrg_topics` fragment: `...;lvl=12;bi=0;rw=0;rwd=2;io=1`

`chk` is deliberately two digits in one key rather than two keys, to keep `lrg_topics` inside its
byte budget (`DlgCfgWire()` adds 20 characters and the `e=` cap is measured after it).

**Server side, still to do (not this lane):** `lrgDlgCheck()` must prefer `chk` digit 1 over
`checks.enabled` and digit 2 over its hostility config, and `bias` must reach the difficulty choice;
`lrgDlgQuestBlock()` must prefer `ql` over its config default; the dialogue driver's server half
must prefer `bi` / `rw` / `rwd` / `io`. **Until that lands the seven controls still change nothing**
- the keys are on the wire, nobody reads them yet. The prompt's fallback ("remove the seven dead
controls") was deliberately NOT taken: `iCheckBias` and the free-checks master toggle were asked for
by name in OWNER_ADDENDA item 5c, so removing them would have deleted a feature the owner ordered.
`bFreeChecks` keeps its game-side effect as well (`LRG_Dialogue.psc:848` refuses `do=award`), so
with the server half in place the switch is honoured at both ends.

### D15 - `iHideMode` 0-based enum vs 1-based code

`ReadSettings` now maps: `sHideMode = m.SettingInt("iHideMode:Dialogue", 0) + 1`, with the existing
1..2 clamp kept. `settings.ini` ships `iHideMode = 0` (with a comment saying 0 = topics only,
1 = everything), so a **missing** key still means topics-only, and the MCM's second option
"Everything (test)" is reachable for the first time. `config.json`'s help says so.
`LRG_DlgProbe` is unaffected: `PressP6` is called with a literal 1 or 2, never with the setting.

### D12 and D14 - not this lane

* **D12** (`res=` read by nobody): `PROTOCOL.md` and `lrg_actions.php` are the server fixer's.
  Confirmed from this side: the Papyrus `ParamGet` keys in `LRG_Dialogue.psc` are
  `ask, cid, cost, do, gen, i, kind, ok, pos, ref, sid, stat, take, txt, vt, x, xp` - no `res`.
  Both of the offered options are safe for the game half (an unknown key is ignored either way).
* **D14** (ordinary money talk read as paying for sex) is `lib/lrg_intent.php` - server lane,
  untouched here.

### Files this fix pass touched

| File | What |
|---|---|
| `glue/game/LoreRimGlue/Source/Scripts/LRG_Dialogue.psc` | D1, D2, D3, D5, D6, D11, D13, D15 |
| `glue/game/LoreRimGlue/Source/Scripts/LRG_DlgUI.psc` | D1 (family test + `LastUnhide` 4), D9 |
| `glue/game/LoreRimGlue/Source/Scripts/LRG_DlgProbe.psc` | D2 (probe half), D5 (P13/P14), D7 |
| `glue/game/LoreRimGlue/Source/Scripts/LRG_OStim.psc` | D4, D10 |
| `glue/game/LoreRimGlue/Source/Scripts/LRG_Profile.psc` | D11 (snapshot keys) |
| `glue/game/LoreRimGlue/MCM/Config/LoreRimGlue/settings.ini` | `bDlgWire = 1`, `iHideMode 1 -> 0`, probe comments |
| `glue/game/LoreRimGlue/MCM/Config/LoreRimGlue/config.json` | `bDlgWire` toggle, `iHideMode` + `bProbe` help |
| `glue/game/LoreRimGlue/Scripts/*.pex` | rebuilt by `compile.ps1` |

`make_esp.py`, `LoreRimGlue.esp`, `Seq/`, `LRG_Main.psc`, `LRG.psc`, `LRG_MCM.psc`,
`LRG_PlayerAlias.psc` and every `server/` file were **not** touched.

### Notes for the verifier

* The `.esp` is unchanged and `make_esp.py` was not edited, so the T2/T3 in-game steps are exactly
  as the first build report describes them.
* New log lines to grep for: `apd=` on every `arming` line (D3), `unhide: the SWF family could not
  be read` (D1), `WARNING unhide could not bring the topic list back on screen` (D1), `wire=` on the
  self-test line (D5).
* Shipped defaults are unchanged apart from the two ini lines: `bDlgWire = 1` (new) and
  `iHideMode = 1 -> 0` (the same behaviour, now expressed in the enum's own numbering).
* `bMenuless = 0`, `bDlgDryRun = 1`, `bProbe = 0`, `iKeyProbe = 0`, `iKeyLeave = 0` are all
  untouched: the round still ships inert and nothing clicks anything.
