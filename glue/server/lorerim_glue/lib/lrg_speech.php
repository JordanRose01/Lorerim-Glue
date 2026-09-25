<?php
/**
 * LoreRim Glue - the three features the owner added on top of Phase 2 (V04_BUILD_PLAN sections 6, 7, 8):
 *
 *  1. FREE-CONVERSATION SPEECH CHECKS (owner addenda 5b / 7). When the player tries to persuade,
 *     intimidate, bribe or DECEIVE an NPC about anything with no engine entry behind it, the glue runs a
 *     REAL check using THIS load order's own rules - the live Speech* globals as LoreRim / Requiem set
 *     them, the perks the game confirms, the Amulet of Articulation, and for intimidation the ENGINE's own
 *     verdict (WillIntimidateSucceed). The LLM judges only WHETHER the player attempted; the outcome is
 *     handed to it as FACT afterwards. Effects are Speech XP + affinity + memory only:
 *     never SetBribed / SetIntimidated / SetCrimeGold and never a quest stage.
 *     The census in lib/lrg_prompt_index.php is the reason this exists: this load order has exactly three
 *     ENGINE check kinds (persuade / intimidate / bribe) and NO mod adds a deceive, charm or seduce check -
 *     so the deception the owner wants can only be the free-conversation check.
 *
 *  2. QUEST-TREE AWARENESS (owner addendum 4). One prompt block built from the game's q= / qobj= and
 *     CHIM's own questlog. Current objective only, never a future stage, nothing from
 *     skyrim_quest_definitions (its npc_facts are later beats = spoilers).
 *
 *  3. "EVERYONE HAS A PRICE" (owner addendum 6) IS NOT IN THIS FILE. The one implementation is
 *     lrgPriceFor() in lib/lrg_core.php, which is the one the live turn calls (lrg_core.php:1137) and the
 *     one that honours the MCM `paidok` / `pm` keys. This file used to carry a second, never-called
 *     lrgDlgPrice() with its own private config namespace (day_wage vs gold_per_day_of_wage, band_by_wealth
 *     vs bands_by_wealth, money influence DIVIDING instead of MULTIPLYING) - a seam that could only drift.
 *     It was deleted in the 0.4.0 fix pass; PROTOCOL 10.7 / 10.9 now name lrgPriceFor().
 *     All that is left here is the WAGE ANCHOR, shared with a free-conversation bribe's price, and it
 *     reads the live config: [pt15 / addenda 12e-f] HER own day wage (lrgWageFor: paid_intimacy.wage_tiers
 *     x hours_per_day), the same one her price bands are counted in.
 */

if (defined('LRG_SPEECH_LOADED')) { return; }
define('LRG_SPEECH_LOADED', true);

require_once __DIR__ . '/lrg_core.php';
require_once __DIR__ . '/lrg_prompt_index.php';
/**
 * [pt19h-money] THE ONE SPOKEN-SUM READER on every path, loaded where a sum is about to be read. lrg_topics (the want=1 fast
 * path: lrgDlgAnswerWant -> the engine bribe rail -> lrgDlgNamedAmount) runs with lrg_dialogue.php alone (preprocessing.php), so
 * lrgIntentAmount was never defined there and "here are two hundred septims, look away" read 0 on the fast path (the "offered
 * less than the price" rail skipped) while her key read 200. lrg_intent.php is definitions only (a guarded file); on the LLM
 * path lrg_actions.php has loaded it already. Called by the check rail (before it prices his sum) and the money fold.
 */
function lrgDlgSumReader(): bool
{
    if (!function_exists('lrgIntentAmount') && is_file(__DIR__ . '/lrg_intent.php')) { require_once __DIR__ . '/lrg_intent.php'; }
    return function_exists('lrgIntentAmount');
}

/** The five difficulty bands, in the engine's own order. The VALUES are always read live. */
const LRG_SPEECH_GLOBALS = ['SpeechVeryEasy', 'SpeechEasy', 'SpeechAverage', 'SpeechHard', 'SpeechVeryHard'];

// ================================================================== defaults
function lrgDlgCheckDefaults(): array
{
    return [
        'enabled' => true,                 // bFreeChecks - the owner asked for default ON
        'bias' => 0,                       // iCheckBias, -2..+2
        'stats' => false,                  // bCheckStats - OFF: Beneficial Speech Checks pays out on that delta
        'hostility' => false,              // bCheckHostility - OFF: a mis-heard word could get the player killed
        'min_words' => 4,
        'affinity_fail' => 2,
        'memory_seconds' => 86400,
        'deceive_band' => 1,               // a lie is one band harder than a plain persuasion
        'xp' => true,
        'bribe_floor_days' => 0.3,         // a free bribe's price: days of HER wage (paid_intimacy.wage_tiers)
        'bribe_ceiling_days' => 3.0,
        // stakes -> base difficulty band (0 very easy .. 4 very hard); words are matched case-insensitively
        'stakes' => [
            'small' => ['words' => ['drink', 'directions', 'rumour', 'rumor', 'gossip', 'chat', 'weather', 'name'], 'band' => 0],
            'price' => ['words' => ['price', 'discount', 'cheaper', 'haggle', 'deal', 'bargain', 'coin', 'cost'], 'band' => 1],
            'access' => ['words' => ['let me', 'let us', 'pass', 'through', 'inside', 'key', 'door', 'entry', 'permission'], 'band' => 2],
            'secret' => ['words' => ['tell me', 'secret', 'truth', 'who', 'where is', 'confess', 'admit', 'happened'], 'band' => 2],
            'crime' => ['words' => ['bounty', 'arrest', 'guard', 'crime', 'stole', 'murder', 'jail', 'look the other way'], 'band' => 3],
            'grave' => ['words' => ['betray', 'kill', 'war', 'jarl', 'throne', 'treaty', 'army', 'thane'], 'band' => 4],
            // [pt19 v1.0 / S6.1, S6.2] THE REWARD BARGAIN. Gate A reads it for the `reward` locked line only; gate B's window-gated
            // branch (lrgDlgCheckKind) runs the check at band 2 itself. lrgDlgStakes SKIPS this row: as an ordinary stakes row it
            // would move "I need more information" to band 2 on every free check (research/pt19c-language.md 5.2). CONTIGUOUS
            // token phrases, never a substring, compared with the apostrophes folded ("whats in it for me" is the owner's STT).
            // [pt19c-B fix 1 / game, ai, lang, use reviews] FREE SPEECH, not only index lines: "tell me a bit more about the barrow",
            // "I need more information", "there's not enough time", "I'd rather have a word with the Jarl", "let me do it instead",
            // "thanks in advance" are no bargain.
            // [pt19c-B fix 2 / game HIGH, ai 1, lang P1, use P1] ...nor "Is there anything more?", "Did the Jarl say anything
            // more?", "Could you elaborate a little more?", "The people of Whiterun deserve better.", "Your life is worth more than
            // that", "I expected more than a pile of bones", "What do I get from the barrow?", "What will I get there?", "The dragon
            // was twice as much trouble" or "I want to find the hundred gold that was stolen". So five tiers (lrgDlgRewardPhrase),
            // each read inside ONE clause of his (split at . ! ? ; : , ... and "but"; a comma or point between digits is no split):
            //  words        a bargain over the reward on its own;
            //  first_person only after HIS subject - I / I'm / I've / I'll / I'd / we, with nothing between but fillers ("I really",
            //               "I think I", "I was"), never in a relative clause - and then only with a `money` word elsewhere in the
            //               clause, or ENDING the clause (nothing after it, or exactly one of `tails`): "I deserve better", "I'll need
            //               more than that", "I was hoping for more"; never "the people of Whiterun deserve better", "I need more than
            //               that to go on", "your life is worth more than that", "there is nothing I want more";
            //  ends         only ending the clause, before exactly one of `tails`, before one of `leads` ("what do I get for killing
            //               the dragon"), or with a `money` word elsewhere in the clause; never before to / from / at / there ("what
            //               do I get to do next", "any extra supplies", "anything on top of the mountain");
            //  weak         only with a `money` word elsewhere in the clause, or ENDING the clause with no `not_with` phrase in it -
            //               and at the bare end of a QUESTION ("could you ... a little more?", "is there ...?") only when the phrase
            //               is on `in_question` ("can we negotiate?"); "how about / what about / why not ..." is a proposal, not a
            //               question ("how about a little more?" is a bargain);
            //  with_money   only with a `money` word elsewhere in the clause ("I'd rather have the gold", "anything more in septims");
            // and a named sum only on a turn with no live price, in ONE clause with a `sum_frames` phrase right beside it: the frame
            // before the sum with at most three fillers between ("make it two hundred septims", "not for less than 300 gold"), or -
            // for a frame that does not start with "I" - after it ("a hundred septims on top", "two hundred septims and it's a deal");
            // never "I want to find the hundred gold that was stolen" or "I found fifty gold on the bandit".
            'reward' => ['band' => 2,
                'words' => ['more gold', 'more septims', 'more coin', 'more coins', 'more money', 'more pay', 'pay me more', 'pay more',
                    'pay me extra', 'pay extra', 'pay me better', 'deserve a reward', 'worth my while', 'worth to you', 'extra gold',
                    'extra septims', 'extra coin', 'extra money', 'a bonus', 'bonus', 'sweeten', 'double it', 'double that', 'in septims',
                    'in gold', 'in coin', 'all i get', 'in it for me', 'a reward', 'the reward', 'my reward', 'reward me', 'better reward',
                    'bigger reward', 'different reward', 'will i be paid', 'do i get paid', 'am i getting paid', 'how much does it pay',
                    'what does it pay', "what's the pay", 'are you paying', 'will you pay', 'how much will you pay', 'gold instead',
                    'septims instead', 'coin instead', 'money instead', 'house instead', 'horse instead', 'reward instead', 'title instead',
                    'want a title', 'give me a title', 'make me a thane', 'make me thane', 'haggle', 'half now', 'half up front',
                    'half in advance', 'pay up front', 'paid up front', 'pay in advance', 'paid in advance', "what i'm owed",
                    'what i am owed', 'what you owe me', 'work for free', 'do it for free', 'do this for free'],
                'first_person' => ['deserve more', 'deserve better', 'deserve a bigger', 'worth more', 'worth a lot more', 'want more',
                    'need more', 'want more than that', 'need more than that', 'expected more', 'expect more', 'expected more than',
                    'expect more than', 'expecting more', 'expected something more', 'expecting something more', 'hoping for more',
                    'hoped for more', 'hoping for something more', 'hoped for something more', 'ask for more', 'asking for more', 'want extra',
                    'want twice as much'],
                'ends' => ['what do i get', 'what will i get', 'what would i get', 'something extra', 'a little extra', 'a bit extra',
                    'anything extra', 'any extra', 'something on top', 'a little on top', 'a bit on top', 'anything on top', 'extra on top'],
                'weak' => ['a bit more', 'a little more', 'not enough', 'negotiate', 'for free', 'pay me', 'raise it', 'twice that',
                    'give me extra'],
                'in_question' => ['pay me', 'negotiate', 'raise it', 'twice that', 'give me extra'],
                'with_money' => ['up front', 'in advance', 'in return', 'in exchange', 'double the', 'double my', 'raise the', 'rather have',
                    'on top of that', 'on top of it', 'instead', 'more than that', 'more than this', 'is that all', 'anything more',
                    'something more', 'twice as much'],
                'money' => ['gold', 'septim', 'septims', 'septum', 'septums', 'septem', 'septems', 'coin', 'coins', 'money', 'pay', 'paid',
                    'payment', 'reward', 'rewards', 'bonus', 'purse', 'fee', 'wage', 'wages', 'price', 'compensation'],
                // an information verb (or "nothing", "agreed") anywhere in the clause makes a clause-end `weak` phrase no bargain:
                // "let me think about it a bit more", "can you tell me a bit more?", "say a little more", "could you go over it a bit
                // more", "can you describe it a bit more?", "Letrush already agreed to pay me". Contiguous phrases.
                'not_with' => ['think', 'wait', 'rest', 'sleep', 'talk', 'speak', 'look', 'read', 'learn', 'know', 'tell', 'explain', 'hear',
                    'show', 'practice', 'practise', 'train', 'study', 'nothing', 'agreed', 'say', 'describe', 'elaborate', 'detail',
                    'details', 'go over', 'add', 'clarify', 'expand'],
                'tails' => ['for this', 'for that', 'for it', 'for the job', 'for the work', 'for the task', 'for the risk',
                    'for the trouble', 'for my trouble', 'for me', 'on top', 'than that', 'than this', 'please', 'then', 'this time',
                    'first', 'now', 'up front', 'in advance', 'from you', 'out of this', 'out of it'],
                'leads' => ['for', 'if', 'once', 'when', 'in return', 'in exchange', 'out of'],
                'sum_frames' => ['make it', 'i want', "i'd want", 'i need', 'i expect', "i'd expect", "i'm asking", 'i am asking', 'i ask',
                    'pay me', 'give me', 'do it for', 'do this for', 'do that for', "i'll do it", 'i will do it', 'not for less',
                    'for no less', 'for at least', 'at least', 'on top', 'how about', 'in exchange', 'in return', 'instead',
                    "i'll take", 'i will take', "i'd take", 'septims more', 'septim more', 'gold more', 'coins more', 'coin more', 'deal',
                    'extra', 'bonus']],
        ],
        // [pt19 v1.0 / S6.2] THE BOUNDED BONUS - gate B (v1.0.1). Off in gate A: nothing below runs until `enabled` is true.
        // window_seconds: how long after a reward line / a moved-on quest of hers / gold into his purse the window stays open;
        // max_gold: the hard cap of one bonus, in septims (also capped by her purse and one day of her wage).
        'reward' => ['enabled' => false, 'window_seconds' => 180, 'max_gold' => 500],
        // stance: added to the base band. A guard is not immune but gets the hardest band.
        'stance' => ['guard' => 2, 'merchant' => 1, 'hostile' => 2, 'jarl' => 2, 'court' => 1, 'vigilant' => 2,
            'priest_mara' => 1, 'beggar' => -1, 'tavern_folk' => -1, 'commoner' => 0, 'follower' => -2],
        'affinity_bands' => [-60 => 2, -20 => 1, 20 => 0, 60 => -1, 101 => -2],
        'immune' => ['jarl', 'court', 'vigilant', 'priest_mara'],
        // Only used when the game has never sent sg= (no session was ever opened). Owner-overridable, and
        // the G6 log line says "(fallback)" so it can never be mistaken for a live read.
        'fallback_thresholds' => ['SpeechVeryEasy' => 10, 'SpeechEasy' => 25, 'SpeechAverage' => 50,
            'SpeechHard' => 75, 'SpeechVeryHard' => 100],
    ];
}

/**
 * The WAGE ANCHOR ONLY. Everything else about "everyone has a price" lives in the live config's
 * `paid_intimacy` block (config/lrg_config.default.json) and is read by lrgPriceFor() (lib/lrg_core.php).
 * These two keys are here so a free-conversation bribe still has a number when the dialogue block is all
 * that is present; lrgDlgWage() / lrgDlgPriceRound() prefer the LIVE key, so the two can never disagree.
 */
function lrgDlgPriceDefaults(): array
{
    return [
        'day_wage' => 40,                  // only if lib/lrg_core.php is absent: the owner's common wage, 5 g/h x 8
        'round_to' => 5,                   // mirrors paid_intimacy.round_to
    ];
}

/**
 * [pt15 / addenda 12e-f] HER day wage, from the same tiers as her price (lrgWageFor in lib/lrg_core.php):
 * common 40, performer 64, trade 100, fighter 132, craft/magic 200, court 500. With no profile it is the
 * default tier's day (common = 40). The old single gold_per_day_of_wage (35) is gone.
 */
function lrgDlgWage(array $profile = [], string $npc = '', array $state = []): int
{
    if (function_exists('lrgWageFor')) {
        $w = lrgWageFor($npc, $profile, $state);
        if ((float) $w['day'] > 0.0) { return max(1, (int) round((float) $w['day'])); }
    }
    return max(1, (int) lrgDlgCfg('paid_intimacy.day_wage', 40));
}

function lrgDlgPriceRound(): int
{
    $live = (int) ((lrgConfig()['paid_intimacy'] ?? [])['round_to'] ?? 0);
    return $live > 0 ? $live : max(1, (int) lrgDlgCfg('paid_intimacy.round_to', 5));
}

// ================================================================== 1. free-conversation checks
/**
 * The check itself. Returns null when nothing is attempted or a precondition fails; otherwise
 *   ['kind','result' => pass|fail,'why','diff','global','threshold','sp','wis','pg','N','stakes','stance',
 *    'bias','mem','award' => bool,'xp','stat','take','log']
 * Preconditions, ALL required (plan 6.10): bFreeChecks on; NO engine entry of that kind listed this turn
 * (an engine check always wins); the recognised intent kind is persuade|intimidate|bribe|deceive with
 * conf=high; >= attempt.min_words words on voice input; not under kill switch / dry-run / a silent turn;
 * the NPC is a person with a fresh snapshot.
 */
function lrgDlgCheck(array $turn, array $intent): ?array
{
    $npc = (string) $turn['npc'];
    // bFreeChecks, from the GAME when it sends it (PROTOCOL 10.10), else this file's default. It used to
    // be honoured game-side ONLY, so with the toggle off the server still ran the check, still told the
    // LLM the outcome as fact and still emitted do=award for the game to throw away.
    if (!lrgDlgMcm('free_checks', !empty(lrgDlgCfg('checks.enabled')) ? 1 : 0, $npc)) { return null; }
    $kind = lrgDlgCheckKind($intent, (string) ($turn['snap']['_utter'] ?? ''), $turn);
    if ($kind === '') { return null; }
    $st = lrgDlgGet($npc);
    $utter = trim((string) ($st['utter']['text'] ?? ''));
    if ($utter === '') { return null; }
    $words = count(preg_split('/\s+/', $utter) ?: []);
    if ($words < (int) lrgDlgCfg('checks.min_words', 4)) {
        lrgDlgLog('check npc=' . $npc . ' kind=' . $kind . ' skipped: only ' . $words . ' words', (string) $turn['cid']);
        return null;
    }
    // an ENGINE entry of the same kind is listed -> the engine always wins
    foreach (array_merge((array) $turn['entries'], (array) $turn['tail']) as $e) {
        $ek = (string) $e['kind'];
        // deceive and barter are persuade-TYPE: a real persuade entry on the list wins over either
        if ($ek !== '' && ($ek === $kind
            || (in_array($kind, ['deceive', 'barter'], true) && $ek === 'persuade'))) {
            lrgDlgLog('check npc=' . $npc . ' kind=' . $kind . ' skipped: a real ' . $ek
                . ' entry is listed - the engine decides', (string) $turn['cid']);
            return null;
        }
    }
    $profile = lrgDlgProfileOf($turn);
    $pid = strtolower((string) ($profile['id'] ?? ''));
    $facts = (array) $turn['facts'];
    $sp = (int) ($facts['sp'] ?? ($turn['snap']['pspeech'] ?? 0));
    $spSrc = isset($facts['sp']) ? 'live' : 'snapshot';
    $lvl = (int) ($facts['lvl'] ?? ($turn['snap']['plvl'] ?? 0));
    $wis = array_key_exists('wis', $facts) ? (int) $facts['wis'] : -1;
    $pg = (int) ($facts['pg'] ?? ($turn['snap']['pgold'] ?? 0));
    $perks = array_map('strtolower', (array) ($facts['perk'] ?? []));
    // ---- difficulty, from the stakes and her stance. Never from the LLM, never shown to anyone. ------
    [$stakesWord, $base] = lrgDlgStakes($utter);
    // [pt19 v1.0 / S6.2, gate B] THE BOUNDED BONUS: a persuasion taken up as a reward bargain (inside an open window only,
    // lrgDlgRewardBargain) runs at stakes `reward` (band 2); she adds at most lrgDlgRewardAmount, once per quest per NPC. An
    // item, a house, a title or a favour, a second bonus and an empty purse are answered as fact, with no check at all.
    $rb = $kind === 'persuade' ? lrgDlgRewardBargain($turn, $utter) : [];
    $give = 0;
    $rkey = '';
    if ($rb) {
        [$stakesWord, $base] = ['reward', (int) lrgDlgCfg('checks.stakes.reward.band', 2)];
        $rkey = 'reward|' . strtolower((string) (array_values(array_filter((array) ($turn['q'] ?? [])))[0] ?? 'any'));
        $give = lrgDlgRewardAmount($turn, $utter);
    }
    $stance = lrgDlgStance($profile, $turn);
    $bias = (int) lrgDlgMcm('bias', (int) lrgDlgCfg('checks.bias', 0), $npc);   // iCheckBias
    $band = $base + $stance + $bias + ($kind === 'deceive' ? (int) lrgDlgCfg('checks.deceive_band', 1) : 0);
    $band = max(0, min(4, $band));
    $globalName = LRG_SPEECH_GLOBALS[$band];
    [$threshold, $thrSrc] = lrgDlgSpeechThreshold($st, $globalName);
    // ---- memory: the same trick does not work twice (owner) ------------------------------------------
    $memKey = $kind . '|' . substr(md5(lrgPromptNorm($utter)), 0, 10);
    $mem = (array) ($st['checks'][$memKey] ?? []);
    $memState = 'miss';
    $res = null;
    $why = '';
    $memAge = lrgNow() - (int) ($mem['at'] ?? 0);
    if ($mem && $memAge <= (int) lrgDlgCfg('checks.memory_seconds', 86400)) {
        $changed = $sp !== (int) ($mem['sp'] ?? -1) || $wis !== (int) ($mem['wis'] ?? -1)
            || $pg !== (int) ($mem['pg'] ?? -1) || lrgDlgAffBand($turn) !== (int) ($mem['aff'] ?? -99);
        if (!$changed) {
            $memState = 'hit';
            $res = (string) ($mem['result'] ?? 'fail');
            $why = 'she has heard this from him before and nothing about him has changed';
        }
    }
    // a deceive against an NPC who already caught one lie auto-fails until affinity moves
    if ($res === null && $kind === 'deceive' && lrgDlgCaughtLie($st, $turn)) {
        $res = 'fail';
        $memState = 'auto-fail';
        $why = 'she has already caught him in one lie';
    }
    if ($res === null && $rb) {
        // [pt19c-B fix 1 / lang review P7] only when the house / horse / title / favour IS what he asks for: "I did you a favour, I
        // deserve more septims", "I lost my horse on this job, pay me more" and "as Thane I deserve more" are septim bargains
        if (preg_match('/\b(?:(?:house|horse|title|item|favou?r|weapon|land|something else) instead|(?:a )?different reward|'
                . 'make me (?:a )?thane|(?:give me|i want|i(?:\'d| would) (?:like|prefer)|rather have|make me|can i have|could i have|'
                . 'i(?:\'ll| will) take|how about) (?:a |an |the |some |your |my )?(?:own )?(?:house|horse|title|thane|favou?r|item|'
                . 'weapon|land|something else)\b)/i', $utter)
            && !preg_match('/\b(?:gold|septims?|coins?) instead\b/i', $utter)) {
            [$res, $memState, $why] = ['fixed', 'reward', 'an item, a house, a title or a favour is never hers to add'];
        } elseif (!empty(((array) ($st['checks'] ?? []))[$rkey])) {
            [$res, $memState, $why] = ['fixed', 'reward', 'she has added what she could to this reward already'];
        } elseif ($give <= 0) {
            [$res, $memState, $why] = ['nocoin', 'reward', 'she carries no coin to add'];
        }
    }
    // ---- the rules, replicated from the engine's own -------------------------------------------------
    $N = 0;
    if ($res === null) {
        if ($kind === 'intimidate') {
            if (in_array($pid, array_map('strtolower', (array) lrgDlgCfg('checks.immune', [])), true)
                || !empty($profile['intimidate_immune'])) {
                $res = 'fail';
                $why = 'a ' . ($pid ?: 'person') . ' like her is not intimidated by anyone';
            } elseif ($wis === 0) {
                $res = 'fail';
                $why = "the engine's own verdict says she cannot be intimidated";
            } elseif ($wis === 1) {
                $res = 'pass';
                $why = "the engine's own verdict says she can be";
            } else {
                lrgDlgLog('check npc=' . $npc . ' kind=intimidate refused: the game has not sent wis= yet '
                    . '(WillIntimidateSucceed) - guessing an intimidation is exactly what this must not do',
                    (string) $turn['cid']);
                return null;
            }
        } elseif ($kind === 'bribe') {
            $N = lrgDlgBribePrice($turn, $profile, $band);
            $named = lrgDlgNamedAmount(lrgDlgMoneyFold($utter));   // [pt19c-B fix 2 / lang P8] "5 hundred gold" is 500
            // [pt19h-money / G13] a PRICE QUESTION is no offer, whatever figure rides in it ("how much - two hundred?"): she
            // names her price and nothing moves (the engine rail's rule 1, lrgDlgCheckAskRail)
            // [pt19h-money round 2 / arch P5] ...and the directive then says he ASKED, not that he offered gold with no figure
            // ("how much for you to look the other way? two hundred septims?" names 200 - "named no figure" was false)
            $pq = lrgDlgPriceQuestion($utter);
            if ($named > 0 && $pq) { $named = 0; }
            if ($named <= 0) {
                lrgDlgLog('check npc=' . $npc . ' kind=bribe: ' . ($pq ? 'he asked the price' : 'no amount named') . ' - she names her price, nothing moves',
                    (string) $turn['cid']);
                return ['kind' => 'bribe', 'result' => 'ask', 'why' => $pq ? 'he asked the price' : 'no amount was named', 'pq' => $pq ? 1 : 0, 'N' => $N,
                    'diff' => $band, 'global' => $globalName, 'threshold' => $threshold, 'sp' => $sp, 'wis' => $wis,
                    'pg' => $pg, 'stakes' => $stakesWord, 'stance' => $stance, 'bias' => $bias, 'mem' => $memState,
                    'award' => false, 'xp' => 0, 'stat' => '', 'take' => 0];
            }
            if ($named < $N) { $res = 'fail'; $why = 'he offered ' . $named . ' and she will not go below ' . $N; }
            elseif ($pg < $named) { $res = 'fail'; $why = 'he does not have the ' . $named . ' he offered'; }
            else { $res = 'pass'; $N = $named; $why = 'he offered ' . $named . ' and has it'; }
        } else {
            // persuade / deceive: the engine's own comparison, plus the amulet that passes INSTEAD of it
            if (in_array('amulet', $perks, true)) {
                $res = 'pass';
                $why = 'the Amulet of Articulation passes in place of the comparison, exactly as in dialogue';
            } else {
                $res = $sp >= $threshold ? 'pass' : 'fail';
                $why = 'his Speech against the ' . $globalName . ' band';
            }
        }
    }
    $out = ['kind' => $kind, 'result' => $res, 'why' => $why, 'diff' => $band, 'global' => $globalName,
        'threshold' => $threshold, 'thr_src' => $thrSrc, 'sp' => $sp, 'sp_src' => $spSrc, 'lvl' => $lvl,
        'wis' => $wis, 'pg' => $pg, 'N' => $N, 'stakes' => $stakesWord, 'stance' => $stance, 'bias' => $bias,
        'mem' => $memState, 'award' => false, 'xp' => 0, 'stat' => '', 'take' => 0, 'aff' => 0, 'key' => $memKey,
        'reward' => $rb ? 1 : 0, 'give' => 0];
    // ---- consequences --------------------------------------------------------------------------------
    if ($memState === 'miss') {
        $checks = (array) ($st['checks'] ?? []);
        $checks[$memKey] = ['kind' => $kind, 'result' => $res, 'at' => lrgNow(), 'sp' => $sp, 'wis' => $wis,
            'pg' => $pg, 'aff' => lrgDlgAffBand($turn), 'tries' => (int) ($mem['tries'] ?? 0) + 1];
        if (count($checks) > 24) { $checks = array_slice($checks, -24, null, true); }
        lrgDlgSet($npc, ['checks' => $checks]);
    }
    ///[0.5.1 pt9 go-live / S8] A REMEMBERED PASS PAYS NOTHING. The result was recorded in $st['checks']
    // above but award was still set on EVERY pass, so one passing sentence granted Speech XP for ever -
    // repeat it and the XP keeps coming - and on a memory hit $N stays 0, so a repeated bribe kept the
    // verdict 'pass' while take= collapsed to zero: the same guard bribed free from the second attempt
    // onward. The verdict itself still stands for the narration ("she has heard this from him before and
    // nothing about him has changed") - it just no longer moves gold, XP or a stat a second time. A
    // remembered pass falls through BOTH branches below, which is the intended outcome: nothing happens.
    if ($res === 'pass' && $memState === 'miss') {
        $out['award'] = true;
        $out['xp'] = !empty(lrgDlgCfg('checks.xp')) ? 1 : 0;
        $out['stat'] = !empty(lrgDlgCfg('checks.stats'))
            ? (['persuade' => 'Persuasions', 'deceive' => 'Persuasions', 'barter' => 'Persuasions',
                'bribe' => 'Bribes', 'intimidate' => 'Intimidations'][$kind] ?? '') : '';
        $out['take'] = $kind === 'bribe' ? (int) $N : 0;
        if ($rb) {
            // [S6.2 (5), gate B] do=award give=<n>: the game moves min(n, her septims) and answers "OK: gave <n> septims"
            $out['give'] = $give;
            $ck = (array) (lrgDlgGet($npc)['checks'] ?? []);
            $ck[$rkey] = ['kind' => 'reward', 'result' => 'pass', 'give' => $give, 'at' => lrgNow()];
            lrgDlgSet($npc, ['checks' => $ck]);
            // [pt19c-B fix 1 / CHIM review, brief fact 7 / P5] the bonus is stated pre-LLM (<reward_talk>), so it MOVES pre-LLM too:
            // the award's D2 row is queued now, and the post-gate's line reuses its x (the driver de-dups D1 / D2 by x). A reply cut
            // short by his next sentence (no post-gate runs) can no longer leave the bonus promised and not one septim moved.
            if (function_exists('lrgDlgAwardLine')) {
                $pre = lrgDlgAwardLine(['npc' => $npc, 'cid' => (string) ($turn['cid'] ?? ''), 'check' => $out]);
                if (preg_match('/;x=([0-9a-f]+);/', $pre, $mx)) { $out['award_x'] = $mx[1]; }
            }
        }
    } elseif ($memState === 'miss' && function_exists('lrgAdjustAffinity')) {
        $d = (int) lrgDlgCfg('checks.affinity_fail', 2);
        if ($d > 0) {
            try { lrgAdjustAffinity($npc, -$d, 'check failed'); $out['aff'] = -$d; }
            catch (Throwable $e) { lrgDlgLog('affinity adjust failed: ' . $e->getMessage()); }
        }
        // bCheckHostility - the one consequence that can get the player killed over a mis-heard word
        if ($kind === 'intimidate'
            && lrgDlgMcm('hostility', !empty(lrgDlgCfg('checks.hostility')) ? 1 : 0, $npc)) { $out['hostility'] = 1; }
    }
    lrgDlgLog(lrgDlgCheckLogLine($npc, 'free', $out), (string) $turn['cid']);
    return $out;
}

/** The one greppable line per attempt (plan 6.12). The owner must be able to answer "why" from it alone. */
function lrgDlgCheckLogLine(string $npc, string $where, array $c): string
{
    return sprintf('check npc=%s where=%s kind=%s diff=%d/%s=%s%s sp=%d(%s) wis=%s pg=%d N=%d stakes=%s'
        . ' stance=%d bias=%d mem=%s res=%s why=%s aff=%d xp=%d gold=%d',
        $npc, $where, (string) $c['kind'], (int) $c['diff'], (string) $c['global'], (string) $c['threshold'],
        (string) ($c['thr_src'] ?? '') === 'fallback' ? '(fallback)' : '', (int) $c['sp'],
        (string) ($c['sp_src'] ?? '?'), (int) $c['wis'] < 0 ? '-' : (string) (int) $c['wis'], (int) $c['pg'],
        (int) $c['N'], (string) $c['stakes'], (int) $c['stance'], (int) $c['bias'], (string) $c['mem'],
        (string) $c['result'], (string) $c['why'], (int) ($c['aff'] ?? 0), (int) ($c['xp'] ?? 0),
        (int) ($c['take'] ?? 0));
}

/**
 * Which kind was attempted. The intent recogniser of Phase 1 answers first (it is another lane's file, so
 * this is a read-only call); a plain word test is the fallback so the feature does not depend on a kind
 * that lane may not have added.
 *
 * [0.4.0 fix pass] Two of the owner's own three examples from addendum 5 produced NO CHECK AT ALL with the
 * first version of these patterns (the merchant haggle and the lie to a guard), and recall on 18 natural
 * phrasings was 6/18. Three things were wrong and are fixed here:
 *  1. THE NEGATION BLOCKER WAS BACKWARDS FOR THIS FEATURE. Phase 1's blocker exists because "don't get
 *     naked yet" is not a request. But a LIE is usually a negative statement - "That bounty is not mine",
 *     "I have never set foot in Riften" - and a threat often is too ("You do not want me as an enemy").
 *     Blocking every `never` / `do not` threw away exactly the sentences this feature is for. Only the
 *     HYPOTHETICAL framings ("should I ...", "what if I ...") and a quoted utterance are refused now.
 *  2. THERE WAS NO HAGGLING KIND, so "knock the price down" matched nothing. `barter` is new and is a
 *     persuade-type check (the engine has no barter check of its own - the builder's census found exactly
 *     three engine kinds in this load order - so this can only ever be a free-conversation check).
 *  3. THE BRIBE PATTERN WANTED DIGITS, so "Here is two hundred septims" missed. It now reuses
 *     lrgIntentAmount(), which understands spelled-out sums.
 * ORDER MATTERS: intimidate, then barter, then bribe (both mention coin - haggling over a PRICE is not a
 * bribe), then deceive, then persuade as the widest net.
 */
function lrgDlgCheckKind(array $intent, string $unusedUtter, array $turn): string
{
    $k = strtolower((string) ($intent['kind'] ?? ''));
    $conf = strtolower((string) ($intent['conf'] ?? ''));
    // The forward-compatible seam. lrgRecogniseIntent() does not return any of these today (its money
    // kinds are offer / askprice / haggle, which belong to "everyone has a price", NOT to a check - see
    // the bribe branch below); the map is kept so the day that lane adds one, this file already reads it.
    $map = ['persuade' => 'persuade', 'persuasion' => 'persuade', 'intimidate' => 'intimidate',
        'threaten' => 'intimidate', 'bribe' => 'bribe', 'pay_offer' => 'bribe', 'deceive' => 'deceive',
        'lie' => 'deceive', 'barter' => 'barter'];
    if (isset($map[$k]) && $conf === 'high') { return $map[$k]; }
    // A money turn that is about HER is "everyone has a price" and must never also run a bribe check.
    $moneyTurn = $conf === 'high' && in_array($k, ['offer', 'askprice', 'haggle'], true);
    $st = lrgDlgGet((string) $turn['npc']);
    $u = ' ' . strtolower(trim(lrgDlgMoneyFold((string) ($st['utter']['text'] ?? '')))) . ' ';   // [pt19c-B fix 2 / lang P8] the STT sum folded
    if ($u === '  ') { return ''; }
    // a HYPOTHETICAL or a quoted utterance is not an attempt. A negative statement IS one (see 1 above).
    if (preg_match('/^\s*(?:should i|shall i|what if|if i were to|could i|would it (?:work|help) if|'
        . 'do you think i should|suppose i)\b/', ltrim($u))) { return ''; }
    if (preg_match('/["\x{201c}\x{201d}]/u', $u)) { return ''; }

    if (preg_match('/\b(?:or i(?:\'ll| will| am going to)\b|break your|hurt you|make you|regret (?:it|this)|'
        . 'last warning|last chance|do it or|i(?:\'ll| will) kill|you(?:\'ll| will) be sorry|threaten|'
        . 'don\'t (?:make|test|push) me|do not (?:make|test|push) me|cross me|'
        . 'you (?:do not|don\'t) want me as an enemy|you (?:do not|don\'t) want to (?:find out|know)|'
        . 'i(?: have|\'ve) killed (?:men|people|better)|put you in the ground|end you (?:here|now)|'
        . 'drop (?:the|your) (?:axe|sword|weapon|blade|knife)|talk\.? now|start talking|'
        . 'or (?:things|this) (?:get|gets) (?:ugly|worse)|or else)\b/', $u)) { return 'intimidate'; }

    // [pt19 v1.0 / S6.2 (2), gate B] INSIDE an open reward window - and only there - his bargaining words are a persuasion with
    // stakes `reward` (lrgDlgCheck runs it at band 2 and caps the bonus); outside the window the same words fall through the
    // ladder below as before. Inert until checks.reward.enabled (gate B).
    if (lrgDlgRewardBargain($turn, $u) !== []) { return 'persuade'; }

    // haggling over the PRICE OF SOMETHING: the merchant case of addendum 5, and never a bribe
    if (preg_match('/\b(?:knock the price down|better price|lower the price|drop the price|daylight robbery|'
        . 'too (?:much|steep|dear|expensive|rich)|not a (?:coin|septim|gold piece) more|'
        . 'best you can do|meet me (?:in the )?(?:middle|half ?way)|cut me a deal|discount|haggl\w*|'
        . 'come down (?:on|to|a bit)|for the (?:lot|whole lot)|cheaper|fair price|rip ?off)\b/', $u)) {
        // [pt19 v1.0 / language brief 5.2-5.3] on a QUEST turn with no live price, "let's haggle" is a bargain over the reward,
        // not a merchant's haggle: no free barter check against a reward that has no price - the `reward` locked line answers
        if (lrgDlgQuestTurn($turn) && !lrgDlgTurnPriced($turn)) { return ''; }
        return 'barter';
    }

    // A BRIBE IS COIN WITH A PURPOSE. The purpose phrase alone is enough and wins even on a money turn -
    // "here's two hundred septims, look the other way" is a bribe, not a proposition.
    if (preg_match('/\b(?:look the other way|look away|turn a blind eye|forget (?:my face|you saw me|this|it|about it)|'
        . 'for your (?:trouble|silence)|buy your silence|let me (?:through|pass|go|by|in)|'
        . 'drop the (?:charges|bounty)|keep (?:this|it) (?:quiet|between us)|no questions|'
        . 'you never saw me|never saw me)\b/', $u)) { return 'bribe'; }
    // [pt19c-B fix 1 / game review] "make it worth" is gone: "Make it worth my while" is HIM asking to be paid (a reward bargain,
    // checks.stakes.reward), and read as a bribe it had the quest giver ask HIM for septims. "worth your while" stays.
    if (!$moneyTurn && (preg_match('/\b(?:i(?:\'ll| will) pay|here(?:\'s| is) (?:some )?(?:gold|coin|septims?)|'
            . 'take (?:this|the) (?:gold|coin|purse)|a little coin|worth your while)\b/', $u)
        // a spelled-out or numeric sum IS a bribe, but only inside a giving frame: a sum on its own is
        // just a number in a sentence ("I found fifty gold in the cave")
        || (function_exists('lrgIntentAmount') && lrgIntentAmount($u, false) > 0
            && preg_match('/\b(?:here(?:\'s| is| you go)|take (?:it|this|these|them)|i(?:\'ll| will) give you|'
                . 'is yours|consider it|in it for you|keep it|all yours)\b/', $u)))) {
        // [pt19c-B fix 1 / language brief 5.3] on a QUEST turn with no live price, coin with no sum and no purpose phrase is talk
        // about the reward, not a bribe: no "name what it would take" - the reward line answers
        if (lrgDlgNamedAmount($u) <= 0 && lrgDlgQuestTurn($turn) && !lrgDlgTurnPriced($turn)) { return ''; }
        return 'bribe';
    }

    if (preg_match('/\b(?:i(?:\'m| am) (?:actually |really )?(?:a|the|with|from)\b.{0,30}\b(?:guard|thane|courier|'
        . 'agent|inspector|healer|priest|steward|steward,|new steward|jarl\'s)|trust me, i(?:\'m| am)|'
        . 'i(?:\'m| am) the new|i was sent by|sent word ahead|on the jarl\'s orders|'
        . 'i never (?:took|did|was|saw|touched)|i(?: have|\'ve) never (?:set foot|been|seen|met)|'
        . 'that (?:wasn\'t|was not) me|it (?:wasn\'t|was not) me|i had nothing to do with|'
        . '(?:is|was) not mine|(?:isn\'t|wasn\'t) mine|wrong (?:man|woman|person|one)|you(?:\'ve| have) got the wrong)\b/', $u)) {
        return 'deceive';
    }

    if (preg_match('/\b(?:you can tell me|you can trust me|please,? ?just|surely you|be reasonable|'
        . 'think about it|hear me out|for the sake of|i(?:\'m| am) asking you|help me out here|'
        . 'i(?:\'m| am) begging|just tell me|i only need|i just need|let me have|let me through|'
        . 'i(?:\'m| am) good for it|you know me|you(?:\'ve| have) known me|do me this favou?r|'
        . 'i need your help|you owe me|for old times|come on,)\b/', $u)) { return 'persuade'; }
    return '';
}

/** Which stakes the player is playing for, and the base difficulty band that follows. */
function lrgDlgStakes(string $utter): array
{
    $u = ' ' . strtolower($utter) . ' ';
    $best = ['small', 0];
    foreach ((array) lrgDlgCfg('checks.stakes', []) as $name => $def) {
        if ((string) $name === 'reward') { continue; }   // [pt19 v1.0 / S6.1] read by lrgDlgRewardAsk only, never a band here
        foreach ((array) ($def['words'] ?? []) as $w) {
            if (strpos($u, ' ' . strtolower((string) $w)) !== false || strpos($u, strtolower((string) $w) . ' ') !== false) {
                if ((int) ($def['band'] ?? 0) >= $best[1]) { $best = [(string) $name, (int) ($def['band'] ?? 0)]; }
            }
        }
    }
    return $best;
}

// ================================================================== [pt19h-money] the ENGINE check rail and the price in prose
/**
 * [pt19h-money / G13, COVERAGE G13] A QUESTION IS NO ATTEMPT. lrgDlgCheckRails (lib/lrg_dialogue.php) asks this for every
 * persuade / intimidate / bribe line, on her key and on the fast path alike, BEFORE the bribe's price rails: '' = his words may
 * execute the line, else the logged reason - nothing is clicked and her words answer him (no park: a question is no half-said
 * choice, and no refusal note: nothing was refused). $utter is the sentence the rails judge (a parked check's own sentence when
 * his assent released it).
 *  0. He SAID the line (his words at confirm.single_entry_exact, 0.85, and no "?" of his own unless the line itself asks): the
 *     STT's clipped "how I operate. Either do it my way, or find an idiot who does it yours." is the line, not a question, and
 *     "Maybe I could rent the room?" is the question-shaped bribe line itself; "a priestess of Azura sent me?" is an echo.
 *  1. A PRICE QUESTION never executes a check ("how much would it cost?", "how much do you want?", "what's your price?", "what
 *     would it take?", "name your price") - unless the line itself asks the price ("What will it cost to change your mind?
 *     (Bribe)": then his price question IS the line). Stig's 0E4A2E and Skjor's 00090F paid the bribe on "how much would it
 *     cost" (her key).
 *  2. A PROPOSAL is an attempt, not a question: "how about three thousand septims", "why not use me to break the curse?", "what
 *     if I pay you to come to our aid", "why don't you let me through" (lrgDlgCheckProposal) - never "what if I refuse?".
 *  3. A BRIBE offered as a question stays an attempt when it offers the coin: "Would this loosen your tongue?", "would some gold
 *     help?", "Perhaps this will jog your memory?" (lrgDlgCoinOffer) - the bribe lines of this load order are asked that way.
 *  4. A STATEMENT of his that says the line, then a question ("the courier's life is in danger, where is he?", "I don't care
 *     about the ring, what else did I say?"): the attempt was made - a clause of his that is no question (and not the one his
 *     "?" closes) carries the line at the matcher's floor.
 *  5. An INFORMATION question (what / who / why / how / where / when - "what if I refuse?", "Who is the priestess of Azura?",
 *     and a request that embeds one: "can you tell me who the priestess is?") executes a check only when the check line is
 *     itself that question: a question line, the same kind of question (lrgDlgQuestionsAgree), his words at the floor ("What
 *     makes you believe I would not?" says "What makes you think I won't? (Persuade)") - or when it is RHETORICAL and says the
 *     line ("what good is all that gold if the dragons kill you all?", "what's the harm", "who would ...", "why would you ...").
 *  6. A REQUEST ("can you spare a soldier", "could you please help me find the Augur", "won't you defend it") is an attempt
 *     (the gate's two-step still judges it); an order that opens "do it / do this" with no "?" is no question; any other yes /
 *     no question or an echo ("is he in danger", "a priestess of Azura sent me?") executes a check only when the line is itself
 *     a question and his words say it ("will you not defend it" says Skjor's "Whiterun is your hometown, isn't it? Will you not
 *     defend it?").
 */
function lrgDlgCheckAskRail(array $t, array $e, string $utter): string
{
    lrgDlgSumReader();   // the bribe rail right after this prices his sum with the one reader (the fast path has no lrg_intent.php)
    $u = trim($utter);
    if ($u === '' || !function_exists('lrgDlgIsQuestion') || !function_exists('lrgDlgMatchText')) { return ''; }
    $kind = (string) ($e['kind'] ?? '');
    $kind = $kind !== '' ? $kind : 'check';
    $no = 'his question is no ' . $kind . ' attempt - nothing is clicked, her words answer it';
    $line = trim((string) preg_replace('/\s*[\(\[][^)\]]{0,60}[\)\]]\s*$/u', '', (string) ($e['text'] ?? '')));
    $floor = (float) lrgDlgCfg('match.min_score', 0.55);
    $eff = static function (string $s) use ($e): float {
        $m = lrgDlgMatchText($s, [$e]);
        return $m === null || !empty($m['short']) ? 0.0 : (float) ($m['eff'] ?? $m['score'] ?? 0);
    };
    $all = $eff($u);
    // [pt19h r2 / extended DA10.logrolf.bribe] the line with one STT slip is the line: "does it madder here" says "Does it matter? Here."
    if (function_exists('lrgDlgSttFold')) { $all = max($all, $eff(lrgDlgSttFold($u, [$e]))); }
    $lineQ = function_exists('lrgDlgEntryIsQuestion') && lrgDlgEntryIsQuestion($e);
    if ($all >= (float) lrgDlgCfg('confirm.single_entry_exact', 0.85) && ($lineQ || !preg_match('/\?\s*["\')\]]*\s*$/', $u))) { return ''; }
    if (lrgDlgPriceQuestion($u)) {
        return lrgDlgPriceQuestion($line) ? '' : 'a price question is no ' . $kind . ' attempt - nothing is clicked, her words answer it';
    }
    // [pt19h-money round 2 / lang P6] a HYPOTHESIS OF REFUSING attempts nothing, with or without a question word or a "?": "and if
    // i refuse", "so what if i said no", "if i don't pay, what happens", "what happens if i walk away" (the STT drops the "?")
    if (lrgDlgRefusalHypothesis($u)) { return $no; }
    if (!lrgDlgIsQuestion($u)) { return ''; }
    // an order that opens with "do it / do this / do as" and has no "?" is no question ("do it my way or find another idiot")
    if (!str_contains($u, '?') && preg_match('/^\W*(?:(?:just|now|so|then|look|uh|um)[,\s]+){0,2}do\s+(?:it|this|that|as|what|so)\b/i', $u)) { return ''; }
    if (lrgDlgCheckProposal($u)) { return ''; }
    if ($kind === 'bribe' && lrgDlgCoinOffer($u)) { return ''; }
    $parts = preg_split('/([.!?;,]+|\s+but\s+)/iu', $u, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
    for ($i = 0, $n = count($parts); $i < $n; $i += 2) {
        $c = trim((string) $parts[$i]);
        if (str_contains((string) ($parts[$i + 1] ?? ''), '?') || count(preg_split('/\s+/', $c) ?: []) < 3 || lrgDlgIsQuestion($c)) { continue; }
        if ($eff($c) >= $floor) { return ''; }
    }
    $says = $all >= $floor;
    $qk = function_exists('lrgDlgQuestionKind') ? lrgDlgQuestionKind($u) : '';
    $low = strtolower($u);
    $request = (bool) preg_match('/^\W*(?:(?:uh|um|er|so|well|look|please|and|ok|okay|hey|now)[,\s]+){0,2}(?:can|could|would|will|won\'?t|can\'?t|couldn\'?t|'
        . 'wouldn\'?t)\s+you\b/', $low) && !preg_match('/\b(?:who|what|where|why|how|which|when)\b/', $low);
    if (str_starts_with($qk, 'wh:') || (!$request && preg_match('/\b(?:tell me|know|explain)\s+(?:who|what|where|why|how|which|when)\b/', $low))) {
        // a RHETORICAL question that says the line is the argument itself ("what good is all that gold if the dragons kill you
        // all?" says "Won't have a point earning all that gold if the dragons kill you all. (Persuade)")
        $rhet = (bool) preg_match('/^\W*(?:(?:uh|um|er|so|well|look|and|but|come on|then|now|hey)[,\s]+){0,2}(?:what good (?:is|are|will|would|does)|'
            . 'what use (?:is|are|will|would)|what(?:\'s| is) the (?:use|point|harm|worst|good|sense)|what(?:\'s| is) (?:it|that|all that|your \w+) '
            . '(?:worth|good for)|who cares|who would|why would (?:you|anyone|i|we|they)|why wouldn\'?t|how (?:could|can) you|what (?:do|would|will) you '
            . '(?:gain|lose)|what have you got to lose|what(?:\'s| is) there to lose)\b/', $low);
        // [pt19h-money review] "why would I pay you?" is HIS refusal to pay, never the bribe said rhetorically
        if ($kind === 'bribe' && preg_match('/\bwhy (?:would|should|do|must) (?:i|we)\b(?:\s+\w+){0,2}\s+(?:pay|paid|give|bribe|offer|hand)\b/', $low)) { return $no; }
        return $says && ($rhet || ($lineQ && (!function_exists('lrgDlgQuestionsAgree') || lrgDlgQuestionsAgree($u, $e)))) ? '' : $no;
    }
    if ($request) { return ''; }   // a request is an attempt; the gate's own two-step (park, scoff-first) still judges it
    // [pt19h-money round 2 / lang P6] ...and a yes / no question says a QUESTION line only at the explicit floor and as the same
    // question (lrgDlgCheckSaysQuestion); the matcher's loosest floor (0.55) let "isn't Whiterun your hometown?" (0.838), "is
    // Whiterun your hometown?" and "is this your hometown?" (0.567) execute Skjor's persuade (00090D, COVERAGE G13's evidence)
    return $lineQ && lrgDlgCheckSaysQuestion($t, $e, $u) ? '' : $no;
}

/**
 * [pt19h-money round 2 / lang P6] Does his yes / no question SAY the check line, which is itself a question? Both of:
 *  - the explicit floor of S4.3: his words at confirm.said_line_score (0.70) against the line, and - on a list of more than one
 *    line - that line on top with confirm.said_line_margin (0.25) over the next (the matcher's loosest floor was used before);
 *  - the SAME question: his question's subject (lrgDlgQuestionKind 'yn:<subject>') is the subject of one of the line's own
 *    questions. "will you defend it?" asks what Skjor's "... Will you not defend it?" asks (yn:you); "isn't Whiterun your
 *    hometown?" (yn:whiterun), "is this your hometown?" (yn:it) ask something of their own. An echo ("rent the room?") asks back.
 * His line said at confirm.single_entry_exact (0.85) never reaches this (lrgDlgCheckAskRail's rule 0).
 */
function lrgDlgCheckSaysQuestion(array $t, array $e, string $u): bool
{
    if (!function_exists('lrgDlgQuestionKind')) { return false; }
    $ku = lrgDlgQuestionKind($u);
    if (!str_starts_with($ku, 'yn:')) { return false; }
    $same = static fn(array $x): bool => (int) ($x['pos'] ?? -1) === (int) ($e['pos'] ?? -2) && (string) ($x['norm'] ?? '') === (string) ($e['norm'] ?? '');
    $pool = array_values(array_filter(array_merge((array) ($t['entries'] ?? []), (array) ($t['tail'] ?? [])),
        static fn($x) => is_array($x) && (string) ($x['class'] ?? '') !== 'hidden'));
    if (!array_filter($pool, $same)) { $pool = [$e]; }
    $m = lrgDlgMatchText($u, $pool);
    if ($m === null || !empty($m['short']) || !$same((array) ($pool[$m['i']] ?? []))) { return false; }
    if ((float) ($m['eff'] ?? 0) < (float) lrgDlgCfg('confirm.said_line_score', 0.70)) { return false; }
    // (the margin as lrgDlgExplicit reads it: his effective score over the runner-up's)
    if (count($pool) > 1 && (float) $m['eff'] - ((float) $m['score'] - (float) $m['margin']) < (float) lrgDlgCfg('confirm.said_line_margin', 0.25)) { return false; }
    $txt = trim((string) ($e['text'] ?? ''));
    for ($i = 0; $i < 2; $i++) { $txt = trim((string) preg_replace('/\s*[\(\[][^)\]]{1,60}[\)\]]\s*$/u', '', $txt)); }
    foreach (preg_split('/(?<=[.!?])\s+/u', $txt) ?: [] as $sent) {
        if (lrgDlgQuestionKind((string) $sent, true) === $ku) { return true; }
    }
    return false;
}

/**
 * [pt19h-money round 2 / lang P6] A HYPOTHESIS OF HIS REFUSING, leading his sentence (after fillers, "what", "what happens",
 * "even"): "and if i refuse", "so what if i said no", "if i don't pay what happens", "what happens if i walk away", "what if we
 * decline". Never a condition inside an offer ("I'll pay you if you don't tell anyone" - the "if" does not lead, and its
 * subject is not his).
 */
function lrgDlgRefusalHypothesis(string $s): bool
{
    $s = strtolower(str_replace(["\u{2019}", "\u{2018}"], "'", trim($s)));
    // a negated auxiliary is a refusal only with nothing after it or a verb of going along ("if I don't pay", "and if I won't?",
    // "what if we don't agree") - "If I don't get the hilt from you, then Mehrunes Dagon himself will" is the threat itself
    $negAux = '(?:don\'?t|do not|didn\'?t|did not|won\'?t|will not|wouldn\'?t|would not|can\'?t|cannot|couldn\'?t|could not|never)(?:\s+(?:want to\s+|feel like\s+)?'
        . '(?:pay|paid|do (?:it|that|this|so|as you say)|agree|help|comply|accept|cooperate|obey|go along|play along|care|bother|give (?:you|it|in)|hand (?:it|them) over|'
        . 'tell you|talk)\b|\s*[?!.,]|\s*$)';
    return (bool) preg_match('/^\W*(?:(?:and|but|so|then|well|ok|okay|now|uh|um|er|hmm|yeah|right|look|listen|i mean|alright)[,\s]+){0,3}'
        . '(?:what(?:\'s| is)?\s+(?:(?:happens|would happen|will happen|happened|then)\s+)?)?(?:even\s+)?if\s+(?:i|we)\s+(?:just\s+|simply\s+|still\s+|'
        . 'really\s+)?(?:(?:refuse[ds]?|decline[ds]?|say no|said no|says no|tell you no|told you no|say nothing|said nothing|choose not|chose not|decide not|'
        . 'decided not|walk away|walked away|leave|left|pass|keep (?:my|the|our) (?:gold|money|coin|coins|septims)|turn (?:you|it|this) down|'
        . 'turned (?:you|it|this) down|refuse to)\b|' . $negAux . ')/', $s);
}

/**
 * [pt19h-money / G13] A PROPOSAL shaped as a question is an attempt: "how about ...", "why not ...", "why don't you / we ...",
 * "what if I / we <do something>" - never a refusal or a hypothetical of refusing ("what if I refuse?", "what if I say no?",
 * "what if I don't?").
 */
function lrgDlgCheckProposal(string $s): bool
{
    $s = strtolower(str_replace(["\u{2019}", "\u{2018}"], "'", trim($s)));
    // [pt19h-money round 2] "what about" + something FOR HIM is a proposal too ("uh, and what about something for me, up front?", "before
    // I say yes, what about a little something for me, right now, in coin?" - Anuriel's persuade line is asked that way); "what about
    // the shipment?" asks about the shipment (an echo: nothing is clicked)
    if (preg_match('/^(.*?\b)what about\s+(?:a little |a bit of |some |a |an )?(?:something|anything|more|extra|share|cut|coin|gold|septims?|money|pay|payment|'
        . 'compensation|bonus|fee|reward|my (?:share|cut|pay|payment|reward|fee|due))\b(.*)$/', $s, $wa)
        && !preg_match('/\b(?:not|never|no|nothing)\b/', $wa[2])) {
        return true;
    }
    if (!preg_match('/^\W*(?:(?:uh|um|er|so|well|look|then|and|ok|okay|hey|now|fine)[,\s]+){0,2}(how about|how \'?bout|howbout|why not|why don\'?t (?:you|we)|what if (?:i|we))\b(.*)$/', $s, $m)) {
        return false;
    }
    // [pt19h-money review] a proposal of NOTHING or of LATER offers nothing now: "how about I pay you nothing?", "what if I pay you
    // later?", "how about some other time?" (a deferral never clicks a priced / scripted line)
    if (preg_match('/\b(?:nothing|not a (?:septim|coin|penny|thing)|later|tomorrow|some ?other time|another time|next time|some ?day|one day|eventually|not (?:now|today|yet))\b/', $m[2])) {
        return false;
    }
    if (str_starts_with($m[1], 'what if')) {
        // [pt19h-money round 2 / lang P6] + the past and reported refusals: "so what if i said no", "what if i told you no", "what if
        // we turned you down", "what if i kept my gold"
        return !preg_match('/^\s*(?:just\s+|simply\s+|still\s+)?(?:refuse[ds]?|decline[ds]?|say no|said no|says no|tell you no|told you no|say nothing|'
            . 'said nothing|don\'?t|do not|won\'?t|will not|wouldn\'?t|would not|can\'?t|cannot|couldn\'?t|never|am not|\'m not|didn\'?t|did not|walk away|'
            . 'walked away|leave|left|pass|fail|lose|die|choose not|chose not|decide not|decided not|turn(?:ed)? (?:you|it|this) down|'
            . 'kept? (?:my|the|our) (?:gold|money|coins?|septims))\b/', $m[2]);
    }
    return true;
}

/**
 * [pt19h-money / G13] Does this sentence ASK THE PRICE (a question, or a price-inquiry frame leading it after at most three
 * fillers - "tell me how much", "name your price", "just tell me what it costs")? "I don't care how much it costs, here's 200
 * gold" is no price question: the frame neither asks nor leads.
 */
function lrgDlgPriceQuestion(string $s): bool
{
    $s = strtolower(str_replace(["\u{2019}", "\u{2018}"], "'", trim($s)));
    if ($s === '') { return false; }
    // [pt19h-money round 2 / lang P6] + the contracted auxiliary ("so what'll it cost", "what'd that run me"), "what's that gonna
    // cost me", "what's the damage"
    $frame = '(?:how much|how many (?:septims|gold|coins?)|what(?:\'s| is| are| would be| will be| be)? (?:the |your |a |that |this )?'
        . '(?:price|cost|fee|rate|charge|asking price)s?|what(?:\s+(?:would|will|does|do|did|might|could|should)|\'ll|\'d)?\s*(?:it|that|this|you|they)\s*'
        . '(?:cost|take|want|charge|ask|run)|what(?:\'s|s| is)? (?:it|that|this) (?:going to|gonna) (?:cost|take|run)|name (?:your|a|the|my) price|'
        . 'your price|what do you want (?:for|in exchange|in return)|what(?:\'ll| will) it be|what(?:\'s|s| is) the damage)';
    if (!preg_match('/\b' . $frame . '\b/', $s, $m, PREG_OFFSET_CAPTURE)) { return false; }
    if (function_exists('lrgDlgIsQuestion') && lrgDlgIsQuestion($s)) { return true; }
    $before = trim(substr($s, 0, (int) $m[0][1]));
    // [pt19h-money round 2 / lang P6] + the owner's STT fillers ("so yeah how much would it cost", "i mean how much would it cost",
    // "okay but how much", "i wonder how much it costs", "i'd like to know how much it is") - up to four of them
    $fill = ['tell', 'me', 'just', 'so', 'well', 'uh', 'um', 'er', 'ok', 'okay', 'alright', 'then', 'and', 'now', 'fine', 'look', 'please',
        'yeah', 'yes', 'yep', 'like', 'i', 'mean', 'right', 'hmm', 'but', 'oh', 'hey', 'listen', 'say', 'wait', 'all', 'anyway', 'actually',
        'honestly', 'sorry', 'wonder', 'know', 'want', 'to', "i'd", 'id'];
    $toks = $before === '' ? [] : preg_split('/[^a-z\']+/', $before, -1, PREG_SPLIT_NO_EMPTY);
    if (count($toks) > 4) { return false; }
    foreach ($toks as $tk) { if (!in_array($tk, $fill, true)) { return false; } }
    return true;
}

/**
 * [pt19h-money / G13] A bribe offered as a question: the COIN right after a proposal frame ("would this ...", "does this coin
 * ...", "would some gold help?", "perhaps this will ...", "maybe a little gold ...", "how about I offer you some coin"), his
 * paying verb after "what if / if / how about I" ("what if I pay you to come to our aid"), or "would you take / accept" a sum or
 * the coin - and no negation ("what if I refuse to pay" offers nothing). "will you do this for me?" offers no coin.
 */
function lrgDlgCoinOffer(string $s): bool
{
    $s = strtolower(str_replace(["\u{2019}", "\u{2018}"], "'", trim($s)));
    // [pt19h-money round 2 / lang P7] the same offer whatever auxiliary asks it: a NEGATIVE auxiliary that leads the clause is the
    // question's shape, no refusal ("wouldn't some gold help you look the other way?", "won't this loosen your tongue?")
    $lead = '(?:^|[,.;:!?]\s*)(?:(?:uh|um|er|so|well|here|look|and|then|now|ok|okay|alright|hmm|but|yeah|listen)[,\s]+){0,2}';
    $s = (string) preg_replace('/(' . $lead . ')(?:wouldn\'?t|won\'?t|couldn\'?t|isn\'?t|doesn\'?t)\b/', '$1would', $s);
    if (preg_match('/\b(?:not|never|no|refuse|won\'?t|don\'?t|can\'?t|cannot|without)\b/', $s)) { return false; }
    // [pt19h-money review] ...nor a coin of nothing or of later ("what if I gave you nothing?", "what if I pay you later?")
    if (preg_match('/\b(?:nothing|later|tomorrow|some ?other time|another time|next time|some ?day|one day|eventually)\b/', $s)) { return false; }
    // [pt19h-money round 2] "why would I pay you?" / "why should we give you anything?" refuses, whatever follows
    if (preg_match('/\bwhy\b/', $s)) { return false; }
    $coin = '(?:gold|coins?|septims?|money|purse|drakes?)';
    $numw = '(?:\d[\d,]*|a|an|one|two|three|four|five|six|seven|eight|nine|ten|eleven|twelve|fifteen|twenty|thirty|forty|fifty|sixty|seventy|eighty|'
        . 'ninety|hundred|thousand|couple|few|and)';
    $obj = '(?:this|these|(?:(?:some|a little|a bit of|a few|a handful of|a purse of|my|more)\s+)?' . $coin . '|\d+|(?:a|an|one|two|three|four|five|'
        . 'six|seven|eight|nine|ten|twenty|thirty|forty|fifty|hundred|thousand|a hundred|a thousand)\s+(?:\w+\s+)?' . $coin . ')';
    // a SUM of coin: number words (or digits) and the coin, or digits alone ("is 200 enough?")
    $sum = '(?:(?:' . $numw . '[\s\-]+)+' . $coin . '|\d[\d,]*(?:\s*' . $coin . ')?)';
    // the frame LEADS its clause (after at most two fillers): "will you do this for me?" is no "will this ..."
    return (bool) preg_match('/' . $lead . '(?:(?:would|will|could|can|does|do|might|may|maybe|perhaps|how about|what about|how does|how do|how would|how will|'
            . 'what would you say to|what do you say to|what say you to)\s+(?:i\s+(?:offer|give|pay|hand)\s+(?:you\s+)?)?|(?:can|could|may|might|shall)\s+'
            . '(?:i|we)\s+(?:offer|give|pay|hand)\s+(?:you\s+)?)' . $obj . '\b/', $s)
        // [round 2 / P7] "is two hundred septims enough?", "is this enough?", "would 200 be fair?"
        || (bool) preg_match('/' . $lead . '(?:is|are|was|would|will)\s+(?:' . $sum . '|this|that|these)\s+(?:be\s+)?(?:enough|sufficient|acceptable|fair|'
            . 'alright|all right|okay|ok|good enough|a fair price|worth (?:it|your while))\b/', $s)
        // [round 2 / P7] "Two hundred septims, how's that?", "forty gold - what do you say?"
        || (bool) preg_match('/^\W*(?:(?:uh|um|so|well|ok|okay|then|here)[,\s]+){0,2}' . $sum . '\s*[,.\-]*\s*(?:how(?:\'s| is| about) (?:that|it)|what do you say|'
            . 'what say you|will that do|would that do|is that enough|does that work|does that help|deal|sound good|fair)\W*$/', $s)
        // his paying verb after a proposal / request frame: "what if I pay you to come to our aid", "can we pay you to come help us?"
        || (bool) preg_match('/\b(?:what if|if|how about|what about|suppose)\s+(?:i|we)\s+(?:pay|paid|give|gave|offer|offered|compensate|bribe)\b/', $s)
        // (paying verbs only: "may I give you some advice?" offers no coin - a give / offer needs the coin itself, above)
        || (bool) preg_match('/' . $lead . '(?:can|could|may|might|shall|let)\s+(?:i|we|me|us)\s+(?:just\s+)?(?:pay|compensate|bribe|tip|reimburse)\b/', $s)
        // [round 2 / P7] a hand-over of coin, then his question: "Here's two hundred septims, will you look the other way?", "Here, take
        // the gold. Does it matter who sent me?", "..., here, take this and come with me" (Logrolf's "Does it matter? Here." bribe)
        || (bool) preg_match('/' . $lead . '(?:here(?:\'s| is| are)?[,.]?\s+(?:take\s+|have\s+)?|take\s+|have\s+)(?:(?:this|these)\b|(?:(?:the|my|some|a little|'
            . 'a few|a bit of|your)\s+)?(?:' . $sum . '|' . $coin . ')\b)/', $s)
        || (bool) preg_match('/\b(?:would|will|could|can)\s+you\s+(?:take|accept|consider)\b[^.!?]{0,24}\b(?:\d+|' . $coin . '|this|these)\b/', $s);
}

/**
 * [pt19h-money / G14, COVERAGE G14] THE PRICE OF A LINE OUTSIDE A "(N gold)" TAG, in septims, or 0. lrgDlgDecorateEntries asks
 * this only for a line whose tag gave no price and that is no check (a bribe is priced by its own token / bamt): the >= 100
 * septims ask (S4.10) and the afford rail then run on it, and its label says the price - so every figure here is one the game
 * really takes, never a guess.
 *  1. HIS LINE hands over or pays a sum it names: "Here's the 500 gold." (06F999), "Buy unusual gem for 1000 gold." (00080B),
 *     "I'm the Arch-Mage. It's 2,000 coins." (0126D7), "Alright, here's your money. (Pay 1000 coins)", "Here, have 100 septims
 *     ...", "I'll take that claw for 50 gold.", "I'll pay you 300 septims". Never a sale or a sum he RECEIVES ("Sell unusual gem
 *     for 50 gold.", "It's yours for 500 gold.", "6000 gold, and they're yours.", "I think I've earned that 100 gold", "pay me"),
 *     never a question, a wager or a bare figure ("20,000 gold.", "How about 100 gold?", "1000 gold!"), never a negation ("I
 *     don't have 750 gold").
 *  2. A HAND-OVER WITH NO SUM ("Here's the gold you wanted." 0C9A08, "Here's the gold." 0B038E, "Here's your money.", "I'll pay the
 *     fine."): the sum SHE DEMANDED in her lines of the last 120 s (lrgDlgState lines - her subtitles), the last one she said
 *     ("Then, as I've said, 1000 gold will be required", "a donation of 250 should help", "Bring me 1000 gold"). None -> 0: the glue
 *     does not know the figure and says none (the fixed fines of the fragment - Vex's reparation - are not in any line on this
 *     install); lrgDlgProseMark then makes that line ask before it pays (the sum is unknown), and lrgDlgProseReprice prices it
 *     when her figure arrives after the list.
 *     [pt19h-money round 2 / arch P3] only a figure she ASKS FOR (lrgDlgProseDemanded - a demand frame in her clause, no telling
 *     of a sale, a payment, a find or a worth): "I sold the last one for 5000 gold, you know." then "Here's your money." priced the
 *     line at 5000, the afford rail refused it and her next turn was told the false "could not pay what that costs".
 *  [pt19h-money round 2 / lang P8, arch P3] never a DEFERRAL of his (lrgDlgProseDefers): "I'll pay the toll later." (0DF1CD, MS02)
 *  took her last-named sum; "I'll pay you 300 septims tomorrow" pays nothing now.
 */
function lrgDlgProseCost(string $text, string $npc = ''): int
{
    $raw = trim(str_replace(["\u{2019}", "\u{2018}"], "'", $text));
    if ($raw === '' || (function_exists('lrgDlgKindFromTag') && lrgDlgKindFromTag($raw) !== '')) { return 0; }
    $s = strtolower($raw);
    $num = '(\d{1,3}(?:,\d{3})+|\d{1,6})';
    $coin = '(?:gold|septims?|coins?|drakes?)';
    $toInt = static fn(string $n): int => (int) str_replace(',', '', $n);
    // a tag that pays ("(Pay 1000 coins)", "(pay 300 septims)"): the tag's own sum
    if (preg_match('/\(\s*(?:pay|give|costs?|donate)\s+' . $num . '\s*' . $coin . '?\s*\)/', $s, $m)) { return $toInt($m[1]); }
    $body = trim((string) preg_replace('/\s*[\(\[][^)\]]{0,60}[\)\]]\s*$/', '', $s));
    if ($body === '' || str_contains($body, '?') || preg_match('/\b(?:sell|sold|selling|earned|owe me|pay me|give me|it\'?s yours|they\'?re yours'
        . '|you can have|reward|winnings|bet|wager|lie)\b/', $body)
        || preg_match('/\b(?:not|never|no(?!\s+(?:problem|worries|trouble))|don\'?t|doesn\'?t|didn\'?t|can\'?t|cannot|won\'?t|haven\'?t|without)\b/', $body)
        || lrgDlgProseDefers($body)) {
        return 0;
    }
    $lead = '(?:^|[.!;:,]\s*)(?:(?:sure|okay|ok|fine|alright|all right|no problem|yes|yeah|well|very well|here)[.!,]*\s+)*';
    $pay = [
        '/' . $lead . 'here(?:\'s| is| are|,)?(?:\s*\.\.\.)?\s+(?:the |your |my |another |you go,? |you are,? )?(?:have\s+)?' . $num . '\s*' . $coin . '\b/',
        '/\b(?:buy|purchase|rent|hire|i\'?ll take|i will take)\b[^.!?]{0,40}\bfor\s+' . $num . '\s*' . $coin . '\b/',
        '/\b(?:i\'?ll|i will|let me|i can|i\'m ready to|i am ready to)\s+(?:pay|give)\s+(?:you\s+|the\s+)?' . $num . '\s*' . $coin . '\b/',
        '/' . $lead . 'it\'?s\s+' . $num . '\s*' . $coin . '\s*[.!]*\s*$/',
    ];
    foreach ($pay as $rx) { if (preg_match($rx, $body, $m)) { return $toInt($m[1]); } }
    // 2. a hand-over with no sum: the sum she just demanded
    if ($npc === '' || !function_exists('lrgDlgState') || !lrgDlgProseHandOver($body)) { return 0; }
    $lines = (array) (lrgDlgState($npc)['lines'] ?? []);
    for ($i = count($lines) - 1; $i >= 0; $i--) {
        $l = (array) $lines[$i];
        if (lrgNow() - (int) ($l['at'] ?? 0) > 120) { break; }
        $n = lrgDlgProseDemanded((string) ($l['t'] ?? ''));
        if ($n > 0) { return $n; }
    }
    return 0;
}

/**
 * [pt19h-money / G14] His line HANDS OVER a sum it does not name (lrgDlgProseCost rule 2's shape, tag stripped, lower-cased):
 * "Here's the gold you wanted.", "Here's the gold.", "Alright, here's your money.", "I'll pay the fine." - never a deferral.
 */
function lrgDlgProseHandOver(string $body): bool
{
    $body = strtolower(str_replace(["\u{2019}", "\u{2018}"], "'", trim($body)));
    return (bool) preg_match('/(?:^|[.!,]\s*)(?:(?:okay|ok|fine|alright|yes|yeah|well|very well|sure)[.!,]*\s+)*(?:here(?:\'s| is| you go| you are)?,?\s+'
            . '(?:the |your |my )?(?:gold|money|coin|coins|septims|payment|fine|fee|donation)\b|i\'?ll pay (?:the |your |my )?(?:fine|fee|debt|toll)\b)/', $body)
        && !lrgDlgProseDefers($body);
}

/**
 * [pt19h-money round 2 / lang P8, arch P3] A DEFERRAL of the payment in his words: later, tomorrow, another / next / some other
 * time, some day, one day, eventually, soon, when I can, once I have it, after I ..., not now / yet / today.
 */
function lrgDlgProseDefers(string $s): bool
{
    return (bool) preg_match('/\b(?:later|tomorrow|another time|some ?other time|next time|some ?day|one day|eventually|soon|when i can|when i\'?m able|'
        . 'once i (?:have|get|find|earn)|after (?:i|we)|not (?:now|yet|today))\b/', strtolower($s));
}

/**
 * [pt19h-money round 2 / arch P3] The figure SHE DEMANDS in one line of hers, or 0: her line carries a demand frame (required,
 * cost, will be, that'll be, owe, bring, pay, a donation / fine / fee / toll / price / sum / ransom / debt / tithe / charge, want,
 * need, demand, hand over, give me, "sell you", "let it go for", "I'll take", "I'd say", "N or no deal / your life", sufficient,
 * enough, secure) and the figure - the last "N gold / septims / coins" or "a donation / fine / fee / price / toll ... of N" - sits
 * in a sentence of hers that tells of no sale, payment, find, worth or gift ("I sold the last one for 5000 gold, you know", "it's
 * worth 5000 gold", "I paid 300 septims for it", "they offered 500 gold", "here's 500 gold for your discretion") - a sum she
 * tells of is never one she asks for. Measured over every response in the prompt index that names a figure (tools-side
 * p_demand.php): Tolfdir's three fines, Astrid's 300-gold fine, "the price is still 4,000 coins", "It's 200 gold or your life"
 * are read; "The victor gains 100 gold coins from the loser" and "2000 gold? You've got to be joking!" are not.
 */
function lrgDlgProseDemanded(string $said): int
{
    $said = strtolower(str_replace(["\u{2019}", "\u{2018}"], "'", $said));
    $num = '(\d{1,3}(?:,\d{3})+|\d{1,6})';
    $coin = '(?:gold|septims?|coins?|drakes?)';
    if (!preg_match('/\b(?:requir\w*|cost|costs|will be|would be|that\'?ll be|that will be|owe|owes|bring|pay|payment|donation|fine|fee|toll|'
        . 'price|sum|bounty|reparations?|ransom|debt|tithe|charge|want|need|demand|hand over|give me|give us|fetch|deliver|sufficient|suffice|enough|'
        . 'secure|sell (?:you|it|them|this|that)|let (?:it|them) go for|i\'?ll take|i will take|i\'?d say|or (?:no|your|nothing|else|you)|'
        . 'for (?:a mere|just|only|merely))\b/', $said)) {
        return 0;
    }
    $best = 0;
    foreach (preg_split('/(?<=[.!?;])\s+/', $said) ?: [] as $cl) {
        // (never "made": Tolfdir's own "I made it rather clear that a donation of 500 gold will clear it up" is the demand - measured
        // offline over every response that leads to a bare hand-over, tools-side p_rule2.php)
        if (preg_match('/\b(?:sold|bought|paid|spent|found|worth|earned|won|lost|stole|stolen|gave|given|give you|reward\w*|offered|cheap|ago|'
            . 'last one|winnings|gains?|here(?:\'s| is| are)\s+(?:your\s+|the\s+|some\s+)?(?:\d[\d,]*\s*)?(?:gold|septims?|coins?))\b/', $cl)) {
            continue;
        }
        if (preg_match_all('/\b' . $num . '\s*' . $coin . '\b|\b(?:donation|fine|price|fee|sum|payment|toll|cost|bounty|reparations?|ransom|debt)\s+'
            . '(?:of|is|will be|would be|was|comes to|stands at)\s+(?:still\s+|only\s+|just\s+)?' . $num . '\b/', $cl, $mm, PREG_SET_ORDER)) {
            $last = end($mm);
            $n = (int) str_replace(',', '', (string) (($last[1] ?? '') !== '' ? $last[1] : ($last[2] ?? '')));
            if ($n > 0) { $best = $n; }
        }
    }
    return $best;
}

/**
 * [pt19h-money round 2 / G14, arch P2] What priced this line (lrgDlgDecorateEntries stores it as `prose`): 0 nothing, 1 its own
 * words (rule 1), 2 the sum she demanded (rule 2), 3 a hand-over whose sum is NOT KNOWN yet (rule 2's shape, no figure of hers).
 */
function lrgDlgProseKind(string $text, int $cost): int
{
    if ($cost > 0) { return lrgDlgProseCost($text, '') > 0 ? 1 : 2; }
    $body = trim((string) preg_replace('/\s*[\(\[][^)\]]{0,60}[\)\]]\s*$/', '', strtolower(str_replace(["\u{2019}", "\u{2018}"], "'", trim($text)))));
    if ($body === '' || (function_exists('lrgDlgKindFromTag') && lrgDlgKindFromTag($text) !== '')) { return 0; }
    return lrgDlgProseHandOver($body) ? 3 : 0;
}

/**
 * [pt19h-money round 2 / G14, arch P2] A HAND-OVER OF AN UNKNOWN SUM ASKS FIRST (prose 3). The price of "Here's the gold you
 * wanted." (0C9A08) is Tolfdir's own line, and ev=line and lrg_topics are separate messages: with the list read first (a hand
 * click, an E-press greeting, the want=1 fast path) the line was cost 0, class plain, and "here's the gold you wanted" paid 1,000
 * septims with no question. Until her figure is known it is a commit that is never explicit (fcommit - lrgDlgExplicit): every
 * path parks and she asks, and his assent then releases it (S4.4). A single-entry layer keeps S4.5 (class unchanged). What the
 * row was before is kept (commit0 / fcommit0) for lrgDlgProseReprice. Called by lrgDlgDecorateEntries before the label.
 */
function lrgDlgProseMark(array $row): array
{
    if ((int) ($row['prose'] ?? 0) !== 3 || (int) ($row['cost'] ?? 0) > 0) { return $row; }
    $row['commit0'] = !empty($row['commit']) ? 1 : 0;
    $row['fcommit0'] = !empty($row['fcommit']) ? 1 : 0;
    $row['commit'] = true;
    $row['fcommit'] = 1;
    return $row;
}

/**
 * [pt19h-money round 2 / G14, arch P2] HER FIGURE ARRIVED AFTER THE LIST: lrgDlgOnLine calls this for each new subtitle. Every
 * row of the open session still waiting for its sum (prose 3) is priced from her lines now (lrgDlgProseCost rule 2): cost, class
 * pay, afford against the list's purse (freeze rule B1), commit (>= confirm.min_gold or a quarter of the purse, or what it was
 * before the unknown sum made it one), label. Returns the number of rows priced.
 */
function lrgDlgProseReprice(string $npc, string $cid = ''): int
{
    if ($npc === '' || !function_exists('lrgDlgState') || !function_exists('lrgDlgPut')) { return 0; }
    $sess = (array) (lrgDlgState($npc)['session'] ?? []);
    $rows = (array) ($sess['entries'] ?? []);
    $pg = (int) ($sess['pg'] ?? 0);
    $n = 0;
    foreach ($rows as $i => $row) {
        if (!is_array($row) || (int) ($row['prose'] ?? 0) !== 3 || (int) ($row['cost'] ?? 0) > 0) { continue; }
        $c = lrgDlgProseCost((string) ($row['text'] ?? ''), $npc);
        if ($c <= 0) { continue; }
        $row['cost'] = $c;
        $row['prose'] = 2;
        $row['afford'] = $pg >= $c ? 1 : 0;
        if (in_array((string) ($row['class'] ?? ''), ['plain', 'commit'], true)) { $row['class'] = 'pay'; }
        $row['commit'] = !empty($row['commit0']) || $c >= (int) lrgDlgCfg('confirm.min_gold', 100)
            || ($pg > 0 && $c >= (float) lrgDlgCfg('confirm.gold_fraction', 0.25) * $pg);
        $row['fcommit'] = (int) ($row['fcommit0'] ?? 0);
        if (function_exists('lrgDlgLabel')) { $row['label'] = lrgDlgLabel($row, $pg); }
        $rows[$i] = $row;
        $n++;
        lrgDlgLog('prose price npc=' . $npc . ': "' . substr((string) $row['text'], 0, 40) . '" costs ' . $c . ' septims (her line after the list)', $cid);
    }
    if ($n > 0) {
        $sess['entries'] = $rows;
        lrgDlgPut($npc, ['session' => $sess]);
    }
    return $n;
}

// ================================================================== [pt19 v1.0 / S6] rewards: fixed by the engine, said out loud
/**
 * [S6.1] A QUEST turn with this NPC: a journal quest of hers in q, or a Phase 2 result younger than 180 s. The reward hides, the
 * reward line and the truth backstop key on it.
 * [pt19c-B fix 1 / ai review 8] "a journal quest of hers" is CHIM's questlog carrying a row for a quest in her q= (the bookkeeping
 * patterns aside) - not whether <shared_business> renders, which also follows quests.enabled, iQuestLines and the objective
 * cleaner: switching the quest DISPLAY off must not switch the reward protection off with it.
 * [pt19c-B fix 1 / game review 3] the result must be on a line that is not a service one: "I'd like a room.", a trade, carriage,
 * ferry, training, follower or fine line, a priced line or a back-out is no quest business - 20 s after the first evening's room
 * the reward line rode Hulda's ale order. Such a click still counts when it moved a journal row of hers.
 */
function lrgDlgQuestTurn(array $t): bool
{
    $npc = (string) ($t['npc'] ?? '');
    if ($npc === '' || empty($t['on'])) { return false; }
    if (trim((string) ($t['quests'] ?? '')) !== '' || lrgDlgHasQuestRows($t)) { return true; }
    $st = lrgDlgState($npc);
    $lr = (array) ($st['last_result'] ?? []);
    if ($lr === [] || !empty($lr['refusal']) || lrgNow() - (int) ($lr['at'] ?? 0) > 180) { return false; }
    return !lrgDlgServiceResult($lr) || (function_exists('lrgDlgNewQuestRows') && lrgDlgNewQuestRows($st, (int) ($lr['at'] ?? 0)) !== []);
}

/** [S6.1] CHIM's questlog carries a journal row for a quest in her q= (the bookkeeping patterns aside). One read, cached per turn. */
function lrgDlgHasQuestRows(array $t): bool
{
    $q = array_values(array_filter(array_map('strval', (array) ($t['q'] ?? []))));
    if (!$q) { return false; }
    static $memo = [];
    $cid = (string) ($t['cid'] ?? '');
    $key = $cid . '|' . implode(',', $q);
    $cache = $cid !== '' && !isset($GLOBALS['LRG_DLG_TEST_QUESTLOG']);
    if ($cache && isset($memo[$key])) { return $memo[$key]; }
    $skip = (array) lrgDlgCfg('quests.bookkeeping_patterns', []);
    $has = false;
    foreach (lrgDlgQuestRows($q) as $row) {
        $id = (string) ($row['id_quest'] ?? '');
        if ($id !== '' && !lrgAnyGlob($skip, [$id])) { $has = true; break; }
    }
    if ($cache) {
        if (count($memo) > 32) { $memo = []; }
        $memo[$key] = $has;
    }
    return $has;
}

/**
 * [S6.1, game review 3] Was the clicked line of this result a SERVICE line (its class service / pay / back as Phase 2 kept it,
 * else from its words: a price in it, a service word, a service kind phrase, a back-out)? Such a click is no quest business.
 */
function lrgDlgServiceResult(array $res): bool
{
    if (in_array((string) ($res['eclass'] ?? ''), ['service', 'pay', 'back'], true)) { return true; }
    $txt = trim((string) ($res['entry'] ?? '')) !== '' ? (string) $res['entry'] : (string) ($res['txt'] ?? '');
    if (trim($txt) === '') { return false; }
    if (function_exists('lrgPromptCost') && lrgPromptCost($txt) !== 0) { return true; }
    return lrgDlgIsService(strtolower($txt)) || lrgDlgServiceKind($txt) !== '' || lrgDlgIsBackOut($txt);
}

/** Does this turn carry a LIVE price (a priced entry on the list, or a vendor's stock / room price on the wire)? */
function lrgDlgTurnPriced(array $t): bool
{
    foreach (array_merge((array) ($t['entries'] ?? []), (array) ($t['tail'] ?? [])) as $e) {
        if ((int) ($e['cost'] ?? 0) > 0) { return true; }
    }
    return function_exists('lrgMktRailFacts') && (bool) lrgMktRailFacts($t);
}

/**
 * [S6.1] Does he bargain over the reward? The phrase he said, 'a named sum', or '' (checks.stakes.reward, the tiers are described
 * there): a phrase of the five tiers that holds in one of his clauses (lrgDlgRewardPhrase), or - on a turn with no live price (a
 * sum on a pay or bribe list is a price, not a reward bargain) - a named sum with a `sum_frames` phrase right beside it in the
 * same clause (lrgDlgRewardSum).
 * [pt19c-B fix 1 / lang review P1] never when his words ARE a line on her live list: the game offers that choice itself and S4
 * takes it (Eorlund's "I'd rather have the greatsword", MQ104's "What about my reward?") - "you cannot change it" would be false.
 */
function lrgDlgRewardAsk(string $utter, array $t): string
{
    if (trim($utter) === '') { return ''; }
    $cfg = (array) lrgDlgCfg('checks.stakes.reward', []);
    $utter = lrgDlgMoneyFold($utter);   // [pt19c-B fix 2 / lang P8] "5 hundred sept ums" is a sum
    $hit = lrgDlgRewardPhrase($utter, $cfg);
    if ($hit === '') { $hit = lrgDlgRewardPaidAsk($utter); }   // [pt19h-money] "I expect to be paid well for this"
    if ($hit === '' && !lrgDlgTurnPriced($t) && lrgDlgRewardSum($utter, $cfg)) { $hit = 'a named sum'; }
    if ($hit === '' || lrgDlgRewardLineSaid($t, $utter)) { return ''; }
    return $hit;
}

/**
 * [pt19c-B fix 2 / lang review P8, the in-lane half] A sum as the owner's STT writes it, folded before THIS lane reads it (the free
 * bribe check, the reward bargain, gate B's bonus): a digit before hundred / thousand becomes its word ("5 hundred gold" ->
 * "five hundred gold"; lrgIntentAmount dropped the digit and read 100) and the money word's STT spellings become "septims"
 * ("sept ums", "septem's", "septums", "septem" - read as no money word, so 0). The ROOT is not Lane B's: LRG_MONEY_WORD and
 * lrgIntentAmount (lrg_intent.php) and the engine bribe rail lrgDlgCheckRails (lrg_dialogue.php, Lane A) still read the raw
 * words - handed off in research/pt19c-B.md; lrgDlgNoteRefusal tells that rail's refusal as "did not come through clearly".
 * [pt19c final fixer] the root is fixed in lrg_intent.php (LRG_MONEY_WORD's STT spellings, lrgIntentSumFold): the raw
 * readers - lrgIntentAmount, lrgDlgNamedAmount, the engine bribe rail - now read these sums as well; this fold stays harmless.
 * [pt19h-money] OWNED by the money fixer (it was handed off): ONE reader of a spoken sum. The money word is folded here, the
 * NUMBER by the root itself (lrgIntentSumFold: the digit before hundred / thousand, the thousands comma, and the multipliers of
 * lrgIntentMultFold - "1.5 thousand" 1500, "2k", "two grand"). This fold's own digit rule turned "1.5 thousand septims" into
 * "1.five thousand septims" (5,000 - an overstated sum on the free bribe and the reward bargain); it now runs only when the
 * root is absent, and never on the digits after a point. Lower-cased when the root runs (every reader here lower-cases).
 */
function lrgDlgMoneyFold(string $u): string
{
    $u = (string) preg_replace('/\b(?:septem[\'\x{2019}]s|sept\s*[eu]ms?|sep\s+tims?)\b/iu', 'septims', $u);
    if (lrgDlgSumReader() && function_exists('lrgIntentSumFold')) {
        $f = lrgIntentSumFold($u);
        return $f === strtolower($u) ? $u : $f;   // nothing of a sum moved: his words as he said them (case kept)
    }
    return (string) preg_replace_callback('/(?<![\d.,])\b(\d{1,2})\s+(hundred|thousand)\b/i', static function (array $m): string {
        $ones = ['', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten', 'eleven', 'twelve', 'thirteen',
            'fourteen', 'fifteen', 'sixteen', 'seventeen', 'eighteen', 'nineteen'];
        $tens = ['', '', 'twenty', 'thirty', 'forty', 'fifty', 'sixty', 'seventy', 'eighty', 'ninety'];
        $n = (int) $m[1];
        if ($n <= 0) { return $m[0]; }
        $w = $n < 20 ? $ones[$n] : trim($tens[intdiv($n, 10)] . ' ' . $ones[$n % 10]);
        return $w . ' ' . strtolower($m[2]);
    }, $u);
}

/**
 * [pt19h-money / S6.1, the measured missed bargain] HIS WANT OF PAY, as a shape rather than a phrase: in one clause of his,
 * paid / rewarded / compensated / reimbursed after "to be" / "to get" / "be" (and well / properly / handsomely), right after a
 * verb of wanting (expect, want, need, deserve, demand, insist, ought, should, better, like, hope, prefer, must) whose subject is
 * HIS (lrgDlgRewardSelf: I / I'm / I'd / we, fillers between, a negation is no filler): "I expect to be paid well for this", "I'd
 * like to be paid up front", "I should be paid more", "Don't I deserve to be compensated?". Never "they expect to be paid", "I
 * don't expect to be paid", "I was paid well by the Jarl". 'to be paid' or ''.
 */
function lrgDlgRewardPaidAsk(string $utter): string
{
    static $skip = ['be' => 1, 'to' => 1, 'get' => 1, 'getting' => 1, 'being' => 1, 'got' => 1, 'well' => 1, 'properly' => 1, 'fairly' => 1,
        'handsomely' => 1, 'decently' => 1];
    // [pt19h-money round 2 / lang P5] no "hope" / "hoping": "I hope to be rewarded in Sovngarde." is a prayer, not his price
    static $want = ['expect' => 1, 'expecting' => 1, 'expected' => 1, 'want' => 1, 'wanted' => 1, 'need' => 1, 'deserve' => 1, 'demand' => 1,
        'insist' => 1, 'ought' => 1, 'should' => 1, 'better' => 1, 'like' => 1, 'prefer' => 1, 'must' => 1];
    // [pt19h-money round 2 / lang P5] an idiom of paying is no pay ("I want to be paid a visit by the Jarl", "paid respects / tribute /
    // attention / heed / homage / mind / a compliment / lip service"), and a reward in the afterlife is no reward of hers ("rewarded
    // in Sovngarde / Aetherius / the afterlife / the next life / heaven / death")
    static $idiom = ['visit' => 1, 'visits' => 1, 'respects' => 1, 'respect' => 1, 'tribute' => 1, 'attention' => 1, 'heed' => 1, 'homage' => 1,
        'mind' => 1, 'compliment' => 1, 'compliments' => 1, 'lip' => 1, 'court' => 1, 'dearly' => 1];
    static $beyond = ['sovngarde' => 1, 'aetherius' => 1, 'afterlife' => 1, 'heaven' => 1, 'heavens' => 1, 'death' => 1, 'next' => 1, 'hereafter' => 1,
        'valhalla' => 1, 'eternity' => 1, 'spirit' => 1];
    foreach (lrgDlgRewardClauses($utter) as [$hay]) {
        foreach ($hay as $i => $tok) {
            if (!in_array($tok, ['paid', 'rewarded', 'compensated', 'reimbursed'], true)) { continue; }
            $after = array_slice($hay, $i + 1, 3);
            if (isset($idiom[(string) ($after[0] ?? '')]) || (in_array((string) ($after[0] ?? ''), ['a', 'my', 'your', 'his', 'her', 'some', 'no', 'the', 'in'], true)
                && isset($idiom[(string) ($after[1] ?? '')]))) {
                continue;
            }
            if (in_array((string) ($after[0] ?? ''), ['in', 'by'], true)
                && (isset($beyond[(string) ($after[1] ?? '')]) || isset($beyond[(string) ($after[2] ?? '')]))) {
                continue;
            }
            $k = $i - 1;
            while ($k >= 0 && isset($skip[$hay[$k]])) { $k--; }
            if ($k < 0 || !isset($want[$hay[$k]]) || $k === $i - 1) { continue; }
            if (lrgDlgRewardSelf(array_slice($hay, 0, $k))) { return 'to be paid'; }
        }
    }
    return '';
}

/** [S6.1] The tokens of a reward compare: lrgPromptNorm with the apostrophes FOLDED ("what's" = "whats", the owner's STT). */
function lrgDlgRewardToks(string $s): array
{
    return lrgDlgTokens(lrgPromptNorm(str_replace(["'", "\u{2019}", "\u{2018}"], '', $s)));
}

/**
 * [pt19c-B fix 2] His clauses for the reward compare: [tokens, question?] each. Split at . ! ? ; : , ... and "but"; a
 * comma or point BETWEEN DIGITS is no split ("2,000 septims" stays one sum). question? = the clause is a question: it ended in "?"
 * or opens with a question word ("could you ...", "is there ...", "what ..."), unless it opens "how about / what about / why not"
 * - a proposal ("how about a little more?" is a bargain).
 */
function lrgDlgRewardClauses(string $utter): array
{
    $out = [];
    $parts = preg_split('/([!?;:\x{2026}]+|[.,]+(?!\d)|(?<!\d)[.,]+)/u', $utter, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
    for ($i = 0, $n = count($parts); $i < $n; $i += 2) {
        $bits = preg_split('/\s+but\s+/iu', (string) $parts[$i]) ?: [];
        $last = count($bits) - 1;
        foreach ($bits as $j => $raw) {
            $hay = lrgDlgRewardToks((string) $raw);
            if ($hay) { $out[] = [$hay, lrgDlgRewardQuestion($hay, $j === $last && str_contains((string) ($parts[$i + 1] ?? ''), '?'))]; }
        }
    }
    return $out;
}

/** [pt19c-B fix 2] Is this clause (its tokens; $mark = it ended in "?") a question? See lrgDlgRewardClauses. */
function lrgDlgRewardQuestion(array $hay, bool $mark): bool
{
    $i = 0;
    while (isset($hay[$i]) && in_array($hay[$i], ['so', 'well', 'uh', 'um', 'er', 'and', 'okay', 'ok', 'then', 'now', 'hey', 'oh', 'look'], true)) { $i++; }
    $w0 = (string) ($hay[$i] ?? '');
    $w1 = (string) ($hay[$i + 1] ?? '');
    if (in_array("$w0 $w1", ['how about', 'what about', 'why not'], true)) { return false; }
    if ($mark || in_array($w0, ['what', 'why', 'how', 'who', 'where', 'when', 'which', 'is', 'are', 'was', 'am', 'isnt', 'arent', 'wasnt'], true)) {
        return true;
    }
    // an auxiliary opens a question only with a subject after it: "could you ...", "can I ..."; "do it for a bit more" is an order
    return in_array($w0, ['do', 'does', 'did', 'can', 'could', 'would', 'will', 'shall', 'should', 'may', 'might', 'have', 'has', 'dont',
        'doesnt', 'didnt', 'cant', 'couldnt', 'wouldnt', 'wont', 'shouldnt'], true)
        && in_array($w1, ['you', 'i', 'we', 'they', 'he', 'she', 'there', 'ye', 'anyone', 'anybody', 'someone', 'somebody'], true);
}

/**
 * [pt19c-B fix 2] Is HE the subject of the phrase that starts after $before (the clause's tokens before it)? Walking back over at
 * most four fillers ("really", "think", "was", "going to", "surely"), the next token is I / I'm / I've / I'll / I'd / we / we've. A
 * negation is no filler: "I don't deserve more" is no bargain; "Don't I deserve more?" is. Never inside a relative clause: "there
 * is nothing I want more", "is there anything I need more" (the word before his "I" is nothing / anything / what / which ...).
 */
function lrgDlgRewardSelf(array $before): bool
{
    $fill = ['think', 'really', 'truly', 'surely', 'certainly', 'definitely', 'honestly', 'clearly', 'still', 'also', 'just', 'only',
        'all', 'do', 'did', 'does', 'was', 'am', 'are', 'would', 'should', 'could', 'will', 'must', 'might', 'may', 'believe', 'feel',
        'guess', 'reckon', 'suppose', 'been', 'had', 'have', 'got', 'going', 'gonna', 'to', 'kind', 'of', 'kinda', 'sort', 'sorta', 'too',
        'so', 'even', 'very', 'much'];
    $k = count($before) - 1;
    for ($s = 0; $k >= 0 && $s < 4 && in_array($before[$k], $fill, true); $s++) { $k--; }
    return $k >= 0 && in_array($before[$k], ['i', 'im', 'ive', 'ill', 'id', 'we', 'weve'], true)
        && !in_array((string) ($before[$k - 1] ?? ''), ['nothing', 'something', 'anything', 'everything', 'what', 'which', 'who', 'whom',
            'thing', 'things', 'one', 'ones'], true);
}

/** [S6.1] The longest reward phrase of the five tiers that holds in one of his clauses (lrgDlgRewardAsk), or ''. */
function lrgDlgRewardPhrase(string $utter, array $cfg): string
{
    $list = static function (string $k) use ($cfg): array {
        $o = [];
        foreach ((array) ($cfg[$k] ?? []) as $w) { $tt = lrgDlgRewardToks((string) $w); if ($tt) { $o[] = $tt; } }
        return $o;
    };
    $money = [];
    foreach ($list('money') as $tt) { foreach ($tt as $tok) { $money[$tok] = 1; } }
    [$tails, $leads, $notWith, $inQ] = [$list('tails'), $list('leads'), $list('not_with'), $list('in_question')];
    $tiers = [];
    foreach (['words', 'first_person', 'ends', 'weak', 'with_money'] as $tier) {
        foreach ((array) ($cfg[$tier] ?? []) as $p) { $pt = lrgDlgRewardToks((string) $p); if ($pt) { $tiers[] = [(string) $p, $pt, $tier]; } }
    }
    $best = '';
    $bestN = 0;
    $clauses = lrgDlgRewardClauses($utter);
    foreach ($clauses as $ci => [$hay, $q]) {
        $info = false;
        foreach ($notWith as $nw) { if (lrgDlgTokenAt($hay, $nw) >= 0) { $info = true; break; } }
        foreach ($tiers as [$p, $pt, $tier]) {
            $n = count($pt);
            if ($n <= $bestN) { continue; }
            $at = lrgDlgTokenAt($hay, $pt);
            if ($at < 0) { continue; }
            $before = array_slice($hay, 0, $at);
            $rest = array_slice($hay, $at + $n);
            $hasMoney = false;
            foreach (array_merge($before, $rest) as $tok) { if (isset($money[$tok])) { $hasMoney = true; break; } }
            // [pt19c final fixer / completeness critic] every tier, the words tier too: an idiom, a waiver, a place, a third party
            // [pt19h-money round 2 / lang P3] ...but a NARRATIVE opening ("The reward was set at five hundred septims") that a later
            // clause of his DEMANDS ("..., pay up", "..., and I want my share", "..., so where is it?") is his bargain: only that one
            // occurrence was a report - the sentence is not
            if (lrgDlgRewardNotBargain($pt, $tier, $before, $rest, $q, $hasMoney)
                && !(lrgDlgRewardNarrative($pt, $before, $rest) && lrgDlgRewardDemandAfter($clauses, (int) $ci))) {
                continue;
            }
            if ($tier !== 'words') {
                $ends = $rest === [] || in_array($rest, $tails, true);
                $lead = false;
                foreach ($leads as $l) { if (array_slice($rest, 0, count($l)) === $l) { $lead = true; break; } }
                $ok = match ($tier) {
                    'first_person' => lrgDlgRewardSelf($before) && ($hasMoney || $ends),
                    'ends' => $hasMoney || $ends || $lead,
                    'weak' => $hasMoney || ($ends && !$info && ($rest !== [] || !$q || in_array($pt, $inQ, true))),
                    default => $hasMoney,
                };
                if (!$ok) { continue; }
            }
            $best = $p;
            $bestN = $n;
        }
    }
    return $best;
}

/**
 * [pt19c final fixer / completeness critic, S6.1 "ABSENT unless he bargains"] Quest talk that matched a reward phrase and is no
 * bargain ($pt the phrase's tokens, $tier its tier, $before / $rest his clause around it, $q the clause is a question, $hasMoney a
 * money word outside the phrase). True when:
 *  - IDIOM: a pay word followed by attention / respects / heed / homage / tribute / a visit / mind / dearly ("are you paying
 *    attention?", "will you pay your respects to the fallen?", "pay me no mind");
 *  - WAIVER: "for free" with no negation, no question and no "you / expect / want" before it ("I'll do it for free" waives the pay;
 *    "I won't do it for free", "you expect me to work for free?" bargain), or a pay phrase right after don't / no / needn't /
 *    never / not outside a question ("you don't have to pay me", "no need to pay me");
 *  - PLACE: no money word outside the phrase and the phrase is followed by a place ("more gold in the barrow", "what do I get out of
 *    the barrow") - in / at / on / from / inside / under / near / into / out of ... + the / a / his / their ... + a noun that is no
 *    deal ("out of the deal", "at the end" stay bargains);
 *  - THIRD PARTY (weak tier only, no money word): a word before the phrase that is not his own talk - a name, a noun, an activity
 *    verb ("Ulfric won't negotiate", "the guards are not enough", "let me explore a bit more"); every word before a weak phrase
 *    must be a pronoun, a filler, an auxiliary or a want / need / ask verb ("that's not enough", "can we negotiate?", "how about a
 *    little more?", "I'd like a bit more" stay bargains).
 */
function lrgDlgRewardNotBargain(array $pt, string $tier, array $before, array $rest, bool $q, bool $hasMoney): bool
{
    static $idiom = ['attention' => 1, 'respects' => 1, 'respect' => 1, 'heed' => 1, 'homage' => 1, 'tribute' => 1, 'visit' => 1,
        'mind' => 1, 'dearly' => 1];
    static $neg = ['not' => 1, 'no' => 1, 'never' => 1, 'wont' => 1, 'dont' => 1, 'doesnt' => 1, 'cant' => 1, 'isnt' => 1, 'aint' => 1,
        'arent' => 1, 'wouldnt' => 1, 'couldnt' => 1, 'shouldnt' => 1, 'nobody' => 1, 'noone' => 1, 'neednt' => 1];
    static $waive = ['dont' => 1, 'no' => 1, 'neednt' => 1, 'never' => 1, 'not' => 1];
    static $preps = ['in' => 1, 'at' => 1, 'on' => 1, 'from' => 1, 'inside' => 1, 'under' => 1, 'near' => 1, 'around' => 1, 'into' => 1,
        'behind' => 1, 'beyond' => 1, 'across' => 1, 'through' => 1, 'within' => 1, 'beneath' => 1, 'below' => 1, 'above' => 1];
    static $dets = ['the' => 1, 'a' => 1, 'an' => 1, 'those' => 1, 'these' => 1, 'his' => 1, 'her' => 1, 'their' => 1, 'some' => 1,
        'every' => 1, 'each' => 1, 'its' => 1, 'that' => 1];
    static $deal = ['deal' => 1, 'job' => 1, 'bargain' => 1, 'work' => 1, 'task' => 1, 'arrangement' => 1, 'contract' => 1, 'trouble' => 1,
        'quest' => 1, 'mission' => 1, 'errand' => 1, 'risk' => 1, 'favour' => 1, 'favor' => 1, 'business' => 1, 'agreement' => 1, 'end' => 1];
    static $talk = ['i' => 1, 'im' => 1, 'ive' => 1, 'ill' => 1, 'id' => 1, 'we' => 1, 'weve' => 1, 'well' => 1, 'wed' => 1, 'you' => 1,
        'youre' => 1, 'youll' => 1, 'youd' => 1, 'youve' => 1, 'me' => 1, 'us' => 1, 'it' => 1, 'its' => 1, 'that' => 1, 'thats' => 1,
        'this' => 1, 'theres' => 1, 'there' => 1, 'so' => 1, 'and' => 1, 'or' => 1, 'uh' => 1, 'um' => 1, 'er' => 1, 'okay' => 1, 'ok' => 1,
        'then' => 1, 'now' => 1, 'hey' => 1, 'oh' => 1, 'look' => 1, 'yes' => 1, 'yeah' => 1, 'no' => 1, 'fine' => 1, 'alright' => 1,
        'right' => 1, 'just' => 1, 'only' => 1, 'still' => 1, 'really' => 1, 'surely' => 1, 'simply' => 1, 'quite' => 1, 'maybe' => 1,
        'perhaps' => 1, 'how' => 1, 'about' => 1, 'what' => 1, 'why' => 1, 'not' => 1, 'lets' => 1, 'let' => 1, 'can' => 1, 'could' => 1,
        'would' => 1, 'will' => 1, 'shall' => 1, 'should' => 1, 'must' => 1, 'may' => 1, 'might' => 1, 'do' => 1, 'does' => 1, 'did' => 1,
        'dont' => 1, 'doesnt' => 1, 'didnt' => 1, 'wont' => 1, 'cant' => 1, 'couldnt' => 1, 'wouldnt' => 1, 'shouldnt' => 1, 'is' => 1,
        'are' => 1, 'was' => 1, 'be' => 1, 'am' => 1, 'isnt' => 1, 'arent' => 1, 'wasnt' => 1, 'have' => 1, 'has' => 1, 'had' => 1,
        'need' => 1, 'want' => 1, 'like' => 1, 'expect' => 1, 'expected' => 1, 'ask' => 1, 'asking' => 1, 'get' => 1, 'give' => 1,
        'offer' => 1, 'make' => 1, 'to' => 1, 'gonna' => 1, 'going' => 1, 'gotta' => 1, 'got' => 1, 'think' => 1, 'guess' => 1,
        'reckon' => 1, 'suppose' => 1, 'seems' => 1, 'sounds' => 1, 'at' => 1, 'least' => 1, 'even' => 1, 'all' => 1, 'much' => 1,
        'way' => 1, 'far' => 1, 'a' => 1, 'the' => 1, 'little' => 1, 'bit' => 1, 'for' => 1, 'sure' => 1, 'please' => 1, 'honestly' => 1,
        'doing' => 1, 'work' => 1, 'working' => 1, 'unless' => 1, 'if' => 1, 'when' => 1, 'once' => 1, 'until' => 1, 'before' => 1];
    $last = (string) end($pt);
    if (in_array($last, ['pay', 'pays', 'paying', 'paid'], true) || $pt === ['pay', 'me']) {
        if (isset($idiom[(string) ($rest[0] ?? '')]) || isset($idiom[(string) ($rest[1] ?? '')])) { return true; }
    }
    if (lrgDlgRewardNarrative($pt, $before, $rest)) { return true; }
    if ($last === 'free') {
        $bargain = $q;
        foreach ($before as $tok) { if (isset($neg[$tok]) || in_array($tok, ['you', 'expect', 'expected', 'want'], true)) { $bargain = true; break; } }
        if (!$bargain) { return true; }
    }
    if (in_array('pay', $pt, true) && !$q && !in_array('if', $before, true) && !in_array('unless', $before, true)) {
        foreach (array_slice($before, -3) as $tok) { if (isset($waive[$tok])) { return true; } }
    }
    if (!$hasMoney) {
        $i = in_array((string) ($rest[0] ?? ''), ['down', 'up', 'over', 'back'], true) ? 1 : 0;
        $prep = false;
        if (($rest[$i] ?? '') === 'out' && ($rest[$i + 1] ?? '') === 'of') { $i += 2; $prep = true; }
        elseif (isset($preps[(string) ($rest[$i] ?? '')])) { $i++; $prep = true; }
        if ($prep && isset($dets[(string) ($rest[$i] ?? '')]) && isset($rest[$i + 1]) && !isset($deal[(string) $rest[$i + 1]])) { return true; }
        if ($tier === 'weak') {
            foreach ($before as $tok) { if (!isset($talk[$tok])) { return true; } }
        }
    }
    return false;
}

/**
 * [pt19h-money round 2 / lang P3] Does a clause AFTER clause $ci of his DEMAND what the narrative one reported? After the fillers
 * so / then / and / now / well / but: an order to pay or hand it over ("pay up", "so pay me", "hand it over", "give it to me",
 * "cough it up", "fork it over"), "where is it / where's my ...", or his own want / claim of it ("I want it now", "I want my
 * share", "I need my cut", "I claimed it", "I'll take it", "I'd like it now").
 */
function lrgDlgRewardDemandAfter(array $clauses, int $ci): bool
{
    static $fill = ['so' => 1, 'then' => 1, 'and' => 1, 'now' => 1, 'well' => 1, 'but' => 1, 'okay' => 1, 'ok' => 1, 'just' => 1, 'uh' => 1, 'um' => 1,
        'right' => 1, 'yet' => 1, 'still' => 1];
    foreach ($clauses as $k => [$hay]) {
        if ((int) $k <= $ci) { continue; }
        $i = 0;
        while (isset($hay[$i]) && isset($fill[$hay[$i]])) { $i++; }
        $s = ' ' . implode(' ', array_slice($hay, $i)) . ' ';
        if (preg_match('/^ (?:pay (?:up|me|us|it|now|what)|hand (?:it|that|them|over)|give (?:it|me|us|that)|cough (?:it )?up|fork (?:it )?over|'
            . 'where(?:s| is| are)? (?:it|my|the|that|our)|wheres (?:it|my|the|that|our)) /', $s)) {
            return true;
        }
        if (preg_match('/ (?:i|we|id|ill|im|weve|ive) (?:still )?(?:want|need|demand|expect|deserve|claim|claimed|will take|take|would like|like|am owed|owed|'
            . 'ought to get|should get|get) (?:it|that|them|my|our|what|payment|to be paid|a share|a cut|the (?:reward|money|gold|bonus|bounty)|some of it|'
            . 'paid) /', $s)) {
            return true;
        }
    }
    return false;
}

/**
 * [pt19h-money / S6.1, measured leak] NARRATIVE: the reward is the SUBJECT of a report, not his ask - "The reward was posted in
 * Riften.", "a reward was offered for his head", "the reward for his capture has been claimed", "I heard the bonus was paid
 * out". Only when the phrase opens the clause (fillers, a determiner, or "I heard / they said" before it - never "I want the
 * reward that was promised"), no relative clause follows it, a be / have verb comes within six words and a report participle
 * right after it; a word of too little ("my reward was too small", "the reward is not enough") keeps it a bargain.
 * [pt19h-money round 2 / lang P3] its own function: lrgDlgRewardPhrase lets a later demand clause of his count (only this
 * occurrence was a report).
 */
function lrgDlgRewardNarrative(array $pt, array $before, array $rest): bool
{
    $last = (string) end($pt);
    if (in_array($last, ['reward', 'rewards', 'bonus', 'bounty'], true)) {
        static $okBefore = ['so' => 1, 'well' => 1, 'uh' => 1, 'um' => 1, 'er' => 1, 'and' => 1, 'oh' => 1, 'but' => 1, 'the' => 1, 'a' => 1,
            'an' => 1, 'that' => 1, 'this' => 1, 'his' => 1, 'her' => 1, 'their' => 1, 'its' => 1, 'jarls' => 1, 'i' => 1, 'we' => 1,
            'they' => 1, 'he' => 1, 'she' => 1, 'heard' => 1, 'hear' => 1, 'think' => 1, 'know' => 1, 'knew' => 1, 'saw' => 1, 'read' => 1,
            'said' => 1, 'say' => 1, 'says' => 1, 'told' => 1, 'guess' => 1, 'believe' => 1, 'suppose' => 1, 'apparently' => 1, 'looks' => 1,
            'like' => 1, 'seems' => 1];
        static $be = ['was' => 1, 'were' => 1, 'is' => 1, 'are' => 1, 'has' => 1, 'have' => 1, 'had' => 1, 'been' => 1, 'be' => 1, 'will' => 1,
            'got' => 1, 'gets' => 1, 'being' => 1, 'already' => 1, 'just' => 1, 'finally' => 1, 'recently' => 1, 'still' => 1];
        static $done = ['posted' => 1, 'offered' => 1, 'paid' => 1, 'given' => 1, 'claimed' => 1, 'set' => 1, 'put' => 1, 'placed' => 1,
            'announced' => 1, 'collected' => 1, 'handed' => 1, 'split' => 1, 'shared' => 1, 'sent' => 1, 'delivered' => 1,
            'spent' => 1, 'taken' => 1, 'stolen' => 1, 'earned' => 1, 'raised' => 1, 'issued' => 1, 'nailed' => 1, 'pinned' => 1, 'listed' => 1,
            'advertised' => 1, 'cancelled' => 1, 'canceled' => 1, 'withdrawn' => 1, 'lifted' => 1, 'won' => 1, 'hidden' => 1, 'kept' => 1];
        static $lack = ['not' => 1, 'no' => 1, 'too' => 1, 'enough' => 1, 'small' => 1, 'low' => 1, 'little' => 1, 'less' => 1, 'poor' => 1,
            'paltry' => 1, 'insult' => 1, 'insulting' => 1, 'meager' => 1, 'meagre' => 1, 'pathetic' => 1, 'nothing' => 1, 'more' => 1,
            'higher' => 1, 'bigger' => 1, 'better' => 1, 'larger' => 1, 'worth' => 1, 'never' => 1, 'isnt' => 1, 'wasnt' => 1];
        $opens = true;
        foreach ($before as $tok) { if (!isset($okBefore[$tok])) { $opens = false; break; } }
        // [pt19h-money review] a word of too little ANYWHERE in his clause keeps it a bargain ("the reward has been set too low" -
        // the participle came first and was read as a report); "promised" is no report either ("The reward was promised, where is
        // it?" asks for it, as "I want the reward that was promised" does)
        foreach ($rest as $tok) { if (isset($lack[$tok])) { $opens = false; break; } }
        if ($opens && !in_array((string) ($rest[0] ?? ''), ['that', 'which', 'who', 'you', 'i', 'we', 'he', 'she', 'they'], true)) {
            $sawBe = false;
            foreach (array_slice($rest, 0, 8) as $j => $tok) {
                if (isset($lack[$tok])) { break; }
                if (isset($be[$tok]) && $j <= 6) { $sawBe = true; continue; }
                if ($sawBe) { if (isset($done[$tok])) { return true; } break; }
            }
        }
    }
    return false;
}

/**
 * [pt19c-B fix 2 / game review] A NAMED SUM he bargains with: his words name a sum (lrgDlgNamedAmount) and, in one clause, a
 * `sum_frames` phrase sits RIGHT BESIDE a number - the frame before it with at most three fillers between ("make it two hundred
 * septims", "I'll do it for five hundred septims", "not for less than three hundred septims", "fifty septims? how about a
 * hundred?"), or, for a frame that does not start with "I", after it with at most three fillers between ("a hundred septims on
 * top", "fifty septims more", "two hundred septims and it's a deal"). A frame elsewhere in the sentence is no bargain: "I want to
 * find the hundred gold that was stolen".
 */
function lrgDlgRewardSum(string $utter, array $cfg): bool
{
    // [pt19h-money round 2 / lang P4] a VAGUE coin quantity is no figure (the bribe rail must not read "a few coins" as 3), but inside
    // a sum frame it is still his bargain: "I want a few coins for the trouble.", "I want a couple septims for this.", "I'll do it
    // for a few coins." (the frame test below reads couple / few / several as the number beside it)
    if (lrgDlgNamedAmount($utter) <= 0 && !preg_match('/\b(?:a\s+)?(?:few|couple(?:\s+of)?|several|handful\s+of)\s+(?:more\s+|extra\s+|gold\s+|silver\s+)?'
        . '(?:coins?|septims?|gold|drakes?)\b/i', $utter)) {
        return false;
    }
    $money = [];
    foreach ((array) ($cfg['money'] ?? []) as $w) { foreach (lrgDlgRewardToks((string) $w) as $tok) { $money[$tok] = 1; } }
    $numRx = '/^(?:#|' . (defined('LRG_MONEY_NUMWORD') ? LRG_MONEY_NUMWORD
        : '(?:one|two|three|four|five|six|seven|eight|nine|ten|twenty|thirty|forty|fifty|sixty|seventy|eighty|ninety|hundred|thousand)') . ')$/';
    $pre = ['for', 'than', 'a', 'an', 'at', 'least', 'another', 'only', 'just', 'about', 'around', 'the', 'me', 'you', 'it', 'us',
        'maybe', 'say', 'like', 'more', 'extra', 'even', 'any', 'less', 'no', 'of'];
    $post = ['and', 'its', 'it', 'is', 'a', 'then', 'we', 'have', 'got', 'thats', 'that', 'more', 'extra', 'as', 'all', 'up'];
    $frames = [];
    foreach ((array) ($cfg['sum_frames'] ?? []) as $f) { $ft = lrgDlgRewardToks((string) $f); if ($ft) { $frames[] = $ft; } }
    foreach (lrgDlgRewardClauses($utter) as [$hay]) {
        $h = count($hay);
        foreach ($frames as $ft) {
            for ($at = 0; $at + count($ft) <= $h; $at++) {
                if (array_slice($hay, $at, count($ft)) !== $ft) { continue; }
                $k = $at + count($ft);
                for ($s = 0; $k < $h && $s < 3 && in_array($hay[$k], $pre, true); $s++) { $k++; }
                if ($k < $h && preg_match($numRx, $hay[$k])) { return true; }
                if (in_array($ft[0], ['i', 'id', 'ill', 'im'], true)) { continue; }
                $k = $at - 1;
                for ($s = 0; $k >= 0 && $s < 3 && in_array($hay[$k], $post, true); $s++) { $k--; }
                if ($k >= 0 && (isset($money[$hay[$k]]) || preg_match($numRx, $hay[$k]))) { return true; }
            }
        }
    }
    return false;
}

/** [S6.1, lang P1] Are his words a line on her live list (the similarity matcher at its floor)? Then the list answers them. */
function lrgDlgRewardLineSaid(array $t, string $utter): bool
{
    $live = array_values(array_filter(array_merge((array) ($t['entries'] ?? []), (array) ($t['tail'] ?? [])),
        static fn($e) => (string) ($e['norm'] ?? '') !== '' && (string) ($e['class'] ?? '') !== 'hidden'));
    if (!$live || !function_exists('lrgDlgMatchText')) { return false; }
    $m = lrgDlgMatchText($utter, $live);
    return $m !== null && empty($m['short']) && (float) ($m['eff'] ?? $m['score'] ?? 0) >= (float) lrgDlgCfg('match.min_score', 0.55);
}

/**
 * [S6.1, S6.2 (1)] THE REWARD WINDOW, read-only in gate A: ['why', 'at'] or []. Open for checks.reward.window_seconds (180)
 * after a driven click that settled well and (a) the clicked row's topic matches *Reward*, or the clicked line or her answer to
 * it names the reward ("reward", "take this", "token of", "for your trouble"), (b) ev=result reported gold INTO his purse, or -
 * gate B only, $moved - (c) the matter moved on (a new journal row) for a quest she set him on or is an alias of (qgiver / qal).
 * [pt19c-B fix 1 / code review] (a) reads the topic EditorID too (Phase 2's result keeps it): "MQ104...Reward" whose line is
 * "I'm ready" opens the window. [ai review 5] (c) is NOT a gate-A trigger: accepting her quest adds a journal row as well, and the
 * reward line would then ride unprompted for three minutes after "I'll do it" - the Farengar bug S6.1 names.
 */
function lrgDlgRewardWindow(string $npc, bool $moved = false): array
{
    if ($npc === '') { return []; }
    $st = lrgDlgState($npc);
    $res = (array) ($st['last_result'] ?? []);
    if (!$res || !empty($res['refusal']) || empty($res['ok'])) { return []; }
    $at = (int) ($res['at'] ?? 0);
    if (lrgNow() - $at > max(10, (int) lrgDlgCfg('checks.reward.window_seconds', 180))) { return []; }
    if ((string) ($res['topic'] ?? '') !== '' && lrgGlob('*Reward*', (string) $res['topic'])) { return ['why' => 'a reward line', 'at' => $at]; }
    $said = [(string) ($res['entry'] ?? ''), (string) ($res['txt'] ?? '')];
    foreach ((array) ($st['lines'] ?? []) as $l) { if ((int) ($l['at'] ?? 0) >= $at - 6) { $said[] = (string) ($l['t'] ?? ''); } }
    foreach ($said as $s) {
        if ($s !== '' && preg_match('/\b(?:reward\w*|take this|token of|for your trouble)\b/i', $s)) { return ['why' => 'a reward line', 'at' => $at]; }
    }
    if ((int) ($res['gold'] ?? 0) > 0) { return ['why' => 'gold into his purse', 'at' => $at]; }
    if ($moved && ((int) ($st['qgiver'] ?? 0) === 1 || (array) ($st['qal'] ?? [])) && function_exists('lrgDlgNewQuestRows')
        && lrgDlgNewQuestRows($st, $at)) {
        return ['why' => 'the matter moved on', 'at' => $at];
    }
    return [];
}

/**
 * [S6.1] The `reward` locked line: only when he bargains or a reward window is open, never beside a market fact.
 * [pt19c-B fix 1 / ai review 2] ~190 chars as the lane budget says (it was 255): with a real faction line (Tullius 386, Rikke 412,
 * Galmar 350) the long one never fitted the 600-char body, and the short task line after it went too. The three clauses are kept.
 */
function lrgDlgRewardLine(): string
{
    return 'the reward is what the world gives: you cannot add septims, an item or a favour, or change it; if he bargains, say so'
        . ' and offer nothing; if a reward line is on his list, tell him to ask it';
}

/**
 * [S6.1, the truth backstop] A GiveGoldTo / TakeGoldFromPlayer wire line -> ['code', 'amt'] (0 = no amount), else null.
 * [pt19c-B fix 1 / CHIM review] ANY channel: CHIM writes herikaActionCatalogGetConfirmationCommandChannel's channel
 * (functions.php queueFunctionExecutionCommand) - TakeGoldFromPlayer's default 'ask' policy sends |confirmcommand|, automatic
 * |approvedcommand|, never |command|. A multi-property action's param is JSON (buildFunctionParameterValueFromResponse): the
 * amount is its `item` (or `amount`), never the first digits of the line - with no `item` those were the target's RefID.
 */
function lrgDlgRewardAct(string $line): ?array
{
    $parts = explode('|', rtrim($line, "\r\n"), 3);
    $cmd = explode('@', (string) ($parts[2] ?? ''), 2);
    $code = trim((string) ($cmd[0] ?? ''));
    if (!in_array(strtolower($code), ['givegoldto', 'takegoldfromplayer'], true)) { return null; }
    $param = trim((string) ($cmd[1] ?? ''));
    $j = str_starts_with($param, '{') ? json_decode($param, true) : null;
    $src = is_array($j) ? trim((string) ($j['item'] ?? ($j['amount'] ?? ''))) : $param;
    return ['code' => $code, 'amt' => preg_match('/\d[\d,]*/', $src, $a) ? (int) str_replace(',', '', $a[0]) : 0];
}

/**
 * [S6.2, gate B] A reward bargain the free check takes up: checks.reward.enabled, a quest turn, his bargaining words and an open
 * window (S6.2 (1) counts "the matter moved on" too). ['ask' => phrase, 'window' => why] or []. Gate A: always [] (enabled is
 * false).
 */
function lrgDlgRewardBargain(array $t, string $utter = ''): array
{
    if (empty(lrgDlgCfg('checks.reward.enabled', false))) { return []; }
    $npc = (string) ($t['npc'] ?? '');
    if ($utter === '') { $utter = (string) ((lrgDlgState($npc)['utter'] ?? [])['text'] ?? ''); }
    $ask = lrgDlgRewardAsk($utter, $t);
    if ($ask === '' || !lrgDlgQuestTurn($t)) { return []; }
    $win = lrgDlgRewardWindow($npc, true);
    return $win ? ['ask' => $ask, 'window' => (string) $win['why']] : [];
}

/**
 * [S6.2 (4), gate B] The bonus she may add: min(the named amount or round5(cap / 2), her purse from the snapshot (gold=), one
 * day of her wage (lrgDlgWage), checks.reward.max_gold). 0 = she carries no coin (no check runs; she says so).
 */
function lrgDlgRewardAmount(array $t, string $utter): int
{
    $purse = max(0, (int) (((array) ($t['snap'] ?? []))['gold'] ?? 0));
    $npc = (string) ($t['npc'] ?? '');
    $wage = max(0, (int) lrgDlgWage(lrgDlgProfileOf($t), $npc));
    $cap = min($purse, $wage, max(0, (int) lrgDlgCfg('checks.reward.max_gold', 500)));
    if ($cap <= 0) { return 0; }
    $named = lrgDlgNamedAmount(lrgDlgMoneyFold($utter));   // [pt19c-B fix 2 / lang P8]
    $n = $named > 0 ? min($named, $cap) : (int) (round($cap / 2 / 5) * 5);
    return max(1, $n);
}

/** How her own standing moves the band: profile, guard / merchant / hostile, and the affinity band. */
function lrgDlgStance(array $profile, array $turn): int
{
    $map = (array) lrgDlgCfg('checks.stance', []);
    $s = (int) ($map[strtolower((string) ($profile['id'] ?? ''))] ?? 0);
    $snap = (array) $turn['snap'];
    $fac = strtolower((string) ($snap['fac'] ?? ''));
    if (strpos($fac, 'guard') !== false) { $s = max($s, (int) ($map['guard'] ?? 2)); }
    if ((int) ($snap['combat'] ?? 0) === 1) { $s += (int) ($map['hostile'] ?? 2); }
    foreach ((array) lrgDlgCfg('checks.affinity_bands', []) as $upTo => $delta) {
        if (lrgDlgAffinity($turn) < (int) $upTo) { $s += (int) $delta; break; }
    }
    return $s;
}

function lrgDlgAffinity(array $turn): int
{
    $npc = (string) $turn['npc'];
    if (function_exists('lrgAffinity')) {
        try { return (int) lrgAffinity($npc, (array) $turn['snap']); } catch (Throwable $e) { return 0; }
    }
    return 0;
}

function lrgDlgAffBand(array $turn): int
{
    return (int) floor(lrgDlgAffinity($turn) / 20);
}

function lrgDlgProfileOf(array $turn): array
{
    if (function_exists('lrgBuildProfile')) {
        try { return (array) lrgBuildProfile((string) $turn['npc'], (array) $turn['snap']); }
        catch (Throwable $e) { return []; }
    }
    return [];
}

/** True when this NPC has already caught the player in a lie (owner: a caught lie is remembered). */
function lrgDlgCaughtLie(array $st, array $turn): bool
{
    foreach ((array) ($st['checks'] ?? []) as $c) {
        if ((string) ($c['kind'] ?? '') === 'deceive' && (string) ($c['result'] ?? '') === 'fail') {
            if (lrgDlgAffBand($turn) <= (int) ($c['aff'] ?? -99)) { return true; }
        }
    }
    return false;
}

/**
 * The live value of one Speech global, and where it came from. sg= arrives on lrg_dlg ev=open (and is
 * accepted on ev=facts too, additively). Only when the game has never sent it does the config fallback
 * speak, and the log line then says "(fallback)" so it can never be mistaken for a live read.
 */
function lrgDlgSpeechThreshold(array $st, string $globalName): array
{
    $sg = (array) (($st['facts'] ?? [])['sg'] ?? []);
    if (isset($sg[$globalName]) && (float) $sg[$globalName] > 0) { return [(float) $sg[$globalName], 'live']; }
    $fb = (array) lrgDlgCfg('checks.fallback_thresholds', []);
    return [(float) ($fb[$globalName] ?? 50), 'fallback'];
}

/** A free bribe's price, from the wage anchor and her band - not from a made-up formula. */
function lrgDlgBribePrice(array $turn, array $profile, int $band): int
{
    $wage = lrgDlgWage($profile, (string) ($turn['npc'] ?? ''), (array) ($turn['snap'] ?? []));
    $floor = (float) lrgDlgCfg('checks.bribe_floor_days', 0.3);
    $ceil = (float) lrgDlgCfg('checks.bribe_ceiling_days', 3.0);
    $days = $floor + ($ceil - $floor) * ($band / 4.0);
    $bamt = (int) (($turn['facts']['bamt'] ?? 0));
    if ($bamt > 0) { return $bamt; }      // the engine would price this NPC: use its own number
    $n = (int) round($days * $wage);
    $round = lrgDlgPriceRound();
    return (int) (max($round, (int) round($n / $round) * $round));
}

/**
 * The directive that hands the LLM the outcome as FACT (plan 6.11). Appended as the last lines INSIDE the
 * existing prompt_bottom block - never a second injection. It states the outcome and forbids the model from
 * narrating one of its own. NEVER a threshold, NEVER a skill number, NEVER "you would have needed 75".
 */
function lrgDlgCheckDirective(array $turn): string
{
    $c = $turn['check'] ?? null;
    if (!is_array($c)) { return ''; }
    if (!empty($c['reward'])) { return lrgDlgRewardTalk($c); }   // [pt19 v1.0 / S6.2, gate B] the bonus outcome, as fact
    $player = (string) ($GLOBALS['PLAYER_NAME'] ?? 'the player');
    $kind = (string) $c['kind'];
    $lines = [];
    if ((string) $c['result'] === 'ask' && !empty($c['pq'])) {
        // [pt19h-money round 2 / arch P5] he ASKED the price (a figure in his question is no offer): never "named no figure"
        $lines[] = $player . ' is asking what it would take. Name your price in your own words - around ' . (int) $c['N']
            . ' septims - and take nothing until he agrees to pay it.';
    } elseif ((string) $c['result'] === 'ask') {
        $lines[] = $player . ' is offering you gold but named no figure. Name what it would take, in your own'
            . ' words - around ' . (int) $c['N'] . ' septims - and take nothing until he agrees.';
    } elseif ((string) $c['result'] === 'pass') {
        if ($kind === 'bribe') {
            $lines[] = 'You took the gold: exactly ' . (int) $c['N'] . ' septims. The matter is settled in '
                . $player . "'s favour.";
        } elseif ($kind === 'intimidate') {
            $lines[] = 'The threat WORKED. You are afraid of ' . $player . ' and you give way. Do not pretend otherwise.';
        } elseif ($kind === 'deceive') {
            $lines[] = 'You BELIEVE him. Act on it as if it were true - you have no reason to doubt it.';
        } elseif ($kind === 'barter') {
            // no engine effect exists for this: say what she WILL do, never a number a shop cannot honour
            $lines[] = 'He talked you round on the price. Give ground in your own words and say what you'
                . ' will actually do for him - you cannot change what the counter charges.';
        } else {
            $lines[] = 'He convinced you. Treat the matter as settled in ' . $player . "'s favour and do not reopen it.";
        }
    } else {
        if ($kind === 'intimidate') {
            $lines[] = 'The threat FAILED. You are not afraid of ' . $player . '. Do not soften this.';
        } elseif ($kind === 'deceive') {
            $lines[] = 'You do NOT believe a word of it, and you remember being lied to. Do not soften this.';
        } elseif ($kind === 'bribe') {
            $lines[] = 'The offer was not enough and you took nothing. Do not soften this.';
        } elseif ($kind === 'barter') {
            $lines[] = 'He did not talk you down. Hold your price, in your own words. Do not soften this.';
        } else {
            $lines[] = 'It FAILED - he did not move you. Do not soften this, and do not change your mind unless'
                . ' something about ' . $player . ' changes.';
        }
        if ((string) $c['mem'] !== 'miss') { $lines[] = 'He has tried this on you before and you remember it.'; }
    }
    $lines[] = 'This is fact. Never state a rule, a number or a chance, and never announce an outcome of your own.';
    return "<what_just_happened>\n" . implode("\n", $lines) . "\n</what_just_happened>";
}

/**
 * [pt19 v1.0 / S6.2 (6), gate B] <reward_talk> (<= 350 chars, the window only): the outcome of his reward bargain, stated
 * pre-LLM as fact - the bonus in septims, a failure, no coin, or a reward that is fixed. She promises nothing beyond it.
 */
function lrgDlgRewardTalk(array $c): string
{
    $res = (string) ($c['result'] ?? '');
    if ($res === 'pass' && !empty($c['award']) && (int) ($c['give'] ?? 0) > 0) {
        $s = 'He talked you into more: you add ' . (int) $c['give'] . ' septims of your own to his reward, on top of what the world'
            . ' pays - say so in your own words. Nothing else is added.';
    } elseif ($res === 'fail') {
        $s = 'He did not move you: the reward stays what the world gives. Say so plainly and add nothing.';
    } elseif ($res === 'nocoin') {
        $s = 'You carry no coin to add to his reward. Say so plainly, and never promise to pay him later.';
    } else {
        $s = 'The reward is fixed: you add no item, house, title or favour, and nothing beyond what you already added. Say so.';
    }
    return "<reward_talk>\n" . $s . "\nThis is fact. Promise nothing beyond it.\n</reward_talk>";
}

// ================================================================== 2. quest-tree awareness
/**
 * <shared_business>, <= 3 clauses, prompt_bottom. q= / qobj= from the game joined to CHIM's questlog,
 * newest row per quest, only quests that have shown an objective. A quest in the journal but NOT in q is
 * never mentioned - that is what keeps this from becoming a spoiler feed.
 */
function lrgDlgQuestBlock(array $turn): string
{
    if (empty(lrgDlgCfg('quests.enabled'))) { return ''; }
    if (empty($turn['on'])) { return ''; }
    $q = array_values(array_filter((array) ($turn['q'] ?? [])));
    if (!$q) { return ''; }
    $rows = lrgDlgQuestRows($q);
    if (!$rows) { return ''; }
    $npc = (string) $turn['npc'];
    $player = (string) ($GLOBALS['PLAYER_NAME'] ?? 'the player');
    // iQuestLines, from the GAME when it sends it (PROTOCOL 10.10). 0 means "none at all", which is how
    // a game half that wants the block off says so without a second key.
    $max = (int) lrgDlgMcm('ql', (int) lrgDlgCfg('quests.lines', 3), $npc);
    if ($max <= 0) { return ''; }
    $chars = (int) lrgDlgCfg('quests.max_chars', 120);
    $out = [];
    foreach ($rows as $row) {
        if (count($out) >= $max) { break; }
        $id = (string) ($row['id_quest'] ?? '');
        if ($id !== '' && lrgAnyGlob((array) lrgDlgCfg('quests.bookkeeping_patterns', []), [$id])) { continue; }
        $obj = lrgDlgCleanObjective((string) ($row['briefing'] ?? ''), $npc);
        if ($obj === '') { continue; }
        if (strlen($obj) > $chars) { $obj = rtrim(substr($obj, 0, $chars - 1)) . '.'; }
        $out[] = 'You and ' . $player . ' have unfinished business: "' . $obj . '".';
    }
    if (!$out) { return ''; }
    // [0.5.0 / E2(e)] HER OWN PART in it, from PO3_SKSEFunctions.GetRefAliases on the game side
    // (wire W3 qal= / W4 qgiver=). One clause INSIDE this block, never a second injection, and only for
    // a quest the journal already knows - the no-spoiler rule of lrgDlgQuestKnown() still gates the name.
    $clause = lrgDlgAliasClause($turn, $rows);
    if ($clause !== '') { $out[] = $clause; }
    return '<shared_business>' . implode(' ', $out) . '</shared_business>';
}

/**
 * "You are the one who set him on this." / "In this, you are the Innkeeper." - facts an NPC would know
 * about HERSELF, never a spoiler about the quest. Empty when the game sent no qal= (an older script),
 * when no alias belongs to a quest the journal shows, or when the alias name carries no meaning.
 */
function lrgDlgAliasClause(array $turn, array $rows): string
{
    $player = (string) ($GLOBALS['PLAYER_NAME'] ?? 'the player');
    if ((int) ($turn['qgiver'] ?? 0) === 1) { return 'You are the one who set ' . $player . ' on this.'; }
    foreach ((array) ($turn['qal'] ?? []) as $pair) {
        $q = strtolower((string) ($pair['q'] ?? ''));
        $a = trim((string) ($pair['alias'] ?? ''));
        if ($q === '' || $a === '' || !isset($rows[$q])) { continue; }
        $words = trim((string) preg_replace('/([a-z0-9])([A-Z])/', '$1 $2', $a));
        $words = trim((string) preg_replace('/[^A-Za-z0-9 ]+/', ' ', $words));
        $words = trim((string) preg_replace('/\s+/', ' ', $words));
        if ($words === '' || strlen($words) < 3) { continue; }
        if (preg_match('/^(alias|ref|actor|target|npc)\b/i', $words)) { continue; }
        return 'In this, you are the ' . strtolower($words) . '.';
    }
    return '';
}

/**
 * newest row per quest from CHIM's questlog. The test seam $GLOBALS['LRG_DLG_TEST_QUESTLOG'] is
 * [id_quest => ['briefing' => .., 'stage' => .., 'at' => ..]] so the offline tests need no real table.
 * Returned in the order of $q, newest first within a quest.
 */
function lrgDlgQuestRows(array $q): array
{
    $want = [];
    foreach ($q as $id) { $want[strtolower((string) $id)] = (string) $id; }
    if (!$want) { return []; }
    $out = [];
    if (isset($GLOBALS['LRG_DLG_TEST_QUESTLOG'])) {
        foreach ((array) $GLOBALS['LRG_DLG_TEST_QUESTLOG'] as $id => $row) {
            $k = strtolower((string) $id);
            if (!isset($want[$k])) { continue; }
            $out[$k] = ['id_quest' => (string) $id] + (array) $row;
        }
        return $out;
    }
    $db = lrgDb();
    if (!$db) { return []; }
    $in = [];
    foreach ($want as $id) { $in[] = $db->escapeLiteral($id); }
    try {
        // [pt19c-B fix 1 / ai review 5] `at` = CHIM's localts (time(), processor/comm.php _uquest - the same clock as lrgNow): without
        // it lrgDlgNewQuestRows could only ever read the offline seam, and "The matter moved on" never reached a live prompt
        $rows = (array) $db->fetchAll('SELECT DISTINCT ON (id_quest) id_quest, briefing, stage, localts AS at FROM questlog'
            . ' WHERE id_quest IN (' . implode(',', $in) . ') ORDER BY id_quest, rowid DESC');
    } catch (Throwable $e) {
        lrgDlgLog('questlog join failed (CHIM journal): ' . $e->getMessage());
        return [];
    }
    foreach ($rows as $r) { $out[strtolower((string) ($r['id_quest'] ?? ''))] = (array) $r; }
    return $out;
}

/**
 * CHIM's DLL does not resolve objective-text replacement, so 48 live rows still carry unresolved tags.
 * <Alias=QuestGiver> -> the NPC's own name when she is the giver, else "the quest giver";
 * any other <Alias...=X> -> "the X"; <Global=...> -> "some". No stage numbers, ever.
 */
function lrgDlgCleanObjective(string $text, string $npc): string
{
    $s = trim($text);
    if ($s === '') { return ''; }
    $s = (string) preg_replace_callback('/<Alias(?:\.[A-Za-z]+)?=([^<>]{1,40})>/i', static function ($m) use ($npc) {
        $a = trim((string) $m[1]);
        if (stripos($a, 'questgiver') !== false) { return $npc !== '' ? $npc : 'the quest giver'; }
        $a = trim((string) preg_replace('/([a-z])([A-Z])/', '$1 $2', $a));
        return 'the ' . strtolower($a);
    }, $s);
    $s = (string) preg_replace('/<Global[^<>]*>/i', 'some', $s);
    $s = (string) preg_replace('/<[^<>]{1,40}>/', 'it', $s);
    $s = (string) preg_replace('/\s+/', ' ', $s);
    $s = str_replace('"', "'", $s);
    return trim($s);
}

// ================================================================== 3. "everyone has a price"
// DELETED in the 0.4.0 fix pass: lrgDlgPrice(), lrgDlgPriceWords(), lrgDlgPriceRemember() and
// lrgDlgWealthByGold(). They were a SECOND implementation of owner addendum 6 that nothing in
// production ever called - only tools/flows/dlg_adapter.php and scenario d41 did, so 15 green checks
// proved nothing about shipped behaviour. The live one is lrgPriceFor() (lib/lrg_core.php:835, called
// from the live turn at :1137); it honours the MCM paidok / pm keys, which the deleted twin did not,
// and it reads the paid_intimacy block of config/lrg_config.default.json rather than a private
// dialogue.paid_intimacy namespace whose keys (day_wage / band_by_wealth / a money influence that
// DIVIDED instead of multiplying) disagreed with it. PROTOCOL 10.7 and 10.9 now name lrgPriceFor().
// The wage anchor that a free-conversation bribe needs survives as lrgDlgWage() / lrgDlgPriceRound()
// above, and both read the live config ([pt15] lrgDlgWage = HER tier's day wage, lrgWageFor).
