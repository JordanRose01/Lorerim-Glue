# pt19h REACH fixer: G2, G4, G6, U1 and Nazir's turn-ins

2026-09-25. Role: the REACH fixer. The job is that his own words reach the line he plainly meant, and nothing else. Nothing was deployed or installed.

## Result in one table

The measurer's engine (`pt19h-measure/m_engine.php`) was re-run over the full `m_in.json`: 2,324 beats and 39,052 utterances. It was run on two trees:

- **isolated**: the measured 05:23 tree plus my change only;
- **live**: the shared tree at about 07:30, which includes the other pt19h fixers' in-progress edits.

The baseline is the measurer's own `m_out.json`, from the same engine, input and index (e756e311).

| gap | measurer's rows | baseline (05:23) | isolated tree | live tree |
|---|---|---|---|---|
| **G2** STT damage | 395 STT say lines | 0 click on the fast path, 395 nothing | **213** click the target; 16 she asks once; 1 show; 165 nothing | 203 / 20 / 1 / 171 |
| G2, all STT say lines | 4,316 | 2,948 fast target; 339 blocked by the precision rail | **3,264** fast target; **102** blocked by the rail | 3,196; 106 |
| **G4** work ask | 394 probe rows on 56 radiant beats | 8 of 56 beats start on the fast path; 4 wrong-line clicks; 388 nothing | **42 of 56** beats start; 294 target, 14 another work line, 29 she asks once, 57 nothing; **0 wrong-line clicks** | 43 of 56; 301 / 14 / 22 / 57 |
| **G6** follower verbs | 178 sentences | 178 swallowed by the follower arbiter | **105** click the quest line; 38 she asks once (commit lines); 35 nothing (matcher score or margin) | 107 / 36 / 35 |
| **Nazir** turn-ins | 103 say lines (DB02a.turnin.*) | 16 fast, all Ennodius | **95** fast; 8 nothing | 95 / 8 |
| **U1** open.kind_factions | measurer: already fixed | fixed | regression test added | same |

**Must-not rows.** These are `never`, `never_red`, `near`, `probe`, `not_target`, `q_still` and `ev`, compared with the baseline on the isolated tree. **0 new fast clicks on a `never` or `probe` row.**

- 6 `not_target`/`near` rows now click a sibling line that he said word for word (for example "I'm ready, let's go" clicks "I'm ready. Let's go." and "Wait here. I'll take care of them." clicks itself). The measurer does not count these ("he said another listed line word for word").
- 2 `never` rows now park, so she asks: "can you hold this for me" and "will you join me".

**Her T-key.** Only `say` rows changed. 38 went from park to explicit and 17 from park to single, because the homophone and join folds now reach `lrgDlgExplicit` and S4.5. Two went from explicit to park ("re porting in" and "i don't understand what co incidence"): the join makes an exact 2-token line, and the shipped explicit rule wants 3 tokens for an exact line. That outcome is safe (she asks).

**Attribution on the live tree.** I built the live tree with only my functions reverted and compared it with the live tree as it is. That removes the other fixers' effects. The protected-line clicks that remain attributable to me are the verbatim G6 rows above, plus one G11 echo on a question line: "wait, is there any College business I can assist with?" clicks that plain question line, which is the same line. The G4 work pick had also clicked Brelyna's and J'zargo's "What did you need help with?" on the coverage team's near-miss "do you need help". That is fixed: the "do you need help / need a hand" asks were dropped from the work phrases, and a test pins it.

## What changed, by gap

All edits are in `glue/server/lorerim_glue/lib/lrg_dialogue.php` and are marked `[pt19h-reach]`. They are in my own functions, plus one new block of functions placed after `lrgDlgWordsCarry`. Backups of all three touched files are in `glue/.backup/pt19h-reach/`.

### G2: STT damage to the one meaning word (`lrgDlgSttFold`, `lrgDlgWordsCarry`, new helpers)

Three repairs. Each is entry-driven: it works only against the line's own words. None of them changes a score, a floor or a margin.

1. **Aligned homophones** (`lrgDlgSttHomophones`, a step of the fold).
   - What it fixes: "i no that's why" → "know", "so we no his name" → "know", "i have know doubts" → "no", "who are ewe" → "you", "hoo are you" → "who", "what do we do know" → "now", "yes i'm shore" → "sure", "the piece council" → "peace", "i'm knot shore" → "not sure".
   - The rule: a word is replaced only where a line on this list has the meant word between the same neighbours. At the sentence ends, one neighbour is enough.
   - A word that is or becomes "no" or "not" always needs both neighbours. So "I... no." stays a refusal, "no, I know" stays as said, and "we no longer trust him" folds nothing.
2. **2-letter joins** in the fold. A 2-letter half may now join, but only into a line's exact word of 5 or more letters: "is ran" → Isran, "re turn" → return, "in side" → inside, "co incidence" → coincidence. A leading "no" never joins, so "no body" stays two words.
3. **In `lrgDlgWordsCarry`, the precision rail itself:**
   - (a) **Token-aligned containment.** When his words are the line with a word dropped, they now carry it. The condition is that his words cover at least 3/4 of the line's tokens, never short (F16). Example: "need to talk to you". Before, the F1 tier outscored containment and hid it. The other direction (the line inside his longer sentence, such as "wait, what do we do now?") stays the matcher's tier alone.
   - (b) **Bounded STT repair** (`lrgDlgSttRepair` + `lrgDlgReachEcho`). 1-3 of his words that the line lacks may be read as one meaning word of the line he did not say, when:
     - the first letter is the same (or only one letter differs);
     - the letter edit distance is at most a quarter of the word, or the metaphone codes are equal or one step apart (codes of 3 or more; two steps for a long name, codes of 6 or more);
     - it is never another form of the same word (rumor/rumors, escape/escaped).
   - **The repaired sentence must then BE the line** (equal, or the line with a word dropped). Number words count as the line's digits, so "two thousand coins" matches "2,000 coins".
   - The precision rail only ever reads a **split word joined back** ("great beards" → Greybeards, "mal oral" → Maluril). It never reads a one-word echo. This rule came from a measured regression: "do you have any rooms" read as "rumors" clicked Hulda's "Heard any rumors lately?". The v31 test in `test_dialogue.php` caught it.

**Still open for G2.** 165 of the 395 still do nothing. Grouped by cause:

- **102: the precision rail.** A one-word echo is deliberately not read ("what does this half to do with me", "I'll do"), or the line is an `<Alias=...>` line.
- **15 price rail, 10 check/meta rail, 6 price-list slot: by design.**
- **11: negation parity.** "i no where the blood horkers are", "i'm knot shore". `lrgDlgNegationClash` reads his raw sentence on the fast path, not the folded one. This is a hand-off, below.
- **11: single-entry refusal or shape (S4.5), not mine.** Examples: "so you want help me" for "So you won't help me?", and "is ran's at fort dawnguard".
- **8: matcher score or margin.**
- **2: alias lines.**

### G4: "got any work?" reaches the radiant start (`lrgDlgKindPick` hook, `lrgDlgWordsCarry`, `lrgDlgBusinessMarker`)

- **The work ask.** `lrgDlgReachWorkAsk` reads his words against `dialogue.reach.work.say` ("any work", "anything need doing", "put me to work", "can I help", "got a job", "a job for me" and so on). It never reads the ask when it is negated, a price question, a refusal or a deferral, or followed by "myself / it / for you / on / about".
- **Her work lines.** `lrgDlgReachWorkLine` finds them through `dialogue.reach.work.entry`: "What can I do to help?", "I'm looking for work. ...", "Is there any work to be done?", "Is there any College business I can assist with?", "I'm ready for some extra work.", "I heard you might need help.", "What's the next target?" and so on.
  - "get to work right away (takes 6 hours)" is deliberately NOT a work line. That is the Andrealphus job line, where a work ask would spend 6 hours.
- **The pick** (`lrgDlgReachPick`, called first thing in `lrgDlgKindPick`). This is the existing S5 kind-pick seat, so it runs only where the similarity path chose nothing, on the fast path and in the gate alike.
  - One work line on a root or closed layer is picked, with mode `kind`. A commit start (Vex, Erikur) therefore still needs his explicit sentence, so she asks.
  - Two work lines: nothing is picked, and she asks which.
  - His question that says a statement work line back ("I heard you're offering extra work?", "is it true that I'm looking for work") is never picked. That is the G11 echo the matcher already turned down.
- **The wrong-line clicks are gone.** In `lrgDlgWordsCarry`, a work ask no longer carries a non-work line when the only shared word is the work noun. "put me to work" no longer clicks "You take your work very seriously." or "So you're treated badly because of your work?".
- **The barter misclick stays gone.**
- **The pre-LLM open.** The narrow marker's root clause (and the qrows clause when no root is cached) opens her list for a work ask when her cached root carries exactly one work line.

**Still open for G4.** 57 rows on 8 beats do nothing. On 7 of those beats the target is not a work offer: the MGRitual "What else is there to be learned about ...?" lines, "I'd like to clear up the matter of my suspension.", Arniel's "How is your project coming along?" and "Have you unraveled...?". Her T-key covers them.

The 8th beat is DLC1RV05.start2, a single-entry layer. The single-entry path returns before the kind pick, and that path is not mine.

14 rows click another work line on the same list, and I treat that as correct:

- MGR21: "College business" is picked instead of Urag's special books.
- MGRitual05: "You look like you could use a hand." is picked.

### G6: follower verbs on a list with no follower entry (`lrgDlgFollowerArbitrate`)

When the list carries no follower entry and she is nobody's companion, the arbiter now returns `null` ("no follower order to arbitrate"). Ordinary matching then decides, with every floor and rail it has. Examples: "Ready. Let's go.", "let's go find him", "I'm ready, let's go get them", and COVERAGE's "let's go" (MQ301, which now asks because it is a commit).

- "Nobody's companion" is `lrgDlgCompanionOwner` over the turn's snapshot, else `lrgDlgSnapshotKv`.
- A companion's list keeps the old answer, which is nothing: CHIM's Follow/WaitHere and the escort carry her orders.
- A negated-only follower order still settles nothing.
- Lists that do carry follower entries are unchanged.

**Still open for G6.** 35 rows are the matcher's own misses, for example "why wait for me" against "Why were you waiting for me?" and "wait here" against "Both of you wait here for a moment.". The arbiter no longer swallows them, and her T-key picks.

**Risk to verify in game.** On a stranger in an engine scene, "Ready. Let's go." may now click the quest line AND trigger the 10.20 escort's follow (`lrgEscortPlan`), which is unchanged and not mine. Before this change, only the escort fired.

### Nazir's contract turn-ins (`lrgDlgReachReport`, via the same `lrgDlgReachPick`)

A statement that reports its target picks the ONE report line that names it. Examples: "Narfi has been dealt with", "the Narfi contract is done", "I finished the job on Narfi", "Narfi is taken care of", and STT "narfy", "bay tilled", "mal oral", "mar andrew joe", "her n". The report line is a line ending in `dialogue.reach.report.entry`, such as "Narfi is dead.". The "Tell me about Narfi." beside it is never picked.

It never fires on:

- a question ("is Narfi dead?");
- a negation outside the phrase ("Narfi isn't dead yet");
- a plan or a condition, via `not_with` ("Narfi will be dealt with", "once Narfi is dead");
- a refusal or a deferral.

Name matching: the name must be said exactly, joined exactly ("her n"), or be a bounded STT echo of 5 or more letters with the same first letter. It is never "here" or "her" for Hern, and never "my mother" for Maluril. When two report lines are named, she asks which.

A commit report line still needs his explicit sentence (for example "Paarthurnax is dead.", which parks).

**Still open for Nazir.** 8 lines. 5 are on `narfi.alt`, whose list carries a "(Persuade)" check twin; the check rails decide those, by design. The rest are negation parity: "Ennodius Papius won't be a problem anymore" is vetoed by `lrgDlgNegationClash` before the kind pick. This is a hand-off, below.

### U1: open.kind_factions

This was already fixed by the final fixer (the measurer confirms it). I added regression rows:

- CarriageSystemFaction, DLC1FerrySystemFaction and JobTrainerFaction open.
- JobAnimalTrainerFaction, GuardFaction and no snapshot do not open.

## Files

- `glue/server/lorerim_glue/lib/lrg_dialogue.php`:
  - edited: `lrgDlgSttFold`, `lrgDlgWordsCarry`, `lrgDlgKindPick` (a 3-line hook at the top: the radiant "work" ask routing), `lrgDlgBusinessMarker` (the work ask in clauses 3 and 5) and `lrgDlgFollowerArbitrate` (the G6 branch);
  - new: `LRG_DLG_REACH_*` constants, `lrgDlgSttHomophones`, `lrgDlgReachContains`, `lrgDlgSttRepair`, `lrgDlgReachEcho`, `lrgDlgReachWorkAsk`, `lrgDlgReachWorkLine`, `lrgDlgReachPick`, `lrgDlgReachReport`, `lrgDlgReachReportNames` and `lrgDlgReachNames`.
  - Every function was verified identical to my tested private copy after the port (md5 per function).
- `glue/server/lorerim_glue/config/lrg_config.default.json`: a new key `dialogue.reach` with `work.{enabled,say,not_after,entry}`, `report.{enabled,say,entry,not_with}` and a `_readme`. The code constants are the defaults; `enabled: false` switches either lane off.
- `glue/tools/test_services.php`: a new section 9 is appended before the summary. It has 28 checks built from the measurer's examples, as must-resolve and must-not rows:
  - G2 carry and near-miss rows, fold and keep rows;
  - G4 asks and look-alikes, Gunmar, CR07, RV03 ("two, she asks"), the Delvin echo, J'zargo's near-miss, Urag's missing work line, and the pre-LLM open;
  - Nazir's 11 reports and their must-nots;
  - the G6 arbiter (no entry, a companion, a real entry);
  - 10 end-to-end `lrgDlgAnswerWant` rows over an in-memory index;
  - U1;
  - the JSON-completeness check.

## Suite tails (live tree snapshot, about 07:30; run on a WSL copy)

```
--- php tools/test_services.php (tail)
127 passed, 0 failed
ALL CHECKS PASSED
--- php tools/test_dialogue.php --quiet (tail)
1127 passed, 0 failed
ALL CHECKS PASSED
--- php tools/test_gates.php --quiet (tail)
843 passed, 0 failed
--- php tools/test_questline.php --quiet (tail)
  [FAIL] MS05.viarmo.comein @clicks_ok 1: "and that's wear i come in" -> pick  [got single/pick: fast pick pos=0 mode=single | s=1.000 m=1.000 ...
1955 passed, 1 failed, 0 known
--- php tools/test_questline.php --words (tail)
2193 passed, 1 failed, 0 known      (the same MS05.viarmo.comein row)
--- php tools/test_questline.php --first-evening (tail)
463 passed, 0 failed, 0 known
ALL CHECKS PASSED
--- php tools/flows/run_flows.php --quiet (tail)
89 scenarios: 88 passed, 0 FAILED, 1 pending; 1600 checks, 0 warnings
```

The other suites were also run: test_intent 470/0, test_stt 60/0, test_phrases 47/0, test_prompt_index 103/0, test_mcm_wiring 49/0, test_latency 34/0 and test_scene_index all ok. test_audiofilterd fails 2 of 75 on the live tree and on the untouched baseline alike; it is environmental (a daemon socket and the log flood check), not dialogue.

**The one questline failure is a stale fixture label, and it comes from my G2 fix.** The STT row "and that's wear i come in" was labelled `via: "pick"`, with the note "the fast path does not click; the model's T-key does". It now releases on the fast path as a single ("wear" folds to "where", aligned; step 2 exact 1.0). That is the same outcome its sibling rows already carry (`via: "single"`). `qlMatches('pick', 'single/pick')` fails by design.

**Needed from the owner of `tools/fixtures/lrg_questline.json`**, the harness fixer, which is not my file: on beat MS05.viarmo.comein, change that row's `via` from `"pick"` to `"single"`. After that, test_questline and `--words` are all-pass.

## Hand-offs (not my functions)

1. **`lrgDlgNegationClash`, the safety owner.** On the fast path `lrgDlgAnswerWant` calls it with his RAW sentence, so the aligned homophone fold never reaches it. Two changes are needed:
   - Folding his words with `lrgDlgSttFold($said, [$entry])` first would release 11 STT lines: "i no where the blood horkers are", "i no what i'm doing", "i'm knot shore about this" and others. The fold never adds or removes a negator without both neighbours aligned against the line.
   - "won't be a problem" should join the idioms it already exempts ("no problem", "no doubt"). This is Nazir's "Ennodius Papius won't be a problem anymore".
2. **`lrgDlgSingleEntryRelease` / `lrgDlgAnswerWant`.** On a single-entry layer, a work ask ("got any work?" on DLC1RV05's lone "What can I do to help?") and a few STT lines ("so you want help me" for "So you won't help me?") return before the kind pick. They are left to her T-key.
3. **The escort (10.20, `lrgEscortPlan`).** See the G6 risk above. If the owner sees a double carrier in game, the escort could stand aside when the fast path clicked a quest line in this cid.

## Hard rules, checked

- No floor was lowered (`min_score`, `min_margin`, precision 0.75, the explicit thresholds). The repair only lets through a pick the matcher already made.
- No question, negation, deferral, echo, hedge or near-miss newly clicks a commit, crit, priced, scripted or irreversible line on the isolated tree (0 `never`/`probe` rows).
- A commit reached by the kind pick still needs his explicit sentence.
- Everything is server-side. There is no Papyrus change and no new wire key; latency is one phrase scan per entry, and the engine run time is unchanged at about 12 s for 39k utterances.
- Shipped features:
  - buying (10.27): the kind pick still stands down beside a voice order, because the hook sits inside `lrgDlgKindPick` after the callers' market guard;
  - quiet mode, escort and SFF, quest entry, direct barter, the visible menu: test_gates 843/0 and flows 88/0;
  - the barter kind: the v31 test and my Gunmar rows.
- Work folder: `%TEMP%\lrg_test\pt19h-reach\`. It holds the engine runs `m_out_after7.json` (isolated) and `m_out_live2.json` (live), the comparison tools `cmp.py`, `gapsum.py`, `cases.py` and `gatecmp.py`, and `revert.php`, which builds the live-minus-reach attribution tree.
