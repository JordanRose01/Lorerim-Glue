# LoreRim environment scan (profile "Ultra") - Phase 0 research

Scan date: 2026-09-21. All work was READ-ONLY. Every claim below is tagged **[V]** (verified by opening the file / parsing the binary) or **[I]** (inference). Machine local time is UTC-4 (PE timestamp 09:08:09Z of OStim.dll vs. file mtime 05:08).

Path shorthands: `MODS` = `F:\Modlists\LoreRim\mods`, `PROFILE` = `F:\Modlists\LoreRim\profiles\Ultra`, `DOCS_SKSE` = `C:\Users\Jordan\Documents\My Games\Skyrim Special Edition\SKSE`.
"rank N" = position among ENABLED non-separator mods counted from the top of `modlist.txt` (rank 1 = highest priority = wins conflicts).

---

## Executive summary (10 lines)

1. LoreRim 5.0.4.8 / MO2 2.5.2 / SkyrimSE.exe **1.6.1170.0** / SKSE **2.2.6**; 3,889 enabled mods; load order = **177 full + 3,317 light plugins** (3,494). An ESL-flagged glue plugin fits (779 light slots left); a full plugin also fits (77 left).
2. `AIAgent.esp`, `OStim.esp`, `OStimCommunityResource.esp`, `OBody.esp` are the LAST four plugins (positions 3411-3414 of 3414 in `plugins.txt`); runtime index of `AIAgent.esp` was `[175]` = 0xAF in the last game run. The six CHIM/OStim mods are the top 7 entries of `modlist.txt` (highest priority).
3. `Interface\dialoguemenu.swf`: two enabled providers - **CHIM (rank 6) wins** over Norden UI 16x9 (rank 77). CHIM's SWF is functional, not cosmetic: it adds `skse.SendModEvent` / `PlayMenuTopic` / `startTopicClickedTimer` (player-line TTS hold). The glue must not ship its own `dialoguemenu.swf`.
4. Only one other CHIM file conflict: `SKSE\Plugins\NPCsNamesDistributor.ini` (CHIM overrides LoreRim's settings). Nothing overrides any OStim / OARE / OCR / OBody file.
5. Dialogue stack present: **Smart Talk 1.0.5** (hooks `DialogueMenu` vtable + input, builds a quest-topic cache of 7,917 IDs over 148 plugins), Improved Alternate Conversation Camera (forces 1st person, lock-on), Switch Camera During Dialogue, To Your Face, Considerate Followers, Lingering Subtitles Fix, Fuz Ro D-oh 2.5, Dynamic Activation Key, Simple Activate, BTPS, TDM, SmoothCam. NOT present: Dynamic Dialogue Replacer, Better Dialogue Controls, Dialogue Movement Enabler, DBVO, RDO, Mantella, MinAI.
6. Quest content is Requiem-centric and moderate: VIGILANT, Wyrmstooth, Forgotten City, Gray Cowl, S&S Extended Cut, Sirenroot, Meridia's Order, Journey to Baan Malur, Missives (+expansion), AJO jobs, 17 "Quest Expansion" mods, followers Inigo/Lucien/Auri/Remiel/Taliesin/Katana/Gore, SDE, 53 dialogue-expansion/edit mods. NOT present: LOTD, Bruma, 3DNPC, Falskaar, Glenmoril, Unslaad, Clockwork, AHO, Beyond Reach, Helgen Reborn, Moonpath, Midwood.
7. **Compile blocker:** base-game Papyrus sources do NOT exist on disk (no `Scripts.zip`, no CK, SKSE mod only has its 62 overridden `.psc`). SkyUI SDK (`SKI_ConfigBase.psc`) and MCM Helper SDK (`MCM_ConfigBase.psc`, `MCM.psc`) sources are also absent. PapyrusUtil, JContainers, PO3, ConsoleUtil, OStim, OCR, OBody, CHIM sources ARE present.
8. MCM Helper 1.6.2 convention on this install is `MCM\Config\<ModName>\config.json` + `settings.ini` at Data root (71 enabled examples; **zero** under `Interface\MCM\Config`), user values in `MCM\Settings\<ModName>.ini`.
9. Behaviours: **Nemesis 0.84** (no Pandora). Nemesis was re-run 2026-09-21 02:04 with `ostim` + `oanims` patches ticked and OCR / openani_* registered; winning `0_master.hkx` contains 4,867 "OStim" strings. BUT output was written *in place* into `LoreRim - Nemesis Output` (and 4 other mods); the new top-priority mod `Nemesis Output - OStim` holds only `.pyc` files - there is no pristine fallback.
10. OStim.dll 7.5.1 is an Address-Library/NoStructUse build (version independent, OK for 1.6.1170) but has **never been loaded in game yet** (last run 01:09-01:14 predates its install; no `OStim.log`). `AIAgent.dll` loaded fine, but **`AIAgent.ini` does not exist anywhere** - CHIM's game side is not yet pointed at the server.

---

## 1. Profile `Ultra`: plugins.txt, loadorder.txt, modlist.txt

### 1.1 Files **[V]**
`PROFILE` contains `modlist.txt` (149,865 b, 2026-09-21 01:59), `plugins.txt` (123,860 b, 02:05), `loadorder.txt` (122,555 b, 02:05), `settings.ini`, `archives.txt`, `lockedorder.txt` (empty), `initweaks.ini`, profile-local `skyrim.ini / skyrimcustom.ini / skyrimprefs.ini`, `saves\`. Backups made today: `modlist.txt.bak-20260921-055706`, `settings.ini.bak-20260921-055706`, plus Wabbajack-time `plugins.txt.2026_07_01_14_59_24` / `loadorder.txt.2026_07_01_14_59_24`.

`PROFILE\settings.ini` (whole file):
```
[General]
LocalSaves=true
LocalSettings=true
AutomaticArchiveInvalidation=false

[custom_overwrites]
xEdit%20QAC=LoreRim - xEdit64 Output
xEdit%20VQSC=LoreRim - xEdit64 Output
Nemesis%20Dev%20Build=Nemesis Output - Dev Build
Nemesis=Nemesis Output - OStim
Reqtificator=LoreRim - Reqtificator
Synthesis=LoreRim - Synthesis Output
xEdit32=LoreRim - xEdit64 Output
xEdit64=LoreRim - xEdit64 Output
```
Diff vs. today's backup **[V]**: `Nemesis=LoreRim - Nemesis Output` was changed to `Nemesis=Nemesis Output - OStim` (settings.ini:10); `modlist.txt` gained exactly one line, `+Nemesis Output - OStim`.

### 1.2 plugins.txt **[V]**
- 3,415 lines = 1 comment + **3,414 entries, all active (`*`)**, 0 inactive. By extension: 14 `.esm`, 3,341 `.esp`, 59 `.esl`.
- Tail of file (file line numbers):
```
3412: *AIAgent.esp
3413: *OStim.esp
3414: *OStimCommunityResource.esp
3415: *OBody.esp
```
  i.e. active positions 3411-3414 of 3414. Other anchors: `SkyUI_SE.esp` 165, `MCMHelper.esp` 167, `UIExtensions.esp` 508, `Requiem.esp` 1598, `Requiem for the Indifferent.esp` (Reqtificator output) 1717, `DynDOLOD.esp` 3406, `Occlusion.esp` 3407.
- Diff vs. the Wabbajack-time copy: added `Pure Auri Replacer.esp`, `Auri Patch.esp`, `AIAgent.esp`, `OStim.esp`, `OStimCommunityResource.esp`, `OBody.esp`; removed `AuriLL.esp`, `LoreBox.esp`.

### 1.3 loadorder.txt and real slot usage **[V]**
- 3,494 plugin lines (3,414 from plugins.txt + 80 implicit: Skyrim/Update/DLC, CC content, `cckrtsse001_altar.esl`, `_ResourcePack.esl`). Last four lines identical to plugins.txt tail.
- I read the TES4 header (flags @ offset 8, HEDR version @ offset 30) of the conflict-winning copy of every load-order entry (script `pl.ps1` in SCRATCH): **177 full-slot plugins, 3,317 light (ESL-flagged or .esl)**; 1,189 plugins use HEDR 1.71.
- Cross-check with the real runtime list in `DOCS_SKSE\skse64.log` (run of 2026-09-21 01:09, before OStim was added): 176 full + 3,315 light; `skse64.log:7000` = `[175]	AIAgent.esp`; `cckrtsse001_altar.esl` = `[FE:62]`, `_ResourcePack.esl` = `[FE:64]` (SKSE prints light indices in decimal).
- Headers of the four plugins:

| Plugin | Slot type | Expected index now | HEDR | Records | Masters |
|---|---|---|---|---|---|
| `AIAgent.esp` | FULL | 0xAF (175) [V runtime] | 1.71 | 152 | Skyrim, Update, Dawnguard, HearthFires, Dragonborn |
| `OStim.esp` | ESL-flagged (flags 0x200) | FE:CF3 [I] | 1.70 | 1129 | Skyrim.esm, HearthFires.esm |
| `OStimCommunityResource.esp` | FULL | 0xB0 (176) [I] | 1.70 | 1078 | Skyrim, Update, Dawnguard, HearthFires |
| `OBody.esp` | ESL-flagged | FE:CF4 [I] | 1.70 | 11 | Skyrim.esm |

- No active plugin lists `AIAgent.esp`, `OStim.esp`, `OStimCommunityResource.esp` or `OBody.esp` as a master **[V]** (header scan of all 3,492 resolved plugins).

### 1.4 modlist.txt **[V]**
4,083 lines: 3,889 enabled mods (non-separator `+`), 54 disabled mods, 135 separators, 4 unmanaged (`*Creation Club: _ResourcePack`, `*DLC: Dawnguard/Dragonborn/HearthFires`). No enabled mod folder is missing on disk. Version separator at line 4079: `-5.0.4.8_separator`.

Top of file (highest priority first):
```
2  +Nemesis Output - OStim
3  +OBody Next Generation
4  +Open Animations Romance and Erotica for OStim Standalone
5  +OStim Community Resource
6  +OStim Standalone - Advanced Adult Animation Framework
7  +CHIM
8  +Prisma UI - Next-Gen Web UI Framework
9  -ADDING ANY MODS VOIDS SUPPORT - RULES IN DISCORD.GGBUNGALO_separator
10 -NL_CMD - A Console Command Framework
11 -Papyrus Profiler
12 -Testing Mods - Ignore If Empty_separator
13 +ENB Local Override (ALWAYS KEEP ON)
14 +Norden simple main menu Lorerim logo
...
129 +LoreRim - DynDOLOD Output        139 +LoreRim - Synthesis Output
140 +LoreRim - Nemesis Output          141 +LoreRim - xEdit64 Output
142 +LoreRim - Reqtificator            143 +LoreRim - MCM and INI Settings
144 +LoreRim - BodySlide Output        147 +Norden UI 16x9
148 +Norden UI DIP                     149 +Outputs & Final Overwrites_separator
```
Bottom (lowest priority) is the core: `4029 +Skyrim Script Extender (SKSE64)`, `4027 +Address Library for SKSE Plugins`, `4019 +PapyrusUtil SE ...`, `4018 +powerofthree's Papyrus Extender`, `4012 +JContainers SE`, `3998 +ConsoleUtilSSE NG`, `3806 +SkyUI`, `3804 +UIExtensions`, `3801 +MCM Helper`, `4074 +Unofficial Skyrim Special Edition Patch`, `4067 +1.6.1170 Missing Files`.

Installed versions of the seven new mods (meta.ini): CHIM 3.3.2.0 (`AIAgent 126330 3.3.2 2026-09-06...zip`), Prisma UI 1.5.1.0, OStim Standalone 7.5.1.0, OARE 1.52.1.0, OStim Community Resource 1.17.6.0, OBody NG 4.4.3.0.

---

## 2. dialoguemenu.swf and other CHIM Interface files

### 2.1 Providers **[V]** (Glob `MODS\*\Interface\dialoguemenu.swf`)
| Mod | Enabled? | modlist line / rank | Size | 
|---|---|---|---|
| `CHIM` | yes | line 7 / rank 6 | 24,908 b (CWS, SWF v10, 77,300 b decompressed) |
| `Norden UI 16x9` | yes | line 147 / rank 77 | 27,123 b (CWS v10, 89,576 b decompressed) |
| `Norden UI 21x9` | **no** (line 29 `-`) | - | - |

No loose copy in `Stock Game\Data\Interface`. **Winner: CHIM.** (Loose files beat any BSA copy, e.g. vanilla `Skyrim - Interface.bsa`.)

### 2.2 What CHIM's SWF adds **[V]** (zlib-decompressed both SWFs, diffed constant-pool strings)
Strings present in CHIM's SWF and absent from Norden's: `Entry5`, `timerBool`, `initMenu`, `join`, `PlayMenuTopic`, `skse`, `SendModEvent`, `startTopicClickedTimer`, `timer`, `setTimeout`. Constant-pool order around it: `... topicClicked | selectedEntry | ... | split | join | PlayMenuTopic | skse | SendModEvent | startTopicClickedTimer | off | TopicClicked | ...`.
Norden-only string: `QuestMarker`. Neither SWF contains `iQuestColor`, `bQuestIconVisible` or `SetEntryTextCustom` (the hooks Smart Talk's INI refers to).

Papyrus side, `MODS\CHIM\Source\Scripts\AIAgentPapyrusFunctions.psc`:
```
1097	; Traditional dialogue Player TTS is handled in native code.
1098	; Re-registering the Papyrus bridge here can resume the held topic twice.
1099	UnRegisterForModEvent("PlayMenuTopic")
1100	UnRegisterForModEvent("AIAgent_PlayerMenuTTSFinished")
...
1170	Event OnPlayerMenuTopicSelected(String eventName, String strArg, Float numArg, Form sender)
1173		if (AIAgentFunctions.get_conf_i("_player_tts_traditional_dialogue") <= 0)
1174			UI.InvokeString("Dialogue Menu", "_root.DialogueMenu_mc.startTopicClickedTimer", "off")
1178		int started = AIAgentFunctions.startPlayerMenuDialogueTTS(strArg)
...
1186	Event OnPlayerMenuTTSFinished(String eventName, String strArg, Float numArg, Form sender)
1193			UI.InvokeString("Dialogue Menu", "_root.DialogueMenu_mc.startTopicClickedTimer", "off")
```
Also `AIAgentAIMind.psc:1438`: `while UI.IsMenuOpen("Dialogue Menu") && safety < 40`.
**[I]** The SWF holds a clicked topic, emits mod event `PlayMenuTopic` with the topic text, and resumes when `startTopicClickedTimer("off")` is invoked - a DBVO-style "speak the player's line" feature. If Norden's SWF won instead, that CHIM feature would break; with CHIM winning, the dialogue menu loses Norden's skin.

### 2.3 Other Interface files shipped by CHIM **[V]**
`Interface\Translations\AIAgent_{czech,english,french,german,italian,japanese,polish,russian,spanish}.txt` (64 b each) - **no other enabled mod provides them**. `skse64.log:2325` shows `Reading translations from Interface\Translations\AIAgent_ENGLISH.txt...`.

### 2.4 Full conflict check for the new mods **[V]**
Index of all enabled mods (`Interface`, `Scripts`, `Source`, `SKSE`, `PrismaUI\views\CHIM`, `Nemesis_Engine`, behaviour dirs, `meshes\0SA`, `meshes\OStim`, `meshes\OARE_animations`, `meshes\OCR_*`, `meshes\...\animations\{OStim,OCR,openani_*}`, `textures\{OStim,OCR_npcasset,AIAgent}`, `Sound\fx\{OStim,AIAgent}`, `Sound\Voice\AIAgent.esp`, `Seq`, `CalienteTools\...\OStim *`). Conflicts that involve CHIM / OStim / OARE / OCR / OBody / Prisma UI / Nemesis Output - OStim: **exactly two**
```
interface\dialoguemenu.swf              [6] CHIM (24908b)  >  [77] Norden UI 16x9 (27123b)
skse\plugins\npcsnamesdistributor.ini   [6] CHIM (2185b)   >  [73] LoreRim - MCM and INI Settings (2164b)
```
NND ini diff: CHIM sets `sCrosshairMinion/sSubtitles/sDialogue/sDialogueHistory/sInventory/sBarter/sEnemyHUD/sOther = display` and `iFormat = 3`; LoreRim had `title/short/full/...` and `iFormat = 0`. NPCs Names Distributor itself is enabled (modlist lines 1233-1235). (Not indexed: `.dds` icons under `Interface\OStim\icons` - cosmetic only.)

---

## 3. Enabled mods affecting dialogue / conversation camera / subtitles / activation

Name heuristics over `modlist.txt`, then file inspection. "NOT FOUND" = no match in modlist.txt for the given pattern.

| Mod (version, modlist line) | What it is [V] | Interaction with a menuless-dialogue approach [I] |
|---|---|---|
| **CHIM** 3.3.2 (7) | `Interface\dialoguemenu.swf` + native topic-click hook (see 2.2) | Only matters while the vanilla Dialogue Menu is open; menuless flow bypasses it. Do not replace the SWF. |
| **Norden UI 16x9** 1.2.5 (147) | UI skin; loses `dialoguemenu.swf` to CHIM | No effect if no menu is opened. |
| **Smart Talk (Dialogue Menu Enhancer)** 1.0.5 (3842) | `SKSE\Plugins\SmartTalk.dll` + `SmartTalk.ini`. Log: `ProcessInputHook hooked`, `DialogueMenu hooked at virtual table index 0x4`, `Initializing quest topic cache`, `Quest topic cache initialization DONE after 22.12 seconds` (`DOCS_SKSE\SmartTalk.log:8-13`). Cache file `MODS\LoreRim - MCM and INI Settings\SKSE\Plugins\SmartTalk_QuestCache.json` | Hooks only DialogueMenu + input, so inert without the menu. Its INI documents a hazard the glue shares: `SmartTalk.ini:109-116` "skipping a dialogue too early may prevent the fragment from running" (`iPapyrusHandle = 3` = forced >=500 ms pause). Its cache is a ready-made static list of quest-relevant dialogue FormIDs (see 3.1). |
| **Improved Alternate Conversation Camera** 1.2.0 (3709) | DLL + `ImprovedAlternateConversationCamera.esp` + 2 pex. Winning ini (LoreRim settings mod): `bForceFirstPerson=1`, `bLockOn=1`, `bHeadTracking=1`, `bConversationHT=1`, `bHideDialogueMenu=1`, `bLetterBox=0` | Triggers on dialogue-menu state; menuless flow gets no camera lock/zoom - glue would have to do its own facing/head-tracking if wanted. If the glue ever opens the real menu briefly, camera will snap to 1st person. |
| **Switch Camera During Dialogue** 1.1 (1145) | `CameraSwitchDuringDialogue.esp` + `CameraSwitchScript.pex` (source included) | Same: keyed to dialogue state; bypassed. |
| **To Your Face SE** (3983) | `ToYourFace.dll`, `MaxDeviationAngle = 30` | Limits ambient NPC comments to NPCs in front of player; independent of menus. May suppress idle "hello" lines the LLM context expects. |
| **Considerate Followers** 1.3.0 (3840) | `ConsiderateFollowers.dll`, `fMaxConversationDistance = 400.0` | Silences followers while the player is in (engine) dialogue; menuless flow will not trigger it, so follower chatter can overlap CHIM speech. |
| **Lingering Subtitles Fix** 1.2.4 (3961) | DLL; log "PlayerCharacter::Update hooked" | Benign; subtitle cleanup. |
| **Fuz Ro D-oh** 2.5 (3679) | `Fuz Ro D'oh.dll` | Gives unvoiced INFO lines a timed silent playback + lip file; relevant if the glue ever forces a real INFO to be spoken (`Say`). |
| **Dynamic Activation Key** 1.12 (1289-1291) | DLL + 3 esp + MCM | Rebinds/branches the Activate key (hold/tap variants). A menuless "talk" key must not collide; activation of NPCs may already be modified. |
| **Simple Activate SKSE** 1.4.2 (3867) + **Oblivion Interaction Icons** 2.6 (3759) | `po3_SimpleActivateSKSE.ini` winner is OII's copy: `[Hide Button] NPCs = true`, text kept | Crosshair prompt only. |
| **Better Third Person Selection** 0.7.1 (3787), **Contextual Crosshair** 1.3.2 (3742), **SkyInteract** 1.0.8 (317), **Remote Interactions** 1.06 (933, 49 pex), **First Person Interactions** 1.7.6, **Immersive Interactions** 1.78 | Activation-target selection / activation animations | If the glue suppresses vanilla activation-to-dialogue on NPCs, test against BTPS (it changes which ref is the activate target). |
| **True Directional Movement** 2.2.5 (3762), **SmoothCam** 1.7 (3716, + 5 presets), **Camera Persistence Fixes** 1.1, **No Furniture Camera** 1.0 | 3rd-person camera stack | OStim scenes use their own free camera; SmoothCam/TDM are standard OStim-compatible but untested here. |
| **Collision Dialogue Overhaul** 1.01 (1135), **Guard Dialogue Overhaul**, **Extended Guard/Bandit Dialogue**, **Naked Comments Overhaul**, **Taunt Your Enemies**, **Narrative Gameplay Consistent Dialogue Tweaks** (+8 patches), **Immersive Speech Dialogues**, 53 mods named "...Dialogue Expansion / Dialogue Edit / Dialogue Tweak / Dialogue Bundle" (mostly Follower Dialogue Expansion), **Serana Dialogue Expansion (+Romance ESL)** | Plugin-side DIAL/INFO additions and condition edits | These are exactly the data the menuless system must enumerate generically; they change INFO conditions of vanilla topics (NGCDT especially), so topic availability must be evaluated at runtime, never pre-baked. |
| **Whose Quest is it Anyway NG** 1.5 (3866), **Yes Im Sure** 1.7 (3859), **Papyrus MessageBox - SKSE NG** 1.0 (3997) | Message-box helpers | `SkyMessage.psc` is available as a non-menu-editing confirm UI if needed. |
| **Infinity UI + Compass Navigation Overhaul** (53), **SKSE Menu Framework** 3.9 (3996), **Prisma UI** 1.5.1 (8), **UIExtensions** 1.2.0 (3804) | UI frameworks | Prisma UI is what CHIM uses for chat box/overlays (`MODS\CHIM\PrismaUI\views\CHIM\*.html/js`). |

NOT FOUND in modlist.txt **[V]**: "Dynamic Dialogue Replacer"/DDR, "Better Dialogue Controls", "Dialogue Movement Enabler", "EZ2C", "Dialogue History", DBVO / "Dragonborn Voice Over", "Relationship Dialogue Overhaul"/RDO, "Misc Dialogue Edits", Mantella, MinAI, Dylbill's Papyrus Functions, Improved Camera SE. (Dialogue Movement Enabler's absence means the player is locked in place while the vanilla menu is open - another argument for menuless.)

Profile INI facts **[V]**: `skyrimprefs.ini`: `bDialogueSubtitles = 1`, `bGeneralSubtitles = 1`. `skyrim.ini [Papyrus]`: `bEnableLogging=0`, `bEnableTrace=0`, `bLoadDebugInformation=0`, `fPostLoadUpdateTimeMS=2000`, `iMaxAllocatedMemoryBytes=500000` (Papyrus log is OFF). Papyrus Tweaks NG 4.1.1 (winning ini in `LoreRim - MCM and INI Settings`): `iMaxOpsPerFrame = 1000`, `bSpeedUpNativeCalls = false`, `bEnableDebugInformation = true`, `iStackDumpTimeoutMS = 15000`.

### 3.1 Smart Talk quest cache (useful data source) **[V]**
`MODS\LoreRim - MCM and INI Settings\SKSE\Plugins\SmartTalk_QuestCache.json` (61,253 b, regenerated 2026-09-21 01:12:37):
```
{"GlobalHash":16618107869329582009,"PluginsHash":6073047490085241943,
 "QuestTopics":{"00Taliesin.esp":[5602025,4212391,...],"018Auri.esp":[338144,...], ... }}
```
148 plugins, **7,917 IDs** (decimal, plugin-local FormIDs). Largest: Skyrim.esm 3,062; Missives.esp 345; Dragonborn.esm 277; HLIORemi.esp 228; Dawnguard.esm 224; HearthFires.esm 222; Katana.esp 215; Lucien.esp 208; Meridia.esp 200; Vigilant.esm 194; Favor Quests Seperated.esp 192; Andrealphus Jobs Overhaul - MASTER.esp 166; Journey to Baan Malur.esp 133; Inigo.esp 117; SeranaDialogueExpansion.esp 113; GORE.esp 94; MissivesExpansion.esp 89; Wyrmstooth.esp 87; ForgottenCity.esp 56.
Selection rule per `SmartTalk.ini:23-40`: `iPapyrusFilterMode = 2` (fragment calls `Start()/Stop()` or `SetStage()`), `bRequireLinkedQuest = 0`, `iLinkedQuestFilter = 3`. **[I]** Whether the IDs are TopicInfo (INFO) or Topic (DIAL) FormIDs is not stated in the ini/log; key name says "QuestTopics". Must be checked against xEdit before relying on it. The cache was generated BEFORE OStim/OCR were added (hash will change on next launch).

---

## 4. Quest / new-land / expansion / follower mods and Requiem

Checked `plugins.txt` for the signature plugin of each mod **[V]**.

**Present (active plugin found):**
- New lands / big quests: `Vigilant.esm` (+ `VIGILANT SE.esp`, voiced EN addon, Delayed Start), `Wyrmstooth.esp`, `ForgottenCity.esp`, `Gray Fox Cowl.esm` (10th anniversary ed. + "Under New Management Start"), Saints & Seducers Extended Cut (`SkyrimExtendedCut...`), `evgSIRENROOT.esm`, `Meridia.esp` (Meridia's Order ESMIFIED), `Journey to Baan Malur.esp`, `Belethor's Sister.esp`, `WindhelmSSE.esp`, Land of Razors (Deadlands), The Cause tweaks, all AE Creation Club content (`cc*.esl/esm`).
- Radiant / boards / jobs: `Missives.esp` + `MissivesExpansion.esp` (Voice and Quest Expansion) + Solstheim/Gray Cowl patches, `Andrealphus Jobs Overhaul - MASTER.esp` (AJO), `Favor Quests Seperated.esp`, Additional Contracts for the Dark Brotherhood, Listen - DB Radiant Quests, Companions Radiant Expansion. No "Notice Board"; no bounty-board mod (`Bounty` pattern only hit `zeroBountyHostilityFix.esp`).
- Vanilla quest expansions: College of Winterhold QE, Paarthurnax QE, House of Horrors QE, The Only Cure QE, Innocence Lost QE, Caught Red Handed QE, Nilheim, Heart of Dibella QE, Search and Seizure QE, Destroy the DB QE, Infiltration QE, Toying With The Dead, Whispering Door QE, Cursed Tribe QE, The Choice is Yours, Timing is Everything, Thugs Not Assassins, Less Tedious Thieves Guild, Civil War F Off, SeranaCureQuestPlus, Customizable Companions Questline Requirements, CC Farming quest expansion.
- Start: `AlternatePerspective.esp` (+ Voiced Addon, Adventurer's Start, New Beginnings, Messenger).
- Followers (custom-voiced, own dialogue trees): `Inigo.esp` (+Lulu's INIGO 2.0), `Lucien.esp`, `018Auri.esp`, `HLIORemi.esp` (Remiel), `00Taliesin.esp`, `Katana.esp`, `GORE.esp`, Serana Dialogue Expansion (+Romance ESL), Simple Follower Framework, Swiftly Order Squad, plus the 53 dialogue-expansion/edit mods noted in section 3.

**NOT FOUND in plugins.txt:** Legacy of the Dragonborn, Beyond Skyrim Bruma, Interesting NPCs/3DNPC, Falskaar, GLENMORIL, UNSLAAD, Moonpath, Clockwork (only a stray "Clockwork (SSE) - Settings Loader" MCM config), Project AHO, Beyond Reach, Helgen Reborn, Carved Brink, Moon and Star, Midwood Isle, Notice Board.

**Requiem:** `Requiem - The Roleplaying Overhaul (No Messages ESLIFIED)` (meta.ini `version=6.0.2.0`, but `installationfile=Requiem 5.4.5 - Towers and Shadows Bugfix Pack 5-...7z` - discrepancy unresolved), `Requiem.esp` is a FULL plugin (header flags 0x0; computed slot 0x87). Reqtificator output `Requiem for the Indifferent.esp` at position 1717 (mod `LoreRim - Reqtificator`, MO2 executable `cmd /K Reqtificator.bat`). 81 enabled mods have "Requiem"/"Reqtif" in the name; ~40 `* - Reqtificated.esp` patches.
Speech / persuasion / barter related, enabled **[V]**: `Immersive Speech Dialogues.esp` (77 KB plugin, no scripts), `Beneficial Speech Checks.esp` (+1 pex; XP on speech checks), `Speechcraft Randomization` (3 pex, no plugin), `[LoreRim] Economy Overhaul` (`LoreRim - Economy Overhaul.esp`), `Barter Limit Fix` (DLL), `Dynamic Pricing Framework`, `Missives Quests Raise Disposition`, `Quantity Trade` patches. "Trade and Barter" exists only as a *Settings Loader* (no `Trade & Barter.esp` active). **[I]** Requiem itself gates persuade/intimidate/bribe through Speech perks and GMSTs; a generic executor must let the engine evaluate those INFO conditions rather than assume vanilla thresholds.

---

## 5. Tooling and versions on disk

### 5.1 Tools **[V]** (`F:\Modlists\LoreRim\tools`, file VersionInfo)
| Tool | Path | Version |
|---|---|---|
| xEdit | `tools\SSE Edit (4.0.4)\SSEEdit64.exe` (+`SSEEdit.exe`, `SSEDump64.exe`, 218 Edit Scripts) | **4.1.5.0** (folder name says 4.0.4) ; MO2 args `-D:"...\Stock Game\Data" -IKnowWhatImDoing -pseudoesl` |
| zEdit | `tools\zEdit\zEdit.exe` | 0.6.6 (module: unifiedPatchingFramework) |
| Synthesis | `tools\Synthesis\Synthesis.exe` | 0.35.3 (profiles `Data\Ascensio`, `Data\LoreRim`) |
| ReSaver | `tools\Resaver\ReSaver.exe` / `target\ReSaver.jar` | FallrimTools ReSaver 6.0 (`pom.xml:7`) |
| PCA SE (Papyrus Compiler App GUI) | `tools\PCA\PCA SE.exe` | 5.8.0 - never configured (no `%APPDATA%\pca*`) |
| LOOT 0.24.1, DynDOLOD 3.0.0.203, xLODGen 4.1.5.0, EasyNPC 0.9.6, BethINI, CAO, NifSkope Dev 8, DIP, FaceFXWrapper 0.4 | under `tools\` | - |
| Mod Organizer | `F:\Modlists\LoreRim\ModOrganizer.exe` | 2.5.2, `gamePath=F:\Modlists\LoreRim\Stock Game`, `selected_profile=Ultra`, Root Builder plugin present (`plugins\rootbuilder`) |
| Nemesis | `MODS\Project New Reign - Nemesis Unlimited Behavior Engine\Nemesis_Engine\Nemesis Unlimited Behavior Engine.exe` | v0.84 (PatchLog line 1) |

MO2 also lists executables that do NOT exist on disk: `Stock Game/CreationKit.exe`, `Stock Game/ckpe_loader.exe`, `Stock Game/CKPE.Installer.exe`, `tools/PGPatcher`, `tools/Sniff` **[V: Stock Game root listing has no CreationKit.exe]**.

### 5.2 Game / SKSE / Address Library **[V]**
- `F:\Modlists\LoreRim\Stock Game\SkyrimSE.exe`: FileVersion **1.6.1170.0** (37,157,144 b). `Stock Game\Data` holds only vanilla ESMs/BSAs + `_ResourcePack` (15 files, no `Scripts`/`Source`/`Scripts.zip`).
- (A separate Steam install exists at `F:\SteamLibrary\steamapps\common\Skyrim Special Edition`, `SkyrimSE.exe` FileVersion 1.7.104.0; no CreationKit.exe, no `Data\Scripts.zip`, no `Data\Source`, no `Data\Scripts`. No Creation Kit app manifest `appmanifest_1946180.acf` in any Steam library.)
- SKSE: mod `Skyrim Script Extender (SKSE64)` (Root Builder layout) -> `Root\skse64_loader.exe`, `Root\skse64_1_6_1170.dll`, FileVersion `0, 2, 2, 6`; `skse64_whatsnew.txt`: "2.2.6 - support for 1.6.1170"; runtime confirmation `skse64.log:1` `SKSE64 runtime: initialize (version = 2.2.6 01064920 ...)`. The mod also ships the full SKSE C++ source tree under `Root\src\` (408 files). `SKSE.ini`: `ClearInvalidRegistrations=1`.
- Address Library: mod v11 -> `SKSE\Plugins\versionlib-1-6-1170-0.bin` (795,129 b) present (plus 1.5.x `version-*.bin` and other 1.6.x libs). Single provider.
- Last game run: 2026-09-21 01:09-01:14 (plus a startup crash at 01:04: `crash-2026-09-21-01-04-24.log`, C++ `std::invalid_argument "invalid stoull argument"` during `InitTESThread`, no plugin named).

### 5.3 Library versions **[V]** (meta.ini + DLL VersionInfo + parsed `SKSEPlugin_Version` export)
| Library | meta.ini | DLL facts |
|---|---|---|
| PapyrusUtil SE | 4.6.0.0 | `PapyrusUtil.dll` compatibleVersions = 1.6.1170.0 |
| JContainers SE | 4.2.9.0 | `JContainers64.dll` 4.2.9, compatibleVersions = 1.6.1170.0 |
| powerofthree's Papyrus Extender | 6.4.0.0 | `po3_PapyrusExtender.dll` 6.4.0.1, compatible 1.6.1170.0 |
| ConsoleUtilSSE NG | 1.5.1.0 | `ConsoleUtilSSE.dll` 1.5.1.0, Address-Library independent |
| SkyUI | 6.11.0.0 (`SkyUI-12604-6-11-...zip`) | BSA-only: `SkyUI_SE.bsa` + `SkyUI_SE.esp` (ESL-flagged, FE:189 dec) |
| MCM Helper | 1.6.2.0 | `MCMHelper.dll` 1.6.2.0, `seVersionRequired=0x02020050` (SKSE 2.2.5+); `MCMHelper.esp` ESL-flagged |
| UIExtensions | 1.2.0.0 | BSA-only; `UIExtensions.esp` FULL slot [65] |
| Others present | Papyrus Tweaks NG 4.1.1, Papyrus MessageBox NG 1.0, NL_MCM 1.1.4 (pex only), Andrealphus' Papyrus Functions 1.7.2, Papyrus Ini Manipulator 1.9.8, Dynamic Persistent Forms, SKSE Menu Framework 3.9, Scaleform Translation++ NG 1.8, Native EditorID Fix 1.2.2, Console Commands Extender 1.12, ConsolePlusPlus 1.5, CrashLogger 1.23.1, Engine Fixes 7.0.19 AIO |

`ConsoleUtil.psc` (Champollion-decompiled, `MODS\ConsoleUtilSSE NG\Scripts\Source\ConsoleUtil.psc:16-26`):
```papyrus
Int function GetVersion() global native
String function ReadMessage() global native
function PrintMessage(String a_message) global native
function ExecuteCommand(String a_command) global native
ObjectReference function GetSelectedReference() global native
function SetSelectedReference(ObjectReference a_reference) global native
```
Dialogue-related natives in installed libraries **[V]**: SKSE `Actor.psc:190` `Actor Function GetDialogueTarget() native`, `Game.psc:446` `ObjectReference Function GetDialogueTarget() global native`, `ObjectReference.psc:426` `bool Function IsInDialogueWithPlayer() native`, `ObjectReference.psc:517` `Function Say(Topic akTopicToSay, Actor akActorToSpeakAs = None, bool abSpeakInPlayersHead = false) native`, `FormType.psc:78-79,118` `kTopic = 75`, `kTopicInfo = 76`, `kDialogueBranch = 115`. `PO3_SKSEFunctions.psc` has NO topic/dialogue function (only `GetActorsInScene` :1025 / `IsActorInScene` :1027). `ANDR_PapyrusFunctions.psc:381` `GetCurrentDialogueTopic()` and `:491` `EndDialogue(Actor akActor)` are inside `;/ ... /;` "Wish list" comment blocks -> **not available**.

### 5.4 Papyrus SOURCE locations (crucial for compiling)
| Needed | Status | Exact path |
|---|---|---|
| Base game (`Debug.psc`, `TopicInfo.psc`, `Scene.psc`, `ReferenceAlias.psc`, `Topic.psc`, ...) | **NOT FOUND** | Looked: `Stock Game\Data` (no Scripts/Source/Scripts.zip), Glob `MODS\**\{TopicInfo,Debug,Scene,ReferenceAlias}.psc` (only Nemesis stubs), `downloads\*cript*`, Steam install on F:. |
| SKSE overrides (62 files: `Actor, ActorBase, Alias, Form, Game, Quest, ObjectReference, UI, ModEvent, Input, StringUtil, Utility, SKSE, FormType, ...`) | FOUND | `MODS\Skyrim Script Extender (SKSE64)\Scripts\Source\` (pex in `Scripts\`) |
| `TESV_Papyrus_Flags.flg` | FOUND (standard content, 659 b) | `MODS\Project New Reign - Nemesis Unlimited Behavior Engine\Nemesis_Engine\Papyrus Compiler\scripts\TESV_Papyrus_Flags.flg` - NOTE the `.psc` files beside it (`Actor.psc` = 49 b etc.) are **stubs**; never put that folder on the import path. |
| SkyUI SDK (`SKI_ConfigBase.psc`, `SKI_QuestBase.psc`, `SKI_WidgetBase.psc`) | **NOT FOUND** | `MODS\SkyUI` = `SkyUI_SE.bsa` + esp only; BSA name table has 12 `.pex`, no `.psc` files. |
| MCM Helper SDK (`MCM_ConfigBase.psc`, `MCM.psc`) | **NOT FOUND** | `MODS\MCM Helper\Source\Scripts\` contains only `SKI_ConfigMenu.psc`; `MCMHelper.bsa` has `mcm.pex`, `mcm_configbase.pex`, `ski_configmenu.pex` only. |
| PapyrusUtil (`StorageUtil, JsonUtil, MiscUtil, PapyrusUtil, ActorUtil, ...` 6 scripts) | FOUND (duplicated) | `MODS\PapyrusUtil SE - Modders Scripting Utility Functions\Scripts\Source\` and `...\Source\Scripts\` |
| JContainers (`JValue, JMap, JArray, JDB, JContainers, ...` 12) | FOUND | `MODS\JContainers SE\Scripts\source\` |
| PO3 (`PO3_SKSEFunctions, PO3_Events_Alias, PO3_Events_Form, PO3_Events_AME, ...` 8) | FOUND | `MODS\powerofthree's Papyrus Extender\Source\scripts\` |
| `ConsoleUtil.psc` | FOUND | `MODS\ConsoleUtilSSE NG\Scripts\Source\ConsoleUtil.psc` |
| UIExtensions (`UIExtensions.psc`, `UIListMenu.psc`, `UIMenuBase.psc`, `UIWheelMenu.psc`, ...) | only INSIDE BSA | `MODS\UIExtensions\UIExtensions.bsa` name table lists 12 `.psc` + 12 `.pex`; no loose copy. |
| OStim (29: `OSKSE, OSexIntegrationMain, OThread, OActor, OMetadata, OUtils, OLibrary, ...`) | FOUND | `MODS\OStim Standalone - Advanced Adult Animation Framework\Scripts\Source\` |
| OStim Community Resource (22) | FOUND | `MODS\OStim Community Resource\Source\Scripts\` |
| OBody (5) | FOUND | `MODS\OBody Next Generation\Source\Scripts\` |
| CHIM (34 psc / 33 pex; `AIAgentFunctions.psc` 8,602 b) | FOUND | `MODS\CHIM\Source\Scripts\` |
| Others | FOUND | `SkyMessage.psc` (`Papyrus MessageBox - SKSE NG\Scripts\Source`), `PapyrusIniManipulator.psc`, `ANDR_PapyrusFunctions.psc`, `DynamicPersistentForms.psc` (`DPF ...\Source\Scripts`). NOT FOUND: `NL_MCM.psc`, `PrismaUI.psc`, `DbSkseFunctions.psc`. |

### 5.5 MCM Helper layout on this install **[V]**
Convention: `<mod>\MCM\Config\<ModName>\config.json` (+ `settings.ini` defaults, optional `keybinds.json`), NOT `Interface\MCM\Config`. 71 such folders among enabled mods, 0 under `Interface\MCM\Config`. Saved user values: `MCM\Settings\<ModName>.ini` (65 files in `LoreRim - MCM and INI Settings\MCM\Settings\`). MCM Helper itself ships `MCM\Config\SkyUI_SE\config.json`.

Example: `MODS\Alchemical Appraisal Services\`
```
Alchemical Appraisal Services.esp
Interface\translations\Alchemical Appraisal Services_english.txt   (UTF-16 LE with BOM, "$KEY<TAB>Text")
MCM\Config\Alchemical Appraisal Services\config.json
MCM\Config\Alchemical Appraisal Services\settings.ini
Scripts\AlchemicalAppraisalServices_MCM.pex          Source\Scripts\AlchemicalAppraisalServices_MCM.psc
Seq\Alchemical Appraisal Services.seq
```
`config.json` (abridged, lines 1-19):
```json
{
    "$schema": "https://raw.githubusercontent.com/Exit-9B/MCM-Helper/main/docs/config.schema.json",
    "modName": "Alchemical Appraisal Services",
    "displayName": "$AAS_MODNAME",
    "minMcmVersion": 13,
    "cursorFillMode": "topToBottom",
    "content": [
        { "id" : "iMaxAppraisedEffects:Main", "text": "$AAS_MAXEFFECTS", "help" : "$AAS_MAXEFFECTS_H",
          "type": "slider",
          "valueOptions": { "min": 1, "max": 4, "step": 1, "sourceType": "ModSettingInt" } },
        ...
```
`settings.ini`: `[Main]` / `iMaxAppraisedEffects = 4` ... (setting id = `<key>:<Section>`).
MCM script (whole file): 
```papyrus
ScriptName AlchemicalAppraisalServices_MCM extends MCM_ConfigBase
Event OnSettingChange(string a_ID)
    ((Self as Quest) as AlchemicalAppraisalServices_script).LoadOptions()
EndEvent
```
Consumer (`AlchemicalAppraisalServices_Script.psc:3,30-33`): `Import MCM` then `GetModSettingInt(sModName, "iMaxAppraisedEffects:Main")`, `GetModSettingFloat(sModName, "fCostMultiplierForRare:Main")`.
A second example with `keybinds.json`: `MODS\Helmet Toggle 2\MCM\Config\Helmet Toggle 2\keybinds.json`.

---

## 6. OStim install sanity

### 6.1 DLL **[V]**
`MODS\OStim Standalone - Advanced Adult Animation Framework\SKSE\Plugins\OStim.dll`: 8,852,480 b, FileVersion 7.5.1.0, PE timestamp 2026-09-02 09:08:09Z, `OStim.pdb` shipped. Exports: `RequestPluginAPI_Scene`, `RequestPluginAPI_Thread`, `SKSEPlugin_Load`, `SKSEPlugin_Query`, `SKSEPlugin_Version`. `SKSEPlugin_Version`: name `OStim`, pluginVersion 0x07050010, `versionIndependence = 0x1` (AddressLibraryPostAE), `versionIndependenceEx = 0x1` (NoStructUse) -> version-independent AE build; with `versionlib-1-6-1170-0.bin` present it is loadable on 1.6.1170. **[I]** "built for 1.6.1170" is therefore satisfied by design, but it is **unproven at runtime**: `skse64.log` of the last run (01:09) contains no "OStim" line and there is no `OStim.log` in `DOCS_SKSE` - OStim was installed after that run (meta.ini `lastNexusQuery=2026-09-21T05:43:35Z` = 01:43 local).
For comparison, same parse: `AIAgent.dll` (4,134,912 b, PE 2026-09-06 15:33Z) name `AIAgent`, version 3.3.2.0, same independence flags; `skse64.log:354` `plugin AIAgent.dll (00000001 AIAgent 03030020) loaded correctly (handle 7)`; `PrismaUI.dll` `loaded correctly (handle 231)`; `OBody.dll` 4.4.3.0 same flags.

### 6.2 Behaviour engine **[V]**
- **Nemesis**, not Pandora ("Pandora" matches only "Pandorable's Patches"; no `Pandora_Engine` dir in any enabled mod). Enabled: `Project New Reign - Nemesis Unlimited Behavior Engine` (line 3819, v0.84 beta), `Nemesis Creatures BEHAVIOUR compatibility`, `LoreRim - Nemesis Output` (line 140, rank 70), and the new `Nemesis Output - OStim` (line 2, rank 1). Also Animation Queue Fix, Paired Animation Improvements, Open Animation Replacer, XPMSSE, Behavior Data Injector, Auto Skeleton Patch present.
- Nemesis run evidence, `MODS\LoreRim - Nemesis Output\Nemesis_Engine\PatchLog.txt`:
```
1   [21-09-2026 02:04:12] Nemesis Behavior Version: v0.84
9   [21-09-2026 02:04:16] Mod Checked 1: ostim
10  [21-09-2026 02:04:16] Mod Checked 2: oanims
11..55  Mod Checked 3..47: nemesis, abeef, amc, ... wsaf
61  Mod installed: OCR
62-66  Mod installed: openani_furniture / openani_interaction / openani_main / openani_redress / openani_solo
       WARNING(1013) ... animations\immersiveinteractions\..\idlekneel.hkx (pre-existing, unrelated)
       Number of animations: 6326
       Behavior generation complete: 29 second(s) taken
```
  `CriticalLog.txt`: only `Global reset all: TRUE`. No ERROR/FAILED lines.
- Winning behaviour files: `LoreRim - Nemesis Output\meshes\actors\character\behaviors\0_master.hkx` (2,295,696 b, 2026-09-21 02:04), `characters\defaultmale.hkx`, `characters female\defaultfemale.hkx`, `meshes\animationsetdatasinglefile.txt`. ASCII scan of that `0_master.hkx`: **"OStim" x4,867**, "FNIS_openani" x5, "FNIS_OCR" x1, e.g. `Animations\OStim\OStim1P\M\idle\OStim1PStandingM_0.hkx`. -> OStim / OARE / OCR animations ARE registered.
- Source patches: OStim ships `Nemesis_Engine\mod\ostim\0_master` (3 files) and `Nemesis_Engine\mod\oanims\{0_master (6,837 files), defaultfemale, defaultmale}`; OARE ships FNIS-style lists `meshes\actors\character\animations\openani_{main,furniture,interaction,redress,solo}\FNIS_*_List.txt` (main = 1,248 lines) + prebuilt `behaviors\FNIS_openani_*_Behavior.hkx` (5, dated 2023-2025, untouched by the run) ; OCR ships `FNIS_OCR_List.txt` (86 lines) + `FNIS_OCR_Behavior.hkx`.
- **Side effect to know:** because MO2 writes changed files back in place, the 02:04 run modified 182 files inside `LoreRim - Nemesis Output`, plus `TK Dodge RE\...\magicbehavior.hkx`, `Make Non-Exploitable Crossbow Slow Again For Requiem SSE\...\1hm_behavior.hkx` (2 files), `TK Dodge SE\meshes\animationdatasinglefile.txt`, 1 file in `Animated Mounted Casting`, 1 in the Nemesis engine mod. `Nemesis Output - OStim` received only 49 `.pyc` + `meta.ini`; its meta note ("Replaces LoreRim - Nemesis Output (kept as fallback)") is inaccurate - no backup copy of the original output exists in `MODS`.

### 6.3 Content counts **[V]**
| Pack | scene JSON (`SKSE\Plugins\OStim\scenes`) | `.hkx` | other |
|---|---|---|---|
| OStim Standalone 7.5.1 | 280 | 974 | definition files: 86 in `actions`, 23 `action tags`, 80 `facial expressions`, 17 `furniture types`, 10 `events`, 3 `voice sets`; 29 pex/psc; `OStim.esp` |
| OARE 1.52.1 | 287 | 1,571 (1,174 under `meshes\0SA\mod\0Sex\anim`) | no plugin, no scripts |
| OStim Community Resource 1.17.6 | 40 | 92 | 40 files in `sequences`, 27 in `actions`, 22 pex/psc, `OStimCommunityResource.esp`, `Seq\` |

### 6.4 OBody NG / overrides **[V]**
OBody Next Generation 4.4.3 enabled (line 3), `OBody.esp` active (last plugin), `OBody.dll`, `OBody_presetDistributionConfig.json`. Body stack in list: CBBE + CBBE 3BA (3BBB), HIMBO refits, "NeverNude" bodyslide mods (`Bijin Family Bodyslides - CBBE NeverNude`, `Dark Souls Undressed - NeverNude - CBBE`); no SOS/TNG-type mod matched **[I: LoreRim bodies are likely never-nude; cosmetic only]**. No enabled mod overrides any OStim/OARE/OCR/OBody file, and they override nothing else (section 2.4).

---

## 7. Mods bridging CHIM and OStim, or touching AIAgent

- `modlist.txt` patterns `minai|mantella|herika|chim\b|pantella|inworld|chatgpt|LLM|sky ?mind` -> only `+CHIM` **[V]**. MinAI: NOT FOUND (enabled, disabled, or as a folder).
- Grep `AIAgentFunctions|AIAgent\.esp|CHIM_|HerikaServer` over all `*.psc/ini/json/toml/yaml` in `MODS` -> 13 files, **all inside `MODS\CHIM\Source\Scripts`** **[V]**.
- No active plugin has `AIAgent.esp` / `OStim.esp` as master **[V]** (1.3).
- CHIM's own OStim-adjacent script exists: `MODS\CHIM\Source\Scripts\AIAgentIntimacyBubbleEffect.psc` (listed by the grep; content is another agent's topic).
- CHIM game-side config: strings in `AIAgent.dll` (sibling agent's dump `SCRATCH\dll_ascii.txt:4403,4405,5572,7616`): `AIAgent.ini`, `Using AIAgent.ini configuration: http://{}:{}{}`, `[CHIM] AIAgent.ini is missing or invalid.`, `[CHIM] Cannot connect to the server. Check AIAgent.ini.` **`AIAgent.ini` / `AIAgent.log`: NOT FOUND** in `MODS\CHIM`, `overwrite`, `Stock Game`, `LoreRim - MCM and INI Settings`, or `Documents\My Games\Skyrim Special Edition`. WSL distro `DwemerAI4Skyrim3` is Running (WSL2); `F:\DwemerDistro` present.

---

## Implications for the glue

1. **Plugin format/slot.** Ship an ESL-flagged `.esp`. Light slots 3,317/4,096 used. Put it after `OBody.esp` (end of `plugins.txt` and `loadorder.txt`) and the mod folder at the top of `modlist.txt` (above `+Nemesis Output - OStim`). Masters may include `AIAgent.esp` (full) and `OStim.esp` (ESL-flagged) - both load before it. HEDR 1.70 with FormIDs 0x800-0xFFF is the conservative choice; 1.71 is legal on 1.6.1170 (AIAgent.esp uses it).
2. **Never hard-code load indices.** `AIAgent.esp` = 0xAF today only because it is the last full plugin; `OStim.esp` is in FE space. Use `Game.GetFormFromFile(localID, "OStim.esp")` / `Game.GetModByName`.
3. **Do not ship `Interface\dialoguemenu.swf`.** CHIM's copy must keep winning (it implements `PlayMenuTopic` / `startTopicClickedTimer`). If a SWF change is ever unavoidable it has to be a patch of CHIM's file, prioritised above CHIM.
4. **Menuless is the low-conflict route here.** Every dialogue mod found (Smart Talk, ACC, Switch Camera, Considerate Followers, CHIM's SWF) keys off the vanilla Dialogue Menu; none hooks topic evaluation itself. Consequences to design for: no camera lock / head-tracking (ACC), followers not auto-silenced (Considerate Followers), no Fuz Ro D-oh timing unless a real INFO is spoken, and the fragment-timing hazard Smart Talk documents (fragments run on INFO begin/end - executing effects "programmatically" must reproduce both).
5. **Topic availability must be evaluated live.** NGCDT (+8 patches), Requiem, ~45 dialogue expansions and Reqtificator/Synthesis outputs rewrite INFO conditions; any offline-extracted table is only an index, not truth. `SmartTalk_QuestCache.json` (7,917 IDs / 148 plugins) is a free, already-generated index of quest-affecting dialogue - verify ID type in xEdit 4.1.5 (`SSEEdit64.exe -D:"F:\Modlists\LoreRim\Stock Game\Data"` through MO2) before use; regenerate after load-order changes.
6. **Compile environment must be completed before any Papyrus work:** obtain (a) vanilla script sources (Creation Kit `Scripts.zip`; CK is not installed), (b) SkyUI SDK sources, (c) MCM Helper SDK sources (`MCM_ConfigBase.psc`, `MCM.psc`) if an MCM is wanted. Keep them OUTSIDE `F:\Modlists`. Suggested import order (first wins): glue sources; `CHIM\Source\Scripts`; `OStim ...\Scripts\Source`; `OStim Community Resource\Source\Scripts`; MCM Helper SDK; SkyUI SDK; `powerofthree's Papyrus Extender\Source\scripts`; `PapyrusUtil ...\Scripts\Source`; `JContainers SE\Scripts\source`; `ConsoleUtilSSE NG\Scripts\Source`; `Skyrim Script Extender (SKSE64)\Scripts\Source`; vanilla sources last. Flags file: copy of `TESV_Papyrus_Flags.flg` (path in 5.4). Never import the Nemesis `Papyrus Compiler\scripts` stubs.
7. **MCM (if any):** use MCM Helper layout `MCM\Config\<ModName>\config.json` + `settings.ini`, translations `Interface\Translations\<PluginName>_english.txt` in UTF-16 LE BOM, script `extends MCM_ConfigBase`, read with `MCM.GetModSettingInt/Float(...)`, `minMcmVersion: 13` is what the installed example uses [I: accepted by MCM Helper 1.6.2]. Alternative that avoids the missing SDKs entirely: configure the glue from the HerikaServer side / a JSON read with PapyrusUtil `JsonUtil` or JContainers.
8. **Available native helpers without writing a DLL:** PapyrusUtil 4.6, JContainers 4.2.9, PO3 PE 6.4.0.1, ConsoleUtilSSE NG 1.5.1 (`ExecuteCommand`), SkyMessage, UIExtensions 1.2, Papyrus Ini Manipulator, DPF. None exposes Topic/TopicInfo enumeration or "run this INFO's fragment"; SKSE gives only `Say`, `GetDialogueTarget`, `IsInDialogueWithPlayer`. If the menuless executor needs INFO condition evaluation / fragment dispatch, that is the justification point for an SKSE DLL (CommonLibSSE-NG, Address Library, flags as in OStim/AIAgent: `AddressLibraryPostAE` + `NoStructUse`). OStim.dll additionally exports a native API (`RequestPluginAPI_Scene`, `RequestPluginAPI_Thread`).
9. **Before first in-game test:** (a) create `AIAgent.ini` (absent) so CHIM can reach the server; (b) launch once and confirm `DOCS_SKSE\OStim.log` reports scenes loaded (280 + 287 + 40 JSON expected) - OStim has never run here; (c) consider enabling Papyrus logging (`bEnableLogging/bEnableTrace=1` in `PROFILE\skyrim.ini` - user decision; currently 0); (d) MO2 must be closed (or refreshed) when `modlist.txt` / `plugins.txt` are edited by hand.
10. **Do not re-run Nemesis casually.** Output lands in place inside Wabbajack-managed mods. Any glue feature needing new animations/behaviour events should be avoided; use OStim scenes only.

---

## Open questions

1. Where will vanilla Papyrus sources, SkyUI SDK and MCM Helper SDK come from (user must install CK or approve fetching the SDK sources)? Nothing on disk satisfies this today.
2. Are `SmartTalk_QuestCache.json` IDs TopicInfo or Topic FormIDs? (Not stated in ini/log; check 2-3 IDs, e.g. Skyrim.esm 0x0E306E, in xEdit.)
3. OStim runtime health is unverified: no launch since install. Does `OStim.log` show all three packs' scenes, and does the 02:04 Nemesis output play OStim animations without T-pose?
4. `AIAgent.ini` is missing and no `AIAgent.log` was found although `AIAgent.dll` loaded at 01:09 - where does 3.3.2 write its log/config (Data\SKSE\Plugins via VFS -> would appear in `overwrite\skse\plugins`, which has none)?
5. The 01:04 startup crash (`std::invalid_argument: invalid stoull argument` in `InitTESThread`) - one-off or reproducible? Cause not identified (no module named in the first 45 lines).
6. Requiem version: meta.ini says 6.0.2.0 while the install archive is "Requiem 5.4.5 ... Bugfix Pack 5". Which is actually in `Requiem.esp`?
7. Expected light index of `OStim.esp` (FE:CF3) and full index of `OStimCommunityResource.esp` (0xB0) are computed, not observed.
8. The in-place Nemesis overwrite removed the pristine LoreRim behaviour output; is a Wabbajack re-verify/restore path acceptable to the user if behaviours misbehave?
9. Body/nudity setup (NeverNude CBBE variants) vs. OStim undressing expectations - cosmetic, but user-visible; not investigated further.
10. Interaction of BTPS / Dynamic Activation Key with any glue-side interception of NPC activation is untested.
