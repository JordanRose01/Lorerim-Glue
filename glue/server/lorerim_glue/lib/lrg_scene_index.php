<?php
/**
 * LoreRim Glue - OStim scene index, built on the SERVER from the scene JSON files.
 *
 * Why here: MO2's virtual file system only exists inside the game process, so there is no
 * merged Data folder to read. The WSL server can read the MO2 mods folder directly, so we
 * walk the ENABLED mods of the active profile in modlist.txt priority order. Nothing is
 * hardcoded: OARE, OStim's own scenes and any other pack are indexed the same way. Pack files
 * are only ever read, never modified.
 *
 * The index is the PRIMARY source for "what can happen next" (labels, tiers, routes).
 * The game's live OLibrary.GetScenesInRange() list is used as the routability oracle.
 *
 * v0.2 (PROTOCOL.md 7.1):
 *  - lrgSceneIndex() NEVER builds inside a web request. It serves the cached file (even when stale) or the
 *    empty index. Builds happen in lrgIndexWarm(): from the CLI (`php lrg_scene_index.php warm [--force]`,
 *    tools/warm_index.php, deploy_server.ps1) or detached from lrgIndexMaybeRebuildAsync(), which the fast
 *    lrg_npcstate path calls. No 24 h expiry: only a changed signature (modlist.txt, scene folders,
 *    scene_index config, index format) makes the index stale.
 *  - content filter: scene_index.content_filter = open (default) | standard. The taste lists of the config
 *    only apply under "standard". The HARD list below is code, applies always and cannot be configured away.
 *  - furniture: lrgPickStart(), lrgFurnitureOptions(); every search respects OStim's furniture supertypes.
 */

require_once __DIR__ . '/lrg_core.php';

const LRG_TIERS = ['neutral' => 0, 'affection' => 1, 'kissing' => 2, 'sensual' => 3, 'sexual' => 4];
const LRG_INDEX_VERSION = 4; // bump when the digest format changes so cached indexes rebuild ([0.3]: per-scene "roles")
/** Fallback furniture supertypes (OStim 7.5.1 "furniture types/*.json"); the build reads the real files. */
const LRG_FURN_SUPER = ['bed' => 'none', 'bedroll' => 'bed', 'singlebed' => 'bed', 'doublebed' => 'bed', 'bench' => 'chair',
    'alchemytable' => 'table', 'enchantingtable' => 'table', 'tableleanmarker' => 'table', 'tableleanmarkerbbls' => 'tableleanmarker',
    'wardrobe' => 'wall', 'wardrobethick' => 'wardrobe', 'wardrobethin' => 'wardrobe'];

/**
 * HARD exclusions - the consent model and the adults-only rail, not a matter of taste. No config key can
 * switch them off. A scene is dropped when a scene tag, an actor tag, an action name or a word of its id /
 * name equals one of LRG_HARD_WORDS, when its id / name / tags / actions match one of LRG_HARD_GLOBS, when
 * an actor slot is not a humanoid ("type" other than npc = creature scene), or when a sensual / sexual
 * scene has an incapacitated participant (LRG_HARD_INCAPACITATED).
 */
const LRG_HARD_WORDS = ['forced', 'force', 'rape', 'raped', 'raping', 'rapist', 'noncon', 'nonconsensual', 'nonconsent', 'aggressive', 'aggressor', 'aggression',
    'victim', 'unconscious', 'passedout', 'drugged', 'abuse', 'abused', 'slave', 'creature', 'creatures', 'bestiality', 'beastiality', 'zoophilia', 'animal',
    'necro', 'necrophilia', 'corpse', 'dead', 'gore', 'guro', 'snuff', 'child', 'children', 'kid', 'kids', 'loli', 'shota', 'underage', 'minor', 'teen', 'teens', 'young',
    // the vocabulary non-consent packs actually use: with content_filter=open this list is the ONLY net
    'dubcon', 'dubious', 'coerced', 'coercion', 'struggle', 'struggling', 'resist', 'resisting', 'reluctant',
    'captive', 'prisoner', 'blackmail', 'defeated', 'helpless', 'lolita', 'schoolgirl'];
const LRG_HARD_GLOBS = ['*forced*', '*noncon*', '*nonconsen*', '*necro*', '*bestial*', '*beastial*', '*creature*', '*unconscious*', '*victim*', '*aggressive*', '*underage*',
    '*dubcon*', '*struggl*', '*resist*', '*captive*', '*defeat*', '*blackmail*'];
const LRG_HARD_INCAPACITATED = ['sleeping', 'unconscious', 'drowsy', 'passedout', 'asleep'];
/** Body requirements every humanoid can meet (OStim "actor properties"); any other requirement is "special". */
const LRG_COMMON_REQUIREMENTS = ['anus', 'foot', 'hand', 'mouth', 'nipple', 'penis', 'testicles', 'vagina', 'breast'];

/** In-code defaults of the furniture wording (config: scene_index.furniture_labels / furniture_words). */
const LRG_FURN_LABELS = ['bed' => 'the bed', 'doublebed' => 'the bed', 'singlebed' => 'the bed', 'bedroll' => 'the bedroll', 'chair' => 'the chair', 'bench' => 'the bench',
    'table' => 'the table', 'alchemytable' => 'the alchemy table', 'enchantingtable' => 'the enchanting table', 'tableleanmarker' => 'the table', 'tableleanmarkerbbls' => 'the table',
    'shelf' => 'the shelf', 'wall' => 'the wall', 'wardrobe' => 'the wardrobe', 'wardrobethick' => 'the wardrobe', 'wardrobethin' => 'the wardrobe', 'cookingpot' => 'the cooking pot'];
const LRG_FURN_WORDS = ['bed' => ['bed', 'mattress', 'cot', 'sheets'], 'bedroll' => ['bedroll', 'bed roll', 'furs', 'sleeping roll'], 'chair' => ['chair', 'seat', 'stool', 'throne'],
    'bench' => ['bench'], 'table' => ['table', 'desk'], 'shelf' => ['shelf', 'counter', 'cupboard'], 'wall' => ['wall'], 'wardrobe' => ['wardrobe', 'closet', 'dresser'],
    'cookingpot' => ['cooking pot', 'cooking spit', 'pot', 'hearth']];

// ---------------------------------------------------------------- files, status, warm-up
function lrgIndexPath(): string { return LRG_DIR . '/data/scene_index.json'; }
function lrgIndexMetaPath(): string { return LRG_DIR . '/data/scene_index.meta.json'; }

function lrgIndexEmpty(string $error = 'not built'): array
{
    return ['v' => 0, 'scenes' => [], 'actions' => [], 'dirs' => [], 'furn' => [], 'sig' => '', 'built' => 0, 'filter' => '', 'error' => $error];
}

/**
 * The index for this request. Outside the CLI it NEVER builds: it serves the cached file (even when stale)
 * or the empty index, and at most leaves a "rebuild wanted" flag for lrgIndexMaybeRebuildAsync(). On the
 * CLI (tests, tools) a missing or outdated-format cache is built on the spot.
 */
function lrgSceneIndex(): array
{
    if (isset($GLOBALS['LRG_INDEX_CACHE'])) { return $GLOBALS['LRG_INDEX_CACHE']; }
    $GLOBALS['LRG_INDEX_DERIVED'] = [];
    $idx = lrgIndexLoad();
    if ($idx === null || (int) ($idx['v'] ?? 0) !== LRG_INDEX_VERSION) {
        if (PHP_SAPI === 'cli' && empty($GLOBALS['LRG_INDEX_NO_BUILD'])) {
            $r = lrgIndexWarm(true);
            if (isset($GLOBALS['LRG_INDEX_CACHE'])) { return $GLOBALS['LRG_INDEX_CACHE']; }
            $idx = lrgIndexLoad() ?? $idx;
            if ($idx === null && !empty($r['skipped'])) {
                // another process held the lock: do NOT memoise an empty index for the whole process
                // (parallel test runs share one data/ directory) - the next call retries
                return lrgIndexApplyFilter(lrgIndexEmpty());
            }
        } else {
            // [0.3 / G6] "@" does not suppress the warning: CHIM installs its own error handler, and
            // these two lines produced 9 warnings each in a 10-minute window. Guard instead of silencing.
            if (!is_dir(LRG_DIR . '/data')) { @mkdir(LRG_DIR . '/data', 0770, true); }
            @touch(LRG_DIR . '/data/.index_wanted'); // cheap flag; the next fast state message starts the rebuild
        }
    }
    return $GLOBALS['LRG_INDEX_CACHE'] = lrgIndexApplyFilter($idx ?? lrgIndexEmpty());
}

/** Decoded cache file, or null. Any format from v2 on is servable (newer fields are read with defaults). */
function lrgIndexLoad(): ?array
{
    $f = lrgIndexPath();
    if (!is_file($f)) { return null; }
    $idx = json_decode((string) @file_get_contents($f), true);
    return (is_array($idx) && !empty($idx['scenes']) && (int) ($idx['v'] ?? 0) >= 2) ? $idx : null;
}

/** open | standard. A missing key is "open" (the owner's default); an unknown value reads as the stricter "standard". */
function lrgContentFilter(): string
{
    $f = strtolower(trim((string) ($GLOBALS['LRG_TEST_CONTENT_FILTER'] ?? lrgConfig()['scene_index']['content_filter'] ?? 'open')));
    return ($f === 'open' || $f === '') ? 'open' : 'standard'; // standard = grounded = the original taste lists
}

/** Drop the per-request copy (tests, and after a warm-up in the same process). The next lrgSceneIndex() reads the file again. */
function lrgIndexForget(): void
{
    unset($GLOBALS['LRG_INDEX_CACHE']);
    $GLOBALS['LRG_INDEX_DERIVED'] = [];
}

/** "excluded" is derived from the stored flags, so switching the filter level needs no rebuild. */
function lrgIndexApplyFilter(array $idx): array
{
    $filter = lrgContentFilter();
    if (($idx['filter'] ?? '') === $filter || (int) ($idx['v'] ?? 0) < 3) { return $idx; }
    foreach ($idx['scenes'] as &$s) { $s['excluded'] = ($s['hard'] ?? '') !== '' || ($filter === 'standard' && !empty($s['taste'])); }
    unset($s);
    $idx['filter'] = $filter;
    return $idx;
}

function lrgIndexMeta(): ?array
{
    $m = is_file(lrgIndexMetaPath()) ? json_decode((string) @file_get_contents(lrgIndexMetaPath()), true) : null;
    return is_array($m) ? $m : null;
}

/** True while another process holds the build lock. */
function lrgIndexBuilding(): bool
{
    $h = @fopen(LRG_DIR . '/data/.index_lock', 'c');
    if (!$h) { return false; }
    $free = flock($h, LOCK_EX | LOCK_NB);
    if ($free) { flock($h, LOCK_UN); }
    fclose($h);
    return !$free;
}

/** Cheap (two small local files, no Windows-drive access, never decodes the index). 'stale' is the last known verdict. */
function lrgIndexStatus(): array
{
    $m = lrgIndexMeta();
    $dir = LRG_DIR . '/data';
    $stale = !$m || (int) ($m['v'] ?? 0) !== LRG_INDEX_VERSION || !is_file(lrgIndexPath()) || is_file("$dir/.index_wanted")
        || trim((string) @file_get_contents("$dir/.index_checked")) === 'stale';
    $error = (string) ($m['error'] ?? ($m ? '' : 'not built'));
    if ($error === '' && is_dir($dir) && !is_writable($dir)) { $error = "$dir is not writable for the web server: the index cannot be refreshed"; }
    return ['built' => (int) ($m['built'] ?? 0), 'scenes' => (int) ($m['scenes'] ?? 0), 'stale' => $stale, 'building' => lrgIndexBuilding(), 'error' => $error];
}

/**
 * Synchronous build under data/.index_lock. Skips when the cached index is current (unless $force) or when
 * another process is already building. Never call this inside an LLM request.
 */
function lrgIndexWarm(bool $force = false): array
{
    $t0 = microtime(true);
    // apache runs as www-data; the documented recovery command ("warm --force") is run as root with
    // umask 0022, which would leave the markers group-read-only and silently break every later write
    umask(0002);
    $dir = LRG_DIR . '/data';
    if (!is_dir($dir)) { @mkdir($dir, 0770, true); }
    $lock = @fopen("$dir/.index_lock", 'c');
    if (!$lock) {
        lrgLog('scene index: cannot open data/.index_lock - not building (nothing would be synchronised)');
        return ['ok' => false, 'scenes' => 0, 'seconds' => 0.0, 'skipped' => true];
    }
    if (!flock($lock, LOCK_EX | LOCK_NB)) {
        fclose($lock);
        return ['ok' => true, 'scenes' => (int) (lrgIndexMeta()['scenes'] ?? 0), 'seconds' => 0.0, 'skipped' => true];
    }
    try {
        $m = lrgIndexMeta();
        if (!$force && $m && (int) ($m['v'] ?? 0) === LRG_INDEX_VERSION && ($m['error'] ?? '') === '' && is_file(lrgIndexPath())
            && ($m['sig'] ?? '') !== '' && $m['sig'] === lrgIndexSignature((array) ($m['dirs'] ?? []))) {
            @file_put_contents("$dir/.index_checked", 'fresh');
            if (is_file("$dir/.index_wanted")) { @unlink("$dir/.index_wanted"); }
            return ['ok' => true, 'scenes' => (int) ($m['scenes'] ?? 0), 'seconds' => round(microtime(true) - $t0, 3), 'skipped' => true];
        }
        try {
            $idx = lrgBuildSceneIndex();
        } catch (Throwable $e) {
            // without a failure record the 10-minute back-off in lrgIndexMaybeRebuildAsync never engages
            // and background builds would loop unbounded
            $old = lrgIndexMeta() ?? [];
            @file_put_contents(lrgIndexMetaPath(), json_encode(['error' => 'build failed: ' . $e->getMessage(), 'attempt' => lrgNow()]
                + $old + ['v' => 0, 'built' => 0, 'scenes' => 0, 'sig' => '', 'dirs' => []]), LOCK_EX);
            lrgLog('scene index: build failed - ' . $e->getMessage());
            $idx = null;
        }
        if ($idx !== null) {
            unset($GLOBALS['LRG_INDEX_CACHE']);
            $GLOBALS['LRG_INDEX_DERIVED'] = [];
            $GLOBALS['LRG_INDEX_CACHE'] = lrgIndexApplyFilter($idx);
        }
        return ['ok' => $idx !== null, 'scenes' => $idx ? count($idx['scenes']) : 0, 'seconds' => round(microtime(true) - $t0, 3), 'skipped' => false];
    } finally {
        if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
    }
}

/**
 * Called from the FAST lrg_npcstate path only. At most one signature check per 60 s (a few stat() calls on
 * the Windows drive), never decodes the index. Missing / stale -> start the detached CLI warm-up; when PHP may
 * not start processes it builds synchronously (still outside any LLM request). After a failed build it waits
 * 10 minutes before trying again. True = a rebuild was started.
 */
function lrgIndexMaybeRebuildAsync(): bool
{
    $dir = LRG_DIR . '/data';
    $stamp = "$dir/.index_checked";
    $wanted = is_file("$dir/.index_wanted");
    $now = lrgNow(); // PROTOCOL 7.2: every clock read of the glue goes through the seam
    if (!$wanted && $now - (int) @filemtime($stamp) < 60) { return false; }
    if (!is_dir($dir)) { @mkdir($dir, 0770, true); }
    if (!is_writable($dir)) { return false; } // nothing could be cached or throttled: never start a build per snapshot (lrgIndexStatus() reports it)
    $m = lrgIndexMeta();
    if ($m && ($m['error'] ?? '') !== '' && $now - (int) ($m['attempt'] ?? 0) < 600) { @touch($stamp); return false; }
    $stale = !$m || (int) ($m['v'] ?? 0) !== LRG_INDEX_VERSION || !is_file(lrgIndexPath()) || ($m['sig'] ?? '') === ''
        || $m['sig'] !== lrgIndexSignature((array) ($m['dirs'] ?? []));
    @file_put_contents($stamp, $stale ? 'stale' : 'fresh');
    if (!$stale) { if (is_file("$dir/.index_wanted")) { @unlink("$dir/.index_wanted"); } return false; }
    if (lrgIndexBuilding()) { return false; }
    $php = is_executable(PHP_BINDIR . '/php') ? PHP_BINDIR . '/php' : 'php';
    // under php-fpm / apache the child inherits the request's process group, and nohup only covers
    // SIGHUP: setsid puts the build in its own session so it cannot be reaped with the request
    $setsid = is_executable('/usr/bin/setsid') ? '/usr/bin/setsid ' : (is_executable('/bin/setsid') ? '/bin/setsid ' : '');
    $cmd = $setsid . 'nohup ' . escapeshellarg($php) . ' ' . escapeshellarg(__FILE__) . ' warm > /dev/null 2>&1 < /dev/null &';
    if (!empty($GLOBALS['LRG_TEST_NO_SPAWN'])) { $GLOBALS['LRG_TEST_SPAWNED'] = $cmd; return true; } // offline tests: decide, do not start a process
    $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
    if (DIRECTORY_SEPARATOR === '/' && function_exists('exec') && !in_array('exec', $disabled, true)) {
        @exec($cmd);
        lrgLog('scene index: stale or missing - detached rebuild started');
        return true;
    }
    if (DIRECTORY_SEPARATOR === '/' && function_exists('shell_exec') && !in_array('shell_exec', $disabled, true)) {
        @shell_exec($cmd);
        lrgLog('scene index: stale or missing - detached rebuild started');
        return true;
    }
    lrgLog('scene index: stale or missing - this PHP cannot start processes, building on the state message');
    lrgIndexWarm();
    return true;
}

/** `php lrg_scene_index.php warm [--force] | status`. Returns the process exit code. */
function lrgIndexCli(array $argv): int
{
    umask(0002); // a root-run "warm --force" must not leave markers apache cannot rewrite
    $cmd = strtolower((string) ($argv[1] ?? 'status'));
    if ($cmd === 'warm') {
        $r = lrgIndexWarm(in_array('--force', $argv, true));
        $st = lrgIndexStatus();
        printf("scene index: %s, %d scenes, %.1fs%s\n", !$r['ok'] ? 'FAILED' : ($r['skipped'] ? 'up to date' : 'rebuilt'), $r['scenes'], $r['seconds'], $st['error'] !== '' ? ' - ' . $st['error'] : '');
        return $r['ok'] ? 0 : 1;
    }
    echo json_encode(lrgIndexStatus() + ['filter' => lrgContentFilter(), 'path' => lrgIndexPath()]) . "\n";
    return 0;
}

/** Cheap change detector: modlist.txt + every scene folder found last time + the scene_index config + the format. */
function lrgIndexSignature(array $dirs): string
{
    $mo2 = lrgConfig()['mo2'] ?? [];
    $modlist = rtrim((string) ($mo2['root'] ?? ''), '/') . '/profiles/' . ($mo2['profile'] ?? 'Ultra') . '/modlist.txt';
    if (!is_file($modlist)) { return ''; }
    $parts = [filemtime($modlist)];
    foreach ($dirs as $d) { $parts[] = @filemtime($d) ?: 0; }
    // only the keys that are baked into the digests (tiers, taste flags, live-only flags); everything else is read at query time
    $baked = ['excluded_tags', 'excluded_actions', 'excluded_id_patterns', 'kissing_patterns', 'sensual_patterns', 'affection_patterns', 'neutral_actions',
        'sensual_actor_tags', 'sensual_actor_tags_strict', 'live_only_id_patterns'];
    $cfg = array_intersect_key(lrgConfig()['scene_index'] ?? [], array_flip($baked));
    ksort($cfg);
    // The verdicts are frozen into the cache by THIS FILE's rules (tiers, the code hard list, taste flags,
    // requirements, the search vocabulary). Without the library in the digest a rail fix does not survive a
    // deploy: the warm-up would report "up to date" and the server would keep the old verdicts.
    $code = md5(json_encode([LRG_HARD_WORDS, LRG_HARD_GLOBS, LRG_HARD_INCAPACITATED, LRG_COMMON_REQUIREMENTS]) . '|' . (int) @filemtime(__FILE__));
    return md5(implode('|', $parts) . '|' . json_encode($cfg) . '|' . $modlist . '|v' . LRG_INDEX_VERSION . '|' . $code);
}

/** json_decode that tolerates a UTF-8 BOM, // and block comments and trailing commas (hand-edited pack files). */
function lrgJsonDecodeLoose(string $txt): ?array
{
    if (strncmp($txt, "\xEF\xBB\xBF", 3) === 0) { $txt = substr($txt, 3); }
    $j = json_decode($txt, true);
    if (is_array($j)) { return $j; }
    // strip comments outside of strings, then trailing commas
    $clean = preg_replace('~("(?:\\\\.|[^"\\\\])*")|//[^\r\n]*|/\*.*?\*/~s', '$1', $txt);
    $clean = preg_replace('~,(\s*[}\]])~', '$1', (string) $clean);
    $j = json_decode((string) $clean, true);
    return is_array($j) ? $j : null;
}

/** Files of one folder by suffix, case-insensitive. Not glob(): MO2 mod names often contain [ ] which glob treats as a pattern. */
function lrgListFiles(string $dir, string $suffix): array
{
    $out = [];
    if (!is_dir($dir)) { return $out; }
    $suffix = strtolower($suffix);
    foreach (@scandir($dir) ?: [] as $f) {
        if (substr(strtolower($f), -strlen($suffix)) === $suffix && is_file("$dir/$f")) { $out[] = "$dir/$f"; }
    }
    return $out;
}

// ---------------------------------------------------------------- build
function lrgBuildSceneIndex(): ?array
{
    $t0 = microtime(true);
    $mo2 = lrgConfig()['mo2'] ?? [];
    $root = rtrim((string) ($mo2['root'] ?? ''), '/');
    $modlist = $root . '/profiles/' . ($mo2['profile'] ?? 'Ultra') . '/modlist.txt';
    if (!is_dir(LRG_DIR . '/data')) { @mkdir(LRG_DIR . '/data', 0770, true); }
    if (!is_file($modlist)) {
        $err = "cannot read $modlist - set mo2.root / mo2.profile in lrg_config.json";
        lrgLog("scene index: $err. Falling back to live game data only.");
        $old = lrgIndexMeta() ?? [];
        @file_put_contents(lrgIndexMetaPath(), json_encode(['error' => $err, 'attempt' => lrgNow()] + $old + ['v' => 0, 'built' => 0, 'scenes' => 0, 'sig' => '', 'dirs' => []]), LOCK_EX);
        return null;
    }
    // modlist.txt lists the HIGHEST priority first; "+" = enabled
    $mods = [];
    foreach (file($modlist, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = rtrim($line, "\r\n");
        if ($line !== '' && $line[0] === '+') { $mods[] = substr($line, 1); }
    }
    $dirs = [];
    $actionTags = [];
    $actionReq = [];  // action => ['actor'|'target'|'performer' => [requirement, ...]]
    $aliasOf = [];    // alias => canonical action name
    $furnSuper = [];
    $scenes = [];
    $names = []; // translation key (lowercase, with $) => English text
    $tScan = 0.0; $packs = 0;
    foreach ($mods as $mod) {
        if (substr($mod, -10) === '_separator') { continue; }
        $base = "$root/mods/$mod/SKSE/Plugins/OStim";
        $ts = microtime(true);
        $has = is_dir($base);
        $tScan += microtime(true) - $ts;
        if (!$has) { continue; }
        // scene names are usually $keys resolved through Interface/translations (UTF-16LE, key<TAB>text)
        foreach (lrgListFiles("$root/mods/$mod/Interface/translations", '_english.txt') ?: lrgListFiles("$root/mods/$mod/Interface/Translations", '_english.txt') as $tf) {
            $raw = (string) file_get_contents($tf);
            $txt = @mb_convert_encoding($raw, 'UTF-8', 'UTF-16LE');
            foreach (preg_split('/\R/u', (string) $txt) as $ln) {
                $p = strpos($ln, "\t");
                if ($p > 0) { $names[strtolower(trim(substr($ln, 0, $p), "\xEF\xBB\xBF \u{FEFF}"))] ??= trim(substr($ln, $p + 1)); }
            }
        }
        foreach (lrgListFiles("$base/furniture types", '.json') as $f) {
            $name = strtolower(basename($f, '.json'));
            $j = lrgJsonDecodeLoose((string) file_get_contents($f)) ?? [];
            $furnSuper[$name] ??= strtolower((string) ($j['supertype'] ?? ''));
        }
        foreach (lrgListFiles("$base/actions", '.json') as $f) {
            $name = strtolower(basename($f, '.json'));
            if (isset($actionTags[$name])) { continue; } // higher priority already won
            $j = lrgJsonDecodeLoose((string) file_get_contents($f)) ?? [];
            $actionTags[$name] = array_map('strtolower', (array) ($j['tags'] ?? []));
            $actionReq[$name] = [];
            foreach (['actor', 'target', 'performer'] as $role) {
                $actionReq[$name][$role] = array_map('strtolower', (array) ($j[$role]['requirements'] ?? []));
            }
            foreach ((array) ($j['aliases'] ?? []) as $alias) {
                $alias = strtolower((string) $alias);
                $actionTags[$alias] ??= $actionTags[$name];
                $aliasOf[$alias] ??= $name;
            }
        }
        // what the signature watches per OStim mod: MO2 rewrites meta.ini on every (re)install / update, the two
        // folders change when files are added or removed. (One stat on the Windows drive costs ~4 ms through WSL,
        // so watching every scene sub-folder - 104 of them - would cost half a second per check.)
        $dirs["$root/mods/$mod/meta.ini"] = true;
        $dirs[$base] = true;
        if (!is_dir("$base/scenes")) { continue; }
        $dirs["$base/scenes"] = true;
        $packs++;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$base/scenes", FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (strtolower($file->getExtension()) !== 'json') { continue; }
            $id = $file->getBasename('.json');
            $key = strtolower($id);
            if (isset($scenes[$key])) { continue; }
            $j = lrgJsonDecodeLoose((string) file_get_contents($file->getPathname()));
            if (!is_array($j)) { lrgLog("scene index: unreadable JSON skipped: " . $file->getPathname()); continue; }
            $scenes[$key] = lrgDigestScene($id, $j, $mod);
        }
    }
    $dirs = array_slice(array_keys($dirs), 0, 300);
    // second pass: origin-style edges (X -> this) and tiers/exclusions
    foreach ($scenes as $key => $s) {
        foreach ($s['_origins'] as $o) {
            $ok = strtolower($o);
            if (isset($scenes[$ok]) && !in_array($s['id'], $scenes[$ok]['to'], true)) { $scenes[$ok]['to'][] = $s['id']; }
        }
    }
    $missing = 0;
    $liveOnly = (array) (lrgConfig()['scene_index']['live_only_id_patterns'] ?? ['*vampire*', '*drained*', '*devour*']);
    foreach ($scenes as $key => &$s) {
        // hard body requirements per actor slot, from the action definitions (what OStim's ActorCondition checks)
        $need = array_fill(0, $s['actors'], []);
        foreach ($s['_acts'] as $a) {
            $def = $actionReq[$aliasOf[$a[0]] ?? $a[0]] ?? [];
            foreach (['actor' => $a[1], 'target' => $a[2], 'performer' => $a[3]] as $role => $slot) {
                foreach (($def[$role] ?? []) as $r) { if (isset($need[$slot])) { $need[$slot][$r] = true; } }
            }
        }
        $special = [];
        foreach ($need as $i => $r) {
            foreach ($s['_slotreq'][$i] ?? [] as $x) { $r[$x] = true; }
            foreach (array_diff(array_keys($r), LRG_COMMON_REQUIREMENTS) as $x) { $special[$x] = true; } // vampire, tail, inwater ...: the snapshot cannot prove them
            $m = isset($r['penis']) || isset($r['testicles']);
            $f = isset($r['vagina']) || isset($r['breast']);
            $need[$i] = ($m && $f) ? 'none' : ($m ? 'male' : ($f ? 'female' : 'any'));
        }
        if (lrgAnyGlob($liveOnly, [$s['id'], $s['name']])) { $special['live'] = true; }
        $s['need'] = $need;
        $s['special'] = array_keys($special);
        $s['actions'] = array_values(array_unique(array_map(fn($a) => $aliasOf[$a] ?? $a, $s['actions']))); // canonical names
        // [0.3 / G5.1] WHO does WHAT to WHOM, kept for the act layer: [canonical action, actorSlot, targetSlot],
        // de-duplicated. Until 0.2 this was computed and thrown away, which is why "she goes down on you" and
        // "you go down on her" were the same offer. ~607 scenes x a handful of triples.
        $roles = [];
        foreach ($s['_acts'] as $a) {
            $name = $aliasOf[$a[0]] ?? $a[0];
            $roles[$name . '|' . $a[1] . '|' . $a[2]] = [$name, (int) $a[1], (int) $a[2]];
        }
        $s['roles'] = array_values($roles);
        unset($s['_origins'], $s['_acts'], $s['_slotreq']);
        if ($s['name'] !== '' && $s['name'][0] === '$') { $s['name'] = $names[strtolower($s['name'])] ?? $s['name']; }
        $s['tier'] = lrgSceneTier($s, $actionTags);
        $s['hard'] = lrgSceneHardExcluded($s);
        $s['taste'] = lrgSceneTasteExcluded($s);
        $s['excluded'] = $s['hard'] !== '' || (lrgContentFilter() === 'standard' && $s['taste']);
        foreach ($s['to'] as $dest) { if (!isset($scenes[strtolower($dest)])) { $missing++; } }
    }
    unset($s);
    // per-action facts for the text search: tier of the action alone, and its vocabulary tags (oral, fellatio, intercourse ...)
    $actions = [];
    foreach ($actionTags as $a => $tags) {
        if (isset($aliasOf[$a])) { continue; }
        $actions[$a] = ['tier' => LRG_TIERS[lrgSceneTier(['actions' => [$a], 'tags' => [], 'id' => '', 'name' => '', 'actors' => 1, 'actor_tags' => []], $actionTags)], 'tags' => array_values($tags)];
    }
    $seconds = round(microtime(true) - $t0, 2);
    $idx = ['v' => LRG_INDEX_VERSION, 'scenes' => $scenes, 'actions' => $actions, 'dirs' => $dirs, 'furn' => array_filter($furnSuper), 'built' => lrgNow(),
        'sig' => lrgIndexSignature($dirs), 'filter' => lrgContentFilter(), 'seconds' => $seconds, 'error' => ''];
    // atomic: web requests read this file at any moment
    $tmp = lrgIndexPath() . '.' . getmypid() . '.tmp';
    if (@file_put_contents($tmp, json_encode($idx), LOCK_EX) === false || !@rename($tmp, lrgIndexPath())) {
        @unlink($tmp);
        lrgLog('scene index: cannot write ' . lrgIndexPath() . ' (permissions?) - serving from memory only');
    }
    @file_put_contents(lrgIndexMetaPath(), json_encode(['v' => LRG_INDEX_VERSION, 'built' => $idx['built'], 'attempt' => $idx['built'], 'scenes' => count($scenes), 'sig' => $idx['sig'],
        'dirs' => $dirs, 'seconds' => $seconds, 'scan_seconds' => round($tScan, 2), 'mods' => count($mods), 'packs' => $packs, 'error' => '']), LOCK_EX);
    @file_put_contents(LRG_DIR . '/data/.index_checked', 'fresh');
    if (is_file(LRG_DIR . '/data/.index_wanted')) { @unlink(LRG_DIR . '/data/.index_wanted'); }
    $hard = array_filter($scenes, fn($s) => $s['hard'] !== '');
    lrgLog(sprintf('scene index rebuilt: %d scenes from %d pack folder(s), %d dangling edge(s), %.1fs (%.1fs of it looking through %d enabled mods), filter=%s, taste-listed=%d, always excluded=%d%s',
        count($scenes), $packs, $missing, $seconds, $tScan, count($mods), $idx['filter'], count(array_filter($scenes, fn($s) => $s['taste'])), count($hard),
        $hard ? ' (' . implode(', ', array_map(fn($s) => $s['id'] . ': ' . $s['hard'], array_slice($hard, 0, 10))) . (count($hard) > 10 ? ', ...' : '') . ')' : ''));
    return $idx;
}

function lrgDigestScene(string $id, array $j, string $mod): array
{
    $to = []; $origins = [];
    foreach ((array) ($j['navigations'] ?? []) as $nav) {
        if (!empty($nav['destination'])) { $to[] = (string) $nav['destination']; }
        if (!empty($nav['origin'])) { $origins[] = (string) $nav['origin']; }
    }
    $isTransition = !empty($j['destination']);
    if ($isTransition) { $to = [(string) $j['destination']]; } // a transition plays once, then moves on
    $actions = []; $acts = [];
    foreach ((array) ($j['actions'] ?? []) as $a) {
        if (empty($a['type'])) { continue; }
        $type = strtolower((string) $a['type']);
        $actions[] = $type;
        $actor = (int) ($a['actor'] ?? 0);
        $acts[] = [$type, $actor, (int) ($a['target'] ?? $actor), (int) ($a['performer'] ?? $actor)];
    }
    $actorTags = []; $sex = []; $slotReq = []; $types = [];
    foreach (array_values((array) ($j['actors'] ?? [])) as $i => $a) {
        foreach ((array) ($a['tags'] ?? []) as $t) { $actorTags[] = strtolower((string) $t); }
        $v = strtolower((string) ($a['intendedSex'] ?? 'any'));
        $sex[$i] = ($v === 'male' || $v === 'female') ? $v : 'any'; // OStim: anything else = any
        $slotReq[$i] = array_map('strtolower', (array) ($a['requirements'] ?? []));
        $t = strtolower(trim((string) ($a['type'] ?? 'npc')));
        if ($t !== '' && $t !== 'npc') { $types[] = $t; }
    }
    return [
        'id' => $id,
        'name' => (string) ($j['name'] ?? $id),
        'pack' => (string) ($j['modPack'] ?? $j['modpack'] ?? $mod),
        'actors' => count((array) ($j['actors'] ?? [])),
        'sex' => $sex,
        'types' => array_values(array_unique($types)), // non-humanoid actor types (creature scenes) - always excluded
        'furniture' => strtolower((string) ($j['furniture'] ?? '')),
        'transition' => $isTransition,
        'tags' => array_values(array_unique(array_map('strtolower', (array) ($j['tags'] ?? [])))),
        'actor_tags' => array_values(array_unique($actorTags)),
        'actions' => array_values(array_unique($actions)),
        'speeds' => count((array) ($j['speeds'] ?? [])),
        'to' => array_values(array_unique($to)),
        '_origins' => $origins,
        '_acts' => $acts,
        '_slotreq' => $slotReq,
    ];
}

/** Content tier from action-definition tags first, then names / scene tags / id tokens, then posture. */
function lrgSceneTier(array $s, array $actionTags): string
{
    $c = lrgConfig()['scene_index'] ?? [];
    $tier = 'neutral';
    $bump = function (string $t) use (&$tier) { if (LRG_TIERS[$t] > LRG_TIERS[$tier]) { $tier = $t; } };
    foreach ($s['actions'] as $a) {
        if (lrgAnyGlob($c['neutral_actions'] ?? [], [$a])) { continue; }
        $tags = $actionTags[$a] ?? [];
        if (in_array('sexual', $tags, true)) { $bump('sexual'); continue; }
        if (lrgAnyGlob($c['sensual_patterns'] ?? [], [$a])) { $bump('sensual'); continue; }
        if (lrgAnyGlob($c['kissing_patterns'] ?? [], [$a])) { $bump('kissing'); continue; }
        if (lrgAnyGlob($c['affection_patterns'] ?? [], [$a])) { $bump('affection'); continue; }
        if (in_array('sensual', $tags, true) || in_array('seductive', $tags, true)) { $bump('sensual'); continue; }
        if (in_array('romantic', $tags, true)) { $bump('affection'); continue; }
        $bump('sexual'); // unknown action with no gentle evidence: be conservative
    }
    if ($tier === 'neutral') { // many romance scenes declare no actions at all
        $hay = array_merge($s['tags'], [strtolower($s['id']), strtolower($s['name'])]);
        $wrap = fn(array $ps) => array_map(fn($p) => '*' . trim($p, '*') . '*', $ps);
        if (lrgAnyGlob($wrap($c['kissing_patterns'] ?? []), $hay)) { $tier = 'kissing'; }
        elseif (lrgAnyGlob($wrap($c['affection_patterns'] ?? []), $hay)) { $tier = 'affection'; }
    }
    // positional idles of the explicit branches (all fours, kneeling in front, mounted ...) declare no
    // actions either; they are staging for what follows and must not pass as a neutral/gentle option
    if ($tier === 'neutral' && $s['actors'] > 1 && array_intersect($s['actor_tags'], array_map('strtolower', (array) ($c['sensual_actor_tags'] ?? [])))) {
        $tier = 'sensual';
    }
    // unmistakable staging postures are never gentle, whatever harmless action the scene also declares
    if (LRG_TIERS[$tier] < LRG_TIERS['sensual'] && $s['actors'] > 1
        && array_intersect($s['actor_tags'], array_map('strtolower', (array) ($c['sensual_actor_tags_strict'] ?? ['allfours', 'bendover', 'spreadlegs'])))) {
        $tier = 'sensual';
    }
    return $tier;
}

/** Words of an id / display name: CamelCase and digits split, lowercased. */
function lrgIdWords(string $text): array
{
    $t = preg_replace('/(?<=[a-z])(?=[A-Z])|(?<=[A-Z])(?=[A-Z][a-z])|(?<=\d)(?=[A-Za-z])/', ' ', $text);
    return array_values(array_filter(preg_split('/[^a-z]+/', strtolower((string) $t)), fn($w) => strlen($w) > 1));
}

/** The consent / adults-only rail. Returns the reason ('' = fine). Not configurable. */
function lrgSceneHardExcluded(array $s): string
{
    if (!empty($s['types'])) { return 'non-humanoid actor (' . implode(',', $s['types']) . ')'; }
    $words = array_merge($s['tags'], $s['actor_tags'], $s['actions'], lrgIdWords($s['id'] . ' ' . ($s['name'] !== '' && $s['name'][0] !== '$' ? $s['name'] : '')));
    if ($hit = array_intersect($words, LRG_HARD_WORDS)) { return 'hard word: ' . implode(',', array_unique($hit)); }
    if (lrgAnyGlob(LRG_HARD_GLOBS, array_merge($s['tags'], $s['actor_tags'], $s['actions'], [$s['id'], $s['name']]))) { return 'hard pattern'; }
    if (LRG_TIERS[$s['tier']] >= LRG_TIERS['sensual'] && ($hit = array_intersect(array_merge($s['tags'], $s['actor_tags'], $s['actions']), LRG_HARD_INCAPACITATED))) {
        return 'incapacitated participant: ' . implode(',', array_unique($hit));
    }
    return '';
}

/** The owner-adjustable taste lists; they only apply under content_filter = standard. */
function lrgSceneTasteExcluded(array $s): bool
{
    $c = lrgConfig()['scene_index'] ?? [];
    return lrgAnyGlob($c['excluded_tags'] ?? [], array_merge($s['tags'], $s['actor_tags']))
        || lrgAnyGlob($c['excluded_actions'] ?? [], $s['actions'])
        || lrgAnyGlob($c['excluded_id_patterns'] ?? [], [$s['id'], $s['name']]);
}

// ---------------------------------------------------------------- lookups and filters
function lrgScene(string $id): ?array
{
    return lrgSceneIndex()['scenes'][strtolower($id)] ?? null;
}

/** Participant sexes -> ['male'|'female'|'any', ...]; accepts words, m/f, or the game's 0 (male) / 1 (female). */
function lrgNormSexes(?array $sexes): ?array
{
    if ($sexes === null || !$sexes) { return null; }
    $out = [];
    foreach ($sexes as $v) {
        $v = strtolower(trim((string) $v));
        $out[] = in_array($v, ['0', 'male', 'm'], true) ? 'male' : (in_array($v, ['1', 'female', 'f'], true) ? 'female' : 'any');
    }
    return $out;
}

/**
 * Can these participants fill the scene's actor slots? A slot is bound by the body requirements of its
 * actions (penis / testicles -> male, vagina / breast -> female) and, while scene_index.intended_sex_only is
 * true (OStim MCM "intended sex only"), by its intendedSex. Missing / "any" = wildcard. null = no filtering.
 * Deliberately conservative: OStim's own actor properties let a woman fill a "penis" slot with a strap-on;
 * the index never offers that, so it never offers something OStim then refuses.
 */
function lrgSceneSexOk(array $s, ?array $sexes): bool
{
    $sexes = lrgNormSexes($sexes);
    if ($sexes === null || !isset($s['need'])) { return true; }
    if (count($sexes) !== (int) $s['actors']) { return false; }
    $strict = (bool) (lrgConfig()['scene_index']['intended_sex_only'] ?? true);
    $have = array_count_values($sexes) + ['male' => 0, 'female' => 0, 'any' => 0];
    $want = ['male' => 0, 'female' => 0, 'any' => 0];
    foreach ($s['need'] as $i => $n) {
        if ($n === 'none') { return false; }
        if ($n === 'any' && $strict) { $n = $s['sex'][$i] ?? 'any'; }
        $want[$n]++;
    }
    $shortM = max(0, $want['male'] - $have['male']);
    $shortF = max(0, $want['female'] - $have['female']);
    return $shortM + $shortF <= $have['any']; // participants of unknown sex may fill either
}

/** Soft check: a scene CALLED "Female ..." for two men (or "Male ..." for two women) is legal for OStim but reads wrong. */
function lrgSceneNameFits(array $s, ?array $sexes): bool
{
    $sexes = lrgNormSexes($sexes);
    if ($sexes === null) { return true; }
    $w = array_flip(lrgIdWords($s['id'] . ' ' . $s['name']));
    if (isset($w['female']) && !array_intersect($sexes, ['female', 'any'])) { return false; }
    if (isset($w['male']) && !array_intersect($sexes, ['male', 'any'])) { return false; }
    return true;
}

/** Furniture chain of the thread: the type itself, then its supertypes (doublebed -> bed -> none). */
function lrgFurnitureChain(string $furniture): array
{
    $f = strtolower(trim($furniture));
    if ($f === '') { $f = 'none'; }
    $super = (lrgSceneIndex()['furn'] ?? []) ?: LRG_FURN_SUPER;
    $chain = [$f];
    while (isset($super[$f]) && $super[$f] !== '' && !in_array($super[$f], $chain, true)) { $f = $super[$f]; $chain[] = $f; }
    return $chain;
}

/** bed, singlebed, doublebed, bedroll and any pack-defined subtype of bed. */
function lrgIsBedFamily(string $furniture): bool
{
    return in_array('bed', lrgFurnitureChain($furniture), true);
}

/** OStim only navigates to scenes whose furniture type is the thread's type or one of its supertypes. null = no filtering. */
function lrgSceneFurnitureOk(array $s, ?string $furniture): bool
{
    if ($furniture === null) { return true; }
    $sf = $s['furniture'] === '' ? 'none' : $s['furniture'];
    return in_array($sf, lrgFurnitureChain($furniture), true);
}

/** Short, neutral, human-readable line for the LLM - derived from metadata only. */
function lrgDescribeScene(array $s): string
{
    $name = $s['name'];
    if ($name === '' || $name[0] === '$') {
        // no translation: make the id readable (drop pack prefix and the MF/FM/FF/MM suffix, split CamelCase)
        $id = preg_replace('/^(OStim\d*P?|OARE|OCR|[A-Za-z0-9]+_)_?/', '', $s['id']);
        $id = preg_replace('/(MF|FM|FF|MM)$/', '', $id);
        $name = trim(preg_replace('/(?<=[a-z])(?=[A-Z])|_/', ' ', $id));
    }
    // the NPC is told to speak from this label on a [say it] turn, so no internal tokens reach the prompt:
    // pack sex suffixes, left/right variants and the bookkeeping "holding*" actions are not words she can say
    $name = trim((string) preg_replace(['/\s*\[(MF|FM|FF|MM)\]/i', '/\s*\((left|right)\)/i'], '', $name));
    $bits = [];
    $pose = array_intersect($s['actor_tags'], ['standing', 'sitting', 'kneeling', 'lyingback', 'lyingfront', 'lyingside', 'allfours', 'squatting', 'bendover']);
    if ($pose) { $bits[] = implode('/', array_slice(array_values($pose), 0, 2)); }
    if ($s['furniture'] !== '' && $s['furniture'] !== 'none') { $bits[] = 'on ' . $s['furniture']; }
    $acts = array_diff($s['actions'], ['default', 'holdingbody', 'holdinghand', 'holdinghead', 'holdinghip', 'holdingarm', 'holdingleg']);
    if ($acts) { $bits[] = implode(', ', array_slice(array_values($acts), 0, 3)); }
    return $name . ($bits ? ' (' . implode('; ', $bits) . ')' : '') . ' [' . $s['tier'] . ']';
}

/**
 * Breadth-first walk over declared navigations, the way OStim routes: transitions are folded into the
 * navigation that uses them (they cost no hop and are never a target); a destination that is excluded,
 * has another actor count, or cannot be filled by these sexes / this furniture is neither offered nor
 * routed through. Scenes above $routeTier are not routed through either. Scenes with a requirement the
 * snapshot cannot prove (vampire, tail, in water) only count when OStim's live list names them.
 * Returns [lowercase id => ['scene' => digest, 'hops' => n]] for every loop scene found.
 */
function lrgSceneWalk(string $current, array $liveReachable, int $routeTier, int $maxHops, ?array $sexes = null, ?string $furniture = null): array
{
    $idx = lrgSceneIndex()['scenes'];
    $ck = strtolower($current);
    $cur = $idx[$ck] ?? null;
    if (!$cur) { return []; }
    $live = array_values(array_unique(array_map('strtolower', $liveReachable)));
    // the game caps the live list (24 ids / 700 chars): a full list is truncated and proves nothing about what is missing
    $liveAuthoritative = $live && count($live) < 24 && strlen(implode(',', $live)) < 640;
    $seen = [$ck => true];
    $frontier = [$ck];
    $found = [];
    for ($hop = 1; $hop <= $maxHops && $frontier; $hop++) {
        $next = [];
        foreach ($frontier as $k) {
            $stack = [];
            foreach (($idx[$k]['to'] ?? []) as $dest) { $stack[] = [strtolower($dest), null]; }
            while ($stack) {
                [$dk, $via] = array_pop($stack);
                if (isset($seen[$dk]) || !isset($idx[$dk])) { continue; }
                $seen[$dk] = true;
                $d = $idx[$dk];
                if ($d['excluded'] || $d['actors'] !== $cur['actors']) { continue; }
                if (!lrgSceneSexOk($d, $sexes) || !lrgSceneFurnitureOk($d, $furniture)) { continue; }
                if (!$d['transition'] && !empty($d['special']) && !in_array($dk, $live, true)) { continue; } // unprovable requirement: OStim must have named it
                if ($d['transition']) { // fold: follow it within the same hop, never offer it
                    foreach ($d['to'] as $t) { $stack[] = [strtolower($t), $via ?? $dk]; }
                    continue;
                }
                if (LRG_TIERS[$d['tier']] > $routeTier) { continue; } // stay inside the allowed tier; do not route through it either
                if ($hop === 1 && $liveAuthoritative && !in_array($dk, $live, true) && !($via !== null && in_array($via, $live, true))) {
                    continue; // OStim says no for these actors: neither offer it nor route through it
                }
                $next[] = $dk;
                $found[$dk] = ['scene' => $d, 'hops' => $hop];
            }
        }
        $frontier = $next;
    }
    return $found;
}

/**
 * Options the NPC / player can move to from $current.
 * $liveReachable = ids OStim itself says are routable for these actors (may be empty when
 * the game could not provide it; then the index alone decides).
 * $sexes = participant sexes (e.g. ['female','male'] or the game's [1, 0]); $furniture = the thread's
 * furniture type as sent by the game ("" / "none" = no furniture). null = do not filter on it.
 * Returns [key => ['id','label','tier','hops']], keys P1..Pn.
 * [0.3] $avoid = lowercase or exact scene ids already visited in this thread (G2, no circling): they are
 * pushed behind every unvisited candidate. When EVERY candidate is visited the penalty is dropped, so the
 * list is never empty because of it, and the caller passes [] whenever the PLAYER asked.
 */
function lrgSceneOptions(string $current, array $liveReachable, int $maxTier, int $max, ?array $sexes = null, ?string $furniture = null, array $avoid = []): array
{
    $cur = lrgScene($current);
    if (!$cur) { return []; }
    $avoidMap = array_flip(array_map('strtolower', $avoid));
    $found = []; $labels = []; $fresh = 0;
    foreach (lrgSceneWalk($current, $liveReachable, $maxTier, 3, $sexes, $furniture) as $w) {
        $d = $w['scene'];
        $label = lrgDescribeScene($d);
        if (isset($labels[$label])) { $label = lrgDescribeScene(['name' => ''] + $d); } // packs reuse display names: fall back to the readable id
        $labels[$label] = true;
        // nearest first; a scene named for the other sex counts as one hop further. A bare routing idle
        // (neutral: "Sitting", "Standing apart") is where the walk goes THROUGH, never a step the NPC should
        // pick on a lead tick - it would read as a wordless step backwards - so it ranks three hops further.
        $seen = isset($avoidMap[strtolower($d['id'])]);
        if (!$seen) { $fresh++; }
        $rank = $w['hops'] + ($d['tier'] === 'neutral' ? 3 : 0) + (lrgSceneNameFits($d, $sexes) ? 0 : 1) + (!empty($d['taste']) ? 1 : 0);
        $found[] = ['id' => $d['id'], 'label' => $label, 'tier' => $d['tier'], 'hops' => $w['hops'], '_rank' => $rank, '_seen' => $seen];
    }
    if ($fresh > 0) { // every candidate visited: the penalty would only empty the list
        foreach ($found as &$o) { if ($o['_seen']) { $o['_rank'] += 6; } }
        unset($o);
    }
    foreach ($found as &$o) { unset($o['_seen']); }
    unset($o);
    $curTier = LRG_TIERS[$cur['tier']];
    usort($found, fn($a, $b) => [$a['_rank'], abs(LRG_TIERS[$a['tier']] - $curTier), $a['hops']] <=> [$b['_rank'], abs(LRG_TIERS[$b['tier']] - $curTier), $b['hops']]);
    // when the ceiling is above the current scene, the next step must be on the list: up to a third of the slots go to
    // the nearest scenes of the ceiling tier, listed first (the ceiling only opens at the NPC's pace or on the player's word)
    $step = $maxTier > $curTier ? array_slice(array_values(array_filter($found, fn($o) => LRG_TIERS[$o['tier']] === $maxTier)), 0, max(1, intdiv($max, 3))) : [];
    $stepIds = array_column($step, 'id');
    $rest = array_values(array_filter($found, fn($o) => !in_array($o['id'], $stepIds, true)));
    $out = [];
    foreach (array_slice(array_merge($step, $rest), 0, $max) as $i => $o) { unset($o['_rank']); $out['P' . ($i + 1)] = $o; }
    return $out;
}

// ---------------------------------------------------------------- [0.3] the act layer (G5)
/**
 * The act families, config section "acts" merged over these in-code defaults. An act id is the stable
 * handle the LLM and the player use, replacing the per-turn P<n> indices (in playtest 6 "P1" meant three
 * different scenes in ten minutes). A directional family carries a role suffix:
 *   <family>:npc = the NPC is the one doing it   ·   <family>:you = the player is
 * "match" are OStim action-name globs (matched AFTER OStim's aliases are resolved, like every other list
 * here); "words" are spoken synonyms and come from the vocabulary of the installed packs - the same words
 * scene_index.synonyms already uses. Display names are VOCABULARY, never a line anyone says: %n = the NPC,
 * %p = the player. tier_hint only sorts; the real tier always comes from the scene.
 */
function lrgActsTable(): array
{
    static $t = null;
    if ($t !== null) { return $t; }
    // [0.3.1 / R2] Rebuilt from what is REALLY installed (research/pt7-act-coverage.md section 1.1):
    // 72 of the 112 canonical action names matched no family at all, and every glob was prefix-anchored,
    // so all nine of OCR's 3pp_* actions were invisible. Globs are now *<name>* wherever the prefix could
    // carry a pack marker. ORDER IS PRIORITY: lrgActFamily() returns the first family whose glob matches,
    // so the specific families (footjob, rimjob, fingering) come before the broad ones (kiss, grinding).
    $def = [
        // [0.3 fix] The "words" lists are what turns a spoken sentence into a HIGH-confidence act request
        // that the safety net will carry out. Words that carry no act on their own - feel, taste, mouth,
        // arms, neck, inside - made a rhetorical question ("can you feel that?", which lrgIntentPolite
        // reads as a request frame) change the position. They are gone; lrgFindSceneByText() still reaches
        // those scenes from plain words, at the ordinary confidence.
        // [0.3.1] niche=true keeps a family out of her own offers unless the player names it (D13).
        'footjob'      => ['match' => ['footjob*', '*footjob*', 'grindingfoot', 'kissingfoot', 'lickingfoot'], 'directional' => true, 'niche' => true,
            // the possessive is the name's own, never the name twice ("Hulda uses Hulda's feet") - this
            // text is injected verbatim into the LLM's context, the outro directive included
            'name_npc' => "%n's feet on %p", 'name_player' => "%p's feet on %n", 'tier_hint' => 'sexual',
            'words' => ['footjob', 'foot job', 'with your feet', 'your feet']],
        'rimjob'       => ['match' => ['anallicking', 'rimjob*', '*rimming*'], 'directional' => true, 'niche' => true,
            'name_npc' => "%n's tongue on %p's arse", 'name_player' => "%p's tongue on %n's arse", 'tier_hint' => 'sexual',
            'words' => ['rimjob', 'rim job', 'rimming']],
        'fingering'    => ['match' => ['*fingering', 'fingering', 'vulvalrubbing', 'vaginaltoying'], 'directional' => true, 'name_npc' => '%n fingers %p', 'name_player' => '%p fingers %n', 'tier_hint' => 'sensual',
            'words' => ['fingering', 'finger me', 'finger you', 'your fingers', 'my fingers']],
        // analsex / analpenetration are listed even though NO installed scene declares them: the request
        // must produce an explicit "can't do that", never a silent fall-back to vaginal (pt7 F7 / D4).
        'anal'         => ['match' => ['analsex', 'analpenetration', 'analtailsex', 'analtoying'], 'directional' => false, 'name' => 'anal sex', 'tier_hint' => 'sexual',
            'words' => ['anal', 'anal sex', 'up the ass', 'in the ass', 'asshole', 'ass hole', 'buttsex', 'butt sex', 'assfuck', 'ass fuck']],
        'nipples'      => ['match' => ['*suckingnipple*', '*lickingnipple*', 'nipplestimulation', 'nipple*'], 'directional' => true, 'name_npc' => "%n's mouth on %p's nipples", 'name_player' => "%p's mouth on %n's nipples", 'tier_hint' => 'sensual',
            'words' => ['nipple', 'nipples']],
        'handjob'      => ['match' => ['*handjob*'], 'directional' => true, 'name_npc' => '%n strokes %p by hand', 'name_player' => '%p strokes %n by hand', 'tier_hint' => 'sensual',
            'words' => ['handjob', 'hand job', 'jerk me off', 'jerk me', 'stroke me', 'your hand', 'your hands']],
        'oralpenis'    => ['match' => ['*blowjob*', '*fellatio*', '*lickingpenis*', 'suckingpenis', 'penilelicking', 'deepthroat*', '*facefuck*'], 'directional' => true, 'name_npc' => "%n's mouth on %p's cock", 'name_player' => "%p's mouth on %n's cock", 'tier_hint' => 'sexual',
            'words' => ['blowjob', 'blow job', 'bj', 'fellatio', 'deepthroat', 'deep throat', 'suck my cock', 'suck my dick', 'suck me off']],
        'oralvulva'    => ['match' => ['*cunnilingus*', 'vulvaleating', 'vulvallicking', 'lickingvulva'], 'directional' => true, 'name_npc' => "%n's mouth on %p's pussy", 'name_player' => "%p's mouth on %n's pussy", 'tier_hint' => 'sexual',
            'words' => ['cunnilingus', 'eat your pussy', 'lick your pussy', 'eat you out', 'go down on you']],
        // NOT INSTALLED on this setup: zero actions match and zero scenes declare a penis-oral AND a
        // vulva-oral action. Kept in the table so "lets do 69" is answered in words instead of ignored.
        'sixtynine'    => ['match' => ['sixtynine*', '*sixtynine*'], 'directional' => false, 'name' => 'sixty-nine', 'tier_hint' => 'sexual',
            'words' => ['69', 'sixty nine', 'sixtynine', 'sixty-nine']],
        'titfuck'      => ['match' => ['*boobjob*', 'titfuck*', 'titjob*', 'breastsliding'], 'directional' => true, 'name_npc' => "%p's cock between %n's breasts", 'name_player' => "%n's cock between %p's breasts", 'tier_hint' => 'sexual',
            'words' => ['boobjob', 'boob job', 'titjob', 'tit job', 'titfuck', 'tit fuck', 'between your breasts', 'between your tits']],
        'thighjob'     => ['match' => ['*thighjob*'], 'directional' => true, 'name_npc' => "%p between %n's thighs", 'name_player' => "%n between %p's thighs", 'tier_hint' => 'sexual',
            'words' => ['thighjob', 'thigh job', 'between your thighs']],
        'grinding'     => ['match' => ['grinding*', '*buttjob*', 'rubbing*'], 'directional' => false, 'name' => 'grinding against each other', 'tier_hint' => 'sensual',
            'words' => ['grind', 'grinding', 'dry hump']],
        'grope'        => ['match' => ['*groping*'], 'directional' => true, 'name_npc' => '%n touches %p', 'name_player' => '%p touches %n', 'tier_hint' => 'sensual',
            'words' => ['grope', 'fondle', 'breasts', 'tits', 'boobs', 'butt']],
        'spanking'     => ['match' => ['spanking', 'breastslapping', 'pullinghair'], 'directional' => true, 'niche' => true,
            'name_npc' => '%n spanks %p', 'name_player' => '%p spanks %n', 'tier_hint' => 'sensual',
            'words' => ['spank', 'spanking', 'smack my ass', 'smack your ass']],
        'massage'      => ['match' => ['massag*', '*massaging*'], 'directional' => true, 'name_npc' => '%n massages %p', 'name_player' => '%p massages %n', 'tier_hint' => 'affection',
            'words' => ['massage', 'rub my shoulders', 'rub your shoulders']],
        'vaginal'      => ['match' => ['vaginalsex', 'intercourse*', 'vaginalpenetration'], 'directional' => false, 'name' => 'vaginal sex', 'tier_hint' => 'sexual',
            'words' => ['sex', 'fuck', 'fucking', 'screw', 'make love', 'take me', 'all the way', 'missionary', 'cowgirl', 'riding', 'ride',
                'doggy', 'doggystyle', 'doggy style', 'from behind', 'from the back', 'bend over', 'bent over', 'reverse cowgirl', 'all fours', 'on top']],
        'masturbation' => ['match' => ['*masturbation*'], 'directional' => true, 'name_npc' => "%n touches %n's own body", 'name_player' => "%p touches %p's own body", 'tier_hint' => 'sensual',
            'words' => ['masturbate', 'touch yourself', 'touch myself']],
        'kiss'         => ['match' => ['*kissing*', 'kiss', 'frenchkiss*', 'lickingear'], 'directional' => false, 'name' => 'kissing', 'tier_hint' => 'kissing',
            'words' => ['kiss', 'kissing', 'make out', 'making out', 'french kiss']],
        'hold'         => ['match' => ['*hugging*', 'embrac*', '*cuddl*', 'holdingbody', 'spooning', 'lappillow', 'leglocking'], 'directional' => false, 'name' => 'holding each other', 'tier_hint' => 'kissing',
            'words' => ['hug', 'embrace', 'cuddle', 'cuddling', 'snuggle', 'hold me']],
        // no OStim ACTION name exists for this; it is recognised from the scene tags by the position layer
        'facesitting'  => ['match' => [], 'directional' => true, 'niche' => true,
            'name_npc' => "%n sits on %p's face", 'name_player' => "%p sits on %n's face", 'tier_hint' => 'sexual',
            'words' => ['facesitting', 'face sitting', 'sit on my face', 'ride my face']],
    ];
    $cfg = (array) (lrgConfig()['acts'] ?? []);
    foreach ($cfg as $id => $row) {
        if (!is_array($row)) { continue; }
        $def[$id] = is_array($def[$id] ?? null) ? array_replace($def[$id], $row) : $row;
    }
    return $t = $def;
}

/** Canonical OStim action name -> act family id, '' = no family knows it (G5, assumption 6). */
function lrgActFamily(string $action): string
{
    static $cache = [];
    $a = strtolower(trim($action));
    if ($a === '' || $a === 'default') { return ''; }
    if (array_key_exists($a, $cache)) { return $cache[$a]; }
    foreach (lrgActsTable() as $id => $f) {
        foreach ((array) ($f['match'] ?? []) as $glob) {
            if (fnmatch(strtolower((string) $glob), $a)) { return $cache[$a] = (string) $id; }
        }
    }
    return $cache[$a] = '';
}

/** Plain words for a position id - vocabulary for the notes, never a line anyone speaks. */
const LRG_POS_LABELS = ['reversecowgirl' => 'reverse cowgirl', 'cowgirl' => 'her on top, facing him', 'missionary' => 'missionary',
    'doggy' => 'from behind', 'allfours' => 'on all fours', 'bendover' => 'bent over', 'spooning' => 'spooning',
    'prone' => 'face down', 'carrying' => 'carried in his arms', 'lap' => 'on his lap', 'facesitting' => 'sitting on his face',
    'wall' => 'against the wall', 'standing' => 'standing', 'kneeling' => 'kneeling', 'sitting' => 'sitting',
    'bed' => 'on the bed', 'table' => 'on the table', 'chair' => 'on the chair', 'bench' => 'on the bench',
    'shelf' => 'against the shelf', 'cookingpot' => 'over the cooking pot'];

function lrgPositionLabel(string $pos): string
{
    $cfg = array_change_key_case((array) (lrgConfig()['scene_index']['position_labels'] ?? []), CASE_LOWER);
    return (string) ($cfg[$pos] ?? LRG_POS_LABELS[$pos] ?? $pos);
}

/**
 * Display name of an act id. %n / %p are replaced with the two names (or neutral words).
 * [0.3.1] An id may carry a position ("vaginal/reversecowgirl"), which is appended in plain words.
 */
function lrgActLabel(string $actId, string $npc = '', string $player = ''): string
{
    [$fam, $role, $pos] = lrgActParse($actId);
    $f = lrgActsTable()[$fam] ?? null;
    if (!$f) { return str_replace([':npc', ':you', '/'], ['', '', ', '], $actId); }
    $name = empty($f['directional'])
        ? (string) ($f['name'] ?? $fam)
        : (string) ($role === 'you' ? ($f['name_player'] ?? $f['name'] ?? $fam) : ($f['name_npc'] ?? $f['name'] ?? $fam));
    $name = str_replace(['%n', '%p'], [$npc !== '' ? $npc : 'she', $player !== '' ? $player : 'the player'], $name);
    return $pos === '' ? $name : $name . ', ' . lrgPositionLabel($pos);
}

/**
 * The act ids one scene offers. $npcSlot is the NPC's actor position in the CURRENT thread (wire key npos).
 * Direction is derived from the CANDIDATE's own slot sexes whenever they tell them apart (the digest already
 * carries them): a candidate scene has its own per-slot intendedSex, so reusing the current thread's slot
 * number would offer half the directional acts the wrong way round. Only when the candidate's slots are
 * indistinguishable does $npcSlot decide, and $assumed is then raised so the turn can log how often it happened.
 */
function lrgSceneActs(array $scene, int $npcSlot, ?array $sexes = null, ?bool &$assumed = null): array
{
    $slot = $npcSlot;
    $byS = false;
    $sx = $sexes !== null ? lrgNormSexes($sexes) : null;   // [player, npc] as words
    $slots = (array) ($scene['sex'] ?? []);
    if ($sx !== null && count($slots) >= 2) {
        $npcSex = (string) ($sx[1] ?? 'any');
        $playerSex = (string) ($sx[0] ?? 'any');
        $named = array_values(array_filter($slots, static fn($v) => $v === 'male' || $v === 'female'));
        if ($named && $npcSex !== $playerSex) {
            foreach ($slots as $i => $v) {
                if ($v === $npcSex) { $slot = (int) $i; $byS = true; break; }
            }
            if (!$byS) { // only the player's slot is named; the NPC has the other one
                foreach ($slots as $i => $v) {
                    if ($v === $playerSex) { $slot = (int) (1 - (int) $i); $byS = true; break; }
                }
            }
        }
    }
    if (!$byS) { $assumed = true; }
    $out = [];
    foreach ((array) ($scene['roles'] ?? []) as $r) {
        $fam = lrgActFamily((string) ($r[0] ?? ''));
        if ($fam === '') { continue; }
        $f = lrgActsTable()[$fam];
        $out[empty($f['directional']) ? $fam : $fam . ((int) ($r[1] ?? 0) === $slot ? ':npc' : ':you')] = true;
    }
    return array_keys($out);
}

/** Every act id a family can produce, for a lookup that does not care about direction. */
function lrgActIds(string $fam): array
{
    $f = lrgActsTable()[$fam] ?? null;
    if (!$f) { return []; }
    return empty($f['directional']) ? [$fam] : [$fam . ':npc', $fam . ':you'];
}

// ---------------------------------------------------------------- [0.3.1 / R2-D5] positions are first-class
/**
 * Position id -> the words that prove it, matched against ONE blob per scene (tags + actor tags + the
 * words of the id and display name, all concatenated). The installed data is rich and was completely
 * unused by the act layer: doggy 44 scenes, cowgirl 29, missionary 25, all fours 25, bend over 23,
 * prone 14, spooning 11, reverse cowgirl 7, carrying 7 (research/pt7-act-coverage.md 1.3).
 * ORDER MATTERS: reversecowgirl is tested before cowgirl and cancels it.
 */
const LRG_POSITIONS = [
    'reversecowgirl' => ['reversecowgirl', 'reversecow', 'reverserid'],
    'cowgirl'        => ['cowgirl', 'ridingontop'],
    'missionary'     => ['missionary', 'matingpress', 'holdknees', 'frontalsex'],
    'doggy'          => ['doggystyle', 'doggy', 'rearsex', 'frombehind', 'behind', 'backitup', 'backshot'],
    'allfours'       => ['allfours'],
    'bendover'       => ['bendover', 'bentover'],
    'spooning'       => ['spooning', 'spooncuddle'],
    'prone'          => ['prone', 'lyingfront'],
    'carrying'       => ['suspended', 'carrying', 'princesscarry', 'standinglotus', 'pickup'],
    'facesitting'    => ['facesitting', 'faceriding', 'facesit'],
    'lap'            => ['lappillow', 'onlap', 'sittingonlap', 'lapdance'],
    'wall'           => ['wall'],
    'standing'       => ['standing'],
    'kneeling'       => ['kneeling'],
    'sitting'        => ['sitting'],
    // [0.3.1 fix pass] WHICH part of her the hands are on. Groping had no such split, so "grab my ass"
    // and "play with your tits" both landed on whichever groping scene ranked best - HandsOnBreasts in
    // the playtest. The words are the ones the ids and tags really use; they are deliberately narrow
    // ("ass" would match "massage" and "passionate" inside a concatenated blob).
    'butt'           => ['butt', 'buttock', 'rear'],
    'breasts'        => ['breast', 'boob', 'nipple', 'titfuck', 'titjob'],
];
/**
 * [0.3.1 fix pass] What to try when the position the player named has NO installed scene for that act:
 * the nearest thing the packs really have, rather than "any scene of this act at all". "Against the
 * wall" landing on a scene of them lying on their sides is the failure this closes - no OStim scene of
 * these packs declares a wall sex position, but a standing one is the same picture.
 */
const LRG_POS_NEAR = [
    'wall' => ['standing'], 'lap' => ['sitting', 'cowgirl'], 'table' => ['bendover', 'standing'],
    'chair' => ['sitting'], 'bench' => ['sitting'], 'carrying' => ['standing'], 'allfours' => ['doggy'],
    'bendover' => ['doggy', 'allfours'], 'prone' => ['doggy'], 'doggy' => ['allfours', 'bendover'],
    'facesitting' => ['cowgirl'], 'breasts' => [], 'butt' => [],
];
/**
 * Positions that only make sense as a SEX position: naming one is a request for sex, not for a posture.
 * `carrying` is deliberately NOT here - "pick me up" / "in your arms" is as often an embrace - and
 * `wall` is, because "against the wall" is never said about standing next to one.
 */
const LRG_POS_SEXUAL = ['reversecowgirl', 'cowgirl', 'missionary', 'doggy', 'allfours', 'bendover', 'prone', 'facesitting', 'wall'];

/** The position ids one scene really offers (tags, actor tags, id / name words, plus its furniture). */
function lrgScenePositions(array $s): array
{
    $k = strtolower((string) ($s['id'] ?? ''));
    if ($k !== '' && isset($GLOBALS['LRG_INDEX_DERIVED']['pos'][$k])) { return $GLOBALS['LRG_INDEX_DERIVED']['pos'][$k]; }
    $name = (string) ($s['name'] ?? '');
    $blob = implode('', (array) ($s['tags'] ?? [])) . implode('', (array) ($s['actor_tags'] ?? []))
        . implode('', lrgIdWords((string) ($s['id'] ?? '') . ' ' . ($name !== '' && $name[0] !== '$' ? $name : '')));
    $out = [];
    foreach (LRG_POSITIONS as $pos => $words) {
        if ($pos === 'cowgirl' && isset($out['reversecowgirl'])) { continue; } // "ReverseCowgirl" is not cowgirl
        foreach ($words as $w) {
            if (str_contains($blob, $w)) { $out[$pos] = true; break; }
        }
    }
    $furn = strtolower(trim((string) ($s['furniture'] ?? '')));
    if ($furn !== '' && $furn !== 'none') {
        foreach (lrgFurnitureChain($furn) as $t) { if ($t !== 'none') { $out[$t] = true; } }
    }
    $list = array_keys($out);
    if ($k !== '') { $GLOBALS['LRG_INDEX_DERIVED']['pos'][$k] = $list; }
    return $list;
}

/**
 * [0.3.1 / R2-D6] EVERY installed scene this pair can be in, grouped by act id and by position.
 *
 * This is the primitive that stops unreachability from killing a request. lrgActOptions() is built from
 * a 3-hop lrgSceneWalk(), and from the default start scene only 6 of 24 act ids are inside that walk -
 * which is exactly F1 ("suck my deck" answered with "not reachable"). The game was never the blocker:
 * LRG_OStim warps a player-requested goto whenever the route oracle says unreachable and bAllowWarp is
 * on. So the request is resolved against the whole pool and simply marked "not routed" when no route
 * exists; only when NOTHING installed fits is it a real "can't".
 * Returns [actId => ['any' => [entry, ...], 'pos' => [posId => [entry, ...]]]], entries sorted best first.
 * Cached per request.
 */
function lrgActPool(?array $sexes, string $furn, int $npcSlot, int $actors = 2): array
{
    $key = $actors . '|' . implode(',', lrgNormSexes($sexes) ?? ['*']) . '|' . strtolower(trim($furn)) . '|' . $npcSlot;
    if (isset($GLOBALS['LRG_INDEX_DERIVED']['actpool'][$key])) { return $GLOBALS['LRG_INDEX_DERIVED']['actpool'][$key]; }
    $idx = lrgSceneIndex()['scenes'];
    $out = [];
    foreach (lrgSearchPool($actors, $sexes, $furn) as $k) {
        $s = $idx[$k] ?? null;
        if (!$s || in_array('climaxing', (array) $s['actor_tags'], true)) { continue; }
        $positions = lrgScenePositions($s);
        $entry = ['id' => (string) $s['id'], 'tier' => (int) LRG_TIERS[$s['tier']], 'taste' => !empty($s['taste']),
            'special' => !empty($s['special']),
            'rank' => (lrgSceneNameFits($s, $sexes) ? 0 : 4) + (!empty($s['taste']) ? 2 : 0) + (!empty($s['special']) ? 3 : 0)
                + (stripos((string) $s['pack'], 'Open Animations') !== false ? 0 : 1) - min(count((array) $s['to']), 4) * 0.1];
        $acts = lrgSceneActs($s, $npcSlot, $sexes);
        // [0.3.1] facesitting has no OStim action name at all: the scene tags are the only evidence
        if (in_array('facesitting', $positions, true)) { $acts[] = 'facesitting:npc'; }
        // [0.3.1 fix pass] Neither has ANY of the gentle scenes: OARE_SpooningCuddling1..4,
        // OARE_LapPillow, OARE_PrincessCarryEmbrace, OARE_CuddleFromBehind and two dozen more declare
        // the action "default", so lrgSceneActs() returns nothing for them and the whole holding
        // family consisted of the two scenes that happen to declare a hugging action. Every "let's
        // spoon" and "cuddle with me" therefore ended in the same standing hug. The scene's own
        // position and id are the evidence here, exactly as they are for facesitting.
        if (!$acts && in_array((string) $s['tier'], ['affection', 'kissing'], true)
            && (array_intersect($positions, ['spooning', 'lap', 'carrying'])
                || preg_match('/cuddl|embrac|hug|holding/i', (string) $s['id']))) {
            $acts[] = 'hold';
        }
        foreach (array_unique($acts) as $act) {
            $out[$act]['any'][] = $entry;
            foreach ($positions as $p) { $out[$act]['pos'][$p][] = $entry; }
        }
    }
    $cmp = static fn($a, $b) => [$a['rank'], $a['id']] <=> [$b['rank'], $b['id']];
    foreach ($out as $act => &$row) {
        usort($row['any'], $cmp);
        foreach (($row['pos'] ?? []) as $p => &$list) { usort($list, $cmp); }
        unset($list);
    }
    unset($row);
    return $GLOBALS['LRG_INDEX_DERIVED']['actpool'][$key] = $out;
}

/** 'vaginal:npc/doggy' -> ['vaginal', ':npc', 'doggy']; the role and the position are both optional. */
function lrgActParse(string $actId): array
{
    $pos = '';
    if (($p = strpos($actId, '/')) !== false) { $pos = strtolower(substr($actId, $p + 1)); $actId = substr($actId, 0, $p); }
    [$fam, $role] = array_pad(explode(':', $actId, 2), 2, '');
    return [strtolower($fam), $role === '' ? '' : strtolower($role), $pos];
}

/** The act id without its position suffix (what lrgActOptions and lrgSceneActs speak). */
function lrgActBase(string $actId): string
{
    [$fam, $role] = lrgActParse($actId);
    return $fam . ($role !== '' ? ':' . $role : '');
}

/** True when the installed packs can actually do this act (with this position, if one was named). */
function lrgActInstalled(string $actId, ?array $sexes = null, string $furn = '', int $npcSlot = 1, int $actors = 2): bool
{
    [$fam, $role, $pos] = lrgActParse($actId);
    $pool = lrgActPool($sexes, $furn, $npcSlot, $actors);
    $base = $fam . ($role !== '' ? ':' . $role : '');
    if (!isset($pool[$base])) { return false; }
    return $pos === '' || !empty($pool[$base]['pos'][$pos]);
}

/**
 * [0.3.1 / R2-D6] The best installed scene for this act (and position), whether or not a route exists.
 * $prefer = ids that are routable right now; they win, and 'routed' says whether the winner was one of
 * them - the caller turns a false into warp=1 rather than into a refusal.
 * Returns ['id','tier','routed','exact'] or null when nothing installed fits these two actors at all.
 */
function lrgFindActScene(string $actId, ?array $sexes, string $furn, int $maxTier, array $prefer = [], string $current = '', int $npcSlot = 1, int $actors = 2, bool $standing = false): ?array
{
    [$fam, $role, $pos] = lrgActParse($actId);
    $base = $fam . ($role !== '' ? ':' . $role : '');
    $pool = lrgActPool($sexes, $furn, $npcSlot, $actors);
    if (!isset($pool[$base])) { return null; }
    $preferMap = array_flip(array_map('strtolower', $prefer));
    $curK = strtolower($current);
    $pick = static function (array $list, bool $exact) use ($preferMap, $curK, $maxTier, $standing): ?array {
        $best = null; $bestScore = INF;
        foreach ($list as $e) {
            if ($e['tier'] > $maxTier) { continue; }
            if (strtolower($e['id']) === $curK) { continue; }
            $score = $e['rank'] - (isset($preferMap[strtolower($e['id'])]) ? 10 : 0);
            // a START with no furniture ref: nobody lies down on thin air, so an upright scene wins
            if ($standing) {
                $s = lrgScene((string) $e['id']);
                if ($s && in_array('standing', (array) $s['actor_tags'], true)) { $score -= 6; }
            }
            if ($score < $bestScore) { $bestScore = $score; $best = $e; }
        }
        return $best === null ? null : ['id' => $best['id'], 'tier' => $best['tier'],
            'routed' => isset($preferMap[strtolower($best['id'])]), 'exact' => $exact];
    };
    if ($pos !== '' && !empty($pool[$base]['pos'][$pos])) {
        $hit = $pick($pool[$base]['pos'][$pos], true);
        if ($hit !== null) { return $hit; }
    }
    // [0.3.1 fix pass] the named position has nothing installed for this act: the NEAREST installed
    // position beats "any scene of this act" ("against the wall" -> standing, not lying on their sides)
    if ($pos !== '') {
        foreach ((array) (LRG_POS_NEAR[$pos] ?? []) as $near) {
            if (empty($pool[$base]['pos'][$near])) { continue; }
            $hit = $pick($pool[$base]['pos'][$near], false);
            if ($hit !== null) { return $hit; }
        }
    }
    return $pick($pool[$base]['any'], $pos === '');
}

/** The position ids this act really has installed for this pair (for the notes and for the tests). */
function lrgActPositions(string $actId, ?array $sexes = null, string $furn = '', int $npcSlot = 1, int $actors = 2): array
{
    $pool = lrgActPool($sexes, $furn, $npcSlot, $actors);
    $base = lrgActBase($actId);
    $out = array_keys((array) ($pool[$base]['pos'] ?? []));
    sort($out);
    return $out;
}

/**
 * [0.3.1 / R2-D12] Speech-to-text and split-word repairs, applied before anything is matched.
 * Every entry is a form that really arrived in playtest 7 or a split-word spelling Whisper produces.
 * Deliberately a closed table, never a fuzzy pass over the blocker patterns: a mis-repaired "don't"
 * would turn a refusal into a request.
 */
function lrgActSpeechFixes(): array
{
    $def = [
        'my deck' => 'my dick', 'your deck' => 'your dick', 'my dic' => 'my dick',
        'doggy style socks' => 'doggy style sex', 'style socks' => 'style sex', 'from behind sack' => 'from behind sex',
        'behind sack' => 'behind sex', 'book a missionary' => 'do missionary', 'booked a missionary' => 'do missionary',
        'cow girl' => 'cowgirl', 'blow job' => 'blowjob', 'hand job' => 'handjob', 'tit job' => 'titjob',
        'boob job' => 'boobjob', 'foot job' => 'footjob', 'thigh job' => 'thighjob', 'rim job' => 'rimjob',
        'sixty nine' => 'sixtynine', 'sixty-nine' => 'sixtynine', 'dogy style' => 'doggy style', 'dogie style' => 'doggy style',
        'doggie style' => 'doggy style', 'deep throat' => 'deepthroat', 'striped naked' => 'strip naked',
        'missionery' => 'missionary', 'missionnary' => 'missionary', 'reverse cow girl' => 'reverse cowgirl',
        'ass hole' => 'asshole', 'butt sex' => 'buttsex', 'ass fuck' => 'assfuck', 'prone bone' => 'prone',
        'mating press' => 'missionary', 'all four' => 'all fours', 'on all 4s' => 'on all fours',
    ];
    $cfg = (array) (lrgConfig()['intent']['speech_fixes'] ?? []);
    foreach ($cfg as $k => $v) { $def[strtolower((string) $k)] = strtolower((string) $v); }
    return $def;
}

/** Lowercase, punctuation-free, speech-repaired, space-padded - the one form every act matcher works on. */
function lrgActNormalise(string $text): string
{
    $t = ' ' . trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[^a-z0-9\']+/', ' ', strtolower($text)))) . ' ';
    foreach (lrgActSpeechFixes() as $bad => $good) {
        if (str_contains($t, ' ' . $bad . ' ')) { $t = str_replace(' ' . $bad . ' ', ' ' . $good . ' ', $t); }
    }
    return $t;
}

/** Spoken phrase -> position id. Longest phrase first, so "reverse cowgirl" beats "cowgirl". */
function lrgPositionWordsTable(): array
{
    static $t = null;
    if ($t !== null) { return $t; }
    $def = [
        'reverse cowgirl' => 'reversecowgirl', 'reversecowgirl' => 'reversecowgirl', 'facing away' => 'reversecowgirl',
        'cowgirl' => 'cowgirl', 'ride me' => 'cowgirl', 'ride you' => 'cowgirl', 'riding me' => 'cowgirl', 'riding you' => 'cowgirl',
        'on top of me' => 'cowgirl', 'on top' => 'cowgirl', 'straddle me' => 'cowgirl', 'climb on' => 'cowgirl', 'bounce on' => 'cowgirl',
        'missionary' => 'missionary', 'on your back' => 'missionary', 'on my back' => 'missionary', 'face to face' => 'missionary',
        // [0.3.1 fix pass] WHOSE top. "on top" alone is her on top, but "let me be on top" / "i want to
        // be on top" is the player - it was answered with a cowgirl scene, which is the owner's own
        // reverse-cowgirl complaint from the other side. Longest phrase first does the rest.
        'let me be on top' => 'missionary', 'i want to be on top' => 'missionary', 'i wanna be on top' => 'missionary',
        'me on top' => 'missionary', 'lay down' => 'missionary', 'lie down' => 'missionary', 'lay back' => 'missionary',
        'lie back' => 'missionary', 'get on your back' => 'missionary',
        // "turn around" on its own is a posture, not a request for sex, and is deliberately absent
        'doggy style' => 'doggy', 'doggystyle' => 'doggy', 'doggy' => 'doggy', 'from behind' => 'doggy', 'from the back' => 'doggy',
        'take you from behind' => 'doggy', 'turn around and' => 'doggy', 'back shot' => 'doggy',
        // ... but "turn around AND RIDE me" is reverse cowgirl, and it is one of the six the owner named
        'turn around and ride me' => 'reversecowgirl', 'turn around and ride' => 'reversecowgirl',
        'turn around and get on' => 'reversecowgirl', 'ride me backwards' => 'reversecowgirl', 'ride me facing away' => 'reversecowgirl',
        'all fours' => 'allfours', 'hands and knees' => 'allfours',
        'bend over' => 'bendover', 'bent over' => 'bendover', 'bend me over' => 'bendover', 'bend you over' => 'bendover',
        'spooning' => 'spooning', 'spoon me' => 'spooning', 'spoon' => 'spooning',
        'prone' => 'prone', 'face down' => 'prone',
        'carry me' => 'carrying', 'carrying' => 'carrying', 'pick me up' => 'carrying', 'pick you up' => 'carrying',
        'princess carry' => 'carrying', 'lift me' => 'carrying', 'in your arms' => 'carrying',
        'sit on my face' => 'facesitting', 'ride my face' => 'facesitting', 'face sitting' => 'facesitting', 'facesitting' => 'facesitting',
        'sit on my lap' => 'lap', 'sit in my lap' => 'lap', 'on my lap' => 'lap', 'on your lap' => 'lap',
        'in my lap' => 'lap', 'in your lap' => 'lap', 'lap' => 'lap',
        'against the wall' => 'wall', 'up against the wall' => 'wall', 'on the wall' => 'wall',
        // [0.3.1 fix pass] which part of her the hands are on (LRG_POSITIONS butt / breasts)
        'my ass' => 'butt', 'your ass' => 'butt', 'my butt' => 'butt', 'your butt' => 'butt',
        'my arse' => 'butt', 'your arse' => 'butt', 'my cheeks' => 'butt', 'your cheeks' => 'butt',
        'my tits' => 'breasts', 'your tits' => 'breasts', 'my breasts' => 'breasts', 'your breasts' => 'breasts',
        'my boobs' => 'breasts', 'your boobs' => 'breasts', 'my chest' => 'breasts', 'your chest' => 'breasts',
        'standing up' => 'standing', 'standing' => 'standing', 'on our feet' => 'standing',
        'on your knees' => 'kneeling', 'on my knees' => 'kneeling', 'kneel' => 'kneeling', 'kneeling' => 'kneeling',
        'on the bed' => 'bed', 'to the bed' => 'bed', 'in bed' => 'bed', 'on the table' => 'table', 'on the chair' => 'chair',
    ];
    $cfg = (array) (lrgConfig()['scene_index']['position_phrases'] ?? []);
    foreach ($cfg as $k => $v) { $def[strtolower((string) $k)] = strtolower((string) $v); }
    $keys = array_keys($def);
    usort($keys, static fn($a, $b) => strlen((string) $b) <=> strlen((string) $a));
    $t = [];
    foreach ($keys as $k) { $t[$k] = $def[$k]; }
    return $t;
}

/**
 * [0.3.1 fix pass] Phrases that name a position ONLY when a sex verb is in the same breath. "Lie down"
 * on its own is a posture and must stay silent (it used to resolve to missionary, i.e. to sex);
 * "lay down and let me fuck you" is the position he means.
 */
const LRG_POS_WEAK_PHRASES = ['lay down', 'lie down', 'lay back', 'lie back', 'get on your back', 'turn around and get on'];

/** The position the player named, '' when none. $text must already be lrgActNormalise()d. */
function lrgMatchPosition(string $t, bool $sexVerb = true): string
{
    foreach (lrgPositionWordsTable() as $phrase => $pos) {
        if (!str_contains($t, ' ' . $phrase . ' ')) { continue; }
        if (!$sexVerb && in_array((string) $phrase, LRG_POS_WEAK_PHRASES, true)) { continue; }
        return $pos;
    }
    return '';
}

/**
 * [0.3.1 / R2-D3] The priority phrase table: an anatomy+verb phrase beats every bare noun.
 * lrgMatchAct() used to be a flat longest-word contest, which is why "i want to fuck your asshole"
 * resolved to vaginal (the word "fuck"), "lick my cock" to oralvulva (the word "lick") and "fuck my
 * cock with your tits" to grope (the word "tits"). Each row is [regex, act id]; the FIRST match wins,
 * so the rows are ordered most specific first. `:npc` = she does it, `:you` = the player does it.
 */
function lrgActPhrases(): array
{
    static $t = null;
    if ($t !== null) { return $t; }
    $t = [
        // --- self first: "stroke myself", "rub yourself on me" are about one body, not two
        ['/\b(touch|stroke|rub|finger|play with|pleasure)\s+(myself|my ?self)\b/', 'masturbation:you'],
        ['/\b(touch|stroke|rub|finger|play with|pleasure)\s+(yourself|your ?self)\b/', 'masturbation:npc'],
        ['/\brub yourself (on|against)\b|\bgrind (on|against)\b|\brub against\b|\bdry hump\b/', 'grinding'],
        // --- nipples before the oral rows: "let me suck your nipples" is not a blowjob
        ['/\bnipples?\b/', 'nipples'],
        // --- a mouth that is named wins over every general verb below
        ['/\b(in|into|down) (your|my) (mouth|throat)\b|\bface ?fuck\w*\b|\bchoke on it\b/', 'oralpenis:npc'],
        // --- oral on a penis
        ['/\b(give|gimme)\b[^.]{0,12}\b(blowjob|bj)\b|\bblowjob\b|\bbj\b|\bdeepthroat\w*\b|\bfellatio\b/', 'oralpenis:npc'],
        ['/\b(suck|lick|blow|kiss|worship)\b[^.]{0,16}\bmy\b[^.]{0,10}\b(cock|dick|shaft|balls|prick|length)\b/', 'oralpenis:npc'],
        ['/\b(suck|blow)\s+(me|it)\b|\bsuck me off\b|\bgo down on me\b|\bput your mouth on (my|me)\b|\buse your mouth on (my|me)\b/', 'oralpenis:npc'],
        ['/\bon your knees\b[^.]{0,24}\b(suck|blow|mouth)\b/', 'oralpenis:npc'],
        ['/\blet me\b[^.]{0,16}\b(suck|blow)\b[^.]{0,16}\byour\b/', 'oralpenis:you'],
        // --- face sitting (only two scenes are installed and both are taste-listed, so it is never
        //     offered by itself - but when he asks for it by name he gets it)
        ['/\b(sit on my face|ride my face|facesitting)\b/', 'facesitting'],
        // --- oral on a vulva
        ['/\blet me\b[^.]{0,16}\b(eat|lick|taste|tongue|go down on)\b/', 'oralvulva:you'],
        ['/\b(eat|lick|taste)\b[^.]{0,12}\byour\b[^.]{0,10}\b(pussy|cunt|clit|twat|snatch)\b|\beat you out\b|\bgo down on you\b|\b(taste|lick|eat) you\b/', 'oralvulva:you'],
        ['/\b(eat|lick|taste)\b[^.]{0,12}\bmy\b[^.]{0,10}\b(pussy|cunt|clit)\b|\byour tongue\b[^.]{0,12}\b(on|in|inside) me\b/', 'oralvulva:npc'],
        // --- hands
        // --- her own fingers in her own body is masturbation, not fingering HIM
        ['/\b(put|slide|push|get|work|slip)\b[^.]{0,16}\byour (fingers|hand)\b[^.]{0,12}\b(in|inside|into)\b[^.]{0,10}\byou\w*\b/', 'masturbation:npc'],
        // --- hands
        ['/\bhandjob\b|\bjerk me( off)?\b|\bjerk it\b|\buse your hand\b/', 'handjob:npc'],
        ['/\b(stroke|rub|squeeze|grip|work|pump|play with|touch)\b[^.]{0,12}\b(my|that|it)\b[^.]{0,10}\b(cock|dick|shaft|prick|length)\b/', 'handjob:npc'],
        ['/\blet me\b[^.]{0,12}\b(jerk|stroke)\b[^.]{0,10}\byou\b/', 'handjob:you'],
        ['/\bfinger me\b|\bfinger my (pussy|cunt)\b|\buse your fingers\b/', 'fingering:npc'],
        ['/\b(let me\b[^.]{0,12})?\bfinger you\b|\bplay with your (pussy|cunt|clit)\b|\brub your (pussy|cunt|clit)\b|\blet me use my fingers\b/', 'fingering:you'],
        // --- breasts / thighs / feet
        // a fuck verb is required: "play with your tits" is groping, not a titjob.
        // [0.3.1 fix pass] the gap between the verb and the breasts was 12 characters, and "fuck my
        // cock with your tits" has 18 of them in between, so the owner's sentence was read as plain
        // intercourse. "with your tits" is by itself unambiguous and is now its own alternative.
        ['/\btitjob\b|\bboobjob\b|\btitfuck\b|\bbetween your (tits|breasts|boobs)\b|\b(fuck|use|slide|rub|put it)\b[^.]{0,26}\b(your )?(tits|breasts|boobs)\b/', 'titfuck:npc'],
        ['/\bthighjob\b|\bbetween your thighs\b/', 'thighjob'],
        ['/\bfootjob\b|\bwith your feet\b|\buse your feet\b|\brub me with your feet\b/', 'footjob'],
        // --- rear
        ['/\brimjob\b|\brimming\b|\b(lick|eat)\b[^.]{0,10}\b(my|your) (ass|arse|asshole)\b/', 'rimjob'],
        ['/\banal\b|\basshole\b|\bbuttsex\b|\bassfuck\b|\b(in|up) (the|your|my) (ass|arse|butt)\b|\btake it in the ass\b|\bfuck (your|my) (ass|arse|butt)\b/', 'anal'],
        // --- sixty-nine (not installed here: it must still be recognised so she can say so)
        ['/\bsixtynine\b|\b69\b/', 'sixtynine'],
        // --- self
        ['/\btouch yourself\b|\bplay with yourself\b|\bmasturbate\b|\bfinger yourself\b|\bmake yourself come\b|\bwatch you touch\b/', 'masturbation:npc'],
        ['/\blet me touch myself\b|\bwatch me (touch|jerk|stroke)\b/', 'masturbation:you'],
        // --- groping (an explicit body part, otherwise a bare "touch" is not an act)
        ['/\b(grab|squeeze|fondle|grope|play with|touch|feel|hold)\b[^.]{0,16}\byour\b[^.]{0,12}\b(tits|breasts|boobs|ass|arse|butt|chest)\b/', 'grope:you'],
        ['/\b(grab|squeeze|fondle|grope|touch|rub|slap)\b[^.]{0,12}\bmy\b[^.]{0,10}\b(ass|arse|butt|chest|balls)\b/', 'grope:npc'],
        ['/\blet me\b[^.]{0,12}\b(touch|feel|grab|squeeze|grope|fondle)\b[^.]{0,12}\b(those|them|these)\b/', 'grope:you'],
        ['/\bspank\w*\b|\bsmack my (ass|arse|butt)\b|\bsmack your (ass|arse|butt)\b/', 'spanking'],
        // --- gentle. A head in a lap is a lap PILLOW, and it has to be tested before the lap-sex row
        ['/\b(put|rest|lay|lie|place)\b[^.]{0,16}\byour head\b[^.]{0,12}\b(on|in)\b[^.]{0,10}\bmy lap\b/', 'hold'],
        ['/\bfrench kiss\w*\b|\bmake out\b|\bmaking out\b|\bkiss\w*\b/', 'kiss'],
        ['/\bhug\b|\bhug me\b|\bcuddle\w*\b|\bsnuggle\w*\b|\bhold me\b|\bembrace\b|\bin your arms\b/', 'hold'],
        ['/\bmassage\b|\brub my (shoulders|back)\b/', 'massage'],
        // --- intercourse, last: its verbs are the most general ones people use.
        //     [0.3.1 fix pass] the four sentences playtest 7 left silent: a lap, being picked up,
        //     "inside you", and the crude "get that X over here". R2 names lap and carrying as
        //     first-class positions, and lrgMatchPosition() has already found which one it is.
        ['/\b(sit|climb|get|come|hop|settle|ride|bounce)\b[^.]{0,12}\b(on|onto|in|into)\b[^.]{0,8}\bmy lap\b/', 'vaginal'],
        ['/\bpick me up\b|\bcarry me\b|\blift me up\b|\bprincess carry\b|\bpick you up\b/', 'vaginal'],
        ['/\b(get|bring|put|move|back)\b[^.]{0,16}\b(that|your|them|those|it)\b[^.]{0,12}\b(pussy|cunt|ass|arse|butt|cheeks)\b[^.]{0,20}\b(on me|over here|here|on my|to me|back here)\b/', 'vaginal'],
        ['/\b(fuck|screw|shag|bang|pound|rail|plow|hump)\b|\bmake love\b|\bhave sex\b|\bput it in\b|\bslide (it )?in(side)?\b|\btake me\b|\bmount me\b|\binside me\b|\b(be|get) inside you\b|\ball the way\b|\bi (want|need) you right now\b/', 'vaginal'],
    ];
    return $t;
}

/**
 * [0.3.1 / R2-D3] Which act ids these two BODIES can actually perform, and what to use instead.
 * [whose body must be male/female, which sex, what the act becomes when it is not]. 'p' = the player's
 * slot, 'n' = the NPC's. Without this, a male player asking "go down on me" resolved to oralvulva:npc,
 * which has zero installed scenes, and the request died as "not reachable".
 */
/**
 * Synonym keys that carry NO act on their own. scene_index.synonyms exists for the free-text SCENE
 * search, where an incidental word costs nothing; feeding the same keys to the ACT layer produced
 * high-confidence requests out of ordinary talk ("can you feel that?" -> grope, "I love you" ->
 * vaginal sex, "lick my ear" -> oral), and the safety net then carried them out.
 */
const LRG_ACT_WEAK_SYNONYMS = ['feel', 'touch', 'hold', 'love', 'taste', 'inside', 'mouth', 'tongue', 'lick', 'eat',
    'arms', 'chest', 'back', 'side', 'sideways', 'lie', 'lying', 'lay', 'laying', 'sit', 'sitting', 'seated', 'stand',
    'standing', 'lap', 'rest', 'sleep', 'nap', 'hair', 'pat', 'neck', 'ear', 'behind', 'bend', 'rub', 'grind',
    'go down', 'turn around', 'on top', 'oral'];

const LRG_ACT_BODY = [
    'oralpenis:npc' => ['p', 'male', 'oralvulva:npc'],
    'oralpenis:you' => ['n', 'male', 'oralvulva:you'],
    'oralvulva:npc' => ['p', 'female', 'oralpenis:npc'],
    'oralvulva:you' => ['n', 'female', 'oralpenis:you'],
    'handjob:npc'   => ['p', 'male', 'fingering:npc'],
    'handjob:you'   => ['n', 'male', 'fingering:you'],
    'fingering:npc' => ['p', 'female', 'handjob:npc'],
    'fingering:you' => ['n', 'female', 'handjob:you'],
    'titfuck:npc'   => ['n', 'female', ''],
    'titfuck:you'   => ['p', 'female', ''],
];

/** True when these two bodies can perform this act id at all (null = the act has no body requirement). */
function lrgActBodyOk(string $base, array $sx): ?bool
{
    if (!isset(LRG_ACT_BODY[$base])) { return null; }
    [$slot, $want] = LRG_ACT_BODY[$base];
    $have = (string) ($slot === 'p' ? $sx[0] : $sx[1]);
    return $have === 'any' || $have === $want;
}

/**
 * Swap a direction these two bodies cannot perform for the one they can; '' when there is none.
 *
 * [0.3.1 fix pass] TWO REPAIRS, and which one comes first depends on where the role came from.
 *  · The player's own words said who does what ("put your tongue on me", "finger me"): WHO does it is
 *    the part he really said, so the cross-family fallback keeps it and adapts the organ - her tongue
 *    on a male player is oralpenis:npc, her hand on him is handjob:npc. Only if no fallback fits at
 *    all is the mirror tried, instead of dropping the request.
 *  · The role was ASSUMED, because nothing in the sentence named a direction (`item="oralvulva"` or
 *    "cunnilingus" from the model): there is no "who" to preserve, so the mirror wins and the FAMILY
 *    is never swapped. That is the defect this pass fixes - an assumed `:npc` used to turn a request
 *    for cunnilingus into OARE_SittingFellatio, a blowjob, silently (playtest 7's F3, moved families).
 */
function lrgActFixSexes(string $actId, ?array $sexes, bool $roleAssumed = false): string
{
    $sx = lrgNormSexes($sexes);
    if ($sx === null || count($sx) < 2) { return $actId; }
    [$fam, $role, $pos] = lrgActParse($actId);
    $base = $fam . ($role !== '' ? ':' . $role : '');
    $mirrorOf = static function (string $id): string {
        [$f, $r] = lrgActParse($id);
        return $r === 'npc' ? $f . ':you' : ($r === 'you' ? $f . ':npc' : '');
    };
    $seen = [];
    while (isset(LRG_ACT_BODY[$base]) && !isset($seen[$base])) {
        $seen[$base] = true;
        if (lrgActBodyOk($base, $sx) !== false) { break; }
        $mirror = $mirrorOf($base);
        $mirrorOk = $mirror !== '' && !isset($seen[$mirror]) && lrgActBodyOk($mirror, $sx) !== false;
        if ($roleAssumed && $mirrorOk) { $base = $mirror; break; }
        $fallback = (string) LRG_ACT_BODY[$base][2];
        if ($fallback === '') {
            if ($mirrorOk) { $base = $mirror; break; }   // the mirror rather than nothing at all
            return '';
        }
        $base = $fallback;
    }
    return $base . ($pos !== '' ? '/' . $pos : '');
}

/**
 * Spoken words -> act id, '' = no match. [0.3.1] The id may now carry a POSITION: `vaginal/doggy`,
 * `anal/missionary`. Resolution order: an exact act id · the priority phrase table (anatomy+verb beats
 * a bare noun) · the families' own words, most specific first · the scene_index.synonyms vocabulary ·
 * a named sex position on its own ("do reverse cowgirl" is a request for sex in that position).
 * The direction is then corrected against the two bodies, so a direction that cannot exist for this
 * pair is never returned.
 * $available = the act ids open right now ([] = do not filter, which is what the pre-lock pass uses).
 */
function lrgMatchAct(string $text, array $available = [], ?array $sexes = null): string
{
    $t = lrgActNormalise($text);
    if (trim($t) === '') { return ''; }
    $have = [];
    foreach ($available as $a) { $have[lrgActBase((string) $a)] = true; }
    $sexVerb = (bool) preg_match('/\b(fuck\w*|screw|sex|shag|bang|pound|rail|hump|penetrat\w*|take me|do it|go again|another round|round two|inside me|ride|riding)\b/', $t);
    $pos = lrgMatchPosition($t, $sexVerb);

    // [0.3.1 fix pass] $assumed is set when NOTHING in the sentence named a direction, so lrgActFixSexes
    // knows there is no "who" to preserve and may mirror the role instead of swapping the family.
    $assumed = false;
    $pick = static function (string $fam) use ($t, $have, $available, $sexes, &$assumed): string {
        $assumed = false;
        $f = lrgActsTable()[$fam] ?? [];
        if (empty($f['directional'])) { return $fam; }
        // the PLAYER is speaking: "let me ..." means the player acts, "... me / my / your mouth" means she does
        $self = (bool) preg_match('/\b(let me|i want to|i wanna|i would like to|i\'?d like to|i\'?ll|i am going to|i\'?m going to|can i|may i|could i|my turn)\b/', $t);
        $her = (bool) preg_match('/\byour (mouth|hand|hands|fingers|tongue|lips|feet|tits|breasts|thighs)\b/', $t)
            || (bool) preg_match('/\b(give me|gimme)\b/', $t)
            // [0.3.1 fix pass] the preposition is optional: "suck ON my nipples", "get down ON me" name
            // her as the one doing it just as plainly as "suck my nipples", and missing it sent the
            // sentence into the role-is-assumed branch
            || (bool) preg_match('/\b(suck|lick|eat|stroke|jerk|finger|rub|touch|grope|kiss|blow|spank|squeeze|grab|fondle|massage|ride|mount|pound|bite)\s+(on\s+|at\s+|onto\s+)?(me|my)\b/', $t)
            || (bool) preg_match('/\b(on|for|to)\s+me\b/', $t);
        // naming HER body part is stronger evidence than "i wanna": "i wanna nut in your mouth" is
        // something SHE does, however the sentence opens
        $role = ($self && !$her) ? 'you' : ($her ? 'npc' : '');
        if ($role !== '') { return $fam . ':' . $role; }
        $assumed = true;
        if ($available) {
            foreach ([':npc', ':you'] as $r) { if (isset($have[$fam . $r])) { return $fam . $r; } }
        }
        // [0.3.1 fix pass] The sentence named no direction at all - which is what a BARE family name
        // from the model is ("vaginal", "oralvulva", "handjob"). Defaulting to :npc and letting
        // lrgActFixSexes repair it is how "oralvulva" used to come back as a blowjob; the role these
        // two bodies really have installed is the honest default.
        // (only when the two bodies are known: with $sexes null this is the pre-lock light pass, which
        // must not decode the scene index at all)
        if (lrgNormSexes($sexes) !== null) {
            foreach ([':npc', ':you'] as $r) {
                if (lrgActInstalled($fam . $r, $sexes)) { return $fam . $r; }
            }
        }
        return $fam . ':npc';
    };
    $finish = static function (string $id, bool $roleAssumed = false) use ($pos, $sexes, $have, $available): string {
        if ($id === '') { return ''; }
        $id = lrgActFixSexes($id, $sexes, $roleAssumed);
        if ($id === '') { return ''; }
        [$fam, $role] = lrgActParse($id);
        // a position only rides along on a family that really has positions worth naming: a SEX
        // position on intercourse, and [0.3.1 fix pass] breasts / butt on groping, which had no split
        // at all and answered "grab my ass" with a scene of hands on her breasts
        $hands = in_array($pos, ['butt', 'breasts'], true);
        if ($pos !== '' && (($fam === 'grope' && $hands) || (!$hands && in_array($fam, ['vaginal', 'anal'], true)))) {
            $id = lrgActBase($id) . '/' . $pos;
        }
        if ($available && !isset($have[lrgActBase($id)])) {
            // the caller filters by what is OPEN; a direction that is open wins over one that is not
            foreach ([':npc', ':you'] as $r) { if (isset($have[$fam . $r])) { return $fam . $r; } }
        }
        return $id;
    };

    foreach (lrgActsTable() as $id => $f) { // 1. an exact act id, with or without a position suffix
        foreach (lrgActIds((string) $id) as $full) {
            if (str_contains($t, ' ' . strtolower($full) . ' ') || str_contains($t, ' ' . strtolower(str_replace(':', ' ', $full)) . ' ')) { return $finish($full); }
        }
        // [0.3.1 fix pass] A BARE family name with NO role suffix - `item="vaginal"`, `"oralpenis"`,
        // `"spanking"` - is what the model really sends (playtest 7, 17:12:51 and 17:18:15). It used to
        // match nothing here and was DROPPED, which is silence, which is F1. The role now comes from the
        // sentence when it says anything, and otherwise from what these two bodies have installed.
        if (str_contains($t, ' ' . strtolower((string) $id) . ' ')) { $c = $pick((string) $id); return $finish($c, $assumed); }
    }
    foreach (lrgActPhrases() as [$rx, $act]) { // 2. the priority phrase table
        if (preg_match($rx, $t)) {
            [$fam, $role] = lrgActParse($act);
            if ($role !== '') { return $finish($act); }
            $c = $pick($fam);
            return $finish($c, $assumed);
        }
    }
    // 3. a named position IS the request. An unmistakable sex position ("do reverse cowgirl",
    //    "missionary") needs no verb at all; the ambiguous ones (spooning, carrying) only count as sex
    //    when a sex verb is in the same breath - "spoon me" is an embrace, "lets do it spooning" is not.
    //    This runs before the synonym pass, which used to send "lets do it spooning" to hold.
    if ($pos !== '' && (in_array($pos, LRG_POS_SEXUAL, true) || $sexVerb)) {
        return $finish($pos === 'facesitting' ? 'facesitting:npc' : 'vaginal');
    }
    $byWord = []; // 4. the families' own spoken words, longest phrase first
    foreach (lrgActsTable() as $id => $f) {
        foreach ((array) ($f['words'] ?? []) as $w) { $byWord[strtolower((string) $w)][] = (string) $id; }
    }
    $keys = array_keys($byWord);
    usort($keys, static fn($a, $b) => strlen($b) <=> strlen($a));
    foreach ($keys as $w) {
        if (str_contains($t, ' ' . $w . ' ')) {
            foreach ($byWord[$w] as $fam) {
                $p = $pick($fam);
                $cand = $finish($p, $assumed);
                if ($cand !== '' && (!$available || isset($have[lrgActBase($cand)]))) { return $cand; }
            }
            $p = $pick($byWord[$w][0]);
            $c = $finish($p, $assumed);
            if (!$available && $c !== '') { return $c; }
        }
    }
    // 5. the existing synonym vocabulary: a phrase whose targets name one of a family's match globs.
    //    LRG_ACT_WEAK_SYNONYMS is skipped here: those keys exist so the free-text SCENE search can find
    //    a position from plain words, and turning them into a high-confidence ACT made "can you feel
    //    that?" a grope and "I love you" a request for sex - both of which the safety net would carry
    //    out. The scene search still reaches them, at the ordinary confidence.
    $syn = array_change_key_case((array) (lrgConfig()['scene_index']['synonyms'] ?? []), CASE_LOWER);
    $sk = array_keys($syn);
    usort($sk, static fn($a, $b) => strlen((string) $b) <=> strlen((string) $a));
    foreach ($sk as $key) {
        if (in_array((string) $key, LRG_ACT_WEAK_SYNONYMS, true)) { continue; }
        if (!str_contains($t, ' ' . strtolower((string) $key) . ' ')) { continue; }
        foreach ((array) $syn[$key] as $target) {
            $fam = lrgActFamily((string) $target);
            if ($fam === '') { continue; }
            $p = $pick($fam);
            $cand = $finish($p, $assumed);
            if ($cand !== '' && (!$available || isset($have[lrgActBase($cand)]))) { return $cand; }
        }
    }
    // 6. a gentle position on its own: spooning and being carried are an embrace when nothing else said sex
    if ($pos === 'spooning' || $pos === 'carrying') { return $finish('hold'); }
    return '';
}

/**
 * [0.3.1] The utterance with every LRG_ACT_WEAK_SYNONYMS word removed. Handed to the recogniser's
 * last-resort scene search so a single incidental word cannot make an ordinary sentence a request:
 * "can you feel that?" scored a groping scene only because scene_index.synonyms maps "feel" onto the
 * groping actions for the FREE-TEXT search, where an incidental hit costs nothing.
 */
function lrgActStripWeak(string $text): string
{
    $t = ' ' . trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[^a-z0-9\']+/', ' ', strtolower($text)))) . ' ';
    $weak = LRG_ACT_WEAK_SYNONYMS;
    usort($weak, static fn($a, $b) => strlen((string) $b) <=> strlen((string) $a));
    foreach ($weak as $w) {
        while (str_contains($t, ' ' . $w . ' ')) { $t = str_replace(' ' . $w . ' ', ' ', $t); }
    }
    return trim($t);
}

/**
 * [0.3.1] The STRONG half of lrgMatchAct(): only an exact act id or a priority phrase counts.
 * Used to veto the clothing branch ("put your mouth on my cock" was read as ChangeClothing at high
 * confidence and really re-dressed her, pt7 defect D2) and to decide the confidence of a bare request.
 */
function lrgMatchActStrong(string $text, ?array $sexes = null): string
{
    $t = lrgActNormalise($text);
    if (trim($t) === '') { return ''; }
    foreach (lrgActsTable() as $id => $f) {
        foreach (lrgActIds((string) $id) as $full) {
            if (str_contains($t, ' ' . strtolower($full) . ' ')) { return lrgMatchAct($text, [], $sexes); }
        }
    }
    foreach (lrgActPhrases() as [$rx, $act]) {
        if (preg_match($rx, $t)) { return lrgMatchAct($text, [], $sexes); }
    }
    $pos = lrgMatchPosition($t, (bool) preg_match('/\b(fuck\w*|screw|sex|shag|bang|pound|rail|hump|penetrat\w*|take me|ride|riding)\b/', $t));
    if ($pos !== '' && in_array($pos, LRG_POS_SEXUAL, true)) { return lrgMatchAct($text, [], $sexes); }
    return '';
}

/**
 * G5.2: the acts that are really open from here - reachable, fitting the sexes, the furniture and the tier
 * ceiling - grouped by act id with the BEST scene per act. Same walk and the same filters lrgSceneOptions()
 * uses, so an act can never offer something a position could not.
 * Returns [actId => ['act','name','tier','scene','hops','label']].
 */
function lrgActOptions(string $current, array $live, int $maxTier, ?array $sexes, string $furn, int $npcSlot, array $avoid = [], int $max = 8): array
{
    $cur = lrgScene($current);
    if (!$cur) { return []; }
    $assumed = false; $assumedCount = 0;
    $curActs = array_flip(lrgSceneActs($cur, $npcSlot, $sexes, $assumed));
    $avoidMap = array_flip(array_map('strtolower', $avoid));
    $best = []; $fresh = 0;
    foreach (lrgSceneWalk($current, $live, $maxTier, 3, $sexes, $furn) as $w) {
        $d = $w['scene'];
        $a2 = false;
        $acts = lrgSceneActs($d, $npcSlot, $sexes, $a2);
        if ($a2) { $assumedCount++; }
        $seen = isset($avoidMap[strtolower((string) $d['id'])]);
        foreach ($acts as $act) {
            if (isset($curActs[$act])) { continue; } // what is already happening is not an offer
            // [0.3.1 / D13] a niche family is never OFFERED; the player can still ask for it by name
            if (!empty(lrgActsTable()[explode(':', $act)[0]]['niche'])) { continue; }
            $rank = [$seen ? 1 : 0, (int) $w['hops'], lrgSceneNameFits($d, $sexes) ? 0 : 1, !empty($d['taste']) ? 1 : 0];
            if (isset($best[$act]) && $best[$act]['_rank'] <= $rank) { continue; }
            if (!isset($best[$act]) && !$seen) { $fresh++; }
            $best[$act] = ['act' => $act, 'name' => lrgActLabel($act), 'tier' => (string) $d['tier'], 'scene' => (string) $d['id'],
                'hops' => (int) $w['hops'], 'label' => lrgDescribeScene($d), '_rank' => $rank, '_seen' => $seen];
        }
    }
    $GLOBALS['LRG_ACTS_ASSUMED'] = $assumedCount;
    if ($fresh === 0) { foreach ($best as &$b) { $b['_seen'] = false; } unset($b); } // everything visited: no penalty
    $curTier = LRG_TIERS[$cur['tier']];
    $list = array_values($best);
    usort($list, static fn($a, $b) => [$a['_seen'] ? 1 : 0, abs(LRG_TIERS[$a['tier']] - $curTier), $a['hops'], $a['act']]
        <=> [$b['_seen'] ? 1 : 0, abs(LRG_TIERS[$b['tier']] - $curTier), $b['hops'], $b['act']]);
    // the next step must stay visible: up to a third of the slots are reserved for the ceiling tier
    $step = $maxTier > $curTier ? array_slice(array_values(array_filter($list, static fn($o) => LRG_TIERS[$o['tier']] === $maxTier)), 0, max(1, intdiv($max, 3))) : [];
    $stepActs = array_column($step, 'act');
    $rest = array_values(array_filter($list, static fn($o) => !in_array($o['act'], $stepActs, true)));
    $out = [];
    foreach (array_slice(array_merge($step, $rest), 0, $max) as $o) {
        unset($o['_rank'], $o['_seen']);
        // [0.3.1 / R2-D5] the positions this act really has installed for these two, so the notes can
        // offer "vaginal/reversecowgirl" and RequestAct can be answered with the position that was named
        $o['positions'] = lrgActPositions((string) $o['act'], $sexes, $furn, $npcSlot, (int) $cur['actors']);
        $out[$o['act']] = $o;
    }
    return $out;
}

// ---------------------------------------------------------------- start scenes and furniture
/**
 * [v1] A gentle two-actor starting scene (kiss / embrace), preferring well-connected hubs.
 * No furniture: a standing, face-to-face scene. On furniture: a scene OStim can play there (the type itself
 * or a supertype); on a bed the floor scenes are valid too, but nobody stands on a mattress, so only
 * sitting / lying ones are taken. '' = nothing suitable.
 */
function lrgPickStartScene(?array $sexes = null, ?string $furniture = null): string
{
    $c = lrgConfig()['scene_index'] ?? [];
    $tiers = $c['start_tiers'] ?? ['kissing', 'affection'];
    $ftype = strtolower(trim((string) $furniture));
    $onFurniture = $furniture !== null && !in_array($ftype, ['', 'none'], true);
    $best = ''; $bestScore = -INF;
    foreach (lrgSceneIndex()['scenes'] as $s) {
        if ($s['excluded'] || $s['transition'] || $s['actors'] !== 2 || !empty($s['special']) || !empty($s['taste']) || !in_array($s['tier'], $tiers, true)) { continue; }
        $hasFurn = $s['furniture'] !== '' && $s['furniture'] !== 'none';
        $standing = in_array('standing', $s['actor_tags'], true);
        if ($onFurniture) {
            if (!lrgSceneFurnitureOk($s, $ftype) || (!$hasFurn && $standing)) { continue; }
        } elseif ($hasFurn || !$standing) {
            continue;
        }
        if (!lrgSceneSexOk($s, $sexes)) { continue; }
        // a face-to-face kiss or embrace that leads somewhere; OARE preferred (primary pack)
        $score = min(count($s['to']), 6)
            + ($s['tier'] === 'kissing' ? 40 : 0)
            + (stripos($s['pack'], 'Open Animations') !== false ? 15 : 0)
            + ($hasFurn ? 20 : 0)                                   // authored for this furniture: alignment is certain
            + ($hasFurn && $s['furniture'] === $ftype ? 4 : 0)
            - (lrgSceneNameFits($s, $sexes) ? 0 : 60)
            - (stripos($s['id'] . $s['name'], 'behind') !== false || in_array('facingaway', $s['actor_tags'], true) ? 25 : 0)
            - (array_intersect($s['actor_tags'], ['suspended']) ? 30 : 0)
            - (count($s['to']) === 0 ? 50 : 0);
        if ($score > $bestScore) { $bestScore = $score; $best = $s['id']; }
    }
    return $best; // '' = let OStim choose its default idle
}

/** Lowercased, de-duplicated furniture types as the game sent them (closest first); 'none' / '' dropped. */
function lrgNormFurnitureList(array $near): array
{
    $out = [];
    foreach ($near as $t) {
        $t = strtolower(trim((string) $t));
        if ($t !== '' && $t !== 'none' && !in_array($t, $out, true)) { $out[] = $t; }
    }
    return $out;
}

/**
 * [NEW] What BeginIntimacy starts on. 'scene' is always the standing no-furniture start (the game's fallback
 * when it finds no free furniture ref). 'furn' + 'fscene' are set only when a start-tier scene exists for a
 * furniture type in reach: scene_start.prefer_furniture = never | bed (default: beds only) | any (beds first,
 * then the closest other type).
 */
function lrgPickStart(?array $sexes, array $nearFurniture = [], string $actId = ''): array
{
    $out = ['scene' => lrgPickStartScene($sexes), 'furn' => '', 'fscene' => '', 'after' => '', 'act' => $actId];
    $mode = strtolower(trim((string) (lrgConfig()['scene_start']['prefer_furniture'] ?? 'bed')));
    $near = lrgNormFurnitureList($nearFurniture);
    // [0.3.1 / R2-D10, w1] START IN WHAT HE ASKED FOR. lrgPickStart() never read the request, so
    // "I want you to ride me" began at the standing kiss (pt7 F4). OStim can start in ANY two-actor
    // scene - OThreadBuilder.SetStartingAnimation has no intro flag and no route requirement - so the
    // only real conditions are the actor count, the furniture and our own rails. The owner removed the
    // forced gentle lead-in, so the requested act's own scene is the start whenever one exists.
    if ($actId !== '' && (lrgConfig()['scene_start']['start_in_requested_act'] ?? true)) {
        $free = lrgFindActScene($actId, $sexes, '', LRG_TIERS['sexual'], [], '', 1, 2, true)
            // the act exists but not in the position he named: start in the act, land the position after
            ?? lrgFindActScene(lrgActBase($actId), $sexes, '', LRG_TIERS['sexual'], [], '', 1, 2, true);
        if ($free !== null) { $out['scene'] = $free['id']; }
    }
    if ($mode !== 'never' && $near) {
        $beds = array_values(array_filter($near, 'lrgIsBedFamily'));
        $order = $mode === 'any' ? array_merge($beds, array_values(array_diff($near, $beds))) : $beds;
        foreach ($order as $type) {
            // on furniture the requested act wins there too, when the packs have it for that type
            $id = $actId !== '' ? (string) (lrgFindActScene($actId, $sexes, $type, LRG_TIERS['sexual'], [], '', 1, 2)['id'] ?? '') : '';
            // the v1 rule still holds: a floor scene may play on a bed, but nobody stands on a mattress
            if ($id !== '' && ($s = lrgScene($id)) !== null) {
                $hasFurn = $s['furniture'] !== '' && $s['furniture'] !== 'none';
                if (!$hasFurn && in_array('standing', (array) $s['actor_tags'], true)) { $id = ''; }
            }
            if ($id === '') { $id = lrgPickStartScene($sexes, $type); }
            if ($id !== '') { $out['furn'] = $type; $out['fscene'] = $id; break; }
        }
    }
    return lrgStartAfter($out, $actId, $sexes);
}

/**
 * [0.3.1 / w1] The additive `after=` key: the scene to navigate to a few seconds after the start, set
 * only when the scene the thread really begins on does NOT already carry the requested act (the
 * furniture start won, or only a gentle scene was startable here). An old game script ignores the key
 * and simply starts where it always did, which is why this is wire-safe in both directions.
 */
function lrgStartAfter(array $start, string $actId, ?array $sexes): array
{
    $start['after'] = '';
    if ($actId === '') { return $start; }
    $begins = (string) (($start['fscene'] ?? '') !== '' ? $start['fscene'] : ($start['scene'] ?? ''));
    $s = $begins !== '' ? lrgScene($begins) : null;
    if ($s !== null) {
        [$fam, $role, $pos] = lrgActParse($actId);
        $acts = lrgSceneActs($s, 1, $sexes);
        $okAct = in_array($fam . ($role !== '' ? ':' . $role : ''), $acts, true) || in_array($fam, $acts, true);
        $okPos = $pos === '' || in_array($pos, lrgScenePositions($s), true);
        if ($okAct && $okPos) { return $start; }
    }
    $furn = (string) ($start['furn'] ?? '');
    $hit = lrgFindActScene($actId, $sexes, $furn, LRG_TIERS['sexual'], [], $begins, 1, 2)
        ?? lrgFindActScene(lrgActBase($actId), $sexes, $furn, LRG_TIERS['sexual'], [], $begins, 1, 2);
    if ($hit !== null) { $start['after'] = (string) $hit['id']; }
    return $start;
}

function lrgFurnitureLabel(string $type): string
{
    $labels = array_change_key_case((array) (lrgConfig()['scene_index']['furniture_labels'] ?? []), CASE_LOWER) + LRG_FURN_LABELS;
    foreach (lrgFurnitureChain($type) as $t) { if (isset($labels[$t])) { return (string) $labels[$t]; } }
    return 'the ' . $type;
}

/** Spoken words that mean this furniture type: its own entry, else that of the nearest supertype (doublebed -> bed). */
function lrgFurnitureWords(string $type): array
{
    $words = array_change_key_case((array) (lrgConfig()['scene_index']['furniture_words'] ?? []), CASE_LOWER) + LRG_FURN_WORDS;
    foreach (lrgFurnitureChain($type) as $t) {
        if (!empty($words[$t])) { return array_values(array_unique(array_map(fn($w) => strtolower((string) $w), (array) $words[$t]))); }
    }
    return [strtolower($type)];
}

/**
 * [NEW] Furniture the pair could move to now: [type => ['furn','label','words','scene','tier']].
 * Never the thread's own type or family, never a scene above $maxTier, always WITH a scene id (an empty id
 * would let OStim pick a random scene and skip the ladder). The scene keeps the mood: the current scene
 * itself when it can play there (floor scene -> bed), otherwise the closest tier at or below the current one.
 */
function lrgFurnitureOptions(array $nearFurniture, string $current, ?array $sexes, int $maxTier, string $threadFurniture): array
{
    $cur = lrgScene($current);
    $curTier = $cur ? LRG_TIERS[$cur['tier']] : LRG_TIERS['kissing'];
    $actors = $cur ? (int) $cur['actors'] : 2;
    $thread = strtolower(trim($threadFurniture));
    $threadChain = in_array($thread, ['', 'none'], true) ? [] : lrgFurnitureChain($thread);
    $neutralActs = ['default', 'audiblebreathing', 'spectating', 'holdingbody', 'holdinghand', 'holdinghead', 'holdinghip', 'holdingarm'];
    $out = []; $labels = [];
    foreach (lrgNormFurnitureList($nearFurniture) as $type) {
        $chain = lrgFurnitureChain($type);
        if ($threadChain && (in_array($type, $threadChain, true) || in_array($thread, $chain, true) || array_diff(array_intersect($chain, $threadChain), ['none']))) { continue; }
        $label = lrgFurnitureLabel($type);
        if (isset($labels[$label])) { continue; } // two beds in reach are one spoken option: the closest
        $isBed = in_array('bed', $chain, true);
        $best = null; $bestScore = -INF;
        foreach (lrgSceneIndex()['scenes'] as $k => $s) {
            if ($s['excluded'] || $s['transition'] || (int) $s['actors'] !== $actors || !empty($s['special']) || LRG_TIERS[$s['tier']] > $maxTier) { continue; }
            if (!empty($s['taste']) && $k !== strtolower($current)) { continue; } // a move to furniture never introduces a niche scene by itself
            if (!lrgSceneFurnitureOk($s, $type)) { continue; }
            $hasFurn = $s['furniture'] !== '' && $s['furniture'] !== 'none';
            if (!$hasFurn && (!$isBed || in_array('standing', $s['actor_tags'], true))) { continue; }
            if (in_array('climaxing', $s['actor_tags'], true) || !lrgSceneSexOk($s, $sexes)) { continue; }
            $t = LRG_TIERS[$s['tier']];
            $score = ($cur && $k === strtolower($current) ? 100 : 0)
                - 8 * abs($t - $curTier) - ($t > $curTier ? 6 : 0)      // keep the mood; moving is not a step up
                + ($cur ? 4 * count(array_intersect($s['actions'], array_diff($cur['actions'], $neutralActs))) + 2 * count(array_intersect($s['tags'], array_diff($cur['tags'], LRG_NOISE_TAGS))) : 0) // same act, new place
                + ($hasFurn ? 6 : 0) + min(count($s['to']), 6)
                + (stripos($s['pack'], 'Open Animations') !== false ? 3 : 0)
                - (lrgSceneNameFits($s, $sexes) ? 0 : 20);
            if ($score > $bestScore) { $bestScore = $score; $best = $s; }
        }
        if ($best === null) { continue; }
        $labels[$label] = true;
        $out[$type] = ['furn' => $type, 'label' => $label, 'words' => lrgFurnitureWords($type), 'scene' => $best['id'], 'tier' => $best['tier']];
    }
    return $out;
}

// ---------------------------------------------------------------- wind-down
/**
 * Afterglow: the best cuddle / embrace scene reachable from $current (same filters as the options,
 * routing through any tier because the pair is coming down from one). Order of preference: a cuddle scene
 * (afterglow_patterns) on the route, any gentle scene on the route, then - scene_index.afterglow_allow_unrouted,
 * default true - the best gentle scene (cuddles first) this pair can be in on this furniture even without a
 * route (the game warps). On a bed, floor scenes in which someone stands are avoided. '' = nothing suitable
 * (wind down in place), or already in a cuddle scene.
 */
function lrgPickAfterglowScene(string $current, array $liveReachable, ?array $sexes = null, ?string $furniture = null): string
{
    return lrgPickAfterglow($current, $liveReachable, $sexes, $furniture)['scene'];
}

/** As lrgPickAfterglowScene, with the route facts: ['scene' => id|'', 'hops' => n (0 = no route, warp), 'routed' => bool]. */
function lrgPickAfterglow(string $current, array $liveReachable, ?array $sexes = null, ?string $furniture = null): array
{
    $c = lrgConfig()['scene_index'] ?? [];
    $none = ['scene' => '', 'hops' => 0, 'routed' => false];
    $pats = $c['afterglow_patterns'] ?? ['*cuddl*', '*spooning*', '*embrace*', '*lappillow*', '*hug*'];
    $isGentle = fn(array $s): bool => LRG_TIERS[$s['tier']] <= LRG_TIERS['kissing'] && LRG_TIERS[$s['tier']] >= LRG_TIERS['affection'];
    $isCuddle = fn(array $s): bool => $isGentle($s) && lrgAnyGlob($pats, array_merge($s['tags'], $s['actions'], [strtolower($s['id']), strtolower($s['name'])]));
    $cur = lrgScene($current);
    if (!$cur || $isCuddle($cur)) { return $none; }
    $lyingTags = ['lyingback', 'lyingfront', 'lyingside', 'lying'];
    $lying = (bool) array_intersect($cur['actor_tags'], array_merge($lyingTags, ['allfours']));
    $onBed = $furniture !== null && lrgIsBedFamily($furniture);
    $rate = function (array $s, int $hops) use ($isCuddle, $lying, $lyingTags, $onBed, $sexes): float {
        $sLying = (bool) array_intersect($s['actor_tags'], $lyingTags);
        return -20 * $hops
            + ($isCuddle($s) ? 60 : 0)                              // a real cuddle beats any other gentle scene, even one hop further
            + ($sLying === $lying ? 15 : 0)                         // stay lying down / stay standing: no awkward get-up
            + ($onBed && $sLying ? 10 : 0)
            - ($onBed && in_array('standing', $s['actor_tags'], true) && ($s['furniture'] === '' || $s['furniture'] === 'none') ? 40 : 0)
            + (in_array('cuddling', $s['tags'], true) ? 10 : 0)
            + ($s['tier'] === 'affection' ? 5 : 0)
            - (lrgSceneNameFits($s, $sexes) ? 0 : 30)
            - (array_intersect($s['actor_tags'], ['suspended']) ? 25 : 0)
            - (stripos($s['id'], 'behind') !== false ? 3 : 0);
    };
    $best = $none; $bestScore = -INF;
    foreach (lrgSceneWalk($current, $liveReachable, LRG_TIERS['sexual'], (int) ($c['afterglow_max_hops'] ?? 4), $sexes, $furniture) as $w) {
        if (!$isGentle($w['scene']) || !empty($w['scene']['taste'])) { continue; }
        $score = $rate($w['scene'], $w['hops']);
        if ($score > $bestScore) { $bestScore = $score; $best = ['scene' => $w['scene']['id'], 'hops' => $w['hops'], 'routed' => true]; }
    }
    if ($best['scene'] !== '' || !($c['afterglow_allow_unrouted'] ?? true) || $isGentle($cur)) { return $best; }
    foreach (lrgSceneIndex()['scenes'] as $k => $s) { // no route inside the reach: the best gentle scene this pair can be in here (the game warps); $rate puts cuddles first
        if ($s['excluded'] || $s['transition'] || (int) $s['actors'] !== (int) $cur['actors'] || !empty($s['special']) || !empty($s['taste']) || !$isGentle($s)) { continue; }
        if (!lrgSceneSexOk($s, $sexes) || !lrgSceneFurnitureOk($s, $furniture)) { continue; }
        $score = $rate($s, 0) + min(count($s['to']), 6);
        if ($score > $bestScore) { $bestScore = $score; $best = ['scene' => $s['id'], 'hops' => 0, 'routed' => false]; }
    }
    return $best;
}

// ---------------------------------------------------------------- positions by name
/** Id / name words that say nothing about the position. */
const LRG_NOISE_WORDS = ['oare', 'ostim', 'ocr', 'mf', 'fm', 'ff', 'mm', 'idle', 'left', 'right', 'go', 'to', 'the', 'and', 'on', 'of', 'in', 'from', 'with', 'both', 'out', 'up',
    'ace', 'simple', 'hub', 'intro', 'outro', 'male', 'female', 'performer', 'alt', 'var', 'variant', 'new', 'old', 'back'];
/** Scene / action tags that are bookkeeping, not vocabulary. */
const LRG_NOISE_TAGS = ['oare', 'idle', 'intro', 'transition', 'action', 'sexual', 'sensual', 'romantic', 'seductive', 'default'];

/** Searchable words of one scene: [word => weight]. Scene tags, actions and action tags 3, actor tags 2.5, id / name words 2. */
function lrgSceneWords(array $s): array
{
    $k = strtolower($s['id']);
    if (isset($GLOBALS['LRG_INDEX_DERIVED']['words'][$k])) { return $GLOBALS['LRG_INDEX_DERIVED']['words'][$k]; }
    $w = [];
    foreach (lrgIdWords($s['id'] . ' ' . ($s['name'] !== '' && $s['name'][0] !== '$' ? $s['name'] : '')) as $x) { $w[$x] = 2; }
    foreach ($s['actor_tags'] as $x) { $w[$x] = max($w[$x] ?? 0, 2.5); }
    $acts = lrgSceneIndex()['actions'] ?? [];
    foreach ($s['actions'] as $a) {
        $w[$a] = 3;
        foreach (($acts[$a]['tags'] ?? []) as $t) { $t = ltrim($t, '-'); $w[$t] = 3; }
    }
    foreach ($s['tags'] as $x) { $w[$x] = 3; }
    foreach (array_merge(LRG_NOISE_WORDS, LRG_NOISE_TAGS) as $x) { if (!in_array($x, $s['actions'], true)) { unset($w[$x]); } }
    return $GLOBALS['LRG_INDEX_DERIVED']['words'][$k] = $w;
}

/** Request text -> list of term groups (one group per meaningful word / phrase; any term of a group may match). */
function lrgTextTermGroups(string $text): array
{
    $c = lrgConfig()['scene_index'] ?? [];
    $syn = array_change_key_case((array) ($c['synonyms'] ?? []), CASE_LOWER);
    $t = ' ' . trim((string) preg_replace('/[^a-z0-9]+/', ' ', strtolower($text))) . ' ';
    $groups = [];
    $phrases = array_filter(array_keys($syn), fn($k) => strpos((string) $k, ' ') !== false);
    usort($phrases, fn($a, $b) => strlen($b) <=> strlen($a)); // longest phrase first ("on top of" before "on top")
    foreach ($phrases as $key) {
        if (strpos($t, " $key ") !== false) { $groups[] = array_map('strtolower', (array) $syn[$key]); $t = str_replace(" $key ", ' ', $t); }
    }
    $stop = array_flip((array) ($c['stopwords'] ?? ['the', 'a', 'an', 'me', 'my', 'you', 'your', 'on', 'in', 'to', 'let', 'lets', 'us', 'do', 'go', 'into', 'position', 'want', 'i', 'please', 'now', 'some',
        'it', 'with', 'and', 'for', 'move', 'switch', 'change', 'try', 'can', 'we', 'get', 'of', 'up', 'down', 'from', 'at', 'this', 'that', 'her', 'him', 'his', 'while', 'then', 'more', 'one', 'style', 'p', 's', 't',
        'out', 'off', 'over', 'under', 'against', 'like', 'just', 'there', 'here', 'make', 'give', 'take', 'put', 'have', 'would', 'could', 'should', 'will', 'how', 'about', 'what', 'little', 'bit', 'again',
        'so', 'is', 'are', 'be', 'im', 'ill', 'id', 'll', 'm', 'd', 're', 've', 'am', 'as', 'if', 'or', 'but', 'by', 'them', 'they', 'she', 'he', 'our', 'mine', 'yours', 'time', 'way', 'around', 'really', 'first', 'before', 'after']));
    foreach (array_filter(explode(' ', $t), 'strlen') as $word) {
        if (isset($stop[$word])) { continue; }
        $groups[] = isset($syn[$word]) ? array_map('strtolower', (array) $syn[$word]) : [$word];
    }
    return $groups;
}

/**
 * What tier a term group ASKS for. 'hard' = the lowest tier of the acts it names (an act word such as an oral
 * or penetrative one can only mean a scene of that tier: lower scenes are no answer); 'soft' = a position tag
 * that mostly lives on one tier (missionary -> sexual): a preference only. null = says nothing about the tier.
 */
function lrgGroupIntent(array $terms): array
{
    $idx = lrgSceneIndex();
    if (!isset($GLOBALS['LRG_INDEX_DERIVED']['intent'])) {
        $byTag = []; $sceneTag = [];
        foreach (($idx['actions'] ?? []) as $a => $def) {
            foreach ($def['tags'] as $t) { $t = ltrim($t, '-'); if (!in_array($t, LRG_NOISE_TAGS, true)) { $byTag[$t] = min($byTag[$t] ?? 9, (int) $def['tier']); } }
        }
        // position words: scene tags (missionary, cowgirl ...) and the unmistakable staging postures; plain postures
        // (standing, sitting, lying, kneeling) say nothing about the tier and keep the search near the current scene
        $staging = array_map('strtolower', (array) (lrgConfig()['scene_index']['sensual_actor_tags_strict'] ?? ['allfours', 'bendover', 'spreadlegs']));
        foreach ($idx['scenes'] as $s) {
            if ($s['excluded'] || $s['transition']) { continue; }
            foreach (array_merge(array_diff($s['tags'], LRG_NOISE_TAGS), array_intersect($s['actor_tags'], $staging)) as $t) { $sceneTag[$t][] = LRG_TIERS[$s['tier']]; }
        }
        $soft = [];
        foreach ($sceneTag as $t => $tiers) { $n = array_count_values($tiers); arsort($n); $soft[$t] = (int) array_key_first($n); }
        $GLOBALS['LRG_INDEX_DERIVED']['intent'] = ['tag' => $byTag, 'soft' => $soft];
    }
    $d = $GLOBALS['LRG_INDEX_DERIVED']['intent'];
    $pseudo = ['@sexual' => 4, '@sensual' => 3, '@kissing' => 2, '@affection' => 1, '@gentle' => 1];
    $hard = null; $soft = null;
    foreach ($terms as $t) {
        $h = $pseudo[$t] ?? (isset($idx['actions'][$t]) ? (int) $idx['actions'][$t]['tier'] : ($d['tag'][$t] ?? null));
        if ($h !== null) { $hard = $hard === null ? $h : min($hard, $h); }
        if (isset($d['soft'][$t])) { $soft = $soft === null ? $d['soft'][$t] : max($soft, $d['soft'][$t]); }
    }
    return ['hard' => $hard, 'soft' => $soft];
}

/** Two-actor (or $actors) scenes this pair can be in on this furniture: the pool every text search scans. Cached per request. */
function lrgSearchPool(int $actors, ?array $sexes, string $furniture): array
{
    $key = $actors . '|' . implode(',', lrgNormSexes($sexes) ?? ['*']) . '|' . strtolower(trim($furniture));
    if (isset($GLOBALS['LRG_INDEX_DERIVED']['pool'][$key])) { return $GLOBALS['LRG_INDEX_DERIVED']['pool'][$key]; }
    $pool = [];
    foreach (lrgSceneIndex()['scenes'] as $k => $s) {
        if ($s['excluded'] || $s['transition'] || (int) $s['actors'] !== $actors) { continue; }
        if (!lrgSceneSexOk($s, $sexes) || !lrgSceneFurnitureOk($s, $furniture)) { continue; }
        $pool[] = $k;
    }
    return $GLOBALS['LRG_INDEX_DERIVED']['pool'][$key] = $pool;
}

/**
 * Free-text position search over the WHOLE index ("missionary", "sit on my lap", "from behind").
 * Only scenes this pair can really be in: same actor count as $current, sexes, furniture (the thread's type
 * or a supertype of it), not excluded, not a transition; scenes with an unprovable requirement only when
 * $prefer (= routable right now) names them.
 * Returns ['id','label','tier','score'], or ['too_soon' => true, 'tier' => ..] when what was asked for only
 * exists above $maxTier (an act word never resolves to a lower scene that merely shares a word; a scene under
 * the ceiling only wins when it covers as many of the asked words as the best one above it), or null.
 * $prefer = lowercase ids that are routable right now (they win ties).
 */
function lrgFindSceneByText(string $text, string $current, ?array $sexes, string $furniture, int $maxTier, array $prefer = [], bool $requireCore = false): ?array
{
    $groups = lrgTextTermGroups($text);
    if (!$groups) { return null; }
    $idx = lrgSceneIndex()['scenes'];
    $cur = lrgScene($current);
    $actors = $cur ? (int) $cur['actors'] : 2;
    $curTier = $cur ? LRG_TIERS[$cur['tier']] : 2;
    $curK = $cur ? strtolower($current) : '';
    $prefer = array_flip(array_map('strtolower', $prefer));
    $pseudoTier = ['@sexual' => [4, 4], '@sensual' => [3, 3], '@kissing' => [2, 2], '@affection' => [1, 1], '@gentle' => [1, 2], '@any' => [0, 4]];
    $wantsClimax = (bool) preg_match('/\b(climax|finish|cum|come)\b/', strtolower($text));
    $hardIntent = null; $softIntent = null; $core = [];
    foreach ($groups as $gi => $terms) {
        $i = lrgGroupIntent($terms);
        if ($i['hard'] !== null) { $hardIntent = max($hardIntent ?? 0, $i['hard']); }
        if ($i['soft'] !== null) { $softIntent = max($softIntent ?? 0, $i['soft']); }
        if ($i['hard'] !== null || $i['soft'] !== null) { $core[$gi] = true; } // names an act or a position, not just a posture
    }
    // [0.3.1] $requireCore: the recogniser's last-resort fallback must not turn an ordinary sentence
    // into a request just because one of its words happens to appear in some scene's vocabulary.
    // "can you feel that?" scored a groping scene on the word "feel" and became a DO-IT order.
    if ($requireCore && !$core) { return null; }
    // niche scenes (the taste lists of the "standard" level) are only candidates when the request names the niche itself:
    // "all fours" must never land on a foot or spanking scene just because that scene is also on all fours
    $c = lrgConfig()['scene_index'] ?? [];
    $tastePatterns = array_merge((array) ($c['excluded_tags'] ?? []), (array) ($c['excluded_actions'] ?? []), (array) ($c['excluded_id_patterns'] ?? []));
    $asksNiche = $tastePatterns && lrgAnyGlob($tastePatterns, array_merge(...array_values($groups)));
    // pass 1: which groups does each scene answer, and how well
    $hits = []; $known = array_fill(0, count($groups), false); $curRow = [];
    // the CURRENT scene is scored too (R1): if it answers the request as well as anything else, the
    // answer is "nothing changes" - naming the position you are already in must not move the pair
    $pool = lrgSearchPool($actors, $sexes, $furniture);
    if ($curK !== '' && isset($idx[$curK]) && !in_array($curK, $pool, true)) { $pool[] = $curK; }
    foreach ($pool as $k) {
        $s = $idx[$k];
        if ($k !== $curK && !empty($s['special']) && !isset($prefer[$k])) { continue; }
        if ($k !== $curK && !empty($s['taste']) && !$asksNiche) { continue; }
        $words = lrgSceneWords($s);
        $row = [];
        foreach ($groups as $gi => $terms) {
            $g = 0.0; $n = 0;
            foreach ($terms as $term) {
                if (isset($pseudoTier[$term])) {
                    [$lo, $hi] = $pseudoTier[$term];
                    if (LRG_TIERS[$s['tier']] >= $lo && LRG_TIERS[$s['tier']] <= $hi) { $g = max($g, 1.0); $n++; }
                    continue;
                }
                if (isset($words[$term])) { $g = max($g, $words[$term]); $n++; continue; }
                if (strlen($term) < 4) { continue; }
                foreach ($words as $w => $wt) { if (strncmp($w, $term, strlen($term)) === 0) { $g = max($g, $wt - 0.5); $n++; break; } }
            }
            if ($g > 0) { $row[$gi] = $g + 0.5 * ($n - 1); $known[$gi] = true; } // named AND tagged that way beats tagged only
        }
        if ($row) { if ($k === $curK) { $curRow = $row; } else { $hits[$k] = $row; } }
    }
    $nKnown = count(array_filter($known)); // words no installed scene knows ("chandelier") are ignored, not held against a match
    if ($nKnown === 0) { return null; }
    // pass 2: score
    $best = null; $bestScore = -INF; $bestCover = 0; $blocked = null; $blockedScore = -INF; $blockedCover = 0;
    $curCover = 0; $curScore = -INF;
    if ($curRow) { $hits[$curK] = $curRow; } // scored with everything else, then judged separately
    foreach ($hits as $k => $row) {
        $s = $idx[$k];
        $cover = count($row);
        $tier = LRG_TIERS[$s['tier']];
        if ($cover * 2 < $nKnown) { continue; }
        if ($core && !array_intersect_key($row, $core)) { continue; }  // "<act> standing" is not answered by any standing scene
        if ($hardIntent !== null && $tier < $hardIntent) { continue; } // an after-scene idle is no answer to an act word
        $target = $hardIntent ?? $softIntent;
        $score = array_sum($row)
            + (isset($prefer[$k]) ? 1.5 : 0)
            + (stripos($s['pack'], 'Open Animations') !== false || stripos($s['id'], 'OARE') === 0 ? 0.5 : 0)
            - ($target !== null ? 0.6 * abs($tier - $target) + 0.1 * abs($tier - $curTier) : 0.35 * abs($tier - $curTier))
            - (lrgSceneNameFits($s, $sexes) ? 0 : 2.0)
            - (!$wantsClimax && (in_array('climaxing', $s['actor_tags'], true) || stripos($s['id'] . $s['name'], 'climax') !== false) ? 3.0 : 0)
            - 0.02 * count(lrgSceneWords($s));
        if ($k === $curK) { $curCover = $cover; $curScore = $score; continue; }
        if ($tier > $maxTier) {
            if ($cover > $blockedCover || ($cover === $blockedCover && $score > $blockedScore)) { $blockedScore = $score; $blockedCover = $cover; $blocked = $s; }
            continue;
        }
        if ($cover > $bestCover || ($cover === $bestCover && $score > $bestScore)) { $bestScore = $score; $bestCover = $cover; $best = $s; }
    }
    // R1: if the scene they are ALREADY in answers the request at least as well as anything else - or the
    // winner is just another pack's version of the very same position - the answer is "nothing changes".
    // Naming the position you are in must never move the pair somewhere else.
    $curS = $curCover > 0 ? ($idx[$curK] ?? null) : null;
    if ($best && $bestCover >= $blockedCover) {
        if ($curCover >= $bestCover && ($curScore >= $bestScore || lrgSameShape($curS, $best))) { return null; }
        return ['id' => $best['id'], 'label' => lrgDescribeScene($best), 'tier' => $best['tier'], 'score' => round($bestScore, 2)];
    }
    if ($blocked && $curCover >= $blockedCover && ($curScore >= $blockedScore || lrgSameShape($curS, $blocked))) { return null; }
    return $blocked ? ['too_soon' => true, 'tier' => $blocked['tier']] : null;
}

/**
 * Two scenes are "the same position" when they are on the same tier and their acts and poses match:
 * different packs ship their own version of the same thing, and moving between those two is a fade
 * with nothing to show for it.
 */
function lrgSameShape(?array $a, ?array $b): bool
{
    if (!$a || !$b) { return false; }
    $skip = ['default', 'holdingbody', 'holdinghand', 'holdinghead', 'holdinghip', 'holdingarm', 'holdingleg'];
    $poses = ['standing', 'sitting', 'kneeling', 'lyingback', 'lyingfront', 'lyingside', 'allfours', 'squatting', 'bendover'];
    $acts = static function (array $s) use ($skip) { $x = array_values(array_diff($s['actions'] ?? [], $skip)); sort($x); return $x; };
    $pose = static function (array $s) use ($poses) { $x = array_values(array_intersect($s['actor_tags'] ?? [], $poses)); sort($x); return $x; };
    return ($a['tier'] ?? '') === ($b['tier'] ?? '') && $acts($a) === $acts($b) && $acts($a) !== [] && $pose($a) === $pose($b);
}

/** 6-10 plain category words that really lead somewhere for this pair under the tier ceiling (for the scene notes). */
function lrgPositionWords(string $current, ?array $sexes, string $furniture, int $maxTier, int $limit = 10): array
{
    $out = [];
    foreach ((array) (lrgConfig()['scene_index']['position_words'] ?? []) as $word) {
        $r = lrgFindSceneByText((string) $word, $current, $sexes, $furniture, $maxTier);
        if ($r && empty($r['too_soon'])) { $out[] = $word; }
        if (count($out) >= $limit) { break; }
    }
    return $out;
}

// ---------------------------------------------------------------- CLI entry: php lrg_scene_index.php warm [--force] | status
if (PHP_SAPI === 'cli' && isset($_SERVER['SCRIPT_FILENAME']) && realpath((string) $_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    exit(lrgIndexCli($_SERVER['argv'] ?? []));
}
