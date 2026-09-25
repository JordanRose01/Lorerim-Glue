# pt19x coverage - Civil War, Imperial Legion (`cw_imperial`)

The data is in `cw_imperial.json`, next to this file. It has 95 beats across 14 quest groups. Every beat carries an index `info_key`, the plugin that wins the row, the stage, the `fx` read from the fragment, the v1.0 class, a path, 5 paraphrases, 2 speech-to-text (STT) variants and 1 near-miss. Each utterance was scored with the real v1.0 functions against the live list.

## One-page summary

**Questline, Imperial side.** Each step below lists the beats that advance the quest. Items marked **engine** are forcegreets or Invisible-Continue lines: the game plays them by itself, so there is nothing to click.

- **CW00A:** the soldier's "How does one join..." sets stage 1. Tullius forcegreets (**engine**, stage 10). Rikke: "Consider that fort already yours." sets 20, or "I already cleared it out." sets 21.
- **CW01A:** "I'm ready to take the oath." Oath lines 1-3 auto-advance. Oath 4 sets 200.
- **CW01AOutfitImperial:** Beirand's Light, Medium or Heavy set stage 10, 11 or 12.
- **CW02A:** "General Tullius told me to report to you." sets 20. "What's the mission?" (**engine**) sets 30. At Korvanjund, "I'm ready, ma'am." sets 50 (inside a scene). Crown turn-in: one of 3 answers, then "Yes, sir!" sets 200. Optional side switch at Ulfric: 220.
- **CW03:** Tullius's message to Balgruuf. "For the jarl's eyes only." sets 15. "About that message from the general..." sets 20. Balgruuf's own axe line sets 50 (**engine**). At Ulfric: "I've brought a message..." then "We'll be seeing you soon." Back at Balgruuf, "Ulfric means war." sets 150. Cipius's "Reporting in." sets CW 50, CW03 210 and CWSiegeObj 1.
- **Battle for Whiterun:** no player line.
- **Per-hold cycle** (Pale, Rift, Winterhold, then optionally Hjaalmarch, Falkreath and Reach, then Eastmarch):
  - At Tullius: "We were victorious!", then "How am I doing?" (promotion), then "What are my new orders, sir?" (CW 4), then "Yes sir."
  - At Rikke's camp: "Reporting for duty.", her briefing, then the accept line (SetStage 10).
  - The missions in between:
    - **A False Front:** innkeeper persuade, bribe or intimidate (stage 20/21); the courier; the Frorkmar bluff (stages 50/51 to 200).
    - **Fort sieges:** "That fort is as good as ours." (stage 10), then combat.
    - **Compelling Tribute:** "Recognize this?" (25), then "All right, it's a deal..." (30). At Rikke, "...a shipment of coin..." (40). With Hadvar, "What's the plan?" (41), then "Ready. Let's go." (50).
    - **Kastav:** "Nothing I can't handle." (10). With Hadvar, "What's the plan?" (12). The prisoners.
- **Windhelm:** Rikke's "Reporting in." sets CW 50 and CWSiegeObj 1. The execution choice sets CWFinale 305 or 310, inside a scene. Mod quest CWCRQuest: the champion line sets 100.

The spec harness already covers CW00A and CW01A (`in_spec_harness` marks those beats). Everything from Get Outfitted onward is new here.

**How the build does on paraphrases.** 88 beats can be answered by voice (7 are engine-only). Across their 440 paraphrases:
- **resolve:** 297 (67%)
- **ask:** 59 — she asks once, then "yes" clicks
- **nothing:** 84 — the model answers in words or has to choose

Of the 176 STT-damaged variants, 154 still resolve. 77 of the 88 near-misses pass. On 10 beats the verbatim line itself does **not** click (see gaps 2, 3 and 6).

**Rewards and bargaining.** Every Legion reward is fixed: ranks with a leveled item, Rikke's pay (`paySalary`), Frorkmar's exactly 5 gold, Tullius's sword and the champion set. When the player asks for more, the truthful answer is "that is what the Legion gives."

The group has exactly one real bargaining line: Anuriel's `CWMission07BlackmailPersonal` "And what about something for me, right now? (Persuade)" (`skyrim.esm:0E0B8B`). If the persuasion succeeds, she adds a large leveled gold reward on the spot (`LvlQuestReward03Large`, fragment read). Rikke's pay line (`069648`) also has a real alternative: donate or take.

## Counts by path (95 beats)

| pick | ask_once | scripted_entry_candidate | check | scene_read_only | service | cannot |
|---|---|---|---|---|---|---|
| 42 | 31 | 5 | 3 | 6 | 1 | 7 |

**Click-free entry candidates.** These are lines whose whole fragment is a single `SetStage`, suitable for a PROTOCOL 10.26-style table:

| Quest | Stage | Line | info_key |
|---|---|---|---|
| CW00A | 1 | "How does one join the Imperial Legion?" | `skyrim.esm:0D3C5A` |
| CW02A | 20 | "General Tullius told me to report to you." | `skyrim.esm:01A2A5` |
| CW03 | 20 | "About that message from the general..." (a commit on the list) | `skyrim.esm:0DA25C` |
| CW03 | 150 | "Ulfric means war." | `skyrim.esm:0DA236` |
| CWMission07 | 25 | "Recognize this?" (a commit on the list) | `skyrim.esm:0E0B93` |

The four accept lines also set only stage 10: `05B6B6`, `05C614`, `0504C4`, `0A1F94`. They come after Rikke's briefing, so keep the real click for those.

## Gaps, ranked by how often a player hits them

1. **Map-table actors may not open the menu at all. Not measured; the largest exposure.** About 20 turn-ins per playthrough go to Tullius, Rikke (in Castle Dour and at every camp) or Ulfric, and each stands at a map table or throne. On such an actor the v1.0 open needs a narrow marker: a verbatim top-level prompt, the NPC's own list, or a line from the NPC's journal quest (S2/S0.10). Paraphrases such as "we won, general", "Tullius sent me" or "what've you got for me, Rikke?" may leave the talk as words only.
   - This scorer assumes the list is already open. The open decision itself (`lrgDlgMaybeOpen`) needs a harness beat, using the paraphrases in this file, before the owner plays the Civil War.
   - The 5 candidates above cover the stage-setting part of this without any click.

2. **"Nothing I can't handle." is graded as a back line** (a bug in `lrgDlgIsBackOut`). The entry-side regex matches a bare `nothing` anywhere in the text, so these lines get class `back` instead of `commit`:
   - the Fort Kastav accept `05C614` (CWMission04 stage 10)
   - the Jagged Crown answer `01C5C3`

   The fast path never clicks a back line, and the list labels it `[leave]`. On the Kastav layer both entries are now "back", so a leave intent could click the ACCEPT. Across the whole index, 50 distinct scripted or goodbye lines are misgraded this way. Examples: MQ302's "Ulfric holds nothing worth trading Markarth for.", DA02's "I'll do nothing of the sort.", and the Stormcloak mirror `05F3F9`.

   Fix: on the entry side, count `nothing` only when it leads the line and stands alone (as the spoken-side rule already does), and pin `05C614` and `01C5C3` in `test_dialogue`.

3. **Short verbatim commit lines always park.** An exact 1.0 hit needs at least 3 tokens (S4.3), so a 1- or 2-token commit that the player says word for word still gets her question:
   - "Reporting in." (Cipius `0DC249`, Rikke before Windhelm `023870`; both scripted goodbyes)
   - "Recognize this?" (`0E0B93`)
   - "Follow me." (`0D76F3`)
   - "Light." / "Medium." / "Heavy." (Beirand)

   This happens about 6 times per playthrough and costs one extra "yes" each time. Option: count an exact whole-line hit as explicit at the entry's own token count when it is the only commit on the list and has no walk-out target.

4. **Questions fire entries on multi-entry lists.** The shape test exists only for single entries (S4.5 step 0). On root and closed lists, a question about the topic clicks the line:

   | Question | Clicks | Score |
   |---|---|---|
   | "what's the oath about?" | "I'm ready to take the oath." | 0.61 |
   | "what is the Jagged Crown?" | the crown hand-over | 0.639 |
   | "does Ulfric want war?" | "Ulfric means war." (sets CW03 150) | 0.721 |
   | "is his life in danger?" | the (Persuade) check | 1.0 |

   This is common in natural talk. Fix: on the fast pick, a question never picks a scripted or check entry that is not itself a question; the model answers, and a T-key still works if he meant it.

5. **The side-switch line is plain.** CW02A's `05A6B1`, "I made a mistake. I want to be a Stormcloak. The crown belongs to you." (sets 220, which fails the Imperial line), is the only scripted entry on its layer and has no goodbye flag, and crit 1 no longer counts (S4.1). So "I made a mistake coming here" (0.61) defects with no question. The Stormcloak mirror `05A6A6` has the same problem.

   This only happens if the player takes the crown to Ulfric, but it cannot be undone. Fix: a `commit: true` entry override, following the precedent of MQ102BStormcloakOath4.

6. **Invisible-Continue flavour answers are graded as commits.** The `invis` flag makes an entry count as a scripted sibling, so harmless answers need the full wording or a "yes":
   - Rikke: "What kind of test?" and "I can handle anything..."
   - The Jagged Crown debrief: 3 answers, all linking to the same `0D6605`
   - AP Tullius: "I was at Helgen."
   - Kastav Hadvar: "What's the plan?" and "So what's the plan?". Both score 1.0, the margin is 0, and nothing is clicked. The stage-12 line cannot be taken by voice without the model.

   Fix: siblings that link to the same topic are not a branching choice; and on a tie, the exact tier beats containment and F1.

7. **Rewards need a truthful "no".** The reward lines are listed in `negotiation`. Only `0E0B8B` really negotiates. The v1.0.1 bonus comes from her own purse only (S6), never from Tullius.

8. **Layer lines merge topic variants (affects the harness, not play).** These layer lines list texts from both factions, or a say-once line together with its repeat:

   `0E7259`, `0CE261`, `0DC250`, `050936`, `06591D`, `0D28CC`, `0DDE41`, `0E173E`, `0F7B60`

   The engine shows one info per topic. Spec 3.3's rule "refuse a closed beat whose entries differ from the layer line" would force false siblings. Example: "let's wait for nightfall" lands on the repeat "Let's wait a little longer." only on the merged line. Build the fixture layer from the layer line deduplicated by `topic_key`. The JSON gives both results per paraphrase: `via` on the live list and `index_layer_via` on the merged line.

9. **The explicit test does not check for foreign words.** "I want to fight as champion of the Stormcloaks" explicitly clicks the Imperial champion commit (0.721). Commit singles already require precision of at least 0.75 (S4.5); `lrgDlgExplicit` does not. Add the same precision check.

10. **Negation parity crosses sentence breaks.** `lrgDlgClauseStarts` ignores `.`, so "I need to think about it" clashes with "I'm not sure. I need to think about it.". Declines still work through the leave path, so the impact is low.

11. **Scenes and engine lines.**
    - Korvanjund (Rikke) and the Windhelm execution are journal scenes. They stay read-only until the first proven click, and the owner will have one long before CW02A.
    - 7 beats are engine-only: forcegreets, Invisible Continues, battles, and Frorkmar's hand-over. The glue must not claim it chose them.
    - The CW03 "Ulfric means war." fragment ran on end in vanilla (fixed in USSEP 4.3.7). The glue must not cut his line short.
    - UESP's missing "Reporting for duty." (a CWObj soft-lock) cannot be fixed by the glue. She should say the camp has nothing for him yet.

## Sources and method

- **Rows:** the live prompt index (header hash `e756e311`, 3496 plugins).
- **Stages:** UESP raw wikitext for the 12 Imperial pages (URLs are in the JSON), plus `QF_*` stage-to-fragment maps decoded from the QUST VMAD.
- **fx:** TIF fragments decoded from the winning plugin's VMAD, looking in loose mods first, then the USSEP BSA, then `Skyrim - Misc.bsa`. The AP and CWCR fragments came from their mod BSAs. The Choice is Yours blanks CW00A's "I'm not sure about this." (`tif__000d513d.psc`, read).
- **Scores:** a read-only copy of `glue/server/lorerim_glue/lib` taken 2026-09-24 at 18:11. The fast-path order of `lrgDlgAnswerWant` was modelled with `clicks_ok = 1` and `pg = 300`.
- **Work files:** `%TEMP%\lrg_test\pt19x-cwimp\`, containing `beats.py`, `build_in.py`, `score.php`, `assemble.py`, `fx_out.txt` and `qvmad.py`.
