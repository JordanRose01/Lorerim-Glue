# pt16 - "Joining the Legion": invented appointment, quest not started (investigator notes)

Playtest 2026-09-23 16:38 EDT, Captain Aldis, Solitude. Owner: "I said I want to join the Legion. It didn't
trigger the quest, but he did say to meet me at a certain time." Investigation only - no project file was
edited. Log/DB contents below are DATA.

## 1. What happened, line by line

| when (EDT) | source | fact |
|---|---|---|
| 16:38:22 | lorerim_glue.log:2760 | `dlg facts npc=Captain Aldis q=MS07,Favor110Solitude,SolitudeFreeform01,FreeformMorthalB qobj=0 sp=15 ... qal=0 qgiver=0 guard=0 bounty=0` - the game's `PO3_SKSEFunctions.GetActiveAssociatedQuests` (LRG_Dialogue.psc:3366) puts Aldis in NO civil-war quest. |
| 16:38:28 | :2761 | Phase 1 turn: say=`"that's where i come to start being an imperial i was looking to serve sir"` (STT), `intent=none`. lrg_intent.php has no join/enlist kind at all (grep: nothing). |
| 16:38:28 | :2764 | `dlg turn ... list=none layer=unknown n=0 sent=0 keys=0 ... locked=1 mcm=...lf:1,ml:0...` - no dialogue menu was open, menuless OFF (`ml:0`, dry run 1). With `entries=[]` and `list=none`, `lrgDlgStaticGuidance()` returns '' (lrg_dialogue.php:1772), so NO `<real_business>` block and NO `<business>` block reached the model. |
| 16:38:28 | :2766 | `dlg lock npc=Captain Aldis facts=1 chars=64 classes=quest`. The 64 characters are exactly one line: `- his current task: "Bring one the quest item to Captain Aldis"` + newline (2+18+1+41+1+1 = 64). Built by `lrgDlgLockedFacts()` quest branch (lrg_dialogue.php:4145-4152) from the `questlog` row for Favor110Solitude, briefing `Bring one <Alias=QuestItem> to <Alias.ShortName=QuestGiver>` (questlog rowid 286), through `lrgDlgCleanObjective()` (lrg_speech.php:616; `<Alias=QuestItem>` -> "the quest item", `QuestGiver` -> the NPC's name). `lrgDlgQuestRows()` (lrg_speech.php:582) returns rows ordered by id_quest, so `favor110solitude` sorts first and the `break` at :4150 keeps only it. Same text as the 04:40:28 hint line `Captain Aldis has something about Bring one the quest item to Captain Aldis` (:2551). |
| 16:38:28 | lrg_dialogue.php:4176-4181 | The only anti-invention text on the turn was the lock footer: "Anything not listed here you do NOT know - say you are not sure rather than name a number, a place, a price, a bounty or a quest step." Nothing in the prompt said who recruits for the Legion, that Aldis cannot, or that appointments / tests / "report at the bell" are forbidden. |
| 16:38:32-34 | AIAgent.log:2085, 2172 | Aldis: "So you wish to serve in the Legion." / "Good. Report to the training yard at Castle Dour when the bell rings. We'll see what the Empire can make of you." -> THE INVENTED APPOINTMENT. glue log :2769 `llm ... action=none`. |
| 16:38:37-38 | AIAgent.log:2335, 2350 | Hans [Solitude Guard] (rechat, `q=- sp=-`, no facts): "You've chosen a noble path, citizen." / "Castle Dour's training yard will test both your resolve and your blade arm." - the hallucination spread to the audience. |
| 16:38:43 | :2776 | Aldis rechat turn `type=rechat` - `rechat` is not in `LRG_PLAYER_SPEECH_TYPES`, so `lrgDlgPrepareTurn()` builds NO locked facts at all (:1597, no `lock` line follows). |
| 16:38:47-49 | AIAgent.log:2712, 2846 | Aldis: "The training yard will test more than just your blade arm, recruit..." / "Report at the bell, and bring that fire you carry into something worthy of the Legion's name." |

No quest started because nothing was clicked (no menu, ml=0, dry run) AND because Aldis has no join entry
anyway (section 2). The model filled the gap with lore-plausible fiction (Aldis's own vanilla line is "Train
the recruits. Turn boys into men.").

`responselog` is EMPTY (0 rows) on this install, so reply texts come from AIAgent.log only.

## 2. Why Captain Aldis cannot recruit (data)

Aldis's factions, from the CHIM addnpc line (AIAgent.log:1925): CWImperialFactionNPC (0001C9FC),
TownSolitudeFaction (0002817C), CrimeFactionHaafingar (00029DB0), CWImperialFaction (0002BF9A),
Favor110QuestGiverFaction (000CA43B); base NPC_ 00041FB8 (ref 00041FB9).

The generic Legion join line "How does one join the Imperial Legion?" (DIAL CW00JoinTopic, Skyrim.esm INFO
0D3C5A and 0FBA67, WINNER AlternatePerspective.esp) is conditioned on (scanner pass 2, read-only):
`GetQuestCompleted(MQ101)==1` (function 543, name inferred from its quest params) AND speaker
`GetInFaction(CWFieldCOFaction 000C4A99)` AND `GetInFaction(CWDialogueSoldierFaction 0006B18C)` AND
`GetInFaction(CWImperialFaction)` AND voice type not FemaleCommander / MaleUniqueGalmar (fn 426).
Aldis lacks CWFieldCOFaction and CWDialogueSoldierFaction -> the line never shows on him. It is a
camp-legate REDIRECT line anyway ("Think you've got the mettle, eh?" -> go to Solitude).

His real root list (lrg_dialogue payload, sid D5007-981): "Are you with the Legion?" (Favor110QuestGiveTopicSolitude),
"You were the one presiding over the execution.", "How goes the training?", "I would like to take guard duty."
(Andrealphus Jobs Overhaul, class commit). The last one is what he CAN truthfully offer a would-be soldier:
`ANDR_AJO_GuardHaafingarImp_StartTopic01` INFO andrealphus jobs overhaul - master.esp:000A3B is `GetIsID(00041FB8)`
= Aldis, GameHour 9-15 (or AJO_NoShifts), no Haafingar bounty, not in CWSonsFaction; "(takes 6 hours)".

## 3. The REAL entry path to the Legion in this load order

1. Prerequisite: MQ101 "Unbound" completed (Alternate Perspective marks it complete when Helgen is skipped).
2. General Tullius (base 0001327E), Castle Dour, Solitude: forcegreet `CW00TulliusForcegreetTopic` INFO
   Skyrim.esm:0C348B (winner AlternatePerspective.esp) "I remember you, you were at Helgen. Speak to Legate
   Rikke." - needs MQ101 complete, CW00A stage 10 not done, `GetVMQuestVariable(CW)==2`; OR the AP-added
   player line `CW00TulliusGreetTalkRikkateAP` (alternateperspective.esp:206783, scripted, `GetIsID` Tullius):
   "I don't want to sit idly by after what I've witnessed. I want to join the Legion".
3. Legate Rikke (base 000132A1): `CW00RikkeBlockingTopic` INFO Skyrim.esm:0D514E "You survived Helgen?..."
   (CW00A stage 10 done, 20 not) -> sub-branch -> "About that test..." INFO 0D514F (winner USSEP) "Are you ready
   to test yourself at Fort Hraggstad?" = CW01 "Joining the Legion".
4. Also joinable from the Stormcloak side after the Jagged Crown: `MQ103TulliusBookTopic` "I'd like to join the
   Imperial Legion." (Skyrim.esm:05A6A0, Tullius, CW02B stage 180).

Requiem / LoreRim: none of these records is won by a Requiem plugin; `Requiem - Minor Arcana - Civil War.esp`
(40 KB, NPC/equipment overhaul per its own description) carries no GLOB and wins none of them; no `GetLevel`
condition anywhere on the path -> no level gate. CWFO_TheFallen.esp only edits MQ301 (Season Unending).
QUST-level overrides of CW00A were not checked (the index builder keeps QUST EDID/FULL only).

## 4. Faction and quest entry-point truth table (LoreRim Ultra, index built 2026-09-22, 37,561 rows)

Legend: V = verified from record conditions (scanner) and/or index rows; P = partial; U = unknown.

| faction | who recruits / first step | where | known prerequisites / gates | verified from |
|---|---|---|---|---|
| Imperial Legion | General Tullius (first contact, CW00A stage 10) then Legate Rikke (the Fort Hraggstad test, CW01) | Castle Dour, Solitude | MQ101 complete; not already enlisted. No level gate. Camp legates (CWFieldCOFaction) and Tasius in Dragon Bridge (moretosaykarthwasten.esp:0008D1) only redirect. | V - INFOs 0C348B, 0D514E/F, 0D3C5A/0FBA67, AP 206783; Aldis factions AIAgent.log:1925 |
| Stormcloaks | Galmar Stone-Fist (00014128): "That's why I'm here. I want to join." (CW00BGalmarSignMeUp, INFO 0E1AE8, scripted); Ulfric greets / AP line "I could've gone anywhere..." -> "Speak with Galmar" | Palace of the Kings, Windhelm | Test: Serpentstone Island ice wraith. No level gate seen. | V - scan + index (TheChoiceIsYours.esp wins CW00BGalmarNotSure) |
| Companions | Kodlak Whitemane (0001A68E): "I would like to join the Companions." (C00KodlakJoinUpStartTopic, INFO 0A3E7A, winner Companions at Mirmulnir.esp). Aela/Skjor/Farkas/Vilkas redirect: "Can I join the Companions?" (CRNoWorkBranchTopic, winner LoreRim - Dialogue Patch.esp) | Jorrvaskr, Whiterun | STAT GATE on the redirect line: OneHanded/TwoHanded/Archery/Block >= NGCDT_GLOB_Aela_AVMin (30) OR Health/Stamina >= NGCDT_GLOB_Aela_HMSMin (150), else NGCDT's "Nah. I don't think so." (Narrative Gameplay Consistent Dialogue Tweaks.esp:000F75/76). C00 stage <= 10. | V - scan + GLOB dump |
| College of Winterhold | Faralda (MG01 alias 11): "May I enter the College?" (INFOs 0B810F/0B8136; MG01Faralda1WhyAreYouHereBranchTopic 0AF0DF winner CollegeEntry.esp) -> cast-a-spell test | College bridge, Winterhold | Need one of the test spells (CollegeEntry.esp adds "I don't know the X spell" -> buy it; Requiem magic prices). No level gate on the entry INFOs. | P - entry rows verified, test branches not decoded |
| Thieves Guild | Brynjolf (0001B07D): his own hello "Never done an honest day's work..." / "Running a little light in the pockets" (TG00BrynjolfIntroBranchTopic INFOs 04FCDA/0352E0, TG00 stage < 5, gold >= / < 500 variants) -> the Brand-Shei job | Riften market | No level gate. Less Tedious Thieves Guild.esp present (later quests). | V |
| Dark Brotherhood | No walk-up recruiter: Aventus Aretino, Windhelm (DB01 Innocence Lost; Innocence Lost - Quest Expansion.esp wins many rows) -> kill Grelod -> Astrid abducts the player (DB02) | Windhelm / Riften orphanage | No level gate seen. Destroy the Dark Brotherhood - Quest Expansion.esp present. | P - DB01/DB02 index rows; Astrid's DB02 join rows not decoded |
| Bards College | Viarmo: "I'm looking to apply to the college." (MS05ViarmoApplicationTopic, Skyrim.esm INFO 053504, winner TheGiftofSaturalia.esp) | Bards College, Solitude | No gate seen. | V (index row); conditions not decoded |
| Dawnguard | A hold guard's hello starts DLC1VQ00 -> Durak (xx01541D) "Killing vampires? Where do I sign up?" (INFO 00E997/00D8E2) -> Isran (xx00336A) "I'm here to join the Dawnguard." (DLC1VQ01IntroA1 INFO 00D901, DLC1VQ01MiscObjective stage 50) | Fort Dawnguard, SE of Riften | Sensible Dawnguard Prerequisite (DawnguardQuestPrerequisite.esp wins guard hello 0100D057/58): MS14 "Laid to Rest" COMPLETED and GetLevel >= DLC1VQMinLevel, which Requiem.esp and LoreRim - Global Modifiers.esp set to 30 (vanilla 10). | V - scan pass 3 + GLOB dump |
| Volkihar (vampires) | Not a walk-up faction: Harkon's "I will accept your gift and become a vampire." (DLC1VQ02 INFO 003BA1) inside Bloodline, after Awakening | Castle Volkihar | Same Dawnguard gates upstream. | V (index rows) |
| Blades | Not joinable by asking: Delphine (Sleeping Giant Inn, Riverwood) in the main quest; the Blades take the player in during MQ203 Alduin's Wall | Riverwood / Sky Haven Temple | Main quest progress. No recruit line exists in the index. | V (absence) |
| Penitus Oculatus | JOINABLE here via Penitus_Oculatus.esp: "I want to join the Penitus Oculatus." (zzzPO00JoinTopic, INFO penitus_oculatus.esp:10DD93, scripted; speaker = alias 1 of quest zzzPO00) | Penitus outpost, Dragon Bridge (speaker probably Commander Maro - UNVERIFIED) | DBDestroy stage >= 200 (the Dark Brotherhood destroyed), zzzPO00 stage 0. | P - conditions V, speaker/location U |
| Vigilants of Stendarr (VIGILANT.esm) | "Are you recruiting for the Vigil of Stendarr?" -> "Yes, I'll join you." (zzAoMMq0B1Tvigilant / zzAoMMq0B1Yes, quest zzzAoMMq00 "Vigilant of Stendarr", speaker = a quest alias; Altano per the mod - UNVERIFIED) | Stendarr's Beacon (UNVERIFIED) | Vigilant - Delayed Start.esp: zzzVigilantMinLevel = 25. | P - GLOB V, speaker/location U |
| Hold guards | No vanilla faction. Andrealphus Jobs Overhaul: "I would like to take guard duty." on each hold's captain (Haafingar Imperial = Captain Aldis, GetIsID 00041FB8) | the hold's guard captain | GameHour 9-15 (or AJO_NoShifts), no bounty in that hold, not in the enemy CW faction; a 6-hour shift. | V - scan pass 2 |
| East Empire Company | Not joinable (only EEC building / navmesh patches; no join rows) | - | - | V (absence) |
| Thalmor | Not joinable (no rows) | - | - | V (absence) |

Function ids the index builder has no name for, as they appear on these records: 543 (params are always quests,
compared == 1) = GetQuestCompleted [inferred]; 426 (params are voice types) = GetIsVoiceType [inferred];
71 = GetInFaction; 372, 35, 46, 555, 1 = list / disabled / dead / loaded-3D / distance tests (not load-bearing).

## 5. How the server could have known (and what the fix uses)

- `lrg_npcstate` snapshot key `fac` (<= 48 faction EditorIDs, PROTOCOL 1.1; read server-side as
  `lrgGetNpcState($npc)['fac']`, lrg_core.php:460-471; already in the turn as `$turn['snap']`) - the
  data-driven recruiter test (CWFieldCOFaction + CWDialogueSoldierFaction + CWImperialFaction = a legate who can
  at least redirect; Rikke / Tullius by display name).
- `ev=facts` `q=` / `qal=` (LRG_Dialogue.psc:3353-3382) - a recruiter standing in CW00A would carry it (not
  verified that PO3 reports CW00A for Rikke; name match covers it).
- The utterance: `lrgDlgState($npc)['utter']['text']` (set at lrg_dialogue.php:1472-1475).
- The live list entries carry `topic` EditorIDs (payload shows `"topic": "Favor110QuestGiveTopicSolitude"`), so an
  exact topic-glob pick (the follower-verb pattern, `lrgDlgFollowerVerbOf()` :3742, `lrgDlgFollowerArbitrate()`
  :3855) is available for the recruiter hand-off with no new wire.

## 6. Proposed fix (server only; no Papyrus change needed)

See the structured report. Summary: a `factions` truth table in `lrgDlgDefaults()` (overridable through
`dialogue.factions` in lrg_config.json via `lrgMerge`), a join recogniser in `lrgDlgPrepareTurn()`, `faction`-class
locked facts in `lrgDlgLockedFacts()` (add `faction` to `truth.classes`), the "never invent an appointment /
never claim a quest began" rule in `lrgDlgLockedBlock()` and `<real_business>` (and emit `<real_business>` on a
faction turn even with no list), a recruiter hit as a business marker in `lrgDlgBusinessMarker()`, no open-for-
awareness on a redirect NPC in `lrgDlgMaybeOpen()`, and `lrgDlgFactionArbitrate()` in `lrgDlgGateItem()` /
`lrgDlgAnswerWant()` right after the follower arbitrate. Tests in tools/test_dialogue.php (new section 12) and
recruiter rows in tools/fixtures/lrg_quest_npcs.json.

## 8. What was BUILT (implementer, 2026-09-23 18:xx) and why it differs from section 6

Server only; no wire key, no Papyrus, no MCM setting. Backups of every touched file: `glue/.backup/pt16-legion/`.

**New file `glue/server/lorerim_glue/lib/lrg_factions.php`** (everything of substance lives here):
- `lrgFacDefaults()` - the truth table of section 4 as data, 15 rows, each with `verified_from` (never sent).
  Cells rated P/U in section 4 are EMPTY or nameless (Penitus: "their own outpost", no Maro / Dragon Bridge;
  Vigilants: "their own recruiter"); a row with no `redirect` emits nothing. Overridable via `dialogue.factions`
  in lrg_config.json (lrgMerge: maps merge, lists replace); the offline seam is `$GLOBALS['LRG_FAC_TEST_OVERRIDE']`.
  Ulfric is a REDIRECT on purpose (his AP greet lines carry no join word); Dawnguard's `DLC1VQ01IntroA2` ("looking
  for vampire hunters") was dropped for the same reason - the shared-topic guard would never pick either.
- `lrgFacAsk()` - the recogniser: exact token runs (the follower verbs' machinery: `lrgDlgTokenAt`,
  `lrgDlgNegatedAt` with clause stops), three strengths (STRONG fires alone, WEAK needs a faction word or a
  faction NPC, WORD-bound needs a faction word), an object guard ("can i join YOU" / "join MY party" are follower
  talk) and a subject guard two tokens back ("why did YOU join", "you should join"). The faction word is the
  longest run across rows; a tie ("the college": Winterhold or the Bards) is broken by the NPC's own faction,
  else it is reported ambiguous and no line is emitted. Covers the three REAL utterances of the day:
  16:38:28 `start being an imperial i was looking to serve` (weak `i was looking to serve` + word `imperial`),
  04:39:26 `where i could sign up for the legion` (`sign up`), 04:39:57 `i was looking to join the legion`.
  The model's own item shape ("join the Legion") is read too, so the post-gate cannot fall back to similarity.
- `lrgFacRoleOf()` / `lrgFacRoles()` - recruiter = display name (Tullius, Rikke, Galmar, Kodlak, Faralda,
  Brynjolf, Viarmo, Isran, Durak) OR (every `recruiter_factions` id on the snapshot's `fac` AND a
  `recruiter_topics` glob really on her list - live layer, cached root or open session); redirect = a row quest in
  `q` OR a `member_factions` glob on `fac`; else none. Quest membership alone is never a recruiter (refuter 2).
  Aldis's real csv -> `legion: redirect`; every Solitude guard -> `legion: redirect` + `guards: redirect`;
  Aldis with the AJO line on his list -> `guards: recruiter` (the captain, by topic, no name needed).
- `lrgFacTurn()` - `$turn['faction']` (asked / role / phrase / word / carried / at). An asking speech or
  lrg_dlgtalk turn stores `facask` in the NPC's dialogue state; inside `window_seconds` (90) a `rechat` turn AND
  the player's next line without an ask ("yes sir") carry it (`carried=1`). CHIM's 5 s poll and funcrets bail
  out before anything is computed.
- `lrgFacLockedLines()` - the POSITIVE fact, worded to override CHIM's persona text ("trainer for the Imperial
  Legion"): redirect NPC = `member` sentence ("any recruit or soldier you deal with is already enlisted - you
  enlist nobody") + `redirect` (Tullius then Rikke in Castle Dour; never through you; no time, place, drill or
  test) + `gate` + "whether he can join right now you do not know unless the game says so"; a second line when
  her list carries an `offer_topics` glob (Aldis: "the one thing you yourself can offer a willing hand is a
  shift of guard duty, and only if he asks for that"). Recruiter under ml=1 = her real entry + the action name
  + "nothing has begun until the game says so"; under ml=0 = "enlistment is settled in your own dialogue with
  him, not in this talk - tell him so plainly in one line ... nothing has begun" (never silent, no action the
  game would refuse). Every line is prefixed "of this world, not of this moment" because the block's header says
  the game confirmed the rest. `lrgFacRule()` adds the one sentence "Never invent an appointment, a meeting time
  or place, a drill, a test, a rank or a next step ... never say ... has begun" to the block on faction turns
  ONLY (the backstop; the positive line is the fix).
- `lrgFacRefusesOpen()` / `lrgFacMarker()` - no open-for-awareness on a redirect (Aldis's Say-Once greeting
  is not burnt; his `q` would otherwise have been a marker) nor on a recruiter under ml=0 (the game would
  refuse it with "the feature is switched off"); a recruiter under ml=1 is a marker of her own.
- `lrgFacArbitrate()` / `lrgFacArbitrateWant()` - the hand-off: candidates are entries on a `recruiter_topics`
  / `redirect_topics` glob WHOSE OWN WORDS are about joining (`entry_words`; `CRNoWorkBranchTopic` carries both
  "Can I join the Companions?" and "I'm looking for work.") or whose norm equals a row `entries` line; exactly
  one runs (every existing rail still applies: commit -> park, crit, arrest, afford, class), two ask, none =
  NOTHING executed and she answers in words. The similarity matcher is never consulted on an enlistment:
  "i want to join the legion" tops on Aldis's "Are you with the Legion?". Also the guard's own real line
  `SolitudeFreeformGuardSolitudeArmy` ("How do I join the Imperial Legion?" -> "speak to Legate Rikke in Castle
  Dour") is picked on a Solitude guard's list.

**Hooks in `lib/lrg_dialogue.php`**, each marked `[0.5.7 / pt16-legion]` (grep it): the require; `faction` in
`truth.classes`; `lrgDlgPrepareTurn()` sets `$turn['faction']` before `lrgDlgLockedFacts()` and, on a rechat
inside the window, gives `$turn['locked']` the faction class alone; `lrgDlgLockedFacts()` adds the faction lines
FIRST (the 600-char cap trims from the end); `lrgDlgLockedBlock()` appends `lrgFacRule()`; `lrgDlgMaybeOpen()`
returns null when `lrgFacRefusesOpen()`; `lrgDlgBusinessMarker()` asks `lrgFacMarker()` first; `lrgDlgGateItem()`
runs `lrgFacArbitrate()` after the follower verbs and before the slots; `lrgDlgAnswerWant()` runs
`lrgFacArbitrateWant()` before the two matcher branches (`$m === null &&` / `elseif ($m === null)`); the turn line
carries ` faction=<id>:<role>[:carried]`. New log prefix: `dlg faction ...`.

**Deliberately NOT done** (refuter 2's must-change list): `lrgDlgStaticGuidance()` is NOT widened - on an
ml=0 / no-list turn `<real_business>` would tell the model to settle things "only with the action", which invites
a Take_Up_Business call the game refuses; the flow-16 contract (nothing injected on a cold turn) stays. No
`<real_business>` sentence either (same block). The Companions/College/Bards/Dawnguard hand-offs are data-only
and untested in game.

**Tests.** `tools/test_dialogue.php` section 13 (60 checks: the recogniser on the three real utterances and the
non-hits incl. "join me" staying a follower verb, the roles from the real fac csvs, the locked lines for Aldis /
a guard / a vendor / Rikke ml=1 and ml=0 / the captain's guard-duty line / the Penitus nameless cell, the carry
onto a rechat and onto "yes sir" and its expiry, no open on a redirect and on ml=0, the hand-off on Rikke /
Tullius (ambiguous) / Aldis (nothing) / the guard's real line / the shared Companions topic / the want=1 fast
path, the table's bracket-freedom and verified_from, the config override). `tools/test_prompt_index.php` section
5c (10 checks): every named topic of the table exists in the built index and carries a join-word prompt, the
Legion redirect's anchors read back in words ("Legate Rikke ... Castle Dour", "Hraggstad"), and the winners
(AP / USSEP / Companions at Mirmulnir.esp) are still the winners - a load-order change fails here first.
Results (WSL, copy under the scratchpad): test_dialogue 314/0; test_prompt_index 76/1 (the pre-existing census
failure only); test_gates 459/0 (with game/ copied); test_intent 316/0; flows 78/78 scenarios, 1349 checks, on
both the untouched and the edited tree. An end-to-end scratch run through the real hook order (fake DB,
Aldis's snapshot, ev=facts, his words, a rechat 15 s later, a model item "join the Legion" with no list, a line
140 s later) produced: locked classes faction+quest (455 chars), the rechat faction-only (391 chars), no wire
line, `dlg faction npc=Captain Aldis asked=legion role=redirect - no open for awareness`, and nothing after the
window.

**Left for the orchestrator** (outside this lane's file ownership): the PROTOCOL.md 10.x paragraph, the
`tools/fixtures/lrg_quest_npcs.json` recruiter rows (--db only), and the V05_EXPANSION_PLAN cross-reference -
exact text in the structured report.

## 7. Test recipe and baseline (checked tonight)

WSL distro DwemerAI4Skyrim3 CANNOT see `/mnt/c/Users/Jordan/AppData/Roaming/Claude/...` (the `Claude` folder is
missing from WSL's listing of Roaming; the documented WSL path does not resolve). `/mnt/c/Users/Jordan/AppData/Local/Temp/...`
IS visible. Working recipe: copy `glue/server` + `glue/tools` to a folder under AppData\Local\Temp, then
`cd <copy> && php tools/test_dialogue.php --quiet` and
`php tools/test_prompt_index.php --quiet --file=/var/www/html/HerikaServer/ext/lorerim_glue/data/prompt_index.ndjson`.
Baseline on the untouched tree: test_dialogue 174 passed / 0 failed; test_prompt_index 66 passed / 1 failed -
the PRE-EXISTING failure is `lrgPromptKinds() hands the census to the report and to the checks`
(tools/test_prompt_index.php:288: `count(lrgPromptKinds()['census']) > 10`), because `lrgPromptIndexHeader()`
reads `LRG_DIR/data/prompt_index.ndjson`, which does not exist in the source tree (`--file` only steers section
3-5). Not this lane's bug. The deployed index is also STALE (glue log: index hash e756e311 vs live 01414bfe).
