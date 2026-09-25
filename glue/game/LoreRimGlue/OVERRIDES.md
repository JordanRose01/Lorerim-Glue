# LoreRim Glue — what this mod overrides, and why

`LoreRim Glue` sits at **line 3** of `profiles\Ultra\modlist.txt`. Line 1 is the header comment and
line 2 is `The New Gentleman`, which ships no female body and no `SKSE\Plugins` config file — so in
practice **every file in this folder wins over every other mod in the list**. That is the only
mechanism this project uses to change game data: no file inside another mod's folder is ever
edited, no profile file is edited by hand, and BodySlide / Nemesis / LOOT are never run.

One line per file. "Overrides X" means: same relative path as X, higher priority, so ours is the
file the game loads. "New file" means no other mod provides that path.

---

## SKSE\Plugins — CBPC bounce configuration (added 2026-09-21, pt8)

Background in one paragraph: only two mods ship `SKSE\Plugins\CBP*` files — `CBBE 3BA (3BBB)`
(line 1949) and `CBPC` (line 1950). The lower line number wins, so 3BA's copies beat CBPC's.
CBPC merges *every* `Data\SKSE\Plugins\CBPConfig*.txt` it can see, and `amplitude` is its 0..1
master volume on the bounce (CBPC's own `CBPCBounceinterpolationconfig_Test.txt` interpolates
motion to nothing by writing `*.amplitude 0.0`). 3BA's winning amplitude files had the breasts at
**0.1** — one tenth of the motion — which is why there was little or no jiggle.

| file | overrides | why |
|---|---|---|
| `SKSE\Plugins\CBPConfig_BreastAmplitude.txt` | `CBBE 3BA (3BBB)\SKSE\Plugins\CBPConfig_BreastAmplitude.txt` (378 B, all aliases at `0.1`) | pt8 restored CBPC's own default `amplitude 1.0` on all twelve breast aliases, including `ExtraBreast1L..3R`, which is the chain this body's `L/R Breast01-03` bones are mapped to (root-cause fix for "no jiggle"). **pt15 (2026-09-23): all twelve → `0.55`** after the owner found 1.0 "a little too extra / unrealistic". LoreRim installed 3BA with its softest spring preset ("Very Softness") and the lowest strength ("A - a bit or no jiggle", i.e. 0.1), so 1.0 was ten times the volume on the floppiest springs. 3BA's spring tuning in `CBPConfig_3b.txt` / `_BBP.txt` is still untouched. Dial: all twelve together, 0.45 calmer / 0.65 livelier. **pt16 (2026-09-23): values unchanged** - this is the nude / scene volume; a DRESSED actor is additionally governed by CBPC's per-material keys, which are the separate `CBPConfig_3b_armor.txt` dial below. |
| `SKSE\Plugins\CBPConfig_ButtAmplitude.txt` | `CBBE 3BA (3BBB)\SKSE\Plugins\CBPConfig_ButtAmplitude.txt` (76 B, `0.5` = the 3BA installer's `79 CBPC BBL - Less` file) | `LButt` / `RButt` / `Butt`: pt8 set `0.8`; pt15 (2026-09-23) `0.6`, same reason as the breasts. **pt19 (2026-09-24) → `1.0`** = 3BA's own `78 CBPC BBL - Normal` file, the same three values byte for byte (CBPC's default is 1.0 as well). Owner: "there really isnt any ass physics and ass wiggling, even during the sex" - the volume alone could never have fixed that (next row: 0.204 skin weights on the stiffest 3BA spring preset), so the springs and the volume moved together. Two values per line = CBPC's weight-100 / weight-0 pair, in that order: CBPC's Nexus description (cached verbatim in the CBPC mod's `meta.ini`, `nexusDescription`) documents `Breast.stiffness 0.05 0.08` as "both 100 (first one) and 0 (second one) weight bounce settings", a single value serving both weights, and CBPC's own installer (`CBPC - Fomod installer - MAIN FILE ... 1.7.2.7z`, `00 Data\amplitude1-5` / `2_4` / `2_5` / `3_5`) ships `amplitude 1.0 0.3` / `0.75 0.45` / `0.75 0.3` / `0.6 0.3` - full at weight 100, less on flat actors. (Reviewer correction, pt19 review: no file inside the CBPC archive states the order and its collision configs' "0 and 100 weight settings" note is the pipe-separated collider syntax, but the Nexus text and the ladder do; `cbp.dll` keeps a separate `0 weight config[%s][%s] = %s` table.) The two are identical here, so the order changes nothing. Dial: all three together, 0.8 calmer / 1.0 = 3BA Normal (shipped); not above 1.0 - a conservative rule from 3BA's own ladders (its amplitude files stop at 1.0) and from CBPC's own installer ladder (`amplitude1` = 1.0 down to `amplitude5` = 0.3, nothing above 1.0), CBPC's behaviour above 1.0 being undocumented (the 0..1 range CBPC does document is its `ApplyBounceInterpolation(percentage)` Papyrus API, not `amplitude`). |
| `SKSE\Plugins\CBPConfig_butt.txt` | `CBBE 3BA (3BBB)\SKSE\Plugins\CBPConfig_butt.txt` (3108 B, CRLF, 100 keys `LButt`/`RButt` = the 3BA installer's `10 Physics Patch\77 CBPC M - BBL` file byte for byte, sha256 `3b751f808012dacd…`; 3BA's FOMOD name for that file plus the 0.5 amplitude file is "Elastic (Small jiggle)", the option LoreRim chose for belly, butt and leg per 3BA's `meta.ini`) | **pt19 (2026-09-24) - "no ass physics, even during the sex".** Nothing was broken: `NPC L/R Butt` are in the winning XPMSSE skeleton (`XPMSSE Left Hand Sheath Rotation Fix`, line 490), this mod's nude body and the built dresses are weighted to them, CBPC 1.7.2 drives them (`CBPC-Collision.log` 03:19: every config loaded, no error), no SMP xml constrains them (the nude body and the dress meshes carry no xml string; 3BA's five `hdtSkinnedMeshConfigs` xmls and several HDT-SMP Vanilla Armors `furexarot` xmls name `NPC L/R Butt` only as kinematic collision bones, nothing per-vertex), and 3BA's physics manager (the compiled `scripts\mus3bphysicsmanager.pex`, 15,584 B, inside 3BA's `RaceMenuMorphsCBBE.bsa`; its source `scripts\source\mus3bphysicsmanager.psc`, 3,503 B, inside 3BA's `3BBB.bsa` - both archives listed with the pt19 BSA reader, so the research note's `3BBB.bsa` and this row's `RaceMenuMorphsCBBE.bsa` are both true, of different files) returns from `CBPCButts()` before any `StopPhysics` because `SMP.Butt` is `0` in both 3BA's and this mod's `PhysicsManager.json`. The motion was real but sub-visible, by arithmetic: on a 3BA body the butt bones carry very light skin weights - `NPC L/R Butt` peak at **0.204** over about 510 of 18,436 vertices, identical (0.204) on 3BA's own `CBBE 3BA Ref.nif`, so it is the body's design, not a build defect - while the breast chain peaks at 0.75 over three chained bones. Skin travel = bone travel x weight x amplitude; on the Elastic spring the cheek moved 0.1-0.3 units (millimetres), 0.55 units even pinned at its -4.5 clamp. This override is 3BA's own **"Very Soft (Big jiggle)"** preset, verbatim: the installer's `10 Physics Patch\76 CBPC S - BBL\SKSE\Plugins\CBPConfig_butt.txt` (sha256 `7a636a7696cee2d6…`; both hashes re-read from `F:\Modlists\LoreRim\downloads\CBBE 3BA (3BBB)-30174-2-48-1740765899.zip`), all 100 `LButt`/`RButt` keys. Exactly nine value groups differ from the installed Elastic file: stiffness 0.03→0.015, stiffness2 0.0082→0.0041, damping 0.035→0.0175, Zmax/ZminoffsetRot +1.5/-4.5→±3.0, timetick 1.5→2.5, linearX/Y/Z 0.875/0.5/0.75→1.225/0.7/1.05, timeStep 1.2→0.9, collisionMultipler 1→2, collisionMultiplerRot 0→0.2. The LINEAR clamps are identical in both files (X/Y +-3.0, Z +1.5/-4.5, and the `collision*offset` clamps), as are `rotationalX/Y/Z`, `collisionFriction` and `collisionElastic`: the preset changes only the rotational Z clamps and the spring / damping / force numbers, so it does not raise the translation ceiling - it lets the bone reach the existing clamps on a step and ring for about a second (damping halved); the ceiling rises only through the amplitude row above (0.6 -> 1.0). **Plus the same 50 keys under `Butt`**, CBPC's own alias for the same two nodes (`CBPCMasterConfig.txt`: `NPC L Butt=Butt`, `NPC R Butt=Butt`; 3BA's `_3BA` master: `LButt`/`RButt`). CBPC keeps one alias per node (`cbp.pdb`: `configMap` is a node-name→alias map); which duplicate survives is unreadable from the public-only pdb, and `cbp.dll` logs `ConfigMap[%s] = %s = %s` for every parsed line in load order but never which duplicate it kept, so `Logging=1` cannot settle it either - hence all three aliases identical. The one unavoidable difference: `rotationalZ` is mirrored -0.3/+0.3 under `LButt`/`RButt`, and `Butt.rotationalZ` is `0.0` because one alias serves both sides. History under both readings: if CBPC keeps `LButt`/`RButt`, stock was Elastic at 0.5 → pt8 0.8 → pt15 0.6; if it keeps `Butt`, stock was *no spring definition at all* - only `Butt.amplitude 0.5` from 3BA's own amplitude file, no stiffness / damping / clamp key anywhere (3BA's 13-byte `CBPConfig.txt` removed CBPC's defaults: undefined, at best zero, motion) until pt8's `zzGlueDefaults` supplied CBPC's default block (±2.44, timetick 13.33, timeStep 0.40) - the same sub-visible class either way (2.44 x 0.204 = 0.50 units). Honest expectation after this change: the peak-weighted vertex can move at most about 0.92 units (1.3 cm) down / 0.61 sideways (0.204 x the 4.5 / 3.0 linear clamps x 1.0), the average weighted cheek vertex about 0.45 units (0.6 cm; mean butt weight about 0.10 over the 509 vertices above 0.01) - roughly half the breast travel the owner already judged slightly much. Read from the `NiSkinPartition` (the per-vertex data the engine renders; refuter check), the combined weight of all six CBPC-driven butt / thigh bones at any cheek vertex is at most 0.255, so the later leg lever can add at most about a quarter more, and there is no further config lever after that (reweighting the mesh needs Outfit Studio - out of bounds by rule). So: it should be perceptible on a fuller preset, nude in a scene or in a dress walking away; a modest result is the likely outcome, not a guaranteed visible wobble. Limits: CBPC has **no** clothed/armoured volume key for the butt (`cbp.dll` 1.7.2's only per-material keys are `breastClothed/LightArmored/HeavyArmoredAmplitude` + `Pushup`), so dressed = nude volume; about 310 of the 365 built armour meshes and 112 of the 151 built clothes meshes in `LoreRim - BodySlide Output` carry no butt bone at all - judge on a dress (wench, merchant, fine clothes; not miner clothes, whose right cheek is unweighted) or a nude scene. No `Conditions` / `Priority` line: CBPC's `CBPConfig.txt` header says a conditioned bounce file must carry ALL settings itself, i.e. it stops merging. Written LF, no BOM, ASCII like the six sibling glue CBPConfig files (3BA's copy is CRLF; CBPC parses both - the glue's LF amplitude files have changed the in-game result three times). Dial, always all three aliases together: the volume in `CBPConfig_ButtAmplitude.txt` (0.8 calmer, 1.0 shipped, not above 1.0 - the conservative rule in that row); `*.collisionMultipler` 2.0→1.0 if in OStim scenes the cheeks look shoved rather than wobbling - the key acts only on colliders the node does not exclude, and 3BA's `CBPCollisionConfig_Female.txt` (lines 48-49) excludes `NPC L/R Butt`'s own butt, thigh, genital and breast colliders (`@` = "self node" in CBPC's `[AffectedNodes]` syntax), so a walking actor's own body never pushes the cheeks and the doubled multiplier shows only against another actor (or the actor's own hands); if still nothing, the next lever is bone travel in this file (`*.linearX/Y/Z` and the twelve `*max/minoffset` + `collision*offset` clamps, e.g. x1.5 together), because the skin weight is the mesh's and the volume is capped; a glued-on lower cheek/hamstring is a different complaint (the LEG config: `NPC L/R RearThigh`, weight 0.162, still Elastic + 0.5 - same recipe with the installer's `76 CBPC S - BBL\...\CBPConfig_leg.txt` + `CBPConfig_LegAmplitude.txt` 0.8, deliberately not shipped now). A fresh launch always loads an edit; `cbpc reload` is CBPC's documented console command for re-reading the bounce, collision and master configs (Nexus text; `cbp.dll` help fragments `Reload CBPC Master `, ` Collision `, ` Config files` at 920648-920680), though the log's reload blocks never print `Loaded Bounce configs` - if an edit seems ignored, restart the game. |
| `SKSE\Plugins\CBPConfig_BellyAmplitude.txt` | `CBBE 3BA (3BBB)\SKSE\Plugins\CBPConfig_BellyAmplitude.txt` (25 B, `0.5`) | `Belly` **down** to `0.4` (and `HDTBelly` the same) — the owner asked for belly de-emphasised, so this is the one value that goes down rather than up. |
| `SKSE\Plugins\CBPConfig_zzGlueDefaults.txt` | **new file** | Gap filler. 3BA's winning `CBPConfig.txt` is 13 bytes (`Tuning.rate 0`) and replaces CBPC's 6272-byte default, which was the only file defining the aliases `Breast`, `Butt` and `Belly`. After the merge `Breast` and `Butt` had *only* `.amplitude` and `HDTBelly` was undefined, while CBPC's and 3BA's ConfigMaps both still claim the nodes `NPC L/R Breast`, `NPC L/R Butt` and `HDT Belly` under different alias names. This file defines only the aliases nothing else defines — `Breast.*` (and, from pt8 to pt18, `Butt.*`) verbatim from CBPC's `CBPConfig.txt`, `HDTBelly.*` from 3BA's `CBPConfig_belly.txt` — so the result is the same whichever duplicate CBPC keeps. `zz` makes it sort last; it contains no `.amplitude` line, so each alias's volume is still set in exactly one place. **Re-checked in pt15 and left unchanged:** none of its three blocks drives the nude breasts. In the winning `skeleton_female.nif` (XPMSSE) `L Breast01-03` hang under `L Breast00`, a sibling of `NPC L Breast`, not a child of it, so the `Breast.*` springs only move outfits weighted to `NPC L/R Breast`; the breast motion the owner sees comes from 3BA's `ExtraBreast*` values. **pt16 (2026-09-23): still no volume key here.** The `Breast` alias's per-material `breast*Amplitude` / `breast*Pushup` keys went into `CBPConfig_BBP_armor.txt` (below), not into this file, so the "each alias's volume is set in exactly one place, never here" rule holds. **pt19 (2026-09-24): the `Butt.*` block (43 keys, CBPC's defaults) was removed.** All three butt aliases now live in `CBPConfig_butt.txt` (above) at 3BA's "Very Soft" values; left here, the legacy single-value `Butt.stiffness` / `Butt.damping` / `Butt.timetick 13.33` lines would have defined the same alias in two files with undocumented precedence (`cbp.dll` recognises both the `stiffness` and the `stiffnessX` spellings). The file now carries `Breast.*` and `HDTBelly.*` only, header reworded to say so; still no `.amplitude` line. |
| `SKSE\Plugins\CBPCSystem.ini` | `CBPC - Physics with Collisions for SSE and VR\SKSE\Plugins\CBPCSystem.ini` (2382 B) | Verbatim copy. **Since pt15 (2026-09-23) no value differs from CBPC's** — `Logging` is back to `0`. pt8 had set it to `1` so CBPC wrote per-node drive detail to `Documents\My Games\Skyrim Special Edition\SKSE\CBPC-Collision.log` and the playtest could be verified; in one 25-minute session that file reached **710 MB** — log writes on every physics frame. The file is kept rather than deleted so this register stays true and the next physics knob is one edit away. `FpsCorrection` deliberately left at CBPC's `0`; it is the next knob if motion still feels weak, but only one change at a time. |
| `SKSE\Plugins\CBPConfig_3b_armor.txt` | `CBBE 3BA (3BBB)\SKSE\Plugins\CBPConfig_3b_armor.txt` (1796 B, 36 keys: `ExtraBreast1L..3R.breast{Clothed,LightArmored,HeavyArmored}Pushup` all `0.0`, `...Amplitude` all `1.0`) | **pt16 (2026-09-23) - the dressed-walking complaint.** CBPC (cbp.dll 1.7.2) applies a separate per-material breast volume to a DRESSED actor - `breastClothedAmplitude` / `breastLightArmoredAmplitude` / `breastHeavyArmoredAmplitude`, chosen from the worn body item's armour type (`ArmorTypeEnum`, `GetWornForm`, `AS_NAKED` in cbp.pdb; CBPC's Nexus text: "the above parameters effect the physics by the material of the armour"). 3BA's file is byte-identical to its FOMOD variant `97 CBPC NoPushUp` ("Don't add auto push up effect when wearing clothes and armor"): every material at **1.0**, so a dressed woman has always moved at the nude volume. Stock LoreRim was `amplitude 0.1` with material 1.0; pt8 raised amplitude to 1.0 and pt15 to 0.55 without touching this file, and the owner then judged dressed townsfolk walking in Solitude "a little too high in terms of bounciness". This override carries all 36 keys (the 18 push-up lines stay `0.0` - ORefit already does a push-up morph) and sets **Clothed 0.70 / Light 0.50 / Heavy 0.30**. Effective, IF the key multiplies `amplitude` (every shipped 3BA data point says so: its `94 CBPC PushUp` variant ships 0.9/0.8/0.7 unchanged across the strength ladders A=0.1 .. D=1.0, and CBPC's `CBPCAsNaked` keyword means "plain amplitude"): nude 0.55 unchanged, dress 0.385 (about 3.9x stock, 30 % below tonight), leather 0.275, plate 0.165. Under the other reading (material value used alone) tonight's dressed volume was 1.0 - pt15's cut never reached a dressed NPC - and this is still 30 % less, but dressed (0.70) would then sit ABOVE nude (0.55); the in-game discriminator is a dressed woman walking vs the nude body in a scene: dressed livelier than or equal to nude = replace, then the six Clothed lines go to <= 0.50 (Light 0.35, Heavy 0.20). Keyword re-classification checked and closed: none of the 3679 `.esp/.esm/.esl` under `mods\` carries a `CBPCAsNaked/AsClothing/AsLight/AsHeavy L/R` or `CBPCNoPushUp L/R` keyword, so the material comes solely from the body item's armour type. Written LF, no BOM, ASCII like the glue's other CBPConfig files (3BA's copy is CRLF; CBPC reads both - the glue's LF amplitude file has been the live one since pt8). Dial: the six Clothed numbers together, 0.60 calmer / 0.80 livelier; `cbpc reload` applies an edit without a restart. |
| `SKSE\Plugins\CBPConfig_BBP_armor.txt` | `CBBE 3BA (3BBB)\SKSE\Plugins\CBPConfig_BBP_armor.txt` (545 B, the same 6 keys for `LBreast` / `RBreast`, `1.0` / `0.0`) | Same values as `_3b_armor`, for the older single `NPC L/R Breast` bone: 3 clothes + 19 armour pieces in `LoreRim - BodySlide Output` are weighted to it, and only the farmer robe (`farmclothes03`), the mudcrab and the 1st-person dragonscale are on it alone - so judge the fix on townsfolk in clothes and on guards, not on those. Carries 3BA's 12 keys plus the same 6 for `Breast`, CBPC's own alias for the same two bones (CBPC's ConfigMap: `NPC L/R Breast=Breast`; 3BA's: `LBreast` / `RBreast`; which duplicate CBPC keeps is unknown, so both aliases get the value). The `Breast` lines live here and not in `CBPConfig_zzGlueDefaults.txt` so that file keeps its "no volume key" rule and every material dial is in the two `_armor` files. Keep equal to `_3b_armor`. |

## SKSE\Plugins — 3BA physics manager (added 2026-09-21, pt8)

| file | overrides | why |
|---|---|---|
| `SKSE\Plugins\StorageUtilData\CBBE 3BA\PhysicsManager.json` | `CBBE 3BA (3BBB)\SKSE\Plugins\StorageUtilData\CBBE 3BA\PhysicsManager.json` (169 B, the only copy anywhere) | 3BA shipped `SMP.Breast = 1`, i.e. "the breasts belong to HDT-SMP". On this setup SMP covers nothing on the female body: the body NIF carries no physics-XML string and 3BA's `defaultBBPs.xml` maps no female body at all. 3BA's `mus3bphysicsmanager.psc` reads this key into `BreastSMP` and, whenever an actor is on its `ActorPhysicsList`, calls `CBPCPluginScript.StopPhysics` on `L/R Breast01-03` and snaps them to the rest pose — re-applied on every `OnPlayerLoadGame`. All six values set to `0` so `CBPCBreasts()` returns immediately and `StopPhysics` can never be called. JSON has no comment syntax, hence this note. Actors already on that list live in the **save**, not on disk — see the owner step about clearing the actor list. **pt19 (2026-09-24), butt check:** the compiled script does exist and runs - `scripts\mus3bphysicsmanager.pex` (15,584 B) is inside 3BA's `RaceMenuMorphsCBBE.bsa`, loaded by the active `RaceMenuMorphsCBBE.esp` (3BA's copy of that BSA beats CBBE's, modlist line 1949 vs 1951) - but its `CBPCButts()` returns before any `StopPhysics` unless `ButtSMP`, which this file's `SMP.Butt = 0` (3BA shipped `0` there too) keeps false on every load. So it cannot be what hides the butt, and the butt change needs no actor-list step. |

## SKSE\Plugins — OBody NG body-shape distribution (added 2026-09-21 pt8, rewritten 2026-09-22 pt9)

| file | overrides | why |
|---|---|---|
| `SKSE\Plugins\OBody_presetDistributionConfig.json` | `OBody Next Generation\SKSE\Plugins\OBody_presetDistributionConfig.json` (854 B, line 5, the only copy anywhere) | OBody's file is completely stock: every distribution map empty, so every female NPC gets a uniformly random pick from the whole BodySlide preset pool — and about half that pool is curvy/thick/belly presets. **pt8** curated the pool by extending `blacklistedPresetsFromRandomDistribution`. **pt9** keeps that curation but puts curvy bodies back deliberately instead of accidentally: a rare share of the random roll plus targeted assignment to women a fuller figure suits. See the four blocks below. `blacklistedPresetsShowInOBodyMenu` stays `true`, so every excluded preset is still hand-pickable from OBody's preset-list hotkey. Validated against OBody's own `OBody_presetDistributionConfig_schema.json` — 21/21 keys present, no unknown key (`additionalProperties: false`), every FormID matches `^[0-9A-Fa-f]{3,8}$`. No mod's preset XML is edited. |

What the pt9 file actually says, and why each part is safe (all of it verified against
OBody NG 4.4.3's own source — `src/Body/Body.cpp::GenerateActorBody`,
`src/JSONParser/JSONParser.cpp`, `src/PresetManager/PresetManager.cpp`):

* **`npcFormID` — 36 named women**, keyed by plugin + local FormID (`Skyrim.esm` 29,
  `Dawnguard.esm` 4, `Dragonborn.esm` 3), each with a three-name list so the exact shape still
  varies. FormID keys, not the `npc` name map, because `npc` matches
  `actorBase->GetName()` — the *display* name, which `NPCs Names Distributor` and the NPC
  overhauls in this list are free to change. Every FormID was re-read from the ESM and confirmed
  to be a female `NPC_` record. None of the eight NPCs whose bodies this mod cannot reach
  (Lelaegh, Nirya, Birna, Mirabelle, Faralda, Rayya, Susanna, Adrianne) is in the list — their
  morphs are inert, so an assignment would silently do nothing.
* **`factionFemale` — two factions.** `MarkarthTempleofDibellaFaction` (5 vanilla females) and
  `JobInnkeeperFaction` (12 vanilla females, plus whatever modded inns use it). Both EditorIDs
  were confirmed to exist. Faction rules are checked *after* `npcFormID`, so a named woman always
  wins. Each list mixes curvy and natural presets so a faction does not become uniform.
* **`raceFemale` — `OrcRace` / `OrcRaceVampire` only.** Six names, two of them curvy, the rest
  athletic/vanilla: Orsimer women read as stockier and stronger, and this is the cheapest way to
  say that without naming twenty stronghold NPCs. Every other race falls through to the curated
  random pool, which is the safe default.
* **`blacklistedRacesFemale` / `Male` — child and elder races.** Stock is `["ElderRace"]`. Added
  `ElderRaceVampire` and the six child races present in this load order (`NordRaceChild`,
  `BretonRaceChild`, `ImperialRaceChild`, `RedguardRaceChild` and Kidmer's four
  `urshug_*RaceChild`). Child bodies use a different mesh and `.tri`, so OBody's morphs were
  already inert on them — this makes it explicit, and it is what stops the Dibella faction rule
  from ever reaching Fjotra, who is `NordRaceChild`.
* **`blacklistedPresetsFromRandomDistribution` — 15 entries**, of which 12 match an installed
  preset exactly (rapidjson compares case-**sensitively**, so the spelling matters) and 3 are
  OBody's own stock defensive spellings. It now also excludes `TNG Default` (an all-zero stub
  preset `The New Gentleman` leaves in `SliderPresets`, i.e. a second "Zeroed Sliders" that was
  taking a tenth of every roll) and the two stronger glue presets. pt8's entries for
  `OutfitTesting`, `CBBE Curvy (Outfit)` and `CBBE Slim (Outfit)` were **removed as no-ops**:
  OBody skips `outfittesting.xml` on its filename and skips any preset whose *name* contains
  "outfit", so those three never reach the pool in the first place.
* **The resulting random pool is 9 female presets** — `CBBE Vanilla`, `CBBE SevenBase`,
  `CBBE Petite`, `CBBE Slim`, `CBBE Athletic`, `CBBE Strong`,
  `Skinny Days - Birna's Body Preset WeelBones`,
  `Observer of the void - Paranoiac's Body [By WeelBones]` and `LoreRim - Curvy Soft`. One in
  nine, about 11%, is the mild curvy body; the rest are vanilla/slim/athletic and none has a
  `Belly` slider above 12. The single male preset is untouched.
* **Explicitly named presets bypass the blacklist.** `GetRandomPresetByName` searches
  `allFemalePresets`, which is the non-blacklisted set *plus* the blacklisted set, so
  `CBBE Curvy` and the two stronger glue presets can still be handed to a named NPC while staying
  out of the random roll. That is the whole mechanism this file leans on.

## CalienteTools — three curvy BodySlide presets (added 2026-09-22, pt9)

| file | overrides | why |
|---|---|---|
| `CalienteTools\BodySlide\SliderPresets\LoreRimGlue.xml` | **new file** — no other mod ships this path (the merged `SliderPresets` folder has 9 XMLs, this is the 10th) | Before pt9 the entire load order contained exactly **one** non-extreme curvy female preset, `CBBE Curvy`. Handing it to thirty-odd women would have made them all the same shape, which defeats the point. This file adds `LoreRim - Curvy Soft`, `LoreRim - Curvy Hourglass` and `LoreRim - Curvy Statuesque`: full figures with **no** `Belly`, `BigBelly`, `Chubby*` or `PregnancyBelly` slider anywhere. Every slider name in it was checked against the 154 morphs on the `3BA` shape of the winning `femalebodyastrid.tri`, so they apply as RaceMenu morphs with **no BodySlide run** — OBody reads `Data\CalienteTools\BodySlide\SliderPresets\*.xml` directly at load. The values are not invented: *Soft* is the midpoint between the shipped `CBBE Slim` and `CBBE Curvy`, *Hourglass* is `CBBE Curvy` with the bust dialled back from 80 to 60 and rounder hips, *Statuesque* is *Soft* plus ~60% of `CBBE Strong`'s muscle block. `set="CBBE 3BBB Body Amazing"` is 3BA's own slider set (the same one `Warmaiden…` uses), which is what makes OBody treat them as 3BA female presets. The filename and all three preset names avoid OBody's filter words (cloth/outfit/nevernude/bikini/feet/hands/push/cleavage/armor) and the set name avoids its male markers (himbo/talos/sam/sos/savren). Deleting this one file reverts the whole idea. |

## SKSE\Plugins — Auto Physics Reset triggers (added 2026-09-21, pt8)

| file | overrides | why |
|---|---|---|
| `SKSE\Plugins\AutoPhysicsReset.ini` | `Auto Physics Reset\SKSE\Plugins\AutoPhysicsReset.ini` (3700 B, line 3961) | That mod exists to un-stick CBPC/SMP physics after the game drops an actor into a synchronised or scripted animation — the old "draw and sheathe your weapon to get the jiggle back" workaround, automated. Sync animations, furniture and riding were already enabled, but `bScriptedSceneEnter` / `bScriptedSceneExit` — "Scripted Scenes (Loss of control)", which is what an OStim scene looks like to the game — were the one pair left `false`. That matches the reported symptom (physics in the world, none in the scene) exactly. Verbatim copy with those two values flipped to `true`; the author's 0.5 s enter delay is unchanged, and the two lines a previous editor annotated "User set to false" (`bCellTransitionReset`, `bFollowerSupport`) are left alone. **Secondary, easily reverted:** if a scene ever stutters or snaps at the moment it starts, set `bScriptedSceneEnter` back to `false` and keep `bScriptedSceneExit = true`. |

## SKSE\Plugins — the three dialogue settings that are not ours (added 2026-09-22, pt9)

These are the settings PLAYTEST8_NOTES.md section 2 asked the owner to change by hand in three other
mods. Nobody should have to edit another mod's folder, so each one is a **full copy of whichever file
the game loads today**, placed here with only the named keys changed and a `;` header naming the
source. All three paths are `Data\SKSE\Plugins\<name>.ini`, which every one of these plugins resolves
through the game's virtual file system, so MO2's priority alone decides the winner — no BSA can
serve a file at this path and none of the three mods reads from anywhere else.

Verified against `profiles\Ultra\modlist.txt` after installing: for all three paths `LoreRim Glue`
(line 3) is the only provider above the previous winner, the `overwrite` folder provides none of
them, and the one enabled mod above us, `The New Gentleman` (line 2), ships no `.ini` at all except
MO2's own `meta.ini`.

| file | overrides | why |
|---|---|---|
| `SKSE\Plugins\SmartTalk.ini` | `Smart Talk (Dialogue Menu Enhancer)\SKSE\Plugins\SmartTalk.ini` (5668 B, line 3845, the only copy in the load order) | Three values out of 128 lines: `bSkipImmediateOnInput` 1 → 0, `bHoldToSkip` 1 → 0, `bDBVOIntegration` 1 → 0. `iPapyrusHandle` is left at **3** and must stay there; `bSkipOnInteraction` was already 0. This is a hard precondition, not a preference: `LRG_DlgUI.SmartTalkOffender()` reads this exact path (`MiscUtil.ReadFromFile("Data/SKSE/Plugins/SmartTalk.ini")`) and `bMenuless` may not leave dry run while any of the three skip keys is above 0 (design D-21). Smart Talk has **no MCM anywhere in this list**, so the ini is the only control. Its DLL does expose `SetIniSettingsValue*` Papyrus natives that write the ini back, but nothing in this load order calls them. The glue's own `IniInt()` only counts a key at the **start of a line**, so the key names inside the header comment are inert. |
| `SKSE\Plugins\AlternateConversationCamera.ini` | `LoreRim - MCM and INI Settings\SKSE\Plugins\AlternateConversationCamera.ini` (3502 B, **line 145**) — which already beat `Improved Alternate Conversation Camera`'s own copy (3509 B, line 3712) | Two values: `bForceFirstPerson` **1** → 0 and `bHideDialogueMenu` **1** → 0. Decision **D2**. The copy that matters is LoreRim's, not the mod author's — the author ships `bForceFirstPerson=0` already, LoreRim turns it **on**, so reading the mod's own file would have said "nothing to do". With `bForceFirstPerson=1` every conversation snapped to first person and locked the camera on the NPC; with `bHideDialogueMenu=1` IACC rewrites the topic list's visibility every frame and fights the glue's own hide. Side effect of the pair: `bForceThirdPerson` is 0 in LoreRim's file, so with both force flags now 0 **IACC forces no point of view at all** and a conversation stays in whatever view you were already in. `bSwitchTarget` is 0 and must stay 0 (design C14: it makes IACC rewrite the menu state in both directions and the glue's readiness gate goes MANUAL). **Runtime writes:** IACC's DLL imports `WritePrivateProfileStringW` and its MCM setters (`IACC.SetHideDialogueMenu` etc., all native) write this ini back; USVFS redirects that write into the winning copy, i.e. **this file**. So a change made in IACC's MCM in game edits our file rather than LoreRim's — expected, but it means this file can drift from what was shipped. The MCM only ever *reads* through the DLL, so it never pushes a stale saved value back over ours at load. The glue itself writes no IACC setting: `bIaccToggle` ships `0` in our own `settings.ini`. |
| `SKSE\Plugins\Fuz Ro D'oh.ini` | `LoreRim - MCM and INI Settings\SKSE\Plugins\Fuz Ro D'oh.ini` (58 B, line 145) | One value: `WordsPerSecondSilence` 2 → **3**; `SkipEmptyResponses` left at 1. `Fuz Ro D-oh - Silent Voice` (line 3682) ships **no ini of its own** — the DLL's only config path is the string `Data\SKSE\Plugins\Fuz Ro D'oh.ini` — so LoreRim's was the file in force. This number divides an unvoiced line's word count into seconds of forced silence, so 2 → 3 makes every unvoiced line a third shorter. The header comment sits **above** `[General]` on purpose: the DLL uses `WritePrivateProfileSectionA`, which would replace the whole of a section it rewrites, and anything before the first section header survives that. |

### Helmet Toggle 2 — deliberately NOT overridden

PLAYTEST8_NOTES.md asked for `HT_EnableDialogue` off "only once menuless is really on". No file was
shipped, for four reasons:

1. **It is already off.** The MCM control is `iEnableDialogue:Main`, and it is `0` in both places it
   is defined on disk — `Helmet Toggle 2\MCM\Config\Helmet Toggle 2\config.json`
   (`"defaultValue": 0`) and `…\MCM\Config\Helmet Toggle 2\settings.ini` (`iEnableDialogue=0`).
2. **The modlist's own preset does not turn it on.** `LoreRim - MCM and INI Settings\MCM\Settings\
   Helmet Toggle 2.ini` carries 14 keys and `iEnableDialogue` is not one of them, so the default
   stands.
3. **The mod force-resets it anyway**: `HT_MCM.psc` `OnConfigInit()` calls
   `HT_EnableDialogue.SetValue(0)` with the comment "Reset here because it only registers on game
   load anyway."
4. **An ini could not be trusted to control it even if it were on.** This is an MCM Helper setting,
   not a plugin ini: the live value is the global `HT_EnableDialogue` in the **save**. A file we drop
   in only changes what is read at initialisation, so on an existing save the MCM is the only
   reliable switch. Shipping a copy of someone else's saved-settings file would also have frozen the
   modlist author's other 13 keys inside our mod for no gain.

If the owner ever sees his helmet pop off and back on every conversation turn, the fix is
**MCM → Helmet Toggle 2 → the Dialogue toggle → off**, not a file.

---

## meshes — the nude female body (added earlier, pt4; unchanged by pt8)

| file | overrides | why |
|---|---|---|
| `meshes\actors\character\character assets\femalebody_0.nif` / `_1.nif` | `LoreRim - BodySlide Output\…\femalebody_0/1.nif` (line 146), and by priority also the per-NPC copies in `Belethor's General Goods WeelBones' Replacers`, seven WeelBones/SHWB overhauls and `Bijin Wives SE` | LoreRim's own `femalebody` is an underwear body (8 shapes including Bra/BraStraps/Panty) with only 4 physics-relevant bones. These are LoreRim's own nude CBBE 3BA body, copied unmodified from `femalebodyastrid_0/1.nif` in the same BodySlide Output mod: 3 shapes (`3BA`, `3BA_Vagina`, `3BA_Anus`) and 31 physics bones (`L/R Breast01-03`, `NPC L/R Butt`, `NPC Belly`, thighs, calves, vagina and anus chains), all of which exist in the winning `skeleton_female.nif`. Binaries are copied, never edited. |
| the same two files in eleven per-NPC folders: `Bijin NPCs`, `Bijin Warmaidens`, `Bijin Wives`, `Chaconne`, `Elisif`, `Minazuki`, `Serana`, `Succubus-San`, `Toccata`, `Valerica`, `Vivace` | the same paths in whichever NPC-overhaul mod provides them | Those overhauls point their NPCs at a body under their own folder, so overriding `character assets` alone would leave those women in underwear. Eight further named NPCs (Lelaegh, Nirya, Birna, Mirabelle, Faralda, Rayya, Susanna, Adrianne) keep their author's body under a differently-named folder that this mod does not cover — they are already nude 3BA physics bodies, so jiggle is unaffected; only their RaceMenu/OBody morphs are inert, because their NIFs point at LoreRim's old `femalebody.tri` whose shape name does not match. Left as-is on purpose. |

The `-NoNudeBody` switch on `tools\install_mo2.ps1` excludes exactly this `meshes\` tree and nothing
else (`robocopy … /XD <source>\meshes`), so every `SKSE\Plugins` and `CalienteTools\` file above is
installed either way. No change to that switch was needed for pt8 or pt9 — which is correct: the
physics config, the body-shape distribution and the three dialogue inis must apply whether or not
the nude body is enabled, and all three of the pt9 inis live under the existing `SKSE\Plugins`
folder, so no new top-level folder was added.

### textures — deliberately NOT overridden (pt15, 2026-09-23)

The owner reported invisible nipples in OStim scenes. This mod ships **no texture** for it, because
the textures are not the cause:

* The body's texture paths are `textures\actors\character\female\femalebody_1{,_msn,_s,_sk}.dds`.
  The diffuse, normal and specular all come from `BnP - Female Skin` (`BnP - Skinfix - Textures.bsa`,
  4096² BC7). Only `_sk` comes from `Amon - SK Fix All in One` as a loose file: 16×16, solid black,
  which switches subsurface scattering off. The BnP diffuse has painted areolas, and the model-space
  normal map has sculpted nipples. The nipple vertices of this mesh's `3BA` shape land on those
  areolas in UV space: checked by plotting all 18,436 UVs over the texture.
* Every other body diffuse that an NPC skin points this mesh at (`ARMA` → `NAM1` texture set,
  including `NPC Appearances Merged.esp` and the Easy NPC output) was also checked. That is 43 adult
  human/elf textures, and all 43 have painted areolas in the same CBBE layout. Six have smaller,
  paler ones (Auri, AnimaNera, Wolven Widow, Tilael, Vigilant, the DB initiates). No NeverNude or
  smooth texture set is assigned anywhere.
* The real cause is a body morph. OBody NG's **ORefit** adds an `OClothe` morph set to every
  clothed NPC, and with "ORefit nipple morphing" on (OBody.esp's default) that set contains
  `NipBGone 1.0`, `AreolaSize -0.3` and `NipplePerkManga -0.25`. The last save carries it on
  132 of the 134 morphed actors. OBody logged nothing for Lisette during the 04:25-04:27 scene,
  so the clothed morphs were never removed when OStim undressed her. The fix is an OBody MCM
  toggle (owner step, `research\pt15-body.md`), not a file.

## The mod's own content (not overrides)

`LoreRimGlue.esp`, `Seq\LoreRimGlue.seq`, `Scripts\LRG*.pex`, `Source\Scripts\LRG*.psc`,
`MCM\Config\LoreRimGlue\config.json` and `MCM\Config\LoreRimGlue\settings.ini` are this project's
own files. They add content; they override nothing.

**`config.json` is written without a UTF-8 BOM (fixed pt15, 2026-09-23).** MCM Helper reads it with RapidJSON's plain
`FileReadStream` + `Reader::Parse`, which does not skip a byte-order mark. With the BOM, every load logged
`ConfigStore.cpp(100): [warning] Failed to parse config for LoreRimGlue` in `SKSE\MCMHelper.log` and the LoreRim Glue MCM
showed MCM Helper's error page instead of its twelve pages. Settings still worked, because `settings.ini` is read by a
separate store. Any tool that rewrites `config.json` must write UTF-8 **without** BOM (PowerShell 5.1's
`Set-Content -Encoding UTF8` / `Out-File` add one).
