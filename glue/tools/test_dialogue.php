<?php
/**
 * LoreRim Glue - unit checks of PHASE 2's MATCHING and CLASSIFICATION (V04_BUILD_PLAN 5.2).
 *
 * No game, no CHIM, no LLM, no database: every function under test is pure or goes through the seams.
 * The end-to-end behaviour is in tools/flows/scenarios/d20_*.php .. d41_*.php; this file is the fast
 * table-driven half - the wire parser, the three matching modes' thresholds, the class vocabulary, the
 * commit heuristics in their documented ORDER, the labels and the param shape.
 *
 *   php tools/test_dialogue.php            # everything
 *   php tools/test_dialogue.php --quiet    # failures only
 * Exit 0 = every check passed.
 */

error_reporting(E_ALL);
ini_set('display_errors', 'stderr');

$root = dirname(__DIR__);
require_once $root . '/server/lorerim_glue/lib/lrg_dialogue.php';

$quiet = in_array('--quiet', $argv, true);
$ok = 0;
$fail = 0;
function chk(string $name, $cond, $detail = ''): void
{
    global $ok, $fail, $quiet;
    if ((bool) (is_callable($cond) ? $cond() : $cond)) { $ok++; if (!$quiet) { echo "  [ok] $name\n"; } }
    else { $fail++; echo "  [FAIL] $name" . ($detail !== '' ? '  [' . substr((string) (is_callable($detail) ? $detail() : $detail), 0, 240) . ']' : '') . "\n"; }
}
function head(string $s): void { echo "\n$s\n"; }

// the world every check runs in: an empty in-memory index, no database, a fixed clock
$GLOBALS['LRG_TEST_INDEX'] = ['rows' => [], 'layers' => []];
$GLOBALS['LRG_DLG_TEST_QUEUE'] = [];
$GLOBALS['LRG_TEST_NOW'] = 1700000000;
$GLOBALS['PLAYER_NAME'] = 'Testplayer';
unset($GLOBALS['db']);

// ------------------------------------------------------------------ 1. the wire
head('1. the wire: lrg_topics entry parsing and the raw LAST key');
$e = lrgDlgParseEntries('0~100~1~16777215~I need work.~~1~101~0~-~Never mind.', 1);
chk('two entries', count($e) === 2, json_encode($e));
chk('pos / topicIndex / new / colour are parsed', (int) $e[0]['pos'] === 0 && (int) $e[0]['i'] === 100
    && (int) $e[0]['new'] === 1 && (int) $e[0]['col'] === 16777215, json_encode($e[0]));
chk('a "-" field reads as 0, never as a false index', (int) $e[1]['col'] === 0 && (int) $e[1]['new'] === 0, json_encode($e[1]));
$e2 = lrgDlgParseEntries("0~100~0~-~What's the word; any news = at all?", 1);
chk('a text containing ; and = survives whole', (string) $e2[0]['text'] === "What's the word; any news = at all?",
    (string) ($e2[0]['text'] ?? ''));
$e3 = lrgDlgParseEntries('7~-~-~-~A tail entry, texts only', 2);
chk('a part=2 tail entry keeps its position and is marked tail',
    (int) $e3[0]['pos'] === 7 && (int) $e3[0]['i'] === -1 && (int) $e3[0]['tail'] === 1, json_encode($e3[0]));
chk('an empty e= is an empty list, not a phantom entry', lrgDlgParseEntries('', 1) === []);

[$kv, $tail] = lrgDlgSplitLast('v=1;sid=s1;npc=Someone;e=0~1~0~-~a;b=c', 'e');
chk('the LAST key is taken raw: everything after ;e= belongs to it', $tail === '0~1~0~-~a;b=c', $tail);
chk('and the keys before it still parse', (string) ($kv['sid'] ?? '') === 's1', json_encode($kv));
[$kv2, $t2] = lrgDlgSplitLast('ev=line;sid=s1;npc=A;t=She said: x=1; fine.', 't');
chk('an ev=line subtitle with = and ; inside survives', $t2 === 'She said: x=1; fine.', $t2);
chk('ids keep their case (questlog.id_quest is case sensitive)',
    lrgDlgIds('MQ302,FxDog , -, ,MQ302') === ['MQ302', 'FxDog'], json_encode(lrgDlgIds('MQ302,FxDog , -, ,MQ302')));

// ------------------------------------------------------------------ 2. normalisation and price
head('2. lrgPromptNorm / lrgPromptCost (the shared key rule)');
chk('a leading tag is dropped', lrgPromptNorm('(Persuade) Be reasonable.') === 'be reasonable', lrgPromptNorm('(Persuade) Be reasonable.'));
chk('a trailing tag is dropped', lrgPromptNorm('Be reasonable. (Persuade)') === 'be reasonable', lrgPromptNorm('Be reasonable. (Persuade)'));
chk('digits become #', lrgPromptNorm('I have 12 of them') === 'i have # of them', lrgPromptNorm('I have 12 of them'));
chk('a token becomes <t>', lrgPromptNorm('How about <Alias=Hold>?') === 'how about <t>', lrgPromptNorm('How about <Alias=Hold>?'));
chk('an apostrophe is kept (it carries meaning)', lrgPromptNorm("I'd like a room") === "i'd like a room", lrgPromptNorm("I'd like a room"));
chk('a price tag and its token form give the SAME key',
    lrgPromptNorm('Take this. (<BribeCost> gold)') === lrgPromptNorm('Take this. (250 gold)'), lrgPromptNorm('Take this. (250 gold)'));
chk('a literal price is read', lrgPromptCost('Take this. (250 gold)') === 250, (string) lrgPromptCost('Take this. (250 gold)'));
chk('a token price reads -1 ("ask the live text")', lrgPromptCost('Take this. (<BribeCost> gold)') === -1);
chk('"(3 days left)" is not a price', lrgPromptCost('Come back. (3 days left)') === 0);

// ------------------------------------------------------------------ 3. matching
head('3. lrgDlgMatchText - the score and the margin that guard every server-side match');
$mk = static function (array $texts): array {
    $out = [];
    foreach ($texts as $i => $x) {
        $out[] = ['pos' => $i, 'i' => 100 + $i, 'text' => $x, 'norm' => lrgPromptNorm($x), 'class' => 'plain',
            'kind' => '', 'cost' => 0, 'commit' => false, 'crit' => 0, 'twat' => '', 'indexed' => 1,
            'compound' => 0, 'variant' => 'na', 'scripted' => 0, 'goodbye' => 0, 'afford' => 1];
    }
    return $out;
};
$list = $mk(['I need work.', "I'd like to rent a room.", 'Have you heard any rumours?', 'Never mind.']);
$m = lrgDlgMatchText('I need work.', $list);
chk('an exact line scores 1.0 on its own entry', $m !== null && (int) $m['i'] === 0 && $m['score'] >= 0.99, json_encode($m));
$m = lrgDlgMatchText('i am looking for work', $list);
chk('a paraphrase of "I need work." lands on it', $m !== null && (int) $m['i'] === 0, json_encode($m));
chk('and it clears the contract threshold 0.55 with a margin over 0.15',
    $m !== null && $m['score'] >= 0.55 && $m['margin'] >= 0.15, json_encode($m));
// the margin rule is what keeps an ambiguous utterance from being resolved by LIST ORDER (design 4.4)
$m = lrgDlgMatchText('is there any work for me', $list);
chk('an utterance that fits two entries almost equally tops on one of them',
    $m !== null && in_array((int) $m['i'], [0, 2], true), json_encode($m));
chk('but its margin is under 0.15, so the caller refuses it and the NPC asks instead',
    $m !== null && $m['margin'] < 0.15, json_encode($m));
$m = lrgDlgMatchText('i want to rent a room please', $list);
chk('"rent a room" lands on the room entry, not on the work entry', $m !== null && (int) $m['i'] === 1, json_encode($m));
$m = lrgDlgMatchText('what do you think of the weather', $list);
chk('an unrelated utterance does NOT clear the threshold', $m === null || $m['score'] < 0.55, json_encode($m));
$m = lrgDlgMatchText('', $list);
chk('an empty utterance matches nothing at all', $m === null, json_encode($m));
$m = lrgDlgMatchText('I need work.', []);
chk('an empty list matches nothing at all', $m === null, json_encode($m));

// ------------------------------------------------------------------ 4. the class vocabulary
head('4. lrgDlgClass / lrgDlgIsCommit / lrgDlgLabel (design 3.3 and 4.5)');
$row = static function (array $o = []): array {
    return $o + ['text' => 'Some line.', 'norm' => 'some line', 'indexed' => 1, 'kind' => '', 'variant' => 'na',
        'scripted' => 0, 'goodbye' => 0, 'walkaway' => 0, 'invis' => 0, 'placeholder' => 0, 'crit' => 0,
        'cost' => 0, 'afford' => 1, 'compound' => 0, 'new' => 0, 'fail_brawl' => 0, 'shared' => 1];
};
chk('a check entry is class check', lrgDlgClass($row(['kind' => 'persuade']), false, 0, 300) === 'check');
chk('a priced entry is class pay', lrgDlgClass($row(['cost' => 34, 'text' => 'Three nights. (34 gold)']), false, 0, 300) === 'pay',
    lrgDlgClass($row(['cost' => 34]), false, 0, 300));
chk('"Never mind." is class back', lrgDlgClass($row(['text' => 'Never mind.', 'norm' => 'never mind']), false, 0, 300) === 'back');
chk('"(Remain silent)" is class silent',
    lrgDlgClass($row(['text' => '(Remain silent)', 'norm' => 'remain silent']), false, 0, 300) === 'silent',
    lrgDlgClass($row(['text' => '(Remain silent)']), false, 0, 300));
chk('"(skip quest)" is class meta',
    lrgDlgClass($row(['text' => 'Let us pretend that happened. (skip quest)']), false, 0, 300) === 'meta',
    lrgDlgClass($row(['text' => 'Let us pretend that happened. (skip quest)']), false, 0, 300));
chk('a rent line is class service',
    lrgDlgClass($row(['text' => "I'd like to rent a room."]), false, 0, 300) === 'service',
    lrgDlgClass($row(['text' => "I'd like to rent a room."]), false, 0, 300));
chk('a placeholder is class hidden and never reaches the LLM',
    lrgDlgClass($row(['placeholder' => 1, 'text' => '(Invisible Continue)']), false, 0, 300) === 'hidden');
chk('an ordinary line is class plain', lrgDlgClass($row(['text' => 'Tell me about Whiterun.']), false, 0, 300) === 'plain',
    lrgDlgClass($row(['text' => 'Tell me about Whiterun.']), false, 0, 300));

chk('scripted + Goodbye commits (heuristic 1)', lrgDlgIsCommit($row(['scripted' => 1, 'goodbye' => 1]), false, 0, 300));
// [pt19 v1.0 / S4.1] WALK-AWAY IS NOT A COMMIT, and neither is crit 1: the flag guards LEAVING a list (S4.2), never clicking
chk('a walk-away does NOT commit any more (S4.1: it is the leave guard)', !lrgDlgIsCommit($row(['walkaway' => 1]), false, 0, 300));
chk('crit 1 does NOT commit any more (crit 2 stays lethal)', !lrgDlgIsCommit($row(['crit' => 1]), false, 0, 300));
chk('two scripted entries in one closed layer commit', lrgDlgIsCommit($row(['scripted' => 1]), true, 2, 300));
chk('...but a HUB question (its links lead back to the layer) does not (S4.1)', !lrgDlgIsCommit($row(['scripted' => 1, 'hub' => 1]), true, 3, 300));
chk('a price at or over confirm.min_gold commits', lrgDlgIsCommit($row(['cost' => 137]), false, 0, 3000));
chk('a price over a quarter of the purse commits too', lrgDlgIsCommit($row(['cost' => 30]), false, 0, 100));
chk('a small price on a full purse does NOT commit', !lrgDlgIsCommit($row(['cost' => 12]), false, 0, 3000));
chk('an INDEXED ordinary line does not commit on the word list alone',
    !lrgDlgIsCommit($row(['text' => 'I will pay you later, I promise.']), false, 0, 3000),
    'the UI-only word list must be LAST and only for unindexed text');
chk('an UNINDEXED choice word does commit (the last-resort rule)',
    lrgDlgIsCommit($row(['indexed' => 0, 'text' => 'I will kill him.']), false, 0, 3000));
chk('an unindexed new entry in a closed layer commits',
    lrgDlgIsCommit($row(['indexed' => 0, 'new' => 1, 'text' => 'Anything at all.']), true, 0, 3000));

chk('a persuade label says "attempt", never a chance', lrgDlgLabel($row(['kind' => 'persuade', 'class' => 'check']), 300) === '[persuasion attempt]');
chk('an intimidate label whose failure is a brawl says so',
    lrgDlgLabel($row(['kind' => 'intimidate', 'class' => 'check', 'fail_brawl' => 1]), 300) === '[threat - refusing means a brawl]');
chk('a bribe label names the price and the purse',
    lrgDlgLabel($row(['kind' => 'bribe', 'class' => 'check', 'cost' => 137, 'afford' => 1]), 412) === '[bribe: costs 137 septims; the player has 412]',
    lrgDlgLabel($row(['kind' => 'bribe', 'cost' => 137]), 412));
chk('an unaffordable bribe says so instead of naming the purse',
    lrgDlgLabel($row(['kind' => 'bribe', 'class' => 'check', 'cost' => 137, 'afford' => 0]), 50) === '[bribe: costs 137 septims; the player cannot pay]',
    lrgDlgLabel($row(['kind' => 'bribe', 'cost' => 137, 'afford' => 0]), 50));
chk('a COSTLY back-out entry says what leaving costs',
    lrgDlgLabel($row(['class' => 'back', 'crit' => 1]), 300) === '[leaving now ends this]');
chk('no label ever contains a number that is not a real price',
    !preg_match('/\d/', lrgDlgLabel($row(['kind' => 'persuade', 'class' => 'check']), 300)));

// ------------------------------------------------------------------ 5. shown text and safe prefix
head('5. what reaches the prompt and what reaches the wire');
chk('the shown text drops only the leading tag, verbatim otherwise',
    lrgDlgShownText('(Persuade) There has been enough bloodshed.') === 'There has been enough bloodshed.',
    lrgDlgShownText('(Persuade) There has been enough bloodshed.'));
chk('a text that is ONLY a tag is not emptied', lrgDlgShownText('(Brawl)') === '(Brawl)', lrgDlgShownText('(Brawl)'));
chk('txt is a prefix free of ; = @ | " ~',
    !preg_match('/[;=@|"~]/', lrgDlgSafePrefix('Pay me; now = @ "quoted" ~ or else, friend')),
    lrgDlgSafePrefix('Pay me; now = @ "quoted" ~ or else, friend'));
chk('txt is at most 40 chars', strlen(lrgDlgSafePrefix(str_repeat('long line ', 12))) <= 40,
    (string) strlen(lrgDlgSafePrefix(str_repeat('long line ', 12))));
// [0.5.1 pt9 go-live / S9] the 12-character floor is gone: it blanked txt= for 7.7% of the live index,
// including every bare destination name, and an empty txt disables the GAME's only content check on the
// click (TryResolvePick falls back to position alone) - on exactly the entries that spend gold.
chk('a short text still yields a txt - position alone must never decide a click',
    lrgDlgSafePrefix('Yes.') === 'Yes.', lrgDlgSafePrefix('Yes.'));
chk('a bare destination name carries its prefix', lrgDlgSafePrefix('Morthal.') === 'Morthal.',
    lrgDlgSafePrefix('Morthal.'));
chk('and a text that sanitises to nothing is still empty', lrgDlgSafePrefix(' ;=@ ') === '',
    '[' . lrgDlgSafePrefix(' ;=@ ') . ']');

// ------------------------------------------------------------------ 6. ranking
head('6. lrgDlgRank - a closed layer is never re-ordered, a root list is');
$closed = lrgDlgDecorateEntries(lrgDlgParseEntries('0~100~0~-~Whiterun.~~1~101~0~-~The Rift.~~2~102~0~-~Never mind.', 1),
    [null, null, null], ['pg' => 300], []);
[$head, $rest] = lrgDlgRank($closed, 'closed', 'the rift', []);
chk('a closed layer keeps the engine order', (int) $head[0]['pos'] === 0 && (int) $head[1]['pos'] === 1,
    json_encode(array_column($head, 'pos')));
chk('and nothing is pushed into a tail', $rest === [], json_encode($rest));
$rootEntries = [];
for ($i = 0; $i < 14; $i++) { $rootEntries[] = lrgDlgEntryFix($i, 'Matter ' . $i . ' about a thing'); }
$rootEntries[13] = lrgDlgEntryFix(13, 'About the missing dog of yours');
$dec = lrgDlgDecorateEntries($rootEntries, array_fill(0, 14, null), ['pg' => 300], []);
[$head2, $rest2] = lrgDlgRank($dec, 'root', 'about the missing dog', []);
chk('a root list is capped at 8', count($head2) === 8, (string) count($head2));
chk('the rest becomes the tail', count($rest2) === 6, (string) count($rest2));
chk('lexical similarity to the utterance pulls the right entry into the head',
    in_array('About the missing dog of yours', array_column($head2, 'text'), true), json_encode(array_column($head2, 'text')));
chk('the head is printed in ENGINE order, so the prompt and the screen agree',
    array_column($head2, 'pos') === array_values(array_map('intval', array_column($head2, 'pos')))
    && $head2[0]['pos'] <= $head2[count($head2) - 1]['pos'], json_encode(array_column($head2, 'pos')));

// ------------------------------------------------------------------ 7. the confirmation layer test
head('7. a vanilla "Are you sure?" sub-layer IS the confirmation - never stack a second one');
$yesno = lrgDlgDecorateEntries(lrgDlgParseEntries('0~100~0~-~Yes, I am sure.~~1~101~0~-~No, wait.', 1), [null, null], [], []);
chk('a two-entry yes/no layer is recognised as the engine\'s own confirmation',
    lrgDlgLayerIsOwnConfirmation(['entries' => $yesno]), json_encode(array_column($yesno, 'text')));
$other = lrgDlgDecorateEntries(lrgDlgParseEntries('0~100~0~-~Whiterun.~~1~101~0~-~The Rift.', 1), [null, null], [], []);
chk('two ordinary choices are NOT a confirmation layer', !lrgDlgLayerIsOwnConfirmation(['entries' => $other]));

// ------------------------------------------------------------------ 8. amounts and config
head('8. amounts, config defaults, and the action row');
chk('"for a hundred septims" is not a number we invent', lrgDlgNamedAmount('for a hundred septims') === 0);
chk('"50 gold" is read', lrgDlgNamedAmount('I will give you 50 gold') === 50, (string) lrgDlgNamedAmount('I will give you 50 gold'));
chk('"pay 137" is read', lrgDlgNamedAmount('I will pay 137 and no more') === 137, (string) lrgDlgNamedAmount('I will pay 137 and no more'));
chk('a bare number with no money word is not an offer', lrgDlgNamedAmount('I have been here 3 times') === 0,
    (string) lrgDlgNamedAmount('I have been here 3 times'));
chk('the contract thresholds are the design\'s', (float) lrgDlgCfg('match.min_score') === 0.55
    && (float) lrgDlgCfg('match.min_margin') === 0.15 && (float) lrgDlgCfg('match.cont_score') === 0.70
    && (float) lrgDlgCfg('match.cont_margin') === 0.25, json_encode(lrgDlgCfg('match')));
chk('the confirmation defaults are the design\'s (S9)', (int) lrgDlgCfg('confirm.min_gold') === 100
    && (int) lrgDlgCfg('confirm.park_seconds') === 60 && lrgDlgCfg('confirm.typed_skips') === null
    && (float) lrgDlgCfg('confirm.said_line_score') === 0.70 && (float) lrgDlgCfg('confirm.said_line_margin') === 0.25
    && (int) lrgDlgCfg('confirm.said_line_tokens') === 4 && (int) lrgDlgCfg('confirm.slot_tokens') === 2
    && (float) lrgDlgCfg('confirm.named_score') === 0.45 && (float) lrgDlgCfg('confirm.single_entry_score') === 0.65
    && (float) lrgDlgCfg('confirm.single_entry_exact') === 0.85 && (float) lrgDlgCfg('confirm.single_entry_precision') === 0.75
    && (int) lrgDlgCfg('confirm.utter_window') === 30 && lrgDlgCfg('confirm.bare_yes') === true && lrgDlgCfg('confirm.single_entry') === true
    && in_array('ok', (array) lrgDlgCfg('confirm.assent_words'), true) && in_array('okay', (array) lrgDlgCfg('confirm.assent_words'), true)
    && in_array('sure thing', (array) lrgDlgCfg('confirm.assent_words'), true)
    && lrgDlgCfg('confirm.single_entry_extra') === ['go on', 'carry on'],
    json_encode(lrgDlgCfg('confirm')));
chk('[pt19 v1.0 / S9] the new keys have a CODE default (never dependent on the JSON, model F21)',
    (int) lrgDlgCfg('auto_advance.grace_ms') === 2500 && lrgDlgCfg('auto_advance.rearm') === true && lrgDlgCfg('auto_advance.enabled') === true
    && lrgDlgCfg('match.scripted_needs_word') === true && lrgDlgCfg('match.service_kind_pick') === true
    && lrgDlgCfg('open.narrow_marker') === true && lrgDlgCfg('open.toplevel_marker') === true && lrgDlgCfg('open.qrows_marker') === true
    && (float) lrgDlgCfg('open.qrows_score') === 0.55 && (int) lrgDlgCfg('open.qrows_cap') === 300
    && lrgDlgCfg('session.talk_again') === false && lrgDlgCfg('session.stage_rail') === true && lrgDlgCfg('session.drive_scene') === true,
    json_encode(['aa' => lrgDlgCfg('auto_advance'), 'open' => lrgDlgCfg('open'), 'session' => lrgDlgCfg('session')]));
chk('[pt19 v1.0 / section 4] every retired config key is gone from the code defaults',
    lrgDlgCfg('auto_advance.grace_seconds') === null && lrgDlgCfg('assist') === null && lrgDlgCfg('calib.auto') === null
    && lrgDlgCfg('quests.initiative') === null && lrgDlgCfg('match.cont_depth') === null
    && lrgDlgCfg('session.silence_seconds') === null && lrgDlgCfg('session.lost_seconds') === null
    && lrgDlgCfg('session.utter_max_age') === null && lrgDlgCfg('script_proxy_watch') === null
    && lrgDlgCfg('quest_colour') === null && lrgDlgCfg('continuer_words') === null,
    'a retired key is still defaulted');
chk('attempt.min_words is 4 and scoff-first is on (decision D5)', (int) lrgDlgCfg('attempt.min_words') === 4
    && lrgDlgCfg('attempt.scoff_first') === true, json_encode(lrgDlgCfg('attempt')));
chk('free-conversation checks default ON, their stat increment OFF, hostility OFF (owner + E13)',
    lrgDlgCfg('checks.enabled') === true && lrgDlgCfg('checks.stats') === false
    && lrgDlgCfg('checks.hostility') === false, json_encode(array_intersect_key((array) lrgDlgCfg('checks'), array_flip(['enabled', 'stats', 'hostility']))));
chk('ForgiveCrime is on the always-hidden list (decision D4)',
    in_array('ForgiveCrime', (array) lrgDlgCfg('hide_always', []), true), json_encode(lrgDlgCfg('hide_always')));
chk('PayBounty is on NO hide list (it checks gold before PlayerPayCrimeGold)',
    !in_array('PayBounty', array_merge((array) lrgDlgCfg('hide_always', []), (array) lrgDlgCfg('hide_chim', []),
        (array) lrgDlgCfg('hide_gold', []), (array) lrgDlgCfg('hide_in_session', [])), true));
chk('there is no TradeItems code on the hide list (the code is OpenInventory)',
    !in_array('TradeItems', (array) lrgDlgCfg('hide_chim', []), true), json_encode(lrgDlgCfg('hide_chim')));

$GLOBALS['FXD_CATALOG'] = [];
if (!function_exists('herikaActionCatalogUpsertCustomRow')) {
    function herikaActionCatalogUpsertCustomRow($row) { $GLOBALS['FXD_CATALOG'][(string) $row['code_name']] = $row; return true; }
    function herikaGetActionCatalogRow($c) { return $GLOBALS['FXD_CATALOG'][(string) $c] ?? null; }
}
@unlink(LRG_DIR . '/data/.dlg_actions_v' . LRG_DLG_ACTIONS_VERSION);
lrgDlgEnsureActions();
$rowA = $GLOBALS['FXD_CATALOG'][LRG_ACT_TOPIC] ?? null;
chk('the action row is installed under its own code', is_array($rowA), json_encode(array_keys($GLOBALS['FXD_CATALOG'])));
chk('its LLM-facing name is TakeUpBusiness', (string) ($rowA['action_name'] ?? '') === 'TakeUpBusiness', (string) ($rowA['action_name'] ?? ''));
chk('item is a REQUIRED string', ($rowA['parameters_json']['required'] ?? []) === ['item']
    && (string) ($rowA['parameters_json']['properties']['item']['type'] ?? '') === 'string', json_encode($rowA['parameters_json'] ?? []));
chk('its request types are the four player-speech types plus lrg_dlgtalk',
    ($rowA['metadata']['requirements']['request_types_any'] ?? []) === array_merge(LRG_PLAYER_SPEECH_TYPES, ['lrg_dlgtalk']),
    json_encode($rowA['metadata']['requirements'] ?? []));
chk('confirmation is automatic, the placeholder infoaction is suppressed and follow-ups are off',
    (string) ($rowA['metadata']['confirmation']['default_policy'] ?? '') === 'automatic'
    && ($rowA['metadata']['suppress_placeholder_infoaction'] ?? null) === true
    && ($rowA['metadata']['followup']['enabled'] ?? null) === false, json_encode($rowA['metadata'] ?? []));
// It must never be inside Phase 1's LRG_GLUE_ACTIONS, or lrgHideActions(LRG_GLUE_ACTIONS) under SHARMAT
// would switch the whole feature off. That constant lives in another lane's file, so test both ways.
chk('it is NOT in Phase 1\'s LRG_GLUE_ACTIONS, so SHARMAT cannot switch it off',
    !defined('LRG_GLUE_ACTIONS') || !in_array(LRG_ACT_TOPIC, (array) constant('LRG_GLUE_ACTIONS'), true),
    defined('LRG_GLUE_ACTIONS') ? implode(',', (array) constant('LRG_GLUE_ACTIONS')) : 'not loaded here');
chk('and its catalog marker is its own file, not Phase 1\'s .actions_v*',
    !str_contains('.dlg_actions_v' . LRG_DLG_ACTIONS_VERSION, '.actions_v' . (defined('LRG_ACTIONS_VERSION') ? LRG_ACTIONS_VERSION : 'x'))
    || LRG_DLG_ACTIONS_VERSION !== (defined('LRG_ACTIONS_VERSION') ? LRG_ACTIONS_VERSION : -1),
    '.dlg_actions_v' . LRG_DLG_ACTIONS_VERSION);
chk('its description forbids announcing a check outcome',
    str_contains(strtolower((string) ($rowA['description'] ?? '')), 'never announce'), fx_cut((string) ($rowA['description'] ?? '')));
chk('its marker is the module\'s own, never Phase 1\'s',
    is_file(LRG_DIR . '/data/.dlg_actions_v' . LRG_DLG_ACTIONS_VERSION));

// ------------------------------------------------------------------ 9. the 0.4.0 fix pass
// These four are regressions, each of which shipped broken. Read the comment before changing one.
head('9. the 0.4.0 fix pass: the require graph, the gate name, the tag fallback and the layer merge');

// (a) THE REQUIRE GRAPH. preprocessing.php handles lrg_topics / lrg_dlg with lib/lrg_dialogue.php alone
// loaded; lib/lrg_actions.php is required further down and that branch never reaches it. A call into the
// other lane's file therefore FATALS the whole request - which is what lrgCsv() did on every message that
// carried a perk, i.e. every message the moment the player owns one Speech perk or the Amulet of
// Articulation. This file's own require is exactly that graph: lrg_dialogue.php and nothing else.
chk('this test loads the SAME graph preprocessing.php uses for a Phase 2 message',
    !function_exists('lrgCsv') && !function_exists('lrgHideActions'),
    'lrg_actions.php got loaded somehow - this check can no longer see the bug it guards');
$GLOBALS['gameRequest'] = ['lrg_topics', $GLOBALS['LRG_TEST_NOW'], 100000,
    'v=1;sid=sF;gen=0;layer=0;origin=glue;npc=Perktest;st=1;n=1;sent=1;part=1;cid=fix01;want=0;'
    . 'pg=300;sp=40;lvl=14;perk=amulet;e=0~100~0~-~I need work.'];
$GLOBALS['HERIKA_NAME'] = 'Perktest';
$fixRes = '';
try { $fixRes = (string) lrgDlgHandleGameMessage($GLOBALS['gameRequest']); }
catch (Throwable $fixE) { $fixRes = 'THREW ' . get_class($fixE) . ': ' . $fixE->getMessage(); }
chk('an lrg_topics carrying perk=amulet is HANDLED, not fatal', $fixRes === 'handled', $fixRes);
chk('and the perk really reached the state, lowercased and split',
    (array) (lrgDlgGet('Perktest')['facts']['perk'] ?? null) === ['amulet'],
    json_encode(lrgDlgGet('Perktest')['facts']['perk'] ?? null));
$GLOBALS['gameRequest'][3] = str_replace('perk=amulet', 'perk=REQ_Speech_SilverTongue, Amulet ,', $GLOBALS['gameRequest'][3]);
lrgDlgHandleGameMessage($GLOBALS['gameRequest']);
chk('a csv of perks is split, trimmed, lowercased and emptied of blanks',
    (array) (lrgDlgGet('Perktest')['facts']['perk'] ?? null) === ['req_speech_silvertongue', 'amulet'],
    json_encode(lrgDlgGet('Perktest')['facts']['perk'] ?? null));
$GLOBALS['gameRequest'][3] = str_replace('perk=REQ_Speech_SilverTongue, Amulet ,', 'perk=-', $GLOBALS['gameRequest'][3]);
lrgDlgHandleGameMessage($GLOBALS['gameRequest']);
chk('and "-" means no perks at all, never a one-element list of "-"',
    (array) (lrgDlgGet('Perktest')['facts']['perk'] ?? null) === [],
    json_encode(lrgDlgGet('Perktest')['facts']['perk'] ?? null));

// (b) THE GATE NAME. The wire carries the CODE name (HerikaServer functions.php builds
// "$actor|$channel|$functionCodeName@$param"); CHIM snake-cases the DISPLAY name for the strict JSON
// enum. Comparing a stripped code against an unstripped strtolower(LRG_ACT_TOPIC) matched NEITHER, so
// every raw LLM line went through ungated and every rail below it was unreachable in production.
$norm = static fn(string $s): string => str_replace(['_', ' '], '', strtolower($s));
foreach ([LRG_ACT_TOPIC, LRG_DLG_NAME, 'Take_Up_Business', 'take_up_business', 'ExtCmdLRG_SelectTopic'] as $spelling) {
    chk('the gate recognises the spelling "' . $spelling . '"',
        in_array($norm($spelling), LRG_DLG_GATE_NAMES, true), $norm($spelling));
}
chk('and it does NOT swallow another action', !in_array($norm('ExtCmdLRG_StartIntimacy'), LRG_DLG_GATE_NAMES, true));
unset($GLOBALS['LRG_DLG_TURN']);
$passed = lrgDlgPostProcessActions(['Someone|command|ExtCmdLRG_SelectTopic@item=T1' . "\r\n"]);
chk('with no turn record the real code name is DROPPED, never passed through raw', $passed === [],
    json_encode($passed));

// (c) THE VISIBLE TAG is a second source of truth: 54 rows in this load order show (Persuade) /
// (Intimidate) / (Bribe) and carry neither kind nor topic_kind in the index.
chk('a tagged line with no index kind is read as the attempt it is',
    lrgDlgKindFromTag('Stand aside, or else. (Intimidate)') === 'intimidate'
    && lrgDlgKindFromTag('(Persuade) Let me through.') === 'persuade'
    && lrgDlgKindFromTag('I can make it worth your while. (Bribe 100 gold)') === 'bribe',
    lrgDlgKindFromTag('Stand aside, or else. (Intimidate)'));
chk('and an ordinary parenthesis is never a check',
    lrgDlgKindFromTag('I found it (in the cave).') === '' && lrgDlgKindFromTag('Never mind.') === '');
$tagged = lrgDlgEntryFix(0, 'Stand aside, or else. (Intimidate)') + ['norm' => '', 'indexed' => 1, 'kind' => '',
    'own_check' => 0, 'variant' => 'na', 'quest' => '', 'journal' => 0, 'toplevel' => 0, 'scripted' => 1,
    'compound' => 0, 'twat' => '', 'crit' => 0, 'resp' => '', 'shared' => 1, 'goodbye' => 0, 'sayonce' => 0,
    'walkaway' => 0, 'invis' => 0, 'placeholder' => 0, 'fail_brawl' => 0, 'fail_hard' => 0,
    'unreachable' => 0, 'cost' => 0, 'afford' => 1];
chk('so lrgDlgClass() grades it as a check, not as an irreversible commit',
    lrgDlgClass($tagged, true, 2, 300) === 'check', lrgDlgClass($tagged, true, 2, 300));

// (c2) THE MCM KNOBS THAT ARE SERVER BEHAVIOUR. Seven controls did nothing because they had no wire
// carrier; the server kept using its own config default while the owner moved a slider. The keys are
// optional and additive, so an ABSENT key still reads as the server's default (script 310 unchanged).
unset($GLOBALS['LRG_DLG_MCM']);
chk('with nothing on the wire an MCM read is the server default',
    lrgDlgMcm('bias', 0) === 0 && lrgDlgMcm('free_checks', 1) === 1 && lrgDlgMcm('ql', 3) === 3);
$GLOBALS['gameRequest'] = ['lrg_dlg', $GLOBALS['LRG_TEST_NOW'], 100000,
    'ev=facts;npc=Perktest;ref=0x00012345;sp=40;lvl=14;perk=-;chk=01;bias=-2;ql=0;io=0;bi=2;rw=0'];
lrgDlgHandleGameMessage($GLOBALS['gameRequest']);
chk('chk= carries bFreeChecks and bCheckHostility as two digits, in that order',
    lrgDlgMcm('free_checks', 1, 'Perktest') === 0 && lrgDlgMcm('hostility', 0, 'Perktest') === 1,
    json_encode($GLOBALS['LRG_DLG_MCM'] ?? []));
chk('bias, ql and io all arrive and are clamped to their ranges',
    lrgDlgMcm('bias', 0) === -2 && lrgDlgMcm('ql', 3) === 0 && lrgDlgMcm('io', 1) === 0, json_encode($GLOBALS['LRG_DLG_MCM'] ?? []));
// [pt19 v1.0 / section 4] iBranchInput (bi), bRewalk (rw) and iRewalkDepth (rwd) are retired: an old script's keys are ignored
chk('[v1.0] the retired bi / rw / rwd keys are ignored (no dead reader)',
    lrgDlgMcm('bi', 7) === 7 && lrgDlgMcm('rw', 7) === 7, json_encode($GLOBALS['LRG_DLG_MCM'] ?? []));
$GLOBALS['gameRequest'][3] = 'ev=facts;npc=Perktest;bias=99;ql=-4';
lrgDlgHandleGameMessage($GLOBALS['gameRequest']);
chk('an out-of-range value is clamped, never trusted', lrgDlgMcm('bias', 0) === 2 && lrgDlgMcm('ql', 3) === 0,
    json_encode($GLOBALS['LRG_DLG_MCM'] ?? []));
chk('bFreeChecks=0 really stops the free-conversation check, narration and all',
    lrgDlgCheck(['npc' => 'Perktest', 'cid' => 'x', 'snap' => [], 'entries' => [], 'tail' => [], 'facts' => []],
        ['kind' => 'intimidate', 'conf' => 'high']) === null);
unset($GLOBALS['LRG_DLG_MCM']);

// (c3) [0.4.0 RELEASE PASS] THE SNAPSHOT TIER. chk / bias / ql are CHECK and QUEST knobs and the game puts
// them on the ordinary lrg_npcstate SNAPSHOT, not on a dialogue message - it has to, because a FREE
// conversation check happens in ordinary CHIM talk where no lrg_topics / lrg_dlg is ever sent. Until this
// tier existed the facts parser was the only reader, so bFreeChecks / bCheckHostility / iCheckBias still
// changed nothing in the one situation the owner asked for them by name (OWNER_ADDENDA 5c).
unset($GLOBALS['LRG_DLG_MCM'], $GLOBALS['LRG_DLG_MCM_SNAP']);
$GLOBALS['LRG_TEST_NPCSTATE'] = ['Snaptest' => ['npc' => 'Snaptest', 'chk' => '01', 'bias' => '2', 'ql' => '0']];
chk('a knob that only ever rides the snapshot is still read back',
    lrgDlgMcm('free_checks', 1, 'Snaptest') === 0 && lrgDlgMcm('hostility', 0, 'Snaptest') === 1
    && lrgDlgMcm('bias', 0, 'Snaptest') === 2 && lrgDlgMcm('ql', 3, 'Snaptest') === 0,
    json_encode(lrgDlgMcmFromSnapshot('Snaptest')));
unset($GLOBALS['LRG_DLG_MCM_SNAP']);
$GLOBALS['LRG_TEST_NPCSTATE'] = ['Snaptest' => ['npc' => 'Snaptest', 'chk' => 'yes', 'bias' => 'x']];
chk('and a malformed snapshot value keeps the server default rather than being trusted',
    lrgDlgMcm('free_checks', 1, 'Snaptest') === 1 && lrgDlgMcm('bias', 0, 'Snaptest') === 0,
    json_encode(lrgDlgMcmFromSnapshot('Snaptest')));
unset($GLOBALS['LRG_DLG_MCM_SNAP'], $GLOBALS['LRG_TEST_NPCSTATE']);

// (c4) [pt19 v1.0 / section 4] the rewalk depth (bRewalk / iRewalkDepth, match.cont_depth) is retired: a same-session
// continuation is depth 1, always (D-18). The (c4) rewalk-depth checks went with it.
unset($GLOBALS['LRG_DLG_MCM']);

// (d) THE LAYER TIER must not under-claim risk. It wins over the exact-norm tier, and all seven norms in
// this load order that carry both a LETHAL and a non-lethal candidate sit inside a known closed layer.
$lethal = ['norm' => 'x', 'crit' => 2, 'scripted' => 1, 'cost' => 0, 'kind' => '', 'twat' => 'DGCrimeResistArrest',
    'flags' => ['walkaway' => 1, 'goodbye' => 0, 'sayonce' => 0], 'compound' => 0];
$plain = ['norm' => 'x', 'crit' => 0, 'scripted' => 0, 'cost' => 0, 'kind' => '', 'twat' => '',
    'flags' => ['goodbye' => 0], 'compound' => 0];
$merged = lrgPromptEscalate($plain, [$plain, $lethal]);
chk('the fail-safe merge raises crit, twat and the walkaway flag to the strictest candidate',
    (int) $merged['crit'] === 2 && (string) $merged['twat'] === 'DGCrimeResistArrest'
    && (int) ($merged['flags']['walkaway'] ?? 0) === 1, json_encode($merged));

// ------------------------------------------------------------------ 10. the follower verbs
/*
 * [0.5.1 / owner addendum 10] The verb table against the REAL topic EditorIDs and the REAL player
 * lines of this load order (read out of the 37,561-row index, not invented): the vanilla set on quest
 * DialogueFollower, Set Follower Home's two topics, and Inigo's / Lucien's / ksws07's own copies.
 * These are the checks that matter, because a wrong answer here dismisses the player's real follower.
 */
head('10. [0.5.1] follower verbs: an entry\'s verb, the player\'s intent, and what must never match');
$fol = static function (string $topic, string $text, int $pos = 0): array {
    return ['pos' => $pos, 'i' => 100 + $pos, 'text' => $text, 'norm' => lrgPromptNorm($text),
        'class' => 'plain', 'kind' => '', 'cost' => 0, 'commit' => false, 'crit' => 0, 'topic' => $topic,
        'quest' => 'DialogueFollower', 'twat' => ''];
};
$verbOf = static fn(string $topic, string $text) => lrgDlgFollowerVerbOf($fol($topic, $text));
chk('the vanilla wait topic is the wait verb', $verbOf('DialogueFollowerWaitTopic', 'Wait here.') === 'wait',
    $verbOf('DialogueFollowerWaitTopic', 'Wait here.'));
chk('the vanilla follow topic is the follow verb', $verbOf('DialogueFollowerFollowTopic', 'Follow me.') === 'follow',
    $verbOf('DialogueFollowerFollowTopic', 'Follow me.'));
chk('the vanilla dismiss topic is the dismiss verb',
    $verbOf('DialogueFollowerDismissTopic', "It's time for us to part ways.") === 'dismiss',
    $verbOf('DialogueFollowerDismissTopic', "It's time for us to part ways."));
chk('the vanilla trade topic is the trade verb',
    $verbOf('DialogueFollowerTradeTopic', 'I need to trade some things with you.') === 'trade',
    $verbOf('DialogueFollowerTradeTopic', 'I need to trade some things with you.'));
chk('the vanilla favour topic is the favour verb',
    $verbOf('DialogueFollowerFavorStateTopic', 'I need you to do something.') === 'favor',
    $verbOf('DialogueFollowerFavorStateTopic', 'I need you to do something.'));
chk('Set Follower Home\'s two real topics are home / unhome',
    $verbOf('SetHomeSetTopic', "You should live here when you're not traveling with me.") === 'home'
    && $verbOf('SetHomeUnsetTopic', 'You should stop living at <t>.') === 'unhome',
    $verbOf('SetHomeSetTopic', 'x') . '/' . $verbOf('SetHomeUnsetTopic', 'y'));
chk('a custom follower\'s own copies resolve to the same verbs (Inigo, Lucien, ksws07)',
    $verbOf('InigoDialogueFollowerWaitTopic1', 'Wait here.') === 'wait'
    && $verbOf('JRLucienFollowerDismissGoodbye', "It's time for us to part ways.") === 'dismiss'
    && $verbOf('ksws07FollowerRecruitTopic', 'Follow me, I need your help.') === 'recruit',
    $verbOf('InigoDialogueFollowerWaitTopic1', 'x') . '/' . $verbOf('JRLucienFollowerDismissGoodbye', 'y')
    . '/' . $verbOf('ksws07FollowerRecruitTopic', 'z'));
chk('a blocking favour topic is NEVER a verb, at any setting',
    $verbOf('InigoDialogueFollowerDoingFavorBlockingTopic', 'Yes, let me show you.') === '',
    $verbOf('InigoDialogueFollowerDoingFavorBlockingTopic', 'Yes, let me show you.'));
chk('the ANIMAL twins are a separate slot and are never a verb',
    $verbOf('DialogueFollowerAnimalWaitTopic', 'Wait here.') === '',
    $verbOf('DialogueFollowerAnimalWaitTopic', 'Wait here.'));
chk('an ordinary quest line that happens to say "follow me" is not a follower verb',
    lrgDlgFollowerVerbOf(['pos' => 0, 'i' => 1, 'text' => 'Follow me, the path is this way.',
        'norm' => 'follow me the path is this way', 'class' => 'plain', 'topic' => 'MQ201Branch',
        'quest' => 'MQ201', 'cost' => 0, 'kind' => '']) === '', 'matched a non-follower topic');

$intent = static fn(string $s) => implode('/', array_map(static fn($h) => (string) $h['verb'], lrgDlgFollowerIntents($s)));
chk('"wait here" is the wait intent', $intent('Wait here, I will be back.') === 'wait', $intent('Wait here, I will be back.'));
chk('"you\'re dismissed" reaches the dismiss verb although the ENTRY says "part ways"',
    str_contains($intent("You're dismissed - go on."), 'dismiss'), $intent("You're dismissed - go on."));
chk('"come with me" is BOTH a recruit and a follow-again, in that order - only her list can decide',
    $intent('Come with me.') === 'recruit/follow', $intent('Come with me.'));
chk('"follow me, I need your help" prefers the recruit (longest phrase wins)',
    (lrgDlgFollowerIntents('Follow me, I need your help.')[0]['verb'] ?? '') === 'recruit',
    $intent('Follow me, I need your help.'));
chk('a NEGATED order is not an order ("don\'t wait here")',
    $intent("Don't wait here for me.") === '', $intent("Don't wait here for me."));
chk('an ordinary sentence names no follower verb at all', $intent('Have you heard any rumours?') === '',
    $intent('Have you heard any rumours?'));
chk('"this is your home now" is the home verb and it is marked as a commitment',
    str_contains($intent('This is your home now.'), 'home')
    && !empty(lrgDlgCfg('services.follower.verbs.home.commit')), $intent('This is your home now.'));
chk('a dismissal is a commitment too, so the two-step confirmation always runs on it',
    !empty(lrgDlgCfg('services.follower.verbs.dismiss.commit')));
chk('every verb of the owner\'s list is in the table',
    !array_diff(['recruit', 'follow', 'wait', 'dismiss', 'trade', 'favor', 'home'],
        array_keys((array) lrgDlgCfg('services.follower.verbs', []))),
    implode(',', array_keys((array) lrgDlgCfg('services.follower.verbs', []))));
$byVerb = lrgDlgFollowerEntries([
    $fol('DialogueFollowerWaitTopic', 'Wait here.', 0),
    $fol('DialogueFollowerDismissTopic', "It's time for us to part ways.", 1),
    ['pos' => 2, 'i' => 102, 'text' => 'I need work.', 'norm' => 'i need work', 'class' => 'plain',
        'topic' => 'DialogueGenericWork', 'quest' => '', 'cost' => 0, 'kind' => ''],
]);
chk('a mixed list yields exactly the follower entries, keyed by verb',
    array_keys($byVerb) === ['wait', 'dismiss'] && count($byVerb['wait']) === 1, implode(',', array_keys($byVerb)));

/**
 * ------------------------------------------------------------------ 11. the pt9 go-live fix pass
 * One check per fix, on the pure function each one lives in. The end-to-end halves are in the flows
 * (d26 / d28 / d37 / d56 / d59 / d62); these are the fast table-driven ones.
 */
head('11. [0.5.1] the pt9 go-live fix pass');
function fx_short_local(string $s): string { return substr(str_replace("\n", ' ', $s), 0, 120); }

// --- S3: exact containment answers "did he say this word", never "did he ask for this" -------------
$dest = static function (): array {
    $out = [];
    foreach (['Solitude. (50 gold)', 'Morthal. (35 gold)', 'Riften. (50 gold)', 'Dawnstar. (50 gold)'] as $i => $text) {
        $out[] = ['pos' => $i, 'i' => 100 + $i, 'text' => $text, 'norm' => lrgPromptNorm($text),
            'class' => 'pay', 'kind' => '', 'cost' => lrgPromptCost($text), 'commit' => false, 'crit' => 0,
            'topic' => 'KmodFastTravelCarriage', 'quest' => '', 'twat' => ''];
    }
    return $out;
};
$slot = static fn(string $u) => (string) ((array) lrgDlgServiceSlot($dest(), $u))['mode'] ?? '';
chk('an order still executes: "Take me to Morthal."', $slot('Take me to Morthal.') === 'pick', $slot('Take me to Morthal.'));
chk('the bare slot name on its own is still an order', $slot('Morthal.') === 'pick', $slot('Morthal.'));
foreach (["I've just come from Riften.", 'My brother lives in Morthal.', 'They call me Riften, after the city.',
    'Solitude is a beautiful city, is it not?', 'I was robbed on the road to Morthal.',
    'My cousin farms near Morthal.'] as $said) {
    chk('mentioning a hold is not ordering a ride: "' . $said . '"', $slot($said) !== 'pick', $slot($said));
}
chk('a price question is still answered, never executed', $slot('How much to Dawnstar?') !== 'pick',
    $slot('How much to Dawnstar?'));

// --- S10: min_priced = 0 is for service turns, not for every short quest list -----------------------
$shout = [];
foreach (['Feim.', 'Fus.', 'Yol.'] as $i => $text) {
    $shout[] = ['pos' => $i, 'i' => 100 + $i, 'text' => $text, 'norm' => lrgPromptNorm($text), 'class' => 'plain',
        'kind' => '', 'cost' => 0, 'commit' => false, 'crit' => 0, 'topic' => 'T', 'quest' => '', 'twat' => ''];
}
chk('a price-FREE short list is not a price list, so the similarity matcher still works there',
    lrgDlgServiceSlot($shout, 'Feim.') === null, json_encode(lrgDlgServiceSlot($shout, 'Feim.')));
chk('...but a priced layer is a price list exactly as before',
    is_array(lrgDlgServiceSlot($dest(), 'Take me to Morthal.')));
chk('...and a price-free list IS routed when the words really ask for a service',
    lrgDlgServiceSlot($shout, 'Train me in Feim.', 'train') !== null,
    json_encode(lrgDlgServiceSlot($shout, 'Train me in Feim.', 'train')));

// --- S13: a negation never reaches across a clause boundary ----------------------------------------
chk('"Don\'t wait here, come with me instead." is not a pure refusal',
    lrgDlgFollowerNegatedOnly("Don't wait here, come with me instead.") === false);
chk('...and the recruit half of it is heard',
    in_array('recruit', array_column(lrgDlgFollowerIntents("Don't wait here, come with me instead."), 'verb'), true)
    || in_array('follow', array_column(lrgDlgFollowerIntents("Don't wait here, come with me instead."), 'verb'), true),
    json_encode(lrgDlgFollowerIntents("Don't wait here, come with me instead.")));
chk('a plain refusal is still a refusal', lrgDlgFollowerNegatedOnly("Don't follow me.") === true);
chk('the clause guard does not re-open a single-clause negation',
    $slot("Don't take me to Morthal.") !== 'pick', $slot("Don't take me to Morthal."));
chk('the control sentence still resolves', $slot("I don't need a room, take me to Morthal.") === 'pick',
    $slot("I don't need a room, take me to Morthal."));

// --- S12: a spelled-out bribe is priced, not just recognised ---------------------------------------
// First WITHOUT lrg_intent.php, the graph section 9 pins: the digit forms must not depend on it.
chk('the digit form works on lrg_dialogue.php alone',
    lrgDlgNamedAmount('Here is 200 gold, just look the other way.') === 200,
    (string) lrgDlgNamedAmount('Here is 200 gold, just look the other way.'));
chk('a bare number in a paying frame works on it alone too', lrgDlgNamedAmount("I'll pay 300 for it.") === 300,
    (string) lrgDlgNamedAmount("I'll pay 300 for it."));
// Now the graph the CHECK really runs in. lrgDlgNamedAmount is only ever called from the post-LLM gate
// (lrgDlgCheckRails) and from lrgDlgCheck, both of which run with Phase 1 loaded - which is why
// lrgDlgCheckKind() has always been free to call lrgIntentAmount() to RECOGNISE the bribe. The bug was
// that the function which PRICES it was digits-only, so the owner's own phrasing yielded result='ask'
// for ever and the "he offered less than she will take" rail was skipped on an engine bribe entry.
require_once $root . '/server/lorerim_glue/lib/lrg_intent.php';
chk('"two hundred gold" is priced', lrgDlgNamedAmount('Here is two hundred gold, just look the other way.') === 200,
    (string) lrgDlgNamedAmount('Here is two hundred gold, just look the other way.'));
chk('"two hundred septims" is priced', lrgDlgNamedAmount("I'll give you two hundred septims for this.") === 200,
    (string) lrgDlgNamedAmount("I'll give you two hundred septims for this."));
chk('the digit form is unchanged with it loaded',
    lrgDlgNamedAmount('Here is 200 gold, just look the other way.') === 200,
    (string) lrgDlgNamedAmount('Here is 200 gold, just look the other way.'));
chk('a bare number in a paying frame still works', lrgDlgNamedAmount("I'll pay 300 for it.") === 300,
    (string) lrgDlgNamedAmount("I'll pay 300 for it."));
chk('a sentence with no amount is still 0', lrgDlgNamedAmount('Let me through, friend.') === 0,
    (string) lrgDlgNamedAmount('Let me through, friend.'));

// --- S6: the truth gate needs a PRICE FRAME around the number --------------------------------------
$tTruth = ['npc' => 'Bjorlam Flowtest', 'cid' => 't', 'entries' => $dest(), 'tail' => [],
    'locked' => [['class' => 'price', 'line' => 'x', 'num' => 50], ['class' => 'service', 'line' => 'y', 'num' => null]],
    'svc' => ['slots' => ['morthal'], 'all' => ['solitude', 'morthal', 'riften', 'dawnstar'], 'offlist' => []]];
chk('a quoted price the list never showed is still caught',
    is_array(lrgDlgTruthCheck($tTruth, 'That will be 500 gold, friend.', [])),
    json_encode(lrgDlgTruthCheck($tTruth, 'That will be 500 gold, friend.', [])));
chk('...in the "will be" form too', is_array(lrgDlgTruthCheck($tTruth, 'Three nights will be 99 gold, love.', [])));
chk('...and in the "it costs" form', is_array(lrgDlgTruthCheck($tTruth, 'It costs 99 gold.', [])));
chk('past-tense narration about money is NOT a price claim',
    lrgDlgTruthCheck($tTruth, 'Aye. I lost 300 gold at dice in Riften last winter. Climb up.', []) === null,
    json_encode(lrgDlgTruthCheck($tTruth, 'Aye. I lost 300 gold at dice in Riften last winter. Climb up.', [])));
chk('a price the list really showed is never a claim',
    lrgDlgTruthCheck($tTruth, 'That will be 50 gold.', []) === null);

// --- S7: the two-word follower commands resolve ----------------------------------------------------
chk('"Follow me." resolves even when its topic is in no follower family',
    lrgDlgFollowerVerbOf($fol('CWPrisonerFollower', 'Follow me.')) === 'follow',
    lrgDlgFollowerVerbOf($fol('CWPrisonerFollower', 'Follow me.')));
chk('"Wait here." resolves the same way',
    lrgDlgFollowerVerbOf($fol('CWPrisonerWait', 'Wait here.')) === 'wait',
    lrgDlgFollowerVerbOf($fol('CWPrisonerWait', 'Wait here.')));
chk('an ordinary quest line that merely CONTAINS the words is still not a verb',
    lrgDlgFollowerVerbOf($fol('MQ201QuestTopic', 'Follow me down to the Greybeards and wait here a while.')) === '',
    lrgDlgFollowerVerbOf($fol('MQ201QuestTopic', 'Follow me down to the Greybeards and wait here a while.')));
chk('and a blocked topic is still refused however it resolves',
    lrgDlgFollowerVerbOf($fol('DialogueFollowerAnimalFollowTopic', 'Follow me.')) === '');

// --- S11: an action this module hid for the turn is dropped ----------------------------------------
$GLOBALS['LRG_DLG_SVC_HIDDEN'] = ['MakeFollower', 'HireCarriage'];
$GLOBALS['LRG_DLG_HOLD_HIDDEN'] = ['TravelTo'];
chk('a hidden service shortcut is recognised whatever its spelling',
    lrgDlgHiddenThisTurn('makefollower') && lrgDlgHiddenThisTurn('hirecarriage'));
chk('a hidden movement action is recognised too', lrgDlgHiddenThisTurn('travelto'));
chk('an action nobody hid is left alone', !lrgDlgHiddenThisTurn('givegoldto'));
unset($GLOBALS['LRG_DLG_SVC_HIDDEN'], $GLOBALS['LRG_DLG_HOLD_HIDDEN']);
chk('with nothing hidden the test is a no-op', !lrgDlgHiddenThisTurn('makefollower'));

// --- S5: the locked facts are built whatever bLockedFacts says; only the BLOCK honours it ----------
$tLock = ['npc' => 'Bjorlam Flowtest', 'cid' => 't', 'entries' => $dest(), 'tail' => [], 'facts' => ['pg' => 900],
    'svc' => ['slots' => ['morthal'], 'all' => ['solitude', 'morthal', 'riften', 'dawnstar']], 'q' => [], 'crime' => []];
$GLOBALS['LRG_DLG_MCM'] = ['lf' => 0];
$factsOff = lrgDlgLockedFacts($tLock);
$blockOff = lrgDlgLockedBlock($tLock + ['locked' => $factsOff]);
$GLOBALS['LRG_DLG_MCM'] = ['lf' => 1];
$factsOn = lrgDlgLockedFacts($tLock);
$blockOn = lrgDlgLockedBlock($tLock + ['locked' => $factsOn]);
unset($GLOBALS['LRG_DLG_MCM']);
chk('bLockedFacts still decides whether she is TOLD them',
    $blockOff === '' && str_contains($blockOn, '<locked_facts>'), $blockOff . '|' . fx_short_local($blockOn));
chk('the facts the truth gate needs exist with bLockedFacts either way',
    $factsOff !== [] && $factsOn !== [], json_encode([count($factsOff), count($factsOn)]));
// --- S4: <locked_facts> names the WHOLE list, never the one slot that matched
$svcLine = '';
foreach ($factsOn as $f) { if ((string) $f['class'] === 'service') { $svcLine = (string) $f['line']; } }
chk('the service fact lists every destination on her layer, not just the matched one',
    str_contains($svcLine, 'solitude') && str_contains($svcLine, 'riften') && str_contains($svcLine, 'morthal'),
    $svcLine);

/**
 * ------------------------------------------------------------------ 12. [0.5.7 / pt16] direct barter
 * "What do you have for sale?" at Solitude's market, 2026-09-23 16:40-16:43: five turns, nothing opened,
 * nobody said why. (a) the matcher on the exact STT strings of that evening, (b) the one decision
 * (lrgDlgServiceDirect), (c) the directive, (d) the net's exact wire line. The end-to-end half - the
 * hide policy, the blind snapshot, the real-entry path - is flow d56.
 */
head('12. [0.5.7 / pt16] direct barter: tonight\'s own words, the decision, the directive and the net');
unset($GLOBALS['LRG_DLG_MCM'], $GLOBALS['LRG_DLG_SVC_HIDDEN'], $GLOBALS['LRG_DLG_HOLD_HIDDEN'], $GLOBALS['LRG_DLG_TURN']);
// --- (a) every shape the owner's sentence arrived in, plus the ways of asking that the ten phrases lacked
foreach (['for sale', 'thing for sale', 'excuse me what do you have for sale?', 'what have you what have you got for sale sir?',
    'what do you have for sale', 'what do you sell', 'anything for sale', 'can i buy something', 'got anything to sell',
    "i'd like to buy a sword", 'sell me a sweetroll', 'let me see your wares'] as $s) {
    chk("barter: '$s'", lrgDlgServiceKind($s) === 'barter', lrgDlgServiceKind($s) ?: '(nothing)');
}
chk("STT noise 'a for several' (a 0.38 s clip) is nothing, and stays nothing", lrgDlgServiceKind('a for several') === '');
// [review] a hit followed by a person, a bribe or an idiom is NOT a request for her goods - before this guard
// "can i buy you a drink" was barter direct=1 and the window opened on the tavern opener (services.kinds.barter.not_after)
foreach (['can i buy you a drink', 'let me buy you a drink', "i'd like to buy you a drink", 'i want to buy you a drink',
    'let me buy you dinner', 'can i buy us a round', 'can i buy your silence', 'do you sell your body',
    "i'd like to trade places with you"] as $s) {
    chk("not barter (a gesture, a bribe or an idiom): '$s'", lrgDlgServiceKind($s) === '', lrgDlgServiceKind($s) ?: '(nothing)');
}
chk("'can i buy a drink' (from her) is still barter", lrgDlgServiceKind('can i buy a drink') === 'barter');
chk("'can i buy them all' is still barter", lrgDlgServiceKind('can i buy them all') === 'barter');
// [pt19-purchase] 'something to drink' left the inn kind (it is an ORDER, answered from her real stock - section 16); the barter
// phrase 'buy something' still classifies the sentence, and the market lane takes the turn over when the stock is fresh
chk("'can i buy something to drink' is barter now, not inn (the market lane owns the drink)", lrgDlgServiceKind('can i buy something to drink') === 'barter', lrgDlgServiceKind('can i buy something to drink') ?: '(nothing)');
chk("'sell me a sweetroll' is still barter after the guard", lrgDlgServiceKind('sell me a sweetroll') === 'barter');
chk("'how much for the salmon' is a price question", lrgDlgIsPriceQuestion('how much for the salmon'));
chk("'i need a room for the night' is still inn", lrgDlgServiceKind('i need a room for the night') === 'inn');
chk("\"let's trade\" still belongs to the follower kind (d62 owns it)", lrgDlgServiceKind("let's trade, hold this for me") === 'follower');

// --- (b) the decision, on Phase 2's own state only
$bt = static function (array $over = []): array {
    return $over + ['npc' => 'Addvar Test', 'cid' => 'b1', 'on' => true, 'speech' => true, 'talk' => false,
        'entries' => [], 'tail' => [], 'svc' => ['kind' => 'barter']];
};
$vendor = ['combat' => '0', 'mate' => '', 'class' => 'VendorFood', '_age' => 5,
    'fac' => 'CrimeFactionHaafingar,TownSolitudeFaction,JobMerchantFaction,JobStreetVendorFaction,ServicesSolitudeAddvar'];
$d = lrgDlgServiceDirect($bt(), 'what do you have for sale', $vendor, true);
chk('a vendor, ml absent (default 1), no list: DIRECT', (int) $d['direct'] === 1 && $d['vendor'] === 'vendor', json_encode($d));
$d = lrgDlgServiceDirect($bt(), 'what do you have for sale', null, true);
chk('NO snapshot at all (Jala 16:40:01, age 212647 s): still DIRECT - first contact at a stall has none',
    (int) $d['direct'] === 1 && $d['vendor'] === 'unknown', json_encode($d));
$d = lrgDlgServiceDirect($bt(), 'what do you have for sale', ['combat' => '1', '_age' => 10] + $vendor, true);
chk('a FRESH snapshot saying combat holds it back', (int) $d['direct'] === 0 && $d['why'] === 'combat', json_encode($d));
$d = lrgDlgServiceDirect($bt(), 'what do you have for sale', ['combat' => '1', '_age' => 600] + $vendor, true);
chk('... a STALE combat=1 does not', (int) $d['direct'] === 1, json_encode($d));
$d = lrgDlgServiceDirect($bt(), 'what do you have for sale', ['mate' => '1'] + $vendor, true);
chk('a teammate is never on the direct path (CHIM\'s own teammate branch answers her, no refusal)',
    (int) $d['direct'] === 0 && $d['why'] === 'companion', json_encode($d));
$d = lrgDlgServiceDirect($bt(), 'how much for your wares', $vendor, true);
chk('a price question asks, it does not order', (int) $d['direct'] === 0 && $d['why'] === 'price question', json_encode($d));
$d = lrgDlgServiceDirect($bt(), "don't sell me anything", $vendor, true);
chk('"don\'t sell me anything" is negated', (int) $d['direct'] === 0 && $d['why'] === 'negated', json_encode($d));
chk('"i don\'t want to buy anything" is not barter at all', lrgDlgServiceKind("i don't want to buy anything") === '');
$GLOBALS['LRG_DLG_MCM'] = ['ml' => 1];
$d = lrgDlgServiceDirect($bt(['entries' => [lrgDlgEntryFix(0, 'What have you got for sale?') + ['class' => 'service']]]),
    'what do you have for sale', $vendor, true);
chk('ml=1 with a known list: the REAL entry wins (D4) and the direct path stands aside',
    (int) $d['direct'] === 0 && $d['why'] === 'real entry', json_encode($d));
$GLOBALS['LRG_DLG_MCM'] = ['ml' => 0];
$d = lrgDlgServiceDirect($bt(['entries' => [lrgDlgEntryFix(0, 'What have you got for sale?') + ['class' => 'service']]]),
    'what do you have for sale', $vendor, true);
chk('ml=0 with the same list: the real entry cannot run, so DIRECT', (int) $d['direct'] === 1, json_encode($d));
unset($GLOBALS['LRG_DLG_MCM']);
$d = lrgDlgServiceDirect($bt(), 'what do you have for sale', ['fac' => 'TownSolitudeFaction,CrimeFactionHaafingar', 'class' => '', '_age' => 5], true);
chk('a snapshot that DID list her factions and found no vendor: the model decides, the net stays out',
    (int) $d['direct'] === 0 && $d['why'] === 'not a vendor' && $d['vendor'] === 'none', json_encode($d));
$d = lrgDlgServiceDirect($bt(), 'what do you have for sale', $vendor, false);
chk('an lrg_dlgtalk turn is never direct (it has a session of its own)', (int) $d['direct'] === 0, json_encode($d));
$d = lrgDlgServiceDirect($bt(['svc' => ['kind' => 'inn']]), 'i need a room', $vendor, true);
chk('another kind is never direct', (int) $d['direct'] === 0 && $d['why'] === 'not barter', json_encode($d));

// --- (c) the directive: do it with CHIM's own Trade_Items, or say why - never ignore it
$GLOBALS['ENABLED_FUNCTIONS'] = ['Talk', 'OpenInventory', 'RentRoom'];
$blk = lrgDlgServiceRequestBlock($bt(['svc' => ['kind' => 'barter', 'direct' => 1, 'direct_why' => '', 'vendor' => 'vendor']]));
chk('direct: the block names CHIM\'s own trade action and says "Do it now"',
    str_contains($blk, '<player_request>') && str_contains($blk, 'OpenInventory') && str_contains($blk, 'Do it now'), fx_cut($blk));
chk('... forbids prices (Addvar\'s "four septims apiece") and says never ignore it',
    str_contains($blk, 'Name no prices') && str_contains($blk, 'Never ignore it'), fx_cut($blk));
chk('... and overrides the blind-turn note above it in so many words',
    str_contains($blk, 'showing wares is done by the game itself'), fx_cut($blk));
$GLOBALS['ENABLED_FUNCTIONS'] = ['Talk'];
$blk = lrgDlgServiceRequestBlock($bt(['svc' => ['kind' => 'barter', 'direct' => 1, 'direct_why' => '', 'vendor' => 'vendor']]));
chk('direct but OpenInventory NOT on the table (CHIM\'s own row deactivated): she says plainly she cannot show them',
    str_contains($blk, 'cannot show any wares') && !str_contains($blk, 'Do it now') && str_contains($blk, 'Never ignore it'), fx_cut($blk));
$GLOBALS['ENABLED_FUNCTIONS'] = ['Talk', 'OpenInventory', 'RentRoom'];
$blk = lrgDlgServiceRequestBlock($bt(['svc' => ['kind' => 'barter', 'direct' => 0, 'direct_why' => 'companion', 'vendor' => 'unknown']]));
chk('a companion: the block names the action (CHIM opens her pack) and never refuses',
    str_contains($blk, 'travels with') && str_contains($blk, 'OpenInventory') && !str_contains($blk, 'cannot'), fx_cut($blk));
$blk = lrgDlgServiceRequestBlock($bt(['svc' => ['kind' => 'barter', 'direct' => 0, 'direct_why' => 'price question', 'vendor' => 'vendor']]));
chk('a price question: never invent a price - show the window instead', str_contains($blk, 'Never invent a price') && str_contains($blk, 'OpenInventory'), fx_cut($blk));
$blk = lrgDlgServiceRequestBlock($bt(['svc' => ['kind' => 'barter', 'direct' => 0, 'direct_why' => 'real entry', 'vendor' => 'vendor']]));
chk('the real entry on her list: nothing here (the <business> block carries it)', $blk === '', fx_cut($blk));
$blk = lrgDlgServiceRequestBlock($bt(['svc' => ['kind' => 'barter', 'direct' => 0, 'direct_why' => 'combat', 'vendor' => 'vendor']]));
chk('combat: the plain reason, no action', str_contains($blk, 'fight') && str_contains($blk, 'chooses no action'), fx_cut($blk));
$GLOBALS['LRG_DLG_MCM'] = ['ml' => 0];
$blk = lrgDlgServiceRequestBlock($bt(['svc' => ['kind' => 'inn', 'direct' => 0, 'direct_why' => '', 'vendor' => 'unknown']]));
chk('inn under ml=0 with RentRoom on the table: "Do it now: choose RentRoom ... otherwise say plainly why not"',
    str_contains($blk, 'Do it now') && str_contains($blk, 'RentRoom') && str_contains($blk, 'why not'), fx_cut($blk));
$GLOBALS['ENABLED_FUNCTIONS'] = ['Talk'];
$blk = lrgDlgServiceRequestBlock($bt(['svc' => ['kind' => 'carriage', 'direct' => 0, 'direct_why' => '', 'vendor' => 'unknown']]));
chk('carriage under ml=0 with HireCarriage NOT on the table (the hold hid it): say why, name nothing',
    str_contains($blk, 'no action for it') && !str_contains($blk, 'HireCarriage'), fx_cut($blk));
$GLOBALS['LRG_DLG_MCM'] = ['ml' => 1];
$GLOBALS['ENABLED_FUNCTIONS'] = ['Talk', 'OpenInventory', 'RentRoom'];
$blk = lrgDlgServiceRequestBlock($bt(['svc' => ['kind' => 'inn', 'direct' => 0, 'direct_why' => '', 'vendor' => 'unknown']]));
chk('inn under ml=1: nothing here - the real entry path owns it', $blk === '', fx_cut($blk));
unset($GLOBALS['LRG_DLG_MCM']);
$blk = lrgDlgServiceRequestBlock($bt(['speech' => false, 'talk' => true, 'svc' => ['kind' => 'barter', 'direct' => 1]]));
chk('never on the module\'s own lrg_dlgtalk turn', $blk === '', fx_cut($blk));

// --- (d) the net: the exact line CHIM itself emits for Trade_Items, once, and only when it is on the table
$GLOBALS['ENABLED_FUNCTIONS'] = ['Talk', 'OpenInventory'];
$GLOBALS['LAST_LLM_RESPONSE'] = ['action' => 'Talk', 'item' => '', 'target' => 'Addvar Test', 'message' => 'Have a look, then.'];
$GLOBALS['LRG_DLG_TURN'] = $bt(['svc' => ['kind' => 'barter', 'direct' => 1, 'direct_why' => '', 'vendor' => 'vendor'], 'q' => [], 'locked' => [], 'check' => null]);
$w = lrgDlgPostProcessActions([]);
chk('the reply carried no trade action: exactly ONE line, the vanilla barter window on CHIM\'s own code',
    $w === ["Addvar Test|command|OpenInventory@Testplayer\r\n"], json_encode($w));
$w = lrgDlgPostProcessActions(["Addvar Test|command|OpenInventory@Testplayer\r\n"]);
chk('the model chose it itself: the line passes and nothing is added (never both)',
    $w === ["Addvar Test|command|OpenInventory@Testplayer\r\n"], json_encode($w));
$w = lrgDlgPostProcessActions(["Addvar Test|command|Trade_Items@Testplayer\r\n"]);
chk('... the snake-cased display name counts as chosen too', count($w) === 1, json_encode($w));
$w = lrgDlgPostProcessActions(["Addvar Test|command|OpenInventory2@Testplayer\r\n"]);
chk('... and so does the gift menu (OpenInventory2)', count($w) === 1 && str_contains($w[0], 'OpenInventory2'), json_encode($w));
$w = lrgDlgPostProcessActions(["Somebody Else|command|OpenInventory@Testplayer\r\n"]);
chk('another actor\'s trade line does not count as hers', count($w) === 2 && str_starts_with($w[1], 'Addvar Test|command|OpenInventory@'), json_encode($w));
$GLOBALS['ENABLED_FUNCTIONS'] = ['Talk'];
$w = lrgDlgPostProcessActions([]);
chk('OpenInventory not offered (CHIM\'s own row deactivated): nothing is smuggled past the offer', $w === [], json_encode($w));
// bServiceShortcut OFF (svx byte 0) hides the rooms / rides / training shortcuts with no list, never trade
$GLOBALS['LRG_DLG_MCM'] = ['svsc' => 0, 'ml' => 0];
$hp = lrgDlgServiceHidePolicy($bt(), false);
chk('bServiceShortcut off with no list hides RentRoom / HireCarriage / Training ...',
    in_array('RentRoom', $hp, true) && in_array('HireCarriage', $hp, true) && in_array('Training', $hp, true), implode(',', $hp));
chk('... but never OpenInventory: trade by talking is the real window with the real prices',
    !in_array('OpenInventory', $hp, true) && !in_array('OpenInventory2', $hp, true), implode(',', $hp));
unset($GLOBALS['LRG_DLG_MCM']);
$GLOBALS['ENABLED_FUNCTIONS'] = ['Talk', 'OpenInventory'];
$GLOBALS['LRG_DLG_SVC_HIDDEN'] = ['OpenInventory'];
$w = lrgDlgPostProcessActions(["Addvar Test|command|OpenInventory@Testplayer\r\n"]);
chk('a code this module HID this turn is dropped AND not re-added', $w === [], json_encode($w));
unset($GLOBALS['LRG_DLG_SVC_HIDDEN']);
$GLOBALS['LRG_DLG_TURN'] = $bt(['svc' => ['kind' => 'barter', 'direct' => 0, 'direct_why' => 'negated', 'vendor' => 'vendor'], 'q' => [], 'locked' => [], 'check' => null]);
$w = lrgDlgPostProcessActions([]);
chk('direct=0: nothing at all', $w === [], json_encode($w));
unset($GLOBALS['LRG_DLG_TURN'], $GLOBALS['ENABLED_FUNCTIONS'], $GLOBALS['LAST_LLM_RESPONSE']);
// --- (e) config defaults: on, and the per-kind hide lists still a subset of the shipped ones (d56 asserts it live)
chk('services.direct.barter defaults ON', !empty(lrgDlgCfg('services.direct.barter')));
chk('services.direct.request_block defaults ON', !empty(lrgDlgCfg('services.direct.request_block')));

/**
 * ------------------------------------------------------------------ 13. [0.5.7 / pt16-legion] enlistment
 * Captain Aldis, 2026-09-23 16:38: "I want to join the Legion" got an invented appointment ("Report to the
 * training yard at Castle Dour when the bell rings") and no quest, because nothing true about recruitment
 * reached the model and Aldis owns no join line. (a) the recogniser on the three REAL utterances of the day,
 * (b) the role from the real fac csv / name / list, (c) the positive locked line and the rule, (d) the carry
 * onto the rechat and the next player turn, (e) no open for awareness on a redirect, (f) the hand-off by exact
 * topic / line, never similarity. lib/lrg_factions.php.
 */
head('13. [0.5.7 / pt16-legion] enlistment: the ask, the role, the locked lines, the rule, the open and the hand-off');
unset($GLOBALS['LRG_DLG_MCM'], $GLOBALS['LRG_DLG_TURN'], $GLOBALS['LRG_DLG_SVC_HIDDEN'], $GLOBALS['LRG_DLG_HOLD_HIDDEN']);
$GLOBALS['LRG_TEST_NOW'] = 1700000000;
$GLOBALS['gameRequest'] = ['inputtext', 1700000000, 100000, 'x'];   // not a fast-path type: lrgDlgEmit returns the line
$aldisFac = 'CrimeFactionHaafingar,CWImperialFaction,CWImperialFactionNPC,TownSolitudeFaction,Favor110QuestGiverFaction';
$guardFac = 'CrimeFactionImperial,CrimeFactionHaafingar,GuardDialogueFaction,CWImperialFaction,CWImperialFactionNPC,GuardFactionSolitude,IsGuardFaction';
$ft = static function (array $over = []) use ($aldisFac): array {
    return $over + ['npc' => 'Captain Aldis', 'cid' => 'f1', 'type' => 'inputtext', 'on' => true, 'speech' => true, 'talk' => false,
        'entries' => [], 'tail' => [], 'snap' => ['fac' => $aldisFac, '_age' => 5], 'q' => ['MS07', 'Favor110Solitude'],
        'facts' => [], 'svc' => [], 'crime' => [], 'list' => 'none', 'layer_kind' => 'unknown', 'n' => 0, 'sent' => 0,
        'crit' => 0, 'scene' => 0, 'open' => 0, 'lost' => 0, 'why' => [], 'offer' => [], 'ask' => '', 'arrest' => '',
        'fol' => [], 'check' => null, 'sid' => '0', 'gen' => 0, 'list_pg' => 0, 'assisted' => 0, 'parked' => []];
};
$fe = static function (string $topic, string $text, int $pos, string $class = 'plain'): array {
    return lrgDlgEntryFix($pos, $text) + ['norm' => lrgPromptNorm($text), 'class' => $class, 'kind' => '', 'cost' => 0,
        'commit' => $class === 'commit', 'crit' => 0, 'topic' => $topic, 'quest' => '', 'twat' => '', 'afford' => 1,
        'walkaway' => 0, 'compound' => 0, 'indexed' => 1, 'variant' => 'na', 'scripted' => 0, 'goodbye' => 0, 'label' => ''];
};

// --- (a) the recogniser: today's three real utterances, plus the plain ways of asking
foreach (["that's where i come to start being an imperial i was looking to serve sir",
    "what's up bro? do you know where i could sign up for the legion?",
    "up man i was looking to join the legion i'm kind of a low life", 'I want to join the Legion',
    'how do I enlist in the Imperial Legion', 'i want to fight for the empire', "I'd like to join up with the Legion"] as $s) {
    $a = lrgFacAsk($s);
    chk("ask -> legion: '$s'", !empty($a['join']) && $a['faction'] === 'legion', json_encode($a));
}
$a = lrgFacAsk('can I sign up with the Stormcloaks?');
chk('ask -> stormcloaks: "can I sign up with the Stormcloaks?"', !empty($a['join']) && $a['faction'] === 'stormcloaks', json_encode($a));
$a = lrgFacAsk('I would like to join the Companions.');
chk('ask -> companions', !empty($a['join']) && $a['faction'] === 'companions', json_encode($a));
foreach (["I don't want to join the Legion", 'why did you join the Legion?', 'you should join the Legion', 'join me',
    'can i join you', 'will you join my party', "i'm proud of being an imperial", 'a for several',
    'how goes the training?'] as $s) {
    chk("not an ask: '$s'", empty(lrgFacAsk($s)['join']), json_encode(lrgFacAsk($s)));
}
chk('"join me" is still the follower recruit verb (d62 owns it)',
    in_array('recruit', array_column(lrgDlgFollowerIntents('join me'), 'verb'), true));
$a = lrgFacAsk('i want to join');
chk('a bare "i want to join" to nobody in particular: an ask with no faction', !empty($a['join']) && $a['faction'] === '', json_encode($a));
$a = lrgFacAsk('i want to join', ['legion']);
chk('...and to a legionary it is the Legion', $a['faction'] === 'legion', json_encode($a));
$a = lrgFacAsk('i want to join the college');
chk('"the college" alone is ambiguous between Winterhold and the Bards (no faction, both named)',
    !empty($a['join']) && $a['faction'] === '' && count($a['ambiguous']) === 2, json_encode($a));
$a = lrgFacAsk('i want to join the college', ['bards']);
chk('...and a bard settles it', $a['faction'] === 'bards', json_encode($a));
$a = lrgFacAsk('i want to serve');
chk('a weak phrase with no faction word and no faction NPC is not an ask', empty($a['join']), json_encode($a));
$a = lrgFacAsk('i want to serve', ['legion']);
chk('...but to a legionary it is', !empty($a['join']) && $a['faction'] === 'legion', json_encode($a));
$a = lrgFacAsk('i want to join the legion, not the stormcloaks');
chk('a negated faction word does not count', $a['faction'] === 'legion', json_encode($a));
$a = lrgFacAsk('join the Legion');
chk('the model\'s own item shape "join the Legion" is read', !empty($a['join']) && $a['faction'] === 'legion', json_encode($a));

// --- (b) the role, from what the game already sends
$roles = static fn(array $t): array => lrgFacRoles($t);
chk('Captain Aldis (his real fac csv) is a REDIRECT for the Legion, never a recruiter',
    ($roles($ft())['legion'] ?? '') === 'redirect', json_encode($roles($ft())));
chk('...and no role for the guard until his list carries the AJO guard-duty line', !isset($roles($ft())['guards']), json_encode($roles($ft())));
$ajo = $fe('ANDR_AJO_GuardWhiterunImperial_StartTopic01', 'I would like to take guard duty.', 3, 'commit');
chk('...with it he is the guard-duty recruiter (the captain) - by topic, no name needed',
    ($roles($ft(['entries' => [$ajo]]))['guards'] ?? '') === 'recruiter', json_encode($roles($ft(['entries' => [$ajo]]))));
$tR = $ft(['npc' => 'Legate Rikke', 'snap' => [], 'q' => []]);
chk('Legate Rikke is a recruiter by name, snapshot or not', ($roles($tR)['legion'] ?? '') === 'recruiter', json_encode($roles($tR)));
$tL = $ft(['npc' => 'Legate Somebody', 'snap' => ['fac' => 'CWFieldCOFaction,CWDialogueSoldierFaction,CWImperialFaction'], 'q' => []]);
chk('an unnamed legate with the recruiter faction trio but no recruiter line: REDIRECT (the camp-legate tier)',
    ($roles($tL)['legion'] ?? '') === 'redirect', json_encode($roles($tL)));
$tL2 = $tL; $tL2['entries'] = [$fe('CW00RikkeBlockingTopic', 'About that test...', 0)];
chk('...and with a recruiter topic really on her list: recruiter', ($roles($tL2)['legion'] ?? '') === 'recruiter', json_encode($roles($tL2)));
$tB = $ft(['npc' => 'Bjorskir [Solitude Guard]', 'snap' => ['fac' => $guardFac], 'q' => []]);
chk('a Solitude guard (real fac csv) is a redirect for the Legion AND for the guard',
    ($roles($tB)['legion'] ?? '') === 'redirect' && ($roles($tB)['guards'] ?? '') === 'redirect', json_encode($roles($tB)));
$tJ = $ft(['npc' => 'Jala', 'snap' => ['fac' => 'CrimeFactionHaafingar,TownSolitudeFaction,JobMerchantFaction,JobStreetVendorFaction'], 'q' => []]);
chk('a fruit vendor has no role at all', $roles($tJ) === [], json_encode($roles($tJ)));
$tQ = $ft(['npc' => 'Somebody', 'snap' => [], 'q' => ['CW01']]);
chk('quest membership alone (a CW01 alias) is at most a redirect, never a recruiter', ($roles($tQ)['legion'] ?? '') === 'redirect', json_encode($roles($tQ)));

// --- (c) the turn, the positive locked line and the rule (Aldis, the owner's exact words)
$tA = $ft();
$tA['faction'] = lrgFacTurn($tA, "that's where i come to start being an imperial i was looking to serve sir", true);
chk('the turn carries the ask: legion, role redirect, from his own words',
    ($tA['faction']['asked'] ?? '') === 'legion' && ($tA['faction']['role'] ?? '') === 'redirect' && empty($tA['faction']['carried']),
    json_encode($tA['faction']));
$GLOBALS['LRG_DLG_MCM'] = ['lf' => 1, 'ml' => 0];
$facts = lrgDlgLockedFacts($tA);
$facLine = '';
foreach ($facts as $f) { if ((string) $f['class'] === 'faction') { $facLine = (string) $f['line']; break; } }
chk('the FIRST locked fact is the faction line (the 600-char cap trims from the end)',
    (string) ($facts[0]['class'] ?? '') === 'faction', json_encode(array_column($facts, 'class')));
chk('it names General Tullius, Legate Rikke and Castle Dour',
    str_contains($facLine, 'General Tullius') && str_contains($facLine, 'Legate Rikke') && str_contains($facLine, 'Castle Dour'), $facLine);
chk('it overrides CHIM\'s persona: his recruits are ALREADY enlisted, he enlists nobody, never through him',
    str_contains($facLine, 'already enlisted') && str_contains($facLine, 'you enlist nobody') && str_contains($facLine, 'never through you'), $facLine);
chk('it forbids a time, a place, a drill or a test', str_contains($facLine, 'a time, a place, a drill or a test'), $facLine);
chk('it never asserts the player CAN join', str_contains($facLine, 'you do not know unless the game says so'), $facLine);
chk('it is marked a fact of the world, not of this moment', str_starts_with($facLine, 'of this world, not of this moment'), $facLine);
chk('no angle bracket in any line (the $add closure would drop it)', !preg_match('/[<>]/', implode(' ', array_column($facts, 'line'))));
chk('the quest line is still there after it', in_array('quest', array_column($facts, 'class'), true) || empty($GLOBALS['LRG_DLG_TEST_QUESTLOG']),
    json_encode(array_column($facts, 'class')));
$block = lrgDlgLockedBlock($tA + ['locked' => $facts]);
chk('the block carries the rule: never invent an appointment, and never say an enlistment has begun',
    str_contains($block, 'Never invent an appointment') && str_contains($block, 'has begun'), fx_cut($block));
chk('...the rule is absent on an ordinary turn', !str_contains(lrgDlgLockedBlock($tLock + ['locked' => $factsOn]), 'Never invent an appointment'));
chk('the block still fits the 600-char cap with room to spare', strlen($block) < 900, (string) strlen($block));
chk('lrgDlgStaticGuidance() stays silent on a faction turn with no list (flow 16 contract, no <real_business> widening)',
    lrgDlgStaticGuidance($tA) === '');
chk('the turn line carries faction=legion:redirect', str_contains(lrgDlgTurnLine($tA), ' faction=legion:redirect'), lrgDlgTurnLine($tA));
$tA2 = $ft(['entries' => [$ajo]]);
$tA2['faction'] = $tA['faction'];
$lines2 = lrgFacLockedLines($tA2);
chk('with the guard-duty line on his list a SECOND line offers the one thing he really can, only if asked',
    count($lines2) === 2 && str_contains($lines2[1], 'guard duty') && str_contains($lines2[1], 'only if he asks'), json_encode($lines2));
chk('without it, one line', count(lrgFacLockedLines($tA)) === 1, json_encode(lrgFacLockedLines($tA)));
// the guard who first sent the owner to Aldis (Bjorskir, 04:39:26) gets the same fact
$tB['faction'] = lrgFacTurn($tB, "what's up bro? do you know where i could sign up for the legion?", true);
$lb = lrgFacLockedLines($tB);
chk('the Solitude guard gets the same redirect (Legate Rikke in Castle Dour)',
    ($tB['faction']['role'] ?? '') === 'redirect' && str_contains($lb[0] ?? '', 'Legate Rikke'), json_encode($lb));
// a vendor asked about the Legion: the world fact only, no "you enlist nobody"
$tJ['faction'] = lrgFacTurn($tJ, 'I want to join the Legion', true);
$lj = lrgFacLockedLines($tJ);
chk('a fruit vendor: role none, the world fact only - no "you enlist nobody" sentence about her',
    ($tJ['faction']['role'] ?? '') === 'none' && str_contains($lj[0] ?? '', 'Legate Rikke') && !str_contains($lj[0] ?? '', 'you enlist nobody'), json_encode($lj));
// the recruiter's line, both ways
$tR['faction'] = lrgFacTurn($tR, 'I want to join the Legion', true);
chk('Legate Rikke: role recruiter on the turn', ($tR['faction']['role'] ?? '') === 'recruiter', json_encode($tR['faction']));
$GLOBALS['LRG_DLG_MCM'] = ['lf' => 1, 'ml' => 1];
$lr = lrgFacLockedLines($tR);
chk('ml=1: her line names her real entry and the action, and that nothing has begun',
    str_contains($lr[0] ?? '', "'About that test'") && str_contains($lr[0] ?? '', lrgDlgActionName()) && str_contains($lr[0] ?? '', 'nothing has begun'), $lr[0] ?? '');
$GLOBALS['LRG_DLG_MCM'] = ['lf' => 1, 'ml' => 0];
$lr0 = lrgFacLockedLines($tR);
chk('ml=0: no action is named - enlistment is settled in her own dialogue and she SAYS so (never silent)',
    !str_contains($lr0[0] ?? '', lrgDlgActionName()) && str_contains($lr0[0] ?? '', 'own dialogue') && str_contains($lr0[0] ?? '', 'tell him so plainly'), $lr0[0] ?? '');
$tG = $ft(['entries' => [$ajo]]);
$tG['faction'] = lrgFacTurn($tG, 'i want to join the guard', true);
chk('"i want to join the guard" to the captain: the guard-duty recruiter line, and there is no enlisting',
    ($tG['faction']['asked'] ?? '') === 'guards' && ($tG['faction']['role'] ?? '') === 'recruiter'
    && str_contains(lrgFacLockedLines($tG)[0] ?? '', 'no enlisting as a guard'), json_encode([$tG['faction'], lrgFacLockedLines($tG)]));
$tX = $ft(['npc' => 'Somebody', 'snap' => [], 'q' => []]);
$tX['faction'] = lrgFacTurn($tX, 'i want to join the penitus oculatus', true);
chk('an unverified cell emits no name and no place (Penitus Oculatus: "their own outpost")',
    str_contains(lrgFacLockedLines($tX)[0] ?? '', 'their own outpost') && !preg_match('/Maro|Dragon Bridge/', lrgFacLockedLines($tX)[0] ?? ''), json_encode(lrgFacLockedLines($tX)));

// --- (d) the carry: the rechat and the next player line, inside the window
$GLOBALS['LRG_TEST_NOW'] = 1700000000;
lrgFacTurn($ft(), "that's where i come to start being an imperial i was looking to serve sir", true);   // the ask, stored at NOW
$GLOBALS['LRG_TEST_NOW'] = 1700000020;
$tRe = $ft(['type' => 'rechat', 'speech' => false, 'talk' => false]);
$tRe['faction'] = lrgFacTurn($tRe, "that's where i come to start being an imperial i was looking to serve sir", false);
chk('a rechat 20 s after the ask carries it (16:38:43 was the worst line of the evening)',
    ($tRe['faction']['asked'] ?? '') === 'legion' && !empty($tRe['faction']['carried']), json_encode($tRe['faction']));
$ff = lrgFacLockedFacts($tRe);
chk('...and lrgFacLockedFacts() hands it the faction class ALONE',
    count($ff) >= 1 && array_unique(array_column($ff, 'class')) === ['faction'], json_encode(array_column($ff, 'class')));
chk('...so the block on that rechat carries the fact and the rule',
    str_contains(lrgDlgLockedBlock($tRe + ['locked' => $ff]), 'Legate Rikke') && str_contains(lrgDlgLockedBlock($tRe + ['locked' => $ff]), 'Never invent'),
    fx_cut(lrgDlgLockedBlock($tRe + ['locked' => $ff])));
chk('the turn line says so', str_contains(lrgDlgTurnLine($tRe), ' faction=legion:redirect:carried'), lrgDlgTurnLine($tRe));
$tYes = $ft();
$tYes['faction'] = lrgFacTurn($tYes, 'yes sir', true);
chk('"yes sir" 20 s later carries it too', ($tYes['faction']['asked'] ?? '') === 'legion' && !empty($tYes['faction']['carried']), json_encode($tYes['faction']));
$tFr = $ft(['type' => 'funcret', 'speech' => false]);
chk('a funcret never carries it', lrgFacTurn($tFr, '', false) === [], json_encode(lrgFacTurn($tFr, '', false)));
$GLOBALS['LRG_TEST_NOW'] = 1700000200;
chk('beyond the window (90 s) the rechat carries nothing', lrgFacTurn($ft(['type' => 'rechat', 'speech' => false]), '', false) === []);
chk('...nor does an ordinary line', lrgFacTurn($ft(), 'lovely weather', true) === []);
$GLOBALS['LRG_TEST_NOW'] = 1700000000;

// --- (e) no open for awareness on a redirect; a recruiter opens only under ml=1
$GLOBALS['LRG_DLG_TEST_QUEUE'] = [];
$GLOBALS['LRG_DLG_MCM'] = ['io' => 1, 'ml' => 0, 'lf' => 1];
chk('Aldis (a quest NPC, so a marker exists) is NOT opened for awareness on an enlistment - his Say-Once greeting stays',
    lrgDlgMaybeOpen($tA, 'join the Legion') === null && $GLOBALS['LRG_DLG_TEST_QUEUE'] === [], json_encode($GLOBALS['LRG_DLG_TEST_QUEUE']));
chk('a recruiter under ml=0 is not opened either (the game would refuse it) - she was told to say so',
    lrgDlgMaybeOpen($tR, 'join the Legion') === null && $GLOBALS['LRG_DLG_TEST_QUEUE'] === []);
$GLOBALS['LRG_DLG_MCM'] = ['io' => 1, 'ml' => 1, 'lf' => 1];
chk('a recruiter under ml=1 is a business marker of her own', lrgDlgBusinessMarker($tR, 'join the Legion') === 'a faction this NPC recruits for',
    lrgDlgBusinessMarker($tR, 'join the Legion'));
$opened = lrgDlgMaybeOpen($tR, 'join the Legion');
chk('...and opens for awareness', is_string($opened) && str_contains($opened, ';do=open;'), (string) $opened);
$GLOBALS['LRG_DLG_TEST_QUEUE'] = [];

// --- (f) the hand-off: exact topic or exact line, ambiguity asks, no candidate answers in words
$poolR = [$fe('CW00RikkeBlockingTopic', 'About that test...', 0), $fe('CW00RikkeGreetWhatTest', 'What kind of test?', 1),
    $fe('DialogueGenericHello', 'Hello.', 2)];
$out = lrgFacArbitrate($tR, $poolR, 'join the Legion', 'f1');
chk('Rikke: the one entry on her recruiter topic is picked', is_array($out) && is_array($out['entry'])
    && (string) $out['entry']['topic'] === 'CW00RikkeBlockingTopic', json_encode($out));
$poolT = [$fe('CW00TulliusGreetTalkRikkateAP', "I don't want to sit idly by after what I've witnessed. I want to join the Legion", 0),
    $fe('MQ103TulliusBookTopic', "I'd like to join the Imperial Legion.", 1)];
$tT = $ft(['npc' => 'General Tullius', 'snap' => [], 'q' => []]);
$tT['faction'] = lrgFacTurn($tT, 'i want to join the legion', true);
$out = lrgFacArbitrate($tT, $poolT, 'join', 'f1');
chk('two join entries at once: AMBIGUOUS, entry=null, she asks', is_array($out) && $out['entry'] === null, json_encode($out));
// [pt17 / orchestrator] THIS save: Alternate Perspective start, MQ101 stage 0. The AP line is unreachable; Tullius's open
// join entry is USSEP's CW00TulliusGreetFreeToGo ("I was set free. I could've gone anywhere. I came here to fight for the
// Empire.") - no join word in it, so it is answered by the exact line, never by similarity.
$poolF = [$fe('CW00TulliusGreetFreeToGo', "I was set free. I could've gone anywhere. I came here to fight for the Empire.", 0),
    $fe('DialogueGenericHello', 'Hello.', 1)];
$out = lrgFacArbitrate($tT, $poolF, 'join', 'f1');
chk('[pt17] Tullius on an AP start: the FreeToGo line (no join word) is picked by the exact line',
    is_array($out) && is_array($out['entry']) && (string) $out['entry']['topic'] === 'CW00TulliusGreetFreeToGo', json_encode($out));
// [pt18-quest] CORRECTION of pt17: on this save AP's greet 0D5146 is CLOSED (MQQuickstart 7.0 vs its < 7), so the FreeToGo
// line is unreachable until Helgen; Tullius's recruiter line is now the AP road's (206783), and the FreeToGo norm stays
// in `entries` for the exact-line match on a save whose greet is open (the arbitrate check above still passes on it)
chk('[pt18-quest] the Legion row anchors Tullius on the AP line, keeps the FreeToGo norm as an exact line, and lists the Hadvar alternate',
    str_contains((string) lrgFacCfg('rows.legion.recruiters.General Tullius'), 'I want to join the Legion')
    && in_array("i was set free i could've gone anywhere i came here to fight for the empire", (array) lrgFacCfg('rows.legion.entries'), true)
    && in_array('CW00TulliusGreetHadvar', (array) lrgFacCfg('rows.legion.recruiter_topics'), true));
$tR0 = $ft(['npc' => 'Legate Rikke', 'snap' => ['fac' => 'CWImperialFaction,CWFieldCOFaction'], 'q' => ['CW00A']]);
$tR0['facts'] = ['qst' => ['CW00A' => 0]];
$tR0['faction'] = lrgFacTurn($tR0, 'i want to join the legion', true);
$lR0 = lrgFacLockedLines($tR0);
chk('[pt17] Rikke before Tullius has sent him (CW00A stage 0): she says General Tullius comes first and sets nothing',
    (string) ($tR0['faction']['role'] ?? '') === 'recruiter' && str_contains($lR0[0] ?? '', 'is not open before that') && str_contains($lR0[0] ?? '', 'at the map table in Castle Dour') && str_contains($lR0[0] ?? '', 'nothing has begun'), json_encode($lR0));
$tR1 = $tR0;
$tR1['facts'] = ['qst' => ['CW00A' => 10]];
$lR1 = lrgFacLockedLines($tR1);
chk('[pt17] ...and once CW00A is at stage 10 the ordinary recruiter line is back',
    !str_contains($lR1[0] ?? '', 'is not open before that') && str_contains($lR1[0] ?? '', 'nothing has begun'), json_encode($lR1));
$poolA = [$fe('Favor110QuestGiveTopicSolitude', 'Are you with the Legion?', 0), $fe('DialogueSolitudeValdBranchTopic', 'How goes the training?', 1), $ajo];
$out = lrgFacArbitrate($tA, $poolA, 'join the Legion', 'f1');
chk('Aldis\'s REAL list: "i want to join the legion" executes NOTHING - no join entry exists on it',
    is_array($out) && $out['entry'] === null, json_encode($out));
$sim = lrgDlgMatchText('i want to join the legion', $poolA);
chk('...while the similarity matcher tops on "Are you with the Legion?" (which is why it is bypassed)',
    $sim !== null && (string) $poolA[$sim['i']]['topic'] === 'Favor110QuestGiveTopicSolitude', json_encode($sim));
$poolG = [$fe('SolitudeFreeformGuardSolitudeArmy', 'How do I join the Imperial Legion?', 0), $fe('SolitudeFreeformGuardSolitudeInfo', 'Who is Roggvir?', 1)];
$out = lrgFacArbitrate($tB, $poolG, 'join the Legion', 'f1');
chk('the guard\'s own real answer line ("speak to Legate Rikke in Castle Dour") is picked on his list',
    is_array($out) && is_array($out['entry']) && (string) $out['entry']['topic'] === 'SolitudeFreeformGuardSolitudeArmy', json_encode($out));
$poolC = [$fe('CRNoWorkBranchTopic', "I'm looking for work.", 0), $fe('CRNoWorkBranchTopic', 'Can I join the Companions?', 1)];
$tAe = $ft(['npc' => 'Aela the Huntress', 'snap' => ['fac' => 'CompanionsFaction,CrimeFactionWhiterun'], 'q' => ['C00']]);
$tAe['faction'] = lrgFacTurn($tAe, 'can i join the companions', true);
$out = lrgFacArbitrate($tAe, $poolC, 'join', 'f1');
chk('a SHARED topic: only the entry whose own words are about joining is a candidate',
    is_array($out) && is_array($out['entry']) && (string) $out['entry']['norm'] === 'can i join the companions', json_encode($out));
$tN = $ft();
$tN['faction'] = [];
chk('no ask anywhere: null, ordinary matching applies', lrgFacArbitrate($tN, $poolA, 'guard duty', 'f1') === null);
chk('the model\'s own item carries the ask when the player\'s words did not - and still nothing is clicked on Aldis',
    lrgFacArbitrate($tN, $poolA, 'join the Legion', 'f1') === ['entry' => null], json_encode(lrgFacArbitrate($tN, $poolA, 'join the Legion', 'f1')));
// the fast path (want=1), which has no turn record
$GLOBALS['LRG_DLG_STATE']['Legate Rikke']['utter'] = ['text' => 'I want to join the Legion', 'at' => 1700000000, 'type' => 'inputtext'];
$w = lrgFacArbitrateWant('Legate Rikke', $poolR, lrgDlgState('Legate Rikke'), '', 'f1');
chk('want=1: Rikke\'s entry is picked by index', is_array($w) && (int) $w['i'] === 0, json_encode($w));
chk('want=1: Aldis\'s list gives false (nothing clicked, her words answer)',
    lrgFacArbitrateWant('Captain Aldis', $poolA, ['utter' => ['text' => 'i want to join the legion']], '', 'f1') === false);
chk('want=1: nobody asked gives null', lrgFacArbitrateWant('Captain Aldis', $poolA, ['utter' => ['text' => 'how goes the training']], '', 'f1') === null);
unset($GLOBALS['LRG_DLG_MCM']);

// --- (g) the table itself
chk('faction is a truth class', in_array('faction', (array) lrgDlgCfg('truth.classes'), true), json_encode(lrgDlgCfg('truth.classes')));
chk('factions.enabled defaults ON', !empty(lrgFacCfg('enabled')));
$bad = [];
foreach ((array) lrgFacCfg('rows', []) as $id => $row) {
    foreach (['redirect', 'member', 'gate', 'offer', 'how', 'how_entry', 'recruiter_line', 'name'] as $cell) {
        if (preg_match('/[<>]/', (string) ($row[$cell] ?? ''))) { $bad[] = $id . '.' . $cell; }
    }
    if (trim((string) ($row['redirect'] ?? '')) !== '' && trim((string) ($row['verified_from'] ?? '')) === '') { $bad[] = $id . ':unverified'; }
    foreach ((array) ($row['recruiters'] ?? []) as $rn => $line) { if (preg_match('/[<>]/', (string) $line)) { $bad[] = $id . '.' . $rn; } }
}
chk('every rendered cell is bracket-free and every redirect cites where it was verified', $bad === [], implode(',', $bad));
chk('the Legion row is anchored on the guard\'s real answer and the Tullius / Rikke records',
    str_contains((string) lrgFacCfg('rows.legion.verified_from'), 'SolitudeFreeformGuardSolitudeArmy')
    && str_contains((string) lrgFacCfg('rows.legion.verified_from'), '0D514F'));
chk('the owner can override a row through dialogue.factions (lrgMerge: maps merge, lists replace)', (static function (): bool {
    $GLOBALS['LRG_FAC_TEST_OVERRIDE'] = ['rows' => ['legion' => ['gate' => 'test gate']]];
    $ok = (string) lrgFacCfg('rows.legion.gate') === 'test gate' && (string) lrgFacCfg('rows.legion.name') === 'the Imperial Legion';
    unset($GLOBALS['LRG_FAC_TEST_OVERRIDE']);
    lrgFacCfg();
    return $ok && (string) lrgFacCfg('rows.legion.gate') === '';
})());

// --- (h) [reviewer] a CARRIED ask is prompt context only: it rides <locked_facts> for 90 s but never owns a
// click or blocks an open for OTHER business on the same NPC (lrgFacClickAsk)
$GLOBALS['LRG_TEST_NOW'] = 1700000000;
$GLOBALS['LRG_DLG_MCM'] = ['io' => 1, 'ml' => 1, 'lf' => 1];
$tH1 = $ft(['entries' => $poolA]);
$tH1['faction'] = lrgFacTurn($tH1, 'i want to join the legion', true);
$GLOBALS['LRG_TEST_NOW'] = 1700000030;
$tH2 = $ft(['entries' => $poolA]);
$tH2['faction'] = lrgFacTurn($tH2, 'so how goes the training', true);
chk('30 s later an unrelated line still carries the ask for the prompt', !empty($tH2['faction']['carried']), json_encode($tH2['faction']));
chk('...but the carried ask does not own the click: an unrelated item falls through to ordinary matching (null)',
    lrgFacArbitrate($tH2, $poolA, 'How goes the training', 'f1') === null, json_encode(lrgFacArbitrate($tH2, $poolA, 'How goes the training', 'f1')));
chk('...while an ask-bearing item on the same carried turn is still the enlistment (nothing on Aldis\'s list)',
    lrgFacArbitrate($tH2, $poolA, 'join the Legion', 'f1') === ['entry' => null]);
$GLOBALS['LRG_DLG_TEST_QUEUE'] = [];
$tH3 = $ft();
$tH3['faction'] = lrgFacTurn($tH3, 'how goes the training', true);
chk('a first-contact open for OTHER business is not refused by the carried ask (Aldis is a quest NPC: it opens)',
    !lrgFacRefusesOpen($tH3, 'f1', 'the training') && is_string(lrgDlgMaybeOpen($tH3, 'the training')));
$GLOBALS['LRG_DLG_TEST_QUEUE'] = [];
chk('...whereas "join the Legion" on the same carried turn is still refused',
    lrgFacRefusesOpen($tH3, 'f1', 'join the Legion') && lrgDlgMaybeOpen($tH3, 'join the Legion') === null && $GLOBALS['LRG_DLG_TEST_QUEUE'] === []);
$tH4 = $ft(['npc' => 'Legate Rikke', 'snap' => [], 'q' => []]);
$tH4['faction'] = ['asked' => 'legion', 'join' => 1, 'role' => 'recruiter', 'carried' => 1];
chk('a carried recruiter ask is no business marker for an unrelated item, and still one for an enlistment',
    lrgFacMarker($tH4, 'the weather') === '' && lrgFacMarker($tH4, 'join the Legion') === 'a faction this NPC recruits for');
$GLOBALS['LRG_DLG_TEST_QUEUE'] = [];
unset($GLOBALS['LRG_DLG_MCM']);
$GLOBALS['LRG_TEST_NOW'] = 1700000000;

// --- (i) [pt17-replies] a CARRIED ask must never make an unrelated line answerless. Captain Aldis, 2026-09-23
// 20:10:31: twelve seconds after "i would like to join the legion" the player said "it says it's locked" (the Castle
// Dour door); the carried <locked_facts> block was byte-identical to the ask turn's - all prohibition - and the model
// returned a schema-valid reply whose message was "" (research/pt17-replies.md). The floor for that empty reply is
// lib/lrg_replies.php; THIS half is the wording: on a carried LIVE turn the fact is marked background and the rule
// says his words now may be about something else - answer them, in words. A rechat carry keeps the plain wording.
$GLOBALS['LRG_DLG_MCM'] = ['lf' => 1, 'ml' => 0];
$tI1 = $ft();
$tI1['faction'] = lrgFacTurn($tI1, 'sorry i meant to say i would like to join the legion', true);   // the ask, stored at NOW
$lI1 = lrgFacLockedLines($tI1);
chk('(i) the ASK turn: the first line is the plain world fact, no background mark',
    str_starts_with($lI1[0] ?? '', 'of this world, not of this moment: '), $lI1[0] ?? '');
$rI1 = lrgFacRule($tI1);
chk('(i) ...and its rule keeps the no-invented-next-step rule and ends "Answer what he says, in words."',
    str_contains($rI1, 'Never invent an appointment') && str_ends_with(trim($rI1), 'Answer what he says, in words.'), $rI1);
chk('(i) ...without the carried clause', !str_contains($rI1, 'something else'), $rI1);
chk('(i) ...and the ask-turn block stays under 13(c)\'s 900-char bound (the clause is 31 characters on purpose)',
    strlen(lrgDlgLockedBlock($tI1 + ['locked' => lrgDlgLockedFacts($tI1)])) < 900, (string) strlen(lrgDlgLockedBlock($tI1 + ['locked' => lrgDlgLockedFacts($tI1)])));
$GLOBALS['LRG_TEST_NOW'] = 1700000012;
$tI2 = $ft(['cid' => 'f2']);
$tI2['faction'] = lrgFacTurn($tI2, "i didn't uh it says it's locked", true);
chk('(i) twelve seconds later, an aside about the locked door: the ask is CARRIED on a live turn',
    !empty($tI2['faction']['carried']) && ($tI2['faction']['asked'] ?? '') === 'legion', json_encode($tI2['faction']));
$lI2 = lrgFacLockedLines($tI2);
chk('(i) ...its first line is marked background to what he asked a moment ago, not what he is saying now',
    str_starts_with($lI2[0] ?? '', '(background to what he asked a moment ago, not what he is saying now) of this world, not of this moment: '), $lI2[0] ?? '');
chk('(i) ...and still carries the fact itself (Tullius, Rikke, Castle Dour, never through you)',
    str_contains($lI2[0] ?? '', 'General Tullius') && str_contains($lI2[0] ?? '', 'Castle Dour') && str_contains($lI2[0] ?? '', 'never through you'), $lI2[0] ?? '');
$rI2 = lrgFacRule($tI2);
chk('(i) ...the rule says his words now may be about something else - answer them, in words; the enlistment is background only',
    str_contains($rI2, 'may be about something else') && str_contains($rI2, 'answer them, in words') && str_contains($rI2, 'background only'), $rI2);
chk('(i) ...and keeps the no-invented-next-step rule (the carried clause replaces the short ending, not the rule)',
    str_contains($rI2, 'Never invent an appointment') && str_contains($rI2, 'or the game told you.') && str_ends_with(trim($rI2), 'background only.'), $rI2);
chk('(i) no angle bracket and no quoted example line in either (rules only, section 0)', !preg_match('/[<>"]/', ($lI2[0] ?? '') . $rI2), ($lI2[0] ?? '') . $rI2);
$bI2 = lrgDlgLockedBlock($tI2 + ['locked' => lrgDlgLockedFacts($tI2)]);
chk('(i) the whole carried block carries both and still fits (the rule sits outside the 600-char body cap)',
    str_contains($bI2, '(background to what he asked') && str_contains($bI2, 'answer them, in words') && strlen($bI2) < 1050, (string) strlen($bI2));
chk('(i) the turn line is unchanged: faction=legion:redirect:carried', str_contains(lrgDlgTurnLine($tI2), ' faction=legion:redirect:carried'), lrgDlgTurnLine($tI2));
// the rechat carry (the NPC continuing after an audience line, no player words): the ask is still the subject
$tI3 = $ft(['type' => 'rechat', 'speech' => false, 'talk' => false]);
$tI3['faction'] = lrgFacTurn($tI3, '', false);
$lI3 = lrgFacLockedLines($tI3);
chk('(i) a RECHAT carry keeps the plain wording (there are no player words to answer)',
    !empty($tI3['faction']['carried']) && str_starts_with($lI3[0] ?? '', 'of this world, not of this moment: '), $lI3[0] ?? '');
chk('(i) ...and its rule has no carried clause, just the short "Answer what he says, in words." ending',
    !str_contains(lrgFacRule($tI3), 'something else') && str_ends_with(trim(lrgFacRule($tI3)), 'Answer what he says, in words.'), lrgFacRule($tI3));
// a recruiter's carried line gets the same mark, and her ml=0 sentence is still there
$GLOBALS['LRG_TEST_NOW'] = 1700000000;
lrgFacTurn($tR, 'I want to join the Legion', true);
$GLOBALS['LRG_TEST_NOW'] = 1700000030;
$tI4 = $ft(['npc' => 'Legate Rikke', 'snap' => [], 'q' => []]);
$tI4['faction'] = lrgFacTurn($tI4, 'what a view from up here', true);
$lI4 = lrgFacLockedLines($tI4);
chk('(i) a recruiter (Rikke) 30 s later: carried, marked background, and her ml=0 line still says enlistment is settled in her own dialogue',
    !empty($tI4['faction']['carried']) && str_starts_with($lI4[0] ?? '', '(background to what he asked') && str_contains($lI4[0] ?? '', 'own dialogue'), json_encode($lI4));
// the two switches, and their defaults
$GLOBALS['LRG_FAC_TEST_OVERRIDE'] = ['carried_note' => false, 'answer_in_words' => false];
chk('(i) factions.carried_note=false: the carried line is plain again',
    str_starts_with(lrgFacLockedLines($tI2)[0] ?? '', 'of this world, not of this moment: '), lrgFacLockedLines($tI2)[0] ?? '');
chk('(i) factions.answer_in_words=false: the rule is the pt16 rule alone',
    !str_contains(lrgFacRule($tI2), 'in words') && str_ends_with(trim(lrgFacRule($tI2)), 'or the game told you.'), lrgFacRule($tI2));
unset($GLOBALS['LRG_FAC_TEST_OVERRIDE']);
lrgFacCfg();
chk('(i) ...both default ON', !empty(lrgFacCfg('carried_note')) && !empty(lrgFacCfg('answer_in_words')));
unset($GLOBALS['LRG_DLG_MCM']);

// --- (j) [pt17-replies, lib/lrg_replies.php] the prompt-side prevention in lrg_dialogue.php: the never-empty clause on
// message's DESCRIPTION (never a schema keyword), the <business> wording, the reorder scope, the next-turn rule
require_once $root . '/server/lorerim_glue/lib/lrg_replies.php';
$GLOBALS['LRG_TEST_NOW'] = 1700000000;
$GLOBALS['LRG_DLG_STATE'] = [];
$schemaJ = static function (): void {
    $keys = ['character', 'listener', 'message', 'mood', 'action', 'target', 'item', 'amount'];
    $props = [];
    foreach ($keys as $k) { $props[$k] = ['type' => $k === 'amount' ? 'integer' : 'string', 'description' => $k === 'message' ? 'lines of dialogue' : $k]; }
    $GLOBALS['structuredOutputTemplate'] = ['type' => 'json_schema', 'json_schema' => ['name' => 'response', 'strict' => true,
        'schema' => ['type' => 'object', 'properties' => $props, 'required' => $keys, 'additionalProperties' => false]]];
    $GLOBALS['responseTemplate'] = ['character' => 'x', 'listener' => 'x', 'message' => 'lines of dialogue', 'mood' => 'x', 'action' => 'x', 'target' => 'x', 'item' => 'x', 'amount' => 0];
};
$GLOBALS['LRG_DLG_TURN'] = $ft(['cid' => 'j1']);   // the Aldis shape: a speech turn, module on, no list, no business at all
$schemaJ();
lrgDlgJsonTemplate();
$pj = (array) $GLOBALS['structuredOutputTemplate']['json_schema']['schema']['properties'];
chk('(j) on a reordered speech turn the message description ends with the never-empty clause',
    str_ends_with((string) ($pj['message']['description'] ?? ''), 'Never empty: at least one short spoken sentence, even when the action says it all.'), (string) ($pj['message']['description'] ?? ''));
// [pt19 v1.0 / S11, Lane B] reorder_json_scope ships as 'business': the Aldis turn carries no business, so message keeps its place
chk('(j) ...a turn with no business keeps the character-first order (reorder_json_scope=business, spec S9 / S11)', array_slice(array_keys($pj), 0, 3) === ['character', 'listener', 'message'], json_encode(array_keys($pj)));
$GLOBALS['LRG_DLG_TURN'] = $ft(['cid' => 'j1b', 'entries' => [['text' => 'x', 'norm' => 'x', 'kind' => '', 'cost' => 0]]]);
$schemaJ();
lrgDlgJsonTemplate();
$pjb = (array) $GLOBALS['structuredOutputTemplate']['json_schema']['schema']['properties'];
chk('(j) ...and a business turn (a list) still puts action / target / item first (D3)', array_slice(array_keys($pjb), 0, 3) === ['action', 'target', 'item'], json_encode(array_keys($pjb)));
chk('(j) ...and nothing but type + description on message: no minLength, no keyword a strict mode would 400 on', array_keys((array) $pj['message']) === ['type', 'description'], json_encode($pj['message']));
chk('(j) ...the text template carries the clause too', str_contains((string) $GLOBALS['responseTemplate']['message'], 'Never empty'), (string) $GLOBALS['responseTemplate']['message']);
chk('(j) ...and no example line in it (section 0)', !str_contains(lrgDlgNeverEmptyClause(), '"'));
chk('(j) the Aldis turn carries no business for the action-first order (list=none, only a carried ask)', !lrgDlgTurnHasBusiness($ft(['faction' => ['asked' => 'legion', 'join' => 1, 'role' => 'redirect', 'carried' => 1]])));
chk('(j) ...a fresh enlistment ask, a list, a service, a follower verb or an arrest does',
    lrgDlgTurnHasBusiness($ft(['faction' => ['asked' => 'legion', 'join' => 1, 'role' => 'redirect', 'carried' => 0]])) && lrgDlgTurnHasBusiness($ft(['entries' => [$ajo]]))
    && lrgDlgTurnHasBusiness($ft(['svc' => ['kind' => 'barter', 'direct' => 1]])) && lrgDlgTurnHasBusiness($ft(['fol' => ['asked' => 'recruit']])) && lrgDlgTurnHasBusiness($ft(['arrest' => 'lethal'])));
chk('(j) reorder_json_scope defaults to business (spec S9 / S11: empty replies <= 3 %)', (string) lrgDlgCfg('reorder_json_scope') === 'business'
    && (string) (lrgDlgDefaults()['reorder_json_scope'] ?? '') === 'business');
$tJ = $ft(['entries' => [$ajo], 'n' => 1, 'sent' => 1]);
$bJ = lrgDlgBusinessBlock($tJ);
chk('(j) the <business> block no longer asks for an empty message (request 706: a parked [commits] pick lost her question)',
    !str_contains($bJ, 'With the action leave message empty') && str_contains($bJ, 'never leave message empty') && str_contains($bJ, 'not played'), fx_cut($bJ));
// the next-turn rule rides Phase 2's volatile guidance, once, within note_seconds. This file runs with no database;
// lrg_memory gets a tiny in-memory stand-in for these three checks only (the same shape test_gates' FakeDb has).
$GLOBALS['gameRequest'] = ['inputtext', 1700000000, 100000, 'well?'];
$GLOBALS['db'] = new class {
    public array $t = [];
    public function upsertRowOnConflict($table, $data, $key) { $this->t[$table][$data[$key]] = $data; return true; }
    public function escapeLiteral($s) { return "'" . str_replace("'", "''", (string) $s) . "'"; }
    public function execQuery($q) { return true; }
    public function fetchOne($q) {
        if (!preg_match('/FROM (\w+)/', (string) $q, $m) || !preg_match("/npc_name='((?:[^']|'')*)'/", (string) $q, $n)) { return []; }
        return $this->t[$m[1]][str_replace("''", "'", $n[1])] ?? [];
    }
    public function fetchAll($q) { return []; }
    public function __call($name, $args) { return []; }
};
lrgMemSet('Captain Aldis', ['empty_reply' => ['at' => 1700000000 - 30, 'cid' => 'x', 'n' => 1, 'why' => 'empty message', 'line' => 'x', 'i' => 0, 'kind' => 'statement', 'told' => false]]);
$vJ = lrgDlgVolatileGuidance($ft(['cid' => 'j2']));
chk('(j) 30 s after an empty reply the volatile guidance carries the rule once', str_contains($vJ, "Captain Aldis's last reply carried no words at all") && str_contains($vJ, 'answers in words'), fx_cut($vJ));
chk('(j) ...and not a second time', !str_contains(lrgDlgVolatileGuidance($ft(['cid' => 'j3'])), 'carried no words'));
lrgMemSet('Captain Aldis', ['empty_reply' => ['at' => 1700000000 - 500, 'cid' => 'x', 'n' => 1, 'told' => false]]);
chk('(j) ...nor after note_seconds', !str_contains(lrgDlgVolatileGuidance($ft(['cid' => 'j4'])), 'carried no words'));
unset($GLOBALS['db'], $GLOBALS['LRG_DLG_TURN'], $GLOBALS['structuredOutputTemplate'], $GLOBALS['responseTemplate']);
$GLOBALS['LRG_DLG_STATE'] = [];
$GLOBALS['LRG_TEST_NOW'] = 1700000000;

// --- (k) [pt18-words] the recruiter's ml=0 line. General Tullius, 2026-09-23 23:19: the old line said "enlistment is
// settled in your own dialogue with him, not in this talk (the glue is still learning this install's dialogue menu (0 of
// 10 conversations measured))" - from the model's seat it IS in dialogue with him, the meta text leaked into the prompt,
// and grok-4.3 enacted the oath. Now the line carries the FACT (the game records an enlistment only through his real
// entry in the dialogue menu, and has recorded none), the plain sentence to say, and the concrete prohibitions; the
// learning counter stays in the log. The executed line and clause (the quest lane's flag, PROTOCOL 10.22 / 10.25) and
// the bounds. The never-false rail that judges the words themselves is lib/lrg_replies.php (test_gates 36 (n)-(x)).
$GLOBALS['LRG_DLG_MCM'] = ['lf' => 1, 'ml' => 0, 'cal' => 0];
$tK = $ft(['npc' => 'General Tullius', 'snap' => [], 'q' => ['CW00A'], 'facts' => ['qst' => ['CW00A' => 0]]]);
$tK['faction'] = lrgFacTurn($tK, "okay i guess you're the guy i talked to to join the legion", true);
$lK = lrgFacLockedLines($tK);
chk('(k) Tullius under ml=0: recruiter, one line, marked of this world', ($tK['faction']['role'] ?? '') === 'recruiter' && count($lK) === 1
    && str_starts_with($lK[0] ?? '', 'of this world, not of this moment: you are one of those who take recruits for the Imperial Legion'), json_encode($lK));
chk('(k) ...it says the game records an enlistment only through his real entry in the dialogue menu, and has recorded none',
    str_contains($lK[0] ?? '', 'the game records an enlistment only when he takes your real entry in your own dialogue menu, and it has recorded none'), $lK[0] ?? '');
chk('(k) ...names the concrete prohibitions: swear him in, his oath, a rank, orders, a place, a time, a drill, a test',
    str_contains($lK[0] ?? '', 'never swear him in, take his oath, give him a rank, orders, a place, a time, a drill or a test'), $lK[0] ?? '');
chk('(k) ...keeps "nothing has begun", "tell him so plainly" and "the proper way"',
    str_contains($lK[0] ?? '', 'nothing has begun') && str_contains($lK[0] ?? '', 'tell him so plainly') && str_contains($lK[0] ?? '', 'the proper way'), $lK[0] ?? '');
chk('(k) ...and carries none of the glue\'s meta text (still learning / of 10 / not in this talk / dry run / menuless)',
    !preg_match('/still learning|of 10|not in this talk|dry run|menuless/i', $lK[0] ?? ''), $lK[0] ?? '');
chk('(k) ...bracket-free, no double quote, and short enough for the quest line to follow it under the 600-char body cap',
    !preg_match('/["<>]/', $lK[0] ?? '') && strlen($lK[0] ?? '') < 560, (string) strlen($lK[0] ?? ''));
$fK = lrgDlgLockedFacts($tK);
$bK = lrgDlgLockedBlock($tK + ['locked' => $fK]);
chk('(k) the whole Tullius block: the faction line first, the rule after it, within the caps (header + 600-char body + the rule)',
    (string) ($fK[0]['class'] ?? '') === 'faction' && str_contains($bK, 'Never invent an appointment') && strlen($bK) < 1150, (string) strlen($bK));
// the executed line: the quest lane queued CW00A stage 10 through the real entry THIS turn (set before call_llm)
$tX = $tK; $tX['faction']['executed'] = ['quest' => 'CW00A', 'stage' => 10, 'how' => 'pick', 'cid' => 'x'];
$lX = lrgFacLockedLines($tX);
chk('(k) executed CW00A:10: a second line says what the game has just recorded, what it means, and nothing beyond it',
    count($lX) === 2 && ($lX[1] ?? '') === 'the game has just recorded CW00A stage 10 - he is sent to Legate Rikke - you may say so, and nothing beyond it', json_encode($lX));
chk('(k) ...the rule gains the executed clause, and only then',
    str_contains(lrgFacRule($tX), 'What the game has just recorded (stated above) you may say - that, and nothing beyond it.') && !str_contains(lrgFacRule($tK), 'just recorded'), lrgFacRule($tX));
chk('(k) ...still ending "Answer what he says, in words."', str_ends_with(trim(lrgFacRule($tX)), 'Answer what he says, in words.'), lrgFacRule($tX));
$tX2 = $tK; $tX2['faction']['executed'] = ['quest' => 'CW00A', 'stage' => 7, 'how' => 'open', 'cid' => 'x'];
chk('(k) a stage the table has no words for still gets its line, without a meaning',
    (lrgFacLockedLines($tX2)[1] ?? '') === 'the game has just recorded CW00A stage 7 - you may say so, and nothing beyond it', json_encode(lrgFacLockedLines($tX2)));
$tX3 = $tK; $tX3['faction']['executed'] = [];
chk('(k) an empty executed flag (lrgFacTurn initialises none) changes nothing', lrgFacLockedLines($tX3) === $lK && !str_contains(lrgFacRule($tX3), 'just recorded'));
// the redirect ask-turn block is untouched and still under 13(c)'s bound: the rule kept its length on purpose (it sits at 898)
$tAk = $ft();
$tAk['faction'] = lrgFacTurn($tAk, "that's where i come to start being an imperial i was looking to serve sir", true);
chk('(k) the Aldis ask-turn block stays under 900 chars (the rule did not grow)', strlen(lrgDlgLockedBlock($tAk + ['locked' => lrgDlgLockedFacts($tAk)])) < 900,
    (string) strlen(lrgDlgLockedBlock($tAk + ['locked' => lrgDlgLockedFacts($tAk)])));
chk('(k) the pt17 Rikke-before-Tullius line is unchanged (it was truthful and worked at 23:19:13)', (static function () use ($ft): bool {
    $t = $ft(['npc' => 'Legate Rikke', 'snap' => [], 'q' => ['CW00A'], 'facts' => ['qst' => ['CW00A' => 0]]]);
    $t['faction'] = lrgFacTurn($t, 'i want to join the legion', true);
    $l = lrgFacLockedLines($t)[0] ?? '';
    return str_contains($l, 'is not open before that') && str_contains($l, 'at the map table in Castle Dour');
})());
unset($GLOBALS['LRG_DLG_MCM']);
$GLOBALS['LRG_DLG_STATE'] = [];

// --- (l) [pt18-quest] THE ROAD AND THE CLICK-FREE ENTRY (research/pt18-quest.md, PROTOCOL 10.26). Tonight's turn shapes
// replayed with the truth the refuters established: on this Alternate Perspective save MQ101 (Helgen) is at stage 0,
// GLOB MQQuickstart is 7.0 and AP's Tullius greet 0D5146 needs < 7 - every join line of his is unreachable until Helgen.
// Nothing is sent on an unknown or closed road (fail closed); the Helgen line replaces the recruiter lines (Rikke's too);
// Rikke's 'Tullius first' is hedged while the road is unknown; after Helgen (MQ101 complete on a fresh facts line) the AP
// line's stage is queued through ExtCmdLRG_QuestEntry - LICENSED (executed set pre-LLM) only when every condition is on
// the facts line, else the game's own OK is voiced (test_gates 37). Rikke has no effect row: words only this round.
$GLOBALS['LRG_DLG_MCM'] = ['lf' => 1, 'ml' => 0, 'cal' => 0];
$GLOBALS['LRG_DLG_STATE'] = [];
$GLOBALS['LRG_TEST_NOW'] = 1700000000;
$tull = static function (array $facts, array $over = []) use ($ft): array {
    return $ft($over + ['npc' => 'General Tullius', 'cid' => 'q1', 'snap' => ['fac' => 'CWImperialFaction,CWFieldCOFaction', '_age' => 5],
        'q' => ['CW00A', 'CWObj', 'CWReservations', 'CW', 'aaThalmor', 'MQ101'], 'facts' => $facts, 'ambient' => 1]);
};
// (l1) tonight, 23:19:29: qst carries MQ101:0 -> the road is CLOSED, nothing queued, no executed flag, the Helgen line
$tL1 = $tull(['qst' => ['CW00A' => 0, 'CWObj' => 0, 'CWReservations' => 0, 'CW' => 0, 'aaThalmor' => 1, 'MQ101' => 0], 'at' => 1700000000 - 5]);
$tL1['faction'] = lrgFacTurn($tL1, "okay i guess you're the guy i talked to to join the legion", true);
chk('(l) 23:19:29 replayed: the road is CLOSED from his own facts line (MQ101:0)',
    ($tL1['faction']['road']['state'] ?? '') === 'closed' && str_contains((string) ($tL1['faction']['road']['src'] ?? ''), 'qst'), json_encode($tL1['faction']['road'] ?? null));
chk('(l) ...the plan says closed: nothing is queued, no param, no executed flag',
    ($tL1['faction']['plan']['state'] ?? '') === 'closed' && ($tL1['faction']['plan']['param'] ?? 'x') === '' && ($tL1['faction']['executed'] ?? ['x']) === [], json_encode($tL1['faction']['plan'] ?? null));
$lL1 = lrgFacLockedLines($tL1);
chk('(l) ...the ONE locked line is the Helgen line: the road runs through Helgen first, no line of his opens before it, nothing recorded',
    count($lL1) === 1 && str_contains($lL1[0] ?? '', "the Legion's road runs through Helgen first") && str_contains($lL1[0] ?? '', 'until Helgen is behind him')
    && str_contains($lL1[0] ?? '', 'it has recorded none'), json_encode($lL1));
chk('(l) ...it tells him plainly (Helgen first) and keeps every prohibition; no map table, no "settled in your own dialogue"',
    str_contains($lL1[0] ?? '', 'tell him so plainly in one line (Helgen first)')
    && str_contains($lL1[0] ?? '', 'never swear him in, take his oath, give him a rank, orders, a place, a time, a drill or a test')
    && !str_contains($lL1[0] ?? '', 'map table') && !str_contains($lL1[0] ?? '', 'own dialogue'), $lL1[0] ?? '');
chk('(l) ...in-world words only: no global, no stage number, no mod name, no quote or bracket, under the 560-char bound',
    !preg_match('/MQQuickstart|MQ101|stage \d|Alternate Perspective|["<>]/', $lL1[0] ?? '') && strlen($lL1[0] ?? '') < 560, strlen($lL1[0] ?? '') . ' ' . ($lL1[0] ?? ''));
$bL1 = lrgDlgLockedBlock($tL1 + ['locked' => lrgDlgLockedFacts($tL1)]);
chk('(l) ...the whole block carries it (the faction line survives the 600-char body cap) with the rule',
    str_contains($bL1, 'Helgen first') && str_contains($bL1, 'Never invent an appointment'), (string) strlen($bL1));
chk('(l) ...the turn line says faction=legion:recruiter:road=closed:qe=closed', str_contains(lrgDlgTurnLine($tL1), ' faction=legion:recruiter:road=closed:qe=closed'), lrgDlgTurnLine($tL1));
$GLOBALS['LRG_DLG_TEST_QUEUE'] = [];
$GLOBALS['LRG_DLG_TURN'] = $tL1;
unset($GLOBALS['LRG_FAC_QE_SENT']);
chk('(l) ...and the net appends nothing (no D1 line, no D2 row)', lrgFacQuestNet([]) === [] && $GLOBALS['LRG_DLG_TEST_QUEUE'] === []);
chk('(l) ...no open for awareness either, even under ml=1: the road is closed', (static function () use ($tL1): bool {
    $GLOBALS['LRG_DLG_MCM'] = ['lf' => 1, 'ml' => 1, 'io' => 1];
    $r = lrgFacRefusesOpen($tL1, 'q1', 'join the Legion');
    $GLOBALS['LRG_DLG_MCM'] = ['lf' => 1, 'ml' => 0, 'cal' => 0];
    return $r;
})());
// (l2) mqq=7 alone (the game's MQQuickstart key; MQ101 not on the sweep) closes the road too; (l3) mq101c=0 closes it
$tL2 = $tull(['qst' => ['CW00A' => 0], 'mqq' => 7, 'at' => 1700000000 - 5]);
$tL2['faction'] = lrgFacTurn($tL2, 'i want to join the legion', true);
chk('(l) mqq=7 with MQ101 absent: closed (src facts:mqq), the Helgen line',
    ($tL2['faction']['road']['state'] ?? '') === 'closed' && str_ends_with((string) ($tL2['faction']['road']['src'] ?? ''), ':mqq')
    && str_contains(lrgFacLockedLines($tL2)[0] ?? '', 'Helgen first'), json_encode($tL2['faction']['road'] ?? null));
$tL3 = $tull(['qst' => ['CW00A' => 0], 'mq101c' => 0, 'at' => 1700000000 - 5]);
$tL3['faction'] = lrgFacTurn($tL3, 'i want to join the legion', true);
chk('(l) mq101c=0 (the explicit completion key): closed (src facts:mq101c)',
    ($tL3['faction']['road']['state'] ?? '') === 'closed' && str_ends_with((string) ($tL3['faction']['road']['src'] ?? ''), ':mq101c'), json_encode($tL3['faction']['road'] ?? null));
// (l4) the carried oath turn 18 s later ("i swear to uphold the imperial vows"): the road still closed, no plan
$GLOBALS['LRG_TEST_NOW'] = 1700000018;
$tL4 = $tull(['qst' => ['CW00A' => 0, 'MQ101' => 0], 'at' => 1700000000 - 5], ['cid' => 'q2']);
$tL4['faction'] = lrgFacTurn($tL4, 'i swear to uphold the imperial vows', true);
chk('(l) the carried oath turn: carried, road closed, no plan, no executed',
    !empty($tL4['faction']['carried']) && ($tL4['faction']['road']['state'] ?? '') === 'closed' && ($tL4['faction']['plan'] ?? ['x']) === []
    && ($tL4['faction']['executed'] ?? ['x']) === [], json_encode($tL4['faction']));
chk('(l) ...its line is the Helgen line, marked background (a carried live turn)',
    str_starts_with(lrgFacLockedLines($tL4)[0] ?? '', '(background to what he asked a moment ago') && str_contains(lrgFacLockedLines($tL4)[0] ?? '', 'Helgen first'),
    json_encode(lrgFacLockedLines($tL4)));
$GLOBALS['LRG_TEST_NOW'] = 1700000000;
// (l5) Rikke: her own facts line has no MQ101 (the sweep is capped at 6) - Tullius's cached line (quest stages are global) closes hers
$GLOBALS['LRG_DLG_STATE'] = [];
lrgDlgPut('General Tullius', ['facts' => ['qst' => ['CW00A' => 0, 'MQ101' => 0], 'at' => 1700000000 - 11]]);
$tR5 = $ft(['npc' => 'Legate Rikke', 'cid' => 'q3', 'snap' => ['fac' => 'CWImperialFaction,CWFieldCOFaction'], 'q' => ['CW00A'],
    'facts' => ['qst' => ['CW00A' => 0], 'at' => 1700000000 - 5], 'ambient' => 1]);
$tR5['faction'] = lrgFacTurn($tR5, "hi um i'm looking to join the legion", true);
chk('(l) Rikke with Tullius\'s facts line cached: her road is closed through the cache',
    ($tR5['faction']['road']['state'] ?? '') === 'closed' && str_starts_with((string) ($tR5['faction']['road']['src'] ?? ''), 'cache:General Tullius'), json_encode($tR5['faction']['road'] ?? null));
$lR5 = lrgFacLockedLines($tR5);
chk('(l) ...and she names Helgen - not "General Tullius first, at the map table"',
    str_contains($lR5[0] ?? '', 'Helgen first') && str_contains($lR5[0] ?? '', 'or of General Tullius until Helgen') && !str_contains($lR5[0] ?? '', 'at the map table'), json_encode($lR5));
chk('(l) ...Rikke has no effect row: no plan at all (words only this round)', ($tR5['faction']['plan'] ?? ['x']) === [] && lrgFacCfg('rows.legion.effects.Legate Rikke') === null);
// (l6) Rikke with NOTHING known (23:19:13 itself, before any Tullius line): the road is unknown - the pt17 line is hedged, never asserted open
$GLOBALS['LRG_DLG_STATE'] = [];
$tR6 = $ft(['npc' => 'Legate Rikke', 'cid' => 'q4', 'snap' => [], 'q' => ['CW00A'], 'facts' => ['qst' => ['CW00A' => 0]]]);
$tR6['faction'] = lrgFacTurn($tR6, 'i want to join the legion', true);
$lR6 = lrgFacLockedLines($tR6);
chk('(l) Rikke, road unknown: the before line keeps "General Tullius first" but adds that Helgen comes before all of it and the game has not confirmed it',
    ($tR6['faction']['road']['state'] ?? '') === 'unknown' && str_contains($lR6[0] ?? '', 'General Tullius first, at the map table in Castle Dour')
    && str_contains($lR6[0] ?? '', 'Helgen comes before all of it') && str_contains($lR6[0] ?? '', 'has not told you whether that is behind him'), json_encode($lR6));
chk('(l) ...the turn line carries road=unknown', str_contains(lrgDlgTurnLine($tR6), ' faction=legion:recruiter:road=unknown'), lrgDlgTurnLine($tR6));
// (l7) Rikke after Helgen (mq101c=1) and before Tullius: the plain pt17 line, unhedged
$tR7 = $ft(['npc' => 'Legate Rikke', 'cid' => 'q5', 'snap' => [], 'q' => ['CW00A'], 'facts' => ['qst' => ['CW00A' => 0], 'mq101c' => 1, 'at' => 1700000000 - 5]]);
$tR7['faction'] = lrgFacTurn($tR7, 'i want to join the legion', true);
chk('(l) Rikke after Helgen, before Tullius: "General Tullius first, at the map table" plain, no hedge',
    ($tR7['faction']['road']['state'] ?? '') === 'open' && str_contains(lrgFacLockedLines($tR7)[0] ?? '', 'at the map table in Castle Dour;')
    && !str_contains(lrgFacLockedLines($tR7)[0] ?? '', 'Helgen'), json_encode(lrgFacLockedLines($tR7)));
// (l8) Tullius after Helgen: MQ101 complete on a fresh facts line, CW00A at 0, ml=0 -> QUEUED, unlicensed (CW00B is not on the facts line)
$GLOBALS['LRG_DLG_STATE'] = [];
$GLOBALS['LRG_DLG_TEST_QUEUE'] = [];
$tL8 = $tull(['qst' => ['CW00A' => 0, 'CW' => 0], 'mq101c' => 1, 'at' => 1700000000 - 5], ['cid' => 'q8']);
$tL8['faction'] = lrgFacTurn($tL8, 'i want to join the legion', true);
$p8 = (array) ($tL8['faction']['plan'] ?? []);
chk('(l) after Helgen (mq101c=1, fresh), ml=0: the plan is QUEUED, unlicensed (CW00B not on the facts line)',
    ($p8['state'] ?? '') === 'queued' && (int) ($p8['licensed'] ?? 1) === 0 && ($p8['quest'] ?? '') === 'CW00A' && (int) ($p8['stage'] ?? 0) === 10, json_encode($p8));
chk('(l) ...no executed flag without a licence', ($tL8['faction']['executed'] ?? ['x']) === []);
chk('(l) ...the param: ok=1;cid;npc;quest=CW00A;stage=10;isid=78462;notdone=10,20;max=9;qdone=MQ101;qnd=CW00B:10;entry=..;hint=..;x=..;z=1',
    (bool) preg_match('/^ok=1;cid=q8;npc=General Tullius;quest=CW00A;stage=10;isid=78462;notdone=10,20;max=9;qdone=MQ101;qnd=CW00B:10;entry=CW00TulliusGreetTalkRikkateAP;hint=[^;]+;x=[0-9a-f]{10};z=1$/', (string) ($p8['param'] ?? '')),
    (string) ($p8['param'] ?? ''));
$lL8 = lrgFacLockedLines($tL8);
chk('(l) ...the locked line: the game is being asked this moment, has NOT confirmed it, do not say it is done - under the bound',
    count($lL8) === 1 && str_contains($lL8[0] ?? '', 'is being asked this moment to record CW00A stage 10') && str_contains($lL8[0] ?? '', 'has NOT confirmed it yet')
    && str_contains($lL8[0] ?? '', "the game's own answer follows") && strlen($lL8[0] ?? '') < 560, strlen($lL8[0] ?? '') . ' ' . json_encode($lL8));
chk('(l) ...the turn line: qe=queued, no licence, no executed', str_contains(lrgDlgTurnLine($tL8), ':qe=queued') && !str_contains(lrgDlgTurnLine($tL8), 'licensed')
    && !str_contains(lrgDlgTurnLine($tL8), 'executed='), lrgDlgTurnLine($tL8));
$GLOBALS['LRG_DLG_TURN'] = $tL8;
unset($GLOBALS['LRG_FAC_QE_SENT']);
$net8 = lrgFacQuestNet(['General Tullius|command|Talk@x' . "\r\n"]);
chk('(l) ...the net appends ONE ExtCmdLRG_QuestEntry line after the model\'s own - D1 only, no D2 row',
    count($net8) === 2 && str_starts_with((string) ($net8[1] ?? ''), 'General Tullius|command|ExtCmdLRG_QuestEntry@ok=1;cid=q8;') && str_ends_with((string) ($net8[1] ?? ''), "\r\n")
    && $GLOBALS['LRG_DLG_TEST_QUEUE'] === [], json_encode($net8));
chk('(l) ...once per request', count(lrgFacQuestNet($net8)) === 2);
$fx8 = (array) (lrgDlgState('General Tullius')['facexec'] ?? []);
chk('(l) ...facexec records it (pending, unlicensed, this cid)', ($fx8['quest'] ?? '') === 'CW00A' && (int) ($fx8['stage'] ?? 0) === 10 && (int) ($fx8['done'] ?? 1) === 0
    && (int) ($fx8['lic'] ?? 1) === 0 && ($fx8['cid'] ?? '') === 'q8', json_encode($fx8));
chk('(l) ...and no open beside a queued entry', (static function () use ($tL8): bool {
    $GLOBALS['LRG_DLG_MCM'] = ['lf' => 1, 'ml' => 1, 'io' => 1];
    $r = lrgFacRefusesOpen($tL8, 'q8', 'join the Legion');
    $GLOBALS['LRG_DLG_MCM'] = ['lf' => 1, 'ml' => 0, 'cal' => 0];
    return $r;
})());
// (l9) a second ask 30 s later, no answer yet: PENDING, not sent again; (l10) the OK came back (exec_qst): ALREADY
$GLOBALS['LRG_TEST_NOW'] = 1700000030;
$tL9 = $tull(['qst' => ['CW00A' => 0, 'CW' => 0], 'mq101c' => 1, 'at' => 1700000000 - 5], ['cid' => 'q9']);
$tL9['faction'] = lrgFacTurn($tL9, 'so can i join the legion or not', true);
chk('(l) a second ask 30 s later with no answer yet: pending - the line says it is being seen to, nothing sent again',
    ($tL9['faction']['plan']['state'] ?? '') === 'pending' && str_contains(lrgFacLockedLines($tL9)[0] ?? '', 'was asked a moment ago to record CW00A stage 10'),
    json_encode([$tL9['faction']['plan']['state'] ?? null, lrgFacLockedLines($tL9)]));
$GLOBALS['LRG_DLG_TURN'] = $tL9;
unset($GLOBALS['LRG_FAC_QE_SENT']);
chk('(l) ...and the net appends nothing on a pending plan', lrgFacQuestNet([]) === []);
lrgDlgPut('General Tullius', ['exec_qst' => ['quest' => 'CW00A', 'stage' => 10, 'at' => 1700000020, 'cid' => 'q8'],
    'facexec' => ['quest' => 'CW00A', 'stage' => 10, 'at' => 1700000000, 'cid' => 'q8', 'done' => 1, 'lic' => 0]]);
$tL10 = $tull(['qst' => ['CW00A' => 0, 'CW' => 0], 'mq101c' => 1, 'at' => 1700000000 - 5], ['cid' => 'q10']);
$tL10['faction'] = lrgFacTurn($tL10, 'i want to join the legion', true);
chk('(l) after the game\'s OK (exec_qst fresher than the facts line): already - nothing sent, "the game has already recorded CW00A stage 10"',
    ($tL10['faction']['plan']['state'] ?? '') === 'already' && ($tL10['faction']['plan']['param'] ?? 'x') === ''
    && str_contains(lrgFacLockedLines($tL10)[0] ?? '', 'has already recorded CW00A stage 10') && str_contains(lrgFacLockedLines($tL10)[0] ?? '', 'sent to Legate Rikke'),
    json_encode(lrgFacLockedLines($tL10)));
$GLOBALS['LRG_DLG_STATE'] = [];
$tL11 = $tull(['qst' => ['CW00A' => 10], 'mq101c' => 1, 'at' => 1700000000 - 5], ['cid' => 'q11']);
$tL11['faction'] = lrgFacTurn($tL11, 'i want to join the legion', true);
chk('(l) qst CW00A:10 on the facts line itself: already, no command', ($tL11['faction']['plan']['state'] ?? '') === 'already' && ($tL11['faction']['plan']['param'] ?? 'x') === '');
chk('(l) ...at stage 10 exactly the line keeps the stage-10 meaning (he is sent to Legate Rikke)',
    (int) ($tL11['faction']['plan']['cur'] ?? -1) === 10 && str_contains(lrgFacLockedLines($tL11)[0] ?? '', 'has already recorded CW00A stage 10')
    && str_contains(lrgFacLockedLines($tL11)[0] ?? '', 'sent to Legate Rikke'), json_encode(lrgFacLockedLines($tL11)));
// [pt18-quest review] PAST the stage (CW00A 20: Rikke's test given): still 'already' and nothing sent, but the line must not
// re-tell the stage-10 meaning - the step is behind him
$tL11b = $tull(['qst' => ['CW00A' => 20], 'mq101c' => 1, 'at' => 1700000000 - 5], ['cid' => 'q11b']);
$tL11b['faction'] = lrgFacTurn($tL11b, 'i want to join the legion', true);
chk('(l) qst CW00A:20 (past the stage): already, nothing sent, the line says "past stage 10 ... behind him" and NOT "sent to Legate Rikke"',
    ($tL11b['faction']['plan']['state'] ?? '') === 'already' && ($tL11b['faction']['plan']['param'] ?? 'x') === '' && (int) ($tL11b['faction']['plan']['cur'] ?? -1) === 20
    && str_contains(lrgFacLockedLines($tL11b)[0] ?? '', 'past stage 10') && str_contains(lrgFacLockedLines($tL11b)[0] ?? '', 'that step is behind him')
    && !str_contains(lrgFacLockedLines($tL11b)[0] ?? '', 'sent to Legate Rikke') && strlen(lrgFacLockedLines($tL11b)[0] ?? '') < 560,
    json_encode(lrgFacLockedLines($tL11b)));
$GLOBALS['LRG_TEST_NOW'] = 1700000000;
// (l12) LICENSED: CW00B on the facts line below 10 -> executed is set pre-LLM (the words lane's exact shape); the OK will stay quiet
$GLOBALS['LRG_DLG_STATE'] = [];
$tL12 = $tull(['qst' => ['CW00A' => 0, 'CW00B' => 0], 'mq101c' => 1, 'at' => 1700000000 - 5], ['cid' => 'q12']);
$tL12['faction'] = lrgFacTurn($tL12, 'i want to join the legion', true);
chk('(l) every condition on the facts line (CW00B:0 too): queued AND licensed',
    ($tL12['faction']['plan']['state'] ?? '') === 'queued' && (int) ($tL12['faction']['plan']['licensed'] ?? 0) === 1, json_encode($tL12['faction']['plan'] ?? null));
chk('(l) ...executed = {quest CW00A, stage 10, how entry, cid q12} - the words lane\'s exact shape',
    ($tL12['faction']['executed'] ?? []) === ['quest' => 'CW00A', 'stage' => 10, 'how' => 'entry', 'cid' => 'q12'], json_encode($tL12['faction']['executed'] ?? null));
$lL12 = lrgFacLockedLines($tL12);
chk('(l) ...two lines: the frame, then the words lane\'s "the game has just recorded CW00A stage 10 - he is sent to Legate Rikke"',
    count($lL12) === 2 && str_contains($lL12[0] ?? '', 'the game answers it this moment') && str_contains($lL12[1] ?? '', 'the game has just recorded CW00A stage 10')
    && str_contains($lL12[1] ?? '', 'sent to Legate Rikke'), json_encode($lL12));
chk('(l) ...the rule gains the executed clause; the turn line says qe=queued:licensed:executed=CW00A:10',
    str_contains(lrgFacRule($tL12), 'What the game has just recorded') && str_contains(lrgDlgTurnLine($tL12), ':qe=queued:licensed:executed=CW00A:10'), lrgDlgTurnLine($tL12));
// (l13) CW00B:10 done on the facts line: closed-intro; (l14) stale facts; (l15) no facts at all
$tL13 = $tull(['qst' => ['CW00A' => 0, 'CW00B' => 10], 'mq101c' => 1, 'at' => 1700000000 - 5], ['cid' => 'q13']);
$tL13['faction'] = lrgFacTurn($tL13, 'i want to join the legion', true);
chk('(l) CW00B:10 done (the other side\'s introduction): closed-intro, nothing sent', ($tL13['faction']['plan']['state'] ?? '') === 'closed-intro' && ($tL13['faction']['plan']['param'] ?? 'x') === '');
$tL14 = $tull(['qst' => ['CW00A' => 0], 'mq101c' => 1, 'at' => 1700000000 - 900], ['cid' => 'q14']);
$tL14['faction'] = lrgFacTurn($tL14, 'i want to join the legion', true);
chk('(l) a facts line 900 s old: stale - nothing sent, the ordinary ml=0 line (it claims nothing about the road)',
    ($tL14['faction']['plan']['state'] ?? '') === 'stale' && ($tL14['faction']['plan']['param'] ?? 'x') === '' && str_contains(lrgFacLockedLines($tL14)[0] ?? '', 'own dialogue menu'),
    json_encode([$tL14['faction']['plan']['state'] ?? null, lrgFacLockedLines($tL14)]));
$tL15 = $tull([], ['cid' => 'q15']);
$tL15['faction'] = lrgFacTurn($tL15, 'i want to join the legion', true);
chk('(l) no facts at all: road unknown, plan unknown, nothing sent, no executed',
    ($tL15['faction']['road']['state'] ?? '') === 'unknown' && ($tL15['faction']['plan']['state'] ?? '') === 'unknown'
    && ($tL15['faction']['executed'] ?? ['x']) === [] && ($tL15['faction']['plan']['param'] ?? 'x') === '');
// (l16) ml=1, NOT ambient, the AP entry on his list: the real click wins; (l17) ml=1 on an AMBIENT actor: the click-free path is the only path
$GLOBALS['LRG_DLG_MCM'] = ['lf' => 1, 'ml' => 1, 'cal' => 10];
$apEntry = $fe('CW00TulliusGreetTalkRikkateAP', "I don't want to sit idly by after what I've witnessed. I want to join the Legion", 0);
$tL16 = $tull(['qst' => ['CW00A' => 0, 'CW00B' => 0], 'mq101c' => 1, 'at' => 1700000000 - 5], ['cid' => 'q16', 'ambient' => 0, 'entries' => [$apEntry], 'list' => 'root', 'n' => 1]);
$tL16['faction'] = lrgFacTurn($tL16, 'i want to join the legion', true);
chk('(l) ml=1, no ambient scene, the AP entry on his list: click-wins - no plan, no executed', ($tL16['faction']['plan'] ?? ['x']) === [] && ($tL16['faction']['executed'] ?? ['x']) === []);
$out16 = lrgFacArbitrate($tL16, [$apEntry], 'join the Legion', 'q16');
chk('(l) ...and lrgFacArbitrate picks that entry (the real click, every rail applies)',
    is_array($out16) && is_array($out16['entry']) && (string) $out16['entry']['topic'] === 'CW00TulliusGreetTalkRikkateAP', json_encode($out16));
$tL17 = $tull(['qst' => ['CW00A' => 0, 'CW00B' => 0], 'mq101c' => 1, 'at' => 1700000000 - 5], ['cid' => 'q17', 'ambient' => 1]);
$tL17['faction'] = lrgFacTurn($tL17, 'i want to join the legion', true);
// [pt19 v1.0 / S2.3, Lane B] the open comes FIRST on an ambient actor the game may open on; the click-free entry only when it cannot
chk('(l) ml=1 on an ambient map-table actor (no list, never opened), the open possible: open-first - nothing is sent (S2.3)',
    ($tL17['faction']['plan']['state'] ?? '') === 'open-first' && ($tL17['faction']['executed'] ?? ['x']) === [] && (string) ($tL17['faction']['plan']['param'] ?? 'x') === '',
    json_encode($tL17['faction']['plan'] ?? null));
lrgDlgPut((string) $tL17['npc'], ['open_refused' => ['why' => 'a quest scene is running', 'at' => 1700000000 - 10]]);
$tL17b = $tull(['qst' => ['CW00A' => 0, 'CW00B' => 0], 'mq101c' => 1, 'at' => 1700000000 - 5], ['cid' => 'q17b', 'ambient' => 1]);
$tL17b['faction'] = lrgFacTurn($tL17b, 'i want to join the legion', true);
chk('(l) ...the game refused the last open for her: queued - 10.26 is the fallback (S2.3)',
    ($tL17b['faction']['plan']['state'] ?? '') === 'queued' && str_contains((string) ($tL17b['faction']['plan']['why'] ?? ''), 'the open cannot happen'),
    json_encode($tL17b['faction']['plan'] ?? null));
lrgDlgPut((string) $tL17['npc'], ['open_refused' => null]);
$GLOBALS['LRG_DLG_MCM'] = ['lf' => 1, 'ml' => 0, 'cal' => 0];
// (l18) never on a rechat, a carried ask or the model's item alone; never for a redirect
$tL18 = $tull(['qst' => ['CW00A' => 0, 'CW00B' => 0], 'mq101c' => 1, 'at' => 1700000000 - 5], ['cid' => 'q18', 'type' => 'rechat', 'speech' => false, 'talk' => false]);
chk('(l) a rechat carries the ask (and the road) but never a plan', (static function () use ($tL18): bool {
    $f = lrgFacTurn($tL18, '', false);
    return !empty($f['carried']) && ($f['plan'] ?? ['x']) === [] && ($f['road']['state'] ?? '') === 'open';
})());
$tA18 = $ft(['cid' => 'q19', 'facts' => ['qst' => ['CW00A' => 0], 'mq101c' => 1, 'at' => 1700000000 - 5]]);
$tA18['faction'] = lrgFacTurn($tA18, 'i want to join the legion', true);
chk('(l) Captain Aldis (a redirect) never gets a plan or a road', ($tA18['faction']['plan'] ?? ['x']) === [] && ($tA18['faction']['road'] ?? ['x']) === []);
chk('(l) the model\'s item alone is never a plan (a carried record, no words of his this turn)',
    lrgFacQuestPlan($tull(['qst' => ['CW00A' => 0], 'mq101c' => 1, 'at' => 1700000000 - 5]), ['asked' => 'legion', 'join' => 1, 'role' => 'recruiter', 'carried' => 1]) === []);
// (l19) CHIM's own quest engine on: STAND DOWN - nothing queued
if (!function_exists('chimQuestEngineFeatureEnabled')) { function chimQuestEngineFeatureEnabled() { return !empty($GLOBALS['LRG_TEST_QENGINE']); } }
$GLOBALS['LRG_TEST_QENGINE'] = true;
$tL19 = $tull(['qst' => ['CW00A' => 0, 'CW00B' => 0], 'mq101c' => 1, 'at' => 1700000000 - 5], ['cid' => 'q20']);
$tL19['faction'] = lrgFacTurn($tL19, 'i want to join the legion', true);
chk('(l) CHIM AI Quest Progression on: engine-on, nothing queued (two engines would set the same stage twice)',
    ($tL19['faction']['plan']['state'] ?? '') === 'engine-on' && ($tL19['faction']['plan']['param'] ?? 'x') === '' && ($tL19['faction']['executed'] ?? ['x']) === []);
$GLOBALS['LRG_TEST_QENGINE'] = false;
// (l20) the switch and the table
$GLOBALS['LRG_FAC_TEST_OVERRIDE'] = ['quest_entry' => ['enabled' => false]];
$tL20 = $tull(['qst' => ['CW00A' => 0, 'CW00B' => 0], 'mq101c' => 1, 'at' => 1700000000 - 5], ['cid' => 'q21']);
$tL20['faction'] = lrgFacTurn($tL20, 'i want to join the legion', true);
chk('(l) factions.quest_entry.enabled=false: no plan (words only, as before)', ($tL20['faction']['plan'] ?? ['x']) === []);
unset($GLOBALS['LRG_FAC_TEST_OVERRIDE']);
lrgFacCfg();
chk('(l) defaults: enabled, pending 120 s, facts fresh 300 s, cache 1800 s', !empty(lrgFacCfg('quest_entry.enabled')) && (int) lrgFacCfg('quest_entry.pending_seconds') === 120
    && (int) lrgFacCfg('quest_entry.require_facts_fresh') === 300 && (int) lrgFacCfg('quest_entry.cache_seconds') === 1800);
$effT = (array) lrgFacCfg('rows.legion.effects.General Tullius');
chk('(l) the table: Tullius\'s effect is the AP line 206783 -> CW00A 10 with isid 78462 (0x01327E), qdone MQ101, notdone 10/20, max 9, qnd CW00B:10',
    ($effT['entry'] ?? '') === 'CW00TulliusGreetTalkRikkateAP' && ($effT['info'] ?? '') === 'alternateperspective.esp:206783' && (int) ($effT['conds']['isid'] ?? 0) === 78462 && 78462 === 0x01327E
    && ($effT['conds']['qdone'] ?? []) === ['MQ101'] && ($effT['conds']['notdone'] ?? []) === [10, 20] && (int) ($effT['conds']['max'] ?? 0) === 9 && ($effT['conds']['qnd'] ?? []) === ['CW00B' => 10], json_encode($effT));
chk('(l) ...its meaning names the journal truth (nothing enters his journal until her test); no other row has an effect',
    str_contains((string) ($effT['meaning'] ?? ''), 'nothing enters his journal') && (lrgFacEffectFor('CW00A', 10)['name'] ?? '') === 'General Tullius'
    && lrgFacEffectFor('CW00A', 20) === [] && lrgFacEffectFor('CW00B', 10) === []);
chk('(l) the closed rule names MQ101 stage 900 for Tullius and Rikke, and the record says 0D5146 is CLOSED on AP starts',
    (int) lrgFacCfg('rows.legion.closed.General Tullius.complete_stage') === 900 && (string) lrgFacCfg('rows.legion.closed.Legate Rikke.quest') === 'MQ101'
    && str_contains((string) lrgFacCfg('rows.legion.verified_from'), '0D5146 is CLOSED'));
unset($GLOBALS['LRG_DLG_MCM'], $GLOBALS['LRG_DLG_TURN'], $GLOBALS['LRG_FAC_QE_SENT']);
$GLOBALS['LRG_DLG_STATE'] = [];
$GLOBALS['LRG_TEST_NOW'] = 1700000000;

/**
 * ------------------------------------------------------------------ 14. [pt17] ambient scenes, the learning counter,
 * the effective ml. Legate Rikke, 2026-09-23 20:12: "dlg turn ... scene=1 ... on=0 ... why=the speaker is in a scene",
 * no faction= tag - she LIVES in the Castle Dour map-table scene and any scene at all switched the module off for her.
 * The game now sends sq= (the scene's owning quest), sqj= (an unfinished journal objective on it), cal= (calibration
 * rows answered) and qst= (quest:stage) on the facts line.
 */
head('14. [pt17] ambient scenes (sq / sqj), the learning counter (cal), the effective ml');
unset($GLOBALS['LRG_DLG_MCM'], $GLOBALS['LRG_DLG_TURN'], $GLOBALS['LRG_DLG_MCM_SNAP']);
$GLOBALS['LRG_DLG_STATE'] = [];
$GLOBALS['LRG_TEST_NOW'] = 1700000000;
// --- (a) the wire: the four keys are parsed, '-' is nothing, an old script sends none
$f14 = lrgDlgFactsFrom(['cal' => '4', 'sq' => 'CW00SolitudeMapTableScene', 'sqj' => '0', 'qst' => 'CW00A:0,CWObj:0,CW00SolitudeMapTableScene:10', 'dlg' => '0'], []);
chk('facts: cal / sq / sqj / qst / dlg are parsed', $f14['cal'] === 4 && $f14['sq'] === 'CW00SolitudeMapTableScene' && $f14['sqj'] === 0
    && ($f14['qst']['CW00A'] ?? -1) === 0 && ($f14['qst']['CW00SolitudeMapTableScene'] ?? -1) === 10 && $f14['dlg'] === 0 && $f14['sq_at'] === 1700000000, json_encode($f14));
chk('...cal also rides the global MCM tier (it is one number for the whole game)', ($f14['mcm']['cal'] ?? -1) === 4 && ($GLOBALS['LRG_DLG_MCM']['cal'] ?? -1) === 4);
$f14b = lrgDlgFactsFrom(['sq' => '-', 'qst' => '-'], $f14);
chk('sq=- is "no scene / unknown" and qst=- is empty', $f14b['sq'] === '' && $f14b['qst'] === []);
$f14c = lrgDlgFactsFrom(['q' => 'CW00A'], ['pg' => 5]);
chk('an old script that sends none of them leaves nothing behind', !isset($f14c['sq']) && !isset($f14c['cal']) && !isset($f14c['qst']));
unset($GLOBALS['LRG_DLG_MCM']);
// --- (b) the verdict
$stA = ['facts' => ['sq' => 'CW00SolitudeMapTableScene', 'sq_at' => 1700000000, 'sqj' => 0]];
chk('the map-table scene (sqj=0, fresh, on the allow-list, no journal row) is AMBIENT',
    !empty(lrgDlgSceneAmbient('Legate Rikke', $stA)['ambient']), json_encode(lrgDlgSceneAmbient('Legate Rikke', $stA)));
chk('...sqj=1 (an unfinished objective on the owning quest): not ambient', empty(lrgDlgSceneAmbient('Legate Rikke', ['facts' => ['sq' => 'CW00SolitudeMapTableScene', 'sq_at' => 1700000000, 'sqj' => 1]])['ambient']));
// [pt19 v1.0 / S1.3, capability map U8] the glob is a SHORTCUT: a quest the INDEX knows as a journal quest is a quest
// scene; one it does not know (Hulda's DialogueWhiterunBanneredMareScene3) is ambient without any glob
$GLOBALS['LRG_TEST_INDEX'] = ['rows' => [['txt' => 'Stay close to me.', 'quest' => 'MQ101', 'journal' => 1, 'toplevel' => 0]], 'layers' => []];
chk('...MQ101 (journal rows in the index, sqj=0): a quest scene, not ambient', empty(lrgDlgSceneAmbient('Legate Rikke', ['facts' => ['sq' => 'MQ101', 'sq_at' => 1700000000, 'sqj' => 0]])['ambient']));
chk('...Hulda\'s DialogueWhiterunBanneredMareScene3 (no glob, no journal row): AMBIENT (v1.0)',
    !empty(lrgDlgSceneAmbient('Hulda', ['facts' => ['sq' => 'DialogueWhiterunBanneredMareScene3', 'sq_at' => 1700000000, 'sqj' => 0]])['ambient']));
chk('...an unknown owner (sq empty / never sent): not ambient - the old rule stands', empty(lrgDlgSceneAmbient('Legate Rikke', ['facts' => ['sq' => '', 'sq_at' => 1700000000]])['ambient'])
    && empty(lrgDlgSceneAmbient('Legate Rikke', [])['ambient']));
$GLOBALS['LRG_TEST_NOW'] = 1700000000 + 301;
chk('...a stale facts line (older than scenes.fresh_seconds): not ambient', empty(lrgDlgSceneAmbient('Legate Rikke', $stA)['ambient']), json_encode(lrgDlgSceneAmbient('Legate Rikke', $stA)));
$GLOBALS['LRG_TEST_NOW'] = 1700000000;
$GLOBALS['LRG_DLG_TEST_QUESTLOG'] = ['DialogueWhiterunBanneredMareScene3' => ['briefing' => 'x', 'stage' => 10, 'at' => 1700000000]];
chk('...CHIM\'s questlog is NOT the test any more (Postgres down must not change the verdict): the index decides',
    !empty(lrgDlgSceneAmbient('Hulda', ['facts' => ['sq' => 'DialogueWhiterunBanneredMareScene3', 'sq_at' => 1700000000, 'sqj' => 0]])['ambient']));
$GLOBALS['LRG_DLG_TEST_QUESTLOG'] = [];
$GLOBALS['LRG_TEST_INDEX'] = ['rows' => [], 'layers' => []];
chk('the allow-list ships the five ambient globs', lrgDlgCfg('scenes.ambient') === ['*MapTableScene*', 'BardSongs*', '*Idle*', '*Sandbox*', 'WI*'], json_encode(lrgDlgCfg('scenes.ambient')));
// --- (c) an ambient turn keeps the faction role and the locked line, and never opens
$tAmb = $ft(['npc' => 'Legate Rikke', 'snap' => ['scene' => '1'], 'q' => ['CWObj', 'CW00A', 'CWReservations', 'CW', 'CW00SolitudeMapTableScene'], 'scene' => 1, 'ambient' => 1]);
$tAmb['faction'] = lrgFacTurn($tAmb, 'I want to join the Legion', true);
chk('Rikke in the map-table scene (on=1, ambient=1): role recruiter, the ask carried', ($tAmb['faction']['role'] ?? '') === 'recruiter' && ($tAmb['faction']['asked'] ?? '') === 'legion', json_encode($tAmb['faction']));
$GLOBALS['LRG_DLG_MCM'] = ['lf' => 1, 'ml' => 0, 'io' => 1];
$lAmb = lrgFacLockedLines($tAmb);
chk('...her locked line is built (ml=0: settled in her own dialogue, she says so)', str_contains($lAmb[0] ?? '', 'own dialogue'), json_encode($lAmb));
$GLOBALS['LRG_DLG_TEST_QUEUE'] = [];
chk('...under ml=0 NO open is queued for her (a recruiter under ml=0 answers in her own dialogue)', lrgDlgMaybeOpen($tAmb, 'join the Legion') === null && $GLOBALS['LRG_DLG_TEST_QUEUE'] === []);
$GLOBALS['LRG_DLG_MCM'] = ['lf' => 1, 'ml' => 1, 'io' => 1];
// [pt19 v1.0 / S2.2] an ambient actor may be opened - on the NARROW marker only (a join ask here, clause 1), with amb=1;
// the road rule (lrgFacRefusesOpen, Helgen first) still refuses when the Legion road is closed
$refuses = lrgFacRefusesOpen($tAmb, 't14', 'join the Legion');
$oAmb = lrgDlgMaybeOpen($tAmb, 'join the Legion');
chk('...under ml=1 the narrow marker decides (the road rule refuses, or do=open amb=1 goes out)',
    $refuses ? ($oAmb === null && $GLOBALS['LRG_DLG_TEST_QUEUE'] === []) : (is_string($oAmb) && str_contains($oAmb, ';do=open;') && str_contains($oAmb, ';amb=1')),
    ($refuses ? 'refused ' : 'open ') . (string) $oAmb);
$GLOBALS['LRG_DLG_TEST_QUEUE'] = [];
$tAmbNo = ['faction' => []] + $tAmb;   // the same actor on a turn with no enlistment ask
chk('...and never on "she is in some quest": an ambient actor + "uh some beer" opens nothing (the wide marker is not used)',
    lrgDlgMaybeOpen($tAmbNo, 'uh some beer') === null && $GLOBALS['LRG_DLG_TEST_QUEUE'] === []);
chk('the turn line carries ambient=1 and faction=legion:recruiter', str_contains(lrgDlgTurnLine($tAmb), ' ambient=1') && str_contains(lrgDlgTurnLine($tAmb), ' faction=legion:recruiter'), lrgDlgTurnLine($tAmb));
// --- (d) the learning counter's words ([pt19 v1.0 / S3.1] four passive rows, no automatic session, no emergency key)
$GLOBALS['LRG_DLG_MCM'] = ['cal' => 3];
chk('cal=3: "still learning (3 of 4)" and the next conversation of ANY kind measures it', str_contains(lrgDlgLearningText('Legate Rikke'), 'still learning')
    && str_contains(lrgDlgLearningText('Legate Rikke'), '3 of 4') && str_contains(lrgDlgLearningText('Legate Rikke'), 'any kind'), lrgDlgLearningText('Legate Rikke'));
$GLOBALS['LRG_DLG_MCM'] = ['cal' => 4];
chk('cal=4 under ml=0: learned, the dry run is on (names its page, never the retired key)', str_contains(lrgDlgLearningText('Legate Rikke'), 'learned')
    && str_contains(lrgDlgLearningText('Legate Rikke'), 'Menuless questing page') && !str_contains(lrgDlgLearningText('Legate Rikke'), 'emergency'), lrgDlgLearningText('Legate Rikke'));
unset($GLOBALS['LRG_DLG_MCM']);
$GLOBALS['LRG_DLG_STATE'] = [];
chk('no cal at all (an old script): the old wording', lrgDlgLearningText('Nobody') === 'menuless questing is off (or in dry run)', lrgDlgLearningText('Nobody'));
// --- (e) the effective ml: the snapshot's raw pair ANDed with the driver's dlg= while the facts line is fresh
unset($GLOBALS['LRG_DLG_MCM_SNAP']);
$GLOBALS['LRG_TEST_NPCSTATE'] = ['Learner' => ['ml' => '1', 'sv' => '1']];
lrgDlgPut('Learner', ['facts' => ['dlg' => 0, 'at' => 1700000000]]);
chk('snapshot ml=1 but the driver says dlg=0 (forced dry run): the server reads ml=0', (int) lrgDlgMcm('ml', 1, 'Learner') === 0 && !empty(lrgDlgMcmFromSnapshot('Learner')['ml_forced']), json_encode(lrgDlgMcmFromSnapshot('Learner')));
unset($GLOBALS['LRG_DLG_MCM_SNAP']);
lrgDlgPut('Learner', ['facts' => ['dlg' => 1, 'at' => 1700000000]]);
chk('...dlg=1: ml stays 1', (int) lrgDlgMcm('ml', 1, 'Learner') === 1);
unset($GLOBALS['LRG_DLG_MCM_SNAP']);
lrgDlgPut('Learner', ['facts' => ['dlg' => 0, 'at' => 1700000000 - 400]]);
chk('...a stale dlg=0 does not override the snapshot', (int) lrgDlgMcm('ml', 1, 'Learner') === 1);
unset($GLOBALS['LRG_DLG_MCM_SNAP'], $GLOBALS['LRG_TEST_NPCSTATE']);
$GLOBALS['LRG_DLG_STATE'] = [];

// ------------------------------------------------------------------ 16. [pt19-purchase] buying by voice
head('16. [pt19-purchase] buying food and drink by voice: the snapshot facts, the price mirror, the recogniser on tonight\'s own STT, the plan, the locked lines, the directive, the hide policy, the net, the price rail');
// research/pt19-purchase.md, PROTOCOL 10.27. Hulda, 02:53-02:55: "i'll have uh l please" / "uh some beer" / "yeah thank you" ->
// "That'll be one septim for a bottle of Honningbrew" and no bottle. The Bannered Mare's chest as the game sends it on the
// snapshot (REQ_VendorChest_Inn_Whiterun: Ale 5, Nord Mead 10, Honningbrew Mead 10, Bread 6, Bread Half 3, Village White Wine 20
// septims of value; Speech 15, no price perk -> x3.85 -> 19 / 39 / 39 / 23 / 12 / 77). No DB here: the pending-offer memory is
// test_gates 38; the never-false rail (lib/lrg_replies.php) is test_gates 38 and flow d66.
unset($GLOBALS['LRG_DLG_MCM'], $GLOBALS['LRG_DLG_SVC_HIDDEN'], $GLOBALS['LRG_DLG_TURN'], $GLOBALS['LRG_MKT_SENT'], $GLOBALS['LRG_TURN'], $GLOBALS['LRG_DLG_TRUTH_CLAIM'], $GLOBALS['LAST_LLM_RESPONSE']);
$GLOBALS['LRG_DLG_STATE'] = [];
$GLOBALS['PLAYER_NAME'] = 'Jordan';
$mkStock = '00034C5E:Ale:19:8:5,00034C5D:Nord Mead:39:3:10,000508CA:Honningbrew Mead:39:12:10,00065C97:Bread:23:24:6,00065C98:Bread Half:12:4:3,000C5348:Village White Wine:77:2:20';
$mkBp = '4.000000,3.000000,2.000000,15,0.000000,0.000000,3.850000,-';
$mkSnap = ['vend' => '1', 'room' => '25', 'bp' => $mkBp, 'stock' => $mkStock, 'pgold' => '300', '_age' => 5, 'sess' => '4242',
    'fac' => 'JobInnkeeperFaction,ServicesWhiterunBanneredMare,TownWhiterunFaction', 'class' => '', 'combat' => '0'];
$mkTurn = static function (array $over = []) use ($mkSnap): array {
    return $over + ['npc' => 'Hulda Test', 'cid' => 'm1', 'type' => 'inputtext', 'speech' => true, 'talk' => false, 'on' => true, 'crit' => 0,
        'scene' => 0, 'ambient' => 1, 'ostim' => 0, 'open' => 0, 'lost' => 0, 'entries' => [], 'tail' => [], 'q' => [], 'facts' => ['sp' => 15], 'why' => [],
        'crime' => [], 'qgiver' => 0, 'qal' => [], 'svc' => ['kind' => '', 'direct' => 0, 'direct_why' => '', 'pricelist' => 0], 'fol' => [], 'faction' => [],
        'list' => 'none', 'layer_kind' => '', 'n' => 0, 'sent' => 0, 'offer' => [], 'snap' => $mkSnap,
        'check' => null, 'initiative' => null, 'quests' => '', 'result_block' => '', 'ask' => '', 'arrest' => '', 'parked' => [], 'mute' => false, 'price' => null, 'assisted' => 0, 'list_pg' => 0];
};
$mkPlan = static function (string $say, array $over = []) use ($mkTurn): array { $t = $mkTurn($over); $t['buy'] = lrgMktPlan($t, $say, true); return $t; };
// --- (a) the facts off the snapshot
$mf = lrgMktFacts($mkSnap, 'Hulda Test');
chk('(a) vend=1 -> vendor, fresh, six rows, drinks first (Ale, Nord Mead, Honningbrew Mead, Village White Wine), then Bread, Bread Half',
    $mf['vendor'] === 'vendor' && $mf['fresh'] && count($mf['stock']) === 6 && implode('|', array_map(static fn($r) => $r['name'], $mf['stock'])) === 'Ale|Nord Mead|Honningbrew Mead|Village White Wine|Bread|Bread Half',
    json_encode(array_map(static fn($r) => $r['name'], $mf['stock'])));
chk('(a) pg 300 (pgold), room 25, bp parsed (max 4.0, min 3.0, buymin 2.0, speech 15, mult 3.85)',
    $mf['pg'] === 300 && $mf['room'] === 25 && abs((float) $mf['bp']['max'] - 4.0) < 0.001 && abs((float) $mf['bp']['mult'] - 3.85) < 0.001 && (float) $mf['bp']['speech'] === 15.0, json_encode($mf['bp']));
chk('(a) a stale snapshot (older than fresh_seconds) is not fresh: no stock is offered off it', !lrgMktFacts(['_age' => 400] + $mkSnap, 'Hulda Test')['fresh']);
chk('(a) vend=0 -> vendor none; no vend= key -> the faction hint (ServicesWhiterunBanneredMare = vendor); a bare snapshot -> unknown',
    lrgMktFacts(['vend' => '0', '_age' => 5], 'x')['vendor'] === 'none' && lrgMktFacts(['fac' => 'JobInnkeeperFaction,ServicesWhiterunBanneredMare', '_age' => 5], 'x')['vendor'] === 'vendor'
    && lrgMktFacts(['_age' => 5], 'x')['vendor'] === 'unknown');
chk('(a) a malformed row is skipped, a count-0 row is not stock', count(lrgMktFacts(['vend' => '1', '_age' => 5, 'stock' => 'garbage,0000ABCD:Thing:5:0:1,0000ABCE:Other:5:2:1'], 'x')['stock']) === 1);
// --- (b) the price mirror (UESP Skyrim:Speech) - the same formula the game computes, with the game's own inputs
$bp = static fn(float $max, float $min, float $buymin, float $sp, string $mods = '-'): array => ['max' => $max, 'min' => $min, 'buymin' => $buymin, 'speech' => $sp, 'spmod' => 0, 'sppow' => 0, 'mult' => 0, 'mods' => $mods];
chk('(b) vanilla 3.3/2.0: x3.3 at skill 0 (value 10 -> 33), x2.0 at skill 100 (-> 20)', lrgMktModelPrice(10, $bp(3.3, 2.0, 1.05, 0)) === 33 && lrgMktModelPrice(10, $bp(3.3, 2.0, 1.05, 100)) === 20);
chk('(b) LoreRim 4.0/3.0/2.0 at Speech 15: value 10 -> 39 (38.5 rounds up), value 5 (Ale) -> 19 - the owner\'s "like 19"', lrgMktModelPrice(10, $bp(4.0, 3.0, 2.0, 15)) === 39 && lrgMktModelPrice(5, $bp(4.0, 3.0, 2.0, 15)) === 19);
chk('(b) Merchant x0.8 + Haggling (adds -0.01 x Speech) at Speech 50: 3.5 x 0.3 = 1.05 -> floored at fBarterBuyMin 2.0 -> 20 (the documented order-of-operations assumption)',
    lrgMktModelPrice(10, $bp(4.0, 3.0, 2.0, 50, 'merchant:0.8/haggling:-0.5')) === 20);
chk('(b) Silent Dovah x2.0 at Speech 15 -> 77; Skill Boosts with spmod 20 (x0.8) -> 31; Silver Tongue x0.9 -> 35',
    lrgMktModelPrice(10, $bp(4.0, 3.0, 2.0, 15, 'silentdovah:2.0')) === 77 && lrgMktModelPrice(10, $bp(4.0, 3.0, 2.0, 15, 'skillboosts:0.8')) === 31
    && lrgMktModelPrice(10, $bp(4.0, 3.0, 2.0, 15, 'silvertongue:0.9')) === 35);
chk('(b) an unmodelled entry (rvt) is carried as x1.0 and changes nothing; Speech above 100 is clamped', lrgMktModelPrice(10, $bp(4.0, 3.0, 2.0, 15, 'unmodelled_rvt:1.0')) === 39 && lrgMktModelPrice(10, $bp(4.0, 3.0, 2.0, 140)) === 30);
// --- (c) the recogniser on tonight's own STT
$rec = static fn(string $s, ?array $offer = null) => lrgMktRecognise($s, $mf['stock'], $offer);
$r = $rec("i'll have uh l please");
chk("(c) \"i'll have uh l please\": an order (a strong frame), nothing recognisable named, nothing ambiguous -> the plan asks", $r['kind'] === 'order' && $r['item'] === null && $r['ambiguous'] === [] && $r['cls'] === '', json_encode($r));
$r = $rec('uh some beer');
chk('(c) "uh some beer": the class word beer -> the ONE row with the token ale -> Ale, an order', $r['kind'] === 'order' && ($r['item']['name'] ?? '') === 'Ale' && $r['cls'] === 'ale' && $r['n'] === 1, json_encode($r['item'] ?? null));
$r = $rec('hey could i buy something to drink?');
chk('(c) "hey could i buy something to drink?": class drink, four drinks -> ambiguous, an order (no gesture: "buy | something")', $r['kind'] === 'order' && $r['cls'] === 'drink' && count($r['ambiguous']) === 4, json_encode(array_map(static fn($x) => $x['name'], $r['ambiguous'])));
$r = $rec('yeah thank you');
chk('(c) "yeah thank you" with NO offer open is nothing', $r['kind'] === '' && $r['why'] === 'no order', json_encode($r));
$r = $rec('yeah thank you', ['id' => '00034C5E', 'name' => 'Ale', 'price' => 19, 'n' => 1, 'at' => lrgNow()]);
chk('(c) "yeah thank you" with the glue\'s own one-item offer open CONFIRMS it', $r['kind'] === 'confirm', json_encode($r));
chk('(c) "no thanks" / "not that one" against an offer is not a confirmation', $rec('no thanks', ['id' => 'x'])['kind'] === '' && $rec('not that one', ['id' => 'x'])['kind'] === '');
// --- (d) the variants
$name = static fn(array $r) => (string) ($r['item']['name'] ?? '');
chk('(d) "a mead please": two meads -> ambiguous (Nord Mead, Honningbrew Mead), an order', ($r = $rec('a mead please'))['kind'] === 'order' && $r['item'] === null && count($r['ambiguous']) === 2, json_encode($r['ambiguous']));
chk('(d) "give me a bottle of honningbrew": the distinctive word -> Honningbrew Mead', $name($rec('give me a bottle of honningbrew')) === 'Honningbrew Mead');
chk('(d) "two honningbrew meads": the exact run (plural folded) -> Honningbrew Mead x2', ($r = $rec('two honningbrew meads'))['kind'] === 'order' && $name($r) === 'Honningbrew Mead' && $r['n'] === 2, json_encode($r));
chk('(d) "two meads": ambiguous, count 2 kept', ($r = $rec('two meads'))['n'] === 2 && count($r['ambiguous']) === 2);
chk('(d) "can i get some bread": the exact name Bread wins over Bread Half (never longest-wins on a partial)', $name($rec('can i get some bread')) === 'Bread');
chk('(d) "half a bread" / "bread half": the exact run of the longer name', $name($rec('a bread half please')) === 'Bread Half');
chk('(d) "pour me an ale" -> Ale; "a nord mead" -> Nord Mead; "some wine" -> Village White Wine (the one wine)', $name($rec('pour me an ale')) === 'Ale' && $name($rec('a nord mead')) === 'Nord Mead' && $name($rec('some wine')) === 'Village White Wine');
chk('(d) "how much for a mead" is a price QUESTION (quote, sell nothing)', ($r = $rec('how much for a mead'))['kind'] === 'question' && count($r['ambiguous']) === 2, json_encode($r));
chk('(d) "how much for the ale" -> question about Ale', ($r = $rec('how much for the ale'))['kind'] === 'question' && $name($r) === 'Ale');
chk("(d) \"don't give me mead\" is NOTHING (negated)", ($r = $rec("don't give me mead"))['kind'] === '' && $r['negated'], json_encode($r));
chk('(d) "i do not want any bread" is nothing (negated)', $rec('i do not want any bread')['kind'] === '');
chk('(d) "can i buy you a drink" is a gesture, nothing', ($r = $rec('can i buy you a drink'))['kind'] === '' && $r['why'] === 'gesture', json_encode($r));
chk('(d) "let me get you an ale" is a gesture too', $rec('let me get you an ale')['kind'] === '');
chk('(d) "a room for the night" names no food or drink: nothing here (the inn kind keeps it)', $rec('a room for the night')['kind'] === '' && lrgDlgServiceKind('a room for the night') === 'inn');
chk('(d) "i want you to get on that bed" is nothing (a weak frame with no thing)', $rec('i want you to get on that bed')['kind'] === '');
chk('(d) "the ale here was good last winter" is nothing (a thing named in a long sentence, no order frame)', $rec('the ale you served me last winter was good')['kind'] === '');
chk('(d) "honningbrew" alone (a short bare order) -> Honningbrew Mead', $name($rec('honningbrew')) === 'Honningbrew Mead');
chk('(d) "a bottle of Honningbrew Mead and a bread": the exact run first (Honningbrew Mead)', $name($rec('a bottle of honningbrew mead and a bread')) === 'Honningbrew Mead');
chk('(d) "3 ales" -> Ale x3; "seven ales" is capped at max_count 5', $rec('3 ales')['n'] === 3 && $rec('give me seven ales')['n'] === 1);
chk('(d) with NO stock a class word or a strong frame is still an ORDER (item null) - the plan then falls back to the barter window, never a refusal', lrgMktRecognise('a mead please', [])['kind'] === 'order' && lrgMktRecognise('a mead please', [])['item'] === null && lrgMktRecognise("i'll have a mead", [])['kind'] === 'order');
// --- (e) the plan states, on Phase 2's own turn
$t = $mkPlan("i'll have uh l please");
chk('(e) "i\'ll have uh l please" -> ask, listing the three drinks with their prices, why "no item named"', $t['buy']['state'] === 'ask' && count($t['buy']['list']) === 3 && $t['buy']['list'][0]['name'] === 'Ale' && $t['buy']['why'] === 'no item named', json_encode($t['buy']['list']));
$t = $mkPlan('uh some beer');
chk('(e) "uh some beer" -> QUEUED Ale x1 @19, total 19, pg 300, the param carries item=00034C5E;n=1;price=19;name=Ale;z=1',
    $t['buy']['state'] === 'queued' && $t['buy']['item']['name'] === 'Ale' && $t['buy']['price'] === 19 && $t['buy']['total'] === 19 && $t['buy']['pg'] === 300
    && str_contains((string) $t['buy']['param'], ';item=00034C5E;n=1;price=19;name=Ale;') && str_ends_with((string) $t['buy']['param'], ';z=1') && strlen((string) $t['buy']['x']) === 10, (string) $t['buy']['param']);
$t = $mkPlan('a mead please');
chk('(e) "a mead please" -> ask with the two meads', $t['buy']['state'] === 'ask' && count($t['buy']['list']) === 2 && $t['buy']['why'] === 'ambiguous');
// [review] an AVAILABILITY question is an offer, never a sale on the spot: "do you have any ale?" once handed the ale over and
// took the 19 septims; now it is the one-item offer (ask), and the player's next "yeah" buys it through the confirm path
$t = $mkPlan('do you have any ale?');
chk('(e) "do you have any ale?" -> ask with the ONE ale as the offer, nothing queued, why names the frame',
    $t['buy']['state'] === 'ask' && count($t['buy']['list']) === 1 && $t['buy']['list'][0]['name'] === 'Ale' && $t['buy']['param'] === ''
    && str_starts_with((string) $t['buy']['why'], 'an availability question'), json_encode([$t['buy']['state'], $t['buy']['why']]));
$d = lrgMktRequestBlock(array_merge($t, ['speech' => 1]));
chk('(e) ...its directive: he asked whether you have Ale, offer it at 19 septims, sell nothing yet',
    str_contains($d, 'asked whether you have Ale. Offer the one thing you have for it - Ale (19 septims)') && str_contains($d, 'Sell nothing yet'), $d);
foreach (['do you sell bread', 'is there any bread left', 'have you got bread', 'any ale', 'what ales do you have'] as $q) {
    $t = $mkPlan($q);
    chk("(e) \"$q\" -> ask (an offer), never queued", $t['buy']['state'] === 'ask' && count($t['buy']['list']) === 1 && $t['buy']['param'] === '', $t['buy']['state'] . ' ' . $t['buy']['why']);
}
chk('(e) an imperative stays an order: "sell me some bread" / "get me an ale" -> queued',
    $mkPlan('sell me some bread')['buy']['state'] === 'queued' && $mkPlan('get me an ale')['buy']['state'] === 'queued');
lrgMemSet((string) $t['npc'], ['buyask' => null]);
$t = $mkPlan('how much for a mead');
chk('(e) "how much for a mead" -> quote with the two meads, nothing queued', $t['buy']['state'] === 'quote' && count($t['buy']['list']) === 2 && $t['buy']['param'] === '');
$t = $mkPlan('two honningbrew meads', ['snap' => ['pgold' => '50'] + $mkSnap]);
chk('(e) two Honningbrew (78) with 50 septims -> UNAFFORDABLE, nothing queued', $t['buy']['state'] === 'unaffordable' && $t['buy']['total'] === 78 && $t['buy']['pg'] === 50 && $t['buy']['param'] === '', json_encode($t['buy']['why']));
$t = $mkPlan('give me 5 village white wines');
chk('(e) five wines with only 2 in the chest: clamped to 2, queued for 154', $t['buy']['state'] === 'queued' && $t['buy']['n'] === 2 && $t['buy']['total'] === 154 && str_contains($t['buy']['why'], 'clamped'));
$t = $mkPlan('a mead please', ['snap' => ['vend' => '0', '_age' => 5, 'fac' => 'TownWhiterunFaction']]);
chk('(e) a snapshot that found no vendor faction -> not-vendor (she says she sells nothing)', $t['buy']['state'] === 'not-vendor');
$t = $mkPlan('a mead please', ['snap' => ['_age' => 5, 'fac' => 'JobMerchantFaction']]);
chk('(e) a vendor with no stock on the wire -> no-stock (the caller falls back to the barter window, never a refusal)', $t['buy']['state'] === 'no-stock');
$t = $mkPlan('a mead please', ['snap' => ['_age' => 900] + $mkSnap]);
chk('(e) stale stock -> no-stock too', $t['buy']['state'] === 'no-stock' && str_contains($t['buy']['why'], 'no fresh stock'));
$t = $mkPlan('a mead please', ['open' => 1]);
chk('(e) an open session: the plan stays out (the real entries own the turn)', $t['buy']['state'] === '' && $t['buy']['why'] === 'a session is open');
$t = $mkPlan('what a lovely evening');
chk('(e) ordinary talk: state "" why "no order"', $t['buy']['state'] === '' && $t['buy']['why'] === 'no order');
$GLOBALS['LRG_TURN'] = ['npc' => 'Hulda Test', 'intent' => ['kind' => 'undress']];
$t = $mkPlan('get me a mead and take that off');
chk('(e) a Phase 1 intent on the same turn (clothes off): never an order', $t['buy']['state'] === '' && str_starts_with($t['buy']['why'], 'intimacy intent'));
unset($GLOBALS['LRG_TURN']);
$GLOBALS['LRG_DLG_MCM'] = ['sv' => 0];
chk('(e) services off (bServiceDialogue, sv=0): no plan', $mkPlan('a mead please')['buy']['state'] === '');
unset($GLOBALS['LRG_DLG_MCM']);
// --- (f) the locked lines and the block
$tq = $mkPlan('uh some beer');
$ll = lrgMktLockedLines($tq);
chk('(f) the stock line: class stock, "what Hulda Test sells, at the price Hulda Test asks:", the ordered row first, six rows, counts <= 20 shown',
    $ll[0][0] === 'stock' && str_starts_with($ll[0][1], 'what Hulda Test sells, at the price Hulda Test asks: Ale 19 septims (8 left); ') && str_contains($ll[0][1], 'Honningbrew Mead 39 septims (12 left)') && str_contains($ll[0][1], 'Bread 23 septims;') && !str_contains($ll[0][1], '(24 left)'), $ll[0][1]);
chk('(f) the room line (an innkeeper): class price, "a room here costs 25 septims", num 25', $ll[1][0] === 'price' && $ll[1][1] === 'a room here costs 25 septims' && $ll[1][2] === 25, json_encode($ll[1]));
chk('(f) the order line: "Jordan ordered 1 Ale: 19 septims in all", num 19', $ll[2][0] === 'price' && $ll[2][1] === 'Jordan ordered 1 Ale: 19 septims in all' && $ll[2][2] === 19, json_encode($ll[2]));
$tq['locked'] = lrgDlgLockedFacts($tq);
$blk = lrgDlgLockedBlock($tq);
chk('(f) lrgDlgLockedFacts carries the stock, the room, the order AND the purse (gold from the snapshot\'s pgold - the class was dead before)',
    count(array_filter($tq['locked'], static fn($f) => $f['class'] === 'stock')) === 1 && count(array_filter($tq['locked'], static fn($f) => $f['class'] === 'gold')) === 1
    && str_contains($blk, 'Jordan is carrying 300 septims') && str_contains($blk, 'a room here costs 25 septims'), fx_cut($blk));
chk('(f) a non-innkeeper vendor gets no room line', (static function () use ($mkPlan, $mkSnap): bool {
    $t = $mkPlan('uh some beer', ['snap' => ['fac' => 'JobMerchantFaction,ServicesSolitudeAddvar'] + $mkSnap]);
    foreach (lrgMktLockedLines($t) as $l) { if (str_contains($l[1], 'a room here')) { return false; } }
    return true;
})());
chk('(f) the allowed prices: the stock, the total, the room, the purse', (static function () use ($tq): bool {
    $a = lrgMktAllowedPrices($tq);
    foreach ([19, 39, 23, 12, 77, 25, 300] as $n) { if (!in_array($n, $a, true)) { return false; } }
    return !in_array(1, $a, true);
})(), json_encode(lrgMktAllowedPrices($tq)));
// --- (g) the directive shapes
$d = lrgMktRequestBlock($tq);
chk('(g) queued: "Jordan ordered 1 Ale. Do it: say ONE short line handing it over for 19 septims - that price and no other number", the game takes the coin, choose no action, never done before the line',
    str_contains($d, 'Jordan ordered 1 Ale. Do it: say ONE short line handing it over for 19 septims - that price and no other number.') && str_contains($d, 'The game itself takes the coin') && str_contains($d, 'choose no action') && str_contains($d, 'never say it is done before your line is out') && str_starts_with($d, '<player_request>This is an ORDER of food or drink, not intimacy'), $d);
$d = lrgMktRequestBlock($mkPlan("i'll have uh l please"));
chk('(g) ask (three): name what you have from this list with the prices - Ale (19 septims), Nord Mead (39 septims), Honningbrew Mead (39 septims) - ask which, sell nothing yet',
    str_contains($d, 'Name what you have from this list with the prices - Ale (19 septims), Nord Mead (39 septims), Honningbrew Mead (39 septims) - nothing else') && str_contains($d, 'Sell nothing yet and choose no action'), $d);
$d = lrgMktRequestBlock($mkPlan('some wine', ['snap' => ['stock' => '000C5348:Village White Wine:77:2:20,00065C97:Bread:23:24:6'] + $mkSnap]));
chk('(g) ask with ONE candidate ("some wine" with one wine is resolved, so use "something to drink"): the one-item offer wording', (static function () use ($mkPlan, $mkSnap): bool {
    $t = $mkPlan('something to drink', ['snap' => ['stock' => '000C5348:Village White Wine:77:2:20,00065C97:Bread:23:24:6'] + $mkSnap]);
    $d = lrgMktRequestBlock($t);
    return $t['buy']['state'] === 'ask' && count($t['buy']['list']) === 1 && str_contains($d, 'Offer the one thing you have for it - Village White Wine (77 septims) - in ONE short line') && str_contains($d, 'ask whether he wants it');
})());
$d = lrgMktRequestBlock($mkPlan('how much for a mead'));
chk('(g) quote: "Answer with the listed price only - Nord Mead (39 septims), Honningbrew Mead (39 septims)", sell nothing', str_contains($d, 'Answer with the listed price only - Nord Mead (39 septims), Honningbrew Mead (39 septims) - in ONE short line; sell nothing'), $d);
$d = lrgMktRequestBlock($mkPlan('two honningbrew meads', ['snap' => ['pgold' => '50'] + $mkSnap]));
chk('(g) unaffordable: "Jordan cannot pay 78 septims with the 50 he carries", sell nothing', str_contains($d, 'Jordan cannot pay 78 septims with the 50 he carries; sell nothing'), $d);
$d = lrgMktRequestBlock($mkPlan('a mead please', ['snap' => ['vend' => '0', '_age' => 5, 'fac' => 'TownWhiterunFaction']]));
chk('(g) not-vendor: "Hulda Test sells nothing. Say so plainly", no price, no action', str_contains($d, 'Hulda Test sells nothing. Say so plainly in ONE short line') && str_contains($d, 'name no price and choose no action'), $d);
chk('(g) no-stock and none: no directive of this lane (the barter block / nothing applies)', lrgMktRequestBlock($mkPlan('a mead please', ['snap' => ['_age' => 5, 'fac' => 'JobMerchantFaction']])) === '' && lrgMktRequestBlock($mkPlan('hello')) === '');
chk('(g) the volatile guidance carries the directive and no <her_list>', str_contains(lrgDlgVolatileGuidance($tq), 'Jordan ordered 1 Ale.') && !str_contains(lrgDlgVolatileGuidance($tq), '<her_list'));
// --- (h) the hide policy
chk('(h) queued hides GiveItemTo AND the barter window; ask / quote hide GiveItemTo only; none hides nothing',
    lrgMktHide($tq) === ['GiveItemTo', 'OpenInventory', 'OpenInventory2'] && lrgMktHide($mkPlan('a mead please')) === ['GiveItemTo'] && lrgMktHide($mkPlan('how much for a mead')) === ['GiveItemTo'] && lrgMktHide($mkPlan('hello')) === []);
chk('(h) ...and lrgDlgServiceHidePolicy carries them (services on)', (static function () use ($tq): bool { $h = lrgDlgServiceHidePolicy($tq, false); return in_array('GiveItemTo', $h, true) && in_array('OpenInventory', $h, true); })());
// [pt19 v1.0] the automatic calibration open is retired (S3); an order still owns its turn: no pre-LLM open by kind (F18)
chk('(h) lrgDlgTurnHasBusiness: an order is business, and a service kind never opens her menu beside it (model F18)',
    lrgDlgTurnHasBusiness($tq) && !lrgDlgKindMayOpen($tq, 'barter'));
chk('(h) the turn line: " buy=queued:Ale@19"', str_contains(lrgDlgTurnLine($tq), ' buy=queued:Ale@19') && str_contains(lrgDlgTurnLine($mkPlan('two honningbrew meads')), ' buy=queued:Honningbrew_Mead@39x2') && str_contains(lrgDlgTurnLine($mkPlan("i'll have uh l please")), ' buy=ask'), lrgDlgTurnLine($tq));
// --- (i) the net: the exact wire line, once per request, never beside a trade action, never under a flagged price
unset($GLOBALS['LRG_MKT_SENT'], $GLOBALS['LRG_DLG_TRUTH_CLAIM']);
$GLOBALS['LAST_LLM_RESPONSE'] = ['action' => '', 'item' => '', 'message' => 'Ale it is. Nineteen septims.'];
$w = lrgMktNet($tq, []);
chk('(i) the net appends "Hulda Test|command|ExtCmdLRG_Buy@<param>\\r\\n" - once', count($w) === 1 && $w[0] === 'Hulda Test|command|ExtCmdLRG_Buy@' . $tq['buy']['param'] . "\r\n", json_encode($w));
chk('(i) ...a second run of the same request adds nothing', lrgMktNet($tq, []) === []);
unset($GLOBALS['LRG_MKT_SENT']);
chk('(i) a reply that already chose Trade_Items / Give_Item_To from her gets nothing added', lrgMktNet($tq, ["Hulda Test|command|OpenInventory@Jordan\r\n"]) === ["Hulda Test|command|OpenInventory@Jordan\r\n"] && lrgMktNet($tq, ["Hulda Test|command|GiveItemTo@Ale\r\n"]) === ["Hulda Test|command|GiveItemTo@Ale\r\n"]);
$GLOBALS['LRG_DLG_TRUTH_CLAIM'] = ['claim' => 'price', 'said' => '1 septims', 'fact' => '19/39 septims'];
chk('(i) a price the truth gate flagged in this same reply: the line is WITHHELD (nothing sold under a wrong number)', lrgMktNet($tq, []) === []);
unset($GLOBALS['LRG_DLG_TRUTH_CLAIM'], $GLOBALS['LRG_MKT_SENT']);
chk('(i) lrgDlgActsOnMoney knows the carrier (price > 0)', lrgDlgActsOnMoney($w) && !lrgDlgActsOnMoney(['Hulda Test|command|ExtCmdLRG_Buy@ok=1;price=0;z=1']));
chk('(i) ask / quote / none append nothing', lrgMktNet($mkPlan('a mead please'), []) === [] && lrgMktNet($mkPlan('how much for a mead'), []) === [] && lrgMktNet($mkPlan('hello'), []) === []);
// --- (j) the price rail: digits AND number words
$pm = static fn(string $s) => array_map(static fn($m) => $m['n'], lrgMktPriceMentions($s));
chk('(j) "That\'ll be one septim for a bottle of Honningbrew, friend." -> 1 (the line that started this)', $pm("That'll be one septim for a bottle of Honningbrew, friend.") === [1]);
chk('(j) "It costs 39 septims." -> 39; "for twenty gold" -> 20; "that\'s a couple of septims" -> 2; "a hundred and twenty coins" -> 120', $pm('It costs 39 septims.') === [39] && $pm('I will let it go for twenty gold.') === [20] && $pm("That's a couple of septims.") === [2] && $pm('It will be a hundred and twenty coins.') === [120]);
chk('(j) past-tense narration with no price frame is left alone ("I lost 300 gold at dice")', $pm('Aye. I lost 300 gold at dice in Riften last winter.') === []);
chk('(j) lrgMktNumber: twenty-five, thirty five, a dozen, 1,200', lrgMktNumber('twenty-five') === 25 && lrgMktNumber('thirty five') === 35 && lrgMktNumber('a dozen') === 12 && lrgMktNumber('1,200') === 1200 && lrgMktNumber('nothing') === null);
$v = lrgMktPriceVerdict("That'll be one septim for a bottle of Honningbrew, friend.", $mkPlan('a honningbrew please'));
chk('(j) the verdict on the queued Honningbrew turn: FALSE, fact "the list says Honningbrew Mead is 39 septims, not 1"', is_array($v) && $v['verdict'] === 'false' && $v['fact'] === 'the list says Honningbrew Mead is 39 septims, not 1' && ($v['mkt']['price'] ?? 0) === 39, json_encode($v));
chk('(j) "Thirty-nine septims for the Honningbrew." -> true; two for 78 -> true; no price frame -> null',
    lrgMktPriceVerdict('That will be thirty-nine septims for the Honningbrew.', $mkPlan('a honningbrew please'))['verdict'] === 'true'
    && lrgMktPriceVerdict('Two bottles, that comes to 78 septims.', $mkPlan('two honningbrew meads'))['verdict'] === 'true' && lrgMktPriceVerdict('Here you are, friend.', $tq) === null);
$tb = $mkPlan('a honningbrew please');
$tb['locked'] = lrgDlgLockedFacts($tb);
$bad = lrgDlgTruthCheck($tb, "That'll be one septim for a bottle of Honningbrew, friend.", []);
chk('(j) lrgDlgTruthCheck (the second net) sees the number WORD too: claim price, said 1 septims', is_array($bad) && $bad['claim'] === 'price' && $bad['said'] === '1 septims', json_encode($bad));
chk('(j) ...and a listed price passes it', lrgDlgTruthCheck($tb, 'Thirty-nine septims, friend.', []) === null && lrgDlgTruthCheck($tb, 'That is 39 septims.', []) === null);
chk('(j) lrgMktRailFacts: true on the vendor turn, false on a bare one', lrgMktRailFacts($tq) && !lrgMktRailFacts($mkTurn(['snap' => ['_age' => 5]])));
unset($GLOBALS['LAST_LLM_RESPONSE'], $GLOBALS['LRG_MKT_SENT'], $GLOBALS['LRG_DLG_TURN']);
$GLOBALS['LRG_DLG_STATE'] = [];

// ##################################################################################################
// [pt19 v1.0] MENULESS QUESTING v1.0 - spec 2.2 Lane A, sections v16-v29 (research/pt19-menuless-v1-spec.md; the
// behavioural contract is research/pt19c-interaction-model.md). Numbered v16.. because 16 above is the purchase lane's.
// Every turn below runs through the REAL entry points: lrgDlgHandleGameMessage (lrg_topics / lrg_dlg), lrgDlgPrepareTurn,
// lrgDlgVolatileGuidance, lrgDlgPostProcessActions, lrgDlgWillEmit, lrgDlgTransformer - no database, the index seam.
// ##################################################################################################
function v1Row(string $txt, array $o = []): array
{
    return ['txt' => $txt, 'norm' => lrgPromptNorm($txt), 'pattern' => '',
        'topic_key' => (string) ($o['tk'] ?? ('v1:' . substr(md5($txt), 0, 6))),
        'info_key' => (string) ($o['ik'] ?? ('v1i:' . substr(md5($txt . ($o['quest'] ?? '') . ($o['n'] ?? '')), 0, 8))),
        'topic' => (string) ($o['topic'] ?? 'V1Topic'), 'quest' => (string) ($o['quest'] ?? ''),
        'journal' => (int) ($o['journal'] ?? 0), 'toplevel' => (int) ($o['toplevel'] ?? 0), 'kind' => (string) ($o['kind'] ?? ''),
        'variant' => 'na', 'flags' => ['goodbye' => (int) ($o['goodbye'] ?? 0), 'sayonce' => 0, 'walkaway' => (int) ($o['walkaway'] ?? 0),
            'invis' => (int) ($o['invis'] ?? 0), 'random' => 0, 'favor' => 0, 'placeholder' => 0],
        'scripted' => (int) ($o['scripted'] ?? 0), 'compound' => 0, 'twat' => (string) ($o['twat'] ?? ''), 'crit' => (int) ($o['crit'] ?? 0),
        'cost' => (int) ($o['cost'] ?? 0), 'links' => (array) ($o['links'] ?? []), 'resp' => '', 'shared' => 1];
}
function v1Reset(array $rows = [], int $clicks = 1): void
{
    unset($GLOBALS['LRG_DLG_MCM'], $GLOBALS['LRG_DLG_TURN'], $GLOBALS['LRG_DLG_MCM_SNAP'], $GLOBALS['LRG_DLG_SVC_HIDDEN'],
        $GLOBALS['LAST_LLM_RESPONSE'], $GLOBALS['talkedSoFar'], $GLOBALS['LRG_NPCSTATE_MEMO'], $GLOBALS['LRG_TEST_NPCSTATE'],
        $GLOBALS['LRG_DLG_TEST_OVERRIDES'], $GLOBALS['LRG_DLG_HOLD_HIDDEN'], $GLOBALS['LRG_DLG_HOLD_MOVE'], $GLOBALS['ENABLED_FUNCTIONS']);
    $GLOBALS['LRG_DLG_STATE'] = [];
    $GLOBALS['LRG_TEST_INDEX'] = ['rows' => $rows, 'layers' => []];
    $GLOBALS['LRG_DLG_TEST_QUEUE'] = [];
    $GLOBALS['LRG_DLG_TEST_QUESTLOG'] = [];
    lrgDlgPut('*install*', ['clicks_ok' => $clicks]);
}
function v1Snap(string $npc, array $kv): void
{
    $GLOBALS['LRG_NPCSTATE_MEMO'][$npc] = ['kv' => $kv, 'at' => lrgNow()];
    $GLOBALS['LRG_TEST_NPCSTATE'][$npc] = $kv + ['_age' => 0];
    unset($GLOBALS['LRG_DLG_MCM_SNAP']);
}
function v1Msg(string $type, string $npc, string $payload): array
{
    $GLOBALS['gameRequest'] = [$type, lrgNow(), 100000, $payload];
    $GLOBALS['HERIKA_NAME'] = $npc;
    ob_start();
    $res = lrgDlgHandleGameMessage($GLOBALS['gameRequest']);
    return ['res' => $res, 'echo' => (string) ob_get_clean()];
}
function v1Open(string $npc, array $kv = []): void
{
    v1Msg('lrg_dlg', $npc, lrgKv(array_replace(['ev' => 'open', 'npc' => $npc, 'sid' => 's1', 'origin' => 'glue', 'crit' => 0, 'scene' => 0], $kv)));
}
function v1Topics(string $npc, array $texts, array $kv = []): array
{
    $e = [];
    foreach (array_values($texts) as $i => $tx) { $e[] = $i . '~' . (100 + $i) . '~0~-~' . str_replace(['|', '~'], ['/', '-'], $tx); }
    $kv = array_replace(['v' => 1, 'sid' => 's1', 'gen' => 1, 'layer' => 1, 'origin' => 'glue', 'npc' => $npc, 'n' => count($texts),
        'part' => 1, 'cid' => 'wcid', 'want' => 0, 'ask' => '', 'crit' => 0, 'scene' => 0, 'pg' => 300, 'q' => ''], $kv);
    $GLOBALS['LRG_DLG_TEST_QUEUE'] = [];
    $r = v1Msg('lrg_topics', $npc, lrgKv($kv) . ';e=' . implode('~~', $e));
    $r['queue'] = (array) $GLOBALS['LRG_DLG_TEST_QUEUE'];
    return $r;
}
function v1Say(string $npc, string $text, string $type = 'inputtext'): array
{
    unset($GLOBALS['LRG_DLG_TURN'], $GLOBALS['LRG_DLG_MCM_SNAP'], $GLOBALS['LRG_DLG_SVC_HIDDEN'], $GLOBALS['LAST_LLM_RESPONSE'], $GLOBALS['talkedSoFar']);
    $GLOBALS['gameRequest'] = [$type, lrgNow(), 100000, $text];
    $GLOBALS['V1_SAY_REQ'] = $GLOBALS['gameRequest'];
    $GLOBALS['HERIKA_NAME'] = $npc;
    $GLOBALS['LRG_DLG_TEST_QUEUE'] = [];
    lrgDlgPrepareTurn();
    $t = (array) ($GLOBALS['LRG_DLG_TURN'] ?? []);
    return ['t' => $t, 'biz' => $t ? lrgDlgVolatileGuidance($t) : '', 'queue' => (array) $GLOBALS['LRG_DLG_TEST_QUEUE']];
}
/** The model's reply on this turn: WillEmit is asked FIRST (the mute runs before the gate), then the gate. */
function v1Llm(string $npc, ?string $item, string $msg = 'Very well.'): array
{
    $GLOBALS['LAST_LLM_RESPONSE'] = ['action' => $item === null ? '' : 'Take_Up_Business', 'item' => (string) $item, 'target' => $npc, 'message' => $msg];
    $GLOBALS['talkedSoFar'] = [$msg];
    // the reply is gated inside the PLAYER's request (an lrg_topics / lrg_dlg sent in between is its own request)
    if (isset($GLOBALS['V1_SAY_REQ'])) { $GLOBALS['gameRequest'] = $GLOBALS['V1_SAY_REQ']; $GLOBALS['HERIKA_NAME'] = $npc; }
    $t = (array) ($GLOBALS['LRG_DLG_TURN'] ?? []);
    $will = $item !== null && $t ? lrgDlgWillEmit($t, $item) : false;
    $lines = $item === null ? [] : [$npc . '|command|ExtCmdLRG_SelectTopic@' . $item . "\r\n"];
    $out = array_values(array_filter((array) lrgDlgPostProcessActions($lines), static fn($l) => strpos((string) $l, 'ExtCmdLRG_SelectTopic@') !== false));
    $sel = array_values(array_filter($out, static fn($l) => (bool) preg_match('/;do=(pick|leave);/', (string) $l)));
    $emitted = $sel && strpos((string) $sel[0], ';adv=') === false
        && !(strpos((string) $sel[0], ';do=leave;') !== false && strpos((string) $sel[0], ';pos=-1;') !== false);
    return ['out' => $out, 'will' => $will, 'emitted' => $emitted];
}
function v1Kv(string $line): array { $p = strpos($line, '@'); return lrgParseKv(rtrim(substr($line, $p === false ? 0 : $p + 1), "\r\n")); }
function v1Do(array $out): string { return $out ? (string) (v1Kv((string) $out[0])['do'] ?? '') : ''; }
function v1LogMark(): int { clearstatcache(); $f = dirname(LRG_DIR, 2) . '/log/lorerim_glue.log'; return is_file($f) ? (int) filesize($f) : 0; }
function v1LogFrom(int $m): string { $f = dirname(LRG_DIR, 2) . '/log/lorerim_glue.log'; $s = (string) @file_get_contents($f); return strlen($s) > $m ? substr($s, $m) : ''; }
$twin = [];   // [label, WillEmit, emitted] for every T-key / LEAVE decision below: v28 asserts the twin on all of them
$v1Twin = static function (string $label, array $r) use (&$twin): void { $twin[] = [$label, $r['will'], $r['emitted']]; };
$GLOBALS['LRG_TEST_NOW'] = 1700100000;
$GLOBALS['PLAYER_NAME'] = 'Testplayer';

// ------------------------------------------------------------------ v16. visible sessions
head('v16. [pt19 v1.0 / S1.3] visible sessions: who drives, read-only sessions, sj on the server, the retired rails');
$L = ['I need work.', 'Tell me about Whiterun.', 'Never mind.'];
$rows16 = [v1Row('I need work.'), v1Row('Tell me about Whiterun.'), v1Row('Never mind.')];
// (a) no assisted / bi / x1 rail: two losses, bi=2, x1=2 and a closed layer still pick
v1Reset($rows16, 1);
lrgDlgPut('Ysolda V1', ['losses' => 2, 'cal' => ['x1' => 2]]);
v1Open('Ysolda V1', ['drv' => 1]);
v1Topics('Ysolda V1', $L, ['bi' => 2]);
v1Say('Ysolda V1', 'I need work.');
$r = v1Llm('Ysolda V1', 'T1');
$v1Twin('v16a plain key', $r);
chk('v16 (a) two losses, bi=2, x1=2: a plain key still picks (no assisted / bi / x1 rail)', v1Do($r['out']) === 'pick' && $r['will'], json_encode($r['out']));
// (b) ev=open drv=0 -> a T-key -> NO emit, the clause, WillEmit false, the log line
v1Reset($rows16, 1);
v1Open('Ysolda V1', ['drv' => 0]);
v1Topics('Ysolda V1', $L);
$q = v1Say('Ysolda V1', 'I need work.');
$m = v1LogMark();
$r = v1Llm('Ysolda V1', 'T1');
$v1Twin('v16b read-only key', $r);
chk('v16 (b) drv=0: the clause "choose it on the list yourself" rides <business> the same turn, the list as facts (no keys)',
    str_contains($q['biz'], 'choose it on the list yourself - I cannot pick for you here') && !preg_match('/\nT1 /', $q['biz']) && str_contains($q['biz'], '- I need work.'), $q['biz']);
chk('v16 (b) ...a T-key emits NOTHING and WillEmit is false', $r['out'] === [] && !$r['will'], json_encode($r['out']));
chk('v16 (b) ...and the T-key\'s log line is "gate: read-only session why=vis" (S1.3), not a stale-key drop',
    str_contains(v1LogFrom($m), 'gate: read-only session why=vis'), v1LogFrom($m));
$m = v1LogMark();
$r2 = v1Llm('Ysolda V1', 'I need work');
chk('v16 (b) ...words too: log "gate: read-only session why=vis"', $r2['out'] === [] && str_contains(v1LogFrom($m), 'gate: read-only session why=vis'), v1LogFrom($m));
$w = v1Topics('Ysolda V1', $L, ['want' => 1, 'ask' => 'I need work', 'cid' => 'w16b', 'gen' => 2]);
chk('v16 (b) ...and want=1 picks nothing on it', $w['echo'] === '' && $w['queue'] === [], $w['echo']);
// (c) sj=1 + clicks_ok 0 -> read-only; clicks_ok 1 -> the scene rail drives it
v1Reset($rows16, 0);
v1Open('Irileth V1', ['origin' => 'engine', 'scene' => 1, 'sj' => 1, 'sq' => 'MQ102', 'sqj' => 1, 'drv' => 1]);
v1Topics('Irileth V1', $L);
$q = v1Say('Irileth V1', 'I need work.');
$r = v1Llm('Irileth V1', 'T1');
$v1Twin('v16c scene unproven', $r);
chk('v16 (c) sj=1 with clicks_ok 0: read-only (why=scene-unproven), the clause rides the turn, nothing emitted',
    (string) ($q['t']['ro'] ?? '') === 'scene-unproven' && str_contains($q['biz'], 'choose it on the list yourself') && $r['out'] === [] && !$r['will'], json_encode([$q['t']['ro'] ?? '', $r['out']]));
lrgDlgPut('*install*', ['clicks_ok' => 1]);
$q = v1Say('Irileth V1', 'I need work.');
$r = v1Llm('Irileth V1', 'T1');
$v1Twin('v16c scene rail plain', $r);
chk('v16 (c) sj=1 with clicks_ok 1: the scene rail - a plain pick goes out, WillEmit true', v1Do($r['out']) === 'pick' && $r['will'], json_encode($r['out']));
$GLOBALS['LRG_DLG_TEST_OVERRIDES'] = ['entries' => []];
$sceneRows = [v1Row('We should hurry.', ['scripted' => 1, 'goodbye' => 1]), v1Row('What happened?'), v1Row('Tell me more.', ['scripted' => 0])];
v1Reset($sceneRows, 1);
v1Open('Irileth V1', ['origin' => 'engine', 'scene' => 1, 'sj' => 1, 'sq' => 'MQ102', 'sqj' => 1]);
v1Topics('Irileth V1', ['We should hurry.', 'What happened?', 'Tell me more.']);
v1Say('Irileth V1', 'hurry');
$r = v1Llm('Irileth V1', 'T1', 'You want to hurry, then?');
$v1Twin('v16c scene rail commit parks', $r);
chk('v16 (c) ...on the scene rail a commit still PARKS (explicit / bare yes / single only)', $r['out'] === [] && !$r['will']
    && (string) ((lrgDlgGet('Irileth V1')['parked'] ?? [])['norm'] ?? '') === 'we should hurry', json_encode([$r['out'], lrgDlgGet('Irileth V1')['parked'] ?? null]));
v1Say('Irileth V1', 'Yes.');
$r = v1Llm('Irileth V1', 'T1', '');
$v1Twin('v16c scene rail bare yes', $r);
chk('v16 (c) ...and "yes" to her question releases it', v1Do($r['out']) === 'pick' && $r['will'], json_encode($r['out']));
// (d) session.drive_scene = false (the kill switch, a config line) -> read-only again
v1Reset($rows16, 1);
v1Open('Irileth V1', ['origin' => 'engine', 'scene' => 1, 'sj' => 1, 'sq' => 'MQ102', 'sqj' => 1]);
v1Topics('Irileth V1', $L);
$sess = (array) (lrgDlgGet('Irileth V1')['session'] ?? []);
chk('v16 (d) sj=1, clicks_ok 1, drive_scene on (the shipped default) -> driven', lrgDlgReadOnlyWhy($sess, 'Irileth V1') === '' && lrgDlgCfg('session.drive_scene') === true);
$GLOBALS['LRG_DLG_TEST_CFG'] = ['session.drive_scene' => false];
$q = v1Say('Irileth V1', 'I need work.');
$r = v1Llm('Irileth V1', 'T1');
$v1Twin('v16d drive_scene off', $r);
chk('v16 (d) session.drive_scene=false -> read-only (why=drive_scene off), nothing emitted', (string) ($q['t']['ro'] ?? '') === 'drive_scene off' && $r['out'] === [] && !$r['will'],
    json_encode([$q['t']['ro'] ?? '', $r['out']]));
unset($GLOBALS['LRG_DLG_TEST_CFG']);
// (e) [rev2: game R1] sj on the server: the game's sj OR (scene=1 AND the INDEX knows sq as a journal quest); old ev=open
v1Reset([v1Row('Some TG00 line.', ['quest' => 'TG00', 'journal' => 1]), v1Row('A MQ102 line.', ['quest' => 'MQ102', 'journal' => 1])], 0);
v1Open('Hulda V1', ['origin' => 'engine', 'scene' => 1, 'sj' => 0, 'sq' => 'DialogueWhiterunBanneredMareScene3', 'sqj' => 0]);
chk('v16 (e) scene=1 sq=DialogueWhiterunBanneredMareScene3 sqj=0 -> sj=0 (driven: no journal row in the index)',
    (int) (lrgDlgGet('Hulda V1')['session']['sj'] ?? -1) === 0, json_encode(lrgDlgGet('Hulda V1')['session'] ?? null));
v1Open('Irileth V1', ['origin' => 'engine', 'scene' => 1, 'sj' => 1, 'sq' => 'MQ102', 'sqj' => 1]);
chk('v16 (e) sq=MQ102 sqj=1 -> sj=1 (read-only at clicks_ok 0, the scene rail at 1)',
    (int) (lrgDlgGet('Irileth V1')['session']['sj'] ?? -1) === 1 && lrgDlgReadOnlyWhy((array) lrgDlgGet('Irileth V1')['session'], 'Irileth V1') === 'scene-unproven');
v1Open('Brynjolf V1', ['origin' => 'engine', 'scene' => 1, 'sj' => 0, 'sq' => 'TG00', 'sqj' => 0]);
chk('v16 (e) sq=TG00 sqj=0 -> the SERVER says sj=1 (TG00 has journal rows although no objective is shown yet)',
    (int) (lrgDlgGet('Brynjolf V1')['session']['sj'] ?? -1) === 1, json_encode(lrgDlgGet('Brynjolf V1')['session'] ?? null));
lrgDlgPut('Old V1', ['facts' => ['sq' => '', 'at' => lrgNow()]]);
v1Open('Old V1', ['origin' => 'engine', 'scene' => 1]);
chk('v16 (e) an OLD ev=open (scene=1, no sq / sj): sj from scene && !ambient (1: the owner is unknown, never ambient)',
    (int) (lrgDlgGet('Old V1')['session']['sj'] ?? -1) === 1 && (int) (lrgDlgGet('Old V1')['session']['drv'] ?? -1) === 1);
// (f) F1: sj / drv survive the session's lists; a lrg_topics drv= wins
v1Reset($rows16, 1);
v1Open('Ysolda V1', ['drv' => 0]);
v1Topics('Ysolda V1', $L);
v1Topics('Ysolda V1', $L, ['gen' => 2]);
chk('v16 (f) drv=0 survives a second lrg_topics WITHOUT drv= (model F1)', (int) (lrgDlgGet('Ysolda V1')['session']['drv'] ?? -1) === 0);
v1Topics('Ysolda V1', $L, ['gen' => 3, 'drv' => 1]);
chk('v16 (f) ...and a lrg_topics that carries drv=1 wins', (int) (lrgDlgGet('Ysolda V1')['session']['drv'] ?? -1) === 1);
// (g) F2: ev=stopped -> read-only (why=stopped)
v1Msg('lrg_dlg', 'Ysolda V1', 'ev=stopped;npc=Ysolda V1;sid=s1;why=combat');
$q = v1Say('Ysolda V1', 'I need work.');
chk('v16 (g) ev=stopped why=combat: read-only from then on (model F2)', (string) ($q['t']['ro'] ?? '') === 'stopped' && str_contains($q['biz'], 'choose it on the list yourself'), (string) ($q['t']['ro'] ?? ''));
// (h) F6: an open session with scene=1 sj=0 is driven (the scene never switches the module off while a session is open)
v1Reset($rows16, 1);
v1Snap('Hulda V1', ['scene' => '1']);
v1Open('Hulda V1', ['origin' => 'engine', 'scene' => 1, 'sj' => 0, 'sq' => 'DialogueWhiterunBanneredMareScene3', 'sqj' => 0]);
v1Topics('Hulda V1', $L, ['scene' => 1]);
$q = v1Say('Hulda V1', 'I need work.');
$r = v1Llm('Hulda V1', 'T1');
chk('v16 (h) scene=1 sj=0 with the session OPEN: on, keys offered, the key picks (model F6)', !empty($q['t']['on']) && v1Do($r['out']) === 'pick', json_encode([$q['t']['why'] ?? [], $r['out']]));
// (i) F23: an OPEN session older than 900 s counts as closed (a lost ev=closed)
$sessOld = ['state' => 'open', 'at' => lrgNow() - 901, 'entries' => []];
chk('v16 (i) state=open 901 s old is NOT open (the game\'s own cap, model F23); 899 s is', !lrgDlgSessionOpen($sessOld) && lrgDlgSessionOpen(['state' => 'open', 'at' => lrgNow() - 899]));
// (j) the state wording (S1.3): open root / open closed layer / cached root
v1Reset([v1Row('I need work.', ['toplevel' => 1]), v1Row('Tell me about Whiterun.', ['toplevel' => 1])], 1);
v1Topics('Ysolda V1', ['I need work.', 'Tell me about Whiterun.'], ['layer' => 0]);
$q = v1Say('Ysolda V1', 'hello there');
chk('v16 (j) an OPEN root: "the list is on screen in front of <player>; things he can raise"', str_contains($q['biz'], 'state="the list is on screen in front of Testplayer; things he can raise"'), $q['biz']);
v1Msg('lrg_dlg', 'Ysolda V1', 'ev=closed;npc=Ysolda V1;sid=s1;why=goodbye;pending=1;layer=0');
chk('v16 (j) ev=closed pending=1 is CLOSED (no "lost" state any more), losses stays a log counter',
    (string) (lrgDlgGet('Ysolda V1')['session']['state'] ?? '') === 'closed' && (int) (lrgDlgGet('Ysolda V1')['losses'] ?? 0) === 1);
$q = v1Say('Ysolda V1', 'nice weather today');
chk('v16 (j) the CACHED root (session closed): "things <player> could raise with <npc> (her list is not open now)"',
    str_contains($q['biz'], 'things Testplayer could raise with Ysolda V1 (her list is not open now)'), $q['biz']);
v1Reset($rows16, 1);
v1Topics('Ysolda V1', $L);
$q = v1Say('Ysolda V1', 'hello there');
chk('v16 (j) an OPEN closed layer: "<npc> is waiting for an answer - the list is on screen"', str_contains($q['biz'], 'Ysolda V1 is waiting for an answer - the list is on screen'), $q['biz']);
// (k) U2: an Invisible Continue prompt is VISIBLE, graded scripted, offered and picked
v1Reset([v1Row('A dragon has destroyed Helgen.', ['scripted' => 1, 'goodbye' => 1]), v1Row('I was told to give the message directly to the jarl.', ['scripted' => 1, 'invis' => 1, 'links' => ['x:1']])], 1);
v1Topics('Irileth V1', ['A dragon has destroyed Helgen.', 'I was told to give the message directly to the jarl.']);
$q = v1Say('Irileth V1', 'okay');
$ents = (array) (lrgDlgGet('Irileth V1')['session']['entries'] ?? []);
chk('v16 (k) U2: an invis=1 entry is OFFERED (class not hidden) and graded scripted=1; Irileth\'s layer is TWO entries, not a single',
    count($q['t']['offer']['keys'] ?? []) === 2 && (int) ($ents[1]['scripted'] ?? 0) === 1 && (string) ($ents[1]['class'] ?? '') !== 'hidden' && $q['t']['single'] === null,
    json_encode(array_map(static fn($e) => [$e['class'], $e['scripted']], $ents)));
$w = v1Topics('Irileth V1', ['A dragon has destroyed Helgen.', 'I was told to give the message directly to the jarl.'], ['want' => 1, 'cid' => 'w16k', 'gen' => 2]);
chk('v16 (k) ..."okay" releases nothing there (a two-entry layer, both commits)', $w['echo'] === '', $w['echo']);
v1Say('Irileth V1', 'I was told to give the message directly to the jarl.');
$r = v1Llm('Irileth V1', 'T2');
$v1Twin('v16k invis explicit', $r);
chk('v16 (k) ...and the invisible-continue line is picked when he says it (explicit: his own sentence)', v1Do($r['out']) === 'pick' && $r['will'], json_encode($r['out']));

// ------------------------------------------------------------------ v17. walk-away is not a commit; the hub rule
head('v17. [pt19 v1.0 / S4.1] walk-away is not a commit; the hub rule; the commit override');
$six = ['skyrim.esm:0328EC', 'skyrim.esm:086497', 'skyrim.esm:0328EB', 'skyrim.esm:083049', 'skyrim.esm:0328EA', 'skyrim.esm:0CA628'];
$hub = [
    // C3Dragonborn: TWO INFOs share the prompt - the one the lookup takes first branches away, the other loops (hub)
    v1Row('Why are you looking for a Dragonborn?', ['tk' => 'skyrim.esm:0328EB', 'ik' => 'skyrim.esm:03290A', 'quest' => 'MQ106', 'journal' => 1, 'scripted' => 1,
        'links' => ['skyrim.esm:020079', 'skyrim.esm:0CA628']]),
    v1Row('Why are you looking for a Dragonborn?', ['tk' => 'skyrim.esm:0328EB', 'ik' => 'skyrim.esm:021144', 'quest' => 'MQ106', 'journal' => 1, 'scripted' => 1, 'links' => $six]),
    v1Row("You said you're in hiding? From who?", ['tk' => 'skyrim.esm:083049', 'ik' => 'skyrim.esm:083053', 'quest' => 'MQ106', 'journal' => 1, 'scripted' => 1, 'links' => $six]),
    v1Row('You said the Thalmor are after you?', ['tk' => 'skyrim.esm:083049', 'ik' => 'skyrim.esm:05C2FD', 'quest' => 'MQ106', 'journal' => 1, 'links' => $six]),
    v1Row('Why did you take the horn from Ustengrav?', ['tk' => 'skyrim.esm:086497', 'ik' => 'skyrim.esm:08649A', 'quest' => 'MQ106', 'journal' => 1, 'scripted' => 1, 'links' => $six]),
    v1Row("I don't need to prove anything to you. I'm done here.", ['tk' => 'skyrim.esm:0CA628', 'ik' => 'skyrim.esm:0CA632', 'quest' => 'MQ106', 'journal' => 1, 'scripted' => 1, 'goodbye' => 1]),
];
v1Reset($hub, 1);
$hubTexts = ['Why are you looking for a Dragonborn?', "You said you're in hiding? From who?", 'You said the Thalmor are after you?',
    'Why did you take the horn from Ustengrav?', "I don't need to prove anything to you. I'm done here."];
v1Topics('Delphine V1', $hubTexts);
$ents = (array) (lrgDlgGet('Delphine V1')['session']['entries'] ?? []);
$commits = array_values(array_map(static fn($e) => (string) $e['text'], array_filter($ents, static fn($e) => !empty($e['commit']))));
chk('v17 MQ106 C2Horn hub: ONE commit (C5, goodbye); the three looping scripted questions are hub questions (incl. the shared C3 norm)',
    $commits === ["I don't need to prove anything to you. I'm done here."] && (int) $ents[0]['hub'] === 1 && (int) $ents[3]['hub'] === 1, json_encode(array_map(static fn($e) => [$e['text'], $e['hub'], $e['commit']], $ents)));
// oath layers: unscripted walk-away lines never commit
v1Reset([v1Row('"Upon my honor I do swear undying loyalty to the Emperor..."', ['walkaway' => 1, 'links' => ['a', 'b']]), v1Row('Hold on.', ['walkaway' => 0])], 1);
v1Topics('Rikke V1', ['"Upon my honor I do swear undying loyalty to the Emperor..."']);
$ents = (array) (lrgDlgGet('Rikke V1')['session']['entries'] ?? []);
chk('v17 an oath layer (walk-away, unscripted): 0 commits', !array_filter($ents, static fn($e) => !empty($e['commit'])), json_encode($ents));
// MS08-shaped kill / spare: two scripted goodbye lines -> two commits; MQ104 OutroB layer: scripted siblings that branch away
v1Reset([v1Row('Kill him.', ['scripted' => 1, 'goodbye' => 1]), v1Row('Spare him.', ['scripted' => 1, 'goodbye' => 1]), v1Row('What do you think?')], 1);
v1Topics('Brelyna V1', ['Kill him.', 'Spare him.', 'What do you think?']);
$ents = (array) (lrgDlgGet('Brelyna V1')['session']['entries'] ?? []);
chk('v17 an MS08-shaped kill / spare layer: exactly two commits', count(array_filter($ents, static fn($e) => !empty($e['commit']))) === 2, json_encode($ents));
v1Reset([v1Row('When the dragon died, I absorbed some kind of power from it.', ['scripted' => 1, 'links' => ['skyrim.esm:05EE40']]),
    v1Row("That's just what the men called me.", ['scripted' => 1, 'walkaway' => 1, 'links' => ['skyrim.esm:05EE40', 'skyrim.esm:05EE35']])], 1);
v1Topics('Balgruuf V1', ['When the dragon died, I absorbed some kind of power from it.', "That's just what the men called me."]);
$ents = (array) (lrgDlgGet('Balgruuf V1')['session']['entries'] ?? []);
chk('v17 MQ104 OutroB layer: still commits by the sibling rule (two scripted lines that branch away)', count(array_filter($ents, static fn($e) => !empty($e['commit']))) === 2, json_encode($ents));
// Eorlund's five
$eor = ["I'd like a waraxe.", "I'd like a greatsword.", "I'd like a battleaxe.", "I'd like a dagger.", "I'd like a sword."];
v1Reset(array_map(static fn($x) => v1Row($x, ['quest' => 'C00', 'journal' => 1, 'scripted' => 1, 'goodbye' => 1]), $eor), 1);
v1Topics('Eorlund V1', $eor);
$ents = (array) (lrgDlgGet('Eorlund V1')['session']['entries'] ?? []);
chk('v17 Eorlund\'s five are five commits', count(array_filter($ents, static fn($e) => !empty($e['commit']))) === 5);
// rev2 ai R1: both Oath4 rows commit - the Legion's by goodbye, the Stormcloak's by the commit:true override
$GLOBALS['LRG_DLG_TEST_OVERRIDES'] = ['entries' => [['match' => ['topic' => 'MQ102BStormcloakOath4'], 'commit' => true],
    ['match' => ['topic' => 'MQ102ALegionOath4'], 'commit' => true]]];
$singles = [
    ['"Long live the Emperor! Long live the Empire!"', ['topic' => 'MQ102ALegionOath4', 'scripted' => 1, 'goodbye' => 1]],
    ['"All hail the Stormcloaks, the true sons and daughters of Skyrim!"', ['topic' => 'MQ102BStormcloakOath4', 'scripted' => 1]],
    ['What do these Greybeards want with me?', ['topic' => 'MQ104BalgruufOutroD1', 'scripted' => 1, 'goodbye' => 1]],
    ['What else can I help you with?', ['topic' => 'MQ102BalgruufReward', 'scripted' => 1, 'goodbye' => 1]],
    ['Is that it?', ['topic' => 'MS05PoemFinalVerse', 'scripted' => 1, 'goodbye' => 1]],
];
foreach ($singles as [$tx, $o]) {
    v1Reset([v1Row($tx, $o)], 1);
    $GLOBALS['LRG_DLG_TEST_OVERRIDES'] = ['entries' => [['match' => ['topic' => 'MQ102BStormcloakOath4'], 'commit' => true]]];
    v1Topics('Single V1', [$tx]);
    $e = (array) ((lrgDlgGet('Single V1')['session']['entries'] ?? [])[0] ?? []);
    chk('v17 "' . substr($tx, 0, 40) . '" (' . $o['topic'] . ') is a COMMIT single', !empty($e['commit']), json_encode($e['commit'] ?? null));
}
unset($GLOBALS['LRG_DLG_TEST_OVERRIDES']);

// ------------------------------------------------------------------ v18. the leave guard
head('v18. [pt19 v1.0 / S4.2] the leave guard');
$tg = [v1Row('What do you have in mind?', ['walkaway' => 1, 'twat' => 'TG00BrynjolfWalkAwayTopic']), v1Row('What do I have to do?', ['walkaway' => 1, 'twat' => 'TG00BrynjolfWalkAwayTopic'])];
v1Reset($tg, 1);
v1Topics('Brynjolf V1', ['What do you have in mind?', 'What do I have to do?']);
$q = v1Say('Brynjolf V1', 'I have to go.');
$r = v1Llm('Brynjolf V1', 'LEAVE');
$v1Twin('v18 leave guard', $r);
chk('v18 TG00 intro layer + LEAVE: do=show kind=back (never the engine cancel pos=-1 that plays the walk-away topic)',
    v1Do($r['out']) === 'show' && (string) (v1Kv($r['out'][0])['kind'] ?? '') === 'back' && !$r['will'], json_encode($r['out']));
chk('v18 ...and her line carries the new wording', str_contains($q['biz'], 'No line here backs out cleanly; leaving is his to do by hand, and it ends things with her - tell him so.'), $q['biz']);
v1Reset([v1Row("I'd like to rent a room. (10 gold)", ['toplevel' => 1, 'cost' => 10]), v1Row('What have you got for sale?', ['toplevel' => 1])], 1);
v1Topics('Hulda V1', ["I'd like to rent a room. (10 gold)", 'What have you got for sale?'], ['layer' => 0]);
$q = v1Say('Hulda V1', 'bye');
$r = v1Llm('Hulda V1', 'LEAVE');
$v1Twin('v18 innkeeper leave', $r);
chk('v18 an innkeeper layer with no walk-away: do=leave pos=-1, WillEmit false (no back line)', v1Do($r['out']) === 'leave'
    && (string) (v1Kv($r['out'][0])['pos'] ?? '') === '-1' && !$r['will'] && !str_contains($q['biz'], 'backs out cleanly'), json_encode($r['out']));
v1Reset(array_merge($tg, [v1Row('Never mind.')]), 1);
v1Topics('Brynjolf V1', ['What do you have in mind?', 'What do I have to do?', 'Never mind.']);
v1Say('Brynjolf V1', 'never mind');
$r = v1Llm('Brynjolf V1', 'LEAVE');
$v1Twin('v18 back line', $r);
chk('v18 "Never mind." listed: LEAVE clicks THAT entry (do=leave pos=2), WillEmit true', v1Do($r['out']) === 'leave'
    && (string) (v1Kv($r['out'][0])['pos'] ?? '') === '2' && $r['will'], json_encode($r['out']));

// ------------------------------------------------------------------ v19. explicit
head('v19. [pt19 v1.0 / S4.3] explicit: his own sentence is the confirmation');
$kod = [v1Row('I would like to join the Companions.', ['topic' => 'C00KodlakJoinUpStartTopic', 'quest' => 'C00', 'journal' => 1, 'scripted' => 1, 'links' => ['x:1']]),
    v1Row('I can take care of myself.', ['quest' => 'C00', 'journal' => 1, 'scripted' => 1, 'links' => ['x:2']]), v1Row('Nothing.')];
v1Reset($kod, 1);
v1Topics('Kodlak Whitemane', ['I would like to join the Companions.', 'I can take care of myself.', 'Nothing.']);
v1Say('Kodlak Whitemane', 'I would like to join the Companions.');
$r = v1Llm('Kodlak Whitemane', 'T1', 'Let me take a look at you.');
$v1Twin('v19 kodlak exact', $r);
chk('v19 Kodlak: ONE utterance via the exact line - picked at once (mode explicit), WillEmit true', v1Do($r['out']) === 'pick' && $r['will'], json_encode($r['out']));
v1Say('Kodlak Whitemane', 'I can take care of myself');
$r = v1Llm('Kodlak Whitemane', 'T2', 'We shall see.');
$v1Twin('v19 kodlak care', $r);
chk('v19 "I can take care of myself" on Kodlak\'s layer: explicit', v1Do($r['out']) === 'pick' && $r['will'], json_encode($r['out']));
v1Reset(array_map(static fn($x) => v1Row($x, ['quest' => 'C00', 'journal' => 1, 'scripted' => 1, 'goodbye' => 1]), $eor), 1);
v1Topics('Eorlund V1', $eor);
foreach (['a sword, please', 'the sword'] as $say) {
    v1Say('Eorlund V1', $say);
    $r = v1Llm('Eorlund V1', 'T5', 'A fine choice.');
    $v1Twin('v19 eorlund ' . $say, $r);
    chk('v19 "' . $say . '" on Eorlund\'s five: explicit through the named-choice slot (the one differing word)', v1Do($r['out']) === 'pick' && $r['will'], json_encode($r['out']));
}
v1Say('Eorlund V1', 'sword');
$r = v1Llm('Eorlund V1', 'T5', 'The sword, then?');
$v1Twin('v19 eorlund stt sword', $r);
chk('v19 STT "sword" (one token) PARKS', $r['out'] === [] && !$r['will'], json_encode($r['out']));
v1Reset([v1Row('Are we ready?', ['toplevel' => 1, 'scripted' => 1, 'goodbye' => 1, 'quest' => 'MS05', 'journal' => 1]), v1Row('Tell me the plan again.', ['toplevel' => 1])], 1);
v1Topics('Viarmo V1', ['Are we ready?', 'Tell me the plan again.'], ['layer' => 0]);
v1Say('Viarmo V1', 'are we ready');
$r = v1Llm('Viarmo V1', 'T1', 'We are.');
$v1Twin('v19 are we ready', $r);
chk('v19 "are we ready" (3 tokens, 1.0) on a 3-token line: explicit', v1Do($r['out']) === 'pick' && $r['will'], json_encode($r['out']));
v1Reset([v1Row('I submit. Take me to jail.', ['crit' => 2, 'scripted' => 1, 'goodbye' => 1]), v1Row('Wait, I can explain.')], 1);
v1Topics('Guard V1', ['I submit. Take me to jail.', 'Wait, I can explain.']);
v1Say('Guard V1', 'I submit. Take me to jail.');
$r = v1Llm('Guard V1', 'T1', 'Choose that yourself.');
$v1Twin('v19 lethal', $r);
chk('v19 lethal NEVER: the exact line on a crit 2 entry -> do=show kind=meta, never a pick', v1Do($r['out']) === 'show' && !$r['will'], json_encode($r['out']));
v1Reset([v1Row('Here is the money. (150 gold)', ['cost' => 150]), v1Row('Not now.')], 1);
v1Topics('Merchant V1', ['Here is the money. (150 gold)', 'Not now.'], ['pg' => 900]);
v1Say('Merchant V1', 'Here is the money.');
$r = v1Llm('Merchant V1', 'T1', 'A hundred and fifty, you are sure?');
$v1Twin('v19 costly', $r);
chk('v19 100+ septims ALWAYS asks: his exact line on a 150-septim entry parks', $r['out'] === [] && !$r['will'], json_encode($r['out']));
v1Reset([v1Row('Kill him.', ['scripted' => 1, 'goodbye' => 1]), v1Row('Spare him.', ['scripted' => 1, 'goodbye' => 1])], 1);
v1Topics('Brelyna V1', ['Kill him.', 'Spare him.']);
v1Say('Brelyna V1', 'kill');
$r = v1Llm('Brelyna V1', 'T1', 'You want him dead?');
$v1Twin('v19 stt kill', $r);
chk('v19 STT single "kill" NEVER: parks', $r['out'] === [] && !$r['will'], json_encode($r['out']));
// the same utterance re-presented on the next layer with no new speech -> nothing (F7)
v1Reset([v1Row('So what do you need me to do?', ['scripted' => 1, 'quest' => 'MQ103', 'journal' => 1]),
    v1Row('Do you need any help with the dragons?', ['quest' => 'MQ103', 'journal' => 1, 'toplevel' => 1]), v1Row('Never mind.', ['toplevel' => 1])], 1);
v1Say('Farengar V1', 'do you need any help with the dragons');
$w = v1Topics('Farengar V1', ['Do you need any help with the dragons?', 'Never mind.'], ['want' => 1, 'cid' => 'w19a', 'layer' => 0, 'gen' => 1]);
chk('v19 MQ103: the intro line is picked from his sentence (fast path)', str_contains($w['echo'], ';do=pick;') && str_contains($w['echo'], ';pos=0;'), $w['echo']);
$w = v1Topics('Farengar V1', ['So what do you need me to do?'], ['want' => 1, 'cid' => 'w19b', 'layer' => 1, 'gen' => 2]);
chk('v19 ...the B1 single with the SAME utterance releases NOTHING (not newer than the click it produced; a scripted single never advances)',
    !str_contains($w['echo'], ';do=pick;'), $w['echo']);
$GLOBALS['LRG_TEST_NOW'] += 5;
v1Say('Farengar V1', 'what do you need done');
$w = v1Topics('Farengar V1', ['So what do you need me to do?'], ['want' => 1, 'cid' => 'w19c', 'layer' => 1, 'gen' => 3]);
chk('v19 ...a NEW "what do you need done" releases the single (step 5: shares "need", a question to a question)', str_contains($w['echo'], ';do=pick;'), $w['echo']);
// [rev2: game R4] Kodlak first contact: the sentence 6 s BEFORE the session, open_pending set -> explicit on want=1
v1Reset($kod, 1);
$GLOBALS['LRG_TEST_NOW'] += 60;
$q = v1Say('Kodlak Whitemane', 'I would like to join the Companions.');
$cidK = (string) $q['t']['cid'];
lrgDlgPut('Kodlak Whitemane', ['open_pending' => ['cid' => $cidK, 'at' => lrgNow()]]);
$GLOBALS['LRG_TEST_NOW'] += 6;
v1Open('Kodlak Whitemane', ['origin' => 'glue']);
$w = v1Topics('Kodlak Whitemane', ['I would like to join the Companions.', 'I can take care of myself.', 'Nothing.'], ['want' => 1, 'cid' => $cidK]);
chk('v19 Kodlak first contact: utterance 6 s before session.at, open_pending set -> the fast path clicks the join (explicit)',
    str_contains($w['echo'], ';do=pick;') && str_contains($w['echo'], ';pos=0;'), $w['echo']);
$GLOBALS['LRG_TEST_NOW'] += 300;
v1Open('Kodlak Whitemane', ['origin' => 'engine', 'sid' => 's2']);
$w = v1Topics('Kodlak Whitemane', ['I would like to join the Companions.', 'I can take care of myself.', 'Nothing.'], ['want' => 1, 'cid' => 'eng1', 'sid' => 's2']);
chk('v19 ...the same utterance 300 s old on a new engine session: nothing', !str_contains($w['echo'], ';do=pick;'), $w['echo']);
// G1: negation parity - "I'm not ready to learn" never confirms "I'm ready to learn"
v1Reset([v1Row("I'm ready to learn.", ['scripted' => 1, 'goodbye' => 1]), v1Row('Tell me more about the Voice.')], 1);
v1Topics('Arngeir V1', ["I'm ready to learn.", 'Tell me more about the Voice.']);
v1Say('Arngeir V1', "I'm not ready to learn");
$r = v1Llm('Arngeir V1', 'T1', 'Then take your time?');
chk('v19 G1 negation parity: "I\'m not ready to learn" (1.000 by score) is NOT explicit for "I\'m ready to learn" - it parks',
    $r['out'] === [] && lrgDlgNegationClash("I'm not ready to learn", ['text' => "I'm ready to learn."]) && !lrgDlgNegationClash("I'm ready to learn", ['text' => "I'm ready to learn."]), json_encode($r['out']));
// S4.3 / S4.10 / model R12: a follower dismissal (and a home) is NEVER explicit - said word for word it still parks and she asks
v1Reset([v1Row("It's time for us to part ways.", ['topic' => 'DialogueFollowerDismissTopic', 'quest' => 'DialogueFollower']),
    v1Row('Wait here.', ['topic' => 'DialogueFollowerWaitTopic', 'quest' => 'DialogueFollower'])], 1);
v1Topics('Lydia V1', ["It's time for us to part ways.", 'Wait here.']);
v1Say('Lydia V1', "It's time for us to part ways.");
$r = v1Llm('Lydia V1', "it's time for us to part ways", 'You want me gone, then?');
chk('v19 a follower dismissal said word for word is NEVER explicit: it parks and she asks (S4.3, S4.10)',
    $r['out'] === [] && (string) ((lrgDlgGet('Lydia V1')['parked'] ?? [])['norm'] ?? '') === lrgPromptNorm("It's time for us to part ways."),
    json_encode($r['out']) . ' parked=' . json_encode(lrgDlgGet('Lydia V1')['parked'] ?? null));

// ------------------------------------------------------------------ v20. the bare yes
head('v20. [pt19 v1.0 / S4.4] the bare yes');
$tw = [v1Row("I'll come along with you.", ['scripted' => 1, 'goodbye' => 1]), v1Row('Not right now.')];
$park = static function (string $said, bool $hint = false) use ($tw): void {
    v1Reset($tw, 1);
    v1Topics('Irileth V1', ["I'll come along with you.", 'Not right now.']);
    v1Say('Irileth V1', 'the watchtower');
    v1Llm('Irileth V1', 'T1', $said);
    if ($hint) { $p = (array) lrgDlgGet('Irileth V1')['parked']; $p['hint'] = 1; lrgDlgPut('Irileth V1', ['parked' => $p]); }
    $GLOBALS['LRG_TEST_NOW'] += 3;
};
$rel = static function (string $say, ?string $item = 'T1'): array { v1Say('Irileth V1', $say); return v1Llm('Irileth V1', $item, 'Good.'); };
$park('You would ride with me to the watchtower, then?');
chk('v20 the parking turn stores what she asked (parked.said)', str_contains((string) (lrgDlgGet('Irileth V1')['parked']['said'] ?? ''), 'watchtower, then?'), json_encode(lrgDlgGet('Irileth V1')['parked'] ?? null));
$r = $rel('yes');
$v1Twin('v20 bare yes question', $r);
chk('v20 her paraphrased QUESTION + "yes" -> release (WillEmit true)', v1Do($r['out']) === 'pick' && $r['will'], json_encode($r['out']));
$park('The watchtower is a long way.');
v1Reset(array_merge($tw, [v1Row('I will think about it.', ['scripted' => 1, 'goodbye' => 1])]), 1);
v1Topics('Irileth V1', ["I'll come along with you.", 'Not right now.', 'I will think about it.']);
v1Say('Irileth V1', 'the watchtower');
v1Llm('Irileth V1', 'T1', 'The watchtower is a long way.');
$GLOBALS['LRG_TEST_NOW'] += 3;
$r = $rel('yes');
$v1Twin('v20 bare yes statement', $r);
chk('v20 her NON-question (two commits on the layer) + "yes" -> stays parked', $r['out'] === [] && !$r['will'] && !empty(lrgDlgGet('Irileth V1')['parked']), json_encode($r['out']));
$park('You would ride with me to the watchtower, then?');
$r = $rel('no');
chk('v20 "no" un-parks', $r['out'] === [] && empty(lrgDlgGet('Irileth V1')['parked']), json_encode(lrgDlgGet('Irileth V1')['parked'] ?? null));
$park('You would ride with me to the watchtower, then?');
$r = $rel('i swear');
chk('v20 "i swear" on a park -> release', v1Do($r['out']) === 'pick', json_encode($r['out']));
$park('You would ride with me to the watchtower, then?');
$r = $rel("yes, I'll do it");
chk('v20 [rev2] her question + "yes, I\'ll do it" -> release (a LEADING phrase)', v1Do($r['out']) === 'pick', json_encode($r['out']));
$park('You would ride with me to the watchtower, then?');
$r = $rel('yes, but not now');
chk('v20 + "yes, but not now" -> un-parked (the refusal is checked first)', $r['out'] === [] && empty(lrgDlgGet('Irileth V1')['parked']), json_encode($r['out']));
foreach (['ok', 'okay', 'OK.', 'Okay, I will'] as $a) {
    $park('You would ride with me to the watchtower, then?');
    $r = $rel($a);
    chk('v20 + "' . $a . '" -> the same outcome (release): ok = okay over normalised tokens', v1Do($r['out']) === 'pick', json_encode($r['out']));
}
foreach (['I do not', 'yeah no', 'okay wait', 'alright stop', 'yes, if you pay me'] as $a) {
    $park('You would ride with me to the watchtower, then?');
    $r = $rel($a);
    chk('v20 A3 tail veto: "' . $a . '" is no assent - nothing released', $r['out'] === [], json_encode($r['out']));
}
// the only-commit rule would release anyway; two commits on the layer make the hint the deciding fact
v1Reset(array_merge($tw, [v1Row("I'll come along later.", ['scripted' => 1, 'goodbye' => 1])]), 1);
v1Topics('Irileth V1', ["I'll come along with you.", 'Not right now.', "I'll come along later."]);
v1Say('Irileth V1', 'the watchtower');
v1Llm('Irileth V1', 'T1', 'Which one do you mean, now or later?');
$p = (array) (lrgDlgGet('Irileth V1')['parked'] ?? []); $p['hint'] = 1; lrgDlgPut('Irileth V1', ['parked' => $p]);
$GLOBALS['LRG_TEST_NOW'] += 3;
$r = $rel('yes');
chk('v20 a park written on a two-candidate-hint turn + "yes" -> stays parked (her "?" asked WHICH)', $r['out'] === [] && !empty(lrgDlgGet('Irileth V1')['parked']), json_encode($r['out']));
$park('You would ride with me to the watchtower, then?');
v1Say('Irileth V1', 'yes');
$r = v1Llm('Irileth V1', null, 'Then we ride.');
chk('v20 F27: "yes" and the model chose NO key - the gate appends the parked pick (mode bare-yes), her words still play',
    v1Do($r['out']) === 'pick' && !$r['will'], json_encode($r['out']));
v1Reset($tw, 1);
v1Topics('Irileth V1', ["I'll come along with you.", 'Not right now.']);
$q = v1Say('Irileth V1', 'the watchtower');
chk('v20 the block text contains "quoting the choice in its own words"', str_contains($q['biz'], 'quoting the choice in its own words'), $q['biz']);
$park('You would ride with me to the watchtower, then?');
v1Say('Irileth V1', 'again', 'rechat');
$r = v1Llm('Irileth V1', 'T1', '');
chk('v20 a rechat (no player turn) never confirms', $r['out'] === [] && !empty(lrgDlgGet('Irileth V1')['parked']), json_encode($r['out']));

// ------------------------------------------------------------------ v21. the single-entry layer
head('v21. [pt19 v1.0 / S4.5] single-entry release: every verified row with its outcome');
$singleTable = [
    ['So what do you need me to do?', ['scripted' => 1], ['So what do you need me to do?', 'what do you need done', "alright, what's the task you need"],
        ['tell me what you need done', 'uh what now', 'I need a drink', 'I need to think', 'what now?']],
    ['What do you need me to do?', ['scripted' => 1], ['What do you need me to do?', 'what do you need', 'alright, what do you need'],
        ['tell me what you need done', 'I need a drink first']],
    ['Oh, do you mean this old stone? (Give Dragonstone to Farengar)', ['scripted' => 1],
        ['do you mean this old stone', 'you mean this old stone', 'oh, this old stone?', 'yes, I have the stone'], ['you mean this stone here', 'this stone is heavy']],
    ['What do these Greybeards want with me?', ['scripted' => 1, 'goodbye' => 1], ['What do these Greybeards want with me?', 'what do they want with me', 'yes, what do they want'],
        ['the Greybeards want me dead?', 'tell me what the Greybeards want', 'yes, kill the Greybeards']],
    ['The Greybeards?', ['walkaway' => 1], ['the Greybeards?', 'who are the Greybeards', 'I hate the Greybeards', 'go on'], ['what do you think of them', '...']],
    ['What else can I help you with?', ['scripted' => 1, 'goodbye' => 1], ['what else can I help with', "yes, I'll help", 'yes', 'okay'],
        ['is there anything else I can do', 'can you help me find a room']],
    ["Thank you. What's next?", ['scripted' => 1], ["Thank you. What's next?", 'what comes next', 'yes, what now', 'ok, what now', 'ok', 'okay'], ['thank you for the horn']],
    ["And that's where I come in?", ['scripted' => 1], ["And that's where I come in?", "so that's where I come in", "yes, I'll do it", 'can I come in'], ['yes, but not now']],
    ['Is that it?', ['scripted' => 1, 'goodbye' => 1], ['is that it', "yes, that's it", 'yes'], ['is that all of it', 'is that a threat', 'yes, and the verse is done', 'the', 'a', 'is that']],
    ['Contract?', ['scripted' => 1], ['contract?', 'what contract?', 'a contract for what', "yes, I'll take the contract"], ['no, I want no part of this']],
    ['"Long live the Emperor! Long live the Empire!"', ['scripted' => 1, 'goodbye' => 1, 'topic' => 'MQ102ALegionOath4'],
        ['"Long live the Emperor! Long live the Empire!"', 'long live the Emperor', 'long live the Empire', 'the empire will live long', 'yes, long live the Emperor',
            'I swear', 'I swear it', 'ok', 'okay', 'yes'],
        ['long live Ulfric', 'long live the Stormcloaks', 'death to the emperor', 'the emperor is a fool', 'hail the Stormcloaks, the true sons of Skyrim',
            'yes, long live Ulfric', 'fine, long live the emperor and death to Ulfric', 'wait, what does that mean?', 'yes, but not now', '...', 'the', 'live']],
    ['"All hail the Stormcloaks, the true sons and daughters of Skyrim!"', ['scripted' => 1, 'topic' => 'MQ102BStormcloakOath4'],
        ['all hail the Stormcloaks', 'hail the Stormcloaks, the true sons of Skyrim', 'I swear it'],
        ['all hail the Empire', 'the Stormcloaks are traitors to Skyrim', 'yes, all hail the Empire', 'long live the Emperor']],
    ['"Upon my honor I do swear undying loyalty to the Emperor..."', ['walkaway' => 1], ['"Upon my honor I do swear undying loyalty to the Emperor..."', 'I swear', 'I swear it', 'go on'],
        ['what does that mean?', '...']],
    ['What does that mean?', [], ['What does that mean?', 'what do you mean by that', 'go on'], ['...']],
    ['What do you have in mind?', ['walkaway' => 1, 'twat' => 'TG00BrynjolfWalkAwayTopic'], ['What do you have in mind?'], ['what do you mean', 'never mind', 'uh what now']],
    ['What do I have to do?', ['walkaway' => 1, 'twat' => 'TG00BrynjolfWalkAwayTopic'], ['What do I have to do?'], ['what must I do', "no, I won't do that"]],
];
foreach ($singleTable as [$tx, $o, $yes, $no]) {
    v1Reset([v1Row($tx, $o)], 1);
    $GLOBALS['LRG_DLG_TEST_OVERRIDES'] = ['entries' => [['match' => ['topic' => 'MQ102BStormcloakOath4'], 'commit' => true]]];
    v1Topics('Single V1', [$tx]);
    $e = (array) ((lrgDlgGet('Single V1')['session']['entries'] ?? [])[0] ?? []);
    $bad = [];
    foreach ($yes as $u) { $rel = lrgDlgSingleEntryRelease($e, $u); if (!$rel['release']) { $bad[] = '+"' . $u . '" ' . $rel['step']; } }
    foreach ($no as $u) { $rel = lrgDlgSingleEntryRelease($e, $u); if ($rel['release']) { $bad[] = '-"' . $u . '" ' . $rel['step']; } }
    chk('v21 "' . substr($tx, 0, 44) . '"' . (!empty($e['commit']) ? ' (COMMIT)' : '') . ': ' . count($yes) . ' release, ' . count($no) . ' do not', $bad === [], implode(' | ', $bad));
}
unset($GLOBALS['LRG_DLG_TEST_OVERRIDES']);
// the paths: fast (want=1), T-key, park
v1Reset([v1Row('So what do you need me to do?', ['scripted' => 1])], 1);
v1Topics('Farengar V1', ['So what do you need me to do?']);
v1Say('Farengar V1', 'what do you need done');
$w = v1Topics('Farengar V1', ['So what do you need me to do?'], ['want' => 1, 'cid' => 'w21a', 'gen' => 2]);
chk('v21 path FAST: "what do you need done" releases the assignment on want=1 (mode single)', str_contains($w['echo'], ';do=pick;') && !str_contains($w['echo'], ';adv='), $w['echo']);
$GLOBALS['LRG_TEST_NOW'] += 5;
v1Say('Farengar V1', 'tell me what you need done');
$w = v1Topics('Farengar V1', ['So what do you need me to do?'], ['want' => 1, 'cid' => 'w21b', 'gen' => 3]);
$r = v1Llm('Farengar V1', 'T1', 'The Dragonstone, from Bleak Falls Barrow.');
$v1Twin('v21 T-key trusted', $r);
chk('v21 path T-KEY: "tell me what you need done" (a statement) - nothing on the fast path, the model\'s key is trusted (not a commit)',
    !str_contains($w['echo'], ';do=pick;') && v1Do($r['out']) === 'pick' && $r['will'], json_encode([$w['echo'], $r['out']]));
v1Reset([v1Row('What do these Greybeards want with me?', ['scripted' => 1, 'goodbye' => 1])], 1);
v1Topics('Balgruuf V1', ['What do these Greybeards want with me?']);
v1Say('Balgruuf V1', 'tell me what the Greybeards want');
$r = v1Llm('Balgruuf V1', 'T1', 'You want to know what the Greybeards want with you?');
$v1Twin('v21 commit single parks', $r);
chk('v21 path PARK: a COMMIT single whose T-key fails steps 0-5 parks and she asks, quoting it', $r['out'] === [] && !$r['will'] && !empty(lrgDlgGet('Balgruuf V1')['parked']), json_encode($r['out']));
v1Say('Balgruuf V1', 'yes');
$r = v1Llm('Balgruuf V1', 'T1', '');
$v1Twin('v21 commit single yes', $r);
chk('v21 ...and "yes" releases it (a single-entry assent)', v1Do($r['out']) === 'pick' && $r['will'], json_encode($r['out']));
v1Reset([v1Row('The Greybeards?', ['walkaway' => 1])], 1);
v1Topics('Balgruuf V1', ['The Greybeards?']);
v1Say('Balgruuf V1', 'what do you think of them?');
$r = v1Llm('Balgruuf V1', 'T1', 'Old men on a mountain.');
$v1Twin('v21 question to a question', $r);
chk('v21 a question to a QUESTION entry is not refused by shape (the model\'s key is trusted)', v1Do($r['out']) === 'pick', json_encode($r['out']));
v1Reset([v1Row('"Upon my honor I do swear undying loyalty to the Emperor..."', ['walkaway' => 1])], 1);
v1Topics('Rikke V1', ['"Upon my honor I do swear undying loyalty to the Emperor..."']);
v1Say('Rikke V1', 'wait, what does that mean?');
$r = v1Llm('Rikke V1', 'T1', 'It means you serve the Empire.');
$sel = array_values(array_filter($r['out'], static fn($l) => str_contains((string) $l, ';do=pick;') && !str_contains((string) $l, ';adv=')));
chk('v21 "wait, what does that mean?" against the oath: the T-key is REFUSED by shape (a question to her is never clicked)', $sel === [] && !$r['will'], json_encode($r['out']));
// the DB02 layer: scoring keeps the SHIPPED list; the strict list decides meaning words
$m = lrgDlgMatchText('tell me who you are', [['norm' => 'who are you'], ['norm' => 'why should i help you'], ['norm' => 'let me out of here']]);
chk('v21 "tell me who you are" on the DB02 layer: 0.783 (the shipped scoring list) and a pick', (float) $m['score'] === 0.783 && $m['i'] === 0
    && lrgDlgMatchPick($m, [['scripted' => 0], [], []], 0.55, 0.15) === 0, json_encode($m));
chk('v21 "who are the Greybeards" -> 0.850 (containment) with the strict shared word "greybeards"',
    (float) lrgDlgMatchText('who are the Greybeards', [['norm' => 'the greybeards']])['score'] === 0.85
    && array_values(array_intersect(lrgPromptWords('who are the greybeards', true), lrgPromptWords('the greybeards', true))) === ['greybeards']);
chk('v21 "who are you" against "who are the greybeards": NO shared word under the strict list', !array_intersect(lrgPromptWords('who are you', true), lrgPromptWords('who are the greybeards', true)));
$mhi = lrgDlgMatchText('hi', [['norm' => 'anything interesting going on in town']]);
chk('v21 [F16] "hi" / "what?" never count as a containment hit ("hi" inside "anything" is no token run at all - fix 1; "is that"'
    . ' inside "is that it" is a SHORT one)', (string) $mhi['tier'] !== 'contain' && (float) $mhi['eff'] < 0.55
    && !empty(lrgDlgMatchText('is that', [['norm' => 'is that it']])['short'])
    && lrgDlgMatchPick(lrgDlgMatchText('what?', [['norm' => 'what have you got for sale'], ['norm' => 'i d like to rent a room']]), [[], []], 0.55, 0.15) === null,
    json_encode($mhi));
chk('v21 [S4.8] lrgDlgMatchText reports f1 = 1.0 on an exact hit ("is that it" said verbatim)', (float) lrgDlgMatchText('is that it', [['norm' => 'is that it']])['f1'] === 1.0);
$fake = ['i' => 0, 'score' => 0.6, 'margin' => 0.6, 'eff' => 0.6, 'f1' => 0.0, 'short' => false, 'tier' => 'trigram', 'j' => null, 'second' => 0.0];
chk('v21 [S4.8] a scripted entry on the similarity path needs a shared meaning word (f1 > 0)',
    lrgDlgMatchPick($fake, [['scripted' => 1]], 0.55, 0.15) === null && lrgDlgMatchPick($fake, [['scripted' => 0]], 0.55, 0.15) === 0);
// 21b: the questline fixture's single beats (Lane E's tools/fixtures/lrg_questline.json). [pt19c final fixer / Lane A test gap] the
// old block read `layer` as a list and so ran over NO beat (the fixture's layer is {kind: single, parent_info}). Now: every single
// beat's target row comes from the prompt index (server/lorerim_glue/data/prompt_index.ndjson - staged beside the tree, as for
// test_questline), and each `never` line runs on the FAST path (lrgDlgSingleEntryRelease: never a release) AND through the gate
// with the target's T-key: on a COMMIT single nothing is clicked; on a plain one a line the fast path refused BY SHAPE (or as a
// refusal) clicks nothing either - any other never line is the model's reach by design (S4.5 step 6). Scene beats run unscened.
$qlf = $root . '/tools/fixtures/lrg_questline.json';
$qli = $root . '/server/lorerim_glue/data/prompt_index.ndjson';
if (is_file($qlf) && is_file($qli)) {
    $qld = json_decode((string) file_get_contents($qlf), true);
    $want21b = [];
    foreach ((array) ($qld['beats'] ?? []) as $beat) {
        $ik = (string) (((array) ($beat['target'] ?? []))['info_key'] ?? '');
        if ((string) (((array) ($beat['layer'] ?? []))['kind'] ?? '') === 'single' && $ik !== '') { $want21b[$ik][] = $beat; }
    }
    $rows21b = [];
    $fh = fopen($qli, 'r');
    while ($fh && ($ln = fgets($fh)) !== false) {
        if (preg_match('/"info_key": "([^"]+)"/', $ln, $mm) && isset($want21b[$mm[1]])) { $rows21b[$mm[1]] = json_decode($ln, true); }
    }
    if ($fh) { fclose($fh); }
    $nFast = 0; $nGate = 0; $nBeats = 0; $bad21b = []; $miss21b = [];
    foreach ($want21b as $ik => $beats) {
        $row = $rows21b[$ik] ?? null;
        if (!is_array($row)) { $miss21b[] = $ik; continue; }
        $fl = (array) ($row['flags'] ?? []);
        $o = ['scripted' => (int) ($row['scripted'] ?? 0), 'goodbye' => (int) ($fl['goodbye'] ?? 0), 'walkaway' => (int) ($fl['walkaway'] ?? 0),
            'invis' => (int) ($fl['invis'] ?? 0), 'kind' => (string) ($row['kind'] ?? ''), 'cost' => (int) ($row['cost'] ?? 0),
            'crit' => (int) ($row['crit'] ?? 0), 'quest' => (string) ($row['quest'] ?? ''), 'topic' => (string) ($row['topic'] ?? ''),
            'twat' => (string) ($row['twat'] ?? ''), 'journal' => (int) ($row['journal'] ?? 0), 'ik' => $ik];
        foreach ($beats as $beat) {
            $nBeats++;
            $tx = (string) ($beat['live_text'] ?? ($row['txt'] ?? ''));
            foreach ((array) ($beat['never'] ?? []) as $u) {
                v1Reset([v1Row($tx, $o)], 1);
                v1Topics('Beat V1', [$tx]);
                $e = (array) ((lrgDlgGet('Beat V1')['session']['entries'] ?? [])[0] ?? []);
                $commit = !empty($e['commit']) || (string) ($e['class'] ?? '') === 'commit';
                $rel = lrgDlgSingleEntryRelease($e, (string) $u);
                $nFast++;
                if (!empty($rel['release'])) { $bad21b[] = $beat['id'] . ' FAST released "' . $u . '" (' . ($rel['step'] ?? '') . ')'; }
                $GLOBALS['LRG_TEST_NOW'] += 5;
                v1Say('Beat V1', (string) $u);
                $r = v1Llm('Beat V1', 'T1', 'Hm.');
                $v1Twin('v21b ' . $beat['id'] . ' never', $r);
                $click = array_values(array_filter($r['out'], static fn($l) => str_contains((string) $l, ';do=pick;') && !str_contains((string) $l, ';adv=')));
                if ($commit || !empty($rel['refused'])) {
                    $nGate++;
                    if ($click) { $bad21b[] = $beat['id'] . ' GATE clicked "' . $u . '" on a ' . ($commit ? 'COMMIT' : 'plain') . ' single (fast: ' . ($rel['step'] ?? '') . ')'; }
                }
            }
        }
    }
    chk('v21b the questline fixture\'s ' . $nBeats . ' single beats (' . count($want21b) . ' targets from the index): ' . $nFast . ' never lines release nothing on the fast path, and '
        . $nGate . ' of them (on a commit single, or refused by shape / as a refusal) click nothing through the gate with the T-key',
        $bad21b === [] && $miss21b === [] && $nBeats >= 20 && $nFast >= 30, implode(' | ', array_merge($bad21b, array_map(static fn($k) => 'no index row ' . $k, $miss21b))));
} else {
    echo "  [pending] v21b needs tools/fixtures/lrg_questline.json and the prompt index (server/lorerim_glue/data/prompt_index.ndjson - copy the live data/ beside the staged tree)\n";
}
// [pt19c final fixer / Lane A test gap] THE MS11 SHAPE (spec 2.2 test 21): "I believe the killer is Wuunferth the Unliving." (plain,
// top-level) clicks on his sentence; the layer that follows is ONE scripted goodbye line, "We have evidence of necromancy, and found
// his amulet." (a COMMIT - it jails a man; the real rows skyrim.esm:023B6A / 025E4A): the SAME sentence re-presented there clicks
// nothing (S4.3 / F8), a question to her releases nothing (S4.5 step 0, shape), and only his SECOND statement releases it
$ms11A = 'I believe the killer is Wuunferth the Unliving.';
$ms11B = 'We have evidence of necromancy, and found his amulet.';
v1Reset([v1Row($ms11A, ['quest' => 'MS11', 'journal' => 1, 'toplevel' => 1, 'topic' => 'MS11JorleifAccusationOfWuunferthTopic']),
    v1Row($ms11B, ['quest' => 'MS11', 'journal' => 1, 'scripted' => 1, 'goodbye' => 1, 'topic' => 'MS11JorleifPresentEvidence'])], 1);
v1Say('Jorleif V1', 'I believe the killer is Wuunferth the Unliving');
$ms1 = v1Topics('Jorleif V1', [$ms11A], ['want' => 1, 'cid' => 'ms11a', 'gen' => 1, 'layer' => 0]);
$GLOBALS['LRG_TEST_NOW'] += 2;
$ms2 = v1Topics('Jorleif V1', [$ms11B], ['want' => 1, 'cid' => 'ms11a', 'gen' => 2, 'layer' => 1]);
$ms11E = (array) ((lrgDlgGet('Jorleif V1')['session']['entries'] ?? [])[0] ?? []);
$GLOBALS['LRG_TEST_NOW'] += 3;
v1Say('Jorleif V1', 'what proof do you want?');
$ms3 = v1Topics('Jorleif V1', [$ms11B], ['want' => 1, 'cid' => 'ms11c', 'gen' => 3, 'layer' => 1]);
$GLOBALS['LRG_TEST_NOW'] += 3;
v1Say('Jorleif V1', 'yes, we found his amulet');
$ms4 = v1Topics('Jorleif V1', [$ms11B], ['want' => 1, 'cid' => 'ms11d', 'gen' => 4, 'layer' => 1]);
chk('v21 [MS11 shape] the accusation clicks on his sentence; on the evidence layer (a COMMIT single) the SAME sentence clicks nothing,'
    . ' "what proof do you want?" releases nothing, and his SECOND statement ("yes, we found his amulet") releases it',
    str_contains($ms1['echo'], ';do=pick;') && !empty($ms11E['commit']) && !str_contains($ms2['echo'], ';do=pick;')
    && !str_contains($ms3['echo'], ';do=pick;') && str_contains($ms4['echo'], ';do=pick;'),
    json_encode([$ms1['echo'], $ms2['echo'], $ms3['echo'], $ms4['echo'], $ms11E['class'] ?? '?']));

// ------------------------------------------------------------------ v22. auto-advance
head('v22. [pt19 v1.0 / S4.6] auto-advance: adv= on the fast path, the re-arm on the next turn');
v1Reset([v1Row('What does that mean?', ['topic' => 'MS05PoemInspection'])], 1);
v1Topics('Viarmo V1', ['What does that mean?']);
$w = v1Topics('Viarmo V1', ['What does that mean?'], ['want' => 1, 'cid' => 'w22a', 'gen' => 2]);
chk('v22 MS05 "What does that mean?" (scripted=0, no new words): do=pick with adv=2500 after z=1', (bool) preg_match('/;do=pick;.*;z=1(;[a-z]+=[^;]*)*;adv=2500\s*$/', trim($w['echo'])), $w['echo']);
chk('v22 ...last_exec stores adv (so the mute never fires on it, F14)', (int) (lrgDlgGet('Viarmo V1')['last_exec']['adv'] ?? -1) === 2500);
v1Reset([v1Row('Go on.', [])], 1);
v1Topics('Viarmo V1', ['Go on.']);
$w = v1Topics('Viarmo V1', ['Go on.'], ['want' => 1, 'cid' => 'w22b', 'gen' => 2]);
chk('v22 a continuer entry ("Go on."): adv=0', str_contains($w['echo'], ';adv=0'), $w['echo']);
foreach ([['So what do you need me to do?', ['scripted' => 1], 'a scripted single'], ['Farewell.', ['goodbye' => 1], 'a goodbye single'],
    ['Pay me. (10 gold)', ['cost' => 10], 'a priced single'], ['I dare you. (Persuade)', ['kind' => 'persuade'], 'a check single'],
    ['A secret line.', ['invis' => 1], 'an Invisible Continue single (graded scripted, U2)']] as [$tx, $o, $lab]) {
    v1Reset([v1Row($tx, $o)], 1);
    v1Topics('Viarmo V1', [$tx]);
    $w = v1Topics('Viarmo V1', [$tx], ['want' => 1, 'cid' => 'w22c', 'gen' => 2]);
    chk('v22 ' . $lab . ': no auto-advance', !str_contains($w['echo'], ';adv='), $w['echo']);
}
v1Reset([v1Row('The Greybeards?', ['walkaway' => 1])], 1);
v1Topics('Balgruuf V1', ['The Greybeards?']);
v1Say('Balgruuf V1', 'no, stop');
$w = v1Topics('Balgruuf V1', ['The Greybeards?'], ['want' => 1, 'cid' => 'w22d', 'gen' => 2]);
chk('v22 a NEW refusal suppresses the auto-advance (model F13)', $w['echo'] === '', $w['echo']);
$GLOBALS['LRG_TEST_NOW'] += 10;
v1Say('Balgruuf V1', 'what do you think of them');
$r = v1Llm('Balgruuf V1', null, 'Old men who speak with the Voice.');
chk('v22 a question on the layer, no pick emitted: the SAME reply carries do=pick;...;adv=2500;rearm=1 (the CHIM anchor)',
    count($r['out']) === 1 && str_contains((string) $r['out'][0], ';adv=2500;rearm=1'), json_encode($r['out']));
$GLOBALS['LAST_LLM_RESPONSE'] = ['action' => '', 'item' => '', 'message' => 'Old men.'];
chk('v22 ...her answer is NOT muted (an adv pick is never WillEmit, F14)', lrgDlgTransformer('Old men.') === 'Old men.');
v1Reset([v1Row('"Upon my honor I do swear undying loyalty to the Emperor..."', ['walkaway' => 1])], 1);
v1Topics('Rikke V1', ['"Upon my honor I do swear undying loyalty to the Emperor..."']);
v1Say('Rikke V1', 'what does that mean?');
$r = v1Llm('Rikke V1', 'T1', 'That you serve the Emperor, with your life.');
chk('v22 a T-key refused by SHAPE also re-arms (else the oath stalls after one question)', count($r['out']) === 1 && str_contains((string) $r['out'][0], ';rearm=1'), json_encode($r['out']));
v1Say('Rikke V1', 'again', 'rechat');
$r = v1Llm('Rikke V1', null, 'Well?');
chk('v22 a rechat never re-arms (a player speech turn only, CHIM brief P12)', $r['out'] === [], json_encode($r['out']));
v1Say('Rikke V1', 'no, I refuse');
$r = v1Llm('Rikke V1', null, 'As you wish.');
chk('v22 a refusal never re-arms', $r['out'] === [], json_encode($r['out']));
// model row 24 re-arms only on "none found, or a T-key refused by shape": a LEAVE on a single walk-away layer (the oath lines
// carry the flag and no back line) is the leave guard (do=show kind=back, her words say leaving is his) and never re-arms
$GLOBALS['LRG_TEST_NOW'] += 5;
v1Say('Rikke V1', 'I have to go now');
$r = v1Llm('Rikke V1', 'LEAVE', 'Then go, if you must.');
chk('v22 LEAVE on a single walk-away layer: the leave guard (do=show kind=back) and NO re-arm', count($r['out']) === 1
    && str_contains((string) $r['out'][0], ';do=show;') && str_contains((string) $r['out'][0], ';kind=back;')
    && !str_contains(implode('', $r['out']), ';adv='), json_encode($r['out']));

// ------------------------------------------------------------------ v23. two candidates must agree; the utterance guard
head('v23. [pt19 v1.0 / S4.7, S4.3 rev2] two candidates must agree; the words that may act');
$ag = [v1Row('I need work.'), v1Row('Tell me about Whiterun.'), v1Row('Never mind.')];
$agL = ['I need work.', 'Tell me about Whiterun.', 'Never mind.'];
v1Reset($ag, 1);
v1Topics('Ysolda V1', $agL);
v1Say('Ysolda V1', 'tell me about Whiterun');
$m = v1LogMark();
$w = v1Topics('Ysolda V1', $agL, ['want' => 1, 'ask' => 'I need work', 'cid' => 'w23a', 'gen' => 2]);
chk('v23 ask= and his words both clear on DIFFERENT entries: nothing, log "want=1 ambiguous model=0 player=1"', $w['echo'] === ''
    && str_contains(v1LogFrom($m), 'want=1 ambiguous model=0 player=1'), v1LogFrom($m));
$w = v1Topics('Ysolda V1', $agL, ['want' => 1, 'ask' => 'Whiterun', 'cid' => 'w23b', 'gen' => 3]);
chk('v23 ...one candidate (they agree): that one', str_contains($w['echo'], ';pos=1;'), $w['echo']);
v1Reset($ag, 1);
v1Say('Ysolda V1', 'tell me about Whiterun');
$GLOBALS['LRG_TEST_NOW'] += 300;
$w = v1Topics('Ysolda V1', $agL, ['want' => 1, 'cid' => 'w23c', 'sid' => 's9']);
chk('v23 a 300 s-old sentence on an E-press session asserts NOTHING (model F7)', $w['echo'] === '', $w['echo']);
$w = v1Topics('Ysolda V1', $agL, ['want' => 1, 'cid' => 'w23d', 'sid' => 's9', 'gen' => 2, 'ask' => 'work']);
chk('v23 ...while ask= on its own list still picks', str_contains($w['echo'], ';pos=0;'), $w['echo']);
v1Reset($ag, 1);
v1Say('Ysolda V1', 'tell me about Whiterun');
lrgDlgPut('Ysolda V1', ['parked' => ['norm' => 'x', 'expires' => lrgNow() + 60]]);
$GLOBALS['LRG_TEST_NOW'] += 2;
$w = v1Topics('Ysolda V1', $agL, ['want' => 1, 'cid' => 'w23e', 'hc' => 1]);
chk('v23 hc=1 (he clicked by hand): his older sentence and the park are inert on the layer he chose (model F9)',
    $w['echo'] === '' && empty(lrgDlgGet('Ysolda V1')['parked']), $w['echo']);
$days = ['1 day. (25 gold)', '2 days. (50 gold)', '3 days. (75 gold)', '7 days. (175 gold)'];
v1Reset(array_merge([v1Row("I'd like to rent a room.", ['toplevel' => 1]), v1Row('What have you got for sale?', ['toplevel' => 1, 'scripted' => 1])],
    array_map(static fn($d) => v1Row($d, ['cost' => lrgPromptCost($d)]), $days)), 1);
v1Snap('Hulda V1', ['fac' => 'JobInnkeeperFaction', 'class' => '']);
v1Topics('Hulda V1', ["I'd like to rent a room.", 'What have you got for sale?'], ['layer' => 0, 'gen' => 0]);
$q = v1Say('Hulda V1', "I'd like a room for the night");
$w = v1Topics('Hulda V1', ["I'd like to rent a room.", 'What have you got for sale?'], ['want' => 1, 'cid' => (string) $q['t']['cid'], 'layer' => 0, 'gen' => 1]);
chk('v23 [U3] "I\'d like a room for the night": the room line by KIND on the root', str_contains($w['echo'], ';do=pick;') && str_contains($w['echo'], ';pos=0;'), $w['echo']);
$GLOBALS['LRG_TEST_NOW'] += 3;
$w = v1Topics('Hulda V1', $days, ['want' => 1, 'cid' => (string) $q['t']['cid'], 'layer' => 1, 'gen' => 2, 'pg' => 300]);
chk('v23 ...and the SAME sentence names "1 day" on the days list that click produced (one sentence, two clicks)',
    str_contains($w['echo'], ';do=pick;') && str_contains($w['echo'], ';pos=0;') && str_contains($w['echo'], ';cost=25;'), $w['echo']);
$GLOBALS['LRG_TEST_NOW'] += 3;
$w = v1Topics('Hulda V1', ['Yes, that will do.', 'No.'], ['want' => 1, 'cid' => (string) $q['t']['cid'], 'layer' => 2, 'gen' => 3]);
chk('v23 ...but never a third layer (the chain is price lists only, depth 1)', $w['echo'] === '', $w['echo']);

// ------------------------------------------------------------------ v24. the stage rail
head('v24. [pt19 v1.0 / S3.3] the stage rail (probation)');
$sr = [v1Row('What have you got for sale?', ['scripted' => 1]), v1Row('Tell me about the war.'), v1Row('Never mind.')];
v1Reset($sr, 0);
v1Topics('Ysolda V1', ['What have you got for sale?', 'Tell me about the war.', 'An unindexed line.', 'Never mind.']);
$q = v1Say('Ysolda V1', 'tell me about the war');
// [pt19c fixer / QA wording] the example is a line that CAN be picked (T2), never "a question" (the blocked line may be one)
chk('v24 clicks_ok 0: ONE line naming the keys that may be picked (T2, T4), never per-entry labels, with a pickable example',
    str_contains($q['biz'], 'Until he has asked me something simple I can only pick T2, T4; for anything else say: I have not picked a line for you yet - ask me something simple first, like "Tell me about the war.", then I can pick this one')
    && substr_count($q['biz'], 'I have not picked a line') === 1, $q['biz']);
chk('v24 ...the line carries no closed-list word (menu / click / gate)', !preg_match('/\b(menu|click|gate)\b/i', (string) (preg_match('/Until he has asked[^\n]*/', $q['biz'], $mm) ? $mm[0] : 'x menu')));
$m = v1LogMark();
$r = v1Llm('Ysolda V1', 'T1', 'Take a look.');
$v1Twin('v24 rail scripted', $r);
$sel = array_values(array_filter($r['out'], static fn($l) => str_contains((string) $l, ';do=pick;')));
$note = array_values(array_filter($r['out'], static fn($l) => str_contains((string) $l, ';do=noop;')));
chk('v24 a scripted key: no pick, "gate: stage rail", the corner note once (kind=rail: bQuestHint cannot silence it), WillEmit false', $sel === [] && count($note) === 1 && !$r['will']
    && str_contains((string) $note[0], ';kind=rail;')
    && str_contains(v1LogFrom($m), 'gate: stage rail'), json_encode($r['out']));
v1Say('Ysolda V1', 'what about that line');
$r = v1Llm('Ysolda V1', 'T3', 'Hm.');
$v1Twin('v24 rail unindexed', $r);
chk('v24 an UNINDEXED key: no pick (and no second corner note this session)', !array_filter($r['out'], static fn($l) => str_contains((string) $l, ';do=pick;') || str_contains((string) $l, ';do=noop;')), json_encode($r['out']));
v1Say('Ysolda V1', 'tell me about the war');
$r = v1Llm('Ysolda V1', 'T2', 'It goes badly.');
$v1Twin('v24 rail plain', $r);
chk('v24 an indexed PLAIN key: picked under the rail', v1Do($r['out']) === 'pick' && $r['will'], json_encode($r['out']));
v1Msg('lrg_dlg', 'Ysolda V1', 'ev=result;npc=Ysolda V1;sid=s1;gen=1;x=abc;pos=1;i=101;kind=plain;ok=1;txt=Tell me about the war.');
chk('v24 an ok result: clicks_ok 1', lrgDlgClicksOk() === 1);
$q = v1Say('Ysolda V1', 'what have you got for sale');
$r = v1Llm('Ysolda V1', 'T1', 'Have a look.');
$v1Twin('v24 after first click', $r);
chk('v24 clicks_ok 1: every key (the rail line is gone)', v1Do($r['out']) === 'pick' && !str_contains($q['biz'], 'Until he has asked'), json_encode($r['out']));
v1Msg('lrg_dlg', 'Ysolda V1', 'ev=calib;v=1;ref=00000000;npc=Testplayer;src=forget;gate=0;reset=1;miss=cm rm fam st;k=cm:0');
chk('v24 ev=calib reset=1 -> clicks_ok 0 again', lrgDlgClicksOk() === 0);
v1Reset([v1Row('Stand aside, or else. (Intimidate)', ['kind' => 'intimidate']), v1Row('I have news from Helgen about the dragon attack. (Persuade)', ['kind' => 'persuade']), v1Row('Farewell.', ['goodbye' => 1])], 0);
v1Topics('Guard V1', ['Stand aside, or else. (Intimidate)', 'I have news from Helgen about the dragon attack. (Persuade)', 'Farewell.']);
$q = v1Say('Guard V1', 'let me through');
chk('v24 [U7] no key can pass the rail (the Whiterun gate guard): the honest sentence instead', str_contains($q['biz'], 'I cannot pick any of these for you yet - choose this one yourself, this once')
    && !str_contains($q['biz'], 'ask me something simple first'), $q['biz']);

// ------------------------------------------------------------------ v25. the pre-LLM open
head('v25. [pt19 v1.0 / S2.1] the pre-LLM open: the narrow marker, clause by clause');
$huldaRoot = ['What have you got for sale?' => ['toplevel' => 1, 'scripted' => 1, 'quest' => 'DialogueGeneric'],
    "I'd like to rent a room. (10 gold)" => ['toplevel' => 1, 'cost' => 10, 'quest' => 'DialogueGeneric'],
    // [pt19c-A fix 2] the live index's topic editor ids (clause 4 asks whether HER list can carry the row: More to Say names her)
    'Nice inn you have here. Do you get many visitors?' => ['toplevel' => 1, 'quest' => 'ACFDialogueWhiterun', 'ik' => 'moretosaywhiterun.esp:000940',
        'topic' => 'ACFDialogueWhiterunHuldaBranchChatTopic'],
    'Heard any rumors lately?' => ['toplevel' => 1, 'scripted' => 1, 'quest' => 'DarkBrotherhood', 'topic' => 'DBRumorsTopic']];
$rows25 = [];
foreach ($huldaRoot as $tx => $o) { $rows25[] = v1Row($tx, $o); }
$rows25[] = v1Row('Where can I get a drink?', ['toplevel' => 1, 'scripted' => 1, 'quest' => 'DialogueWhiterun', 'journal' => 0, 'ik' => 'skyrim.esm:0CD075',
    'topic' => 'DialogueWhiterunJonTopic3BranchTopic']);
$rows25[] = v1Row("I'd like to rent the attic room. (<Global=RoomCost> gold)", ['toplevel' => 1, 'scripted' => 1, 'goodbye' => 1, 'quest' => 'MQ106', 'journal' => 1, 'ik' => 'skyrim.esm:0453EE']);
$rows25[] = v1Row('I have your shield.', ['toplevel' => 1, 'scripted' => 1, 'goodbye' => 1, 'quest' => 'C00', 'journal' => 1, 'ik' => 'skyrim.esm:0A3E85']);
$rows25[] = v1Row('What are your orders?', ['toplevel' => 1, 'goodbye' => 1, 'quest' => 'MQ104', 'journal' => 1, 'ik' => 'skyrim.esm:05DD51']);
$rows25[] = v1Row('Who are you?', ['toplevel' => 1, 'quest' => 'DB02', 'journal' => 1]);
$p25 = static function (string $npc, string $say, array $snap = []): array {
    if ($snap) { v1Snap($npc, $snap); }
    $m = v1LogMark();
    $q = v1Say($npc, $say);
    $log = v1LogFrom($m);
    $cl = preg_match('/open marker=([a-z]+) row=(\S+)/', $log, $mm) ? $mm[1] : '';
    return ['clause' => !empty($q['t']['open_pending']) ? $cl : '', 'row' => (string) ($mm[2] ?? ''), 'q' => $q, 'log' => $log];
};
$inn = ['fac' => 'JobInnkeeperFaction,TownWhiterunFaction', 'class' => ''];
v1Reset($rows25, 1);
lrgDlgPut('Hulda V1', ['facts' => ['sq' => 'DialogueWhiterunBanneredMareScene3', 'sq_at' => lrgNow(), 'sqj' => 0, 'at' => lrgNow()], 'q' => ['ACFWhiterunVampires', 'WITavern', 'BQ01', 'DialogueWhiterun']]);
$x = $p25('Hulda V1', 'Nice inn you have here, do you get many visitors?', $inn + ['scene' => '1']);
chk('v25 Hulda COLD (in her tavern scene) "Nice inn you have here, do you get many visitors?" -> marker=toplevel row=moretosaywhiterun.esp:000940',
    $x['clause'] === 'toplevel' && $x['row'] === 'moretosaywhiterun.esp:000940', $x['log']);
chk('v25 ...the open is D2 only, ask= carries his words, amb=1 (an ambient actor), the bridging line rides this turn (<= 220 chars)',
    count($x['q']['queue']) === 1 && str_contains((string) $x['q']['queue'][0]['action'], ';do=open;') && str_contains((string) $x['q']['queue'][0]['action'], ';amb=1')
    && (bool) preg_match('/The list of what he can raise[^\n]*/', $x['q']['biz'], $bm) && strlen($bm[0]) <= 220, json_encode([$x['q']['queue'], $x['q']['biz']]));
$GLOBALS['LRG_TEST_NOW'] += 5;
$x2 = $p25('Hulda V1', 'hello again');
chk('v25 ...and the bridging line is ABSENT on the next turn', !str_contains($x2['q']['biz'], 'being brought up'), $x2['q']['biz']);
foreach ([['what have you got?', 'kind'], ["I'd like a room", 'kind'], ['I need a bed', 'kind'], ['uh some beer', ''], ['I need a drink', ''],
    ['can I buy you a drink', ''], ['what have you got against the Stormcloaks', ''], ["I don't need a room", '']] as [$say, $want]) {
    lrgDlgPut('Hulda V1', ['open_pending' => null]);
    $GLOBALS['LRG_TEST_NOW'] += 5;
    $x = $p25('Hulda V1', $say);
    chk('v25 Hulda COLD "' . $say . '" -> ' . ($want !== '' ? 'marker=' . $want : 'NO open'), $x['clause'] === $want, $x['clause'] . ' ' . substr($x['log'], 0, 300));
}
// "where can I get a drink" is a VERBATIM top-level prompt (Jon Battle-Born's, journal=0) with 2 strict words (get, drink). The
// spec's test row (and FE.hulda.open) says NO row: [pt19c-A fix 1] clause 4 needs open.toplevel_min_words (3) strict words
// and a line at most open.toplevel_max_topics (5) topics carry ([fix 2] and a row HER list can carry: Jon's row of the shared
// DialogueWhiterun is not); clause 5 never takes a journal=0 row.
$GLOBALS['LRG_TEST_NOW'] += 5;
$x = $p25('Hulda V1', 'where can I get a drink');
chk('v25 "where can I get a drink" -> NO row (clause 4: 2 strict words < 3; clause 5 never takes a journal=0 row)',
    $x['clause'] === '' && !array_filter(lrgPromptRowsForQuests(['DialogueWhiterun'], 300), static fn($r) => (string) $r['norm'] === 'where can i get a drink'), $x['log']);
v1Topics('Hulda V1', array_keys($huldaRoot), ['layer' => 0, 'gen' => 0]);
v1Msg('lrg_dlg', 'Hulda V1', 'ev=closed;npc=Hulda V1;sid=s1;why=goodbye;pending=0;layer=0');
$GLOBALS['LRG_TEST_NOW'] += 5;
$x = $p25('Hulda V1', 'Nice inn you have here, do you get many visitors?');
chk('v25 Hulda WARM (root cached by an E-press) "Nice inn..." -> marker=root', $x['clause'] === 'root', $x['log']);
$GLOBALS['LRG_TEST_NOW'] += 5;
$x = $p25('Hulda V1', 'hi');
chk('v25 [F16] "hi" to warm Hulda -> NO open (a short containment never counts)', $x['clause'] === '', $x['log']);
lrgDlgPut('Delphine V1', ['q' => ['MQ106']]);
$x = $p25('Delphine V1', 'nice weather', $inn);
chk('v25 Delphine (q=MQ106) + "nice weather" -> none', $x['clause'] === '', $x['log']);
$GLOBALS['LRG_TEST_NOW'] += 5;
$x = $p25('Delphine V1', "I'd like the attic room");
chk('v25 Delphine + "I\'d like the attic room" -> marker=qrows row=skyrim.esm:0453EE (0.907, 3 shared strict words)', $x['clause'] === 'qrows' && $x['row'] === 'skyrim.esm:0453EE', $x['log']);
chk('v25 ...her journal rows are cached (one index read per session) in a row of their own, never in her state row (fix 1)',
    (string) (lrgDlgGet(lrgDlgQRowsKey('Delphine V1'))['qrows']['sig'] ?? '') === 'mq106' && !isset(lrgDlgGet('Delphine V1')['qrows']));
lrgDlgPut('Aela V1', ['q' => ['C00']]);
$x = $p25('Aela V1', 'I have your shield', ['class' => '']);
chk('v25 [U5] Aela (q C00) "I have your shield" -> marker=qrows (an exact journal line, 1 shared word)', $x['clause'] === 'qrows', $x['log']);
lrgDlgPut('Irileth V1', ['q' => ['MQ104']]);
$x = $p25('Irileth V1', 'what are your orders', ['class' => '']);
chk('v25 [U5] Irileth (MQ104) "what are your orders" -> marker=qrows', $x['clause'] === 'qrows', $x['log']);
$GLOBALS['LRG_TEST_NOW'] += 5;
$x = $p25('Hulda V1', 'who are you');
chk('v25 [U5] Hulda "who are you" -> none (no strict meaning word, not her journal row)', $x['clause'] === '', $x['log']);
lrgDlgPut('Lydia V1', ['q' => ['HousecarlWhiterun']]);
$x = $p25('Lydia V1', 'what do you think about the war', ['class' => '']);
chk('v25 Lydia + "what do you think about the war" -> none', $x['clause'] === '', $x['log']);
v1Topics('Hulda V1', array_keys($huldaRoot), ['layer' => 0, 'gen' => 5, 'sid' => 's5']);
$x = $p25('Hulda V1', 'what have you got?');
chk('v25 an OPEN session -> no pre-LLM open', $x['clause'] === '' && $x['q']['queue'] === [], $x['log']);
v1Msg('lrg_dlg', 'Hulda V1', 'ev=closed;npc=Hulda V1;sid=s5;why=goodbye;pending=0;layer=0');
v1Snap('Hulda V1', $inn); // out of her tavern scene from here: the guard tests below are about the guards, not the scene
lrgDlgPut('Hulda V1', ['facts' => ['quiet' => 1, 'quiet_at' => lrgNow(), 'at' => lrgNow()]]);
$x = $p25('Hulda V1', 'what have you got?');
chk('v25 [F17] quiet=1 (a curated intro runs) -> no open', $x['clause'] === '', $x['log']);
lrgDlgPut('Hulda V1', ['facts' => ['quiet' => 0, 'at' => lrgNow()], 'root' => null]);
lrgDlgPut('*install*', ['clicks_ok' => 0]);
$x = $p25('Hulda V1', 'what have you got?');
chk('v25 [F19] clicks_ok 0 + "what have you got?" cold -> no open (the rail would refuse the service line): 10.15\'s net instead',
    $x['clause'] === '' && !empty($x['q']['t']['svc']['direct']), json_encode($x['q']['t']['svc'] ?? null));
lrgDlgPut('*install*', ['clicks_ok' => 1]);
chk('v25 [F18] an active voice order (lrgMktState queued) -> the kind clause stands down', !lrgDlgKindMayOpen(['buy' => ['state' => 'queued'], 'snap' => $inn], 'barter')
    && lrgDlgKindMayOpen(['buy' => ['state' => ''], 'snap' => $inn], 'barter'));
lrgDlgPut('Hulda V1', ['open_refused' => ['why' => 'a quest scene is running', 'at' => lrgNow()]]);
$x = $p25('Hulda V1', 'what have you got?');
chk('v25 [S2.3] the game refused the last open within 120 s: no open, the direct-barter net comes back', $x['clause'] === '' && !empty($x['q']['t']['svc']['direct']),
    json_encode($x['q']['t']['svc'] ?? null));
lrgDlgPut('Hulda V1', ['open_refused' => null]);
$GLOBALS['LRG_TEST_NOW'] += 5;
$x = $p25('Hulda V1', 'what have you got?');
$t25 = (array) $GLOBALS['LRG_DLG_TURN'];
chk('v25 open_pending blocks the gate\'s own open (no second do=open from the model\'s words)', lrgDlgMaybeOpen($t25, 'show me your wares') === null, $x['log']);
$m = v1LogMark();
$r = v1Llm('Hulda V1', 'T1', 'Let me see.');
$v1Twin('v25 held key', $r);
chk('v25 [F15] the reply\'s key before the list answered: HELD, WillEmit false', $r['out'] === [] && !$r['will'] && str_contains(v1LogFrom($m), 'held - the open for this sentence decides'), v1LogFrom($m));
lrgDlgPut('Hulda V1', ['want_none' => ['cid' => (string) $t25['cid'], 'at' => lrgNow()]]);
chk('v25 ...and gated normally once the fast path answered this cid with nothing (want_none)', lrgDlgOpenTurnHold($t25, lrgDlgGet('Hulda V1')) === '');
lrgDlgPut('Hulda V1', ['last_exec' => ['do' => 'pick', 'cid' => (string) $t25['cid'], 'adv' => -1, 'at' => lrgNow()]]);
chk('v25 ...and DROPPED when the fast pick for this sentence already landed', str_contains(lrgDlgOpenTurnHold($t25, lrgDlgGet('Hulda V1')), 'already landed'));
$GLOBALS['LAST_LLM_RESPONSE'] = ['action' => '', 'item' => '', 'message' => 'Have a look.'];
chk('v25 the transformer mutes her bridging line when the fast pick landed in time (the fresh last_exec)', lrgDlgTransformer('Have a look.') === '');
lrgDlgPut('Hulda V1', ['last_exec' => ['do' => 'open', 'cid' => (string) $t25['cid'], 'at' => lrgNow()]]);
chk('v25 ...and NOT when late (the pick has not landed)', lrgDlgTransformer('Have a look.') === 'Have a look.');
chk('v25 talk_again false: lrg_dlgtalk is answered pre-lock, no LLM', v1Msg('lrg_dlgtalk', 'Hulda V1', 'again')['res'] === 'handled');
foreach ([['Lisette V1', 'come with me', ['class' => '', 'fac' => 'TownWhiterunFaction']], ['Guard V1', 'let me go', ['fac' => 'IsGuardFaction,CrimeFactionWhiterun', 'class' => '']],
    ['Guard V1', 'do you know who I am', ['fac' => 'IsGuardFaction', 'class' => '']], ['Guard V1', 'take me to the jarl', ['fac' => 'IsGuardFaction', 'class' => '']],
    ['Guard V1', 'what have you got?', ['fac' => 'IsGuardFaction,CrimeFactionWhiterun', 'class' => '']]] as [$who, $say, $sn]) {
    $GLOBALS['LRG_TEST_NOW'] += 5;
    $x = $p25($who, $say, $sn);
    chk('v25 [U1] ' . $who . ' + "' . $say . '" -> no open', $x['clause'] === '', $x['log']);
}
$GLOBALS['LRG_DLG_TEST_CFG'] = ['open.kind_factions.carriage' => ['CarriageDriverFaction']];
$x = $p25('Bjorlam V1', 'take me to Whiterun', ['fac' => 'CarriageDriverFaction', 'class' => '']);
chk('v25 [U1] a driver whose job faction is verified + "take me to Whiterun" -> marker=kind', $x['clause'] === 'kind', $x['log']);
unset($GLOBALS['LRG_DLG_TEST_CFG']);
// [pt19c final fixer / U1] the SHIPPED kind_factions (verified in the plugins): a driver, a ferryman, a trainer open pre-LLM on their
// kind; the same sentences to a guard, a farmer or a dog trainer do not
foreach ([['Bjorlam V1', 'take me to Whiterun', 'CarriageSystemFaction,TownWhiterunFaction', 'kind'],
    ['Kmod Driver V1', 'take me to Whiterun', 'KmodCarriageFreeFaction', 'kind'],
    ['Gjalund V1', 'take me across', 'KmodFerryRoute4Faction', 'kind'], ['Ferryman V1', 'take me across', 'DLC1FerrySystemFaction', 'kind'],
    ['Aela V1', 'can you train me', 'CompanionsFaction,JobTrainerFaction,JobTrainerMarksmanFaction', 'kind'],
    ['Guard V1', 'take me to Whiterun', 'IsGuardFaction,CrimeFactionWhiterun', ''], ['Farmer V1', 'take me across', 'TownRiverwoodFaction', ''],
    ['Banning V1', 'can you train me', 'JobAnimalTrainerFaction', '']] as [$who, $say, $fac, $want]) {
    $GLOBALS['LRG_TEST_NOW'] += 5;
    $x = $p25($who, $say, ['fac' => $fac, 'class' => '']);
    chk('v25 [U1, shipped kind_factions] ' . $who . ' (' . $fac . ') + "' . $say . '" -> ' . ($want === '' ? 'no open' : 'marker=kind'), $x['clause'] === $want, $x['log']);
}

// [pt19c final fixer / clause-4/5 name-word proxy] a rank or a bare role is no name: "General Tullius" no longer claims
// RoriksteadFreeformErikGeneralTopic2Topic ("Have you lived here all your life?", the real index row) as his list's line
$nwWant = ['General Tullius' => 'tullius', 'Captain Aldis' => 'aldis', 'Commander Maro' => 'maro', 'Legate Rikke' => 'rikke',
    'Priest of Arkay' => '', 'Hunter' => '', 'Balgruuf the Greater' => 'balgruuf', 'Whiterun Guard' => 'guard', 'Eorlund Gray-Mane' => 'eorlund'];
$nwGot = [];
foreach ($nwWant as $who => $w) { $nwGot[$who] = lrgDlgNameWord($who); }
chk('v25 [final fixer] the name word skips a rank and a bare role (General / Captain / Commander / Legate / Priest of Arkay / Hunter)', $nwGot === $nwWant, json_encode($nwGot));
$erikRow = ['topic' => 'RoriksteadFreeformErikGeneralTopic2Topic', 'quest' => 'RoriksteadFreeform', 'journal' => 1, 'toplevel' => 1];
chk('v25 [final fixer] ...so Erik\'s "Have you lived here all your life?" row is not General Tullius\'s (no false clause-4 open), and a row naming him still is',
    !lrgDlgRowIsHers($erikRow, lrgDlgNameWord('General Tullius'), ['cw00a'])
    && lrgDlgRowIsHers(['topic' => 'CW00TulliusGreetTalkRikkateAP', 'quest' => 'CW00A', 'journal' => 0], lrgDlgNameWord('General Tullius'), []));

// ------------------------------------------------------------------ v26. ambient scene actors
head('v26. [pt19 v1.0 / S2.2] ambient scene actors open on the SAME narrow clauses (amb=1)');
v1Reset($rows25, 1);
lrgDlgPut('Belethor V1', ['facts' => ['sq' => 'WISandbox', 'sq_at' => lrgNow(), 'sqj' => 0, 'at' => lrgNow()]]);
$x = $p25('Belethor V1', 'what do you sell', ['scene' => '1', 'fac' => 'JobMerchantFaction', 'class' => '']);
chk('v26 Belethor (scene=1 sq=WISandbox sqj=0) + "what do you sell" -> do=open amb=1 marker=kind', $x['clause'] === 'kind'
    && str_contains((string) ($x['q']['queue'][0]['action'] ?? ''), ';amb=1'), json_encode($x['q']['queue']));
lrgDlgPut('Hulda V1', ['facts' => ['sq' => 'DialogueWhiterunBanneredMareScene3', 'sq_at' => lrgNow(), 'sqj' => 0, 'at' => lrgNow()]]);
$x = $p25('Hulda V1', 'what have you got?', $inn + ['scene' => '1']);
chk('v26 Hulda (no glob, no journal row) + "what have you got?" -> do=open amb=1', $x['clause'] === 'kind' && str_contains((string) ($x['q']['queue'][0]['action'] ?? ''), ';amb=1'), $x['log']);
v1Reset(array_merge($rows25, [v1Row('Stay close to me.', ['quest' => 'MQ101', 'journal' => 1])]), 1);
lrgDlgPut('Hadvar V1', ['facts' => ['sq' => 'MQ101', 'sq_at' => lrgNow(), 'sqj' => 1, 'at' => lrgNow()]]);
$x = $p25('Hadvar V1', 'what have you got?', ['scene' => '1', 'class' => '']);
chk('v26 Helgen (sq=MQ101 sqj=1) -> no open, the module is off (D-17)', $x['clause'] === '' && empty($x['q']['t']['on']), json_encode($x['q']['t']['why'] ?? []));
lrgDlgPut('Legate Rikke', ['facts' => ['sq' => 'CW00SolitudeMapTableScene', 'sq_at' => lrgNow(), 'sqj' => 0, 'at' => lrgNow()]]);
$x = $p25('Legate Rikke', 'what do you sell', ['scene' => '1', 'fac' => 'CWImperialFaction,CWFieldCOFaction', 'class' => '']);
chk('v26 Rikke + "what do you sell" -> no open (no vendor faction)', $x['clause'] === '', $x['log']);
lrgDlgPut('General Tullius', ['facts' => ['sq' => 'CW00SolitudeMapTableScene', 'sq_at' => lrgNow(), 'sqj' => 0, 'mq101c' => 0, 'at' => lrgNow()]]);
$x = $p25('General Tullius', 'I want to join the Legion', ['scene' => '1', 'fac' => 'CWImperialFaction', 'class' => '']);
chk('v26 Tullius BEFORE Helgen (the road closed) + a join ask -> none (the road rule)', $x['clause'] === '', $x['log']);
$GLOBALS['LRG_TEST_NOW'] += 200;
lrgDlgPut('General Tullius', ['facask' => null, 'facts' => ['sq' => 'CW00SolitudeMapTableScene', 'sq_at' => lrgNow(), 'sqj' => 0, 'mq101c' => 1, 'at' => lrgNow()]]);
$x = $p25('General Tullius', 'I want to join the Legion', ['scene' => '1', 'fac' => 'CWImperialFaction', 'class' => '']);
$plan = (string) ((($x['q']['t']['faction'] ?? [])['plan'] ?? [])['state'] ?? '');
chk('v26 Tullius AFTER Helgen + a join ask: marker=join amb=1 - or, until Lane B\'s S2.3 condition lands, 10.26\'s queued quest entry answers it',
    $x['clause'] === 'join' || $plan === 'queued', $x['clause'] . ' plan=' . $plan . ' ' . substr($x['log'], 0, 300));
// [pt19c-A fix 1 / code review] with a STAGE on the facts line the quest entry really queues: exactly ONE carrier, never both
$GLOBALS['LRG_TEST_NOW'] += 200;
lrgDlgPut('General Tullius', ['facask' => null, 'open_pending' => null, 'facts' => ['sq' => 'CW00SolitudeMapTableScene', 'sq_at' => lrgNow(), 'sqj' => 0,
    'mq101c' => 1, 'qst' => ['CW00A' => 0], 'at' => lrgNow()]]);
$x = $p25('General Tullius', 'I want to join the Legion', ['scene' => '1', 'fac' => 'CWImperialFaction', 'class' => '']);
$plan = (string) ((($x['q']['t']['faction'] ?? [])['plan'] ?? [])['state'] ?? '');
chk('v26 Tullius AFTER Helgen, CW00A:0 on the facts line + a join ask: exactly one carrier (the open XOR the queued quest entry)',
    ($x['clause'] === 'join') !== ($plan === 'queued'), $x['clause'] . ' plan=' . $plan . ' ' . substr($x['log'], 0, 400));

// ##################################################################################################
// [pt19c-A fix 1] THE REVIEW ROUND: every reviewer finding the lane fixed, pinned through the real entry points
// ##################################################################################################
head('v30. [pt19c-A fix 1] the review round: the pre-LLM open, the fast pick, the park, the single, the breath');
// ---- (a) clause 4: >= 3 strict words and a line <= 5 topics carry; never on a companion; the escort owns its phrases
$rows30 = array_merge($rows25, [
    v1Row('Tell me about yourself.', ['toplevel' => 1, 'quest' => 'QA', 'tk' => 't:ya']), v1Row('Tell me about yourself.', ['toplevel' => 1, 'quest' => 'QB', 'tk' => 't:yb', 'n' => 2]),
    v1Row('Tell me about yourself.', ['toplevel' => 1, 'quest' => 'QC', 'tk' => 't:yc', 'n' => 3]), v1Row('Tell me about yourself.', ['toplevel' => 1, 'quest' => 'QD', 'tk' => 't:yd', 'n' => 4]),
    v1Row('What do you think about the war?', ['toplevel' => 1, 'quest' => 'MQ102A', 'tk' => 't:wa', 'scripted' => 1]),
    v1Row('What do you think about the war?', ['toplevel' => 1, 'quest' => 'QW2', 'tk' => 't:wb', 'n' => 2]),
    v1Row('What do you think about the war?', ['toplevel' => 1, 'quest' => 'QW3', 'tk' => 't:wc', 'n' => 3]),
    v1Row('What do you think about the war?', ['toplevel' => 1, 'quest' => 'QW4', 'tk' => 't:wd', 'n' => 4]),
    v1Row('What do you think about the war?', ['toplevel' => 1, 'quest' => 'QW5', 'tk' => 't:we', 'n' => 5]),
    v1Row('What do you think about the war?', ['toplevel' => 1, 'quest' => 'QW6', 'tk' => 't:wf', 'n' => 6]),
    v1Row('Tell me about yourself.', ['toplevel' => 1, 'quest' => 'QE', 'tk' => 't:ye', 'n' => 5]), v1Row('Tell me about yourself.', ['toplevel' => 1, 'quest' => 'QF', 'tk' => 't:yf', 'n' => 6]),
    v1Row("Let's go.", ['toplevel' => 1, 'quest' => 'TG08B', 'journal' => 1, 'scripted' => 1, 'ik' => 'skyrim.esm:0525EC']),
    v1Row('Follow me. I need your help.', ['toplevel' => 1, 'quest' => 'DLC2Skaal', 'ik' => 'dragonborn.esm:03A475']),
    v1Row('Tell me about Riften.', ['toplevel' => 1, 'quest' => 'DialogueCarriageSystem', 'ik' => 'skyrim.esm:0C41DB', 'topic' => 'DialogueCarriageSystemLoreRiftenTopic'])]);
v1Reset($rows30, 1);
foreach ([['Hulda V1', 'tell me about yourself', $inn, 'none of the 6 topics that carry it is hers'], ['Hulda V1', 'what do you think about the war', $inn, 'the same'],
    ['Lisette V1', "let's go", ['class' => '', 'fac' => 'TownWhiterunFaction'], 'the escort owns it (10.20)'],
    ['Lisette V1', 'follow me, I need your help', ['class' => '', 'fac' => 'TownWhiterunFaction'], 'the escort owns it (10.20)'],
    ['Lydia V1', 'Nice inn you have here, do you get many visitors?', ['class' => '', 'mate' => '1'], 'never on a companion']] as [$who, $say, $sn, $why]) {
    lrgDlgPut($who, ['open_pending' => null]);
    $GLOBALS['LRG_TEST_NOW'] += 5;
    $x = $p25($who, $say, $sn);
    chk('v30 (a) ' . $who . ' + "' . $say . '" -> NO pre-LLM open (' . $why . ')', $x['clause'] === '', $x['clause'] . ' ' . substr($x['log'], 0, 300));
}
chk('v30 (a) ...the escort case says so in the log', str_contains($p25('Lisette V1', "let's go", ['class' => '', 'fac' => 'TownWhiterunFaction'])['log'], 'an escort order owns this sentence'));
$GLOBALS['LRG_TEST_NOW'] += 5;
$x = $p25('Hulda V1', 'Nice inn you have here, do you get many visitors?', $inn);
chk('v30 (a) ...while "Nice inn you have here..." (5 strict words, 1 topic) still opens cold (toplevel)', $x['clause'] === 'toplevel', $x['log']);
$GLOBALS['LRG_TEST_NOW'] += 5;
$x = $p25('Ysolda V1', 'tell me about Riften', ['class' => '']);
chk('v30 (a) [fix 2] ...but "tell me about Riften" to Ysolda is the CARRIAGE driver\'s line (DialogueCarriageSystemLoreRiftenTopic): NO open,'
    . ' and the log names clause 4 and the row', $x['clause'] === '' && str_contains($x['log'], 'clause 4: skyrim.esm:0C41DB is DialogueCarriageSystemLoreRiftenTopic'), $x['log']);
// ---- (a2) [pt19c-A fix 2 / language review 7, game review 4] clause 4 opens only on a row HER list can carry: her quest (never
// a town's shared journal-free Dialogue quest) or an editor id that names her. The live index's rows, as the builder wrote them.
$rowsC4 = array_merge($rows30, [
    v1Row('Do you need any help?', ['toplevel' => 1, 'quest' => 'DLC2TTR8', 'journal' => 1, 'topic' => 'DLC2TTR8StartTopic', 'tk' => 'dragonborn.esm:01F12B', 'ik' => 'dragonborn.esm:01F132']),
    v1Row('Do you need any help?', ['toplevel' => 1, 'quest' => 'WTDialogueIdle', 'topic' => 'WTDialogueIdleShomaraBranch1Topic1', 'tk' => 'wyrmstooth.esp:5B62D6', 'n' => 2]),
    v1Row('Do you need any help?', ['toplevel' => 1, 'quest' => 'ACFMTSFreeformFalkreath001', 'journal' => 1, 'topic' => 'ACFMTSFreeformFalkreath001IntroBranchTopic', 'tk' => 'moretodo.esp:00092A', 'n' => 3]),
    v1Row('Do you need any help?', ['toplevel' => 1, 'quest' => 'ACFMTSAngisCampDialogue', 'journal' => 1, 'topic' => 'ACFMTSAngisCampDialogueKillBranchTopic', 'tk' => 'moretodo.esp:000A43', 'n' => 4]),
    v1Row('Do you need any help?', ['toplevel' => 1, 'quest' => 'ACFMTSAniseDialogue', 'topic' => 'ACFMTSAniseDialogueCuriousTopic', 'tk' => 'moretosayfalkreath.esp:000B23', 'ik' => 'moretosayfalkreath.esp:000B24', 'n' => 5]),
    v1Row('What can you tell me about the jarl?', ['toplevel' => 1, 'quest' => 'MQ102A', 'topic' => 'MQ102AJarlBalgruufTopic', 'tk' => 'skyrim.esm:0D3998', 'ik' => 'skyrim.esm:0D39B8']),
    v1Row('What can you tell me about the jarl?', ['toplevel' => 1, 'quest' => 'MQ102B', 'topic' => 'MQ102BJarlBalgruufTopic', 'tk' => 'skyrim.esm:0DF1F1', 'ik' => 'skyrim.esm:0DF1F6']),
    v1Row('Tell me about the Thieves Guild.', ['toplevel' => 1, 'quest' => 'DialogueRiften', 'topic' => 'DialogueRiftenThievesGuildBranchTopic', 'tk' => 'skyrim.esm:0C1F6B', 'ik' => 'skyrim.esm:0C1F70']),
    v1Row('Tell me about yourself.', ['toplevel' => 1, 'quest' => 'DialogueWhiterun', 'topic' => 'DialogueWhiterunEorlundTopicsBranch4Topic', 'tk' => 'skyrim.esm:0D33A6', 'ik' => 'skyrim.esm:0D33A8']),
    v1Row('Grelod is abusing the children at the orphanage. You must do something!', ['toplevel' => 1, 'quest' => 'DB01', 'journal' => 1, 'topic' => 'ICQEGuardNoTopic',
        'tk' => 'innocence lost - quest expansion.esp:000801', 'ik' => 'innocence lost - quest expansion.esp:000802'])]);
$c4 = static function (string $who, array $q, string $say, array $snap) use ($p25): array {
    lrgDlgPut($who, ['q' => $q, 'open_pending' => null]);
    $GLOBALS['LRG_TEST_NOW'] += 5;
    return $p25($who, $say, $snap + ['class' => '']);
};
$whq = ['ACFWhiterunVampires', 'WITavern', 'BQ01', 'DialogueWhiterun'];
foreach ([1, 0] as $clk) {
    v1Reset($rowsC4, $clk);
    foreach ([['Townie V1', [], []], ['Guard V1', [], ['fac' => 'IsGuardFaction']], ['Hulda V1', $whq, $inn]] as [$who, $q, $sn]) {
        foreach (['do you need any help', 'what can you tell me about the jarl', 'tell me about the thieves guild'] as $say) {
            $x = $c4($who, $q, $say, $sn);
            chk('v30 (a2) clicks_ok ' . $clk . ': ' . $who . ' + "' . $say . '" -> NO pre-LLM open (another NPC\'s top-level line), logged as clause 4',
                $x['clause'] === '' && str_contains($x['log'], 'pre-LLM: none - clause 4:'), $x['clause'] . ' ' . substr($x['log'], 0, 300));
        }
    }
}
v1Reset($rowsC4, 1);
foreach ([['Alvor V1', ['MQ102A'], 'what can you tell me about the jarl', 'skyrim.esm:0D39B8', 'his quest MQ102A (journal-free, not a town Dialogue quest)'],
    ['Gerdur V1', ['MQ102B'], 'what can you tell me about the jarl', 'skyrim.esm:0DF1F6', 'her quest MQ102B'],
    ['Neloth V1', ['DLC2TTR8'], 'do you need any help', 'dragonborn.esm:01F132', 'his journal quest'],
    ['Anise V1', [], 'do you need any help', 'moretosayfalkreath.esp:000B24', 'the editor id names her (More to Say: GetIsID, no alias)'],
    ['Eorlund Gray-Mane', ['DialogueWhiterun'], 'tell me about yourself', 'skyrim.esm:0D33A8', 'the topic names him inside the shared DialogueWhiterun'],
    ['Whiterun Guard', [], 'Grelod is abusing the children at the orphanage. You must do something!', 'innocence lost - quest expansion.esp:000802', 'ICQEGuardNoTopic names a guard']] as [$who, $q, $say, $row, $why]) {
    $x = $c4($who, $q, $say, $who === 'Whiterun Guard' ? ['fac' => 'IsGuardFaction'] : []);
    chk('v30 (a2) ' . $who . ' + "' . substr($say, 0, 40) . '" -> marker=toplevel row=' . $row . ' (' . $why . ')',
        $x['clause'] === 'toplevel' && str_contains($x['log'], 'open marker=toplevel row=' . $row . ' npc='),
        $x['clause'] . ' ' . substr($x['log'], 0, 300));
}
$x = $c4('Keerava V1', ['DialogueRiften'], 'tell me about the thieves guild', $inn);
chk('v30 (a2) Keerava (q=DialogueRiften) + "tell me about the thieves guild" -> NO open: a town\'s shared journal-free Dialogue quest is no proof'
    . ' (spec reconciliation 2 - its warm root still opens it)', $x['clause'] === '', $x['clause'] . ' ' . substr($x['log'], 0, 300));
$x = $c4('Chatty V1', ['QA', 'QB', 'QC', 'QD', 'QE', 'QF'], 'tell me about yourself', []);
chk('v30 (a2) six of HER topics carry "tell me about yourself" -> NO open (open.toplevel_max_topics 5 counts the topics she can carry)', $x['clause'] === '', $x['clause'] . ' ' . substr($x['log'], 0, 300));
$x = $c4('Chatty V1', ['QA'], 'tell me about yourself', []);
chk('v30 (a2) ...one of them -> marker=toplevel', $x['clause'] === 'toplevel', $x['clause'] . ' ' . substr($x['log'], 0, 300));
chk('v30 (a2) the name word: "Balgruuf the Greater" -> balgruuf, "Whiterun Guard" -> guard, "Eorlund Gray-Mane" -> eorlund, "Hulda V1" -> hulda',
    lrgDlgNameWord('Balgruuf the Greater') === 'balgruuf' && lrgDlgNameWord('Whiterun Guard') === 'guard' && lrgDlgNameWord('Eorlund Gray-Mane') === 'eorlund'
    && lrgDlgNameWord('Hulda V1') === 'hulda');
chk('v30 (a2) the editor id words split on case and digits (MQ102AJarlBalgruufTopic, ACFDialogueWhiterunHuldaBranchChatTopic)',
    lrgDlgEdidWords('MQ102AJarlBalgruufTopic') === ['mq', 'a', 'jarl', 'balgruuf', 'topic']
    && in_array('hulda', lrgDlgEdidWords('ACFDialogueWhiterunHuldaBranchChatTopic'), true) && !in_array('hul', lrgDlgEdidWords('ACFDialogueWhiterunHuldaBranchChatTopic'), true));
v1Reset($rows30, 1);
// ---- (b) clause 3 and the fast pick: HIS words must carry the line (strict precision >= 0.75 on the F1 / trigram tier)
$ik = ['Tell me about Whiterun.', 'What do you know about the Companions?', 'Heard any rumors lately?', "I'd like to rent a room. (10 gold)"];
$ikRows = [v1Row($ik[0], ['toplevel' => 1]), v1Row($ik[1], ['toplevel' => 1]), v1Row($ik[2], ['toplevel' => 1]), v1Row($ik[3], ['toplevel' => 1, 'cost' => 10])];
v1Reset($ikRows, 1);
v1Snap('Innkeep V1', $inn);
v1Topics('Innkeep V1', $ik, ['layer' => 0, 'gen' => 0]);
v1Msg('lrg_dlg', 'Innkeep V1', 'ev=closed;npc=Innkeep V1;sid=s1;why=goodbye;pending=0;layer=0');
foreach (['tell me about the war', 'tell me about yourself', 'do you know Jarl Balgruuf', 'what do you know about the dragons', 'do you have any work',
    'no rumors please', "I'm not here to trade", "can't get a room around here"] as $say) {
    lrgDlgPut('Innkeep V1', ['open_pending' => null]);
    $GLOBALS['LRG_TEST_NOW'] += 5;
    $x = $p25('Innkeep V1', $say);
    chk('v30 (b) WARM innkeeper + "' . $say . '" -> NO open (clause 3 needs his words to carry the line; never on a refusal)', $x['clause'] === '', $x['clause'] . ' ' . substr($x['log'], 0, 300));
}
foreach (['tell me about Whiterun', 'what do you know about the Companions', 'heard any rumors'] as $say) {
    lrgDlgPut('Innkeep V1', ['open_pending' => null]);
    $GLOBALS['LRG_TEST_NOW'] += 5;
    $x = $p25('Innkeep V1', $say);
    chk('v30 (b) WARM innkeeper + "' . $say . '" -> marker=root', $x['clause'] === 'root', $x['clause'] . ' ' . substr($x['log'], 0, 300));
}
$w30 = static function (string $say) use ($ikRows, $ik, $inn): string {
    v1Reset($ikRows, 1);
    v1Snap('Innkeep V1', $inn);
    v1Topics('Innkeep V1', $ik, ['layer' => 0, 'gen' => 0]);
    $GLOBALS['LRG_TEST_NOW'] += 2;
    v1Say('Innkeep V1', $say);
    $w = v1Topics('Innkeep V1', $ik, ['layer' => 0, 'gen' => 1, 'want' => 1, 'cid' => 'w30', 'ask' => substr($say, 0, 60)]);
    return preg_match('/;pos=(\d+);/', $w['echo'], $m) ? $m[1] : '-';
};
foreach (['tell me about the war' => '-', 'tell me about yourself' => '-', 'what do you know about the dragons' => '-', 'do you know Jarl Balgruuf' => '-',
    'tell me about Whiterun' => '0', 'what do you know about the Companions' => '1', 'heard any rumors' => '2', "I'd like a room" => '3', 'can I rent a room' => '3'] as $say => $want) {
    $got = $w30($say);
    chk('v30 (b) the fast pick (want=1, ask= his words) on the innkeeper\'s list: "' . $say . '" -> ' . ($want === '-' ? 'nothing' : 'pos ' . $want), $got === $want, $got);
}
// ---- (c) F18 through the real path: a voice order owns its turn (10.27 must keep working) - no open, no service click
v1Reset($ikRows, 1);
v1Snap('Innkeep V1', $mkSnap);
v1Topics('Innkeep V1', $ik, ['layer' => 0, 'gen' => 0]);
v1Msg('lrg_dlg', 'Innkeep V1', 'ev=closed;npc=Innkeep V1;sid=s1;why=goodbye;pending=0;layer=0');
$GLOBALS['LRG_TEST_NOW'] += 5;
$x = $p25('Innkeep V1', "I'd like to buy a mead");
chk('v30 (c) [F18] WARM innkeeper with fresh stock + "I\'d like to buy a mead": the order is active and NO menu opens (logged)',
    in_array(lrgMktState($x['q']['t']), LRG_MKT_ACTIVE, true) && $x['clause'] === '' && str_contains($x['log'], 'a voice order owns this turn'),
    lrgMktState($x['q']['t']) . ' ' . substr($x['log'], 0, 400));
$GLOBALS['LRG_TEST_NOW'] += 3;
$m = v1LogMark();
$w = v1Topics('Innkeep V1', $ik, ['layer' => 0, 'gen' => 2, 'want' => 1, 'cid' => 'w30c', 'sid' => 's7']);
chk('v30 (c) [F18] ...and an E-press within the window clicks no service line beside the order (the persisted mkt_order)',
    $w['echo'] === '' && str_contains(v1LogFrom($m), 'voice order'), $w['echo'] . ' ' . v1LogFrom($m));
chk('v30 (c) [game 5] lrgDlgServiceSense: "I\'d like to buy a mead" against "I\'d like to rent a room." is CONTRADICTED (another service)',
    lrgDlgServiceSense([], ['text' => "I'd like to rent a room. (10 gold)", 'class' => 'pay'], "I'd like to buy a mead") === 'contradicted');
// ---- (d) U1 / 10.20: follower kinds
v1Reset([v1Row('Follow me.', ['topic' => 'DialogueFollowerFollowTopic']), v1Row('Wait here.', ['topic' => 'DialogueFollowerWaitTopic'])], 1);
$sff = ['class' => '', 'fol' => 'fw:sff,mate:1,cff:1,pff:0,wait:0,chim:0,ghost:0,slot:2/4,cap:1,prim:1'];
$x = $p25('Serana V1', 'wait here', $sff);
chk('v30 (d) an SFF companion + cold "wait here" -> NO do=open (10.20 carries it through the game, no menu)', $x['clause'] === '' && $x['q']['queue'] === [], $x['log']);
$GLOBALS['LRG_TEST_NOW'] += 5;
$GLOBALS['ENABLED_FUNCTIONS'] = ['Talk', 'FollowPlayer', 'WaitHere', 'EndConversation', LRG_ACT_TOPIC];
$x = $p25('Mate V1', 'follow me', ['class' => '', 'mate' => '1']);
chk('v30 (d) a teammate (her own follower dialogue) + cold "follow me" -> do=open (kind), and FollowPlayer / WaitHere / EndConversation are'
    . ' off the table while it is pending', $x['clause'] === 'kind' && !array_intersect(['FollowPlayer', 'WaitHere', 'EndConversation'], (array) $GLOBALS['ENABLED_FUNCTIONS'])
    && in_array('FollowPlayer', (array) ($GLOBALS['LRG_DLG_SVC_HIDDEN'] ?? []), true), json_encode([$x['clause'], $GLOBALS['ENABLED_FUNCTIONS'], $GLOBALS['LRG_DLG_SVC_HIDDEN'] ?? null]));
unset($GLOBALS['ENABLED_FUNCTIONS']);
// ---- (e) the price-list chain and the days list (U3) with the REAL flags: every days / destination row is scripted
$days30 = ['1 day. (25 gold)', '2 days. (50 gold)', '3 days. (75 gold)', '7 days. (175 gold)'];
$rows30e = array_merge([v1Row("I'd like to rent a room.", ['toplevel' => 1]), v1Row('What have you got for sale?', ['toplevel' => 1, 'scripted' => 1])],
    array_map(static fn($d) => v1Row($d, ['cost' => lrgPromptCost($d), 'scripted' => 1]), $days30));
v1Reset($rows30e, 1);
v1Snap('Hulda V1', ['fac' => 'JobInnkeeperFaction', 'class' => '']);
v1Topics('Hulda V1', ["I'd like to rent a room.", 'What have you got for sale?'], ['layer' => 0, 'gen' => 0]);
$q = v1Say('Hulda V1', "I'd like a room for the night");
$w = v1Topics('Hulda V1', ["I'd like to rent a room.", 'What have you got for sale?'], ['want' => 1, 'cid' => (string) $q['t']['cid'], 'layer' => 0, 'gen' => 1]);
$GLOBALS['LRG_TEST_NOW'] += 3;
$w = v1Topics('Hulda V1', $days30, ['want' => 1, 'cid' => (string) $q['t']['cid'], 'layer' => 1, 'gen' => 2, 'pg' => 1000]);
chk('v30 (e) [U3] one sentence, two clicks on the REAL flags: "1 day" (scripted, a closed layer grades it commit) is named by the chain',
    str_contains($w['echo'], ';do=pick;') && str_contains($w['echo'], ';pos=0;') && str_contains($w['echo'], ';cost=25;'), $w['echo']);
foreach (['three nights' => 2, "I'll stay three nights" => 2, '3 days' => 2, 'two nights' => 1] as $say => $pos) {
    v1Reset($rows30e, 1);
    v1Topics('Hulda V1', $days30, ['layer' => 1, 'gen' => 1, 'pg' => 1000]);
    $GLOBALS['LRG_TEST_NOW'] += 2;
    v1Say('Hulda V1', $say);
    $w = v1Topics('Hulda V1', $days30, ['want' => 1, 'cid' => 'w30e', 'layer' => 1, 'gen' => 2, 'pg' => 1000]);
    chk('v30 (e) [U3] the days list + "' . $say . '" -> pos ' . $pos . ' (position AND norm: every row is "# days")', str_contains($w['echo'], ';pos=' . $pos . ';'), $w['echo']);
}
v1Reset($rows30e, 1);
v1Topics('Hulda V1', $days30, ['layer' => 1, 'gen' => 1, 'pg' => 1000]);
$q = v1Say('Hulda V1', 'how much for three nights');
chk('v30 (e) [U3] the days list is named to her by its LIVE names ("1 day", "2 days" ...), never "# days"',
    in_array('3 days', (array) ($q['t']['svc']['all'] ?? []), true) && !in_array('# days', (array) ($q['t']['svc']['all'] ?? []), true), json_encode($q['t']['svc']['all'] ?? null));
$dest = ['Whiterun. (20 gold)', 'Solitude.', 'Markarth.', 'Riften.'];
v1Reset(array_map(static fn($d) => v1Row($d, ['scripted' => 1, 'cost' => lrgPromptCost($d)]), $dest), 1);
$q = v1Say('Bjorlam V1', 'take me to Whiterun');
lrgDlgPut('Bjorlam V1', ['last_click' => ['at' => lrgNow() + 1, 'cid' => (string) $q['t']['cid'], 'lead' => 1]]);
$GLOBALS['LRG_TEST_NOW'] += 3;
$w = v1Topics('Bjorlam V1', $dest, ['want' => 1, 'cid' => (string) $q['t']['cid'], 'layer' => 1, 'gen' => 2, 'pg' => 300]);
chk('v30 (e) [U3] a carriage chain: the hire click his sentence produced, then "Whiterun" (scripted destination, 20 septims) on the list it opened',
    str_contains($w['echo'], ';do=pick;') && str_contains($w['echo'], ';pos=0;'), $w['echo']);
// ---- (f) G5 on the fast path: a named slot said as a question or a remark is no request (language review 2)
$eorRows = array_map(static fn($x) => v1Row($x, ['quest' => 'C00', 'journal' => 1, 'scripted' => 1, 'goodbye' => 1]), $eor);
foreach (['is the waraxe any good?', 'what is a battleaxe', 'the greatsword looks heavy', 'tell me about the dagger', 'I already have a dagger'] as $say) {
    v1Reset($eorRows, 1);
    v1Topics('Eorlund V1', $eor, ['layer' => 1, 'gen' => 1]);
    $GLOBALS['LRG_TEST_NOW'] += 2;
    v1Say('Eorlund V1', $say);
    $w = v1Topics('Eorlund V1', $eor, ['want' => 1, 'cid' => 'w30f', 'layer' => 1, 'gen' => 2]);
    chk('v30 (f) Eorlund\'s five (commits) + "' . $say . '" -> nothing clicked on the fast path', $w['echo'] === '', $w['echo']);
}
v1Reset($eorRows, 1);
v1Topics('Eorlund V1', $eor, ['layer' => 1, 'gen' => 1]);
$GLOBALS['LRG_TEST_NOW'] += 2;
v1Say('Eorlund V1', 'a sword, please');
$w = v1Topics('Eorlund V1', $eor, ['want' => 1, 'cid' => 'w30f2', 'layer' => 1, 'gen' => 2]);
chk('v30 (f) ...while "a sword, please" (clean) is still his own confirmation (pos 4)', str_contains($w['echo'], ';pos=4;'), $w['echo']);
// ---- (g) the single entry: content outranks the refusal regex, continuers only bare, fragments are no containment
$sg = static function (string $tx, array $o, string $u): array {
    v1Reset([v1Row($tx, $o)], 1);
    v1Topics('Single V1', [$tx]);
    return lrgDlgSingleEntryRelease((array) ((lrgDlgGet('Single V1')['session']['entries'] ?? [])[0] ?? []), $u);
};
$join = "I don't want to sit idly by after what I've witnessed. I want to join the Legion.";
$mq205 = 'No, but I know how to find out. I need an Elder Scroll.';
foreach ([[$join, $join], [$join, "I don't want to sit idly by, I want to join the Legion"], [$mq205, 'No, but I know how to find out. I need an Elder Scroll.'],
    ["I don't know yet, but I know some people who can probably help.", "I don't know yet, but I know some people who can help"]] as [$tx, $u]) {
    $rel = $sg($tx, ['scripted' => 1, 'goodbye' => 1], $u);
    chk('v30 (g) [language 3] a single whose line opens with a refusal word, said by him: "' . substr($u, 0, 50) . '" -> released (' . $rel['step'] . ')',
        !empty($rel['release']), json_encode($rel));
}
$rel = $sg($join, ['scripted' => 1, 'goodbye' => 1], "I don't want to");
chk('v30 (g) ...but its bare opening "I don\'t want to" releases nothing and refuses nothing: the model decides (a commit single then parks)',
    empty($rel['release']) && empty($rel['refused']), json_encode($rel));
v1Reset([v1Row($join, ['scripted' => 1, 'goodbye' => 1])], 1);
v1Topics('Tullius V1', [$join]);
v1Say('Tullius V1', "I don't want to sit idly by, I want to join the Legion");
$r = v1Llm('Tullius V1', 'T1', 'Then welcome to the Legion.');
$v1Twin('v30 tullius join single', $r);
chk('v30 (g) [language 3] the model picks the only key on Tullius\'s single with his own words: clicked (step 0 no longer refuses it)',
    v1Do($r['out']) === 'pick' && $r['will'], json_encode($r['out']));
foreach (['and what do you think of them', 'and who are they?', 'then what do you want from me'] as $u) {
    $rel = $sg('The Greybeards?', ['walkaway' => 1], $u);
    chk('v30 (g) [ai] a continuer that LEADS a question ("' . $u . '") releases nothing (the breath decides)', empty($rel['release']), json_encode($rel));
}
foreach (['go on', 'go on then', 'and?', 'then what'] as $u) {
    $rel = $sg('The Greybeards?', ['walkaway' => 1], $u);
    chk('v30 (g) ...while a BARE continuer ("' . $u . '") still releases it', !empty($rel['release']), json_encode($rel));
}
foreach ([['Well?', 'farewell'], ['So...', 'sorry, I have to go'], ['So...', 'also'], ['What?', 'whatever you say'],
    ['Contract?', 'con'], ['Contract?', 'contra']] as [$tx, $u]) {
    $rel = $sg($tx, ['scripted' => 1], $u);
    chk('v30 (g) [ai] a word FRAGMENT is no containment: "' . $u . '" against "' . $tx . '" releases nothing', empty($rel['release']), json_encode($rel));
}
chk('v30 (g) [ai] lrgDlgMatchText: "farewell" vs "well" is no containment (0.533, trigram); "what contract" vs "contract" still scores >= 0.85',
    (string) lrgDlgMatchText('farewell', [['norm' => 'well']])['tier'] !== 'contain' && (float) lrgDlgMatchText('farewell', [['norm' => 'well']])['eff'] < 0.85
    && (float) lrgDlgMatchText('what contract', [['norm' => 'contract']])['eff'] >= 0.85);
$yn = ['Yes.', 'No.'];
foreach (["I know, let's do this", 'I know', 'nothing can stop me now', 'yesterday I was ready'] as $u) {
    v1Reset([v1Row('Yes.', ['scripted' => 1, 'goodbye' => 1, 'quest' => 'DLC2MQ06']), v1Row('No.', ['scripted' => 1, 'goodbye' => 1, 'quest' => 'DLC2MQ06'])], 1);
    v1Topics('Miraak V1', $yn, ['layer' => 1, 'gen' => 1]);
    $GLOBALS['LRG_TEST_NOW'] += 2;
    v1Say('Miraak V1', $u);
    $w = v1Topics('Miraak V1', $yn, ['want' => 1, 'cid' => 'w30g', 'layer' => 1, 'gen' => 2]);
    chk('v30 (g) [ai] [Yes., No.] (two commits) + "' . $u . '" -> nothing clicked on the fast path', $w['echo'] === '', $w['echo']);
}
chk('v30 (g) [ai] explicit on a one-token line only when said EXACTLY: "no" IS "No." (3-token floor aside), "I know" is not',
    !lrgDlgExplicit(['speech' => true, 'npc' => 'Miraak V1', 'entries' => [['pos' => 0, 'norm' => 'yes', 'text' => 'Yes.', 'class' => 'commit'], ['pos' => 1, 'norm' => 'no', 'text' => 'No.', 'class' => 'commit']], 'tail' => []],
        ['pos' => 1, 'norm' => 'no', 'text' => 'No.', 'class' => 'commit'], 'I know', 'intent', []));
// ---- (h) the breath: never beside a LEAVE, never on leaving words; R1's "I don't ..." heads do not stall it (language 4)
$gb = static function (string $say, ?string $item) {
    v1Reset([v1Row('The Greybeards?', ['walkaway' => 1])], 1);
    v1Topics('Balgruuf V1', ['The Greybeards?'], ['layer' => 1, 'gen' => 1]);
    $GLOBALS['LRG_TEST_NOW'] += 2;
    v1Say('Balgruuf V1', $say);
    $r = v1Llm('Balgruuf V1', $item, 'Old men on a mountain.');
    return array_values(array_filter($r['out'], static fn($l) => str_contains((string) $l, ';rearm=1')));
};
chk('v30 (h) [ai, architect P1] "I have to go" + the model\'s LEAVE on a walk-away single: the leave guard, and NO re-armed breath', $gb('I have to go', 'LEAVE') === []);
chk('v30 (h) "goodbye then" with no model item: no re-armed breath (he is leaving)', $gb('goodbye then', null) === []);
foreach (['what do you think of them?', "I don't understand", "I don't know who they are", 'not really sure what you mean', 'never heard of them'] as $say) {
    chk('v30 (h) [language 4] "' . $say . '" answered in her words -> the breath IS re-armed (rearm=1)', count($gb($say, null)) === 1);
}
foreach (['no', 'stop', "I don't want to hear it"] as $say) {
    chk('v30 (h) ...while a real refusal ("' . $say . '") never re-arms it', $gb($say, null) === []);
}
// [pt19c-A fix 2 / language + game review round 2] the fix-1 deferrals were unanchored: "think about it" and "(have|got) a
// question" matched QUESTIONS, so the breath waited on "what do you think about it?" and re-armed on "...of them?"
foreach (['what do you think about it?', 'tell me what you think about it', 'I have a question, who are they?', 'I have one more question, where do they live',
    'can I ask who they are?'] as $say) {
    chk('v30 (h) [fix 2] "' . $say . '" is a question, not a deferral: backout=0 (wide and narrow), the breath IS re-armed (rearm=1)',
        !lrgDlgIsBackOut($say, true) && !lrgDlgIsBackOut($say, true, true) && !lrgDlgRefuses($say) && count($gb($say, null)) === 1);
}
foreach (["I've got a question for you", 'I have a question', 'I do have a question first', 'can I ask you something?', "I'll have to think about it",
    'I must think it over', 'let me think about it'] as $say) {
    chk('v30 (h) [fix 2] ...while "' . $say . '" (a question ANNOUNCED, or his own deferral) defers: no re-arm, the breath waits for what he asks next',
        lrgDlgRefuses($say) && $gb($say, null) === []);
}
foreach (["think about it, there's no point earning all that gold if the dragons come and kill every one of you", 'I think about it every day',
    'you should think about it', 'do you have a question for me?'] as $say) {
    chk('v30 (h) [fix 2] "' . substr($say, 0, 60) . '" is no deferral of HIS (an argument, a statement, a question to her): backout=0', !lrgDlgRefuses($say));
}
chk('v30 (h) [fix 2] a refusal before the announced question still refuses ("no, I have a question: who are they?"); a hesitation before it does not'
    . ' ("hold on, I have a question, who are they?" - G6)', lrgDlgRefuses('no, I have a question: who are they?')
    && !lrgDlgRefuses('hold on, I have a question, who are they?') && lrgDlgRefuses('I have a question, never mind'));
$rel = $sg('What good is all that gold if the dragons kill you all? (Persuade)', ['kind' => 'persuade'],
    "think about it, there's no point earning all that gold if the dragons come and kill every one of you");
chk('v30 (h) [fix 2] TG00\'s persuade single + the language brief\'s "think about it, there\'s no point earning all that gold..." is not refused at step 0',
    empty($rel['refused']) && !str_starts_with((string) $rel['step'], '0 refusal'), json_encode($rel));
foreach (['what do you think about it?', 'I have a question, who are they?'] as $u) {
    $rel = $sg('The Greybeards?', ['walkaway' => 1], $u);
    chk('v30 (h) [fix 2] the walk-away single "The Greybeards?" + "' . $u . '": not refused at step 0 (S4.5)', empty($rel['refused']), json_encode($rel));
}
// ---- (i) the park: a deferral, a question back, another line, an unnamed park - none releases a commit
$kl = [v1Row('I will kill him for you.', ['scripted' => 1, 'goodbye' => 1]), v1Row('Let him go free.', ['scripted' => 1, 'goodbye' => 1])];
$pk30 = static function (array $rows, array $texts, string $said, string $key = 'T1', bool $hint = false): void {
    v1Reset($rows, 1);
    v1Topics('Captor V1', $texts);
    v1Say('Captor V1', 'the prisoner');
    v1Llm('Captor V1', $key, $said);
    if ($hint) { $p = (array) lrgDlgGet('Captor V1')['parked']; $p['hint'] = 1; lrgDlgPut('Captor V1', ['parked' => $p]); }
    $GLOBALS['LRG_TEST_NOW'] += 3;
};
foreach ([['I will think about it', null], ['I will do it later', null], ['sure, after I rest', null], ['I will need some time', null],
    ['I do have a question first', null], ['hold on, what do you mean?', 'T1'], ['wait, what?', 'T1'], ['why would I do that?', 'T1'],
    ['what happens if I do?', 'T1']] as [$say, $item]) {
    $pk30($kl, ['I will kill him for you.', 'Let him go free.'], 'You would kill him for me?');
    v1Say('Captor V1', $say);
    $r = v1Llm('Captor V1', $item, 'As you say.');
    chk('v30 (i) [ai consent, language 1/8] parked "I will kill him for you." + "' . $say . '" (' . ($item ?? 'no key') . ') -> nothing clicked',
        !array_filter($r['out'], static fn($l) => str_contains((string) $l, ';do=pick;')), json_encode($r['out']));
}
$pk30($kl, ['I will kill him for you.', 'Let him go free.'], 'You would kill him for me?');
v1Say('Captor V1', 'yes');
$r = v1Llm('Captor V1', null, 'Then it is done.');
chk('v30 (i) ...while her question + "yes" (no key) still releases it (F27)', v1Do($r['out']) === 'pick', json_encode($r['out']));
// [pt19c-A fix 2 / language + game review round 2] a QUESTION back keeps the park (she answers, then asks again) - fix 1's
// unanchored "think about it" / "have a question" made these un-park the line; an announced question alone still drops it
foreach (['what do you think about it?', 'I have a question, who are they?'] as $say) {
    $pk30($kl, ['I will kill him for you.', 'Let him go free.'], 'You would kill him for me?');
    v1Say('Captor V1', $say);
    $r = v1Llm('Captor V1', null, 'He deserves it.');
    $kept = (string) (((array) (lrgDlgGet('Captor V1')['parked'] ?? []))['norm'] ?? '');
    chk('v30 (i) [fix 2] parked "I will kill him for you." + "' . $say . '" -> nothing clicked and STILL parked (a question back, not a refusal)',
        !array_filter($r['out'], static fn($l) => str_contains((string) $l, ';do=pick;')) && $kept === lrgPromptNorm('I will kill him for you.'),
        json_encode([$r['out'], lrgDlgGet('Captor V1')['parked'] ?? null]));
    $GLOBALS['LRG_TEST_NOW'] += 3;
    v1Say('Captor V1', 'yes');
    $r = v1Llm('Captor V1', null, 'Then it is done.');
    chk('v30 (i) [fix 2] ...and his "yes" after her answer releases it (F27)', v1Do($r['out']) === 'pick', json_encode($r['out']));
}
$pk30($kl, ['I will kill him for you.', 'Let him go free.'], 'You would kill him for me?');
v1Say('Captor V1', "I've got a question for you");
$r = v1Llm('Captor V1', null, 'Ask, then.');
chk('v30 (i) [fix 2] ...while "I\'ve got a question for you" (announced, not asked) drops the park like any deferral (language brief step 0)',
    !array_filter($r['out'], static fn($l) => str_contains((string) $l, ';do=pick;')) && empty(((array) (lrgDlgGet('Captor V1')['parked'] ?? []))['norm'] ?? ''),
    json_encode([$r['out'], lrgDlgGet('Captor V1')['parked'] ?? null]));
$sp = [v1Row('I will spare him.', ['scripted' => 1, 'goodbye' => 1]), v1Row('I will kill him for you.', ['scripted' => 1, 'goodbye' => 1])];
$pk30($sp, ['I will spare him.', 'I will kill him for you.'], 'You would spare him, then?');
v1Say('Captor V1', 'I will kill him');
$r = v1Llm('Captor V1', null, 'So be it.');
chk('v30 (i) [language 1] parked "I will spare him." + "I will kill him" -> NEVER the opposite line (F27 appends nothing)',
    !array_filter($r['out'], static fn($l) => str_contains((string) $l, 'spare')), json_encode($r['out']));
$three = [v1Row('I will kill him for you.', ['scripted' => 1, 'goodbye' => 1]), v1Row('Let him go free.', ['scripted' => 1, 'goodbye' => 1]),
    v1Row('I will hand him to the guards.', ['scripted' => 1, 'goodbye' => 1])];
foreach (['yes please', 'okay then'] as $say) {
    $pk30($three, ['I will kill him for you.', 'Let him go free.', 'I will hand him to the guards.'], 'Which one do you mean?', 'T1', true);
    v1Say('Captor V1', $say);
    $r = v1Llm('Captor V1', 'T1', 'Very well.');
    chk('v30 (i) [architect P3] a park written on a hint turn + "' . $say . '" and T1 -> stays parked (her "?" asked WHICH)',
        !array_filter($r['out'], static fn($l) => str_contains((string) $l, ';do=pick;')), json_encode($r['out']));
}
foreach (['no problem', 'sure, why not', 'I suppose so', "no problem, I'll do it"] as $say) {
    $pk30($kl, ['I will kill him for you.', 'Let him go free.'], 'You would kill him for me?');
    v1Say('Captor V1', $say);
    $r = v1Llm('Captor V1', null, 'Then it is done.');
    chk('v30 (i) [language 9] an idiom of assent ("' . $say . '") answers her naming question', v1Do($r['out']) === 'pick', json_encode($r['out']));
}
// ---- (j) the question shape reads unfolded tokens; a negated auxiliary asks only by inversion (language 5)
foreach (["We're here to see the jarl", 'we are ready', "Don't worry, I'll handle it", "Can't wait to start", "Didn't think so", "Won't happen again"] as $s) {
    chk('v30 (j) "' . $s . '" is a statement', !lrgDlgIsQuestion($s));
}
chk('v30 (j) [game review 8] his EXACT line beside a near twin ("I will kill him for you." / "I will spare him.", margin 0.217) is explicit',
    lrgDlgExplicit(['speech' => true, 'npc' => 'Captor V1', 'entries' => [['pos' => 0, 'norm' => lrgPromptNorm('I will kill him for you.'), 'text' => 'I will kill him for you.', 'class' => 'commit'],
        ['pos' => 1, 'norm' => lrgPromptNorm('I will spare him.'), 'text' => 'I will spare him.', 'class' => 'commit']], 'tail' => []],
        ['pos' => 0, 'norm' => lrgPromptNorm('I will kill him for you.'), 'text' => 'I will kill him for you.', 'class' => 'commit'], 'I will kill him for you', 'key', []));
foreach (["don't you know", "isn't it strange", "what's that", 'were you there', 'can we go', 'wait what does that mean', 'hold on what do you mean'] as $s) {
    chk('v30 (j) "' . $s . '" is a question', lrgDlgIsQuestion($s));
}
chk('v30 (j) the ENTRY side too: "Don\'t worry. I\'ll return the Key." and "We\'re looking for a fragment of Wuuthrad." are statements',
    !lrgDlgEntryIsQuestion(['text' => "Don't worry. I'll return the Key.", 'norm' => lrgPromptNorm("Don't worry. I'll return the Key.")])
    && !lrgDlgEntryIsQuestion(['text' => "We're looking for a fragment of Wuuthrad.", 'norm' => lrgPromptNorm("We're looking for a fragment of Wuuthrad.")]));
$rel = $sg('I have news from Helgen.', ['scripted' => 1], "We're here with news from Helgen");
chk('v30 (j) ...so "We\'re here with news from Helgen" against "I have news from Helgen." is not refused by shape', empty($rel['shape']), json_encode($rel));
$rel = $sg("What else can I help you with?", ['scripted' => 1, 'goodbye' => 1], 'yes, Ill help');
chk('v30 (j) [language 10] the STT\'s "Ill" is no foreign word: "yes, Ill help" releases the commit single', !empty($rel['release']), json_encode($rel));
// ---- (j2) G2, the STT compound fold (research/pt19c-language.md; language review 11): entry-driven, matching only
chk('v30 (j2) [G2] lrgDlgSttFold joins a split word a line carries and keeps the punctuation',
    lrgDlgSttFold('all hail the storm cloaks!', [['norm' => 'all hail the stormcloaks the true sons and daughters of skyrim']]) === 'all hail the stormcloaks!'
    && lrgDlgSttFold('what do these gray beards want?', [['norm' => 'what do these greybeards want with me']]) === 'what do these greybeards want?'
    && lrgDlgSttFold('I will wait here', [['norm' => 'i will wait here']]) === 'I will wait here',
    json_encode([lrgDlgSttFold('all hail the storm cloaks!', [['norm' => 'all hail the stormcloaks the true sons and daughters of skyrim']]),
        lrgDlgSttFold('what do these gray beards want?', [['norm' => 'what do these greybeards want with me']])]));
foreach ([['"All hail the Stormcloaks, the true sons and daughters of Skyrim!"', 'all hail the storm cloaks'],
    ['What do these Greybeards want with me?', 'what do these gray beards want with me'], ['Contract?', 'con tract'],
    ['Are you all right?', 'are you alright']] as [$tx, $u]) {
    $rel = $sg($tx, ['scripted' => 1, 'goodbye' => 1], $u);
    chk('v30 (j2) [G2] "' . $u . '" releases the single "' . substr($tx, 0, 40) . '"', !empty($rel['release']), json_encode($rel));
}
// ---- (k) the follower rails wherever a line is resolved: a dismissal always asks; never-by-voice topics never click
$folL = ["It's time for us to part ways.", 'Follow me.', 'Wait here.'];
$folRows = [v1Row($folL[0], ['topic' => 'DialogueFollowerDismissTopic']), v1Row($folL[1], ['topic' => 'DialogueFollowerFollowTopic']),
    v1Row($folL[2], ['topic' => 'DialogueFollowerWaitTopic'])];
v1Reset($folRows, 1);
v1Snap('Lydia V1', ['class' => '', 'mate' => '1']);
v1Topics('Lydia V1', $folL, ['layer' => 0, 'gen' => 0]);
$e0 = (array) ((lrgDlgGet('Lydia V1')['session']['entries'] ?? [])[0] ?? []);
chk('v30 (k) [architect P2] the dismissal line is a commit that is never explicit (fcommit) the moment the list is read', !empty($e0['commit']) && !empty($e0['fcommit']), json_encode($e0));
v1Say('Lydia V1', "it's time for us to part ways");
$r = v1Llm('Lydia V1', 'T1', 'You want me to leave?');
$v1Twin('v30 dismiss T-key parks', $r);
chk('v30 (k) [architect P2, model R12] "it\'s time for us to part ways" + the model\'s T1: PARKED, she asks (never dismissed at once)',
    $r['out'] === [] && !$r['will'] && !empty(lrgDlgGet('Lydia V1')['parked']), json_encode($r['out']));
v1Reset($folRows, 1);
v1Snap('Lydia V1', ['class' => '', 'mate' => '1']);
v1Topics('Lydia V1', $folL, ['layer' => 0, 'gen' => 0]);
$GLOBALS['LRG_TEST_NOW'] += 2;
v1Say('Lydia V1', "it's time for us to part ways");
$w = v1Topics('Lydia V1', $folL, ['layer' => 0, 'gen' => 1, 'want' => 1, 'cid' => 'w30k']);
chk('v30 (k) ...and the fast path (an E-press after he said it) clicks nothing either', $w['echo'] === '', $w['echo']);
$GLOBALS['LRG_TEST_NOW'] += 2;
v1Say('Lydia V1', 'wait here');
$w = v1Topics('Lydia V1', $folL, ['layer' => 0, 'gen' => 2, 'want' => 1, 'cid' => 'w30k2']);
chk('v30 (k) [CHIM] "wait here" on the fast path resolves through the VERB table (exact), the wait line (pos 2)', str_contains($w['echo'], ';pos=2;'), $w['echo']);
v1Reset([v1Row('Follow me.', ['topic' => 'DialogueFollowerAnimalFollowTopic']), v1Row('Stay.', ['topic' => 'DialogueFollowerAnimalWaitTopic'])], 1);
v1Topics('Dog V1', ['Follow me.', 'Stay.'], ['layer' => 0, 'gen' => 0]);
$GLOBALS['LRG_TEST_NOW'] += 2;
v1Say('Dog V1', 'follow me');
$w = v1Topics('Dog V1', ['Follow me.', 'Stay.'], ['layer' => 0, 'gen' => 1, 'want' => 1, 'cid' => 'w30k3']);
chk('v30 (k) [CHIM, S4.9] an ANIMAL twin (never_topics) is never clicked on the fast path', $w['echo'] === '', $w['echo']);
// ---- (l) the words path is the twin's too (architect P4): a pick from the model's WORDS is muted like a key
v1Reset($rows16, 1);
v1Topics('Ysolda V1', $L);
v1Say('Ysolda V1', 'tell me about Whiterun');
$r = v1Llm('Ysolda V1', 'Tell me about Whiterun.', 'Whiterun, then.');
$v1Twin('v30 words path', $r);
chk('v30 (l) [architect P4] the model names the line in WORDS on an open session: picked AND WillEmit true (the twin covers intent mode)',
    v1Do($r['out']) === 'pick' && $r['will'], json_encode($r['out']));
// ---- (m) a read-only session takes the action off the table too (ai review)
v1Reset($rows16, 1);
$GLOBALS['ENABLED_FUNCTIONS'] = ['Talk', LRG_ACT_TOPIC];
v1Open('Ysolda V1', ['drv' => 0]);
v1Topics('Ysolda V1', $L);
$q = v1Say('Ysolda V1', 'tell me about Whiterun');
chk('v30 (m) a read-only session (drv=0): TakeUpBusiness is not in the enum (nothing is offered that cannot be clicked)',
    (string) ($q['t']['ro'] ?? '') !== '' && !in_array(LRG_ACT_TOPIC, (array) $GLOBALS['ENABLED_FUNCTIONS'], true), json_encode([$q['t']['ro'] ?? null, $GLOBALS['ENABLED_FUNCTIONS']]));
unset($GLOBALS['ENABLED_FUNCTIONS']);
// ---- (n) the funcret of our own click never uses up <what_just_happened> (CHIM review)
v1Reset($rows16, 1);
lrgDlgPut('Ysolda V1', ['last_result' => ['ok' => 0, 'why' => 'unverified', 'entry' => 'Tell me about Whiterun.', 'at' => lrgNow(), 'told' => false]]);
unset($GLOBALS['LRG_DLG_TURN']);
$GLOBALS['gameRequest'] = ['funcret', lrgNow(), 100000, 'command@ExtCmdLRG_SelectTopic@OK: The matter is raised.'];
$GLOBALS['HERIKA_NAME'] = 'Ysolda V1';
lrgDlgPrepareTurn();
$fr = (array) ($GLOBALS['LRG_DLG_TURN'] ?? []);
$q = v1Say('Ysolda V1', 'so what happened');
chk('v30 (n) our own SelectTopic funcret builds no result block and marks nothing told; the next player turn still carries "Nothing came of it"',
    (string) ($fr['result_block'] ?? '') === '' && str_contains((string) $q['t']['result_block'], 'Nothing came of it'), json_encode([$fr['result_block'] ?? null, $q['t']['result_block'] ?? null]));
// ---- (o) the mute reads last_click (CHIM review): the next layer's auto-advance pick with the same cid keeps her bridge muted
v1Reset($rows25, 1);
v1Snap('Hulda V1', $inn);
$x = $p25('Hulda V1', 'Nice inn you have here, do you get many visitors?');
$t30 = (array) $GLOBALS['LRG_DLG_TURN'];
lrgDlgPut('Hulda V1', ['last_click' => ['at' => lrgNow(), 'cid' => (string) $t30['cid'], 'lead' => 1],
    'last_exec' => ['do' => 'pick', 'cid' => (string) $t30['cid'], 'adv' => 2500, 'at' => lrgNow()]]);
$GLOBALS['LAST_LLM_RESPONSE'] = ['action' => '', 'item' => '', 'message' => 'Thanks!'];
chk('v30 (o) the fast pick landed, then the auto-advance pick overwrote last_exec with the same cid: her bridge is STILL muted',
    $x['clause'] === 'toplevel' && lrgDlgTransformer('Thanks, stranger.') === '');
lrgDlgPut('Hulda V1', ['last_click' => ['at' => lrgNow(), 'cid' => (string) $t30['cid'], 'lead' => 0]]);
chk('v30 (o) ...and an auto-advance alone (no lead pick for this sentence) never mutes her', lrgDlgTransformer('Thanks, stranger.') === 'Thanks, stranger.');
// ---- (p) a doomed open is not queued: too far, or in a fight (game review 6)
v1Reset($rows25, 1);
$x = $p25('Hulda V1', 'Nice inn you have here, do you get many visitors?', $inn + ['dist' => '900']);
chk('v30 (p) 900 units away (the game opens within 200): no pre-LLM open, logged', $x['clause'] === '' && str_contains($x['log'], 'units away'), $x['log']);
$GLOBALS['LRG_TEST_NOW'] += 5;
$x = $p25('Hulda V1', 'Nice inn you have here, do you get many visitors?', $inn + ['dist' => '150', 'combat' => '1']);
chk('v30 (p) in combat: no pre-LLM open', $x['clause'] === '' && str_contains($x['log'], 'combat'), $x['log']);
$GLOBALS['LRG_TEST_NOW'] += 5;
$x = $p25('Hulda V1', 'Nice inn you have here, do you get many visitors?', $inn + ['dist' => '150', 'combat' => '0']);
chk('v30 (p) ...150 units, no fight: it opens', $x['clause'] === 'toplevel', $x['log']);
// ---- (q) the stage rail's words are true (game review 7, ai review)
v1Reset([v1Row('Tell me about the war.'), v1Row('What have you got for sale?', ['scripted' => 1])], 0);
v1Topics('Guard V1', ['Tell me about the war.', 'What have you got for sale?']);
v1Say('Guard V1', 'show me your wares');
$r = v1Llm('Guard V1', 'T2', 'Have a look.');
$note = (string) (v1Kv((string) ($r['out'][0] ?? ''))['note'] ?? '');
chk('v30 (q) the corner note promises only "this line", names no "her", and says no "any line"', $note !== '' && !preg_match('/\b(her|she|any line)\b/i', $note), $note);
v1Reset([v1Row('I have news from Helgen.', ['scripted' => 1]), v1Row('What have you got for sale?', ['scripted' => 1])], 0);
v1Topics('Guard V1', ['I have news from Helgen.', 'What have you got for sale?']);
$q = v1Say('Guard V1', 'I have news from Helgen');
$r = v1Llm('Guard V1', 'T1', 'Go on.');
$note = (string) (v1Kv((string) ($r['out'][0] ?? ''))['note'] ?? '');
chk('v30 (q) [U7] no key can pass: neither her line nor the corner note asks for "something simple first"',
    !str_contains($q['biz'], 'Until he has asked me something simple') && str_contains($q['biz'], 'choose this one yourself, this once')
    && $note !== '' && !str_contains($note, 'simple'), json_encode([$q['biz'], $note]));
// ---- (r) U8 fails closed: an unreadable index never makes a quest scene ambient
$idx = $GLOBALS['LRG_TEST_INDEX'];
unset($GLOBALS['LRG_TEST_INDEX'], $GLOBALS['LRG_DLG_QIDX']);
chk('v30 (r) [usefulness U8] no database and no index seam: lrgDlgQuestIndexed is TRUE (fail closed - never ambient on a guess)',
    lrgDb() !== null || lrgDlgQuestIndexed('DialogueWhiterunBanneredMareScene3') === true);
$GLOBALS['LRG_TEST_INDEX'] = $idx;
// ---- (s) the lost update, through a STORE that is not this request's cache (CHIM brief P15)
v1Reset($rows25, 1);
v1Snap('Hulda V1', $inn);
$GLOBALS['LRG_DLG_TEST_STORE'] = ['*install*' => ['clicks_ok' => 1]];
$x = $p25('Hulda V1', 'Nice inn you have here, do you get many visitors?');
$t30 = (array) $GLOBALS['LRG_DLG_TURN'];
// the FAST PATH, in another request, lands its pick for this sentence: it writes the store, never this request's cache
$GLOBALS['LRG_DLG_TEST_STORE']['Hulda V1']['last_exec'] = ['do' => 'pick', 'cid' => (string) $t30['cid'], 'adv' => -1, 'at' => lrgNow()];
$GLOBALS['LRG_DLG_TEST_STORE']['Hulda V1']['want_none'] = ['cid' => 'other', 'at' => lrgNow()];
$m = v1LogMark();
$r = v1Llm('Hulda V1', 'T1', 'Thanks!');
lrgDlgPut('Hulda V1', ['truth_note' => ['at' => lrgNow(), 'claim' => 'x']]);
$sto = (array) ($GLOBALS['LRG_DLG_TEST_STORE']['Hulda V1'] ?? []);
chk('v30 (s) [P1] the post-gate reads the store FRESH: the reply\'s T-key is dropped (the fast pick for this sentence landed), and a later write'
    . ' of this request keeps what the fast path wrote', $r['out'] === [] && str_contains(v1LogFrom($m), 'already landed')
    && (string) (($sto['last_exec'] ?? [])['cid'] ?? '') === (string) $t30['cid'] && (string) (($sto['want_none'] ?? [])['cid'] ?? '') === 'other',
    json_encode([$r['out'], v1LogFrom($m), $sto['last_exec'] ?? null]));
unset($GLOBALS['LRG_DLG_TEST_STORE']);
// ---- (t) MS11's shape: a single STATEMENT line executes only on his statement (spec 21: "MS11 shape executes only on the second statement")
$ms11 = 'I will find out what happened.';
$rel1 = $sg($ms11, ['scripted' => 1], 'what happened here?');
$rel2 = $sg($ms11, ['scripted' => 1], 'I will find out what happened');
chk('v30 (t) MS11 shape: a question to her first ("what happened here?") is refused by shape; the statement then releases it',
    !empty($rel1['shape']) && empty($rel1['release']) && !empty($rel2['release']), json_encode([$rel1, $rel2]));

// ------------------------------------------------------------------ v28. WillEmit is the gate's twin
head('v28. [pt19 v1.0 / S1.3] lrgDlgWillEmit === (a pick was emitted), on every T-key / LEAVE decision above');
$diff = array_values(array_filter($twin, static fn($r) => $r[1] !== $r[2]));
chk('v28 WillEmit agrees with the gate on all ' . count($twin) . ' decisions', $diff === [], json_encode($diff));
chk('v28 ...and the set really covers both answers', count(array_filter($twin, static fn($r) => $r[1])) >= 5 && count(array_filter($twin, static fn($r) => !$r[1])) >= 5,
    json_encode(array_map(static fn($r) => $r[0] . '=' . ($r[1] ? 1 : 0), $twin)));

// ------------------------------------------------------------------ v29. a service by kind
head('v29. [pt19 v1.0 / S5] a service by KIND picks the one real entry of that kind on a root list');
$root29 = ['What have you got for sale?', "I'd like to rent a room. (10 gold)", 'Heard any rumors lately?', 'Nasty business, that execution.'];
$rows29 = [v1Row($root29[0], ['toplevel' => 1, 'scripted' => 1, 'topic' => 'OfferServicesTopic']), v1Row($root29[1], ['toplevel' => 1, 'topic' => 'RentRoomTopic']),
    v1Row($root29[2], ['toplevel' => 1, 'scripted' => 1, 'topic' => 'DBRumorsTopic']), v1Row($root29[3], ['toplevel' => 1]),
    v1Row('Let me see your wares.', ['toplevel' => 1, 'scripted' => 1])];
$k29 = static function (string $say, int $clicks, array $list = []) use ($rows29, $root29): string {
    v1Reset($rows29, $clicks);
    $l = $list ?: $root29;
    v1Topics('Hulda V1', $l, ['layer' => 0, 'gen' => 0]);
    v1Say('Hulda V1', $say);
    $w = v1Topics('Hulda V1', $l, ['layer' => 0, 'gen' => 1, 'want' => 1, 'cid' => 'w29']);
    return preg_match('/;pos=(\d+);/', $w['echo'], $m) ? $m[1] : '-';
};
foreach (['show me your wares', 'what have you got?'] as $say) {
    chk('v29 Hulda root + "' . $say . '" -> OfferServices (pos 0) at clicks_ok 1', $k29($say, 1) === '0');
    chk('v29 ...and nothing at clicks_ok 0 (the service line is scripted: the rail)', $k29($say, 0) === '-');
}
foreach (['I need a bed', "I'd like a room", 'can I get a room'] as $say) {
    chk('v29 Hulda root + "' . $say . '" -> RentRoom (pos 1)', $k29($say, 1) === '1');
}
chk('v29 "what have you got against the Stormcloaks" -> nothing', $k29('what have you got against the Stormcloaks', 1) === '-');
foreach (['any rumors?', 'heard any rumors?'] as $say) {
    $p = $k29($say, 1);
    chk('v29 [U4] "' . $say . '" -> the rumours line or nothing, NEVER RentRoom', $p !== '1', $p);
}
chk('v29 two barter entries -> nothing (she asks which)', $k29('show me your wares', 1, ['What have you got for sale?', 'Let me see your wares.', 'Nasty business, that execution.']) === '-');
// model F18: a voice order of food or drink owns its turn (10.27) - the gate's kind pick stands down beside it too
v1Reset($rows29, 1);
v1Topics('Hulda V1', $root29, ['layer' => 0, 'gen' => 0]);
v1Say('Hulda V1', "I'd like to buy a mead");
$GLOBALS['LRG_DLG_TURN']['buy'] = ['state' => 'queued'];
$r = v1Llm('Hulda V1', 'let me buy something', 'A mead, coming up.');
$pk = array_values(array_filter($r['out'], static fn($l) => str_contains((string) $l, ';do=pick;')));
unset($GLOBALS['LRG_DLG_TURN']['buy']);
v1Say('Hulda V1', "I'd like to buy something");
$r2 = v1Llm('Hulda V1', 'let me buy something', 'Take a look.');
$pk2 = array_values(array_filter($r2['out'], static fn($l) => str_contains((string) $l, ';do=pick;')));
chk('v29 [F18] an active voice order (queued): the gate\'s kind pick stands down (no trade line beside ExtCmdLRG_Buy); with none it picks',
    $pk === [] && count($pk2) === 1 && str_contains((string) $pk2[0], ';pos=0;'), json_encode($pk) . ' / ' . json_encode($pk2));
chk('v29 a follower kind never picks by kind',lrgDlgKindPick([['class' => 'service', 'text' => 'Follow me.', 'pos' => 0, 'norm' => 'follow me']], 'come with me', 'root')['entry'] === null);
$daysE = [];
foreach (['1 day. (25 gold)', '2 days. (50 gold)', '3 days. (75 gold)', '4 days. (100 gold)', '5 days. (125 gold)', '6 days. (150 gold)', '7 days. (175 gold)'] as $i => $d) {
    $daysE[] = ['pos' => $i, 'text' => $d, 'norm' => lrgPromptNorm($d), 'class' => 'pay', 'cost' => lrgPromptCost($d)];
}
$ds = static fn(string $u) => (array) lrgDlgServiceSlot($daysE, $u);
chk('v29 [U3] "one night" -> 1 day', (int) (((array) ($ds('one night')['entry'] ?? []))['pos'] ?? -1) === 0, json_encode($ds('one night')));
chk('v29 [U3] "two nights" -> 2 days', (int) (((array) ($ds('two nights')['entry'] ?? []))['pos'] ?? -1) === 1, json_encode($ds('two nights')));
chk('v29 [U3] "a room for the night" -> 1 day', (int) (((array) ($ds('a room for the night')['entry'] ?? []))['pos'] ?? -1) === 0, json_encode($ds('a room for the night')));
chk('v29 [U3] "not tonight" -> nothing; "how much for a night" -> price; "for a week" -> she asks',
    ($ds('not tonight')['mode'] ?? '') === 'none' && ($ds('how much for a night')['mode'] ?? '') === 'price' && in_array($ds('for a week')['mode'] ?? '', ['none', 'ask'], true),
    json_encode([$ds('not tonight'), $ds('how much for a night'), $ds('for a week')]));
chk('v29 [U3] a literal "1 day" still picks, and "3 days" no longer asks between six identical "# days" slots',
    (int) (((array) ($ds('1 day')['entry'] ?? []))['pos'] ?? -1) === 0 && ($ds('3 days')['mode'] ?? '') === 'pick');

// ------------------------------------------------------------------ v13j. [Lane B second pass] prompt size
head('v13j. [pt19 v1.0] the <business> size slot - Lane B adds its S11 assertions here in its second pass (spec 2.2 Lane B)');
// [pt19 v1.0 / S11, S12, spec 2.2 Lane B] the diet: <business> keeps four standing rules plus the conditional never-false rails;
// <real_business> is suppressed while <business> is present; "(+N more)" is a count; the budget is measured on the REAL path
$jTexts = ['What can you tell me about Whiterun?', 'Any news from the war?', "I'd like a room for the night. (10 gold)", 'What do you have for sale?',
    'You should really let me see the Jarl. (Persuade)', 'I want to join the Companions.', 'Tell me about the Bannered Mare.', 'Who owns this place?',
    'Have you seen anything strange lately?', 'I am looking for work.', 'What is the best drink here?', 'Where can I find the Jarl?', 'Do you know Uthgerd?',
    'Goodbye.'];
$jRows = [];
foreach ($jTexts as $i => $tx) {
    $jRows[] = v1Row($tx, ['kind' => $i === 4 ? 'persuade' : '', 'cost' => $i === 2 ? 10 : 0, 'goodbye' => $i === 13 ? 1 : 0, 'toplevel' => 1, 'quest' => 'DialogueWhiterun']);
}
v1Reset($jRows, 1);
v1Snap('Hulda J', ['fac' => 'JobInnkeeperFaction', 'gold' => '300', 'pgold' => '300', 'dist' => '100', 'combat' => '0', 'scene' => '0']);
v1Open('Hulda J');
v1Topics('Hulda J', $jTexts, ['n' => 20]);
$rJ = v1Say('Hulda J', 'hmm, let me think about what I wanted');
$tJ13 = $rJ['t'];
$bJ13 = lrgDlgBusinessBlock($tJ13);
$keysJ = count((array) (($tJ13['offer'] ?? [])['keys'] ?? []));
chk('v13j the real turn offers the list (' . $keysJ . ' keys, ' . count((array) ($tJ13['tail'] ?? [])) . ' in the (+N more) count)', $keysJ >= 8, fx_cut($bJ13));
chk('v13j kept rail 1 by text: "You may NOT say that a thing is not on the table" rides while sent < n (14 of 20)',
    str_contains($bJ13, 'You may NOT say that a thing is not on the table') && str_contains($bJ13, '(14 of 20)'), fx_cut($bJ13));
chk('v13j kept rail 2 by text: an offered persuasion puts "Never say whether a persuasion, a threat or a bribe worked; you are told afterwards." in <business>',
    str_contains($bJ13, "Never say whether a persuasion, a threat or a bribe worked; you are told afterwards.\n"), fx_cut($bJ13));
chk('v13j <real_business> is SUPPRESSED while <business> is present (the four standing rules say it once)', lrgDlgStaticGuidance($tJ13) === '');
chk('v13j "(+N more)" is a count only - no word bucket', !preg_match('/\(\+\d+ more:/', $bJ13) && (!($tJ13['tail'] ?? []) || preg_match('/\(\+\d+ more\)\n/', $bJ13)), fx_cut($bJ13));
chk('v13j the four standing rules and nothing else of the old block: one key when his words do it, two fit -> ask, never empty, a [commits] key quotes the choice',
    substr_count($bJ13, 'ONLY when') === 1 && str_contains($bJ13, 'Two keys fit, or unsure') && str_contains($bJ13, 'never leave message empty')
    && str_contains($bJ13, 'quoting the choice in its own words'));
$tNoKind = $tJ13;
$tNoKind['entries'] = array_values(array_filter((array) $tJ13['entries'], static fn($e) => (string) ($e['kind'] ?? '') === ''));
chk('v13j ...and no persuasion / threat / bribe offered: the outcome rule is not said at all', !str_contains(lrgDlgBusinessBlock($tNoKind), 'Never say whether a persuasion'));
// THE BUDGET: the 12-key worst case (a CLOSED layer: closed_cap 12, never bucketed) with both kept rails AND the bridging directive
// (an open_pending turn), all of it counted
$jRowsC = [];
foreach ($jTexts as $i => $tx) { $jRowsC[] = v1Row($tx, ['kind' => $i === 4 ? 'persuade' : '', 'cost' => $i === 2 ? 10 : 0, 'toplevel' => 0]); }
v1Reset($jRowsC, 1);
v1Snap('Hulda J', ['fac' => 'JobInnkeeperFaction', 'gold' => '300', 'pgold' => '300', 'dist' => '100', 'combat' => '0', 'scene' => '0']);
v1Open('Hulda J');
v1Topics('Hulda J', $jTexts, ['n' => 20, 'layer' => 2]);
$tBud = v1Say('Hulda J', 'hmm, let me think about what I wanted')['t'];
chk('v13j the closed layer offers 12 keys (' . count((array) (($tBud['offer'] ?? [])['keys'] ?? [])) . ', layer ' . (string) ($tBud['layer_kind'] ?? '?') . ')',
    count((array) (($tBud['offer'] ?? [])['keys'] ?? [])) === 12);
$tBud['open_pending'] = 1;
$promptJ = lrgDlgStaticGuidance($tBud) . lrgDlgLockedBlock($tBud) . lrgDlgVolatileGuidance($tBud);
chk('v13j the business turn with the two kept rails and the bridge is <= 2,500 chars (' . strlen($promptJ) . ')', strlen($promptJ) <= 2500
    && str_contains($promptJ, 'The list of what he can raise is being brought up now') && str_contains($promptJ, 'You may NOT say'), (string) strlen($promptJ));
// the locked block: the 600-char body cap holds with the faction line and the reward line both in it (lib/lrg_speech.php)
$lbJ = lrgDlgLockedBlock(['npc' => 'Hulda J', 'cid' => 'j13', 'locked' => [['class' => 'faction', 'line' => str_repeat('a faction fact ', 20), 'num' => null],
    ['class' => 'reward', 'line' => lrgDlgRewardLine(), 'num' => null], ['class' => 'gold', 'line' => str_repeat('x', 300), 'num' => 5]]]);
$lbBody = (string) preg_replace('/^.*?quest step\.\n/s', '', (string) preg_replace('/<\/locked_facts>$/', '', $lbJ));
chk('v13j the locked block body stays <= 600 chars (a line that would pass the cap is dropped whole) and the reward line fits after a 300-char faction line',
    strlen($lbBody) <= 600 && str_contains($lbJ, 'the reward is what the world gives') && !str_contains($lbJ, str_repeat('x', 300)), (string) strlen($lbBody));
// [pt19c-B fix 1 / ai review 9] THE FIRST EVENING's quest turn: no click verified yet (the stage-rail line rides), one journal quest of
// hers (<shared_business> and the task line), the bridge, and a bargaining sentence (the reward line) on the 12-key closed layer.
// S12 counts TWO budgets - "dialogue prompt on a business turn <= 2,500; locked <= 600" - so they are measured apart, the sum reported.
// a quest giver's list: no priced line on it (a priced line makes it a market turn, where the reward line stays out - game review 3)
$jTextsQ = $jTexts;
$jTextsQ[2] = 'What do you know about the dragon attack?';
$jRowsQ = [];
foreach ($jTextsQ as $i => $tx) { $jRowsQ[] = v1Row($tx, ['kind' => $i === 4 ? 'persuade' : '', 'toplevel' => 0]); }
v1Reset($jRowsQ, 0);
$GLOBALS['LRG_DLG_TEST_QUESTLOG'] = ['MQ104' => ['briefing' => 'Speak to Farengar about the dragon attack at the western watchtower', 'stage' => 10]];
v1Snap('Hulda F', ['fac' => 'JobInnkeeperFaction', 'gold' => '300', 'pgold' => '300', 'dist' => '100', 'combat' => '0', 'scene' => '0']);
v1Open('Hulda F', ['q' => 'MQ104']);
v1Topics('Hulda F', $jTextsQ, ['n' => 20, 'layer' => 2, 'q' => 'MQ104']);
$tFe = v1Say('Hulda F', "that's not enough, I want more gold for this")['t'];
$tFe['open_pending'] = 1;
$lockFe = lrgDlgLockedBlock($tFe);
$restFe = lrgDlgStaticGuidance($tFe) . lrgDlgVolatileGuidance($tFe);
preg_match_all('/^- .*$/m', $lockFe, $mFe);
$lockBodyFe = (int) array_sum(array_map(static fn($l) => strlen($l) + 1, $mFe[0]));
chk('v13j the FIRST EVENING quest turn (clicks_ok 0, a journal quest, the bridge, a bargain): the dialogue prompt <= 2,500 (' . strlen($restFe)
    . '), the locked body <= 600 (' . $lockBodyFe . '), ' . (strlen($restFe) + strlen($lockFe)) . ' chars in all',
    strlen($restFe) <= 2500 && $lockBodyFe <= 600 && str_contains($restFe, 'ask me something simple first') && str_contains($restFe, '<shared_business>')
    && str_contains($lockFe, 'the reward is what the world gives') && str_contains($restFe, 'The list of what he can raise is being brought up now'),
    json_encode(['rail' => str_contains($restFe, 'ask me something simple first'), 'quest' => str_contains($restFe, '<shared_business>'),
        'reward' => str_contains($lockFe, 'the reward is what the world gives'), 'bridge' => str_contains($restFe, 'The list of what he can raise is being brought up now')]));
unset($GLOBALS['LRG_DLG_TEST_QUESTLOG']);

// ------------------------------------------------------------------ v40e. [Lane B / S7] a gate refusal is told next turn, in plain words
head('v40e. [pt19 v1.0 / S7, spec test 40] the voice gap: a pick the gate refused is told next turn as "Nothing came of it: <plain sentence>"');
v1Reset([v1Row("I'd like a room for the night. (10 gold)", ['cost' => 10])], 1);
v1Snap('Hulda R', ['fac' => 'JobInnkeeperFaction', 'gold' => '300', 'pgold' => '5', 'dist' => '100', 'combat' => '0', 'scene' => '0']);
v1Open('Hulda R');
v1Topics('Hulda R', ["I'd like a room for the night. (10 gold)", 'Goodbye.'], ['pg' => 5]);
v1Say('Hulda R', 'give me the room then');
$r40 = v1Llm('Hulda R', 'T1', 'Here is your key.');
$lr40 = (array) (lrgDlgState('Hulda R')['refusal_note'] ?? []);
// [pt19c-B fix 1 / CHIM review, use review P2] the note has its OWN key (Phase 2's last_result is never overwritten by it)
chk('v40e the model chose the priced line he cannot pay for: nothing is emitted, and the refusal is kept as code afford (refusal_note)', !$r40['emitted']
    && (string) ($lr40['code'] ?? '') === 'afford' && empty(((array) (lrgDlgState('Hulda R')['last_result'] ?? []))['refusal']), json_encode($lr40));
$n40 = v1Say('Hulda R', 'so what now');
$pl40 = (string) ($GLOBALS['PLAYER_NAME'] ?? 'the player');
chk('v40e ...his next turn: <what_just_happened> says "Nothing came of it: ' . $pl40 . ' could not pay what that costs." - no digit, no gate word, no bare he / she',
    str_contains($n40['biz'], "Nothing came of it: " . $pl40 . " could not pay what that costs.\n")
    && !preg_match('/Nothing came of it:[^\n]*(\d|gate|afford|priced|\bshe\b|\bhe\b)/', $n40['biz']),
    fx_cut((string) strstr($n40['biz'], '<what_just_happened>')));

// ------------------------------------------------------------------ v27. [Lane B / S6.2, gate B] the bounded bonus, switched on for this check only
head('v27. [pt19 v1.0 / S6.2, gate B - OFF in v1.0] the reward bargain: inside the window only, a real check, the cap, the refusals');
$GLOBALS['LRG_DLG_STATE'] = [];
$GLOBALS['LRG_DLG_TEST_CFG'] = ['checks.reward.enabled' => true];
$rq = static function (string $npc, string $utter, int $gold = 200, int $sp = 100, bool $window = true, array $snapX = [], bool $fresh = true): array {
    lrgDlgPut($npc, ['utter' => ['text' => $utter, 'at' => lrgNow(), 'type' => 'inputtext', 'cid' => 'r27'], 'checks' => $fresh ? [] : (lrgDlgState($npc)['checks'] ?? []),
        'last_result' => $window ? ['ok' => true, 'at' => lrgNow() - 30, 'entry' => 'What about my reward?', 'told' => true] : null]);
    return ['npc' => $npc, 'cid' => 'r27', 'on' => true, 'speech' => true, 'quests' => '<shared_business>x</shared_business>', 'q' => ['MQ104'],
        'entries' => [], 'tail' => [], 'facts' => ['sp' => $sp, 'pg' => 300], 'snap' => $snapX + ['gold' => (string) $gold, 'pspeech' => (string) $sp, '_age' => 1]];
};
$askS = ['I deserve more than this, a hundred septims', 'can you sweeten the deal a little', "what's in it for me, I expected more gold"];
foreach ($askS as $a) {
    $in = lrgDlgCheckKind(['kind' => 'none', 'conf' => 'low'], '', $rq('Balgruuf R', $a));
    $out = lrgDlgCheckKind(['kind' => 'none', 'conf' => 'low'], '', $rq('Balgruuf R', $a, 200, 100, false));
    chk('v27 "' . $a . '" -> persuade INSIDE the window, \'\' outside (a merchant haggle is never a check)', $in === 'persuade' && $out === '', "$in / $out");
}
$GLOBALS['LRG_DLG_TEST_QUEUE'] = [];
$c27 = lrgDlgCheck($rq('Irileth R', 'I deserve more than this, a hundred septims', 200, 100), ['kind' => 'none', 'conf' => 'low']);
chk('v27 a real check at stakes reward (band 2): Speech 100 passes, the bonus is the named 100 clamped by the cap, awarded with give=',
    is_array($c27) && $c27['stakes'] === 'reward' && $c27['result'] === 'pass' && (int) $c27['give'] > 0 && (int) $c27['give'] <= 100 && !empty($c27['award']), json_encode($c27));
// [pt19c-B fix 1 / CHIM review, brief fact 7 / P5] the bonus stated pre-LLM MOVES pre-LLM: its D2 row is queued by the check itself, and
// the post-gate's line reuses that x (one execution: the driver de-dups D1 / D2 by x) - a reply cut short cannot strand the promise
$q27 = array_values(array_filter((array) $GLOBALS['LRG_DLG_TEST_QUEUE'], static fn($r) => str_contains((string) ($r['action'] ?? ''), ';do=award;')));
$x27 = (string) ($c27['award_x'] ?? '');
chk('v27 the award D2 row is queued PRE-LLM with give= and the check carries its x', count($q27) === 1 && $x27 !== ''
    && str_contains((string) $q27[0]['action'], ';x=' . $x27 . ';') && str_contains((string) $q27[0]['action'], ';give='), json_encode($q27));
$aw27 = lrgDlgAwardLine(['npc' => 'Irileth R', 'cid' => 'r27', 'check' => $c27]);
$q27b = array_values(array_filter((array) $GLOBALS['LRG_DLG_TEST_QUEUE'], static fn($r) => str_contains((string) ($r['action'] ?? ''), ';do=award;')));
chk('v27 ...the post-gate\'s award line carries the SAME x and queues no second row', str_contains($aw27, ';x=' . $x27 . ';') && count($q27b) === 1, $aw27);
// [ai review 3] the fixed-reward locked line never rides beside the reward check that just passed (<reward_talk> states it)
$t27 = ['check' => $c27] + $rq('Irileth R', 'I deserve more than this, a hundred septims', 200, 100, true, [], false);
chk('v27 [ai review 3] no "you cannot add septims" locked line beside the passed bonus: <reward_talk> alone states the outcome',
    !in_array('reward', array_column(lrgDlgLockedFacts($t27), 'class'), true) && str_contains(lrgDlgRewardTalk($c27), 'septims of your own'));
$c27b = lrgDlgCheck($rq('Irileth R', 'Surely that is worth more to you, I deserve more than this', 200, 100, true, [], false), ['kind' => 'none', 'conf' => 'low']);
chk('v27 ...once per quest per NPC: the second ask is answered "fixed", nothing more is given', is_array($c27b) && $c27b['result'] === 'fixed' && empty($c27b['give']), json_encode($c27b));
$c27f = lrgDlgCheck($rq('Olfina R', 'I deserve more than this, a hundred septims', 200, 5), ['kind' => 'none', 'conf' => 'low']);
chk('v27 Speech 5 fails the live band: no bonus', is_array($c27f) && $c27f['result'] === 'fail' && empty($c27f['give']), json_encode($c27f));
$c27n = lrgDlgCheck($rq('Lars R', 'I deserve more than this, a hundred septims', 0, 100), ['kind' => 'none', 'conf' => 'low']);
chk('v27 pocket 0: no check runs, she carries no coin (and never promises later)', is_array($c27n) && $c27n['result'] === 'nocoin'
    && str_contains(lrgDlgRewardTalk($c27n), 'never promise to pay him later'), json_encode($c27n));
$c27i = lrgDlgCheck($rq('Irileth S', 'I would rather have a horse instead of gold, I deserve more', 200, 100), ['kind' => 'none', 'conf' => 'low']);
chk('v27 an item / house / horse / title ask is refused in her words - the reward is fixed', is_array($c27i) && $c27i['result'] === 'fixed', json_encode($c27i));
// [pt19c-B fix 1 / lang review P7] only when the house / horse / title / favour IS the ask: a septim bargain that merely names one is checked
$p7 = [];
foreach (['I did you a favour, I deserve more septims', 'I lost my horse on this job, pay me more', 'as Thane of Whiterun I deserve more'] as $i7 => $a7) {
    $c7 = lrgDlgCheck($rq('Irileth P' . $i7, $a7, 200, 100), ['kind' => 'none', 'conf' => 'low']);
    if (!is_array($c7) || $c7['result'] === 'fixed') { $p7[] = $a7 . ' -> ' . json_encode($c7['result'] ?? null); }
}
chk('v27 [lang P7] "I did you a favour, I deserve more septims", "I lost my horse ..., pay me more", "as Thane ... I deserve more" are septim bargains, not item asks',
    $p7 === [], implode(' | ', $p7));
chk('v27 the named amount is clamped: min(named, purse, one day of her wage, max_gold 500)', lrgDlgRewardAmount($rq('X R', 'x', 40), 'give me a thousand septims') <= 40
    && lrgDlgRewardAmount($rq('X R', 'x', 5000), 'give me a thousand septims') <= 500);
unset($GLOBALS['LRG_DLG_TEST_CFG']);
chk('v27 gate A (the switch off): the same ask inside the window is no check', lrgDlgCheckKind(['kind' => 'none', 'conf' => 'low'], '', $rq('Balgruuf R', $askS[0])) === '');
unset($GLOBALS['LRG_DLG_TEST_OVERRIDES'], $GLOBALS['LRG_DLG_TEST_CFG'], $GLOBALS['LRG_DLG_TURN']);
$GLOBALS['LRG_DLG_STATE'] = [];
$GLOBALS['LRG_TEST_NOW'] = 1700000000;


// ------------------------------------------------------------------ v31. [pt19c fixer] the adversarial round
head('v31. [pt19c fixer] the adversarial QA and the walkthrough: a question, a negation, a bargain or a near-miss never clicks');
$GLOBALS['LRG_TEST_NOW'] = 1700400000;
// ---- G1 per-word parity (@negation, @adv_neg_subject, @adv_neg_plain)
$ng = static fn(string $u, string $e): bool => lrgDlgNegationClash($u, ['text' => $e, 'norm' => lrgPromptNorm($e)]);
$legionAP = "I don't want to sit idly by after what I saw at Helgen. I want to join the Legion.";
chk('v31 G1: "I don\'t want to join the Legion" negates the AP join line although the line has its own "don\'t" (per-word parity)', $ng("I don't want to join the Legion", $legionAP));
chk('v31 G1: "I want to join the Legion" does not', !$ng('I want to join the Legion', $legionAP));
chk('v31 G1: a negated PREDICATE covers its subject - "the Dark Brotherhood hasn\'t come" / "... has not come"',
    $ng("the Dark Brotherhood hasn't come", 'The Dark Brotherhood has come, Grelod.') && $ng('the Dark Brotherhood has not come', 'The Dark Brotherhood has come, Grelod.'));
chk('v31 G1: nobody / refuse negate ("nobody told me to see you", "I refuse your test")',
    $ng('nobody told me to see you', 'I was told to come see you.') && $ng('I refuse your test', 'About that test...'));
chk('v31 G1: an idiom negates nothing ("there\'s no point earning all that gold if ...")',
    !$ng("there's no point earning all that gold if the dragons kill you all", 'What good is all that gold if the dragons kill you all?'));
chk('v31 G1: a sentence end is a clause end ("I have news. It isn\'t good." negates no news)', !$ng("I have news. It isn't good.", 'I have news from Helgen.'));
// ---- the question's kind (@adv_q_plain, @adv_q_single, @adv_frame)
$qa = static fn(string $u, string $e): bool => lrgDlgQuestionsAgree($u, ['text' => $e, 'norm' => lrgPromptNorm($e)]);
chk('v31 a question word against a yes/no line disagrees: "what does a bard do?" / "Does that mean I\'m a bard now?", "do you mind?" / "What do you have in mind?"',
    !$qa('what does a bard do?', "Does that mean I'm a bard now?") && !$qa('do you mind?', 'What do you have in mind?') && !$qa('what old stone?', 'Oh, do you mean this old stone?'));
chk('v31 ...unless the yes/no line carries that word, asks for any-thing, or the STT clipped the question word',
    $qa('so where do I come in', "And that's where I come in?") && $qa('what else can I help with', 'Is there anything else I can help with?')
    && $qa('do you have in mind', 'What do you have in mind?') && $qa('do these greybeards want with me', 'What do these Greybeards want with me?'));
chk('v31 "what ... for?" asks why: "what do you need a poem for?" is no "What do you need me to do?"; "why do the Greybeards want me" (no word of his own) is "What do these Greybeards want with me?"',
    lrgDlgQuestionKind('what do you need a poem for?') === 'wh:why' && !$qa('what do you need a poem for?', 'What do you need me to do?')
    && $qa('why do the Greybeards want me', 'What do these Greybeards want with me?'));
chk('v31 two yes/no questions about another subject: "do dragons sing ballads?" is no "Do you know any old ballads about dragons?"',
    !$qa('do dragons sing ballads?', 'Do you know any old ballads about dragons?') && $qa('is this the stone you mean', 'Oh, do you mean this old stone?'));
chk('v31 a request shaped as a question is a request ("can I rent a room?", "could you spare some supplies"), a doubt is not ("can we even kill a dragon?")',
    lrgDlgIsRequest('can I rent a room?') && lrgDlgIsRequest('could you spare some supplies') && !lrgDlgIsRequest('can we even kill a dragon?')
    && !lrgDlgIsRequest('is the room expensive?'));
// ---- bargains, vocatives, own words
chk('v31 a bargain or a payment condition (@adv_bargain) - a courtesy "if you\'ll have me" is none',
    lrgDlgBargains("I'll kill the ice wraith for fifty septims", []) && lrgDlgBargains("I'll join the Legion if you pay me five hundred septims", [])
    && lrgDlgBargains("I have your shield, that'll be twenty septims for the delivery", []) && !lrgDlgBargains("I wish to join the Companions, if you'll have me", [])
    && !lrgDlgBargains('here, fifty septims if you let me through', ['kind' => 'bribe', 'cost' => 50]));
chk('v31 the vocative is no content ("..., my jarl", "... with sir", "..., Aela"); "to the jarl" keeps it',
    lrgDlgStripVocative('what else can I help you with, my jarl') === 'what else can I help you with' && lrgDlgStripVocative('else can i help you with sir') === 'else can i help you with'
    && lrgDlgStripVocative('I have your shield, Aela', 'Aela the Huntress') === 'I have your shield' && lrgDlgStripVocative('I need to speak to the jarl') === 'I need to speak to the jarl');
chk('v31 his own words: "Farengar" is one, an STT echo is none ("lauren" / learn, "a long" / along, "grey lod" / Grelod, "your gun" / Jurgen)',
    lrgDlgOwnWords('I need to talk to Farengar', ['norm' => 'i need to talk to you']) === ['farengar']
    && lrgDlgOwnWords("i'm ready to lauren", ['norm' => "i'm ready to learn"]) === [] && lrgDlgOwnWords("i'll come a long with you", ['norm' => "i'll come along with you"]) === []
    && lrgDlgOwnWords('the dark brotherhood has come grey lod', ['norm' => 'the dark brotherhood has come grelod']) === []
    && lrgDlgOwnWords('i have the horn of your gun windcaller', ['norm' => 'i have the horn of jurgen windcaller']) === []);
// ---- the explicit test (@adv_frame, @adv_bargain, @adv_facask_q) on a live commit layer
$ex = static function (string $u, string $line, string $mode = 'key', array $sib = ["What's the matter?"]): bool {
    $es = [];
    foreach (array_merge([$line], $sib) as $i => $tx) { $es[] = ['pos' => $i, 'i' => 100 + $i, 'text' => $tx, 'norm' => lrgPromptNorm($tx), 'class' => $i ? 'plain' : 'commit', 'commit' => $i === 0, 'kind' => '', 'cost' => 0, 'crit' => 0, 'scripted' => 1, 'indexed' => 1, 'compound' => 0, 'variant' => 'na']; }
    return lrgDlgExplicit(['npc' => 'Balgruuf V31', 'speech' => true, 'entries' => $es, 'tail' => [], 'crit' => 0, 'arrest' => '', 'facts' => ['pg' => 300]], $es[0], $u, $mode, []);
};
chk('v31 explicit: "I need to talk to Farengar" is no "I need to talk to you."; "well, I need to talk to you" is (G7 fillers)',
    !$ex('I need to talk to Farengar', 'I need to talk to you.') && $ex('well, I need to talk to you', 'I need to talk to you.'));
chk('v31 explicit: "I need to talk to you about the civil war" is no "I need to talk to you about Helgen."', !$ex('I need to talk to you about the civil war', 'I need to talk to you about Helgen.'));
chk('v31 explicit: "what would change your mind?" is no "Will this change your mind?"', !$ex('what would change your mind?', 'Will this change your mind?'));
chk('v31 explicit: a bargain never, on any path - "I\'ll kill the ice wraith for fifty septims" / the enlistment line itself',
    !$ex("I'll kill the ice wraith for fifty septims", "I'm off to kill that ice wraith...") && !$ex("I'll join the Legion if you pay me five hundred septims", 'I want to join the Legion', 'faction'));
chk('v31 explicit: a question word is no join ask ("what does it take to join the Companions?"); "can I join the Companions" still is',
    !$ex('what does it take to join the Companions?', 'I would like to join the Companions.', 'faction') && $ex('can I join the Companions', 'I would like to join the Companions.', 'faction'));
chk('v31 the enlistment ask: "what does it take to join ...?" no, "how do I enlist" yes, "I\'d like to be a Companion" yes, "I want to join the Legion, but what\'s in it for me?" yes',
    empty(lrgFacAsk('what does it take to join the Companions?', ['companions'])['join']) && !empty(lrgFacAsk('how do I enlist', ['legion'])['join'])
    && (string) lrgFacAsk("I'd like to be a Companion", [])['faction'] === 'companions' && !empty(lrgFacAsk("I want to join the Legion, but what's in it for me?", ['legion'])['join']));
// ---- the single-entry release (@single_neg, @adv_rearm, @adv_q_single)
$apply = ['pos' => 0, 'text' => "I'm looking to apply to the college.", 'norm' => lrgPromptNorm("I'm looking to apply to the college."), 'scripted' => 0, 'commit' => false];
$r1 = lrgDlgSingleEntryRelease($apply, "I'm not looking to apply to the college");
$r2 = lrgDlgSingleEntryRelease($apply, 'why would anyone apply to the college?');
$r3 = lrgDlgSingleEntryRelease($apply, 'who are you?');
chk('v31 single: his negated restatement refuses (no auto-advance); a question in the line\'s own words refuses without the re-arm; another question re-arms',
    !empty($r1['refused']) && empty($r1['shape']) && !empty($r2['refused']) && empty($r2['shape']) && !empty($r3['refused']) && !empty($r3['shape']),
    json_encode([$r1, $r2, $r3]));
$bard = ['pos' => 0, 'text' => "Does that mean I'm a bard now?", 'norm' => lrgPromptNorm("Does that mean I'm a bard now?"), 'scripted' => 1, 'commit' => true];
chk('v31 single: "what does a bard do?" never completes "Does that mean I\'m a bard now?"; "so I\'m a bard now?" does',
    empty(lrgDlgSingleEntryRelease($bard, 'what does a bard do?')['release']) && !empty(lrgDlgSingleEntryRelease($bard, "so I'm a bard now?")['release']));
// ---- the days list (@adv_price, @adv_chain)
$daysF = [];
foreach (['1 day. (25 gold)', '2 days. (50 gold)', '3 days. (75 gold)'] as $i => $d) { $daysF[] = ['pos' => $i, 'text' => $d, 'norm' => lrgPromptNorm($d), 'class' => 'pay', 'cost' => lrgPromptCost($d)]; }
$dsF = static fn(string $u) => (array) lrgDlgServiceSlot($daysF, $u);
chk('v31 days: "twenty septims for one day" (below the 25) is the price question; "one day I\'ll come back" names no stay',
    ($dsF('twenty septims for one day')['mode'] ?? '') === 'price' && ($dsF("one day I'll come back")['mode'] ?? '') === 'none', json_encode([$dsF('twenty septims for one day'), $dsF("one day I'll come back")]));
chk('v31 days: "a single day" and "i\'d like a room for the nite" name 1 day; "thirty septims for one day" still picks it',
    (int) (((array) ($dsF('a single day')['entry'] ?? []))['pos'] ?? -1) === 0 && (int) (((array) ($dsF("i'd like a room for the nite")['entry'] ?? []))['pos'] ?? -1) === 0
    && ($dsF('thirty septims for one day')['mode'] ?? '') === 'pick');
chk('v31 inn kind: "a room for one night please" (no verb) asks for a room', lrgDlgServiceKindSaid('a room for one night please') === 'inn');
// ---- a service line is no commit by the heuristics; its price still is (M6, M7, W4)
$svcE = ['text' => 'What have you got for sale?', 'indexed' => 1, 'scripted' => 1, 'goodbye' => 1, 'new' => 0, 'cost' => 0, 'hub' => 0];
chk('v31 a scripted+goodbye SERVICE line is no commit ("What have you got for sale?", "I\'d like to hire your carriage.", training)',
    !lrgDlgIsCommit($svcE, true, 3, 300) && !lrgDlgIsCommit(['text' => "I'd like to hire your carriage."] + $svcE, true, 3, 300)
    && !lrgDlgIsCommit(['text' => "I'd like training in Alchemy."] + $svcE, true, 3, 300));
chk('v31 ...its PRICE still is (150 septims, or a quarter of his purse)', lrgDlgIsCommit(['cost' => 150] + $svcE, true, 3, 300) && lrgDlgIsCommit(['cost' => 25] + $svcE, true, 3, 80));
chk('v31 ...and a quest line with the same flags still is', lrgDlgIsCommit(['text' => 'I need to talk to you.'] + $svcE, true, 3, 300));
// ---- the fast path on Hulda's root: a question is no line, a polite request is (@adv_q_plain)
$fp = static function (string $say) use ($rows29, $root29): string {
    v1Reset($rows29, 1);
    v1Topics('Hulda V31', $root29, ['layer' => 0, 'gen' => 0]);
    v1Say('Hulda V31', $say);
    $w = v1Topics('Hulda V31', $root29, ['layer' => 0, 'gen' => 1, 'want' => 1, 'cid' => 'w31']);
    return preg_match('/;pos=(\d+);/', $w['echo'], $m) ? $m[1] : '-';
};
chk('v31 fast path: "is the room expensive?" rents nothing; "can I rent a room?" rents the room (pos 1)', $fp('is the room expensive?') === '-' && $fp('can I rent a room?') === '1');
chk('v31 fast path: "do you have any rooms?" is still a request for the room (the kind pick answers it); "how much for a room?" buys nothing',
    $fp('do you have any rooms?') === '1' && $fp('how much for a room?') === '-');
// ---- a parked CHECK is released by his assent: the rails judge the sentence that parked it (M3, W5)
$chkRow = v1Row('She even shackles the children to the walls. (Persuade)', ['kind' => 'persuade', 'scripted' => 1, 'goodbye' => 1, 'quest' => 'DB01']);
$chkList = ['She even shackles the children to the walls. (Persuade)', 'Never mind.'];
v1Reset([$chkRow, v1Row('Never mind.')], 1);
v1Topics('Riften Guard V31', $chkList);
v1Say('Riften Guard V31', 'she keeps the kids shackled to the wall, please act');
$p1 = v1Llm('Riften Guard V31', 'T1', 'You mean she chains the children up?');
$pk31 = (string) (((array) (lrgDlgState('Riften Guard V31')['parked'] ?? []))['norm'] ?? '');
$GLOBALS['LRG_TEST_NOW'] += 4;
v1Say('Riften Guard V31', 'yes');
$p2 = v1Llm('Riften Guard V31', 'T1', 'Then I will look into it.');
chk('v31 M3: a persuasion he did not say plainly parks; his "yes" releases it (the 4-word rail reads the parking sentence, never "yes")',
    v1Do($p1['out']) === '' && $pk31 !== '' && v1Do($p2['out']) === 'pick' && str_contains((string) ($p2['out'][0] ?? ''), ';kind=persuade;'), json_encode([$p1['out'], $pk31, $p2['out']]));
// ---- G1 on the park path: she never asks him to confirm what he negated
v1Reset([v1Row("I'd like to rent the attic room. (10 gold)", ['cost' => 10, 'scripted' => 1, 'goodbye' => 1]), v1Row('Tell me about the Blades.', ['scripted' => 1])], 1);
v1Topics('Delphine V31', ["I'd like to rent the attic room. (10 gold)", 'Tell me about the Blades.'], ['pg' => 30]);
v1Say('Delphine V31', "I don't want the attic room");
$ng31 = v1Llm('Delphine V31', 'T1', 'You want the attic room?');
chk('v31 "I don\'t want the attic room" + the model\'s key: nothing is parked (his "yes" would pay for what he refused)',
    v1Do($ng31['out']) === '' && empty(lrgDlgState('Delphine V31')['parked']), json_encode([$ng31['out'], lrgDlgState('Delphine V31')['parked'] ?? null]));
// ---- a check kind the fail-safe merge lent is no check (F3, F4)
$guardRow = v1Row('I have news from Helgen about the dragon attack. (Persuade)', ['kind' => 'persuade', 'tk' => 'v31:guard', 'topic' => 'DialogueWhiterunGuardGateStopPersuade']);
$irRow = v1Row('I have news from Helgen. About the dragon attack.', ['tk' => 'v31:irileth', 'topic' => 'MQ102IrilethIntroA1', 'quest' => 'MQ102', 'journal' => 1, 'scripted' => 1, 'goodbye' => 1]);
v1Reset([$guardRow, $irRow, v1Row('I need to speak to the jarl.', ['quest' => 'MQ102'])], 1);
v1Topics('Irileth V31', ['I have news from Helgen. About the dragon attack.', 'I need to speak to the jarl.'], ['q' => 'MQ102']);
$irE = (array) ((((array) (lrgDlgState('Irileth V31')['session'] ?? []))['entries'] ?? [])[0] ?? []);
chk('v31 F3: Irileth\'s news line carries no persuade kind lent by the gate guard\'s row (its own topic has none, its text no tag)',
    (string) ($irE['kind'] ?? '?') === '' && (string) ($irE['class'] ?? '') !== 'check', json_encode(array_intersect_key($irE, ['kind' => 1, 'class' => 1, 'label' => 1])));
// ---- wording: the leave guard only when he leaves, and a man is named; his list
$tgL = [v1Row('What do you have in mind?', ['walkaway' => 1, 'twat' => 'TG00BrynjolfWalkAwayTopic']), v1Row('What do I have to do?', ['walkaway' => 1, 'twat' => 'TG00BrynjolfWalkAwayTopic'])];
v1Reset($tgL, 1);
v1Snap('Brynjolf V31', ['sex' => '0', 'fac' => 'ThievesGuildFaction']);
v1Topics('Brynjolf V31', ['What do you have in mind?', 'What do I have to do?']);
$lq1 = v1Say('Brynjolf V31', 'what is the job about?');
$lq2 = v1Say('Brynjolf V31', 'I have to go.');
chk('v31 the leave guard rides only when he means to leave, and names a man ("ends things with Brynjolf V31", never "her")',
    !str_contains($lq1['biz'], 'backs out cleanly') && str_contains($lq2['biz'], 'it ends things with Brynjolf V31 - tell him so.') && !str_contains($lq2['biz'], 'ends things with her'),
    $lq2['biz']);
unset($GLOBALS['LRG_DLG_TURN'], $GLOBALS['LRG_TEST_NPCSTATE'], $GLOBALS['LRG_NPCSTATE_MEMO']);
$GLOBALS['LRG_DLG_STATE'] = [];

// ##################################################################################################
// [pt19h-safety] THE SAFETY FIXER's regressions (research/pt19h-safety.md): a question, a negation, a deferral, an echo, a hedge
// or a near-miss never clicks a commit, a crit, a priced, a scripted or an irreversible line - the measurer's own examples
// (research/pt19h-measure.md G1 G5 G8 G9 G10 G11, the name-word proxy) as must-not-click rows, and the lines that must still resolve.
// ##################################################################################################
head('pt19h-safety. refusals, deferrals, hedges, echoes and questions around a line; LEAVE; negation parity; the name word');
$sgR = static fn(array $r): string => !empty($r['release']) ? 'release' : (!empty($r['refused']) ? 'refused' : 'none');
$wqX = static fn(string $u, string $tx): string => lrgDlgWordsQualm($u, ['text' => $tx, 'norm' => lrgPromptNorm($tx), 'scripted' => 1, 'pos' => 0], []);
$cs = ['scripted' => 1, 'goodbye' => 1];   // a commit single (S4.1: scripted && goodbye)
// ---- G1 / G11: the $hit exemption holds only for the line's own words (single-entry layers, S4.5 step 0)
foreach ([
    ["I trust her to do the right thing.", 'not now, i trust her to do the right thing later', 'refused'],
    ["I trust her to do the right thing.", 'no, i trust her to do the right thing', 'refused'],
    ["I trust her to do the right thing.", "I'm not sure, i trust her to do the right thing", 'refused'],
    ["I trust her to do the right thing.", 'not yet, i trust her to do the right thing', 'refused'],
    ["I trust her to do the right thing.", 'do you trust her to do the right thing?', 'refused'],
    ["I'll restore the remaining Stones if that will help.", "wait, i'll restore the remaining Stones if that will help?", 'refused'],
    ["I'll restore the remaining Stones if that will help.", "I'll restore the remaining stones if that will help? what stones", 'refused'],
    ["I'll restore the remaining Stones if that will help.", "not now, i'll restore the remaining Stones if that will help later", 'refused'],
    ["He's here in Riverwood. Right outside.", "wait, he's here in Riverwood. Right outside?", 'refused'],
    ["He's here in Riverwood. Right outside.", "is it true that he's here in Riverwood. Right outside", 'refused'],
    ["He's here in Riverwood. Right outside.", "He's here in Riverwood. Right outside?", 'refused'],
    ["I've brought the fragment.", 'have you brought the fragment?', 'refused'],
    ["I'll keep that in mind.", 'what should I keep in mind', 'refused'],
    ["I'll keep that in mind.", "wait, i'll keep that in mind?", 'refused'],
    ['I have the Elder Scroll.', 'what do I do with the Elder Scroll?', 'refused'],
    ['Yes, sir!', 'wait, yes, sir?', 'refused'],
    ["I've brought the fragment.", "maybe I've brought the fragment", 'refused'],
] as [$tx, $u, $want]) {
    $rel = $sg($tx, $cs, $u);
    chk('pt19h-safety G1/G11/G5 commit single "' . substr($tx, 0, 34) . '": "' . substr($u, 0, 60) . '" -> ' . $want . ', never a release (' . $rel['step'] . ')',
        $sgR($rel) === $want, json_encode($rel));
}
foreach ([
    ["I trust her to do the right thing.", 'I trust her to do the right thing'],
    ["I'll restore the remaining Stones if that will help.", "yes, I'll restore the stones"],
    ["I'll restore the remaining Stones if that will help.", "I'll restore the remaining Stones if that will help."],
    ["He's here in Riverwood. Right outside.", "He's here in Riverwood. Right outside."],
    ["He's here in Riverwood. Right outside.", "he's right outside"],
    ["I've brought the fragment.", "I've brought the fragment"],
    ["I'll keep that in mind.", "yes, I'll keep that in mind"],
    ['Yes, sir!', 'yes sir'],
    ["I don't want to sit idly by after what I've witnessed. I want to join the Legion.", "I don't want to sit idly by, I want to join the Legion"],
] as [$tx, $u]) {
    $rel = $sg($tx, $cs, $u);
    chk('pt19h-safety must-resolve: commit single "' . substr($tx, 0, 34) . '" said plainly: "' . substr($u, 0, 50) . '" releases (' . $rel['step'] . ')',
        $sgR($rel) === 'release', json_encode($rel));
}
// his refusal that is not the line's own opening refuses (no key, no breath): "never mind" against "I don't understand ..."
$anc = "I don't understand what's going on.";
chk('pt19h-safety G1: "never mind" / "no, never mind" against the unscripted single "' . $anc . '" are refused (no auto-advance)',
    $sgR($sg($anc, [], 'never mind')) === 'refused' && $sgR($sg($anc, [], 'no, never mind')) === 'refused' && $sgR($sg($anc, [], "I don't understand what's going on")) === 'release');
v1Reset([v1Row($anc)], 1);
v1Topics('Ancano PT', [$anc]);
v1Say('Ancano PT', 'never mind');
$wA = v1Topics('Ancano PT', [$anc], ['gen' => 2, 'want' => 1, 'cid' => 'wpt1']);
chk('pt19h-safety G1: ... and the fast path emits no breath (adv pick) on it', !str_contains($wA['echo'], ';do=pick;'), $wA['echo']);
chk('pt19h-safety G10: "let\'s not" against "Then let\'s get to it." refuses; "let\'s get to it" releases',
    $sgR($sg("Then let's get to it.", ['scripted' => 1], "let's not")) === 'refused' && $sgR($sg("Then let's get to it.", ['scripted' => 1], "let's get to it")) === 'release');
// ---- G9 on singles: a near-miss (an opposite) and a statement against a question line release no scripted single
chk('pt19h-safety G9: "sounds hard" is no "Sounds easy.", "I lost the letter" no "I found this letter." (scripted singles); the lines themselves release',
    $sgR($sg('Sounds easy.', ['scripted' => 1], 'sounds hard')) !== 'release' && $sgR($sg('I found this letter.', ['scripted' => 1], 'I lost the letter')) !== 'release'
    && $sgR($sg('Sounds easy.', ['scripted' => 1], 'sounds easy')) === 'release' && $sgR($sg('I found this letter.', ['scripted' => 1], 'I found the letter')) === 'release');
chk('pt19h-safety G9: "I have a plan" is no "What\'s the plan?", "Thorald is alive" no "If Thorald is alive, where is he?" (scripted singles); "what\'s the plan" is',
    $sgR($sg("What's the plan?", ['scripted' => 1], 'I have a plan')) !== 'release'
    && $sgR($sg('If Thorald is alive, where is he?', ['scripted' => 1], 'Thorald is alive')) !== 'release'
    && $sgR($sg("What's the plan?", ['scripted' => 1], "what's the plan")) === 'release');
chk('pt19h-safety G9: a commit single takes only the SAME question ("what\'s on Alduin\'s Wall" / "Where can we find Alduin\'s Wall?"); "why do the Greybeards want me" still is "What do these Greybeards want with me?"',
    $sgR($sg("Where can we find Alduin's Wall?", $cs, "what's on Alduin's Wall")) !== 'release'
    && $sgR($sg('What do these Greybeards want with me?', $cs, 'why do the Greybeards want me')) === 'release');
chk('pt19h-safety G11: a line that asks inside and states at its end, asked back ("wait, really? Silus Vesuius says otherwise?", "is it true that esbern? Open the door. I\'m a friend") releases no protected single; the line itself does',
    $sgR($sg('Really? Silus Vesuius says otherwise.', ['scripted' => 1], 'wait, really? Silus Vesuius says otherwise?')) !== 'release'
    && $sgR($sg("Esbern? Open the door. I'm a friend.", ['scripted' => 1], "is it true that esbern? Open the door. I'm a friend")) !== 'release'
    && $sgR($sg("Gretolde? I'm here to deliver the enchanted sword you asked for.", $cs, "wait, gretolde? I'm here to deliver the enchanted sword you asked for?")) !== 'release'
    && $sgR($sg('Really? Silus Vesuius says otherwise.', ['scripted' => 1], 'Really? Silus Vesuius says otherwise.')) === 'release');
chk('pt19h-safety G9: "how are you doing?" is no "How am I doing?" (another person), "I believe you" no "Why should I believe you?", "can you read the Elder Scroll later?" no "Are you prepared to read the Elder Scroll?" (a deferral); "how am I doing" is',
    $sgR($sg('How am I doing?', ['scripted' => 1], 'how are you doing?')) !== 'release' && $sgR($sg('Why should I believe you?', ['scripted' => 1], 'I believe you')) !== 'release'
    && $sgR($sg('Are you prepared to read the Elder Scroll?', $cs, 'can you read the Elder Scroll later?')) !== 'release'
    && $sgR($sg('How am I doing?', ['scripted' => 1], 'how am I doing')) === 'release');
chk('pt19h-safety G5: "has Malborn got my gear" asks (no "Yes, Malborn\'s all set."); the STT\'s "has burn open the door" (Esbern) and "has to be done" state',
    lrgDlgIsQuestion('has Malborn got my gear') && !lrgDlgIsQuestion('has burn open the door') && !lrgDlgIsQuestion('has to be done')
    && $wqX('has Malborn got my gear', "Yes, Malborn's all set.") !== '');
chk('pt19h-safety G10: "that\'s not where I come in" clicks no "And that\'s where I come in?"; "Don\'t say I didn\'t warn you." keeps its two negators',
    $sgR($sg("And that's where I come in?", ['scripted' => 1], "that's not where I come in")) !== 'release'
    && !lrgDlgNegationClash("don't say I didn't warn you", ['text' => "Don't say I didn't warn you."]));
// ---- G1 / G5 / G9 / G10 / G11 on the explicit line (S4.3) - $ex builds a commit line and one plain sibling
foreach ([
    ["not now, no, Onmund. I'm asking you. Be the Arch-Mage instead of me later", "No, Onmund. I'm asking you. Be the Arch-Mage instead of me.", 'key'],
    ["I'm not sure, no, Onmund. I'm asking you. Be the Arch-Mage instead of me", "No, Onmund. I'm asking you. Be the Arch-Mage instead of me.", 'key'],
    ['who are the Blood Horkers', 'So where are the Blood Horkers?', 'key'],
    ['tell me where it is', 'Tell me where it is, or else.', 'key'],
    ['yes she wants you', 'Yes. She wants you to leave her alone.', 'key'],
    ['I made a mistake', 'I made a mistake. I want to be a Stormcloak. The crown belongs to you.', 'key'],
    ["who's behind the door", "Who's behind all this?", 'key'],
    ['I understand', 'Understand? How?', 'key'],
    ['where do I sign up for the Companions?', 'Killing vampires? Where do I sign up?', 'key'],
    ["I don't want to refuse your gift", "I don't want to become a vampire. I refuse your gift.", 'key'],
    ['that makes no sense', 'Makes sense.', 'key'],
    ["haven't you had enough of this", "I've had enough of this.", 'key'],
    ["I could just kill you now, couldn't I", 'Or I could just kill you now.', 'key'],
    ["I can't let you lead Thirsk? says who", "I can't let you lead Thirsk.", 'key'],
    ["I'd like to learn the Ash Guardian spell? is it any good", "I'd like to learn the Ash Guardian spell.", 'key'],
    ["is it true that i'm here to join the Dawnguard", "I'm here to join the Dawnguard.", 'key'],
    ["wait, i'm here to join the Dawnguard?", "I'm here to join the Dawnguard.", 'faction'],
    ['can anyone join the Dawnguard?', "I'm here to join the Dawnguard.", 'faction'],
    ["no, i'm here to join the Dawnguard", "I'm here to join the Dawnguard.", 'faction'],
    ["not now, i'm here to join the Dawnguard later", "I'm here to join the Dawnguard.", 'faction'],
    ['can anyone join the Companions?', 'Can I join the Companions?', 'faction'],
    ['maybe I want to fight as champion of the Imperial Legion', 'I want to fight as champion of the Imperial Legion.', 'key'],
    ['I want to fight as champion of the Stormcloaks', 'I want to fight as champion of the Imperial Legion and defeat Ulfric Stormcloak.', 'key'],
    ['I almost ran out of scrolls', 'I ran out of scrolls.', 'key'],
    ["I'll join you later", "I'll join you.", 'key'],
    ["what's the passphrase?", "Agreed. What's the passphrase?", 'key'],
] as [$u, $line, $mode]) {
    chk('pt19h-safety must-not-click (explicit, ' . $mode . '): "' . substr($u, 0, 60) . '" is no "' . substr($line, 0, 40) . '"', !$ex($u, $line, $mode));
}
foreach ([
    ["no Onmund, I'm asking you, be the Arch-Mage instead of me", "No, Onmund. I'm asking you. Be the Arch-Mage instead of me.", 'key'],
    ['so where are the Blood Horkers?', 'So where are the Blood Horkers?', 'key'],
    ['tell me where it is, or else', 'Tell me where it is, or else.', 'key'],
    ['yes, she wants you to leave her alone', 'Yes. She wants you to leave her alone.', 'key'],
    ["who's behind all this?", "Who's behind all this?", 'key'],
    ['are we reddy', 'Are we ready?', 'key'],
    ['where do I sign up to kill vampires', 'Killing vampires? Where do I sign up?', 'key'],
    ["I don't want to become a vampire", "I don't want to become a vampire. I refuse your gift.", 'key'],
    ["I've had enough of this", "I've had enough of this.", 'key'],
    ['or I could just kill you now', 'Or I could just kill you now.', 'key'],
    ["I'm here to join the Dawnguard", "I'm here to join the Dawnguard.", 'faction'],
    ['would like to join the companions', 'I would like to join the Companions.', 'faction'],
    ['can I join the Companions', 'Can I join the Companions?', 'faction'],
    ['I ran out of scrolls', 'I ran out of scrolls.', 'key'],
    ["so what's next", "So what's next?", 'key'],
    ["agreed, what's the passphrase?", "Agreed. What's the passphrase?", 'key'],
    ['I want to fight as champion of the Imperial Legion', 'I want to fight as champion of the Imperial Legion and defeat Ulfric Stormcloak.', 'key'],
] as [$u, $line, $mode]) {
    chk('pt19h-safety must-resolve (explicit, ' . $mode . '): "' . substr($u, 0, 60) . '" says "' . substr($line, 0, 40) . '"', $ex($u, $line, $mode));
}
// ---- G1, LLM path: the engine's own Yes / No layer is the confirmation of HIS yes only
$conf = ["I'm sure.", 'Never mind.'];
foreach (["I'm sure I need to think about it", 'never mind', 'no', "not now, i'm sure later", 'what happens then?'] as $u) {
    v1Reset([v1Row("I'm sure.", ['scripted' => 1, 'goodbye' => 1]), v1Row('Never mind.')], 1);
    v1Topics('Serana PT', $conf);
    v1Say('Serana PT', $u);
    $rc = v1Llm('Serana PT', 'T1', 'Are you certain?');
    chk('pt19h-safety G1 (LLM path): "' . $u . '" + her key on "I\'m sure." releases nothing on the engine\'s own confirmation layer', v1Do($rc['out']) === '' && !$rc['will'], json_encode($rc));
}
v1Reset([v1Row("I'm sure.", ['scripted' => 1, 'goodbye' => 1]), v1Row('Never mind.')], 1);
v1Topics('Serana PT', $conf);
v1Say('Serana PT', "yes, I'm sure");
$rc = v1Llm('Serana PT', 'T1', 'Then so be it.');
chk('pt19h-safety must-resolve (LLM path): "yes, I\'m sure" + her key releases "I\'m sure." at once', v1Do($rc['out']) === 'pick' && $rc['will'], json_encode($rc));
// ---- G5: a request stands for a line only when it asks for what the line says
chk('pt19h-safety G5: "will you be right back" / "can you help me cure myself?" / "can I kill the escaped prisoner?" are no "I\'ll be right back." / "I will help you cure yourself." / "I can kill the escaped prisoner."',
    lrgDlgWordsQualm('will you be right back', ['text' => "I'll be right back.", 'norm' => lrgPromptNorm("I'll be right back."), 'scripted' => 1], []) !== ''
    && lrgDlgWordsQualm('can you help me cure myself?', ['text' => 'I will help you cure yourself.', 'norm' => lrgPromptNorm('I will help you cure yourself.'), 'scripted' => 1], []) !== ''
    && lrgDlgWordsQualm('can I kill the escaped prisoner?', ['text' => 'I can kill the escaped prisoner.', 'norm' => lrgPromptNorm('I can kill the escaped prisoner.'), 'scripted' => 1], []) !== '');
chk('pt19h-safety G5 must-resolve: "can I rent a room?" is still "I\'d like to rent a room.", "could you tell me about Whiterun?" "Tell me about Whiterun."',
    lrgDlgWordsQualm('can I rent a room?', ['text' => "I'd like to rent a room.", 'norm' => lrgPromptNorm("I'd like to rent a room.")], []) === ''
    && lrgDlgWordsQualm('could you tell me about Whiterun?', ['text' => 'Tell me about Whiterun.', 'norm' => lrgPromptNorm('Tell me about Whiterun.')], []) === '');
// ---- G9 on the words path (a protected line): a statement against a question line, an opposite, a goodbye
$wq = static fn(string $u, string $tx): string => lrgDlgWordsQualm($u, ['text' => $tx, 'norm' => lrgPromptNorm($tx), 'scripted' => 1, 'pos' => 0], []);
chk('pt19h-safety G9 (words path, scripted line): "the situation is bad" / "What\'s the situation?", "I know what happened" / "What happened?", "we surrender" / "Do you surrender?", "close the door" / "Open the door.", "I\'ve finished that special Solitude job" / "... Markarth job.", "sorry, I have to go" / "Sorry. So, the Staff of Magnus?", "is the Augur dangerous" / "Have you ever heard of the Augur of Dunlain?"',
    $wq('the situation is bad', "What's the situation?") !== '' && $wq('I know what happened', 'What happened?') !== '' && $wq('we surrender', 'Do you surrender?') !== ''
    && $wq('close the door', 'Open the door.') !== '' && $wq("I've finished that special Solitude job", "I've finished that special Markarth job.") !== ''
    && $wq('sorry, I have to go', 'Sorry. So, the Staff of Magnus?') !== '' && $wq('is the Augur dangerous', 'Have you ever heard of the Augur of Dunlain?') !== ''
    && $wq('who is Madanach', "Where's Madanach?") !== '' && $wq('do you need help', 'What do you need help with?') !== '');
$wqP = static fn(string $u, string $tx): string => lrgDlgWordsQualm($u, ['text' => $tx, 'norm' => lrgPromptNorm($tx), 'scripted' => 0, 'pos' => 0], []);
chk('pt19h-safety G9 (words path, any line): "who wrote the first verse" / "We can do this. What\'s the first verse?", "Mirabelle is fine" / "Where\'s Mirabelle?", "what project" / "How is your project coming along?"; "go away" refuses "Go on."',
    $wqP('who wrote the first verse', "We can do this. What's the first verse?") !== '' && $wqP('Mirabelle is fine', "Where's Mirabelle?") !== ''
    && $wqP('what project', 'How is your project coming along?') !== '' && $sgR($sg('Go on.', [], 'go away')) === 'refused');
chk('pt19h-safety G9 must-resolve (words path, any line): "just tell me where the scroll is" / "So, where is the Scroll?", "so you want my help" / "Are you saying you want my help?", "I\'ll pay you for the amulet" / "What if I were to pay you for the amulet?", "whose contract was it" / "So who was it? Who had the contract?"',
    $wqP('just tell me where the scroll is', 'So, where is the Scroll?') === '' && $wqP('so you want my help', 'Are you saying you want my help?') === ''
    && $wqP("I'll pay you for the amulet", 'What if I were to pay you for the amulet?') === '' && $wqP('whose contract was it', 'So who was it? Who had the contract?') === '');
chk('pt19h-safety G9 must-resolve (words path): "the situation" / "What\'s the situation?", "tell me about the plan" / "What\'s the plan?", "open the door" / "Open the door.", "Consider Fort Hraagstad already taken" / "Consider that fort already yours."',
    $wq('the situation', "What's the situation?") === '' && $wq('tell me about the plan', "What's the plan?") === '' && $wq('open the door', 'Open the door.') === ''
    && !lrgDlgSubstitutes('Consider Fort Hraagstad already taken', ['text' => 'Consider that fort already yours.', 'norm' => lrgPromptNorm('Consider that fort already yours.')]));
// ---- G8: LEAVE takes only a REAL back-out line
$dec = static function (string $tx, array $o = []): array {
    v1Reset([v1Row($tx, $o), v1Row('Tell me more.')], 1);
    v1Topics('Leave PT', [$tx, 'Tell me more.']);
    return (array) (((array) (lrgDlgGet('Leave PT')['session']['entries'] ?? []))[0] ?? []);
};
foreach ([["Nothing I couldn't handle.", $cs], ['I have nothing to hide. The Blades helped me find out about it.', ['scripted' => 1]],
    ["Forget it. I'll just open it myself.", $cs], ['The Thalmor know nothing about the dragons.', []], ["I'm not sure I understand the terms.", []],
    ['I did what had to be done. Nothing more.', []], ['Not yet.', $cs], ["I'll do nothing of the sort.", $cs]] as [$tx, $o]) {
    $d = $dec($tx, $o);
    chk('pt19h-safety G8: "' . $tx . '" is no way out for LEAVE (class ' . ($d['class'] ?? '?') . ')', !lrgDlgRealBackOut($d), json_encode(array_intersect_key($d, ['class' => 1, 'commit' => 1, 'scripted' => 1])));
}
foreach (['Never mind.', 'I see. Never mind then.', "No, nothing. I'll just be moving on.", "On second thought... I need more time to think about this.",
    "I'm not sure. I need to think about it.", 'Forget it, I\'ll come back.', 'Maybe later.'] as $tx) {
    chk('pt19h-safety G8 must-resolve: "' . $tx . '" is a real back-out LEAVE may click', lrgDlgRealBackOut($dec($tx, ['goodbye' => 1])));
}
$lv = static function (array $rows, array $texts, string $npc): array {
    v1Reset($rows, 1);
    v1Topics($npc, $texts);
    v1Say($npc, 'never mind');
    return v1Llm($npc, 'LEAVE', 'As you wish.');
};
$l1 = $lv([v1Row("Nothing I couldn't handle.", $cs), v1Row("What's the plan?", ['scripted' => 1, 'goodbye' => 1])], ["Nothing I couldn't handle.", "What's the plan?"], 'Tullius PT');
$k1 = $l1['out'] ? v1Kv((string) $l1['out'][0]) : [];
chk('pt19h-safety G8: "never mind" + LEAVE on Tullius\'s list never clicks "Nothing I couldn\'t handle." - the engine cancel (pos=-1), her line plays',
    ($k1['do'] ?? '') === 'leave' && (string) ($k1['pos'] ?? '') === '-1' && !$l1['will'], json_encode($l1));
$l2 = $lv([v1Row("Forget it. I'll just open it myself.", $cs), v1Row('What code?', ['walkaway' => 1, 'twat' => 'TG02WalkAwayTopic'])], ["Forget it. I'll just open it myself.", 'What code?'], 'Aringoth PT');
$k2 = $l2['out'] ? v1Kv((string) $l2['out'][0]) : [];
chk('pt19h-safety G8: ... and beside a walk-away line it is the leave guard (do=show kind=back), never the fight', ($k2['do'] ?? '') === 'show' && ($k2['kind'] ?? '') === 'back', json_encode($l2));
$l3 = $lv([v1Row('Tell me about the war.'), v1Row('Never mind.', ['goodbye' => 1])], ['Tell me about the war.', 'Never mind.'], 'Guard PT');
$k3 = $l3['out'] ? v1Kv((string) $l3['out'][0]) : [];
chk('pt19h-safety G8 must-resolve: a real "Never mind." is still clicked by LEAVE (pos 1), WillEmit TRUE', ($k3['do'] ?? '') === 'leave' && (string) ($k3['pos'] ?? '') === '1' && $l3['will'], json_encode($l3));
// ---- G10: negation parity
chk('pt19h-safety G10: a double negative accepts ("I don\'t want to refuse your gift" clashes with "... I refuse your gift."); "that makes no sense" clashes with "Makes sense."; "no, I don\'t want that" clashes with "I want that."',
    lrgDlgNegationClash("I don't want to refuse your gift", ['text' => "I don't want to become a vampire. I refuse your gift."])
    && lrgDlgNegationClash('that makes no sense', ['text' => 'Makes sense.']) && lrgDlgNegationClash("no, I don't want that", ['text' => 'I want that.'])
    && !lrgDlgNegationClash('there is no point earning all that gold', ['text' => 'All that gold is yours.']));
chk('pt19h-safety G10: a tag question asks ("I could just kill you now, couldn\'t I", "haven\'t you had enough of this"); "the key wasn\'t there" states',
    lrgDlgIsQuestion("I could just kill you now, couldn't I") && lrgDlgIsQuestion("haven't you had enough of this") && !lrgDlgIsQuestion("the key wasn't there"));
// ---- hedges: his doing hedged, never a proposal
chk('pt19h-safety hedges: "maybe I\'ll do it", "I guess so", "perhaps later" hedge; "maybe some septims will change your mind" proposes (and is no statement against the bribe\'s rhetorical question)',
    lrgDlgHedges("maybe I'll do it") && lrgDlgHedges('I guess so') && lrgDlgHedges('perhaps later') && !lrgDlgHedges('maybe some septims will change your mind')
    && !lrgDlgDeclares('maybe some septims will change your mind', ['text' => 'Will this change your mind? (50 gold)', 'norm' => lrgPromptNorm('Will this change your mind? (50 gold)'), 'kind' => 'bribe']));
// ---- the name-word proxy: a generic display name is no name
chk('pt19h-safety name word: "Dark Brotherhood Assassin", "Courier", "College Apprentice", "Innkeeper", "Thalmor Justiciar", "Vigilant of Stendarr", "Dawnguard Veteran" carry no name; "Balgruuf the Greater" balgruuf, "General Tullius" tullius, "Whiterun Guard" guard (by design)',
    lrgDlgNameWord('Dark Brotherhood Assassin') === '' && lrgDlgNameWord('Courier') === '' && lrgDlgNameWord('College Apprentice') === ''
    && lrgDlgNameWord('Innkeeper') === '' && lrgDlgNameWord('Thalmor Justiciar') === '' && lrgDlgNameWord('Vigilant of Stendarr') === ''
    && lrgDlgNameWord('Dawnguard Veteran') === '' && lrgDlgNameWord('Thalmor gate guard') === 'guard' && lrgDlgNameWord('Thalmor embassy guard') === 'guard'
    && lrgDlgNameWord('Balgruuf the Greater') === 'balgruuf' && lrgDlgNameWord('General Tullius') === 'tullius'
    && lrgDlgNameWord('Whiterun Guard') === 'guard',
    json_encode(array_map('lrgDlgNameWord', ['Dark Brotherhood Assassin', 'Courier', 'College Apprentice', 'Innkeeper', 'Thalmor Justiciar', 'Vigilant of Stendarr', 'Dawnguard Veteran'])));
// ---- [pt19h-safety review] the hedge, the deferral and the tag with an assent word or a phrase the templates did not carry
// (MQ305's "I'm ready to return to Tamriel." - leaving Sovngarde - clicked as explicit on each of these)
chk('pt19h-safety review: a hedge after an assent word, "probably yes" and a trailing "or not" / "... not" hedge; the config\'s "yeah, I guess so", "sure, why not" and "okay i guess you\'re the guy ..." do not',
    lrgDlgHedges("yeah maybe I'm ready to return to Tamriel") && lrgDlgHedges("sure, I guess I'm here to join the Dawnguard") && lrgDlgHedges('yes, probably')
    && lrgDlgHedges('probably yes') && lrgDlgHedges("I'm ready to return to Tamriel... or not") && lrgDlgHedges("I'm sure... not")
    && !lrgDlgHedges('yeah, I guess so') && !lrgDlgHedges('sure, why not') && !lrgDlgHedges("okay i guess you're the guy i talked to to join the legion")
    && !lrgDlgHedges('maybe some septims will change your mind') && !lrgDlgHedges('of course not') && !lrgDlgHedges('so can i join the legion or not'));
$tam = "I'm ready to return to Tamriel.";
chk('pt19h-safety review: "in a bit", "once I\'ve ...", "after I ..." put the line off (unless it says so itself); a bare "better not" refuses, "better not keep him waiting" does not',
    lrgDlgDefers("I'm ready to return to Tamriel in a bit", ['text' => $tam]) && lrgDlgDefers("I'm ready to return to Tamriel once I've said goodbye", ['text' => $tam])
    && lrgDlgDefers("I'll come along after I finish here", ['text' => "I'll come along with you."]) && !lrgDlgDefers("I'll be back in a moment", ['text' => "I'll be back in a moment."])
    && !lrgDlgDefers($tam, ['text' => $tam]) && lrgDlgRefuses('better not') && !lrgDlgRefuses('better not keep him waiting'));
foreach (["yeah maybe I'm ready to return to Tamriel", "sure, I guess I'm ready to return to Tamriel", "I'm ready to return to Tamriel in a bit",
    "I'm ready to return to Tamriel once I've said goodbye", "I'm ready to return to Tamriel... or not", "yes, probably I'm ready to return to Tamriel",
    "I'm ready to return to Tamriel, right"] as $u) {
    chk('pt19h-safety review must-not-click (explicit): "' . $u . '"', !$ex($u, $tam, 'key', ['Not yet.']));
}
chk('pt19h-safety review must-resolve (explicit): "I\'m ready to return to Tamriel", "yes, I\'m ready to return to Tamriel"',
    $ex("I'm ready to return to Tamriel", $tam, 'key', ['Not yet.']) && $ex("yes, I'm ready to return to Tamriel", $tam, 'key', ['Not yet.']));
// ---- S4.4: a hedge or a deferral releases no parked commit; the config's whole assent phrases still do
$twR = [v1Row("I'll come along with you.", ['scripted' => 1, 'goodbye' => 1]), v1Row('Not right now.')];
$parkR = static function (string $say) use ($twR): array {
    v1Reset($twR, 1);
    v1Topics('Irileth PT', ["I'll come along with you.", 'Not right now.']);
    v1Say('Irileth PT', 'the watchtower');
    v1Llm('Irileth PT', 'T1', 'You would ride with me to the watchtower, then?');
    $GLOBALS['LRG_TEST_NOW'] += 3;
    v1Say('Irileth PT', $say);
    $r = v1Llm('Irileth PT', 'T1', 'Good.');
    return [v1Do($r['out']), !empty(lrgDlgGet('Irileth PT')['parked'])];
};
foreach (['hmm maybe', 'maybe I will', 'I might', 'yes, maybe', 'I guess', 'sure, in a bit', 'probably yes'] as $u) {
    [$doR, $pkR] = $parkR($u);
    chk('pt19h-safety review S4.4: "' . $u . '" releases no parked commit (still parked, she asks again)', $doR === '' && $pkR, $doR);
}
[$doR, $pkR] = $parkR('better not');
chk('pt19h-safety review S4.4: a bare "better not" un-parks, never a release', $doR === '' && !$pkR, $doR);
foreach (['yes', 'I guess so', 'yeah I guess so', 'I think so'] as $u) {
    [$doR] = $parkR($u);
    chk('pt19h-safety review S4.4 must-resolve: "' . $u . '" on the park releases it', $doR === 'pick', $doR);
}
foreach (['yeah, later', 'yes, maybe', "I'm sure... not"] as $u) {
    v1Reset([v1Row("I'm sure.", ['scripted' => 1, 'goodbye' => 1]), v1Row('Never mind.')], 1);
    v1Topics('Serana PT', ["I'm sure.", 'Never mind.']);
    v1Say('Serana PT', $u);
    $rc = v1Llm('Serana PT', 'T1', 'Are you certain?');
    chk('pt19h-safety review (LLM path): "' . $u . '" + her key releases nothing on the engine\'s own confirmation layer', v1Do($rc['out']) === '' && !$rc['will'], json_encode($rc));
}
chk('pt19h-safety review name word: "Moth gro-Bagol" is moth, "Moth Priest" no name', lrgDlgNameWord('Moth gro-Bagol') === 'moth' && lrgDlgNameWord('Moth Priest') === '');
chk('pt19h-safety review: "has been done" / "have already seen it" state (the STT\'s clipped "It has been done."); "has Malborn got my gear" still asks; "dragon or not" is no take-back',
    !lrgDlgIsQuestion('has been done') && !lrgDlgIsQuestion('have already seen it') && lrgDlgIsQuestion('has Malborn got my gear')
    && !lrgDlgHedges('he helped me, dragon or not') && !lrgDlgHedges('Mercer killed Gallus, not'));
unset($GLOBALS['LRG_DLG_TURN'], $GLOBALS['LRG_TEST_NPCSTATE'], $GLOBALS['LRG_NPCSTATE_MEMO']);
$GLOBALS['LRG_DLG_STATE'] = [];

function fx_cut(string $s): string { return substr($s, 0, 120); }
function lrgDlgEntryFix(int $pos, string $text): array
{
    return ['pos' => $pos, 'i' => 100 + $pos, 'new' => 0, 'col' => 0, 'text' => $text, 'tail' => 0];
}

// ##################################################################################################
// [pt19h-grading] THE GRADING FIXER (research/pt19h-grading.md): G3 converging Invisible-Continue siblings, G12 the layer tier's
// own row, G15 one service word, G16 hand-over words, G19 the shipped overrides and the catch-all pattern, G20 Serana's
// dismissal, the Brotherhood misgrades (Astrid 021451 back, the Destroy QE decline 000E1C meta), the entry-side back-out, the
// LETHAL show on the fast path. The examples are the measurer's (research/pt19h-measure.md); a must-not-click row is asserted
// as "no do=pick" on the fast path. Everything runs through the index seam and the real entry points.
// ##################################################################################################
head('v60. [pt19h-grading] grading: converging siblings, the layer tier, service words, hand-overs, the overrides, Serana, the Brotherhood');
$GLOBALS['LRG_TEST_NOW'] += 1000;
$grN = 0;
$grDecor = static function (array $recs, array $kv = []): array {
    $ents = [];
    foreach (array_values($recs) as $i => $r) { $ents[] = ['pos' => $i, 'i' => 100 + $i, 'new' => 0, 'col' => 0, 'text' => (string) $r['txt']]; }
    return lrgDlgDecorateEntries($ents, array_values($recs), $kv + ['pg' => 500], ['pg' => 500]);
};
$grCls = static fn(array $d): string => json_encode(array_map(static fn($x) => [$x['class'], (int) !empty($x['commit']), (int) ($x['conv'] ?? 0)], $d));
// his sentence, then the list with want=1 (the fast path): the echo holds the emit, if any
$grFast = static function (string $npc, array $rows, array $texts, string $say, array $kv = []) use (&$grN): array {
    v1Reset($rows, 1);
    $GLOBALS['LRG_TEST_NOW'] += 40;
    v1Say($npc, $say);
    $GLOBALS['LRG_TEST_NOW'] += 2;
    $grN++;
    return v1Topics($npc, $texts, $kv + ['want' => 1, 'cid' => 'gr' . $grN, 'gen' => $grN]);
};
$grPicked = static fn(array $w): bool => str_contains((string) $w['echo'], ';do=pick;');
$grPickPos = static fn(array $w, int $pos): bool => str_contains((string) $w['echo'], ';do=pick;') && str_contains((string) $w['echo'], ';pos=' . $pos . ';');

// ---- G3: siblings that continue into the same topic are ONE choice (COVERAGE G3; the S4.1 hub rule's other half)
$grKar = [v1Row("Then I'm in your debt.", ['tk' => 'gr:k1', 'topic' => 'TG05KarliahIntroBranchTopic01y', 'quest' => 'TG05', 'invis' => 1, 'links' => ['skyrim.esm:0B8378']]),
    v1Row('Why should I believe you?', ['tk' => 'gr:k2', 'topic' => 'TG05KarliahIntroBranchTopic01z', 'quest' => 'TG05', 'invis' => 1, 'links' => ['skyrim.esm:0B8378']]),
    v1Row('You should have shot Mercer instead.', ['tk' => 'gr:k3', 'topic' => 'TG05KarliahIntroBranchTopic01az', 'quest' => 'TG05', 'invis' => 1, 'links' => ['skyrim.esm:0B8378']])];
// [r2 / review P1] the converge rule is DORMANT by default (grading.converge_plain false): the gate trusts the model's key on
// a plain line with no test of his words, so converging siblings stay commits until the gate's key-mode rail lands
$grConv = static function (bool $on): void {
    if ($on) { $GLOBALS['LRG_DLG_TEST_CFG']['grading.converge_plain'] = true; } else { unset($GLOBALS['LRG_DLG_TEST_CFG']['grading.converge_plain']); }
};
$grConv(false);
$d = $grDecor($grKar);
chk('pt19h-grading r2 G3 (shipped default, the rule dormant): Karliah\'s three converging answers stay commits (conv flagged) until the gate\'s key-mode rail lands (review P1)',
    !lrgDlgConvergeOn() && array_column($d, 'class') === ['commit', 'commit', 'commit'] && array_column($d, 'conv') === [1, 1, 1], $grCls($d));
$grConv(true);
$d = $grDecor($grKar);
chk('pt19h-grading G3 (grading.converge_plain on): Karliah\'s three answers (skyrim.esm:0B8382 / 0B838F / 0B838E) all continue into 0B8378 - one choice: the two statements are plain (was: three commits, "I owe you" cost her question); the QUESTION among them stays a commit (a statement is no answer to it)',
    array_column($d, 'class') === ['plain', 'commit', 'plain'] && array_column($d, 'conv') === [1, 1, 1], $grCls($d));
$w = $grFast('Karliah G', $grKar, array_column($grKar, 'txt'), 'I believe you', ['q' => 'TG05']);
chk('pt19h-grading G3 must-not-click: the near-miss "I believe you" never clicks "Why should I believe you?" (the measurer\'s never_red row)', !$grPicked($w), $w['echo']);
$grMir = [v1Row("I don't understand. What coincidence?", ['tk' => 'gr:mi1', 'topic' => 'MG05Stage50MirabelleFollowUp2', 'quest' => 'MG05', 'invis' => 1, 'links' => ['skyrim.esm:09BB89']]),
    v1Row('What are you talking about?', ['tk' => 'gr:mi2', 'topic' => 'MG05Stage50MirabelleFollowUp1', 'quest' => 'MG05', 'invis' => 1, 'links' => ['skyrim.esm:09BB89']])];
$w = $grFast('Mirabelle G', $grMir, array_column($grMir, 'txt'), "it's just a coincidence", ['q' => 'MG05']);
chk('pt19h-grading G3 must-not-click: the near-miss "it\'s just a coincidence" never clicks Mirabelle\'s "I don\'t understand. What coincidence?" (09BB8C)', !$grPicked($w), $w['echo']);
chk('pt19h-grading G3: ... they stay graded scripted (Invisible Continue, U2: never an auto-advance, a shared word needed - S4.8)',
    array_column($d, 'scripted') === [1, 1, 1], $grCls($d));
$d = $grDecor([v1Row("Aela and I work to avenge Skjor's death.", ['tk' => 'gr:c1', 'topic' => 'C04KodlakPlayerSpillTheBeans', 'invis' => 1, 'links' => ['skyrim.esm:0582CE']]),
    v1Row('I work for the honor of the Companions.', ['tk' => 'gr:c2', 'topic' => 'C04KodlakPlayerTriesToHideIt', 'invis' => 1, 'links' => ['skyrim.esm:0582D0']])]);
chk('pt19h-grading G3 guard: Kodlak\'s two answers continue into DIFFERENT topics (0582CE / 0582D0) - a real fork, both stay commits',
    array_column($d, 'class') === ['commit', 'commit'] && array_column($d, 'conv') === [0, 0], $grCls($d));
$d = $grDecor([v1Row('A dragon is attacking Kynesgrove?', ['tk' => 'gr:i1', 'topic' => 'MQ106KynesgroveDragonA1', 'invis' => 1, 'links' => ['skyrim.esm:0C81D5']]),
    v1Row("What's wrong?", ['tk' => 'gr:i2', 'topic' => 'MQ106KynesgroveDragonB1', 'scripted' => 1, 'goodbye' => 1]),
    v1Row("Where's this dragon?", ['tk' => 'gr:i3', 'topic' => 'MQ106KynesgroveDragonA2', 'invis' => 1, 'links' => ['skyrim.esm:0C81D5']])]);
chk('pt19h-grading G3 guard: Iddra\'s layer - the two questions converge but "What\'s wrong?" runs its own fragment and ends the talk: still a fork, the questions stay commits (never less safe)',
    $d[0]['class'] === 'commit' && $d[2]['class'] === 'commit' && $d[1]['commit'], $grCls($d));
$grSav = [v1Row('We found some sort of... orb. Tolfdir wanted you to see it.', ['tk' => 'gr:s2', 'topic' => 'MG02SavosResponse2', 'quest' => 'MG02', 'journal' => 1, 'invis' => 1, 'links' => ['skyrim.esm:0263D3']]),
    v1Row("We've found something in Saarthal, and Tolfdir thinks it's important.", ['tk' => 'gr:s1', 'topic' => 'MG02SavosResponse1', 'quest' => 'MG02', 'journal' => 1, 'invis' => 1, 'links' => ['skyrim.esm:0263D3']])];
$grSavT = array_column($grSav, 'txt');
$w = $grFast('Savos Aren G', $grSav, $grSavT, 'we found some kind of orb, Tolfdir wants you to see it', ['q' => 'MG02']);
chk('pt19h-grading G3: "we found some kind of orb, Tolfdir wants you to see it" on Savos (skyrim.esm:0263D9) is clicked on the fast path (measurer: "matched a commit, she asks")',
    $grPickPos($w, 0), $w['echo']);
$w = $grFast('Savos Aren G', $grSav, $grSavT, 'not now, we found some sort of orb, Tolfdir wanted you to see it, later', ['q' => 'MG02']);
chk('pt19h-grading G3 must-not-click: the deferral "not now, <the line> later" on the now-plain converging line clicks nothing', !$grPicked($w), $w['echo']);
$w = $grFast('Savos Aren G', $grSav, $grSavT, 'what is the orb', ['q' => 'MG02']);
chk('pt19h-grading G3 must-not-click: the near-miss question "what is the orb" clicks nothing', !$grPicked($w), $w['echo']);
// the shipped default (dormant): his refusal, deferral, hedge or question + her key never clicks a converging line (review P1's
// traces, Savos 0263D9 / 0263DA: each clicked with WillEmit TRUE while the siblings were plain)
$grConv(false);
foreach ([["no, I'm not telling you anything about the orb", 'T1'], ['what orb? I never found an orb', 'T1'], ["I'd rather not say what we found", 'T2'],
    ['not yet, I need more time', 'T2'], ["I don't think Tolfdir found anything important", 'T2']] as [$grSay, $grKey]) {
    v1Reset($grSav, 1);
    v1Topics('Savos Aren K', $grSavT, ['q' => 'MG02']);
    v1Say('Savos Aren K', $grSay);
    $r = v1Llm('Savos Aren K', $grKey, 'Hm. Go on.');
    chk('pt19h-grading r2 G3 must-not-click (her key, shipped default): "' . $grSay . '" + ' . $grKey . ' clicks no converging Savos line (WillEmit FALSE)',
        v1Do($r['out']) !== 'pick' && !$r['will'], json_encode($r['out']));
}
$w = $grFast('Savos Aren G', $grSav, $grSavT, 'we found some kind of orb, Tolfdir wants you to see it', ['q' => 'MG02']);
chk('pt19h-grading r2 G3 (shipped default): the paraphrase is not clicked on the fast path - the converging line is a commit, she asks (G3 waits for the rail)',
    !$grPicked($w), $w['echo']);

// ---- G12: the layer tier's row is HER quest's row when the parent's links name none; the merge is scoped to it
$grLayer = static function (array $rows, array $norms, string $parent = ''): void {
    $GLOBALS['LRG_TEST_INDEX'] = ['rows' => $rows, 'layers' => [['fingerprint' => 'gr:fp' . md5(implode('|', $norms)), 'parent_info' => $parent, 'norms' => $norms]]];
};
$grDel = [v1Row("I'll do it.", ['tk' => 'gr:lis1', 'ik' => 'gr:046FAD', 'topic' => 'DialogueMarkarthLisbetAccept', 'quest' => 'DialogueMarkarth', 'scripted' => 1, 'goodbye' => 1, 'crit' => 1]),
    v1Row("I'll do it.", ['tk' => 'gr:tgr1', 'ik' => 'gr:06182B', 'topic' => 'TGRShellFOBranchTopicAccept', 'quest' => 'TGRShell', 'scripted' => 1, 'goodbye' => 1]),
    v1Row('Never mind, maybe later.', ['tk' => 'gr:lis2', 'ik' => 'gr:lisno', 'topic' => 'DialogueMarkarthLisbetDecline', 'quest' => 'DialogueMarkarth', 'goodbye' => 1]),
    v1Row('Never mind, maybe later.', ['tk' => 'gr:tgr2', 'ik' => 'gr:0618CA', 'topic' => 'TGRShellFOBranchTopicDecline', 'quest' => 'TGRShell', 'goodbye' => 1])];
$grLayer($grDel, [lrgPromptNorm("I'll do it."), lrgPromptNorm('Never mind, maybe later.')]);
$rec = lrgPromptLookup(["I'll do it.", 'Never mind, maybe later.'], ['quests' => ['TGRShell']]);
chk('pt19h-grading G12: Delvin\'s "I\'ll do it." (skyrim.esm:06182B) on a layer Lisbet shares resolves to HIS quest\'s row (TGRShell) with crit 0 - no crit 1 lent by DialogueMarkarth',
    (string) ($rec[0]['quest'] ?? '') === 'TGRShell' && (int) ($rec[0]['crit'] ?? -1) === 0 && (string) ($rec[0]['matched'] ?? '') === 'layer'
    && (string) ($rec[1]['quest'] ?? '') === 'TGRShell', json_encode($rec));
$rec = lrgPromptLookup(["I'll do it.", 'Never mind, maybe later.'], []);
chk('pt19h-grading G12 guard: with no quest named the fail-safe merge stays load-order-wide (crit 1 over-claimed, never under-claimed)',
    (int) ($rec[0]['crit'] ?? 0) === 1, json_encode($rec[0] ?? null));
$grTul = [v1Row('Yes, sir.', ['tk' => 'gr:u1', 'topic' => 'CW00BUlfricGreetYessir', 'quest' => 'CW00B', 'scripted' => 1, 'goodbye' => 1]),
    v1Row('Yes, sir.', ['tk' => 'gr:t1', 'topic' => 'CW00ATulliusGreetYes', 'quest' => 'CW00A', 'goodbye' => 1]),
    v1Row("That's not why I'm here.", ['tk' => 'gr:t2', 'topic' => 'CW00ATulliusGreetNotWhyImHere', 'quest' => 'CW00A'])];
$grLayer($grTul, [lrgPromptNorm('Yes, sir.'), lrgPromptNorm("That's not why I'm here.")]);
$texts = ["That's not why I'm here.", 'Yes, sir.'];
$rec = lrgPromptLookup($texts, ['quests' => ['CW00A']]);
$ents = [];
foreach ($texts as $i => $t) { $ents[] = ['pos' => $i, 'i' => 100 + $i, 'new' => 0, 'col' => 0, 'text' => $t]; }
$d = lrgDlgDecorateEntries($ents, $rec, ['pg' => 500], ['pg' => 500]);
chk('pt19h-grading G12: Tullius\'s "Yes, sir." (skyrim.esm:0C3483) is graded by CW00A\'s row, not Ulfric\'s CW00B row: plain, no commit (measurer: resolved to 0C347D, commit)',
    (string) ($rec[1]['topic'] ?? '') === 'CW00ATulliusGreetYes' && $d[1]['class'] === 'plain' && empty($d[1]['commit']), json_encode([$rec[1], $d[1]['class']]));
$grLayer(array_merge($grTul, [v1Row('Yes, sir.', ['tk' => 'gr:crime', 'topic' => 'DGCrimeYesSirTest', 'quest' => 'DialogueCrimeGuards', 'crit' => 2])]),
    [lrgPromptNorm('Yes, sir.'), lrgPromptNorm("That's not why I'm here.")]);
$rec = lrgPromptLookup($texts, ['quests' => ['CW00A']]);
chk('pt19h-grading G12 guard: a LETHAL row that shares the norm is still merged into her quest\'s row (the arrest guarantee, crit 2 - never under-claimed)',
    (int) ($rec[1]['crit'] ?? 0) === 2, json_encode($rec[1] ?? null));
$grDph = [v1Row('I know that mound - high on the hill east of Kynesgrove', ['tk' => 'gr:dA1', 'topic' => 'MQ106DelphineIntroEndA1', 'quest' => 'MQ106', 'scripted' => 1]),
    v1Row('I know that mound - high on the hill east of Kynesgrove', ['tk' => 'gr:dB1', 'topic' => 'MQ106DelphineLaterA1', 'quest' => 'MQ106', 'scripted' => 1, 'goodbye' => 1]),
    v1Row("Let's go kill a dragon.", ['tk' => 'gr:dA2', 'topic' => 'MQ106DelphineIntroEndA2', 'quest' => 'MQ106', 'goodbye' => 1]),
    v1Row('(parent)', ['ik' => 'gr:parentD', 'links' => ['gr:dA1', 'gr:dA2']])];
$grLayer($grDph, [lrgPromptNorm('I know that mound - high on the hill east of Kynesgrove'), lrgPromptNorm("Let's go kill a dragon.")], 'gr:parentD');
$rec = lrgPromptLookup(['I know that mound - high on the hill east of Kynesgrove', "Let's go kill a dragon."], ['quests' => ['MQ106']]);
chk('pt19h-grading G12 guard: the PARENT\'s links still choose and scope first (the pt19c ruling) - Delphine\'s mound line keeps its own topic and lends no goodbye from another MQ106 topic',
    (string) ($rec[0]['topic'] ?? '') === 'MQ106DelphineIntroEndA1' && (int) ((($rec[0]['flags'] ?? [])['goodbye']) ?? 1) === 0, json_encode($rec[0] ?? null));

// ---- G19: the catch-all pattern row and the token tags
$GLOBALS['LRG_TEST_INDEX'] = ['rows' => [array_replace(v1Row('<Alias=Player>.', ['tk' => 'gr:cat', 'ik' => 'hearthfires.esm:003D89', 'topic' => 'RelationshipAdoptableOrphanage_Q2']), ['pattern' => '*'])], 'layers' => []];
$rec = lrgPromptLookup(['A line no index row carries.'], []);
chk('pt19h-grading G19: a pattern with no literal word ("*", hearthfires.esm:003D89) is never a key - an unindexed line stays unindexed (UI fallback), not "indexed" as the catch-all row',
    $rec[0] === null, json_encode($rec[0]));
$GLOBALS['LRG_TEST_INDEX'] = ['rows' => [
    array_replace(v1Row('Recognize this? (Show <Alias.PronounObj=Steward> <Alias=Evidence>)', ['tk' => 'gr:bm', 'ik' => 'skyrim.esm:0E0B93', 'topic' => 'CWMission07BlackmailExplain', 'quest' => 'CWMission07', 'scripted' => 1, 'goodbye' => 1, 'toplevel' => 1]), ['pattern' => 'recognize this show * *']),
    array_replace(v1Row("I'll get proof... and <Alias=Steward>'s cooperation.", ['tk' => 'gr:ac', 'ik' => 'skyrim.esm:0504C4', 'topic' => 'CWMission07AcceptYes', 'quest' => 'CWMission07', 'scripted' => 1, 'goodbye' => 1]), ['pattern' => "i'll get proof and * 's cooperation"])], 'layers' => []];
$rec = lrgPromptLookup(['Recognize this? (Show him Inscribed Amulet of Talos)', "I'll get proof... and Captain Aldis's cooperation."], ['quests' => ['CWMission07']]);
chk('pt19h-grading G19: the Compelling Tribute blackmail "Recognize this? (Show him Inscribed Amulet of Talos)" resolves to its own row 0E0B93 (the pattern keeps "show * *": the tag-kept key)',
    (string) ($rec[0]['info_key'] ?? '') === 'skyrim.esm:0E0B93' && (string) ($rec[0]['matched'] ?? '') === 'pattern', json_encode($rec[0]));
chk('pt19h-grading G19: ... and the accept "I\'ll get proof... and Captain Aldis\'s cooperation." resolves to 0504C4 (a possessive written against its token)',
    (string) ($rec[1]['info_key'] ?? '') === 'skyrim.esm:0504C4', json_encode($rec[1]));
$ents = [['pos' => 0, 'i' => 100, 'new' => 0, 'col' => 0, 'text' => 'Recognize this? (Show him Inscribed Amulet of Talos)'],
    ['pos' => 1, 'i' => 101, 'new' => 0, 'col' => 0, 'text' => "I'll get proof... and Captain Aldis's cooperation."]];
$d = lrgDlgDecorateEntries($ents, $rec, ['pg' => 500], ['pg' => 500]);
chk('pt19h-grading G19: both are commits (scripted, goodbye; and the shipped overrides by text) - they were plain by the catch-all row',
    $d[0]['class'] === 'commit' && $d[1]['class'] === 'commit', $grCls($d));

// ---- G19: the shipped overrides file carries every trap the coverage found
$grOv = json_decode((string) @file_get_contents(LRG_DIR . '/config/lrg_dialogue_overrides.default.json'), true);
$grRule = static function (array $e) use ($grOv): array {
    foreach ((array) ($grOv['entries'] ?? []) as $rule) {
        $ok = true;
        foreach ((array) ($rule['match'] ?? []) as $k => $pat) { if (!lrgGlob((string) $pat, (string) ($e[$k] ?? ''))) { $ok = false; break; } }
        if ($ok) { return $rule; }
    }
    return [];
};
$grNeed = ['MQ303OdahviingFreeA1' => 'commit', 'MQ103AUlfricBookB1' => 'never_auto', 'MQ103BTulliusBookB1' => 'never_auto', 'MGRAppJzargo01OutOfScrollsTopic' => 'commit',
    'DA08_SecretEndingTopic' => 'never_auto', 'DA08_Balgruuf25Topic' => 'commit', 'TGRShellBEQuitBranchTopic' => 'commit', 'TGRShellSLQuitBranchTopic' => 'commit',
    'CR06TakeJob' => 'commit', 'CR07TakeTheJob' => 'commit', 'CR13WellLetsDoIt' => 'commit', 'MGRejoinQuestPayGold' => 'never_auto',
    'TGCrownBuyGemsPurchase' => 'never_auto', 'DLC1VQElderUragBranchTopic02d' => 'never_auto', 'TGBanVexDoneBranchTopic01' => 'never_auto',
    'DBNazirEvictionPlayerResponse1' => 'never_auto', 'CWMission07BlackmailExplain' => 'commit', 'CWMission07AcceptYes' => 'commit',
    'CWMission07AboutAmbushTopic' => 'commit', 'CYA_Onmund_Choice_Topic' => 'never_auto', 'CYA_Faralda_End_Topic' => 'never_auto',
    'CYA_Jzargo_Choice_Topic' => 'never_auto', 'CYA_Tolfdir_End_Topic' => 'never_auto', 'TG02AringothWalkawayTopic' => 'never_auto',
    'DA08_NOPE' => 'never_auto', 'CWMission04AcceptYesImperial' => 'never_auto', 'CWMission04AcceptYesSons' => 'never_auto',
    'TGLeadershipBrynjolfAfterBranchTopic' => 'never_auto', 'MS08SaadiaST125Response1' => 'commit', 'DLC1NPCMentalModelDismissTopic' => 'fcommit'];
$grMiss = [];
foreach ($grNeed as $topic => $flag) { $r = $grRule(['topic' => $topic, 'text' => 'x']); if (empty($r[$flag])) { $grMiss[] = $topic . ':' . $flag; } }
chk('pt19h-grading G19: the shipped overrides file names every G19 / G14 / G8 / G20 trap of the coverage (Skuldafn 04DE3C, the side switch 05A6B1, J\'zargo 0F1B29, the secret ending 00082B, the jarl 000D64, the TGR quits, CR06 / CR07 / CR13, the rejoin fine 0C9A08, the prose prices, Compelling Tribute, the CYA hand-overs, the fights worded as back-outs, the mission accepts, Saadia\'s lie, Serana\'s dismissal)',
    $grMiss === [] && (int) ($grOv['version'] ?? 0) >= 2, implode(', ', $grMiss));
$r1 = $grRule(['topic' => 'DBDestroy_No', 'text' => "Sorry, I'm not interested. (Fail Quest)"]);
$r2 = $grRule(['topic' => 'COW_CentralQuest_Skip', 'text' => "Actually, I'm not interested. (Skip Quest)"]);
chk('pt19h-grading Brotherhood C15: a "(Fail Quest)" decline is class commit + never_auto (in character, by voice after her question); "(Skip Quest)" stays meta (out of character)',
    ($r1['class'] ?? '') === 'commit' && !empty($r1['never_auto']) && $r2 === [], json_encode([$r1, $r2]));
// the decorated grades, through the shipped file
$d = $grDecor([v1Row('Hold on. I\'m not quite ready.', ['tk' => 'gr:o2', 'topic' => 'MQ303OdahviingFreeA2', 'quest' => 'MQ303', 'goodbye' => 1]),
    v1Row("I'm ready. Take me to Skuldafn.", ['tk' => 'gr:o1', 'ik' => 'skyrim.esm:04DE3C', 'topic' => 'MQ303OdahviingFreeA1', 'quest' => 'MQ303', 'scripted' => 1])]);
chk('pt19h-grading G19: Odahviing\'s "I\'m ready. Take me to Skuldafn." (skyrim.esm:04DE3C) is a commit (was plain: the carriage phrase "take me to" hid it as a service line)',
    $d[1]['class'] === 'commit' && !empty($d[1]['commit']) && (string) $d[1]['info'] === 'skyrim.esm:04DE3C', $grCls($d));
$grOdh = [v1Row('Hold on. I\'m not quite ready.', ['tk' => 'gr:o2', 'topic' => 'MQ303OdahviingFreeA2', 'quest' => 'MQ303', 'goodbye' => 1]),
    v1Row("I'm ready. Take me to Skuldafn.", ['tk' => 'gr:o1', 'topic' => 'MQ303OdahviingFreeA1', 'quest' => 'MQ303', 'scripted' => 1])];
foreach (['tell me about Skuldafn', 'take me to Skuldafn later', 'why Skuldafn?'] as $grSay) {
    $w = $grFast('Odahviing G', $grOdh, array_column($grOdh, 'txt'), $grSay, ['q' => 'MQ303']);
    chk('pt19h-grading G19 must-not-click: "' . $grSay . '" on Odahviing clicks nothing on the fast path (the coverage re-check: it clicked, intent / kind)', !$grPicked($w), $w['echo']);
}
$grJz = [v1Row('I ran out of scrolls.', ['tk' => 'gr:j1', 'topic' => 'MGRAppJzargo01OutOfScrollsTopic', 'quest' => 'MGRAppJzargo01', 'scripted' => 1, 'toplevel' => 1, 'journal' => 1]),
    v1Row('What do you need?', ['tk' => 'gr:j2', 'toplevel' => 1])];
$w = $grFast("J'zargo G", $grJz, array_column($grJz, 'txt'), 'I ran out', ['layer' => 0, 'q' => 'MGRAppJzargo01']);
chk('pt19h-grading G19 must-not-click: "I ran out" never fails J\'zargo\'s test on the fast path (0F1B29 is a commit now)', !$grPicked($w), $w['echo']);
$grQuit = [v1Row('I want to quit that burglary job.', ['tk' => 'gr:q1', 'topic' => 'TGRShellBEQuitBranchTopic', 'quest' => 'TGRShell', 'scripted' => 1, 'toplevel' => 1]),
    v1Row('Any work for me?', ['tk' => 'gr:q2', 'toplevel' => 1])];
$w = $grFast('Vex G', $grQuit, array_column($grQuit, 'txt'), 'I quit', ['layer' => 0, 'q' => 'TGRShell']);
chk('pt19h-grading G19 must-not-click: "I quit" never abandons the burglary job on the fast path (0D785E is a commit now)', !$grPicked($w), $w['echo']);
$grSide = [v1Row('I made a mistake. I want to be a Stormcloak. The crown belongs to you.', ['tk' => 'gr:x1', 'topic' => 'MQ103AUlfricBookB1', 'quest' => 'CW02A', 'scripted' => 1]),
    v1Row('What do you want me to do?', ['tk' => 'gr:x2', 'quest' => 'CW02A'])];
foreach (['I made a mistake', 'I made a mistake. I want to be a Stormcloak. The crown belongs to you.'] as $grSay) {
    $w = $grFast('Ulfric G', $grSide, array_column($grSide, 'txt'), $grSay, ['q' => 'CW02A']);
    chk('pt19h-grading G19: "' . substr($grSay, 0, 40) . '" never switches sides on the fast path - never_auto: even his own line asks first', !$grPicked($w), $w['echo']);
}
// [r2 / review BLOCKING, usefulness] the MIRROR side switch: Tullius's 05A6A6 was a commit only by a flag lent to "Never mind."; with
// Tullius's own row 05A6A9 it fell to plain, and the fast path and her key clicked it on a paraphrase
$grTs = [v1Row('I just want to join the Legion. Consider the crown a gift.', ['tk' => 'gr:ts1', 'ik' => 'skyrim.esm:05A6A6', 'topic' => 'MQ103BTulliusBookB1', 'quest' => 'CW02B', 'scripted' => 1, 'crit' => 1, 'walkaway' => 1]),
    v1Row('Never mind.', ['tk' => 'gr:ts2', 'ik' => 'skyrim.esm:05A6A9', 'topic' => 'MQ103BTulliusBookA2', 'quest' => 'CW02B', 'goodbye' => 1])];
$grTsT = array_column($grTs, 'txt');
$d = $grDecor($grTs);
chk('pt19h-grading r2 G19: Tullius\'s side switch (skyrim.esm:05A6A6) is a commit by its never_auto override, whatever its sibling "Never mind." resolves to',
    $d[0]['class'] === 'commit' && !empty($d[0]['commit']), $grCls($d));
foreach (['uh, consider it a gift', 'consider the clown a gift', 'i just want to join the lesion', 'never mind', "It's a gift.",
    'I just want to join the Legion, consider the crown a gift'] as $grSay) {
    $w = $grFast('Tullius G', $grTs, $grTsT, $grSay, ['q' => 'CW02B']);
    chk('pt19h-grading r2 G19 must-not-click (fast path): "' . $grSay . '" never switches sides to the Legion (never_auto: she asks first)', !$grPickPos($w, 0), $w['echo']);
}
foreach (['never mind', 'not now, i just want to join the Legion... consider the crown a gift, later', "It's a gift."] as $grSay) {
    v1Reset($grTs, 1);
    v1Topics('Tullius K', $grTsT, ['q' => 'CW02B']);
    v1Say('Tullius K', $grSay);
    $r = v1Llm('Tullius K', 'T1', 'A gift, you say?');
    chk('pt19h-grading r2 G19 must-not-click (her key): "' . $grSay . '" + T1 never clicks the side switch', v1Do($r['out']) !== 'pick' && !$r['will'], json_encode($r['out']));
}
$grAcc = [v1Row("Nothing I can't handle.", ['tk' => 'gr:m1', 'topic' => 'CWMission04AcceptYesImperial', 'quest' => 'CWMission04', 'scripted' => 1, 'goodbye' => 1]),
    v1Row("I'm not sure. I need to think about it.", ['tk' => 'gr:m2', 'topic' => 'CWMission04AcceptNo', 'quest' => 'CWMission04'])];
$d = $grDecor($grAcc);
chk('pt19h-grading G8 trap: the mission accept "Nothing I can\'t handle." (05C614) is a commit, never class back (LEAVE took it); the decline stays back',
    $d[0]['class'] === 'commit' && $d[1]['class'] === 'back', $grCls($d));
foreach (["I can't handle it", "no, nothing I can't handle", 'never mind'] as $grSay) {
    $w = $grFast('Rikke G', $grAcc, array_column($grAcc, 'txt'), $grSay, ['q' => 'CWMission04']);
    chk('pt19h-grading must-not-click: "' . $grSay . '" never accepts the mission on the fast path', !$grPickPos($w, 0), $w['echo']);
}

// ---- G15: one service word is no service on a quest line
$d = $grDecor([v1Row("There's a horse waiting at the stables. I'll make sure you're safe. (Lie)", ['tk' => 'gr:sa', 'topic' => 'MS08SaadiaST125Response1', 'quest' => 'MS08', 'scripted' => 1])]);
chk('pt19h-grading G15: Saadia\'s horse lie (skyrim.esm:057FA1) is no service ("horse", "stables") - a commit (the betrayal)', $d[0]['class'] === 'commit', $grCls($d));
$d = $grDecor([v1Row("So you're supposed to train me?", ['tk' => 'gr:vk', 'topic' => 'C00VilkasTrainPlayerTopic', 'quest' => 'C00', 'scripted' => 1, 'goodbye' => 1, 'toplevel' => 1]),
    v1Row('What is this place?', ['tk' => 'gr:vk2', 'toplevel' => 1])]);
chk('pt19h-grading G15: Vilkas\'s spar "So you\'re supposed to train me?" (0A3E99) is a commit (scripted, goodbye), not "[a service]"', $d[0]['class'] === 'commit', $grCls($d));
$d = $grDecor([v1Row('A second drink. Easy enough.', ['tk' => 'gr:sm1', 'topic' => 'DA14StartPostDrink2ContinueTopic', 'quest' => 'DA14Start', 'links' => ['skyrim.esm:0A954F']]),
    v1Row("I'm done now.", ['tk' => 'gr:sm2', 'topic' => 'DA14StartPostDrink2RefusalTopic', 'quest' => 'DA14Start', 'scripted' => 1, 'goodbye' => 1])]);
chk('pt19h-grading G15: Sam\'s "A second drink. Easy enough." (0A95B9) is plain (it leads on), not a service', $d[0]['class'] === 'plain', $grCls($d));
$d = $grDecor([v1Row('Here, drink this potion first. Then we can talk.', ['tk' => 'gr:da1', 'topic' => 'DA02AltQuestTopic11Alt', 'scripted' => 1, 'links' => ['x:1']]),
    v1Row("Hold still. I know a spell that can help you. We'll speak after.", ['tk' => 'gr:da2', 'topic' => 'DA02AltQuestTopic11Spell', 'scripted' => 1, 'links' => ['x:1']])]);
chk('pt19h-grading G15: DA02AltQuest\'s potion line (000880) is no service - graded by the sibling rule (commit)', $d[0]['class'] === 'commit', $grCls($d));
$d = $grDecor([v1Row('Will you buy it?', ['tk' => 'gr:dv', 'topic' => 'DB04aDelvinAmuletBuyBranchTopic', 'quest' => 'DB04a', 'scripted' => 1, 'goodbye' => 1])]);
chk('pt19h-grading G15: Delvin\'s "Will you buy it?" (05B490, Brotherhood C15) is a commit, not a service', $d[0]['class'] === 'commit', $grCls($d));
$d = $grDecor([v1Row("I'd like to rent a room.", ['tk' => 'gr:rr', 'topic' => 'RentRoomTopic', 'scripted' => 1, 'toplevel' => 1]),
    v1Row('What have you got for sale?', ['tk' => 'gr:os', 'topic' => 'OfferServicesTopic', 'scripted' => 1, 'toplevel' => 1]),
    v1Row('Can you train me in Conjuration?', ['tk' => 'gr:tr', 'topic' => 'DLC2TelMithrynTalvasTrainTopic', 'scripted' => 1, 'toplevel' => 1]),
    v1Row('Where can I get a drink around here?', ['tk' => 'gr:dr', 'topic' => 'ACFDialogueWhiterunDrinkTopic', 'toplevel' => 1])]);
chk('pt19h-grading G15 guard: the real services keep their class - the room, the wares, the trainer (their PHRASE), and an ambient leaf line keeps the word rule',
    array_column($d, 'class') === ['service', 'service', 'service', 'service'], $grCls($d));
$d = $grDecor([v1Row('Looking to trade.', ['tk' => 'gr:ws1', 'topic' => '0WindhelmDialogueGeneralDarkElfShopTopic', 'quest' => '0WindhelmDialogueGeneral', 'scripted' => 1, 'toplevel' => 1]),
    v1Row('Looking to move some goods. Discreetly.', ['tk' => 'gr:ws2', 'topic' => '0WindhelmDialogueGeneralSootFenceTopic', 'quest' => '0WindhelmDialogueGeneral', 'scripted' => 1, 'toplevel' => 1]),
    v1Row('I need you to trade something with me, Zeus.', ['tk' => 'gr:ws3', 'topic' => 'DialogueFollowerTradeTopic', 'quest' => 'DialogueFollower', 'scripted' => 1, 'toplevel' => 1, 'journal' => 1]),
    v1Row('Could you show me my room?', ['tk' => 'gr:ws4', 'topic' => 'TAIF_ShowRoomDialogueTopic', 'quest' => 'TAIF_ShowRoomDialogue', 'scripted' => 1, 'toplevel' => 1])]);
chk('pt19h-grading r2 G15: the shop, fence, trade and show-room openers keep class service by TOPIC (windhelmsse.esp 15E2AD / 211D46, zeus.esp DialogueFollowerTradeTopic, USMP 014CBA) - the review found ~480 rows losing it',
    array_column($d, 'class') === ['service', 'service', 'service', 'service'], $grCls($d));
$d = $grDecor([v1Row('Just looking to sell today.', ['tk' => 'gr:ws5', 'topic' => '0WindhelmDialogueAxeshopSell', 'quest' => '0WindhelmDialogueAxeshop', 'scripted' => 1]),
    v1Row("Take my Markarth horse. He's yours now.", ['tk' => 'gr:in1', 'topic' => 'InigoSteedTradeMarkar', 'quest' => 'InigoSteed', 'scripted' => 1])]);
chk('pt19h-grading r2 G15: "Just looking to sell today." (...AxeshopSell) keeps service; guard: a bare "Trade" in a topic is no service topic - Inigo\'s InigoSteedTrade* gives his horse away',
    $d[0]['class'] === 'service' && $d[1]['class'] !== 'service', $grCls($d));

// ---- G16: a hand-over tag's object is a match word; the recipient and a price are not
// [r2 / review BLOCKING] the object rides as the row's `hand`, NEVER in its match norm: in the norm the object alone passed the
// S4.3 explicit test and the intent pick ("I have Auriel's Bow, but you can't have it" picked Harkon's commit hand-over)
$grHand = static function (string $txt) use ($grDecor): array {
    $r = $grDecor([v1Row($txt, ['tk' => 'gr:h' . md5($txt)])])[0];
    return [(string) $r['norm'], (string) ($r['hand'] ?? '?')];
};
chk('pt19h-grading r2 G16: "Do you think this would help? (Give Journal)" (01667C) carries "journal" as its hand, and its match norm is the line\'s own',
    $grHand('Do you think this would help? (Give Journal)') === [lrgPromptNorm('Do you think this would help? (Give Journal)'), 'journal'],
    json_encode($grHand('Do you think this would help? (Give Journal)')));
chk('pt19h-grading G16: the recipient is no object - "(Give Dragonstone to Farengar)" -> "dragonstone", "(Give Aela the ring)" -> "ring"',
    $grHand('Oh, do you mean this old stone? (Give Dragonstone to Farengar)')[1] === 'dragonstone'
    && $grHand('Aye, but this was your hunt as well. Here. (Give Aela the ring)')[1] === 'ring',
    json_encode([$grHand('Oh, do you mean this old stone? (Give Dragonstone to Farengar)'), $grHand('Aye, but this was your hunt as well. Here. (Give Aela the ring)')]));
$grHar = [v1Row('Never.', ['tk' => 'gr:hk2', 'topic' => 'DLC1VQ08HarkonEndBranchTopic01b', 'quest' => 'DLC1VQ08', 'scripted' => 1, 'goodbye' => 1]),
    v1Row("Very well. (Give Auriel's Bow)", ['tk' => 'gr:hk1', 'ik' => 'dawnguard.esm:012F5C', 'topic' => 'DLC1VQ08HarkonEndBranchTopic01a', 'quest' => 'DLC1VQ08', 'scripted' => 1, 'goodbye' => 1])];
$grHarT = array_column($grHar, 'txt');
foreach (["I have Auriel's Bow, but you can't have it", "I have Auriel's Bow", "yes, I have Auriel's Bow", "I'll keep Auriel's Bow", "is that Auriel's Bow?",
    'I have the bow'] as $grSay) {
    $w = $grFast('Harkon G', $grHar, $grHarT, $grSay, ['q' => 'DLC1VQ08']);
    chk('pt19h-grading r2 G16 must-not-click (fast path): "' . $grSay . '" never hands Auriel\'s Bow over (dawnguard.esm:012F5C) - the object alone is no hand-over',
        !$grPickPos($w, 1), $w['echo']);
    v1Reset($grHar, 1);
    v1Topics('Harkon K', $grHarT, ['q' => 'DLC1VQ08']);
    v1Say('Harkon K', $grSay);
    $r = v1Llm('Harkon K', 'T2', 'So be it.');
    chk('pt19h-grading r2 G16 must-not-click (her key): "' . $grSay . '" + T2 is no explicit hand-over (at most she asks)',
        v1Do($r['out']) !== 'pick' && !$r['will'], json_encode($r['out']));
}
foreach (['Very well.', 'very well, take it'] as $grSay) {
    $w = $grFast('Harkon G', $grHar, $grHarT, $grSay, ['q' => 'DLC1VQ08']);
    chk('pt19h-grading r2 G16 must-resolve: "' . $grSay . '" is Harkon\'s hand-over said plainly - an explicit click (the object prefix had turned it into her question)',
        $grPickPos($w, 1), $w['echo']);
}
$grInv = [v1Row('Here you go. (Show invitation)', ['tk' => 'gr:v1', 'topic' => 'MQ201EmbassyGuardIntroA1', 'quest' => 'MQ201', 'scripted' => 1, 'goodbye' => 1]),
    v1Row('Is there a problem?', ['tk' => 'gr:v2', 'topic' => 'MQ201EmbassyGuardIntroA2', 'quest' => 'MQ201']),
    v1Row('Just a minute. I think I left it on the cart.', ['tk' => 'gr:v3', 'topic' => 'MQ201EmbassyGuardIntroA3', 'quest' => 'MQ201', 'goodbye' => 1])];
$w = $grFast('Embassy Guard G', $grInv, array_column($grInv, 'txt'), 'Here you go.', ['q' => 'MQ201']);
chk('pt19h-grading G16 guard: the embassy guard\'s "Here you go. (Show invitation)" (041CDD) said verbatim is still an explicit click', $grPickPos($w, 0), $w['echo']);
chk('pt19h-grading G16 guard: a price, a token, a pronoun or a word the line already has gives no hand, and no norm moves',
    $grHand('Here. (Give 100 gold)') === ['here', ''] && $grHand('I have this letter for you. (Give letter)') === ['i have this letter for you', '']
    && $grHand('I have some Skooma... (Show her)') === ['i have some skooma', '']
    && $grHand('Recognize this? (Show <Alias.PronounObj=Steward> <Alias=Evidence>)')[1] === '',
    json_encode([$grHand('Here. (Give 100 gold)'), $grHand('I have some Skooma... (Show her)')]));
$grEor = [v1Row('Here, take them. (Give fragments)', ['tk' => 'gr:e1', 'topic' => 'C06EorlundYeahWhatever', 'quest' => 'C06', 'invis' => 1, 'links' => ['skyrim.esm:0E2FDB']]),
    v1Row('I return them with honor. (Give fragments)', ['tk' => 'gr:e2', 'topic' => 'C06EorlundYouBetcha', 'quest' => 'C06', 'scripted' => 1, 'invis' => 1, 'links' => ['skyrim.esm:0E2FDB']])];
$w = $grFast('Eorlund G', $grEor, array_column($grEor, 'txt'), 'here, take the fragments', ['q' => 'C06']);
chk('pt19h-grading G16: "here, take the fragments" says Eorlund\'s hand-over (0E3064) on the fast path (measurer: no match)', $grPickPos($w, 0), $w['echo']);
$w = $grFast('Eorlund G', $grEor, array_column($grEor, 'txt'), 'Here, take them.', ['q' => 'C06']);
chk('pt19h-grading G16 guard: the verbatim line "Here, take them." is still his own sentence (S4.3\'s clipped-first-word floor)', $grPickPos($w, 0), $w['echo']);
$w = $grFast('Eorlund G', $grEor, array_column($grEor, 'txt'), 'not now, here, take them later', ['q' => 'C06']);
chk('pt19h-grading G16 must-not-click: "not now, here, take them later" hands nothing over', !$grPicked($w), $w['echo']);

// ---- G20: Serana's dismissal is a follower commit wherever it is resolved
$grSer = [v1Row('I think we should part ways.', ['tk' => 'gr:sd', 'topic' => 'DLC1NPCMentalModelDismissTopic', 'quest' => 'DLC1NPCMentalModel', 'scripted' => 1, 'goodbye' => 1, 'toplevel' => 1]),
    v1Row('Were you always a vampire?', ['tk' => 'gr:sv', 'topic' => 'DLC1NPCMentalModelBeingAVampireTopic', 'quest' => 'DLC1NPCMentalModel', 'toplevel' => 1])];
$d = $grDecor($grSer);
chk('pt19h-grading G20: Serana\'s "I think we should part ways." (012EBC / 015A93) is a follower commit (fcommit 1): never explicit, she always asks',
    !empty($d[0]['fcommit']) && !empty($d[0]['commit']), json_encode($d[0]));
foreach (['I think we should part ways', 'we should part ways'] as $grSay) {
    v1Reset($grSer, 1);
    v1Topics('Serana G', array_column($grSer, 'txt'), ['layer' => 0]);
    v1Say('Serana G', $grSay);
    $r = v1Llm('Serana G', 'T1', 'You want me gone?');
    chk('pt19h-grading G20: "' . $grSay . '" and her key: she asks first - no pick (measurer: her key picked it EXPLICIT)', $r['out'] === [] && !$r['will'], json_encode($r['out']));
    $w = $grFast('Serana G', $grSer, array_column($grSer, 'txt'), $grSay, ['layer' => 0, 'q' => 'DLC1NPCMentalModel']);
    chk('pt19h-grading G20: "' . $grSay . '" on the fast path: no pick', !$grPicked($w), $w['echo']);
}

// ---- the Brotherhood misgrades and the entry-side back-out
$d = $grDecor([v1Row('I did what had to be done. Nothing more.', ['tk' => 'gr:as1', 'topic' => 'DB03AstridCompleteResponse1', 'quest' => 'DarkBrotherhood', 'links' => ['skyrim.esm:03B66B']]),
    v1Row('I live only to serve. Hail Sithis!', ['tk' => 'gr:as2', 'topic' => 'DB03AstridCompleteResponse2', 'quest' => 'DarkBrotherhood', 'links' => ['skyrim.esm:03B66B']])]);
chk('pt19h-grading Brotherhood C15: Astrid\'s report "I did what had to be done. Nothing more." (021451) is no back-out - plain, so LEAVE never takes it',
    $d[0]['class'] === 'plain', $grCls($d));
$d = $grDecor([v1Row("What's in it for me?", ['tk' => 'gr:dd1', 'topic' => 'DBDestroy_Reward', 'quest' => 'DBDestroy']),
    v1Row("Fine, I'll do it.", ['tk' => 'gr:dd2', 'topic' => 'DBDestroy_Yes', 'quest' => 'DBDestroy', 'scripted' => 1]),
    v1Row("Sorry, I'm not interested. (Fail Quest)", ['tk' => 'gr:dd3', 'topic' => 'DBDestroy_No', 'quest' => 'DBDestroy', 'scripted' => 1, 'goodbye' => 1])]);
chk('pt19h-grading Brotherhood C15: the Destroy QE decline "Sorry, I\'m not interested. (Fail Quest)" (000E1C) is a commit, not meta (it was never voiceable)',
    $d[2]['class'] === 'commit' && !empty($d[2]['commit']), $grCls($d));
$grBack = ['Never mind.', "I'm not sure.", 'Fine, forget it.', "I'll come back later.", 'Nothing.', 'Nothing for now.', 'Nothing more.', 'No thanks.', 'Maybe later.',
    "Actually, I'm not sure yet. I need more time to think about it.", 'No, I need more time.', 'How about another time?'];
$grNotBack = ["Nothing I couldn't handle.", "Nothing I can't handle.", 'I have nothing to hide. The Blades helped me find out about it.',
    "We're not sure, but they have an Elder Scroll.", "I'll do nothing of the sort.", 'The Thalmor know nothing about the dragons.',
    'Ulfric holds nothing worth trading Markarth for.', "So that's it? There's nothing else to it?", 'I did what had to be done. Nothing more.'];
$bad = [];
foreach ($grBack as $x) { if (!lrgDlgIsBackOut($x)) { $bad[] = 'not back: ' . $x; } }
foreach ($grNotBack as $x) { if (lrgDlgIsBackOut($x)) { $bad[] = 'back: ' . $x; } }
chk('pt19h-grading the entry-side back-out: the shipped back-outs keep class back; "nothing" / "not sure" inside an answer no longer make it one (G8 shape, Brotherhood C15)',
    $bad === [], implode(' | ', $bad));

// ---- spec 3.5: a LETHAL line matched on the FAST path is shown (do=show kind=meta), never clicked, never a silent drop
$grLet = [v1Row('Perhaps you care to explain this letter then?', ['tk' => 'gr:l1', 'crit' => 2, 'scripted' => 1]), v1Row('I have no idea what you mean.', ['tk' => 'gr:l2'])];
$w = $grFast('Agmaer G', $grLet, array_column($grLet, 'txt'), 'explain this letter');
chk('pt19h-grading spec 3.5: "explain this letter" on a crit 2 line - the fast path hands the menu back (do=show kind=meta), no pick',
    str_contains((string) $w['echo'], ';do=show;') && str_contains((string) $w['echo'], ';kind=meta;') && !$grPicked($w), $w['echo']);
unset($GLOBALS['LRG_DLG_TURN']);
$GLOBALS['LRG_DLG_STATE'] = [];

printf("\n%d passed, %d failed\n", $ok, $fail);
echo $fail === 0 ? "ALL CHECKS PASSED\n" : "RESULT: FAILED\n";
exit($fail === 0 ? 0 : 1);
