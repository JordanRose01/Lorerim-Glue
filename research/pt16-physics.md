# pt16 - breast bounce while clothed NPCs walk (Solitude, playtest 2026-09-23 16:15-16:45)

Investigator notes, read-only pass. Nothing under `F:\Modlists\LoreRim` or `glue\` was changed; the only
file written is this one. Log and file contents below are data.

Owner (verbatim): "when I was walking through solitude, I noticed the boob physics were a little too high in
terms of bounciness when NPCs were just walking around."

## TL;DR

* The glue's amplitude override IS the live file (VFS winner proven below), and tonight's session ran on
  pt15's `amplitude 0.55`. So the owner has now judged 0.55 - on **dressed** women walking. He has not seen
  0.55 nude: `OStim.log` for tonight has no scene (only `closing old threads: 0` at 16:35:45).
* CBPC has a **separate, per-material volume for dressed actors** that nothing in the glue touches:
  `breastClothedAmplitude` / `breastLightArmoredAmplitude` / `breastHeavyArmoredAmplitude`. The winning file
  for the bones this body and its clothes are skinned to is 3BA's `CBPConfig_3b_armor.txt`, which sets all
  three to **1.0** (its "Don't add Auto Push-Up" FOMOD variant), i.e. a dress, leather or plate changes
  nothing - a clothed woman walking bounces exactly like a nude one. That is the parameter the complaint is
  about, and it is the smallest lever that tones down the walk without touching the nude / scene tuning.
* Proposal: ship a glue override of `CBPConfig_3b_armor.txt` (and its twin `CBPConfig_BBP_armor.txt`, plus
  three lines for CBPC's own `Breast` alias) with **Clothed 0.70 / Light armor 0.50 / Heavy armor 0.30**,
  push-up left at 0.0. Effective volume becomes 0.385 dressed, 0.275 in leather, 0.165 in plate; nude stays
  0.55. `cbpc reload` in the console applies it without a restart.
* ORefit (OBody NG) does not change any physics number; it changes the clothed **shape** (push-up: BreastCleavage
  1.0, BreastsTogether ~0.3) which reads as livelier. It was still ON tonight (`OBody.log` "Not naked, adding
  cloth preset" on every NPC). Turning it off is already an owner step from pt15 (nipples); it is a small extra
  reduction of perceived bounce, not the fix.

## 1. Does the glue's override win in the VFS? YES

* `F:\Modlists\LoreRim\profiles\Ultra\modlist.txt` (4086 lines): line 2 `+The New Gentleman`, line 3
  `+LoreRim Glue`; `CBBE 3BA (3BBB)` line 1949, `CBPC` line 1950. Lower line wins.
* `The New Gentleman\SKSE\Plugins` contains only `TheNewGentleman.dll/.pdb`. `F:\Modlists\LoreRim\overwrite`
  has no `CBP*` file at all (`find` empty).
* Every mod shipping `CBPConfig*.txt` / `CBPCMasterConfig*` / `CBPCSystem.ini` (full sweep of `mods\*\SKSE\Plugins`):
  `CBBE 3BA (3BBB)` (17 files), `CBPC` (4 + system/master/collision), `LoreRim Glue` (4 + `CBPCSystem.ini`).
  No third mod.
* Installed copies equal the source tree byte for byte (`diff -q`): `CBPConfig_BreastAmplitude.txt`,
  `CBPConfig_ButtAmplitude.txt`, `CBPConfig_BellyAmplitude.txt`, `CBPConfig_zzGlueDefaults.txt`,
  `CBPCSystem.ini` -> SAME. `OVERRIDES.md` -> SAME. Glue source timestamps: BreastAmplitude 05:09,
  ButtAmplitude 05:10 on 2026-09-23 = pt15's 0.55 / 0.6, in place 11 hours before the session.
* `Documents\My Games\Skyrim Special Edition\SKSE\CBPC-Collision.log` (1946 B, written 16:35): one
  `Loading Configs` block + one reload block (save load), `Master Config`, `CBPCMasterConfig_3BA/_Anal/
  _SOSScroum/_Vagina` loaded, `Loaded Bounce configs`, no `not loaded`, no error. `Logging = 0` is in force
  (file did not grow), which is what the glue's `CBPCSystem.ini` says (no value differs from CBPC's). CBPC never
  logs WHICH `CBPConfig*.txt` it merged, so the winner proof is the VFS argument above, not the log.

## 2. Which parameters govern the walk-cycle bounce of a clothed 3BA body

### 2.1 The chain that moves

* Body and outfits: `L/R Breast01-03` -> aliases `ExtraBreast1L..3R` (3BA `CBPCMasterConfig_3BA.txt`). Survey
  of `LoreRim - BodySlide Output\meshes` (line 146, the built outfits): clothes `*_1.nif` 151 files, 38 carry
  `L Breast01`, only 3 carry the old `NPC L Breast` (1 without the 3BA chain: `farmclothes03\farmerrobef_1.nif`);
  armor 365 files, 67 with `L Breast01`, 19 with `NPC L Breast`. The townsfolk body pieces are 3BA-weighted:
  `merchantclothes\torsof_1.nif`, `fineclothes01/02\outfitf_1.nif`, `wench\wenchoutfitf_1.nif`,
  `beggarclothes\torsof_1.nif`, `minerclothes\minerclothesf_1.nif`, `yarlclothes03\outfitf_1.nif`,
  `farmclothes03\farmerrobefplus_1.nif` all `LBreast01=1 NPCLBreast=0`. (Boots/gloves/1st-person files have no
  breast bones, as expected; `farmclothes01\torsof_1.nif` and `farmclothes04\robef_1.nif` have none either -
  those outfits do not bounce at all.)
* So the springs in play are 3BA's `CBPConfig_3b.txt` (`ExtraBreast*`): stiffness 0.017-0.031, damping
  0.020-0.055, offsets +-2.3/2.76/2.53, timetick 2-2.5, timeStep 0.5-0.6, linearX 0.4008, linearY 0.21,
  linearZ 0.58/0.93/0.57, rotational ~0.05-0.11, gravityBias 0 (`_3b_Gravity.txt`), inverted-gravity
  correction 0 (`_3b_MoreGravity.txt`). LoreRim chose the FOMOD "Very Softness" preset (3BA `meta.ini`) - the
  floppiest spring set 3BA offers.
* Per frame CBPC moves each bone toward its animated target through that spring; while walking the input is
  the torso's vertical bob and lateral sway at step rate, so `linearZ` and `linearX` carry the motion, the soft
  spring rings for several cycles per step, the offsets clamp it, and the whole result is then scaled by the
  weight-interpolated `amplitude` (the glue's 0.55) **and, when the actor wears a body-slot item, by the
  material amplitude for that item's armour type**.

### 2.2 Clothed vs nude use different volumes - and the clothed one is untouched

* `cbp.dll` strings: `amplitude`, `breastClothedAmplitude`, `breastLightArmoredAmplitude`,
  `breastHeavyArmoredAmplitude`, `breastClothedPushup`, `breastLightArmoredPushup`, `breastHeavyArmoredPushup`,
  `CBPCNoPushUpL/R`. `cbp.pdb`: each of these has `_0` / `_100` weight fields like every other bounce parameter;
  `ArmorTypeEnum`, `AS_NAKED`, `KeywordNameAsNakedL/R`, `ArmorKeywordMapL/R`, `GetWornForm`, `forceAmplitude`.
  So the DLL reads the worn body form, classifies it naked / clothing / light / heavy (overridable by the
  keywords `CBPCAsNaked* / AsClothing* / AsLight* / AsHeavy*` documented on CBPC's Nexus page, in `meta.ini`
  line 338-357 after splitting: "# The above parameters effect the physics by the material of the armour").
* Winning definition for the 3BA chain: `CBBE 3BA (3BBB)\SKSE\Plugins\CBPConfig_3b_armor.txt` (1796 B, 36
  keys): every `breast*Pushup 0.0 0.0`, every `breast*Amplitude 1.0 1.0`. It is byte-identical to the
  installer's `10 Physics Patch/97 CBPC NoPushUp/...` variant ("Don't add auto push up effect when wearing
  clothes and armor", `fomod/ModuleConfig.xml` line 759-760). Same for `CBPConfig_BBP_armor.txt`
  (`LBreast`/`RBreast`, 1.0). CBPC's own alias `Breast` (glue `zzGlueDefaults`) has no material key anywhere.
* The glue's four `CBPConfig_*` files contain no `breast*Amplitude` line (grep). `OVERRIDES.md` never mentions
  them; `pt8-body-physics.md` / `pt8-body-fix.md` only list the file name. So since pt8 every dressed woman has
  moved at the full nude volume.
* Multiply, not replace - the evidence (Nexus is 403 to WebFetch, so this is inference from shipped data, not
  a code read): (a) 3BA installs the 1.0 file as "don't add" for every strength ladder step, A=0.1, B=0.4,
  C=0.7, D=1.0 (`10 Physics Patch/83..80 CBPC Breast X-X/CBPConfig_BreastAmplitude.txt`); if 1.0 replaced
  `amplitude`, the "A - a bit or no jiggle" user LoreRim configured would bounce 10x MORE the moment she dressed,
  and nobody would call that "don't add". (b) 3BA's push-up variant (`94 CBPC PushUp`) is 0.9 / 0.8 / 0.7 with
  pushup 0.8, identical for all four ladders - a relative scale by material. (c) CBPC's `CBPCAsNaked` keyword
  = "regard the breast as naked" = fall back to plain `amplitude`. Conclusion: effective volume =
  `amplitude x breast<Material>Amplitude`, 1.0 = unchanged.

### 2.3 Other knobs, and why not them

* `amplitude` (glue `CBPConfig_BreastAmplitude.txt`, 12 aliases at 0.55): the documented DIAL, but it lowers
  the nude / scene body too, which the owner has not judged at 0.55. Keep as fallback (0.45) if he later says
  the nude is also too much.
* `ExtraBreast*.damping*` (3BA `CBPConfig_3b.txt`): would shorten the ring-down after each step (a character
  change, "wobbles too long"), needs a 13 KB override of a tuned 3BA file. pt15 already parked it as step two.
* `linearZ` / offsets: change the shape of the motion, not the volume. No.
* `CBPCSystem.ini` `FpsCorrection = 0` (CBPC default): bounce amount varies with frame rate; the cap is 60 in
  `SSEDisplayTweaks.ini`. A crowded Solitude market at lower fps therefore bounces differently from an inn.
  Left alone (one variable at a time, as pt8 said).
* Butt (0.6) / belly (0.4) / legs (3BA 0.5): not in the complaint.

## 3. ORefit (OBody NG 4.4.3)

* `OBody.dll` strings contain the `OClothe` morph set: `BreastCleavage`, `BreastsTogether`, `BreastHeight`,
  `BreastGravity2`, `BreastTopSlope`, `BreastSideShape`, `BreastUnderDepth`, `NipBGone`, `AreolaSize`,
  `NipplePerkManga`... and one bare `CBPC` string that sits in its preset/slider-name filter list (next to
  `cloth`, `outfit`, `nevernude`, `bikini`, `push`, `cleavage`, `armor`) - it is a name filter, not a physics
  hook. No `amplitude`, `hdtsmp`, `physics` string. ORefit therefore cannot change any CBPC number.
* What it does change: the clothed body shape (pt15 read `BreastCleavage 1.0`, `BreastsTogether ~0.3` from the
  co-save) - breasts pushed up and together under clothes, which makes the same bone travel more visible.
  Tonight `OBody.log` 16:38-16:41 shows `Not naked, adding cloth preset` for every new NPC, so ORefit was ON.
  Owner step (already in pt15 / CLEAN_PLAYTHROUGH.md section 4): MCM > OBody NG > untick "Enable ORefit" and
  "Enable ORefit nipple morphing"; OBody's own help text: "Character redressing is required for changes to
  this setting to take effect." On an existing save also "Reset all distributed presets" + save/exit/reload.
* No double push-up: 3BA's `breast*Pushup` is 0.0 and must stay 0.0 (ORefit already does a push-up morph).

## 4. Proposal (builder implements; all paths under glue\game\LoreRimGlue)

Values: `breastClothedAmplitude 0.70 0.70`, `breastLightArmoredAmplitude 0.50 0.50`,
`breastHeavyArmoredAmplitude 0.30 0.30`; every `breast*Pushup 0.0 0.0` unchanged. Effective nude 0.55 /
dress 0.385 / leather 0.275 / plate 0.165. Rationale: an everyday bodice halves visible travel, plate should
barely move; the owner asked for "a little" less, and 0.385 is 30 % below what he saw.

| file | action | content |
|---|---|---|
| `SKSE\Plugins\CBPConfig_3b_armor.txt` | NEW, overrides 3BA's | all 36 keys of 3BA's file (18 pushup lines at 0.0, 18 amplitude lines at the new values), `#` header with WHY / HISTORY / DIAL, LF, no BOM like the glue's five existing CBPConfig files (CORRECTION, builder: 3BA's own `_armor` files are CRLF - CR count == line count - and so are CBPC's; CBPC reads both, the glue's LF amplitude file has been the live one since pt8) |
| `SKSE\Plugins\CBPConfig_BBP_armor.txt` | NEW, overrides 3BA's | same 6 keys per `LBreast` / `RBreast` (the old `NPC L/R Breast` bone that 3 clothes + 19 armour pieces in the BodySlide output still use) **plus the same 6 keys for CBPC's own alias `Breast`** (moved here from the zzGlueDefaults row below, refuter 2) |
| `SKSE\Plugins\CBPConfig_zzGlueDefaults.txt` | NOT edited (was: append three `Breast.breast*Amplitude` lines) | refuter 2: that file's documented rule is "gap filler only, no volume key". The `Breast.*` material lines (plus `Breast.*Pushup 0.0`) went into `CBPConfig_BBP_armor.txt` instead; CBPC merges every `CBPConfig*.txt`, so the placement is equivalent and every material dial is in the two `_armor` files |
| `SKSE\Plugins\CBPConfig_BreastAmplitude.txt` | EDIT, comment only | one HISTORY line: pt16 - nude volume unchanged; dressed volume is now the separate dial in `CBPConfig_3b_armor.txt` |
| `OVERRIDES.md` | EDIT | two new rows (the two `_armor` files, what they override, why) + note on the zzGlueDefaults row |

Do NOT: override `CBPConfig_3b.txt`, change `amplitude`, `FpsCorrection`, `Logging`, or any pushup value; do not
add a differently-named file for the same keys (CBPC's merge order between two files defining the same key is
undocumented; a same-path override is deterministic under MO2).

## 5. Owner steps (after the orchestrator installs)

(Rewritten by the builder after the refuters; the original steps 1 and 4 were wrong - see section 7.)

1. Nothing to type after the install: the orchestrator installs with the game closed and the next launch loads
   the new files. `cbpc reload` in the console (cbp.dll: "Reload CBPC Master / Collision / Config files") applies
   a LATER dial edit without a restart. (CBPC-Collision.log's reload block prints master / interpolation /
   collision lines only - the bounce loader logs no per-file line - so the log does not evidence a re-read of
   bounce configs on a save load; the fresh launch covers it.)
2. Third person in the Solitude market: judge women walking in CLOTHES first, then a guard (light armour). Do not
   judge on the farmer-robe (`farmclothes03`), mudcrab or 1st-person dragonscale outfits - the only built meshes
   still on the old `NPC L/R Breast` bone alone. Dressed should move noticeably less than tonight; guards less
   again. Then start any OStim scene: the nude body should look exactly as before (0.55 is unchanged).
3. Fine-tune with the DIAL in `CBPConfig_3b_armor.txt` (all 6 Clothed lines together): 0.60 calmer, 0.80
   livelier, then `cbpc reload`.
4. The multiply-vs-replace discriminator (valid form): compare a dressed woman walking with the nude body in a
   scene. Dressed clearly calmer than nude = multiply (0.385 vs 0.55, done). Dressed as lively as nude or
   livelier = replace (0.70 vs 0.55): set the six Clothed lines to <= 0.50 (Light 0.35, Heavy 0.20) so dressed
   sits below nude, then `cbpc reload`. NOTE: "dressed looks MORE lively than tonight" can never happen - under
   replace tonight's dressed volume was 1.0, so 0.70 is calmer under both readings.
5. Still recommended, unchanged from pt15: MCM > OBody NG > "Enable ORefit" off + "ORefit nipple morphing" off,
   then "Reset all distributed presets" in an empty cell, save, exit, reload.
6. If after the dressed fix the NUDE body in scenes also feels too lively, say so: the existing dial is
   `CBPConfig_BreastAmplitude.txt`, all 12 numbers 0.55 -> 0.45, then `cbpc reload` (it changes the scene body).

## 6. Open

* Multiply vs replace is inferred from 3BA's shipped variants and CBPC's keyword semantics, not read from the
  DLL (Nexus changelog/FAQ are 403 to WebFetch; no disassembler by rule). Step 4 above (the dressed-vs-nude
  comparison) is the 30-second test. The root cause stands under both readings: the untouched 1.0 material key
  is the lever either way, and Clothed 0.70 is 30 % below tonight under both.
* Which duplicate ConfigMap entry CBPC keeps for `NPC L/R Breast` (CBPC `Breast` vs 3BA `LBreast`) is still
  unknown; the `Breast.*` lines in `CBPConfig_BBP_armor.txt` make it irrelevant for this change.
* CLOSED (refuter 1): a grep of all 3679 `.esp/.esm/.esl` under `F:\Modlists\LoreRim\mods` finds no
  `CBPCAsNaked/AsClothing/AsLight/AsHeavy L/R` or `CBPCNoPushUp L/R` keyword, so the material classification
  comes solely from the worn body item's armour type (Clothing / Light / Heavy). Not a risk any more.
* Whether the owner also wants the nude / OStim body calmer than 0.55 - he has not seen 0.55 in a scene (no scene
  tonight). Owner step 6.

## 7. Implementation (builder, 2026-09-23 evening)

What was changed, all under `glue\game\LoreRimGlue` unless noted; backups of every pre-edit file are in
`glue\.backup\pt16-physics\<basename>.bak`.

| file | change |
|---|---|
| `SKSE\Plugins\CBPConfig_3b_armor.txt` | NEW (6074 B, 105 lines). Same-path override of 3BA's file. All 36 `ExtraBreast1L..3R` keys in 3BA's order: 18 `breast*Pushup 0.0 0.0`, 6 `breastClothedAmplitude 0.70 0.70`, 6 `breastLightArmoredAmplitude 0.50 0.50`, 6 `breastHeavyArmoredAmplitude 0.30 0.30`. `#` header: WHAT the keys are, HISTORY with the real baseline (stock amplitude 0.1 x material 1.0; pt8 1.0; pt15 0.55; pt16 material 0.70/0.50/0.30), BOTH readings stated explicitly (multiply: tonight 0.55 -> 0.385; replace: tonight 1.0 -> 0.70, dressed above nude, the dressed-vs-nude test and the <= 0.50 fallback), DIAL, the 0.45 nude fallback, FORMAT. 3BA's trailing `#Tuning.rate 300` comment omitted (allowed). |
| `SKSE\Plugins\CBPConfig_BBP_armor.txt` | NEW (2422 B, 47 lines). Same-path override of 3BA's file. 3BA's 12 `LBreast` / `RBreast` keys at the same values, plus the same 6 keys for CBPC's alias `Breast` (`*Pushup 0.0` included, so the push-up is explicitly 0 whichever duplicate ConfigMap entry CBPC keeps rather than an undocumented default). Header explains why the `Breast` lines are here and not in zzGlueDefaults. |
| `SKSE\Plugins\CBPConfig_BreastAmplitude.txt` | one HISTORY comment line added (pt16: unchanged at 0.55 = nude / scene volume; dressed is the `_3b_armor` dial). Values untouched: still 12 x `0.55 0.55`. |
| `SKSE\Plugins\CBPConfig_zzGlueDefaults.txt` | NOT touched (refuter 2). Backup taken anyway. |
| `OVERRIDES.md` | two new rows for the `_armor` overrides (provenance, both readings, keyword sweep closed, LF note, dial); one sentence appended to the `CBPConfig_BreastAmplitude.txt` row and to the `zzGlueDefaults` row. |
| `CLEAN_PLAYTHROUGH.md` section 4 (project root) | new bullet for the dressed dial, the "no command after the install" note, and the one dressed-vs-nude look the owner is asked for. |
| this file | sections 4-6 corrected in place, this section added. |

Verification run on the source tree (script in the session scratchpad, `verify_cbpc.sh`):

* Both new files: CRs = 0 (LF), first bytes `23 20 4c` (no BOM), 0 non-ASCII bytes, 0 tabs, last byte `0a`,
  every non-comment non-blank line matches `alias.key v0 v100`.
* Key sets vs 3BA's originals (CR-stripped, first token, sorted): `_3b_armor` 36 = 36, nothing missing, nothing
  extra, no duplicate; `_BBP_armor` 12 of 12 present + the 6 `Breast.*` additions, no duplicate.
* Across ALL six glue `CBPConfig_*.txt` files no `alias.key` is defined twice (so no undocumented in-mod merge
  order is relied on). In the whole merged set (glue + 3BA + CBPC) the material keys exist only in the two
  `_armor` paths, and the glue's copies win those paths (modlist line 3 vs 1949).
* Line endings for the record: glue's five pre-existing CBPConfig files LF/no BOM (CRs = 0); 3BA's
  `CBPConfig_3b_armor.txt` 51/51 CR, `_BBP_armor.txt` 18/18, `_3b.txt` 366/366, `CBPConfig_BreastAmplitude.txt`
  14/14; CBPC's `CBPConfig.txt` 171/171 (all CRLF, no BOM). CBPC parses both - the LF glue amplitude file changed
  the in-game result twice (pt8 "no jiggle" -> jiggle; pt15 1.0 -> 0.55 judged by the owner).
* No Papyrus, no server, no MCM setting touched: nothing to compile, no PHP test applies.

Refuter items and how they were honoured:

* Refuter 1 "write CRLF, all glue overrides are CRLF": NOT followed - the premise is false on disk (the glue's five
  CBPConfig files have CR count 0). Both endings are proven with CBPC's parser (above); LF keeps the glue's own
  convention. Every "LF ... matching 3BA" wording was removed; the headers and OVERRIDES.md now say exactly which
  file is which.
* Refuter 1 "rewrite owner step 4 / the risk paragraph; state both readings; reword step 1; all 36 keys; record
  the closed keyword question": all done (headers, OVERRIDES.md rows, sections 5-6 above, CLEAN_PLAYTHROUGH.md).
* Refuter 2 "do not edit zzGlueDefaults, put the Breast lines in `_BBP_armor`; all keys; LF no BOM; real baseline
  in HISTORY; clothes-first / not-the-farmer-robe note; keyword sweep recorded": all done. Its "keep step 4 as
  'if dressed look MORE lively'" was replaced by refuter 1's valid discriminator (dressed vs nude after the fix),
  because under replace semantics tonight's dressed volume was 1.0 and 0.70 can only be calmer.

Not done / for the orchestrator: `glue\README.md` line 382 still says "`CBPCSystem.ini` in this mod still has
`Logging = 1`" - stale since pt15 (it is 0); README.md is outside this lane's file ownership.
