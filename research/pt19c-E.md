# pt19c-E - Lane E (the questline harness and the new flows), menuless questing v1.0

Implementer notes for `research/pt19-menuless-v1-spec.md` rev 2, Lane E (section 2.2 text authoritative), plus the **fixer round 1**
(section 9) and the **fixer round 2** (section 10: every reviewer problem of round 2, resolved or answered with evidence). Built and
re-run against the CURRENT tree on a fresh re-stage (2026-09-24 23:0x, `%TEMP%\lrg_test\pt19c-Efix2\work`). Nothing was compiled,
deployed or installed; `LRG_Main.CurrentVersion` was not touched; no file outside Lane E's was edited (the gate-B and mutation runs
used scratch copies only). Backups: `glue/.backup/pt19c-E/` (lane), `glue/.backup/pt19c-E-rev/` (before the reviewer's inline
edits), `glue/.backup/pt19c-E-fix1/` (before round 1), `glue/.backup/pt19c-E-fix2/` (before round 2: harness, fixture, d68, these notes).

## 1. Files (Lane E owns these only - spec 2.2)

| file | what |
|---|---|
| `tools/test_questline.php` | the harness of spec section 3 (3.1-3.7), the single-entry sweep, `--words`, `--stage=B`, `--first-evening`, and the maintenance modes `--remeasure`, `--scoped-merge`, `--explain`, `--dump`, `--allow-known` |
| `tools/fixtures/lrg_questline.json` | 104 beats, 848 paraphrases, 49 never lines, 141 `known` entries citing 7 shared `known_reasons`; schema in its `about` key |
| `tools/flows/scenarios/d66_visible.php` (id `d66v`) | the visible menu where Lanes A and C meet on the wire |
| `tools/flows/scenarios/d67_single_entry.php` | single-entry layers, "assignment waits" |
| `tools/flows/scenarios/d68_reward.php` | gate B's bounded bonus, every outcome pinned, and her WORDS judged by the never-false rail (PENDING while `checks.reward.enabled` is false) |
| `tools/flows/scenarios/d69_walkaway_leave.php` | the leave guard and F20 |
| `tools/test_prompt_index.php` (section **5d** only) | the fixture against THIS index: 14 checks (variants, root INFOs and chain steps pinned too) |

## 2. The harness - what it asserts (as of fixer round 2)

- **Layers from the index only (3.3).** closed = the layer line of `parent_info` (or the parent row whose links equal one); every
  line the layer line carries must be on the built list or be the other prompt of a linked topic already shown - never dropped.
  A linked topic whose INFOs carry DIFFERENT prompts shows the one its conditions allow: the fixture names it in `layer.variants`
  (`"-"` = hidden at this beat), with the plugin evidence in `variants_note`; unnamed, the builder refuses. single = the parent row's
  links -> one line; root = real top-level rows (a root entry may name its INFO).
- **One paraphrase (3.4).** Fast path (`fxDlgSay`, `fxAdvance(5)`, `lrg_topics want=1`) then, when nothing clicked, the LLM path with
  the target's key forced. A PARK is released by the owner's own assent, rotated per paraphrase (`yes`, `okay`, `yes, I'm sure`,
  `uh yeah sure`; `I swear` on the oaths) and carried alternately by the model's key and by a WORDS-ONLY reply, where the gate appends
  the parked pick itself (F27, mode `bare-yes`, WillEmit FALSE - the twin records it as not emitted). A pick on the target that carries
  an engine-check kind its own index row does not carry is a FAIL (`kind-lie`).
- **never lines (model 2.4).** Fast path: nothing - on an auto beat the breath may advance an unreleased continuation, EXCEPT after a
  refusal (step 0: "nothing, and no auto-advance"). LLM path: nothing on a commit; on every single-entry key a refusal clicks nothing
  and re-arms nothing, a shape refusal clicks nothing (it may re-arm); another line is never clicked.
- **Stage rail (3.5, model R8, capability map U7).** R8 is computed IN THE HARNESS from the live entry's grading (never by asking
  `lrgDlgStageRailBlocks`); a line R8 lets through must be picked on the target; the ONE rail sentence is pinned: it names exactly
  the keys that pass, or - where none passes - is the U7 sentence and never "ask me something simple first". Asserted on every
  beat's first line (cross), the unindexed probe, `rail` beats (FE.kodlak.rail, FE.hulda.trade.rail naming the Nice-inn key), and
  every line of the `rail0` beats (the Whiterun gate: note, persuade, bribe, intimidate).
- **Cross-cutting.** index hash + row count; LETHAL answers `do=show;kind=meta` (F3); the leave guard `do=show;kind=back`; the
  carriage slot; the WillEmit twin over every gate decision (950); the park-tail PROBE (S4.4 by design, printed, never a check);
  the single-entry sweep (576 lines).
- **Scene beats** (sj derived from the journal rows, U9) at clicks_ok 0 (read-only) and 1 (stage B); scene beats now carry the
  per-quest guard pair at clicks_ok 1 too (DB02 has one now: 15 quests; MQ101 and CW01B have no fast-clicking first line, printed).
- **The fail-safe merge.** Every entry (target AND siblings) graded above its own index row is printed (with `graded by <topic>'s
  row` when the risk comes from another topic); a failing paraphrase on such a beat prints what it does with the merge scoped;
  `--scoped-merge` measures the whole run that way. Scoped = for each line on the list, only the rows of that line's OWN TOPIC and
  lethal (crit 2) rows - the beat's quests are NOT exempt (round 2: a quest-wide scope hid MQ106's cross-topic borrowing).
- **Gate semantics.** The default run fails on ANY failure, `known` included, naming the owner (the release gate, spec 3.1 / 2.4);
  `--allow-known` reports known defects without failing ("NOT A RELEASE GREEN").
- **`--remeasure`** re-classes a failing paraphrase ONLY to `park` / `words` (spec 3.4 step 5); a line on a merge-affected beat, a
  `known` line, a click on another line, a stuck park, a kind-lie and a click where words were due are listed, never moved; a click
  on the target under another label is listed for a human; a beat's class is never moved. On today's fixture it proposes nothing
  (the proposal is byte-identical to the fixture).

## 3. The fixture

Paraphrases: every line of `research/pt19c-language-paraphrases.json` for its beat, plus the capability map's beats. 78 lines keep a
`spec_via` (a measured path that differs from the spec's) - each is a real floor miss (park / words, the number recorded) or a class
the index proves from the line's OWN row or its layer (the singles, MQ104.balgruuf.power, CW00B.galmar.join, MQ106.delphine.walkout
- its own row is scripted + goodbye, F20 -, MS05.viarmo.apply). None rests on the fail-safe merge's grading: with the merge scoped to
the topic (`--scoped-merge`) every one of the 78 takes its recorded path or fails only as its own non-merge known entry (16
@check_park); the one line that is ALSO known @merge (C00.kodlak.handle "can handle myself", park by its floor) resolves scoped.
Round 2 (section 10) removed the rest: the three beats round 1 had re-classed on the merge's grading (MQ102.balgruuf.intro,
MQ105.arngeir.summons, MQ106.delphine.mound) and Sven's never line assert the SPEC's row again - MQ106.delphine.mound's lines are known @merge (the goodbye
is borrowed from ANOTHER topic), the other 21 are known @spec_row (a spec-owner ruling): where the spec's row and the index disagree,
the spec's row fails red with its owner named.

## 4. The flows

- **d66v** (PASS): the explicit turn MUTED; the stage-rail turn SPOKEN with the sentence pinned (`I can only pick T2, T3`); ambient
  `sj=0` -> fast pick; journal scene read-only at 0, driven at 1; **first contact** now a real first contact: `fxReset` (the flow
  database kept step 1-2's session open), Kodlak's real name (the faction lane knows him as the recruiter), exactly one `do=open`
  queued with `open_pending` naming his turn's cid, then the list 6 s later -> `mode=explicit` (row 1, F8, 2.1); F1 drv=0; auto=1;
  the U1 escort row.
- **d67** (PASS), **d69** (PASS): unchanged.
- **d68** (PENDING in gate A): rewritten to pin every outcome - outside the window no check; a pass (Speech 100) gives exactly ONE
  award (one x across D1 and D2) of 0 < N <= 100 and her pre-LLM fact names that N; a second ask in the window: nothing, "The reward
  is fixed"; a failed check (Speech 5): nothing, "He did not move you", never "you add"; an empty purse: nothing, "You carry no
  coin... never promise"; the cap: a thousand asked of a purse of 40 -> 0 < N <= 40. (In round 1 it caught a double transfer - the
  post-gate's award line used a new x beside the pre-LLM D2 row, 200 septims for a promised 100; another lane's fix removed it.)
  **Round 2 - her WORDS are judged** (`fx68Judge`: CHIM's validator `lrgNeValidate`, which runs the never-false rail first, on the
  reply object the JSON connector decodes, after the ask turn and BEFORE the post-gate - production's order): after the FAILED check
  her reply is the reviewer's exact line "Very well - a hundred septims, from my own purse." - it must move no gold (no award, no
  GiveGoldTo: passes) and must be REJECTED before it is spoken (class reward, "no bonus was granted", logged `verdict=rejected`);
  the control "I'll add a hundred septims on top." must be rejected on the same turn; on the PASS her line naming N must pass the rail
  and a reply naming N + 100 must be rejected ("the bonus is N septims"). With gate B forced on in a scratch copy: **13/15 - the two
  words checks FAIL on the current tree** (the false promise and the wrong sum are spoken unjudged; finding 5.1 for Lane B). With
  Lane B's reward class widened in a scratch copy only (any money amount - `lrgMktPriceMentions` - OR the old giving-verb shape):
  15/15, so the flow is wired right and the red is the tree's.

## 5. Findings for other lanes (the fixture's `known`, 141 entries - 139 in the default run, 2 in `--first-evening`; each FAILS the gate with its owner named)

| reason | owner | lines | what |
|---|---|---|---|
| `@merge` | Lane A (or a spec-owner ruling on `lrgPromptEscalate`) | 96 (MQ102A.alvor.help 9, MQ102.irileth.news 10, MQ103.balgruuf.blocking 10, MQ106.delphine.go 10, **MQ106.delphine.mound 10** (round 2), C00.kodlak.handle 10, MG01.learn 10, DB01.aventus.ok 10, DB02.captive.who 10, TG00.brynjolf.chain06 6, FE.irileth.gate 1) | the merge borrows scripted / goodbye / walk-away / a PERSUADE kind from INFOs of OTHER topics: plain quest lines ask first, Brynjolf 06 stops advancing, Irileth's and Kodlak's lines are sent as persuade checks, Delphine's mound line borrows goodbye from MQ106KynegroveEntryA1 (0CA633 - same quest, another topic, not linked from parent 032906). **Every one of the 96 resolves with the merge scoped** to each line's own topic and lethal rows (`--scoped-merge`: 1110 passed, only the 24 non-merge Lane A entries and the 20 @spec_row entries left). MG01.learn moves through a SIBLING (Wuunferth's "Do you need any help in the magical arts?" row lends goodbye) - the overclaim detector reads siblings too |
| `@spec_row` | the SPEC OWNER (a ruling on the 3.6 / 3.7 row; then the fixture or Lane A) | 21 (MQ102.balgruuf.intro 10, MQ105.arngeir.summons 10, FE.riverwood.plain never 1) | round 2: the spec's row contradicts what the index proves and it is no floor miss, so spec 3.4 step 5 allows no re-class - the spec's row is asserted and fails red until the ruling (section 10.2 / 10.4) |
| `@check_park` | Lane A | 16 | a parked persuade / bribe / intimidate is never released by his assent: the >= 4-word check rail judges the one-word confirmation turn (a bribe >= 100 septims can never be done by voice) - incl. the Whiterun gate's "here, fifty septims if you let me through" |
| `@assent_sure` | Lane A | 3 | "yes, I'm sure" / "uh yeah sure" leave Rikke's and Galmar's parks stuck: the sibling "I'm not sure about this." puts `sure` in the tail-vs-sibling test |
| `@negation` | Lane A | 1 | "I don't want to join the Legion" clicks the Legion commit (lrgDlgNegationClash's whole-side precondition) |
| `@single_neg` | Lane A | 2 | "I'm not looking to apply to the college" / "I'm not here to apply" are auto-advanced (step 6 negation without `refused`) |
| `@filler_choice` | Lane A | 2 | "um, the sword" / "uh, the sword" ask first (lrgDlgNamedChoice's filler list lacks the A2/G7 fillers) |

**5.1 Lane B (gate B, not a harness `known` entry - d68 fails on it with `checks.reward.enabled` forced on).** The never-false rail's
`reward` class (`lib/lrg_replies.php:854-859`, `lrgNfClassify`) only sees a sentence with a GIVING verb from a closed list (I'll / I
will / I can add|give|pay|throw in|hand you|double, here's, take this, you'll get...) AND a bonus word (on top, extra, more, bonus,
additional, added, double, besides, as well). So after a FAILED check "Very well - a hundred septims, from my own purse." (neither),
"Here are a hundred septims for your trouble." ("here are" is not "here's"), and on a PASS of 100 "Very well - 200 septims, from my own
purse." are spoken unjudged - a false septims promise, against never-false and S6.2 (6). Probe on a gate-B scratch copy
(`%TEMP%\lrg_test\pt19c-Efix2\probe68.php`): `lrgNfJudgeSentence` returns null for all three; "I'll add a hundred septims on top." is
rejected (`never-false npc=Balgruuf Flowtest role=reward class=reward ... verdict=rejected`). No gold moves in any case (the post-gate
sends no award on words; hide_reward + lrgDlgTruthCheck hold). Fix for Lane B, proven in a scratch copy only: on the reward
pseudo-row, ALSO classify any money amount (`lrgMktPriceMentions($s)`, digits and number words) - d68 goes 15/15 with the union of
both shapes (the amount alone loses the control, which has no price frame).

## 6. Spec deviations, decisions, and what the plugins confirm

- **Plugin evidence (spec 3.7 [X], capability map U9 [I]) - DONE with a read-only probe** (`%TEMP%\lrg_test\pt19c-Efix\esp\esm_probe.py`,
  Python, reads Skyrim.esm / USSEP / USMP / AP / moretosaywhiterun.esp, writes nothing; the equivalent of `esp_dump.py`, which cannot
  target one INFO of a 250 MB master):
  - Sven: INFO 0BB965 lives under DIAL 0BB960 (`DialogueRiverwoodDragons` in Skyrim.esm; the winning USMP override renames it
    `DialogueRiverwoodSvenTiberSeptimTopic`), ONE condition `GetIsID(Sven) == 1` in both. His other root lines: 0BCCC6 (stores, alias
    Sven of DialogueRiverwood_Revised) and 0BCC9B (dragon, alias Sven, MQ102 < 110). `DialogueRiverwoodBackgroundTopic` ("What can
    you tell me about Riverwood?") is `GetIsID(Alvor)` (0BABE2) and `GetIsID(Gerdur)` (0FE2D3) - NOT Sven's, so the reviewer's inline
    addition of it to FE.riverwood.plain was reverted; on Sven's real three lines "sing me something about dragons" is a fast pick
    (s=0.610, margin 0.242; the spec's 0.098 needed the Riverwood line). Round 1 kept it as `pick` with `spec_via: never`; round 2
    restores the spec's NEVER line (3.4 step 5 never turns a never line into a pick) and it fails as known @spec_row until the spec
    owner rules (section 10.4). The index rows carry no conditions, so that the root is EXACTLY these three is a plugin fact the
    offline run cannot prove: Lane F confirms it in game (section 7).
  - Alvor: his quest scene is `MQ102HadvarAlvorScene` (SCEN 02BFAC), owned by **MQ102A** (actors Alvor, Dorthe, Hadvar, Sigrid); no
    MQ102 scene casts him or Gerdur (MQ102's scenes: Balgruuf / Irileth / Proventus). His help topic shows ONE INFO by stage: "Hadvar
    said you could help me out" (041F16 / 0C65A8, unscripted) at MQ102A stages 20-40, "Do you have any supplies I could take?"
    (02C44F, scripted) at 40-50 - so the two never share his list, and `sj=0` holds (MQ102A has no journal row). Lane F still reads
    `arming ... sq=MQ102A sqj=0 sj=0 drv=1` in the first evening's log.
  - Irileth: `MQ102IrilethIntroCW03` (098D55 / 098D56, the Tullius / Ulfric messages) needs `GetQuestRunning(CW03)` - hidden at the
    MQ102 beat (`variants: "-"`). Hulda's follow-up topic 000943: "I'm the Dragonborn." needs `GetStageDone(000242BA, 30)`, the
    refugee line (00095B) is the first evening's. Faralda's persuade topic shows 0B810C only at Speech >= SpeechVeryHard (or the
    amulet), else 0B810D (index conds). Galmar's `SkyrimIsHome` shows by race; `DGCrimeBribe` shows the Guild line to members only.
- Fresh world per paraphrase; the S4.3 new-utterance guard across sentences is the per-quest guard pair.
- LETHAL: the gate answers `do=show;kind=meta`; the fast path clicks nothing (no `show` on D1 in this tree) - "show on both paths"
  is asserted as show on the gate + nothing (or a meta show) on the fast path.
- The park-tail (a parked commit + "yes, <foreign words>") is a printed PROBE: S4.4 says the release does not test the tail. The
  design owner should decide whether an OATH must refuse a foreign tail (the build swears the Legion oath on "yes, long live Ulfric").
- The nine legacy flows Lanes A and B handed to "Lane E" (d23 d29 d30b d31 d33 d33s d44 d56 d57) are NOT rebased: spec 2.2 gives Lane
  E new files only ("E never edits A's files"). They need an owner before gate A.

## 7. What Lane F / the reviewers check

1. `php tools/test_questline.php` (the gate) and `--first-evening` fail today ONLY on the 141 `known` entries (139 + 2, owners
   named: Lane A 120, the spec owner 21); `--allow-known` must say "NOT A RELEASE GREEN: 139 known" (and 2 with `--first-evening`)
   and pass. `--scoped-merge --allow-known` must leave exactly 44: the 24 non-merge Lane A entries and the 20 @spec_row lines.
2. After any tree or index change: `--remeasure=<out>` and review (it moves only park / words).
3. The spec-owner questions: the @spec_row rows (MQ102.balgruuf.intro and MQ105.arngeir.summons in 3.6 - pick, or explicit / park
   because the target's own topic ends the talk on another INFO with the same prompt; FE.riverwood.plain's never line in 3.7), S4.4's
   oath tail, and whether `lrgPromptEscalate` is scoped to the topic.
4. Lane F, in game on the first evening (the offline index carries no conditions): Sven's Tab list in the Sleeping Giant at MQ102
   stage <= 30 is EXACTLY "Do you know any old ballads about dragons?", his stores line and his dragon line - a fourth line voids
   the FE.riverwood.plain measurements (re-run `--first-evening` with it in the root list); and Alvor's arming line
   `sq=MQ102A sqj=0 sj=0 drv=1`.
5. Lane B, before gate B: finding 5.1 (d68 is red on it with `checks.reward.enabled` forced on).

## 8. Test results (WSL, staged copy `%TEMP%\lrg_test\pt19c-Efix2\work`, fresh re-stage of the current tree, 2026-09-24 23:0x)

```
test_questline                       1015 passed, 139 failed (all KNOWN, owners named)  (4.8s)   RESULT: FAILED (the gate - Lane A + spec owner)
test_questline --allow-known         1015 passed, 0 failed, 139 known                            NOT A RELEASE GREEN
test_questline --first-evening         71 passed, 2 failed (KNOWN: FE.irileth.gate @merge, FE.riverwood.plain never @spec_row)  (0.8s)
test_questline --scoped-merge --allow-known  1110 passed, 0 failed, 44 known (24 non-merge Lane A + 20 @spec_row)
test_questline --words               resolve 540, nothing 252, WRONG 0
test_questline --stage=B               23 passed, 0 failed
test_questline --remeasure           0 re-classed (proposal identical to the fixture)
test_prompt_index                      99 passed, 0 failed (5d: 14)
run_flows --only=d66v,d67,d68,d69      3 passed, 1 pending (d68, gate B)
run_flows --only=d68, gate B forced    13/15 - the two never-false words checks FAIL (finding 5.1, Lane B); with Lane B's reward
                                       class widened in a scratch copy: 15/15
run_flows (all)                        89 scenarios: 79 passed, 9 FAILED (the nine legacy flows d23 d29 d30b d31 d33 d33s d44 d56
                                       d57), 1 pending (d68)
mutations (scratch copies, round 1)   no U7 branch -> 57+1 fail; rail off -> 86+8 fail; lethal kind=back -> 1 fail; no F27 append ->
                                      58 fail; a refusal no longer stops the breath -> 1 fail; a bad variant -> 5d + the beat fail
round 2                               the old quest-wide --scoped-merge scope -> MQ106.delphine.mound's 10 lines "still fail"
                                      (explicit / park); the topic scope -> all 10 "RESOLVES" (pick)
```

## 9. Fixer round 1 - every reviewer problem

**9.1 The fail-safe merge absorbed as expected behaviour** ([code] 4, [game] P1, [lang] "re-classed instead of known", [arch]
"fixture has taken on", [use] P3). Resolved. The spec's `via` / class is the assertion again on every merge-affected beat; each line
the merge moves is a `known` @merge entry (86); `qlRemeasure` refuses to re-class on those beats. Measured with `--scoped-merge`, all
86 resolve. Three beats do NOT resolve even with the merge scoped, and are re-classed with the index evidence instead (`spec_class`
kept, `class_note`): **MQ102.balgruuf.intro** (its own topic carries the prompt on five INFOs: 0DF024 goodbye, 0D50EB an Invisible
Continue line graded scripted), **MQ105.arngeir.summons** (own topic: 02F2C4 scripted + goodbye), **MQ106.delphine.mound** (own quest:
MQ106KynegroveEntryA1 0CA633 scripted + goodbye). Which INFO is on screen cannot be told from the text there, so the fail-safe grading
is the merge's documented case, not borrowing from another topic - the reviewers' premise does not hold for these three; the 3.6 rows
predate that evidence (spec owner). FE.irileth.gate now fails as a kind-lie (known @merge).
*Corrected in round 2 (section 10.1 / 10.2): this paragraph was wrong on MQ106.delphine.mound - 0CA633 is in ANOTHER topic
(MQ106KynegroveEntryA1, topic_key 0CA629) that parent 032906 does not link, so it IS borrowing, and round 1's quest-wide
`--scoped-merge` hid it; and re-classing Balgruuf's and Arngeir's beats without a ruling went beyond spec 3.4 step 5. All three now
assert the spec's pick (known @merge / @spec_row).*

**9.2 Balgruuf's "a word with you, jarl"** ([code] 1b vs [arch]). The architect is right: on the whole live list, target included,
the sibling "My Jarl, I seek an audience." is the better match (s=0.610 m=0.298) under any grading. Replaced (spec 3.4 step 5) by "I
need a word with you" (pick; known @merge under the full merge; resolves scoped); the known entry is gone.

**9.3 Lane A's check-park defect blocks the gate** ([code] 1a). Not a Lane E fix: 16 known @check_park, owner Lane A.

**9.4 esp_dump confirmation** ([code] 2, [use] P6). Done - section 6. The reviewer's inline Riverwood row was wrong and is reverted.

**9.5 Nine legacy flows** ([code] 3). Answered: spec 2.2 lines 1148-1153 give Lane E new files only; unchanged (79 / 9 / 1).

**9.6 Stale notes** ([code] 5). This file, rewritten.

**9.7 Rail sentence never asserted** ([game] P2, [use] P4b, [arch] stage rail). Resolved - section 2; d66v step 2 pinned too.

**9.8 Alvor's first click** ([game] P3, [use] P1). Resolved: `MQ102A.alvor.hadvar` (041F16 on his real root, pick at clicks_ok 0 AND
1, 10/10) and `FE.riverwood.alvor` (Path C1); the Hadvar sentence left the supplies beat.

**9.9 d68 cannot fail** ([game] P4). Resolved - section 4.

**9.10 The room in one sentence** ([game] P5, [use] P5). Resolved: `FE.hulda.room.chain` on Hulda's real rows - "I'd like a room for the
night" and "I need a bed for the night" -> the room line, then "1 day" at cost=25 with no new words at purse 300, and ASKS at purse 80
(F29). 4/4.

**9.11 S4.4 and the oath tail** ([game] design, [arch] park_tail). The check was already turned into a printed probe by the reviewer
and its known entries removed; kept so. Forwarded to the design owner (section 6).

**9.12 Negation, auto-advanced negation, assents, fillers** ([lang] HIGH / MEDIUM / MEDIUM / LOW). Resolved in the fixture and the
harness (never lines on CW00A.tullius.join and MS05.viarmo.apply; the assent rotation + F27 carrier; "um, the sword" back to explicit,
"uh, the sword" added); the lines that fail are known entries for Lane A (section 5). "I'm not here to join the Legion" passes.

**9.13 qlNever gaps** ([arch]). Resolved (section 2; mutation "a refusal no longer stops the breath" turns TG00.brynjolf.chain04 red).

**9.14 d66v step 5 not a first contact** ([arch]). Resolved (section 4).

**9.15 FE.hulda.open at clicks_ok 0** ([arch]). Resolved: passes `cold@0` / `warm@0` - "Nice inn..." opens (toplevel / root), "what
have you got?" and "I'd like a room" open NOTHING with `marker=kind` refused in the log (F19).

**9.16 LETHAL kind=meta, leave guard kind=back** ([arch]). Resolved (mutations prove both).

**9.17 DB02 guard pair** ([arch]). Resolved: scene beats carry the pair at clicks_ok 1 (`qlGuardEligible`).

**9.18 F27 untested** ([arch]). Resolved: every other park is released through a words-only reply; the twin records the appended
pick as not emitted (table 2.6); mutation "no F27 append" turns 58 red.

**9.19 The layer builder's INFO variants** ([use] P2). Resolved: `layer.variants` + the two-way layer-line check; FE.hulda.plain.followup
shows the refugee INFO, and the page's two sentences are asserted ("I'm just a traveler" on the traveler beat, the refugee sentence on
its own beat `FE.hulda.plain.refugee`). The new refusal found four more lists the builder had guessed (Irileth, Faralda, Galmar, the
DGCrime layer) - each named with its evidence (section 6).

**9.20 The Whiterun gate** ([use] P4a). Resolved: `MQ102.gate.bribe` ("Will this change your mind?" check; "fifty septims" words on
the BRIBE key; "here, fifty septims if you let me through" misses the explicit floor, s=0.242, so park -> known @check_park) and
`MQ102.gate.intimidate` (check x3); all gate beats `rail0` (U7 at clicks_ok 0).

## 10. Fixer round 2 - every reviewer problem

**10.1 MQ106.delphine.mound absorbed the merge's cross-topic over-grading** ([game] P1, [arch], [use] P3, [lang]). The reviewers are
right; round 1's "the risky versions are in their own topic or quest" was false here. Resolved: `expect` is the spec's `pick` again,
all 10 lines assert `via: pick` and each is a known **@merge** entry; `spec_class` / `class_note` are gone and `merge_note` carries the
evidence (target 0CA649, topic MQ106DelphineIntroEndA1, scripted=1 goodbye=0; the goodbye is borrowed from 0CA633, topic
MQ106KynegroveEntryA1, topic_key 0CA629, not linked from parent 032906). `--scoped-merge` is redefined to the reviewers' scope - each
line's OWN TOPIC plus lethal rows, the beat's quests NOT exempt (`qlSeam`, `tools/test_questline.php:670-689`); with it all 10 print
"with the merge scoped: pick - RESOLVES" (with the round-1 scope, run from the backup harness: "explicit/pick - still fails" x9 and
"park - still fails" x1). The whole run under the new scope: 1110 passed, 44 known - every @merge line of every beat resolves, so the
86 earlier @merge entries did not depend on the quest exemption. If Lane A scopes the merge to the topic, the gate goes green on
these 10 and the harness prints "known entry ... passes now - remove it".

**10.2 MQ102.balgruuf.intro and MQ105.arngeir.summons re-classed without a ruling** ([arch], [game], reviewers asked for known entries
or a ruling). The facts rebuttal holds (the risky INFOs are in the target's OWN topic: 0DF024 goodbye + 0D50EB invisible in
MQ102BalgruufIntroTopic; 02F2C4 goodbye + 02F2C6 walk-away in MQ105ArngeirIntroTopic, Arngeir's own row 0649B7 scripted=1; both stay
explicit / park under the topic scope - re-verified: they are the 20 @spec_row lines left in the `--scoped-merge` run). But Lane E
cannot rule for the spec owner, and 3.4 step 5 allows only park / words. Resolved as the reviewers asked: `stageB` is the spec's
`pick`, every line asserts `via: pick`, `spec_via` / `spec_class` / `class_note` are gone, `ruling_note` states the evidence and the
two ways out, each line's `note` records the build's measured path, and the 20 lines are known **@spec_row** (a new shared reason,
owner "spec owner (a ruling on the spec 3.6 / 3.7 row; then the fixture or Lane A)"). The release gate is therefore RED on them with
the spec owner named, not green on a contradiction of 3.6.

**10.3 d68: the false-promise case** ([game] P4, PARTIAL). Resolved - section 4 and finding 5.1. After the failed check (Speech 5) her
reply IS the reviewer's line "Very well - a hundred septims, from my own purse."; d68 now looks at her words through CHIM's own
validator (`fx68Judge` -> `lrgNeValidate('')` -> `lrgNfValidate()`, on the reply object with every schema key, before the post-gate)
and asserts: no award / no GiveGoldTo for it (passes), the sentence is REJECTED with class reward / "no bonus was granted" /
`never-false ... role=reward class=reward ... verdict=rejected` (FAILS on the current tree), and the control "I'll add a hundred
septims on top." is rejected on the same turn (passes - the rail runs, the sentence shape is what it misses). On the pass it also
asserts her line naming N passes the rail and a reply naming N + 100 is rejected (FAILS, same cause). Gate B forced, scratch copy:
13/15; with the reward class widened in a scratch copy (union of the old shape and any money amount): 15/15. The two reds are Lane B's
(`lib/lrg_replies.php:854-859`); d68 stays PENDING in gate A, so run_flows is unchanged (79 / 9 / 1).

**10.4 FE.riverwood.plain's never line re-classed to pick** ([game] LOW). Resolved: "sing me something about dragons" is out of `say`
and back in `never` (spec 3.7); it fails on Sven's real root (fast pick, s=0.610 m=0.242) and is known **@spec_row** with a
`ruling_note` (the spec's 0.098 margin needed a line that is not Sven's). The Lane F waiver the reviewer missed is recorded: section
7 item 4 (Sven's Tab list in game is exactly the three lines). The reviewer's check of the rest (0BB965 GetIsID Sven; the Riverwood
topic is Alvor's / Gerdur's; MQ102HadvarAlvorScene owned by MQ102A; 041F16 and 02C44F never on the list together) agrees with
section 6 - no change.

**10.5 Owned elsewhere** (no Lane E defect; recorded). The gate is red on 141 known entries: Lane A 120 (96 @merge, 16 @check_park,
3 @assent_sure, 2 @single_neg, 2 @filler_choice, 1 @negation) and the spec owner 21 (@spec_row). The nine legacy flows d23 d29 d30b
d31 d33 d33s d44 d56 d57 still fail and still have no owner - spec 2.2 (lines 1148-1153) gives Lane E new files only and "E never
edits A's files". The S4.4 oath tail ("yes, long live Ulfric" swears the Legion oath) stays a printed probe for the design owner.

**10.6 Docs** ([minor]). Section 8 said "9 FAILED (the legacy set of 6)" - it is nine, named there now. Sections 1-8 and 9.1 are
brought up to date (9.1 carries a correction note).
