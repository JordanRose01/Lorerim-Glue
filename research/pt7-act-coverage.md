# pt7 - act and phrase coverage audit (v0.3 -> v0.3.1)

Read-only audit. Nothing in the project was modified except this file.
Harness + the reusable phrase matrix live in `%TEMP%\lrg_test`
(= `/mnt/c/Users/Jordan/AppData/Local/Temp/lrg_test`).

## 0. Method and what was actually run

- Installed packs read from `F:\Modlists\LoreRim\mods\*\SKSE\Plugins\OStim\{scenes,actions}`.
- The recogniser was run against the **real v0.3 code** (project `glue\server\lorerim_glue\lib\*.php`,
  staged to `%TEMP%\lrg_test\plugin`) with the **real built scene index** copied from the deployed
  plugin (`/var/www/html/HerikaServer/ext/lorerim_glue/data/scene_index.json`, built 2026-09-21 17:06,
  607 scenes, filter=`open`). PHP 8.2.28 in WSL distro `DwemerAI4Skyrim3`.
- The deployed lib and the project lib are **byte-identical** (md5 of all four `lib/*.php`,
  `config/lrg_config.default.json`, `globals.php`), so this audit tests exactly what playtest 7 ran.
- The runner replays `lrgPrepareTurn()` steps 1-5 (`lrg_actions.php:660-700`) verbatim, including the
  provisional ceiling-4 context, `req_ceiling`, `lrgActOptions()` and the step-5 `lrgIntentResolve()`.
- The only live config override on the server is `npc_overrides.Lisette.min_affinity = -5`; it does not
  affect act resolution.

Pack scene counts: OStim Standalone 280, OARE 287, OCR 40. After the index filters: **416 scenes are
usable for a male player + female NPC pair** (2 actors, not excluded, not a transition).

---

## 1. WHAT IS ACTUALLY INSTALLED

### 1.1 Act family -> installed OStim action names

`lrgActsTable()` globs (`lrg_scene_index.php:798-846`) matched against the 112 canonical action names
the index found:

| family | globs | installed actions matched |
|---|---|---|
| kiss | `kissing*`,`kiss` | kissing, kissingcheek, kissingfoot, kissinghand, kissingneck |
| hold | `hugging*`,`embrac*`,`cuddl*`,`holdingbody`,`spooning`,`lappillow` | cuddling, holdingbody, hugging, spooning |
| grope | `groping*` | gropingbreast, gropingbutt, gropingtesticles |
| nipples | `suckingnipple*`,`nipplestimulation`,`nipple*` | suckingnipple |
| handjob | `handjob*` | handjob |
| fingering | `*fingering`,`fingering`,`vulvalrubbing` | 3pp_vaginalfingering, analfingering, oralfingering, vaginalfingering, vulvalrubbing |
| oralpenis | `blowjob*`,`fellatio*`,`lickingpenis`,`suckingpenis`,`penilelicking` | blowjob, penilelicking |
| oralvulva | `cunnilingus*`,`vulvaleating`,`vulvallicking`,`lickingvulva` | vulvaleating, vulvallicking |
| **sixtynine** | `sixtynine*` | **NONE** |
| titfuck | `boobjob*`,`titfuck*`,`titjob*` | boobjob |
| thighjob | `thighjob*` | thighjob |
| grinding | `grinding*`,`buttjob*`,`rubbing*` | buttjob, grindingfoot, grindingobject, grindingpenis, grindingthigh, rubbingpenisagainstface |
| vaginal | `vaginalsex`,`intercourse*`,`vaginalpenetration` | vaginalsex |
| **anal** | `analsex`,`analpenetration`,`anal*` | analfisting, anallicking, analsex, analtailsex, analtoying |
| masturbation | `*masturbation` | 3pp_femalemasturbation, femalemasturbation, malemasturbation |

**72 of 112 installed action names match no family at all.** The ones that matter for the owner's
vocabulary: `deepthroating`, `frenchkissing`, `footjob`, `rimjob`, `lickingnipple`, `lickingear`,
`spanking`, `pullinghair`, `massaging`, `breastsliding`, `breastsmothering`, `mounting`, `leglocking`,
`tribbing`, `vaginaltoying`, `vaginalfisting`, `oraltoying`, `cumonbutt/cumonchest/cumonvulva`,
`facial`, `internalclimax`, and **every OCR `3pp_*` action** (`3pp_boobjob`, `3pp_buttjob`,
`3pp_cunnilingus`, `3pp_gropingbreast`, `3pp_gropingbutt`, `3pp_kissing`, `3pp_kissfellatio1/2`,
`3pp_lickingpenis`) - the globs are prefix-anchored, so the `3pp_` prefix defeats them.

### 1.2 Scenes per act id, male player + female NPC

| act id | scenes | free-standing | needs furniture | example |
|---|---|---|---|---|
| vaginal | 119 | 105 | 14 | OARE_SittingSex, OARE_Doggystyle |
| kiss | 47 | 37 | 10 | OARE_StandingEmbraceKiss |
| hold | 36 | 23 | 13 | OARE_StandingHug |
| oralpenis:npc | 31 | 25 | 6 | OARE_SittingFellatio |
| grope:you | 26 | 18 | 8 | OARE_StandingGropingBreasts |
| handjob:npc | 19 | 13 | 6 | OARE_SimpleStandingHandjob |
| masturbation:you | 15 | 15 | 0 | OARE_OnTopMasturbation |
| oralvulva:you | 14 | 14 | 0 | OARE_SittingCunnilingus |
| fingering:you | 9 | 8 | 1 | OARE_SpooningFingering |
| grinding | 9 | 9 | 0 | OARE_MountedGrind |
| titfuck:npc | 9 | 9 | 0 | OARE_AceLyingBoobjob |
| grope:npc | 5 | 5 | 0 | OARE_CowgirlSquattingHandsOnBreasts |
| masturbation:npc | 5 | 5 | 0 | OARE_StandingRearSexMasturbation |
| nipples:you | 3 | 3 | 0 | OARE_SittingBreastSucking |
| thighjob:npc | 1 | 1 | 0 | OARE_ThighfuckFromBehind |

**Act ids with ZERO scenes:** `anal`, `sixtynine`, `nipples:npc`, `handjob:you`, `fingering:npc`,
`oralpenis:you`, `oralvulva:npc`, `titfuck:you`, `thighjob:you`.
(The last six are simply the wrong direction for a male player + female NPC and are correct.)

### 1.3 Positions, for the same pair

| position | scenes | free | furniture | example ids |
|---|---|---|---|---|
| doggy / rear / from behind | 44 | 42 | shelf 2 | OARE_Doggystyle, OARE_RearSexFPerformer, OARE_AceBackItUp |
| cowgirl | 29 | 25 | bed 4 | OARE_CowgirlSquatting, OARE_CowgirlHandOnBreast |
| missionary | 25 | 23 | table 2 | OARE_Missionary, OARE_HoldKneesFrontalSex, OARE_MatingPress |
| bend over | 23 | 16 | cookingpot 3, shelf 4 | OARE_CookingPotSex, OARE_ShelfIdleBentOver |
| all fours | 25 | 21 | bed 4 | OARE_AceBackItUp, OARE_Doggystyle |
| prone | 14 | 14 | 0 | OARE_ProneSex, OARE_ProneSexMaleKneeling |
| spooning | 11 | 11 | 0 | OARE_SpooningCuddleKiss, OARE_SpooningFingering |
| **reverse cowgirl** | **7** | 7 | 0 | OARE_ReverseCowgirlMounted, OARE_ReverseCowgirlLeaningBack, OStim2PReverseCowgirlIdleMF |
| carrying / suspended | 7 | 7 | 0 | OARE_PrincessCarryKiss, OARE_StandingCarryingSex, OARE_AceStandingLotus |
| standing (any act) | 110 | 88 | mixed | OARE_StandingHoldingSex |
| lap / sitting | 88 | 36 | bench 6, chair 9, table 5, bed 32 | OARE_LapPillow, OStimDoubleBedLeft2PSittingOnLapMF |
| footjob | 6 | 6 | 0 | OARE_SittingFootjob (**all 6 taste-listed**) |
| facesitting | 2 | 2 | 0 | OStim2PFaceSittingMF, OStim2PFaceRidingMF (**both taste-listed**) |
| **wall** | **1** | 0 | wall 1 | OStimWall2PStandingLeaningMF (neutral tier, an idle - no sex scene on a wall is installed) |

Furniture types in use: none 316, doublebed 20, singlebed 20, chair 13, shelf 13, table 13,
cookingpot 12, bench 8, wall 1.

Taste-listed ("niche", reachable only when the request names the niche): 26 MF scenes - all footjob,
facesitting, facefuck, spanking, hair-pulling, tailjob and vampire/drained scenes.
Hard-excluded: 0. Scenes with an unprovable requirement (`special`): 22.

### 1.4 F7 SETTLED

**F7 was half right and half wrong.**

- **`sixtynine`: CONFIRMED, and worse than F7 said.** Zero installed actions match `sixtynine*`, *and*
  there is **no 69 scene installed at all**: no scene id, display name or tag anywhere in the three
  packs contains `69`, `sixty` or `sixtynine`, and **zero scenes declare both a penis-oral and a
  vulva-oral action** (the pair that would make a 69 out of two actions). Evidence:
  `%TEMP%\lrg_test\inventory.php` section 5 and `inventory2.php` "69 candidates" (total: 0).
  -> "lets do 69" can never be carried out on this install. The only correct outcome is an
  in-character "can't do that" + the corner note.

- **`anal`: F7's claim is WRONG at the action level, RIGHT at the scene level.** The `anal*` glob
  matches five installed action names (`analsex`, `analfingering` is taken by `fingering` first,
  `anallicking`, `analfisting`, `analtailsex`, `analtoying`). But **no installed scene declares
  `analsex`** - `grep -rl '"analsex"' */SKSE/Plugins/OStim/scenes` returns **0 files**, and only
  6 scene files mention "anal" at all, all of them `analfingering` on face-sitting / prone-jerk
  scenes where the *player* is the one doing it. So `lrgSceneActs()` never emits `anal`,
  `lrgActOptions()` never offers it, and `lrgIntentResolve()` always answers "not reachable".
  -> **The owner has no anal animations installed.** OARE is a romance/erotica pack and OStim
  Standalone's base 2P set has none. If the owner wants "i want to fuck your asshole" to work, an
  anal animation pack has to be installed; the glue cannot invent it.
  Until then this must be an explicit in-character "can't", **never** a silent fallback to vaginal -
  which is exactly what happens today (see 3.2 / defect D4).

- **Why the v0.3 act table "found none":** for `sixtynine` because the glob matches nothing installed;
  for `anal` because the *scenes* do not exist, not because the glob is wrong.

---

## 2. THE PHRASE MATRIX

### 2.1 File, format, how to run

- Data: **`C:\Users\Jordan\AppData\Local\Temp\lrg_test\phrases.json`** - **218 player sentences**.
- Runner: `C:\Users\Jordan\AppData\Local\Temp\lrg_test\run_phrases.php`
  (`php run_phrases.php` -> TSV on stdout; `--fails` -> only rows that miss the expectation).
- Last output: `C:\Users\Jordan\AppData\Local\Temp\lrg_test\matrix.tsv` (654 rows = 218 x 3 states).
- Supporting scripts: `inventory.php`, `inventory2.php`, `startable.php`, `cfgdump.php`,
  `stage.sh` (stages the plugin + the real index; run it again after any source change).

JSON shape (`phrases.json` carries its own `_format` block):

```
{ "_format": {...}, "rows": [
  { "id":"A1", "say":"give me a blowjob", "want_kind":"act", "want_act":"oralpenis:npc",
    "want_pos":"fellatio|blowjob|mouthfuck|hj", "want_outscene":"start", "installed":"yes",
    "group":"owner pt7", "note":"owner's words, playtest 7" }, ... ] }
```

- `want_kind` - the intent kind the owner expects (`act|undress|dress|stop|faster|...|none`)
- `want_act` - expected act id (`ANY` = any act is acceptable, `''` = not applicable)
- `want_pos` - case-insensitive regex the **resolved scene id** must match when a position was named
- `want_outscene` - what should happen with no scene running (`start|talk|undress|none`)
- `installed` - `no` means the packs cannot do it, so the correct outcome is a spoken "can't" + note

Only PLAYER utterances are stored - no NPC line appears anywhere in the file.
`tools\test_phrases.php` can consume this directly: `json_decode(file_get_contents(...))['rows']`,
then the same replay loop as `run_phrases.php::runOne()`.

### 2.2 The three states

| state | current scene | ceiling | notes |
|---|---|---|---|
| A_noscene | - | - | mode `private`, ctx **null** (that is what `lrg_actions.php:750` passes) |
| B_kiss | OARE_StandingEmbraceKiss (kissing, 3 exits) | 2 | near furniture: doublebed, chair, table |
| C_doggy | OARE_Doggystyle (sexual, 4 exits) | 4 | same |

### 2.3 Headline results

| | A_noscene | B_kiss | C_doggy |
|---|---|---|---|
| matches the owner's expectation | 64 / 218 (29%) | 103 / 218 (47%) | 103 / 218 (47%) |
| wrong intent kind | 52 | 36 | 35 |
| right kind, wrong act | 18 | 33 | 33 |
| right act, confidence LOW (net cannot fire) | 30 | 31 | 31 |
| act unreachable -> CANT | - | 8 | 12 |
| landed on the wrong position | - | 7 | 4 |
| should have offered to START, gave a neutral line | 54 | - | - |

Directive shapes produced: out of a scene **148 NEUTRAL, 70 nothing, 0 DOIT** - i.e. **no
out-of-scene request ever produces a directive that tells her she may say yes**. In a scene:
B_kiss 105 DOIT / 62 CANT / 7 MAYBE; C_doggy 98 DOIT / 47 CANT / 30 MAYBE.

Of the in-scene rows that did resolve a scene, **51/77 (B) and 74/93 (C) are outside the 3-hop
route walk** - see defect D6 for why that is survivable but still wrong.

Failure rate by group (B_kiss): anal 6/7, crude 9/10, oral vulva 8/9, oral penis 8/12,
position:vaginal 17/28, hug-hold 6/8, grope 6/7, fingering 5/6, tit/thigh/foot 4/6,
out-of-scene start 6/8, misc acts 5/8, role words 4/6, kiss 4/5, speech-to-text 4/10,
compound-2 3/4, furniture 3/5, posture 3/5, **must-NOT-trigger 1/14** (the blockers are healthy),
**clothes 0/7, pt7-log 0/5** (the v0.3 fixes hold).

### 2.4 The owner's six, verbatim

| say | state | kind/conf | act | scene | directive | net | verdict |
|---|---|---|---|---|---|---|---|
| give me a blowjob | B_kiss | act/high | oralpenis:npc | OARE_SittingFellatio | DOIT | FIRES | works (warps) |
| | A_noscene | act/high | oralpenis:npc | - | NEUTRAL | - | **no start offered** |
| give me a hug | B_kiss | act/high | hold | OARE_StandingHug | DOIT | FIRES | works |
| | A_noscene | act/high | hold | - | NEUTRAL | - | **no start offered** |
| let me eat your pussy | B_kiss | act/**low** | oralvulva:you | - | CANT | skipped (conf low) | **FAILS** |
| | C_doggy | act/**low** | oralvulva:you | OARE_SittingCunnilingus | MAYBE | skipped | **FAILS** |
| lets do 69 | B_kiss | act/high | sixtynine | - | CANT | skipped (cant) | correct verdict, nothing installed |
| i want to fuck your asshole | B_kiss | act/high | **vaginal** | OARE_StandingHoldingSex | DOIT | **FIRES** | **WRONG ACT - silently vaginal** |
| do reverse cowgirl | B_kiss | act/**low** | vaginal | - | CANT | skipped | **FAILS** |
| | C_doggy | act/**low** | vaginal | OARE_ReverseCowgirlMounted | MAYBE | skipped | right scene, no order given |

### 2.5 The playtest-7 log lines, reproduced exactly

- **F1** `"now get down on your knees and suck my deck"` in OARE_StandingEmbraceKiss ->
  `act / oralpenis:npc / conf=LOW / cant "act oralpenis:npc is not reachable right now"` ->
  CANT directive -> `net skipped: confidence low`. **Byte-for-byte the 17:11:28 log line.**
- **F2** `"take my clothes off and then let's book a missionary"` -> `undress/high who=player`,
  the missionary half is gone (the scan returns on the first hit).
- **F3** `RequestAct item="vaginal"` -> `lrgResolveAct()` -> `lrgMatchAct('vaginal', openActs)`;
  in a kissing scene `vaginal` is not in `$turn['acts']`, so it falls through to the plain-words
  search, which scored `OARE_SpooningFingering` highest - reproduced.
- **F4** every out-of-scene request yields the NEUTRAL directive; `"go again"` yields `none`;
  `"i want you to ride me"` yields `act/vaginal/high` **which `lrgPickStart()` never reads**.

---

## 3. DEFECTS, CAUSES AND FIXES

### D1 - The request-frame test is far too narrow (**the single biggest cause: 92 of 654 rows**)

`lrg_intent.php:31` `LRG_INTENT_FRAME1` is `^`-anchored and its verb list is missing most of the
verbs people actually use; `lrg_intent.php:286` frame 2 has `let's`/`lets` but **not `let me`**.
Confidence is decided at `lrg_intent.php:269-271` *before* resolution, and `low` means (a) no DOIT
directive, (b) the safety net is skipped (`lrg_actions.php:1413`), and (c) `req_ceiling` stays at the
*pacing* ceiling (`lrg_actions.php:679`), so a low-confidence act request is also tier-blocked.

Failing examples (all conf=low): `let me eat your pussy`, `let me lick your pussy`,
`let me finger you`, `let me touch your breasts`, `do reverse cowgirl`, `hug me`, `cuddle with me`,
`snuggle up to me`, `jerk me off`, `grab my ass`, `play with your tits`, `french kiss me`,
`missionary`, `cowgirl`, `reverse cowgirl`, `doggy style`, `now get down on your knees and suck my deck`.

**Fix.** (a) Add `let me`, `i wanna`, `i wanna have`, `do`, `start`, `begin`, `try`, `grab`, `squeeze`,
`rub`, `jerk`, `blow`, `hug`, `cuddle`, `snuggle`, `spoon`, `eat`, `lie`, `mount`, `bounce`, `bend`,
`spread`, `pull`, `work`, `pound`, `push` to the frame vocabulary. (b) Stop anchoring frame 1 to `^`:
test it on the utterance **and** on every clause from `lrgIntentClauses()` (the same treatment the
negation already gets at `lrg_intent.php:227-238`). (c) A **bare act noun or crude noun phrase that
resolves to exactly one act family** ("missionary", "reverse cowgirl", "blowjob", "doggy style") is a
request on its own: raise it to high unless a blocker fired. That is the owner's expectation - she
said "do reverse cowgirl" and expects it done.

### D2 - The `dress` branch eats `put ... on ...` (**high confidence, fires the net, dresses her**)

`lrg_intent.php:335` runs the clothing branches *before* acts and its pattern contains `put\b.*\bon\b`.
`put` is also in `LRG_INTENT_FRAME1`, so the result is conf=high + DOIT + net FIRES.

| say | becomes |
|---|---|
| put your mouth on my cock | `dress who=npc part=all` |
| put your tongue on me | `dress who=npc part=all` |
| put your head on my lap | `dress who=npc part=**head**` (she puts her helmet back on) |
| put your mouth on me and then ride me | `dress who=npc` |

**Fix.** Require a garment word for the `put ... on` reading (`put your clothes/shirt/armour/... on`,
`put them back on`), or run `lrgMatchAct()` first and let an act hit veto the clothing branch.

### D3 - `lrgMatchAct()` is a flat longest-word contest with no sex-awareness and no position layer

`lrg_scene_index.php:928-983`. Words from all families are pooled and sorted by string length only;
ties resolve by table order; the family's own `words` never consider who has what body.

| say | resolves to | should be |
|---|---|---|
| lick my cock | `oralvulva:npc` (`lick`) | `oralpenis:npc` |
| go down on me | `oralvulva:npc` (`go down`) | `oralpenis:npc` |
| eat my pussy (male player) | `oralvulva:npc` (0 scenes) | `oralpenis:npc` or a plain can't |
| take it in the ass | `grope:npc` (`ass`) | `anal` |
| let me put it in your ass | `grope:you` | `anal` |
| fuck my cock with your tits | `grope:npc` (`tits`) | `titfuck:npc` |
| rub my cock | `fingering:npc` (`rub`) | `handjob:npc` |
| rub me with your feet | `fingering:npc` | footjob |
| lets do it spooning | `hold` (`spoon`) | `vaginal` at a spooning scene |
| i want to watch you touch yourself | `masturbation:**you**` | `masturbation:npc` |
| let me play with your pussy | `` (no word) | `fingering:you` |
| i want to fuck your asshole | `vaginal` (`fuck`) | `anal` -> not installed -> can't |

**Fix.** (a) Score by **specificity, not string length**: an anatomy+verb phrase beats a bare noun,
and a family whose direction is impossible for these two bodies is dropped before the pick. (b) Take
the participants' sexes into `lrgMatchAct()` (they are already in `$ctx['sexes']`) and flip
`oralvulva:npc` -> `oralpenis:npc` for a male player, `fingering:npc` -> `handjob:npc`, etc.
(c) Word lists that contradict a more specific family (`ass` under grope, `rub` under fingering,
`spoon` under hold, `lick`/`eat`/`go down` under oralvulva) need an explicit override table.

### D4 - Missing act families

No family exists for **footjob**, **facesitting**, **deepthroat**, **rimjob**, **spanking**,
**frenchkiss**, **massage**, **earlicking**, and none of the OCR `3pp_*` actions is reachable.
`lrgActFamily()` (`lrg_scene_index.php:849-861`) therefore returns `''`, `lrgSceneActs()` skips the
action, and the scene can only ever be found by the plain-text fallback (which is off out of a scene
and low confidence in one).

**Fix.** Add `footjob` (`footjob*`,`grindingfoot`,`kissingfoot`,`holdingfoot`,`ticklingfoot`),
`facesitting` (tag-driven, no action name exists), `deepthroat` as an `oralpenis` alias
(`deepthroating`, `*facefuck*`), and widen every glob to `*<name>` so the OCR `3pp_` prefix stops
defeating it (`*boobjob*`, `*cunnilingus*`, `*groping*`, `*kissing*`, `*lickingpenis*`,
`*fellatio*`, `*masturbation*`). Also add `frenchkissing` to `kiss` and `lickingnipple` to `nipples`.

### D5 - POSITIONS ARE NOT FIRST-CLASS (F3, and the owner's "do reverse cowgirl")

Every position is a synonym inside `acts.vaginal.words` (`lrg_scene_index.php:832-834`), so the act id
is always the bare family `vaginal`. `lrgIntentResolve()` (`lrg_intent.php:374-387`) first takes
`$opts['vaginal']['scene']` - "the nearest vaginal scene", i.e. an arbitrary one - and only then tries
to improve it with a free-text search of the fragment. When that search misses, the player gets a
different position from the one he named; when it hits, the act layer had nothing to do with it.
`RequestAct item="vaginal"` (`lrg_actions.php:1336-1351`) has exactly the same hole, which is F3.

**Fix.** Make the act id carry the position: `vaginal/doggy`, `vaginal/cowgirl`,
`vaginal/reversecowgirl`, `vaginal/missionary`, `vaginal/spooning`, `vaginal/standing`,
`vaginal/carrying`, `vaginal/lap`, `vaginal/prone`, `vaginal/allfours`, `vaginal/bendover`,
plus the furniture variants. The data is already there: the position tags live on the scenes
(`missionary` 24, `cowgirl` 21, `reversecowgirl` 6, `doggystyle` 20, `allfours` 25, `bendover` 21,
`prone` 11, `suspended` 7, `facesitting` 2) and `config.scene_index.synonyms` already maps every
spoken phrase onto those tags (182 entries, verified present). Build the position index at index-build
time from `tags + actor_tags + id words`, expose `lrgActOptions()` rows per position, and let
`lrgResolveAct()` accept `vaginal/reversecowgirl` from the LLM.

### D6 - Unreachability is turned into a refusal instead of a warp (F1)

`lrgIntentResolve()` (`lrg_intent.php:388-392`) only ever looks at `lrgActOptions()`, which is built
from `lrgSceneWalk()` - a 3-hop walk (`lrg_scene_index.php:991-1030`, `697-737`). When the act is not
in that walk it sets `cant = 'not from here'`. **Measured from the default start scene
OARE_StandingEmbraceKiss with ceiling 4, only 6 of 24 act ids are reachable in 3 hops**
(`fingering:you`, `handjob:npc`, `oralvulva:you`, `vaginal`, `grope:you`, `hold`).
`oralpenis:npc`, `titfuck:npc`, `grinding`, `thighjob:npc`, `masturbation:*`, `nipples:*` are not -
which is precisely F1.

Two separate things go wrong:

1. **Server side.** `lrgIntentResolve()` never sets `warp`. `lrgResolveControl()`'s free-text path
   does (`lrg_actions.php:1644`), and `lrgIntentScan()`'s own text fallback (`lrg_intent.php:346-351`)
   does not either. So an act request can only ever be answered by a routable scene.
2. **Confidence.** Because the frame test already dropped the confidence (D1), F1's request hit the
   *low* branch and the net logged `net skipped: confidence low` rather than the honest reason.
   Unreachability and confidence are two different things and must never be conflated.

The game is **not** the blocker: `LRG_OStim.psc:2394-2422` already warps a player-requested `goto`
whenever `NavigateBlockedReason()` returns `unreachable`, `nowarp=1` is absent and
`bAllowWarp:Intimacy` is on (default true) - the server's `warp=` key is only a hint. So the ~66-80%
of resolved scenes that my run marks `UNROUTED` **do** arrive in game today.

**Fix.** In `lrgIntentResolve()`, when `$opts[$act]` is empty, search the **whole installed pool**
for these actors / this furniture (not the walk) and return that scene with no `nowarp`; only when no
installed scene fits at all is it a `cant`. Never let unreachability touch the confidence.

### D7 - An act already running is reported as impossible

`lrgActOptions()` line 1006 skips acts already present in the current scene ("what is already
happening is not an offer"). Correct for her lead pick, wrong for the player's request: in
OARE_Doggystyle **every** vaginal position request answers "act vaginal is not reachable right now"
(`doggy style`, `fuck me from behind`, `get on all fours`, `turn around and ride me`,
`i want to take you from behind`), and in OARE_StandingEmbraceKiss `come kiss me` answers the same.

**Fix.** Pass the "already happening" filter only for her own picks. For a player request, if the
current scene already *is* the answer say so in character ("we're already doing that") rather than
CANT; if the player named a *different* position of the same act, resolve within the position index.

### D8 - Compound requests lose everything after the first hit

`lrgIntentScan()` (`lrg_intent.php:297-353`) returns on the first pattern that matches and
`lrgRecogniseIntent()` carries exactly one kind. Confirmed losses: F2's missionary,
`kiss me then suck my cock` (-> kiss only), `suck me off and then i want to fuck you doggy style`
(-> vaginal only, the blowjob is gone), `undress and ride me`, `go faster and grab my ass`,
`put me on the table and fuck me` (-> furniture only), `turn around, bend over and take it`.

**Fix.** Split on `and then / then / after that / , / and` (`lrgIntentClauses()` already exists), scan
each clause, and carry a list: the first goes out this turn, the rest is queued as a
`pending_request` in memory and replayed on the next turn (or chained after the funcret confirms).

### D9 - Nothing out of a scene ever nudges her to say yes

`lrg_actions.php:750` calls the recogniser with **`ctx = null`**, so `lrgIntentScan()`'s text fallback
(`lrg_intent.php:346`) is switched off and `lrgIntentResolve()` never runs - no scene is ever chosen
for an out-of-scene act request. And `lrgBuildRequestDirective()` (`lrg_intent.php:534-536`) replaces
any named action with the neutral "whether she does it is her own choice" whenever `!$inScene`.
Result: **148 of 218 phrases get the neutral line, 0 get a DOIT, 54 that plainly ask to start get
nothing useful.** `sit on my face`, `pick me up`, `prone bone`, `mating press`, `against the wall`,
`give me a footjob`, `lets go to the bed` do not even register.

**Fix.** (a) Pass a real context out of a scene (sexes + near furniture are already in
`$gate['state']`), so the text fallback and the position index work there too.
(b) Keep the "the server never starts a scene" rail, but change the directive: when she is willing and
in private, say plainly that `BeginIntimacy` is hers to call if she wants this. That is what R2 asks
for and it does not weaken the consent rail - she still chooses.

### D10 - `lrgPickStart()` ignores the request entirely (F4's "ride me")

`lrg_actions.php:1097-1100` calls `lrgPickStart($sexes, $nearf)` and never looks at
`$turn['intent']`. `lrgPickStartScene()` (`lrg_scene_index.php:1039-1069`) additionally restricts
itself to `start_tiers = ["kissing","affection"]` and, with no furniture, to scenes carrying the
`standing` actor tag. Measured: it returns **OARE_StandingEmbraceKiss for every input**
(no furniture, doublebed, chair, table). See 4 below for the fix.

### D11 - Unrecognised vocabulary

Returns `none` today and must not: `go again`, `another round`, `round two`, `lets do that again`,
`i want you again`, `i want you right now` (R2 explicitly wants these to count as asking to start);
`deepthroat me`, `squeeze my cock`, `work that cock`, `bounce on my cock`, `pound me`, `put it in`,
`against the wall`, `prone bone`, `mating press`, `cow girl position`, `lets do dogy style`,
`can i lick you` (blocked as a question - `can i` should be a polite frame like `can you`),
`you do the work`, `get on the bedroll`, `pull out`.

**Fix.** Add a "go again" family (out of a scene -> start; in a scene -> the last act from
`lrg_romance` history); add `can i / may i / could i` to `lrgIntentPolite()`; add the missing crude
synonyms; add `pull out` to the recogniser (`lrgResolveControl()` already knows `do=pullout`).

### D12 - Speech-to-text damage

Survives: `suck my deck`, `doggy style socks`, `i wanna you doggy style`, `hey lazette lets have sex`,
`give me a blow job`, `blowjob please`.
Fails: `lets book a missionary` (F2 - `book` is not a frame verb and `missionary` alone is low),
`lets do dogy style`, `do a reverse cow girl`, `cow girl position`.

**Fix.** Add the split-word forms (`cow girl`, `blow job`, `doggy style`, `sixty nine`, `hand job`,
`tit job`, `foot job`) and a small edit-distance pass (<=1) against the act vocabulary only - never
against the blocker patterns.

### D13 - Sex filter / niche gate (works, but only by accident)

`content_filter` is `open`, so the 26 taste-listed scenes are not excluded; `lrgFindSceneByText()`
only reaches them when the request names the niche (`$asksNiche`, `lrg_scene_index.php:1351`).
Footjob and facesitting requests do reach their scenes this way - but with `act=''`, so the act layer
never sees them and `lrgActOptions()` never offers them. With families added (D4) the niche gate must
be kept: offer them only when asked for by name.

---

## 4. STARTING DIRECTLY IN AN ACT (R2)

**Which scenes OStim can start in:** `LRG_OStim.psc:1120-1155` builds the thread and calls
`OThreadBuilder.SetStartingAnimation(builder, sceneId)`. The only game-side conditions are:

- `OMetadata.GetActorCount(sceneId) == 2` (line 1138) - the only check in our code today;
- no furniture ref -> `OThreadBuilder.NoFurniture()` is explicit, so the scene must itself be
  furniture-free (`furniture` = `""`/`none`);
- with a furniture ref -> `SetFurniture()` + `SetStartingAnimation(fscene)`, so `fscene` must be a
  scene authored for that furniture type (or a supertype);
- **no intro flag and no navigation requirement exist.** Any scene in the library is a legal start.

**How many are startable** (2 actors, MF, not excluded, not a transition, no unprovable requirement,
not a climax scene): **305 furniture-free + 100 furniture-bound**. Per act, free-standing:
vaginal 105, kiss 37, oralpenis:npc 25, hold 21, grope:you 18, masturbation:you 15,
oralvulva:you 14, handjob:npc 13, grinding 9, titfuck:npc 9, fingering:you 8, grope:npc 5,
masturbation:npc 5, nipples:you 3, thighjob:npc 1. (`startable.php`.)

**What blocks it today:** `lrgPickStartScene()` accepts only `start_tiers = ["kissing","affection"]`
and, without furniture, only scenes carrying the `standing` actor tag - **140 of the 305
furniture-free scenes are standing, 165 are not**, so two thirds of the library is unreachable as a
start even before the tier filter. And `lrgPickStart()` is never told what the player asked for.

**Fix.** `lrgPickStart($sexes, $nearFurniture, $intent)`:
1. when the intent carries a high-confidence act (and, with D5, a position), pick the best startable
   scene of that act - free-standing when there is no furniture ref, the furniture variant as `fscene`
   when there is;
2. drop the `start_tiers` and `standing` restrictions for that path (keep them for the gentle
   default), keep `special`, `climaxing`, transition and the niche gate;
3. when no startable scene of the act exists (69, anal), start at the gentle scene **only if the
   player also agreed to something** - otherwise it is a plain in-character "can't";
4. when the act exists but OStim refuses the start, fall back to the gentle start and queue the act
   as the first change (that is the "gentle start with the act queued right after" of R2).

No Papyrus change is needed for this: the wire already carries `scene=` and `fscene=`.

---

## 5. PRIORITY ORDER FOR THE BUILDER

1. **D2** - `put ... on` -> ChangeClothing at high confidence and the net fires. Cheapest fix, worst
   symptom (she re-dresses when asked for a blowjob).
2. **D3** - wrong act family, especially `asshole -> vaginal` firing the net. Wrong sex act carried
   out silently.
3. **D1** - the frame test. One change unlocks 92 low-confidence rows including four of the owner's six.
4. **D6 + D7** - unreachable/already-running must never be a refusal; warp instead (the game already
   cooperates).
5. **D5** - positions as first-class act ids (fixes F3 and `RequestAct "vaginal"`).
6. **D9 + D10** - out-of-scene context + directive, and a direct act start.
7. **D4 + D11 + D12** - the missing families and vocabulary.
8. **D8** - compound requests.
9. Tell the owner: **69 and anal are simply not installed.** Everything else she asked for is.
