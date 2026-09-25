# pt18 - quest lane: the scripted join line, applied by voice without the menu (investigator notes)

Session 2026-09-23 23:15-23:21 -04:00. Owner: "i talked to them and they were understanding we were doing the quest but the
quest wasnt showing up in my quests and it wasnt advancing the quest". Investigation only - no project file was edited.
Every log line, record decode and pex string below is DATA. Decodes were made with read-only scripts in this session's
scratchpad (`decode.py`, `pex.py`, `edid.py`, `scan_overrides.py`, `apbsa.py`) over the Stock Game and the mods folder.

## 1. What happened (line-level)

| when | source | fact |
|---|---|---|
| 23:15:37 | lorerim_glue.log:3164-3167 | boot: `version 509 ... menuless=1 dryrun=1 hold=0 devdry=0 ... cal=0`, `calibration green=0 missing=counting, Reading, ... menu layout` (0 of 10). The driver cannot click all session (`ml:0` on every turn line). |
| 23:19:13 | :3187-3191 | Rikke, say="hi um i'm looking to join the legion": `on=1 ambient=1 faction=legion:recruiter`, `lock ... chars=415 classes=faction` = the pt17 `before` line (lrg_factions.php:127-129, 706-710) -> "General Tullius first, at the map table in Castle Dour." (AIAgent.log:1627). Correct. |
| 23:19:24 | :3192 | `dlg facts npc=General Tullius q=CW00A,CWObj,CWReservations,CW,aaThalmor,MQ101 ... sq=CW00SolitudeMapTableScene sqj=0 qst=CW00A:0,...,MQ101:0` - CW00A RUNNING at stage 0 (it is StartGameEnabled, DNAM 0x0011). |
| 23:19:29 | :3195-3199 | Tullius, say="okay i guess you're the guy i talked to to join the legion": `list=none n=0 ... on=1 locked=2 ambient=1 faction=legion:recruiter ml:0`, `lock ... chars=482 classes=faction,quest`. The ml=0 branch of lrgFacLockedLines (lrg_factions.php:719-724): "enlistment is settled in your own dialogue with him, not in this talk (still learning ... 0 of 10) ... nothing has begun". Model: "You want to join the Legion? Swear your oath here, or move on." (AIAgent.log:1892, 1964). `llm ... action=none item="" spoke=86` (:3201). |
| 23:19:47 | :3202-3206, :3209 | Tullius, say="i swear to uphold the imperial vows": `faction=legion:recruiter:carried`, 552 chars. Model: "Then you are a Legionnaire." / "Report to Legate Rikke at the training yard for your first orders." (AIAgent.log:2219, 2239). `action=none item="" spoke=94`. No `emit`, no `faction ... pos=`, no ExtCmd, no funcret. |
| 23:20:06 | :3210 | Rikke facts again: `qst=...CW00A:0...` - the game recorded nothing. |

Why nothing could have happened in the code as shipped:
- `lrgFacArbitrate()` (lrg_factions.php:861) is only reached from `lrgDlgGateItem()` (lrg_dialogue.php:2560) when the MODEL
  emitted a Take_Up_Business line AND a pool exists; with `list=none` the gate goes to `lrgDlgMaybeOpen()` (:2535-2537), which
  `lrgFacRefusesOpen()` (lrg_factions.php:818-837) ends for a recruiter under ml=0: "no hand-off: menuless questing is off (or
  in dry run), so she was told to settle it in her own dialogue and say so". Tonight the model emitted NO action at all
  (`action=none` x3), so even that branch never ran. The three turns were words only, by design of pt16/pt17.
- The locked line said "nothing has begun" and the model said "you are a Legionnaire" anyway - the words lane
  (research/pt18-words.md) owns that half; this lane owns making the quest REALLY move.

## 2. What the real join lines do in THIS load order (decoded, not inferred)

Form ids are load-order-independent low 24 bits (Skyrim.esm). Winners from `scan_overrides.py` over all 3,416 active plugins
of profile Ultra (a record override must master Skyrim.esm at index 0; 359 s): INFO 0D5150 / 0D514F / 0D5138 / 0D5146 -> USSEP,
0D5146 again -> AlternatePerspective.esp (last, wins); INFO 0D5136 / 0D5153 / 0D5133 / 0D513D -> Skyrim.esm ONLY (nobody
overrides them); QUST CW00A 0D3C5F -> "LoreRim - Dialogue Patch.esp" (mods\LoreRim - xEdit64 Output; identical stages, objective
and fragments, priority 251, DNAM 0x0011); GLOB MQQuickstart 0004679E -> AlternatePerspective.esp; QUST CW 00019E53 -> USSEP,
DIS_Heavy_Legion.esp, LoreRim - Dialogue Patch.esp.

### 2a. Tullius: the FreeToGo line -> CW00A stage 10

- Greet DIAL 0D5116 CW00TulliusForcegreetTopic, INFO **0D5146** (winner AP), toplevel, resp "Hmm. There something I can do for
  you? Perhaps direct you to the nearest prison...", links 0D5110 (Hadvar), 0D510F (FreeToGo), 04A20B (MQ302). Conditions (AP):
  `GetGlobalValue(MQQuickstart 0004679E) < 7`, `GetVMQuestVariable(CW 00019E53, ::PlayerGotIntro_var) == 0`,
  `GetStageDone(CW00A, 20) == 0`, `GetStageDone(CW00A, 10) == 0`, `IsInDialogueWithPlayer == 1`, `GetIsID(Tullius 0001327E)`.
  (fn249 = IsInDialogueWithPlayer per tools/build_prompt_index.py:48-52; 629 = GetVMQuestVariable.)
- **0D5150 CW00TulliusGreetFreeToGo** (winner USSEP): prompt "I was set free. I could've gone anywhere. I came here to fight for
  the Empire." (index norm `i was set free i could've gone anywhere i came here to fight for the empire`), resp "Hmm. I suppose
  that's true. Fine." + "Why don't you have a chat with Legate Rikke?", flag InvisibleContinue, ONE condition
  `GetIsID(Tullius) == 1`, links DIAL 0D5111. No script.
- **0D5136 (DIAL 0D5111 CW00TulliusGreetTalkRikke)**, Skyrim.esm, no override: two response lines (lstrings 56887 / 19795 - the
  "Speak to Legate Rikke..." continuation), flag Goodbye, condition `GetIsID(Tullius)` only, VMAD begin-fragment
  `TIF__000D5136.Fragment_0`. The pex (Skyrim - Misc.bsa `scripts\tif__000d5136.pex`, 691 bytes, string table
  `GetOwningQuest`, `setStage`, int immediate **10**) = `GetOwningQuest().SetStage(10)` and nothing else.
- Also: 0D514B (Hadvar line) needs `GetStageDone(MQ102A 0002BF9C, 10)` - closed (no Helgen); 0C348B (forcegreet "I remember
  you") = `GetOwningQuest().SetStage(10)` too (int 10), needs MQ101 complete + PlayerGotIntro 2 - closed.

### 2b. What CW00A stage 10 really does here (the journal question)

QUST CW00A "Imperial Introductions" (FULL lstring 14180; objective 1 NNAM 14177 = "Join the Imperial Legion"; strings from
`Skyrim - Interface.bsa` skyrim_english.strings). Stages 0,1,2,5,7,10,20,21; objective 1 targets alias 2 Tullius while
`GetStageDone(CW00A,10)==0`, alias 0 Rikke once done. Stage fragments (QF_CW00_000D3C5F): 10 -> Fragment_4, 20 -> Fragment_6,
21 -> Fragment_8, 7 -> Fragment_7.

**The fragment script that RUNS on this install is The Choice is Yours' loose override**
(`F:\Modlists\LoreRim\mods\The Choice is Yours\scripts\qf_cw00_000d3c5f.pex`, source `scripts\source\qf_cw00_000d3c5f.psc`):
- Fragment_4 (stage 10): `;setObjectiveDisplayed(1)` COMMENTED OUT; `kmyquest.CW.PlayerGotIntro = 1`;
  `(CW00SolitudeMapTableScene as CWMapTableSceneScript).StartMyScene()`.
- Fragment_6 (stage 20): `if IsObjectiveDisplayed(1): SetObjectiveCompleted(1)`; `kmyquest.CW01A.setStage(1)`; StartMyScene().
- Fragment_8 (stage 21): `setObjectiveCompleted(1)`; `CW01A.setStage(1)`; `CW01A.setStage(100)`.
- Fragment_0 (stage 1): `;setObjectiveDisplayed(1)` commented out. The vanilla pex in Skyrim - Misc.bsa still carries the
  string `setObjectiveDisplayed`; TCIY's does not.
=> On this load order **CW00A stage 10 writes NOTHING to the journal, by the REAL menu path too.** The first journal entry of
the Legion road is CW01A "Joining the Legion" stage 1 ("Clear out Fort Hraggstad"), set by CW00A stage 20 (Rikke). The owner's
"it wasn't showing up in my quests" is therefore only half a bug: stage 10 is invisible here on purpose (TCIY), and the glue must
SAY that in game ("Tullius sends you to Rikke; nothing goes in your journal until she gives you the Fort Hraggstad test").

### 2c. Rikke: "About that test..." -> the Hraggstad test -> CW00A stage 20 -> CW01A stage 1 (journal)

- **0D514F CW00RikkeBlockingTopic** (winner USSEP, toplevel): resp "Are you ready to test yourself at Fort Hraggstad?";
  conditions `GetStage(CW00A) < 20`, `GetStageDone(CW00A, 10) == 1`, `IsInDialogueWithPlayer == 1`, `GetIsID(Rikke 000132A1)`.
  No script; links DIAL 0D510A.
- 0D5138 "What's at Fort Hraggstad?" (USSEP): four resp lines ("The ancients built many of the fortresses..." ... "We're going
  to install a garrison there, but first, you're going to clean out the bandits that have moved in."), `GetIsID(Rikke)` only,
  links 0D5109 / 0D5108 / 0D5107 / 0D5106.
- **0D5153 "Consider that fort already yours."** (Skyrim.esm): resp "Good. That's what I want to hear. Now go make it happen,
  soldier.", Goodbye, end-fragment `TIF__000D5153.Fragment_0` = `GetOwningQuest().SetStage(20)` (int 20). Conditions:
  `fn592(LCTN FortHraggstadLocation 00019181, LCRT CWFortMonster 000D5F06) >= 1` and `GetIsID(Rikke)`. Function 592 is not in the
  builder's table; its parameter types (a Location and a LocationRefType) make it **GetRefTypeAliveCount** [name inferred -
  Papyrus has `Location.GetRefTypeAliveCount(LocationRefType)` (SKSE Location.psc:7)]: the line shows while a bandit is alive.
- 0D5133 "I already cleared it out." = `SetStage(21)` (int 21); 0D513D "I'm not sure about this." = vanilla `SetStage(20)`
  (int 20 in Skyrim - Misc.bsa) but **TCIY's loose `scripts\tif__000d513d.pex` removes the SetStage** (no `setStage` string) -
  "not sure" no longer starts the test here. 0D5140 "I'm going alone?" is walkaway (crit=1 in the index).
=> Rikke's effect is a plain stage change too (CW00A 20; its fragment starts CW01A at 1 = the journal entry), but it is THREE
clicks deep and it is her giving him the mission - it needs an explicit yes from the player, never the bare ask.

### 2d. The gate nobody has checked: MQQuickstart on this save

AlternatePerspective.esp OVERRIDES GLOB MQQuickstart with FLTV **7.0** (Skyrim.esm: 0.0), and AP's version of the greet 0D5146
requires `MQQuickstart < 7`. AP's scripts (AlternatePerspective0.bsa: apmq101controller.pex, apmq101setstagetrigscript.pex,
mq101startingcellloadregisterscript.pex) write the global at runtime (int immediates 0-5, 9) - on Helgen / MQ101 paths. On this
save MQ101 is at stage 0 and never ran. **The live value is unknown offline.** If it is still 7, Tullius's greet is CLOSED, the
FreeToGo line is unreachable by menu AND by voice, and the honest answer is "on this start the Legion's road runs through Helgen
(AP's MQ101) - Tullius will not take you at the table". pt17 assumed 0 (research/pt17-switches.md section 3) from Skyrim.esm.
The fix below reads the global in the game and sends it on the facts line, so she can say which it is.

### 2e. Every condition the game must check before applying an effect (the truth table of the command)

| effect | quest / stage | the engine's conditions, as the game must check them | confirming words (index resp) |
|---|---|---|---|
| Tullius FreeToGo (0D5150 -> 0D5136) | CW00A 10 | speaker IS Tullius (GetIsID 0001327E = `npc.GetActorBase().GetFormID()` low24 == 0x01327E, or `GetLeveledActorBase`); CW00A running; `!GetStageDone(10)`; `!GetStageDone(20)`; `MQQuickstart.GetValue() < 7`; `CW.PlayerGotIntro == 0`; actor loaded and alive; a live CHIM exchange with him (the voice analogue of IsInDialogueWithPlayer - the command only ever goes out on his own player-speech turn) | "Hmm. I suppose that's true. Fine." / "Why don't you have a chat with Legate Rikke?" (+ the 0D5136 lines) -> after: "speak with Legate Rikke" |
| Rikke About-that-test accepted (0D514F ... 0D5153) | CW00A 20 (-> CW01A 1) | speaker IS Rikke (000132A1); `GetStageDone(CW00A,10)`; `GetStage(CW00A) < 20`; `FortHraggstadLocation.GetRefTypeAliveCount(CWFortMonster) >= 1` (else the real answer is 0D5133's stage 21 - NOT applied by voice: unverified branch); actor loaded and alive; the player said YES after she posed the test | "Are you ready to test yourself at Fort Hraggstad?" + the four 0D5138 lines; after the yes: "Good. That's what I want to hear. Now go make it happen, soldier." |
| every other row (Stormcloaks, Companions, College, Thieves, Bards, Dawnguard, Penitus, Vigilants) | unknown | not decoded this round (Galmar's TIF__000E1AE8 carries int 1, target quest not decoded) | words only, as today |

## 3. CHIM's own quest engine, and why the glue applies the stage itself

- `HerikaServer/lib/chim_quest_engine.php` runs only with `CHIM_AI_QUEST_PROGRESSION` (general setting, default false;
  `chimQuestEngineFeatureEnabled()` :111-170). The DLL polls `quest_action_poll` and calls `AIAgentQuestProgressionBridge`
  (`SetQuestStage(int questFormId, int stage)` = `Game.GetForm(id) as Quest; SetStage(stage)`, no checks, no return). Tonight's
  AIAgent.log has **0** `[QuestProgression]` lines and chim.log 0 engine lines: the engine is OFF on this install (Postgres is down,
  so the row itself could not be read). Its bundled CW00A definition would set stages **1 and 7** on "join the legion" /
  "general tullius" keywords (data/skyrim_quest_definitions.json, beats HEAR_ABOUT_LEGION / SPEAK_TO_LEADERS) - not 10.
- The glue already stands down when that engine is on: `lrgDlgQuestEngineOn()` (lrg_dialogue.php:400-410, "two engines would
  set the same stage twice"). The new net must be gated on it too.
- Decision: **call `Quest.SetStage()` directly from LRG_Main** on the quest from `Quest.GetQuest(editorId)` (SKSE; the glue
  already does exactly this in LRG_Followers.psc:47-49 with a `GetFormFromFile` fallback), not the bridge. Reasons: SetStage
  returns bool and is latent ("Set the quest to the requested stage ID - returns true if stage exists and was set", SKSE
  Quest.psc:108-124), GetStage/GetStageDone let the game verify the outcome and refuse idempotently; the stage fragment
  (Fragment_4) runs identically either way because TIF__000D5136 itself is nothing but `SetStage(10)`; the bridge is CHIM's DLL
  seam (hidden script, runtime form id, no result) and calling it would couple the glue to that plugin for no gain.
- Idempotence / never lower: refuse when `GetStageDone(stage)` (already set) or `GetStage() > stage` (past it), and Papyrus'
  GetStage is "the highest completed stage" (Quest.psc:59-65), so a lower SetStage is never issued.

## 4. The smallest complete fix

### 4a. Faction table: a per-entry `effect` (lib/lrg_factions.php, legion row :103-136)
```
'effects' => [
  'General Tullius' => ['entry' => 'CW00TulliusGreetFreeToGo', 'info' => '0D5150', 'quest' => 'CW00A', 'stage' => 10,
     'conds' => ['isid' => '0001327E', 'stage_not_done' => [10, 20], 'stage_max' => 9, 'glob_lt' => ['MQQuickstart', 7],
                 'qvar0' => 'CW.PlayerGotIntro'], 'confirm' => false,
     'words' => "Hmm. I suppose that's true. Fine. Why don't you have a chat with Legate Rikke?",
     'meaning' => 'he is sent to Legate Rikke; nothing enters his journal until she gives him the Fort Hraggstad test',
     'journal' => 0],
  'Legate Rikke' => ['entry' => 'CW00RikkeBlockingTopic', 'info' => '0D514F', 'quest' => 'CW00A', 'stage' => 20,
     'conds' => ['isid' => '000132A1', 'stage_done' => [10], 'stage_max' => 19, 'alive' => ['00019181', '000D5F06']],
     'confirm' => true,  // her question first, his yes second (the paid-offer confirm pattern: yes / deal / I'm ready)
     'words' => 'Are you ready to test yourself at Fort Hraggstad? ... Good. That\'s what I want to hear. Now go make it happen, soldier.',
     'meaning' => 'she has given him the test: clear Fort Hraggstad of its bandits (Joining the Legion is now in his journal)',
     'journal' => 1],
],
```
Rows without `effects` (all others) keep today's words-only behaviour - "unknown", honestly. `verified_from` gains section 2.

### 4b. Game: `ExtCmdLRG_QuestEntry` in LRG_Main.psc (dispatcher :1654 gets an `elseif`; `CmdQuestEntry` next to CmdEscort :1719)
Wire (server -> game): `<npc>|command|ExtCmdLRG_QuestEntry@ok=1;cid=<cid>;npc=<name>;quest=CW00A;stage=10;isid=0001327E;
notdone=10,20;max=9;glob=MQQuickstart:lt:7;qvar=CW.PlayerGotIntro:0;alive=00019181:000D5F06:ge:1;entry=CW00TulliusGreetFreeToGo`
(every cond key optional and additive; unknown keys ignored). In order:
1. `IsEnabled()` else "Error: the feature is switched off"; `ok != 1` -> "Error: not authorised by the server gate".
2. `IsDryRun()` -> `ReportError(..., "", "dry-run mode is ON in the LoreRim Glue MCM (Diagnostics page) - the quest was not touched")`
   (SayReason :1553 names the page; the technical text names it too, exactly the CmdEscort pattern :1750-1754). The MENULESS dry
   run does NOT block it: this path exists because the driver cannot click (it is not a click).
3. `Actor npc = AIAgentFunctions.getAgentByName(want)` -> None: "Error: <name> is not here"; `IsDead()/IsDisabled()/!Is3DLoaded()`
   -> "she is not here"; `isid`: `npc.GetActorBase().GetFormID()` (and `GetLeveledActorBase`) low 24 bits must equal it ->
   "Error: that is not really <name> (the game's <quest> line is for a different actor)".
4. `Quest q = Quest.GetQuest(quest)`; None -> `Game.GetFormFromFile(0x000D3C5F, "Skyrim.esm") as Quest` for CW00A; still None ->
   "Error: the quest <id> is not in this game". `!q.IsRunning()` -> "Error: <quest> is not running". `q.GetStageDone(stage)` ->
   "Error: <quest> is already at stage <n> - nothing to do" (idempotent). `q.GetStage() > max` -> "Error: <quest> is already past
   that (stage <cur>)" (never lower). Each `notdone` stage done -> the same wording.
5. `glob`: `GlobalVariable g = PO3_SKSEFunctions.GetFormFromEditorID("MQQuickstart") as GlobalVariable` (fallback GetFormFromFile
   0x0004679E) -> `g.GetValue() < 7` else "Error: the game does not open General Tullius's greeting on this start (MQQuickstart is
   7) - his enlistment line cannot be reached by menu or by voice". `qvar`: `(Quest.GetQuest("CW") as CWScript).PlayerGotIntro == 0`
   through a new stub `tools/stubs/CWScript.psc` (`Scriptname CWScript extends Quest Hidden` + `int Property PlayerGotIntro Auto
   Conditional`; property access is by name at run time, so a partial stub is safe) -> else "Error: he has already had the Legion's
   introduction". `alive`: `(Game.GetFormFromFile(0x00019181,"Skyrim.esm") as Location).GetRefTypeAliveCount(GetFormFromFile
   0x000D5F06 as LocationRefType) >= 1` -> else "Error: Fort Hraggstad already stands empty - that is a different answer of hers".
6. Apply: `int before = q.GetStage(); bool ok = q.SetStage(stage); int after = q.GetStage()`; `!ok || !q.GetStageDone(stage)` ->
   "Error: the game refused stage <n> of <quest> (stage <before> before, <after> after)". Success -> `LogC` + funcret
   `OK: <quest> now at stage <n> (<before> before): <meaning>` with additive keys `;qs=<after>;qj=<0|1>` (qj = EscortHasJournal(q)
   after the change - the journal truth, read back, not assumed). `Debug.Notification` corner note when `bNotifyErrors`-style
   `bQuestHint` is on: "LoreRim Glue: CW00A stage 10 - speak with Legate Rikke (no journal entry until her test)".
7. Facts line (LRG_Dialogue.psc SendFacts :3541): two additive keys after `qst=`: `;mqq=<MQQuickstart int>;pgi=<PlayerGotIntro>`
   (only when the NPC is in CW00A / CW; `-` otherwise), so the server can pre-check and SAY the closed-greet case before he asks.
   `LRG_Main.psc:36` CurrentVersion 509 -> 510 (the calibration lane plans the same bump - re-read line 36 first).
   Papyrus rules kept: no try/catch, no docstring > 500 chars, OnInit trivial, no None cast to a typed array.

### 4c. Server: the net, pre-LLM, decided from the player's own words (lib/lrg_factions.php + lib/lrg_dialogue.php)
- New `lrgFacQuestPlan(array $t): array` called from lrgDlgPrepareTurn right after `$turn['faction'] = lrgFacTurn(...)`
  (lrg_dialogue.php:1721) - BEFORE `call_llm()`, which is the contract research/pt18-words.md 4.4 needs. It returns [] unless ALL
  of: a player-speech turn (`$t['speech']`), `$t['faction']['join']` with `carried=0` (an ask in HIS words now; the model's item
  is never consulted here), role `recruiter`, the row's `effects[<name>]` exists, `lrgDlgQuestEngineOn()` is false (STAND DOWN
  with CHIM's engine), the driver cannot click (`!lrgFacMenuless($npc)` - ml=0 / uncalibrated - OR no list carries the entry:
  `!lrgFacListHasTopic($t, [effect.entry])`; with ml=1 AND the entry on her list the real click still wins and this returns []),
  the server-side pre-check on `$t['facts']['qst']` passes (stage not done / max; `mqq` < 7 and `pgi` == 0 when the facts line
  carries them, else "unknown, the game decides"), and no `facexec` for the same quest/stage is pending in lrgDlgState (a repeat
  inside 120 s does not re-queue). `confirm: true` rows (Rikke) additionally need `st['facask']['posed']` from her previous turn
  and a yes in his words now (reuse the paid-offer yes/deal recogniser in lrg_intent / lrg_core).
  On success it sets `$turn['faction']['executed'] = ['quest'=>'CW00A','stage'=>10,'how'=>'bridge','cid'=>$cid]` (the words
  lane's flag, exact shape), stores `facexec`, and QUEUES the command through the D2 route now: a responselog row
  `command|ExtCmdLRG_QuestEntry@<param>` (the lrgDlgQueue shape, lrg_dialogue.php:2942-2958, parametrised on the code) so the
  DLL's poll delivers it while the model is still generating; the D1 echo line is appended in the post-gate
  (`lrgFacQuestNet($t, $out)` next to `lrgDlgServiceNet` at :2484) with the same x - PROTOCOL 10.6's both-routes rule, and the game's
  5 s de-dupe (LRG_Main.HandleCommand) drops the second delivery. `lrgNoteSent()` (lrg_actions.php:887) records it so
  lrgFuncretVerdict knows its source.
- Locked line (lrgFacLockedLines, lrg_factions.php:679-740) on an executed turn: "the game is being told this moment to record
  <quest> stage <n>: <meaning>; you may say so in your own words once, promise nothing beyond it" (the words lane adds the
  confirming-sentence licence on the same flag). When the pre-check FAILS the reason is the locked line instead ("of this moment:
  the game does not open his enlistment line on this start (MQQuickstart 7) - tell him so plainly ..."), never silence.
- Log: `dlg faction npc=General Tullius asked=legion role=recruiter quest=CW00A stage=10 why=driver-cannot-click(ml=0) -> queued
  ExtCmdLRG_QuestEntry x=<x>` / `... refused pre-check: <cond>`; the turn tail gains `:executed=CW00A:10` (lrgFacTurnTail :937).
- Funcret: lrgRecordResult/lrgFuncretVerdict (lrg_actions.php:794-977). A FAILURE is voiced exactly as every glue failure (the
  row below makes CHIM's follow-up turn run; lrgVoicedWhy maps the new technical texts to plain words: "the game does not open his
  enlistment line on this start", "it is already recorded", "Fort Hraggstad already stands empty"). A SUCCESS of THIS code is
  voiced too (new branch before :933 `if ok && partial==='' return handled`: `if (strcasecmp($r['cmd'], LRG_ACT_QUESTENTRY)===0)
  -> voiced with fact = the OK text`), with a directive of its own (lrgVoicedDirective :1167): "What just happened: <fact>. Say the
  outcome in ONE short line in your own voice, as the game recorded it - he is to speak with Legate Rikke; no oath, no rank, no
  time or place." That is the confirming words, spoken only after the game confirmed. The next-turn note (`last_result.told`)
  covers a dropped voiced turn as for every other code.
- Catalog: `LRG_ACT_QUESTENTRY = 'ExtCmdLRG_QuestEntry'` in lrg_core.php:31 area; lrg_actions.php `LRG_ACTIONS_VERSION` 11 -> 12,
  `LRG_CATALOG_ROWS` + the code, a row copied from the inactive escort row (:196-206): `action_name 'GlueQuestEntry'`, description
  "LoreRim Glue internal: applies the quest stage of a scripted join line the player chose by voice. Sent by the server only - keep
  it switched off.", `is_activated 0`, available to nobody, `request_types_any ['lrg_never']`, follow-up ON with its own prompt
  "The game has just answered what the player asked for. Say the outcome in one short spoken line in your own voice - exactly what
  the game recorded, nothing more." (LRG_FOLLOWUP_PROMPT stays the failure wording for the other rows). `lrgChimWillVoice()` then
  finds the row, as for the escort.
- deploy_server.ps1: no rows are listed there - it lists ANCHORS (:32-88). Add one: `@{ f = 'lib\lrg_factions.php'; t = 'function
  lrgFacQuestPlan'; w = 'the click-free quest entry (pt18): without it a join by voice under ml=0 is words only again' }` and one
  for the verdict branch `t = "strcasecmp($r['cmd'], LRG_ACT_QUESTENTRY) === 0"` (a lost one = a silent success).
- Config (`lrg_config.default.json`, `dialogue.factions`, lrgMerge): `quest_entry.enabled true`, `quest_entry.pending_seconds 120`,
  `quest_entry.require_facts_fresh 300`; the `effects` maps live in the row (overridable like the rest).
- PROTOCOL.md: 10.22 gains the `effects` / `executed` bullets and the new wire; a new 10.26 "QUEST ENTRY BY VOICE - ExtCmdLRG_
  QuestEntry, its conds keys, its two additive facts keys mqq= / pgi=, the voiced success". 10.24's facts line list + mqq/pgi.

### 4d. Tests
- tools/test_dialogue.php section 13 (k): the plan on Tullius's real turn shape of 23:19:29 (snap fac, q, `facts.qst
  CW00A:0`, ml=0, cal=0, ambient) -> executed CW00A:10, a queued row in `$GLOBALS['LRG_DLG_TEST_QUEUE']` whose action starts
  `command|ExtCmdLRG_QuestEntry@ok=1;` and carries `quest=CW00A;stage=10;isid=0001327E`; the same with `qst CW00A:10` -> nothing
  queued, no flag, the locked line says already recorded; with `mqq=7` -> nothing queued, the locked line names MQQuickstart; with
  ml=1 and the FreeToGo entry on her list -> nothing queued (the click wins, lrgFacArbitrate still picks the entry); a carried ask
  -> nothing; a rechat -> nothing; the model's item alone -> nothing; Rikke without a posed test -> nothing, with `posed` + "yes
  I'm ready" -> stage 20 queued with `alive=00019181:000D5F06:ge:1`; `lrgDlgQuestEngineOn()` true (seam) -> nothing + the STAND
  DOWN line; a second ask 30 s later -> not re-queued (facexec pending).
- tools/test_gates.php (the verdict block :736-810): an `OK: CW00A now at stage 10 ...` funcret for ExtCmdLRG_QuestEntry IS
  voiced (mode voiced, cue contains "speak with Legate Rikke", never "oath"); `Error: the game does not open General Tullius's
  greeting on this start (MQQuickstart is 7) ...` is voiced with plain words; `Error: ... already at stage 10` voiced; the dry-run
  refusal names the page (existing wordings); the catalog row is inactive and has a follow-up (lrgChimWillVoice).
- tools/test_prompt_index.php 5c: `CW00TulliusGreetFreeToGo` winner USSEP nconds 1 (exists), `CW00RikkeBlockingTopic` winner USSEP
  nconds 4, `CW00RikkeGreetAcceptQuest` scripted 1 nconds 2 - a load-order change fails here first.
- tools/flows/scenarios/d65_quest_entry.php (after d63): the whole evening replayed - facts line with qst/mqq/pgi, Rikke's
  redirect-before line, Tullius's ask -> the queued command + flag + locked line, the game's OK funcret -> the voiced turn, then
  Rikke with qst CW00A:10 -> her posed test -> the yes -> stage 20 queued; the refusal variants (mqq=7; already done; devdry).
- tools/test_mcm_wiring.php unchanged (no MCM control); compile.ps1 needs the `CWScript` stub only.

### 4e. Owner steps (game closed): none for the server beyond deploy; game: the 510 scripts + esp as usual; on the next evening
say to Tullius "I want to join the Legion" - he either sends you to Rikke (the corner note names the stage) or says exactly why
not; then to Rikke "I want to join" -> her test -> "yes" -> "Joining the Legion" appears in the journal.

## 5. Risks and open questions
1. **MQQuickstart** (2d): if the live value is 7 the whole Tullius road is closed on this save by AP's design; the fix voices it
   but cannot open it. Needs one look at the value in game (the facts line `mqq=` will show it in the log at once).
2. The flag is set pre-LLM while the game applies the stage in parallel (the D2 poll is ~1 s, the model ~6 s): a game-side refusal
   after a pre-check pass (stale facts, actor unloaded) makes that turn's confirming words wrong once; the voiced refusal follows
   in the same exchange and the words lane's next-turn note corrects it. Pre-check + `mqq/pgi` on the wire make this rare.
3. PlayerGotIntro through a hand-written CWScript stub: safe by Papyrus' by-name property access, but the compile must not import a
   vanilla CWScript from anywhere else (none is installed; verified: no CWScript.psc under the mods folder).
4. Rikke's stage 20 by voice = the player accepting the mission with a yes; the walkaway / "not sure" branches are not modelled.
   The index marks 0D5140 crit=1 (walkaway), which the click path would hand back - by voice the yes is the commitment.
5. Nothing here touches CHIM's engine; if the owner ever switches CHIM_AI_QUEST_PROGRESSION on, lrgDlgQuestEngineOn() stands the
   net down (CHIM's own beats would set CW00A 1 / 7, not 10).
6. Stormcloaks and the other rows stay words-only (honest unknown) until their lines are decoded the same way.

## 6. BUILD (implementer, 2026-09-24) - what was built, and where it departs from section 4 after the refuters

> **CORRECTIONS to sections 2d / 4a, from the refuters (both verified their claims):** MQQuickstart is NOT unknown on this
> save - it is **7.0**: AlternatePerspective.esp overrides GLOB 0004679E to 7.0 and adds `GetGlobalValue(MQQuickstart) < 7` to
> its winning Tullius greet 0D5146 (USSEP's 0D5146 has no such condition); the ONLY writer of the global in the load order is
> AP's `QF_MQ101_0003372B_new` (sets 6.0 on its Helgen path, MQ101 stage 0 here - never ran); refuter 2 decoded the Global
> Variables table of Save15 and Save16 read-only: 7.0 in both. So 0D5146 is **CLOSED**, the FreeToGo line (0D5150 -> 0D5136
> SetStage 10) is unreachable, 0C348B / 0D5145 need `GetQuestCompleted(MQ101)`, and the Legion road on this save opens ONLY
> after AP's Helgen (MQ101 stage 900 = CompleteQuest): greet 0D5145 -> 0D5113 "I was at Helgen." -> 05206780 -> DIAL 05206784
> / INFO **alternateperspective.esp:206783** `CW00TulliusGreetTalkRikkateAP` ("I don't want to sit idly by after what I've
> witnessed. I want to join the Legion", GetIsID only, Goodbye, scripted 1 in the index, `tif_ap_04206783.pex` =
> `GetOwningQuest().SetStage(10)`). pt17-switches.md:102 ("0D5146 ... OPEN on this save") and the shipped
> `lrg_factions.php` verified_from were wrong; both are corrected (the row here, pt17-switches.md as a patch in the report -
> that file is not this lane's). Rikke's pt17 'before' line ("General Tullius first, at the map table") was therefore a dead
> end on this save and is no longer said while the road is closed or unknown.

Files touched (all under glue/, backups in glue/.backup/pt18-quest/): `server/lorerim_glue/lib/lrg_factions.php`,
`server/lorerim_glue/lib/lrg_actions.php`, `game/LoreRimGlue/Source/Scripts/LRG_Main.psc` (CurrentVersion 509 -> **510**,
the dispatch line, `CmdQuestEntry` + six `Qe*` helpers), `tools/test_dialogue.php` (13 (l), 55 checks; one pt17 assertion in
13 (f) amended, see 6.4), `tools/test_gates.php` (section 37, 27 checks), `tools/flows/scenarios/d65_quest_entry.php` (new,
29 checks), this note. NOT touched (ownership): `lrg_dialogue.php`, `LRG_Dialogue.psc`, `lrg_core.php`,
`lrg_config.default.json`, `deploy_server.ps1`, `test_prompt_index.php`, `PROTOCOL.md`, `pt17-switches.md` - exact patches in
the build report. No `tools/stubs/CWScript.psc` (refuter 2: a compile error there would block the whole 510).

### 6.1 The refuters' must_change items, and how each was honoured
1. **Treat MQQuickstart as 7 / fail closed on unknown.** `lrgFacRoad()` (lrg_factions.php) evaluates a per-recruiter `closed`
   rule on the legion row for General Tullius AND Legate Rikke, keyed on MQ101 completion (`complete_stage` 900). Sources,
   this NPC's facts line first: `mq101c=` (1 / 0, when the game sends it - patch below), else `qst=` MQ101:<stage> against 900,
   else `mqq=` >= 7 (= AP's untouched start); then another recruiter's cached facts line (quest stages are global,
   `quest_entry.cache_seconds` 1800). Known incomplete -> **closed**: the Helgen line replaces every other recruiter line
   ("on this start the Legion's road runs through Helgen first: the game opens no enlistment line of yours until Helgen is
   behind him, and it has recorded none - tell him so plainly in one line (Helgen first), promise nothing, and never swear him
   in, ..."; in-world, no global / stage / mod name in it; 500 chars for Tullius, 522 for Rikke, under the 600-char body
   cap that would otherwise drop the line whole). Unknown -> nothing is asserted open and
   NOTHING is ever sent (`lrgFacQuestPlan` state `unknown`); Rikke's 'before' line is hedged ("..., and on this start Helgen
   comes before all of it - the game has not told you whether that is behind him"). Tonight's turn shapes (MQ101:0 on
   Tullius's own facts line; Rikke through his cached line) are `closed` (test_dialogue 13 (l1)-(l5), flow d65 steps 1-3).
2. **The FreeToGo entry dropped as the modelled effect.** `effects['General Tullius']` = the AP line: `entry
   CW00TulliusGreetTalkRikkateAP`, `info alternateperspective.esp:206783`, CW00A stage 10, conds `isid 78462` (= 0x01327E,
   decimal on the wire), `qdone ['MQ101']` (the greet's GetQuestCompleted), `notdone [10, 20]`, `max 9`, `qnd ['CW00B' => 10]`
   (see 6.3). The recruiters line for Tullius is now that AP line; the FreeToGo norm stays in `entries` for the exact-line
   match on a save whose greet is open (13 (f) still picks it from a list that carries it). `verified_from` corrected.
3. **The truthful gate now, in her words** - item 1; the refusal the GAME sends for the same case (`CW00A waits on MQ101 being
   complete`) is voiced as "his road into the Legion begins at Helgen, and that is not behind him yet" (lrgVoicedWhy) and
   her own words on the funcret are "that road is not open to you yet - there is something you must see through first".
   No wording anywhere names MQQuickstart, a stage, a global or a mod to the player.
4. **The wrong records corrected**: the row (this build); pt17-switches.md:102 as a patch (report).
5. **Rikke words-only this round**: no `effects` row for her (`lrgFacCfg('rows.legion.effects.Legate Rikke') === null`,
   asserted); her stage-20 line (0D5153, fn592 = GetRefTypeAliveCount >= 1 per https://en.uesp.net/wiki/Skyrim_Mod:Function_Indices
   and the xEdit declaration order refuter 2 read; the alive == 0 -> stage 21 branch 0D5133) is recorded here for a later cut.
6. **ONE route, no pre-LLM D2 row** (refuter 2, the 4-entry / 5 s de-dupe ring): `lrgFacQuestNet()` appends the line in the
   post-gate (Phase 1's `lrgPostProcessActions`, after the model's own lines - the escort pattern), never `lrgDlgQueue`; once
   per request (`LRG_FAC_QE_SENT[cid]`). The game's ring is never asked to catch a second delivery. A game-side repeat is a
   plain OK anyway (`GetStageDone(stage)` -> "OK: <quest> already at stage <n> - nothing to do", quiet).
7. **`executed` only on a COMPLETE, fresh pre-check; success voiced OR licensed, never both.** `lrgFacQuestPlan()` runs in
   `lrgFacTurn` (pre-LLM, the player's own words, never the model's item, never carried / rechat) and requires: role
   recruiter, an effect row, road `open` (item 1), `lrgDlgQuestEngineOn()` false (STAND DOWN), the driver unable to click
   (`ml=0`, OR an ambient scene actor, OR the entry not on her list - with ml=1 and the entry listed `lrgFacArbitrate` owns
   the real click and the plan is []), a facts line younger than `quest_entry.require_facts_fresh` (300 s), the quest on
   `qst=` below the stage (a fresher `exec_qst` counts), no `facexec` pending inside `quest_entry.pending_seconds` (120 s),
   no `qnd` quest done. **licensed** = every `qnd` quest also ON the facts line and clear -> `executed = {quest, stage,
   how 'entry', cid}` (the words lane's shape, `how` documented as `entry`), the words lane's "the game has just recorded
   ..." line rides, and the OK funcret is `handled` quietly. **unlicensed** (CW00B not on the sweep - the normal case) -> no
   flag, the locked line says "the game is being asked this moment to record CW00A stage 10 (...); it has NOT confirmed it
   yet, so do not say it is done", and the game's OK is VOICED through CHIM's funcret turn (`lrgQuestEntryResult`, the one
   glue success that is: field 3 rewritten to `OK: <meaning>`, directive "he is to speak with Legate Rikke - no oath, no
   rank, no time or place - never a word about the game, quests, stages, commands or errors"). Either way the OK writes
   `exec_qst {quest, stage, at, cid}` into Phase 2's state (the words lane's 9.2 contract: written on the game's result,
   never on emission) BEFORE CHIM's follow-up turn runs, so the never-false judge allows "speak with Legate Rikke" on it.
8. **For map-table NPCs the click-free path is the ONLY path** (lrgDlgMaybeOpen never opens on an ambient actor): stated in
   the lrgFacQuestPlan docblock and pinned by 13 (l17) (ml=1 + ambient -> queued) and (l16) (ml=1, not ambient, the entry
   listed -> the click wins). `lrgFacRefusesOpen` also refuses an open on a closed road (a Say-Once greeting on a menu that
   has no join line) and beside a queued entry.
9. **No CWScript stub, no `pgi=`** (refuter 2). `CW.PlayerGotIntro == 0` is stood in for by `qnd=CW00B:10` (CW00B stage 10 is
   the Stormcloak introduction that sets PlayerGotIntro 2; CW00A stage 10 sets 1 and is itself in `notdone`). The game checks
   the pair on the live quest (`Quest.GetQuest("CW00B").GetStageDone(10)`), the server treats a CW00B not on the facts line
   as "the game decides" (no licence, voiced OK). Recorded as a proxy in the row comment; the exact read waits for a stub
   built in its own round with `CWScript` on compile.ps1's `$denied` list.
10. **Tests encode the real save** (refuter 1): 13 (l1) and d65 step 2 replay 23:19:29 with MQ101:0 -> no command, no
    executed, the Helgen line; the "stage 10 queued" cases run only with MQ101 complete on a fresh facts line (`mq101c=1`, or
    `qst=MQ101:900`). test_prompt_index 5c additions are a patch (the file is not this lane's): the index carries no `conds`
    for 0D5146, only `nconds 6` and the winner - what can be asserted is in the patch.
11. **Owner steps say the truth up front** (report): next evening Tullius and Rikke say "Helgen first" - deterministic, not a
    corner case; the quest entry only fires after Unbound is complete.

### 6.2 The wire and the game side (for PROTOCOL 10.26)
`<npc>|command|ExtCmdLRG_QuestEntry@ok=1;cid=<cid>;npc=<name>;quest=CW00A;stage=10;isid=78462;notdone=10,20;max=9;qdone=MQ101;qnd=CW00B:10;entry=CW00TulliusGreetTalkRikkateAP;hint=CW00A stage 10 - speak with Legate Rikke (no journal entry until her test);x=<10 hex>;z=1`
- fixed keys first (ok, cid, npc, quest, stage), every cond key optional and additive, unknown keys ignored (`lrgFacQuestParam`).
- LRG_Main.CmdQuestEntry (script 510), in order: no quest / stage -> `unknown quest entry request`; `IsDryRun()` -> the
  developer dry run, named with the page (asSay "" so SayReason names the switch); `getAgentByName` -> "<name> is not here";
  dead / disabled / not loaded -> "<name> is not here"; `isid` (base or leveled base, low 24 bits, `Math.LogicalAnd`) ->
  "that is not really <name> (the <quest> line is for a different actor)"; `Quest.GetQuest(quest)` (CW00A falls back to
  `GetFormFromFile(0x000D3C5F, "Skyrim.esm")`) -> "the quest <id> is not in this game"; `!IsRunning()` -> "<quest> is not
  running"; `qdone` (each `IsCompleted()`, an unknown quest counts as not complete) -> "<quest> waits on <miss> being
  complete" (her words: "that road is not open to you yet - there is something you must see through first");
  `GetStageDone(stage)` -> **OK** "already at stage <n> - nothing to do" (idempotent, quiet); `max` / `notdone` -> "<quest> is
  already past that (stage <n>)" ("that step is already behind you"); `qnd` pairs -> "he has already had the other side's
  introduction (<pair> is done)" ("that line of mine is closed to you now"); then `before = GetCurrentStageID()`, `ok =
  SetStage(stage)`, `after`; `!ok || !GetStageDone(stage)` -> "the game refused stage <n> of <quest> (...)"; success ->
  corner note (hint=, gated on bQuestHint:Quests, only AFTER the stage is set) and `OK: <quest> now at stage <n> (<before>
  before): <hint>` with `;qs=<after>;qj=<0|1>` (EscortHasJournal read back) appended to field 2. The MENULESS dry run does not
  apply (not a click). No try/catch, no docstring over 150 chars, no arrays.
- Server verdict (`lrgQuestEntryResult`, lrg_actions.php): OK -> exec_qst + facexec done; licensed -> `handled`; else voiced
  success (`LRG_VOICED.success`, field 3 `@entry@OK: <meaning>`), subject to `lrgVoiceSkip` (the 8 s gap). Error -> facexec
  cleared, the ordinary failure voicing (`lrgVoicedWhat` "nothing was recorded for <player>'s enlistment", `lrgVoicedWhy`
  mappings above; technical texts never reach her mouth).
- Catalog: `LRG_ACT_QUESTENTRY` (guarded define in lrg_factions.php and lrg_actions.php; its home is lrg_core.php - patch),
  `LRG_CATALOG_ROWS` + the inactive `GlueQuestEntry` row (is_activated 0, nobody, `lrg_never`, follow-up ON with
  `LRG_QUESTENTRY_FOLLOWUP_PROMPT` "... exactly what the game recorded or refused, nothing more"). `LRG_ACTIONS_VERSION` stays
  11 on purpose: `lrgEnsureActions()` reinstalls whenever a row of the list is missing from the catalog, so the row reaches
  the live install on the first turn without a bump that would overwrite rows the owner may have edited (test_gates 37 (a)).
- Log: `dlg faction npc=.. asked=legion role=recruiter quest=CW00A stage=10 entry=<state> -> <why>` per plan;
  `dlg faction net npc=.. ExtCmdLRG_QuestEntry appended (D1 only): ... licensed=<0|1> x=..`; the turn line gains
  `:road=closed|unknown`, `:qe=<state>[:licensed]`, `:executed=CW00A:10`; funcret side `questentry OK ...` / `voiced
  QuestEntry OK ... (no licence was given pre-LLM)`.

### 6.3 What the game does NOT send yet (patches for the orchestrator, in the report)
- LRG_Dialogue.psc SendFacts: `;mqq=<int MQQuickstart>;mq101=<current stage>;mq101c=<0|1 IsCompleted>` after `qst=`
  (GetFormFromFile 0x0004679E / MQ101 0x0003372B, "Skyrim.esm" - no PO3 editor-ID lookup, refuter 2). Until it lands the
  road is read from `qst=` (MQ101 while it runs; a COMPLETED quest leaves the PO3 sweep, so `mq101c=1` is what opens the
  road after Helgen) and from the recruiters' cache. lrg_dialogue.php `lrgDlgFactsFrom` must add `mqq`, `mq101`, `mq101c` to
  its int-key list, or the keys never reach `$turn['facts']` (tests set the facts directly; flow d65 uses `qst=MQ101:900`).

### 6.4 Tests (all green on the WSL copy under lrg_test/quest: test_dialogue 475/0, test_gates 614/0, flows 82/82, 1467 checks)
test_dialogue 13 (l): tonight replayed (closed from qst / mqq / mq101c; the carried oath turn; Rikke through the cache; Rikke
unknown hedged; Rikke after Helgen plain), the queued / licensed / already / pending / closed-intro / stale / unknown /
click-wins / ambient / rechat / redirect / item-only / engine-on / switch cases, the param shape, the net (one line, once, no
D2 row, facexec), the table. One pre-existing assertion amended: 13 (f) "[pt17] the Legion row anchors Tullius on that
line" now asserts the AP line as the recruiter line and the FreeToGo norm in `entries`. test_gates 37: the row, the net through
`lrgPostProcessActions` with NO Phase 1 turn, the sent record + qe_lic, the OK voiced (cue, gate, log) / licensed quiet /
idempotent OK quiet, the seven refusal wordings, the Helgen refusal through the verdict (facexec cleared). Flow d65: the
evening end to end through the real hook files, then after Helgen: one line, pending on a re-ask, the OK voiced, already
after it, the Helgen refusal and the dry run voiced. Flow 31 step 7 (the words lane's) still passes: its 23:19 facts line now
yields the Helgen line, which carries the same "it has recorded none" fact.

### 6.5 Risks added or changed by the build
- The unlicensed path is the norm on this install (CW00B is never on Tullius's sweep): the confirmation comes ONE LLM turn
  later (CHIM's funcret turn), not in the ask turn - by design (never both). If the voice gap (8 s) skips it, `exec_qst`
  and the next facts line carry the fact and the next turn's judge allows "speak with Rikke".
- Under SHARMAT Phase 1's gate is not registered, so the net never runs there (like the escort); the words still say Helgen.
- `mq101c` / `mqq` are dead keys until the two patches (6.3) land; the road is then read from `qst=` and the cache only,
  which cannot see a COMPLETED MQ101 - after Helgen the entry would stay `unknown` (nothing sent, the hedged line) until
  the game sends `mq101c=1`. The patch is one line on each side.
