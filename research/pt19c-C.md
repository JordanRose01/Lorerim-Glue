# pt19c Lane C - the game driver (implementer notes)

Spec: `research/pt19-menuless-v1-spec.md` rev 2, section 2.2 Lane C, S1.1-S1.2, S2.1 (game), S3.2, S3.4, S4.6 (game), S7 (game), S8, section 4 (GAME).
Interaction model: `research/pt19c-interaction-model.md` F1-F5, F9-F13, F17, F22, F24-F26, F28, F30, F31 (the resolutions Lane C owns).
Backups: `glue/.backup/pt19c-C/<name>.psc.bak`. Full diff: `C:\Users\Jordan\AppData\Local\Temp\lrg_test\pt19c-C\pt19c-C.diff`.
Nothing compiled, deployed or installed (compile.ps1 is the Build stage's).

## 1. What changed, where

| file | lines | what |
|---|---|---|
| `LRG_Dialogue.psc` | 4565 -> 4207 | the visible, voice-driven driver (below) |
| `LRG_Main.psc` | 4852 -> 4864 | script 513; emergency key gone; talk key; speaker-aware speech stamps; S7 SayReason; rail note once per session |
| `LRG_Profile.psc` | 954 -> 944 | qi/qig carriers removed (ids retired by Lane D fix 1); bDlgDryRun in-code default false |

### LRG_Dialogue.psc
Removed (section 4): `HandleEmergencyKey`, `HardReset`, `MaybeResume`, `SendResume`, `DoUnhide`, `NeedsUnhide`, `SendUnhide`, `GoManual`, `Unpark`,
`HandBack`/`HandBackKind` (-> `StopDriving`/`StopReason`), `EnterPendingOrManual`/`StepPending`/`ST_PENDING` (-> `EnterHeld`), `ActiveCalibSafe`,
the `calib=1` branch and `calSess/openCalib/reqCalibPick/reqCalibLeave/calPos/calPickTxt`, every `CalActive*`/`CalAuto*`/`CalPending`/`SetCalAbort`/
`CalAbortWanted`/`CalSetInt("auto")` call, the guard upkeep in `Step`, the Guard/Hide/HideCursor block in `Arm`, `isHidden/guarded/parked/hideFailed`,
the `assisted`/`hide refused` reasons, the `kind=rewalk` counters, `Finish()`'s `talkNeeded` + `lrg_dlgtalk` + `MSG_DLGTALK`, `ReadCalibration`
(d)/(e)/(e0) (no forcing, no MCM write), every reader of `bDlgDryRunHold iKeyVanillaMenu iHideMode bHideCursor iEngineOpen iCritical
iBranchInput bRewalk iRewalkDepth fSilenceTimeout bIaccToggle bQuestColour bCalibPassive bAutoTimings bCalibOverride bCalibAuto iCalibRuns
bHandBackNote bResumeAfterChoice bCrimeManual` (and the driver's unused copy of bQuestHint), the `bi/rw/rwd` wire keys, `runs=` on
ev=calib, and the dead `DiagValue`, `SceneIsAmbient`, `SceneQuestOf`, `AMBIENT_SCENES` (no callers anywhere). `HasActiveJournalQuest` stays
(spec section 4), unused by the driver.

New / changed:
- **Arm (S1.1)**: GuardReset + classify only. `sj` = `GetCurrentScene()` AND `EscortHasJournal(owning quest)` via `SceneBasis()` (it
  reuses the last facts line's answer for the SAME Scene of the same NPC when < 30 s old: 0 natives; never under quiet). Ladder: module
  off, smart talk skip, asked for (the dump, bounded to 5 s), lethal (`ClassifyCrit` = IsGuard && crime gold > 0, model F31), scene
  (quiet mode, model F17; or sj without `bDriveSceneMenus && CalRouteLive()`), unknown swf family. drv=0 -> MANUAL (lethal through
  `StopDriving("lethal")` and its corner note). `CalArm` on every arming (F22). Arming line: `scene= sq= sqj= sj= drv=` (+ `quiet=1`).
  A stamped `do=open` is reported `OK: Noted.` there, its ask=/cid= kept for the first lrg_topics (F4); log `select: do=open reported
  OK at arming`. A wrong speaker now arms as the engine's session (was MANUAL + ev=unhide).
- **ev=open** (S8): `;scene=;sj=;sq=;sqj=;drv=;hid=0[;quiet=1]` after svck=. scene= is added to ev=open because Lane A's sj index
  fallback (`scene && lrgDlgQuestIndexed(sq)`) reads `scene` from ev=open.
- **lrg_topics** (F1, F9): `;sj=;drv=` on every list, `;hc=1` on a layer his click produced, want forced to 0 when not driven, the
  open's ask= on the first want=1 list.
- **HELD (S1.1, F10)**: after the decide window `EnterHeld()` = LISTENING with `held`; polls EntryCount + the harvested subtitle only;
  a stamped pick resolves against the held arrays; a changed count or a new line -> READING (`layerStart` = his click). Decide timeout
  (F26): an outstanding pick is closed silently (`pick overtaken` when `reqGen < layerGen`, or when its entry is on the list after all);
  "not on the table" only when its entry is not on the live list. `layerGen` = the gen of the last REAL layer change (forced re-reads
  of the same layer do not move it); `TryResolvePick` refuses a pick older than it.
- **StopDriving(why, tell)** (S1.1, F2): closes the x in flight (`StopReason`: read-failed -> `the list could not be read`, combat,
  scene -> `a quest scene is running`, else `that moment has passed`), MANUAL, log `stopped driving why=`, the corner note for lethal
  only, and `ev=stopped;sid;ref;npc;why;layer;n` when tell. Whys sent: lethal, combat, scene, read-failed, unverified, close-failed.
- **do=show** (F3): `kind=back` -> OK and no state change; any other -> OK, then `StopDriving("lethal")`.
- **CmdSelectTopic**: F5 twin drop (`x == reqX && !reqReported`, silent). Undriven live session: a pick on the list -> `Error: choose
  that one on the list yourself`, not on it -> `Error: that is not on the table right now`, show -> OK, noop/open -> `OK: Noted.`. A
  session still OPENING is not refused (the pick is stamped and resolved once the list is read). An overwritten request is closed
  SILENTLY (F26); a stale gen at stamp time -> silent + the fresh list once. `adv=` / `rearm=1` -> `reqAdv` / `reqRearm`. A pick for a
  session that has died whose re-open is refused -> `Error: the conversation was interrupted` (S7).
- **OpenBlockedReason**: `QuietOn()` -> "a quest scene is running" (F17); the scene test is SceneBasis (iSceneGate 1: refuse only a
  journal-objective scene; 0: any scene), never the PO3 alias sweep.
- **StepClicking (S3.4, S4.6, S7)**: `sDryRun || !CalGreenNow()` -> `WOULD CLICK`, `Error: <DlgDryReason>`, HELD (no hand-back). Stale x2
  -> `Error: the entry moved before the click`; ClickResult <= 0 x2 -> `Error: the click did not take`; two aborts flip the route
  (`FlipRoute`: never a route forced by iClickRoute, never one proven live; writes `CalSet("route", r, "flip", 1)`). F30: no click
  within 1.0 s of a CHIM event of hers. Click line: `clicked pos= origin= sj= i= route= result= kind= auto= [adv anchor: ...] text=`.
- **Auto-advance** (`AdvReady`, S4.6 / F11-F13 / F25): bAutoAdvance off, subtitles off (engine anchor only), 30 s without an anchor, or
  `speechAt > layerStart` (`> reqAt` for rearm) -> silent close + HELD (`adv off` / `adv expired` / `adv cancelled by speech`). Engine
  anchor: `lineSeenAt > layerStart` and blank since >= the grace (a CHIM event of hers restarts it; isActorTalking must be 0) -> `adv
  anchor: line seen at= blank since=`. CHIM anchor (rearm=1): `adv wait: her line`; her voice began (herVoiceAt > reqAt, or she is
  talking now, or 8 s), then isActorTalking 0 for 1.0 s + the grace (+1 native per poll only while it waits). `ev=result auto=1`.
- **HarvestLine(afNow)**: keeps `lineSeenAt` / `blankSince` (blank = length < 2) and `lnSeq`; 0 new natives.
- **StepResponding / RouteProof (S3.2)**: the first signalled click of a session -> `CalSet("route", route, "live", 1)`; no signal 9 s after a
  click on route A -> FlipRoute. `ok=0` -> `StopDriving("unverified")` (model row 28).
- **NoteSpeech(who, at, actor)**: 0 -> speechAt; 1 and 2 only for the session speaker (CHIM brief P7) -> npcSpeechAt, 2 also herVoiceAt
  (F12). The probe's X1 measurement still hears every event.
- **Poll (S1.2, F28)**: 0.1 s CLICKING/RESPONDING, 0.5 s MANUAL/SUSPENDED, 0.25 s otherwise. Combat is looked for once a second in
  DECIDING and HELD (it used to cost 3 natives on every DECIDING poll), so the other polls keep to 3 natives.
- **CmdAward give=** (S6.2, gate B, inert until the server sends give=): `min(give, her septims)` moved NPC -> player, `OK: gave <n> septims`.
- ReadSettings: new `bAutoAdvance:Dialogue` (true) and `bDriveSceneMenus:Dialogue` (true); `iSceneGate` default 1, `iTailMax` 16 (clamp
  40, the array size), `bDlgDryRun` default false. ReadCalibration keeps (a)-(c) (the timings always on: bAutoTimings is retired).
  Mode 4 is never used (it walks iSelectedIndex on a visible menu): reads are mode 3.
- DlgDryReason (S3.4): `the menuless dry run is on (Menuless questing page)` | `still learning the dialogue menu - the next conversation
  of any kind measures it` | (probe `CalLearnable()` false) `still learning the dialogue menu - another conversation will not finish it: <CalStuckWhy>`.
- DoOpen logs `opened sid= origin=glue` and calls `NoteFirstBusiness`: ev=lat first=1 now marks the reply of a glue-opened turn (the
  "again" turn it used to mark is gone).

### LRG_Main.psc
`CurrentVersion = 513` plus a header line. `keyVanillaMenu`, its registration, its OnKeyDown branch and `OpenVanillaDialogue` removed.
`keyTalk = SettingInt("iKeyPushToTalk:Dialogue", 29)` registered like the leave key (<= 0 = off); OnKeyDown, before the menu-mode
return -> `NoteSpeechToDriver(0, now, None)`. `NoteSpeechToDriver(who, at, actor)`: SpeechStarted(NPC) -> kind 2, SpeechStopped and
TextReceived -> 1, the player -> 0, all with the actor. SayReason gains: `choose that one on the list yourself`; `the entry moved before
the click` -> "I lost the thread - say that again"; `the click did not take` -> "that did not take - choose it on the menu"; `the list
could not be read` -> "I did not catch what we could talk about - choose it on the menu yourself"; `the conversation was interrupted`
-> "we were interrupted - ask me again". Corner note `kind=rail`: shown once per sid, not gated by bQuestHint. The quiet log line and
comment no longer promise "no automatic calibration" (it is gone). The spec's "calib corner-note branch of HandleCommand" does not
exist in script 512 - nothing to remove. `CmdQuestEntry` / `Qe*` untouched.

### LRG_Profile.psc
The `qi=` / `qig=` writers (bQuestInitiative, iQuestInitiativeGap - retired by Lane D fix 1 on spec section 4 / S9) removed; the
pre-boot `ml=` read uses `bDlgDryRun` default false (= the ini). The spec says "nothing in gate A" for this file; both changes are
forced by the retired ids (tools/test_mcm_wiring.php section 10) and the in-code-default rule.

## 2. Tests (WSL copy `C:\Users\Jordan\AppData\Local\Temp\lrg_test\pt19c-C\glue`)
- `php tools/test_mcm_wiring.php` (strict): **48 passed, 1 failed** (was 40 / 31). The one FAIL is Lane B's `lrg_actions.php:1300-1301`
  "of 10"; every Lane C check is green (27 retired ids gone everywhere, qi/qig not written, bAutoAdvance / iKeyPushToTalk /
  bDriveSceneMenus read with default = ini, no driver "of 10" string, no bDlgDryRun MCM write).
- `php tools/flows/run_flows.php --quiet`: 75/85 - the SAME 10 failing scenarios with the original three .psc files swapped back in
  (d23 d29 d30b d31 d33 d33s d44 d53 d56 d57: server lanes; d53 is the strict wiring gate above).
- `php tools/test_gates.php`: fatals in 36b on `lrgDlgCalibCandidate()` (removed by Lane A; the test is Lane B's) before and after this
  lane, so section 35's .psc parse cannot run yet.
- Papyrus lint (block balance, unknown local calls, undeclared assignment targets, docstrings <= 500, every `p.` / `m.` / `LRG_DlgUI.` /
  `LRG_Profile.` / `Main().` call resolves in its script, every caller into LRG_Dialogue resolves): identical to the baseline on all three.

## 3. What the reviewers must check
1. **compile.ps1** on the driver scripts (not run here). Watch: `NoteSpeech(int, float, Actor akWho = None)` called with 3 args from
   LRG_Main; `SendOpen(bool)`; `HarvestLine(float)`; Scene compares; `StringUtil.Substring(s, n) as int`.
2. **Closed list (Lane B test 35, Lane F PROTOCOL 1.6)** - new game reasons: `choose that one on the list yourself`, `the entry moved
   before the click`, `the click did not take`, `the conversation was interrupted` (literals), and (returned by a function, not literal)
   `the list could not be read`, `still learning the dialogue menu - the next conversation of any kind measures it`, `still learning the
   dialogue menu - another conversation will not finish it: <why>`, `the menuless dry run is on (Menuless questing page)`.
   lrgVoicedWhy must map each.
3. **Lane A asks**: send the stage-rail note with `kind=rail` (today `kind=hint`, which bQuestHint can silence); ev=stopped whys as above;
   ev=open now carries `scene=`; `do=award` answers `OK: gave <n> septims` (gate B).
4. The HELD machine: a hand click while held -> READING with hc=1; a pick stamped while held resolves without a re-read; an adv pick
   landing on a hand-clicked layer clicks only after its own anchor and SelectAndVerify.
5. Deviations (each deliberate): combat once a second in DECIDING + HELD; an adv cancel goes to HELD (model row 25) not DECIDING; picks
   during OPENING are stamped, not refused; the 20 s LISTENING watchdog and the 8 s "never ready" hand-back are gone (nothing to give
   back - the pick is closed and the layer held); forceVisible is bounded to 5 s and no longer set by a do=show on a closed session;
   StepSuspended asks for the service windows by name when the dialogue reads as menu mode; the route flips only when not forced and
   not proven live.
6. Budget: -356 lines net over the three files (spec -900): the new HELD, anchor and route code and their comments took the difference.
   Per poll: 3 natives outside CLICKING/RESPONDING (+3 once a second while a list waits: the combat look).

## 4. Fixer round 1 (review problems)
Backups of the reviewed state: `glue/.backup/pt19c-Cfix/`. Diff: `C:\Users\Jordan\AppData\Local\Temp\lrg_test\pt19c-Cfix\pt19c-Cfix.diff`.
Lines now: LRG_Dialogue 4568, LRG_Main 4867, LRG_Profile 944 (net +8 over the pre-lane files; the -900 budget needs the orchestrator's waiver).
- **Wire-form match (P1)**: `WireKey` (CleanEntry, then `; = @` to a space, runs of spaces collapsed, trimmed, 40 characters = the server's
  `lrgDlgSafePrefix`) and `TxtMatch` (the raw text first, the wire form only when the first characters can still agree). Used by
  TryResolvePick (a)/(b)/tail and PickOnList (which now also scans the tail). A PHP port checked over the live index (37,561 rows):
  0 misses (1,821 rows miss the raw compare). Once resolved, SelectAndVerify gets `clickTxt` = the RAW live prefix (40 characters).
- **Picks that are overtaken, expired or missed twice** (`PickDead`, called before the layer goes out, so want=1 follows): overtaken (reqGen <
  layerGen) is silent; older than 20 s gives "that moment has passed"; a second miss on the SAME layer gives `staleWhy` ("the entry moved
  before the click" or "the click did not take"). An auto-advance is always closed silently. Every stale/abort re-reads first; staleCount
  is reset per pick and after a click. The stamp-time test uses layerGen (P2). TryResolvePick (a) accepts a forced re-read of the same layer
  and never reports; a pick that failed on one read layer is not scanned again (0 work per poll).
- **Decide window (P4)**: not on the list gives "that is not on the table right now"; on the list but unresolved gives "the entry moved
  before the click"; an auto-advance, or anything that is not a pick, is closed silently.
- **Auto-advance**: every second poll reads EntryCount while an auto-advance waits. A change is his click: the pick is closed silently,
  sig/lastSig are cleared (so "..." after "..." is a NEW layer: gen+1, hc=1, want=1). The 30 s cap counts from max(reqAt, lineSeenAt,
  npcSpeechAt). The F30 1.0 s rail now applies to the engine anchor too. **adv=0** is a continuer: no breath and no line anchor, with the
  subtitle blank 0.5 s (or, with subtitles off, the progress timer settled), MenuState 1, and her CHIM voice silent. It works with
  subtitles off.
- **Click window ownership**: `clickX`. A request replaced while it waits in CLICKING goes back to DECIDING/HELD. From the claim
  (dlgState = RESPONDING before the first native of the click path) to the settle, a new command waits in the one-slot queue
  (`qX/qNpc/qCmd/qParam`, twin-dropped by x) and Step replays it once the state has left RESPONDING. Finish closes a queued command
  (pick "that moment has passed", leave "The conversation is left.", anything else or an auto-advance "OK: Noted.").
- **Route proof (S3.2)**: isActorTalking is no longer a RESPONDING signal. Only the list clearing or a menu on top proves the route (rsProof);
  the progress timer and the subtitle still verify ok=1. FlipRoute counts only ClickResult -1.
- **Never false / never silent**: an unverified click reports "Error: nothing came of that" (new SayReason line: "nothing came of that").
  ReportOnce puts every answered x into the ring, and every synchronous refusal in CmdSelectTopic calls XPush first. Finish and
  StopDriving close a waiting auto-advance silently. StopReason("lethal") = "choose that one on the list yourself". A pick that reaches
  CLOSING gets "that moment has passed" (a leave gets "The conversation is left."). A pick stamped before a read-only Arm is answered by
  StepManual once the list is read. A do=leave with pos >= 0 that does not resolve is closed silently and the layer held (P3); the leave
  key overtakes a waiting pick silently. Step routes reqLeave in CLICKING too.
- **cid licence**: Arm clears sessCid for an engine-opened session; StartOpen's refusal and FailOpen clear it.
- **LRG_Main**: `OnChimSpeechStopped(player)` sends kind 3 (the X1 probe only, never speechAt). SayReason maps "nothing came of that".
- **Integration asks (update section 3)**: Lane B's closed list and lrgVoicedWhy gain **"nothing came of that"**. "the click did not take" is now
  sent through `staleWhy` (a variable), not a literal ReportOnce. Lane A still owes kind=rail (P5) and WillEmit FALSE under ml=0 (P6).

## 5. Fixer round 2 (review problems)
Backups of the reviewed state: `glue/.backup/pt19c-Cfix2/`. Diff: `C:\Users\Jordan\AppData\Local\Temp\lrg_test\pt19c-Cfix2\pt19c-Cfix2.diff`.
Lines now: LRG_Dialogue 4590, LRG_Main 4853, LRG_Profile 944 = 10387 (pre-lane 10371: net +16).
- **sessCid leftover (F7/F8)**: `CmdSelectTopic` (LRG_Dialogue:1090-1099) sets sessCid only for a LIVE session (first decision) or for the
  pick / open that is about to OPEN one, which now REPLACES any leftover. A leave / show / noop / unknown command with no session open
  no longer writes it.
- **Lethal Arm under a stamped pick**: `StopDriving` (:3199) closes a pick with `lethal` by the list: not read yet (eHead 0, the
  Arm case at :1777) -> kept, and StepManual (:3058) answers it once the list is read ("choose that one on the list yourself" only when
  PickOnList); read -> the same on-list test in place. Finish still closes a kept pick ("that moment has passed") if the list is never read.
- **Route proof hardening (in-game item b)**: when the first RESPONDING signal is not a proof (subtitle, progress timer), `rsWatch`
  keeps looking for `EntryCount() == 0 && IsOpen()` on every RESPONDING poll until the menu reads ready (:2896-2910) - only while this
  install's route is unproven (`rlSess`, one probe call per session; a proven install pays nothing).
- **Dead code**: `SessionSpeaker`, `QobjCsv` (its format note moved onto `QobjCsvOf`), `LRG_Main.SendNarrative`, `IsConvHolding` had no
  caller in any .psc (string-aware scan). `HasActiveJournalQuest` stays uncalled (spec section 4).
- **Budget (-900)**: not met, needs the orchestrator's decision. Against the pre-lane files, code lines are +46 (Dialogue +58, Main -4,
  Profile -8). Comment lines are -21. Reaching -900 would mean deleting about 910 of the 1,898 comment lines left, most of them in
  1-5-line runs next to code. Comments compile to nothing, and the runtime budgets hold. Recommendation: waive the line figure.
- **Owned by other lanes (evidence)**: P5 `lrgDlgRailNote` still sends `'kind' => 'hint'` (server/lorerim_glue/lib/lrg_dialogue.php:4296).
  The game's `kind=rail` branch is at LRG_Main:1771. P6 `lrgDlgWillEmit` (lrg_dialogue.php:5010) has no ml=0 or layer-match test. For
  [game] 3, the game half is done (LRG_Dialogue:2952, LRG_Main:1691), and "nothing came of that" is still missing from Lane B's closed list.
- **In-game (a)**: the auto-advance watch (:2496-2499) fires on any `cnt != nTotal`, the cleared gap included. After his click, that gap
  lasts as long as the engine's reply line, which is well over 0.2 s, so the 0.2 s sampling should catch it. It is still a first-evening check: look for
  `pick overtaken - the list changed under the waiting auto-advance` and `CALIB set route src=live`.
