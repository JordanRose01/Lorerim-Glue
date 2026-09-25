# pt7 verify - GAME side (v0.3.1, script version 310)

Independent verifier, read-only except this file (plus the mandated `compile.ps1` run, which
rewrites the six `.pex` - see 8.3). Lens: `glue\game\LoreRimGlue\**` and the game half of the
game/server contract.

## VERDICT: SHIP

No critical defect. No path was found in which the outro hold can leave the NPC frozen. The build
compiles clean, every new MCM key has an ini default of the right type, version 310 is really
compiled in, and the wire keys the game now sends and reads match the server that is deployed.
Two MAJOR items (one of them a one-slider tuning the owner can do from inside the MCM, one a
documentation gap) and eight minor ones are listed below; none of them blocks playtest 8.

---

## 1. Method

1. `glue\tools\compile.ps1` re-run from scratch. Output:
   `rounds: 2 ; auto-stubs created: 23` / `compiled: LRG.pex, LRG_Main.pex, LRG_MCM.pex,
   LRG_OStim.pex, LRG_PlayerAlias.pex, LRG_Profile.pex` /
   **`OK - .pex files copied ...; compiler reported 0 errors, 0 warnings`** (exit 0).
   The built-in 500-character `.pex` string guard passed for all six.
2. Independent docstring scanner (own `{...}` parser that skips `;` line comments, `;/ /;` blocks
   and string literals) over all six sources.
3. Independent `.pex` string-table walker: longest string per file, and a full ordinal comparison
   of my build against the one installed in MO2.
4. `diff` of all three changed sources against their `*.psc.bak-docstrings` snapshots, to see every
   changed line rather than trusting the build report's summary.
5. Full read of every new / changed function; every outro exit path traced to its release.
6. MCM `config.json` control ids vs `settings.ini` keys, and every id against the code that reads it.
7. Cross-check against the deployed server (`/var/www/html/HerikaServer/ext/lorerim_glue`) for the
   contract items the game now depends on.

---

## 2. What is confirmed good

**2.1 Build rules.** Longest docstring in the whole mod: **282** characters
(`LRG_OStim.psc:2394`), all six files under 300 - claim confirmed independently. Longest string of
any kind inside a `.pex`: **312** characters, and it is *not* a docstring but the
`FullStripActor()` action-name CSV (`LRG_OStim.psc:1792`); the split `FullStripTarget()` pieces
(`:1798-1800`) are each ~210. Both are far under the 500 guard. The CSV split preserves the list
byte-for-byte (`a + b + tail` = the old single constant).

**2.2 The outro hold has no stuck-NPC path.** `BeginOutroHold` (`LRG_OStim.psc:720`) sets
`outroHeld = true` **before** `SetDontMove(true)` (`:765-766`), so a failing native leaves the
release armed, not the flag. Every exit reaches the one release function:

| exit | where | verified |
|---|---|---|
| her line is out | `TickOutro:923` -> `OutroLineState:806` | yes |
| timeout `fOutroHold` | `TickOutro:919` | yes |
| game load (unconditional, first statement) | `Maintenance:300` | yes |
| self-heal: clock restart or hold older than `2*budget+30` | `TickOutro:877` | yes |
| a hold only the save remembers (`outroActive` false, actor set) | `TickOutro:860-865` | yes |
| combat on either side, or the player died | `TickOutro:897` | yes |
| distance > `fOutroFar` | `TickOutro:907` | yes |
| another cell (only when either is in an interior) | `TickOutro:913` | yes |
| she is dead / disabled / unconscious / not 3D-loaded | `TickOutro:892` | yes |
| a new scene (`starting`, `pendingStart`, a live thread) | `TickOutro:888` | yes |
| a new `StartIntimacy` | `CmdStart:1435` (first line) | yes |
| any thread-start event | `OnOStimThreadStart:3319` | yes |
| stop hotkey / `stop` verb | `StopScene:3292` (first line) | yes |
| kill switch / `bEnabled` / `bIntimacyEnabled` / `fOutroHold` -> 0 | `TickOutro:871,882` | yes |

`Tick()` looks at the hold **before** the `ostimPresent` early return (`:3593-3598,3611`), and
`LRG_Main.OnUpdate` (`LRG_Main.psc:335-342`) calls `ost.Tick()` with **no** `IsEnabled()` guard, so
the kill switch cannot strand the release. The tick is kept alive at 0.5 s by `TickOutro:927` and
re-armed by her speech events (`NotePartnerSpeech:517`); `RequestTick`'s "an earlier tick is already
on its way" branch (`LRG_Main.psc:325`) cannot push a pending 0.5 s tick back.

**Mid-`Utility.Wait` save/load (explicitly asked).** `FinishThread` waits 1.5 s
(`:3556`) and, on the redress path, 2.5 s more (`:3566`). If the save is loaded while the stack is
parked there: `LRG_PlayerAlias.OnPlayerLoadGame` -> `LRG_Main.Maintenance` -> `LRG_OStim.Maintenance`
-> `ReleaseOutroHold` runs first (`:300`); when the stack resumes, `if outroActive`
(`:3559`) is false, so no late outro is requested, and no duplicate `ev=end` is sent because the
`if partnerName != ""` test at `:3548` was already evaluated before the wait. Confirmed safe.
`finishing` has no early-return path between `:3515` and `:3574`, so it is always cleared.

**2.3 The hold does not fight the redress / weapon give-back / CHIM's busy flag.** The hold only
sets `SetDontMove` and one `SetLookAt`; `RestoreWeapons` / `RedressRemembered` / `RedressBaseline`
are inventory calls. It in fact *helps*: `RedressBaseline` now runs ~4 s after the end while she is
still standing there. `ResetState` (`:414-418`) clears CHIM's `setAnimationBusy(0)` while
`partnerName` is still set, before it is blanked - so she is free to speak during the hold.
`ClearLookAt` is never called, as documented.

**2.4 `after=` (w1).** Armed only in the `if starting` branch of `OnOStimThreadStart` (`:3372-3378`),
i.e. only for a start the glue itself made, and only when it differs from the scene that actually
started. Cleared by `FinishThread:3522`, by `OnOStimThreadStart:3322`, by `ResetState:436` and by
`Maintenance:306`; `TickAfterScene` is reached only from `ThreadTick`, which only runs while
`OThread.IsRunning(0)`, and it re-checks that itself (`:1053`). It cannot fire into a scene the
actors do not fit: `NavigateBlockedReason` (`:3271`) rejects a mismatched actor count and a
transition before anything is called. Window 4..40 s with a 1 s retry on the transient reasons, then
`after= gave up`. Warp is gated on `bAllowWarp:Intimacy` and deliberately ignores `nowarp=` - correct
per R2 (this is the player's own request). The server really does emit the key
(`lib/lrg_actions.php:1328-1333`), so the two halves match.

**2.5 `dur=` / `how=` (w3) on every end path.**

| end | `how=` | verified |
|---|---|---|
| OStim's own end event | `finished` (nobody claimed it) | `OnOStimThreadEnd:3506` -> `FinishThread:3534-3537` |
| wind-down reaching its linger | `finished` (claimed at `ThreadTick:3743` before `StopScene`) | yes - `StopScene:3293` does not overwrite a set `endHow` |
| `stop` verb / stop hotkey | `stopped` | `StopScene:3293-3295` |
| thread gone without an end event | `lost` | `Tick:3652-3653` |
| load with no thread running | `interrupted`, `dur=0` | `Maintenance:404` |

`endHow` is cleared at every thread start (`:3321`) and by `Maintenance:310`, so a stop hotkey
pressed outside a scene cannot poison the next end. One cosmetic wrinkle: a start the player
cancelled while OStim was building reports `how=finished` with `dur` ~0 (`OnOStimThreadStart:3356`
-> `OThread.Stop` -> `FinishThread` with `endHow` empty). Both consumers gate on `dur >= 45/60`, so
it is inert.

**2.6 Two commands in one reply (w2).** The 4-slot de-dup ring (`LRG_Main.psc:31-33, 388-406`) is a
real fix, not a cosmetic one: I re-derived the old behaviour from `LRG_Main.psc.bak-docstrings`
(single `lastCmdKey`), and with the realistic delivery order `A-bridge, B-bridge, A-event, B-event`
**both** commands were executed twice. The key is stored *before* dispatch, so a mod event racing a
blocked bridge call (e.g. during `GuardStart`'s 2.5 s wait loop, `:1998-2002`) is still deduplicated.
`cmdKeys` is rebuilt for a save that comes from 300 (`:389-393`) and on every load (`:89-91`).

`CmdFurniture` queues under the command name `ExtCmdLRG_SceneControl` (it is reached from
`CmdControl`), so `TickDeferred`'s dispatch table (`:1025-1033`) covers it - no "unknown command"
hole. The slot is freed before the retry and the original deadline is carried
(`defResumeCid` / `defResumeUntil`, `:1022-1023, 984-986`), so a command cannot restart its budget
every second. A third command in one reply is refused, as agreed. A queued command is answered on
every in-session path (`ResetState:435` -> `DropDeferred`, `TickDeferred:1012`), never left open.

**goto+goto is handled now.** The server builder's note that "the GAME still drops goto+goto
(`navInFlight`)" is out of date: `CmdControl`'s `do=goto` branch defers exactly that reason
(`:2938-2942`). Nothing is needed from the game for it - only the budget in M1 below.

**2.7 Everything the round asked to be intact, is.** The `diff` against the pre-round snapshots
shows only (a) docstring -> `;/ /;` conversions, (b) the long CSV split, (c) the v0.3.1 additions.
`HoldOStimGates` / `ReleaseOStimGates` / `GlueUndressNode` / `PrepareWeapons` / `SettleWeapons` /
`GuardStart` / `RestoreWeapons` / `AnnounceState` / `ParkVerb` / `ForceListener` / `ReleaseListener`
are untouched in behaviour. `ReleaseOStimGates` is still the first statement of `FinishThread`
(`:3518`), before the hold.

**2.8 MCM.** 40 controls, 40 ini keys, no orphans, every type matches the reader
(`fOutroHold` / `fOutroSettle` / `fOutroMinScene` / `fOutroFar` / `fQueueWait` are all
`ModSettingFloat` sliders read through `SettingFloat`). Every code clamp is inside or equal to its
slider range. `fOutroFar`'s missing-key guard is real (`:901-906`: below the slider minimum falls
back to 1500 instead of releasing her instantly). Failure direction of every new key is
"the feature is off", never "the NPC is stuck". No saved `MCM\Settings\LoreRimGlue.ini` exists
anywhere under the modlist, so these defaults are what the game will actually use.

**2.9 Version.** `CurrentVersion = 310` (`LRG_Main.psc:15`), printed from the compiled-in constant
with the saved number beside it (`:139`); the string `maintenance done, version ` is verified
present in `LRG_Main.pex`. `IsCourting` is now `IsCourting(Actor, Actor)` with the player named
(`LRG_Profile.psc:94-101`); the only call site (`:342`) already had him, and nothing else in the
project calls it.

**2.10 Old/new interop.** Scripts 310 against an old (0.3.0) server: `dur=` / `how=` are additive
k=v and ignored; the `outro` request would find no cue and fall through to CHIM's ordinary reply,
so she still speaks and the hold still ends early. New server against scripts 300: the server's
duration/how fall back to the stored push (`lib/lrg_actions.php:459-470`), `after=` is ignored, no
outro is ever requested - **but** the old 1-slot de-dup would double-execute both halves of a
compound reply (2.6). That combination does not exist on this machine (see 8.1), and the fix is
already in 310, so it is a deployment-order note rather than a defect.

---

## 3. MAJOR

### M1 (game) The queue budget is shorter than the game's own navigation deadline

`GoToScene` gives a navigation 25 s (`LRG_OStim.psc:2984`, `navDeadline = afNow + 25.0`), and
`NavigateBlockedReason` answers "still moving into the previous position" for the whole of it
(`:3257-3259`). The queue that w2 added gives the second command only `fQueueWait:Intimacy`
= **20 s** (`settings.ini:28`, clamp 0..60 at `:973-978`). So a compound "...and then <position>"
whose first move takes a long route (`iNavigationReach` default 5, OStim transitions ~2-5 s each)
has its second half dropped by `TickDeferred:1012` while the first move is still legitimately in
flight - the exact failure w2 exists to remove, and the owner's F2 complaint.

The same mismatch is worse on the start path, which is defensive today: `CmdStart`'s announce wait
is up to `fSayFirstMaxWait` = 20 s (`AnnounceBudget:585`, clamp 30) plus OStim's build time, so
`starting` can outlive a 20 s queue by 5-10 s. The deployed server only pairs commands inside a
confirmed running scene (`lib/lrg_actions.php:1730-1731`), so it never sends start+X today - but
`CmdStart:1440` and `CmdClothing:2640` both implement that queue, and it cannot succeed as tuned.

**Smallest fix:** `settings.ini` `fQueueWait = 30` (>= the 25 s navigation deadline; the slider
already goes to 60). The owner can do this from the MCM without reinstalling anything. A tighter
fix for a later pass: in `TickDeferred`, do not expire while the very condition that caused the
queue is still true (`starting || pendingStart || navInFlight || pendingVerb != ""`), with the
existing `2x` ceiling as the backstop.

### M2 (docs) The outro request is an undocumented fifth wire item

R3 works only because the **game** fires
`AIAgentFunctions.requestMessageForActor("outro", "lrg_scenetalk", <npc>)`
(`LRG_OStim.psc:853`) and the **server** keys on exactly that
(`lib/lrg_actions.php:563-566`, `prompts.php:33`). I verified the two match, including
`lrgIsTickText`'s tolerated `Name:` prefix. But this contract exists only inside two build reports:
`PROTOCOL.md` 1.2 still documents `ev=end` as carrying only `ev/npc/cid/scene/byglue`, section 2
lists no `after=` on `StartIntimacy`, and section 1.4 does not mention the `outro` tick at all.
Both builders flagged the file as "outside my lane", so nobody owns it and the next round will
re-derive it from memory.

**Smallest fix:** three additive rows in `PROTOCOL.md` (ev=end `dur=`/`how=`; StartIntimacy
`after=`; lrg_scenetalk text `outro`, game -> server, answered as an ordinary speech turn), and the
main session's explicit blessing of the fifth item.

---

## 4. MINOR

**m1 (game) `how=lost` is held for a goodbye the server will never write.**
`BeginOutroHold:728` excludes only `interrupted`, but the server refuses a ticket for `lost`
(`lib/lrg_actions.php:524`). A scene whose thread vanished after >= 45 s therefore holds her, spends
an LLM+TTS turn and gets an ordinary line instead of an outro. Not a stand-still (she speaks, which
releases the hold), but wasted. *Fix:* `|| asHow == "lost"` in the same condition at `:728`.
(The `interrupted` term there is already dead code - that end never goes through `FinishThread`.)

**m2 (game) an NPC-initiative tick can fire during the outro hold.**
`MaybeInitiative` (`LRG_Main.psc:641-682`) gates on `ost.IsSceneActiveOrStarting()`, which is
`starting || OThread.IsRunning(0)` (`LRG_OStim.psc:465-473`) - false during the hold. Nothing
consults the outro state, so she can be handed an "approach" turn in the middle of her goodbye.
*Fix:* an `IsOutroHolding()` accessor on `LRG_OStim`, checked next to `IsSceneActiveOrStarting()`.

**m3 (game) the forced end-of-scene snapshot can be silently skipped.**
`FinishThread:3545-3546` forces a snapshot so the server's 90 s adult/freshness rail can answer the
outro. `MaybeSnapshot` returns false while `snapBusy` even with `abForce`
(`LRG_Main.psc:756-761`), so a snapshot already in flight loses that guarantee and the server logs
`outro dropped ... the rails do not hold`. *Fix:* let `abForce` past the `snapBusy` gate, or retry
once after 0.2 s.

**m4 (game) the transient queue is entered before the partner / adults-only / privacy re-checks.**
`CmdControl` queues at `:2851-2858` before `IsPartnerName` (`:2860`) and the adult re-check
(`:2865`); `StartBlockedReason` returns "still moving into the previous position" at `:2193` before
`PairBlockedReason` at `:2198`. No gate is bypassed - the retry re-runs the whole chain, so the
answer is still correct - but a command that must be refused occupies the single queue slot for up
to `fQueueWait`, during which the real partner's second command is refused outright
(`DeferCommand:969-971`). The build report's "nothing unauthorised is ever parked" holds only for
the `ok=1` server gate. *Fix:* move the transient test below the partner/adult checks in both places.

**m5 (game) `DeferCommand` can retry forever when `cid` is empty.**
`:984-986` keeps the original deadline only when `asCid != ""`; with an empty cid each retry starts
a fresh `fQueueWait`, so `TickDeferred:1012` never expires it and the funcret can be arbitrarily
late. Needs a malformed command (the server always sends `cid=`). *Fix:* key the resume on
command+param, or treat an empty cid as "not resumable" and let it expire.

**m6 (both) R2's corner note for an impossible in-scene request exists on neither side.**
The game only raises `Debug.Notification` from `ReportResult` (`LRG_Main.psc:371-373`), which needs
a funcret, i.e. a command; when the server decides an act is impossible it emits only the spoken
line. "Never silence" is satisfied (she speaks), the note is not. The server report lists it as NOT
DONE and "a game-side need"; the game report's section 9 does not carry it, so it fell between the
lanes. *Fix (later round):* an additive `note=` on an existing command, or a no-op
`ExtCmdLRG_SceneControl do=note`.

**m7 (game/server) `fOutroMinScene` 45 vs the relationship gain's 60.**
A 45-59 s scene gets a full goodbye but no affinity gain (`settings.ini:49` and
`lib/lrg_actions.php:521` both 45; R1's completed-scene test is `dur >= 60`). Probably intended, but
it is an undocumented asymmetry the owner will notice. *Fix:* state it, or align the two.

**m8 (tools) `install_mo2.ps1:54` still writes `version=0.1.0`** into MO2's `meta.ini` - and the
installed mod's `meta.ini` on disk indeed reads `version=0.1.0` for a v0.3.1 build. Cosmetic, but it
is the one place the owner would look to confirm what is installed.

---

## 5. Claims I could not confirm

- **Nothing was tested in game.** There is no Papyrus harness and MO2 must stay closed. Everything
  above is compile-verified and read-verified only. In particular: whether CHIM accepts the `outro`
  request when the NPC has just been released from the listener lock, and whether `SetDontMove` plus
  `EvaluatePackage` interacts cleanly with CHIM's own dialogue package, are unverified by anyone.
- **Deviation 2 (the listener is released at scene end) is an accepted risk, not a verified one.**
  If the player answers her goodbye and she is not under the crosshair, the reply routes to the
  Narrator. The design note ("she is standing in front of the player anyway") is plausible and
  untested. Watch this in playtest 8.
- `Maintenance` **forgets** a queued command instead of answering it (`:303`). The justification
  ("CHIM's command state went with the load") is unverified. It matches the pre-existing treatment
  of a parked verb (`ClearPendingVerb` at `:341`), so it is at least consistent.

---

## 6. Answers to the round's specific questions

- *Can the hold leave her stuck?* No path found - see 2.2, including the mid-`Utility.Wait` load.
  The one residual is inherent: if the Papyrus update chain dies without a game load, only a load
  clears `SetDontMove`. The self-heal lives in that same chain, which is the honest limit of the
  design.
- *Does the hold fight the redress / weapons / busy flag?* No - 2.3.
- *Can `after=` fire after the scene ended, or into a scene the actors do not fit?* No - 2.4.
- *Are two back-to-back commands executed in order, and is a stale second dropped after a scene end?*
  Yes and yes - 2.6 - except for the budget in M1.
- *Are `dur=`/`how=` right on every end path?* Yes - 2.5 (one inert cosmetic case).
- *Gates / undress node / weapons / say-first / listener lock intact?* Yes - 2.7.
- *MCM ids, defaults, types?* Complete and correct - 2.8.
- *Version 310 reported?* Yes - 2.9.
- *Behaviour against the old server?* Safe - 2.10; the risky direction is the *old game* against the
  new server, which is already moot here (8.1).

---

## 7. Fix list, in the order worth doing

1. `settings.ini` `fQueueWait = 30` (M1) - or just move the slider in the MCM before playtest 8.
2. `PROTOCOL.md`: three additive rows + the fifth wire item (M2).
3. `BeginOutroHold:728` - also skip `how == "lost"` (m1).
4. `MaybeInitiative` - skip while the outro hold is live (m2).
5. `MaybeSnapshot` - let `abForce` past `snapBusy` (m3).
6. m4, m5, m6, m7, m8 as they fit.

---

## 8. Environment facts the owner should know

**8.1 Both sides are ALREADY live, contrary to both build reports.**
- Server: `/var/www/html/HerikaServer/ext/lorerim_glue` is **0.3.1** (manifest `"version": "0.3.1"`,
  `lrgIsOutroTick` present), mtime **19:20** today. The server report says "Not deployed".
- Game: `F:\Modlists\LoreRim\mods\LoreRim Glue\Scripts\*.pex` are the **v0.3.1** build, mtime
  **19:17**; `MCM\Config\LoreRimGlue\{config.json,settings.ini}` are **byte-identical** to the
  project copies (18:34) and carry all five new keys. The game report says "Not installed".

So the pair is matched and consistent - which also removes the 2.10 double-execution hazard. But
note that **R1d's relationship repair is config-driven and the repair config is now on the live
server**; that is the server lane's to confirm before the owner loads a save.

**8.2 No MCM settings override exists**, so `settings.ini`'s defaults (including
`fOutroHold = 25`) are what playtest 8 will run with.

**8.3 My `compile.ps1` run rewrote the project's six `.pex`** (19:26). They are semantically
identical to the installed 19:17 build: same size, and an ordinal comparison of the full `.pex`
string tables is **identical for all six**. The byte differences are the 8-byte compilation
timestamp and the compiler's non-deterministic string-table ordering (e.g. `hidden` /
`conditional` swapped in `LRG_PlayerAlias.pex`). Nothing needs reinstalling; if you prefer, the
installed copies are the builder's own and are unchanged.
