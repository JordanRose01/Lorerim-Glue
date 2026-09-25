# SHARMAT (aiagent_nsfw, Wondernuttz/Sharmat-Alpha) - prior-art research

STATUS: IN PROGRESS (skeleton written first so a cutoff does not lose everything). Sections are filled in as each question is answered.

Method: web only (GitHub REST API, raw.githubusercontent.com, GitHub web pages). Nothing cloned, downloaded or installed.

## Executive summary

(pending)

## Q1. Repo facts

VERIFIED (GitHub REST API, fetched 2026-09-21):

- Repo metadata - https://api.github.com/repos/Wondernuttz/Sharmat-Alpha : description null; created_at 2026-01-23T16:22:02Z; pushed_at 2026-09-19T04:39:01Z; license null; stars 0; forks 3; open_issues_count 1; default_branch main; size ~34 MB (mostly logo PNGs); language PHP; has_issues true; has_wiki false; has_discussions false; archived false.
- LICENCE: NONE. `license: null` in the API and no LICENSE / COPYING file anywhere in the recursive tree (https://api.github.com/repos/Wondernuttz/Sharmat-Alpha/git/trees/main?recursive=1). Legally that means all rights reserved by default - code may be read for interoperability facts but must not be copied into the glue.
- README: there is NO root README.md. The only README is mod/README.txt (https://raw.githubusercontent.com/Wondernuttz/Sharmat-Alpha/main/mod/README.txt), an install note: copy the /mod folder over the existing SHARMAT mod in MO2/Vortex; the web UI "Update" button updates server files only, never game files; scene-framework detection is toggled by `Data/SKSE/Plugins/StorageUtilData/SHARMAT_scene_framework.json` (shipped content: `{"ostim":1,"sexlab":1}`; missing file = both enabled; change needs a save reload).
- Version: 3.1.9.3 in dwemer-package.json, manifest.json and mod/version.txt (all three agree).
- Releases: NONE (https://api.github.com/repos/Wondernuttz/Sharmat-Alpha/releases returns an empty array). Distribution is "main branch = live" (manifest.json declares a main-branch channel with force-update) via the HerikaServer plugin manager; release notes exist only as commit messages.
- Activity (https://api.github.com/repos/Wondernuttz/Sharmat-Alpha/commits?per_page=30): very active single-maintainer project. Last commit 2026-09-19 (dc1723e, merge of PR #4 "Add OStim and SexLab scene detection toggles" by hey-danielx). 30 most recent commits span 2026-07-18 .. 2026-09-19; version went 3.1.5 (07-18) -> 3.1.6 -> 3.1.7 -> 3.1.8 (07-21/24) -> 3.1.9 (07-29 / 08-18) -> 3.1.9.1 / 3.1.9.2 (08-25) -> 3.1.9.3 (09-14/19). Outside contributors: Tyler Maister / maister-sk, Augusto Beiro, hey-danielx, RANGROO.
- Commit messages worth noting (verbatim titles): "3.1.8: make Open Mode bypass the consent decision..." (ef65b12, 2026-07-21); "Make SHARMAT authoritative over scene arousal" (afe1e94); "HOTFIX: OSLA arousal sync could feed the rechat loop..." (1eb2d1f); "Fix OStim scene speech pacing" (fd8bd82); "Restore server-owned scene speech pacing" (62f7bab); "3.1.9: pace scene speech and preserve scene state" (69901d8); "3.1.9: harden updates and scene role handling" (816ed62); "Complete Sharmat 3.1.9.1 safety and scene fixes" (7698de1); "Remove Sharmat response token ceilings" (ce1c22a); "Schema heal: legacy conf_opts without unique index..." (5fbf415).
- Issues / PRs (https://api.github.com/repos/Wondernuttz/Sharmat-Alpha/issues?state=all&per_page=30): 5 items, ALL pull requests, zero bug-report issues. Open: PR #5 "Add SHARMAT NPC plugin-data storage support" (RANGROO, 2026-09-19). Closed: #4 detection toggles, #3 "Update functions.php" (legacy whitespace trim), #2 "Minor fixes" (property init timing, variable naming, armor-slot protection on undress), #1 "Bundle SHARMAT server package for automatic install" (.dwpkg, schema-v4 manifest).

(Targets / requirements: see the end of this section, filled from config_section_info.php and the Papyrus sources.)

## Q2. Layout (server side / game side / package descriptor)

VERIFIED (tree: https://api.github.com/repos/Wondernuttz/Sharmat-Alpha/git/trees/main?recursive=1, 108 blobs, truncated false):

- The REPO ROOT is the server plugin folder: it is deployed as `HerikaServer/ext/aiagent_nsfw/` (manifest.json config URL is `/HerikaServer/ext/aiagent_nsfw/config_manager.php`). There is no `ext/` sub-directory in the repo.
- CHIM hook files present at root: `preprocessing.php` (119911 B), `prerequest.php` (190363), `prepostrequest.php` (570), `postrequest.php` (13446), `context_pre.php` (54136), `context.php` (49976), `context_building.php` (1913), `functions.php` (154620), `prompts.php` (43149), `manifest.json` (801), `dwemer-package.json` (230). NOT present: `globals.php`, `processing.php`/`postprocessing.php`, `comm.php`.
- Non-hook server modules: `common.php` (150850), `nsfw_ostim_handler.php` (234845 - the OStim scene brain), `nsfw_relationship.php` (102006), `nsfw_physics.php` (58707, VR touch/gaze), `nsfw_npc_scene.php`, `nsfw_fertility_family.php`, `nsfw_profile_queue.php` + `background_profile_worker.php` + `profile_worker_tick.php` (LLM-generated per-NPC profiles), `scene_lookup.php`, `scene_threads.php`, `scene_role_policy.php`, `open_mode_policy.php`, `spank_motion_policy.php`, `payment_handler.php`, `contact_state.php`, `helpers.php`, `catalog_seed.php` (action catalog seeding), `nsfw_data.php` / `nsfw_import_data.php` / `import_nsfw_data.php`, `migrate_scenes.php`, `rename_table.php`, `sharmat_reset.php`, `sharmat_updater_lib.php`, `check_auto.php`, `ostim_log_viewer.php`, `nsfw_debug_panel.php`.
- Settings UI: a bespoke web UI, `config_manager.php` (744869 B) with section files `config_section_settings.php`, `_prompts.php` (239094), `_npc_settings.php`, `_reltypes.php`, `_scenes.php`, `_fertility.php`, `_defeat.php`, `_logs.php`, `_info.php`. Config persisted in `conf/conf.php` (+ duplicate `cmd/conf/`), schema `conf/conf_schema.json` (65483), loader `conf/conf_loader.php`.
- Data: `data/scene_defaults.json` (1145253 B = 1.1 MB of per-scene description defaults); `cmd/gen_scene_desc.php` and `cmd/gen_prompt.php` (generators).
- Tests: `tests/` has 9 files (PHP + node .mjs static tests) incl. `intimacy_safety_static_test.mjs`, `open_mode_policy_test.php`, `scene_role_policy_test.php`.
- dwemer-package.json (https://raw.githubusercontent.com/Wondernuttz/Sharmat-Alpha/main/dwemer-package.json) VERBATIM fields: `schema_version: 4`, `name: "aiagent_nsfw"`, `version: "3.1.9.3"`, `description: "SHARMAT server integration for CHIM."`, `server.mutable_paths: ["conf/conf.php", "cmd/conf/conf.php"]`. It declares NO game payload, NO dependencies, NO minimum server version.
- manifest.json (https://raw.githubusercontent.com/Wondernuttz/Sharmat-Alpha/main/manifest.json): name aiagent_nsfw, display name SHARMAT, version 3.1.9.3, the catalogue description, repo Wondernuttz/Sharmat-Alpha, featured, config page `/HerikaServer/ext/aiagent_nsfw/config_manager.php`, channel = main branch (live), force-update enabled.
- Game side `/mod`: `AIAgentNSFW.esp` (535 bytes - essentially a quest + player alias container; ESL flag NOT stated anywhere I read - UNKNOWN), `Seq/AIAgentNSFW.seq` (start-game-enabled quest), `version.txt` = 3.1.9.3, `SKSE/Plugins/StorageUtilData/SHARMAT_scene_framework.json` = `{"ostim":1,"sexlab":1}`, Papyrus: `AIAgentNSFW` (208367 B source - one monolithic quest script), `AIAgentNSFWPlayerAlias` (extends ReferenceAlias), `AIAgentNSFWSceneEngine` (`Scriptname AIAgentNSFWSceneEngine Hidden`, global-function library), `AIAgentVRItems`. NO SKSE DLL. NO MCM script in the tree (no SkyUI `SKI_ConfigBase` script, no MCM Helper `MCM/Config` folder) - all configuration is in the server web UI plus the one JSON file.
- Scripting dependencies visible in the sources: PapyrusUtil (`JsonUtil`, StorageUtil data folder), OStim script API (OThread, OThreadBuilder, OActor, OLibrary, OFurniture, OActorUtil), SexLab (`SexLabFramework`, `sslThreadController`), CHIM `AIAgentFunctions`. Compiling from source therefore needs SexLab + OStim headers; at runtime both frameworks are soft-detected (`HasOStim()` checks OStim.esp by form lookup; `GetSexLab()`).

## Q3. OStim integration specifics

(pending)

## Q4. Consent / gating model

(pending)

## Q5. In-scene dialogue

(pending)

## Q6. Action registration with CHIM 3.3.x and command/result plumbing

(pending)

## Q7. Namespaces occupied

(pending)

## Q8. Maturity signals

(pending)

## Q9. Recommendation

(pending)

## Open questions

(pending)

## Fetch log (what was tried)

- OK  https://api.github.com/repos/Wondernuttz/Sharmat-Alpha  (repo metadata)
- OK  https://api.github.com/repos/Wondernuttz/Sharmat-Alpha/git/trees/main?recursive=1  (truncated: false)

### Raw tree listing (VERIFIED, from the git/trees URL above; sizes in bytes)

Root (server-side plugin files live at the REPO ROOT, not under ext/): .gitignore 108; PROMPT_FLOWCHARTS.md 7318; background_profile_worker.php 17290; catalog_seed.php 13152; check_auto.php 1005; common.php 150850; config_manager.php 744869; config_section_defeat.php 8916; config_section_fertility.php 14242; config_section_info.php 16387; config_section_logs.php 4233; config_section_npc_settings.php 44555; config_section_prompts.php 239094; config_section_reltypes.php 10723; config_section_scenes.php 5262; config_section_settings.php 125468; contact_state.php 11465; context.php 49976; context_building.php 1913; context_pre.php 54136; dwemer-package.json 230; functions.php 154620; helpers.php 7322; import_nsfw_data.php 1950; manifest.json 801; migrate_scenes.php 2013; nsfw_data.php 129297; nsfw_debug_panel.php 35061; nsfw_fertility_family.php 26190; nsfw_import_data.php 110408; nsfw_npc_scene.php 25694; nsfw_ostim_handler.php 234845; nsfw_physics.php 58707; nsfw_profile_queue.php 24693; nsfw_relationship.php 102006; open_mode_policy.php 6475; ostim_log_viewer.php 8849; payment_handler.php 10341; postrequest.php 13446; prepostrequest.php 570; preprocessing.php 119911; prerequest.php 190363; profile_worker_tick.php 1596; prompts.php 43149; rename_table.php 736; scene_lookup.php 20579; scene_role_policy.php 1407; scene_threads.php 11726; sharmat_reset.php 12378; sharmat_updater_lib.php 34316; spank_motion_policy.php 1892.

Dirs: cmd/ (gen_prompt.php 11813, gen_scene_desc.php 8463, conf/ = character_map.json, conf.php 58962, conf.sample.php 35985, conf_loader.php 3553, conf_schema.json 65483); conf/ (same five files, same sizes); data/scene_defaults.json 1145253; images/ (logos + "Race Photos"/ 10 PNGs); scripts/build-dwpkg.ps1 5280; tests/ (intimacy_safety_static_test.mjs 3825, legacy_command_whitespace_test.php 1505, open_mode_policy_test.php 9259, scene_role_policy_test.php 1144, sharmat_updater_local_stage_test.php 1311, sharmat_updater_network_test.php 969, sharmat_updater_test.php 15783, spank_motion_policy_test.php 1894, unsaved_section_ui_test.mjs 19853).

mod/: AIAgentNSFW.esp 535; README.txt 1517; version.txt 8; SKSE/Plugins/StorageUtilData/SHARMAT_scene_framework.json 23; Scripts/ (AIAgentNSFW.pex 151600, AIAgentNSFWPlayerAlias.pex 5338, AIAgentNSFWSceneEngine.pex 35666, AIAgentVRItems.pex 3945); Seq/AIAgentNSFW.seq 4; Source/Scripts/ (AIAgentNSFW.psc 208367, AIAgentNSFWPlayerAlias.psc 6455, AIAgentNSFWSceneEngine.psc 43790, AIAgentVRItems.psc 6834).

NOTE: there is NO README.md and NO LICENSE file anywhere in the tree. No SKSE DLL in /mod.
