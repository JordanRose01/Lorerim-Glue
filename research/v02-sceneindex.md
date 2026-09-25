# v0.2 - SCENE-INDEX builder report

Files changed (all mine per PROTOCOL 8): `glue/server/lorerim_glue/lib/lrg_scene_index.php` (rewritten, index format v3),
`glue/server/lorerim_glue/config/lrg_config.default.json` (ONLY sections `scene_index`, `scene_start`, `scene_progression`, targeted edits),
`glue/tools/test_scene_index.php` (rewritten, 199 checks), NEW `glue/tools/warm_index.php`, `glue/tools/deploy_server.ps1` (warm-up step + final chown of `data/` only).
Tests: `php -l` clean; `test_scene_index.php --spawn` ALL CHECKS PASSED (199); BEHAVIOUR's `test_gates.php` 196 passed / 0 failed against this library;
`deploy_server.ps1` parsed by PowerShell and its bash part checked with `bash -n` (NOT executed - only the integrator deploys). Nothing tested in game.
Request words in this report and in the test are search input (position / act words), not dialogue.

## 1. Content filter levels (R5)
`scene_index.content_filter` = `open` (NEW DEFAULT) | `standard` (alias accepted: `grounded`; any unknown value reads as the stricter `standard`).
- The three `excluded_*` lists of the config are now TASTE lists: they only apply under `standard`.
- A HARD list lives in code (`LRG_HARD_WORDS`, `LRG_HARD_GLOBS`, `LRG_HARD_INCAPACITATED`, non-humanoid actor `type`) and no config key reads or overrides it: forced / non-consensual /
  aggressive, creature / bestiality, necro, gore, any non-adult word, a creature actor slot, and a sensual / sexual scene with a sleeping / unconscious / drowsy participant.
  Matching is on whole words of the CamelCase-split id / name, tags, actor tags and action names (so "Drape" / "Grapes" do not trip "rape"), plus a few unambiguous substrings.
  It fails closed: a future pack scene called "...Young..." or "...Minor..." is dropped; the build log names every hard-excluded id with its reason.
- Both flags (`hard`, `taste`) are stored per scene, `excluded` is derived when the index is loaded: switching the level takes effect on the next request, no rebuild.

Data, 607 installed scenes (OStim 280, OARE 287, OCR 40; 114 transitions; tiers neutral 169 / affection 89 / kissing 33 / sensual 84 / sexual 232):

| level | excluded | usable two-actor scenes MF / FF / MM (of which sexual) |
|---|---|---|
| open | 0 (the hard list matches nothing that is installed - it guards future packs) | 416 (227) / 121 (14) / 134 (33) |
| standard | 38 | 390 (214) / 113 (13) / 122 (28) |

What `standard` removes and `open` gives back: vampire feeding 19 (3 OARE + 2 OStim bite scenes, 5 "drained" idles, 9 transitions), foot play / tickling 7, spanking 5, rough oral 3
(`*FaceFuck*`, `*MouthFuck*`), face sitting 2, hair pulling 1, tail play 1. Full id list: section C of the test output.
Two guards keep `open` sensible:
- **Live-only scenes (22):** OStim's action files declare requirements the snapshot cannot prove - `vampire` (vampirebiting), `tail` (tailjob), `inwater` (bathing) - plus the config list
  `live_only_id_patterns` (`*vampire*`, `*drained*`, `*devour*`). They are only offered / found when OStim's own live list (`next=`) names them. A non-vampire pair never gets a bite scene.
- **Niche scenes are never a side effect:** a taste-listed scene is a text-search candidate only when the request names the niche itself (a term that matches one of the taste patterns);
  pickers (start, furniture, wind-down) never choose one; in the P-list they rank one hop further. "all fours" can no longer land on a foot scene that happens to be on all fours.
Other tier fix found on the way: `allfours / bendover / spreadlegs` scenes are now at least `sensual` even when they declare a harmless action (`OStim2PStandingSpankIdleMF` was `affection`).
New sex rule: OStim's `actor properties` give `breast` only to females, so a slot that needs `breast` (boobjob, groping breast) now needs a woman (MM lost 1 scene, correctly).

## 2. Non-blocking index (R7)
- `lrgSceneIndex()` outside the CLI never builds. It serves `data/scene_index.json` whatever its age (also a v2 file of the previous build: new fields are read with defaults),
  or the empty index, and leaves `data/.index_wanted`. Every lookup degrades quietly on the empty index. On the CLI a missing / old-format cache is built on the spot (tests, tools).
- `lrgIndexWarm(bool $force=false)` builds under `flock(data/.index_lock)`; skipped when the signature is unchanged or another process is building; index written to a temp file and `rename()`d
  (a request never sees half a file); small `data/scene_index.meta.json` beside it so status / staleness never decode the index.
- `lrgIndexMaybeRebuildAsync()` (BEHAVIOUR already calls it from `preprocessing.php` on `lrg_npcstate`): <= 1 signature check per 60 s (the wanted-flag beats the throttle), never decodes the index,
  stale / missing -> `nohup php lib/lrg_scene_index.php warm &` via `exec` (fallback `shell_exec`, else synchronous build on the state path); 10 min back-off after a failed build; no-op when
  `data/` is not writable (status reports it). Verified for real with `--spawn`: the trigger returned after 51 ms, the detached process finished the index 5.8 s later.
- `lrgIndexStatus()` -> `built, scenes, stale, building, error` from two small local files. The 24 h forced rebuild is gone (a 30-day-old index with an unchanged signature is not rebuilt - tested).
- Signature = modlist.txt mtime + per OStim mod `meta.ini` (MO2 rewrites it on every install / update), the `OStim` folder and its `scenes` folder (9 paths) + only the config keys baked into the
  digests + format version. Measured why: ONE stat on the Windows drive costs ~4 ms through WSL; watching all 104 scene sub-folders cost 466 ms per check, the 9 paths cost 27 ms.
  Not detected: a pack file edited in place without MO2 (then run `php lib/lrg_scene_index.php warm --force`).
- CLI: `php lib/lrg_scene_index.php warm [--force] | status`; `tools/warm_index.php` = same for staged runs. `deploy_server.ps1` runs the warm-up after the lint as `www-data`
  (`runuser`, present in the distro; `www-data` can read `/mnt/f` - checked) and then chowns `data/` back to `dwemer:www-data`.

Measured (staging on /mnt/c, i.e. worst case; PHP 8.2.28):
| what | cost |
|---|---|
| full build | 5.7 - 6.3 s, of which 2.5 - 2.7 s is `is_dir` over 3949 enabled mods and the rest reading ~700 JSON files through 9p. Now background only |
| warm-up when already current | 40 ms |
| index file / meta | 292 KB / 1 KB |
| `json_decode` of the index | 1.1 ms |
| first `lrgSceneIndex()` in a request (read + decode + filter) | 6 - 7 ms on the Windows-drive staging; **0.95 ms measured on the server's own ext4** (read-only timing of the deployed file); later calls 0 |
| `lrgIndexMaybeRebuildAsync()` throttled / with signature check | 3 ms (ext4: ~0) / 27 - 30 ms once a minute, fast state path only |
| `lrgSceneOptions` 0.2 ms, `lrgFindSceneByText` 0.17 ms avg (468 searches 81 ms; first one 2.4 ms builds the word + pool caches), `lrgPositionWords` 2.7 ms, `lrgPickStart` 0.4 ms, `lrgFurnitureOptions` 1.1 ms, `lrgPickAfterglowScene` 0.2 ms; peak memory 9 MB | |
So an LLM request now pays about 1 ms for the index, never 6 s.

## 3. Furniture (R8)
Installed: doublebed 20, singlebed 20, chair 24, bench 10, table 19, shelf 19, cookingpot 16, wall 5 scenes; none for `bed` itself or `bedroll`. OStim chain: doublebed / singlebed / bedroll -> bed -> none,
bench -> chair, chair / table / shelf / wall / cookingpot stand alone. `lrgSceneFurnitureOk` already followed the chain; tested incl. a synthetic scene for the supertype `bed`.
- `lrgPickStart($sexes, $nearf)` -> `scene` (standing fallback, always), `furn`, `fscene`. `scene_start.prefer_furniture` = `never | bed` (default) `| any` (beds first, then the closest other type).
  On a bed the floor scenes are legal too, but only sitting / lying ones are taken (nobody stands on a mattress); furniture-authored scenes are preferred.
  Result: MF double / single bed -> `OStim<Double|Single>BedLeft2PBothSittingKissMF` (kissing, sitting on the bed's edge); FF, MM and any pair on a bedroll -> `OARE_SpooningCuddleKiss` (kissing, lying).
- The ladder on a double bed, MF (P-list from the bed start): gentle -> Sitting On Lap On Bed, Sitting On Bed, the other bed side, `OStim2PSittingMF` (gateway to every floor scene, played on the bed);
  sensual ceiling -> Cowgirl Idle, Doggy Idle first, then the bed-edge idles; sexual ceiling -> Sitting On Bed Cowgirl first. FF: Spooning Idle / Kiss / Lying Cuddling -> Sitting Female Approach, Mounted Female ->
  Spooning Cuddle Kiss Masturbation, Spooning Fingering. MM analogous. Full print: section E of the test.
- `lrgFurnitureOptions($nearf, $current, $sexes, $maxTier, $threadFurn)` -> per type `furn, label, words, scene, tier`: never the thread's own furniture or family (bed -> other bed), never above the ceiling,
  ALWAYS with a scene id, one option per spoken label (two beds in reach = "the bed", the closest). The scene keeps the mood: a lying / sitting floor scene moves to the bed as itself (same id);
  otherwise closest tier at or below the current one, same act preferred, furniture-authored preferred. Labels / words: `scene_index.furniture_labels / furniture_words` (in-code defaults, supertype fallback).
- Text search on furniture uses the same chain (on a chair only chair scenes; on a bed bed + floor scenes).

## 4. Positions by name (R1)
Problems found in the v1 search with 52 plain requests x MF / FF / MM x 3 ceilings: an after-climax idle answered an act word because its ID carries the word; junk hits on id words ("out", "against", "back");
"Female ..." scenes for two men; FF / MM got nothing for generic requests; several common phrasings unknown. Changes:
- Vocabulary per scene now includes OStim's ACTION TAGS from the action files (`oral, fellatio, cunnilingus, intercourse, fingering, licking, cumshot ...`) - real data instead of guesses; bookkeeping tags and id noise removed.
- **Intent:** a term that is an OStim action (or action tag) carries that act's tier. A request never resolves to a scene BELOW the act it names -> under a lower ceiling the answer is `too_soon`.
  Position tags (missionary, cowgirl, doggystyle, all fours, bent over ...) carry a soft tier: under a sensual ceiling they resolve to the staging idle of that position (one step), under a sexual ceiling to the act.
  Plain postures (stand, sit, lie, kneel) stay near the current tier.
- **Coverage rule:** a scene under the ceiling only wins when it covers as many of the asked words as the best scene above it; otherwise `too_soon`. A request that names an act or position must hit it
  ("<act> standing" for a pair without that act = null, not any standing scene). Words no installed scene knows are ignored ("... under the stars tonight").
- Pseudo targets in the synonym table: `@sexual / @sensual / @kissing / @gentle / @any` ("sex" for FF / MM = some sexual scene the pair can be in; "foreplay"; "something else" prefers what is routable now).
- Name fit: a scene named for the other sex loses 2 points (still returned when it is the only match). Climax scenes are not targets.
- Synonym table rebuilt from the index vocabulary (182 keys, phrases matched longest first; only 4 targets - analsex, analpenetration, sixtynine, lappillow - name something no installed scene has, kept for other packs). `position_words` extended. A user `lrg_config.json` merges into `synonyms` key by key.
Result, MF from the standing start (K = kissing ceiling, S = sensual, X = sexual); FF / MM tables in the test output:
| request | K | S | X |
|---|---|---|---|
| missionary / cowgirl, ride me, get on top / doggy style | too soon | MissionaryIdle / CowgirlIdle / DoggyIdle [sensual] | OARE_Missionary / OARE_CowgirlSquatting / OARE_Doggystyle |
| reverse cowgirl, bend over | too soon | too soon | OARE_ReverseCowgirlMounted / OARE_HoldingArmsStandingBack |
| from behind | OARE_StandingHoldingFromBehind [affection] | OARE_GropingBreastsFromBehind | OARE_RearSexFPerformer |
| on all fours | too soon | OARE_FemaleAllFours | OARE_Doggystyle |
| lie on your stomach | OStim2PLyingOnTopMF [affection] | same | OARE_ProneSexFemaleLegsIn |
| oral, use your mouth, go down on me, blowjob / lick me | too soon | too soon | OARE_SittingFellatio / OARE_SittingCunnilingus |
| handjob / use your hand, finger me / between your breasts / thighs | too soon | too soon | OARE_SimpleStandingHandjob / OARE_SpooningFingering / OARE_AceLyingTitfuck / OARE_ThighfuckFromBehind |
| sex, make love to me | too soon | too soon | OARE_SittingSex (with `$prefer` the routable one wins) |
| touch me, grope / play with my breasts / grab my ass | too soon | OARE_SittingGropingBreast / OARE_StandingGropingBreasts / OARE_HoldingGropingButt | same |
| kiss me, make out / kiss my neck | OARE_StandingKiss / OARE_PrincessCarryNeckKiss | same | same (stays a kiss) |
| cuddle / hold me, hug me / hold my hand | StandingHandOnHeadCuddle / OARE_StandingHug / OARE_StandingHandHolding | same | same |
| spooning / lie down with me / sit down / on your knees / carry me, pick me up / sleep / rest your head on my lap | SpooningIdle / MaleLyingKiss / SittingFemaleApproachKiss / same / PrincessCarryKiss / SpooningSleeping / LapPillowHeadStroke | same | same |
| sixty nine, anal, against the wall (no wall in the thread), dance with me, nonsense | null | null | null (not installed) |
FF: everything that needs a man is null (not a wrong scene); oral / lick / go down -> OARE_SittingCunnilingus; finger me, use your hand -> OARE_SpooningFingering; sex -> a sexual FF scene.
MM: oral / blowjob -> OARE_SittingFellatio; handjob -> OARE_SimpleStandingHandjob; thighs -> OARE_ThighfuckFromBehind; finger me, breasts -> null / never a breast scene.
Known soft spots: "sit on my lap" without furniture = lap pillow (the only floor lap scene; on a bed / chair it is Sitting On Lap); `too_soon` reports the tier of the best blocked match, which can be one tier higher than the lowest blocked one.

## 5. P-list order (small behaviour change, same signature)
`lrgSceneOptions`: when the ceiling is above the current scene, up to a third of the slots (2 of 8) go to the nearest scenes OF THE CEILING TIER and are listed first - before, the first 8 by hop count
often contained no next-step scene at all, so a lead tick could not step up from the list. Bare routing idles (tier neutral), scenes named for the other sex and niche scenes rank one hop further.

## 6. Wind-down (R8)
`lrgPickAfterglowScene` (and new `lrgPickAfterglow` -> `scene, hops, routed`): cuddle on the route (<= `afterglow_max_hops` 4) > any gentle scene on the route > (`afterglow_allow_unrouted`, default true)
best gentle scene the pair can be in on that furniture (warp). On a bed, floor scenes in which someone stands are avoided. Checked from EVERY sexual scene of each pair, not a sample:
| pair | no furniture | double / single bed | bedroll | other furniture |
|---|---|---|---|---|
| MF | 203 scenes: 191 routed (avg 3.3 hops), 12 warp, 0 none | 207: 195 / 12 / 0 | 203: 191 / 12 / 0 | chair 3 (2 routed, 1 warp), bench 6, table 4, shelf 2, cooking pot 3: all have one |
| FF | 13: 12 routed (2.2 hops), 1 warp | same | same | table 1 routed; no FF sexual scene on the other types |
| MM | 29: 20 routed (2.7 hops), 9 warp | same | same | bench 3, table 1 routed; chair 1: none (no gentle MM chair scene -> wind down in place) |
The 12 best-connected sexual hubs reach their wind-down over a real route: MF 12/12, FF 11/12, MM 9/12. Bed-edge scenes wind down to Sitting On Lap On Bed.

## 7. Not done / open
- Nothing in game. Needs an in-game look: (a) `SetFurniture(bed)` + a FLOOR scene as starting animation (what FF / MM / bedroll starts use; legal by OStim's supertype rule); (b) `ChangeFurniture(ref, <the scene the thread is already in>)`.
  If (a) misbehaves: `scene_start.prefer_furniture: "never"`.
- Build time itself is unchanged (~6 s): moved off the request path instead. An incremental scan (skip `is_dir` for mods seen before) would save ~2.6 s; left out because it would miss a mod that gains OStim content in an update.
- Beast races: OStim's actor properties give Khajiit / Argonians `mouth: false`, so OStim hides kissing / oral scenes for them; the snapshot has no race flag, so the index cannot know. The live list protects hop-1 options, not the start scene or a warp.
- OStim gives every NPC `penis: true` (strap-on support); the index stays conservative (penis -> male) so it never offers what OStim refuses when "intended sex only" is on.
