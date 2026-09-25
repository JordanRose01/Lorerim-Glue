<?php
/**
 * LoreRim Glue - [pt19-purchase / PROTOCOL 10.27] BUYING FOOD AND DRINK BY VOICE, at the price the game asks.
 *
 * Hulda, 2026-09-24 02:53-02:55: "i'll have uh l please" / "uh some beer" / "yeah thank you" -> "That'll be one septim
 * for a bottle of Honningbrew" and no bottle. Three defects stacked: no lane recognised an order, nothing could hand
 * over a bottle from the inn's merchant chest (the DLL only ever ships her personal inventory; Give_Item_To is bound
 * to it), and no rail judged the price she invented. This file is the server half of the ONE route that fixes it:
 *
 *   GAME (LRG_Profile.MarketFacts, on the snapshot): vend= / room= / bp= / stock=<hex8>:<name>:<price>:<count>:<value>
 *        - what the vendor's merchant chest really holds, priced by the game's own barter formula from LIVE inputs
 *        (LRG_Profile.BuyMult: fBarter* game settings, Speech, the two Speech modifier actor values, the held price
 *        perks of this load order), at most 12 consumable rows, drinks first, food never starved.
 *   SERVER (this file): the recogniser (exact name first, then a distinctive word, then a class word: beer -> Ale),
 *        the plan (none | not-vendor | no-stock | ask | quote | pending | unaffordable | queued), the locked lines
 *        ("what <npc> sells, at the price <npc> asks"), the directive, the hide policy (Give_Item_To can never hand
 *        over stock), the NET that appends ONE `<npc>|command|ExtCmdLRG_Buy@...` line after her own words (D1 only,
 *        the 10.26 shape), the funcret verdict (OK quiet, the cached row refreshed from unit=/stock=; Error voiced),
 *        and the never-false PRICE class (lib/lrg_replies.php): a price frame with a number the list does not carry -
 *        digits OR words ("one septim") - is a false claim, re-asked once, then floored.
 *   GAME (LRG_Main.CmdBuy): every condition re-checked, the price recomputed, her line waited for, THEN the item and
 *        the septims move; every refusal voiced with the real reason (NEVER SILENT), the price re-quoted from the
 *        game's own number when it moved (NEVER FALSE).
 *
 * Loaded by lib/lrg_actions.php (the funcret path needs the verdict) AND lib/lrg_dialogue.php (the turn path). It
 * requires nothing but lrg_core.php: every call into the dialogue lane is guarded, so the preprocessing branch that
 * loads lrg_dialogue.php alone (the lrgCsv() trap of 0.4.0) cannot fatal here. The recogniser is only ever called from
 * lrgDlgPrepareTurn, where the dialogue lane and the prompt index are loaded.
 *
 * Config: the owner's `dialogue.market` block (config/lrg_config.default.json) over lrgMktDefaults().
 * Log: grep lorerim_glue.log for 'buy ' - "buy plan", "buy net", "buy result", "buy price drift", "inn: CHIM RentRoom".
 */

require_once __DIR__ . '/lrg_core.php';

if (!defined('LRG_ACT_BUY')) { define('LRG_ACT_BUY', 'ExtCmdLRG_Buy'); }
/** The GlueBuy row's own follow-up prompt: its funcret turn is a refusal (an OK stays quiet - the words already said it). */
const LRG_BUY_FOLLOWUP_PROMPT = 'The game has just answered the order. Say the outcome in one short spoken line in your own voice - exactly what it handed over or refused and the real price, nothing more, and never pretend more happened.';
/** The plan states on which the market lane owns the turn's wording (the directive, the hide policy, the barter net stands down). */
const LRG_MKT_ACTIVE = ['queued', 'ask', 'quote', 'unaffordable', 'pending', 'not-vendor'];

function lrgMktDefaults(): array
{
    return [
        'enabled' => true,
        // how old the snapshot's stock= may be for a plan / a locked line (the game rescans at most every 60 s per NPC)
        'fresh_seconds' => 300,
        // a buy already sent and not yet answered holds a second order back this long
        'pending_seconds' => 120,
        // "yeah thank you" confirms the glue's OWN single-item offer for this long, and only that
        'offer_seconds' => 90,
        // at most this many real items named in an ask / a quote
        'list_max' => 3,
        'max_count' => 5,
        // the locked line's wording: 'asking' = "at the price <npc> asks" (the game charges exactly what it quoted, or
        // refuses with the new number), never "the price the game charges" - Dynamic Pricing Framework and the perk
        // entry-point order are modelled, not read from the engine (research/pt19-purchase.md section 3)
        'wording' => 'asking',
        // CHIM's RentRoom row charges its own cost_gold (default 10) while the game's RoomCost global is 25 here:
        // warn once per game session; align writes CHIM's row from the live global (an owner-editable CHIM row - off)
        'warn_rentroom_cost' => true,
        'align_rentroom_cost' => true,   // [pt19 ship] owner asked for a dearer room: CHIM charges the game's own RoomCost (25 here)
        // a name token that makes a row a DRINK (drinks are listed first, and "something to drink" means one of these)
        'drink_words' => ['mead', 'ale', 'beer', 'wine', 'brandy', 'cider', 'milk', 'juice', 'water', 'sujamma', 'flin',
            'matze', 'shein', 'lager', 'tea', 'skooma'],
        // class words -> the name token(s) they mean; 'drink' / 'food' are the two broad classes
        'class' => [
            'ale' => ['beer', 'beers', 'ale', 'ales', 'lager', 'lagers', 'pint', 'pints'],
            'mead' => ['mead', 'meads'],
            'wine' => ['wine', 'wines'],
            'milk' => ['milk'],
            'water' => ['water'],
            'bread' => ['bread', 'loaf', 'loaves'],
            'cheese' => ['cheese'],
            'stew' => ['stew', 'stews', 'soup', 'soups', 'broth'],
            'pie' => ['pie', 'pies'],
            'drink' => ['drink', 'drinks', 'something to drink', 'round', 'tankard', 'mug', 'bottle', 'cup', 'flagon',
                'something wet', 'a drink'],
            'food' => ['food', 'eat', 'something to eat', 'meal', 'bite', 'supper', 'dinner', 'breakfast', 'lunch', 'snack',
                'something hot', 'a bite'],
        ],
        // an order on its own: these need no item word ("i'll have uh l please" is an order with nothing recognisable)
        'frames_strong' => ["i'll have", 'ill have', 'i will have', "i'll take", 'ill take', 'i will take', 'pour me',
            "i'll buy", 'ill buy', 'i will buy', 'get me a', 'get me an', 'get me some', 'get me one', 'get me two',
            'bring me', 'fetch me', 'another round', 'same again', 'a bottle of', 'a mug of', 'a cup of', 'a tankard of',
            'a pint of', 'a flagon of', 'a bowl of', 'a plate of', "let's have", 'lets have', 'serve me', 'i need a drink',
            'i could use a drink', 'one more', 'another one', 'another'],
        // an order only together with an item or a class word ("i want" alone is nothing - it may be about anything)
        'frames_weak' => ['give me', 'can i get', 'could i get', 'can i have', 'could i have', 'may i have', 'i want',
            "i'd like", 'id like', 'i would like', 'let me have', 'let me get', 'let me buy', 'can i buy', 'could i buy',
            'i wanna', 'i want to buy', "i'd like to buy", 'sell me', 'do you have', 'have you got', 'got any', 'any',
            'gimme', 'buy', 'do you sell', 'do you serve', 'is there any'],
        // [review] an AVAILABILITY question with one of these frames ("do you have any ale?", "do you sell bread", "is there
        // any bread left") is never a sale on the spot: the one thing she has for it becomes the glue's own offer (the ask
        // state) and the player's next "yes" buys it. An imperative ("i'll have", "sell me", "get me") stays an order.
        'frames_ask' => ['do you have', 'have you got', 'got any', 'any', 'do you sell', 'do you serve', 'is there any'],
        // a frame followed by one of these is a gesture or a bribe, not an order ("can i buy | you a drink")
        'not_after' => ['you', 'ya', 'us', 'everyone', 'everybody', 'yourself', 'myself', 'him', 'her', 'them',
            'your silence', 'your body', 'your soul'],
        'fillers' => ['uh', 'um', 'er', 'erm', 'ah', 'hmm', 'hm', 'eh', 'please', 'pls', 'like', 'just'],
        'confirm' => ['yes', 'yeah', 'yep', 'yup', 'aye', 'sure', 'ok', 'okay', 'please', 'go on', 'do it', 'thank you',
            'thanks', 'that one', 'fine', 'alright', 'all right', 'sounds good', 'why not', 'deal', 'done', 'i will',
            'i do', 'good', 'great', 'perfect', 'yes please', 'that', 'this one'],
        'counts' => ['a' => 1, 'an' => 1, 'one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5, 'couple' => 2,
            'pair' => 2, 'few' => 3, 'some' => 1],
        // name tokens too generic to identify a row on their own (the class words are added at run time)
        'generic' => ['of', 'the', 'with', 'and', 'half', 'a', 'jug', 'bottle', 'cup', 'bowl', 'plate', 'dash', 'bag'],
    ];
}

/** One key of dialogue.market (dotted), the owner's block over the defaults. */
function lrgMktCfg(string $path = '', $default = null)
{
    static $cfg = null;
    if ($cfg === null || array_key_exists('LRG_MKT_TEST_OVERRIDE', $GLOBALS)) {
        $cfg = lrgMktDefaults();
        $over = (lrgConfig()['dialogue'] ?? [])['market'] ?? null;
        if (is_array($over)) { $cfg = lrgMerge($cfg, $over); }
        if (is_array($GLOBALS['LRG_MKT_TEST_OVERRIDE'] ?? null)) { $cfg = lrgMerge($cfg, $GLOBALS['LRG_MKT_TEST_OVERRIDE']); }
    }
    if ($path === '') { return $cfg; }
    $v = $cfg;
    foreach (explode('.', $path) as $k) {
        if (!is_array($v) || !array_key_exists($k, $v)) { return $default; }
        $v = $v[$k];
    }
    return $v;
}

function lrgMktLog(string $msg, string $cid = ''): void
{
    if (function_exists('lrgDlgLog')) { lrgDlgLog($msg, $cid); } else { lrgLog($msg, $cid); }
}

// ================================================================== the facts (snapshot -> rows)
/**
 * The market facts of one snapshot: ['vendor' => vendor|none|unknown, 'fresh' => bool, 'age' => int, 'stock' => rows,
 * 'bp' => [...], 'room' => int|null, 'pg' => int|null, 'at' => int]. A row: ['id' => hex8, 'name', 'norm', 'tokens',
 * 'price', 'count', 'value', 'drink' => 0|1]. Rows the game's funcret has refreshed since the snapshot (lrg_memory
 * buyadj: count / price after a sale or a re-quote) are overlaid. Never throws on a malformed value: a bad row is skipped.
 */
function lrgMktFacts(?array $snap, string $npc = ''): array
{
    $out = ['vendor' => 'unknown', 'fresh' => false, 'age' => 9999, 'stock' => [], 'bp' => [], 'room' => null, 'pg' => null, 'at' => 0];
    if (!is_array($snap)) { return $out; }
    $age = (int) ($snap['_age'] ?? 9999);
    $out['age'] = $age;
    $out['at'] = lrgNow() - $age;
    $out['fresh'] = $age <= max(5, (int) lrgMktCfg('fresh_seconds', 300));
    $vend = (string) ($snap['vend'] ?? '');
    if ($vend === '1') { $out['vendor'] = 'vendor'; }
    elseif ($vend === '0') { $out['vendor'] = 'none'; }
    elseif (function_exists('lrgDlgVendorHint')) { $out['vendor'] = (string) lrgDlgVendorHint($snap); }
    if (isset($snap['pgold']) && preg_match('/^\d{1,9}$/', (string) $snap['pgold'])) { $out['pg'] = (int) $snap['pgold']; }
    if (isset($snap['room']) && preg_match('/^\d{1,6}$/', (string) $snap['room'])) { $out['room'] = (int) $snap['room']; }
    $bp = array_map('trim', explode(',', (string) ($snap['bp'] ?? '')));
    if (count($bp) >= 7 && is_numeric($bp[0]) && is_numeric($bp[1]) && is_numeric($bp[2])) {
        $out['bp'] = ['max' => (float) $bp[0], 'min' => (float) $bp[1], 'buymin' => (float) $bp[2], 'speech' => (float) $bp[3],
            'spmod' => (float) $bp[4], 'sppow' => (float) $bp[5], 'mult' => (float) $bp[6], 'mods' => (string) ($bp[7] ?? '')];
    }
    $adj = $npc !== '' ? (array) (lrgMemGet($npc)['buyadj'] ?? []) : [];
    $drinkWords = array_map('strval', (array) lrgMktCfg('drink_words', []));
    foreach (array_filter(array_map('trim', explode(',', (string) ($snap['stock'] ?? '')))) as $raw) {
        $p = explode(':', $raw);
        if (count($p) < 4) { continue; }
        $id = strtoupper(trim((string) $p[0]));
        if (!preg_match('/^[0-9A-F]{8}$/', $id)) { continue; }
        $name = trim((string) $p[1]);
        if ($name === '' || !preg_match('/^\d{1,6}$/', trim((string) $p[2])) || !preg_match('/^\d{1,6}$/', trim((string) $p[3]))) { continue; }
        $row = ['id' => $id, 'name' => substr($name, 0, 40), 'price' => (int) $p[2], 'count' => (int) $p[3],
            'value' => isset($p[4]) && preg_match('/^\d{1,6}$/', trim((string) $p[4])) ? (int) $p[4] : null];
        // a sale or a re-quote the game answered AFTER this snapshot was taken (or in the same second: the funcret follows
        // the snapshot, and the game rescans her chest after a sale anyway) wins over the snapshot's numbers
        $a = (array) ($adj[$id] ?? []);
        if ($a && (int) ($a['at'] ?? 0) >= $out['at']) {
            if (isset($a['count'])) { $row['count'] = max(0, (int) $a['count']); }
            if (isset($a['price']) && (int) $a['price'] > 0) { $row['price'] = (int) $a['price']; }
        }
        $row['norm'] = lrgMktNorm($row['name']);
        $row['tokens'] = lrgMktTokens($row['norm']);
        $row['drink'] = 0;
        foreach ($row['tokens'] as $tk) { if (in_array(lrgMktSingular($tk), $drinkWords, true)) { $row['drink'] = 1; break; } }
        if ($row['count'] > 0 && $row['price'] > 0) { $out['stock'][] = $row; }
        if (count($out['stock']) >= 16) { break; }
    }
    // drinks first, the order of the wire otherwise (the game already put drinks first; a food-only chest is fine)
    usort($out['stock'], static fn($a, $b) => ($b['drink'] <=> $a['drink']));
    // the price mirror: the same formula in two places, and a drift line when they disagree (the verifiable computation)
    if ($out['bp'] && $out['fresh']) {
        foreach ($out['stock'] as $r) {
            if ($r['value'] === null || (int) $r['value'] <= 0) { continue; }
            $q = lrgMktModelPrice((int) $r['value'], $out['bp']);
            if ($q !== (int) $r['price'] && empty($adj[$r['id']])) {
                lrgMktLog(sprintf('buy price drift %s game=%d model=%d value=%d bp=%s', $r['name'], (int) $r['price'], $q, (int) $r['value'], (string) ($snap['bp'] ?? '-')));
            }
        }
    }
    if ($out['room'] !== null && $npc !== '') { lrgMktRoomCheck($out['room'], $npc, (string) ($snap['sess'] ?? '')); }
    return $out;
}

/**
 * THE PRICE MIRROR (UESP Skyrim:Speech): factor f = fBarterMax - (fBarterMax - fBarterMin) x min(Speech,100)/100; the
 * held price perks of this load order change a modifier that starts at 1.0 in priority order (multiply entries multiply
 * it, Requiem's Haggling ADDS -0.01 x Speech at priority 101 - an assumption about the entry-point order, see the note);
 * mult = max(fBarterBuyMin, f x mod); price = floor(value x mult + 0.5). `bp` carries the game's own inputs and mods
 * ("merchant:0.80/haggling:-0.15") so the mirror recomputes what the game computed, not what the plugins predict.
 */
function lrgMktModelPrice(int $value, array $bp): int
{
    $max = (float) ($bp['max'] ?? 3.3);
    $min = (float) ($bp['min'] ?? 2.0);
    $buymin = (float) ($bp['buymin'] ?? 1.05);
    $speech = max(0.0, min(100.0, (float) ($bp['speech'] ?? 0)));
    $f = $max - ($max - $min) * $speech / 100.0;
    $mod = 1.0;
    foreach (array_filter(array_map('trim', explode('/', (string) ($bp['mods'] ?? '')))) as $m) {
        if ($m === '-' ) { continue; }
        $p = explode(':', $m, 2);
        if (count($p) < 2 || !is_numeric($p[1])) { continue; }
        $k = strtolower(trim($p[0]));
        $v = (float) $p[1];
        if ($k === 'haggling') { $mod += $v; } else { $mod *= $v; }
    }
    $mult = $f * $mod;
    if ($mult < $buymin) { $mult = $buymin; }
    return (int) floor($value * $mult + 0.5);
}

// ================================================================== the recogniser
function lrgMktNorm(string $s): string
{
    if (function_exists('lrgPromptNorm')) { return lrgPromptNorm($s); }
    $s = strtolower(trim($s));
    $s = (string) preg_replace('/[^a-z0-9\' ]+/', ' ', $s);
    return trim((string) preg_replace('/\s+/', ' ', $s));
}

function lrgMktTokens(string $norm): array
{
    $t = preg_split('/\s+/', trim($norm));
    return is_array($t) ? array_values(array_filter($t, 'strlen')) : [];
}

/** meads -> mead, loaves -> loaf, pies -> pie; a token that is not a plural is returned as it is. */
function lrgMktSingular(string $tk): string
{
    if ($tk === 'loaves') { return 'loaf'; }
    if (strlen($tk) > 3 && str_ends_with($tk, 'ies')) { return substr($tk, 0, -3) . 'y'; }
    if (strlen($tk) > 3 && str_ends_with($tk, 's') && !str_ends_with($tk, 'ss')) { return substr($tk, 0, -1); }
    return $tk;
}

/** The utterance's tokens without STT fillers and stray single letters ("i'll have uh l please" -> i'll have). */
function lrgMktClean(array $tokens): array
{
    $fillers = array_map('strval', (array) lrgMktCfg('fillers', []));
    $out = [];
    foreach ($tokens as $tk) {
        if (in_array($tk, $fillers, true)) { continue; }
        if (strlen($tk) === 1 && $tk !== 'a' && $tk !== 'i' && !ctype_digit($tk)) { continue; }
        $out[] = $tk;
    }
    return $out;
}

/** The first index at which one of $phrases starts inside $hay (longest phrase wins), or -1; the hit phrase in $hit. */
function lrgMktPhraseAt(array $hay, array $phrases, ?string &$hit = null): int
{
    $hit = '';
    $bestAt = -1;
    $bestN = 0;
    foreach ($phrases as $p) {
        $pt = lrgMktTokens(lrgMktNorm((string) $p));
        if (!$pt || count($pt) <= $bestN) { continue; }
        $at = lrgMktRunAt($hay, $pt);
        if ($at >= 0) { $bestAt = $at; $bestN = count($pt); $hit = (string) $p; }
    }
    return $bestAt;
}

/** The token length of the LONGEST phrase of $phrases that starts at index 0 of $hay, or 0 when none does. */
function lrgMktPrefixPhrase(array $hay, array $phrases): int
{
    $best = 0;
    foreach ($phrases as $p) {
        $pt = lrgMktTokens(lrgMktNorm((string) $p));
        if (!$pt || count($pt) <= $best || count($pt) > count($hay)) { continue; }
        $ok = true;
        foreach ($pt as $j => $w) { if ($hay[$j] !== $w && lrgMktSingular($hay[$j]) !== lrgMktSingular($w)) { $ok = false; break; } }
        if ($ok) { $best = count($pt); }
    }
    return $best;
}

function lrgMktRunAt(array $hay, array $needle): int
{
    $n = count($needle);
    $h = count($hay);
    if ($n === 0 || $n > $h) { return -1; }
    for ($i = 0; $i + $n <= $h; $i++) {
        $ok = true;
        for ($j = 0; $j < $n; $j++) {
            if ($hay[$i + $j] !== $needle[$j] && lrgMktSingular($hay[$i + $j]) !== lrgMktSingular($needle[$j])) { $ok = false; break; }
        }
        if ($ok) { return $i; }
    }
    return -1;
}

/** The rows a class word means: 'ale' -> the name token ale; 'drink' -> every drink; 'food' -> everything else. */
function lrgMktClassRows(string $cls, array $stock): array
{
    if ($cls === 'drink') { return array_values(array_filter($stock, static fn($r) => !empty($r['drink']))); }
    if ($cls === 'food') { return array_values(array_filter($stock, static fn($r) => empty($r['drink']))); }
    $out = [];
    foreach ($stock as $r) {
        foreach ((array) ($r['tokens'] ?? []) as $tk) {
            $s = lrgMktSingular($tk);
            if ($s === $cls || ($cls === 'stew' && $s === 'soup') || ($cls === 'ale' && $s === 'beer')) { $out[] = $r; break; }
        }
    }
    return $out;
}

/**
 * ['kind' => order|question|confirm|'', 'item' => row|null, 'cls' => '', 'n' => 1, 'ambiguous' => [rows], 'negated' => bool,
 *  'why' => '', 'at' => int]. Exact name run first (bread -> "Bread", never "Bread, Half"; the longest run wins, two runs
 * that do not contain each other are ambiguous), then a distinctive name word (honningbrew, nord, village), then a class
 * word (beer -> the rows with the token ale; mead with several meads -> ambiguous -> ask). A price question quotes and sells
 * nothing; a negation within the clause ("don't give me mead") is nothing; a frame followed by a person ("can i buy you a
 * drink") is a gesture. $pendingOffer: the glue's own single-item offer still open - a bare confirmation then confirms IT.
 */
function lrgMktRecognise(string $utter, array $stock, ?array $pendingOffer = null): array
{
    $out = ['kind' => '', 'item' => null, 'cls' => '', 'n' => 1, 'ambiguous' => [], 'negated' => false, 'why' => '', 'at' => -1, 'frame' => ''];
    $norm = lrgMktNorm($utter);
    $raw = lrgMktTokens($norm);
    $hay = lrgMktClean($raw);
    if (!$hay) { $out['why'] = 'empty'; return $out; }
    $stops = function_exists('lrgDlgClauseStarts') ? lrgDlgClauseStarts($utter) : [];
    $negAt = static function (int $at) use ($raw, $stops): bool {
        if ($at < 0) { return false; }
        return function_exists('lrgDlgNegatedAt') ? lrgDlgNegatedAt($raw, $at, $stops) : false;
    };
    // the raw index of a clean-token index (fillers were dropped): the negation guard runs on the raw tokens
    $rawAt = static function (int $cleanAt) use ($raw, $hay): int {
        if ($cleanAt < 0 || !isset($hay[$cleanAt])) { return -1; }
        $seen = 0;
        foreach ($raw as $i => $tk) {
            if (!isset($hay[$seen])) { break; }
            if ($tk === $hay[$seen]) { if ($seen === $cleanAt) { return $i; } $seen++; }
        }
        return $cleanAt;
    };
    $classes = (array) lrgMktCfg('class', []);
    $generic = array_map('strval', (array) lrgMktCfg('generic', []));
    foreach ($classes as $cls => $words) { $generic[] = (string) $cls; foreach ((array) $words as $w) { $generic[] = lrgMktSingular((string) $w); } }
    // 1. exact name runs
    $exact = [];
    foreach ($stock as $r) {
        $tk = (array) ($r['tokens'] ?? []);
        if (!$tk) { continue; }
        $at = lrgMktRunAt($hay, $tk);
        if ($at >= 0) { $exact[] = ['row' => $r, 'at' => $at, 'n' => count($tk)]; }
    }
    if ($exact) {
        usort($exact, static fn($a, $b) => $b['n'] <=> $a['n']);
        $best = $exact[0];
        $ties = array_values(array_filter($exact, static fn($e) => $e['n'] === $best['n'] && $e['row']['id'] !== $best['row']['id']));
        if ($ties) {
            $out['ambiguous'] = array_merge([$best['row']], array_map(static fn($e) => $e['row'], $ties));
        } else {
            $out['item'] = $best['row'];
        }
        $out['at'] = (int) $best['at'];
    }
    // 2. a distinctive word of a name (never a class or a generic word)
    if ($out['item'] === null && !$out['ambiguous']) {
        $hits = [];
        foreach ($stock as $r) {
            foreach ((array) ($r['tokens'] ?? []) as $tk) {
                $s = lrgMktSingular($tk);
                if (strlen($s) < 4 || in_array($s, $generic, true)) { continue; }
                foreach ($hay as $i => $h) {
                    if (lrgMktSingular($h) === $s) { $hits[$r['id']] = ['row' => $r, 'at' => $i]; break 2; }
                }
            }
        }
        if (count($hits) === 1) { $h = array_values($hits)[0]; $out['item'] = $h['row']; $out['at'] = (int) $h['at']; }
        elseif (count($hits) > 1) { $out['ambiguous'] = array_map(static fn($h) => $h['row'], array_values($hits)); $out['at'] = (int) array_values($hits)[0]['at']; }
    }
    // 3. a class word
    if ($out['item'] === null && !$out['ambiguous']) {
        $bestCls = '';
        $bestAt = -1;
        $bestN = 0;
        foreach ($classes as $cls => $words) {
            $hit = '';
            $at = lrgMktPhraseAt($hay, (array) $words, $hit);
            if ($at < 0) { continue; }
            $n = count(lrgMktTokens(lrgMktNorm($hit)));
            if ($n > $bestN || ($n === $bestN && $cls !== 'drink' && $cls !== 'food' && in_array($bestCls, ['drink', 'food'], true))) {
                $bestCls = (string) $cls; $bestAt = $at; $bestN = $n;
            }
        }
        if ($bestCls !== '') {
            $out['cls'] = $bestCls;
            $out['at'] = $bestAt;
            $rows = lrgMktClassRows($bestCls, $stock);
            if (count($rows) === 1 && $bestCls !== 'drink' && $bestCls !== 'food') { $out['item'] = $rows[0]; }
            elseif (count($rows) > 1) { $out['ambiguous'] = $rows; }
        }
    }
    // the frames
    $strongHit = '';
    $strongAt = lrgMktPhraseAt($hay, (array) lrgMktCfg('frames_strong', []), $strongHit);
    $weakHit = '';
    $weakAt = lrgMktPhraseAt($hay, (array) lrgMktCfg('frames_weak', []), $weakHit);
    $frameAt = $strongAt >= 0 ? $strongAt : $weakAt;
    $out['frame'] = $strongAt >= 0 ? $strongHit : $weakHit;
    // a gesture / a bribe: the words right after the frame name a person
    $frameTok = $out['frame'] !== '' ? lrgMktTokens(lrgMktNorm($out['frame'])) : [];
    if ($frameAt >= 0 && $frameTok) {
        $after = array_slice($hay, $frameAt + count($frameTok));
        foreach ((array) lrgMktCfg('not_after', []) as $s) {
            $st = lrgMktTokens(lrgMktNorm((string) $s));
            if ($st && array_slice($after, 0, count($st)) === $st) { $out['why'] = 'gesture'; return $out; }
        }
    }
    foreach ($hay as $i => $tk) {
        if (in_array($tk, ['buy', 'get', 'pour', 'bring'], true) && isset($hay[$i + 1]) && in_array($hay[$i + 1], ['you', 'ya', 'us', 'him', 'her', 'them', 'everyone', 'everybody'], true)) {
            $out['why'] = 'gesture'; return $out;
        }
    }
    $hasThing = $out['item'] !== null || $out['ambiguous'] || $out['cls'] !== '';
    // a price question: "how much for a mead" - quote, sell nothing
    if (function_exists('lrgDlgIsPriceQuestion') && lrgDlgIsPriceQuestion($utter)) {
        $out['kind'] = $hasThing ? 'question' : '';
        $out['why'] = $hasThing ? 'price question' : 'price question about nothing on the list';
        return $out;
    }
    // the negation guard, on whichever came first: the frame or the thing
    $checkAt = $hasThing && $out['at'] >= 0 ? $out['at'] : $frameAt;
    if ($frameAt >= 0 && $hasThing && $out['at'] >= 0) { $checkAt = min($frameAt, (int) $out['at']); }
    if ($checkAt >= 0 && $negAt($rawAt($checkAt))) { $out['negated'] = true; $out['why'] = 'negated'; return $out; }
    // the count: a number word before the thing, else 1. Digits are read off the RAW words: lrgPromptNorm folds every digit to '#'
    $counts = (array) lrgMktCfg('counts', []);
    $n = 1;
    $thingAt = $hasThing ? (int) $out['at'] : -1;
    foreach ($hay as $i => $tk) {
        if ($thingAt >= 0 && $i >= $thingAt) { break; }
        if (isset($counts[$tk]) && ($tk !== 'a' && $tk !== 'an' && $tk !== 'some')) { $n = (int) $counts[$tk]; }
    }
    if (preg_match('/(?:^|\s)(\d{1,2})(?:\s|$)/', strtolower($utter), $dm) && (int) $dm[1] > 0) { $n = (int) $dm[1]; }
    $out['n'] = max(1, min((int) lrgMktCfg('max_count', 5), $n));
    // the kind
    if ($strongAt >= 0 || ($weakAt >= 0 && $hasThing) || ($hasThing && count($hay) <= 5)) {
        $out['kind'] = 'order';
        return $out;
    }
    // a bare confirmation of the glue's OWN single-item offer: every token belongs to a confirmation phrase
    if (is_array($pendingOffer) && !$hasThing) {
        $conf = array_map('strval', (array) lrgMktCfg('confirm', []));
        $rest = $hay;
        $ok = true;
        while ($rest) {
            $len = lrgMktPrefixPhrase($rest, $conf);
            if ($len <= 0) { $ok = false; break; }
            $rest = array_slice($rest, $len);
        }
        if ($ok && !$negAt($rawAt(0)) && !preg_match('/\b(no|nope|nah|not)\b/', $norm)) { $out['kind'] = 'confirm'; return $out; }
    }
    $out['why'] = $hasThing ? 'a thing named, no order' : 'no order';
    return $out;
}

// ================================================================== the plan
/**
 * Decided ONCE per turn in lrgDlgPrepareTurn (after svc, before the locked facts) from the player's own words and Phase 2's
 * own state. ['state' => none|not-vendor|no-stock|ask|quote|pending|unaffordable|queued, 'why', 'item' => row|null,
 * 'n', 'price' (unit), 'total', 'list' => [rows], 'cls', 'param', 'x', 'pg', 'facts' => lrgMktFacts()].
 */
function lrgMktPlan(array $turn, string $utter, bool $isSpeech): array
{
    $npc = (string) ($turn['npc'] ?? '');
    $cid = (string) ($turn['cid'] ?? '');
    $plan = ['state' => '', 'why' => '', 'item' => null, 'n' => 1, 'price' => 0, 'total' => 0, 'list' => [], 'cls' => '',
        'param' => '', 'x' => '', 'pg' => null, 'facts' => null, 'rec' => null];
    $facts = lrgMktFacts(is_array($turn['snap'] ?? null) ? $turn['snap'] : null, $npc);
    $plan['facts'] = $facts;
    $plan['pg'] = $facts['pg'];
    if (empty(lrgMktCfg('enabled', true))) { $plan['why'] = 'off'; return $plan; }
    if (empty($turn['on']) || !$isSpeech || $npc === '') { $plan['why'] = 'not a player turn'; return $plan; }
    if (!empty($turn['open']) || !empty($turn['lost'])) { $plan['why'] = 'a session is open'; return $plan; }
    if (function_exists('lrgDlgServicesOn') && !lrgDlgServicesOn($npc)) { $plan['why'] = 'services off'; return $plan; }
    // Phase 1 recognised a real intent of its own (an act, clothes, an offer of gold for intimacy): never an order
    $p1 = (string) ((($GLOBALS['LRG_TURN'] ?? [])['intent'] ?? [])['kind'] ?? '');
    if ($p1 !== '' && $p1 !== 'none' && strcasecmp((string) (($GLOBALS['LRG_TURN'] ?? [])['npc'] ?? ''), $npc) === 0) {
        $plan['why'] = 'intimacy intent ' . $p1; return $plan;
    }
    $mem = lrgMemGet($npc);
    $offer = (array) ($mem['buyask'] ?? []);
    $offerFresh = $offer && lrgNow() - (int) ($offer['at'] ?? 0) <= max(10, (int) lrgMktCfg('offer_seconds', 90));
    $rec = lrgMktRecognise($utter, $facts['fresh'] ? $facts['stock'] : [], $offerFresh ? $offer : null);
    $plan['rec'] = $rec;
    $plan['cls'] = (string) $rec['cls'];
    if ((string) $rec['kind'] === '') {
        $plan['why'] = (string) $rec['why'];
        // an offer answered with anything but a confirmation is over
        if ($offer && !$rec['negated']) { lrgMemSet($npc, ['buyask' => null]); }
        return $plan;
    }
    // no fresh stock: a vendor's order falls back to the barter window (the caller sets the barter kind); a snapshot
    // that listed her factions and found no vendor among them says so in words
    if (!$facts['fresh'] || !$facts['stock']) {
        if ($facts['vendor'] === 'none') { $plan['state'] = 'not-vendor'; $plan['why'] = 'no vendor faction on the snapshot'; }
        else { $plan['state'] = 'no-stock'; $plan['why'] = $facts['fresh'] ? 'a vendor with no consumables on the wire' : 'no fresh stock (age ' . $facts['age'] . ')'; }
        lrgMktLog('buy plan npc=' . $npc . ' state=' . $plan['state'] . ' why=' . $plan['why'] . ' say="' . substr(str_replace('"', "'", $utter), 0, 60) . '"', $cid);
        return $plan;
    }
    if ((string) $rec['kind'] === 'question') {
        $plan['state'] = 'quote';
        $plan['item'] = $rec['item'];
        $plan['list'] = $rec['item'] ? [$rec['item']] : array_slice((array) ($rec['ambiguous'] ?: lrgMktClassRows($rec['cls'] ?: 'drink', $facts['stock'])), 0, (int) lrgMktCfg('list_max', 3));
        if ($rec['item']) { $plan['price'] = (int) $rec['item']['price']; $plan['n'] = (int) $rec['n']; $plan['total'] = $plan['price'] * $plan['n']; }
        $plan['why'] = 'price question';
        lrgMktLog('buy plan npc=' . $npc . ' state=quote ' . ($rec['item'] ? $rec['item']['name'] . '@' . $rec['item']['price'] : 'list=' . count($plan['list'])), $cid);
        return $plan;
    }
    // a buy already sent and not answered
    $exec = (array) ($mem['buyexec'] ?? []);
    if ($exec && empty($exec['done']) && lrgNow() - (int) ($exec['at'] ?? 0) <= max(10, (int) lrgMktCfg('pending_seconds', 120))) {
        $plan['state'] = 'pending';
        $plan['exec'] = $exec;   // the buy in flight (not a stock row: no count on it)
        $plan['why'] = 'a buy is in flight (' . (string) ($exec['name'] ?? '?') . ')';
        lrgMktLog('buy plan npc=' . $npc . ' state=pending ' . (string) ($exec['name'] ?? '?'), $cid);
        return $plan;
    }
    $item = $rec['item'];
    $n = (int) $rec['n'];
    if ((string) $rec['kind'] === 'confirm') {
        $item = null;
        foreach ($facts['stock'] as $r) { if ((string) $r['id'] === (string) ($offer['id'] ?? '')) { $item = $r; break; } }
        $n = max(1, (int) ($offer['n'] ?? 1));
        lrgMemSet($npc, ['buyask' => null]);
        if ($item === null) { $plan['why'] = 'the offered item is gone'; lrgMktLog('buy plan npc=' . $npc . ' confirm of a vanished offer', $cid); return $plan; }
    }
    // [review] an availability question is an OFFER, not a sale: "do you have any ale?" handed the ale over and took the
    // 19 septims on the spot before this; now she offers it at the listed price and the player's next "yes" buys it
    $avail = $item !== null && (string) $rec['kind'] === 'order'
        && in_array((string) ($rec['frame'] ?? ''), array_map('strval', (array) lrgMktCfg('frames_ask', [])), true);
    if ($avail) { $rec['ambiguous'] = [$item]; $item = null; }
    if ($item === null) {
        // nothing exact: name what she really has (the class first, drinks first) and ask
        $rows = $rec['ambiguous'] ?: ($rec['cls'] !== '' ? lrgMktClassRows($rec['cls'], $facts['stock']) : []);
        if (!$rows) { $rows = $facts['stock']; }
        $plan['state'] = 'ask';
        $plan['list'] = array_slice(array_values($rows), 0, max(1, (int) lrgMktCfg('list_max', 3)));
        $plan['n'] = $n;
        $plan['why'] = $avail ? 'an availability question (' . (string) $rec['frame'] . ')'
            : ($rec['ambiguous'] ? 'ambiguous' : ($rec['cls'] !== '' ? 'a class word (' . $rec['cls'] . ')' : 'no item named'));
        // ONE candidate = the glue's own offer; a bare "yes" within offer_seconds confirms it (verified against her line by the net)
        if (count($plan['list']) === 1) {
            lrgMemSet($npc, ['buyask' => ['id' => (string) $plan['list'][0]['id'], 'name' => (string) $plan['list'][0]['name'],
                'price' => (int) $plan['list'][0]['price'], 'n' => $n, 'at' => lrgNow(), 'cid' => $cid, 'said' => 0]]);
        } elseif ($offer) {
            lrgMemSet($npc, ['buyask' => null]);
        }
        lrgMktLog('buy plan npc=' . $npc . ' state=ask why=' . $plan['why'] . ' list=' . implode('/', array_map(static fn($r) => $r['name'] . '@' . $r['price'], $plan['list'])), $cid);
        return $plan;
    }
    if ($n > (int) $item['count']) { $n = max(1, (int) $item['count']); $plan['why'] = 'clamped to the ' . (int) $item['count'] . ' left'; }
    $plan['item'] = $item;
    $plan['n'] = $n;
    $plan['price'] = (int) $item['price'];
    $plan['total'] = $plan['price'] * $n;
    if ($facts['pg'] !== null && $facts['pg'] < $plan['total']) {
        $plan['state'] = 'unaffordable';
        $plan['why'] = 'pg ' . $facts['pg'] . ' < ' . $plan['total'];
        lrgMktLog('buy plan npc=' . $npc . ' state=unaffordable ' . $item['name'] . ' x' . $n . ' total=' . $plan['total'] . ' pg=' . $facts['pg'], $cid);
        return $plan;
    }
    $plan['state'] = 'queued';
    $plan['x'] = substr(md5($npc . '|' . $cid . '|' . $item['id'] . '|' . lrgNow()), 0, 10);
    $plan['param'] = lrgKv(['ok' => 1, 'cid' => $cid !== '' ? $cid : 'dlg', 'npc' => $npc, 'item' => (string) $item['id'], 'n' => $n,
        'price' => $plan['price'], 'name' => substr((string) $item['name'], 0, 32), 'x' => $plan['x'], 'z' => 1]);
    if ($plan['why'] === '') { $plan['why'] = 'resolved'; }
    lrgMktLog('buy plan npc=' . $npc . ' state=queued ' . $item['name'] . ' x' . $n . ' @' . $plan['price'] . ' total=' . $plan['total'] . ' pg=' . ($facts['pg'] ?? '-') . ' via=' . ($rec['kind'] === 'confirm' ? 'confirm' : ($rec['cls'] !== '' ? 'class:' . $rec['cls'] : 'name')), $cid);
    return $plan;
}

function lrgMktState(array $t): string { return (string) ((($t['buy'] ?? [])['state'] ?? '')); }

/** " buy=<state>[:<name>@<price>]" for the turn line, '' when the lane decided nothing. */
function lrgMktTurnTail(array $t): string
{
    $b = (array) ($t['buy'] ?? []);
    $s = (string) ($b['state'] ?? '');
    if ($s === '') { return ''; }
    $item = is_array($b['item'] ?? null) ? $b['item'] : null;
    return ' buy=' . $s . ($item ? ':' . str_replace(' ', '_', (string) ($item['name'] ?? '?')) . '@' . (int) ($b['price'] ?? ($item['price'] ?? 0)) . (((int) ($b['n'] ?? 1)) > 1 ? 'x' . (int) $b['n'] : '') : '');
}

// ================================================================== the locked lines and the prices she may name
/**
 * Is this a turn the stock belongs in her mouth? An order / ask / quote / refusal of the lane, an inn or barter conversation,
 * or a price question - never an idle chat with a vendor (the 600-char cap and every other lane's silence are kept), and
 * never a turn the lane did not run on (a non-adult, a scene, SHARMAT with the module off).
 */
function lrgMktShowFacts(array $t): bool
{
    if (lrgMktState($t) !== '') { return true; }
    if (in_array((string) ((($t['svc'] ?? [])['kind'] ?? '')), ['inn', 'barter'], true)) { return true; }
    $npc = (string) ($t['npc'] ?? '');
    if ($npc !== '' && function_exists('lrgDlgState') && function_exists('lrgDlgIsPriceQuestion')) {
        $u = (string) ((lrgDlgState($npc)['utter'] ?? [])['text'] ?? '');
        if ($u !== '' && lrgDlgIsPriceQuestion($u)) { return true; }
    }
    return false;
}

/** [class, line, num] rows for lrgDlgLockedFacts: the stock (one line, <= 6 rows), the room, the order's total - on a market turn only. */
function lrgMktLockedLines(array $t): array
{
    if (!lrgMktShowFacts($t)) { return []; }
    $b = (array) ($t['buy'] ?? []);
    $f = is_array($b['facts'] ?? null) ? $b['facts'] : lrgMktFacts(is_array($t['snap'] ?? null) ? $t['snap'] : null, (string) ($t['npc'] ?? ''));
    $npc = (string) ($t['npc'] ?? '') ?: 'she';
    $player = (string) ($GLOBALS['PLAYER_NAME'] ?? 'the player');
    $out = [];
    if (!empty($f['fresh']) && !empty($f['stock'])) {
        $rows = (array) $f['stock'];
        // the ordered / asked rows first, then the rest, at most six
        $first = [];
        foreach (array_merge(is_array($b['item'] ?? null) ? [$b['item']] : [], (array) ($b['list'] ?? [])) as $r) { if (is_array($r) && isset($r['id'])) { $first[(string) $r['id']] = $r; } }
        $ordered = array_values($first);
        foreach ($rows as $r) { if (!isset($first[(string) $r['id']])) { $ordered[] = $r; } }
        $parts = [];
        foreach (array_slice($ordered, 0, 6) as $r) {
            if (!isset($r['name'], $r['price'])) { continue; }
            $cnt = isset($r['count']) ? (int) $r['count'] : 99;
            $parts[] = $r['name'] . ' ' . (int) $r['price'] . ' septims' . ($cnt <= 20 ? ' (' . $cnt . ' left)' : '');
        }
        $lead = lrgMktCfg('wording', 'asking') === 'game' ? 'what ' . $npc . ' sells, at the price the game charges: ' : 'what ' . $npc . ' sells, at the price ' . $npc . ' asks: ';
        $out[] = ['stock', $lead . implode('; ', $parts), null];
    }
    if (isset($f['room']) && $f['room'] !== null && (int) $f['room'] > 0 && lrgMktIsInnkeeper($t)) {
        $out[] = ['price', 'a room here costs ' . (int) $f['room'] . ' septims', (int) $f['room']];
    }
    if ((string) ($b['state'] ?? '') === 'queued' && is_array($b['item'] ?? null)) {
        $out[] = ['price', $player . ' ordered ' . (int) $b['n'] . ' ' . $b['item']['name'] . ': ' . (int) $b['total'] . ' septims in all', (int) $b['total']];
    }
    return $out;
}

function lrgMktIsInnkeeper(array $t): bool
{
    $fac = ',' . strtolower((string) (((array) ($t['snap'] ?? []))['fac'] ?? '')) . ',';
    return str_contains($fac, ',jobinnkeeperfaction,') || preg_match('/,services[a-z]*inn[a-z]*,/', $fac) === 1
        || (function_exists('lrgDlgOffered') && lrgDlgOffered('RentRoom'));
}

/** Every septim figure she may say on this turn: the stock prices, the order's total, the live entries, the room, the bounty, the purse. */
function lrgMktAllowedPrices(array $t): array
{
    $b = (array) ($t['buy'] ?? []);
    $f = is_array($b['facts'] ?? null) ? $b['facts'] : lrgMktFacts(is_array($t['snap'] ?? null) ? $t['snap'] : null, (string) ($t['npc'] ?? ''));
    $nums = [];
    if (!empty($f['fresh'])) {
        foreach ((array) $f['stock'] as $r) { $nums[] = (int) $r['price']; }
        if (isset($f['room']) && (int) $f['room'] > 0) { $nums[] = (int) $f['room']; }
        if ($f['pg'] !== null) { $nums[] = (int) $f['pg']; }
    }
    if ((int) ($b['total'] ?? 0) > 0) { $nums[] = (int) $b['total']; }
    if ((int) ($b['price'] ?? 0) > 0) { $nums[] = (int) $b['price']; }
    foreach (array_merge((array) ($t['entries'] ?? []), (array) ($t['tail'] ?? [])) as $e) { if ((int) ($e['cost'] ?? 0) > 0) { $nums[] = (int) $e['cost']; } }
    $crime = (array) ($t['crime'] ?? []);
    if ((int) ($crime['bounty'] ?? 0) > 0) { $nums[] = (int) $crime['bounty']; }
    $pg = (int) (($t['facts'] ?? [])['pg'] ?? 0);
    if ($pg > 0) { $nums[] = $pg; }
    return array_values(array_unique(array_filter($nums)));
}

/** Does this turn carry a market fact the price rail can judge against (fresh stock, a room price, an order)? */
function lrgMktRailFacts(array $t): bool
{
    $b = (array) ($t['buy'] ?? []);
    $f = is_array($b['facts'] ?? null) ? $b['facts'] : lrgMktFacts(is_array($t['snap'] ?? null) ? $t['snap'] : null, (string) ($t['npc'] ?? ''));
    return !empty($f['fresh']) && (!empty($f['stock']) || (isset($f['room']) && (int) $f['room'] > 0 && lrgMktIsInnkeeper($t)));
}

const LRG_MKT_NUMWORDS = ['a' => 1, 'an' => 1, 'one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5, 'six' => 6, 'seven' => 7,
    'eight' => 8, 'nine' => 9, 'ten' => 10, 'eleven' => 11, 'twelve' => 12, 'thirteen' => 13, 'fourteen' => 14, 'fifteen' => 15,
    'sixteen' => 16, 'seventeen' => 17, 'eighteen' => 18, 'nineteen' => 19, 'twenty' => 20, 'thirty' => 30, 'forty' => 40,
    'fifty' => 50, 'sixty' => 60, 'seventy' => 70, 'eighty' => 80, 'ninety' => 90, 'hundred' => 100, 'couple' => 2, 'dozen' => 12, 'half' => 0];

/** "twenty-five" / "a hundred and twenty" / "39" / "a couple of" -> the integer, or null. */
function lrgMktNumber(string $s): ?int
{
    $s = strtolower(trim($s));
    if ($s === '') { return null; }
    if (preg_match('/^\d[\d,]*$/', $s)) { return (int) preg_replace('/[^0-9]/', '', $s); }
    $parts = array_values(array_filter((array) preg_split('/[\s-]+/', str_replace([' and ', ' of'], [' ', ''], ' ' . $s . ' ')), 'strlen'));
    // "a couple of", "a hundred", "an eight": the article is not a one when another number word follows it
    if (count($parts) > 1) { $parts = array_values(array_filter($parts, static fn($w) => $w !== 'a' && $w !== 'an')); }
    $total = 0;
    $cur = 0;
    $any = false;
    foreach ($parts as $w) {
        if (!isset(LRG_MKT_NUMWORDS[$w])) { return null; }
        $v = LRG_MKT_NUMWORDS[$w];
        $any = true;
        if ($w === 'hundred') { $cur = max(1, $cur) * 100; }
        elseif ($w === 'half') { continue; }
        else { $cur += $v; }
    }
    if (!$any) { return null; }
    $total += $cur;
    return $total > 0 ? $total : null;
}

// A price she QUOTES: a frame word ("costs", "that'll be", "for", "pay") within 24 characters before the number, OR the number at
// the start of a clause ("Ale, nineteen septims." / "Nineteen septims, friend." - a vendor's plainest phrasing); never a number in
// mid-sentence narration ("I lost 300 gold at dice" is left alone, S6). Digits or words; the money word after it is required.
const LRG_MKT_PRICE_FRAME = '/(?:\b(?:costs?|charges?|fare|price|fee|owes?|asking|for|only|just|(?:will|would|shall|\'ll)\s+be|that(?:\'s| is)|it(?:\'s| is)|pay(?: me)?|give me|hand over|make it|call it|comes? to|set(?:s)? you back)\b[^.!?]{0,24}?|(?:^|[.!?,;:(]|\s-)\s*)((?:\d[\d,]*|(?:a|an|one|two|three|four|five|six|seven|eight|nine|ten|eleven|twelve|thirteen|fourteen|fifteen|sixteen|seventeen|eighteen|nineteen|twenty|thirty|forty|fifty|sixty|seventy|eighty|ninety|hundred|couple|dozen|half)(?:[ -](?:and[ -])?(?:a|of|one|two|three|four|five|six|seven|eight|nine|ten|eleven|twelve|thirteen|fourteen|fifteen|sixteen|seventeen|eighteen|nineteen|twenty|thirty|forty|fifty|sixty|seventy|eighty|ninety|hundred))*))\s*(?:gold|septims?|coins?|drakes?)\b/i';

/** Every price she QUOTES in $s (a price frame around a number, digits or words), as ['n' => int, 'said' => text]. */
function lrgMktPriceMentions(string $s): array
{
    $out = [];
    if (!preg_match_all(LRG_MKT_PRICE_FRAME, $s, $m, PREG_SET_ORDER)) { return $out; }
    foreach ($m as $hit) {
        $n = lrgMktNumber((string) $hit[1]);
        if ($n !== null && $n > 0) { $out[] = ['n' => $n, 'said' => trim((string) $hit[0])]; }
    }
    return $out;
}

/** The never-false PRICE verdict on one sentence: null (no price quoted), or ['verdict' => true|false, 'fact', 'hit', 'mkt' => [...]]. */
function lrgMktPriceVerdict(string $sentence, array $t): ?array
{
    $mentions = lrgMktPriceMentions($sentence);
    if (!$mentions) { return null; }
    $allowed = lrgMktAllowedPrices($t);
    $b = (array) ($t['buy'] ?? []);
    $item = is_array($b['item'] ?? null) ? $b['item'] : (is_array(($b['list'] ?? [])[0] ?? null) ? $b['list'][0] : null);
    $listed = $item ? (string) $item['name'] . ' is ' . (int) $item['price'] . ' septims' : 'the list carries ' . implode(', ', array_slice($allowed, 0, 6)) . ' septims';
    foreach ($mentions as $mn) {
        if (!in_array((int) $mn['n'], $allowed, true)) {
            return ['verdict' => 'false', 'hit' => (string) $mn['said'], 'fact' => 'the list says ' . $listed . ', not ' . (int) $mn['n'],
                'mkt' => ['npc' => (string) ($t['npc'] ?? ''), 'item' => $item ? (string) $item['name'] : 'that', 'price' => $item ? (int) $item['price'] : (int) ($allowed[0] ?? 0), 'said' => (int) $mn['n']]];
        }
    }
    return ['verdict' => 'true', 'hit' => (string) $mentions[0]['said'], 'fact' => 'a listed price', 'mkt' => []];
}

// ================================================================== the directive, the hide policy, the net
function lrgMktRequestBlock(array $t): string
{
    $b = (array) ($t['buy'] ?? []);
    $s = (string) ($b['state'] ?? '');
    if ($s === '' || $s === 'no-stock' || empty($t['speech'])) { return ''; }
    $npc = (string) ($t['npc'] ?? '') ?: 'she';
    $player = trim((string) ($GLOBALS['PLAYER_NAME'] ?? '')) ?: 'the player';
    $end = ' Never ignore it.</player_request>';
    $head = "<player_request>This is an ORDER of food or drink, not intimacy: nothing said above about intimacy applies to it. ";
    $list = static function (array $rows): string {
        return implode(', ', array_map(static fn($r) => $r['name'] . ' (' . (int) $r['price'] . ' septims)', $rows));
    };
    if ($s === 'queued') {
        $item = (array) $b['item'];
        return $head . "$player ordered " . (int) $b['n'] . ' ' . $item['name'] . ". Do it: say ONE short line handing it over for "
            . (int) $b['total'] . ' septims' . ((int) $b['n'] > 1 ? ' (' . (int) $b['price'] . ' each)' : '') . " - that price and no other number."
            . " The game itself takes the coin and hands it over right after your line: choose no action, name no other goods,"
            . ' and never say it is done before your line is out.' . $end;
    }
    if ($s === 'ask') {
        $rows = (array) ($b['list'] ?? []);
        $what = (string) ($b['cls'] ?? '') !== '' ? 'something to ' . ((string) $b['cls'] === 'food' ? 'eat' : 'drink') : 'something';
        if (count($rows) === 1) {
            // [review] an availability question named the thing itself: offer it, never say he failed to name it
            $asked = str_starts_with((string) ($b['why'] ?? ''), 'an availability question')
                ? 'asked whether you have ' . (string) ($rows[0]['name'] ?? 'it') : "asked for $what and did not name it";
            return $head . "$player $asked. Offer the one thing you have for it - " . $list($rows)
                . ' - in ONE short line, that price and no other, and ask whether he wants it. Sell nothing yet and choose no action.' . $end;
        }
        return $head . "$player asked for $what and did not say which. Name what you have from this list with the prices - " . $list($rows)
            . ' - nothing else and no other number, and ask which. Sell nothing yet and choose no action.' . $end;
    }
    if ($s === 'quote') {
        $rows = (array) ($b['list'] ?? []);
        return $head . "$player asked what it costs. Answer with the listed price only - " . ($rows ? $list($rows) : 'what the list says')
            . ' - in ONE short line; sell nothing and choose no action.' . $end;
    }
    if ($s === 'unaffordable') {
        $item = (array) $b['item'];
        return $head . "$player ordered " . (int) $b['n'] . ' ' . $item['name'] . ' at ' . (int) $b['total'] . " septims, and $player carries only "
            . (int) $b['pg'] . " septims. Say plainly in ONE short line that $player cannot pay " . (int) $b['total'] . ' septims with the '
            . (int) $b['pg'] . ' he carries; sell nothing and choose no action.' . $end;
    }
    if ($s === 'pending') {
        return $head . "The last order is still being handed over. Say so in ONE short line and sell nothing more this turn; choose no action." . $end;
    }
    if ($s === 'not-vendor') {
        return $head . "$player ordered food or drink, and $npc sells nothing. Say so plainly in ONE short line, in $npc's own voice; name no price and choose no action." . $end;
    }
    return '';
}

/** Codes this module hides for the turn: Give_Item_To on every buy turn (it can never hand over stock), the barter window on a queued one. */
function lrgMktHide(array $t): array
{
    $s = lrgMktState($t);
    if (!in_array($s, ['queued', 'ask', 'quote', 'unaffordable', 'pending'], true)) { return []; }
    $hide = ['GiveItemTo'];
    if ($s === 'queued') { $hide[] = 'OpenInventory'; $hide[] = 'OpenInventory2'; }
    return $hide;
}

/**
 * THE NET (lrgDlgPostProcessActions, after the barter net, before the corner hint): a queued plan becomes ONE
 * `<npc>|command|ExtCmdLRG_Buy@<param>` line after the model's own lines - unless the reply already chose a trade / hand-over
 * action from this NPC, or the truth gate flagged a price she invented in this same reply (then nothing is sold under a
 * wrong number: NEVER FALSE). On an ask turn it verifies the glue's own single-item offer against her line.
 */
function lrgMktNet(array $t, array $out): array
{
    $b = (array) ($t['buy'] ?? []);
    $s = (string) ($b['state'] ?? '');
    $npc = (string) ($t['npc'] ?? '');
    $cid = (string) ($t['cid'] ?? '');
    if ($npc === '' || empty($t['speech'])) { return $out; }
    $reply = (string) ($GLOBALS['LAST_LLM_RESPONSE']['message'] ?? '');
    if ($s === 'ask' && count((array) ($b['list'] ?? [])) === 1) {
        $offer = (array) (lrgMemGet($npc)['buyask'] ?? []);
        if ($offer && (string) ($offer['cid'] ?? '') === $cid) {
            $said = $reply !== '' && lrgMktNamesRow($reply, (array) $b['list'][0]);
            if ($said) { lrgMemSet($npc, ['buyask' => ['said' => 1] + $offer]); }
            else { lrgMemSet($npc, ['buyask' => null]); lrgMktLog('buy ask npc=' . $npc . ' her line did not offer ' . $b['list'][0]['name'] . ' - no offer is left open', $cid); }
        }
        return $out;
    }
    if ($s !== 'queued' || trim((string) ($b['param'] ?? '')) === '') { return $out; }
    if (!empty($GLOBALS['LRG_MKT_SENT'][$cid])) { return $out; }
    foreach ($out as $line) {
        $parts = explode('|', rtrim((string) $line, "\r\n"), 3);
        if (count($parts) < 3 || strcasecmp(trim((string) $parts[0]), $npc) !== 0) { continue; }
        $raw = trim((string) explode('@', (string) $parts[2], 2)[0]);
        $code = str_replace(['_', ' '], '', strtolower($raw));
        if (in_array($code, ['openinventory', 'openinventory2', 'tradeitems', 'giveitemto', 'giveitemtoplayer', 'extcmdlrgbuy'], true)
            || (defined('LRG_DLG_GATE_NAMES') && in_array($code, LRG_DLG_GATE_NAMES, true))) {
            lrgMktLog('buy net npc=' . $npc . ' the reply already chose ' . $raw . ' - nothing appended', $cid);
            return $out;
        }
    }
    $claim = $GLOBALS['LRG_DLG_TRUTH_CLAIM'] ?? null;
    if (is_array($claim) && (string) ($claim['claim'] ?? '') === 'price') {
        lrgMktLog('buy net npc=' . $npc . ' WITHHELD: she quoted ' . (string) ($claim['said'] ?? '?') . ' and the list says ' . (string) ($claim['fact'] ?? '?') . ' - nothing is sold under a wrong number', $cid);
        return $out;
    }
    $GLOBALS['LRG_MKT_SENT'][$cid] = 1;
    $out = array_values($out);
    $out[] = $npc . '|command|' . LRG_ACT_BUY . '@' . (string) $b['param'] . "\r\n";
    $item = (array) $b['item'];
    $rec = ['id' => (string) $item['id'], 'name' => (string) $item['name'], 'n' => (int) $b['n'], 'price' => (int) $b['price'],
        'total' => (int) $b['total'], 'at' => lrgNow(), 'cid' => $cid, 'x' => (string) ($b['x'] ?? ''), 'done' => 0];
    lrgMemSet($npc, ['buyexec' => $rec, 'buyask' => null]);
    if (function_exists('lrgNoteSent')) { lrgNoteSent($t, LRG_ACT_BUY, ['do' => 'buy'], 'speech', $cid); }
    lrgMktLog(sprintf('buy net npc=%s %s x%d @%d appended (D1 only) x=%s', $npc, $item['name'], (int) $b['n'], (int) $b['price'], (string) ($b['x'] ?? '')), $cid);
    return $out;
}

/** Does her line name this row (a distinctive word of its name, or the whole name)? */
function lrgMktNamesRow(string $reply, array $row): bool
{
    $hay = lrgMktTokens(lrgMktNorm($reply));
    $tk = (array) ($row['tokens'] ?? lrgMktTokens(lrgMktNorm((string) ($row['name'] ?? ''))));
    if (!$tk || !$hay) { return false; }
    if (lrgMktRunAt($hay, $tk) >= 0) { return true; }
    $generic = array_map('strval', (array) lrgMktCfg('generic', []));
    foreach ($tk as $w) {
        $s = lrgMktSingular($w);
        if (strlen($s) < 4 || in_array($s, $generic, true)) { continue; }
        foreach ($hay as $h) { if (lrgMktSingular($h) === $s) { return true; } }
    }
    // a one-word name that is also a class word ("Ale", "Bread"): the word itself
    if (count($tk) === 1) { foreach ($hay as $h) { if (lrgMktSingular($h) === lrgMktSingular($tk[0])) { return true; } } }
    return false;
}

// ================================================================== the funcret
/**
 * The verdict on an ExtCmdLRG_Buy result (lrgFuncretVerdict): OK -> the cached row takes the game's unit= / stock=,
 * buyexec is done, `bought` is remembered, 'handled' (quiet: her line already said it and the vanilla "<item> added"
 * message is the receipt). Error -> buyexec is cleared, the cached price takes unit= when the game sent one (so a
 * re-ask quotes the live number), null = the ordinary voicing (lrgVoicedWhy carries the wordings).
 */
function lrgMktResult(array $r): ?string
{
    $npc = (string) ($r['npc'] ?? '');
    $cid = (string) ($r['cid'] ?? '');
    $kv = (array) ($r['kv'] ?? []);
    if ($npc === '') { return null; }
    $id = strtoupper(trim((string) ($kv['item'] ?? '')));
    $mem = lrgMemGet($npc);
    $adj = (array) ($mem['buyadj'] ?? []);
    $unit = isset($kv['unit']) && preg_match('/^\d{1,6}$/', (string) $kv['unit']) ? (int) $kv['unit'] : 0;
    if (empty($r['ok'])) {
        $patch = ['buyexec' => null];
        if ($id !== '' && $unit > 0) {
            $adj[$id] = ['price' => $unit, 'at' => lrgNow()] + (array) ($adj[$id] ?? []);
            $patch['buyadj'] = array_slice($adj, -16, null, true);
            // [review] "it is 21 septims now, not 19 - say the word and it is yours": the re-quote IS the glue's own one-item offer
            // at the live price, so a bare "yes" within offer_seconds re-orders it through lrgMktPlan's confirm path (the cached
            // row now carries unit=, and the game dropped its stock cache). Without it the player's "yes" was nothing at all.
            if (preg_match('/^the price is \d+ septims, not \d+/i', (string) ($r['reason'] ?? ''))) {
                $patch['buyask'] = ['id' => $id, 'name' => (string) ($kv['name'] ?? $id), 'price' => $unit, 'n' => max(1, (int) ($kv['n'] ?? 1)),
                    'at' => lrgNow(), 'cid' => $cid, 'said' => 1];
            }
        }
        lrgMemSet($npc, $patch);
        lrgMktLog('buy result npc=' . $npc . ' REFUSED ' . (string) ($kv['name'] ?? $id) . ' - ' . (string) ($r['reason'] ?? '?') . ($unit > 0 ? ' (live unit ' . $unit . ')' : ''), $cid);
        return null;
    }
    $paid = (int) ($kv['paid'] ?? 0);
    $left = isset($kv['left']) ? (int) $kv['left'] : null;
    $stock = isset($kv['stock']) && preg_match('/^\d{1,6}$/', (string) $kv['stock']) ? (int) $kv['stock'] : null;
    $exec = (array) ($mem['buyexec'] ?? []);
    if ($id !== '') {
        $a = (array) ($adj[$id] ?? []);
        if ($stock !== null) { $a['count'] = $stock; }
        if ($unit > 0) { $a['price'] = $unit; }
        $a['at'] = lrgNow();
        $adj[$id] = $a;
    }
    $ring = array_values(array_filter((array) ($mem['bought'] ?? []), static fn($e) => is_array($e) && lrgNow() - (int) ($e['at'] ?? 0) <= 3600));
    $ring[] = ['name' => (string) ($kv['name'] ?? $id), 'n' => (int) ($kv['n'] ?? 1), 'paid' => $paid, 'at' => lrgNow(), 'cid' => $cid];
    lrgMemSet($npc, ['buyexec' => $exec ? ['done' => 1] + $exec : null, 'buyadj' => array_slice($adj, -16, null, true), 'bought' => array_slice($ring, -8)]);
    lrgMktLog(sprintf('buy result npc=%s OK %s x%s paid=%d left=%s stock=%s - quiet (her line said it; the "added" message is the receipt)',
        $npc, (string) ($kv['name'] ?? $id), (string) ($kv['n'] ?? '1'), $paid, $left === null ? '-' : (string) $left, $stock === null ? '-' : (string) $stock), $cid);
    return 'handled';
}

/** The game's technical refusal reasons of CmdBuy in her words; '' = not one of ours (the ordinary map applies). */
function lrgMktVoicedWhy(string $reason, string $npc, string $player): string
{
    $r = trim($reason);
    $she = $npc !== '' ? $npc : 'she';
    if (preg_match('/^the price is (\d+) septims, not (\d+)/i', $r, $m)) { return 'it is ' . (int) $m[1] . ' septims now, not ' . (int) $m[2] . ' - say the word and it is his'; }
    if (preg_match('/^not enough gold \(has (\d+), needs (\d+)\)/i', $r, $m)) { return "$player cannot pay " . (int) $m[2] . ' septims with the ' . (int) $m[1] . ' he carries'; }
    if (preg_match('/^(?:she|he|they) (?:is|are) out of (.+?) \(has (\d+), wants (\d+)\)/i', $r, $m)) { return "$she has only " . (int) $m[2] . ' of that left'; }
    if (preg_match('/^(?:she|he|they) (?:is|are) out of /i', $r)) { return "$she has none of that left to sell"; }
    if (preg_match('/^(?:she|he|they) (?:does|do) not sell |^no vendor faction|^vendor faction (?:is )?not-sell-buy/i', $r)) { return "$she sells nothing of the kind"; }
    if (preg_match('/^closed \(vendor hours/i', $r)) { return "$she is not serving at this hour"; }
    if (preg_match('/^unknown (?:buy request|item form)/i', $r)) { return "$she did not catch what $player asked for"; }
    if (preg_match('/^quiet mode:/i', $r)) { return "$she is in the middle of something she cannot leave"; }
    if ($r === 'hostile') { return "$she is hostile to $player"; }
    if ($r === 'an arrest') { return "$she is dealing with $player as a lawbreaker"; }
    if ($r === 'a scene with you') { return 'the two of them are in the middle of something together'; }
    return '';
}

// ================================================================== the room
/**
 * CHIM's RentRoom row charges its own `cost_gold` (Action Editor > RentRoom > Gold Cost, default 10) while the game's
 * RoomCost global is what every real "rent a room (N gold)" line charges (25 here, LoreRim - Economy Overhaul). Once per
 * game session: a log line naming both (warn_rentroom_cost); with align_rentroom_cost the row is written from the live
 * global through CHIM's own config API (an owner-editable CHIM row - shipped ON since the pt19 ship: the owner asked for a
 * dearer room; false keeps the owner's own Gold Cost).
 */
function lrgMktRoomCheck(int $room, string $npc, string $sess): void
{
    if ($room <= 0 || !function_exists('herikaActionCatalogGetCustomConfigValue')) { return; }
    if (empty(lrgMktCfg('warn_rentroom_cost', true)) && empty(lrgMktCfg('align_rentroom_cost', false))) { return; }
    $mem = lrgMemGet('*market*');
    $seen = (array) ($mem['room_check'] ?? []);
    if ($seen && (string) ($seen['sess'] ?? '') === $sess && lrgNow() - (int) ($seen['at'] ?? 0) <= 6 * 3600) { return; }
    try { $cost = (int) herikaActionCatalogGetCustomConfigValue('RentRoom', 'cost_gold', 10); } catch (Throwable $e) { return; }
    lrgMemSet('*market*', ['room_check' => ['sess' => $sess, 'at' => lrgNow(), 'room' => $room, 'chim' => $cost]]);
    if ($cost === $room) { return; }
    if (!empty(lrgMktCfg('align_rentroom_cost', false)) && function_exists('herikaActionCatalogUpsertCustomConfigValue')) {
        try {
            herikaActionCatalogUpsertCustomConfigValue('RentRoom', 'cost_gold', $room);
            if (function_exists('herikaActionCatalogResetCache')) { herikaActionCatalogResetCache(); }
            lrgMktLog("inn: CHIM RentRoom cost_gold=$cost set to the game's RoomCost=$room (dialogue.market.align_rentroom_cost) - seen at $npc");
            return;
        } catch (Throwable $e) {
            lrgMktLog('inn: could not align CHIM RentRoom cost_gold: ' . $e->getMessage());
        }
    }
    lrgMktLog("inn: CHIM RentRoom cost_gold=$cost differs from the game's RoomCost=$room (seen at $npc) - set Gold Cost in CHIM's Action Editor (RentRoom), or dialogue.market.align_rentroom_cost");
}
