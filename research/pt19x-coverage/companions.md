# pt19x coverage - The Companions (C00-C06 + radiant CR01-CR14)

Machine file: `companions.json` (same folder). Index: `prompt_index.ndjson` hash `e756e311aefaeab54a1184d609adb77b`.
Every score comes from the REAL v1.0 functions (`lrgDlgMatchText`, `lrgDlgMatchPick`, `lrgDlgSingleEntryRelease`,
`lrgDlgClass`, `lrgDlgNegationClash`, `lrgDlgUniqueWordTie`) run on a read-only copy of `glue/server/lorerim_glue/lib`
(copied 2026-09-24 18:11, work in `%TEMP%\lrg_test\pt19x-companions\`). Fast path only; the model's T-key is reported, not run.

## One-page summary

- **What exists.** The index holds 23 Companions quests with player lines: C00GiantAttack, C00, C00VilkasTrainingQuest,
  C01, C02, C02PostQuest, C03, C03PostQuest, C04, C04JorrvaskrCommotion, C06, C06PostQuest, CR01-CR14, plus
  DialogueCompanionsJorrvaskr (flavour). **C05 has 0 rows**.
- **Two corrections to the brief.** C05 is *Purity of Revenge*, and it has no player dialogue
  ([UESP](https://en.uesp.net/wiki/Skyrim:Purity_of_Revenge)). *Purity* is the radiant CR13
  ([UESP](https://en.uesp.net/wiki/Skyrim:Purity)). C02 is the initiation that follows Proving Honor.
- **Who wins the rows.** The rows are mostly Skyrim.esm and USSEP. The mods that change behaviour:
  - Narrative Gameplay Consistent Dialogue Tweaks: 12 of 18 C00GiantAttack rows and 10 of 23 CR14 rows, including a
    hostile "Nah. I don't think so." join variant.
  - Companions Radiant Expansion: every accept line and the CR09/10/11 starts.
  - CompanionsTweaks (Improved Companions): the C03 Underforge layer and "Yes, I'm prepared."
  - Companions Dialogue Bundle and CAM (Companions at Mirmulnir): extra C00 rows.
  - GORE.esp: C04 KodlakUhOhHeKnows and ReallyGiveTask.
  - IDE Jorrvaskr: an alternative 5-entry blood-ritual layer, plus a twin "Skjor has fallen..." line.
  - Vilkas Spar Skip: a twin of Vilkas's training line.
- **Radiant jobs are required.** `Customizable Companions Questline Progression Requirements` writes
  RadiantQuestsUntilC01/C03/C04 = **3 / 5 / 4** from its json (read). So a player must finish **12 radiant jobs**
  before Kodlak's witch task. Each job means a work line, an accept and a turn-in, so at least 36 driven lines, and more
  after C06 (CR12 totems, CR13, CR14). By volume, the radiant loop is most of what people say in the Companions.
- **Beats mapped.** 121 beats across 23 quests: 114 with a player line and 7 `cannot`. The six C00 beats already in the
  spec harness (Kodlak join/handle, Eorlund sword/weapon/choice, Aela shield) are listed as `already_in_harness` and
  are not repeated. There are 798 scored utterances (5 paraphrases and 2 STT variants per beat) and 114 near misses.
  Each beat also has its verbatim line and a negotiation block for the reward lines.
- **Scripts actually read.** Four fragments are loose on disk and were read:
  - NGCDT `TIF__000CF2D7` and `TIF__000CF2D5`: `MiscObj.SetStage(10)`, `GetOwningQuest().SetStage(200)` and a global.
  - CompanionsTweaks `TIF__C03SkjorFinallyReady`: `C03ReturntoUnderforgeKeyword.SendStoryEvent()`.
  - `VilkasSparSkip_Scripts`: `SetStage(20)` and `TraningQuest.SetStage(200)`.
  Every other `fx` is `unknown`, and its stage comes from UESP.

### Counts by path (121 beats)

| path | beats | notes |
|---|---|---|
| pick | 63 | 52 plain picks and 11 single-entry layers outside scenes (4 auto-advance at adv=2500, 7 released under S4.5) |
| ask_once | 34 | commits: 21 are scripted goodbye lines; 13 sit on closed layers with 2 or more scripted siblings |
| scene_read_only | 15 | C01 Farkas werewolf, C02 Vilkas x2, C03 Skjor x2 + Aela x2, C04 Vilkas x2, C06 Eorlund x2 + Farkas + Kodlak's ghost x3 |
| cannot | 7 | Farkas's quarters walk, the C02 ceremony, the C03 blood basin, C05 (0 rows), the C06 Flame, the CR04 brawl, the CR12 totem placement |
| service | 1 | C00 "So you're supposed to train me?" is misgraded as a service (gap 7) |
| scripted_entry_candidate | 1 | C00GiantAttack "Can I join you?" (fragment read: SetStage 200 + MiscObj 10 + a global) |
| check | 0 | the questline has no persuade, intimidate or bribe row (the CR04 target brawl is not a dialogue check) |

How the 798 utterances land on the fast path:

| outcome | utterances | share |
|---|---|---|
| clicks (fast 322 + explicit 166 + single 72) | 560 | 70% |
| only the model's T-key would pick it | 92 | 12% |
| she asks once, then "yes" clicks | 137 | 17% |
| nothing | 7 | 1% |
| wrong line | 2 | <1% (both are the fixture artefact in gap 4d) |

**Near misses:** 40 of 114 failed, meaning they would click or park the target. 25 are fast clicks, 7 are explicit
commits, 7 are parks and 1 clicks another line.

## Capability gaps, ranked by how often a player hits them

**1. The radiant loop (at least 36 lines per playthrough).**
- **"job" is not "work".** There is no synonym table (S5), so "do you have a job for me" (0.255), "any jobs going"
  (0.282) and "got another job for me" (0.229) never reach 0.55 on a work line. Every such request costs an LLM turn.
- **The same words are graded two ways.** The story pointers reuse the exact line "I'm looking for work.":
  - At C01 (`skyrim.esm:0D8701`) it is a plain pick.
  - At C03 (`skyrim.esm:0A7052`) and at C04 on Aela (`skyrim.esm:0582A0`) it is a scripted goodbye, which makes it a
    commit. The player's habit phrase then draws a confirmation question at exactly the beats that start the next quest.
- **Accept lines are graded inconsistently.**
  - CR01, CR02, CR03, CR04 and CR05 accept lines are commits, because their decline line is scripted as well.
  - CR06 and CR07 accept lines are plain picks, because their decline line is unscripted (`0E300B`, `0E2FF3`).
  - Accepting a contract should feel the same everywhere.
- **Alias tokens.** Accept lines and four turn-ins carry `<Alias=...>`, so the index norm is `i can kill a <t> in <t>`.
  The fixture needs `live_text` (recorded per beat). STT-damaged names still cleared the bar ("rorik stead" 0.767,
  "fayndal" 0.768, "fallow stone" 0.85). CR07's own text has the typo "prisioner"; a correctly spelled "prisoner" still
  scores 0.87.
- **Positive-sounding declines never click on the fast path.** "I'll pass on that job" and "not that job" hit the
  negation parity against "I don't want that job." (`0537D8`), so they fall to the model. That is safe, but it costs a turn.
- **Rewards are fixed.** Every turn-in pays leveled gold from the quest script (UESP: 100 to 300 septims by level). No
  Companions layer has a "what about my reward" line, so bargaining is words only (S6.1). Gate B's purse bonus is the
  only lever, and Kodlak's ghost has no purse.

**2. A question about a line clicks it (40 of 114 near misses).** The shape test (question against statement) exists
only for single-entry layers (S4.5 step 0). Neither the plain fast pick nor the S4.3 explicit test compares shape.
- Harmful examples:
  - "where is Dustman's Cairn" counts as explicit for "I'll meet you at Dustman's Cairn." (`0F64FA`, 0.721), so Farkas
    walks off alone.
  - "am I prepared for this" counts as explicit for "Yes, I'm prepared." (`companionstweaks.esp:000D6A`, 0.783), which
    starts the ritual.
  - "who will take care of it" accepts CR03 (`0A3E6D`, 0.783).
- Merely noisy examples:
  - "is the sabre cat dead yet" triggers the turn-in (0.907).
  - "is there any mead left" picks "Is there any work to be done?" (0.675).
  - "who are the Companions" picks "Can I join the Companions?" (0.610).
- Suggestion: apply the step-0 shape rule on every layer. A question-shaped utterance against a statement entry gets no
  fast pick and no explicit; the model answers instead.

**3. Converging choices are graded as commits and ask needlessly (137 utterances parked).** Opinion lines that lead to
the same next line still count as commits:
- Aela's two answers to "If you were any other man..." both link to `0A3E3A`.
- Kodlak's two witch opinions both link to `0582C5`.
- Eorlund's "lend a hand" and "servant" both hand over the same shield.
- Kodlak's three C00 answers all lead to the same stage.
The owner's own sentence releases them when it is close to the line. Looser wording ("happy to help", "I'd cut him down
before he could draw") makes her ask. Suggestion: treat scripted siblings whose `links` all point to one topic like
hub questions, not commits.

**4. Twin lines on one list.**
- (a) Vilkas "So you're supposed to train me?" appears twice: vanilla `0A3E99` and Vilkas Spar Skip `000003`, with
  identical text. They tie at 1.0 with margin 0, and the words cannot say "spar" or "skip".
- (b) "Skjor has fallen to the Silver Hand." appears twice: `0B3DFE` and IDE `aaJorrvaskrToastC03SkjorDead0`. No fast
  pick is possible, and it is a commit.
- (c) CR14 has "Let's go kill a dragon." (`0E308B`) next to NGCDT's "Let's go kill that Dragon!" (`000F6E`). The margin
  is below 0.15 for every paraphrase.
- (d) A fixture artefact. Layer lines `0C0085` (Skjor's "I'm looking for work."/"You wanted to see me?", two INFOs of
  one topic) and `0C0084` (Farkas's brother/sister lines) list entries the engine never shows together. The harness
  rule "use the layer line" then turns both into false commits. "You're going to be my shield-brother?" parks at 1.0,
  because the sister line scores 0.85.
- Suggestion: the layer builder should keep one visible INFO per topic, and a `twin` override should cover (a) to (c).

**5. Scenes stay read-only until this install records a real click (15 beats, once each per playthrough).** The whole
spine from C02 to C06 runs through forcegreets and scenes: Vilkas's initiation, Skjor at the Underforge, the blood
ritual, Aela's two forcegreets, Vilkas after the raid, the funeral, and Kodlak's ghost. On an install with no proven
click, every one of these answers "choose it on the list yourself".

**6. No player line (7 `cannot` beats).** C05 has 0 rows. The blood basin, the Flame of the Harbinger (C06 and CR13)
and the Underforge totem are activators. The CR04 target has no dialogue row, so the brawl starts only by hand. Farkas's
walk and the C02 ceremony are pure scenes. The player gets her words only (S7).

**7. Misgraded service.** `lrgDlgClass` checks service words before commit, so "So you're supposed to train me?"
(scripted goodbye, starts the spar) is graded `service` because the text contains "train". A request such as "I'd like
some training" can then act as a service-by-kind pick on a quest line.

**8. A leave phrase that is really a quest step (risk, not verified).** "I'll need to be going" (`0A3E7B`) is Eorlund's
only route to the shield favour, on a walk-away layer. The fast path clicks it: "I need to be going" scores 1.0. But if
the model reads the words as leaving, the leave guard (S4.2) answers `do=show` instead of clicking. The harness should
pin this.

**9. Minor items.**
- "I'm ready" in the blood ritual (`0AE72A`, 2 tokens) parks even when said verbatim, because an exact 1.0 hit needs 3
  tokens to count as explicit. "yes" then releases it.
- "wait, where is Glenmoril" is not refused by shape, because "wait" is not in the list of question lead-ins.
- Two of the three verbatim-tie commits need the model's T-key before she can even ask: (b) "Skjor has fallen" and
  (c) "Let's go kill a dragon".
