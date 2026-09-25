# PT8 build — the CONVERSATION HOLD

Built against `research/pt8-walkaway.md` §5 and `glue/OWNER_ADDENDA.md` item 8.
Game script version **401**, server manifest **0.4.1**. **Nothing was installed and nothing was
deployed** — the round ends at "compiles, lints and tests clean".

---

## 1. What it does

While a CHIM conversation with an NPC is live — any line from the player to her or from her to him
inside `fConvHoldWindow` (default 45 s, pushed out by every further line) — she **stops**
(`SetDontMove(true)`) and **faces the player** (`SetLookAt(player, false)`, set once and never
cleared, because CHIM owns the look-at and clears it itself ~90 s after her last line).

Her AI package is **not touched** by default: no priority fight with AI Overhaul or Jobs Overhaul.
The priority-60 package layer the brief offered is built but **opt-in and OFF**
(`bConvHoldPackage`), exactly as specified.

Everything lives in `LRG_Main.psc`, which already owns the CHIM speech events, the single update
timer, the settings accessors and the log. `LRG_Dialogue.psc` was **not touched**.

## 2. Where it is

`glue\game\LoreRimGlue\Source\Scripts\LRG_Main.psc` — one new section, after the initiative block:

| function | job |
|---|---|
| `ConvWindow()` / `ConvFarUnits()` | the two clamped settings, with the "a missing MCM key reads as 0" guard on the distance |
| `NoteConvLine(npc, now, weak)` | one line from either side: refresh, or begin. `weak` = the player merely looked at her (crosshair), which may extend a live hold but never start one |
| `BeginConvHold(npc, now)` | begins it; every refusal is checked here, once |
| `ConvRefuseReason(npc)` | the refusal list (below) |
| `ConvIsArrest` / `ConvQuestPackage` | the two composite refusals |
| `ConvDoNothingPackage` / `ConvApplyPackage` | the opt-in package layer (`AIAgent.esp 0x027374`, `ActorUtil.AddPackageOverride(.., 60, 0)`) |
| `ReleaseConvHold(why)` | **the only way out**; idempotent, clears every field before touching the actor |
| `TickConvHold(now)` | first in `OnUpdate`, 1 s while a hold lives, free otherwise; also the self-heal |
| `NoteChimAction(npc, cmd)` | the CHIM-command pre-hook (see §5 — dormant on CHIM 3.3.2) |
| `IsConvHolding()` / `IsConvHeldActor(npc)` | read by the snapshot |

Wired into the four existing events — `OnChimSpeechStarted` (both branches, the player's own voiced
line included), `OnChimSpeechStopped` (both branches), `OnChimTextReceived`, `OnCrosshairRefChange`
(weak only) — plus `OnKeyDown`, `OpenVanillaDialogue()`, `Maintenance()` and `HandleCommand()`.

`LRG_OStim.psc` gained three one-line calls — `CmdStart` ("a scene is starting"),
`OnOStimThreadStart` ("a scene began") and `BeginOutroHold` ("a scene is ending") — so the two holds
are mutually exclusive with no window at all, not just within one tick.

## 3. Never held

Checked once on entry, cheapest state first, and every one of them re-checked on every tick:

- an OStim scene of ours, the outro hold, a live `LRG_Dialogue` session, any menu / the Dialogue Menu
- hostile (`IsHostileToActor` **or** `GetCombatState() != 0`), combat on either side, the player dead
- an engine scene on either side (`GetCurrentScene() != None`) — the bard mid-song case
- a quest-owned package (`GetCurrentPackage().GetOwningQuest() != None`)
- a guard mid-arrest: `IsArrested()` on either actor, or `IsGuard()` + crime gold (normal or violent)
- followers / teammates (harmless but pointless — skipped, as instructed; no MCM toggle for it)
- children (`IsChild()`) and anything without `ActorTypeNPC`
- already stationary: sitting, asleep, mounted, swimming, flying, bleeding out, in a kill move
- dead / disabled / unloaded / unconscious, or further away than `fConvHoldFar`
- an NPC that never produced a snapshot (`!= lastSnapActor`, i.e. not a CHIM agent in range)
- the glue disabled, the kill switch, or **dry run**

## 4. Released — one function, every path

`ReleaseConvHold` is called on: the window expiring (never across her own sentence — while
`isActorTalking` or a line was heard < 4 s ago the deadline moves on in 5 s steps), distance
> `fConvHoldFar`, **the player sneaking away** (sneaking *and* past half that distance), combat on
either side, the kill switch / glue off / dry run / `fConvHoldWindow` at 0, `Maintenance()` (first
and unconditional, because `SetDontMove` is written into the save), an OStim scene or the outro hold,
a Dialogue Menu or an `LRG_Dialogue` session, her death / disable / unload, a different cell (only
compared where it means something), an arrest, a quest taking her package, another NPC starting to
talk, the stop hotkey, the vanilla-dialogue hotkey, a CHIM movement command, and a **self-heal at
3 × window + 30 s**. A hold that only the save remembers is released by the same function from
`Maintenance()` and again by the tick's leftover branch.

## 5. EndConversation — and the finding that shaped it

Per the brief the hold **keeps** itself through an incoming `EndConversation` for the held NPC: it is
logged, `SetDontMove` is simply re-asserted on the next tick (nothing fights CHIM's `ResetPackages`,
which does not clear `SetDontMove` anyway), and the hold ends when the window does. That is what
`NoteChimAction` does, and it releases instead for the 16 catalog actions that really move or
re-task her (`CONV_MOVE_ACTIONS` — `ComeCloser`, `FollowPlayer`, `TravelTo`, `ReturnBackHome` …).

**Finding: on CHIM 3.3.2 that hook can never fire for CHIM's own actions.** Verified in the DLL:
`CHIM_CommandReceived` does not exist as a string in `AIAgent.dll` at all; the only producer is
`AIAgentAIMind.SendExternalEvent`, and the DLL's own dispatch table pairs it with the **`ExtCmd`**
branch alone (`ExtCmd … DispatchExternalCommand | SendExternalEvent` / `IntCmd … SendInternalEvent`).
`EndConversation` is dispatched by the DLL calling the Papyrus global
`AIAgentAIMind.EndConversation` directly, and CHIM's own `CommandManager` has no `EndConversation`
branch. So **no core CHIM action reaches any event this mod can register for.** The pre-hook is kept
(one string compare per command, correct the day CHIM routes its actions through the mod event) but
it is documented in the script as dormant.

The working half is therefore server-side, and it is the one the brief called clean:

**`lrgDlgHideEndConversationOnHold()`** in `lib/lrg_dialogue.php`, called at brace depth 0 at the end
of `functions.php` — after both lanes have built their offer, so it only ever *removes*. While this
NPC's own snapshot says `hold=1` and is no older than `dialogue.hold_max_age_seconds` (45, one
window), `EndConversation` is filtered out of `ENABLED_FUNCTIONS`, so the model is never offered the
one leave cause with hard log evidence (8 firings in a single session, pt8 §3.1). Config
**`dialogue.hold_hides_end_conversation`, default `true`**. Absent key, `hold=0`, a stale snapshot, a
different NPC, the config off, or a database that is not there → nothing happens and CHIM's own
behaviour applies. This is the per-turn, per-NPC version of the owner's Action Editor toggle (§0.1),
and it does not replace it: disabling the row in the Action Editor is still the blanket fix.

**Gap the owner should know about:** because of the same DLL routing, the *movement*-command release
(`ComeCloser` fired 18× and `FollowPlayer` 10× in one session) is dormant too. If playtesting shows
her stalling against a CHIM movement command, the fix is the same shape — add those codes to the
server-side hide while `hold=1` — but that was outside this round's server scope, which the task
limited to `EndConversation`.

## 6. Two new snapshot keys

`LRG_Profile.BuildSnapshot` now emits, before `class`:

- **`dist=<units>`** — the NPC's distance from the player. The snapshot carried no distance at all,
  which is exactly why pt8 §3.4 could not answer "how far away was she when she answered". No server
  rule reads it; it is a log fact.
- **`hold=0|1`** — 1 only while the hold really has her. This is what the server filter reads.

Both optional and additive; `v` stays `2`. Documented in `PROTOCOL.md` §1.1 with the server handling.

## 7. MCM — new page "Conversation" (between *Initiative* and *Scene talk*)

| key | default | control |
|---|---|---|
| `bConvHold:Conversation` | **1** | "She stays with you while you are talking" |
| `fConvHoldWindow:Conversation` | **45** | "Let her go if nobody speaks for" (0–180 s, **0 = off**) |
| `fConvHoldFar:Conversation` | **900** | "Let her go if you walk this far away" (300–4000) |
| `bConvHoldPackage:Conversation` | **0** | "Also suspend her daily routine (stronger)" |

All four have a `[Conversation]` line in `settings.ini` with the in-code default, and the section
carries the "a missing key reads as 0/FALSE" note — `bConvHold` and `fConvHoldWindow` fail to *off*,
`fConvHoldFar` falls back to its default in code rather than releasing her instantly. Help text is
in the owner's voice, no jargon, and says what is held (her feet) and what is not (her voice, her
gestures).

## 8. Log lines

Format `conv hold <npc> <state> reason=<..> [held=<s>]`, through `LogC` (so `bDebugLog:General`
gates them, and each carries the hold's own cid):

```
conv hold Lisette on reason=a line window=45
conv hold Lisette refresh reason=a line held=12.4        <- throttled to one per 10 s
conv hold Lisette keep reason=CHIM ended the conversation held=18.2
conv hold Lisette release reason=you walked away held=31.0
conv hold Lisette release reason=nobody spoke held=47.2
conv hold Lisette release reason=the game was loaded held=0.0
conv hold Lisette release reason=a hold was left over held=0.0     <- self-heal, should be rare
conv hold Lisette skip reason=a scene                    <- one per NPC per 30 s
```

Refusals that are simply normal and frequent — our own OStim scene, a menu, a dialogue session —
are deliberately silent, or a whole scene would put a refusal in the log every half minute. The
refusals worth seeing (a bard mid-song, a guard mid-arrest, a quest errand, too far, a follower)
all speak.

## 9. Tooling

- `tools/stubs/Package.psc` (new, compile-only, never deployed). The CK's vanilla `Package.psc` is
  not on this machine and SKSE ships only the scripts it extends, so the auto-stub generator produced
  an **empty** `Package` type and `GetOwningQuest()` could not compile. Declared verbatim in
  vanilla's shape — zero arguments, native — which is the same call CHIM itself compiles at
  `AIAgentAIMind.psc:1896`.
- `tools/compile.ps1` — `ActorUtil` added to the PapyrusUtil declaration export (the package layer
  calls `AddPackageOverride` / `RemovePackageOverride`).
- `tools/flows/dlg_adapter.php` — calls `lrgDlgHideEndConversationOnHold()` in `functions.php`'s own
  order, so the filter is really exercised.
- `tools/flows/scenarios/d44_convhold.php` (new) — 10 checks: no key / `hold=0` / stale / another
  NPC / config off all leave `EndConversation` offered; a fresh `hold=1` withholds it and takes
  nothing else away; the hide is idempotent.

## 10. Verification

| gate | result |
|---|---|
| `tools\compile.ps1` | **OK** — 9 `.pex`, 0 errors, 0 warnings, no string over 500 chars |
| `php -l` (functions.php, lrg_dialogue.php, lrg_actions.php, lrg_core.php) | no syntax errors |
| `tools\test_gates.php` | **296 passed, 0 failed** |
| `tools\flows\run_flows.ps1 --strict` | **61 scenarios, 61 passed, 0 FAILED, 0 pending, 934 checks, 0 warnings** |
| `MCM\Config\LoreRimGlue\config.json` | parses; pages `General, Intimacy, Initiative, Conversation, Scene talk, Survival, Keys, Menuless questing` |

Not run: `install_mo2.ps1`, `deploy_server.ps1` — the task says do not install or deploy.

## 11. Risks and what playtesting should look for

1. **A frozen NPC** — the defect this must not have. Six independent releases cover it (single
   idempotent exit, `convHeld` as its own saved field, unconditional release in `Maintenance`, the
   self-heal at 3 × window + 30 s, the stop hotkey, `Window() == 0`). Same mechanism that logged
   three clean outro releases in playtest 8.
2. **Her fidgeting against a busy AI Overhaul / Jobs Overhaul package** — `SetDontMove` stops the
   legs, not the package. If it shows, turn `bConvHoldPackage` on; it is built and tested-by-compile
   but has had no in-game exercise.
3. **CHIM movement commands** — see §5. Dormant mitigation; watch for her stalling after a
   `ComeCloser` / `FollowPlayer`.
4. **ForceGreets** — a ForceGreet that is a bare package with no owning quest is delayed by up to one
   tick (1 s). One with a scene or an owning quest releases the hold at once.
5. **The package form id** — `AIAgent.esp 0x027374` is looked up once and guarded: if it is not a
   `Package`, the cast gives `None` and the layer is a no-op. Only reachable with the opt-in toggle.
6. **`fConvHoldFar` default 900**, tighter than the brief's 1200 and than the outro's 1500, per the
   task. Mid-conversation, 900 units away *is* the end of the conversation.

## 12. Still recommended to the owner, unchanged from pt8 §0

The one-click wins need no build and are still the biggest single effect: disable the
**`EndConversation`** row in CHIM's Action Editor, set `END_CONVERSATION_COOLDOWN` to 0, and set
`RECHAT_MODE` to `conversational` or `tight`. The server filter built here does the first of those
only for the NPC being held, only while she is held.
