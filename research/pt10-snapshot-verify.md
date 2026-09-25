# pt10 verification + install - LoreRim Glue 502

Independent check of `research/pt10-snapshot-fix.md`, then the real install.
Written 2026-09-22 18:40 local. MO2 and Skyrim were **closed** for the whole of this pass.

**Verdict: the fix is sound and is now installed.** The root cause is confirmed to the point the
fixer's own "honest statement of confidence" claims (an abort inside `MaybeSnapshot`), but **one
leg of the fixer's evidence does not hold** (section 2), and that same weakness lines up with a
**blind spot in the new rail** (section 3, defect D1). Neither blocks the playtest.

---

## 1. What was confirmed independently, from the code

Everything below was reproduced at the exact lines, from the **installed v501 sources**
(`F:\Modlists\LoreRim\mods\LoreRim Glue\Source\Scripts\`, i.e. what actually ran in playtest 10)
and the project's v502 sources. Nothing here is taken from the fixer's report.

**1.1 The abort was inside `MaybeSnapshot` - CONFIRMED, and this is the load-bearing claim.**
In the *installed v501* `LRG_Main.psc`, `OnChimSpeechStarted` reads:

```
	MaybeSnapshot(npc, false)
	Watch(npc, true)
	NoteConvLine(npc, now, false)
	LatStarted(npc, now)
	NoteSpeechToDriver(1, now)
	if ost
		ost.NoteActivity(npc)
		ost.NotePartnerSpeech(npc, true, now)
	endif
```

`Watch()` is called **unconditionally** - its return value is not tested. So a `MaybeSnapshot` that
merely *returned false* would still have set `watchActor`, and `watchActor` is the only thing
`SnapTick` needs to log `initiative tick`. Zero `initiative tick`, zero `conv hold`, zero `LAT npc=`
lines in a 30-minute session therefore cannot be explained by an early return. Only an unwound
stack explains it. `OnChimTextReceived` and `OnCrosshairRefChange` have the same shape.

**1.2 Why the second Skyrim process logged nothing at all - CONFIRMED.**
In installed v501 `Maintenance()`, the order is `MaybeSnapshot(look, true)` (forced, on the
crosshair target) -> `FollowerSweep()` -> `Log("maintenance done, version ...")`. The proof-of-life
line really did sit behind the two riskiest calls in the function. v502 moves the `Log` ahead of
both. Verified in both files.

**1.3 The SFF half really was live in the build that was played - CONFIRMED.**
Installed `Source\Scripts\LRG_Followers.psc:59` is
`PO3_SKSEFunctions.GetFormFromEditorID("SFF_CanRecruitMore")` - the underscore spelling that
resolves. So `SffPresent()` was true and `SFF_SKSE.IsVanillaFollower()` / `GetMaxFollowers()` were
being called for the first time ever, on every snapshot.

**1.4 The stage stamp will actually discriminate SFF - CONFIRMED.**
`WitnessScan`'s `witchim` half only calls `LRG_Followers.FacCurrentFollower()`
(`Game.GetForm(0x0005C84E)`) - **no** SFF native. Every SFF native is reached only through
`FolState` -> `Framework`/`IsSff`/`SffBlock`, which is stage **7**. So a `stage 7` abort line really
does mean the SFF block, and a `stage 3` line means something else (see D5).

**1.5 `LRG_Followers` needs no quest - CONFIRMED.** `Scriptname LRG_Followers Hidden`, and a grep
for any function or event *not* declared `Global` returns nothing. It has no member variables, so
there is nothing to instantiate. Deploying `LRG_Followers.pex` is the whole requirement, and
`esp_dump.py` correctly does not show it on either quest.

**1.6 The server-side second bug - CONFIRMED verbatim.**
`lib/lrg_core.php:1221-1223`: `no_fresh_snapshot` is in the list that returns `'silent'`.
`lib/lrg_actions.php:1120`: `if (in_array($mode, ['closed', 'public', 'private', 'follow'], true) && ...)`
- `silent` is absent, so `lrgRecogniseIntent()` is never called on a silent turn. `intent=none` for
"kiss me" / "come hug me" / "take your clothes off" is the recogniser **never being called**, not
failing. Still unfixed, still server lane.

**1.7 The fix cannot itself abort - CHECKED line by line.**
* `LRG_Profile.BuildSnapshot`: `LRG.GetMain()` is `Game.GetFormFromFile(...) as LRG_Main` - returns
  `None`, never throws - and **every** `m.` call is inside an `if m` (lines 452-573, 585-588,
  595-604, 618, 622) or an `if m && ...`. No unguarded member call.
* `LRG_Followers.SffBlock`: `GvFollowerCount()` behind `if gc`, `GvCanRecruit()` behind `if gr`,
  `FollowerQuest()` behind `if fq`, the `DialogueFollowerScript` cast behind `if dfs`, `used` and
  `slots` range-checked, `""` returned when `!akNpc || !SffPresent()`. The only ungardable calls
  left are the two SKSE natives themselves, which is the point of the rail.
* `SnapStage` / `SnapDoorOk` / `SnapDoorTry` / `SnapFolOk` / `SnapFolTry` / `SnapStageName` /
  `SnapReportAbort`: member reads/writes, string concatenation and one `Log()` call
  (`Log` -> `LogC`, no latent call). Nothing native, nothing that can be `None`.
* **Latent calls**: the only `Utility.Wait` on this path is `LRG_Main.psc:1899`, inside
  `MaybeSnapshot`'s forced branch, and `if !abForce / return false` sits above it - so no wait ever
  runs inside a CHIM speech event, which is what the comment at :1189 already claims. The new code
  adds no latent call anywhere. `tools\compile.ps1` would have refused a latent call in an illegal
  context in any case.

**1.8 Build.** `tools\compile.ps1` ->
`OK - .pex files copied to game\LoreRimGlue\Scripts (only the glue's own scripts); compiler reported 0 errors, 0 warnings`,
2 rounds, 22 auto-stubs. The compiled `LRG_Main.pex` contains the new strings
(`SNAPSHOT ABORTED in `, `the door block`, `the follower block (fol=)`); the previously installed
`.pex` contained none of them, so the two builds are distinguishable by inspection.

---

## 2. One leg of the fixer's evidence does NOT hold

**`pt10-snapshot-fix.md` section 1.3 is not valid.** It argues that the save file proves
`PlaceFacts()` ran to completion and therefore that `BuildSnapshot` was entered. The three member
strings it read out of Save15 are **byte-identical to the values the script declares as defaults**:

```
LRG_Main.psc (v502 project)      installed v501 Source\Scripts\LRG_Main.psc
 92: string lcFacts = "loc=;ltype=wild"           72: (identical)
 98: string pcFacts = "cellown=none;home=0;nhome="  78: (identical)
 99: string pcSde   = "sde=-1"                      79: (identical)
```

A save holding those strings is exactly as consistent with **`PlaceFacts` never having run once**.
So the save evidence distinguishes nothing, and the conclusion "MaybeSnapshot got past
`getAgentByName`, BuildSnapshot was entered" is **not established**.

What *is* established is 1.1: the stack was unwound somewhere inside `MaybeSnapshot`. That is
enough for the fix to be the right fix - but it means the abort could equally be in the stretch of
`MaybeSnapshot` that the new rail does **not** watch, which is defect D1 below. The elimination
argument for the SFF block (1.4 in the fixer's report) still stands on its own ground - it is the
only new cross-mod code on the path - but it is now the *only* support for that identification.

---

## 3. Defects found in the fix (none blocking; listed most useful first)

**D1 - blind spot between `snapBusy = true` and the first stage stamp.**
`LRG_Main.psc:1918` takes `snapBusy`; `:1947` writes the first `snapWhere = 1`. Between them sit
`IsEnabled()`, `akNpc.IsDead()`, `HasKeywordString`, `GetDistance`, **`AIAgentFunctions.getAgentByName(akNpc.GetDisplayName())`** (CHIM), `GetOStim()` and `ost.IsActorInOurScene(akNpc)`.
An abort in that stretch leaves `snapBusy` true with `snapWhere == 0`, so the new detector's
`if snapWhere > 0` never fires: **no abort line, no retirement**, and every later non-forced call
returns false for 30 s, clears the flag, and aborts again in the same place. Combined with section
2, this is precisely the stretch that has *not* been ruled out.
*Fix, one line, no risk:* move (or duplicate) `snapWhere = 1` to immediately after `snapBusy = true`.
The detector requires `snapBusy` to still be true, and every early return below already clears
`snapBusy`, so a stale `snapWhere` can never produce a false positive.

**D2 - "the very next event gets a full snapshot" is optimistic.**
The aborted attempt already armed `lastSnapActor` / `lastSnapTime` / `lastAnySnapTime`
(`:1938-1940`) before it died. After the abort is detected and the block retired, the same NPC is
still throttled for **10 s** (any NPC: 2 s) unless the call is forced. Expect a ~10 s gap between
the `SNAPSHOT ABORTED` line and the first good snapshot, not an instant one.

**D3 - `NoteFollower()` is still unbracketed and still inside the CHIM event.**
`:1957`, after the send and after `snapBusy` is released. It calls `FollowerAware()` and
`LRG_Followers.IsGhost()`. An abort there would still take the caller's `Watch()` and
`NoteConvLine()` with it, and the rail cannot see it (`snapWhere` is already 0). Low risk - it ran
all day on 21 Sep - but it is the one remaining unbracketed cross-script call in the speech path.
Consider moving it onto the tick.

**D4 - `FollowerSweep()` is unbracketed.** It calls `PO3_SKSEFunctions.GetActorsByProcessingLevel(0)`
and `LRG_Followers.IsGhost()`. It no longer hides the version line (that moved in front of it), so
an abort there is now *observable* - but it would still cost the load-time crosshair snapshot that
follows it in `Maintenance()`, with no line naming it.

**D5 - two stage names are misleading.**
*Stage 1* is stamped before the call, and Papyrus evaluates arguments at the call site, so stage 1
covers `LRG_Main.PlaceFacts()` as well as the head of `BuildSnapshot` - a "stage 1" line should not
be read as "the actor facts" alone.
*Stage 3*'s `SnapFolTry` bracket is over-broad: the witness scan's follower half touches no SFF
native (1.4), so an abort at stage 3 would retire `fol=` for the session without `fol=` being to
blame. Harmless, but a stage-3 line does **not** implicate SFF.

---

## 4. Environment defect found and fixed: Papyrus logging was not reliably on

`F:\Modlists\LoreRim\profiles\Ultra\Skyrim.ini` (the ini MO2 really uses - the profile's
`settings.ini` has `LocalSettings=true`) had the right values, but the 17:50 edit that set them
**rewrote the entire file with LF-only line endings**: 5061 -> 4842 bytes, exactly 219 CR bytes
removed, while `skyrimprefs.ini`, `skyrimcustom.ini` and the untouched backup are all CRLF.

* Restored to CRLF. The file is now **5061 bytes again** and differs from
  `Skyrim.ini.bak-20260922-175053` in exactly three lines: `bEnableLogging`, `bEnableTrace`,
  `bLoadDebugInformation`, all `0 -> 1`. Backup of the LF version:
  `Skyrim.ini.bak-lffix-20260922-183716`.
* Belt and braces: `C:\Users\Jordan\Documents\My Games\Skyrim Special Edition\Skyrim.ini` had the
  same three keys at 0 and is now at 1 (same size, same line endings, backup `*.bak-lrg-*`). It is
  not the ini MO2 feeds the game, but it costs nothing and removes the ambiguity.
* `Documents\My Games\Skyrim Special Edition\Logs\` does **not exist yet** - confirming Papyrus
  logging has never once been on in this setup. It will be created on the next launch.

---

## 5. The install (full path, MO2 and Skyrim closed)

`tools\install_mo2.ps1` ran end to end and printed `Done.` - no refusal.
(Its process exit code is `3`, which is **robocopy's** "files copied / extra files present", not an
error; the script's own `$ErrorActionPreference = "Stop"` never tripped.)

| step | result |
|---|---|
| process check | no `ModOrganizer`, no `SkyrimSE`, no `skse64_loader` running |
| profile backups | `modlist.txt` `plugins.txt` `loadorder.txt` `settings.ini` -> `*.bak-20260922-183726` |
| mod files | robocopy into `F:\Modlists\LoreRim\mods\LoreRim Glue`; 24 mesh files |
| `meta.ini` | `version=0.1.0` -> **`version=0.5.1`** (from `server\lorerim_glue\manifest.json`) - this had never been refreshed, exactly as predicted |
| `modlist.txt` | `+LoreRim Glue` already present, left as is - 1 occurrence, enabled |
| `plugins.txt` | `*LoreRimGlue.esp` already present, line 3418 of 3418 (last) - 1 occurrence |
| `loadorder.txt` | `LoreRimGlue.esp` already present, line 3497 of 3497 (last) - 1 occurrence |

**Hash comparison, source vs installed** (SHA-256, every file, recursive):
**61 source files, 61 installed byte-identical, 0 missing, 0 different.** The only file in the mod
folder that is not in the source tree is `meta.ini`, which the installer generates. `Seq\LoreRimGlue.seq`,
`LoreRimGlue.esp`, `MCM\Config\LoreRimGlue\config.json` and `settings.ini` are all present and match.
Installed `.pex` are dated 18:36 today; installed `Source\Scripts\LRG_Main.psc:23` reads
`int Property CurrentVersion = 502 AutoReadOnly`; installed `Scripts\LRG_Main.pex` contains
`SNAPSHOT ABORTED in ` and `Scripts\LRG_Followers.pex` contains `SffBlock`.

**`tools\esp_dump.py` against the INSTALLED `LoreRimGlue.esp`** (683 bytes, 3 records, `nextObjectId=00000802`):

```
QUST 01000800  EDID LRG_MainQuest
  VMAD scripts=['LRG_Main', 'LRG_OStim', 'LRG_Dialogue', 'LRG_DlgProbe']
       alias 0 (PlayerAlias, ALFR=00000014) scripts=['LRG_PlayerAlias']
QUST 01000801  EDID LRG_MCMQuest
  VMAD scripts=['LRG_MCM']   alias 0 (PlayerRef) scripts=['SKI_PlayerLoadGameAlias']
```

Correct and unchanged. `LRG_Followers` is deliberately absent (see 1.5).

*One observation, not a fault:* `modlist.txt` now has `+The New Gentleman` on the line above
`+LoreRim Glue`, so the glue is one slot lower in MO2 priority than when it was first installed.
No file in either mod overlaps the glue's own files, so nothing is overridden.

---

## 6. What the owner does next

1. Start MO2 (profile **Ultra**), start the game, load a save.
2. **Proof the new build loaded** - in `lorerim_glue.log`:
   `GAME maintenance done, version 502, save <n>, session <n>`
   If it still says **501**, the new `.pex` did not load and nothing below is meaningful.
3. **THE line that proves snapshots flow again** - talk to any CHIM NPC and look at the server's
   gate line. Playtest 10 read:
   `gate npc=Lisette mode=silent ... reasons=no_fresh_snapshot aff=0 interest=-(0) ... profile=-`
   It must now read:
   **`gate npc=<name> mode=private`** (or `public` / `closed` / `follow`) **` ... reasons= ... profile=<word>`**
   The decisive part is `profile=` no longer being `-` and `reasons=` no longer containing
   `no_fresh_snapshot`. That one line is the whole fix.
4. If a block still dies, **one** line names it, at most six per session:
   `GAME SNAPSHOT ABORTED in the follower block (fol=) (stage 7): fol= / witchim are off for this session`
   - and normal traffic from the next event onwards (~10 s later, see D2). Send that line.
5. **Log paths**
   * server: `\\wsl.localhost\DwemerAI4Skyrim3\var\www\html\HerikaServer\log\lorerim_glue.log`
   * **Papyrus (now on, send this one if it still fails):**
     `C:\Users\Jordan\Documents\My Games\Skyrim Special Edition\Logs\Script\Papyrus.0.log`
     It will name the exact function and line, which turns "by elimination it is the SFF block"
     into a fact - and, because of D1, it is also the only thing that can identify an abort in the
     unwatched stretch before stage 1.
6. Still expected to be wrong until the **server** lane fixes it (1.6): `intent=` stays `none` on any
   turn the gate calls `silent`. Once snapshots flow the gate should not be silent, so this should
   stop being visible - but it is not fixed.
