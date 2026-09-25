<?php
/**
 * LoreRim Glue - offline checks of the PHASE 2 PROMPT INDEX (design 4.2 / V04_BUILD_PLAN 5.1).
 *
 * It needs a built index: run, inside WSL,
 *     python3 tools/build_prompt_index.py --out server/lorerim_glue/data/prompt_index.ndjson
 * then
 *     php tools/test_prompt_index.php                # the ndjson only (no database at all)
 *     php tools/test_prompt_index.php --db           # ALSO load it into Postgres and query it back
 *     php tools/test_prompt_index.php --file=<path>  # a different index
 * Exit 0 = every check passed. The named assertions come from design 7.3's last row and, since 0.5.0,
 * from tools/fixtures/lrg_quest_npcs.json (plan 10.3 / 23) - ten quest NPCs and four service NPCs of
 * THIS load order, each asserted by name so a failure says which row it could not find.
 *
 * Nothing here writes anything except, with --db, the two index tables of migration 007.
 *
 * [0.5.0] --db RUNS MIGRATION 007, which MOVES public.lrg_prompt into the index schema. Point it at a
 * SCRATCH database unless you mean to migrate the live one:
 *     createdb -h localhost -U dwemer lrg_t
 *     LRG_PGDSN='host=localhost dbname=lrg_t user=dwemer password=dwemer' php tools/test_prompt_index.php --db
 */

error_reporting(E_ALL);
ini_set('display_errors', 'stderr');

$root = dirname(__DIR__);
require_once $root . '/server/lorerim_glue/lib/lrg_dialogue.php';

$args = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $a, $m)) { $args[$m[1]] = $m[2] ?? true; }
}
$file = is_string($args['file'] ?? null) ? $args['file'] : (LRG_DIR . '/data/prompt_index.ndjson');
$quiet = !empty($args['quiet']);

$ok = 0;
$fail = 0;
$notes = [];
function chk(string $name, $cond, $detail = ''): bool
{
    global $ok, $fail, $quiet;
    $good = (bool) (is_callable($cond) ? $cond() : $cond);
    if ($good) { $ok++; if (!$quiet) { echo "  [ok] $name\n"; } }
    else { $fail++; echo "  [FAIL] $name" . ($detail !== '' ? '  [' . substr((string) (is_callable($detail) ? $detail() : $detail), 0, 260) . ']' : '') . "\n"; }
    return $good;
}
function head(string $s): void { echo "\n$s\n"; }

// [pt17 / orchestrator] The STALE check and tools/build_prompt_index.py must hash the load order the same way.
// Python reads plugins.txt / modlist.txt in text mode (CRLF -> LF); the PHP side used to md5 the raw bytes, so a
// CRLF modlist.txt (this install's) made every facts line say INDEX-STALE all evening on a current index.
require_once $root . '/server/lorerim_glue/lib/lrg_prompt_index.php';
head('0. the load-order hash agrees with the builder');
chk('CRLF and LF profile files hash the same (the builder reads them in text mode)',
    function_exists('lrgPromptLoadOrderHash')
    && lrgPromptLoadOrderHash("*a.esp\r\n*b.esp\r\n", "+Mod A\r\n-Mod B\r\n") === lrgPromptLoadOrderHash("*a.esp\n*b.esp\n", "+Mod A\n-Mod B\n"));
chk('... a lone CR folds too, and the result is the plain md5 of the LF form',
    lrgPromptLoadOrderHash("*a.esp\r\n", "+M\r") === md5("*a.esp\n" . "\n" . "+M\n"));

// ------------------------------------------------------------------ 1. the norm rule, both directions
head('1. lrgPromptNorm() - the one key rule, mirrored in tools/build_prompt_index.py');
$cases = [
    'I need work.' => 'i need work',
    '(Persuade) Come on, you can tell me.' => 'come on you can tell me',
    'Come on, you can tell me. (Persuade)' => 'come on you can tell me',
    'Perhaps this will change your mind. (137 gold)' => 'perhaps this will change your mind',
    'Perhaps this will change your mind. (<BribeCost> gold)' => 'perhaps this will change your mind',
    'How about <Alias=SonsMajorHoldCapital>?' => 'how about <t>',
    "I'd like to rent a room." => "i'd like to rent a room",
    'Three nights. (34 gold)' => 'three nights',
    '  MIXED   Case   and   spaces  ' => 'mixed case and spaces',
    '[family] Will you call me father?' => 'will you call me father',
];
foreach ($cases as $raw => $want) {
    chk('norm("' . substr($raw, 0, 44) . '") = "' . $want . '"', lrgPromptNorm($raw) === $want, lrgPromptNorm($raw));
}
chk('the plugin\'s <BribeCost> form and the menu\'s digits normalise to the SAME key',
    lrgPromptNorm('Look, I am willing to pay. (<BribeCost> gold)')
    === lrgPromptNorm('Look, I am willing to pay. (137 gold)'), lrgPromptNorm('Look, I am willing to pay. (137 gold)'));

head('2. lrgPromptCost() - the corrected price regex (BRANCH C3)');
$prices = ['Perhaps this will change your mind. (137 gold)' => 137, 'Okay, this is for the spell. (Give 30 gold)' => 30,
    'Solitude. (1,000 gold)' => 1000, 'A week. (75 septims)' => 75, 'I will pay. (<BribeCost> gold)' => -1,
    'Riften. (<Global=CarriageCost> gold)' => -1, 'Come back later. (3 days left)' => 0,
    'That ritual takes time. (takes 6 hours)' => 0, 'I brought your fish. (goldfish)' => 0,
    'I need work.' => 0];
foreach ($prices as $raw => $want) {
    chk('cost("' . substr($raw, 0, 48) . '") = ' . $want, lrgPromptCost($raw) === $want, (string) lrgPromptCost($raw));
}

// ------------------------------------------------------------------ 3. the built index
head('3. the built index: ' . $file);
if (!is_file($file)) {
    echo "  [FAIL] no index file. Run, inside WSL: python3 tools/build_prompt_index.py\n";
    echo "\nRESULT: FAILED (no index)\n";
    exit(1);
}
$fh = fopen($file, 'rb');
$hdr = json_decode((string) fgets($fh, 1024 * 512), true) ?: [];
chk('line 1 is the header', ($hdr['_'] ?? '') === 'header', json_encode(array_slice($hdr, 0, 3)));
chk('the index version matches this server (' . LRG_PROMPT_INDEX_VERSION . ')',
    (int) ($hdr['v'] ?? 0) === LRG_PROMPT_INDEX_VERSION, (string) ($hdr['v'] ?? '?'));
chk('it was built from the Ultra profile', (string) ($hdr['profile'] ?? '') === 'Ultra', (string) ($hdr['profile'] ?? '?'));
chk('it carries a load-order hash for the staleness check', strlen((string) ($hdr['hash'] ?? '')) === 32,
    (string) ($hdr['hash'] ?? ''));
chk('every active plugin was read', (int) ($hdr['plugins_read'] ?? 0) === (int) ($hdr['plugins_active'] ?? -1),
    (int) ($hdr['plugins_read'] ?? 0) . '/' . (int) ($hdr['plugins_active'] ?? 0));
chk('more than 30,000 player prompts are indexed', (int) ($hdr['rows'] ?? 0) > 30000, (string) ($hdr['rows'] ?? 0));
chk('DNAM shared responses were resolved (13,000+)', (int) ($hdr['stats']['shared_resp_resolved'] ?? 0) > 10000,
    (string) ($hdr['stats']['shared_resp_resolved'] ?? 0));
chk('almost nothing is truly silent (< 100 of them)', (int) ($hdr['stats']['silent'] ?? 99999) < 100,
    (string) ($hdr['stats']['silent'] ?? '?'));
chk('DynamicStringDistributor rewrites NO dialogue string (plugin text == displayed text)',
    (int) ($hdr['dsd_dialogue_entries'] ?? -1) === 0 && empty($hdr['FAIL']),
    json_encode($hdr['dsd']['dialogue'] ?? []) . ' ' . (string) ($hdr['FAIL'] ?? ''));
chk('it says how many DSD files it scanned', (int) ($hdr['dsd']['files'] ?? 0) > 100, json_encode($hdr['dsd']['files'] ?? 0));
$kinds = (array) ($hdr['check_kinds'] ?? []);
sort($kinds);
chk('EXACTLY three engine check kinds exist in this load order: bribe, intimidate, persuade',
    $kinds === ['bribe', 'intimidate', 'persuade'], implode(',', $kinds));
chk('the kinds census is in the header, with counts and examples', count((array) ($hdr['kinds'] ?? [])) > 20,
    (string) count((array) ($hdr['kinds'] ?? [])));
chk('no mod added a deceive / charm / seduce ENGINE check',
    !preg_match('/deceive|charm|seduce/i', json_encode($hdr['kinds'] ?? [])), 'a new kind appeared - read the census');
chk('GetBaseActorValue(Speechcraft) is counted apart as a trainer cap, not as a check',
    (int) ($hdr['stats']['speech_base_av'] ?? -1) >= 0, (string) ($hdr['stats']['speech_base_av'] ?? '?'));
chk('compound checks are a handful, not the amulet OR-branch (that is counted apart)',
    (int) ($hdr['stats']['compound'] ?? 0) > 0 && (int) ($hdr['stats']['compound'] ?? 999) < 60
    && (int) ($hdr['stats']['amulet_or_branch'] ?? 0) > 100,
    json_encode([$hdr['stats']['compound'] ?? '?', $hdr['stats']['amulet_or_branch'] ?? '?']));
chk('the DIAL-subtype filter is in force: the subtype census names CUST',
    str_contains(json_encode($hdr['subtypes'] ?? []), 'CUST'), json_encode($hdr['subtypes'] ?? []));
chk('a token-prompt pattern set exists', (int) ($hdr['stats']['token_patterns'] ?? 0) > 500,
    (string) ($hdr['stats']['token_patterns'] ?? 0));
chk('LETHAL rows were found (DGCrimeResistArrest)', (int) ($hdr['stats']['lethal'] ?? 0) > 0,
    (string) ($hdr['stats']['lethal'] ?? 0));
$notes[] = 'unreadable (engine-only): ' . count((array) ($hdr['unreadable'] ?? [])) . ' plugin(s) - '
    . implode(', ', array_map(static fn($u) => (string) $u['plugin'], array_slice((array) ($hdr['unreadable'] ?? []), 0, 4)));

// ------------------------------------------------------------------ 4. the named rows of design 7.3
head('4. the named assertions of design 7.3 (the real load order, not a fixture)');
$byTopic = [];
$byNorm = [];
$layers = 0;
while (($line = fgets($fh)) !== false) {
    $o = json_decode($line, true);
    if (!is_array($o)) { continue; }
    if (($o['_'] ?? '') === 'layer') { $layers++; continue; }
    $tp = (string) ($o['topic'] ?? '');
    if ($tp !== '') { $byTopic[$tp][] = $o; }
    $byNorm[(string) ($o['norm'] ?? '')][] = $o;
}
fclose($fh);
$topic = static fn(string $t) => $byTopic[$t] ?? [];

// MG03 Caller's books: the success and the failure prompt of one topic map to the two variants
$mg = $topic('MG03CallerBookPersuade');
chk('MG03CallerBookPersuade has both variants', count($mg) >= 2
    && count(array_filter($mg, static fn($r) => $r['variant'] === 'success')) >= 1
    && count(array_filter($mg, static fn($r) => $r['variant'] === 'failure')) >= 1,
    json_encode(array_map(static fn($r) => [$r['variant'], $r['kind']], $mg)));
chk('its success INFO is the one that carries the persuade condition',
    (bool) array_filter($mg, static fn($r) => $r['variant'] === 'success' && $r['kind'] === 'persuade'),
    json_encode(array_map(static fn($r) => [$r['variant'], $r['kind']], $mg)));
chk('both INFOs of that topic carry topic_kind=persuade, so a shown FAILURE variant is still recognised',
    count(array_filter($mg, static fn($r) => ($r['topic_kind'] ?? '') === 'persuade')) === count($mg),
    json_encode(array_map(static fn($r) => $r['topic_kind'] ?? '-', $mg)));
chk('its full condition list is stored (the globals and the amulet are readable)',
    (bool) array_filter($mg, static fn($r) => str_contains(json_encode($r['conds'] ?? []), 'Speech')),
    json_encode(($mg[0]['conds'] ?? [])));

// MS11 Blood on the Ice: a scripted + Goodbye accusation must be commit-class
$ms11 = array_filter($byNorm, static function ($rows) {
    foreach ($rows as $r) { if (strncmp((string) ($r['quest'] ?? ''), 'MS11', 4) === 0 && $r['scripted'] && ($r['flags']['goodbye'] ?? 0)) { return true; } }
    return false;
});
chk('MS11 (Blood on the Ice) has scripted + Goodbye prompts: "commit and close"', count($ms11) > 0,
    (string) count($ms11));

// DGCrime: twat -> DGCrimeResistArrest = crit 2 (LETHAL)
$dg = [];
foreach ($byTopic as $tp => $rows) {
    if (strncmp($tp, 'DGCrime', 7) !== 0) { continue; }
    foreach ($rows as $r) { $dg[] = $r; }
}
chk('DGCrime* prompts exist', count($dg) > 3, (string) count($dg));
chk('every DGCrime prompt with a walk-away into DGCrimeResistArrest is graded LETHAL (crit=2)',
    !array_filter($dg, static fn($r) => strcasecmp((string) $r['twat'], 'DGCrimeResistArrest') === 0 && (int) $r['crit'] !== 2),
    json_encode(array_map(static fn($r) => [$r['twat'], $r['crit']], array_slice($dg, 0, 6))));
chk('"I submit. Take me to jail." style entries are indexed with their walk-away',
    (bool) array_filter($dg, static fn($r) => (string) $r['twat'] !== ''), json_encode(array_column($dg, 'twat')));

// the rent flow: the hub has NO price tag but DOES resolve a shared (DNAM) response; the durations are silent
$hub = $topic('RentRoomStartTopic');
chk('the Xtended Stay rent hub (RentRoomStartTopic) is indexed', count($hub) > 0, (string) count($hub));
chk('the hub carries NO price tag', !array_filter($hub, static fn($r) => (int) $r['cost'] !== 0),
    json_encode(array_column($hub, 'cost')));
chk('the hub DOES resolve a response (BRANCH D1 corrects "silent hub")',
    (bool) array_filter($hub, static fn($r) => trim((string) $r['resp']) !== ''),
    json_encode(array_column($hub, 'resp')));
$dur = [];
foreach ($byTopic as $tp => $rows) {
    foreach ($rows as $r) {
        if (str_contains(strtolower((string) $r['norm']), 'night') && (int) $r['cost'] !== 0 && (int) $r['toplevel'] === 0) { $dur[] = $r; }
    }
}
chk('priced duration entries exist one layer down', count($dur) > 0, (string) count($dur));

// token patterns
$tokRows = [];
foreach ($byNorm as $n => $rows) {
    foreach ($rows as $r) { if ((string) ($r['pattern'] ?? '') !== '') { $tokRows[] = $r; } }
}
chk('token prompts became wildcard patterns', count($tokRows) > 500, (string) count($tokRows));
$howAbout = array_values(array_filter($tokRows, static fn($r) => str_starts_with((string) $r['norm'], 'how about')));
chk('"How about <Alias=...>?" is one of them', count($howAbout) > 0, (string) count($howAbout));
if ($howAbout) {
    $GLOBALS['LRG_TEST_INDEX'] = ['rows' => $howAbout, 'layers' => []];
    $hit = lrgPromptLookup(['How about Riften?'])[0];
    chk('and the live menu line "How about Riften?" matches it through the pattern tier',
        is_array($hit) && (string) ($hit['matched'] ?? '') === 'pattern', json_encode($hit));
    unset($GLOBALS['LRG_TEST_INDEX']);
}

// PNAM inversion
$inv = (array) ($hdr['pnam_inverted'] ?? []);
chk('PNAM-inverted check topics are flagged in the header', count($inv) > 0, json_encode($inv));
$orc = array_filter($inv, static fn($i) => str_contains((string) $i['topic'], 'Ghorbash') || str_contains((string) $i['topic'], 'Borgakh'));
chk('the orc-follower topics (Ghorbash / Borgakh) are among them (BRANCH B6)', count($orc) >= 2,
    json_encode(array_column($inv, 'topic')));
$invRows = [];
foreach ($byNorm as $rows) {
    foreach ($rows as $r) { if (!empty($r['unreachable'])) { $invRows[] = $r; } }
}
chk('and the affected ROWS carry unreachable=1, so the server can report it', count($invRows) > 0,
    (string) count($invRows));
$notes[] = 'PNAM-inverted topics (report to the owner, change NOTHING): '
    . implode(' | ', array_map(static fn($i) => $i['kind'] . ' ' . $i['topic'], $inv));

// layers
chk('closed-layer fingerprints were built', $layers > 1000, (string) $layers);

// ------------------------------------------------------------------ 5. the lookup API on real rows
head('5. lrgPromptLookup / lrgPromptLayerKind on real rows (through the test seam, no database)');
$sample = [];
foreach (['I need work.', 'What have you got for sale?'] as $want) {
    $n = lrgPromptNorm($want);
    if (isset($byNorm[$n])) { $sample = array_merge($sample, array_slice($byNorm[$n], 0, 3)); }
}
$sample = array_merge($sample, $mg, $hub);
$GLOBALS['LRG_TEST_INDEX'] = ['rows' => $sample, 'layers' => []];
$realLine = (string) ($mg[0]['txt'] ?? ($hub[0]['txt'] ?? 'I need work.'));
$r = lrgPromptLookup([$realLine, 'A line no plugin in Skyrim has ever contained, truly.']);
chk('a real prompt from this load order is found ("' . substr($realLine, 0, 40) . '")', is_array($r[0]),
    json_encode($r[0]));
chk('an invented line is reported as unindexed (null), never guessed', $r[1] === null, json_encode($r[1]));
$recs = lrgPromptLookup(array_map(static fn($x) => (string) $x['txt'], array_slice($hub, 0, 3)));
chk('lrgPromptLayerKind answers root|closed|unknown', in_array(lrgPromptLayerKind($recs), ['root', 'closed', 'unknown'], true),
    lrgPromptLayerKind($recs));
chk('lrgPromptIndexStatus reports the seam', (string) (lrgPromptIndexStatus()['source'] ?? '') === 'test',
    json_encode(lrgPromptIndexStatus()));
unset($GLOBALS['LRG_TEST_INDEX']);

head('5b. the FAIL-SAFE MERGE: an ambiguous norm may never UNDER-claim risk');
// 1,601 norms in this load order are carried by more than one topic, and for 7 of them one candidate is a
// DGCrime arrest line (LETHAL) while another is ordinary. Picking the ordinary one would be the only way an
// advisory index could cause real harm, so every risk claim is raised to the strictest candidate's.
$amb = [];
foreach ($byNorm as $n => $rows) {
    if (count($rows) < 2) { continue; }
    if (max(array_map(static fn($r) => (int) $r['crit'], $rows)) === 2
        && min(array_map(static fn($r) => (int) $r['crit'], $rows)) < 2) { $amb[$n] = $rows; }
}
chk('this load order really has such norms (the DGCrime arrest lines)', count($amb) > 0, (string) count($amb));
if ($amb) {
    $n = array_key_first($amb);
    $GLOBALS['LRG_TEST_INDEX'] = ['rows' => $amb[$n], 'layers' => []];
    $hit = lrgPromptLookup([(string) $amb[$n][0]['txt']])[0];
    chk('a lookup of "' . substr((string) $amb[$n][0]['txt'], 0, 34) . '" comes back LETHAL whichever row won',
        is_array($hit) && (int) ($hit['crit'] ?? 0) === 2, json_encode(['crit' => $hit['crit'] ?? null, 'ambiguous' => $hit['ambiguous'] ?? null]));
    chk('and it says how ambiguous it was, so the log can show it',
        is_array($hit) && (int) ($hit['ambiguous'] ?? 0) === count($amb[$n]), json_encode($hit['ambiguous'] ?? null));
    unset($GLOBALS['LRG_TEST_INDEX']);
}
$GLOBALS['LRG_TEST_INDEX'] = ['rows' => [
    ['txt' => 'A shared line.', 'kind' => '', 'crit' => 0, 'scripted' => 0, 'cost' => 0, 'toplevel' => 1,
        'flags' => ['goodbye' => 0, 'walkaway' => 0, 'sayonce' => 0], 'topic_key' => 'a', 'info_key' => 'a1'],
    ['txt' => 'A shared line.', 'kind' => 'persuade', 'crit' => 1, 'scripted' => 1, 'cost' => 137, 'toplevel' => 1,
        'flags' => ['goodbye' => 1, 'walkaway' => 1, 'sayonce' => 1], 'topic_key' => 'b', 'info_key' => 'b1',
        'compound' => 1, 'twat' => 'SomeWalkAway'],
], 'layers' => []];
$m = lrgPromptLookup(['A shared line.'])[0];
chk('crit, scripted, cost, kind, twat, compound and the three flags all take the strictest value',
    is_array($m) && (int) $m['crit'] === 1 && (int) $m['scripted'] === 1 && (int) $m['cost'] === 137
    && (string) $m['kind'] === 'persuade' && (string) $m['twat'] === 'SomeWalkAway' && (int) $m['compound'] === 1
    && (int) $m['flags']['goodbye'] === 1 && (int) $m['flags']['walkaway'] === 1 && (int) $m['flags']['sayonce'] === 1,
    json_encode($m));
unset($GLOBALS['LRG_TEST_INDEX']);
chk('lrgPromptKinds() hands the census to the report and to the checks',
    count((array) (lrgPromptKinds()['census'] ?? [])) > 10, json_encode(array_keys(lrgPromptKinds())));

// ------------------------------------------------------------------ 5c. [0.5.7 / pt16-legion] the faction table vs THIS index
/*
 * lib/lrg_factions.php carries load-order facts (who recruits, on which topic). A load-order change moves
 * them silently, so every named topic of the table is checked against the index that was really built, and
 * the two records the Legion redirect is anchored on are read back in words.
 */
head('5c. [0.5.7 / pt16-legion] the faction truth table (lib/lrg_factions.php) against this index');
$facMissing = [];
$facOff = [];
foreach ((array) lrgFacCfg('rows', []) as $facId => $facRow) {
    foreach (array_merge((array) ($facRow['recruiter_topics'] ?? []), (array) ($facRow['redirect_topics'] ?? [])) as $tp) {
        if (str_contains((string) $tp, '*')) { continue; }        // a family glob is checked below
        $rows = $byTopic[(string) $tp] ?? [];
        if (!$rows) { $facMissing[] = $facId . ':' . $tp; continue; }
        $exact = array_map('strval', (array) ($facRow['entries'] ?? []));   // [pt17] an exact-line entry (FreeToGo) carries no join word by design
        if (!array_filter($rows, static fn($r) => lrgFacNormHasEntryWord((string) ($r['norm'] ?? '')) || in_array((string) ($r['norm'] ?? ''), $exact, true))) { $facOff[] = $facId . ':' . $tp; }
    }
}
chk('every named recruiter / redirect topic of the table exists in this index', $facMissing === [], implode(', ', $facMissing));
chk('...and each carries at least one prompt whose own words are about joining (the shared-topic guard would drop it otherwise)',
    $facOff === [], implode(', ', $facOff));
$ajoTopics = array_filter(array_keys($byTopic), static fn($t) => lrgGlob('ANDR_AJO_Guard*', (string) $t));
chk('the AJO guard-duty family exists (the captain\'s one real offer)', count($ajoTopics) > 0, (string) count($ajoTopics));
$guardArmy = $byTopic['SolitudeFreeformGuardSolitudeArmy'] ?? [];
chk('the Solitude guard\'s real answer sends him to Legate Rikke in Castle Dour (the Legion redirect is anchored on it)',
    (bool) array_filter($guardArmy, static fn($r) => stripos((string) ($r['resp'] ?? ''), 'Legate Rikke') !== false
        && stripos((string) ($r['resp'] ?? ''), 'Castle Dour') !== false), json_encode(array_column($guardArmy, 'resp')));
$rikkeRows = $byTopic['CW00RikkeBlockingTopic'] ?? [];
chk('Rikke\'s "About that test..." is the Fort Hraggstad test (where CW01 begins)',
    (bool) array_filter($rikkeRows, static fn($r) => stripos((string) ($r['resp'] ?? ''), 'Hraggstad') !== false),
    json_encode(array_column($rikkeRows, 'resp')));
$freeRows = $byTopic['CW00TulliusGreetFreeToGo'] ?? [];
chk('[pt17] Tullius\'s join line CW00TulliusGreetFreeToGo still exists (USSEP, one condition) - CLOSED on this save behind AP\x27s greet, see pt18, "Hmm. I suppose that\'s true"',
    (bool) array_filter($freeRows, static fn($r) => (string) ($r['plugin'] ?? '') === 'Unofficial Skyrim Special Edition Patch.esp'
        && (int) ($r['nconds'] ?? -1) === 1 && stripos((string) ($r['resp'] ?? ''), 'I suppose that') !== false),
    json_encode(array_map(static fn($r) => [$r['plugin'] ?? '', $r['nconds'] ?? null, $r['resp'] ?? ''], $freeRows)));
chk('no Legion topic of the table is Captain Aldis\'s own quest-giver topic (Favor110)',
    !in_array('Favor110QuestGiveTopicSolitude', array_merge((array) lrgFacCfg('rows.legion.recruiter_topics'),
        (array) lrgFacCfg('rows.legion.redirect_topics')), true));
foreach (['legion' => ['CW00TulliusGreetTalkRikkateAP' => 'AlternatePerspective.esp',
        'CW00RikkeBlockingTopic' => 'Unofficial Skyrim Special Edition Patch.esp',
        'SolitudeFreeformGuardSolitudeArmy' => 'Unofficial Skyrim Special Edition Patch.esp'],
    'companions' => ['C00KodlakJoinUpStartTopic' => 'Companions at Mirmulnir.esp']] as $facId => $wins) {
    foreach ($wins as $tp => $plugin) {
        $rows = $byTopic[$tp] ?? [];
        chk($facId . ': ' . $tp . ' is still won by ' . $plugin . ' (a load-order change would move the truth table)',
            (bool) array_filter($rows, static fn($r) => (string) ($r['plugin'] ?? '') === $plugin), json_encode(array_column($rows, 'plugin')));
    }
}
// [pt18-quest] the Legion road on this load order (research/pt18-quest.md 6): AP's Tullius greet 0D5146 is the winner with SIX
// conditions (the index carries no conds array for it - its GetGlobalValue(MQQuickstart) < 7 was read by record decode and
// refuter-verified; USSEP's has five); 0D5145 carries fn543 = GetQuestCompleted (the post-Helgen greet); the post-Helgen join
// line alternateperspective.esp:206783 is scripted (tif_ap_04206783.pex = SetStage 10) with one condition; the effect row of the
// faction table names exactly that entry; Rikke's test and its accept line as the row states; the FreeToGo line still exists.
$greetRows = $byTopic['CW00TulliusForcegreetTopic'] ?? [];
$g46 = array_values(array_filter($greetRows, static fn($r) => strcasecmp((string) ($r['info_key'] ?? ''), 'skyrim.esm:0D5146') === 0));
chk('[pt18-quest] AP\'s Tullius greet 0D5146 is won by AlternatePerspective.esp with six conditions (CLOSED on this save: MQQuickstart 7.0 vs < 7)',
    $g46 && (string) ($g46[0]['plugin'] ?? '') === 'AlternatePerspective.esp' && (int) ($g46[0]['nconds'] ?? -1) === 6, json_encode(array_map(static fn($r) => [$r['plugin'] ?? '', $r['nconds'] ?? null], $g46)));
$g45 = array_values(array_filter($greetRows, static fn($r) => strcasecmp((string) ($r['info_key'] ?? ''), 'skyrim.esm:0D5145') === 0));
chk('[pt18-quest] the post-Helgen greet 0D5145 (AP) carries fn543 = GetQuestCompleted == 1 and links "I was at Helgen" (0D5113)',
    $g45 && (bool) array_filter((array) ($g45[0]['conds'] ?? []), static fn($c) => (string) ($c[0] ?? '') === 'fn543' && (int) ($c[4] ?? 0) === 1)
    && in_array('skyrim.esm:0D5113', (array) ($g45[0]['links'] ?? []), true), json_encode($g45[0] ?? null));
$apJoin = $byTopic['CW00TulliusGreetTalkRikkateAP'] ?? [];
chk('[pt18-quest] the AP join line 206783 is scripted (SetStage 10), one condition (GetIsID), Goodbye, its norm is the row\'s recruiter line',
    (bool) array_filter($apJoin, static fn($r) => strcasecmp((string) ($r['info_key'] ?? ''), 'alternateperspective.esp:206783') === 0 && (int) ($r['scripted'] ?? 0) === 1
        && (int) ($r['nconds'] ?? -1) === 1 && (int) (($r['flags'] ?? [])['goodbye'] ?? 0) === 1
        && (string) ($r['norm'] ?? '') === lrgPromptNorm((string) lrgFacCfg('rows.legion.recruiters.General Tullius'))), json_encode(array_column($apJoin, 'info_key')));
$effT = (array) lrgFacCfg('rows.legion.effects.General Tullius');
chk('[pt18-quest] the effect row names that entry and info (a load-order change moves the effect table first)',
    (string) ($effT['entry'] ?? '') === 'CW00TulliusGreetTalkRikkateAP' && (string) ($effT['info'] ?? '') === 'alternateperspective.esp:206783'
    && (bool) array_filter($apJoin, static fn($r) => (string) ($r['topic'] ?? '') === (string) ($effT['entry'] ?? '') && (string) ($r['info_key'] ?? '') === (string) ($effT['info'] ?? '')));
chk('[pt18-quest] Rikke: CW00RikkeBlockingTopic 0D514F (USSEP) has four conditions; CW00RikkeGreetAcceptQuest 0D5153 is scripted (SetStage 20) with two',
    (bool) array_filter($byTopic['CW00RikkeBlockingTopic'] ?? [], static fn($r) => strcasecmp((string) ($r['info_key'] ?? ''), 'skyrim.esm:0D514F') === 0 && (int) ($r['nconds'] ?? -1) === 4)
    && (bool) array_filter($byTopic['CW00RikkeGreetAcceptQuest'] ?? [], static fn($r) => strcasecmp((string) ($r['info_key'] ?? ''), 'skyrim.esm:0D5153') === 0 && (int) ($r['scripted'] ?? 0) === 1 && (int) ($r['nconds'] ?? -1) === 2));

// ------------------------------------------------------------------ 5d. [pt19 v1.0 / Lane E] the questline fixture vs THIS index
/*
 * tools/test_questline.php walks the main quest and the guild openings over the real index; its fixture names every layer by
 * its PARENT (a layer line, or the parent row's links) and never hand-lists siblings. A load-order change that moves one of those
 * layers fails HERE first, by name: every fixture parent_info pins a live layer line (or the parent row's links resolve to one),
 * every single resolves to exactly its line, every root topic is a real top-level row; the single-entry chains the harness counts
 * on (the oaths, Brynjolf's approach) are still one-line layers; and Delphine's C2Horn hub keeps the shape S4.1's hub rule was
 * measured on (7 lines, 4 scripted, the scripted questions loop back, C5 is the one goodbye).
 */
head('5d. [pt19 v1.0] the questline fixture (tools/fixtures/lrg_questline.json) against this index');
$qlFx = json_decode((string) @file_get_contents(__DIR__ . '/fixtures/lrg_questline.json'), true);
if (!chk('the questline fixture is readable', is_array($qlFx) && ($qlFx['_'] ?? '') === 'lrg_questline')) { $qlFx = ['beats' => []]; }
// the layer lines (section 4 counted them only) and the rows by info / topic key
$qlLayers = [];
$qlLayerByParent = [];
$qlLayerByNorm = [];
$fh5 = fopen($file, 'rb');
fgets($fh5, 1024 * 512);
while (($line = fgets($fh5)) !== false) {
    if (strncmp($line, '{"_": "layer"', 13) !== 0) { continue; }
    $L = json_decode($line, true);
    if (!is_array($L)) { continue; }
    $li = count($qlLayers);
    $qlLayers[] = $L;
    $qlLayerByParent[(string) $L['parent_info']][] = $li;
    foreach ((array) $L['norms'] as $n) { $qlLayerByNorm[(string) $n][] = $li; }
}
fclose($fh5);
$qlByInfo = [];
$qlByTk = [];
foreach ($byTopic as $rowsOfTopic) {
    foreach ($rowsOfTopic as $r) {
        $qlByInfo[(string) ($r['info_key'] ?? '')] = $r;
        $qlByTk[(string) ($r['topic_key'] ?? '')][] = $r;
    }
}
// the visible lines a parent row's links resolve to: one per linked topic (the first row with prompt text), keyed by topic key
$qlLinked = static function (array $prow) use ($qlByTk): array {
    $out = [];
    foreach ((array) ($prow['links'] ?? []) as $tk) {
        foreach ($qlByTk[(string) $tk] ?? [] as $r) {
            if ((string) ($r['norm'] ?? '') !== '' && empty(($r['flags'] ?? [])['placeholder'])) { $out[(string) $tk] = $r; break; }
        }
    }
    return $out;
};
$qlBad = ['target' => [], 'closed' => [], 'single' => [], 'root' => []];
$qlN = ['target' => 0, 'closed' => 0, 'single' => 0, 'root' => 0];
$qlAll = array_merge((array) $qlFx['beats'], array_values(array_filter([($qlFx['cross'] ?? [])['lethal'] ?? null, ($qlFx['cross'] ?? [])['price_list'] ?? null])),
    (array) (($qlFx['cross'] ?? [])['leave'] ?? []));
// [pt19c-E fix 1] a chain beat (one sentence, several clicks) names one layer per step: each step is pinned like a beat of its kind
foreach ((array) $qlFx['beats'] as $b) {
    foreach ((array) ($b['chain'] ?? []) as $ci => $step) { $qlAll[] = ['id' => $b['id'] . ' step ' . ($ci + 1)] + (array) $step; }
}
foreach ($qlAll as $b) {
    $tg = (array) ($b['target'] ?? []);
    if (!$tg) { continue; }
    $qlN['target']++;
    $trow = $qlByInfo[(string) ($tg['info_key'] ?? '')] ?? null;
    if ($trow === null || (isset($tg['topic']) && (string) $trow['topic'] !== (string) $tg['topic'])) {
        $qlBad['target'][] = $b['id'] . ' ' . json_encode($tg);
        continue;
    }
    $lay = (array) ($b['layer'] ?? []);
    $kind = (string) ($lay['kind'] ?? '');
    $tn = (string) $trow['norm'];
    if ($kind === 'closed') {
        $qlN['closed']++;
        $p = (string) ($lay['parent_info'] ?? '');
        $qlPinned = false;   // (never $ok: this is the global scope, where $ok counts the passed checks)
        foreach ($qlLayerByParent[$p] ?? [] as $li) { if (in_array($tn, (array) $qlLayers[$li]['norms'], true)) { $qlPinned = true; } }
        if (!$qlPinned && isset($qlByInfo[$p])) {
            // layer lines are de-duplicated by their norm set: the parent row's links must resolve to exactly one of them
            $set = [];
            foreach ((array) $qlByInfo[$p]['links'] as $tk) {
                foreach ($qlByTk[(string) $tk] ?? [] as $r) { if ((string) ($r['norm'] ?? '') !== '') { $set[(string) $r['norm']] = 1; } }
            }
            $set = array_keys($set);
            sort($set);
            foreach ($qlLayerByNorm[$tn] ?? [] as $li) { $ns = array_map('strval', (array) $qlLayers[$li]['norms']); sort($ns); if ($ns === $set) { $qlPinned = true; } }
        }
        if (!$qlPinned) { $qlBad['closed'][] = $b['id'] . ' parent ' . $p . ' "' . $tn . '"'; }
    } elseif ($kind === 'single') {
        $qlN['single']++;
        $p = (string) ($lay['parent_info'] ?? '-');
        if ($p !== '-') {
            $vis = isset($qlByInfo[$p]) ? $qlLinked($qlByInfo[$p]) : [];
            $norms = array_values(array_unique(array_map(static fn($r) => (string) $r['norm'], $vis)));
            if (!isset($vis[(string) $trow['topic_key']]) || count($norms) !== 1) { $qlBad['single'][] = $b['id'] . ' parent ' . $p . ' -> ' . implode(' | ', $norms); }
        } elseif (!empty($qlLayerByNorm[$tn])) {
            // a prompt-less parent (no index row): no layer line may carry the line - it is a one-choice layer
            $qlBad['single'][] = $b['id'] . ' (prompt-less parent) but ' . count($qlLayerByNorm[$tn]) . ' layer line(s) carry "' . $tn . '"';
        }
    } elseif ($kind === 'root') {
        $qlN['root']++;
        foreach ((array) ($lay['topics'] ?? []) as $tp) {
            $tpName = is_array($tp) ? (string) ($tp['topic'] ?? '') : (string) $tp;
            $tops = array_filter($byTopic[$tpName] ?? [], static fn($r) => (string) ($r['norm'] ?? '') !== ''
                && ((int) ($r['toplevel'] ?? 0) === 1 || (string) ($r['quest'] ?? '') === 'DialogueGeneric'));
            if (!$tops) { $qlBad['root'][] = $b['id'] . ' ' . $tpName; }
            // [pt19c-E fix 1] a root entry naming its INFO (the one the engine shows, e.g. Alvor's Hadvar line 041F16): that row, on that topic
            if (is_array($tp) && isset($tp['info_key']) && (string) (($qlByInfo[(string) $tp['info_key']] ?? [])['topic'] ?? '') !== $tpName) {
                $qlBad['root'][] = $b['id'] . ' ' . $tpName . ' names ' . $tp['info_key'] . ', which is no row of that topic';
            }
        }
    }
    // [pt19c-E fix 1] layer.variants (the INFO a linked topic shows at this beat, or "-" when its conditions hide it): the topic is one
    // the parent row links, and a named INFO is a row of that topic with prompt text - a moved variant fails here, by name
    foreach ((array) ($lay['variants'] ?? []) as $vtk => $vinfo) {
        $qlN['variants'] = ($qlN['variants'] ?? 0) + 1;
        $prow = $qlByInfo[(string) ($lay['parent_info'] ?? '')] ?? null;
        if ($prow === null || !in_array((string) $vtk, array_map('strval', (array) ($prow['links'] ?? [])), true)) {
            $qlBad['variants'][] = $b['id'] . ' ' . $vtk . ' is not linked by parent ' . ($lay['parent_info'] ?? '?');
        } elseif ((string) $vinfo !== '-' && ((string) (($qlByInfo[(string) $vinfo] ?? [])['topic_key'] ?? '') !== (string) $vtk
            || (string) ($qlByInfo[(string) $vinfo]['norm'] ?? '') === '')) {
            $qlBad['variants'][] = $b['id'] . ' ' . $vinfo . ' is no prompt row of topic ' . $vtk;
        }
    }
}
chk('every layer.variants entry (' . (int) ($qlN['variants'] ?? 0) . ') is a topic its parent links, naming an INFO of that topic (or "-": hidden)',
    ($qlBad['variants'] ?? []) === [], implode('; ', array_slice($qlBad['variants'] ?? [], 0, 6)));
chk('every fixture target row (' . $qlN['target'] . ') is in this index, on the topic the fixture names', $qlBad['target'] === [], implode('; ', array_slice($qlBad['target'], 0, 6)));
chk('every closed-layer parent_info (' . $qlN['closed'] . ') pins a live layer line carrying its target (or its links resolve to one)', $qlBad['closed'] === [],
    implode('; ', array_slice($qlBad['closed'], 0, 6)));
chk('every single (' . $qlN['single'] . ') resolves to exactly its one line (the parent row\'s links), a prompt-less one to no layer line', $qlBad['single'] === [],
    implode('; ', array_slice($qlBad['single'], 0, 6)));
chk('every root-list topic (' . $qlN['root'] . ' lists) is a real top-level row (or a DialogueGeneric service row)', $qlBad['root'] === [], implode('; ', array_slice($qlBad['root'], 0, 6)));
// the single-entry chains: each link of the chain is a one-line layer whose line is the next topic
$qlChains = [
    'the Legion oath' => ['MQ102ALegionOath1', 'MQ102ALegionOath2', 'MQ102ALegionOath3', 'MQ102ALegionOath4'],
    'the Stormcloak oath' => ['MQ102BStormcloakOath1', 'MQ102BStormcloakOath2', 'MQ102BStormcloakOath3'],
    'Brynjolf\'s approach' => ['TG00BrynjolfIntroBranch02a', 'TG00BrynjolfIntroBranch03a'],
    'Brynjolf\'s offer' => ['TG00BrynjolfIntroBranch04', 'TG00BrynjolfIntroBranch06'],
];
foreach ($qlChains as $name => $chain) {
    $broken = [];
    for ($c = 0; $c + 1 < count($chain); $c++) {
        $qlLinkOk = false;
        foreach ($byTopic[$chain[$c]] ?? [] as $r) {
            $vis = $qlLinked($r);
            $norms = array_values(array_unique(array_map(static fn($x) => (string) $x['norm'], $vis)));
            if (count($norms) === 1 && (string) array_values($vis)[0]['topic'] === $chain[$c + 1]) { $qlLinkOk = true; break; }
        }
        if (!$qlLinkOk) { $broken[] = $chain[$c] . ' -> ' . $chain[$c + 1]; }
    }
    chk("single-entry chain shape: $name (" . implode(' -> ', $chain) . ') is one line per layer', $broken === [], implode('; ', $broken));
}
// the C2Horn hub (S4.1): parent 08649A, 7 lines on its layer line, 4 of them scripted; the scripted QUESTIONS loop back to the hub
// (their links name >= 60 % of the hub's topics), and exactly one scripted line ends the talk (C5, goodbye) - the hub's ONE commit
$hubP = (string) ((($qlFx['cross'] ?? [])['hub'] ?? [])['parent_info'] ?? 'skyrim.esm:08649A');
$hubRow = $qlByInfo[$hubP] ?? null;
$hubLine = null;
foreach ($qlLayerByParent[$hubP] ?? [] as $li) { $hubLine = $qlLayers[$li]; }
$hubTks = $hubRow ? array_map('strval', (array) $hubRow['links']) : [];
$hubScripted = [];
$hubGoodbye = [];
$hubLoops = [];
foreach ($hubTks as $tk) {
    $rows = array_values(array_filter($qlByTk[$tk] ?? [], static fn($r) => (string) ($r['norm'] ?? '') !== '' && $hubLine && in_array((string) $r['norm'], (array) $hubLine['norms'], true)));
    foreach ($rows as $r) {
        if ((int) ($r['scripted'] ?? 0) !== 1) { continue; }
        $hubScripted[(string) $r['norm']] = (string) $r['topic'];
        if ((int) (($r['flags'] ?? [])['goodbye'] ?? 0) === 1) { $hubGoodbye[(string) $r['norm']] = 1; continue; }
        $back = count(array_intersect(array_map('strval', (array) $r['links']), $hubTks));
        if ($back >= max(2, (int) ceil(0.6 * count($hubTks)))) { $hubLoops[(string) $r['topic']] = 1; }
    }
}
chk('C2Horn hub: the layer line of ' . $hubP . ' carries 7 lines', $hubLine !== null && (int) ($hubLine['n'] ?? 0) === 7, json_encode($hubLine['norms'] ?? null));
chk('C2Horn hub: 4 of its lines are scripted (C3Dragonborn, C4 in hiding, C2Horn, C5)', count($hubScripted) === 4, json_encode($hubScripted));
chk('C2Horn hub: exactly one scripted line ends the talk (C5, goodbye) - the ONE commit under the hub rule', count($hubGoodbye) === 1, json_encode(array_keys($hubGoodbye)));
chk('C2Horn hub: every other scripted line has an INFO whose links lead back to the hub (a hub question, S4.1)',
    count($hubLoops) >= count(array_unique(array_values(array_diff_key($hubScripted, $hubGoodbye)))), json_encode(array_keys($hubLoops)));
// [pt19h-harness] Sven's first-evening root (FE.riverwood.plain, owner page step 3): the four lines the plugins give him in the Sleeping
// Giant (glue/tools/esp_dump.py over the winning records, research/pt19h-harness.md) are top-level rows of this index, on their topics
$svenRoot = ['skyrim.esm:0BB965' => 'DialogueRiverwoodSvenTiberSeptimTopic', 'skyrim.esm:0BCCC6' => 'DialogueRiverwoodSvenStoresTopic',
    'skyrim.esm:0BCC9B' => 'DialogueRiverwoodSvenDragon', 'moretosayriverwood.esp:000902' => 'ACFRiverwoodConversationsWhiterunBranchTopic',
    'moretosayriverwood.esp:00086E' => 'ACFRiverwoodConversationsSvenBranchDrunkTopic'];
$svenBad = [];
foreach ($svenRoot as $ik => $tp) {
    $r = $qlByInfo[$ik] ?? null;
    if ($r === null || (string) $r['topic'] !== $tp || (int) ($r['toplevel'] ?? 0) !== 1) { $svenBad[] = "$ik $tp"; }
}
chk('[pt19h] Sven\'s root lines (the ballads, the stores, the dragon, "How do I get to Whiterun from here?", and the Ralof path\'s Hod line) are top-level rows on their topics',
    $svenBad === [], implode('; ', $svenBad));
// [pt19h-harness] the EXTENDED fixture (tools/test_questline.php --extended): measured on THIS index, and every target row it names is here, on its topic
$qlxFx = json_decode((string) @file_get_contents(__DIR__ . '/fixtures/lrg_questline_extended.json'), true);
if (chk('[pt19h] the extended questline fixture (tools/fixtures/lrg_questline_extended.json) is readable', is_array($qlxFx) && ($qlxFx['_'] ?? '') === 'lrg_questline')) {
    chk('[pt19h] ...and it was measured on this index', (string) ($qlxFx['index_hash'] ?? '') === (string) ($hdr['hash'] ?? ''),
        'fixture ' . (string) ($qlxFx['index_hash'] ?? '?') . ', index ' . (string) ($hdr['hash'] ?? '?'));
    $qlxBad = [];
    $qlxN = 0;
    foreach (array_merge((array) ($qlxFx['beats'] ?? []), array_map(static fn($q) => (array) ($q['beat'] ?? []), (array) ($qlxFx['quarantine'] ?? []))) as $b) {
        $tg = (array) ($b['target'] ?? []);
        if ((string) ($tg['info_key'] ?? '') === '') { continue; }
        $qlxN++;
        $r = $qlByInfo[(string) $tg['info_key']] ?? null;
        if ($r === null) {
            // a row of an INFO whose topic has no EDID (topic "") is not in the by-topic map: find it by its norm
            foreach ($byNorm[(string) ($tg['norm'] ?? '')] ?? [] as $x) { if ((string) ($x['info_key'] ?? '') === (string) $tg['info_key']) { $r = $x; break; } }
        }
        if ($r === null || ((string) ($tg['topic'] ?? '') !== '' && (string) $r['topic'] !== (string) $tg['topic'])) { $qlxBad[] = (string) ($b['id'] ?? '?') . ' ' . $tg['info_key']; }
    }
    chk("[pt19h] every extended target row ($qlxN) is in this index, on the topic the fixture names", $qlxN > 2000 && $qlxBad === [],
        count($qlxBad) . ' not: ' . implode('; ', array_slice($qlxBad, 0, 6)));
}
// [pt19h-harness r2] the two BASELINES tools/test_questline.php compares with (--words / --first-evening --words, --extended): readable, of
// their mode, measured on THIS index (a baseline of another index would compare lists the game no longer shows)
foreach (['words' => 'lrg_questline_words_baseline.json', 'extended' => 'lrg_questline_extended_baseline.json'] as $qlbMode => $qlbFile) {
    $qlb = json_decode((string) @file_get_contents(__DIR__ . '/fixtures/' . $qlbFile), true);
    $qlbOk = is_array($qlb) && ($qlb['_'] ?? '') === 'lrg_questline_baseline' && ($qlb['mode'] ?? '') === $qlbMode && count((array) ($qlb['lines'] ?? [])) > 1000;
    chk("[pt19h] the $qlbMode baseline (tools/fixtures/$qlbFile) is readable, with its lines", $qlbOk);
    if ($qlbOk) {
        chk("[pt19h] ...and it was measured on this index", (string) (($qlb['measured'] ?? [])['index_hash'] ?? '') === (string) ($hdr['hash'] ?? ''),
            'baseline ' . (string) (($qlb['measured'] ?? [])['index_hash'] ?? '?') . ', index ' . (string) ($hdr['hash'] ?? '?'));
    }
}


// ------------------------------------------------------------------ 6. optional: the real database
if (!empty($args['db'])) {
    head('6. --db: load into Postgres and query it back');
    if (!isset($GLOBALS['db'])) {
        $dsn = getenv('LRG_PGDSN') ?: 'host=localhost dbname=dwemer user=dwemer password=dwemer';
        $pg = @pg_connect($dsn);
        if (!$pg) { chk('a Postgres connection', false, 'pg_connect failed for ' . $dsn); }
        else {
            $GLOBALS['db'] = new class ($pg) {
                public function __construct(private $c) { }
                public function escapeLiteral($s) { return pg_escape_literal($this->c, (string) $s); }
                public function execQuery($q) { return @pg_query($this->c, (string) $q) !== false; }
                // [0.5.0] throw on a failed query, as CHIM's own postgresql.class.php:390 does, so the
                // schema fallback of lrgPromptFetch() is really exercised instead of silently returning []
                public function fetchOne($q) { $r = @pg_query($this->c, (string) $q); if ($r === false) { throw new Exception('SQL: ' . pg_last_error($this->c)); } return pg_fetch_assoc($r) ?: []; }
                public function fetchAll($q) { $r = @pg_query($this->c, (string) $q); if ($r === false) { throw new Exception('SQL: ' . pg_last_error($this->c)); } return pg_fetch_all($r) ?: []; }
                public function upsertRowOnConflict($t, $d, $k) { return true; }
                public function insert($t, $d) { return true; }
            };
            // [0.5.0 / E4(a)] migration 007 first: the index now lives in its OWN schema, so a fresh
            // database (or a scratch one, which is what this test should be pointed at) needs it made.
            lrgPromptEnsureSchema();
            chk('the index schema is the configured one', lrgPromptSchemaActive() === lrgPromptSchema(),
                lrgPromptSchemaActive() . ' vs ' . lrgPromptSchema());
            $res = lrgPromptLoad($file);
            chk('the loader reported no error', (string) $res['error'] === '', (string) $res['error']);
            chk('every row was inserted', (int) $res['rows'] === (int) ($hdr['rows'] ?? -1),
                $res['rows'] . ' of ' . (int) ($hdr['rows'] ?? 0));
            chk('every layer was inserted', (int) $res['layers'] === (int) ($hdr['layers'] ?? -1),
                $res['layers'] . ' of ' . (int) ($hdr['layers'] ?? 0));
            $st = lrgPromptIndexStatus();
            chk('the status now reads from the database', (string) $st['source'] === 'db', json_encode($st));
            $hit = lrgPromptLookup([$realLine])[0];
            chk('a lookup against the real table finds "' . substr($realLine, 0, 40) . '"', is_array($hit), json_encode($hit));
            chk('and the row it returns carries the index\'s claims (kind / variant / topic_kind)',
                is_array($hit) && array_key_exists('kind', $hit) && array_key_exists('topic_kind', $hit),
                json_encode(array_keys((array) $hit)));
            $miss = lrgPromptLookup(['A line no plugin in Skyrim has ever contained, truly.'])[0];
            chk('and an invented line is still unindexed', $miss === null, json_encode($miss));

            // ---------------------------------------------------------- [0.5.0 / E5] the named fixture
            head('7. --db: the named NPCs of plan 10.3 / 23, each asserted by name');
            $fx = $root . '/tools/fixtures/lrg_quest_npcs.json';
            if (!is_file($fx)) {
                chk('the fixture tools/fixtures/lrg_quest_npcs.json exists', false, $fx);
            } else {
                $spec = json_decode((string) file_get_contents($fx), true);
                chk('the fixture is valid JSON', is_array($spec) && isset($spec['npcs']), json_last_error_msg());
                $schema = lrgPromptSchemaActive();
                $q = static function (string $where) use ($schema) {
                    return (array) lrgPromptFetch('SELECT topic, quest, kind, variant, plugin, toplevel, journal,'
                        . ' scripted, compound, amulet, crit, cost, shared, txt FROM {s}.lrg_prompt WHERE '
                        . $where . ' LIMIT 4000');
                };
                $db2 = lrgDb();
                foreach ((array) ($spec['npcs'] ?? []) as $row) {
                    $m = (array) ($row['match'] ?? []);
                    $a = (array) ($row['assert'] ?? []);
                    $name = (string) ($row['npc'] ?? '?');
                    $where = '';
                    if (isset($m['topic_in'])) {
                        $in = array_map(static fn($x) => $db2->escapeLiteral((string) $x), (array) $m['topic_in']);
                        $where = 'topic IN (' . implode(',', $in) . ')';
                    } elseif (isset($m['topic_prefix'])) {
                        $where = 'topic LIKE ' . $db2->escapeLiteral(((string) $m['topic_prefix']) . '%');
                    } elseif (isset($m['quest_prefix'])) {
                        $where = 'quest LIKE ' . $db2->escapeLiteral(((string) $m['quest_prefix']) . '%');
                    } elseif (isset($m['plugin'])) {
                        $where = 'plugin = ' . $db2->escapeLiteral((string) $m['plugin']);
                    } elseif (isset($m['text_like'])) {
                        $where = 'txt ILIKE ' . $db2->escapeLiteral('%' . (string) $m['text_like'] . '%');
                    }
                    if ($where === '') { chk($name . ': the fixture row names a match rule', false, json_encode($m)); continue; }
                    $rows = $q($where);
                    $n = count($rows);
                    $why = ' [' . $where . ' -> ' . $n . ' rows]';
                    chk(sprintf('#%s %s: at least %d indexed row(s)%s', (string) ($row['n'] ?? '?'), $name,
                        (int) ($a['min_rows'] ?? 1), $n >= (int) ($a['min_rows'] ?? 1) ? '' : $why),
                        $n >= (int) ($a['min_rows'] ?? 1), $why);
                    if ($n === 0) { continue; }
                    $kinds = array_values(array_unique(array_filter(array_column($rows, 'kind'))));
                    $plugs = array_values(array_unique(array_column($rows, 'plugin')));
                    $vars = array_values(array_unique(array_column($rows, 'variant')));
                    if (isset($a['kinds_any'])) {
                        chk($name . ': one of ' . implode('/', (array) $a['kinds_any']) . ' is present',
                            (bool) array_intersect((array) $a['kinds_any'], $kinds),
                            'kinds found: ' . (implode(',', $kinds) ?: 'none') . $why);
                    }
                    if (isset($a['plugin_any'])) {
                        chk($name . ': the winner is one of ' . implode(' / ', (array) $a['plugin_any']),
                            (bool) array_intersect((array) $a['plugin_any'], $plugs),
                            'plugins found: ' . implode(',', array_slice($plugs, 0, 5)) . $why);
                    }
                    if (isset($a['variants_any'])) {
                        chk($name . ': the success/failure split survived',
                            count(array_intersect((array) $a['variants_any'], $vars)) >= 2,
                            'variants: ' . implode(',', $vars) . $why);
                    }
                    if (isset($a['quest'])) {
                        chk($name . ': the quest is ' . (string) $a['quest'],
                            in_array((string) $a['quest'], array_column($rows, 'quest'), true),
                            'quests: ' . implode(',', array_slice(array_unique(array_column($rows, 'quest')), 0, 5)) . $why);
                    }
                    if (!empty($a['min_toplevel'])) {
                        $tl = count(array_filter($rows, static fn($r) => (int) $r['toplevel'] === 1));
                        chk($name . ': at least ' . (int) $a['min_toplevel'] . ' top-level prompt(s)',
                            $tl >= (int) $a['min_toplevel'], 'toplevel=' . $tl . $why);
                    }
                    foreach ([['any_journal', 'journal', 1], ['any_crit2', 'crit', 2], ['any_scripted', 'scripted', 1],
                        ['any_compound', 'compound', 1]] as [$flag, $col, $want]) {
                        if (empty($a[$flag])) { continue; }
                        $hit = count(array_filter($rows, static fn($r) => (int) $r[$col] >= $want));
                        chk($name . ': at least one row with ' . $col . ' >= ' . $want, $hit > 0,
                            $col . ' hits=' . $hit . $why);
                    }
                    if (!empty($a['any_cost'])) {
                        $hit = count(array_filter($rows, static fn($r) => (int) $r['cost'] !== 0));
                        chk($name . ': at least one PRICED row (a real fare, literal or a <Global=> token)',
                            $hit > 0, 'priced=' . $hit . $why);
                    }
                    if (!empty($a['any_shared_over_1'])) {
                        $hit = count(array_filter($rows, static fn($r) => (int) $r['shared'] > 1));
                        chk($name . ': at least one prompt several topics share (the layer tier has to carry it)',
                            $hit > 0, 'shared>1=' . $hit . $why);
                    }
                    if (isset($a['text_contains_any'])) {
                        $found = false;
                        foreach ($rows as $r) {
                            foreach ((array) $a['text_contains_any'] as $needle) {
                                if (stripos((string) $r['txt'], (string) $needle) !== false) { $found = true; break 2; }
                            }
                        }
                        chk($name . ': an entry containing ' . implode(' / ', (array) $a['text_contains_any'])
                            . ' really exists', $found, $why);
                    }
                }
            }
        }
    }
} else {
    $notes[] = 'pass --db to also load the index into Postgres and query it back';
    $notes[] = 'the named-NPC fixture of plan 10.3 (tools/fixtures/lrg_quest_npcs.json) is only checked with --db';
}

// ------------------------------------------------------------------
echo "\n";
foreach ($notes as $n) { echo "note: $n\n"; }
printf("\n%d passed, %d failed\n", $ok, $fail);
echo $fail === 0 ? "ALL CHECKS PASSED\n" : "RESULT: FAILED\n";
exit($fail === 0 ? 0 : 1);
