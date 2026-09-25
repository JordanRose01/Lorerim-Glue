<?php
/**
 * LoreRim Glue - audiofilterd supervisor.  [0.5.2 / PT9]
 *
 * WHY THIS EXISTS. CHIM 3.3.2 ships the CLIENT half of its own audio filter: `tts/tts-pockettts.php`
 * hands every synthesised line to `tts/audiofilterd_client.php`, which speaks JSON over the unix socket
 * `/var/www/html/HerikaServer/tts/audiofilterd.sock` and asks for `trim_start` 250 ms. The DAEMON half
 * was never shipped in this distro (research/pt9-tts-cpu.md §4: it exists nowhere on the box, in no
 * package, in no commit). So every single spoken line throws, is logged as
 * "Audio processing failed for PocketTTS response", and falls through to an ffmpeg re-encode whose
 * filter string is hard-coded EMPTY on the audio.cpp path - 0.25-0.31 s of dead air in front of every
 * line she says, plus two log lines and an ffmpeg spawn.
 *
 * The owner's instruction was "I want our mod to change that line instead of changing it through CHIM".
 * This is that: CHIM's files are untouched, and the half of CHIM's own feature that is missing is
 * supplied by us, on the socket CHIM already calls.
 *
 * THIS file is only the supervisor - the cheap "is it up?" check that globals.php runs on every request,
 * and the detached spawn when it is not. The daemon itself is lrg_audiofilterd.php and the audio work is
 * lrg_wav.php. Nothing here ever throws into a request: the whole point of the feature is that when it
 * is not working, CHIM behaves exactly as it does today.
 *
 * COST ON THE HOT PATH: one filemtime() of a marker file whose mtime is set INTO THE FUTURE. While that
 * marker is still in the future there is no config parse, no socket call and no stat of anything else.
 */

if (defined('LRG_TTS_BOOT_LOADED')) { return; }
define('LRG_TTS_BOOT_LOADED', true);
if (!defined('LRG_TTS_DIR')) { define('LRG_TTS_DIR', dirname(__DIR__)); } // the plugin root, NOT tts/

/** Everything the daemon and the supervisor agree on, with the shipped defaults behind it. */
const LRG_TTS_DEFAULTS = [
    'enabled'                 => true,
    // The socket CHIM's tts-pockettts.php passes to processAudio(). Changing this only makes sense
    // together with a CHIM that calls somewhere else, which is exactly what we are NOT doing.
    'socket'                  => '/var/www/html/HerikaServer/tts/audiofilterd.sock',
    'php_binary'              => '/usr/bin/php',
    'probe_interval_seconds'  => 10,   // how long a successful probe is trusted for
    'probe_timeout_ms'        => 50,   // a connect that takes longer than this counts as "not there"
    'spawn_retry_seconds'     => 30,   // never try to start it more often than this
    'request_timeout_seconds' => 5,    // reading one request off the socket
    'budget_ms'               => 250,  // a filter slower than this is logged as a warning
    'max_request_bytes'       => 33554432,
    'accept_timeout_seconds'  => 1,
    'idle_exit_seconds'       => 0,    // 0 = stay up. The daemon is ~14 MB RSS and starts on demand anyway.
    'fork'                    => true, // one slow client must never stall the next line she speaks
    'max_children'            => 8,
    'log_requests'            => true,
    'log_file'                => '',   // '' = HerikaServer/log/lorerim_glue.log, beside everything else
    'socket_mode'             => 0660,
    'socket_group'            => 'www-data',
    'run_dir'                 => '',   // '' = <plugin>/data. The pid file lives here.
    'trim'                    => [],   // see LRG_WAV_TRIM_DEFAULTS in lrg_wav.php
];

/**
 * The `tts.audiofilterd` block, defaults filled in.
 *
 * Works in both worlds: inside a CHIM request lrgConfig() is already loaded and cached, so this costs
 * nothing extra; inside the daemon (a plain CLI process that deliberately does NOT pull in the 82 KB
 * core library) the two config files are read directly. $GLOBALS['LRG_TTS_TEST_CFG'] is the test seam,
 * the same shape the rest of the glue uses.
 */
function lrgTtsCfg(): array
{
    static $c = null;
    if ($c === null) {
        $block = [];
        if (function_exists('lrgConfig')) {
            $block = (array) ((lrgConfig()['tts'] ?? [])['audiofilterd'] ?? []);
        } else {
            foreach (['/config/lrg_config.default.json', '/config/lrg_config.json'] as $f) {
                $raw = @file_get_contents(LRG_TTS_DIR . $f);
                if (!is_string($raw) || $raw === '') { continue; }
                $j = json_decode($raw, true);
                if (is_array($j) && isset($j['tts']['audiofilterd']) && is_array($j['tts']['audiofilterd'])) {
                    $block = array_replace_recursive($block, $j['tts']['audiofilterd']);
                }
            }
        }
        $c = array_replace_recursive(LRG_TTS_DEFAULTS, $block);
    }
    // The test seam is layered ON TOP of the real configuration, never instead of it: a test that pins
    // the socket path must still get the shipped trim settings.
    $ov = $GLOBALS['LRG_TTS_TEST_CFG'] ?? null;
    return is_array($ov) ? array_replace_recursive($c, $ov) : $c;
}

/** Where our own log lines go - the same file, in the same format, as the rest of the glue. */
function lrgTtsLog(string $msg): void
{
    // Inside a request the real logger is already loaded and owns the timezone question.
    if (function_exists('lrgLog') && !isset($GLOBALS['LRG_TTS_FORCE_OWN_LOG'])) { lrgLog($msg); return; }

    static $tz = null, $file = null;
    if ($tz === null) {
        $name = 'America/New_York';
        $raw = @file_get_contents(LRG_TTS_DIR . '/config/lrg_config.default.json');
        $j = is_string($raw) ? json_decode($raw, true) : null;
        if (is_array($j) && !empty($j['log_timezone'])) { $name = (string) $j['log_timezone']; }
        $rawU = @file_get_contents(LRG_TTS_DIR . '/config/lrg_config.json');
        $jU = is_string($rawU) ? json_decode($rawU, true) : null;
        if (is_array($jU) && !empty($jU['log_timezone'])) { $name = (string) $jU['log_timezone']; }
        try { $tz = new DateTimeZone($name); } catch (Throwable $e) { $tz = new DateTimeZone('UTC'); }

        $file = (string) lrgTtsCfg()['log_file'];
        if ($file === '') { $file = dirname(LRG_TTS_DIR, 2) . '/log/lorerim_glue.log'; }
        $dir = dirname($file);
        if (!is_dir($dir)) { @mkdir($dir, 0770, true); }
    }
    $line = (new DateTimeImmutable('now'))->setTimezone($tz)->format('Y-m-d H:i:s P') . ' ' . $msg . "\n";
    @file_put_contents($file, $line, FILE_APPEND);
}

/** Where the pid file lives. Created on demand; the deploy leaves <plugin>/data group-writable for www-data. */
function lrgTtsRunDir(): string
{
    $dir = (string) (lrgTtsCfg()['run_dir'] ?? '');
    if ($dir === '') { $dir = LRG_TTS_DIR . '/data'; }
    if (!is_dir($dir)) { @mkdir($dir, 0770, true); }
    return $dir;
}

/**
 * The supervisor's two marker files. Deliberately NOT under run_dir: the hot path has to know this path
 * without reading config at all, so it is a constant expression on both sides.
 *
 * If <plugin>/data is not writable by the web user - which the deploy makes sure it is, but a hand-copied
 * install might not - the markers go to the temp directory instead. That matters more than it looks:
 * without a writable marker the spawn rate-limit would be a no-op and every single request would try to
 * start a daemon. The hot path then misses its stat and pays the full probe (~0.1 ms), which is fine.
 */
function lrgTtsMarkerDir(): string
{
    static $d = null;
    if ($d !== null) { return $d; }
    $dir = LRG_TTS_DIR . '/data';
    if (!is_dir($dir)) { @mkdir($dir, 0770, true); }
    if (is_dir($dir) && is_writable($dir)) { return $d = $dir; }
    $d = rtrim(sys_get_temp_dir(), '/');
    lrgTtsLog('audiofilterd: ' . $dir . ' is not writable by ' . (function_exists('posix_getpwuid')
        ? (string) (@posix_getpwuid(@posix_geteuid())['name'] ?? '?') : '?')
        . ' - keeping the supervisor markers in ' . $d . ' instead');
    return $d;
}

/**
 * Push a marker's mtime into the future. NOT just touch().
 *
 * utime() with an EXPLICIT time needs OWNERSHIP of the file, not write permission - and
 * `tools/deploy_server.ps1` chowns the whole plugin, data/ included, to dwemer:www-data on every deploy.
 * So from the SECOND deploy onwards the web user owns neither marker, touch() fails silently, and the
 * spawn rate-limit goes with it: measured on this box, 40 of 40 hook calls tried to start a daemon
 * instead of 1, with a log line each, whenever the daemon could not come up. The containing directory is
 * group-writable, so removing the file and recreating it makes the caller its owner again.
 *
 * Returns false only when even that is impossible, which on a deployed install cannot happen.
 */
function lrgTtsTouchAhead(string $file, int $when): bool
{
    if (@touch($file, $when)) { return true; }
    @unlink($file); // group write on the directory is enough for this; the recreate below makes us owner
    return (bool) @touch($file, $when);
}

/**
 * Is a daemon answering on this socket? A connect and an immediate close - the daemon reads EOF with an
 * empty buffer and drops it without a word, which is exactly what this probe wants to cost.
 *
 * The file_exists() first is not redundant: "no daemon at all" is the state this box has been in since
 * it was built, and ENOENT is cheaper than a connect that has to fail.
 */
function lrgTtsProbe(string $path, float $timeoutSeconds = 0.05): bool
{
    if ($path === '' || !@file_exists($path)) { return false; }
    $errno = 0;
    $errstr = '';
    $c = @stream_socket_client('unix://' . $path, $errno, $errstr, $timeoutSeconds);
    if ($c === false) { return false; }
    @fclose($c);
    return true;
}

/**
 * Start the daemon, detached, and do not wait for it.
 *
 * THREE things this has to get right in THIS distro, where the caller is mod_php inside a prefork
 * Apache running as www-data:
 *
 *  1. PHP_BINARY is NOT php here - under mod_php it is /usr/sbin/apache2. The interpreter comes from
 *     config (`php_binary`, /usr/bin/php) and is checked before use.
 *  2. setsid, so the daemon leaves Apache's session and process group and survives `apachectl restart`
 *     and the CHIM launcher's own stop/start.
 *  3. It must not inherit Apache's LISTENING SOCKETS. A background process that holds :8081 is the
 *     classic way to make "Address already in use" appear on the next Apache start, and the owner would
 *     have no reason to connect that to a TTS filter. Redirecting 0/1/2 is not enough - the shell closes
 *     every other descriptor it was handed before exec'ing php.
 *
 * Returns ['ok'=>bool,'how'=>string,'cmd'=>string].
 */
function lrgTtsSpawnDaemon(array $cfg = []): array
{
    $cfg = $cfg ?: lrgTtsCfg();
    $php = (string) $cfg['php_binary'];
    if ($php === '' || !@is_executable($php)) {
        // Only trust PHP_BINARY if it really is a php; under mod_php it is the web server.
        $alt = defined('PHP_BINARY') ? (string) PHP_BINARY : '';
        if ($alt !== '' && strpos(basename($alt), 'php') !== false && @is_executable($alt)) { $php = $alt; }
        else { return ['ok' => false, 'how' => 'no usable php binary (tried ' . $cfg['php_binary'] . ')', 'cmd' => '']; }
    }
    $script = __DIR__ . '/lrg_audiofilterd.php';
    if (!@is_file($script)) { return ['ok' => false, 'how' => 'daemon script missing: ' . $script, 'cmd' => '']; }

    // run_dir / log_file are empty in production (the daemon reads its own config), and are passed on
    // only when something - the test harness - has pinned them in THIS process.
    $extra = '';
    foreach (['run-dir' => 'run_dir', 'log-file' => 'log_file'] as $flag => $key) {
        $v = (string) ($cfg[$key] ?? '');
        if ($v !== '') { $extra .= ' --' . $flag . '=' . escapeshellarg($v); }
    }
    $inner = 'for fd in /proc/$$/fd/*; do n=${fd##*/}; case "$n" in 0|1|2) ;; *) eval "exec $n>&-" 2>/dev/null;; esac; done; '
        . 'exec ' . escapeshellarg($php) . ' ' . escapeshellarg($script)
        . ' --daemon --socket=' . escapeshellarg((string) $cfg['socket']) . $extra;
    $setsid = @is_executable('/usr/bin/setsid') ? '/usr/bin/setsid ' : (@is_executable('/bin/setsid') ? '/bin/setsid ' : '');
    $cmd = $setsid . '/bin/sh -c ' . escapeshellarg($inner) . ' </dev/null >/dev/null 2>&1 &';

    if (function_exists('proc_open')) {
        $desc = [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']];
        $pipes = [];
        $p = @proc_open($cmd, $desc, $pipes, '/', null);
        if (is_resource($p)) {
            foreach ($pipes as $pipe) { if (is_resource($pipe)) { @fclose($pipe); } }
            @proc_close($p); // the shell backgrounded it and exited: this does not wait for the daemon
            return ['ok' => true, 'how' => 'proc_open', 'cmd' => $cmd];
        }
    }
    if (function_exists('exec')) {
        $out = [];
        $rc = 0;
        @exec($cmd, $out, $rc);
        return ['ok' => $rc === 0, 'how' => 'exec rc=' . $rc, 'cmd' => $cmd];
    }
    return ['ok' => false, 'how' => 'neither proc_open nor exec is available', 'cmd' => $cmd];
}

/**
 * THE HOOK. globals.php calls this once per request, before anything else the glue does.
 *
 * Fast path: one filemtime(). The marker's mtime is written INTO THE FUTURE, so "still fresh" is a
 * single integer comparison and no config file is even opened.
 *
 * Slow path (every probe_interval_seconds, or after a failure): read config, probe the socket, and if
 * nothing answers, start the daemon at most once every spawn_retry_seconds.
 *
 * It swallows everything. A TTS filter that cannot start is a cosmetic regression (CHIM's own ffmpeg
 * fallback still produces the wav); a TTS filter that throws inside globals.php would take the whole
 * conversation down.
 */
function lrgTtsEnsureAudiofilterd(): void
{
    // INERT OUTSIDE A REAL REQUEST. tools/test_gates.php and the whole flow harness include globals.php
    // to check the wiring; none of them may start a daemon on the live socket as a side effect of asking
    // what an NPC would say. A CLI caller that really does want this (tools/test_audiofilterd.php) opts
    // in by pinning the config seam, which also pins the socket somewhere harmless.
    if (PHP_SAPI === 'cli' && !isset($GLOBALS['LRG_TTS_TEST_CFG'])) { return; }
    if (isset($GLOBALS['LRG_TEST_NOW']) && !isset($GLOBALS['LRG_TTS_TEST_CFG'])) { return; }

    try {
        $dir = LRG_TTS_DIR . '/data';
        $mark = $dir . '/audiofilterd.next';
        @clearstatcache(true, $mark); // mod_php keeps a stat cache across requests in the same child
        $now = time();
        $mt = @filemtime($mark);
        if ($mt !== false && $mt > $now) { return; }

        $cfg = lrgTtsCfg();
        $dir = lrgTtsMarkerDir();
        $probeEvery = max(1, (int) $cfg['probe_interval_seconds']);

        if (empty($cfg['enabled'])) {
            lrgTtsTouchAhead($mark, $now + max(30, $probeEvery));
            // "enabled": false has to mean OFF NOW, not "off after the next reboot" - so a daemon that is
            // still up from before the owner turned this off is asked to stand down. It obeys the first
            // time, so this costs one probe per interval and nothing else.
            if (lrgTtsProbe((string) $cfg['socket'], 0.05)) {
                $errno = 0;
                $errstr = '';
                $c = @stream_socket_client('unix://' . $cfg['socket'], $errno, $errstr, 0.2);
                if ($c !== false) {
                    @fwrite($c, json_encode(['command' => 'shutdown']));
                    @stream_socket_shutdown($c, STREAM_SHUT_WR);
                    @fclose($c);
                    lrgTtsLog('audiofilterd: disabled in config - asked the running daemon to stop');
                }
            }
            return;
        }

        if (lrgTtsProbe((string) $cfg['socket'], max(0.005, ((float) $cfg['probe_timeout_ms']) / 1000.0))) {
            lrgTtsTouchAhead($mark, $now + $probeEvery);
            return;
        }

        // Nothing is answering. One starter at a time, and not more often than spawn_retry_seconds.
        $spawnMark = $dir . '/audiofilterd.spawn';
        @clearstatcache(true, $spawnMark);
        $sm = @filemtime($spawnMark);
        if ($sm !== false && $sm > $now) { lrgTtsTouchAhead($mark, $now + 5); return; }
        // If the rate-limit marker cannot be written at all, do NOT spawn: a start we cannot rate-limit
        // is a start on EVERY request, which is a process storm and a log flood exactly when the daemon
        // is already failing. Losing the feature is the cheaper failure. One line says so, once per
        // interval, because $mark is refreshed either way.
        if (!lrgTtsTouchAhead($spawnMark, $now + max(5, (int) $cfg['spawn_retry_seconds']))) {
            lrgTtsTouchAhead($mark, $now + max(30, $probeEvery));
            lrgTtsLog('audiofilterd: cannot write ' . $spawnMark . ' - not starting the daemon, because a'
                . ' start that cannot be rate-limited would be attempted on every request. Fix the'
                . ' permissions on ' . $dir . ' (the deploy sets dwemer:www-data ug+rwX) and it recovers'
                . ' by itself.');
            return;
        }

        $r = lrgTtsSpawnDaemon($cfg);
        lrgTtsLog('audiofilterd: nothing answering on ' . $cfg['socket'] . ' - starting the daemon ('
            . ($r['ok'] ? 'ok via ' . $r['how'] : 'FAILED: ' . $r['how']) . ')');
        // Re-probe soon: a start takes ~60 ms and we want the next line she speaks to use it.
        lrgTtsTouchAhead($mark, $now + 3);
    } catch (Throwable $e) {
        // Deliberately silent-ish: one line, never an exception into the request.
        try { lrgTtsLog('audiofilterd: supervisor error (ignored): ' . $e->getMessage()); } catch (Throwable $e2) { }
    }
}
