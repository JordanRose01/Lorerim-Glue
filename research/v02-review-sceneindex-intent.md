# v0.2 adversarial review - SCENEINDEX builder, lens: owner intent, rails, prompt quality

Reviewer is READ-ONLY for project files; this report is the only file written.
Everything below was reproduced by running the code, not by reading it. Staging:
`%TEMP%\lrg_test`, PHP 8.2.28 in `DwemerAI4Skyrim3`, real installed packs through `/mnt/f`.

## Verdict: SHIP AFTER FIXES

The engineering is sound and the measurements hold up: the index really is off the request path
(13.6 ms first call on the 9p staging path with `LRG_INDEX_NO_BUILD`, no build), the filter really
does switch without a rebuild, the furniture model is grounded in OStim's own `furniture types
README.txt` ("none" = no-furniture animations as supertype, so the FF/MM floor start on a bed is
legal), `breast`->female is grounded in `OStimFemale.json`, and both suites pass (199 / 196-0).
But the round's headline change - `content_filter: open` as the new default - makes the code hard
list the ONLY consent filter, and that list is (a) baked into a cache whose signature does not
cover it, so a rail fix does not survive a deploy, and (b) missing the vocabulary that non-consent
packs actually use, so the "fails closed" claim is not true. Alongside that, three owner
requirements are claimed but not delivered in the case the owner will hit first: naming the
position you are already in moves you somewhere else (R1), the gentle end of the ladder offers
mostly silent "nothing happens" idles (R6/R10), and the labels the NPC must speak from carry
`[MF]` / `(left)` / `holdingbody` tokens (R4/R10). All ten fixes are small and local; none needs a
redesign.

---

## Defects

### D1 HIGH - the hard rail is baked into a cache whose signature does not cover it; `deploy_server.ps1` will not apply a rail change
`lrg_scene_index.php:242-255`. `lrgIndexSignature()` = modlist.txt mtime + watched dirs + 10 named
`scene_index` config keys + `LRG_INDEX_VERSION`. It never references `LRG_HARD_WORDS`,
`LRG_HARD_GLOBS`, `LRG_HARD_INCAPACITATED`, `LRG_COMMON_REQUIREMENTS` or the tier code - yet all of
those are evaluated at BUILD time and frozen into `$s['hard']`, `$s['taste']`, `$s['need']`,
`$s['special']`, `$s['tier']` (lines 399-402).

Proven, not argued. I added one probe word to `LRG_HARD_WORDS` on the staged copy and ran exactly
what `deploy_server.ps1:43` runs:

```
probe word in LRG_HARD_WORDS: yes
lrgIndexWarm(false): {"ok":true,"scenes":607,"seconds":0.04,"skipped":true}
scenes flagged hard in the SERVED index: 0
scenes still offered that the new rail should drop: 46
lrgIndexWarm(true):  {"ok":true,"scenes":607,"seconds":5.851,"skipped":false}   -> hard=47
```

So: change the rail, deploy, and the server keeps serving the pre-fix index for ever (no 24 h
expiry any more, and the signature is unchanged). Under `open` this list is the only consent and
adults-only filter in the index, which makes "a rail fix that silently does nothing" the worst
available failure mode. Same staleness applies to a tier fix and to `LRG_COMMON_REQUIREMENTS`.

**Fix (smallest):** in `lrgIndexSignature()` add the code side to the digest -
`md5(json_encode([LRG_HARD_WORDS, LRG_HARD_GLOBS, LRG_HARD_INCAPACITATED, LRG_COMMON_REQUIREMENTS]))` -
and change `deploy_server.ps1:43/45` to `warm --force` (a deploy is not a hot path; 6 s once).

### D2 HIGH - `open` is the only net now, and the net misses the non-consent vocabulary; "fails closed" is not true
`lrg_scene_index.php:41-45, 522-532`. Probe over `lrgSceneHardExcluded()` with synthetic future-pack
scenes (sexual tier, 2 actors):

| synthetic scene | hard? | taste (standard)? |
|---|---|---|
| `PackX_ForcedTakeMF` | forced | - |
| `PackX_TeenLoversMF` | teen | - |
| `PackX_DrunkPassedOutMF` | passedout | - |
| `PackX_StruggleSnatchMF` (tags struggle, resisting) | **NO** | no |
| `PackX_CaptiveMF` (tags captive, prisoner) | **NO** | no |
| `PackX_DubconMF` (tag dubcon) | **NO** | no |
| `PackX_LolitaMF` / `PackX_SchoolgirlMF` | **NO** | no |
| `PackX_BondageMF` / `PackX_ChokeMF` / `PackX_RoughGrabMF` | **NO** | yes |

Two separate problems:
1. `dubcon`, `struggle`/`resisting`, `captive`/`prisoner`, `blackmail`, `defeated`, `lolita`,
   `schoolgirl` pass at BOTH levels. The report says the hard list "fails closed: a future pack
   scene called '...Young...' is dropped" - it fails closed only for the words that happen to be in
   the list, and the list omits the terms SE non-consent packs normally use.
2. `bondage`, `bdsm`, `choking`, `*smothering*`, `*bound*`, `*whip*`, `fisting` were ALWAYS excluded
   in v1 (they sit in `excluded_tags` / `excluded_actions` / `excluded_id_patterns`). This round
   demotes them to taste-only and flips the default to `open`, so 0.2 ships with them allowed - and
   the report's "what standard removes and open gives back" list (vampire feeding, foot, spanking,
   rough oral, face sitting, hair pulling, tail) does not mention them. Nothing installed matches
   today, so the owner cannot see this in game; it appears the day he adds a pack.

Third, smaller: `LRG_HARD_INCAPACITATED` is matched only against `actor_tags` + `actions`
(line 528), not scene `tags`. Verified: a sexual scene with the scene tag `sleeping` / `asleep` /
`drowsy` and no actor tag returns `hard=''`; `unconscious` survives only because it is separately in
`LRG_HARD_WORDS`. (OStim's own `list of commonly used actor tags.txt` does list `sleeping` and
`drowsy` as actor tags, so the current surface matches convention - but a one-word widening costs
nothing and removes the hole.)

**Fix (smallest):** add to `LRG_HARD_WORDS`: `dubcon, dubious, coerced, coercion, struggle,
struggling, resist, resisting, reluctant, captive, prisoner, blackmail, defeated, helpless, lolita,
schoolgirl`; add to `LRG_HARD_GLOBS`: `*dubcon*, *struggl*, *resist*, *captive*, *defeat*,
*blackmail*`; change line 528 to `array_merge($s['tags'], $s['actor_tags'], $s['actions'])`. Decide
explicitly on `choking / smothering / bound / bondage / whip / bdsm`: either keep them hard, or add
one line to the owner-facing notes saying `open` allows them. Requires D1's fix to take effect.

### D3 MEDIUM - R1 not delivered: naming the position you are already in moves the NPC to a different one
`lrg_scene_index.php:1054` - `if ($k === $curK) { continue; }` removes the current scene from the
candidate pool, so a request the current scene answers perfectly is answered by its nearest
neighbour instead. Measured (MF, no furniture, ceiling sexual):

| in scene | player / NPC says | result | current scene's coverage |
|---|---|---|---|
| `OARE_Missionary` | missionary | `OARE_MissionaryHandholding` | 1 of 1 groups |
| `OARE_SittingFellatio` | blowjob | `OARE_SittingFellatioLyingFemale` | 1 of 1 |
| `OARE_StandingKiss` | kiss me | `OARE_SittingFemaleApproachKiss` (she sits down) | 1 of 1 |
| `OARE_Doggystyle` | doggy style | `OARE_AceBackItUp` | 1 of 1 |

`lrgResolveControl` (lrg_actions.php:669-675) turns that straight into `do=goto`, so "stay in
missionary" / "keep doing that" / the NPC re-naming what she is already doing all cause an
unrequested animation change. It also breaks R10 on an announce turn: she names the act she chose
and then moves to a different scene. Owner, verbatim: "If I say a position it should go to that
position."

**Fix (smallest):** score the current scene as a candidate in pass 1/2 and, when its coverage is
`>=` the best other candidate's, return `null` (nothing changes, she just answers). Checked that
this keeps the good cases: "cowgirl" from `OARE_Missionary` covers 0 of 1 groups on the current
scene and still resolves to `OARE_CowgirlSquatting`.

### D4 MEDIUM - R6/R10 not delivered at the gentle end: most offered options are silent "nothing happens" idles
`lrg_scene_index.php:716` (neutral costs +1 hop of rank) and `:723` (the ceiling-tier promotion only
fires when `$maxTier > $curTier`). From the bed start this round introduced:

```
OStimDoubleBedLeft2PBothSittingKissMF  ceiling=2 (kissing):  5 of 8 options are tier-neutral routing idles
                                       ceiling=3:            1 of 8
                                       ceiling=4:            1 of 8
OARE_SpooningCuddleKiss                ceiling=2:            2 of 8
```

At the kissing ceiling P2 is `Sitting On Bed (left) [neutral]` at 1 hop, ranked above the only
kissing alternative at 3 hops. R10 marks every neutral option `[silent]`, and the lead-tick prompt
tells her to "choose ONE change". So while the pace timer is still running, the most likely NPC lead
is a wordless move from "sitting and kissing" to "sitting" - a step backwards, in silence. That is
exactly the failure the P-list reorder was meant to cure, and it is worst on the new bed start.

**Fix (smallest):** in `lrgSceneOptions`, drop tier-`neutral` scenes from `$found` while at least
`$max` non-neutral options remain (they are still routed THROUGH by `lrgSceneWalk`, which is where
they are actually needed), or raise their rank penalty from +1 to +3.

### D5 MEDIUM - R4/R10 prompt quality: labels carry internal pack tokens into the line the NPC must speak
`lrgDescribeScene` (`:629-645`) passes the pack display name through unchanged. Counted over the
usable index: **201** labels carry an `[MF]`/`[FF]`/`[MM]` marker, **41** a `(left)`/`(right)`
marker, **59** a bookkeeping `holding*` action. Rendered P1 on a double bed:

```
Sitting On Bed Cowgirl (left) [MF] (sitting/kneeling; on doublebed; vaginalsex, gropingbutt, holdingbody) [sexual]
```

114 chars. The eight options as `lrgSceneNotes` renders them (`+ [say it] [next step]`) are **834
chars** of every scene turn's prompt. On a `[say it]` turn the notes then ask for "ONE short
sentence that names ... the act or position just chosen" - with `left`, `MF` and `holdingbody` as
the nearest available words. That is a direct route to an NPC line containing internal tokens, and
it fights R4's "plain everyday words".

**Fix (smallest):** in `lrgDescribeScene`, strip `/\s*\[(MF|FM|FF|MM)\]/i` and
`/\s*\((left|right)\)/i` from `$name`, and filter `default|holdingbody|holdinghand|holdinghead|
holdinghip|holdingarm|holdingleg` out of `$acts` (the same neutral list `lrgFurnitureOptions`
already keeps at `:832`). Measured effect: the eight labels go from 610 to 472 chars and stay
readable (`Sitting On Bed Cowgirl (sitting/kneeling; on doublebed; vaginalsex, gropingbutt)
[sexual]`). Consider dropping the trailing `[tier]` too - the notes already mark `[say it]` /
`[silent]` / `[next step]`. Re-run `test_gates.php` and check `lrg_actions.php:663`, which matches
the label by prefix.

### D6 MEDIUM - R1/R2 for beast races: the default start scene is one OStim refuses for Khajiit and Argonians
Grounded in the installed sources: `actor properties/OStimBeastRace.json` sets `"mouth": false`;
`actions/kissing.json` requires `mouth` on BOTH `actor` and `target`. `lrgPickStartScene` scores
`kissing` +40 (`:758`), so:

```
lrgPickStartScene(no furniture) = OARE_StandingEmbraceKiss              [kissing] needs mouth: YES
lrgPickStartScene(double bed)   = OStimDoubleBedLeft2PBothSittingKissMF [kissing] needs mouth: YES
lrgPickStartScene(chair)        = OStimChair2PSittingOnLapMF            [affection] needs mouth: no
usable MF 2-actor scenes: 416, of which need mouth: 101
options from the standing start: 3 of 8 (ceiling 2), 5 of 8 (ceilings 3 and 4) need mouth
mouth-free standing gentle starts available: 27 (OARE_GentleEmbrace, OARE_StandingHandHolding, ...)
```

So a Khajiit or Argonian player - or NPC - gets a BeginIntimacy whose starting animation OStim will
not play. The report lists beast races under "not done" and says "the live list protects hop-1
options, not the start scene or a warp", which is honest, but the start scene is the single most
likely first contact and it is left unsafe when a safe alternative exists in the same tier band.

**Fix (smallest, PROTOCOL 7.1-legal - new parameter at the end with a default):**
`lrgPickStartScene(?array $sexes, ?string $furniture = null, bool $noMouth = false)` and the same
flag through `lrgPickStart()`, skipping scenes whose actions require `mouth`. It cannot be switched
on without one wire key - see `forIntegrator` below.

### D7 LOW - dead guard, and a new function nobody calls
- `lrgFurnitureOptions:847` rejects scenes with the `climaxing` ACTOR TAG. Counted: **0** installed
  scenes carry it, while 6 are climax scenes by id (`OARE_AceLyingBoobjob_MaleClimax`,
  `OStim2PMissionaryClimaxMF`, `OARE_AceStandingBoobjob_AfterClimax`, ...). `lrgFindSceneByText:1093`
  gets this right by testing the id. Fix: use the same id test in `lrgFurnitureOptions`.
- `lrgPickAfterglow()` (new, returns `hops` / `routed`) has no caller: `lrg_actions.php:627-629`
  calls the old `lrgPickAfterglowScene()` and then recomputes the same `lrgSceneWalk` to decide
  `warp`. Either hand BEHAVIOUR the `routed` flag it already computed, or drop the second function.
- The report's "Climax scenes are not targets" is an overstatement: they are candidates with a -3.0
  penalty. Harmless, but the claim should match the code.

### D8 LOW - `lrgIndexWarm` builds without a lock when the lock file cannot be opened
`:161-165`. If `@fopen("$dir/.index_lock", 'c')` fails (a stale root-owned lock file, a data/ created
at runtime by `@mkdir(..., 0770)` which umask 022 turns into 0750 for the other user), `$lock` is
`false`, the guard is skipped and the build proceeds unsynchronised; `lrgIndexBuilding()` also then
reports "not building", so `lrgIndexMaybeRebuildAsync` will happily spawn a second 6 s build.
**Fix:** when `@fopen` fails, log it and return `['ok'=>false,'scenes'=>0,'seconds'=>0,'skipped'=>true]`
rather than building blind.

### D9 LOW - PHP 8.2 warning in `warm_index.php:11`
`$args[0][0]` on an empty-string argument (`php tools/warm_index.php ""`) emits
"Uninitialized string offset 0". **Fix:** `if (!$args || ($args[0][0] ?? '-') === '-')`.

### D10 INFO - the "verified for real" detached-rebuild claim was measured in the wrong SAPI
`lrgIndexMaybeRebuildAsync` is only ever reached from `preprocessing.php`, i.e. under the web SAPI,
where the child inherits the request's process group; `nohup` covers SIGHUP only. The report's proof
(trigger 51 ms, index 5.8 s later) was produced from the CLI with `--spawn`. The failure mode is
benign (the index stays stale and is retried 60 s later), but the claim is not evidence for the path
that actually runs. Worth one line in the notes, or a `posix_setsid`-style detach if it ever bites.

---

## What I checked and found CORRECT (so the integrator does not re-do it)

- `php -l` clean on all four files; `test_scene_index.php --quiet` **199 checks ALL PASSED**;
  BEHAVIOUR's `test_gates.php` **196 passed / 0 failed** against this library.
- **R7, non-blocking index:** with `LRG_INDEX_NO_BUILD` (the web-request path) `lrgSceneIndex()`
  served 607 scenes in 13.6 ms on the 9p staging path and did not build. `lrgIndexWarm(false)` right
  after a build = 40 ms `skipped`. The 24 h rule is gone.
- **Filter switching at query time** works exactly as claimed, and fails to the strict side on
  garbage: `open`->0 excluded, `standard`->38, `grounded`->38, `nonsense`->**38** (strict),
  empty/missing->`open` (the owner's default).
- **Empty-index degradation is genuinely quiet** - no PHP notice or warning from
  `lrgPickStartScene / lrgPickStart / lrgSceneOptions / lrgFindSceneByText / lrgPositionWords /
  lrgFurnitureOptions / lrgPickAfterglow / lrgFurnitureChain` (which correctly falls back to
  `LRG_FURN_SUPER`).
- **Furniture model is grounded**, not guessed: OStim's `furniture types README.txt` states
  `"supertype": "none"` means "no furniture (so default animations) as supertype", and `bed.json`
  has `"supertype": "none"`, `doublebed/bedroll` have `"supertype": "bed"`. So the FF/MM/bedroll
  start on a floor scene (`OARE_SpooningCuddleKiss`) with `SetFurniture(bed)` is legal by OStim's own
  rule; the residual risk is alignment, not rejection, and the report says so.
- **Sex inference is grounded:** `OStimFemale.json` is the only source of `breast` and `vagina`
  (so breast->female is right); `OStimNPC.json` gives `penis` to everyone (so the conservative
  penis->male divergence is deliberate and correctly documented); `OStimVampire/Tail/InWater.json`
  justify the `special` / live-only set; `OStimBeastRace.json` is what D6 rests on.
- **Niche scenes are not a side effect:** "all fours" -> `OARE_Doggystyle`, "something else" ->
  a kissing scene, "bite me" -> null, "spank me" -> null under `open`. Only naming the niche
  ("feet") reaches one. As claimed.
- **No explicit example dialogue** in `lrg_scene_index.php`, `test_scene_index.php` or
  `v02-sceneindex.md`. The `synonyms` keys ("hold me", "take me", "between your tits") are
  recognition vocabulary for `item` / player speech, never emitted; `position_words` - the only
  config list that reaches the prompt verbatim - is plain category words ("missionary", "oral",
  "grope", "kiss"), not lines. I looked for a rail breach here and there is none.
- `lrgPickStart` honours `prefer_furniture: bed` correctly (chair/cookingpot in reach -> `furn` empty,
  standing fallback always returned); every start scene is tier `kissing` or `affection` (R6).
- `lrgFurnitureOptions` never offers the thread's own furniture family (on a double bed: chair,
  table, cooking pot only) and never steps the tier up.
- Wind-down from the sexual hubs routes to `OARE_SpooningIdle [affection]` in 3 hops with and
  without a bed, as reported.
- `$wantsClimax` is not the misfire I expected: "come here" / "come closer" / "come on" / "cum" all
  return null, and no installed scene carries the `climaxing` actor tag.
- Signature cost, config-key baking and query-time reads are split correctly (`start_tiers`,
  `intended_sex_only`, `afterglow_*`, `furniture_*`, `synonyms`, `position_words` are all query-time
  and correctly NOT baked; `live_only_id_patterns` and `sensual_actor_tags_strict` ARE baked and
  correctly are). Every new config key has an in-code default.
- PROTOCOL 7.1 signatures all match, including return shapes
  (`['P1'=>['id','label','tier','hops']]`, `['scene','furn','fscene']`,
  `[type=>['furn','label','words','scene','tier']]`, `['built','scenes','stale','building','error']`,
  `['ok','scenes','seconds','skipped']`). `LRG_INDEX_VERSION 3` matches PROTOCOL 0.
- `deploy_server.ps1`'s warm-up ordering is right: `data/` is created and chowned before the
  `runuser -u www-data` build, and re-chowned + `ug+rwX` after, so the web user can replace the cache
  later. (Only the missing `--force` is wrong - D1.)

---

## For the integrator (cross-file, exact)

1. **D6 needs one wire key.** The index cannot know a beast race: PROTOCOL 1.1 carries no race
   information and OStim exposes actor properties to Papyrus only through the perk conditions.
   Add one snapshot key before `class`, e.g. `beast` `0/1` = either participant is Khajiit or
   Argonian (including the vampire variants), and let BEHAVIOUR pass it to
   `lrgPickStartScene(..., $noMouth)` / `lrgPickStart(..., $noMouth)`. Without it D6's fix cannot be
   switched on and a Khajiit/Argonian playthrough has a broken BeginIntimacy.
2. **D1's second half is a one-line edit in a SCENEINDEX-owned file** (`deploy_server.ps1:43` and
   `:45`, `warm` -> `warm --force`). Do it in the same change as the signature fix, or the signature
   fix ships without effect on the first deploy.
3. **D5 changes label text.** `lrg_actions.php:663` matches an offered option by
   `stripos($o['label'], $t) === 0`, and `test_gates.php` asserts on option labels. BEHAVIOUR should
   re-run `test_gates.php` after SCENEINDEX lands the label cleanup.
4. **D7 second bullet is BEHAVIOUR's call:** `lrg_actions.php:627-629` recomputes a full
   `lrgSceneWalk` only to decide `warp=1`, which `lrgPickAfterglow()` already returns as `routed`.
   One call saved per wind-down; either adopt it or ask SCENEINDEX to delete the function.
5. **FLOWTESTS:** `tools/flows/adapter.php:74` gates on `index_v3` = `lrgIndexWarm, lrgIndexStatus,
   lrgPickStart, lrgFurnitureOptions`. If D3's "already there" rule lands, the R9 flow
   "position by name" should gain a case: naming the CURRENT position must produce no wire line.
6. **Owner-facing note (D2):** the release notes tell the owner `open` gives back vampire feeding,
   foot play, spanking, rough oral, face sitting, hair pulling and tail play. It also gives back
   bondage / bdsm / choking / smothering / whipping / fisting / restraint, which v1 always removed.
   Nothing installed matches today; say it anyway, because it changes what a future pack install
   does silently.

## Not done in this review
- Nothing in game (no game session in this role); every in-game risk the builder listed is still
  open, and D6 adds one more (beast-race start scene).
- I did not re-derive the 607/416/121/134 scene counts independently; I re-ran their test, which
  asserts them, and spot-checked the filter totals (0 / 38 / 38 / 38 / 0) myself.
- I did not test the detached spawn under php-fpm (D10) - that needs a live HerikaServer request.
- `lrg_config.default.json` was checked for section ownership by reading, not by diffing against a
  pre-change copy; I could not prove that no key outside `scene_index` / `scene_start` /
  `scene_progression` was touched.

## Tests run
`php -l` on `lrg_scene_index.php`, `warm_index.php`, `test_scene_index.php` (clean) ·
`test_scene_index.php --quiet` (199 checks, ALL PASSED) · `test_gates.php` (196 passed / 0 failed) ·
four throwaway probes of my own against the real installed packs (current-position requests,
`$wantsClimax` words, synthetic hard-list scenes, niche leakage, `lrgPickStart` across 6 furniture
sets x 3 pairings, P-list composition at 3 ceilings x 3 start scenes, `lrgPositionWords` at 3
ceilings x 3 pairings, label-noise counts over the whole index, `lrgFurnitureOptions`,
`lrgPickAfterglow`, empty-index degradation, filter switching across 5 config values, the
mouth-requirement census, and the patched-hard-list rebuild demonstration). All probe files live
under `%TEMP%\lrg_test\tools\rev_probe*.php`; the stage was re-mirrored from the project afterwards.
No project file was modified.
