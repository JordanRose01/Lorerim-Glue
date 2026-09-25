# LoreRim Glue 0.5.6 - menuless questing v1.0 (game script 513)

An integration layer between **LoreRim** (Skyrim SE 1.6.1170, Requiem, MO2 profile `Ultra`),
**CHIM** (AIAgent 3.3.2 + HerikaServer), **OStim Standalone** and **OARE**. It is a normal MO2 mod
plus a HerikaServer extension. No file of LoreRim, CHIM, OStim, OARE or HerikaServer is ever
modified by this project.

> **Menuless questing v1.0 (2026-09-25; game script 513, server 0.5.6; `../research/pt19-menuless-v1-spec.md` rev 2).**
> The vanilla dialogue list is on screen and driven by voice. When you talk business with someone, her real list comes
> up - the same one E shows - and stays there; say what you want and the matching line is clicked for you; click any
> line yourself whenever you like. Nothing is hidden any more, so there is no emergency key; the menuless dry run ships
> OFF; the calibration is four passive rows learned from any conversation, the click route is proven by the first real
> click, and until one line has been picked on this install only a simple, harmless line is picked (the stage rail).
> What the owner sees, the first evening step by step and the settings: `OWNER_MENULESS_V1.md`. The contract:
> `PROTOCOL.md` section 10.29 and the v1.0 wire table at the end of 10.4. The keys and the Menuless questing controls:
> section 4 below.
> Version markers: `LRG_Main.CurrentVersion` **513**; `manifest.json` and `LRG_VERSION` **0.5.6**;
> `LRG_ACTIONS_VERSION` **11**; `PROTOCOL.md` v0.5 (build 0.5.5) plus section 10.29.
> **State:** built and tested offline; compile, deploy and install are the release step. Release gate A is NOT green
> yet - section 7 says what is red and whose it is (`../research/pt19c-F.md` has the checklist). Nothing of v1.0 has
> been seen in Skyrim.
>
> Everything below this box that names an earlier release is that release's history.

**Status (2026-09-22, release 0.5.3).** Built, compiled, offline-tested. Server deployed to
`/var/www/html/HerikaServer/ext/lorerim_glue`. Game files installed into
`F:\Modlists\LoreRim\mods\LoreRim Glue` by SHA-256.
**Nothing of 0.5.x has been tested in Skyrim.** Menuless questing ships inert (`bMenuless = 0`,
`bDlgDryRun = 1`); the follower work is live on load and has never had a real follower present in any
playtest.

Version markers: `manifest.json` **0.5.5** · `LRG_VERSION` **0.5.5** · `LRG_ACTIONS_VERSION` **11** ·
`LRG_Main.CurrentVersion` **507** · wire contract `PROTOCOL.md` **v0.5 (build 0.5.5)** (section 10.21 is this round).

> **0.5.5 server + GAME script 507 (2026-09-23, owner addendum 11 "never silent" - `research/pt14-ship-v507.md`).**
> Deployed and installed together. When she cannot do what the player asked (a quest scene she may not leave,
> clothes the game will not take off, a position that cannot be reached, a start the game refuses, a follow
> that came undone), she now SAYS so in her own voice, in CHIM's own funcret turn right after - one short line
> with the real reason; successes stay silent. The game marks every result `OK: ...` / `Error: <her words>`
> and sends the technical reason as `err=` (plus `late=1` for a command that came undone); the server decides
> on `err=` (PROTOCOL 1.6, 10.21).

> **0.5.4 server + GAME script 506 (2026-09-23, playtest 13 - `research/pt13-ship-v506.md`).** Deployed
> and installed together. "Follow me" now works for an NPC her own performance / idle scene holds (a
> bard on her stage): the server recognises follow / wait / release and sends `ExtCmdLRG_Escort`
> (PROTOCOL 10.20); the game ends a SAFE scene non-destructively and lets CHIM's follow package win.
> The false `BOOT STEP ABORTED` / `SNAPSHOT ABORTED` reports (and the `fol=` retirement they caused)
> are gone: lost now means silent for 60 s / 45 s, and `fol=` goes off only after three in a row. The
> 246 `Cannot cast from None` errors of `LRG_Dialogue` are fixed.

> **GAME script 505 (2026-09-23, playtest 12 - `research/pt12-stuck-oninit-fix.md`).** Not
> installed yet; the wire and the server are unchanged. The Papyrus log finally showed why 504 did
> nothing: Save14/Save15 carry two script stacks **frozen against each other** since playtest 10 -
> `LRG_Dialogue`'s `OnInit` called into `LRG_Main` while `LRG_Main.Maintenance` was calling into
> `LRG_Dialogue`, and a script is closed to everyone else until its `OnInit` returns. Every event
> of the quest queued behind them on every load since. 505 (1) moves `LRG_MainQuest` to a **new
> form id, 0x802**, so those saves drop the frozen instances and the quest starts fresh (the MCM
> quest keeps 0x801, so MCM settings are untouched); (2) makes every `OnInit` set members and
> register only; (3) boots `LRG_OStim` / `LRG_Dialogue` / `LRG_DlgProbe` by an `LRG_Boot` mod
> event that each confirms, with a 20 s watchdog (`BOOT STALL: <script>`) - `LRG_Main` never
> waits on them; (4) logs with a cached player name, no actor native in `LogC`. Corner note on
> load: **`LoreRim Glue v505 loaded - session NNNN`**. The plugin changed, so
> `LoreRimGlue.esp` and `Seq\LoreRimGlue.seq` must be installed together with the scripts.
>
> **GAME script 504 (2026-09-22, playtest 11 - `research/pt11-dead-scripts-fix.md`).** Not
> installed yet, and **the wire and the server are unchanged**, so `manifest.json` / `LRG_VERSION`
> stay at 0.5.3. `Maintenance()` no longer runs the whole load path on one Papyrus stack: the
> version line goes out through `Debug.Trace` / `Debug.TraceUser` / `Debug.Notification` before
> anything that can fail, and every block that reaches into CHIM, OStim, MCM Helper, PapyrusUtil or
> StorageUtil is a *boot step* run one per `OnUpdate`, with the next tick armed before the step, so
> one abort can cost at most that one block. A 30 s heartbeat keeps the quest's timer alive and
> renews the CHIM event registrations. On load you should now see a corner note
> **`LoreRim Glue v504 loaded - session NNNN`**; if that note is missing, the scripts are not
> running at all.

0.5.3 is playtest 10's two fixes, one per lane, and **the wire is unchanged in both directions**:

* GAME - the snapshot black box. `CurrentVersion` 501 -> 502 -> **503**: a Papyrus error inside
  `MaybeSnapshot` used to unwind the whole event and take the snapshot, the watch, the conversation
  hold and the latency mark with it, silently. The rail now notices the abort, names the stage in
  the log and retires the one optional block that was running. See `../research/pt10-snapshot-fix.md`
  and `../research/pt10-rail-gaps-fix.md`. The payload is byte-identical on a healthy install.
* SERVER - the recogniser now also runs on a `silent` turn whose only reason is `no_fresh_snapshot`,
  so the log says what the player asked for and the conversation keeps its build-up. **Nothing is
  offered and nothing is executed without a fresh snapshot** - that rail is unchanged. See
  `../research/pt10-intent-silent-fix.md`.

`maintenance done, version 503` in `lorerim_glue.log` is how you know the new `.pex` files really
loaded; `meta.ini` in the mod folder now reads `0.5.3`.

Owner notes: `../MORNING_SUMMARY.md`, then `../PLAYTEST9_NOTES.md` and `../PLAYTEST8_NOTES.md`.

---

## 1. What it does

**Intimacy, by talking.** When you look at a CHIM-driven NPC the game sends a snapshot of hard facts
(adult check, combat, quest scene, marriage, factions, class, morality, gold, witnesses, doors, your
renown and Speech). The server turns those into a per-NPC profile, evaluates hard gates, and only if
every gate passes does the LLM ever see the `BeginIntimacy` action. Whatever it answers is re-checked
by a server gate, and the game re-checks everything again before calling OStim. Inside a scene,
everything OStim's own menu does is reachable in words: a position by name, pace, hold, climax, wind
down, furniture, who leads, undress and dress per person and per part.

**Her own moves.** An NPC who is drawn to you may flirt, suggest somewhere private, or make a move -
but never silently: anything she starts herself gets a spoken line first. In public she proposes
rather than acts; the invitation is remembered for 30 minutes.

**Everyone has a price.** Gold can be part of the arrangement, priced in days of her own trade's wage
(an 8-hour day: 40 septims for common folk, 64 for a bard, 100 for a merchant or a guard, 132 for an
adventurer, 200 for a smith or a mage, 500 for the court) and from who she is. She names her own figure and haggles. The server never
starts a scene and never speaks for her; some people are never for sale at any price.

**Conversation hold.** While a CHIM conversation is live she stops, faces you and stays, instead of
walking back to her schedule mid-sentence. Only her feet are held. She is released on about a dozen
conditions, including you walking away, a fight, a scene, a menu, or a game load.

**Menuless questing v1.0 (on by default).** Quest, service and follower dialogue answered by speaking instead of
clicking: her real list is on screen - the same one E shows - and stays there; the glue reads it, the server matches
your words against an offline index of every dialogue line in this load order (37,561 prompts / 5,718 choice layers),
and the matching line is clicked in the real, visible conversation - real prices, real Requiem rules, real quest
hooks. You may click any line yourself at any moment. A line that cannot be undone asks once unless you said it
plainly; a lone line that changes nothing (the oath) plays by itself a short breath after the one before it, unless you
press your talk key. PROTOCOL 10.29; owner page `OWNER_MENULESS_V1.md`. Persuade / intimidate / bribe are decided by the engine. In ordinary conversation the same
four checks are run against this load order's own Speech globals and perks, never by the model.

**Services.** Bartering, training, inns, carriages and ferries, guard and crime talk go through the
NPC's real dialogue entry when she has one, and CHIM's own shortcut is hidden for that turn so the two
can never fire twice. An arrest is never picked by voice: the list stays yours to click, and she says so.

**Followers.** The AI is told who travels with you and which framework owns her. CHIM's
`MakeFollower` is hidden where it cannot work, a half-recruited follower is repaired, and the
conversation hold leaves companions alone. Follower verbs route to the framework's own dialogue entry
(that half is behind `bMenuless`). The framework here is **Simple Follower Framework** - Nether's is
not installed. [pt17] "Follow me" hands her to Simple Follower Framework when it can take her (its own recruit,
follow package and wait; "you can go" is a wait - parting ways stays her own dialogue); otherwise she walks with you the
old way and says why, and any follow of CHIM's found on a real companion is taken off her (MCM: bSffFollow, default on).

[pt18-words] NEVER FALSE (PROTOCOL 10.25): on a faction turn the NPC's words are judged against the game's own quest stages - a claimed enlistment, oath, rank, next step or appointment the game did not record is stopped before TTS, re-asked once with her own sentence quoted as false, and floored with one truthful line; config `never_false` (mode reask|mute|log), `grep never-false lorerim_glue.log`.
[pt18-quest] QUEST ENTRY BY VOICE (PROTOCOL 10.26): a scripted join line whose whole effect is one quest stage (the Legion's AP line -> CW00A 10) is applied by the game on a voice ask when the driver cannot click, after re-checking the line's engine conditions; on an Alternate Perspective start the Legion road is closed until Helgen (MQ101) and Tullius / Rikke say so.
[pt18-calibration, RETIRED in v1.0] The automatic calibration session and the emergency key are gone: the calibration is four passive rows learned from any conversation (an E-press counts), the click route is proven by the first real click, and it is still mirrored to Data/SKSE/Plugins/LoreRimGlue_calibration.ini so it survives a new save (PROTOCOL 10.13, 10.29 section 3).
[pt19-purchase] Buying food and drink by talking (pt19-purchase, PROTOCOL 10.27): at an inn or a food stall say "I'll have an ale", "a bottle of Honningbrew", "some bread" - she names the real price from her own stock (the game's own barter formula from your Speech and perks), and right after her line the game hands it over ("Ale added") and takes the septims. "How much for a mead" quotes and sells nothing; with too little coin she says so; a price she invents is corrected before anything is sold. Needs 'Handle rooms, rides, training and trade by talking' on (Services page). Log: grep 'buy ' in lorerim_glue.log. CHIM's own Rent Room shortcut charges its Action Editor 'Gold Cost' (10 by default) - set it to 25, the game's room price in this modlist.
[pt19-helgen] Quiet mode (script 511): while Helgen's Unbound runs (MQ101 from stage 5) the glue stands aside - no snapshots, holds, follow, escort, quest entry, opened conversations or initiative (a dialogue list the game shows is yours to click), and CHIM's move / follow actions are withheld from those actors; refusals are voiced with the reason. MCM > Menuless questing > "Stay out of scripted intro scenes (Helgen)"; grep the log for QUIET.
[pt19-physics] Butt physics: the glue now ships 3BA's own "Very Soft" butt springs (CBPConfig_butt.txt) and the butt volume is 1.0 (3BA's Normal) - modest, visible movement on fuller bodies nude or in a dress; most armour meshes have no butt bone. Dial: CBPConfig_ButtAmplitude.txt, then `cbpc reload` in the console.
[pt19-recogniser] "why don't you / why not / won't you / how about you <do X>" is a request, never a negation (PROTOCOL 7.2).

**Bodies and physics.** The mod also carries a nude CBBE 3BA female body, restored CBPC bounce
amplitudes, a 3BA physics-manager override and an OBody NG distribution config (curated random pool
plus deliberate assignment for 36 named women, two factions and one race). See
`game/LoreRimGlue/OVERRIDES.md` - one line per overridden file, with the reason.

---

## 2. Modules

### Game side (`game/LoreRimGlue/Source/Scripts`, 10 scripts)

| Script | What it owns |
|---|---|
| `LRG_Main.psc` | Hotkeys, `HandleCommand` (every `ExtCmdLRG_*`), `NoteChimAction`, the conversation hold, the snapshot send, the tick. |
| `LRG_Profile.psc` | The `lrg_npcstate` snapshot: hard facts, witnesses, doors, gold, follower facts, `ml=`. |
| `LRG_OStim.psc` | Intimacy: scene start and control, the weapon-crash workaround, the outro hold, the listener hold, follow-me-somewhere-private mode, redress, the controls repair. |
| `LRG_Dialogue.psc` | Menuless questing v1.0: the visible-menu driver - who drives a session (the journal-scene test, `drv`), topic reading, the stamped pick, HELD, clicking, the line-end grace, results, `StopDriving`. |
| `LRG_DlgUI.psc` | The menu layer: counting, reading, the two click routes (the hide primitives are kept, unused). |
| `LRG_DlgProbe.psc` | The four passive calibration rows, the route proof, the install file, Forget (`ev=calib reset=1`). |
| `LRG_Followers.psc` | Follower facts, SFF globals, ghost detection and repair. Globals only, no state, no properties. |
| `LRG_MCM.psc` | MCM Helper glue: the Calibration status line and Forget; re-registers the keys when one changes. |
| `LRG.psc` | The command bridge (`DispatchExternalCommand`). |
| `LRG_PlayerAlias.psc` | Player alias events. |

`LoreRimGlue.esp` is ESL-flagged, master `Skyrim.esm` only, two quests (`LRG_MainQuest` 0x802 with
the four main scripts plus the player alias, `LRG_MCMQuest` 0x801 with `LRG_MCM`). Script 505 moved
`LRG_MainQuest` to a new object id so that saves carrying the frozen 0.5.4 instances drop them; the
old id is retired in `tools/make_esp.py` and must never be reused.

### Server side (`server/lorerim_glue`)

Hooks: `context_pre.php`, `functions.php`, `globals.php`, `preprocessing.php`, `prerequest.php`,
`prompts.php`.

| Library | What it owns |
|---|---|
| `lib/lrg_core.php` | Config, state, profiles, gates, logging, the follower block, `LRG_VERSION`. |
| `lib/lrg_actions.php` | The action catalog, the offered-action filter, the post-process gate, the act list. |
| `lib/lrg_intent.php` | The deterministic recogniser for the player's own words, and the safety net. |
| `lib/lrg_scene_index.php` | The index of the installed OStim scene packs (607 scenes) and position search. |
| `lib/lrg_dialogue.php` | Menuless questing: sessions, matching, services, follower verbs, quest awareness. |
| `lib/lrg_prompt_index.php` | The offline dialogue index (Postgres schema `lrg_index`). |
| `lib/lrg_speech.php` | Persuade / intimidate / bribe / deception, using this load order's own rules. |
| `lib/lrg_latency.php` | The pacing rails and the per-reply latency line. |

Six action-catalog rows, all activated: `ExtCmdLRG_StartIntimacy` (`Begin_Intimacy`),
`ExtCmdLRG_SceneControl` (`Change_Intimacy`), `ExtCmdLRG_Clothing` (`Change_Clothing`),
`ExtCmdLRG_Invite` (`Suggest_Privacy`), `ExtCmdLRG_RequestAct` (`Request_Act`),
`ExtCmdLRG_SelectTopic` (`Take_Up_Business`). They are reinstalled on the first request after a
deploy so the new preconditions are picked up.

Tables in `public` (playthrough data): `lrg_npc_state`, `lrg_scene_state`, `lrg_romance`, `lrg_turn`,
`lrg_memory`, `lrg_dialogue`. The prompt index lives in its **own** schema `lrg_index`
(`lrg_prompt`, `lrg_prompt_layer`) so a playthrough switch does not clone 30 MB per profile.
Migrations `001`, `002`, `003`, `005`, `006`, `007`.

### Message types the game sends

`lrg_npcstate` (snapshot, fast) · `lrg_scene` (live scene state, fast) · `lrg_log` (diagnostics,
fast) · `lrg_topics` (a read topic list, fast) · `lrg_dlg` (session events keyed by `ev`, fast) ·
`lrg_scenetalk` (a spoken turn during a scene; text `lead` = she may act, `outro` = the goodbye) ·
`lrg_initiative` (text `approach`) · `lrg_dlgtalk` (no longer requested in v1.0; the handler stays) ·
`funcret` (the game's answer to a command). Wire format `k=v;k=v`; both sides ignore unknown keys and
a missing key always means the strict reading. Full contract: `PROTOCOL.md`.

---

## 3. What is live and what is not

| | Setting | Ships |
|---|---|---|
| Intimacy, initiative, scene control, the conversation hold, paid intimacy, closed-door privacy | - | **live** |
| Follower facts, `<companion_status>`, the hide rule, the ghost repair, the hold skip | `bFollowerAware` etc. | **live** |
| The latency line, locked facts, the truth gate, services (server half) | - | **live** |
| Quest talk without the menu (v1.0: the visible list, driven by voice) | `bMenuless` | **ON** |
| The menuless dry run (reads and decides, clicks nothing) | `bDlgDryRun` | **OFF** (never switches itself) |
| A lone effect-free line plays by itself after a short breath | `bAutoAdvance` | **ON** (2.5 s; a press of your talk key stops it; a lone line that moves a quest - scripted - always waits for his words or "yes") |
| Conversations the game opens (E-press, forcegreet) outside a journal-quest scene | - | **driven** |
| Conversations inside a journal-quest scene | `bDriveSceneMenus` (ini only) + the server's `dialogue.session.drive_scene` | **driven once the first click on this install is verified**; read-only before |
| Opening a conversation to find out (the pre-LLM open) | `bIntentOpen` | **ON** (five narrow clauses; short verbatim quest lines with one meaning word open too - "I have your shield", "What are your orders?", "Where are we headed?"; rides and training open after her line only; a quest line said to someone outside that quest opens nothing) |
| Rooms by voice in one sentence (the days list) | behind `bMenuless` | **live**: "a room for the night" -> the room line, then "1 day"; number words, nights count as days, "tonight" = 1 day (PROTOCOL 10.29 section 5) |
| Food and drink by voice, no window ("A mead, please") | the server's `market.enabled` | **live** (PROTOCOL 10.27; shipped before v1.0, unchanged) |
| NPCs inside a scene may be spoken to | `iSceneGate` | **1** = "Unless a journal quest owns it" |
| Guards, arrests and bounties | none (a text row) | **never picked by voice** |
| Follower verbs through her real dialogue | behind `bMenuless` | live |
| She raises a quest matter herself | - | **retired** (with `<she_may_raise>`) |
| The bounded reward bonus in septims (gate B) | the server's `dialogue.checks.reward.enabled` | **OFF** until v1.0.1 |

Until one line has been picked for you on this install (`clicks_ok` 0, the stage rail), only an indexed, plain,
cost-free line is picked; everything else is left for you to click and she says what to do. "Forget everything it
learned" starts that over.

---

## 4. MCM (Mod Configuration -> LoreRim Glue)

Twelve pages, 98 controls and three text rows (v1.0). Every control has a line in
`MCM/Config/LoreRimGlue/settings.ini` and a reader in Papyrus - `tools/test_mcm_wiring.php` asserts both, because
**a missing ini key reads as 0 / FALSE** and would silently switch a feature off - and every retired id is gone from
both files and from every script.

| Page | What is on it |
|---|---|
| **General** | Glue enabled, kill switch, debug log, failure notes. |
| **Intimacy** | The module switch, witness radii, closed-door privacy (`bPrivacyDoors`, `fHearRadius`, `fDoorRadius`), lead, navigation reach, warp, furniture, redress, the OStim weapon-crash switches, her-own-moves announcement, listener forcing, paid intimacy (`bPaidIntimacy`, `fPriceMultiplier`). |
| **Initiative** | She may approach you, and how often. |
| **Conversation** | The hold (`bConvHold`, `fConvHoldWindow` 45 s, `fConvHoldFar` 900, `bConvHoldPackage` off). |
| **Scene talk** | Speech during scenes, the lead interval and hold, say-first waits, the outro hold, the listener hold (`fListenerHold` 20 s), the camera repair (`bFixControlsAfterScene`), explicitness. |
| **Survival** | Cold exemption for Survival Mode Improved. |
| **Keys** | Stop scene now, dump topics, your talk key (the table below). |
| **Menuless questing** | The v1.0 controls (the table below), quiet mode for scripted intros, the free speech checks, quest awareness, the leave key. |
| **Calibration** | Only "Calibration status" (the live text) and "Forget everything it learned". |
| **Services and truth** | Real service dialogue, CHIM shortcut fallback, destinations by name, prices out loud, locked facts, the truth gate. |
| **Followers** | She knows who travels with you, follower verbs use her real dialogue, never hold a companion, repair a half-recruited follower and how. |
| **Diagnostics** | The latency line, the slow-reply warning threshold and, last on the page, the DEVELOPER dry run (DEV: dry-run everything) - leave it off. |

**Keys (v1.0).** A key code of 0 means unbound.

| MCM label | Page | id | default | what it does |
|---|---|---|---|---|
| Stop scene now | Keys | `iKeyStopScene:Keys` | 207 (End) | ends an OStim scene at once |
| Dump topics (test tool) | Keys | `iKeyDumpTopics:Keys` | 0 | writes the topics it sees for the NPC in your crosshair, the counters and the self-test to the log |
| Your talk key (CHIM's push-to-talk) | Keys | `iKeyPushToTalk:Dialogue` | 29 (Left Ctrl, CHIM's key on this install) | the glue only listens: any press during the short breath makes a lone line wait for your words instead of playing by itself; 0 = off. The key itself stays CHIM's: hold it and speak, as always; a quick tap is CHIM's own hush (it stops what anyone is saying through CHIM); a double tap tells the person you look at to wait here (PROTOCOL 10.29 section 6) |
| Leave the conversation | Menuless questing | `iKeyLeave:Keys` | 0 | ends the business politely (her own way out, else the menu's exit); with a guard who has something against you it closes nothing |

Removed in v1.0: "Open vanilla dialogue (emergency)" (`iKeyVanillaMenu`) - nothing is hidden, so nothing needs
bringing back - and the probe key.

**Menuless questing controls (v1.0).** Exact MCM labels; `bDriveSceneMenus` has no control on purpose.

| MCM label | id | ships | notes |
|---|---|---|---|
| Quest talk without the menu | `bMenuless:Dialogue` | on | the visible list, driven by voice |
| Menuless questing: dry run (nothing is clicked) | `bDlgDryRun:Dialogue` | off | the log says WOULD CLICK; never switches itself |
| Send quest talk to the server | `bDlgWire:Dialogue` | on | the rollback switch for an older server |
| Let her carry on by herself when there is only one thing to say | `bAutoAdvance:Dialogue` | on | NEW: the 2.5 s line-end grace; needs dialogue subtitles |
| Guards, arrests and bounties: always yours to click | (a text row) | - | replaces the retired `iCritical` and `bCrimeManual` |
| NPCs inside a quest scene | `iSceneGate:Dialogue` | 1, "Unless a journal quest owns it" | 0 = "Never" |
| May open a conversation to find out | `bIntentOpen:Dialogue` | on | the pre-LLM open on five narrow clauses |
| Stay out of scripted intro scenes (Helgen) | `bQuietIntro:Quests` | on | quiet mode (PROTOCOL 10.28) |
| How long it waits for the server | `fDecideTimeout:Dialogue` | 4 s | the list stays on screen either way |
| Quiet time before the first click | `fLineSettle:Dialogue` | 0.4 s | the first pick only |
| How close she has to be | `fOpenDistance:Dialogue` | 200 | also the server's `open.max_distance` |
| How the topics are read / How the topics are counted | `iReadMode` / `iCountMode:Dialogue` | 0 (automatic) | leave on automatic |
| Topics read in full / Topics counted beyond that | `iMaxEntries` / `iTailMax:Dialogue` | 0 / 16 | |
| Which click the menu takes | `iClickRoute:Dialogue` | 0 (automatic) | the route is proven by the first click |
| Allow a silent conversation / Plain activation | `bAllowNullVoice` / `bActivateDefaultOnly:Dialogue` | off / off | advanced; leave alone |
| (settings.ini only) | `bDriveSceneMenus:Dialogue` | 1 | drive a journal-scene list once the route is proven live |

Removed in v1.0 (spec S10 and the lane fixes): Conversations the game starts (`iEngineOpen`), Choices inside a
conversation (`iBranchInput`), How long she waits for your answer (`fSilenceTimeout`), the Hiding the menu group
(`iHideMode`, `bHideCursor`), Walk back to a lost choice (`bRewalk`, `iRewalkDepth`), Say when I have to choose by hand
(`bHandBackNote`), Carry on without the menu afterwards (`bResumeAfterChoice`), the first-playtest probe (`bProbe`,
`iProbePress`, its key), Touch the dialogue camera mod (`bIaccToggle`), Use the quest colour hint (`bQuestColour`),
`iCritical`, `bCrimeManual`, `bQuestInitiative`, `iQuestInitiativeGap`, `bDlgDryRunHold`, and every Calibration-page
control except the status line and Forget (the whole `[Calib]` section of settings.ini).

---

## 5. Folder map

| Path | What |
|---|---|
| `game/LoreRimGlue/` | The MO2 mod: ESP, seq, 10 `.pex` + sources, MCM config, meshes, `SKSE/Plugins` overrides, BodySlide preset. |
| `game/LoreRimGlue/OVERRIDES.md` | One line per file this mod overrides, and why. |
| `server/lorerim_glue/` | The HerikaServer extension: hooks, `lib/`, `config/`, `migrations/`. |
| `server/lorerim_glue/config/lrg_config.default.json` | Status rules, profiles, thresholds, prices, intent words. **Do not edit** - copy to `lrg_config.json` beside it; your copy overrides key by key and survives a deploy. Lists are replaced wholesale, not merged. |
| `tools/compile.ps1` | Compiles the Papyrus scripts with the compiler inside the Nemesis mod - no Creation Kit needed. |
| `tools/deploy_server.ps1` | Deploys the server extension (`-Remove` uninstalls). Anchor pre-flight, scene-index rebuild, index load into `lrg_index`. |
| `tools/install_mo2.ps1` | Installs the MO2 mod. Refuses while MO2 or Skyrim runs. `-NoNudeBody` skips the meshes. |
| `tools/make_esp.py`, `tools/esp_dump.py` | Generate / inspect the plugin without the Creation Kit. |
| `tools/build_prompt_index.py` | Builds the offline dialogue index from the installed plugins. |
| `tools/bench_llm.php` | Compares your own LLM connector rows (time to first token, total, JSON compliance, cost). Read-only. |
| `tools/bench_llm_models.php` | The model benchmark. **Dry by default** - it spends nothing unless `--live` is passed. |
| `tools/warm_index.php`, `tools/test_latency_prompt.php` | Scene-index warm-up; prompt-size dumps. |
| `tools/flows/` | The offline conversation harness and 89 scenarios. |
| `tools/test_questline.php`, `tools/fixtures/lrg_questline*.json` | The questline coverage harness (menuless v1.0): the main quest and the guild openings walked over the real prompt index; `--first-evening` checks the owner page's own sentences. |
| `OWNER_MENULESS_V1.md` | Menuless questing v1.0 for the owner: what changed, what you will notice, the first evening step by step, the settings, the open decisions. |
| `PROTOCOL.md` | The wire and code contract. If code and this file disagree, this file wins. |
| `PHASE2_DESIGN.md`, `V03_DESIGN.md`, `V04_BUILD_PLAN.md`, `V05_EXPANSION_PLAN.md` | Design and build plans per round. |
| `OWNER_ADDENDA.md` | The owner's own instructions, newest last. They win over the round prompt, the design and the critic. |

---

## 6. Build, deploy, install

```powershell
powershell -ExecutionPolicy Bypass -File tools\compile.ps1         # -> game\LoreRimGlue\Scripts (must print OK)
powershell -ExecutionPolicy Bypass -File tools\deploy_server.ps1   # -> WSL DwemerAI4Skyrim3
powershell -ExecutionPolicy Bypass -File tools\install_mo2.ps1     # MO2 and Skyrim must be CLOSED
```

* **`compile.ps1`** must print **OK**. It refuses a build whose `.pex` contains a string over 500
  characters, and docstrings must stay under 300 characters. Vanilla sources are not on this machine,
  so referenced script types get generated declaration stubs; stubs never reach the game.
* **`deploy_server.ps1`** runs an anchor pre-flight first (every shared-file edit is expressed as the
  statement that contains a verbatim line, never a line number; a missing or duplicated anchor stops
  the deploy), rebuilds the scene index, ensures schema `lrg_index` and loads the prompt index. It
  never touches `config/lrg_config.json` or `data/`. Postgres must be up - start it if CHIM's
  launcher is closed.
* **`install_mo2.ps1`** backs up `modlist.txt`, `plugins.txt`, `loadorder.txt` and `settings.ini`
  first, never sorts, never touches the Default or Extreme profiles, and writes the version MO2 shows
  from `manifest.json`. **If it refuses because MO2 is open**, copy only our own files
  (`LoreRimGlue.esp`, `Seq\*`, `Scripts\LRG*.pex`, `Source\Scripts\LRG*.psc`,
  `MCM\Config\LoreRimGlue\*`) into `F:\Modlists\LoreRim\mods\LoreRim Glue` and verify by hash. **If
  it refuses because Skyrim is running, stop.**

One deployment channel only: this is the dev channel. Do not also ship a
`Data/CHIM/server-plugins` package.

WSL cannot see this project folder. Stage to `%TEMP%\lrg_test`, write `.sh` with LF endings, and run
`wsl -d DwemerAI4Skyrim3 --cd / -- bash /mnt/c/Users/Jordan/AppData/Local/Temp/lrg_test/<script>.sh`.
Postgres: `PGPASSWORD=dwemer psql -h localhost -U dwemer -d dwemer`.

---

## 7. Tests

All offline. No game, no LLM, no money.

**Release gate A, the formal record (2026-09-25 05:04, the pt19c final fixer, `../research/pt19c-final.md`).** The tree was
frozen after the last fix (no other writer since 04:16), `tools/compile.ps1` was run on it (05:03: 0 errors, 0 warnings, no
.pex string over 500 characters, the ten glue .pex files rebuilt), and the same tree was staged twice (`r1`, `r2`: code, fixture,
config, .psc, .pex, the owner page and PROTOCOL.md hash to the same manifest, md5 `5498dee02872`) and run whole on each, php 8.2,
the live prompt index (hash e756e311, 37,561 rows). Both runs gave identical results (only random cids and temp paths differ):

| Command | Result (r1 = r2) |
|---|---|
| `tools/compile.ps1` | 0 errors, 0 warnings; the 500-character .pex string guard and the deploy denylist passed |
| `php -l` (every `.php` under `server/` and `tools/`) | 97 files, 0 errors; the three config JSONs and both fixtures valid, UTF-8 without BOM, LF |
| `tools/test_gates.php` | 793 passed, 0 failed |
| `tools/test_intent.php` / `test_phrases.php` / `test_stt.php` | 470 / 47 / 60 passed, 0 failed |
| `tools/test_dialogue.php` | 1,019 passed, 0 failed (v21b now runs the fixture's 22 single beats, 34 never lines) |
| `tools/test_prompt_index.php` | 99 passed, 0 failed |
| `tools/test_mcm_wiring.php` | 49 passed, 0 failed |
| `tools/test_services.php` | 99 passed, 0 failed (it reads `OWNER_MENULESS_V1.md`'s service rows) |
| `tools/test_scene_index.php` | all checks passed |
| `tools/test_latency.php` | 34 passed |
| `tools/test_latency_prompt.php` | mean 2,306 chars / 576 tokens per turn (budget 2,500 chars) |
| `tools/test_questline.php` (the gate) | 1,956 passed, 0 failed, 0 known |
| `tools/test_questline.php --first-evening` | 222 passed, 0 failed |
| `tools/test_questline.php --words` / `--first-evening --words` | 2,194 / 247 passed, 0 failed |
| `tools/test_questline.php --stage=B` / `--allow-known` | 39 passed / 1,956 passed, 0 known |
| `tools/flows/run_flows.php` | 89 scenarios: 88 passed, 0 failed, 1 pending (`d68`, gate B by design); 1,600 checks, 0 warnings |

Gate A's offline proof is complete. The questline rows went green through the spec owner's two rulings (the fixture's
`ruling` fields: MQ102.balgruuf.intro and MQ105.arngeir.summons are explicit / ask once, because their own topic carries a
version that ends the talk; Sven's "sing me something about dragons" picks his ballads line). What is left before the owner
plays is not offline: `tools/deploy_server.ps1` (CHIM's launcher running) and `tools/install_mo2.ps1` (MO2 closed), both the
orchestrator's.

Results of the 0.5.3 release pass (2026-09-22 19:20), kept as history:

| Command | Result |
|---|---|
| `tools/test_gates.php` | 332 passed (section 8b = the blind turn) |
| `tools/test_intent.php` | 190 passed |
| `tools/test_phrases.php` | 14 passed (hit rate 91.9 %, floor 82 %) |
| `tools/test_scene_index.php` | all checks passed, 607 scenes |
| `tools/test_dialogue.php` | 174 passed |
| `tools/test_prompt_index.php` | 67 passed |
| `tools/test_prompt_index.php --db` | 112 passed against the live database |
| `tools/test_mcm_wiring.php` | 4 passed; prints **113 controls**, every one with an ini line and a reader |
| `tools/test_services.php` | 35 passed |
| `tools/test_latency.php` | 26 passed |
| `tools/flows/run_flows.php --strict` | **75 scenarios, 75 passed, 1,203 checks, 0 pending, 0 warnings** (scenario 27 = the blind turn) |
| `php -l` | 79 files, 0 errors (the deploy re-runs it on the deployed copy) |
| `tools/esp_dump.py` | valid; all VMAD byte counts consumed exactly |
| `tools/compile.ps1` | OK, 0 errors, 0 warnings, 10 `.pex` |

`run_flows.php` is the release gate - read its headers. `tools/flows/mutation_check.ps1` breaks rails
on purpose in a throw-away copy and requires the suite to catch every one.

Two standing notes the index tests print, neither a failure: one engine-only plugin is unreadable
(`Sanguine Symphony.esp`, not needed), and nine topics in this load order have an inverted flag
(upstream data, reported, changed nothing).

---

## 8. Owner-side settings this project does not write

The glue writes no setting of any other mod. These are set by hand, once:

**CHIM web interface** (`http://localhost:8081`, launcher must be running): switch **off** the actions
`MakeFollower` (`Join_<name>_Party`) and `Follow`; leave `FollowPlayer` on; set `EndConversation` to
Disabled; `END_CONVERSATION_COOLDOWN` 0; `RECHAT_MODE` `conversational`; untick `<nearby_items>`,
`<points_of_interest>` and `<group_descriptions>` under Context Selections; delete or repoint the dead
`Gemma 3N E4B` connector.

**CHIM MCM**: AI Quest Progression **off**; Player TTS for Traditional Dialogue off, Vanilla Dialogue
capture on, NPC Scene Safety on; "force default voice" off; do not press the K2 wait hotkey at a
follower.

**Other mods**: IACC `bForceFirstPerson = 0` and `bHideDialogueMenu = 0`; Smart Talk
`bSkipImmediateOnInput = 0` and `bHoldToSkip = 0` (hard preconditions for menuless questing: until they are 0 the Calibration status reads "blocked - ..." and nothing is picked for you; also `bSkipOnInteraction = 0`) and never
change `iPapyrusHandle`; Helmet Toggle 2 `HT_EnableDialogue` off once menuless is really on; Fuz Ro
D-oh words-per-second 3 or 4.

**Voice latency**: PocketTTS competes with Skyrim for the graphics card - measured 0.089 s idle
against 3.41 s median in a play session. `/home/dwemer/audio.cpp/server.json` -> `"backend": "cpu"`,
`"threads": 8` is the owner's decision, with `server.json.bak` as the revert. `audiofilterd` does not
exist in this distro, so the ~0.28 s of leading silence would need a one-line edit to a CHIM core
file - also the owner's decision. Details and numbers: `../research/pt9-tts-cpu.md`.

---

## 9. Safety rails (never configurable)

* Adults only, fail closed: a fresh snapshot must say `adult=1`, and the game re-checks it.
* The NPC must be willing. Her consent is decided **before** a scene - gates, her interest, her own
  choice of the action. A refusal there is final and is never overridden. The server never starts a
  scene by itself and never speaks for her.
* Inside a running scene the player's requests are never refused in character (owner addendum 3).
  Only the **game** can still say no - not installed, filtered, wrong actors or furniture,
  unreachable - and then she says so in words, never silence.
* `stop` ends a scene at once and wins every tie, by voice and by hotkey. The kill switch overrides
  everything. [v1.0] The emergency dialogue key is retired: the dialogue menu is never hidden.
* The player is always a participant. Forced, non-consensual and creature scenes are never indexed.
* Arrests and anything lethal are never picked by voice, at any setting (`iCritical` and `bCrimeManual` are retired):
  the list stays on screen for the player to click.
* Unoffered or invented actions are dropped by a server gate; an action carrying a number the game
  never confirmed is dropped by the truth gate.
* No file of LoreRim, CHIM, OStim, OARE or HerikaServer is written, and no OStim setting is written.
  Nemesis, LOOT and BodySlide are never run. No C++ tooling.
* [v1.0] Nothing is hidden: the real dialogue list stays on screen whenever the glue works on it, the player can click
  it at any moment, and nothing is clicked by a timeout except a lone effect-free line after its short breath.
* No explicit example dialogue in code, prompts, tests or docs: write rules, not lines.

---

## 10. Known gaps and honest notes

* **Nothing of 0.5.x has been seen in Skyrim.** The follower work is the least proven part: no
  playtest has ever had a real follower present, and its eight test scenarios
  (`../research/pt9-followers-build.md` section 8) are all unknown.
* **[v1.0] Menuless questing v1.0 has not been seen in Skyrim.** The first evening (`OWNER_MENULESS_V1.md` section 3)
  is the proof: the first real click, the route proof, a driven click inside a journal scene. Known offline limits:
  two quest lines (Balgruuf's "I need to talk to you about Helgen", Arngeir's "I am answering your summons") ask once
  when paraphrased, because their own topic carries a version that ends the talk (`tools/test_questline.php` asserts
  exactly that since the 2026-09-25 ruling); a ride, a crossing or a lesson opens a driver's, ferryman's or trainer's
  list before her line only when her snapshot's `fac=` carries a job faction of `open.kind_factions` (verified in the
  plugins, not yet on a live snapshot); the stage rail's corner note is sent as `kind=rail`, once per conversation, and
  "Tell me when she has something" does not silence it. **A quest line said to someone who is not part of that quest opens nothing** (U10): the
  verbatim "Where can I learn more about magic?" to a passer-by who is no MG01 alias gets words only - clause 4 accepts
  only a row her list can carry (walkthrough W.open.anyone). The owner page tells him: press E on her and, if the line
  is on her list, say it.
* **[v1.0] What CHIM does around the glue, as far as it is known** (`../research/pt19c-chim-brief.md`; PROTOCOL 10.6,
  10.29 sections 4, 6, 7, 13): the pre-LLM open rides D2 only, and its latency mid-request is unproven; the bridging
  word's mute is a text-time race, so the audible order at first contact is unproven; a game refusal of a pick is a
  corner note, and a loop failure reaches her words only on his next turn (CHIM never voices a SelectTopic result);
  every click's funcret costs a MAIN-lock slot and a prompt build; the talk key is CHIM's own push-to-talk (a tap
  stops every CHIM line, a double tap makes the crosshair NPC wait); the microphone is off while a trade, gift or
  training window is open. The first evening's checks are listed in PROTOCOL 10.29 section 13 and need the session's
  `AIAgent.log`.
* **[v1.0] Never-false does not judge a promised bonus while gate B is off** (`lrg_replies.php` ~712: the `reward`
  row exists only while `checks.reward.enabled`): a spoken "a hundred septims from my own purse" on a quest turn is
  held back only by the prompt. The owner page says so; the fix belongs to Lane B / the spec owner (PROTOCOL 10.29
  section 8).
* **`cap` / `slot` / `prim` have never been seen on the wire** - the SFF lookup was broken for the
  whole of 0.5.1 until the fix pass, so the slot facts and the cap-full hide rule are unexercised.
* **The latency claim is ~33 s of blocking work per nine-hour session**, not the ~133 s an earlier
  report gave. Two prompt trims that shipped with it were taken back out because they narrowed what
  she may choose. Net effect on prompt size: zero.
* **CHIM has no fact verifier.** `lock_profile` and `relationships_locked` are write protection on an
  authored profile, not "facts the AI may not contradict". The real mechanism is the action catalog's
  `requirements`, which the glue now declares; the truth gate sits on top.
* **A latency line only exists if CHIM voices the player's own line** - with player TTS / re-speech
  off there is no zero to measure from and no line appears. `dlg self-test chim tts=` says which.
* **Two upstream data bugs, reported and not touched**: Little Lessons permanently lowers the five
  Speech difficulty globals on every dialogue menu with a married NPC when the hardest is >= 100
  (the log's `drift=` measures it); four orc-follower check topics sort their success line after an
  unconditional failure line.
* **`CBPCSystem.ini`: `Logging` is back to `0` since pt15** - no value differs from CBPC's; the file is kept
  so `OVERRIDES.md` stays true and the next physics knob is one edit away.
* **Three `.psc.bak-docstrings` files** (164 KB) sit in `Source/Scripts` and would be carried into the
  mod folder by a full `robocopy /E` install. Inert; delete before the next full install if unwanted.
* **A scratch database `lrg_t`** exists on the server from an index migration test; nothing uses it.
* **If the server is ever rolled back to 0.4.1**, move the index back first
  (`ALTER TABLE lrg_index.lrg_prompt SET SCHEMA public;` and the same for `lrg_prompt_layer`),
  or 0.4.1 runs unindexed. Playthrough tables never moved. The other rollback switch is `bDlgWire`:
  turn it **off** before putting an older server back.
* **Eight NPCs are outside the body work's reach** (their overhauls point at a mismatched `.tri`), so
  OBody and RaceMenu morphs are inert for them. They do get the restored jiggle.
* Older 0.1.x-0.3.x notes that are still true - beast races and FF pairs seeing fewer scenes, "pull
  out" not existing in any installed pack, positions keyed by display name, the compile's declaration
  stubs - are kept in `PROTOCOL.md` and the per-round reports rather than repeated here.
