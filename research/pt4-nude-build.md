# pt4 - nude female body, built into LoreRim Glue itself

Build date 2026-09-21. Builder role. Nothing of LoreRim, CBBE, 3BA, OStim, OBody or any other mod was
modified, moved or deleted - the only reads were read-only parses, the only writes are inside the glue
project. BodySlide, Nemesis and LOOT were **not** run; MO2 and the game were **not** launched.

Owner decision honoured: **the nude body is part of the "LoreRim Glue" mod, not a second mod.**

---

## 0. Summary

| | |
|---|---|
| What was added | 24 mesh files (12 folders x `femalebody_0.nif` + `femalebody_1.nif`) inside `glue\game\LoreRimGlue\meshes\` |
| Where they came from | byte-for-byte copies of LoreRim's own **nude** `femalebodyastrid_0/1.nif` |
| Off switch | `install_mo2.ps1 -NoNudeBody` (installs everything else and deletes an already-installed `meshes` folder) |
| Installed? | **No - `install_mo2.ps1` refused: Mod Organizer is running.** Exact command for the owner in section 5 |
| OBody | will morph the new body normally (proved in 1.3) |
| Males | no file work; TNG's public description was **not readable** (section 6) |

---

## 1. Verification of the shortcut (before using it)

Read-only NIF/TRI parses of
`F:\Modlists\LoreRim\mods\LoreRim - BodySlide Output\meshes\actors\character\character assets\`.

### 1.1 Astrid's pair is a complete, correct nude 3BA body

```
femalebodyastrid_0.nif / _1.nif   2,009,928 bytes each, NIF bsver 100, root NiNode "Scene Root"
  shape 3BA          verts 18436   bones 49   skeletonRoot = #0 Scene Root
  shape 3BA_Vagina   verts  1905   bones  8
  shape 3BA_Anus     verts   201   bones  6
  md5 _0 A04246356EA14340DF86B40726B06001   md5 _1 FC273DB4A388026AFBD55A0AE8A68877
```

* **_0 and _1 have identical per-shape vertex counts** (18436 / 1905 / 201) and identical block
  layout; the files differ (different md5) only in vertex positions, i.e. a proper low-weight /
  high-weight pair. Weight sliders will work.
* The shape bone lists contain the 3BA/CBPC physics bones - `L Breast01..03`, `R Breast01..03`,
  `NPC L/R Butt`, `NPC Belly`, `NPC L/R Pussy02`, `VaginaB1`, `VaginaDeep1`, `Clitoral1`,
  `NPC LT/RT/LB/RB Anus2`, `NPC Anus Deep2`. **Every one of those bones exists in the winning
  skeleton**, `F:\Modlists\LoreRim\mods\XPMSSE Left Hand Sheath Rotation Fix\meshes\actors\character\character assets female\skeleton_female.nif`
  (modlist line 489; XP32 Maximum Skeleton Special Extended at line 742 is the loser). So the body
  deforms and gets CBPC physics instead of being welded to the root.
* There is **no HDT-SMP / physics-XML string data in the file at all**: the only `NiStringExtraData`
  in either NIF is `BODYTRI`. Nothing can point at a missing XML. Physics for this body is bone-driven
  (CBPC), exactly as for every other 3BA mesh in the list.
* Textures per shape (`BSShaderTextureSet`, shader type 5 = Skin Tint):

  | shape | diffuse / normal / sk / specular | winning provider |
  |---|---|---|
  | `3BA` | `textures\actors\character\female\femalebody_1{,_msn,_sk,_s}.dds` | **`BnP - Female Skin`** (line 1944, `BnP - Skinfix - Textures.bsa`) |
  | `3BA_Vagina`, `3BA_Anus` | `textures\actors\character\female\femalebody_etc_v2_1{,_msn,_sk,_s}.dds` | **`BnP - Female Skin`** (line 1944) - also present in `CBBE 3BA (3BBB)` (line 1948, loses) |

  All eight `.dds` exist and come from the **same** enabled skin mod, so body and genital textures
  match in tone - no seam or colour break. (Enabled-BSA scan, Ultra profile order.)

### 1.2 Compared with the NeverNude body it replaces

```
femalebody_0/1.nif (LoreRim, current)   8 shapes
  CBBE  verts 13554  bones 26  tex textures\actors\character\female\femalebody_1.dds
  Bra / BraStraps / Panty / Panty_FrontCloth / Panty_BackCloth / Panty_SideCloth / Panty_Straps
        tex textures\cptnjtk\dsunderwear\underwear.dds   + 7 x NiAlphaProperty
  BODYTRI = actors\character\character assets\femalebody.tri
  skeleton root "Scene Root"; NO breast/butt/belly/vagina bones at all
```

Same convention on both sides: root node `Scene Root`, `BSDismemberSkinInstance`, `skeletonRoot = #0`,
one `BODYTRI` string. The replacement is structurally the same kind of file, with the underwear
geometry gone, +23 bones and the 3BA physics/genital shapes added.

### 1.3 BODYTRI and OBody - the important one

`femalebodyastrid_0/1.nif` carry
`NiStringExtraData NAME='BODYTRI' VALUE='actors\character\character assets\femalebodyastrid.tri'`.

That file exists: `...\LoreRim - BodySlide Output\meshes\actors\character\character assets\femalebodyastrid.tri`
(5,011,925 bytes, enabled mod, modlist line 145). Parsed (BodySlide `PIRT` morph format):

```
numShapes = 3
  shape '3BA'         154 morphs   AnkleSize, AppleCheeks, Arms, Back, BackArch, Belly, BigBelly, BigButt, BigTorso, BreastCenter, ...
  shape '3BA_Anus'     27 morphs   AppleCheeks, BigButt, Butt, ButtClassic, ButtCrack, ...
  shape '3BA_Vagina'   51 morphs   AppleCheeks, BigBelly, BigButt, Butt, ButtClassic, ...
```

**The shape names inside the .tri are exactly the shape names inside the NIF** (`3BA`, `3BA_Vagina`,
`3BA_Anus`), and the morph names are the ordinary BodySlide slider names that OBody/RaceMenu drive.
=> **OBody NG will morph the new body**, and with more sliders than before (154 vs the old CBBE
shape's 97 in `femalebody.tri`).

The reference is *kept as it is* and **no `.tri` is copied into our mod**: `femalebodyastrid.tri` keeps
resolving from `LoreRim - BodySlide Output` through MO2's VFS (our mod overrides only the two `.nif`
per folder; every other file in that folder still comes from LoreRim). Nothing is broken, nothing is
duplicated. LoreRim's own `femalebody.tri` stays untouched and simply stops being referenced.

### 1.4 The eleven per-NPC bodies

All 22 files (`meshes\actors\character\<Name>\femalebody_0/1.nif`) parsed. They are identical to each
other in every respect that matters:

```
root "Scene Root", 3 shapes:  CBBE 13554 verts / 26 bones  +  Bra 500 / Panty 664
CBBE tex0   = textures\actors\character\female\femalebody_1.dds       <- the SHARED skin, no per-NPC skin
Bra/Panty   = textures\actors\character\female\FemaleUnderwear.dds
BODYTRI     = actors\character\<Name>\femalebody.tri                  <- per-NPC tri
```

Shared skin textures + standard skeleton + standard root => **a straight copy is correct for all
eleven.** After the copy their bodies reference `femalebodyastrid.tri` instead of their own
`<Name>\femalebody.tri`; that file exists, so morphs keep working and their own `.tri` is simply
unused (left in place, untouched).

### 1.5 Verdict

A straight copy of Astrid's pair as `femalebody_0/1.nif` **is** a correct nude replacement for every
female NPC and the player: same body family as LoreRim's own first-person body (`1stpersonfemalebody`
is shape `3BA`) and as every 3BA armour refit in the list, textures resolve from the winning skin mod,
skeleton has every bone, morph link intact, weight pair intact.

Residual risk (cannot be proven without launching the game, which the rules forbid): the base mesh of
`femalebodyastrid` was built by LoreRim's author in the same prebuilt BodySlide-output archive as
`femalehands_*`/`femalefeet_*` (one batch build, one preset - `meta.ini` shows a single prebuilt Nexus
archive, and all of those files were extracted in the same minute), so wrist/ankle rings should line
up. **The in-game check is "no neck / wrist / ankle seam"**, which is in the owner notes.

---

## 2. What was added to the mod (copies only)

Source for every file: `...\LoreRim - BodySlide Output\meshes\actors\character\character assets\femalebodyastrid_0.nif`
and `femalebodyastrid_1.nif` (unchanged, byte-for-byte; md5 of the copies verified equal to the
source: `A042...6001` / `FC27...8877`).

Written under `...\scratch-2026-09-21-8ca12a\glue\game\LoreRimGlue\meshes\actors\character\`:

```
character assets\femalebody_0.nif   character assets\femalebody_1.nif      <- the generic body (global)
Bijin NPCs\        Bijin Warmaidens\   Bijin Wives\   Chaconne\   Elisif\
Minazuki\          Serana\             Succubus-San\  Toccata\    Valerica\   Vivace\
        ... each with femalebody_0.nif + femalebody_1.nif
```

24 files, 2,009,928 bytes each (~48 MB). **None skipped** - all eleven per-NPC folders qualified under
1.4. Nothing for males, nothing for hands/feet/first-person (they are already nude and already match).

Because "LoreRim Glue" is line 2 of `profiles\Ultra\modlist.txt` (= bottom of MO2's left pane = highest
priority), these win over `LoreRim - BodySlide Output` (line 145). MO2 will show the expected conflict
flag on both mods.

---

## 3. Off switch - `install_mo2.ps1 -NoNudeBody`

`glue\tools\install_mo2.ps1` (the only installer; the leftover `install_nude_body.ps1` never existed on
disk) gained a `[switch]$NoNudeBody`:

* default (no switch): the whole `game\LoreRimGlue` tree including `meshes` is robocopied, and the
  script prints how many mesh files it installed;
* with `-NoNudeBody`: `robocopy ... /XD <source>\meshes` so the meshes are never copied, **and** an
  already-installed `F:\Modlists\LoreRim\mods\LoreRim Glue\meshes` is deleted - guarded by
  `$modDir -eq "<Mo2Root>\mods\<ModName>"` **and** `LoreRimGlue.esp` being present in it, so it can
  only ever delete our own folder;
* the pre-flight "missing file" check now also demands `meshes\...\femalebody_0.nif` unless
  `-NoNudeBody` is given.

Every existing guard is intact and untouched: refuses while `ModOrganizer.exe` or `SkyrimSE.exe` runs,
timestamped backups of `modlist.txt` / `plugins.txt` / `loadorder.txt` / `settings.ini` before anything
else, inserts the modlist line only if absent, appends the plugin only if absent, **never sorts**,
re-runnable.

---

## 4. OBody NG 4.4.3

Read: `F:\Modlists\LoreRim\mods\OBody Next Generation\SKSE\Plugins\OBody_presetDistributionConfig.json`
(854 bytes - the mod ships no `.ini`; no other enabled mod overrides that JSON). Nothing was edited.

Current content, in full: every distribution map (`npcFormID`, `npc`, `factionFemale`, `factionMale`,
`npcPluginFemale`, `npcPluginMale`, `raceFemale`, `raceMale`) is **empty**; `blacklistedNpcs`,
`blacklistedNpcsFormID`, `blacklistedNpcsPlugin*` are **empty**; `blacklistedRacesFemale/Male` =
`["ElderRace"]`; `blacklistedOutfitsFromORefit` = `["LS Force Naked", "OBody Nude 32"]`;
`outfitsForceRefit` empty; `blacklistedPresetsFromRandomDistribution` = the three "Zeroed Sliders"
spellings + `HIMBO Zero for OBody`; `blacklistedPresetsShowInOBodyMenu` true.

Answers:

* **Does OBody need anything for the new body?** No. OBody drives RaceMenu BodyMorph by slider name;
  it never swaps meshes. The link it needs is the NIF's `BODYTRI` -> a `.tri` whose shape names match,
  which is proved in 1.3. Nothing to add, nothing to change. (This is also why OBody never removed the
  bra and panties: it cannot add or delete shapes.)
* **Does ORefit care?** No. ORefit applies *clothed* refit morphs to outfits so armour follows the
  morphed body; it is driven by the same morph system and by the outfit blacklists above. Changing the
  base body mesh changes nothing for it. Both blacklisted "outfits" are nude-forcing helpers and are
  unrelated to the glue.
* **Lisette (`FDE Lisette.esp`)?** Not blacklisted, not pinned - she gets a random preset like every
  other female. Nothing needs to change. *Optional suggestion only (owner's call, do not let an agent
  edit OBody):* if he ever wants her body fixed instead of random, the stock way is an entry under
  `"npc": { "Lisette": ["<preset name>"] }` in that JSON.
* **The eleven custom-body NPCs?** No reason to blacklist them. Their old bodies used the shared skin
  and the same base build, so nothing individual is lost by the copy, and OBody keeps varying their
  shapes exactly as before. *Optional suggestion only:* blacklisting a name (e.g. `Serana`) under
  `blacklistedNpcs` would freeze her at the base mesh - i.e. make her *less* varied, not more.

---

## 5. Install - NOT DONE, MO2 is running

```
PS> powershell -NoProfile -ExecutionPolicy Bypass -File "...\glue\tools\install_mo2.ps1"
Mod Organizer is running. Close it first - profile files must not be edited while MO2 is open.
```

`ModOrganizer.exe` PID 42108 was up, so the script's own guard refused and **nothing was written to
`F:\Modlists\LoreRim`** - no backups, no mod files, no profile edits. The installed mod folder
`F:\Modlists\LoreRim\mods\LoreRim Glue` still holds only `LoreRimGlue.esp`, `MCM`, `Scripts`, `Seq`,
`Source`, `meta.ini`; `meshes` is **not** there yet. No workaround was attempted.

**Owner: close Mod Organizer, then run exactly this one line (it also installs the crash-fixed `.pex`
from the previous round if they are not in yet):**

```
powershell -NoProfile -ExecutionPolicy Bypass -File "C:\Users\Jordan\AppData\Roaming\Claude\scratch-workspaces\81ce68fa-07b1-4b77-bf43-737182b06d3c\6e558c34-6aca-475c-b527-d967c1b190e9\scratch-2026-09-21-8ca12a\glue\tools\install_mo2.ps1"
```

and, to undo the body later, the same line with ` -NoNudeBody` appended.

### Leftover clean-up from the stopped run - nothing to clean

Checked, all three negative, so nothing was moved or deleted:

* `F:\Modlists\LoreRim\mods\LoreRim Glue - Nude Body` - **does not exist** (the only "Nude" folders in
  `mods\` are LoreRim's own `Bijin Family Bodyslides - CBBE NeverNude`, `Dark Souls Undressed -
  NeverNude`, `Dark Souls Undressed - NeverNude - CBBE`, which were not touched);
* `glue\tools\install_nude_body.ps1` - **does not exist** (`glue\tools\` holds only `flows`, `stubs`,
  `compile.ps1`, `deploy_server.ps1`, `esp_dump.py`, `install_mo2.ps1`, `make_esp.py`,
  `test_gates.php`, `test_scene_index.php`, `warm_index.php`);
* `profiles\Ultra\modlist.txt` - the only matching line is `2: +LoreRim Glue`. No
  `+LoreRim Glue - Nude Body` line, so no profile file was edited (and none could have been - MO2 is
  open).

---

## 6. Males - TNG (owner steps), and one correction

**Correction to the premise: LoreRim's male body is not HIMBO.** Verified now, not assumed: no HIMBO
*body* mod is enabled - the ten enabled matches for `himbo` in `modlist.txt` are armour/outfit refits
(lines 865, 1540, 1541, 1550, 1554, 1562, 1589, 1735 ...). The winning
`meshes\actors\character\character assets\malebody_1.nif` comes from **`Males Of Skyrim by zzjay - with
Better Male Feet and High poly hands - SE`** (line 1945, from that mod's BSA). LoreRim therefore has
HIMBO *armour* refits on a zzjay male body - whatever TNG option the owner picks must match **that**
body, not HIMBO.

**TNG's public description could not be read.** `https://www.nexusmods.com/skyrimspecialedition/mods/104215`
returned **HTTP 403** to the fetch tool, and so did the Schaken-Mods mirror of the same description.
The only readable public source, the source repo `github.com/ModiLogist/TheNewGentleman`
(`README.md`), is a developer readme: it confirms TNG is **an SKSE plugin built with CommonLibSSE-NG**
and lists only build requirements (CMake, vcpkg, Visual Studio, CommonLibSSE-NG, simpleini). It says
nothing about FOMOD options, requirements, Nemesis, BodySlide or load order.

So, rather than guess:

* **What to download (name only):** *The New Gentleman* (TNG) for Skyrim Special Edition - the main
  file from its Nexus page, plus, if he wants it, *The New Gentleman - SoS Keyword Patch*. There is
  also a *SFW Edition* and a *CHS* (Chinese) page - neither is the one wanted here.
* **Installer options:** **unknown - read them on the page.** Do not let anybody (including an agent)
  pick blind. What he is choosing *for* is verified above: a **zzjay "Males of Skyrim"** male body with
  **HIMBO armour refits**; if the installer offers "vanilla-shaped / SOS-shaped / HIMBO" body options
  he wants the one matching a vanilla-proportioned high-poly body, and if it offers to install its own
  male body he must accept that it will override zzjay's `malebody_*.nif`.
* **MO2 placement:** left pane - install it and drag it to the **bottom** of the left pane (= highest
  priority; it may sit above or below `LoreRim Glue`, they never touch the same files: ours are
  `femalebody_*`, TNG's are male). Right pane - let its plugin sit **at the end** of the load order,
  after `LoreRimGlue.esp`; **do not sort, do not run LOOT.**
* **Nemesis:** **not verified.** TNG being an SKSE plugin makes behaviour files unlikely, but the page
  is the authority - if its description or installer mentions FNIS/Nemesis, believe it over this note.
* Useful local facts either way: `OStim.log` already prints `TNG is not installed` and
  `SoS full is not installed`, i.e. **OStim 7.5.1 detects TNG natively** - no patch or glue change is
  needed on our side; and a stripped male today shows a smooth, featureless crotch because nothing in
  the list supplies genital geometry (pt3 section 5.5).

Sources used for this section: [Nexus - The New Gentleman (403, unreadable)](https://www.nexusmods.com/skyrimspecialedition/mods/104215),
[GitHub - ModiLogist/TheNewGentleman README](https://github.com/ModiLogist/TheNewGentleman/blob/master/README.md),
[Nexus - The New Gentleman SFW Edition](https://www.nexusmods.com/skyrimspecialedition/mods/143823),
[Nexus - The New Gentleman - SoS Keyword Patch](https://www.nexusmods.com/skyrimspecialedition/mods/111245).

---

## 7. Files changed by this run

| file | what |
|---|---|
| `glue\game\LoreRimGlue\meshes\actors\character\**\femalebody_0.nif`, `femalebody_1.nif` | new, 24 files, copies of LoreRim's nude Astrid body |
| `glue\tools\install_mo2.ps1` | `-NoNudeBody` switch + mesh pre-flight check + two extra output lines |
| `glue\README.md` | one paragraph: the body is part of this mod, it is global, how to undo |
| `PLAYTEST4_NOTES.md` | appended section "Nude body (females) and males" (supersedes old steps 4-9) |
| `research\pt4-nude-build.md` | this file |

Nothing under `F:\Modlists\LoreRim` was written. Read-only helper scripts used for the parses live in
`%TEMP%` (`lrg_nif2.py`, `lrg_brief.py`, `lrg_bsa.py`, `lrg_who.py`, `lrg_skel.py`, `lrg_tri2.py`,
`lrg_nif3.py`, `lrg_nif4.py`).
