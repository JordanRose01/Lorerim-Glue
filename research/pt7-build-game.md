# pt7 build - GAME side (v0.3.1, script version 310)

Builder report. Lane: `glue\game\LoreRimGlue\**` only. Nothing outside it was touched; nothing was
installed (`install_mo2.ps1` was not run). `compile.ps1` prints **OK** (0 errors, 0 warnings, every
`.pex` string under the 500-character guard; the longest docstring in the whole mod is 282).

Read first, in full: `glue\OWNER_ADDENDA.md`, `research\pt7-relationship.md`,
`research\pt7-act-coverage.md`, `research\pt7-outro.md`, plus `glue\PROTOCOL.md` sections 0-3.

## STATUS: done, compiles, NOT installed, NOT tested in game

---

## 1. What was built

| # | round item | where | state |
|---|---|---|---|
| 1 | R3 outro hold | `LRG_OStim.psc` (new section, `FinishThread`, `Tick`, `Maintenance`, `StopScene`, `CmdStart`, `OnOStimThreadStart`, `NotePartnerSpeech`) | done |
| 2 | `ev=end` carries `dur=` + `how=` (w3) | `LRG_OStim.FinishThread`, `LRG_OStim.Maintenance` | done |
| 3 | `StartIntimacy after=` (w1) | `LRG_OStim.TickAfterScene`, `OnOStimThreadStart`, `ThreadTick` | done |
| 4 | two commands back to back (w2) | `LRG_OStim` deferred-command queue + `LRG_Main.HandleCommand` de-dup ring | done |
| 5 | "maintenance done" prints the running script version | `LRG_Main.Maintenance` | done |
| 6 | gates / undress node / weapons / say-first / listener lock unchanged | - | verified, untouched |
| + | `IsCourting` leaked +10 affinity to strangers (relationship report) | `LRG_Profile.psc` | done (added, see deviations) |

Versions: `LRG_Main.CurrentVersion` **300 -> 310**. The manifest (0.3.1) is server-side and was not
touched.

---

## 2. R3 - the outro hold, in detail

### 2.1 The flow at the end of a scene now

`FinishThread` (`LRG_OStim.psc`), in order:

1. `ReleaseOStimGates` (unchanged), `sceneEndedAt`, drop a pending `after=` move,
   `DropPendingVerb` (unchanged);
2. compute `dur` (from the new `sceneStartedAt`) and `how` (from the new `endHow`);
3. **`BeginOutroHold(endAt, how, dur)` - before the first release**, so there is no window in which
   her package takes over: `outroActor.SetDontMove(true)` + one `SetLookAt(player)`;
4. `ReleaseListener` (unchanged, see deviations);
5. **forced snapshot** `Main().MaybeSnapshot(outroActor, true)` while the scene row is still open -
   this is the `no_fresh_snapshot` trap from 17:16:24 in the outro report;
6. `ev=end;...;sess=..;dur=<n>;how=<finished|stopped|interrupted|lost>`;
7. `ResetState()` (frees her for CHIM: `setAnimationBusy(0)`);
8. `Utility.Wait(1.5)` (the existing redress wait) - and only then
   **`AIAgentFunctions.requestMessageForActor("outro", "lrg_scenetalk", <npc>)`**, so the server has
   certainly processed `ev=end` and written its outro ticket before the turn arrives;
9. weapons / clothes exactly as before.

The hold begins **only** when all of: `fOutroHold > 0`, intimacy enabled, not dry-run, a partner
exists, `how != interrupted`, the scene really ran (`dur >= fOutroMinScene`, default 45 s), she is
alive / loaded / conscious / not disabled, neither of them is in combat, and no new scene is
starting. Otherwise the scene ends **exactly** as it did in 0.3 and no outro is asked for.

### 2.2 Release - one function, every path

`ReleaseOutroHold(asWhy)` is the only way out. It always does
`SetDontMove(false)` + `EvaluatePackage()`, clears every field, and logs
`outro released (<why>) held=<s>`. It is safe to call twice, and on a hold that only the save
remembers. **`ClearLookAt` is never called** - the look-at is CHIM's (`EndDialogueClear`, ~90 s).

| # | path | where |
|---|---|---|
| 1 | her line is out (started after the request, stopped, quiet for `fOutroSettle`, `isActorTalking == 0`) | `TickOutro` -> `OutroLineState` |
| 2 | timeout `fOutroHold` (from the moment the line was asked for) | `TickOutro` |
| 3 | game load / `Maintenance` - **first statement in the function, unconditional** | `LRG_OStim.Maintenance` |
| 4 | real-time clock restart, or a hold older than `2 * fOutroHold + 30` (self-heal) | `TickOutro` |
| 5 | combat on either actor, or the player died | `TickOutro` |
| 6 | player further than `fOutroFar` (default 1500) | `TickOutro` |
| 7 | another cell - **only tested when either of them is in an interior** (two actors standing together outdoors are regularly in different exterior cells) | `TickOutro` |
| 8 | she is dead / disabled / unconscious / not 3D-loaded (cell unload, fast travel) | `TickOutro` |
| 9 | a new scene: `starting`, `pendingStart` or `OThread.IsRunning(0)` | `TickOutro` |
| 10 | a new `StartIntimacy` command arrives (first line of `CmdStart`) | `CmdStart` |
| 11 | a thread start event of any kind | `OnOStimThreadStart` |
| 12 | the stop hotkey / the `stop` verb | `StopScene` |
| 13 | kill switch, `bEnabled`, `bIntimacyEnabled` off, or `fOutroHold` set to 0 mid-hold | `TickOutro` |
| 14 | `outroActor == None`, or a hold left over with `outroActive == false` | `TickOutro`, first branch |

`TickOutro` runs **first in every `Tick()`**, before the OStim-present check, so the self-heal works
even if OStim is gone. While a hold is live it asks for a 0.5 s tick; her CHIM speech events also
ask for one (`NotePartnerSpeech`), so the release is prompt.

### 2.3 Why `SetDontMove`, and what it costs

Chosen exactly as `pt7-outro.md` 2.4 recommends: one boolean with an exact inverse, no package
override (an `AddPackageOverride` is written into the save and outlives the mod - the classic stuck
NPC), she can still turn, talk and play idles. CHIM uses `SetDontMove` itself
(`AIAgentAIMind.psc:3663, 3840`), so it is proven in this stack.

The one real risk is that the flag is **saved**. That is covered three ways: the unconditional
release in `Maintenance` (which runs on every load and is the first thing the script does), the
self-heal branch in `TickOutro` (`outroActive == false` but `outroActor != None` -> release), and
the `2 * budget + 30` ceiling. `outroActor` / `outroName` are deliberately their **own** fields:
`ResetState` clears `partner` / `partnerName` a moment after the hold begins.

### 2.4 Detecting that her line is out

Her CHIM speech during the hold is counted on its **own** clock (`outroSpeakStart` /
`outroSpeakStop`, fed by `NotePartnerSpeech` when `akNpc == outroActor`) because she is no longer
`partner` once `ResetState` has run. `OutroLineState` is `AnnounceState`'s `wait=end` shape -
started after the request (3 s look-back), then stopped, then a quiet settle in which nothing
started again, and `isActorTalking == 0` - with its **own longer settle** `fOutroSettle`
(default 2.5 s, clamp 0..6) because CHIM raises SpeechStarted/Stopped once per **sentence** and the
outro is 2-4 sentences. `fSayFirstSettle` (1.0 s, clamp 0..3) is untouched.

---

## 3. w3 - `dur=` and `how=`

New game state `sceneStartedAt` (set in `OnOStimThreadStart` and in `Maintenance`'s running branch,
cleared in `ResetState`), and `endHow`, claimed by whoever causes the end:

| who ends it | `how=` |
|---|---|
| OStim's own end event and nobody claimed it | `finished` |
| the wind-down verb reaching its linger time | `finished` (claimed in `ThreadTick` before `StopScene`) |
| the `stop` verb or the stop hotkey (`StopScene`) | `stopped` |
| `Tick`'s "thread gone without an end event" | `lost` |
| a game load with no thread running (`Maintenance`) | `interrupted`, always with `dur=0` |

Exact strings on the wire (both additive, appended after `sess=`):

```
ev=end;npc=<name>;cid=<cid>;scene=<last scene>;byglue=<0|1>;sess=<tag>;dur=<int seconds>;how=<finished|stopped|interrupted|lost>
ev=end;npc=<name>;cid=<cid>;scene=;byglue=<0|1>;sess=<tag>;dur=0;how=interrupted        (the post-load close)
```

The glue log line at the end of a scene now reads
`<why> last scene=<id> dur=<n> how=<x>`.

Note for the server: a scene resumed across a save load has `sceneStartedAt` stamped at the **load**
(the real start is in a session that is gone), so its `dur` is short on purpose and it normally gets
no outro. That is the safe direction.

---

## 4. w1 - `StartIntimacy after=<scene id>`

`CmdStart` needs no change: `OnOStimThreadStart` reads `after=` out of the start command's own
param (`startParam`) in the branch that knows the start was ours, and arms
`afterScene` / `afterAt = now + 4 s` / `afterUntil = now + 40 s`. `ThreadTick` calls
`TickAfterScene` first on every tick while a thread runs.

- `after=` equal to the scene that actually started is ignored.
- While `starting`, `navInFlight`, a parked verb or a wind-down is in the way it retries every 1 s
  inside the window; the same for OStim's own transient reasons (`the scene is still starting`,
  `in the middle of a transition`, `still moving into the previous position`).
- **Unreachable -> warp**, because this is the player's own request:
  `bAllowWarp:Intimacy` (default on) decides, and `nowarp=` is deliberately NOT consulted here -
  that key exists for moves SHE chooses. With warp off it is dropped with a log line.
- Anything else (not a position, wrong actor count, already there) is dropped with
  `after= dropped target=<id> reason=<...>`; a window that expires logs
  `after= gave up: <id> never became possible`.
- No funcret is ever sent for `after=`: the start command was already answered
  `They draw close. The scene begins.` The move is a *consequence* of that command, so an old game
  (script 300) simply ignores the key and the start stands on its own.

---

## 5. w2 - two commands in one reply

Two independent defects had to be fixed.

### 5.1 The second command was refused and lost (the one the round asked about)

`LRG_OStim` now has **one** deferred-command slot. Where a refusal is *transient* - i.e. something
of **ours** is still running - the command is queued instead of answered, and retried once a second
until the first one is done. At `fQueueWait:Intimacy` (default 20 s, 0 = off = exactly 0.3) it is
answered with the very reason it would have got at once, so nothing is ever left open (CHIM waits
for the funcret before the NPC is free again).

| call site | transient reason that now queues |
|---|---|
| `CmdStart` | `still moving into the previous position` (a parked verb from the first command) |
| `CmdControl`, before the gate-checked "no scene is running" | `the scene is still starting` - only while `starting` is true and `ok=1` |
| `CmdControl` | `still moving into the previous position` (a parked verb) |
| `CmdControl` `do=goto` | `still moving into the previous position`, `the scene is still starting`, `in the middle of a transition` |
| `CmdFurniture` | the same three |
| `CmdClothing` | `a scene is just starting`, `still moving into the previous position` |

Everything else is answered immediately exactly as before: **not authorised, adults only, a scene
with someone else, a scene is already running, combat, a child is nearby, someone is watching, no
suitable furniture, that position does not exist** - a real refusal is never delayed and never
queued. The `ok=1` gate check always runs **before** any queueing, so nothing unauthorised is ever
parked. `DeferCommand` returns false when a command is already queued, so a **third** command in one
reply is refused as it is today - the server must not send more than two.

Retry bookkeeping: the slot is freed before the retry, so a command that is still too early simply
queues itself again and **keeps its original deadline** (`defResumeCid` / `defResumeUntil`), instead
of starting a fresh 20 s budget every second. `ResetState` answers a queued command with
`no scene is running`; `Maintenance` forgets it silently (CHIM's own command state went with the
load, so a funcret for it would describe a command nobody is waiting for).

### 5.2 A latent double-execution in the de-duplication (found while reading, as asked)

`LRG_Main.HandleCommand` de-duplicated against **one** slot (`lastCmdKey`). Every command arrives
twice (bridge script `LRG.DispatchExternalCommand` **and** the `CHIM_CommandReceived` mod event).
With two commands in one reply, the delivery order `A-bridge, B-bridge, A-event, B-event` makes `A`
look new again on its second delivery and **carries it out a second time** - a duplicated undress,
or worse a duplicated start. Replaced with a 4-slot ring (`cmdKeys` / `cmdTimes` / `cmdSlot`,
5 s window, re-armed in `Maintenance`, rebuilt automatically for a save that comes from 300).
Single-command replies behave exactly as before.

---

## 6. R4 - the version in the log

`LRG_Main.Maintenance` printed `installedVersion`, a **saved** variable, so a `.pex` that never
loaded (a failed install, a stale copy in Overwrite) was invisible. It now prints the compiled-in
`CurrentVersion` and keeps the saved number beside it:

```
maintenance done, version 310, save 300, session 4812
```

Nothing on the server parses this line (checked: no hit for `maintenance` anywhere under
`glue/server`), so the format change is safe.

---

## 7. The `IsCourting` fix (game side, from `pt7-relationship.md`)

`LRG_Profile.IsCourting(akActor)` called `HasAssociation(courting)` with the partner omitted, which
asks "with **anyone**". Playtest 7 snapshots show `courting=1` for Jala, Vivienne Onis and Sorex
Vinius - none of them courting the player - each of whom was silently handed the server's +10
`affinity.courting_bonus`. Now `IsCourting(Actor akActor, Actor akPlayer)` ->
`akActor.HasAssociation(courting, akPlayer)`; the only call site (`BuildSnapshot`) already had the
player. The snapshot key `courting=` is unchanged in name and position.

---

## 8. New MCM settings (all five have a control AND an ini default)

Cross-checked programmatically: every control in `config.json` has a default line in `settings.ini`
and there are no orphan ini keys.

| key | page | default | range | what a MISSING key (reads as 0) does |
|---|---|---|---|---|
| `fOutroHold:SceneTalk` | Scene talk, "After a scene" | 25 | 0..60 step 5 | 0 = the whole outro is off = exactly 0.3's behaviour (safe) |
| `fOutroSettle:SceneTalk` | same | 2.5 | 0..6 step 0.5 | releases right after her first sentence stops (harmless) |
| `fOutroMinScene:SceneTalk` | same | 45 | 0..300 step 15 | an outro after any scene (harmless) |
| `fOutroFar:SceneTalk` | same | 1500 | 200..4000 step 100 | **guarded in code**: a value below the slider minimum falls back to 1500, otherwise a missing key would release her before she said a word |
| `fQueueWait:Intimacy` | Intimacy, scene control | 20 | 0..60 step 5 | 0 = no queueing = exactly 0.3's behaviour (safe) |

Every help text is plain English and says what the player will see. The failure direction of every
new setting is "the new feature is off", never "the NPC is stuck".

---

## 9. What the SERVER builder / the fix pass needs from this side

1. **The outro turn is triggered by the GAME.** ~1.5 s after `ev=end` the game fires
   `AIAgentFunctions.requestMessageForActor("outro", "lrg_scenetalk", <npc display name>)` -
   request type `lrg_scenetalk`, request **text exactly `outro`**. This is what
   `pt7-outro.md` 3.1/3.2 proposes and what the server's planned precondition change keys on. If
   the server rejects it, CHIM falls back to `TEMPLATE_DIALOG` and the player hears an ordinary
   line - no crash, no regression, but also no real outro.
2. The outro ticket window only has to cover ~2-5 s after `ev=end` (the report's 60 s is plenty).
   The forced snapshot is sent **before** `ev=end`, so it is 1-2 s old when the outro turn arrives.
3. `ev=end` now carries `dur=` (int seconds) and `how=` (`finished|stopped|interrupted|lost`); the
   post-load close carries `dur=0;how=interrupted`. R1's "a completed scene" test can use them
   directly (`how` in `finished|stopped` and `dur >= 60`) instead of guessing from `_tier_since`.
4. **The funcret of a queued second command can arrive up to ~20 s late.** Anything server-side that
   treats a missing / late funcret as "the command was lost" must tolerate that, and the
   stale-state-by-funcret logic should not fire on it.
5. **At most TWO commands per reply.** A third is refused with today's reason - there is one queue
   slot on purpose (two parallel OStim calls are what the parked-verb rail exists to prevent).
6. `after=` is read only on `ExtCmdLRG_StartIntimacy` and only for a start the glue itself made. Send
   the scene OStim can really start in as `scene=` / `fscene=` and the position the player named as
   `after=`; sending the same id in both is harmless (ignored).
7. The `courting=` key in the snapshot is now true only for an NPC courting **the player**. Any
   server-side affinity that leaned on it will read lower (and correctly) for strangers.
8. PROTOCOL.md is outside this lane and still says "`ev=end` carries only `ev, npc, cid, scene,
   byglue`" (1.2) and lists no `after=` on `StartIntimacy` (section 2). Both need the additive rows
   added by whoever owns the file.
9. Cosmetic, `tools/` (not this lane): `install_mo2.ps1:54` still writes `version=0.1.0` into the
   MO2 `meta.ini`.

---

## 10. Deviations, and why

1. **The game fires the outro request.** w3 says "the server answers nothing new on the wire", and
   it does not - but *something* must trigger the LLM turn, and `ev=end` is a fast message the
   server `terminate()`s. This is the mechanism both the outro report and the server plan assume.
   Flagged here because it is strictly a fifth wire item beyond w1-w3.
2. **The listener is released at scene end exactly as today.** `pt7-outro.md` 3.1-B4 proposed
   keeping `setDrivenByAIA` forced through the hold so the player's reply still routes to her. Build
   item 6 says the listener lock stays exactly as safe as it is, and holding CHIM's routing open for
   another 25 s is the one thing that could leave the player glued to an ex-partner, so it was not
   done. During the outro she is standing right in front of the player, i.e. normally under the
   crosshair anyway. Easy to revisit if playtest 8 shows her answers going to the Narrator.
3. **`IsCourting` was fixed although it was not in the six build items.** It is game-side, one line,
   in my lane, and `pt7-relationship.md` names it as a live defect that inflates affinity for
   strangers.
4. **The 4-slot de-dup ring in `LRG_Main`** goes beyond "read HandleCommand's de-duplication, read
   only": reading it showed a double-execution that w2's own two-commands-per-reply makes reachable.
   It is 12 lines and strictly safer.
5. **Four extra MCM keys** beyond the mandated `fOutroHold` (`fOutroSettle`, `fOutroMinScene`,
   `fOutroFar`, `fQueueWait`). Each is a release rail or a budget that the owner may have to tune
   after playtest 8; `bOutro` and `bOutroDontMove` from the outro report were deliberately NOT added
   (`fOutroHold = 0` is the off switch - fewer settings, per owner addendum 3h).
6. **`CmdFurniture` also queues** its three transient reasons, for symmetry with `goto`.
7. `Maintenance` **forgets** a queued command instead of answering it (a funcret after a load
   describes a command nobody is waiting for); inside a session it is always answered.

## 11. What is NOT done

- Nothing was installed. `compile.ps1` copied the six `.pex` files to
  `glue\game\LoreRimGlue\Scripts` only.
- Not tested in game: there is no Papyrus test harness in this project, and the round forbids
  running MO2 / the installer. Everything here is compile-verified and read-verified.
- Server side (R1, R2, R3's directive and ticket, R4's `say=` logging) is the other builder's lane.
- `PROTOCOL.md` was not updated (outside this lane) - see 9.8.

## 12. Files changed

```
glue\game\LoreRimGlue\Source\Scripts\LRG_OStim.psc     (the bulk: outro hold, dur/how, after=, queue)
glue\game\LoreRimGlue\Source\Scripts\LRG_Main.psc      (version 310, log line, 4-slot de-dup ring)
glue\game\LoreRimGlue\Source\Scripts\LRG_Profile.psc   (IsCourting with the player)
glue\game\LoreRimGlue\MCM\Config\LoreRimGlue\config.json    (5 new controls)
glue\game\LoreRimGlue\MCM\Config\LoreRimGlue\settings.ini   (5 new defaults)
glue\game\LoreRimGlue\Scripts\*.pex                    (rebuilt by compile.ps1: all six)
```

## 13. What to watch in playtest 8 (game side)

| log line | means |
|---|---|
| `outro hold: <npc> stays with you (finished, the scene ran 262 s, up to 25 s)` | the hold went on |
| `no outro: the scene ran 12 s, less than 45` | the scene was too short - working as intended |
| `outro line requested from <npc>` | the request went out ~1.5 s after `ev=end` |
| `outro released (she has said her piece) held=14.3` | the good case |
| `outro released (timeout) held=26.5` | her voice never arrived in the budget - raise `fOutroHold` |
| `outro released (a new scene / combat / the player walked away / another cell / she is gone)` | a rail fired; she is free |
| `outro released (self-heal)` | a bug - the hold outlived its budget; report it |
| `queued behind the command before it (<reason>)` | the second half of a compound request is waiting |
| `the queued ExtCmdLRG_* was dropped: <reason>` | it waited `fQueueWait` and never became possible |
| `after=<id>: moving there in a few seconds` / `after= dropped ... reason=` | w1 |
| `maintenance done, version 310, save 300, session <n>` | the new scripts really loaded |

---

# FIX PASS (game side) - v0.3.1, after the playtest-7 audit

Lane: only `glue\game\LoreRimGlue\**`. Server half is being fixed in parallel by the server lane.
Started 2026-09-21 (owner's local time), written as the work went along.

Source files touched (planned):
- `Source\Scripts\LRG_OStim.psc`
- `Source\Scripts\LRG_Main.psc`
- `MCM\Config\LoreRimGlue\settings.ini`
- `MCM\Config\LoreRimGlue\config.json`

Defects taken (game half), in the order they are fixed:
1. [major] outro hold times out while she is still speaking -> she walks off mid-goodbye.
2. [major] fQueueWait (20 s) shorter than the navigation deadline (25 s) -> second half of a
   compound request dropped while the first is still legitimately in flight.
3. [minor] `how=lost` still triggers the hold although the server writes no outro ticket for it.
4. [minor] NPC initiative can fire during the outro hold (she propositions the player mid-goodbye).
5. [minor] forced end-of-scene snapshot silently skipped when `snapBusy` is set.
6. [minor] a command that must be refused occupies the single queue slot (CmdControl /
   StartBlockedReason order).
7. [minor] DeferCommand resume keyed on the cid: an empty cid restarts the budget every retry.
8. [minor] fOutroMinScene (45) vs the server's scene-gain threshold (60) - documented, not silently
   aligned.
9. [minor] corner note for an impossible request - receive side only, dormant (see deviations).

Out of lane, not fixed here: `tools\install_mo2.ps1` version stamp (tools\, not game\).

## What was changed, defect by defect

### 1. [major] The outro hold could time out while she was still speaking
`LRG_OStim.TickOutro` - the timeout branch is no longer a guillotine. Before releasing on
`afNow >= outroUntil` it asks whether she is audibly speaking; if she is, the deadline moves on by
5 s and the tick comes back in 0.5 s.

Two sources for "speaking", because CHIM raises SpeechStarted / SpeechStopped once per SENTENCE
and `isActorTalking` reads 0 in the gap between two of them:
- `AIAgentFunctions.isActorTalking(outroName) != 0`, and
- our own clock: a line that started (`outroSpeakStart > 0`) and stopped less than 4 s ago.

Everything that releases her (feature off, kill switch, a new scene, death / unload, combat,
distance, another cell, the self-heal at `2 x budget + 30 s`) is tested BEFORE this branch, so the
extension can never hold her against one of them. Worst case with the default budget: 25 s for her
voice to arrive, and the hold as a whole can never outlive 80 s. `fOutroHold` stays 25 - the
observed LLM latency before the first syllable in playtest 7 was 2-13 s - but the settings.ini
comment and the MCM help now say plainly that the number is "how long her voice has to arrive",
not "how long she may talk", so the owner can raise the slider if TTS regularly starts later.

### 2. [major] Queue budget shorter than the navigation deadline
- `MCM\Config\LoreRimGlue\settings.ini`: `fQueueWait = 20` -> `30` (>= GoToScene's 25 s navigation
  deadline). The MCM slider already reaches 60; its help text now says "keep it at or above 30,
  because moving into a new position alone may take 25 seconds".
- `LRG_OStim.TickDeferred`: the deadline is no longer hard while the FIRST command is demonstrably
  still in flight. Past `defUntil`, the queue is kept open when
  `starting || pendingStart || navInFlight || pendingVerb != ""`, extending `defUntil` in 5 s steps
  and logging one line ("the queued command waits on: the one before it is still running").
- New hard ceiling `defHardUntil` = `2 x budget + 10 s`, set once when the command is first queued
  and carried through every retry. Past it, or when the real-time clock restarted underneath it
  (`afNow < defHardUntil - 180`), the command is dropped and answered exactly as before - a funcret
  is never left open.
- The extension also had to move `defUntil` itself, not only skip the drop: the retry hands the
  deadline back to `DeferCommand`, which refuses an already expired budget (`now >= until`).

### 3. [minor] how=lost still triggered the hold
`LRG_OStim.BeginOutroHold` now returns for `asHow == "interrupted" || asHow == "lost"`. The server
writes no outro ticket for either, so the hold would have cost her an ordinary CHIM line and 25 s
of standing still.

### 4. [minor] Initiative could fire during the outro hold
New accessor `LRG_OStim.IsOutroHolding()` (returns `outroActive`), checked in
`LRG_Main.MaybeInitiative` right next to `IsSceneActiveOrStarting()`.

### 5. [minor] The forced end-of-scene snapshot could be skipped
`LRG_Main.MaybeSnapshot`: `snapBusy` now only stops an UNforced snapshot. A forced one waits 0.25 s
for the snapshot in flight and then goes ahead regardless (one log line when it does). The cost of
a duplicate snapshot is one message; the cost of skipping it is the whole goodbye, because the
server measures the outro against its 90 s freshness rail (its adults-only fail-closed rail, which
is not softened anywhere).

### 6. [minor] A command that must be refused occupied the single queue slot
- `LRG_OStim.CmdControl`: the `pendingVerb != ""` test (queue / "still moving into the previous
  position") now runs AFTER `IsPartnerName` and the adults-only re-check.
- `LRG_OStim.CmdControl`, the "no scene is running" branch: the "the scene is still starting" queue
  now also requires `IsPartnerName` and both actors adult - same hole, start path.
- `LRG_OStim.StartBlockedReason`: the `pendingVerb` test moved below PairBlockedReason,
  VerifyActors AND PrivacyBlockedReason, i.e. it is now the last thing tested.
- `CmdClothing` already re-checked actor and adulthood before deferring: unchanged.
No gate is bypassed by queueing in any case - a retry re-runs the whole chain from the top.

### 7. [minor] DeferCommand resume keyed on the cid
`defResumeCid` -> `defResumeKey` (= `command + "|" + parameter`), so a command with an empty or
malformed cid also keeps its ORIGINAL deadline instead of starting a fresh budget on every retry.
`defResumeHard` carries the ceiling the same way. `Maintenance()` clears all three.

### 8. [minor] fOutroMinScene (45) vs the server's scene-gain threshold (60)
Not silently aligned - the two thresholds mean different things (goodbye vs affinity gain) and the
game half only owns one of them. Written down where the owner will look: a NOTE block above
`fOutroMinScene` in settings.ini, and the MCM help for that slider now ends with "The goodbye and
the small relationship gain have separate thresholds (the gain needs a full minute), so a very
short scene can give you one without the other."

### 9. [minor] The corner note for an impossible request - built, but DORMANT
w4 forbade a fifth wire item this round, so nothing was added to the wire. The RECEIVE side is in
place, inert until the main session admits it:
- `LRG_Main.HandleCommand`: `note=<text>` on ANY `ExtCmdLRG_` command raises
  `Debug.Notification("LoreRim Glue: " + note)` (clipped to 120 chars), after the kill-switch check.
- `LRG_OStim.CmdControl`: `do=note` (with `ok=1`) is a no-op verb - no scene, no partner, nothing
  changed - that shows `text=` in the corner and answers "Noted.".
Both forms are covered because the audit named both. Nothing sends either key today, so this
changes no behaviour; when the main session says yes, the server half is a one-line change and no
game update is needed. Until then R2's corner note is still NOT delivered - the spoken
"can't do that here" is the only feedback, so the never-silence rail holds.

## Not done in this lane
- `tools\install_mo2.ps1:54` still stamps `version=0.1.0` into MO2's meta.ini. Out of lane (only
  `glue\game\LoreRimGlue\**` may be edited here). One line: read the version from
  `glue\server\lorerim_glue\manifest.json`. The installed mod on disk still reads 0.1.0.
- Nothing was installed or deployed (as instructed). The freshly built .pex in
  `glue\game\LoreRimGlue\Scripts` differ from the ones in `F:\Modlists\LoreRim\mods\LoreRim Glue\`
  from 19:17, so playtest 8 needs `install_mo2.ps1` with MO2 and Skyrim closed.

## What the server lane / the main session needs from here (w2 read-only findings)
1. `LRG_Main.HandleCommand` de-duplicates on `npc|command|parameter` within 5 s, in a ring of FOUR
   slots. The parameter carries `cid=`, so two commands of one compound reply are distinct and both
   pass. Requirements that follow:
   - every command of a reply needs its OWN cid (identical command+parameter inside 5 s is treated
     as the same command delivered twice - modevent and bridge - and the second is dropped);
   - at most TWO ExtCmdLRG_ commands per reply. Three commands x two delivery paths = six keys in a
     four-slot ring, and the glue holds exactly ONE deferred command, so a third would be refused
     with "Error: still moving into the previous position" anyway.
2. Order inside a compound reply matters: the glue carries them out in arrival order and the second
   waits for the first. "undress, then position" is right; the other way round costs a warp.
3. The queue waits `fQueueWait` (now 30 s) and keeps waiting while the first command is still in
   flight, up to twice that. A funcret always arrives - CHIM is never left holding the NPC.
4. STILL NEEDED, main session's call: the corner note. Game side is ready for either shape
   (`note=<text>` on any ExtCmdLRG_ command, or `do=note;text=<...>;ok=1` on SceneControl). Neither
   is sent by anyone today.

## Verification
- `glue\tools\compile.ps1` printed:
  `OK - .pex files copied to game\LoreRimGlue\Scripts (only the glue's own scripts); compiler
  reported 0 errors, 0 warnings` (all six .pex built; the 500-character .pex string guard passed).
- PAPYRUS RULE kept: no new or extended `{docstring}`; the longest one added (`IsOutroHolding`) is
  ~236 characters. The new header notes are inside the existing `;/ ... /;` block comment.
- `MCM\Config\LoreRimGlue\config.json` re-parsed as JSON after the help-text edits.
- Script version stays 310 (no wire change, no new command, no new snapshot key).
- `F:\Modlists\LoreRim` holds no saved `MCM\Settings\LoreRimGlue.ini`, so the new settings.ini
  defaults (fQueueWait 30) really are what the game will read after installing - MCM Helper has
  never written an override for this mod.

## For the owner (state of the machine, not a code change)
- Both halves of v0.3.1 were already deployed to HerikaServer and installed into MO2 before this
  fix pass (19:17 / 19:20 today), against both build reports. The server half is live.
- `relationship.repair` is live and has not fired yet: after the next line with Lisette, look for
  "relationship repair: Lisette -> Player set to 30" in
  `/var/www/html/HerikaServer/log/lorerim_glue.log`, then set `relationship.repair.enabled=false`
  in `ext/lorerim_glue/config/lrg_config.json`.
- The game half on disk is now NEWER than what is installed: install before playtest 8, with MO2
  and Skyrim closed.
