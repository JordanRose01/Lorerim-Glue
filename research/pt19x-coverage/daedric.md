# pt19x coverage - Daedric quests (DA01-DA16)

Machine file: `daedric.json` (same folder). Index: `prompt_index.ndjson` hash `e756e311aefaeab54a1184d609adb77b`
(37,561 rows, profile Ultra). Work folder: `%TEMP%\lrg_test\pt19x-daedric\` (`gen.py`, `beats_*.py`, `extras.py`, `score2.php`).

**How the numbers were made.** Every score comes from the real v1.0 functions, extracted read-only from
`glue/server/lorerim_glue/lib`. The copy was taken on 2026-09-24 at 18:11, while Lane A was still editing. The functions
are `lrgDlgMatchText`, `lrgDlgMatchPick`, `lrgDlgExplicit`, `lrgDlgSingleEntryRelease`, `lrgDlgNegationClash` and
`lrgDlgRefuses`, and they run with `lrgDlgDefaults()`. Each line gets its grade (plain, commit, back, check, pay, meta)
from a line-for-line Python port of `lrgDlgClass`, `lrgDlgIsCommit` and the hub rule of `lrgDlgDecorateEntries`.

Each line is scored against its real layer. For a sub-menu, the layer is the index LAYER line, deduplicated by topic
and by text, so a success/failure pair shows as one entry the way the engine shows it. For a top-level line, the layer
is the NPC's known quest lines.

Only the fast path is modelled. The model's T-key pick is not run.
- `resolve`: the fast path clicks the line.
- `ask`: she asks once first.
- `nothing`: the fast path clicks nothing. On a plain line the model may still pick it.

## One-page summary

**Quest IDs.** The group is 15 EditorIDs, not 16. DA12 does not exist. "Azura's Star" and "The Black Star" are one
quest, DA01, with two endings: stage 100 (Aranea) or stage 110 (Nelacar). The IDs map as follows:

| ID | Quest | ID | Quest | ID | Quest |
|---|---|---|---|---|---|
| DA01 | Black Star / Azura's Star | DA06 | The Cursed Tribe | DA11 | The Taste of Death |
| DA02 | Boethiah's Calling | DA07 | Pieces of the Past | DA13 | The Only Cure |
| DA03 | A Daedra's Best Friend | DA08 | The Whispering Door | DA14 | A Night to Remember |
| DA04 | Discerning the Transmundane | DA09 | The Break of Dawn | DA15 | The Mind of Madness |
| DA05 | Ill Met By Moonlight | DA10 | The House of Horrors | DA16 | Waking Nightmare |

The quests start through these lead-in IDs: DA01FIN, DA02AltQuest, DA03Start, DA07intro, DA07Key, DA07Reforge,
DA10PreQuest, DA11Intro, DA11FIN, Da13Intro and DA14Start. In total the index holds 655 DA* rows.

**Mods that own the dialogue on this load order:**
- TheChoiceIsYours.esp rewrites Boethiah's conduit talk and Silus's job offer, and adds a refusal to Lod.
- The Cursed Tribe - Quest Expansion (TCT-QE) plus its LoreRim patch rework Largashbur and add a spell or scroll cure.
- The Whispering Door - Quest Expansion (TWD-QE), with its Wintersun patch and WSN addon, adds three new steps:
  telling Balgruuf, facing Nelkir with the blade, and giving the blade to the Vigilants.
- SilusJournal_Dialogue / SilusJournal_ReforgeRazor add a shrine-key path and a way to reforge the Razor without Dagon.
- TheOnlyCureQuestExpansion adds a decision hub, a reward talk and attack lines.
- BoethiahCalling_AlternativeQuest adds DA02AltQuest.
- MoiraFollowerMod adds the option to marry the hagraven.
- Delayed-start mods own the openers of DA10, DA11 and DA15.
- Immersive Speech Dialogues and LoreRim - Dialogue Patch replace the TEXT of the success or failure variant of 6 speech
  checks, so the menu words depend on the player's Speech.
- House of Horrors - Quest Expansion has loose scripts on disk but **no rows in the index** (see gap 11).
- The TasteOfDeath addon has its own questline, `madNamiraAddonQuest` (32 rows). It is out of the vanilla scope and is
  not mapped here.

**Scripts read.** These loose fragments were read, and each `fx` value comes from them. Every other `fx` is `unknown`,
and its stage comes from UESP.
- TCIY `tif__010048a6/a5/a7/010083f4`: SetStage 9, 8, 300 and 310. Also `tif__000973a3` (SetStage 11) and
  `tif__01004e0f/0e` (SetStage 13).
- TCT-QE `TCTRC_TIF__0003BDCC` (20), `0B000063` (35), `0003BDC5/C0/C4` (40), `0B000914` (67), `0B000915` (210), plus
  `TIF__0004601F/00046023` (Persuade and SetStage 30).
- TWD-QE `TIF__01000D9A` (43), `01000D8C` (44), `04000812` (blade to the Vigilant, then 45), `04000815`
  (SendAssaultAlarm + StartCombat) and `01000D86` (reward item).
- Bring Meeko to Lod `tif__000e0d47/000d7933`: SetStage 10 plus the meat and 25 gold.

**Beats mapped: 210.** 205 have a player line and 5 are `cannot`. In total 1,914 utterances were scored: 6 paraphrases
and 2 speech-to-text variants per beat, a near miss for each of the 205 real beats, and 23 bargaining lines. Stages
cite UESP quest pages, linked per quest in the JSON. Scene and forcegreet status comes from UESP walkthroughs plus
forcegreet topic names in the index. It is **inferred, not read from the plugin**.

### Counts by path (210 beats)

| path | beats | natural paraphrases: resolve / ask / nothing | STT variants: resolve / ask / nothing |
|---|---|---|---|
| pick | 61 | 318 / 8 / 40 | 115 / 2 / 5 |
| ask_once | 75 | 287 / 120 / 43 | 132 / 18 / 0 |
| scripted_entry_candidate | 14 | 59 / 19 / 6 | 27 / 1 / 0 |
| check | 29 | 149 / 3 / 22 | 55 / 0 / 3 |
| scene_read_only | 26 | 120 / 29 / 7 | 50 / 1 / 1 |
| service | 0 | - | - |
| cannot | 5 | 0 / 0 / 30 | 0 / 0 / 10 |

- Scene lines: the numbers show what happens **after** this install's first proven click. Before it, every scene
  line gets "choose it on the list yourself".
- **Real lines overall:** 933 of 1,230 natural paraphrases resolve (76 %), 179 ask (15 %) and 118 click nothing (10 %).
  379 of 400 STT variants resolve.
- **Near misses:** 164 of 205 click nothing, as they should. **20 click the target line** and 21 make her ask (see
  gap 1).
- **Entry candidates:** the 14 `scripted_entry_candidate` lines are the ones whose read fragment is exactly
  `SetStage(n)`. In the menu, 13 of them are commits and 1 (TCIY `0048A5`) is back-class.

| quest | beats | pick | ask_once | entry cand. | check | scene | cannot | paraphrases resolving |
|---|---|---|---|---|---|---|---|---|
| DA01 (+DA01FIN) | 19 | 5 | 8 | 0 | 4 | 2 | 0 | 82/114 |
| DA02 (+AltQuest) | 17 | 5 | 7 | 4 | 0 | 0 | 1 | 76/102 |
| DA03 (+DA03Start) | 14 | 4 | 9 | 0 | 1 | 0 | 0 | 62/84 |
| DA04 | 8 | 3 | 5 | 0 | 0 | 0 | 0 | 35/48 |
| DA05 | 11 | 2 | 4 | 0 | 0 | 5 | 0 | 47/66 |
| DA06 | 17 | 3 | 6 | 5 | 3 | 0 | 0 | 78/102 |
| DA07 (+intro, Key, Reforge) | 24 | 7 | 5 | 3 | 6 | 3 | 0 | 123/144 |
| DA08 | 14 | 8 | 3 | 2 | 1 | 0 | 0 | 69/84 |
| DA09 | 5 | 0 | 0 | 0 | 0 | 4 | 1 | 18/30 |
| DA10 (+PreQuest) | 13 | 2 | 3 | 0 | 4 | 4 | 0 | 66/78 |
| DA11 (+Intro, FIN) | 16 | 3 | 3 | 0 | 5 | 4 | 1 | 69/96 |
| DA13 (+Da13Intro) | 13 | 6 | 6 | 0 | 0 | 0 | 1 | 42/78 |
| DA14 (+DA14Start) | 17 | 4 | 9 | 0 | 4 | 0 | 0 | 63/102 |
| DA15 | 9 | 4 | 0 | 0 | 1 | 4 | 0 | 47/54 |
| DA16 | 13 | 5 | 7 | 0 | 0 | 0 | 1 | 56/78 |

**Negotiation (23 reward and priced lines).** Every reward in this group is fixed, and she says so. None of the 23
bargaining utterances clicks its reward line. Only one reaches a line at all: "Double the pay and I'll do it" parks on
TCIY's pay line and asks first.

Four layers carry a real "what about my reward" line:
- TCIY `thechoiceisyours.esp:004E0E`: "I'll do it, but you better be willing to pay me for my efforts." It is
  SetStage 13 only; the pay stays the vanilla per-piece reward.
- TOCQE `theonlycurequestexpansion.esp:000829`: "About that reward...". It leads to accept, accept-but-not-your-puppet,
  or refuse Spellbreaker. None of them changes the item.
- Erandur `skyrim.esm:07EA23`: "How about some compensation?" He answers that he has no gold and offers his
  companionship instead.
- Complaint lines that change only the reply: `0AC992` "A mace? This is hardly a fitting prize." and `089425` "That's a
  lot of work for such a little thing...".

Priced lines (fixed, always ask): Ennis 1000 gold (`0C4204`) and Ysolda 2000 gold (`0C4208`). A lower named amount is
words only.

## Capability gaps, ranked by how often a player hits them

1. **A question about a line's subject clicks the line.** This happens on every multi-entry and top-level list. S4.5's
   shape rule (a question to her never clicks a statement) exists only for single-entry layers. It is not applied on
   the fast path or in `lrgDlgExplicit`, so the question just has to share words with the line. All 20 near misses that
   click are questions. Players question every Daedric prince, so this comes up in almost every conversation here.
   Four of them are irreversible:

   | question | line it clicks | layer | score | why it matters |
   |---|---|---|---|---|
   | "Who is Molag Bal?" | TCIY `0083F4` "To honor Lord Molag Bal..." | conduit | 0.705 | EXPLICIT on a commit: sets DA02 stage 310, the Molag Bal branch, with no question |
   | "What are your reasons?" | `060513` "My reasons are my own." | conduit | 0.783 | explicit commit |
   | "Who is Molag Bal?" | `0DEE78` Logrolf intimidate | Logrolf | 0.87 | fires the speech check |
   | "Who is the priestess of Azura?" | `02450E` Nelacar persuade | Nelacar | 0.783 | fires the speech check |

   Other examples:
   - "Where is Azura's Star?" clicks the turn-in `04C433` (0.87).
   - "Is the Hall of the Dead safe?" clicks the report `0819DD` (0.907).
   - "Where's the ring?" clicks `01F3A3` "Here's the ring." (0.85).
   - "What is Shagrol's Hammer?" clicks `02ACC7` (0.87).
   - "What is the Pelagius Wing?" clicks `02B8C2` (0.721).

   **Fix:** apply S4.5 step 0's shape test to every fast-path pick and to `lrgDlgExplicit`, so that a question never
   clicks a line that is not itself a question.

2. **The Daedric princes speak in engine-opened scenes: 26 beats (12 %).** These are Azura, Hircine (all five of his
   beats), Mehrunes Dagon, Meridia (all four), Molag Bal, Namira, Eola and Sheogorath. They are read-only until the
   first click is proven on this install. After that, 120 of 156 paraphrases resolve. The scene flags come from UESP,
   not from `sj` in the plugin, so a Scene-record audit is needed before the harness asserts `sj=1` on these beats.

3. **Commits that ask even when the player said the line (75 beats; 120 of 450 paraphrases on them only park).** Most
   Daedric talks are two or three scripted goodbye answers, and S4.1's sibling rule makes each one a commit. That is
   correct for the kill/spare and accept/refuse pairs. It over-asks on:
   - Questions and interchangeable openers: Aranea's three openers (`029F0B`), Barbas's "You were looking for me?"
     (an Invisible Continue sibling counts as scripted, `07EAE0`), Silus's "A courier gave me this invitation"
     (`0B82EB`), Tyranus's "Why are you asking?", "What did Malyn do?" (`024394`), "Tell me about this incense."
     (`089982`), and "Here we go." at the drinking contest (`0A95B7`).
   - "Tell me about this incense." can never be explicit: "Tell me about Peryite." sits on the same list at 0.783, so
     the margin stays below 0.25. All 6 paraphrases ask.

4. **Two back-class lines that the leave path clicks, both with real effects.** The shipped back-out regex matches the
   text, so a "never mind", "goodbye" or LEAVE on these layers clicks the line:
   - TWD-QE `000815` "On second thought, I'm going to keep it for myself." Its fragment runs SendAssaultAlarm and
     StartCombat, so the Vigilants attack.
   - TCIY `0048A5` "I'll do nothing of the sort." (the word "nothing"). Its fragment is SetStage(8), which refuses
     Boethiah. The vanilla cultist's "I'll do nothing of the sort" (`04D8AF`) has the same class but is harmless.

   **Fix:** a `never_auto` / `class: plain` override for both, or let the leave path skip back lines that are scripted.

5. **A consequential line graded plain.** TWD-QE `00082B` "Nelkir didn't make it." is top-level, scripted and not
   goodbye, so it clicks on the first sentence. Its reply is "Guards, trespasser!", and the mod ships
   `DA08_SecretEnding1/02.psc` (combat, stage 60). **Fix:** a `commit: true` override.

6. **Five steps have no dialogue line, so the menu driver cannot do them:**
   - DA02: the pillar sacrifice.
   - DA09: placing the beacon.
   - DA11: "Eat" on Verulus's corpse.
   - DA13: inhaling the incense.
   - DA16: killing Erandur for the Skull.

   Each is an activation or combat. She must say so in words, per S7.

7. **The words of a speech check depend on the player's Speech (6 checks).** The patches rewrite the success or
   failure variant:
   - Nelacar: "A priestess of Azura sent me" or "I'm the emissary of Azura! Heed me!"
   - Jorgen: "It's only going to bring this place misfortune..." or "You won't miss it, then."
   - Jorgen's bribe: "Money will do you more good than the hilt..." or "I'll pay for the hilt."
   - Jorgen's intimidate: the failing variant is a `(Brawl)` commit.
   - Logrolf ×2 and Verulus: similar success/failure splits.

   The live text always decides, so the matcher is fine. The fixture has to carry both variants, and each is its own
   beat here: `.low` marks the failing text. A player who says the other variant's words matches weakly.

8. **"Molag Bal. (Intimidate)" (`0DEE78`) is two words.** The check rail wants at least 4 spoken words, so the verbatim
   line, "uh Molag Bal" and every STT variant ("molag ball", "mole a ball") click nothing. "Molag Bal sent me" works.

9. **STT damage on negated lines and names.** The index turns "don't" into "don", but this owner's STT writes "dont":
   - "i dont want to help you" scores 0.783 against Mephala's refusal `10EBC2` but loses the margin, so it clicks nothing.
   - "you want miss it then" loses its "won't". Negation parity then blocks it, correctly.
   - Proper-noun lines fail on the name: "fork you know I'll be careful" (Falk) and "i have as your as star" (0.675,
     no click).

   Of the 400 STT variants, only 9 click nothing, but they cluster on short lines built around a name.

10. **Fixture hazards for Lane E:**
    - Hermaeus Mora's rows `03277B` and `032784` have an **empty topic name** in the index, so they must be targeted
      by `info_key`. So do their siblings `03277C`, `03277D`, `04A378` and `04A379`, and Dragonborn.esm's
      `038A70/71`.
    - Boethiah's "I have slain <Alias=DeadFriend>..." (`04D8BA`) carries an alias token. Its live text holds the
      follower's name; the scores here use "Lydia".
    - 29 top-level beats have `root` lists that contain only the target, because the NPC's other topics at that moment
      cannot be known offline. Their margins are optimistic, and each is marked `layer_note`.
    - DA15 Dervenin's "What do you need?" and "Why do you need him back?" are ONE topic (`DA15AboutQuest0`), and the
      engine shows only one of them.

11. **House of Horrors - Quest Expansion has no rows in the index.** Its folder holds `HouseOfHorrorsQuestExpansion.esp`
    and 43 loose `.psc` files. Among them: `HOH_FragmentFinishQuest.psc` pays 1000 gold and sets stage 210;
    `HOH_AmuletOKFragment.psc` sets stage 51; `HOH_AltarOnHit.psc` moves the quest from stage 52 to 53. The plugin name
    appears nowhere in `prompt_index.ndjson` (grep count 0), and the index header reports 3,496 of 3,496 active plugins
    read. So the plugin is most likely not active in Ultra. If it is active, its DA10 dialogue is unindexed. Worth one
    check before promising DA10 by voice. This researcher did not open MO2.

## Notes

- Three unscripted single-entry lines advance by themselves 2.5 s after her line (`auto_advance`, S4.6): Sinding
  "Why are you imprisoned here?", Sheogorath "So does that mean you'll leave?", and Erandur "I found the Torpor."
- The stage rail does not affect this group. A player reaches the Daedric quests long after the first proven click.
- `stage` fields cite the UESP stage table and say "inferred" where a line's exact stage is not documented.
- `fx` is `SetStage <n>` only where the fragment was actually read, and `fx_src` names the file.
