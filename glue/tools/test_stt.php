<?php
// Offline test of lib/lrg_stt.php [0.5.6 / PT15]: the player's own words, repaired before anything reads them.
// Usage (inside WSL, from a staged copy):  php tools/test_stt.php [--quiet] [--live]
//
// Section A is the owner's REAL playthrough of 23 Sep 2026: every line parakeet produced that night (player lines
// only, verbatim from apache's error.log), run against every name that was present that night at once. Exactly one
// line may change - "Hey Lazette" - and nothing else, ever. B: the corrector's edges (the words it must never touch).
// C: the request rewrite. D: the retry, with a stand-in for the STT server. --live (E) sends the owner's real empty
// recordings of that night to the real parakeet server through this code, if they are still in soundcache.
//
// No HerikaServer, no DB, no LLM.
require __DIR__ . '/../server/lorerim_glue/lib/lrg_stt.php';

$fails = 0; $pass = 0;
$quiet = in_array('--quiet', $argv, true);
function check(string $what, bool $ok, string $detail = ''): void {
    global $fails, $pass;
    $ok ? $pass++ : $fails++;
    printf("  [%s] %s%s\n", $ok ? 'ok' : 'FAIL', $what, (!$ok && $detail !== '') ? "  <- $detail" : '');
}
function say(string $line = ''): void { global $quiet; if (!$quiet) { echo $line . "\n"; } }
$GLOBALS['PLAYER_NAME'] = 'Jordan';
unset($GLOBALS['HERIKA_NAME'], $GLOBALS['STTFUNCTION']);

/** Everybody the routing snapshots named on 23 Sep (the whole night at once - a harder test than any one line). */
const NIGHT = ['Lisette', 'Jordan', 'Katana', 'Braste', 'Gulum-Ei', 'Beirand', 'Maul Cold-Raven [Beggar]',
    'Noster Eagle-Eye', 'Captain Aldis', 'Bjorskir [Solitude Guard]', 'Azzadal [Mage]', 'Octieve San', 'Jawanan',
    'Corpulus Vinius', 'Evette San', 'Mikael', 'Sorex Vinius', 'Ahtar', 'Vittoria Vici'];
function names(array $labels): array {
    $snap = base64_encode(json_encode(['speaker' => 'Jordan', 'companions' => $labels]));
    return lrgSttNames([3 => '', 4 => $snap], ['names' => []]);
}
function fix(string $text, array $labels = NIGHT, array &$fixes = null): string {
    $fixes = [];
    return lrgSttFixNames($text, names($labels), $fixes, []);
}

// ------------------------------------------------------------------ A. the real night
echo "A. 23 Sep: every transcript of the night, every name of the night\n";
$night = [
    'Come closer.', 'Was that what should we do next?', "Well, we're in a private room already. Why don't you get naked?",
    'Okay. Take your clothes off.', 'Come kiss me.', "I'm giving me a hug.", 'Alright. Come have sex with me on the bed.',
    'There we go, now that that door is closed.', 'Hey.', 'I want you to get down on your knees and suck my throbbing cock right now.',
    "It's me.", 'Take your clothes off.', 'Alright, see you later.', 'Did you hear what happened in',
    "Okay, so you didn't hear anything that room that I was just standing in with Liz?", 'Sorry to bother you. Have a good day.',
    'Anything there?', 'Hey Liz, anything new?', "Can't get a room around here.",
    "No, seriously, where's the person that I buy a room from around here?", "I'll I'll pay that.",
    'Excuse me, sir. Uh which room is it upstairs?', 'Hey, which room did you say was mine again?', 'Is this my room?',
    'Uh I need you to come with me and talk to me in private for a moment.', 'Okay, so come on, follow me now.',
    'Hey, are you gonna follow me?', 'Off.', 'Come give me a kiss.', 'Alright, now fuck me on this bed.', 'Yeah.',
    'But can you follow me?', "You can stop following me now, there's uh thank you.",
    "Would you be interested in having any sexual interactions with me tonight? I'm a very wealthy man.",
    "Well come on. Everybody's gotta pray.", 'And everybody has a price.', "If you were to fuck me, I'd give you a thousand gold.",
    'Heard any rumors?', 'Then the sun. What are you looking at me for?',
    'What are you talking about? What target might be taken care of? Dude, are you doing some illegal shit?',
    'Get the fuck out of here, you ugly ass bitch.', "Wow, isn't it?", "What's up, bro? Do you know where I could sign up for the Legion?",
    "Up man, I was looking to join the Legion. I'm kind of a low life, kind of a bomb. I need to do something with my life, you know.",
    'Like to take guard duty.', 'I was looking to maybe buy a weapon today.',
    "Anyway, you would sell me one of your Elven spears for three hundred and twenty two gold. That's all I have on me.",
    'Well you could train me in Smith.', 'Do you need any help around the forge?',
    "There's gotta be a reason you don't have your shirt on, right?", 'YOURE LOOKIN MIGHTY BEAUTiful tonight miss',
    'And stop following me.', "Well I miss you're looking mighty beautiful tonight.", "Wow this city's just beautiful, isn't it?",
];
$changed = [];
foreach ($night as $line) { if (fix($line) !== $line) { $changed[] = $line . ' => ' . fix($line); } }
check(count($night) . ' real lines, not one of them changed', $changed === [], implode(' | ', $changed));
$f = [];
check('"Hey Lazette." -> "Hey Lisette." (the night\'s one real miss)',
    fix('Hey Lazette. Now where was I? I forgot what I was talking about.', NIGHT, $f) === 'Hey Lisette. Now where was I? I forgot what I was talking about.');
check('... and it is reported as Lazette -> Lisette', $f === [['Lazette', 'Lisette']], json_encode($f));

// ------------------------------------------------------------------ B. the corrector's edges
echo "B. what is corrected, and what never is\n";
$rows = [
    // [text, labels, expected, note]
    ['hey lizette come here', ['Lisette'], 'hey Lisette come here', 'lowercase miss, proper case out'],
    ['Lazat, follow me.', ['Lisette'], 'Lisette, follow me.', 'same consonants l-s-t, 5 edits'],
    ["Lazette's room is upstairs", ['Lisette'], "Lisette's room is upstairs", 'possessive kept'],
    ['Lisette, come here', ['Lisette'], 'Lisette, come here', 'already right'],
    ['Hey Liz', ['Lisette'], 'Hey Liz', 'a nickname (3 letters) is never touched'],
    ['Hand me the brass key', ['Braste'], 'Hand me the brass key', 'brass: an English word'],
    ['Nice breast plate', ['Braste'], 'Nice breast plate', 'breast: same skeleton, still a word'],
    ['Play me an octave', ['Octieve San'], 'Play me an octave', 'octave'],
    ['A brand new sword', ['Beirand'], 'A brand new sword', 'brand'],
    ['That was the last time', ['Lisette'], 'That was the last time', 'last: l-s-t, a word'],
    ['Make a list of it', ['Lisette'], 'Make a list of it', 'list'],
    ['What is your alias', ['Captain Aldis'], 'What is your alias', 'alias'],
    ['The captian said so', ['Captain Aldis'], 'The captian said so', 'a title is never a target'],
    ['Where is Bayrand', ['Beirand'], 'Where is Beirand', 'b-r-n-d, not a word'],
    ['Hey Katanna', ['Katana'], 'Hey Katana', 'one edit'],
    ['Talk to Jawanon', ['Jawanan'], 'Talk to Jawanan', ''],
    ['I met Mikel today', ['Mikael'], 'I met Mikel today', 'Mikel is a dictionary name: a miss beats a wrong change'],
    ['I met Mikaal today', ['Mikael'], 'I met Mikael today', ''],
    ['Hey Lasette', ['Lisette', 'Lysette'], 'Hey Lasette', 'two names equally close: no change'],
    ['Lazette (Talking to Lazette)', ['Lisette'], 'Lisette (Talking to Lazette)', 'text in (...) is never touched'],
    ['LZTT now', ['Lisette'], 'LZTT now', 'an acronym is never touched'],
    ['Hey Lazette', [], 'Hey Lazette', 'nobody present: nothing to correct to'],
    ['Pray to Mara', ['Maria'], 'Pray to Mara', 'a god of Tamriel is protected'],
    ['Train me in smithing', ['Smithe'], 'Train me in smithing', 'a skill is protected'],
    ['you gotta be kidding', ['Gotha'], 'you gotta be kidding', 'slang is protected'],
    ["I'm lookin for work", ['Lukin'], "I'm lookin for work", 'dropped g: lookin -> looking is a word'],
    ['Where is Cold-Rave', ['Maul Cold-Raven [Beggar]'], 'Where is Cold-Raven', 'hyphenated name'],
    ['hi Beggar', ['Maul Cold-Raven [Beggar]'], 'hi Beggar', 'a bracketed role is never a name'],
];
foreach ($rows as [$in, $labels, $want, $note]) {
    $got = fix($in, $labels);
    check(sprintf('%-32s -> %s%s', '"' . $in . '"', $want === $in ? 'unchanged' : '"' . $want . '"', $note !== '' ? "  ($note)" : ''),
        $got === $want, $got);
}
check('skeleton: lisette / lazette / lizette / lazat are all "lst"',
    lrgSttSkeleton('lisette') === 'lst' && lrgSttSkeleton('lazette') === 'lst' && lrgSttSkeleton('lizette') === 'lst' && lrgSttSkeleton('lazat') === 'lst');
$n = names(['Maul Cold-Raven [Beggar]', 'Captain Aldis', 'Jordan']);
check('routing names: parts, no titles, no [role], the player too',
    isset($n['maul'], $n['cold-raven'], $n['aldis'], $n['jordan']) && !isset($n['captain']) && !isset($n['beggar']), json_encode($n));
$snap = base64_encode(json_encode(['listener' => 'Lisette', 'present_actors' => [
    ['name' => 'Noster Eagle-Eye', 'creature' => false], ['name' => 'Skeever', 'creature' => true]]]));
$n = lrgSttNames([3 => '', 4 => $snap], ['names' => ['Serana']]);
check('routing names: listener + present people + stt.names, creatures left out',
    isset($n['lisette'], $n['noster'], $n['eagle-eye'], $n['serana']) && !isset($n['skeever']), json_encode($n));
check('dictionary present and read (brass is a word, lazette is not)', lrgSttIsWord('brass') && !lrgSttIsWord('lazette'));
check('stt.protect is honoured', lrgSttIsWord('lazette', ['protect' => ['Lazette']]));

// ------------------------------------------------------------------ C. the request rewrite
echo "C. the request: label and whitespace kept byte for byte\n";
function req(string $text, array $labels = ['Lisette']): string {
    $GLOBALS['gameRequest'] = ['inputtext', '1', '2', $text, base64_encode(json_encode(['speaker' => 'Jordan', 'listener' => $labels[0] ?? '', 'companions' => $labels]))];
    lrgSttRepairRequest();
    return $GLOBALS['gameRequest'][3];
}
$logFile = dirname(LRG_DIR, 2) . '/log/lorerim_glue.log';
$before = is_file($logFile) ? filesize($logFile) : 0;
check('microphone line: "Jordan:\r\nHey Lazette.\n" -> "Jordan:\r\nHey Lisette.\n"', req("Jordan:\r\nHey Lazette.\n") === "Jordan:\r\nHey Lisette.\n");
clearstatcache();
$tail = is_file($logFile) ? (string) file_get_contents($logFile, false, null, $before) : '';
check('... logged as "stt fix: Lazette -> Lisette"', str_contains($tail, 'stt fix: Lazette -> Lisette'), $tail);
check('typed line: "Jordan:hey lazette" -> "Jordan:hey Lisette"', req('Jordan:hey lazette') === 'Jordan:hey Lisette');
check('nothing to repair: the request is left identical', req("Jordan:\r\nTake your clothes off.\n") === "Jordan:\r\nTake your clothes off.\n");
check('no label at all still works', req('Lazette, wait') === 'Lisette, wait');

// ------------------------------------------------------------------ D. the retry (stand-in STT server)
echo "D. empty and too-short transcripts: transcribed again from CHIM's copy of the recording\n";
$dir = sys_get_temp_dir() . '/lrg_stt_test_' . getmypid();
@mkdir($dir);
$GLOBALS['LRG_STT_SOUNDCACHE'] = $dir;
/** 16 kHz mono: $lead s of near-silence, $voice s of a -20 dBFS tone, 0.2 s of near-silence. */
function mkwav(string $dir, float $lead, float $voice, int $ageSeconds = 0): string {
    $s = [];
    for ($i = 0; $i < (int) ($lead * 16000); $i++) { $s[] = $i % 7 === 0 ? 1 : 0; }
    for ($i = 0; $i < (int) ($voice * 16000); $i++) { $s[] = (int) round(3000 * sin($i * 2 * M_PI * 220 / 16000)); }
    for ($i = 0; $i < 3200; $i++) { $s[] = 0; }
    foreach (glob($dir . '/_stt_*.wav') ?: [] as $old) { @unlink($old); }
    $path = $dir . '/_stt_' . md5((string) microtime(true)) . '.wav';
    file_put_contents($path, lrgSttWavBuild(16000, pack('s*', ...$s)));
    if ($ageSeconds > 0) { touch($path, time() - $ageSeconds); clearstatcache(); }
    return $path;
}
$calls = [];
$answers = [];
$GLOBALS['LRG_STT_TEST_TRANSCRIBE'] = function (string $wav) use (&$calls, &$answers): ?string {
    $calls[] = strlen($wav);
    return array_shift($answers);
};
$w = mkwav($dir, 0.6, 1.2);
$a = lrgSttWavRead($w);
check('WAV read: 16 kHz mono PCM, samples intact', $a !== null && $a['rate'] === 16000 && strlen($a['pcm']) === ((int) (0.6 * 16000) + (int) (1.2 * 16000) + 3200) * 2);
check('voiced seconds of the tone: 1.2 s (+/- 0.02)', abs(lrgSttVoicedSeconds($a['pcm'], 16000) - 1.2) <= 0.02, (string) lrgSttVoicedSeconds($a['pcm'], 16000));
check('tail:250 appends exactly 250 ms, lead:500 prepends 500 ms',
    strlen(lrgSttPad($a, 'tail:250')) === strlen($a['pcm']) + 8000 && strlen(lrgSttPad($a, 'lead:500')) === strlen($a['pcm']) + 16000
    && substr(lrgSttPad($a, 'lead:500'), 16000) === $a['pcm']);

$calls = []; $answers = ['And stop following me.'];
check('EMPTY: "Jordan:\r\n\n" -> "Jordan:\r\nAnd stop following me.\n"', req("Jordan:\r\n\n") === "Jordan:\r\nAnd stop following me.\n");
check('... one call, with the recording + 250 ms of silence', count($calls) === 1 && $calls[0] === 44 + strlen($a['pcm']) + 8000, json_encode($calls));
$calls = []; $answers = ['', 'And stop following me.'];
check('EMPTY: the second variant is tried when the first is empty too', req("Jordan:\r\n\n") === "Jordan:\r\nAnd stop following me.\n" && count($calls) === 2);
$calls = []; $answers = ['', ''];
check('EMPTY: still empty after both -> the request is left as it was', req("Jordan:\r\n\n") === "Jordan:\r\n\n" && count($calls) === 2);
$calls = []; $answers = ['Hey Lazette.'];
check('EMPTY: a recovered line gets its names corrected too', req("Jordan:\r\n\n") === "Jordan:\r\nHey Lisette.\n");
mkwav($dir, 0.6, 1.2, 60);
$calls = []; $answers = ['stale'];
check('EMPTY: a recording older than max_wav_age_seconds is never used', req("Jordan:\r\n\n") === "Jordan:\r\n\n" && $calls === []);
mkwav($dir, 1.5, 0.0);
$calls = []; $answers = ['ghost'];
check('EMPTY: a recording with no voice in it is not sent anywhere', req("Jordan:\r\n\n") === "Jordan:\r\n\n" && $calls === []);
mkwav($dir, 0.6, 1.2);
$calls = []; $answers = ['typed'];
check('TYPED empty line ("Jordan:") has no recording: never retried', req('Jordan:') === 'Jordan:' && $calls === []);
$GLOBALS['STTFUNCTION'] = 'deepgram';
$calls = []; $answers = ['other engine'];
check('another STT engine configured: no retry', req("Jordan:\r\n\n") === "Jordan:\r\n\n" && $calls === []);
$GLOBALS['STTFUNCTION'] = 'parakeet';

mkwav($dir, 0.5, 2.0);
$calls = []; $answers = ["Wow this city's just beautiful, isn't it?"];
check('SHORT: "Wow, isn\'t it?" in 2 s of voice -> the fuller retry (it keeps every word)',
    req("Jordan:\r\nWow, isn't it?\n") === "Jordan:\r\nWow this city's just beautiful, isn't it?\n" && count($calls) === 1);
$calls = []; $answers = ['Something else entirely was said here'];
check('SHORT: a retry that drops or changes a word is thrown away', req("Jordan:\r\nWow, isn't it?\n") === "Jordan:\r\nWow, isn't it?\n");
$calls = []; $answers = ['Wow'];
check('SHORT: a retry with fewer words is thrown away', req("Jordan:\r\nWow, isn't it?\n") === "Jordan:\r\nWow, isn't it?\n");
$calls = []; $answers = ['never'];
check('a normal-length line is never retried (no call at all)',
    req("Jordan:\r\nI was looking to maybe buy a weapon today.\n") === "Jordan:\r\nI was looking to maybe buy a weapon today.\n" && $calls === []);
mkwav($dir, 0.5, 0.6);
$calls = []; $answers = ['never'];
check('a short line in a short recording is never retried ("Yeah." in 0.6 s)', req("Jordan:\r\nYeah.\n") === "Jordan:\r\nYeah.\n" && $calls === []);
check('keeps-words: order matters, punctuation and case do not',
    lrgSttKeepsWords("Wow, isn't it?", "wow this city's just beautiful isn't it") && !lrgSttKeepsWords("isn't it wow", "wow isn't it"));
foreach (glob($dir . '/*') ?: [] as $x) { @unlink($x); }
@rmdir($dir);
unset($GLOBALS['LRG_STT_TEST_TRANSCRIBE'], $GLOBALS['LRG_STT_SOUNDCACHE'], $GLOBALS['STTFUNCTION']);

// ------------------------------------------------------------------ the wiring
$pre = (string) file_get_contents(__DIR__ . '/../server/lorerim_glue/preprocessing.php');
check('preprocessing.php runs the repair before every other reader of the words',
    ($p = strpos($pre, 'lrgSttRepairRequest();')) !== false && $p < strpos($pre, 'lrgDlgHandleGameMessage') && $p < strpos($pre, 'lrgHandleGameMessage($gameRequest)'));

// ------------------------------------------------------------------ E. live (optional)
$live = array_values(preg_grep('/^--live(=|$)/', $argv));
if ($live !== []) {
    echo "E. LIVE: the owner's real empty recordings, through this code and the real parakeet server\n";
    $cfg = lrgSttCfg();
    if (str_starts_with($live[0], '--live=')) { $cfg['retry_url'] = substr($live[0], 7); } // e.g. a temporary instance
    $sc = '/var/www/html/HerikaServer/soundcache';
    // the three recordings parakeet returned nothing for on 23 Sep (04:31:27, 04:32:35, 04:36:17 EDT)
    foreach (glob($sc . '/_stt_*.wav') ?: [] as $f) {
        $t = (new DateTime('@' . (int) filemtime($f)))->setTimezone(new DateTimeZone('America/New_York'))->format('H:i:s');
        if (!in_array($t, ['04:31:27', '04:32:35', '04:36:17'], true)) { continue; }
        $a = lrgSttWavRead($f);
        $t0 = microtime(true);
        $base = lrgSttTranscribe(lrgSttWavBuild($a['rate'], $a['pcm']), $cfg);
        $got = '';
        foreach ($cfg['retry_variants'] as $v) {
            $got = trim((string) lrgSttTranscribe(lrgSttWavBuild($a['rate'], lrgSttPad($a, $v)), $cfg));
            if ($got !== '') { break; }
        }
        printf("  %s  as sent: \"%s\"  retried: \"%s\"  (%d ms total)\n", $t, trim((string) $base), $got, (microtime(true) - $t0) * 1000);
    }
}

say();
printf("%d passed, %d failed\n", $pass, $fails);
if ($fails === 0) { echo "OK\n"; }
exit($fails === 0 ? 0 : 1);
