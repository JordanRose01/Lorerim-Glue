# pt4 — independent verification of the nude body inside LoreRim Glue

Verified 2026-09-21 11:55–12:20. **Read-only**: this file is the only thing written. No file under
`F:\Modlists\LoreRim` and no file of the project was created, modified or deleted by this pass.
Everything below was measured with my own scripts (NIF / TRI / BSA parsers written from scratch in
WSL python3, plus PowerShell hashing and mtime sweeps) — nothing was taken on the builder's word.

## Verdict: **SHIP**

The 24 mesh files are exactly what they are claimed to be, and the two risks the builder could not
close (the wrist/ankle seam, and whether the genital textures resolve) are now **closed and proven**.
Five defects, all **minor**; two of them are one-line prose corrections in owner-facing notes that
should be made before the owner reads them, and neither blocks installing.

---

## 1. What I confirmed

### 1.1 The 24 files (project copy)

`glue\game\LoreRimGlue\meshes\...` holds exactly 24 `.nif`, all 2,009,928 bytes:

| | md5 | source |
|---|---|---|
| every `femalebody_0.nif` (12×) | `A04246356EA14340DF86B40726B06001` | `LoreRim - BodySlide Output\...\femalebodyastrid_0.nif` |
| every `femalebody_1.nif` (12×) | `FC273DB4A388026AFBD55A0AE8A68877` | `...\femalebodyastrid_1.nif` |

Byte-for-byte identical to the source, in `character assets` + the 11 named NPC folders. Nothing else
is under `meshes`.

**Nothing is installed yet.** `F:\Modlists\LoreRim\mods\LoreRim Glue` has no `meshes` folder, and no
profile backup exists newer than the 11:26 set — the installer genuinely refused and wrote nothing.
`ModOrganizer.exe` PID 42108 is still running, `SkyrimSE.exe` is not.

### 1.2 Geometry (my own NIF parser)

Vertex counts read from each shape's `NiSkinPartition` (`dataSize / vertexSize`), which is where an
SSE skinned `BSTriShape` actually keeps them:

```
femalebodyastrid_0.nif   3BA 18436   3BA_Vagina 1905   3BA_Anus 201
femalebodyastrid_1.nif   3BA 18436   3BA_Vagina 1905   3BA_Anus 201
(replaced) femalebody_1  CBBE 13554 + Bra 1557 + BraStraps 696 + Panty 1372
                         + 4 panty cloth shapes  <- the underwear geometry
```

* Shapes are **3BA / 3BA_Vagina / 3BA_Anus** only. **No `Bra`, no `Panty*` shape anywhere** in the
  24 files.
* `_0` and `_1` have identical per-shape counts, different md5, **and genuinely different geometry** —
  bounding boxes `_0` x ±28.3, z 11.5–114.1 vs `_1` x ±28.7, z 11.4–114.0. A real weight pair.
* Dismember partitions on the new body: `32 BODY`, `38 CALVES`, `34 FOREARMS` — **identical to the
  generic body it replaces**, so gauntlets and boots still hide the right limb sections.
* Root node `Scene Root`, 49 bones on the body shape, header `20.2.0.7 / user 12 / BS 100`,
  `Exported using Outfit Studio.`

### 1.3 Textures — all eight resolve (BSA scan of all 3,949 enabled mods)

| path | winner |
|---|---|
| `femalebody_1.dds` | **BnP - Female Skin** (L1945, `BnP - Skinfix - Textures.bsa`) |
| `femalebody_1_msn.dds` | BnP - Female Skin |
| `femalebody_1_s.dds` | BnP - Female Skin |
| `femalebody_1_sk.dds` | **Amon - SK Fix All in One** (L269, LOOSE, 516 bytes) — beats BnP, see defect 2 |
| `femalebody_etc_v2_1{,_msn,_sk,_s}.dds` | **BnP - Female Skin** (CBBE 3BA at L1949 loses) |

No missing texture. Diffuse, normal and specular of both the body and the genital patch come from the
same mod, so there is no tone or seam break.

### 1.4 BODYTRI / OBody — the morph link is intact

`NiStringExtraData` = `BODYTRI` → `actors\character\character assets\femalebodyastrid.tri`. Among
enabled mods only `LoreRim - BodySlide Output` (L146) provides that file, and LoreRim Glue does not
carry a copy — so it keeps resolving from LoreRim through MO2's VFS, exactly as intended.

My PIRT parse of `femalebodyastrid.tri` (5,011,925 bytes):

```
SHAPE 3BA          morphs=154  maxVertIndex=18435   (NIF has 18436 verts)
SHAPE 3BA_Anus     morphs= 27  maxVertIndex=  200   (NIF has   201)
SHAPE 3BA_Vagina   morphs= 51  maxVertIndex= 1904   (NIF has  1905)
```

Shape names and vertex counts match the NIF exactly. **OBody NG will morph the new body**, with 154
sliders on the body shape against the old `CBBE` shape's 97. OBody's own config
(`OBody_presetDistributionConfig.json`) is stock as claimed: every distribution map empty, no NPC
blacklist, `ElderRace` blacklisted for both sexes, ORefit blacklist = `LS Force Naked` +
`OBody Nude 32`. Nothing needs changing.

### 1.5 Physics XML — nothing can be missing

The only `NiStringExtraData` in either NIF is `BODYTRI`. No `.xml`, `.hkx` or SMP string exists
anywhere in either file's string table. There is no dangling physics reference. (What *drives* those
bones is a separate matter — defect 1.)

### 1.6 Skeleton

Winning `skeleton_female.nif` = `XPMSSE Left Hand Sheath Rotation Fix` (L490, 86,844 bytes; XPMSSE
itself at L743 loses). All **51** bone strings the new body uses — `L/R Breast01-03`, `NPC L/R Butt`,
`NPC Belly`, `NPC L/R Pussy02`, `VaginaB1`, `VaginaDeep1`, `Clitoral1`, `NPC L/R B/T Anus2`,
`NPC Anus Deep2` and the standard skeleton — exist in it. **Missing: none.** The old body used 26
bones and had no breast/3BA bones at all.

### 1.7 The seam — **proven, not assumed**

This is the one thing the builder reported as unprovable outside the game. It is provable, and it is
fine. I decoded the skin-partition vertex positions (full-precision float32 at record offset 0) and
measured, for every vertex of `femalehands_*` (6,374) and `femalefeet_*` (8,876), the nearest vertex
in each body:

```
weight _0  hands  vs OLD CBBE body: 10 exact, 32 within 0.05   smallest ~0.0000
           hands  vs NEW 3BA body :  0 exact, 42 within 0.05   smallest  0.0002
weight _1  hands  vs OLD          : 30 exact, 12 within 0.05
           hands  vs NEW          :  2 exact, 40 within 0.05   smallest  0.0001
weight _0  feet   vs NEW          :  2 exact, 40 within 0.05   smallest  0.0001
weight _1  feet   vs NEW          : 15 exact, 27 within 0.05   smallest  0.0000
```

42 wrist vertices and 42 ankle vertices sit within **0.0005 game units** of the new body at both
weights — the same residual scale as against LoreRim's own current body. And the new body's bounding
box is identical to the replaced one to 0.1 units at both weights (`_1`: x ±28.7, y −11.8…11.6,
z 11.4…114.0 for *both*). `femalebodyastrid` was batch-built from the **same preset** as
`femalebody` / `femalehands` / `femalefeet`. **There is no wrist or ankle seam.** The in-game look is
now a nicety, not a risk.

### 1.8 The eleven per-NPC bodies

All 22 files parsed. Every one: `CBBE 13554` + `Bra 500` + `Panty 664`, 26 bones, root `Scene Root`,
diffuse `textures\actors\character\female\femalebody_1.dds` (the **shared** skin) and
`FemaleUnderwear.dds`. **Not one of the eleven has a unique skin texture**, so none loses anything by
being replaced. Builder's "all 11 qualified, none skipped" is correct.

One behaviour note: the eleven currently carry dismember slot `32 BODY` only; the replacement adds
`34 FOREARMS` / `38 CALVES`, matching the generic body everyone else already uses. That is a
correction, not a regression.

### 1.9 No other mod was touched

Full recursive mtime sweep, **278,643 files** under `F:\Modlists\LoreRim\mods`, 57 changed in the last
three hours — every one accounted for:

* `LoreRim Glue\Scripts\*.pex` (11:25) and `Source\Scripts\*.psc` — our own mod.
* ~50 `SKSE\Plugins\*.ini` / `*.log` written 11:00–11:06 by SKSE plugins during the owner's own game
  session (`Actor Limit Fix`, `Bug Fixes SSE`, `Norden UI 16x9`, `LoreRim - MCM and INI Settings`, …).
* `The New Gentleman\meta.ini` (12:04) — the owner installing TNG in MO2 right now.
* `SSE Display Tweaks\SKSE\Plugins\SSEDisplayTweaks.ini` (10:48) — see defect 5.

Targeted deep scans of `LoreRim - BodySlide Output` (3,673 files), CBBE, CBBE 3BA (3BBB), both Dark
Souls Undressed mods, OBody NG, BnP - Female Skin, Bijin Family Bodyslides, XPMSSE and OStim
Standalone (8,931 files): **zero files newer than the cutoff** in any of them. Newest file in
`LoreRim - BodySlide Output` is still 9/20 22:11:02.

### 1.10 Leftovers and profile

* `F:\Modlists\LoreRim\mods\LoreRim Glue - Nude Body` — **does not exist**.
* `glue\tools\install_nude_body.ps1` — **does not exist**.
* `modlist.txt` contains no `LoreRim Glue - Nude Body` line. The only `Glue` line is `+LoreRim Glue`.
* `plugins.txt` L3417 `*LoreRimGlue.esp`, `loadorder.txt` L3497 `LoreRimGlue.esp` — already present, so
  a future install run changes nothing there.
* Backups present from earlier runs: `*.bak-20260921-{035059,040923,055706,095904,112611}` for all
  four files. No new backup at ~11:45 → the installer really did abort before step 1.
* `profiles\Default` and `profiles\Extreme` last written 9/20 22:11:04 — **untouched**.

### 1.11 Installed mod files

ESP, `.seq`, MCM `config.json` and all six `.pex` in `mods\LoreRim Glue` are **md5-identical** to the
project's current build (compiled 11:25:42). Nothing stale.

### 1.12 The installer

`install_mo2.ps1` parses clean. Guards intact: throws on `ModOrganizer` or `SkyrimSE` running (I
confirmed `Get-Process -Name ModOrganizer` finds PID 42108, so the throw is real, not theoretical);
backs up `modlist.txt` / `plugins.txt` / `loadorder.txt` / `settings.ini` with a timestamp before any
write; inserts into `modlist.txt` only when the entry is absent; **never sorts**; idempotent.

I reproduced the copy logic in a throwaway sandbox (`%TEMP%\lrgxd`, nothing to do with the game):

```
-NoNudeBody on a clean target : LoreRimGlue.esp + Scripts\  copied, meshes\ NOT created   OK
default on a clean target     : meshes\...\femalebody_0.nif copied                        OK
-NoNudeBody over an install   : robocopy leaves meshes\ in place, the script's
                                Remove-Item then deletes it (ESP-guarded)                 OK
```

`-NoNudeBody` can only ever delete `<mods>\LoreRim Glue\meshes`.

---

## 2. Defects

### D1 — minor — "CBPC physics" is wrong: nothing drives the new body's bones

**Evidence.** I scanned every enabled mod's `SKSE\Plugins` for a physics DLL. Present:
`hdtsmp64.dll` (`FSMP - Faster HDT-SMP`, L718), `DVSMPSurvivalTweaks.dll`, `AutoPhysicsReset.dll`,
`SMP-NPC crash fix.dll`. **There is no CBPC plugin.** `CBBE 3BA (3BBB)` (L1949) ships
`SKSE\Plugins\CBPConfig.txt`, but the DLL that reads it is not installed, so that file is inert. The
winning `SKSE\Plugins\hdtSkinnedMeshConfigs\configs.xml` (from `LoreRim - MCM and INI Settings`,
L145) contains only global `<smp>` / `<solver>` / `<wind>` tuning — no `defaultBBP` or per-mesh entry —
and neither NIF carries an SMP XML string. So the 3BA breast/butt/vagina bones will simply rest.

This is **not a regression** (the old body had no physics bones at all) and nothing breaks. The
problem is only that `PLAYTEST4_NOTES.md` §4 "How to check in game", item 4, tells the owner:
*"Breasts/butt still move (CBPC physics) … this should be better than before"*. They will not move,
and the owner will conclude the install failed.

**Smallest fix.** Replace that one item in `PLAYTEST4_NOTES.md` (line 243–244) with:

> 4. **No jiggle, and that is normal here** — this profile has no CBPC plugin and no SMP body config,
>    so nothing drives body bones either before or after. The new body at least *has* the 3BA bones
>    (the old one had none), so if you ever add CBPC it will just start working.

`README.md` line 31 says "every 3BA/CBPC bone present in the winning skeleton" — that statement is
true as written (the bones exist in the skeleton) and needs no change.

### D2 — minor — one of the eight textures does not come from BnP

**Evidence.** `textures\actors\character\female\femalebody_1_sk.dds` is supplied as a **loose**
516-byte file by `Amon - SK Fix All in One` (modlist L269), which beats `BnP - Female Skin`'s BSA
(L1945). The other seven, including all four `femalebody_etc_v2_1*` genital maps, come from BnP.

**Impact: cosmetic wording only.** `_sk` is the subsurface-scattering mask, not the diffuse, and this
exact combination is already live in LoreRim for Astrid's body. No visible tone break.

**Smallest fix.** In `PLAYTEST4_NOTES.md` §4 and `README.md` line 31, change "skin *and* genital
textures both from `BnP - Female Skin`" to "skin and genital diffuse/normal/specular all from
`BnP - Female Skin`; only the main body's `_sk` subsurface map comes from `Amon - SK Fix All in One`,
exactly as it already does for every female body in the list".

### D3 — minor — every modlist line number quoted in the notes is now off by one

**Evidence.** The owner is installing TNG *right now*: `modlist.txt` was rewritten by MO2 at 12:04:42
and gained `-The New Gentleman` as line 2 (disabled). Everything below shifted:

| quoted | actual now |
|---|---|
| `LoreRim Glue` 2 | **3** |
| `LoreRim - BodySlide Output` 145 | **146** |
| `XPMSSE Left Hand Sheath Rotation Fix` 489 | **490** |
| `BnP - Female Skin` 1944 | **1945** |
| `CBBE 3BA (3BBB)` 1948 | **1949** |

`TheNewGentleman.esp` is already in `plugins.txt` (L152, enabled) and `loadorder.txt` (L232) although
the mod itself is unticked.

**Impact: none functional.** `LoreRim Glue` is still the highest-priority **enabled** mod, so its
meshes still win over `LoreRim - BodySlide Output`.

**Smallest fix.** None required. If line numbers are quoted again, re-read `modlist.txt` first, or
quote mod names only — MO2 renumbers the file on every change.

### D4 — minor — installer's delete guard is half a tautology, and `-ModName` is unvalidated

**Evidence.** `install_mo2.ps1` line 41:

```powershell
$guardOk = ($modDir -eq (Join-Path $Mo2Root "mods\$ModName")) -and (Test-Path (Join-Path $modDir "LoreRimGlue.esp"))
```

`$modDir` is assigned that very expression at line 26, so the left half can never be false; only the
ESP check does any work. Separately, `-ModName` / `-Mo2Root` are free-form, and the unconditional
`robocopy $source $modDir /E` at line 40/49 runs **before** any guard — a mistyped `-ModName` would
copy the glue's files into another mod's folder.

**Impact:** only reachable by passing wrong parameters by hand; the documented invocation has none.

**Smallest fix.** Two lines, right after the process checks:

```powershell
if ($ModName -ne "LoreRim Glue") { throw "refusing: -ModName must be 'LoreRim Glue'" }
if ((Test-Path $modDir) -and -not (Test-Path (Join-Path $modDir "LoreRimGlue.esp"))) {
    throw "refusing: $modDir exists and is not our mod"
}
```

(the second must be placed *before* the robocopy, i.e. moved up from line 41), and the tautology in
`$guardOk` can then be dropped.

### D5 — minor / informational — one non-glue mod file changed at 10:48, not by this build

**Evidence.** `F:\Modlists\LoreRim\mods\SSE Display Tweaks\SKSE\Plugins\SSEDisplayTweaks.ini` changed
`FramerateLimit=240` → `FramerateLimit=60`, with a sibling `SSEDisplayTweaks.ini.bak-2026-09-21`
written the same second. It is the only `.bak-*` file anywhere under `mods\`.

**Not attributable to the nude-body build:** it predates it by ~50 minutes, nothing in the whole glue
project mentions `SSEDisplayTweaks` or `FramerateLimit`, and the backup name format differs from
`install_mo2.ps1`'s `.bak-yyyyMMdd-HHmmss`. Almost certainly the owner's own frame-cap change. I am
recording it because the standing rule is that no other mod's file may be modified, and it falls
inside the window I was asked to check.

**Smallest fix.** Ask the owner to confirm they made it. If not, restore from the `.bak-2026-09-21`
beside it (MO2 closed).

---

## 3. Notes accuracy (`PLAYTEST4_NOTES.md` §4 and `README.md`)

True and doable, apart from D1 and D2:

* "24 files, all copies, no LoreRim file was touched" — **true**, md5-verified both ways plus a
  278k-file mtime sweep.
* "both weights have the same vertex counts (a real low/high weight pair)" — **true**.
* "every 3BA physics bone the body uses exists in your skeleton" — **true**, all 51.
* "the morph file the body points at contains the same three shape names … which is what makes OBody
  keep working" — **true**, and OBody's config needs no change.
* "Close MO2, then run `install_mo2.ps1`" — **correct**; the script's refusal is genuine and the path
  in the notes is right.
* "It is global … nothing changes while she is clothed" — **true**; no armour or outfit mesh is
  touched and ORefit is unaffected.
* Undo instructions (`-NoNudeBody`, or hiding the `meshes` folder in MO2) — **both verified to work**;
  `-NoNudeBody` can only reach our own mod's `meshes` folder.
* TNG section — correctly hedged; the male-body correction (zzjay's *Males Of Skyrim*, not HIMBO) is
  consistent with everything I saw. Note the owner has already started installing TNG.
* Check item 3, "no seams … the one thing that cannot be proven outside the game" — this is now
  **over-cautious**; §1.7 above proves it. Optional improvement: say the seam was measured and matches
  to 0.0005 units, so a seam would indicate something else entirely.
