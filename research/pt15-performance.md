# pt15 - performance lane: logging off, log levels, cadence, MCM parse fix

LoreRim Glue, PERFORMANCE lane, 2026-09-23 ~05:10. Scripts are still `CurrentVersion = 507` (not bumped here - the ship
step owns the version). **Installed** by hand-copy into `F:\Modlists\LoreRim\mods\LoreRim Glue`, 10/10 files SHA-256
verified, SkyrimSE not running, MO2 running (no profile file touched), nothing in `overwrite` shadows them.
`tools\compile.ps1` -> `OK ... 0 errors, 0 warnings`. `test_mcm_wiring.php` 3/0 (run on a WSL copy). No server file changed,
so the server suites/flows were not re-run.

## 0. The short version - what was actually slowing the game

| # | cost | size of it | status |
|---|---|---|---|
| 1 | **CBPC collision logging** (`Logging = 1`, left on since the pt8 bounce test) | `SKSE\CBPC-Collision.log` = **710 MB** after one 25-min session - writes on every physics frame | **fixed**: back to `0` (installed) |
| 2 | **Papyrus logging** (`bEnableLogging/bEnableTrace/bLoadDebugInformation = 1` in `profiles\Ultra\Skyrim.ini`, set by us for debugging) | `Papyrus.0.log` 155,197 lines in 24 min (6,500/min at the end), plus a VM freeze + stack dump every ~62 s | **owner step** (MO2 is running) - section 4 |
| 3 | **The old saves carry a dead copy of the glue quest** (form `FECF6800`, the 0.5.4 deadlock, see pt12) | every crosshair change adds **2 suspended Papyrus stacks** that never run; 1,191 were waiting at 04:45 (513 + 515 crosshair, 56 text, 55 + 34 speech ...). They are written into every save and grow across sessions (01:44 already ended with 424 + 426) | **start a NEW game** for the real playthrough - no script can drain them |
| 4 | **The LoreRim Glue MCM never loaded** | `SKSE\MCMHelper.log`: `Failed to parse config for LoreRimGlue` on all 84 registrations - the menu showed MCM Helper's error page | **fixed**: UTF-8 BOM removed from `config.json` (installed) |
| 5 | the glue's own logging | ~7 GAME lines/min, each = Trace + TraceUser + 3 MCM reads + a server request | **cut**: log level, default Normal (section 2) |
| 6 | ev=facts on crosshair | 70 lines, median **823 ms** of script time each (334-1480) | **cut**: settings cache 5 -> 30 s on this path, one quest sweep instead of two (section 3) |

Row 4 detail: MCM Helper reads `config.json` with RapidJSON's plain `FileReadStream` + `Reader::Parse`, which does not skip
a byte-order mark. Ours was the only `config.json` in the whole mod list with a BOM (pt13 had "preserved" it). Settings kept
working because `settings.ini` goes through a separate store - only the menu was broken.

## 1. Physics INIs (task 1)

* `SKSE\Plugins\CBPCSystem.ini`: `Logging = 1 -> 0`. Every key=value now equals CBPC's own file (checked key by key);
  header and the `OVERRIDES.md` row rewritten to say so. File kept, not deleted, so the register stays true.
* `SKSE\Plugins\AutoPhysicsReset.ini`: `[Debug] bEnableLog = false` already - unchanged (its log is 206 bytes).
* The 710 MB `Documents\My Games\Skyrim Special Edition\SKSE\CBPC-Collision.log` was **not** deleted (owner's call - it is
  only disk space now; CBPC writes just its load lines from the next start).

## 2. Glue log level (task 2)

**Measured.** Server `lorerim_glue.log`, GAME lines: 01:32-01:44 = 97 lines (~7.5/min), 04:24-04:45 = 150 lines
(~6.8/min). Breakdown of 04:2x: facts 49, conv hold 38, CALIB 7, command 6, result 6, listener 5, others 1-4.
`Papyrus.0.log`: 169 `[LRG]` lines in 24 min (~7/min) - about 0.1 % of that log; the rest is the stack dumps of row 3 and
other mods. There was **no 03:5x session**: the logs hold 01:31-01:44 (`Papyrus.1.log`, 73,698 lines, 11 dumps) and
04:22-04:46 (`Papyrus.0.log`, script 507).

**New MCM setting `iLogLevel:General`** ("How much to log", General page; `settings.ini` default `1`):
0 = errors only, 1 = normal (for play), 2 = everything.

| level | lines |
|---|---|
| 0 (always, and always `Debug.Trace`d) | `BOOT STEP LATE/LOST`, `BOOT STALL`, `BOOT LATE`, boot event not sent, module not attached, MCM Helper has no settings, `SNAPSHOT LOST/LATE/sixth`, every **refused result** (`result X: Error ... [why: ...]`), `open failed`, `WARNING ...` (speech-global drift, unhide failed, hand keeps filling), OStim undress gates missing, OStim start failed immediately, Remove-Weapons unreadable, `NOTE these 0.4 settings read OFF` |
| 1 (normal) | everything not in row 0 or 2: version/maintenance lines, dlg self-test, commands, successful results, scene/undress/escort/follower lines, `voice of X at open`, arming/closed/SVC, `LAT SLOW`, `CALIB set/armed/WARNING` |
| 2 (chatter) | `conv hold` (on/refresh/keep/release/skip), `facts npc=`, `initiative tick`, `listener forced/released`, `LAT npc=`, `prep ... rounds=`, `lead request`, `CALIB SUMMARY/GATE/probe/X1/active` |

Implementation (`LRG_Main.psc`): `LogC` = level 1, new `LogV` (2) and `LogE` (0), one writer `LogAt`. The level and
`bDebugLog` are **cached members** (`LogRefresh()` at boot step 11 and on every 30 s heartbeat): a line no longer costs any
MCM read, a dropped line costs one compare, a level change applies within 30 s. `Debug.Trace/TraceUser` only at level 2 or
for level 0. The 0.5.4 rule holds (nothing reads the MCM before a trace). `BootAnnounce` (the version line) still traces
unconditionally. `LRG_DlgProbe.psc` was not edited: its periodic CALIB lines are demoted by prefix inside `LogC`.
Expected at level 1: ~2-3 lines/min instead of ~7 (facts + conv hold were 58 % of all lines). The `bLatencyLog` help
text now says per-reply `LAT` lines need level 2; a slow reply is still logged at level 1. No server code parses any of
the demoted lines (checked).

## 3. Cadence inventory (task 3)

| what | trigger / cadence | native cost | verdict |
|---|---|---|---|
| `LRG_Main.OnUpdate` heartbeat | every 30 s; re-registers crosshair + 5 mod events every 4th beat | a few compares; now +2 MCM reads (`LogRefresh`) | fine |
| `LRG_OStim.Tick` | only while a scene / outro / listener / park is live (0.8 s pushes, 5 s duties); idle = a few bool tests | low | fine |
| `SnapTick` | every 20 s while watching an agent (looked at, or spoke in the last 90 s) | one snapshot | fine |
| `MaybeSnapshot` (crosshair, every CHIM sentence, text) | floors: 2 s global, 10 s per NPC, 5 s per last miss | `BuildSnapshot` ~20 actor natives + `WitnessScan` (up to 80 `GetDistance` + 40 x ~10 natives incl. 2 `HasLOS`) + door scan (cached 15 s / 200 u) + rented-bed scan (120 s per cell) | already "witness scan at most once per 2 s" via the global floor; only forced snapshots (scene end, bed change, load) bypass it - left alone |
| `LRG_Dialogue.OnCrosshairRefChange` -> ev=facts | 60 s per NPC (8-slot ring), CHIM agents only, never inside a session | **was** ~35 MCM reads (settings, 5 s cache) + 2 x `GetActiveAssociatedQuests` + objectives + aliases + perks (30 s cache) + crime; median 823 ms | **cut**: settings cache 30 s on this path only (sessions keep 5 s); one quest sweep feeds both `q=` and `qobj=`. Identical wire output |
| `VkSweep` | at most once per 30 s on a form tick while idle | `findAllNearbyAgents` + up to 24 x 2 natives | fine |
| menu session loop (`RunSession`) | only while a Dialogue Menu is open: poll 0.1 s (0.5 s parked/manual) | UI reads per poll | untouched - it IS the menuless feature; the passive calibration rides the arming and costs nothing extra |
| `LRG_DlgProbe.OnUpdate` | every form tick; returns on `!cShotBusy` | 1 compare | fine |

"No facts sweep for NPCs with no dialogue": already true - non-agents return before any sweep, and an agent with no quests
costs one po3 call that returns empty. The only observable difference: an MCM change to menuless / quest awareness can
take up to 30 s (was 5 s) to reach the crosshair facts path.

## 4. OWNER STEP - Papyrus logging off (task 4)

MO2 was running the whole time, so this was **not** done. Current `profiles\Ultra\Skyrim.ini` lines 186-189:
`bEnableLogging=1`, `bEnableProfiling=0`, `bEnableTrace=1`, `bLoadDebugInformation=1` (LoreRim's originals were all 0,
per `research\lorerim-scan.md`).

1. Close Skyrim and **close MO2**.
2. Copy `F:\Modlists\LoreRim\profiles\Ultra\Skyrim.ini` to `Skyrim.ini.bak-pt15` in the same folder.
3. Open `Skyrim.ini` in Notepad, find `[Papyrus]`, change three lines to `bEnableLogging=0`, `bEnableTrace=0`,
   `bLoadDebugInformation=0`. Leave everything else. Save, reopen MO2.

(Or tell Claude once MO2 is closed and it will do exactly this, with the backup.) Afterwards
`Documents\My Games\Skyrim Special Edition\Logs\Script\Papyrus.0.log` keeps its old contents from 04:46 - it is simply no
longer written, so it says nothing about the new playthrough. The glue's own log still reaches the server
(`lorerim_glue.log`), which is what diagnostics use.

## 5. TTS (task 5)

`/home/dwemer/audio.cpp/server.json`: `"backend": "cpu"`, `"threads": 8`, model `pocket-tts` (voice `alba`, 16 voice-state
cache slots). `curl http://127.0.0.1:8086/health` -> `{"status":"ok","backend":"cpu","models":1}`. So yes: TTS runs on
**8 CPU threads** next to the game (32 logical cores; RTX 4080 at 1.6 GB used / 1 %). STT (parakeet) also runs
`--cpu --precision int8`. Moving either to the GPU is the voice/STT lanes' call; noted because on CPU each reply's
synthesis competes with the game's threads for those seconds.

## 6. Files changed (all installed, SHA-256 verified)

`Scripts\LRG_Main.pex`, `LRG_OStim.pex`, `LRG_Dialogue.pex` + their `Source\Scripts\*.psc`;
`MCM\Config\LoreRimGlue\config.json` (BOM removed, `iLogLevel` control, `bDebugLog` / `bLatencyLog` help);
`MCM\Config\LoreRimGlue\settings.ini` (`iLogLevel = 1`); `SKSE\Plugins\CBPCSystem.ini`; `OVERRIDES.md` (CBPC row, BOM note).
The other seven `.pex` were rebuilt by compile.ps1 from unchanged sources and were not copied.
Note: after my install (05:10) the physics/body lane went on editing the project `OVERRIDES.md` (CBPConfig rows, a textures
section) on top of my version - both pt15 edits are still in it. The installed copy is mine; that lane installs its own.

## 7. For the owner / other lanes

* **Real playthrough = new game.** Every current save carries the dead `FECF6800` quest instance and its growing queue of
  stuck events (section 0 row 3).
* The LoreRim Glue MCM opens for the first time (12 pages). "How much to log" = **Normal** is the play setting; switch to
  **Everything** only while diagnosing.
* Any tool that rewrites `config.json` must write UTF-8 without BOM (PowerShell 5.1 `Set-Content -Encoding UTF8` adds one).
* Version is still 507; if the ship step bumps it, nothing here depends on the number.
