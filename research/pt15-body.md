# pt15: body lane (invisible nipples, physics too strong)

2026-09-23. Skyrim was not running and MO2 was. The files were hand-copied into
`F:\Modlists\LoreRim\mods\LoreRim Glue` and checked with SHA-256. No profile file, no other mod
and no plugin was touched.

## TL;DR

* **Nipples: not a texture problem, so no texture was shipped.** The cause is **OBody NG's
  "ORefit"**. When an NPC wears clothes, OBody adds a set of body morphs called `OClothe`. With
  "ORefit nipple morphing" on, which is OBody.esp's default, that set includes **`NipBGone 1.0`**
  (the 3BA slider that deletes the nipple), plus `AreolaSize -0.3` and `NipplePerkManga -0.25`.
  When OStim undressed Lisette, OBody never noticed, so she went into the scene still carrying
  her "clothed" morphs. **Fix: one MCM toggle (owner step O1).**
* **Physics toned down.** Breast amplitude went from 1.0 to **0.55** on all 12 aliases. Butt went
  from 0.8 to **0.6** on 3 aliases. Belly stays **0.4**. The spring values (stiffness and
  damping) were checked and left alone; §2 has the numbers.

---

## 1. Nipples

### 1.1 Texture chain: which mod wins

The glue's nude body is `meshes\actors\character\character assets\femalebody_1.nif`, shape `3BA`
(18,436 vertices). Its `BSShaderTextureSet` points at
`textures\actors\character\female\femalebody_1.dds / _msn / _sk / _s`. The genital shapes point
at `femalebody_etc_v2_1*`.

I scanned all 785 BSAs and every loose file in the enabled mods:

| file | providers (winner first) |
|---|---|
| `femalebody_1.dds` | **BnP - Female Skin** (`BnP - Skinfix - Textures.bsa`, 4096² BC7). Loses: `Cleaned Skyrim SE Textures` (vanilla) |
| `femalebody_1_msn.dds` | **BnP**. Loses: vanilla |
| `femalebody_1_s.dds` | **BnP**. Loses: vanilla |
| `femalebody_1_sk.dds` | **Amon - SK Fix All in One** (loose, 16×16 solid black, which turns subsurface scattering off). Loses: BnP, vanilla |
| `femalebody_etc_v2_1{,_msn,_s,_sk}.dds` | **BnP**. Loses: CBBE 3BA |

BnP's installer choices (from `meta.ini`): body "Frostnip", "Big boobs / Soft", "Custom vagina:
Pale pink", "Esp fix". **BnP offers no censored or no-nipple option, and none was picked.** Its
`.esp` only fixes texture sets for the hands and head.

### 1.2 The diffuse and normal maps have nipples, and the UVs line up

* I decoded the BnP diffuse. It has painted areolas at 4k pixel ≈ (1740, 2865) and (2350, 2865).
  They are only subtly darker and redder than the skin around them: RGB 94/66/64 against
  98/88/83.
* The model-space normal map `_msn` has sculpted nipples at the same spot. (I saved a crop of
  the chest region.)
* I plotted all 18,436 UVs of the `3BA` shape over the diffuse. The dense cluster of nipple
  vertices sits exactly on the painted areolas. The mesh and texture use the same CBBE layout.

### 1.3 No texture set swaps in a nipple-less skin

The engine can swap a body's textures through the skin record (`ARMA` → `NAM1`). I parsed the
winning `ARMA`, `ARMO`, `TXST` and `RACE` records of all 3,496 active plugins:

* Lisette (`Skyrim.esm:013297`) wears `NPC Appearances Merged.esp:000801 SkinNakedPatched`. It
  uses the vanilla `NakedTorso` record with texture set `SkinBodyFemale_1`, which points at
  `Actors\Character\Female\FemaleBody_1.dds`, i.e. the BnP texture above.
* In total I checked 63 distinct body diffuse textures reached through `NAM1`. The 43 adult
  human/elf ones all have painted areolas in the CBBE layout; six have smaller, paler ones
  (Auri, AnimaNera, Wolven Widow, Tilael, Vigilant, the DB initiates). I checked these by eye.
  **No NeverNude or smooth texture set is assigned anywhere**, so no plugin override is needed.
* Side note, not nipples: five texture-set paths resolved to no file in my scan. They are
  Valerica replacer `BodyTEX`, three Growl `HRI_Alternate_Texture_SkinBody_*` and
  `AAA_GirlBodyTexture` from `Vannysa_Frea_replacer.esp`. I did not investigate them.

### 1.4 The actual cause: OBody ORefit morphs

* OBody.esp's quest properties, read from its `VMAD`, are: `ORefitEnabled = true`,
  `NippleSlidersORefitEnabled = true`, `NippleRandEnabled = false`, `PerformanceMode = true`.
* OBody's own MCM help text says: *"If you get sunken or weird nipples/areolas with ORefit, turn
  off this option."*
* I parsed RaceMenu's body-morph data out of the last co-save (`Save15 … .skse`, SKEE
  `STTB`/`MRPH`/`MRDT` chunks). **132 of the 134 actors with morphs carry the `OClothe` key.**
  Every one of them has `NipBGone = 1.0`, and most also have `AreolaSize -0.3`,
  `NipplePerkManga -0.25`, `BreastCleavage 1.0` and `BreastsTogether ≈0.3`. In other words, a
  push-up-bra shape with the nipples deleted.
* `OStim.log` shows the scene with Lisette ran from 04:25:32 to 04:27:22. `OBody.log` has **no
  line for Lisette at all**, and nothing between 04:24:30 and 04:27:26. During ordinary outfit
  changes OBody logs "Removing clothed preset…" for other NPCs, but it did not do so when OStim
  undressed her. So she went nude with her `OClothe` morphs still applied. That matches both
  complaints: invisible nipples, and breasts that looked unnaturally shaped.

**Fix = owner step O1** (an MCM toggle). **Follow-up for the game lane:** the robust fix is in
script. At OStim scene start, call `OBodyNative.RemoveClothesOverlay(actor)` for each actor. At
scene end, after OStim redresses them, call `OBodyNative.AddClothesOverlay(actor)`. Both are
OBody NG 4.4.3 natives (`OBodyNative.psc`). That would keep ORefit for clothed NPCs and still
give correct nude bodies in scenes. It is not done here; it is outside this lane's files.

---

## 2. Physics

### 2.1 What drives the motion

* **Breasts.** The body is skinned to `L/R Breast01-03`, which 3BA's `CBPCMasterConfig_3BA.txt`
  maps to `ExtraBreast1L..3R`. In the winning skeleton (XPMSSE, `skeleton_female.nif`), the
  chain is `L Breast03 ← 02 ← 01 ← L Breast00 ← CME L PreBreast`. `NPC L Breast` is a *sibling*
  of that chain, not its parent. So the `Breast` / `LBreast` aliases do **not** move the nude
  breasts; they only move outfits weighted to `NPC L/R Breast`.
* **Butt.** The body is skinned to `NPC L/R Butt`. Both config maps claim that bone: 3BA's as
  `LButt`/`RButt`, CBPC's as `Butt`. That is why all three aliases get the same volume.
* **Belly.** `NPC Belly` is a child of `HDT Belly`, so both `Belly` and `HDTBelly` apply. Both are
  0.4, unchanged.

### 2.2 Numbers

| alias group | LoreRim/3BA shipped | pt8 | **pt15 (now)** | CBPC default |
|---|---|---|---|---|
| 12 breast aliases (`ExtraBreast1-3 L/R`, `ExtraBreast1-3`, `L/RBreast`, `Breast`) | 0.1 | 1.0 | **0.55** (all × 0.55) | 1.0 |
| `LButt` / `RButt` / `Butt` | 0.5 | 0.8 | **0.6** | 1.0 |
| `Belly` / `HDTBelly` | 0.5 | 0.4 | **0.4** (file untouched) | 1.0 |

LoreRim installed 3BA with the "Very Softness" breast preset and strength "A (a bit or no
jiggle)". That is the floppiest spring set at the lowest volume. pt8 raised the volume 10× on
those same floppy springs, which is where "too extra" came from.

The springs that actually drive the nude breasts are 3BA `CBPConfig_3b.txt`, `ExtraBreast1L..3L`:

* stiffness X/Y/Z: 0.020 / 0.017 / 0.022, rising to 0.027 / 0.023 / 0.031 at Breast03
* damping: 0.020–0.055
* offsets: ±2.3 to ±2.76
* timeStep: 0.6 / 0.55 / 0.5
* linearZ: 0.58 / 0.93 / 0.57

For comparison, CBPC's default `Breast` block (copied verbatim into `zzGlueDefaults`) is stiffness
0.039, damping 0.174, offset ±3.889, linearX/Z 1.82 / 1.28, timeStep 0.40. The 3BA butt preset
"Elastic" (`LButt`) is stiffness 0.03, damping 0.035, offsets ±3 (Z +1.5 / −4.5), timeStep 1.2.
CBPC's `Butt` is stiffness 0.03, damping 0.05, ±2.44, timeStep 0.4, which is close.

**`CBPConfig_zzGlueDefaults.txt` was left unchanged, for two reasons:**

1. Its `Breast.*` block does not reach the nude breasts (§2.1).
2. The remaining knob would be damping on `ExtraBreast*`, which needs an override of 3BA's
   `CBPConfig_3b.txt`. That file is not in this lane, and overriding it is only needed if 0.55
   still wobbles too long.

If it is ever needed, the next step is to double the `ExtraBreast*.damping*` values in a glue copy
of `CBPConfig_3b.txt`: roughly 0.04–0.11 in place of 0.02–0.055.

---

## 3. Installed

| file (under `mods\LoreRim Glue`) | bytes | SHA-256 | change |
|---|---|---|---|
| `SKSE\Plugins\CBPConfig_BreastAmplitude.txt` | 1816 | `E8F47E4F3BCFA2AFB6768A4AFCF2726111238E53AB07DA68139E50418081DD9B` | 12 × 1.0 → 0.55 |
| `SKSE\Plugins\CBPConfig_ButtAmplitude.txt` | 953 | `CCB4A40978BCB92643845BE88C0877AB83DE83FF32923145CB906CF36AA5E03A` | 3 × 0.8 → 0.6 |
| `OVERRIDES.md` | 24100 | `EC54232C5775A324321D656EBCCD8040D85E084EE1D653DD4525906189251D5B` | rows updated, plus a new "textures — deliberately NOT overridden" section |
| `SKSE\Plugins\CBPConfig_BellyAmplitude.txt`, `CBPConfig_zzGlueDefaults.txt` | 640 / 6453 | unchanged, hash re-verified | none |

A VFS check confirmed the glue is the only winner for all four `CBPConfig_*` names. Neither the
overwrite folder nor any mod above the glue provides them. No texture file was added. Scratch
tools and images are in `C:\Users\Jordan\AppData\Local\Temp\lrg_test\pt15body\`.

## 4. Owner steps

* **O1 (the nipple fix): turn off OBody's ORefit.** In game, open **MCM → OBody NG** and untick
  **"Enable ORefit"**. Also untick **"Enable ORefit nipple morphing"**.
  * Unticking only the nipple toggle brings the nipples back but keeps the push-up shape in
    scenes. Unticking both gives natural nude bodies.
  * **New game:** do this once, as soon as the MCM appears. OBody.esp defaults both to ON, and
    the setting lives in your save.
  * **Existing save:** after the toggles, press **"Reset all distributed presets"** in a
    near-empty cell, then save, fully exit, restart and reload. OBody's own message requires
    that. For one NPC at a time, aim at her and use **"Reset this actor"**.
* **O2 (look in game):**
  * Out of a scene, jump and sprint. The motion should be clearly present but settle quickly.
  * In an OStim scene with a woman met *after* O1, the nipples and areolas should be visible and
    the breasts should hang naturally rather than looking pushed together.
* **Dials** (in `SKSE\Plugins` of the glue mod; after editing, type `cbpc reload` in the console
  to apply without a restart):
  * Breast: change all 12 numbers in `CBPConfig_BreastAmplitude.txt`. 0.45 is calmer, 0.65 is
    livelier.
  * Butt: change all 3 numbers in `CBPConfig_ButtAmplitude.txt`. 0.5 is calmer, 0.7 is livelier.
  * Belly: `CBPConfig_BellyAmplitude.txt`, 0.4.
* **Performance note, for the owner or the performance lane:**
  `Documents\My Games\Skyrim Special Edition\SKSE\CBPC-Collision.log` is **710 MB**, left over
  from pt8's `Logging = 1`. The installed `CBPCSystem.ini` already reads `Logging = 0`, which was
  restored by another pt15 lane. That old log file can be deleted.
