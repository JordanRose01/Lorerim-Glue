# pt16 - Lisette's sprint-and-stop following (investigator notes, 2026-09-23)

Owner: "when i had the npc lizette following me she was very erratic in her movements. she wouldn't walk
at all. She would just sprint as fast as she could and then stop. And then when I'd move again, she'd
sprint as fast as she could and stop." (Winking Skeever, interior.)

Verdict: **(b) - a far too large follow distance in CHIM's own follow package.** Not Always Run, not
preferred speed, not SpeedMult, not the glue's conversation hold.

## 1. What was on her

* The follow was CHIM's `FollowPlayer` (`stayAtPlace(npc, 1)`), issued in an earlier session and carried
  over by the save, because PapyrusUtil package overrides persist (`PapyrusUtil SE ... \Source\Scripts\
  ActorUtil.psc:6` "These overrides persist through save games"). Which earlier one is not established
  (refuter correction): `actions_issued` holds only two rows in total (rowid 95, `Lisette|command|
  FollowPlayer@Jordan`, 2026-09-22 ~17:36 -04:00, and rowid 117), and the 04:30:52 CHIM FollowPlayer of
  2026-09-23 (`lorerim_glue.log:2294`, echoed in `chim.log`) has no row at all - so it was either of the two,
  depending on which save was loaded at 16:35:45. The PROOF that CHIM's follow was on her tonight is the
  game's own release text at 16:37:26 ("CHIM's follow is off (its package override and
  CHIM_FollowPlayerActive ...)"), which `LRG_Main.EscortStopFollowing` returns ONLY when
  `LRG_Followers.ChimFollowActive() == 1` - not the `fol=live` of the escort log line, which only says the
  follower facts came from a fresh snapshot (`lrg_actions.php`, "remembered" vs "live").
* Tonight: `lorerim_glue.log` 16:37:22 `intent=escort/release` -> 16:37:26 `GAME escort Lisette: released -
  CHIM's follow is off (its package override and CHIM_FollowPlayerActive, as CHIM's ResetPackages does)`.
  Everything the owner saw before 16:37 was CHIM's `AIAgentFollowPlayerPackage` running. No escort
  `do=follow` was sent tonight (only the release), so the glue's escort path was not involved in the
  movement, but it REUSES the same package (`LRG_Main.EscortFollow` :1801 -> `LRG_Followers.ChimFollowPlayer`
  :317-325 -> `AddPackageOverride(0x2226D, 100, 0)`).
* Tavern context in the game log (`AIAgent.log` 16:36:32 SPATIAL distance=126.4): she was 1.8 m from the player
  when he spoke to her, i.e. standing still inside the dead band described below.

## 2. The package, decoded (AIAgent.esp PACK 0x2226D `AIAgentFollowPlayerPackage`, form version 44)

Decoder: `C:\Users\Jordan\AppData\Local\Temp\lrg_test\pack_dump.py` (read-only, run in WSL over
`/mnt/f/Modlists/LoreRim/mods/CHIM/AIAgent.esp`).

| subrecord | value |
|---|---|
| PKDT | general `0x00040000` = **AllowSwimming only - NO Always Run (0x2000)**; type 18; interrupt override None; **preferred speed 0 = Walk**; interrupt flags `0x0` (no hellos / idle chatter / world interactions while following) |
| PSDT | any time, duration 0 |
| CTDA | `GetFactionRank(AIAgent.esp 0x1BC24 AIAgentFactionFollow) == 1` (func 73) - the only condition |
| PKCU | 6 data inputs, template 0, version 4 |
| procedure | **Follow** (one procedure, sequence of one) |
| Target to Follow | SpecificReference 0x14 (player) |
| **Min Radius** | **512.0** units (~7.3 m) |
| **Max Radius** | **1024.0** units (~14.6 m) |
| Accompany? | 0 |
| Ride Horse? | 0 |
| **Need LOS?** | **1** |
| POBA/POEA/POCA | empty (the PF_ fragment script only Debug.Trace's) |

Bethesda's own follower package, same procedure, for comparison (Skyrim.esm, decoded with the same tool):

| package | Min | Max | Accompany | Ride Horse | Need LOS | PKDT speed / flags |
|---|---|---|---|---|---|---|
| `FollowPlayer` 0x000750BE (DialogueFollower's alias package) | **128** | **256** | 0 | - | - | Run, AllowSwimming, interrupts 0xFEFF |
| `FollowerPackageTemplate` 0x000D530D | 128 | 256 | 0 | - | - | Run, AllowSwimming |
| `Follow` template 0x00019B2C | 128 | 256 | 1 | 0 | 0 | Run, AllowSwimming |
| CHIM `AIAgentFollowPackage` 0x1BC25 (Follow a linked ref) | 256 | 512 | 0 | 1 | 0 | Run, LockDoorsStart |

The Follow procedure holds still while the actor is inside Min Radius, only starts moving once the target is
beyond Max Radius, and its locomotion speed grows with the gap to close. With Bethesda's 128/256 a follower
starts moving when you are ~3.7 m away and closes a ~1.8 m gap: she walks when you walk and runs only when
you sprint away or fast-travel. With CHIM's 512/1024 she does nothing until the player is more than 14.6 m
away - in the Winking Skeever that is the other end of the room or the stairs - and then has to close a
>1024-unit gap to get back inside 512 units, which the engine does at a run, and stops dead 7 m short of
the player. Repeat every time the player moves off. `Need LOS = 1` adds the indoor jitter: behind the bar,
a post or the stairs she is "not following" until she has line of sight again, then stops as soon as she has
it. That is the report, verbatim.

Note the vanilla packages carry preferred speed **Run** and still walk behind a walking player, which is
the proof that neither PKDT's preferred speed nor Always Run decides a Follow procedure's pace - the
radii do.

## 3. Direct log evidence of the dead band

`lorerim_glue.log` 2026-09-23 04:30:52 `watch: FollowPlayer npc=Lisette - the next snapshots say whether she
really moved (dist 436)` -> 04:31:18 `watch: FollowPlayer npc=Lisette FAILED after 26s (did not move at all;
scene=0 dist=905 was 436)`. The player walked from 436 to 905 units away and the distance never shrank: 905 is
inside the 1024 Max Radius. (Refuter correction: the judge, `lrg_core.php:276`, only tests that the distance
did not shrink by 100 units, so "did not move at all" does not prove she took no step - it proves she never
closed the gap, which is what a >512-unit dead band predicts.) The follow was working as authored; the
server's "did not move" verdict (and the "it failed" line she was then told to say, addendum 11 c) was a
false negative produced by the dead band. After the fix (Max 256) that judge becomes truthful without any
server change. Also unverified (the CK wiki was unreachable from every host): in BOTH CHIM's record and
Bethesda's, the Follow procedure's PKC2 binds its 2nd and 3rd parameters to input 01 (input 02 "Max Radius:"
is never bound), so which input the engine reads as the far radius is not established - the dead band may be
512 rather than 1024 units. Same cause, different number, same fix: both inputs are patched.

## 4. The other candidates, ruled out

* (a) Always Run / preferred speed: PKDT above - flag clear, speed Walk. CHIM's `FollowPackageSoft` 0x268B0
  DOES carry Always Run (Travel to within 128 of a linked ref, then Wait 30 s), but it is only applied by
  `ComeCloser` (:558), `GetIntoConversation` (:1568, only while the PLAYER IS SITTING, :1540, and she is
  >1024 away) and the optional "NPCs Walk To Target" (:1752, `AIAgentNpcWalkToTarget`, a CHIM MCM toggle,
  `AIAgentMCMConfigScript.psc:1098/2499`, default 0). No ComeCloser / walk-to line for Lisette in tonight's
  `AIAgent.log`; the owner was walking, not seated.
* (c) SpeedMult: `grep -ri SpeedMult` over `glue\`, `mods\CHIM\Source\Scripts`, and every `*OStim*` mod's
  `.psc` -> nothing. Neither the glue, CHIM nor OStim writes it.
* Conversation hold: `LRG_Main.ConvSkipReason` :2737 skips any follower-like NPC (`bFollowerHoldSkip`,
  settings.ini:283 = 1, `LRG_Followers.IsFollowerLike` reads `CHIM_FollowPlayerActive`), so she was never
  `SetDontMove`-frozen while following; the 16:36:32 turn ran `mode=private` with no hold line.
* (d) engine scene: snapshot `scene=0` at 04:31 and tonight (`gate ... reasons=-`), no BardSongs scene.

## 5. Why the glue cannot simply reuse CHIM's package with other numbers

Package data lives in AIAgent.esp (read-only, and package inputs cannot be changed at runtime by any
Papyrus / PapyrusUtil / po3 call). The only way to change the radii is a package record of our own.

Two facts shape the design:
1. PapyrusUtil: "Priority ranges from 0 to 100 with 100 being highest priority ... if multiple overrides have
   same priority then last added package will run" (`ActorUtil.psc:6-9`). CHIM applies at **100**, the
   ceiling, so the glue cannot out-rank it - it must be added at 100 **after** CHIM's, and re-added whenever
   CHIM re-applies its own (`stayAtPlace` :919, `EndFollowSoft` :550, `MoveToPlayer` :877 all
   `AddPackageOverride(FollowPlayerPackage, 100, 0)` again).
2. The glue gets no event for CHIM's own catalog actions (`LRG_Main.NoteChimAction` :2866 is dormant for them -
   `OnChimCommand` is the mod-event name registered at :440 / :759, and the DLL raises `CHIM_CommandReceived`
   only for ExtCmd), and the server only appends `ExtCmdLRG_Escort do=follow` when she is in an engine scene
   (`lrg_actions.php:2932` "she is in no engine scene - CHIM's own follow is enough"). So a
   plain LLM-chosen FollowPlayer is only visible to the game through `CHIM_FollowPlayerActive` (StorageUtil),
   which the snapshot path already reads (`NoteFollower`, at most every 20 s per NPC, and on every line she
   says). A light 4-second tick, in the `TickEscort` mould, closes the gap.

`Actor.GetCurrentPackage()` (SKSE64 `Actor.psc:187`, the compiler's import) tells whether CHIM's package or
ours is the one running, so the re-assert is exact and cheap.

Rejected: promotion to a real SFF follower. Speech 15 -> exactly ONE slot (`SimpleFollowerFramework.ini`
`iSpeechLevelsPerSlot=25`, research pt9 section 1), a recruit needs her real recruit entry and Requiem's
gates, and it would change who she is to the player. The package fix is purely about how she walks.

## 6. The fix (glue only)

### 6.1 `glue\tools\make_esp.py` - one PACK record, `LRG_FollowPackage`, object id 0x803

* `PACK_ID = 0x803` (in `ESL_OBJECT_IDS`), `NEXT_OBJECT_ID = 0x804`, HEDR record count 3 -> **5** (2 groups + 3
  records); emit `GRUP PACK` (type 0, label `PACK`) after the QUST group; assert `PACK_ID not in RETIRED_IDS`.
* Body = CHIM's 0x2226D subrecords byte-for-byte MINUS `VMAD` (its fragment script is CHIM's) and MINUS `CTDA`
  (it names an AIAgent.esp faction; the plugin keeps Skyrim.esm as its only master), with three CNAM patches.
  Exact sequence (`sub(sig, bytes)` as make_esp.py already does; 691-byte body):

```
EDID  "LRG_FollowPackage\0"
PKDT  00 00 04 00 12 00 00 00 00 00 00 00          general 0x00040000 AllowSwimming, type 18, Walk, interrupts 0
PSDT  ff ff 00 ff ff 00 00 00 00 00 00 00
PKCU  06 00 00 00 00 00 00 00 04 00 00 00          6 inputs, no template, version 4
ANAM  "SingleRef\0"   PTDA 00 00 00 00 14 00 00 00 00 00 00 00   (SpecificReference = player 0x14)
ANAM  "Float\0"       CNAM 00 00 00 43                           Min Radius 128.0   (CHIM: 00 00 00 44 = 512)
ANAM  "Float\0"       CNAM 00 00 80 43                           Max Radius 256.0   (CHIM: 00 00 80 44 = 1024)
ANAM  "Bool\0"        CNAM 00                                    Accompany 0 (as CHIM and as vanilla FollowPlayer)
ANAM  "Bool\0"        CNAM 00                                    Ride Horse 0
ANAM  "Bool\0"        CNAM 00                                    Need LOS 0         (CHIM: 01)
UNAM 00  UNAM 01  UNAM 02  UNAM 04  UNAM 06  UNAM 08
XNAM 09
ANAM "Sequence\0"  CITC 00 00 00 00  PRCB 01 00 00 00 00 00 00 00
ANAM "Procedure\0" CITC 00 00 00 00  PNAM "Follow\0"  FNAM 00 00 00 00
PKC2 00  PKC2 01  PKC2 01  PKC2 04  PKC2 06  PKC2 08
UNAM 00 BNAM "Target to Follow\0" PNAM 01 00 00 00
UNAM 01 BNAM "Min Radius:\0"      PNAM 01 00 00 00
UNAM 02 BNAM "Max Radius:\0"      PNAM 01 00 00 00
UNAM 04 BNAM "Accompany?\0"       PNAM 01 00 00 00
UNAM 06 BNAM "Ride Horse?\0"      PNAM 01 00 00 00
UNAM 08 BNAM "Need LOS?\0"        PNAM 01 00 00 00
POBA ""  INAM 00 00 00 00  PDTO 00 00 00 00 00 00 00 00
POEA ""  INAM 00 00 00 00  PDTO 00 00 00 00 00 00 00 00
POCA ""  INAM 00 00 00 00  PDTO 00 00 00 00 00 00 00 00
```
  Record header: `record('PACK', 0, (OWN_INDEX << 24) | PACK_ID, body)` (flags 0, form version 44 - the same
  as CHIM's record). No conditions: the override is only ever put on and taken off by the glue, and PapyrusUtil
  evaluates an unconditioned override as always valid (CHIM's own `doNothing` use is the precedent). Verify with
  `tools\esp_dump.py` (it prints every subrecord; `HEDR records=5 nextObjectId=00000804`).
* `Seq\LoreRimGlue.seq` unchanged (only Start-Game-Enabled quests belong in it).

### 6.2 `glue\game\LoreRimGlue\Source\Scripts\LRG_Followers.psc` - the package helpers (global, facts-and-repair file)

* `Package Function GluePkg()` -> `Game.GetFormFromFile(0x803, "LoreRimGlue.esp") as Package`;
  `Package Function ChimFollowPkg()` -> `GetFormFromFile(0x2226D, "AIAgent.esp")`.
* `bool Function GlueFollowOn(Actor a)`: `p = GluePkg()`; None -> false; `ActorUtil.RemovePackageOverride(a, p)`
  then `ActorUtil.AddPackageOverride(a, p, 100, 0)` (remove-then-add = LAST ADDED, wins the tie at 100);
  `StorageUtil.SetIntValue(a, "LRG_GlueFollow", 1)`; `a.EvaluatePackage()`; true.
* `Function GlueFollowOff(Actor a)`: `RemovePackageOverride(a, GluePkg())` (guard None), `UnsetIntValue
  LRG_GlueFollow`, `EvaluatePackage()`.
* `bool Function GlueFollowLost(Actor a)`: `a.GetCurrentPackage() == ChimFollowPkg()` - CHIM re-applied its own
  after ours; ours must be re-added.
* `int Function GlueFollowOn(...)` reads no MCM (this file has no SettingBool): the callers decide.

### 6.3 `LRG_Main.psc` - hooks, all behind `SettingBool("bNaturalFollow:Followers", true)`

* `EscortFollow` (:1771-1842): after the CHIM-follow block (both "already on" and "put on" branches, not the
  `followFailed` branch) call `LRG_Followers.GlueFollowOn(akNpc)`, `GfTrack(akNpc)`, and add
  `" - natural follow on (glue package 128/256)"` to the log line. The scene verdict / `EscortStopScene` /
  `TickEscort` logic from playtest 13 is untouched.
* `EscortStopFollowing` (:1890) and `EscortDropFollow` (:1913): first line `LRG_Followers.GlueFollowOff(akNpc)`
  (wait and release must take ours off too, whoever put CHIM's on).
* `NoteFollower` (:3107, snapshot path, <= every 20 s per NPC): after the teammate block:
  `chim = LRG_Followers.ChimFollowActive(akNpc)`, `mine = StorageUtil.GetIntValue(akNpc, "LRG_GlueFollow", 0)`;
  - `chim == 1` and not teammate / not SFF and toggle on -> if `mine != 1 || GlueFollowLost` ->
    `GlueFollowOn` + `GfTrack` + log `natural follow <name>: shadowing CHIM's follow`;
  - `chim != 1 && mine == 1` (CHIM's ResetPackages / StopCurrent ran, or toggle off) -> `GlueFollowOff` + log.
  - the existing teammate branch also calls `GlueFollowOff` (her framework owns follow now).
* `TickGlueFollow(afNow)`: a 4-slot ring `gfQ` (`Actor[4]`, created in `Maintenance` next to `folSeenQ`, and
  re-created on `Length != 4` exactly as `NoteFollower` does for a pre-fix save - never in OnInit). For each
  slot: gone / dead / disabled -> drop; `chim != 1` or teammate / SFF or toggle off -> `GlueFollowOff`, drop;
  `GlueFollowLost` -> `GlueFollowOn` (log once per re-assert). `RequestTick(4.0)` while any slot is set. Wire it
  into the `OnUpdate` fan-out (:1315-1324) beside `TickEscort`, guarded by `if gfCount > 0`.
* `FollowerSweep` (:3284, boot step 9): for each loaded actor with `LRG_GlueFollow == 1` -> `GfTrack(a)` so a
  follow that crossed a save is watched again (the tick then keeps or drops it). **Not built that way** - see
  section 9 (b): the ring is a saved field and survives the load by itself, so no sweep and no Maintenance line
  is needed, and nothing sits behind FollowerSweep's `bFollowerRepair` gate (refuter 1 item 3, refuter 2 item 3).
* `CurrentVersion` 507 -> **508** (in the orchestrator's patch, section 10 - line 36 is outside the escort section).

### 6.4 MCM

* `MCM\Config\LoreRimGlue\config.json`, Followers page, after `bEscortStopScene:Followers`:
  `{ "id": "bNaturalFollow:Followers", "text": "She follows you like a real follower", "type": "toggle",
  "help": ...` - the shipped wording (section 9) says "about four times farther away than the game's own
  followers (512 / 1024 units against their 128 / 256) and only follows while she can see you", NOT the
  "fifteen metres / seven" numbers this note first proposed (refuter: the engine's reading of the two radius
  inputs is unverified, see section 3). The file is UTF-8 WITHOUT a BOM and LF-terminated, and must stay so:
  the BOM was the "Failed to parse config for LoreRimGlue" MCM crash fixed earlier on 2026-09-23
  (research/pt15-performance.md :16-21, :114). This note's first draft said "keep the file's BOM and CRLF" -
  that was wrong.
* `settings.ini` `[Followers]` `bNaturalFollow = 1` (a MISSING key reads as 0 = off, so the line is
  mandatory). `iSettingsVersion` (306: 506, not 507 as first written here) is only ever tested `> 0`
  (`LRG_Main.psc:1009`) and is left alone. `tools\test_mcm_wiring.php` passes by its rule (a) once the id
  string is in LRG_Main.psc.

### 6.5 Docs

`PROTOCOL.md` 10.20: one paragraph - the game applies its own follow package on top of CHIM's; nothing on the
wire changes in either direction (a 0.5.6 server and script 508 interoperate; script 507 with the new server
simply keeps CHIM's radii).

### 6.6 Not touched

Server plugin (no wire change; the `watch:` judge becomes truthful by itself), `esp_dump.py`, `compile.ps1`
(Package / Actor declarations already imported: `stubs\Package.psc`, SKSE64 `Actor.psc:187`), CHIM, OStim,
LoreRim, MO2 profile.

## 7. Owner-side steps

1. Nothing to change before the build. After it: LoreRim Glue MCM -> Followers -> "She follows you like a
   real follower" is on by default.
2. CHIM MCM: make sure "NPCs Walk To Target" is OFF (it makes any NPC who speaks sprint to her listener with
   the Always-Run soft package when they are more than 400 units apart - `AIAgentAIMind.psc:1752-1765`).
3. Playtest check: "follow me" to Lisette in the Skeever, walk around the room - she should walk 2-4 m behind
   and never sprint; the log shows `escort Lisette: ... - natural follow on (the glue's package over CHIM's:
   128 / 256 units, no LOS rule)` or `natural follow Lisette: shadowing CHIM's follow (...)`, and `GAME` lines
   `natural follow Lisette: re-asserted (CHIM put its own package back on top)` at most once each time CHIM
   re-applies its own; `standing down while CHIM moves her itself` / `on - CHIM's own move has ended` around a
   ComeCloser; `off - ...` with the reason when it comes off. A line that says `natural follow NOT on
   (LoreRimGlue.esp PACK 0x803 not found)` means the new plugin did not install.

## 8. Risks

* Tie at priority 100: if CHIM re-applies its package (a new FollowPlayer, EndFollowSoft after ComeCloser,
  MoveToPlayer) hers wins until the next 4-s tick re-adds ours. Worst case 4 s of the old radii.
* CHIM's `ResetPackages` does not know our form: our override stays on until the tick / snapshot sees
  `CHIM_FollowPlayerActive == 0`. Covered by the tick (tracked NPCs, every 4 s), the snapshot path (any NPC
  the player deals with) and the 120-s out-of-sight rule (section 9 g); `StopCurrent`'s `ClearPackageOverride`
  strips ours anyway. Ours is only ever put on a TRACKED NPC (GfTrack evicts with GlueFollowOff), so no
  override is ever dormant on somebody nobody watches.
* Interrupt flags 0 (copied from CHIM): no engine hellos / idle chatter / world interactions while she
  follows - the same as today under CHIM's package.
* Object id 0x803 is spent for good (never reuse; a save may hold the override by form).
* A save from before 508 has `gfQ == None`: handled by `GfRing()` (`!gfQ || gfQ.Length != 4`), as `folSeenQ`.
* A CHIM soft move that never ends cleanly (the DLL calling `ReleaseFromConversation` directly, which does not
  clear the MoveTarget linked ref) would keep ours stood down until CHIM's next `ResetPackages` - i.e. CHIM's
  own radii, today's behaviour, never worse than today. Within CHIM's own scripts every FollowSoft path ends
  in a ref clear (EndFollowSoft, GetIntoConversation's arrival, the walk-to release, ResetPackages).
* "NPCs Walk To Target" (CHIM MCM, save-side, unknown tonight): its Always-Run soft package is the one other
  sprint-and-stop source in this list and is untouched by this fix - owner step 2.

## 9. What was built (implementer, 2026-09-23 evening) and where it differs from section 6

Files, all under `glue\` (backups in `glue\.backup\pt16-follow\<basename>.bak`):

* `tools\make_esp.py`: `PACK_ID = 0x803`, `NEXT_OBJECT_ID = 0x804`, `follow_package()` authored from the 6.1
  listing (691-byte body asserted), emitted in a `GRUP PACK` after the QUST group, HEDR count 3 -> 5. Verified in
  WSL: `esp_dump.py` prints `PACK size=691 flags=00000000 formid=01000803 formver=44`, `HEDR records=5
  nextObjectId=00000804`; a scratch verifier (`scratchpad\pt16esp\verify_pack.py`) rebuilt the expected body
  from the INSTALLED `AIAgent.esp` record (matched by the low 24 bits 0x02226D - that plugin has five masters,
  Skyrim / Update / Dawnguard / HearthFires / Dragonborn, in-file id `0502226D`, 989 bytes) minus VMAD (245)
  and CTDA (32), EDID renamed, the three CNAM patches: **byte-identical** to the generated PACK, and the only
  form field left (PTDA) is `00000014`. `LoreRimGlue.esp` regenerated (1422 bytes); `Seq\LoreRimGlue.seq`
  byte-identical to before.
* `LRG_Followers.psc` (helpers only): `GluePkg`, `ChimFollowPkg`, `ChimMoveTargetKw`, `GlueFollowMine`,
  `GlueFollowOn` (remove-then-add at 100, `LRG_GlueFollow = 1`, false when the PACK is not resolvable),
  `GlueFollowOff` (one StorageUtil read when it was never on), `GlueFollowLost` (`GetCurrentPackage() ==`
  CHIM's 0x2226D), `ChimSoftMoveOn` (MoveTarget linked ref, AIAgent.esp KYWD 0x21245, `!= None`).
* `LRG_Main.psc`, ESCORT SECTION ONLY: `EscortFollow` appends `GlueFollowApply()`'s fragment to `pkg` on both
  success branches (the `followFailed` branch untouched); `EscortStopFollowing` takes ours off first
  (`GlueFollowRelease`) and says so in its return string; `EscortDropFollow` takes ours off first; and a new
  block right after `TickEscort`: fields `gfQ / gfT / gfCount / gfSlot / gfOn / gfOnAt`, `NaturalFollowOn`,
  `GfRing`, `GfIndex`, `GfTrack`, `GfDrop`, `GlueFollowRelease`, `GlueFollowApply`, `NoteGlueFollow`,
  `TickGlueFollow`, `TickGlueSlot`. Papyrus constraints kept: no try/catch, docstrings well under 500 chars
  (long notes are `;/ /;` comments), nothing in OnInit, arrays guarded with `!gfQ || gfQ.Length != 4`.
* MCM: `config.json` toggle `bNaturalFollow:Followers` after `bEscortStopScene:Followers` (UTF-8, no BOM, LF -
  verified by byte count after the edit); `settings.ini` `[Followers] bNaturalFollow = 1`.
  `php tools/test_mcm_wiring.php --quiet`: 4 passed, 0 failed; the id is satisfied by rule (a).

Differences from section 6, and why:

a. `NoteGlueFollow` is hooked at the TOP of `NoteFollower`, before its 20-second ring throttle and before
   `FollowerAware()` (refuter 2 items 1 and 3): it gates itself on `IsEnabled() && bNaturalFollow` only, and
   costs two StorageUtil reads while nothing is on. The one hook line is in the orchestrator's patch
   (section 10) because `NoteFollower` is outside the escort section.
b. No `FollowerSweep` change, no `Maintenance` change, no new boot step: `gfQ` is a saved field and DELIBERATELY
   survives the load - the overrides it watches survive it too (PapyrusUtil) - so the first fan-out tick after
   the boot queue runs `TickGlueFollow`, and `GlueFollowLost` re-asserts ours whatever order PapyrusUtil
   restored the two overrides in (refuter 2 item 3). A pre-508 save has no ring: `GfRing()` makes one on
   first use. Tonight's Lisette (CHIM's follow on, nothing tracked) is picked up by her next snapshot.
c. STAND DOWN for CHIM's own moves (refuter 2 item 2): while `ChimSoftMoveOn()` - the MoveTarget linked ref
   is set - ours comes off and she stays tracked; ours goes back on once the ref is None with
   `CHIM_FollowPlayerActive` still 1. Verified in `AIAgentAIMind.psc`: `FollowSoft` :509-522 sets the ref,
   REMOVES CHIM's FollowPlayer override and adds the soft package at 55; its end fragment
   (`PF_AIAgentFollowPackageSoft_020268B0.psc:17`) -> `EndFollowSoft` :530-552 clears the ref and re-adds
   CHIM's follow at 100; `ComeCloser` :557 is `FollowSoft` and nothing else; `ReleaseFromConversation` is only
   called from `EndConversation` :1641, which then runs `ResetPackages` (:54 clears the ref, :55 the flag).
   `MoveToTarget` / `TravelTo*` set the UNKEYWORDED linked ref and go through `ResetPackages` first, so the
   flag rule (chim != 1 -> ours off) covers them.
d. Latency (refuter 2 item 1): `TickGlueFollow` also looks at `watchActor` when she is not tracked (one
   StorageUtil read per 4 s while the toggle is on and somebody is watched; the MCM toggle itself is read
   at most every 30 s), so CHIM's own FollowPlayer is shadowed within ~4 s of landing. The OnUpdate hook is
   `if gfCount > 0 || watchActor != None`.
e. Ring full (refuter 1 item 4): the oldest slot is given up WITH `GlueFollowOff` and a log line; ours is only
   ever on a tracked NPC.
f. Log truthfulness (refuter 2 item 4): every "on" line sits behind `GlueFollowOn() == true`; stand-down,
   re-assert and off are logged on the change only, never per tick; `GlueFollowApply`'s fragment says `NOT on
   (LoreRimGlue.esp PACK 0x803 not found)`, `waits for CHIM's own move to end` or `off (bNaturalFollow)` when
   ours is not on.
g. An NPC out of sight (`!Is3DLoaded`): ours stays on for up to 120 s (she may be a door away), then comes off
   and the slot is freed; her next snapshot puts it back if CHIM's follow still runs.
h. The MCM help text uses the refuters' wording (four times the distance, LOS), not "fifteen metres / seven".
i. `CurrentVersion` 507 -> 508 and the two hook lines are in the orchestrator's patch (section 10); the new
   code compiles and is inert without them except on the escort path, which is fully wired inside the section.
j. Not touched, as planned: the server plugin (no wire change - `lrg_actions.php`'s escort skip at :2932 stays:
   a plain LLM FollowPlayer is shadowed game-side), `esp_dump.py`, `compile.ps1` (`stubs\Package.psc` and the
   SKSE64 `Actor.psc:187` / `ObjectReference.psc:275` declarations already cover `GetCurrentPackage` /
   `GetLinkedRef`), CHIM, OStim, LoreRim, the MO2 profile. `iSettingsVersion` stays 506 (only tested `> 0`).

## 10. Orchestrator patch - `LRG_Main.psc` outside the escort section, and PROTOCOL.md

Three anchored edits in `glue\game\LoreRimGlue\Source\Scripts\LRG_Main.psc` (line numbers as of this build):

1. Line 36: `int Property CurrentVersion = 507 AutoReadOnly` -> `= 508`.
2. `Event OnUpdate()`, the fan-out, right after the `TickEscort` block (`if escortActor != None ...
   TickEscort(Utility.GetCurrentRealTime()) endif`, line ~1323-1325) and before `LRG_OStim ost = GetOStim()`:
   ```
   	; [pt16] the natural follow: ours re-asserted over CHIM's, stood down for CHIM's own moves, and the
   	; watched NPC looked at until she is tracked. One or two field tests while nothing is on.
   	if gfCount > 0 || watchActor != None
   		TickGlueFollow(Utility.GetCurrentRealTime())
   	endif
   ```
3. `Function NoteFollower(Actor akNpc)` (line ~3431): as the FIRST statement, before
   `if akNpc == None || !FollowerAware()`:
   ```
   	NoteGlueFollow(akNpc) ; [pt16] before the throttle and before FollowerAware(): its own gate, two StorageUtil reads
   ```
   (It runs inside the snapshot's `SnapFolTry` rail like the rest of NoteFollower; every call it makes -
   StorageUtil, MCM Helper, ActorUtil, GetFormFromFile, GetLinkedRef, GetCurrentPackage, EvaluatePackage -
   is one the snapshot path already makes.)

`PROTOCOL.md` section 10.20, one paragraph to append at the end of the section (verbatim):

> **[script 508 / pt16] THE NATURAL FOLLOW - game only, nothing on the wire.** CHIM's own follow package
> (AIAgent.esp PACK 0x2226D) is a Follow procedure authored with Min 512 / Max 1024 units and Need LOS - four
> times the radii of Skyrim.esm's own follower packages (FollowPlayer 0x750BE: 128 / 256, no LOS rule) - which
> is the sprint-and-stop of playtest 16. Package inputs cannot be changed at runtime, so LoreRimGlue.esp now
> carries one PACK of its own, `LRG_FollowPackage` 0x803 (CHIM's record byte for byte, minus its VMAD and CTDA,
> with those three inputs patched), which the game lays OVER CHIM's follow at PapyrusUtil priority 100, where
> the last added override wins a tie: `LRG_Main.EscortFollow` puts it on with the escort, `NoteGlueFollow` (the
> snapshot path, before `NoteFollower`'s 20 s throttle) shadows a follow CHIM's own `FollowPlayer` put on, and
> a 4-second tick (`TickGlueFollow`, the saved 4-slot ring `gfQ`) puts ours back whenever CHIM re-adds its own,
> stands down while CHIM moves her itself (its MoveTarget linked ref is set: ComeCloser, walk-to-target) and
> takes ours off when `CHIM_FollowPlayerActive` is 0, when she becomes a teammate, or when the MCM toggle
> `bNaturalFollow:Followers` (default on) is off. CHIM stays the owner of `CHIM_FollowPlayerActive` and of its
> package; wait / release take ours off first. **Server -> game and game -> server: unchanged** - no key, no
> verb, no `ev`, no message type; the current server with script 508, and script 507 with any server,
> interoperate (507 simply keeps CHIM's radii). The `watch: FollowPlayer ... did not move` judge of 10.21
> becomes truthful by itself: with Max 256 she really closes the gap. StorageUtil key on the NPC, the glue's
> own: `LRG_GlueFollow`. Object id 0x803 is spent for good (a save may hold the override by form).

**[orchestrator, 2026-09-23 ~19:00]** The three hook edits named above as "the orchestrator's patch" (CurrentVersion 508, the TickGlueFollow call in OnUpdate, NoteGlueFollow at the top of NoteFollower) were applied by the follow reviewer inside the workflow; nothing remains to apply.
