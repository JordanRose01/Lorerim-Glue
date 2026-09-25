# pt19x coverage: Dawnguard (DLC1VQ00-DLC1VQ08, both paths, DLC1 radiant givers)

Data file: `research/pt19x-coverage/dawnguard.json`. It has 263 beats in 46 quest EditorIDs, plus 9 `cannot` notes and 13 ranked findings.

## One page

- **What was measured.** I checked every player line that advances or branches the Dawnguard questline, on both paths, and every radiant giver. Each line got 5 paraphrases, 2 speech-to-text variants in the owner's style and 1 near-miss. That is 1,841 utterances in total. Each one was scored with the real `lrgDlgMatchText`, `lrgDlgSingleEntryRelease` and `lrgDlgNegationClash` against the line's own layer. The functions are verbatim read-only extracts of the build as of 2026-09-24 18:11. The harness reproduces the spec's own [M] numbers: 0.783, 0.328, 0.675, 0.850 and 0.593.
- **Results.** Paraphrases: 841 resolve, 125 ask, 349 nothing (223 of the "nothing" results are ones the model's T-key may still pick). STT variants: 419 resolve, 54 ask, 53 nothing. Near-misses: 261 of 263 click nothing. One parks (asks) and one clicks (findings 10 and 11 below).
- **Where the lines come from.** Almost all rows are won by Dawnguard.esm. The exceptions are USSEP (33 beats), CFTO (3), LoreRim - Dialogue Patch (2: the carriage persuades), Immersive Speech Dialogues (2), GORE (1: Isran's "We have to do something") and Skip Vampire Lord Tutorial (1).
- **Main and side quests versus radiants.** Main and side quests do well: 705 resolve, 105 ask and 215 nothing across 1,025 paraphrases. Radiants do badly: 136 resolve, 20 ask and 134 nothing across 290. Every radiant starts with the same engine line, "What can I do to help?", and players do not say it that way (finding 1).
- **No fatal holes.** At least one natural sentence reaches every stage-moving line of both paths, from Durak through Harkon. Three lines need a hand-click or an odd wording today: Gunmar's recruit line (finding 3), "I'll become a vampire" (finding 4) and the "Understood." briefings (finding 2).
- **Stages.** Stage numbers come from UESP (URLs are in the JSON under `sources.uesp`). Every `fx` is `unknown` because no vanilla DLC1 fragment is on disk as a loose file. The only fragment I actually read is DLC1VQ00's (The Choice is Yours): Fragment_7 completes objective 10 and calls `DLC1VQ01MiscObjective.SetStage(10)`.
- **Negotiation, per the spec.** The engine's reward is fixed and she says so. No main-quest line pays septims, and Harkon's "reward" is the gift. The real bargaining lines are:
  - Urag's buy-back of the Elder Scroll: 4000 (always asks), 3000 by persuade, 2000 for the Arch-Mage.
  - Dexion's scroll sale after the war: 6000 septims or free (UESP).
  - Falmer tomes: a fixed 1000. The engine's own "Depends how much you're paying" does not change the price.

## Counts by path

| path | beats | paraphrases resolve / ask / nothing |
|---|---|---|
| pick | 111 | 378 / 0 / 177 |
| ask_once | 58 | 151 / 80 / 59 |
| scene_read_only | 47 | 152 / 36 / 47 (at clicks_ok=1; before the first proven click every one is nothing) |
| scripted_entry_candidate | 27 | 92 / 5 / 38 |
| check | 15 | 55 / 0 / 20 |
| service | 5 | 13 / 4 / 8 |
| cannot | 9 notes | scenes and combat with no player text: Lokil, Harkon's speech, the reading, the proclamation, Redwater Spring, Durnehviir's fight, Vyrthur, the assault, the moth ritual |

By class: 116 plain, 48 commit, 31 commit single, 15 single, 25 auto, 15 check, 7 walk-out, 4 service, 2 pay.

By layer: 57 closed (the index layer line), 24 closed* (keyed by an NPC info), 71 single, 13 hand-built closed, 3 links, 1 single~, and 94 root~. The root~ lists are approximate: I built them from the NPC's index rows, so margins on those beats are indicative only.

## The line through the questline (both paths)

| quest (UESP) | stage-moving lines (info key, path) |
|---|---|
| Dawnguard (DLC1VQ00 / DLC1VQ01MiscObjective) | Durak forcegreet 00D8E2 "Killing vampires? Where do I sign up?" (scene); Isran 00D901 / 00D8FA join (scene, commit); 010EB9 "What can I do to help?"; 00D8ED "What is it?" (Awakening 10) |
| Awakening (DLC1VQ01 / RNPC) | Serana's wake scene 0038F6, 00B678, 00B676, 00B669 "Where do you want to go?"; 019F14 "check back with Isran" branch; vampire variant 011973 |
| Bloodline (DLC1VQ02) | Harkon's scene 003BA0 / 011992; reward question 01198E; **accept 003BA1 (stage 40) / refuse 003BA3 (stage 30)**; Isran debrief 010DDA, 019812 |
| A New Order (DLC1HunterBaseIntro) | Isran 018C6D, 018CDD, 0076CC, 0076D1; Sorine 008474, 008470, 00F818 gyro, 00F7C8 persuade, 003D1B, 008479; Gunmar 008475, 008473, **008471 (class 'back' bug)**, 0088F6, 0088F4 |
| Bloodstone Chalice (DLC1VampireTutorial / BaseIntro) | Harkon 019075, 010F89; Garan 005943, 00A36F, 005E30 |
| Prophet, Dawnguard (DLC1VQ03Hunter) | Serana 0098BE, 0098B6 / 00FFE1; Isran's scene 0098B4, 013771; 0098B7 "Who can?"; Urag 006A9C, 0069F6; innkeeper 0069F0, 0069ED, persuade 0069E8, bribe 0179E9; carriage 0179F7, 0179F1, 0179EC; Dragon Bridge 006AA1; Dexion 006A9B, 0069EF, 0069E7; Isran 006B84; Dexion 006AE4, 006A79; Serana rejoin 01219D |
| Prophet, Volkihar (DLC1VQ03Vampire) | Harkon 006534; Serana 0069AC; Urag, innkeeper and carriage twins; Dexion 007B83, 007B82; Harkon 008861, 013707; Dexion 008864, 008862; Harkon 008858; Serana 007B80 |
| Chasing Echoes (DLC1VQ04) | Serana 003C29 ... 003C3E; 012191 secret entrance; 01329E moondial; 01667C journal (Give Journal); 00CCC7, 00CCCA, 017678 / 017679; portal 00D3EC, 00D3E2, 01218A, 00D3E9; 003C19, 003C1F; **vampire 003C1B, 00D3EA, 00D3E3, 00D3E0 / soul trap 00E87C, 00E872, 00E880** |
| Beyond Death (DLC1VQ05, Post) | Valerica 005867 ... 008A5A / 014744 (stage 30); 0076F1, 010678, 0076EA, 01AA81 (stage 50); 005864, 010A53 (stage 110); 008386; soul 00E9E1, 00E9DD; 011579; post 015A00, 0159FD, husks 015A12 |
| Durnehviir, Impatience of a Saint | 01156C, 011572, 01157D, 01157B, 01157E; Jiub 014161, 01417B, 014159, 014164 (Locket + Opus) |
| Scroll Scouting / Seeking Disclosure | Dexion 0181BD, 0181BC; 0181BE; Urag buy-back 0126D4, 0126D2, 0126D5 (3000 persuade), 0126D1 (4000), 0126D7 (2000 Arch-Mage), 0126D8; Dexion 005D9B / 005D95, 005D9F, 0137BE / 0137BD |
| Unseen Visions (DLC1VQ06) | Serana 015FA4 (scene), 015FB2, 015FA5 |
| Touching the Sky (DLC1VQ07, Post) | Gelebor 00A876, 002B42, 00A86E, 00A871, 002B3F (stage 50); 002B3A, 00A864; prelates 002B40 "Yes." (x5); 0071AB, 007063 (bow); arrows 015A65, 004235 |
| Kindred Judgment (DLC1VQ08, Post) | Serana 00F35F, 00F35D, 019FA5, 019F97 / vampire path 019F9D, 019FA7, 019FA9; Isran 019305, 019F94, 019F8F; Harkon's scene 00F35A, 019FA1 / 019F96, 019FAC, **019FA6 "Never." / 012F5C "Very well. (Give Auriel's Bow)"**; Dexion 012F4D, 012F53, 012F50 (6000), 012F4F (free); Garan 012F8C |
| Serana, Radiants | Cure 004A1C; turn 004A79, 004A77; follow 01218D; dismiss 012EBC. Router 002F1A; RH01-RH08 and RV01-RV10: start, "Understood.", report; checks at the RH04 friend and the disguised vampire; troll purchase 01056C; Falmer tomes 01A3D9, 01A3DF, 01A3DE |

## Gaps, ranked by how often a player hits them

1. **Radiant work ask (every radiant, repeatable).** Players say "got any work for me?", "anything you need done?" or "any jobs?". The engine line is "What can I do to help?".
   - "got any work for me?" lands on **"What have you got for sale?"** through the shared word `got`. That happens on every vendor giver (Gunmar, Florentius, the court vendors) and opens barter as a plain pick.
   - Only near-verbatim wordings resolve: 1 of 5 per start beat, 223 T-key-only results overall.
   - Suggested fix: a `work` kind beside S5's service kinds. "work / job / task / anything I can do / need help" would map to the one "What can I do to help?" row on a root list. `got` alone should not carry a pick.
2. **"Understood." is a walk-out on 7 radiant briefings** (RH01, RH08, RV06-RV10). It is the briefing's walk-away target, so under S4.10 it always asks. "understood" (itself an assent word) parks it, she asks, and "yes" clicks it. "got it", "I understand" and "consider it done" click nothing (11 ask / 24 nothing of 35). Suggested fix: an acknowledgement-only walk-away target should release on an assent, like a single.
3. **Entry-side back-out misfire.** `lrgDlgClass` calls `lrgDlgIsBackOut($e['text'])`, which has an unanchored `not sure|nothing|never mind`.
   - Gunmar's recruit line **"We're not sure, but they have an Elder Scroll."** (dawnguard.esm:008471, scripted+goodbye, A New Order -> 45) becomes class `back`.
   - The fast path never executes it: "they have an Elder Scroll" gives nothing, and the single-entry release does not allow `back`.
   - A model LEAVE ("never mind") clicks it and advances the quest.
   - The same happens to Sorine's 00846E and Serana's 015FA1 "Never mind that... it worked!".
   - Index-wide there are 885 entry-side back lines, and 64 of them are scripted and not goodbye. Example: MQ204 "I have nothing to hide. The Blades helped me find out about it."
4. **Negation parity ignores sentence breaks.** `lrgDlgClauseStarts` breaks clauses on `, ; :` and dashes, but not on `. ! ?`. In "I don't see another way. I'll become a vampire." (003C1B), the "don't" from the first sentence negates "become vampire", so **"I'll become a vampire"** (0.85 containment) is blocked. Every player who chooses to be turned hits this. Only the verbatim line and its STT copy resolve.
5. **"we're" folds to "were", which counts as a question word.** `lrgDlgFoldTokens` plus `lrgDlgLeadsQuestion` read every "We're ..." line as a question, whether it is an entry or the player's own sentence. 50 index norms start with "we're", 7 of them in DLC1.
   - Sorine's single "We're meeting at Fort Dawnguard" rejects the statement "meet us at Fort Dawnguard".
   - Gunmar's "We're up against vampires" releases on "are you afraid of vampires?".
6. **Dawnguard opens inside scenes.** 47 beats (18%) sit in engine-opened journal-quest conversations: Durak, Isran, Serana's wake, Harkon's court and gift, Isran's interrogation, the freed Dexion, Harkon's final scene and Durnehviir. On an install with no proven click yet, the very first Dawnguard lines are read-only.
7. **Landing on a sibling.** Two cases (once each):
   - "take them, they're yours" parks **"6000 gold, and they're yours"** (the sale), not the free gift 012F4F.
   - "how can I help" picks Gelebor's "How did you know?", because `how` is not a shipped stop word.
   - Negation parity rescued a third case: "where should I take you" versus "I wasn't told where to take you".
8. **1-2 token commits can never be explicit**, because an exact 1.0 needs 3 tokens. These are "I'm ready." (the portal), "Never." and "Very well." (Harkon), "You first.", "I'm sure." (Serana turns you) and "Deal.". Each one always parks and needs a "yes". It is safe, at one extra round trip, and every playthrough hits "I'm ready" and "Never".
9. **Hand-over tags are stripped from the norm.** "(Give Journal)", "(Give letter)", "(Give Book)" and "(Give Auriel's Bow)" disappear, so "here's her journal" shares no word with "Do you think this would help?". Jarl 007EC3 and 007EC4 (with and without the letter) have the same norm; the conditions make them exclusive.
10. **Report lines click on a question.** A scripted top-level turn-in is a plain pick, so "who are the Dawnguard?" clicks "I've killed the Dawnguard leaders" (0.567) and completes DLC1RV10. Suggested fix: require statement shape for scripted top-level lines on the fast path.
11. **The join layer parks on a faction question.** "what is the Dawnguard anyway?" parks "I'm here to join the Dawnguard" (0.61). It never clicks, but her reply quotes the join line.
12. **Effects are unknown.** 27 scripted-entry candidates (radiant starts and ends, Dragon Bridge, Isran's "Is everything ready?", Harkon's "other scrolls") have `fx` unknown. A PROTOCOL 10.26 entry table first needs the stage-to-fragment map read from Dawnguard.esm.
13. **Fixture inputs.** Bribes carry a `<BribeCost>` token and the troll a `<Global=DLC1TrollCost>` token, so the fixture needs `live_text`. The 94 root~ beats need the real, condition-filtered root lists.

Not covered (not in this group's brief): Lost to the Ages (DLC1LD_*), the Soul Cairn husk merchant, hireable dogs, Surgery, Redwater Den, DLC1WE* encounters, Aela's lycanthropy regift, and SeranaCureQuestPlus.

## Method notes

- **Index rows.** I read all 1,225 DLC1 rows from `prompt_index.ndjson` (hash e756e311..., 37,561 rows). Layers come from the index layer lines, or from the parent row's `links`.
- **How expectations were set.**
  - Multi-entry layers: fast pick at 0.55/0.15 (a scripted target also needs a shared word), explicit per S4.3, negation parity on the entry that would be clicked, and class `back` never executed.
  - Single layers: the build's own `lrgDlgSingleEntryRelease`.
  - Commit singles that do not release: `ask`.
  - `tkey` marks a result the model may still pick.
  - Scene beats also record `expect_before_first_click: nothing`.
- **Placeholders.** The player's name is written as "Aldric". Radiant aliases are filled with plausible live names (Bloodlet Throne, Movarth's Lair, Whiterun, Ysolda, Brenuin ...).
