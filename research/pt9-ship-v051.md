# pt9 - RELEASE, LoreRim Glue v0.5.1

Release lane for v0.5.1, run 2026-09-22 ~07:30 local (11:30 UTC). Inputs: the two build reports
(`pt9-followers-build.md`, `pt9-latency-reconcile.md`), `glue\OWNER_ADDENDA.md` items 8-10.

**Verdict: SHIPPED.** Everything compiles, every offline test and all 74 flow scenarios pass, the
server is deployed and the game-side files are installed by hash. Nothing was tested in Skyrim -
this lane does not play the game, and the two build lanes state plainly that no playtest has ever
had a real follower present.

**The shipped state is still inert for menuless questing**: `bMenuless = 0`, `bDlgDryRun = 1` in the
installed `settings.ini`. The follower *verbs* therefore do nothing in game yet. The follower facts,
the `<companion_status>` block, the hide rule, the ghost repair and the conversation-hold skip are
live at once, as are every one of the latency reconciliations.

---

## 1. Release gates

| gate | result |
|---|---|
| `config.json` (MCM) | **valid JSON** |
| `settings.ini` complete | **yes** - `test_mcm_wiring.php` 4 passed / 0 failed; **113 controls** listed, every one with an ini line **and** a reader (corrected in the 0.5.1 fix pass - the tool prints 113; "17" was the number of rows this lane looked at, not the total) (5 of them this round's Followers page). Flow `d53` asserts the same thing as a release gate, 21 checks |
| `server\...\config\lrg_config.default.json` | **valid JSON** |
| `server\...\manifest.json` | **valid JSON**, `version = 0.5.1` |
| `compile.ps1` | **OK** - 2 rounds, 22 auto-stubs, 10 `.pex`, compiler reported **0 errors, 0 warnings**; the 500-char `.pex` string guard and the 0.5.1 deploy denylist both passed |
| `esp_dump.py` | **valid** - TES4 (3 records, formver 44, MAST Skyrim.esm), `LRG_MainQuest` with VMAD `LRG_Main` / `LRG_OStim` / `LRG_Dialogue` / `LRG_DlgProbe` + `LRG_PlayerAlias` on the alias, `LRG_MCMQuest` with `LRG_MCM` + `SKI_PlayerLoadGameAlias`. All VMAD byte counts consumed exactly (108/108, 67/67) |
| `php -l` | **74 files, 0 errors** |

### Test suite

| test | result |
|---|---|
| `test_gates` | **316 passed, 0 failed** |
| `test_intent` | **190 passed, 0 failed** |
| `test_phrases` | **14 passed, 0 failed** (overall hit rate 91.9 %, floor 82 %) |
| `test_scene_index` | **ALL CHECKS PASSED** (607 scenes, peak 10.4 MB) |
| `test_dialogue` | **132 passed, 0 failed** |
| `test_prompt_index` | **67 passed, 0 failed** |
| `test_prompt_index --db` | **112 passed, 0 failed** |
| `test_mcm_wiring` | **4 passed, 0 failed** |
| `test_services` | **35 passed, 0 failed** |
| `test_latency` | **26 passed, 0 failed** |
| `flows\run_flows.php --strict` | **74 scenarios: 74 passed, 0 FAILED, 0 pending; 1161 checks, 0 warnings. RESULT: OK** |

Nothing was fixed, because nothing failed. No test was skipped, relaxed or re-run to get a pass.

Two standing notes the index tests print, unchanged and neither a failure:
* one plugin is engine-only / unreadable - `Sanguine Symphony.esp`;
* nine PNAM-inverted topics in this load order (`DialogueMorKhazgurBorgakhPersuade`,
  `MQ202RatwayEsbernBribe`, `CWMission03Intimidate`, ...). Upstream data, reported, changed nothing.

---

## 2. Server deploy

`tools\deploy_server.ps1`, no arguments.

**Postgres did not need starting** - `15/main (port 5432): online`, `pg_isready` accepting
connections before the run.

```
anchor pre-flight: 28 anchors, one match each
scene index: rebuilt, 607 scenes, 5.5s
schema ensured: lrg_index
loaded 37561 prompts, 5718 layers into schema lrg_index
prompt index v1  source=db  schema=lrg_index  rows=37561  layers=5718
                 file_rows=37561  coverage_rows=432  built=2026-09-22T11:30:47+00:00
check kinds found in this load order: bribe, intimidate, persuade
index pre-flight: lrg_index.lrg_prompt has 37561 rows, service catalog present
deployed to /var/www/html/HerikaServer/ext/lorerim_glue
```

Verified on the live copy afterwards:

* `manifest.json` → `0.5.1`; `lib/lrg_core.php:16` → `define('LRG_VERSION', '0.5.1')`.
* `lrg_index.lrg_prompt` = 37,561 rows, `lrg_index.lrg_prompt_layer` = 5,718. **Warm.**
* `data/service_catalog.json` present (the deploy fails without it); no `CFTO.esp` warning.
* **No plugin errors.** `log/lorerim_glue.log` has no `fatal`, `parse error` or `uncaught` line at
  all; the apache error log's only glue line is a routine `[FUNCTION] Removing 6
  Change_Clothing:ExtCmdLRG_Clothing` from 21 Sep 22:17, long before this round.

### The latency lane's two files were already live

`pt9-latency-reconcile.md` says "NOT DEPLOYED … exactly two files differ from the live copy:
`lib/lrg_actions.php` and `config/lrg_config.default.json`". **That note is stale.** The followers
lane's whole-tree deploy at 07:19 carried them. Before this lane's own deploy I byte-compared every
`.php` / `.json` / `.sql` in `server\lorerim_glue` against the live copy: **zero differences**, in
both directions, with only the owner's `config/lrg_config.json` live-only (as designed).

The three reconciliation markers were then read out of the live files directly:

| reconciliation | live state |
|---|---|
| 1.1 position cap reverted | `max_positions_per_act: 6`; **no** `max_positions_total` anywhere in code or config; the `_pt9_positions_readme` explaining why is in the shipped default config |
| 1.2 RequestAct catalogue drop reverted | no `&& !$asked` and no `$asked` computation in `lib/lrg_actions.php` |
| 1.3 scene-talk clock armed late | `lrgNoteSceneTalk()` has exactly one call site, `lib/lrg_actions.php:1000`, inside `lrgPrepareTurn()` (876-1215) and behind `!$same && $type === 'lrg_scenetalk' && !lrgIsOutroTick()` |

So the owner's install no longer narrows her act list and no longer drops the catalogue on an
"asked" turn. The deploy above simply re-confirmed it and rebuilt the index.

### Action catalog

All **six** glue rows are present and activated in the schema the server actually reads
(`search_path` = `"$user", public`):

```
public.core_action_custom
  ExtCmdLRG_Clothing       Change_Clothing    t   import_version 10
  ExtCmdLRG_Invite         Suggest_Privacy    t   10
  ExtCmdLRG_RequestAct     Request_Act        t   10
  ExtCmdLRG_SceneControl   Change_Intimacy    t   10
  ExtCmdLRG_SelectTopic    Take_Up_Business   t    1
  ExtCmdLRG_StartIntimacy  Begin_Intimacy     t   10
```

A stale per-playthrough copy exists in schema `chim_profile_default` with only four rows at
`import_version 7`. It is **not** on the search path, so nothing reads it today; if that playthrough
were ever made active, `lrgEnsureActions()`'s "a glue row is missing → reinstall" branch repopulates
it on the first request. Recorded, not acted on - writing into CHIM's per-profile schema is not this
project's business.

---

## 3. Game-side install

`tools\install_mo2.ps1` was run first, as the rule requires. It **refused**:

```
Mod Organizer is running. Close it first - profile files must not be edited while MO2 is open.
```

**Skyrim is not running** (no `SkyrimSE` / `skse64_loader` process). MO2 has been open since
21 Sep 16:54. So the round's fallback applied: our own changed files only, copied into
`F:\Modlists\LoreRim\mods\LoreRim Glue` and **verified by SHA-256 after the copy**.

24 files compared (`LoreRimGlue.esp`, `Seq\*`, `Scripts\LRG*.pex`, `Source\Scripts\LRG*.psc`,
`MCM\Config\LoreRimGlue\*`):

* **10 copied, all hash-verified OK** - every `.pex`, including `LRG_Followers.pex`.
* **14 already byte-identical** - the ESP, the seq file, all 10 `.psc` sources, `config.json`,
  `settings.ini`.
* Nothing outside the glue's own mod folder was read or written. No profile file was touched.

### Why all ten `.pex` differed although no source did

The 10 `.psc` sources hash identically to the installed copies, yet every `.pex` differed. This is
**Papyrus compiler non-determinism, not a content change**, and it is worth writing down so a later
round does not treat it as a regression:

* every file is **exactly the same length** as the one it replaced (e.g. `LRG.pex` 1319 = 1319);
* the first differing byte is offset 14 - inside the 8-byte compilation timestamp at bytes 8-15;
* the rest is a **string-table permutation**: in `LRG.pex` the entries `conditional` and `hidden`
  are swapped, which shifts every later index by one and so changes ~75 % of the bytes without
  changing a single instruction.

The freshly built `.pex` are this release's own artifacts, so they were installed rather than
reverted, and the mod folder now matches the project tree exactly.

### `meta.ini` still reads `0.1.0`

`install_mo2.ps1` is the only thing that writes that line and it could not run. Note that it reads
**`0.1.0`**, not `0.5.0` as `pt9-followers-build.md` section 7.4 states - the version line has never
been refreshed since the very first install. One run of `install_mo2.ps1` with MO2 closed fixes it
and nothing else depends on it; MO2's left-pane version is cosmetic.

---

## 4. Findings - things for the owner or the next round, none of them blocking

1. **`pt9-latency-reconcile.md` "NOT DEPLOYED" is out of date** (section 2 above). No action needed;
   the reconciled behaviour is live. Worth correcting if that report is used as a source later.
2. **`meta.ini` says `0.1.0`**, not `0.5.0`. Fixed by one `install_mo2.ps1` run with MO2 closed.
3. **Three leftover backups ship with the mod.** `game\LoreRimGlue\Source\Scripts\` holds
   `LRG_Main.psc.bak-docstrings`, `LRG_OStim.psc.bak-docstrings` and
   `LRG_Profile.psc.bak-docstrings` (21 Sep, 164 KB together). `install_mo2.ps1` copies with
   `robocopy /E`, so a full install would carry them into the mod folder. They are inert text the
   game never reads. **Not deleted** - they are another lane's files and the cost of being wrong is
   higher than 164 KB. Delete them before the next full install if nobody wants them.
4. **`apache2` is not running in the distro** (`apache2 is not running ... failed!`), so the CHIM web
   UI on port 8081 is down right now. That is the expected state with the owner's launcher closed and
   it did not affect the deploy - only postgres is needed, and it was already up. The owner's web-UI
   steps in section 5 need the launcher started first.
5. **Stale per-profile action catalog** in schema `chim_profile_default` (section 2). Inert.
6. **Nothing of v0.5.1 has been tested in Skyrim.** The follower work carries eight playtest items
   (P-F1..P-F8 in `pt9-followers-build.md` section 8) and every one is `[U]`. The PocketTTS backend
   recommendation is likewise unmeasured in game.

---

## 5. Owner steps, carried forward from the two build lanes

Both build lanes' owner sections stand unchanged. Verified against the live database just now, so
these are current facts, not recollections:

### 5.1 CHIM web UI (port 8081, start the launcher first) → Actions

```
public.core_action
  Follow        Follow                     is_activated = t   <- switch OFF
  MakeFollower  Join_#PLAYER_NAME#_Party   is_activated = t   <- switch OFF
  FollowPlayer  Follow_#PLAYER_NAME#       is_activated = t   <- LEAVE ON, the glue uses it
  WaitHere      Wait_Here                  is_activated = f   <- already off, leave it
```

`MakeFollower` is written for Nether's Follower Framework, which is **not** in this modlist; here it
only half-recruits, and the game's dismiss line then goes to your real follower. `Follow` follows
another actor at priority 100 with no owner and no end condition, and nothing in the glue uses it.
The glue hides both wherever it can see they cannot work and repairs the damage if it happens anyway
- these two switches are the only way it can never happen.

### 5.2 In-game MCM

* **LoreRim Glue → Followers** (new page): the defaults are right. The one choice worth a thought is
  *"How to fix her"* - leave it on *"Make her real if there is room"*.
* **CHIM → Behavior**: switch **"NPCs Sandbox Near Player"** and **"NPCs Walk To Target"** off while
  testing with a real follower; they are the priority-55 layer and compete with her follow package.
* **CHIM → Keys, K2** ("make the NPC in your crosshair wait here"): do not use it on a follower.
* **"force default voice"**: leave off - RDO workaround, RDO is not installed, and it kills
  custom-voiced follower audio.

### 5.3 Latency, the one big lever (owner's decision, CHIM-side)

`/home/dwemer/audio.cpp/server.json` → `"backend": "cpu"`, `"threads": 8` (8, not 12 - 8/12/16/24 are
within noise and 8 leaves 24 of 32 logical cores for Skyrim). Back up to `server.json.bak` first,
with Skyrim and the CHIM server stopped; revert = restore the `.bak`. Then play one scene with ~20
spoken lines and run the soundcache median/p90 check in `PLAYTEST9_NOTES.md` section 7 - pass =
median under ~1.0 s, p90 under ~2.0 s, nothing over 5 s.

The ~0.28 s of silence at the front of every spoken line is a **CHIM core file** change
(`tts/tts-pockettts.php` ~448) and is the owner's call; nothing was touched, and doing nothing is a
defensible option. Model choice: decide it for compliance and cost, not speed (n=6 cannot separate
0.79 s from 0.92 s).

### 5.4 Optional

* **Simple Follower Framework**: `SimpleFollowerFramework.ini` has `bFollowerOptionSelector=2` and
  `iSpeechLevelsPerSlot=25`, so slots are Speech/25 + 1 - early game that is **one** slot. To
  exercise the follower paths now, raise Speech or temporarily set `bFollowerOptionSelector=0` with
  `iMaxFollowers=2`. SFF has no MCM; the ini is the control surface.
* **MO2**: close Mod Organizer and run `tools\install_mo2.ps1` once. Safe to re-run; it refreshes
  `meta.ini` to `0.5.1` and re-checks the profile entries.

---

## 6. What "shipped" means here

* **Deployed**: `/var/www/html/HerikaServer/ext/lorerim_glue` at `0.5.1`, index warm in schema
  `lrg_index` (37,561 / 5,718), service catalog present, no plugin errors.
* **Installed**: `F:\Modlists\LoreRim\mods\LoreRim Glue` byte-identical to
  `glue\game\LoreRimGlue`, every copied file hash-verified. `meta.ini` version line pending one
  MO2-closed run.
* **Untested in game.** Everything above is offline verification and live-server verification. The
  first Skyrim evidence for any of v0.5.1 is still the owner's next playtest.
