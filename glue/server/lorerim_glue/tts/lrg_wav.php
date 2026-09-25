<?php
/**
 * LoreRim Glue - WAV reader and ADAPTIVE leading-silence trimmer.  [0.5.2 / PT9]
 *
 * PocketTTS (audio.cpp) puts 0.25-0.31 s of near-silence in front of every line it synthesises
 * (research/pt9-tts-cpu.md §2 finding 5: median 0.283 s on GPU, 0.309 s on CPU, worst 0.63 s). CHIM
 * asks for a FIXED 250 ms trim; a fixed trim is wrong in both directions - it leaves 30-380 ms of dead
 * air on a typical line and would cut into the first word of the rare line that starts at 120 ms.
 *
 * So this file does what a fixed number cannot: it finds where the speech actually starts and removes
 * the silence in front of it, keeping a short lead-in so the first consonant never sounds clipped.
 *
 * Three rules it never breaks, because a wrong trim is far worse than no trim:
 *   1. It never removes more than `cap_ms` (900 ms). Past that the "silence" is probably the model
 *      breathing or a very soft opening word, and the measured worst case is 0.63 s.
 *   2. It never cuts into detected speech. `lead_in_ms` (40 ms) of the silence is always kept.
 *   3. Anything it does not fully understand - not RIFF/WAVE, not 16-bit PCM, a chunk layout that does
 *      not add up - is returned BYTE-IDENTICAL. Pass-through is always a valid answer here.
 *
 * Pure functions, no I/O, no globals: the daemon (lrg_audiofilterd.php) and the tests
 * (tools/test_audiofilterd.php) both call these directly.
 */

if (defined('LRG_WAV_LOADED')) { return; }
define('LRG_WAV_LOADED', true);

/**
 * Defaults for the trimmer. Every one of them is overridable per call (the daemon passes the
 * `tts.audiofilterd.trim` block of config/lrg_config.default.json straight through).
 *
 *   cap_ms          hard ceiling on what may be removed (ms).
 *   lead_in_ms      silence kept in front of the detected onset (ms) - anti-clipping.
 *   threshold_db    ABSOLUTE silence floor, dBFS. -40 dBFS is what ffmpeg's silencedetect used for the
 *                   measurements in pt9-tts-cpu.md, so the numbers there describe what this will find.
 *   relative_db     the same floor expressed BELOW the loudest sample of the scanned region, for a line
 *                   that was synthesised quietly. The higher of the two thresholds wins.
 *   ceiling_db      and the threshold is never allowed nearer the peak than this. It is what keeps a
 *                   QUIET line safe: measured on the owner's own soundcache, the quietest line peaks at
 *                   -24.5 dBFS, where a fixed -40 dBFS floor sits only 16 dB below the loudest sample
 *                   and eats the soft breath the word starts with. -30 dB below the peak tracks
 *                   ffmpeg's own -50 dB reading on that file to within 2 ms.
 *   window_ms       analysis window.
 *   sustain_ms      how long the signal must stay up for the crossing to count as speech and not a click.
 *   min_output_ms   never leave less audio than this behind.
 *   scan_extra_ms   how far past the cap to look before giving up on finding an onset.
 */
const LRG_WAV_TRIM_DEFAULTS = [
    'cap_ms'        => 900,
    'lead_in_ms'    => 40,
    'threshold_db'  => -40.0,
    'relative_db'   => -35.0,
    'ceiling_db'    => -30.0,
    'window_ms'     => 10,
    'sustain_ms'    => 30,
    'min_output_ms' => 120,
    'scan_extra_ms' => 1000,
    // false (the default) = adaptive wins: when the real silence is SHORTER than the milliseconds the
    // client asked for, we remove only the real silence rather than eating the first word. true = do what
    // CHIM literally asked and remove the requested milliseconds whenever any silence at all is present.
    'honor_client_minimum' => false,
];

/**
 * Read the header of a WAV. null = "this is not something we understand", which always means
 * pass-through. Never throws, never trusts a length field it can check.
 *
 * Returns: ['format','channels','rate','bits','block_align','data_hdr','data_off','data_len','tail_off']
 * where data_hdr is the offset of the 8-byte 'data' chunk header and tail_off the first byte after the
 * sample data (there can be LIST/INFO chunks behind it, and they are preserved verbatim).
 */
function lrgWavParse(string $b): ?array
{
    $n = strlen($b);
    if ($n < 44 || substr($b, 0, 4) !== 'RIFF' || substr($b, 8, 4) !== 'WAVE') { return null; }

    $off = 12;
    $fmt = null;
    $data = null;
    $guard = 0;
    while ($off + 8 <= $n && ++$guard < 256) {
        $id = substr($b, $off, 4);
        $u = @unpack('V', substr($b, $off + 4, 4));
        if (!is_array($u)) { return null; }
        $sz = (int) $u[1];
        $body = $off + 8;
        if ($sz < 0 || $body + $sz > $n) { $sz = $n - $body; } // truncated or streaming writer: take the rest

        if ($id === 'fmt ' && $sz >= 16) {
            $f = @unpack('vformat/vchannels/Vrate/VbyteRate/vblock/vbits', substr($b, $body, 16));
            if (!is_array($f)) { return null; }
            $format = (int) $f['format'];
            // WAVE_FORMAT_EXTENSIBLE (0xFFFE) is ordinary PCM when the SubFormat GUID starts 0x00000001.
            if ($format === 0xFFFE && $sz >= 40) {
                $g = @unpack('V', substr($b, $body + 24, 4));
                if (is_array($g) && (int) $g[1] === 1) { $format = 1; }
            }
            $fmt = ['format' => $format, 'channels' => (int) $f['channels'], 'rate' => (int) $f['rate'],
                'bits' => (int) $f['bits'], 'block_align' => (int) $f['block']];
        } elseif ($id === 'data') {
            // A streaming writer leaves 0 or 0xFFFFFFFF here; both mean "to the end of the file".
            if ($sz <= 0) { $sz = $n - $body; }
            $data = ['hdr' => $off, 'off' => $body, 'len' => $sz];
            break; // never walk past data: its length is the field most often wrong
        }
        $off = $body + $sz + ($sz % 2);
    }
    if ($fmt === null || $data === null) { return null; }

    $block = $fmt['block_align'] > 0 ? $fmt['block_align'] : max(1, $fmt['channels'] * (int) ($fmt['bits'] / 8));
    if ($fmt['rate'] <= 0 || $fmt['channels'] <= 0) { return null; }

    // NOT `$fmt + [...]`: PHP's array union keeps the LEFT value, so a blockAlign of 0 in the file would
    // survive the repair above and every frame calculation after it would divide by zero.
    return ['format' => $fmt['format'], 'channels' => $fmt['channels'], 'rate' => $fmt['rate'],
        'bits' => $fmt['bits'], 'block_align' => $block, 'data_hdr' => $data['hdr'],
        'data_off' => $data['off'], 'data_len' => $data['len'],
        'tail_off' => $data['off'] + $data['len']];
}

/** Is this something the trimmer may touch at all? '' = yes, otherwise the reason it is passed through. */
function lrgWavUnsupportedReason(?array $w): string
{
    if ($w === null) { return 'not a RIFF/WAVE file'; }
    if ((int) $w['format'] !== 1) { return 'not PCM (format=' . $w['format'] . ')'; }
    if ((int) $w['bits'] !== 16) { return 'not 16-bit (bits=' . $w['bits'] . ')'; }
    if ((int) $w['data_len'] < 2) { return 'no sample data'; }
    return '';
}

/**
 * Where does the speech start? Returns ['onset_ms' => float|null, 'peak' => int, 'threshold' => float,
 * 'scanned_ms' => float]. onset_ms null = nothing in the scanned region ever crossed the threshold
 * (the whole opening is silence, or the line is silence).
 *
 * The work is BOUNDED: only the first cap+lead+scan_extra milliseconds are ever unpacked, so a 60 s
 * file costs exactly as much as a 2 s one. That is what keeps the per-request budget honest.
 */
function lrgWavOnset(string $b, array $w, array $opt = []): array
{
    $o = $opt + LRG_WAV_TRIM_DEFAULTS;
    $rate = (int) $w['rate'];
    $block = (int) $w['block_align'];
    $chan = max(1, (int) $w['channels']);

    $scanMs = (float) $o['cap_ms'] + (float) $o['lead_in_ms'] + (float) $o['scan_extra_ms'];
    $scanBytes = (int) floor($scanMs / 1000.0 * $rate) * $block;
    $scanBytes = max($block, min((int) $w['data_len'], $scanBytes));
    $scanBytes -= $scanBytes % $block; // whole frames only

    $raw = substr($b, (int) $w['data_off'], $scanBytes);
    $vals = @unpack('v*', $raw);
    if (!is_array($vals) || $vals === []) {
        return ['onset_ms' => null, 'peak' => 0, 'threshold' => 0.0, 'scanned_ms' => 0.0];
    }
    $vals = array_values($vals); // 0-based; interleaved channels, all treated alike

    // |sample| without the sign dance: unpack('v') gives unsigned, and a two's-complement 16-bit value
    // v >= 32768 has magnitude 65536 - v.
    $count = count($vals);
    $peak = 0;
    for ($i = 0; $i < $count; $i++) {
        $v = $vals[$i];
        $a = $v < 32768 ? $v : 65536 - $v;
        $vals[$i] = $a;
        if ($a > $peak) { $peak = $a; }
    }

    $abs = 32767.0 * pow(10.0, ((float) $o['threshold_db']) / 20.0);
    $rel = $peak * pow(10.0, ((float) $o['relative_db']) / 20.0);
    $thr = max($abs, $rel);
    // ... but never nearer the peak than ceiling_db. On a quietly synthesised line the absolute floor
    // would otherwise sit close under the speech itself and swallow its soft attack.
    if ($peak > 0) { $thr = min($thr, $peak * pow(10.0, ((float) $o['ceiling_db']) / 20.0)); }

    $winFrames = max(1, (int) round(((float) $o['window_ms']) / 1000.0 * $rate));
    $winSamples = $winFrames * $chan;
    $sustainWins = max(1, (int) ceil(((float) $o['sustain_ms']) / max(1.0, (float) $o['window_ms'])));

    $voiced = [];
    $firstAbove = [];
    for ($s = 0, $k = 0; $s < $count; $s += $winSamples, $k++) {
        $end = min($count, $s + $winSamples);
        $hit = -1;
        for ($i = $s; $i < $end; $i++) {
            if ($vals[$i] >= $thr) { $hit = $i; break; }
        }
        $voiced[$k] = $hit >= 0;
        $firstAbove[$k] = $hit;
    }

    $nWins = count($voiced);
    $onset = null;
    for ($k = 0; $k < $nWins; $k++) {
        if (!$voiced[$k]) { continue; }
        // A click is one loud window with silence around it. Real speech keeps the level up.
        $need = (int) ceil($sustainWins / 2);
        $have = 0;
        $seen = 0;
        for ($j = $k; $j < min($nWins, $k + $sustainWins); $j++) { $seen++; if ($voiced[$j]) { $have++; } }
        if ($seen < $sustainWins) { $need = (int) ceil($seen / 2); } // end of the scan region: judge what we have
        if ($have >= $need) {
            $frame = (int) floor($firstAbove[$k] / $chan);
            $onset = $frame / $rate * 1000.0;
            break;
        }
    }

    return ['onset_ms' => $onset, 'peak' => $peak, 'threshold' => $thr,
        'scanned_ms' => ($scanBytes / $block) / $rate * 1000.0];
}

/**
 * Remove the leading silence. ALWAYS returns a usable buffer: on anything unexpected it hands back the
 * input unchanged with a reason, because the caller's contract with CHIM is "never make it worse".
 *
 * $requestedMs is what the client asked for (CHIM sends 250.0). See `honor_client_minimum`.
 *
 * Returns ['audio','trimmed_ms','reason','onset_ms','peak','threshold'].
 */
function lrgWavTrimStart(string $b, float $requestedMs, array $opt = []): array
{
    $o = $opt + LRG_WAV_TRIM_DEFAULTS;
    $none = static fn(string $why): array => ['audio' => $b, 'trimmed_ms' => 0.0, 'reason' => $why,
        'onset_ms' => null, 'peak' => 0, 'threshold' => 0.0];

    $w = lrgWavParse($b);
    $bad = lrgWavUnsupportedReason($w);
    if ($bad !== '') { return $none('passed through: ' . $bad); }

    $rate = (int) $w['rate'];
    $block = (int) $w['block_align'];
    if ($block <= 0 || ((int) $w['data_len']) % $block !== 0) {
        return $none('passed through: data length ' . $w['data_len'] . ' is not whole frames of ' . $block);
    }
    $totalMs = (((int) $w['data_len']) / $block) / $rate * 1000.0;

    $on = lrgWavOnset($b, $w, $o);
    $cap = max(0.0, (float) $o['cap_ms']);
    $lead = max(0.0, (float) $o['lead_in_ms']);

    if ($on['onset_ms'] === null) {
        // Nothing crossed the threshold in the scanned region. The opening really is silence - but we have
        // no idea where it ends, so we only do the conservative thing CHIM asked for.
        $trim = min((float) max(0.0, $requestedMs), $cap);
        $reason = 'no onset found in ' . round($on['scanned_ms']) . ' ms (peak ' . $on['peak'] . '): fixed trim';
    } else {
        $trim = max(0.0, $on['onset_ms'] - $lead);
        $reason = 'onset at ' . round($on['onset_ms'], 1) . ' ms';
        if ($trim > $cap) { $trim = $cap; $reason .= ', capped at ' . round($cap) . ' ms'; }
        if (!empty($o['honor_client_minimum']) && $requestedMs > $trim) {
            $trim = min((float) $requestedMs, $cap);
            $reason .= ', raised to the client minimum ' . round($requestedMs) . ' ms';
        }
    }

    // Never leave a stub behind.
    $minOut = max(0.0, (float) $o['min_output_ms']);
    if ($totalMs - $trim < $minOut) {
        $trim = max(0.0, $totalMs - $minOut);
        $reason .= ', held back to keep ' . round($minOut) . ' ms of audio';
    }

    $frames = (int) floor($trim / 1000.0 * $rate);
    $cut = $frames * $block;
    if ($cut <= 0 || $cut >= (int) $w['data_len']) {
        return ['audio' => $b, 'trimmed_ms' => 0.0, 'reason' => $reason . ' -> nothing to remove',
            'onset_ms' => $on['onset_ms'], 'peak' => $on['peak'], 'threshold' => $on['threshold']];
    }

    $dataOff = (int) $w['data_off'];
    $dataLen = (int) $w['data_len'];
    $newLen = $dataLen - $cut;
    $out = substr($b, 0, $dataOff)                                   // everything up to the samples, verbatim
        . substr($b, $dataOff + $cut, $newLen)                       // the samples, minus the silence
        . substr($b, (int) $w['tail_off']);                          // LIST/INFO etc. behind the data chunk

    // Two length fields have to follow the audio, or players read past the end.
    $out = substr_replace($out, pack('V', $newLen), ((int) $w['data_hdr']) + 4, 4);
    $out = substr_replace($out, pack('V', max(0, strlen($out) - 8)), 4, 4);

    return ['audio' => $out, 'trimmed_ms' => $frames / $rate * 1000.0, 'reason' => $reason,
        'onset_ms' => $on['onset_ms'], 'peak' => $on['peak'], 'threshold' => $on['threshold']];
}

/**
 * Build a 16-bit PCM WAV in memory. Used by the tests and by `--test`; kept here so the daemon and the
 * tests agree on what a WAV looks like byte for byte.
 *
 * $frames is a flat list of ints (-32768..32767), interleaved if $channels > 1.
 */
function lrgWavBuild(array $frames, int $rate = 24000, int $channels = 1): string
{
    $bytes = '';
    $chunk = [];
    foreach ($frames as $s) {
        $s = (int) $s;
        if ($s > 32767) { $s = 32767; }
        if ($s < -32768) { $s = -32768; }
        $chunk[] = $s;
        if (count($chunk) >= 4096) { $bytes .= pack('v*', ...array_map(static fn($x) => $x & 0xFFFF, $chunk)); $chunk = []; }
    }
    if ($chunk !== []) { $bytes .= pack('v*', ...array_map(static fn($x) => $x & 0xFFFF, $chunk)); }

    $block = $channels * 2;
    $hdr = 'RIFF' . pack('V', 36 + strlen($bytes)) . 'WAVE'
        . 'fmt ' . pack('V', 16) . pack('vvVVvv', 1, $channels, $rate, $rate * $block, $block, 16)
        . 'data' . pack('V', strlen($bytes));
    return $hdr . $bytes;
}

/** How much leading silence is left? For the tests' verdicts and for the log line. */
function lrgWavLeadingSilenceMs(string $b, array $opt = []): ?float
{
    $w = lrgWavParse($b);
    if (lrgWavUnsupportedReason($w) !== '') { return null; }
    $on = lrgWavOnset($b, $w, $opt);
    return $on['onset_ms'];
}

/** Audio length in ms, or null when this is not a WAV we read. */
function lrgWavDurationMs(string $b): ?float
{
    $w = lrgWavParse($b);
    if (lrgWavUnsupportedReason($w) !== '') { return null; }
    $block = max(1, (int) $w['block_align']);
    return (((int) $w['data_len']) / $block) / max(1, (int) $w['rate']) * 1000.0;
}
