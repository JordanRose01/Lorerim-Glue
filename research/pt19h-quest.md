# pt19h-quest: the quest-entry table (G17) and faction words that took quest lines (G18)

2026-09-25. The QUEST FIXER's report. Server only: no Papyrus change, `CurrentVersion` stays 513, nothing deployed or installed.

## What changed

| file | change |
|---|---|
| `server/lorerim_glue/config/lrg_quest_entries.default.json` | **new**. 8 rows covering 9 INFOs: the PROTOCOL 10.26 click-free quest-entry table, extended beyond the enlistment rows |
| `server/lorerim_glue/lib/lrg_factions.php` | The table's loader, matcher, plan, locked line and rule. The G18 hand-off fixes. The G1, G5 and G11 guards on the enlistment hand-off. `lrgFacEffectFor` now also searches the table |
| `server/lorerim_glue/lib/lrg_actions.php` | Quest-entry plumbing only, in `lrgVoicedWhat`. A table row's refusal says "the step Jordan asked for", never "enlistment". It reads the `table=1` mark in `qe_lic`, because the funcret is decided pre-lock, where `lrg_factions.php` is not loaded |
| `tools/test_gates.php` | New section **43. [pt19h-quest]**, placed after the money fixer's section 42. 35 checks |

Backups are in `glue/.backup/pt19h-quest/`: `lrg_factions.php.bak`, `lrg_actions.php.bak` and `test_gates.php.bak`.

**PROTOCOL.md is not mine to edit.** Section 10.26 needs one paragraph on the table; the text is under "For the docs owner" at the end.

## G17: the table

### How rows were chosen

I decoded every `scripted_entry_candidates.json` row from the load order itself. `glue/tools/build_prompt_index.py`'s reader was run read-only over `/mnt/f/Modlists/LoreRim`, profile Ultra. For each row it read:

- the winning INFO's conditions;
- every TCLT path up to a player topic, with each ancestor's conditions, its VMAD (a fragment) and its DIAL category;
- the owning QUST's dialogue conditions. None of the quests used here have any.

The tooling is in `%TEMP%\lrg_test\pt19h-quest\`: `dump_conds.py`, `qust_conds.py`, `classify.py`, `conds.json`, `paths.json` and `verdict_*.json`.

A row qualifies only when one path satisfies all four rules below. That path's conditions become the row's `conds`.

1. Every condition on the path maps to a key `LRG_Main.CmdQuestEntry` re-checks today:
   - `GetIsID` becomes `isid`;
   - `GetStage <` or `<=` becomes `max`;
   - `GetStageDone == 0` becomes `notdone` (or `qnd` for another quest);
   - `GetQuestCompleted == 1` becomes `qdone`;
   - `IsInDialogueWithPlayer` is benign.
2. No ancestor carries a fragment. Otherwise the path changes state before the line, and a click-free `SetStage` would skip it.
3. Every node is a player topic (category 0), and the root is a top-level topic.
4. `max` is set to `min(ceiling, stage - 1)`, and `notdone` includes the stage itself. This keeps the entry idempotent and means it never lowers a quest.

**Results over the 55 rows graded `use=entry` (plain):**
- 54 were decoded. `0DDE41` was not, but the index gives it a `GetIsAliasRef` condition, so it could not qualify anyway.
- **11 pass** the rules above. **8 rows (9 INFOs) ship.**
- 2 of the 11 were dropped by hand:
  - `MQ204.arngeir.notpuppet` (0B3970): its only clean path starts at an Invisible Continue root, 03FF9D.
  - `MS10.orthus.proof` (029A94): its root is a blank-prompt topic, and "Do you have any proof of that?" is too generic to stand as his explicit sentence for starting a quest.
- **9 need a floor.** A floor is a `GetStageDone(..) == 1` or `GetStage >= / ==` condition, which no existing `CmdQuestEntry` key can re-check. They are listed under "Still open".
- **34 cannot be re-checked by the game at all:**
  - `GetIsAliasRef`: 18;
  - `GetVMQuestVariable`: 13;
  - an OR on the speaker (`GetIsID`): Esbern's door, DA06;
  - a fragment on an ancestor: 9;
  - a Hello (category 7) root: Paarthurnax 055870.

  Some rows fail for more than one reason.
- The **18 `avoid` rows are excluded**, and section 43 (a) asserts it.
- **None of the 121 `confirm_first` (commit) rows is added.** 5 of them decode clean with existing keys: CWCRQuest.champion, MQ204.arngeir.wonthelp, MQ204.paar.whoami, MQ306.arngeir.portal and MQ306.delphine.dead. Another 20 need a floor. See "Still open".

### The rows

The table below records every row's info_key, quest, stage, conditions and the coverage evidence.

| row | speaker | info_key | quest | stage | `conds` sent to the game | decoded path (the evidence) | coverage |
|---|---|---|---|---|---|---|---|
| CW02B.galmar.mission | Galmar Stone-Fist | skyrim.esm:057BD0 | CW02B | 30 | isid 82216 (0x014128); notdone 30,40; max 29 | top-level: GetIsID(Galmar); GetStageDone(CW02B,40)==0 | cw_stormcloak, fx SetStage 30; resp "I've found the final resting place of the Jagged Crown..." |
| MQ204.arngeir.shout | Arngeir | skyrim.esm:03F891 (+ say-once twin 03C1A7) | MQ204 | 20 | isid 181959 (0x02C6C7); notdone 20; max 19 | top-level: GetIsID(Arngeir); GetStage(MQ204)<30. The twin has <50 | mq2, TIF__0003F891 / TIF__0003C1A7 = SetStage 20 |
| MQ205.arngeir.howfind | Arngeir | skyrim.esm:077359 | MQ205 | 45 | isid 181959; notdone 45; max 44 | parent 07735B "I talked to Paarthurnax." (top-level, no fragment): GetStageDone(MQ205,45)==0 | mq3, tif__00077359 = SetStage(45) |
| MQ205.elderscroll.ask | Esbern | skyrim.esm:0BD16D | MQ205 | 50 | isid 78680 (0x013358); notdone 50; max 49 | top-level: GetIsID(Esbern) only | mq3, tif__000bd16d = SetStage(50); UESP 50 = "point to the College" |
| MQ301.esbern.plan | Esbern | skyrim.esm:0D070F | MQ301 | 18 | isid 78680; notdone 18; max 17 | 07D42B (no fragment), then root 0D070C "I have good news." (top-level): GetStageDone(MQ301,18)==0; GetStage(MQ301)<30 | mq3, tif__000d070f = SetStage(18) |
| MQ301.paar.where | Paarthurnax | skyrim.esm:04591B | MQ301 | 20 | isid 247164 (0x03C57C); notdone 20; max 19 | root 045919 "I defeated Alduin, but he escaped." (top-level, no fragment): GetStage(MQ301)<20 | mq3, tif__0004591b = SetStage(20) |
| MQ301.arngeir.lure | Arngeir | skyrim.esm:02673B | MQ301 | 21 | isid 181959; notdone 21,25; max 20 | 02673C, 026735, 026733 (no fragments), then root 026736 (top-level): GetStageDone(MQ301,25)==0 | mq3, tif__0002673b = SetStage(21) |
| MQ301.esbern.lure | Esbern | skyrim.esm:07D43B | MQ301 | 22 | isid 78680; notdone 18,22,25; max 21 | every path passes 07D42C (25 not done), then 0D070C (18 not done, <30); no fragments | mq3, tif__0007d43b = SetStage(22) |

Each row also carries:
- `line` and `words`: its accepted sentences, including the coverage's own STT forms such as "all do in" and "dragons reach";
- `meaning`: the voiced fact;
- `say`: her one line, taken from the INFO's own response text;
- `hint`: the corner note;
- `evidence`: comment only, never sent.

### When a row acts

| his sentence to that NPC | what happens |
|---|---|
| A menu of hers is open | Nothing. The list on his screen carries the line |
| Menuless on, and she is not an ambient scene actor | Nothing. The E-press or the pre-LLM open carries the real line (the v1.0 visible menu wins) |
| Ambient actor, and the open is possible | Nothing. The open brings her list (open-first) |
| A voice order or an escort order owns the sentence | Nothing |
| **ml=0 (as 10.26), or an ambient actor the game cannot open on** (`lrgFacOpenImpossible`: io off, last open refused, **no click verified yet**, too far, combat) | The table plan runs, as below |

The plan then sets one state:
- **engine-on**: CHIM's quest engine is on, so the table stands down.
- **quiet**: quiet mode is on. Nothing is sent.
- **stale**: the facts line is older than 300 s.
- **no-stage**: the quest is not on `qst=`.
- **already**: the quest stands at or past the stage, or past `max`, on the facts line or on `exec_qst`.
- **pending**: `facexec` shows the same entry went out inside 120 s.
- **queued**: every check passed. `lrgFacQuestNet` sends ONE `ExtCmdLRG_QuestEntry`, on D1 only.

Every state gets its own locked line, so she is never silent. No state's line names a quest id or a stage number.

- **Never licensed.** `executed` is never set, and her line says the game has NOT confirmed the step. The game's OK is voiced from the row's `meaning` and `say` through the existing `lrgQuestEntryResult`.
- **One carrier.** `lrgFacRefusesOpen` refuses the open when the table has queued, and the pre-LLM interlock already stands down on `plan.state == queued`.
- **His explicit sentence only.**
  - First, strip the vocative, then up to 4 leading and 3 trailing fillers, her name tokens and titles.
  - His words must then EQUAL the line or one of `words`. Containment and similarity are never used.
  - Never an echo: a trailing `?` on a line that asks nothing.
  - Never a question shape on a statement line: `lrgFacClauseAsks`, the case behind "is it true that ...".
  - Never a refusal on a line that refuses nothing.
  - Never a bargain.

  A negation, a deferral, a hedge or a near-miss breaks the equality by itself.
- **The loader** skips a whole row if:
  - `conds` carries any key other than isid, qdone, notdone, max or qnd;
  - it has no `isid`;
  - `max` is outside 1..stage-1;
  - it has no accepted sentence of 3 words or more.

## G18: faction words took quest lines

### Fixes in `lrg_factions.php`

1. **The hand-off stands aside for a quest line** (`lrgFacDeferTo` and `lrgFacEntryJoins`). When no join entry is found by topic or exact line, the hand-off now returns `null`, and the ordinary matchers decide with every rail. It does this in two cases:
   - a line on her list is itself an enlistment for the same faction, in its own first-person words. Sentence ends count as clause ends, "I want you to turn me into ..." counts as his own request, and a few entry-only word-bound shapes are added ("turn me into a", "I'll become a", "and become a");
   - his leading yes answers a yes-line on her list.

   A list with no such line keeps pt16's rule. Aldis's "Are you with the Legion?" is no enlistment, so nothing is clicked.
2. **Hunting vampires is the Dawnguard.** The dawnguard row gains:
   - "hunt vampires", "kill vampires", "killing vampires", "kill some vampires" and the other vampire-hunting phrases;
   - the STT's "dawn guard(s)", which the one-token `guards` row had been taking.
3. **The object of the ask wins.** A faction word within 2 tokens after the phrase decides the faction ("join the Stormcloaks and fight the Empire" is the Stormcloaks). A tie, such as "the college", keeps the old rule (tiers, then ambiguity).
4. **Asking for the way in stays an ask** (`lrgFacWayIn`). "Where do I sign up", "how would someone go about joining ..." and "where do I go to sign up for this vampire hunting thing" are asks. "Why would I join" and "what does it take to join" still ask ABOUT joining.
5. **A wh-question that IS the line's own question on a commit stands aside for the explicit test.** Faction mode vetoes every wh-question on a commit, which would otherwise click nothing where the line itself was said, as with "Killing vampires? Where do I sign up?".

### G1, G5 and G11 inside the enlistment hand-off

These are in my file (`lrgFacAskActs` and `lrgFacClauseAsks`).

The hand-off used to run before any refusal test. Now his words own no click (`lrgFacArbitrateWant` returns false, and `lrgFacClickAsk` returns nothing) and queue no 10.26 entry (`lrgFacQuestPlan`) when they:
- refuse or defer (`lrgDlgRefuses`);
- hedge ("maybe", "might", "one day", ...);
- carry a deferring tail ("later", "tomorrow", "after I");
- ask in the ask's own clause with anything other than a request for the way in:
  - echoes ("wait, I'm here to join the Dawnguard?");
  - advice ("do you think I should join the Legion?");
  - "does this make me a bard".

Requests still act: "can I join ...", "do you know where I could sign up ...", "would like to join the companions" (the STT dropped the "I"). The faction truth still rides in `<locked_facts>` either way.

`subject_stops` gains anyone, anybody, someone, somebody, everyone, everybody and people. So "can anyone join the Dawnguard?" is no ask.

### The measurer's G18 rows, before and after

Each row gives the fast path (want=1) on the measurer's engine, the same index and the same lists.

| he says | info_key | before | after |
|---|---|---|---|
| I just want to join the Legion, consider the crown a gift | 05A6A6 | nothing (hand-off) | **clicks the target** (the rails decide: intent) |
| Keep the crown, I just want to join. | 05A6A6 | nothing | **clicks the target** |
| Honestly I just want to join the Legion, you can have the crown as a gift | 05A6A6 | nothing | **clicks the target** |
| i just want to join the lesion | 05A6A6 | nothing | **clicks the target** |
| I made a mistake. I want to be a Stormcloak. The crown belongs to you. | 05A6B1 | nothing (hand-off) | the hand-off stands aside; the line is `never_auto` (G19 overrides), so **she asks** |
| I want to be a Stormcloak, uh, take the crown | 05A6B1 | nothing | she asks (never_auto) |
| i'm here to join the dawn guards | 00D901 | nothing (went to the `guards` row) | **clicks the target** (explicit) |
| Yes sir, I want to join the Stormcloaks and fight for Skyrim | 0C347D | nothing | **clicks "Yes, sir."** |
| make me a vampire, Serana | 004A79 | nothing (hand-off) | the hand-off stands aside; no match, so her key decides |
| bite me, I want to be a vampire | 004A79 | nothing | **clicks the target** (plain: it opens her confirmation) |
| count me in, I want to hunt vampires | 00D8E2 | nothing (went to Volkihar) | **clicks the target** |
| I'd like to join up and kill some vampires | 00D8E2 | nothing | **clicks the target** |
| killing vampires where do i sign op | 00D8E2 | nothing | **clicks the target** |
| I accept your offer. I'd like to join the Stormcloaks | 04BE5F | nothing | **clicks the target** (explicit) |
| I'd like to join the Stormcloaks | 04BE5F | nothing | **clicks the target** |
| I accept, I'll join the Stormcloaks | 04BE5F | nothing | **clicks the target** |
| i except your offer id like to join the stormcloaks | 04BE5F | nothing | **clicks the target** |
| fine, make me a vampire | 003C1B | nothing | the hand-off stands aside; no match, so her key decides (irreversible: asking is right) |
| where do I sign up for the Companions? (said to Durak, **must not click**) | 00D8E2 | **clicked Durak's line EXPLICIT** | **nothing** |

**Still nothing on the fast path:**
- "I'm, uh, here to join the Stormcloaks and fight the Empire" (05A6A3). Ulfric's line "I'm here to join your fight ..." is not read as an enlistment, because "your" is an object stop. It now parses as the Stormcloaks.
- "count me in, can I join the Companions" (0CF2D7). Aela's "Can I join you?" has the follower object stop.
- "does this make me a bard" (05C61E). A question does not act; her words answer.
- "sign me up with the Stormcloaks" (04BE5F). The hand-off stands aside, but the ordinary matcher finds no match.
- "yes, make me a vampire" (003BA1). The hand-off stands aside; there is no match and the line is irreversible.

## Evidence

### The full extended and Brotherhood fixture, before and after

I ran the measurer's own engine (`%TEMP%\lrg_test\pt19h-measure\m_engine.php`: fast path want=1 plus her T-key) over all of `m_in.json`: 2,324 beats and about 39,000 utterances. It ran twice on one frozen tree: once with my three files swapped for their backups ("base") and once with them ("mine"). Each of the 4 shards ran in its own glue copy, because the engine parses its emits from the shared log.

The diff is in `%TEMP%\lrg_test\pt19h-quest\cmp_measure.py`. The only utterances whose outcome changed:

**Summary:** 14 say lines now click their target; 0 say lines were lost. 25 must-not-click probes on enlistment commits no longer click: "not now, X later", "not yet, X", "I'm not sure, X", "no, X", "X?", "is it true that X" and "wait, X?" on Galmar's Oath, Tullius's join and the soldier's howjoin. 1 `never` row now clicks its target. That row ("I'd like to join up and kill some vampires", 00D8E2) is also a `say` line on the same beat and is one of the measurer's G18 examples; see "Fixture data" under Still open. No other utterance on any beat changed, on either the fast path or her T-key. The table itself cannot change this engine's numbers: the engine runs with a menu open, and the table never acts while one is.

Utterances measured: 39052 on 2324 beats. Changed: 40.

| class | beat | info_key | he says | base fast path | mine fast path |
|---|---|---|---|---|---|
| never | DLC1VQ00.durak.signup | dawnguard.esm:00D8E2 | I'd like to join up and kill some vampires | nothing | **target** (explicit) |
| probe | CW00A.soldier.howjoin | skyrim.esm:0D3C5A | not now, How does one join the Imperial Legion later | **target** (faction) | nothing |
| probe | CW00A.soldier.howjoin | skyrim.esm:0D3C5A | not yet, how does one join the Imperial Legion | **target** (faction) | nothing |
| probe | CW00A.soldier.howjoin | skyrim.esm:0D3C5A | I'm not sure, how does one join the Imperial Legion | **target** (faction) | nothing |
| probe | CW00A.soldier.howjoin | skyrim.esm:0D3C5A | no, how does one join the Imperial Legion | **target** (faction) | nothing |
| probe | CW01B.galmar.ready | skyrim.esm:0E2D06 | not now, I'm ready to take the Oath later | **target** (faction) | nothing |
| probe | CW01B.galmar.ready | skyrim.esm:0E2D06 | not yet, i'm ready to take the Oath | **target** (faction) | nothing |
| probe | CW01B.galmar.ready | skyrim.esm:0E2D06 | I'm not sure, i'm ready to take the Oath | **target** (faction) | nothing |
| probe | CW01B.galmar.ready | skyrim.esm:0E2D06 | no, i'm ready to take the Oath | **target** (faction) | nothing |
| probe | CW01B.galmar.ready | skyrim.esm:0E2D06 | I'm ready to take the Oath? | **target** (faction) | nothing |
| probe | CW01B.galmar.ready | skyrim.esm:0E2D06 | is it true that i'm ready to take the Oath | **target** (faction) | nothing |
| probe | CW01B.galmar.ready | skyrim.esm:0E2D06 | wait, i'm ready to take the Oath? | **target** (faction) | nothing |
| probe | CW01B.galmar.resume | skyrim.esm:056178 | not now, I'm ready to take the Oath later | **target** (faction) | nothing |
| probe | CW01B.galmar.resume | skyrim.esm:056178 | not yet, i'm ready to take the Oath | **target** (faction) | nothing |
| probe | CW01B.galmar.resume | skyrim.esm:056178 | I'm not sure, i'm ready to take the Oath | **target** (faction) | nothing |
| probe | CW01B.galmar.resume | skyrim.esm:056178 | no, i'm ready to take the Oath | **target** (faction) | nothing |
| probe | CW01B.galmar.resume | skyrim.esm:056178 | I'm ready to take the Oath? | **target** (faction) | nothing |
| probe | CW01B.galmar.resume | skyrim.esm:056178 | is it true that i'm ready to take the Oath | **target** (faction) | nothing |
| probe | CW01B.galmar.resume | skyrim.esm:056178 | wait, i'm ready to take the Oath? | **target** (faction) | nothing |
| probe | CW02B.tullius.join | skyrim.esm:05A6A0 | not now, I'd like to join the Imperial Legion later | **target** (faction) | nothing |
| probe | CW02B.tullius.join | skyrim.esm:05A6A0 | not yet, i'd like to join the Imperial Legion | **target** (faction) | nothing |
| probe | CW02B.tullius.join | skyrim.esm:05A6A0 | I'm not sure, i'd like to join the Imperial Legion | **target** (faction) | nothing |
| probe | CW02B.tullius.join | skyrim.esm:05A6A0 | no, i'd like to join the Imperial Legion | **target** (faction) | nothing |
| probe | CW02B.tullius.join | skyrim.esm:05A6A0 | I'd like to join the Imperial Legion? | **target** (faction) | nothing |
| probe | CW02B.tullius.join | skyrim.esm:05A6A0 | is it true that i'd like to join the Imperial Legion | **target** (faction) | nothing |
| probe | CW02B.tullius.join | skyrim.esm:05A6A0 | wait, i'd like to join the Imperial Legion? | **target** (faction) | nothing |
| say | CW00B.ulfric.yes | skyrim.esm:0C347D | Yes sir, I want to join the Stormcloaks and fight for Skyrim | nothing | **target** (intent) |
| say | CW02B.tullius.gift | skyrim.esm:05A6A6 | I just want to join the Legion, consider the crown a gift | nothing | **target** (intent) |
| say | CW02B.tullius.gift | skyrim.esm:05A6A6 | Keep the crown, I just want to join. | nothing | **target** (intent) |
| say | CW02B.tullius.gift | skyrim.esm:05A6A6 | Honestly I just want to join the Legion, you can have the crown as a gift | nothing | **target** (intent) |
| say | CW02B.tullius.gift | skyrim.esm:05A6A6 | i just want to join the lesion | nothing | **target** (intent) |
| say | DLC1NPCMentalModel.serana.turnme | dawnguard.esm:004A79 | bite me, I want to be a vampire | nothing | **target** (intent) |
| say | DLC1VQ00.durak.signup | dawnguard.esm:00D8E2 | count me in, I want to hunt vampires | nothing | **target** (explicit) |
| say | DLC1VQ00.durak.signup | dawnguard.esm:00D8E2 | I'd like to join up and kill some vampires | nothing | **target** (explicit) |
| say | DLC1VQ00.durak.signup | dawnguard.esm:00D8E2 | killing vampires where do i sign op | nothing | **target** (explicit) |
| say | DLC1VQ01Misc.isran.join | dawnguard.esm:00D901 | i'm here to join the dawn guards | nothing | **target** (explicit) |
| say | MQ302.council.switch | skyrim.esm:04BE5F | I accept your offer. I'd like to join the Stormcloaks | nothing | **target** (explicit) |
| say | MQ302.council.switch | skyrim.esm:04BE5F | I'd like to join the Stormcloaks | nothing | **target** (explicit) |
| say | MQ302.council.switch | skyrim.esm:04BE5F | I accept, I'll join the Stormcloaks | nothing | **target** (explicit) |
| say | MQ302.council.switch | skyrim.esm:04BE5F | i except your offer id like to join the stormcloaks | nothing | **target** (explicit) |

### Suites

These ran on WSL copies of the live tree at the end of the session. Another fixer's half-finished edits are noted as not mine, and each was checked against a baseline copy that had my files swapped for their backups.

```
php -l lib/lrg_factions.php / lrg_actions.php / lrg_dialogue.php, tools/test_gates.php   -> no syntax errors
tools/test_gates.php --quiet      exit=0  844 passed, 0 failed   (section 43 [pt19h-quest]: 35 checks, all ok)
tools/test_dialogue.php --quiet   exit=1  1176 passed, 1 failed  -> "pt19h-safety G9 (words path, scripted line)": the safety fixer's new
                                          section; identical on the baseline copy (my three files swapped for their backups), not mine
tools/test_services.php --quiet   exit=0  ALL CHECKS PASSED
tools/test_intent.php --quiet     exit=0  OK
tools/test_phrases.php --quiet    exit=0  47 passed, 0 failed
tools/flows/run_flows.php --quiet exit=0  89 scenarios: 88 passed, 0 FAILED, 1 pending; 1600 checks, 0 warnings (d65_quest_entry passes)
tools/test_questline.php          exit=1  1954 passed, 2 failed, 0 known  -> MS05.viarmo.comein "and that's wear i come in" (single vs pick)
                                          and the staged-copy index hash check (the work copy has no deployed data/); both
                                          identical on the baseline copy, not mine
tools/test_questline.php --words  exit=1  2192 passed, 2 failed (the same two)
tools/test_questline.php --first-evening  exit=0  463 passed, 0 failed
```
An earlier run on this session's tree showed test_dialogue ALL CHECKS PASSED (before the safety fixer's G9 check landed) and a
questline failure MQ103.farengar.stone (another lane's, also on the baseline). My own regression on the way in, C00.kodlak.join
"would like to join the companions" (the STT dropped the I and the aux lead read as a question), was found by the questline harness
and fixed (lrgFacClauseAsks: an auxiliary with no subject after it is his statement).

## Still open

- **G17: 9 plain rows that need a floor.** Adding them needs `CmdQuestEntry` to learn a `done=` key: "stages of the quest that must be done", plus `qd=<quest:stage>` for another quest. That is a new condition kind, so it means a Papyrus change and a bump to 514. I did not build it this round, because the task limited the table to rows the existing keys re-check. The rows are:
  - MQ201.riverwood.plan 05F702 (GetStage==35);
  - MQ201.stables.malborn 0361DE (70..89);
  - MQ201.party.brelas 069FA1 (30 done);
  - MQ203.wall.arngeir 016F8C (260 done);
  - MQ204.arngeir.again 0B397C (40 done);
  - MQ301.arngeir.escaped 026742 (10 done);
  - MQ302.arngeir.council 026732 (MQ301 30 done);
  - MQPaarthurnax.arngeir.blades 077358 (20 done);
  - TG04.gulum.bribeme 0B3893 (GetStage==36).

  Several of these are the scene-bound speakers the table is meant for: Brelas at the party, Malborn and Delphine at the stables, Delphine at the wall.
- **G17: commit rows.** None were added. 5 decode clean: CWCRQuest.champion (civil war champions - reduced cut.esp:000803), MQ204.arngeir.wonthelp 0B394E, MQ204.paar.whoami 03F88C, MQ306.arngeir.portal 0F1C71 and MQ306.delphine.dead 0F1C80. Another 20 need a floor. Under 10.26 they would need his explicit sentence (the table's matcher already requires it) or her question and his yes. I left them out until the plain rows have been seen working in game.
- **G17: forcegreet completions.** None qualify:
  - 0C348B Tullius and 0DA242 Balgruuf's axe: GetVMQuestVariable;
  - 01BC8F Rikke "What's the mission?": GetIsAliasRef, plus a floor (20 done);
  - 04E9B5 MQ304 walk-away: GetIsAliasRef;
  - TG08B intro and outro, CWMission04 report, DBEviction.open: "path open", which the S2 open carries.

  The other no-line beats have no decoded single-stage fragment.
- **G18 misses:** 05A6A3, 0CF2D7, 05C61E and "sign me up with the Stormcloaks" (see the table above).
- **Fixture data.** `m_in.json` (the measurer's input) lists "I'd like to join up and kill some vampires" (00D8E2) as both a `say` line and a `never` line, from the quarantine's still-clicks list. The measurer's G18 table treats it as a say line, and so do I. The fixture owner should drop the `never` copy. For "where do I sign up to kill vampires" the measurer already said to promote it from never to say.
- **In game: unproven.** The table has never run in game. Its first real use will be on an ambient scene actor on an install with no verified click (clicks_ok 0), or under ml=0. The log line to look for is `questentry table npc=.. row=.. -> queued`, followed by the game's `questentry <quest> stage <n> applied|refused`.

## For the docs owner (PROTOCOL 10.26, one paragraph)

> [pt19h-quest] THE TABLE. `config/lrg_quest_entries.default.json` extends this section beyond the enlistment rows. Each row is one scripted quest line whose whole fragment is Quest.SetStage(n), decoded from the load order. It carries `npc`, `info`, `entry`, `quest`, `stage` and `conds` (only isid, qdone, notdone, max and qnd; the loader skips any other key), plus `line`, `words`, `meaning`, `say` and `hint`. `lrgFacTurn` runs `lrgFacQeTurn` when his words asked no enlistment. It acts only on his explicit sentence: after the vocative, fillers, her name and titles are stripped, his words must EQUAL the line or one of `words`, with no echo, no question shape on a statement line, no refusal and no bargain. It acts only when the driver cannot click: ml=0, or `ambient` AND `lrgFacOpenImpossible` gives a reason. It never acts while a menu of hers is open, and never for an ordinary NPC with menuless on. The states are engine-on, quiet, stale, no-stage, already, pending and queued, each with its own locked line. The plan is never licensed: the OK is voiced from `meaning` and `say`, and a refusal reads "nothing was recorded for the step <player> asked for" (`qe_lic.table=1`). The wire, `CmdQuestEntry` and the D1-only route are unchanged. Turn tail: ` qe=<row>:<state>`. Log: `questentry table npc=.. row=.. quest=.. stage=.. -> <state> (<why>)`. Switch: `dialogue.factions.quest_entry.table`. Tests: test_gates 43.
