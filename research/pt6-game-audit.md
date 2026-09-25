# Playtest 6 - GAME-SIDE CONTROL AUDIT (read-only)

Scope: `LRG_OStim.psc` (2517 lines, mtime 13:22), `LRG_Main.psc` (752 lines), `LRG_MCM.psc`,
`LRG.psc`, `LRG_PlayerAlias.psc`, `MCM/Config/LoreRimGlue/config.json` + `settings.ini`,
`glue/PROTOCOL.md`, the installed OStim 7.5.1 sources + `OStim.esp` globals + `OStim.dll` strings,
the installed CHIM 3.3.2 Papyrus sources + `AIAgent.dll` strings, and two logs from playtest 6:
`C:\Users\Jordan\Documents\My Games\Skyrim Special Edition\SKSE\OStim.log` (owner's local clock)
and `/var/www/html/HerikaServer/log/lorerim_glue.log` (glue clock = local + 6 h).

Nothing was modified. All paths below are absolute; script line numbers are of the files on disk
today under
`C:\Users\Jordan\AppData\Roaming\Claude\scratch-workspaces\81ce68fa-07b1-4b77-bf43-737182b06d3c\6e558c34-6aca-475c-b527-d967c1b190e9\scratch-2026-09-21-8ca12a\glue\game\LoreRimGlue\Source\Scripts\`.

## 0. Owner addenda handled here
`glue/OWNER_ADDENDA.md` addendum 2 (revised R10: "if she initiates any new scene/animation she
should say something signifying it") is a GAME-side sequencing problem as much as a server one.
Section 6 gives the game-side mechanism (a `wait=` hold on the command) and what it needs on the
wire; section 2.4 gives the measured facts about CHIM's speech-vs-command ordering that the design
must build on. Addendum 1 (G3a) is the same mechanism with a longer budget.

---

## 1. NEW HARD EVIDENCE FROM THE TWO LOGS (all of it reproducible)

`OStim.log` was not previously in evidence. It pins the OStim side of playtest 6 exactly:

```
[13:25:14] Registered OStimAlignMenu / OstimSceneMenu / OStimSearchMenu
[13:25:14] loading config -> no config file found
[13:27:13] path (Data/SKSE/Plugins/OStim/settings) does not exist
[13:29:12.991] starting scene            <- glue 19:29:12
[13:29:13.703] thread 0 changed to node OARE_StandingEmbraceKiss
[13:30:58.224] -> OARE_GropingButtKiss
[13:31:48.375] -> OARE_StandingEmbraceKiss   (transition)
[13:31:50.988] -> OARE_OutstretchedArmsKiss  (transition)
[13:31:51.498] -> OARE_GropingButtKissOA
[13:32:52.058] -> OARE_OutstretchedArmsKiss  (transition)
[13:32:52.552] -> OARE_StandingEmbraceKiss
[13:33:43.962] deserializing data / closing old threads: 0     <- THE RELOAD
[13:35:53.732] starting scene
[13:36:43.505] -> OARE_GropingButtKiss
[13:37:04.835] serializing data
[13:38:27.039] -> OARE_RearSexFPerformer                       <- then the crash
```

And the glue log for the same window (excerpt, glue clock):

```
19:29:10 gate StartIntimacy -> ok=1;...;scene=OARE_StandingEmbraceKiss
19:29:10 GAME command ExtCmdLRG_StartIntimacy ... via Bridge     (<1 s after the gate)
19:29:12 GAME prep player rounds=3 ... / prep Lisette rounds=3
19:29:13 GAME start requested tid=0
19:29:14 scene Start ... leader=NPC
19:29:21 scene Change ...
19:30:06 GAME lead request       -> 19:30:08 gate goto GropingButtKiss  ** NO "GAME command" LINE **
19:30:55 GAME lead request       -> 19:30:57 gate + command -> 19:30:58 navigate
19:31:45 GAME lead request       -> 19:31:48 navigate -> GropingButtKissOA
19:32:45 GAME lead request       -> 19:32:50 turn (5 s in the queue) -> 19:32:52 navigate -> back to StandingEmbraceKiss
19:33:47 GAME maintenance done, version 200, session 6403        <- the reload, NOTHING else sent
19:33:54 turn ... in_scene=OARE_StandingEmbraceKiss speech       <- server still thinks a scene runs
19:33:57 result ... -> Error: no scene is running
17:34:04 scene row for Lisette closed: a newer snapshot says the scene is over   <- UTC-stamped line
19:36:10 turn ... in_scene=... speech        ** no gate line at all: the LLM chose NO action **
19:36:37 turn ... speech -> 19:36:42 gate goto -> 19:36:43 navigate
19:37:56 GAME lead request -> 19:37:58 do=faster
19:38:18 turn ... speech -> 19:38:25 gate goto RearSexFPerformer warp=1 -> 19:38:26 GAME warp ->
```

Five facts follow directly and are used throughout this report.

**F1 - the player's voice was never blocked.** Speech turns arrived at 19:33:54, 19:36:10,
19:36:37 and 19:38:18, i.e. *during a running OStim scene*. CHIM's push-to-talk path works while a
scene runs. "Voice commands weren't working" was never an input problem.

**F2 - the "get naked" turn produced no action at all.** 19:36:10 has a `turn` line and no `gate:`
line. `CmdClothing` was never called, never refused anything. This is an LLM/offer problem, not a
game problem (section 4 nevertheless lists every way `CmdClothing` *can* fail, as asked).

**F3 - the lead loop is a closed cycle.** lead tick -> she calls goto -> the scene change resets
`lastActivity` -> 45 s later the next lead tick. Measured gaps 45 s, 49 s, 41 s, 60 s. The player
speaking is not part of that cycle at all (section 3).

**F4 - one lead action was lost between server and game.** cid `sa6f90c7e` passed the gate at
19:30:08 and no `GAME command` line for it ever appears; the next lead tick at 19:30:55 re-did the
same move. So on that turn CHIM never delivered the command. Section 3.4.

**F5 - the command reaches the game 0-1 s after the server emits it**, every single time
(19:30:57->57, 19:31:47->48, 19:32:51->51, 19:36:42->43, 19:37:58->58, 19:38:25->26). That is
*response-processing time*, long before her TTS audio has been fetched and played. Section 6.

---

## 2. Q2 - "PUT IN DIRECTOR MODE ... NO CONTROL": WHAT OSTIM 7.5.1 DOES TO THE PLAYER

### 2.1 The single most likely cause: OStim's free camera on start

The installed `OStim.esp` GLOB defaults (read directly out of the plugin,
`F:\Modlists\LoreRim\mods\OStim Standalone - Advanced Adult Animation Framework\OStim.esp`):

| formid | EditorID | ships as | MCM label (Interface/translations/OStim_ENGLISH.txt) |
|---|---|---|---|
| `0x000DDE` | `OStimUseFreeCam` | **1** | Camera > Free Cam > **"Switch to free cam mode on start"** |
| `0x000DE0` | `OStimFreeCamFOV` | 45 | Free cam FOV |
| `0x000DDF` | `OStimFreeCamSpeed` | 3 | Camera speed |
| `0x000DEC` | `OStimKeyFreeCam` | 181 (numpad `/`) | Controls > "Toggle free cam" |
| `0x000E19` | `OStimUseFades` | **1** | General > **"Fade out on intro/outro"** |
| `0x000DA1` | `OStimUseIntroScenes` | 1 | General > "Use designated intro scenes" |
| `0x000E12` | `OStimForceFirstPersonOnEnd` | 0 | Camera > "Force return to first person after scene" |
| `0x000E1C..0x000E20` | `OStimUseAutoModeAlways/Solo/Dominant/Submissive/Vanilla` | **all 0** | Auto Control > Auto Mode Toggle |
| `0x000E43` | `OStimNavigationDistanceMax` | 5 | Auto Control > "Max navigation distance" |
| `0x000DEB` | `OStimKeyAutoMode` | **82** (numpad 0) | Controls > **"Switch control mode"** |
| `0x000E2D` | `OStimKeyEnd` | 83 (numpad `.`) | Navigation Keys > End |
| `0x000E28..0x000E2E` | `OStimKeyUp/Down/Left/Right/Yes/Toggle` | 72/76/75/77/71/73 (numpad 8/5/4/6/7/9) | Navigation Keys |
| `0x000E41` / `0x000DE2` | `OStimKeySearch` / `OStimKeyAlignment` | 37 (`K`) / 38 (`L`) | Search menu / Alignment menu |
| `0x000DAB` | `OStimRemoveWeaponsAtStart` | 1 (glue forces 0) | Undressing |
| `0x000DAC` / `0x000DAD` | `OStimUndressMidScene` / `OStimPartialUndressing` | 1 / 1 (glue holds at 0 per scene) | Undressing |
| `0x000DF9` | `OStimEndOnMaleOrgasm` | 1 | Orgasms > "End scene after male orgasm" |

`OStim.log` line 8 says `loading config -> no config file found` and line 26 says
`path (Data/SKSE/Plugins/OStim/settings) does not exist`; there is no exported MCM settings JSON
anywhere under `F:\Modlists\LoreRim`. So unless the owner changed it by hand in OStim's MCM, the
live values are these plugin defaults - i.e. **free cam on start is ON**.

`OStim.dll` contains the symbols `SetUseFreeCam`, `SetFreeCamFOV`, `SetFreeCamToggleKey` and the
Skyrim INI key string `fFreeCameraTranslationSpeed:Camera`: OStim's free cam is the *game's* free
camera. At scene start the player's character control is gone and the mouse/WASD fly a camera.
That is, almost word for word, "it seemed to put me in director mode ... i had really no control".
The escape hatch is numpad `/` (`OStimKeyFreeCam` = 181).

`OStimUseFades = 1` is the "makeout animation cutscene": OStim fades to black on intro and outro.

### 2.2 What it is NOT
- **Not OStim auto mode.** The glue logs every auto-mode intervention
  (`LRG_OStim.psc:2029-2032` at start, `LRG_OStim.psc:2272-2275` on the 5 s tick). The glue log for
  playtest 6 contains no "auto mode" line at all, and the `lrg_scene` pushes all report
  `leader=NPC`, never `leader=auto`. Auto mode was off the whole time, as the ESP defaults predict.
- **Not blocked input.** F1.
- **Not `OPlayerThread.SetPlayerControl`.** The glue never calls it (`OPlayerThread` is not even in
  `compile.ps1`'s API list, `glue\tools\compile.ps1:43`), and PROTOCOL 3 records that decision.

### 2.3 The other half: the glue itself made her the director
`iDefaultLead:Intimacy = 0` (`MCM\Config\LoreRimGlue\settings.ini:15`) -> `DefaultLeader()`
returns `"npc"` (`LRG_OStim.psc:283-288`), applied at every start (`LRG_OStim.psc:2025`) and on
adoption (`LRG_OStim.psc:2076`). With `fSceneLeadInterval = 45` she gets a turn every 45 s of
inactivity and the player's own speech does not reset that timer (section 3). Free camera plus an
NPC who changes the position every ~50 s and circles back to where she started is exactly the
experience the owner described.

### 2.4 Can CHIM's push-to-talk / text input be blocked while OStim's UI is up?
CHIM's gate is `AIAgentPapyrusFunctions.psc:1228-1251`
(`F:\Modlists\LoreRim\mods\CHIM\Source\Scripts\AIAgentPapyrusFunctions.psc`):

```papyrus
Bool Function SafeProcess(bool allowMenuMode = false)
    If (allowMenuMode || !Utility.IsInMenuMode()) \
    && (!UI.IsMenuOpen("Console")) && (!UI.IsMenuOpen("Crafting Menu")) \
    && (!UI.IsMenuOpen("MessageBoxMenu")) && (!UI.IsMenuOpen("ContainerMenu")) \
    && (!UI.IsTextInputEnabled()) && (!UI.IsMenuOpen("LootMenu")) \
    && (!UI.IsMenuOpen("RaceSex Menu")) && (!UI.IsMenuOpen("listmenu"))
```

Every CHIM hotkey path runs it: `BeginVoiceHotkey` (`:647`), `BeginTextHotkey` (`:615`),
`FinishVoiceHotkey` (`:689`), `UpdateChatHotkeys` (`:724`), plus
`ShouldSuppressChatboxFocusedHotkey` (`:297`, blocks while CHIM's own chatbox has focus) and
`AIAgentFunctions.isGameFocused()`.

OStim registers three menus (`OStim.log`: `OStimAlignMenu`, `OstimSceneMenu`, `OStimSearchMenu`;
swfs in `...\Interface\OstimSceneMenu.swf` etc.). None of them is in CHIM's name list, so the only
way OStim could block CHIM is by putting the game into menu mode. **F1 proves it does not**: four
player speech turns landed while the scene ran. Free camera also does not open a menu. Conclusion:
input is not the problem and no game-side change is needed for it.

One live trap worth telling the owner about, though: `AIAgentPapyrusFunctions.psc:9` declares
`int _currentKey = 0x52` (82 = numpad 0) as CHIM's text-chat key, and `OStimKeyAutoMode`
(`OStim.esp 0x000DEB`) ships as the same 82. The MCM default for CHIM's key is `-1`
(`AIAgentMCMConfigScript.psc:7,10`), so this is only a collision if the owner actually bound CHIM's
text chat to numpad 0 - but if they did, every text-chat press also flips OStim between manual and
full-auto control, and the glue's 5 s tick would then fight it back off
(`LRG_OStim.psc:2272-2276`). It did not happen in playtest 6 (no auto-mode log line).

### 2.5 Which options the OWNER changes, and which the GLUE sets
**Owner, in OStim's MCM (the glue must never write these - PROTOCOL 0 rail):**
1. **Camera > Free Cam > "Switch to free cam mode on start" -> OFF.** The one change most likely to
   remove "director mode".
2. General > "Fade out on intro/outro" - taste. Keep it only if her announcing line is guaranteed
   to land before the fade (section 6); otherwise the scene really does begin out of nowhere.
3. Controls - make sure CHIM's voice/text hotkeys are not numpad 0 / numpad `/` / numpad `.` /
   numpad 4-9 / `K` / `L`, which are OStim's.
4. Auto Control > Auto Mode Toggle - leave all five OFF (they already are).
5. Auto Control > "Max navigation distance" (5) - raising it makes more targets *animate* instead
   of warp; it is the same number as the glue's `iNavigationReach:Intimacy`, so raise both together
   or neither.
6. Orgasms > "End scene after male orgasm" (`0x000DF9` = 1) - owner's call; it ends scenes early.

**Glue, per scene:** nothing new in OStim's settings. The two globals it already borrows
(`0x000DAC` / `0x000DAD`, `HoldOStimGates` `LRG_OStim.psc:666-689`, released in
`ReleaseOStimGates` `:691-714`) are the mid-scene crash guard and must stay exactly as they are.
`OStimRemoveWeaponsAtStart` (`0x000DAB`) is already forced to 0 at `LRG_OStim.psc:519-527` and the
playtest log shows it firing on both starts ("was ON - turned OFF by the glue"). What the glue
should control per scene is its own state: leader, the lead hold, the warp policy and the announce
sequencing (sections 3, 5, 6). Optionally `OPlayerThread.SetPlayerControl(true)` once at
`ostim_thread_start` to guarantee the player keeps OStim's manual keys as a fallback - low value,
low risk, needs `OPlayerThread` added to `compile.ps1:43`.

---

## 3. Q1 - THE SCENE-LEAD TICK

### 3.1 When it fires
`LRG_Main` owns the quest's only update timer (`LRG_Main.psc:295-317`, `RequestTick` /
`OnUpdate` -> `ost.Tick()` + `SnapTick()`). Chain:

`Tick()` (`LRG_OStim.psc:2204`) -> thread running -> `ThreadTick(now)` (`:2252`) ->
`MaybeLead(afNow)` (`:2310`, reached only when `!windDown && !navInFlight`) ->
`MaybeLead` (`:2482-2516`).

`MaybeLead` returns early on: no partner / `starting` / `navInFlight` / `windDown` (`:2484`);
`leader != "npc"` or `lastAuto` (`:2487`); `(now - lastActivity) < fSceneLeadInterval` default 45
(`:2490`); glue or scene-talk switched off (`:2493`); no scene id or a transition (`:2496-2502`);
OStim auto mode on (`:2503-2507`); `AIAgentFunctions.isActorTalking(partnerName) != 0` (`:2508`).
Otherwise it sets `lastActivity = lastTalkTime = now`, re-asserts
`setAnimationBusy(1, partnerName)` and fires
`AIAgentFunctions.requestMessageForActor("lead", "lrg_scenetalk", partnerName)` (`:2511-2515`).

The tick cadence while a thread runs is 5 s (`ThreadTick` returns `nextIn = 5.0`, `:2254`), so the
real interval is 45 s + up to 5 s - matching the measured 45/49/41/60 s.

### 3.2 What resets it - and the answer to "does a player speech turn reset it?"
`lastActivity` is written at: `Maintenance` (`:196`), `NoteActivity` (`:276-281`),
`CmdClothing` (`:1522`), `CmdControl` (`:1638`), `MarkDirty(_, true)` (`:2193-2202`, i.e. every
scene change, speed change, hold/release, climax, lead change, furniture change, clothing in
scene), `OnOStimThreadStart` (`:2009`), `OnOStimActorOrgasm` (`:2142`), `AdoptRunningThread`
(`:2078`) and `MaybeLead` itself (`:2511`).

**A player speech turn does not reset it.** The game cannot see the player speak, and the one place
it could have is deliberately closed:

- `LRG_Main.OnChimSpeechStarted` (`LRG_Main.psc:440-455`): if the form is the player it sets
  `playerTalkTime` and **returns at line 448** - `ost.NoteActivity` (line 453) is only reached for
  an NPC.
- `LRG_Main.OnChimTextReceived` (`LRG_Main.psc:471-490`): returns at line 481-483 when the name
  resolves to the player.
- `LRG_OStim.NoteActivity` (`:276-281`) only accepts `akNpc == partner`.

So the only thing that resets the lead timer on a player turn is *the command that turn produced*
(`CmdControl:1638` / `CmdClothing:1522`). A player turn that produces no command - which is exactly
the 19:36:10 "get naked" turn, and every ordinary sentence - leaves the timer running. And even a
turn that does produce a command only buys 45 s, not the 150 s G2 asks for.

Two further gaps in the same place: `playerTalkTime` is only written when **CHIM voices the
player's line** (`FakeDialogue`/`FakeDialogueWith` are called with the player form only when
CHIM's player TTS is on, `AIAgentAIMind.psc:1719` / `:1790`); with player TTS off the game gets no
event at all. And `CHIM_SpeechStarted` fires **per sentence**
(`AIAgentAIMind.psc:1708` comment: "Should be called after NPC starts speech to listener (every
sentence)"), which matters for section 6.

### 3.3 Collision with CHIM's single MAIN semaphore
`MaybeLead` and `MaybeSceneTalk` (`LRG_OStim.psc:2441-2474`) both call
`AIAgentFunctions.requestMessageForActor(...)`, which takes the same MAIN lock as the player's own
utterance. Measured cost in playtest 6: the 19:32:45 lead tick's turn only started at 19:32:50 -
5 s behind the lock; the other three took 0-1 s. A player push-to-talk inside that window queues
behind a turn the player did not ask for, whose answer then moves the position.

The game has no way to ask CHIM "is a player request in flight" - `AIAgentFunctions.psc` has
`isActorTalking`, `isChatboxPanelFocused`, `isAnyPrismaHotkeyPanelFocused`, `isGameFocused`,
`get_conf_i`, and nothing about the request queue. The practical game-side mitigations are:
(a) fire far fewer lead ticks (75 s + the hold, below), and
(b) an optional new MCM key `iKeyPushToTalk:Keys` that the owner binds to the *same* key as CHIM's
voice hotkey; `RegisterForKey` does not consume the key, so both CHIM and the glue see it.
`LRG_Main.OnKeyDown` (`LRG_Main.psc:183`) already mirrors CHIM's menu guard
(`Utility.IsInMenuMode() || UI.IsTextInputEnabled()`), so it would fire on exactly the presses CHIM
acts on. It would call a new `ost.NotePlayerRequest()` -> sets the lead hold *and* a short
"request in flight" block (~15 s) so no lead tick is fired into the same lock window.

### 3.4 The lost lead action (F4)
cid `sa6f90c7e`: gate passed 19:30:08 with `do=goto;scene=OARE_GropingButtKiss`, and no
`GAME command` line ever followed. The dedupe in `LRG_Main.HandleCommand` (`LRG_Main.psc:363-366`,
key `npc|command|param`, 5 s) cannot explain it - the `cid=` inside the param differs from every
other command. The delivery simply did not happen. The most plausible cause is CHIM-side: the glue
re-asserts `AIAgentFunctions.setAnimationBusy(1, partnerName)` immediately before firing the lead
request (`LRG_OStim.psc:2513`) and again every 5 s (`:2266`), and `AIAgent.dll` carries
`"[SPEAKERMANAGER {}] {} is locked, cannot speak"`; a reply that is dropped because the speaker is
locked takes its action with it. This is a hypothesis, not a proven cause - but it is worth a
server-side diagnostic (G6: log "emitted N wire lines, funcret seen for M") so the next playtest
measures it instead of guessing.

### 3.5 What the game must send / receive for G2 (hold) and R11 (ask first)
The game cannot detect a player request by itself, so the hold must be **told to it**. Smallest
additive design, compatible in both directions:

- **New param key `hold=<seconds>`, accepted on `ExtCmdLRG_SceneControl` and `ExtCmdLRG_Clothing`,
  on every verb.** The game clamps to 0..600 and sets `leadHoldUntil = now + hold`. A missing key
  = 0 = today's behaviour (the neutral reading PROTOCOL 0 demands). An old game ignores it.
- **`do=lead;who=npc;hold=150` as the carrier when the turn produced no other command.** An old
  game understands `do=lead;who=npc` and simply re-asserts NPC lead (`CmdLead`
  `LRG_OStim.psc:1929-1950`), which is harmless; a new game additionally takes the hold. The server
  sends it on any player speech turn inside a scene.
- **`MaybeLead` gains one line**: `if afNow < leadHoldUntil: return` (and the hold survives a
  `MarkDirty`, unlike `lastActivity`).
- **`fSceneLeadInterval:SceneTalk` default 45 -> 75** (`settings.ini:33`, `config.json`). Add
  `fLeadHold:SceneTalk` default 150 so the owner can tune the hold the game applies when the server
  sends `hold=` without a number, and `iKeyPushToTalk:Keys` default 0 (section 3.3).
  **Every new key needs its default line in `settings.ini` or MCM Helper reads it as 0/false.**
- **R11 "ask first" needs nothing new in the game.** The server simply withholds the `goto` on a
  lead turn for a sex act, lets her ask in words, remembers the pending proposal, and sends the
  `goto` only after the player's yes. While a proposal is pending the server sends
  `do=lead;who=npc;hold=<ttl>` so the game stops offering her further lead turns; the ">= 75 s in
  the current act" clock is already derivable server-side from the `ev=change` stream
  (`lrg_scene_state._tier_since`).

---

## 4. Q3 - `CmdClothing` IN A RUNNING SCENE: EVERY WAY IT REFUSES OR DOES NOTHING

`LRG_OStim.psc:1453-1583`. First, the playtest fact: **it was not involved in the "get naked"
failure** (F2 - no gate line at 19:36:10, so no command was ever emitted). When it *is* called it
works: 17:06:33-35 (`inscene=0`, who=npc) and 18:48:34-35 (`inscene=1`, who=both) both succeeded.

### 4.1 Hard refusals (each answers `Error: <reason>` and notifies the player)
| line | condition | reason |
|---|---|---|
| `:1465` | `ok != 1` | `not authorised by the server gate` |
| `:1469` | `do` not undress/dress, or `who` not npc/player/both, or `part` not all/body/head/hands/feet | `unknown clothing request` |
| `:1473` | `!IsIntimacyEnabled()` (master switch, kill switch, intimacy switch) | `the feature is switched off` |
| `:1481` | `AIAgentFunctions.getAgentByName(asNpcName)` returns None, or the player, or a dead actor | `the actor could not be found` |
| `:1485` | `LRG_Profile.IsAdult` fails for either | `adults only` |
| `:1489` | `starting == true` | `a scene is just starting` |
| `:1496` | a thread runs but not both of them are in it | `a scene with someone else is running` |
| `:1503-1513` | **outside** a scene + `undress`: `PairBlockedReason` (`:1053`) then `PrivacyBlockedReason` (`:1072`) | `combat`, `a quest scene is running`, `too far apart`, `a child is nearby`, `someone is watching`, `a companion is present` |
| `:1515` | dry run | `dry-run mode, nothing changed` |

Two of these deserve attention:

- **`:1479` uses only `getAgentByName`, never the `partner` it already holds.** `CmdControl` is
  stricter *and* more forgiving: it resolves through `IsPartnerName` (`:290-297`) which falls back
  to comparing against `partner`. If CHIM's agent lookup misses her for a moment (she unloads,
  gets renamed, the name has a title), a clothing command inside a scene the glue is running dies
  with `the actor could not be found` even though the glue knows exactly who she is. Fix: in a
  running scene, prefer `partner` when `IsPartnerName(asNpcName)` is true.
- **The out-of-scene privacy branch is strict-by-default and can catch a scene that just ended.**
  `maxwit` / `folok` are missing from the in-scene shape the server emits (log 18:48:34:
  `do=undress;who=both;part=all`). `ParamGet` returns `""` -> `as int` = 0 and `folok != "1"`, so
  if `OThread.IsRunning(0)` happens to be false at that instant (the thread ended a second before,
  or the start is still pending and `starting` has already been cleared by the 20 s timeout at
  `:2211-2218`), the same command is judged as an out-of-scene strip in public and refuses with
  `someone is watching`. This is a genuine, findable failure mode for "undress" right at the end of
  a scene.

### 4.2 Silent successes (reports success, nothing changes)
- **`part != "all"` in a scene**: `ScenePartMask` (`:1268-1286`) skips any slot whose worn item
  also covers the body (`CoversBody`, `:1192-1198`) and returns 0 if nothing qualifies;
  `SceneUndress` (`:1288-1297`) then calls nothing at all, and the result string is still
  `"<npc> undresses."` (`:1552`). "Take your gloves off" over a robe reports success and does
  nothing.
- **`do=dress` in a scene when OStim never undressed them** (thread built with `NoUndressing`, or
  `gatePrevMid != 1`): `OActor.Redress` is a no-op and `DressPart` finds nothing (`cloNpcItems`
  only ever holds *out-of-scene* strips, `:1539`), yet the result says
  `"<npc> gets dressed again."`.
- **Out of scene the state push is skipped**: `MarkDirty("change", true)` and `stateRecheckAt`
  only run `if inScene` (`:1579-1582`). After an out-of-scene strip the server's `und=` / `undp=`
  view is stale until the next 20 s snapshot.

### 4.3 Interplay with today's `GlueUndressNode` (the crash fix - not to be weakened)
`HoldOStimGates` (`:666-689`) parks OStim's `UndressMidScene` (0xDAC) and `PartialUndressing`
(0xDAD) at 0 for the whole glue scene, and `GlueUndressNode` (`:728-771`) does the stripping
instead, once per thread position, tracked in the bitmask `glueStripped` (`:130`, set at `:760`).

`CmdClothing` uses `OActor.Undress` / `Redress` / `UndressPartial` / `RedressPartial` directly
(`SceneUndress` `:1288`, `SceneRedress` `:1299`). Those are OStim's own calls; OStim records what it
removed and redresses at thread end. **They are not blocked by the held gates** - the gates only
govern OStim's *automatic* stripping at node changes. So the crash fix does not break the verb, and
nothing here needs weakening.

But there is one real bug at the seam:

> **`CmdClothing` never clears `glueStripped`.** If the player asks her to dress again mid-scene
> (`do=dress;who=npc`), her bit stays set, so `GlueUndressNode` (`:758`,
> `Math.LogicalAnd(glueStripped, bit) == 0`) will *never* strip her again for the rest of the
> scene - including when the scene reaches a full-strip sex node that OStim would normally have
> undressed her for. She stays dressed through the act.

Fix, two lines, no weakening of anything: in the `dress` branch, clear the bit of each actor being
redressed (`glueStripped = Math.LogicalAnd(glueStripped, Math.LogicalNot(bit))`, or rebuild it from
`OThread.GetActorPosition`). Symmetrically, an explicit `undress ... part=all` in a scene could
*set* the bit so the ladder does not strip twice - cosmetic only.

A second, smaller one: `GlueUndressNode` indexes `glueStripped` by the **array index** of
`OThread.GetActors(0)` (`:757-766`), while `CmdClothing` works on actor identities. When the two
actors' positions swap in a later node the bits describe the wrong actor. In a strict 2-actor
player thread this is low-impact, but any fix should key the bitmask on
`OThread.GetActorPosition(0, actor)` instead.

---

## 5. Q4 - GAME LOAD DURING A SCENE (G4)

### 5.1 What Maintenance does
`LRG_PlayerAlias.psc:4-9` -> `LRG_Main.Maintenance()` (`LRG_Main.psc:69-115`): version bump, new
`sessionTag`, **every real-time stamp and cache zeroed** (lines 80-99 - correct, since
`Utility.GetCurrentRealTime()` restarts at 0 each launch), `UnregisterForAllModEvents()` +
re-register, `RegisterForCrosshairRef`, `RegisterKeys`, then `ost.Maintenance()` (line 110-113),
then the `maintenance done, version ..., session ...` log line (line 114).

`LRG_OStim.Maintenance()` (`:172-233`): detects OStim and SMI, `EnsureArrays`, clears every timer,
re-registers the six OStim mod events (`:203-208`), then

```papyrus
if OThread.IsRunning(0)            ; :210   -> AdoptRunningThread, keep startedByGlue/leader,
                                   ;           rescan furniture, RequestTick(5.0)
else                               ; :226
    ResetState()                   ; :227
    RestoreWeapons()               ; :229
    RedressRemembered("game loaded") ; :230
    baseValid = false              ; :231
endif
```

### 5.2 Why the server still believed a scene ran for ~10 s
**`ResetState()` (`:235-257`) sends nothing to the server.** It clears `partner`, `partnerName`,
`starting`, `startedByGlue`, `dirty`, `leader`, `sceneNearf`, `lastSentKey`, `lastSceneId`,
`goneSince`, and calls `setAnimationBusy(0, partnerName)` - but there is no `SendNpcMessage`,
no `ev=end`, no forced snapshot, anywhere on the load path. `FinishThread` (`:2153-2188`) is the
only place that emits `ev=end`, and a load never reaches it.

Nor does the *running* branch tell the server anything: `AdoptRunningThread` (`:2052-2082`) sets
state and requests a tick; the next `PushState` is only reached through `MarkDirty` / `Tick`, and
`PushState` de-duplicates on `lastSentKey` (`:2392-2396`) - `ResetState` clears that, but
`AdoptRunningThread` does not, so a reload into the *same* scene can be swallowed entirely.

Playtest 6 confirms both halves:
```
19:33:47 GAME maintenance done, version 200, session 6403     <- the only line the load produced
19:33:54 turn ... in_scene=OARE_StandingEmbraceKiss speech    <- server still in scene mode
19:33:57 result ... -> Error: no scene is running
17:34:04 scene row for Lisette closed: a newer snapshot says the scene is over
```
OStim's own log shows it closed the thread at load time (`13:33:43.962 closing old threads: 0`),
so `OThread.IsRunning(0)` was already false when `Maintenance` looked. The row only closed ~17 s
later, off the back of a snapshot - and the closing line is the one with the **UTC** timestamp
(17:34:04 among 19:33/19:34 lines), i.e. the known server-side timezone bug, confirmed in the wild.

### 5.3 Smallest reliable fix
Three small pieces; the first two are game-side and additive, the third is a one-line server rule.

1. **`LRG_OStim.Maintenance`, the no-thread branch (`:226`): emit one `ev=end` before resetting**,
   using the `partnerName` that survived in the save:
   ```papyrus
   if partnerName != "" && Main().IsEnabled()
       Main().SendNpcMessage(Main().MSG_SCENE, "ev=end;npc=" + partnerName + ";cid=" + startCid \
           + ";scene=;byglue=" + LRG_Profile.B2I(startedByGlue), partnerName)
   endif
   ```
   Covers the "saved mid-scene, loaded later" case exactly. It does **not** cover playtest 6's case
   (the owner loaded an older save whose `partnerName` was empty) - hence piece 2.
2. **`LRG_Main.Maintenance`, after `ost.Maintenance()`: force one immediate snapshot** of whoever
   the player is looking at:
   ```papyrus
   Actor look = Game.GetCurrentCrosshairRef() as Actor
   if look
       MaybeSnapshot(look, true)   ; carries ostim=0 -> the server closes the row at once
       Watch(look, false)
   endif
   ```
   The server already closes on that evidence ("a newer snapshot says the scene is over"); this
   just delivers it in the same second instead of ~17 s later, and it is name-free.
   Cheap hardening in the same place: add an additive snapshot key **`sess=<sessionTag>`** to
   `lrg_npcstate` (`LRG_Main.psc:683`, `PlaceFacts`, or next to `x=`). A changed session id is an
   unambiguous "the game was (re)loaded" signal for any NPC, so the server can invalidate every
   scene row without needing to know which NPC was involved. Old servers ignore the key.
3. **Server rule (BEHAVIOUR, noted only):** an `Error: no scene is running` funcret on any
   `ExtCmdLRG_SceneControl` closes the scene row immediately. The server already had that evidence
   at 19:33:57 and did not use it.

Also worth doing while in there: `AdoptRunningThread` should set `lastSentKey = ""` so the first
push after a load is never de-duplicated away.

---

## 6. Q5 - `goto`, WARPING, VISITED SCENES, LANDING NOTES, AND `RequestAct`

### 6.1 Route search reach and when it warps today
`NavigateBlockedReason` (`LRG_OStim.psc:1952-1985`) refuses on: empty target (`no position was
named`), `navInFlight` (`still moving into the previous position`), no current scene (`the scene is
still starting`), current or target is a transition, `asTarget == cur` (`already there`), actor
count mismatch. Then the oracle:

```papyrus
int reach = Main().SettingInt("iNavigationReach:Intimacy", 5)          ; :1979
string[] ok = OLibrary.GetScenesInRange(cur, actors, reach)            ; :1980
if ok.Find(asTarget) < 0 : return "unreachable"
```

`OLibrary.GetScenesInRange(Id, Actors, Distance = 0)` - "if 0 will use MCM setting"
(`OLibrary.psc:43`); the glue passes 5 explicitly, which equals OStim's own
`OStimNavigationDistanceMax` (0xE43 = 5). So reach is 5 transitions, evaluated against the live
actors, i.e. the same oracle OStim uses.

`CmdControl` `goto` (`:1678-1701`) then does:
```papyrus
if why == "unreachable"
    if m.SettingBool("bAllowWarp:Intimacy", true)     ; :1686  default 1
        useWarp = true ; why = ""
    else
        why = "that position cannot be reached from here"
```
`GoToScene` (`:1717-1729`) -> `OThread.WarpTo(0, target, true)` (fades) or
`OThread.NavigateTo(0, target)`.

**The `warp` param is never read on the `goto` path.** `ParamGet(asParam, "warp")` appears only in
`CmdWindDown` (`:1833`). So the server's `warp=0` is ignored and the game warps whenever its own
oracle disagrees. Playtest 6: 19:38:25 the server sent `warp=1`, the oracle said unreachable, and
19:38:26 the game warped (`GAME warp -> OARE_RearSexFPerformer`) - the two agreed there, but the
code path would have warped either way.

### 6.2 "NPC-led moves never warp, player requests may"
The clean additive change is a **new key `nowarp=1`**, not a reinterpretation of `warp=0`:
reinterpreting `warp=0` would make a *new* game refuse plain navigable gotos that an *old* server
sends without `warp`, breaking the owner's rule "if I say a position it should go to that
position". With a new key:

- server sends `nowarp=1` on every NPC-initiated goto (lead turn, afterglow, her own act change);
- game: `if why == "unreachable" && ParamGet(asParam,"nowarp") == "1"` -> refuse with
  `that position cannot be reached from here`, never warp;
- otherwise today's behaviour (warp if `bAllowWarp:Intimacy`);
- missing key = 0 = today's behaviour, so old server + new game and new server + old game both
  behave exactly as now. `CmdFurniture` (`:1882-1927`) needs the same treatment - a furniture move
  is a hard cut and should carry `nowarp=1` when she initiates it.

### 6.3 Visited-scene tracking and landing descriptions (G5.7, G5.9)
The game already sends everything needed and should **not** grow a visited list:
`PushState` (`:2355-2434`) emits `ev=change` with `scene=`, `trans=`, `furn=`, `speed=`, `tags=`,
`acts=`, `next=` on every landing, and `lastSceneId` (`:2090-2091`) already distinguishes a real
change from a repeat. The server sees the whole sequence per `cid` and can keep the visited set in
`lrg_scene_state` - it is the side that has the labels, the tiers and the memory anyway. The one
thing to add on the game side is cheap and useful for G5.9 and G6: include the **previous** scene
id on a change push, e.g. an additive key `prev=<id>`, so a dropped push cannot make the server's
visited chain lie.

For the neutral one-line landing description (G5.9), `LRG_Main.SendNarrative`
(`LRG_Main.psc:329-336`, type `infoaction`, log-only on the server) is the existing, correct
channel. But the description must be written from the index's vocabulary, which only the server
has - so the server should compose it and put it in her context itself; the game does not need a
new verb.

### 6.4 `RequestAct` - does the game need anything new?
**No.** The server can resolve act+role -> scene id from its own index and keep emitting
`do=goto;scene=<id>`; `CmdControl` needs no new branch. Everything the resolution needs is already
on the wire or on the server:
- live routability: `next=` in every non-transition push (`:2428`,
  `OLibrary.GetScenesInRange(sceneId, actors, 1)`, capped at 24 ids / 700 chars);
- acts and tags of the current scene: `acts=` / `tags=` (`:2423-2424`);
- who is in which position: `ppos=` / `npos=` (`:2408-2413`) - that is the roles half of G5.3;
- furniture: `furn=` + `nearf=` (`:2369-2372`, `:2422`).

Two optional game-side helpers, both small, if the server ever wants act data for scenes it has not
indexed: `OMetadata.FindAnyActionForActorCSV` / `FindAnyActionForTargetCSV` are already imported
and used by `GlueUndressNode` (`:759`), and `OMetadata.GetActionTypes` is already used for `acts=`.
The only genuine limit is the 700-char cap on `next=`: with 607 indexed scenes and reach 1 that has
been enough so far, but if `RequestAct` wants reach > 1 the cap should be raised or `next=` split
across pushes. Recommend leaving it and letting the server route in hops.

One caveat that matters for `RequestAct`: `iNavigationReach:Intimacy = 5` means a *single* goto may
traverse up to 5 transitions, which is a long animated walk. If the act layer wants "animated
transitions preferred" to feel prompt, prefer a chain of short hops (the server already has
`lrgSceneWalk`) over one 5-hop navigate, and keep `navInFlight`'s 25 s deadline (`:1725`) in mind -
a 5-hop route can outlive it and the glue will then declare the navigation finished while OStim is
still moving.

---

## 7. G3 / R10 - "SHE SPEAKS BEFORE SHE MOVES": WHAT THE GAME CAN ACTUALLY DO

### 7.1 Measured ordering of speech vs command in CHIM 3.3.2
- `AIAgent.dll` queues a reply line as one item carrying **both** text and action:
  `"[SpeakManager] Queueing new line - Actor: {}, Text: '{}', Expression: '{}', Action: '{}', Animation: '{}', Phonetic: '{}'"`.
- The audio is fetched and played afterwards:
  `"[SpeakManager] Starting DownloadAndPlay - Text: '{}', PreClip: {}, PostClip: {}, Speaker: {}, ..."`.
- The command reaches the game through `LRG.DispatchExternalCommand` (`glue\...\LRG.psc:10-17`;
  the DLL carries the string `DispatchExternalCommand`) and/or the `CHIM_CommandReceived` mod event
  (`AIAgentAIMind.psc:1395-1405`). The glue log records **`via Bridge` every time**, 0-1 s after
  the server emitted the line (F5).
- TTS playback start is signalled per sentence by `CHIM_SpeechStarted`
  (`AIAgentAIMind.FakeDialogueWith:1719`, `FakeDialogue:1790`; comment at `:1708`: "Should be
  called after NPC starts speech to listener (every sentence)"), and the end by
  `CHIM_SpeechStopped` (`EndDialogue:1845`). The glue already registers both
  (`LRG_Main.psc:104-105`, handlers `:440` and `:457`).
- The game can also poll `AIAgentFunctions.isActorTalking(npcName)` (`AIAgentFunctions.psc:33`),
  already used at `LRG_OStim.psc:2463` and `:2508`.

**Conclusion: the command arrives before her line is audible.** Nothing on the server can fix that
ordering; the *game* has to hold the action until her voice is out. In playtest 6 the start was
saved only by accident: the weapon prep takes ~3 s (19:29:10 command -> 19:29:12 prep ->
`13:29:12.991 starting scene`), which is roughly one short sentence - and that is exactly why some
starts felt fine and the makeout one "came out of nowhere".

### 7.2 The mechanism to build (game side)
**New additive param key `wait=begin|end` (missing = today's immediate behaviour), honoured by
`CmdStart`, `CmdControl` (`goto`, `furniture`) and `CmdClothing`.**

- `wait=begin` - hold the OStim call until her voice is *audible*: satisfied immediately if
  `isActorTalking(partnerName) != 0` when the command arrives, otherwise wait for the first
  `CHIM_SpeechStarted` for the partner, up to `fSayFirstWait:SceneTalk` (default 6 s). This is the
  right budget for R10 position/act changes, undressing and furniture moves: the owner's wording is
  "the line comes BEFORE or as the change happens".
- `wait=end` - additionally wait for `CHIM_SpeechStopped` for the partner (or
  `isActorTalking()` returning to 0), up to `fSayFirstMaxWait:SceneTalk` (default 12 s). This is
  the budget for `CmdStart`, because OStim's intro **fade** (`OStimUseFades` = 1) would otherwise
  cut across her line. Require the speech-start to be strictly *after* the command arrived, so a
  still-playing earlier sentence of the same reply does not satisfy it.
- **A start is never lost.** Both budgets end in "do it anyway". `startDeadline` is set to
  `now + 40` at `:506` and re-set to `now + 20` at `:609` immediately before
  `OThreadBuilder.Start`, so the wait sits inside the first window - but it must be added to the
  40 s (`startDeadline = now + 40 + waitBudget`) or a slow TTS fetch turns into
  `Error: the scene did not start` in `Tick` (`:2211-2218`).
- **Do the waiting in `Tick`, not inline.** `CmdStart` already blocks its Papyrus thread for ~4 s
  in `SettleWeapons` (`:875-906`) and ~2.5 s in `GuardStart` (`:908-922`); adding up to 12 more on
  the bridge call stack is asking for a dumped stack. Restructure: `CmdStart` does the gates and
  the weapon prep, then parks in a `pendingStart` state with a deadline, and `Tick` builds and
  starts the thread once the speech condition or the deadline is met. `RequestTick(0.5)` while
  parked is cheap.
- **Add `CHIM_SpeechStopped` bookkeeping for the partner**: `LRG_Main.OnChimSpeechStopped`
  (`:457-469`) already stores `watchTalkTime` for the watched NPC; add a forward to
  `ost.NotePartnerSpeech(started/ended, now)` so `LRG_OStim` has its own timestamps and does not
  have to poll the native every 0.5 s.
- **G3(b) / R10 server guarantee:** a start or a self-initiated change that arrives with no spoken
  text at all must not happen silently. The cleanest split is: the *server* never emits such a
  command (it speaks a short fallback line first, or re-asks), and the *game* only implements the
  hold. If the design instead wants the game to police it, the additive key would be
  `said=0|1` and `CmdStart` would answer a new error reason - but that burns the turn, so the
  server-side guarantee is the better half of the contract.
- **Changes the player asked for need no announcement** - so the server simply omits `wait=` on
  those, and they execute as fast as today. That is the whole compatibility story.

---

## 8. RECOMMENDED PAPYRUS CHANGES, SMALLEST FIRST

All of these are additive on the wire and keep script version 200 working with a new server and a
new script (version 300) working with the old server. **Every new MCM id needs a default line in
`MCM\Config\LoreRimGlue\settings.ini` as well as an entry in `config.json`, or MCM Helper reads it
as 0/false.**

1. **`settings.ini` / `config.json`: `fSceneLeadInterval:SceneTalk` 45 -> 75.** One number, G2's
   first half. (`settings.ini:33`)
2. **`CmdClothing`: clear `glueStripped` for any actor redressed in a scene** (`:1554-1569`).
   Two lines; fixes "she stays dressed for the rest of the scene after one `dress`". Does not
   touch `HoldOStimGates` / `GlueUndressNode` behaviour.
3. **`CmdClothing`: use `partner` when `IsPartnerName(asNpcName)` is true** before falling back to
   `getAgentByName` (`:1479`), mirroring `CmdControl` (`:1628`). Removes the spurious
   `the actor could not be found` inside a scene the glue is running.
4. **`LRG_Main.Maintenance`: force one snapshot of the crosshair actor after `ost.Maintenance()`**
   (`LRG_Main.psc:113`). Closes the stale scene row in the same second (G4). ~5 lines.
5. **`LRG_OStim.Maintenance`: emit `ev=end` on the no-thread branch when `partnerName != ""`**
   (`:226`), and set `lastSentKey = ""` in `AdoptRunningThread` (`:2052`). G4's other half. ~6 lines.
6. **`MaybeLead`: honour a new `leadHoldUntil`** (`:2484`), plus `fLeadHold:SceneTalk` default 150.
   ~4 lines.
7. **Accept `hold=<seconds>` on every `ExtCmdLRG_SceneControl` and `ExtCmdLRG_Clothing` param**
   (one `ParamGet` + clamp in `CmdControl` `:1617` and `CmdClothing` `:1521`), and let
   `do=lead;who=npc;hold=<n>` be the server's no-op carrier (`CmdLead` `:1929`). Completes G2. ~8 lines.
8. **Honour `nowarp=1` on `goto` and `furniture`** (`:1682-1692`, `:1882`). G5.5. ~6 lines.
9. **Add `prev=<last non-transition scene id>` to the `ev=change` push** (`PushState` `:2403`) and
   `sess=<sessionTag>` to `lrg_npcstate` (`LRG_Main.psc:683`). Two additive keys, both for G4/G5/G6
   robustness. ~4 lines.
10. **The `wait=begin|end` announce gate** (section 7.2): `CmdControl` `goto`/`furniture` and
    `CmdClothing` defer their OStim call; `CmdStart` is restructured into a `pendingStart` state
    driven by `Tick`, with `fSayFirstWait` (6 s) / `fSayFirstMaxWait` (12 s) and
    `startDeadline += waitBudget`. Add `NotePartnerSpeech` fed from `LRG_Main.OnChimSpeechStarted`
    / `OnChimSpeechStopped`. This is the largest item (~80-120 lines) and the one that actually
    delivers G3a and R10.
11. **Optional: `iKeyPushToTalk:Keys`** (default 0) registered in `RegisterKeys`
    (`LRG_Main.psc:167-181`) and handled in `OnKeyDown` (`:183`) -> `ost.NotePlayerRequest()`.
    Gives the game a direct "the player is talking" signal and suppresses a lead tick from being
    fired into the same MAIN-lock window (section 3.3). ~15 lines.
12. **Optional: `OPlayerThread.SetPlayerControl(true)` once at `ostim_thread_start`** (`:2013`),
    adding `OPlayerThread` to `compile.ps1:43`. Guarantees OStim's manual keys stay available as
    the owner's fallback. ~3 lines.

## 9. THINGS THAT ARE NOT GAME-SIDE (for the integrator)
- The UTC/UTC+2 mix is confirmed live (`17:34:04 scene row for Lisette closed` sitting between
  19:33 and 19:34 lines) and is a server-side bug.
- `OStimUseFreeCam` is the owner's MCM setting; the glue must not write it (PROTOCOL 0 rail).
- F4 (a gated command that never reached the game) needs a server-side diagnostic - "wire lines
  emitted vs funcrets received per cid" - before anyone can call it a game bug.
