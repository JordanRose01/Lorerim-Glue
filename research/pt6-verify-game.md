# pt6 verify - GAME SIDE (independent verifier)

Date: 2026-09-21. Read-only except this file. Nothing was deployed, installed or modified.
Hard rules honoured: no `research/*.md`, `PHASE*`, `REVIEW*`, `REFINE*` or old PLAYTEST notes were
read. Sources used: the round prompt, `glue/PROTOCOL.md` (v0.3), `glue/V03_DESIGN.md`,
`glue/OWNER_ADDENDA.md`, the Papyrus / PHP / MCM sources themselves, the installed CHIM sources
(`F:\Modlists\LoreRim\mods\CHIM\Source\Scripts`) and `tools/compile.ps1`.
The critic amendments live in `research/pt6-design-critique.md`, which the hard rules forbid me to
read; I verified the amendments named in the round prompt (`ev=sync` removal, `fSayFirstSettle`)
against `PROTOCOL.md`, which documents both, and against both sides of the code.

**VERDICT: FIX FIRST** - two majors (one is a two-line server fix, one a game one-liner),
eight minors. Nothing found weakens the crash fix; compile is clean.

---

## 1. Checks that PASSED

| check | result |
|---|---|
| `tools\compile.ps1` | `rounds: 2 ; auto-stubs created: 23` / `compiled: LRG.pex, LRG_Main.pex, LRG_MCM.pex, LRG_OStim.pex, LRG_PlayerAlias.pex, LRG_Profile.pex` / **`OK - .pex files copied ... 0 errors, 0 warnings`**, exit 0 |
| script version 300 | `LRG_Main.psc:15` `int Property CurrentVersion = 300 AutoReadOnly`; logged by `Maintenance()` (`:129`) |
| `config.json` valid JSON | parses with `ConvertFrom-Json`; **35** setting ids (the game build report says 34 - a miscount, every id checks out) |
| MCM ids <-> `settings.ini` | all 35 ids have a default line, correct section, correct prefix/type: `b*`->`SettingBool`, `f*`->`SettingFloat`, `i*`->`SettingInt`. New ones present: `bAnnounceWait`, `bForceListener`, `fLeadHold=150`, `fSayFirstWait=6`, `fSayFirstMaxWait=12`, `fSayFirstSettle=1`, `fSceneLeadInterval=75` |
| in-code defaults == ini defaults | verified for all 35 (e.g. `fSceneLeadInterval:SceneTalk` 75.0 at `LRG_OStim.psc:3200`; `fSayFirstSettle` 1.0 at `:509`) |
| single OnUpdate tick | only `LRG_Main.psc:322/325` register/handle; `LRG_OStim` has no `OnUpdate` and no `RegisterForSingleUpdate`. `RequestTick` still earliest-wins (`LRG_Main.psc:310-323`); `Tick()` fans out and every new state (`pendingVerb`, `pendingStart`) re-arms itself with `RequestTick(0.5)` |
| crash fix intact on every exit path | `HoldOStimGates` at `CmdStart:993-995`; `ReleaseOStimGates` reached through `RestoreWeapons:1555-1557` on: prep failure (`:1006-1012`), no-furniture-no-scene (`:1020-1029` and `:1079-1086`), builder `-1` (`:1099-1104`), `tid<0` (`:1131-1139`), partner gone (`:1062-1068`), abortStart while parked (`:1151-1159`), start timeout (`:2899-2907`), load with no thread (`:315-317`), foreign thread after load (`:288`), and directly in `FinishThread:2834`. `GlueUndressNode` unchanged and still gated by `gateHeld`/`startNoUndress`/`gatePrevMid`. `BeginStartThread` adds an extra `ClearHands` pass (`:1090-1093`) - strictly stronger, as claimed |
| `nowarp=1` | `goto` honours it first, before `bAllowWarp` (`:2307-2314`) = "NPC-led never warps, a player request may". `CmdFurniture` never reads it and `ChangeFurniture` has no warp path - inert as the build report claims |
| G4 load handling | `LRG_Main.Maintenance` re-rolls `sessionTag` (`:79`) **before** `ost.Maintenance()` (`:114`), then forces the crosshair snapshot (`:123-128`). `LRG_OStim.Maintenance` no-thread branch sends `ev=end;npc=..;cid=..;scene=;byglue=..;sess=..` (`:312`) before `ResetState`; running branch clears `lastSentKey` and pushes `ev=change` with `sync=1` (`:296-300`). Matches PROTOCOL §1.2 [0.3] (no `ev=sync`) - both builders followed the same amendment |
| `sess=` in the snapshot | `PlaceFacts` appends it last of the place facts (`LRG_Main.psc:719`), `BuildSnapshot` inserts the block before `class` (`LRG_Profile.psc:347-348`), `fac` stays last |
| lead hold | `ApplyHold` clamps 0..600, monotonic, `hold=` without a number falls back to `fLeadHold` (`:431-456`); `MaybeLead` returns while `afNow < leadHoldUntil` (`:3197`); cleared in `ResetState:329` and `Maintenance:251`; survives `MarkDirty` |
| lead tick preconditions | `MaybeLead:3184-3220` still refuses on `starting`, `navInFlight`, `windDown`, `pendingVerb`, `leader != npc`, `lastAuto`, transition, `isActorTalking` - matches PROTOCOL §1.4 |
| addendum 3 (no in-scene refusal) | nothing on the game side implements a decline, a refusal marker or a per-act memory - correct, nothing to remove |
| old server (v0.2.0) | `ApplyHold` returns on a missing `hold` (`:437`), `AnnounceBudget` returns 0 unless `wait` is exactly `begin`/`end` (`:461-464`), `nowarp` missing = today's warp policy. New keys the game *sends* (`sess`, `prev`, `sync=1`) ride inside an existing `ev`, so an old server just stores them. Behaviour with an old server = v0.2 behaviour, as the matrix requires |
| parked commands always answered | `DropPendingVerb` reports a funcret + `commandEndedForActor` on every drop (`:587-601`), and is called from `FinishThread:2837`, `StopScene:2646`, the stop verb (`:2212`, `:2234`) and `TickPendingVerb:681` |

---

## 2. Defects

### MAJOR 1 - "you lead" / "surprise me" now *silences* her for 150 s (side: both; fix in the server)
G2 names this as a control the player has. It does the opposite.

- `lib/lrg_intent.php:247` recognises `lead_npc` -> `do=lead;who=npc`.
- `lib/lrg_actions.php:1222-1241` `lrgDecorate()` appends `hold=<lead.hold_seconds>` (150) to **every**
  non-START command on a player-speech turn, with no exception for `do=lead;who=npc`.
- The game cannot tell that command from the §9 hold carrier (identical kv shape, built at
  `lrg_actions.php:1363`), so `CmdLead` clears the hold and immediately re-applies whatever `hold=`
  arrived: `LRG_OStim.psc:2592-2593` `leadHoldUntil = 0.0` / `ApplyHold(asParam)`.
- Net effect: the player hands her the lead and `MaybeLead` (`LRG_OStim.psc:3197`) refuses for 150 s.
  The game's lead tick is the only source of `lrg_scenetalk "lead"`, so the server-side fix already
  present at `lrg_actions.php:266-268` (which zeroes `player_request_at` / `player_spoke_at` for
  exactly this intent) never gets a chance to matter.

This is the hand-over the game build report asked for and the server build did not implement.

**Smallest fix (server).** In `lrgDecorate()`, before the hold block:
```php
if ($do === 'lead' && (string) ($kv['who'] ?? '') === 'npc') { return $kv; }   // the player handed her the lead
```
The carrier builds its own kv and is unaffected. No game change, no wire change.

### MAJOR 2 - the no-op hold carrier cancels a running wind-down (side: both; fix in the game)
- `lrgHoldCarrier()` (`lib/lrg_actions.php:1351-1366`) fires on any player speech turn inside a scene.
  `scene_blocked` is only set by the rail re-check (`lrg_actions.php:619`); a wind-down does not set
  it and `can_act` is true, so a wind-down is not excluded.
- The carrier is `do=lead;who=<current leader>`, and `CmdLead` cancels the wind-down unconditionally:
  `LRG_OStim.psc:2595` `CancelWindDown("the lead changed")`.
- Failure: the player says "let's wind down" -> `wd=1`, speed 0, 20 s linger -> the player says
  anything at all during the linger -> the wind-down is aborted (`windDown=false`, `wdStopAt` never
  consulted again, `ThreadTick:3005-3007` falls through to `MaybeLead`) and the scene simply runs on.
  Throttled to once per 75 s by `hold_sent_at`, so it needs a quiet stretch first - which is exactly
  what a wind-down follows.

**Smallest fix (game).** In `CmdLead`, only cancel when the lead really changes:
```papyrus
if who != leader
    CancelWindDown("the lead changed")
endif
```
(Server alternative: return `null` from `lrgHoldCarrier()` when `($turn['scene']['wd'] ?? '0') === '1'`.
The game fix is preferable because it also holds against an old server and against a stray `do=lead`.)

### MINOR 3 - `command_confirm_seconds = 20` is below the game's worst case: the G6 watchdog will cry wolf
`config/lrg_config.default.json:221` sets 20; its own readme says "it must stay above the game's own
say-first wait budget". A `StartIntimacy` funcret is only emitted at `ostim_thread_start`
(`LRG_OStim.psc:2684`), after: sheathe loop up to 3 s (`:1413`), `SettleWeapons` up to 4 s (`:1435`),
the announce park up to `fSayFirstMaxWait` = 12 s (`:1043`), then OStim's asynchronous start. A normal
announced start therefore exceeds 20 s routinely, and `lrgCommandWatchdog()` (`lrg_actions.php:838-842`)
writes `WARN command not confirmed by the game` for a command that was perfectly fine. No retry, so the
only damage is a diagnostics channel that is wrong by construction - which is what G6 exists to avoid.
The game build report's hand-over asked for >= 32.
**Fix:** `"command_confirm_seconds": 40`.

### MINOR 4 - `CmdLead` breaks the monotonic-hold guarantee PROTOCOL states
`PROTOCOL.md` §2 ("the hold carrier"): *"the game's `leadHoldUntil` is monotonic, so a skipped carrier
costs nothing"*. `LRG_OStim.psc:2592` zeroes it. With today's server numbers the re-applied deadline
always lands later, so nothing breaks now; it is a latent trap for any future carrier that carries a
shorter hold than the one already running (e.g. a proposal with 30 s left arriving after a 150 s hold).
**Fix:** subsumed by MAJOR 1 - once `do=lead;who=npc` from the player carries no `hold=`, the clear is
the hand-over and the re-apply can go. Alternatively give the hand-over its own key (`hold=0`).

### MINOR 5 - a parked start is not abandoned when a foreign OStim thread starts during the announce wait
`OnOStimThreadStart` (`LRG_OStim.psc:2663-2698`) clears `starting` and reports success, but never clears
`pendingStart`. If OStim's own menu (or another mod) starts thread 0 inside the up-to-12 s announce
window, the next `Tick` runs `TickPendingStart` -> `BeginStartThread` -> `OThreadBuilder.Create` on
actors already in a thread -> `-1` -> a **second** funcret for the same command (`:1099`) and
`ResetState()`, which drops the glue's tracking of the thread that is actually running. `RestoreWeapons`
then skips `ReleaseOStimGates` (`:1555`, a thread runs), so OStim's two undressing globals stay at 0
until the next scene or a load.
**Fix:** at the top of `OnOStimThreadStart`, before the `if starting` branch:
```papyrus
if pendingStart
    pendingStart = false
    pendingStartFurn = None
    starting = false
    Main().ReportResult(partnerName, startCmd, startParam, "Error: a scene is already running")
endif
```
and let the existing `else` branch adopt the thread.

### MINOR 6 - an in-scene clothing command that changes nothing still reports success
`SceneUndress` (`:1844-1853`) calls no native when `ScenePartMask` is 0 (she wears nothing on the
requested part), and `PerformClothing` (`:2150-2157`) still answers `"<npc> undresses."`. The lens asks
that a clothing command in a scene never silently do nothing; this is worse than a refusal, because the
funcret tells the server (and G1's safety-net bookkeeping) that the request was carried out.
**Fix:** make `SceneUndress` / `SceneRedress` / `DressPart` return a count and, when every requested
actor produced 0, answer with the existing closed-list reason `Error: not possible in this position`
so the player gets the corner note instead of a false success.

### MINOR 7 - `FinishThread`'s `ev=end` omits `sess=`
`LRG_OStim.psc:2843` sends `ev=end;npc=..;cid=..;scene=..;byglue=..` with no `sess`, while
`PROTOCOL.md` §1.2 [0.3] lists `sess` as "every push" and the load-path `ev=end` (`:312`) does carry it.
Harmless today (`ev=end` closes the row regardless) but it is the one `lrg_scene` message without a
session tag, and it is a stated-contract mismatch.
**Fix:** append `+ ";sess=" + Main().SessionTag()`.

### MINOR 8 - `ApplyHold` runs before `CmdControl`'s partner / adult / dry-run checks
`LRG_OStim.psc:2227` applies the lead hold, then the command can still be refused with
`a scene with someone else is running` (`:2247`), `adults only` (`:2252`), dry-run (`:2259`) or
`unknown scene request` (`:2336`). A refused or stray command therefore silences her lead for up to
600 s.
**Fix:** move `ApplyHold(asParam)` down, below the `IsPartnerName` / adult checks (it must stay after
the `stop` fast path, which needs no hold at all).

### MINOR 9 - `wait=end` may be satisfied in the gap between two of her sentences (could not be confirmed)
`AnnounceState` (`:481-519`) accepts "her line is out" on `partnerSpeakStop > partnerSpeakStart` +
`isActorTalking()==0` + `fSayFirstSettle` (1.0 s). But `CHIM_SpeechStarted` / `CHIM_SpeechStopped` are
per **sentence** - the installed sources say so themselves (`AIAgentAIMind.psc:1707` "every sentence",
`:1788` same, `:1843` `EndDialogue` "after NPC stops speech"). Whether `isActorTalking` spans a whole
multi-sentence reply is a DLL detail that cannot be read from Papyrus; CHIM's own wait loop
(`AIAgentAIMind.psc:3143-3155`) also treats one `false` as "done". If it is per sentence, OStim's intro
fade can still land after her first sentence whenever the inter-sentence gap exceeds the settle.
Mitigation is in place (`fSayFirstSettle` 0-3 s) and `LogWait` makes it measurable.
**Fix:** none before playtest 7 - but read the `announce wait=end ... reason=` lines and raise
`fSayFirstSettle` if scenes still begin mid-reply.

### MINOR 10 - the whole announce gate rests on "the command arrives before her TTS", which is unverifiable offline
`SendExternalEvent` (`AIAgentAIMind.psc:1395`) has **no Papyrus caller**: the SKSE DLL dispatches
`CHIM_CommandReceived`, so the ordering against TTS playback cannot be checked from source. The design
asserts it (§8.4, "game audit F5"); I could not confirm it. If the command in fact arrives after her
line has finished, `AnnounceState` never sees a start after `cmdAt` and **every** self-initiated move
is delayed by the full budget before happening anyway: 6 s for goto / furniture / clothing, 12 s for a
scene start (plus the ~6.5 s weapon prep that already precedes it). Nothing is lost; it would just feel
laggy - and it would also be the cause of MINOR 3's false WARNs.
**Fix:** none now. `LogWait` was built for exactly this: in playtest 7, `reason=spoke` means the design
holds; a run of `reason=timeout` means the ordering assumption is wrong and `wait=` should be dropped
to `begin` everywhere (or `bAnnounceWait` turned off) until CHIM's ordering is instrumented.

---

## 3. Build-report claims I could not confirm, and what I did instead

- **"No in-game verification"** - correct and unavoidable; the announce gate, the listener lock
  (`setDrivenByAIA`) and the load sync are compile-verified only. `setDrivenByAIA` /
  `setDrivenByAI` exist as declared in `AIAgentFunctions.psc` and are used by CHIM's own crosshair
  hotkey, so the call is legitimate; its side effects remain the round's named unknown, and
  `bForceListener:Intimacy` is the escape hatch as designed.
- **"all 34 setting ids"** - there are 35. Every one of them checks out; the count in the report is
  simply wrong.
- **`ev=sync` deviation** - confirmed consistent across `PROTOCOL.md` §1.2, the game
  (`LRG_OStim.Maintenance`) and the server (no `sync` `ev` handler); no phantom-row risk on an old
  server.
- **`command_confirm_seconds >= 32` hand-over** - see MINOR 3: not implemented.
