# v0.2 adversarial review - SCENEINDEX, lens: CORRECTNESS AND RUNTIME

Reviewer is READ-ONLY for project files; this file is the only thing written.
Target: `glue/server/lorerim_glue/lib/lrg_scene_index.php`, the `scene_index` / `scene_start` / `scene_progression`
sections of `glue/server/lorerim_glue/config/lrg_config.default.json`, `glue/tools/test_scene_index.php`,
`glue/tools/warm_index.php`, `glue/tools/deploy_server.ps1`, report `research/v02-sceneindex.md`.

## Verdict: SHIP AFTER FIXES

The library itself is good work: the data the builder reports is reproducible to the scene (I rebuilt the index
from the real packs and got their exact numbers), the non-blocking design is real and I watched the detached
rebuild happen, the search rewrite answers plain requests correctly, and the pickers never reach a niche or a
live-only scene. But two defects are blocking. **D1**: the furniture string the game actually puts on the wire for
a bed thread is OStim's *list* type `bed`, while every scene in the packs is authored `doublebed` / `singlebed`;
with `scene_start.prefer_furniture` defaulting to `bed`, the new headline feature starts the scene on a bed and
then hands the LLM **zero** options from that scene, at every ceiling - the ladder, "next step", P1..P8 and the
routed wind-down are all dead on the one path that is now the default. The whole test suite misses it because it
only ever passes the specific type. **D2**: the index signature does not fingerprint the library, so changing the
rules in code and deploying does not rebuild the index - the warm-up prints "up to date" and the server keeps
serving the old verdicts. That directly undercuts R5's "the hard list is code and cannot be configured away", and
I hit it live during this review before I reproduced it deliberately. Both fixes are a few lines. Nothing here is
a rails breach and nothing crashes; it is "the feature quietly does nothing" and "your rule change quietly did
not take".

## Tests I ran myself (WSL DwemerAI4Skyrim3, PHP 8.2.28)

| what | result |
|---|---|
| `php -l` over every glue PHP file (lib, hooks, tools, flows, scenarios) | clean |
| `tools/test_scene_index.php --quiet` | ALL CHECKS PASSED (199), 7.6 s |
| `tools/test_scene_index.php --spawn --quiet` | ALL CHECKS PASSED; detached rebuild: trigger back in 54 ms, index finished 6.1 s later |
| `tools/test_gates.php` | 196 passed / 0 failed, 0.98 s |
| `tools/flows/run_flows.php` | 23 scenarios, 466 checks, 0 failed, 6.4 s |
| `tools/compile.ps1` | OK - 6 .pex |
| `deploy_server.ps1` here-string rendered + `bash -n` | exit 0 (not executed) |
| my probes (below) | `$env:TEMP\lrg_probe\probe1..7` |

## Claims I checked and found TRUE

Rebuilt the index with `lrgIndexWarm(true)` against the installed packs and re-derived every number:

- 607 scenes; `open` excludes **0**, `standard` excludes **38**; usable two-actor `open` **MF 416 (227 sexual) /
  FF 121 (14) / MM 134 (33)**, `standard` **390 (214) / 113 (13) / 122 (28)**. Exactly the report's table.
- unknown `content_filter` value and `grounded` both read as `standard`; missing / empty reads as `open`;
  switching the level changes the counts with no rebuild (`lrgIndexApplyFilter` derives `excluded` from the stored
  `hard` / `taste` flags).
- 22 live-only scenes, all of them the vampire-bite / drained / tailjob / bathing family, `special` correctly set.
- `OStim2PStandingSpankIdleMF` is now `sensual` (actor tags `standing,bendover,facingaway`, action `holdingbody`).
- `breast` -> female: `OARE_AceLyingBoobjob` has `need=["male","female"]`, refused for MM, allowed for MF.
- **pickers never choose a taste or live-only scene**: `lrgPickStartScene` over 3 pairs x 10 furniture types and
  `lrgPickAfterglow` from *every* two-actor sexual MF scene -> not one taste / special hit.
- position-by-name reproduces the report's table (`missionary/cowgirl/doggy/all fours/ride me` -> the right ids at
  each ceiling, `too_soon` below, `sixty nine` / `anal` / `against the wall` -> `null`), and a niche word is needed
  to reach a niche scene (`foot` -> `OARE_SittingFootjob`, `all fours` -> `OARE_Doggystyle`, not a foot scene).
- R7: `lrgSceneIndex()` really never builds outside the CLI (test proves the empty-index + `.index_wanted` path);
  the 24 h rule is gone; `exec` / `shell_exec` are **not** in `disable_functions` in either
  `/etc/php/8.2/cli/php.ini` or `/etc/php/8.2/apache2/php.ini`, so the detached path is the one that will run.
- Function signatures all match PROTOCOL 7.1 exactly; no existing signature changed; `lrgFurnitureChain` matches
  the real `furniture types/*.json` (`bed` really does declare `"supertype": "none"`, so floor scenes are legal on
  a bed, and `chair` / `table` / `shelf` / `wall` / `cookingpot` really do end their chain at themselves).
- Hot path, worst case (staging on `/mnt/c`): a whole scene turn - index load + `lrgSceneOptions` +
  `lrgPositionWords` + `lrgFurnitureOptions` + one free-text search - costs **9.7 ms cold / 3.7 ms warm**, peak
  memory 8 MB. Nothing on the crosshair or LLM path is slow.

---

## Defects

### D1 (BLOCKING, runtime + protocol). A bed thread reports furniture `bed`; the index only knows `doublebed` / `singlebed`, so the bed ladder is empty

**What the game puts on the wire.** `LRG_OStim.psc:1956` (`PushState`) fills the `lrg_scene` key `furn` from
`OThread.GetFurnitureType(0)`. That native returns OStim's **list** type, not the specific type: `bed.json` is the
only bed file with `"listIndividually": true`, and `doublebed` / `singlebed` / `bedroll` declare
`"supertype": "bed"` with `listIndividually` unset, which the shipped `furniture types README.txt` defines as
"listed as its supertype". The project's own Phase 0 research already spelled this out and warned against exactly
this use - `research/ostim-api.md:4062`: *"`GetFurnitureType` returns the LIST type: `"bed"` for any bed ... a
search with `"bed"` only reaches `bed` + `none` scenes"*. `OFurniture.GetFurnitureType(ref)` (the one the START
command uses) returns the SPECIFIC type, which is why `lrgPickStart` / `nearf` / `do=furniture` all work.

**What the index does with it.** `lrgSceneFurnitureOk()` (`lrg_scene_index.php:621`) accepts a scene only when its
`furniture` is in `lrgFurnitureChain($thread)`. `chain('bed') = [bed, none]`. The installed packs contain
**20 `doublebed` + 20 `singlebed` + 0 `bed`** scenes, so all 40 are refused - not only as targets, but as
route hops, which is what kills it.

**Measured** (`probe1.php`, `probe3.php`, MF pair, live list empty):

```
lrgPickStart(MF, [doublebed]) = {"scene":"OARE_StandingEmbraceKiss","furn":"doublebed",
                                 "fscene":"OStimDoubleBedLeft2PBothSittingKissMF"}

thread furniture 'doublebed' (what the TEST passes)      thread furniture 'bed' (what the GAME sends)
  options ceiling=2: 8                                     options ceiling=2: 0
  options ceiling=3: 8                                     options ceiling=3: 0
  options ceiling=4: 8                                     options ceiling=4: 0
  walk(3 hops): 19 scenes                                  walk(3 hops): 0 scenes
  afterglow: OStimDoubleBedLeft2PSittingOnLapMF, 1 hop     afterglow: OARE_SpooningIdle, warp (no route)
bed-authored scenes: 40, invisible when the thread reports 'bed': 40
```

Same for the single bed (`OStimSingleBedLeft2PBothSittingKissMF`: 8 options at `singlebed`, **0** at `bed`). The
start scene's only navigation is `OStimDoubleBedLeft2PBothSittingMF`, itself a `doublebed` scene, so the walk dies
at hop 1 and cannot reach the floor scenes behind it.

**Why nobody saw it.** Every furniture string in `tools/test_scene_index.php` is the specific type - `doublebed`,
`singlebed`, `bedroll`, `chair`, ... (lines 224-283, 400, 433). The list type `bed` is never passed to anything but
`lrgSceneFurnitureOk` on a *synthetic* scene (line 233). The flow tests run with `furn=''`.

**Blast radius.** `scene_start.prefer_furniture` defaults to `bed` and the game's furniture radius is 1200 units,
so any bed on the same floor makes this the normal path: the scene starts on the bed and from that moment
`lrgSceneOptions` is empty -> no P-list in the scene notes, no `[next step]` marker, `P1..P8` resolve to nothing,
a lead tick has nothing to step to (R6 dead), and the wind-down degrades from a 1-hop route to a fade+warp. The
FF / MM / bedroll start is unaffected (`OARE_SpooningCuddleKiss` is a floor scene, and floor scenes stay legal on
a bed). Free-text position search still answers, but only ever with floor scenes.

**Smallest correct fix (GAME + PROTOCOL, one note for SCENEINDEX).** Do not widen the index - it cannot tell a
double bed from a single one, and offering a `doublebed` scene to a `singlebed` thread is wrong. Send the specific
type instead. In `LRG_OStim.psc PushState`, replace

```papyrus
string furn = OThread.GetFurnitureType(0)
```

with the furniture object's own type (both natives are in the installed sources:
`OThread.psc:268 ObjectReference Function GetFurniture(int ThreadID)`,
`OFurniture.psc:16 string Function GetFurnitureType(ObjectReference FurnitureRef)` - returns `"none"` for a
non-furniture ref, which is exactly the value the wire already means):

```papyrus
ObjectReference furnRef = OThread.GetFurniture(0)
string furn = "none"
if furnRef
    furn = OFurniture.GetFurnitureType(furnRef)
endif
```

`OFurniture` is already imported by `LRG_OStim.psc` and already in `compile.ps1`'s list, so this costs nothing.
PROTOCOL 1.2 should say `furn` is the SPECIFIC OStim furniture type (same vocabulary as `nearf` and as the
`furn` parameter of the start / furniture commands) - today the one key name carries two different vocabularies,
which is what allowed the mismatch. SCENEINDEX should add one regression check per list type
(`lrgSceneOptions($bedStart, [], 4, 8, $MF, 'bed')` must not be empty once the game is fixed, and
`lrgFurnitureChain('bed')`/`'table'` documented), so a future regression is caught in `test_scene_index.php`.

### D2 (BLOCKING, correctness). The index signature ignores the library, so a rule change in code never rebuilds the index

`lrgIndexSignature()` (`lrg_scene_index.php:242-255`) hashes `modlist.txt` mtime + the watched folder mtimes + a
whitelist of `scene_index` config keys + `LRG_INDEX_VERSION`. It does **not** include the file that produces the
digests. Every tier rule, the hard list, the taste flags, `special`, `need`, `to` and the search vocabulary are
baked into the cache by that code. So any change to `lrg_scene_index.php` that does not also bump
`LRG_INDEX_VERSION` leaves the old verdicts in place, and `deploy_server.ps1`'s warm-up - the one step that would
repair it - reports success and skips.

Demonstrated (`probe7.sh`, staged copy only, restored afterwards): I added one word to `LRG_HARD_WORDS`, a change
that must drop 47 scenes, and left `LRG_INDEX_VERSION` alone.

```
--- 3. warm-up after the code change
scene index: up to date, 607 scenes, 0.0s
{"built":...,"scenes":607,"stale":false,"building":false,"error":""}
--- 4. what the server would now serve
  scenes=607 hard-excluded=0 kissing-tier=33
  lrgPickStartScene(MF) = "OARE_StandingEmbraceKiss"      <- a scene the new rule forbids
```

This is not theoretical: it bit me before I reproduced it. Part-way through the review my staged tree served an
index in which 47 scenes carried `hard=hard word: kissing` - every kissing-tier scene, i.e. the whole gentle start
tier, produced by a library that is not the one on disk - while `lrgIndexWarm()` answered
`{"ok":true,"scenes":607,"skipped":true}` and `lrgIndexStatus()` answered `"stale":false`. `lrgIndexWarm(true)`
put it right and reproduced the report's numbers exactly. Two consequences worth stating plainly:

- R5's promise that the hard list "lives in code and no config key can switch it off" is only true for a *fresh*
  index. Tighten the rail in code, deploy, and the server keeps the old, looser verdicts.
- `test_gates.php:28` and `tools/flows/adapter.php:339` both call `lrgIndexWarm()` **without** `--force`, so a
  green suite can be green against an index built by different code. (`test_scene_index.php:19` does force, which
  is why it is immune - and why the builder's own numbers are right.)

**Smallest fix:** one line in `lrgIndexSignature()` - add the library's own fingerprint to `$parts`, e.g.
`$parts[] = (int) @filemtime(__FILE__);` (`deploy_server.ps1`'s `cp -r` always refreshes the mtime; the signature
is only computed in the warm-up and in the once-a-minute async check, and `__FILE__` is on local ext4, so this is
free). `md5_file(__FILE__)` is stricter if you would rather not depend on mtimes surviving a copy.

### D3 (MEDIUM, runtime). A throwing build writes no error record, so the 10-minute back-off never engages

`lrgIndexWarm()` (`:156-184`) is `try { ... } finally { unlock }` with **no `catch`**. Only one failure mode -
`modlist.txt` unreadable (`:290-296`) - writes `['error' => ..., 'attempt' => time()]` into the meta, and that
record is the only thing `lrgIndexMaybeRebuildAsync()` (`:202`) uses for its 10-minute back-off. Anything that
*throws* instead of returning `null` - `RecursiveDirectoryIterator` on a mod folder it may not traverse, a pack
directory removed by MO2 while the walk is running, a `json_encode` depth/memory failure - unwinds without leaving
a record. Meanwhile the triggering request has already written `.index_checked = 'stale'` and `.index_wanted` is
still there (`lrgSceneIndex()` re-touches it on every LLM request while the index is missing), so `$wanted` beats
the 60 s throttle and the next `lrg_npcstate` - every ~20 s, and forced at every scene start - starts another
detached 6-second build. That is an unbounded loop of background builds, each one re-reading ~700 JSON files and
3949 mod folders over 9p.

**Smallest fix:** wrap the build and reuse the branch that already exists -

```php
try { $idx = lrgBuildSceneIndex(); }
catch (Throwable $e) {
    $old = lrgIndexMeta() ?? [];
    @file_put_contents(lrgIndexMetaPath(), json_encode(['error' => 'build failed: ' . $e->getMessage(), 'attempt' => time()] + $old
        + ['v' => 0, 'built' => 0, 'scenes' => 0, 'sig' => '', 'dirs' => []]), LOCK_EX);
    lrgLog('scene index: build failed - ' . $e->getMessage());
    $idx = null;
}
```

### D4 (MEDIUM, runtime). Cache markers are created with the caller's umask; the documented recovery command runs as root and locks the web user out - silently

The distro's default WSL user is **root with umask 0022** (`wsl -d DwemerAI4Skyrim3 -- sh -c 'id; umask'` ->
`uid=0(root)`, `0022`), and the report's own recovery instruction is to run
`php lib/lrg_scene_index.php warm --force` there. On a `data/` directory that does not yet hold them, that run
creates `scene_index.meta.json` and `.index_checked` as `root:www-data 0644` (the dir is setgid, so the group is
right, but the group has no write bit). Apache runs as `www-data`. Every write in this file is `@`-suppressed, so
nothing is logged and nothing fails loudly:

- `@file_put_contents("$dir/.index_checked", ...)` fails -> the mtime never advances -> the
  `$now - filemtime($stamp) < 60` throttle in `lrgIndexMaybeRebuildAsync()` (`:198`) never short-circuits, so
  **every** snapshot pays the full signature check (9 `filemtime()` calls across `/mnt/f`; the builder measured
  27 ms, and 466 ms for the variant that watches all scene folders) instead of one per minute.
- `@file_put_contents(lrgIndexMetaPath(), ...)` at the end of a later www-data rebuild fails -> the stored `sig`
  never catches up -> `$stale` stays true -> a detached 6 s rebuild is started on **every** snapshot, forever.
  (The index file itself survives: it is written temp+rename, and `rename()` only needs the directory bit.)

`deploy_server.ps1` is safe both ways (it runs the warm-up as `www-data`, then `chown -R dwemer:www-data` +
`chmod -R ug+rwX` on `data/`), so this is reachable only through a manual/CLI warm-up on a fresh `data/` - which is
exactly what the report tells the owner to do when a pack file was edited in place.

**Smallest fix:** `umask(0002);` as the first statement of `lrgIndexWarm()` and `lrgIndexCli()`, or `@chmod($f, 0660)`
immediately after each of the four writes (`scene_index.json` after the rename, the meta, `.index_checked`,
`.index_wanted`). One line either way.

### D5 (LOW, protocol). `lrgIndexMaybeRebuildAsync()` reads the clock with `time()`, not `lrgNow()`

`lrg_scene_index.php:197` `$now = time();`. PROTOCOL 7.2 is explicit: *"`lrgNow(): int` ... every time read of the
glue goes through it"*. It is internally consistent (the `built` / `attempt` stamps the comparison uses are also
real time), so nothing misbehaves today, but the 60 s throttle and the 10-minute back-off cannot be driven from
`LRG_TEST_NOW`, which is why neither has a flow test. Fix: use `lrgNow()` here and for `built` / `attempt`.

### D6 (LOW, cross-file). `lrgPickAfterglow()`'s route facts are computed and then thrown away, and BEHAVIOUR re-derives them with a hard-coded hop count

SCENEINDEX added `lrgPickAfterglow()` returning `['scene','hops','routed']`, but `lrg_actions.php:627-629` still
calls `lrgPickAfterglowScene()` and then decides `warp` by running a **second** `lrgSceneWalk(..., 4, ...)` with
the hop limit written in literally. `lrgPickAfterglow()` uses `scene_index.afterglow_max_hops` (default 4), so the
two disagree the moment the owner changes that key - a scene routed at 5 hops would be sent with `warp=1` and
fade+jump for no reason - and the walk is paid twice on a wind-down turn.
**For INTEGRATOR:** have BEHAVIOUR call `lrgPickAfterglow()` and use its `routed` flag
(`$warp = $a['routed'] ? 0 : 1;`), dropping the second walk.

### D7 (LOW, dead code). `lrgSceneExcluded()` has no callers

`lrg_scene_index.php:544`, kept "for callers of the old name" - a repo-wide grep finds none. It also reads
`$s['tags'] / ['actor_tags'] / ['actions'] / ['id'] / ['name']` without defaults while its own `+` guard only fills
`types` and `tier`, so the first caller who passes a partial digest gets PHP 8.2 "Undefined array key" warnings.
Either delete it or give it the same `+ [...]` defaults for all five keys.

### D8 (LOW, test flakiness). CLI lock contention caches the EMPTY index for the rest of the process

`lrgSceneIndex()` `:76-85`: on the CLI with no cache file it calls `lrgIndexWarm(true)`; if another process holds
`.index_lock` that returns `skipped` without building and without setting `LRG_INDEX_CACHE`, `lrgIndexLoad()`
returns `null`, and the function caches `lrgIndexEmpty()` in `$GLOBALS['LRG_INDEX_CACHE']` for the whole process.
Every lookup then silently returns nothing. This is reachable from the parallel flow-test variants (they run as
child processes against one `data/` dir). Fix: when the warm-up reports `skipped` and the cache file is still
missing, retry the load once after the lock frees, or do not memoise the empty index.

## Two things that are not defects but the owner should decide on

- **`prefer_furniture: bed` + a 1200-unit radius can snap the pair sideways into another room.**
  `OFurniture.FindFurniture(2, player, 1200.0, 96.0)` constrains only the Z difference (96 units = same floor); the
  1200-unit radius is about 17 m horizontally. Combined with the new default, a scene started in an inn's common
  room can begin on a bed behind a wall. Against R3 ("she steers you somewhere private"), teleporting to the bed is
  the wrong picture. Consider a smaller default radius for the *start* pick, or `prefer_furniture: any/bed` only
  when `cellown=npc`/`prent=1`.
- **Translation files are decoded as UTF-16LE unconditionally** (`:321`). OStim's are UTF-16LE, but a pack that
  ships a UTF-8 `_english.txt` produces mojibake keys, the `$key` name is left unresolved and `lrgDescribeScene()`
  falls back to the readable id. Graceful, but a two-line BOM/`mb_detect` check would keep the display names.

## Rails (hunt item 3): no breach found

- Nothing in the `scene_index` config can reach the hard list: `LRG_HARD_WORDS` / `LRG_HARD_GLOBS` /
  `LRG_HARD_INCAPACITATED` / the non-humanoid `type` test are constants, `lrgSceneHardExcluded()` never calls
  `lrgConfig()`, and the test asserts that (line 140). Confirmed by reading and by grep.
- `excluded` is recomputed at load from the stored `hard` flag, so `content_filter: open` can never re-admit a
  hard-excluded scene - only the `taste` half is level-dependent.
- The one gap worth naming, for the record: `LRG_HARD_INCAPACITATED` is matched against `actor_tags` + `actions`
  only, not `tags`, and only at tier >= sensual. Dormant today (all four installed `sleeping` scenes and all nine
  `drowsy` ones are neutral/affection, and the `drowsy` ones are live-only as well), but a future pack that puts
  `"sleeping"` in the scene's own `tags` on a sexual scene would slip through. Adding `$s['tags']` to that
  `array_merge` costs nothing and closes it.
- Beast races (`mouth: false` for Khajiit / Argonians) really are unfilterable from the snapshot - the builder
  says so and is right; the start scene and a warp target are the exposed spots, and the honest answer is the one
  they gave.
- No explicit example dialogue anywhere in `lrg_scene_index.php`, `test_scene_index.php`, `warm_index.php` or the
  config sections. The request words in the tests are search input (position / act words), which is what they are
  for. Config `_readme` strings describe rules, not lines.
- `stop` never touches this library: `lrgResolveControl()` returns `['do' => 'stop']` from its first regex before
  any index call, including mid wind-down. Nothing SCENEINDEX added can delay or divert it.

## Cross-file notes for the integrator

1. **D1 is a GAME change** (`LRG_OStim.psc PushState`) plus one line in PROTOCOL 1.2 stating that `furn` is the
   SPECIFIC OStim furniture type. SCENEINDEX only owes a regression check at the list type. Until the game side
   lands, `scene_start.prefer_furniture: "never"` is the safe setting and should be the shipped default.
2. **D6**: BEHAVIOUR should switch `lrg_actions.php:627-629` to `lrgPickAfterglow()` and use its `routed` flag.
3. **D2's second half**: `test_gates.php:28` and `tools/flows/adapter.php:339` should call `lrgIndexWarm(true)`
   (or assert `lrgIndexStatus()['built'] >= filemtime(lib)`), otherwise a green suite proves nothing about the
   current library.
4. The deployed server still carries `LRG_INDEX_VERSION = 2` (`/var/www/html/HerikaServer/ext/lorerim_glue`,
   Sep 21 07:07) - the v2 -> v3 bump is what will force the first rebuild after the deploy, and it is the only
   reason D2 does not bite on this particular round.
