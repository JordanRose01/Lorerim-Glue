# pt8 — jiggle physics, and the body-shape spread

Investigated 2026-09-21 (evening). **Read-only**: this file is the only thing written. Nothing under
`F:\Modlists\LoreRim` was created, modified or deleted; MO2, the game, BodySlide, Nemesis and LOOT were
not launched. Every claim below is from a file I parsed or a log line I read, quoted inline.

Modlist line numbers are from `F:\Modlists\LoreRim\profiles\Ultra\modlist.txt` as it stands **now**
(4086 lines). **Line 1 = highest priority; a LOWER line number WINS.** Current anchors:

| line | mod |
|---|---|
| 2 | `The New Gentleman` |
| **3** | **`LoreRim Glue`** (our override point) |
| 5 | `OBody Next Generation` |
| 8 | `OStim Standalone` |
| 145 | `LoreRim - MCM and INI Settings` |
| 146 | `LoreRim - BodySlide Output` |
| 490 | `XPMSSE Left Hand Sheath Rotation Fix` (winning `skeleton_female.nif`) |
| 718 | `FSMP - Faster HDT-SMP` |
| 1949 | `CBBE 3BA (3BBB)` v2.48 |
| 1950 | `CBPC - Physics with Collisions for SSE and VR` v1.7.2 |

Note `LoreRim - Performance Plugins` is **disabled** (line 119, `-`), so its `configs.xml` never applies.

---

# VERDICT IN ONE PARAGRAPH

Nothing is broken or missing. CBPC's DLL loads, reads its configs without error, the female body
**is** a proper CBPC 3BA body with all 31 physics bones, and every bone exists in the winning
skeleton. The jiggle is missing because of **configuration**, in two layers that both come from
`CBBE 3BA (3BBB)` winning the file conflict against `CBPC`:

1. **`CBPConfig_BreastAmplitude.txt` sets every breast bounce alias to `amplitude 0.1`** — i.e. 10% of
   the motion CBPC would otherwise produce. Butt, belly and thigh are at `0.5`. CBPC's own shipped
   values are `1.0` across the board, but CBPC sits *below* 3BA in priority so 3BA's copies win.
2. **3BA's Physics Manager is set to "breasts are handled by SMP"** (`PhysicsManager.json` →
   `SMP.Breast = 1`), but this body has **no SMP configuration whatsoever** — no physics XML string in
   the NIF and no female entry in 3BA's `defaultBBPs.xml`. So the moment anything puts an actor on
   3BA's SMP list, CBPC is explicitly told to *stop* driving the breast bones and pin them to the rest
   pose, and SMP does not pick them up.

Both are fixable from inside `LoreRim Glue` (line 3 beats 1949 and 1950) with three small text/JSON
files and no change to any other mod.

---

# PART 1 — THE PHYSICS CHAIN, LINK BY LINK

## Link 1 — is CBPC's DLL loading? **YES.**

`C:\Users\Jordan\Documents\My Games\Skyrim Special Edition\SKSE\skse64.log`:

```
checking plugin cbp.dll
loading plugin "CBPC"
plugin cbp.dll (00000001 CBPC 00010702) loaded correctly (handle 40)
```

`00010702` = **1.7.2**, matching `mods\CBPC …\meta.ini` → `version=1.7.2.0`,
`installationFile=CBPC - Fomod installer - MAIN FILE 21224 1.7.2 2026-08-30T23-48Z kATsMWw0A.7z`.
No load error, no version complaint anywhere in `skse64.log`. Game is 1.6.1170; CBPC 1.7.2 is the
current build and is happy with it.

`CBPC-Collision.log` (written 2026-09-21 22:00, i.e. during the owner's most recent session):

```
CBPC Physics SKSE Plugin: 1.7.2
Attempting Game Hook with old
Console interface Loaded
Register Succeeded
CBPC Load Complete
Building Event Sinks...
Loading Configs
System Config file is loaded successfully.
Master Config file is loaded successfully.
Extra Master Config file CBPCMasterConfig_3BA.txt is loaded successfully.
Extra Master Config file CBPCMasterConfig_Anal.txt is loaded successfully.
Extra Master Config file CBPCMasterConfig_SOSScroum.txt is loaded successfully.
Extra Master Config file CBPCMasterConfig_Vagina.txt is loaded successfully.
Loaded MasterConfig
Loaded Bounce configs
Collision Config file is loaded successfully.
Specific collision config files(if any) are loaded successfully.
Player Collision Event Config file is loaded successfully.
```

("Attempting Game Hook with old" is CBPC's normal SE-branch message, not an error — it is followed by
`Register Succeeded` / `CBPC Load Complete`. The block repeats 5× because CBPC re-reads configs on each
save load; the session loaded 5 saves.)

**The timeline matters:** that session ran 21:43–22:18 and `OStim.log` records three scenes inside it —
`21:53:53 starting scene` (`OARE_FKneeling_Fellatio`), `21:57:30` (`OARE_CunnilingusRear` →
`OARE_Missionary`), `22:12:38` (`OARE_StandingEmbraceKiss` … `OARE_StandingCarryingSex`). So the scenes
the owner is describing happened **with CBPC loaded**. This is a real configuration failure, not "the
mod wasn't active yet".

### Which mods ship CBPC config files, and who wins

Exhaustive sweep of `mods\*\SKSE\Plugins\CBP*` across every mod folder — **only two mods** ship any:

| file | `CBBE 3BA (3BBB)` L1949 | `CBPC` L1950 | winner |
|---|---|---|---|
| `CBPCMasterConfig.txt` | — | 3711 B | CBPC |
| `CBPCMasterConfig_3BA/_Anal/_Vagina/_SOSScroum.txt` | 699/50/158/107 B | — | 3BA |
| `CBPCSystem.ini` | — | 2382 B | CBPC |
| `CBPCollisionConfig.txt` | 5304 B | 4797 B | **3BA** |
| `CBPCollisionConfig_Female.txt` | 8881 B | — | 3BA |
| `CBPCollisionConfig_Yuriana.txt` | — | 5291 B | CBPC |
| **`CBPConfig.txt`** | **13 B** | **6272 B** | **3BA (13 bytes!)** |
| **`CBPConfig_BreastAmplitude.txt`** | **378 B (`0.1`)** | 280 B (`1.0`) | **3BA** |
| **`CBPConfig_ButtAmplitude.txt`** | **76 B (`0.5`)** | 74 B (`1.0`) | **3BA** |
| **`CBPConfig_BellyAmplitude.txt`** | **25 B (`0.5`)** | 23 B (`1.0`) | **3BA** |
| `CBPConfig_3b*.txt`, `_BBP*.txt`, `_butt`, `_belly`, `_leg`, `_LegAmplitude`, `_SOSScroum` | present | — | 3BA |

`LoreRim Glue` (line 3) currently ships **no** `SKSE\Plugins` folder at all, so it takes no part.
That priority order (3BA over CBPC) is what 3BA's own instructions ask for and what the owner set up —
it is not itself a mistake, but it has the two consequences below.

The whole of 3BA's winning `CBPConfig.txt` is:

```
Tuning.rate 0
```

That single line **replaces** CBPC's 6.2 KB default file, which is the only place `Breast.*`, `Butt.*`
and `Belly.*` parameters were defined. Verified alias coverage across every winning `CBPConfig*.txt`
(CBPC globs the lowercase prefix `cbpconfig` in `Data\SKSE\Plugins\` — the literal strings `cbpconfig`,
`cbpcmasterconfig`, `cbpcollisionconfig`, `Data\SKSE\Plugins\` are all in `cbp.dll`):

| alias | fully defined? | defined in |
|---|---|---|
| `ExtraBreast1L…3L`, `1R…3R` | yes | `CBPConfig_3b.txt` |
| `LBreast`, `RBreast` | yes | `CBPConfig_BBP.txt` |
| `LButt`, `RButt` | yes | `CBPConfig_butt.txt` |
| `Belly` | yes | `CBPConfig_belly.txt` |
| `LFrontThigh`/`RFrontThigh`/`L,RRearThigh`/`L,RRearCalf` | yes | `CBPConfig_leg.txt` |
| `SOSScrotum` | yes | `CBPConfig_SOSScroum.txt` |
| **`Breast`** | **NO — only `.amplitude`** | `CBPConfig_BreastAmplitude.txt` |
| **`Butt`** | **NO — only `.amplitude`** | `CBPConfig_ButtAmplitude.txt` |
| **`HDTBelly`** | **NO — nothing at all** | (nowhere) |

And the two ConfigMaps collide on four node names. CBPC's `CBPCMasterConfig.txt` `[ConfigMap]` is:

```
NPC L Breast=Breast=IsFemale()
NPC R Breast=Breast=IsFemale()
NPC L Butt=Butt=IsFemale()
NPC R Butt=Butt=IsFemale()
HDT Belly=Belly=IsFemale()
NPC Pelvis [Pelv]
```

3BA's `CBPCMasterConfig_3BA.txt` re-declares the same nodes with different aliases:

```
NPC L Breast=LBreast=IsFemale()      NPC R Breast=RBreast=IsFemale()
NPC L Butt=LButt=IsFemale()          NPC R Butt=RButt=IsFemale()
NPC Belly=Belly=IsFemale()           HDT Belly=HDTBelly=IsFemale()
< L Breast01=ExtraBreast1L … L Breast03=ExtraBreast3L >
< R Breast01=ExtraBreast1R … R Breast03=ExtraBreast3R >
NPC L/R FrontThigh, NPC L/R RearThigh, NPC L/R RearCalf [LrClf]/[RrClf]
```

Whichever of the two duplicate entries CBPC keeps, the outcome is bad in one direction: if **CBPC's**
line wins, `NPC L/R Breast` and `NPC L/R Butt` are bound to the `Breast`/`Butt` aliases that now have
**no stiffness, no damping and no offsets** — a dead config. `HDT Belly` is bound to `HDTBelly`, which
is undefined no matter who wins. The one path that is alive under every interpretation is the
`L/R Breast01–03 → ExtraBreast*` chain — and that is the chain capped at `amplitude 0.1`.

`amplitude` is confirmed as a first-class CBPC bounce parameter: the string `amplitude` (plus
`breastClothedAmplitude`, `breastLightArmoredAmplitude`, `breastHeavyArmoredAmplitude`) is in
`cbp.dll` alongside `stiffness`, `damping`, `maxoffset`, `linearX`, `timeStep` …, and CBPC's own
bounce-interpolation target file `CBPCBounceinterpolationconfig_Test.txt` consists of nothing but
`…​.amplitude 0.0 0.0` lines — i.e. amplitude is the knob CBPC itself uses to interpolate motion down
to zero. So `0.1` means one tenth of the motion.

`CBPCSystem.ini` (CBPC's, winning) is otherwise sane and is **not** limiting anything:
`SkipFrames = 0`, `ActorDistance = 1024`, `ActorBounceDistance = 2048`, `ActorAngle = 360` (no angle
restriction), `UseCamera = 0`, `InCombatActorCount = 20`, `OutOfCombatActorCount = 40`,
`FpsCorrection = 0`, **`Logging = 0`** (see fix F4 — turning this on is the definitive in-game test).
`CBPCMasterConfig.txt` `MalePhysics=0`, which is default and irrelevant to females.

## Link 2 — is the winning female body a physics body? **YES, a CBPC body. Not an SMP body.**

I wrote my own NIF-header parser (`…\scratchpad\nifhdr.ps1`) and read the block list and full string
table of each file rather than trusting earlier notes.

`F:\Modlists\LoreRim\mods\LoreRim Glue\meshes\actors\character\character assets\femalebody_0.nif`
and `femalebody_1.nif` (2,009,928 bytes each, installed 2026-09-21 11:47, all 24 files present):

```
Gamebryo 20.2.0.7  user=12  bs=100  blocks=71   "Exported using Outfit Studio."
BSTriShape x3  (3BA, 3BA_Vagina, 3BA_Anus)   NiNode x52   NiStringExtraData x1
57 strings
physics bones (31): L Breast01, L Breast02, L Breast03, R Breast01, R Breast02, R Breast03,
                    NPC L Butt, NPC R Butt, NPC Belly,
                    NPC L/R FrontThigh, NPC L/R RearThigh, NPC L/R RearCalf, NPC L/R Thigh, NPC L/R Calf,
                    NPC L/R Pussy02, VaginaB1, VaginaDeep1, Clitoral1,
                    NPC LT/RT/LB/RB Anus2, NPC Anus Deep2
XML / HDT / SMP references: (none)
the only NiStringExtraData: BODYTRI -> actors\character\character assets\femalebodyastrid.tri
```

Two things follow:

* **It is a CBPC body.** All the bones CBPC's and 3BA's ConfigMaps name are in it, and all of them
  exist in the winning skeleton `mods\XPMSSE Left Hand Sheath Rotation Fix\meshes\actors\character\
  character assets female\skeleton_female.nif` (L490, 716 strings) — I checked each by name:
  `NPC L Breast` ✓, `NPC R Breast` ✓, `L Breast01/02/03` ✓, `NPC L Butt` ✓, `NPC Belly` ✓,
  `HDT Belly` ✓, `NPC L FrontThigh` ✓, `NPC L RearCalf` ✓. (`L PreBreast01` is **absent** — that is
  expected and is exactly the branch 3BA's reset function handles.)
* **It is not an SMP body, and cannot be made one by accident.** There is no `.xml`, `.hkx` or
  `HDT Skinned Mesh Physics Object` string anywhere in either file — the sole `NiStringExtraData` is
  `BODYTRI`. Nothing dangles; there is simply no SMP config for this mesh.

For comparison, the body the glue overrides,
`mods\LoreRim - BodySlide Output\…\femalebody_1.nif` (L146):

```
8 BSTriShape (CBBE + Bra + BraStraps + Panty + 4 panty cloth shapes), 27 NiNode, 7 NiAlphaProperty
physics bones: NPC L/R Thigh, NPC L/R Calf only — no breast, butt or belly bones at all
```

So the glue's replacement is a straight upgrade: 4 → 31 physics-relevant bones. The glue's body is
byte-identical (as installed) to LoreRim's own nude `femalebodyastrid_1.nif`, and both match 3BA's
BodySlide source `CalienteTools\BodySlide\ShapeData\SE 3BBB Amazing\SE 3BBB Body Amazing v2.nif` shape
for shape (`3BA`, `3BA_Vagina`, `3BA_Anus`, 52 NiNode, same 31 physics bones, no SMP string).

**3BA v2.48 ships exactly one body slider set** — `SliderSets\SE 3BBB Amazing.osp` →
`<SliderSet name="CBBE 3BBB Body Amazing"> <SourceFile>SE 3BBB Body Amazing v2.nif</SourceFile>
<OutputFile GenWeights="true">femalebody</OutputFile>`. There is **no separate "SMP" body set to
build instead**. In current 3BA the SMP path is not a different body mesh at all (see Link 3).

## Link 3 — is 3BA set to CBPC or SMP? **SMP for breasts. And SMP covers nothing on this body.**

### 3BA's stored setting

`mods\CBBE 3BA (3BBB)\SKSE\Plugins\StorageUtilData\CBBE 3BA\PhysicsManager.json` (169 B, the **only**
copy anywhere under `F:\Modlists\LoreRim` — nothing in `overwrite`, nothing in `Stock Game`):

```json
{ "PhysicsManage" : { "SMP" : {
    "Breast" : 1,  "Belly" : 0,  "Butt" : 0,
    "Thigh" : 0,   "Vagina" : 0, "VaginaCollision" : 0 } } }
```

`PapyrusUtilDev.log` confirms 3BA reads it every load and never wrote it back:

```
JSON Loading: Data/SKSE/Plugins/StorageUtilData/CBBE 3BA/PhysicsManager.json
JSON Reverted CBBE 3BA/PhysicsManager.json
```

(no `JSON Saved`, and no copy in `overwrite\skse\plugins\StorageUtilData\` — so this is 3BA's shipped
default, untouched.)

### What that setting does

I extracted 3BA's scripts from `3BBB.bsa` (BSA v105 / LZ4-frame; parser written for this pass,
`%TEMP%\lrg_test\bsax2.py`). `scripts\source\mus3bphysicsmanager.psc`:

```papyrus
String Property FileName = "CBBE 3BA/PhysicsManager" autoReadOnly
bool property BreastSMP = false auto hidden
...
Function DataManagerLoad()
    BreastSMP = JsonUtil.GetPathBoolValue(FileName, ".PhysicsManage" + ".SMP" + ".Breast")
    ...

function CBPCBreasts(actor target, bool Pstop = false)
    if !BreastSMP
        return                                     ; part is on CBPC -> leave CBPC alone
    endif
    if !Pstop
        CBPCPluginScript.StartPhysics(target, LBreast01Name)   ; ... 01/02/03 L+R
    else
        CBPCPluginScript.StopPhysics(target, LBreast01Name)    ; ... 01/02/03 L+R
        CBPCBreastsReset(target)                   ; SetNodeLocalPosition/RotationEuler -> rest pose
    endif
endFunction

function CBPCPhysicsAccess(actor target, bool Pstop = false, bool initial = false)
    CBPCBreasts(target, Pstop)  CBPCButts(...)  CBPCBelly(...)
    CBPCVaginaCollision(...)    CBPCVagina(...) CBPCThigh(...)
endFunction

function ApplyActorList()          ; every actor on ActorPhysicsList
    ... CBPCPhysicsAccess(target, true, true)      ; Pstop = true  => StopPhysics + pin bones
endFunction
```

and `scripts\source\musactorphysicsalias.psc` re-applies it on **every** game load:

```papyrus
Event OnPlayerLoadGame()
    PM.DataManager(1)          ; reload the JSON
    PM.ApplyActorList()        ; StopPhysics + reset for everyone on the SMP list
EndEvent
```

The SMP list is populated when the player uses 3BA's MCM / its toggle spell
(`ToggleSpellP3BBB` → `MCM.PlayerSMP()`, `NPCSMP(CrossHairRef)`), which **equips a 3BA "collision
armor"** on biped slot 48/50/51/60 — `meshes\armor\mu3b\3bbbcollisionarmorf1_0.nif` … `p_1.nif`, whose
physics XMLs are `SKSE\Plugins\hdtSkinnedMeshConfigs\Outfits\3BCA-A..D-Amazing.xml`. That equipped item
is where 3BA's SMP comes from — **not** the body mesh.

So: with `SMP.Breast = 1`, the instant any actor is put on 3BA's SMP list, CBPC is told to stop driving
`L/R Breast01–03` on her and those bones are snapped back to the rest pose, permanently, re-applied on
every load. It is a live trap even if it has not fired yet.

### Does SMP cover the body? **No.**

* `FSMP - Faster HDT-SMP` L718 loads fine — `skse64.log`: `plugin hdtsmp64.dll (00000001 hdtsmp64
  03020010) loaded correctly`; `hdtsmp64.log` is **44 bytes**, one line: `[21:43:50.782] [C] hdtsmp64
  v3-2-1-0 (avx)`.
* Winning `SKSE\Plugins\hdtSkinnedMeshConfigs\configs.xml` comes from `LoreRim - MCM and INI Settings`
  (L145, 1022 B; FSMP's own copy at L718 loses; `LoreRim - Performance Plugins` is disabled). It is
  global tuning only — `<smp>`, `<solver>`, `<wind>` — with `logLevel 0`,
  `maximumActiveSkeletons 10`, `budgetMs 3.5`, `disable1stPersonViewPhysics true`. **No `defaultBBP`
  and no per-mesh mapping.**
* `defaultBBPs.xml` exists exactly once — `mods\CBBE 3BA (3BBB)\SKSE\Plugins\hdtSkinnedMeshConfigs\
  defaultBBPs.xml`, 715 B, in full:

  ```xml
  <default-bbps>
    <map shape="MaleBody"     file="...\MaleBody.xml"/>
    <map shape="MaleHead"     file="...\HeadMale.xml"/>
    <map shape="MaleHands"    file="...\MaleHands.xml"/>
    <map shape="MaleGenitals" file="...\MaleGenitals.xml"/>
    <map shape="penis"        file="...\penis.xml"/>
    <map shape="Boner"        file="...\Boner.xml"/>
    <map shape="ErinTail"     file="...\tailSMP.xml"/>
    <map shape="ErinEar"      file="...\earSMP.xml"/>
  </default-bbps>
  ```

  **Not one female-body entry.** No `3BA`, no `femalebody`, no `3BBB-Amazing.xml`.
* 3BA's female body SMP configs do exist on disk — `3BBB-Amazing.xml` (96 KB),
  `Outfits\3BCA-A..D-Amazing.xml`, and `Meshes\actors\character\character assets\CUSTOM CBBE SMP.xml`
  (16 KB) — but **nothing references them**: no NIF in the winning load order carries those paths as
  `NiStringExtraData`, and `defaultBBPs.xml` does not map them.

So the SMP half of the chain is a dead end for this body, by design of what was built.

## Link 4 — does OStim disable or alter body physics? **No. OStim is innocent.**

* I grepped every `.json/.ini/.txt/.psc/.xml` in `mods\OStim Standalone …` for
  `cbpc|hdtsmp|smp|physics|jiggle|bounce`. The only hits are OStim's **own props**: the Calyps strap-on
  (`meshes\OStim\strapons\CalypsStrapOnSMP.xml`) and the tongues
  (`meshes\OStim\tongues\LongTongueSMP.xml`, `NormalTongueSMP.xml`). No setting, anywhere, that touches
  body physics, CBPC or the actor's own bones.
* `OStim.log` for the session with the three scenes contains nothing physics-related. The only
  notable lines are `SoS full is not installed.`, `papyrus undressing is disabled`, and
  `path (Data/SKSE/Plugins/OStim/settings) does not exist` — i.e. **OStim has no saved settings, so
  its MCM defaults are in force** (`Interface\OStim\DefaultOstimMCMSettings.json` →
  `"SetAlwaysUndressAtStart": 1`).
* OStim's undressing slot mask is logged verbatim: `reading undressing mask: 3d8bc39d`. Decoded
  (bit *n* = biped slot 30+*n*), OStim strips 30, 32, 33, 34, 37, 38, 39, 44, 45, 46, 47, 49, 53, 54,
  56, 57, 58, 59 and deliberately **leaves 48, 50, 51, 52 and 60 alone** — which is precisely the set
  3BA uses for its SMP collision armour (OStim's own MCM label confirms the intent:
  `$ostim_slot_50  S. 50  Used by 3BA/BHUNP for SMP physics`) plus slot 52 where TNG's cover sits.
  OStim is actively protecting 3BA's physics item, not stripping it.

## Link 5 — outfit vs naked: does anything swap the body in-scene? **No.**

* The glue's undress path (`LRG_OStim.psc::StripPart()`, `PartFullMask("all") = 0x0000119F`, slots
  30/31/32/33/34/37/38/42) only unequips worn items; it cannot change a mesh. Confirmed unchanged
  from pt3.
* **`The New Gentleman` is at line 2 — above the glue — so I checked it file by file.** TNG ships
  `meshes\actors\character\character assets\MaleBody_0/1.nif`, `MaleFeet_0/1.nif`, the
  `TNG\*_genitals_*.nif` set, boots, `auxbones\SOS\*.hkx`, BodySlide data and translations. A filtered
  search of the whole mod for `femalebody|cbp|hdt|smp` returns **zero files**. TNG's only female asset
  is `meshes\actors\character\character assets\TNG\f_blank.nif` (345 bytes) — a deliberately empty
  genital mesh for female actors. **TNG does not touch the female body, its bones or any physics
  config.** It cannot be the cause, and it does not conflict with the glue's meshes.
* TNG does equip an item on actors — `DVSMPSurvivalTweaks.log`:
  `EquipEvent: TNG GenitalCover (FE0B1AFF)` at 21:47:29 and again at 21:54:49. That is slot 52,
  which OStim does not strip. It is a male-genital cover and carries no physics.
* No other "nude body replacer" armor record: pt3's bounded scan of every `*_DISTR.ini` / `*_KID.ini`
  for underwear/nude keywords found only keyword tagging (`Object Categorization Framework`,
  `B.O.O.B.I.E.S`), no SPID `Outfit =` / `Item =` distribution. Nothing re-skins an actor mid-scene.

## Link 6 — is anything overriding the body or CBPC's configs above the glue? **No.**

* Loose `femalebody_*.nif` providers, with priority (glue = 3):

  | line | mod | files |
  |---|---|---|
  | **3** | **LoreRim Glue** | 24 — **wins every path it covers** |
  | 146 | LoreRim - BodySlide Output | 24 |
  | 890 | Belethor's General Goods WeelBones' Replacers | 2 |
  | 1790–1798 | 7 × WeelBones/SHWB NPC overhauls (Nirya, Birna, Ranmir, Mirabelle, Faralda, Rayya, Susanna, Adrianne) | 2 each |
  | 1807 | Bijin Wives SE (Ysolda Only) | 2 |

  Only line 2 (`The New Gentleman`) is above the glue and it ships no female body. So the glue's
  `femalebody_0/1.nif` wins in `character assets` and in all eleven per-NPC folders it covers,
  including `Bijin Wives` (whose own L1807 copy is an old `bs=83` non-physics `BaseShape` mesh with
  **4** physics bones — the glue correctly beats it).
* The eight WeelBones/SHWB mods put their bodies under *their own* folder names
  (e.g. `Meshes\actors\character\Faralda WeelBones' Overhaul Meshes\Body\femalebody_1.nif`), which the
  glue does not cover — see "Other findings" below. I parsed two: they **are** full nude 3BA physics
  bodies with the same 31 bones, so those NPCs still get jiggle from the same config.
* CBPC config overrides: only 3BA (1949) and CBPC (1950) ship any `SKSE\Plugins\CBP*` file. The glue
  ships none today, so it is a free, uncontested override slot.

## CONCLUSION — the single most likely reason, and the fixes ranked

**Most likely reason for (near-)zero jiggle:** the breast bounce is running at **10% amplitude**.
The only breast path that is alive for this mesh under every reading of the config merge is
`L/R Breast01–03 → ExtraBreast1L…3R`, and `CBPConfig_BreastAmplitude.txt` (3BA's copy, which wins
because 3BA outranks CBPC) sets all six of those to `amplitude 0.1`. Butt and belly are at `0.5`,
which reads as "not a lot of jiggle" rather than none. On top of that, whichever way CBPC resolves the
duplicated ConfigMap entries, `NPC L/R Breast`, `NPC L/R Butt` and `HDT Belly` are at risk of being
bound to aliases (`Breast`, `Butt`, `HDTBelly`) that the 13-byte `CBPConfig.txt` left with **no
parameters at all**.

**Second most likely, and a trap that will bite later even if it has not yet:** 3BA's Physics Manager
default says breasts belong to SMP, and SMP has no config for this body. Any use of the 3BA MCM /
toggle spell calls `StopPhysics` on the breast chain and pins it, forever, re-applied every load.

All fixes below go **into `LoreRim Glue`** (line 3), which beats both 3BA and CBPC. No other mod is
edited, no profile file changes, no BodySlide / Nemesis / LOOT run.

### F1 — highest certainty, smallest change: restore the amplitudes

New file `LoreRimGlue\SKSE\Plugins\CBPConfig_BreastAmplitude.txt`:

```
ExtraBreast1L.amplitude 1.0 1.0
ExtraBreast2L.amplitude 1.0 1.0
ExtraBreast3L.amplitude 1.0 1.0
ExtraBreast1R.amplitude 1.0 1.0
ExtraBreast2R.amplitude 1.0 1.0
ExtraBreast3R.amplitude 1.0 1.0

ExtraBreast1.amplitude 1.0 1.0
ExtraBreast2.amplitude 1.0 1.0
ExtraBreast3.amplitude 1.0 1.0

RBreast.amplitude 1.0 1.0
LBreast.amplitude 1.0 1.0

Breast.amplitude 1.0 1.0
```

and `CBPConfig_ButtAmplitude.txt` (`RButt`/`LButt`/`Butt` = `0.8 0.8`), `CBPConfig_BellyAmplitude.txt`
(`Belly.amplitude 0.4 0.4` — the owner does **not** want belly motion emphasised), and optionally
`CBPConfig_LegAmplitude.txt` at `0.5` (unchanged). Same filenames as 3BA's so they override cleanly.
Start at `1.0` breast / `0.8` butt; if it looks cartoonish, walk breast back to `0.6`.

### F2 — high certainty: put breasts back on CBPC and disarm the SMP trap

New file `LoreRimGlue\SKSE\Plugins\StorageUtilData\CBBE 3BA\PhysicsManager.json`:

```json
{ "PhysicsManage" : { "SMP" : {
    "Breast" : 0, "Belly" : 0, "Butt" : 0,
    "Thigh" : 0,  "Vagina" : 0, "VaginaCollision" : 0 } } }
```

With `BreastSMP = false`, `CBPCBreasts()` returns immediately and can never call `StopPhysics`.
**Caveat the builder must know:** `JsonUtil` reads through MO2's VFS so the glue's copy wins on disk,
**but** if 3BA's MCM has ever been used, the actors are recorded in the ESP FormList
`ActorPhysicsList`, which lives in the save, not on disk. Owner step O2 clears that.

### F3 — medium certainty, cheap insurance: refill the emptied aliases

New file `LoreRimGlue\SKSE\Plugins\CBPConfig_zzGlueDefaults.txt` containing the `Breast.*` and
`Butt.*` blocks copied verbatim from CBPC's own
`mods\CBPC …\SKSE\Plugins\CBPConfig.txt` (stiffness 0.039 / stiffness2 0.01 / damping 0.174 /
±3.889 offsets / timetick 13.33 / linearX 1.82 / linearY 0.3 / linearZ 1.28 / rotationalX 0.074 /
timeStep 0.40 / the collision block for `Breast`; the `Butt` block likewise), plus an `HDTBelly.*`
block copied from `CBPConfig_belly.txt`'s `Belly.*`. The `zz` prefix makes it sort last so it fills
gaps without fighting 3BA's tuned values for aliases 3BA does define. This makes the outcome
independent of how CBPC resolves the duplicate ConfigMap keys.

### F4 — the definitive test, and it costs nothing

New file `LoreRimGlue\SKSE\Plugins\CBPCSystem.ini` — a copy of CBPC's with one line changed:

```
Logging = 1
```

CBPC then writes per-node attach/drive detail to `…\SKSE\CBPC-Collision.log`, which settles in one
minute whether a given bone is being driven on a given actor. **Turn it back to 0 afterwards** — CBPC's
own comment: *"This decreases performance, so don't play with this on. Also the file generated will be
huge if played long."* Do this on the same run as F1–F3 so one playtest answers everything.

### Fixes that are NOT needed

* Nothing needs re-building in BodySlide for physics. The body already has every bone.
* Do **not** try to give this body an SMP config. 3BA v2.48 has no SMP body slider set, its SMP comes
  from an equipped collision-armour item, and `defaultBBPs.xml` deliberately maps no female body.
  CBPC is the right and intended engine here.
* Do not reorder `CBPC` above `CBBE 3BA (3BBB)` in MO2. That would restore CBPC's `amplitude 1.0` and
  its `Breast`/`Butt` parameters, but it would also throw away 3BA's `CBPCollisionConfig.txt`,
  `CBPCollisionConfig_Female.txt` and the whole tuned `_3b`/`_butt`/`_belly`/`_leg` set — a much
  bigger, riskier change than four files in the glue, and it edits the profile.

### Owner-only steps for Part 1

* **O1** — After the builder installs, in game open **MCM → CBBE 3BA → Physics Manager** (or whatever
  that page is called in this build) and confirm every part reads **CBPC**, not SMP.
* **O2** — On the same page use **"Clean SMP Actor List"** (the MCM function that calls
  `PM.CancleActorList()` / `CleanActorList()`) once, in a cell with no other NPCs, then save and
  reload. That empties `ActorPhysicsList` in the save so no actor stays pinned.
* **O3** — Test out of a scene first: stand in third person, jump and sprint. If breasts move while
  running but not in a scene, that is a different problem and worth reporting; if they move in both,
  done. Only then judge the scene.

---

# PART 2 — BODY SHAPES: WHY EVERY GIRL IS CURVY AND THICK

## Where shapes actually come from in this modlist

Three layers, in order:

1. **The base mesh** — the glue's `femalebody_0/1.nif` (Astrid's 3BA build), blended by the NPC's
   weight 0–100 between `_0` (low) and `_1` (high).
2. **RaceMenu BodyMorph** — driven by the `.tri` the NIF points at:
   `BODYTRI = actors\character\character assets\femalebodyastrid.tri` (from L146, 5,011,925 B,
   154 morphs on the `3BA` shape). This is the layer OBody writes to.
3. **OBody NG 4.4.3** (L5) — picks a BodySlide *preset* per actor and applies it as morphs on top.
   `OBody.log`: `OBody 4.4.3.0 is loading…`, `Validated Data/SKSE/Plugins/
   OBody_presetDistributionConfig.json successfully`, then
   **`Female presets: 17, Male presets: 1` / `Blacklisted: Female presets: 1, Male Presets: 0`**.

## OBody's config: one copy, completely stock

`SKSE\Plugins\OBody_presetDistributionConfig.json` is provided by **exactly one mod** — `OBody Next
Generation` (L5, 854 B, 2025-10-29). No other mod anywhere under `F:\Modlists\LoreRim` ships that path,
and there is no copy in `overwrite`. Its full content:

```json
{ "npcFormID": {}, "npc": {}, "factionFemale": {}, "factionMale": {},
  "npcPluginFemale": {}, "npcPluginMale": {}, "raceFemale": {}, "raceMale": {},
  "blacklistedNpcs": [], "blacklistedNpcsFormID": {},
  "blacklistedNpcsPluginFemale": [], "blacklistedNpcsPluginMale": [],
  "blacklistedRacesFemale": ["ElderRace"], "blacklistedRacesMale": ["ElderRace"],
  "blacklistedOutfitsFromORefitFormID": {},
  "blacklistedOutfitsFromORefit": ["LS Force Naked", "OBody Nude 32"],
  "blacklistedOutfitsFromORefitPlugin": [],
  "outfitsForceRefitFormID": {}, "outfitsForceRefit": [],
  "blacklistedPresetsFromRandomDistribution": [
      "- Zeroed Sliders -", "-Zeroed Sliders-", "Zeroed Sliders", "HIMBO Zero for OBody" ],
  "blacklistedPresetsShowInOBodyMenu": true }
```

**Every distribution map is empty**, so every female NPC gets a **uniformly random** pick from the
whole preset pool, and the only thing excluded is the zeroed preset. `LoreRim - MCM and INI Settings`
(L145) ships **no** MCM settings for OBody, 3BA, CBPC or OStim — I listed every file in it — and
`overwrite\` contains no `MCM\Settings\OBody*`, so OBody's MCM is at defaults too.

**OBody's MCM has no shape sliders at all.** Extracted from `Scripts\OBodyNGMCMScript.pex` and
`Interface\translations\OBody_ENGLISH.txt`, its complete option list is: *Enable ORefit*, *Enable
ORefit nipple morphing*, *Enable nipple randomization*, *Enable genitals randomization*, *Enable
Performance Mode*, *Enable respectful morph application*, *Force immediate preset application*,
*Enable legacy StorageUtil usage*, *Presets list key*, *Reset all distributed presets*, *Reset this
actor*. Toggles and a hotkey — **no 0–100 or 1–100 slider anywhere**.

## The preset pool — this is the whole problem

`CalienteTools\BodySlide\SliderPresets\*.xml` across all **enabled** mods — the entire pool OBody has
to choose from (I parsed every `<Preset>` and every `<SetSlider>`; values below are BodySlide's own
0–100 scale, shown **low-weight / high-weight**):

| preset | source (line) | key sliders | class |
|---|---|---|---|
| `- Zeroed Sliders -` | CBBE (1951) | all 0 | already blacklisted |
| `CBBE Vanilla` | CBBE | all 0 on shape sliders | **vanilla** |
| `CBBE SevenBase` | CBBE | 0 on all shape sliders | **vanilla** |
| `CBBE Petite` | CBBE | BreastsSmall 80/25, ButtSmall 70/40, Belly −25/0 | **slim** |
| `CBBE Slim`, `CBBE Slim (Outfit)` | CBBE | BreastsSmall 60/0, ButtSmall 35/0, Butt 50/50 | **slim** |
| `CBBE Athletic` | CBBE | Breasts 0/30, ButtSmall 80/35, Waist 30/30 | **natural/athletic** |
| `CBBE Strong` | CBBE | BreastsSmall 60/0, BigButt 10/20, ButtSmall 35/0 | **natural/athletic** |
| `Skinny Days - Birna's Body Preset WeelBones` | Remnants (1791) | HipBone 100, Thighs 14, no belly | **slim** |
| `Observer of the void - Paranoiac's Body` | Paranoiac (1790) | BigBelly 12, ChubbyButt 30, ChubbyWaist 30, Arms 120 | mild-thick |
| `[Dint999] SSE First CBBE Body Physics` | Dint999 (1871) | Breasts 0/30, BigButt 0/15, **Belly 0/40** | thick at high weight |
| **`CBBE Curvy`**, **`CBBE Curvy (Outfit)`** | CBBE | **Breasts 0/80**, BigButt 0/15, AppleCheeks 0/35, **Butt 50/80** | **curvy** |
| **`CBBE Oppai`** | CBBE | BreastPerkiness 0/−25 (big, heavy breasts) | **curvy** |
| **`CBBE Fetish`**, **`CBBE Fetish v2`** | CBBE | Breasts 15/25, AppleCheeks 25/35, **Thighs 40/40**, ChubbyButt 20/40 | **thick** |
| **`CBBE Chubby`** | CBBE | **Belly 60/60, BigBelly 0/25, ChubbyButt 85/100, ChubbyWaist 55/60, ChubbyLegs 30/50** | **thick + belly** |
| **`State of mind - Psyche's Body`** | Psyche (1792) | **Belly 70/70**, ChubbyWaist 75, Waist 48 | **belly** |
| **`Warmaiden - Adrianne Avenicci SHWB's Body 3BA`** | Warmaiden (1798) | **Belly 80/80, BigBelly 35/35**, ChubbyWaist 28, HipBone 57 | **big belly** |
| **`OutfitTesting`** | NordwarUAs Race Armor Expansion - Bodyslide (1648) | **BigButt 60/60, AppleCheeks 83/83, BigBelly 0/64, ChubbyWaist 0/63, ChubbyArms 0/82, ChubbyLegs 0/64, Waist 0/52** | **extreme — huge butt + belly + thick limbs** |
| `Sentence - Ranmir Himbo Body` | Remnants (1791) | all 0 | male/HIMBO |
| `TNG Default` | TNG (2) | all 0 | male |

That table **is** the owner's complaint, item for item. `OutfitTesting` — a shape-testing preset that a
*bodyslide-for-armour* mod happened to leave in `SliderPresets` — is in the random rotation with
`AppleCheeks 83` and `BigButt 60`. `CBBE Chubby`, `Warmaiden…`, `State of mind…` carry `Belly` 60–80.
`CBBE Curvy` carries `Breasts 80` / `Butt 80`. With every distribution map empty, roughly **half** the
pool is curvy/thick/belly, so about half the women in Skyrim are. `OBody.log` shows it happening:
`Preset Skinny Days - Birna's Body Preset WeelBones will be applied to Braste`, `Preset Sentence -
Ranmir Himbo Body will be applied to Decimus Oritius`.

3BA itself ships **no** `SliderPresets` (only `SliderCategories\CBBE 3BA.xml` and
`SliderGroups\CBBE 3BA.xml`), so there is no 3BA preset pack to lean on, and no LoreRim-specific body
preset pack exists in the list.

## What "the curvy option" and "the diverse 1-100 slider" most plausibly mean

Nothing in this modlist has a setting literally named "curvy", and **no installed mod offers a 1–100
diversity slider** — OBody's MCM has none (list above), 3BA's MCM manages physics parts and morph
sliders, and OBody NG has no random-slider generator. So the owner is remembering something else, and
there are two candidates:

1. **The BodySlide preset list from the earlier turn.** `research\pt3-nude.md` §5.1 presented exactly
   this shortlist for building the body: *"`- Zeroed Sliders -`, `CBBE Vanilla`, `CBBE SevenBase`,
   `CBBE Oppai`, `CBBE Curvy`, `CBBE Curvy (Outfit)`"* — a list in which `CBBE Curvy` is the fullest
   figure and the recommendation was *"Use `- Zeroed Sliders -` or `CBBE Vanilla`"*. The "1–100
   slider" would then be BodySlide's own per-slider 0–100 values, i.e. "set the sliders yourself
   instead of picking a named preset".
2. **OBody's random distribution** — the mechanism that is *supposed* to give a diverse spread, and
   which is currently diverse but unfiltered, so it hands out `CBBE Curvy`/`Chubby`/`OutfitTesting` as
   often as `Slim`/`Athletic`.

Either way the fix is the same and it is better than both: **keep the random spread and curate the
pool.** That gives natural/slim/vanilla bodies varied per NPC — plus continuous variation from each
NPC's own weight (0–100) blending `femalebody_0.nif` ↔ `femalebody_1.nif` — while big-butt and belly
shapes become impossible to roll.

## RECOMMENDED CHANGE — one file in the glue

New file `LoreRimGlue\SKSE\Plugins\OBody_presetDistributionConfig.json`. Glue is line 3, OBody NG is
line 5, so this wins. It is the stock file with **only** `blacklistedPresetsFromRandomDistribution`
extended (and `blacklistedPresetsShowInOBodyMenu` left `true`, so all the excluded presets are still
selectable by hand from OBody's preset-list hotkey — the owner loses nothing):

```json
{
  "npcFormID": {}, "npc": {}, "factionFemale": {}, "factionMale": {},
  "npcPluginFemale": {}, "npcPluginMale": {}, "raceFemale": {}, "raceMale": {},
  "blacklistedNpcs": [], "blacklistedNpcsFormID": {},
  "blacklistedNpcsPluginFemale": [], "blacklistedNpcsPluginMale": [],
  "blacklistedRacesFemale": ["ElderRace"], "blacklistedRacesMale": ["ElderRace"],
  "blacklistedOutfitsFromORefitFormID": {},
  "blacklistedOutfitsFromORefit": ["LS Force Naked", "OBody Nude 32"],
  "blacklistedOutfitsFromORefitPlugin": [],
  "outfitsForceRefitFormID": {}, "outfitsForceRefit": [],
  "blacklistedPresetsFromRandomDistribution": [
    "- Zeroed Sliders -",
    "-Zeroed Sliders-",
    "Zeroed Sliders",
    "HIMBO Zero for OBody",
    "OutfitTesting",
    "CBBE Chubby",
    "CBBE Curvy",
    "CBBE Curvy (Outfit)",
    "CBBE Oppai",
    "CBBE Fetish",
    "CBBE Fetish v2",
    "Warmaiden - Adrianne Avenicci SHWB's Body 3BA",
    "State of mind - Psyche's Body [By WeelBones]",
    "[Dint999] SSE First CBBE Body Physics"
  ],
  "blacklistedPresetsShowInOBodyMenu": true
}
```

The **surviving random pool** is then: `CBBE Vanilla`, `CBBE SevenBase`, `CBBE Petite`, `CBBE Slim`,
`CBBE Slim (Outfit)`, `CBBE Athletic`, `CBBE Strong`, `Skinny Days - Birna's Body Preset WeelBones`,
`Observer of the void - Paranoiac's Body [By WeelBones]` — nine female shapes running vanilla → slim →
athletic, none with a `Belly` slider above 12, none with `BigButt` above 20. That is "natural, diverse,
vanilla", which is what was asked for.

Notes for the builder:
* The names must match the `<Preset name="…">` attribute **exactly**, apostrophes and all. They were
  read from the XMLs, not typed from memory.
* Validate against OBody's own schema, `SKSE\Plugins\OBody_presetDistributionConfig_schema.json`
  (10,626 B) — `blacklistedPresetsFromRandomDistribution` and `blacklistedPresetsShowInOBodyMenu` are
  both top-level keys in it. OBody logs `Validated … successfully` on load, so a mistake shows up
  immediately in `OBody.log`.
* If the owner wants it *even* tighter later, drop `Observer of the void…` (it has `BigBelly 12`,
  `ChubbyButt 30`) and `CBBE Strong`.
* Trimming a mod's preset **file** is not an option — that would mean editing another mod's folder.
  The blacklist is the correct lever.

### Owner-only steps for Part 2

* **O4** — Presets already handed out are stored per-NPC in the save. After the new JSON is installed:
  **MCM → OBody NG → "Reset all distributed presets"**, in a cell with as few NPCs as possible (a
  player home), then **save, fully exit Skyrim, restart and reload**. OBody's own message insists on
  this: *"You will need to save and exit the game afterwards"* / *"PLEASE SAVE THE GAME AND EXIT THE
  GAME NOW!"*. Without it, already-met NPCs keep their old curvy roll.
* **O5** — The player character is not distributed to; if the player's own body is too curvy, use the
  **Presets list key** (OBody MCM) with nothing in the crosshair and pick e.g. `CBBE Athletic`.

## Does the glue's fixed base mesh fight OBody? Mildly — and a neutral base would be better

* **The morph link is sound.** The glue's NIF points at `femalebodyastrid.tri`, which exists (only
  `LoreRim - BodySlide Output` L146 provides it) and whose three shape names — `3BA`, `3BA_Vagina`,
  `3BA_Anus` — match the NIF's three shapes exactly, with 154/51/27 morphs. OBody will morph this body,
  with more sliders than the CBBE body it replaced (154 vs 97).
* **But morphs are applied *on top of* the base shape, not instead of it.** So whatever Astrid's build
  is, it is baked into every woman before OBody adds a preset, and it shows in the window between a
  cell loading and OBody applying (`Enable Performance Mode` is on by default, and its own MCM warning
  says *"you may see body morphs being applied to the NPCs when you meet them for the first time,
  which can look jarring"*). It is also what NPCs OBody skips are stuck with.
* **How curvy is that base?** Measured, not guessed: `pt4-nude-verify.md` §1.7 recorded the new body's
  bounding box as identical to the CBBE body it replaced to within 0.1 game units at both weights
  (`_1`: x ±28.7, y −11.8…11.6, z 11.4…114.0 for both). So Astrid's build is close to default CBBE
  proportions and is **not** the main driver of "everyone is thick" — the preset pool is. Fixing the
  JSON is the change that matters.
* **A neutral base would still be better**, and the owner can have it cheaply if he ever opens
  BodySlide anyway: build `CBBE 3BBB Body Amazing` (3BA's only body set) with preset
  **`- Zeroed Sliders -`** or **`CBBE Vanilla`**, **Build Morphs ticked**, both weights, then have the
  resulting `femalebody_0/1.nif` (+ `femalebody.tri`) replace the glue's 24 copies. That makes layer 1
  a true blank canvas so OBody's preset is the *only* thing shaping each woman. This is an
  **owner step** (agents must not run BodySlide) and it is optional — do the JSON first and look at
  the result before deciding it is needed.

---

# OTHER FINDINGS (not asked for, worth one line each)

1. **Eight named NPCs are outside the glue's reach.** The WeelBones/SHWB overhauls put their bodies at
   `Meshes\actors\character\<mod-specific folder>\Body\femalebody_*.nif` — Lelaegh (L890), Nirya
   (1790), Birna + Ranmir (1791), Mirabelle (1792), Faralda (1794), Rayya (1795), Susanna (1796),
   Adrianne (1798). The glue does not cover those paths. I parsed Faralda's and Adrianne's: both are
   **nude 3BA physics bodies** with the same 31 bones, so no underwear and no missing jiggle there.
   But both carry `BODYTRI = actors\character\character assets\femalebody.tri` — LoreRim's **old CBBE**
   morph file (13,554-vertex `CBBE` shape) — while the mesh is an 18,436-vertex `3BA` shape. The shape
   names do not match, so **OBody/RaceMenu morphs will silently do nothing for those eight NPCs**;
   they will always wear their author's fixed shape. Harmless, but explains it if some named women
   never change. Fixing it means copying those eight bodies into the glue under their own paths
   (they would then point at `femalebodyastrid.tri` and morph normally) — a separate decision.
2. **TNG is installed but effectively inert for genitals.** `TheNewGentleman.log` reports, for *every*
   playable race: *"The race [0x27438a26: NordRace] cannot have any genitals since their skin cannot be
   recognized! It was last modified by [Synthesis - World.esp]"* — Nord, Breton, Imperial, Redguard,
   Orc, Altmer, Bosmer, Dunmer, Khajiit, Argonian, and many modded races. LoreRim's Synthesis patch
   rewrote the race skin records into a form TNG does not recognise. TNG still ran (`plugin
   TheNewGentleman.dll (… 04000000) loaded correctly`), wrote
   `overwrite\skse\plugins\TheNewGentleman5.ini`, recognised 2,979 armours as covering and equips
   `TNG GenitalCover`, but it will not give anybody genitals in this profile. That is a TNG-vs-LoreRim
   problem, out of scope here, and it does **not** affect females or physics.
3. **`Amon - SK Fix All in One` (L269) still wins `femalebody_1_sk.dds`** with a 516-byte loose file,
   beating `BnP - Female Skin` (L1945). Unchanged from pt4-verify D2; cosmetic subsurface mask only.
4. `CBPCSystem.ini` has `Logging = 0` and `FpsCorrection = 0`. With FPS correction off, bounce
   magnitude varies with framerate — relevant because
   `mods\SSE Display Tweaks\SKSE\Plugins\SSEDisplayTweaks.ini` was changed `FramerateLimit=240` →
   `FramerateLimit=60` on 2026-09-21 at 10:48 (pt4-verify D5, believed to be the owner's own edit).
   Lower FPS with `FpsCorrection = 0` means *less* motion. Worth a mention; not the main cause, and
   `FpsCorrection = 1` can be set in the glue's own `CBPCSystem.ini` copy alongside fix F4 if the
   builder wants to rule it out in the same pass.
5. `hdtsmp64.log` is one line long and `<logLevel>0</logLevel>` is set in the winning `configs.xml`,
   so there is no evidence either way about what SMP *is* driving (hair, cloaks, tails). Nothing in
   this investigation needs it, and SMP demonstrably has no female-body config.

---

# FILES READ / TOOLS WRITTEN

Read-only parses: `skse64.log`, `CBPC-Collision.log`, `hdtsmp64.log`, `OStim.log`, `OBody.log`,
`MCMHelper.log`, `PapyrusUtilDev.log`, `skee64.log`, `TheNewGentleman.log`, `DVSMPSurvivalTweaks.log`,
`AutoPhysicsReset.log`; `profiles\Ultra\modlist.txt`; every `SKSE\Plugins\CBP*` and
`hdtSkinnedMeshConfigs\*` file in the profile; `CBBE 3BA (3BBB)` incl. `3BBB.bsa`; `CBPC` incl.
`cbp.dll` string scan and `CBPCPluginScript.psc`; `OBody Next Generation` incl.
`OBodyNGMCMScript.pex`; `The New Gentleman`; `OStim Standalone`; `LoreRim - MCM and INI Settings`;
`LoreRim - BodySlide Output`; all `SliderPresets\*.xml`; 8 NIF headers.

Helper scripts (scratch only, nothing in the game folder):
* `…\scratch-2026-09-21-8ca12a\…\scratchpad\nifhdr.ps1` — NIF header / string-table parser.
* `C:\Users\Jordan\AppData\Local\Temp\lrg_test\bsax2.py` — BSA v105 + LZ4-frame extractor
  (used on `3BBB.bsa`; output in `…\lrg_test\out3ba\`).
* `C:\Users\Jordan\AppData\Local\Temp\lrg_test\presets.py` + `plist.txt` — BodySlide preset slider
  dump / classification.
