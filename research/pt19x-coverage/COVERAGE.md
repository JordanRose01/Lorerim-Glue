# pt19x coverage: every questline past the spec harness, merged

2026-09-24. This file merges the eleven verified group maps in this folder: mq2, mq3, companions, college, thieves,
cw_imperial, cw_stormcloak, dawnguard, dragonborn, daedric and hold_side. It also records a re-check of every danger the
verifiers found, run against today's build. It is the plan for extending `tools/test_questline.php` once v1.0 lands.
The spec harness itself (MQ101-MQ106 and each guild's first quest) is not repeated here.

**Files next to this one**
- `lrg_questline_extended.json` is the extended fixture. It uses the spec's section 3.3 format and adds a few keys, all
  explained in its `_legend`: `path`, `scene`, `near_miss`, `never_red`, `not_target`, `misses`, `fp`, and `twin_of`.
  It holds 2,005 beats, a `quarantine` list of 107 beats with the reason for each, and a `spec_overlap` list of 10 beats
  the spec harness already owns.
- `scripted_entry_candidates.json` lists 194 scripted lines whose fragment was read and turned out to be exactly one
  `SetStage(n)`. Each row gives the quest, stage, info_key, the index conditions and a `use` grade, ready for a
  PROTOCOL 10.26 table extension. Six more rows come from mq2's own table.

**How it was checked (read-only)**
- **Facts** come from the load order's prompt index: `prompt_index.ndjson`, hash `e756e311…`, Ultra profile, 37,561
  rows and 5,718 layer lines. UESP links are kept on each beat. Stage effects (`fx`) come only from fragments the groups
  actually read: TIF/QF `.pex` in the BSAs, loose `.psc` files, and plugin VMAD.
- **Every verifier danger was re-run on today's build.** I copied `lrg_dialogue.php` (md5 `dd7ab88b…`, 19:36) and
  `lrg_prompt_index.php` (19:04) byte for byte into `%TEMP%\lrg_test\pt19x-merge\g\`. Then I re-ran all 212 danger rows
  (157 beats) through the real functions:
  - `lrgPromptLookup` and `lrgDlgDecorateEntries`
  - the want=1 fast path `lrgDlgAnswerWant`, on his words alone
  - her T-key, via `lrgDlgDecideEntry`
  - the model's LEAVE, via `lrgDlgDecide`
- **One extra probe per commit beat.** 826 more beats each got a deferral ("not now, <the line> later"), plus echo
  questions, following the dragonborn verifier's pattern.
- **Scripts.** `norm.py`, `danger.py`, `build_recheck.py`, `recheck.php`, `evaluate.py` and `assemble.py` are in the
  same temp folder.
- **Quarantine rule.** A beat goes into quarantine when a verifier marked it dangerous and the danger still reproduces
  on the 19:36 lib. It also goes there when the danger is structural and cannot be re-tested offline: a missing
  speaker map, a lethal norm share, or the catch-all pattern row.
- **Dangers that no longer reproduce** stay in the fixture. Their danger lines become `never` rows, pinned so they
  cannot come back, and the beat carries `danger_resolved`.
- **The deferral defect** turned out to affect most commit beats, not just the ones the verifiers marked (gap G1). So
  it does not quarantine a beat. Instead every commit beat carries its deferral probe as a `never_red` row: a
  must-not-click line that clicks today.

## Headline

- **Size.** 2,122 beats across 246 quest EditorIDs.
  - Fixture: 2,005 beats, with 14,327 `say` lines, 1,800 green `never` lines and 1,178 `never_red` lines.
  - Quarantine: 107 beats (41 high harm, 47 medium, 19 low).
  - Already in the spec harness: 10 beats.
- **What a natural sentence does today**, measured over 11,196 paraphrases:

  | outcome | share |
  |---|---|
  | clicks on the fast path from his words alone | 54% |
  | clicks through her T-key in the same turn (depends on the model) | 23% |
  | she asks once, then "yes" clicks | 17% |
  | nothing, she asks which, or the wrong line | 6% |

  Speech-to-text-damaged variants (4,779) do 62 / 27 / 8 / 3%.
- **The biggest live defect is new today: G1.** A deferral that quotes a commit line clicks it. On the 19:36 lib,
  "not now, <the line> later" is an EXPLICIT click on **646 of 837 commit beats**. That includes Delphine's carriage
  line, which puts all his gear in her chest (0361DC), and "I'm ready to return to Tamriel." (0EB7C9).
- **Questions no longer click commits in most places.** The 19:28 question guard fixed most of what the verifiers
  measured on 19:05-19:14. Of 212 danger rows, 105 no longer reproduce.
- **What is still live:**
  - Questions that click statement turn-ins on root lists (G5: 40 quarantined beats).
  - LEAVE clicking quest lines (G8: 9 beats).
  - Lines graded plain that are really irreversible (G19).
- **Scenes.** 311 beats (15%) are spoken in journal-quest scenes. They are read-only until this install has one proven
  click, then driven. That matters only on the first evening.
- **What voice cannot do at all.** Activations (blood seal, basins, totems, pedestals), Shouts (Clear Skies, Fire
  Breath), combat-only steps, container menus (Malborn's gear) and locked-door conversations. Each questline section
  below lists them.
- **Click-free entry candidates** for PROTOCOL 10.26: 194 lines.
  - 55 can be sent as a plain entry.
  - 121 are commits in the menu, so they need his explicit sentence or her question and his yes first.
  - 18 must be avoided (quarantined traps).

## Per questline

Paths, as the groups use them:
- `pick`: a plain line clicks on his words.
- `ask_once`: a commit. His exact sentence clicks it; otherwise she asks once.
- `single`, `auto`: one-entry layers (S4.5 / S4.6).
- `check`: persuade, intimidate or bribe, under the check rails.
- `service`: a vendor or trainer line.
- `scene_read_only`: a journal-quest scene. It is driven after the first proven click; `stageB` in the fixture says how.
- `scripted_entry_candidate`: the line's whole fragment is one SetStage. It is driven in the menu and is also a 10.26
  row.
- `open`: the engine plays the line when the talk opens.
- `leave_only`, `never_by_voice`: the menu is his.
- `cannot`: there is no player line.

| group | beats | fixture | quarantine | spec | pick | ask_once | single/auto | check | service | scene | entry cand. | open | leave/never | cannot |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| mq2 (MQ106 rest - MQ204) | 128 | 120 | 8 | 0 | 26 | 26 | 0 | 13 | 2 | 41 | 15 | 0 | 0 | 5 |
| mq3 (MQ205 - MQ306) | 101 | 98 | 3 | 0 | 64 | 17 | 0 | 2 | 0 | 12 | 0 | 0 | 0 | 6 |
| companions | 129 | 124 | 5 | 0 | 63 | 36 | 0 | 2 | 1 | 20 | 1 | 0 | 0 | 6 |
| college | 308 | 302 | 6 | 0 | 154 | 116 | 0 | 4 | 1 | 28 | 0 | 0 | 3 | 2 |
| thieves | 338 | 325 | 13 | 0 | 184 | 66 | 0 | 15 | 2 | 65 | 3 | 3 | 0 | 0 |
| cw_imperial | 95 | 85 | 4 | 6 | 41 | 31 | 0 | 4 | 1 | 6 | 5 | 1 | 0 | 6 |
| cw_stormcloak | 96 | 84 | 8 | 4 | 34 | 38 | 4 | 4 | 2 | 8 | 0 | 0 | 4 | 2 |
| dawnguard | 285 | 269 | 16 | 0 | 121 | 68 | 0 | 15 | 5 | 49 | 27 | 0 | 0 | 0 |
| dragonborn | 181 | 170 | 11 | 0 | 70 | 56 | 0 | 8 | 1 | 19 | 26 | 0 | 0 | 1 |
| daedric | 248 | 232 | 16 | 0 | 73 | 93 | 0 | 33 | 1 | 26 | 16 | 0 | 0 | 6 |
| hold_side | 213 | 196 | 17 | 0 | 87 | 79 | 0 | 19 | 0 | 24 | 3 | 0 | 0 | 1 |

How his sentences land, per group. Near-miss columns: green means nothing is clicked; "sibling" means another listed
line is clicked; "target" means the beat's own line is clicked.

| group | paraphrases | fast path | her T-key | ask once | nothing | STT | STT fast | STT T-key | near-miss green / sibling / target |
|---|---|---|---|---|---|---|---|---|---|
| mq2 | 728 | 51% | 30% | 14% | 4% | 353 | 65% | 26% | 109 / 0 / 14 |
| mq3 | 480 | 59% | 27% | 3% | 11% | 192 | 49% | 40% | 98 / 2 / 1 |
| companions | 690 | 68% | 14% | 17% | 1% | 246 | 90% | 4% | 82 / 0 / 41 |
| college | 1,382 | 49% | 25% | 24% | 2% | 537 | 45% | 47% | 226 / 24 / 58 |
| thieves | 2,060 | 57% | 29% | 12% | 2% | 1,292 | 74% | 21% | 220 / 70 / 48 |
| cw_imperial | 475 | 37% | 22% | 15% | 25% | 190 | 31% | 49% | 45 / 44 / 6 |
| cw_stormcloak | 463 | 40% | 27% | 32% | 1% | 176 | 32% | 47% | 74 / 2 / 14 |
| dawnguard | 1,425 | 62% | 16% | 16% | 6% | 570 | 74% | 10% | 173 / 101 / 11 |
| dragonborn | 843 | 65% | 11% | 22% | 2% | 302 | 74% | 11% | 124 / 52 / 5 |
| daedric | 1,374 | 48% | 18% | 18% | 17% | 496 | 55% | 24% | 176 / 53 / 19 |
| hold_side | 1,276 | 53% | 28% | 19% | 1% | 425 | 47% | 44% | 176 / 0 / 37 |

### Main quest, part 2: mq2

Covers the rest of MQ106 A Blade in the Dark, MQ201 Diplomatic Immunity (with MQ201Party and MQ201Malborn), MQ202 A
Cornered Rat, MQ203 Alduin's Wall and MQ204 The Throat of the World.

- **What he can do by voice, end to end:**
  - Delphine's basement talk and her Kynesgrove, stables and return forcegreets are journal scenes (41 beats). By this
    point MQ101-MQ106 have proven a click, so in practice they are driven.
  - Malborn: "Our mutual friend sent me." (SetStage 50).
  - The carriage line "I'm ready. Keep the rest of my things safe for me." (0361DC) is a commit. It clicks on his own
    sentence. The questions that used to click it now park.
  - At the party:
    - the five distraction persuades (checks, on her T-key)
    - Erikur's offer
    - Brelas: "That fellow over there asked me to talk to you..." (SetStage 35)
  - In the dungeon, Etienne's three lines (205 / 208 / 210).
  - In the Ratway: Brynjolf, then the Vekel/Dirge persuade, bribe, intimidate and brawl checks.
  - At Esbern's door: the passphrase lines (130 / 140 / 145 / 150).
  - Alduin's Wall, Arngeir and Paarthurnax, by voice.
- **Where the menu or his hands are still needed:**
  - Malborn's gear container is an inventory menu (TIF__000361DF waits for it to close).
  - Esbern speaks from behind a locked door. The player must Activate the door; after that every line is voiceable.
  - The Karthspire blood seal is an activator.
  - Clear Skies and Fire Breath are Shouts, not dialogue.
- **Quarantined (8):**
  - Erikur's truth line 06A79B. "yes she wants you" is EXPLICIT and takes the irreversible branch.
  - Arngeir 03F883 "I have nothing to hide..." (SetStage 30) and the return line 03BC99. LEAVE clicks both.
  - Esbern's "Do you know the way out of here?" 0A0D5E. The question "is there a way out" is EXPLICIT on it.
  - Four beats whose hand-built root lists contain rows the NPC cannot have: 053A51, 03B69C, 03B69E and 0A8BEA.
- **Pinned as never** (fixed at 19:32, confirmed at 19:36):
  - "are my things safe with you" (0361DC)
  - "are you ready" (0362CB)
  - "how do I defeat Alduin" (016F9B)
- **10.26 candidates:** 57 (20 entry, 32 confirm first, 5 avoid).

### Main quest, part 3: mq3

Covers MQ205 Elder Knowledge (with its DA04 leg at Urag and Septimus), MQ206, MQ301 The Fallen, MQPaarthurnax, MQ302,
and MQ303-MQ306.

- **What he can do by voice, end to end:**
  - Elder Knowledge: Delphine, Esbern, Arngeir, Urag and Septimus. Every stage line there is a single-SetStage
    fragment.
  - Alduin's Bane has one line, "I have the Elder Scroll." (0C64EC, SetStage 15). Skip Time Wound Scene replaces
    QF_MQ206.
  - The Fallen: the trap plan with Arngeir, Esbern and Balgruuf (15 / 18 / 20 / 21 / 22 / 27 / 40), then Odahviing
    (220 / 230).
  - Paarthurnax Quest Expansion adds a spare path that works by voice (75 / 76 / 99).
  - Sovngarde and Tsun are scenes, driven after the first click.
- **Season Unending never happens on this install.** CWFO_TheFallen.esp gates the truce on a global that is 0, so the
  8 MQ302 beats are dormant.
- **Where the menu or his hands are still needed:**
  - Five Invisible-Continue prompts, which the engine plays itself.
  - The Sovngarde walk-away target (04E9B5). Its stage is also reachable by voice through 04E9BB.
  - Killing Paarthurnax: the fight starts only when he strikes.
- **Quarantined (3):**
  - Odahviing's "I'm ready. Take me to Skuldafn." 04DE3C. It is a point of no return graded plain, and 7 of 8
    questions about Skuldafn click it.
  - Tsun's "Can I enter the Hall of Valor?" 04FA45. "what is the Hall of Valor?" starts the trial by combat.
  - 0C64EC. "what do I do with the Elder Scroll?" is released by the $hit exemption. Low harm.
- **Pinned as never:** 07735E, 0EB7C9, 0485C7, 07CC29 and 04DE34. The questions that clicked them at 19:05 park now.
- **10.26 candidates:** 37 (17 entry, 17 confirm first, 3 avoid).

### The Companions: companions

Covers C00GiantAttack, the rest of C00, C01-C06, radiants CR01-CR14, and Companions at Mirmulnir.

- **What he can do by voice, end to end:**
  - Proving Honor through Glory of the Dead.
  - The radiant loop is most of what he says. Customizable Companions Questline Progression Requirements asks for 12
    radiant jobs (3 / 5 / 4) before Kodlak's witch task.
  - "Is there any work to be done?" works on the fast path.
  - Speech-to-text damage costs least here: 90% of STT variants still click on the fast path.
- **Where the menu or his hands are still needed:**
  - The C00 quarters walk and the C02 ceremony are scenes with no player line.
  - Drinking the blood (C03), feeding the witch heads to the Flame (C06, CR13) and placing totems (CR12) are
    activations.
  - C05 has 0 dialogue rows.
- **Quarantined (5):**
  - The CR06 and CR07 accepts (0E3077, 0E3009) are graded plain. "can I kill the escaped prisoner?" accepts CR07.
  - CR13 0E3095. "can you help me cure myself?" starts Purity.
  - CR13 "Get up. Did it work?" is released by "did it work for Kodlak?".
  - The Companions at Mirmulnir persuade fires on her key after "isn't Whiterun your hometown?".
- **Resolved at 19:36** (these now park):
  - Farkas's Dustman's Cairn questions
  - Skjor's prepared/test questions
  - the CR01, CR03 and CR04 accepts
  - the CR04 brawl
  - C06, and Kodlak's "who are the Silver Hand"
  - Vilkas's "train me"
- **10.26 candidates:** 0. Most fragments are not loose; Aela's join fragment does three things.

### College of Winterhold: college

Covers MG01 beyond the spec through MG08, the MGR side and radiant quests, Arniel, the apprentices, the ritual spells,
Choose Your Own Arch-Mage, and the College Quest Expansion.

- **What he can do by voice, end to end:**
  - The whole MG line after the first click. The class, Ancano and Quaranir are read-only on the first evening.
  - Many choices ask once: Faralda's 8 school answers and other lines whose answers lead to the same place still
    count as commits (G3). About a quarter of paraphrases cost one question.
- **Where the menu or his hands are still needed:**
  - The Quest Expansion's three "(Skip Quest)" lines are meta, so never by voice.
  - MGR12 and Rogue Wizard are unfinished quests (UESP).
- **Quarantined (6):**
  - CYA Onmund 000863. "no, Onmund, I'm not asking you" hands him the Arch-Mage title.
  - The Caller 0FA21F. "haven't you had enough of this" starts the fight.
  - J'zargo 0F1B29, which is graded plain. "I ran out" fails the quest.
  - Arniel 0E0CF1. "what was next" runs his last experiment.
  - The rejoin fine 0C9A08, 250-1,000 septims paid with no question.
  - Ancano's single 02C8D2. "never mind" auto-advances.
- **Resolved at 19:36** (these park now):
  - the CYA Phinis, J'zargo, Drevis and Nirya questions
  - "can I have Orthorn"
  - the sigil stone
  - Savos and the staff
  - Tolfdir's "Let's get in there."
- **10.26 candidates:** 10, all Quest Expansion lessons, all confirm first. The vanilla MG fragments are in BSAs and
  were not read.

### Thieves Guild: thieves

Covers TG00 beyond the spec through TG09, TGLeadership, TGR jobs, banishment, the Crown, Larceny, TGTQ01-04, fences and
two mods.

- **What he can do by voice, end to end:**
  - Debts, Goldenglow, Honningbrew, Mercer's house, Gulum-Ei, Karliah, and every Vex/Delvin job and turn-in.
  - These are mostly scenes, driven after the first click: TG05 (all 10 beats), TG08A (16 beats, the whole quest), and
    most of TG08B and TG09.
  - TG08B's Karliah intro and outro are EditorID-text branches that the open plays (expect `open`).
- **Where the menu or his hands are still needed:**
  - the ring plant, the urn and the statue
  - the Honningbrew tasting, the Snow Veil ambush and the Cistern
  - the Oath (TG08A 50-57) and the Mercer fight
  - returning the Key and choosing the Agent circle
  - the Guild Master offer, which has no indexed player row
- **Quarantined (13):**
  - Aringoth's "Forget it." 0562FD. LEAVE starts a fight.
  - Rhorlak's "Tell me where it is, or else." 0799DC. "tell me where it is" starts a fight.
  - Brynjolf's "So that's it?" 0AA78C. LEAVE commits TGLeadership 50.
  - The Sell Stones buy-back 00080B takes 1,000 septims with no ask.
  - The TGR quit line 0D785E. "I quit" abandons the job.
  - Eight turn-ins clicked by a question: the wine, the crown, the stones, the locket, the Dainty Sload, Arn's safety,
    and two Larceny sales.
- **Resolved at 19:36:** TG08A's "are you ready?" now parks on the fast path. TGBan's gold line now parks.
- **10.26 candidates:** 1 (Gulum-Ei 0B3893, SetStage 37). The other report lines need a CK read first.

### Civil War, Imperial: cw_imperial

- **What he can do by voice, end to end:**
  - Get Outfitted and The Jagged Crown.
  - Message to Whiterun: "For the jarl's eyes only." 15, "Ulfric means war." 150, then Cipius.
  - Each hold's cycle: "We were victorious!", then "How am I doing?", then "What are my new orders, sir?" (CW 4), then
    Rikke's camp and the mission accept.
  - A False Front (checks), the fort sieges, Compelling Tribute and Kastav.
  - The Windhelm execution is a scene.
- **Voice results here are the lowest of all groups** (37% fast, 25% nothing). Enlistment words, follower verbs and
  negation parity catch the camp wording.
- **Where the menu or his hands are still needed:**
  - Tullius's forcegreet (0C348B SetStage 10). PROTOCOL 10.26 already covers it through the AP line.
  - "What's the mission?", Balgruuf's axe line and Frorkmar's documents are engine-played.
  - Hadvar's report after Kastav completes on his greeting, so it needs the open.
  - The battles are combat.
- **Quarantined (4):**
  - The side switch at Ulfric, 05A6B1 (CW02A 220). "I made a mistake" is EXPLICIT.
  - Kastav's accept 05C614. Both lines are class back, and LEAVE takes whichever is first in a live order nobody has
    proven.
  - Compelling Tribute's accept 0504C4 and blackmail 0E0B93, graded plain by the catch-all pattern row.
- **10.26 candidates:** 34 (10 entry: CW00A 1, CW02A 20 and 30, CW03 15, 50 and 150, CWMission07 30 and 41, CW 4, plus
  CW00A 10 which is already shipped).

### Civil War, Stormcloak: cw_stormcloak

- **What he can do by voice, end to end:**
  - Ulfric and Galmar, the ice wraith, the oath (spec harness), The Jagged Crown and Message to Whiterun.
  - The camp cycle, Neugrad, Compelling Tribute (Raerek) and A False Front.
  - The sieges start with "How can I help?". The Solitude finale is a scene.
- **This group asks the most** (32% ask once). "Reporting in." and "Yes, sir." are commits by escalation (G12).
- **Where the menu or his hands are still needed:** Balgruuf's surrender and Ralof's Neugrad report have no player row.
- **Quarantined (8):**
  - Both siege starts, 065C85 and 065C87. "can you help me?" is EXPLICIT on "How can I help?" and starts the siege.
  - Neugrad's accept 05B379 and Ulfric's crown answer 05F3F9, both by LEAVE.
  - Four Compelling Tribute lines hit by the catch-all.
- **Spec overlap.** CW01B.oath.4's `commit: true` override is still not shipped:
  `config/lrg_dialogue_overrides.default.json` is absent from the 19:36 tree. The spec's own never line "yes, all hail
  the Empire" will be red until it is.
- **10.26 candidates:** 36 (5 entry, 26 confirm first, 5 avoid).

### Dawnguard: dawnguard

Covers both paths and every radiant giver.

- **What he can do by voice, end to end:**
  - Every stage line on both paths, from Isran to Harkon, has at least one natural sentence that reaches it.
  - The radiant starts work only on near-verbatim "What can I do to help?" (G4).
- **Where the menu or his hands are still needed:**
  - Scenes and combat with no player text: Lokil, Harkon's speech, the scroll reading, the proclamation, Redwater
    Spring, Durnehviir's fight, Vyrthur, the assault and the moth ritual.
  - The very first line is dead by voice (Durak, quarantined).
- **Quarantined (16):**
  - Durak 00D8E2. The Volkihar faction row takes the sentence.
  - Harkon's refusal 003BA3. "I don't want to refuse your gift" banishes a player who means yes.
  - Serana's "I'm sure." 004A77. "I'm sure I need to think about it" makes him a Vampire Lord.
  - Serana's dismissal 012EBC is not a follower commit.
  - Urag's 2,000 septims 0126D7. The price is only in the prose.
  - 003C1B. "I don't want to become a vampire" clicks the line that makes him one.
  - Serana's convince line 0098B6.
  - Seven radiant turn-ins and the Jarl's letter (007EC4), each clicked by a question.
  - The vessel line, via the unique-word tie.
- **10.26 candidates:** 0. No DLC1 fragment is loose, so each stage must be read from Dawnguard.esm before any row.

### Dragonborn: dragonborn

- **What he can do by voice, end to end:**
  - MQ01-MQ07 and the Solstheim side quests.
  - 19 main-quest beats are journal scenes: Frea at the temple, the Storn briefing, Hermaeus Mora, Frea's book, and
    Miraak's Yes./No. They are driven after the first click.
  - In this load order the fares are 500 and 750 septims. Severin Manor is sold for 10,000.
- **Where the menu or his hands are still needed:** From the Ashes can only be started by hand. "What's the problem?"
  01AA9A shares its norm with the crime forcegreet (crit 2) and is LETHAL.
- **Quarantined (11):**
  - Seven commits that the verifier's natural probes still click: Bujold's "not yet, I'll keep this quiet later",
    "I can't let you lead Thirsk? says who", Talvas's staff deferral, Mogrul's "I could just kill you now, couldn't I",
    Ralis, Storn's restore line, and Talvas's spell.
  - Three turn-ins clicked by a question: scathecraw, the taproot, and "are my people free?".
  - The From the Ashes start.
- **Resolved at 19:36:** echo questions on the 41 other verifier-marked commits park now, and Frea's "I need a moment"
  parks.
- **10.26 candidates:** 1. The Ebony Warrior's "I have no time for trifles." (SetStage 250) is confirm first, because
  it retires him for good.

### Daedric quests: daedric

Covers DA01-DA16; DA12 does not exist.

- **Voice results:** this group has the most "nothing" (17%). The causes are speech-check text variants, House of
  Horrors - Quest Expansion lines that are not in the index, and lines whose words do not carry.
- **Where the menu or his hands are still needed:**
  - the DA02 sacrifice (the pillar), the DA09 beacon, the DA11 feast and the DA13 incense
  - attacking Erandur (DA16)
  - Peryite's "(Fail Quest)" line, which is meta
- **Quarantined (16):**
  - Two LEAVE clicks with real effects. Boethiah's refusal 0048A5 runs SetStage 8. The Vigilant keep line 000815
    starts combat.
  - The Whispering Door QE secret ending 00082B, graded plain: combat plus SetStage 60.
  - Telling the Jarl, 000D64, which picks a branch.
  - Nine turn-ins clicked by a question: Azura's Star, Shagrol's Hammer, the Hall of the Dead, the ring, the lexicon,
    Peryite's decision, the Pelagius Wing, Silus, and the ears of the wind.
  - Two lines graded service because of the word "drink".
  - Nelacar's persuade fires on her key after a question.
- **10.26 candidates:** 14. They include TCIY Boethiah stages 8 / 9 / 300 / 310 (avoid or confirm first) and the
  Cursed Tribe QE stages 5 / 20 / 35 / 40 / 67 / 210.

### Hold side quests: hold_side

Covers MS13, MS01, MS02, MS14, MS11, MS08, MS09, MS10, MS06/MS06Start, MS04, the rest of MS05, and Forbidden Legend.

- **Where the menu or his hands are still needed:** Forbidden Legend has 0 player rows (the quest is reading and
  reforging), and the fights.
- **Quarantined (17):**
  - Saadia's betrayal chain. The first lie 022E79 is clicked by "are they coming". The horse lie 057FA1 is graded
    service, so none of the S4.5 guards run on it.
  - Stig's bribe 0E4A2E. "how much would it cost" pays <BribeCost> septims on her key.
  - Four commits EXPLICIT on a question or on one shared word: Stig, Adelaisa's plan and job, and Madanach's
    "Understand? How?".
  - Ten turn-ins clicked by a question.
- **Resolved at 19:36:** 7 of the verifier's 11 question-click commits park now: Avulstein, Grisvar, Alva's journal,
  Orthus, Madanach's freedom, the escape "I'm ready", and Kematu.
- **10.26 candidates:** 4 (TCIY: Fralia's accept 10 and refusal 5, Orthus 5 and 10).

### Not mapped by any group

- The Dark Brotherhood after DB01/DB02. A `pt19x-brotherhood` work folder exists, but there is no group file in this
  folder.
- The Bards College beyond MS05.
- Hearthfire, and minor city misc quests.

## Top 20 capability gaps, ranked by how often a player hits them

Each gap gives how often it bites, the evidence (index info keys), and the smallest fix that stays inside the spec. The
`gap` ids are the ones on the fixture's `never_red` rows and on each quarantine entry.

| # | gap | how often | evidence | smallest fix (spec-consistent) |
|---|---|---|---|---|
| G1 | **A refusal, deferral or hedge that quotes a commit clicks it.** `lrgDlgExplicit` has no step-0 refusal test, and the S4.5 `$hit` exemption lets "not now, <the line> later" through on singles. | Every commit layer where he hesitates: **646 of 837 commit beats** on the 19:36 lib. Commits are 41% of all beats. | 0361DC carriage (all gear to Delphine); 0EB7C9 (leaves Sovngarde); 03921D Talvas staff; "I'm sure I need to think about it" on 004A77 (Vampire Lord); "no, Onmund, I'm not asking you" on 000863; "never mind" auto-advances Ancano 02C8D2 | In `lrgDlgExplicit`, run the S4.5 step-0 refusal test first (`lrgDlgRefuses` / `lrgDlgIsBackOut`, the widened hesitation list, a leading no / nope / not now / not yet / never): the same order S4.4 already gives the park. The `$hit` exemption must never outrank a LEADING refusal. |
| G2 | **Speech-to-text damage to the one meaning word loses the fast path.** `lrgDlgWordsCarry` counts a split or misspelled name as a foreign word. | Every sentence with a name: 27% of STT variants click only through her key; 881 utterances were blocked by the precision rail. | "i talked to the gray beards" on 041F10; "i was at hell again" on 0E1AE0 (nothing); hold_side 198 of 418 STT variants; mq3 lost 40% of STT hits at 19:28 | Apply `lrgDlgSttFold` (the G2 fold) against the layer's own strict words before `WordsCarry`: join split tokens ("gray beards", "storm cloaks", "rift in", "is ran") and allow edit distance 1 on the layer's proper nouns, using `lrg_stt_words.txt`. |
| G3 | **Answers that lead to the same place grade as commits, so each costs one extra question.** An Invisible-Continue sibling counts as a scripted sibling. | Very frequent: 17% of all paraphrases ask once. The college verifier measured 185 of 375 commit paraphrases. | Faralda's 8 answers (layer 0AF0DF); 9 hold_side layers (Thonar, Braig, Viola...); dawnguard "Yes. I am married.", "I killed them both."; mq2 0C81D9 / 04C5F1 / 0B94B1 / 05597E | Extend the S4.1 hub rule: a scripted sibling counts only when it branches away, i.e. its links or its Invisible Continue lead somewhere its siblings' do not. Siblings that converge on the same NPC info are one choice, and the layer stays plain. |
| G4 | **Asking for work misses the radiant start.** "got any work?" does not reach "What can I do to help?" and can open barter. | Every radiant: Companions (12 required before C04), Dawnguard RH/RV, Thieves TGR, College "College business". | Dawnguard radiant paraphrases: 136 of 290 resolve. "got any work for me?" CLICKS "What have you got for sale?" on vendor givers (DLC1RH01, DLC1RH06). "any College work" (MGR02/10/11/20) and "got a burglary job" (TGR) click only through her key. | A `work` service kind in `lrgDlgServiceKind` ("any work", "got a job", "anything need doing", "put me to work", "can I help"), mapped to the ONE radiant-start entry of that kind on a root list. This is the existing S5 kind pick. `lrgDlgServiceSense` must not read the "got" in "got any work" as barter. |
| G5 | **A question about an item or person clicks the statement turn-in** on root lists and through the unique-word tie. | Every turn-in conversation: 40 quarantined beats; 254 near-misses click their target. | "where's the crown?" 09DFB4; "is Arn safe?" 07D020; "Where's the ring?" 01F3A3 (hands it over); "who is Thonar" on 0D66D6 "Margret was investigating Thonar."; "where are Potema's remains" on 09E008 (hands them over); "are they coming" on Saadia's lie 022E79; "are all of those ingredients here?" 00D3EC (tie) | Run S4.5 step 0's shape rule on the fast path for every scripted statement entry, root lists included: a question to her never clicks a statement line. `lrgDlgUniqueWordTie` never fires on a question against a statement. |
| G6 | **Follower verbs take quest lines.** "let's go", "wait here" and "follow me" go to the follower arbiter on lists with no follower entry. | Common words: 31 utterances in the maps, 23 of them in hold_side. The spec's own MQ106.delphine.go is hit. | 0485C7 "I'm ready. Let's go trap a dragon."; 04E9B7; CWMission07 "Ready. Let's go." 0DDDC7; TG08B follow/wait | Turn `lrgDlgFollowerOn` on only when the list carries a follower entry or the NPC is his follower. Otherwise the S5 matcher runs. |
| G7 | **Journal-quest scenes are read-only until the first proven click.** | The first evening only, but 311 beats (15%). Dawnguard opens with two scene lists (Durak, Isran); TG05, TG08A and much of MQ204 are scenes. | `expect: scene` beats; `stageB` in the fixture | No new code (S1/S3 by design). The practical fix is to make the first click happen early on a harmless root list ("What have you got for sale?"), as the stage-rail sentence already suggests. |
| G8 | **LEAVE clicks a quest line** whose text contains "nothing", "not sure" or "forget it". Such a line is class back, and `lrgDlgDecide(LEAVE)` takes the first back entry. | Every "never mind" or "goodbye" on those layers. Index-wide there are 885 entry-side back lines, 64 of them scripted and not goodbye. | 0562FD (Aringoth fight); 0048A5 (refuses Boethiah, SetStage 8); 000815 (Vigilant combat); 03F883 (SetStage 30); 05C614 / 05B379 (mission accepts); 05F3F9 (Ulfric 190); 008471 Gunmar | In `lrgDlgDecide(LEAVE)`, skip back entries that are scripted or commit and fall to the S4.2 leave guard. Anchor the entry-side back-out regex to a leading phrase. |
| G9 | **Explicit on one shared word, or question against question.** F1 of 1.0 on one strict word passes as "his own sentence". | Frequent on short lines. | "can you help me?" on "How can I help?" 065C85 / 065C87 (starts a siege); "what was next" 0E0CF1; "I have a plan" on "What's the plan?"; "I understand" on "Understand? How?"; "is there a way out" 0A0D5E; "tell me where it is" 0799DC; "yes she wants you" 06A79B | For explicit, require at least 2 shared strict words, or an exact or containment hit, AND strict-word coverage of at least 0.75 of the entry, so a missing distinguishing clause ("or else", "leave her alone") is not "his sentence". |
| G10 | **Negation parity is blind in both directions.** | Every negated line: 44 utterances in the maps, plus the double negatives. | "I don't want to refuse your gift" clicks the refusal 003BA3; "I don't want to become a vampire" clicks 003C1B; "we don't have to convince the others" 0098B6; "I'll become a vampire" is blocked by the first sentence's negator; "i can kill grisvar without a shiv" (the STT dropped "n't"); "I can't handle it" on 05C614 | `lrgDlgNegationClash`: split clauses on . ! ? as well, compare negators per verb (clause-local), and treat a double negative ("nothing I can't") as positive. |
| G11 | **Echo questions and the `$hit` exemption release commit singles.** | 143 commit beats (91 singles) release on "wait, <line>?" or "is it true that <line>". | "what is the Hall of Valor?" on 04FA45 (trial by combat); "what do I do with the Elder Scroll?" on 0C64EC; Storn 035BCA "...? what stones" | Use the `$hit` exemption only when nothing follows the line and there is no question word of his own. A commit single never releases on a question to her unless the entry is that same question at the exact score. |
| G12 | **Norm-sharing escalation and the layer tier's base row regrade lines using other quests' rows.** | Every "Reporting in." / "Yes, sir." at a camp, and every norm shared across quests. | "Reporting in." is a commit everywhere; Frea's "What do you need?" 026598 is a commit; "Nope" 000803 is parked only because of an unrelated USSEP row; "What's the problem?" 01AA9A is LETHAL, so From the Ashes starts only by hand; 8 hold_side lines | `lrgPromptEscalate` merges flags only from rows of the same quest or topic_key, and never merges crit 2 across quests. `lrgPromptLayerFor` uses the layer's own row, not `$recs[0]`. |
| G13 | **Check rails let a question, or a price question, execute a check on her key.** | Every bribe and persuade layer. The index has 86 bribe, 366 persuade and 135 intimidate rows. | "how much would it cost" on 0E4A2E (Stig) and 00090F (Skjor); "isn't Whiterun your hometown?"; "Who is the priestess of Azura?" on 02450E | A question never executes a check. A price question never executes a bribe. A bribe priced by a token (<BribeCost>) asks once, as cost >= 100 does (S4.10). |
| G14 | **A price outside a "(N gold)" tag is invisible**, so the >= 100 septims ask and the afford rail never run. | Each paid quest step. | 0C9A08 rejoin fine (250-1,000); 00080B buy-back (1,000); 0126D7 Urag (2,000); 0B038E reparation (1,000 per UESP); the troll <Global=DLC1TrollCost> | Parse "for N gold" in the line, or the price in its reply ("It's 2,000 coins."), into `cost` at index build or at lookup. Tokens come from `live_text`. |
| G15 | **One service word misgrades a scripted quest line as a service**, and that also skips S4.5. | Occasional, but the harm is high. | Saadia's betrayal 057FA1 ("horse"); Vilkas's spar 0A3E99 ("train"); Sam's contest 0A95B9 and DA02AltQuest 000880 ("drink"); Razelan 036D50; the "room" keys | A scripted entry is class service only when its topic is a service topic (OfferServicesTopic, RentRoomTopic, trainer and barter topics), never because of one word. |
| G16 | **Hand-over tags are stripped from the norm**, so "here's her journal" shares no word with the line. | Every item hand-over line: 17 beats. | "(Give Journal)" 01667C; "(Give letter)" 007EC4; "(Give Auriel's Bow)" 012F5C; "(Show invitation)"; "(Give fragments)" | At index build, keep the tag's object noun as an extra match word for the entry. It is used for matching only, never shown. |
| G17 | **Steps with no clickable player line**: forcegreet INFOs, greeting completions, Invisible-Continue prompts and EditorID-text branches. | A few in every questline. | Tullius 0C348B (SetStage 10, already shipped in 10.26); "What's the mission?" 01BC8F; Balgruuf's axe 0DA242; Hadvar after Kastav; MQ301's 5 auto-continues; TG08B's intro and outro | The S2 open for greeting completions, plus PROTOCOL 10.26 rows from `scripted_entry_candidates.json` (55 entry, 121 confirm first). |
| G18 | **Faction words take quest lines.** | Once per playthrough, but it blocks the first Dawnguard line. | "Killing vampires? Where do I sign up?" 00D8E2 goes to the Volkihar row; "I want to be a Stormcloak" on Ulfric's crown layer becomes an enlistment ask; 5 mq3 and 11 dawnguard utterances | In `lrg_factions`, the row whose recruiter topic is on this NPC's list wins over a word hit. Add "killing / kill / hunt vampires" to the dawnguard row. |
| G19 | **Irreversible lines graded plain**, alias lines graded by the catch-all pattern row, and the overrides file that was never shipped. | Once each, but each is a trap. | 04DE3C Skuldafn; 05A6B1 side switch; 0F1B29 J'zargo; 00082B secret ending (combat); 0D785E "I quit"; 0E3077 / 0E3009 accepts; 0E3095; 000D64; catch-all hearthfires.esm:003D89 on 0504C4 / 0E0B93; `config/lrg_dialogue_overrides.default.json` is absent from the 19:36 tree, so even Oath4's override is not live | Ship the overrides file with `{match:{info}, commit:true}`, or `never_auto:true` for side switches and fights, for these lines. Drop the catch-all "*" row and run `lrgPromptEscalate` on the pattern tier. |
| G20 | **Serana's dismissal is not a follower commit**, so it never asks. | Every playthrough with Serana, who follows for the whole DLC. | "should we part ways?" is EXPLICIT on 012EBC; "so what do you think?" parks the dismissal on 003C23 | Add `DLC1NPCMentalModelDismissTopic` to `services.follower.verbs.dismiss`. The line is then fcommit and always asks (S4.3 / S4.10). |

**Not a gap, by design:** rewards are fixed. Bargaining sentences click nothing in every group (for example, 24 of 24
in hold_side and 34 attempts in college). The truthful answer is the engine's fixed reward. The real bargaining rows are
listed per beat under `negotiation`: Anuriel's persuade, Urag's buy-back, Dexion's 6,000 septims or free, and Brelyna's
"What's in it for me?".

## For the harness extension (not player-facing)

- **H1. A `siblings` layer form is needed.** 186 beats have no player-text parent: forcegreets and NPC-side parents.
  Section 3.3 cannot name them by `parent_info`. Another 311 have a parent but no index layer line, and are marked
  `form: parent_links`.
- **H2. Root lists need a speaker map.** There are 611 root beats; 233 of them are hand-built approximations
  (dawnguard `root~`, hold_side, and mq2 lists holding rows the NPC cannot have). Every OfferServicesTopic row has
  `toplevel=0`. Root versus closed decides pick versus commit, so 4 mq2 beats are quarantined for this reason (H2).
- **H3. 148 closed beats list entries that differ from the index layer line.** The harness uses the layer line, so the
  margins move.
- **H4. Build entries through `lrgPromptLookup`, never from the target row alone.** Escalation and the pattern tier
  regrade lines. Use `live_text` for alias, global and bribe tokens. My re-check could not decide 5 alias rows, which
  therefore stay quarantined on the verifier's claim.
- **H5. `never_red` rows are expected failures.** There are 1,178: G1 712, G11 253, G5 182 and G9 31. Assert them as
  red until the fix lands, then promote them to `never`.
- **H6. Twin records.** 20 CWMission INFOs appear in both Civil War groups, once for each speaker side (Hadvar or
  Ralof, Rikke or Galmar). Both are kept, with `twin_of`.
- **H7. The Invisible Continue flag is graded `scripted=1`** by `lrgDlgDecorateEntries` (U2). Fixture classes must come
  from the live decoration, which the verified groups already did.

## Quarantine: 107 beats

Each beat below is kept in full under the fixture's `quarantine` list. Each entry also carries `reason` (the
verifier's words), `status_on_current_lib`, `still_clicks`, `release_when` (the smallest fix) and `gaps`. Move a beat
back into `beats` once its fix lands and its `still_clicks` lines stop clicking.

| harm | group | beat | info | gap | what still happens on the 19:36 lib |
|---|---|---|---|---|---|
| high | college | `CYA.onmund.end` | 000863 | G1,G9 | clicks on: "no, Onmund, I'm not asking you" |
| high | college | `MG03.caller.fight` | 0FA21F | G1,G9 | clicks on: "haven't you had enough of this" |
| high | college | `MGRAppJzargo01.outofscrolls` | 0F1B29 | G19 | clicks on: "I almost ran out of scrolls"; "I ran out" |
| high | college | `MGRArniel04.next` | 0E0CF1 | G9 | clicks on: "what was next"; "what's next for you" |
| high | college | `MGRejoin.pay` | 0C9A08 | G14 | clicks on: "here's the gold"; "here is the gold" |
| high | cw_imperial | `CW02A.ulfric.defect` | 05A6B1 | G19 | clicks on: "I made a mistake" |
| high | cw_imperial | `CWMission04.rikke.accept` | 05C614 | G8 | the target is still class back (a scripted/commit line): LEAVE takes the first back line in live order, which is unprove |
| high | cw_imperial | `CWMission07.anuriel.recognize` | 0E0B93 | G19 | clicks on: "look at this"; "what is this amulet?" |
| high | cw_imperial | `CWMission07.rikke.accept` | 0504C4 | G19 | a line on this layer still resolves to the catch-all row hearthfires.esm:003D89 and is graded plain |
| high | cw_stormcloak | `CWMission04.accept` | 05B379 | G8 | the target is still class back (a scripted/commit line): LEAVE takes the first back line in live order, which is unprove |
| high | cw_stormcloak | `CWMission07.accept` | 0504C4 | G19 | a line on this layer still resolves to the catch-all row hearthfires.esm:003D89 and is graded plain |
| high | cw_stormcloak | `CWMission07.show` | 0E0B93 | G19 | clicks on: "look at this"; "what is this amulet?" |
| high | cw_stormcloak | `CWMission07.show2` | 050936 | G19 | recheck inconclusive: the entry text carries an alias/global token (Recognize this? (Show <Alias.PronounObj=Steward> <Al |
| high | cw_stormcloak | `CWSiege.solitude.orders` | 065C87 | G9 | clicks on: "can you help me?"; "can you help me" |
| high | cw_stormcloak | `CWSiege.whiterun.galmar` | 065C85 | G9 | clicks on: "can you help me?"; "can you help me" |
| high | daedric | `DA02.boethiah.reject` | 0048A5 | G8 | LEAVE clicks the target (I'll do nothing of the sort.) |
| high | daedric | `DA08twd.secret` | 00082B | G19 | clicks on: "uh Nelkir didn't make it"; "Jarl, I have some bad news... Nelkir didn't make it" |
| high | daedric | `DA08twd.vigilant.keep` | 000815 | G8 | LEAVE clicks the target (On second thought, I'm going to keep it for myself) |
| high | dawnguard | `DLC1NPCMentalModel.serana.partways` | 012EBC | G20 | the Serana dismissal is still not a follower commit (fcommit=0) |
| high | dawnguard | `DLC1NPCMentalModel.serana.sure` | 004A77 | G1,G9 | clicks on: "I'm sure I need to think about it" |
| high | dawnguard | `DLC1VQ00.durak.signup` | 00D8E2 | G18 | the enlistment hand-off still owns the fast path: want=1 npc=Testnpc: an enlistment was asked and nothing single can be  |
| high | dawnguard | `DLC1VQ02.harkon.refuse` | 003BA3 | G10 | clicks on: "I don't want to refuse your gift" |
| high | dawnguard | `DLC1VQElder.urag.archmage` | 0126D7 | G14 | the price is still invisible to the glue (entry cost 0) |
| high | dragonborn | `MH01.bujold.cantlet` | 01D961 | G1,G11 | clicks on: "I can't let you lead Thirsk? says who" |
| high | dragonborn | `MH01.bujold.quiet` | 01D963 | G1,G11 | clicks on: "not yet, I'll keep this quiet later" |
| high | dragonborn | `MQ05.storn.restore` | 035BCA | G1,G11 | clicks on: "I'll restore the remaining stones if that will help? what stones"; "wait, i'll restore the remaining Stones if that will help?" |
| high | dragonborn | `TT1b.mogrul.kill` | 019576 | G1,G11 | clicks on: "I could just kill you now, couldn't I" |
| high | dragonborn | `TTF1.talvas.spell` | 03921F | G1,G11 | clicks on: "I'd like to learn the Ash Guardian spell? is it any good" |
| high | dragonborn | `TTF1.talvas.staff` | 03921D | G1,G11 | clicks on: "not now, I'll just take the staff later" |
| high | dragonborn | `UN.ralis.punish` | 0275B3 | G1,G11 | clicks on: "I can't let you go unpunished? why would I say that" |
| high | hold_side | `MS08.saadia.lie1` | 022E79 | G5 | clicks on: "are they coming" |
| high | hold_side | `MS08.saadia.lie3` | 057FA1 | G15 | clicks on: "is there a horse for sale" |
| high | hold_side | `MS10.stig.bribe` | 0E4A2E | G13 | the gate picks the target on the model's key after: how much would it cost; how much do you want |
| high | mq2 | `MQ201.party.erikur.truth` | 06A79B | G9 | clicks on: "yes she wants you"; "yes, she wants you" |
| high | mq2 | `MQ201.return.nothing` | 03BC99 | G8 | LEAVE clicks the target (The Thalmor know nothing about the dragons.) |
| high | mq2 | `MQ204.arngeir.nothingtohide` | 03F883 | G8 | LEAVE clicks the target (I have nothing to hide. The Blades helped me find ) |
| high | mq3 | `MQ303.odahviing.skuldafn` | 04DE3C | G19 | clicks on: "why Skuldafn?"; "what's Skuldafn like?" |
| high | mq3 | `MQ304.tsun.enter` | 04FA45 | G11 | clicks on: "what is the Hall of Valor?"; "who can enter the Hall of Valor?" |
| high | thieves | `TG02.aringoth.forget` | 0562FD | G8 | LEAVE clicks the target (Forget it. I'll just open it myself.) |
| high | thieves | `TGCSG.buyback` | 00080B | G14 | clicks on: "buy the unusual gem"; "fine, uh, buy the unusual gem for 1000 gold" |
| high | thieves | `TGTQ01.rhorlak.orelse` | 0799DC | G9 | clicks on: "tell me where it is" |
| med | companions | `CR06.accept` | 0E3077 | G19 | the target is still graded plain (not a commit) |
| med | companions | `CR07.accept` | 0E3009 | G19 | clicks on: "can I kill the escaped prisoner?" |
| med | companions | `CR13.help` | 0E3095 | G19 | clicks on: "can you help me cure myself?"; "will you help me cure myself?" |
| med | cw_stormcloak | `CW02B.ulfric.trouble` | 05F3F9 | G8 | LEAVE clicks another scripted/commit line (Nothing I couldn't handle.) |
| med | cw_stormcloak | `CWMission07.report` | 0DDE89 | G19 | not re-testable offline; unresolved per the verifier |
| med | daedric | `DA01.nelacar.star` | 04C433 | G5 | clicks on: "Where is Azura's Star?" |
| med | daedric | `DA04.septimus.lexicon` | 0E4A32 | G5 | clicks on: "What is a lexicon?" |
| med | daedric | `DA06.atub.ears` | 03BDC3 | G5 | clicks on: "what are the ears of the wind" |
| med | daedric | `DA06.atub.hammer` | 02ACC7 | G5 | clicks on: "What is Shagrol's Hammer?" |
| med | daedric | `DA08twd.telljarl` | 000D64 | G19 | clicks on: "there's a daedra talking to your son downstairs" |
| med | daedric | `DA11intro.verulus.safe` | 0819DD | G5 | clicks on: "Is the Hall of the Dead safe?" |
| med | daedric | `DA13.peryite.decide` | 000816 | G5 | clicks on: "What decision?" |
| med | daedric | `DA14.ysolda.ring` | 01F3A3 | G5 | clicks on: "Where's the ring?" |
| med | dawnguard | `DLC1VQ03Hunter.serana.convince` | 0098B6 | G1,G9 | clicks on: "we don't have to convince the others" |
| med | dawnguard | `DLC1VQ04.serana.becomevampire` | 003C1B | G10 | clicks on: "I don't want to become a vampire" |
| med | dragonborn | `MH02.chief.scathecraw` | 01FE0E | G5 | clicks on: "what is scathecraw?"; "scathecraw?" |
| med | dragonborn | `MQ03.storn.free` | 02066F | G5 | clicks on: "are my people free?"; "wait, your people are free?" |
| med | dragonborn | `TTF1.talvas.start` | 01AA9A | G12 | not re-testable offline; unresolved per the verifier |
| med | dragonborn | `TTF2.elynea.planted` | 01AA97 | G5 | clicks on: "where do I plant the taproot?"; "is the soaked taproot planted?" |
| med | hold_side | `MS01.eltrys.thonar` | 0D66D6 | G5 | clicks on: "who is Thonar" |
| med | hold_side | `MS02.madanach.understand` | 0E162E | G1,G9 | clicks on: "I understand" |
| med | hold_side | `MS02.uraccen.where` | 0DC290 | G5 | clicks on: "who is Madanach" |
| med | hold_side | `MS06.styrr.remains` | 09E008 | G5 | clicks on: "where are Potema's remains" |
| med | hold_side | `MS09.avul.haveproof` | 03AF5F | G5 | clicks on: "is there proof Thorald lives" |
| med | hold_side | `MS09.fralia.safe` | 03D154 | G5 | clicks on: "is Thorald safe" |
| med | hold_side | `MS09.idolaf.where` | 027F56 | G5 | clicks on: "Thorald is alive" |
| med | hold_side | `MS09.justiciar.here` | 0524D3 | G5 | clicks on: "is Thorald Gray-Mane here" |
| med | hold_side | `MS09.thorald.safe` | 03AF78 | G5 | clicks on: "is it safe here" |
| med | hold_side | `MS09.tullius.release` | 0524D5 | G5 | clicks on: "what is Northwatch Keep" |
| med | hold_side | `MS10.adelaisa.job` | 024982 | G1,G9 | clicks on: "what's your job" |
| med | hold_side | `MS10.adelaisa.plan` | 052260 | G1,G9 | clicks on: "I have a plan" |
| med | hold_side | `MS10.stig.where` | 052262 | G1,G9 | clicks on: "who are the Blood Horkers" |
| med | hold_side | `MS11.viola.butcher` | 02166F | G5 | clicks on: "are you the Butcher" |
| med | mq2 | `MQ201Malborn.khajiit` | 053A51 | H2 | not re-testable offline; unresolved per the verifier |
| med | mq2 | `MQ202.flagon.esbern` | 03B69C | H2 | not re-testable offline; unresolved per the verifier |
| med | mq2 | `MQ202.flagon.oldguy` | 03B69E | H2 | not re-testable offline; unresolved per the verifier |
| med | mq2 | `MQ203.karthspire.entrance` | 0A8BEA | H2 | clicks on: "what is Sky Haven Temple" |
| med | thieves | `TG04.gulum.wine` | 0B3896 | G5 | clicks on: "where's the Firebrand Wine?" |
| med | thieves | `TGCrown.vex.allstones` | 09DFB6 | G5 | clicks on: "where are the Stones of Barenziah?" |
| med | thieves | `TGCrown.vex.crown` | 09DFB4 | G5 | clicks on: "where's the crown?" |
| med | thieves | `TGL.brynjolf.thatsit` | 0AA78C | G8 | LEAVE clicks the target (So that's it? There's nothing else to it?) |
| med | thieves | `TGLarceny.LT02` | 027927 | G5 | clicks on: "where can I find the Queen Bee Statue?" |
| med | thieves | `TGLarceny.LT06` | 0C3A2D | G5 | clicks on: "where can I find the Dwarven Puzzle Box?" |
| med | thieves | `TGR.vex.burglary.quit` | 0D785E | G19 | clicks on: "I quit"; "I quit that job" |
| med | thieves | `TGTQ02.erikur.planted` | 07CD34 | G5 | clicks on: "where's the Dainty Sload?" |
| med | thieves | `TGTQ03.olfrid.done` | 07D020 | G5 | clicks on: "is Arn safe?" |
| med | thieves | `TGTQ04.torsten.locket` | 07D674 | G5 | clicks on: "where's the locket?" |
| low | college | `MG04.ancano.understand` | 02C8D2 | G1 | clicks on: "never mind"; "no, never mind" |
| low | companions | `CAM.skjor.persuade` | 00090D | G13 | the gate picks the target on the model's key after: isn't Whiterun your hometown? |
| low | companions | `CR13.didwork` | 0E3005 | G1,G9 | clicks on: "did it work for Kodlak?" |
| low | daedric | `DA01.nelacar.persuade` | 02450E | G13 | the gate picks the target on the model's key after: Who is the priestess of Azura? |
| low | daedric | `DA02alt.heal` | 000880 | G15 | not re-testable offline; unresolved per the verifier |
| low | daedric | `DA07.silus.refuse` | 004E0D | G5 | clicks on: "Why is the blade broken?" |
| low | daedric | `DA14start.drink2` | 0A95B9 | G15 | not re-testable offline; unresolved per the verifier |
| low | daedric | `DA15.wing.access` | 02B8C2 | G5 | clicks on: "What is the Pelagius Wing?" |
| low | dawnguard | `DLC1RH01.gunmar.end` | 005E49 | G5 | clicks on: "who is the vampire masquerading as a bard?" |
| low | dawnguard | `DLC1RH02.end` | 005E47 | G5 | recheck inconclusive: the entry text carries an alias/global token (The <Alias=Boss> at <Alias=Dungeon> has been destroy |
| low | dawnguard | `DLC1RH03.end` | 005E42 | G5 | recheck inconclusive: the entry text carries an alias/global token (I've destroyed the <Alias=Vampire> at <Alias=Dungeon |
| low | dawnguard | `DLC1RH07.jarl.letter` | 007EC4 | G5 | clicks on: "is there a vampire in your court?" |
| low | dawnguard | `DLC1RV01.end` | 004C2B | G5 | clicks on: "who is the Dawnguard masquerading as a bard?" |
| low | dawnguard | `DLC1RV06.end` | 0058C3 | G5 | recheck inconclusive: the entry text carries an alias/global token (It is done. <Alias=Spouse> has been welcomed into th |
| low | dawnguard | `DLC1RV08.end` | 00CDFD | G5 | clicks on: "what are the Rings of Blood Magic?"; "where are the Rings of Blood Magic?" |
| low | dawnguard | `DLC1RV10.end` | 019934 | G5 | clicks on: "who are the Dawnguard leaders?"; "where are the Dawnguard leaders?" |
| low | dawnguard | `DLC1VQ04.serana.vessel` | 00D3EC | G5 | clicks on: "are all of those ingredients here?" |
| low | mq2 | `MQ203.ratway.wayout` | 0A0D5E | G9 | clicks on: "is there a way out"; "is there a way out of here" |
| low | mq3 | `MQ206.paarthurnax.scroll` | 0C64EC | G11 | clicks on: "what do I do with the Elder Scroll?" |

## Scripted-entry candidates (PROTOCOL 10.26)

`scripted_entry_candidates.json` has 194 rows. Each is a scripted line whose attached fragment was read, by a group
researcher or verifier, and is exactly one `SetStage(n)`. Each row carries these fields:
- `entry`: the topic
- `info`: the info_key
- `quest` and `stage`
- `conds_raw`: the index row's conditions, where the index stores them
- `conds.isid`: the speaker, from GetIsID
- `journal`, `toplevel`, `menu_class`, `menu_path`, `meaning` and `words`

Rows are graded by `use`:

| use | rows | meaning |
|---|---|---|
| entry | 55 | Safe as a click-free entry. Examples: CW00A 1 (the soldier's join question), CW02A 20 and 30, CW03 15 / 50 / 150, CWMission07 30 / 41, CW 3 / 4, MQ201 40 / 50 / 110 / 205 / 208 / 210, MQ202 50 / 130, MQ204 20, MQ205 45 / 50, MQ301 15-40 / 220, MS10 5, TG04 37, DA06 5 |
| confirm_first | 121 | A commit in the menu, or a line that asserts a world fact ("It's done. Paarthurnax is dead."). Send it only on his explicit sentence, or after her question and his yes, and only when the fact is on the facts line. |
| avoid | 18 | Quarantined traps: CW02A 220, the CWMission04/07 accepts, MQ303 50, MQ304 140, MQ201Party 40, MQ204 30, DA02 8, and others. |

Notes for the table:
- Fill `notdone = [stage]` so each row is idempotent, and `max = stage - 1` unless a later stage is legal.
- Only one row sets a different quest than the one owning the line: MQ204's College route sets MQ205 30.
- No Dawnguard or Companions rows exist. Their vanilla fragments were not readable from loose files, so each stage
  must be read from the plugin first.
- `extra_from_mq2_table` lists mq2's own table rows (twins such as 03F88A / 03F88E -> 100) that no beat carries.

## Method limits

- **The recheck ran on his words alone**, at clicks_ok = 1, not in a scene, with the danger lines, near-misses, the
  deferral probe and echo probes. It did not re-score every paraphrase in the fixture. The paraphrase outcomes are the
  verifiers' (builds 19:05-19:38). The build is still changing; `say` lines marked `fp: true` should be re-asserted
  once it lands.
- **Her T-key was run through `lrgDlgDecideEntry`** with the entry offered. Two-entry layers of a commit plus a back
  line count as the engine's own confirmation layer, so her key there picks without asking ("are you ready?" on
  05A0C0, "am I prepared for this" on companionstweaks 000D6A). That follows the rule, but the model must not offer the
  key on a question.
- **Alias rows.** Entries whose text carries `<Alias=...>` could not be matched against the live name offline. Those
  verdicts (5 rows) follow the verifier.
