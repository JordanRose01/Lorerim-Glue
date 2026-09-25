# pt17 - the two dry-run switches, never-silent for dry run, quest talk by voice out of the box (investigator notes)

Playtest 2026-09-23 19:55-20:13 EDT (second session). Investigation only: no project file was edited. Every log line,
ini value and record decode below is DATA. Times are lorerim_glue.log (-04:00); chim.log runs at +02:00 (02:10 = 20:10).

## 1. What happened, line by line

### 1a. "none of the sex commands were working", "stop following me didn't work"

| when | source | fact |
|---|---|---|
| 19:57:15 | `F:\Modlists\LoreRim\overwrite\MCM\Settings\LoreRimGlue.ini.bak-20260923-201926` (27 bytes, UTF-8 BOM) | the WHOLE file MCM Helper had saved was `[General]` / `bDryRun = 1`. MCM Helper only writes values the player changed, so this is the only control the owner ever touched. He did NOT touch `bMenuless`, `bDlgDryRun` or `bCalibActive`. (The orchestrator reset it to `bDryRun = 0` at 20:19.) |
| config.json page 0 | `glue\game\LoreRimGlue\MCM\Config\LoreRimGlue\config.json` General page content[3]-[4] | header **"Testing"**, toggle `bDryRun:General` text **"Dry-run mode"**, help "Everything runs ... but nothing is executed". It is the FIRST dry-run switch anyone opening the MCM meets (page 0 of 12) and it ships OFF. The instruction "leave dry run on" + a switch that reads OFF = he switched it on. The switch that was meant is page 7 content[2] `bDlgDryRun:Dialogue` "Dry run (nothing is clicked)". |
| 20:05:14-18 | log:2928-2932 | `ExtCmdLRG_Clothing do=undress` -> `GAME DRY RUN WOULD Undress` -> `result ... Error: I cannot do that right now [why: dry-run mode, nothing changed]` -> **`voiced: no (a dry run changes nothing) - Clothing do=undress npc=Lisette is told on her next turn`** |
| 20:05:36, 20:06:00, 20:06:14 | :2944-2946, :2957-2960, :2973-2976 | same for the second undress and both `ExtCmdLRG_StartIntimacy` (OARE_SittingFellatio / OARE_MissionaryHugKiss) - `DRY RUN WOULD START`, `voiced: no` |
| 20:06:28, 20:08:55, 20:09:48 | :2987-2990, :3045-3048, :3078-3081 | `ExtCmdLRG_Escort do=release` x3 -> `escort Lisette do=Release: nothing changed (dry run)` -> `Error: Lisette cannot do that right now [why: the glue is in dry-run mode - nothing was changed]` -> `voiced: no` |

Recogniser and gates were right every time (intent=undress/npc, act/oralpenis, act/kiss, escort/release; offer_start=yes,
wit=0/0, door=shut). Seven refusals, zero spoken reasons.

**Where the silence is made:**
- game `LRG_Main.psc:1044` `IsDryRun()` = `SettingBool("bDryRun:General", false)`; refusal branches `LRG_OStim.psc:1901-1911` (start), `:3205-3208` (clothing), `:3439-3442` (control), `LRG_Main.psc:1697-1700` (escort), `LRG_Main.psc:2206`, `:2989`, `:3273` (hold / initiative / follower repair), `LRG_Dialogue.psc:1198-1201` (do=award).
- game `LRG_Main.psc:1437-1518` `SayReason()`: no branch for a dry-run reason -> falls to the last line `return "I cannot do that right now"` (1518): her words never name the reason. The corner note (`SendFuncret`, `:1429-1431`) prints the technical text only with `bNotifyErrors`.
- server `lib\lrg_actions.php:966` `lrgVoiceSkip()`: `if (str_contains($reason,'dry-run') || str_contains($reason,'dry run')) return 'a dry run changes nothing';` -> `lrgFuncretVerdict()` (:915-920) logs `voiced: no ...` and returns `'handled'`: CHIM's funcret follow-up turn (PROTOCOL 10.21) never runs. Even if it did, `lrgVoicedWhy()` (:1076-1080) maps any `dry-?run` reason to "it just would not work right now".
- `tools\test_gates.php:767-768` pins the silence: `check('a dry run is never voiced', $quiet === 'handled' && !isset($GLOBALS['LRG_VOICED']))`.

### 1b. Legion: Aldis / guards / Fafnyr answered in words; Rikke got nothing about the Legion

| when | source | fact |
|---|---|---|
| 20:07:40, 20:10:06, 20:11:14 | :3008, :3094, :3126 | Bjorskir / Aldis / Fafnyr: `dlg turn ... on=1 locked=2|3 faction=legion:redirect` + `dlg lock ... classes=faction,guard|quest` - the pt16 redirect worked. |
| every turn | e.g. :2914, :2979, :3093 | `dlg ml=0: menuless questing is off (or in dry run), so CHIM's own RentRoom ... are LEFT ON THE TABLE` and `mcm=...ml:0...` on every turn line - `ml` = `bMenuless && !bDlgDryRun` from `LRG_Profile.psc:585-587`; ini ships `bMenuless = 0`, `bDlgDryRun = 1` (`settings.ini` [Dialogue]). |
| 20:11:44 | :3134 | `dlg facts npc=Legate Rikke q=CWObj,CW00A,CWReservations,CW,CW00SolitudeMapTableScene` - PO3 reports CW00A on her at stage 0, AND the map-table scene's own quest. |
| 20:12:02, 20:12:29 | :3137-3138, :3143-3144 | `gate ... reasons=quest_scene,witnesses,not_close_enough` (intimacy lane, correct) and **`dlg turn npc=Legate Rikke ... scene=1 ... on=0 ... why=the speaker is in a scene`** - no `faction=` tag, `list=none`, no `dlg lock` line. |
| 20:12:49 | :3149 | `dlg facts npc=General Tullius q=CW00A,CWObj,CWReservations,CW,aaThalmor,MQ101 ... INDEX-STALE` - no turn was spoken to him. |

**Where Rikke is switched off:** `LRG_Profile.psc:447` `s += ";scene=" + B2I(akNpc.GetCurrentScene() != None)` (any scene at
all) -> `lib\lrg_dialogue.php:1522-1532` `$scene = snapshot scene==1 || session scene==1; if ($scene||$ostim) { $turn['on']=false; why[]='the speaker is in a scene'; list='none' }` -> `lib\lrg_factions.php:597` `lrgFacTurn()`: `if (empty($t['on']) ...) return [];` -> no role, no locked facts, no hand-off; `:1638-1640` locked facts also need `on`. Game side the same scene blocks an open: `LRG_Dialogue.psc:1127-1133` (`sSceneGate == 0` ships -> "a quest scene is running"), `:1530` `inScene = speaker.GetCurrentScene() != None`, and `lrgDlgOnTalk()` drops `lrg_dlgtalk` for a scene (`lrg_dialogue.php:1144-1147`).

The escort module already has the precedent for telling scenes apart: `LRG_Main.psc:1968-1985` `EscortSceneVerdict()` reads
`sc.GetOwningQuest().GetID()`, denies `ESCORT_DENY` (`:55`, includes `CW*`), denies any quest with a displayed unfinished
objective (`EscortHasJournal`, `:1990-2008`), and allows `safe_quests` globs (`config\lrg_config.default.json:92`
`["BardSongs","BardSongsInstrumental","*Idle*","*Sandbox*","WI*"]`) through `EscortMatchAny` / `EscortMatch` (`:2011-2075`).

### 1c. The prompt index was STALE all session - already fixed in source, not deployed

`lib\lrg_prompt_index.php:725-736` (source, "[pt17 / orchestrator]") normalises CRLF before the md5 exactly as
`tools\build_prompt_index.py:286` does. Deployed copy md5 `d4eda427...` != source `13f89841...` (the other four lib files and
the config match). The 20:23 rebuild wrote header hash `e756e311` again (`data/.prompt_index_v1`, index header) - i.e. the load
order never changed; the "live 01414bfe" was the CRLF bug. Deploying the source file ends the INDEX-STALE lines. `.index_checked` = `fresh` (20:18).

### 1d. Aldis silent at 20:10:31 (not this lane; for the record)

chim.log 02:10:31-33 +02:00 (lines 17894-17943): `Scoped CACHE_PEOPLE ... |Captain Aldis|...`, prompt composed (28,062
chars), `openrouterjson: Prepared command payload for Talk` / **`Returning command buffer with 0 commands`**, lock released -
no reply text is logged at this level and `responselog` is empty on this install (pt16 §1). The glue side saw `llm ...
action=none item="" spoke=0` (:3116). The raw model output has to come from the connector's own log / CHIM debug level.

## 2. The calibration gate - why "turn it on" could never have finished by itself

`LRG_DlgProbe.psc:2856-2913` `CalMissing()` - TEN rows must be answered before `LRG_Dialogue.ReadCalibration()` (`:685-701`)
stops forcing `sDryRun = true`:

| row | key | written by | file:line |
|---|---|---|---|
| counting | cm | passive | CalPoll `:1812` |
| reading | rm | passive (3) / active A4 (4) | `:1846/:1876`, `:2575` |
| progress timer | timer | passive at close, or the click test | CalClose `:2130-2132`, `:2732` |
| hide round trip | hide | ACTIVE A1 (bCalibActive) | `:2503-2510` |
| input guard | guard | **MANUAL click test only** | CalPressClickBody `:2670` |
| click route | route | **MANUAL click test only** (one real click on the LAST entry) | `:2725` |
| state read-back | rb | ACTIVE A2 | `:2393` |
| vanilla menu after a hide | apd + reopen | apd passive `:1722`; reopen **MANUAL press 35 only** | `:2802`, `:2823` |
| Smart Talk settings | st | passive | `:1741` |
| menu layout | fam | passive | `:1726` |

Tonight after ONE engine dialogue (20:09:38-41, :3052-3064): `k=rm:3,cm:1,fam:1,apd:750,st:1` answered; missing
`progress timer, hide round trip, input guard, click route, state read-back, vanilla menu after a hide`. So: even with
`bMenuless = 1` and `bCalibActive = 1`, three rows can only be answered by the Calibration page's buttons 1 and 2
(`LRG_MCM.psc:72-102`) or by the auto-armed one-shots those buttons set (`CalShotArm` `:306-320`, fired by `CalShotFire`
`:264-305` on the next Dialogue Menu, with `ClickAllowed` `:1331-1341` refusing guards / scenes / bounties). Nothing arms them
by itself. `bCalibOverride` is the only other way out (`ReadCalibration :687`).

Two more scars found on the way:
- `LRG_Profile.psc:585-587` computes `ml` from the RAW MCM values (`bMenuless && !bDlgDryRun`), while the driver's effective
  state is `sDryRun` after the forced gate (`LRG_Dialogue.psc:687-688`). `SendFacts` (`:3371-3374`) uses the effective one.
  So an owner who ticks bMenuless and unticks bDlgDryRun while the gate is red sends `ml=1` - the server then hides CHIM's
  RentRoom / OpenInventory (`lrg_dialogue.php:1701-1706`) and the game answers WOULD CLICK. Not hit tonight; will be hit the
  first evening after the defaults change unless `ml` reads the effective state.
- `HandBack("dryrun")` (`LRG_Dialogue.psc:2354-2363`, `:2770-2812`) shows the generic "choose this one by hand" note and sends
  `Error: dry-run mode, nothing was clicked` (`ReportOnce` `:3926`) - which the server then silences as a dry run (1a).

## 3. The enlistment path on THIS save (Alternate Perspective start, MQ101 stage 0, CW00A stage 0) - decoded from the plugins

Read-only decodes (scratchpad `ap_scan.py`, `esm_scan2.py`) of `AlternatePerspective.esp`, `Skyrim.esm` (Stock Game) and USSEP.
FormIDs are load-order-independent low 24 bits; quest names from Skyrim.esm QUST: `0D3C5F = CW00A`, `3372B = MQ101`,
`2BF9C = MQ102A`, `19E53 = CW`; GLOB `4679E = MQQuickstart` (0); DLBR `0D5178 = CW00TulliusForcegreet`, flags 2 = **blocking**.

Tullius greet DIAL `0D5116` "Hello." (3 INFOs, all won by AlternatePerspective.esp):
- `0C348B` "I remember you, you were at Helgen. Speak to Legate Rikke..." - `GetQuestCompleted(MQ101)==1`, CW00A stage 10 not done, `CW::PlayerGotIntro_var==2`, GetIsID. **Closed** (MQ101 not complete).
- `0D5145` "Are my men now giving free rein..." - `GetQuestCompleted(MQ101)==1`, PlayerGotIntro_var==0, stage 20 not done, GetIsID. **Closed**.
- `0D5146` "Hmm. There something I can do for you? Perhaps direct you to the nearest prison..." - `MQQuickstart < 7`, PlayerGotIntro_var==0, stage 20 not done, stage 10 not done, GetIsID. **CLOSED on this save (pt18-quest correction): AlternatePerspective.esp adds GetGlobalValue(MQQuickstart) < 7 to this INFO (USSEP's 0D5146 has no such condition) and overrides GLOB 0004679E to 7.0; the only writer in the load order is AP's QF_MQ101_0003372B_new (6.0 on its Helgen path; MQ101 at stage 0 here); Save15/Save16 GLOB decode = 7.0. The FreeToGo line 0D5150 -> 0D5136 is therefore unreachable until Helgen; the post-Helgen road is 0D5145 -> 0D5113 -> 05206780 -> 05206784 / alternateperspective.esp:206783 (SetStage 10).** Links: `0D5110` CW00TulliusGreetHadvar, `0D510F` CW00TulliusGreetFreeToGo, `04A20B` MQ302TulliusIntroTopic.
- `0D5110` "I helped Hadvar escape. He said he'd vouch for me." INFO `0D514B` (Skyrim.esm): GetIsID AND `GetStageDone(MQ102A,10)==1`. **Closed** (no Helgen on this save).
- **`0D510F` "I was set free. I could've gone anywhere. I came here to fight for the Empire." INFO `0D5150` (winner USSEP): the ONLY condition is `GetIsID(Tullius)`. OPEN.** Its link `0D5111` CW00TulliusGreetTalkRikke, INFO `0D5136` (GetIsID only, **VMAD script fragment present, 69 bytes**, goodbye flag) = "Speak to Legate Rikke..." and the fragment that sets CW00A stage 10.
- AP's own `05206781` CW00TulliusAP01 "I've helped Hadvar escape. He said I should look for you." INFO `05206782`: GetIsID AND `GetStageDone(MQ102A,10)==1` -> `05206784` CW00TulliusGreetTalkRikkateAP "I don't want to sit idly by ... I want to join the Legion!" (INFO `05206783`, GetIsID only). It sits in the same blocking branch but **no INFO in the load order links to DIAL 206781** (index: only its own row mentions it; AP's winning greet overrides link 0D5110/0D510F/04A20B). Unreachable here, and closed anyway (MQ102A).

Rikke DIAL `0D510E` CW00RikkeBlockingTopic: `0D514D` (stage 10 not done, stage 20 not done - the pre-Tullius greet), `0D514E`
"You survived Helgen?..." (stage 10 DONE, 20 not) -> `0D510D/0D510B/0D510C`; **`0D514F` "About that test..." (USSEP): `GetStage(CW00A) < 20` AND `GetStageDone(CW00A,10)==1`** -> `0D510A` "What's at Fort Hraggstad?" -> `0D5106` "Consider that fort already yours." (scripted -> CW01).

So on this save: **Tullius, entry "I was set free. I could've gone anywhere. I came here to fight for the Empire." (one click after his greet) is what opens CW00A (stage 10); Rikke's "About that test..." only exists after that.** `lib\lrg_factions.php:90-91` names the AP line for Tullius and `:96` lists `CW00TulliusGreetTalkRikkateAP` first - the wrong anchor for this save; `:99-100` `entries` lacks the FreeToGo norm; `:59` `entry_words` has no "fight", so the topic-glob path (`lrgFacArbitrate :810-815`, `lrgFacNormHasEntryWord`) would not accept the FreeToGo entry even with its topic added - the exact-line (`byNorm`) path must carry it.

## 4. Root causes (short)

1. Two toggles named "Dry-run mode" / "Dry run (nothing is clicked)", the global one first and OFF by default -> the owner switched the wrong one ON.
2. The global dry run is a designed silence on both sides (`lrgVoiceSkip :966`, `SayReason :1518`, `test_gates :768`), against owner addendum 11.
3. Menuless questing ships inert (`bMenuless=0`, `bDlgDryRun=1`, `bCalibActive=0`) and its gate needs two button presses nobody pressed; nothing tells the player "still learning".
4. Any scene at all switches the dialogue module off for an NPC (`lrg_dialogue.php:1528`), so people who LIVE in a looping ambient scene (Rikke, Tullius at the map table) never get faction facts or a hand-off.
5. The Legion row anchors Tullius on an AP line that is unreachable on this save.

## 5. The smallest complete fix (files named; nothing edited by me)

### A. The switches (MCM + game + server + tests)
- `game\LoreRimGlue\MCM\Config\LoreRimGlue\config.json`: remove General content[3] ("Testing") and [4]; append to the **Diagnostics** page: header "Developer switch - leave this OFF for play", toggle `bDryRun:General` text "DEV: dry-run everything (nothing NPCs do will happen)", help naming what it kills (undress, scenes, follow/wait/release, clicks, services), that NPCs will say it is on and a message box will say so on load, and that the menuless dry run is the OTHER switch on the Menuless questing page. Relabel `bDlgDryRun:Dialogue` to "Menuless questing: dry run (nothing is clicked)" and add the cross-reference sentence to its help. Same id -> `settings.ini` and MCM Helper's saved ini stay valid.
- `LRG_MCM.psc:18-44` `OnSettingChange`: if `a_ID == "bDryRun:General"` and the new value is true -> `Debug.MessageBox(...)` naming the Diagnostics page and the other switch; call `m.LogRefresh()`.
- `LRG_Main.psc`: cache `devDry` in `LogRefresh()` (`:1182-1190`, read every heartbeat `:752`); boot step 11 (`:717-725`) -> `Debug.MessageBox` + `LogE` when on; every 4th heartbeat (`:757`) -> `Debug.Notification("LoreRim Glue: DEV dry-run is ON (Diagnostics) - nothing NPCs try will happen")`; `SayReason()` (`:1437-1518`) new branch for any reason containing "dry-run"/"dry run" -> "I cannot - the LoreRim Glue dry-run switch is on". Technical texts at `LRG_OStim.psc:1910, :3207, :3441` and `LRG_Main.psc:1699` -> "dry-run mode is ON in the LoreRim Glue MCM (Diagnostics page) - nothing was started/changed" (the corner note `:1430` then names the page).
- `lib\lrg_actions.php`: delete the skip at `:966` (or `voice.dry_run` config, default voiced); `lrgVoicedWhy()` (`:1076-1080`) maps `dry-?run` to "the LoreRim Glue developer dry-run switch is on in its MCM (Diagnostics page), so nothing it is asked to do can happen until it is off"; `lrgVoicedDirective()` (`:1090-1096`) drops "never a word about the game" for that one reason.
- Keep `bDryRun` (eight readers, flow strings depend on it) but loud, as above.
- Tests: `tools\test_mcm_wiring.php` new section: `bDryRun:General` on the page titled Diagnostics, text starts "DEV", help mentions the other switch; `bDlgDryRun` text starts "Menuless questing"; no two toggles share a text. `tools\test_gates.php:767-768` inverted: a dry run IS voiced and the fact names the switch. `tools\flows\scenarios\29_never_silent.php`: one more `fxFuncretTurn` with a dry-run error -> `mode()==='voiced'`, cue contains "dry-run switch".

### B. Quest talk by voice out of the box (game + server + config + tests)
- Defaults: `settings.ini` `[Dialogue] bMenuless = 1` (keep `bDlgDryRun = 1`), `[Calib] bCalibActive = 1`, new `[Dialogue] bDlgDryRunHold = 0`; in-code defaults to match: `LRG_Dialogue.psc:537` (`bMenuless` -> true), `LRG_Profile.psc:585`; MCM help of `bMenuless` ("Off (the default)") and README page table.
- `LRG_Profile.psc:585-588`: `ml` from the driver's EFFECTIVE state (`m.GetDialogue()` -> new `LRG_Dialogue.MenulessLive()` = `sMenuless && !sDryRun` after `ReadSettings()`; raw MCM only when the module is not booted) and a new additive key `cal=<CalAnswered 0-10>`; `SendFacts` (`LRG_Dialogue.psc:3373`) carries `cal=` too.
- `LRG_DlgProbe.psc`: `CalAutoArm()` called from `Maintenance()` after `CalArmBudget` (`:182`), from the end of `CalShotFire` (`:264-305`) and from `CalClose`: with `bCalibActive` on, `!calOff`, no shot pending: `route==0` -> `CalShotArm(1, "learning the dialogue menu - the next talk with an innkeeper or merchant runs the click test")`; then `reopen!=1` -> `CalShotArm(3, ...)`. The AUTO-armed click (not the buttons) additionally requires the last root entry's text to be on a harmless whitelist (never mind / goodbye / rent a room / what have you got for sale / let me see what you have / rumours) - otherwise skip and wait, logged `CALIB active A-click skipped=last-entry-unknown`; `ClickAllowed` rails unchanged. Passive `reopen`: in `CalClose` (`:2107`), `hide==1 && abAnyClick && !cArmHidden && visible` -> `CalSet("reopen",1,"passive")` (R1's proof without the second press).
- `LRG_Dialogue.psc:685-701` `ReadCalibration()`: when `calGreen && sDryRun && !sCalibOverride && !SettingBool("bDlgDryRunHold:Dialogue")` and the MCM still says 1 -> `MCM.SetModSettingBool(ModName, "bDlgDryRun:Dialogue", false)` once (declared in `tools\stubs\MCM.psc:11`), `Debug.Notification("LoreRim Glue: the dialogue menu is learned - quest talk by voice is ON")`, log `dlg dry-run auto-cleared`. `HandBack("dryrun")` note (`:2803`) and the three "dry-run mode, nothing was clicked" results (`:998, :1201, :2360`) -> "still learning the dialogue menu (N of 10) - nothing was clicked".
- Server: `lrgDlgMcmFromSnapshot()` (`lrg_dialogue.php:472-486`) reads `cal` (0-10); `lrgFacLockedLines()` ml=0 branch (`lrg_factions.php:678-681`) and `lrgDlgServiceRequestBlock()` (`:3915-3935`) say "the glue is still learning this install's dialogue menu (N of 10) - tell him plainly you cannot take that up by voice yet, the menu still works; set no time or place"; `lrgVoicedWhy()` maps "still learning" to plain words.
- Ambient scenes: game `LRG_Profile.psc:447` adds `;sq=<scene owning quest EditorID>;sqj=<0|1>` (via a new `LRG_Main.SceneQuestOf()` reusing `GetOwningQuest().GetID()` and `EscortHasJournal`, `:1782-1786`, `:1990-2008`); `LRG_Dialogue.psc` `OpenRefusal` `:1127`, session `:1530`, and `SendFacts` use `SceneIsAmbient()` = owner known AND `!EscortHasJournal(owner)` AND `m.EscortMatchAny(qid, AMBIENT_SCENES)` with `AMBIENT_SCENES = "*MapTableScene*,BardSongs*,*Idle*,*Sandbox*,WI*"` (allow-list; the escort's own lists untouched). Server `lrgDlgOnFacts()` (`:1067-1122`) stores `sq/sqj`; `lrgDlgPrepareTurn()` (`:1528`) and `lrgDlgOnTalk()` (`:1144`) treat `sqj==0 && sq matches dialogue.scenes.ambient && !lrgDlgQuestKnown(sq)` as talkable (`why[]="ambient scene <sq> - talkable"`); config `dialogue.scenes.ambient` defaults in `lrgDlgDefaults()` (`:60-80`) and `lrg_config.default.json`. The intimacy gate's `quest_scene` (`lrg_core.php:1421`) is untouched.
- Legion truth: `lrg_factions.php:90-115` `recruiters['General Tullius']` = "I was set free. I could've gone anywhere. I came here to fight for the Empire."; `entries` += that norm and "i helped hadvar escape he said he'd vouch for me"; `recruiter_topics` += `CW00TulliusGreetFreeToGo`, `CW00TulliusGreetHadvar` (keep the AP topic as an alternate); `verified_from` updated with the decodes above; per-name `before` text for Rikke ("only after General Tullius has sent him to you - the entry 'About that test...' is not open before that") used by `lrgFacLockedLines()` when the recruiter entry is not on her list.
- `LRG_Main.psc:36` `CurrentVersion` 508 -> 509.
- Tests: `tools\test_dialogue.php` section 13: Rikke with snapshot `scene=1 sq=CW00SolitudeMapTableScene sqj=0` -> `on=1`, `faction=legion:recruiter`, locked line carries the "not open before Tullius" text; `scene=1 sqj=1` or `sq=MQ101` -> `on=0` (unchanged); Tullius pool with the FreeToGo entry -> exact-line hand-off; `cal=4` under ml=0 -> "still learning (4 of 10)". `tools\test_prompt_index.php` 5c: `CW00TulliusGreetFreeToGo` exists, winner USSEP, `nconds==1`. `tools\test_mcm_wiring.php`: the relabels (A) and the new `bDlgDryRunHold` line + reader. A flow (`d6x_out_of_the_box.php`): `settings.ini` parsed -> `bMenuless=1`, `bCalibActive=1`, `bDryRun=0`; the `ml`/`cal` snapshot keys are read back.
- Deploy: `lib\lrg_prompt_index.php` (source already fixed) must go out with this round.

## 6. Owner steps (game closed), risks, open questions - see the structured report.

## 7. IMPLEMENTER (lane "switches", 2026-09-23 evening) - what was changed and why

Backups of every edited file: `glue\.backup\pt17-switches\<basename>.bak`. Nothing compiled, deployed or installed
here (the Build stage does). Script version bumped 508 -> 509. Files outside this lane's ownership are NOT edited;
their exact patches are in the structured report (`papyrus_patch_for_orchestrator`).

### 7a. PART A - the developer dry run is loud and never silent (done)
- `config.json`: the General page lost its "Testing" header and `bDryRun:General`; the same id now sits as the LAST
  control of the LAST page (Diagnostics) under "Developer switch - leave this OFF for play", text
  "DEV: dry-run everything (nothing NPCs do will happen)"; its help lists what it refuses, says NPCs will SAY it, and
  names the other switch. `bDlgDryRun:Dialogue` relabelled "Menuless questing: dry run (nothing is clicked)" with the
  reverse cross-reference and the auto-clear explained. Same ids: settings.ini and MCM Helper's saved ini stay valid.
- `LRG_MCM.psc` OnSettingChange: `bDryRun:General` ticked ON -> Debug.MessageBox naming the page + LogE; OFF -> LogC;
  both -> LRG_Main.LogRefresh() so the cache is current at once.
- `LRG_Main.psc`: `devDry` cached in LogRefresh() (boot step 11 + every 30 s heartbeat); boot step 11 -> MessageBox +
  LogE when on (never on built-in defaults: SettingBool answers false), a "dev dry run off" log line when off; every
  4th heartbeat (2 min) -> corner note DevDryNote(); the "maintenance done" line carries `devdry <0|1>`; SayReason()
  gained three branches BEFORE the fallback: "still learning the dialogue menu" (in-world), "menuless dry run"
  (in-world), any "dry-run" / "dry run" -> "I cannot - the LoreRim Glue dry-run switch is on in its settings, on the
  Diagnostics page" (out of character on purpose). CmdEscort's dry-run branch passes asSay "" so SayReason answers
  (the explicit asSay used to bypass it - refuter 2 (b)) and its technical text names the page.
- `lib/lrg_actions.php`: the dry-run skip in lrgVoiceSkip() is gone (config `voice.dry_run_voiced`, default true;
  false brings the old silence back); new lrgVoicedDryKind() / lrgVoicedDryWhy() cover EVERY wording the game has
  ever sent ("dry-run mode, nothing changed" / "... nothing was started" / "the glue is in dry-run mode - nothing was
  changed" / 509's page-naming text) plus the two menuless texts; lrgVoicedWhy() names the switch and the page before
  the technical map would hide it; lrgVoicedDirective() replaces "never a word about the game" with "say plainly that
  the LoreRim Glue dry-run switch is on ... on the Diagnostics page" for the dev kind only; the next-turn fallback
  (`$turn['fail']`) uses the same words when a dry-run refusal was NOT voiced (the 8 s gap, a dropped voiced turn, an
  old script's "cannot do that right now") - refuter 1's must-change.
- Tests: test_gates.php's "a dry run is never voiced" inverted (four wordings, the cue, the gap fallback); flow 29
  gained two dry-run funcrets; flow 30's "507 shape: NOT voiced" inverted; test_mcm_wiring.php section 7 (the page,
  the DEV label, the cross-references, no two toggles share a label, the shipped defaults, the auto-clear exists).

### 7b. PART B - quest talk by voice out of the box (done where the lane owns the file)
- Defaults: settings.ini `[Dialogue] bMenuless = 1`, `bDlgDryRun = 1`, new `bDlgDryRunHold = 0`; `[Calib]
  bCalibActive = 1` (the hide round trip and the state read-back are written ONLY by its tests - refuter 1: load-
  bearing). The in-code default of bMenuless in LRG_Dialogue.psc is true (rule: the in-code default equals the ini
  line). config.json: the bMenuless help rewritten ("On (the default) ... only goes live once ... green"), a new toggle
  "Keep the menuless dry run even after learning" on the Calibration page.
- `LRG_Dialogue.psc` ReadCalibration() (e0) THE AUTO-CLEAR: green, sDryRun, no hold, no override, sMenuless,
  SettingsLive -> while iKeyVanillaMenu is 0: log + corner note "bind 'Open vanilla dialogue (emergency)'" once
  (refuter 2 (d): bMenuless=1 never goes live without the escape key); else MCM.SetModSettingBool(bDlgDryRun:Dialogue,
  false), sDryRun released in memory, a StorageUtil marker `LRG.dlg.autoclr` so it fires ONCE per green (dropped
  whenever the gate is red again, i.e. after "forget everything it learned"), and if the MCM write did not take,
  `calAutoTried` keeps the in-memory release for the session ("play never depends on the write"). The forced-dry-run
  log line and corner note now say "still learning (N of 10)".
- New public functions: MenulessLive() (the effective ml, for LRG_Profile - patch), CalAnsweredNow(), DlgDryReason()
  (the three WOULD CLICK / WOULD AWARD results say "still learning the dialogue menu (N of 10) - nothing was clicked"
  when FORCED and "the menuless dry run is on (Menuless questing page) - nothing was clicked" when it is the owner's own
  switch - neither contains "dry-run", so the server never claims them as the developer switch), SceneQuestOf(),
  SceneIsAmbient() (allow-list `*MapTableScene*,BardSongs*,*Idle*,*Sandbox*,WI*` judged on the scene's OWNING quest
  EditorID, never with a displayed unfinished objective, through LRG_Main.EscortHasJournal / EscortMatchAny).
  HandBack("dryrun")'s note says why the menu is his. The self-test line carries hold= devdry= vkey= cal=.
- The facts line (ev=facts) gained `cal=<0-10>;sq=<owning quest EditorID|->;sqj=<0|1>;qst=<quest>:<stage>,...` (ONE
  po3 sweep, reused). The game-side open refusal on a scene actor and D-17 are UNCHANGED (refuter 2 (e), open
  question 5).
- `lib/lrg_dialogue.php`: lrgDlgFactsFrom parses cal (also the global MCM tier) / sq / sq_at / sqj / qst; the facts log
  line prints them; lrgDlgMcmFromSnapshot ANDs the snapshot's raw ml with the driver's fresh `dlg=` (the effective ml
  closes the LRG_Profile mismatch server-side; `ml_forced`); lrgDlgLearningText(); lrgDlgSceneAmbient() (fresh sq,
  sqj=0, a glob on dialogue.scenes.ambient, not a journal-known quest); lrgDlgPrepareTurn keeps on=1 with `ambient=1`
  for such a scene (a session scene, OStim and an open session still switch off); lrgDlgMaybeOpen refuses the open on
  an ambient turn ("words and facts only"); the ml=0 log line says "still learning (N of 10)"; the turn line prints
  ` ambient=1`.
- `lrg_config.default.json`: `voice.dry_run_voiced`, `dialogue.scenes.{ambient,fresh_seconds}` (with readmes).
- Tests: test_dialogue.php section 14 (the wire, the verdict, an ambient recruiter turn keeps her role and locked line
  and never opens, the learning words, the effective ml); flow d63_out_of_the_box.php (the shipped defaults,
  config.json placement / BOM / LF, the ml / cal wire, the effective ml, Rikke end-to-end with the three "still off"
  cases, a voiced dry run).

### 7c. Deliberately NOT done (owner / orchestrator decisions, or another lane's file)
- No automatic CLICK (CAL-1 stays): the input guard and click route rows remain the Calibration page's button 1.
  With bCalibActive=1 and the passive reopen (LRG_DlgProbe patch) 8 of 10 rows self-complete; the status line, the NPC
  and the log say "still learning (N of 10)" until the owner presses button 1 once at an innkeeper. Refuter 2 (c)
  showed the proposed auto-click was unsafe as specified (a rent-room last entry spends gold; guard=1 is vacuous with
  nobody mashing).
- The game-side open on an ambient scene stays refused (whether the map-table scene pauses cleanly is unknown).
- LRG_OStim / LRG_Profile / LRG_DlgProbe / lrg_factions / test_prompt_index / README / PROTOCOL: patches in the report.

### 7d. Tests run (WSL, staged copy C:\Users\Jordan\AppData\Local\Temp\lrg_test\switches\glue + the deployed data/)
php -l clean on every changed .php; both JSON files valid (config.json: no BOM, LF); test_mcm_wiring 13/0 (+1 note:
LRG_Profile still reads bMenuless with the default false); test_gates 470/0; test_dialogue 355/0; test_phrases 47/0;
test_intent 316/0; test_services 24/0 (deployed census); the full flow suite: 79 scenarios all PASS after flow 30's
inversion (1373 checks, 0 warnings).
