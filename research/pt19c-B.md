# pt19c-B - Lane B (words, truth, factions, speech - server), menuless questing v1.0

Implementer notes for `research/pt19-menuless-v1-spec.md` rev 2, Lane B (section 2.2 is the authority), built on Lane A's landed
tree (`research/pt19c-A.md`, fix rounds 1-2). Nothing was compiled, deployed or installed; `LRG_Main.CurrentVersion` was not
touched. Every edited file's pre-edit copy is in `glue/.backup/pt19c-B/<basename>.bak`.

## 1. Files

| file | change |
|---|---|
| `server/lorerim_glue/lib/lrg_factions.php` | S2.2 `lrgFacSayOnceClosed` (:1036) + the road rule first in `lrgFacRefusesOpen` (:991); S2.3 the one changed condition in `lrgFacQuestPlan` (:1235) + `lrgFacOpenImpossible` (:1338); new plan state `open-first` |
| `server/lorerim_glue/lib/lrg_actions.php` | S2.3 funcret writer `lrgDlgTopicFuncret` (:912, called from `lrgRecordResult` :831) + `LRG_DLG_OPEN_REFUSALS`; S7 `lrgDlgNoteRefusal` (:968), `lrgDlgRefusalCode` (:955), `LRG_DLG_REFUSAL_WORDS`; S7 mappings in `lrgVoicedWhy` (:1243) and its widened machinery filter; the learning line in `lrgVoicedDryWhy` (:1395) - no "of 10", no "glue" |
| `server/lorerim_glue/lib/lrg_speech.php` | S6.1 `checks.stakes.reward` words (+ `lrgDlgStakes` skips that row), `lrgDlgQuestTurn` (:455), `lrgDlgTurnPriced`, `lrgDlgRewardAsk` (:478), `lrgDlgRewardWindow` (:492), `lrgDlgRewardLine` (:513); gate B (inert): `checks.reward` {enabled false, 180, 500}, `lrgDlgRewardBargain` (:524), `lrgDlgRewardAmount` (:539), the window branch in `lrgDlgCheckKind` (:356), the reward path in `lrgDlgCheck` (:141), `<reward_talk>` = `lrgDlgRewardTalk` (:681); language brief 5.2-5.3: "let's haggle" on a quest turn with no live price is no barter check |
| `server/lorerim_glue/lib/lrg_replies.php` | F14 `lrgNeLinesCarryPick` (:224) ignores `adv=` picks; gate B (inert): the `reward` never-false pseudo-row (`lrgNfContext`, `lrgNfClassify`, `lrgNfVerdict`, floor lines) |
| `server/lorerim_glue/lib/lrg_dialogue.php` (SECOND PASS) | `lrgDlgStaticGuidance` (:2502) suppressed while `<business>` is present; `lrgDlgBusinessBlock` (:2632) `(+N more)` count only + the outcome rule when a kind entry is offered; `lrgDlgGroundTruth` (:2808) the stopped line, the plain refusal line, the gate-B award line; `lrgDlgResultWhy` (:2873); `lrgDlgLockedFacts` (:6539) the `reward` line + "the only ones" fix; `lrgDlgTruthCheck` (:6681) the reward backstop; `lrgDlgJsonTemplate` (:5025) default `business`; `lrgDlgApplyOffer` (:2364) `hide_reward`; defaults: `reorder_json_scope` business, `truth.classes` + reward, `hide_reward`, `entries.more_words` retired, `lrgDlgShortWords` deleted. Two one-line cross-lane hooks, see 4. |
| `server/lorerim_glue/config/lrg_config.default.json` | `dialogue`: `_v1_readme` + every S9 key with its code default (lists COMPLETE, F21); `_dialogue_readme` no longer says "the open stays refused" for ambient scenes |
| `server/lorerim_glue/config/lrg_dialogue_overrides.default.json` | NEW: both Oath4 rows `commit: true`; `APStartIntroDiaTopic`, `MessengerAlduinSkipImp`, `MessengerMQSkipImp` `never_auto: true` (capability map U11) |
| `tools/test_gates.php` | 36b (f) the retired `lrgDlgCalibCandidate` replaced (the fatal is gone); 36 (g) built through the real path; 35 learning wording; NEW 35v, 36v, 38v, 39, 40, 41 |
| `tools/test_services.php` | NEW section 8 (kind phrases, the owner's sentences, look-alikes, F21 superset, S9 values, retired keys, the overrides file) |
| `tools/test_dialogue.php` (Lane A's; only what the spec gives B) | the 13 (j) slot filled; NEW v40e (e2e voice gap) and v27 (gate B); three pre-v1.0 assertions of my functions updated: (j) scope default and order, (l) the ambient plan |

## 2. What changed, by spec item

**S2.1 clause 2 / S9 config.** The code defaults (Lane A) already carried S2.1's phrase lists, `not_after` strings, U12's `training in` / `train in`,
and had dropped `what's the news` / `any rumo(u)rs` from `inn.phrases` (U1). The JSON now carries them as COMPLETE lists (shipped order,
then the additions) together with `confirm.*` (assent_words incl. `ok`, `okay`, `sure thing`; `single_entry_extra` go on / carry on;
`utter_window` 30; `single_entry_precision` 0.75), `auto_advance.*`, `match.*`, `open.*` (toplevel/qrows markers, 0.55, 300, Lane A's
`toplevel_min_words`, `toplevel_max_topics`, `max_distance`, `refused_seconds`), `session.{talk_again false, stage_rail, drive_scene true}`
(the kill switch is now a visible config line), `truth.classes` + reward, `hide_reward`, `checks.stakes.reward`, `checks.reward`,
`reorder_json_scope: business`. None of section 4's retired keys was ever in the JSON; the code has none either (asserted).

**S2.2 the road rule for ANY open.** `lrgFacSayOnceClosed`: one loop over the faction rows; a row whose `closed` cell names this NPC
(her Say-Once greeting stands behind that road; `sayonce: false` in the cell opts out) and whose road `lrgFacRoad` reports `closed`
refuses every open, whatever he said. It runs FIRST in `lrgFacRefusesOpen`, before the join-ask test, so "what do you sell" to Rikke
before Helgen opens nothing. There is no `greet`/`flags.sayonce` cell in the rows today; the `closed` cell is the representation the
spec itself names ("already true for Tullius/Rikke through the Legion row's closed cell").

**S2.3 the open first, the click-free entry as the fallback.** `lrgFacQuestPlan`: "the driver cannot click" = ml=0, OR the entry is not on
her cached list, OR (ambient AND the open is impossible). An ambient actor with the open possible and the entry on her list OR no list
known yet -> `open-first` (a plan with no param: nothing is sent; `lrgFacRefusesOpen` / `lrgDlgPreOpen` read only `queued`, so the open
proceeds). `lrgFacOpenImpossible`: io off, `open_refused` within 120 s, and - beyond the letter, see 5.1 - no verified click yet.
The writer: the SelectTopic funcret used to end at `lrgRecordResult`'s first line; now `lrgDlgTopicFuncret` reads it (still `pass` to
CHIM, nothing voiced): `do=open` + Error with one of the OpenBlockedReason / FailOpen reasons (err= first, the Error text for an older
game) -> `open_refused {why, at}`; "that moment has passed", "OK: Noted." and every other close never set it (F4).

**S6.1 fixed by the engine.** `lrgDlgQuestTurn`: `<shared_business>` non-empty (a journal quest of hers in q) or a Phase 2 result
< 180 s old (a refusal note is not a result). On a quest turn:
- `hide_reward` (GiveGoldTo, SpawnGold, SpawnItem, GiveItemTo, TakeGoldFromPlayer) is merged into `LRG_DLG_SVC_HIDDEN` in
  `lrgDlgApplyOffer` (PrepareTurn time, before CHIM builds the enum - CHIM brief P2), so the existing hidden-this-turn rail drops a stray one;
- the `reward` locked line (the spec's text, 255 chars - the spec estimated ~190) rides AFTER the faction lines only when he bargains (`lrgDlgRewardAsk`) or
  `lrgDlgRewardWindow` is open;
- `lrgDlgTruthCheck`'s backstop (11 lines): a surviving GiveGoldTo / TakeGoldFromPlayer whose amount no live entry cost or confirmed fact
  (the purse excluded) carries - or with NO amount (CHIM brief P3) - returns claim `reward`; the existing caller drops the money
  actions and logs `truth npc= claim=reward said= fact=`; her words play.
The bargaining words are the language brief's 5.2 CONTIGUOUS token phrases (`lrgDlgPhraseHit`), not the spec's bare "more / extra /
on top": bare "more" hits real entries ("Tell me more about the Dragon War", "I'm ready for more training" - both asserted) and would
put "I cannot add septims" into Farengar's mouth unprompted, which S6.1 itself calls a certain bug report. Every spec word is still
covered as a phrase (deserve more, worth more, sweeten, bonus, in septims/gold/coin, pay me, "a hundred septims on top" via the named
sum). The list lives at `checks.stakes.reward` as S9 says, and `lrgDlgStakes` SKIPS that row (brief 5.2: as a stakes row it would move
"I need more information" to band 2 on every free check).

**S6.2 gate B, written and OFF.** `checks.reward.enabled = false`. Inside an open window (and only there) his bargaining words make
`lrgDlgCheckKind` return `persuade`; `lrgDlgCheck` runs it at stakes `reward` (band 2) with the live `sg=`, the amulet and the memory
as any check; an item / house / horse / title ask, a second bonus for the same quest (memory `reward|<quest>`) and an empty purse are
answered as fact with no check; `N = min(named or round5(cap/2), her purse gold=, one day of her wage, max_gold 500)`; a pass sets
`give` and `lrgDlgAwardLine` appends `give=<n>` after `z=1` (only when > 0 - the wire of every other award is unchanged); the funcret
"OK: gave <n> septims" is read back into `award_gave`; the next `<what_just_happened>` says "You gave <player> N septims on top of his
reward." and, on a short-fall, "You found you had only N septims to give." once. `<reward_talk>` (<= 350) replaces the check
directive for a reward check. The never-false `reward` pseudo-row judges "I'll add fifty septims on top" true only against this turn's
passed bonus. With the switch off every one of these returns [] / '' (asserted).

**S7 every failure is words.** `lrgVoicedWhy` maps the driver's reasons: the entry moved -> "I lost the thread - say that again"; the
click did not take -> "that did not take - choose it on the menu"; the list could not be read -> "I did not catch what we could talk
about - choose it on the menu yourself"; `choose that one on the list yourself` (kept verbatim); her voice not ready -> "give me a
moment"; interrupted -> "we were interrupted - ask me again"; a `stage rail` reason -> the S3.3 sentence; learning -> "that cannot be
taken up by voice yet - the next conversation of any kind settles it ..." (no count, no "glue" - test_mcm_wiring 11 green). The
machinery filter gains index / topic / session / calibrat* / park(ed) / rail / matcher / t-key / editorid (language brief 6.2).
`lrgDlgNoteRefusal($npc, $code)` stores the PLAIN sentence for afford / amount / words / retry / frozen into Phase 2's `last_result
{ok:0, why, code, refusal:1}`; `lrgDlgRefusalCode` classifies the gate's own `why` strings. `lrgDlgGroundTruth` tells it as
"Nothing came of it: <sentence>." once, tells ev=result's `why=unverified` as "nothing came of that" (never the log string), and adds
F2's stopped line once within 180 s ("The menu was left to <player> because a fight began / a scene began").

**S11 the diet.** `<real_business>` is SUPPRESSED whenever `<business>` is present (entries, or the open_pending bridge); its one unique
rule rides `<business>` as "Never say whether a persuasion, a threat or a bribe worked; you are told afterwards." only when an offered
entry has a kind. `(+N more)` is the count alone (the word bucket, `entries.more_words` and `lrgDlgShortWords` are deleted). The four
standing lines, the quoting rule, the kept `sent < n` rail, the leave-guard, stage-rail, bridge and read-only texts are Lane A's and
unchanged. Measured on the real path (13 j): a CLOSED 12-key layer with both kept rails and the bridge = **2,069 chars** (budget 2,500);
the root 8-key layer 1,841.

**F14.** `lrgNeLinesCarryPick` no longer counts a pick carrying `adv=` (adv=0 included - the transformer does not mute it either) as "the
game answers itself", so an empty message on an auto-advance / re-arm turn is re-asked or floored.

**Lane A hand-offs closed.** the test_gates fatal; the (g) never-empty fixture (built through ev=open + lrg_topics + PrepareTurn with
clicks_ok 1; its parked case now needs words that do not say the line - "I need work" verbatim IS the confirmation under S4.3);
"of 10"; F14; F21; S2.3; the S11 diet; "the only ones on her list" now says "on her list: a, b, c, d and N more" when the list is longer
than four.

## 3. What the reviewers must check

1. `lrgFacQuestPlan` `open-first` never leaves an ambient join ask with neither carrier: the open must be possible exactly when
   `lrgDlgPreOpen` would open on clause 1 (io, ml, no refusal, clicks_ok >= 1). PreOpen's other stand-downs (distance > 200, combat,
   quiet, a voice order, an escort) are transient and are NOT mirrored: on such a turn her words answer and the post-LLM
   `lrgDlgMaybeOpen` may still open. Decide whether `lrgFacOpenImpossible` should mirror them too (one call each).
2. `hide_reward` on EVERY quest turn: 180 s after any settled click at an NPC, GiveItemTo / TakeGoldFromPlayer are off her table too
   (the spec's definition of a quest turn). Buying (ExtCmdLRG_Buy), direct barter (OpenInventory) and escort are untouched.
3. The reward line's trigger list deviates from the spec's bare words on purpose (2, S6.1) - confirm the choice.
4. The two cross-lane one-liners in `lrg_dialogue.php` outside the named second-pass functions (4 below).
5. `lrgDlgNoteRefusal` overwrites `last_result`: the refusal note replaces an older untold result of the same NPC (the turn that
   refused has already told the old one - PrepareTurn runs first).
6. test 35v's closed list is parsed from `LRG_Dialogue.psc` (Lane C): a new literal Error: reason there must stay plain in `lrgVoicedWhy`.

## 4. Cross-lane edits (one line each, flagged in the code)

- `lrgDlgApplyDecision` (Lane A): on a `do=none` gate decision of a speech turn -> `lrgDlgNoteRefusal($npc, $d['why'])` (S7: the
  function has no other caller; codes that do not classify store nothing).
- `lrgDlgAwardLine` (Lane A): `+ ['give' => n]` after `z=1`, only when the check carries a positive `give` (S6.2 / S8, gate B).

## 5. Decisions beyond the letter, and not done

1. **`lrgFacOpenImpossible` adds "no click verified yet".** Lane A's pre-LLM open never opens on a join clause at clicks_ok 0 (F19);
   without this the S2.3 condition would say "open first" while no open can happen, and an ambient join ask on a fresh install would get
   neither the open nor 10.26's entry.
2. **The ambient no-list case.** The literal "the entry is not on her cached list" would queue 10.26 for every never-opened ambient
   actor and make "open first" unreachable (test 39 expects `open-first` with no refusal). Read as: with NO list known the open is how
   the list is learnt; with a list known that lacks the entry, 10.26 carries it. Non-ambient behaviour is unchanged.
3. **Test numbering.** test_gates already had a 38 (buying); the spec's 35 additions / 38 / F14 run as 35v / 38v / 36v; 39 / 40 / 41 as
   numbered. test_dialogue's gate-B test is v27; the e2e half of 40 is v40e.
4. **Not done:** `open.kind_factions` (capability map U1) stays EMPTY - no live snapshot of a driver, ferryman or trainer carries a
   `fac=` line in the logs I can read, and the rule is "until the names are verified". CHIM brief P11 (`lrgFuncretVerdict` answering our
   own SelectTopic funcret `handled`) is not in the spec and test_gates 24 pins `pass`; left as a recommendation. Language brief 5.1
   (`LRG_MONEY_WORD` + septum/septem spellings, "5 hundred gold" -> 100) lives in `lrg_intent.php`, not a Lane B file.

## 6. Hand-offs

- **Lane E (flows):** d23 gains two failures from S11 on top of its v1.0 rebase: "with a list known the static rules block IS injected,
  at character_bottom" and "the rules forbid announcing a check outcome" - with a list known `<real_business>` is suppressed and the
  outcome rule rides `<business>` only when an offered entry is a persuasion / threat / bribe. Every other failing flow is unchanged from
  Lane A's list (d29 d30b d31 d33 d33s d44 d56 d57); d53 now PASSES (the "of 10" line).
- **Lane F (PROTOCOL 10.29 / owner page):** the `_v1_readme` keys; `session.drive_scene` is the owner-visible kill switch in the JSON;
  the new `config/lrg_dialogue_overrides.default.json` (deployed by the /MIR copy); `open-first` in the 10.26 plan states; the reward
  line wording and that it rides only when he bargains; gate B's switch `checks.reward.enabled`; "Nothing came of it" sentences; the
  learning corner note no longer carries a count. The owner page should mark its kind sentences `mode kind (<kind>)` - test_services 8
  reads them from `OWNER_MENULESS_V1.md` once it exists.

## 7. Test results (WSL copy of the shared tree, `%TEMP%\lrg_test\pt19c-B\work`, 2026-09-24)

```
== test_gates ==        747 passed, 0 failed          (baseline: PHP Fatal at test_gates.php:2507)
== test_dialogue ==     969 passed, 0 failed  ALL CHECKS PASSED   (baseline 944/0)
   v13j the closed layer offers 12 keys; the business turn with the two kept rails and the bridge is <= 2,500 chars (2069)
== test_services ==     70 passed, 0 failed   ALL CHECKS PASSED   (baseline 40/0)
== test_mcm_wiring ==   49 passed, 0 failed   ALL CHECKS PASSED   (baseline 48/1, "of 10")
== test_intent == 459/0  == test_phrases == 47/0  == test_prompt_index == 85/0  == test_scene_index == ALL CHECKS PASSED
== test_latency == 34/0  == test_latency_prompt == MEAN per turn 2306 chars / 576 tokens (unchanged)
== run_flows == 85 scenarios: 76 passed, 9 FAILED (baseline 75/10: d53 now passes; d23 +2 S11 checks, see 6)
Mutation checks (throwaway copy): no open-first -> test_gates 1 fail; no hide_reward -> 1; reward line always -> 3; no road rule -> 1;
no NoteRefusal hook -> test_dialogue 2.
```

## 8. Fix round 1 (2026-09-24, Lane B fixer) - every reviewer problem, resolved or answered

Pre-fix copies: `glue/.backup/pt19c-B-fix1/<basename>.bak`. Nothing compiled, deployed or installed; CurrentVersion untouched.
Sections 2-5 above still describe the lane; where this section differs, this section is current.

| reviewer item | resolution (file) |
|---|---|
| game HIGH / ai 1 / lang P1 / use P1: the reward words fire on ordinary quest talk | `checks.stakes.reward` is now three tiers plus guards (`lrg_speech.php` `lrgDlgRewardAsk` / `lrgDlgRewardPhrase`, the same lists in the JSON): `words` fire alone; `weak` (a bit more, a little more, need more, want more, not enough, negotiate, pay me, for free ...) fire only with a `money` word elsewhere in the same clause, or when the phrase ENDS the clause (nothing after it, or exactly one `tails` entry) and no `not_with` word (think, tell, explain, nothing, agreed ...) is in it; `with_money` (up front, in advance, in return, in exchange, double the, rather have, instead, more than that ...) only with a money word. Bare `a title`, `it/that/one instead`, `name your price` are gone. A named sum counts only inside a `sum_frames` frame ("make it", "i want", "do it for", "on top", "septims more" ...). Apostrophes are folded ("whats in it for me"). Never when his words ARE a line on her live list (Eorlund's weapon choice). Measured: 0 of the 59 reviewer free-speech sentences hit, 66 of 66 bargains hit (the 5.1 probes, lang P2's misses, the STT forms); 15 of 833 Appendix A paraphrases hit, all MQ104 reward asks; 59 of 10,605 journal-quest index lines hit, all payment / reward talk except "That's not enough. I need to stop Miraak now." |
| lang P2: misses (extra, on top, pay more, reward me, STT) | covered, see above (`anything extra`, `any extra`, `something on top`, `pay more`, `reward me`, `a reward`, `better reward`, `are you paying`, `work for free`, apostrophe fold) |
| game HIGH: a service click makes a quest turn; the reward line rides Hulda's ale order and pushes market facts out | `lrgDlgQuestTurn`: the last_result branch counts only a result on a non-service line (`lrgDlgServiceResult`: class service / pay / back, a price, a service word or kind phrase, a back-out), unless that click moved a journal row. `lrgDlgLockedFacts`: the reward line is never added on a market turn (a market locked line, `lrgMktShowFacts`, a live price list, a priced line on her list) unless a reward window is open, and it now comes AFTER the market lines |
| game MEDIUM: "make it worth my while" becomes a bribe | `make it worth` removed from the bribe regex; `worth my while` is a reward phrase; on a quest turn with no live price, coin talk with no sum and no purpose phrase returns `''` (`lrgDlgCheckKind`) |
| game MEDIUM / lang P6: refusal sentences in the wrong person and gender | `LRG_DLG_REFUSAL_WORDS` in the block's voice: "{player} could not pay what that costs", "{player} offered less than it costs", "{player} did not say it in a whole sentence", "you have already refused that and nothing has changed", "things had just changed and you had to look again" (`lrgDlgRefusalSentence` fills the name) |
| game LOW: the stuck learning reason promises the next conversation | `lrgVoicedDryWhy`: a reason with "will not finish it" says "that cannot be taken up by voice for now - the menu itself still works and he can choose it there" |
| game LOW / ai 4 / use P3: open-first leaves the first join ask with neither carrier | `lrgFacOpenImpossible` runs PreOpen's own stand-downs with the same reads: fresh snapshot beyond `open.max_distance` ("too far apart"), fresh combat, quiet mode, an active voice order, an escort order. The plan then queues 10.26's entry on the FIRST ask |
| game LOW: "any rooms available", "got any wares" miss | `inn.phrases` += any rooms, rooms available, a room available; `barter.phrases` += any wares (each maps to one kind, test_services 8) |
| code LOW: the window ignores the `*Reward*` topic | `lrgDlgOnResult` keeps `topic` (and `eclass`) on the result (one line in Lane A's function); `lrgDlgRewardWindow` opens on `lrgGlob('*Reward*', topic)` |
| ai 2: reward line 255 chars, dropped with the task line on real faction NPCs | the line is 188 chars ("the reward is what the world gives: you cannot add septims, an item or a favour, or change it; if he bargains, say so and offer nothing; if a reward line is on his list, tell him to ask it"); `lrgDlgLockedBlock` skips a line that overflows instead of stopping (one token in Lane A's function). Hadvar (CWImperialFaction, 388-char faction line): faction + reward = 582-char body. On a 500-char line (Tullius, road closed) the reward line is skipped and the task line still rides |
| ai 3: gate B, reward locked line contradicts `<reward_talk>` | the reward locked line is not added when `check.reward` is set |
| ai 5 / lang P9: `at` never set live | `lrgDlgQuestRows` selects `localts AS at` (CHIM `_uquest` writes `time()`, the same clock as `lrgNow`). Clause (c) "the matter moved on" is gate B only (`lrgDlgRewardWindow($npc, true)` from `lrgDlgRewardBargain`): accepting her quest adds a row too, and in gate A the reward line would ride for 180 s after "I'll do it". Open for gate B: (c) still opens on an acceptance - decide before switching gate B on |
| ai 6: read-only list has no sent < n rail | the rail rides the read-only branch of `lrgDlgBusinessBlock` too |
| ai 7 / architect: "Nothing came of it: nothing came of that." | `lrgDlgResultWhy` returns '' for unverified / empty; the line is "Nothing came of it." |
| ai 8: quest turn follows the quest DISPLAY | `lrgDlgQuestTurn` reads journal rows for her q (`lrgDlgHasQuestRows`, bookkeeping patterns aside, one read per turn) |
| ai 9: prompt size of the first evening | measured in test_dialogue 13 (j): clicks_ok 0, a journal quest, the bridge, a bargain, 12 keys: dialogue prompt 2,058 chars (<= 2,500), locked body 318 (<= 600), 2,626 in all. S12 lists these as two budgets ("<= 2,500; locked <= 600"); the test asserts them separately and reports the sum |
| CHIM / architect BLOCKING: funcret writer never runs pre-lock | already fixed before this round: `lrgDlgTopicFuncret` requires `lrg_dialogue.php` itself for the two results it writes (lrg_actions.php:930 / :939); test_gates 39 runs it in a subprocess that loads only lrg_actions (+ lrg_core) and sees `open_refused` written |
| CHIM: truth backstop matches only `|command|` | `lrgDlgRewardAct` (lrg_speech.php) parses any channel (`confirmcommand`, `approvedcommand`) and reads `item` / `amount` from a JSON param, never the target's RefID |
| CHIM / use P2: `lrgDlgNoteRefusal` overwrites last_result | the note has its own key `refusal_note {why, code, at, told}`; `lrgDlgGroundTruth` tells it once within 180 s; last_result, the check verdict, the quest turn and the reward window are untouched |
| CHIM gate B: bonus promised pre-LLM, moved post-gate | `lrgDlgCheck` queues the award's D2 row pre-LLM on a passed reward check and keeps its x (`award_x`); `lrgDlgAwardLine` reuses that x and queues no second row |
| CHIM MINOR: lrg_replies.php header | corrected: CHIM's core closure and ACTION POST-FILTER run after the glue's last hook |
| lang P3: barter kind clicks the trade line on information questions | `barter.not_after` += on, about, to tell, to report, to show, in store, going on, for us, for him, for her, in common, that i need; phrases += what do you have / have you got on sale / on offer |
| lang P4 / use P4: train kind on questions about her and C00's own line | `train.phrases` are request frames (like / need / some / want training in, like / want / need to train in, can / could / will / would you train me, you to train me, please train me, train me in ...); bare `train me`, `training in`, `train in` removed; `train.not_after` more, about the/you/your/this/that/them/him/her/it. "So you're supposed to train me?" is no kind any more, so the verbatim C00 line reaches clauses 4/5 |
| lang P5: negated inn request becomes a Rent_Room directive | `lrgDlgPrepareTurn` sets `svc.kind` from `lrgDlgServiceKindSaid` (one token in Lane A's function); `inn.not_after` for the wounded / injured / sick, you two, to think |
| lang P7: gate B item regex refuses septim bargains | fires only when the house / horse / title / favour / item is the object of the ask |
| lang P8: "5 hundred gold" -> 100 | root cause is in `lrg_intent.php` (no lane owns it in v1.0) - HAND-OFF to the orchestrator. In-lane: an `amount` refusal whose words carry "<digit> hundred / thousand" or a damaged septim word is told as "the sum {player} named did not come through clearly - ask him plainly how many septims he means", never "offered less than it costs" |
| architect: F27 bare-yes pick counted as "the game answers itself" | `lrgNeLinesCarryPick` skips a pick whose x is last_exec with mode `bare-yes` (both hooks use it) |
| architect: road-rule log says "she was told to say so" | the log says "she was told the road is closed" only when a join ask is present, else "her own words answer" |
| use P5: U7 rail variant not scanned | test_gates 35v scans the no-key-passes sentence |

Cross-lane one-liners in Lane A's `lrg_dialogue.php` (each flagged in the code): `lrgDlgOnResult` keeps `topic` / `eclass`; `lrgDlgLockedBlock` `break` -> `continue`; `lrgDlgPrepareTurn` `lrgDlgServiceKind` -> `lrgDlgServiceKindSaid`; `lrgDlgAwardLine` reuses a pre-queued x (Lane B had already added give= there).

Hand-offs:
- Orchestrator: lang P8 (`lrg_intent.php` `LRG_MONEY_WORD` septum/septem spellings, a digit before `hundred`) has no owner in v1.0.
  Not done, spec-level: a NON-ambient recruiter whose join line is on her cached list stays `click-wins` when no session is open and the open cannot happen (distance / combat). S2.3 changes the ambient condition only; the same `lrgFacOpenImpossible` test could extend to that case.
- Lane E: d56 (6) "'Don't sell me anything.' (negated): no direct path and no window" now gets `svc.kind` '' (not barter with `direct_why` 'negated') because of lang P5. The behaviour holds (no direct path, no window); the check's `direct_why === 'negated'` term needs rewriting. d23's two S11 checks are still open from the first pass.
- Lane F (PROTOCOL 10.29): the reward tiers and their keys (`words`, `weak`, `with_money`, `money`, `not_with`, `tails`, `sum_frames`); the reward line's new wording and its market-turn rule; `refusal_note` and the five sentences; `at` on questlog rows (the "matter moved on" line is live now); the train / inn / barter phrase changes; gate B's pre-queued award.

Tests (WSL copy `%TEMP%\lrg_test\pt19c-Bfix1\work`; baseline = the same tree with this lane's nine files at their pre-fix state):
test_gates 785/0 (was 749/0); test_dialogue 974/0 (969/0); test_services 95/0 (70/0); test_mcm_wiring 49/0; test_intent 459/0; test_phrases 47/0; test_prompt_index 98/0; test_scene_index all pass; test_latency 34/0; test_latency_prompt mean 2,306 chars (unchanged); test_questline identical failure set to baseline (Lane E is changing it); flows 89 scenarios 79 passed / 9 failed, the same 9 as baseline, plus one check in the already-failing d56 (the Lane E hand-off above). Mutation checks on a throwaway copy, each caught: weak phrases as strong (2 failures), no distance stand-down (1), note overwriting last_result (2), truth backstop on |command| only (1), bare-yes carrying (1), locked block `break` (1), service click as quest (1), reward line beside market lines (1), unverified doubled (2), quest turn from the block (4), reward line beside a gate-B check (1), moved-on in gate A (1), third-person refusal (2), no rail on read-only (1), stuck learning promise (4), "make it worth" as a bribe (1), award not pre-queued (2), bare "train me" back (1), barter stops lost (2).

## 9. Fix round 2 (2026-09-24, Lane B fixer) - the reward line on ordinary quest talk, and lang P8

Pre-edit copies: `glue/.backup/pt19c-B-fix2/` (lrg_speech.php, lrg_config.default.json, test_gates.php, test_services.php, this
note). Files changed: `lib/lrg_speech.php`, `config/lrg_config.default.json`, `tools/test_gates.php`, `tools/test_services.php`.

**game HIGH / ai 1 / lang P1 / use P1 - the reward line must be absent unless he bargains.** Resolved. All 17 sentences the game
review measured on the real path (Farengar, MQ104 in q) now leave `<locked_facts>` without the reward line. Before the fix, all 17
put it there. I found 23 more of the same kind and they are fixed too: "The guards need more.", "I will double my efforts", "Do
you have any extra supplies?", "Is there anything on top of the mountain?", "They deserve a bigger funeral", "I need to find
fifty gold" and others. `checks.stakes.reward` is now five tiers, each read inside ONE clause. A clause is split at . ! ? ; : ,
... and "but". A comma or point between digits does not split, so "2,000 septims" stays one sum.
- `words`: a bargain on its own. The first round's weak entries were moved out of it.
- `first_person` (NEW; review item 4): 'deserve more / better', 'worth more', 'want / need / expect(ed) more (than)', 'hoping
  for (something) more', 'want extra', 'want twice as much'.
  - They count only after HIS subject (I / I'm / I've / I'll / I'd / we / we've), with at most four fillers between ("I think
    I", "I was", "I'm going to").
  - A negation is not a filler: "I don't deserve more" is no bargain, but "Don't I deserve more?" is one.
  - Never inside a relative clause: "there is nothing I want more".
  - Then only with a money word, or at the clause's end / before one tail.
  - Examples that are no longer bargains: "The people of Whiterun deserve better.", "Your life is worth more than that", "I
    expected more than a pile of bones", "I'll need more than that to go on".
- `ends` (NEW; review item 3): 'what do / will / would i get', and the 'extra' and 'on top' phrases. They count only at the
  clause's end, before one of `tails`, before one of `leads` (NEW: for, if, once, when, in return, in exchange, out of), or with
  a money word. They never count before to / from / at / there. "What do I get for killing the dragon?" and "what do I get from
  you" still count.
- `weak` (review item 1): now only a bit / little more, not enough, negotiate, for free, pay me, raise it, twice that, give me
  extra.
  - `not_with` is now matched as contiguous phrases and gains say, describe, elaborate, detail(s), go over, add, clarify, expand.
  - At the bare end of a QUESTION a weak phrase counts only when it is on `in_question` (NEW: pay me, negotiate, raise it,
    twice that, give me extra).
  - A clause is a question when it ends in "?", or opens with a wh-word, a be-verb, or an auxiliary followed by a subject.
  - "how about / what about / why not ..." is a proposal, so "How about a little more?" still counts.
- `with_money` (review items 2 and 4): 'anything more', 'something more', 'twice as much' and 'double my' count only with a money
  word.
- Named sum (the reviewer's "hundred gold ... framed by 'i want'"; `lrgDlgRewardSum`): a `sum_frames` phrase must sit right beside
  a number in one clause.
  - Before the sum, with at most three fillers between.
  - Or, for a frame that does not start with "I", after it ("a hundred septims on top", "two hundred septims and it's a deal").
  - "Fifty septims? How about a hundred?" still counts.
- Evidence:
  - Prompt index, 21,663 distinct player lines: 100 hits became 79, and nothing new hits. The 21 lost are 20 index lines whose
    "(N gold)" tag is a price ("I want to decorate the Armory (500 gold)", "I'll take it. (7500 gold)"), plus the bribe line
    "Seems like coin would be worth more to you."
  - The language brief's 959 paraphrases and extras: 17 hits before and 17 after, all MQ103 / MQ104 reward talk.
  - The 88 bargains all still hit: the spec's words, the language brief's 5.1 probes, the first round's list, and the forms
    the new rules must keep.
- test_gates 38v(c) adds two checks: a must-be-absent list of 40 sentences (all 17 reviewer sentences, plus siblings and question
  forms), and the 17 reviewer sentences on the real path. `$rwAsk` gains 26 bargains.
- Mutation checks each turn test_gates red: 'anything more' back in weak (2 failures), first-person rule off (3), question rule
  off (1), the ends guard off (2), sum adjacency off (2), the new not_with verbs removed (2).

**lang P8 - "5 hundred gold" read as 100, "sept ums" / "septem's" read as 0.** Only partly fixed inside the lane; the rest is
handed off.
- In-lane: `lrgDlgMoneyFold` (lrg_speech.php) folds the owner's STT before THIS lane reads a sum.
  - What it folds: a digit before hundred / thousand becomes its word ("5 hundred" -> "five hundred", so "5 hundred and fifty
    gold" = 550 and "25 hundred" = 2500), and septem's / sept ums / septum(s) / septem(s) / sep tims become "septims".
  - Where it applies: the free bribe check's amount (`lrgDlgCheck`), the bribe / reward reading in `lrgDlgCheckKind`, the reward
    bargain (`lrgDlgRewardAsk`, including the named sum) and gate B's bonus (`lrgDlgRewardAmount`).
  - test_gates 38v(c) checks it: "5 hundred gold" 500, "five hundred sept ums" 500, "a hundred septem's" 100, 550, 2000, 2500.
    It also checks that "I'll do it for 5 hundred sept ums" is a bargain and "here's 5 hundred sept ums, look the other way" is a
    bribe.
- Still live, and not Lane B's to fix (spec 2.2 names Lane B's `lrg_dialogue.php` functions; neither of these is one):
  - `LRG_MONEY_WORD` / `lrgIntentAmount` (lrg_intent.php:63, :301-330). Every other caller still reads the raw words, for example
    the intimacy / price pre-scan at lrg_intent.php:485 and :549.
  - The ENGINE bribe rail `lrgDlgCheckRails` (lrg_dialogue.php:4491, Lane A), which reads `lrgDlgNamedAmount($utter)` raw.
  - That rail's refusal is still told as "did not come through clearly" (lrg_actions.php:997), not as "offered less than it
    costs".
- HAND-OFF to the orchestrator: assign an owner for the root fix before release. The simplest root fix is for `LRG_MONEY_WORD`
  to take septum / septem spellings and for `lrgIntentAmount` to read a digit before hundred / thousand. Calling
  `lrgDlgMoneyFold` from `lrgDlgNamedAmount` would cover the engine rail as well.

Tests (WSL copy `%TEMP%\lrg_test\pt19c-Bfix2\work`; base = the shared tree before this round):
- test_gates 789/0 (base 785/0).
- test_dialogue 974/0; test_services 99/0 (base 95/0; the F21 list covers the four new keys); test_mcm_wiring 49/0; test_intent 459/0;
  test_phrases 47/0; test_prompt_index 99/0; test_scene_index all pass; test_latency 34/0; test_latency_prompt mean 2306 chars /
  576 tokens (unchanged).
- Flows: 89 scenarios, 79 passed, 9 failed, 1 pending (d68, gate B). The failures are d23, d29, d30b, d31, d33, d33s, d44, d56
  and d57, the same set with the same 1,596 checks as base.
- php -l is clean on every lib, root, test and scenario file, and the config JSON parses (UTF-8, no BOM, LF).

Hand-off to Lane F (PROTOCOL 10.29): document the tier keys as they are now. The five tiers are `words`, `first_person`, `ends`,
`weak` and `with_money`; the helper keys are `in_question`, `leads`, `money`, `not_with`, `tails` and `sum_frames`. The rules are
in the JSON `_v1_readme` and in the comment above the row in lrg_speech.php.
