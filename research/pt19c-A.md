# pt19c-A - Lane A (server gate and two-step), menuless questing v1.0

Implementer notes for `research/pt19-menuless-v1-spec.md` rev 2, Lane A (section 2.2 text authoritative), built to
`research/pt19c-interaction-model.md` (binding F-resolutions) and the capability-map gaps assigned to A (U1, U2, U3, U4,
U5, U7, U8). Nothing was compiled, deployed or installed; `LRG_Main.CurrentVersion` was not touched (Lane C's).
Every edited file has its pre-edit copy in `glue/.backup/pt19c-A/<basename>.bak`.

## 1. Files

| file | change |
|---|---|
| `server/lorerim_glue/lib/lrg_dialogue.php` | 4979 -> 6225 lines. 47 functions added, 6 removed (list in 2.9) |
| `server/lorerim_glue/lib/lrg_prompt_index.php` | `lrgPromptWords($norm, bool $strict = false)` (:193) + ONE new read-only `lrgPromptRowsForQuests(array $quests, int $cap)` (:336) with its `LRG_TEST_INDEX` seam. Index format, builder and hash untouched |
| `tools/test_dialogue.php` | 1891 -> 2760 lines: sections 4 / 8 / 14 updated, 15 deleted (retired rails), new v16-v26, v28, v29 |
| `tools/test_latency.php` | new section **6b** (the spec's "section 6": the shipped section 6 kept its number) + `require flows/dlg_adapter.php` |
| `tools/flows/scenarios/d21_contact.php` | d21 no-marker check, d22 `lrg_dlgtalk` always `handled`, new **d21c** (pre-LLM open, bridge, fast pick, mute in time / late, no OpenInventory while pending) |
| `tools/flows/scenarios/d26_commit.php` | `fxDlgCache(..., int $clicks = 1, bool $close = false)` (seeds `*install*` clicks_ok, leaves the session OPEN = "the list is on his screen"); d26 rewritten (explicit, paraphrase parks, bare yes, F27, tail veto, refusal, rechat, stale park dropped); new **d26b** (stage rail) |
| `tools/flows/scenarios/d50_wire05.php` | v1.0 wire: `sj/sq/sqj/drv/stopped` parse, an old `ev=open` still parses, `ev=calib reset=1`, `ev=result auto=1`, five-row `k=` and the old ten-row one; d51 replaced (initiative retired) |
| `tools/flows/scenarios/d53_mcm.php` | retired qi/qig block replaced; d55 rewritten (read-only session, scene rail, crit 2 hand-back) |
| `tools/flows/scenarios/d63_out_of_the_box.php` | defaults (bDlgDryRun 0, no [Calib] page, S10 keys), learning 3 of 4, MQ101 indexed row, Hulda ambient |
| `tools/flows/scenarios/d40_quests.php`, `d65_quest_entry.php` | backed up, NOT changed: both pass as they are (d65's v1.0 "entry only when the open is refused/impossible" half rides on Lane B's S2.3 condition) |
| `tools/flows/scenarios/d64_auto_calib.php` | deleted (backup kept) |

## 2. What changed, by spec section

### 2.1 S1.3 visible sessions (who drives, read-only, the twin)
- `lrgDlgOnEvent` (:988) `ev=open` parses `sj`, `sq`, `sqj`, `drv`, `stopped`, `quiet`; `sess.sj` = the game's `sj` OR (`scene=1` && `lrgDlgQuestIndexed(sq)` (:613)); old game (no `sq`/`sj`): `scene && !ambient`. `src=` of `sj` is logged. `lrgDlgSceneAmbient` (:590): the glob list is a SHORTCUT; a fresh `sqj=0` scene whose quest has no journal row in the index is ambient too.
- `lrgDlgReadOnlyWhy` (:1778) = `stopped | quiet | vis | drive_scene off | scene-unproven`; a read-only session emits nothing (log `gate: read-only session why=...`), the block says "choose it on the list yourself - I cannot pick for you here", `lrgDlgHandBackForeseen` (:2569) gives "choose that yourself" for crit 2 / arrest.
- The scene rail: `sj=1` needs `clicks_ok >= 1` and `session.drive_scene` (true).
- **F6** (binding): while a session is OPEN a scene never switches the module off (PrepareTurn :1910). With no open session the snapshot's scene flag decides (D-17) unless ambient.
- `lrgDlgDecide` (:3728) is the one decision; `lrgDlgWillEmit` (:4563) is its side-effect-free twin (`LRG_DLG_SILENT` mutes the log, no state write). test_dialogue v28 asserts the twin on all 31 T-key / LEAVE decisions of v16-v26.
- **P1** lost update: `lrgDlgFresh` (:720) re-reads the row; `lrgDlgSet` (:740) merges on a fresh read; `lrgDlgPostProcessActions` and the transformer read fresh before deciding.
- `lrgDlgSessionOpen` (:1765): an open session older than `session.open_seconds` (900, F23) is closed.

### 2.2 S2.1 / S2.2 the pre-LLM open (narrow marker)
- `lrgDlgBusinessMarker($t, $item, bool $narrow, ?array &$hit)` (:4193), five clauses in order, each logged by name (`open marker=<join|kind|root|toplevel|qrows> row=<info_key>`): 1 recruiter join ask; 2 a service KIND phrase (never a bare service word, never bare "a room"), guarded by `lrgDlgKindMayOpen` (:4298, U1: inn/barter only on a vendor, follower only on a follower, carriage/ferry/train only on a verified job faction - `open.kind_factions` is EMPTY until verified, crime never, never beside an active voice order F18); 3 her cached root >= 0.5 with the F16 short-containment floor; 4 a verbatim top-level prompt with >= 2 STRICT words; 5 her journal-quest rows (`lrgDlgQRows` :4276, one `lrgPromptRowsForQuests` read per NPC per session, cached as `qrows {sig,at,rows}`), >= 0.55 with >= 2 shared strict words, or (U5) an exact/contain line with >= 1.
- `lrgDlgPreOpen` (:2152) runs after the faction pass in PrepareTurn, only with `io` on, `ml == 1` (see 5.6), not quiet, no `open_refused` within 120 s (`lrgDlgOpenRefused` :1809 - the S2.3 read helper Lane B's factions use), no open session; at clicks_ok 0 only a clause-3/4/5 row that passes the stage rail opens (F19). Emits `do=open` (D2) with `ask=` and `amb=1` for an ambient actor, puts `open_pending {cid,at,clause,row}`.
- On that turn: the bridge line `lrgDlgBridgeLine` (:2447) REPLACES the state wording ("The list of what he can raise is being brought up now. Say ONE short line ..." <= 220 chars); OpenInventory / OpenInventory2 / RentRoom / HireCarriage are hidden while `open_pending` (ApplyOffer :2210) and the direct-barter net holds; `lrgDlgMaybeOpen` (:4152) never opens a second time; **F15** `lrgDlgOpenTurnHold` (:3623): the reply's key is HELD until the fast path answered (`want_none` for this cid -> gate normally), DROPPED when the fast pick for this cid already landed (`last_exec`); the transformer mutes her bridge only when that fast pick landed in time (fresh `last_exec`).
- `session.talk_again = false`: `lrg_dlgtalk` is answered `handled` pre-lock (OnTalk :1395).
- Measured (test_latency 6b, in-memory seam, 1808 rows, 300 journal rows): warm 0.73 ms avg / 1.47 max, cold 0.92 ms avg / 2.36 max per sentence. Postgres round trips are not in these numbers (clause 4 = the shipped exact-norm query; clause 5 = one query per NPC per session).

### 2.3 S3 the stage rail and the learning counter
- `clicks_ok` lives on the `*install*` row (`lrgDlgClicksOk` :1150); `ev=result auto=1` / a verified click raises it (OnResult :1218, `clicks_ok=` on the result log line); `ev=calib reset=1` clears it (OnCalib :1115). `lrgDlgStageRailBlocks` (:1796): at 0 only an INDEXED plain/back line with scripted 0, cost 0, kind '', goodbye 0 is clicked; the rest gets the label and, once, `lrgDlgStageRailLine` (:2580) - and the U7 honest sentence when NO key can pass ("I cannot pick any of these for you yet - choose this one yourself, this once").
- Retired: `lrgDlgCalibCandidate`, `lrgDlgCalibPick`, the `calib=1` branch, `lrgDlgInitiativeCandidate/Block/Gap` + `<she_may_raise>`, `lrgDlgScriptProxyHealth`, the rewalk depth (`rw`, `rwd`), the assisted and `x1`/`bi` rails, the LOST session state (OnClosed :1306 closes), `lrgDlgOnResume` body (logging stub :1159). `res=` is sent EMPTY (slot kept).

### 2.4 S4 the two-step
- **S4.1** hub rule: `lrgDlgHubFlags` (:1513) loops `links` once per layer; `lrgDlgIsCommit` (:1637) - the `commit: true` override is read in one line; walk-away and crit 1 are no commit (F20: walk-away is not a commit).
- **S4.2** leave guard: `lrgDlgLeaveGuarded` (:2601) -> `do=show kind=back` + "No line here backs out cleanly; leaving is his to do by hand, and it ends things with her - tell him so."; innkeeper layer -> `do=leave;pos=-1`; "Never mind." -> that entry.
- **S4.3** explicit (`lrgDlgExplicit` :2993): the player's own words through `lrgDlgUtterFor` (the NEW-utterance guard: `last_exec.at` + 30 s window / the cid, F7/F8, the price-list chain); >= 0.70, margin >= 0.25, `min(4, entry tokens)` (3 when 1.0), slot path >= 2 tokens; named choice (G5), first-word clip (G3), negation parity (G1 `lrgDlgNegationClash`); never lethal / crit 2 / arrest / >= 100 septims or >= 25 % purse / `never_auto` (F20 R12).
- **S4.4** bare yes: ONE assent list compared as a LEADING phrase over folded tokens (`lrgDlgLeadPhrase`, A1 apostrophes, A2 fillers, A3 tail veto `lrgDlgLeadVetoed` - "yeah no", "okay wait", "yes, if you pay me" assent to nothing); `ok` = `okay`; refusal first ("yes, but not now" un-parks); her question must NAME the choice (`named_score`), and a park written on a two-candidate-hint turn never releases on "yes"; F27 `lrgDlgBareYesLine` (:3940) appends the parked pick when the model chose no key. `lrgDlgParkOrRelease` (:4039) is pure and returns `release|park|unpark|still`.
- **S4.5** single entry (`lrgDlgSingleEntryRelease` :3042): refusal first (widened `lrgDlgIsBackOut($text, bool $spoken)`), the shape test, steps 0-5 with `lrgPromptWords($norm, strict: true)` (shipped list untouched for scoring), `single_entry_score/_exact/_precision`, the commit-single tail test, step 6's T-key rule, f1 = 1.0 on exact/containment. The single path never goes through the explicit margin (a one-entry layer has no sibling; fixed in `lrgDlgDecideEntry` :3769).
- **S4.6** auto-advance: `adv=<ms>` (continuer -> 0; never scripted / goodbye / commit / cost / crit 2) and `rearm=1` in the SAME reply after a question turn with no pick (`lrgDlgRearmLine` :3971).
- **S4.7** two candidates agree (`lrgDlgTwoCandidates` :2128) + the hint line "T<a> or T<b> fit what he said - ask which, naming both." **S4.8** a scripted entry needs f1 > 0 on the similarity path. **S4.9/S4.10** as the spec.
- `lrgDlgLayerIsOwnConfirmation` (:4102): yes-shape += i'm ready / i am ready / let's do it / i'm sure; no-shape += actually / i'm not sure / i need to think / not yet.
- NEW (small, beyond the letter): ANY emitted pick now drops a park of ANOTHER line (`lrgDlgApplyDecision` :3859, logged) - a park belongs to the layer it was written on; before, a stale park survived a click on the sibling (d26 (5) found it).

### 2.5 S5 service by kind
`lrgDlgKindPick` (:3167) runs ONLY after the matcher chose nothing (U4), picks the ONE real entry of that kind on a root list, `lrgDlgServiceSense` (:3188) vetoes a contradicted or ambiguous service sense ("what have you got against the Stormcloaks", two barter entries -> nothing; a follower list never). U2: an invisible-in-menu entry is graded scripted. U3: the days lists go through `lrgDlgDaysSlot` (:5225).

### 2.6 S9 keys (defaults in `lrgDlgDefaults`, lrg_dialogue.php:59)
`match.scripted_needs_word`, `match.service_kind_pick`, `match.hint_gap`; `confirm.{said_line_score, said_line_margin, said_line_tokens, slot_tokens, assent_words (one list), single_entry_extra, named_score, single_entry_score, single_entry_exact, single_entry_precision, utter_window, bare_yes, single_entry}`; `auto_advance.{enabled, grace_ms, rearm, continuer_words}`; `open.{narrow_marker, toplevel_marker, qrows_marker, qrows_score, qrows_cap, refused_seconds, kind_factions}`; `session.{talk_again, stage_rail, drive_scene, open_seconds}`; new kind phrases (inn / barter) and `not_after` words. Retired keys are gone from the defaults (test_dialogue 8 lists them). Test seams: `$GLOBALS['LRG_DLG_TEST_CFG']` (per-key override), `$GLOBALS['LRG_DLG_TEST_OVERRIDES']`.

### 2.7 S8 / U8 wire and index
`indexed=1` on entries; the index journal flag decides "quest scene" (U8); `adv=`, `rearm=1`, `amb=1` are additive AFTER `z=1`; `res=` empty.

### 2.8 prompt index
`lrgPromptWords` strict list = shipped + why how who where when which can could would should does mean now then wait uh they them these those there here we us he she him his her its our their + G7 fillers um er erm hmm ah mm hey. `lrgPromptRowsForQuests`: journal = 1 rows of her quests, top-level first, capped (<= 2000), seam first; SQL `... WHERE quest IN (...) AND journal = 1 AND norm <> '' ORDER BY toplevel DESC LIMIT cap`.

### 2.9 function list
Removed: lrgDlgCalibCandidate lrgDlgCalibPick lrgDlgInitiativeBlock lrgDlgInitiativeCandidate lrgDlgInitiativeGap lrgDlgScriptProxyHealth.
Added: lrgDlgAdvMs ApplyDecision Assent BareYesLine BridgeLine ClicksOk DaysSlot Decide DecideEntry EntryIsQuestion Explicit FoldTokens Fresh HubFlags IsQuestion KindMayOpen KindPick LeadPhrase LeadPhraseRaw LeadVetoed LeadsQuestion LeaveGuarded MatchPick NamedChoice NegationClash No OpenRefused OpenTurnHold ParkNamed PreOpen QRows QuestIndexed RailNote ReadOnlyWhy RearmLine Refuses RowFacts ServiceKindSaid ServiceSense SessionOpen SingleEntryOf SingleEntryRelease StageRailBlocks StageRailLine TwoCandidates UniqueWordTie UtterFor (all `lrgDlg*`).

## 3. What reviewers must check
1. The twin: `lrgDlgWillEmit` must stay a pure call of `lrgDlgDecide` + `lrgDlgOpenTurnHold` - no state write, no log (v28, 31 decisions).
2. F15 on the open turn: held / dropped / gated by `want_none` (v25, d21c) - and that the Phase 1 never-empty rail sees "no emit" on a held key (Lane B's F14 must exclude `adv` picks from `lrgNeLinesCarryPick`).
3. The stage rail at clicks_ok 0 lets through ONLY an indexed plain line (v24, d26b), and the pre-LLM open obeys the same rail on its predicted row (F19).
4. Explicit never fires on lethal / crit 2 / arrest / >= 100 septims / >= 25 % purse / never_auto / negation (v19).
5. The bare yes: vetoed tails, hint parks, rechat, same words (v20, d26).
6. `open_pending` hides exactly OpenInventory/OpenInventory2/RentRoom/HireCarriage and the barter net comes back after `open_refused` (v25).
7. `lrgDlgKindMayOpen`: carriage/ferry/train stay post-LLM until `open.kind_factions` is filled from a live snapshot (U1).
8. Quiet mode, buying, 10.15 direct barter, 10.20 escort/SFF, 10.23/10.25, 10.26: all their suites and flows pass (test_services 40/40, d29-d65 service flows other than the ones listed in 4.3).

## 4. Hand-offs to other lanes
### 4.1 Lane B
- `tools/test_gates.php:2505-2520` calls the retired `lrgDlgCalibCandidate()` -> PHP fatal (the whole file stops). Delete block (f) and the `$cand` check closing (g). With that call stubbed (scratch wrapper, not in the tree) the rest is **677 passed, 2 failed**: the calibration check itself and
- `test_gates.php:1971` (g) "an EMITTED pick with message ''": the hand-made `LRG_DLG_TURN` (no entries, no session, no `*install*` clicks_ok) - WillEmit is now the gate's exact twin (S1.3) and the real gate would emit nothing on that turn either. Build the turn through the real path (topics + turn, clicks_ok 1).
- `lrg_actions.php:1300-1301` still count the calibration "of 10" (test_mcm_wiring's last failure).
- F14: `lrgNeLinesCarryPick` must not count an `adv=` pick as "the game answers itself". F21: the config JSON lists must be complete supersets of the default lists above (assent_words, kind phrases, `not_after`). `open.kind_factions` names once verified. S2.3's changed condition in `lrgFacQuestPlan` (Tullius after Helgen: v26 accepts `marker=join` OR 10.26's queued entry until it lands). The S11 diet of `lrgDlgBusinessBlock` / `StaticGuidance` / `JsonTemplate` / `LockedFacts` / `TruthCheck` / `GroundTruth` is B's second pass on top of this tree; test_dialogue 13 (j) has the `[slot]` for it.
### 4.2 Lane C
test_mcm_wiring readers (the latest tree run shows only B's "of 10" left; an earlier run also had `iKeyPushToTalk`, `iKeyVanillaMenu`, `bQuestInitiative`, `iQuestInitiativeGap`, `qi=`/`qig=` in LRG_Profile.psc). d53's release gate is green when that file is.
### 4.3 Lane E (flows that are not A's; each fails for a v1.0 reason, not a regression)
| flow | cause | rebase |
|---|---|---|
| d23 | a cached (closed) root: the pre-LLM open fires `marker=root` and F15 holds the model's key | assert the open + the fast pick, or open the session first |
| d29, d56 "with no list known ... stay" | the kind clause opens pre-LLM and `open_pending` hides RentRoom / HireCarriage / OpenInventory (S2.1 "the dropped shortcut") | pick a phrase that is no kind, or assert the hide |
| d30b, d33s | F6: a `scene=1` topics message IS an open session; a scene never switches the module off while it is open (read-only instead) | assert read-only; the snapshot case needs `fxDlgCache(..., close: true)` |
| d31 (in d29_services.php) | the LOST state and the assisted rail are retired (S9); with 0 keys :167 then feeds `null` to fxDlgLlm (TypeError + the one PHP notice of the run) | delete / rewrite |
| d33 (in d32_mute.php), d44 | "with no session": `fxDlgCache` now leaves the session OPEN (the list on screen) | pass `close: true` (6th parameter) - checked: both pass that way |
| d57 | stage rail: `fxDlgPriceLayer` never seeds `*install*` clicks_ok, and a priced slot is no plain line | seed clicks_ok 1 in `fxDlgReset` or the helper - checked: d57 passes that way |
- Two DB calls outside PROTOCOL section 5 come from Phase 1 scenario `31_never_empty` (no index seam): clause 4's exact-norm read `SELECT * FROM lrg_index.lrg_prompt WHERE norm IN (...) LIMIT 400` (the shipped `lrgPromptRowsFor` shape) now runs pre-LLM on the player's words. The fake DB should serve it (empty) or the scenario set `LRG_TEST_INDEX`.
- Test 21b (the fixture's single beats through the fast path AND the gate) needs Lane E's fixture file; v21 covers every row of S4.5's verified table with its path (fast / T-key / park / nothing).
- FE.hulda.open "where can I get a drink" -> NO open: see 5.1.

## 5. Spec contradictions and decisions taken
1. **Clause 4 vs Jon's row.** The clause as written (a verbatim top-level row with >= 2 strict words) fires on "where can I get a drink" (Jon Battle-Born's `DialogueWhiterun` row, strict words `get, drink`), while the test row and FE.hulda.open say NO open (the spec's own reason, S0.2, is about clause 5's journal filter). Implemented the clause text; v25 asserts `toplevel` with the reason in a comment. If the owner wants the test row, a floor of 3 strict words does it (Nice inn = 5, Heard any rumors = 4, Tell me about Whiterun = 3; Where can I get a drink = 2, What have you got for sale = 2 - clause 2 covers that one).
2. **Clause 3 is wide at 0.5** ("unchanged from rev1"). Measured against an inn root: "tell me about the war" -> "Tell me about Whiterun." 0.783, "what do you think about the war" -> "What do you know about the Companions?" 0.588, "do you know Jarl Balgruuf" -> same 0.567 - each a visible false open on a WARM NPC. Not changed (spec). Proposal: >= 1 shared strict word outside {tell, about, know, think} on clause 3.
3. The bridge line says "he", not the player's name (with the name it passes 220 chars).
4. The explicit margin (0.25) is tight between similar siblings: "I will kill him for you." vs "I will spare him." is 0.217 even for the EXACT line, so d26's sibling is "Let him go free." (0.46). Real layers with near-twin lines park the exact line - by design of the margin, worth a playtest look.
5. F20 (binding) wins over the language brief's walk-away-as-commit rows.
6. `ml == 1` gate added to the pre-LLM open (not in the letter of S2.1): under the dry run or with the module off, 10.15's own direct net and her words answer, as shipped (the open would have suppressed direct barter with no driver to click).
7. The stage rail, the pre-LLM open and F6 change what several non-A flows assert (4.3) - intended by the spec.
8. test_latency: the spec's "section 6" is numbered 6b (section 6 already existed, 0.5.5 funcret cost).
9. test harness only: `v1Llm` (test_dialogue) now gates inside the player's own request - an `lrg_topics` sent in between made the gate think it was on the fast path (it echoed the line instead of returning it). Production is unaffected (the gate runs in the inputtext request).

## 6. Not done / partial
- G2 (STT compound fold, language brief only - not in the spec or the model): not implemented; "are you alright" vs `are you all right` stays blocked by S4.8.
- 21b through the fixture (Lane E's file). MS11 shape "executes only on the second statement" is not pinned by its own row.
- Tullius after Helgen join open depends on Lane B's S2.3 condition (the test accepts either outcome).
- d53 (MCM release gate) waits on B's "of 10" line.

## 7. Test results (WSL copy of the shared tree, 2026-09-24, `%TEMP%\lrg_test\pt19c-A\work`)
```
== test_dialogue ==   747 passed, 0 failed   ALL CHECKS PASSED
== test_latency ==    34 passed, 0 failed
  6b marker path: warm 0.726 ms avg / 1.471 ms max, cold 0.922 ms avg / 2.362 ms max (1808 rows)
== test_prompt_index == 85 passed, 0 failed
== test_intent == 459 passed   == test_phrases == 47 passed   == test_services == 40 passed   == test_scene_index == ALL CHECKS PASSED
== test_latency_prompt == MEAN per turn 2306 chars / 576 tokens (10 fixtures)
== test_mcm_wiring == 48 passed, 1 failed (lrg_actions.php:1300-1301 "of 10", Lane B)
== test_gates == PHP Fatal at test_gates.php:2507 (retired lrgDlgCalibCandidate, Lane B); stubbed: 677 passed, 2 failed (4.1)
== run_flows == 85 scenarios: 75 passed, 10 FAILED (d23 d29 d30b d31 d33 d33s d44 d56 d57 = 4.3; d53 = mcm gate)
   Lane A's own: d21 d21b d21c d22 d26 d26b d27 d28 d40 d41 d50 d51 d52 d54 d55 d63 d65 all PASS
```

## 8. Fix round 1 (pt19c-A fix 1, 2026-09-24): every reviewer problem, how it was resolved

Files: `lib/lrg_dialogue.php` (6236 -> 6674 lines), `lib/lrg_prompt_index.php` (strict list only), `tools/test_dialogue.php`
(new section **v30**, 139 new checks; v21 F16, v25, v26 updated), `tools/test_latency.php` (the q-row cache key),
`tools/flows/scenarios/d26_commit.php` (the label now says septims). Pre-fix copies: `glue/.backup/pt19c-A-fix1/`.
Nothing compiled, deployed or installed; `CurrentVersion` untouched.

### 8.1 The decision the lane asked for: clause 4 (the verbatim top-level line)
The spec's own test row wins ("where can I get a drink" -> NO open, FE.hulda.open). Clause 4 now needs
`open.toplevel_min_words` (3) strict words AND a line that at most `open.toplevel_max_topics` (5) distinct TOPICS carry, and
never fires on a companion. Measured on the live index (the reviewers' own probes, re-run): 24 of 50 everyday sentences to a
plain NPC opened before -> 0; the companion / escort / buying phrases 8 of 59 -> 0; every capability-map section 2 "top" row
still opens ("The dragon is dead." and "I was told you would have a weapon for me" open through clause 5 instead; "Heard any
rumors lately?" is 5 topics' line and still opens cold). Both numbers are config keys, so the owner can move them.

### 8.2 What changed, by finding (file lrg_dialogue.php unless named)
- **Clause 3 / the fast pick picked on frame words** (game 1, ai clause 3): `lrgDlgWordsCarry` (:2973). On the F1 / trigram
  tier his words must share a strict word outside the frame words (tell, about, know, think, like, want, need, got, get, ...)
  and reach S4.5's precision (0.75) or half of his subject words. Used by clause 3 and the fast path (on ask= only when ask= is
  his own sentence; the model's item of an open for awareness keeps the shipped floors). Probe: every wrong open / click of
  game 1 and ai "clause 3" is gone; every right one stays.
- **10.27 buying regressed** (game 2, architect P5): the pre-LLM open stands down on an active voice order (`lrgDlgPreOpen`
  :2237); PrepareTurn persists `mkt_order`; the fast path and the gate click no service line beside it.
- **U1 bypassed / escort** (game 3, usefulness U1, CHIM follower): a kind the guard refuses ends the marker; an escort order
  owns its sentence when 10.20 carries it (a stranger, an SFF companion, CHIM's ghost); an SFF / ghost companion never opens
  on the follower kind; a follower open (teammate / custom) hides that kind's CHIM codes while pending.
- **Wrong service** (game 5): `lrgDlgServiceSense` - another service kind in his words is `contradicted`.
- **Doomed open** (game 6): no pre-LLM open beyond `open.max_distance` (200, the game's fOpenDistance) or in combat.
- **Rail wording** (game 7, ai LOW a/b): the U7 line and the corner note say only what is true, no "her", no "any line".
- **Exact near-twin** (game 8): an EXACT hit needs margin > 0 only.
- **Containment fragments** (ai HIGH): containment counts only token-aligned; a one-token / no-meaning-word line is explicit
  only when said exactly; a question never stands for a statement line (explicit).
- **Consent** (ai HIGH, language 1/8/9, architect P3): the spoken back-out covers the STT deferrals ("I will think about it",
  "need some time", "have a question"); the tail veto reads later / first / after / if / though anywhere and ignores the
  assent idioms ("no problem", "sure, why not"); a question back never releases; a tail naming ANOTHER line, or an "I will /
  I do / I swear" sentence of its own, never releases; an unnamed park releases only on a tail that names the line; F27
  (`lrgDlgBareYesLine`) now asks the same park decision. Assent list += i suppose so, i guess so, let's go, no problem, no
  worries ("why not" stays off it: the language brief 1.1).
- **Continuers** (ai MEDIUM): a continuer releases only bare.
- **The re-arm** (ai MEDIUM, architect P1, language 4): only after no item / nothing matched / a shape refusal (the gate
  records it), never on leaving words, and with the breath's narrow refusal set ("I don't understand" re-arms).
- **Follower rails** (architect P2, CHIM MED): the dismiss / home commit (`fcommit`) is set when the list is decorated, so a
  T-key and the fast path park it too; the fast path resolves follower orders through the verb table and applies
  never_topics.
- **The twin** (architect P4): `lrgDlgDecideWords` (:4000) - the words path is decided inside `lrgDlgDecide`; WillEmit covers
  it (v28 now 34 decisions).
- **Step 0 on refusal-shaped lines** (language 3): content outranks the refusal regex when his words carry the line (all 3,271
  single-entry lines of the live index now release when said verbatim; before, 108 were refused); his bare refusal on such a
  line refuses nothing and the model decides (a commit parks).
- **Question shape** (language 5): unfolded tokens; a negated auxiliary asks only by inversion; + wait / hold / on lead-ins.
- **Clause 3 negation** (language 6): never an open on a refusal or against negation parity.
- **STT apostrophes** (language 10): the strict list drops the fold-only stems (ill, im, dont, ...; lrg_prompt_index.php).
- **G2** (language 11): `lrgDlgSttFold` (:2929), entry-driven, matching only; `[costs N septims]` labels.
- **U3** (usefulness): the days pick by position AND norm; the price-list chain is his own sentence for a scripted slot;
  svc.all names days from the live text.
- **U8** (usefulness LOW): `lrgDlgQuestIndexed` fails closed with no database.
- **Funcret** (CHIM HIGH): our own SelectTopic funcret builds no `<what_just_happened>` and marks nothing told.
- **Mute** (CHIM LOW): `last_click.lead` keeps her bridge muted after the next layer's auto-advance pick.
- **hide_in_session** on the open_pending turn (CHIM LOW); **TakeUpBusiness** off the enum on a read-only session (ai LOW b).
- **Lost-update test** (CHIM LOW): `$GLOBALS['LRG_DLG_TEST_STORE']`, a store seam apart from the request cache (v30 s).
- **JSON size** (code design 3): the journal-row cache lives in its own row (`lrgDlgQRowsKey`, '*qrows*<npc>').
- **Double enlistment** (code, cross-lane): the pre-LLM open stands down when 10.26's entry is queued for the sentence;
  v26 now asserts exactly one carrier with CW00A:0 on the facts line. Lane B's S2.3 still decides which of the two.

### 8.3 Answered, not changed
- ai LOW (a) "choose no key on the open_pending turn": F15 gates the model's key normally once the fast path answered this
  cid with nothing (`lrgDlgOpenTurnHold`, want_none) - the key is that turn's fallback, and the bridge line already says
  "settle nothing".
- "I am doing well" against "Well?" still releases: it is a whole-word containment, the same shape as the spec's verified
  "what contract?" -> "Contract?" and "I hate the Greybeards" -> "The Greybeards?".

### 8.4 Hand-offs
- **Lane B**: test_gates.php:2507 (the retired lrgDlgCalibCandidate - fatal; stubbed: 678 passed, 1 failed, the (g)
  fixture); lrg_actions.php:1300-1301 "of 10" (test_mcm_wiring 48/1, d53); `lrgFuncretVerdict` could answer our SelectTopic
  funcret `handled` (CHIM P11: saves a thrown-away prompt build per click); S2.3; F14; F21 (a JSON assent list must carry the
  new defaults); `lrgDlgLockedFacts` says "the only ones on her list" over the first 4 slots of a 7-slot days list.
- **Lane E**: unchanged set - d23 d29 d30b d31 d33 d33s d44 d56 d57 fail for the v1.0 reasons in 4.3 (d29 / d56 now also
  see EndConversation hidden on the open_pending turn).
- **Lane F**: new keys `open.toplevel_min_words`, `open.toplevel_max_topics`, `open.max_distance`; clause 4's rule in
  PROTOCOL 10.29.

### 8.5 Test results (WSL copy, `%TEMP%\lrg_test\pt19c-Afix\work`)
test_dialogue 890/0; test_latency 34/0 (6b warm 0.90 ms, cold 1.05 ms); test_prompt_index 85/0; test_intent 459/0;
test_phrases 47/0; test_services 40/0; test_scene_index all; test_mcm_wiring 48/1 (Lane B); test_gates fatal (Lane B;
stubbed 678/1, the same as before the fix); run_flows 85: 75 passed, the same 10 failing scenarios with the same failing
checks as before the fix (d53 = the mcm gate).

## 9. Fix round 2 (pt19c-A fix 2, 2026-09-24): both reviewer problems resolved

Files: `lib/lrg_dialogue.php` (6674 -> 6751 lines), `tools/test_dialogue.php` (890 -> 944 checks: v30 (a) updated, new
(a2), new rows in (h) and (i); v25's fixture rows carry the live topic editor ids), `tools/test_latency.php` and
`tools/flows/scenarios/d21_contact.php` (the inn row's live topic editor id). Pre-fix copies: `glue/.backup/pt19c-A-fix2/`.
Nothing compiled, deployed or installed; `CurrentVersion` untouched.

### 9.1 Clause 4 opened a plain NPC's menu on small talk (language review 7, game review 4) - decided: option (b)
Clause 4 (`lrgDlgBusinessMarker` :4681) now takes a verbatim top-level row only when HER list can carry it
(`lrgDlgRowIsHers` :4745): (1) the row's quest is in her `q`, unless it is a town's shared journal-free Dialogue quest
(DialogueWhiterun, DialogueRiften, DialogueCarriageSystem - spec reconciliation 2's own reason), or (2) the row's topic or
quest editor id names her (`lrgDlgEdidWords` :4757 splits on case and digits; `lrgDlgNameWord` :4767 takes the first name
word of >= 3 letters that is no title, article or place: "Whiterun Guard" -> guard). The reviewer's "conditions name her or
her faction" is not buildable: the index keeps `nconds` only (row keys: norm, pattern, txt, topic_key, info_key, topic,
quest, journal, toplevel, ... nconds, free, plugin, origin, shared - no conditions), and the index format is not this lane's
to change. The editor id is the per-NPC fact it has: the spec's own FE.hulda.open row is
`ACFDialogueWhiterunHuldaBranchChatTopic` (More to Say conditions by GetIsID, so no q test can see it). The topic cap
(`open.toplevel_max_topics` 5) now counts the topics HER list can carry. A refused row is logged:
`open npc=X pre-LLM: none - clause 4: <info_key> is <topic> (<quest>) - a top-level line her list cannot carry` (:2317).
Option (c) alone (cap 4) would have fixed only "do you need any help"; the other two are 2- and 1-topic lines.

Measured on the live index (`%TEMP%\lrg_test\pt19c-Afix2\probes\pu`, the reviewers' own probe harness; base = the tree
before this fix):
- the reviewers' three sentences on a townsperson, a guard and Hulda: 9 of 9 opened (3 at clicks_ok 0) -> 0; plus
  "tell me about Riften" (the carriage driver's `DialogueCarriageSystemLoreRiftenTopic`) to a townsperson -> 0.
- the usefulness / fix-1 capability sets (45 rows each): 33 -> 32 opens. The one lost: Hulda + "Heard any rumors lately?"
  cold (`DBRumorsTopic`, quest DarkBrotherhood - her innkeeper faction condition is invisible to the index); it still opens
  warm through clause 3 and post-LLM through the wide marker. Also no longer cold: "Where can I learn more about magic?" to
  an NPC outside MG01 (the capability map's "anyone, 26 variants").
- every other capability-map "top" row still opens cold, with realistic names and q: Corpulus, Sven, Vilod (named);
  Alvor / Gerdur (MQ102A/B in q); Farengar, Balgruuf, Arngeir, Delphine, Eorlund, Mirabelle, Brynjolf, Viarmo (q or named);
  a guard + the Grelod line (`ICQEGuardNoTopic`); Hulda + "Nice inn..." (`marker=toplevel`, FE.hulda.open). New cold opens
  that are right: "tell me about yourself" to Eorlund (`DialogueWhiterunEorlundTopicsBranch4Topic`).
- the 50 chat sentences (usefulness and fix-1 sets): 0 opens before and after.
- known residual: "what can you tell me about the jarl" said to Balgruuf himself opens (MQ102AJarlBalgruufTopic names him as
  its subject); his real list then sits open with nothing picked, the residual the spec already accepts for clause 4.

### 9.2 The widened back-out matched QUESTIONS (regression from fix 1) - fixed as the reviewer proposed
`lrgDlgIsBackOut` (:1649): "think about it" needs HIS deferring head ("I'll have to / I must / I'd like to / let me / I
should ... think about it | it over | on it"); "(have|got) a question" is replaced by the QUESTION PREAMBLE rule (:1655),
first person only ("I have / I've got / we have a (few|quick|more) question(s)", "can I ask you something"): when a question
follows it, the words WITHOUT the preamble are judged (so G6 applies: "hold on, I have a question, who are they?" asks; "no,
I have a question: who are they?" still refuses); alone ("I've got a question for you", "I do have a question first") it
defers as before. Measured (base -> fix): "what do you think about it?", "tell me what you think about it", "I have a
question, who are they?", "do you have a question for me?", TG00's "think about it, there's no point earning all that
gold..." (a language-brief R row that fix 1 refused), "I think about it every day", "you should think about it": backout 1
-> 0. "I must think it over", "we have a few questions", "can I ask you something?": 0 -> 1 (deferrals / announced questions).
Effects restored: the breath re-arms on them (S4.6), a park stays parked and a later "yes" releases it (F27), S4.5 step 0 no
longer refuses a single on them, clauses 3-5 no longer skip them.

Tests (v30): (h) 5 question rows re-arm, 7 deferral rows do not, 4 non-deferral rows, the refusal / G6 prefix row, TG00's
persuade single and "The Greybeards?" not refused at step 0; (i) a parked commit + "what do you think about it?" / "I have a
question, who are they?" stays parked and "yes" then releases it; "I've got a question for you" drops the park. (a2) 18 rows
of the three sentences on three NPCs at clicks_ok 1 and 0, 6 rows that must still open (q, name, guard), the shared-quest
row, the topic cap on her own topics, the two helpers. Mutation check on the WSL copy: restoring fix 1's two unanchored
alternatives fails 16 checks; switching the carry test off fails 23.

### 9.3 Hand-offs
- **Lane F** (PROTOCOL 10.29, clause 4): "a verbatim top-level row her list can carry - her quest (never a town's shared
  journal-free Dialogue quest) or an editor id that names her"; `open.toplevel_max_topics` counts her topics.
- **Lane B / E**: unchanged from 8.4.

### 9.4 Test results (WSL copy, `%TEMP%\lrg_test\pt19c-Afix2\work`)
test_dialogue 944/0; test_latency 34/0 (6b warm 0.91 ms avg, cold 1.00 ms avg); test_prompt_index 85/0; test_intent 459/0;
test_phrases 47/0; test_services 40/0; test_scene_index all; test_mcm_wiring 48/1 (Lane B "of 10"); test_gates fatal (Lane
B; stubbed 678/1, identical before and after); run_flows 85: 75 passed, the same 10 failing scenarios with the same failing
checks as before this fix (`fl-base.fails` vs `fl-work.fails`: only a random cid differs); Lane A's flows 17 of 18 (d53 =
the mcm gate).
