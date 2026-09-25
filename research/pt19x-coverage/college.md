# College of Winterhold: voice coverage map (pt19x, group "college")

**What this is.** Every player line that moves the College questline (MG01 to MG08), its side quests and radiant quests, the Arch-Mage content, and the College Quest Expansion lessons. The Quest Expansion is a mod that is switched on in the Ultra profile. Each line was checked against the menuless v1.0 rules, so the harness can be extended once the build lands. The data is in `college.json`, next to this file.

**How it was measured.** Facts come from the load order's prompt index (Ultra profile, index hash `e756e311…`, 43,280 rows). Each line's real menu layer was rebuilt from the index's `links` and layer rows. Every sentence was then run through the build's **real** decision code, copied read-only on 2026-09-24 at 18:36 while Lane A is still mid-build:

- `lrgDlgMatchText`, `lrgDlgMatchPick` and `lrgDlgUniqueWordTie`
- `lrgDlgNegationClash`
- `lrgDlgExplicit`
- `lrgDlgSingleEntryRelease` and `lrgDlgAdvMs`
- `lrgDlgIsBackOut` and `lrgDlgIsQuestion`

The config defaults were copied the same way. Stage numbers and rewards come from UESP; each quest's page is linked in the JSON. `fx` is recorded only where I actually read a loose fragment script:

- 11 Improved College Entry fragments
- 6 College Quest Expansion fragments whose info IDs map by number
- Quest fragments for MG01, MG02, MGR21, MGR22 and MGRitual05

The vanilla fragment sources are not on disk, so `fx` is `unknown` for the rest. The tools are in `C:\Users\Jordan\AppData\Local\Temp\lrg_test\pt19x-college\`: run `build.py` in WSL; the beat definitions are in `beats_*.py`.

## What was found

- **229 beats across 43 quest groups:**
  - main questline: 116 beats (MG01 to MG08, plus the Elder Knowledge gate bridge)
  - side and radiant quests: 64
  - Choose Your Own Arch-Mage (mod): 9
  - College Quest Expansion (mod): 40
- **The 8 MG01 beats already in the spec harness are not repeated.** The 20 MG01 beats here extend them: Faralda's 8 school answers, the Dragonborn route, the tour skip, and Tolfdir's class answers.
- **Sentences tested:**
  - 1,145 paraphrases (5 per line)
  - 458 speech-to-text-damaged variants
  - 229 near misses
  - 34 bargaining attempts
- **Revealing the Unseen is filed as `mg06` in the index**, lower case. It is MG06 in UESP. The fixture's quest filter must match that exact spelling.
- **Plugins that win rows:**
  - Skyrim.esm and USSEP win most rows.
  - Improved College Entry (`CollegeEntry.esp`) wins 10 lines. These include Faralda's school answers and the tour skip.
  - `Requiem.esp` wins Tolfdir's two ward answers.
  - Immersive Speech Dialogues wins the Caller's persuade line; its success text is only shown when the check will pass.
  - `DragonHunting.esp` wins the heartscale turn-in.
  - `CYA_ChooseYourOwnArchMage.esp` wins Tolfdir's Arch-Mage line "Is there anything I should be aware of?" (MGR30).

## Counts by path

| path | beats | meaning |
|---|---|---|
| pick | 123 | A plain line: clicked when the player says it |
| ask_once | 74 | A commit: she asks once, unless his own sentence is explicit (S4.3) |
| scene_read_only | 25 | Said inside a journal-quest scene: read-only until the first proven click, then driven |
| check | 3 | Persuade lines under the check rails. The Caller (MG03), Mirabelle's easy Speech check for the Augur (MG04), and Enthir's check in Onmund's Request |
| service | 2 | Urag's shop, and Enthir's fence |
| scripted_entry_candidate | 1 | MG01 tour skip. Its fragment was read: its whole effect is `SetStage(50)`, nothing else |
| cannot | 1 | MGR12 Filling Soul Gems: UESP lists it as incomplete, so its start line never shows |

**What the paraphrases do under the real code.** Of the 1,145 paraphrases:

| outcome | count | share | note |
|---|---|---|---|
| clicked at once | 764 | 67% | by the matcher, an explicit sentence, a single-entry release, the automatic advance or a check |
| clicked only if the model picks the line itself | 133 | 12% | includes 3 persuade lines the model must pick |
| she asks once first | 218 | 19% | |
| nothing | 28 | 2% | 23 refused as back-outs, 5 on the incomplete quest |
| wrong line clicked | 2 | 0.2% | |

Of the 458 speech-to-text variants, 424 are clicked and 28 are asked first. In the owner's style (one word mangled, the rest intact), they stay above 0.55 against their own line in every case. **65 of the 229 near misses would be clicked by the fast path**; gap 1 below explains why.

| group | beats | pick/service | ask_once | scene | paraphrases clicked at once / asked first / nothing | near misses that click |
|---|---|---|---|---|---|---|
| MG01 First Lessons | 20 | 5 | 9 | 6 | 54 / 40 / 6 | 2 |
| MG02 Under Saarthal | 12 | 8 | 4 | 0 | 46 / 12 / 2 | 2 |
| MG03 Hitting the Books (+1 check) | 15 | 10 | 4 | 0 | 60 / 12 / 3 | 3 |
| MG04 Good Intentions (+1 check) | 30 | 12 | 6 | 11 | 124 / 23 / 3 | 9 |
| MG06 Revealing the Unseen | 19 | 11 | 6 | 2 | 78 / 16 / 1 | 6 |
| MG05 Containment | 9 | 5 | 0 | 4 | 36 / 7 / 2 | 4 |
| MG07 The Staff of Magnus | 4 | 1 | 3 | 0 | 13 / 6 / 1 | 2 |
| MG08 The Eye of Magnus | 4 | 1 | 1 | 2 | 13 / 7 / 0 | 1 |
| Elder Knowledge gate bridge (MGMainQuestBridge) | 3 | 2 | 1 | 0 | 12 / 3 / 0 | 1 |
| Side, radiant, ritual and Arch-Mage quests (24 groups; +1 check, +1 cannot) | 64 | 53 | 9 | 0 | 296 / 17 / 7 | 27 |
| Choose Your Own Arch-Mage | 9 | 4 | 5 | 0 | 35 / 10 / 0 | 3 |
| College Quest Expansion (8 groups) | 40 | 14 | 26 | 0 | 130 / 65 / 5 | 5 |

## Capability gaps, most often hit first

**1. A question about a listed line clicks that line.**
- **What happens:** the fast path has no question/statement shape test on lists with more than one entry, and neither does the explicit-sentence test (S4.3). S4.5's step 0 shape test covers single-entry lists only.
- **How often:** 65 of 229 near misses click, and players constantly ask NPCs about the very names on the list. 54 were clicked by the plain matcher, 10 by the explicit test on a commit, and 1 by a single-entry release. For example, "is Winterhold safe" reports "Winterhold is safe for now." (0.870), and "who is Gavros" clicks "Who are you?" (0.783).
- **The costly ones:**
  - "have you had enough" is explicit on "I've had enough of this." and starts the Caller fight.
  - "was that easy" is explicit on Phinis's "That was easy..." and **hands him the Arch-Mage title**.
  - "what was next" is explicit on Arniel's last experiment, "So what's next?". A single shared word gives an F1 of 1.0, and the 3-word floor for exact hits accepts that as exact.
  - "is reading a waste of time" skips the Reading lesson.
  - "do you need a sigil stone" hands the stone to Phinis.
  - "do you know where the staff is" is explicit on Savos's "I know where to find the Staff of Magnus".
- **Fix direction:**
  - Apply S4.5's step 0 shape test to the fast path and inside `lrgDlgExplicit`.
  - Count a score of 1.0 as "exact" only when the normalised texts are equal or one contains the other.

**2. Story options that lead to the same place are treated as commits.** She asks "do you mean …?" before lines that change nothing. Two different rules cause it:

- **(a) The "branch away" rule (S4.1).** A scripted option counts as branching away even when its siblings link to the same next layer. This hits 17 main-path lines:
  - Faralda's 8 school answers (MG01)
  - Tolfdir's 2 ward answers (MG01)
  - The Augur's 2 layers, 4 lines (MG04)
  - Paratus: the Gavros/Synod pair and the "I haven't done anything, I swear" row (MG06)

  **Fix direction:** count a scripted sibling only when its links lead somewhere its siblings' links do not.
- **(b) Scripted + goodbye on every option.** Every option has the same effect, so the question adds nothing. This hits 9 main-path lines:
  - Tolfdir's 3 opinion answers (MG01)
  - Tolfdir's 2 answers after Savos dies (MG05)
  - Faralda's 2 in Winterhold (MG05)
  - Estormo's 2: both start the same fight (MG07)

  Also 26 of the 40 College Quest Expansion lines, where every lesson answer is scripted + goodbye. **Fix direction:** a data override (`commit: false`) for rows whose siblings are all equivalent.
- **How often:** on lists with more than one entry, 185 of the 375 commit paraphrases (49%) cost one question. About 20 of these lines come up in every College playthrough.

**3. A line that is itself worded negatively cannot be paraphrased.**
- **What happens:** the widened back-out list refuses any sentence that starts like a refusal, even when the line on the menu starts that way too.
- **How often:** 27 genuine paraphrases and speech-to-text variants were refused, about 5 to 8 lines per playthrough. Examples:
  - Saying Ancano's own line "I don't understand what's going on." word for word is **refused, and the automatic advance is cancelled** (S4.5 step 0 runs before anything else).
  - "no more questions, what do you want me to do" (MG02, the assignment line)
  - "no wards"
  - "not really, there was this girl called Adara" (the line itself starts "Not really")
  - "I don't know that spell", said to "I'm not familiar with that spell"
  - "no time, Winterhold first"
  - "no comment"
- **Fix direction:** skip the refusal test when the line itself carries the same negator (negation parity already exists in `lrgDlgNegationClash`), or when the sentence matches the line at 0.85 or more.

**4. Three irreversible lines click without asking.**

| line | info_key | what it does | why it does not ask |
|---|---|---|---|
| "I ran out of scrolls." | `skyrim.esm:0F1B29` | Fails J'zargo's quest | Top-level scripted with no goodbye flag, so S4.1 calls it plain |
| "I am serious. It's yours if you want it." | `skyrim.esm:0DA18C` | Sells the Elder Scroll to Urag for 2,000 septims (UESP) | The effect sits in a follow-up reply that has no player text |
| "Here's the gold you wanted." | `skyrim.esm:0C9A08` | Pays the 250, 500 or 1,000 septim fine to rejoin the College | The index cost is 0; the price is only in the parent reply |

- **How often:** once or twice per playthrough, but nothing can undo them.
- **Fix direction:**
  - Give these three rows `commit: true` overrides.
  - Read the price from the parent reply's "(N) gold" into the line's cost.

**5. Scene lines wait for the first proven click.**
- **What happens:** 25 beats sit inside journal-quest scenes, where nothing is driven until a real click has been verified on this install:
  - Tolfdir's class: 6 lines
  - Ancano: 5
  - Quaranir's time-stop scene: 6
  - Savos at the barrier: 2
  - After Savos dies (Tolfdir 2, Faralda 2): 4
  - Quaranir at the end: 2
- **How often:** once per install. If First Lessons is the first thing played after install, Tolfdir's class is clicked by hand.
- **Uncertain:** UESP does not say whether Faralda's Winterhold talk (MG05) is a scene or a forcegreet; I treated it as a scene.

**6. The wrong line gets clicked (2 of 1,603 sentences).**
- **Cases:**
  - "I've never learned a ward" clicks "I have a ward spell, but I've never really used it." (the Requiem ward layer)
  - "can you teach me more Destruction" clicks the Quest Expansion's "You teach Destruction, right?" instead of the ritual question. Two mods' Destruction lines share Faralda's list, and the unique-word tie picks the wrong one.
- **Related risk:** the Quest Expansion's Reading quiz lists four answers of the same shape. The margins go as low as 0.162, so one misheard word can pick a wrong answer.

**7. The harness fixture cannot name some layers.**
- **What happens:** 18 beats on 13 menus (mostly engine forcegreets, such as Tolfdir trapped at Saarthal, Orthorn, Estormo and the Dremora) have a parent line with no player text. There is no layer row for them, so the spec's required `parent_info` form cannot name their layer. I set their siblings by hand (`sibs`). The fixture needs a `siblings` form.
- **Related:** many rewards and stages sit in no-prompt replies. Examples are Savos's staff (`skyrim.esm:0263D3`) and Mirabelle's amulet (`skyrim.esm:09BB89`). The harness should check the stage or the item, not the line that was clicked.

**8. Almost no click-free quest-entry candidates can be proven.**
- **What happens:** only two lines are known to do nothing but one stage change: the MG01 tour skip (`SetStage 50`) and the Quest Expansion's "waste of time" (`SetStage 25`).
- **Why:** the vanilla fragment sources are not on disk. Getting them means decompiling the game's compiled scripts, which is new tooling and needs your approval (workflow rule).

**9. The same words appear on different quests.**
- **Examples:**
  - "Is there any College business I can assist (or help) with?" opens radiants for Drevis, Sergius, Urag and Arniel: 10 index rows across MGR02, MGR10, MGR11, MGR12, MGR20, MGR20B and MGRArniel01.
  - "Is there anything I should be aware of?" starts both Aftershock and Rogue Wizard.
  - "What do we do now?" appears three times: MG08 stage 10 (plain), stage 40 (commit), and Quaranir (commit, single entry).
  - "You mean me, don't you?" appears on two consecutive MG04 layers.
  - "Did you see that?" appears in two MG02 topics.
- **What to do:** the fixture must pin the stage for these, because the matcher cannot tell them apart.

**10. Rewards are fixed.** Each of the 34 bargaining attempts is either left to the model or asked about first; none is clicked by the fast path. The one exception is Brelyna's real "What's in it for me?" row, which is correct: she answers with the game's own line.
- **Real bargaining lines that exist:**
  - Brelyna: `skyrim.esm:0C04EB`
  - Arniel, part 3: `skyrim.esm:06A03D`
  - Enthir, Arniel part 4, "What sort of payment…": `skyrim.esm:06A03F`
  - Enthir in Onmund's Request, "What if I were to pay you…" (he refuses): `skyrim.esm:0C1E54`
  - Nirya, Research Thief (incomplete quest): `skyrim.esm:05D2E7`
- **Everything else:** she must say the reward is fixed (verdict 9). Each reward line's actual reward is recorded in `negotiation[].truth`.

**11. Lines that contain the player's name.** Two Quest Expansion greetings include `<Alias=Player>`, which the index stores as `<t>`. Saying your own name scores 0.48 to 0.65, so these lines depend on the model picking them.

**12. The noon lectures.** Improved College Entry runs a daily lecture scene from 12:00 to 13:00 (`MGCollegeLectures`) for Drevis, Faralda, Colette and others. During it, the glue opens their list only on a narrow marker.
