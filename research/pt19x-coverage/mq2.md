# pt19x coverage: main quest part 2 (mq2)

**Scope.** Every player line that advances or branches these quests after The Horn of Jurgen Windcaller:

- A Blade in the Dark (**MQ106**), minus the 5 beats the spec harness already has
- Diplomatic Immunity (**MQ201**, plus **MQ201Party** and **MQ201ThalmorEmbassy**)
- Malborn's Windhelm follow-up (**MQ201Malborn**)
- A Cornered Rat (**MQ202**), including Brynjolf's TG00 branch and the DGIntimidate brawl win
- Alduin's Wall (**MQ203**)
- The Throat of the World (**MQ204**)

Elder Knowledge (MQ205) and Alduin's Bane (MQ206) belong to the mq3 group. The data is in `mq2.json` in the same folder.

**Method.** Every row comes from the live prompt index (Ultra, hash e756e311…, 37,561 rows, 5,718 layer lines). There are 113 dialogue beats plus 5 steps that are not dialogue.

- **Layers.** Taken from the index layer lines. Root lists, and the one parent with no layer line (the carriage, 0A75F2), are hand-built and marked in the JSON.
- **Fragments.** For every scripted line, `fx` comes from reading the fragment itself. The script name is taken from the **winning plugin's** INFO VMAD. The .pex is then disassembled from wherever it wins: `Skyrim - Misc.bsa`, `Unofficial Skyrim Special Edition Patch.bsa`, `GORE.bsa`, or a loose .pex (Storm the Thalmor Embassy, Defeat the Dragon Cult).
- **Stage meanings.** From the QUST VMAD stage→fragment maps, plus UESP (sources are in the JSON).
- **Scoring.** 1,022 utterances were scored with the real matcher (a read-only copy of `lib/`): `lrgDlgMatchText`, `lrgDlgMatchPick` at 0.55/0.15, `lrgDlgSingleEntryRelease`, `lrgDlgNegationClash`, `lrgDlgClass`, and the S4.3 explicit test. The copy reproduces the spec's [M] numbers (0.783 / 0.328 / 0.907 / 0.838 / 0.941).

**LoreRim specifics that change the quests:**

- **Storm the Thalmor Embassy** replaces the MQ201 quest fragment and TIF__000361DF. You can pick the gate lock (→ stage 170, no dialogue). Malborn's "I'm ready. Here's what I'll need." now opens an inventory menu.
- **Defeat the Dragon Cult** replaces TIF__0003FA49. Paarthurnax's "How does any of this help me?" sets MQ204 200 **and** the DC quest to 15.
- **Immersive Speech Dialogues** rewrites the persuade success texts. The engine shows either the success text or the failure text, so only one of them is on screen at a time.
- **GORE** adds follower-only fragments to two MQ201 rows.

## What the player gets (fast path, offline)

| | resolve on the fast path | she asks first (park / which) | the model decides | nothing |
|---|---|---|---|---|
| 565 paraphrases | 403 (71 %) | 88 (16 %) | 65 (the T-key or the S4.6 breath) | 9 |
| 226 STT-damaged | 194 (86 %) | 20 | 8 | 4 |

- **The quest's own words.** The verbatim line (scored as `verbatim` on every beat) resolves on 109 of 113 beats. The other four:
  - Two are 2-token commits that park: "What's wrong?" (Iddra 0C81D8) and "I'm ready." (Malborn 0362CB).
  - Two are refused: 03A1CF and 03BC9A (gap 4).
- **Near-misses.** Each beat has one near-miss that must not click anything. **33 of 113 click anyway** (gap 1).
- **Reward lines.** Only one reward line exists in the group: MQ201Malborn 04CBA0 → stage 150 → one `LootBanditGoldBoss` leveled gold roll. The bribe price on Vekel or Dirge is the engine's `<BribeCost>`. Delphine, Esbern, Arngeir and Paarthurnax pay nothing, and no layer has a "what about my reward" line. The truthful answer is always that the reward is fixed; the JSON `negotiation` field quotes it per beat.

## Counts by path

| path | beats | examples |
|---|---|---|
| scene_read_only | 41 | Delphine's basement talk (MQ106), her Kynesgrove / stables / return forcegreets, Esbern's lecture, the Arngeir intro scene, the whole Paarthurnax talk |
| ask_once | 25 | carriage "Keep the rest of my things safe" (all gear to her chest), the Frostfall passphrase, Erikur's lie/truth, Karthspire together/meet, "The Thalmor assassin is dead" |
| pick | 21 | "So where are we headed?" variants, "I found Esbern.", "We need to talk.", "It's not hopeless, Esbern. I'm Dragonborn." |
| scripted_entry_candidate | 13 | "Our mutual friend sent me." → 50, "I need to talk to you." → 110, Etienne's lines → 205/208/210, Arngeir → 20/50, Esbern at Karthspire → 150 |
| check | 11 | 5 party persuades, the Solar trickery, Vekel/Dirge persuade/bribe/intimidate/brawl, Esbern's door persuade |
| service | 2 | "I'd like a drink." (free brandy), "Here, I brought you a drink." (Razelan) |
| cannot | 5 | Malborn's gear container, Esbern's locked door, the Karthspire blood seal, Clear Skies, Fire Breath at Paarthurnax |

- **Journal scenes.** Once the install has proven one real click, which MQ101-MQ106 guarantees by this point, the 41 scene beats are driven as: 23 pick, 9 single, 7 explicit, 2 auto.
- **Entry candidates.** 51 beats have an `entry_candidate` fragment (exactly one SetStage and nothing else). The `entry_table_useful` list in the JSON narrows these to the journal-advancing ones.

## Capability gaps, ranked by how often a player hits them

1. **A question about the topic clicks the quest line.** The shape step ("a question to her is never clicked") exists only in S4.5 single-entry release. The multi-entry fast path, root lists and the S4.3 explicit test have no shape test, and neither do S4.5 steps 3 and 4 (unscripted singles, and scripted singles whose entry is itself a question). **33 of 113 near-misses click:** 18 plain picks, 4 explicit commits, 3 persuade checks, 6 single releases and 2 wrong siblings. The owner talks over open menus all the time. The worst cases:
   - "are my things safe with you" → explicit on 0361DC at 0.721 / 0.498. Stages 95→100 then run `RemoveAllItems` into Delphine's chest and the carriage leaves.
   - "are you ready" → explicit on Malborn's 0362CB at 1.000. The leave-party scene starts.
   - "how do I defeat Alduin" → explicit on 016F9B. Esbern's lecture is skipped (stage 250).
   - "is there a way out" → explicit on 0A0D5E (Esbern's Ratway escort).
   - "are you the Jarl of Solitude", "is the party dull", "does everyone appreciate you" → each fires its party persuade check.
   - "where is the College of Winterhold" → sets MQ205 30 (the College route instead of the Esbern/Arngeir route).
2. **Five required steps are not dialogue:**
   - the inventory menu after Malborn's line (TIF__000361DF `OpenInventory`)
   - Esbern's locked door: the conversation opens only on an Activate press
   - the blood-seal activator (MQ203 stages 180/200)
   - Clear Skies at the wind walls (MQ204 stages 83→90)
   - Fire Breath at Paarthurnax (stages 130/135)

   Every playthrough hits all five. Voice cannot do them, and she should say so in words.
3. **Harmless answers ask first.** S4.1 grades equivalent scripted siblings that leave the layer as commits:
   - Paarthurnax's three intro lines (all SetStage 100)
   - Arngeir's "It was recorded on Alduin's Wall." and "The Blades helped me find out about it."
   - MQ201Malborn "Tell me about this Thalmor assassin"

   2-token commits never pass the explicit test ("What's wrong?", "I'm ready."). In total 88 paraphrases (16 %) park or ask which, and each costs one extra "yes" turn. Every player hits Arngeir and Paarthurnax.
4. **Lines that start like a refusal cannot be said.** S4.5 step 0 runs `lrgDlgRefuses` on the utterance without comparing it to the entry, and a T-key goes through the same step. Two stage lines are affected:
   - "No time to explain. Let's get out of here. (Free him)" (03A1CF → 205)
   - "I don't know, but the Thalmor are looking for someone named Esbern." (03BC9A → 240)

   Only a rephrase without the lead ("let's get out of here") reaches the model. Across the index, 1,118 of 21,148 distinct lines (420 of them scripted) are refused when spoken verbatim.
5. **Negation parity false alarms.** Question inversion clashes with a negated entry: "what aren't you telling me" against D1 0328FE, and "aren't you coming with me" against 041E15. The owner's STT also writes "no" for "know": "no of an old guy…" on Vekel 03B69C, "hold on you may no something important" on 03A1CE, "do you no the way out of hear". In all of these the fast path refuses, and only the model can pick.
6. **Quest lines graded as back-outs.** The entry-side back-out regex matches "nothing" mid-sentence, so these are class `back`:
   - "The Thalmor know nothing about the dragons." (03BC99)
   - "I have nothing to hide. The Blades helped me find out about it." (03F883, SetStage 30)

   A leave request clicks them instead of leaving. 67 lines index-wide are affected.
7. **Opposite statements release unscripted singles.** S4.5 step 3 needs only one shared strict word:
   - "the Thalmor are behind the dragons" releases "The Thalmor know nothing about the dragons."
   - "close the door" releases "Open the door."

   Low harm, because these are the only way forward, but the click contradicts his words.
8. **Stop-word-only differences give F1 1.0.**
   - "what is an Elder Scroll" → "I have the Elder Scroll." (MQ206 line on Paarthurnax's list) at 1.000
   - "is Esbern here" → the generic "What do you do here?" at 0.783
   - "yes she wants you", meant as the lie, clicks the truth line 06A79B
9. **Harness data holes.**
   - Root lists need a speaker map: the index exports condition details on only 1,445 of 37,561 rows, so this file hand-builds root lists.
   - The carriage parent 0A75F2 has no layer line.
   - The Flagon layer line 06C862 merges condition-exclusive variants: persuade success and failure texts, and both Vekel's and Dirge's intimidate and brawl lines. Offline margins are therefore lower than in game ("tell me or I'll beat it out of you" 0.870, margin 0.033).
10. **Scene read-only.** 41 beats sit inside journal scenes or forcegreets. They are harmless after the first proven click. EsbernOpenDoorScene may still be running when "We need to talk." is first offered.
11. **Label only.** "Here, I brought you a drink." is class `service` because of the word "drink", although it is a quest gift that removes the brandy. The market module does not intercept it (`lrgDlgServiceKind` returns '').
