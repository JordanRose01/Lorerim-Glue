# pt19 - final sign-off, both directors, v1.0 as built + v1.0.1 reward switch-on

2026-09-25. Combined GAME DESIGN DIRECTOR + AI DESIGN DIRECTOR, round 3 (the round cut off by the spend limit), read-only
review. Inputs: `research/pt19-oversight-game-r2.md` (R1-R6), `research/pt19-oversight-ai-r2.md` (R1-R5), spec
`research/pt19-menuless-v1-spec.md` revision 2 (the "REVISION 2" section, lines 16-110), the as-built tree under `glue/`,
`research/pt19c-final.md`. Probes: `/tmp/claude-0/signoff/p1.php` (the reward bargain through the real `lrgDlgCheckKind`
/ `lrgDlgCheck`) and `p2.php` (the never-false rail through the real `lrgNfJudgeSentence`), both bootstrapped exactly as
`glue/tools/test_dialogue.php` (in-memory index, fixed clock, no DB), config = the shipped default.

## 0. Verdicts

| scope | GAME DESIGN DIRECTOR | AI DESIGN DIRECTOR |
|---|---|---|
| v1.0 as built (the eleven round-2 changes) | **APPROVED WITH NOTES** | **APPROVED WITH NOTES** |
| v1.0.1 (`checks.reward.enabled` = true) | **NOT APPROVED** - defect D1 | **NOT APPROVED** - defects D1, D2 |

This is a sign-off of v1.0 and a refusal of v1.0.1 as it stands. Ship with `checks.reward.enabled: false`, which gives v1.0,
the approved build, until D1 is fixed and D2 is closed. D1 breaks a never-bend rule: a negation, a deferral, a hedge or a
plain question moves septims from her purse.

## 1. The eleven round-2 required changes

(a) = in spec revision 2 and (b) = in the as-built code and docs.

| # | change | (a) spec rev2 | (b) as built - evidence | verdict |
|---|---|---|---|---|
| game R1 | scene test = the owning quest has an unfinished objective; `scene=` unchanged; `sq= sqj=` on `ev=open` | yes (S1.1/S1.3/S2.2/S8) | `LRG_Dialogue.psc` `SceneBasis()` :516-557 (`EscortHasJournal`) is used by `Arm()` :1714, `OpenBlockedReason` :1242 and `SendFacts` :3722. `HasActiveJournalQuest` is still defined (:4143) but nothing calls it. The `ev=open` tail :3513 carries `sq= sqj=`. Server: `lrg_dialogue.php:1095-1098` `sj` = game OR `scene && lrgDlgQuestIndexed(sq)`. `lrgDlgSceneAmbient` :639 is fresh AND `sqj=0` AND (glob OR not indexed), and it fails closed when there is no DB (:676). | **MET** |
| game R2 | pre-LLM open on "no open session"; kind phrases; cold/warm harness | yes | Config `services.kinds.barter.phrases` += `what have you got`, `what do you have`...; `inn.phrases` += `need a bed`, `like a room`...; `not_after` guards += `against`, `to say`, `planned`, `in mind`, `for me`, `there`, `left`... `test_dialogue.php:2677-2705` v25 checks Hulda COLD -> `marker=toplevel` and WARM -> `marker=root`. | **MET** |
| game R3 | E-press on Hulda first, then the plain line; the page says why | yes | `OWNER_MENULESS_V1.md` steps (8) E-press + "She keeps her list in mind for half an hour" -> (9) "Nice inn..." + "If you waited longer than half an hour, press E on her again first". Note: the page now opens with a new-game run (Vilod, Riverwood), so the Hulda pair is steps 8-9, not 1-2. The order and the reason are intact. | **MET** |
| game R4 | "new utterance" is measured against the last CLICK plus a 30 s window, or its own open's cid | yes | `lrgDlgUtterFor` `lrg_dialogue.php:5998-6021`: `at > last_click.at` AND (fresh <= `confirm.utter_window` OR cid = the list's / `open_pending` cid). There is no `session.at` clause. | **MET** |
| game R5 | grace = continuous blank subtitle after a line was seen; progress timer is not an anchor; subtitles off -> no auto-advance | yes | `LRG_Dialogue.psc` :2790-2807 (`lineSeenAt > layerStart`, `blankSince`, `adv anchor: line seen at= blank since=`). `!subsOn` -> `AdvClose("adv off...")` :2786. Note: an `adv=0` CONTINUER with subtitles off settles on an unchanged `ProgressTimerId` (:2770). That is a settle test, not a start anchor. Acceptable. | **MET** |
| game R6 | assent is a LEADING phrase; refusal wins; fresh-start line | yes | `lrgDlgLeadPhrase` :4115 + `lrgDlgLeadVetoed` :4122 (negator / but / wait / deferral anywhere vetoes). `lrgDlgSingleEntryRelease` runs step 0 refusal before step 1. The page names Sven's "Do you know any old ballads about dragons?" (step 3, `FE.riverwood.plain`, `test_prompt_index.php:549`). | **MET** |
| ai R1 | scripted content test: precision, <=2-word rule, shape, commit T-key | yes (S4.5 six steps) | `lrgDlgSingleEntryRelease` `lrg_dialogue.php:5805-5968`: step 2 exact (precision on commit), step 4 `count($we) >= 3 && $shapeEq && score >= 0.65 && prec >= 0.75`, step 5 `<= 2` words + shape + shared. Commit with a tail: "nothing foreign". Several pt19h guards were added on top (quote qualm, substitutes, questions-same). | **MET** |
| ai R2 | two stop lists, one function; scoring keeps the old one | yes | `lrgPromptWords($norm, bool $strict = false)` `lrg_prompt_index.php:195`. The strict list is used only in the shared-word / precision / marker-count sites (`lrg_dialogue.php:5930`, :7546, :7579). | **MET** |
| ai R3 | narrow marker = the functions that exist, each logged by name | yes | `lrgDlgBusinessMarker` :7479: `join` :7501, `kind` :7507, `root` :7524/7532, `toplevel` :7560 (verbatim toplevel row, >= 3 strict words, `lrgDlgRowIsHers`, <= 5 topics), `qrows` :7574/7584 (per-session cache, >= 2 shared strict at 0.55, or exact/contain). Logged as `open marker=<clause>` :2711. It is tighter than the spec (3 words, not 2; her-row only), and v25 shows step 9 still fires cold. | **MET** |
| ai R4 | a re-armed advance anchors on the END of CHIM speech | yes | Server emits `rearm=1` (`lrg_dialogue.php:7173`, :7772). Game `reqRearm` branch `LRG_Dialogue.psc:2719-2752`: voice begun, then `isActorTalking==0` for 1.0 s + grace, and `NoteSpeech` restarts it. Logs `adv wait: her line` and `adv anchor: her line ended`. | **MET** |
| ai R5 | `ok` == `okay`, both on one list | yes | `confirm.assent_words` holds `ok`, `okay`, `o k`, `okey`. `single_entry_extra` = `go on`, `carry on` only. | **MET** |

Notes on v1.0 (none blocking):
- N1. The unused `HasActiveJournalQuest` (`LRG_Dialogue.psc:4143`) is dead code. Delete it when convenient.
- N2. The `CmdAward` comment "gate B - ships inert" (`LRG_Dialogue.psc:1315`) and the test title `test_gates.php:3218`
  "gate A: no reward bargain is ever taken up (the branch is inert)" are stale since v1.0.1. The test still passes, but
  only because its turn has no reward window, not because the switch is off.
- N3. `CmdAward` has no ceiling of its own on `give=`. It moves `min(give, her gold)`, so the 500 cap lives only on the
  server (`lrgDlgRewardAmount`). A game-side clamp, `if give > 500 give = 500`, would be cheap defence in depth.

## 2. v1.0.1 - is gate B safe to switch on?

What is right: the amount rules hold. `lrgDlgRewardAmount` (`lrg_speech.php:1542`) = min(named or round5(cap/2), purse from
the snapshot, one day's wage, `max_gold` 500), once per quest per NPC (`reward|<quest>` memory). `CmdAward` then moves
`min(give, npc.GetItemCount(gold))` NPC -> player with `RemoveItem(..., player)`: it never mints gold, and she can only give
what her own purse holds. A remembered pass pays nothing. The window (`lrgDlgRewardWindow` :1470) is tight: 180 s after a
settled click on a reward line, gold into his purse, or (gate B only) a moved-on quest of hers.

What is wrong is WHO counts as asking.

### D1 (blocking): a negation, a deferral, a hedge or an information question opens the reward check and pays out

`lrgDlgCheckKind` returns `persuade` whenever `lrgDlgRewardBargain` is non-empty (`lrg_speech.php:473`). That requires
`lrgDlgRewardAsk` != '', which is `lrgDlgRewardPhrase` (:1208). For the `words` tier ("a reward", "the reward", "bonus",
"more gold", "more money", ...), `lrgDlgRewardPhrase` applies NO shape test: the `if ($tier !== 'words')` block is skipped.
`lrgDlgRewardNotBargain` (:1300) only removes idioms, "for free" / "don't pay me" waivers, places, narratives and third
parties. Nothing tests a negation of HIS want, a deferral, a hedge or a question about the reward.
`lrgDlgCheck` (:208) then runs the check pre-LLM and, on a pass, queues `do=award ... give=N` itself (:385-389). No later
rail can stop it. On a fail she takes `checks.affinity_fail` (-2 affinity) off him for a polite decline.

Reproducer. `p1.php`, shipped config, a window open (a `last_result` 30 s old on "What about my reward?"), quest turn `q=[MQ104]`,
her purse 200, Speech 100:

| his words (the player's) | kind | ask phrase | result | queued |
|---|---|---|---|---|
| I don't want a reward, keep your gold | persuade | a reward | pass | `do=award;...;give=20` |
| No need for a reward, I was glad to help | persuade | a reward | pass | give=20 |
| That's all right, I don't need a bonus | persuade | a bonus | pass | give=20 |
| I'm not asking for more gold | persuade | more gold | pass | give=20 |
| I never wanted a reward | persuade | a reward | pass | give=20 |
| Thank you, the reward is more than enough | persuade | the reward | pass | give=20 |
| Keep the reward, give it to the orphans | persuade | the reward | pass | give=20 |
| I'll think about the reward later | persuade | the reward | pass | give=20 |
| Perhaps later I will ask for more gold | persuade | ask for more | pass | give=20 |
| Maybe I should ask for a bonus | persuade | a bonus | pass | give=20 |
| I am not sure I deserve more gold | persuade | more gold | pass | give=20 |
| Is there a reward for this? / What is the reward? | persuade | a reward / the reward | pass | give=20 |
| Did you already give me the reward? | persuade | the reward | pass | give=20 |

In game this happens at Balgruuf right after his reward line: "No need for a reward, I was glad to help" -> she hands over
septims (log: `check ... kind=persuade stakes=reward result=pass give=N`, then `GAME award ... gave=N`), and
`<reward_talk>` tells her to say so. A polite refusal of a reward is the most natural sentence in that window.

Not defects, by the spec's own design (S6.2 (2) names "can you sweeten it"): proposal-shaped questions such as "how about a
little more?", "can you sweeten the deal?" and "don't I deserve more gold for this?" are bargains, and they stay.

Required fix (small, all inside Lane B's `lrg_speech.php`), to apply before gate B goes on:
1. `lrgDlgRewardBargain` (not `lrgDlgRewardAsk`, whose gate-A job of riding the locked line is harmless) refuses when HIS
   clause holding the phrase:
   - carries a negator before it (`LRG_DLG_NEG`, "no need", "never"), or
   - carries a deferral (later, tomorrow, someday, first, after, maybe, perhaps, "I'll think about"), or
   - carries a sufficiency word ("enough", "more than enough", "keep the"), or
   - is a question that is not a proposal. `lrgDlgRewardQuestion` already knows the proposal openers (how about / what
     about / why not); add `can/could you` + sweeten/add/raise/double/pay, and `don't I deserve`.
2. Test rows: every sentence in the table above -> `lrgDlgCheckKind` == '' with the window open, next to the three v27 bargains
   that must still pass. Add a d68 beat: "No need for a reward, I was glad to help" -> no `do=award`, no affinity change.

### D2 (blocking for the ai director): the rail's reward detector still misses plain grant sentences

The reward pseudo-row (`lrg_replies.php:853-866`) flags a sentence only in three shapes:
- a giving verb + a bonus word, or
- a sum "from my own purse", or
- a sum right after an assent (the v1.0.1 addition catches the d68 sentence, which is good).

Anything else is not classified, so it passes as true. `p2.php`, no check passed on the turn:

| her words (the model's) | verdict, no pass | verdict, pass give=50 |
|---|---|---|
| I will give you two hundred septims. | none (passes) | none (the wrong amount passes) |
| Here are fifty septims. | none | none |
| Here's another fifty septims. | none | none |
| Take these hundred septims. | none | none |
| You'll have your hundred septims tomorrow. | none (a later-payment promise, which S6.2 forbids) | none |
| Very well - a hundred septims, from my own purse. | false (caught) | false (caught, wrong sum) |
| I'll add fifty septims on top. | false (caught) | true |

Required: on the reward row, a giving frame (I'll / I will / I can give|pay|hand, here's|here is|here are|here you go,
take this|these|it, you'll have|get|receive) followed by a sum or a money word within the sentence is a claim, with or without
a bonus word. A future frame ("you'll have ... tomorrow") is judged the same way, because she can never promise later
payment. Sentences that `lrgNfGuarded` treats as hedged ("could", "if", "not") stay unjudged, as for every other class.

Before gate B, when `reward` was not a rail row at all, these sentences were only steered by the locked line. With
`give=` live, the model now has a real grant to imitate, so the gap matters in v1.0.1.

## 3. Summary

- v1.0 as built: all eleven round-2 changes are in spec revision 2 and in the code and docs. Both directors approve with
  notes N1-N3.
- v1.0.1: the amount, purse and cap rules are sound, but gate B takes a "no, thank you" as a bargain and pays it (D1), and the
  rail misses plain grant sentences (D2). Keep `checks.reward.enabled: false` until both are fixed. Then re-run
  test_dialogue v27, test_gates and flow d68 with the new rows.

## 4. Resolution (the orchestrator, 2026-09-25, after this review)

Both defects are fixed on the tree before gate B ships; `checks.reward.enabled` stays true.

- **D1** - `lrgDlgRewardDeclines` (`lrg_speech.php`, read by `lrgDlgRewardBargain` before the window test): every sentence of
  the D1 table runs no check (test_dialogue v27 "[D1]": 14 declines -> '', 8 bargains -> persuade, among them "don't I deserve
  more gold for this?", "that's not enough, I want more gold for this", "how about a little more?" and a decline in another
  clause); flow d68 adds "No need for a reward, I was glad to help" at Balgruuf's reward line (Speech 100): no check, no
  do=award, no "you add".
- **D2** - the rail's reward pseudo-row (`lrgNfClassify`) now reads a giving frame + money as a claim with or without a bonus
  word, never money she denies; `lrgNfVerdict` judges a later payment false whatever the sum. Flow d68 "[D2]": "I will give
  you N+100 septims." / "Here are N+100 septims." / "You'll have your N septims tomorrow." rejected; "Here are N septims." /
  "Take these N septims." pass.
- Runs: test_dialogue, test_gates, flows (d68 18/18) and every questline mode green on a staged copy; PROTOCOL 10.29 addendum.
