# pt8 — FIX: jiggle physics restored, body-shape pool curated

Applied 2026-09-21 (late evening), from the investigation in `research\pt8-body-physics.md`.

**Everything is one folder.** Nine files were added to `LoreRim Glue`, which sits at line 3 of
`profiles\Ultra\modlist.txt` and therefore wins over every other mod. **No file inside any other
mod folder was created, changed or deleted. No profile file was touched. BodySlide, Nemesis and
LOOT were not run.** Every new file also has a one-line entry in
`F:\Modlists\LoreRim\mods\LoreRim Glue\OVERRIDES.md` saying what it overrides and why.

---

## 1. What changed

| # | file (relative to the mod folder) | overrides | change |
|---|---|---|---|
| F1a | `SKSE\Plugins\CBPConfig_BreastAmplitude.txt` | `CBBE 3BA (3BBB)`'s copy — every alias at `amplitude 0.1` | all twelve breast aliases → **`1.0`** (CBPC's own default) |
| F1b | `SKSE\Plugins\CBPConfig_ButtAmplitude.txt` | 3BA's copy at `0.5` | `LButt`/`RButt`/`Butt` → **`0.8`** |
| F1c | `SKSE\Plugins\CBPConfig_BellyAmplitude.txt` | 3BA's copy at `0.5` | `Belly` and `HDTBelly` → **`0.4`** (down, on purpose) |
| F3 | `SKSE\Plugins\CBPConfig_zzGlueDefaults.txt` | **new file** | fills the `Breast.*`, `Butt.*`, `HDTBelly.*` parameter blocks that the merged config left empty |
| F4 | `SKSE\Plugins\CBPCSystem.ini` | CBPC's copy | one value: `Logging = 0` → **`1`** (temporary, for this playtest) |
| F2 | `SKSE\Plugins\StorageUtilData\CBBE 3BA\PhysicsManager.json` | 3BA's copy with `SMP.Breast = 1` | all six values → **`0`** |
| F5 | `SKSE\Plugins\AutoPhysicsReset.ini` | `Auto Physics Reset`'s copy | two values: `bScriptedSceneEnter` / `bScriptedSceneExit` `false` → **`true`** |
| B1 | `SKSE\Plugins\OBody_presetDistributionConfig.json` | `OBody Next Generation`'s stock copy | `blacklistedPresetsFromRandomDistribution` extended by **ten** preset names |
| — | `OVERRIDES.md` | new file | the register of what this mod overrides |

Only `CBPCSystem.ini` (F4) and the two `bScriptedScene*` lines (F5) are meant to be revisited later.
Everything else is a permanent part of the mod.

---

## 2. Why — the three real causes, shortest form

**Cause 1 — the breasts were running at 10% volume.** `amplitude` is CBPC's 0…1 master volume on a
bounce alias; CBPC's own copy of `CBPConfig_BreastAmplitude.txt` sets every breast alias to `1.0`,
and CBPC's `CBPCBounceinterpolationconfig_Test.txt` proves the meaning by containing nothing but
`*.amplitude 0.0 0.0` lines (that is literally how CBPC interpolates motion to a standstill). The
string `amplitude` is in `cbp.dll` next to `stiffness`, `damping`, `maxoffset`, `linearX` and the
three `breast*ArmoredAmplitude` variants, so it is a first-class parameter, not a comment.

3BA's copy of that file set all twelve aliases to `0.1`, and 3BA (modlist line 1949) beats CBPC
(line 1950), so `0.1` was the live value. Butt and belly were at `0.5` against CBPC's `1.0`.
This body is skinned to `L/R Breast01-03`, which 3BA's `CBPCMasterConfig_3BA.txt` maps to
`ExtraBreast1L…3R` — exactly the aliases that were capped. That is the root cause, and the ratio
(10×) matches "no jiggle at all" rather than "a bit weak".

The spring tuning is untouched: 3BA's `CBPConfig_3b.txt` still supplies `ExtraBreast1L.linearX 0.4008`,
`Zmaxoffset 2.3`, `timeStep 0.6` and the rest. Only the volume knob moved.

**Cause 2 — a live trap that pins the breast bones.** 3BA shipped `PhysicsManager.json` with
`SMP.Breast = 1`, i.e. "the breasts belong to HDT-SMP". But on this setup SMP covers nothing on the
female body: the body NIF has no physics-XML string at all (its only `NiStringExtraData` is
`BODYTRI`), 3BA's `defaultBBPs.xml` maps eight *male/tail/ear* shapes and no female body, and the
winning `hdtSkinnedMeshConfigs\configs.xml` is global solver tuning with no per-mesh entry.
Meanwhile 3BA's `mus3bphysicsmanager.psc` reads that key and, for every actor on its
`ActorPhysicsList`, calls `CBPCPluginScript.StopPhysics` on `L/R Breast01-03` and resets them to the
rest pose — re-applied on **every** `OnPlayerLoadGame` via `musactorphysicsalias.psc`. With all six
values now `0`, `CBPCBreasts()` returns before it can ever call `StopPhysics`.
`PapyrusUtilDev.log` shows this JSON being read and never written (`JSON Loading… / JSON Reverted`,
no `JSON Saved`), so the file on disk is the whole story and ours is the one that wins.

**Cause 3 — nothing un-stuck physics when a scene started.** `Auto Physics Reset` (line 3961) is an
SKSE plugin whose entire job is to redo the old "draw and sheathe your weapon to get the jiggle
back" trick automatically, after the game drops an actor into a synchronised or scripted animation.
Sync animations, furniture and riding were enabled; **`bScriptedSceneEnter` and
`bScriptedSceneExit` — "Scripted Scenes (Loss of control)", which is what an OStim scene looks like
to the game — were the one pair left `false`.** That fits the reported symptom precisely: physics
in the world, none in the scene. Both are now `true`, with the author's 0.5 s enter delay unchanged.
This one is a hypothesis with a good motive, not a proven cause like the amplitude — it is two
lines and is trivially reverted (see §6).

**Cause 4 (shape, not physics) — half the random preset pool was curvy, thick or bellied.**
OBody NG's config was completely stock: every distribution map empty, so every woman got a uniformly
random pick from the whole BodySlide pool. That pool contains `OutfitTesting` (a shape-test preset an
armour mod left behind: `AppleCheeks 83`, `BigButt 60`, `BigBelly 64`, `ChubbyArms 82`),
`CBBE Chubby` (`Belly 60`, `ChubbyButt 85-100`), `Warmaiden…` (`Belly 80`), `State of mind…`
(`Belly 70`), `CBBE Curvy` (`Breasts 80`, `Butt 80`), `CBBE Oppai`, `CBBE Fetish`/`v2`,
`[Dint999]…` (`Belly 40`). Roughly half the rolls, hence "every body super curvy and thick, asses
too big, bellies".

### About "the curvy option" and "the 1-100 slider"

Nothing installed has a setting called *curvy*, and **no mod here offers a 1–100 diversity slider** —
OBody NG's MCM is eight toggles, one hotkey and two reset buttons, no slider of any kind (read out
of `OBodyNGMCMScript.pex` and `OBody_ENGLISH.txt`). What was presented earlier, in
`research\pt3-nude.md` §5.1, was a *BodySlide preset shortlist* in which `CBBE Curvy` was the
fullest figure; the "1–100" is BodySlide's own per-slider scale.

The fix used here is better than either reading of that, and it is what was actually asked for:
**keep the random spread, curate the pool.** Each woman still gets her own shape, and on top of that
her own weight (0–100) blends `femalebody_0.nif` against `femalebody_1.nif` continuously — so the
variation is real, it is just now bounded to natural. The surviving random pool, verified by exact
string match against every `<Preset name="…">` in the enabled load order:

`CBBE Vanilla` · `CBBE SevenBase` · `CBBE Petite` · `CBBE Slim` · `CBBE Slim (Outfit)` ·
`CBBE Athletic` · `CBBE Strong` · `Skinny Days - Birna's Body Preset WeelBones` ·
`Observer of the void - Paranoiac's Body [By WeelBones]`

Nine female shapes, vanilla → slim → athletic. None has `Belly` above 12 or `BigButt` above 20. The
single male preset (`Sentence - Ranmir Himbo Body`) is untouched.

Excluded from the *random roll only*: `OutfitTesting`, `CBBE Chubby`, `CBBE Curvy`,
`CBBE Curvy (Outfit)`, `CBBE Oppai`, `CBBE Fetish`, `CBBE Fetish v2`,
`Warmaiden - Adrianne Avenicci SHWB's Body 3BA`, `State of mind - Psyche's Body [By WeelBones]`,
`[Dint999] SSE First CBBE Body Physics` — plus the four zeroed-slider spellings that were already
there. Because `blacklistedPresetsShowInOBodyMenu` stays `true`, **all of them are still
hand-pickable** from OBody's preset-list hotkey. Nothing was removed from any mod.

---

## 3. Verification done before installing

* Both JSON files parse. The OBody file was checked against OBody's own
  `OBody_presetDistributionConfig_schema.json`: every schema key present, **no unknown key**
  (the schema is `additionalProperties: false`, so a stray key would fail).
* All ten new preset names were re-read from the `SliderPresets\*.xml` files and compared
  **case-sensitively**: 10/10 exact matches. (The four pre-existing zeroed-slider spellings match 1
  of 4 — the other three are OBody's own defensive spellings and are harmless.)
* `AutoPhysicsReset.ini` was rebuilt from the original bytes: the body after our comment header is
  a `-ceq` exact match to the original with only the two `bScriptedScene*` values substituted, and
  the author's Korean comments survive intact (UTF-8, no BOM, as the original).
* `CBPCSystem.ini` is CBPC's file with only the `Logging` value changed.
* `CBPConfig_zzGlueDefaults.txt` defines **only** aliases that nothing else in the merged config
  defines (`Breast.*`, `Butt.*`, `HDTBelly.*`) and contains **no** `.amplitude` line — so it cannot
  fight 3BA's tuned values or the three amplitude files no matter what order CBPC merges in.
  I confirmed CBPC merges *all* `CBPConfig*.txt` unconditionally: none of 3BA's twelve bounce files
  carries a `Conditions =` line.
* `The New Gentleman` is the only mod above the glue (line 2). Its entire `SKSE` folder is
  `TheNewGentleman.dll` + `.pdb` — it cannot shadow any of the new files.

## 4. How it was installed

`glue\tools\install_mo2.ps1` was run and **refused**, with:

```
Mod Organizer is running. Close it first - profile files must not be edited while MO2 is open.
```

Skyrim was **not** running (checked: only `ModOrganizer` pid 42744). Per the standing rule for that
case, the nine files were copied by hand into `F:\Modlists\LoreRim\mods\LoreRim Glue` — **our own
mod folder only, same relative paths** — behind a guard that the target contains `LoreRimGlue.esp`,
and then verified by SHA-256:

| file | bytes | SHA-256 match |
|---|---|---|
| `OVERRIDES.md` | 9714 | ✔ |
| `SKSE\Plugins\CBPConfig_BreastAmplitude.txt` | 1657 | ✔ |
| `SKSE\Plugins\CBPConfig_ButtAmplitude.txt` | 694 | ✔ |
| `SKSE\Plugins\CBPConfig_BellyAmplitude.txt` | 640 | ✔ |
| `SKSE\Plugins\CBPConfig_zzGlueDefaults.txt` | 6453 | ✔ |
| `SKSE\Plugins\CBPCSystem.ini` | 3669 | ✔ |
| `SKSE\Plugins\AutoPhysicsReset.ini` | 5061 | ✔ |
| `SKSE\Plugins\OBody_presetDistributionConfig.json` | 1115 | ✔ |
| `SKSE\Plugins\StorageUtilData\CBBE 3BA\PhysicsManager.json` | 153 | ✔ |

No profile file was edited — none needed to be: `+LoreRim Glue` is already line 3 of
`profiles\Ultra\modlist.txt` and `LoreRimGlue.esp` is already in `plugins.txt`/`loadorder.txt`.
Nothing else in the mod folder changed. Re-running `install_mo2.ps1` later with MO2 closed is still
safe and will simply re-sync the same files.

**`install_mo2.ps1` was not modified.** Its `-NoNudeBody` switch excludes exactly the `meshes\` tree
(`robocopy … /XD <source>\meshes`); everything added here lives under `SKSE\`, so all nine files
install with or without that switch — which is correct, because the physics config must apply
whether or not the nude body is enabled.

---

## 5. How to tell it worked — in this order

**Step 1 — out of a scene first.** Third person, jump, then sprint. Breasts and butt should now move
clearly. If they move here, Cause 1 is fixed. **Judge nothing else until this passes.**

**Step 2 — in a scene.** Start an OStim scene. If it moves out of a scene but not in one, then
Cause 1 was the fix and Cause 3 (Auto Physics Reset) is not doing its job — say so and it gets
looked at separately.

**Step 3 — the logs.** All three are in
`C:\Users\Jordan\Documents\My Games\Skyrim Special Edition\SKSE\`:

* **`OBody.log`** — this is the definitive check that the body-shape change took. It must still say
  ```
  Validated Data/SKSE/Plugins/OBody_presetDistributionConfig.json successfully
  ```
  (if the JSON were malformed OBody would log a validation failure instead — so that line *is* the
  proof), and a few seconds later the count line must change from what it said last night:
  ```
  before:  Blacklisted: Female presets: 1, Male Presets: 0
  after :  Blacklisted: Female presets: <a number well above 1>, Male Presets: 0
  ```
  `Female presets: 17` should stay the same — the presets are still installed, just not rolled.
  And no new line should ever read `Preset CBBE Curvy … will be applied to …`,
  `Preset CBBE Chubby …`, `Preset OutfitTesting …`, `Preset Warmaiden - … will be applied to …`.
* **`CBPC-Collision.log`** — was 4187 bytes of config-load messages only. With `Logging = 1` it will
  now grow steadily and record per-actor/per-node detail; that growth alone tells you the new
  `CBPCSystem.ini` is the one being read. The existing load block must still end with
  `Collision Config file is loaded successfully.` / `Player Collision Event Config file is loaded
  successfully.` and must **not** contain `… is not loaded.` for any of Master, System or Collision.
  CBPC does not log parameter values, so the amplitude itself is proven by eye (step 1), not here.
* **`skse64.log`** — unchanged expectation:
  `plugin cbp.dll (00000001 CBPC 00010702) loaded correctly (handle 40)`.

**Step 4 — turn the logging back off.** Once you are satisfied, say so and I will flip one line
(`Logging = 1` → `0`) in the glue's `CBPCSystem.ini` and re-install. Leaving it on costs performance
and the log grows without limit — CBPC's own warning.

**Handy while testing:** CBPC registers a console command (strings taken from `cbp.dll`):
`cbpc reload` re-reads the Master / Collision / bounce config files and `cbpc sysreload` re-reads
`CBPCSystem.ini`, so bounce tweaks can be tried without restarting Skyrim. (`cbpc pause` /
`cbpc start` also exist.) A normal save load reloads them too — `CBPC-Collision.log` showed the load
block five times last night for five save loads.

---

## 6. Owner steps

**O1 — clear 3BA's SMP actor list, if the page exists.**
Correction to the investigation: **3BA v2.48 has no "Physics Manager" MCM page**, and no MCM option
of any kind that sets the CBPC/SMP split — that split lives only in the `PhysicsManager.json` this
mod now overrides. Read out of `Mus3BAddonMCM` (`GetVersion() = 13`), 3BA's MCM has exactly two
pages and the only relevant control is a toggle:

| page (script key) | option (script key) | what it does |
|---|---|---|
| `$PageName_ActorList` | `$ClearActorList` | `PM.CancleActorList()` → unequip the SMP collision armour from every listed actor → `PM.CleanActorList()`; on-screen notifications `Cleaning SMP Actor List...` then `Cleaning SMP Actor List done` |

In an English build SkyUI shows those as **"Actor List"** and **"Clear Actor List"** under the mod
name `$MODNAME` (normally *CBBE 3BA*). Honest caveat: I could not find 3BA's MCM translation file or
its compiled scripts anywhere on disk (`3BBB.bsa` holds only `scripts\source` and
`meshes\armor\mu3b`; there is no `Mus3B*.pex` under `F:\Modlists\LoreRim`), yet `PapyrusUtilDev.log`
proves its Papyrus side does run — so they are coming from a repacked archive I did not identify.
Practical version: **if you can find that page, press "Clear Actor List" once, in a cell with no
other NPCs, then save and reload.** Actors previously switched to 3BA SMP are recorded in an ESP
FormList that lives in the save, not on disk, and they stay pinned until that list is emptied.
**If the page is not there, skip it** — the list only ever fills when the toggle spell/hotkey is
used, so it is almost certainly empty.

**O2 — do not press Numpad `+` or Numpad `9`.** Those are 3BA's default toggle hotkeys
(`PKEY = 78` player, `NPCDKEY = 73` NPC, from the MCM script). They are what put an actor on the SMP
list and equip the collision armour. With `SMP.Breast` now `0` the worst case is much milder, but
there is no reason to touch them: CBPC is the engine on this setup.

**O3 — reset OBody's already-handed-out presets.** This is required; without it every woman you have
already met keeps last night's roll. Exact labels from `OBody_ENGLISH.txt`:

1. MCM → **OBody NG** (one page) → **"Reset all distributed presets"**.
2. Do it in a cell with as few NPCs as possible — a player home. OBody's own help text:
   *"Please do this on a cell with no other NPCs or with as few NPCs as possible, such as a player
   home."*
3. Accept the confirmation, then obey the message literally:
   *"PLEASE SAVE THE GAME AND EXIT THE GAME NOW! Then start Skyrim and reload your save again.
   This is needed for the reset to work!"* — save, **fully exit Skyrim**, restart, reload.

**O4 — your own character is never distributed to.** If the player body is too curvy, press the
OBody **"Presets list key"** with nothing in the crosshair and pick e.g. `CBBE Athletic`,
`CBBE Slim` or `CBBE Vanilla`. `"Reset this actor:"` (same page) clears OBody's morphs from whoever
is in the crosshair, or from you when the crosshair is empty.

**O5 — if a scene ever stutters or snaps at the instant it starts**, that is F5. Tell me and I will
set `bScriptedSceneEnter` back to `false` while keeping `bScriptedSceneExit = true`.

**O6 — if it now looks cartoonish**, say so: the six `ExtraBreast*.amplitude` lines go from `1.0`
to `0.6` and that is the whole change.

**O7 — optional, only after you have seen the result of O3.** A truly neutral base body. OBody's
preset is applied as morphs *on top of* the base mesh, and the base mesh here is LoreRim's Astrid
build (measured in `pt4-nude-verify.md` §1.7 as within 0.1 game units of default CBBE at both
weights, so it is *not* what made everyone thick — but it is not a blank canvas either). If you ever
have BodySlide open anyway: outfit **`CBBE 3BBB Body Amazing`** (3BA's only body set), preset
**`- Zeroed Sliders -`** or **`CBBE Vanilla`**, **Build Morphs ticked**, both weights — then hand me
the resulting `femalebody_0/1.nif` and `femalebody.tri` and I will replace the glue's 24 copies.
Agents must not run BodySlide, so this one is yours or nobody's.

**O8 — close Mod Organizer** next time before asking for an install, so `install_mo2.ps1` can run
its normal path (backups, modlist/plugins checks) instead of a hand copy.

---

## 7. Deliberately not done

* **No BodySlide / Nemesis / LOOT run**, and no mesh changed. The body already has all 31 physics
  bones and every one of them exists in the winning `skeleton_female.nif`; nothing needed rebuilding
  for physics. 3BA v2.48 ships only one body slider set and it has no SMP variant.
* **CBPC was not moved above `CBBE 3BA (3BBB)` in MO2.** It would restore CBPC's amplitudes but
  throw away 3BA's `CBPCollisionConfig.txt`, `CBPCollisionConfig_Female.txt` and the whole tuned
  `_3b` / `_butt` / `_belly` / `_leg` set — and it edits a profile file. Four small files in our own
  mod is smaller and reversible.
* **No mod's BodySlide preset XML was edited.** The OBody blacklist is the correct lever and it
  leaves every preset hand-pickable.
* **`FpsCorrection` left at CBPC's `0`.** The framerate cap was changed 240 → 60 in
  `SSEDisplayTweaks.ini`, and with correction off the bounce amount does vary with framerate — but
  the amplitude change is a 10× effect and dominates. One variable at a time; `FpsCorrection = 1` is
  the next knob if motion still feels weak after step 1 passes, and it is one line in the glue's own
  `CBPCSystem.ini`.
* **The eight named NPCs outside the glue's reach** (Lelaegh, Nirya, Birna, Mirabelle, Faralda,
  Rayya, Susanna, Adrianne) were left alone. Their bodies live under their overhaul's own folder
  names, are already nude 3BA physics bodies with the same 31 bones — **so they do get the restored
  jiggle** — but they point at LoreRim's old `femalebody.tri`, whose shape name does not match their
  mesh, so OBody/RaceMenu morphs are inert for them and they will always wear their author's fixed
  shape. Copying those eight into the glue under their own paths would fix that; it is a separate
  decision, not a physics issue.
* **`TheNewGentleman`'s genitals problem** (`TheNewGentleman.log`: every playable race *"cannot have
  any genitals since their skin cannot be recognized"*, blamed on `Synthesis - World.esp`) is
  untouched. It does not affect females or physics.
* **`install_mo2.ps1` unchanged** — see §4 for why no `-NoNudeBody` change was needed.
