# pt19 - Helgen lane: the keep softlock, the way past it, and a QUIET MODE for scripted intros (investigator notes)

Session 2026-09-24 02:41-03:26 -04:00 (owner report verbatim in the task). Investigation only - no project file was edited.
Every log line, record decode and script below is DATA. Decodes were made with read-only scripts in
`C:\Users\Jordan\AppData\Local\Temp\lrg_test\helgen\` (`bsa_extract.py` - a pure-python LZ4-frame BSA reader,
`esp_walk.py` - QUST/INFO/CELL/REFR decoder, `esm_scan.py` - Skyrim.esm name lookup, `frag.py`) over copies of
`AlternatePerspective.esp`, `AlternatePerspective.bsa` (source\scripts), `AlternatePerspective0.bsa` (scripts), `AIAgent.esp`
and the Stock Game `Skyrim.esm` (read through `/mnt/f`). Form ids below are as stored in the plugin (index 05 = AP's own
records; the runtime index differs: AlternatePerspective.esp is plugins.txt line 1748 of profile Ultra).

Web sources: UESP Skyrim:Unbound (https://en.uesp.net/wiki/Skyrim:Unbound), Skyrim:Hadvar
(https://en.uesp.net/wiki/Skyrim:Hadvar - BaseID 0002BF9F, RefID 0002BFA2), Skyrim:Ralof
(https://en.uesp.net/wiki/Skyrim:Ralof - BaseID 0002BF9D, RefID 0002BF9E), Skyrim:Joining_the_Legion
(prerequisite "Unbound"), Skyrim:The_Way_of_the_Voice (MQ105 stages: 8 = objective 10, 10 = journal; 0/1/5 are empty).

---------------------------------------------------------------------------------------------------------------------
## 1. What happened in the keep, line by line (both attempts)

### 1a. CHIM's own MQ101 stage samples (chim.log, `Processing Dynamic Oghma for Quest ID: MQ101, Stage: n`, +02:00 = -04:00 + 6h)

| -04:00 | stage | meaning (AP's `QF_MQ101_0003372B_new`, decoded below) |
|---|---|---|
| 03:04:55 / 03:05:40 | 1, 3, 5 | the AP intro transition: "Give me your best room" -> bed -> stage 4 (Fragment_381: `MQQuickStart = 6`, `SetStage(5)`, `SetStage(10)`) |
| 03:06:05-03:13:21 | 10 ... 145 | the cart, the block, Alduin (normal) |
| 03:14:41 | 150 | journal entry; 03:16:21 stage 180 (exterior actors disabled) |
| **03:16:47** | **210 -> 200** | the Imperial keep-door trigger (210 = Fragment_21 `FactionPath = 1; SetStage(200)`), then 200 = Fragment_17 (`SetObjectiveCompleted 30, 50; MQ101DragonAttack.SetStage(200); SetStage(180); SetStage(250)`) |
| 03:16:57 | 252 | Fragment_330: only the "Left Attack/Block" help message (a KEEP TRIGGER sets it - it fires BEFORE 250 here and no 280 event exists; no AP source sets 252 / 272 / 280 except Fragment_67, which did not run - refuter correction, it is NOT "set from stage 280") |
| 03:17:42 | 250 | Fragment_46: `KeepIntroSceneA.Start()`, Imperials allied, PlayerFriend = Hadvar, Ralof disabled |
| 03:19:29 | 67 | **RELOAD** (an earlier save; the cart again: 75 ... 150 at 03:24:17, 180 at 03:25:28) |
| **03:25:55** | **220 -> 200** | the Stormcloak door this time (220 = Fragment_23 `FactionPath = 2`) |
| 03:26:05 | 272 | Fragment_296: the two barracks Imperials `StartCombat(player)` |
| 03:26:31 | 252 | as above; the log ends here (03:26 -04:00) |

**The 55 s gap (refuter finding, kept as the load argument for quiet mode):** the stage-200 event (09:16:47) and the
stage-250 event (09:17:42) come from the same five-line Fragment_17, and SetStage is latent only for quest start-up (SKSE
Quest.psc:108-123) - about 50 s of Papyrus VM backlog inside the keep. The scene of attempt 1 therefore started no earlier
than ~03:18:30 and had ~1 min before the reload; attempt 2's keep window is 38 s (03:25:55 to the log end) in which stage
250 never fired, so attempt 2 supports no conclusion at all.

**Stage 240 never appears in either attempt.** 240 is the ONLY stage that gives the player his hands back after Alduin:
- stage 160, Fragment_316: `Game.GetPlayer().SetRestrained(false)`, `Game.EnablePlayerControls(abMovement = true, abFighting = false, abCamSwitch = false, abLooking = true, abSneaking = true, abMenu = false, abActivate = false)` - movement yes, **activate no** (bound hands), objective 30 "Make your way to the Keep";
- stage 240, Fragment_74: `Game.EnablePlayerControls()` (everything), `Game.ShowFirstPersonGeometry(True)`, `RemoveItem(PrisonerCuffsPlayer, 99)`, IgnoreFriendlyHits / SetNotShowOnStealthMeter / BlockActivation on Hadvar and Ralof;
- 240 is set by nothing but the keep intro scenes: `SF_MQ101KeepIntroSceneANew_00020167.Fragment_0` -> `setstage(240)` (Hadvar) and `SF_MQ101KeepIntroSceneB_0004E1E2.Fragment_10` -> `SetStage(240)` (Ralof). A Skyrim scene only runs while every one of its actor aliases is loaded in the player's cell.

That is the exact symptom the owner described: "it prevents me from using my interact button, i cant open chests" = activate never came back = the keep scene never reached its bindings-cut phase = the companion was not in the keep.

### 1b. CHIM's presence scans - what they show and what they do NOT (AIAgent.log, -04:00; corrected after the refuters)

**Correction first (both refuters):** the DLL's "not present" flag is NOT a loaded-in-cell test. It says
`NOT Cleaning state for Hadvar, not present` at 03:25:17.665 six seconds after his subtitle "Still alive? Keep close to me"
(03:25:11.925) while he is audibly leading the player, `Clean: Restoring voice` (present) at 03:25:25.680 and "Come on!
Stay close!" at 03:25:26.096; Ralof is "not present (1/2)" at 03:16:44 four seconds after "Come on, this way!" (03:16:40),
in the same pass as four Imperial soldiers. Hadvar's 03:16:44.752 line falls in the door load transition (exterior cell
0000982B detached 03:16:44.710, keep 0005DE24 loaded 03:16:46) and he had already been deleted / re-added at 03:15:26 -
the separation happened during the run (Delete agent Hadvar 03:15:26, Ralof 03:15:50; run 2 Hadvar 03:24:25, Ralof
03:25:01), not three seconds before the door. What DOES hold: no Hadvar / Ralof subtitle and no re-registration inside the
keep 03:16:47-03:19:29, no stage-240 event in either attempt, and the owner's own report that the companion was not in the
building. Where the companion was cannot be told from these logs; the defensible statement is "the keep intro scene never
reached stage 240 and the companion never spoke inside" (confidence: medium, not high). The lines as first read:

Attempt 1 (Hadvar path): `03:16:44.752 [PRECLEANER] Actor not present yet retained (1/2): Hadvar` (three seconds BEFORE the door,
stage 210 at 03:16:47) and the same for Ralof; `03:16:52.774 Actor is gonna be deleted because not present for 2 passes: Hadvar`,
`Delete agent Hadvar`; `03:17:12.840 ... not present for 7 passes: Ralof`, `Delete agent Ralof`. Nothing about either of them
again until the reload (`03:19:25.850 Auto-adding Hadvar / Ralof` with the whole Helgen cast).

Attempt 2 (Ralof path): `03:25:43 Ralof says: You! Come on, into the keep!`, `03:25:50 Ralof says: Come on, this way!`,
`03:25:51 Hadvar says: Where are you going? The barracks is through here!` (the Stormcloak door was taken), then from
`03:25:54.278 [CLEANER] NOT Cleaning state for Ralof, not present. Will do later` on EVERY pass (03:26:01, :09, :17, :25, :33)
while the player is inside (stage 220/200 at 03:25:55). Ralof never entered the keep.

CHIM's scan radius is the DLL's "present" test, i.e. loaded near the player: the companion was already out of range at the door
and was never loaded in the keep cell. This is the vanilla failure UESP documents for Unbound ("he will be locked out of Helgen
once the gate closes ... and will not be present for the proceeding scene") - the companion's own travel package
(MQ101 alias packages, quest priority 80 per the AP override's DNAM) did not bring him through the keep door.

### 1c. What CHIM and the glue did to Hadvar / Ralof in that window: nothing that moves an actor

- CHIM registered them as agents ("Auto-adding Hadvar 03:15:26 / 03:19:25 / 03:24:55", "Auto-adding Ralof 03:16:22 / 03:19:25
  / 03:25:29", "IS NOW DRIVEN BY AI") and then refused every conversation with the whole cast, once a minute:
  `[Globals.h:222] ACTOR IN SCENE (not allowed) by conf: Hadvar` (03:07:05, 03:08:06, 03:09:41 ... 03:24:40). CHIM applies
  AI packages only through LLM actions (`ActorUtil.AddPackageOverride` at priority 100 / 99 / 55 / 50 in
  `AIAgentAIMind.psc:91..4730 - MoveToPlayer 877, stayAtPlace 911, CheckAndReleaseWalkToTargetNPCs 1909, SpawnAgent 2269, SandboxOld 2669, projectNPC 3845, BackgroundCmd 4110, Sandbox 4710-4730 at priority 100 (refuter correction of the range; the conclusion stands: AIAgent.log shows none of them on Hadvar / Ralof)`; AIAgent.esp has NO quest alias packages - four quests, priority 0 / 30, no PACK on an alias);
  the AIAgent.log has not one `AIProxy` / package / `SetDontMove` line for either of them 03:06-03:27. Registration itself
  only uploads spells / inventory / equipment / a voice sample (the `[SPELLS_UPDATE]` / `[INVENTORY_UPDATE]` flood).
- The glue's server log has NO `GAME` line, no command, no hold, no escort, no snapshot for Hadvar or Ralof between
  `03:06:03 dlg facts npc=Haming ... sq=MQ101 sqj=0 qst=MQ101:5` and the end (03:25:36); the only glue traffic is
  `03:14:45 dlg turn npc=Moava type=instruction` and CHIM combat barks from the Helgen soldiers. The AIAgent.log has no
  `ExtCmdLRG_` line in the window. The conversation hold refuses a scene actor by design (`ConvRefuseReason`: "a scene"
  when `akNpc.GetCurrentScene() != None || player.GetCurrentScene() != None`, LRG_Main.psc:3512), the escort refuses
  `MQ*` (ESCORT_DENY, LRG_Main.psc:55), the auto calibration refuses `InScene` (LRG_DlgProbe.psc:3031 "crit"), the OStim
  start refuses "a quest scene is running" (LRG_OStim.psc:2607), the follower repair refuses a menu / scene / combat.
- One CHIM curiosity, not a cause: `03:24:40 [Globals.h:233] SEEMS Moava is talking to Hadvar` - Moava is an NPC CHIM took for
  a listener during the Alduin attack; the glue saw one `instruction` turn for her at 03:14:45. Unrelated to the stall.

**Verdict.** The stall is the engine's (the companion never reached the keep interior; the keep scene cannot start without
him; stage 240 - and with it activation - never comes). Neither CHIM nor the glue moved him. Why he did not path through the
door cannot be told from these logs (Papyrus logging was off); the plausible suspects are the things that rewrote Helgen's
exterior and navmesh in this load order - Alternate Perspective's own inn / shrine / well placements (AP's `HelgenDisEnabMarker`,
`CollisionInnMarker`, `CollisionWall`), "Northern Roads - Alternate Perspective Patch.esp" (plugins.txt 3321), "Lux -
Alternate Perspective.esp" / "Lux Orbis - Alternate Perspective.esp" (3000-3001), "Helgen and Alternate Perspective NPC
replacer" - and the 4k-mod script load itself. What IS in the glue's hands: (a) making sure the glue provably contributes
nothing to that window (deliverable 2), and (b) giving the owner a clean way past (deliverable 1).

What the glue DID do silently in that window, and would again: `OnCrosshairRefChange` (LRG_Main.psc:2984) calls
`MaybeSnapshot` on every actor looked at - a CHIM agent in a scene still gets `LRG_Profile.BuildSnapshot` (witness scan,
faction list, PlaceFacts) every 10-20 s, plus `LRG_Dialogue.OnCrosshairRefChange` -> `SendFacts` (a PO3
`GetActiveAssociatedQuests` sweep, `GetAllQuestObjectives` per quest, `EscortHasJournal`) once per 60 s per NPC; `Watch` ->
`SnapTick` every 20 s; `TickGlueFollow` polls every 4 s while an NPC is watched; `MaybeInitiative` every 90 s. None of it
moves an actor, all of it is Papyrus load on top of a script-heavy intro, and none of it is logged at level Normal - which is
exactly why the log could not say "the glue did nothing". Quiet mode makes that statement true and logged.

---------------------------------------------------------------------------------------------------------------------
## 2. THE WAY PAST HELGEN - Alternate Perspective 4.1.0, decoded from the installed files

### 2a. What is installed (meta.ini / modlist.txt profile Ultra / plugins.txt)

| mod folder | version | note |
|---|---|---|
| Alternate Perspective | **4.1.0.0** (`Alternate Perspective-50307-4-1-0-1765368778.zip`) | AlternatePerspective.esp (1.2 MB) + 3 BSAs; the compiled scripts are in `AlternatePerspective0.bsa` (`scripts\`), the sources in `AlternatePerspective.bsa` (`source\scripts\`, 111 files, LZ4) |
| Alternate Perspective - Alternate Start - Messenger | 4.1.0.0 (`Messenger Spawn-50307-4-1-0`) | modlist line 988 `+` (ENABLED). Ships ONLY `APShrineEnableTrigger.pex/.psc` - see 2c |
| Alternate Perspective - Alternate Start - No Markers | 2.4 | `APStartCellTrigger` only |
| Alternate Perspective - Voiced Addon | 1.13 for 4.1.0 | `Alternate Perspective - Gate to Sovngarde Edition.esp` (plugins 1754) overrides the Messenger's GREETING lines (index rows: "Greetings, Wunduniik." / "Destiny calls you. Go to it."), not the skip lines |
| New Beginnings, Adventurer's Start, ARA NPC replacer, Ferries / Northern Roads / Lux AP patches | - | AP add-ons; none touches the skip lines (the index's winner for both is AlternatePerspective.esp) |

### 2b. The two skip lines (esp DIAL/INFO + `source\scripts` + the prompt index rows)

Both belong to quest **AP_DialogueHelgen** (051E301D, priority 30, scripts `APMessengerUtil` + `APDialogueHelgen`), branch
`MessengerAlduinSkip`, greeted by DIAL `MessengerAlduinSkipTopic` "Hello" (INFO 0538C6E3: "So we meet again, Wunduniik. / Here,
I offer you to send you forward in time. Grind hiil suldez. Face Fate." - links to the three answers).

| DIAL (prompt) | INFO | condition (the ONLY one) | fragment | what it runs |
|---|---|---|---|---|
| `MessengerAlduinSkipNone` "I will choose my own fate." | 0538C6E5 | `GetIsID(AP_TheMessenger 0540B189) == 1` | none (goodbye) | nothing |
| `MessengerAlduinSkipImp` **"Skip to Helgen Keep"** | 0538C6E9 (goodbye, scripted) | `GetIsID(AP_TheMessenger) == 1` | `TIF_AP_0638C6E9.Fragment_1` (begin) | `(GetOwningQuest() as APMessengerUtil).SkipKeepEntrance()` |
| `MessengerMQSkipImp` **'Skip to "The Way of the Voice"'** | 053DD8ED (goodbye, scripted) | `GetIsID(AP_TheMessenger) == 1` | `TIF_AP_063DD8ED.Fragment_2` (begin) | Split message -> `APSMQ105[choice-1].Start()` = quests 053D36E9 / 053D36EA |

So on THIS load order the conditions are **GetIsID** (function index 72; the prompt index rows `alternateperspective.esp:38C6E9`
and `:3DD8ED` carry `nconds 1, scripted 1, goodbye 1, resp "Of course."`, winner AlternatePerspective.esp) - the 4.10 change
the community mentions is in.

**"Skip to Helgen Keep"** = `APMessengerUtil.SkipKeepEntrance(bool skipImod = false)`: `split.show()` -> 0 = cancel; imod
`WarpTime` 2.5 s; **1 -> `MQ101.SetStage(6)`** (Imperials), **2 -> `MQ101.SetStage(7)`** (Stormcloaks). AP's MQ101 stages 6 / 7
(Fragment_57 / 59 + Fragment_374 / 377 = `APMQ101Controller.QuickStartKeep(true|false)`): trophy-room prisoners (or barracks
soldiers) enabled, Hadvar `TryToMoveTo(HadvarKeepMarker1)` + `Enable()` / Ralof to `RalofKeepMarker1` (cuffs removed), scenes
1-3 stopped, stages 180 / 190 / 195, the player `MoveTo(PlayerImperialKeepMarker1 | PlayerSonKeepMarker1)`, `destroyHelgen()`
(145 / 150 / 180 / 190 / 195 + the Helgen markers), then `Game.DisablePlayerControls(abCamSwitch = True)` (= EVERYTHING off -
the defaults are all true) and `Game.EnablePlayerControls(abFighting = false, abCamSwitch = false, abActivate = false)` -
**activate stays off here too**; stages 8 / 9 (Fragment_157 / 159) then `SetStage(200)` + `KeepIntroSceneA|B.Start()`. I.e. the
keep skip lands the player in the keep's first room WITH the companion teleported beside him and needs the same scene to reach
stage 240. It removes tonight's cause (the companion outside), not the dependency.

**'Skip to "The Way of the Voice"'** = `QF_APS_MQSkip_063D36E9.Fragment_0` (Imperial; the Ralof twin `QF_APS_MQSkipRalof_063D36EA`
differs only in `FactionPath = 2` and stage 220):
```
(MQ101 as MQ101QuestScript).FactionPath = 1      ; 2 on the Ralof side
APMQ101Controller.DestroyHelgen()               ; FortNeugradEnableMarker / TempEndGate / HelgenDisEnabMarker / CollisionWall / CartPathAmbientMarker off; stages 145,150,180,190,195
MQ101.SetStage(200) ; 210 (220) ; 250 ; 500 ; 800 ; 1000
MQ101.CompleteAllObjectives()
MQ101.CompleteQuest()
MQ105.SetStage(1)
Player.MoveTo(Alias_portLoc.GetReference())     ; alias portLoc = Skyrim.esm REFR 0007B0C0
Stop()
```
- Stage 800 (Fragment_120 / 121): `MQ102A.setstage(5)` (Before the Storm, Imperial side; MQ102B for Ralof) and every keep scene
  stopped. Stage 1000: Fragment_19 (Hadvar / Ralof `BlockActivation(false)`, `HelgenReserved.Clear()`, Ulfric and Tullius back to
  their editor locations and enabled, the keep's interior doors unblocked, `NorthGate.Lock(False)`, `EastGate.Lock(False)`,
  `PostMQ101KeepMarker` + `MQ101OutsideClutterMarker` enabled, `Stop()`) and Fragment_365 (AP's Helgen civilians disabled,
  `Stage1000()`: `DialogueWhiterunGuardGateStop.SetStage(5)`, `dunHunterDoor` unlocked, every quest in `StopAfterIntroList`
  stopped; `DialogueHelgenScr.ShutDown()`).
- `MQ105.SetStage(1)`: an EMPTY stage (UESP: MQ105's journal starts at 8 / 10) - "The Way of the Voice" is primed, nothing
  shows. MQ103 (Bleak Falls Barrow) and MQ104 (Dragon Rising) are not touched: Before the Storm at stage 5 leads to them the
  normal way (Alvor / Gerdur -> Balgruuf).
- Where the player lands: `0007B0C0` is an XMarkerHeading (base 00000034) at Tamriel (77690, -57272, 9231) = cell (18, -14) -
  by the coordinates just east of Ivarstead at the foot of the 7,000 Steps (Helgen is (4,-21), Riverwood (4,-11)). Not
  verified in-game; the position math is the evidence.
- **Does it open the Legion road? Yes.** `MQ101.CompleteQuest()` + stage 1000: the AP override of CW00JoinTopic (INFOs 000D3C5A /
  000FBA67, "How does one join the Imperial Legion?") needs `fn543(MQ101) == 1` (pt16-legion: GetQuestCompleted, inferred from
  its quest params) plus the speaker's factions; UESP "Joining the Legion": prerequisite Unbound. The glue's own road rule
  (`lrgFacRoad`, lrg_factions.php:1153-1185) reads `mq101c=1` from the facts line (LRG_Dialogue.psc:3719-3729 sends
  `qHelgen.IsCompleted()`) or `qst=MQ101:<stage> >= 900` - both true after the skip. Caveat (open question 1): `mqq=`
  (GLOB MQQuickstart 0004679E) - the skip never runs stage 4, which is where AP writes 6 into it; Tullius's vanilla forcegreet
  0D5146 needs `MQQuickstart < 7` (pt18-quest 2a). The AP-added "I was at Helgen" chain and Rikke's lines key on MQ101 only.
- **What the owner loses** with the Voice skip: the whole keep (Gunjar's / Hadvar's gear, the keep loot, the torture room, the
  bear cave, objectives 60-80), the escape scenes with the companion, the walk to Riverwood (MQ102 starts at stage 5 = talk to
  Alvor / Gerdur; the companion is not with him), Helgen's exterior loot after the attack. He keeps the faction path (Hadvar's
  or Ralof's kin in Riverwood greet him as a Helgen survivor), Before the Storm, and the Legion road.

### 2c. WHERE the Messenger is, WHEN he exists, and how the owner reaches him

- The NPC is **AP_TheMessenger** (NPC_ 0540B189, FULL "The Messenger", `AP_MessengerRace`, `AP_MessengerFaction`); his placed ref
  **MessengerREF (ACHR 0540B18A)** is a PERSISTENT Tamriel ref (cell record 00000D74 = Tamriel's persistent cell) at
  (16986, -84406, 8410) - Helgen's own cell (4, -21) = `HelgenExterior03` (00009849), inside the town.
- **Enable parent = 054E4D7F, flag "opposite of parent"** - 054E4D7F is the placed **AP_ShrineofAkatoshExterior** activator at
  (17131, -84463, 8465), 150 units from him (its candles 054E4D80 / 054E4D81 `ImpCandle01` and the lavender 054E4D84 share the
  parent, same state; a rock 053DD8E4 `RockL02Snow` is the opposite). So: shrine enabled = Messenger hidden; shrine disabled =
  Messenger standing there. The shrine is disabled by the start cell's trigger `AP_StartCellTrigger` (ACTI 054246F7) whose
  script `APShrineEnableTrigger.OnTriggerEnter` does `ShrineStartCell.DisableNoWait(); ShrineExterior.Disable()`. **That script
  exists in this install ONLY because the "Messenger Spawn" optional is installed and enabled** (`AlternatePerspective0.bsa`
  has no `apshrineenabletrigger.pex`; the optional ships it). Without the optional the shrine stays and only offers the keep skip
  (`APShrineSkipIntro.OnActivate` -> `MessengerUtil.SkipKeepEntrance(true)`). With it - the owner's case - the Messenger appears
  the moment the player walks out of the AP start cell.
- **He exists only before the intro begins.** AP_DialogueHelgen's quest condition is `GetStage(MQ101) < 10`: at stage 10 (the
  cart) the quest - and all his lines - end. AP also tries to hide him at stage 5 (Fragment_383:
  `kmyQuest.DialogueHelgenScr.GetAliasByName("Messenger")` -> `.GetReference().DisableNoWait()`) but AP_DialogueHelgen has NO
  alias named "Messenger" (the string occurs in the esp only as the keyword EDID and the NPC's name) - that call errors on None
  and does nothing; the quest condition is what really retires him. The well exit door (below) is what disappears for good at
  stage 145.
- **The route (records; the "which door hides the ladder" part needs eyes):** The Resting Pilgrim (CELL `APHelgenInn`
  05005900; its street door 0500591E `FarmhouseLDoor01` teleports to Skyrim.esm REFR 00015B6A at (15881, -83290) - the inn is
  in Helgen's north-west) -> the cellar door 050752F2 (`FarmhouseLDoor01`, unlocked, at inn coordinates (1312, -720, -240) -
  the sunken corner behind the bar) -> **The Resting Pilgrim Cellar** (`APHelgenInnCellar` 05075286) -> the ladder 053BF14E
  (`RiftenRWRoomLadder01`, at cellar coordinates (-733, 187, -69), the west part) -> its other end is the exterior well door
  053C43C1 (`AP_FarmWellDoor01`) at (16899, -84190, 8372) in the town, 230 units from the Messenger. Two doors in the cellar are
  locked, both `FarmhouseAnimDoor01`: **053C435E NOVICE (lock level 1)** at (640, 512) - the east side - and **053C435F
  APPRENTICE (lock level 25)** at (-768, 640) - the west side, 450 units from the ladder. One of them is the lockpick the
  community mentions ("behind the innkeeper, basement, a lockpicked door, a ladder"); by position the Apprentice door is the
  west-wing one nearest the ladder. Bring lockpicks either way (Apprentice at Lockpicking 15 is a few tries). The door
  051CE815 behind `BardChamberEnableMarker` (initially disabled) is New Beginnings' bard room, not the route.
- The well door 053C43C1 is enable-parented OPPOSITE to `APMQ101AlduinAttackEnableMarker` (05312D3C, AP's `APMarker`, enabled
  at stage 145 by Fragment_367) - the exit vanishes with Helgen. Irrelevant for a pre-intro save.

**Practical consequence for the owner's two keep saves: the Messenger cannot help from them** (MQ101 is at 200+, his quest is
long stopped, and the player's hands are bound anyway - dialogue needs activate). He needs a save from BEFORE the
intro trigger: before "Give me your best room" / sleeping in the inn's bed (that line is `TIF_AP_05075139.Fragment_0` ->
`APMQ101.PrepareIntro()` -> the bed script -> stage 1 ... 4 -> 5). AP itself made a save right after character creation
(`APMQ101.GameStart` -> `RequestSave()`), and the game autosaves on the inn's door - one of those is the one.

---------------------------------------------------------------------------------------------------------------------
## 3. Console fallbacks for the keep saves (community + UESP + AP's own scripts; the engine decides, nothing is invented)

The stage numbers are AP's decoded MQ101 (2b) and UESP's table. `prid` targets are UESP's RefIDs (Hadvar `0002BFA2`, Ralof
`0002BF9E`). `~` opens the console.

1. Bring the companion in and let the scene run (the least invasive):
   `prid 0002BFA2` (Hadvar) or `prid 0002BF9E` (Ralof) -> `moveto player` -> close the console, stand still ~10-20 s. If the
   keep scene starts ("let's get those bindings off"), stage 240 fires by itself and everything is vanilla from there.
2. If nothing starts within a minute: `enableplayercontrols` (UESP's own suggestion for Unbound) gives activation back at once;
   then `setstage mq101 240` (= Fragment_74: full controls, the cuffs removed, the two companions un-flagged) - the very call
   the scene would have made. **Not a complete unstick by itself (refuter):** stage 250's Fragment_44 `BlockActivation(true)`'d
   both interior keep doors, and only the companion's scene (after the moveto) or stage 1000 (Fragment_19, lines 1540-1541)
   unblocks them - 240 gives activation and takes the cuffs off, it does not open the route door. Then try 1. again; the
   scene's later stages (255 find equipment / loot Gunjar, 300 the locked door, 500 the bridge, 700-725 the cave) follow
   from play if the companion is present.
3. If the companion is still dead weight, mirror AP's own "skip to the end of the keep" quest (`QF_APS_MQSkipKeepEnd_05484A45`
   sets exactly these): `setstage mq101 210` (Imperial; `220` on the Ralof side), `250`, `550`, `700`, `710`, `720`, `725`,
   - **NOT USABLE AS WRITTEN (refuter):** on the console this leaves the player in the keep entry room behind the interior
   doors that stage 250 blocked and only stage 1000 unblocks; AP's own APS_MQSkipKeepEnd also teleports player and companion
   to its markers. Use 4. instead.
4. The nuclear option, mirroring AP's Voice skip (2b): `setstage mq101 200`, `210` (or `220`), `250`, `500`, `800`, `1000`,
   then **`setstage mq101 900`** (after 800, so MQ102A has started) **or `completequest MQ101`** - correction (refuter): stage
   900's first QSDT carries flags 0x01 = Complete Quest (journal "I have escaped both my execution and a dragon attack at
   Helgen...", UESP "Finishes quest"; its Fragment_245 itself sets 500 and 1000); the APS_MQSkip recipe SKIPS 900, which is
   the only reason it calls `CompleteQuest()`. Both the Legion line (`GetQuestCompleted(MQ101)`) and the glue's road rule
   (`mq101c=1`) need the quest complete one way or the other. Stage 1000's Fragment_19 log 0 is unconditional and calls
   `Stop()`, so quiet mode releases at 1000 even without the complete flag. After 1000 the keep's interior doors are
   unblocked and Helgen's gates unlocked (Fragment_19), Before the Storm is at stage 5 (from 800), fast travel is on (stage 250's
   Fragment_363 under MQQuickstart >= 6) - walk out the way he came or fast-travel to Riverwood.
Never `setstage` below the current stage; never use `resetquest mq101` (AP's controller keeps state outside the quest).
5. **Preventive, for any retry from a pre-keep save (refuter):** the companion enters the keep by AI pathing only (no script
   MoveTo on the normal path), and CHIM's scans show both companions out of range 50-80 s before each door entry. Wait at the
   keep door until Hadvar / Ralof stands beside it and goes in first, then activate the door - the engine's stall is the
   player entering alone.

---------------------------------------------------------------------------------------------------------------------
## 4. QUIET MODE - design (deliverable 2), the smallest complete change that obeys every rule

### 4a. The rule
While a CURATED quest is running, not complete, and the player is inside its business - it shows an unfinished journal
objective (`EscortHasJournal`, LRG_Main.psc:2385) OR the player is himself an actor of a scene that quest owns
(`Game.GetPlayer().GetCurrentScene().GetOwningQuest()`, which covers MQ101 stages 10-160 where no objective is displayed yet)
- and, when the row names places, the player's current cell or location EditorID matches one of them - the glue is QUIET:

| glue behaviour | where it starts today | quiet gate |
|---|---|---|
| snapshots (`MaybeSnapshot` -> `BuildSnapshot`, witness scan, PlaceFacts) | LRG_Main.psc:4269 (crosshair 2984, speech 3031, SnapTick 3248, hold 3462) | return false right after the `ModPending` test, before any native (a forced snapshot too - nothing of ours needs one in an intro) |
| the facts line (`SendFacts`: PO3 quest sweep + objectives) | LRG_Dialogue.psc:3663, from OnCrosshairRefChange 3856 | send the SHORT form only: `ev=facts;ref;npc;quiet=1;sq=<owner>;sqj=<0/1>;q=-;qobj=-;qal=-;qst=-` (no sweep), same 60 s throttle - the server needs the flag |
| watch / SnapTick / initiative (`Watch` 3170, `SnapTick` 3191, `MaybeInitiative` 3263) | LRG_Main | `Watch` returns before `RequestTick`; `SnapTick` drops the watch; `MaybeInitiative` returns (no `lrg_initiative` tick, no OStim move) |
| conversation hold (`BeginConvHold` 3418, `ConvRefuseReason` 3466) | LRG_Main | new refusal "a scripted intro" ahead of the natives, logged once by `ConvLogSkip` |
| natural follow (`NoteGlueFollow` 2787, `GfTrack` 2701, `TickGlueFollow` 2875) | LRG_Main | `NoteGlueFollow` returns; `TickGlueFollow` drops its slots (GlueFollowOff) and does not poll |
| escort / SFF hand-off (`CmdEscort` 1723, `EscortFollow` 2089, `NoteFollower` 3870, `ArmFollowerRepair` 3926, `FollowerSweep` 4051, `RepairFollower` 3994) | LRG_Main | `CmdEscort` refuses with the real reason (voiced: "<name> is in the middle of the Helgen business and cannot leave it"); `NoteFollower` / `ArmFollowerRepair` / `FollowerSweep` return |
| quest entry (`CmdQuestEntry` 1799) | LRG_Main | refuses ("the game is running a scripted quest right now") - never a SetStage while quiet |
| automatic calibration (`CalAutoWanted`, LRG_DlgProbe.psc:2978) | LRG_DlgProbe | `cAutoWhy = "quiet"` right after "disabled-this-session" |
| the dialogue driver's open on scene actors | already refused (LRG_Dialogue.psc:1338 "a quest scene is running") | unchanged |
| OStim start / initiative | already refused (LRG_OStim.psc:2607) / gated above | add "a scripted intro" to `StartBlockedReason` for the log's sake only |
| boot announce chatter | `BootAnnounce` 456 (corner note + traces), boot step BOOT_AGAIN's second "maintenance done" line 715 | keep the two `Debug.Trace` lines (proof of life, owner rule), skip the corner `Debug.Notification` and the BOOT_AGAIN repeat while quiet at boot; `LRG_Dialogue.SelfTest` unchanged (three server-log lines) |
| server: CHIM's movement / follow actions for its actors | `lrgFollowerPolicy` (lrg_core.php:1785, functions.php:81) hides only owned / followed cases | new `lrgQuietPolicy()` beside it: `quiet=1` fresh on this NPC's facts -> `lrgHideActions(LRG_MOVEMENT_ACTIONS + ['EndConversation'])`, the escort net skips (`lrgEscortNet`: "escort skipped: quiet"), no move watch, `lrgFacQuestPlan` never sends; one prompt_bottom line so the refusal is VOICED ("<npc> is inside a scripted scene of the game (MQ101); she cannot be led, followed, sent or dismissed until it is over") |

One log line on engage, one on release (LRG_Main, level Normal): `QUIET on: MQ101 stage 160 objective 30 at HelgenExterior03
- snapshots, holds, follow, escort, calibration, initiative and CHIM's movement actions are off for its actors` /
`QUIET off: MQ101 (completed | no objective left | left HelgenKeep01)`. The server logs `quiet: withheld <codes> from <npc>
(MQ101 intro)` once per NPC per quiet spell (lrgMemSet flag), and `quiet: released <npc>` when a facts line with `quiet=0`
follows one with `quiet=1`.

CHIM's own agents stay CHIM's: nothing here touches registration, its scene refusal, or its packages.

### 4b. Config-driven list
- Game side (the decision must be local - no server round trip inside an intro): settings.ini `[Quests]`
  `sQuietQuests = MQ101` with the optional place list per row `MQ101:HelgenLocation,HelgenKeep01,HelgenExterior*` (`quest[:place,place...];quest...`;
  places are cell or location EditorIDs, globs as `EscortMatch` already parses; read with `MCM.GetModSettingString`, at most
  every 30 s like the other knobs; bounded at 8 rows / 24 places like `EscortMatchAny`). Quests are resolved with
  `Quest.GetQuest(editorId)` and the FormID fallback the facts sender already uses for MQ101 (LRG_Dialogue.psc:3721-3723).
  The first row ships as MQ101 with no places (the objective / scene test is enough; Helgen's exterior cells are three
  and the keep is one - listing them only narrows).
- MCM: `{ "id": "bQuietIntro:Quests", "text": "Stay out of scripted intro scenes (Helgen)", "type": "toggle", "help": "While the
  game runs a scripted intro you are inside (Helgen's Unbound to begin with), the glue takes no snapshots, holds nobody, does
  not follow, escort, calibrate or take the initiative, and CHIM's own move / follow actions are withheld from that scene's
  actors - so nothing of the glue's can stall the script. One log line when it engages and one when it releases. Off = the
  glue behaves as before inside such scenes.", "valueOptions": { "sourceType": "ModSettingBool" } }` on the "Menuless questing"
  page under a new header "Scripted intros" (next to bQuestAware); settings.ini `[Quests] bQuietIntro = 1` (a MISSING key reads
  as 0) and the `sQuietQuests` line with a comment; config.json stays UTF-8 no BOM, LF; `tools/test_mcm_wiring.php` gets
  `'bQuietIntro:Quests' => 'quiet'` in `$SERVER_KNOBS` (a .psc writes `";quiet="`, a .php reads `'quiet'`).
- Server config (`lrg_config.default.json`): `"quiet": { "enabled": true, "fresh_seconds": 300, "hide": ["EndConversation"], "line": "<npc> is inside a scripted scene of the game (<quest>) - she cannot be led, followed, sent away or dismissed until it is over" }`
  (the movement list is `LRG_MOVEMENT_ACTIONS`; `hide` adds to it).

### 4c. Cost
(As designed; corrected by the refuters and BUILT differently - see section 7.) "Zero natives when no curated quest runs" was
FALSE on this load order: AP runs MQ101 at stage 0 from the first minute (`qst=MQ101:0` at Solitude 02:45:43, Helgen
03:02-03:04), so the designed predicate would have paid the PO3 objective walk every 5 s for the whole pre-Helgen game. What
was built: per 5 s, `GetCurrentStageID` first (one native below the stage floor), then `IsRunning` + `IsCompleted`; no PO3
walk and no `GetCurrentScene` at all (MQ101 shows no objective and runs no scene between stages 200 and 250 - exactly the
stall window, where the designed predicate would have RELEASED). Everything it switches OFF is far more expensive than that. Server: one array read
per turn.

### 4d. Files (the smallest complete change; every rule kept - never silent, never false, no Papyrus try/catch, no docstring
over 500 chars, OnInit untouched, new arrays only in Maintenance, CurrentVersion 510 -> 511 - line 36 re-read first)
- `glue\game\LoreRimGlue\Source\Scripts\LRG_Main.psc` - `CurrentVersion = 511`; `quietOn/quietAt/quietWhy/quietRows[]` (arrays
  made in Maintenance); `QuietOn()`, `QuietRefresh()`, `QuietLog()`; gates in MaybeSnapshot, Watch, SnapTick, MaybeInitiative,
  ConvRefuseReason, NoteGlueFollow, TickGlueFollow, NoteFollower, ArmFollowerRepair, FollowerSweep, CmdEscort, CmdQuestEntry,
  BootAnnounce / BootTick BOOT_AGAIN; the OnUpdate fan-out calls `QuietRefresh()` first (cheap).
- `LRG_Dialogue.psc` - `SendFacts` short form with `quiet=1`; `OnCrosshairRefChange` unchanged (it is the throttle).
- `LRG_DlgProbe.psc` - `CalAutoWanted`: `cAutoWhy = "quiet"`.
- `LRG_OStim.psc` - `StartBlockedReason`: "a scripted intro" (log wording only; the scene test already refuses).
- `LRG_Profile.psc` - `BuildSnapshot`: `;quiet=` key (for the rare forced snapshot that still goes out - none while quiet, but the
  key documents the wire).
- `MCM\Config\LoreRimGlue\config.json`, `settings.ini` - the toggle, the `sQuietQuests` line.
- `glue\server\lorerim_glue\lib\lrg_dialogue.php` - `lrgDlgFactsFrom`: `quiet` (int) + `quiet_at`; `lrgDlgQuietOn($npc)`.
- `lib\lrg_core.php` - `lrgQuietPolicy()` (+ `lrgQuietCfg`), the prompt line helper; `functions.php:81` - call it after
  `lrgFollowerPolicy()`; `lib\lrg_actions.php` - `lrgEscortNet` / `lrgMoveWatchCheck` / the quest-entry plan skip under quiet;
  `context_pre.php` - the voiced line on prompt_bottom.
- `config\lrg_config.default.json` - the `quiet` block.
- `tools\test_dialogue.php` (facts key: `quiet=1` -> 1 with `quiet_at`; `-` / absent -> 0 / unset), `tools\test_gates.php`
  (quiet policy: hides Follow / FollowPlayer / MoveTo / EndConversation, keeps Talk; stale `quiet_at` -> nothing; `quiet=0` ->
  nothing; the escort net skipped; the prompt line present), `tools\test_mcm_wiring.php` (SERVER_KNOBS row).
- `glue\PROTOCOL.md` - new 10.27 "QUIET MODE - `quiet=` on the facts line, the curated list, what is off and the two log lines".

---------------------------------------------------------------------------------------------------------------------
## 5. OWNER STEPS for the next Helgen attempt

1. **Papyrus logging ON for that one session** (MO2 is the only editor): MO2 -> the wrench icon (Tools) -> **INI Editor** ->
   the `Skyrim.ini` tab -> under `[Papyrus]` change `bEnableLogging=0` to **`1`**, `bEnableTrace=0` to **`1`**,
   `bLoadDebugInformation=0` to **`1`** (leave `bEnableProfiling=0`) -> Save. Profile Ultra keeps its own INIs
   (`profiles\Ultra\settings.ini`: `LocalSettings=true`; ModOrganizer.ini `profile_local_inis=true`), so only Ultra changes.
   The log is `Documents\My Games\Skyrim Special Edition\Logs\Script\Papyrus.0.log` (it worked here before: the 2026-09-23
   log carries 169 `[LRG]` lines) and the glue's own `Logs\Script\User\LoreRimGlue.0.log`; count on 5-10 MB an hour. In the glue
   MCM set "How much to log" to **Everything** for the session. Set all three back to 0 afterwards (the same editor).
   Note (refuter): `Documents\My Games\Skyrim Special Edition\Skyrim.ini` already holds `bEnableLogging=1` (edited
   2026-09-22 18:40, `.bak-lrg` backup) but it is IRRELEVANT under profile-local INIs - only `profiles\Ultra\Skyrim.ini`
   (all 0, mtime 09-23 05:30) counts, which is exactly why no Papyrus log exists for tonight.
2. **Load the keep save and read the log** (or hand it over): look for `QF_MQ101_0003372B_new`, `SF_MQ101KeepIntroScene`,
   `APMQ101Controller`, "Cannot call ... on a None object" (the stage-5 Messenger line is harmless), and any error naming
   Hadvar's / Ralof's packages; the last `[LRG]` lines say what the glue did (with quiet mode: `QUIET on ...` and nothing else).
3. **Unstick THAT save** with section 3: `prid 0002BFA2` (Hadvar) / `prid 0002BF9E` (Ralof) -> `moveto player` -> wait for the
   scene; if not: `enableplayercontrols`, `setstage mq101 240` (activation and the cuffs, NOT the route door - section 3);
   if still stuck: the full skip ONLY (210 or 220, 250, 500, 800, 900 - or 1000 then `completequest MQ101`; the keep-end
   mirror of section 3 item 3 is not usable from the console), then walk out through the keep or back out the entry door.
4. **Or the clean way, from a save made before the intro** (before "Give me your best room" / the inn's bed): leave the inn,
   or go down through the door behind the bar into the cellar, pick the locked door (Novice / Apprentice - carry lockpicks),
   climb the ladder into the well yard - the Messenger stands by the Shrine of Akatosh spot. "Skip to The Way of the Voice",
   choose the Imperial side (Hadvar's kin, the Legion road) - MQ101 is completed by script, Before the Storm starts, he lands
   near Ivarstead; then Solitude for the Legion (the recruiters' "Helgen first" is over: `mq101c=1`). "Skip to Helgen Keep"
   is the same keep scene with the companion teleported in - possible, but it is the scene that failed tonight.
5. Rikke / Tullius afterwards: if Tullius will not greet at the map table, that is `MQQuickstart` (open question 1), not the
   glue - the facts line shows `mqq=` and the log will say which.

---------------------------------------------------------------------------------------------------------------------
## 6. Open questions / risks
1. `MQQuickstart` after a Messenger skip (never set by the skip; AP's start may leave 7 -> Tullius's vanilla forcegreet closed;
   the AP "I was at Helgen" chain and Rikke's lines only need MQ101 complete).
2. Which cellar door (Novice 053C435E east, Apprentice 053C435F west) is in front of the ladder - positions only.
3. Why the companion did not path into the keep - needs the Papyrus log; suspects are the Helgen exterior / navmesh overrides
   (AP's inn / shrine / well, Northern Roads AP patch, Lux AP patches, the NPC replacer), not CHIM or the glue.
4. The landing marker 0007B0C0 = "east of Ivarstead" by coordinates only.
5. Quiet mode must never take the OStim stop key, the kill switch or the never-silent voice line with it; the hide is an
   OFFER change (the model answers in words, with the reason) - exactly the follower policy's shape.
6. Quiet is decided on the GAME (facts line) and only mirrored on the server; a stale `quiet=1` (no facts for 300 s) releases
   by itself - same freshness rule as `sq=`.

---------------------------------------------------------------------------------------------------------------------
## 7. IMPLEMENTATION (implementer, 2026-09-24, script 510 -> 511) - what was built, and where it differs from section 4

Built exactly as the refuters asked, smaller than section 4 designed. Backups: `glue\.backup\pt19-helgen\`.

**Game (LRG_Main.psc, +217 lines)** - `CurrentVersion 511`; `QUIET_QUESTS = "MQ101>=5"` (a plain property, ESCORT_DENY's shape:
`<EditorID>[>=<stage floor>]`, comma-separated, at most 8; NOT an MCM string - no `sQuietQuests` ini key, no
`GetModSettingString`); `QUIET_LOOK_SECS = 5.0`; members `quietOn / quietAt / quietWhy / quietQuest / quietIds[] / quietFloors[]
/ quietRows` (cleared and the arrays made in Maintenance; `QuietLook` re-makes them once for a save from before 511, the
`folSeenQ` precedent). `QuietOn()` = `bQuietIntro:Quests` (MCM, ships ON) AND a row's quest `GetCurrentStageID() >= floor`
FIRST, then `IsRunning() && !IsCompleted()` - NO `EscortHasJournal`, NO `GetCurrentScene` (refuter 1: MQ101 shows no
objective and runs no scene between 200 and 250, the stall window; refuter 2: AP runs MQ101 at stage 0 from the first
minute, so the floor is read first and a pre-Helgen game pays one native per 5 s). Gates: `MaybeSnapshot` returns before
any native (forced too); `Watch` / `SnapTick` (drops the watch, `OnWatchEnded` re-dresses) / `MaybeInitiative`;
`ConvRefuseReason` -> "a scripted intro" (first, before the natives); `NoteGlueFollow` (ours comes OFF if on, never on);
`TickGlueFollow` -> new `QuietFollowOff()` (every tracked slot stands down, no poll, no next tick); `NoteFollower`,
`ArmFollowerRepair`, `FollowerSweep` return; `EscortRefuseReason` -> "a scripted intro", `EscortSayRefusal` -> "<name> is
in the middle of the Helgen business and cannot leave it" (`QuietWords()`: MQ101 -> "the Helgen business", else "a scripted
scene of the game"); `CmdQuestEntry` refuses after the dry-run check: "not now - the Helgen business is under way and I will
not take anything up until it is over", err= "quiet mode: MQ101 stage <n> is running - the glue touches no quest while it
does". Log lines (level Normal): `QUIET on: MQ101 stage <n> - no snapshots, holds, natural follow, follower repair, escort,
quest entry, calibration or initiative until it ends (bQuietIntro:Quests)` and `QUIET off: MQ101 stage <n> - <the quest is
complete | the quest stopped | switched off in the MCM | ...> - the glue is back`. NOT done on purpose: `BootAnnounce`
(it is the tail of Maintenance, which must not call into another mod; the corner note is harmless) and the BOOT_AGAIN
"maintenance done" line (it is the boot record).
**LRG_Profile.psc**: `;quiet=` after `fv=` (0 in practice - no snapshot goes out while quiet; documents the wire).
**LRG_Dialogue.psc**: ONLY the automatic-calibration gate - `if m.QuietOn()` before `CalAutoWanted`, logs `CALIB auto
skipped=quiet`. `SendFacts` (the `quiet=` facts key the server reads) is an ORCHESTRATOR PATCH (file ownership), and it keeps
`mqq= / mq101= / mq101c=` (refuter 2). `LRG_DlgProbe.psc` / `LRG_OStim.psc` untouched (the LRG_Dialogue gate covers the
calibration; the OStim wording was log-only).
**MCM**: `config.json` Menuless questing page, header "Scripted intros", toggle `bQuietIntro:Quests` "Stay out of scripted
intro scenes (Helgen)"; `settings.ini [Quests] bQuietIntro = 1`. UTF-8 no BOM, LF; `test_mcm_wiring.php` 22/0 (read by a
script - path (a) - no SERVER_KNOBS row: the setting's VALUE never travels, the derived `quiet=` does).
**Server**: `lrg_core.php` - `lrgFollowerPolicy()` now calls `lrgQuietPolicy($npc)` right after `lrgEnabled()` (before
`followers.enabled`, so `functions.php` is untouched); `lrgQuietCfg` (`quiet.enabled / fresh_seconds 300 / hide [] /
names {MQ101: the Helgen intro} / line`), `lrgQuietFor` (the facts line `quiet=1` + `quiet_at` fresh, OR the snapshot
`quiet=1` within `snapshot_max_age_seconds`), `lrgQuietPolicy` (hides `LRG_MOVEMENT_ACTIONS` + `hide` from the offer,
prepares `$GLOBALS['LRG_QUIET'] = {npc, quest, src, line}` for context_pre.php, logs `quiet: withheld <codes> from <npc>
(MQ101 stage <n>, from the facts)` once per spell via `lrg_memory.quiet_held`, and `quiet: released <npc>` after). The
redundant `hide: ['EndConversation']` of section 4 is gone (refuter 2: LRG_MOVEMENT_ACTIONS already has it).
`lrg_dialogue.php` - `lrgDlgFactsFrom`: `quiet=1 -> facts.quiet 1 + quiet_at`, `0 / - -> 0`, absent -> unchanged;
`lrgDlgQuietOn($npc)`; `lrgDlgCalibCandidate` -> `'quiet'` right after the no-npc test. `lrg_config.default.json` - the
`quiet` block + `_quiet_readme`. ORCHESTRATOR PATCHES (not my files): `context_pre.php` prompt_bottom line from
`LRG_QUIET`, `lrgEscortNet` skip, `lrgFacQuestPlan` skip, `SendFacts quiet=`.
**Tests**: `test_gates.php` section 36 (12 checks: the facts key x3, the offer, the line, the memory flag, stale, quiet=0, the
snapshot carrier fresh / stale, the candidate x2) - 641 passed 0 failed; `tools/flows/scenarios/32_quiet.php` (8 checks
through the dialogue adapter: snapshot, quiet=0, ev=facts alone, stale) - PASS; `test_dialogue.php` 477/0;
`test_mcm_wiring.php` 22/0 ALL CHECKS PASSED. `compile.ps1` NOT run here (the Build stage does).
**Why the server half is an offer change, not a mute**: NEVER SILENT - she still answers in words; the game-side refusals
(escort, quest entry) are voiced through ReportError with the real reason; the server line on prompt_bottom (orchestrator
patch) gives the model the reason for the withheld actions.

### 7a. REVIEW (2026-09-24) - three small inline edits, all tests green afterwards
1. `LRG_Main.psc QuietOn()`: `quietWhy` is now refreshed on EVERY look while quiet (it was set only on engage), so the escort /
   quest-entry `err=` texts, the log lines and the `QUIET off:` line name the CURRENT stage (240, not the stage-5 text it
   engaged at) - the technical text was becoming false as the intro advanced. No behaviour change beyond the words.
2. `lib/lrg_actions.php lrgVoicedWhy()`: the server builds the voiced fact from `err=` alone (`say` is stored for the
   next-turn note but never read by `lrgVoicedWhy`), so the two new game refusals lost their reason on the funcret turn:
   "<name> will not do that now (a scripted intro)" fell to the vague "she will not do that right now" and "quiet mode:
   MQ101 stage <n> ... the glue touches no quest" hit the technical filter ("glue") and became "it just would not work
   right now". A mapping before that filter now says "she is in the middle of the Helgen business and cannot leave it until
   it is over" (MQ101 named in the reason, or `lrgQuietFor` says MQ101) / "... something important that she cannot walk
   away from until it is over" (never the quest id, the stage or the glue). NEVER SILENT kept on both routes.
3. `tools/test_gates.php`: the quiet section is numbered `36b.` (it duplicated the never-empty section's `36.`) and gains
   two checks for the mapping above (678 passed, 0 failed; test_dialogue 559/0; all 84 flow scenarios PASS).
Not changed, for the orchestrator: the purchase lane's comments also claim "PROTOCOL 10.27" (LRG_Main.CmdBuy,
LRG_Profile.MarketFacts, `_market_readme`) - one of the two sections must become 10.28 when PROTOCOL is written. The
SendFacts `quiet=` patch (section 7, orchestrator patch 1) is still what makes the server half live: SendFacts runs from
LRG_Dialogue's own crosshair path (independent of the snapshot, 60 s per NPC), so it WILL carry quiet=1 once patched, and
until then the facts sweep (PO3 GetActiveAssociatedQuests) still runs on Hadvar / Ralof while quiet.
