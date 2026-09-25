# PT8 — "the AI is quick to walk away from you"

Investigation, read-only. CHIM = AIAgent 3.3.2 (`F:\Modlists\LoreRim\mods\CHIM`), HerikaServer at
`/var/www/html/HerikaServer` in WSL `DwemerAI4Skyrim3`, modlist LoreRim profile Ultra.

**One-line answer.** CHIM has *no* conversation hold for talking to the player. The only "stay near me"
feature it owns is switched off unless the player is **sitting down**, and on top of that the LLM is
offered an explicit `EndConversation` action that fires `ResetPackages()` and hands the NPC straight back
to her AI Overhaul / Jobs Overhaul / bard-scene package. It fired **8 times today**, three of them inside
the owner's 17:00–17:45 window and three more in the 21:45–21:55 window.

---

## 0. WHAT THE OWNER CAN CHANGE TODAY (do these first)

Ordered by effect. Nothing here needs a new build.

### 0.1 Disable the `EndConversation` action — biggest single win
- **Where:** CHIM web UI → **Action Editor** (`http://localhost/HerikaServer/ui/function_editor.php`,
  reachable from the CHIM control panel; also embedded in the Prisma **CHIM Settings hub**).
  Find the row **`EndConversation`** ("*#HERIKA_NAME# ends the conversation and becomes unavailable to
  talk for a short time*"), tick its checkbox to flip it to **Disabled**, press **Save all changes**.
- **Live state, verified:** `EndConversation = 't'` (enabled) in `public.combined_core_action`.
  Evidence: `functions/functions.php:65` puts it in the default `$ENABLED_FUNCTIONS_LOCAL`;
  `functions/functions.php:1000` is its description; `functions/functions.php:1938` its tool schema;
  `ui/function_editor.php:589-598` is the toggle; `ui/function_editor.php:1100` reads `is_activated`.
- **What it stops:** the model can no longer *choose* to leave. This is the one cause with hard log
  evidence today (§3).

### 0.2 Raise / neutralise the cooldown that follows it
- **Where:** CHIM web UI → **Global Settings** → **`END_CONVERSATION_COOLDOWN`** (⏳ icon), 0–300 s.
- **Current value: 60.** (`ui/global_settings.php:154`, `lib/core/prisma_settings_catalog.php:63`,
  `conf/conf.sample.php:44`.) Setting it to **0** means that even if she does end a conversation she is
  immediately re-chattable instead of stonewalling for a minute
  (DLL: `[RECHAT] Rechat avoided because cooldown: {}`, `{} is on conversation cooldown, showing message`).
  Leaving 0.1 done makes this moot; do both if the owner wants belt and braces.

### 0.3 Turn ON "NPCs Sandbox Near Player" and know its catch
- **Where:** MCM → **CHIM** → page **Behavior** → header **NPC Behavior** → toggle
  **"NPCs Sandbox Near Player"** (also in Prisma MCM under *Behavior ▸ NPC Behavior ▸ NPCs Sandbox Near
  Player*). Default is **ON** already.
  Evidence: `AIAgentMCMConfigScript.psc:1347` (SkyUI), `:854` (Prisma), `:2485-2494` (handler),
  info text at `:2875` — "*When enabled NPC's will subtly move around the player to make listening to
  conversations easier.*"
- **The catch (this is the real bug):** the code behind it returns immediately unless the **player is
  sitting**:
  `AIAgentAIMind.psc:1540` — `if (Game.GetPlayer().GetSitState()==0 || (Game.GetPlayer().IsOnMount()))
  ... return;` with the comment "*Dont use feature if player is not sitting, or is on a mount*".
  So while the owner is standing — i.e. almost always — **CHIM does nothing at all to keep the NPC
  nearby**. Practical workaround today: **sit down** (bench/chair/stool) to talk. Then the feature works:
  >1024 units → soft follow, <300 units → soft wait.
- Pair it with **"Seat Conversation Camera"** (same section, default OFF) so the camera turns to the
  speaker while seated (`AIAgentMCMConfigScript.psc:1349`, `:856`).

### 0.4 Leave "NPCs Walk To Target" OFF
- Same MCM section, **"NPCs Walk To Target"**, default **OFF** (`AIAgentMCMConfigScript.psc:132`, `:1348`,
  `:855`). It only applies to NPC→NPC speech, and it *does* move the speaker away from where she was
  (`AIAgentAIMind.psc:1751-1770`, soft-follow at >400 units, released after 40 s by
  `CheckAndReleaseWalkToTargetNPCs` at `:1650-1705`). If the owner ever turned it on, turn it off.

### 0.5 Keep "NPC Scene Safety" ON
- Same section, **"NPC Scene Safety"** — "*Prevent traditional dialogue-scene NPCs from responding
  automatically*" (`AIAgentMCMConfigScript.psc:1351`, `:857`, `:577-579` sets it to 1 on upgrade).
  Server/DLL side it becomes `AllowActorsOnScene` (DLL string: `Setting _restrict_onscene to {}, so
  AllowActorsOnScene is {}`). With it ON, an NPC who is mid-bard-performance / mid-quest-scene is not
  dragged into a CHIM conversation she is going to abandon two seconds later.

### 0.6 The immediate in-game handbrake: double-tap Voice Chat on her
- Looking at the NPC, **double-tap the Voice Chat hotkey** (MCM → CHIM → *Hotkeys ▸ Primary Hotkeys ▸
  Voice Chat*) → "*<Name> will wait here*".
  `AIAgentPapyrusFunctions.psc:693-713` → `AIAgentAIMind.StartWait(target)` →
  `AIAgentAIMind.psc:404-425`: `AddPackageOverride(WaitPackage, 100, 0)` + `WaitFaction` rank 1.
  Same action from the Prisma **Actions Menu** button **"Wait Here"**
  (`PrismaUI/views/CHIM/settings_menu.html:145-146`; wheel label at
  `AIAgentPapyrusFunctions.psc:1372`).
- **Warning:** this is a *persistent* package override written into the save. It does **not** time out.
  Release it with the Actions/wheel **"Follow Me"** or **"Stop All AI"**
  (`AIAgentPapyrusFunctions.psc:1373-1374`) or she will stand there for good. This is exactly the failure
  mode the glue's own hold is designed to avoid (§5).

### 0.7 Two smaller knobs
- **Global Settings → `RECHAT_MODE`** (currently `'random'`, `conf/conf.sample.php:608`). `tight` =
  listener-only; `conversational` = focused back-and-forth. `random` re-rolls per chain and is what lets a
  reply wander off to another NPC or the Narrator. Set to **`conversational`** or **`tight`** for a
  one-on-one that stays one-on-one.
- **Global Settings → `BORED_EVENT`** (currently 30) plus MCM → CHIM → *Behavior ▸ Bored Event Timer
  (seconds)* (`AIAgentMCMConfigScript.psc:1328`, default 60). A bored event is another NPC starting a
  conversation, which steals the listener slot.

---

## 1. WHAT CHIM DOES (AND DOES NOT DO) TO KEEP AN NPC IN PLACE

### 1.1 Talking to the player: nothing holds her
`FakeDialogue(Actor npc, int animation, int movehead)` — `AIAgentAIMind.psc:1788-1817` — is the function
CHIM calls for every sentence an NPC speaks **to the player**. Its entire body is:

- `npc.SetLookAt(Game.GetPlayer())` (`:1799`) — head only
- `GetIntoConversation(npc, Game.GetPlayer())` (`:1804`)
- an optional `PlayIdle` (`:1807-1813`)
- `PlaceCam(npc)` (`:1815`)

There is **no `SetDontMove`**, **no package override**, **no `KeepOffsetFromActor`**, **no faction**.
Her own package keeps running the whole time she is talking. `SetLookAt` turns the head; it does not stop
the feet.

### 1.2 `GetIntoConversation` — the only "stay near" logic, and it is gated on the player sitting
`AIAgentAIMind.psc:1530-1598`:

| line | what |
|---|---|
| `:1532` | `BackgroundCmd(npc,"Track")` — telemetry only (`:4144-4253`) |
| `:1534` | `StorageUtil.GetIntValue(None, "AIAgentNpcWalkNear", 1)` — the MCM toggle; 0 → `return` |
| **`:1540`** | **`if (Game.GetPlayer().GetSitState()==0 \|\| Game.GetPlayer().IsOnMount()) ... return;`** ← kills the feature whenever the player is standing |
| `:1549-1558` | if the other party is <256 units from the player use them as the anchor, else anchor on the player |
| `:1560` | guards: not in combat / killmove / **not `IsRunning()`** / not unconscious / `!GetCurrentScene()` |
| `:1561-1573` | distance >1024 → `FollowSoft(npc, anchor)` (soft approach) |
| `:1574-1590` | distance <300 → drop the soft follow, `StartWaitSoft(npc)` |
| `:1591-1592` | 300–1024 units → **"is at middle distance"**, i.e. *nothing is done at all* |

`FollowSoft` = `AIAgentAIMind.psc:502-528`: `FollowPackageSoft` (AIAgent.esp `0x0268B0`) at priority
**55**, plus `FollowFaction` (`0x01BC24`) and `SetLinkedRef(..., MoveTargetKw 0x021245)`.
`StartWaitSoft` = `:427-448`: `WaitPackage` soft variant (`0x0268B1`) at priority **50**.

Priority 50/55 is deliberately low, "soft". A quest/scene package or a high-priority AI Overhaul package
outranks it. The hard variants exist (`StartWait` priority 100 at `:404-425`, `Follow` priority 100 at
`:481-500`) but are only reachable by explicit player/LLM command, never by the conversation path.

### 1.3 Release paths
- `ReleaseFromConversation` — `:1600-1627`: removes `PackageSoft`, removes `FollowFaction`,
  `EvaluatePackage()`. Trace: `"<Name> leaves conversation"`.
- `EndConversation` — `:1629-1648`: clears the walk-to-target timers, `ReleaseFromConversation(npc)`,
  then **`ResetPackages(npc)`**, trace `"<Name> conversation ended, cooldown started in C++"`.
- `ResetPackages` — `:21-67`: `RemovePackageOverride` × 11 (Travelto, Attack, Follow, Seat, MoveTo, Wait,
  FollowPlayer, FollowPackageSoft, Sandbox, doNothing, SandboxWork), `SetLinkedRef(npc, None,
  MoveTargetKw)`, `SheatheWeapon`, **`npc.EvaluatePackage()`** (`:57`).
  `EvaluatePackage()` makes the engine re-pick a package *this instant* — so the NPC does not drift off,
  she turns and leaves on the same frame. **This is the mechanical "walk away".**
- `EndDialogue` — `:1841-1854`: only fires the `CHIM_SpeechStopped` mod event.
- `EndDialogueClear` — `:1856-1872`: `ClearLookAt()`, "*about 90 seconds after last speech*". Called from
  the DLL (`[RESTORE] Calling papyrus function for EndDialogueClear`). So CHIM's *only* stateful notion of
  "a conversation is live" is a ~90 s look-at timer, and it is head-only.
- `EndDialogueClearScene` — `:1874-1923`: for NPCs who were in a scene. Note it **stops and restarts the
  scene or the owning quest** (`:1887-1906`) with the comment "*Try to restart scene, can break quests*".

### 1.4 The one real hold CHIM has — and it is not used for conversations
`AIAgentPapyrusFunctions.psc:1311-1337`, `IntCmd / InterruptScene` (sent from the DLL, not from PHP —
no match for `InterruptScene` anywhere under `HerikaServer/*.php`):
stops bard songs (`BardSongsScript.StopAllSongs()`, `:1326`), `currentScene.Stop()` (`:1328`),
`Debug.SendAnimationEvent(npc,"IdleForceDefaultState")` (`:1329`), then
`AddPackageOverride(npc, doNothing, 99, 0)` + `EvaluatePackage()` (`:1334-1336`).
Two hazards worth knowing: the override is **never removed** on this path, and the form it uses,
`Game.GetForm(0x654e2)`, is labelled `doNothing` here but `Package Travelto` at
`AIAgentAIMind.psc:3833`. `projectNPC` (`:3831-3859`) is the only other place CHIM calls `SetDontMove` on
an NPC, and it is an experimental hologram toy.

### 1.5 The command surface CHIM exposes to the server
`AIAgentScriptProxy.psc` is a generic Papyrus bridge the server can drive by numeric id:
`41 SetPlayerTeammate` (`:605`), `52 SetLookAt` (`:677`), `54 SetHeadTracking` (`:687`),
**`55 SetDontMove`** (`:692-695`), `56 KeepOffsetFromActor` (`:697-712`), `81/82 EvaluatePackage`
(`:861-866`), `400 AddPackageOverride` (`:1276-1297`).
So the primitives exist and are callable; CHIM's conversation code simply never uses them.

### 1.6 Movement/leave actions the LLM is allowed to call
Live catalog state (`public.combined_core_action`, queried today):

```
ComeCloser      = t      MoveTo          = t      StopWalk = t
EndConversation = t      ReturnBackHome  = t      TravelTo = t
FollowPlayer    = t      WaitHere        = f   <-- the one that would keep her is OFF
```

`WaitHere` is also commented out of the default list in `functions/functions.php:66`
(`//    'WaitHere'`). Result: the model has seven ways to make her move and **zero** ways to make her
stay.

### 1.7 Server-side conversation settings (all of them)
- `END_CONVERSATION_COOLDOWN=60` — `conf/conf.sample.php:44`; also
  `lib/settings.php:230`, `lib/core/prisma_settings_catalog.php:63` (0–300),
  `ui/cmd/settings_portability.php:64`.
- `BORED_EVENT=30` — `conf/conf.sample.php:45`.
- `RECHAT_MODE='random'` — `conf/conf.sample.php:608`: "*tight = listener-only, conversational = focused
  back-and-forth, group = rotate around nearby NPCs, random = roll one mode per chain*".
- `ENFORCE_STRICT_RECHAT_RESPONSE=false`.
- `HTTP_TIMEOUT=15` (AI request timeout, not a conversation timeout).
- There is **no** server key for a conversation radius, a conversation timeout, or an idle-walk-away timer.
  `conf/conf.php` on this box is 0 bytes; live values come from the Postgres `general_settings` table via
  `lib/runtime_bootstrap.php` (schema `lib/core/database_schema/general_settings.sql`).

### 1.8 What the DLL does — re-targeting, not holding
Strings extracted from `F:\Modlists\LoreRim\mods\CHIM\SKSE\Plugins\AIAgent.dll`
(source path `D:\wt\chim-332-main-voice-hotfix\Plugin\PlayerConversationRouter.cpp`):

```
EndConversation / ReleaseFromConversation / Setting end conversation cooldown to {}s
{} conversation ended, cooldown started      {} is on conversation cooldown, showing message
infoaction|{}|{}|{} leaves the conversation  [RESTORE] Throwing out of the conversation : {}
[RESTORE] Calling papyrus function for EndDialogueClear / EndDialogueClearScene
[LISTENER-RESOLVE] Selected '{}' via {} (source={}, status={}, dist={:.1f}m)
[LISTENER-RESOLVE] Look target failed final spatial check; trying ranked auto target
[LISTENER-RESOLVE] Ranked auto targets failed final spatial check after {} candidate(s); falling back
[LISTENER-RESOLVE] Routing to Narrator: no targetable NPC in ranked spatial targets (audible={}, ranked={})
[RECHAT] Rechat avoided because speaker is no longer spatially valid: {} reason={} distance={:.1f}
NPC {} too far away: {:.1f} units   Too far away   (far away)   @Error: actor is too far away
Chosen target {}, distance {}   Selecting {} because distance ({})   Discarding {} because distance ({})
Setting _restrict_onscene to {}, so AllowActorsOnScene is {}
```

The DLL's whole model is *"pick whoever is closest and still in range"*. When she walks off it re-ranks,
drops her, and falls back to another NPC or **the Narrator**. It never tries to stop her.
The status suffixes the server strips confirm the same vocabulary:
`lib/data_functions.php:4585` / `lib/chat_helper_functions.php:3241` / `ui/api/chim_overlay.php:30`
`(busy|hostile|in combat|far away|too far away|restrained|dead|disabled|unavailable|…)`.

---

## 2. LORERIM MODS THAT MOVE NPCs, AND WHETHER THEY OVERRIDE CHIM

Profile `F:\Modlists\LoreRim\profiles\Ultra\plugins.txt` / `modlist.txt` (4086 entries).
**No Immersive Citizens** — good. What is there:

| mod | plugin | what it does | vs CHIM |
|---|---|---|---|
| **AI Overhaul SSE** | `AI Overhaul.esp` (2.1 MB) + ~30 `- AI Overhaul Patch.esp` files incl. **`JKs Winking Skeever - AI Overhaul Patch.esp`** | rewrites schedules/packages for most named NPCs: shifts, sitting spots, wandering, sleep | **beats CHIM's soft follow.** Its packages sit at the normal quest/actor level; CHIM's conversation packages are priority **50–55** (`:440`, `:523`). Overridden only by CHIM's priority-100 commands, which the conversation path never issues |
| **Andrealletius' Jobs Overhaul (AJO)** | `Andrealphus Jobs Overhaul - MASTER.esp` (2.1 MB) | gives NPCs job packages — they go to work and keep working | same: outranks the soft packages; an NPC on shift leaves |
| **Requiem** + `Requiem-general_NPC_tweaks.esp` | many | package/faction/AV edits | contributes to the package the NPC snaps back to on `EvaluatePackage()` |
| vanilla **bard scenes** | — | Lisette is a Winking Skeever bard: singing is a **Scene** driven by quest `0x00074A55` (`BardSongsScript`) | `GetIntoConversation` refuses outright when `GetCurrentScene()` is non-None (`:1560`). CHIM's only answer is the destructive `InterruptScene` (§1.4). The glue's own gate saw this today: `reasons=quest_scene` at 21:51:01 and 21:51:32 |
| **NPC AI Process Position Fix – NG** (`MaxsuAIProcessFix`) | SKSE dll | keeps distant NPCs' AI processing at the right position | makes off-screen packages *more* reliable, i.e. she is more likely to actually walk off |
| **Sandbox When Idle** | `SandboxWhenIdle.esp` + `po3_SandboxWhenIdle.dll` | **player only** — after `fAutoVanityModeDelay` (log says **120 s**) the *player character* starts sandboxing and wanders | not the cause, but a trap: stand still listening for 2 minutes and *the player* walks away, which then trips every distance check in §1.8. Log: `…\SKSE\SandboxWhenIdle.log` |
| **Smart Talk (Dialogue Menu Enhancer)** | `SmartTalk.ini` | vanilla dialogue-menu UX | not a schedule mod |
| **Considerate Followers** | `ConsiderateFollowers.ini` | followers silent during dialogue | not a schedule mod |
| **Collision Dialogue Overhaul**, **Chatty NPCs and Followers**, ~60 **Follower Dialogue Expansion** mods | many | add vanilla topics/forcegreets | these can *steal* the NPC into engine dialogue, which does hold her (§4) — the opposite problem |
| **Look Around – Searching Animations For NPCs** | OAR | animation only | harmless |

No "NPCs walk away when the player is far" mod and no "stop talking when the player is far" mod is
installed. The walking away is CHIM's own release plus these schedule mods reclaiming the NPC.

---

## 3. LOG EVIDENCE FROM TODAY

Clock note: `log/chim.log` timestamps are PHP-local **+02:00**; the Windows/WSL clock and
`log/lorerim_glue.log` are **-04:00**. Subtract 6 h from chim.log to get the owner's wall clock.

### 3.1 `EndConversation` fired 8 times
`log/output_to_plugin.log` — 8 × `Lisette|command|EndConversation@Jordan` / `Braste|command|
EndConversation@Jordan`, at lines 82, 95, 405, 435, 449, 454, 467, 472. In the same file: 18 ×
`ComeCloser@`, 10 × `FollowPlayer@`, 2 × `MoveTo@Jordan`, 1 × `TravelToRaw@100954`.

Timestamped in `log/chim.log` (local time in brackets):

| chim.log | local | who |
|---|---|---|
| `2026-09-21T23:34:59+02:00` (l. 5729-5733) | **17:34:59** | Lisette |
| `2026-09-21T23:40:18+02:00` (l. 8180-8184) | **17:40:18** | Braste |
| `2026-09-21T23:44:30+02:00` (l. 9778-9782) | **17:44:30** | Lisette |
| `2026-09-22T03:48:01+02:00` (l. 10296-10300) | **21:48:01** | Lisette |
| `2026-09-22T03:51:35+02:00` (l. 11763-11767) | **21:51:35** | Lisette |
| `2026-09-22T03:52:00+02:00` (l. 11976-11980) | **21:52:00** | Lisette |

All six land inside the two windows the owner named (17:00–17:45 and 19:00–21:00/21:5x).
Each is `openrouterjson: Prepared command payload for EndConversation` →
`Buffer contains: <Name>|command|EndConversation@Jordan` → `Echoing action to plugin: …`.

### 3.2 What the player had just said
`log/lorerim_glue.log`, correlated on the second:

- `21:47:58 [cid=s1aae7e93] turn npc=Lisette … say="follow me lazette"` → `21:48:01 llm … spoke=21`
  — and chim.log at the same second: `EndConversation@Jordan`. She was asked to **follow** and answered
  by ending the conversation.
- `21:51:32 … say="laz uh can you please come with me outside so we can talk"` → `21:51:35 llm … spoke=63`
  + `EndConversation@Jordan`.
- `21:51:56 … say="please i'm begging you to hear me out"` → `21:52:00 llm … spoke=122`
  + `EndConversation@Jordan`.
- Both of those turns log `mode=closed … reasons=quest_scene,witnesses` and
  `closed="something important is happening right now; other people are close enough to see or hear"` —
  she was inside a **quest scene** (bard performance) with witnesses at the time.
- `21:48:49 [cid=sd6408ffd] llm npc=The Narrator action=none … spoke=69` — one minute after she left, the
  next line came from **the Narrator**: exactly the DLL's
  `[LISTENER-RESOLVE] Routing to Narrator: no targetable NPC in ranked spatial targets`.
- The last spoken line in `log/output_to_plugin.log` is the narrative shape of the same thing:
  `Lisette|ScriptQueue|Got to get back to the Skeever and start my shift before the drunkards notice I
  was gone.`

### 3.3 The glue's own hold worked, three times, on the same NPC in the same session
`log/lorerim_glue.log`:

```
21:54:49 GAME outro hold: Lisette stays with you (Finished, the scene ran 53 s, up to 25 s)
21:55:04 GAME outro released (she has said her piece) held=14.1
21:59:20 GAME outro hold: Lisette stays with you (Finished, the scene ran 105 s, up to 25 s)
21:59:39 GAME outro released (she has said her piece) held=17.9
22:17:18 GAME outro hold: Lisette stays with you (Finished, the scene ran 262 s, up to 25 s)
22:17:52 GAME outro released (she has said her piece) held=33.0
```

Three `SetDontMove` holds, 14–33 s, all released cleanly by the NPC finishing her line, on a Winking
Skeever bard under AI Overhaul + AJO. **The mechanism is proven in this exact profile.** Zero stuck-NPC
incidents in the log.

### 3.4 Gaps
- Papyrus logging is off — `%USERPROFILE%\Documents\My Games\Skyrim Special Edition\Logs\Script` does
  not exist, so none of CHIM's `Debug.Trace("[CHIM] … is at middle distance")` /
  `"player conditions not met"` lines were captured. Turning `bEnableLogging`/`bEnableTrace` on in
  `Skyrim.ini [Papyrus]` for one session would give direct confirmation of the sit-state early return.
- The glue's snapshot has **no distance field** — `LRG_Profile.psc:329-356` sends `combat`, `scene`,
  `ostim`, `mate`, `married`, `rank`, witnesses, place facts, but never `npc.GetDistance(player)`. So
  "she was 900 units away when she answered" is not recoverable from today's logs. Worth adding
  (`;dist=` in `BuildSnapshot`) while building this.

---

## 4. WHAT REAL DIALOGUE DOES THAT CHIM DOES NOT

When the player activates an NPC and the **Dialogue Menu** opens, the engine — not a script — does all of
this at once:

1. **Puts the actor in a talking-to-player state.** The actor's AI process takes a dialogue target; its
   current package is suspended for the duration. Nothing re-evaluates packages while the menu is up, so
   no schedule, sandbox or travel package can fire.
2. **Freezes her feet without freezing her.** She still turns, gestures and plays dialogue idles, but she
   does not path anywhere.
3. **Holds the facing.** Dialogue headtracking points her at the player continuously and is cleared by
   the engine when the menu closes.
4. **Pauses the world clock for her turn.** The menu is a menu: her package timer, her shift, her scene
   do not advance while the player is reading topics.
5. **Releases atomically.** Closing the menu ends the state and the engine re-evaluates once — a single,
   clean handover.

CHIM's voice conversation has **none** of 1–5. It is a subtitle + TTS queue layered on an NPC whose AI is
running full speed the whole time. Everything it does is cosmetic-plus-hints:
`SetLookAt` (`AIAgentAIMind.psc:1799`), `PlayIdle` (`:1807`), `PlaceCam` (`:1815`), and a low-priority
soft package that is switched off unless the player is sitting (`:1540`).

CHIM even knows the distinction and defers to it. DLL strings:
`[RECHAT] Avoiding rechat event because player is in dialogue`,
`[BORED] Avoiding bored event because player is in dialogue`. And CHIM hooks the vanilla menu for its own
TTS: `AIAgentPapyrusFunctions.psc:1170-1195` drives
`_root.DialogueMenu_mc.startTopicClickedTimer` and ships a replacement
`Interface/dialoguemenu.swf`.

**The conversation hold is, precisely, item 1+2+3 of that list re-implemented in Papyrus.**

---

## 5. BUILD BRIEF — `LRG_Main`: THE CONVERSATION HOLD

### 5.1 Goal
While a CHIM conversation with an NPC is live, she **stops walking, faces the player and does not resume
her package**. One hold at a time, one release function, released on everything.

### 5.2 Mechanism: `SetDontMove`, not a package — decided
Reuse the outro hold verbatim (`LRG_OStim.psc:719-976`). Its own docstring at `:723-727` is the argument
and it is now backed by three clean runs today (§3.3):

- `SetDontMove` is **one boolean with an exact inverse**. `SetDontMove(false)` always undoes it.
- It **touches no AI package**, so nothing is written into the save that can outlive the mod.
  `ActorUtil.AddPackageOverride` *is* persisted — that is the classic "NPC frozen forever" bug, and CHIM
  itself leaves one behind on the `InterruptScene` path (§1.4) and on `StartWait` (§0.6).
- She can still **turn, talk, gesture and play idles** while it is on — which is what the engine's
  dialogue state also allows.
- Facing is CHIM's: it calls `SetLookAt` on every sentence (`AIAgentAIMind.psc:1799`) and clears it ~90 s
  after the last line (`EndDialogueClear`, `:1856-1872`). **Set the look-at once, never clear it** — same
  rule as `LRG_OStim.psc:795`.

**Rejected: a temporary package via the glue's own quest alias.** It would be more faithful to what
vanilla does (a real priority-100 package beats AI Overhaul and AJO outright, where `SetDontMove` only
stops the legs while the package keeps "running" underneath). But: it needs a new alias + a new package
form in the ESP, `AddPackageOverride` is written into the save, a stack dump between add and remove
strands the NPC permanently, and it would fight CHIM's own overrides (CHIM's `ResetPackages` removes *its*
eleven forms by name — an LRG package would survive `ResetPackages` and then *nothing* would clear it).
`SetDontMove` has a strictly smaller blast radius and today's log says it is sufficient. Revisit only if
playtesting shows an NPC visibly struggling against her package (turning on the spot, sliding).

Optional refinement if that happens: add `ActorUtil.AddPackageOverride(npc, <AIAgent doNothing 0x027374>,
60)` *alongside* `SetDontMove`, removed in the same `ReleaseConvHold`. `AIAgent.esp 0x027374` is
`AIAgentDoNothing` (`PF_AIAgentDoNothing_02027374.psc`) and CHIM's `ResetPackages` already removes a
`doNothing` form (`AIAgentAIMind.psc:50`) — so even a stranded override gets cleaned up by CHIM's next
`EndConversation`. Keep it behind its own MCM bool, default OFF.

### 5.3 Where it lives
**`LRG_Main.psc`**, not `LRG_OStim.psc`. `LRG_Main` already owns:
- the conversation target: `watchActor` / `watchTalkTime` / `lastSnapActor` (`:559-578`)
- the player's last spoken line: `playerTalkTime` (`:500`, `:522`)
- the CHIM speech events: `OnChimSpeechStarted` (`:489`), `OnChimSpeechStopped` (`:514`),
  `OnChimTextReceived` (`:538`), `OnChimCommand` (`:379`), registered at `:113-116`
- the single timer: `RequestTick` / `OnUpdate` / `SnapTick` (`:307-342`, `:580`)
- settings and logging: `SettingBool/Int/Float` (`:150-169`), `IsEnabled` (`:171`), `Log`/`LogC`
  (`:243-251`)

`LRG_OStim` must **not** hold and be held at the same time: `BeginConvHold` refuses while
`GetOStim().IsOutroHolding()` (`LRG_OStim.psc:493`) or a scene is starting/running, and
`LRG_OStim.BeginOutroHold` calls `ReleaseConvHold("a scene is ending")` first.

### 5.4 Fields
```
Actor convActor          ; who is held
string convName
string convCid
bool   convActive
bool   convHeld          ; SetDontMove(true) is really in force  <- own field, like outroHeld
float  convStart         ; GetCurrentRealTime when the hold began
float  convUntil         ; refreshed deadline
float  convLastLine      ; last line from either side
```
Mirror `LRG_OStim.psc:186-193`: `convActor` is its own field so that clearing `watchActor` cannot orphan
a live hold, and `convHeld` exists because `SetDontMove` **is written into the save** and must be
released on load even when `convActive` came back false.

### 5.5 Triggers (refresh, not restart)
Call `NoteConvLine(npc, now)` from — all four already exist:
- `OnChimSpeechStarted` (`LRG_Main.psc:489`) — an NPC sentence. **Also the player's own voiced line**:
  that branch (`:499-505`) currently returns early; take the refresh before the return.
- `OnChimSpeechStopped` (`:514`)
- `OnChimTextReceived` (`:538`) — the text arrived even if TTS has not started
- `OnCrosshairRefChange` (`:481`) — only as a *weak* refresh (looking at her ≠ talking to her); gate it
  behind `now - convLastLine < window`.

`NoteConvLine`:
```
if npc == None || npc == Game.GetPlayer()   ; player line refreshes the EXISTING hold only
    if convActive ; convUntil = now + Window() ; convLastLine = now ; endif
    return
endif
if convActive && npc == convActor
    convUntil = now + Window() ; convLastLine = now ; RequestTick(0.5) ; return
endif
if convActive                                ; a different NPC spoke
    ReleaseConvHold("someone else is talking now")
endif
BeginConvHold(npc, now)
```
Only an NPC that really got a snapshot is eligible — reuse the `akNpc == lastSnapActor` test from
`Watch()` (`:561-563`). That is already "a CHIM agent in range".

### 5.6 Refusals in `BeginConvHold` (checked once, on entry)
Copy the shape of `LRG_OStim.BeginOutroHold` (`:745-798`).

```
if !IsEnabled() || Window() <= 0.0 || IsDryRun()                 return
if npc == None || npc == Game.GetPlayer()                        return
if npc != lastSnapActor                                          return
LRG_OStim ost = GetOStim()
if ost && (ost.IsOutroHolding() || ost.IsSceneLive())            return   ; OStim owns her
if npc.IsDead() || npc.IsDisabled() || !npc.Is3DLoaded() || npc.IsUnconscious()   return
if npc.IsInCombat() || player.IsInCombat() || player.IsDead()    return
if npc.IsHostileToActor(player) || npc.GetCombatState() != 0     return   ; never a hostile
if npc.IsBleedingOut() || npc.IsInKillMove() || npc.IsOnMount() || npc.IsSwimming() || npc.IsFlying()   return
if npc.GetCurrentScene() != None                                 return   ; engine scene
if player.GetCurrentScene() != None                              return
if npc.GetSitState() != 0 || npc.GetSleepState() != 0             return   ; seated/asleep already holds
if npc.IsPlayerTeammate() && !SettingBool("bConvHoldFollowers:Conversation", false)   return
if IsArrestingGuard(npc)                                          return
if IsQuestCritical(npc)                                           return
if npc.GetDistance(player) > FarUnits()                           return
```

**How to detect each exempt state — all with primitives already in use:**

| exemption | test | precedent |
|---|---|---|
| hostile | `npc.IsHostileToActor(Game.GetPlayer())`, `GetCombatState() != 0`, `GetActorValue("Aggression")` | `AIAgentAIMind.psc:1560`, `:3800`; `LRG_Profile.psc:346` already reads Aggression |
| in an engine **scene** (bard song, quest scene, forcegreet scene) | `npc.GetCurrentScene() != None` | `LRG_Profile.psc:337` `;scene=`, consumed server-side as `quest_scene` (`ext/lorerim_glue/lib/lrg_core.php:826`, `lrg_actions.php:2042`). CHIM uses the same test at `AIAgentAIMind.psc:1560`, `:1522`, `:1757`. Proven today (§3.2) |
| in **engine dialogue** | `Game.GetPlayer().GetDialogueTarget() == npc`, or `UI.IsMenuOpen("Dialogue Menu")`. Cheapest robust form: skip while `Utility.IsInMenuMode()` or the Dialogue Menu is open — CHIM's own `SafeProcess()` (`AIAgentPapyrusFunctions.psc:1228-1251`) is exactly this list and can be copied | the menu already holds her (§4); holding on top is redundant and `SetDontMove` would survive the menu closing |
| **quest-critical package** | `Package p = npc.GetCurrentPackage()` (or `PO3_SKSEFunctions.GetRunningPackage(npc)`, used by CHIM at `AIAgentAIMind.psc:4236`); then `Quest q = p.GetOwningQuest()`; refuse if `q != None && q.IsRunning() && (q.IsObjectiveDisplayed(...) \|\| q.GetStage() > 0)`. Simplest safe rule: **refuse whenever `p.GetOwningQuest() != None`** — a package owned by a quest is by definition scripted movement. CHIM reads the same chain at `AIAgentAIMind.psc:1895-1898` | |
| **guard making an arrest** | `npc.IsGuard()` **and** the player is wanted: `Faction cf = npc.GetCrimeFaction()`, refuse if `cf != None && (cf.GetCrimeGold() > 0 \|\| cf.GetCrimeGoldViolent() > 0)`. Also refuse while `npc.IsArrested()` or `Game.GetPlayer().IsArrested()`. CHIM has the arrest path itself (`AIAgentAIMind.ArrestPlayer` `:3305`, `ConfirmArrestPlayer` `:3335`, `AddBounty` `:3213`) — hook `LRG_Main.HandleCommand` to release on those too | |
| **follower / teammate** | `npc.IsPlayerTeammate()` (`AIAgentAIMind.psc:945`, `LRG_Profile.psc:339` `;mate=`) | followers are already leashed; freezing one mid-follow is the worst UX. Default OFF, MCM bool to allow |
| already stationary | `GetSitState() != 0`, `GetSleepState() != 0`, `IsOnMount()` | seated NPCs cannot walk away; `SetDontMove` on a seated actor risks a stuck get-up |

### 5.7 `BeginConvHold` body
```
convActor = npc ; convName = npc.GetDisplayName() ; convCid = NextCid()
convActive = true ; convStart = now ; convLastLine = now
convUntil = now + Window()
convHeld = true
npc.SetDontMove(true)
npc.SetLookAt(Game.GetPlayer(), false)     ; never cleared here - CHIM owns the look-at
LogC(convCid, "conversation hold: " + convName + " stays with you (up to " + (Window() as int) + " s)", convName)
RequestTick(1.0)
```
`SetLookAt(..., false)` — the second argument `true` means *pathing* look-at; the outro hold passes
`false` (`LRG_OStim.psc:795`) and so should this.

### 5.8 `ReleaseConvHold(string asWhy)` — the only way out
Copy `LRG_OStim.ReleaseOutroHold` (`:800-832`) exactly, including:
- the `if !convActive && convActor == None && !convHeld : return` early-out, so it is safe to call twice,
  from any path, and on a hold only the save remembers
- clear every field **before** touching the actor
- `who.SetDontMove(false)` then `who.EvaluatePackage()` — "*her own package picks up cleanly instead of on
  the next AI poll*" (`:826-827`)
- `LogC(cid, "conversation hold released (" + asWhy + ") held=" + Secs(held), nm)`

### 5.9 `TickConvHold(float afNow)` — first in `OnUpdate`, before `SnapTick`
Copy `TickOutro` (`LRG_OStim.psc:885-976`). Order matters: cheap when there is no hold, and it is the
self-heal.

```
if !convActive
    if convActor != None || convHeld : ReleaseConvHold("a hold was left over") : endif
    return
endif
if convActor == None                                     ReleaseConvHold("the NPC is gone")
if Window() <= 0.0                                       ReleaseConvHold("the feature was switched off")
if afNow < convStart || (afNow - convStart) > (3.0 * Window() + 30.0)   ReleaseConvHold("self-heal")
if !IsEnabled()                                          ReleaseConvHold("the feature was switched off")
LRG_OStim ost = GetOStim()
if ost && (ost.IsOutroHolding() || ost.IsSceneLive())    ReleaseConvHold("a scene")
if convActor.IsDead() || convActor.IsDisabled() || !convActor.Is3DLoaded() || convActor.IsUnconscious()
                                                         ReleaseConvHold("she is gone")
if convActor.IsInCombat() || player.IsInCombat() || player.IsDead()     ReleaseConvHold("combat")
if convActor.GetCurrentScene() != None                   ReleaseConvHold("a scene started")
if convActor.IsInKillMove() || convActor.IsBleedingOut() ReleaseConvHold("she is fighting")
if IsArrestingGuard(convActor) || player.IsArrested()    ReleaseConvHold("an arrest")
if IsQuestCritical(convActor)                            ReleaseConvHold("a quest needs her")
if convActor.GetDistance(player) > FarUnits()            ReleaseConvHold("the player walked away")
if player.IsInInterior() || convActor.IsInInterior()
    if convActor.GetParentCell() != player.GetParentCell()   ReleaseConvHold("another cell")
if afNow >= convUntil
    ; the deadline may never cut across her own line - identical to LRG_OStim.psc:947-969
    bool talking = AIAgentFunctions.isActorTalking(convName) != 0
    if !talking && convLastLine > 0.0 && (afNow - convLastLine) < 4.0 : talking = true : endif
    if talking : convUntil = afNow + 5.0 : RequestTick(0.5) : return : endif
    ReleaseConvHold("timeout")
RequestTick(1.0)
```

### 5.10 Release triggers outside the tick
| trigger | hook | note |
|---|---|---|
| **game load** | `Maintenance()` (`LRG_Main.psc:78`) — call `ReleaseConvHold("the game was loaded")` **first and unconditionally**, exactly as `LRG_OStim.psc:313-317` does | `SetDontMove` is in the save |
| **kill switch / glue off** | the tick's `IsEnabled()` check; `IsEnabled()` = `bEnabled:General && !bKillSwitch:General` (`:171-174`) | |
| **stop hotkey** | `OnKeyDown` (`:208`) — `iKeyStopScene:Keys` already releases the outro (`LRG_OStim.psc:3399-3401`); release the conversation hold there too | one panic key for both |
| **a scene starting** | `LRG_OStim.BeginOutroHold` and the scene-start path call `Main().ReleaseConvHold("a scene is starting")` | mirrors `LRG_OStim.psc:1513`, `:3428` |
| **CHIM says the conversation is over** | `OnChimCommand` / `HandleCommand` (`:379-386`). `HandleCommand` currently returns false for anything not `ExtCmdLRG_` — **before** that early return, if `asCommand == "EndConversation"` or `"TravelTo"` / `"TravelToRaw"` / `"ReturnBackHome"` / `"MoveTo"` / `"Sandbox"` / `"GoToSleep"` / `"TakeASeat"` / `"MakeFollower"` / `"FollowPlayer"`, call `ReleaseConvHold("CHIM sent " + asCommand)` and still return false | **essential.** Otherwise `SetDontMove(true)` fights a CHIM `TravelTo` and she slides/struggles in place. Command names from `LRG_MOVEMENT_ACTIONS`, `ext/lorerim_glue/lib/lrg_actions.php:149-153` |
| **she is unloaded / cell change** | the tick's `Is3DLoaded()` and cell tests | |

### 5.11 MCM keys — page **Conversation** (new, after *Initiative*)
Matching the existing `<key>:<Page>` convention (`LRG_Main.psc:150-169`) and
`MCM/Config/LoreRimGlue/config.json` + `settings.ini`.

```ini
[Conversation]
bConvHold          = 1     ; "Keep her with you while you are talking"   (bool)
fConvHoldWindow    = 45    ; "Let her go if nobody speaks for"           0..180 s, 0 = feature OFF
fConvHoldFar       = 1200  ; "Let her go if you walk this far away"      400..4000 units
bConvHoldFollowers = 0     ; "Also hold followers and teammates"         (bool, default OFF)
bConvHoldLookAt    = 1     ; "Turn her to face you"                      (bool)
bConvHoldPackage   = 0     ; "Also suspend her AI package (stronger, riskier)"  (bool, default OFF)
```
Clamp in code the way `OutroHoldSeconds()` (`LRG_OStim.psc:733-743`) and `fOutroFar`
(`:929-934`) do, **including the "a missing MCM key reads as 0" guard on the distance**:
```
float Function Window()
    if !SettingBool("bConvHold:Conversation", true) : return 0.0 : endif
    float v = SettingFloat("fConvHoldWindow:Conversation", 45.0)
    if v < 0.0 : return 0.0 : elseif v > 180.0 : return 180.0 : endif
    return v
EndFunction
float Function FarUnits()
    float v = SettingFloat("fConvHoldFar:Conversation", 1200.0)
    if v < 400.0 : v = 1200.0    ; a missing key reads as 0 and would release her instantly
    elseif v > 4000.0 : v = 4000.0
    endif
    return v
EndFunction
```
`fConvHoldFar` default **1200**, deliberately tighter than the outro's 1500: mid-conversation the player
walking 1200 units away *is* the end of the conversation.

### 5.12 Log lines (via `LogC`, `bDebugLog:General`)
```
conversation hold: Lisette stays with you (up to 45 s)
conversation hold refused: Lisette (in a scene)
conversation hold refused: Lisette (a quest package: DialogueWinkingSkeever)
conversation hold refreshed: Lisette (line 3, 45 s left)        <- only at bDebugLog, it is chatty
conversation hold released (timeout) held=47.2
conversation hold released (CHIM sent EndConversation) held=12.8
conversation hold released (the player walked away) held=31.0
conversation hold released (the game was loaded) held=0.0
conversation hold released (a hold was left over) held=0.0       <- self-heal, should be rare
```
They land in `/var/www/html/HerikaServer/log/lorerim_glue.log` through
`AIAgentFunctions.logMessageForActor` as today's `GAME …` lines do. Keep the wording in the owner's voice,
as the outro's lines are.

### 5.13 Also add while in here: `dist` in the snapshot
`LRG_Profile.BuildSnapshot` (`:329-356`) has no distance field, which is why §3.4 could not prove how far
away she was. Add one line next to `;scene=`:
```
s += ";dist=" + (akNpc.GetDistance(akPlayer) as int)
```
and one `hold=` flag for whether a conversation hold is in force. Cheap, and it makes the next
walk-away report answerable from the log alone.

### 5.14 Risks, ranked, each with its mitigation already in the design
1. **An NPC stuck with `SetDontMove(true)` forever.** *The* defect this must not have.
   Mitigations: `ReleaseConvHold` is the single exit and is idempotent; `convHeld` is its own saved field;
   `Maintenance()` releases first and unconditionally on every load; the tick self-heals at
   3 × window + 30 s; the stop hotkey releases; `Window() == 0` releases. All six are lifted from a
   mechanism that logged three clean releases today (§3.3).
2. **ForceGreets and vanilla topics blocked.** LoreRim has Guard Dialogue Overhaul, Collision Dialogue
   Overhaul, Chatty NPCs, ~60 Follower Dialogue Expansions. A ForceGreet is a **package** and often a
   **scene**; with `SetDontMove` on she cannot walk over to greet the player. Mitigation: the tick releases
   on `GetCurrentScene() != None` and on a quest-owned package; the engine-dialogue exemption (§5.6) keeps
   the hold off entirely while the Dialogue Menu is open. Residual risk: a ForceGreet that is a bare
   package with no owning quest would be delayed by up to one tick (1 s). Acceptable.
3. **Followers.** Freezing a follower mid-follow reads as a bug. Mitigation: `IsPlayerTeammate()` refusal,
   `bConvHoldFollowers` default **OFF**.
4. **Guards mid-arrest / the player being hauled to jail.** Mitigation: `IsGuard()` + `GetCrimeFaction()`
   crime-gold check, `IsArrested()` on both actors, and a release hooked to CHIM's own
   `ArrestPlayer` / `AddBounty` commands.
5. **Fighting CHIM's own movement commands.** `ComeCloser` fired **18 times** today and `FollowPlayer`
   **10** (§3.1). If CHIM says "walk to the player" while the glue says "do not move", she stalls.
   Mitigation: the `HandleCommand` pre-hook in §5.10 releases on every `LRG_MOVEMENT_ACTIONS` name.
   This is the highest-traffic interaction in the whole feature — test it first.
6. **Struggling against a high-priority AI Overhaul / AJO package.** `SetDontMove` stops the legs but the
   package still "runs", which can show as turning on the spot or a slide. Mitigation: `bConvHoldPackage`
   (§5.2) as an opt-in second layer; keep it OFF until playtesting asks for it.
7. **Double hold with the outro.** Mitigation: mutual refusal via `IsOutroHolding()` /
   `IsSceneLive()`, and each path releases the other before beginning.
8. **Combat safety.** An NPC who cannot move is an NPC who cannot dodge. Mitigation: combat is checked in
   `BeginConvHold` *and* on every tick, for both actors, plus `IsBleedingOut` / `IsInKillMove`.
9. **Script load.** The tick is 1 s while a hold is live and free otherwise (`if !convActive : return`),
   and it shares `LRG_Main`'s single `RegisterForSingleUpdate` (`:307-333`) — no second timer.

### 5.15 Order of work
1. **Ship §0 first.** Disabling `EndConversation` in the Action Editor is a one-click change that removes
   the only cause with hard evidence. Get the owner to do it before any build.
2. Fields + `Window()`/`FarUnits()` + `ReleaseConvHold` + the `Maintenance()` release. Nothing holds yet;
   verify a load with a stale hold in the save releases cleanly.
3. `BeginConvHold` with the full refusal list, wired only to `OnChimTextReceived`. Watch the log for
   refusals on the Winking Skeever bard (she should refuse while she is singing).
4. `TickConvHold`, the `HandleCommand` pre-hook, the stop-hotkey release, the OStim mutual exclusion.
5. `NoteConvLine` on all four speech events, including the player's own line.
6. MCM page + `settings.ini` defaults; `;dist=` and `;hold=` in the snapshot.
