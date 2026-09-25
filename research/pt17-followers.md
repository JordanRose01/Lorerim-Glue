# pt17 - Following handed to Simple Follower Framework (investigator notes, 2026-09-23, read-only)

Owner, verbatim: "my followers movement was still a little clunky, if chim is managing it or if you are
managing it lets change it to whatever follower mod is managing it and work with it that way because the
follower just looks straight clunky in game."

Nothing was edited. Tags: **[V]** read from a file / log, **[I]** inferred from evidence, **[U]** untested in game.
Timestamps: `lorerim_glue.log` -04:00, `AIAgent.log` local (game clock, same as -04:00 tonight).

---

## 0. Verdict in five lines

1. Tonight Lisette was never a follower of any framework. She was under **CHIM's own package follow**
   (`CHIM_FollowPlayerActive=1`, AIAgent.esp PACK 0x2226D at PapyrusUtil priority 100) with the glue's
   128/256 overlay on top (`lorerim_glue.log:2893` 19:55:11 "natural follow Lisette: shadowing CHIM's follow"),
   then CHIM's **ComeCloser sprint** (20:08:39, `AIAgent.log:4093-4096`, glue :3031-3033 stood the overlay down),
   then the overlay again (:3049 20:09:15). Every "stop following me" was answered by the game with
   `nothing changed (dry run)` (:2987, :3045, :3078) because `[General] bDryRun = 1` - so the release never ran
   and she kept following. Not one snapshot in the whole log carries `mate=1` (grep count 0): no playtest has
   ever had a real follower present. **[V]**
2. **Simple Follower Framework can be driven from a script**, and the glue already compiles against it:
   `DialogueFollowerScript.SetFollower(ObjectReference)` is SFF's full recruit (cap check, alias, teammate flag,
   `SFF_SKSE.AddVanillaFollower`, relationship 3) and is what the real "Follow me, I need your help" fragment
   calls (`Missing Follower Dialogue Edit\Scripts\Source\MFMD_FollowerRecruit.psc:9`); `RepairGhost`
   (`LRG_Followers.psc:270`) calls it today. Wait = `WaitingForPlayer` 1/0 (what SFF's `FollowerWait` /
   `FollowerFollow` and Quick Follower Commands write), dismiss = `DismissFollower(0, 0)` after
   `SFF_SetLastSpeaker(npc)` (the only way to aim it at a specific actor from outside a dialogue menu). **[V]**
3. **What SFF does when the slot cap forbids it: nothing, silently.** `SetFollower` returns without a message
   when `SFF_GetTrackedFollowerCount() >= SFF_GetMaxFollowersSafe()` (pt9 decompile, `pt9-followers.md` 4.1);
   the spoken refusal in the real dialogue is a *different INFO* (`MFMD_FollowerRecruitReject`) gated on the
   `SFF_CanRecruitMore` global. Cap tonight: `iSpeechLevelsPerSlot=25`, `bFollowerOptionSelector=2`
   (`SimpleFollowerFramework.ini`), the DLL's own text "Speech levels needed for each extra follower slot ...
   Speech 0-9 = 1 follower" -> at Speech 15 the owner has **exactly one companion slot**. So the glue must
   test `SFF_CanRecruitMore` BEFORE calling and voice the reason itself. **[V]**
4. **The follow a real SFF follower gets in THIS load order** is USSEP's `PlayerFollowerPackage` 0x0005C84B
   (Min 384 / Max 512 units, the familiar vanilla follower distance) - and, inside any Dwelling / Habitation
   location with `bFollowerSandbox=1` (the shipped value), SFF's **`IvyFollowerIdleSandbox` package outranks
   it**: she sandboxes (wanders, sits) within 1024 units of the player instead of heeling. That is the
   framework's own behaviour and an ini switch, not a glue bug - owner step 2. **[V, decoded from the plugins]**
5. **CHIM's "NPCs Walk To Target" is OFF in this save [I, strong]** and the 10-second `[WALKTOTARGET_TIMER]`
   line proves nothing either way: it is the DLL's unconditional housekeeping timer (`Plugin.cpp:2800`, 46 firings
   tonight) that calls a Papyrus function which only ever RELEASES a walker after 40 s
   (`AIAgentAIMind.psc:1650-1694`) - it cannot move anyone. The one thing that CAN start a walk-to is
   `FakeDialogueWith` (:1752-1765), which fired once tonight (`AIAgent.log:5652` 20:10:03 Lisette -> Braste) with
   the two more than 400 units apart (Braste 914 from the player :5264, Lisette 127 :3034 - triangle inequality
   gives >= 787), while Lisette was tracked by the glue's 4-second tick; a `FollowSoft` would have set CHIM's
   MoveTarget ref and the tick logs "standing down" within 4 s. The only such line all night is the 20:08:40
   ComeCloser. The option is `StorageUtil.GetIntValue(None, "AIAgentNpcWalkToTarget", 0)` - save-side, and the
   glue CAN read it (section 6.7 makes it a log field so the owner step becomes checkable).

---

## 1. What tonight's logs say (verified lines)

| time | file:line | what |
|---|---|---|
| 19:55:11 | glue:2893 | `natural follow Lisette: shadowing CHIM's follow (128/256, no LOS)` - CHIM's follow was on her from the save; the overlay went on |
| 20:06:25 | glue:2977 | `say="stop following me" intent=escort/release conf=high` |
| 20:06:28 | glue:2984-2990 | escort release sent -> GAME `escort Lisette do=Release: nothing changed (dry run)` -> `voiced: no (a dry run changes nothing)` |
| 20:08:18 / :34 | glue:3017, :3025 | "why are you still following me?" / "following me" -> `intent=none` (questions and fragments stay nothing, PROTOCOL 10.20) |
| 20:08:39 | glue:3031, AIAgent:4075-4096 | the model chose **ComeCloser** (`[ComeCloser] Dispatched temporary approach`) = CHIM's `FollowSoft` = the Always-Run soft package at 55 + MoveTarget ref |
| 20:08:40 | glue:3033 | `natural follow Lisette: standing down while CHIM moves her itself (ComeCloser / walk-to)` |
| 20:08:50-55 | glue:3035-3048 | "okay stop following me now" -> release -> dry run again |
| 20:09:15 | glue:3049 | `natural follow Lisette: on - CHIM's own move has ended` (EndFollowSoft after Travel + 30 s Wait) |
| 20:09:44-48 | glue:3066-3081 | third release -> dry run again |
| 20:10:03 | AIAgent:5652 | `FakeDialogueWith 'Lisette' 'Braste' '0' '1'` - no stand-down follows -> no walk-to (section 0.5) |

The `fol=live` on the escort lines (:2984 etc.) only says the follower facts came from a fresh snapshot
(`lrg_actions.php:2767-2771`); the facts themselves live in Postgres (`lrg_npcstate`), which was not
reachable from WSL tonight (`connection refused` on 5432 - the server stack is down while the game is closed).

Root cause of "stop following me didn't work": the dry run (the switches lane). Root cause of "clunky": CHIM's
follow + ComeCloser sprints, exactly as pt16 found, and now the owner wants the framework to own her instead.

---

## 2. Simple Follower Framework 1.4.1.1 - the facts a script needs (from SFF's own files)

Files: `F:\Modlists\LoreRim\mods\Simple Follower Framework\` - `Simple Follower Framework.esp`,
`SKSE\Plugins\SimpleFollowerFramework.{dll,ini}`, `Scripts\{dialoguefollowerscript,SFF_FollowerAliasScript,
SFF_SKSE,QF_DialogueFollower_000750BA}.pex`, `Source\Scripts\*.psc` (the `DialogueFollowerScript.psc` there is the
STALE vanilla script - no `SFF_*` function; `SFF_FollowerAliasScript.psc:19-20` calls `SyncSFFFollowerState()` /
`SFF_SetLastSpeaker()` which only exist in the .pex). **[V]**

### 2.1 The quest record that wins, and its packages

QUST `DialogueFollower` 0x000750BA is overridden by Skyrim.esm, Update.esm, USSEP (plugins.txt:9), Requiem,
SFF (:927), `Big Tweaks.esp` (:2628) and **`Simple Follower Framework Patch.esp` (:3336 - the winner)**, which
carries SFF's aliases byte for byte (scan of every plugin under `mods\` and `Stock Game\Data`, WSL). Alias
`Follower` and `ExtraFollower01..07` each carry three packages, in this priority order **[V]**:

| ALPC | package | what it does |
|---|---|---|
| 0x00101006 | `PlayerFollowerSayDismissPackage` (Skyrim.esm) | says the dismissal line while `iFollowerDismiss == 1` |
| SFF 0x803 / 0x004..0x009 | `IvyFollowerIdleSandbox0N` (SFF) | **Sandbox within 1024 units of the player** (PLDT near ref 0x14, radius 0x400) when `GetGlobalValue SFF_FollowerSandbox == 1` AND (`LocationHasKeyword LocTypeHabitation` 0x39793 OR `LocTypeDwelling` 0x130DC) AND `GetDistance player <= 1024` AND six "not busy" guards |
| 0x0005C84B | `PlayerFollowerPackage` - **USSEP's override wins** (only Skyrim.esm and USSEP carry it) | Follow procedure from `FollowerPackageTemplate` 0xD530D: **Min Radius 384.0 / Max Radius 512.0** (CNAM `0000c043` / `00000044`), Accompany 0, target 0x14 |

So the framework's follow is 384/512 (the vanilla feel: 5-7 m behind, walks when you walk) - farther than the
glue's 128/256 overlay, closer than CHIM's 512/1024 and without CHIM's LOS rule. And indoors the sandbox wins:
in the Winking Skeever a real follower with the shipped `bFollowerSandbox=1` **wanders and sits** near the
player. `SFF_FollowerSandbox` is GLOB 0x05000805 and the DLL sets it from the ini / its SKSE-menu toggle
("Follower Sandbox - Active - Followers Will Sandbox When Idle", DLL strings). **[V]**

### 2.2 The API (compiled `dialoguefollowerscript.pex`, 36 functions; pt9 7.1 transcribed them - names re-confirmed tonight from the pex string table)

```
SetFollower(ObjectReference)              full recruit; CAP ENFORCED (silent return when full)
IsManagedFollower(Actor)                  primary OR extra alias
SFF_IsPrimaryFollower / SFF_IsExtraFollower(Actor)
SFF_SetLastSpeaker(Actor)                 <- aims GetDialogueFollowerTarget() at her
GetDialogueFollowerTarget()               GetDialogueTarget -> SFFLastSpeaker -> pFollowerAlias   (the wrong-target trap, pt9 4.2)
FollowerWait() / FollowerFollow()         WaitingForPlayer 1 / 0 on GetDialogueFollowerTarget(), + 72 h timer if primary
DismissFollower(Int iMessage, Int iSayLine)   on GetDialogueFollowerTarget(): DismissedFollower faction, teammate off,
                                          alias cleared / extra removed, promote next, globals resynced
SFF_GetTrackedFollowerCount / SFF_GetMaxFollowersSafe   (= min(SFF_SKSE.GetMaxFollowers(), aliases+1))
```
Natives (`SFF_SKSE.psc:3-8`): `AddVanillaFollower`, `IsVanillaFollower` (the truthful "is she SFF's"),
`GetMaxFollowers` (honours the Speech option), `Apply/RestoreFollowerEssential`. Globals the DLL/script keep:
`SFF_CanRecruitMore` 0x05000001, `SFF_CurrentFollowerCount` 0x05000002 (EditorIDs WITH underscore -
`LRG_Followers.psc:54-65`). The glue's compile-only header `tools\stubs\DialogueFollowerScript.psc` declares
`SetFollower, IsManagedFollower, SFF_IsPrimaryFollower, SFF_GetTrackedFollowerCount, SFF_GetMaxFollowersSafe`;
the hand-off needs three more (section 6.2). **Never ship a DialogueFollowerScript.pex** (`compile.ps1:137-151`
already refuses).

### 2.3 Where the slot rule is enforced

* Script: `SetFollower` (count vs `SFF_GetMaxFollowersSafe()`, pt9 4.1) - silent.
* Dialogue: the recruit INFO is gated on `SFF_CanRecruitMore` (SFF esp / SFF Patch), the reject INFO
  (`MFMD_FollowerRecruitReject` -> `MFMD_AlreadyHaveFollower.Show()`) speaks. **[V for the fragments; the CTDA on
  the INFOs was not re-parsed - pt9 U1]**
* DLL: `GetMaxFollowers()` = Speech / `iSpeechLevelsPerSlot` + 1 with option 2 (ini comment + DLL strings) ->
  Speech 15 / 25 = 0 -> **1 slot**. `SFF_CanRecruitMore` = count < max. **[V]**

### 2.4 What a script recruit bypasses (honest list)

`SetFollower` is the recruit *fragment*; the INFO's conditions are not consulted. Bypassed: the vanilla
`GetInFaction PotentialFollowerFaction` gate (the glue must test `PffRank >= 0` itself - `LRG_Followers.psc:88`),
hireling gold (Belrand / Jenassa / Marcurio / Stenvar / Vorstag / Erik are in PotentialFollowerFaction and charge
500 gold in their own line), and Requiem's per-NPC additions (Ghorbash level 35 / Speech 100, Borgakh - pt9 1.4).
Mitigation that already exists: when the menuless follower verb is live, her REAL entry answers and the escort
steps aside (`lrg_actions.php:2929` `lrgEscortDlgVerbs`); tonight `ml=0` everywhere, so that path was dark.

---

## 3. What the glue does today, and where it fights the framework

* **Server, `lrgEscortPlan()` (`lrg_actions.php:2899-2942`)**: `do=follow` only when she is in an ENGINE scene
  (:2936 "she is in no engine scene - CHIM's own follow is enough") - so outside a scene "follow me" is CHIM's
  `FollowPlayer` and nothing else; and for `fw:sff` / `fw:custom` / `mate:1` / `ghost` **nothing is sent at all**
  (:2927 "a follower framework owns her ... her own dialogue does this") while the directive tells the model
  "her own follower dialogue carries that out" (:2860) - with `ml=0` that dialogue never runs: she would SAY yes
  and nothing would happen (addendum 11 by construction).
* **Server, `lrgFollowerPolicy()` (`lrg_core.php:1784-1815`)**: hides only `MakeFollower` for a teammate /
  full cap, and the three "make it worse" ones for a ghost. **`ComeCloser`, `MoveTo`, `FollowPlayer` stay on the
  table for a real follower** (test_gates.php:1528-1531 asserts FollowPlayer stays offered for fw:sff) - and CHIM's
  DLL keeps `CurrentParty` empty (`AIAgent.log:422, :669` `setconf ... CurrentParty@` with nothing), so CHIM's own
  `available_to_followers` column never kicks in either (pt9 2.2).
* **Game, `EscortRefuseReason` (`LRG_Main.psc:1729`)** refuses every teammate / SFF follower ("her own follow and
  wait apply"); **`EscortStopFollowing` (:1918)** returns without touching CHIM's follow on a teammate;
  **`NoteGlueFollow` (:2412-2421)** and **`TickGlueSlot` (:2507-2519)** take only the glue's OWN overlay off a
  teammate and leave CHIM's 0x2226D override (priority 100) and CHIM's soft package ON her - so a follower CHIM
  had been driving, or one CHIM sends `ComeCloser` to, is still walked by CHIM's packages, which out-rank every
  alias package (pt9 4.3). **`RepairGhost`'s promote path (`LRG_Followers.psc:262-276`)** likewise calls
  `SetFollower` without taking CHIM's follow off first. These four are the concrete "fights the framework" defects.
* `IsSff()` / `Framework()` / `FolState()` / the hold skip / the witness scan are right and stay.

---

## 4. Design - "follow me" through SFF, the overlay as the fallback

Principle (owner addendum 10, tonight's ruling): the framework owns follow / wait / dismiss for anyone it can
own; CHIM's follow + the glue's overlay is the fallback for anyone it cannot, and the reason is spoken.

| player says | she is | what happens | who moves her afterwards |
|---|---|---|---|
| follow me | stranger, SFF present, `SFF_CanRecruitMore>=1`, `PffRank>=0` | CHIM's follow / soft move vetoed off her, `SetFollower(npc)`, verified with `SFF_SKSE.IsVanillaFollower` | SFF (USSEP `PlayerFollowerPackage` 384/512; the SFF sandbox indoors) |
| follow me | stranger, but no slot / not recruitable / SFF absent / SetFollower refused | today's path: CHIM's follow + the glue overlay; the OK funcret carries `fb=<reason>` -> voiced in her funcret turn ("I'll walk with you, but I can't join you - you can only lead one companion") | CHIM + overlay (pt16) |
| follow me | already SFF's (waiting) | `WaitingForPlayer 0` + veto CHIM | SFF |
| follow me | a custom follower (Inigo, Katana ...) | refused as today, voiced ("ask her the way you always do") | her own quest |
| wait here | SFF's | `WaitingForPlayer 1` (+ veto CHIM) - the value USSEP's / SFF's packages and Quick Follower Commands read | SFF |
| wait here | stranger | today's `EscortWait` | - |
| stop following / you can go | SFF's | `SFF_SetLastSpeaker(npc)`; `GetDialogueFollowerTarget() == npc` or REFUSE voiced; `DismissFollower(0, 0)` (the real dismiss fragment's arguments, `MFMD_FollowerDismiss.psc:10`) | nobody |
| stop following / you can go | stranger under CHIM / overlay | today's `EscortRelease` | nobody |
| ghost repaired | ghost, slot free | veto CHIM's follow, then `SetFollower` (RepairGhost) | SFF |
| CHIM chooses ComeCloser / MoveTo / FollowPlayer for an SFF or custom follower | - | server: not offered (`hide_when_owned`); game: any CHIM follow-type override found on a teammate is removed at her next snapshot (belt and braces) | SFF |

Why `WaitingForPlayer` directly and not `FollowerWait()`: SFF's function acts on `GetDialogueFollowerTarget()`,
which prefers `Game.GetDialogueTarget()` - outside a menu that is None or stale - and falls back to the PRIMARY
alias: the wrong-follower trap (pt9 4.2, G4). The AV write is exact per actor and is precisely what SFF's own
function does apart from a 72-hour "dismiss if forgotten" timer on the primary. Dismiss has no AV equivalent, so
it goes through `SFF_SetLastSpeaker` + a target check first (she IS managed after a recruit, so the check holds).

Why drop CHIM's `FollowPlayer` line from the batch when the escort carries the promotion: CHIM's command and the
glue's ExtCmd land in CHIM's queue in either order; if `stayAtPlace(npc,1)` ran AFTER `SetFollower`, CHIM's 0x2226D
at 100 would sit on the new follower until her next snapshot (up to 20 s of the old walk). `EscortFollow` puts
CHIM's follow on itself in the fallback (`LRG_Main.psc:1815`), so nothing is lost by dropping it.

---

## 5. The Walk-To-Target question, answered

* Option key: MCM "NPCs Walk To Target" -> `StorageUtil.SetIntValue(None, "AIAgentNpcWalkToTarget", 0|1)`
  (`AIAgentMCMConfigScript.psc:855, :1098, :2499`), default false (:132). Save-side StorageUtil, **readable by
  any script** with `StorageUtil.GetIntValue(None, "AIAgentNpcWalkToTarget", 0)` - not a file. **[V]**
* Its only mover: `FakeDialogueWith` (`AIAgentAIMind.psc:1752-1765`) - when the SPEAKER is > 400 units from the
  listener and not in combat / scene -> `FollowSoft(npc, listener)` (Always-Run soft package at 55). Only for the
  speaking NPC, only toward the listener. **[V]**
* The DLL timer (`[WALKTOTARGET_TIMER] Checking WalkToTarget NPCs after Ns`, `Plugin.cpp:2800`, every 10 s, 46
  times tonight) -> `CheckAndReleaseWalkToTargetNPCs()` (:1650) which only RELEASES walkers after 40 s. It logs the
  same line whether the option is on or off; there is no state line in `AIAgent.log`, `chim.log`, the overwrite
  folder or CHIM's mod folder (grep, all empty). **[V]**
* Evidence it is OFF tonight: section 0.5 (one `FakeDialogueWith` at > 400 units while Lisette was tick-tracked,
  no stand-down). **[I]** Nothing tonight was moved by it; the sprint at 20:08:39 was **ComeCloser**, chosen by the
  model (`glue:3031 chim=ComeCloser`).
* Also default-ON and unrelated to the timer: "NPCs Sandbox Near Player" (`AIAgentNpcWalkNear`, default 1 when the
  key is missing, `GetIntoConversation` :1532) - acts only while the PLAYER IS SEATED and she is > 1024 away. Leave it.

---

## 6. The smallest complete fix (every file named; the implementer's checklist)

### 6.1 `glue\game\LoreRimGlue\Source\Scripts\LRG_Followers.psc` (helpers, all Global, no state)

Add after `ChimSoftMoveOn` (:421):
* `bool Function ChimFollowVeto(Actor a)` - returns whether anything was on: `RemovePackageOverride` for
  0x2226D (FollowPlayer), 0x268B0 (FollowSoft), 0x1BC25 (Follow); `PO3_SKSEFunctions.SetLinkedRef(a, None,
  ChimMoveTargetKw())`; `StorageUtil.SetIntValue(a, "CHIM_FollowPlayerActive", 0)`; `RemoveFromFaction`
  AIAgent.esp 0x1BC24; `GlueFollowOff(a)`; `EvaluatePackage()`. CHIM's own `ResetPackages` (`AIAgentAIMind.psc:21-68`)
  does the same removals by form, so CHIM's later EndConversation is unaffected; a missing override is a no-op.
* `string Function SffRefuseReason(Actor a)` - "" when she may be recruited; else one of: `Simple Follower
  Framework is not installed` (`!SffPresent()`), `you have no free companion slot (<used>/<max> at Speech <n>)`
  (`GvCanRecruit().GetValue() < 1`; used = `GvFollowerCount()`, max = `SFF_SKSE.GetMaxFollowers()`, n =
  `Game.GetPlayer().GetActorValue("Speechcraft") as int`), `she is not someone who would join you` (`PffRank(a) < 0`),
  `she already travels with you as somebody else's companion` (teammate and not `IsSff`).
* `int Function SffRecruit(Actor a)` - 1 recruited, 0 refused (reason above), -1 SetFollower ran and she is still
  not SFF's (`!IsSff(a)`): `ChimFollowVeto(a)` FIRST, then `(FollowerQuest() as DialogueFollowerScript).SetFollower(a
  as ObjectReference)`, verify with `IsSff(a)`, `StorageUtil.SetIntValue(a, "LRG_SffRecruit", 1)`.
* `Function SffWait(Actor a, bool abWait)` - `SetActorValue("WaitingForPlayer", 1.0 / 0.0)` (never -1, pt9 1.3),
  `ChimFollowVeto(a)`, `EvaluatePackage()`.
* `int Function SffDismiss(Actor a)` - 1 dismissed, 0 refused: `dfs.SFF_SetLastSpeaker(a)`; `if
  dfs.GetDialogueFollowerTarget() != a` -> 0 (never the wrong follower); `dfs.DismissFollower(0, 0)`; result
  `!IsSff(a)`; `UnsetIntValue LRG_SffRecruit`.
* `RepairGhost` (:262-276): call `ChimFollowVeto(akNpc)` before `dfs.SetFollower(...)` so the promoted ghost is
  not still walked by CHIM's package (this is "RepairGhost must agree with this").

### 6.2 `glue\tools\stubs\DialogueFollowerScript.psc` (compile-only header)

Add the three signatures (exact names from the pex string table, types as vanilla / pt9 7.1):
`Function SFF_SetLastSpeaker(Actor akActor)`, `Actor Function GetDialogueFollowerTarget()`,
`Function DismissFollower(int iMessage = 0, int iSayLine = 1)`. Update the header comment ("five" -> "eight").
`compile.ps1` needs no change (the denylist already carries the name).

### 6.3 `glue\game\LoreRimGlue\Source\Scripts\LRG_Main.psc`

* `:36` `CurrentVersion` 508 -> **509** (re-read the line first; the switches lane may already have done it).
* `EscortRefuseReason` (:1729-1731): `if akNpc.IsPlayerTeammate() && !LRG_Followers.IsSff(akNpc)` -> the refusal
  as today (custom followers); an SFF follower passes.
* `EscortFollow` (:1778): after the scene block and `EscortUnwait` (:1806), gated on
  `SettingBool("bSffFollow:Followers", true)`:
  1. `IsSff(akNpc)` -> `SffWait(akNpc, false)`; log `escort <name>: your companion follows again (SFF, WaitingForPlayer 0)`;
     `ReportResult OK: <name> comes with you.`; keep the scene follow-up arming when a scene was stopped; return.
  2. `why = SffRefuseReason(akNpc)`; if `""` -> `SffRecruit(akNpc)`; on 1: log `escort <name>: recruited as your
     companion through Simple Follower Framework (slot <used>/<max>)`, `SendNpcMessage(MSG_LOG, "follower recruited
     npc=...;slot=u/m")`, `ReportResult OK` (same sentences as today), the scene tick as today, return. On -1: `why =
     "the framework did not take her"`.
  3. fallback (`why != ""`): today's CHIM-follow + `GlueFollowApply` path unchanged, but the OK goes out with the
     additive key `;fb=<why>` on field 2 (`ReportResult(asNpcName, asCommand, asParam + ";fb=" + CleanForWire(why),
     "OK: ...")` - `SendFuncret` already appends keys the same way for `err=` / `late=`), plus the corner note
     `LoreRim Glue: <name> walks with you, but not as a companion - <why>` under `bNotifyErrors:General`, plus the
     log line. Add `walkto=<StorageUtil.GetIntValue(None,"AIAgentNpcWalkToTarget",0)>` to the escort log line (one read).
* `EscortWait` (:1855): first thing, `if IsSff(akNpc)` -> `SffWait(akNpc, true)`; log `escort <name>: waits here
  (your companion, WaitingForPlayer 1)`; `ReportResult OK: <name> waits here.`; return (no `LRG_EscortWait`
  bookkeeping - the framework owns the value from here).
* `EscortRelease` (:1879): first thing, `if IsSff(akNpc) && SettingBool("bSffFollow:Followers", true)` ->
  `SffDismiss(akNpc)`: 1 -> log `escort <name>: dismissed through Simple Follower Framework`, `ReportResult OK:
  <name> goes back to her own business.`; 0 -> `ReportError(..., say = <name> + " cannot part ways like this right
  now - tell her properly", tech = "the framework would not dismiss her (dialogue target mismatch)")` - voiced.
* `EscortStopFollowing` (:1918): on a teammate, `LRG_Followers.ChimFollowVeto(akNpc)` and return `"CHIM's follow
  taken off your companion"` when it was on (instead of returning "" and leaving CHIM's package on her).
* `NoteGlueFollow` (:2412-2421) and `TickGlueSlot` (:2507-2519), the teammate branch: replace `GlueFollowRelease`
  with `ChimFollowVeto` (it includes the overlay) and log `natural follow <name>: off - she is your follower now;
  CHIM's follow taken off her (her framework owns follow)`. In `NoteGlueFollow` also enter the branch when
  `akNpc.IsPlayerTeammate() && LRG_Followers.ChimSoftMoveOn(akNpc)` (a ComeCloser / walk-to on a companion with
  `chim == 0`) - one `GetLinkedRef` per snapshot of a teammate only.
* `NoteFollower` (:3470-3480) teammate block: add `LRG_Followers.ChimFollowVeto(akNpc)` (covers a follow CHIM put
  on her before she was recruited by the real dialogue).
* Papyrus rules kept: no new arrays, nothing in OnInit, no docstring over 500 chars (`;/ /;`), no try/catch.

### 6.4 MCM

* `glue\game\LoreRimGlue\MCM\Config\LoreRimGlue\config.json`, Followers page, after `bNaturalFollow:Followers`
  (:219): `{ "id": "bSffFollow:Followers", "text": "'Follow me' makes her your real companion", "type": "toggle",
  "help": "Simple Follower Framework recruits her exactly as its own 'Follow me, I need your help' line would, so its
  follow package, 'wait here' and 'part ways' apply and every follower mod sees her. Needs a free companion slot
  (one per 25 Speech) and someone the game lets you recruit; otherwise she walks with you the old way and says why.
  Off = the old way always.", "valueOptions": { "sourceType": "ModSettingBool" } }` - UTF-8, no BOM, LF.
* `settings.ini` `[Followers]` after :291: `bSffFollow = 1` (a missing key reads 0).
* `php tools/test_mcm_wiring.php` after the edit (rule (a): the id string must appear in LRG_Main.psc).

### 6.5 Server - `glue\server\lorerim_glue\lib\lrg_actions.php`

* `lrgEscortFacts()` (:2757): add `'can_take' => 0|1` (fw none|chim, mate 0, `pff >= 0`, `cap` 1 - absent cap
  counts as unknown = 0) and `'sff' => isset($fol['slot'])`.
* `lrgEscortPlan()` (:2899):
  - :2927 owner rail: skip only for `fw:custom`, `teammate` (mate:1 without fw:sff) and `faction:*`; `fw:sff` and
    `ghost` go through (the game routes them).
  - :2936 follow outside a scene: with `escort.sff_promote` (new, default true) and `sff`, emit `do=follow` when
    the reply carries `FollowPlayer`, or when the player asked and `FollowPlayer` was NOT offered this turn; when
    it was offered and the model chose nothing, keep the skip with the reason `the model did not choose
    Follow_<player> - her answer stands` (a spoken refusal is never overridden outside a scene).
  - for follow with `sff_promote`: `$res['drop'] = $follows` (CHIM's FollowPlayer line comes out; the escort
    carries the follow; the game re-adds CHIM's follow itself in the fallback). `why` text accordingly.
* `lrgEscortDirective()` (:2833): owner `fw:sff` -> `"$npc travels with $player as his companion; the game itself
  makes her follow / wait / part ways. Answer in one short line, in $npc's own voice - never ignore it - and
  choose no movement action."`; the follow shape for a promotable stranger keeps "choose Follow_<player>" (the
  model's consent), and when `FollowPlayer` is hidden the "the game makes her walk with the player by itself" shape.
* `lrgRecordResult()` (:794): read `fb=` on an OK; store it in `last_result.partial`.
* `lrgFuncretVerdict()` (:916): an OK with `partial` is VOICED like a failure when `voice.fallback` (new, default
  true): `fact` = "<npc> comes along with <player>, but not as his sworn companion: <reason>" (extend `lrgVoicedWhat`
  /`lrgVoicedFact` for the escort code with `partial`); when `lrgVoiceSkip` skips it, it is told on her next turn via
  the existing `missed` note (`['what' => "become $player's companion", 'why' => $reason, 'code' => escort]`,
  rendered by `lrgMissedNote` :1211 - no new prompt text).
* `lrg_core.php` `lrgFollowerPolicy()` (:1784): new config `followers.hide_when_owned` =
  `["ComeCloser","FollowPlayer","Follow","MakeFollower","MoveTo","WaitHere","ReturnBackHome"]`, applied when
  `fw` is `sff` or `custom` or `mate:1`; log line as today.
* `config\lrg_config.default.json`: `escort.sff_promote: true`, `followers.hide_when_owned: [...]`,
  `voice.fallback: true`; one sentence each in the `_readme` strings.

### 6.6 Tests to change / add (they pin the OLD behaviour today)

`tools\test_intent.php:580-583` (the fw:sff directive text), `tools\test_gates.php:1528-1531` (FollowPlayer now
hidden for fw:sff), `tools\flows\scenarios\28_escort.php` section 7 (:121-129: an SFF follower now gets `do=wait`,
log text changes) and section 8 (:135), `tools\flows\scenarios\d62_followers.php:152-160`, `tools\test_phrases.php`
D rows that quote the directive. Add: promotable stranger outside a scene + FollowPlayer in the reply -> one
escort line, FollowPlayer dropped; same with FollowPlayer offered and not chosen -> skipped; `fw:custom` still
skipped; an OK funcret with `fb=` -> voiced, and told next turn when the voice gap skips it; `hide_when_owned`.
Run `php tools/test_intent.php`, `test_gates.php`, `test_phrases.php`, `flows/run_flows.php`, `test_mcm_wiring.php`
in the WSL copy (data/ from the deployed server next to it).

### 6.7 Docs

`PROTOCOL.md` 10.20: a section 8 "[script 509 / pt17] follow / wait / release through Simple Follower Framework"
(the table in section 4 above, the additive `fb=` key on an OK funcret, the `fw:sff` rail change, `hide_when_owned`,
the FollowPlayer drop, `walkto=` on the escort log line). `README.md:112-115` one sentence. `pt16-follow.md` section
5's "Rejected: promotion to a real SFF follower" is overruled by tonight's owner ruling - note it there or here.

### 6.8 Not touched

Barter (`lrgDlgServiceNet`, 10.15) - untouched. `make_esp.py` / the PACK 0x803 - untouched (it stays the
fallback). CHIM, OStim, SFF, USSEP, the MO2 profile, the overwrite folder, the prompt index / deploy order (another
lane). No new wire message type; `ExtCmdLRG_Escort` keeps its shape (one optional key on the reply).

---

## 7. Owner steps

0. General page: **Dry-run mode OFF** (the orchestrator reset `bDryRun` to 0 in the overwrite ini); the Dialogue
   page's "Dry run (nothing is clicked)" is the one that may stay on. Every release tonight died on the General one.
1. CHIM MCM -> Behavior: confirm **"NPCs Walk To Target" is OFF** (the log says it is; after the build the escort
   line prints `walkto=0/1` so it is checkable). "NPCs Sandbox Near Player" may stay on (seated-player only).
2. `F:\Modlists\LoreRim\mods\Simple Follower Framework\SKSE\Plugins\SimpleFollowerFramework.ini`: with
   `bFollowerSandbox=1` a real companion **wanders and sits inside inns, homes and towns** instead of staying at
   your heels (SFF's `IvyFollowerIdleSandbox`, 1024 units around you) - that is the framework's design. Set
   `bFollowerSandbox=0` if you want her right behind you indoors. `iSpeechLevelsPerSlot=25` = one companion until
   Speech 25; to try two, `bFollowerOptionSelector=0` + `iMaxFollowers=2` (your call; the glue reads whatever SFF says).
3. CHIM web UI -> Actions: `Join_<name>_Party` (MakeFollower) and `Follow` still deserve to be OFF (pt9 9.1; the
   glue logs `follower HEALTH:` while they are on).
4. Playtest: "follow me" to Lisette -> log `escort Lisette: recruited as your companion through Simple Follower
   Framework (slot 1/1)`; she now has the vanilla follower HUD, Swiftly Order Squad's wheel and trade; walk around -
   the framework's own 384/512 follow. "wait here" -> `waits here (your companion ...)`. "you can go" -> `dismissed
   through Simple Follower Framework`. Then "follow me" to somebody else while Lisette is with you -> she comes the old
   way and SAYS "you can only lead one companion" (`fb=` voiced). A line `natural follow ...: CHIM's follow taken off
   her` means CHIM tried to drive a companion and was vetoed.

---

## 8. Risks

* A script recruit skips the INFO conditions: hirelings for free, Requiem's per-NPC gates, a rank-0 stranger made
  Ally (section 2.4). The `PffRank >= 0` gate keeps guards, jarls and children out; the real entry wins whenever the
  menuless follower verb is live. If the owner wants hirelings excluded now: refuse promotion for an NPC whose base
  is one of the six `CanRehire*` names (SFF esp globals) - not built into 6.x, named here.
* "stop following me" to a companion the player recruited by the real dialogue now DISMISSES her (that is what the
  owner asked for; the recogniser needs `conf=high`). The dismissal message of the real fragment (`iMessage 0`) shows.
* `DismissFollower(0, 0)` runs inside SFF's script on the caller's stack; `CleanupDismissedFollowerActor` waits 2 s
  only when `iSayLine == 1` (pt9 verify m2) - so the escort handler does not stall.
* The veto removes CHIM's follow-type overrides from a teammate; CHIM's `EndFollowSoft` fragment then never runs for
  that soft move (its ref is cleared by the veto; `ResetPackages` at EndConversation cleans the rest). Same shape as
  `EscortStopFollowing` today.
* SFF's indoor sandbox (section 2.1) will read as "she is not following me" in a tavern - owner step 2 exists for it.
* CHIM's DLL `CurrentParty` stays empty (tonight :422), so CHIM never flips her action set to `available_to_followers`;
  the glue's `hide_when_owned` is the only thing keeping ComeCloser / MoveTo off a companion - the pt9 U3 unknown stands.
* `SFF_SKSE.GetMaxFollowers()` / `SFF_CanRecruitMore` are the framework's numbers; `cap` on the wire is the looser of
  the two gates (pt9 verify 4) and only ever hides what cannot work.
* Object id 0x803 / the overlay are unchanged; a save that holds the overlay keeps working (fallback path).

## 9. Unknowns

* Whether the DLL fills `CurrentParty` once she is a real teammate (pt9 U3) - first real follower ever, watch `setconf
  ... CurrentParty@` in `AIAgent.log`.
* The exact CTDA set on the recruit / reject INFOs as they win (pt9 U1) - only matters for the bypass list.
* `Game.GetDialogueTarget()` outside a menu: None or stale - the `GetDialogueFollowerTarget() == npc` check covers both.

---

## 10. IMPLEMENTER (lane "followers", 2026-09-23 evening) - what was changed and why

Backups of every edited file: `glue\.backup\pt17-followers\<basename>.bak`. Nothing compiled, deployed or installed here
(the Build stage does). `CurrentVersion` was already 509 (the switches lane bumped it). Files outside this lane's
ownership are NOT edited; their exact patches are in the structured report (`papyrus_patch_for_orchestrator`) - the
first of them, `tools\stubs\DialogueFollowerScript.psc`, is BUILD-BLOCKING (four signatures the new code calls).

### 10.1 Corrections to sections 0-5 above (refuter 1's evidence items, accepted)
- 0.1 "not one snapshot carries mate=1 (grep count 0)" is vacuous: the log never prints `mate=` (it rides the snapshot
  wire). The proof that no framework ever had her is CHIM's own DLL line `Setting dialogue busy for Lisette,
  isPlayerTeammate false` on every turn (AIAgent.log:432, 505, 635, 637, 679, 750, 919, 994 - never `true`) and the two
  code paths that produced :2893 / :3049 (`LRG_Main.psc` NoteGlueFollow `chim==1 && !IsPlayerTeammate && !IsSff`;
  TickGlueSlot `!IsPlayerTeammate`).
- 0.5 / 5 walk-to: the distances were not simultaneous. At 20:10:03.422 Lisette was 687.8 units from the player
  (AIAgent.log:5641), not 127 (that is the 20:08:45 watch line); Braste was 914 at 20:09:52 (:5264) and 1192.7 at
  20:09:55 (:5418). Lisette-Braste > 400 stays LIKELY, so the OFF verdict is **[I]**; the new `walkto=` field on the
  escort log line (10.2) is the real check. `[WALKTOTARGET_TIMER]` fired 51 times (the first "after 159s"), not 46.
- `fol=live` is set in `lrg_actions.php` lrgEscortFacts, the MFMD scripts live under `Scripts\Source`, and "carried
  in the save" (0.1) is inferred: the glue released CHIM's follow at 04:31:59 (:2316) and 16:37:26 (:2754), it was
  back at 19:55:11, and the second launch (AIAgent.log 20:01:50, session 9412 at 20:04:31) had the overlay on with no
  new "shadowing" line - a reloaded save carrying `CHIM_FollowPlayerActive=1` + `LRG_GlueFollow=1`.
- 2.4 / 4: Lisette is NOT in PotentialFollowerFaction on this save (refuter 2): Skyrim.esm NPC_ 00013297 and every
  override (NPC Appearances Merged, Requiem for the Indifferent, LoreRim - Outfit Distribution) carry factions 000CF8F9,
  00029DB0, 00053514, 0002817C and no 0005C84D; FDE Lisette adds it only in `Lise_QF_aaLisette_05000810.Fragment_1`
  (her quest stage 2, INFO 050008C4 "Take me with you...", inside her intro tree gated behind MS05 "Tending the
  Flames"). So on the owner's level-1 save the first "follow me" to Lisette takes the FALLBACK (CHIM's follow + the pt16
  overlay) and she SAYS why ("she is not somebody who can be taken on as a companion yet") - owner step in the report.

### 10.2 GAME (`LRG_Followers.psc`; `LRG_Main.psc` escort / natural-follow / NoteFollower sections only)
- `LRG_Followers.ChimFollowVeto(a)` - every follow-type move of CHIM's off her, in the one order that cannot race
  CHIM's own end fragment (refuter 2): `CHIM_FollowPlayerActive` 0 FIRST, the MoveTarget linked ref cleared, CHIM's
  soft-move bookkeeping cleared (`PackageSoft`, `WalkToTargetStartTime`, `WalkToTargetListener` - refuter 1: otherwise
  GetIntoConversation would StartWaitSoft a companion at < 300 units), the two hard overrides 0x2226D / 0x1BC25 and
  the follow faction 0x1BC24 off, the glue's overlay off, the soft package 0x268B0 LAST (its end fragment then finds
  the flag 0 and only tidies up). Returns whether anything was on. The PapyrusUtil / po3 natives it calls
  (`SetFormValue`, `UnsetFloatValue`, `UnsetFormValue`, `SetLinkedRef`) are declared in the installed sources
  compile.ps1 imports (StorageUtil.psc:80/90/92, PO3_SKSEFunctions.psc:908).
- `SffRefuseReason(a)` (technical texts; the server puts them in her words): "she already travels with you as
  somebody else's companion" (teammate, not SFF's) / "Simple Follower Framework is not installed" / "you have no free
  companion slot (<used>/<max> at Speech <n>)" (SFF_CanRecruitMore < 1, tested BEFORE SetFollower because SetFollower
  refuses a full party silently) / "the game does not let you recruit her yet" (PffRank < 0 - refuter 2's truthful
  wording) / "she is a hireling - her price is in her own dialogue" (Skyrim.esm JobMercenaryFaction by EditorID; the
  optional rail of section 8, fail-open when the EditorID does not resolve).
- `SffRecruit(a)`: the veto, then `dfs.SetFollower(a)`, verified with `SFF_SKSE.IsVanillaFollower`; `LRG_SffRecruit=1`.
- `SffWait(a, abWait)` (refuters 1 and 2): `SFF_SetLastSpeaker(a)`, and only when `GetDialogueFollowerTarget() == a`
  SFF's own `FollowerWait()` / `FollowerFollow()` (the 72-hour "dismiss if forgotten" timer included); the direct
  `WaitingForPlayer` 1 / 0 write (never -1) only when the target check fails or SFF's call did not take; then the
  veto. Returns "sff" | "sff+av" | "av" for the log line. `WaitAv`'s docstring updated accordingly.
- `SffSlotText()`, `PlayerSpeech()`, `FacHireling()`, three CHIM form helpers. `RepairGhost`'s promote path calls the
  veto before `SetFollower` and marks `LRG_SffRecruit`.
- **No `SffDismiss`, no `DismissFollower` stub** (refuter 2, over refuter 1): "stop following me" / "you can go" to
  a companion of SFF's is a WAIT (`EscortRelease` -> `SffWait(true)`), because dismissal is commit-class (pt9 6.2
  item 4, PHASE2_DESIGN 782/889) and the recogniser lumps "stop following me" with "you can go" (lrg_intent.php).
  Parting ways for good stays her own dialogue (the menu, or the two-step menuless dismiss verb when it is live). A
  `do=dismiss` for explicit parting phrases with the two-step confirmation is left for a follow-up.
- `LRG_Main.psc`: `SffFollowOn()` (= IsEnabled && `bSffFollow:Followers`, default on); `EscortFacts(a)` = the tail
  of every escort log line, ` fol=[<the snapshot's fol= string>] walkto=<StorageUtil AIAgentNpcWalkToTarget>` (pff /
  cff / slot / cap are all in it - refuters 1 and 2); `EscortRefuseReason` lets an SFF follower through while the
  switch is on (a custom follower is refused as before); `EscortFollow`: after the scene stop, SFF's own companion ->
  `SffWait(false)`, a stranger -> `SffRefuseReason` -> `SffRecruit` (log "recruited as your companion through Simple
  Follower Framework (slot u/m)" + one `lrg_log` line), otherwise today's CHIM-follow + overlay path with the
  additive key `fb=<reason>` appended to field 2 of the OK funcret, the corner note "walks with you, but not as a
  companion - <reason>" under bNotifyErrors and "NOT as your companion: <reason>" on the log line; `EscortWait` /
  `EscortRelease` first branch for an SFF companion (release = wait, with the dead / disabled / 3D-loaded and
  IsInCombat guards, voiced through EscortSayRefusal - refuter 1); `EscortStopFollowing` on a teammate vetoes instead
  of returning ""; `NoteGlueFollow` (also entered for a teammate CHIM is soft-moving with chim 0 - one GetLinkedRef
  per snapshot of a teammate only), `TickGlueSlot` and `NoteFollower`'s teammate block call the veto ("natural follow
  <n>: off - she is your follower now; CHIM's follow taken off her (her framework owns follow)"). With `bSffFollow`
  OFF every one of these is 508's behaviour exactly.
- MCM: `config.json` Followers page toggle `bSffFollow:Followers` after bNaturalFollow (help: the game's own follower
  distance, FARTHER than the natural follow, the sandbox indoors, what a real companion gains - refuter 2, no
  "smoother" claim); `settings.ini` `[Followers] bSffFollow = 1`. `test_mcm_wiring.php`: 13/0.

### 10.3 SERVER (`lrg_actions.php` escort section + the funcret-voicing functions it needs; `lrg_config.default.json`)
- `lrgEscortFacts()` gains `sff` (SFF's half on the snapshot), `take` (owner '' AND pff >= 0 AND cap 1) and `why`.
  New `lrgEscortSffRouted(owner)`: `fw:sff` and `ghost` are the game's to route (`escort.sff_owned`, default true).
- `lrgEscortPlan()`: the owner rail skips only `fw:custom` / `teammate` / `faction:*`; outside a scene a `do=follow`
  goes out for a routed owner, or when the PLAYER asked (conf=high) and `take` (`escort.sff_promote`, default true) -
  the reply carrying Follow_<player> or no movement at all (the in-scene safety-net shape, applied outside a scene
  too: refuter 2's alternative to the silent skip); the model's own unasked Follow_<player> stays CHIM's package
  follow as before, and the skip line says why ("the player did not ask" / "not promotable: <why>"). Whenever the
  framework may take her (`routed || take`) CHIM's Follow_<player> line is DROPPED from the batch (the game re-adds
  CHIM's follow itself in the fallback). The escort log line carries `owner=` and `take=`.
- `lrgEscortDirective()`: `fw:custom` / teammate / faction -> she SAYS her own companion dialogue is the way ("ask
  her the way he always does") - never "her own follower dialogue carries that out" while ml=0 (refuter 2);
  `fw:sff` -> "the game itself makes her follow again / wait / stop following and wait - parting ways for good is
  said in her own companion dialogue"; a promotable stranger or ghost -> the usual follow shapes plus "If the game
  can take her on as his companion it does so by itself; if it cannot, she is told why and says so then"; chim:1,
  not promotable, Follow_<player> withheld -> "she is already walking with him" (the truth, not "nothing can").
- `lrgRecordResult()` reads `fb=` on an OK into `last_result.partial` (only when present - the record's shape is
  otherwise 507's); `lrgFuncretVerdict()` voices an OK with `partial` like a failure (`escort.voice_fallback`,
  default true) - `lrgVoicedWhat` "comes along with <player>, but not as his sworn companion", `lrgVoicedWhy` maps
  the five fb= reasons to plain words ("<player> can lead only one companion at a time, and that place is taken" /
  "not somebody who can be taken on as a companion yet" / "a hireling, and her price comes first" / "nothing could
  make her a real companion just now" / "her own companion orders"), `lrgVoicedDirective` tells her she IS coming
  along and must not pretend she has joined him; when lrgVoiceSkip skips it (the 8 s gap etc.) the `missed` note
  carries it to her next turn ("did not manage to become <player>'s companion just now (<why>)").
- Config: `escort.sff_owned`, `escort.sff_promote`, `escort.voice_fallback` (+ `_sff_readme`). The proposal's
  `voice.fallback` became `escort.voice_fallback` (this lane owns the escort keys). `followers.hide_when_owned` rides
  in the lrg_core.php patch (not this lane's file).

### 10.4 Tests (WSL, staged copy `C:\Users\Jordan\AppData\Local\Temp\lrg_test\followers\glue` + the deployed data/)
php -l clean; both JSON files valid (config.json no BOM, LF); test_mcm_wiring 13/0; test_gates 482/0 (section 35 (1d):
the five fb= facts, the record, the voiced verdict, the Error-shaped funcret, the directive, the voice-gap missed
note, a plain OK unchanged); test_dialogue 373/0; test_services 35/0; test_phrases 47/0; test_intent 315/1 (the one
FAIL is the old fw:sff wording pin at test_intent.php:580-583 - patch in the report); flow 28 rewritten (74 checks:
sections 5b promotable stranger / no slot / no PFF / on the stage / already walking / ghost, 5c fb= voiced + the
gap, 7 SFF companion wait / follow again / "you can go" = wait, a custom follower says so, 8 remembered fol=); the
full flow suite 79/79, 1398 checks, 0 warnings.

### 10.5 Not done here (patches in the report) / open
- `tools\stubs\DialogueFollowerScript.psc`: `SFF_SetLastSpeaker(Actor)`, `Actor GetDialogueFollowerTarget()`,
  `FollowerWait()`, `FollowerFollow()` - BUILD-BLOCKING. `lrg_core.php` lrgFollowerPolicy `hide_when_owned` (sff /
  custom / mate:1) and `hide_when_following` (ComeCloser / MoveTo / FollowPlayer while chim:1 - refuter 2's stall
  fix) + `test_gates.php` 34 (c) flip + `lrg_config.default.json` followers keys; `test_intent.php:580-583`;
  PROTOCOL 10.20 section 8 (text in 10.6); README one line.
- A `do=dismiss` (explicit parting phrases, two-step confirmation) - not built; pt9 U3 (`CurrentParty`) unchanged;
  the watch judge's "she moved (dist 127)" wording for an unchanged near distance (cosmetic, lrg_core.php).

### 10.6 PROTOCOL.md 10.20 - section 8 to append (verbatim)

**### 8. [script 509 / pt17] follow / wait / release through Simple Follower Framework**

Additive. One optional key on the OK funcret; no new verb, no new message type. Owner: "lets change it to
whatever follower mod is managing it and work with it that way". GAME (MCM `bSffFollow:Followers`, default on):
`do=follow` makes her the player's real companion through SFF's own `SetFollower` when SFF can take her
(installed, `SFF_CanRecruitMore`, PotentialFollowerFaction, not a hireling - `LRG_Followers.SffRefuseReason`),
after taking every follow-type move of CHIM's off her (`ChimFollowVeto`: flag 0 first, linked ref and soft-move
bookkeeping cleared, overrides 0x2226D / 0x1BC25 / 0x268B0, faction 0x1BC24, the pt16 overlay); a companion of
SFF's follows again / waits through `SFF_SetLastSpeaker` + `FollowerFollow` / `FollowerWait` (the direct
`WaitingForPlayer` write only when `GetDialogueFollowerTarget()` does not answer her); `do=release` on her is a
WAIT, never a dismissal. Otherwise the 508 path runs (CHIM's follow + the overlay) and the OK funcret carries
`fb=<technical reason>` on field 2 (`OK: <npc> comes with you.`), plus the corner note. Any follow-type move of
CHIM's found on a teammate is vetoed at her next snapshot / tick. The escort log line ends in
`fol=[<fol=>] walkto=<0|1>`. SERVER: `lrgEscortFacts` `sff` / `take`; `fw:sff` and `ghost` are routed
(`escort.sff_owned`), `fw:custom` / teammate / faction stay skipped and she SAYS her own dialogue is the way;
outside a scene `do=follow` goes out when the player asked and SFF can take her (`escort.sff_promote`), and CHIM's
`Follow_<player>` line is dropped whenever the framework may take her; an OK with `fb=` is voiced like a failure
(`escort.voice_fallback`) or told on her next turn through `missed`. Tests: test_gates 35 (1d), flow 28 (5b, 5c, 7,
8). The framework's follow is USSEP's `PlayerFollowerPackage` (Min 384 / Max 512) - wider than the pt16 overlay;
SFF's `IvyFollowerIdleSandbox` sandboxes her indoors with `bFollowerSandbox=1`.
