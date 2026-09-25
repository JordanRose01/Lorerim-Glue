<?php
/**
 * [0.5.6 / PT15] The PLAYER's own words, repaired before anything reads them.
 *
 * Called from preprocessing.php for every player-speech request (inputtext*, ginputtext*, narrator_inputtext*),
 * BEFORE the MAIN lock and before every other reader (Phase 2, the intent recogniser, CHIM's event log, the
 * player subtitle). Three repairs, all measured on the owner's own recordings (research/pt15-stt.md):
 *
 *  1. EMPTY transcript. The DLL still sends the turn ("Jordan:\r\n\n") and CHIM drops it after the lock: the NPC
 *     never answers. parakeet-tdt-0.6b-v3 INT8 returned nothing for 3 of 54 real lines on 23 Sep (10 of 201 on
 *     21/22 Sep) although the voice is clearly there; the same audio with 250 ms of silence appended is
 *     transcribed correctly. CHIM keeps its own copy of the recording (stt.php -> soundcache/_stt_<md5>.wav), so
 *     the retry needs nothing from the game.
 *  2. A transcript far too short for the voice in it ("Wow, isn't it?" for "Wow, this city's just beautiful,
 *     isn't it?"): tried once more, and replaced only when the retry keeps every word of the first one, in order.
 *  3. NAMES. "Lazette" for Lisette: a word that is NOT an English word and is close to the name of somebody
 *     present right now becomes that name. Conservative by construction - see lrgSttMatch().
 *
 * Nothing here ever throws into the turn (the caller wraps it), ever blocks without a timeout, or runs at all for
 * any other request type. The whole step is off with stt.enabled = false.
 */

require_once __DIR__ . '/lrg_core.php';

/** Words a name must never replace, on top of the dictionary (lrg_stt_words.txt, CMUdict) and stt.protect. */
const LRG_STT_PROTECT = [
    // speech the dictionary does not carry
    'alright', 'gonna', 'wanna', 'gotta', 'kinda', 'sorta', 'lemme', 'gimme', 'dunno', 'yeah', 'yep', 'nope', 'okay',
    'hmm', 'uhh', 'umm', 'yall', 'aint', 'imma', 'tryna', 'outta', 'lotta', 'sup', 'dude', 'bro', 'bruh', 'lookin',
    // skills and game words
    'smithing', 'blacksmithing', 'enchanting', 'alchemy', 'archery', 'lockpicking', 'pickpocket', 'pickpocketing',
    'sneak', 'speechcraft', 'illusion', 'conjuration', 'destruction', 'restoration', 'alteration', 'onehanded',
    'twohanded', 'heavyarmor', 'lightarmor', 'septim', 'septims', 'sweetroll', 'sweetrolls', 'skooma', 'mead',
    'ebony', 'daedric', 'dwarven', 'elven', 'orcish', 'glass', 'dragonbone', 'dragonscale', 'stalhrim', 'nordic',
    // Tamriel
    'tamriel', 'skyrim', 'cyrodiil', 'morrowind', 'hammerfell', 'solstheim', 'whiterun', 'solitude', 'windhelm',
    'riften', 'markarth', 'falkreath', 'morthal', 'dawnstar', 'winterhold', 'riverwood', 'rorikstead', 'ivarstead',
    'helgen', 'karthwasten', 'shorsstone', 'dragonbridge', 'haafingar', 'hjaalmarch', 'eastmarch', 'reach', 'rift',
    'pale', 'sovngarde', 'jorrvaskr', 'dragonsreach', 'college', 'companions', 'thieves', 'guild', 'brotherhood',
    'dragonborn', 'dovahkiin', 'dovah', 'jarl', 'jarls', 'thane', 'housecarl', 'legion', 'legate', 'stormcloak',
    'stormcloaks', 'imperial', 'imperials', 'thalmor', 'altmer', 'bosmer', 'dunmer', 'orsimer', 'khajiit',
    'argonian', 'breton', 'redguard', 'nord', 'nords', 'orc', 'orcs', 'daedra', 'dwemer', 'falmer', 'draugr',
    'akatosh', 'arkay', 'dibella', 'julianos', 'kynareth', 'mara', 'stendarr', 'talos', 'zenithar', 'azura',
    'boethiah', 'hircine', 'malacath', 'mephala', 'meridia', 'molag', 'namira', 'nocturnal', 'peryite', 'sanguine',
    'sheogorath', 'vaermina', 'hermaeus', 'mora', 'mehrunes', 'dagon', 'alduin', 'paarthurnax', 'ulfric', 'tullius',
];

/** Name parts that are titles or plain words, never a target of a correction ("Captain Aldis" -> only "Aldis"). */
const LRG_STT_TITLES = [
    'the', 'of', 'and', 'captain', 'guard', 'jarl', 'lady', 'lord', 'sir', 'brother', 'sister', 'master',
    'mistress', 'old', 'young', 'aunt', 'uncle', 'legate', 'general', 'commander', 'steward', 'housecarl', 'thane',
    'court', 'wizard', 'mage', 'priest', 'priestess', 'beggar', 'bard', 'blacksmith', 'merchant', 'innkeeper',
    'hunter', 'farmer', 'soldier', 'narrator', 'player', 'high', 'king', 'queen', 'prince', 'princess',
];

function lrgSttCfg(): array
{
    $c = lrgConfig()['stt'] ?? [];
    return (is_array($c) ? $c : []) + [
        'enabled' => true, 'name_fix' => true, 'names' => [], 'protect' => [],
        'retry_empty' => true, 'retry_short' => true, 'retry_engines' => ['parakeet'],
        'retry_url' => 'http://127.0.0.1:8022/v1/audio/transcriptions', 'retry_timeout_seconds' => 3,
        'retry_variants' => ['tail:250', 'lead:500'], 'max_wav_age_seconds' => 6,
        'min_words_per_second' => 1.8, 'min_voiced_seconds' => 1.2,
    ];
}

/**
 * The one entry point (preprocessing.php). Rewrites $GLOBALS['gameRequest'][3] in place when - and only when -
 * something was repaired; the speaker label and the whitespace around the words are kept byte for byte.
 */
function lrgSttRepairRequest(): void
{
    $cfg = lrgSttCfg();
    if (empty($cfg['enabled']) || !lrgEnabled()) { return; }
    $raw = (string) ($GLOBALS['gameRequest'][3] ?? '');
    [$pre, $body, $post] = lrgSttSplitSpeech($raw);
    $new = $body;
    // Only a line that came from the microphone is ever re-transcribed. The DLL writes its STT result as
    // "Name:<CR><LF><words><LF>"; a TYPED line arrives as "Name:<words>" and has no recording of its own.
    $voice = (bool) preg_match('/^[^:\r\n]{1,80}:\r?\n/', $raw);
    if (!$voice) {
        // typed: names only
    } elseif (trim($body) === '') {
        if (!empty($cfg['retry_empty']) && lrgSttRetryAllowed($cfg)) {
            $got = lrgSttRetryEmpty($cfg);
            if ($got !== '') { $new = $got; $pre = rtrim($pre) . "\r\n"; $post = "\n"; } // the DLL's own shape
        }
    } elseif (!empty($cfg['retry_short']) && lrgSttRetryAllowed($cfg)) {
        $got = lrgSttRetryShort($body, $cfg);
        if ($got !== '') { $new = $got; }
    }
    if (!empty($cfg['name_fix']) && trim($new) !== '') {
        $fixes = [];
        $new = lrgSttFixNames($new, lrgSttNames($GLOBALS['gameRequest'] ?? [], $cfg), $fixes, $cfg);
        foreach ($fixes as $f) { lrgLog('stt fix: ' . $f[0] . ' -> ' . $f[1]); }
    }
    if ($new !== $body) { $GLOBALS['gameRequest'][3] = $pre . $new . $post; }
}

/** [label + leading whitespace, the words, trailing whitespace]. The label is what CHIM strips: /^[^:]+:/. */
function lrgSttSplitSpeech(string $raw): array
{
    $pre = '';
    if (preg_match('/^[^:\r\n]{1,80}:/', $raw, $m)) { $pre = $m[0]; $raw = (string) substr($raw, strlen($m[0])); }
    if (trim($raw) === '') { return [$pre . $raw, '', '']; }
    preg_match('/^(\s*)(.*?)(\s*)$/s', $raw, $m);
    return [$pre . $m[1], $m[2], $m[3]];
}

/** The retry repairs parakeet's instability: with another engine configured it has nothing to do. */
function lrgSttRetryAllowed(array $cfg): bool
{
    $engine = strtolower(trim((string) ($GLOBALS['STTFUNCTION'] ?? '')));
    return $engine === '' || in_array($engine, array_map('strtolower', (array) $cfg['retry_engines']), true);
}

// ------------------------------------------------------------------------------------------- 1 + 2: the retry

function lrgSttRetryEmpty(array $cfg): string
{
    $wav = lrgSttLatestWav((int) $cfg['max_wav_age_seconds']);
    if ($wav === null) { lrgLog('stt retry: empty transcript, no fresh recording in soundcache - nothing to retry'); return ''; }
    $audio = lrgSttWavRead($wav);
    if ($audio === null) { lrgLog('stt retry: empty transcript, recording is not 16-bit mono PCM - left alone'); return ''; }
    $voiced = lrgSttVoicedSeconds($audio['pcm'], $audio['rate']);
    if ($voiced < 0.3) {
        lrgLog(sprintf('stt retry: empty transcript, no voice in the recording (%.2f s voiced) - left alone', $voiced));
        return '';
    }
    $t0 = microtime(true);
    foreach ((array) $cfg['retry_variants'] as $variant) {
        $text = trim((string) lrgSttTranscribe(lrgSttWavBuild($audio['rate'], lrgSttPad($audio, (string) $variant)), $cfg));
        if ($text !== '') {
            lrgLog(sprintf('stt retry: empty transcript -> "%s" (%s, %d ms, %.2f s voiced)', $text, $variant,
                (int) round((microtime(true) - $t0) * 1000), $voiced));
            return $text;
        }
    }
    lrgLog(sprintf('stt retry: empty transcript, still empty after %d tries (%d ms, %.2f s voiced)',
        count((array) $cfg['retry_variants']), (int) round((microtime(true) - $t0) * 1000), $voiced));
    return '';
}

function lrgSttRetryShort(string $body, array $cfg): string
{
    $words = lrgSttWords($body);
    $minRate = (float) $cfg['min_words_per_second'];
    if ($words === [] || $minRate <= 0) { return ''; }
    $wav = lrgSttLatestWav((int) $cfg['max_wav_age_seconds']);
    if ($wav === null) { return ''; } // typed text, or an engine that keeps no copy
    $audio = lrgSttWavRead($wav);
    if ($audio === null) { return ''; }
    $seconds = strlen($audio['pcm']) / 2 / max(1, $audio['rate']);
    // cheap pre-check on the file length first: the voice can only be shorter than the file
    if (count($words) / max(0.001, $seconds) >= $minRate) { return ''; }
    $voiced = lrgSttVoicedSeconds($audio['pcm'], $audio['rate']);
    if ($voiced < (float) $cfg['min_voiced_seconds'] || count($words) / $voiced >= $minRate) { return ''; }
    $variant = (string) (((array) $cfg['retry_variants'])[0] ?? 'tail:250');
    $t0 = microtime(true);
    $text = trim((string) lrgSttTranscribe(lrgSttWavBuild($audio['rate'], lrgSttPad($audio, $variant)), $cfg));
    $ms = (int) round((microtime(true) - $t0) * 1000);
    if ($text !== '' && count(lrgSttWords($text)) > count($words) && lrgSttKeepsWords($body, $text)) {
        lrgLog(sprintf('stt retry: "%s" -> "%s" (%d -> %d words in %.2f s of voice, %s, %d ms)', $body, $text,
            count($words), count(lrgSttWords($text)), $voiced, $variant, $ms));
        return $text;
    }
    lrgLog(sprintf('stt retry: "%s" kept (%.2f s of voice; retry "%s", %d ms)', $body, $voiced, $text, $ms));
    return '';
}

/** Lowercased words, punctuation dropped ("isn't" stays one word). */
function lrgSttWords(string $text): array
{
    preg_match_all("/[a-z0-9]+(?:['\x{2019}][a-z]+)?/u", strtolower($text), $m);
    return $m[0];
}

/** True when every word of $orig appears in $retry in the same order (the retry only ADDS words). */
function lrgSttKeepsWords(string $orig, string $retry): bool
{
    $a = lrgSttWords($orig);
    $b = lrgSttWords($retry);
    $j = 0;
    foreach ($b as $w) {
        if ($j < count($a) && $w === $a[$j]) { $j++; }
    }
    return $a !== [] && $j === count($a);
}

/** CHIM's own copy of the newest recording (stt.php), when it is fresh enough to be THIS request's. */
function lrgSttLatestWav(int $maxAge): ?string
{
    $dir = $GLOBALS['LRG_STT_SOUNDCACHE'] ?? (dirname(LRG_DIR, 2) . '/soundcache');
    $best = null; $bestT = 0;
    foreach (glob($dir . '/_stt_*.wav') ?: [] as $f) {
        $t = (int) @filemtime($f);
        if ($t > $bestT) { $best = $f; $bestT = $t; }
    }
    if ($best === null || time() - $bestT > max(1, $maxAge)) { return null; }
    return $best;
}

/** ['rate' => int, 'pcm' => string] for a 16-bit mono PCM WAV, else null. Walks the chunks, so extra ones are fine. */
function lrgSttWavRead(string $path): ?array
{
    $b = @file_get_contents($path);
    if (!is_string($b) || strlen($b) < 44 || substr($b, 0, 4) !== 'RIFF' || substr($b, 8, 4) !== 'WAVE') { return null; }
    $pos = 12; $rate = 0; $ok = false; $pcm = null;
    while ($pos + 8 <= strlen($b)) {
        $id = substr($b, $pos, 4);
        $len = unpack('V', substr($b, $pos + 4, 4))[1];
        if ($id === 'fmt ' && $len >= 16) {
            $f = unpack('vfmt/vch/Vrate/Vbyterate/valign/vbits', substr($b, $pos + 8, 16));
            $ok = $f['fmt'] === 1 && $f['ch'] === 1 && $f['bits'] === 16;
            $rate = (int) $f['rate'];
        } elseif ($id === 'data') {
            $pcm = substr($b, $pos + 8, $len);
            break;
        }
        $pos += 8 + $len + ($len & 1);
    }
    if (!$ok || $rate <= 0 || !is_string($pcm) || strlen($pcm) < 2) { return null; }
    return ['rate' => $rate, 'pcm' => substr($pcm, 0, strlen($pcm) & ~1)];
}

function lrgSttWavBuild(int $rate, string $pcm): string
{
    return 'RIFF' . pack('V', 36 + strlen($pcm)) . 'WAVE' . 'fmt ' . pack('VvvVVvv', 16, 1, 1, $rate, $rate * 2, 2, 16)
        . 'data' . pack('V', strlen($pcm)) . $pcm;
}

/** "tail:250" = 250 ms of digital silence appended, "lead:500" = 500 ms in front; anything else = unchanged. */
function lrgSttPad(array $audio, string $variant): string
{
    if (!preg_match('/^(tail|lead):(\d{1,4})$/', trim($variant), $m)) { return $audio['pcm']; }
    $zeros = str_repeat("\0\0", (int) round($audio['rate'] * (int) $m[2] / 1000));
    return $m[1] === 'tail' ? $audio['pcm'] . $zeros : $zeros . $audio['pcm'];
}

/** Seconds from the first to the last 10 ms frame louder than -45 dBFS (0.0 when there is none). */
function lrgSttVoicedSeconds(string $pcm, int $rate): float
{
    $frame = max(1, intdiv($rate, 100));
    $s = unpack('s*', $pcm) ?: [];
    $n = count($s);
    $limit = 184.3 * 184.3 * $frame; // -45 dBFS as an RMS, squared, times the frame length
    $first = -1; $last = -1; $k = 0;
    for ($i = 1; $i + $frame - 1 <= $n; $i += $frame, $k++) {
        $sum = 0;
        for ($j = $i; $j < $i + $frame; $j++) { $sum += $s[$j] * $s[$j]; }
        if ($sum > $limit) { if ($first < 0) { $first = $k; } $last = $k; }
    }
    return $first < 0 ? 0.0 : ($last - $first + 1) * $frame / $rate;
}

/** One transcription of $wavBytes by the STT server (the same OpenAI-style endpoint CHIM's parakeet driver uses). */
function lrgSttTranscribe(string $wavBytes, array $cfg): ?string
{
    if (isset($GLOBALS['LRG_STT_TEST_TRANSCRIBE']) && is_callable($GLOBALS['LRG_STT_TEST_TRANSCRIBE'])) {
        return ($GLOBALS['LRG_STT_TEST_TRANSCRIBE'])($wavBytes);
    }
    $b = '----lrgstt' . md5((string) mt_rand() . microtime());
    $lang = (string) ($GLOBALS['STT']['PARAKEET']['LANG'] ?? 'en');
    $body = "--$b\r\nContent-Disposition: form-data; name=\"file\"; filename=\"lrg_retry.wav\"\r\nContent-Type: audio/wav\r\n\r\n"
        . $wavBytes . "\r\n--$b\r\nContent-Disposition: form-data; name=\"model\"\r\n\r\nwhisper-1\r\n"
        . "--$b\r\nContent-Disposition: form-data; name=\"language\"\r\n\r\n" . ($lang !== '' ? $lang : 'en') . "\r\n--$b--\r\n";
    $ctx = stream_context_create(['http' => [
        'method' => 'POST', 'timeout' => max(0.5, (float) $cfg['retry_timeout_seconds']), 'ignore_errors' => true,
        'header' => "Content-Type: multipart/form-data; boundary=$b\r\nContent-Length: " . strlen($body) . "\r\n",
        'content' => $body,
    ]]);
    $resp = @file_get_contents((string) $cfg['retry_url'], false, $ctx);
    if (!is_string($resp) || $resp === '') { return null; }
    $j = json_decode($resp, true);
    return is_array($j) && isset($j['text']) && is_string($j['text']) ? $j['text'] : null;
}

// ------------------------------------------------------------------------------------------------- 3: names

/**
 * lowercased name part => the part as written, for everybody the request says is here right now: the routing
 * snapshot CHIM's DLL attaches to every player line (speaker, listener, companions, present_actors), the player,
 * the current NPC and stt.names. Bracketed suffixes ("[Beggar]") and titles ("Captain") are dropped; parts
 * shorter than 4 letters are never a target.
 */
function lrgSttNames(array $req, array $cfg): array
{
    $labels = [(string) ($GLOBALS['PLAYER_NAME'] ?? '')];
    $herika = (string) ($GLOBALS['HERIKA_NAME'] ?? '');
    if ($herika !== '' && stripos($herika, 'narrator') === false) { $labels[] = $herika; }
    $raw = trim((string) ($req[4] ?? ''));
    $snap = $raw !== '' ? json_decode((string) base64_decode($raw, true), true) : null;
    if (is_array($snap)) {
        foreach (['speaker', 'listener'] as $k) { $labels[] = (string) ($snap[$k] ?? ''); }
        foreach ((array) ($snap['companions'] ?? []) as $c) { if (is_string($c)) { $labels[] = $c; } }
        foreach ((array) ($snap['present_actors'] ?? []) as $a) {
            if (is_array($a) && empty($a['creature']) && is_string($a['name'] ?? null)) { $labels[] = $a['name']; }
        }
    }
    foreach ((array) $cfg['names'] as $n) { if (is_string($n)) { $labels[] = $n; } }
    $out = [];
    foreach ($labels as $label) {
        $label = trim((string) preg_replace('/\[[^\]]*\]|\([^)]*\)/', ' ', $label));
        foreach (preg_split('/\s+/', $label) ?: [] as $part) {
            $part = trim($part, " \t.,'\"-");
            $lp = strtolower($part);
            if (strlen($part) < 4 || !preg_match("/^[a-z][a-z'\-]*$/", $lp) || in_array($lp, LRG_STT_TITLES, true)) { continue; }
            $out[$lp] = $out[$lp] ?? $part;
        }
    }
    return $out;
}

/**
 * $text with every near-miss of a present name replaced; $fixes collects [from, to]. Text inside (...) is never
 * touched. A word is only ever changed when ALL of these hold: it has 4+ letters and is not an acronym, it is not
 * already one of the names, it is close to exactly ONE name (lrgSttMatch) and it is not an English word
 * (dictionary, LRG_STT_PROTECT, stt.protect - checked last, so the dictionary is only read when a candidate exists).
 */
function lrgSttFixNames(string $text, array $names, array &$fixes, array $cfg = []): string
{
    if ($names === [] || trim($text) === '') { return $text; }
    $pieces = preg_split('/(\([^)]*\))/', $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$text];
    foreach ($pieces as $i => $piece) {
        if ($piece === '' || $piece[0] === '(') { continue; }
        $pieces[$i] = (string) preg_replace_callback("/[A-Za-z][A-Za-z'\x{2019}\-]*/u", function (array $m) use ($names, &$fixes, $cfg): string {
            $tok = $m[0];
            $suffix = '';
            if (preg_match("/^(.+?)(['\x{2019}]s|['\x{2019}])$/ui", $tok, $p)) { $tok = $p[1]; $suffix = $p[2]; }
            if (strlen($tok) < 4 || preg_match("/['\x{2019}]/u", $tok) || (ctype_upper($tok) && strlen($tok) > 1)) { return $m[0]; }
            $lt = strtolower($tok);
            if (isset($names[$lt])) { return $m[0]; }
            $to = lrgSttMatch($lt, $names);
            if ($to === null || lrgSttIsWord($lt, $cfg)) { return $m[0]; }
            $fixes[] = [$m[0], $to . $suffix];
            return $to . $suffix;
        }, $piece);
    }
    return implode('', $pieces);
}

/**
 * The one name $t (lowercase) is a near-miss of, or null. Same first sound (c/k/q, s/z, any vowel), length within
 * 2, and either an edit distance of at most 1 (names of 4-5 letters) / 2 (6-8) / 3 (9+), or the same consonant
 * skeleton ("lazat" and "Lisette" are both l-s-t) within 60 % of the name. A tie between two names = no change.
 */
function lrgSttMatch(string $t, array $names): ?string
{
    $lt = strlen($t);
    if ($lt < 4) { return null; }
    $st = lrgSttSkeleton($t);
    if ($st === '') { return null; }
    $best = null; $bestD = PHP_INT_MAX; $tie = false;
    foreach ($names as $lp => $canon) {
        $lp = (string) $lp;
        $len = strlen($lp);
        if ($len < 4 || abs($lt - $len) > 2) { continue; }
        $sn = lrgSttSkeleton($lp);
        if ($sn === '' || $st[0] !== $sn[0]) { continue; }
        $d = levenshtein($t, $lp);
        if ($d === 0) { return null; }
        $limit = $len <= 5 ? 1 : ($len <= 8 ? 2 : 3);
        if (!($d <= $limit || ($st === $sn && strlen($sn) >= 3 && $d <= (int) ceil($len * 0.6)))) { continue; }
        if ($d < $bestD) { $best = (string) $canon; $bestD = $d; $tie = false; }
        elseif ($d === $bestD && strcasecmp((string) $best, (string) $canon) !== 0) { $tie = true; }
    }
    return ($best !== null && !$tie) ? $best : null;
}

/** First sound + consonants: lisette, lazette, lizette, lazat -> "lst". Vowel-initial words start with "a". */
function lrgSttSkeleton(string $w): string
{
    $w = (string) preg_replace('/[^a-z]/', '', strtolower($w));
    if ($w === '') { return ''; }
    $w = strtr($w, ['ph' => 'f', 'ck' => 'k', 'qu' => 'kw', 'x' => 'ks', 'c' => 'k', 'q' => 'k', 'z' => 's']);
    $first = strpos('aeiouy', $w[0]) !== false ? 'a' : $w[0];
    return (string) preg_replace('/(.)\1+/', '$1', $first . preg_replace('/[aeiouyhw]/', '', substr($w, 1)));
}

/**
 * Is $w (lowercase) an English word? The dictionary is CMUdict's 123k words (lib/lrg_stt_words.txt), read at most
 * once per request and only when a name candidate exists. A missing dictionary fails CLOSED: every word counts.
 */
function lrgSttIsWord(string $w, array $cfg = []): bool
{
    static $dict = null;
    if (in_array($w, LRG_STT_PROTECT, true)) { return true; }
    foreach ((array) ($cfg['protect'] ?? []) as $p) { if (is_string($p) && strcasecmp($p, $w) === 0) { return true; } }
    if ($dict === null) {
        $dict = (string) @file_get_contents(__DIR__ . '/lrg_stt_words.txt');
        if ($dict === '') { lrgLog('stt: lib/lrg_stt_words.txt is missing - name correction is off'); }
    }
    if ($dict === '') { return true; }
    foreach ([$w, $w . 'g', (string) preg_replace('/s$/', '', $w)] as $form) { // lookin -> looking, plurals
        if ($form !== '' && strpos($dict, "\n" . $form . "\n") !== false) { return true; }
    }
    return false;
}
