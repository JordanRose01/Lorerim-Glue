# pt19x coverage - Dragonborn (DLC2MQ01-MQ06 + the major Solstheim quests)

Machine file: `dragonborn.json` (same folder). Index: `prompt_index.ndjson`, hash `e756e311aefaeab54a1184d609adb77b`, profile Ultra.
Every score comes from the build's own v1.0 functions, run on a read-only copy of `glue/server/lorerim_glue/lib` + `config`
copied 2026-09-24 ~18:40: `lrgDlgDecorateEntries` (class, commit, hub), `lrgDlgMatchText`, `lrgDlgMatchPick` (0.55/0.15),
`lrgDlgExplicit`, `lrgDlgSingleEntryRelease`, `lrgDlgLayerIsOwnConfirmation`. The purse is 20,000, so money never blocks a line.
Work files are in `%TEMP%\lrg_test\pt19x-dragonborn\` (`beats_mq.py`, `beats_side.py` → `score.php` → `assemble.py`).
Only the fast path is run. When a line reaches `expect` only through the model's T-key, `via` says `model`.

## One-page summary

- **Scope.** 151 beats in 24 story quests. 53 are main quest: the cultist ambush, Gjalund, Raven Rock, the Temple, the
  Skaal, Cleansing the Stones, Neloth, Apocrypha, the summit and the epilogue. 98 are side quests: March of the Dead,
  Served Cold, The Final Descent and An Axe to Find, Reluctant Steward, A New Debt, Old Friends, From the Ashes, Healing a
  House, Lost Legacy, A New Source of Stalhrim, Filial Bonds, Retaking Thirsk, The Chief of Thirsk Hall, Unearthed and The
  Ebony Warrior. Each beat has 5 paraphrases, 2 speech-to-text-damaged variants and 1 near miss. That is 755 + 302 + 151
  utterances, all scored on the real sibling layer.
- **Corrections to the brief.**
  - UESP lists seven main quests, not six. **Cleansing the Stones (DLC2MQ03B)** sits between MQ03 and MQ04.
  - Its Storn rows, and several MQ04 and MQ05 rows, are filed under the index quest **DLC2MQ00**.
  - Gjalund's crossing lines live in **DLC2DialogueRavenRock**. Adril's dock greeting lives in **DLC2RRArrivalScene**.
    Both quests have journal 0.
  - The `quest` field in the JSON is the story quest. `index_quest` is the owner in the index.
- **Mods that change what the player can say.**
  - Severin Manor Has A Price.esp wins Morvayn's reward INFO. The manor is **no longer part of the reward**: Cindiri sells
    it for 10,000 septims (`severin manor has a price.esp:00080C`).
  - The Choice is Yours rewrites An Axe to Find. It adds "Sure." / "I don't have time for this." and a refusal line for
    Crescius. It comments out the only Dragonborn fragment in the load order that has a loose source:
    `DLC2_TIF__02020A0A`, `;GetOwningQuest().SetStage(15)`. So "Can't you just get the guard to find Crescius?" does
    nothing now.
  - EbonyGetLost.esp adds a way to turn the Ebony Warrior away.
  - Immersive Speech Dialogues and the LoreRim - Dialogue Patch win several persuade and intimidate success INFOs. Their
    text drops the "(Persuade)" / "(Intimidate)" tag that the failure INFO still shows.
- **fx.** Only that one TIF source exists. Every other `fx` is `unknown`, and every `stage` comes from UESP (cited per
  beat) or from a quest-fragment comment where one was read (RR01, RR02, RR03Intro, Ebony).
- **What the player gets (driven session, clicks_ok >= 1).**
  - Of 755 paraphrases, 585 click (resolve), 139 are confirmed once (ask) and 31 do nothing.
  - 609 of the 755 act on the fast path. 115 click or ask only through the model's T-key, and the other 31 are the
    "do nothing" outcomes.
  - 690 of 755 match the outcome a player would want.
  - Speech-to-text damage: 268 of 302 damaged variants still act on the fast path, and 34 rely on the model.
  - Near misses: 145 of 151 do not click the line (5 click and 1 asks). Four of the six are one kind of failure (gap 5).
- **Main-quest reality.** 19 main-quest beats are **journal scenes**: Frea at the temple (x6), the Storn briefing (x4),
  Hermaeus Mora (x6), Neloth's greeting, Frea handing over Waking Dreams, and Miraak's "Yes./No.". Until one real click
  is proven on this install, the Temple, Skaal and Apocrypha conversations are "choose it on the list yourself".
  Everything else in the main quest can be driven the first evening, apart from the stage rail on non-plain lines.

## Counts by path

| path | beats | examples |
|---|---|---|
| pick | 52 | "Do you know someone called Miraak?", "Your people are free.", "Ildari is dead." |
| ask_once | 45 | Gjalund's fare (250), Talvas spell/staff, Bujold quiet/can't-let, Ralis's 1,000-5,000 septim fund lines |
| scripted_entry_candidate | 26 | "What do we do now?" (starts MQ03B), "Where were they headed?" (starts SV02), "Thanks for the tip." |
| scene_read_only | 19 | Frea x6, Storn x4, Hermaeus Mora x6, Neloth greet, Frea's book, Miraak's Yes/No |
| check | 8 | Gjalund persuade/intimidate, Mogrul "take half", Drovas, Ancarion x3, Ralis |
| service | 1 | "I'd like to book passage to Solstheim." (ferry) |
| cannot | 0 | every advancing line has player text in the index. Deathbrand and the Black Book quests have no player lines at all, so they are not beats |

64 targets are graded commits by the build. 5 are unscripted singles that advance on their own after 2.5 s.
4 lines need live text for a token price: DLC2CostToSail, DLC2CostToSailx2, ANDR_SeverinManorPrice.

## Gaps, ranked by how often a player hits them

1. **Journal scenes are read-only until the first proven click.** 19 beats, and every main-quest player hits them
   early. Dragonborn opens its big conversations inside scenes: Frea, then Storn, then Hermaeus Mora. On a fresh install,
   the first proven click must come *before* the Temple of Miraak, or the whole meeting has to be clicked by hand. This is
   by design (S4.10). The owner page should say so for Dragonborn by name.
2. **Invisible Continue makes flavour choices into commits.** 10 beats, plus the scripted-goodbye flavour layers:
   - the cultists' three answers
   - Adril's three dock answers
   - Tilisu's three accusations
   - Neloth's "The Dwemer?" / "Just tell me where the book is"
   - "I hear you know where to find Black Books"
   - Hermaeus Mora's price line
   - Veleth's help/for-a-price
   - Tharstan's "something dangerous"

   The build grades an Invisible-Continue entry as scripted (capability map U2). So these layers have 2-3 "scripted
   siblings", and each line is a commit, **although every sibling continues to the SAME next line**. Examples:
   Adril → 03924A, Tilisu → 01F293, the cultists → 035457, Crescius → 0209D8, Neloth D1/D2 → 025068,
   Tharstan → 01AA78.

   On these 10 beats, 23 of 50 paraphrases are confirmed once instead of clicking, on lines that commit to nothing.
   For example: "yeah that's me, I'm the Dragonborn" and "sure, I'll give you a hand".

   Across all 64 commit targets, 139 of 320 paraphrases ask once and 118 click as explicit.

   Proposed rule (one pass over `links`): siblings whose links are identical form a **converging** layer, and no sibling
   of it counts toward the commit rule.
3. **Leading "no / not / I don't / hold on / forget it" is refused as a back-out.** It also refuses content sentences:
   - 10 of 755 paraphrases are refused at step 0: 8 as a back-out and 2 by the shape test. Examples: "no more ash spawn",
     "not yours", "no body" and "I don't have it on me right now, give me some time...".
   - "hold on, ... who exactly are you" is refused because it has no `?`, and speech-to-text often drops the `?`.
   - Frea's own line starts with "I'm not really sure. I saw Miraak on a dragon." Its natural paraphrase, "I don't know, I
     saw Miraak riding a dragon", is refused.
   - "don't worry, ... I destroyed the Ash Guardian" is refused **as a question**, because `dont` is on the question-lead
     list in `lrgDlgLeadsQuestion`.
4. **Negation parity does not split sentences.** This is a bug, and it affects every group. `lrgDlgClauseStarts` breaks
   clauses only on `, ; :` and dashes, not on `. ? !`. So when an entry has a negation only in its FIRST sentence, a clean
   paraphrase of the second sentence reports a negation clash:
   - "That's not enough. I need to stop Miraak now." against "I need to stop Miraak now" → clash = true.
   - With a comma instead of the period → false (verified on the copy).

   **569 of 21,451 distinct entry texts** in the index have this shape: "I'm not sure. I need to think about it.",
   "No deal. Take the axe...". The clash only blocks the content steps, so these lines fall to the model.

   A second trigger: speech-to-text hears "know" as "no". "i hope you no what your doing" clashes against Storn's book line.
5. **A question ABOUT a report line clicks the report.** Four near misses click, and a fifth misses the same way (see
   below). Examples:
   - "are my people free?" → "Your people are free."
   - "where is Ildari?" → "Ildari is dead."
   - "where do I plant the taproot?" → "The soaked taproot is planted."
   - "what is scathecraw?" → "I brought your scathecraw."

   S4.5's step 0 (a question must not click a statement) exists only for single-entry layers. A fast pick on a root or
   closed list has no shape test.

   Also: "I need a moment" clicks Frea's lone root line "What do you need?", which hands over Waking Dreams. "need a
   moment" is not on the widened back-out list (only "give me a moment"), and a one-entry ROOT list gets no step 0.
6. **Commit singles that are questions.** 8 beats, mostly quest starts:
   - Deor "Where were they headed?"
   - Tharstan "A new passage to what?"
   - the Riekling Chief's "Bilgemuck is an animal...?" and "What is this redgrass?"
   - Veleth "Did he say what it was about?"
   - Storn's restore / give-book lines
   - Tharstan's "He must have been someone important."

   These are scripted goodbye, so each is a COMMIT single. Natural questions ("which way did they go" 0.494, "where'd
   they go?" 0.721) share no strict word, or fail precision, so they park, and a second "yes" is needed. That is 24 of
   the 56 utterances on these beats.

   Speech-to-text damage to the question word also flips the shape test: "wear were they headed" (0.838) is read as a
   statement. This is the strongest argument for writing the 26 `scripted_entry_candidate` rows into the click-free entry
   table once their fragments are read.
7. **One-word and assent-shaped entries.**
   - Miraak's "Yes." / "No." IS an own-confirmation layer (the build's regex already matches it). But "yeah" scores 0.457
     against "Yes.", so the own-confirmation release never fires and the line parks. Only the literal "yes" works.
   - Gjalund's "Yes. (250 gold)" needs a yes to pick and then a yes to pass the ≥100-septim confirmation.
   - The Choice is Yours' "Sure." is a commit whose text is itself an assent word. "sure" parks, and "shore" parks.
   - "Let's go!" parks on "let's go": an exact hit needs 3 tokens.

   Proposal: on a layer where an entry's text IS an assent phrase, the assent list names that entry.
8. **Token or hidden prices.**
   - Gjalund (fare / double), Severin Manor, and the `<BribeCost>` lines need live text.
   - **Mogrul's "I'm so sorry! Here, take the money." takes 1,000 septims by script, but the index says cost 0.** It still
     asks, because it is scripted goodbye, but the ask cannot name the price, and the affordability rail never runs.
9. **Proper nouns damaged by speech-to-text on scripted singles.** Examples: "this mirror tried to have me killed",
   "i need to stop mirror now", "bilge muck is an animal".
   - Multi-entry layers survive, because the frame words carry the match.
   - Single scripted lines fall to the model, because the foreign word fails S4.5 precision. Homophones do the same:
     "wear" for "where", "know" for "now", "no" for "know", "dun", "a bout". 34 of 302 damaged variants fall to the model.
   - `name_fix` only repairs names of people present, and Miraak never is.
   - Proposal: while a DLC2 quest is active, add quest vocabulary to the protect / name list: Miraak, Hermaeus Mora,
     Solstheim, Skaal, Storn, Frea, Neloth, Tel Mithryn, Raven Rock, stalhrim, Apocrypha, Nchardak.
10. **`service_words` false positive.** Storn's "Are you ready to trade your secrets to Hermaeus Mora?" is graded class
    `service` because it contains "trade". It is labelled "[a service]" and can be picked on a bare "ready?".
11. **Rewards differ from vanilla, so negotiation must read the index, not memory.**
    - Served Cold gives no house in this load order.
    - Only one real bargain changes a septim amount: Mogrul's intimidate "How about you take half?" (500 instead of 1,000).
    - The "what about my reward" lines exist, and none moves the price:
      - Veleth "I could lend you a hand... for a price." (01BFC6)
      - Neloth "What's in it for me?" (0271F6)
      - "Would I be able to collect a bounty?" (0279E0)
      - Ralis "You want... 5000 septims?" (027582)
      - Talvas's spell-or-staff choice
      - Gjalund's persuade / intimidate, which makes the crossing free
    - Every reward line in the JSON has a `negotiation` entry with the truthful answer.

## Not mapped (present in the index, out of this group's "major" scope)

- DLC2RRFavor01-07 (Raven Rock favours)
- DLC2TTR1-8 (Neloth's research tasks)
- DLC2ThirskFF* (mead-hall favours)
- DLC2SkaalVillageFreeform1/2 (Morwen / Nikulas, including a persuade, an intimidate and a bribe)
- DLC2dunFrostmoonQST
- DLC2KagrumezQST
- DLC2TGQuest (Glover's formula)
- DLC2WE* (world encounters; DLC2WE09 is the cultist-ambush twin)
- DLC2HirelingQuest (Teldryn)
- Frea's follower recruitment

Deathbrand, Black Book: The Winds of Change, Black Book: The Hidden Twilight and the other Black Books have **no** player
lines in the index.
