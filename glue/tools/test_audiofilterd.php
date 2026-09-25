<?php
/**
 * LoreRim Glue - the audiofilterd daemon, checked THROUGH CHIM'S OWN CLIENT.  [0.5.2 / PT9]
 *
 * The daemon exists to be talked to by exactly one piece of code: `tts/audiofilterd_client.php`, which
 * CHIM calls for every PocketTTS line. So this test does not invent a client. It loads CHIM's file,
 * read-only, and calls `processAudio()` the same way `tts-pockettts.php:483` calls it - same filter
 * list, same 250.0 milliseconds, same socket argument. If these checks pass, the real path works, and
 * if the protocol was misread, it fails here rather than in game.
 *
 * It also checks the two things that are not about audio at all and would bite the owner later:
 *   - a daemon spawned from a web request must NOT inherit Apache's listening sockets (§6), or the next
 *     `apachectl restart` says "Address already in use" and nobody connects that to a TTS filter;
 *   - a stale socket file and a stale pid file must never stop it from starting (§7).
 *
 * Nothing here touches the live socket, the live log or the live plugin: everything happens under a
 * work directory of its own. Run it inside the WSL distro (it needs unix sockets and CHIM's client):
 *
 *     php tools/test_audiofilterd.php
 *     php tools/test_audiofilterd.php --dir=/tmp/lrg_af_test --client=/var/www/html/HerikaServer/tts/audiofilterd_client.php
 *     sudo -u www-data php tools/test_audiofilterd.php        # the user Apache really runs as
 *
 * Exit 0 = every check passed.
 */

error_reporting(E_ALL);
ini_set('display_errors', 'stderr');

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "cli only\n"); exit(2); }

$root = dirname(__DIR__);
$plugin = $root . '/server/lorerim_glue';

$args = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $a, $m)) { $args[$m[1]] = $m[2] ?? true; }
}
$dir = is_string($args['dir'] ?? null) ? $args['dir'] : (sys_get_temp_dir() . '/lrg_af_test');
$clientPath = is_string($args['client'] ?? null) ? $args['client']
    : '/var/www/html/HerikaServer/tts/audiofilterd_client.php';

@mkdir($dir, 0777, true);
if (!is_dir($dir) || !is_writable($dir)) { fwrite(STDERR, "cannot write to $dir\n"); exit(2); }
$sock = is_string($args['socket'] ?? null) ? $args['socket'] : ($dir . '/audiofilterd.sock');
$log = $dir . '/lorerim_glue.log';

// The daemon and the supervisor read this BEFORE any config file, so the test never writes to the live
// socket, the live pid file or the live log.
$GLOBALS['LRG_TTS_TEST_CFG'] = ['socket' => $sock, 'run_dir' => $dir, 'log_file' => $log];

require_once $plugin . '/tts/lrg_wav.php';
require_once $plugin . '/tts/lrg_audiofilterd_boot.php';

$ok = 0;
$fail = 0;
function chk(string $name, $cond, $detail = ''): bool
{
    global $ok, $fail;
    $good = (bool) (is_callable($cond) ? $cond() : $cond);
    if ($good) { $ok++; echo "  [ok] $name\n"; }
    else {
        $fail++;
        echo "  [FAIL] $name" . ($detail !== '' ? '  [' . substr((string) (is_callable($detail) ? $detail() : $detail), 0, 300) . ']' : '') . "\n";
    }
    return $good;
}
function head(string $s): void { echo "\n$s\n"; }

// ---------------------------------------------------------------- fixtures
/** A line of "speech": dithered silence, then a decaying vowel-ish tone. Mono 24 kHz, like PocketTTS. */
function fixture(float $silenceMs, float $speechMs = 900.0, int $amp = 9000, int $rate = 24000, int $ch = 1): string
{
    $frames = [];
    $n = (int) round($silenceMs / 1000.0 * $rate);
    for ($i = 0; $i < $n; $i++) { for ($c = 0; $c < $ch; $c++) { $frames[] = random_int(-6, 6); } }
    $n = (int) round($speechMs / 1000.0 * $rate);
    for ($i = 0; $i < $n; $i++) {
        $v = (int) round($amp * sin(2 * M_PI * 175.0 * $i / $rate) * (0.6 + 0.4 * sin(2 * M_PI * 3.0 * $i / $rate)));
        for ($c = 0; $c < $ch; $c++) { $frames[] = $v; }
    }
    return lrgWavBuild($frames, $rate, $ch);
}

/** Sum of |sample| over the data chunk - if trimming ever cut into speech, this drops. */
function energy(string $wav): float
{
    $w = lrgWavParse($wav);
    if (lrgWavUnsupportedReason($w) !== '') { return -1.0; }
    $v = @unpack('v*', substr($wav, (int) $w['data_off'], (int) $w['data_len']));
    if (!is_array($v)) { return -1.0; }
    $s = 0.0;
    foreach ($v as $x) { $s += $x < 32768 ? $x : 65536 - $x; }
    return $s;
}

// ---------------------------------------------------------------- 0. CHIM's client
head('0. CHIM\'s own client is the test harness (nothing of ours talks to the socket)');
if (!is_file($clientPath)) {
    echo "  [FAIL] CHIM's audiofilterd_client.php not found at $clientPath\n";
    echo "  This test has to run inside the DwemerAI4Skyrim3 distro. Pass --client=<path> if it lives elsewhere.\n";
    echo "\nRESULT: FAILED (no client)\n";
    exit(1);
}
require_once $clientPath; // defines processAudio(); its own CLI block does not fire (argv[0] is us)
chk('processAudio() is loaded from ' . $clientPath, function_exists('processAudio'));
chk('and we did not redefine it ourselves', (new ReflectionFunction('processAudio'))->getFileName() === realpath($clientPath),
    (new ReflectionFunction('processAudio'))->getFileName());

/**
 * Start a daemon exactly the way the web hook does, on a socket and a run dir of this test's choosing,
 * and wait (up to 5 s) until it answers. Each section gets its own run dir so two daemons never fight
 * over one pid file. Returns [ok, pid].
 */
function afStart(string $sockPath, string $runDir, string $logFile): array
{
    @mkdir($runDir, 0777, true);
    $GLOBALS['LRG_TTS_TEST_CFG']['socket'] = $sockPath;
    $GLOBALS['LRG_TTS_TEST_CFG']['run_dir'] = $runDir;
    $GLOBALS['LRG_TTS_TEST_CFG']['log_file'] = $logFile;
    $r = lrgTtsSpawnDaemon(lrgTtsCfg());
    $up = false;
    for ($i = 0; $i < 100; $i++) {
        if (lrgTtsProbe($sockPath, 0.2)) { $up = true; break; }
        usleep(50000);
    }
    $pid = (int) (json_decode((string) @file_get_contents($runDir . '/audiofilterd.pid'), true)['pid'] ?? 0);
    return ['ok' => $up && $r['ok'], 'up' => $up, 'pid' => $pid, 'how' => $r['how'], 'cmd' => $r['cmd']];
}

/** Ask a daemon to stop over its own socket, the way tools and the owner would. */
function afStop(string $sockPath): void
{
    $errno = 0;
    $errstr = '';
    $c = @stream_socket_client('unix://' . $sockPath, $errno, $errstr, 2.0);
    if ($c === false) { return; }
    @fwrite($c, json_encode(['command' => 'shutdown']));
    @stream_socket_shutdown($c, STREAM_SHUT_WR);
    @stream_get_contents($c);
    @fclose($c);
    usleep(300000);
}

// ---------------------------------------------------------------- 1. start it the way Apache would
head('1. the daemon starts the way a web request starts it (detached, through the supervisor\'s own code)');
@unlink($sock);
$main = afStart($sock, $dir, $log);
chk('lrgTtsSpawnDaemon() reported success (' . $main['how'] . ')', $main['ok'] !== false, $main['cmd']);
chk('it is answering on ' . $sock, $main['up'], 'no socket after 5 s - see ' . $log);
if (!$main['up']) { echo "\nRESULT: FAILED (daemon did not start)\n"; exit(1); }

$daemonPid = $main['pid'];
chk('it wrote a pid file with a live pid', $daemonPid > 0 && (!function_exists('posix_kill') || @posix_kill($daemonPid, 0)),
    (string) @file_get_contents($dir . '/audiofilterd.pid'));
chk('the socket is a socket, not a regular file', @filetype($sock) === 'socket', (string) @filetype($sock));
$perm = @fileperms($sock) & 0777;
chk('the socket is reachable by the group CHIM runs as (mode ' . decoct($perm) . ')', ($perm & 0060) === 0060, decoct($perm));
chk('the daemon is detached - its parent is init, not this test',
    !@is_file('/proc/' . $daemonPid . '/stat') || (int) (explode(' ', (string) @file_get_contents('/proc/' . $daemonPid . '/stat'))[3] ?? 0) !== getmypid(),
    'ppid ' . (explode(' ', (string) @file_get_contents('/proc/' . $daemonPid . '/stat'))[3] ?? '?') . ' vs us ' . getmypid());

// ---------------------------------------------------------------- 2. the audio cases
head('2. the audio, end to end through processAudio() - exactly the call tts-pockettts.php:483 makes');
$filters = [['type' => 'trim_start', 'milliseconds' => 250.0]];
$cases = [
    // name,                        silence, min trim, max trim
    ['no leading silence',              0.0,    0.0,    60.0],
    ['250 ms of leading silence',     250.0,  180.0,   260.0],
    ['600 ms of leading silence',     600.0,  520.0,   610.0],
    ['900 ms of leading silence',     900.0,  820.0,   900.0],
    ['1400 ms - past the 900 ms cap', 1400.0, 860.0,   900.0],
];
foreach ($cases as [$name, $silence, $lo, $hi]) {
    $in = fixture($silence);
    $out = $dir . '/out_' . (int) $silence . '.wav';
    @unlink($out);
    $t0 = microtime(true);
    $threw = '';
    try { processAudio($in, $filters, $out, $sock); } catch (Throwable $e) { $threw = $e->getMessage(); }
    $ms = (microtime(true) - $t0) * 1000.0;

    if (!chk($name . ': the client returned without throwing', $threw === '', $threw)) { continue; }
    $got = (string) @file_get_contents($out);
    if (!chk($name . ': a file was written', $got !== '', $out)) { continue; }
    $dIn = (float) lrgWavDurationMs($in);
    $dOut = (float) lrgWavDurationMs($got);
    $removed = $dIn - $dOut;
    chk(sprintf('%s: removed %.0f ms (want %.0f-%.0f), round trip %.1f ms', $name, $removed, $lo, $hi, $ms),
        $removed >= $lo - 1 && $removed <= $hi + 1, sprintf('in %.0f ms, out %.0f ms', $dIn, $dOut));
    chk($name . ': the output is still a valid 16-bit PCM WAV', lrgWavUnsupportedReason(lrgWavParse($got)) === '',
        lrgWavUnsupportedReason(lrgWavParse($got)));
    // The one thing that must never happen: cutting into the speech.
    $eIn = energy($in);
    $eOut = energy($got);
    chk(sprintf('%s: no speech was cut (energy %.0f -> %.0f)', $name, $eIn, $eOut),
        $eOut >= $eIn * 0.999, sprintf('%.4f of the input', $eIn > 0 ? $eOut / $eIn : 0));
    // What may be left in front: the lead-in we deliberately keep (40 ms, plus an analysis window), and
    // - for a file whose silence is longer than the 900 ms cap - everything the cap refused to remove.
    $leftMax = 70.0 + max(0.0, $silence - 900.0);
    $left = lrgWavLeadingSilenceMs($got);
    chk(sprintf('%s: what is left in front of the first sound is %.0f ms (at most %.0f)', $name, (float) $left, $leftMax),
        $left === null || $left <= $leftMax, var_export($left, true));
}

// ---------------------------------------------------------------- 3. everything that must pass through
head('3. anything not understood comes back BYTE-IDENTICAL (a pass-through is always a valid answer)');
$passthrough = [
    'a garbage payload'            => str_repeat("\x01\x02\x03\xff", 512),
    'an mp3-looking payload'       => "ID3\x03\x00\x00\x00\x00" . str_repeat("\xff\xfb\x90\x44", 400),
    'a RIFF that is not WAVE'      => 'RIFF' . pack('V', 100) . 'AVI ' . str_repeat("\x00", 100),
    'a truncated WAV header'       => substr(fixture(300.0), 0, 30),
];
foreach ($passthrough as $name => $bytes) {
    $out = $dir . '/pt_' . md5($name) . '.bin';
    @unlink($out);
    $threw = '';
    try { processAudio($bytes, $filters, $out, $sock); } catch (Throwable $e) { $threw = $e->getMessage(); }
    chk($name . ': accepted without an error object', $threw === '', $threw);
    chk($name . ': returned unchanged', (string) @file_get_contents($out) === $bytes,
        strlen((string) @file_get_contents($out)) . ' bytes back, ' . strlen($bytes) . ' sent');
}

$eightBit = 'RIFF' . pack('V', 36 + 2000) . 'WAVE' . 'fmt ' . pack('V', 16)
    . pack('vvVVvv', 1, 1, 8000, 8000, 1, 8) . 'data' . pack('V', 2000) . str_repeat("\x80", 2000);
$out = $dir . '/pt_8bit.wav';
@unlink($out);
try { processAudio($eightBit, $filters, $out, $sock); } catch (Throwable $e) { }
chk('an 8-bit PCM WAV is passed through untouched (we only understand 16-bit)',
    (string) @file_get_contents($out) === $eightBit);

$unknown = fixture(400.0);
$out = $dir . '/pt_unknownfilter.wav';
@unlink($out);
try { processAudio($unknown, [['type' => 'reverb', 'wet' => 0.3]], $out, $sock); } catch (Throwable $e) { }
chk('an UNKNOWN filter type is skipped, not refused (a future CHIM effect degrades to "nothing happened")',
    (string) @file_get_contents($out) === $unknown);
$out = $dir . '/pt_nofilters.wav';
@unlink($out);
try { processAudio($unknown, [], $out, $sock); } catch (Throwable $e) { }
chk('an empty filter list returns the input', (string) @file_get_contents($out) === $unknown);

// ---------------------------------------------------------------- 4. the shapes PocketTTS really makes
head('4. the real shapes: 24 kHz mono like PocketTTS, and a stereo/44.1 kHz file for the header maths');
$stereo = fixture(300.0, 600.0, 9000, 44100, 2);
$out = $dir . '/stereo.wav';
@unlink($out);
try { processAudio($stereo, $filters, $out, $sock); } catch (Throwable $e) { }
$got = (string) @file_get_contents($out);
$w = lrgWavParse($got);
chk('the stereo 44.1 kHz file stays stereo 44.1 kHz',
    is_array($w) && (int) $w['channels'] === 2 && (int) $w['rate'] === 44100, json_encode($w));
chk('its data chunk is still whole frames', is_array($w) && ((int) $w['data_len']) % ((int) $w['block_align']) === 0,
    json_encode($w));
chk('and about 260 ms came off it', (static function () use ($stereo, $got) {
    $d = lrgWavDurationMs($stereo) - (float) lrgWavDurationMs($got);
    return $d > 200 && $d < 310;
})(), sprintf('%.0f ms', lrgWavDurationMs($stereo) - (float) lrgWavDurationMs($got)));

// A wav with a LIST chunk behind the data: the sizes in the header have to stay consistent.
$base = fixture(350.0, 400.0);
$withList = $base . 'LIST' . pack('V', 12) . 'INFOISFT' . pack('V', 4) . "lrg\x00";
$withList = substr_replace($withList, pack('V', strlen($withList) - 8), 4, 4);
$out = $dir . '/withlist.wav';
@unlink($out);
try { processAudio($withList, $filters, $out, $sock); } catch (Throwable $e) { }
$got = (string) @file_get_contents($out);
chk('a trailing LIST chunk survives the trim', strpos($got, 'LIST') !== false && strpos($got, 'ISFT') !== false,
    strlen($got) . ' bytes');
chk('and the RIFF size field still matches the file',
    strlen($got) >= 8 && (int) unpack('V', substr($got, 4, 4))[1] === strlen($got) - 8,
    strlen($got) >= 8 ? unpack('V', substr($got, 4, 4))[1] . ' vs ' . (strlen($got) - 8) : 'too short');

// ---------------------------------------------------------------- 4b. REAL PocketTTS output
/*
 * Synthetic fixtures prove the arithmetic; only the real thing proves the thresholds. The `_o.wav` files
 * in CHIM's soundcache are the untouched bytes PocketTTS returned during the owner's own play sessions,
 * so they are the exact input this daemon will see in game. Read-only, and skipped when there are none.
 */
head('4b. real PocketTTS output from CHIM\'s soundcache (read-only; this is the input it will really see)');
$realDir = is_string($args['real-dir'] ?? null) ? $args['real-dir'] : '/var/www/html/HerikaServer/soundcache';
$reals = is_dir($realDir) ? (array) @glob($realDir . '/*_o.wav') : [];
if ($reals === []) {
    echo "  [note] no *_o.wav in $realDir - skipped\n";
} else {
    shuffle($reals);
    $reals = array_slice($reals, 0, 20);
    $trims = [];
    $bad = 0;
    $slow = 0.0;
    $worstRatio = 1.0;
    foreach ($reals as $f) {
        $in = (string) @file_get_contents($f);
        if ($in === '' || lrgWavUnsupportedReason(lrgWavParse($in)) !== '') { continue; }
        $out = $dir . '/real_' . basename($f);
        @unlink($out);
        $t0 = microtime(true);
        try { processAudio($in, $filters, $out, $sock); } catch (Throwable $e) { $bad++; continue; }
        $ms = (microtime(true) - $t0) * 1000.0;
        $slow = max($slow, $ms);
        $got = (string) @file_get_contents($out);
        if ($got === '' || lrgWavUnsupportedReason(lrgWavParse($got)) !== '') { $bad++; continue; }
        // What is removed is real recorded noise, not digital zero, so a little energy always goes with
        // it. 1 % is the line: below that something audible was cut. (Measured over all 132 files of the
        // owner's own soundcache the worst case is 0.9945, and that is the quietest line on the box.)
        $ratio = energy($in) > 0 ? energy($got) / energy($in) : 1.0;
        $worstRatio = min($worstRatio, $ratio);
        if ($ratio < 0.99) { $bad++; continue; }
        $trims[] = (float) lrgWavDurationMs($in) - (float) lrgWavDurationMs($got);
    }
    sort($trims);
    $n = count($trims);
    chk('every real file came back as a valid wav with its speech intact', $bad === 0 && $n > 0,
        $bad . ' bad of ' . count($reals));
    if ($n > 0) {
        printf("  %d real lines: removed median %.0f ms, min %.0f, max %.0f; slowest round trip %.1f ms;"
            . " worst energy ratio %.4f\n",
            $n, $trims[(int) ($n / 2)], $trims[0], $trims[$n - 1], $slow, $worstRatio);
        chk('the median removal is in the 0.25-0.31 s band pt9-tts-cpu.md measured for PocketTTS',
            $trims[(int) ($n / 2)] >= 150.0 && $trims[(int) ($n / 2)] <= 700.0, sprintf('%.0f ms', $trims[(int) ($n / 2)]));
        chk('nothing was trimmed past the 900 ms cap', $trims[$n - 1] <= 900.0, sprintf('%.0f ms', $trims[$n - 1]));
        chk('and the whole round trip stays far under ffmpeg\'s measured 55 ms', $slow < 55.0, sprintf('%.1f ms', $slow));
    }
}

// ---------------------------------------------------------------- 5. it logged what it did
head('5. one log line per request, in the glue\'s own log');
$logged = (string) @file_get_contents($log);
chk('the daemon logged that it was listening', strpos($logged, 'audiofilterd: listening on') !== false, substr($logged, 0, 200));
chk('and it logged a trim_start line with the millisecond it removed',
    preg_match('/trim_start\(250 ms\) removed \d+ ms/', $logged) === 1,
    implode(' | ', array_slice(array_filter(explode("\n", $logged), static fn($l) => strpos($l, 'trim_start') !== false), 0, 2)));
chk('the health probe did NOT produce a log line per request (it would flood the file)',
    substr_count($logged, 'audiofilterd:') < 60, (string) substr_count($logged, 'audiofilterd:'));

// ---------------------------------------------------------------- 6. it must not hold Apache's sockets
head('6. a daemon started from a web request must not inherit Apache\'s listening sockets');
$port = random_int(21000, 21999);
$errno = 0;
$errstr = '';
$listener = @stream_socket_server('tcp://127.0.0.1:' . $port, $errno, $errstr);
if ($listener === false) {
    echo "  [note] could not bind a test port ($errstr) - skipping the inheritance check\n";
} else {
    $sock2 = $dir . '/inherit.sock';
    @unlink($sock2);
    $d2 = afStart($sock2, $dir . '/run2', $log);
    chk('a second daemon started while a listening socket was open', $d2['up'], $d2['how']);
    @fclose($listener); // we let go of the port; only an inherited copy could still hold it
    usleep(200000);
    $again = @stream_socket_server('tcp://127.0.0.1:' . $port, $errno, $errstr);
    chk('the port is free again - the daemon did NOT inherit it (this is what keeps apachectl restart working)',
        $again !== false, 'port ' . $port . ' still held: ' . $errstr);
    if ($again !== false) { @fclose($again); }
    if ($d2['up']) {
        afStop($sock2);
        chk('and it shut down cleanly when asked over the socket',
            !@file_exists($sock2) && ($d2['pid'] <= 0 || !function_exists('posix_kill') || !@posix_kill($d2['pid'], 0)),
            'pid ' . $d2['pid'] . ' socket ' . var_export(@file_exists($sock2), true));
        chk('and removed its pid file', !@file_exists($dir . '/run2/audiofilterd.pid'));
    }
}

// ---------------------------------------------------------------- 7. stale files never block a restart
head('7. a stale socket file and a stale pid file never stop it starting');
$run3 = $dir . '/run3';
@mkdir($run3, 0777, true);
$stale = $dir . '/stale.sock';
@unlink($stale);
@file_put_contents($stale, "not a socket, left over from a kill -9\n");
@file_put_contents($run3 . '/audiofilterd.pid', json_encode(['pid' => 999999, 'socket' => $stale, 'started' => 0]) . "\n");
$d3 = afStart($stale, $run3, $log);
chk('it removed the leftover file and bound the socket anyway', $d3['up'], $d3['how'] . ' - see ' . $log);
chk('and the pid file now names a process that is really alive',
    $d3['pid'] > 0 && $d3['pid'] !== 999999 && (!function_exists('posix_kill') || @posix_kill($d3['pid'], 0)),
    (string) @file_get_contents($run3 . '/audiofilterd.pid'));
// A second start against a LIVE socket must be a no-op, not a fight over the path.
lrgTtsSpawnDaemon(lrgTtsCfg());
sleep(1);
chk('a second spawn against a live socket changes nothing (idempotent)',
    lrgTtsProbe($stale, 0.2)
    && (int) (json_decode((string) @file_get_contents($run3 . '/audiofilterd.pid'), true)['pid'] ?? 0) === $d3['pid'],
    'pid was ' . $d3['pid']);
afStop($stale);
$GLOBALS['LRG_TTS_TEST_CFG']['socket'] = $sock;
$GLOBALS['LRG_TTS_TEST_CFG']['run_dir'] = $dir;

// ---------------------------------------------------------------- 8. the supervisor's own hot path
head('8. the hook globals.php runs on every request');
$marker = $plugin . '/data/audiofilterd.next';
$markerExisted = @file_exists($marker);
$t0 = microtime(true);
for ($i = 0; $i < 200; $i++) { lrgTtsEnsureAudiofilterd(); }
$per = (microtime(true) - $t0) / 200 * 1000.0;
chk(sprintf('200 calls of lrgTtsEnsureAudiofilterd() cost %.3f ms each', $per), $per < 1.0, sprintf('%.3f ms', $per));
chk('it never throws when the socket is gone', (static function () {
    $keep = $GLOBALS['LRG_TTS_TEST_CFG']['socket'];
    $GLOBALS['LRG_TTS_TEST_CFG']['socket'] = '/nonexistent/dir/nope.sock';
    $GLOBALS['LRG_TTS_TEST_CFG']['enabled'] = false; // do not actually try to start one there
    try { lrgTtsEnsureAudiofilterd(); $good = true; } catch (Throwable $e) { $good = false; }
    unset($GLOBALS['LRG_TTS_TEST_CFG']['enabled']);
    $GLOBALS['LRG_TTS_TEST_CFG']['socket'] = $keep;
    return $good;
})());
if (!$markerExisted) { @unlink($marker); } // leave the source tree as we found it

// "enabled": false must mean off NOW, not off after the next reboot.
$sock4 = $dir . '/disable.sock';
@unlink($sock4);
$d4 = afStart($sock4, $dir . '/run4', $log);
if (chk('a daemon to switch off started', $d4['up'], $d4['how'])) {
    $GLOBALS['LRG_TTS_TEST_CFG']['enabled'] = false;
    @unlink(lrgTtsMarkerDir() . '/audiofilterd.next'); // force the slow path
    lrgTtsEnsureAudiofilterd();
    usleep(400000);
    chk('with enabled=false the hook asks the running daemon to stand down', !@file_exists($sock4),
        'socket still there: ' . $sock4);
    unset($GLOBALS['LRG_TTS_TEST_CFG']['enabled']);
}
$GLOBALS['LRG_TTS_TEST_CFG']['socket'] = $sock;
$GLOBALS['LRG_TTS_TEST_CFG']['run_dir'] = $dir;
@unlink(lrgTtsMarkerDir() . '/audiofilterd.next');

// ---------------------------------------------------------------- 9. the daemon's own self-test
head('9. the filter unit checks (php tts/lrg_audiofilterd.php --test)');
$outLines = [];
$rc = 0;
@exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($plugin . '/tts/lrg_audiofilterd.php') . ' --test 2>&1', $outLines, $rc);
foreach ($outLines as $l) { echo '  | ' . $l . "\n"; }
chk('--test passes', $rc === 0, 'exit ' . $rc);

// ---------------------------------------------------------------- stop the daemon we started
if (empty($args['keep'])) {
    afStop($sock);
    head('10. shutdown');
    chk('the daemon removed its own socket on the way out', !@file_exists($sock), $sock);
    chk('and its pid file', !@file_exists($dir . '/audiofilterd.pid'), (string) @file_get_contents($dir . '/audiofilterd.pid'));
}

printf("\n%d passed, %d failed   (work dir %s, log %s)\n", $ok, $fail, $dir, $log);
echo $fail === 0 ? "ALL CHECKS PASSED\n" : "RESULT: FAILED\n";
exit($fail === 0 ? 0 : 1);
