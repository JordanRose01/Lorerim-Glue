# pt19x coverage - Civil War, Stormcloak side (CW00B, CW01B, CW02B, CW03, the campaign, CWMission*, the sieges, the finale)

Machine file: `cw_stormcloak.json` (same folder). Index: `prompt_index.ndjson` hash `e756e311aefaeab54a1184d609adb77b`
(profile Ultra, 37,561 rows). All scores come from the real v1.0 functions, run on a read-only copy of
`glue/server/lorerim_glue/lib` taken on 2026-09-24. The functions are `lrgDlgDecorateEntries` (the live class, commit and
hub flags), `lrgDlgMatchText`, `lrgDlgMatchPick`, `lrgDlgUniqueWordTie`, `lrgDlgNegationClash`, `lrgDlgExplicit`,
`lrgDlgSingleEntryRelease` and `lrgDlgAdvMs`. They run in the order `lrgDlgAnswerWant` uses, on **his words alone**:
no `ask=`, clicks_ok >= 1, and not inside a journal scene. The work files are in `%TEMP%\lrg_test\pt19x-cwsc\`
(`beats_def.py`, `score.php`, `assemble.py`).

## One-page summary

- **Quests found in the index** (the plugin that wins most rows is in brackets):
  - CW00B (24 rows: Skyrim.esm, USSEP; TheChoiceIsYours wins the decline; AlternatePerspective wins 3 of Ulfric's lines)
  - CW01B (12, Skyrim.esm)
  - CW02B (27, Skyrim.esm and USSEP)
  - CW03 (26, Skyrim.esm; Update.esm wins the Stormcloak axe lines)
  - CW (164). This is the Liberation hub: camp entries, victories, promotions, orders and salary. Vittoria's Wedding
    and Jobs Overhaul add rows.
  - CWMission03 (61; Immersive Speech Dialogues rewrites the check texts; the LoreRim Revoiced patch adds the uniform
    bluff)
  - CWMission04 (48)
  - CWMission07 (49)
  - CWFortSiegeFort (56). One quest covers all six fort battles.
  - CWFinale (17), CWFin (13, talk only), CWResolution01/02, CWAttackCity, CWMerchants
  - CWCRQuest (Civil War Champions - Reduced Cut, active)
- **What the brief expects but the index does not have:**
  - **CW04** does not exist. The Stormcloak "Liberation of Skyrim" is **CWObj** on
    [UESP](https://en.uesp.net/wiki/Skyrim:Liberation_of_Skyrim), and it has no player rows.
  - **CWSiege\*** / **CWSiegeObj** (Battle for Whiterun, Battle for Solitude) have **no player rows of their own**.
    - Entry to each siege is a CW-hub line: "How can I help?" 065C85 / 065C87. Its fragment was read:
      `SetStage 50` + `CWSiegeS.SetStage 1`.
    - Balgruuf's surrender has no row to click (`cannot`).
    - The Solitude execution and speech are in CWFinale.
  - CWMission01/02/05/06 have no rows.
- **The Stormcloak missions** ([UESP](https://en.uesp.net/wiki/Skyrim:Liberation_of_Skyrim)):
  - CWMission04 = Rescue from Fort Neugrad (Imperial: Kastav)
  - CWMission07 = Compelling Tribute (Raerek, Markarth; Imperial: Anuriel, Riften)
  - CWMission03 = A False Front (the Imperial legate in Morthal)
  - CWFortSiegeFort = the six fort battles
  - The Imperial and Stormcloak variants share topics, so this map picks the Stormcloak INFOs. The signs used were the
    topic name (`...Sons`), the responses and the conditions.
- **Scripts actually read.** `fx` comes from the INFO fragments (`TIF__<id>.pex`) of the winning plugin. They were
  extracted read-only from the vanilla or USSEP BSAs and disassembled with the cwimp team's `fx.py`, re-run on this
  group's rows.
  - Examples of `fx` values:
    - CW00BGalmarSignMeUp `SetStage 15`, AcceptQuest `SetStage 20`
    - CW01BGalmarGreetGoodbye "I need to think it over." `SetStage 160`
    - Oath4 `SetStage 200`
    - "What's the mission?" `SetStage 30`
    - "I'm ready. Let's go get them." `SetStage 50`
    - Ulfric's crown answers `SetStage 190`, "Understood." `SetStage 200`
    - "About that message from the jarl..." `SetStage 20`, "You have a task for me?" `SetStage 50`
    - "The Jarl of Whiterun returns your axe." `SetStage 225`, "Yes, sir!" / "Many will die by my hand." `SetStage 240`
    - The mission accepts `SetStage 10`
    - Compelling Tribute `25 / 30 / 40 / 41 / 50`
    - A False Front `20 / 21 / 50 / 51 / 200`
    - The promotions (`SetStage 3` + reward faction, then `setPlayerFactionRank 2/3/4`)
    - "What's our next move..." `SetStage 4`
    - The execution choices `305 / 310`, "Of course, my Lord." `340`
  - One loose script was also read: The Choice Is Yours `QF_CW00B_000E1B5A.psc`. It gives Ulfric's forcegreet and the
    note that CW00B shows no journal objective. The quest also references the `CW00WindhelmMapTableScene` quest.
  - `unknown` is left only where the plugin was not parsed: TCIY's decline and CWCRQuest.
- **Beats mapped.** There are 88 beats across 16 quests: 86 with a player line and 2 `cannot`.
  - Scored utterances: 602 (430 paraphrases + 172 STT variants), plus 86 near misses. 7 beats carry a negotiation
    block.
  - These spec harness beats are marked `in_harness`: CW00B.galmar.test, CW00B.galmar.join, CW00B.galmar.accept and
    CW01B.oath.4.
  - The rest extends the harness. It covers Ulfric's greeting, Galmar's questions, the oath entry and resume, the
    whole Jagged Crown, Message to Whiterun, the siege entries, and the campaign hub (victory, promotion, next orders,
    camp offers). It also covers every mission step with a player line, the fort battles, the finale, and the Tullius
    defection branch.

### Counts by path (88 beats)

| path | beats | what it means here |
|---|---|---|
| pick | 40 | plain lines. Includes 3 single-entry layers (promotion, next orders, the plan) and 1 auto-advance (the uniform bluff) |
| ask_once | 31 | commits. Scripted-goodbye lines, and layers with >= 2 scripted siblings. **Invisible Continue prompts count as scripted** (7 beats become commits only through this, gap 2) |
| scene_read_only | 6 | CW03 Balgruuf's "You have a task for me?" after the council scene; the 3 execution choices and "Of course, my Lord." in CWFinale; the ambush "Ready..." cue |
| check | 4 | A False Front innkeeper: persuade, bribe (cost token), ISD intimidate. Compelling Tribute: "And what about something for me, right now? (Persuade)" |
| service | 3 | war salary ask, take / donate, camp quartermaster |
| scripted_entry_candidate | 2 | Galmar "What's the mission?" (whole fragment `SetStage 30`); Ulfric "The Jarl of Whiterun returns your axe." (whole fragment `SetStage 225`). Both are spoken at the Windhelm map table, an ambient scene |
| cannot | 2 | Balgruuf's surrender (Battle for Whiterun objective 1210) and Ralof's Fort Neugrad report: no player row in the index |

How the 602 paraphrase and STT utterances land. This is his words alone; her T-key is not simulated.

| outcome | utterances | share |
|---|---|---|
| clicks: fast pick 208, explicit commit 124, single release 29, check under the rails 24 | 385 | 64% |
| she asks once, quoting the line, then "yes" clicks | 54 | 9% |
| nothing: below 0.55/0.15 (134), a **back-class** target (18), a negation clash (5), another entry wins (4), check under 4 words (2) | 163 | 27% |

STT variants: 126 of 172 click, 9 ask and 37 do nothing. Mangled names mostly survive through the rest of the line:
"core van june", "rare ick", "all frick" and "tully us" still click. The losses are:
- split or near words on short lines: "yes sur" and "yes serve" (both "Yes, sir!" lines), "under stood", "report in",
  "so rey", "red e", "hollow me", "sit you ation", "surrender ring", "for sail"
- the owner's own "i want to join the lead jam" (0.513 against "I'd like to join the Imperial Legion.")
- "all hail the storm clocks" on the oath (0.586, below the single-entry floor)
- the British spelling "recognise this": no shared word with Raerek's scripted "Recognize this?", so S4.8 blocks it

Near misses: **43 of 86 are hazards**.
- 28 of them click the target: 20 plain picks, 5 explicit commits, 2 checks and 1 single release.
- 15 more park a commit, so she asks.
- The other 43 near misses do nothing: 30 score below the floor, 7 hit a negation clash and 6 land on a sibling.

## Gaps, ranked by how often a Stormcloak player hits them

1. **A question about the topic clicks the line (every conversation).** Outside single-entry layers there is no shape
   test. Neither `lrgDlgMatchPick` nor `lrgDlgExplicit` asks whether the utterance is a question. A question that shares
   the line's meaning word clicks it. Examples:
   - "what happened at Helgen?" is an **explicit** hit on "I was at Helgen." (0.87)
   - "can you help me?" is explicit on the siege entry "How can I help?" (065C85, fragment `SetStage 50` + siege 1)
   - "should I kill him?" is explicit on "I'll gladly kill him." (0D1E19 `SetStage 305`) once the finale scene is proven
   - "where's the courier's package?" clicks the False Front turn-in
   - "is his life in danger?" fires the persuade
   - "what's the Jagged Crown?" clicks the crown hand-in
   - Fix: S4.5 step 0 already has a shape rule. Apply it to `lrgDlgExplicit`, and to plain picks whose entry is not a
     question.
2. **Invisible Continue prompts turn plain answers into commits (the first two conversations and several more).** The
   glue grades an Invisible Continue prompt as scripted (capability map U2), so any layer with two of them has two
   scripted siblings. S4.1 then makes both commits. Hit on:
   - Ulfric's greeting: "I was at Helgen." 0E1AE0, "I believe we've already met." 0E1AFD, "I helped Ralof escape..."
     0E1B05 and "I was set free..." 0E1B21
   - Galmar's "What kind of test?" 0E1B34 / "I can handle anything you throw at me." 0E1AE7
   - Ralof's "What's the plan?" pair
   Every paraphrase that is not explicit parks, so she asks "you were at Helgen?" before a line with no fragment at all
   (`fx` none). This is 7 beats: 17 of their 49 utterances park, and 9 more do nothing (CWMission04.plan never clicks at all, gap 9). Fix: count an Invisible Continue sibling only when its
   continuation carries a fragment. The fx extraction proves most of these carry none.
3. **Ulfric and Galmar stand at the Windhelm map table (an ambient scene) for most returns.** Affected: the victory
   report after every hold, the promotion, next orders, the crown, the axe and "What's the mission?". The scene's owning
   quest is `CW00WindhelmMapTableScene` (per the comment in QF_CW00B; not yet confirmed from the game's `sq=`). That
   name matches the `*MapTableScene*` ambient glob, so the list opens only on a narrow marker. "we won", "I brought the
   crown" and "what's my first assignment" are not verbatim prompts. Unless another marker catches them, nothing opens
   and she answers in words. The two scripted entry candidates (`CW02B` 10 -> 30, `CW03` 50 -> 225) are the fallback. The
   victory line (no SetStage; WarIsActive / RewardPlayerForReclaimingHold) is not a candidate, so it relies on the open.
4. **Generic camp texts share no word with what a soldier says (every camp visit, 10+ per campaign).** The entry texts
   are:
   - "Reporting in." (Neugrad, Markarth, forts, capitals)
   - "I'm here to help."
   - "How can I help?" (the sieges)
   - "Reporting for duty." (False Front)
   - "Sorry." (after a failure)
   "Orders?" scores 0.16 against "How can I help?". "what are my orders" and "I'm ready for the attack" land nowhere.
   On the hold capitals, "Reporting in." is a **2-token commit** (0CA74D), so even the verbatim sentence parks (gap 5).
5. **Short commits always take two turns.** An exact hit under 3 tokens is never explicit (S4.3), so these park and need
   a "yes":
   - "Yes, sir!" (CW03 accept 0E40B1)
   - "Understood." (Jagged Crown hand-off 0E2D03)
   - "Follow me." (Neugrad prisoner)
   - "Reporting in." (capitals)
   - "Recognize this?" (Raerek)
   "okay, understood" is explicit (containment 0.85, 2 tokens against a 1-token entry), but "understood" alone is not.
6. **Lines that start with "Nothing" or "not sure" are class `back`, and the fast path never executes a back entry.**
   - "Nothing I can't handle." is the **accept** of Rescue from Fort Neugrad (05B379 `SetStage 10`, and Kastav on the
     Imperial side). It sits beside "I'm not sure. I need to think about it.", which is also `back`.
     - Not one of 7 wordings clicks.
     - "I can handle it" is refused as a negation clash, because the entry's "can't" negates "handle".
     - A "never mind / bye" leave intent clicks a back entry, which **may accept the mission**.
   - The same text rule catches Ulfric's "Nothing I couldn't handle." (05F3F9) and TCIY's "I'm not sure about this.".
   - Fix: apply the entry-side back-out regex only to lines that end or leave the talk. Or anchor `nothing` to a
     sentence that is only "nothing", as the spoken side already does.
7. **S4.10 "a walk-out line always asks" is not in the code.** `lrgDlgIsCommit` has no twat test. So these plain
   walk-out targets click on his words:
   - "Hold on. I need a minute." 057BCB
   - "All right, it's a deal. Where can I find this shipment?" 0E0B92 (`SetStage 30`)
   The leave guard is the only protection. Other walk-out targets are commits anyway (scripted goodbye):
   - "I need to think it over." 0E2CFA sets `SetStage 160` even though it reads as a decline.
   - "Yes, sir!" and "Ready. Let's go." (`SetStage 50`) are the lines a walk-away plays.
8. **Switching sides is graded plain.** "I just want to join the Legion. Consider the crown a gift." (05A6A6,
   `SetStage 220`, then "Yes, sir!" 0C9833 `SetStage 200`) moves the player to the Legion. It is scripted and not
   goodbye, with one scripted sibling. It needs a `commit: true` override, and so does the Imperial mirror. "I'll
   donate my earnings to the cause." is a commit only by the sibling rule.
9. **Identical norms tie at margin 0.** "What's the plan?" and "So what's the plan?" normalise alike on Ralof's Neugrad
   layer. Every wording, the verbatim one included, ties, and `lrgDlgUniqueWordTie` cannot separate them. The same
   near-tie happens with "Oath?", a one-word sibling on Galmar's return layer. It takes the margin from every oath
   paraphrase: "I'll take the oath" wins only by the unique-word tie (0.87, margin 0.02), and "Alright Galmar, I'm ready
   to swear your oath" lands on "Oath?".
10. **Check-row mislabels.**
    - ISD's intimidate success "Waste my time, and my comrades won't be so lenient..." (0C27F0) is kind=''. It stays a
      check only because the glue borrows topic_kind.
    - Its sibling "(Brawl)" 0C27EF is kind=intimidate, but its fragment only starts a Brawl.
    - The ISD pass and fail texts ("His life is in danger." / "A dragon is going after him!") are one topic: the live
      list shows one of them.
    - "Here's the Jagged Crown. I believe you owe Galmar a drink?" is class **service** (because of "drink").
11. **No row to click:**
    - Balgruuf's surrender (Battle for Whiterun 1210)
    - Ralof's Fort Neugrad report (50 -> 200)
    - The Compelling Tribute close (90 -> 200)
    The engine does these without a player line. The owner page should say so.
12. **Stage disagreement to verify in game.** "I've returned with the courier's package." removes the package and sets
    `SetStage 200` (read). UESP's A False Front table has the Galmar hand-in at 30 -> 40, followed by the forged orders.

### For the harness extension (not player-facing)

- The index **layer lines merge every INFO of a topic**, but the engine shows one INFO per topic. Examples:
  - CWWhatsNext under 0CE261: 3 norms, 1 visible
  - CWPromotionAwardTopic
  - CW00BGalmarSkyrimIsHome (Nord vs non-Nord)
  - The False Front check layer 0D28CC: 7 norms, 4 visible
  - The execution layer 06591D: both sides' lines
  - Raerek vs Anuriel in 050936 / 0E0B99
  Built from the layer line, the harness shows menus the game never shows. It must collapse by `topic_key`. This map
  scores the visible set and records `layer` = the parent_info.
- Alias tokens need `live_text`:
  - `<Alias=Steward>` becomes Raerek
  - `<Alias.PronounObj=Steward> <Alias=Evidence>` becomes "him Inscribed Amulet of Talos"
  - `<BribeCost>` becomes the live price
- The 0D265A offer reads "Reporting for duty." on the Stormcloak side, probably from the topic's FULL text. Check it
  against the live list.

## Negotiation (the reward lines)

- The one real bargain line in the group is Raerek's persuade "And what about something for me, right now?".
  - Success pays LvlQuestReward03Large: 500 / 750 / 1,000 / 1,250 / 1,500 septims by level
    ([UESP](https://en.uesp.net/wiki/Skyrim:Compelling_Tribute_(Stormcloaks))).
  - There is no second ask.
- The campaign's "what about my reward" line is Ulfric's "How am I doing?" (the promotion). Its walk-out target is "What's
  next?", so leaving plays the orders and the promotion waits.
- Everything else is fixed by script and has no bargain line:
  - The oath armor (4 pieces)
  - RewardPlayerForReclaimingHold
  - paySalary
  - A False Front: 5 septims + leveled gold
  - The Solitude sword
- Under S6.1 she says the reward is what the world gives and offers nothing else.
