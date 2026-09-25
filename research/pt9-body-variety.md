# pt9 — curvy bodies back in, on purpose: rare in the roll, deliberate on the right women

Designed, built, validated and installed 2026-09-22.

**Everything changed lives in `LoreRim Glue`** (line 3 of `profiles\Ultra\modlist.txt`, so it wins
over everything). **Two files** were written: one rewritten override and one brand-new file. No other
mod folder was touched, no profile file was edited, BodySlide / Nemesis / LOOT were not run.

| file (relative to `F:\Modlists\LoreRim\mods\LoreRim Glue`) | status | what it does |
|---|---|---|
| `SKSE\Plugins\OBody_presetDistributionConfig.json` | **rewritten** (1115 → 5407 B) | 36 named women, 2 factions, the Orc races, a curated random pool with one curvy entry, child/elder races blacklisted |
| `CalienteTools\BodySlide\SliderPresets\LoreRimGlue.xml` | **new** (12783 B) | three curvy-but-not-extreme presets: `LoreRim - Curvy Soft`, `LoreRim - Curvy Hourglass`, `LoreRim - Curvy Statuesque` |
| `OVERRIDES.md` | updated | the register entry for both |

---

## 0. The answer to the owner's question, in one paragraph

**No mod in this list decides this for you.** There is no OBody preset-distribution mod, no *Bodies
of Skyrim*, no BodyGen, no SPID body distributor anywhere in the 4086-line modlist (§1). But **OBody
NG can do it itself** — its JSON supports per-NPC (by name *and* by plugin + FormID), per-faction,
per-plugin and per-race assignment, and an explicitly named preset **bypasses the random-roll
blacklist**, which is exactly the lever this needed (§2). There is **no weight key**, but a preset
listed twice in a rule's list is picked twice as often, and the *global* share is set by what is left
in the random pool (§2.4). So: the extremes stay out of the roll entirely; one deliberately mild
curvy body is **1 in 9** of the random roll (~11%); and **36 named women, every innkeeper, every
Dibella priestess and every Orc woman** get a fuller figure chosen to suit them. Because the load
order contained exactly **one** non-extreme curvy preset (`CBBE Curvy`), handing it to thirty-six
women would have made them all identical — so the glue now ships **three more** (§3.3), built from
the shipped presets' own numbers and verified against the body's morph list, with **no belly slider
anywhere** and **no BodySlide run required**.

---

## 1. Is there already a mod that does this? **No.**

Checked, and negative on all counts:

* **`OBody_presetDistributionConfig.json` exists in exactly two places** under `F:\Modlists\LoreRim`:
  `OBody Next Generation\SKSE\Plugins\` (stock, 854 B) and `LoreRim Glue\SKSE\Plugins\` (ours).
  Nothing in `overwrite\`. So no third-party distribution config is in play.
* **No distribution mod in the modlist.** Grepping all 4086 lines for `obody`, `bodies of skyrim`,
  `bodygen`, `distribut` and `SPID` returns OBody NG itself (line 5), `Spell Perk Item Distributor`
  (4010) and a long tail of *armour/clothing* SPID mods — no body-shape distributor. The one
  near-miss is **`Racial Body Morphs Redux SSE AE - FKDRS`**, which is a RaceMenu/NiOverride
  height-and-morph-by-race mod, not an OBody preset distributor; it is not touched here.
* **No preset pack** either: the entire merged `Data\CalienteTools\BodySlide\SliderPresets\` folder
  was, before today, **9 XML files** from 7 mods (§3.1). 3BA ships none.

**Correction to `pt8-body-fix.md`:** the earlier round listed `OutfitTesting`, `CBBE Curvy (Outfit)`
and `CBBE Slim (Outfit)` as part of the random pool. They never were — OBody NG skips a whole preset
**file** whose path contains `cloth / outfit / nevernude / bikini / feet / hands / push / cleavage /
armor`, and skips any individual preset whose **name** contains one of those words
(`PresetManager::IsClothedSet`, case-insensitive). `outfittesting.xml` is skipped on its filename;
the two `(Outfit)` presets are skipped on their names. Those three blacklist lines were no-ops and
have been removed. The other seven pt8 lines were real and are kept.

---

## 2. What OBody NG 4.4.3 can actually do — established from the DLL, the PDB, the Papyrus source and the published C++

Read out of `OBody.dll` / `OBody.pdb` / `OBodyConfigModel.py` /
`OBody_presetDistributionConfig_schema.json` / `Source\Scripts\*.psc` on disk, and cross-checked
against the matching functions in `github.com/Aietos/OBody-NG` (the repo the mod's own `meta.ini`
points at). Nothing below is guessed.

### 2.1 The resolution order — first match wins

`Body::OBody::GenerateActorBody` runs exactly this sequence for every actor, once:

| # | step | source of truth |
|---|---|---|
| 0 | **already processed?** → stop | `IsProcessed()` = does the actor carry body-morph key `obody_processed` (or `obody_processedN` after a reset) |
| 1 | **`npcFormID`, then `npc`** | `JSONParser::GetNPCPreset(actorName, actorID, female)` |
| 2 | **blacklist by plugin or race** → stop, mark blacklisted | `IsNPCBlacklistedGlobally(actor, raceEditorID, female)` |
| 3 | **`factionFemale` / `factionMale`** | `GetNPCFactionPreset` |
| 4 | **`npcPluginFemale` / `npcPluginMale`** | `GetNPCPluginPreset` |
| 5 | **`raceFemale` / `raceMale`** | `GetNPCRacePreset` |
| 6 | **random** from the blacklist-filtered pool | `PresetManager::GetRandomPreset(container.femalePresets)` |

A rule earlier in the table always beats a later one; there is no merging and no scoring. Note that
**step 2 runs after step 1**, so an NPC named in `npcFormID` still gets her preset even if her race is
blacklisted — and that a race blacklist stops the faction rule from ever reaching her.

The two log lines to look for are `Trying to find and apply preset to {}` and, only when steps 1–5 all
missed, `No preset defined for this actor, getting it randomly`.

### 2.2 `npc` matches the **display name**; `npcFormID` matches plugin + local FormID

* `auto actorName{actorBase->GetName()};` — the **full/display name** of the actor base, not the
  EditorID. (`GetNPCPreset` then does a plain `FindMember(actorName)` on the `npc` object.) So the
  documented examples `"Mjoll the Lioness"`, `"Haelga"`, `"Temba Wide-Arm"` are display names.
* `npcFormID` is `{ "<plugin file>": { "<hex>": [presets] } }`. `ProcessNPCsFormID` right-pads the hex
  to 8 digits, strips the **first 2** digits for a full plugin (**first 5** for an ESL/light one —
  `DiscardFormDigits(..., mod->IsLight())`), then `LookupForm(hex, pluginName)`. A plugin that is not
  loaded is dropped with `removed '<name>'` in the log; an unresolvable FormID logs
  `<id> is not a valid key!`.

**This build uses `npcFormID` exclusively and leaves `npc` empty**, because a display name is not
stable in this list: `NPCs Names Distributor` (modlist lines 1235–1237) plus a dozen NPC overhauls
are all free to rewrite `FULL`, while a FormID cannot change.

### 2.3 Race and faction keys

* `raceFemale` / `blacklistedRacesFemale` keys are **race EditorIDs** —
  `stl::get_editorID(actorBase->GetRace())`, matched with rapidjson `HasMember`, i.e.
  **case-sensitive** (`"NordRace"`, `"OrcRace"`, `"OrcRaceVampire"`, …).
* `factionFemale` keys are **faction EditorIDs**, resolved with
  `RE::TESFaction::LookupByEditorID(key)`. The parser walks the JSON's members **in file order** and
  returns on the **first** faction the NPC belongs to — so for an NPC in two listed factions, the one
  written first in the file wins.
* Race keys that match no loaded race are silently dropped by `JSONParser::FilterOutNonLoaded`
  (it handles `raceFemale|raceMale|blacklistedRacesFemale|blacklistedRacesMale`), so a stale race
  name is harmless.

### 2.4 Weighting: **no weight key, but duplicates work**

There is no weight field anywhere in the schema — the 21 top-level keys are fixed and
`additionalProperties: false`, so inventing one would make OBody refuse the file
(`Seems like there is an error when validating the config using the json schema`).

What does work:

* `GetRandomPresetByName` picks **a uniformly random entry from the list of names** you gave
  (`a_presetNames[stl::random(0, size)]`, and `stl::random` is documented in-source as `[min, max)`,
  so no off-by-one). If the chosen name resolves to no preset it erases that entry and recurses.
  **Therefore listing a preset twice in a list doubles its chance**, and a typo degrades gracefully
  instead of failing.
* The **global** rare share is set by what remains in `femalePresets` — i.e. by
  `blacklistedPresetsFromRandomDistribution`. With a pool of 9, one curvy entry is 1/9.

One implementation detail worth knowing: `GetNPCFactionPreset`, `GetNPCPluginPreset`,
`GetNPCRacePreset` and `ProcessNPCsFormID` all build their name vector with the **size constructor**
(`std::vector<std::string_view> copy{value.Size()}`) and then `emplace_back`, so each list is
silently padded with an equal number of empty entries. It is harmless — the empties resolve to
nothing, get erased one at a time and the function recurses — and it does not bias the real names
relative to each other. It does mean the log will show a few `Looking for preset: ` lines with an
empty name. Not a problem, just not a bug in our config.

### 2.5 A named preset **beats** the blacklist — the key fact this design rests on

`GeneratePresets` splits every preset into `femalePresets` (not blacklisted) and
`blacklistedFemalePresets`, then builds `allFemalePresets = femalePresets + blacklistedFemalePresets`.

* Step 6 (random) is handed **`femalePresets`** — blacklisted presets can never be rolled.
* Steps 1/3/4/5 (named lists) are handed **`allFemalePresets`** — a blacklisted preset **can** be
  assigned by name.

So `CBBE Curvy` and the two stronger glue presets can be given to a named woman while remaining
impossible to meet at random. Two more details from the same function:

* the blacklist is compared with rapidjson value equality — **case-sensitive**, spelling must be
  exact — whereas the *assignment* lists are matched with `stl::cmp` = `boost::iequals`, i.e.
  **case-insensitive**;
* any preset whose name ends in `-Refit` is blacklisted automatically;
* male vs female is decided by the preset's **`set` attribute**: `IsFemalePreset` returns false if the
  set name contains `himbo / talos / sam / sos / savren` (case-insensitive). That is why
  `Sentence - Ranmir Himbo Body` (set `HIMBO Body - SOS High Poly`) is the one male preset here.

### 2.6 Does an assignment survive "Reset all distributed presets"? **Yes — that is the point of it**

`OBodyNGScript.ResetDistribution()` does not delete anything. It increments a counter and switches
the **distribution key** (`obody_processed` → `obody_processed1` → `…`) via
`OBodyNative.SetDistributionKey`. Since `IsProcessed()` tests for that key as a body morph, every
actor in the save instantly counts as unprocessed and `GenerateActorBody` runs again from step 1 —
re-reading the JSON. OBody's own MCM help text says so in as many words: *"Reset the presets that
have been distributed to NPCs. This will respect the rules you set in the JSON configuration."*

The same reset also clears any preset you picked by hand with the O key — that is unavoidable and
expected.

---

## 3. The presets: what is installed, and what is now in which bucket

### 3.1 The real pool, after OBody's own filtering

The merged `SliderPresets` folder had 9 XML files. `outfittesting.xml` is skipped whole on its
filename; `CBBE Curvy (Outfit)` and `CBBE Slim (Outfit)` are skipped on their names. What is left is
**18 female + 1 male** presets, which matches the `Female presets: 17` in last night's `OBody.log`
exactly once you account for pt8 having already blacklisted one (`- Zeroed Sliders -`) — i.e. 18
female presets existed, 17 were rollable. The glue's new XML makes it **21 female + 1 male**.

| preset | source mod | the numbers that matter (low weight / high weight) | bucket |
|---|---|---|---|
| `CBBE Vanilla` | CBBE | `VanillaSSELo 100/-`, `VanillaSSEHi -/100` | **natural — keep in the roll** |
| `CBBE SevenBase` | CBBE | `7B Lower 30/70`, `7B Upper 30/70` | **natural** |
| `CBBE Petite` | CBBE | `BreastsSmall 80/25`, `ButtSmall 70/40`, `Belly -25/-` | **natural** |
| `CBBE Slim` | CBBE | `BreastsSmall 60/-`, `ButtSmall 35/-`, `Butt 50/50` | **natural** |
| `CBBE Athletic` | CBBE | `MuscleAbs 70/100`, `ButtSmall 80/35`, `Breasts -/30` | **natural** |
| `CBBE Strong` | CBBE | `MuscleArms/Butt/Legs 75/75`, `BigButt 10/20` | **natural** |
| `Skinny Days - Birna's Body Preset WeelBones` | Remnants (1791) | `HipBone 100`, `Thighs 14`, no belly | **natural** |
| `Observer of the void - Paranoiac's Body [By WeelBones]` | Paranoiac (1790) | `BigBelly 12`, `ChubbyButt 30`, `ChubbyWaist 30` | **natural** (mildest thick; left in) |
| **`LoreRim - Curvy Soft`** | **glue (new)** | `Breasts 0/45`, `Butt 50/65`, `AppleCheeks 0/20`, **no belly slider** | **curvy-moderate — the 1-in-9 random entry** |
| **`LoreRim - Curvy Hourglass`** | **glue (new)** | `Breasts 0/60`, `Butt 50/75`, `Hips 12/22`, `RoundAss 25/40`, **no belly** | **curvy-moderate — assigned only** |
| **`LoreRim - Curvy Statuesque`** | **glue (new)** | `Breasts 0/40`, `Butt 50/68` + 60% of `CBBE Strong`'s muscle block | **curvy-moderate — assigned only** |
| `CBBE Curvy` | CBBE | `Breasts -/80`, `Butt 50/80`, `AppleCheeks -/35`, no belly | **curvy-moderate — assigned only** (too strong for a random roll) |
| `TNG Default` | The New Gentleman (2) | every slider `0` | **never distributed** (an unnamed second "Zeroed Sliders" — it was taking ~1 roll in 9) |
| `- Zeroed Sliders -` | CBBE | all `0` | **never distributed** (OBody's own default) |
| `CBBE Chubby` | CBBE | **`Belly 60/60`, `BigBelly -/25`, `ChubbyButt 85/100`, `ChubbyWaist 55/60`** | **never distributed** |
| `CBBE Oppai` | CBBE | `7B Lower 100/100`, `BreastPerkiness -/-25`, `DoubleMelon 20/40` | **never distributed** |
| `CBBE Fetish` | CBBE | `AreolaSize 100/100`, `NippleManga 75/75`, `Thighs 40/40` | **never distributed** |
| `CBBE Fetish v2` | CBBE | as above plus `Cutepuffyness 100`, `NipplePuffy_v2 60` | **never distributed** |
| `Warmaiden - Adrianne Avenicci SHWB's Body 3BA` | Warmaiden (1798) | **`Belly 80/80`, `BigBelly 35/35`, `ChubbyArms 70/70`** | **never distributed** (and it is Adrianne's own character preset) |
| `State of mind - Psyche's Body [By WeelBones]` | Psyche (1792) | **`Belly 70/70`, `ChubbyWaist 75/75`** | **never distributed** (Mirabelle's own preset) |
| `[Dint999] SSE First CBBE Body Physics` | Dint999 (1871) | `Belly -/40`, `BigButt -/15` | **never distributed** (belly at high weight) |
| `Sentence - Ranmir Himbo Body` | Remnants (1791) | male, set `HIMBO Body - SOS High Poly` | male — untouched |

Everything in the "never distributed" bucket is still **hand-pickable** from the O-key preset list,
because `blacklistedPresetsShowInOBodyMenu` stays `true`. Nothing has been removed from any mod.

### 3.2 Why the random pool needed a curvy entry at all

The owner's request was *"curvy bodies should be in the game too… just maybe not as common"*. With
only named assignments, every woman who is not on a list is vanilla/slim/athletic, and the curvy
bodies become a thing that happens only to famous people. One mild curvy preset in a pool of nine is
**~11%** — roughly one woman in nine you meet at random — which reads as "uncommon but clearly
present" rather than "every woman is thick", which was the original complaint.

### 3.3 The three new presets — how they were built, and why they are safe

`CalienteTools\BodySlide\SliderPresets\LoreRimGlue.xml`. This is a **new file**; no other mod ships
that path.

* **They need no BodySlide run.** OBody reads `Data\CalienteTools\BodySlide\SliderPresets\*.xml`
  directly at load and applies the sliders as RaceMenu body morphs. I parsed the winning
  `femalebodyastrid.tri` (which the glue's `femalebody_0/1.nif` point at) and dumped its morph list:
  **154 morphs on shape `3BA`**. Every one of the 41 distinct slider names used across the three new
  presets is in that list. Nothing is invented and nothing is dead.
* **The values are not invented either.** Each preset is a controlled interpolation of presets that
  already ship and already work on this body:
  * `LoreRim - Curvy Soft` = the **midpoint between `CBBE Slim` and `CBBE Curvy`** on every slider
    the two share (so `Butt 50/65` sits between Slim's `50/50` and Curvy's `50/80`, `Breasts 0/45`
    between nothing and `0/80`, and so on).
  * `LoreRim - Curvy Hourglass` = **`CBBE Curvy` with the bust dialled back 80 → 60**, calves and
    arms softened, plus `Hips 12/22` and `RoundAss 25/40` (both sliders used by shipped presets in
    the same direction) for a rounder, wider-hipped read.
  * `LoreRim - Curvy Statuesque` = `Soft`'s curves plus **~60% of `CBBE Strong`'s muscle block**
    (`MuscleAbs/Arms/Butt/Legs/Pecs`, `MuscleMore*_v2`, `BigTorso`) — a powerful, full figure for
    warrior women.
* **No `Belly`, `BigBelly`, `Chubby*` or `PregnancyBelly` slider appears anywhere in the file.** That
  is the owner's stated red line and it is enforced by omission, not by a low number.
* **They will not be mis-classified.** Filename `LoreRimGlue.xml` and all three preset names avoid
  OBody's filter words (`cloth/outfit/nevernude/bikini/feet/hands/push/cleavage/armor`);
  `set="CBBE 3BBB Body Amazing"` is 3BA's own slider set — the one `Warmaiden…` uses — and contains
  none of OBody's male markers, so all three are read as **female 3BA** presets. The XML comment sits
  **outside** `<SliderPresets>`, so OBody's `for (node : presets)` loop never sees it.
* They also show up in BodySlide's own preset dropdown, which costs nothing and is arguably a bonus.

---

## 4. The assignment

### 4.1 36 named women — `npcFormID`

Three lists are used, so no two women on the same list are guaranteed to look alike:

* **A "warrior"** = `["LoreRim - Curvy Statuesque", "LoreRim - Curvy Soft", "CBBE Strong"]`
* **B "full"** = `["LoreRim - Curvy Hourglass", "CBBE Curvy", "LoreRim - Curvy Soft"]`
* **C "soft"** = `["LoreRim - Curvy Soft", "LoreRim - Curvy Hourglass", "CBBE SevenBase"]`

Every FormID below was re-read from the ESM and confirmed to be a **female `NPC_` record** (29 in
`Skyrim.esm`, 4 in `Dawnguard.esm`, 3 in `Dragonborn.esm`; all also flagged Unique).

| name | plugin | FormID | EditorID | list | why her |
|---|---|---|---|---|---|
| Lydia | Skyrim.esm | `0A2C8E` | `HousecarlWhiterun` | **B** | the owner's own example; the archetypal housecarl, and a full figure on a shield-maiden is the whole idea |
| Jordis the Sword-Maiden | Skyrim.esm | `0A2C8F` | `HousecarlSolitude` | A | housecarl, same sturdy warrior build as Lydia |
| Iona | Skyrim.esm | `0A2C91` | `HousecarlRiften` | A | housecarl, ditto |
| Uthgerd the Unbroken | Skyrim.esm | `0918E2` | `Uthgerd` | A | the brawler who knocks you down in the Bannered Mare — heavy, powerful frame |
| Aela the Huntress | Skyrim.esm | `01A696` | `AelaTheHuntress` | A | Companion and werewolf; athletic-curvy, not waifish |
| Njada Stonearm | Skyrim.esm | `01A6D9` | `NjadaStonearm` | A | shield-bearer whose entire characterisation is being solid and unmoved |
| Mjoll the Lioness | Skyrim.esm | `01336B` | `Mjoll` | A | described in-game as towering and formidable |
| Irileth | Skyrim.esm | `013BB8` | `Irileth` | A | career soldier and Balgruuf's housecarl |
| Legate Rikke | Skyrim.esm | `0132A1` | `Rikke` | A | veteran Legion officer; muscle, not slimness |
| Delphine | Skyrim.esm | `013478` | `Delphine` | A | middle-aged Blade still fighting for a living |
| Borgakh the Steel Heart | Skyrim.esm | `019959` | `Borgakh` | A | Orc stronghold warrior and a follower; given a fixed identity rather than the generic Orc pool |
| Ysolda | Skyrim.esm | `013BAB` | `Ysolda` | B | the young Whiterun trader everyone in town has an opinion about |
| Carlotta Valentia | Skyrim.esm | `013B99` | `CarlottaValentia` | B | the game writes her as the market widow who cannot stop being propositioned |
| Haelga | Skyrim.esm | `01335F` | `Haelga` | B | openly Dibella-devoted and openly promiscuous — the single most obvious fit in Skyrim |
| Saadia | Skyrim.esm | `013BA2` | `Saadia` | B | a noble in hiding, written and voiced as a beauty |
| Alva | Skyrim.esm | `0135E6` | `Alva` | B | Morthal's vampire seductress; seduction is literally her quest role |
| Elisif the Fair | Skyrim.esm | `01326A` | `ElisifTheFair` | B | "the Fair" is in her name |
| Vittoria Vici | Skyrim.esm | `01327A` | `VittoriaVici` | B | Imperial noblewoman at her own wedding |
| Tonilia | Skyrim.esm | `0B8827` | `Tonilia` | B | the Guild's fence, played as worldly and self-assured |
| Serana | Dawnguard.esm | `002B6C` | `DLC1Serana` | B | the list's headline companion; the glue already ships her body folder, so her morphs work |
| Camilla Valerius | Skyrim.esm | `01347B` | `CamillaValerius` | C | Riverwood's courted beauty, but young — soft rather than full |
| Maven Black-Briar | Skyrim.esm | `01336A` | `Maven` | C | matriarch; a fuller, matronly figure suits the power |
| Temba Wide-Arm | Skyrim.esm | `0658D2` | `TembaWideArm` | C | her name is Wide-Arm and she runs a lumber mill |
| Gerdur | Skyrim.esm | `01347C` | `Gerdur` | C | mill owner doing physical work every day |
| Sigrid | Skyrim.esm | `013476` | `Sigrid` | C | Riverwood homemaker, the soft end of the spread |
| Danica Pure-Spring | Skyrim.esm | `013BA5` | `DanicaPureSpring` | C | Kynareth's priestess; earthy and maternal |
| Dinya Balu | Skyrim.esm | `013352` | `Dinya` | C | Mara's priestess of love and marriage |
| Muiri | Skyrim.esm | `01406B` | `Muiri` | C | the jilted young alchemist at the centre of her own love-and-revenge quest |
| Olfina Gray-Mane | Skyrim.esm | `013B9B` | `OlfinaGrayMane` | C | young Bannered Mare barmaid |
| Sylgja | Skyrim.esm | `0C3A3F` | `Sylgja` | C | Shor's Stone miner — a working Nord's build |
| Valerica | Dawnguard.esm | `003B8B` | `DLC1Valerica` | C | ancient vampire matriarch; statuesque rather than slim (glue ships her body folder) |
| Ingjard | Dawnguard.esm | `01541B` | `DLC1Ingjard` | A | Dawnguard front-line warrior |
| Fura Bloodmouth | Dawnguard.esm | `003363` | `DLC1FuraBloodmouth` | A | vampire warrior; imposing by design |
| Frea | Dragonborn.esm | `017934` | `DLC2Frea` | A | Skaal warrior-shaman, strong and healthy |
| Bujold the Intrepid | Dragonborn.esm | `01A511` | `DLC2Bujold` | A | Nord chieftain's daughter contesting a war-hall |
| Fanari Strong-Voice | Dragonborn.esm | `018FC5` | `DLC2SVFanariStrongVoice` | C | the Skaal chief's wife; sturdy matriarch |

**Deliberately not assigned:** the eight women whose bodies this mod cannot reach — **Lelaegh, Nirya,
Birna, Mirabelle Ervine, Faralda, Rayya, Susanna, Adrianne Avenicci**. Their overhauls point them at a
body under their own folder name whose NIF references LoreRim's old `femalebody.tri`, so RaceMenu /
OBody morphs are **inert** for them (documented in `OVERRIDES.md` since pt4). An assignment would look
like it worked in the log and do nothing in the world. They keep their author's fixed shape.

### 4.2 Two factions

| key | members it catches (vanilla females) | list | why |
|---|---|---|---|
| `MarkarthTempleofDibellaFaction` | Senna, Hamal, Anwen, Orla (+ Fjotra, who is excluded — see below) | `Hourglass, Soft, CBBE Curvy, CBBE SevenBase` | Dibella is the goddess of beauty and physical love; her priestesses being fuller-figured is the most lore-native version of this whole request |
| `JobInnkeeperFaction` | Hulda, Elda Early-Dawn, Keerava, Iddra, Jonna, Frabbi, Eydis, Faida, Karita, Lynly, Valga Vinicia, Saadia (+ any modded inn staff using the vanilla job faction) | `Soft, Hourglass, CBBE Curvy, CBBE Vanilla, CBBE SevenBase, CBBE Petite` | the tavern-keeper archetype, and a 3-of-6 mix so innkeepers skew fuller **without** becoming uniform |

Both EditorIDs were confirmed to exist in the load order. Saadia is in both the innkeeper faction and
the named list — `npcFormID` runs first, so her named list wins, which is the intended precedence.

### 4.3 One race rule

`raceFemale` → **`OrcRace`** and **`OrcRaceVampire`**:
`["LoreRim - Curvy Statuesque", "LoreRim - Curvy Soft", "CBBE Strong", "CBBE Athletic", "CBBE Vanilla", "CBBE SevenBase"]`
— 2 curvy in 6, the rest athletic or vanilla. Orsimer women read as stockier and stronger than the
other races, and this is far cheaper than naming the twenty-odd stronghold women individually. **No
other race is given a rule**, on purpose: a race rule bypasses the curated random pool entirely, so
using it everywhere would make the blacklist decorative and the file brittle. Every non-Orc woman
falls through to the pool, which is the good default.

### 4.4 Child and elder races blacklisted

Stock is `["ElderRace"]`. Now also `ElderRaceVampire` and every child race present in this load order:
`NordRaceChild`, `BretonRaceChild`, `ImperialRaceChild`, `RedguardRaceChild` and Kidmer's
`urshug_OrcRaceChild`, `urshug_DarkelfRaceChild`, `urshug_HighelfRaceChild`, `urshug_WoodelfRaceChild`
(all eight were found by scanning `RACE` records across the enabled plugins). In practice OBody's
morphs were already inert on child bodies — they use a different mesh and `.tri` — but this makes it
explicit, and it is specifically what stops the Dibella faction rule reaching **Fjotra**, who is the
temple's new Sybil and is a `NordRaceChild` record. Same list applied to `blacklistedRacesMale`.

This is the one change in this round that the owner did not ask for. It is one JSON line and can be
reverted to `["ElderRace"]` on request.

---

## 5. Verification

A validator was written at `C:\Users\Jordan\AppData\Local\Temp\lrg_test\validate_obody.py` and run
**against the installed copy** in `F:\Modlists\LoreRim\mods\LoreRim Glue`. It re-implements OBody's own
preset-loading rules (merged-folder priority, the filename filter, the preset-name filter, the
male-set markers, the blacklist split) and then checks the config against the shipped schema and
against the actual plugins. Output, in full:

```
== 1. JSON parses ==                       OK  no BOM; 21 top-level keys
== 2. schema conformance ==                OK  no unknown keys; all 21 schema keys present;
                                               all types / patterns match the definitions
== 3. preset database as OBody will build it ==
     files skipped on filename : outfittesting.xml
     presets skipped on name   : CBBE Curvy (Outfit), CBBE Slim (Outfit)
                                           OK  21 female presets, 1 male preset
== 4. every preset name the config uses ==  OK  130 references, all resolve, all case-exact,
                                               no male preset in a female rule
== 5. blacklist ==                         OK  12 of 15 entries match an installed preset exactly
                                               (the other 3 are OBody's own stock spellings)
== 6. keys resolve ==                      OK  Skyrim.esm 29/29, Dawnguard.esm 4/4,
                                               Dragonborn.esm 3/3 female NPC_ records
                                           OK  both faction EditorIDs exist
                                           OK  all 12 race EditorIDs exist
RESULT: 0 failure(s)
```

It also predicts exactly what `OBody.log` should print on the next load:

```
Female presets: 9, Male presets: 1
Blacklisted: Female presets: 12, Male Presets: 0
```

and the resulting random pool: `CBBE Athletic`, `CBBE Petite`, `CBBE SevenBase`, `CBBE Slim`,
`CBBE Strong`, `CBBE Vanilla`, `LoreRim - Curvy Soft`,
`Observer of the void - Paranoiac's Body [By WeelBones]`,
`Skinny Days - Birna's Body Preset WeelBones`.

Separately verified by hand:

* the `.tri` parse — `femalebodyastrid.tri` is `PIRT` v3 with shapes `3BA` (154 morphs),
  `3BA_Vagina` (51) and `3BA_Anus` (27); all 41 slider names used by the new presets are among the
  154;
* the new XML parses as XML and its three `<Preset>` elements are the only children of
  `<SliderPresets>`;
* no other mod ships `CalienteTools\BodySlide\SliderPresets\LoreRimGlue.xml`.

---

## 6. How it was installed

`glue\tools\install_mo2.ps1` was run and **refused**, with:

```
Mod Organizer is running. Close it first - profile files must not be edited while MO2 is open.
```

**Skyrim was not running** (checked: only `ModOrganizer`, pid 42744). Per the standing rule for that
case, the three files were copied by hand into `F:\Modlists\LoreRim\mods\LoreRim Glue` — **our own mod
folder only, same relative paths** — behind a guard that the target contains `LoreRimGlue.esp`, then
verified by SHA-256:

| file | bytes | SHA-256 |
|---|---|---|
| `SKSE\Plugins\OBody_presetDistributionConfig.json` | 5407 | match |
| `CalienteTools\BodySlide\SliderPresets\LoreRimGlue.xml` | 12783 | match |
| `OVERRIDES.md` | 14742 | match |

No profile file was edited — none needed to be: `+LoreRim Glue` is already line 3 of
`profiles\Ultra\modlist.txt`. Nothing else in the mod folder changed. Re-running `install_mo2.ps1`
later with MO2 closed is safe and will simply re-sync the same files. `install_mo2.ps1` was not
modified: its `-NoNudeBody` switch excludes only `meshes\`, so both new files install either way,
which is correct.

---

## 7. Owner steps

**O0 — press F5 in Mod Organizer before launching.** MO2 was open while the files were copied in, and
it caches each mod's file tree. `CalienteTools\` is a **brand-new top-level folder** inside
`LoreRim Glue`, so MO2 may not have it in its virtual file system yet — and if it does not, OBody
will not see the three new presets and will log `Female presets: 8` / `Blacklisted: … 10` instead of
`9` / `12` (the named rules then quietly fall back to the one stock name in each list). **Refresh** (the
F5 key, or the circular-arrows button above the left pane) once, confirm that `LoreRim Glue` still
shows ticked at the bottom of the left pane, then launch. Closing and reopening MO2 does the same
thing.

**O1 — "Reset all distributed presets" is required again.** Every woman you have already met is
carrying last night's roll, and none of today's rules can reach her until the distribution key is
bumped. Exact MCM path and labels, verbatim from `OBody_ENGLISH.txt`:

1. **MCM → OBody NG** (single page) → **"Reset all distributed presets"**.
2. Do it **in a cell with as few NPCs as possible** — a player home, or an empty dungeon. OBody's own
   words: *"PLEASE do this on a cell with no other NPCs or with as few NPCs as possible, such as a
   player home or even a dungeon."*
3. Accept the confirmation. The second message is an instruction, not a formality:
   *"PLEASE SAVE THE GAME AND EXIT THE GAME NOW! Then start Skyrim and reload your save again. This is
   needed for the reset to work!"* — **save, fully exit Skyrim, restart, reload.**

The reset **respects the JSON** (its own help text says so), so Lydia and the rest pick up their new
bodies on the reload. It also clears any preset you had picked by hand with the O key; re-pick those
afterwards if you had any.

**O2 — how to check it took, in `OBody.log`.**
`C:\Users\Jordan\Documents\My Games\Skyrim Special Edition\SKSE\OBody.log`:

* The validation line must still be there — if the JSON were malformed OBody would log a validation
  failure instead, so this line *is* the proof the new file was accepted:
  `Validated Data/SKSE/Plugins/OBody_presetDistributionConfig.json successfully`
* The counts must now read **exactly**:
  `Female presets: 9, Male presets: 1`
  `Blacklisted: Female presets: 12, Male Presets: 0`
  (last night it was `Female presets: 17` / `Blacklisted: … 1` before pt8's file, and would have been
  `9 / 9` after it — the numbers moving to `9 / 12` is how you know today's file is the live one).
* Per-NPC lines look like `Preset <name> will be applied to <NPC>`. After the reset, expect e.g.
  `Preset LoreRim - Curvy Hourglass will be applied to Lydia` — one of *Hourglass / CBBE Curvy /
  Curvy Soft* for her, one of *Statuesque / Curvy Soft / CBBE Strong* for Aela, and so on.
* You should **never** see `Preset CBBE Chubby …`, `Preset CBBE Oppai …`, `Preset CBBE Fetish …`,
  `Preset Warmaiden - … `, `Preset State of mind - …`, `Preset [Dint999] …` or `Preset TNG Default …`
  applied to anyone. If one of those appears, tell me the line.
* A handful of `Looking for preset: ` lines with an **empty** name are normal — that is OBody's own
  list-padding quirk (§2.4), not a mistake in the config.
* Harmless-but-expected on load: `removed '<name>'` lines for race keys that this load order does not
  contain. All twelve of ours were confirmed present, so there should be none from us.

**O3 — how to check it in game.** Put the crosshair on a woman and press the **Presets list key**
(OBody MCM, default `O`). The menu header shows **"Current Preset is: <name>"** for that actor. Lydia,
Ysolda, Haelga, Aela, Uthgerd and any innkeeper should show a `LoreRim - Curvy …` or `CBBE Curvy`
name. A random townswoman should show one of the nine pool presets, and roughly one in nine of them
should be `LoreRim - Curvy Soft`. (NPCs you met before the reset will say *"Unknown Preset"* until
they are re-processed — another reason to do O1 first.)

**O4 — your own character is never distributed to.** Press the Presets list key with **nothing** in
the crosshair to pick your own body; all three new presets are in that list, and so is every
blacklisted one.

**O5 — dials, if the result is not quite right.** Each of these is a one-line change in the glue and I
can do it in a minute:

* *Curvy shows up too often at random* → move `LoreRim - Curvy Soft` into
  `blacklistedPresetsFromRandomDistribution`. The random pool goes to 8 and curvy becomes
  named-only.
* *Not often enough* → also un-blacklist `LoreRim - Curvy Statuesque` (pool 10, 2 curvy, ~20%).
* *A specific woman is wrong* → give me her name; it is one line in `npcFormID`, or one press of the
  O key on your side.
* *The new presets are too much / too little* → they are plain numbers in
  `CalienteTools\BodySlide\SliderPresets\LoreRimGlue.xml`; say "half it" or "more hips" and I will
  re-scale them. Deleting that one file reverts the entire idea and leaves pt8's behaviour with
  `CBBE Curvy` as the only curvy option.
* *You want the child-race blacklist gone* → `blacklistedRacesFemale` / `Male` back to
  `["ElderRace"]`.

**O6 — still outstanding from pt8, unrelated to this round:** `CBPCSystem.ini` in the glue still has
`Logging = 1`. Once you are satisfied with the jiggle, say so and I will set it back to `0`.

---

## 8. Deliberately not done

* **No `npc` (display-name) rules.** `NPCs Names Distributor` and a dozen NPC overhauls can rewrite
  `FULL`; FormIDs cannot change. The `npc` map is left `{}`.
* **No `npcPluginFemale` rules.** Assigning a preset to *every* female in a plugin (e.g. all of
  `Bijin_AIO.esp`) is a blunt instrument and would have overridden the random spread for large
  swathes of the game.
* **No race rules beyond the Orcs.** Covering every race would silently retire the curated random pool
  and make the file brittle; see §4.3.
* **No duplicate-name weighting used yet.** It works (§2.4) and is the tool to reach for if a
  particular rule needs a skew, but every list here is small enough that plain uniform choice is
  clearer.
* **`Observer of the void - Paranoiac's Body` left in the natural pool** despite `BigBelly 12` /
  `ChubbyButt 30` — it is the mildest of the thick presets and removing it would cut the pool to
  eight. Say the word if you want it out.
* **No mod's preset XML edited, no mesh touched, no BodySlide / Nemesis / LOOT run**, and no file
  created or changed outside `LoreRim Glue`.
* **The eight unreachable NPCs were not fixed.** Copying their overhaul bodies into the glue under
  their own folder paths would make their morphs live (and let them be assigned like everyone else).
  That is a separate decision, and a mesh job rather than a config one — pt8 §7 flagged it and it is
  still open.
