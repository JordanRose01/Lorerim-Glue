# pt9 go-live: fix + release pass

**Scope.** 24 reported defects against LoreRim Glue v0.5.1 (12 game / Papyrus, 12 server / PHP), each
traced against the real code before any edit. **24 confirmed, 24 fixed, 0 refuted.** Menuless questing
still ships INERT (`bMenuless = 0`, `bDlgDryRun = 1`); no shipped default was changed by this pass.

Deliverables: compiler OK, `php -l` clean, every test and flow green, server deployed, game files
installed by the documented fallback (MO2 was open), owner notes written.

---

## 1. Verification method

Every finding's cited evidence lines were opened and read before the fix was written. The line numbers
in the brief were accurate throughout; nothing had drifted. Where a finding proposed a fix that did not
survive contact with the surrounding code, the fix was changed and the deviation is recorded in §4.

Baseline before any edit (so that every later failure could be attributed): gates 319, intent 190,
phrases 14, scene index OK, dialogue 132, services 35, latency 26, MCM wiring OK, prompt index 67,
flows 74/74 with 1,161 checks, 0 warnings.

---

## 2. Game fixes (`LRG_Dialogue.psc`, `LRG_DlgUI.psc`)

| # | Severity | What was wrong | Fix |
|---|---|---|---|
| G1 | critical | `HandleEmergencyKey` fell into the "lost stack" branch during the `ST_OPENING` window, where `IsSessionOpen()` is true but `loopRunning` is false. `DoUnhide`'s two `WaitMenuMode(0.5)` calls kept the key stack alive while the Activate completed, so `Arm()` hid and guarded the new menu and the key stack then wrote `ST_MANUAL` over `ST_LISTENING`. Result: MANUAL + hidden + guarded, which `StepManual` never repairs. `LRG.du.hid` stayed 1 (chains into G7). | New first branch, before `IsSessionOpen()`: `openWanted \|\| dlgState == ST_OPENING` → cancel the open, `forceVisible = true`, close any in-flight request, return false (or true when the menu is already open, so `LRG_Main` does not Activate on top of it). `forceVisible = true` on **every** branch of the function. |
| G2 | major | `reqEmergency` / `reqDump` / `reqWant` outlived their session: `Finish()` calls `ClearRequest(false)`, which only clears them under `abHard`, and `Arm()` does not clear them either. A key pressed inside `SettleAndReport`'s 4 s settle (where `loopBeat` keeps being refreshed) latched and fired on the **next** session, minutes later, on a different NPC. | The three flags are cleared in `ClearSession()` — the one place that runs at the end of every session and nowhere else harmful. `openWanted` deliberately excluded: it belongs to the pump, not the session. Plus `!finishing` on the latch branch (see G6) so the press is not swallowed in the first place. |
| G3 | major | Any entry past `iMaxEntries` (16) was structurally unclickable while **both** server emit paths could pick it: `BuildTail` sends true array positions, the server merges by position and ranks head + tail. `TryResolvePick` branch (a) required `reqPos < eHead`, branch (b) searched only `eText[0..eHead)`, and `StepClicking` refused `reqPos >= eHead`. Cost: decide timeout → `ST_PENDING` → up to `fSilenceTimeout` (45 s) of frozen hidden menu, repeatable on every long-list NPC. | New tail branch in `TryResolvePick`: for `eHead <= reqPos < nTotal` with a non-empty `reqTxt`, read the live text once and require a prefix match from index 0, then take the live `topicIndex`. `StepClicking`'s bound moved from `eHead` to `nTotal`. New `PosText()` helper keeps the two log lines from indexing past `eText`. A tail pick with an empty `txt=` is still refused — position alone is not evidence for an entry this driver never read. |
| G4 | major | `inScene` was captured once at ARMING and never refreshed, so a quest scene started by the driver's **own** click (any walk-and-talk INFO) was invisible to every promise that depends on it: the D-17 PENDING refusal, resume arming, `scene=` on the wire, the `lrg_dlgtalk` gate. | One `GetCurrentScene()` per **layer**, on the `ReadList` branch where the signature really changed. Sets `inScene`, and outside MANUAL hands the menu back with `why=scene` and returns `false` (the finding said `true`, which would have let `StepReading` overwrite `ST_MANUAL` with `ST_DECIDING` — see §4). |
| G5 | major | A second command for the same NPC overwrote `reqX` / `reqCmd` / `reqParam` and reset `reqReported` with no `ReportOnce` for the request being replaced, so the first `x` never got its funcret or `commandEndedForActor` and CHIM waited for a result that never came. Both deliveries pass `XSeen` because the executed ring is only written at click time. | `ReportOnce("Error: that moment has passed")` immediately before the stamp block, mirroring what `HandBack()` has done for the same reason since 0.5. |
| G6 | major | `OnMenuOpen` returns whenever `loopRunning && now - loopBeat < 5.0`, and `Finish()` holds `loopRunning` true to its last line while refreshing `loopBeat` through a 4 s settle and two 0.5 s unhide waits. A conversation opening in that window got **no `Arm()` at all**: no `GuardReset()` (rule R1's only repair), no hide, no `lrg_topics`, no `ev=open` — while `IsSessionOpen()` still returned true, so `LRG_OStim.DialogueBusy`, `LRG_Main.ConvRefuseReason` and `RepairFollower` all refused work on its behalf. | New `finishing` field, set at the top of `Finish()` and cleared in `RunSession()` **after** `loopRunning = false`. `OnMenuOpen` cannot arm the menu itself in that window (the dying `Finish()` would tear it down again on its way out), so it raises `rearmWanted` and sends the pump; `OnLrgDlgPump` arms it on a fresh stack once `loopRunning` is really false, re-pumping while it is not and giving up after 15 s. |
| G7 | minor | `LRG.du.hid` / `hok` / `hfam` had no load-time reset, so a hide lost to a dumped stack or a crash (`HardReset` only unhides while the menu is still open) latched `IsHidden()` true for the rest of the save: a full `DoUnhide` on every healthy vanilla menu, and `CalActiveWanted()` refusing every active test for ever. | `SSetInt("hid", 0)`, `("hok", 0)`, `("hfam", -1)` added to `ResetCalibration()`, which already runs once per load. `Hide()` re-reads and re-validates the stored floats on every use, so this costs nothing. All three probe call sites are "measure from nothing" contexts on a visible menu. |
| G8 | minor | Layer signature was `count \| first text \| last text`, so a middle-only change (a persuade entry swapped after the freeze, a topic consumed and replaced) did not bump `gen`; the layer was never re-sent and the server ranked against positions that had moved. Never a wrong click — `SelectAndVerify` catches it — but a lost turn the server could not diagnose. | Fold the topic identities in: `+ "\|" + (eIdx[0] + eIdx[eHead/2] + eIdx[eHead-1])`. No extra UI read. |
| G9 | minor | The whole SUSPENDED path hung on `mmBase = Utility.IsInMenuMode()` taken once at ARMING — a value nobody has measured from inside a Dialogue Menu on this install, and one `CalMissing()` has no gate row for. If it reads true, a Barter / Training / Gift window over a driven session was never seen: `StepReading` saw 0 entries, dropped to LISTENING, and the 20 s watchdog unhid the topic list underneath the player's open trade window. | `ServiceMenuOpen()` asks for the three windows by name, used as a fallback in `Step()` guarded by `eHead > 0 && lastN == 0` (so a silent NPC pays nothing and a normal session pays only on the poll where the list has just emptied), and once more in `Finish()` for `why=handoff`, where it is free. `StepResponding` was left alone: signal 1 (`EntryCount() == 0`) always precedes a repopulate, so the redundant signal 3 is already covered and a per-poll cost there was not justified. |
| G10 | minor | Combat, mount and arrest are tested only in `OpenBlockedReason`, whose sole caller is `StartOpen`. A bandit ambush or a guard's arrest ForceGreet after ARMING was never noticed, and the hidden guarded menu kept being driven through the fight. | `CombatBroke()` (two natives) on the two states that wait for an answer — `StepDeciding` and `StepPending` — handing back with `why=combat`. |
| G11 | minor | `smartSafe` was read once per load in `Maintenance()`, while the Calibration page re-reads `SmartTalk.ini` live. The owner fixed the ini, saw the page go green, and the driver went on forcing every session visible with `visible=smart talk skip` for the rest of the play session, with nothing in the log explaining it. | Moved into `ReadSettings()`, which has a 5 s cache; the load-time duplicate removed. |
| G12 | major | `BuildEntries` called `EntryColour(i)` with an **array position** while that function takes a laid-out **screen row** (its own docstring says so, and `EntryRowItem()` exists for the mapping and is used correctly by the dump). On any list longer than the row count (8 on CHIM's SWF) or any scrolled list, Smart Talk's `cQuestEntryColor` was attributed to the wrong entry and the server added `+3` to its score against a quest weight of 4 and a similarity weight of 6 — the ranker then preferred the wrong topic and the glue clicked it. The only wrong-entry-clicked path found; inert today only because both toggles ship off. | A colour is sent only when row **is** position for the whole list: `sQuestColour && nTotal <= maxItems && i < maxItems`. |

---

## 3. Server fixes (`lib/lrg_dialogue.php`, `lib/lrg_speech.php`)

| # | Severity | What was wrong | Fix |
|---|---|---|---|
| S1 | high | `lrgDlgAnswerWant()` (the `want=1` fast path, which runs in preprocessing with no LLM and no turn record) had the LETHAL rail but neither the resist-arrest rail nor the arrest-session hand-back the post-LLM gate has. `ClassifyCrit` grades LETHAL as `IsGuard() AND (engine-opened OR crimeGold > 0)`, so a glue-opened session with a mod-added enforcer arrives `crit=0`; the vanilla resist rows ship `crit=0 twat=''` and were saved only by an unrelated scripted+goodbye heuristic. | Both gate rails added verbatim after the LETHAL rail: `lrgDlgIsResistArrest($e)` and `lrgDlgArrestClass([...]) !== '' && services.kinds.crime.lethal && crit !== 1`. |
| S2 | high | `lrgDlgParkOrRelease()` released the two-step confirmation on **any** second selection of the same norm from a different `cid`. Its only content test was a token count, and that test was skipped entirely on a non-speech turn, where `$st['utter']` still holds the sentence that parked the item. So an explicit refusal committed, and an `lrg_dlgtalk` poll with no player turn at all committed. | Three requirements to release: the turn must be a **speech** turn; the utterance must differ from the one stored on the park (new `parked.utter`); and it must not be a back-out or a refusal phrase — a refusal clears the park instead of leaving it armed. The existing single-token guard is kept. Keyed on the utterance rather than a timestamp, because two turns can share a second (see §4). |
| S3 | high | `lrgDlgServiceSlot()` returned `mode=pick` on exact token containment with no test that the sentence was an instruction. Measured against the real index: "I've just come from Riften.", "My brother lives in Morthal.", "They call me Riften, after the city.", "Is the road to Dawnstar safe?" all executed. Deferred on the vanilla CFTO list only by the accidental scripted+goodbye park (which the next turn released); fired on the first turn on `KmodFastTravelCarriageEastmarch` / `...FalkreathHold` / `...Haafingar`. | An imperative frame is required before `pick`: `lrgDlgServiceKind($utter) !== ''` **or** the whole normalised utterance equals the slot name. Otherwise `mode=none`. |
| S4 | medium | `lrgDlgLockedFacts()` rendered `svc.slots`, which `lrgDlgPrepareTurn()` collapses to the single matched slot on a pick, under a header stating the listed facts are confirmed and true — so the NPC was told as fact that one destination was "the only one on her list" and denied her own list. | Uses `svc.all` (the full census built on the same turn), falling back to `svc.slots`. `svc.slots` keeps its job in the ambiguity wording. |
| S5 | medium | `bLockedFacts = 0` silently disabled `bTruthGate`: `lrgDlgLockedFacts()` returned `[]` whenever `lf !== 1`, and `lrgDlgTruthCheck()` returns null the moment `$t['locked']` is empty. This is the exact bug the 0.5.0 comment claims was fixed — the fix named the right switch at the gate but the facts were still gated by the wrong one upstream. | The `lf` check moved out of `lrgDlgLockedFacts()` and into `lrgDlgLockedBlock()`. Facts are now always computed; `lf` decides only whether the NPC is told them, `tg` only whether an unconfirmed number may act. |
| S6 | medium | `lrgDlgTruthCheck()` matched `/(\d[\d,]*)\s*(gold\|septims?\|coins?)/` anywhere in the reply, so a driver reminiscing ("I lost 300 gold at dice…") cancelled a ride the player had just confirmed — the owner sees her agree out loud and nothing happens. | A price frame is now required around the number: `costs/charges/fare/price/fee/owes/asking/for/will be/that's/pay/give me/hand over` within 20 non-period characters. Past-tense narration is left alone. |
| S7 | medium | `lrgDlgFollowerVerbOf()` lost the topic lookup for "Follow me." / "Wait here." to a `CWMission04` row in no family glob, and the text fallback demanded `$inFamily \|\| count($pt) >= 3` — two tokens. Because dismiss/trade still resolved, `fol.verbs` was non-empty and `lrgDlgServiceHidePolicy()` had already removed CHIM's `MakeFollower` / `FollowPlayer` / `WaitHere` / `Follow`: the two most-used follower commands did nothing and their fallback was gone. | `\|\| $norm === $pt` — a two-token phrase is accepted when it **is** the whole entry, not merely contained in it. Cannot widen into quest lines: "Follow me. I need your help." is a different token run. |
| S8 | medium | `lrgDlgCheck()` recorded the result in `$st['checks']` but still set `award = true` on **every** pass, so one sentence granted Speech XP for ever; and on a memory hit `$N` stays 0, so a repeated bribe kept verdict `pass` while `take=` collapsed to zero — the same guard bribed free from the second attempt onward. | `if ($res === 'pass' && $memState === 'miss')`. A remembered pass keeps its verdict for the narration and falls through both consequence branches, which is the intended outcome. |
| S9 | medium | `lrgDlgSafePrefix()` returned `''` for anything under 12 characters — 2,895 of 37,561 live index rows (7.7%), including every bare destination name — and an empty `txt=` disables the game's only content check on the click (`TryResolvePick` falls back to position alone), on exactly the entries where a mis-positioned click spends gold and teleports. | The floor is gone. The sanitiser already strips `; = @ | " ~`, which is what the floor was protecting. Pairs with G3, which now **requires** a prefix for a tail pick. |
| S10 | low | `services.slot.min_priced = 0` routed 138 of 5,718 live layers (2.4%) with no priced entry through the slot matcher — "feim / fus / yol", "heavy / light / medium", "consider it done / forget it / …" — restricting execution on those to exact containment and disabling paraphrase matching on real quest choices. | The relaxation now needs a reason: `min_priced` is raised back to 1 unless the layer has a priced entry, a kind was passed, or the utterance is a recognised service request. |
| S11 | low | `lrgDlgPostProcessActions()` forwarded any line whose code is not in `LRG_DLG_GATE_NAMES`, including a money or follower shortcut this module had hidden for that very turn. The hide policy only edits `ENABLED_FUNCTIONS`, which is advisory. | New `lrgDlgHiddenThisTurn()` compares against `LRG_DLG_SVC_HIDDEN ∪ LRG_DLG_HOLD_HIDDEN` with the gate's own normalisation, dropping the line. `LRG_DLG_SVC_HIDDEN` is unset at the top of `lrgDlgApplyOffer()` so one turn's list cannot leak into the next. |
| S12 | low | `lrgDlgCheckKind()` recognises a bribe through `lrgIntentAmount()` (which understands words) while `lrgDlgNamedAmount()` — used by both the free check and `lrgDlgCheckRails()` to **price** it — was digits-only. "Here is two hundred gold…" yielded `result='ask'` every turn, and the "offered less than the price" rail was skipped entirely because it is guarded by `$named > 0`. | `lrgDlgNamedAmount()` delegates to `lrgIntentAmount($utter, false)` first (strict form), then falls through to the existing regexes, which still cover the money-word-less "I'll pay 300" case. |
| S13 | low | `lrgDlgNegatedAt()` scanned a flat six tokens back, crossing clause boundaries: in "Don't wait here, come with me instead." the leading *don't* negated both the wait verb and the recruit verb, so `lrgDlgFollowerNegatedOnly()` reported a pure refusal and the turn settled nothing. | New `lrgDlgClauseStarts()` marks clause boundaries **before** `lrgPromptNorm()` strips punctuation and returns token indices aligned with the token array; `lrgDlgNegatedAt()` takes them as an optional `$stops` argument and never walks past one. If the marked and plain tokenisations disagree it returns `[]` and the old flat window applies. All four call sites pass it. |

---

## 4. Where the implemented fix differs from the one proposed

Four deviations, each because the proposed form did not survive contact with the surrounding code.

1. **G4 — `return false`, not `return true`.** `StepReading` does `if !ReadList(n) : return` and then unconditionally `dlgState = ST_DECIDING`. Returning true after `HandBack()` would have overwritten `ST_MANUAL` with `ST_DECIDING` and carried on driving the scene. Returning false leaves MANUAL standing. The refresh of `inScene` still happens on the MANUAL path (so the other consumers see it) without a redundant hand-back.
2. **G6 — the pump, not an in-place re-arm.** Arming the new menu inside `OnMenuOpen` while `finishing` is true would run a fresh session on the menu-open stack while the old `Finish()` is still live on its own; when `Finish()` resumed it would call `ClearSession()` and `dlgState = ST_IDLE` and destroy the session that had just armed — and `DoUnhide()` would un-hide the menu the new `Arm()` had just hidden. The re-arm is therefore deferred to `OnLrgDlgPump`, which gets a fresh stack and runs only once `loopRunning` is really false.
3. **G2 — `ClearSession()`, not `ClearRequest(true)` from `Finish()`.** `ClearRequest(true)` also clears `openWanted` / `openNpc`, which belong to the pump rather than to the session; clearing them from `Finish()` risks silently dropping an open with no funcret. Clearing the three genuinely session-scoped flags in `ClearSession()` is the same fix with none of that exposure.
4. **S2 — keyed on the utterance, not on `utter.at > parked.at`.** The proposed timestamp test fails whenever the park and the next player turn land in the same second, which is exactly what the offline harness does (frozen clock) and is possible in game. The implemented test — a speech turn, with words different from the ones that parked it — is clock-independent and closes both reported cases (the spoken refusal and the silent poll).

Two smaller judgement calls worth recording:

* **S3 degrades to `mode=none`, not `mode=ask`.** Both refuse to execute, but `<her_list>`'s wording for `none` is exactly right here ("he has not named one of them — ask which he means; do not choose for him"), whereas `ask` makes her say "two of them could be meant", which is not what happened.
* **G3's belt was not added.** The proposal offered, as an alternative, having the server drop a pick with `tail == 1 && pos >= sent`. Since the game-side fix makes those picks work correctly, adding the server drop would defeat it.

---

## 5. Tests added

18 new flow checks and 42 new unit checks, all of which fail against the pre-fix code.

* `tools/test_dialogue.php` — new **section 11**, covering S3 (six real "mention is not an order"
  sentences plus the two that must still execute), S4, S5, S6 (including the exact false-positive
  reply), S7, S9, S10, S11, S12 and S13. S12 is checked twice: once on the `lrg_dialogue.php`-only
  require graph section 9 pins, then again with `lrg_intent.php` loaded, which is the graph the check
  really runs in. The old assertion that a text under 12 characters yields an empty `txt` was replaced
  — it encoded the S9 bug.
* `tools/flows/scenarios/d26_commit.php` — four new blocks for S2: a spoken refusal does not release
  and drops the park; an `lrg_dlgtalk` poll executes nothing on either route and leaves it parked; the
  same sentence repeated is not a second answer; and a real, different agreement still executes (so the
  three refusals cannot pass by simply breaking the release). Keys are resolved by entry text rather
  than by rank, and each block starts from a cleared park.
* `tools/flows/scenarios/d37_checks.php` — S8: a remembered pass grants no XP and no stat, emits no
  `do=award` on either route, stays that way on a third identical turn, and the bribe half (a repeated
  bribe never takes gold a second time).
* `tools/flows/scenarios/d56_services.php` — S1: on the same arrest layer that the post-LLM gate hands
  back, `want=1` emits nothing at `crit=0`, the log names the rail, and the resist row reached directly
  on the fast path is refused by its own rail.

---

## 6. Release gate

| Gate | Result |
|---|---|
| `tools/compile.ps1` | **OK** — 10 scripts, 0 errors, 0 warnings |
| `php -l` | **74 files, 0 errors**; **14 files, 0 errors** on the deployed copy |
| test_gates | 319 passed, 0 failed |
| test_intent | 190 passed, 0 failed |
| test_phrases | 14 passed, 0 failed (hit rate 91.9%, floor 82%) |
| test_scene_index | ALL CHECKS PASSED (607 scenes) |
| test_dialogue | **174 passed**, 0 failed (was 132) |
| test_services | 35 passed, 0 failed |
| test_latency | 26 passed, 0 failed |
| test_mcm_wiring | ALL CHECKS PASSED |
| test_prompt_index `--db` | **112 passed**, 0 failed |
| `run_flows.php --strict` | **74 scenarios, 74 passed, 0 failed, 0 pending; 1,179 checks, 0 warnings** (was 1,161) |
| `deploy_server.ps1` | 28 anchors (one match each), schema `lrg_index`, 37,561 prompts / 5,718 layers, service catalog rebuilt, scene index 607 scenes, **no lorerim_glue errors in any HerikaServer log** |

**Install.** `install_mo2.ps1` refused — Mod Organizer was running — which is correct. Skyrim was not
running, so the documented fallback was used: only the glue's own files copied into
`F:\Modlists\LoreRim\mods\LoreRim Glue`, SHA-256 verified before and re-hashed after.
**24 files compared, 12 already identical, 12 copied and re-hashed, 0 mismatches** — all 10 `.pex`
(the compiler rewrites them all) plus `Source\Scripts\LRG_Dialogue.psc` and `LRG_DlgUI.psc`. No profile
file, no other mod, nothing under `SKSE\` or `meshes\`. MO2's left pane will still show `0.1.0` for the
mod: `meta.ini`'s version line is the installer's to write and the installer could not run.

**Shipped state unchanged.** `bMenuless = 0`, `bDlgDryRun = 1`, `bQuestColour = 0`. Nothing in this
pass turns anything on; G12 in particular is a correctness fix under a toggle that stays off.

---

## 7. Files touched

Game:
* `glue/game/LoreRimGlue/Source/Scripts/LRG_Dialogue.psc` — G1–G6, G8–G12
* `glue/game/LoreRimGlue/Source/Scripts/LRG_DlgUI.psc` — G7

Server:
* `glue/server/lorerim_glue/lib/lrg_dialogue.php` — S1–S7, S9–S13
* `glue/server/lorerim_glue/lib/lrg_speech.php` — S8

Tests:
* `glue/tools/test_dialogue.php`
* `glue/tools/flows/scenarios/d26_commit.php`
* `glue/tools/flows/scenarios/d37_checks.php`
* `glue/tools/flows/scenarios/d56_services.php`

Notes:
* `PLAYTEST9_NOTES.md` — new section 11, "Go-live review"
* `MORNING_SUMMARY.md` — section D, "The go-live review (09:30)"

---

## 8. Residual risk / what this pass did not do

* **None of this has been seen in Skyrim.** Everything above is offline-green and hash-verified on
  disk; the menuless path is still inert and nothing has driven a real Dialogue Menu.
* **G9 is a mitigation, not an answer.** `Utility.IsInMenuMode()` from inside a Dialogue Menu is still
  unmeasured on this install and still has no calibration gate row. The three service windows are now
  detected by name regardless of what it returns, but a *different* pausing menu over a driven session
  is still governed by `mmBase`.
* **`StepResponding`'s signal 3 was left on `mmBase`.** Signal 1 (`EntryCount() == 0`) always precedes
  a repopulate and covers the hand-off in practice; adding three natives to a 0.1 s poll that can run
  for 20 s was not justified. `rsOther` may therefore be empty on a hand-off close — diagnostic only.
* **S6's price-frame list includes `for`**, which is broad. A false *drop* is the old behaviour, so
  this can only be better than it was, but a sentence like "I searched for hours and found 300 gold"
  can still trip it.
* **S2's refusal list contains a bare `no`**, so "I have no gold, take me to Morthal" will refuse a
  confirmation rather than complete it. Deliberate: in this module a false refusal costs a question,
  and that is the stated bias throughout.
* **G3 raises what a single decision can reach.** Picks at positions 16+ are now executable. They are
  gated on a live text-prefix match and on `SelectAndVerify`, which are the same two checks the head
  entries get, and a tail pick with no `txt=` is still refused outright.
