<?php
/**
 * LoreRim Glue - THE SERVICE CENSUS, checked against THIS load order (plan 18.6 / E6).
 *
 * The owner asked for bartering, training, inns, carriages and guard / crime "compatible with LoreRim".
 * CHIM already ships all five as shortcut actions - and every one of them is wrong here in a checkable
 * way: HireCarriage knows 19 vanilla destinations and 8 vanilla drivers while this load order runs
 * CFTO.esp with its own topics and places CHIM has never heard of. So the glue does not guess: the
 * census below is built offline from the plugins themselves, and this file is the proof that it found
 * the mods that matter rather than a plausible-looking empty list.
 *
 * Needs the census:
 *     python3 tools/build_prompt_index.py --out server/lorerim_glue/data/prompt_index.ndjson --services
 * then
 *     php tools/test_services.php
 *     php tools/test_services.php --file=<path to service_catalog.json>
 * Exit 0 = every check passed. It writes nothing.
 */

error_reporting(E_ALL);
ini_set('display_errors', 'stderr');

$root = dirname(__DIR__);
require_once $root . '/server/lorerim_glue/lib/lrg_dialogue.php';

$args = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $a, $m)) { $args[$m[1]] = $m[2] ?? true; }
}
$file = is_string($args['file'] ?? null) ? $args['file'] : (LRG_DIR . '/data/service_catalog.json');

$ok = 0;
$fail = 0;
function chk(string $name, $cond, $detail = ''): bool
{
    global $ok, $fail;
    $good = (bool) (is_callable($cond) ? $cond() : $cond);
    if ($good) { $ok++; echo "  [ok] $name\n"; }
    else { $fail++; echo "  [FAIL] $name" . ($detail !== '' ? '  [' . substr((string) (is_callable($detail) ? $detail() : $detail), 0, 260) . ']' : '') . "\n"; }
    return $good;
}
function head(string $s): void { echo "\n$s\n"; }

head('0. the census file');
if (!is_file($file)) {
    echo "  [FAIL] no service catalog at $file\n";
    echo "  Run, inside WSL: python3 tools/build_prompt_index.py --out server/lorerim_glue/data/prompt_index.ndjson --services\n";
    echo "\nRESULT: FAILED (no census)\n";
    exit(1);
}
$cat = json_decode((string) file_get_contents($file), true);
chk('the catalog is valid JSON with the expected shape',
    is_array($cat) && ($cat['_'] ?? '') === 'service_catalog', json_last_error_msg());
if (!is_array($cat)) { exit(1); }
printf("  %s  built %s  %d destinations (%d travel), %d skills, %d price globals, %d crime topics, %d plugins\n",
    basename($file), date('c', (int) ($cat['built_at'] ?? 0)), count((array) ($cat['destinations'] ?? [])),
    count((array) ($cat['destinations_travel'] ?? [])), count((array) ($cat['skills'] ?? [])),
    count((array) ($cat['price_globals'] ?? [])), count((array) ($cat['crime_topics'] ?? [])),
    count((array) ($cat['service_plugins'] ?? [])));

// ------------------------------------------------------------------ 1. the mods that matter were FOUND
head('1. the mods this load order really uses for these services (plan 18.6 - the census is the authority,');
echo "   the table in the plan is the expected answer to check it against)\n";
$plugins = [];
foreach ((array) ($cat['service_plugins'] ?? []) as $p) { $plugins[(string) ($p['plugin'] ?? '')] = (int) ($p['n'] ?? 0); }
chk('CFTO.esp is in the census - the carriage / ferry system actually in use',
    isset($plugins['CFTO.esp']) && $plugins['CFTO.esp'] > 0, implode(', ', array_slice(array_keys($plugins), 0, 12)));
chk('and it is a SUBSTANTIAL contributor, not a stray row', (int) ($plugins['CFTO.esp'] ?? 0) >= 50,
    (string) ($plugins['CFTO.esp'] ?? 0));
$expected = ['Skyrim.esm', 'HearthFires.esm'];
foreach ($expected as $p) {
    chk('the census also found ' . $p, isset($plugins[$p]), implode(', ', array_slice(array_keys($plugins), 0, 12)));
}

// ------------------------------------------------------------------ 2. destinations, and the hard pair
head('2. destinations - including the multi-word one the whole slot matcher exists for (risk R12)');
$dest = array_values((array) ($cat['destinations'] ?? []));
$travel = array_values((array) ($cat['destinations_travel'] ?? []));
chk('the census found destinations at all', count($dest) >= 20, (string) count($dest));
chk('the narrow travel list is a subset of the whole list', !array_diff($travel, $dest),
    implode(', ', array_slice(array_diff($travel, $dest), 0, 6)));
$multi = array_values(array_filter($travel, static fn($d) => strpos((string) $d, ' ') !== false));
chk('at least one MULTI-WORD travel destination exists (CHIM\'s enum cannot name one)',
    count($multi) >= 1, implode(', ', array_slice($multi, 0, 8)));
echo '  multi-word travel destinations: ' . implode(', ', array_slice($multi, 0, 10)) . "\n";
$hasPair = false;
foreach ($travel as $a) {
    foreach ($travel as $b) {
        if ($a === $b) { continue; }
        if (lrgDlgTokenRun(lrgDlgTokens(lrgPromptNorm((string) $b)), lrgDlgTokens(lrgPromptNorm((string) $a)))) {
            $hasPair = true;
            echo '  the dangerous shape really exists here: "' . $a . '" is contained in "' . $b . "\"\n";
            break 2;
        }
    }
}
chk('a "X" / "X Y" pair really exists in this load order - the longest-wins rule is not hypothetical',
    $hasPair, 'none found: the slot matcher is still correct, but d57 is the only proof left');

// ------------------------------------------------------------------ 3. skills, prices, crime
head('3. training skills, price globals, crime topics');
$skills = array_values((array) ($cat['skills'] ?? []));
chk('at least six distinct skill names were discovered from the entries themselves',
    count($skills) >= 6, implode(', ', $skills));
chk('and they are real skill names, not sentence fragments',
    count(array_intersect(['Alchemy', 'Archery', 'Smithing', 'Restoration', 'Illusion', 'Alteration'], $skills)) >= 3,
    implode(', ', $skills));
// [0.5.0 fix pass / S-6] The two shapes that used to be missed, asserted BY NAME. 'One-Handed' proves the
// capture crosses a hyphen (it shipped as "One", the owner's own example), and 'Block' proves the
// "train me to <Skill>" trigger exists (it and Sneak / Pickpocket / Speech / Two-Handed were absent
// from the census entirely). ">= 6 names" could never have caught either.
chk('the hyphenated skill survives the capture - "One-Handed", not "One"',
    in_array('One-Handed', $skills, true) && !in_array('One', $skills, true), implode(', ', $skills));
chk('the "train me to <Skill>" form is covered - Block is in the census',
    in_array('Block', $skills, true), implode(', ', $skills));
$globs = (array) ($cat['price_globals'] ?? []);
chk('every price global has an owning plugin', (static function () use ($globs) {
    foreach ($globs as $g) { if (trim((string) ($g['plugin'] ?? '')) === '') { return false; } }
    return $globs !== [];
})(), json_encode(array_slice($globs, 0, 3)));
chk('the CFTO fare global is among them - the number CHIM replaces with a flat 20',
    (static function () use ($globs) {
        foreach ($globs as $g) { if (stripos((string) ($g['token'] ?? ''), 'KmodFerryCost') !== false) { return true; } }
        return false;
    })(), implode(', ', array_map(static fn($g) => (string) ($g['token'] ?? ''), array_slice($globs, 0, 8))));
$crime = array_values((array) ($cat['crime_topics'] ?? []));
chk('at least one DGCrime* topic was found', (static function () use ($crime) {
    foreach ($crime as $c) { if (stripos((string) $c, 'DGCrime') === 0) { return true; } }
    return false;
})(), implode(', ', array_slice($crime, 0, 8)));
chk('the resist-arrest topic is among them (it is the one entry that must never be voice-selectable)',
    (static function () use ($crime) {
        foreach ($crime as $c) { if (stripos((string) $c, 'ResistArrest') !== false) { return true; } }
        return false;
    })(), implode(', ', array_slice($crime, 0, 10)));

// ------------------------------------------------------------------ 4. the server really uses it
head('4. the server reads the census rather than a hard-coded list');
$GLOBALS['LRG_DLG_TEST_SERVICE_CATALOG'] = $cat;
$lethal = lrgDlgLethalTwats();
chk('crit.lethal_twat is EXTENDED from the census, not typed by hand',
    count($lethal) > count((array) lrgDlgCfg('crit.lethal_twat', [])),
    'seed=' . count((array) lrgDlgCfg('crit.lethal_twat', [])) . ' extended=' . count($lethal));
chk('and the shipped seed survives the extension',
    in_array('DGCrimeResistArrest', $lethal, true), implode(',', array_slice($lethal, 0, 8)));
$resist = ['twat' => '', 'topic' => '', 'norm' => 'you will never take me alive'];
chk('a resist-arrest line is refused on its WORDS alone, even with no index row at all',
    lrgDlgIsResistArrest($resist), json_encode($resist));
foreach ($crime as $c) {
    if (stripos((string) $c, 'ResistArrest') !== false) {
        chk('... and on its TOPIC alone, from the census (' . $c . ')',
            lrgDlgIsResistArrest(['twat' => '', 'topic' => $c, 'norm' => 'i yield']), (string) $c);
        break;
    }
}
unset($GLOBALS['LRG_DLG_TEST_SERVICE_CATALOG']);

// ------------------------------------------------------------------ 5. the slot matcher on REAL names
head('5. the slot matcher over the real destination names of this load order');
$priced = [];
$i = 0;
foreach (array_slice($travel, 0, 6) as $d) {
    $txt = $d . '. (35 gold)';
    $priced[] = ['pos' => $i, 'i' => 100 + $i, 'norm' => lrgPromptNorm($txt), 'text' => $txt,
        'class' => 'pay', 'cost' => 35];
    $i++;
}
if (count($priced) >= 3) {
    $first = (string) $travel[0];
    $r = lrgDlgServiceSlot($priced, 'Take me to ' . $first . '.', 'carriage');
    chk('"Take me to ' . $first . '" resolves to exactly that place',
        is_array($r) && (string) $r['mode'] === 'pick'
        && (string) $r['slot'] === lrgPromptNorm($first), json_encode($r['mode'] ?? null));
    $r2 = lrgDlgServiceSlot($priced, 'How much to ' . $first . '?', 'carriage');
    chk('"How much to ' . $first . '?" is a price question and executes nothing',
        is_array($r2) && (string) $r2['mode'] === 'price', json_encode($r2['mode'] ?? null));
    $r3 = lrgDlgServiceSlot($priced, 'Somewhere warm, I do not mind where.', 'carriage');
    chk('a vague answer names no slot and executes nothing',
        is_array($r3) && (string) $r3['mode'] === 'none', json_encode($r3['mode'] ?? null));
} else {
    chk('enough travel destinations to exercise the matcher', false, (string) count($priced));
}

// ------------------------------------------------------------------ 6. the follower verb table is REAL
/*
 * [0.5.1 / owner addendum 10] The follower verbs are the one part of this round that cannot be checked
 * against the service census - the census is about rooms, rides and trainers. It can be checked against
 * the INDEX, which is this load order itself: every verb in services.follower.verbs must match at least
 * one real topic EditorID here, or the table is a guess about a mod that is not installed. The index is
 * optional (build it with --out ... and no --services), and the section says so rather than failing.
 */
head('6. [0.5.1] the follower verb table really matches THIS load order');
$idxFile = LRG_DIR . '/data/prompt_index.ndjson';
if (!is_file($idxFile)) {
    echo "  [note] no prompt_index.ndjson - the follower topics could not be checked against the load order\n";
} else {
    $topics = [];
    $fh = fopen($idxFile, 'rb');
    while ($fh && ($line = fgets($fh)) !== false) {
        if (strpos($line, 'Follower') === false && strpos($line, 'SetHome') === false) { continue; }
        if (preg_match('/"topic"\s*:\s*"([^"]+)"/', $line, $m)) { $topics[$m[1]] = 1; }
    }
    if ($fh) { fclose($fh); }
    $topics = array_keys($topics);
    chk('the index carries follower topics at all', count($topics) >= 5, (string) count($topics));
    foreach ((array) lrgDlgCfg('services.follower.verbs', []) as $verb => $spec) {
        $hit = [];
        foreach ($topics as $tp) {
            if (lrgAnyGlob((array) ($spec['topics'] ?? []), [$tp])
                && !lrgAnyGlob((array) lrgDlgCfg('services.follower.never_topics', []), [$tp])) {
                $hit[] = $tp;
            }
        }
        chk('the "' . $verb . '" verb matches a real topic in this load order: ' . implode(', ', array_slice($hit, 0, 3)),
            $hit !== [], implode(',', (array) ($spec['topics'] ?? [])));
    }
    // and the rail really has something to protect: the blocking / animal topics must be excluded
    $blocked = array_values(array_filter($topics,
        static fn($tp) => lrgAnyGlob((array) lrgDlgCfg('services.follower.never_topics', []), [$tp])));
    chk('at least one never-selectable follower topic really exists here: ' . implode(', ', array_slice($blocked, 0, 3)),
        $blocked !== [], 'none found - the rail is still correct but nothing in this list exercises it');
    foreach ($blocked as $tp) {
        if (lrgDlgFollowerVerbOf(['topic' => $tp, 'norm' => 'wait here', 'text' => 'Wait here.', 'class' => 'plain']) !== '') {
            chk('a never-selectable topic must never resolve to a verb (' . $tp . ')', false, $tp);
            break;
        }
    }
    chk('... and none of them resolves to a follower verb', true);
}

// ------------------------------------------------------------------ 7. [pt19-purchase] the market lane against the census
/*
 * An order of food or drink is recognised by lib/lrg_market.php from the vendor's REAL stock on the snapshot; the
 * census cannot check the stock (it is read live from the merchant chest), but it CAN check that the lane's words
 * never collide with a real destination of this load order - "take me to <place>" must stay a carriage request,
 * never an order - and that 'something to eat' / 'something to drink' really left the inn kind.
 */
head('7. [pt19-purchase] the market lane: no destination is a class word, a ride is never an order, the inn kind lost the drink');
$mktStock = [];
foreach (['00034C5E:Ale:19:8:5', '000508CA:Honningbrew Mead:39:12:10', '00065C97:Bread:23:24:6'] as $row) {
    $p = explode(':', $row);
    $mktStock[] = ['id' => $p[0], 'name' => $p[1], 'norm' => lrgMktNorm($p[1]), 'tokens' => lrgMktTokens(lrgMktNorm($p[1])), 'price' => (int) $p[2], 'count' => (int) $p[3], 'value' => (int) $p[4], 'drink' => (int) ($p[1] !== 'Bread')];
}
$classWords = [];
foreach ((array) lrgMktCfg('class', []) as $cls => $words) { foreach ((array) $words as $w) { $classWords[] = lrgPromptNorm((string) $w); } }
$collide = [];
foreach ($travel as $d) { if (in_array(lrgPromptNorm((string) $d), $classWords, true)) { $collide[] = (string) $d; } }
chk('no travel destination of this load order is a market class word (beer, mead, bread, ...)', $collide === [], implode(', ', $collide));
$ridesAsOrders = [];
foreach (array_slice($travel, 0, 12) as $d) {
    $r = lrgMktRecognise('take me to ' . $d, $mktStock);
    if ((string) $r['kind'] !== '') { $ridesAsOrders[] = (string) $d . '=' . $r['kind']; }
}
chk('"take me to <every real destination>" is never an order or a price question of the market lane', $ridesAsOrders === [], implode(', ', $ridesAsOrders));
chk("'something to eat' / 'something to drink' are no longer inn phrases", !in_array('something to eat', (array) lrgDlgCfg('services.kinds.inn.phrases', []), true)
    && !in_array('something to drink', (array) lrgDlgCfg('services.kinds.inn.phrases', []), true) && lrgDlgServiceKind('something to drink') === '');
chk("'a room for the night' is still the inn kind and no order", lrgDlgServiceKind('a room for the night') === 'inn' && lrgMktRecognise('a room for the night', $mktStock)['kind'] === '');
chk("'what have you got for sale' is still barter and no order", lrgDlgServiceKind('what have you got for sale') === 'barter' && lrgMktRecognise('what have you got for sale', $mktStock)['kind'] === '');

// ------------------------------------------------------------------ 8. [pt19 v1.0] the service KIND phrases (S2.1 clause 2, S5)
/*
 * [pt19 v1.0 / spec 2.2 Lane B, S2.1 clause 2, capability map U1 / U12, model F21] A kind phrase opens the visible menu BEFORE the
 * model runs, so every one must be a request for that service and nothing else: each new phrase maps to exactly one kind,
 * every service sentence the spec, the owner page and the fixture name returns its kind, the look-alikes return '', and the
 * shipped config JSON's lists are COMPLETE (lists replace, maps merge - a JSON list that dropped a shipped phrase deletes it).
 */
head('8. [pt19 v1.0] the kind phrases: one kind each, the owner\'s sentences, the look-alikes, and the config JSON lists complete');
$newPhrases = ['barter' => ['what have you got', 'what do you have', 'show me your wares', 'anything for sale'],
    'inn' => ['need a bed', 'need a room', 'like a room', 'want a room', 'get a room', 'a bed for', 'a room please', 'a room for me'],
    // [pt19c-B fix 1 / lang review P4, use review P4] U12 as REQUEST frames (the bare 'training in' / 'train in' / 'train me' are gone)
    'train' => ['like training in', 'need training in', 'some training in', 'want training in', 'like to train in', 'want to train in',
        'need to train in', 'could you train me', 'will you train me', 'would you train me', 'you to train me', 'please train me']];
// [pt19c-B fix 1 / game review] the natural asks
$newPhrases['inn'] = array_merge($newPhrases['inn'], ['any rooms', 'rooms available', 'a room available']);
$newPhrases['barter'] = array_merge($newPhrases['barter'], ['any wares', 'what do you have on sale', 'what have you got on offer']);
foreach ($newPhrases as $kind => $list) {
    foreach ($list as $ph) {
        $kinds = [];
        foreach ((array) lrgDlgCfg('services.kinds', []) as $k => $spec) {
            if (lrgDlgPhraseHit($ph, (array) ($spec['phrases'] ?? [])) !== '') { $kinds[] = (string) $k; }
        }
        chk("\"$ph\" is a $kind phrase and maps to exactly one kind", $kinds === [$kind] && lrgDlgServiceKind($ph) === $kind, implode(',', $kinds));
    }
}
$kindSays = [
    // S2.1 clause 2, verified through the real lrgDlgPhraseHit / lrgDlgHitFollowedBy loop [M]
    'what have you got?' => 'barter', 'What do you have?' => 'barter', 'anything for sale' => 'barter', 'show me your wares' => 'barter',
    "I'd like a room" => 'inn', 'I need a bed' => 'inn', 'can I get a room' => 'inn', 'hey could i get a room?' => 'inn',
    // capability map U12: a trainer's line in his words
    "I'd like training in Alchemy" => 'train', 'I need training in Smithing' => 'train',
    // the look-alikes: no kind (the model answers; nothing opens on them)
    'what have you got against the Stormcloaks' => '', 'what have you got there' => '', 'what have you got planned' => '',
    'what have you got to eat' => '', 'what do you have in mind' => '', 'what do you have to say for yourself' => '',
    'what do you have for me' => '', 'can I buy you a drink' => '', 'I need a drink' => '', 'uh some beer' => '',
    // capability map U1 / U4: rumour questions are not a room any more
    "what's the news" => '', 'any rumors about the dragons' => '', 'any rumours' => '',
    // [pt19c-B fix 1 / game review] the natural inn and barter asks, cold
    'Do you have any rooms available?' => 'inn', 'Got any rooms?' => 'inn', 'Is there a room available?' => 'inn', 'Got any wares?' => 'barter',
    'What do you have on offer?' => 'barter', 'what do you have for sale' => 'barter',
    // [lang review P3] information questions at a quest giver are no request for her goods (the kind pick clicked the trade line)
    'what have you got on bleak falls barrow' => '', 'what have you got on the dragon stone' => '', 'what do you have to report' => '',
    'what do you have for us' => '', 'what have you got going on here' => '', 'what do you have on your mind' => '', 'what do you have on the thieves' => '',
    'what do you have in store for me' => '', 'what do you have to tell me' => '', 'what do you have in common with them' => '',
    'what do you have to show for it' => '',
    // [lang review P4, use review P4] questions about HER, his own statements and C00's own line are no training request; the trainers' lines are
    'where did you train in swordplay' => '', 'did you train in the legion' => '', 'who did you train in the past' => '',
    'I have been training in two handed for years' => '', 'I want to learn more about the Companions' => '', "So you're supposed to train me?" => '',
    'teach me about the Greybeards' => '', "I'd like to train in two-handed weapons." => 'train', 'I need some training in archery' => 'train',
    'I want to train in destruction' => 'train', 'can you train me' => 'train', 'train me in one-handed' => 'train', 'teach me archery' => 'train',
    'will you train me?' => 'train',
    // [lang review P5] a bed or a room for someone else's need, or a figure of speech, is no rent
    'we need a bed for the wounded' => '', 'go get a room you two' => '', 'I need a room to think' => '',
];
// the owner page (Lane F) and the questline fixture (Lane E): every sentence they mark `mode kind` / `via kind` with its kind
$ownerPage = $root . '/OWNER_MENULESS_V1.md';
if (is_file($ownerPage) && preg_match_all('/"([^"]{3,80})"[^\n]{0,40}\bmode kind\b[^\n]{0,20}\((inn|barter|carriage|ferry|train|follower)\)/', (string) file_get_contents($ownerPage), $mm, PREG_SET_ORDER)) {
    foreach ($mm as $m) { $kindSays[$m[1]] = $m[2]; }
}
$fx = json_decode((string) @file_get_contents($root . '/tools/fixtures/lrg_questline.json'), true);
$fxKinds = 0;
foreach ((array) ($fx['beats'] ?? []) as $beat) {
    foreach ((array) ($beat['say'] ?? []) as $s) {
        if ((string) ($s['via'] ?? '') === 'kind' && (string) ($s['kind'] ?? '') !== '') { $kindSays[(string) $s['t']] = (string) $s['kind']; $fxKinds++; }
    }
}
echo '  [note] ' . (is_file($ownerPage) ? 'owner page read' : 'no owner page yet (Lane F)') . '; ' . $fxKinds . " fixture sentence(s) marked via=kind\n";
$wrongK = [];
foreach ($kindSays as $say => $want) { $got = lrgDlgServiceKind((string) $say); if ($got !== $want) { $wrongK[] = "\"$say\" -> '$got' (want '$want')"; } }
chk(count($kindSays) . ' service sentences return their kind through lrgDlgServiceKind (and the look-alikes return nothing)', $wrongK === [], implode('; ', $wrongK));
// [pt19c-B fix 1 / lang review P5] the turn's own svc.kind is the NEGATION-guarded one (lrgDlgPrepareTurn): no Rent_Room directive for a no
chk("a negated request is no kind: \"I don't need a room\" and the owner's \"can't get a room around here\" (lrgDlgServiceKindSaid)",
    lrgDlgServiceKindSaid("I don't need a room") === '' && lrgDlgServiceKindSaid("can't get a room around here") === '' && lrgDlgServiceKindSaid('I need a room') === 'inn');
// [model F21] the shipped JSON's lists are COMPLETE: a superset of the code default, key by key
$jsonAll = (array) json_decode((string) @file_get_contents(LRG_DIR . '/config/lrg_config.default.json'), true);
$jsonDlg = (array) ($jsonAll['dialogue'] ?? []);
$code = lrgDlgDefaults();
$pathsF21 = ['confirm.assent_words', 'confirm.single_entry_extra', 'auto_advance.continuer_words', 'services.kinds.inn.phrases',
    'services.kinds.inn.not_after', 'services.kinds.barter.phrases', 'services.kinds.barter.not_after', 'services.kinds.train.phrases',
    'services.kinds.train.not_after', 'truth.classes', 'hide_reward', 'checks.stakes.reward.words', 'checks.stakes.reward.weak',
    'checks.stakes.reward.with_money', 'checks.stakes.reward.money', 'checks.stakes.reward.not_with', 'checks.stakes.reward.tails',
    'checks.stakes.reward.sum_frames', 'checks.stakes.reward.first_person', 'checks.stakes.reward.ends', 'checks.stakes.reward.in_question',
    'checks.stakes.reward.leads', 'scenes.ambient'];
$get = static function (array $a, string $p) { foreach (explode('.', $p) as $k) { if (!is_array($a) || !array_key_exists($k, $a)) { return null; } $a = $a[$k]; } return $a; };
foreach ($pathsF21 as $p) {
    $j = $get($jsonDlg, $p);
    $c = $get($code, $p);
    chk("[F21] dialogue.$p in the config JSON is the COMPLETE list (a superset of the code default)", is_array($j) && is_array($c) && array_diff($c, $j) === [],
        is_array($j) && is_array($c) ? 'missing: ' . implode(', ', array_diff($c, $j)) : 'absent');
}
chk('the spec S2.1 / S9 values: assent_words carries ok, okay, sure thing; single_entry_extra is go on / carry on; utter_window 30; precision 0.75',
    !array_diff(['ok', 'okay', 'sure thing'], (array) lrgDlgCfg('confirm.assent_words')) && lrgDlgCfg('confirm.single_entry_extra') === ['go on', 'carry on']
    && (int) lrgDlgCfg('confirm.utter_window') === 30 && abs((float) lrgDlgCfg('confirm.single_entry_precision') - 0.75) < 1e-9);
chk('open.toplevel_marker / qrows_marker on, qrows_score 0.55, qrows_cap 300; reorder_json_scope business; session.drive_scene true (the kill switch)',
    lrgDlgCfg('open.toplevel_marker') === true && lrgDlgCfg('open.qrows_marker') === true && abs((float) lrgDlgCfg('open.qrows_score') - 0.55) < 1e-9
    && (int) lrgDlgCfg('open.qrows_cap') === 300 && lrgDlgCfg('reorder_json_scope') === 'business' && lrgDlgCfg('session.drive_scene') === true);
$retired = ['confirm.typed_skips', 'auto_advance.grace_seconds', 'assist', 'calib.auto', 'quests.initiative', 'match.cont_depth', 'session.silence_seconds',
    'session.lost_seconds', 'script_proxy_watch', 'quest_colour', 'entries.more_words', 'continuer_words', 'session.utter_max_age'];
$left = [];
foreach ($retired as $p) { if ($get($jsonDlg, $p) !== null || lrgDlgCfg($p) !== null) { $left[] = $p; } }
chk('[S9 / section 4] no retired key is left in the code defaults or the config JSON', $left === [], implode(', ', $left));
$ovr = json_decode((string) @file_get_contents(LRG_DIR . '/config/lrg_dialogue_overrides.default.json'), true);
$ovTopics = [];
foreach ((array) ($ovr['entries'] ?? []) as $r) { $ovTopics[(string) (($r['match'] ?? [])['topic'] ?? '')] = $r; }
chk('the shipped overrides file: both Oath4 rows commit: true; the AP start and the two skip lines never_auto (U11)',
    !empty($ovTopics['MQ102ALegionOath4']['commit']) && !empty($ovTopics['MQ102BStormcloakOath4']['commit'])
    && !empty($ovTopics['APStartIntroDiaTopic']['never_auto']) && !empty($ovTopics['MessengerAlduinSkipImp']['never_auto'])
    && !empty($ovTopics['MessengerMQSkipImp']['never_auto']), json_encode(array_keys($ovTopics)));

// ------------------------------------------------------------------ 9. [pt19h-reach] REACH
/*
 * [pt19h-reach / research/pt19h-reach.md] His own words reach the line he plainly meant - and nothing else. The measurer's own
 * examples (research/pt19h-measure.md G2, G4, G6, U1 and the Nazir turn-ins of brotherhood.md gap 1) as must-resolve rows,
 * and the near-misses each fix could have widened into as must-not rows. Pure calls first (lrgDlgWordsCarry, lrgDlgSttFold,
 * lrgDlgKindPick / lrgDlgReachPick, lrgDlgFollowerArbitrate, lrgDlgKindMayOpen), then the want=1 fast path end to end
 * (lrgDlgAnswerWant over an in-memory index, the emit queue read back).
 */
head('9. [pt19h-reach] reach: STT repair (G2), the work ask (G4), follower verbs on a quest list (G6), Nazir\'s turn-ins, U1');
$rE = static function (string $text, string $class = 'plain', int $pos = 0, array $o = []): array {
    return $o + ['pos' => $pos, 'i' => 100 + $pos, 'text' => $text, 'norm' => lrgPromptNorm($text), 'class' => $class,
        'commit' => $class === 'commit', 'scripted' => 0, 'kind' => '', 'cost' => 0, 'crit' => 0, 'indexed' => 1, 'goodbye' => 0,
        'afford' => 1, 'topic' => ''];
};
// ---- G2: the precision rail no longer loses a pick to STT damage; the frame rule still holds
$g2Carry = [['how can i health', 'How can I help?'], ['partner knacks is dead', 'Paarthurnax is dead.'],
    ["i'm the arch image it's two thousand coins", "I'm the Arch-Mage. It's 2,000 coins."], ['why were you weighting for me', 'Why were you waiting for me?'],
    ['i was at hell again', 'I was at Helgen.'], ['i need to talk to ewe', 'I need to talk to you.'], ['need to talk to you', 'I need to talk to you.'],
    ['we need to torque', 'We need to talk.'], ['so where are we had it', 'So where are we headed?'], ['lets go', "Let's go."],
    ['who are ewe', 'Who are you?'], ['what is this a bout', 'What is this about?'], ['i talk to the great beards', 'I talked to the Greybeards.']];
$bad = [];
foreach ($g2Carry as [$w, $l]) { if (!lrgDlgWordsCarry($w, $rE($l))) { $bad[] = "\"$w\" -/-> \"$l\""; } }
chk('[G2] ' . count($g2Carry) . ' STT-damaged sentences (the measurer\'s own) carry their line past the precision rail', $bad === [], implode('; ', $bad));
$g2Not = [['tell me about the war', 'Tell me about Whiterun.'], ['i need to talk to farengar', 'I need to talk to you.'],
    ['do you have any mead', 'Heard any rumors lately?'], ['do you have any rooms', 'Heard any rumors lately?'],
    ['what do you want', 'So, what do you want me to do?'],
    ['how do I get up there', 'How do I get to the top of the mountain?'], ['I heard a rumor about you', 'Heard any rumors lately?'],
    ['I will not pretend; those deeds were wrong.', 'No way. It was wrong to do those things.'], ['where is the school', 'So, where is the Scroll?'],
    ['wait, what do we do now?', 'What do we do now?'], ['put me to work', 'You take your work very seriously.'],
    ['put me to work', "So you're treated badly because of your work?"]];
$bad = [];
foreach ($g2Not as [$w, $l]) { if (lrgDlgWordsCarry($w, $rE($l))) { $bad[] = "\"$w\" -> \"$l\""; } }
chk('[G2/G4] ' . count($g2Not) . ' near-misses still do NOT carry the line (frame words, another word, a word FORM, an echo, the work noun alone)', $bad === [], implode('; ', $bad));
$fold = [["i no that's why i need to find him", "I know. That's why I need to find him.", "i know that's why"],
    ['so we no his name how does that help', 'So we know his name. How does that help me?', 'we know his'],
    ["i have know doubts i no what i'm doing", "I have no doubts, I know what I'm doing.", "have no doubts i know what"],
    ['is ran needs your help', 'Isran needs your help.', 'isran needs'], ["don't worry i'll re turn the key", "Don't worry. I'll return the Key.", 'return the key'],
    ['hoo are you', 'Who are you?', 'who are you'], ["i'm knot shore about this", "I'm not sure about this.", "not sure"]];
$bad = [];
foreach ($fold as [$w, $l, $want]) { $got = lrgDlgSttFold($w, [$rE($l)]); if (stripos($got, $want) === false) { $bad[] = "\"$w\" -> \"$got\""; } }
chk('[G2] the aligned homophones and the 2-letter joins repair against the line\'s own words', $bad === [], implode('; ', $bad));
$keep = [['i no', 'I know.'], ['no, I know', 'I know.'], ['we no longer trust him', 'We know him.'], ['no body is here', 'Nobody is here.'],
    ['I... no.', "I know."]];
$bad = [];
foreach ($keep as [$w, $l]) { $got = lrgDlgSttFold($w, [$rE($l)]); if (lrgPromptNorm($got) !== lrgPromptNorm($w)) { $bad[] = "\"$w\" -> \"$got\""; } }
chk('[G2] ...and never turns a refusal into assent: "i no" at his end, a leading "no", "no longer", "no body" stay as said', $bad === [], implode('; ', $bad));
// ---- G4: the work ask, by kind
$yes = ['got any work?', 'got any work for me?', 'do you have any work for me?', 'anything need doing?', 'put me to work', 'can I help?',
    'got a job for me?', 'is there any work', 'is there anything I can do?'];
$no = ["I don't need any work", 'how much does the work pay', 'not now, got any work later', 'I got a job for you', 'can I help myself to some bread',
    'does that work for me', 'have you done any work on my sword', 'can you help me?', 'I need help', "I've got work to do", 'do you need help', 'do you need a hand?'];
$bad = [];
foreach ($yes as $s) { if (lrgDlgReachWorkAsk($s) === '') { $bad[] = "\"$s\" missed"; } }
foreach ($no as $s) { if (lrgDlgReachWorkAsk($s) !== '') { $bad[] = "\"$s\" read as a work ask"; } }
chk('[G4] ' . count($yes) . ' work asks are read, ' . count($no) . ' look-alikes (negated, a price, a deferral, his own help, his own work) are not', $bad === [], implode('; ', $bad));
$rh01 = [$rE('What can I do to help?', 'plain', 0), $rE('I need training in Heavy Armor.', 'service', 1), $rE('What have you got for sale?', 'service', 2),
    $rE("I'd like to buy an armored troll. (<Global=DLC1TrollArmoredCost> gold)", 'pay', 3, ['cost' => -1])];
$kp = static fn(array $l, string $s, string $k = 'root') => lrgDlgKindPick($l, $s, $k);
$pickText = static fn(array $r): string => is_array($r['entry'] ?? null) ? (string) $r['entry']['text'] : '-';
chk('[G4 / measurer] "got any work for me?" at Gunmar (DLC1RH01) -> "What can I do to help?" (no barter misclick)',
    $pickText($kp($rh01, 'got any work for me?')) === 'What can I do to help?', json_encode($kp($rh01, 'got any work for me?')));
chk('[G4] "anything need doing?", "can I help?", "put me to work", "got a job for me?" -> the same radiant start; "what have you got for sale?" is still barter',
    $pickText($kp($rh01, 'anything need doing?')) === 'What can I do to help?' && $pickText($kp($rh01, 'can I help?')) === 'What can I do to help?'
    && $pickText($kp($rh01, 'put me to work')) === 'What can I do to help?' && $pickText($kp($rh01, 'got a job for me?')) === 'What can I do to help?'
    && $pickText($kp(array_slice($rh01, 0, 3), 'what have you got for sale?')) === 'What have you got for sale?');
$cr07 = [$rE("I'm looking for work. Do you have anyone you need hunted down?", 'plain', 0, ['scripted' => 1]), $rE('What does it mean to be a Companion?', 'plain', 1),
    $rE('Why did you join the Companions?', 'plain', 2)];
chk('[G4 / measurer] "anything need doing?" at a Companion (CR07) -> the radiant start "I\'m looking for work. ..."',
    str_starts_with($pickText($kp($cr07, 'anything need doing?')), "I'm looking for work."));
$rv03 = [$rE('I heard you might need help.', 'plain', 0), $rE('How do you serve Lord Harkon?', 'plain', 1), $rE('What can I do to help?', 'plain', 2)];
$r = $kp($rv03, 'got any work?');
chk('[G4] two lines offer work (DLC1RV03) -> nothing picked, she asks which', $r['entry'] === null && str_contains((string) $r['why'], 'asks which'), json_encode($r));
$delvin = [$rE("I heard you're offering extra work.", 'plain', 0), $rE('Have something special for me, Delvin?', 'plain', 1)];
chk('[G4 / G11] his question that says the work line back ("I heard you\'re offering extra work?", "is it true that ...") is never picked by kind',
    $kp($delvin, "I heard you're offering extra work?")['entry'] === null && $kp($delvin, "is it true that i heard you're offering extra work")['entry'] === null
    && $pickText($kp($delvin, 'any jobs?')) === "I heard you're offering extra work.");
$jz = [$rE('What did you need help with?', 'plain', 0, ['scripted' => 1]), $rE('You seem very sure of yourself.', 'plain', 1),
    $rE('Not everything is a competition, you know.', 'plain', 2)];
chk('[G4] the coverage team\'s near-miss "do you need help" (a question about HER need) is never a work ask for J\'zargo\'s "What did you need help with?"; '
    . 'his offer "can I help?" is', $kp($jz, 'do you need help')['entry'] === null && $pickText($kp($jz, 'can I help?')) === 'What did you need help with?');
$urag = [$rE("Are there any special books you're looking for?", 'plain', 0), $rE('This is quite an impressive library.', 'plain', 1),
    $rE('You take your work very seriously.', 'plain', 2)];
chk('[G4] no work line on the list -> the kind pick stands aside (nothing), and "You take your work very seriously." is not his line',
    $kp($urag, 'put me to work')['entry'] === null && !lrgDlgWordsCarry('put me to work', $urag[2]));
// ---- G4 before the model: the pre-LLM open's root clause opens her list for a work ask when her cached root has the ONE work line
$bm = static function (string $npc, array $rootEntries, string $say): array {
    $GLOBALS['LRG_DLG_STATE'] = [];
    $GLOBALS['LRG_DLG_TEST_STORE'] = [$npc => ['root' => ['at' => lrgNow() - 5, 'entries' => $rootEntries]]];
    $GLOBALS['LRG_TEST_NPCSTATE'] = [$npc => ['npc' => $npc, 'mate' => '0', '_age' => 0]];
    $hit = [];
    $m = lrgDlgBusinessMarker(['npc' => $npc, 'cid' => 'bm', 'q' => [], 'snap' => ['npc' => $npc, 'mate' => '0', '_age' => 0]], $say, true, $hit);
    return [$m, (string) ((array) ($hit['entry'] ?? []))['text'] ?? ''];
};
[$m1, $l1] = $bm('Gunmar', array_slice($rh01, 0, 3), 'got any work for me?');
[$m2, $l2] = $bm('Urag gro-Shub', $urag, 'put me to work');
[$m3, $l3] = $bm('Gunmar', array_slice($rh01, 0, 3), "I don't need any work");
chk('[G4] the pre-LLM open (narrow marker, clause 3): "got any work for me?" opens Gunmar\'s cached root for "What can I do to help?"; "put me to work" '
    . 'opens nothing on a root with no work line; "I don\'t need any work" opens nothing', $m1 === 'root' && $l1 === 'What can I do to help?' && $m2 === ''
    && $m3 === '', json_encode([$m1, $l1, $m2, $l2, $m3]));
unset($GLOBALS['LRG_DLG_TEST_STORE'], $GLOBALS['LRG_TEST_NPCSTATE']);
$GLOBALS['LRG_DLG_STATE'] = [];
// ---- Nazir's contract turn-ins: a report that names its target
$naz = [$rE('Narfi is dead.', 'plain', 0, ['scripted' => 1]), $rE('Tell me about Narfi.', 'plain', 1), $rE('Tell me about Ennodius.', 'plain', 2),
    $rE('Tell me about Beitild.', 'plain', 3)];
$naz2 = [$rE('Hern is dead.', 'plain', 0), $rE('Lurbuk is dead.', 'plain', 1), $rE('Tell me about Hern.', 'plain', 2), $rE('Tell me about Lurbuk.', 'plain', 3)];
$mar = [$rE("Ma'randru-jo is dead.", 'plain', 0), $rE('Tell me about Deekus.', 'plain', 1), $rE("Tell me about Ma'randru-jo.", 'plain', 2)];
$bad = [];
foreach (['Narfi has been dealt with', 'the Narfi contract is done', 'I finished the job on Narfi', "Narfi won't be a problem anymore",
    'Narfi is taken care of', 'narfy has been dealt with', 'the narfy contract is done'] as $s) {
    if ($pickText($kp($naz, $s)) !== 'Narfi is dead.') { $bad[] = $s; }
}
foreach (['her n has been dealt with' => 'Hern is dead.', 'lure buck has been dealt with' => 'Lurbuk is dead.'] as $s => $w) { if ($pickText($kp($naz2, $s)) !== $w) { $bad[] = $s; } }
foreach (['mar andrew joe has been dealt with', "the Ma'randru-jo contract is done"] as $s) { if ($pickText($kp($mar, $s)) !== "Ma'randru-jo is dead.") { $bad[] = $s; } }
chk('[Nazir] 11 contract reports (paraphrase and STT) pick the turn-in, never "Tell me about <name>." (margin < 0.15 before)', $bad === [], implode('; ', $bad));
$bad = [];
foreach (['is Narfi dead?', 'Narfi will be dealt with', "Narfi isn't dead yet", 'once Narfi is dead I want my pay', 'not now, Narfi is dead later',
    'I need to deal with Narfi'] as $s) { if ($kp($naz, $s)['entry'] !== null) { $bad[] = $s; } }
foreach (["I'm done here", 'I dealt with her'] as $s) { if ($kp($naz2, $s)['entry'] !== null) { $bad[] = $s; } }
$mal = [$rE('Maluril is dead.', 'plain', 0), $rE('Tell me about Maluril.', 'plain', 1), $rE('Tell me about Helvard.', 'plain', 2)];
foreach (['my mother is dead', 'the merchant is dead'] as $s) { if ($kp($mal, $s)['entry'] !== null) { $bad[] = $s; } }
if ($pickText($kp($mal, 'mal oral has been dealt with')) !== 'Maluril is dead.') { $bad[] = 'mal oral (STT) missed'; }
$two = $kp($naz2, 'Hern and Lurbuk are dead');
chk('[Nazir] never a question, a negation, a plan or a condition, never "here" / "her" for Hern; two named -> she asks which',
    $bad === [] && $two['entry'] === null && str_contains((string) $two['why'], 'asks which'), implode('; ', $bad) . ' ' . json_encode($two));
// ---- G6: a follower verb on a list with no follower entry
$GLOBALS['LRG_DLG_STATE'] = [];
$GLOBALS['LRG_DLG_TEST_STORE'] = ['Hadvar' => ['utter' => ['text' => "ready, let's go", 'at' => lrgNow() - 3, 'cid' => 'r6']]];
$GLOBALS['LRG_TEST_NPCSTATE'] = ['Hadvar' => ['npc' => 'Hadvar', 'mate' => '0', '_age' => 0]];
$hadvar = [$rE("Ready. Let's go.", 'plain', 0), $rE("I have a better plan. You wait here, and I'll take care of it.", 'plain', 1)];
$fo = lrgDlgFollowerArbitrate(['npc' => 'Hadvar'], $hadvar, '', 'r6');
$GLOBALS['LRG_DLG_STATE'] = [];
$GLOBALS['LRG_TEST_NPCSTATE'] = ['Hadvar' => ['npc' => 'Hadvar', 'mate' => '1', '_age' => 0]];
$foMate = lrgDlgFollowerArbitrate(['npc' => 'Hadvar'], $hadvar, '', 'r6');
$GLOBALS['LRG_DLG_STATE'] = [];
$GLOBALS['LRG_TEST_NPCSTATE'] = ['Hadvar' => ['npc' => 'Hadvar', 'mate' => '0', '_age' => 0]];
$withFol = [$rE('Follow me.', 'plain', 0, ['topic' => 'DialogueFollowerFollowTopic']), $rE("Ready. Let's go.", 'plain', 1)];
$GLOBALS['LRG_DLG_TEST_STORE'] = ['Hadvar' => ['utter' => ['text' => 'follow me', 'at' => lrgNow() - 3, 'cid' => 'r6']]];
$foReal = lrgDlgFollowerArbitrate(['npc' => 'Hadvar'], $withFol, '', 'r6');
chk('[G6] no follower entry and nobody\'s companion -> no follower order to arbitrate (null: ordinary matching decides); a companion keeps the old '
    . 'answer (nothing); a real follower entry still resolves exactly', $fo === null && is_array($foMate) && $foMate['entry'] === null
    && is_array($foReal) && (string) (($foReal['entry'] ?? [])['text'] ?? '') === 'Follow me.', json_encode([$fo, $foMate, $foReal]));
// ---- the want=1 fast path end to end, on an in-memory index
$rRow = static function (string $txt, array $o = []): array {
    return ['txt' => $txt, 'norm' => lrgPromptNorm($txt), 'pattern' => '', 'topic_key' => 'rch:' . substr(md5($txt), 0, 6),
        'info_key' => (string) ($o['ik'] ?? ('rchi:' . substr(md5($txt), 0, 8))), 'topic' => (string) ($o['topic'] ?? 'ReachTopic'),
        'quest' => (string) ($o['quest'] ?? 'ReachQuest'), 'journal' => 0, 'toplevel' => 1, 'kind' => (string) ($o['kind'] ?? ''), 'variant' => 'na',
        'flags' => ['goodbye' => 0, 'sayonce' => 0, 'walkaway' => 0, 'invis' => 0, 'random' => 0, 'favor' => 0, 'placeholder' => 0],
        'scripted' => (int) ($o['scripted'] ?? 0), 'compound' => 0, 'twat' => '', 'crit' => 0, 'cost' => (int) ($o['cost'] ?? 0), 'links' => [],
        'resp' => '', 'shared' => 1];
};
$rWant = static function (string $npc, array $texts, string $say, array $rowOpts = [], array $snap = []) use ($rRow): array {
    $rows = [];
    foreach ($texts as $i => $t) { $rows[] = $rRow($t, (array) ($rowOpts[$i] ?? [])); }
    $GLOBALS['LRG_TEST_INDEX'] = ['rows' => $rows, 'layers' => []];
    $recs = lrgPromptLookup($texts, ['quests' => ['ReachQuest'], 'bamt' => 40]);
    $ents = [];
    foreach ($texts as $i => $t) { $ents[] = ['pos' => $i, 'i' => 100 + $i, 'new' => 0, 'col' => 0, 'text' => $t]; }
    $E = lrgDlgDecorateEntries($ents, $recs, ['pg' => 500, 'bamt' => 40], ['pg' => 500]);
    unset($GLOBALS['LRG_DLG_TURN'], $GLOBALS['LRG_DLG_MCM'], $GLOBALS['LRG_DLG_MCM_SNAP']);
    $GLOBALS['LRG_DLG_STATE'] = [];
    $GLOBALS['LRG_TEST_NPCSTATE'] = [$npc => $snap + ['npc' => $npc, '_age' => 0]];
    $GLOBALS['LRG_DLG_TEST_STORE'] = ['*install*' => ['clicks_ok' => 1],
        $npc => ['utter' => ['text' => $say, 'at' => lrgNow() - 3, 'cid' => 'rw'], 'facts' => ['pg' => 500], 'ref' => '0x0001']];
    $GLOBALS['LRG_DLG_TEST_QUEUE'] = [];
    $sess = ['sid' => 's1', 'gen' => 1, 'layer' => 0, 'state' => 'open', 'at' => lrgNow(), 'kind' => 'root', 'entries' => $E, 'crit' => 0,
        'scene' => 0, 'sj' => 0, 'drv' => 1, 'n' => count($E), 'sent' => count($E)];
    lrgDlgAnswerWant($npc, ['cid' => 'RW', 'ask' => ''], $sess);
    foreach ((array) $GLOBALS['LRG_DLG_TEST_QUEUE'] as $q) {
        $a = (string) ($q['action'] ?? '');
        $at = strpos($a, '@');
        $kv = $at === false ? [] : lrgParseKv(substr($a, $at + 1));
        if (in_array((string) ($kv['do'] ?? ''), ['pick', 'show'], true)) {
            $pos = (int) ($kv['pos'] ?? -1);
            return ['do' => (string) $kv['do'], 'pos' => $pos, 'text' => $pos >= 0 ? (string) ($texts[$pos] ?? '') : ''];
        }
    }
    return ['do' => '', 'pos' => -1, 'text' => ''];
};
$w = [];
$w[] = ['[G6 / measurer] "ready, let\'s go" at Hadvar (CWMission07) clicks "Ready. Let\'s go." (was: the follower arbiter, nothing)',
    $rWant('Hadvar', ["Ready. Let's go.", "I have a better plan. You wait here, and I'll take care of it."], "ready, let's go"), "Ready. Let's go."];
$w[] = ['[G6 / measurer] "let\'s go find him" at Barbas (DA03) clicks "Sounds easy enough. Let\'s go find him."',
    $rWant('Barbas', ['Sounds easy enough. Let\'s go find him.', 'What happened between you and Clavicus?'], "let's go find him"), "Sounds easy enough. Let's go find him."];
$w[] = ['[G6] ...but on his COMPANION\'s list (teammate) a follower verb still settles nothing there (CHIM / the escort carry her orders)',
    $rWant('Barbas', ['Sounds easy enough. Let\'s go find him.', 'What happened between you and Clavicus?'], "let's go find him", [], ['mate' => '1']), ''];
$w[] = ['[G4 / measurer] "got any work for me?" at Gunmar clicks "What can I do to help?"',
    $rWant('Gunmar', ['What can I do to help?', 'I need training in Heavy Armor.', 'What have you got for sale?'], 'got any work for me?'), 'What can I do to help?'];
$w[] = ['[G4 / measurer] "put me to work" at Urag with no work line clicks NOTHING (was: "You take your work very seriously.")',
    $rWant('Urag gro-Shub', ["Are there any special books you're looking for?", 'This is quite an impressive library.', 'You take your work very seriously.'], 'put me to work'), ''];
$w[] = ['[Nazir] "Narfi has been dealt with" clicks "Narfi is dead." (was: her T-key only)',
    $rWant('Nazir', ['Narfi is dead.', 'Tell me about Narfi.', 'Tell me about Ennodius.', 'Tell me about Beitild.'], 'Narfi has been dealt with'), 'Narfi is dead.'];
$w[] = ['[Nazir] "is Narfi dead?" clicks nothing',
    $rWant('Nazir', ['Narfi is dead.', 'Tell me about Narfi.', 'Tell me about Ennodius.', 'Tell me about Beitild.'], 'is Narfi dead?'), ''];
$w[] = ['[G2 / measurer] "why were you weighting for me" clicks "Why were you waiting for me?"',
    $rWant('Vilkas', ['Why were you waiting for me?', 'What do you want?'], 'why were you weighting for me'), 'Why were you waiting for me?'];
$w[] = ['[G2 / measurer] "how can i health" clicks "How can I help?"',
    $rWant('Galmar', ['How can I help?', 'Tell me about the Legion.'], 'how can i health'), 'How can I help?'];
$w[] = ['[G2] "tell me about the war" still clicks nothing on "Tell me about Whiterun." (the frame rule)',
    $rWant('Farengar', ['Tell me about Whiterun.', 'Goodbye.'], 'tell me about the war'), ''];
foreach ($w as [$name, $res, $want]) {
    chk($name, $want === '' ? $res['do'] === '' : ($res['do'] === 'pick' && $res['text'] === $want), json_encode($res));
}
unset($GLOBALS['LRG_DLG_TEST_STORE'], $GLOBALS['LRG_TEST_NPCSTATE']);
$GLOBALS['LRG_DLG_STATE'] = [];
// ---- U1: open.kind_factions (filled by the final fixer - kept honest here)
$ko = static fn(string $kind, string $fac): bool => lrgDlgKindMayOpen(['npc' => 'Driver', 'snap' => ['fac' => $fac, '_age' => 0]], $kind);
chk('[U1] a carriage / ferry / training sentence opens pre-LLM only for a driver / ferryman / trainer: CarriageSystemFaction, DLC1FerrySystemFaction, '
    . 'JobTrainerFaction yes; JobAnimalTrainerFaction, GuardFaction and no snapshot no',
    $ko('carriage', 'CarriageSystemFaction,TownWhiterunFaction') && $ko('ferry', 'DLC1FerrySystemFaction') && $ko('train', 'JobTrainerFaction')
    && !$ko('train', 'JobAnimalTrainerFaction') && !$ko('carriage', 'GuardFaction') && !lrgDlgKindMayOpen(['npc' => 'Driver'], 'carriage'));
// ---- [pt19h-reach review] the must-nots the review found: HER need is no work ask, a hedge / the doer / another deed is no
// report, and the homophone fold never edits inside a contraction
$bad = [];
foreach (['are you looking for work?', 'do you need work?', 'can you lend a hand?', 'did you find work?', 'is he looking for work',
    'you should find work', "that's a lot of extra work", 'did you get any work done on my armor'] as $s) {
    if (lrgDlgReachWorkAsk($s) !== '') { $bad[] = $s; }
}
foreach (["i'm looking for work", 'where can i find work', 'let me lend a hand', 'need work?', "i'm ready for some extra work"] as $s) {
    if (lrgDlgReachWorkAsk($s) === '') { $bad[] = "$s (missed)"; }
}
chk('[pt19h-reach review] a need / seek phrase about HER or somebody else is no work ask ("are you looking for work?", "can you lend a hand?"), '
    . 'nor a remark ("that\'s a lot of extra work"); his own still is', $bad === [], implode('; ', $bad));
$bad = [];
foreach (['i think narfi is dead', 'maybe narfi is dead', 'narfi is probably dead', 'i heard narfi is dead', 'i doubt narfi is dead',
    'narfi is almost dead', 'narfi nearly killed me', 'narfi killed my dog', 'narfi finished his soup', 'i finished talking to narfi',
    'i am done with narfi', 'narfi is dead to me', 'narfi is as good as dead'] as $s) {
    if ($kp($naz, $s)['entry'] !== null) { $bad[] = $s; }
}
foreach (['i killed narfi', 'i took care of narfi', 'narfi was killed', "it's done, narfi is dead"] as $s) {
    if ($pickText($kp($naz, $s)) !== 'Narfi is dead.') { $bad[] = "$s (missed)"; }
}
chk('[pt19h-reach review] a hedge, hearsay, an idiom, the name as the DOER or a deed that is not the contract never picks the turn-in by kind; '
    . '"I killed Narfi" still does', $bad === [], implode('; ', $bad));
$got = lrgDlgSttFold("i won't pay, give me the won on the left", [$rE('Give me the one on the left.')]);
chk('[pt19h-reach review] the homophone fold never edits inside a contraction ("won\'t" stays, the lone "won" becomes "one")',
    str_contains($got, "won't") && str_contains($got, 'the one on'), $got);
// ---- the shipped JSON carries every reach list (the code constants are the default)
$jr = (array) (((array) (json_decode((string) @file_get_contents(LRG_DIR . '/config/lrg_config.default.json'), true)['dialogue'] ?? []))['reach'] ?? []);
$miss = [];
foreach (['work.say' => LRG_DLG_REACH_WORK_SAY, 'work.not_after' => LRG_DLG_REACH_WORK_NOT_AFTER, 'work.entry' => LRG_DLG_REACH_WORK_ENTRY,
    'report.say' => LRG_DLG_REACH_REPORT_SAY, 'report.entry' => LRG_DLG_REACH_REPORT_ENTRY, 'report.not_with' => LRG_DLG_REACH_REPORT_NOT_WITH,
    'report.not_after' => LRG_DLG_REACH_REPORT_NOT_AFTER] as $p => $c) {
    [$a, $b] = explode('.', $p);
    $j = $jr[$a][$b] ?? null;
    if (!is_array($j) || array_diff($c, $j)) { $miss[] = $p; }
}
chk('[pt19h-reach] dialogue.reach.* in the config JSON is COMPLETE (a superset of the code constants), both lanes on',
    $miss === [] && !empty($jr['work']['enabled']) && !empty($jr['report']['enabled']), implode(', ', $miss));

printf("\n%d passed, %d failed\n", $ok, $fail);
echo $fail === 0 ? "ALL CHECKS PASSED\n" : "RESULT: FAILED\n";
exit($fail === 0 ? 0 : 1);
