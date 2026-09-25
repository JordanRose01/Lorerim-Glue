# pt19h-money: the MONEY FIXER

2026-09-25. Gaps owned: G13, G14, S6.1 (reward line only on a bargain), the STT sum root, lrgDlgMoneyFold ownership.
Nothing was deployed or installed. Backups: `glue/.backup/pt19h-money/*.bak`. Work folder: `%TEMP%\lrg_test\pt19h-money\`.

## What changed (every edit carries `[pt19h-money]`)

| file | function | change |
|---|---|---|
| lib/lrg_speech.php | NEW `lrgDlgCheckAskRail` (+ `lrgDlgPriceQuestion`, `lrgDlgCoinOffer`, `lrgDlgCheckProposal`) | G13: a question / price question / echo executes no engine check |
| lib/lrg_speech.php | NEW `lrgDlgProseCost` | G14: the price in the line's own words, or the sum SHE just named for a bare hand-over |
| lib/lrg_speech.php | NEW `lrgDlgSumReader` | loads lrg_intent.php where a sum is about to be read (the want=1 fast path never had it) |
| lib/lrg_speech.php | `lrgDlgMoneyFold` (ownership taken) | folds the money word, then delegates the NUMBER to the root `lrgIntentSumFold`; case kept when nothing moved |
| lib/lrg_speech.php | `lrgDlgRewardAsk`, NEW `lrgDlgRewardPaidAsk`, `lrgDlgRewardNotBargain` | S6.1: the measured leak and the measured missed bargain |
| lib/lrg_speech.php | `lrgDlgCheck` (free bribe) | a price question with a figure in it is no offer (result `ask`, nothing moves) |
| lib/lrg_intent.php | `lrgWordAmount`, `lrgIntentSumFold`, NEW `lrgIntentMultFold`, `LRG_MONEY_WORD`, one line of `lrgIntentClean` | STT sums: decimals, k, grand, "one fifty", vague quantifiers, "pieces" |
| lib/lrg_replies.php | `lrgNfVerdict` reward branch (gate B) | her bonus figure read in words and whole |
| lib/lrg_dialogue.php | ONE line in `lrgDlgCheckRails`, ONE line in `lrgDlgDecorateEntries` | hooks only; the logic lives in lrg_speech.php |
| tools/test_gates.php | section 42 `[pt19h-money]` | 16 checks, every measured example as a must-not-click or must-resolve row |

**Ownership note for the orchestrator.** My list named no lrg_dialogue.php function. G13 names the check rails, and G14 names the entry cost. So I added exactly two anchored hook lines:
- `lrgDlgCheckRails` (line "`// [pt19h-money / G13]`"), placed before the bribe price rail;
- `lrgDlgDecorateEntries` (line "`// [pt19h-money / G14]`"), placed after the index-cost fallback.

Both call functions in lrg_speech.php and are guarded by `function_exists`. I touched nothing else in the file.

## Gap status (evidence)

Measured with the measurer's own engine (`pt19h-measure/m_engine.php`) on two trees:
- **ctl** = the live tree with my edits removed;
- **work** = the live tree.

**Full run, all 2,324 beats (`an_full.py`):**
- 0 new clicks anywhere.
- 0 say lines lose a click.
- Exactly 4 entries changed cost or class: the 4 G14 evidence lines.

### G13 (a question executes a check on her key): CLOSED

The G13 probes are "how much would it cost?", "what if I refuse?", "how much?", "why should I?" and "Who is the priestess of Azura?", run on the check beats.

**Game form** (the fixture's `<bribe>` / `<persuade>` suffixes rewritten as the `(Bribe)` / `(Persuade)` tags the live menu shows):
- Before: 96 sentences executed a check, on 46 beats.
- After: 2 sentences on 2 beats. Both are **by design**: the bribe line is itself the price question.
  - 01E497 "What would it take to identify the buyer? (Bribe)"
  - 032D5D "What will it cost to change your mind? (Bribe)"

  There, the fixture's own say lines ("how much to change your mind", "what's your price for the name") are meant to execute it.

**The fixture's raw norm form:**
- Before: 146 sentences on 55 beats. After: 67 on 18.
- 65 of the 67 are on 16 lists whose entries carry a `<bribe>` / `<persuade>` / `<intimidate>` suffix. The harness cannot resolve those, so the kind is lost (class plain) and no check rail runs. No live menu text looks like that. The other 2 are the by-design lines above.

The COVERAGE evidence:
- Stig 0E4A2E after "how much would it cost": her key now gives do=none ("a price question is no bribe attempt").
- Skjor 00090F: none.
- Nelacar 02450E after "Who is the priestess of Azura?": none.
- 0D7689, 02455F and 097FCD after "what if I refuse?" / "how much would it cost?": none.

**Say lines kept:**
- Bribes asked as an offer: "Would this loosen your tongue?", "would some gold help?", "Perhaps this will jog your memory?", "what if I pay you to come to our aid".
- Proposals: "how about three thousand septims", "why not use me...".
- A rhetorical argument that says the line: "what good is all that gold if the dragons kill you all?" (canonical test_questline rows).
- A request: "can you spare a soldier".
- A statement, then a question: "the courier's life is in danger, where is he?".
- An STT-clipped line: "how I operate. Either do it my way...".
- The question-shaped bribe line said as itself: "Maybe I could rent the room?".

Echo questions of a check line ("a priestess of Azura sent me?", "wait, his life is in danger?") now execute nothing: 95 probe rows.

**COVERAGE's third item** ("a token-priced bribe asks once, as cost >= 100 does"): no code change. In game, the live text resolves `<BribeCost>` (`cost_live`, bamt), so cost >= 100 already makes the line a commit and she asks. Offline the token is unresolved, so this cannot be measured.

### G14 (a price outside a "(N gold)" tag): 5 of 6 CLOSED, 1 OPEN

`lrgDlgProseCost` reads the price from the line's words:

| info_key | line | cost after |
|---|---|---|
| 06F999 | "Here's the 500 gold." | 500 |
| 06F99A | "Here's the 300 gold." | 300 |
| 00080B | "Buy unusual gem for 1000 gold." | 1000 |
| 0126D7 | "It's 2,000 coins." | 2000 |

All four become class pay and commit. So:
- the ask at >= 100 septims runs (the fast path now parks "Here's the 500 gold.");
- the afford rail runs ("priced entry 1000 septims, the player has 500 - never clicked").

None of the measurer's 15 hand-reviewed non-payments gets a price: sell lines, Nazir's DB11 answers, price questions, spell offers. The full run shows no other line in the load order changed.

- **0C9A08** "Here's the gold you wanted.": the rejoin fine is closed **at runtime**. The fine is priced from Tolfdir's own last line within 120 s (`lrgDlgState` lines):
  - "Then, as I've said, 1000 gold will be required..." gives 1000;
  - "a donation of 250 should help" gives 250;
  - a line 10 minutes old gives 0.

  This is tested in test_gates 42(d). The offline engine has no subtitles, so it still shows cost 0 there.
- **0B038E** "Here's the gold." (Vex): **OPEN.**
  - No line on this install names the reparation. Her replies are "What have you got for me?" and "With gold. A lot of gold.", and the figure is UESP-only ("about 1,000").
  - I did not invent one. A wrong label would be false, and a wrong afford rail would refuse wrongly.
  - Even with a price, the S4.5 single-entry release lets "yes" / "okay" click a priced single. I verified this on base+mine: cost 1000, "yes" gives pick mode single (step 1 assent). That breaks S4.10's "cost >= 100 always asks". See handoff 1.

### S6.1 (the reward line ABSENT unless he bargains): CLOSED

- "The reward was posted in Riften." (the measured leak) now gives no line. A NARRATIVE rule covers it: the reward is the subject of a report participle, as in "a reward was offered for his head" and "I heard the bonus was paid out".
- "I expect to be paid well for this." (the measured miss) is now a bargain, through `lrgDlgRewardPaidAsk`: HIS want of pay.
- Kept as bargains: "my reward was too small", "the reward is not enough" and "I want the reward that was promised".
- The critic's idioms are all regression rows, and all are silent: "you don't have to pay me", "no need to pay me", "I'll do it for free", a third-person "negotiate" / "not enough", "are you paying attention", "more gold in the barrow", "a bit more".
- Totals: 46 ordinary sentences give none, and 20 bargains hit.

### The STT sum root: CLOSED

The measurer's four misreads are fixed in `lrgIntentAmount`, `lrgDlgNamedAmount` and the fold:
- "1.5 thousand septims": 1500. It read 5000, an overstated sum.
- "one fifty septims": 150 (was 51).
- "2k gold": 2000 (was 0).
- "two grand": 2000 (was 0).

Siblings, as they read now:
- "2.5k" 2500;
- "5 grand for the sword" 5000;
- "a grand" 1000, but "a grand hall" 0;
- "a fifty septim piece" 50 (was 51);
- "a few coins" 0 (was 3; see below);
- "Here are all three pieces of the Razor." 0 (was 3; see below).

On the "a few coins" case: the bribe rail had refused the say line "Maybe a few coins would help?" as "he offered 3".

On the Razor case: once the fast path had the reader, DA07's own turn-in line counted as a bargain. "pieces" now counts as coin only in "pieces of gold / silver / eight".

**Found and fixed: the fast path never had the reader.** lrg_topics (want=1: `lrgDlgAnswerWant`, then the engine bribe rail, then `lrgDlgNamedAmount`) runs with lrg_dialogue.php alone (preprocessing.php). So "two hundred sept ums" read 0 there, and the "offered less than the price" rail was skipped. `lrgDlgSumReader` now loads lrg_intent.php right before the rail prices a sum. That file is definitions only and guarded; the LLM path loads it already.

Also fixed, gate B's reward class (off in gate A). It read her figure with a digits-only regex. So "I'll add fifty septims on top" passed as TRUE whatever the check gave, and "1,000 septims" read 1.

### lrgDlgMoneyFold ownership: TAKEN

It is now one reader. Its own digit rule was what turned "1.5 thousand" into "1.five thousand".

## Tests (tails pasted)

**base+mine**: the green baseline tree, staged at the start, plus only my files and my two hook lines. This isolates my change from other fixers' half-finished edits.

```
=== test_gates        809 passed, 0 failed      (793 + 16 new [pt19h-money] checks)
=== test_dialogue     1019 passed, 0 failed
=== test_intent       470 passed, 0 failed
=== test_services     99 passed, 0 failed
=== test_phrases      47 passed, 0 failed
=== test_stt          60 passed, 0 failed
=== test_questline    1956 passed, 0 failed, 0 known  ALL CHECKS PASSED
=== --words           2194 passed, 0 failed, 0 known
=== --first-evening   222 passed, 0 failed, 0 known   ALL CHECKS PASSED
=== flows             89 scenarios: 88 passed, 0 FAILED, 1 pending (d68 gate B, by design)
```

**The live tree at 06:5x** (other fixers' edits included), with and without mine:

```
ctl  (live - mine):  test_gates 827/0   test_dialogue 1127/0   test_questline 1955 passed, 1 failed
work (live + mine):  test_gates 843/0   test_dialogue 1127/0   test_questline 1955 passed, 1 failed
                     test_intent 470/0  test_services 125/0  test_phrases 47/0  test_stt 60/0  flows 88/0/1 pending
```

The one questline failure is the same with and without my edits, so it is not mine: `MS05.viarmo.comein @clicks_ok 1: "and that's wear i come in" -> pick` (another lane's in-flight change).

Earlier, at about 06:30, the live tree fataled: `Call to undefined function lrgFacQeTurn()` at lrg_factions.php:779. That was another fixer mid-edit. It was gone by 06:5x.

## Handoffs (not mine to edit)

1. **`lrgDlgDecideEntry` / `lrgDlgSingleEntryRelease` (S4.5 vs S4.10).** A priced single entry (cost >= confirm.min_gold) is released by a bare assent. On base+mine, "Here's the gold." at cost 1000 clicks on "yes" / "okay" (step 1). S4.10 says a price >= 100 septims always asks.

   Suggested fix: in the single-entry block, when `(int) $e['cost'] >= confirm.min_gold` (or >= a quarter of the purse), skip the release and fall through to the two-step (park). That is the second half of G14 for 0B038E.
2. **`lrgDlgBargains`.** A named sum equal to the sum in the line's OWN text is the line, not a bargain. Dexion's "6000 gold, and they're yours." (012F50) said in words ("six thousand gold and they're yours") now reads 6000 on the fast path. So it is "no match" there, as the digits form already was on every path (her key still parks it). It is the only fast-path change of that kind in the full run.
3. **0B038E's figure.** If the owner confirms Vex's reparation is a fixed 1,000, the overrides file needs a `cost` key. `lrgDlgOverrideFor` has none today (G19's owner).
4. **Measurement.** The coverage fixture writes check lines as `... <bribe>` / `<persuade>`, so the harness drops their kind (class plain), and no check rail can run on them. Re-measure with the tag form, as I did in `sub_in_tag.json`, before calling those beats live.
5. **`tools/test_dialogue.php:216`** asserts `lrgDlgNamedAmount('for a hundred septims') === 0` ("not a number we invent").
   - It passes only because test_dialogue loads lrg_dialogue.php without lrg_intent.php. The LLM path has read 100 there since 0.5.1 S12.
   - It still passes: the reader is loaded lazily by the rail, not at include.
   - Its owner may want it to say 100.

## Files

- Notes: `research/pt19h-money.md`.
- Tools in `%TEMP%\lrg_test\pt19h-money\`:
  - `stage.sh`, `sync.sh`, `mkctl.sh`, `mkbm.sh`, `suites.sh`: trees and suites;
  - `run_sub.sh`, `an_sub.py`, `an_full.py`, `g13m.py`: engine runs and metrics;
  - `sub_in.json`, `sub_in_tag.json`: inputs;
  - `diff_*.txt`, `suites_bm.txt`: outputs.
