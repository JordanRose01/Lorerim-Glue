# pt19 - butt physics: "nothing moves, clothed or nude, even in scenes" (playtest 2026-09-24 02:41-03:26)

Investigator notes, read-only pass. Nothing under `glue\` or `F:\Modlists\LoreRim` was changed; this file is the
only thing written. Scratch tools and extracted copies are in `C:\Users\Jordan\AppData\Local\Temp\lrg_test\pt19physics\`
(`nifweights2.py` = NiSkinData weight reader, `bsalist.py` = BSA reader, `3ba_fomod\` = files extracted read-only from the
3BA installer zip, `dll_strings.txt` / `pdb_strings.txt` = cbp.dll / cbp.pdb string tables). Log and file contents are data.

Owner (verbatim): "i notices there really isnt any ass physics and ass wiggling, even during the sex".

## TL;DR

* **Nothing is broken.** The butt bones exist in the winning skeleton, the nude body and the townsfolk outfits are
  skinned to them, CBPC 1.7.2 drives them, no SMP config claims them, 3BA's physics-manager script never stops them,
  Auto Physics Reset does not touch them, and every config alias that can be bound to them is fully defined and alive.
  The glue's `CBPConfig_zzGlueDefaults.txt` did **not** replace 3BA's tuned butt values: it defines only CBPC's own
  alias `Butt`, which 3BA never defines, and no glue file touches 3BA's `LButt` / `RButt` springs.
* **The motion is real but far below the visible threshold, by arithmetic.** On a 3BA body the butt bones carry very
  light skin weights: `NPC L/R Butt` peak at **0.204** (509 vertices above 0.01) - identical on 3BA's own reference
  body, so it is the body's design, not a LoreRim build defect - while the breast chain peaks at 0.75 over three
  chained bones. LoreRim installed the quietest of 3BA's four butt options ("Elastic (Small jiggle)" = the stiffest
  spring preset at amplitude 0.5), pt8/pt15 only moved the amplitude (0.5 -> 0.8 -> 0.6) and never the springs. Skin
  travel = bone travel x weight x amplitude: with the Elastic spring the bone rarely reaches its clamp on a walk, so the
  cheek moves roughly 0.1-0.3 units (a few millimetres); even pinned at the -4.5 clamp it is 0.55 units. The breasts
  the owner does see move several units at the nipple. Clothed is identical: the dress meshes carry the same <= 0.22
  butt weights and CBPC has **no** clothed/armoured amplitude key for the butt (only `breast*Amplitude` exists).
* **Fix (builder):** ship 3BA's own softer butt preset - "Very Soft (Big jiggle)" from the installer, 100 keys, not
  invented - as a same-path override of `CBPConfig_butt.txt`, duplicated under CBPC's alias `Butt` so the result is the
  same whichever duplicate ConfigMap entry CBPC keeps; raise the three amplitude lines to 3BA's own "Normal" 1.0;
  remove the now-redundant `Butt.*` block from `zzGlueDefaults` (so no key is defined twice); document in
  `OVERRIDES.md`. Physical ceiling after the fix at the most-weighted vertex: ~0.9 units (1.3 cm) down, ~0.6 sideways, the
  average weighted cheek vertex ~0.45 units: should be perceptible on a fuller preset, not a cartoon wobble (builder
  rewording per the refuters). `cbpc reload` in the console applies a later dial edit without a restart.

## 1. Which CBPC files, aliases and springs govern the butt in this VFS

### 1.1 Providers and the winner

* `F:\Modlists\LoreRim\profiles\Ultra\modlist.txt` (4086 lines): `+LoreRim Glue` line 3, `+CBBE 3BA (3BBB)` 1949,
  `+CBPC - Physics with Collisions for SSE and VR` 1950, `+Auto Physics Reset` 3961, `+FSMP - Faster HDT-SMP` 718.
  Lower line wins.
* Full sweep (`find mods -maxdepth 4`) of `CBPConfig*` / `CBPCMasterConfig*` / `CBPCSystem*` / `CBPCollision*`
  providers: 3BA (25 files), CBPC (its own), LoreRim Glue (`CBPConfig_3b_armor`, `_BBP_armor`, `_BellyAmplitude`,
  `_BreastAmplitude`, `_ButtAmplitude`, `_zzGlueDefaults`, `CBPCSystem.ini`). No fourth mod. `overwrite\` has no
  `CBP*` / `AutoPhysics*` file. The four installed glue copies compared with `cmp` are SAME as the source tree.
* CBPC is 1.7.2 (`meta.ini` `version=1.7.2.0`; `Documents\...\SKSE\CBPC-Collision.log` written 03:19 tonight: "CBPC
  Physics SKSE Plugin: 1.7.2", System / Master / Extra Master `_3BA` `_Anal` `_SOSScroum` `_Vagina` "loaded
  successfully", "Loaded Bounce configs" with no per-file line, no error, one load block plus three save-load reload
  blocks).
* **Butt springs come from 3BA, untouched by the glue.** `CBBE 3BA (3BBB)\SKSE\Plugins\CBPConfig_butt.txt` (3108 B,
  122 lines, CRLF) defines `LButt.*` / `RButt.*`, 100 keys. No glue file defines any `LButt` / `RButt` key except
  `.amplitude` (grep across the six glue `CBPConfig_*.txt`). The glue's `CBPConfig_zzGlueDefaults.txt` lines 72-117
  define CBPC's alias `Butt.*` (43 keys over 46 lines, single-value form, copied from CBPC's own `CBPConfig.txt`, verified equal) -
  an alias 3BA never defines, so nothing of 3BA's was replaced.
* `CBPConfig_butt.txt` as installed is **byte-identical** (`cmp`) to the 3BA installer's
  `10 Physics Patch\77 CBPC M - BBL\SKSE\Plugins\CBPConfig_butt.txt`; the installer's `fomod\ModuleConfig.xml`
  (UTF-16, decoded) lines 828-868 map the "Butt Physics Preset" group: **"Very Soft (Big jiggle)" = 76 S-BBL + 78 Normal
  (amplitude 1.0)**, "Very Soft (Small jiggle)" = 76 + 79 Less (0.5), "Elastic (Big jiggle)" = 77 + 78, **"Elastic (Small
  jiggle)" = 77 M-BBL + 79 Less** - the one LoreRim chose (3BA `meta.ini` FOMOD record: Belly, Butt and Leg presets all
  "Elastic (Small jiggle)"; 3BA's shipped `CBPConfig_ButtAmplitude.txt` is 0.5 = the "Less" file). "BBL" = Belly, Butt,
  Leg. 3BA ships no `CBPCMasterConfig.txt` variant (the "CBPC ini file" group is three `CBPCSystem.ini` SkipFrames
  variants, LoreRim chose "Not install"), so the ConfigMap situation below is the same for every 3BA + CBPC user.

### 1.2 The duplicate ConfigMap claim (unchanged from pt8/pt15) - and why it no longer needs settling

* CBPC's `CBPCMasterConfig.txt` `[ConfigMap]`: `NPC L Butt=Butt=IsFemale()`, `NPC R Butt=Butt=IsFemale()` (also the
  bare line `NPC Pelvis [Pelv]`, a collider-only node). 3BA's `CBPCMasterConfig_3BA.txt`: `NPC L Butt=LButt=IsFemale()`,
  `NPC R Butt=RButt=IsFemale()`. Base file loads first, extras after (log order).
* `cbp.pdb` public symbol `?configMap@@3V?$concurrent_unordered_map@<std::string,std::string>...@Concurrency@@A`: the map
  is keyed by **node name** with one alias string per node, so exactly one of the two claims survives. The pdb holds
  public symbols only (`?loadMasterConfig@@YAXAEAV`, `?loadConfig@@YAXAEAV` exist; no member instantiations), so
  insert-vs-assign cannot be read without disassembly (no C++ tooling by rule). `cbp.dll`'s log templates ("Sending cbpc
  event for node:", "Refresh actor bounce for ", "Conditioned bounce config: ", "0 weight config") contain no
  "config X bound to node Y" line, so `Logging = 1` cannot settle it either (pt8's 710 MB log was never analysed and is
  gone: the file is 3440 B now).
* Consequence for the fix: **set the springs identically under `LButt`, `RButt` and `Butt`** - the same principle the
  glue already applies to `.amplitude`. Whichever alias CBPC binds, the outcome is the same.
* For the diagnosis it does not matter: both candidate blocks are complete and of the same stiffness class. 3BA
  Elastic `LButt`: stiffness 0.03, stiffness2 0.0082, damping 0.035, offsets X/Y +-3, Z +1.5/-4.5, timetick 1.5,
  linear 0.875/0.5/0.75, rotational 0.15/0.3/-+0.3, timeStep 1.2. CBPC default `Butt`: stiffness 0.03, damping 0.05,
  +-2.44, timetick 13.33, linear 1.017/0.667/0.644, timeStep 0.40. Neither is dead; neither produces visible travel with
  the weights in section 2.

### 1.3 Keys CBPC 1.7.2 actually recognises (cbp.dll string table, offsets 907824-909800)

`stiffness stiffnessX/Y/Z stiffnessX/Y/ZRot stiffness2 stiffness2X/Y/Z stiffness2X/Y/ZRot damping dampingX/Y/Z
dampingX/Y/ZRot maxoffset X/Y/Zmaxoffset X/Y/Zminoffset X/Y/ZmaxoffsetRot X/Y/ZminoffsetRot X/Y/Zdefaultoffset cogOffset
gravityBias gravityCorrection timetick timetickRot linearX/Y/Z rotationalX/Y/Z linear[XYZ]rotation[XYZ] timeStep
timeStepRot linear[XYZ]spreadforce[YZ..] rotation[XYZ]spreadforce[..] forceMultipler gravityInvertedCorrection(+Start/End)
breastClothedPushup breastLightArmoredPushup breastHeavyArmoredPushup breastClothedAmplitude breastLightArmoredAmplitude
breastHeavyArmoredAmplitude collisionFriction collisionPenetration collisionMultipler collisionMultiplerRot
collisionElastic collisionElasticConstraints collisionX/Y/Zmax/minoffset amplitude`

* Every key in 3BA's butt file is in this list. **There is no butt (or belly/leg) material key**: the only per-material
  volumes are the three `breast*Amplitude` (+ `*Pushup`) keys pt16 handled. A dressed woman's butt therefore runs at the
  plain `amplitude`, exactly like nude. Nothing to add for "clothed"; the material keywords `CBPCAsNaked/Clothing/
  Light/Heavy L/R` and `CBPCNoPushUp L/R` (strings at 907352-907496) only re-class the breast material.

## 2. Bones, meshes and weights - the actual cause

### 2.1 Skeleton (winner by modlist: `XPMSSE Left Hand Sheath Rotation Fix`, line 490, beats `XP32 ... Extended` at 743)

`meshes\actors\character\character assets female\skeleton_female.nif` (869 blocks): `NPC L Butt <- CME L PreButt <- NPC L
PreButt <- CME L PreButt0 <- NPC L PreButt0 <- CME L PreButtRoot <- Butt <- CME Pelvis [Pelv] <- NPC Pelvis [Pelv]` (mirror
for R; `NPC L/R ScaleButt` are RaceMenu scale helpers). Both driven nodes present, normal XPMSSE chain.

### 2.2 Nude body (the glue's `meshes\actors\character\character assets\femalebody_0/1.nif`, shape `3BA`, 18,436 vertices)

Weights read from `NiSkinData` (float weights, block size validated) with `nifweights2.py`:

| bone | vertices listed | > 0.01 | **max weight** | summed weight |
|---|---|---|---|---|
| `NPC L Butt` | 535 | 509 | **0.204** | 50.1 |
| `NPC R Butt` | 536 | 508 | **0.204** | 50.0 |
| `NPC L/R RearThigh` (lower cheek / hamstring, driven by the LEG config) | 524 / 549 | 513 / 530 | 0.162 / 0.161 | 47.4 |
| `NPC L/R FrontThigh` | 390 | 362 | 0.104 | 21.9 |
| `NPC Belly` | 468 | 463 | 0.183 | 54.0 |
| `L Breast01` / `02` / `03` (chain) | 2480 / 3671 / 2831 | 2214 / 3607 / 2738 | **0.367 / 0.535 / 0.752** | 335 / 761 / 1272 |
| `NPC Pelvis [Pelv]` | 5304 | 5166 | 0.998 | 2655 |

* **This is 3BA's design, not a LoreRim build defect.** 3BA's own reference `CBBE 3BA (3BBB)\CalienteTools\BodySlide\
  ShapeData\CBBE 3BA Reference\CBBE 3BA Ref.nif`: `NPC L Butt` 517 listed / 492 > 0.01 / max **0.204** / sum 47.6,
  RearThigh 0.162; `CBBE 3BA UniBody Ref\CBBE 3BA UniButt Ref.nif`: 0.204 as well. Stock CBBE (`Caliente's Beautiful
  Bodies Enhancer -CBBE-\...\ShapeData\CBBE\CBBE Body Physics.nif`): `NPC L Butt` max 0.257. The 3BA cheek is mostly
  pelvis/thigh with a 20 % butt-bone contribution, spread over ~500 vertices ("natural bone weights spread" on the Nexus
  page); the breast tip is 75 % Breast03 on top of 54 % Breast02 and 37 % Breast01, three bones each with its own +-2.3
  to +-2.76 clamp.
* The glue's copies are the unmodified `femalebodyastrid_0/1.nif` from `LoreRim - BodySlide Output` (pt8); no SMP
  string (only `BODYTRI`), nothing to rebuild - and reweighting the body would need Outfit Studio, which is out of bounds.

### 2.3 Clothed bodies (`LoreRim - BodySlide Output\meshes`)

* Bone presence: clothes `*_1.nif` 151 files, **39** carry `NPC L Butt` (38 carry `L Breast01`); armor 365 files, **55**
  carry `NPC L Butt` (69 carry `L Breast01`). Most armour meshes have no butt bone at all - no fix can make a cuirass
  wiggle; judge on dresses.
* Townsfolk dress meshes (cloth shape, not the embedded body): `merchantclothes\torsof_1.nif` `merchantdress03` 24 verts
  max 0.217; `fineclothes01\outfitf_1.nif` `Clothes` 24 / 0.203; `wench\wenchoutfitf_1.nif` `WenchTorso` 45 / 0.222;
  `beggarclothes\torsof_1.nif` `BaseClothes` + `BasePants` both weighted; `farmclothes03\farmerrobefplus_1.nif`
  `outfit_1` both sides; `minerclothes\minerclothesf_1.nif` has `NPC L Butt` but **no `NPC R Butt`** in its cloth shape
  (one-sided). Under the dresses the embedded `3BA` reference shape has its butt vertices zapped (0 listed) except the
  wench (318 / 0.204). So dressed = the same <= 0.22 weighting, at the same amplitude (1.3): one fix covers both.
* SMP: no outfit or body NIF references an `hdtSkinnedMeshConfigs\*.xml` except the five `armor\Mu3B\3BBBCollisionArmor*`
  helper meshes (3BA's collision-armour pieces, `3BCA-*-Amazing.xml` / `3BBB-Amazing.xml`); 3BA's `defaultBBPs.xml` maps
  male shapes and `ErinTail/ErinEar` only. `3BBB-Amazing.xml` does define `NPC L/R Butt` bodies (lines 416-466) but
  nothing on a female body loads it. SMP does not claim the butt bones.

### 2.4 Runtime actors that could stop the bones - all closed

* 3BA `Mus3BPhysicsManager` (source `scripts\source\mus3bphysicsmanager.psc`, 3,503 B, extracted from 3BA's `3BBB.bsa`;
  the compiled `scripts\mus3bphysicsmanager.pex`, 15,584 B, that actually runs is in 3BA's `RaceMenuMorphsCBBE.bsa` -
  both archives listed with `bsalist.py`, builder check, so `OVERRIDES.md` and this note are both right): `CBPCButts()` (line
  297) returns unless `ButtSMP`; `ButtSMP` comes from `StorageUtilData\CBBE 3BA\PhysicsManager.json` `SMP.Butt`, which is
  **0** in 3BA's shipped file and in the glue's override (pt8). The stop/reset path (`StopPhysics(NPC L/R Butt)` lines
  306-321) can never run here, whoever is on the save's `ActorPhysicsList`.
* No other loose `.pex` under `mods\*\Scripts` contains `StopPhysics` / `StartPhysics` (grep; only CBPC's own
  `CBPCPluginScript.pex`).
* `Auto Physics Reset` (`AutoPhysicsReset.dll` strings: `AutoSMPResetLogic::ExecuteForcefulReset`, "Forceful 3D reset for
  actor") performs a 3D reset on animation events; it has no CBPC config or node strings. The glue's ini only flips the
  two ScriptedScene triggers. Not a factor. `AutoPhysicsReset.log` tonight: loaded, `bEnabled=true`, nothing else (log off).
* CBPC `CBPCBounceinterpolationconfig_Test.txt` (breast amplitudes 0.0) is applied only by a Papyrus
  `ApplyBounceInterpolation` call; nothing calls it. `CBPCSystem.ini` is CBPC's own values (`ActorBounceDistance 2048`,
  `ActorAngle 360`, `SkipFramesPelvis 1` = collider update cadence). `MalePhysics=0` only disables the hard-coded female
  node list (cbp.dll 903168-903436: breasts, `NPC L/R Butt`, bellies, pussy, pelvis, anal, spine1) for **males**.
* OBody presets in play tonight (`OBody.log` 02:38-03:26, 'will be applied to'): `CBBE Vanilla` (Matlara 03:19, Ria,
  Skalei, Adrianne...), `CBBE SevenBase` (Hulda 02:53, Matlara 03:02), `LoreRim - Curvy Soft / Hourglass / Statuesque`
  (Rikke, Ysolda, Carlotta, Aela, Uthgerd...), WeelBones presets, `CBBE Athletic` / `Petite`. Butt sliders: Vanilla and
  SevenBase carry none (base CBBE shape), Curvy `Butt 80/50 BigButt 15`, Slim `Butt 50`, the glue's three `Butt 50-75,
  BigButt <= 15, AppleCheeks <= 30`, Athletic/Petite `ButtSmall 35-80`. Ordinary shapes; a small butt is not what hides
  it (the Curvy scene bodies showed nothing either).

### 2.5 The arithmetic

Skin travel at a vertex = bone travel x that vertex's butt weight x `amplitude`. Max butt weight 0.204.

| state | spring | amplitude | bone travel per walk step (est.) | skin travel at the peak vertex |
|---|---|---|---|---|
| LoreRim stock | Elastic (77 M) | 0.5 | ~1-2 u (stiff, timeStep 1.2 settles within a step) | 0.1-0.2 u (1-3 mm) |
| now (pt15) | Elastic | 0.6 | same | 0.12-0.25 u; hard ceiling at the -4.5 clamp 0.55 u |
| proposed | Very Soft (76 S) | 1.0 | reaches the +-3 / -4.5 clamps on a step and rings ~1 s (damping halved) | up to 0.92 u (1.3 cm) down, 0.61 u sideways |
| breasts today, for scale | Very Softness chain | 0.55 | three clamps of +-2.3..2.76 in series | ~2 u (3 cm) at the nipple - "a little too high" dressed (pt16) |

1 Skyrim unit = 1.43 cm. The proposed butt ceiling is about half the breast travel the owner already judged as slightly
much, i.e. "should be perceptible, not cartoon" (builder rewording per the refuters). More than this is physically unavailable from the butt bones on a 3BA body; the next
lever after that is the rear-thigh pair (section 4).

## 3. Proposal (builder; all paths under `glue\game\LoreRimGlue\SKSE\Plugins` unless noted)

| file | action | content |
|---|---|---|
| `CBPConfig_butt.txt` | **NEW**, same-path override of 3BA's | 3BA's own "Very Soft (Big jiggle)" preset verbatim for `LButt` / `RButt`: the installer's `10 Physics Patch\76 CBPC S - BBL\SKSE\Plugins\CBPConfig_butt.txt` (100 keys, extracted copy in `lrg_test\pt19physics\3ba_fomod\`). Versus the installed Elastic file exactly these values differ: `stiffnessX/Y/Z(+Rot)` 0.03 -> **0.015**, `stiffness2X/Y/Z(+Rot)` 0.0082 -> **0.0041**, `dampingX/Y/Z(+Rot)` 0.035 -> **0.0175**, `Zmax/ZminoffsetRot` +1.5/-4.5 -> **+3.0/-3.0**, `timetick` / `timetickRot` 1.5 -> **2.5**, `linearX/Y/Z` 0.875/0.5/0.75 -> **1.225/0.7/1.05**, `timeStep` / `timeStepRot` 1.2 -> **0.9**, `collisionMultipler` 1.0 -> **2.0**, `collisionMultiplerRot` 0.0 -> **0.2**. Unchanged: linear offsets X/Y +-3.0, Z +1.5/-4.5; `rotationalX 0.15`, `rotationalY 0.3`, `rotationalZ -0.3 (L) / +0.3 (R)`; collision offsets, `collisionFriction 0.1`, `collisionElastic 1.0`. **Plus the same 50 keys under `Butt`** (CBPC's alias for the same two nodes) with `Butt.rotationalZ 0.0 0.0` (one alias serves both sides, so the L/R sign cannot be mirrored; CBPC's own default is 0.0). `#` header: WHAT (nodes, both aliases, why both), WHY (the weight arithmetic in one line), HISTORY (stock = Elastic + 0.5; pt8 0.8; pt15 0.6; pt19 Very Soft + 1.0), DIAL (see below), FORMAT. |
| `CBPConfig_ButtAmplitude.txt` | EDIT values | the three lines `RButt` / `LButt` / `Butt` `.amplitude 0.6 0.6` -> **`1.0 1.0`** (= 3BA's own "Normal" file `78 CBPC BBL - Normal`). HISTORY line for pt19; DIAL text: 0.8 calmer, 1.0 = 3BA Normal; do not go above 1.0 (undocumented). |
| `CBPConfig_zzGlueDefaults.txt` | EDIT: delete lines 72-117 (the `# Butt - CBPC default parameters` block) and reword header lines 14-20 | The `Butt` alias is now fully defined in `CBPConfig_butt.txt`; leaving CBPC's legacy `Butt.stiffness` / `Butt.damping` / `Butt.timetick 13.33` here alongside the new per-axis `Butt.stiffnessX...` would define the same alias in two files with undocumented precedence (the key list in 1.3 has both spellings). Header now says: Breast.* copied verbatim from CBPC's file; HDTBelly.* from 3BA's belly file; **Butt.* moved to `CBPConfig_butt.txt` (pt19) because a gap-filler at CBPC's defaults would keep the butt stiff under the alias CBPC's own master config binds**. `Breast.*` and `HDTBelly.*` untouched. |
| `OVERRIDES.md` | EDIT | new row for `CBPConfig_butt.txt` (provenance 76 S-BBL = "Very Soft (Big jiggle)" vs installed 77 M-BBL = "Elastic (Small jiggle)", the 0.204 weight fact with the reference-body proof, both aliases, `rotationalZ 0.0` note, the "most armour has no butt bone" limit, format, dial); update the `CBPConfig_ButtAmplitude.txt` row (line 26) and the `zzGlueDefaults` row (line 28: Butt block moved, why). |
| `..\..\..\research\pt19-physics.md` (this file) | builder appends section 6 | what changed, verification run, refuter items. |

Do NOT touch: `CBPConfig_leg.txt` / `CBPConfig_LegAmplitude.txt` (section 4, owner's call), `CBPCollisionConfig_Female.txt`
(the `[NPC L/R Butt] : 0.5` colliders and affected-node lines are 3BA's, fine), `CBPCSystem.ini` (`FpsCorrection` stays
0 - one change at a time), `PhysicsManager.json`, `AutoPhysicsReset.ini`, `CBPConfig_3b_armor.txt` / `_BBP_armor.txt`
(no butt material key exists), any mesh (no BodySlide by rule; the weights are 3BA's own). Do not add a differently
named file for the same keys.

Format: the six existing glue `CBPConfig_*.txt` and `CBPCSystem.ini` are **LF, no BOM, ASCII** (CR count 0, first bytes
`23 20 4c`); only `AutoPhysicsReset.ini` is CRLF (86 CR in 104 lines - the original body); 3BA's and CBPC's own
`CBPConfig*` are CRLF. The lane text says "CRLF ... like the existing overrides"; that premise is false on disk for the
CBPConfig files, and pt16 already resolved the same point in favour of LF (its refuter 1 note). CBPC parses both: the LF
`CBPConfig_BreastAmplitude.txt` has changed the in-game result three times (pt8, pt15, pt16). Recommendation: LF / no BOM /
ASCII like the six siblings, stated in the header and in `OVERRIDES.md`; if the orchestrator insists on CRLF it works
too, but then say so in both places and keep the verification script's CR expectation consistent.

Builder verification (mirror pt16 section 7): both edited/new files no BOM, 0 tabs, every non-comment line matches
`alias.key v0 v100`; the `LButt`/`RButt` key set of the new file equals 3BA's original 100 keys (CR-stripped, sorted,
nothing missing/extra/duplicate); `Butt` gets exactly the `LButt` key set with `rotationalZ 0.0`; across all seven glue
`CBPConfig_*.txt` no `alias.key` is defined twice (so the zzGlueDefaults deletion is complete); installer file `76` hash
recorded in the header for provenance. No Papyrus, server, MCM or ESP change: nothing to compile, no PHP test applies.

## 4. Owner steps (one look, then the dial)

1. Nothing to type after the install: the orchestrator installs with the game closed and the next launch loads the files
   (CBPC reads `Data\SKSE\Plugins\CBPConfig*.txt` at start and on save load). For a LATER dial edit: open the console and
   type `cbpc reload` (cbp.dll: `cbpc <reload>` / "Reload CBPC Master ..." / "Reload CBPC system file") - no restart.
2. **Nude first** (cleanest): start any OStim scene with a woman on a fuller preset (any `LoreRim - Curvy *` or `CBBE
   Curvy` NPC - Ysolda, Carlotta, Rikke, Saadia, Aela tonight) and watch the cheeks from behind or the side: they
   should be perceptibly livelier on each thrust and settle within about a second (peak vertex at most 1.3 cm, average
   about 0.6 cm - a modest, not a cartoon, result; builder rewording per the refuters). Then **clothed**: a woman in a dress walking away
   from you - wench outfit (Hulda's barmaids), merchant or fine clothes (Carlotta, Ysolda) - a small soft sway of the
   cheeks at step rate. Do NOT judge on a guard, a soldier or anyone in a cuirass: 310 of the 365 built armour meshes
   have no butt bone at all, so nothing can move there; and not on miner clothes (right cheek unweighted).
3. Dial: `SKSE\Plugins\CBPConfig_ButtAmplitude.txt`, all three numbers together - 0.8 calmer, 1.0 (shipped) = 3BA's
   "Normal"; then `cbpc reload`. If the cheeks look shoved around in scenes rather than wobbling, `LButt/RButt/Butt.
   collisionMultipler` 2.0 -> 1.0 in `CBPConfig_butt.txt` (Elastic's value) is the second knob.
4. If after this the upper cheek moves but the lower cheek/hamstring looks glued on, say so: that is the LEG config
   (`NPC L/R RearThigh`, weight 0.162 on the lower cheek), still 3BA's Elastic + 0.5. The same recipe applies there (the
   installer's `76 CBPC S - BBL\...\CBPConfig_leg.txt` for `L/RRearThigh` + `CBPConfig_LegAmplitude.txt` 0.5 -> 0.8) -
   a separate, later change, not part of this fix (one variable at a time).

## 5. Open / risks

* Which duplicate ConfigMap entry CBPC 1.7.2 keeps (`Butt` vs `LButt`) is unknowable without disassembly and unloggable;
  the fix is alias-independent by construction. Only visible difference between the two outcomes: `rotationalZ` (+-0.3
  mirrored under 3BA's aliases, 0.0 under `Butt`).
* `amplitude` above 1.0 is undocumented (3BA's own ladders stop at 1.0; the 0..1 range CBPC documents belongs to its
  `ApplyBounceInterpolation(percentage)` Papyrus API, not to `amplitude`); not proposed - a conservative rule, not a
  CBPC limit (builder correction, refuter 2).
* 1.0 could read as too lively in scenes because the Very Soft preset doubles `collisionMultipler`; the two dials above
  are one edit each and `cbpc reload` applies them. Under-shoot is the likelier outcome given the 0.204 weights; the
  leg lever is the documented next step.
* `FpsCorrection = 0` (CBPC default, unchanged): bounce amount varies with frame rate (60 fps cap). Unchanged on purpose.
* `CLEAN_PLAYTHROUGH.md` section 4 line 111 (project root, outside `glue\`) still says "0.5 is calmer, 0.7 livelier" for
  the butt; pt16's builder edited that file, this run's rule limits edits to `glue\` - orchestrator item.
* Softer springs with `timetick 2.5` (vs 1.5) mean fewer sub-steps per frame: cheaper, not costlier. No Papyrus, no latency.

## 6. Implementation (builder, 2026-09-24 morning - resumed after a usage-limit interruption)

State found on resume (both refuters flagged it): the tree was half-applied. `CBPConfig_ButtAmplitude.txt` was already
`1.0` (04:51), `CBPConfig_zzGlueDefaults.txt` already had the `Butt.*` block removed and its header reworded (04:54),
`OVERRIDES.md` rows 26 / 27 / 29 / 38 were already written - but `CBPConfig_butt.txt` did not exist and this section was
missing. Backups of the four pre-pt19 files were already in `glue\.backup\pt19-physics\` (taken 04:46, before the first
edit: `CBPConfig_ButtAmplitude.txt.bak` = the pt15 953 B file, `CBPConfig_zzGlueDefaults.txt.bak` = 6453 B with the
`Butt.*` block, `OVERRIDES.md.bak` = pt16 state, `pt19-physics.md.bak` = the investigator's text) and were left as they
are; the new file has no predecessor to back up. The MO2-installed copies (`mods\LoreRim Glue\SKSE\Plugins`: amplitude
953 B / 0.6, zzGlueDefaults 6453 B, no `CBPConfig_butt.txt`) are still the pt15 state, so the owner's game was never
exposed to the half-applied tree. Nothing was installed or deployed by this pass.

| file | change |
|---|---|
| `SKSE\Plugins\CBPConfig_butt.txt` | **NEW** (12,869 B, 291 lines as built; 13,220 B, 294 lines after the reviewer's FORMAT / DIAL rewording - the two-value order and CBPC's own amplitude ladder, see the R2 bullet below). Same-path override of 3BA's file. A 100-line `#` header (lines 1-100, `# START Butt` at 102) (WHAT / WHY / the nine changed value groups and, explicitly, what is NOT changed / WHAT TO EXPECT / HISTORY / DIAL / FORMAT), then the installer's `76 CBPC S - BBL` "Very Soft" file byte for byte after CR removal (the segment `# START Butt` .. `#Tuning.rate 60`, `cmp` SAME; 100 `LButt` / `RButt` keys), then a `Butt alias` block: the same 50 keys as `LButt` at the same values, `Butt.rotationalZ 0.0 0.0`. Both sha256 hashes (installer 76, installed 77) quoted in the header. |
| `SKSE\Plugins\CBPConfig_ButtAmplitude.txt` | values were already `1.0 1.0` x3 from the interrupted run (= 3BA's `78 CBPC BBL - Normal` file, verified below). This pass reworded two header statements (refuter 2): the two-value order is no longer called "CBPC's syntax / weight 100 first" but "CBPC's weight-0 / weight-100 pair, order undocumented in any shipped file, identical here"; "CBPC interpolates the volume over 0..1" replaced by "a conservative rule from 3BA's own ladders; CBPC above 1.0 undocumented". |
| `SKSE\Plugins\CBPConfig_zzGlueDefaults.txt` | unchanged this pass (the interrupted run had already removed the `Butt.*` block and reworded the header). Verified: 0 `Butt.*` lines, `Breast.*` and `HDTBelly.*` blocks intact, no `.amplitude` line. |
| `OVERRIDES.md` | row 26: two-value order and "never above 1.0" reworded (refuter 2). Row 27: the BSA names reconciled (compiled `.pex` in `RaceMenuMorphsCBBE.bsa`, source `.psc` in `3BBB.bsa` - both true, different files); "the LINEAR clamps are identical in 76 and 77, only the rotational Z clamps and the spring / damping / force numbers change, the ceiling rises only via amplitude" added (refuter 1); the stock-`Butt` history corrected (stock had `Butt.amplitude 0.5` from 3BA's own amplitude file and no spring key, not "no definition at all"); the honest expectation numbers (peak 0.92 u, mean weighted vertex about 0.45 u, combined physics-bone weight per cheek vertex at most 0.255, leg lever at most about a quarter more, "should be perceptible", not "visible wobble"); the `collisionMultipler` scope (self colliders excluded, so it shows against another actor or the actor's own hands); "not above 1.0" labelled a conservative rule; the `cbp.dll` reload help text quoted as the three string fragments it really is. |
| this file | 2.4 BSA reconciliation, section 5 amplitude bullet (the 0..1 range is `ApplyBounceInterpolation`'s, not `amplitude`'s), TL;DR and owner step 2 expectation wording, this section. |

Verification run on the source tree (script `C:\Users\Jordan\AppData\Local\Temp\lrg_test\physics\verify_butt.sh`, Git Bash,
2026-09-24, result `ALL CHECKS PASSED`):

* Format: `CBPConfig_butt.txt`, `CBPConfig_ButtAmplitude.txt`, `CBPConfig_zzGlueDefaults.txt` all CR = 0 (LF), first bytes
  `23 20 4c` (no BOM), 0 non-ASCII bytes, 0 tabs, last byte `0a`; every non-comment non-blank line matches
  `alias.key v0 [v100]` (0 non-conforming lines in each). Longest line in the new file 99 characters.
* Verbatim: the segment `# START Butt` .. `#Tuning.rate 60` of the new file `cmp`-equals the CR-stripped installer 76 file
  (sha256 `7a636a76...`; installed 3BA 77 file `3b751f80...`), plus the trailing LF the original lacks.
* Key sets (first token, sorted): `LButt`/`RButt` 100 = installer 76's 100 = installed 77's 100, no duplicate; `Butt` 50 =
  `LButt` 50, no duplicate; value diff `Butt` vs `LButt` is exactly the one expected line (`rotationalZ -0.3 -0.3` vs
  `0.0 0.0`).
* Across all SEVEN glue `CBPConfig_*.txt` (`_3b_armor`, `_BBP_armor`, `_BellyAmplitude`, `_BreastAmplitude`,
  `_ButtAmplitude`, `_butt`, `_zzGlueDefaults`) no `alias.key` is defined twice; `zzGlueDefaults` has 0 `Butt.*` lines;
  `CBPConfig_butt.txt` has no `.amplitude` value line (two header mentions only).
* `CBPConfig_ButtAmplitude.txt` value lines == 3BA's `78 CBPC BBL - Normal` file (CR-stripped): the same three lines.
* VFS side: the only 3BA files defining any butt alias key are `CBPConfig_butt.txt` and `CBPConfig_ButtAmplitude.txt`,
  both same-path overridden by the glue; CBPC's `CBPConfig.txt` (`Butt.*`) is shadowed by 3BA's 13-byte same-path file
  and CBPC's `CBPConfig_ButtAmplitude.txt` by the glue's - so in the merged VFS every butt `alias.key` exists exactly once.
  (Script cosmetic: its per-file loop word-splits the two mod paths that contain spaces, so the `CBBE:` / `3BA:` /
  `CBPC:` ... lines in its output are path fragments, not files; the three `YES` / `yes` lines are the answers.)
* Line endings for the record: all seven glue `CBPConfig_*.txt` and `CBPCSystem.ini` CR = 0.
* BSA check (`bsalist.py` in WSL): `3BBB.bsa` (27 files) holds `scripts\source\mus3bphysicsmanager.psc` 3,503 B;
  `RaceMenuMorphsCBBE.bsa` (22 files) holds `scripts\mus3bphysicsmanager.pex` 15,584 B.
* `cbp.dll` bytes at 916488 read `ConfigMap[%s] = %s = %s` (row 27's statement stands); `CBPCollisionConfig.txt` line 37
  defines `@` = "self node" in an exclusion list, which is the wording used for the `collisionMultipler` note.
* No Papyrus, server, MCM or ESP change: nothing to compile, no PHP test applies (as in pt16).

Refuter items and how they were honoured:

* R1 + R2 "create `CBPConfig_butt.txt` now, before anything is installed": done (above); nothing installed.
* R1 "say explicitly that only the ROTATIONAL Z clamps change, the linear clamps are identical, and only amplitude
  raises the ceiling 0.55 -> 0.92 u": header section "WHAT THE VERY SOFT PRESET CHANGES" + row 27.
* R1 "honest expectation numbers, say 'perceptible' not 'will visibly wobble'": header "WHAT TO EXPECT", row 27,
  section 4 step 2, TL;DR. The mean-vertex figure follows from the investigator's own table (summed weight 50.1 over
  509 vertices = 0.098, x 4.5 clamp x 1.0 = 0.44 u); the 0.255 combined-weight figure is the refuter's NiSkinPartition
  read and is quoted as such.
* R1 "LF, no BOM, ASCII; never describe the glue's file as CRLF; verification expects CR = 0": done.
* R1 + R2 "'never above 1.0' is a conservative rule from 3BA's ladders, not CBPC documentation": done in the header,
  the amplitude file and both OVERRIDES rows.
* R1 + R2 "`collisionMultipler` 2.0 matters only against other actors": stated with CBPC's own syntax definition
  (`@ColliderName` = excluded SELF collider): `NPC L/R Butt` exclude their own butt, thigh, genital and breast
  colliders, so a walking actor's own body never pushes the cheeks; another actor's colliders, and the actor's own
  unlisted ones (hands), still do - hence "OStim scenes" in the dial text.
* R2 "two-value order is not documented": reworded everywhere by the builder; CBPC's collision configs' "0 and 100
  weight settings" note is for their pipe-separated collider syntax, and `cbp.dll`'s `0 weight config` strings do not
  fix the order. **Reviewer correction (pt19 review):** the order IS documented, just not in a file inside the CBPC
  archive. CBPC's Nexus description (cached verbatim in `mods\CBPC - Physics with Collisions for SSE and VR\meta.ini`,
  `nexusDescription`) gives `Breast.stiffness 0.05 0.08` as "both 100 (first one) and 0 (second one) weight bounce
  settings", a single value serving both weights; and CBPC's own installer (`downloads\CBPC - Fomod installer - MAIN
  FILE 21224 1.7.2 ....7z`, `00 Data\amplitude1-5`, `2_4`, `2_5`, `3_5`) ships `amplitude 1.0 0.3` / `0.75 0.45` /
  `0.75 0.3` / `0.6 0.3` - full at weight 100, less on flat actors. So the builder's ORIGINAL "weight 100 first"
  wording was right; `CBPConfig_butt.txt`, `CBPConfig_ButtAmplitude.txt` and `OVERRIDES.md` row 26 now say "weight-100
  / weight-0, in that order", and pt16's `CBPConfig_3b_armor.txt` FORMAT line, which had the order reversed, was
  corrected in the same review pass. Every glue line has both values equal, so nothing in-game changes either way. The
  same CBPC ladder also corroborates "not above 1.0": its `amplitude1` .. `amplitude5` variants run 1.0 down to 0.3.
* R2 "reconcile the BSA name": both names were true of different files; both places now say which is which.
* R2 "do not install the partial state": not installed, not deployed (rule).
* Orchestrator items (outside `glue\`): `CLEAN_PLAYTHROUGH.md` line 100 ("butt from 0.8 to **0.6**") and line 111
  ("0.5 is calmer, 0.7 livelier") are stale once this ships - exact patch in the builder's report.
