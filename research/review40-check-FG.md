# Adversarial check of REVIEW_40_RESPONSE.md — sections F and G (items 32-40) + "Still open"

Status: IN PROGRESS (skeleton written first so a cutoff leaves a partial file). Items are appended as they are checked.

Verdict scale: SOUND / SOUND WITH CORRECTION / WRONG / UNVERIFIABLE.

Path shorthand: `HS` = `\\wsl.localhost\DwemerAI4Skyrim3\var\www\html\HerikaServer`; `WSL:` = path inside the DwemerAI4Skyrim3 distro.

---

## Item 32 — server updates, ext/<glue> survival, package auto-sync

**Verdict: SOUND WITH CORRECTION** (the core claim is right and the "unverified updater commands" gap is now closed; three design holes found).

### What I checked
- `.gitignore`: `HS/.gitignore:2` is `/ext/*`, lines 3-7 whitelist `herika_heal`, `time_awareness`, `xLifeLink_plugin`, `generic_installer.php`, `relationship_system`; `/log` ignored at line 8. `git check-ignore -v ext/glue ext/glue/manifest.json ext/glue/lib/x.php ext/glue/log/a.log` run inside the distro returns `.gitignore:2:/ext/*` for all four. CONFIRMED.
- Checkout state: origin `https://github.com/Dwemer-Dynamics/HerikaServer.git`, branch `aiagent`, HEAD `9385c45` (2026-09-12).
- **The updater's exact git commands (lead listed this as unverified — now verified):**
  - `F:\DwemerDistro\Distro\Manual Update Server.bat:26` updates only `/home/dwemer/dwemerdistro` (`git fetch origin && git reset --hard origin/main && ./update.sh`), then line 33 runs `/usr/local/bin/update_gws`.
  - `WSL:/usr/local/bin/update_gws:638` -> `update_installed_server herika ... /var/www/html/HerikaServer` -> line 629 `ddistro_server update herika --branch <main|dev>`.
  - `WSL:/usr/local/bin/ddistro_server:910-946` `update_repository()`: `git fetch origin +refs/heads/$branch:refs/remotes/origin/$branch` (918), optional `git reset --hard HEAD` only with `--force` (937-940), `git checkout -B $branch origin/$branch` (942), `git reset --hard origin/$branch` (944). **No `git clean` anywhere**: a bounded grep for `git .*clean` over `/usr/local/bin /opt /home/dwemer/dwemerdistro HS/debug HS/ui` has one hit, the distro README stating the opposite: `WSL:/home/dwemer/dwemerdistro/README.md:28-30` ("It does not run `git clean`, so untracked files and runtime data remain untouched").
  - One-time org migration path `recover_herika_checkout()` (`ddistro_server:84-137`): tars the whole checkout, enumerates `git ls-files --others` (no `--exclude-standard`, so ignored files are included, line 102), `git checkout --force -B` (129), then restores the runtime tar (132). An ignored `ext/<glue>` survives this too.
  - Legacy (non-git) adoption `copy_legacy_runtime()` (`ddistro_server:959`) copies `uploads data/voices soundcache log ext` back. `ext` survives.
  - What does NOT survive: `ddistro_server uninstall herika --confirm PURGE-HERIKA` -> `safe_remove_product_root` (`ddistro_server:873-880`, called at 1197) = `rm -rf` of the whole server root; and the legacy `F:\DwemerDistro\Distro\Tools\Utils\SwitchToDev.bat:3` (`git reset --hard HEAD; git checkout aiagent; git pull`) which also has no clean.
  - After every update the manager re-owns everything: `ddistro_server:829-831` `chown -R dwemer:www-data`, `chmod -R ug+rwX,o-rwx`, `chmod g+s` on directories.
- GitHub installer wipes the target: `HS/ui/server_plugin_installer.php:363-366` (`chimPluginInstallerRemoveDirectory($targetDir)` then `rename`). CONFIRMED.
- Package manager: `HS/lib/plugin_package_manager.php:13` `SCHEMA_VERSION = 4`; manifest must have `name`, `version`, `server` (331-335); every payload entry must be under `server/` plus root `manifest.json` and `checksums.sha256` (266-270, 342-350); install target is `ext/<manifest name>` (448); old folder is renamed to `data/plugin_packages/backups/<job>/<name>` (449, 458), only `server.mutable_paths` are copied forward (456, 483-499); migrations = `migrations/*.sql` sorted, tracked in `plugins.plugin_migrations` inside one transaction (501-538); a failed migration ROLLS BACK the whole activation (466-473). State lives in `HS/data/plugin_packages` (27). CONFIRMED.
- API: `HS/ui/api/plugin_packages.php` actions `probe` (27), `start-upload` (35), `upload-chunk` (47), `status` (56), `packages` (60). `probe()` decides by name + version string only (`plugin_package_manager.php:34-49`). CONFIRMED.
- Game side: strings re-dumped from `F:\Modlists\LoreRim\mods\CHIM\SKSE\Plugins\AIAgent.dll`: `Data/CHIM/server-plugins` @0x32CE08, `ui/api/plugin_packages.php` @0x32CAC0, `[SERVER_PLUGIN_SYNC] {} has multiple packages; using newest file {}` @0x32CF30, `Ignoring {} because package filename is not a valid version` @0x32CF80, `Startup sync was incomplete; retrying once in 15 seconds` @0x32D320. So the layout is `Data/CHIM/server-plugins/<PluginName>/<version>.<zip|dwpkg>` and the sync runs at startup. CONFIRMED as strings; never exercised on this machine (no mod ships such a folder, `HS/data/plugin_packages` state is created lazily).

### Corrections
1. "modes 660/770 (matches existing files [V])" is only half right. Actual: directories are `2770` (setgid) `dwemer:www-data`; tracked plugin FILES are `770` (`ext/herika_heal/functions.php`, `manifest.json`), only `ext/generic_installer.php` is `660`. Either works for PHP includes; the updater normalises with `ug+rwX,o-rwx` + `g+s` anyway.
2. The default WSL user for `wsl -d DwemerAI4Skyrim3 -- ...` is **root** (my `whoami` returned `root`). Anything `deploy.ps1` writes through `\\wsl.localhost\...` or a plain `wsl` call lands as root with umask 022. The explicit `chown dwemer:www-data` step is therefore mandatory, not cosmetic, and must run with `-u root` (or do the copy as `-u dwemer`).
3. "the updater's exact commands are unverified" can be removed from the Still-open list: see above.

### Strongest counter-arguments (design)
- **Two deployment channels write the same folder.** `deploy.ps1` copies into `ext/<glue>`; the bundled package also installs into `ext/<glue>` by REPLACING the folder. `probe()` compares only the version string against `data/plugin_packages/packages/<name>.json`. Scenario: dev copies build N+1 by script; the MO2 mod still holds package version N, but the server has no package record yet (first run, or state folder removed) -> `reason = not_installed` -> the game uploads N at next launch and the newer dev copy is renamed away into `backups/`. Silent downgrade on every game start until versions line up. Fix: pick ONE channel per environment (dev = script and NO package in the mod; release = package only), or have `deploy.ps1` also write the package state record.
- **Nothing runs migrations on the script channel.** Only three code paths execute `migrations/*.sql`: the package manager, `ui/server_plugin_installer.php:242-266`, and `ext/generic_installer.php:148-181` (which uses a different, unqualified `plugin_migrations` table). A file copy runs none of them. If the glue then self-migrates on first load, and the package channel later runs the same `001_*.sql` with a plain `CREATE TABLE glue_x`, the statement fails and the package activation is rolled back on every launch. Rule: every migration idempotent (`IF NOT EXISTS`), and one owner for running them.
- **`using newest file`** means the DLL picks by file time, not by version. Leaving two archives in the folder during development can upload the older version. Keep exactly one archive per plugin folder.
- **Name collision kills the whole server update.** If upstream ever ships a tracked `ext/<same name>` (they whitelist first-party plugins in `.gitignore`), `git checkout -B` aborts with "untracked working tree files would be overwritten" and `ddistro_server update` fails for the entire server. Use a clearly private folder name.
- **Security aside (not the glue's bug, but it affects the threat model):** `ui/api/plugin_packages.php` has no authentication check in the file; any host that can reach the server port can upload a package that is extracted into `ext/` as PHP. Do not widen network exposure while relying on this channel.
- "Survives a server reinstall" is true for code only: after `PURGE-HERIKA` the game re-uploads the package, but `glue_*` tables and mutable-path data are gone unless the DB survives. State that explicitly.

