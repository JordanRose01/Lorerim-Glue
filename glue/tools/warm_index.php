<?php
// LoreRim Glue - scene index warm-up for staged / offline runs (thin wrapper around the library's own CLI entry).
// Usage (inside WSL):  php tools/warm_index.php [--force]      rebuild only when stale, or always with --force
//                      php tools/warm_index.php status         one JSON line: built, scenes, stale, building, error
// The deployed plugin does not need this file: deploy_server.ps1 and the server itself run
//   php <plugin>/lib/lrg_scene_index.php warm
// Reads the MO2 mods folder (read-only); writes only <plugin>/data/scene_index.json + scene_index.meta.json.
require __DIR__ . '/../server/lorerim_glue/lib/lrg_scene_index.php';

$args = array_slice($argv, 1);
if (!$args || ($args[0][0] ?? '-') === '-') { array_unshift($args, 'warm'); } // an empty first argument must not warn
exit(lrgIndexCli(array_merge([$argv[0]], $args)));
