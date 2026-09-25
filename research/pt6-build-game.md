# pt6-build-game - GAME-SIDE BUILD of LoreRim Glue v0.3

Status: **DONE**. `tools\compile.ps1` prints `OK` (0 errors, 0 warnings), all six `.pex` rebuilt.
NOT installed - a later step does that (`install_mo2.ps1` refuses while MO2 / Skyrim run).

Owned and touched by this agent, nothing else:

- `glue\game\LoreRimGlue\Source\Scripts\LRG_Main.psc`
- `glue\game\LoreRimGlue\Source\Scripts\LRG_OStim.psc`
- `glue\game\LoreRimGlue\MCM\Config\LoreRimGlue\config.json`
- `glue\game\LoreRimGlue\MCM\Config\LoreRimGlue\settings.ini`
- (build output) `glue\game\LoreRimGlue\Scripts\*.pex`

Inputs read: `glue\OWNER_ADDENDA.md` first, then `glue\V03_DESIGN.md` §0-§2, §8.4, §10.3, §14, §15,
§17, §18, the critic amendments carried in the round prompt, the installed CHIM sources
(`AIAgentFunctions.psc`, `AIAgentPapyrusFunctions.psc`, `AIAgentAIMind.psc`) and the two glue
scripts. No `research\*.md`, no `PHASE*`, `REVIEW*`, `REFINE*` or old playtest notes were opened.

---

## 1. What was built, item by item

### §14.1 One number (G2)
`settings.ini` `fSceneLeadInterval = 45` -> **75**; the in-code fallback in `MaybeLead` raised to
`75.0` so a missing MCM Helper cannot reintroduce 45; `config.json` help rewritten (it now also
says she always speaks for anything she starts herself, and which way to move the slider).

### §14.2 The two `CmdClothing` seam bugs (+ the third guard)
1. **`glueStripped` is now cleared on `dress`.** New `NoteSceneStrip(Actor, part, bool)`: an
   in-scene `undress part=all` sets that actor's bit (the ladder need not strip twice), an in-scene
   `dress` clears it, so a later full-strip node undresses her again. Guarded by `gateHeld`, i.e.
   it only touches the mask while the crash-guard actually holds OStim's gates.
2. **The mask is keyed on the thread POSITION.** New `StripBitFor(Actor)` uses
   `OThread.GetActorPosition(0, actor)`; `GlueUndressNode` now derives both the bit *and* the
   metadata slot it queries (`FindAnyActionForActorCSV` / `ForTargetCSV`) from that position
   instead of the `GetActors()` array index, so a swapped order cannot make the glue undress the
   wrong actor or test the wrong role. **`HoldOStimGates` / `ReleaseOStimGates` / the fullStrip
   lists / the `startNoUndress` and `gatePrevMid` respect rules are untouched.**
3. **`CmdClothing` now prefers the partner it already holds** when `IsPartnerName()` is true and a
   thread is running or starting; `getAgentByName` is only the fallback. Removes the spurious
   `Error: the actor could not be found` inside a scene the glue runs.
4. **The 2 s staleness guard** (design §14.2.3): `sceneEndedAt` is stamped in `FinishThread`; an
   out-of-scene `undress` that arrives within 2 s of a scene ending is answered
   `Error: no scene is running` instead of falling into the privacy branch and coming back as
   `someone is watching`.

### §14.3 `hold=` and the lead hold (G2)
- New `float leadHoldUntil`, plus `ApplyHold(param)`: one `ParamGet`, clamp `0..600`,
  `leadHoldUntil = now + n`, **monotonic** (a shorter hold never shortens a longer one, so the
  server may throttle its carrier). A value that is present but not a number falls back to the new
  MCM `fLeadHold:SceneTalk`; a **missing key does nothing at all** (old server = today).
- Applied once in `CmdControl` (after the `ok=1` check, so every verb carries it) and once in
  `CmdClothing`. `CmdLead` with `who=npc` **clears** the hold first and re-applies whatever `hold=`
  the same command carried - so "you lead" / "surprise me" really frees her, while the §9 carrier
  (`do=lead;who=<current leader>;hold=150`) still holds.
- `MaybeLead` returns early while `now < leadHoldUntil` (and also while a verb is parked).
- `leadHoldUntil` survives `MarkDirty` (it is never written there) and is cleared by `ResetState()`
  and `Maintenance()`.
- Meaning fixed as the critic asked: **`hold=<n>` = "do not take a lead turn for the next n
  seconds"**, documented in the script header and the MCM help.

### §14.4 Load / maintenance sync (G4) - **amended: no `ev=sync` on the wire**
- **No-thread branch**: when `partnerName` survived the save, `LRG_OStim.Maintenance` sends
  `ev=end;npc=<partner>;cid=<startCid>;scene=;byglue=<0|1>;sess=<tag>` **before** `ResetState()`.
  An old server closes the row on `ev=end` exactly as it does today; a new one also learns `sess`.
  The nameless case is covered by the forced crosshair snapshot (below), never by a nameless
  scene message.
- **Running branch**: `lastSentKey = ""` (so the first push after a load is never de-duplicated
  away), `lastPosScene` re-seeded, then an ordinary `PushState("change", "sync=1")`. `sync=1` is a
  plain additive key on an `ev=change`; an old server just refreshes its row.
- **`LRG_Main.Maintenance`**, right after `ost.Maintenance()`: one forced snapshot of the crosshair
  actor (`MaybeSnapshot(look, true)`, then `Watch()` only if it really went out). Name-free, and it
  carries `ostim=0` and the new `sess=`.
- **`sess=`** added to `PlaceFacts` (last of the place facts = immediately before `class`, `fac`
  stays last) via the new `LRG_Main.SessionTag()`, and to every `lrg_scene` push (right after
  `cid=`).
- **`prev=`** added to the `ev=change` push: new `lastPosScene` / `prevPosScene` pair maintained in
  `OnOStimSceneChanged` (transitions skipped, a repeat of the same id is not a change), seeded at
  thread start and on adoption.

### §14.5 `wait=begin|end` - the announce gate (G3a / R10) - **with the critic's stricter `end`**
- `AnnounceBudget(param)`: `0` when the key is absent, when `bAnnounceWait:Intimacy` is off, or
  when the owner's slider is 0. `begin` -> `fSayFirstWait` (6 s), `end` -> `fSayFirstMaxWait`
  (12 s), both clamped to 30 s. **Both budgets end in "do it anyway".**
- `AnnounceState(name, now, since, needEnd)`:
  - `begin`: satisfied by a `CHIM_SpeechStarted` **after** the command, or by
    `isActorTalking(name) != 0`.
  - `end` (amendment M7): needs, in order, (1) a `CHIM_SpeechStarted` **strictly after the command
    arrived** - the "already talking" shortcut is deliberately NOT available, because it could
    latch the previous reply's last sentence; (2) a `CHIM_SpeechStopped` after that start;
    (3) a quiet settle of **`fSayFirstSettle:SceneTalk`** (new key, default 1.0, range 0-3) during
    which `isActorTalking()` is 0. Any new start re-arms the settle, because CHIM sends
    `CHIM_SpeechStarted` **per sentence** (verified: `AIAgentAIMind.psc:1719/1790`, "every
    sentence"; `CHIM_SpeechStopped` at `:1845`).
- Speech timestamps are pushed in, not polled: `LRG_Main.OnChimSpeechStarted/Stopped` forward to
  the new `LRG_OStim.NotePartnerSpeech(Actor, bool, float)`, which only latches the partner (or the
  actor of a parked out-of-scene command).
- **`CmdStart` is split in two.** Everything up to and including the weapon preparation, the
  baseline capture and the furniture resolution runs at command time, unchanged. Then either
  `BeginStartThread()` runs straight away, or the command parks (`pendingStart`, `pendingStartAt`,
  `pendingStartUntil`, `pendingStartEnd`, `pendingStartScene/Furn/FurnType`) and **`Tick`** runs
  `TickPendingStart()` every 0.5 s until her line is out or the budget expires.
  - `startDeadline` is `cmdAt + 40 + budget`, so a slow TTS fetch can never become
    `Error: the scene did not start`.
  - `BeginStartThread` re-checks `IsFurnitureInUse()` and does **one more `ClearHands` pass on both
    actors** before `OThreadBuilder.Create` - an announce wait gives an NPC's AI seconds to reach
    for something, and a full hand is exactly what crashes OStim's ThreadActor constructor. This
    strengthens, never weakens, the crash fix.
  - `stop` is never waited on: `abortStart` reaches the parked state and `TickPendingStart` drops
    the start with `Error: the scene did not start` (the same answer the old abort path gives).
- **Parked verbs (amendment M9).** `goto`, `furniture` and `clothing` park **only the OStim call**.
  Every blocking check (`ok=1`, `IsPartnerName`, adults, dry run, `NavigateBlockedReason`,
  `nowarp`/warp decision, furniture lookup, the privacy branch) still runs at command time and a
  refusal is reported immediately. `ReportResult` fires when the parked call is really performed,
  so the funcret always describes what the game did.
  - Exactly **one** parked verb at a time: a second command is refused with
    `Error: still moving into the previous position` (`CmdControl`, `CmdClothing`, and
    `StartBlockedReason` for a start).
  - While parked, the world is re-checked every tick: thread gone -> `Error: no scene is running`;
    `navInFlight` or the scene id changed -> `Error: still moving into the previous position`; an
    out-of-scene clothing park that finds a scene running -> `Error: a scene is just starting`.
  - `stop` (verb or hotkey) cancels a park before anything else, as does `FinishThread`.
  - No new error reasons: all four are already in `PROTOCOL §1.6`.
- One log line per wait, as the amendment asks:
  `announce wait=end waited=3.4 reason=spoke|timeout` (and `... (goto)` for verbs). If playtest 7
  shows `reason=timeout` on every start, `setAnimationBusy(1)` (asserted at command time, still at
  `CmdStart`) is the suspect and the call moves after the wait.
- Master toggle `bAnnounceWait:Intimacy` (default on). Off = every `wait=` is ignored = today.

### §14.6 Listener routing - **with the critic's amendment**
- `ForceListener()` calls `AIAgentFunctions.setDrivenByAIA(partner, false)`; `ReleaseListener()`
  calls the no-argument `AIAgentFunctions.setDrivenByAI()`. Both are behind
  `bForceListener:Intimacy` (default on) and `IsEnabled()`, and **both log through `LogC`**.
  (Verified in the installed CHIM: `AIAgentFunctions.psc:57/58`; the crosshair hotkey at
  `AIAgentPapyrusFunctions.psc:469/474` and `:835/840` uses exactly this pair.)
- Called **once per thread**: in `OnOStimThreadStart` for a glue-run thread and in
  `AdoptRunningThread`; re-asserted from `ThreadTick` at most **every 60 s** (not 15 s).
- **Released explicitly** at `FinishThread`, at `StopScene`, and through `ResetState()` (which
  every ending path passes), so a forced listener can never outlive the scene. `listenerForced` is
  deliberately *not* wiped by `Maintenance`, so a save made mid-scene still releases on load.
- MCM help tells the owner what to watch for (a greeting mid-scene, a conversation partner stuck
  after a scene) and that switching it off is safe.

### §14.7 `nowarp=1` (G5.5)
`CmdControl`'s `goto`: on `unreachable`, `nowarp=1` -> `that position cannot be reached from here`,
before the `bAllowWarp:Intimacy` branch; a missing key leaves today's behaviour bit for bit.
`CmdFurniture` accepts the key for symmetry but has nothing to gate (it never warps -
`ChangeFurniture` is its only path), which is noted in the code.

### §14.8 / §14.9
Route length: no change (as specified). `iKeyPushToTalk`: deferred, not built.

### §15 MCM (every new id has BOTH a `settings.ini` line and a `config.json` entry)
```ini
[Intimacy]  bAnnounceWait = 1 · bForceListener = 1
[SceneTalk] fSceneLeadInterval = 75 (was 45) · fLeadHold = 150 · fSayFirstWait = 6
            fSayFirstMaxWait = 12 · fSayFirstSettle = 1
```
`config.json`: two toggles on the Intimacy page under a new "Her own moves" header, four sliders on
the Scene talk page, all with owner-facing help (no jargon, each says what raising / lowering does
and what "0" means). JSON re-parsed and all 34 ids verified against the ini.

### Version
`LRG_Main.CurrentVersion` 200 -> **300**; both script headers now say wire contract v0.3. Snapshot
`v=` stays 2 (additive keys only), as §1.1 requires.

---

## 2. Compatibility (§1.3), as built

| | server 0.2 (old) | server 0.3 (new) |
|---|---|---|
| **game 300** | no `wait` / `hold` / `nowarp` arrives, so: no park, no lead hold, today's warp policy. The only new traffic is `sess=`, `prev=`, `sync=1` and one extra `ev=end` after a load - all additive keys on messages the old server already parses (`ev=end` is its own normal close). | full feature set |
| **game 200** | today | new keys are ignored by the old scripts; nothing the server sends is required |

The game never *requires* a new key, never invents a `do=` verb, and no message shape changed -
keys were only appended. `ev=sync` is **not** sent (dropped per the critic's amendment).

---

## 3. Deviations from the design text (all deliberate, all small)

1. **`ev=sync` is not on the wire** (critic amendment, major/both) - replaced by `ev=end` on the
   no-thread branch + `sync=1` on the running branch's ordinary `ev=change`. The server builder
   must not expect a new `ev`.
2. **`NotePartnerSpeech` takes the actor** (`NotePartnerSpeech(Actor, bool, float)`) instead of the
   design's `(bool, float)`: `LRG_Main` forwards every CHIM speech event and the filtering ("is
   this the partner?") belongs where `partner` lives. Internal API only, no wire effect.
3. **New MCM key `fSayFirstSettle:SceneTalk`** (default 1.0, range 0-3) - required by amendment M7,
   not present in §15's table.
4. **`NotePlayerSpeech`** (new, not in the design): when CHIM voices a line of the *player's*, the
   scene-lead timer restarts (`lastActivity = now`). Strictly conservative - it can only delay her
   lead turn, never cause one - and it gives G2 a floor even with an old server that sends no
   `hold=`. It is also what makes the `fLeadHold` knob meaningful in every configuration.
5. **`hold=` on a `do=lead;who=npc`**: the game clears the hold and then re-applies whatever the
   command carried. The wire cannot distinguish "the player said *you lead*" from "the §9 carrier
   names the current leader, who happens to be her"; this reading serves both, but the **server
   builder should avoid decorating a `lead;who=npc` that came from a `lead_npc` intent with a
   long `hold=`**, or the game will hold her for that long anyway.
6. **One extra `ClearHands` pass** in `BeginStartThread` (not in the design): a wait of up to 12 s
   between the preparation and `OThreadBuilder.Start` is new, and the crash fix must not get
   thinner because of it.
7. **`nowarp` on `furniture`** is accepted and inert (there is no warp path in `CmdFurniture`).

Nothing else was changed: `HoldOStimGates`, `ReleaseOStimGates`, `GlueUndressNode`'s logic and its
respect for the owner's OStim options, `PrepareWeapons` / `SettleWeapons` / `GuardStart` /
`RestoreWeapons`, and every hard gate are as they were.

---

## 4. For the other stages (hand-over notes)

- **`command_confirm_seconds` (server §12.4) must be >= 20 + `fSayFirstMaxWait`** (= 32 s with the
  defaults), because a parked command reports its funcret only when the OStim call really happens.
- **Do not send `hold=` with a long value on a `lead;who=npc` that answers "you lead"** (see
  deviation 5).
- **OStim MCM, before playtest 7** (not ours to write, PROTOCOL §0): ask the owner to set
  *Camera > Free Cam > "Switch to free cam mode on start"* to **OFF** - it is the other half of
  "director mode". If the new `announce ... reason=` lines show `timeout` on every start, also
  reconsider *Fade out on intro/outro*.
- **What playtest 7 should show in the glue log**: `waiting for <npc> to say it before ...`
  followed by `announce wait=end waited=<s> reason=spoke` (timeout on every start = her TTS is not
  being spoken before the command, see §14.5); `listener forced to <npc>` at every scene start and
  `listener released` at every end (a missing release, a mid-scene greeting, or a conversation
  still glued to her afterwards = switch `bForceListener` off, everything else keeps working);
  `after the load no scene is running: the scene row for <npc> is closed` within a second of a
  reload.
- **Unverifiable here** (§17.1): that `setDrivenByAIA` really re-targets the *next player
  utterance* rather than only registering an agent. All five CHIM call sites are consistent with
  either reading; the hotkey pair (force with an actor / release with no argument) is the strongest
  evidence and is what this build copies. Mitigation is the toggle, the release and the log lines.

## 5. Verification done

- `pwsh tools\compile.ps1` -> `OK - ... compiler reported 0 errors, 0 warnings`, all six `.pex`
  rebuilt (run twice, after the first and the final round of edits).
- `config.json` re-parsed (PowerShell `ConvertFrom-Json`); all 34 setting ids listed and each one
  cross-checked against a `settings.ini` line and against the in-code default in the two scripts
  (every `SettingBool/Float/Int` fallback now matches the ini, including the new 75 s interval).
- CHIM natives re-read in the installed sources before use: `setDrivenByAIA` / `setDrivenByAI`
  (`AIAgentFunctions.psc:57-58`), `isActorTalking` (`:33`), and the per-sentence
  `CHIM_SpeechStarted` / `CHIM_SpeechStopped` senders (`AIAgentAIMind.psc:1719/1790/1845`).
- In-game behaviour is of course untested - nothing was installed.


---

# pt6 GAME-SIDE FIX PASS (appended by the game fixer agent)

Started. Scope: glue\game\LoreRimGlue\** only. Target: fix every critical/major defect and every cheap+safe minor one; compile.ps1 must print OK; no install.

## Triage of the defect list (which are mine)

| # | sev | defect | owner |
|---|-----|--------|-------|
| D1 | minor | command_confirm_seconds 20 -> 40 | SERVER (config + PROTOCOL) - not mine |
| D2 | minor | two `turn npc=` log prefixes | SERVER - not mine |
| D3 | major | you-lead inverted (lrgDecorate hold=) | SERVER - not mine (game half = D5) |
| D4 | major | hold carrier cancels wind-down | GAME - MINE |
| D5 | minor | CmdLead zeroes leadHoldUntil (monotonic break) | GAME - MINE |
| D6 | minor | parked start not abandoned on foreign thread | GAME - MINE |
| D7 | minor | no-op clothing reports success | GAME - MINE |
| D8 | minor | FinishThread ev=end omits sess= | GAME - MINE |
| D9 | minor | ApplyHold before CmdControl refusals | GAME - MINE |
| D10 | minor | wait=end between sentences | no code change (playtest 7 observation) |
| D11 | minor | command-before-TTS ordering unverified | no code change (playtest 7 observation) |
| D12 | minor | command_confirm_seconds dup of D1 | SERVER - not mine |
| D13 | minor | iKeyStopScene ships 0 | GAME (settings.ini + config.json) - MINE |
| D14 | major | in-scene snapshot goes stale -> requests lost | GAME - MINE |
| D15 | major | announce gate has no look-back | GAME - MINE |

(progress appended below as work lands)

## What was changed (all under glue\game\LoreRimGlue\)

`compile.ps1` printed **OK - compiler reported 0 errors, 0 warnings** after the last edit; the six
.pex files are refreshed in `glue\game\LoreRimGlue\Scripts\`. **Nothing was installed** (no
`install_mo2.ps1` run), as instructed.

### D15 [major] the announce gate had no look-back -> FIXED
`LRG_OStim.AnnounceState()`: `started` was `partnerSpeakStart > afSince`, i.e. only a sentence that
began AFTER the command counted. CHIM's delivery order is genuinely undetermined (HerikaServer emits
her line before the action lines; in the game the line goes through the DLL speaker queue while the
ExtCmd dispatch is immediate), so a command that lands a moment after her TTS started could never be
satisfied and burned the whole budget as dead air - and the fade could then still cut across her.

    float lookBack = afSince - 3.0
    bool started = partnerSpeakStart > 0.0 && partnerSpeakStart > lookBack

The `partnerSpeakStart > 0.0` guard is mine and is load-bearing: `partnerSpeakStart` starts at 0.0
and real time also starts at 0.0 after a game launch, so without it `0.0 > (2.0 - 3.0)` would have
read as "she has spoken" in the first three seconds of a session.
3 s is far shorter than a CHIM round trip, so the window can never pick up the previous reply.
It applies to all four call sites (ParkVerb, TickPendingVerb, CmdStart, TickPendingStart).

Second half of the same defect: `fSayFirstMaxWait` default **12 -> 20** in `settings.ini` AND in the
in-code fallback `AnnounceBudget()` (`SettingFloat("fSayFirstMaxWait:SceneTalk", 20.0)`), so the two
cannot drift. The MCM slider already allows 0..25, so no config.json range change was needed; its
help text now names the new default.

### D14 [major] the in-scene snapshot went stale and spoken requests were lost -> FIXED
`LRG_OStim.ThreadTick()`, inside the existing 4.5 s / `glueOn` branch, after the listener re-assert:

    if partner != None
        Main().MaybeSnapshot(partner, false)
    endif

`MaybeSnapshot` throttles itself to one per NPC per 10 s (and 2 s globally), and `lrg_npcstate` is a
fast message the server handles before the MAIN lock, so this costs no LLM call. The server-side
staleness rule (`snapshot_max_age_seconds` 90) is **not** softened - it is the adults-only
fail-closed rail.

### D4 [major] the no-op hold carrier cancelled a running wind-down -> FIXED
`LRG_OStim.CmdLead()`:

    if who != leader
        CancelWindDown("the lead changed")
    endif

The carrier names the CURRENT leader, so it is now a no-op for the wind-down. This also holds
against an old server and against any stray `do=lead`.

### D5 [minor] CmdLead broke the monotonic-hold guarantee -> FIXED, but NOT the way the review proposed
The review said the re-apply at `:2593` "can be dropped" once the server stops decorating a
hand-over with `hold=`. **Dropping it alone would have destroyed the hold carrier**: `ApplyHold` runs
in `CmdControl` BEFORE `CmdLead` is dispatched, so for a carrier `do=lead;who=npc;hold=150` the
sequence would have been "apply 150, then clear to 0" - and `npc` is the default leader, so the
carrier would have held nothing in the common case. Implemented instead:

    string holdKey = m.ParamGet(asParam, "hold")
    if holdKey == "" || holdKey == "0"
        leadHoldUntil = 0.0
    endif

i.e. **an absent hold= (or an explicit hold=0) IS the release**, which is exactly the wire shape the
server-side D3 fix produces, and which also implements the "give the hand-over its own wire key"
alternative the review named. Nothing that carries a hold is cleared or re-applied any more, so
`leadHoldUntil` is monotonic again. No wire change: `hold=0` was already a no-op in `ApplyHold`.

### D6 [minor] a parked start was not abandoned when a foreign thread started -> FIXED
`LRG_OStim.OnOStimThreadStart()`, before the `if starting` branch: `pendingStart` is cleared (with
`pendingStartFurn` / `pendingStartScene` / `pendingStartFurnType` and `starting`), the start command
is answered once with `Error: a scene is already running` (or `Error: the scene did not start` when
`abortStart` was already set), and control falls through to the existing else branch, which adopts
the running thread. `FinishThread` then releases the held OStim globals and the weapons at the end
of the adopted thread, so nothing stays latched. `pendingStart` can only be true while no thread of
ours exists (`TickPendingStart` clears it before `BeginStartThread`), so this can never fire on our
own start.

### D7 [minor] an in-scene clothing command that changed nothing reported success -> FIXED
`SceneUndress` / `SceneRedress` now return an int, and a new `ScenePartWorn()` / `ScenePartFree()`
pair answers "is anything on / is any slot empty" with `GetWornForm`. `PerformClothing` sums the
count over every requested actor (scene natives + `StripPart` / `DressPart`) and, when it is 0,
answers `Error: not possible in this position` (an existing closed-list reason, PROTOCOL:74) and
logs `undress changed nothing who=.. part=..` / `dress changed nothing ...`.
Three deliberate conservatisms:
- `SceneRedress` still CALLS the native even when it returns 0 (OStim also restores items outside
  the eight slots this script knows about); only the ANSWER changes.
- `ScenePartFree` counts EMPTY slots, so an actor who simply owns no boots reads as "something is
  missing" and reports success exactly as before. The change can only turn a **false success** into
  a corner note, never a real change into a false error.
- `NoteSceneStrip` is still called unconditionally, so the crash-4 `glueStripped` bookkeeping is
  byte-for-byte unchanged.

### D9 [minor] ApplyHold ran before CmdControl's refusals -> FIXED (slightly wider than proposed)
`ApplyHold(asParam)` moved from the top of `CmdControl` to just before the verb dispatch - i.e.
after the `stop` fast path, after `pendingVerb != ""`, after `IsPartnerName`, after `IsAdult` **and
after the dry-run check**. The review only asked for "after IsPartnerName / IsAdult"; including
dry-run is a strict superset and removes a 600 s silence for a command that provably changed
nothing. The per-verb refusals below it (unknown verb, unreachable, still starting) still apply a
hold - moving past those would mean duplicating the call into every verb.

### D8 [minor] FinishThread's ev=end had no sess= -> FIXED
`";sess=" + m.SessionTag()` appended, same key order as the load path at `:312`.

### D13 [minor] the documented stop fallback was unbound -> FIXED (with a caveat)
`settings.ini`: `iKeyStopScene = 207` (**End**), with a comment saying why and how to rebind.
Chosen after checking that 207 is free: not bound in vanilla Skyrim; not among the scancodes any
LoreRim MCM-Helper mod saves (a scan of `mods\*\MCM\Settings\*.ini` found only 0, 1, 26, 27, 35, 39,
40, 56, 201 and the four gamepad codes 258/260/273/281); and not among OStim's own keys, which are
numpad and arrow keys (`DefaultOstimMCMSettings.json`: ControlToggle 82, FreecamToggle 181,
KeyDown 74, KeyUp 78, PullOut 79, Keymap 200). config.json's help text now names the default and
says why the hotkey matters.
**Caveat for the owner:** MCM Helper only takes a value from `settings.ini` where the player has no
saved value yet. No `MCM\Settings\LoreRimGlue.ini` exists anywhere in the modlist right now, so the
default should take effect - but if the key does nothing in playtest 7, "Reset to defaults" on the
Keys page (or binding it by hand) is the fix. The other half of this defect (the PROTOCOL:154 /
:179 wording) is in the BEHAVIOUR owner's file and was left alone.

### D10, D11 [minor] - no code change, by design
Both are "read the log in playtest 7" items and the instrumentation they need (`LogWait`'s
`announce ... reason=spoke|timeout` lines) already exists. D15's look-back makes `reason=timeout`
far more meaningful: with the look-back in place a run of `reason=timeout` now really does mean her
voice never arrived, not merely that it arrived a moment too early to be counted.

### D1, D2, D12 - NOT MINE
`config/lrg_config.default.json` (`command_confirm_seconds`), `lib/lrg_actions.php` (the two extra
`turn npc=` log prefixes) and `PROTOCOL.md` belong to the BEHAVIOUR owner (PROTOCOL 8, ownership
table). Left untouched.
**Hand-over, and it has moved:** `command_confirm_seconds` must stay >= 20 + `fSayFirstMaxWait`.
With the new default that is **40 s** - so the D1 fix (40) is correct and the D12 fix (35) is now
too small. Please take 40.

## Owner addenda
`glue\OWNER_ADDENDA.md` was read first. Addendum 3 ("no denying during the romance") removes the
Decline action, refusal-marker detection and per-act decline memory - all server-side; the game side
has no refusal mechanism of its own inside a running scene (it only ever answers with the closed
list of GAME reasons, which addendum 3.f explicitly keeps - D7 above adds one more honest use of
that list instead of a false success). Addendum 2 (the revised R10, and the verbatim user request
that opened this round: "if she initiates any new scene/animation she should say something
signifying it") is served game-side by the `wait=` announce gate on all four self-initiated verbs
(start, goto, furniture, clothing). D15 is precisely the fix that makes her line land before the
change instead of timing out into silence, and D6 stops a parked announce from leaving a stray
funcret behind. No addendum required a game change that is not in the list above.

## Verification
- `tools\compile.ps1` -> `OK - .pex files copied to game\LoreRimGlue\Scripts ...; compiler reported
  0 errors, 0 warnings` (2 rounds, 23 auto-stubs, all six .pex produced).
- `MCM\Config\LoreRimGlue\config.json` re-validated as JSON (6 pages).
- Not installed; `install_mo2.ps1` was not run. No server file, no OStim / OARE / LoreRim / CHIM /
  HerikaServer file and no PROTOCOL.md line was touched.

## For playtest 7 (game-side things to read in the glue log)
1. `announce wait=begin|end (...) waited=.. reason=spoke|timeout` - `spoke` means the say-first
   design holds; a run of `timeout` now means her voice really never arrived, and `wait=` should be
   downgraded to `begin` everywhere (or `bAnnounceWait:Intimacy` switched off) until CHIM's ordering
   is instrumented. If scenes still begin mid-reply, raise `fSayFirstSettle` (0..3 s).
2. `undress changed nothing` / `dress changed nothing` - a spoken clothing request that found
   nothing to do. Several of these on "get naked" would mean the server is aiming at the wrong
   `part`, not that the game ignored her.
3. `the parked start was dropped: another scene began while she was speaking` - should be rare; if
   it is not, something else is starting threads during the announce window.
4. Whether `WARN command not confirmed by the game` still appears once the server takes
   `command_confirm_seconds = 40`.
