# pt19c Lane D - calibration probe and MCM (implementer notes)

Spec: `research/pt19-menuless-v1-spec.md` rev 2, section 2.2 Lane D, S3.1-S3.4, S8 (calib rows, `reset=1`), S10, section 4.
Backups of every file touched: `glue/.backup/pt19c-D/<basename>.bak`. Nothing compiled, deployed or installed.

## 1. What changed, where

| file | lines before -> after | what |
|---|---|---|
| `game/LoreRimGlue/Source/Scripts/LRG_DlgProbe.psc` | 3440 -> 883 | Four-row passive calibration; presses, probe key, active pass, manual presses, automatic session, PENDING hooks deleted; route proof; Forget sends `reset=1` |
| `game/LoreRimGlue/Source/Scripts/LRG_DlgUI.psc` | 1289 -> 1235 | `CalClear` / `CalFileKeys` hold the v1.0 key set (+`rproof`, `rclicks`); retired keys dropped; the auto-session abort flag (`SetCalAbort` / `CalAbortWanted`) deleted; every UI primitive kept (Hide/Unhide/Guard/Park unused) |
| `game/LoreRimGlue/Source/Scripts/LRG_MCM.psc` | 153 -> 89 | the five press functions and the `bProbe`/`bCalibActive` hooks deleted; `iKeyPushToTalk:Dialogue` re-registers keys; `CalStatus`/`RefreshStatus`/`CalForget` kept |
| `game/LoreRimGlue/MCM/Config/LoreRimGlue/config.json` | 243 -> 211 | S10 exactly (below); UTF-8 no BOM, LF, valid JSON |
| `game/LoreRimGlue/MCM/Config/LoreRimGlue/settings.ini` | 324 -> 281 | S10 defaults; retired lines and the whole `[Calib]` section gone |
| `tools/test_mcm_wiring.php` | 398 -> 631 | v1.0 gate (sections 7-10 rewritten/added), `--lane-d-only` staging flag |

Papyrus budget: -2,675 lines over the three scripts (spec: -2,700). 0 per turn: nothing new runs per poll; the route proof
and the green note run only on a CHANGED value.

### LRG_DlgProbe.psc
- Kept (spec keep list): `Maintenance`, `CalArm`, `CalLayer`, `CalReadProbe`, `CalNoteReadCost`, `CalRowProbe`,
  `CalColourProbe`, `CalPoll` (timer), `CalSpeech`/`CalX1Poll`/`CalX1Verdict` (x1, log only), `CalClose`, `CalSet`/`CalGet`/`CalG`,
  `CalWire`, `CalLogSummary`, `CalForget`, `CalDirty`, `CalNewOpen`, install-file sync.
- Deleted: `OnKeyDown`/`OnMenuOpen`/`OnMenuClose`/`OnUpdate`/`OnLrgProbePlayMenuTopic`, `CalShotFire/CalShotArm`, `StepName/
  RunStepByName/RunStep`, every `Press*`, `ClickWindow`, `ClickAllowed/DoClick/DoCloseClean/DoCloseForce`, `CalPending/CalParkPoll`,
  `CalNextActive/CalActiveName/CalActiveWanted/CalActiveDisable/CalActiveRun/CalLogSkip/CalActiveLog/CalActiveA1-A5`,
  `CalPressClick/CalPressClickBody/CalClickRefusal/CalPressReopen`, `CalReopenProof`, `CalServiceNpc/CalAutoWanted/CalAutoRefusal/
  CalAutoClickWanted/CalAutoOther/CalAutoNoteOpen/CalAutoRun`, `CalArmBudget`, `CalRunsWord`, `CalTimerWord`, `CalProbeLog`, and
  the helpers only presses used (`NpcName IsGuardNpc InScene CrimeGold ChooseRoute RouteName Signature WaitForOpen WaitForClose
  TopicsPayload Gold WireText Hex Per F2 T2 Notify P PSlow`), plus their member variables (probe state, park, shots, `booted`,
  `calOff`, `cActiveRan`, `cArmHidden`, `cReopenArmed`, auto-session state). Maintenance no longer registers any key, menu or event.
- `CalMissing()` = counting (cm 1-3), reading (rm 3|4), menu layout (fam 1|2), Smart Talk settings (st 1). `CalGreen()` = all four;
  `CalAnswered()` = N of 4.
- `CalStatusText()`: `blocked - ...` (only a real Smart Talk offender or an unreadable ini - fixes the old bug where an unmeasured
  but safe Smart Talk printed "could not be read"); `learning: N of 4 - talk to anyone once (a conversation opened with E counts;
  still to learn: ...)`; `4 of 4 learned, route proven by N click(s)`; `4 of 4 learned, route not proven yet - the first simple line
  she picks for you proves it`.
- Route by doing (S3.2): `CalSet("route", r, "live")` -> `CalRouteProof` writes `rproof = r`, `rclicks = 1` (a repeat live proof of
  the same route only counts `rclicks`, store only). It ALWAYS logs `CALIB set route=<r> src=live was=<w> n=.. proven=<r> clicks=1`
  on a new proof, even when route already held that value (a pt18 install file carries route from the retired auto click, which
  would otherwise swallow the owner's evening evidence line). Any other source that CHANGES route clears `rproof`/`rclicks`.
  New `bool CalRouteLive()` = `route > 0 && rproof == route` - Lane C's "route src == live".
- The corner note on the red -> green edge: `CalSet` on a changed gate row logs `CALIB GREEN 4 of 4 learned (...)` and notifies
  "the dialogue menu is learned (4 of 4)" once (5.3 step 1). Restores from the install file never reach it.
- `CalForget()` wipes store + UI calibration + install file, then `CalSendReset()`: `lrg_dlg` `ev=calib;v=1;ref=00000000;npc=<player>;
  src=forget;gate=0;reset=1;miss=<...>;k=<CalWire>` (respects `bDlgWire`, k LAST and raw). One corner note (the MCM's duplicate
  "forgotten" note removed; "not attached" note added so the button is never silent).
- `CalWire()` (k=) = exactly `cm rm fam st route timer x1 apd ms3 tail` (S8). `CalLogSummary` also prints `proven clicks lp col row
  pay actms`.
- `rm = -3` wording no longer points at the retired opt-in pass (the driver's own `ReadModeAuto` can still prove mode 3/4 and the
  cheap recovery picks it up). `CalOpened`'s warning names "'Plain activation' on the Menuless questing page".

### config.json (S10)
- Keys page: `Open vanilla dialogue (emergency)` removed; NEW `Your talk key (CHIM's push-to-talk)` (`iKeyPushToTalk:Dialogue`,
  keymap) with the S10 help sentence verbatim; footer header reworded (no probe key).
- Menuless questing page: removed iEngineOpen, iBranchInput, fSilenceTimeout, `Hiding the menu` header, iHideMode, bHideCursor,
  `Choices one layer deeper` header, bRewalk, iRewalkDepth, bHandBackNote, bResumeAfterChoice, iKeyProbe, `First-playtest probe`
  header, bProbe, iProbePress, bIaccToggle, bQuestColour. NEW `Let her carry on by herself when there is only one thing to say`
  (`bAutoAdvance:Dialogue`, toggle) under "The feature". Headers renamed: "Reading the list (leave these on automatic)",
  "Advanced - leave alone unless the log tells you otherwise".
- Calibration page = exactly `Where it stands` / `Calibration status` (scriptName LRG_MCM, CalStatus) / `Forget everything it learned`.
- Help texts rewritten so nothing the owner reads is false in v1.0: bMenuless (visible list, no emergency key, no "green" gate),
  bDlgDryRun (ships OFF, never switches itself), iCritical (no input guard; 0-bounty guard is ordinary), iSceneGate (S10 rev2
  sentence verbatim), bIntentOpen (no extra AI reply, narrow marker), fDecideTimeout (list stays on screen), iReadMode/iCountMode/
  bActivateDefaultOnly (no probe references), iClickRoute (route proven by the first click, flips after two failures / 9 s),
  Calibration status, Forget, bServiceDialogue (Services page: no "Calibration page is green"), bDryRun (Diagnostics: the menuless
  dry run "ships off as well", not "the one that ships on").

### settings.ini
`bDlgDryRun = 0`, `iSceneGate = 1`, `iTailMax = 16`, `bAutoAdvance = 1`, `bDriveSceneMenus = 1` (no control), `iKeyPushToTalk = 29`.
Removed: `bDlgDryRunHold iHideMode bHideCursor iEngineOpen iBranchInput bRewalk iRewalkDepth fSilenceTimeout bHandBackNote
bResumeAfterChoice bProbe iProbePress bIaccToggle bQuestColour iKeyVanillaMenu iKeyProbe` and the whole `[Calib]` section with its
comment block. Comments rewritten to v1.0.

### test_mcm_wiring.php
- Maps: `bi`/`rw`/`rwd` knobs and `bIaccToggle` removed; `bDriveSceneMenus:Dialogue` in GAME_ONLY with its reason (an ini-only id
  must have its ini line and a reader; section 4 does not call it an orphan).
- 7: dry run ships OFF, help must not say CLEARS ITSELF / name the hold switch; ship defaults per S10; no script may write
  `bDlgDryRun` via `MCM.SetModSetting*` (the auto-clear is retired).
- 8: no `[Calib]`, no `:Calib` id, Calibration page shape exact, retired functions defined nowhere, the frozen probe API
  signatures present, CalMissing = the four rows, N of 4, the S10 status strings, CalWire = S8 rows, CalForget sends `reset=1`,
  the route proof, `CalClear`/`CalFileKeys` key sets; server calib-candidate functions reported as notes (Lane A).
- 9: 16 exact S10 labels, pages/types of the two new controls, the two S10 help substrings, `bDriveSceneMenus` has no control,
  in-code defaults of the three new ids equal their ini lines, 28 retired phrases absent from every label/help.
- 10: 23 retired ids absent from config.json, settings.ini, this test's maps and every comment-stripped .psc.
- `--lane-d-only`: evidence found ONLY in another lane's .psc prints `[pending C]`; Lane D's files are strict in both modes.
  Mutation-tested on a copy (a retired id in LRG_DlgProbe, a `[Calib]` line, "CLEARS ITSELF" in a help, `hide` in CalClear and in
  CalWire): all six caught as FAIL even with `--lane-d-only`.

## 2. Frozen probe API for Lane C (signatures unchanged)
All of spec 2.2's list, plus - additive - `bool CalRouteLive()`, and `CalOpened(int, int)` / `CalPayload(int, bool, int)` KEPT
unchanged because `LRG_Dialogue.psc:1567` and `:3399` call them (CalOpened writes the kept `actms` measurement; CalPayload writes
`pay`, now log-only). `CalNewOpen(bool)` is a documented no-op (its state belonged to the active pass); C may drop the call.

## 3. What Lane C MUST do against this tree (else compile errors or a silently dead calibration)
1. **`bCalibPassive:Calib` is gone. A missing MCM key reads FALSE**: if `sCalibPassive = m.SettingBool("bCalibPassive:Calib", true)`
   (LRG_Dialogue:673) stays as a gate, CalArm/CalLayer never run and the four rows are never learned. Call CalArm/CalLayer on every
   arming, ungated. Same for `bAutoTimings` (:674), `bCalibOverride` (:675), `bCalibAuto` (:676), `iCalibRuns` (:612),
   `bDlgDryRunHold` (:641), `iKeyVanillaMenu` (:642 and LRG_Main:1240) and the S10 dialogue readers (:647-679).
2. Delete the call sites of functions that no longer exist: `p.CalPending` (:2535), `p.CalActiveWanted/CalActiveRun`,
   `p.CalAutoWanted/CalAutoRefusal/CalAutoClickWanted/CalAutoOther/CalAutoNoteOpen/CalAutoRun`, `LRG_DlgUI.SetCalAbort` (:976, :1758,
   :4413), `LRG_DlgUI.CalAbortWanted` (:1930); and the direct `LRG_DlgUI.CalSetInt("auto", ...)` (:1766).
3. Route proof: on the first click whose RESPONDING signal arrives, `p.CalSet("route", route, "live")`; a flip after two
   `ClickResult <= 0` or 9 s may write `CalSet("route", other, "flip")` (clears the proof) or keep the flip in memory. The
   journal-scene drive condition is `bDriveSceneMenus && p.CalRouteLive()`.
4. New readers with in-code default = ini: `SettingBool("bAutoAdvance:Dialogue", true)`, `SettingInt("iKeyPushToTalk:Dialogue", 29)`
   (treat `<= 0` as off - a cleared MCM keymap can store -1), `SettingBool("bDriveSceneMenus:Dialogue", true)`. `LRG_Main.RegisterKeys`
   must register the talk key (LRG_MCM already calls RegisterKeys when `iKeyPushToTalk:Dialogue` changes).
5. Wording: `CalAnswered()` is now 0..4 - replace every "N of 10" (CalAnsweredNow doc, DlgDryReason, notifications) with S3.4's
   texts; drop `runs=` from SendCalib (the key is retired; CalGet returns 0). The "menu is learned" corner note now comes from the
   probe - do not add a second one when ReadCalibration (e) goes.
6. Then `php tools/test_mcm_wiring.php` (strict, no flag) must be green: today its 24 FAILs are all in LRG_Dialogue/LRG_Main.

## 4. Other lanes / integration
- **Lane A**: `lrgDlgOnCalib` must read `reset=1` (message shape above; `npc=` is the player's name, `ref=00000000`) and clear
  `clicks_ok` on `*install*`. `d50_wire05` is A's: the new k= is 10 rows (`cm rm fam st route timer x1 apd ms3 tail`).
- **flows `d63_out_of_the_box.php` (not a Lane D file) now FAILS** two checks that assert the retired pt17 defaults
  (`bDlgDryRun=1`, `bDlgDryRunHold=0`, `[Calib] bCalibActive=1`). Its owner must assert `bDlgDryRun=0`, no `bDlgDryRunHold`, no
  `[Calib]` (spec S3.4/S10). `d53_mcm.php` runs `test_mcm_wiring.php` strict: green once Lane C lands.
- **Spec tension to resolve (Lane A / F)**: S10 keeps `bQuestInitiative` / `iQuestInitiativeGap` ("The quest tree unchanged") while
  section 4 deletes `lrgDlgInitiativeCandidate`/`<she_may_raise>` and S9 removes `quests.initiative.*`. If Lane A removes the last
  `.php` reader of the `qi`/`qig` wire keys, `test_mcm_wiring` section 2 fails for those two ids (correctly: a dead control).
- Lane F: PROTOCOL 10.13 (four rows, route by doing, Forget clears `clicks_ok`), README MCM/keys tables.

## 5. Tests run (WSL copy `C:\Users\Jordan\AppData\Local\Temp\lrg_test\pt19c-D\glue`)
- `php -l tools/test_mcm_wiring.php`: no syntax errors. config.json: no BOM, LF, valid JSON.
- `php tools/test_mcm_wiring.php --lane-d-only`: `36 passed, 0 failed, 24 pending Lane C` / `LANE D CHECKS PASSED`, exit 0.
- `php tools/test_mcm_wiring.php --quiet` (release gate): `36 passed, 24 failed` - every failure is a reader in LRG_Dialogue.psc /
  LRG_Main.psc (section 3 above).
- Baseline (pre-change tree from the .bak files) vs this tree: test_gates rc 0/0, test_dialogue 567/567, test_intent 459/459,
  test_phrases 47/47, test_scene_index, test_latency_prompt unchanged; test_services ("no census") and test_prompt_index (84 passed,
  1 failed) fail identically before and after (not Lane D). flows: 84/84 -> 82/84, the two being d53 (strict wiring gate, waits for
  C) and d63 (asserts retired defaults, section 4 above).
- Papyrus: `compile.ps1` NOT run (not allowed for this stage). A structural lint (balanced Function/If/While, no call to an
  undefined local function, no duplicate function, no docstring > 500 chars) is clean on all three scripts; no string literal > 500.

## 6. What the reviewers must check
1. `compile.ps1` on LRG_DlgProbe / LRG_DlgUI / LRG_MCM once Lane C has removed the call sites in section 3.2 (they will not
   compile together before that - by design of the order D -> C).
2. `CalSet`'s route-proof path: a same-value live write logs and syncs; a non-live CHANGE clears the proof; a same-value non-live
   write does nothing.
3. The green-edge note fires once (changed gate row only), never on a restore.
4. `CalSendReset` payload order (k= last and raw) against `lrgDlgOnCalib`'s parser.
5. The rewritten help texts: iCritical relies on C's ClassifyCrit (0-bounty guard = crit 0), bIntentOpen's "no extra AI reply" on
   A's S2.1, iClickRoute's flip on C's S3.2 - each true only once those lanes land as specified.
6. Deviations: `CalOpened`/`CalPayload` kept (not in the keep list; LRG_Dialogue calls them); `SetCalAbort`/`CalAbortWanted`
   deleted from LRG_DlgUI (the automatic session's abort flag, not a UI primitive); the status line adds a parenthetical after the
   S10 prefix; retired `LRG.cal.*` values in older saves are left inert (nothing reads them; CalFileRestore reads only the v1.0 keys).

## 7. Fix round 1 (lane fixer, 2026-09-24) - supersedes sections 1-6 where they differ
Backups of the pre-fix state: `glue/.backup/pt19c-D-fix1/`. Nothing compiled, deployed or installed.
Note: two reviewer edits were already in the tree when the fixer started (config.json and test_mcm_wiring.php, 16:49): the
bQuietIntro help lost "no automatic calibration", the Forget help lost "starts over on every save", and both phrases went into
`$retiredPhrases`. They are kept; the Forget help was reworded once more (below).

| # | problem (reviewers) | resolution |
|---|---|---|
| 1 | green note never fires on a restored / pre-v1.0 install (game, ai, arch P4) | `CalGreenNote(asHow)`: the note + `CALIB GREEN 4 of 4 learned (<how>) ...` once per INSTALL, keyed on `gnote` (store, `CalFileKeys`, `CalClear` - Forget re-arms it). Called from CalSet's red->green edge ("learned now") AND from the first `CalLayer` of each game session ("already learned"). Sets `dirty = 1`, so the driver's close sends one ev=calib and the server logs `cal k=` on that conversation (5.3 step 1's log line). Owner's install file (rm=3 cm=3 fam=1 st=1, no gnote) -> the note shows on his first v1.0 conversation. |
| 2 | 5.3 step 2 literal `CALIB set route src=live` not in the log (game) | a NEW live proof now logs `CALIB set route src=live route=<r> was=<w> n=<n> proven=<r> clicks=1 npc=<who>`; every other answer keeps `CALIB set <key>=<v> src=<src>`. |
| 3 | status line "the first simple line" false after a flip (game, arch P7) | "4 of 4 learned, route not proven yet - the next line she picks for you proves it". |
| 4 | stuck states with a false "talk to anyone once" (arch P9) | `string CalStuckWhy()` names cm=-1 ("no way of counting her list works yet"), rm=-3 after rmN>=3 ("her list cannot be read on this install"), an unknown interface (`famu` >= 3: armings on an OPEN menu whose SwfFamily is 0, counted in CalArm only while fam is unanswered - one native). NEW frozen-API addition `bool CalLearnable()` (false for those and for a Smart Talk offender / unreadable ini, from the cached `LRG.du.sto`), for Lane C's refusal words. |
| 5 | quest-initiative pair is a dead switch after Lane A (game, ai) | RETIRED: `bQuestInitiative:Quests` / `iQuestInitiativeGap:Quests` removed from config.json and settings.ini, added to `$REMOVED`; `SERVER_KNOBS` qi/qig dropped; section 10 also asserts no script writes `;qi=` / `;qig=` (pending C: LRG_Profile.psc:849-863). Rationale: spec section 4 / S9 delete lrgDlgInitiativeCandidate, `<she_may_raise>` and quests.initiative.*, and the hard rule is "no dead switch"; S10's "The quest tree groups unchanged" is overridden for these two ids. The orchestrator may reverse this only by keeping the server initiative (then section 4 / S9 must say so). |
| 6 | "menu comes back" / "hands you the menu" / "consequential choices" (game, ai, arch P3) | bCrimeManual label "Always leave an arrest to me", help says what v1.0 does (crit 2: list stays, nothing picked, she says so; Off -> iCritical decides; resist-arrest never). iCritical options "Leave them to me" / "Let her pick there too"; its help says it only acts while bCrimeManual is off and resisting arrest is never picked. iKeyLeave: "closes nothing - the list stays yours to click", points at 'Your talk key (CHIM's push-to-talk)'. settings.ini [Services] comment rewritten. `$retiredPhrases` += menu comes back, hands you the menu, hand you the menu, Hand me the menu, consequential choices, first simple line; the scan now covers enum options too. |
| 7 | currency (game, ai) | septims in bPaidIntimacy (label + help), fPriceMultiplier, bLockedFacts, bTruthGate (label), and the two settings.ini comments. NEW check: no label / option / help / ini comment says "gold" except the game's own "(N gold)". |
| 8 | bIntentOpen help (game, arch P2) | N0 and N1 ("no conversation with her is open"), the five narrow-marker clauses in plain words, "merely being part of a quest is not enough". |
| 9 | fLineSettle / iClickRoute overclaims (ai, arch P5) | settle gates the FIRST pick, later picks wait for her voice; the 9 s flip is "on route A". |
| 10 | bQuietIntro (game, ai, arch P6) | adds "no conversation is opened for you and nothing on a dialogue list is picked for you (a list the game shows is yours to click)" (model F17 - true once C/A land F17). |
| 11 | Forget help "starts over on every save" (ai) | "a save you load afterwards does not get them back from that file. A save made before you pressed this still remembers what it had learned, and playing it writes that back." |
| 12 | CalAnswered 0..4 reaches the server's `cal=` (arch P1) | not a Lane D file - routed in the contract (Lane A: lrgDlgLearningText; Lane B: lrgVoicedDryWhy). |
| 13 | contract `p.CalSet("route", route, "live")` does not compile (arch P8) | contract corrected: 4 arguments, `p.CalSet("route", route, "live", 1)`; the frozen signature is unchanged. |

Budget: the three scripts are 950 + 1240 + 89 = 2,279 lines (from 4,882): -2,603 (spec -2,700; the fixes add ~30 lines of
code, the rest of the gap is the implementer's -2,675). Per turn: 0 (the note check runs on the first list of a game session
and on a red -> green edge; `famu` costs one native only while fam is unanswered on an unknown interface).

Tests (WSL copy `C:\Users\Jordan\AppData\Local\Temp\lrg_test\pt19c-Dfix\glue`): test_mcm_wiring `--lane-d-only` 39 passed, 0
failed, 27 pending Lane C (the 24 before + bQuestInitiative / iQuestInitiativeGap readers + the qi/qig carrier); strict 39
passed, 27 failed (all Lane C). Ten mutations (gold in a help, gold in an ini comment, the old iCritical option, "menu comes
back", the initiative control back, gnote out of CalFileKeys, no CalLayer note, "first simple line", the route literal gone,
the cm=-1 reason gone) each FAIL with --lane-d-only. Structural lint: balanced, no duplicate / undefined LRG_DlgUI or LRG_Main
calls, docstrings <= 382 chars; its 4 "undefined local function" hits are words inside docstrings that contain a ';'
(the pre-v1.0 probe compiled with the same pattern). Suites unchanged from the implementer's run: test_gates rc 0,
test_dialogue 567/567, test_intent 459/459, test_phrases 47/47, test_scene_index ok, test_services "no census" and
test_prompt_index 84/1 as before; flows 82/84 (d53 = strict gate waiting for C, d63 = retired defaults, not a D file).

## 8. Fix round 2 (lane fixer, 2026-09-24) - supersedes section 7 row 6 where they differ
Backups of the pre-fix state: `glue/.backup/pt19c-D-fix2/` (config.json, settings.ini, test_mcm_wiring.php, this file).
Nothing compiled, deployed or installed.

| # | problem (reviewers) | resolution |
|---|---|---|
| 1 | arch P3(a): the iCritical help ("'Let her pick there too' allows picks ... while 'Always leave an arrest to me' is off") and the bCrimeManual help ("Off: 'Guards, arrests and bounties' ... decides instead") promise picks v1.0 never makes | Checked, and the reviewer is right. Nothing reads either setting except LRG_Dialogue.psc:650 / :681 / :1807. S1.1 (spec :221) drives a session only when "the speaker is not crit 2", and no setting enters that test. The server refuses every pick on crit 2 at any setting: lrg_dialogue.php:3274 (want=1, "never clicked"), :3657 (gate, do=show lethal) and :2562 (HandBackForeseen). These are the reviewer's :2664 / :3047; the lines moved when Lane A landed. The arrest-class rail at :3629 is a server config key (`services.kinds.crime.lethal`), not an MCM setting, and resist-arrest is refused first at :3611. ClassifyCrit (:4004) returns only 0 or 2. So both are dead switches. **RETIRED** (option a, like qi/qig in fix 1): both controls and ini lines are gone, and both ids are in `$REMOVED`. S10's label stays on the Menuless questing page as a **text row** with no id and no switch: "Guards, arrests and bounties: always yours to click". Its help states the fixed rule: nothing on the list is ever picked, at any setting; resisting arrest is never picked; a guard with nothing against you is ordinary; the old settings are gone because they could not change this. The settings.ini [Dialogue] note and the [Services] comment were rewritten. `$retiredPhrases` gained: 'Let her pick there too', 'Always leave an arrest to me', 'allows picks in those conversations'. Section 9 asserts the text row's shape and help. **Deviation:** S10 lists iCritical as kept. It is overridden on the hard rule "no dead switch" and on never-false: the option label "Let her pick there too" is itself false, so option (b) cannot be made true. To reverse this, the orchestrator must give the settings a real effect (c), which the spec does not provide. Interim safety: a missing key reads 0 / FALSE, so the pre-C reader at :1807 still takes the lethal branch (sCritical == 0). |
| 2 | arch P1 (routed): "N of 10" is still in lrg_actions.php:1300-1301 (Lane B) and LRG_Dialogue.psc:480/:853/:855/:3102 (Lane C) | Not Lane D files. The reviewer is right and the lines are confirmed. It is now enforced by the release gate: new **section 11** of test_mcm_wiring reads every **string literal**. For Papyrus it uses a one-pass scanner over the raw source that skips comments and {docstrings}. The comment-stripped copy cannot be used, because CalAnsweredNow's docstring holds a ';', the strip then eats the closing brace, and three of the four hits went missing. For the server it uses PHP's tokenizer. A Lane D hit is a FAIL. With --lane-d-only, another lane's hit shows as `[pending C]` / `[pending B]` / `[pending A]` (`pending()` now takes the lane); in the strict gate it is a FAIL. It currently finds exactly the reviewer's six lines. |

Tests (WSL copy `C:\Users\Jordan\AppData\Local\Temp\lrg_test\pt19c-Dfix2\glue`):
- `php -l`: clean. config.json: no BOM, LF, valid.
- test_mcm_wiring `--lane-d-only`: 40 passed, 0 failed, 31 pending. That is the 27 before, plus iCritical / bCrimeManual readers (C), the four driver strings (C) and the two lrg_actions.php strings (B).
- Strict: 40 passed, 31 failed, all in other lanes' files.
- Mutations (each on a fresh copy):
  - bCrimeManual back in config.json and settings.ini: 2 FAIL.
  - The row's help loses "at any setting": FAIL.
  - The row gets its old id back: 3 FAIL.
  - An "of 10" string in LRG_DlgProbe: FAIL.
  - "of 10" only in a docstring and a comment: stays green (correct).
  - "Let her pick there too" in a help: FAIL.
  - Server strings: `[pending A]` for lrg_dialogue.php, `[pending server]` for an unowned file.
- Flows, pre-fix-2 tree vs this tree (same copy, data/ from the server): 62/84 both times, with an IDENTICAL failing set. The 20 more than fix 1's 82/84 come from Lane A landing in the meantime, not from Lane D. d53 is the strict gate. d63 still asserts the pt17 defaults at :36-39, and also "4 of 10" at :60 / :64, which Lane A's lrgDlgLearningText now words as "N of 4".
