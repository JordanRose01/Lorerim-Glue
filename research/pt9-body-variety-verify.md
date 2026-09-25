# pt9 verify — curvy-bodies-back-on-purpose round

Independent verification of `research\pt9-body-variety.md`, run 2026-09-22 against the **installed**
copy in `F:\Modlists\LoreRim\mods\LoreRim Glue` and the **source** in
`…\scratch-2026-09-21-8ca12a\glue\game\LoreRimGlue`.

**VERDICT: SHIP.** Every functional claim I could test came back true, and I re-derived the
important ones from scratch rather than re-reading the builder's validator. Nothing in the JSON or
the new preset XML needs changing, so **I applied no fixes** — the two files are byte-identical to
what the builder installed. Six findings follow, all documentation-level: one of them
(**D1, duplicate-weighting**) is wrong enough that it would bite the *next* round if acted on, and
one (**D2, po3 Tweaks**) is a load-bearing dependency the register does not record.

Verification was read-only except this file. No mod folder, profile file or game file was touched;
BodySlide / Nemesis / LOOT were not run.

---

## 1. What I re-derived independently (and what the source actually says)

The builder's claims about OBody NG's behaviour are the foundation of the whole design, so I pulled
the upstream C++ (`github.com/Aietos/OBody-NG`, the repo `OBody Next Generation\meta.ini` points at)
rather than trusting the write-up. Verbatim findings:

| pt9 claim | verdict | source |
|---|---|---|
| Resolution order `npcFormID/npc` → global blacklist → faction → plugin → race → random | **true** | `Body.cpp::GenerateActorBody` — the blacklist check sits *inside* `if (!preset.has_value())` after `GetNPCPreset`, so a named NPC of a blacklisted race still gets her preset |
| `npc` keys are display names, not EditorIDs | **true** | `auto actorName{actorBase->GetName()}` then a plain `FindMember(actorName)` |
| 6-digit `npcFormID` keys are correct for a full-ESM plugin | **true** — and this is the one I most expected to be wrong | `DiscardFormDigits`: `char temp[9]{"00000000"}; memcpy(temp + (8 - formID.length()), formID.data(), formID.length()); return std::string(temp + (mod->IsLight() ? 5 : 2));` — that is a **right-aligned copy into a zero-filled buffer**, i.e. a *left*-pad, so `"0A2C8E"` → `"000A2C8E"` → strip 2 → `0x0A2C8E` → `LookupForm(0x0A2C8E, "Skyrim.esm")`. Correct. (pt9 §2.2 calls it "right-pads", which would have produced `0x2C8E00`; the *wording* is backwards, the *conclusion* is right — see D4) |
| A named preset beats the random blacklist | **true** | `GeneratePresets` builds `allFemalePresets = femalePresets + blacklistedFemalePresets`; every named path takes `allFemalePresets`, only `GetRandomPreset` takes `femalePresets` |
| Blacklist compare is case-**sensitive**, assignment compare is case-**insensitive** | **true** | blacklist: rapidjson `std::find(begin, end, name.c_str())`; assignment: `stl::cmp` = `boost::algorithm::iequals` |
| The clothed/male filters are case-insensitive | **true** | `stl::contains` → `boost::algorithm::icontains`. So `CBBE Curvy (Outfit)` *is* skipped on its capital-O name, and the filename filter is applied to `path.wstring()` — the whole relative path `Data\CalienteTools\BodySlide\SliderPresets\…`, which contains none of the nine filter words |
| `-Refit` suffix auto-blacklists | **true** | `preset.value().name.ends_with("-Refit")` (none present here) |
| `stl::random` is `[min, max)` — no off-by-one in the picker | **true** | `std::uniform_int_distribution<T> distrib(min, max - 1)` |
| The empty-string padding from the size-constructor is harmless | **true** | `GetPresetByNameForRandom` returns `{}` (it does **not** fall back to a random preset the way `GetPresetByName` does), so an empty entry is erased and the call recurses; removal is name-independent, so the real names stay equiprobable |
| "Reset all distributed presets" re-reads the JSON | **true** | `OBodyNGScript.psc:84-92` bumps `obody_processed` → `obody_processed<N>` via `OBodyNative.SetDistributionKey`; `IsProcessed` tests that key as a body morph |
| Duplicating a name in a list doubles its odds | **FALSE** | see **D1** |

---

## 2. The checks the task asked for

### 2.1 Schema — run, not eyeballed
`jsonschema` 4.10.3 in WSL, against `OBody Next Generation\SKSE\Plugins\OBody_presetDistributionConfig_schema.json`:

```
Draft7Validator   : VALID
Draft202012Validator: VALID
21/21 schema properties present, 0 extra keys (additionalProperties:false)
no BOM, 5407 bytes, parses as UTF-8
```
Every `npcFormID` key matches `^[0-9A-Fa-f]{3,8}$`; every preset string matches `NonEmptyString`;
every plugin/faction/race key matches `NonEmptyTrimmedString`. The stock `OBodyConfigModel.py`
pydantic model has the same 21 fields with `extra='forbid'`, so the rapidjson check OBody actually
runs at load will agree.

### 2.2 Preset names on disk — rebuilt the merged folder from modlist priority
I rebuilt the virtual `Data\CalienteTools\BodySlide\SliderPresets` from `profiles\Ultra\modlist.txt`
(first line = highest priority; confirmed by USSEP sitting at 4077 and the DLCs at the very bottom)
and re-applied OBody's own filters:

* 10 XML files merged, from 8 mods. `outfittesting.xml` skipped on filename; `CBBE Curvy (Outfit)`
  and `CBBE Slim (Outfit)` skipped on name.
* **21 female + 1 male** presets survive — exactly what pt9 predicts, and 18 + the 3 new ones.
* No duplicate female preset names.
* `TNG Default` has `set=""`, so `IsFemalePreset` returns **true** — it really was sitting in the
  female pool, and blacklisting it was a real fix, not cosmetics.

**130 preset references** across `npcFormID`, `factionFemale` and `raceFemale`: **all 130 resolve,
all 130 are case-exact against the XML `name=` attribute, and none is the male preset.**

### 2.3 Blacklist and the resulting pool
15 entries, all unique. 12 match an installed preset **byte-exactly** (which is what OBody's
case-sensitive compare needs); the other 3 (`-Zeroed Sliders-`, `Zeroed Sliders`,
`HIMBO Zero for OBody`) are OBody's own stock spellings with no matching preset — harmless no-ops.

My independent prediction of the next `OBody.log` matches pt9's to the number:

```
Female presets: 9, Male presets: 1
Blacklisted: Female presets: 12, Male Presets: 0
```

Random pool (9): `CBBE Athletic`, `CBBE Petite`, `CBBE SevenBase`, `CBBE Slim`, `CBBE Strong`,
`CBBE Vanilla`, `LoreRim - Curvy Soft`, `Observer of the void - Paranoiac's Body [By WeelBones]`,
`Skinny Days - Birna's Body Preset WeelBones`.

* **Extremes are out**: `CBBE Chubby`, `CBBE Oppai`, `CBBE Fetish`, `CBBE Fetish v2`,
  `Warmaiden…`, `State of mind…`, `[Dint999]…`, `TNG Default`, `- Zeroed Sliders -`, `CBBE Curvy`
  and the two stronger glue presets are all out of the roll and reachable only by name or by hand.
* **Natural is still the common case**: 8 of 9 pool entries are vanilla/slim/athletic.
* **Curvy is rare**: exactly 1 of 9 = **11.1 %**, as designed.

Cross-check against the live `OBody.log` (2026-09-21 21:46, the last real launch — stock config):
`Female presets: 17, Male presets: 1` / `Blacklisted: Female presets: 1`. 17 + 1 blacklisted = the
18 female presets that existed before the glue's XML. pt9 §3.1's arithmetic is right, and the log
confirms **pt8's config has never been loaded in game** — the `9 / 12` line will be the first
evidence either round is live.

### 2.4 Every NPC key is the form OBody matches — and is who the report says she is
All 36 `npcFormID` keys were resolved against the real `Stock Game\Data` ESMs by walking the record
tree myself (not via the builder's script). **36/36** are `NPC_` records, **36/36** have ACBS bit 0
(Female) set, **36/36** have ACBS bit 5 (Unique) set, and **36/36 EditorIDs match the pt9 §4.1 table
exactly** — `0A2C8E` = `HousecarlWhiterun` (Lydia), `01A696` = `AelaTheHuntress`, `01335F` = `Haelga`,
`002B6C` = `DLC1Serana`, `018FC5` = `DLC2SVFanariStrongVoice`, and so on for all 29 Skyrim.esm /
4 Dawnguard.esm / 3 Dragonborn.esm entries. Every list assignment (A/B/C) in the JSON matches the
table row for row.

Both faction EditorIDs exist as `FACT` records in `Skyrim.esm`: `JobInnkeeperFaction` `0x05091B`,
`MarkarthTempleofDibellaFaction` `0x0656EA`. All 12 race EditorIDs exist (winning records in
`LoreRim - Synthesis Output\Synthesis - NPC.esp`), including all four `urshug_*RaceChild` ones —
so no `removed '<race>'` lines should appear from us.

**Faction membership, read out of `Skyrim.esm` rather than assumed:**

* Dibella temple — 5 female members: **Anwen, Fjotra, Hamal, Orla, Senna**. Fjotra is
  `NordRaceChild`, so the new child-race blacklist is what keeps the Sybil out of it. pt9 §4.2 is
  exactly right, including the Fjotra caveat.
* Innkeepers — 28 members, **12 female**: Elda, Eydis, Faida, Frabbi, Hulda, Iddra, Jonna, Karita,
  Keerava, Lynly, Saadia, ValgaVinicia. That is pt9's list, name for name. Saadia is the one who is
  also in `npcFormID`, and `npcFormID` runs first, so her named list wins — as documented.

### 2.5 Precedence does what the design says
* Lydia, Aela, Haelga … → step 1, before anything else. ✓
* Dibella priestesses and innkeepers → step 3, and no listed NPC is in both rule factions, so
  `GetNPCFactionPreset`'s "first member in file order wins" never has to break a tie. ✓
* Orc women → step 5, reached only if steps 1–4 miss. An Orc innkeeper therefore gets the innkeeper
  list, not the Orc list — correct, and worth knowing. ✓
* Everyone else → step 6, the curated 9. ✓
* Elders and children → step 2, which is *after* the named step, so a named woman of a blacklisted
  race would still be assigned. None of the 36 is one. ✓

### 2.6 The three new presets are real and reachable
* `LoreRimGlue.xml` parses; `<SliderPresets>` has exactly three `<Preset>` children and the XML
  comment sits outside the root, so OBody's `for (node : presets)` loop never sees it.
* All three carry `set="CBBE 3BBB Body Amazing"` — which exists as a real slider set
  (`CBBE 3BA (3BBB)\CalienteTools\BodySlide\SliderSets\SE 3BBB Amazing.osp`), so BodySlide will show
  them too. No name hits the clothed filter; no set hits a male marker; all three load as female.
* `<SetSlider name= size="big|small" value="0…100"/>` — identical format and value scale to CBBE's
  own `CBBE.xml`.
* **No `Belly`, `BigBelly`, `Chubby*` or `Pregnan*` slider anywhere in the file.** Confirmed by
  enumerating all 41 distinct slider names across the three presets. The owner's red line holds.
* **The morph check, redone from the binary.** The glue's `femalebody_0/1.nif` carry exactly one
  `BODYTRI` string: `actors\character\character assets\femalebodyastrid.tri`. I parsed that TRIP
  file (`PIRT`, v3, 5 011 925 B, winning copy from `LoreRim - BodySlide Output`) and it holds three
  shapes — `3BA` (**154** morphs), `3BA_Anus` (27), `3BA_Vagina` (51). **All 41 slider names are
  present on the `3BA` shape.** pt9's "no BodySlide run required" claim is sound.

### 2.7 Install integrity
* SHA-256, source vs installed: `OBody_presetDistributionConfig.json` (5407 B),
  `CalienteTools\BodySlide\SliderPresets\LoreRimGlue.xml` (12783 B) and `OVERRIDES.md` (14742 B)
  **all three match**.
* Full recursive hash diff of the two trees: no unexpected extra or missing file for this round
  (`meta.ini` is MO2's own; three `*.psc.bak-docstrings` are source-side only). See **D5** for the
  17 files that differ for an unrelated reason.
* **Nothing outside the glue changed.** No file anywhere under `F:\Modlists\LoreRim\mods\` other
  than `LoreRim Glue` has a modification time after 2026-09-22 03:00. The newest file in
  `profiles\Ultra\` is `plugins.txt` / `loadorder.txt` / `lockedorder.txt` at 2026-09-21 22:18 —
  hours before this round. `modlist.txt` is untouched since 2026-09-21 16:54.
* VFS winner check: for both `SKSE\Plugins\OBody_presetDistributionConfig.json` and
  `CalienteTools\BodySlide\SliderPresets\*`, **LoreRim Glue wins** (OBody NG's stock 854 B copy is
  the only other candidate, and nothing is in `overwrite\`).

### 2.8 Owner steps use real MCM labels
Read out of `OBody Next Generation\Interface\translations\OBody_ENGLISH.txt` (UTF-16) and
`Source\Scripts\OBodyNGScript.psc`:

| pt9 says | actual | verdict |
|---|---|---|
| MCM → **OBody NG** | `Modname = "OBody NG"`, no `Pages` array ⇒ single page | ✓ |
| **"Reset all distributed presets"** | `$obody_option_reset_distribution` | ✓ verbatim |
| *"PLEASE do this on a cell with no other NPCs or with as few NPCs as possible, such as a player home or even a dungeon."* | `$obody_message_reset_distribution` | ✓ verbatim |
| *"PLEASE SAVE THE GAME AND EXIT THE GAME NOW! Then start Skyrim and reload your save again. This is needed for the reset to work!"* | `$obody_message_reset_distribution_success` | ✓ verbatim |
| *"Reset the presets that have been distributed to NPCs. This will respect the rules you set in the JSON configuration."* | `$obody_highlight_reset_distribution` | ✓ verbatim |
| **Presets list key** (default `O`) | `$obody_option_preset_key` | ✓ |
| Menu header **"Current Preset is: \<name\>"** | `title[1] = "Current Preset is:"`, `title[2] = currentPreset` | ✓ |
| Unprocessed NPCs show **"Unknown Preset"** | `"Unknown/Unassigned Preset"` | **D4** |

Log strings quoted in O2 all exist as literals in `OBody.dll`: `Trying to find and apply preset to {}`,
`No preset defined for this actor, getting it randomly`, `Preset {} will be applied to {}`,
`Looking for preset: {}`, `Validated Data/SKSE/Plugins/OBody_presetDistributionConfig.json successfully`.

### 2.9 "Is there a mod that already does this?" — confirmed no
Re-checked: `OBody_presetDistributionConfig.json` exists in exactly two places under
`F:\Modlists\LoreRim` (OBody NG's stock copy and ours), nothing in `overwrite\`, and the only
distribution-adjacent hits in the 4086-line modlist are `OBody Next Generation` (line 5) and
`Racial Body Morphs Redux` (line 1297). I opened RBM Redux: it ships **ten per-race
`skeleton*.nif` files and an ESP** — it is a skeleton-scale mod, not a NiOverride morph distributor,
so it neither competes with OBody nor gets wiped by OBody's morph reset. pt9's dismissal is correct.

Worth recording for the owner: a purpose-built mod *does* exist off-list —
**"OBody NG Preset Distribution Assistant NG"** (Nexus 159128), an SKSE DLL that writes this same
JSON from SPID-style INIs. It is not installed, and installing it would mean adding a mod to
LoreRim, which the modlist's own separator forbids. Building the JSON by hand was the right call.

---

## 3. Defects

### D1 — MEDIUM · the duplicate-name weighting mechanism does not exist
**Claim.** §2.4: *"listing a preset twice in a list doubles its chance"*, presented as established
from source; §8 repeats it as *"the tool to reach for if a particular rule needs a skew"*; §2.4 is
introduced under "Nothing below is guessed".

**Evidence.** `stl::RemoveDuplicatesInJsonArray(value, allocator)` is applied to **every** preset
list before it is ever read. In `JSONParser.cpp` it is called on `npcFormID`'s `formValue` inside
`ProcessNPCsFormID` (immediately before `DiscardFormDigits`), and again in `FilterOutNonLoaded` on
the value of every member of `npc`, `factionFemale`, `factionMale`, `npcPluginFemale`,
`npcPluginMale`, `raceFemale` and `raceMale`. Its body builds a fresh array from an
`unordered_set<string_view> seen` and swaps it in, so a repeated name is silently deleted at parse
time. `GetRandomPresetByName` then picks uniformly from what is left.

**Impact.** Zero today — §8 correctly records that no list uses duplicates, and I confirmed all 15
blacklist entries and all 130 references are unique. The damage is to the next round: acting on the
advice would produce a rule that looks weighted in the JSON and is not, with no error anywhere.

**Smallest fix.** Documentation only. Replace §2.4's second bullet and §8's fourth bullet with:
duplicates are stripped at parse time, so the only skews available are (a) the composition of the
random pool via `blacklistedPresetsFromRandomDistribution` and (b) the length and makeup of an
individual rule's list (e.g. 1-curvy-in-4 vs 3-curvy-in-6, which is already how the Dibella and
innkeeper lists differ).

### D2 — LOW · both faction rules silently depend on powerofthree's Tweaks
**Claim.** §2.3 says `factionFemale` keys are faction EditorIDs resolved with
`RE::TESFaction::LookupByEditorID(key)` — true — but neither pt9 nor `OVERRIDES.md` records that in
Skyrim SE the engine discards EditorIDs for `FACT` at runtime, so that lookup only works because
something puts them back.

**Evidence.** `po3_Tweaks.dll` contains `D:\a\po3-Tweaks\po3-Tweaks\src\Fixes\CacheEditorIDs.cpp`
and `void __cdecl Fixes::CacheFormEditorIDs::Install(void)`; upstream, that fix's
`SetFormEditorID<T>::thunk()` does
`const auto& [map, lock] = RE::TESForm::GetAllFormsByEditorID(); … map->emplace(a_str, a_this);`
and `TESFaction` is one of the ~70 hooked types. The mod is **enabled** at modlist line 4020, and
the winning `po3_Tweaks.ini` (`LoreRim - MCM and INI Settings`, line 145 — the only copy in the
list) has `Load EditorIDs = true`. So the two faction rules **will** fire. But if a future modlist
update drops Tweaks or flips that line, `MarkarthTempleofDibellaFaction` and `JobInnkeeperFaction`
stop resolving and ~16 women quietly fall back to the random pool with no visible error.

**Smallest fix.** One line in `OVERRIDES.md` under the `factionFemale` block, plus one bullet in
O2: OBody's `FilterOutNonLoaded` erases faction keys it cannot resolve and logs
`removed '<name>'`, so *"there must be no `removed 'JobInnkeeperFaction'` or
`removed 'MarkarthTempleofDibellaFaction'` line in `OBody.log`"* is a direct, one-glance test that
the faction half of the design is live. (pt9 O2 currently mentions `removed '<name>'` only in
connection with race keys.)

### D3 — LOW · "LoreRim Glue … so it wins over everything" is not true
**Evidence.** `profiles\Ultra\modlist.txt` line 2 is `+The New Gentleman`; `+LoreRim Glue` is line 3.
With first-line-highest priority (confirmed: USSEP 4077, DLCs last, Synthesis Output 141), the glue
is priority **#2**, not #1.

**Impact.** None for this round — I checked the actual VFS winners and LoreRim Glue wins both
`SKSE\Plugins\OBody_presetDistributionConfig.json` and everything under `CalienteTools\`; TNG ships
only `tng.xml`, its own `ShapeData/SliderSets/SliderCategories/SliderGroups`, and
`TheNewGentleman.dll`. But the sentence is used as a blanket guarantee in both pt9 §0 and
`OVERRIDES.md`, and it would be wrong the first time TNG and the glue touch the same path.

**Smallest fix.** "line 3 of `modlist.txt` — above everything except `The New Gentleman`, which
shares no file path with it."

### D4 — LOW · two wording errors that read as facts
1. **O3**: *"NPCs you met before the reset will say **'Unknown Preset'**"*. The actual string is
   **`"Unknown/Unassigned Preset"`** (`OBodyNGScript.psc:126`). An owner searching the menu for the
   literal text will not find it.
2. **§2.2**: *"`ProcessNPCsFormID` **right-pads** the hex to 8 digits, strips the first 2 digits"*.
   `DiscardFormDigits` right-*aligns* the string in a zero-filled buffer, i.e. it left-pads. As
   written the two halves of the sentence contradict each other and would justify 8-digit keys
   "just to be safe"; as implemented the 6-digit keys in the file are exactly right.

**Smallest fix.** Reword both.

### D5 — LOW · "re-running `install_mo2.ps1` will simply re-sync the same files" is no longer true
**Evidence.** A full recursive SHA-256 diff of
`glue\game\LoreRimGlue` vs `F:\Modlists\LoreRim\mods\LoreRim Glue` shows **17 differing files** that
are nothing to do with this round: `Scripts\LRG*.pex` (9), `Source\Scripts\LRG*.psc` (6),
`MCM\Config\LoreRimGlue\config.json` and `settings.ini`. Installed copies are stamped 03:00:2x;
the source tree was rewritten at ~04:07 by the parallel pt9 driver/UI/server workstream. The three
pt9-body files are the only ones that match.

**Impact.** Re-running the installer with MO2 closed will also push that other workstream's
Papyrus and MCM changes. That is probably wanted, but it is not what §6 promises, and someone
re-running it to "re-sync the body files" would be shipping more than they think.

**Smallest fix.** §6: "…will re-sync these three files **and the script/MCM changes the parallel
pt9 workstream has since written into the source tree**."

### D6 — INFO · the §1 correction is off by one
§1 says three pt8 blacklist lines (`OutfitTesting`, `CBBE Curvy (Outfit)`, `CBBE Slim (Outfit)`)
were no-ops and have been removed. `pt8-body-fix.md` §… lists ten added names, and
`CBBE Slim (Outfit)` is **not** among them — pt8 shipped `OutfitTesting` and `CBBE Curvy (Outfit)`
only. Both were genuine no-ops and both are correctly gone. The arithmetic still lands on the right
place (stock 4 + pt8's 10 − 2 no-ops + 3 new = 15, which is what the file has). Cosmetic.

---

## 4. Design observations (not defects)

* **Lists A and C contain one non-curvy option each** (`CBBE Strong`, `CBBE SevenBase`), so each of
  the ~21 women on those two lists has a 1-in-3 chance of landing on a body she could have rolled
  anyway. That is deliberate anti-uniformity and I would keep it — but the *effective* count of
  visibly-fuller named women is ≈ 9 (list B, all three options curvy, Lydia among them) + ≈ 14 of
  the other 21, not a flat 36. Worth knowing before the owner reports "some of them didn't change".
* **Keerava is `ArgonianRace`** and is caught by the innkeeper rule. Beast-race females use the same
  CBBE/3BA body here, so the morphs apply normally; flagging only because it is the one non-human
  in the faction sweep.
* **The child-race blacklist is the only unrequested change in the round**, pt9 says so, and it is
  the mechanism that keeps the Sybil of Dibella out of the priestess rule. I would keep it.
* **Per-NPC body coverage is complete**: the only per-NPC `femalebody_0/1.nif` folders anywhere in
  the load order are the 12 the glue already ships (`character assets`, the three Bijin folders,
  Chaconne, Elisif, Minazuki, Serana, Succubus-San, Toccata, Valerica, Vivace). None of the 36 named
  women falls outside them, and none of pt8's eight morph-inert NPCs is on the list.

---

## 5. Bottom line

The JSON validates, every preset name and every FormID is real and case-correct, the precedence
chain does what the design claims, the extremes cannot be rolled, the natural spread is still 8 of
9, the installed files are byte-identical to source, and nothing outside `LoreRim Glue` moved.
**Ship it.** Fold D1 and D2 into `OVERRIDES.md` before the next body round, because those are the
two that would mislead future work rather than the owner.
