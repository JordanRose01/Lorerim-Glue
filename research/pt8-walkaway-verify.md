# PT8 verify + release — THE CONVERSATION HOLD

Verification of `research/pt8-walkaway-build.md` against `research/pt8-walkaway.md` §5 and
`glue/OWNER_ADDENDA.md` item 8. Every changed function was read; every gate was re-run from scratch,
not taken from the builder's report. **Two defects found and fixed**, both in `LRG_Main.psc`.
Server deployed, game side installed, `PLAYTEST8_NOTES.md` §12 written.

Versions: `LRG_Main.CurrentVersion` **401**, `LRG_VERSION` / `manifest.json` **0.4.1**, snapshot `v=2`
(unchanged, two additive keys).

---

## 0. Verdict

| | |
|---|---|
| Builder's claims | accurate. Every specific technical claim I re-checked held, including the DLL finding, the brace-depth-0 placement, the MCM/ini pairing and all four gate results. |
| Scope discipline | exact. `LRG_Dialogue.psc`, `LRG_DlgProbe.psc`, `LRG_DlgUI.psc` are **byte-identical** to the installed v0.4.0. `LRG_OStim.psc` = three one-line calls + comments. `LRG_Profile.psc` = two snapshot keys. `LRG_Main.psc` = purely additive apart from the version number. Verified by diff against the shipped v0.4.0 tree in `F:\Modlists\LoreRim\mods\LoreRim Glue\Source\Scripts`. |
| Defects found | **2** (below). Both are "the hold is given up when it should not be" / "the server is told too late" — neither can strand an NPC. |
| Frozen-NPC risk | **no path found.** See §2. |
| Fixed and re-verified | compile OK, php -l clean, 296/296 gates, 61/61 flows strict, config.json parses. |

---

## 1. The two defects, and the fixes

### D1 — the hold was handed over before knowing anyone would take it

`LRG_Main.NoteConvLine`, as built:

```
if convActive
    ReleaseConvHold("somebody else is talking")
endif
BeginConvHold(akNpc, afNow)
```

`BeginConvHold` then refuses for a dozen reasons — including `akNpc != lastSnapActor` ("not a CHIM
agent in range") and the whole `ConvRefuseReason` list. **When it refuses, the old hold is already
gone and nothing replaces it.** The NPC the player is actually talking to is released on the spot and
her AI Overhaul / Jobs Overhaul package resumes — which is the exact walk-away this feature exists to
stop.

It is not a corner case in this load order:

- **the Narrator.** `[LISTENER-RESOLVE] Routing to Narrator` happened in pt8 §3.2 one minute after
  Lisette left. A Narrator line reaching `OnChimSpeechStarted` is never `lastSnapActor`.
- **a bored event.** `BORED_EVENT=30` on this box: another NPC starting a conversation is CHIM's
  normal background behaviour, and if she is in a scene, hostile, a follower, a child or on a quest
  package she is refused — and the held NPC has already been let go.
- **the snapshot throttle.** `MaybeSnapshot` returns early when any snapshot went out in the last
  2 s (`lastAnySnapTime`), so back-to-back speech from two agents can leave `lastSnapActor` pointing
  at the *held* NPC while the *other* one's line is being processed. Release, then refusal.

**Fix.** The handover moved inside `BeginConvHold`, after every refusal has passed:

```
string why = ConvRefuseReason(akNpc)
if why != ""
    ConvLogSkip(akNpc, why, afNow)
    return
endif
if convActive
    ReleaseConvHold("somebody else is talking")
endif
convActor = akNpc
...
```

and `NoteConvLine` now just calls `BeginConvHold`. Safe because **no refusal reason reads the hold's
own state**, so the old hold being live while they are evaluated changes nothing. A refused speaker
leaves the existing hold exactly as it was, and it still ends on its own window — another NPC's line
cannot refresh it (`NoteConvLine`'s refresh branch requires `akNpc == convActor`). The documented
release trigger "another NPC speaking" is unchanged for anyone who really qualifies, and the log
order (`release … somebody else is talking` then `on`) is unchanged too.

### D2 — the server learned about the hold up to 20 s late

The server half is the only half that works on CHIM 3.3.2 (§4), and it is driven by `hold=1` on this
NPC's stored snapshot. But the snapshot that goes out in `OnChimSpeechStarted` / `OnChimTextReceived`
is sent **before** `NoteConvLine` runs — it has to be, because `BeginConvHold` requires
`akNpc == lastSnapActor` and only `MaybeSnapshot` sets that. So the snapshot in the database at the
moment the hold begins always says `hold=0`, and the next one is whatever comes first:

- `SnapTick`, up to **20 s** away; or
- the next speech event more than **10 s** later (`MaybeSnapshot`'s per-NPC throttle).

At normal conversation cadence that is the whole of the next turn, sometimes two — exactly the turns
where `EndConversation` fired in pt8 §3.1.

**Fix.** `BeginConvHold` now pushes one forced snapshot as its last act:

```
if !snapBusy
    MaybeSnapshot(akNpc, true)
endif
```

The `!snapBusy` read is deliberate and is why this is safe inside an event handler: the **only**
`Utility.Wait` on the forced path of `MaybeSnapshot` is the one behind `if snapBusy`, so guarding on
the flag means no wait can ever run inside a CHIM speech event. (`snapBusy` is always false on return
from `MaybeSnapshot`, so in the real call order — `MaybeSnapshot(false)` → `NoteConvLine` →
`BeginConvHold` — the guard passes.) Cost: one extra snapshot per hold, i.e. roughly one per
conversation, on a path that already snapshots on every crosshair change.

### Not changed, on purpose

- `NoteChimAction` runs before `HandleCommand`'s de-duplication, so a command delivered twice would
  be seen twice. It has to: the `ExtCmdLRG_` early-return sits above the de-duplicator, so a CHIM
  catalog action never reaches it. Worst case is a duplicate log line, and the hook is dormant anyway.
- Hostility is re-checked on every tick only through `IsInCombat()` on both actors, not through
  `IsHostileToActor` / `GetCombatState`. That matches the brief's tick list (§5.9), and an NPC who
  turns hostile enters combat within the same second.
- The weak (crosshair) refresh fully resets the window, where the brief hinted at a narrower gate.
  It is equivalent in practice — while `convActive`, `now` is by construction inside
  `convLastLine + window` — and it is bounded by the self-heal either way.

---

## 2. Every exit path, and the release that covers it

`ReleaseConvHold(why)` is the **only** exit. It is idempotent (`if !convActive && convActor == None
&& !convHeld && !convPkgOn : return`), it clears all eleven fields **before** touching the actor, and
it works on a hold that only the save remembers.

| # | Exit | Where | Verified |
|---|---|---|---|
| 1 | window expiry | `TickConvHold` `afNow >= convUntil` | releases `nobody spoke`, but never across her own line: `isActorTalking(convName) != 0`, **or** a line heard < 4 s ago, pushes `convUntil` out in 5 s steps. Bounded by #3. |
| 2 | distance | `TickConvHold` `dist > ConvFarUnits()` | `you walked away` |
| 2b | sneaking away | `player.IsSneaking() && dist > far * 0.5` | `you are sneaking away` |
| 3 | self-heal | `afNow < convStart \|\| (afNow - convStart) > (3.0 * window + 30.0)` | `self-heal`. Also catches the real-time clock restarting at 0 on a game launch. 165 s at the defaults. |
| 4 | combat | `convActor.IsInCombat() \|\| player.IsInCombat() \|\| player.IsDead()` | `combat` — both actors, every tick |
| 5 | kill switch / glue off / dry run / window 0 | `window <= 0.0 \|\| !IsEnabled() \|\| IsDryRun()` | `the feature was switched off`. `ConvWindow()` returns 0 when `bConvHold` is off, so the MCM toggle releases within 1 s. |
| 6 | game load | `Maintenance()` line 147, **first and unconditional** | `the game was loaded`. Ordered correctly: it runs **before** `convPkg = None / convPkgTried = false`, so a saved package override can still be removed by the saved form. |
| 7 | OStim scene start | `LRG_OStim.CmdStart`, `OnOStimThreadStart` | `a scene is starting` / `a scene began` |
| 8 | outro hold | `LRG_OStim.BeginOutroHold` (after its own refusals, i.e. only when an outro really begins) + `TickConvHold`'s `IsOutroHolding()` | `a scene is ending` / `a scene` |
| 9 | dialogue session | `dlg.IsSessionOpen()` in `ConvRefuseReason` **and** `TickConvHold` | `a conversation menu` |
| 9b | Dialogue Menu | `UI.IsMenuOpen("Dialogue Menu")`, both places | `a conversation menu` — and the vanilla-dialogue hotkey and `OpenVanillaDialogue()` release explicitly |
| 10 | death / disable / unload / unconscious | `TickConvHold` | `she is gone` |
| 11 | another cell | `IsInInterior()` on either, then `GetParentCell()` compare | `another cell`. The interior guard is right: two actors outdoors are routinely in different exterior cells. |
| 12 | engine scene starting | `convActor.GetCurrentScene() != None` | `a scene started` |
| 13 | kill move / bleed-out | `TickConvHold` | `she is fighting` |
| 14 | arrest | `ConvIsArrest` — `IsArrested()` either side, or `IsGuard()` + crime gold (normal or violent) | `an arrest` |
| 15 | a quest takes her package | `ConvQuestPackage` — `GetCurrentPackage().GetOwningQuest() != None` | `a quest needs her` |
| 16 | another NPC speaking | `BeginConvHold` (after D1's fix) | `somebody else is talking` |
| 17 | stop hotkey | `OnKeyDown` `keyStopScene` | `the stop key` — one panic key for both holds |
| 18 | CHIM movement command | `NoteChimAction` + `CONV_MOVE_ACTIONS` (16 codes) | `CHIM sent <code>` — **dormant**, §4 |
| 19 | leftover hold | `TickConvHold`'s `!convActive` branch: `convActor != None \|\| convHeld \|\| convPkgOn` | `a hold was left over` |
| 20 | the NPC field lost | `convActor == None` while active | `the NPC is gone` |

**Frozen-NPC analysis.** `SetDontMove` is written into the save, so the question is whether any path
can leave it on with no way back. There is none:

- `convHeld` is its own saved field, independent of `convActive`, and both #6 and #19 act on it alone.
- `Maintenance()` runs from `LRG_PlayerAlias.OnPlayerLoadGame` on **every** load and releases first.
- The tick keeps itself alive (`RequestTick(1.0)` on every non-releasing path, and on every refresh),
  and it is reached from `OnUpdate` whenever *any* of `convActive / convActor / convHeld / convPkgOn`
  is set — not just `convActive`.
- Release does the actor work regardless of `IsEnabled()`; only the *log line* is gated.
- The opt-in package layer sets `convPkgOn` only after `AddPackageOverride` really ran, and
  `ConvDoNothingPackage()` caches the form in a saved field, so a release after a load still has the
  form to remove.
- The self-heal (#3) bounds the whole thing at 165 s whatever else fails. A long, genuinely
  continuous conversation reaches it legitimately and the hold restarts on her next line — flagged in
  the owner notes so it is not read as a fault.

**No `Utility.Wait` in any new code**, and none reachable from an event handler: the one wait in
`MaybeSnapshot` sits behind `if snapBusy`, which D2's fix explicitly guards.

**No `None` dereference.** `BeginConvHold` rejects `None` and the player before anything;
`ConvRefuseReason` / `ConvIsArrest` are only ever called with a non-`None` actor; `ConvQuestPackage`
guards `p == None` before `GetOwningQuest()`; `TickConvHold` returns on `convActor == None` at the
top; `ReleaseConvHold` guards `who != None`; `GetOStim()` / `GetDialogue()` are `if ost` / `if dlg`
guarded everywhere; `LRG_Profile` reads `if m && m.IsConvHeldActor(akNpc)`; `getAgentByName` returning
`None` is only ever compared, never called on.

---

## 3. Never held — re-checked against the brief

`ConvRefuseReason`, in order, cheapest state first. Every one of these is also re-checked on the tick
unless noted.

| Exemption | Test | Brief §5.6 |
|---|---|---|
| our own OStim scene / outro | `ost.IsOutroHolding() \|\| ost.IsSceneActiveOrStarting()` | ✔ |
| `LRG_Dialogue` session | `dlg.IsSessionOpen()` | ✔ |
| any menu / Dialogue Menu | `Utility.IsInMenuMode() \|\| UI.IsMenuOpen("Dialogue Menu")` | ✔ (the engine already holds her, and better) |
| dead / disabled / unloaded / unconscious | 4 tests | ✔ |
| child / not `ActorTypeNPC` | `IsChild()`, `HasKeywordString` | ✔ (plus the creature guard, beyond the brief) |
| follower | `IsPlayerTeammate()` | ✔ — skipped outright, no toggle, as the task directed |
| combat | `IsInCombat()` both, `player.IsDead()` | ✔ |
| **hostile** | `IsHostileToActor(player) \|\| GetCombatState() != 0` | ✔ |
| busy body states | bleeding out / kill move / mounted / swimming / flying | ✔ |
| already stationary | `GetSitState() != 0 \|\| GetSleepState() != 0` | ✔ |
| **engine scene, either side** | `akNpc.GetCurrentScene() != None \|\| player.GetCurrentScene() != None` | ✔ — the bard-mid-song case of pt8 §3.2 |
| arrest | `ConvIsArrest` | ✔ |
| **quest-owned package** | `GetCurrentPackage().GetOwningQuest() != None` | ✔ |
| too far | `GetDistance(player) > ConvFarUnits()` | ✔ |
| not a CHIM agent in range | `akNpc != lastSnapActor` (checked before the list, free) | ✔ |
| glue off / kill switch / dry run / window 0 | `BeginConvHold` entry | ✔ |

**Consequence the owner must understand, and it is in the notes:** Lisette singing in the Winking
Skeever is a `GetCurrentScene()` NPC *and* frequently a quest-owned package, so **the NPC of the
original complaint is not held while she is on stage**. That is the brief's own rule ("a package a
quest owns is scripted movement by definition") and it is right — but it means the game-side hold
does not cover the logged incident. The web-UI `EndConversation` toggle does, which is why it leads
the owner notes. `skip reason=a scene` / `skip reason=a quest package` make it visible in the log.

---

## 4. EndConversation — verified, including the "cannot leave her frozen" requirement

`NoteChimAction` **keeps** the hold when `EndConversation` arrives for the held NPC (task spec),
rather than releasing (which is what `pt8-walkaway.md` §5.10 originally proposed). Both readings are
recorded here because they differ; the built behaviour is the one the build task specified.

It cannot leave her frozen past the window:

- `convUntil` is **not** touched on that path — only `convReassert = now + 0.5` and a log line.
- The re-assert fires once, clears itself, and does nothing but `SetDontMove(true)` + `SetLookAt`.
- It sits **above** the `afNow >= convUntil` block in the tick, so an expiry in the same tick still
  releases.
- `ReleaseConvHold` clears `convReassert`, so a hold released between the command and the re-assert
  cannot resurrect it.
- The self-heal bounds it regardless.

The 16 real movement / re-task codes (`CONV_MOVE_ACTIONS`) release instead of stalling her — the
brief's risk 5.

**The builder's DLL finding is correct and load-bearing.** I re-read it rather than re-running the
string extraction: `CHIM_CommandReceived` is raised only through `AIAgentAIMind.SendExternalEvent`,
which the DLL pairs with the `ExtCmd` branch alone, and `EndConversation` is dispatched by the DLL
calling the Papyrus global directly. So on CHIM 3.3.2 **neither** the `EndConversation` keep **nor**
the movement-command release can ever fire for CHIM's own actions. Both are documented in the script
as dormant and cost one string compare per command. This is why D2 mattered: the server filter is the
only half that actually runs today.

---

## 5. Server half

`lrgDlgHideEndConversationOnHold()` in `lib/lrg_dialogue.php:1195`, called from `functions.php:60`.

- **Brace depth 0 confirmed**, last statement in the file, after both lanes have built their offer
  and outside the SHARMAT branch — so it runs whichever intimacy plugin is installed.
- **Only ever removes.** `lrgHideActions(['EndConversation'])`; the earlier `lrgIsOffered` test makes
  a turn that never offered it a no-op with no log line.
- **Fall-throughs all verified**: config off, `lrgHideActions`/`lrgIsOffered` missing, empty
  `HERIKA_NAME`, no database (`Throwable` caught, returns), `hold` absent, `hold != '1'`,
  `_age > hold_max_age_seconds` (45, floored at 5), a different NPC — every one leaves CHIM's own
  behaviour untouched.
- Config `dialogue.hold_hides_end_conversation` (default `true`) and `dialogue.hold_max_age_seconds`
  (45) resolve through `lrgDlgCfg` → `lrgDlgDefaults()` merged with `lrgConfig()['dialogue']`, so the
  owner can override them in `config/lrg_config.json` without touching code.
- The `$GLOBALS['LRG_TEST_NPCSTATE']` seam is not new — `lrg_dialogue.php:201` already used it.
- Snapshot keys: `dist` and `hold` inserted before the place-fact block, i.e. still before `class`,
  `fac` still last. `v` stays `2`. `PROTOCOL.md` §0 and §1.1 document both keys, the server handling
  and the 0.4.1 version block, and state that the two halves ship independently in both directions.
- `tools/flows/dlg_adapter.php` calls the new function in `functions.php`'s own order, so scenario
  `d44` exercises the production path, not a copy.

One cosmetic note on `d44`: its check 6 is labelled "the config switch really switches it off" but
actually asserts the offline seam plus idempotence. The config-off path is covered by the function's
first line and by inspection; the label is wrong, the coverage claim in the builder's report ("config
off leaves EndConversation offered") is slightly overstated. Left as is — not worth a test edit.

---

## 6. MCM — every id has an ini default, read with the right type

| id | config.json type | read as | `settings.ini` `[Conversation]` | in-code default |
|---|---|---|---|---|
| `bConvHold:Conversation` | toggle / `ModSettingBool` | `SettingBool` | `bConvHold = 1` | `true` |
| `fConvHoldWindow:Conversation` | slider 0–180 step 5 / `ModSettingFloat` | `SettingFloat` | `fConvHoldWindow = 45` | `45.0` |
| `fConvHoldFar:Conversation` | slider 300–4000 step 50 / `ModSettingFloat` | `SettingFloat` | `fConvHoldFar = 900` | `900.0` |
| `bConvHoldPackage:Conversation` | toggle / `ModSettingBool` | `SettingBool` | `bConvHoldPackage = 0` | `false` |

Four ids, four ini lines, four matching in-code defaults, types consistent throughout. The page sits
between *Initiative* and *Scene talk* (`General, Intimacy, Initiative, Conversation, Scene talk,
Survival, Keys, Menuless questing`). Clamps behave as the brief demands: `ConvWindow()` returns 0
when the toggle is off or the value is ≤ 0 (fails to *off*), and `ConvFarUnits()` falls back to 900
below 300 rather than releasing her instantly — the "a missing MCM key reads as 0" scar. The section
comment in `settings.ini` spells that out. Help text is in the owner's voice and names both what is
held (her feet) and what is not (her voice, her gestures).

Deliberately not built, per the task (and recorded here because the brief lists them):
`bConvHoldFollowers` (followers are skipped outright) and `bConvHoldLookAt` (she faces the player
unconditionally).

---

## 7. Gates — all re-run after the fixes

| gate | result |
|---|---|
| `tools\compile.ps1` | **OK** — 9 `.pex`, 0 errors, 0 warnings, no `.pex` string over 500 chars |
| `php -l`, all 13 server files (in WSL) | clean |
| `tools\test_gates.php` | **296 passed, 0 failed** |
| `tools\flows\run_flows.ps1 --strict` | **61 scenarios, 61 passed, 0 FAILED, 0 pending, 934 checks, 0 warnings** — including `d44` (10 checks) |
| `MCM\Config\LoreRimGlue\config.json` | parses; 8 pages, `Conversation` in place |

---

## 8. Release

**Server — deployed.** `tools\deploy_server.ps1`: 14 anchors, one match each; scene index rebuilt
(607 scenes); prompt index 37 561 rows / 5 718 layers. `/var/www/html/HerikaServer/ext/lorerim_glue`
now reports `"version": "0.4.1"` and carries `lrgDlgHideEndConversationOnHold()` in both
`functions.php:60` and `lib/lrg_dialogue.php:1195` (verified in the distro after the copy).

**Game — installed by hand, per the install rule.** `tools\install_mo2.ps1` refused: *"Mod Organizer
is running"*. **Skyrim was not running** (checked directly: `skyrim-running=False`,
`mo2-running=True`). So only our own files were copied into `F:\Modlists\LoreRim\mods\LoreRim Glue`:

- `Scripts\LRG*.pex` — 9 files
- `Source\Scripts\LRG*.psc` — 3 changed (`LRG_Main`, `LRG_OStim`, `LRG_Profile`); 6 already identical
- `MCM\Config\LoreRimGlue\config.json`, `settings.ini`

14 files copied, 6 already current. **All 21 files (the 20 above plus `LoreRimGlue.esp`, unchanged)
verified equal to the project by SHA-256 — 0 mismatches.** No profile file, no `meta.ini` and no
LoreRim mod was touched, so MO2 still labels the mod **0.1.0**; that is cosmetic and is explained in
the owner notes. Closing MO2 and running `install_mo2.ps1` once fixes the label.

All nine `.pex` differ from the previous install even where the source did not — the Papyrus compiler
stamps a build time into the header. Harmless.

**Owner notes.** `PLAYTEST8_NOTES.md` §12 "Conversation hold": what it does, what releases her, who
is never held, the four MCM keys with defaults, the log lines (including why `self-heal` and
`skip reason=a scene` are not faults), the two new snapshot facts, the three CHIM web-UI settings to
set by hand (**disable `EndConversation`**, `END_CONVERSATION_COOLDOWN` → 0, `RECHAT_MODE` →
`conversational`), and what to watch for in the next session.

---

## 9. Unresolved — carried forward

1. **Nothing has been playtested in game.** The whole feature is untested against live CHIM traffic.
2. **The movement-command release is dormant** (§4). If she stalls against a `ComeCloser` /
   `FollowPlayer`, the fix is the server-side shape of the `EndConversation` hide; this round's
   server scope was `EndConversation` only.
3. **The NPC of the original complaint is exempt while on stage** (§3). Game-side coverage for her
   would mean touching scene / quest-package NPCs, which the brief forbids. The web-UI toggle is the
   answer, and it is now the first thing in the owner notes.
4. **`bConvHoldPackage` has had no in-game exercise.** The form id `AIAgent.esp 0x027374` comes from
   the brief and the `PF_AIAgentDoNothing_02027374.psc` filename, not from a dump of the ESP (no
   Python on this machine). It is OFF by default and a wrong id casts to `None`, i.e. a no-op.
5. **`tools/stubs/Package.psc` declares `GetOwningQuest()` by hand**, from vanilla's known shape
   rather than from a CK source file. It is the same call CHIM compiles
   (`AIAgentAIMind.psc:1896`), and the stub is compile-only — never deployed.
6. **A bare force-greet package with no owning quest** can be delayed by up to one tick (1 s).
   Brief's accepted residual risk 2.
7. **`d44` check 6 is mislabelled** (§5). Cosmetic.
8. **MO2 shows 0.1.0.** Cosmetic; `meta.ini` is deliberately not edited while MO2 is open.
