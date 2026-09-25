# pt19h measure: what is still live on today's tree

2026-09-25, read-only. The MEASURER re-ran the coverage team's tooling, and a wider probe set, on the current glue tree.

- **Tree measured.** `glue/server/lorerim_glue` was copied at 05:23. It was checked again at 05:49 and had not changed. File hashes:
  - `lrg_dialogue.php` fe28ae3e, last written 05:03 by the final fixer
  - `lrg_prompt_index.php` f4e78160
  - `lrg_speech.php` e8cc7274
  - `lrg_intent.php` a4059627
  - `lrg_factions.php` a6966877
  - `config/lrg_config.default.json` cb5269eb
  - `config/lrg_dialogue_overrides.default.json` db66a291

  No pt19h fixer edit is in these numbers.
- **Index.** This is a read-only copy of the live `prompt_index.ndjson`. Its header hash is e756e311, the same file the coverage team used, with 37,561 rows.
- **Machine-readable output:** `research/pt19h-measure.json`. For each gap it holds every case as `{say, info_key, beat, outcome}`, plus the gap's own counters, and the coverage re-check's rows that are still live. It also holds the outcome shares, the never rows and the six extra items.
- **Work folder:** `%TEMP%\lrg_test\pt19h-measure\`. It holds `m_engine.php`, `build_in.py`, `analyze.py`, `m_extras.php`, `final.py`, `tql_measure.php` and the raw outputs.

## How it was measured

1. **Engine.** `m_engine.php` is adapted from `pt19x-merge/recheck.php`. For each beat:
   - the fixture's own list goes through `lrgPromptLookup` and `lrgDlgDecorateEntries`;
   - his sentence goes through the want=1 fast path, `lrgDlgAnswerWant` (the emit log is parsed for do=pick and do=show);
   - her T-key on the target goes through `lrgDlgDecideEntry`;
   - on every list, LEAVE ("never mind" and "goodbye") goes through `lrgDlgDecide`.

   The run used clicks_ok 1, so scene beats were measured as driven.
   - **Beats:** 2,064 extended-fixture beats (fixture plus quarantine) and 260 Dark Brotherhood beats. The Brotherhood texts were resolved from the index by info_key.
   - **Utterances (39,052 in total):**
     - 16,186 `say` lines: the fixture's say, `say_model` and `misses`, plus the Brotherhood verbatim, paraphrase and STT lines;
     - 4,451 must-not-click rows: `never`, `never_red`, near-miss and quarantine `still_clicks`;
     - 14,642 generated probes: "not now, X later", "not yet, X", "I'm not sure, X", "no, X", "X?", "wait, X?" and "is it true that X";
     - 1,011 gap probes: G4 work, G6 follower verbs, G13 price questions and G16 hand-overs;
     - 66 of COVERAGE's own evidence sentences.
2. **The coverage team's `recheck.php` and `evaluate.py`.** Only the index path was changed. They ran over their own `recheck_in.json`: 212 danger rows and 826 generic commit beats.
3. **The canonical harness.** `tools/test_questline.php` was run as a copy with a per-line recorder, over `tools/fixtures/lrg_questline_adversarial.json` and the spec fixture.
   - Cross-check: I put 14 extended beats through it. The canonical harness could build the list for 5 of them. On those 5, it fails every never line the engine had flagged, with the same mode and score (13 lines):
     - Cow_Destruction.finish
     - MQ202.door.open
     - DLC1VQ08.isran.trustserana
     - MQ05.storn.restore
     - MQ203.riverwood.here
4. **Extra items.** These were called directly in `m_extras.php`: `lrgDlgNameWord`, `lrgDlgRewardAsk`, `lrgIntentAmount`, `lrgDlgNamedAmount`, `lrgDlgCheckRails`, `lrgDlgKindMayOpen` and `lrgDlgRailNote`.

**"Protected" line** (the rule's never-click set): a commit, a line of class commit, check or pay, a scripted line (including Invisible Continue), a goodbye line, crit >= 1, cost > 0, or a check kind.

## Headline

**Outcome shares over every say line.** There are 17,418 lines: 16,186 from the extended fixture and the Brotherhood, 440 adversarial and 792 spec.

| set | lines | fast-path click | her T-key | she asks once | nothing | wrong line | menu handed back (show) |
|---|---|---|---|---|---|---|---|
| **all** | 17,418 | **58.6%** (10,212) | **19.2%** (3,346) | **20.3%** (3,528) | **1.7%** (288) | **0.06%** (11) | 0.2% (33) |
| extended + Brotherhood | 16,186 | 58.7% | 18.9% | 20.4% (words park 6.9%, key park 13.5%) | 1.7% | 0.1% | 0.2% |
| - not STT | 11,870 | 55.2% | 19.1% | 23.5% | 1.9% | 0.1% | 0.2% |
| - STT | 4,316 | 68.3% | 18.2% | 12.0% | 1.3% | 0 | 0.3% |
| - Brotherhood only | 1,625 | 45.9% | 18.6% | 32.5% | 3.0% | 0 | 0 |
| adversarial fixture | 440 | 55.7% | 26.1% | 18.2% | 0 | 0 | 0 |
| spec fixture | 792 | 59.3% | 22.0% | 18.1% | 0.6% | 0 | 0 |
| *COVERAGE at 19:36* | *11,196* | *54%* | *23%* | *17%* | *6% (with "wrong" and "which")* | | |

- The 628 lines the fixture recorded as misses (lines that did nothing) now go: 17.7% fast, 42.7% T-key, 21.2% ask, 16.9% nothing and 1.4% wrong.
- "Wrong line" covers 11 sentences. Several of them repeat a sibling line almost word for word.
- "She asks once" has two parts:
  - **words park**: his words named a commit line, so she asks;
  - **key park**: nothing happened on the fast path, and her T-key on the line parked it.

  The T-key and key-park columns both depend on the model pressing the key.

**Must-not-click rows that click.** 644 of 4,689 click on the fast path, and 478 of those are on a protected line. All 644 are in the extended fixture and the Brotherhood set.

| rows | count | click on the fast path |
|---|---|---|
| fixture `never_red` (red at 19:36, or on the verifier's build) | 1,859 | 534 (so 1,325 have turned green) |
| fixture `never` (green at 19:36) | 2,302 | **67 now click** (49 on a protected line) |
| quarantine `still_clicks` | 42 | 27 |
| near-misses | 248 | 16 |
| adversarial fixture `never` | 191 | **0** |
| spec fixture `never` | 47 | **0** |

- **By shape:** refusal, deferral or hedge 247; echo 239; question 82; statement or near-miss 61; negated 15.
- **By mode:** single 485, intent 78, explicit 70, kind 7, advance 3, continuation 1.
- **Not counted:**
  - 33 lines where he said another listed line word for word;
  - 29 effect-free breath advances;
  - 0 of 341 `not_target` rows click their target.
- **Her key (LLM path) after a must-not-click line.** The fast path does nothing, but the gate still releases the target when the model presses the key:
  - 18 lines are released as EXPLICIT on a commit. Example: "I want to be a Stormcloak" picks Ulfric's side switch, 05A6B1.
  - 28 more are released as "the engine's own confirmation layer" (see G1).

**The coverage team's own re-check, unchanged, on today's tree:**
- 65 of 212 danger rows are still live (107 at 19:36).
- The generic deferral "not now, <line> later" clicks on 119 of 826 commit beats (646 of 837 at 19:36).
- Echo questions click on 93 beats (251 utterances); 10 near-misses click.
- The 65 live rows by gap: G19 15, G1 12, G9 10, G8 9, G5 7, G11 6, G14 4, H2 4, G15 3, G13 2, and 1 each for G10, G12, G18 and G20.

**What is still live, in one line each:**
- **G1 and G11 on single-entry layers.** 998 of the 1,072 G1 clicks and 333 of the 342 G11 clicks are mode `single`. S4.5 step 2's "exact" containment releases the line before the step-0 refusal and shape test can refuse it.
- **G1 on the LLM path.** A two-line yes/no list is released by her key as "the engine's own confirmation layer" even after "no", "never mind" or a deferral. Serana's Vampire Lord "I'm sure." (004A77) and Miraak's "yes" (023F61) are affected.
- **G6.** The follower arbiter takes 133 quest sentences on lists with no follower entry.
- **G4.** 48 of 56 radiant beats have no fast-path start.
- **G13.** Her key executes a persuade, intimidate or bribe after "how much would it cost?" on 36 of 112 check beats.
- **G8.** LEAVE takes a scripted or commit back line on 67 lists.
- **G16.** Hand-over words reach the line on 2 of 15 beats.

**Fixed:**
- the LETHAL fast path (show on both paths);
- U1 `open.kind_factions`;
- the stage-rail note (now kind=rail);
- the critic's STT sums, name-word six and reward-line eleven.

## Per gap: how many are still live

Each count has its own unit, given in the table. "Tested" is the matching denominator.

| gap | still live | tested | what the number counts | 19:36 (COVERAGE) |
|---|---|---|---|---|
| G1 | **1,072** (343 beats) | 8,470 | refusal / deferral / hedge utterances that click a protected line | "not now, <line> later" clicked on 646 of 837 commit beats; now 113 of 1,033 |
| G2 | **394** | 4,316 | STT say lines the fast path loses (precision rail 339, nothing 56) | 881 blocked by the precision rail |
| G3 | **271** (86 beats) | 628 | say lines on commit-graded targets whose own row is unscripted, not goodbye, not crit and unpriced, where she asks once | 17% of paraphrases asked once; now 20.4% |
| G4 | **375** (48 of 56 beats) | 396 | "got any work?"-type sentences that do not start the radiant on the fast path | 136 of 290 Dawnguard radiant paraphrases resolved |
| G5 | **22** (20 beats) | 1,183 | question-shaped must-not-click lines that click a protected statement line | 40 quarantined beats, 254 near-misses |
| G6 | **179** (44 beats) | 184 | sentences on lists with no follower entry that the follower arbiter swallows | 31 utterances |
| G7 | **300** | 2,324 | journal-quest scene beats, read-only until the first proven click (by design) | 311 |
| G8 | **67** lists (107 beats) | 1,810 lists | lists where LEAVE takes a scripted or commit back line | 9 quarantined beats |
| G9 | **88** (73 beats) | 691 | statement near-misses, and questions against a question line, that click a protected line | frequent (31 never_red) |
| G10 | **15** (13 beats) | 344 | negated must-not-click lines that click a protected line | 44 utterances |
| G11 | **342** (185 beats, 90 of them commits) | 3,172 | echo questions that click a protected line | 143 commit beats (91 singles) |
| G12 | **53** | 2,324 | targets graded with another row's flags | "Reporting in." / "Yes, sir." / 026598 / 000803 / 01AA9A |
| G13 | **73** (36 beats) | 450 | question or price-question sentences after which her key executes a check | every check layer |
| G14 | **6** | 23 | paid quest lines whose entry cost is 0 (hand-reviewed) | 5 evidence lines |
| G15 | **13** | 233 | scripted, non-service-topic lines graded class service (all 5 evidence lines included) | 5 evidence lines |
| G16 | **48** (13 of 15 beats) | 55 | hand-over sentences whose words reach the line on neither fast path | 17 beats |
| G17 | **113** | 252 | no-line steps (39 + 19) plus unshipped 10.26 `use=entry` candidates (55) | same |
| G18 | **24** | 108 | sentences the enlistment hand-off takes from the quest line he meant | 16 utterances |
| G19 | **9** | 10 | evidence lines still graded plain, plus catch-all-row lines | 9 lines + catch-all + no overrides file |
| G20 | **2** | 6 | Serana dismissal sentences that click it with no question | "should we part ways?" EXPLICIT |

---

### G1: a refusal, deferral or hedge that quotes the line clicks it. STILL LIVE: 1,072 utterances on 343 beats

**Closed-list explicit clicks are mostly fixed.** The deferral on a commit beat clicks on 113 of 1,033 beats; at 19:36 it was 646 of 837.

**Single-entry layers are where it remains.** 998 of the 1,072 clicks are mode `single`. In `lrgDlgSingleEntryRelease`, step 2 "exact" (containment >= 0.85) wins before step 0's refusal test.

| probe | tested | clicks a protected line | clicks a plain line |
|---|---|---|---|
| "not now, <line> later" | 2,188 | 279 | 167 |
| "not yet, <line>" | 2,025 | 242 | 162 |
| "I'm not sure, <line>" | 2,027 | 242 | 162 |
| "no, <line>" | 2,091 | 308 | 166 |
| other leading refusals | 139 | 1 | 5 |

**The 74 clicks that are not mode `single`:**
- Lines that carry their own "no" or negation, which turns negation parity off: "No, Onmund...", "I can't let you lead Thirsk.", "I'd rather not take that job.".
- The **enlistment arbiter**, which runs before any refusal test: 16 faction-mode clicks.
- Service kind picks (12).

| he says | info_key | beat | what happens |
|---|---|---|---|
| not now, Can I enter the Hall of Valor later | skyrim.esm:04FA45 | mq3:MQ304.tsun.enter | clicks the commit single (step 2 exact) |
| not now, i'll restore the remaining Stones if that will help later | dragonborn.esm:035BCA | dragonborn:MQ05.storn.restore | clicks the commit single (S4.5 2 exact 0.907); the canonical harness agrees |
| not now, i trust her to do the right thing later | dawnguard.esm:019F94 | dawnguard:DLC1VQ08.isran.trustserana | clicks the commit single (0.87); the canonical harness agrees |
| not now, no, Onmund. I'm asking you. Be the Arch-Mage instead of me later | cya_chooseyourownarchmage.esp:000863 | college:CYA.onmund.end | EXPLICIT click; hands over the Arch-Mage title |
| no, i'm ready to take the Oath | skyrim.esm:0E2D06 | cw_stormcloak:CW01B.galmar.ready | faction-mode click on the Oath line (graded plain) |
| never mind | skyrim.esm:02C8D2 | college:MG04.ancano.understand | auto-advances the line (mode advance): a refusal still releases the breath |

**COVERAGE evidence that is now safe on the fast path:**
- "not now, are my things safe with you later" (0361DC) parks.
- "not now, I want the staff later" (03921D) parks.
- "no, Onmund, I'm not asking you" (000863) is refused as a negation.
- "I'm sure I need to think about it" (004A77) does nothing on the fast path. Her key still releases it: see the LLM-path gap below.

**LLM path: a new gap in the rail.** Take a two-line yes/no list, where `lrgDlgLayerIsOwnConfirmation` is true. `lrgDlgParkOrRelease` releases her key there as "the engine's own confirmation layer" without reading his words. So a refusal, a deferral or a hesitation does not stop the click if the model presses the "yes" key. This needs the model's key, but no server rail catches it. It happens on 33 refusal-shaped lines (11 more are released as EXPLICIT).

| he says | info_key | beat | what happens |
|---|---|---|---|
| I'm sure I need to think about it | dawnguard.esm:004A77 | dawnguard:DLC1NPCMentalModel.serana.sure | her key releases "I'm sure." (the Vampire Lord confirmation) |
| never mind | dawnguard.esm:004A77 | dawnguard:DLC1NPCMentalModel.serana.sure | the same |
| no | dragonborn.esm:023F61 | dragonborn:MQ06.miraak.yes | her key releases the "yes" line |
| not now, i'm ready to return to Tamriel later | skyrim.esm:0EB7C9 | mq3:MQ305.tsun.return | her key releases the return (COVERAGE evidence) |
| I'm not sure I understand the terms | skyrim.esm:05A0C0 | thieves:TG08A.karliah.ready | her key releases "Yes, I'm ready." |

### G2: STT damage loses the fast path. STILL LIVE: 394 of 4,316 STT lines

339 lines are blocked by the precision rail and 56 do nothing. At 19:36, 881 were blocked. 785 STT lines (18.2%) click only through her key.

| he says | info_key | beat | what happens |
|---|---|---|---|
| how can i health | skyrim.esm:065C87 | cw_stormcloak:CWSiege.solitude.orders | no match; her key picks explicit |
| partner knacks is dead | skyrim.esm:07735E | mq3:MQPaarthurnax.arngeir.dead | no match; her key parks |
| i'm the arch image it's two thousand coins | dawnguard.esm:0126D7 | dawnguard:DLC1VQElder.urag.archmage | no match; her key parks |
| why were you weighting for me | skyrim.esm:0C07ED | companions:C02.vilkas.waiting | no match |
| i was at hell again | skyrim.esm:0E1AE0 | cw_stormcloak:CW00B.ulfric.helgen | no match (COVERAGE evidence, still live); "i was at helgen" is EXPLICIT |

"i talked to the gray beards" (041F10), the other COVERAGE evidence line, now clicks on the fast path, so it is fixed.

### G3: converging answers graded commit, so each costs one question. STILL LIVE: 271 lines on 86 beats

205 of the 271 are Invisible Continue targets. The share of all say lines that ask once has risen from 17% to 20.4%. All four mq2 evidence targets are still commit: 0C81D9, 04C5F1, 0B94B1 and 05597E.

| he says | info_key | beat | what happens |
|---|---|---|---|
| Aela and I are avenging Skjor | skyrim.esm:017809 | companions:C04.kodlak.avenge | matched a commit, so she asks |
| we found some kind of orb, Tolfdir wants you to see it | skyrim.esm:0263D9 | college:MG02.savos.orb | matched a commit, so she asks |
| the Stormcloaks got there first | skyrim.esm:01C5C9 | cw_imperial:CW02A.tullius.ahead | matched a commit, so she asks |
| what sort of test are we talking about | skyrim.esm:0D5151 | cw_imperial:CW00A.rikke.whattest | her key parks |
| I owe you | skyrim.esm:0B8382 | thieves:TG05.karliah.debt | her key parks |

### G4: asking for work misses the radiant start. STILL LIVE: 375 of 396 sentences (48 of 56 radiant beats)

The fast path never starts the radiant on "anything need doing?", "can I help?" or "got a job for me?". "got any work?" works on 6 beats. Mostly her key would pick the start (357).

"put me to work" clicks the wrong plain line on 4 College lists. The DLC1RH01 and RH06 barter misclick from COVERAGE is gone: those beats now do nothing on the fast path, and her key picks.

| he says | info_key | beat | what happens |
|---|---|---|---|
| put me to work | skyrim.esm:028A04 | college:MGR20.business | clicks ANOTHER line, "You take your work very seriously." |
| put me to work | skyrim.esm:0CD985 | college:MGRitual04.start | clicks ANOTHER line, "So you're treated badly because of your work?" |
| got any work? | skyrim.esm:0DE40A | thieves:TGR.vex.extra | no match; her key parks |
| got any work for me? | dawnguard.esm:005E41 | dawnguard:DLC1RH01.gunmar.start | no match; her key picks |
| anything need doing? | skyrim.esm:0E30B0 | companions:CR07.work | no match; her key picks |

### G5: a question clicks the statement turn-in. STILL LIVE: 22 lines on 20 beats

**All 7 COVERAGE evidence questions are safe on the fast path:** "where's the crown?", "is Arn safe?", "Where's the ring?", "who is Thonar", "where are Potema's remains", "are they coming" and "are all of those ingredients here?". Each would still be picked if the model pressed the key.

**What remains:** questions against statement singles, through the step-2 containment.

| he says | info_key | beat | what happens |
|---|---|---|---|
| have you brought the fragment? | skyrim.esm:0681EC | companions:C01.report.fragment | clicks the commit single "I've brought the fragment." |
| do you trust her to do the right thing? | dawnguard.esm:019F94 | dawnguard:DLC1VQ08.isran.trustserana | clicks the commit single (S4.5 2 exact 1); the canonical harness agrees |
| what should I keep in mind | college of winterhold - quest expansion.esp:0008A6 | college:Cow_Destruction.finish | clicks the commit single "I'll keep that in mind."; the canonical harness agrees |
| what do I do with the Elder Scroll? | skyrim.esm:0C64EC | mq3:MQ206.paarthurnax.scroll | clicks the commit single "I have the Elder Scroll." |
| do you have Kodlak's fragment? | skyrim.esm:0E4A2C | companions:C06.eorlund.kodlakfrag | clicks the commit single "I have Kodlak's fragment." |
| can anyone join the Dawnguard? | dawnguard.esm:00D901 | dawnguard:DLC1VQ01Misc.isran.join | EXPLICIT click on "I'm here to join the Dawnguard." |

### G6: follower verbs take quest lines. STILL LIVE: 179 sentences on 44 beats

`lrgDlgFollowerOn` still reads only the MCM switch. A sentence containing "let's go", "follow me" or "wait here" goes to `lrgDlgFollowerArbitrate` on lists with no follower entry, and nothing is clicked. That swallows 133 say lines. A further 21 on real follower lists are not counted.

| he says | info_key | beat | what happens |
|---|---|---|---|
| I'm ready, let's go get them | skyrim.esm:057BCE | cw_stormcloak:CW02B.galmar.go | follower arbiter, nothing; her key picks explicit |
| Ready. Let's go. (the line itself) | skyrim.esm:0DDDC7 | cw_imperial:CWMission07.hadvar.go | follower arbiter, nothing |
| let's go find him | skyrim.esm:01C4DB | daedric:DA03.barbas.go | follower arbiter, nothing |
| why wait for me | skyrim.esm:0C07ED | companions:C02.vilkas.waiting | follower arbiter, nothing |
| he's down by the docks, follow me | innocence lost - quest expansion.esp:000DC7 | brotherhood:DB01.grelod.docks | follower arbiter, nothing |
| let's go | skyrim.esm:0485C7 | mq3:MQ301.balgruuf.go | follower arbiter, nothing (COVERAGE evidence) |

### G7: journal-quest scenes are read-only until the first proven click. By design: 300 beats

This is unchanged. `lrgDlgReadOnlyWhy` still returns scene-unproven while clicks_ok < 1 (`lrg_dialogue.php` 1959-1962). The JSON lists all 300. Examples:
- MQ106.delphine.whatofit (0C65A2)
- MQ204.paar.college (03FA44)
- MQ305.tsun.return (0EB7C9)
- MQ302.council.elenwen (03AEBE)

### G8: LEAVE clicks a quest line. STILL LIVE: 67 lists (107 beats)

On these lists the model's LEAVE takes a scripted or commit back line: 41 commit, 26 scripted-goodbye plain.
- **Worst case:** lines that are not back-outs at all but contain "nothing" or "not sure". Examples: "Nothing I couldn't handle.", "I have nothing to hide...", "Ulfric holds nothing worth trading Markarth for.", "We're not sure, but they have an Elder Scroll.".
- **Also live:** back-out-worded lines that carry an effect, such as the Aringoth fight, the Boethiah refusal and the Vigilant.

| he says | info_key | beat | what happens |
|---|---|---|---|
| never mind | skyrim.esm:01C5C3 | cw_imperial:CW02A.tullius.ahead | LEAVE clicks "Nothing I couldn't handle." (commit) |
| never mind | skyrim.esm:03F883 | mq2:MQ204.arngeir.nothingtohide | LEAVE clicks "I have nothing to hide..." (scripted) |
| never mind | skyrim.esm:04B569 | mq3:MQ302.council.falkreath | LEAVE clicks "Ulfric holds nothing worth trading Markarth for." (commit) |
| never mind | dawnguard.esm:008471 | dawnguard:DLC1HunterBaseIntro.gunmar.scroll | LEAVE clicks "We're not sure, but they have an Elder Scroll." (commit) |
| never mind | skyrim.esm:0562FD | thieves:TG02.aringoth.forget | LEAVE clicks "Forget it. I'll just open it myself." (the fight) |
| never mind | skyrim.esm:04D8AF | daedric:DA02.boethiah.sovngarde | LEAVE clicks "I'll do nothing of the sort." (commit) |

### G9: explicit on one shared word, or a question against a question. STILL LIVE: 88 lines on 73 beats

- **Modes:** intent 38, explicit 29, single 17.
- **COVERAGE evidence still live:** 0799DC, 06A79B, 0A0D5E and 0E0CF1.
- **Fixed:** "can you help me?" on 065C85 and 065C87 now parks.

| he says | info_key | beat | what happens |
|---|---|---|---|
| tell me where it is | skyrim.esm:0799DC | thieves:TGTQ01.rhorlak.orelse | EXPLICIT click on "Tell me where it is, or else." |
| yes she wants you | skyrim.esm:06A79B | mq2:MQ201.party.erikur.truth | EXPLICIT click on "Yes. She wants you to leave her alone." |
| is there a way out | skyrim.esm:0A0D5E | mq2:MQ203.ratway.wayout | EXPLICIT click on "Do you know the way out of here?" |
| I made a mistake | skyrim.esm:05A6B1 | cw_imperial:CW02A.ulfric.defect | EXPLICIT click on the side switch |
| where do I sign up for the Companions? | dawnguard.esm:00D8E2 | dawnguard:DLC1VQ00.durak.signup | EXPLICIT click on Durak's Dawnguard sign-up |
| who's behind the door | skyrim.esm:0D66C4 | hold_side:MS01.nepos.behind | EXPLICIT click on "Who's behind all this?" |

### G10: negation parity. STILL LIVE: 15 lines on 13 beats

- **Fixed evidence:**
  - "we don't have to convince the others" (0098B6) is refused;
  - "I don't want to become a vampire" (003C1B) is refused;
  - "I'll become a vampire" now clicks;
  - "I can't handle it" (05C614) is not executed.
- **Still live:** "I don't want to refuse your gift" on 003BA3.

| he says | info_key | beat | what happens |
|---|---|---|---|
| I don't want to refuse your gift | dawnguard.esm:003BA3 | dawnguard:DLC1VQ02.harkon.refuse | EXPLICIT click on the refusal |
| that makes no sense | skyrim.esm:0479A8 | thieves:TG02.brynjolf.sense | EXPLICIT click on "Makes sense." |
| haven't you had enough of this | skyrim.esm:0FA21F | college:MG03.caller.fight | EXPLICIT click on "I've had enough of this." (the fight) |
| I could just kill you now, couldn't I | dragonborn.esm:019576 | dragonborn:TT1b.mogrul.kill | EXPLICIT click on "Or I could just kill you now." |
| let's not | skyrim.esm:091942 | thieves:TG08A.brynjolf.getto | single release of "Then let's get to it." |

### G11: echo questions release the line. STILL LIVE: 342 lines on 185 beats (90 of them commits)

333 of the 342 are mode `single`: the same $hit exemption as G1.

| he says | info_key | beat | what happens |
|---|---|---|---|
| wait, he's here in Riverwood. Right outside? | skyrim.esm:06EBDF | mq2:MQ203.riverwood.here | clicks the commit single (0.941); the canonical harness agrees |
| wait, i'll restore the remaining Stones if that will help? | dragonborn.esm:035BCA | dragonborn:MQ05.storn.restore | clicks the commit single (0.95); the canonical harness agrees |
| wait, yes, sir? | skyrim.esm:0C9833 | cw_stormcloak:CW02B.tullius.yes | clicks the commit single "Yes, sir!" |
| wait, i'll keep that in mind? | college of winterhold - quest expansion.esp:0008A6 | college:Cow_Destruction.finish | clicks the commit single; the canonical harness agrees |
| wait, i'm here to join the Dawnguard? | dawnguard.esm:00D901 | dawnguard:DLC1VQ01Misc.isran.join | EXPLICIT click |

**COVERAGE evidence:**
- "what is the Hall of Valor?" on 04FA45 is fixed: it parks.
- Storn's "what stones?" is refused.
- "what do I do with the Elder Scroll?" on 0C64EC still clicks. It is listed under G5.

### G12: norm sharing regrades a line with another row. STILL LIVE: 53 targets

- 31 lists resolve the line to another quest's row.
- 22 lines have crit 1 lent by another row.
- Other lent flags: persuade or intimidate kind (5), crit 2 (1), cost 750 (1).

| line | info_key | beat | what happens |
|---|---|---|---|
| Reporting in. | skyrim.esm:023870 | cw_imperial:CWSiegeObj.windhelm.rikke | resolved to 017DC8 (CWMission04InfoAttackPoint): crit 1, commit |
| Reporting in. | skyrim.esm:0DC249 | cw_imperial:CW03.cipius.report | the same row, 017DC8: commit |
| Yes, sir. | skyrim.esm:0C3483 | cw_imperial:CW00A.tullius.yes | resolved to 0C347D (Ulfric's CW00B row): commit |
| Where can I find him? | dawnguard.esm:014362 | dawnguard:DLC1HunterBaseStage2.sorine.where | resolved to 0834FB (DA02BoethiahChampionWhere): crit 1, commit |
| What can I do to help? | dawnguard.esm:010EB9 | dawnguard:DLC1VQ01Misc.isran.help | resolved to 004C1A (DLC1RV01): crit 1, commit |
| I'll do it. | skyrim.esm:06182B | thieves:TGR.delvin.numbers.accept | crit 1 lent: commit |

Frea's "What do you need?" (026598) resolves to its own row, which is commit. The re-check still lists TTF1.talvas.start (01AA9A, LETHAL) as "not re-testable offline".

### G13: a question executes a check on her key. STILL LIVE: 73 sentences on 36 of 112 check beats

The fast path never clicks. After "how much would it cost?" (36) or "what if I refuse?" (36), her key executes the persuade, intimidate or bribe line. "how much?" and "why should I?" are stopped by the 4-word attempt rail.

| he says | info_key | beat | what happens |
|---|---|---|---|
| how much would it cost | skyrim.esm:0E4A2E | hold_side:MS10.stig.bribe | her key executes the bribe (COVERAGE evidence) |
| Who is the priestess of Azura? | skyrim.esm:02450E | daedric:DA01.nelacar.persuade | her key executes the persuade (COVERAGE evidence) |
| how much would it cost? | dragonborn.esm:02455F | dragonborn:SV02.ancarion.deal | her key executes it |
| what if I refuse? | skyrim.esm:0D7689 | mq3:MQ302.ulfric.persuade | her key executes the persuade |
| how much would it cost? | skyrim.esm:097FCD | thieves:TG00.mq203.persuade_fail | her key executes the persuade |

Skjor's bribe, "how much would it cost" (00090F), now parks.

### G14: a price outside a "(N gold)" tag is invisible. STILL LIVE: 6 lines

All six have entry cost 0, so there is no ask at 100 septims or more and no afford rail. 15 more regex hits were reviewed by hand and are not costs of the clicked line: sell lines, Nazir's DB11 answers, price questions and Faralda's spell offer.

| line | info_key | beat | what happens |
|---|---|---|---|
| Here's the gold you wanted. | skyrim.esm:0C9A08 | college:MGRejoin.pay | cost 0; the rejoin fine is 250-1,000 septims; "here's the gold" clicks it on the fast path |
| Buy unusual gem for 1000 gold. | sell stones of barenziah.esp:00080B | thieves:TGCSG.buyback | cost 0 (1,000 septims in prose); "buy the unusual gem" clicks it |
| Here's the gold. | skyrim.esm:0B038E | thieves:TGBan.vex.gold | cost 0 (1,000-septim reparation); "yes" and "okay" release the single |
| I'm the Arch-Mage. It's 2,000 coins. | dawnguard.esm:0126D7 | dawnguard:DLC1VQElder.urag.archmage | cost 0 (2,000 septims) |
| (reparation, 500) | skyrim.esm:06F999 | brotherhood:DBEviction.pay500 | cost 0 (500 septims) |
| (reparation, 300) | skyrim.esm:06F99A | brotherhood:DBEviction.pay300 | cost 0 (300 septims) |

### G15: one service word misgrades a scripted quest line. STILL LIVE: 13 lines

**All 5 COVERAGE evidence lines are still class service:**
- 057FA1, Saadia's betrayal;
- 0A3E99, Vilkas's spar, which resolves to 0A3E98, C00VilkasTrainPlayerTopic;
- 0A95B9 (DA14);
- boethiahcalling_alternativequest.esp:000880 (DA02alt);
- 036D50, Razelan.

Lines on topics that read like a service topic (trade, train, sell, buy, services) are not counted, except for the evidence set.

| line | info_key | beat | what happens |
|---|---|---|---|
| Here, I brought you a drink. | skyrim.esm:036D50 | mq2:MQ201.party.razelan.drink | class service on MQ201PartyDrunkTopic, so the commit ask is skipped |
| I'd like a drink. | skyrim.esm:039CEC | mq2:MQ201.party.brelas | class service on MQ201AskForDrinkTopic |
| So, buy him off? | skyrim.esm:072AF9 | thieves:TG07.vex.buyoff | class service on TG07ValdBranchTopic02 |
| You sure you won't buy it? | skyrim.esm:09DFB5 | thieves:TGCrown.vex.buy | class service on TGCrownIntroBranchTopic03 |
| (Saadia's betrayal) | skyrim.esm:057FA1 | hold_side:MS08.saadia.lie3 | class service on MS08SaadiaST125Response1; the re-check says her key picks it after "no, wait, I changed my mind" |
| So you're supposed to train me? | skyrim.esm:0A3E98 | companions:C00.vilkas.train | class service on C00VilkasTrainPlayerTopic (the spar) |

### G16: hand-over tags are stripped from the norm. STILL LIVE: 48 of 55 sentences (13 of 15 beats)

His words reach the line on neither the fast path nor a words park. The only reason she asks is that her key was forced (34), or her key picks (14).

| he says | info_key | beat | what happens |
|---|---|---|---|
| here's her journal | dawnguard.esm:01667C | dawnguard:DLC1VQ04.serana.journal | no match on the fast path |
| here's the letter | dawnguard.esm:007EC4 | dawnguard:DLC1RH07.jarl.letter | no match; her key picks |
| here's Auriel's Bow | dawnguard.esm:012F5C | dawnguard:DLC1VQ08.harkon.givebow | no match |
| here's my invitation | skyrim.esm:041CDD | mq2:MQ201.embassy.invitation | no match |
| here are the fragments | skyrim.esm:0582E8 | companions:C06.eorlund.honor | no match |
| here's the satchel | skyrim.esm:0D8E34 | thieves:TGFC.risaad.satchel | the single waits (0.308) |

### G17: steps with no clickable line. STILL LIVE: 113

This is 39 extended and 19 Brotherhood no-line beats, plus 55 `use=entry` 10.26 candidates. The `lrg_factions.php` effects table still holds one row, Tullius (alternateperspective.esp:206783). None of the 55 is shipped. There are also 121 confirm-first candidates and 47 Brotherhood candidates.

Examples:
- MQ201.gear.container (the container)
- MQ203.bloodseal (activation)
- MQ202.door.activate
- CW.ulfric.promotion 064F68 (use=entry, CW 3)
- CW.tullius.orders 0CE08C (use=entry, CW 4)
- CW.ulfric.next 0E7257 (use=entry, CW 4)

### G18: faction words take quest lines. STILL LIVE: 24 sentences

The enlistment hand-off, `lrgFacArbitrateWant`, still owns the fast path. The main cases:
- "I just want to join the Legion, consider the crown a gift" (05A6A6)
- "I accept your offer. I'd like to join the Stormcloaks" (04BE5F)
- "make me a vampire, Serana" (004A79)

Each goes to "an enlistment was asked and nothing single can be clicked", and her key picks.

Durak's evidence, "where do I sign up to kill vampires" (00D8E2), now clicks Durak's line EXPLICIT, which is fixed. The fixture still lists it as a `never` row, so promote it to `say`.

| he says | info_key | beat | what happens |
|---|---|---|---|
| I just want to join the Legion, consider the crown a gift | skyrim.esm:05A6A6 | cw_stormcloak:CW02B.tullius.gift | enlistment hand-off, nothing; her key picks explicit |
| I accept your offer. I'd like to join the Stormcloaks | skyrim.esm:04BE5F | mq3:MQ302.council.switch | enlistment hand-off, nothing |
| i'm here to join the dawn guards | dawnguard.esm:00D901 | dawnguard:DLC1VQ01Misc.isran.join | enlistment hand-off, nothing |
| make me a vampire, Serana | dawnguard.esm:004A79 | dawnguard:DLC1NPCMentalModel.serana.turnme | enlistment hand-off, nothing |
| count me in, I want to hunt vampires | dawnguard.esm:00D8E2 | dawnguard:DLC1VQ00.durak.signup | enlistment hand-off, nothing; her key parks |

### G19: irreversible lines graded plain, and the catch-all row. STILL LIVE: 9

This is 8 of the 9 evidence lines, plus 1 catch-all line. The overrides file now ships with 5 rules, but none covers a G19 line. Only 05A6B1 (the side switch) is now a commit. These are still plain:
- 04DE3C, 0F1B29, 0D785E, 0E3077, 0E3009 and 0E3095;
- 00082B and 000D64 from the whispering door expansion.

| line | info_key | beat | what happens |
|---|---|---|---|
| I'm ready. Take me to Skuldafn. | skyrim.esm:04DE3C | mq3:MQ303.odahviing.skuldafn | still plain: "tell me about Skuldafn" clicks it (re-check) |
| I ran out of scrolls. | skyrim.esm:0F1B29 | college:MGRAppJzargo01.outofscrolls | still plain |
| Nelkir didn't make it. | the whispering door - quest expansion.esp:00082B | daedric:DA08twd.secret | still plain (the secret ending) |
| I want to quit that burglary job. | skyrim.esm:0D785E | thieves:TGR.vex.burglary.quit | still plain: "I quit" clicks it |
| (CR06 / CR07 accept lines) | skyrim.esm:0E3077 / 0E3009 | companions:CR06.decline / CR07.decline | still plain |
| I will help you cure yourself. | skyrim.esm:0E3095 | companions:CR13.help | still plain: "can you help me cure myself?" clicks it (re-check) |
| (tell the jarl) | the whispering door - quest expansion.esp:000D64 | daedric:DA08twd.telljarl | still plain (re-check: "there's a daedra talking to your son downstairs" clicks it) |
| Recognize this? (Show ...) | skyrim.esm:0E0B93 | cw_stormcloak:CWMission07.show | resolves to the catch-all row hearthfires.esm:003D89 |

### G20: Serana's dismissal. STILL LIVE: 2 sentences

"should we part ways?" and "let's part ways" now park. The dismissal resolves to dawnguard.esm:015A93 (DLC1NPCMentalModelDismissTopic), which is class commit but still fcommit 0.

On that list the follower arbiter clicks nothing. Her key, however, picks it EXPLICIT with no question:
- "I think we should part ways": her key picks EXPLICIT (dawnguard.esm:012EBC, DLC1NPCMentalModel.serana.partways).
- "we should part ways": the same.

"so what do you think?" no longer parks the lab line 003C23.

## The extra items

| item | still live | status |
|---|---|---|
| name-word proxy | 46 display names (28 role or faction words) | The critic's six are fixed: Tullius gives "tullius", Aldis "aldis", Maro "maro" and Rikke "rikke"; Priest of Arkay and Hunter give "". Erik's row is no longer Tullius's. The same proxy remains for role or faction names, and "guard" is by design. |
| reward-line leak | 2 of 53 | All of the critic's 11 are silent. 1 of 30 new ordinary sentences leaks. 1 of 12 bargains is not read as one. |
| STT sums | 4 of 20 | The critic's shapes are fixed. 4 new shapes are misread. |
| U1 `open.kind_factions` | 0 | Fixed. |
| LETHAL fast path | 0 | Fixed. |
| rail kind | 0 | Fixed. |

**Name-word proxy, the remaining words and how many top-level rows each claims:**
- "Dark Brotherhood Assassin" gives "dark" (205 rows).
- "Courier" gives "courier" (153).
- "College Apprentice" gives "college" (120).
- "Innkeeper" gives "innkeeper" (96).
- "Thalmor Justiciar" gives "thalmor" (30).
- "Vigilant of Stendarr" gives "vigilant" (27).
- "Dawnguard Veteran" gives "dawnguard" (20).

**Reward-line leak.** The leaking sentence is "The reward was posted in Riften." (hit "the reward"). The missed bargain is "I expect to be paid well for this."

**STT sums, the four misreads:**
- "1.5 thousand septims" reads 5000. This one overstates the sum.
- "one fifty septims" reads 51.
- "2k gold" reads 0.
- "two grand" reads 0.

The fixed shapes: "5 hundred gold" reads 500, "five hundred sept ums" 500, and "2,000 septims" 2000. On a 200-septim bribe, "2 hundred septims" passes and "one hundred" is still refused.

**U1 `open.kind_factions`.** It is filled in code and config. CarriageSystemFaction, KmodCarriageFreeFaction, DLC1FerrySystemFaction and JobTrainerFaction open the list; GuardFaction, JobAnimalTrainerFaction, an innkeeper and no snapshot do not.

**LETHAL fast path.** 45 fast-path do=show on lethal, arrest or resist-arrest matches. There are 0 silent drops after a LETHAL match and 0 clicks.

**Rail kind.** `lrgDlgRailNote` emits `kind=rail` (not `kind=hint`).

## Limits

- **Offline measurement.** Her T-key and key-park outcomes depend on the model pressing the key.
- **Lists.** Lists come from the fixture's entries, as in the coverage team's method. Hand-built root lists are approximate (COVERAGE H2), so 4 re-check rows stay "not re-testable offline".
- **"Protected"** includes scripted plain lines (Invisible Continue as well). The per-gap JSON gives the commit-only split: G1 416 of 1,072 on commits, G11 153 of 342.
- **Generated G1 probes** skip lines that themselves refuse or negate. "no, <a refusal>" agrees with the line.
- **G14** was hand-reviewed from 21 regex hits. The troll feeder line is on no measured list.
