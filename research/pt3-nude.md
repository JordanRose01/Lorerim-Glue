# pt3 — "she stripped to her underwear": cause and fix

Investigation date 2026-09-21. Read-only: nothing in the game, in MO2 or in the glue was modified.
All modlist line numbers are from `F:\Modlists\LoreRim\profiles\Ultra\modlist.txt`.
**MO2 stores `modlist.txt` in reverse display order: line 1 = bottom of MO2's left pane = HIGHEST
priority. A LOWER line number wins.** (Cross-check: `Nemesis Output - OStim` is line 3, base texture
replacer `Cleaned Skyrim SE Textures` is line 4076.)

---

## Verdict

**Cause (B): the underwear is geometry baked into the female body mesh itself.** The glue did its job
— it emptied every armour slot it promised to. There was nothing left equipped. What the owner saw is
the body mesh, and LoreRim's shipped third-person female body *is* a "NeverNude" body: a CBBE body
with a bra and panties welded onto it as extra shapes.

Causes (A) and (C) are ruled out with evidence below. (D) is documented for completeness.

---

## 1. The evidence for (B)

### 1.1 Which file is the female body in this profile

Scan of every enabled mod (loose files) and every enabled mod's BSA for the exact path
`meshes\actors\character\character assets\femalebody_1.nif`:

| priority | provider | kind |
|---|---|---|
| **line 145** | **`LoreRim - BodySlide Output`** | loose — **WINS** |
| line 1949 | `Caliente's Beautiful Bodies Enhancer -CBBE-` (`CBBE.bsa`) | loses |

Winning file:
`F:\Modlists\LoreRim\mods\LoreRim - BodySlide Output\meshes\actors\character\character assets\femalebody_1.nif`
(1,892,963 bytes, md5 `931584babdc94e5a8912ae69830a34fb`; `_0.nif` md5 `1ced273c0b247b2c15570d62f24bc870`).

### 1.2 What is inside it

Parsed NIF block list (BSTriShape names, in block order):

```
block 27  BSTriShape  CBBE                <- the actual body
block 33  BSTriShape  Bra
block 41  BSTriShape  BraStraps
block 48  BSTriShape  Panty
block 55  BSTriShape  Panty_FrontCloth
block 62  BSTriShape  Panty_BackCloth
block 69  BSTriShape  Panty_SideCloth
block 76  BSTriShape  Panty_Straps
```

Seven of the eight shapes are underwear. Their `BSShaderTextureSet` blocks all point at:

```
textures\cptnjtk\dsunderwear\underwear.dds
textures\cptnjtk\dsunderwear\underwear_n.dds
textures\cptnjtk\dsunderwear\underwear_s.dds
```

Those textures are provided by `Dark Souls Undressed - NeverNude` (line 1953),
`F:\Modlists\LoreRim\mods\Dark Souls Undressed - NeverNude\textures\cptnjtk\dsunderwear\underwear.dds`
(349,680 bytes). Nothing is equipped; the underwear is part of the naked body.

### 1.3 Which BodySlide set it was built from

`F:\Modlists\LoreRim\mods\Dark Souls Undressed - NeverNude - CBBE\CalienteTools\BodySlide\SliderSets\DS Underwear CBBE.osp`
contains:

```xml
<SliderSet name="DSU NeverNude - DS1 Body - CBBE">
    <SourceFile>ds1_f_cbbe.nif</SourceFile>
    <OutputPath>meshes\actors\character\character assets</OutputPath>
    <OutputFile GenWeights="true" ...>femalebody</OutputFile>
    <Shape target="Bra">Bra</Shape>
    <Shape target="BraStraps">BraStraps</Shape>
    <Shape target="BaseShapeHR" DataFolder="CBBE">CBBE</Shape>
    <Shape target="Panty">Panty</Shape>
    <Shape target="Panty_BackCloth">Panty_BackCloth</Shape>
    <Shape target="Panty_FrontCloth">Panty_FrontCloth</Shape>
    <Shape target="Panty_SideCloth">Panty_SideCloth</Shape>
    <Shape target="Panty_Straps">Panty_Straps</Shape>
</SliderSet>
```

Exact shape-for-shape match with the installed file. The source `ds1_f_cbbe.nif` names its body shape
`CBBE`; the 3BA twin `ds1_f.nif` names it `3BA`. The installed body says `CBBE`, so it is the **CBBE**
variant of `DSU NeverNude - DS1 Body`, i.e. mod `Dark Souls Undressed - NeverNude - CBBE` (line 1952).

### 1.4 The clincher — LoreRim built the *other* bodies nude

Same mod, same folder, built at the same time:

```
1stpersonfemalebody_0/1.nif  shapes = ['3BA']                              <- NUDE
femalebodyastrid_0/1.nif     shapes = ['3BA', '3BA_Vagina', '3BA_Anus']    <- NUDE
femalebody_0/1.nif           shapes = ['CBBE', 'Bra', ... 'Panty_Straps']  <- NEVERNUDE
femalefeet_1.nif             shapes = ['Feet']
femalehands_1.nif            shapes = ['Hands']
```

So this is a deliberate LoreRim choice for the third-person body only, not an accident, and the
intended body family is **CBBE 3BA** (the first-person body and Astrid's body are already 3BA).
Note the shipped third-person body has **no breast/CBPC/3BA bones at all** (searched the NIF: no
`Breast`, no `3BA` string, no SMP xml reference) — it is the plain non-physics CBBE shape.

### 1.5 Every other nude-relevant body in that mod is NeverNude too

Twelve per-NPC body folders in `LoreRim - BodySlide Output` are also NeverNude, built from
`Bijin Family Bodyslides - CBBE NeverNude` (line 1811, sets `Bijin NPCs - Body`, `Serana - Body`, …,
all with shapes `Bra` / `BaseShapeHR` / `Panty`):

```
meshes\actors\character\{Bijin NPCs, Bijin Warmaidens, Bijin Wives, Chaconne, Elisif,
                         Minazuki, Serana, Succubus-San, Toccata, Valerica, Vivace}\femalebody_0/1.nif
   shapes = ['CBBE', 'Bra', 'Panty']   textures ...\female\FemaleUnderwear.dds
```

Those NPCs will still strip to underwear even after the generic body is fixed (see fix step 4).
Useful detail: those NIFs reference the **shared** skin textures
(`textures\actors\character\female\femalebody_1*.dds`), not per-NPC skins, so replacing them with the
generic nude body loses nothing.

### 1.6 The skin texture is fine — no texture mod needed

Exact-path BSA scan for `textures\actors\character\female\femalebody_1.dds`:

| priority | provider |
|---|---|
| **line 1944** | **`BnP - Female Skin`** (`BnP - Skinfix - Textures.bsa`) — WINS |
| line 4076 | `Cleaned Skyrim SE Textures` (vanilla `Skyrim - Textures0.bsa`) |

BnP is an ordinary nude CBBE skin. There is **no painted-on underwear** in the winning diffuse, and
the vanilla texture (which does have painted underwear) is overridden. Once the mesh shapes are gone
the body is nude with no texture change.

---

## 2. (A) ruled out — nothing was left equipped

The glue's out-of-scene strip is `StripPart()` in
`...\glue\game\LoreRimGlue\Source\Scripts\LRG_OStim.psc` (`SlotBit()` at line 779, `StripPart()` at
line 862, called from `CmdClothing()` at line 1184). For `part=All` it walks indices 0–7:

| idx | mask | biped slot | stripped on `part=All` |
|---|---|---|---|
| 0 | `0x00000004` | 32 body | yes |
| 1 | `0x00000001` | 30 head | yes |
| 2 | `0x00000002` | 31 hair/hood | yes |
| 3 | `0x00001000` | 42 circlet | yes |
| 4 | `0x00000008` | 33 hands | yes |
| 5 | `0x00000010` | 34 forearms | yes |
| 6 | `0x00000080` | 37 feet | yes |
| 7 | `0x00000100` | 38 calves | yes |

Combined mask `PartFullMask("all") = 0x0000119F`. **Not** touched: 35 amulet, 36 ring, 39 shield,
40 tail, 41 long hair, 43 ears, and 44–61 (the slots mods use for "extra" clothing layers), plus
weapons/ammo. That is a deliberate, correct design (the rails keep jewellery on).

Nothing underwear-shaped is distributed as an equippable item in this profile:

* Bounded scan of every enabled mod's `*_DISTR.ini` / `*_KID.ini` for
  `underwear|undergarment|lingerie|panty|panties|bra|loincloth|nevernude|modest` returned only
  **keyword tagging**, no outfit/item distribution:
  * `Object Categorization Framework` (line 3732) — `ABA_KW-OCF_ARMO_KID.ini`, e.g.
    `Keyword = OCF_BodyTypeUnderwearF_Top|Armor|CBBE_Underwear_Top` (KID adds keywords to existing
    armours; it distributes nothing to NPCs).
  * `B.O.O.B.I.E.S (aka Immersive Icons)` (line 3733) — inventory-icon keywords only.
* No SPID `Outfit = ` / `Item = ` line anywhere adds underwear to NPCs.

A body-slot (32) underwear armour would have been stripped anyway, because index 0 is always taken.

## 3. (C) ruled out — LoreRim ships no disabled nude option

Every disabled (`-`) entry in `modlist.txt` matching
`nude|underwear|nsfw|adult|lingerie|nevernude|cbbe|himbo|3ba|bodyslide|optional|sos|schlong|tng`:

```
line 28    Widescreen 32x9 ... _separator
line 49    Optional - Difficulty ... _separator
line 50    [Optional] Disable Thirst
line 51    [Optional] Pronouns
line 55    [Optional] Arachnaphobia Mod
line 56    [Optional] ENB Frame Generation
line 60    Optional - Gameplay_separator
line 65    Optional - Extreme Visuals ... _separator
line 113   Optional - ReShade_separator
line 1567  3BA Conversions_separator
```

No nude body, no nude BodySlide output, no "NSFW" separator. There is nothing to switch on.
`LoreRim - BodySlide Output` `meta.ini` shows a plain prebuilt Nexus archive
(`installationFile=LoreRim - BodySlide Output-112590-5-0-1779720866.zip`, modid 112590, v5.0.0.0) —
no FOMOD options were chosen and none exist.

## 4. (D) OStim's own undressing — for completeness only

OStim strips *worn items* by slot mask, configurable at runtime:
`OData.GetUndressingSlotMask()` / `OData.SetUndressingSlotMask(int)`
(`F:\Modlists\LoreRim\mods\OStim Standalone - Advanced Adult Animation Framework\Scripts\Source\OData.psc`
lines 8, 10), plus `OActor.Undress` / `UndressPartial(Actor, Mask)` (OActor.psc 200/216) and the
`OStimUndressAtStart` / `OStimUndressMidScene` / `OStimPartialUndressing` globals
(`OSexIntegrationMain.psc` 977/1005/1019). Default MCM: `SetAlwaysUndressAtStart = 1`
(`Interface\OStim\DefaultOstimMCMSettings.json`).

**Irrelevant to this bug:** OStim also only unequips items. In a scene the NPC would end up in exactly
the same painted-on underwear. Per the rules, nothing here was or should be written.

---

## 5. The fix

Least-invasive, rule-respecting fix: **rebuild the third-person female body from a nude slider set and
ship it as a NEW MO2 mod that overrides `LoreRim - BodySlide Output`.** No LoreRim mod is edited,
nothing is downloaded, and the glue needs no code change.

### 5.1 What to build

In BodySlide (`BodySlide and Outfit Studio`, line 3817 — launch it **through MO2**, Ultra profile):

* **Outfit/Body:** `CBBE 3BBB Body Amazing` — from `CBBE 3BA (3BBB)` (line 1948).
  Shapes `3BA / 3BA_Anus / 3BA_Vagina`; nude, and it matches what LoreRim already built for
  `femalebodyastrid` and (3BA) `1stpersonfemalebody`, and matches every 3BA armour refit in the list.
  *Conservative alternative if 3BA physics is unwanted:* `CBBE Body` (shape `BaseShapeHR`) from
  `Caliente's Beautiful Bodies Enhancer -CBBE-` (line 1949) — that is byte-for-byte the shape family
  LoreRim currently ships, just without the bra and panties. **Do not** pick `CBBE NeverNude`,
  `CBBE Underwear`, `CBBE 3BBB Amazing Underwear` or anything named `DSU NeverNude …` — those are the
  underwear sets.
* **Preset:** LoreRim ships **no named preset of its own** (the only `SliderPresets` in enabled mods
  are the stock `CBBE.xml` entries — `- Zeroed Sliders -`, `CBBE Vanilla`, `CBBE SevenBase`,
  `CBBE Oppai`, `CBBE Curvy`, `CBBE Curvy (Outfit)` — plus a handful of single-NPC presets in
  WeelBones/SHWB NPC mods). Use **`- Zeroed Sliders -`** or `CBBE Vanilla`.
  **Why the preset barely matters:** `OBody Next Generation` (line 4, v4.4.3) distributes BodySlide
  presets to every NPC and the player as RaceMenu body morphs at runtime, on top of whatever the base
  mesh is. The built shape is the *base*; OBody re-morphs it in game. OBody cannot add or remove
  shapes, which is exactly why it never removed the baked bra and panties.
* **MUST tick `Build Morphs`.** It regenerates `femalebody.tri` (the current one is 4,033,133 bytes).
  Without it OBody NG / RaceMenu morphs stop working on the body. Build both weights (`Build`, not
  "Build to …" with weights unticked).

### 5.2 Where the output goes

Building through MO2 normally writes into the **Overwrite** folder. After the build:

1. Right-click **Overwrite → Create Mod…**, name it e.g. **`LoreRim Glue - Nude Female Body`**.
2. It must contain exactly:
   ```
   meshes\actors\character\character assets\femalebody_0.nif
   meshes\actors\character\character assets\femalebody_1.nif
   meshes\actors\character\character assets\femalebody.tri
   ```
   Delete anything else BodySlide dropped in there (stray outfit builds) so this mod overrides only
   the body.
3. **Placement:** in MO2's left pane put it **below** `LoreRim - BodySlide Output` (= a *lower* line
   number than 145 in `modlist.txt`; easiest is to drag it to the very bottom of the left pane) and
   tick it. It must win the conflict against line 145.
4. **Rules:** MO2 must be **closed** when profile files are touched; profile edits go only through
   `glue\tools\install_mo2.ps1` (with its backups) and only the integrator runs it. Do **not** run
   Nemesis, LOOT or BodySlide on the owner's behalf — the BodySlide build in 5.1 is an owner step.

### 5.3 The per-NPC bodies (optional, second pass)

Serana, Elisif, Valerica, Toccata, Vivace, Chaconne, Minazuki, Succubus-San and the three Bijin packs
each have their own body at `meshes\actors\character\<Name>\femalebody_0/1.nif` and are still
NeverNude (section 1.5). Those NIFs use the shared skin textures, so the simplest fix is to **copy the
three newly built files into each of those 11 folders inside the new mod**:

```
meshes\actors\character\<Name>\femalebody_0.nif
meshes\actors\character\<Name>\femalebody_1.nif
meshes\actors\character\<Name>\femalebody.tri
```

(`<Name>` ∈ Bijin NPCs, Bijin Warmaidens, Bijin Wives, Chaconne, Elisif, Minazuki, Serana,
Succubus-San, Toccata, Valerica, Vivace.) Skipping this only means those specific NPCs keep their
underwear; everything else works. Do **not** delete the folders — the NPC skin ARMA records point at
those paths and a missing file means an invisible body.

### 5.4 This is global, and that is expected

Replacing `femalebody_*.nif` is a **global** change: every adult female actor in the game — every NPC,
the player, followers — is nude under her clothes from then on, in and out of any glue scene, and
whenever any mod (looting, bathing, OStim, a dress-removing script) empties the body slot. That is
what the owner asked for, but it is not per-NPC and cannot be scoped to the glue. The glue's own rails
(adults only, willing NPC, stop always stops, kill switch) are unaffected and unchanged.

### 5.5 Males

* `LoreRim - BodySlide Output` contains **no `malebody*` files at all** — verified by walking the
  whole mod. The `DSU NeverNude … HIMBO` sets (which output `malebody`) were never built, so **males
  have no baked-in underwear**.
* The winning male body is `meshes\actors\character\character assets\malebody_1.nif` from
  **`Males Of Skyrim by zzjay - with Better Male Feet and High poly hands - SE`** (line 1945, in that
  mod's BSA), and `textures\actors\character\male\malebody_1.dds` from the same mod (line 1945,
  beating vanilla at line 4076).
* **What a stripped male shows today:** a normal nude male torso with a smooth, featureless crotch —
  no genitals. `OStim.log` reports `SoS full is not installed` and `TNG is not installed`, and no
  HIMBO/SOS body mesh wins the path, so nothing supplies genital geometry.
* **Realistic options if the owner ever wants male nudity (do not install any of these without the
  owner explicitly asking; all are large changes):**
  1. **TNG — The New Gentleman** (SKSE plugin). Modern choice, auto-patches armours at runtime, works
     with HIMBO or vanilla-shaped male bodies, OStim-friendly. Still needs a compatible male body
     mesh, adds a plugin and touches every male armour record at runtime.
  2. **SOS — Schlongs of Skyrim SE / SOS AE "Full"** (what OStim probes for). Older, ESP + its own
     body and a per-armour revealing-armour patch set; heavier and more conflict-prone in a list this
     size.
  3. **HIMBO** as the male body base (LoreRim already ships HIMBO *armour refits*, not the HIMBO body)
     plus one of the above, then a male BodySlide batch build — which means re-running BodySlide over
     male outfits.
  Any of these changes male armour fit list-wide and would need its own investigation before touching
  the profile.

---

## 6. For the integrator (glue code)

**No glue change is required for this bug.** The strip worked; the mesh was the problem. Recorded so
nobody re-opens it:

* `LRG_OStim.psc::StripPart()` already clears biped slots 30, 31, 32, 33, 34, 37, 38, 42 on
  `part=All`, and the server log line `GAME undress done who=NPC part=All inscene=0` means it ran.
* Optional hardening, **not needed for this fix**: some Skyrim outfits put an extra clothing layer on
  biped slots 44–49 or 52. If a future playtest shows a stray piece left on, the change is to add
  entries to `SlotBit()` (extend the `aiIdx` ladder, e.g. `0x00004000` = slot 44, `0x00400000` =
  slot 52) **and** widen `PartFullMask("all")` from `0x0000119F` to include the same bits **and**
  raise `PartLast("feet"/"all")` past 7, **and** widen `Form[] cloNpcItems` / `cloPlayerItems`
  (currently 8 slots per actor, indices [0..7] player / [8..15] NPC — see the comment at line 111 and
  the upgrade path `RedressRemembered("script upgrade")` at line 130) so the remembered items still
  fit and can be re-equipped. Do not do this speculatively: it widens what the mod takes off people
  and costs a save-version bump.
