# pt19x coverage - main quest part 3 (mq3)

Elder Knowledge, Alduin's Bane, The Fallen, Paarthurnax, Season Unending, The World-Eater's Eyrie, Sovngarde, Dragonslayer.
The data is in `mq3.json` next to this file: 94 beats and 752 utterances. Each beat has 5 paraphrases, 2 speech-to-text-damaged variants and 1 near miss; 13 reward and bargain lines add negotiation lines. The real matcher scored the utterances of 93 beats; the 8 on the one 'cannot' beat follow the leave-guard rule instead.

## What was done
- **Index facts.** Every row comes from the live prompt index (built_at 1790276458, profile Ultra). Each beat records `info_key`, the winning `plugin`, `txt/norm`, her reply, `scripted`, `nconds`, `kind`, `crit`, `toplevel`, `flags`, `twat` and the visible sibling set.
  The group spans these quests as the index names them: MQ205, MQ206, MQ301, MQ302, MQ303, MQ304, MQ305, MQ306 and MQPaarthurnax. It also includes the Elder Knowledge leg of **DA04** (Urag and Septimus), because Elder Knowledge cannot be finished without those lines.
- **fx was read, not guessed.** I extracted each scripted row's `TIF__<formid>.pex` from `Skyrim - Misc.bsa` and the USSEP BSA (LZ4) and decoded the call arguments. For example, `0485C7` "I'm ready. Let's go trap a dragon." runs `SetStage(120)`. The Paarthurnax Quest Expansion's own `PAR_*.pex` scripts were matched to their INFO records through the plugin's VMAD:
  - `000D9F`: SetStage 75
  - `000D71`: objectives 20 and 21, then SetStage 76
  - `000D7E`: objective 21, then SetStage 99

  Four GORE- or PQE-patched rows have no fragment I could find, and their fx says `unknown`.
- **Scores come from the real functions.** Read-only copies of the glue libraries (mtime 2026-09-24 18:11) were run offline: `lrgDlgMatchText`, `lrgDlgSingleEntryRelease`, `lrgDlgExplicit`, `lrgDlgNegationClash`, `lrgDlgNamedChoice` and `lrgDlgRefuses`. Each score is taken against the line's layer, which is the set of entries the game really shows.
  `expect` is what v1.0 does today at `clicks_ok >= 1`. `near_miss.expect` is the requirement (`nothing`), and `near_miss.computed` is today's result.

## Load-order facts that change the questline
1. **Season Unending never happens on this install.** `CWFO_TheFallen.esp` is active (plugins.txt line 174). It adds `GetGlobalValue(CWFOisCivilWarFuckOff)` conditions:
   - `0D2540` "It's the only way to find Alduin before it's too late." requires 0.
   - Its truce twin `0D23C8` requires 1.
   - The global's default value is 0.

   So Balgruuf agrees at MQ301 stage 40 without asking for a truce. The 8 MQ302 beats are mapped and marked `reach: dormant`. They should stay out of the harness unless the owner sets the global to 1.
2. **Paarthurnax Quest Expansion adds a spare path by voice.** You can talk Paarthurnax, Delphine and Esbern round (stages 75, 76 and 99). The kill line `000DA1` has no fragment: the fight starts only when you strike him (alias `_PAAR_FightStart`).
3. **Skip Time Wound Scene** replaces QF_MQ206 and starts MQ301 at stage 10. Alduin's Bane has exactly one player line: `0C64EC` "I have the Elder Scroll.", which runs SetStage 15.

## Counts by path
| path | all beats | reachable (MQ302 excluded) |
|---|---|---|
| pick | 59 | 58 |
| ask_once | 14 | 11 |
| scene_read_only | 12 | 9 |
| scripted_entry_candidate | 6 | 6 |
| check | 2 | 1 |
| cannot | 1 | 1 |
| **total** | **94** | **86** |

- **Beats per quest:** MQ205 8, DA04 11, MQ206 1, MQ301 30, MQPaarthurnax 16, MQ302 8, MQ303 7, MQ304 9, MQ305 1, MQ306 3.
- **The 6 scripted_entry_candidate beats** are the ones whose whole fragment is one `GetOwningQuest().SetStage(n)` on a top-level or blocking line: `0BD16D`, `0C64EC`, `045D05`, `0F1C71`, `0F1C80` and `077358`. Another 25 deeper lines qualify too (`entry_candidate: true`): 21 reachable, 3 in the dormant MQ302, and one deliberate exception, below.
  `07735E` "Paarthurnax is dead." (stage 150, the Greybeards turn on you) also qualifies, but it is deliberately left out: that line needs the confirmation.
- **Utterances:**
  - paraphrases: resolve 378, ask 31, nothing 61
  - speech-to-text variants: resolve 163, ask 10, nothing 15
  - 23 resolves and 20 asks happen only if the model offers the line (the harness must supply that model choice)
  - near misses: 89 pass, 5 fail (the line gets clicked anyway)
- **The one 'cannot' beat** is `04E9B5` MQ304A3, "Invisible Walk Away" (stage 50). It has no player text, so the leave guard never sends it. The same stage is reachable by voice through `04E9BB` "Yes, it's at the far end of the valley from here."
- **Negotiation.** No layer in this group has a "what about my reward" line. Every reward here is fixed and not paid in septims:
  - the Blades take you back (MQPaarthurnax 100)
  - Tsun teaches Call of Valor (MQ305)
  - Odahviing's bargain is freedom in exchange for the flight
  - Septimus wants an errand done, not gold

  She should say so; v1.0 never invents a reward.

## Capability gaps, most often hit first
1. **A shared name in a lore question steals the margin on root lists.** 20 misses (18 reachable). On Esbern's, Delphine's, Arngeir's, Paarthurnax's and Urag's root lists, a lore question shares the proper noun with the quest line, so the 0.15 margin fails:
   - Even the exact line fails: "I'm looking for an Elder Scroll" scores **1.0 against 0.87** for "What is an Elder Scroll?", a margin of 0.13.
   - "I spoke with the Greybeards" (margin 0), "how can I lure a dragon to Dragonsreach?" (0.036), "where did Alduin go?" (0.13) and "I know the Dragonrend Shout now" (0.046) all miss.

   Every Elder Knowledge and Fallen turn-in hits this. Suggested fix: exempt an exact or containment hit from the margin rule. Use statement-versus-question shape as the tie-break between a report and a lore question.
2. **A leading "no" or "I won't" is read as a refusal, even when the line itself says it.** 13 misses:
   - Spoken word for word, `041F13` "No, but I know how to find out. I need an Elder Scroll." and `077359` "No, but he told me how to find out." are refused at step 0.
   - Natural spare-Paarthurnax answers are refused as back-outs: "I won't kill him, he's changed", "I can't do what you ask", "no doubts, trust me", "I don't regret it at all", "no regrets".

   Every Elder Knowledge report hits this, and so does every player who spares Paarthurnax. Suggested fix: when the matched entry itself begins with the same negator, or its text carries the negation ("cannot"), do not refuse.
3. **Questions and opposite statements click root lines.** This is where 4 of the 5 near-miss failures come from; the fifth is gap 6.
   - "do you trust the Greybeards?" clicks "I talked to the Greybeards."
   - "who is Paarthurnax?" clicks "About Paarthurnax..."
   - "is the trap holding?" clicks "Open the trap."
   - "Paarthurnax is dead" clicks `000D63` "Paarthurnax has changed, I cannot do what you ask of me", the opposite meaning.

   The shape guard (S4.5 step 0) covers single-entry layers only. CHIM players ask lore questions constantly, so this is hit often.
4. **The engine's own "Are you sure?" layers are not recognised.** `lrgDlgLayerIsOwnConfirmation`'s "no" pattern does not match "Hold on. Not yet." (the trap gatekeeper), "Not quite yet." (Balgruuf's trap) or "Hold on. I'm not quite ready." (Odahviing's porch). So a bare "yes" (0.32) and "yes, do it" (0.38) click nothing at the gatekeeper. Once per playthrough on each of the 3 layers.
5. **The talking dragons are unverified.** Paarthurnax (MQ301 20, the name at 70) and Odahviing (MQ301 220 and 230, MQ303 20 and 50) are dragons. The glue opening the menu on them, and CHIM targeting them, have not been tested in game. If either fails, the click-free table is the fallback: every one of those lines has a one-stage fragment (`entry_candidate`). This affects 6 conversations per playthrough.
6. **A point of no return is graded plain.** `04DE3C` "I'm ready. Take me to Skuldafn." is scripted with no Goodbye flag and has one scripted sibling, so S4.1 grades it plain. It clicks immediately, even on the near miss "what's Skuldafn like?" (0.61 / 0.37). Followers stay behind and there is no way back. Suggested fix: add an override `commit: true` like the Oath4 row.
7. **Negation-parity false alarms and speech-to-text "no/know" swaps.** 7 misses (6 reachable), plus "there's no other way to find Alduin", which only the model can release:
   - "guess I have no choice but to trust you"
   - "Alduin had to be destroyed" (the entry's own "no regrets" marks the shared words as negated)
   - "there's know way…" and "heard you no about…", where speech-to-text wrote "know" and "no" the wrong way round
8. **Assents and "don't…" read as questions.** "fine, what do you know?" is refused by the shape check (step 0 runs before the assent check in step 1). "don't worry, it will be fine" counts as a question because `lrgDlgLeadsQuestion` treats don't, won't and can't as question openers. Speech-to-text turning "good" into "could" does the same. Low frequency.
9. **The harness would build some layers wrong.** The index's layer lines list both condition variants of one topic as siblings: MQ301ArngeirIntroA1 (026742 and 026743), MQ301EsbernFindAlduinA1 (07D42C and 0D070F), MQPaarthurnaxIntroA1, MQ205EsbernIntroA1, MQ301JarlAlduinReturnA3, and the Speech success/failure pairs. Built from the layer line, `026742` becomes a commit with two scripted siblings, while the game shows one plain line. Spec 3.3's "harness refuses a closed beat whose entries differ from the layer line" would reject the true visible set. Suggested fix: dedupe layer lines by topic.
10. **Journal-quest scenes: 9 reachable beats.** These are the Sovngarde lost soul, Tsun at the bridge and after the battle, and the MQ306 Paarthurnax epilogue. They are read-only until one real click is proven. Late in the game that proof will already exist, so the risk is low.
11. **Opposite-meaning commits (only in the dormant MQ302).** "Ulfric is right, Elenwen should go" explicitly clicks the opposite line, "No, you're right. Elenwen should stay." (0.783 / 0.303). F1 cannot see go versus stay. This is the same pattern as gap 3, but on a commit.
