<?php
/**
 * LoreRim Glue [0.3] - G1: the deterministic intent recogniser for the PLAYER's own words, and the
 * per-turn directive built from it. Contract: glue/PROTOCOL.md v0.3 sections 2 and 7.2.
 *
 * English, regex only, no LLM, no network, no DB write: it runs on every player turn, twice
 * (once pre-lock in lrgHandleGameMessage, once under the lock in lrgPrepareTurn).
 *
 * Two rails this file exists to hold:
 *  - the PLAYER is the speaker, so "your clothes" and "my clothes" mean the opposite of what they mean
 *    in the model's own item (lrgResolveClothing). That inversion is why "take my clothes off" undressed
 *    the wrong person in playtest 6; lrgIntentClothingWho() is the player-perspective resolver.
 *  - a recognised request is never a refusal and never a question: the blockers below decide what is NOT
 *    a request (owner addendum 3d), and only conf=high ever reaches the safety net.
 */

if (defined('LRG_INTENT_LOADED')) { return; }
define('LRG_INTENT_LOADED', true);

require_once __DIR__ . '/lrg_core.php';
require_once __DIR__ . '/lrg_scene_index.php';

/** Kinds that mean "the player asked for something the server could carry out". */
const LRG_INTENT_ACTIONABLE = ['stop', 'winddown', 'speed', 'faster', 'slower', 'hold', 'release', 'climax',
    'lead_npc', 'lead_player', 'furniture', 'dress', 'undress', 'act'];
/** Kinds whose verb is unambiguous on its own: they are high confidence without a request frame. */
const LRG_INTENT_ALWAYS_HIGH = ['stop', 'yes', 'no', 'faster', 'slower', 'speed', 'hold', 'release', 'climax',
    'winddown', 'lead_npc', 'lead_player'];
/**
 * [0.4 / OWNER ADDENDA 6] The money kinds. DELIBERATELY NOT IN LRG_INTENT_ACTIONABLE and deliberately
 * not in intent.net_kinds: neither lrgSafetyNet() nor lrgSecondCommand() may ever start a scene or move
 * a septim on its own. They resolve to no kv at all - they are words, and one bounded number.
 * [0.5.6 / pt15] 'withdraw' = the player takes back the offer she asked him to confirm ("no", "forget it").
 */
const LRG_INTENT_MONEY = ['offer', 'askprice', 'haggle', 'withdraw'];
/**
 * [0.5.4 / pt13] The ESCORT kind: "follow me", "wait here", "you can go back". One kind, the verb in
 * kv['do'] (follow | wait | release), so the turn line reads intent=escort/follow. DELIBERATELY not in
 * LRG_INTENT_ACTIONABLE (those are the scene's own verbs, and 'release' there already means "stop
 * holding back") and not in intent.net_kinds: walking with somebody is not intimacy, it has its own
 * directive and its own post-LLM carrier (ExtCmdLRG_Escort), and it is only ever recognised OUTSIDE a
 * running scene with this NPC - inside one "wait" is a hold and "come with me" is a climax.
 */
const LRG_INTENT_ESCORT = 'escort';
/** Code defaults of the `escort` config block (lrgEscortCfg()); the JSON file carries the same keys. */
const LRG_ESCORT_DEFAULTS = [
    'enabled' => true,
    'recognise' => true,
    'directive' => true,
    'emit' => true,
    // quest EditorID patterns (lrgGlob) whose running scene the GAME may stop to let her walk away
    'safe_quests' => ['BardSongs', 'BardSongsInstrumental', '*Idle*', '*Sandbox*', 'WI*'],
    // how old the snapshot may be for its `scene` / follower facts to decide an escort
    'snapshot_max_age_seconds' => 300,
    // with no fol= known: a faction on the snapshot's fac= that means "a follower framework owns her"
    'follower_factions' => ['CurrentFollowerFaction'],
    // the owner's own extra phrases, matched as whole words on the cleaned player line
    'phrases' => ['follow' => [], 'wait' => [], 'release' => []],
    'words' => ['follow' => 'you to come along', 'wait' => 'you to wait here',
        'release' => 'to let you go back to what you were doing'],
];
/** The nouns that mean coin. Shared by every money pattern so they can never drift apart.
 *  [pt19c final fixer] + the owner's STT spellings of septims (research/pt19c-language.md, lang P8): "septum(s)", "septem(s)",
 *  "septem's", "sept ums", "sept ems", "sep tims" - each read as no money word before, so the sum read 0.
 *  [pt19h-money] "pieces" only as coin ("pieces of gold", "gold pieces" reads through "gold"): "Here are all three pieces of the
 *  Razor." (DA07's own turn-in line) read 3 septims, and once the fast path had this reader the gate called it a bargain. */
const LRG_MONEY_WORD = '(?:gold|septims?|sept ?[eu]m\'?s?|sep tims?|coins?|pieces? of (?:gold|silver|eight)|drakes?)';
/**
 * The CLOSED number-word table, as one regex alternation. Closed on purpose: a septim figure is the one
 * recognised value that really leaves the player's purse, so it is never guessed at from loose words.
 */
const LRG_MONEY_NUMWORD = '(?:one|two|three|four|five|six|seven|eight|nine|ten|eleven|twelve|thirteen|fourteen'
    . '|fifteen|sixteen|seventeen|eighteen|nineteen|twenty|thirty|forty|fourty|fifty|sixty|seventy|eighty'
    . '|ninety|hundred|thousand|couple|few|several)';

/**
 * Imperative first word (request frame 1).
 * [0.3.1 / R2-D1] The single biggest cause in the playtest-7 audit: 92 of 654 matrix rows failed only
 * because the verb list was too short and the test was ^-anchored, so "now get down on your knees and
 * suck my deck" came out conf=LOW - which switched off the DO-IT directive, the safety net AND the
 * request ceiling in one go, and the request died in silence (F1). The list is now the verbs people
 * really use, and lrgIntentFrame() tests it on every clause, not only on the whole utterance.
 * [0.5.8 / pt19] + the shedding verbs "lose / ditch / shed / remove" ("lose the clothes", "remove your
 * armour") and "bare" ONLY as "bare yourself / me / all / it all" - "bare with me" (the bear/bare slip)
 * must never become a high-confidence undress.
 */
const LRG_INTENT_FRAME1 = '/^(please\s+)?(take|put|get|turn|bend|move|go|come|lie|lay|sit|stand|kneel|kiss|touch|suck|lick|fuck|ride|stroke|finger|grope|hold|undress|strip|dress|use|give|show|carry|bring|slow|speed|stop|keep|do|start|begin|try|grab|squeeze|rub|jerk|blow|hug|cuddle|snuggle|spoon|eat|mount|bounce|spread|pull|work|pound|push|play|slide|shove|climb|straddle|lean|drop|roll|flip|swallow|deepthroat|tease|finish|make|gimme|fondle|spank|massage|suck|smack|lose|ditch|shed|remove|bare(?= (?:yourself|me|all|it all)\b))\b/';

// ---------------------------------------------------------------- cleaning
/** The player's utterance, ready to match on: no DLL context prefix, no "Name: " prefix, lowercase, collapsed. */
function lrgIntentClean(string $utterance): string
{
    $t = lrgStripContext($utterance);
    // CHIM only strips the speaker prefix for instruction / suggestion (main.php:1092), so player speech
    // still carries "<Player>: ". The pattern is the one lrgIsTickText() already uses.
    $t = (string) preg_replace('/^[^:]{1,40}:\s*/', '', $t);
    $t = strtolower(trim($t));
    // [0.5.6 / pt15] a figure survives the punctuation strip: "1,000" is one thousand (not "1 000"), and
    // "1k" / "2k" is spoken shorthand for thousands
    $t = (string) preg_replace('/(?<=\d),(?=\d{3}(?!\d))/', '', $t);
    // [pt19h-money] ...and the other spoken multipliers, BEFORE the point is stripped: "1.5 thousand" (it became "1 5 thousand",
    // read 5,000), "2.5k", "two grand" (lrgIntentMultFold - the amount parser's own helper)
    $t = lrgIntentMultFold($t);
    $t = (string) preg_replace('/[^a-z0-9?\'\s]+/', ' ', $t);
    return trim((string) preg_replace('/\s+/', ' ', $t));
}

/** Strip a leading "tell me," / "so," / "hey," lead-in before judging whether this is a question (m8). */
function lrgIntentLeadIn(string $t): string
{
    return (string) preg_replace('/^(tell me|so|hey|listen|say)[,\s]+/', '', $t);
}

// ---------------------------------------------------------------- blockers (addendum 3d)
/** True when this reads as a question rather than an instruction. */
function lrgIntentQuestion(string $t): bool
{
    $lead = lrgIntentLeadIn($t);
    if (preg_match('/^(should|do i|do you|did|does|are|is|was|were|have i|has|what|how|why|when|who|which|where|can i|could i|may i|would you ever)\b/', $lead)) { return true; }
    // a trailing question mark is only a question when no imperative verb opens the sentence
    return str_ends_with(trim($t), '?') && !preg_match(LRG_INTENT_FRAME1, $lead);
}

/**
 * True when one of the polite-request frames matched: "can you ..." is a request, not a question.
 * [0.3.1 / D11] "can i / may i / could i" is the same thing from the other side ("can i lick you") and
 * was being thrown away by the question blocker.
 * [0.5.8 / pt19] the invitation frames (LRG_INTENT_INVITE) are polite frames too, so "why not get
 * undressed?", "won't you take your clothes off?" and the speech-to-text "why don t you get naked" are
 * no longer thrown away as questions either.
 */
function lrgIntentPolite(string $t): bool
{
    return (bool) preg_match('/\b(can|could|will|would) you\b|\b(can|could|may) i\b|\bmind if\b|\bwould you like to\b/', $t)
        || (bool) preg_match(LRG_INTENT_INVITE, $t);
}

/**
 * [0.5.8 / pt19] The INVITATION frames: "why don't you X", "why not X", "won't you X", "how about you X"
 * ASK for X - the don't / not / won't they carry is not a refusal. "well we're in a private room already
 * why don't you get naked?" (2026-09-23 00:17:38) was logged intent=none why=negated because
 * lrgIntentNegated() saw the don't. lrgIntentUninvite() rewrites the frames to "please" before the
 * negation test (the escort clause has done the same for its own text since 0.5.4).
 * A frame counts ONLY when an action follows it: "why don't you WANT / LIKE / EVER / HAVE ... X" is a
 * question about her, "why don't you NOT X" / "why not NOT X" is a refusal (a double negative) and
 * "why WON'T you X" is a complaint - they keep their don't / won't and stay blocked. The complaint keeps
 * its reading however it is intensified ("why the hell won't you strip", "why on earth won't you get
 * naked"): a "won't you" counts as an invitation only when no "why" stands earlier in ITS clause (from
 * the start of the text or the last , ; ? . ! - of which only `?` survives lrgIntentClean(), so on the
 * whole-utterance path the clause is the text or the last `?`; the clause rescue (lrgIntentClauses) has
 * already split on the raw commas before this runs). PCRE2 10.42 has no variable-length lookbehind, so that
 * is written as a clause-anchored `\K` (the match starts at the "won't"), and it lives in this ONE
 * constant on purpose: lrgIntentPolite(), lrgIntentUninvite() and lrgIntentClothingWho() all read it,
 * so they can never disagree on what is a frame. "just" is an intensifier, not a stative ("why don't you
 * just get naked" asks). Every other negation is untouched: "don't X", "do not X", "never X", "i don't
 * want you to X". A clause that merely OPENS with "not" ("not so fast kiss me first") is deliberately NOT
 * a negation: speech-to-text gives no commas, and the request after it must survive.
 */
const LRG_INTENT_STATIVE = '(?:not|want|wanna|like|love|need|ever|have|think|know|care|feel|seem|mind|trust|believe|understand|see|admit|just admit|say|tell me)';
const LRG_INTENT_INVITE = '/\bwhy (?:don\'?t|don t|not) (?:you|we|ya)\b(?! ' . LRG_INTENT_STATIVE . '\b)'
    . '|\bwhy not\b(?! not\b)'
    . '|(?:^|[,;?.!])(?:(?!\bwhy\b)[^,;?.!])*?\K\bwon(?:\'?t| t) you\b(?! ' . LRG_INTENT_STATIVE . '\b)'
    . '|\bhow about (?:you|we)\b/';

/** The cleaned utterance with its invitation frames read as the "please" they are. */
function lrgIntentUninvite(string $t): string
{
    return trim((string) preg_replace('/\s+/', ' ', (string) preg_replace(LRG_INTENT_INVITE, 'please', $t)));
}

/**
 * The negation test on its own, so it can also be applied to the FRAGMENT the scan matched.
 * [pt19] judged on the un-invited text, so every caller reads an invitation as the request it is: the
 * whole utterance (lrgIntentBlocked), the clause rescue, lrgIntentScanOrdered, lrgIntentExtra, the
 * money pre-scan (lrgIntentMoney) and the escort clause (lrgEscortClause).
 */
function lrgIntentNegated(string $t): bool
{
    return (bool) preg_match('/\b(don\'?t|do not|never|rather not|no need to|not yet|maybe later|hold off|stop asking)\b/', lrgIntentUninvite($t));
}

/** Why this utterance is not a request at all, '' = it may be one. */
function lrgIntentBlocked(string $t): string
{
    if (preg_match('/\b(what if|imagine|pretend|suppose|if i |if you |would you ever|have you ever|do you ever|what would|one day|some ?day)\b/', $t)) { return 'hypothetical'; }
    if (preg_match('/\b(you said|i said|she said|he said|they said|told (me|you)|remember when)\b/', $t)) { return 'quoted'; }
    if (lrgIntentNegated($t)) { return 'negated'; }
    if (lrgIntentQuestion($t) && !lrgIntentPolite($t)) { return 'question'; }
    return '';
}

/**
 * An utterance that IS the affirmation, and nothing else. A pending proposal (R11) is a yes/no question,
 * and the old test only anchored the FIRST word: "okay, hold on" - the player asking her to wait - answered
 * yes to a sex act, and "ok, so what were you saying" did too. Anything that carries more than the
 * affirmation falls through to the ordinary scan instead, where "hold on" becomes a hold.
 */
function lrgIntentBareYes(string $t): bool
{
    $t = trim($t, " \t,.!?");
    return (bool) preg_match('/^(?:please\s+)?(yes|yeah|yea|yep|yup|sure|ok|okay|alright|all right|gods? yes|fuck yes|mhm|uh ?huh|do it|go ahead|please do|yes please|i want that|i\'?d like that|let\'?s do it|lets do it|sounds good|absolutely|of course)(\s+(please|now|then|love|baby|darling))*$/', $t);
}

/**
 * The utterance with its leading stop clause removed, '' when nothing else is left. LRG_INTENT_FRAME1 is
 * ^-anchored, so a request that does not OPEN the sentence never counted as framed and "that's enough,
 * fuck me" ended the scene. Judging the frame on the remainder fixes that - and judging it on the
 * remainder ONLY also closes the opposite hole: `stop` is itself an LRG_INTENT_FRAME1 verb, so every
 * utterance that began with it counted as framed, and "stop, I can't take any more of this" could be
 * overridden by an incidental act word.
 */
function lrgIntentAfterStop(string $t): string
{
    // the optional gerund is the "enough TALKING" idiom: it belongs to the stop clause, not to the request
    return trim((string) preg_replace('/^.*?\b(?:(?:that\'?s|thats)\s+enough|enough|no more|stop it|stop this|stop|halt|quit|end (?:it|this|the scene))\b[\s,.!]*(?:\w+ing\b[\s,.!]*)?/', '', $t, 1));
}

/**
 * The utterance split into cleaned CLAUSES, or [] when it is a single clause. lrgIntentClean() throws
 * commas away, so the split has to be made on the raw text - which is the whole point: "don't stop,
 * harder" is a refusal and a request, and only the first clause is negated.
 */
function lrgIntentClauses(string $utterance): array
{
    $parts = (array) preg_split('/[,;]+|\s+\bbut\b\s+/i', $utterance);
    if (count($parts) < 2) { return []; }
    $out = [];
    foreach ($parts as $p) {
        $c = lrgIntentClean((string) $p);
        if ($c !== '') { $out[] = $c; }
    }
    return count($out) > 1 ? $out : [];
}

/**
 * [0.3.1 / R2-D8] The utterance split into the pieces a COMPOUND request is made of, [] when there is
 * only one. lrgIntentClauses() splits on punctuation only, which is why "take my clothes off and then
 * let's book a missionary" lost its second half entirely (playtest 7 F2): the scan returns on the first
 * pattern that matches, and everything after "and then" was never looked at.
 * Split on "and then" / "then" / "after that" / "," / ";" / " and " - the last one only when both sides
 * carry something, so "you and me" and "harder and deeper" stay whole.
 */
function lrgIntentParts(string $utterance): array
{
    $raw = ' ' . trim((string) preg_replace('/\s+/', ' ', $utterance)) . ' ';
    $pieces = (array) preg_split('/[,;]+|\s+\band then\b\s+|\s+\bafter that\b\s+|\s+\bthen\b\s+|\s+\band\s+(?=(?:i|we|you|let|then|now|please|make|take|get|put|turn|bend|go|come|lie|lay|sit|stand|kneel|kiss|suck|lick|fuck|ride|stroke|finger|grope|hold|undress|strip|dress|give|start|begin|try|grab|squeeze|rub|jerk|blow|hug|cuddle|spoon|eat|mount|bounce|spread|pull|work|pound|push|play|do)\b)/i', $raw);
    if (count($pieces) < 2) { return []; }
    $out = [];
    foreach ($pieces as $p) {
        $c = lrgIntentClean((string) $p);
        if ($c !== '' && preg_match('/[a-z]{2}/', $c)) { $out[] = $c; }
    }
    return count($out) > 1 ? $out : [];
}

/**
 * The STOP rail of the recogniser - deliberately NOT lrgResolveControl's rail, which was written for the
 * model's deliberate keyword and would end the scene on "stop teasing me and fuck me" or "I can't get
 * enough of you". Only a standalone stop counts. lrgResolveControl keeps its own rail unchanged.
 */
function lrgIntentStop(string $t): bool
{
    $t = lrgIntentUninvite(trim($t, " \t?.!"));   // [pt19] "why don't you stop" is "please stop" (the gerund guard below still holds)
    if (preg_match('/\b(can\'?t get|cannot get|never|not)\s+enough\b/', $t)) { return false; }
    if (preg_match('/^(?:please\s+)?(stop it|stop this|stop|halt|quit)\b(?!\s+\w+ing\b)/', $t)) { return true; }
    if (preg_match('/\b(we|let\'?s|i)\s+(should|need to|want to|have to)\s+stop\b/', $t)) { return true; }
    if (preg_match('/\b(that\'?s|thats)\s+enough\b|^enough$|\bno more\b|\bend (it|this|the scene)\b/', $t)) { return true; }
    return false;
}

// ---------------------------------------------------------------- [0.4] money, from the PLAYER's mouth
/**
 * The value of a spelled-out number phrase, or 0. Small grammar on purpose: units, tens, "X hundred",
 * "X thousand", "a/one", and the three vague quantifiers the owner really says ("a couple hundred" 200,
 * "a few hundred" 300, "several hundred" 500). Anything it does not understand ends the phrase.
 */
function lrgWordAmount(string $phrase): int
{
    static $tbl = null;
    if ($tbl === null) {
        $tbl = ['one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5, 'six' => 6, 'seven' => 7,
            'eight' => 8, 'nine' => 9, 'ten' => 10, 'eleven' => 11, 'twelve' => 12, 'thirteen' => 13,
            'fourteen' => 14, 'fifteen' => 15, 'sixteen' => 16, 'seventeen' => 17, 'eighteen' => 18,
            'nineteen' => 19, 'twenty' => 20, 'thirty' => 30, 'forty' => 40, 'fourty' => 40, 'fifty' => 50,
            'sixty' => 60, 'seventy' => 70, 'eighty' => 80, 'ninety' => 90];
    }
    // [pt19h-money / STT sums] the price-tag reading: a ones or teens word straight before a tens word is that many hundreds
    // ("one fifty" 150 - it read 51; "two fifty" 250, "twelve fifty" 1,250, "one twenty five" 125); "twenty five" stays 25.
    // "a" before a number word is no 1 of its own ("a fifty" is 50 - it read 51); "a hundred" / "a thousand" are unchanged.
    // The three vague quantifiers are a figure only before hundred / thousand ("a few hundred" 300): "a few coins" / "a couple of
    // septims" name no figure (it read 3 - and the bribe rail then refused "maybe a few coins would help?" as "he offered 3").
    // [pt19h-money round 2 / lang P2] ...only when the two words are ADJACENT: "and" between them is the archaic count, "five and
    // twenty" 25 (it read 520 - an overstated sum that passed a 40-septim free bribe and a 200-septim engine bribe), "one and
    // twenty" 21; "one hundred and fifty" stays 150
    $n = 0; $cur = 0; $any = false; $small = false; $fromA = false; $vague = false;
    foreach (preg_split('/[\s\-]+/', trim($phrase)) ?: [] as $w) {
        if ($w === 'and') { $small = false; continue; }
        if ($w === '') { continue; }
        if ($w === 'a' || $w === 'an') { $cur = 1; $fromA = true; $small = false; continue; }   // "a" alone is never a number
        if ($w === 'couple') { $cur = 2; $any = true; $small = $fromA = false; $vague = true; continue; }
        if ($w === 'few') { $cur = 3; $any = true; $small = $fromA = false; $vague = true; continue; }
        if ($w === 'several') { $cur = 5; $any = true; $small = $fromA = false; $vague = true; continue; }
        if ($w === 'hundred') { $n += max(1, $cur) * 100; $cur = 0; $any = true; $small = $fromA = $vague = false; continue; }
        if ($w === 'thousand') { $n = max(1, $n + $cur) * 1000; $cur = 0; $any = true; $small = $fromA = $vague = false; continue; }
        if ($vague) { break; }
        if (isset($tbl[$w])) {
            $v = $tbl[$w];
            if ($fromA) { $cur = 0; }
            if ($small && $v >= 20 && $cur >= 1 && $cur <= 19) { $n += $cur * 100; $cur = 0; }
            $small = !$fromA && $cur === 0 && $v <= 19;
            $cur += $v; $any = true; $fromA = false;
            continue;
        }
        break;
    }
    if ($vague) { return $n; }   // [pt19h-money] a quantifier with no hundred / thousand after it adds nothing
    return $any ? $n + $cur : 0;
}

/**
 * [pt19c final fixer / the STT sum root] A sum as the owner's STT writes it, made readable for lrgIntentAmount: a digit group
 * before hundred / thousand becomes its words ("5 hundred gold" -> "five hundred gold" - the digit was dropped and 100 read;
 * "25 hundred septims" -> "twenty five hundred", 2,500), and a thousands comma between digits goes ("2,000 septims" read "000").
 * Lower-cased: every money pattern here is written lower-case. Nothing else in the sentence moves.
 */
function lrgIntentSumFold(string $t): string
{
    $t = lrgIntentMultFold(strtolower($t));   // [pt19h-money] "1.5 thousand", "2k", "two grand" first
    // [pt19h-money round 2 / lang P8] "a couple of hundred septims" is 200 (the "of" ended the number phrase: it read 100, and a
    // 200-septim bribe was refused as "offered less than it costs"); "a few of" likewise. "a couple of septims" stays no figure.
    $t = (string) preg_replace('/\b(couple|few)\s+of\s+(hundred|thousand)\b/', '$1 $2', $t);
    if (!preg_match('/\d/', $t)) { return $t; }
    $t = (string) preg_replace('/(?<=\d),(?=\d{3}\b)/', '', $t);
    // [pt19h-money] never the digits after a point: "1.5 thousand" is folded above, and "1.five thousand" read 5,000
    return (string) preg_replace_callback('/(?<![\d.,])\b(\d{1,2})\s+(hundred|thousand)\b/', static function (array $m): string {
        $ones = ['', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten', 'eleven', 'twelve', 'thirteen',
            'fourteen', 'fifteen', 'sixteen', 'seventeen', 'eighteen', 'nineteen'];
        $tens = ['', '', 'twenty', 'thirty', 'forty', 'fifty', 'sixty', 'seventy', 'eighty', 'ninety'];
        $n = (int) $m[1];
        if ($n <= 0) { return $m[0]; }
        return ($n < 20 ? $ones[$n] : trim($tens[intdiv($n, 10)] . ' ' . $ones[$n % 10])) . ' ' . $m[2];
    }, $t);
}

/**
 * [pt19h-money / STT sums] The spoken MULTIPLIERS, as plain digits, before anything strips a point or reads a word alone:
 *  - a decimal before thousand / hundred / k / grand: "1.5 thousand septims" 1500 (the point was a word boundary: "1.five
 *    thousand" read 5,000 - an OVERSTATED sum), "2.5k" 2500, "1,5 thousand" (a decimal comma, one or two digits) 1500;
 *  - "k" after digits: "2k gold" 2000, "10 k" 10000;
 *  - "grand" after a number is a thousand septims and names the coin itself: "two grand" -> "two thousand septims", "5 grand" ->
 *    "5 thousand septims", "a couple grand"; "a grand" only as a sum (the end of the clause, or before for / and / now / up
 *    front / to / if / or / in / on / each ...), never "a grand hall", "a grand feast", "the grand total".
 * Lower-case in (the callers lower-case first), lower-case out; a sentence with no digit and no "grand" is returned as it is.
 */
function lrgIntentMultFold(string $t): string
{
    if (!preg_match('/\d|\bgrand\b/', $t)) { return $t; }
    $t = (string) preg_replace_callback('/(?<![\d.,])(\d{1,3})(?:\.(\d{1,3})|,(\d{1,2}))\s*(k|thousand|grand|hundred)\b/',
        static function (array $m): string {
            $frac = $m[2] !== '' ? $m[2] : $m[3];
            $v = (int) round((float) ($m[1] . '.' . $frac) * ($m[4] === 'hundred' ? 100 : 1000));
            return $v . ($m[4] === 'grand' ? ' septims' : '');
        }, $t);
    $t = (string) preg_replace('/(?<![\d.,])\b(\d{1,3})\s?k\b/', '${1}000', $t);
    if (str_contains($t, 'grand')) {
        $w = 'one|two|three|four|five|six|seven|eight|nine|ten|eleven|twelve|thirteen|fourteen|fifteen|sixteen|seventeen|eighteen'
            . '|nineteen|twenty|thirty|forty|fourty|fifty|sixty|seventy|eighty|ninety|hundred';
        // a sum, not an adjective: the clause ends, or a word follows that no noun is ("two grand for the sword", "a grand, now")
        $as = '(?=\s*(?:$|[.,!?;:\'"]|(?:for|and|now|then|up|upfront|to|if|or|in|on|each|a|it|is|was|right|tops|max|cash|please|more|extra'
            . '|total|plus|but|so|from|of|at|with|you|he|she|they|we|i|should|would|will|could|might|septims?|gold|coins?)\b))';
        $t = (string) preg_replace('/\b(\d{1,3}|(?:a\s+)?(?:couple|few)|several|(?:' . $w . ')(?:[\s\-]+(?:and\s+)?(?:' . $w . '))*)\s+grand\b' . $as . '/',
            '$1 thousand septims', $t);
        // [pt19h-money review] the bare "a grand" is the ADJECTIVE before "and" / "total" ("what a grand and glorious day", "a grand
        // total of fifty gold" read 1,000 septims - a false offer on the paid-intimacy reader); a number word keeps the full list
        // [pt19h-money round 2 / lang P1] the bare "a grand" is a sum ONLY at the end of a clause (never at a comma: "What a grand,
        // beautiful city." became "what a thousand septims beautiful city") or before a word no attributive adjective takes - for,
        // each, apiece, up front, tops, now, then, more, extra, the coin, "at most", "in total"; never before and, a comma, total,
        // at, in or of ("a grand total of fifty septims" took 1,000 on the free bribe and passed a 200-septim engine bribe)
        $bare = '(?=\s*(?:$|[.!?;:\'"]|(?:for|each|apiece|up\s*front|upfront|tops|max|cash|now|then|please|plus|more|extra|or so|at most|'
            . 'in total|in all|if|to|is|was|will|would|should|it|septims?|gold|coins?)\b))';
        $t = (string) preg_replace('/\ban?\s+grand\b' . $bare . '/', 'a thousand septims', $t);
    }
    return $t;
}

/**
 * The amount of septims in an utterance, or 0.
 * $abLoose = a money FRAME is already established ("I'll pay you 200 to ..."), so a bare number counts.
 * Without it A NUMBER ALONE IS NEVER AN AMOUNT - "fifty" on its own buys nothing.
 */
function lrgIntentAmount(string $t, bool $abLoose = false): int
{
    $t = lrgIntentSumFold($t);
    $mw = LRG_MONEY_WORD;
    $num = LRG_MONEY_NUMWORD;
    $phrase = '(?:a|an|and|' . $num . ')(?:[\s\-]+(?:a|an|and|' . $num . '))*';
    // 1. a numeral beside a money word, either order
    if (preg_match('/\b(\d{1,6})\s*' . $mw . '\b/', $t, $m)) { return (int) $m[1]; }
    if (preg_match('/\b' . $mw . '\b[^0-9]{0,12}(\d{1,6})\b/', $t, $m)) { return (int) $m[1]; }
    // 2. a spelled-out number beside a money word, either order
    foreach (['/\b(' . $phrase . ')[\s\-]*' . $mw . '\b/', '/\b' . $mw . '\b[^0-9]{0,12}?\b(' . $phrase . ')\b/'] as $rx) {
        if (preg_match($rx, $t, $m) && preg_match('/\b' . $num . '\b/', (string) $m[1])) {
            $v = lrgWordAmount((string) $m[1]);
            if ($v > 0) { return $v; }
        }
    }
    if (!$abLoose) { return 0; }
    // 3. inside a money frame a bare number is the amount
    if (preg_match('/\b(\d{1,6})\b/', $t, $m)) { return (int) $m[1]; }
    // [pt15] the FIRST phrase that really carries a number word - "and i'll give you fifty" used to stop
    // at the leading "and" (a phrase of its own) and read 0
    if (preg_match_all('/\b(' . $phrase . ')\b/', $t, $mm)) {
        foreach ($mm[1] as $p) {
            if (preg_match('/\b' . $num . '\b/', (string) $p)) {
                $v = lrgWordAmount((string) $p);
                if ($v > 0) { return $v; }
            }
        }
    }
    return 0;
}

/**
 * [0.4.0 fix pass] IS THE PLAYER BUYING GOODS OR A SERVICE, rather than talking about her?
 *
 * The money pre-scan had no test for WHAT is being bought, so ordinary commerce was read as paying for
 * sex. Traced against the deployed plugin: "Here is two hundred septims for the room and the food." to an
 * innkeeper produced "That is at or above what Brenna would take ... choose BeginIntimacy with amount 200
 * in the same reply"; "I will give you thirty gold for the sword." produced "That is below what she would
 * take"; "How much do you want for it?" to a jarl produced "name a figure at or above 105000 septims".
 * All three at affinity 0, to an innkeeper, a commoner and a jarl.
 *
 * THREE SIGNALS, all of them things you can only say about a THING:
 *  1. a trade word - buy, sell, trade, barter, wares, shop, stock, merchandise, purchase;
 *  2. "for the <goods noun>" - the room, the night's board, the sword, the horse, the lot, the food;
 *  3. an OBJECT frame - "how much do you want for it", "what do you charge for that", and a figure
 *     attached to an object pronoun ("thirty for it"). "I'll pay you well for it" is NOT one of these:
 *     there is no figure and no asking verb, and in her company "it" is what he is asking her for.
 * A live price quote from HER overrides all three: once she has named a figure, the subject is settled.
 */
function lrgIntentGoodsTalk(string $t): bool
{
    // 1. an unmistakable trade word
    if (preg_match('/\b(buy|buys|buying|bought|sell|sells|selling|sold|resell|trade|trading|barter|bartering'
        . '|wares|merchandise|inventory|stock|shop|shopping|storefront|purchase|purchasing|goods'
        . '|for sale|in stock|your stall|your shelf|your shelves)\b/', $t)) { return true; }
    // 2. "... for the room and the food", "thirty gold for the sword"
    $goods = 'rooms?|beds?|board|lodging|stay|meals?|food|supplies|drinks?|ale|mead|wine|bottles?|bread|stew'
        . '|swords?|blades?|daggers?|axes?|mace|bows?|arrows?|shields?|armou?r|helmets?|boots|gauntlets?'
        . '|horses?|carriages?|carts?|ferry|ride|passage|potions?|ingredients?|soul gems?|ores?|ingots?'
        . '|books?|lockpicks?|rings?|amulets?|necklaces?|circlets?|lanterns?|torches?|maps?|keys?'
        . '|lot|whole lot|pelts?|furs?|hides?|arrowheads?|tools?|repairs?|training|lessons?';
    if (preg_match('/\bfor (?:the |that |this |a |an |your |these |those |some |all )*(?:' . $goods . ')\b/', $t)) { return true; }
    if (preg_match('/\bhow much (?:is|are|for|would you want for|do you want for)?\s*(?:the |that |this |a |an |your |these |those )*(?:'
        . $goods . ')\b/', $t)) { return true; }
    // 3. an OBJECT frame: an asking verb, or a figure, tied to an object pronoun
    if (preg_match('/\b(?:do you want|would you want|would you take|will you take|do you charge|do you ask'
        . '|are you asking|is it|are they)\s+for\s+(?:it|that|this|them|these|those|one|the lot)\b/', $t)) { return true; }
    $num = LRG_MONEY_NUMWORD;
    if (preg_match('/\b(?:\d{1,6}|' . $num . ')\s*(?:' . LRG_MONEY_WORD . ')?\s+for\s+(?:it|that|this|them|these|those|one)\b/', $t)) {
        return true;
    }
    return false;
}

/**
 * [0.5.6 / pt15] The offer she asked him to confirm, or null (none, expired, or - unless asked for -
 * withdrawn). Lives in lrg_memory.offer_pending: gold, npc, at, expires (~2 minutes), req (the request
 * that created it) and state asked | confirmed | withdrawn.
 */
function lrgPendingOffer(array $mem, bool $withWithdrawn = false): ?array
{
    $p = is_array($mem['offer_pending'] ?? null) ? $mem['offer_pending'] : null;
    if ($p === null || (int) ($p['gold'] ?? 0) <= 0 || lrgNow() > (int) ($p['expires'] ?? 0)) { return null; }
    if (!$withWithdrawn && (string) ($p['state'] ?? '') === 'withdrawn') { return null; }
    return $p;
}

/**
 * [0.5.6 / pt15] The same request key lrgPrepareTurn() uses to recognise its own second pass. A pending
 * offer remembers the request that CREATED it, so the sentence that made the offer can never also be
 * read as the confirmation of it on the second pass of the same request.
 */
function lrgMoneyReqKey(): string
{
    return md5((string) ($GLOBALS['HERIKA_NAME'] ?? '') . '|' . json_encode($GLOBALS['gameRequest'] ?? []));
}

/** One spelled-out or numeral amount, as a regex fragment (no capture group). */
function lrgMoneyNumRx(): string
{
    $num = LRG_MONEY_NUMWORD;
    return '(?:\d{1,6}|(?:(?:a|an)\s+)?' . $num . '(?:[\s\-]+(?:and\s+)?' . $num . ')*)';
}

/**
 * [0.5.6 / pt15] The amount when the utterance is NOTHING BUT an amount (plus fillers): "a thousand",
 * "two hundred and fifty septims", "yes, a thousand, right now", "make it 300". 0 otherwise. Only used
 * where a figure is already on the table (her quote, or an offer she asked him to confirm), which is
 * the one place a bare number is an answer - and never on loose words: "give me two minutes" is not 2.
 */
function lrgBareAmount(string $t): int
{
    $s = ' ' . trim($t, " \t?.!,") . ' ';
    $s = (string) preg_replace('/\b(?:yes|yeah|yep|yup|aye|ok|okay|alright|all right|right now|right|now|then|please|well|fine'
        . '|so|just|only|all of it|i said|make it|call it|how about|what about|say|for you|the lot|and not a coin (?:more|less)'
        . '|gold|septims?|coins?|pieces?|drakes?|money|in coin|in gold|up ?front|upfront|on the table|that\s?\'?s|thats|it)\b/', ' ', $s);
    $s = trim((string) preg_replace('/\s+/', ' ', $s));
    if ($s === '') { return 0; }
    if (preg_match('/^\d{1,6}$/', $s)) { return (int) $s; }
    $num = LRG_MONEY_NUMWORD;
    if (preg_match('/^(?:(?:a|an)\s+)?' . $num . '(?:[\s\-]+(?:and\s+)?' . $num . ')*$/', $s)) { return lrgWordAmount($s); }
    return 0;
}

/**
 * [0.4 / OWNER ADDENDA 6, 0.5.6 / pt15 owner rulings A-D] Money in the player's own words: an OFFER,
 * a question about the price (ASKPRICE), HAGGLING, or taking a pending offer back (WITHDRAW).
 * Returns ['kind','gold','from','why','firm'] or null.
 *
 * WHY THIS IS A PRE-SCAN (design §1.5): "how much" and "what's your price" ARE questions, and
 * lrgIntentQuestion() blocks anything opening with what|how - so running this after lrgIntentBlocked()
 * would throw the whole feature away. The blockers are therefore applied HERE, deliberately asymmetric:
 *   negated / quoted   always block (never a septim from "I'm not paying you" or "you said you'd take 100")
 *   question           lifted for askprice and haggle; for an OFFER only when a figure is really named
 *   hypothetical       [pt15, ruling A] an "if / would / could" offer WITH a figure stays an offer, but a
 *                      NON-FIRM one (from=hypothetical): she asks him to confirm it and nothing can start
 *                      on it. Without a figure it is still a question about her price (askprice).
 * FIRMNESS (ruling C): firm = a commitment verb ("I'll give you", "here's", "take 300 and ...", "right
 * now", "deal"), no hypothetical marker. An amount with neither ("a thousand gold for a night", "I've got
 * a thousand septims") is from=implied - grey, she asks. Only a FIRM figure can ever leave the purse.
 * PENDING (ruling C): while she waits for his confirmation, "yes / deal / done / that's right / I mean
 * it" or the same figure again is a FIRM offer at the remembered amount (from=confirm); "no / forget it"
 * is a withdraw; a different figure is a new offer that replaces it.
 * $mem is lrg_memory: price_quoted (her last figure), offer_pending (the offer she asked him to confirm).
 * Pure regex over $mem: no ctx, no index, no DB - which is what lets the gate run it before the recogniser.
 */
function lrgIntentMoney(string $t, array $mem = []): ?array
{
    if (trim($t) === '') { return null; }
    $cfg = (array) (lrgConfig()['paid_intimacy'] ?? []);
    if (empty($cfg['enabled'] ?? true)) { return null; }
    $num = LRG_MONEY_NUMWORD;
    $mw = LRG_MONEY_WORD;
    $n = lrgMoneyNumRx();
    $quoted = is_array($mem['price_quoted'] ?? null) ? $mem['price_quoted'] : null;
    $hasQuote = $quoted !== null && (int) ($quoted['gold'] ?? 0) > 0
        && lrgNow() - (int) ($quoted['at'] ?? 0) <= max(1, (int) ($cfg['quote_ttl_seconds'] ?? 300));
    // the offer she asked him to confirm - never the one THIS request created (second pass of the same line)
    $pend = lrgPendingOffer($mem);
    if ($pend !== null && (string) ($pend['req'] ?? '') !== '' && (string) $pend['req'] === lrgMoneyReqKey()) { $pend = null; }
    $pendAny = lrgPendingOffer($mem, true);
    $hypoRx = '/\b(?:if|what if|imagine|pretend|suppose|supposing|hypothetically|say i|let\s?\'?s say|lets say|would|could|might'
        . '|maybe|perhaps|i\s?\'?d|what would|would you ever|have you ever|do you ever|one day|some ?day)\b/';
    // tag questions and courtesies that do not make an offer conditional: "here's 500, if you want it",
    // "I'll pay you 300 - would that do?"
    $forHypo = (string) preg_replace('/\b(?:if you (?:want|like|wish|will have)(?: (?:it|them|to|that))?|if you\s?\'?ll have (?:it|them)'
        . '|would (?:that|it|this) (?:do|work|be enough|suffice|cover it)|is that enough)\b/', ' ', $t);
    $isHypo = (bool) preg_match($hypoRx, $forHypo);

    // ---- 0. a pending offer: his answer to her "you mean it?" (ruling C)
    if ($pendAny !== null) {
        $cancel = preg_match('/\b(?:forget it|forget about it|forget i said|never ?mind|i take it back|i was (?:joking|kidding)|just kidding'
            . '|only joking|no deal|i didn\s?\'?t mean it|i didnt mean it|i don\s?\'?t mean it)\b/', $t)
            // a plain "no" answers her question only while she is really asking - or on the second pass of
            // the very request that already withdrew it (so both passes of one request agree)
            || (($pend !== null || ((string) ($pendAny['state'] ?? '') === 'withdrawn' && (string) ($pendAny['wreq'] ?? '') === lrgMoneyReqKey()))
                && preg_match('/^(?:(?:well|oh|actually|hmm|um|uh)\s+)?(?:no|nope|nah|not really|not now|maybe later|not yet)\b/', $t)
                && !preg_match('/\bi (?:do )?mean it\b|\bi meant it\b|\bi swear\b/', $t));
        if ($cancel) {
            return ['kind' => 'withdraw', 'gold' => 0, 'from' => 'pending', 'why' => 'he took the offer back', 'firm' => false];
        }
    }
    if ($pend !== null) {
        $pg = (int) $pend['gold'];
        $said = lrgIntentAmount($t, false);
        if ($said <= 0) { $said = lrgBareAmount($t); }
        $confirm = null;
        if ($said > 0) {
            // the same figure again (and no "if" around it) confirms it; a different one is a new offer, below
            if ($said === $pg && !$isHypo) { $confirm = 'the same figure again'; }
        } elseif (preg_match('/\bi (?:do )?mean it\b|\bi meant it\b|\bi swear\b|\bi promise\b|\byou have my word\b|\bevery (?:septim|coin|piece)\b'
            . '|\bdead serious\b|\bi\s?\'?m serious\b|\bi am serious\b/', $t)) {
            $confirm = 'he says he means it';
        } elseif (preg_match('/^(?:(?:well|oh|then|so|okay|ok|alright|all right|yes|yeah)\s+)*(?:yes|yeah|yea|yep|yup|aye|sure|ok|okay|deal|done'
            . '|agreed|right|correct|exactly|absolutely|of course|certainly|i do|right now|now|it\s?\'?s yours|its yours|you\s?\'?ve got it'
            . '|youve got it|take it|that\s?\'?s right|thats right|that\s?\'?s the deal|thats the deal|that\s?\'?s what i said|thats what i said)\b/', $t)) {
            $confirm = 'he confirmed it';
        }
        if ($confirm !== null) {
            return ['kind' => 'offer', 'gold' => $pg, 'from' => 'confirm', 'why' => 'confirming his offer: ' . $confirm, 'firm' => true];
        }
    }

    $isAsk = (bool) preg_match('/\bhow much\b|\bwhat\s?\'?s your price\b|\bname your price\b|\bwhat would it take\b'
        . '|\bhow many (?:gold|septims?|coins?)\b|\bwhat do you charge\b|\bfor how much\b|\byour price\b'
        . '|\bhow much do you (?:cost|charge|want)\b|\bwhat\s?\'?s it worth\b'
        // [pt15] the owner's own opening line, verbatim: "and everybody has a price"
        . '|\bevery ?(?:one|body)(?:\s+(?:has|have)|\s?\'?s)\s+(?:got\s+)?(?:a|their|his|her|your)?\s*price\b|\byou\s?\'?(?:ve)?\s+(?:got|have) a price\b'
        . '|\bwhat(?:\s?\'?s| is| will| would| does)? it (?:gonna |going to )?cost\b|\bwhat\s?\'?s your rate\b|\bwhat are your rates\b/', $t);
    // haggling is split into UNMISTAKABLE phrases and WEAK ones. "come down" and "half that" are
    // things people also say in bed, and the money pre-scan runs before every other pattern - so the
    // weak half only counts when the utterance really is about money (a coin word, the word "price",
    // or a figure she named a moment ago). Without that split "come down" mid-scene would be answered
    // with "nothing is bought or sold" instead of being read as what he asked for.
    // [0.4.0 fix pass] COMMERCE IS NOT A PROPOSITION. Paying an innkeeper for the room, a smith for a
    // sword or asking a merchant what he wants for an item is not "everyone has a price", and reading it
    // as one invited BeginIntimacy at affinity 0. Once SHE has quoted a figure (or asked him to confirm
    // one) the subject is already hers, so a live quote overrides the test.
    if (!$hasQuote && $pendAny === null && lrgIntentGoodsTalk($t)) { return null; }
    $isHag = (bool) preg_match('/\btoo (?:much|expensive|steep|rich|dear|high)\b|\bcan you do better\b'
        . '|\bmeet me (?:in the )?(?:middle|half ?way)\b|\bdiscount\b|\bhaggl\w*|\bcheaper\b/', $t);
    $isOff = (bool) preg_match('/\bi\s?\'?ll pay\b|\bi can pay\b|\bi will pay\b|\blet me pay\b|\bpay(?:ing)? you\b'
        . '|\bi\s?\'?ll make it worth your while\b|\bfor the right price\b|\bmoney is no (?:object|problem)\b'
        . '|\bname it and it\s?\'?s yours\b|\bhere\s?\'?s (?:some )?(?:gold|coin|septims?)\b'
        . '|\btake my (?:gold|coin|purse)\b|\bi have gold\b|\bi\s?\'?ve got gold\b|\bi\s?\'?ll give you\b|\bi will give you\b'
        // [pt15] the conditional and present-tense forms - only with "you", so "I'd pay to see that" and
        // "I'm giving up" are not offers
        . '|\bi\s?\'?d (?:pay|give) you\b|\bi would (?:pay|give) you\b|\bi could (?:pay|give) you\b'
        . '|\bi\s?\'?m (?:offering|paying|giving) you\b|\bi am (?:offering|paying|giving) you\b|\bi offer you\b/', $t);
    $hasMoney = (bool) preg_match('/\b' . $mw . '\b|\bmoney\b/', $t);
    $ctxMoney = $hasQuote || $pendAny !== null;
    if (!$isHag && ($hasMoney || $ctxMoney || preg_match('/\bprice\b/', $t))) {
        $isHag = (bool) preg_match('/\bthat\s?\'?s a lot\b|\bhalf (?:of )?that\b|\bcome down\b|\bless than that\b|\bfor less\b/', $t);
    }
    // [pt15] the grey-area figures (ruling C): "would fifty do?", "is 200 enough?", and - where coin is
    // already the subject - "how about 150", "make it 300"
    $isGrey = (bool) preg_match('/\bwould ' . $n . '(?:\s+' . $mw . ')?\s+(?:do|be enough|work|suffice|cover it)\b|\bis ' . $n
        . '(?:\s+' . $mw . ')?\s+enough\b|\bwill ' . $n . '(?:\s+' . $mw . ')?\s+do\b/', $t)
        || (($ctxMoney || $hasMoney || preg_match('/\bprice\b/', $t))
            && preg_match('/\b(?:how|what) about ' . $n . '\b|\bmake it ' . $n . '\b|\bcall it ' . $n . '\b/', $t));
    // [pt15] a figure handed over with a verb and no coin word: "here's five hundred", "take three
    // hundred and come upstairs". Anchored so "take two steps" / "here's two options" are not money.
    $isHand = (bool) preg_match('/\b(?:here\s?\'?s|here is|take)\s+' . $n . '(?:\s+(?:and|for|right|now|then|up ?front|upfront|just|all)\b|$)/', $t);
    $isFirm = (bool) preg_match('/\bi\s?\'?ll (?:pay|give)\b|\bi will (?:pay|give)\b|\bi can pay\b|\blet me pay\b|\bi\s?\'?m (?:paying|giving|offering)\b'
        . '|\bi am (?:paying|giving|offering)\b|\bi (?:pay|give|offer) you\b|\bhere\s?\'?s\b|\bhere is\b|\bhere you (?:go|are)\b'
        . '|\btake (?:it|this|these|them|my|the (?:gold|coin|coins|money|septims?))\b|\bright now\b|\bup ?front\b|\bupfront\b'
        . '|\bon the table\b|\bcount it\b|\bpaying you\b|\bpay you\b|\bdeal\b|\bdone\b/', $t) || $isHand;

    $strict = lrgIntentAmount($t, false);
    $loose = ($isOff || $isGrey || $isHand || ($hasMoney && !$isAsk)) ? lrgIntentAmount($t, true) : 0;
    // a grey / handed-over figure with no coin word and nothing else about money has to look like a price:
    // "take one for the team" and "would one do?" are not offers of one septim
    if ($loose > 0 && $loose < 10 && !$isOff && !$hasMoney && !$ctxMoney) { $loose = 0; }
    $gold = $strict > 0 ? $strict : $loose;
    $bare = false;
    if ($gold <= 0 && $ctxMoney) { $gold = lrgBareAmount($t); $bare = $gold > 0; }   // a counter-offer: "150?"

    // Precedence: a figure the player named beats everything; then HAGGLING beats asking, because
    // "come down on your price" carries both and the haggle reading is the informative one; then a
    // plain question about the price; then a bare offer with no figure at all.
    $kind = '';
    if ($gold > 0 && ($strict > 0 || $isOff || $hasMoney || $isGrey || $isHand || $bare)) { $kind = 'offer'; }
    elseif ($isHag) { $kind = 'haggle'; }
    elseif ($isAsk) { $kind = 'askprice'; }
    elseif ($isOff) { $kind = 'offer'; $gold = 0; }   // a bare offer: he will pay, he named no figure

    if ($kind === '') {
        // the one utterance that carries no money word at all and is still an offer: closing a figure
        // SHE named a moment ago ("deal")
        if ($hasQuote && preg_match('/^(?:please\s+)?(?:deal|done|agreed|fine|take it|alright then|it\s?\'?s yours|its yours|you\s?\'?ve got it|youve got it)\b/', $t)) {
            return ['kind' => 'offer', 'gold' => (int) $quoted['gold'], 'from' => 'quote', 'why' => 'closing the figure she named', 'firm' => true];
        }
        return null;
    }

    // ---- the blockers, applied here because the pre-scan runs before lrgIntentBlocked()
    if (lrgIntentNegated($t)
        || preg_match('/\b(?:not|never|won\s?\'?t|wont|ain\s?\'?t)\s+(?:going to\s+|gonna\s+|be\s+)?(?:pay|paying|buy|buying)\b'
            . '|\bno (?:gold|coin|coins|septims?|money)\b|\bnot paying\b|\bkeep your (?:gold|coin|money)\b/', $t)) {
        return null;
    }
    if (preg_match('/\b(you said|i said|she said|he said|they said|told (?:me|you)|remember when)\b/', $t)) { return null; }
    if ($isHypo) {
        if ($kind === 'haggle') { return null; }
        // ruling A: an "if" with a figure stays a NON-COMMITTING offer - she asks, nothing can start on it
        if ($kind === 'offer' && $gold > 0) {
            return ['kind' => 'offer', 'gold' => $gold, 'from' => 'hypothetical', 'why' => 'hypothetical: he has not committed - she asks him to confirm', 'firm' => false];
        }
        return ['kind' => 'askprice', 'gold' => 0, 'from' => '', 'why' => 'hypothetical: asking, never committing gold', 'firm' => false];
    }
    if ($kind === 'offer' && !($gold > 0 && ($hasMoney || $isOff || $isGrey || $isHand || $bare)) && lrgIntentQuestion($t) && !lrgIntentPolite($t)) { return null; }

    if ($kind === 'offer' && $gold <= 0 && $hasQuote && $isFirm) {
        // "fine, I'll pay it" - he takes her figure without repeating it
        return ['kind' => 'offer', 'gold' => (int) $quoted['gold'], 'from' => 'quote', 'why' => 'taking the figure she named', 'firm' => true];
    }
    if ($kind === 'offer' && $gold > 0) {
        if ($isFirm && !$isGrey) { return ['kind' => 'offer', 'gold' => $gold, 'from' => 'said', 'why' => '', 'firm' => true]; }
        return ['kind' => 'offer', 'gold' => $gold, 'from' => 'implied',
            'why' => 'implied: a figure without a commitment - she asks him to confirm', 'firm' => false];
    }
    return ['kind' => $kind, 'gold' => $gold, 'from' => '', 'why' => '', 'firm' => $kind === 'offer'];
}

// ---------------------------------------------------------------- clothing, from the PLAYER's mouth
/**
 * Whose clothes the player means. The opposite reading of lrgResolveClothing(), which documents that
 * "take your clothes off" in the MODEL's item means the NPC - because the model echoes the player.
 * From the player's own mouth "my" is the player and "your" is the NPC.
 */
function lrgIntentClothingWho(string $t): string
{
    // [pt19] an aside BEFORE the request says nothing about whose clothes: "well we're in a private room
    // already why don't you get naked?" resolved to BOTH on the aside's "we're". The who is judged from
    // the last invitation frame on - unless that frame is a trailing tag ("take my clothes off, why don't
    // you"), when the words before it ARE the request. And a "we're / we are / we have / we got" (or the
    // speech-to-text "we re") that says where they are is not a "we" that asks for both.
    if (preg_match_all(LRG_INTENT_INVITE, $t, $m, PREG_OFFSET_CAPTURE) && $m[0]) {
        $last = end($m[0]);
        $after = substr($t, (int) $last[1] + strlen((string) $last[0]));
        if (preg_match('/[a-z]/', $after)) { $t = substr($t, (int) $last[1]); }
    }
    if (preg_match('/\b(both|us|each other|together|our)\b|\bwe\b(?!\'(?:re|ve|ll|d)\b| (?:re|ve|ll|are|were|have|got)\b)/', $t)) { return 'both'; }
    // [pt19] every garment the branches know ("take off my dress" undressed HER - the playtest-6 class)
    if (preg_match('/\bmy (clothes|clothing|garments?|armou?r|shirt|dress|boots|shoes|things|gear|tunic|robes?|pants|trousers|gloves|gauntlets|helmet|helm|hood|hat)\b/', $t)
        || preg_match('/\b(undress|strip|get|take|bare|make) me\b/', $t)
        || preg_match('/\b(off|from) me\b/', $t)
        || preg_match('/\bmine\b/', $t)) { return 'player'; }
    return 'npc';
}

/** all | body | head | hands | feet - the same table lrgResolveClothing uses. */
function lrgIntentPart(string $t): string
{
    foreach (['feet' => 'feet|foot|boots?|shoes?', 'hands' => 'hands?|gloves?|gauntlets?', 'head' => 'head|helm\w*|hood|hat|circlet',
              'body' => 'body|top|chest|torso|cuirass|armou?r|shirt|tunic|robes?'] as $p => $rx) {
        if (preg_match('/\b(' . $rx . ')\b/', $t)) { return $p; }
    }
    return 'all';
}

// ---------------------------------------------------------------- [0.5.4] escort: follow / wait / release
/** The `escort` config block over LRG_ESCORT_DEFAULTS. Dotted path; lists are replaced, maps merged. */
function lrgEscortCfg(string $path, $default = null)
{
    $v = lrgMerge(LRG_ESCORT_DEFAULTS, (array) (lrgConfig()['escort'] ?? []));
    foreach (explode('.', $path) as $k) {
        if (!is_array($v) || !array_key_exists($k, $v)) { return $default; }
        $v = $v[$k];
    }
    return $v;
}

/** Is the escort half switched on at all (the whole block, and the given part of it)? */
function lrgEscortOn(string $part = 'recognise'): bool
{
    return !empty(lrgEscortCfg('enabled', true)) && !empty(lrgEscortCfg($part, true));
}

/**
 * The player's line in CLAUSES for the escort scan. Context prefix and "Name: " prefix go first - the
 * DLL's "(Context location: The Winking Skeever, Solitude)" carries a comma of its own. Split on
 * punctuation (kept on the clause, so a question stays a question) and on and / but / then / so:
 * "okay so come on follow me now" is "okay" + "come on follow me now".
 */
function lrgEscortClauses(string $utterance): array
{
    $raw = trim(lrgStripContext($utterance));
    $raw = (string) preg_replace('/^[^:]{1,40}:\s*/', '', $raw);
    $out = [];
    foreach ((array) preg_split('/(?<=[,;.!?])\s*|\s+(?:and|but|then|so)\s+/i', $raw) as $p) {
        $c = lrgIntentClean((string) $p);
        if ($c !== '' && preg_match('/[a-z]/', $c)) { $out[] = $c; }
    }
    return $out;
}

/** The text before $offset ends in a subject that is not the listener ("I'll wait here", "they follow me"). */
function lrgEscortOtherSubject(string $before, bool $firstPerson): bool
{
    if (preg_match('/\b(?:they|he|she|it|someone|somebody|nobody|no one|people|everyone|everybody|guards?)\s+(?:[a-z\']+\s+)?$/', $before)) { return true; }
    return $firstPerson
        && (bool) preg_match('/\b(?:i|we|i\'?ll|i ll|ill|i will|we\'?ll|we ll|we will|i\'?m|i m|im|i am|i\'?d|i d|let me|let us|let\'?s|lets|let s)\s+(?:[a-z\']+\s+){0,2}$/', $before);
}

/**
 * ONE clause -> ['do' => follow|wait|release, 'frag' => the words that matched] or null.
 * Order: release first (its own phrasings carry a negation or a stop: "you don't have to follow me",
 * "stop following me"), then the negation / "can't" blocker, then wait, then follow. A question that
 * is not a polite request, a hypothetical and a quotation stay what they are - nothing.
 */
function lrgEscortClause(string $c): ?array
{
    $t = (string) preg_replace('/\bwhy don\'?t (?:you|we)\b|\bwhy don t (?:you|we)\b/', 'please', $c);   // a request, not a negation
    $t = (string) preg_replace('/\bif you (?:want|like|wish|please)(?: to)?\b/', '', $t);                 // "come with me if you like"
    $t = trim((string) preg_replace('/\s+/', ' ', $t));
    if ($t === '') { return null; }
    if (preg_match('/\b(what if|imagine|pretend|suppose|would you ever|have you ever|do you ever|what would|one day|some ?day)\b/', $t)) { return null; }
    if (preg_match('/\b(you said|i said|she said|he said|they said|told (me|you)|remember when)\b/', $t)) { return null; }
    $hit = static function (array $rxs, bool $firstPerson) use ($t): string {
        foreach ($rxs as $rx) {
            if (!preg_match($rx, $t, $m, PREG_OFFSET_CAPTURE)) { continue; }
            if (lrgEscortOtherSubject(substr($t, 0, (int) $m[0][1]), $firstPerson)) { continue; }
            return trim((string) $m[0][0]);
        }
        return '';
    };
    $own = static function (string $kind): array {
        $rx = [];
        foreach ((array) lrgEscortCfg('phrases.' . $kind, []) as $p) {
            $p = lrgIntentClean((string) $p);
            if ($p !== '') { $rx[] = '/\b' . preg_quote($p, '/') . '\b/'; }
        }
        return $rx;
    };
    $do = '';
    $frag = $hit(array_merge([
        '/\byou(?:\'re| are|re| re) free to go\b/',
        '/\bstop following (?:me|us)\b/',
        '/\byou (?:don\'?t|don t|do not) (?:have|need) to (?:follow|come with|walk with|stay with) (?:me|us)\b/',
        '/\bno need to (?:follow|come with|walk with) (?:me|us)\b/',
        '/\bthat(?:\'?ll| ll| will) be all\b/',
        '/\byou (?:can|may|could) (?:go|head) back(?: to [a-z\' ]+)?$/',
        '/\byou (?:can|may|could) (?:go|leave)(?: (?:now|home|on|too|then|already|off))*$/',
        '/^(?:(?:please|now|okay|ok|alright|so|well)\s+)*(?:go|head|get) back to (?:your|what you were|the stage|singing|playing|work|performing|the bar|the counter)\b/',
        '/\bgo back to (?:singing|playing|performing|what you were doing|your (?:singing|playing|work|song|songs|music|lute|stage|post))\b/',
    ], $own('release')), false);
    if ($frag !== '') { $do = 'release'; }
    if ($do === '') {
        if (lrgIntentNegated($t) || preg_match('/\b(?:can\'?t|can t|cannot|won\'?t|won t|shouldn\'?t|shouldn t|mustn\'?t)\b/', $t)) { return null; }
        $frag = $hit(array_merge([
            '/\b(?:wait|stay|remain)(?: right)? (?:here|there)\b/',
            '/\bwait (?:here )?for me\b/',
            '/\bwait up\b/',
            '/\bstay put\b/',
            '/\bstay where you are\b/',
            '/\bhold (?:your )?position\b/',
        ], $own('wait')), true);
        if ($frag !== '') { $do = 'wait'; }
    }
    if ($do === '') {
        $frag = $hit(array_merge([
            '/\bfollow (?:me|us)\b/',
            '/\bfollow (?:along|behind me|close)\b/',
            '/\bcome (?:(?:up|upstairs|downstairs|outside|inside|over|back|home|along) )?(?:with|along with) (?:me|us)\b/',
            '/\bcome along\b/',
            '/\bcome (?:up |over |back )?to (?:my|our) (?:room|place|house|home|quarters)\b/',
            '/\bcome (?:upstairs|downstairs|outside)\b/',
            '/\bwalk with (?:me|us)\b/',
            '/\btag along\b/',
            '/\byou(?:\'re| are|re| re) coming with (?:me|us)\b/',
            '/\b(?:let\'?s|lets|let s) (?:go|head (?:out|off|up|upstairs|home)|get going|get out of here|move|get moving|be off)'
                . '(?: (?:upstairs|downstairs|up|outside|inside|home|out of here|now|then|together|somewhere(?: [a-z]+){0,3}'
                . '|to (?:my|your|our|the) (?:room|place|house|home|quarters)))*$/',
        ], $own('follow')), false);
        if ($frag !== '') { $do = 'follow'; }
    }
    if ($do === '') { return null; }
    if (lrgIntentQuestion($t) && !lrgIntentPolite($t)) { return null; }   // "are you gonna follow me?" stays a question
    return ['do' => $do, 'frag' => $frag];
}

/**
 * [0.5.4 / pt13] Did the player ask her to come along, to wait, or to go back? ['do','frag'] or null.
 * Regex only, no context, no DB: it is safe to run in EVERY mode outside a running scene with her,
 * including the silent ones, because nothing here has a sexual reading. The three lines of playtest 13
 * are the fixture: "i need you to come with me and talk to me in private" (follow), "okay so come on
 * follow me now" (follow) and "hey are you gonna follow me?" (a question: nothing).
 */
function lrgIntentEscort(string $utterance): ?array
{
    if (!lrgEscortOn('recognise')) { return null; }
    $whole = lrgIntentClean($utterance);
    if ($whole === '') { return null; }
    // a bare "come on" is "come along" - but only when nothing else is said ("oh come on" is not)
    if (preg_match('/^(?:(?:okay|ok|alright|all right|so|hey|well|now|please|quick|quickly|uh|um|right)\s+)*come on'
        . '(?:\s+(?:then|now|already|please|quick|quickly|love|dear|lass|girl|come on|let\'?s go|lets go|let s go|this way|over here|with me|along|up))*$/', $whole)) {
        return ['do' => 'follow', 'frag' => 'come on'];
    }
    foreach (lrgEscortClauses($utterance) as $c) {
        $hit = lrgEscortClause($c);
        if ($hit !== null) { return $hit; }
    }
    return null;
}

/** The escort recognition in the shape every consumer of $turn['intent'] reads. */
function lrgEscortIntent(array $hit, string $t, ?array $ctx): array
{
    $cap = (int) (lrgConfig()['intent']['log_utterance_chars'] ?? 160);
    $out = ['kind' => LRG_INTENT_ESCORT, 'conf' => 'high', 'kv' => ['do' => (string) $hit['do']], 'act' => '', 'scene' => '',
        'words' => '', 'why' => '', 'text' => substr($t, 0, $cap), 'frag' => (string) $hit['frag'], 'again' => false,
        'extra' => [], 'gold' => 0, 'from' => ''];
    $out['words'] = lrgIntentWords($out, $ctx);
    return $out;
}

/**
 * [0.5.4] The escort-only recognition, for the out-of-scene turns on which the ordinary recogniser must
 * not run at all (silent for a reason the game KNOWS: not an adult, a child nearby, the intimacy switch
 * off, an opted-out NPC). No extra, no act, no money: only follow / wait / release, or the empty intent.
 */
function lrgRecogniseEscort(string $utterance): array
{
    $t = lrgIntentClean($utterance);
    $hit = lrgIntentEscort($utterance);
    if ($hit !== null) { return lrgEscortIntent($hit, $t, null); }
    $cap = (int) (lrgConfig()['intent']['log_utterance_chars'] ?? 160);
    return ['kind' => 'none', 'conf' => 'none', 'kv' => [], 'act' => '', 'scene' => '', 'words' => '',
        'why' => $t === '' ? 'empty transcript' : 'escort only: no pattern matched', 'text' => substr($t, 0, $cap), 'frag' => '',
        'again' => false, 'extra' => [], 'gold' => 0, 'from' => ''];
}

// ---------------------------------------------------------------- the recogniser
/**
 * lrgRecogniseIntent(utterance, ctx, memory) -> the whole recognition.
 *  kind  one of LRG_INTENT_ACTIONABLE, 'yes', 'no' or 'none'
 *  conf  high | low | none   (only 'high' ever reaches the safety net)
 *  kv    the command kv this resolves to, [] when nothing resolved
 *  act   act id when the kind is 'act' / 'yes', '' otherwise
 *  scene the scene id the act resolved to, '' when none
 *  words a plain phrase for the directive
 *  why   why it was blocked or downgraded (log only)
 *  text  the cleaned utterance, capped for the log
 *  frag  the fragment that matched (never the whole utterance: PROTOCOL 7.2 forbids handing that to
 *        lrgResolveControl, whose free-text position search would then fire on incidental words)
 *
 * $light = true skips every index lookup: used pre-lock, where only "is this actionable at all" matters.
 */
function lrgRecogniseIntent(string $utterance, ?array $ctx = null, array $mem = [], bool $light = false): array
{
    $cap = (int) (lrgConfig()['intent']['log_utterance_chars'] ?? 160);
    $t = lrgIntentClean($utterance);
    $out = ['kind' => 'none', 'conf' => 'none', 'kv' => [], 'act' => '', 'scene' => '', 'words' => '', 'why' => '',
        'text' => substr($t, 0, $cap), 'frag' => ''];
    if (empty(lrgConfig()['intent']['enabled'] ?? true)) { $out['why'] = 'recogniser off'; return $out; }
    if ($t === '') { $out['why'] = 'empty transcript'; return $out; }

    // [0.5.4 / pt13] ESCORT FIRST, and only where the caller says this is not a running scene with her
    // ($ctx['escort'], set by lrgPrepareTurn's out-of-scene branch). "come with me and talk to me in
    // private" is a request to walk, not a proposition, and before money / stop / the blockers because
    // none of them has anything to say about it. A second request in the same breath is still carried
    // (as `extra`, named in the directive and on the turn line's also=).
    // [0.5.6 / pt15] ... except when the line puts a FIGURE on the table (or answers her "you mean it?"):
    // "take three hundred and come upstairs" is an offer first, and the offer's own directive already
    // takes her somewhere private (SuggestPrivacy) when it cannot begin where they stand.
    $moneyFirst = null;
    if (!empty($ctx['escort'])) {
        $moneyFirst = lrgIntentMoney($t, $mem);
        if (!is_array($moneyFirst) || !((int) ($moneyFirst['gold'] ?? 0) > 0 || (string) ($moneyFirst['kind'] ?? '') === 'withdraw')) { $moneyFirst = null; }
    }
    if (!empty($ctx['escort']) && $moneyFirst === null) {
        $esc = lrgIntentEscort($utterance);
        if ($esc !== null) {
            $e = lrgEscortIntent($esc, $t, $ctx);
            if (!$light) {
                $extra = lrgIntentExtra($utterance, $e, $ctx, $mem);
                // only a kind that means something OUTSIDE a scene: "wait here, I'll be back" must not
                // grow a scene 'hold' out of its own escort clause
                if ($extra && in_array((string) ($extra['kind'] ?? ''), ['act', 'undress', 'dress'], true)) { $e['extra'] = $extra; }
            }
            return $e;
        }
    }

    $prop = is_array($mem['proposal'] ?? null) ? $mem['proposal'] : null;
    if ($prop !== null && lrgNow() > (int) ($prop['expires'] ?? 0)) { $prop = null; }

    // 1. stop - an answer, not a request: blockers do not apply. A request frame plus another actionable
    //    kind in the same breath wins over it ("that's enough, fuck me").
    $isStop = lrgIntentStop($t);

    // 2/3. yes / no, only against a pending proposal (R11). A NO is an answer and stays exempt from the
    //      blockers (its own words - "not now", "maybe later", "rather not" - ARE the negation). A YES is
    //      not: it only counts when the utterance IS the affirmation and carries no blocker, because this
    //      is the one path in v0.3 where a single mis-read word makes a sex act happen.
    if ($prop !== null && !$isStop) {
        if (preg_match('/^(no|nope|nah|not now|not yet|later|maybe later)\b/', $t)
            || preg_match('/\b(rather not|i\'?d rather not|not right now|not now|not yet|maybe later|don\'?t want to)\b/', $t)) {
            return ['kind' => 'no', 'conf' => 'high', 'kv' => [], 'act' => (string) ($prop['act'] ?? ''), 'scene' => '',
                'words' => '', 'why' => '', 'text' => substr($t, 0, $cap), 'frag' => $t];
        }
        if (lrgIntentBareYes($t) && lrgIntentBlocked($t) === '') {
            $r = ['kind' => 'yes', 'conf' => 'high', 'kv' => [], 'act' => (string) ($prop['act'] ?? ''), 'scene' => '',
                'words' => '', 'why' => '', 'text' => substr($t, 0, $cap), 'frag' => $t];
            return $light ? $r : lrgIntentResolve($r, $ctx, 4);
        }
        // anything else falls through to the ordinary scan: it may be a request of its own, and it is
        // never an answer to her question
    }

    /*
     * [0.4 / OWNER ADDENDA 6] MONEY, scanned before the blockers - the same placement `stop` has, and
     * for the same kind of reason (lrgIntentMoney's own docblock spells it out). A money hit is the
     * PRIMARY kind and an act said in the same breath goes to `extra`, so "I'll pay you 200 to suck my
     * cock" is one offer of 200 plus the act, and the existing w1 `after=` / start_scene path makes the
     * scene begin in what he asked for. No new compound code.
     * The money kinds resolve to NO kv, are not in LRG_INTENT_ACTIONABLE and are not in net_kinds, so
     * nothing downstream can turn one into a command by itself.
     */
    if (!$isStop) {
        $money = lrgIntentMoney($t, $mem);
        if ($money !== null) {
            $out['kind'] = (string) $money['kind'];
            $out['gold'] = (int) $money['gold'];
            $out['from'] = (string) ($money['from'] ?? '');
            $out['why'] = (string) ($money['why'] ?? '');
            // [pt15] firm = a figure that may really leave the purse; an "if" / an implied figure is not
            $out['firm'] = !array_key_exists('firm', $money) || !empty($money['firm']);
            $out['frag'] = $t;
            // high by construction: lrgIntentMoney only ever fires on a parsed amount or on an
            // unmistakable phrase, never on a bare number
            $out['conf'] = 'high';
            if (!$light) { $out['extra'] = lrgIntentExtra($utterance, $out, $ctx, $mem); }
            $out['words'] = lrgIntentWords($out, $ctx);
            return $out;
        }
    }

    $blocked = lrgIntentBlocked($t);
    $frame = lrgIntentFrame($t, $utterance);
    $scan = $blocked === '' ? lrgIntentScanOrdered($t, $utterance, $ctx, $light) : null;

    // A negation governs the CLAUSE it sits in, not the whole utterance: "don't stop, harder" is a stop
    // the rail refuses plus a request the player then lost entirely. When the only blocker is the
    // negation, each clause that is itself free of it is scanned on its own. A single-clause utterance
    // ("don't slow down", "don't get naked yet") has nothing to rescue and stays blocked.
    if ($blocked === 'negated' && !$isStop) {
        foreach (lrgIntentClauses($utterance) as $clause) {
            if (lrgIntentNegated($clause)) { continue; }
            $try = lrgIntentScan($clause, $ctx, $light);
            if ($try !== null && (string) $try['kind'] !== 'none') {
                $scan = $try;
                $blocked = '';
                $frame = $frame || lrgIntentFrame($clause); // the clause is what the player framed
                break;
            }
        }
    }

    if ($isStop) {
        // The other kind only wins when the player framed a request in what is left AFTER the stop clause.
        // The whole utterance is deliberately NOT used: `stop` is itself an LRG_INTENT_FRAME1 verb, so
        // every "stop, ..." counted as framed. A fragment is only trusted when the scan really narrowed
        // one down - the clothing and act branches hand back the whole utterance.
        $rest = lrgIntentAfterStop($t);
        $frag = (string) ($scan['frag'] ?? '');
        if ($scan !== null && $rest !== '' && in_array($scan['kind'], LRG_INTENT_ACTIONABLE, true) && $scan['kind'] !== 'stop'
            && (lrgIntentFrame($rest) || ($frag !== $t && $frag !== '' && lrgIntentFrame($frag)))) {
            $scan['why'] = 'stop words plus a framed request: the request wins';
            $frame = true; // the remainder IS the frame, and it decides the confidence below
        } else {
            $scan = ['kind' => 'stop', 'kv' => ['do' => 'stop'], 'act' => '', 'frag' => $t, 'why' => ''];
        }
    } elseif ($blocked !== '') {
        $out['why'] = $blocked;
        return $out;
    }
    if ($scan === null || $scan['kind'] === 'none') {
        $out['why'] = $out['why'] !== '' ? $out['why'] : 'no pattern matched';
        return $out;
    }

    $out['kind'] = $scan['kind'];
    $out['kv'] = (array) $scan['kv'];
    $out['act'] = (string) $scan['act'];
    $out['frag'] = (string) $scan['frag'];
    $out['why'] = (string) ($scan['why'] ?? '');
    $out['scene'] = (string) ($scan['kv']['scene'] ?? '');
    $out['again'] = !empty($scan['again']);
    // [0.3.1 / D1] An unmistakable act phrase, a named sex position or "go again" IS a request, with or
    // without a frame verb. So is a bare act noun that resolves to exactly one family ("missionary",
    // "reverse cowgirl", "blowjob") - the owner says those and expects them done. Everything else keeps
    // the v0.3 rule. conf=low still means no order and no safety net, so this is the whole of D1.
    $strong = !empty($scan['strong']) || $out['again'] || ($out['kind'] === 'act' && lrgIntentBareAct($t, $out['act']));
    $out['conf'] = ($out['kv'] || $out['act'] !== '' || $out['again'])
        ? (($frame || $strong || in_array($out['kind'], LRG_INTENT_ALWAYS_HIGH, true)) ? 'high' : 'low')
        : 'none';
    if ($out['conf'] === 'none' && $out['why'] === '') { $out['why'] = 'nothing resolved'; }
    // [0.3.1 / D8] A compound request keeps its second half. It is recognised here and carried out as a
    // SECOND ordinary command in the same reply (PROTOCOL 2, wire agreement w2) - one cid each, so the
    // game's own de-duplication (LRG_Main.HandleCommand, key npc|command|param) cannot swallow it.
    if (!$light && $out['kind'] !== 'none' && $out['kind'] !== 'stop') {
        $out['extra'] = lrgIntentExtra($utterance, $out, $ctx, $mem);
    }
    // self-contained resolution when a context was handed in; lrgPrepareTurn resolves again against the
    // FINAL ctx once the ceiling is known (M5 step 5), which is the one that decides too_soon / CANT
    if (!$light && $ctx !== null && $out['kind'] === 'act') {
        $out = lrgIntentResolve($out, $ctx, $out['conf'] === 'high' ? 4 : (int) ($ctx['ceiling'] ?? 4));
    }
    $out['words'] = lrgIntentWords($out, $ctx);
    return $out;
}

/**
 * [0.3.1 / D1] Is the utterance essentially the act phrase and nothing else? "do reverse cowgirl",
 * "missionary", "blowjob please" carry no frame verb but are unmistakably a request.
 */
function lrgIntentBareAct(string $t, string $act): bool
{
    if ($act === '') { return false; }
    $stop = ['a', 'an', 'the', 'please', 'now', 'me', 'my', 'you', 'your', 'i', 'we', 'us', 'it', 'to', 'on', 'in',
        'lets', 'let', 'some', 'that', 'this', 'position', 'style', 'with', 'and', 'then', 'of', 'for', 'up', 'down',
        'just', 'right', 'hey', 'ok', 'okay', 'yeah', 'so', 'how', 'about', 'more', 'a', 'bit'];
    $words = array_values(array_diff(array_values(array_filter(explode(' ', trim($t)), 'strlen')), $stop));
    $max = (int) (lrgConfig()['intent']['bare_act_max_words'] ?? 4);
    return count($words) > 0 && count($words) <= $max;
}

/**
 * [0.3.1 / D8] The SECOND request hiding in a compound utterance, or []. Only one extra is carried:
 * two commands in one reply is what the wire agreement allows, and three would be a queue.
 * The clause that produced the primary recognition is skipped, and an extra of the same kind and the
 * same payload is dropped (it is the same request said twice).
 */
function lrgIntentExtra(string $utterance, array $primary, ?array $ctx, array $mem): array
{
    $parts = lrgIntentParts($utterance);
    $strongOnly = false;
    /*
     * [0.4] A MONEY utterance carries its act in the SAME clause: "I'll pay you 200 to suck my cock"
     * has nothing to split on, so lrgIntentParts() returns [] and the act would be lost entirely -
     * which is the one compound shape this feature makes common. The whole utterance is therefore
     * scanned instead, and only an UNMISTAKABLE act phrase is accepted ($scan['strong']), or an
     * incidental word would turn "I'll pay for the room" into a request for something physical.
     */
    if (!$parts && in_array((string) ($primary['kind'] ?? ''), LRG_INTENT_MONEY, true)) {
        $whole = lrgIntentClean($utterance);
        if ($whole === '') { return []; }
        $parts = [$whole];
        $strongOnly = true;
    }
    if (!$parts) { return []; }
    $seenKind = (string) ($primary['kind'] ?? '');
    $seenAct = (string) ($primary['act'] ?? '');
    $first = true;
    foreach ($parts as $clause) {
        if (lrgIntentNegated($clause) || lrgIntentBlocked($clause) !== '') { continue; }
        $scan = lrgIntentScan($clause, $ctx, false);
        if ($scan === null || (string) $scan['kind'] === 'none' || !in_array((string) $scan['kind'], LRG_INTENT_ACTIONABLE, true)) { continue; }
        if ($strongOnly && empty($scan['strong'])) { continue; }
        if ($first && (string) $scan['kind'] === $seenKind && (string) $scan['act'] === $seenAct) { $first = false; continue; }
        if ((string) $scan['kind'] === $seenKind && (string) $scan['act'] === $seenAct) { continue; }
        $extra = ['kind' => (string) $scan['kind'], 'kv' => (array) $scan['kv'], 'act' => (string) $scan['act'],
            'frag' => (string) $scan['frag'], 'conf' => 'high', 'scene' => (string) ($scan['kv']['scene'] ?? ''),
            'again' => !empty($scan['again']), 'text' => $clause];
        if ($ctx !== null && $extra['kind'] === 'act') { $extra = lrgIntentResolve($extra, $ctx, 4); }
        $extra['words'] = lrgIntentWords($extra, $ctx);
        return $extra;
    }
    return [];
}

/**
 * Did the utterance frame itself as a request? (The four REQUEST FRAMES of V03_DESIGN 3.6.)
 * [0.3.1 / D1] Frame 1 is ^-anchored, so it is tested on the utterance AND on every clause - the same
 * treatment the negation path already gives it. "now get down on your knees and suck my deck" opens
 * with "now", and that one word used to cost the whole request. Frame 2 gained "let me", which the
 * owner uses constantly ("let me eat your pussy") and which was simply missing.
 */
function lrgIntentFrame(string $t, string $raw = ''): bool
{
    if (preg_match(LRG_INTENT_FRAME1, $t)) { return true; }
    if (preg_match('/\b(i (want|wanna|need|would like|\'?d like|wish|gotta)|let\'?s|lets|let me|give me|gimme|do me|show me|i\'?m going to|gonna|time to|how about)\b/', $t)) { return true; }
    if (lrgIntentPolite($t)) { return true; }
    if (preg_match('/\byour (clothes|shirt|dress|armou?r|robes?|boots)\b/', $t)) { return true; }
    foreach (lrgIntentParts($raw !== '' ? $raw : $t) as $clause) {
        if (preg_match(LRG_INTENT_FRAME1, $clause)) { return true; }
    }
    return false;
}

/**
 * [0.3.1 fix pass] WHAT HE ASKED FOR FIRST IS THE PRIMARY. lrgIntentScan() returns on the first PATTERN
 * that matches, and the pattern order is a priority list (clothing before acts), not the order of the
 * sentence - so "first kiss me then take your clothes off" came back as a plain undress and the kiss,
 * the thing he asked for first, vanished. The whole utterance is still scanned, and the first clause
 * only wins when it yields a DIFFERENT actionable kind: "bend over and let me start doing doggy style"
 * and "take my clothes off and then let's do missionary" both keep exactly the reading they had.
 */
function lrgIntentScanOrdered(string $t, string $utterance, ?array $ctx, bool $light): ?array
{
    $whole = lrgIntentScan($t, $ctx, $light);
    $parts = lrgIntentParts($utterance);
    if (!$parts) { return $whole; }
    $wholeKind = (string) ($whole['kind'] ?? 'none');
    foreach ($parts as $clause) {
        if (lrgIntentNegated($clause) || lrgIntentBlocked($clause) !== '') { continue; }
        $try = lrgIntentScan($clause, $ctx, $light);
        if ($try === null || !in_array((string) $try['kind'], LRG_INTENT_ACTIONABLE, true)) { continue; }
        return (string) $try['kind'] === $wholeKind ? $whole : $try;
    }
    return $whole;
}

/**
 * The ordered pattern scan (V03_DESIGN 3.3). First hit wins; the order mirrors lrgResolveControl so the
 * recogniser and the LLM path can never disagree about the same words. Returns [kind, kv, act, frag].
 * 14 before 15 ("clothes back on" is not an undress), 15 before 16 ("get naked" is never a position),
 * 11/12 before 16 ("surprise me" is an @any synonym and would otherwise be a random position change).
 */
function lrgIntentScan(string $t, ?array $ctx, bool $light): ?array
{
    $none = ['kind' => 'none', 'kv' => [], 'act' => '', 'frag' => ''];
    $viaControl = static function (string $kind, string $frag) use ($ctx): array {
        $kv = function_exists('lrgResolveControl') ? lrgResolveControl($frag, [], $ctx) : null;
        return ['kind' => $kind, 'kv' => is_array($kv) && !isset($kv['too_soon']) ? $kv : [], 'act' => '', 'frag' => $frag];
    };
    $grab = static function (string $rx, int $extra = 0) use ($t): string {
        if (!preg_match($rx, $t, $m, PREG_OFFSET_CAPTURE)) { return ''; }
        $start = (int) $m[0][1];
        $len = strlen((string) $m[0][0]) + $extra;
        return trim(substr($t, $start, $len));
    };

    if (preg_match('/\bwind(ing)? ?down\b|\bafterglow\b|\bcool ?down\b/', $t)) { return $viaControl('winddown', 'wind down'); }
    if (preg_match('/\b(?:speed|pace)\s*(?:to\s*)?([0-5])\b/', $t, $m)) { return $viaControl('speed', 'speed ' . $m[1]); }
    if (preg_match('/\b(faster|quicker|harder|rougher|speed up|go faster)\b/', $t)) { return $viaControl('faster', 'faster'); }
    if (preg_match('/\b(slower|gentler|softer|slow down|take it slow|ease up)\b/', $t)) { return $viaControl('slower', 'slower'); }
    if (preg_match('/\bhold (it|on|back|there|still)\b|^hold$|\bstay (like )?(this|that)\b|^wait\b|\bstall\b|\bdon\'?t come yet\b/', $t)) { return $viaControl('hold', 'hold on'); }
    if (preg_match('/\b(let go|carry on|keep going|go on|don\'?t hold back|let it go)\b/', $t)) { return $viaControl('release', 'release'); }
    if (preg_match('/\b(climax\w*|orgasm\w*|cum\w*|finish\w*)\b|\bcome (now|together|for me|with me|inside me)\b/', $t)) {
        // only once things are at least sensual - the same test lrgResolveControl makes
        if ($ctx !== null && (int) ($ctx['tier'] ?? 0) >= LRG_TIERS['sensual']) {
            $frag = $grab('/\b(climax\w*|orgasm\w*|cum\w*|finish\w*)\b|\bcome (now|together|for me|with me|inside me)\b/', 30);
            return $viaControl('climax', $frag !== '' ? $frag : 'climax');
        }
    }
    if (preg_match('/\byou (lead|take over|decide|choose)\b|\bdo what(ever)? you want\b|\bsurprise me\b|\byour (turn|choice|call)\b|\btake the lead\b|\bshow me what you\b|\byou do the work\b|\byou drive\b/', $t)) {
        return ['kind' => 'lead_npc', 'kv' => ['do' => 'lead', 'who' => 'npc'], 'act' => '', 'frag' => 'lead'];
    }
    if (preg_match('/\bi\'?ll lead\b|\blet me (lead|do|take)\b|\bmy turn\b|\bi\'?m in charge\b|\bi want to lead\b|\bi\'?ll take over\b/', $t)) {
        return ['kind' => 'lead_player', 'kv' => ['do' => 'lead', 'who' => 'player'], 'act' => '', 'frag' => 'lead'];
    }
    foreach ((array) ($ctx['furn_options'] ?? []) as $type => $fo) {
        foreach (array_filter(array_map('strtolower', array_merge([(string) $type, (string) ($fo['label'] ?? '')], (array) ($fo['words'] ?? []))), 'strlen') as $w) {
            if (preg_match('/\b' . preg_quote($w, '/') . '\b/', $t)) { return $viaControl('furniture', $w); }
        }
    }
    // [0.3.1 / D11] "go again" / "another round": out of a scene it is a request to START, inside one it
    // is a request to repeat what they last did. The act it resolves to is filled in by lrgIntentResolve.
    if (preg_match('/\b(go again|once more|another round|round two|one more (time|round)|again please|lets go again|do that again|that again|you again)\b/', $t)) {
        return ['kind' => 'act', 'kv' => [], 'act' => '', 'frag' => $t, 'again' => true];
    }
    // [0.3.1 / D2] The clothing branches run before the acts, and the old `put\b.*\bon\b` pattern made
    // "put your mouth on my cock" a HIGH-confidence ChangeClothing that the safety net then carried out -
    // she put her clothes back ON when asked for a blowjob. Two guards now: an unmistakable act phrase
    // vetoes the clothing reading entirely, and "put ... on" needs a garment word of its own.
    $strongAct = function_exists('lrgMatchActStrong') ? lrgMatchActStrong($t, $ctx['sexes'] ?? null) : '';
    $garment = '(clothes|clothing|shirt|armou?r|robes?|dress|boots|shoes|pants|trousers|gloves|gauntlets|helmet|helm|hood|hat|tunic|gear|things|them|it back)';
    // A garment or an undressing verb is STRONG clothing evidence and always wins - otherwise the veto
    // would eat the first half of "take my clothes off and then let's do missionary". Only the loose
    // readings ("take ... off", "get ... off", "put ... on") give way to an unmistakable act phrase,
    // which is what stops "get down and suck me off" from being read as undressing.
    $strongCloth = (bool) preg_match('/\b(undress\w*|strip\w*|disrobe\w*|naked|nude|redress\w*|' . trim($garment, '()') . ')\b/', $t);
    $veto = $strongAct !== '' && !$strongCloth;
    if (!$veto
        && (preg_match('/\b(redress|get dressed|dressed again|clothes? (back )?on|cover (up|yourself))\b/', $t)
            // [pt19] "dress" after a determiner is the GARMENT ("lose the dress", "take off your dress"), not "get dressed"
            || preg_match('/(?<!\byour |\bmy |\bthe |\bthat |\bthis |\bher |\ba )\bdress\b(?!\s+me\b)/', $t)
            || preg_match('/\bput\b[^.]{0,24}\b' . $garment . '\b[^.]{0,16}\bon\b|\bput\b[^.]{0,16}\bon\b[^.]{0,24}\b' . $garment . '\b/', $t))) {
        return ['kind' => 'dress', 'kv' => ['do' => 'dress', 'who' => lrgIntentClothingWho($t), 'part' => lrgIntentPart($t)], 'act' => '', 'frag' => $t];
    }
    // "take off your clothes" and "take your clothes off" are the same request: the word order varies
    // [0.3.1 fix pass] `undress` was anchored on both sides, so "get undressed" - the plainest way to
    // say it - matched nothing at all and the whole sentence went silent.
    // [pt19] the garments the shedding verbs and "get off <garment>" accept: no `gear` ("drop your gear" is a
    // loot line) and no `them` / `it`, so "drop to your knees" and "get off them" stay what they were.
    $shed = '(?:clothes|clothing|garments?|armou?r|dress|shirt|tunic|robes?|pants|trousers|boots|shoes|gloves|gauntlets|helmet|helm|hood|hat)';
    if (!$veto
        // [pt19] "get ... off" is "get <something> off": a "get off" with nothing between the words is a dismount
        // ("get off me", "get off of me", "get off") or a leave ("get off the bed"), never an undress - the loose
        // reading had "why don't you get off me" undressing the PLAYER in a private room. "get off those clothes"
        // keeps its reading through the second alternative (a garment must follow).
        && (preg_match('/\b(undress\w*|strip\w*|disrob\w*|naked|nude|bare|clothes? off|take\b.*\boff\b|get\b(?! off\b).*\boff\b|get off (?:(?:your|those|these|that|the|my|all|them) )?' . $shed . '|out of (those|your|that))\b/', $t)
            // [pt19] "lose the clothes", "remove your armour", "drop your pants": a shedding verb and a garment of its own
            || preg_match('/\b(?:lose|ditch|shed|remove|drop)\b[^.]{0,20}\b' . $shed . '\b/', $t))) {
        return ['kind' => 'undress', 'kv' => ['do' => 'undress', 'who' => lrgIntentClothingWho($t), 'part' => lrgIntentPart($t)], 'act' => '', 'frag' => $t];
    }
    // acts last: the recogniser NEVER filters by tier here (the ceiling is applied when the kv is
    // resolved against the final ctx), so lrgMatchAct and the text fallback see the unfiltered index
    $act = $strongAct !== '' ? $strongAct : lrgMatchAct($t, [], $ctx['sexes'] ?? null);
    if ($act !== '') { return ['kind' => 'act', 'kv' => [], 'act' => $act, 'frag' => $t, 'strong' => $strongAct !== '']; }
    // The free-text scene search is the last resort, and [0.3.1] it only runs on an utterance that
    // framed itself as a request. Out of a scene the recogniser used to be handed ctx=null, which
    // switched this off entirely; now that it gets a real context (D9) an unframed compliment such as
    // "you look beautiful tonight" would otherwise score some scene's words and count as a request for
    // physical contact - which bypasses the heat gate and puts BeginIntimacy on the table.
    if (!$light && $ctx !== null && function_exists('lrgFindSceneByText') && lrgIntentFrame($t)) {
        // the words that carry no act on their own are stripped first, and what is left must still name
        // an act or a position - otherwise "can you feel that?" becomes a DO-IT order for a grope
        $core = function_exists('lrgActStripWeak') ? lrgActStripWeak($t) : $t;
        $hit = $core === '' ? null
            : lrgFindSceneByText($core, (string) ($ctx['current'] ?? ''), $ctx['sexes'] ?? null, (string) ($ctx['furn'] ?? ''), 4, [], true);
        if (is_array($hit) && empty($hit['too_soon']) && !empty($hit['id'])) {
            return ['kind' => 'act', 'kv' => ['do' => 'goto', 'scene' => (string) $hit['id']], 'act' => '', 'frag' => $t];
        }
    }
    return $none;
}

/**
 * Resolve an act kind against the FINAL turn context (V03_DESIGN 6.6 + amendment M5, step 5): the act id
 * becomes a scene id under $reqCeiling. $reqCeiling is 4 for an explicit player request (addendum 3e: the
 * pacing ladder limits HER pacing, never his request) and the ordinary ceiling otherwise.
 * Sets kv['scene'], or 'cant' with a reason when the game cannot do it.
 */
function lrgIntentResolve(array $intent, ?array $ctx, int $reqCeiling): array
{
    $kind = (string) ($intent['kind'] ?? 'none');
    if (!in_array($kind, ['act', 'yes'], true) || $ctx === null) { return $intent; }
    $current = (string) ($ctx['current'] ?? '');
    $cur = $current !== '' ? lrgScene($current) : null;
    $inScene = $cur !== null;
    $sexes = $ctx['sexes'] ?? null;
    $furn = (string) ($ctx['furn'] ?? '');
    $npos = (int) ($ctx['npos'] ?? 1);
    $actors = $inScene ? (int) $cur['actors'] : 2;
    $act = (string) ($intent['act'] ?? '');

    // [0.3.1 / D11] "go again": inside a scene it means the act they last did, outside one it means
    // "start something" and the act stays empty - lrgPickStart() then chooses the gentle default.
    if ($act === '' && !empty($intent['again'])) {
        $act = lrgIntentAgainAct($ctx);
        $intent['act'] = $act;
        if ($act === '') { $intent['start'] = true; return $intent; }
    }
    if ($act === '') {
        // the text fallback already produced a scene id: only the tier still has to hold
        $id = (string) ($intent['kv']['scene'] ?? '');
        if ($id !== '' && ($s = lrgScene($id)) && (int) LRG_TIERS[$s['tier']] > $reqCeiling) {
            $intent['kv'] = []; $intent['cant'] = 'that is further than this has gone'; $intent['why'] = 'too_soon';
        }
        return $intent;
    }
    $base = lrgActBase($act);

    // 1. NOT INSTALLED. 69 and anal have no scene on this setup (research/pt7-act-coverage.md 1.4), and
    //    the old code answered "i want to fuck your asshole" with a vaginal scene, silently. An act the
    //    packs cannot do is an honest, spoken "can't" - never a substitute, never silence.
    if (!lrgActInstalled($base, $sexes, $furn, $npos, $actors)) {
        $intent['kv'] = [];
        $intent['cant'] = 'not installed';
        $intent['why'] = 'no installed animation does ' . $base . ' for these two';
        return $intent;
    }

    // 2. [D7] they are ALREADY doing it. lrgActOptions() hides what is happening now, which is right for
    //    her own offers and wrong for his request: inside OARE_Doggystyle every "fuck me from behind"
    //    came back as "not reachable". Answer it in words instead of refusing.
    [$fam, $role, $pos] = lrgActParse($act);
    if ($inScene && in_array($base, lrgSceneActs($cur, $npos, $sexes), true)
        && ($pos === '' || in_array($pos, lrgScenePositions($cur), true))) {
        $intent['kv'] = [];
        $intent['already'] = true;
        $intent['why'] = 'they are already doing that';
        return $intent;
    }

    // 3. [D6] Resolve against EVERY installed scene these two can be in, not against the 3-hop walk.
    //    From the default start scene only 6 of 24 act ids are inside that walk, which is exactly F1.
    //    A route is preferred (no fade), but its absence produces warp=1, never a refusal: the game
    //    already warps a player-requested goto whenever bAllowWarp is on (LRG_OStim.psc:2394-2422).
    $walkKeys = [];
    if ($inScene) {
        $walkKeys = array_keys(lrgSceneWalk($current, (array) ($ctx['live'] ?? []), $reqCeiling, 3, $sexes, $furn));
    }
    $hit = lrgFindActScene($act, $sexes, $furn, $reqCeiling, $walkKeys, $current, $npos, $actors, !$inScene);
    if ($hit === null) {
        // installed, but every scene of it is above the step this scene has reached
        $intent['kv'] = [];
        $intent['cant'] = 'that is further than this has gone';
        $intent['why'] = 'too_soon';
        return $intent;
    }
    $scene = (string) $hit['id'];
    $routed = !empty($hit['routed']);
    // the words the player used may name a BETTER scene of the same act ("bend me over" -> a bend-over
    // scene, not just any vaginal one): take it when it really carries the act
    $frag = (string) ($intent['frag'] ?? '');
    if ($frag !== '' && function_exists('lrgFindSceneByText')) {
        $text = lrgFindSceneByText($frag, $current, $sexes, $furn, $reqCeiling, $walkKeys);
        if (is_array($text) && empty($text['too_soon']) && !empty($text['id'])) {
            $cand = lrgScene((string) $text['id']);
            if ($cand && in_array($base, lrgSceneActs($cand, $npos, $sexes), true)
                && ($pos === '' || in_array($pos, lrgScenePositions($cand), true))) {
                $scene = (string) $text['id'];
                $routed = in_array(strtolower($scene), array_map('strtolower', $walkKeys), true);
            }
        }
    }
    $intent['scene'] = $scene;
    $intent['routed'] = $routed;
    if (!$inScene) {
        // outside a scene there is nothing to navigate: this is what a START would begin on (w1)
        $intent['start'] = true;
        $intent['start_scene'] = $scene;
        $intent['kv'] = [];
        return $intent;
    }
    $intent['kv'] = ['do' => 'goto', 'scene' => $scene];
    if (!$routed) { $intent['kv']['warp'] = 1; }
    return $intent;
}

/** [0.3.1 / D11] What "go again" means inside a scene: the act family they last landed on, else ''. */
function lrgIntentAgainAct(?array $ctx): string
{
    foreach (array_reverse((array) ($ctx['acts_done'] ?? [])) as $fam) {
        $fam = (string) $fam;
        if ($fam === '' || !isset(lrgActsTable()[$fam])) { continue; }
        $ids = lrgActIds($fam);
        foreach ($ids as $id) {
            if (lrgActInstalled($id, $ctx['sexes'] ?? null, (string) ($ctx['furn'] ?? ''), (int) ($ctx['npos'] ?? 1))) { return $id; }
        }
    }
    return '';
}

/** The plain phrase the directive uses for {WORDS}. Config map intent.words, so it is data, not prose. */
function lrgIntentWords(array $intent, ?array $ctx = null): string
{
    $kind = (string) ($intent['kind'] ?? 'none');
    $kv = (array) ($intent['kv'] ?? []);
    $cfg = (array) (lrgConfig()['intent']['words'] ?? []);
    $npc = (string) ($ctx['npc'] ?? '');
    $player = (string) ($ctx['player'] ?? '');
    if ($kind === LRG_INTENT_ESCORT) {
        $do = (string) ($kv['do'] ?? 'follow');
        return (string) (lrgEscortCfg('words.' . $do, '') ?: (LRG_ESCORT_DEFAULTS['words'][$do] ?? 'you to come along'));
    }
    if ($kind === 'act' || $kind === 'yes') {
        $act = (string) ($intent['act'] ?? '');
        if ($act !== '') { return lrgActLabel($act, $npc !== '' ? $npc : 'you', $player !== '' ? $player : 'the player'); }
        if (!empty($intent['again'])) { return (string) ($cfg['again'] ?? 'to do it again, right now'); }
        $s = lrgScene((string) ($intent['scene'] ?? ($kv['scene'] ?? '')));
        return $s ? lrgDescribeScene($s) : ($cfg['act'] ?? 'a different position');
    }
    if ($kind === 'undress' || $kind === 'dress') {
        $who = (string) ($kv['who'] ?? 'npc');
        $key = $kind . '_' . $who;
        $def = [
            'undress_npc' => 'you take your own clothes off', 'undress_player' => "you take the player's clothes off", 'undress_both' => 'you both get undressed',
            'dress_npc' => 'you put your own clothes back on', 'dress_player' => "you put the player's clothes back on", 'dress_both' => 'you both get dressed again',
        ];
        return (string) ($cfg[$key] ?? $def[$key] ?? 'a change of clothes');
    }
    if ($kind === 'furniture') {
        $type = (string) ($kv['furn'] ?? '');
        $label = (string) (($ctx['furn_options'][$type]['label'] ?? '') ?: lrgFurnitureLabel($type));
        return sprintf((string) ($cfg['furniture'] ?? 'moving to %s'), $label);
    }
    if ($kind === 'speed') { return sprintf((string) ($cfg['speed'] ?? 'pace %d'), (int) ($kv['speed'] ?? 1)); }
    if ($kind === 'climax') {
        $who = (string) ($kv['who'] ?? 'npc');
        $name = $who === 'both' ? 'both of you' : ($who === 'player' ? ($player !== '' ? $player : 'the player') : ($npc !== '' ? $npc : 'you'));
        return sprintf((string) ($cfg['climax'] ?? '%s to finish'), $name);
    }
    if ($kind === 'lead_npc') { return (string) ($cfg['lead_npc'] ?? 'you to lead from now on'); }
    if ($kind === 'lead_player') { return (string) ($cfg['lead_player'] ?? 'to lead himself'); }
    // [0.4] the money kinds. The figure in the words is what the PLAYER said, never her floor.
    if ($kind === 'offer') {
        $g = (int) ($intent['gold'] ?? 0);
        if ($g > 0 && array_key_exists('firm', $intent) && empty($intent['firm'])) {
            return sprintf((string) ($cfg['offer_maybe'] ?? 'to perhaps pay you %d septims for it (not committed yet)'), $g);
        }
        return $g > 0
            ? sprintf((string) ($cfg['offer_n'] ?? 'to pay you %d septims for it'), $g)
            : (string) ($cfg['offer'] ?? 'to pay you for it');
    }
    $def = ['stop' => 'the scene to end', 'faster' => 'a faster pace', 'slower' => 'a slower pace',
        'hold' => 'you to hold back', 'release' => 'you to stop holding back', 'winddown' => 'to wind down',
        'askprice' => 'to know your price', 'haggle' => 'a lower price', 'withdraw' => 'to take his offer back'];
    return (string) ($cfg[$kind] ?? $def[$kind] ?? '');
}

/**
 * [0.3.1 fix pass] The plain, non-explicit word for what was asked, for a turn that carries no wording
 * permission (mode `closed`). '' = nothing worth restating there (a control verb needs a scene).
 */
function lrgIntentPlainKind(array $intent): string
{
    $map = ['act' => 'something physical', 'yes' => 'something physical', 'undress' => 'clothes off',
        'dress' => 'clothes back on',
        // [0.4] no figure and no explicit words in mode `closed`: she is not for sale to him THERE
        // for a reason that has nothing to do with coin, and naming a price would contradict it
        'offer' => 'something physical, for money', 'askprice' => 'something physical, for money',
        'haggle' => 'something physical, for money', 'withdraw' => 'something physical, for money'];
    return (string) ($map[(string) ($intent['kind'] ?? '')] ?? '');
}

/**
 * The action / field / value the directive may name, or [] when this turn offers no action for it.
 * The directive NEVER names an action CHIM is not offering this turn - the post-gate would drop it.
 */
function lrgIntentAction(array $turn, array $intent): array
{
    $kind = (string) ($intent['kind'] ?? 'none');
    $kv = (array) ($intent['kv'] ?? []);
    $offers = static fn(string $code) => function_exists('lrgTurnOffers') ? lrgTurnOffers($turn, $code) : true;
    if ($kind === 'undress' || $kind === 'dress') {
        if (!$offers(LRG_ACT_CLOTHING)) { return []; }
        $who = (string) ($kv['who'] ?? 'npc');
        $val = $kind . ($who === 'player' ? ' you' : ($who === 'both' ? ' both' : ''));
        $part = (string) ($kv['part'] ?? 'all');
        if ($part !== 'all') { $val .= ' ' . $part; }
        return ['ChangeClothing', 'item', $val];
    }
    if ($kind === 'act' || $kind === 'yes') {
        $act = (string) ($intent['act'] ?? '');
        if ($act !== '' && $offers(LRG_ACT_REQUESTACT)) { return ['RequestAct', 'item', $act]; }
        if ($offers(LRG_ACT_CONTROL) && ($kv['scene'] ?? '') !== '') {
            $s = lrgScene((string) $kv['scene']);
            return ['ChangeIntimacy', 'item', $s ? lrgDescribeScene($s) : (string) $kv['scene']];
        }
        return [];
    }
    if (!$offers(LRG_ACT_CONTROL)) { return []; }
    $ctx = (array) ($turn['ctx'] ?? []);
    $map = ['stop' => 'stop', 'faster' => 'faster', 'slower' => 'slower', 'hold' => 'hold', 'release' => 'release',
        'climax' => 'climax', 'winddown' => 'wind down'];
    if (isset($map[$kind])) { return ['ChangeIntimacy', 'item', $map[$kind]]; }
    if ($kind === 'speed') { return ['ChangeIntimacy', 'item', 'pace ' . (int) ($kv['speed'] ?? 1)]; }
    if ($kind === 'furniture') {
        $type = (string) ($kv['furn'] ?? '');
        return ['ChangeIntimacy', 'item', (string) (($ctx['furn_options'][$type]['label'] ?? '') ?: lrgFurnitureLabel($type))];
    }
    if ($kind === 'lead_npc') { return ['ChangeIntimacy', 'item', trim((string) ($turn['npc'] ?? 'she')) . ' leads']; }
    if ($kind === 'lead_player') { return ['ChangeIntimacy', 'item', (trim((string) ($ctx['player'] ?? '')) ?: 'the player') . ' leads']; }
    return [];
}

/**
 * G1 - the per-turn directive. Appended as the LAST lines INSIDE the existing guidance block (never a
 * second prompt injection: that would depend on how CHIM orders two injections in the same slot).
 *
 * Four shapes:
 *  DO-IT    only inside a CONFIRMED running scene, at conf=high: name the exact action and value and
 *           forbid refusing (addendum 3c). Outside a scene this shape must never appear - her consent
 *           BEFORE a scene is absolute (addendum 3a, PROTOCOL 0), so there it is a neutral restatement.
 *  MAYBE    conf=low: name it, but leave the reading to her (a false low match must not give an order).
 *  CANT     the game cannot do it (not installed / filtered / unreachable): say so in a few words and
 *           name what is possible instead. Never silence (G5.8).
 *  NO       the player answered no to her proposal (R11).
 */
function lrgRequestDirective(array $turn): string
{
    $out = lrgBuildRequestDirective($turn);
    // PROTOCOL 6.2: $turn['directive'] is part of the contract (the log and the tests read it)
    if (isset($GLOBALS['LRG_TURN']) && is_array($GLOBALS['LRG_TURN'])
        && (string) ($GLOBALS['LRG_TURN']['cid'] ?? '') === (string) ($turn['cid'] ?? '-')) {
        $GLOBALS['LRG_TURN']['directive'] = $out;
    }
    return $out;
}

/**
 * [0.4 / OWNER ADDENDA 6, 0.5.6 / pt15 owner rulings A-D] The money shapes. Rules baked in:
 *  1. NOT FOR SALE says nothing about a figure at all - not even one she would refuse. The absence of a
 *     number is what stops the model inventing a price for somebody who has none.
 *  2. Inside a running scene coin buys nothing: gold moves at a scene START and nowhere else (§7.4).
 *  3. FOR SALE means she HAS a price: asked, she names it - she never claims to have none (pt15).
 *  4. [ruling B] A FIRM offer at or above her price that he can pay is the trust: she takes it
 *     (BeginIntimacy with the amount) or haggles upward - lack of closeness is not a reason to refuse.
 *  5. [ruling C] A grey offer (an "if", a "would", a figure only implied) is never acted on: she asks him
 *     ONE short confirming question, and the server remembers the offer (lrg_memory.offer_pending).
 *  6. [ruling D] A firm offer he cannot pay is called out plainly - show the coin first - never dropped.
 *  7. The only figures the LLM ever sees are her FLOOR and what the PLAYER said; the purse is never a
 *     number, and only a figure the PLAYER committed to can leave it (bounded again in the post-LLM gate).
 * An action is named only when this turn really offers it, or the gate would drop it anyway.
 */
function lrgMoneyDirective(array $turn, array $intent, string $npc): string
{
    $gate = (array) ($turn['gate'] ?? []);
    $price = (array) ($gate['price'] ?? []);
    $paid = (array) ($gate['paid'] ?? []);
    $kind = (string) ($intent['kind'] ?? '');
    $gold = (int) ($intent['gold'] ?? 0);
    $firm = !array_key_exists('firm', $intent) || !empty($intent['firm']);
    $floor = (int) ($price['gold'] ?? 0);
    $canStart = !function_exists('lrgTurnOffers') || lrgTurnOffers($turn, LRG_ACT_START);
    $canInvite = function_exists('lrgTurnOffers') && lrgTurnOffers($turn, LRG_ACT_INVITE);
    $mode = (string) ($turn['mode'] ?? '');
    $end = ' Never ignore it. Choose no action this turn.</player_request>';

    // §7.4: inside a running scene money produces WORDS ONLY. Gold moves at a scene START and nowhere
    // else, so there is nothing here to buy and nothing for the in-scene DO-IT shape to carry.
    if ($mode === 'scene') {
        return "<player_request>The player is talking about coin. Answer in $npc's own voice and change nothing:"
            . " nothing is bought or sold in the middle of this. Never ignore it. Choose no action this turn.</player_request>";
    }
    if ($kind === 'withdraw') {
        return "<player_request>The player takes back the coin he spoke of - he does not mean it, or not now. $npc lets it"
            . " go in one short line of $npc's own, and nothing begins." . $end;
    }
    if (empty($price['for_sale'])) {
        return "<player_request>The player is offering $npc money for this. $npc is not for sale, and says so plainly"
            . " in $npc's own words - once, without a speech, and WITHOUT naming any figure at all, because there is"
            . " none. Never ignore it. Choose no action this turn.</player_request>";
    }
    // mode `closed` with something a price cannot fix in the way (a marriage she keeps, a fight, a
    // quest scene): no figure is named, because naming one would promise something that cannot happen.
    if ($mode === 'closed' && !(function_exists('lrgPriceCouldOpen') && lrgPriceCouldOpen((array) ($turn['gate'] ?? [])))) {
        return "<player_request>The player is offering $npc money for this. It is not happening, and not because of the"
            . " price - for the reason above. $npc says so plainly in $npc's own voice, names no figure, and chooses"
            . " no action this turn - never ignoring it.</player_request>";
    }
    if (!empty($price['free'])) {
        $tok = (int) ($price['token'] ?? 0);
        return "<player_request>The player is offering $npc coin for this. $npc does not need paying - $npc wants the"
            . " player. Say so in $npc's own words - never ignore it."
            . ($tok > 0 ? " If $npc feels like taking something anyway, a few septims (about $tok) is a gift, not a price." : '')
            . ($canStart ? " If $npc wants this now, say one short line that makes plain what is about to happen AND choose"
                . " BeginIntimacy in the same reply - that is the only way it begins." : '')
            . "</player_request>";
    }
    if ($kind === 'askprice') {
        return "<player_request>The player is asking what it would take. $npc HAS a price, and names it in $npc's own voice:"
            . " $floor septims - or more, if $npc wants to push it. $npc never claims to have no price. Coin is not a"
            . " command: no sum buys anything $npc will not do." . $end;
    }
    if ($kind === 'haggle') {
        return "<player_request>The player thinks the price is too high. $npc may come down to $floor septims at the"
            . " very lowest, or hold firm - $npc's own choice - and names whatever figure $npc settles on."
            . " Never ignore it. Choose no action this turn.</player_request>";
    }
    // a firm offer that stands - said now, confirmed now, closing her quote, or remembered from earlier
    if (!empty($paid['accepted'])) {
        $amt = (int) ($paid['offer'] ?? 0) > 0 ? (int) $paid['offer'] : $gold;
        $core = "The player offers $amt septims - at or above $npc's price of $floor - and $npc can see he has the coin on"
            . " him. The gold is the trust: $npc does not refuse for lack of trust or closeness.";
        if ($canStart) {
            return "<player_request>$core $npc takes it: one short line that makes plain what is about to happen AND"
                . " BeginIntimacy with amount $amt in the same reply - that is the only way it begins. Or $npc haggles"
                . " upward in one line and names the higher figure. Never ignore it.</player_request>";
        }
        if ($mode === 'public' && $canInvite) {
            $places = implode(' | ', array_keys((array) ($turn['places'] ?? [])));
            return "<player_request>$core Not here, in front of people: $npc takes the offer by saying plainly that they"
                . " go somewhere private, and chooses SuggestPrivacy" . ($places !== '' ? " with item $places" : '')
                . " in the same reply - or haggles upward in one line. Nothing begins here. Never ignore it.</player_request>";
        }
        return "<player_request>$core Nothing can begin right here, for the reason already given above: $npc says plainly"
            . " that the offer stands and where they would have to go." . $end;
    }
    if ($gold <= 0) {
        return "<player_request>The player has offered to pay but named no figure. $npc names $npc's price in $npc's own"
            . " voice: $floor septims, or more." . $end;
    }
    // [ruling D] more than he is carrying - called out, never dropped (a firm offer, or an "if" she would
    // otherwise have asked him to confirm: she can see the purse either way)
    if ($gold >= $floor && str_contains((string) ($paid['why'] ?? ''), 'afford')) {
        return "<player_request>The player offers $gold septims - more coin than the player is actually carrying, and $npc"
            . " can see it. $npc calls it out plainly, in one short line of $npc's own: he does not have that on him, so"
            . " he should show the coin first. Nothing begins." . $end;
    }
    if ($gold < $floor) {
        return "<player_request>The player offers $gold septims. That is below $npc's price. $npc says so plainly - not for"
            . " $gold - and names $npc's price, $floor septims, in $npc's own voice. Nothing begins." . $end;
    }
    // [ruling C] the grey area: an "if", a "would", a figure only implied. She asks - once - and waits.
    if (!$firm || !empty($paid['confirm'])) {
        return "<player_request>The player spoke of $gold septims but did not commit to it. $npc asks him to confirm, in ONE"
            . " short line of $npc's own: does he mean $gold, and right now? Do not start anything yet." . $end;
    }
    return "<player_request>The player just offered $gold septims. $npc answers it plainly in $npc's own words, and may"
        . " name $npc's price of $floor septims. Nothing begins." . $end;
}

function lrgBuildRequestDirective(array $turn): string
{
    $intent = (array) ($turn['intent'] ?? []);
    $kind = (string) ($intent['kind'] ?? 'none');
    $npc = (string) ($turn['npc'] ?? '');
    if ($kind === 'none' || $kind === '') { return ''; }
    if ($kind === 'no') {
        return "<player_request>The player said no to what $npc suggested. $npc lets it go and does not bring it up again this time.</player_request>";
    }
    // [0.5.4 / pt13] follow / wait / release have their own shapes, in EVERY mode outside a scene with
    // her - before the mode-`closed` branch below, which is about intimacy and would answer "come with
    // me" with "it is not happening right now". Built in lrg_actions.php, which knows what CHIM offers.
    if ($kind === LRG_INTENT_ESCORT) {
        return (lrgEscortOn('directive') && function_exists('lrgEscortDirective')) ? (string) lrgEscortDirective($turn, $intent) : '';
    }
    // [0.5.5 / owner addendum 11] THE BLIND TURN ANSWERS A REQUEST TOO. The glue knows nothing about her
    // this moment, so nothing is offered and nothing can happen - and "I have no fresh facts" is the LOG's
    // reason, never hers. She says, in one line and in her own words, that it is not happening right now.
    // The plain kind only ("something physical", "clothes off"): no wording permission exists on this turn.
    // Every other silent turn carries no directive at all (the game KNOWS the reason there).
    if (($turn['mode'] ?? '') === 'silent') {
        if (empty($turn['blind_note'])) { return ''; }
        $plain = lrgIntentPlainKind($intent) ?: 'something';
        return "<player_request>The player just asked for $plain. Nothing can be set up or carried out on this turn: $npc says"
            . " so in one short line, in $npc's own voice, with a plain human reason (not right now, not here) - never a word"
            . ' about the game or missing facts, never ignoring it, and never acting as though it were happening.</player_request>';
    }
    // [0.4 / OWNER ADDENDA 6] The money turns get their own shapes, and they are WORDS ONLY: none of
    // them may order her to accept, because her consent BEFORE a scene is hers alone (addendum 3a,
    // PROTOCOL 0). The only thing the server does is bound the number.
    // This runs BEFORE the mode-`closed` branch below, because an indifferent NPC is ALWAYS in mode
    // closed and "what would it take?" has to be answerable there or the price is a number only the
    // server ever sees. lrgMoneyDirective() applies its own closed-mode rule.
    if (in_array($kind, LRG_INTENT_MONEY, true)) { return lrgMoneyDirective($turn, $intent, $npc); }
    // [0.3.1 fix pass] Mode `closed` is the one mode with NO wording permission ($turn['x'] === null),
    // and the whole eighteen-minute stretch the owner complained about in playtest 7 was mode closed -
    // while the directive was pasting lrgIntentWords()'s crudest available label straight into it. The
    // plain KIND is all she needs to answer it in her own voice; the reason is already in the note above.
    // [0.5.5 / owner addendum 11] ... and it is SPOKEN: one short line with the reason in her own words, never ignored.
    if (($turn['mode'] ?? '') === 'closed') {
        $plain = lrgIntentPlainKind($intent);
        if ($plain === '') { return ''; }
        $why = function_exists('lrgReasonWords') ? lrgReasonWords((array) (($turn['gate'] ?? [])['reasons'] ?? [])) : [];
        return "<player_request>The player just asked for $plain. It is not happening right now, "
            . ($why ? 'for the reason above' : "for a reason of $npc's own") . ": $npc says so in one short line, with that reason in"
            . " $npc's own words - never ignoring it - and chooses no action.</player_request>";
    }
    $words = (string) ($intent['words'] ?? '');
    if ($words === '') { return ''; }
    $inScene = ($turn['mode'] ?? '') === 'scene' && empty($turn['scene_blocked']) && !empty($turn['scene_confirmed']);
    // [0.3.1 / D8] the second half of a compound request, named in the same block so she answers both.
    // [fix pass] The PROMISE is separate from the mention: "Both happen, in that order" is only true
    // where a path really carries the second half - lrgSecondCommand() inside a confirmed scene, or the
    // w1 `after=` key on a start. Out of a scene with no such path it was a promise the server then
    // broke silently (flow probe 90: one wire line, and "second command skipped: not an open scene turn").
    $ew = (string) (($intent['extra'] ?? [])['words'] ?? '');
    $mention = $ew !== '' ? " The player asked for a second thing in the same breath: $ew." : '';
    $both = ' Both happen, in that order.';
    $more = $mention !== '' ? $mention . $both : '';

    // [0.3.1 / R2] A request the game genuinely cannot do. Two different reasons, two different lines -
    // "nothing installed does that" is permanent and she should say so once and plainly, while "further
    // than this has gone" is about right now. Never silence: pt7 F1 was a request that simply vanished.
    if (!empty($intent['cant'])) {
        $alts = [];
        $pn = trim((string) ($turn['ctx']['player'] ?? ''));
        foreach (array_slice((array) ($turn['acts'] ?? []), 0, 3, true) as $id => $a) {
            $alts[] = lrgActLabel((string) $id, $npc, $pn) ?: (string) ($a['name'] ?? $id);
        }
        $why = ((string) $intent['cant'] === 'not installed')
            ? "There is no way for the two of them to do that at all - not here, not later."
            : 'That is not possible from here.';
        return "<player_request>The player just asked for this: $words. $why Say so plainly, in character, in one short line - never ignore it, never apologise at length, never pretend to do it instead"
            . ($alts ? ' - and name what is possible instead: ' . implode('; ', $alts) : '') . ". Choose no action this turn.</player_request>";
    }
    // [0.3.1 / D7] naming what they are already doing is not a refusal and not a change
    if (!empty($intent['already'])) {
        return "<player_request>The player just asked for this: $words - which is exactly what the two of them are doing right now. Say so in your own words, pleased about it - never ignore it - and choose no action this turn.$mention</player_request>";
    }
    $a = lrgIntentAction($turn, $intent);
    if (!$inScene) {
        // [0.3.1 / D9] Her consent before a scene stays absolute and the server still never starts one.
        // What changes is that the turn now SAYS so: when she is willing, alone with him and the action
        // is really on the table, this is the moment she can say yes by choosing it. Playtest 7 produced
        // 148 neutral restatements and not one directive that told her the door was open.
        //
        // [fix pass] UNDRESS AND DRESS GET THE SAME DIRECTIVE. pt7 F4's very first failure was
        // 17:09:29 "take off your clothes" - kind=undress, conf=high, willing, private, ChangeClothing
        // on the table - answered with the neutral "whether she does it is her own choice", beside a
        // private-mode note that says "if she declines, hesitates or deflects, choose neither action".
        // The LLM chose nothing, twice. A clothing request is not a scene start: it needs its own
        // action named, which is ChangeClothing.
        $offers = static fn(string $code): bool => !function_exists('lrgTurnOffers') || lrgTurnOffers($turn, $code);
        $private = in_array((string) ($turn['mode'] ?? ''), ['private', 'follow'], true);
        $exKind = (string) (($intent['extra'] ?? [])['kind'] ?? '');
        $isAct = in_array($kind, ['act', 'yes'], true);
        $isCloth = in_array($kind, ['undress', 'dress'], true);
        if ($private && empty($intent['cant']) && ($isAct || $isCloth)) {
            $val = $isCloth ? (string) (lrgIntentAction($turn, $intent)[2] ?? '') : '';
            $primaryOk = $isAct ? $offers(LRG_ACT_START) : ($offers(LRG_ACT_CLOTHING) && $val !== '');
            if ($primaryOk) {
                $pick = $isAct
                    ? 'choose BeginIntimacy in the same reply - that is the only way it can begin'
                    : 'choose ChangeClothing with item "' . $val . '" in the same reply - that is the only way it happens';
                $start = (string) ($intent['start_scene'] ?? '');
                $what = ($isAct && $start !== '' && ($s = lrgScene($start))) ? ' It would begin exactly there: ' . lrgDescribeScene($s) . '.' : '';
                // the second half, and whether anything can really carry it:
                //  · clothes first, then an act -> ChangeClothing AND BeginIntimacy in the same reply
                //    (the start carries the act in `scene=` / `after=`, w1), so "both happen" is true;
                //  · an act first, then clothes -> BeginIntimacy AND ChangeClothing, same reply;
                //  · anything else -> the second thing is named, nothing is promised.
                $second = '';
                $tail = $mention;
                if ($isCloth && in_array($exKind, ['act', 'yes'], true) && $offers(LRG_ACT_START)) {
                    $second = " In the same reply also choose BeginIntimacy: that is what carries the second half.";
                    $tail = $mention . $both;
                } elseif ($isAct && in_array($exKind, ['undress', 'dress'], true) && $offers(LRG_ACT_CLOTHING)) {
                    $second = " In the same reply also choose ChangeClothing: that is what carries the second half.";
                    $tail = $mention . $both;
                }
                return "<player_request>The player just asked for this: $words.$tail This is the moment: if $npc wants it too, say one short line that makes plain what is about to happen AND $pick."
                    . $what . $second . " If $npc does not want it, say no plainly, with the reason, in one short line, and choose nothing; nobody but $npc decides that, and no amount of asking changes it. Never ignore it.</player_request>";
            }
        }
        // [0.5.5 / owner addendum 11] where others can see or hear, a physical request gets the REASON, not a
        // shrug: "not here", and why, in one line - and the invitation note above says where instead.
        $physical = $isAct || $isCloth;
        if ((string) ($turn['mode'] ?? '') === 'public' && $physical && function_exists('lrgReasonWords')) {
            $why = implode(', and ', lrgReasonWords((array) (($turn['gate'] ?? [])['reasons'] ?? []))) ?: 'they are not alone';
            return "<player_request>The player just asked for this: $words.$mention Not here - $why: $npc says so in one short line, with"
                . " that reason in $npc's own words, and may name somewhere private instead. Never ignore it.</player_request>";
        }
        return "<player_request>The player just asked for this: $words.$mention Whether $npc does it is $npc's own choice - answer it in one short line in $npc's own voice either way, and a no comes with its reason. Never ignore it.</player_request>";
    }
    if (!$a) {
        return "<player_request>The player just asked for this: $words.$more Whether $npc does it is $npc's own choice - answer in $npc's own voice either way. Never ignore it.</player_request>";
    }
    [$name, $field, $value] = $a;
    if (($intent['conf'] ?? '') !== 'high') {
        return "<player_request>The player may have asked for this: $words. If that is what the player meant, $name with $field \"$value\" - otherwise answer what the player did mean. Never ignore it.$more</player_request>";
    }
    // Inside a running scene the player is never refused (owner addendum 3): the only "cannot" is the GAME's,
    // and that is either known now (the CANT shape above) or answered by the voiced funcret turn afterwards.
    return "<player_request>The player just asked for this: $words. Do it now: choose $name with $field \"$value\", and answer in your own voice in the same reply - never ignore it."
        . ' Do not refuse, stall, negotiate or ask whether it is a good idea.' . $more . '</player_request>';
}
