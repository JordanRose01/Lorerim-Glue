# pt10 - closing the five gaps the verifier found in the snapshot rail

LoreRim Glue, GAME lane. Written 2026-09-22 after `research/pt10-snapshot-verify.md`.
Ships as `LRG_Main.CurrentVersion` **502 -> 503**. **NOT installed** - MO2 untouched, no profile
file touched, no `.pex` copied into `F:\Modlists\LoreRim\mods\LoreRim Glue`.
`tools\compile.ps1` prints `OK - ... 0 errors, 0 warnings`.

---

## 0. The short version

502 gave the snapshot a black box: a stage mark written as the payload is built, and a breadcrumb
on each optional block, so that a Papyrus error - which unwinds the whole stack, because Papyrus
has no try/catch - is *noticed* by the next call, *named* in the log, and the guilty block retired
for the session.

The verifier found five places where that rail does not actually cover the path. All five are
closed here. Nothing else changed: the wire is byte-identical, the server is untouched, and one
file moved - `LRG_Main.psc` is the only source that differs from the installed 502 build (hash
comparison in section 4).

| | verifier defect | what it cost | closed by |
|---|---|---|---|
| D1 | nothing was stamped between `snapBusy = true` and the first stage mark | an abort in that stretch was **completely invisible**: no line, no retirement, and it repeated on every event for the rest of the session | three new stage marks, 21 / 22 / 23 |
| D2 | the killed attempt had already armed the throttle | "the very next event gets a snapshot" was false - it was the next event **10 s later** | the abort detector gives the throttle back |
| D3 | `NoteFollower()` ran outside the rail, after `snapBusy` was released | an abort there still killed the caller's `Watch()` and conversation hold, invisibly | stage 12, bracketed like the follower block |
| D4 | `FollowerSweep()` was unbracketed, and the load-time snapshot came after it | an abort on load cost that snapshot, unnamed | stage 13, bracketed; the snapshot now goes **first** |
| D5 | two stage names were misleading | a "stage 1" line read as "the actor facts"; a "stage 3" line read as "SFF" | both renamed |

---

## 1. D1 - the blind spot, and why it is the one that mattered

`LRG_Main.psc:1968` takes `snapBusy`. In 502 the first `snapWhere = 1` was thirty lines further
down, immediately before `LRG_Profile.BuildSnapshot`. Between them:

```
IsEnabled()  akNpc.IsDead()  HasKeywordString("ActorTypeNPC")  GetDistance(player)
AIAgentFunctions.getAgentByName(akNpc.GetDisplayName())        <- CHIM
GetOStim() / ost.IsActorInOurScene(akNpc)                      <- the intimacy module
```

The detector at the top of `MaybeSnapshot` is `if snapBusy` **and** `if snapWhere > 0`. An abort in
that stretch leaves `snapBusy` true and `snapWhere` at 0, so the second test never passes: no
`SNAPSHOT ABORTED` line, no block retired, and - because the stale flag is only given back after
the 30 s window - a non-forced call simply returned false until then and died in the same place
again. The rail was silent about exactly the stretch the verifier's section 2 showed had **never
been ruled out** (the save-file evidence in `pt10-snapshot-fix.md` 1.3 is consistent with
`PlaceFacts` never having run at all, so "the abort is inside `BuildSnapshot`" is not established -
only "inside `MaybeSnapshot`" is).

The fix is three marks rather than the one the verifier suggested, because the three suspects in
that stretch are three different mods and a log line that cannot tell them apart is worth much less:

```
snapWhere = 21   immediately after snapBusy = true   the entry checks (enabled / dead / ActorTypeNPC / distance)
snapWhere = 22   immediately before getAgentByName   CHIM's getAgentByName
snapWhere = 23   immediately before the OStim probe  the OStim scene check
```

Every early return in that stretch now clears `snapWhere` as well as `snapBusy`, so a stale mark
cannot survive a clean exit. (It could not have produced a false positive in any case - the
detector needs `snapBusy` too - but a rail whose invariant has to be argued is a rail that rots.)

**Numbering.** 1..10 stay the payload, 11 stays the send: the diagnostic contract in
`pt10-snapshot-verify.md` section 6 ("a stage 7 line means the SFF block") is unchanged. The new
labels are 21..23 for the stretch *before* the payload and 12..13 for the two blocks *after* it.
They are labels, not an order.

---

## 2. D2, D3, D4 - the three unbracketed calls

**D2 - the throttle.** `lastSnapActor` / `lastSnapTime` / `lastAnySnapTime` are armed at
`:1999-2001`, i.e. *before* the payload is built, so a killed attempt leaves the NPC throttled for
10 s (any NPC: 2 s). 502's own claim - "the very next event gets a full snapshot minus that one
block" - was therefore not true. The detector now gives all three back, exactly as `Maintenance()`
clears them on load, in the same six lines that report the abort and retire the block. The call it
is sitting in re-arms them the moment a payload really goes out.

Side effect, deliberate: a snapshot that died *after* the send (D3 below) now costs one duplicate
snapshot on the next event. A duplicate is one message; the alternative is a 10 s hole.

**D3 - `NoteFollower()`.** It sat after `snapBusy = false`, so the rail could not see it, and it
calls `FollowerAware()` and `LRG_Followers.IsGhost()` from inside a CHIM speech event. The payload
is already on the wire by then, so an abort costs no data - but it still unwinds the event and
takes that event's `Watch()` and `NoteConvLine()` with it, which is the second half of what
playtest 10 felt like ("she would not stay"). It is now bracketed by `snapWhere = 12` and
`SnapFolTry(true/false)`, and `snapBusy` is held across it.

`SnapFolTry` is the right breadcrumb here and not an approximation: `FollowerAware()` gates every
path `NoteFollower` can reach, and `FollowerAware()` is exactly what `SnapFolOk()` switches off.

The bracket is given back **before** the rented-bed rescan below it, because
`ost.RefreshRentedBed()` can call `MaybeSnapshot` again - a `snapBusy` still held there would look
like an abort to our own recursive call. Confirmed in the compiled bytecode (section 4).

**D4 - `FollowerSweep()`.** Two changes in `Maintenance()`:

* the load-time crosshair snapshot now goes out **before** the sweep instead of after it. The sweep
  walks po3's `GetActorsByProcessingLevel` over up to 40 actors and asks `LRG_Followers` about each;
  an abort in there used to take that snapshot with it. The sweep only *queues* repairs onto
  `OnUpdate` - it changes no value the payload carries - so the swap costs nothing;
* the sweep is bracketed with `snapBusy` / `snapWhere = 13` / `SnapFolTry`, so an abort in it is
  named by the first snapshot after the load and retires the follower block for the session,
  instead of failing silently on every single load.

**Still unbracketed, and named here rather than fixed:** `ost.RefreshRentedBed(player)` at the tail
of `MaybeSnapshot` (inns only, at most once per cell per 120 s). It cannot be given the same
bracket, because it re-enters `MaybeSnapshot` on a changed answer and the detector would read the
held flag as an abort. It is also the glue's own script over furniture references, not another
mod's native. Left as it is on purpose.

---

## 3. D5 - the two misleading names

*Stage 1.* Papyrus evaluates a call's **arguments at the call site**, so `LRG_Main.PlaceFacts()`
and the four MCM reads run while the mark says 1, before `BuildSnapshot` is entered at all. The
old name "the actor facts (head of the payload)" invited exactly the wrong conclusion. Now:
`the arguments (PlaceFacts, MCM reads) or the head of the payload`. Confirmed instruction by
instruction in the compiled output (section 4).

*Stage 3.* `WitnessScan`'s follower half only asks `LRG_Followers.FacCurrentFollower()`
(`Game.GetForm` + `GetFactionRank`) - **no SFF native runs there** - so a stage-3 line must not be
read as "SFF did it", although the `fol=` breadcrumb is deliberately held across the scan and a
stage-3 abort does still retire `fol=`/`witchim`. Now:
`the witness scan (no SFF native runs here)`, and `SnapStageName`'s own comment states that the
retirement there is a fail-safe, not an accusation.

One more wording fix in the same pass: the "no optional block was running" line said *"this is the
CORE payload"*. Stages 21-23 are not payload. It now says *"the CORE path"*.

---

## 4. Verification

**Build.** `tools\compile.ps1` ->
`OK - .pex files copied to game\LoreRimGlue\Scripts (only the glue's own scripts); compiler reported 0 errors, 0 warnings`,
2 rounds, 22 auto-stubs, no denylist hit, no over-long `.pex` string. Longest docstring compiled
into any of the three files this lane owns: 263 (`LRG_Main`), 271 (`LRG_Profile`), 192
(`LRG_Followers`) - all under the 300 limit, and every note added here is a `;/ block comment /;`,
which is not compiled in at all.

**Only one file changed.** SHA-256, project sources vs the installed 502 sources
(`F:\Modlists\LoreRim\mods\LoreRim Glue\Source\Scripts`): nine of ten identical, `LRG_Main.psc`
the only difference. `LRG_Profile.psc` and `LRG_Followers.psc` - the other two files this lane owns -
are byte-identical to what is installed, so **the payload cannot have changed**.

**The marks really land where the source puts them.** `game\LoreRimGlue\Scripts\LRG_Main.pex` was
read back with a read-only PEX disassembler written for this check (header + string table + debug
line table + bytecode; no file was written by it). `MaybeSnapshot` compiles to 162 instructions:

```
 74  L1968  assign      snapBusy true
 75  L1969  assign      snapBusyTime now
 76  L1978  assign      snapWhere 21          <- D1: nothing between the flag and the mark
 79  L1980  callmethod  IsEnabled ...
 87  L1980  callmethod  IsDead akNpc
 91  L1980  callmethod  HasKeywordString akNpc "ActorTypeNPC"
103  L1985  callmethod  GetDistance akNpc
111  L1991  assign      snapWhere 22
113  L1992  callstatic  aiagentfunctions getAgentByName
126  L2003  assign      snapWhere 23
131  L2007  callmethod  IsActorInOurScene ost
134  L2011  assign      snapWhere 1
138  L2015  callmethod  PlaceFacts self ...   <- D5: PlaceFacts runs at stage 1, as claimed
139  L2012  callstatic  lrg_profile BuildSnapshot ...
141  L2016  assign      snapWhere 11
144  L2017  callmethod  SendNpcMessage ...
145  L2027  assign      snapWhere 12          <- D3
146  L2028  callmethod  SnapFolTry true
147  L2029  callmethod  NoteFollower akNpc
148  L2030  callmethod  SnapFolTry false
149  L2031  assign      snapWhere 0
150  L2032  assign      snapBusy false        <- released BEFORE the rescan below
156  L2038  callmethod  RefreshRentedBed ost
158  L2039  callmethod  MaybeSnapshot self akNpc true
```

and the abort detector at the head of the same function:

```
  5  L1925  callmethod  SnapReportAbort
  6  L1926  assign      snapWhere 0
  7  L1927  assign      snapBusy false
  8  L1928  assign      snapBusyTime 0.0
  9  L1934  cast        ::temp301 none
 10  L1934  assign      lastSnapActor ::temp301   <- D2
 11  L1935  assign      lastSnapTime 0.0
 12  L1936  assign      lastAnySnapTime 0.0
```

and `Maintenance`'s tail:

```
113  L285   callmethod  Log ... "maintenance done, version ..."
114  L295   callstatic  game GetCurrentCrosshairRef
118  L297   callmethod  MaybeSnapshot self look true   <- D4: the snapshot goes FIRST now
120  L298   callmethod  Watch self look false
123  L307   assign      snapBusy true
126  L309   assign      snapWhere 13
127  L310   callmethod  SnapFolTry true
128  L311   callmethod  FollowerSweep
129  L312   callmethod  SnapFolTry false
131  L314   assign      snapBusy false
```

**The new strings are in the `.pex`**, so this build is distinguishable from 502 by inspection:
`the entry checks (enabled / dead / ActorTypeNPC / distance)`, `CHIM's getAgentByName`,
`the OStim scene check`, `the arguments (PlaceFacts, MCM reads) or the head of the payload`,
`the witness scan (no SFF native runs here)`, `the follower note after the send`,
`the follower sweep on load`, `CORE path`.

**Nothing new can abort.** Everything added is a member write (`snapWhere`, `snapBusy`,
`snapBusyTime`, the three throttle fields), a call to `SnapFolTry` (one member write) or
`Utility.GetCurrentRealTime()`. No native of another mod, no member call on anything that can be
`None`, no latent call - so the `Utility.Wait` note in `pt10-snapshot-verify.md` 1.7 still holds:
no wait runs inside a CHIM speech event.

**No server coupling.** Nothing in `server\lorerim_glue` reads the game script version, so 503 is
not a wire change and the server lane has nothing to match. The server tests and
`flows\run_flows.php` were not run: no server file, no payload key and no key order changed.

---

## 5. Behaviour that is deliberately different from 502

Everything else is identical. These four are the point of the change:

1. An abort **before** the payload (stages 21-23) now produces a `SNAPSHOT ABORTED` line naming
   which of the three mods was being called, instead of nothing at all - and the flag is given back
   immediately instead of after 30 s.
2. After any detected abort the throttle is cleared, so the next event really sends. On an abort
   that happened after the send (stage 12) that means one duplicate snapshot.
3. An abort in `NoteFollower` or `FollowerSweep` now retires the follower block for the session
   (`fol=` and `witchim` absent, which the server already reads as "say nothing") instead of being
   invisible. As before, `Maintenance()` clears the Bad flags on every load, so a new `.pex` always
   gets a fresh try.
4. On load the crosshair snapshot goes out before `FollowerSweep()` instead of after it.

---

## 6. What to do next (nothing is installed)

MO2, SkyrimSE and skse64_loader were all confirmed **not running** while this was written, so the
install is clear to run whenever the owner wants it.

1. `tools\install_mo2.ps1` with MO2 and Skyrim closed. If it refuses, the owner is launching the
   game - stop and wait.
2. Load and check `lorerim_glue.log` for `maintenance done, version 503`. **If it says 502 the new
   `.pex` did not load** and nothing below means anything.
3. The line that proves the fix, unchanged from the 502 brief: the server's gate line must read
   `gate npc=<name> mode=private|public|closed|follow ... profile=<word>`, with `profile=` no
   longer `-` and `reasons=` no longer carrying `no_fresh_snapshot`.
4. If a block still dies, **one** line names it (at most six per session), and now it names the
   pre-payload stretch too:
   ```
   GAME SNAPSHOT ABORTED in CHIM's getAgentByName (stage 22): no optional block was running - this is the CORE path, please send the Papyrus log
   GAME SNAPSHOT ABORTED in the follower block (fol=) (stage 7): fol= / witchim are off for this session
   ```
   Traffic resumes from the **next** event now, not 10 s later.
5. Papyrus logging is on (verify pass section 4). If a `stage 21/22/23` line appears,
   `Documents\My Games\Skyrim Special Edition\Logs\Script\Papyrus.0.log` names the exact function
   and line - and that stretch is the one the elimination argument never covered, so that log is
   what would settle it.
6. Still not fixed, still server lane: `lrgRecogniseIntent()` is not called on a `silent` turn
   (`lib/lrg_actions.php:1120`), so `intent=` stays `none` whenever the gate is silent.

Out of this lane and untouched here: the version marker in `glue\README.md` (502) and
`server\lorerim_glue\manifest.json` (0.5.1, which is what `install_mo2.ps1` writes into `meta.ini`).
