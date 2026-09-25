# pt19h-grading: the grading fixer

2026-09-25. Role: the GRADING FIXER (gaps G3, G12, G15, G16, G19, G20, the Brotherhood misgrades, spec 3.5 LETHAL show on the
fast path, S3.3 rail note kind=rail). Nothing was deployed or installed; no game, MO2, Nemesis, LOOT or C++ tooling was run.

## What changed (files, functions)

Backups (first touch): `glue/.backup/pt19h-grading/` (`lrg_dialogue.php.bak` fe28ae3e = the measured tree,
`lrg_prompt_index.php.bak` f4e78160, `lrg_dialogue_overrides.default.json.bak` db66a291, `test_dialogue.php.bak`).

`glue/server/lorerim_glue/lib/lrg_dialogue.php` - only my functions, all edits marked `[pt19h-grading ...]`:
- `lrgDlgDecorateEntries`: the sibling count now comes from `lrgDlgSiblingCount` (G3); the hand-over object words lead the
  entry's match norm (G16); new row fields `info` (resolved info key, for overrides), `linked`, `conv`; an override may set
  `fcommit` (G20). The pt19h-money fixer's prose-cost hunk in the same function is untouched.
- `lrgDlgClass`: one service word no longer makes a quest line class service (G15, via `lrgDlgWordOnlyQuestLine`).
- `lrgDlgIsCommit`: a converging sibling that is a QUESTION stays a commit (G3 safety, see below).
- `lrgDlgIsBackOut`: only the ENTRY branch (`!$spoken`) now calls `lrgDlgEntryBacksOut`; the spoken branch (pt19h-safety's) is untouched.
- New: `lrgDlgSiblingCount`, `lrgDlgHandOverWords`, `lrgDlgWordOnlyQuestLine`, `lrgDlgEntryBacksOut`.

`glue/server/lorerim_glue/lib/lrg_prompt_index.php` (the norm-sharing escalation / layer tier / pattern tier my role owns; no
other fixer touched this file - every hunk of its diff against the backup is mine):
- `lrgPromptLookup`: one line - passes `$npcFacts` to the layer tier.
- `lrgPromptLayerFor($norms, $npcFacts = [])`: when the parent's links name none of a norm's rows, a row of a quest the game
  says this NPC is in (`q=`) is chosen instead of `$recs[0]`; the merge is scoped to the narrowest set naming the pick
  (parent links, else her quests' topics), every crit 2 row still merged (the pt19c arrest guarantee). Parent links still win.
- `lrgPromptByPattern`: every matching pattern row is a candidate, resolved and fail-safe-merged through `lrgPromptBest`; a
  second key with the tags KEPT (`lrgPromptNormKeepTags`, new) is tried when the norm finds nothing.
- `lrgPromptPatterns`: a pattern with no literal word (`*`) is never compiled (the catch-all rows); a possessive part (" 's")
  may lose its space; under the test seam the compiled set follows the seam's rows (it was cached once per process).

`glue/server/lorerim_glue/config/lrg_dialogue_overrides.default.json` (version 2): the five shipped rules kept, 36 added (41),
each with its `_why` and info key: G19 (Skuldafn 04DE3C, side switch 05A6B1 never_auto, J'zargo 0F1B29, secret ending
00082B never_auto, the jarl 000D64, every `TGRShell*QuitBranchTopic`, CR06/CR07/CR13, Compelling Tribute by topic and by live
text, the nine CYA Arch-Mage hand-overs never_auto), the prose-priced lines never_auto (rejoin fine 0C9A08/0B12BF/0B12C2,
buy-back 00080B, Urag 0126D7, TGBan 0B038E, DBEviction 06F999/06F99A), the fights and accepts worded as back-outs
(Aringoth 0562FD, Vigilant 000815, mission accepts `CWMission04AcceptYes*`, TGLeadership 0AA78C), Saadia's lie 057FA1,
Serana's dismissal `DLC1NPCMentalModelDismissTopic` (commit + fcommit), and every `*(fail quest)*` line (class commit +
never_auto; `(Skip Quest)` stays meta).

`glue/tools/test_dialogue.php`: section `v60. [pt19h-grading]`, 55 checks, inserted before the final printf.

## Gaps: closed and open, with evidence

Numbers come from the measurer's own engine (`pt19h-measure/m_engine.php`, `m_in.json`: 2,324 beats, 16,186 say lines)
over three trees: the measured tree + my changes only ("mine"), today's shared tree minus my changes ("others"), and today's
shared tree ("combined"). Work folder: `%TEMP%\lrg_test\pt19h-grading\` (`gapstats.py`, `compare.py`, `cmp_gm.txt`,
`cmp_gc.txt`, `m_out_*.json`).

| gap | status | before -> after | how |
|---|---|---|---|
| G3 converging answers | CLOSED for Invisible-Continue siblings with no fragment of their own; OPEN for siblings with own fragments | say lines asked once on unscripted commit targets: 272 (86 beats) -> 149 (47) mine / 159 (48) combined; 179 entries commit -> plain | `lrgDlgSiblingCount`: an Invisible Continue sibling whose own INFO is scripted=0 counts once per destination (its sorted links). Karliah 0B8382/0B838E, Savos 0263D9/0263DA, Tullius 01C5C9 now plain; "we found some kind of orb, Tolfdir wants you to see it" clicks. |
| G12 norm sharing | CLOSED where the game names her quest | targets graded with another quest's row or lent crit: 96 -> 13 | layer tier uses her quest's row and a scoped merge. Delvin 06182B crit 0, Tullius "Yes, sir." 0C3483 plain, Frea 01CB63 plain, Sorine 014362 its own row, crit 0 (still a commit by its own scripted + goodbye). |
| G15 service word | CLOSED | all 13 measured lines leave class service: Saadia 057FA1 commit, Vilkas 0A3E99 commit, Sam 0A95B9 plain, DA02alt 000880 commit, Razelan 036D50 plain, Delvin 05B490 commit, Agnis 024137 commit | a word-only match no longer grades an indexed quest line (scripted, Invisible Continue, or linked) whose topic is no service topic. Real services keep their class through their phrase or topic. |
| G16 hand-over tags | PARTLY CLOSED | hand-over probes reached on the fast path (click or her question): 8/45 -> 19/45 mine, 22/45 combined | the tag's object leads the match norm; the recipient is dropped. Only added while the line's verbatim still carries itself. |
| G19 irreversible plain + catch-all | CLOSED for every evidence line | 8 evidence lines plain -> commit (never_auto where irreversible); catch-all resolution 1 -> 0 | overrides file; pattern tier. "tell me about Skuldafn", "take me to Skuldafn later", "I ran out", "I quit", "I made a mistake" click nothing (tested). |
| G20 Serana's dismissal | CLOSED | her key picked it EXPLICIT on "I think we should part ways" / "we should part ways" -> she asks (fcommit 1) | override `fcommit`. |
| Brotherhood C15 | CLOSED | Astrid 021451 back -> plain; Destroy QE decline 000E1C meta -> commit (never_auto) | entry back-out rule; overrides. |
| spec 3.5 LETHAL fast path | already fixed (measurer) | - | regression check added (fast path do=show kind=meta, no pick). |
| S3.3 rail note kind=rail | already fixed (measurer) | - | covered by the existing v24 check (`;kind=rail;`). |

Notes per gap:
- **G3, what stays open.** (a) Siblings that run their own fragment still count individually. Faralda's eight school answers
  (0AF0DF layer) are scripted=1 each, so they stay commits; the spec harness's own beat `MG01.faralda.seek` expects ASK for a
  paraphrase. (b) Two different destinations are still a fork (Kodlak 017809 / 017805). (c) A converging sibling that is a
  QUESTION stays a commit. Without (c), the near-misses "I believe you" clicked "Why should I believe you?" (a never_red row)
  and "it's just a coincidence" clicked Mirabelle's "What coincidence?" (09BB8C). Both are asserted now.
- **G12, what stays open (13 targets).** These are quest-hint mismatches the offline hint cannot fix ("Reporting in." hint
  CWSiegeObj versus row quest CW or CWMission04), crit 1 lent by another INFO of the same topic (legitimate), DB05Alt mod rows,
  and TTF1 01AA9A "What's the problem?". That one keeps crit 2 on purpose: the pt19c ruling keeps every lethal row in the merge,
  so From the Ashes still starts only by hand.
- **G16, what stays open.** The shipped F1 dilutes one object word on long lines. "here's the letter" on the jarl's 007EC4,
  "here's the satchel" on 0D8E34 and the single-entry hand-overs (Storn 01DFB5, Crescius 0209F7, Wulf) still need her key. On
  Serana's 01667C, "here's Journal" now parks (she asks). The verbatim guard keeps "Here you go. (Show invitation)" and
  "What can you tell me about this? (Give Delvin the amulet)" clicking; on today's shared tree the reach fixer's
  `lrgDlgWordsCarry` accepts the object there as well. A full fix needs a matcher rule ("here's / take / I have the <object>"
  -> the hand-over line). That is not grading.

## Regression checks: tests added and suite tails

`tools/test_dialogue.php` v60 (55 checks). These include must-not-click rows from the measurer's examples:
- "not now, <Savos line> later", "what is the orb", "I believe you" and "it's just a coincidence";
- "tell me about Skuldafn", "take me to Skuldafn later" and "why Skuldafn?";
- "I ran out", "I quit" and "I made a mistake";
- "I can't handle it", "no, nothing I can't handle" and "never mind" on the mission accept;
- "not now, here, take them later";
- Serana "I think we should part ways" / "we should part ways" (her key and the fast path);
- the lethal "explain this letter".

Mutation check: on the measured tree without my changes, 31 of these checks fail. The rest are guards.

Suite tails on today's combined shared tree (WSL copy `/tmp/pt19h-grading-run`, 2026-09-25 ~07:30):
```
=== tools/test_dialogue.php rc=0     1183 passed, 0 failed  ALL CHECKS PASSED
=== tools/test_gates.php rc=0        844 passed, 0 failed
=== tools/test_intent.php rc=0       470 passed, 0 failed
=== tools/test_mcm_wiring.php rc=0   49 passed, 0 failed  ALL CHECKS PASSED
=== tools/test_phrases.php rc=0      47 passed, 0 failed
=== tools/test_prompt_index.php rc=0 103 passed, 0 failed  ALL CHECKS PASSED
=== tools/test_questline.php rc=1    1955 passed, 1 failed  (MS05.viarmo.comein "and that's wear i come in" - identical
                                     with my changes removed: another fixer's STT fold, not grading)
=== tools/test_scene_index.php rc=0  ALL CHECKS PASSED
=== tools/test_services.php rc=0     127 passed, 0 failed  ALL CHECKS PASSED
=== tools/test_stt.php rc=0          60 passed, 0 failed
=== test_questline --words           2193 passed, 1 failed (the same MS05 line)
=== test_questline --first-evening   463 passed, 0 failed  ALL CHECKS PASSED
=== flows                            89 scenarios: 88 passed, 0 FAILED, 1 pending (d68, gate B - by design)
```
On the measured tree + my changes only, with the measured-time tools: test_dialogue 1074/0 (1019 + my 55), test_questline
1956/0, --words 2194/0, --first-evening 222/0, flows 88 passed / 1 pending, gates 793/0, prompt_index 99/0, services 99/0.
The questline failures are identical with and without my changes on today's tree.

## What my regrading exposes (honest list, combined tree, others-only -> combined)

Correct grading removed protection that misgrading had given some lines. New fast-path clicks on the measurer's
must-not-click rows:
- **He said the line itself, or named that sibling** (by design; COVERAGE excludes these from its counts):
  - "understood" -> "Understood." (TG05);
  - "Not interested." -> "Not interested." (DA07);
  - "the Dawnguard nearly killed me" -> the sibling that says it;
  - "what's the latest news?" -> the line "what's the latest news", which the catch-all row used to HIDE (placeholder flags lent).
- **The same question asked back** on plain question lines ("wait, who are you?" -> "Who are you?", MQ02 / MQ05 / DA08 / Serana
  "What's on your mind?"). These were commits only by another quest's lent flags (G12).
- **"no, <line>" where the line is itself a negative answer** ("no, nothing I couldn't handle"; "no, we're not sure, but they
  have an Elder Scroll", Gunmar 008471, commit single). The probe agrees with the line; the generator's own skip rule should
  have skipped these.
- **Delvin "would you buy it?" -> "Will you buy it?"** (05B490, now a commit single): S4.5 step 5 by design (shares "buy",
  question to a question).
- **Owner S4.6 / AnswerWant: MQ201.return.nothing** "The Thalmor know nothing about the dragons." (03BC99) is no longer class
  back (G8), so it is an unscripted single. The breath then advances on "goodbye" / "I'm done". The leave words should cancel
  the breath there. That is not a grading change.
- **Owner pt19h-safety (G5):** MQ201.stables.malborn "has Malborn got my gear" -> "Yes, Malborn's all set." (0361DE is now
  plain: G12 removed a lent scripted flag from "Not yet."). This clicked for part of the session. On the latest shared tree it
  no longer does: safety's G5 shape rule landed and its test passes.
- **The model's T-key on now-plain converging lines** (LLM path): the gate trusts the model on plain lines, as for every plain
  scripted line today. A server rail against his refusal on plain scripted lines would belong to the gate (pt19h-safety).

New gap probes that now reach their line (improvements):
- G4 "got any work?" -> Erikur's "Let's just get to work." (converging, now plain);
- G2 "i was at hell again" -> "I was at Helgen." (COVERAGE evidence);
- G6 "let's go" on CR12;
- G16 as above.

## For the other fixers / the Build stage

- **LEAVE / pt19h-safety G8.** `lrgDlgEntryBacksOut` narrows class back only for "nothing" inside an answer and for
  "not sure, but ...". Scripted or commit back-out-worded lines (Aringoth, Vigilant) are commit + never_auto by override, so LEAVE
  no longer takes them. The LEAVE fix itself (skip scripted/commit back entries) is still G8's.
- **Follower table.** DLC1NPCMentalModel's "Wait here." (01508F) is graded commit by scripted + goodbye, because the verb
  table's globs miss `DLC1NPCMentalModel*`. That makes her ask before waiting. It is safe, but more friction than needed; it
  belongs to the follower config (G6), not grading.
- **pt19h-money.** The overrides mark the prose-priced lines never_auto. The money fixer's prose cost (now in
  `lrgDlgDecorateEntries`) makes them class pay with "[costs N septims]"; the two agree, and no override label hides the price.
- **Performance.** Under the test seam, `lrgPromptPatterns` re-reads the seam rows per call (cheap, compiles only when they
  change). test_questline takes 10.3-11.4 s against 10.5 s before. On the live server it is still one query per request.
