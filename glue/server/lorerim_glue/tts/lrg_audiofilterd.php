<?php
/**
 * LoreRim Glue - audiofilterd, the daemon half of CHIM's own audio filter.  [0.5.2 / PT9]
 *
 * CHIM 3.3.2 ships `tts/audiofilterd_client.php` and calls it for every PocketTTS line; the daemon it
 * talks to was never shipped in this distro. This is that daemon, living in OUR plugin, speaking the
 * protocol CHIM's client already speaks, on the socket CHIM's tts-pockettts.php already passes. Not one
 * byte of CHIM changes.
 *
 * THE PROTOCOL, read off audiofilterd_client.php (the client is the specification):
 *   - unix STREAM socket, path given by the caller; tts-pockettts.php:492 passes
 *     /var/www/html/HerikaServer/tts/audiofilterd.sock (the client's own default is /tmp/audiofilterd.sock).
 *   - the client connects with a 5 s connect timeout, writes the whole request, then
 *     stream_socket_shutdown(WR). There is NO length prefix and NO newline: half-close IS the framing.
 *   - request  {"audio_base64": "<b64 wav>", "filters":[{"type":"trim_start","milliseconds":250.0}]}
 *   - response {"error":{"code":0,"message":"ok"},"audio_base64":"<b64 wav>"}, then EOF: the client reads
 *     with stream_get_contents() until we close, so the response is terminated by the close and nothing else.
 *   - the client treats `error.code !== 0` as a hard failure (STRICT comparison - the code must be an
 *     INTEGER 0, not "0"), and requires audio_base64 to be a string that base64_decode(strict) accepts.
 *     It writes the bytes to the output file itself; we never touch the filesystem for it.
 *   - one request per connection.
 *
 * THE RULE THIS FILE IS BUILT AROUND: if anything at all goes wrong inside us, we answer code 0 with the
 * ORIGINAL audio. CHIM then writes exactly what it would have written without us. The feature can be
 * useless; it must never be a regression.
 *
 * Usage:
 *   php lrg_audiofilterd.php --daemon [--socket=PATH]   serve (this is what the supervisor spawns)
 *   php lrg_audiofilterd.php --once   [--socket=PATH]   serve exactly one connection, then exit (tests)
 *   php lrg_audiofilterd.php --test                     filter self-test, no socket at all
 *   php lrg_audiofilterd.php --status [--socket=PATH]   ask a running daemon how it is doing
 *   php lrg_audiofilterd.php --stop   [--socket=PATH]   ask it to shut down
 */

if (PHP_SAPI !== 'cli') { // a daemon reachable over http would be a silly thing to ship
    http_response_code(403);
    exit(1);
}

require_once __DIR__ . '/lrg_audiofilterd_boot.php';
require_once __DIR__ . '/lrg_wav.php';

define('LRG_AF_VERSION', '0.5.2');

// ---------------------------------------------------------------- process-wide state
$GLOBALS['LRG_AF'] = [
    'owner'    => getmypid(), // only this process may clean up the socket and the pid file
    'socket'   => '',
    'inode'    => 0,
    'pidfile'  => '',
    'server'   => null,
    'stop'     => false,
    'children' => [],
    'served'   => 0,
    'last'     => 0.0,
];

// ---------------------------------------------------------------- argument parsing
$opt = ['mode' => 'daemon', 'socket' => '', 'quiet' => false, 'timeout' => 0.0];
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--daemon' || $a === '-d') { $opt['mode'] = 'daemon'; }
    elseif ($a === '--once') { $opt['mode'] = 'once'; }
    elseif ($a === '--test') { $opt['mode'] = 'test'; }
    elseif ($a === '--status') { $opt['mode'] = 'status'; }
    elseif ($a === '--stop') { $opt['mode'] = 'stop'; }
    elseif ($a === '--quiet' || $a === '-q') { $opt['quiet'] = true; }
    elseif ($a === '--help' || $a === '-h') { $opt['mode'] = 'help'; }
    elseif (preg_match('/^--socket=(.+)$/', $a, $m)) { $opt['socket'] = $m[1]; }
    elseif (preg_match('/^--timeout=([0-9.]+)$/', $a, $m)) { $opt['timeout'] = (float) $m[1]; }
    elseif (preg_match('/^--run-dir=(.+)$/', $a, $m)) { $GLOBALS['LRG_TTS_TEST_CFG']['run_dir'] = $m[1]; }
    elseif (preg_match('/^--log-file=(.+)$/', $a, $m)) { $GLOBALS['LRG_TTS_TEST_CFG']['log_file'] = $m[1]; }
}

$cfg = lrgTtsCfg();
if ($opt['socket'] !== '') { $cfg['socket'] = $opt['socket']; }

switch ($opt['mode']) {
    case 'help':   lrgAfUsage(); exit(0);
    case 'test':   exit(lrgAfSelfTest());
    case 'status': exit(lrgAfAsk($cfg, 'status', $opt['quiet']));
    case 'stop':   exit(lrgAfAsk($cfg, 'shutdown', $opt['quiet']));
    case 'once':   exit(lrgAfServe($cfg, true, $opt));
    default:       exit(lrgAfServe($cfg, false, $opt));
}

// ================================================================== the server
/**
 * Bind, then accept one request per connection until told to stop.
 * Returns the process exit code. $once = serve a single connection (the tests use it).
 */
function lrgAfServe(array $cfg, bool $once, array $opt): int
{
    $sock = (string) $cfg['socket'];
    if ($sock === '') { fwrite(STDERR, "no socket path\n"); return 2; }
    @set_time_limit(0); // the CLI SAPI has no limit anyway; this is insurance if it is ever run elsewhere

    $dir = dirname($sock);
    if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
        lrgTtsLog('audiofilterd: cannot create ' . $dir . ' - not starting');
        return 2;
    }

    // Idempotent start: the supervisor may race with another Apache child, and the owner may run this by
    // hand. If something already answers there, that IS the daemon; leave it alone.
    if (lrgTtsProbe($sock, 0.2)) {
        if (!$opt['quiet']) { fwrite(STDOUT, "already running on $sock\n"); }
        return 0;
    }
    // Nothing answered, so a file sitting there is a leftover from a kill -9 or a reboot. Only then.
    if (@file_exists($sock)) { @unlink($sock); }

    $errno = 0;
    $errstr = '';
    $srv = @stream_socket_server('unix://' . $sock, $errno, $errstr);
    if ($srv === false) {
        if (lrgTtsProbe($sock, 0.2)) { return 0; } // lost the race, and that is fine
        lrgTtsLog('audiofilterd: cannot listen on ' . $sock . ': ' . $errstr . ' (' . $errno . ')');
        if (!$opt['quiet']) { fwrite(STDERR, "cannot listen on $sock: $errstr ($errno)\n"); }
        return 2;
    }

    // CHIM connects as www-data. The containing directory is drwxrws--- dwemer:www-data, so 0660 with
    // the inherited group is enough and nothing wider is needed.
    @chmod($sock, (int) $cfg['socket_mode']);
    $group = (string) $cfg['socket_group'];
    if ($group !== '' && function_exists('posix_getgrnam')) {
        $g = @posix_getgrnam($group);
        $st = @stat($sock);
        if (is_array($g) && is_array($st) && (int) $st['gid'] !== (int) $g['gid']) { @chgrp($sock, $group); }
    }

    $G = &$GLOBALS['LRG_AF'];
    $G['socket'] = $sock;
    $G['server'] = $srv;
    $G['inode'] = (int) @fileinode($sock);
    $G['last'] = microtime(true);
    $G['pidfile'] = lrgTtsRunDir() . '/audiofilterd.pid';
    // A stale pid file NEVER blocks a start: the socket above is the only authority on "already running".
    @file_put_contents($G['pidfile'], json_encode([
        'pid' => getmypid(), 'socket' => $sock, 'started' => time(), 'version' => LRG_AF_VERSION,
    ], JSON_UNESCAPED_SLASHES) . "\n");

    register_shutdown_function('lrgAfCleanup');
    $sig = false;
    if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
        pcntl_async_signals(true);
        foreach ([SIGTERM, SIGINT, SIGHUP] as $s) { @pcntl_signal($s, 'lrgAfSignal'); }
        @pcntl_signal(SIGPIPE, SIG_IGN); // a client that walks away must not kill the daemon
        $sig = true;
    }

    // Our own code changing on disk (a deploy) means this process is now the old version. Exit and let
    // the supervisor start the new one - that is what makes `deploy_server.ps1` pick up daemon changes.
    $stamp = lrgAfCodeStamp();

    lrgTtsLog('audiofilterd: listening on ' . $sock . ' (pid ' . getmypid() . ', v' . LRG_AF_VERSION
        . ', fork=' . (!empty($cfg['fork']) && function_exists('pcntl_fork') ? 'yes' : 'no')
        . ', signals=' . ($sig ? 'yes' : 'no') . ')');
    if (!$opt['quiet']) { fwrite(STDOUT, 'listening on ' . $sock . ' pid ' . getmypid() . "\n"); }

    $acceptTimeout = max(1, (int) $cfg['accept_timeout_seconds']);
    // --once must never hang a test run for ever; the daemon proper stays up unless idle_exit is set.
    $idleExit = $once ? (((float) $opt['timeout']) > 0 ? (float) $opt['timeout'] : 30.0)
        : (float) $cfg['idle_exit_seconds'];
    $maxKids = max(0, (int) $cfg['max_children']);
    $forking = !empty($cfg['fork']) && function_exists('pcntl_fork') && !$once;

    while (empty($G['stop'])) {
        $conn = @stream_socket_accept($srv, $acceptTimeout);
        if ($conn !== false) {
            $G['last'] = microtime(true);
            $G['served']++;
            if ($forking && count($G['children']) < $maxKids) {
                $pid = @pcntl_fork();
                if ($pid === 0) {
                    // CHILD. It must not own anything the parent owns: not the listener, not the pid
                    // file, not the socket path (lrgAfCleanup checks the owner pid for exactly this).
                    $GLOBALS['LRG_AF']['server'] = null;
                    @fclose($srv);
                    try { lrgAfHandle($conn, $cfg); } catch (Throwable $e) { }
                    @fclose($conn);
                    exit(0);
                }
                if ($pid > 0) { $G['children'][$pid] = true; @fclose($conn); }
                else { try { lrgAfHandle($conn, $cfg); } catch (Throwable $e) { } @fclose($conn); } // fork failed: inline
            } else {
                try { lrgAfHandle($conn, $cfg); } catch (Throwable $e) { }
                @fclose($conn);
            }
            if ($once) { break; }
        }

        if ($forking && function_exists('pcntl_waitpid')) {
            while (($p = @pcntl_waitpid(-1, $st, WNOHANG)) > 0) { unset($G['children'][$p]); }
        }
        if (!$sig && function_exists('pcntl_signal_dispatch')) { @pcntl_signal_dispatch(); }

        // Somebody replaced our socket (a second daemon, a cleanup script): stand down quietly.
        @clearstatcache(true, $sock);
        $now = @fileinode($sock);
        if ($now === false || (int) $now !== $G['inode']) {
            lrgTtsLog('audiofilterd: the socket at ' . $sock . ' is no longer ours - exiting (pid ' . getmypid() . ')');
            $G['inode'] = -1; // do not unlink somebody else's socket on the way out
            break;
        }
        if ($stamp !== lrgAfCodeStamp()) {
            lrgTtsLog('audiofilterd: the plugin changed on disk - exiting so the new code is used (pid ' . getmypid() . ')');
            break;
        }
        if ($idleExit > 0 && microtime(true) - $G['last'] > $idleExit) {
            if ($once) { lrgTtsLog('audiofilterd: --once timed out after ' . $idleExit . ' s'); }
            break;
        }
    }

    lrgAfCleanup();
    return 0;
}

/** SIGTERM / SIGINT / SIGHUP: finish what we are doing and stop. */
function lrgAfSignal(int $signo): void
{
    $GLOBALS['LRG_AF']['stop'] = true;
    lrgTtsLog('audiofilterd: signal ' . $signo . ' - shutting down (pid ' . getmypid() . ', '
        . (int) $GLOBALS['LRG_AF']['served'] . ' requests served)');
}

/** Close the listener and remove OUR socket and pid file. Runs once, and only in the process that bound. */
function lrgAfCleanup(): void
{
    $G = &$GLOBALS['LRG_AF'];
    if (empty($G) || (int) $G['owner'] !== getmypid()) { return; } // a forked child owns nothing
    if (!empty($G['done'])) { return; }
    $G['done'] = true;
    if (is_resource($G['server'])) { @fclose($G['server']); $G['server'] = null; }
    if ((string) $G['socket'] !== '' && (int) $G['inode'] > 0) {
        @clearstatcache(true, (string) $G['socket']);
        if (@file_exists((string) $G['socket']) && (int) @fileinode((string) $G['socket']) === (int) $G['inode']) {
            @unlink((string) $G['socket']);
        }
    }
    if ((string) $G['pidfile'] !== '' && @is_file((string) $G['pidfile'])) {
        $j = json_decode((string) @file_get_contents((string) $G['pidfile']), true);
        if (is_array($j) && (int) ($j['pid'] ?? 0) === getmypid()) { @unlink((string) $G['pidfile']); }
    }
}

/** mtime+size of the two files that make up the daemon. A deploy changes it; nothing else does. */
function lrgAfCodeStamp(): string
{
    $s = '';
    foreach ([__FILE__, __DIR__ . '/lrg_wav.php', __DIR__ . '/lrg_audiofilterd_boot.php'] as $f) {
        @clearstatcache(true, $f);
        $s .= (@filemtime($f) ?: 0) . ':' . (@filesize($f) ?: 0) . '|';
    }
    return $s;
}

// ================================================================== one request
/**
 * Read one request, answer it, close. Never lets an exception escape and never answers anything but
 * code 0 once it holds decodable audio.
 */
function lrgAfHandle($conn, array $cfg): void
{
    $t0 = microtime(true);
    $timeout = max(0.2, (float) $cfg['request_timeout_seconds']);
    $max = max(1024, (int) $cfg['max_request_bytes']);

    @stream_set_blocking($conn, true);
    @stream_set_timeout($conn, 1);

    $buf = '';
    $deadline = $t0 + $timeout;
    while (!@feof($conn)) {
        $chunk = @fread($conn, 65536);
        if ($chunk === false) { break; }
        if ($chunk === '') {
            $meta = @stream_get_meta_data($conn);
            if (!empty($meta['timed_out']) && microtime(true) > $deadline) { break; }
            if (@feof($conn)) { break; }
            if (microtime(true) > $deadline) { break; }
            continue;
        }
        $buf .= $chunk;
        if (strlen($buf) > $max) {
            lrgAfRespond($conn, 4, 'request larger than max_request_bytes (' . $max . ')', null);
            lrgTtsLog('audiofilterd: refused a ' . strlen($buf) . ' byte request (limit ' . $max . ')');
            return;
        }
        if (microtime(true) > $deadline) { break; }
    }

    // The supervisor's health probe: connect, close, say nothing. Costs one accept and no log line.
    if ($buf === '') { return; }

    $req = json_decode($buf, true);
    if (!is_array($req)) {
        lrgAfRespond($conn, 5, 'invalid JSON request', null);
        lrgTtsLog('audiofilterd: invalid JSON request (' . strlen($buf) . ' bytes) - CHIM will fall back to ffmpeg');
        return;
    }

    $cmd = strtolower((string) ($req['command'] ?? ''));
    if ($cmd !== '') { lrgAfCommand($conn, $cmd, $cfg); return; }

    $b64 = $req['audio_base64'] ?? null;
    if (!is_string($b64) || $b64 === '') {
        lrgAfRespond($conn, 6, 'no audio_base64 in request', null);
        return;
    }
    $audio = base64_decode($b64, true);
    if ($audio === false || $audio === '') {
        lrgAfRespond($conn, 7, 'audio_base64 is not valid base64', null);
        lrgTtsLog('audiofilterd: undecodable audio_base64 (' . strlen($b64) . ' chars)');
        return;
    }

    $inLen = strlen($audio);
    $notes = [];
    $out = $audio;
    try {
        $out = lrgAfApplyFilters($audio, (array) ($req['filters'] ?? []), $cfg, $notes);
    } catch (Throwable $e) {
        // THE RULE: the original audio, code 0. CHIM gets a working wav and the owner gets a log line.
        $out = $audio;
        $notes[] = 'internal error, passed through: ' . $e->getMessage();
    }
    if (!is_string($out) || $out === '') { $out = $audio; $notes[] = 'filter produced nothing, passed through'; }

    lrgAfRespond($conn, 0, 'ok', $out);

    $ms = (microtime(true) - $t0) * 1000.0;
    if (!empty($cfg['log_requests'])) {
        lrgTtsLog(sprintf('audiofilterd: %d -> %d bytes (%+d), %s, %.1f ms',
            $inLen, strlen($out), strlen($out) - $inLen, implode('; ', $notes) ?: 'no filters', $ms));
    }
    if ($ms > max(1.0, (float) $cfg['budget_ms'])) {
        lrgTtsLog(sprintf('audiofilterd: WARNING one request took %.1f ms (budget %d ms)', $ms, (int) $cfg['budget_ms']));
    }
}

/** ping / status / shutdown. CHIM never sends these; our own tools do. */
function lrgAfCommand($conn, string $cmd, array $cfg): void
{
    $G = &$GLOBALS['LRG_AF'];
    if ($cmd === 'ping') { lrgAfRespond($conn, 0, 'pong', ''); return; }
    if ($cmd === 'status') {
        // $G['owner'], NOT getmypid(): with fork=true this request is answered by a CHILD, and the child
        // would report its own throwaway pid. The owner is told to use --status to find the daemon, so
        // the number it prints has to be the one `kill` would want.
        lrgAfRespond($conn, 0, json_encode([
            'pid' => (int) $G['owner'], 'version' => LRG_AF_VERSION, 'socket' => (string) $G['socket'],
            'served' => (int) $G['served'], 'children' => count((array) $G['children']),
            'answered_by' => getmypid(),
        ], JSON_UNESCAPED_SLASHES), '');
        return;
    }
    if ($cmd === 'shutdown') {
        lrgAfRespond($conn, 0, 'shutting down', '');
        $G['stop'] = true;
        lrgTtsLog('audiofilterd: shutdown requested over the socket (pid ' . getmypid() . ', '
            . (int) $G['served'] . ' requests served)');
        // A forked child cannot stop the parent by setting a variable, so tell it properly.
        if ((int) $G['owner'] !== getmypid() && function_exists('posix_kill')) { @posix_kill((int) $G['owner'], SIGTERM); }
        return;
    }
    lrgAfRespond($conn, 8, 'unknown command: ' . $cmd, '');
}

/**
 * Apply the filter list. Unknown types are NOTED and SKIPPED, never refused: the client's own docs
 * (SUPPORTED_EFFECTS.md) are not on this box, so a CHIM update that starts sending a new effect must
 * degrade to "nothing happened", not to a broken line.
 */
function lrgAfApplyFilters(string $audio, array $filters, array $cfg, array &$notes): string
{
    if ($filters === []) { $notes[] = 'no filters requested'; return $audio; }
    $trimCfg = (array) ($cfg['trim'] ?? []);
    $out = $audio;

    foreach ($filters as $f) {
        if (!is_array($f)) { $notes[] = 'skipped a malformed filter entry'; continue; }
        $type = strtolower((string) ($f['type'] ?? ''));
        switch ($type) {
            case 'trim_start':
                $ms = (float) ($f['milliseconds'] ?? 0.0);
                $r = lrgWavTrimStart($out, $ms, $trimCfg);
                $out = $r['audio'];
                $notes[] = sprintf('trim_start(%.0f ms) removed %.0f ms [%s]', $ms, $r['trimmed_ms'], $r['reason']);
                break;
            default:
                $notes[] = 'skipped unsupported filter "' . ($type !== '' ? $type : '(no type)') . '"';
        }
    }
    return $out;
}

/**
 * Write the response and close the write side. $audio null = no audio field at all (an error answer);
 * '' = an empty audio field (our own commands).
 *
 * `code` is cast to int deliberately: the client compares with !== against an integer 0, so a "0" here
 * would make every successful call look like a failure.
 */
function lrgAfRespond($conn, int $code, string $message, ?string $audio): void
{
    $resp = ['error' => ['code' => (int) $code, 'message' => $message]];
    if ($audio !== null) { $resp['audio_base64'] = base64_encode($audio); }
    $json = json_encode($resp, JSON_UNESCAPED_SLASHES);
    if ($json === false) { $json = '{"error":{"code":9,"message":"failed to encode response"}}'; }

    $len = strlen($json);
    $off = 0;
    while ($off < $len) {
        $w = @fwrite($conn, substr($json, $off, 65536));
        if ($w === false || $w === 0) { break; }
        $off += $w;
    }
    @fflush($conn);
    @stream_socket_shutdown($conn, STREAM_SHUT_WR); // the client reads to EOF: this IS the end of the message
}

// ================================================================== client-side helpers (our tools only)
/** --status / --stop: talk to a running daemon. */
function lrgAfAsk(array $cfg, string $command, bool $quiet): int
{
    $sock = (string) $cfg['socket'];
    $errno = 0;
    $errstr = '';
    $c = @stream_socket_client('unix://' . $sock, $errno, $errstr, 2.0);
    if ($c === false) {
        if (!$quiet) { fwrite(STDERR, "not running on $sock ($errstr)\n"); }
        return 1;
    }
    @fwrite($c, json_encode(['command' => $command]));
    @stream_socket_shutdown($c, STREAM_SHUT_WR);
    $r = (string) @stream_get_contents($c);
    @fclose($c);
    $j = json_decode($r, true);
    if (!$quiet) { fwrite(STDOUT, (is_array($j) ? (string) ($j['error']['message'] ?? $r) : $r) . "\n"); }
    return is_array($j) && (int) ($j['error']['code'] ?? 1) === 0 ? 0 : 1;
}

function lrgAfUsage(): void
{
    fwrite(STDOUT, "LoreRim Glue audiofilterd v" . LRG_AF_VERSION . "\n"
        . "  --daemon [--socket=PATH]   serve until stopped (what the supervisor spawns)\n"
        . "  --once   [--socket=PATH] [--timeout=SEC]   serve one connection, then exit\n"
        . "  --test                     run the filter over synthetic audio, no socket\n"
        . "  --status [--socket=PATH]   ask a running daemon for its state\n"
        . "  --stop   [--socket=PATH]   ask it to shut down\n"
        . "default socket: " . LRG_TTS_DEFAULTS['socket'] . "\n");
}

/**
 * `--test`: the filter, with no socket and no CHIM. Synthesises the shapes that matter, including the
 * ones that must NOT be trimmed, and prints what it did. Exit 0 = every expectation held.
 */
function lrgAfSelfTest(): int
{
    $rate = 24000;
    $mk = static function (float $silenceMs, float $toneMs = 800.0, int $amp = 9000) use ($rate): string {
        $frames = [];
        $n = (int) round($silenceMs / 1000.0 * $rate);
        for ($i = 0; $i < $n; $i++) { $frames[] = random_int(-6, 6); } // dither, not digital zero
        $n = (int) round($toneMs / 1000.0 * $rate);
        for ($i = 0; $i < $n; $i++) { $frames[] = (int) round($amp * sin(2 * M_PI * 180.0 * $i / $rate)); }
        return lrgWavBuild($frames, $rate, 1);
    };

    $cases = [
        ['no silence at all', $mk(0.0), 0.0, 60.0],
        ['250 ms of silence', $mk(250.0), 180.0, 250.0],
        ['600 ms of silence', $mk(600.0), 520.0, 600.0],
        ['1200 ms of silence (past the cap)', $mk(1200.0), 860.0, 940.0],
    ];
    $fail = 0;
    foreach ($cases as [$name, $wav, $lo, $hi]) {
        $r = lrgWavTrimStart($wav, 250.0);
        $ok = $r['trimmed_ms'] >= $lo && $r['trimmed_ms'] <= $hi;
        printf("  [%s] %-34s removed %7.1f ms (want %.0f-%.0f)  %s\n",
            $ok ? 'ok' : 'FAIL', $name, $r['trimmed_ms'], $lo, $hi, $r['reason']);
        if (!$ok) { $fail++; }
    }
    // Things that must come back byte-identical.
    foreach (['garbage' => str_repeat("\x01\x02\x03", 300), 'empty' => '',
              'mp3-ish' => "ID3\x03\x00\x00\x00" . str_repeat("\xff\xfb\x90", 100)] as $name => $bytes) {
        $r = lrgWavTrimStart($bytes, 250.0);
        $ok = $r['audio'] === $bytes && $r['trimmed_ms'] === 0.0;
        printf("  [%s] %-34s passed through unchanged  %s\n", $ok ? 'ok' : 'FAIL', $name, $r['reason']);
        if (!$ok) { $fail++; }
    }
    printf("\n%s\n", $fail === 0 ? 'ALL CHECKS PASSED' : "RESULT: FAILED ($fail)");
    return $fail === 0 ? 0 : 1;
}
