# pt19h harness fixer - questline rulings, the F. / W. fold, `--extended`, Sven and Alvor

2026-09-25, 06:00-06:55 EDT. Role: the HARNESS FIXER (owns `tools/test_questline.php`, `tools/fixtures/*.json`,
`tools/flows/questline_adapter.php`, section 5d of `tools/test_prompt_index.php`; the owner page only on the lines that name
Sven's count). No server code, no .psc, no config was touched; nothing was compiled, deployed or installed.

Work folder: `%TEMP%\lrg_test\pt19h-harness\` (staging `s0` = the tree as found at 06:00 plus only my files, `s1` / `s2` = fresh
stagings of the live tree at 06:40 / 06:50 while the other pt19h fixers were editing; `esp\` = the plugin probes; `cov\` = read-only
copies of the three research inputs; scripts `pt19h-harness_stage.sh` / `pt19h-harness_run.sh` in `%TEMP%\lrg_test`).
Backups (first edit only): `glue\.backup\pt19h-harness\{test_questline.php,lrg_questline.json,lrg_questline_adversarial.json,test_prompt_index.php,OWNER_MENULESS_V1.md}.bak`.

## 1. Files

| file | change |
|---|---|
| `tools/test_questline.php` | `--extended` (runs `tools/fixtures/lrg_questline_extended.json`; options `--group= --beat= --shard=k/N --ext-out=<json>`), `--build-extended=<coverage dir>` (writes that fixture), the companion is not loaded in extended mode, docblock. The standard modes are untouched (same checks, same counts) |
| `tools/flows/questline_adapter.php` | NEW. The extended runner and the fixture builder; every path it drives is the harness's own (`qlLayer`, `qlSeam`, `qlFast`, `qlLlm` ...) |
| `tools/fixtures/lrg_questline_extended.json` | NEW, 4.9 MB, one beat per line: 2,005 coverage beats + 260 Dark Brotherhood beats (converted), the 107-entry quarantine apart, 19 Brotherhood no-line beats listed, 6 recorded corrections, the measurer's per-gap sentences (1,268 never_red + 1,275 resolve rows) |
| `tools/fixtures/lrg_questline.json` | +30 F. beats (Lane F's extra run), +36 W. beats (the walkthrough), FE.riverwood.plain's root gets Sven's fourth line, new FE.riverwood.plain.ralof (104 -> 171 beats; every old beat byte-identical except FE.riverwood.plain's `layer` + new `root_evidence`) |
| `tools/fixtures/lrg_questline_adversarial.json` | FE.riverwood.plain: the same root (its metadata is the main beat's), `root_evidence`, the stale `ruling_note` replaced |
| `tools/test_prompt_index.php` 5d | +4 checks: Sven's root rows are top-level rows on their topics; the extended fixture parses, was measured on this index, and all 2,350 of its target rows are in this index on their topics |
| `OWNER_MENULESS_V1.md` | lines 119-122 only: Sven's list is FOUR lines (five on the Ralof path), not three (section 4 below) |

## 2. Gap: the 31 @spec_row lines - CLOSED (by the pt19c final fixer at 05:03; verified here)

The critic's two recommended rulings were already applied in both fixtures by the final fixer (research/pt19c-final.md 1):
MQ102.balgruuf.intro and MQ105.arngeir.summons are `stageB explicit` with every line's measured `via` (explicit / park, `spec_via`
pick kept) and a `ruling` field; FE.riverwood.plain's "sing me something about dragons" is a say line, `via pick`, `spec_via never`.
No `known` entry and no `spec_row` reason is left in either fixture. On the 06:00 tree, before any change of mine:
plain 1956/0, `--words` 2194/0, `--first-evening` 222/0, `--first-evening --words` 247/0, `--stage=B` 39/0 - all green, no floor
touched. The regression rows are those beats themselves; FE.riverwood.plain.ralof (section 4) also asserts the pick on the
five-line root. The stale adversarial `ruling_note` ("... a known @spec_row entry until the spec owner rules") is replaced.

## 3. Gap: fold the F. / W. extra-run beats - CLOSED

- **F. (30 beats, `pt19c-Ffix2/fix2_fixture.json`)**: appended as Lane F's builder does (quest FIX / WALK -> FE, an open beat keeps
  its quest, `fe` 1, ids kept because owner page section 7 names them). Brought up to date: F.balgruuf.intro and F.arngeir.summons
  follow the 2026-09-25 ruling (`stageB explicit`, `ruling` pointing at the tree beat; the old `ruling_note` said "known @spec_row");
  every `merge_note` said "each line the merge moves is a known entry" - there is none; rewritten. Each beat carries `folded`.
  Before the fold the extra run was already green on the 06:00 tree (75/0).
- **W. (36 of 45 beats, `pt19c-walk/walk_fixture.json` md5 b56b7929)**: the task says F. / W.; Lane F copied only six of them.
  Folded: every green W. beat (rumours, never-lines, the Irileth B2 / door / news / along beats, the AP skip / start, the carriage and
  room chains, the U1 / U5 opens, the open beats of Farengar, Arngeir, Kodlak, Mirabelle, Faralda, Brynjolf, Viarmo, Alvor, Sven, the
  DB01 guard). W.open.driver is folded with its snapshot faction corrected: the walkthrough guessed `CarriageSystemVendors`; the
  drivers' faction is `CarriageSystemFaction` (research/pt19c-final.md 2) - with it the U1 carriage open holds (3/3; with the guess
  it failed "may not open her menu here (U1)").
  Left out (reasons also in `%TEMP%\lrg_test\pt19h-harness\fold_w.php`): W.vilod.plain, W.corpulus.plain, W.corpulus.pet, W.open.anyone,
  W.open.vilod, W.open.corpulus (Lane F's corrected F. copies are folded instead); **W.irileth.B1** ("a dragon burned Helgen to the
  ground" asserts `park`, the build clicks it `explicit` - moving a line toward a click is not the harness's to do; for the spec owner);
  **W.open.balgruuf** ("The dragon is dead." opens, but with marker `qrows` where the beat says `toplevel` - a label for the spec
  owner); **W.trainer** (root topic OffersTrainingTopic has no top-level row - a fixture slip).
- Result (06:00 tree + my files): `--first-evening` 222 -> **463 passed, 0 failed**; `--first-evening --words` 247 -> **530/0**;
  the default run is untouched (1956/0: every folded beat is `fe`); test_prompt_index 5d pins every folded layer (103/0).
- Not mine to edit: owner page section 7 still says the F. / W. beats "are being moved into the standing first-evening check" -
  that move is now done (Lane F / the orchestrator can drop the "extra run" / "walkthrough" marks).

## 4. Gap: Sven's speaker and conditions, Alvor's scene owner (spec 3.7) - DONE, and Sven's count was WRONG

Method: `glue/tools/esp_dump.py` imported unchanged (its `subrecords()`, its record-header walk and zlib rule) by a filter,
`%TEMP%\lrg_test\pt19h-harness\esp\esp_pick.py` (esp_dump.main dumps every record - millions of lines for Skyrim.esm - so the
wrapper prints only named FormIDs and decodes CTDA). Winners over all 3,496 active plugins of Ultra: `sven_scan.py` / `sven_npc.py` /
`alvor_npc.py` (they import `tools/build_prompt_index.py`'s LoadOrder unchanged). Condition function ids checked against
CommonLibSSE-NG `TESCondition.h` (46 GetDead, 359 GetInCurrentLoc, 403 GetRelationshipRank, 491 GetMapMarkerVisible, 543
GetQuestCompleted). All read-only.

**Sven (NPC_ Skyrim.esm:01347F; winner LoreRim - Outfit Distribution.esp).** Every top-level player line of the load order whose
conditions name him:

| INFO (winner) | line | conditions | first evening in the inn |
|---|---|---|---|
| 0BB965 (USMP; topic renamed DialogueRiverwoodSvenTiberSeptimTopic) | Do you know any old ballads about dragons? | GetIsID(Sven) == 1 - one condition | yes |
| 0BCCC6 (USSEP) | Is there somewhere I can buy fresh supplies? | GetDead(ref) == 0, GetIsAliasRef(9 = Sven of DialogueRiverwood_Revised), GetInCurrentLoc(RiverwoodLocation) | yes |
| 0BCC9B (AlternatePerspective) | I saw a dragon in Helgen. | GetQuestCompleted(MQ101), GetStage(MQ102) < 110, alias Sven | yes |
| **moretosayriverwood.esp:000902** | **How do I get to Whiterun from here?** | GetIsID(Sven), GetInCurrentLoc(RiverwoodLocation), GetMapMarkerVisible(WhiterunMapMarkerREF) < 2 | **yes** - the inn's LCTN (winner Lux - Ryn's Sleeping Giant patch) has PNAM RiverwoodLocation, and the marker test holds before Whiterun is found |
| moretosayriverwood.esp:00086E | I think Hod might think you drink too much. | GetRelationshipRank >= 0, GetStageDone(MQ102B, 40), GetIsID(Sven) | Ralof path only |
| skyrim.esm:0BB964 (USSEP, MS05Start) | Where did you learn to play so well? | not Talsgar, GetInFaction(BardSingerFaction), GetIsID(Sven), GetStage(MS05Start) < 10 | no: his winning NPC_ has BardSingerFaction rank -1 |
| the other More to Say Sven lines | (the mill, Camilla, the barrow, Solitude, the Throat ...) | outside the inn / later quests / a negative relationship | no |

The final fixer's probe (pt19c-Efix mts.sh) had missed 000902. So the owner page's "exactly three" was wrong: **four** on the Hadvar
path, **five** on the Ralof path. Fixed:
- owner page lines 119-122: "it should be four (the ballads, the stores, the dragon, and "How do I get to Whiterun from here?" - More
  to Say gives him that one in Riverwood). If you escaped with Ralof, a fifth, "I think Hod ..." ...". Nothing else on the page moved.
- FE.riverwood.plain (main + adversarial): the fourth root line `{ACFRiverwoodConversationsWhiterunBranchTopic,
  moretosayriverwood.esp:000902}` and `root_evidence`; new FE.riverwood.plain.ralof (five lines) with the verbatim line, two
  paraphrases, "know any songs about dragons?", "sing me something about dragons" (all pick) and two never lines. Re-measured: all
  still pick (11/11, 5/5 + 2/2 never, adversarial 5/5 + 2/2) - the ruling's pick holds on the real root.
- test_prompt_index 5d pins the five rows (top-level, on their topics).
Caveat kept on the page: generic lines keyed to factions or voice types are not in this census; the owner's in-game count stays the
final check ("Any other extra line ... please write it down").

**Alvor.** SCEN Skyrim.esm:02BFAC `MQ102HadvarAlvorScene`, record PNAM = QUST 02BF9C **MQ102A** - and no other active plugin
carries the SCEN, so that is the winner. His lines: 041F16 / 0C65A8 "Hadvar said you could help me out" GetIsAliasRef(3),
GetStageDone(MQ102A, 20) == 1, GetStageDone(MQ102A, 40) == 0; 02C44F "Do you have any supplies I could take?" GetStageDone(MQ102A,
40) == 1, GetStageDone(MQ102A, 50) == 0 - never on the list together. The page's `sq=MQ102A sqj=0 sj=0` line and its order (Hadvar
line first, then supplies) are right: no change.

## 5. Gap: `--extended` - CLOSED (the mode and the fixture); the rows it asserts are RED until the G fixers land

What it does (docblock of `tools/flows/questline_adapter.php`):
- **lists**: the index first (`qlLayer`: layer line, parent links, named top-level topics), else the fixture's own list (siblings,
  hand-built roots, prompt-less greetings - the coverage team's method); 715 index-built, 1,629 list-built, 28 no line, 0 unbuildable.
- **asserted** (fail the run): every `never` and `not_target` row clicks nothing on the fast path, and the WillEmit twin. Not a defect,
  counted apart: he said another listed line word for word (`sibling-verbatim`; contractions spelled out, same question/statement
  shape) or named it (`sibling-named`: all his content words in that line, same shape, same polarity); the S4.6 breath on an
  unscripted single unless his words refuse (the lib's step 0 or the harness's own refusal / deferral / hedge reading); a row naming
  a prompt of the target's own topic the engine never shows beside it (`variant`); a row that is the target line itself (`is-target`).
- **reported**: `never_red` rows by gap id (still red / green; G13 / G20 rows count her key, not the fast path); every say / say_model
  / misses line by outcome - fast-path click / her T-key / she asks (words park or key park) / nothing / wrong line / menu handed
  back - with percentages by source, by group, and against the coverage record's `fp`; the measurer's per-gap sentences (never_red
  G1 G5 G9 G10 G11 G13 G20, must-resolve G2 G3 G4 G6 G16 G18: the tracker for the other fixers - compare research/pt19h-measure.json
  `still_live`); the quarantine in its own table (still_clicks still click / green); G17 no-line counts. `--ext-out` writes every line.
- **conversion** of the Brotherhood: its layers stay info_keys and its lines stay `<index txt>` templates (its content policy), filled
  from the index at run time; probe shapes that only repeat the line (an echo of a question line, "no, <a refusal>") are skipped, as
  the measurer did; paraphrases the verifier expected to do nothing are `misses`, not never rows.
- **fixture corrections** (in the fixture's `corrections`, each with evidence): Durak's "where do I sign up to kill vampires" never ->
  say (the measurer's G18 note; quarantine); "I'll become a vampire" on DLC1VQ04.serana.soultrap and the four "tell me about the <X>
  jobs" rows on Vex's job list never -> not_target (each names a SIBLING line, clicking that is what he asked for).
- Runtime 115-145 s (a separate mode, not inside the standard run's 60 s budget).

Results. On the 06:00 tree (the code the measurer measured) the harness reproduces the measurer:

```
say lines 16,747: fast-path click 59.9% | her T-key 18.9% | she asks 19.0% | nothing 1.9% | WRONG line 0.1% (21) | menu handed back 0.3%
never 2,466 ASSERTED: click 64 (49 on a protected line)         (measurer: 67 / 49)
never_red (coverage) G1 245/1122 red, G5 177/531, G9 20/39, G11 156/261
measurer rows      G1 902/906 red, G5 11/11, G9 57/63, G10 11/14, G11 197/199, G13 67/73 (her key), G20 1/2 (her key)
must-resolve       G2 fast 16/394, G3 fast 13/271, G4 fast 0/371, G6 0/169, G16 0/46, G18 0/24
quarantine         still_clicks 42 of 116 still click
2745 passed, 64 failed  (134.3s)  RESULT: FAILED
```

On the live tree at 06:50 (other fixers mid-edit; informational - the Build stage runs a frozen tree):

```
say lines 16,747: fast-path click 61.8% | her T-key 16.9% | she asks 19.0% | nothing 2.0% | WRONG line 0.1% (14) | menu handed back 0.3%
never 2,466 ASSERTED: click 12 (5 on a protected line)
never_red (coverage) G1 4/1122 red, G5 23/531, G9 3/39, G11 1/261
measurer rows      G1 10/906, G5 0/11, G9 12/63, G10 1/14, G11 1/199, G13 2/73, G20 0/2
must-resolve       G2 fast 214/394, G3 88/271, G4 273/371 (14 WRONG line), G6 104/169, G16 5/46, G18 14/24
quarantine         still_clicks 2 of 116 still click
2797 passed, 12 failed  (138.7s)  RESULT: FAILED
```

The 12 `never` rows still clicking at 06:50 (owners: the G fixers):
DB04a.amaund.goon "go away" (single "Go on."); DB04a.delvin.buy "would you buy it?" (single "Will you buy it?", protected);
DarkBrotherhoodSanctuaryRepair.delvin.refit "can the Sanctuary be repaired?" (protected); MG07.tolfdir.mirabelle "Mirabelle is fine"
(a statement clicks "Where's Mirabelle?"); MQ05.neloth.anotherplace "I learned a new word"; MS01.margret.business "what do you think
of Markarth"; MS05.tullius.coat "nice coat" ("I need your coat."); MS05.viarmo.verse1 "who wrote the first verse";
MQPaarthurnax.delphine.spare "Paarthurnax is dead"; TG00.brynjolf.howknow "my wealth is my own business" (protected);
TGBan.vex.gold "okay" and "yes" (single "Here's the gold." - the 1,000-septim reparation, G14).

## 6. Suite tails (06:00 tree + my files, staging s0, run 06:47-06:50)

```
== questline: rc=0 secs=16            1956 passed, 0 failed, 0 known   ALL CHECKS PASSED
== questline_words: rc=0 secs=18      totals: ask 1, breath 32, nothing 698, resolve 739   2194 passed, 0 failed, 0 known   ALL CHECKS PASSED
== questline_fe: rc=0 secs=4          463 passed, 0 failed, 0 known    ALL CHECKS PASSED
== questline_fe_words: rc=0 secs=5    totals: nothing 131, resolve 144   530 passed, 0 failed, 0 known   ALL CHECKS PASSED
== questline_stageB: rc=0 secs=1      39 passed, 0 failed, 0 known     ALL CHECKS PASSED
== test_prompt_index: rc=0 secs=1     103 passed, 0 failed             ALL CHECKS PASSED
== test_services: rc=0 secs=0         99 passed, 0 failed              ALL CHECKS PASSED   (reads the owner page)
== run_flows: rc=0 secs=37            89 scenarios: 88 passed, 0 FAILED, 1 pending (d68, gate B); 1600 checks, 0 warnings
== questline_ext: rc=1 secs=134       2745 passed, 64 failed  RESULT: FAILED   (section 5: the G fixers' rows)
```

Live tree at 06:50 (staging s2): the same except `questline` 1955/1 and `--words` 2193/1, both on the main fixture's
MS05.viarmo.comein STT line "and that's wear i come in" (via `pick`, now a fast `single` - a faster click of the right line), and
`--extended` 2797/12. At 06:40 (s1) the one red line was the adversarial MQ103.farengar.stone "this old stone here?" (via `single`,
then picked by her key). Both are paths other
fixers moved while editing; neither is a floor and neither is a wrong line. At the Build stage's frozen tree the owning fixer, or the
spec owner, re-labels whichever still holds ("a click on the target under another label: a human decides" - `--remeasure` lists it).

## 7. Still open (honestly)

- `--extended` is RED: 64 `never` rows on the 06:00 code, 12 on the 06:50 tree (section 5). Those are defects for the G1 / G5 / G9 /
  G10 / G14 fixers, not harness faults; the mode is not part of gate A's standard runs.
- W.irileth.B1 (park vs explicit) and W.open.balgruuf (marker qrows vs toplevel): spec-owner labels; not folded.
- Owner page section 7's "being moved into the standing first-evening check" is stale now (not a count line, not mine).
- Seen in passing, not my file: `tools/build_prompt_index.py`'s FN table names condition 359 `GetFactionRankDifference`; CommonLibSSE-NG
  says 359 is GetInCurrentLoc (60 is GetFactionRankDifference). It only labels the header's `kinds` census (1,114 rows); no grading
  reads it.
- The extended fixture's say-line `via` values are the coverage team's; the mode reports them, never asserts them (the critic's
  "2,412 unique failures ... the old fixture's layer or mode mismatches" do not reach the verdict).
