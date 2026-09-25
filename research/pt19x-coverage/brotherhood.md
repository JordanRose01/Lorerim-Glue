# pt19x coverage - The Dark Brotherhood (DB01-DB11 and Destroy the Dark Brotherhood)

Machine file: `brotherhood.json` (same folder). Index: `prompt_index.ndjson` hash `e756e311aefaeab54a1184d609adb77b` (Ultra).

**How it was scored.** Every result comes from the real v1.0 functions, not from a re-implementation:
- the want=1 fast path through `lrgDlgAnswerWant`, read back from the emit log;
- her T-key on the target through `lrgDlgDecideEntry`;
- the lists decorated by `lrgPromptLookup` and `lrgDlgDecorateEntries`.

They ran on a byte-identical temp copy of `glue/server/lorerim_glue`: `lrg_dialogue.php` md5 `407c4d60…` (20:08, newer than the 19:36 lib the merge used) and `lrg_prompt_index.php` md5 `bf4d59d2…`. The harness is adapted from `pt19x-merge/recheck.php`. The work is in `%TEMP%\lrg_test\pt19x-db\`. Nothing outside that folder and these two files was written.

**Content policy.** This is classification of scripted game dialogue. Each player line is identified only by its `info_key`, its topic and a neutral summary of at most 8 words. No line text and no NPC reply text appears in either file; the harness looks the text up by `info_key`. The paraphrases, STT variants and near misses are the tester's own plain wording.

## One-page summary

- **Quest IDs, per UESP.**
  - DB01Misc = Delayed Burial; DB01 = Innocence Lost; DB02 = With Friends Like These...; DB02a = Sanctuary.
  - DB03 = Mourning Never Comes; DB04 = Whispers in the Dark; DB04a = The Silence Has Been Broken; DB05 = Bound Until Death.
  - DB06 = Breaching Security; DB07 = The Cure for Madness; DB08 = Recipe for Disaster; DB09 = To Kill an Empire.
  - DB10 = Death Incarnate; DB11 = Hail Sithis!; DBDestroy = Destroy the Dark Brotherhood!.
  - DBEviction = Honor Thy Family. DarkBrotherhoodSanctuaryRepair = Where You Hang Your Enemy's Head....
  - Much of the spine lives in the shared `DarkBrotherhood` quest (208 rows): Astrid's shack talk, the Black Door, Nazir's contracts and payment, and Astrid's transitions. DB02a has no rows of its own.
- **What the load order adds or changes** (winning plugins on the DB rows):
  - *Innocence Lost - Quest Expansion* (28 rows): arrest Grelod through the guards (persuade, bribe or Thane), lure her to the docks, and an aftermath talk with Constance.
  - *Destroy the Dark Brotherhood - Quest Expansion* (66 rows): Maro's deal, with a reward question, an accept and a "(Fail Quest)" decline; Gaston the scholar and the passphrase; hunting Gabriella and Veezara; the captives.
  - *Vittoria's Alternate Wedding* (13 rows): a guest-talk counter and a greeting to Vittoria that can start her speech.
  - *CC Farming - Tweaks and Enhancements*: an alternate first contract set and a persuade that settles Narfi without the job.
  - Smaller winners: TheChoiceIsYours (the rumor pointer), Escapeweave (Cicero's wagon lines), Andrealphus Scene Tweaks (Motierre's accept lines), Requiem (Nazir's turn-ins), GORE and USSEP.
- **Beats mapped: 231.** 213 have a player line and 18 are `cannot`. There are 1,065 paraphrases, 426 STT variants, 213 verbatim checks (text looked up, not reproduced) and 213 near misses.
- **Fragments actually read: 260.**
  - 208 TIF `.pex` from Skyrim - Misc.bsa and 19 from the USSEP BSA.
  - 33 loose mod scripts: Destroy the DB QE 17, Innocence Lost QE 12, Vittoria's Alternate Wedding 2, CC Farming 2.
  - The fragment script name came from the winning plugin's VMAD. 136 beats carry an `fx`; every other stage number is from UESP.
- **No scenes.** 60 DB SCEN records in Skyrim.esm and 8 in the DB mods were read. None has a player-dialogue action (PTOP/NTOP/NETO/QTOP). Every DB conversation is a forcegreet or normal talk, so `scene=false` throughout. Nothing here is read-only on the first evening, unlike every other guild group.
- **Click-free entry candidates (PROTOCOL 10.26): 38.** In each one the whole fragment is stage, objective or quest-variable writes.
  - 11 can be sent as a plain entry.
  - 26 are commits, so they need his exact words or her question and his yes first.
  - 1 must be avoided: the Destroy QE decline, `000E1C`, sets DBDestroy 199 and fails the quest.

### Counts by path (231 beats)

| quest | name | beats | pick | ask_once | scripted_entry_candidate | check | service | never_by_voice | cannot |
|---|---|---|---|---|---|---|---|---|---|
| DB01Misc | Delayed Burial | 15 | 8 | 2 | 3 | 2 | 0 | 0 | 0 |
| DB01 | Innocence Lost | 20 | 6 | 9 | 2 | 2 | 0 | 0 | 1 |
| DB02 | With Friends Like These... | 15 | 4 | 6 | 2 | 2 | 0 | 0 | 1 |
| DB02a | Sanctuary (+ Nazir's 12 contracts) | 24 | 15 | 0 | 8 | 1 | 0 | 0 | 0 |
| DB03 | Mourning Never Comes | 18 | 7 | 7 | 3 | 0 | 0 | 0 | 1 |
| DB04 | Whispers in the Dark | 10 | 5 | 2 | 1 | 0 | 0 | 0 | 2 |
| DB04a | The Silence Has Been Broken | 17 | 10 | 2 | 4 | 0 | 1 | 0 | 0 |
| DB05 | Bound Until Death | 6 | 1 | 4 | 0 | 0 | 0 | 0 | 1 |
| DB06 | Breaching Security | 9 | 3 | 4 | 1 | 0 | 0 | 0 | 1 |
| DB07 | The Cure for Madness | 14 | 6 | 4 | 2 | 0 | 0 | 0 | 2 |
| DB08 | Recipe for Disaster | 14 | 6 | 3 | 3 | 1 | 0 | 0 | 1 |
| DB09 | To Kill an Empire | 12 | 5 | 2 | 3 | 0 | 0 | 0 | 2 |
| DB10 | Death Incarnate | 8 | 3 | 2 | 1 | 0 | 0 | 0 | 2 |
| DB11 | Hail Sithis! | 23 | 8 | 11 | 2 | 0 | 0 | 0 | 2 |
| DBDestroy | Destroy the Dark Brotherhood! | 17 | 5 | 6 | 2 | 1 | 0 | 1 | 2 |
| DBEviction | Honor Thy Family | 3 | 1 | 2 | 0 | 0 | 0 | 0 | 0 |
| DarkBrotherhoodSanctuaryRepair | Where You Hang Your Enemy's Head... | 6 | 1 | 0 | 0 | 0 | 5 | 0 | 0 |
| **total** | | **231** | **94** | **66** | **37** | **9** | **6** | **1** | **18** |

Notes on the path column:
- `scene_read_only` is 0: there are no player-dialogue scenes (see above).
- `never_by_voice` (COVERAGE vocabulary) is the Destroy QE's "(Fail Quest)" line, graded meta.
- **The 18 `cannot` beats:**
  - the contract kills: Grelod, the captive, the optional Nilsine, the bride, the Gourmet (with the writ loot) and the Emperor with Maro;
  - the Night Mother's coffin, used twice;
  - searching Cicero's room and reading his journal;
  - planting the letter on Gaius Maro;
  - sparing Cicero, which means walking away;
  - showing the writ to Maro (no indexed player row);
  - the dinner, the escape and the ambush;
  - Astrid's last request;
  - the dead drop at Volunruud (a container);
  - clearing the sanctuary in the Destroy branch;
  - Maro's final report (no indexed player row; he pays on his own line);
  - Whispers in the Dark's completion, which happens on Nazir's set-2 line and is mapped under DB02a.

### How his sentences land

The fast path runs on his words alone; "T-key" means only her T-key would pick the line; "ask once" means she asks once and "yes" releases it.

| quest | paraphrases | fast path | her T-key | ask once | nothing | STT | STT fast | STT T-key | STT ask | near-miss green / target / park |
|---|---|---|---|---|---|---|---|---|---|---|
| DB01Misc | 75 | 45% | 16% | 33% | 5% | 30 | 60% | 17% | 20% | 9 / 4 / 2 |
| DB01 | 95 | 31% | 28% | 41% | 0% | 38 | 53% | 18% | 29% | 16 / 2 / 1 |
| DB02 | 70 | 27% | 20% | 53% | 0% | 28 | 39% | 14% | 46% | 12 / 1 / 1 |
| DB02a | 120 | 27% | 62% | 9% | 2% | 48 | 27% | 67% | 6% | 23 / 1 / 0 |
| DB03 | 85 | 36% | 15% | 46% | 2% | 34 | 53% | 9% | 35% | 10 / 3 / 4 |
| DB04 | 40 | 68% | 12% | 20% | 0% | 16 | 88% | 6% | 6% | 6 / 2 / 0 |
| DB04a | 85 | 56% | 13% | 31% | 0% | 34 | 74% | 9% | 18% | 11 / 4 / 2 |
| DB05 | 25 | 36% | 8% | 56% | 0% | 10 | 60% | 0% | 40% | 2 / 2 / 1 |
| DB06 | 40 | 42% | 10% | 48% | 0% | 16 | 50% | 6% | 44% | 6 / 1 / 1 |
| DB07 | 60 | 67% | 7% | 25% | 2% | 24 | 75% | 4% | 21% | 9 / 2 / 1 |
| DB08 | 65 | 52% | 15% | 31% | 2% | 26 | 65% | 12% | 23% | 5 / 5 / 3 |
| DB09 | 50 | 62% | 2% | 34% | 2% | 20 | 55% | 10% | 35% | 2 / 5 / 3 |
| DB10 | 30 | 43% | 17% | 40% | 0% | 12 | 75% | 17% | 8% | 5 / 1 / 0 |
| DB11 | 105 | 41% | 9% | 50% | 1% | 42 | 57% | 2% | 40% | 14 / 4 / 3 |
| DBDestroy | 75 | 49% | 5% | 39% | 7% | 30 | 53% | 10% | 30% | 11 / 3 / 1 |
| DBEviction | 15 | 33% | 20% | 47% | 0% | 6 | 17% | 17% | 67% | 3 / 0 / 0 |
| SanctuaryRepair | 30 | 10% | 7% | 83% | 0% | 12 | 8% | 8% | 83% | 5 / 1 / 0 |
| **all** | **1,065** | **42%** | **19%** | **37%** | **2%** | **426** | **54%** | **16%** | **29%** | **149 / 41 / 23** |

- **By path:**
  - `pick`: 64% fast and 34% T-key.
  - `ask_once`: 25% fast and 74% ask.
  - `scripted_entry_candidate`: 36% fast and 50% ask.
  - `check`: 0% fast (by design, the check rails take the T-key); 33% T-key and 62% ask.
  - `service` (Delvin's refits): 97% ask, because the price is quoted. The refit beats run with 20,000 gold in the harness; with the default 500 gold, nothing clicks.
- **Verbatim, 213 lines:**
  - 192 click on the fast path.
  - 11 need her T-key: the 9 checks, the back-graded report line `021451` and Nazir's lake line `0B83A2`.
  - 9 park: the three payment answers to Nazir, the Emperor's two-token "I'm listening" (`04FD5D`), and the 5 refits (price quote).
  - 1 does nothing: the meta "(Fail Quest)".
- **No paraphrase clicked the wrong line.**
- **Near misses: 149 of 213 are green.** That count includes 12 contract-list probes that correctly click the sibling info question and not the turn-in. There are 41 target clicks and 23 parks:
  - 21 are questions that pick a plain statement line;
  - 15 are S4.5 releases of a single-entry line;
  - 5 are S4.6 auto-advances of a single plain entry (by design);
  - 23 are questions that match a commit, so she asks once (safe; it costs a turn).

## Capability gaps, ranked by how often a player hits them

**1. Nazir's contract loop: 18 lines per playthrough, and it runs on her T-key.**
- **The numbers.** The 12 side-contract turn-ins resolve on the fast path for only 5 of 60 paraphrases, and all 5 are Ennodius. The other 55 need her T-key, and STT does 0 of 24 on the fast path.
- **Why.** Nazir's root list pairs every open turn-in (a name plus "is dead") with "Tell me about <name>". A neutral report such as "the Narfi contract is done" or "Narfi has been dealt with" shares only the name with both rows, so the margin never reaches 0.15.
- **The set requests.** Three of the seven set requests (`02AD12`, `02AD00`, `0205C0`) are graded commits (ask once), although their fragments only start the next contract set. Two plain set-2 lines, `0205BE` and `02AD14`, also complete Whispers in the Dark (DB04 200).
- **Suggestion.** When a statement names the target and is not question-shaped, it should prefer the turn-in over the info question. A report-verb synonym class (done, dealt with, taken care of, finished) would lift these lines without touching the info rows.

**2. Every report to a quest giver is a scripted goodbye commit, and a natural report parks.**
- **The numbers.** 37% of all paraphrases ask once, the highest share of any group (cw_stormcloak has 32%).
- **Which lines.** On these 10 beats every loose report parks, 5 of 5:
  - Aventus (`01F6CB`, `051400`), Muiri (`0212D1`, `0212D2`), Astrid after the wedding (`037B43`);
  - Gabriella (`059636`), Festus (`068B77`), Motierre (`04FDA3`), Loreius (`0556F9`), Gabriella's accept (`02393C`).
- **Why.** The explicit test wants words close to the literal line. A player who reports in plain terms ("it's done", "X has been dealt with") always costs one question. It is safe, but it adds about 10 extra turns per playthrough.
- **Suggestion.** Treat a report-shaped statement that names the contract target as explicit for turn-in lines whose only sibling is Goodbye or nothing.

**3. Questions click plain lines (21 near misses; COVERAGE G5 and G1 on plain rows).** The step-0 shape test only guards single-entry layers.
- **Examples that change state:**
  - "not now, I'll take the first contracts later" clicks `020017`. That starts contracts 01-03 and completes Sanctuary (DB02a 200): the G1 deferral defect, on a plain entry candidate.
  - "can you get her arrested" clicks `000D64`, which leads to the Innocence Lost QE arrest ending (199/198).
  - "do you know a jester named Cicero" names Cicero to the guard (`0556F6`, the framing branch of Delayed Burial).
  - "what is the Dark Brotherhood" clicks the guard report `094E3A` (DBDestroy 20). This one is on a one-row hand-built root, so it is approximate.
- **Harmless examples:** Gianna's four ingredient steps ("do you have carrots"), "am I paranoid", and "what is Volunruud".

**4. Single-entry layers release on questions (15 releases and 5 auto-advances among 76 single-entry beats).**
- **Consequential cases:**
  - "are you listening" releases Muiri's `0D2AE6`, a commit. It sets DB03 20 and shows the optional Nilsine objective.
  - "where is Veezara" releases `0A4032`, which starts Veezara's distraction fight.
  - "who's Gianna" releases `04BCAF`: RemoveItem(jarrin root), pPotagePoisoned, DB09 40. The poison choice itself was made one step earlier, at `04BCC0`.
- **Everything else** is flow text: Festus, Arnbjorn, Nazir, Astrid, Maro.

**5. The price rail cannot see the Honor Thy Family fine.**
- **The problem.** `06F999` and `06F99A` remove 500 or 300 gold in the fragment (USSEP pex read), but the index has `cost=0`. So "here's the 500 gold" is an explicit click, and she never quotes a price.
- **Compare.** Delvin's refits carry `cost` 1,000, 3,000 or 5,000 in the index. They park and quote correctly.
- **Bribe.** The Innocence Lost QE bribe carries the engine `<BribeCost>` token (cost -1). The check rails handle it.

**6. Misgrades.**
- `05B490` "buy" to Delvin is graded `service`. It is a quest commit that only sets DB04a 40, with no gold.
- `021451`, Astrid's report after Mourning Never Comes, is graded `back` because of its "nothing more". 5 of 5 paraphrases need her T-key.
- The Destroy QE decline `000E1C` "(Fail Quest)" is graded `meta`, so refusing Maro's deal is never possible by voice.

**7. Index layer lines that are twins or merged.** The fixtures carry `form` and `index_layer` for these.
- **The Dawnstar door.** The Black Door norms are keyed under Penitus Oculatus's own door (`penitus_oculatus.esp:00CFB0`). The vanilla list was rebuilt from siblings.
- **Astrid's shack.** The follow-up list is keyed under the Destroy QE parent `0008A8`. That merged list holds a mod variant (`0008A9`) that is never shown next to `020016`.
- **Innocence Lost QE guard.** Its list collapses persuade fail/success and bribe yes/no by norm.
- **Wrong variant tags.** The index tags the failure INFOs `variant=success`: `000808`, `00080D`, the CC Farming `000804` and the Destroy QE `000884`. This is the index-builder issue the companions verifier noted (C8).

**8. Converging choices are graded commits and ask on loose words.** These are pairs that run identical stage writes or only set flags:
- Astrid's honored / eager (`020BDD` / `020BDE`);
- Motierre's business / Emperor (both 15);
- the Cicero confront pair (both 50);
- the Emperor's consider / no favors (flags);
- Gianna's begin / ready;
- Festus's continue pair.

On these 10 beats, 53 of the 70 paraphrase and STT utterances ask once. Treating siblings that converge like hub questions (companions gap 3) would remove those turns.

**9. STT.**
- **Overall:** 54% of STT variants are fast.
- **Trailing fillers cost the explicit test on commits:** "who are you uh" parks, "is that all uh" parks, and short commits such as "fine im in" and "count me out" park.
- **Damaged names mostly survive on plain lines:** "sisero", "nazeer", "mo tier", "gaius mario", "vittoria vicky".
- **They fail on reports** (gap 2), and on the Nazir turn-ins (gap 1): "the mal oral contract is done" only reaches her T-key.

**10. What voice cannot do (18 `cannot` beats).** See the list under the path table: every kill, both coffins, the room search and journal, the letter plant, Maro's writ check, the dinner and ambush, the dead drop and the sanctuary clear. The player gets her words only (S7).

## Rewards and negotiation

- **No DB line bargains.** Every reward is fixed by the fragment: leveled gold through AddItem, items, a spell, Shadowmere, or the Muiri ring and Nightweaver's Band bonuses (UESP). Only Gate B's purse bonus applies (S6.1).
- **The only bargaining-shaped lines:**
  - Motierre's "can you pay the price" (`03BCF3`, a question loop; the answer is fixed: an amulet and a letter of credit);
  - the Destroy QE's "What's in it for me?" to Maro (`000E1A`; UESP: 3,000 gold and a Marked for Death word at the end, not negotiable);
  - Gabriella's bonus question (`04404D`): Olava's Token for finishing in a city, which the turn-in fragment starts (OlavaReading 10).
- **Nazir's payment talk.** He asks how much Motierre paid, and the three answers (`050731`, `05072F`, `050732`) are cosmetic. The truth line's fragment is empty; the lie chain ends in DB11 200. The 20,000 gold is in a container.
- **Payments that leave the purse.**
  - The Honor Thy Family fine (500, or 300 after Hail Sithis!) is invisible to the price rail (gap 5).
  - Delvin's five refits (1,000, 5,000, 5,000, 5,000 and 3,000 gold, 19,000 in all) park with the price quoted.
  - The Innocence Lost QE bribe uses `<BribeCost>`.

## Out of scope (not mapped)

- **Penitus Oculatus** (`Penitus_Oculatus.esp`, zzzPO* quests, 66 rows): a separate questline against the Brotherhood. It also adds a twin Black Door list (gap 7).
- **Post-questline contracts:** Listen - Dark Brotherhood Radiant Quests (LTN*, 7 rows) and the vanilla DBRecurring contracts.
- **The Hammerfell quest bundle** DDB* quests (34 rows).
- **Follower commands:** Cicero and the initiates (`DBCiceroState*`, `DBFollowerFavorState*`) are a follower service, not quest progress. Cicero's return greeting (`09BCA9`) is mapped under DB11.
- **Sanctuary flavour:** DarkBrotherhoodSanctuaryDialogue (77 rows), and Astrid's, Cicero's and Gabriella's top-level questions.
- **ACDB - Additional Contracts for the Dark Brotherhood** has 0 indexed player rows.

## Caveats

- **Hand-built root lists (36 beats).** They were built only from that NPC's own quest rows at that stage: Nazir's turn-ins plus the "Tell me about" rows for the same set, Gabriella's four questions, and similar. The real menu may also show generic topics such as rumors or follower lines. The one-row roots (guard, innkeeper, Delvin, Maro) are therefore optimistic about margins.
- **Not measured.** The LEAVE probe and the per-commit deferral probe from the merge were not run. The 19:36 recheck found G1 on most commit beats; here it is confirmed on one plain entry (gap 3).
- **Opened by the engine.** `opened_by=engine` is set only where UESP describes the NPC opening the talk: Astrid at the shack, Astrid after the coffin, and the Emperor. Every other beat is `glue`.
