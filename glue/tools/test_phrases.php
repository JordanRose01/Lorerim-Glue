<?php
/**
 * [0.3.1 / R2] THE PHRASE MATRIX - every sentence the owner (or the playtest-7 log) really said, run
 * through the real recogniser in three states, with the outcome she expects written next to it.
 *
 * Usage (inside WSL):  php tools/test_phrases.php            the whole table
 *                      php tools/test_phrases.php --quiet    only the checks and the summary
 *                      php tools/test_phrases.php --fails    only the rows that miss
 *                      php tools/test_phrases.php --tsv      the full table as TSV, for a diff
 *
 * The rows come from the playtest-7 coverage audit (research/pt7-act-coverage.md section 2): 218 PLAYER
 * sentences. No line an NPC says is ever written into a test, a prompt or a config.
 *
 * Two tiers, deliberately:
 *   MUST  - the owner's own six, the phrases the playtest-7 log proves she said, the blocker rails and
 *           the clothing rails. A miss here FAILS the run.
 *   floor - everything else is coverage: the run fails only if the overall hit rate drops below
 *           PHRASE_FLOOR, so a future change cannot quietly undo the work of this round while the
 *           remaining misses stay visible in the output.
 *
 * Fields per row: say · kind (the intent kind expected) · act (the expected act FAMILY; a position on
 * top of it is fine and is what R2 asked for; ANY = any act) · pos (a regex the resolved scene id must
 * match when a position was named) · out (what should happen with no scene running: start | talk |
 * undress | none) · installed (no = the packs cannot do it, so the only correct answer is a spoken
 * "can't", never a substitute).
 */
require __DIR__ . '/../server/lorerim_glue/lib/lrg_actions.php';

const PHRASE_FLOOR = 0.82;          // overall hit rate that must hold across the 654 result rows (0.3.1: 84.7%)
const PHRASE_SEXES = ['male', 'female'];   // [player, npc] - the pair playtest 7 was about
const PHRASE_NEARF = ['doublebed', 'chair', 'table'];
/** Groups whose every row is a hard requirement of this round. */
// [0.4] "money" joins the MUST groups: every one of its rows is a rail of "everyone has a price" -
// what counts as an offer, what is only a question, and what must never move a septim.
const PHRASE_MUST_GROUPS = ['owner pt7', 'pt7 log F1', 'pt7 log F2', 'pt7 log F4', 'pt7 log', 'must NOT trigger', 'clothes', 'money'];

$quiet = in_array('--quiet', $argv, true);
$onlyFails = in_array('--fails', $argv, true);
$tsv = in_array('--tsv', $argv, true);
$GLOBALS['LRG_TEST_NO_SPAWN'] = true;
$GLOBALS['PLAYER_NAME'] = 'Dovah';
if (function_exists('lrgIndexWarm')) { lrgIndexWarm(); }

$pass = 0; $fail = 0;
function pcheck(string $what, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("  [%s] %s%s\n", $ok ? 'ok' : 'FAIL', $what, (!$ok && $detail !== '') ? "  <- $detail" : '');
}
function psay(string $l = ''): void { global $quiet, $tsv; if (!$quiet && !$tsv) { echo $l . "\n"; } }

$STATES = [
    'A_noscene' => null,
    'B_kiss'    => ['current' => 'OARE_StandingEmbraceKiss', 'ceiling' => 2],
    'C_doggy'   => ['current' => 'OARE_Doggystyle', 'ceiling' => 4],
];

/** Replays lrgPrepareTurn's steps 1-5 for one utterance in one state, exactly as the plugin does. */
function phraseRun(string $say, ?array $st): array
{
    $sexes = PHRASE_SEXES;
    $npos = 1;
    if ($st === null) {
        // [0.3.1 / D9] out of a scene the recogniser is handed a REAL context now (it used to get null,
        // which switched the act layer, the position index and the text search off entirely)
        $ctx = ['current' => '', 'live' => [], 'sexes' => $sexes, 'furn' => '', 'tier' => 0, 'player' => 'Dovah',
            'npc' => 'Lisette', 'npos' => 1, 'ceiling' => 4, 'req_ceiling' => 4, 'reached' => 4,
            'furn_options' => [], 'acts' => [], 'nearf' => PHRASE_NEARF, 'acts_done' => [], 'auto_ok' => false];
        $intent = lrgRecogniseIntent($say, $ctx, []);
        $turn = ['npc' => 'Lisette', 'cid' => 'x', 'mode' => 'private', 'type' => 'inputtext', 'intent' => $intent,
            'acts' => [], 'ctx' => $ctx, 'scene_confirmed' => false, 'offered' => [LRG_ACT_START, LRG_ACT_CLOTHING]];
        return ['intent' => $intent, 'acts' => [], 'directive' => lrgBuildRequestDirective($turn),
            'net' => phraseNet($turn, false), 'route' => '-', 'scene' => (string) ($intent['start_scene'] ?? ($intent['kv']['scene'] ?? ''))];
    }
    $current = $st['current'];
    $ceiling = (int) $st['ceiling'];
    $cur = lrgScene($current);
    $furn = '';
    $base = ['current' => $current, 'live' => [], 'sexes' => $sexes, 'furn' => $furn,
        'tier' => $cur ? (int) LRG_TIERS[$cur['tier']] : 0, 'player' => 'Dovah', 'npc' => 'Lisette', 'npos' => $npos,
        'acts_done' => ['kiss']];
    $furnOpts = lrgFurnitureOptions(PHRASE_NEARF, $current, $sexes, 4, $furn);
    $intent = lrgRecogniseIntent($say, $base + ['ceiling' => 4, 'reached' => 4, 'furn_options' => $furnOpts, 'auto_ok' => false], []);
    $reqCeiling = (in_array($intent['kind'], ['act', 'yes'], true) && $intent['conf'] === 'high') ? 4 : $ceiling;
    $acts = lrgActOptions($current, [], $ceiling, $sexes, $furn, $npos, [], 8);
    $ctx = $base + ['ceiling' => $ceiling, 'req_ceiling' => $reqCeiling, 'reached' => $ceiling,
        'furn_options' => $furnOpts, 'acts' => $acts, 'auto_ok' => false];
    if ($intent['kind'] !== 'none') {
        $intent = lrgIntentResolve($intent, $ctx, $reqCeiling);
        $intent['words'] = lrgIntentWords($intent, $ctx);
    }
    $turn = ['npc' => 'Lisette', 'cid' => 'x', 'mode' => 'scene', 'type' => 'inputtext', 'intent' => $intent,
        'acts' => $acts, 'ctx' => $ctx, 'scene' => ['scene' => $current], 'scene_confirmed' => true, 'can_act' => true,
        'offered' => [LRG_ACT_CONTROL, LRG_ACT_CLOTHING, LRG_ACT_REQUESTACT]];
    $scene = (string) ($intent['kv']['scene'] ?? '');
    $route = $scene === '' ? '-' : (isset(lrgSceneWalk($current, [], $reqCeiling, 3, $sexes, $furn)[strtolower($scene)])
        ? 'navigate' : (isset($intent['kv']['warp']) ? 'warp' : 'UNROUTED'));
    return ['intent' => $intent, 'acts' => $acts, 'directive' => lrgBuildRequestDirective($turn),
        'net' => phraseNet($turn, true), 'route' => $route, 'scene' => $scene];
}

/** Would the safety net fire? Mirrors lrgSafetyNet's preconditions without writing a log line. */
function phraseNet(array $turn, bool $inScene): string
{
    $in = $turn['intent'];
    if (($in['kind'] ?? 'none') === 'none') { return 'no intent'; }
    if (!$inScene) { return 'not in scene'; }
    if (($in['conf'] ?? '') !== 'high') { return 'conf ' . $in['conf']; }
    if (!empty($in['cant'])) { return 'cant: ' . $in['cant']; }
    if (!empty($in['already'])) { return 'already there'; }
    if (!($in['kv'] ?? [])) { return 'nothing resolved'; }
    return 'FIRES';
}

/** The four directive shapes, by their own wording. */
function phraseShape(string $d): string
{
    if ($d === '') { return '-'; }
    if (str_contains($d, 'This is the moment')) { return 'OPEN'; }
    if (str_contains($d, 'no way for the two of them')) { return 'CANT-NEVER'; }
    if (str_contains($d, 'not possible from here')) { return 'CANT'; }
    if (str_contains($d, 'doing right now')) { return 'ALREADY'; }
    if (str_contains($d, 'Do it now')) { return 'DOIT'; }
    if (str_contains($d, 'may have asked')) { return 'MAYBE'; }
    if (str_contains($d, 'said no to what')) { return 'NO'; }
    return 'NEUTRAL';
}

/** One verdict per (row, state). 'ok' or the first rule that failed. */
function phraseVerdict(array $r, ?array $st, array $res): string
{
    $i = $res['intent'];
    if ($r['kind'] === 'none') { return $i['kind'] === 'none' ? 'ok' : 'FIRED'; }
    if ($i['kind'] !== $r['kind']) { return 'KIND'; }
    if ($r['kind'] === 'act' && $r['act'] !== '' && $r['act'] !== 'ANY' && lrgActBase((string) $i['act']) !== $r['act']) { return 'ACT'; }
    if ($i['conf'] !== 'high') { return 'CONF'; }
    // an act the packs cannot do must be an explicit, spoken "can't" - never a substitute, never silence
    if ($r['installed'] === 'no') {
        return (($i['cant'] ?? '') === 'not installed' && ($i['kv'] ?? []) === []) ? 'ok' : 'SUBSTITUTED';
    }
    if ($st !== null && $r['kind'] === 'act') {
        if ($res['scene'] === '' && empty($i['already'])) { return 'UNREACHED'; }
        if ($r['pos'] !== '' && $res['scene'] !== '' && !preg_match('/' . $r['pos'] . '/i', $res['scene'])) { return 'POS'; }
    }
    if ($st === null && $r['out'] === 'start' && !in_array(phraseShape($res['directive']), ['OPEN', 'CANT-NEVER'], true)) { return 'NOSTART'; }
    return 'ok';
}

$ROWS = json_decode(<<<'LRGJSON'
[
  {"id":"A1","group":"owner pt7","say":"give me a blowjob","kind":"act","act":"oralpenis:npc","pos":"fellatio|blowjob|mouthfuck|hj","out":"start","installed":"yes"},
  {"id":"A2","group":"owner pt7","say":"give me a hug","kind":"act","act":"hold","pos":"hug|embrace|cuddl|hold","out":"start","installed":"yes"},
  {"id":"A3","group":"owner pt7","say":"let me eat your pussy","kind":"act","act":"oralvulva:you","pos":"cunnilingus|cl|vulval|eating","out":"start","installed":"yes"},
  {"id":"A4","group":"owner pt7","say":"lets do 69","kind":"act","act":"sixtynine","pos":"","out":"talk","installed":"no"},
  {"id":"A5","group":"owner pt7","say":"i want to fuck your asshole","kind":"act","act":"anal","pos":"","out":"talk","installed":"no"},
  {"id":"A6","group":"owner pt7","say":"do reverse cowgirl","kind":"act","act":"vaginal","pos":"reversecowgirl","out":"start","installed":"yes"},
  {"id":"B1","group":"pt7 log F1","say":"now get down on your knees and suck my deck","kind":"act","act":"oralpenis:npc","pos":"fellatio|blowjob|kneeling","out":"start","installed":"yes"},
  {"id":"B2","group":"pt7 log F2","say":"take my clothes off and then let's book a missionary","kind":"undress","act":"","pos":"","out":"undress","installed":"yes"},
  {"id":"B3","group":"pt7 log F4","say":"take off your clothes","kind":"undress","act":"","pos":"","out":"undress","installed":"yes"},
  {"id":"B4","group":"pt7 log F4","say":"bend over and let me start doing doggy style","kind":"act","act":"vaginal","pos":"doggy|rearsex|backitup|bentover|prone","out":"start","installed":"yes"},
  {"id":"B5","group":"pt7 log F4","say":"go again","kind":"act","act":"ANY","pos":"","out":"start","installed":"yes"},
  {"id":"B6","group":"pt7 log F4","say":"come kiss me","kind":"act","act":"kiss","pos":"kiss","out":"start","installed":"yes"},
  {"id":"B7","group":"pt7 log F4","say":"i want you to ride me","kind":"act","act":"vaginal","pos":"cowgirl|ride|mounted","out":"start","installed":"yes"},
  {"id":"B8","group":"pt7 log","say":"let's have doggy style socks","kind":"act","act":"vaginal","pos":"doggy|rearsex|backitup","out":"start","installed":"yes"},
  {"id":"B9","group":"pt7 log","say":"i wanna fuck you standing up","kind":"act","act":"vaginal","pos":"standing","out":"start","installed":"yes"},
  {"id":"B10","group":"pt7 log","say":"faster","kind":"faster","act":"","pos":"","out":"talk","installed":"yes"},
  {"id":"B11","group":"pt7 log","say":"undress her","kind":"undress","act":"","pos":"","out":"undress","installed":"yes"},
  {"id":"B12","group":"pt7 log","say":"undress me","kind":"undress","act":"","pos":"","out":"undress","installed":"yes"},
  {"id":"C1","group":"oral penis","say":"suck my cock","kind":"act","act":"oralpenis:npc","pos":"fellatio|blowjob|mouthfuck","out":"start","installed":"yes"},
  {"id":"C2","group":"oral penis","say":"suck my dick","kind":"act","act":"oralpenis:npc","pos":"fellatio|blowjob|mouthfuck","out":"start","installed":"yes"},
  {"id":"C3","group":"oral penis","say":"put your mouth on my cock","kind":"act","act":"oralpenis:npc","pos":"fellatio|blowjob","out":"start","installed":"yes"},
  {"id":"C4","group":"oral penis","say":"blow me","kind":"act","act":"oralpenis:npc","pos":"fellatio|blowjob","out":"start","installed":"yes"},
  {"id":"C5","group":"oral penis","say":"get on your knees and blow me","kind":"act","act":"oralpenis:npc","pos":"kneeling|fellatio|blowjob","out":"start","installed":"yes"},
  {"id":"C6","group":"oral penis","say":"deepthroat me","kind":"act","act":"oralpenis:npc","pos":"fellatio|blowjob|mouthfuck|facefuck","out":"start","installed":"yes"},
  {"id":"C7","group":"oral penis","say":"i want a blowjob","kind":"act","act":"oralpenis:npc","pos":"fellatio|blowjob","out":"start","installed":"yes"},
  {"id":"C8","group":"oral penis","say":"can you suck me off","kind":"act","act":"oralpenis:npc","pos":"fellatio|blowjob","out":"start","installed":"yes"},
  {"id":"C9","group":"oral penis","say":"lick my cock","kind":"act","act":"oralpenis:npc","pos":"licking|fellatio|blowjob","out":"start","installed":"yes"},
  {"id":"C10","group":"oral penis","say":"use your mouth on me","kind":"act","act":"oralpenis:npc","pos":"fellatio|blowjob","out":"start","installed":"yes"},
  {"id":"C11","group":"oral penis","say":"go down on me","kind":"act","act":"oralpenis:npc","pos":"fellatio|blowjob|kneeling","out":"start","installed":"yes"},
  {"id":"C12","group":"oral penis","say":"face fuck","kind":"act","act":"oralpenis:npc","pos":"facefuck|mouthfuck","out":"start","installed":"yes"},
  {"id":"D1","group":"oral vulva","say":"eat my pussy","kind":"act","act":"oralvulva:npc","pos":"","out":"talk","installed":"no"},
  {"id":"D2","group":"oral vulva","say":"let me lick your pussy","kind":"act","act":"oralvulva:you","pos":"cunnilingus|cl|vulval","out":"start","installed":"yes"},
  {"id":"D3","group":"oral vulva","say":"let me go down on you","kind":"act","act":"oralvulva:you","pos":"cunnilingus|cl|vulval|kneeling","out":"start","installed":"yes"},
  {"id":"D4","group":"oral vulva","say":"i want to taste you","kind":"act","act":"oralvulva:you","pos":"cunnilingus|cl|vulval","out":"start","installed":"yes"},
  {"id":"D5","group":"oral vulva","say":"spread your legs i want to eat you out","kind":"act","act":"oralvulva:you","pos":"cunnilingus|cl|vulval","out":"start","installed":"yes"},
  {"id":"D6","group":"oral vulva","say":"can i lick you","kind":"act","act":"oralvulva:you","pos":"cunnilingus|cl|vulval","out":"start","installed":"yes"},
  {"id":"D7","group":"oral vulva","say":"sit on my face","kind":"act","act":"oralvulva:you","pos":"facesit|faceriding","out":"start","installed":"yes"},
  {"id":"D8","group":"oral vulva","say":"facesitting","kind":"act","act":"oralvulva:you","pos":"facesit|faceriding","out":"start","installed":"yes"},
  {"id":"D9","group":"oral vulva","say":"put your tongue on me","kind":"act","act":"oralpenis:npc","pos":"licking|fellatio","out":"start","installed":"yes"},
  {"id":"E1","group":"69","say":"lets do sixty nine","kind":"act","act":"sixtynine","pos":"","out":"talk","installed":"no"},
  {"id":"E2","group":"69","say":"i want to 69","kind":"act","act":"sixtynine","pos":"","out":"talk","installed":"no"},
  {"id":"E3","group":"69","say":"can we do a 69","kind":"act","act":"sixtynine","pos":"","out":"talk","installed":"no"},
  {"id":"F1","group":"anal","say":"i want to fuck you in the ass","kind":"act","act":"anal","pos":"","out":"talk","installed":"no"},
  {"id":"F2","group":"anal","say":"anal","kind":"act","act":"anal","pos":"","out":"talk","installed":"no"},
  {"id":"F3","group":"anal","say":"take it in the ass","kind":"act","act":"anal","pos":"","out":"talk","installed":"no"},
  {"id":"F4","group":"anal","say":"let me put it in your ass","kind":"act","act":"anal","pos":"","out":"talk","installed":"no"},
  {"id":"F5","group":"anal","say":"anal sex","kind":"act","act":"anal","pos":"","out":"talk","installed":"no"},
  {"id":"F6","group":"anal","say":"fuck my ass","kind":"act","act":"anal","pos":"","out":"talk","installed":"no"},
  {"id":"F7","group":"anal","say":"finger my ass","kind":"act","act":"fingering:npc","pos":"","out":"talk","installed":"no"},
  {"id":"G1","group":"hug / hold","say":"hug me","kind":"act","act":"hold","pos":"hug|embrace|cuddl|hold","out":"start","installed":"yes"},
  {"id":"G2","group":"hug / hold","say":"come here and hold me","kind":"act","act":"hold","pos":"hug|embrace|cuddl|hold","out":"start","installed":"yes"},
  {"id":"G3","group":"hug / hold","say":"cuddle with me","kind":"act","act":"hold","pos":"cuddl|spoon|lap|hold","out":"start","installed":"yes"},
  {"id":"G4","group":"hug / hold","say":"lets spoon","kind":"act","act":"hold","pos":"spoon","out":"start","installed":"yes"},
  {"id":"G5","group":"hug / hold","say":"hold me close","kind":"act","act":"hold","pos":"hug|embrace|hold|cuddl","out":"start","installed":"yes"},
  {"id":"G6","group":"hug / hold","say":"put your head on my lap","kind":"act","act":"hold","pos":"lappillow|lap","out":"start","installed":"yes"},
  {"id":"G7","group":"hug / hold","say":"snuggle up to me","kind":"act","act":"hold","pos":"cuddl|spoon|hug","out":"start","installed":"yes"},
  {"id":"G8","group":"hug / hold","say":"i just want to hold you","kind":"act","act":"hold","pos":"hug|embrace|hold|cuddl","out":"start","installed":"yes"},
  {"id":"H1","group":"kiss","say":"kiss me","kind":"act","act":"kiss","pos":"kiss","out":"start","installed":"yes"},
  {"id":"H2","group":"kiss","say":"lets make out","kind":"act","act":"kiss","pos":"kiss|embrace","out":"start","installed":"yes"},
  {"id":"H3","group":"kiss","say":"french kiss me","kind":"act","act":"kiss","pos":"kiss","out":"start","installed":"yes"},
  {"id":"H4","group":"kiss","say":"kiss my neck","kind":"act","act":"kiss","pos":"neck|kiss","out":"start","installed":"yes"},
  {"id":"H5","group":"kiss","say":"give me a kiss","kind":"act","act":"kiss","pos":"kiss","out":"start","installed":"yes"},
  {"id":"I1","group":"grope","say":"grab my ass","kind":"act","act":"grope:npc","pos":"grop|butt","out":"start","installed":"yes"},
  {"id":"I2","group":"grope","say":"let me grab your tits","kind":"act","act":"grope:you","pos":"grop|breast","out":"start","installed":"yes"},
  {"id":"I3","group":"grope","say":"play with your tits","kind":"act","act":"grope:npc","pos":"grop|breast|masturb","out":"start","installed":"yes"},
  {"id":"I4","group":"grope","say":"squeeze my cock","kind":"act","act":"handjob:npc","pos":"handjob|hj|jerk","out":"start","installed":"yes"},
  {"id":"I5","group":"grope","say":"let me touch your breasts","kind":"act","act":"grope:you","pos":"grop|breast","out":"start","installed":"yes"},
  {"id":"I6","group":"grope","say":"suck on my nipples","kind":"act","act":"nipples:npc","pos":"","out":"talk","installed":"no"},
  {"id":"I7","group":"grope","say":"let me suck your nipples","kind":"act","act":"nipples:you","pos":"nipple|breastsucking","out":"start","installed":"yes"},
  {"id":"J1","group":"handjob","say":"jerk me off","kind":"act","act":"handjob:npc","pos":"handjob|hj|jerk","out":"start","installed":"yes"},
  {"id":"J2","group":"handjob","say":"give me a handjob","kind":"act","act":"handjob:npc","pos":"handjob|hj|jerk","out":"start","installed":"yes"},
  {"id":"J3","group":"handjob","say":"use your hand on me","kind":"act","act":"handjob:npc","pos":"handjob|hj|jerk","out":"start","installed":"yes"},
  {"id":"J4","group":"handjob","say":"stroke my cock","kind":"act","act":"handjob:npc","pos":"handjob|hj|jerk|strok","out":"start","installed":"yes"},
  {"id":"K1","group":"fingering","say":"finger me","kind":"act","act":"fingering:npc","pos":"","out":"talk","installed":"no"},
  {"id":"K2","group":"fingering","say":"let me finger you","kind":"act","act":"fingering:you","pos":"fingering","out":"start","installed":"yes"},
  {"id":"K3","group":"fingering","say":"rub my cock","kind":"act","act":"handjob:npc","pos":"handjob|hj|jerk|grind","out":"start","installed":"yes"},
  {"id":"K4","group":"fingering","say":"put your fingers inside you","kind":"act","act":"masturbation:npc","pos":"masturb","out":"start","installed":"yes"},
  {"id":"K5","group":"fingering","say":"touch yourself","kind":"act","act":"masturbation:npc","pos":"masturb","out":"start","installed":"yes"},
  {"id":"K6","group":"fingering","say":"let me play with your pussy","kind":"act","act":"fingering:you","pos":"fingering|vulval","out":"start","installed":"yes"},
  {"id":"L1","group":"tit / thigh / foot","say":"give me a titjob","kind":"act","act":"titfuck:npc","pos":"boobjob|titfuck|tittyfuck","out":"start","installed":"yes"},
  {"id":"L2","group":"tit / thigh / foot","say":"fuck my cock with your tits","kind":"act","act":"titfuck:npc","pos":"boobjob|titfuck|tittyfuck","out":"start","installed":"yes"},
  {"id":"L3","group":"tit / thigh / foot","say":"use your thighs","kind":"act","act":"thighjob:npc","pos":"thigh","out":"start","installed":"yes"},
  {"id":"L4","group":"tit / thigh / foot","say":"give me a footjob","kind":"act","act":"footjob:npc","pos":"footjob|feet","out":"start","installed":"yes"},
  {"id":"L5","group":"tit / thigh / foot","say":"use your feet on me","kind":"act","act":"footjob:npc","pos":"footjob|feet","out":"start","installed":"yes"},
  {"id":"L6","group":"tit / thigh / foot","say":"rub me with your feet","kind":"act","act":"footjob:npc","pos":"footjob|feet|grindingfoot","out":"start","installed":"yes"},
  {"id":"M1","group":"position: vaginal","say":"missionary","kind":"act","act":"vaginal","pos":"missionary|frontalsex|holdknees|matingpress","out":"start","installed":"yes"},
  {"id":"M2","group":"position: vaginal","say":"lets do missionary","kind":"act","act":"vaginal","pos":"missionary|frontalsex|holdknees|matingpress","out":"start","installed":"yes"},
  {"id":"M3","group":"position: vaginal","say":"get on top of me","kind":"act","act":"vaginal","pos":"cowgirl|ontop|mounted","out":"start","installed":"yes"},
  {"id":"M4","group":"position: vaginal","say":"ride me","kind":"act","act":"vaginal","pos":"cowgirl|ride|mounted","out":"start","installed":"yes"},
  {"id":"M5","group":"position: vaginal","say":"cowgirl","kind":"act","act":"vaginal","pos":"cowgirl","out":"start","installed":"yes"},
  {"id":"M6","group":"position: vaginal","say":"reverse cowgirl","kind":"act","act":"vaginal","pos":"reversecowgirl","out":"start","installed":"yes"},
  {"id":"M7","group":"position: vaginal","say":"turn around and ride me","kind":"act","act":"vaginal","pos":"reversecowgirl","out":"start","installed":"yes"},
  {"id":"M8","group":"position: vaginal","say":"doggy style","kind":"act","act":"vaginal","pos":"doggy|rearsex|backitup|prone","out":"start","installed":"yes"},
  {"id":"M9","group":"position: vaginal","say":"fuck me from behind","kind":"act","act":"vaginal","pos":"doggy|rearsex|backitup|behind|prone","out":"start","installed":"yes"},
  {"id":"M10","group":"position: vaginal","say":"i want to take you from behind","kind":"act","act":"vaginal","pos":"doggy|rearsex|backitup|behind|prone","out":"start","installed":"yes"},
  {"id":"M11","group":"position: vaginal","say":"bend over","kind":"act","act":"vaginal","pos":"bentover|bendover|doggy|rearsex|shelf|cookingpot","out":"start","installed":"yes"},
  {"id":"M12","group":"position: vaginal","say":"get on all fours","kind":"act","act":"vaginal","pos":"allfours|doggy|rearsex|backitup","out":"start","installed":"yes"},
  {"id":"M13","group":"position: vaginal","say":"lets fuck standing up","kind":"act","act":"vaginal","pos":"standing","out":"start","installed":"yes"},
  {"id":"M14","group":"position: vaginal","say":"carry me while you fuck me","kind":"act","act":"vaginal","pos":"carry|lotus|suspend","out":"start","installed":"yes"},
  {"id":"M15","group":"position: vaginal","say":"pick me up","kind":"act","act":"vaginal","pos":"carry|lotus|suspend","out":"start","installed":"yes"},
  {"id":"M16","group":"position: vaginal","say":"sit on my lap","kind":"act","act":"vaginal","pos":"lap|sitting","out":"start","installed":"yes"},
  {"id":"M17","group":"position: vaginal","say":"against the wall","kind":"act","act":"vaginal","pos":"wall","out":"start","installed":"yes"},
  {"id":"M18","group":"position: vaginal","say":"lets do it spooning","kind":"act","act":"vaginal","pos":"spoon","out":"start","installed":"yes"},
  {"id":"M19","group":"position: vaginal","say":"put me on the table and fuck me","kind":"act","act":"vaginal","pos":"table","out":"start","installed":"yes"},
  {"id":"M20","group":"position: vaginal","say":"lets go to the bed","kind":"furniture","act":"","pos":"bed","out":"start","installed":"yes"},
  {"id":"M21","group":"position: vaginal","say":"fuck me on the chair","kind":"act","act":"vaginal","pos":"chair","out":"start","installed":"yes"},
  {"id":"M22","group":"position: vaginal","say":"lay down and let me fuck you","kind":"act","act":"vaginal","pos":"missionary|frontalsex|lying|prone|holdknees","out":"start","installed":"yes"},
  {"id":"M23","group":"position: vaginal","say":"prone bone","kind":"act","act":"vaginal","pos":"prone","out":"start","installed":"yes"},
  {"id":"M24","group":"position: vaginal","say":"mating press","kind":"act","act":"vaginal","pos":"matingpress|missionary","out":"start","installed":"yes"},
  {"id":"M25","group":"position: vaginal","say":"lets fuck","kind":"act","act":"vaginal","pos":"","out":"start","installed":"yes"},
  {"id":"M26","group":"position: vaginal","say":"i want to be inside you","kind":"act","act":"vaginal","pos":"","out":"start","installed":"yes"},
  {"id":"M27","group":"position: vaginal","say":"put it in","kind":"act","act":"vaginal","pos":"","out":"start","installed":"yes"},
  {"id":"M28","group":"position: vaginal","say":"i want to make love to you","kind":"act","act":"vaginal","pos":"","out":"start","installed":"yes"},
  {"id":"N1","group":"misc acts","say":"grind on me","kind":"act","act":"grinding","pos":"grind","out":"start","installed":"yes"},
  {"id":"N2","group":"misc acts","say":"rub yourself on my cock","kind":"act","act":"grinding","pos":"grind","out":"start","installed":"yes"},
  {"id":"N3","group":"misc acts","say":"spank me","kind":"none","act":"","pos":"","out":"talk","installed":"no"},
  {"id":"N4","group":"misc acts","say":"pull my hair","kind":"none","act":"","pos":"","out":"talk","installed":"no"},
  {"id":"N5","group":"misc acts","say":"rim me","kind":"none","act":"","pos":"","out":"talk","installed":"no"},
  {"id":"N6","group":"misc acts","say":"masturbate for me","kind":"act","act":"masturbation:npc","pos":"masturb","out":"start","installed":"yes"},
  {"id":"N7","group":"misc acts","say":"lick my ear","kind":"none","act":"","pos":"","out":"talk","installed":"yes"},
  {"id":"N8","group":"misc acts","say":"massage me","kind":"none","act":"","pos":"","out":"talk","installed":"yes"},
  {"id":"O1","group":"compound","say":"take my clothes off and then fuck me","kind":"undress","act":"","pos":"","out":"undress","installed":"yes"},
  {"id":"O2","group":"compound","say":"get undressed and get on the bed","kind":"undress","act":"","pos":"","out":"undress","installed":"yes"},
  {"id":"O3","group":"compound","say":"kiss me then suck my cock","kind":"act","act":"kiss","pos":"kiss","out":"start","installed":"yes"},
  {"id":"O4","group":"compound","say":"bend over and let me fuck you from behind","kind":"act","act":"vaginal","pos":"doggy|rearsex|backitup|bentover|prone","out":"start","installed":"yes"},
  {"id":"O5","group":"compound","say":"go faster and grab my ass","kind":"faster","act":"","pos":"","out":"talk","installed":"yes"},
  {"id":"O6","group":"compound","say":"slow down and kiss me","kind":"slower","act":"","pos":"","out":"talk","installed":"yes"},
  {"id":"O7","group":"compound","say":"undress and ride me","kind":"undress","act":"","pos":"","out":"undress","installed":"yes"},
  {"id":"P1","group":"speech-to-text","say":"give me a blow job","kind":"act","act":"oralpenis:npc","pos":"fellatio|blowjob","out":"start","installed":"yes"},
  {"id":"P2","group":"speech-to-text","say":"do a reverse cow girl","kind":"act","act":"vaginal","pos":"reversecowgirl","out":"start","installed":"yes"},
  {"id":"P3","group":"speech-to-text","say":"lets do dogy style","kind":"act","act":"vaginal","pos":"doggy|rearsex|backitup","out":"start","installed":"yes"},
  {"id":"P4","group":"speech-to-text","say":"lets book a missionary","kind":"act","act":"vaginal","pos":"missionary|frontalsex|holdknees","out":"start","installed":"yes"},
  {"id":"P5","group":"speech-to-text","say":"suck my deck","kind":"act","act":"oralpenis:npc","pos":"fellatio|blowjob","out":"start","installed":"yes"},
  {"id":"P6","group":"speech-to-text","say":"hey lazette lets have sex","kind":"act","act":"vaginal","pos":"","out":"start","installed":"yes"},
  {"id":"P7","group":"speech-to-text","say":"i wanna you doggy style","kind":"act","act":"vaginal","pos":"doggy|rearsex|backitup","out":"start","installed":"yes"},
  {"id":"P8","group":"speech-to-text","say":"cow girl position","kind":"act","act":"vaginal","pos":"cowgirl","out":"start","installed":"yes"},
  {"id":"P9","group":"speech-to-text","say":"blowjob please","kind":"act","act":"oralpenis:npc","pos":"fellatio|blowjob","out":"start","installed":"yes"},
  {"id":"P10","group":"speech-to-text","say":"sucky sucky","kind":"none","act":"","pos":"","out":"talk","installed":"yes"},
  {"id":"Q1","group":"must NOT trigger","say":"don't stop","kind":"none","act":"","pos":"","out":"talk","installed":"yes"},
  {"id":"Q2","group":"must NOT trigger","say":"should i take this off?","kind":"none","act":"","pos":"","out":"talk","installed":"yes"},
  {"id":"Q3","group":"must NOT trigger","say":"don't get naked yet","kind":"none","act":"","pos":"","out":"talk","installed":"yes"},
  {"id":"Q4","group":"must NOT trigger","say":"have you ever done doggy style?","kind":"none","act":"","pos":"","out":"talk","installed":"yes"},
  {"id":"Q5","group":"must NOT trigger","say":"what if i asked you for a blowjob","kind":"none","act":"","pos":"","out":"talk","installed":"yes"},
  {"id":"Q6","group":"must NOT trigger","say":"you said you liked being on top","kind":"none","act":"","pos":"","out":"talk","installed":"yes"},
  {"id":"Q7","group":"must NOT trigger","say":"do you want to ride me?","kind":"none","act":"","pos":"","out":"talk","installed":"yes"},
  {"id":"Q8","group":"must NOT trigger","say":"i'd rather not do that","kind":"none","act":"","pos":"","out":"talk","installed":"yes"},
  {"id":"Q9","group":"must NOT trigger","say":"what do you want to try?","kind":"none","act":"","pos":"","out":"talk","installed":"yes"},
  {"id":"Q10","group":"must NOT trigger","say":"maybe later, not now","kind":"none","act":"","pos":"","out":"talk","installed":"yes"},
  {"id":"Q11","group":"must NOT trigger","say":"tell me what you picture my cock looking like","kind":"none","act":"","pos":"","out":"talk","installed":"yes"},
  {"id":"Q12","group":"must NOT trigger","say":"no more of that","kind":"stop","act":"","pos":"","out":"talk","installed":"yes"},
  {"id":"Q13","group":"must NOT trigger","say":"can you feel that?","kind":"none","act":"","pos":"","out":"talk","installed":"yes"},
  {"id":"Q14","group":"must NOT trigger","say":"that's enough, fuck me","kind":"act","act":"vaginal","pos":"","out":"start","installed":"yes"},
  {"id":"R1","group":"controls","say":"faster","kind":"faster","act":"","pos":"","out":"talk","installed":"yes"},
  {"id":"R2","group":"controls","say":"harder","kind":"faster","act":"","pos":"","out":"talk","installed":"yes"},
  {"id":"R3","group":"controls","say":"slow down","kind":"slower","act":"","pos":"","out":"talk","installed":"yes"},
  {"id":"R4","group":"controls","say":"stop","kind":"stop","act":"","pos":"","out":"talk","installed":"yes"},
  {"id":"R5","group":"controls","say":"hold on","kind":"hold","act":"","pos":"","out":"talk","installed":"yes"},
  {"id":"R6","group":"controls","say":"keep going","kind":"release","act":"","pos":"","out":"talk","installed":"yes"},
  {"id":"R7","group":"controls","say":"cum for me","kind":"climax","act":"","pos":"","out":"talk","installed":"yes"},
  {"id":"R8","group":"controls","say":"you lead","kind":"lead_npc","act":"","pos":"","out":"talk","installed":"yes"},
  {"id":"R9","group":"controls","say":"surprise me","kind":"lead_npc","act":"","pos":"","out":"talk","installed":"yes"},
  {"id":"R10","group":"controls","say":"let me lead","kind":"lead_player","act":"","pos":"","out":"talk","installed":"yes"},
  {"id":"R11","group":"controls","say":"lets wind down","kind":"winddown","act":"","pos":"","out":"talk","installed":"yes"},
  {"id":"R12","group":"controls","say":"speed 3","kind":"speed","act":"","pos":"","out":"talk","installed":"yes"},
  {"id":"R13","group":"controls","say":"pull out","kind":"none","act":"","pos":"","out":"talk","installed":"yes"},
  {"id":"S1","group":"clothes","say":"take your clothes off","kind":"undress","act":"","pos":"","out":"undress","installed":"yes"},
  {"id":"S2","group":"clothes","say":"i want you to take my clothes off","kind":"undress","act":"","pos":"","out":"undress","installed":"yes"},
  {"id":"S3","group":"clothes","say":"get naked","kind":"undress","act":"","pos":"","out":"undress","installed":"yes"},
  {"id":"S4","group":"clothes","say":"lets both get naked","kind":"undress","act":"","pos":"","out":"undress","installed":"yes"},
  {"id":"S5","group":"clothes","say":"put your clothes back on","kind":"dress","act":"","pos":"","out":"talk","installed":"yes"},
  {"id":"S6","group":"clothes","say":"take your boots off","kind":"undress","act":"","pos":"","out":"undress","installed":"yes"},
  {"id":"S7","group":"clothes","say":"strip for me","kind":"undress","act":"","pos":"","out":"undress","installed":"yes"},
  {"id":"T1","group":"out-of-scene start","say":"lets have sex","kind":"act","act":"vaginal","pos":"","out":"start","installed":"yes"},
  {"id":"T2","group":"out-of-scene start","say":"come to bed with me","kind":"furniture","act":"","pos":"bed","out":"start","installed":"yes"},
  {"id":"T3","group":"out-of-scene start","say":"another round","kind":"act","act":"ANY","pos":"","out":"start","installed":"yes"},
  {"id":"T4","group":"out-of-scene start","say":"round two","kind":"act","act":"ANY","pos":"","out":"start","installed":"yes"},
  {"id":"T5","group":"out-of-scene start","say":"lets do that again","kind":"act","act":"ANY","pos":"","out":"start","installed":"yes"},
  {"id":"T6","group":"out-of-scene start","say":"i want you again","kind":"act","act":"ANY","pos":"","out":"start","installed":"yes"},
  {"id":"T7","group":"out-of-scene start","say":"come here","kind":"none","act":"","pos":"","out":"talk","installed":"yes"},
  {"id":"T8","group":"out-of-scene start","say":"i want you right now","kind":"act","act":"ANY","pos":"","out":"start","installed":"yes"},
  {"id":"U1","group":"furniture","say":"lets move to the bed","kind":"furniture","act":"","pos":"bed","out":"start","installed":"yes"},
  {"id":"U2","group":"furniture","say":"get on the table","kind":"furniture","act":"","pos":"table","out":"start","installed":"yes"},
  {"id":"U3","group":"furniture","say":"sit on the chair","kind":"furniture","act":"","pos":"chair","out":"start","installed":"yes"},
  {"id":"U4","group":"furniture","say":"over here against the wall","kind":"furniture","act":"","pos":"wall","out":"start","installed":"yes"},
  {"id":"U5","group":"furniture","say":"get on the bedroll","kind":"furniture","act":"","pos":"bedroll|bed","out":"start","installed":"yes"},
  {"id":"V1","group":"compound 2","say":"put your mouth on me and then ride me","kind":"act","act":"oralpenis:npc","pos":"fellatio|blowjob","out":"start","installed":"yes"},
  {"id":"V2","group":"compound 2","say":"first kiss me then take your clothes off","kind":"act","act":"kiss","pos":"kiss","out":"start","installed":"yes"},
  {"id":"V3","group":"compound 2","say":"suck me off and then i want to fuck you doggy style","kind":"act","act":"oralpenis:npc","pos":"fellatio|blowjob","out":"start","installed":"yes"},
  {"id":"V4","group":"compound 2","say":"turn around, bend over and take it","kind":"act","act":"vaginal","pos":"doggy|rearsex|bentover|backitup","out":"start","installed":"yes"},
  {"id":"W1","group":"role words","say":"i want you on top","kind":"act","act":"vaginal","pos":"cowgirl|ontop|mounted","out":"start","installed":"yes"},
  {"id":"W2","group":"role words","say":"let me be on top","kind":"act","act":"vaginal","pos":"missionary|frontalsex|prone|holdknees","out":"start","installed":"yes"},
  {"id":"W3","group":"role words","say":"you do the work","kind":"lead_npc","act":"","pos":"","out":"talk","installed":"yes"},
  {"id":"W4","group":"role words","say":"let me do the work","kind":"lead_player","act":"","pos":"","out":"talk","installed":"yes"},
  {"id":"W5","group":"role words","say":"i want to watch you touch yourself","kind":"act","act":"masturbation:npc","pos":"masturb","out":"start","installed":"yes"},
  {"id":"W6","group":"role words","say":"let me stroke myself while you watch","kind":"act","act":"masturbation:you","pos":"masturb","out":"start","installed":"yes"},
  {"id":"X1","group":"posture only","say":"kneel down","kind":"act","act":"oralpenis:npc","pos":"kneeling|fellatio","out":"start","installed":"yes"},
  {"id":"X2","group":"posture only","say":"lie down","kind":"none","act":"","pos":"","out":"talk","installed":"yes"},
  {"id":"X3","group":"posture only","say":"stand up","kind":"none","act":"","pos":"","out":"talk","installed":"yes"},
  {"id":"X4","group":"posture only","say":"turn around","kind":"none","act":"","pos":"","out":"talk","installed":"yes"},
  {"id":"X5","group":"posture only","say":"come closer","kind":"none","act":"","pos":"","out":"talk","installed":"yes"},
  {"id":"Y1","group":"plain talk","say":"i love you","kind":"none","act":"","pos":"","out":"talk","installed":"yes"},
  {"id":"Y2","group":"plain talk","say":"you are beautiful","kind":"none","act":"","pos":"","out":"talk","installed":"yes"},
  {"id":"Y3","group":"plain talk","say":"how was your day","kind":"none","act":"","pos":"","out":"talk","installed":"yes"},
  {"id":"Y4","group":"plain talk","say":"do you want to follow me","kind":"none","act":"","pos":"","out":"talk","installed":"yes"},
  {"id":"Y5","group":"plain talk","say":"that feels good","kind":"none","act":"","pos":"","out":"talk","installed":"yes"},
  {"id":"Y6","group":"plain talk","say":"i can't get enough of you","kind":"none","act":"","pos":"","out":"talk","installed":"yes"},
  {"id":"Y7","group":"plain talk","say":"you feel amazing","kind":"none","act":"","pos":"","out":"talk","installed":"yes"},
  {"id":"Y8","group":"plain talk","say":"where do you work","kind":"none","act":"","pos":"","out":"talk","installed":"yes"},
  {"id":"Z1","group":"crude","say":"put it in my mouth","kind":"act","act":"oralpenis:npc","pos":"fellatio|blowjob|mouthfuck","out":"start","installed":"yes"},
  {"id":"Z2","group":"crude","say":"i wanna nut in your mouth","kind":"act","act":"oralpenis:npc","pos":"fellatio|blowjob|facial","out":"start","installed":"yes"},
  {"id":"Z3","group":"crude","say":"choke on it","kind":"act","act":"oralpenis:npc","pos":"facefuck|mouthfuck|fellatio","out":"start","installed":"yes"},
  {"id":"Z4","group":"crude","say":"bounce on my cock","kind":"act","act":"vaginal","pos":"cowgirl|ride|mounted","out":"start","installed":"yes"},
  {"id":"Z5","group":"crude","say":"pound me","kind":"act","act":"vaginal","pos":"","out":"start","installed":"yes"},
  {"id":"Z6","group":"crude","say":"railed me harder","kind":"faster","act":"","pos":"","out":"talk","installed":"yes"},
  {"id":"Z7","group":"crude","say":"get that ass over here","kind":"act","act":"vaginal","pos":"doggy|rearsex|bentover","out":"start","installed":"yes"},
  {"id":"Z8","group":"crude","say":"work that cock","kind":"act","act":"handjob:npc","pos":"handjob|hj|fellatio","out":"start","installed":"yes"},
  {"id":"Z9","group":"crude","say":"let me hit it from the back","kind":"act","act":"vaginal","pos":"doggy|rearsex|backitup|behind|prone","out":"start","installed":"yes"},
  {"id":"Z10","group":"crude","say":"get that pussy on me","kind":"act","act":"vaginal","pos":"cowgirl|ride|mounted","out":"start","installed":"yes"},
  {"id":"M1","group":"money","say":"how much for a night","kind":"askprice","act":"","pos":"","out":"talk","installed":"yes"},
  {"id":"M2","group":"money","say":"name your price","kind":"askprice","act":"","pos":"","out":"talk","installed":"yes"},
  {"id":"M3","group":"money","say":"what would it take","kind":"askprice","act":"","pos":"","out":"talk","installed":"yes"},
  {"id":"M4","group":"money","say":"what do you charge","kind":"askprice","act":"","pos":"","out":"talk","installed":"yes"},
  {"id":"M5","group":"money","say":"how many septims","kind":"askprice","act":"","pos":"","out":"talk","installed":"yes"},
  {"id":"M6","group":"money","say":"i'll pay you 200 septims","kind":"offer","act":"","pos":"","out":"talk","installed":"yes"},
  {"id":"M7","group":"money","say":"here's fifty gold","kind":"offer","act":"","pos":"","out":"talk","installed":"yes"},
  {"id":"M8","group":"money","say":"i've got a thousand septims","kind":"offer","act":"","pos":"","out":"talk","installed":"yes"},
  {"id":"M9","group":"money","say":"would you for a hundred septims","kind":"offer","act":"","pos":"","out":"talk","installed":"yes"},
  {"id":"M10","group":"money","say":"i'll pay you well for it","kind":"offer","act":"","pos":"","out":"talk","installed":"yes"},
  {"id":"M11","group":"money","say":"take my purse","kind":"offer","act":"","pos":"","out":"talk","installed":"yes"},
  {"id":"M12","group":"money","say":"money is no object","kind":"offer","act":"","pos":"","out":"talk","installed":"yes"},
  {"id":"M13","group":"money","say":"name it and it's yours","kind":"offer","act":"","pos":"","out":"talk","installed":"yes"},
  {"id":"M14","group":"money","say":"can i pay you for it","kind":"offer","act":"","pos":"","out":"talk","installed":"yes"},
  {"id":"M15","group":"money","say":"that's too much","kind":"haggle","act":"","pos":"","out":"talk","installed":"yes"},
  {"id":"M16","group":"money","say":"meet me in the middle","kind":"haggle","act":"","pos":"","out":"talk","installed":"yes"},
  {"id":"M17","group":"money","say":"come down on your price","kind":"haggle","act":"","pos":"","out":"talk","installed":"yes"},
  {"id":"M18","group":"money","say":"that's a lot of gold","kind":"haggle","act":"","pos":"","out":"talk","installed":"yes"},
  {"id":"M19","group":"money","say":"what if i gave you 500 gold","kind":"offer","act":"","pos":"","out":"talk","installed":"yes"},
  {"id":"M20","group":"money","say":"i'm not paying you","kind":"none","act":"","pos":"","out":"talk","installed":"yes"},
  {"id":"M21","group":"money","say":"you said you'd take 100","kind":"none","act":"","pos":"","out":"talk","installed":"yes"},
  {"id":"M22","group":"money","say":"fifty","kind":"none","act":"","pos":"","out":"talk","installed":"yes"},
  {"id":"M23","group":"money","say":"keep your gold","kind":"none","act":"","pos":"","out":"talk","installed":"yes"},
  {"id":"M24","group":"money","say":"i'll pay you 200 to suck my cock","kind":"offer","act":"","pos":"","out":"talk","installed":"yes"},
  {"id":"M25","group":"money","say":"come down a little","kind":"none","act":"","pos":"","out":"talk","installed":"yes"},
  {"id":"M26","group":"money","say":"here is two hundred septims for the room and the food","kind":"none","act":"","pos":"","out":"talk","installed":"yes"},
  {"id":"M27","group":"money","say":"i will give you thirty gold for the sword","kind":"none","act":"","pos":"","out":"talk","installed":"yes"},
  {"id":"M28","group":"money","say":"how much do you want for it","kind":"none","act":"","pos":"","out":"talk","installed":"yes"},
  {"id":"M29","group":"money","say":"i will pay you fifty septims for the horse","kind":"none","act":"","pos":"","out":"talk","installed":"yes"},
  {"id":"M30","group":"money","say":"i will give you thirty for it, not a coin more","kind":"none","act":"","pos":"","out":"talk","installed":"yes"},
  {"id":"M31","group":"money","say":"and everybody has a price","kind":"askprice","act":"","pos":"","out":"talk","installed":"yes"},
  {"id":"M32","group":"money","say":"if you were to fuck me i'd give you a thousand gold","kind":"offer","act":"","pos":"","out":"talk","installed":"yes"},
  {"id":"M33","group":"money","say":"I'll give you a thousand gold","kind":"offer","act":"","pos":"","out":"talk","installed":"yes"},
  {"id":"M34","group":"money","say":"here's five hundred","kind":"offer","act":"","pos":"","out":"talk","installed":"yes"},
  {"id":"M35","group":"money","say":"five hundred septims, right now","kind":"offer","act":"","pos":"","out":"talk","installed":"yes"},
  {"id":"M36","group":"money","say":"take three hundred and come upstairs","kind":"offer","act":"","pos":"","out":"talk","installed":"yes"},
  {"id":"M37","group":"money","say":"would fifty do?","kind":"offer","act":"","pos":"","out":"talk","installed":"yes"},
  {"id":"M38","group":"money","say":"a thousand gold for a night with you","kind":"offer","act":"","pos":"","out":"talk","installed":"yes"},
  {"id":"M39","group":"money","say":"i'll pay you 1,000 gold","kind":"offer","act":"","pos":"","out":"talk","installed":"yes"},
  {"id":"M40","group":"money","say":"i'll give you 1k","kind":"offer","act":"","pos":"","out":"talk","installed":"yes"},
  {"id":"M41","group":"money","say":"two hundred and fifty septims","kind":"offer","act":"","pos":"","out":"talk","installed":"yes"},
  {"id":"M42","group":"money","say":"everybody's got a price","kind":"askprice","act":"","pos":"","out":"talk","installed":"yes"}
]
LRGJSON, true);

psay('LoreRim Glue - phrase matrix, ' . count($ROWS) . ' player sentences x ' . count($STATES) . ' states');
psay();
if ($tsv) { echo implode("\t", ['id', 'group', 'say', 'want', 'state', 'kind', 'conf', 'act', 'scene', 'route', 'directive', 'net', 'why', 'verdict']) . "\n"; }

$verdicts = [];
$byGroup = [];
$mustFails = [];
$ownerSix = [];
foreach ($ROWS as $r) {
    foreach ($STATES as $sn => $st) {
        $res = phraseRun($r['say'], $st);
        $i = $res['intent'];
        $v = phraseVerdict($r, $st, $res);
        $verdicts[$v] = ($verdicts[$v] ?? 0) + 1;
        $byGroup[$r['group']][$v === 'ok' ? 'ok' : 'miss'] = ($byGroup[$r['group']][$v === 'ok' ? 'ok' : 'miss'] ?? 0) + 1;
        $line = implode("\t", [$r['id'], $r['group'], $r['say'], $r['kind'] . ($r['act'] !== '' ? ':' . $r['act'] : ''), $sn,
            $i['kind'], $i['conf'], ($i['act'] ?? '') ?: '-', $res['scene'] ?: '-', $res['route'],
            phraseShape($res['directive']), $res['net'], substr((string) (($i['why'] ?? '') ?: ($i['cant'] ?? '')), 0, 44), $v]);
        if ($tsv && (!$onlyFails || $v !== 'ok')) { echo $line . "\n"; }
        if ($v !== 'ok') {
            if (in_array($r['group'], PHRASE_MUST_GROUPS, true)) { $mustFails[] = $line; }
            if (!$tsv && !$quiet) { echo "    miss  $line\n"; }
        }
        if ($r['group'] === 'owner pt7') { $ownerSix[$r['say']][$sn] = $v; }
    }
}

if (!$tsv) {
    psay();
    echo "A. the owner's own words (playtest 7) - every state in which they make sense\n";
    foreach ($ownerSix as $say => $states) {
        $bad = array_keys(array_filter($states, fn($v) => $v !== 'ok'));
        pcheck('"' . $say . '"', $bad === [], implode(',', array_map(fn($s) => $s . '=' . $states[$s], $bad)));
    }
    echo "\nB. the rails that must NOT fire, and the clothing rails\n";
    foreach (['must NOT trigger', 'clothes', 'money', 'pt7 log', 'pt7 log F1', 'pt7 log F2', 'pt7 log F4'] as $g) {
        if (!isset($byGroup[$g])) { continue; }
        pcheck("group \"$g\": every row", ($byGroup[$g]['miss'] ?? 0) === 0,
            ($byGroup[$g]['miss'] ?? 0) . ' of ' . array_sum($byGroup[$g]) . ' miss');
    }
    echo "\nC. coverage\n";
    $total = array_sum($verdicts);
    $ok = (int) ($verdicts['ok'] ?? 0);
    $rate = $total > 0 ? $ok / $total : 0.0;
    foreach ($byGroup as $g => $c) {
        printf("  %-22s %3d/%-3d\n", $g, $c['ok'] ?? 0, array_sum($c));
    }
    printf("  verdicts: %s\n", json_encode($verdicts));
    pcheck(sprintf('overall hit rate %.1f%% is at or above the floor of %.0f%%', $rate * 100, PHRASE_FLOOR * 100),
        $rate >= PHRASE_FLOOR, sprintf('%d of %d', $ok, $total));

    // ------------------------------------------------------------------ [0.5.4 / pt13] ESCORT
    // A MUST section of its own, because an escort row expects DIFFERENT things per state: out of a
    // scene with her it is follow / wait / release in EVERY mode (it is not intimacy), inside one the
    // scene's own verbs keep their words ("wait" is a hold, "come with me" a climax). The three first
    // rows are playtest 13's own lines, verbatim.
    echo "\nD. [0.5.4] follow / wait / release - every out-of-scene mode, never inside a scene with her\n";
    $escRows = [
        ['uh i need you to come with me and talk to me in private for a moment', 'follow'],
        ['okay so come on follow me now', 'follow'],
        ['hey are you gonna follow me?', ''],
        ['follow me', 'follow'], ['come with me', 'follow'], ['come along', 'follow'], ['come on', 'follow'],
        ['walk with me', 'follow'], ['come with me somewhere private', 'follow'], ['come with me upstairs', 'follow'],
        ['come with me to my room', 'follow'], ['wait here', 'wait'], ['stay here', 'wait'],
        ['you can go', 'release'], ['you can go back', 'release'],
        ["don't follow me", ''], ['do you want to follow me', ''],
    ];
    // what lrgPrepareTurn does per mode: the full recogniser (with the escort flag) where it already ran,
    // the escort-only recogniser on a silence the game knows the reason for
    $escModes = ['closed' => true, 'public' => true, 'private' => true, 'follow' => true, 'silent-blind' => true, 'silent-known' => false];
    $ectx = ['current' => '', 'live' => [], 'sexes' => PHRASE_SEXES, 'furn' => '', 'tier' => 0, 'player' => 'Dovah', 'npc' => 'Lisette',
        'npos' => 1, 'ceiling' => 4, 'req_ceiling' => 4, 'reached' => 4, 'furn_options' => [], 'acts' => [], 'nearf' => PHRASE_NEARF,
        'acts_done' => [], 'auto_ok' => false, 'escort' => true];
    $GLOBALS['ENABLED_FUNCTIONS'] = ['Talk', 'FollowPlayer'];
    $GLOBALS['LRG_TEST_NPCSTATE'] = ['Lisette' => ['scene' => '1', 'adult' => '1', 'on' => '1', 'mate' => '0', 'fac' => 'JobBardFaction', '_age' => 5]];
    foreach ($escRows as [$say, $want]) {
        $bad = [];
        foreach ($escModes as $mode => $full) {
            $in = $full ? lrgRecogniseIntent($say, $ectx, []) : lrgRecogniseEscort($say);
            $got = $in['kind'] === LRG_INTENT_ESCORT && $in['conf'] === 'high' ? (string) ($in['kv']['do'] ?? '') : '';
            if ($got !== $want) { $bad[] = "$mode=" . ($got ?: $in['kind'] . '/' . $in['why']); continue; }
            if ($want === '') { continue; }
            $turn = ['npc' => 'Lisette', 'cid' => 'x', 'type' => 'inputtext', 'mode' => str_starts_with($mode, 'silent') ? 'silent' : $mode,
                'intent' => $in, 'acts' => [], 'ctx' => $ectx, 'gate' => ['reasons' => ['quest_scene']], 'offered' => [],
                'escort' => lrgEscortFacts('Lisette')];
            $d = lrgBuildRequestDirective($turn);
            $shapeOk = str_contains($d, 'one short line') && str_contains($d, 'This is not intimacy')
                && ($want === 'follow' ? str_contains($d, 'choose Follow_Dovah') : str_contains($d, 'choose no movement action'));
            if (!$shapeOk) { $bad[] = "$mode=directive:" . substr($d, 0, 90); }
        }
        foreach (['B_kiss', 'C_doggy'] as $sn) {
            $res = phraseRun($say, $STATES[$sn]);
            if ((string) $res['intent']['kind'] === LRG_INTENT_ESCORT) { $bad[] = "$sn=escort inside a scene"; }
        }
        pcheck(sprintf('"%s" -> %s', $say, $want === '' ? 'no escort, in every mode' : "escort/$want in every out-of-scene mode, the scene reading inside one"),
            $bad === [], implode(', ', $bad));
    }
    unset($GLOBALS['ENABLED_FUNCTIONS'], $GLOBALS['LRG_TEST_NPCSTATE']);

    // ------------------------------------------------------------------ [0.5.6 / pt15] the owner's rulings A-D
    // Tonight's two log lines verbatim, and what each kind of offer turns into: firm / grey (she asks) /
    // her confirming question answered / a counter against her figure / more than he carries.
    echo "\nE. [pt15] everyone has a price: firm, grey, confirm, counter, unaffordable\n";
    $pend = ['offer_pending' => ['gold' => 1000, 'npc' => 'Lisette', 'at' => lrgNow(), 'expires' => lrgNow() + 120, 'req' => 'earlier', 'state' => 'asked']];
    $q = ['price_quoted' => ['gold' => 225, 'at' => lrgNow()]];
    $eRows = [
        // [say, mem, kind, gold, firm, from]
        ['and everybody has a price', [], 'askprice', 0, false, ''],
        ["if you were to fuck me i'd give you a thousand gold", [], 'offer', 1000, false, 'hypothetical'],
        ["I'll give you a thousand gold", [], 'offer', 1000, true, 'said'],
        ['a thousand gold, right now', [], 'offer', 1000, true, 'said'],
        ['would a thousand do?', [], 'offer', 1000, false, 'hypothetical'],
        ['yes', $pend, 'offer', 1000, true, 'confirm'],
        ['deal', $pend, 'offer', 1000, true, 'confirm'],
        ["that's right", $pend, 'offer', 1000, true, 'confirm'],
        ['a thousand', $pend, 'offer', 1000, true, 'confirm'],
        ['no', $pend, 'withdraw', 0, false, 'pending'],
        ['how about 200', $q, 'offer', 200, false, 'implied'],
        ["I'll give you 250 gold", $q, 'offer', 250, true, 'said'],
    ];
    foreach ($eRows as [$say, $mem, $kind, $gold, $firm, $from]) {
        $in = lrgRecogniseIntent($say, null, $mem);
        $ok = $in['kind'] === $kind && (int) ($in['gold'] ?? 0) === $gold && (string) ($in['from'] ?? '') === $from
            && ($kind !== 'offer' || (!empty($in['firm'])) === $firm);
        pcheck(sprintf('"%s"%s -> %s/%d%s', $say, $mem ? ' [' . implode(',', array_keys($mem)) . ']' : '', $kind, $gold,
            $kind === 'offer' ? ($firm ? ' firm' : ' grey (she asks)') : ''), $ok,
            sprintf('got %s/%d from=%s firm=%s', $in['kind'], (int) ($in['gold'] ?? 0), (string) ($in['from'] ?? ''), !empty($in['firm']) ? 'y' : 'n'));
    }
    // the directive each one gets, against tonight's numbers: floor 225, the player carrying 322
    $mt = ['npc' => 'Lisette', 'cid' => 'x', 'type' => 'inputtext', 'acts' => [], 'ctx' => ['player' => 'Dovah'], 'scene_confirmed' => false];
    $g = static fn(array $paid) => ['reasons' => ['not_close_enough'], 'state' => ['pgold' => '322'],
        'price' => ['for_sale' => true, 'free' => false, 'gold' => 225], 'paid' => $paid];
    $d = lrgBuildRequestDirective(['mode' => 'closed', 'offered' => [], 'gate' => $g(['offer' => 1000, 'firm' => false, 'why' => 'the player cannot afford it (322 < 1000), and not firm']),
        'intent' => lrgRecogniseIntent("if you were to fuck me i'd give you a thousand gold", null, [])] + $mt);
    pcheck('the log line with 322 on him: she calls it out - show the coin first', str_contains($d, 'show the coin first'), $d);
    $d = lrgBuildRequestDirective(['mode' => 'closed', 'offered' => [], 'gate' => $g(['offer' => 300, 'firm' => false, 'confirm' => true, 'why' => 'not firm']),
        'intent' => lrgRecogniseIntent("if you come upstairs i'd give you 300 gold", null, [])] + $mt);
    pcheck('a grey 300 he can pay: one confirming question, nothing started', str_contains($d, 'does he mean 300') && str_contains($d, 'Do not start anything yet'), $d);
    $d = lrgBuildRequestDirective(['mode' => 'private', 'offered' => [LRG_ACT_START], 'gate' => ['reasons' => []] + $g(['offer' => 300, 'firm' => true, 'accepted' => true, 'source' => 'said']),
        'intent' => lrgRecogniseIntent("I'll give you 300 gold", null, [])] + $mt);
    pcheck('a firm 300 he can pay: take it with amount 300 (or haggle upward) - the gold is the trust',
        str_contains($d, 'BeginIntimacy with amount 300') && str_contains($d, 'The gold is the trust'), $d);
    $d = lrgBuildRequestDirective(['mode' => 'closed', 'offered' => [], 'gate' => $g(['offer' => 100, 'firm' => true, 'why' => 'below her floor (100 < 225)']),
        'intent' => lrgRecogniseIntent("I'll give you 100 gold", null, [])] + $mt);
    pcheck('a firm 100: not for 100 - she names 225', str_contains($d, 'not for 100') && str_contains($d, '225 septims'), $d);
    if ($mustFails) {
        echo "\n  rows in a MUST group that missed:\n";
        foreach (array_slice($mustFails, 0, 30) as $l) { echo "    $l\n"; }
    }
    echo "\n$pass passed, $fail failed\n";
}
exit($fail > 0 ? 1 : 0);
