# pt8 — VERIFY: jiggle physics + body-shape curation

Verified 2026-09-21 late / 2026-09-22 early. **Read-only**: this file is the only thing written.
Nothing under `F:\Modlists\LoreRim` was created, modified or deleted. MO2, the game, BodySlide,
Nemesis and LOOT were not launched. Helper scripts went to
`C:\Users\Jordan\AppData\Local\Temp\lrg_test\` (`nifchk.py`, `nifstr.py`) — scratch only.

Checking `research\pt8-body-fix.md` against the live install and against
`research\pt8-body-physics.md`.

## VERDICT: **SHIP** — with two caveats that must be relayed to the owner (D1, D2)

The core fix is correct. All nine files are installed, hash-match their source, win by priority,
are well-formed in their own syntax, and the primary lever (breast `amplitude` 0.1 → 1.0) targets
exactly the bone aliases this body is actually skinned to — I re-parsed the NIF headers to confirm
that rather than trusting the earlier notes. The OBody curation validates against OBody's own
schema and excludes precisely the big-butt/belly presets, keeping a genuine natural spread.

No defect requires undoing anything. The defects below are one standing installer hazard that
arose **after** the fix was installed (D1), one unstated scope limit on the weakest fix (D2), and
four minor documentation/verification-guidance errors.

---

# PART A — WHAT PASSED

## A1. All nine files installed and identical to source

SHA-256 of source vs installed: **9/9 MATCH**, byte lengths as the fix report claims.

| file | bytes | src vs dst |
|---|---|---|
| `OVERRIDES.md` | 9714 | MATCH |
| `SKSE\Plugins\CBPConfig_BreastAmplitude.txt` | 1657 | MATCH |
| `SKSE\Plugins\CBPConfig_ButtAmplitude.txt` | 694 | MATCH |
| `SKSE\Plugins\CBPConfig_BellyAmplitude.txt` | 640 | MATCH |
| `SKSE\Plugins\CBPConfig_zzGlueDefaults.txt` | 6453 | MATCH |
| `SKSE\Plugins\CBPCSystem.ini` | 3669 | MATCH |
| `SKSE\Plugins\AutoPhysicsReset.ini` | 5061 | MATCH |
| `SKSE\Plugins\OBody_presetDistributionConfig.json` | 1115 | MATCH |
| `SKSE\Plugins\StorageUtilData\CBBE 3BA\PhysicsManager.json` | 153 | MATCH |

The source `SKSE\` tree contains exactly these eight files — nothing was left behind.

## A2. Every file really wins by priority

`profiles\Ultra\modlist.txt` is 4086 lines; line 1 is the MO2 header comment.

```
    2: +The New Gentleman
    3: +LoreRim Glue        <- our override point
    5: +OBody Next Generation
 1949: +CBBE 3BA (3BBB)
 1950: +CBPC - Physics with Collisions for SSE and VR
 3961: +Auto Physics Reset
```

Exhaustive sweep of every mod folder for the nine paths — the complete provider list is:

| path | other providers | glue wins? |
|---|---|---|
| `CBPConfig_BreastAmplitude.txt` | 3BA (378 B), CBPC (280 B) | yes |
| `CBPConfig_ButtAmplitude.txt` | 3BA (76 B), CBPC (74 B) | yes |
| `CBPConfig_BellyAmplitude.txt` | 3BA (25 B), CBPC (23 B) | yes |
| `CBPConfig_zzGlueDefaults.txt` | none | new file |
| `CBPCSystem.ini` | CBPC (2382 B) | yes |
| `AutoPhysicsReset.ini` | Auto Physics Reset (3700 B) | yes |
| `OBody_presetDistributionConfig.json` | OBody NG (854 B) | yes |
| `StorageUtilData\CBBE 3BA\PhysicsManager.json` | 3BA (169 B) | yes |

`The New Gentleman` (line 2) is the only mod above the glue and its entire `SKSE` tree is
`TheNewGentleman.dll` + `.pdb` — it cannot shadow any of these. Confirmed by listing.

**The check the fix report did not make, and the one that could have sunk this: MO2's `overwrite`
folder outranks *every* mod.** It is clean — `overwrite\SKSE\Plugins` holds only `IED\`,
`SkyrimVanitySystem\`, `MainMenuRandomizer.log` and `TheNewGentleman5.ini`; there is no
`CBP*`, no `OBody_preset*`, no `AutoPhysicsReset.ini` and no `StorageUtilData\` anywhere under it.
`Stock Game\Data\SKSE\Plugins` has no loose copy of any of the nine either. So nothing outranks
the glue.

## A3. Nothing else was touched

* **Profile files**: every `profiles\Ultra\*` timestamp is **22:18:14 or earlier**
  (`modlist.txt` 16:54:54, `plugins.txt`/`loadorder.txt`/`lockedorder.txt` 22:18:14,
  `initweaks.ini` 21:43:19). The nine files were written **23:17:16–23:20:57**. No profile file
  was edited. `+LoreRim Glue` is still line 3 as before.
* **Other mod folders**: a recursive scan of all of `F:\Modlists\LoreRim\mods` for anything
  modified at or after 23:10, excluding `LoreRim Glue`, returns **zero files**. Spot-checking the
  mods the report reasons about confirms it — newest file in `CBBE 3BA (3BBB)` is 2026-09-20
  22:27, `CBPC` 2026-09-21 12:26 (`meta.ini`), `OBody Next Generation` 01:45, `Auto Physics Reset`
  2026-09-20 22:10, CBBE 2026-09-20 22:19, and all six preset-providing NPC-overhaul mods
  2026-09-20. `LoreRim - MCM and INI Settings` newest is 22:18 (the game session's own
  `MCM-Unlocked_UserData.json`), still before the fix window.
* **The glue's own meshes and scripts** are untouched by pt8: meshes 2026-09-20 22:06, `.pex`
  2026-09-21 20:30 — all before the fix window.

## A4. CBPC config syntax is valid

Checked against CBPC's own shipped files rather than assumed:

* `#` line comments are native CBPC syntax — CBPC's own `CBPConfig.txt` opens with 13 of them.
* Two-value form `<alias>.amplitude <lowWeight> <highWeight>` is exactly CBPC's own format;
  CBPC's `CBPConfig_BreastAmplitude.txt` is eleven such lines at `1.0 1.0`.
* The glue's breast file is a **superset** of CBPC's (it keeps 3BA's extra
  `ExtraBreast1/2/3.amplitude` lines), all at `1.0 1.0`. Butt `0.8`, belly `0.4`, as documented.

**`CBPConfig_zzGlueDefaults.txt` — the gap-filler claim holds exactly.** I built the winning set of
18 `CBPConfig*.txt` files (glue wins 3 by name + its new file, 3BA supplies 14, CBPC 0) and
compared every `alias.property` key:

* **136 keys in `zzGlueDefaults`, zero collisions** with any other winning file. The claim "it
  defines ONLY aliases that nothing else defines" is true.
* **No `.amplitude` line in it** — confirmed, so each alias's master volume is set in exactly one
  place.
* Every `Breast.*` and `Butt.*` value is **verbatim** from CBPC's `CBPConfig.txt`, and **no**
  CBPC `Breast.*`/`Butt.*` key was dropped.
* `HDTBelly.*` matches 3BA's `CBPConfig_belly.txt` `Belly.*` values.
* **No winning `CBPConfig*.txt` carries a `Conditions =` line** — verified across all 18, so they
  all merge as defaults and the `zz` sort-last precaution is belt-and-braces, not load-bearing.
  Each amplitude alias is defined in exactly one file (breast 12 aliases in the glue's file, butt
  3, belly 2, leg 6 in 3BA's untouched `CBPConfig_LegAmplitude.txt`).

## A5. The other two config files are single-value edits

* **`CBPCSystem.ini`**: 14/14 keys present, **exactly one** changed — `Logging` `0` → `1`
  (the trailing comment was re-annotated "LoreRim Glue: was 0"). `SkipFrames`, `ActorDistance`,
  `FpsCorrection = 0` etc. all preserved.
* **`AutoPhysicsReset.ini`**: 24/24 keys present, all four sections (`[Main] [Triggers] [Delay]
  [Debug]`) preserved, **exactly two** changed — `bScriptedSceneEnter` and `bScriptedSceneExit`
  `false` → `true`. `;` comment style matches the original, no BOM. The two lines a previous
  editor annotated "User set to false" (`bCellTransitionReset`, `bFollowerSupport`) are left
  `false` as claimed.
  **All 24 key names are present as literal strings inside `AutoPhysicsReset.dll`**, including
  `bScriptedSceneEnter` / `bScriptedSceneExit` — so the keys are real and spelled right.
* **`PhysicsManager.json`**: parses; same object shape and same six keys as 3BA's copy, all six
  values `0` (3BA's had `Breast : 1`).

## A6. The NIF headers carry the bones CBPC needs — and the fix targets the right aliases

Parsed with my own header/string-table parser (`nifchk.py`, `nifstr.py`), not guessed.

`LoreRim Glue\meshes\actors\character\character assets\femalebody_0.nif` and `_1.nif`
(2,009,928 B each, `Gamebryo 20.2.0.7`, user 12, bs 100, 71 blocks, 57 strings, 52 `NiNode`,
3 `BSTriShape`):

```
breast bones : L Breast01, L Breast02, L Breast03, R Breast01, R Breast02, R Breast03
               ( NPC L Breast / NPC R Breast are NOT in this mesh )
butt / belly : NPC L Butt, NPC R Butt, NPC Belly        ( HDT Belly is NOT in this mesh )
legs         : NPC L/R FrontThigh, NPC L/R RearThigh, NPC L/R RearCalf [LrClf]/[RrClf]
SMP/HDT/xml  : (none)
only extra   : BODYTRI -> actors\character\character assets\femalebodyastrid.tri
```

Two conclusions, both load-bearing:

1. **The primary fix is aimed correctly.** The mesh's breast skinning is `L/R Breast01–03`, which
   3BA's `CBPCMasterConfig_3BA.txt` maps to `ExtraBreast1L…3R` — precisely the six aliases the
   glue's `CBPConfig_BreastAmplitude.txt` restores from `0.1` to `1.0`. The alias set that was
   capped is the alias set that drives this body.
2. **The fixer's inline comments are accurate.** `CBPConfig_BellyAmplitude.txt` says "This body is
   skinned to `NPC Belly`, not `HDT Belly`" — correct. `CBPConfig_ButtAmplitude.txt` says the body
   is skinned to `NPC L/R Butt` — correct. No SMP string exists in the body, so CBPC is the right
   engine and F2's premise (SMP covers nothing here) stands.

Winning skeleton `XPMSSE Left Hand Sheath Rotation Fix\…\skeleton_female.nif` (869 blocks, 716
strings) contains every node either ConfigMap names: `NPC L/R Breast`, `L Breast01–04`,
`NPC L/R Butt`, `NPC Belly`, `HDT Belly`, `NPC L/R FrontThigh`, `NPC L/R RearThigh`,
`NPC L/R RearCalf [LrClf]/[RrClf]`. Nothing the configs reference is missing.

(Note for the record: `NPC L/R RearCalf` carry the bracket suffix `[LrClf]`/`[RrClf]` in both mesh
and skeleton. An exact-string check without the suffix reports them missing — they are not. Leg
amplitudes are unchanged by this fix anyway.)

## A7. The OBody change is correct, validated, and hits the right presets

* **Parses**, 21 top-level keys.
* **Validates against OBody's own `OBody_presetDistributionConfig_schema.json`** (10,626 B):
  schema declares 21 properties and `additionalProperties: false`; our file has all 21 and **no
  unknown key**, so the `Validated … successfully` line will still appear.
* **Key-by-key diff against the stock file: exactly one key changed.** All 19 other maps/arrays
  and `blacklistedPresetsShowInOBodyMenu: true` are byte-identical in value. All 4 stock blacklist
  entries retained, **+10** added.
* **All 10 new names match a real `<Preset name="…">` on disk, case-sensitively: 10/10.** I
  enumerated every `SliderPresets\*.xml` across the 8 provider mods (all 8 confirmed **enabled**)
  — 22 presets total. Of the 4 pre-existing entries only `- Zeroed Sliders -` exists; the other
  three are OBody's own defensive spellings, exactly as the fix report states.

**The blacklist excludes what the owner complained about.** Measured slider values (BodySlide
0–100, B = high weight / S = low weight):

| blacklisted preset | the offending sliders |
|---|---|
| `OutfitTesting` | BigBelly B64, **BigButt B60/S60**, **AppleCheeks B83/S83**, ChubbyWaist B63 |
| `CBBE Chubby` | **Belly B60/S60**, BigBelly B25, **ChubbyButt B100/S85**, ChubbyWaist B60/S55 |
| `Warmaiden - Adrianne Avenicci SHWB's Body 3BA` | **Belly B80/S80**, BigBelly B35/S35 |
| `State of mind - Psyche's Body [By WeelBones]` | **Belly B70/S70**, ChubbyWaist B75/S75 |
| `CBBE Curvy` / `CBBE Curvy (Outfit)` | **Butt B80/S50**, **Breasts B80**, AppleCheeks B35 |
| `CBBE Fetish` / `v2` | AppleCheeks B35/S25, ChubbyButt B40/S20, Thighs B40/S40 |
| `[Dint999] SSE First CBBE Body Physics` | Belly B40, BigButt B15, AppleCheeks B20 |
| `CBBE Oppai` | 7B Lower 100, BreastPerkiness −25 (big heavy breasts) |

**The surviving pool is a real, natural spread** — the fix report's claim "none has `Belly` above
12 or `BigButt` above 20" is **verified**:

| surviving preset | key sliders |
|---|---|
| `CBBE Vanilla` | VanillaSSEHi/Lo 100 only |
| `CBBE SevenBase` | 7B Lower/Upper 70/30 |
| `CBBE Petite` | Belly **S−25** (flatter than default) |
| `CBBE Slim`, `CBBE Slim (Outfit)` | Butt B50/S50 |
| `CBBE Athletic` | Breasts B30 |
| `CBBE Strong` | BigButt B20/S10, Butt B50/S50 |
| `Observer of the void - Paranoiac's Body [By WeelBones]` | BigBelly B12/S12, ChubbyButt B30/S30, Thighs B28 |
| `Skinny Days - Birna's Body Preset WeelBones` | ChubbyWaist B30/S30, Thighs B14 |

Max `BigBelly` among survivors = 12, max `BigButt` = 20, no positive `Belly` at all. The one male
preset (`Sentence - Ranmir Himbo Body`) is untouched, as is `TNG Default`.

**The diagnosis is empirically confirmed by the owner's own session.** Distinct presets OBody
actually rolled, from `OBody.log`:

```
  17x  Sentence - Ranmir Himbo Body            (male actors)
   4x  CBBE SevenBase
   2x  Warmaiden - Adrianne Avenicci SHWB's Body 3BA   <- Belly 80, now blacklisted
   2x  Skinny Days - Birna's Body Preset WeelBones
   1x  CBBE Oppai / CBBE Curvy / CBBE Fetish            <- all three now blacklisted
   1x  CBBE Athletic / CBBE Vanilla / Observer of the void
```

Four of the ten rolls that shaped women were presets the owner objected to, and all four are now
excluded. This is not a speculative fix.

## A8. The owner-facing MCM steps name real pages and options

* **O3 / O4 (OBody) are exact.** Read from `OBody NG\Source\Scripts\OBodyNGMCMScript.psc` (shipped
  as source) and `Interface\translations\OBody_ENGLISH.txt`:
  `Modname = "OBody NG"`, a **single** `SKI_ConfigBase` page, options
  `Reset all distributed presets`, `Presets list key`, `Reset this actor:` — all three quoted
  correctly. The help text the report quotes ("*Please do this on a cell with no other NPCs…*",
  "*PLEASE SAVE THE GAME AND EXIT THE GAME NOW!*") is verbatim from the translation file.
  With nothing in the crosshair the preset key and reset both target the player — as O4 says
  (`if actorInCrosshair == none / actorInCrosshair = OBody.PlayerRef`).
* **The report's central claim to the owner is verified from source**: OBody's MCM builds only
  `AddToggleOption`, `AddKeyMapOption`, `AddTextOption` and `AddEmptyOption` — there is **no
  `AddSliderOption` anywhere**. So "no installed mod offers a 1–100 diversity slider" is correct,
  and redirecting the owner to a curated random pool is the right answer to what he asked for.

---

# PART B — DEFECTS

## D1 — MAJOR — Owner step O8 is now unsafe: the source of truth has diverged

`pt8-body-fix.md` §4 says *"Re-running `install_mo2.ps1` later with MO2 closed is still safe and
will simply re-sync the same files"*, and **O8 tells the owner to close MO2 next time so the
installer can run its normal path.** That was true when written. It is not true now.

`install_mo2.ps1` line 49 is `robocopy $source $modDir /E` — it copies the **entire** source tree,
not just the physics files. A full source-vs-installed comparison of
`glue\game\LoreRimGlue` against `F:\Modlists\LoreRim\mods\LoreRim Glue` (58 source files vs 50
installed) shows a separate workstream advanced the shared source **after** this fix landed —
every diverging file is timestamped **23:38–23:55**, i.e. after the 23:17–23:20 hand-copy:

| would be newly installed | | would be overwritten | installed → source |
|---|---|---|---|
| `Scripts\LRG_Dialogue.pex` | 85,358 B | `LoreRimGlue.esp` | 649 → **683 B** |
| `Scripts\LRG_DlgProbe.pex` | 51,580 B | `MCM\Config\LoreRimGlue\config.json` | 19,478 → 38,306 B |
| `Scripts\LRG_DlgUI.pex` | 32,183 B | `MCM\Config\LoreRimGlue\settings.ini` | 2,803 → 4,854 B |
| + 4 matching `.psc` / `.bak-docstrings` | | `Scripts\LRG_OStim.pex` | 110,614 → 121,584 B |
| | | `Scripts\LRG_Main.pex`, `LRG_Profile.pex` | both larger |

Running the installer now would push an entire untested dialogue subsystem live **and replace
`LoreRimGlue.esp` under an existing save** — in one unannounced step, while the owner believes he
is re-syncing four physics configs.

**Not the fixer's fault** — the divergence post-dates the install. But it is a live hazard created
by the report's own instruction.

**Smallest fix:** relay to the owner that `install_mo2.ps1` must **not** be run to "re-sync the
physics files" — they are already installed and hash-verified (A1), so there is nothing to
re-sync. Treat O8 as withdrawn until the dialogue work is deliberately released and tested as its
own change. If a physics value later needs tweaking, hand-copy that one file as before.

## D2 — MAJOR — F5 (Auto Physics Reset) cannot affect the NPC partner; the report does not say so

The report justifies F5 with *"That fits the reported symptom precisely: physics in the world, none
in the scene"* and presents it as the third cause. But `Auto Physics Reset` is **player-scoped**:

* Strings in `AutoPhysicsReset.dll`: `[AutoPhysicsReset] Manual Reset key: Triggering reset for
  player and teammates.`, `Forceful reset skipped for follower {:X}: player is on a mount.`,
  `void __cdecl Hooks::PlayerHook::Install(void)`, `PlayerCharacter Update Hooked.`
* Everything beyond the player is gated behind `bFollowerSupport`, which is **`false`** — and
  deliberately so: LoreRim's own copy carries the annotation `; User set to false.`
* The mod author's own description (`meta.ini` → `nexusDescription`) states it plainly:
  *"APR applies only to the player."*

So if the missing jiggle the owner noticed was on the **female NPC partner** — the most likely
reading of "there wasn't a lot of jiggle physics in the sex scene" — F5 does nothing for it. F5
can only help the player's own body.

**Smallest fix:** documentation only. State the scope: F5 covers the player character, not the
partner. Keep it (it is two reversible lines and the player is half the scene). Do **not**
reflexively set `bFollowerSupport = true` to widen it — that reverses a deliberate LoreRim
performance choice and the author warns the reset cost multiplies per actor; and it still would
not cover a non-follower partner. The honest position is that the partner's jiggle rests entirely
on F1, which is the well-evidenced fix anyway.

## D3 — MINOR — F5 contradicts the author's own guidance and leaves no evidence trail

Two smaller problems with the same file:

1. The author explicitly recommends Exit over Enter — *"It feels smoother to trigger the reset at
   the end of an animation (Exit) rather than at the beginning (Enter)"* — and warns that each
   reset restarts all physics on the actor, *"which can cause brief stuttering"*. The fix enables
   **both**. The report's O5 offers the Enter→`false` rollback only reactively.
2. `bEnableLog` was left `false`, so the playtest will produce **no evidence** whether the
   ScriptedScene trigger fired at all. The DLL contains the trigger labels `ScriptedScene (Enter)`
   and `ScriptedScene (Exit)`, and the current `AutoPhysicsReset.log` is just two startup lines
   (`Settings loaded: bEnabled=true, uToggleKey=0x44, bEnableLog=false`) which do not echo the
   trigger booleans — so there is currently no way to confirm even that the glue's copy of the ini
   is the one being read.

**Smallest fix:** set `bEnableLog = true` in the glue's `AutoPhysicsReset.ini` for the same
playtest that carries F4's `Logging = 1`, and turn both off together afterwards. Consider starting
at `bScriptedSceneEnter = false` / `bScriptedSceneExit = true` (the author's recommendation) rather
than waiting for the owner to report a stutter.

## D4 — MINOR — the OBody.log success criterion is probably wrong and will look like a failure

`pt8-body-fix.md` §5 step 3 tells the owner:

> `Female presets: 17` should stay the same — the presets are still installed, just not rolled.

That is likely backwards. The pre-fix log reads:

```
[21:46:15.507] Female presets: 17, Male presets: 1
[21:46:15.507] Blacklisted: Female presets: 1, Male Presets: 0
```

There are **20** female presets on disk. `17 + 1 blacklisted = 18`, and 18 is exactly 20 minus the
two slider-identical duplicates (`CBBE Curvy (Outfit)` is byte-identical in sliders to
`CBBE Curvy`, `CBBE Slim (Outfit)` to `CBBE Slim`). That arithmetic only works if
**`Female presets:` is the post-blacklist pool**, not the installed count. Ten of the eleven
newly-blacklisted names are recognised female presets, so the line should **drop to roughly 8**
and `Blacklisted: Female presets:` should rise to about **10**.

If the owner sees `Female presets: 8` after being told it must stay at 17, he will reasonably
conclude the change broke something.

**Smallest fix:** correct the expectation — expect `Female presets` to fall to ~8 and
`Blacklisted: Female presets` to rise to ~10. The two criteria that are unambiguous and should be
the ones quoted: the `Validated Data/SKSE/Plugins/OBody_presetDistributionConfig.json
successfully` line must still appear, and no new line may read `Preset CBBE Curvy …`,
`Preset CBBE Chubby …`, `Preset OutfitTesting …` or `Preset Warmaiden - … will be applied to …`.

## D5 — MINOR — "nine surviving female shapes" is probably eight

Both `pt8-body-fix.md` §2 and `OVERRIDES.md` list nine survivors including **both** `CBBE Slim`
and `CBBE Slim (Outfit)`. Those two are slider-identical (38 sliders, same values), and per D4's
arithmetic OBody appears to count such a pair once. The effective pool is most likely eight female
shapes. Cosmetic; the spread is still vanilla → slim → athletic either way.

## D6 — MINOR — F2's premise is unconfirmable, so O1 is probably inapplicable (fixer already hedged)

The report's O1 already says honestly that it could not find 3BA's MCM. I confirmed the stronger
version: **3BA's compiled Papyrus is not in the load order at all.**

* No `mus*.pex` anywhere under `mods\` or under `Stock Game\`.
* `3BBB.bsa` (10,046,544 B) contains the string `mus3bphysicsmanager` but **contains no `.pex`
  string at all** — only `scripts\source`, i.e. the `.psc` sources.
* No `MCM\Config\…3B…` directory anywhere; 3BA ships no `MCM` folder.
* Nothing 3BA-related in `overwrite`.

Yet `PapyrusUtilDev.log` does record `JSON Loading: Data/SKSE/Plugins/StorageUtilData/CBBE 3BA/
PhysicsManager.json` and `JSON Reverted …`, so *something* reads that path — presumably a repacked
archive I did not identify among 784 BSAs (a full scan was too slow to be worth it here).

This does not endanger anything: **F2 is safe either way.** Setting all six values to `0` can only
*prevent* `StopPhysics`, never cause it — if 3BA's scripts run, the trap is disarmed; if they do
not, the file is inert. And if 3BA has no MCM page and no toggle spell, `ActorPhysicsList` can
never have been populated, which is exactly the fixer's own fallback reasoning.

**Smallest fix:** none. Keep O1's hedge; it is correct. Worth telling the owner plainly that O1 is
expected to be a no-op and he should not hunt for the page.

## D7 — MINOR — `OVERRIDES.md` wording: "byte-for-byte the stock file"

`OVERRIDES.md` describes the OBody file as *"byte-for-byte the stock file with **only**
`blacklistedPresetsFromRandomDistribution` extended"*. It is **semantically** identical — I
verified all 20 other keys match stock exactly — but it was re-serialised (pretty-printed, one
array element per line), 854 B → 1115 B, so it is not byte-for-byte. Cosmetic wording only.

## Observation, not a defect — F3 does slightly change butt feel, not only "insure" it

`zzGlueDefaults` gives the `Breast` and `Butt` aliases full parameter blocks where previously they
had only `.amplitude`. This body **is** skinned to `NPC L/R Butt`. If CBPC resolves the duplicated
ConfigMap key in favour of its own line (`NPC L Butt=Butt`), butt motion will now run on **CBPC's**
springs (`stiffness 0.03`, `damping 0.05`, `±2.44` offsets) at amplitude `0.8`, rather than on
3BA's tuned `LButt`/`RButt` values. That is the intended merge-order independence and it is a sane
result, but it is a behaviour change rather than pure insurance. If butt motion reads oddly after
the playtest, this is the knob — deleting `zzGlueDefaults`' `Butt.*` block reverts to 3BA's tuning
and leaves the breast fix untouched.

---

# SUMMARY TABLE

| # | severity | defect | smallest fix |
|---|---|---|---|
| D1 | **major** | O8 invites re-running `install_mo2.ps1`, which would now also install an untested dialogue subsystem and swap `LoreRimGlue.esp` (649→683 B) under a live save — source diverged at 23:38–23:55, after the fix | withdraw O8; nothing needs re-syncing (A1 hashes match) |
| D2 | **major** | F5 is player-only (`bFollowerSupport = false`; author: "APR applies only to the player") so it cannot restore the NPC partner's jiggle; report implies it addresses the scene symptom | document the scope; keep F5; do not widen it |
| D3 | minor | F5 enables Enter against the author's Exit-only advice, and `bEnableLog = false` leaves no evidence it fired | `bEnableLog = true` for the playtest; consider Exit-only first |
| D4 | minor | "`Female presets: 17` should stay the same" — it should drop to ~8; owner may read success as failure | expect ~8 / blacklisted ~10; rely on the `Validated …` line and absent `Preset CBBE Curvy …` lines |
| D5 | minor | "nine surviving female shapes" is probably eight (`CBBE Slim (Outfit)` deduped) | correct the count |
| D6 | minor | 3BA has no compiled scripts or MCM page in the load order, so O1 is a no-op | say so; F2 is safe either way |
| D7 | minor | `OVERRIDES.md` says the OBody file is "byte-for-byte" stock; it is re-serialised | reword |

**Nothing in the nine installed files needs changing before the owner plays.** The breast-amplitude
fix is correct, correctly targeted, correctly prioritised and well-formed; the OBody curation is
correct, schema-valid and hits exactly the presets the owner objected to. D1 and D2 are things the
owner must be *told*; D3–D7 are corrections to the report's prose and test plan.

---

# WHAT I READ / RAN

Read-only: all nine installed files and their nine sources; `profiles\Ultra\modlist.txt` + every
file's timestamp in `profiles\Ultra`; `overwrite\` tree; `Stock Game\Data\SKSE\Plugins`; every
`SKSE\Plugins\CBP*` file in `CBBE 3BA (3BBB)` and `CBPC`; `Auto Physics Reset` ini + `meta.ini` +
string-scan of `AutoPhysicsReset.dll`; `OBody Next Generation` full tree,
`OBody_presetDistributionConfig_schema.json`, `OBodyNGMCMScript.psc`, `OBody_ENGLISH.txt`;
all 9 `SliderPresets\*.xml` across 8 provider mods (22 presets, every `SetSlider` parsed);
`The New Gentleman\SKSE`; `3BBB.bsa` string scan; logs `OBody.log`, `CBPC-Collision.log`,
`AutoPhysicsReset.log`, `PapyrusUtilDev.log`, `skse64.log`, `hdtsmp64.log`, `OStim.log`;
`glue\tools\install_mo2.ps1`; full source-vs-installed diff of the glue mod;
3 NIF headers (`femalebody_0/1.nif`, `skeleton_female.nif`).

Scripts written (scratch only, nothing in the game folder):
`C:\Users\Jordan\AppData\Local\Temp\lrg_test\nifchk.py`, `nifstr.py`, `bsascan.py`.
