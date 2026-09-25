<?php
/**
 * LoreRim Glue - action catalog rows, game-message handling, per-turn mode and offering, the post-LLM hard gate,
 * and the text injected into the NPC's prompt. Contract: glue/PROTOCOL.md v2 (sections 5, 6, 7.2).
 *
 * CHIM facts this is built on (verified in the 3.3.2 server source):
 *  - actions live in the DB catalog; herikaActionCatalogUpsertCustomRow($row) adds ours. CHIM stores the display
 *    name snake-cased (BeginIntimacy -> Begin_Intimacy, lib/core/action_catalog.php:704-728): the post-gate accepts both
 *  - with the strict JSON schema the LLM can only fill "target", "item", "amount"; "message" may be EMPTY: no TTS,
 *    no filler line, the action still runs (connector/openrouterjson.php:1003-1045, lib/data_functions.php:6004 / 6029)
 *  - ext functions.php runs after ENABLED_FUNCTIONS is loaded and before the final filter (functions/functions.php:2752),
 *    so removing a code from ENABLED_FUNCTIONS hides the action for this turn
 *  - $GLOBALS["action_post_process_fnct_ex"][] closures see the final wire lines
 *    "Actor|channel|Code@param" and may drop or rewrite them before the game sees them (lib/data_functions.php:6036)
 *  - main.php:1078 switches actions off for every request type outside its whitelist; the ext prerequest.php hook
 *    (main.php:1117) runs right after that and may switch them on again; functions.php is loaded later (main.php:1615)
 *  - ext preprocessing.php (main.php:193) runs BEFORE the MAIN semaphore (:243): a request dropped there costs nothing
 *  - processor/funcret.php:131-136: a row with followup.enabled=false ends the funcret request without an LLM call
 *  - [0.5.5] processor/funcret.php: a row with followup.enabled=true AND a non-empty followup.prompt makes CHIM run ONE
 *    LLM turn after the result, with $PROMPTS['afterfunc']['cue'][<code>] (processor/request.php) as its last user
 *    line - the only "funcret turn" CHIM supports, and CHIM decides it per ROW. The glue needs it per RESULT (owner
 *    addendum 11: a failure is spoken, a success stays silent), so every glue row has the follow-up ON and the glue
 *    ends every result that must stay silent itself, in preprocessing, BEFORE the MAIN lock (lrgFuncretVerdict).
 *    suppress_placeholder_infoaction stays on: it only drops the "issued ACTION ..." placeholder line from her event
 *    log (funcret.php chimLogFuncretResultInfoAction), never the follow-up turn.
 */

require_once __DIR__ . '/lrg_core.php';
require_once __DIR__ . '/lrg_scene_index.php';
require_once __DIR__ . '/lrg_intent.php';
require_once __DIR__ . '/lrg_latency.php'; // [0.4.2 / PT9] when an unsolicited turn of hers may cost the player a wait
require_once __DIR__ . '/lrg_market.php';  // [pt19-purchase] ExtCmdLRG_Buy: the carrier row, the funcret verdict, her words for its refusals

// [0.4] 10: BeginIntimacy gained the `amount` property + parameter_template (paid intimacy)
// [0.5.5] 11: the follow-up is ON with a prompt on every row (a failure is VOICED - owner addendum 11), plus the
// inactive ExtCmdLRG_Escort row, which exists only so CHIM can voice the escort's refusals (it is never offered)
const LRG_ACTIONS_VERSION = 11;
// [pt18-quest] the click-free quest entry's code (its home is lrg_core.php beside LRG_ACT_ESCORT; lib/lrg_factions.php
// defines it the same way for the fast path, where this file is not loaded). Guarded so both definitions agree.
if (!defined('LRG_ACT_QUESTENTRY')) { define('LRG_ACT_QUESTENTRY', 'ExtCmdLRG_QuestEntry'); }
const LRG_GLUE_ACTIONS = [LRG_ACT_START, LRG_ACT_CONTROL, LRG_ACT_CLOTHING, LRG_ACT_INVITE, LRG_ACT_REQUESTACT];
/**
 * [0.5.5] Every row the glue keeps in CHIM's catalog: the five offered ones and the inactive escort carrier.
 * [pt18-quest] + the inactive quest-entry carrier. LRG_ACTIONS_VERSION stays 11 on purpose: lrgEnsureActions()
 * reinstalls whenever a row of this list is missing from the catalog (the marker alone is not proof), so the new row
 * reaches an existing install without a bump that would also overwrite rows the owner may have edited.
 */
// [pt19-purchase] + the inactive buy carrier (ExtCmdLRG_Buy, lib/lrg_market.php) - the same missing-row path, no version bump.
const LRG_CATALOG_ROWS = [LRG_ACT_START, LRG_ACT_CONTROL, LRG_ACT_CLOTHING, LRG_ACT_INVITE, LRG_ACT_REQUESTACT, LRG_ACT_ESCORT, LRG_ACT_QUESTENTRY, LRG_ACT_BUY];
/**
 * [pt18-quest] The quest-entry row's own follow-up prompt: unlike every other glue row its funcret turn may be a
 * SUCCESS (the game recorded the stage and nobody has said so yet) as well as a refusal, so the prompt names neither.
 */
const LRG_QUESTENTRY_FOLLOWUP_PROMPT = 'The game has just answered what the player asked for. Say the outcome in one short spoken line in your own voice - exactly what the game recorded or refused, nothing more, and never pretend more happened.';
/**
 * [0.5.5] The follow-up prompt of every glue row. CHIM prefixes it to the cue of the funcret turn
 * ("(<prompt>) <cue>", processor/funcret.php chimBuildMetadataFollowupRequest). It is only ever read on a
 * VOICED result: every result that must stay silent is ended in preprocessing, before CHIM gets that far.
 * The specific instruction for the turn is the cue itself (lrgVoicedCue, set from prompts.php).
 */
const LRG_FOLLOWUP_PROMPT = 'What was just asked for did not happen. Say so in one short spoken line in your own voice, with the real reason - never ignore it and never pretend it happened.';
const LRG_ROMANTIC_MODES = ['public', 'private', 'follow', 'scene'];

/** [player sex, npc sex] (0 male / 1 female) from a game snapshot, or null = do not filter scenes by sex. */
function lrgSexes(?array $snap): ?array
{
    $p = (string) ($snap['psex'] ?? ''); $n = (string) ($snap['sex'] ?? '');
    return (in_array($p, ['0', '1'], true) && in_array($n, ['0', '1'], true)) ? [$p, $n] : null;
}

/** csv -> clean lowercase list. */
function lrgCsv($csv): array
{
    return array_values(array_filter(array_map(static fn($x) => strtolower(trim((string) $x)), explode(',', (string) $csv)), 'strlen'));
}

/**
 * [0.5.0 / E7(a)] The one precondition every glue action honestly has, in CHIM's own vocabulary:
 * nothing the glue does can be done with a corpse, an unconscious or sleeping actor, or mid-combat.
 * Spelt exactly as CHIM spells it for HireCarriage / HireFerry
 * (herikaActionCatalogGetBuiltinRequirements(), lib/core/action_catalog.php:1145-1232).
 * [0.5.0 fix pass / S-11] The docstring used to name RentRoom as well, and that was wrong: RentRoom's
 * builtin requirements are ['dead','unconscious','sleeping'] with no 'combat' / 'attacking' at all
 * (action_catalog.php:1148-1152). Only the travel pair carries the full five. The glue keeps the
 * stricter five-element list on purpose - nothing it does belongs in a fight - so only the sentence
 * was wrong, never the list.
 */
const LRG_ACT_ALIVE_REQ = ['activity' => ['current_action_not_in' =>
    ['dead', 'unconscious', 'sleeping', 'combat', 'attacking']]];

/** Do the installed rows predate the requirements block? Costs no query (the row cache answers). */
function lrgActionsNeedRequirements(): bool
{
    if (!function_exists('herikaGetActionCatalogRow')) { return false; }
    foreach (LRG_GLUE_ACTIONS as $code) {
        $row = herikaGetActionCatalogRow($code);
        if (!is_array($row)) { continue; }
        $md = $row['metadata'] ?? [];
        if (is_string($md)) { $md = json_decode($md, true) ?: []; }
        $md = (array) $md;
        $req = (array) ($md['requirements'] ?? []);
        $act = (array) ($req['activity'] ?? []);
        if (empty($act['current_action_not_in'])) { return true; }
    }
    return false;
}

function lrgEnsureActions(): void
{
    $marker = LRG_DIR . '/data/.actions_v' . LRG_ACTIONS_VERSION;
    if (!function_exists('herikaActionCatalogUpsertCustomRow')) { return; }
    if (is_file($marker)) {
        // the marker alone is not proof: a row deleted in the web Action Editor must come back.
        // herikaGetActionCatalogRow reads the per-request row cache, so this costs no query.
        if (!function_exists('herikaGetActionCatalogRow')) { return; }
        $missing = array_filter(LRG_CATALOG_ROWS, static fn($c) => !is_array(herikaGetActionCatalogRow($c)));
        // [0.5.0 / E7(a)] a row installed by an older glue carries no metadata.requirements.activity.
        // Reinstalling on that condition is how the new preconditions reach an existing install without
        // bumping LRG_ACTIONS_VERSION (which would also reinstall rows the owner may have edited).
        if (!$missing && !lrgActionsNeedRequirements()) { return; }
        lrgLog('action catalog: ' . ($missing ? 'a glue row is missing' : 'the rows predate CHIM requirements')
            . ' - reinstalling');
    }
    $speech = LRG_PLAYER_SPEECH_TYPES;
    $inScene = array_merge($speech, ['lrg_scenetalk']);   // the scene-lead tick may act too (offered by lrgPrepareTurn only while a scene runs)
    $ownMove = array_merge($speech, ['lrg_initiative']);   // her own move (admitted by lrgInitiativeAdmit before the MAIN lock)
    $meta = fn(string $arg, array $types) => [
        'dispatch' => 'plugin_command',
        'source' => 'LoreRimGlue',
        'bridge_script' => 'LRG',
        'bridge_entrypoint' => 'DispatchExternalCommand',
        // [0.5.0 / E7(a)] CONDITION TRUTHFULNESS through CHIM'S OWN MACHINERY. `requirements` is
        // evaluated by herikaActionCatalogRequirementsMatch() (lib/core/action_catalog.php:1834) and
        // applied for real at functions/functions.php:2745, so an action whose preconditions are false
        // never enters the function schema and the model cannot choose it. Our per-turn filter stays
        // PRIMARY - CHIM's vocabulary cannot express "a dialogue session with this NPC is open" - but
        // this second, independent gate catches the turns our filter never sees.
        'requirements' => ['request_types_any' => $types] + LRG_ACT_ALIVE_REQ,
        'confirmation' => ['default_policy' => 'automatic'],
        'suppress_placeholder_infoaction' => true, // funcret.php:62 - keep pace/position/clothing changes and errors out of CHIM's event log and memory
        // [0.5.5 / owner addendum 11] THE FOLLOW-UP IS ON, AND THE GLUE DECIDES IT PER RESULT. CHIM only has a per-row
        // switch, so every result that must stay silent (a success, her own scene-lead move, a dry run, a second
        // failure within voice.min_gap_seconds, the kill switch) is ended in preprocessing BEFORE the MAIN lock -
        // cheaper than 0.5.4, which passed them on to wait for the lock and be ended in funcret.php. Only a failure
        // she has to answer reaches processor/funcret.php, which then runs its one follow-up turn.
        // use_functions_again stays false: a voiced failure can say why, it can never act.
        'followup' => ['enabled' => true, 'arg_name' => $arg, 'use_functions_again' => false, 'prompt' => LRG_FOLLOWUP_PROMPT],
    ];
    $common = ['return_message' => '', 'available_to_npc' => 1, 'available_to_followers' => 1, 'available_to_narrator' => 0,
        'is_activated' => 1, 'game_function' => 1, 'import_version' => LRG_ACTIONS_VERSION];
    $item = fn(string $d, bool $required) => ['type' => 'object', 'properties' => ['item' => ['type' => 'string', 'description' => $d]], 'required' => $required ? ['item'] : []];
    $rows = [
        [
            'code_name' => LRG_ACT_START,
            'action_name' => 'BeginIntimacy',
            // [0.3 / G3a] The "it can simply happen with an empty message" licence is GONE: a scene that
            // begins out of nowhere was the owner's complaint after playtest 6.
            'description' => 'Begin physical intimacy with the player; it always starts gently (a kiss, an embrace). In the SAME reply, first say one short line that makes plain what you are about to do - never an empty message. Choose it only because you want it right now, never to be polite, under pressure, or because you were asked twice.',
            /*
             * [0.4 / OWNER ADDENDA 6] `amount` is how the agreed price reaches the glue, and it is the
             * one fact the whole paid-intimacy feature stands on. Verified in the installed 3.3.2
             * server: functions.php:2542 builds the wire line as actor|channel|CODE@ .
             * $executionContext["parameter_string"]; that string comes from
             * buildFunctionExecutionParameter() (:2563) -> buildConfiguredActionParameterFromMetadata()
             * (:137-184), which resolves metadata.parameter_template against a context whose
             * `parameters` key IS the decoded JSON the LLM produced, and
             * herikaActionCatalogResolveTemplateString() (lib/core/action_catalog.php:3520-3541) returns
             * the value itself for a whole-string {{parameters.amount}}.
             * `required` MUST stay empty: queueFunctionExecutionCommand() (:2526-2529) drops the whole
             * action when a row has required parameters and one came back empty, which would kill every
             * ordinary free scene. An empty amount simply resolves to '' and reads as 0 = free, and the
             * post-gate also accepts a bare integer or an `amount=` k=v, so a catalog row installed by
             * an older version degrades to 0 instead of misreading the target's name as a figure.
             */
            'parameters_json' => ['type' => 'object', 'properties' => [
                'target' => ['type' => 'string', 'description' => 'The player'],
                'amount' => ['type' => 'integer', 'description' => 'ONLY when accepting an offer of gold: the number of septims the PLAYER has agreed to pay. Leave it out otherwise.'],
            ], 'required' => []], // not required: the core silently drops an action whose required field came back empty, and the target is always the player anyway
            'metadata' => $meta('target', $ownMove) + ['parameter_template' => '{{parameters.amount}}'],
        ],
        [
            // [0.3 / G5.2] demoted to the VERB action: positions now travel as acts through RequestAct
            'code_name' => LRG_ACT_CONTROL,
            'action_name' => 'ChangeIntimacy',
            'description' => 'During an intimate scene only: change the pace, hold back or release, climax, move to furniture, hand over who leads, wind down or stop. "item": exactly one key from the current scene notes.',
            'parameters_json' => $item('One key from the current scene notes (faster, slower, hold, release, climax, wind down, stop, a furniture word, a name followed by "leads", auto)', true),
            'metadata' => $meta('item', $inScene),
        ],
        [
            // [0.3 / G5] one act key, with its role: the stable handle that replaces the per-turn P<n>
            'code_name' => LRG_ACT_REQUESTACT,
            'action_name' => 'RequestAct',
            'description' => 'During an intimate scene only: move to a different act or position. "item": exactly one act key from the current scene notes, nothing else. An act key may name a position after a slash, exactly as the notes write it.',
            'parameters_json' => $item('One act key from the current scene notes, exactly as written there (a key may carry a position after a slash)', true),
            'metadata' => $meta('item', $inScene),
        ],
        [
            'code_name' => LRG_ACT_CLOTHING,
            // [0.3] split in two: what the player asks for is done; what nobody asked for is her own choice
            'action_name' => 'ChangeClothing',
            'description' => 'Take clothes off or put them back on. When the player asks for clothes off or on, do it. When nobody asked, only because you yourself want to. "item": undress or dress, optionally followed by both or you, optionally by one part (body, head, hands, feet).',
            'parameters_json' => $item('undress or dress; optionally + both / you; optionally + body, head, hands or feet', true),
            'metadata' => $meta('item', array_merge($inScene, ['lrg_initiative'])),
        ],
        [
            'code_name' => LRG_ACT_INVITE,
            'action_name' => 'SuggestPrivacy',
            'description' => 'Choose this in the same reply in which you tell the player, in your own words, that the two of you should go somewhere private. "item": one of the places named in this moment\'s notes (home, room, quiet).',
            'parameters_json' => $item('home, room or quiet - only a place named in this moment\'s notes', false),
            'metadata' => $meta('item', $ownMove),
        ],
        [
            // [0.5.5] NEVER OFFERED: is_activated 0, available to nobody, and a request type that never occurs.
            // The escort line is built by the server (lrgEscortNet). This row exists only because CHIM resolves a
            // funcret's follow-up from the catalog row of its code: without one, a refusal of the escort ("she is in
            // the middle of something she cannot leave") could never be spoken. A line of it FROM the model is
            // still dropped by the post-LLM gate as "not offered".
            'code_name' => LRG_ACT_ESCORT,
            'action_name' => 'GlueEscort',
            'description' => 'LoreRim Glue internal: carries follow / wait / release to the game. Sent by the server only - keep it switched off.',
            'parameters_json' => $item('follow, wait or release', false),
            'metadata' => $meta('item', ['lrg_never']),
            'is_activated' => 0, 'available_to_npc' => 0, 'available_to_followers' => 0,
        ],
        [
            // [pt18-quest] NEVER OFFERED, like the escort: the line is built by the server (lrgFacQuestNet, lib/lrg_factions.php)
            // when a scripted join line's stage may be applied without a click. The row exists so CHIM can voice the game's
            // answer - a refusal ("Helgen first"), or the SUCCESS when no licence was given pre-LLM (lrgQuestEntryResult).
            'code_name' => LRG_ACT_QUESTENTRY,
            'action_name' => 'GlueQuestEntry',
            'description' => 'LoreRim Glue internal: applies the quest stage of a scripted join line the player chose by voice. Sent by the server only - keep it switched off.',
            'parameters_json' => $item('the quest and stage', false),
            'metadata' => array_replace_recursive($meta('item', ['lrg_never']), ['followup' => ['prompt' => LRG_QUESTENTRY_FOLLOWUP_PROMPT]]),
            'is_activated' => 0, 'available_to_npc' => 0, 'available_to_followers' => 0,
        ],
        [
            // [pt19-purchase] NEVER OFFERED, like the escort and the quest entry: the line is built by the server (lrgMktNet,
            // lib/lrg_market.php) when the player ordered food or drink a vendor really stocks. The row exists so CHIM can
            // voice the game's refusal ("it is 39 septims now, not 19", "he cannot pay", "she is out of it"); an OK stays quiet.
            'code_name' => LRG_ACT_BUY,
            'action_name' => 'GlueBuy',
            'description' => 'LoreRim Glue internal: hands over food or drink the player ordered by voice, at the price the game asks. Sent by the server only - keep it switched off.',
            'parameters_json' => $item('the item and the count', false),
            'metadata' => array_replace_recursive($meta('item', ['lrg_never']), ['followup' => ['prompt' => LRG_BUY_FOLLOWUP_PROMPT]]),
            'is_activated' => 0, 'available_to_npc' => 0, 'available_to_followers' => 0,
        ],
    ];
    foreach ($rows as $row) {
        // `+` keeps the row's own keys: the escort row's is_activated / available_* = 0 win over $common
        if (!herikaActionCatalogUpsertCustomRow($row + $common)) { lrgLog('action catalog: upsert failed for ' . $row['code_name']); return; }
    }
    if (function_exists('herikaActionCatalogResetCache')) { herikaActionCatalogResetCache(); }
    @mkdir(LRG_DIR . '/data', 0770, true);
    @file_put_contents($marker, date('c'));
    lrgLog('action catalog: rows installed (v' . LRG_ACTIONS_VERSION . ')');
}

/** Remove codes from this turn's offer. The core's final filter then prunes FUNCTIONS. */
function lrgHideActions(array $codes): void
{
    $drop = array_map('strtolower', $codes);
    $GLOBALS['ENABLED_FUNCTIONS'] = array_values(array_filter(
        $GLOBALS['ENABLED_FUNCTIONS'] ?? [],
        static fn($c) => !in_array(strtolower((string) $c), $drop, true)
    ));
}

/** Keep ONLY these codes in this turn's offer (scene-lead tick, initiative tick). */
function lrgKeepOnlyActions(array $codes): void
{
    $keep = array_map('strtolower', $codes);
    $GLOBALS['ENABLED_FUNCTIONS'] = array_values(array_filter(
        $GLOBALS['ENABLED_FUNCTIONS'] ?? [],
        static fn($c) => in_array(strtolower((string) $c), $keep, true)
    ));
}

function lrgIsOffered(string $code): bool
{
    return in_array(strtolower($code), array_map(static fn($c) => strtolower((string) $c), $GLOBALS['ENABLED_FUNCTIONS'] ?? []), true);
}

/** CHIM actions that move, re-task or animate an NPC: never while that NPC is in a scene (code names from core_action_seed.sql). */
const LRG_MOVEMENT_ACTIONS = ['Follow', 'FollowPlayer', 'MakeFollower', 'MoveTo', 'TravelTo', 'TravelToRaw', 'LeadTheWayTo', 'ComeCloser', 'ReturnBackHome',
    'TakeASeat', 'GoToSleep', 'WaitHere', 'Relax', 'StopWalk', 'Attack', 'Brawl', 'CombatPlayer', 'Surrender', 'Sandbox', 'EndConversation', 'PlayIdle', 'CommandAnimation',
    'IncreaseWalkSpeed', 'DecreaseWalkSpeed', 'HireCarriage', 'HireFerry', 'RentRoom', 'TradeItems', 'OpenInventory', 'OpenInventory2', 'PickupItem', 'LookAround',
    'Inspect', 'InspectSurroundings', 'CastSpell', 'SheatheWeapon', 'Toast', 'Drink', 'Consume', 'StartRitualCeremony', 'EndRitualCeremony', 'Training', 'UseSoulGaze'];

/** True when this request is the game's scene-lead tick (protocol 1.4): type lrg_scenetalk, text "lead". */
function lrgIsLeadTick(): bool
{
    return strtolower((string) ($GLOBALS['gameRequest'][0] ?? '')) === 'lrg_scenetalk' && lrgIsTickText((string) ($GLOBALS['gameRequest'][3] ?? ''), 'lead');
}

/** The game's tick texts are exactly "lead" / "approach"; a "Name:" prefix added on the way is tolerated. */
function lrgIsTickText(string $text, string $word): bool
{
    $t = strtolower(trim(lrgStripContext($text), " \t\r\n\"'.()"));
    return (bool) preg_match('/^(?:[^:]{1,40}:\s*)?' . preg_quote($word, '/') . '$/', $t);
}

/** A lead tick only counts while the NPC is the one driving: not under player lead, OStim auto mode or a wind-down. */
function lrgLeadAllowed(array $scene): bool
{
    $leader = strtolower((string) ($scene['leader'] ?? 'npc'));
    return ($leader === '' || $leader === 'npc') && ($scene['auto'] ?? '0') !== '1' && ($scene['wd'] ?? '0') !== '1';
}

// ---------------------------------------------------------------- game messages (ext preprocessing.php, main.php:193)
/**
 * 'handled' = the caller terminates (state messages, log lines, a dropped initiative tick);
 * 'pass' = the request continues into CHIM's pipeline (a funcret is recorded first, then passed).
 */
function lrgHandleGameMessage(array $gameRequest): string
{
    $type = strtolower((string) ($gameRequest[0] ?? ''));
    $text = (string) ($gameRequest[3] ?? '');

    if ($type === 'lrg_log') {
        // written even when the feature is switched off in the config: diagnostics must not depend on it
        $raw = lrgStripContext($text);
        $cid = preg_match('/(?:^|;)\s*cid=([^;]*)/', $raw, $m) ? (string) preg_replace('/[^A-Za-z0-9_-]/', '', $m[1]) : '';
        $pos = strpos($raw, 'msg=');
        $msg = $pos === false ? $raw : substr($raw, $pos + 4); // msg comes last and may itself contain ; and =
        lrgLog('GAME ' . substr(trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $msg)), 0, 600), substr($cid, 0, 24));
        // [0.5.5] the escort's LATE failure: the funcret already said "comes with you", then her quest
        // started the scene again (twice) and the game left her in it. The game only logs that, so this
        // is the one place the server can hear it - told on her next player turn (lrgMissedNote).
        if (lrgEnabled() && !defined('LRG_SHARMAT_PRESENT')) { lrgNoteEscortLate(trim((string) $msg), substr($cid, 0, 24)); }
        return 'handled';
    }
    if ($type === 'lrg_npcstate' || $type === 'lrg_scene') {
        if (lrgEnabled()) {
            lrgEnsureSchema();
            $kv = lrgParseKv(lrgStripContext($text));
            // the payload carries the name itself: HERIKA_NAME is not reliably resolved this early
            $npc = (string) ($kv['npc'] ?? ($GLOBALS['HERIKA_NAME'] ?? ''));
            if ($type === 'lrg_npcstate') {
                lrgStoreNpcState($npc, $kv);
            } else {
                lrgHandleSceneMessage($npc, $kv, $gameRequest);
            }
        }
        return 'handled';
    }
    if ($type === 'funcret') {
        unset($GLOBALS['LRG_VOICED'], $GLOBALS['LRG_FUNCRET']);
        lrgRecordResult($text);
        // [0.5.5 / owner addendum 11] NEVER SILENT. Every glue result is decided HERE, before the MAIN lock:
        // a result that must stay silent ends now ('handled'), a failure she has to answer passes on to
        // CHIM's funcret processor, whose follow-up turn (on for every glue row since actions v11) is
        // where she says why. See lrgFuncretVerdict().
        return lrgFuncretVerdict($text);
    }
    // [0.3 / G2] The player's own turn, still BEFORE the MAIN semaphore: write the hold clock so her
    // autopilot stops for lead.hold_seconds, and log what was heard. The request itself is passed
    // through untouched - narrator turns included, where FUNCTIONS_ARE_ENABLED is false and the reply
    // belongs to another character, so there is nothing the server could safely do with it (the real
    // fix for that is game side). This must NEVER return 'handled': terminate() would swallow the turn.
    if (in_array($type, LRG_PLAYER_SPEECH_TYPES, true) || in_array($type, LRG_NARRATOR_SPEECH_TYPES, true)) {
        if (lrgEnabled() && !defined('LRG_SHARMAT_PRESENT')) {
            lrgEnsureSchema();
            lrgNotePlayerSpeech($type, $text);
        }
        return 'pass';
    }
    // [0.3 / G2] Her lead tick is dropped here, before the lock, while the player is steering: zero LLM
    // call, zero TTS, zero tokens.
    // [0.4.2 / PT9] ... and so is an ORDINARY scene-talk tick that would land on top of a player line or
    // follow her last spoken turn too closely. Measured: those ticks were 74 % of the glue's whole
    // latency cost (7.9 s median, 18.9 s p90 of MAIN-held LLM + TTS, 0.14 per player line), and the game
    // already decides WHETHER to ask - this only decides whether asking is free of charge to the player.
    // The outro (text "outro") is never gated: one closing line per scene, with the NPC held still for it.
    if ($type === 'lrg_scenetalk' && lrgEnabled() && !defined('LRG_SHARMAT_PRESENT') && !lrgIsTickText($text, 'outro')) {
        lrgEnsureSchema();
        $npc = (string) (lrgGetActiveScene()['_npc'] ?? '');
        if (lrgIsTickText($text, 'lead')) {
            $drop = lrgLeadHoldLeft() ?: lrgTickBusyReason('lead');
            if ($drop !== '') {
                lrgLog('lead tick dropped: ' . $drop);
                return 'handled';
            }
        } else {
            $drop = lrgSceneTalkHoldLeft($text, $npc);
            if ($drop !== '') {
                lrgLog('scene talk dropped: ' . $drop);
                return 'handled';
            }
        }
        // [0.5.0 PT9 reconciliation] The interval clock is NOT armed here any more. Admission is not a
        // spoken turn: prompts.php still has to find a live scene, a fresh adult snapshot and the
        // owner's switch on before it defines a cue at all, and arming the clock at admission made a
        // tick that was admitted and then refused downstream block the next 45 s for nothing.
        // It is armed in lrgPrepareTurn() instead - the last point the glue controls on a scene-talk
        // turn, and the point at which the cue really is going to the model.
        // (research/pt9-latency-verify.md D10.)
    }
    unset($GLOBALS['LRG_INITIATIVE']);
    if ($type === 'lrg_initiative') {
        $npc = lrgResolveRequestNpc();
        $adm = lrgInitiativeAdmit($npc, $text);
        if (!$adm['admit']) {
            if (!empty(lrgConfig()['initiative']['log_drops'])) { lrgLog("initiative npc=$npc dropped: " . $adm['why']); }
            return 'handled'; // dropped before the MAIN lock: no LLM call, no TTS
        }
        $GLOBALS['LRG_INITIATIVE'] = ['npc' => $npc, 'kind' => $adm['kind']];
        lrgLog("initiative npc=$npc admitted (" . $adm['kind'] . ', interest ' . $adm['word'] . ')');
        return 'pass';
    }
    return 'pass';
}

/**
 * [0.3 / G2] Pre-lock bookkeeping for one player utterance. Two clocks, deliberately:
 *   player_request_at  the player ASKED for something (an actionable kind, or a yes / no to her
 *                      proposal) -> she keeps out of the way for lead.hold_seconds
 *   player_spoke_at    the player merely spoke -> a much shorter hold (lead.speech_hold_seconds)
 * One clock for both would mean that at playtest-6 cadence (one utterance every ~50 s) her lead tick
 * would never fire again for the whole scene - the owner's older complaint, re-created by construction.
 * Keyed on the SCENE's NPC, not on the request's, so a narrator-routed utterance still stops her.
 */
function lrgNotePlayerSpeech(string $type, string $text): void
{
    $scene = lrgGetActiveScene();
    if ($scene === null) {
        // [0.4.2 / PT9, made lazy in the 0.5.0 reconciliation] Outside a scene there is no lead tick to
        // hold back, but there IS an initiative tick, and hers must not be spoken on top of a
        // conversation that is already running. Still before the MAIN lock; nothing is recognised,
        // logged or decided here. (Inert under the offline harness's simulated clock - lrgLatencyLive().)
        //
        // LAZY, because the first version was not "one small write": it resolved the NPC (a
        // core_npc_master SELECT when ?profile= is set), read lrg_memory and then UPSERT, so it created
        // a row for EVERY NPC the player ever spoke to - a table that grows with the population of
        // Skyrim to protect a rail that can only ever matter for an NPC the glue already tracks.
        // (research/pt9-latency-verify.md D11.) Now: the two free refusals first, and the clock is only
        // ever UPDATED, never inserted. lrgInitiativeAdmit() writes initiative_at the first time it
        // admits a tick for an NPC, so from her first own move onwards the clock is kept for her; the
        // only thing laziness costs is the quiet-window check on that very first tick.
        if (!lrgLatencyLive() || in_array($type, LRG_NARRATOR_SPEECH_TYPES, true)) { return; }
        $ini = lrgLatencyCfg('initiative');
        if ((int) ($ini['player_quiet_seconds'] ?? 0) <= 0 || !((lrgConfig()['initiative'] ?? [])['enabled'] ?? true)) { return; }
        $who = lrgResolveRequestNpc();
        if ($who === '' || lrgMemGet($who) === []) { return; } // no row yet: nothing to keep a clock on
        lrgMemSet($who, ['player_spoke_at' => lrgNow()]);
        return;
    }
    $npc = (string) $scene['_npc'];
    $mem = lrgMemGet($npc);
    $intent = lrgRecogniseIntent($text, null, $mem, true);
    $actionable = in_array($intent['kind'], LRG_INTENT_ACTIONABLE, true) || in_array($intent['kind'], ['yes', 'no'], true);
    if ($intent['kind'] === 'lead_npc') {
        // "you lead" must let her lead at the NEXT tick, not block her for the whole hold
        lrgMemSet($npc, ['player_request_at' => null, 'player_spoke_at' => null]);
    } elseif ($actionable) {
        lrgMemSet($npc, ['player_request_at' => lrgNow()]);
    } else {
        lrgMemSet($npc, ['player_spoke_at' => lrgNow()]);
    }
    if ($intent['text'] === '' && ($intent['why'] ?? '') === 'empty transcript') {
        lrgLog("WARN empty transcript (npc=$npc type=$type): the player spoke and nothing arrived");
    }
    if (in_array($type, LRG_NARRATOR_SPEECH_TYPES, true)) {
        lrgLog("WARN player speech routed to the Narrator while a scene runs (npc=$npc): \"" . $intent['text'] . '"');
    }
    lrgLog(sprintf('heard npc=%s type=%s say="%s" intent=%s/%s conf=%s why=%s', $npc, $type, $intent['text'],
        $intent['kind'], $intent['act'] !== '' ? $intent['act'] : ($intent['kv']['who'] ?? ($intent['kv']['do'] ?? '-')),
        $intent['conf'], $intent['why'] !== '' ? $intent['why'] : '-'));
}

/**
 * [0.3 / G2] Why her lead tick must not fire right now, '' = it may. Two holds and the pending proposal.
 * Called pre-lock, so a dropped tick costs nothing at all.
 */
function lrgLeadHoldLeft(): string
{
    $scene = lrgGetActiveScene();
    if ($scene === null) { return ''; }
    $cfg = (array) (lrgConfig()['lead'] ?? []);
    $mem = lrgMemGet((string) $scene['_npc']);
    $now = lrgNow();
    $until = max((int) ($mem['player_request_at'] ?? 0) + (int) ($cfg['hold_seconds'] ?? 150),
        (int) ($mem['player_spoke_at'] ?? 0) + (int) ($cfg['speech_hold_seconds'] ?? 45));
    if ($until > $now) { return 'the player is steering, ' . ($until - $now) . 's left'; }
    $prop = is_array($mem['proposal'] ?? null) ? $mem['proposal'] : null;
    if ($prop !== null && $now <= (int) ($prop['expires'] ?? 0)) { return 'a proposal is pending (' . (string) ($prop['act'] ?? '?') . ')'; }
    return '';
}

/** lrg_scene: store, log, and let ONLY the beginning and the end reach CHIM's event stream - one neutral line each. */
function lrgHandleSceneMessage(string $npc, array $kv, array $gameRequest): void
{
    // [0.3.1 / R3-O3] FOLD THE CASE ON ARRIVAL. The wire delivers capitalised values in production -
    // 26 "scene Change", 5 "Start", 4 "Climax", 35 "leader=NPC" against 4 lowercase "scene end" over
    // the whole playtest-7 log - while every test in this file compares against lowercase literals.
    // The game source and the installed .pex are lowercase, so CHIM's DLL transforms values built at
    // runtime from a variable and leaves single string literals alone (which is why only ev=end, built
    // as one literal in FinishThread, arrived intact). The consequences were total: _visited and
    // _acts_done stayed empty, _climaxes stayed 0, the landing notes never fired once, the scene-start
    // line never fired, and "<npc> led" was printed for every scene. One line fixes all of it, and it
    // is safe whatever the real cause is.
    foreach (['ev', 'leader', 'how'] as $k) {
        if (isset($kv[$k])) { $kv[$k] = strtolower(trim((string) $kv[$k])); }
    }
    $ev = (string) ($kv['ev'] ?? '');
    // a start the player cancelled while OStim was still building never became a scene: the game
    // still reports its end, and that must not write "their intimate time comes to an end" into
    // CHIM's event stream, memory and diary, nor count as a scene
    $wasOpen = $ev !== 'end' || lrgSceneWasOpen($npc, (string) ($kv['cid'] ?? ''));
    $prev = lrgSceneRowPayload($npc);
    $kv = lrgTrackVisited($npc, $kv, $prev);
    // [0.3] sync=1 on an ev=change is the game's first push after a load (PROTOCOL 1.2): it only forces
    // the session stamp and skips nothing else. There is no new ev to handle - an old server would have
    // mistaken one for an open scene, which is exactly the reload bug this round fixes.
    $sync = ($kv['sync'] ?? '') === '1';
    lrgStoreScene($npc, $kv);
    lrgLog("scene $ev npc=$npc scene=" . ($kv['scene'] ?? '') . ' speed=' . ($kv['speed'] ?? '') . (($kv['wd'] ?? '') === '1' ? ' wd=1' : '')
        . (isset($kv['leader']) ? ' leader=' . $kv['leader'] : '') . ($sync ? ' sync=1' : ''), (string) ($kv['cid'] ?? ''));
    // [0.4] `paid=` is the game-CONFIRMED truth: the gold that really left the purse at the start of
    // THIS scene. Everything about paid intimacy after the start is built on it and never on the pay=
    // the server sent, because the game re-checks the purse a moment before the scene really begins.
    $paidGold = max(0, (int) ($kv['paid'] ?? ($prev['paid'] ?? 0)));
    if ($ev === 'start') {
        // the offer and her last quote are SPENT the moment a scene begins: neither may ever be
        // charged twice, and a second scene later in the evening is priced from scratch
        lrgMemSet($npc, ['lead_idle' => null, 'proposal' => null, 'say_first' => null, 'player_request_at' => null,
            'player_spoke_at' => null, 'paid_offer' => null, 'price_quoted' => null, 'offer_pending' => null]);
        if ($paidGold > 0 && !empty(lrgConfig()['paid_intimacy']['remember_price'])) {
            $was = lrgMemGet($npc)['paid'] ?? null;
            $times = 1 + (is_array($was) ? (int) ($was['times'] ?? 0) : 0);
            lrgMemSet($npc, ['paid' => ['gold' => $paidGold, 'at' => lrgNow(), 'times' => $times]]);
            lrgLog("scene start: the player paid $npc $paidGold septims for this (arrangement no. $times)", (string) ($kv['cid'] ?? ''));
        }
    }
    if ($ev === 'end') {
        // an invitation is used up once a scene with her has ended; heat starts again from zero
        lrgMemSet($npc, ['invite' => null, 'lead_idle' => null, 'proposal' => null, 'say_first' => null,
            'last_scene_end_at' => lrgNow(), 'heat' => null, 'heat_at' => null, 'paid_offer' => null, 'price_quoted' => null,
            'offer_pending' => null, 'player_request_at' => null, 'player_spoke_at' => null, 'hold_sent_at' => null]);
        if ($wasOpen) {
            // [0.3.1 fix pass] A cancelled or aborted start is NOT a scene. lrg_romance.scenes was
            // bumped unconditionally while the affinity gain right below already applied both guards,
            // so an 8-second `how=stopped` start wrote scenes=1 - which pays lrgHistoryBonus() +15 on
            // the interest score (more than tavern_folk's whole min_affinity of 10) and eats the
            // owner's first-scene +8, because the next real scene then counts as the second one.
            // [0.4] asked ONCE and reused, so the log cannot say "not counted" twice for one scene.
            $counted = lrgSceneCounted($npc, $kv, $prev);
            if ($counted) { lrgRomanceBump($npc, 'scenes'); }
            // [0.4] lrg_romance.gold_accepted is the TOTAL gold she has ever taken from the player: it
            // is what repeat_factor and the "they have had this arrangement before" clause read. Behind
            // the same "did this really happen" guard, so a cancelled start pays nothing into her history.
            if ($paidGold > 0 && $counted) { lrgRomanceBump($npc, 'gold_accepted', $paidGold); }
            // [0.3.1 / R1b] a completed scene raises her affinity a little, through CHIM's own API
            lrgAwardSceneAffinity($npc, $kv, $prev);
            // [0.3.1 / R3] and leaves the facts the outro turn will talk about
            lrgWriteOutroTicket($npc, $kv, $prev);
        }
    }
    if ($wasOpen && ($ev === 'start' || $ev === 'end') && function_exists('logEvent')) {
        $player = (string) ($GLOBALS['PLAYER_NAME'] ?? 'the player');
        // [0.4] When gold really changed hands, the line CHIM remembers names the arrangement instead
        // of the romance: she has to be able to refer to it later, and price him next time, and a
        // "they shared an intimate moment" memory of a transaction would be a lie in her own head.
        $startLine = $paidGold > 0
            ? "$npc and $player came to an arrangement: $paidGold septims, and they went to bed on it."
            : "$npc and $player draw close and share an intimate moment in private.";
        $line = $ev === 'start' ? $startLine : lrgSceneSummaryLine($npc, $player, $prev);
        logEvent(['infoaction', $gameRequest[1] ?? lrgNow(), $gameRequest[2] ?? 0, $line]);
    }
    // [0.3 / G5.9] after every landing, one neutral line about what is happening now, so she has a
    // coherent answer to "what are we doing". Same infoaction channel: log-only on the server, but it
    // enters CHIM's context, memory and diary.
    if ($ev === 'change' && ($kv['trans'] ?? '0') !== '1' && $wasOpen) { lrgLandingNote($npc, $kv, $prev, $gameRequest); }
}

/** The stored payload of this NPC's scene row (active or not), [] when there is none. */
function lrgSceneRowPayload(string $npc): array
{
    $db = lrgDb();
    if (!$db || $npc === '') { return []; }
    $row = $db->fetchOne("SELECT payload, active FROM lrg_scene_state WHERE npc_name=" . $db->escapeLiteral($npc));
    if (!$row || (int) ($row['active'] ?? 0) !== 1) { return []; }
    return json_decode((string) ($row['payload'] ?? ''), true) ?: [];
}

/**
 * [0.3 / G2] _visited (scene ids of this thread) and _acts_done (act FAMILIES seen), appended on
 * ev=start and on a non-transition ev=change. The game's new prev= key repairs a dropped push: when it
 * names a scene that is not the last entry, that one is appended first.
 */
function lrgTrackVisited(string $npc, array $kv, array $prev): array
{
    $ev = (string) ($kv['ev'] ?? '');
    if (!in_array($ev, ['start', 'change'], true) || ($kv['trans'] ?? '0') === '1') { return $kv; }
    $id = (string) ($kv['scene'] ?? '');
    if ($id === '') { return $kv; }
    $cap = (int) (lrgConfig()['lead']['no_repeat_scenes'] ?? 12);
    $visited = array_values((array) ($prev['_visited'] ?? []));
    $acts = array_values((array) ($prev['_acts_done'] ?? []));
    if ($ev === 'start') { $visited = []; $acts = []; }
    $push = static function (string $sid) use (&$visited, &$acts, $kv) {
        if ($sid === '' || (($visited[count($visited) - 1] ?? '') === $sid)) { return; }
        $visited[] = $sid;
        $s = function_exists('lrgScene') ? lrgScene($sid) : null;
        if (!$s) { return; }
        foreach (lrgSceneActs($s, (int) ($kv['npos'] ?? 1), null) as $a) {
            $fam = explode(':', $a)[0];
            if (!in_array($fam, $acts, true)) { $acts[] = $fam; }
        }
    };
    $p = (string) ($kv['prev'] ?? '');
    if ($p !== '' && $p !== $id && !in_array($p, $visited, true)) { $push($p); } // a push we never saw
    $push($id);
    $kv['_visited'] = array_slice($visited, -max(1, $cap));
    $kv['_acts_done'] = array_slice($acts, -24);
    return $kv;
}

/** G5.9 - ONE neutral present-tense clause from index vocabulary only. No tier word, no numbers, no prose. */
function lrgLandingNote(string $npc, array $kv, array $prev, array $gameRequest): void
{
    $cfg = (array) (lrgConfig()['landing_notes'] ?? []);
    if (!($cfg['enabled'] ?? true) || !function_exists('logEvent') || !function_exists('lrgScene')) { return; }
    $id = (string) ($kv['scene'] ?? '');
    if ($id === '' || strcasecmp($id, (string) ($prev['scene'] ?? '')) === 0) { return; }
    $gap = (int) ($cfg['min_gap_seconds'] ?? 20);
    $mem = lrgMemGet($npc);
    if (lrgNow() - (int) ($mem['landing_at'] ?? 0) < $gap) { return; }
    $line = lrgLandingLine($npc, $id);
    if ($line === '') { return; }
    lrgMemSet($npc, ['landing_at' => lrgNow()]);
    logEvent(['infoaction', $gameRequest[1] ?? lrgNow(), $gameRequest[2] ?? 0, $line]);
}

/** The landing clause itself: readable scene name, pose words and furniture, nothing invented. */
function lrgLandingLine(string $npc, string $sceneId): string
{
    $s = function_exists('lrgScene') ? lrgScene($sceneId) : null;
    if (!$s) { return ''; }
    $player = (string) ($GLOBALS['PLAYER_NAME'] ?? 'the player');
    $label = (string) preg_replace('/\s*\[[a-z]+\]$/', '', lrgDescribeScene($s)); // drop the [tier] bracket
    $label = trim((string) preg_replace('/\s+/', ' ', $label));
    return $label === '' ? '' : "$npc and $player are now: $label.";
}

/** G5.10 - ONE composed sentence per scene, written once, at its end. */
function lrgSceneSummaryLine(string $npc, string $player, array $prev): string
{
    if (!(lrgConfig()['scene_summary']['enabled'] ?? true) || !$prev) {
        return "$npc and $player's intimate time together comes to an end.";
    }
    $bits = [];
    // [0.3.1 / O9] _started_at, not _tier_since: the old reading measured only the time spent at the
    // TOP tier, which is why a 2.5-minute scene was written up as "about a minute".
    $secs = max(0, lrgNow() - (int) ($prev['_started_at'] ?? ($prev['_tier_since'] ?? lrgNow())));
    $mins = intdiv($secs, 60);
    if ($mins >= 1) { $bits[] = $mins === 1 ? 'about a minute' : "about $mins minutes"; }
    $acts = array_values(array_filter((array) ($prev['_acts_done'] ?? [])));
    if ($acts) {
        $names = array_map(static fn($f) => lrgActLabel((string) $f, $npc, $player), array_slice($acts, 0, 4));
        $bits[] = implode(', ', $names);
    }
    $furn = strtolower(trim((string) ($prev['furn'] ?? '')));
    if ($furn !== '' && $furn !== 'none') { $bits[] = 'on ' . lrgFurnitureLabel($furn); }
    if ((int) ($prev['_climaxes'] ?? 0) > 0) { $bits[] = 'they finished'; }
    $leader = strtolower((string) ($prev['leader'] ?? 'npc'));
    $bits[] = $leader === 'player' ? "$player led" : "$npc led";
    return "$npc and $player's intimate time together comes to an end (" . implode('; ', $bits) . ').';
}

// ---------------------------------------------------------------- [0.3.1] the end of a scene
/**
 * How long the scene really ran, in seconds. The game's additive `dur=` key (wire agreement w3) wins;
 * without it the stored `_started_at` does. Never `_tier_since`, which is the clock of the CURRENT tier
 * and is why a 2.5-minute scene was summarised as "about a minute" (pt7 O9).
 */
function lrgSceneDuration(array $kv, array $prev): int
{
    $dur = (string) ($kv['dur'] ?? '');
    if ($dur !== '' && is_numeric($dur)) { return max(0, (int) round((float) $dur)); }
    $started = (int) ($prev['_started_at'] ?? 0);
    return $started > 0 ? max(0, lrgNow() - $started) : 0;
}

/** finished | stopped | interrupted | lost - the game's additive `how=` key, or the best guess from $prev. */
function lrgSceneHow(array $kv, array $prev): string
{
    $how = strtolower(trim((string) ($kv['how'] ?? '')));
    if (in_array($how, ['finished', 'stopped', 'interrupted', 'lost'], true)) { return $how; }
    $climaxes = max((int) ($prev['_climaxes'] ?? 0), (int) ($prev['ncl'] ?? 0) + (int) ($prev['pcl'] ?? 0));
    return $climaxes > 0 ? 'finished' : 'stopped';
}

/**
 * [0.3.1 fix pass] Did this really happen? ONE test for "a scene that counts", used by the history
 * counter (lrg_romance.scenes) and by the affinity gain, so the two can never disagree again: it ran at
 * least relationship.min_scene_seconds and it ended `finished` or `stopped`. A crash, a reload, a lost
 * thread or a start the player cancelled after eight seconds counts for nothing.
 * NOTE (config readme): outro.min_scene_seconds (45) is deliberately LOWER - a short scene still earns
 * a proper goodbye, it just does not move the relationship.
 */
function lrgSceneCounted(string $npc, array $kv, array $prev): bool
{
    $cfg = (array) (lrgConfig()['relationship'] ?? []);
    $secs = lrgSceneDuration($kv, $prev);
    $min = (int) ($cfg['min_scene_seconds'] ?? 60);
    if ($secs < $min) { lrgLog("scene not counted for $npc - it ran {$secs}s (< {$min}s)"); return false; }
    $how = lrgSceneHow($kv, $prev);
    if (!in_array($how, ['finished', 'stopped'], true)) { lrgLog("scene not counted for $npc - it ended as $how"); return false; }
    return true;
}

/**
 * [0.3.1 / R1b] "sex also should slightly improve the relationship" (owner, playtest 7). A scene that
 * really happened raises her affinity towards the player by a little, once per window, through CHIM's
 * own RelationshipManager - which creates the entry if CHIM has none and never touches the type.
 * Guards: the scene must have run for min_scene_seconds and ended normally or by "stop" (a crash, a
 * reload or a lost thread is not an experience she had), and at most one gain per NPC per
 * gain_window_seconds of real time - lrg_romance.last_scene_at is a real-clock epoch, so that window is
 * the one the code can know reliably.
 */
function lrgAwardSceneAffinity(string $npc, array $kv, array $prev): void
{
    $cfg = (array) (lrgConfig()['relationship'] ?? []);
    if (!($cfg['enabled'] ?? true) || !($cfg['scene_gain_enabled'] ?? true)) { return; }
    $secs = lrgSceneDuration($kv, $prev);
    $how = lrgSceneHow($kv, $prev);
    $min = (int) ($cfg['min_scene_seconds'] ?? 60);
    if ($secs < $min) { lrgLog("relationship: no gain for $npc - the scene ran {$secs}s (< {$min}s)"); return; }
    if (!in_array($how, ['finished', 'stopped'], true)) { lrgLog("relationship: no gain for $npc - the scene ended as $how"); return; }
    $mem = lrgMemGet($npc);
    $window = (int) ($cfg['gain_window_seconds'] ?? 21600);
    $since = lrgNow() - (int) ($mem['aff_gain_at'] ?? 0);
    if ((int) ($mem['aff_gain_at'] ?? 0) > 0 && $since < $window) {
        lrgLog(sprintf('relationship: no gain for %s - one already given %ds ago (window %ds)', $npc, $since, $window));
        return;
    }
    $scenes = (int) (lrgRomance($npc)['scenes'] ?? 0);
    $gain = $scenes <= 1 ? (int) ($cfg['first_scene_gain'] ?? 8) : (int) ($cfg['scene_gain'] ?? 4);
    $why = $scenes <= 1 ? 'their first time together' : 'a scene they shared';
    // [0.4 / OWNER ADDENDA 6] "paid sex counts half for the relationship gain". The game-confirmed
    // `paid=` is the test, never the pay= the server sent. max(1) keeps it from vanishing entirely;
    // paid_gain_factor = 1.0 switches the penalty off.
    $paid = max(0, (int) ($kv['paid'] ?? ($prev['paid'] ?? 0)));
    if ($paid > 0) {
        $factor = (float) ((lrgConfig()['paid_intimacy'] ?? [])['paid_gain_factor'] ?? 0.5);
        $half = max(1, (int) floor($gain * ($factor > 0.0 ? $factor : 0.5)));
        lrgLog("relationship: the scene was paid for ($paid septims) - the gain is $half instead of $gain");
        $gain = $half;
        $why .= ' (paid for)';
    }
    $new = lrgAdjustAffinity($npc, $gain, $why);
    if ($new !== null) { lrgMemSet($npc, ['aff_gain_at' => lrgNow()]); }
}

/**
 * [0.3.1 / R3] The outro ticket: the facts of the scene that just ended, kept for outro.window_seconds
 * so the game's `outro` request can be answered even though the scene row is already closed.
 * Everything comes from the last stored push ($prev) - `ev=end` itself carries almost nothing.
 */
function lrgWriteOutroTicket(string $npc, array $kv, array $prev): void
{
    $cfg = (array) (lrgConfig()['outro'] ?? []);
    if (!($cfg['enabled'] ?? true)) { return; }
    $secs = lrgSceneDuration($kv, $prev);
    $min = (int) ($cfg['min_scene_seconds'] ?? 45);
    if ($secs < $min) { lrgLog("outro: no ticket for $npc - the scene ran {$secs}s (< {$min}s)"); return; }
    $how = lrgSceneHow($kv, $prev);
    if (in_array($how, ['lost'], true)) { lrgLog("outro: no ticket for $npc - the scene was $how"); return; }
    $rom = lrgRomance($npc);
    $ticket = [
        'at' => lrgNow(),
        'cid' => (string) ($kv['cid'] ?? ''),
        'scene' => (string) ($kv['scene'] ?? ($prev['scene'] ?? '')),
        'how' => $how,
        'dur' => $secs,
        'ncl' => (int) ($prev['ncl'] ?? 0),
        'pcl' => (int) ($prev['pcl'] ?? 0),
        'acts' => array_values(array_slice((array) ($prev['_acts_done'] ?? []), -6)),
        'scenes' => array_values(array_slice((array) ($prev['_visited'] ?? []), -4)),
        'furn' => strtolower(trim((string) ($prev['furn'] ?? ''))),
        'leader' => strtolower((string) ($prev['leader'] ?? 'npc')),
        'first_time' => ((int) ($rom['scenes'] ?? 0)) <= 1,
        'total' => (int) ($rom['scenes'] ?? 0),
        // [0.4] so the goodbye can be about what it really was
        'paid' => max(0, (int) ($kv['paid'] ?? ($prev['paid'] ?? 0))),
    ];
    lrgMemSet($npc, ['outro' => $ticket]);
    lrgLog(sprintf('outro ticket for %s: %ds, %s, acts=%s, climaxes %d/%d, first=%s', $npc, $secs, $how,
        implode(',', $ticket['acts']) ?: '-', $ticket['ncl'], $ticket['pcl'], $ticket['first_time'] ? 'yes' : 'no'),
        (string) ($kv['cid'] ?? ''));
}

/** The outro ticket if it is still inside its window, else null (and it is dropped). */
function lrgOutroTicket(string $npc, ?array $mem = null): ?array
{
    $cfg = (array) (lrgConfig()['outro'] ?? []);
    if (!($cfg['enabled'] ?? true) || $npc === '') { return null; }
    $mem = $mem ?? lrgMemGet($npc);
    $t = is_array($mem['outro'] ?? null) ? $mem['outro'] : null;
    if ($t === null) { return null; }
    if (lrgNow() - (int) ($t['at'] ?? 0) > (int) ($cfg['window_seconds'] ?? 60)) {
        lrgMemSet($npc, ['outro' => null]);
        return null;
    }
    return $t;
}

/** True when this request is the game's outro request (PROTOCOL 1.4): type lrg_scenetalk, text "outro". */
function lrgIsOutroTick(): bool
{
    return strtolower((string) ($GLOBALS['gameRequest'][0] ?? '')) === 'lrg_scenetalk'
        && lrgIsTickText((string) ($GLOBALS['gameRequest'][3] ?? ''), 'outro');
}

/** Was a scene with this NPC really open (an ev=start reached us) under this correlation id? */
function lrgSceneWasOpen(string $npc, string $cid): bool
{
    $db = lrgDb();
    if (!$db || $npc === '') { return false; }
    $row = $db->fetchOne("SELECT payload, active FROM lrg_scene_state WHERE npc_name=" . $db->escapeLiteral($npc));
    if (!$row || (int) ($row['active'] ?? 0) !== 1) { return false; }
    $prev = json_decode((string) ($row['payload'] ?? ''), true) ?: [];
    return isset($prev['_maxtier']) && ($cid === '' || (string) ($prev['cid'] ?? '') === $cid);
}

/**
 * funcret of a glue command -> lrg_memory.last_result, and $GLOBALS['LRG_FUNCRET'] for lrgFuncretVerdict().
 * [0.5.5] A failure is VOICED in the same exchange when it can be (the funcret turn, told=true there); one
 * that is not voiced is still told once on her next turn from last_result, exactly as before.
 */
function lrgRecordResult(string $text): void
{
    $raw = lrgStripContext($text);
    // [0.4 integrator, D-12b] Phase 2 composes <what_just_happened> itself from its own record of the
    // click, so a SelectTopic funcret must NOT also land in Phase 1's lrg_memory.last_result - the NPC
    // would be told about the same thing a second time, in Phase 1's words.
    if (stripos($raw, 'command@ExtCmdLRG_') !== 0 || !lrgEnabled()) { return; }
    // [pt19 v1.0 / S2.3, S6.2] ... but Phase 2 learns two facts from its own funcret: the game REFUSED a do=open (open_refused,
    // read by the pre-LLM open and lrgFacQuestPlan), and what a do=award really gave (gate B). Nothing else, nothing voiced.
    if (stripos($raw, 'command@ExtCmdLRG_SelectTopic') === 0) { lrgDlgTopicFuncret($raw); return; }
    $p = explode('@', $raw, 4);
    $kv = lrgParseKv((string) ($p[2] ?? ''));
    $npc = (string) ($kv['npc'] ?? '');
    if ($npc === '') { return; } // an older game build that does not echo npc=: nothing to attribute it to
    $result = trim((string) ($p[3] ?? ''));
    // [0.5.5 / game 507] "OK: <neutral>" = success, "Error: <her words>" = failure, and the TECHNICAL reason (the
    // closed list of PROTOCOL 1.6) travels as err= appended to field 2; late=1 = a command already answered OK:
    // that came undone. A script <= 506 sends neither key: its Error: text IS the technical reason.
    // `reason` stays the technical reason everywhere (every server match is on it, as before 507); `say` keeps
    // her words for her next-turn note.
    $ok = stripos($result, 'Error:') !== 0;
    lrgEnsureSchema();
    $words = $ok ? '' : trim(substr($result, 6));
    $tech = $ok ? '' : trim((string) ($kv['err'] ?? ''));
    $reason = $tech !== '' ? $tech : $words;
    $say = $tech !== '' ? $words : '';
    $late = ($kv['late'] ?? '') === '1';
    // [pt17 / script 509] an OK that came with a "but": the escort's fallback - she comes along, only not as his
    // companion - travels as fb=<technical reason> on field 2 (LRG_Main.EscortFollow). Voiced by lrgFuncretVerdict.
    $partial = $ok ? trim((string) ($kv['fb'] ?? '')) : '';
    // [0.5.4] a game script older than the escort answers ExtCmdLRG_Escort with "unknown command" and
    // nothing else: nothing she tried failed, so there is nothing for her to be told about it
    $oldGame = !$ok && strcasecmp((string) ($p[1] ?? ''), LRG_ACT_ESCORT) === 0 && strcasecmp($reason, 'unknown command') === 0;
    if ($oldGame) {
        // ... except the game's own corner note (ReportResult shows every "Error:" while bNotifyErrors
        // is on): so the escort is switched off for the rest of THIS game session - one note, not one
        // per "follow me". A reload re-rolls the session tag and the next escort tries again.
        $st = lrgGetNpcState($npc);
        lrgMemSet(LRG_ESCORT_MEM, ['escort_unknown' => ['sess' => (string) (($st ?? [])['sess'] ?? ''), 'at' => lrgNow()]]);
        lrgLog("escort: this game script does not know ExtCmdLRG_Escort yet (npc=$npc) - nothing happened, nothing is said,"
            . ' and no escort is sent again this game session', (string) ($kv['cid'] ?? ''));
    }
    $patch = ['last_result' => ['cmd' => (string) ($p[1] ?? ''), 'do' => (string) ($kv['do'] ?? ''), 'ok' => $ok,
        'reason' => $reason, 'say' => $say, 'late' => $late, 'at' => lrgNow(), 'told' => $ok || $oldGame]];
    if ($partial !== '') { $patch['last_result']['partial'] = $partial; } // [pt17] only when the game sent one: the record's shape is otherwise 507's
    // [0.3 / G6] the emitted-vs-confirmed watchdog: this command came back, so it is no longer pending
    $mem = lrgMemGet($npc);
    $pend = is_array($mem['pending_cmd'] ?? null) ? $mem['pending_cmd'] : null;
    if ($pend !== null && (string) ($pend['cid'] ?? '') === (string) ($kv['cid'] ?? '')) { $patch['pending_cmd'] = null; }
    lrgMemSet($npc, $patch);
    // [0.5.5] what lrgFuncretVerdict() decides on: this result, and how the command was sent (lrgNoteSent)
    $GLOBALS['LRG_FUNCRET'] = ['npc' => $npc, 'cmd' => (string) ($p[1] ?? ''), 'do' => (string) ($kv['do'] ?? ''),
        'ok' => $ok, 'reason' => $reason, 'say' => $say, 'late' => $late, 'partial' => $partial, 'cid' => (string) ($kv['cid'] ?? ''), 'old' => $oldGame, 'kv' => $kv,
        'sent' => lrgSentFind($mem, (string) ($kv['cid'] ?? ''), (string) ($p[1] ?? '')),
        'voiced_at' => (int) ($mem['voiced_at'] ?? 0)];
    lrgLog('result ' . ($p[1] ?? '') . ($late ? ' (late)' : '') . ' do=' . ($kv['do'] ?? '-') . " npc=$npc -> " . substr($result, 0, 120)
        . ($tech !== '' ? ' [why: ' . substr($tech, 0, 120) . ']' : '') . ($partial !== '' ? ' [but: ' . substr($partial, 0, 120) . ']' : ''), (string) ($kv['cid'] ?? ''));
    // [0.3 / G4 path 1] The single most reliable statement the game can make. The error list is closed
    // (PROTOCOL 1.6), so matching on it is safe. The server already had this evidence in playtest 6 at
    // 19:33:57 and threw it away, which is why the next turn was still built as an in-scene turn.
    if (!$ok && strcasecmp($reason, 'no scene is running') === 0) {
        lrgCloseScenesFor($npc, 'the game says no scene is running');
    }
}

/**
 * [pt19 v1.0 / S2.3, model F4] The game's OpenBlockedReason / FailOpen reasons (LRG_Dialogue.psc, the closed list): the ONLY
 * results of a do=open that mean "the open cannot happen". "that moment has passed" (a stamped open overtaken by the first
 * pick), the dry runs and every other close are not refusals of the open and never set open_refused.
 */
const LRG_DLG_OPEN_REFUSALS = ['the actor could not be found', 'combat', 'a conversation is in progress', 'they cannot talk right now',
    'a quest scene is running', 'a scene is already running', 'too far apart', 'her voice is not ready'];

/**
 * [pt19 v1.0 / S2.3, S6.2] Phase 2's own SelectTopic funcret ("command@ExtCmdLRG_SelectTopic@<param>[;err=<tech>]@<result>"):
 *  - do=open + Error: <an open refusal> -> open_refused {why, at} in Phase 2's state (the pre-LLM open is not retried, the
 *    direct-barter net comes back, lrgFacQuestPlan queues 10.26's click-free entry - S2.3's fallback);
 *  - do=award + "OK: gave <n> septims" (gate B, give= on the command) -> award_gave {n, want, at, told}: the next turn's
 *    <what_just_happened> says what she really gave, and a short-fall once (lrgDlgGroundTruth).
 * The funcret itself still passes to CHIM untouched (lrgFuncretVerdict: its row has follow-up off).
 */
function lrgDlgTopicFuncret(string $raw): void
{
    // [review fix] a funcret is decided PRE-LOCK in preprocessing.php, where only lrg_core + lrg_actions are loaded: Phase 2's
    // store (lib/lrg_dialogue.php) is loaded below, and only for the two results that are written - never for a pick / leave
    $p = explode('@', $raw, 4);
    $kv = lrgParseKv((string) ($p[2] ?? ''));
    $npc = (string) ($kv['npc'] ?? '');
    $do = (string) ($kv['do'] ?? '');
    $res = trim((string) ($p[3] ?? ''));
    if ($npc === '' || ($do !== 'open' && $do !== 'award')) { return; }
    $cid = (string) ($kv['cid'] ?? '');
    if ($do === 'open') {
        if (stripos($res, 'Error:') !== 0) { return; }
        $tech = strtolower(trim((string) ($kv['err'] ?? '')) ?: trim(substr($res, 6)));
        if (!in_array($tech, LRG_DLG_OPEN_REFUSALS, true)) {
            lrgLog('open result npc=' . $npc . ' "' . substr($tech, 0, 60) . '" - not a refusal of the open (F4): open_refused untouched', $cid);
            return;
        }
        if (!function_exists('lrgDlgPut')) { require_once __DIR__ . '/lrg_dialogue.php'; }
        lrgDlgPut($npc, ['open_refused' => ['why' => $tech, 'at' => lrgNow()]]);
        lrgLog('open refused npc=' . $npc . ' why="' . $tech . '" - open_refused set: no pre-LLM open for ' . (int) lrgDlgCfg('open.refused_seconds', 120)
            . ' s, the barter net comes back, a join ask falls to the click-free entry (S2.3)', $cid);
        return;
    }
    // do=award (gate B): the ACTUAL number the game moved, read back from its OK
    if (!preg_match('/^OK:.*\bgave (\d+)\b/i', $res, $m)) { return; }
    $want = (int) ($kv['give'] ?? 0);
    if (!function_exists('lrgDlgPut')) { require_once __DIR__ . '/lrg_dialogue.php'; }
    lrgDlgPut($npc, ['award_gave' => ['n' => (int) $m[1], 'want' => $want, 'at' => lrgNow(), 'told' => false]]);
    lrgLog(sprintf('award gave=%d want=%d npc=%s%s', (int) $m[1], $want, $npc, $want > (int) $m[1] ? ' - short-fall, told once next turn' : ''), $cid);
}

/**
 * [pt19 v1.0 / S7] A server-side gate refusal the voice gap skipped (her words were spoken, the pick was not sent): the
 * PLAIN sentence per code is kept for Phase 2 (refusal_note {why, code, at, told}), and lrgDlgGroundTruth tells it next turn as
 * "Nothing came of it: <sentence>." The log keeps the machinery string; the sentence never carries one.
 * [pt19c-B fix 1 / game review, lang review P6] in the VOICE of the block that tells them: <what_just_happened> speaks to her
 * as "you" ("You answered", "It worked on you") and names the player - S7's third-person "she has refused that already" told
 * Balgruuf, as fact, that some woman had refused, and "he could not pay" had no "he" to point at. {player} = PLAYER_NAME.
 */
const LRG_DLG_REFUSAL_WORDS = [
    'afford' => '{player} could not pay what that costs',
    'amount' => '{player} offered less than it costs',
    'words' => '{player} did not say it in a whole sentence',
    'retry' => 'you have already refused that and nothing has changed',
    'frozen' => 'things had just changed and you had to look again',
    // [lang review P8] the sum as heard is not trustworthy ("5 hundred gold" is read as 100, "five hundred sept ums" as 0 -
    // lrg_intent.php): the note must not say he offered less than it costs when that may not be what he said
    'amount_unclear' => 'the sum {player} named did not come through clearly - ask him plainly how many septims he means',
];

/** [pt19 v1.0 / S7] The plain sentence of a refusal code, the player named. */
function lrgDlgRefusalSentence(string $code): string
{
    $player = trim((string) ($GLOBALS['PLAYER_NAME'] ?? '')) ?: 'the player';
    return str_replace('{player}', $player, (string) (LRG_DLG_REFUSAL_WORDS[$code] ?? ''));
}

/** The refusal code of a gate `why` (lrgDlgDecideEntry / lrgDlgCheckRails wording), or ''. */
function lrgDlgRefusalCode(string $why): string
{
    $w = strtolower($why);
    if (isset(LRG_DLG_REFUSAL_WORDS[$w])) { return $w; }
    if (str_contains($w, 'gold moved since the list was read')) { return 'frozen'; }
    if (preg_match('/^priced entry \d+ septims, the player has|cannot pay$/', $w)) { return 'afford'; }
    if (str_starts_with($w, 'the player offered ')) { return 'amount'; }
    if (preg_match('/attempt needs at least \d+ words/', $w)) { return 'words'; }
    if (str_contains($w, 'already failed and nothing relevant changed')) { return 'retry'; }
    return '';
}

/**
 * Store the plain sentence for $code (a code of LRG_DLG_REFUSAL_WORDS, or a gate why it classifies). Returns the code or ''.
 * [pt19c-B fix 1 / CHIM review, use review P2] under its OWN key, refusal_note: this runs in the post-gate at stream end, 5-20 s
 * after PrepareTurn, and overwriting Phase 2's last_result replaced a click result that landed mid-stream (a hand click, an open's
 * result) with "Nothing came of it", erased the engine's check verdict inside its 180 s (she could give in to what the engine
 * refused) and closed the reward window and the quest turn with it.
 */
function lrgDlgNoteRefusal(string $npc, string $code): string
{
    $code = lrgDlgRefusalCode($code);
    if ($npc === '' || $code === '' || !function_exists('lrgDlgPut')) { return ''; }
    $sayCode = $code;
    if ($code === 'amount' && function_exists('lrgDlgState')) {
        $u = strtolower((string) (((array) (lrgDlgState($npc)['utter'] ?? []))['text'] ?? ''));
        if (preg_match('/\b\d+\s+(?:hundred|thousand)\b|\bsept\s?[eu]m\'?s?\b/', $u)) { $sayCode = 'amount_unclear'; }
    }
    lrgDlgPut($npc, ['refusal_note' => ['why' => lrgDlgRefusalSentence($sayCode), 'code' => $code, 'at' => lrgNow(), 'told' => false]]);
    return $code;
}

// ---------------------------------------------------------------- [0.5.5 / owner addendum 11] NEVER SILENT
/*
 * "if she's busy she should say that then and it will be fine, she never told me she was busy, she just
 * didn't do anything." Until 0.5.4 every glue result ended in silence: the follow-up of every glue row was
 * off, so a refused escort, a clothing change the game would not make or an unreachable position was only
 * a corner note, and she said nothing until the player spoke again. Now:
 *   QUIET   a success, her own move on a scene-lead tick, the hold carrier, a dry run, the kill switch, a
 *           second failure inside voice.min_gap_seconds -> ended HERE, before the MAIN lock (no LLM, no TTS;
 *           a quiet failure is still told once on her next turn from last_result, as before);
 *   VOICED  every other failure / refusal -> passed on to CHIM's own funcret follow-up turn (actions v11),
 *           whose cue says what happened and why in plain words (lrgVoicedCue, from prompts.php): ONE short
 *           line in her own voice, never pretending it happened. No new LLM call of the glue's own: it is the
 *           one turn CHIM already runs for a funcret, and it replaces a silence.
 */

/** How a command was sent: speech | lead | initiative | carrier | other, from the turn that sent it. */
function lrgSentSrc(array $turn): string
{
    $type = (string) ($turn['type'] ?? '');
    if (in_array($type, LRG_PLAYER_SPEECH_TYPES, true)) { return 'speech'; }
    if ($type === 'lrg_scenetalk') { return 'lead'; }
    if ($type === 'lrg_initiative') { return 'initiative'; }
    return 'other';
}

/**
 * [0.5.5] Remember what went out, per NPC (lrg_memory `sent`, the last 8 within 15 minutes), so its result
 * can be judged when the funcret comes back: the funcret echoes cid= and the code, never the turn type.
 * $extra is merged into the SAME memory write (lrgNotePending passes pending_cmd).
 */
function lrgNoteSent(array $turn, string $code, array $kv, string $src = '', string $cid = '', array $extra = []): void
{
    $npc = (string) ($turn['npc'] ?? '');
    if ($npc === '') { return; }
    $now = lrgNow();
    $ring = array_values(array_filter((array) (lrgMemGet($npc)['sent'] ?? []),
        static fn($e) => is_array($e) && $now - (int) ($e['at'] ?? 0) <= 900));
    $ring[] = ['cid' => $cid !== '' ? $cid : (string) ($turn['cid'] ?? ''), 'code' => lrgShortCode($code),
        'do' => (string) ($kv['do'] ?? ''), 'src' => $src !== '' ? $src : lrgSentSrc($turn), 'at' => $now];
    lrgMemSet($npc, ['sent' => array_slice($ring, -8)] + $extra);
}

/** The `sent` entry of this cid (and code, when two commands shared the cid), or null. */
function lrgSentFind(array $mem, string $cid, string $code): ?array
{
    if ($cid === '') { return null; }
    $short = lrgShortCode($code);
    $hit = null;
    foreach ((array) ($mem['sent'] ?? []) as $e) {
        if (!is_array($e) || (string) ($e['cid'] ?? '') !== $cid) { continue; }
        if (strcasecmp((string) ($e['code'] ?? ''), $short) === 0) { return $e; }
        $hit = $hit ?? $e;
    }
    return $hit;
}

/**
 * [0.5.5] The pre-lock verdict on one funcret: 'handled' = it ends here in silence, 'pass' = it goes on to
 * CHIM (CHIM's own actions and Phase 2's SelectTopic, untouched; a glue failure that is VOICED).
 * A voiced result also rewrites field 2 and 3 of this request's funcret: CHIM puts field 2 into the turn's
 * tool call by raw interpolation and the JSON connector writes it into her event log as the action's
 * target, where the whole k=v parameter used to land; field 3 becomes the plain fact she is answering.
 */
function lrgFuncretVerdict(string $text): string
{
    $raw = lrgStripContext($text);
    if (stripos($raw, 'command@ExtCmdLRG_') !== 0) { return 'pass'; }              // CHIM's own action: CHIM's own rules
    if (stripos($raw, 'command@' . (defined('LRG_ACT_TOPIC') ? LRG_ACT_TOPIC : 'ExtCmdLRG_SelectTopic') . '@') === 0) {
        return 'pass';                                                             // Phase 2: its own row, follow-up off
    }
    $r = $GLOBALS['LRG_FUNCRET'] ?? null;
    // the kill switch / feature off, SHARMAT, a game that echoes no npc=: nothing to attribute, nothing to say
    if (!is_array($r) || !lrgEnabled() || defined('LRG_SHARMAT_PRESENT')) { return 'handled'; }
    // [pt17] ... except an OK that came with a "but" (fb=: the escort's fallback, "comes along, but not as his sworn
    // companion"), which is voiced like a failure (escort.voice_fallback) - she DID come, and says why not as a companion
    $partial = trim((string) ($r['partial'] ?? ''));
    // [pt18-quest] ExtCmdLRG_QuestEntry: the ONE glue success that may be voiced - when no licence was given pre-LLM the
    // words did not say it, so the game's own OK is what confirms it. Its record (exec_qst) is written either way.
    if (strcasecmp((string) $r['cmd'], LRG_ACT_QUESTENTRY) === 0) {
        $qv = lrgQuestEntryResult($r);
        if ($qv !== null) { return $qv; }
    }
    // [pt19-purchase] ExtCmdLRG_Buy: an OK refreshes the cached row (unit= / stock=) and stays quiet - her line already said it
    // and the vanilla "<item> added" message is the receipt; a refusal is voiced like every glue failure (lrgMktVoicedWhy).
    if (strcasecmp((string) $r['cmd'], LRG_ACT_BUY) === 0) {
        $bv = lrgMktResult($r);
        if ($bv !== null) { return $bv; }
    }
    if (!empty($r['ok']) && $partial === '') { return 'handled'; }  // QUIET SUCCESS: what the player asked for happened - as before, only earlier
    if (!empty($r['old'])) { return 'handled'; } // an old game's "unknown command" to the escort: nothing she tried failed
    $cid = (string) $r['cid'];
    $npc = (string) $r['npc'];
    $skip = ($partial !== '' && empty(lrgEscortCfg('voice_fallback', true))) ? 'switched off (escort.voice_fallback)' : lrgVoiceSkip($r);
    if ($skip !== '') {
        if ($partial !== '') {
            // never dropped: told on her next player turn through the missed note (lrgMissedNote), in the same plain words
            $player = trim((string) ($GLOBALS['PLAYER_NAME'] ?? '')) ?: 'the player';
            lrgMemSet($npc, ['missed' => ['what' => "become $player's companion",
                'why' => lrgVoicedWhy(['npc' => $npc, 'code' => (string) $r['cmd'], 'reason' => $partial]), 'at' => lrgNow(), 'code' => (string) $r['cmd']]]);
            lrgLog(sprintf('voiced: no (%s) - %s do=%s npc=%s came along but not as a companion (%s); told on her next turn', $skip,
                lrgShortCode((string) $r['cmd']), (string) $r['do'] !== '' ? $r['do'] : '-', $npc, $partial), $cid);
            return 'handled';
        }
        lrgLog(sprintf('voiced: no (%s) - %s do=%s npc=%s is told on her next turn', $skip, lrgShortCode((string) $r['cmd']),
            (string) $r['do'] !== '' ? $r['do'] : '-', $npc), $cid);
        return 'handled';
    }
    $mem = lrgMemGet($npc);
    $lr = (array) ($mem['last_result'] ?? []);
    $patch = ['last_result' => array_merge($lr, ['told' => true]), 'voiced_at' => lrgNow()];
    // [game 507] the escort's LATE failure also reaches the server as the game's log line (lrgNoteEscortLate), in
    // either order: the one spoken now must not be told a second time on her next turn
    $miss = $mem['missed'] ?? null;
    if (!empty($r['late']) && is_array($miss) && strcasecmp((string) ($miss['code'] ?? ''), (string) $r['cmd']) === 0) { $patch['missed'] = null; }
    lrgMemSet($npc, $patch);
    $vr = ['npc' => $npc, 'code' => (string) $r['cmd'], 'do' => (string) $r['do'], 'reason' => $partial !== '' ? $partial : (string) $r['reason'], 'cid' => $cid,
        'say' => (string) ($r['say'] ?? ''), 'late' => !empty($r['late']), 'partial' => $partial !== '',
        'src' => (string) (($r['sent'] ?? [])['src'] ?? 'unknown'), 'kv' => (array) $r['kv'], 'at' => lrgNow()];
    $vr['fact'] = lrgVoicedFact($vr);
    $GLOBALS['LRG_VOICED'] = $vr;
    $gr = $GLOBALS['gameRequest'] ?? null;
    if (is_array($gr) && strtolower((string) ($gr[0] ?? '')) === 'funcret' && (string) ($gr[3] ?? '') === $text) {
        $p = explode('@', $raw, 4);
        $token = (string) preg_replace('/[^a-z0-9_]/', '', strtolower($vr['do'] !== '' ? $vr['do'] : lrgShortCode($vr['code'])));
        $GLOBALS['gameRequest'][3] = 'command@' . $p[1] . '@' . ($token !== '' ? $token : 'result') . '@Error: '
            . str_replace(['@', '|', "\r", "\n"], [' ', '/', ' ', ' '], $vr['fact']);
    }
    lrgLog(sprintf('voiced %s%s do=%s npc=%s src=%s reason="%s" -> her funcret turn says why', lrgShortCode($vr['code']),
        $vr['late'] ? ' (late)' : ($vr['partial'] ? ' (partial: came along, not as a companion)' : ''),
        $vr['do'] !== '' ? $vr['do'] : '-', $npc, $vr['src'], str_replace('"', "'", $vr['reason'])), $cid);
    return 'pass';
}

/**
 * [pt18-quest] The verdict on an ExtCmdLRG_QuestEntry result, or null = the ordinary failure voicing applies.
 *  OK    -> exec_qst {quest, stage, at, cid} goes into Phase 2's state (the words lane's never-false judge reads it
 *           while it is fresher than the facts line; lrgFacQuestPlan reads it as 'already'), facexec is marked done.
 *           LICENSED (the flag was set pre-LLM, the words already said it) -> 'handled', quiet like every glue success.
 *           UNLICENSED -> the success is VOICED: $GLOBALS['LRG_VOICED'] with success=true, the row's `say` as the
 *           directive, field 3 rewritten to the plain fact, 'pass' (CHIM's funcret turn speaks it). Never both.
 *  Error -> facexec is cleared (a re-ask must not wait on it) and null: voiced as every glue failure.
 */
function lrgQuestEntryResult(array $r): ?string
{
    $npc = (string) ($r['npc'] ?? '');
    $cid = (string) ($r['cid'] ?? '');
    $kv = (array) ($r['kv'] ?? []);
    $quest = (string) ($kv['quest'] ?? '');
    $stage = (int) ($kv['stage'] ?? 0);
    if (empty($r['ok'])) {
        if (function_exists('lrgDlgPut') && $npc !== '') { lrgDlgPut($npc, ['facexec' => null]); }
        return null;
    }
    $mem = lrgMemGet($npc);
    $lic = (array) ($mem['qe_lic'] ?? []);
    $mine = $cid !== '' && (string) ($lic['cid'] ?? '') === $cid;
    $now = lrgNow();
    if ($quest !== '' && $stage > 0 && function_exists('lrgDlgPut') && $npc !== '') {
        lrgDlgPut($npc, ['exec_qst' => ['quest' => $quest, 'stage' => max($stage, (int) ($kv['qs'] ?? 0)), 'at' => $now, 'cid' => $cid],
            'facexec' => ['quest' => $quest, 'stage' => $stage, 'at' => $now, 'cid' => $cid, 'done' => 1, 'lic' => $mine ? (int) ($lic['lic'] ?? 0) : 0]]);
    }
    if ($mine && !empty($lic['lic'])) {
        lrgLog(sprintf('questentry OK %s stage %d npc=%s - licensed pre-LLM, the words already said it: quiet (journal=%s)',
            $quest, $stage, $npc, (string) ($kv['qj'] ?? '-')), $cid);
        return 'handled';
    }
    $skip = lrgVoiceSkip($r);
    if ($skip !== '') {
        lrgLog(sprintf('questentry OK %s stage %d npc=%s - not voiced (%s); exec_qst and the next facts line carry it', $quest, $stage, $npc, $skip), $cid);
        return 'handled';
    }
    $eff = function_exists('lrgFacEffectFor') ? lrgFacEffectFor($quest, $stage) : [];
    $say = trim((string) ($lic['say'] ?? '')) ?: trim((string) ($eff['say'] ?? ''));
    $meaning = trim((string) ($lic['meaning'] ?? '')) ?: trim((string) ($eff['meaning'] ?? ''));
    $player = trim((string) ($GLOBALS['PLAYER_NAME'] ?? '')) ?: 'the player';
    $fact = $meaning !== '' ? $meaning : "the step $player asked for is recorded";
    lrgMemSet($npc, ['last_result' => array_merge((array) ($mem['last_result'] ?? []), ['told' => true]), 'voiced_at' => $now]);
    $vr = ['npc' => $npc, 'code' => (string) $r['cmd'], 'do' => 'entry', 'reason' => '', 'cid' => $cid, 'say' => '', 'late' => false,
        'partial' => false, 'src' => (string) (($r['sent'] ?? [])['src'] ?? 'speech'), 'kv' => $kv, 'at' => $now,
        'success' => true, 'fact' => $fact, 'directive' => $say];
    $GLOBALS['LRG_VOICED'] = $vr;
    $gr = $GLOBALS['gameRequest'] ?? null;
    if (is_array($gr) && strtolower((string) ($gr[0] ?? '')) === 'funcret') {
        $p = explode('@', lrgStripContext((string) ($gr[3] ?? '')), 4);
        $GLOBALS['gameRequest'][3] = 'command@' . (string) ($p[1] ?? $r['cmd']) . '@entry@OK: '
            . str_replace(['@', '|', "\r", "\n"], [' ', '/', ' ', ' '], $fact);
    }
    lrgLog(sprintf('voiced %s OK npc=%s src=%s fact="%s" -> her funcret turn says the outcome (no licence was given pre-LLM)',
        lrgShortCode((string) $r['cmd']), $npc, (string) $vr['src'], str_replace('"', "'", $fact)), $cid);
    return 'pass';
}

/** Why this failure is NOT spoken now ('' = it is). A skipped one is still told on her next turn. */
function lrgVoiceSkip(array $r): string
{
    if (!lrgVoiceCfg('enabled', true)) { return 'switched off (voice.enabled)'; }
    $reason = strtolower((string) ($r['reason'] ?? ''));
    // [pt17 / owner addendum 11] A DRY RUN IS VOICED. Until 508 it was the one refusal deliberately kept silent ("a
    // dry run changes nothing"), and on 2026-09-23 seven correct requests (undress x2, two scene starts, release x3)
    // were refused that way while the owner had the developer switch on by mistake and never heard why. She now
    // says the switch is on and where it is (lrgVoicedWhy); voice.dry_run_voiced=false restores the old silence.
    if (lrgVoicedDryKind($reason) !== '' && !lrgVoiceCfg('dry_run_voiced', true)) { return 'a dry run changes nothing (voice.dry_run_voiced)'; }
    // the two answers that are not about her at all: an actor the game will not treat as an adult, and the
    // owner's own switch in the game (both fail closed - no line about intimacy is ever asked for there)
    if ($reason === 'adults only' || $reason === 'the feature is switched off') { return 'the game refused on a hard rail'; }
    $src = (string) (($r['sent'] ?? [])['src'] ?? '');
    if ($src === '') {
        // not in `sent` (older than 15 minutes, or sent by 0.5.4): the carrier still has its own shape
        $kv = (array) ($r['kv'] ?? []);
        if (($kv['do'] ?? '') === 'lead' && isset($kv['hold']) && count($kv) === 6) { $src = 'carrier'; }
    }
    if ($src === 'carrier') { return 'the hold carrier - nobody asked for it'; }
    if ($src === 'lead' && !lrgVoiceCfg('lead_failures', false)) { return 'her own move on a scene-lead tick'; }
    $gap = (int) lrgVoiceCfg('min_gap_seconds', 8);
    $at = (int) ($r['voiced_at'] ?? 0);
    if ($gap > 0 && $at > 0 && lrgNow() - $at < $gap) { return 'another failure was voiced ' . (lrgNow() - $at) . 's ago'; }
    return '';
}

/** What did not happen, in plain words, from the command and its echoed parameters. */
function lrgVoicedWhat(array $vr): string
{
    $npc = (string) ($vr['npc'] ?? '') ?: 'she';
    $player = trim((string) ($GLOBALS['PLAYER_NAME'] ?? '')) ?: 'the player';
    $code = (string) ($vr['code'] ?? '');
    $do = (string) ($vr['do'] ?? '');
    $kv = (array) ($vr['kv'] ?? []);
    if (strcasecmp($code, LRG_ACT_BUY) === 0) {
        // [pt19-purchase] what did not change hands: the item by its name, never the code or the form id
        $what = trim((string) ($kv['name'] ?? ''));
        return ($what !== '' ? 'the ' . $what : 'what ' . $player . ' ordered') . ' did not change hands';
    }
    if (strcasecmp($code, LRG_ACT_QUESTENTRY) === 0) {
        // [pt18-quest] a SUCCESS is voiced only when the words could not say it (lrgQuestEntryResult); a failure = nothing recorded
        if (!empty($vr['success'])) { return (string) ($vr['fact'] ?? '') ?: "the step $player asked for is recorded"; }
        // [pt19h-quest] a quest-entry TABLE row (config/lrg_quest_entries.default.json) is a quest step, not an enlistment. Read
        // from the qe_lic record lrgFacQuestNet left (the funcret is decided PRE-LOCK, where lib/lrg_factions.php is not loaded),
        // else from the table itself when it is
        if (lrgQeIsTableRow($vr)) { return "nothing was recorded for the step $player asked for"; }
        return "nothing was recorded for $player's enlistment";
    }
    if (strcasecmp($code, LRG_ACT_ESCORT) === 0) {
        // [pt17] the fallback: she DID come along, only not as his companion (fb= on the OK funcret)
        if (!empty($vr['partial'])) { return "$npc comes along with $player, but not as his sworn companion"; }
        return ['wait' => "$npc could not stay behind and wait for $player",
            'release' => "$npc could not go back to what she was doing"][$do] ?? "$npc could not come along with $player";
    }
    if (strcasecmp($code, LRG_ACT_CLOTHING) === 0) {
        $who = (string) ($kv['who'] ?? 'npc');
        $whose = $who === 'player' ? "$player's clothes" : ($who === 'both' ? 'their clothes' : "$npc's clothes");
        return $do === 'dress' ? "$whose did not go back on" : "$whose did not come off";
    }
    if (strcasecmp($code, LRG_ACT_START) === 0) {
        // [game 507] late=1 on a start: the scene DID begin; the position asked for with it (after=) could not follow
        return !empty($vr['late']) ? 'the change of position did not happen' : "nothing began between $npc and $player";
    }
    $furn = trim((string) ($kv['furn'] ?? ''));
    $map = ['goto' => 'the change of position did not happen', 'furniture' => 'the move' . ($furn !== '' ? " to the $furn" : '') . ' did not happen',
        'faster' => 'the pace did not change', 'slower' => 'the pace did not change', 'speed' => 'the pace did not change',
        'stop' => 'it did not stop', 'winddown' => 'winding down did not happen', 'climax' => 'that did not happen',
        'hold' => 'holding back did not happen', 'release' => 'letting go did not happen', 'lead' => 'who leads did not change',
        'pullout' => 'that did not happen'];
    return $map[$do] ?? 'what was asked for did not happen';
}

/**
 * [pt19h-quest] Is this ExtCmdLRG_QuestEntry result a quest-entry TABLE row (config/lrg_quest_entries.default.json), a quest step
 * rather than an enlistment? Read from the qe_lic record lrgFacQuestNet left (the funcret is decided PRE-LOCK, where
 * lib/lrg_factions.php is not loaded), else from the table itself when it is.
 */
function lrgQeIsTableRow(array $vr): bool
{
    if (strcasecmp((string) ($vr['code'] ?? ''), LRG_ACT_QUESTENTRY) !== 0) { return false; }
    $kv = (array) ($vr['kv'] ?? []);
    $qeLic = function_exists('lrgMemGet') ? (array) (lrgMemGet((string) ($vr['npc'] ?? ''))['qe_lic'] ?? []) : [];
    if (!empty($qeLic['table']) && strcasecmp((string) ($qeLic['quest'] ?? ''), (string) ($kv['quest'] ?? '')) === 0
        && (int) ($qeLic['stage'] ?? 0) === (int) ($kv['stage'] ?? 0)) { return true; }
    return function_exists('lrgFacEffectFor') && !empty(lrgFacEffectFor((string) ($kv['quest'] ?? ''), (int) ($kv['stage'] ?? 0))['table']);
}

/**
 * The game's reason, as a person would put it. The game's reasons are a closed list (PROTOCOL 1.6 and
 * 10.20); the ones that are plain English already are used as they are, the technical ones become one
 * neutral phrase, and nothing here ever names the game, a command or an error.
 */
function lrgVoicedWhy(array $vr): string
{
    $reason = trim((string) ($vr['reason'] ?? ''));
    $npc = (string) ($vr['npc'] ?? '');
    $player = trim((string) ($GLOBALS['PLAYER_NAME'] ?? '')) ?: 'the player';
    // [pt19-purchase] ExtCmdLRG_Buy's technical reasons (LRG_Main.CmdBuy) in her words: the live price when it moved, the purse
    // against the total, "out of it", "sells nothing", "not at this hour" - never a form id, a faction or the game
    if (strcasecmp((string) ($vr['code'] ?? ''), LRG_ACT_BUY) === 0) {
        $mw = lrgMktVoicedWhy($reason, $npc, $player);
        if ($mw !== '') { return $mw; }
    }
    // [pt18-quest] ExtCmdLRG_QuestEntry's technical reasons (LRG_Main.CmdQuestEntry) in her words - never a quest id, a
    // stage, a global or the game. MQ101 is Alternate Perspective's Helgen on this load order (lib/lrg_factions.php).
    if (preg_match('/\bwaits on (\S+) being complete\b/i', $reason, $m)) {
        return strcasecmp((string) $m[1], 'MQ101') === 0
            ? 'his road into the Legion begins at Helgen, and that is not behind him yet'
            : 'there is something he must see through before that road opens to him';
    }
    if (preg_match('/ is already past that\b/i', $reason)) {
        // [pt19h-quest r2 / arch P4] a quest-entry TABLE row's step may never have been taken - another branch moved the quest on
        // (MQ301 at 20 from Paarthurnax, Esbern's plan at 18 never done): only "further on" is true there
        return lrgQeIsTableRow($vr) ? 'that matter already stands further on than that step' : 'that step is already behind him';
    }
    if (preg_match("/^he has already had the other side's introduction/i", $reason)) {
        return "he has already been given the other side's introduction, and that closes this line of hers";
    }
    if (preg_match('/^that is not really /i', $reason)) { return 'that was not really him'; }
    // [pt19 / script 511, review] QUIET MODE: the game's two refusals while a scripted intro runs - LRG_Main.CmdQuestEntry
    // err= "quiet mode: MQ101 stage <n> is running - the glue touches no quest while it does" and LRG_Main.CmdEscort
    // "<name> will not do that now (a scripted intro)" - in her words (NEVER SILENT: the real reason, never the quest id,
    // the stage or the glue). Before the technical filter below, which would turn "glue" into "it just would not work".
    if (stripos($reason, 'quiet mode:') === 0 || preg_match('/\(a scripted intro\)\s*$/i', $reason)) {
        $qid = function_exists('lrgQuietFor') ? (string) (lrgQuietFor($npc)['id'] ?? '') : '';
        if ($qid === '' && stripos($reason, 'MQ101') !== false) { $qid = 'MQ101'; }
        return $qid === 'MQ101' ? 'she is in the middle of the Helgen business and cannot leave it until it is over'
            : 'she is in the middle of something important that she cannot walk away from until it is over';
    }
    if (preg_match('/ is not running$|^the quest \S+ is not in this game$|^the game refused stage |^unknown quest entry request$/i', $reason)) {
        return 'it just would not work right now';
    }
    // [pt17 / script 509] the escort's "comes along, but not as his companion" reasons (LRG_Followers.SffRefuseReason,
    // fb= on the OK funcret) in her words: the framework's truths, never the framework's name
    if (preg_match('/^you have no free companion slot \((\d+)\/(\d+) at speech (\d+)\)/i', $reason, $m)) {
        return (int) $m[2] <= 1 ? "$player can lead only one companion at a time, and that place is taken"
            : "$player already leads as many companions as he can ({$m[1]} of {$m[2]})";
    }
    if (strcasecmp($reason, 'the game does not let you recruit her yet') === 0) { return 'she is not somebody who can be taken on as a companion yet'; }
    if (stripos($reason, 'she is a hireling') === 0) { return 'she is a hireling, and her price comes first, the way it always does'; }
    if (strcasecmp($reason, 'the framework did not take her') === 0 || stripos($reason, 'simple follower framework') === 0) {
        return 'nothing could make her a real companion just now';
    }
    if (strcasecmp($reason, "she already travels with you as somebody else's companion") === 0) {
        return "she already travels with $player as a companion, and that goes through her own companion orders";
    }
    // [game 507] the escort's LATE failure: "she went back to her scene (<quest>) and stays - <why>" (TickEscort) -
    // the same fact as a scene that kept starting again, never the quest's EditorID
    if (preg_match('/^(?:she|he|they) went back to (?:her|his|their) scene\b/i', $reason)) {
        $reason = ($npc !== '' ? $npc : 'she') . ' is in the middle of something she cannot leave';
    }
    if (preg_match('/in the middle of something (?:she|he|they) cannot leave(?:\s*\((.*)\))?\s*$/i', $reason, $m)) {
        $sub = strtolower((string) ($m[1] ?? ''));
        try { $st = lrgGetNpcState($npc); } catch (Throwable $e) { $st = null; }
        $bard = str_contains(strtolower((string) (($st ?? [])['fac'] ?? '')), 'bard');
        $thing = $bard ? 'a performance' : 'something';
        if (str_contains($sub, 'journal') || str_contains($sub, 'main, faction') || str_contains($sub, 'identify')) {
            return "she is in the middle of something important that she cannot walk away from";
        }
        if ($sub === '') { return "she is in the middle of $thing and keeps being pulled back into it"; }
        return "she is in the middle of $thing that she cannot just leave";
    }
    if (preg_match('/will not do that now \((.*)\)\s*$/i', $reason, $m)) {
        $s = strtolower((string) $m[1]);
        if (str_contains($s, 'follower')) { return "she already travels with $player as a companion, and that goes through her own companion orders"; }
        if (str_contains($s, 'hostile')) { return "she is hostile to $player"; }
        if (str_contains($s, 'combat')) { return 'there is fighting'; }
        if (str_contains($s, 'arrest')) { return "she is dealing with $player as a lawbreaker"; }
        if (str_contains($s, 'scene with you')) { return "the two of them are in the middle of something together"; }
        return 'she will not do that right now';
    }
    $lc = strtolower($reason);
    // [game 507] a clothing command that changed nothing keeps its old technical reason for old servers; what it
    // really means is that there was nothing left to take off / to put back on (LRG_OStim.ClothingNothingSay)
    if ($lc === 'not possible in this position' && strcasecmp((string) ($vr['code'] ?? ''), LRG_ACT_CLOTHING) === 0) {
        return ($vr['do'] ?? '') === 'dress' ? 'there was nothing to put back on' : 'there was nothing left to take off';
    }
    if ($lc === 'unreachable') { return 'that position cannot be reached from here'; }
    // [pt19 v1.0 / S7] the dialogue driver's own reasons (LRG_Dialogue.psc, the closed list; LRG_Main.SayReason says the same
    // words game-side) - hers, in the first person, each naming what he can do; never a machinery word
    $dlg = ['the entry moved before the click' => 'I lost the thread - say that again',
        'the click did not take' => 'that did not take - choose it on the menu',
        'the list could not be read' => 'I did not catch what we could talk about - choose it on the menu yourself',
        'choose that one on the list yourself' => 'choose that one on the list yourself',
        'her voice is not ready' => 'give me a moment',
        'the conversation was interrupted' => 'we were interrupted - ask me again',
        'nothing came of that' => 'nothing came of that'];
    if (isset($dlg[$lc])) { return $dlg[$lc]; }
    if (str_starts_with($lc, 'stage rail')) {
        return 'I have not picked a line for you yet - ask me something simple first, a question, then I can pick this one';
    }
    $map = [
        'someone is watching' => 'somebody is watching', 'a companion is present' => "$player's companion is right there",
        'a child is nearby' => 'a child is nearby', 'combat' => 'there is fighting',
        'a quest scene is running' => 'something important is going on around her', 'too far apart' => 'they are too far apart',
        'no scene is running' => 'nothing is going on between them any more', 'a scene is already running' => 'something is already going on',
        'a scene is just starting' => 'it is only just starting', 'a scene with someone else is running' => "$player is already busy with somebody else",
        'still moving into the previous position' => 'they are still in the middle of the last change - it can be asked again in a moment',
        'the scene is still starting' => 'it is only just starting - it can be asked again in a moment',
        'in the middle of a transition' => 'they are still moving - it can be asked again in a moment',
        'already there' => 'they are already doing exactly that',
        'the player does not have that much gold' => "$player cannot pay what was agreed", 'not enough gold' => "$player cannot pay what was agreed",
        'they cannot talk right now' => 'she cannot talk right now',
        'the glue is still starting after the load - say it again in a moment' => 'it needs a moment - it can be asked again shortly',
    ];
    if (isset($map[$lc])) { return $map[$lc]; }
    // [pt17] the dry runs are NAMED, before the technical map below would hide them as "it just would not work"
    $dryWhy = lrgVoicedDryWhy($reason);
    if ($dryWhy !== '') { return $dryWhy; }
    $technical = ['ostim is not installed', 'not available', 'not authorised by the server gate', 'the actor could not be found',
        'ostim does not accept these actors', 'ostim rejected the actors', 'the scene did not start', 'this part of the glue did not start this session',
        'that is not on the table right now', 'that moment has passed'];
    if ($lc === '' || in_array($lc, $technical, true) || str_starts_with($lc, 'unknown ') || str_contains($lc, ' is not here')
        // [game 507] a reason about the machinery itself never reaches her mouth: OStim's weapon setting, CHIM's follow
        // package that could not be put on, a dry run, a part of the glue that did not start
        // [pt19 v1.0 / language brief 6.2] ... nor the dialogue machinery: the index, a topic, a session, the calibration, a park,
        // a rail, the matcher, a T-key, an EditorID
        || preg_match('/\b(?:ostim|chim|mcm|glue|script|plugin|dry-?run|package|index|topic|session|calibrat\w*|park(?:ed)?|rail|matcher|t-key|editorid)\b|\.esp\b|cannot follow you:/i', $reason)) {
        return 'it just would not work right now';
    }
    return $reason; // the rest of the closed list is plain English already ("that position cannot be reached from here")
}

/** "<what> - <why>": the one fact a voiced turn answers. */
function lrgVoicedFact(array $vr): string
{
    return lrgVoicedWhat($vr) . ' - ' . lrgVoicedWhy($vr);
}

/**
 * [pt17] Which dry run a technical reason names: 'dev' (bDryRun:General, the developer switch - every wording the
 * game has ever sent: "dry-run mode, nothing changed", "dry-run mode, nothing was started", "the glue is in dry-run
 * mode - nothing was changed", "dry-run mode is ON in the LoreRim Glue MCM (Diagnostics page) ..."), 'learning'
 * (the menuless dry run FORCED by a red calibration: "still learning the dialogue menu - the next conversation of any kind
 * measures it"), 'menuless'
 * (the owner's own bDlgDryRun: "the menuless dry run is on (Menuless questing page) ..."), or '' (not a dry run).
 */
function lrgVoicedDryKind(string $reason): string
{
    $lc = strtolower($reason);
    if (str_contains($lc, 'still learning the dialogue menu')) { return 'learning'; }
    if (str_contains($lc, 'menuless dry run')) { return 'menuless'; }
    // [pt17 review] "dry run" with a space too - the game side (LRG_Main.SayReason) matches both spellings
    if (preg_match('/dry[- ]?run/', $lc)) { return 'dev'; }
    return '';
}

/** [pt17] The plain words for a dry-run refusal - the switch and the page, or the learning count; '' otherwise. */
function lrgVoicedDryWhy(string $reason): string
{
    $kind = lrgVoicedDryKind($reason);
    if ($kind === 'learning') {
        // [pt19c-B fix 1 / game review] the STUCK case (LRG_Dialogue.DlgDryReason when CalLearnable() is false: "... - another
        // conversation will not finish it: <why>") promises nothing - "the next conversation settles it" was false there, the
        // owner's nightly "still learning" complaint in a new form
        if (str_contains(strtolower($reason), 'will not finish it')) {
            return 'that cannot be taken up by voice for now - the menu itself still works and he can choose it there';
        }
        // [pt19 v1.0 / S3, S7] four passive rows, learnt from the next menu of any kind: no count is told (the game sends none),
        // and no machinery word
        return 'that cannot be taken up by voice yet - the next conversation of any kind settles it; the menu itself still'
            . ' works and he can choose it there';
    }
    if ($kind === 'menuless') {
        return 'the menuless-questing dry run is on in the LoreRim Glue MCM (Menuless questing page), so nothing is'
            . ' clicked for him - the menu itself still works and he can choose it there';
    }
    if ($kind === 'dev') {
        return 'the LoreRim Glue developer dry-run switch is on in its MCM (Diagnostics page), so nothing it is asked'
            . ' to do can happen until it is switched off';
    }
    return '';
}

/** The instruction of a voiced turn: what happened, plainly; ONE short line; the real reason; no pretending. */
function lrgVoicedDirective(array $vr): string
{
    $npc = (string) ($vr['npc'] ?? '') ?: 'she';
    $fact = (string) ($vr['fact'] ?? '') ?: lrgVoicedFact($vr);
    // [pt17] a dry run is the one reason that MAY name the game's own settings: it is the owner's switch and he has
    // to find it (owner addendum 11: the real reason, never silence). The menuless wordings stay in-world.
    $kind = lrgVoicedDryKind((string) ($vr['reason'] ?? ''));
    $rule = $kind === 'dev'
        ? "say plainly that the LoreRim Glue dry-run switch is on in its settings, on the Diagnostics page - this is the one"
            . ' time the game\'s own settings may be named'
        : ($kind !== ''
            ? 'say plainly that this cannot be taken up by voice yet and that the menu still works - never a word about commands or errors'
            : 'never a word about the game, commands or errors');
    if (!empty($vr['success'])) {
        // [pt18-quest] the game recorded the step and nobody has said so yet (no licence pre-LLM): the outcome, in ONE
        // line, exactly what was recorded - the row's own `say` names the next step - and nothing beyond it
        $lead = trim((string) ($vr['directive'] ?? ''));
        return "What just happened: $fact. $npc says so now, in ONE short line in $npc's own voice" . ($lead !== '' ? " - $lead" : '')
            . " - never a word about the game, quests, stages, commands or errors. $npc promises nothing beyond it and makes no speech of it.";
    }
    if (!empty($vr['partial'])) {
        // [pt17] she DID come along: say so, and why not as his companion - no pretending she has joined him, no speech
        $player = trim((string) ($GLOBALS['PLAYER_NAME'] ?? '')) ?: 'the player';
        return "What just happened: $fact. $npc is coming along - $npc says so now, in ONE short line in $npc's own voice, and says"
            . " plainly that she comes along but not as $player's sworn companion, with the real reason in plain words, $rule."
            . " $npc does not pretend she has joined him, and makes no speech of it.";
    }
    return "What just happened: $fact. $npc says so now, in ONE short line in $npc's own voice - the real reason, in plain"
        . " words, $rule. $npc does not pretend it happened, does not promise it, and"
        . ' makes no speech of it.';
}

/**
 * The cue of this request's voiced funcret turn for the NPC CHIM is voicing, or null. prompts.php puts it
 * into $PROMPTS['afterfunc']['cue'][<code>], which processor/request.php makes the turn's last user line.
 */
function lrgVoicedCue(string $npc): ?array
{
    $vr = $GLOBALS['LRG_VOICED'] ?? null;
    if (!is_array($vr) || $npc === '' || strcasecmp((string) $vr['npc'], $npc) !== 0) { return null; }
    return ['code' => (string) $vr['code'], 'cue' => '(' . lrgVoicedDirective($vr) . ')'];
}

/** Will CHIM really run the follow-up turn for this code? (The rows predate 0.5.5 until the first turn repairs them.) */
function lrgChimWillVoice(string $code): bool
{
    if (function_exists('herikaActionCatalogGetResolvedFollowupConfig')) {
        $c = (array) herikaActionCatalogGetResolvedFollowupConfig($code);
        return !empty($c['enabled']) && trim((string) ($c['prompt'] ?? '')) !== '';
    }
    if (function_exists('herikaGetActionCatalogRow')) {
        $row = herikaGetActionCatalogRow($code);
        if (!is_array($row)) { return false; }
        $md = $row['metadata'] ?? [];
        if (is_string($md)) { $md = json_decode($md, true) ?: []; }
        $f = (array) (((array) $md)['followup'] ?? []);
        return !empty($f['enabled']) && trim((string) ($f['prompt'] ?? '')) !== '';
    }
    return true; // no catalog to ask (offline tests): assume the rows are current
}

/**
 * [0.5.5] Called from context_pre.php on a funcret that preprocessing decided to voice. '' = CHIM's turn
 * goes ahead. Anything else = it must not: the request is for another character than the one the result
 * belongs to, or CHIM's row for the code has no follow-up yet. The result is then told on her next turn
 * after all (told=false again) and the caller ends the request.
 */
function lrgVoicedGate(): string
{
    $vr = $GLOBALS['LRG_VOICED'] ?? null;
    if (!is_array($vr)) { return ''; }
    $who = (string) ($GLOBALS['HERIKA_NAME'] ?? '');
    $why = '';
    if (strcasecmp($who, (string) $vr['npc']) !== 0) { $why = "the request is for '$who', not for " . $vr['npc']; }
    elseif (!lrgChimWillVoice((string) $vr['code'])) { $why = "CHIM's catalog row for " . lrgShortCode((string) $vr['code']) . ' has no follow-up yet'; }
    if ($why === '') { return ''; }
    $lr = lrgMemGet((string) $vr['npc'])['last_result'] ?? null;
    if (is_array($lr)) { lrgMemSet((string) $vr['npc'], ['last_result' => array_merge($lr, ['told' => false]), 'voiced_at' => null]); }
    lrgLog("voiced: dropped - $why; told on her next turn instead", (string) $vr['cid']);
    unset($GLOBALS['LRG_VOICED']);
    return $why;
}

/**
 * [0.5.5 / addendum 11 (c)] CHIM's own movement actions fail in silence: FollowPlayer against an engine
 * scene, ComeCloser / MoveTo / TravelTo on an NPC who cannot or does not move. Where the escort does not
 * already carry it, the glue notes the order here and the next snapshots judge it (lrgMoveWatchCheck in
 * lrg_core.php): no LLM call, no wire line, one memory write per order.
 */
function lrgMoveWatchNote(array $turn, array $out): void
{
    if (!lrgVoiceCfg('watch.enabled', true)) { return; }
    $npc = (string) ($turn['npc'] ?? '');
    if ($npc === '' || !in_array((string) ($turn['type'] ?? ''), LRG_PLAYER_SPEECH_TYPES, true)) { return; }
    if (in_array((string) ($turn['mode'] ?? ''), ['scene', 'outro'], true)) { return; }
    $escort = false;
    $move = '';
    foreach ($out as $line) {
        $c = lrgLineCode((string) $line);
        if (strcasecmp($c, LRG_ACT_ESCORT) === 0) { $escort = true; continue; }
        $parts = explode('|', rtrim((string) $line, "\r\n"), 3);
        if (count($parts) < 3 || strcasecmp(trim($parts[0]), $npc) !== 0) { continue; }
        $k = strtolower(str_replace('_', '', $c));
        if ($move === '' && in_array($k, ['comecloser', 'moveto', 'travelto'], true)) { $move = $c; }
        elseif ($move === '' && lrgIsChimFollowLine((string) $line, $npc)) { $move = 'FollowPlayer'; }
    }
    if ($move === '' || ($move === 'FollowPlayer' && $escort)) { return; } // the escort's own funcret answers a follow
    try { $st = lrgGetNpcState($npc); } catch (Throwable $e) { $st = null; }
    $dist = is_array($st) && isset($st['dist']) && is_numeric($st['dist']) ? (int) $st['dist'] : null;
    lrgMemSet($npc, ['move_watch' => ['code' => $move, 'at' => lrgNow(), 'cid' => (string) ($turn['cid'] ?? ''),
        'dist0' => $dist, 'scene0' => is_array($st) ? (int) (($st['scene'] ?? '0') === '1') : 0]]);
    lrgLog("watch: $move npc=$npc - the next snapshots say whether she really moved" . ($dist !== null ? " (dist $dist)" : ''), (string) ($turn['cid'] ?? ''));
}

/**
 * [0.5.5] The game's log line for the escort's LATE failure (LRG_Main.TickEscort, script 506):
 * "escort <npc>: she went back to her scene (<quest>) and stays - <why>". Remembered as missed.
 */
function lrgNoteEscortLate(string $msg, string $cid = ''): void
{
    if (!preg_match('/^escort (.+?): she went back to her scene \((.*?)\) and stays(?: - (.*))?$/', $msg, $m)) { return; }
    $npc = trim((string) $m[1]);
    if ($npc === '') { return; }
    // [game 507] the same failure also comes as a late Error: funcret (late=1). When that one was already
    // SPOKEN, this log line must not tell her a second time on her next turn (the other order is handled in
    // lrgFuncretVerdict, which clears this note when it voices the late result).
    $lr = lrgMemGet($npc)['last_result'] ?? null;
    if (is_array($lr) && strcasecmp((string) ($lr['cmd'] ?? ''), LRG_ACT_ESCORT) === 0 && !empty($lr['late']) && !empty($lr['told'])
        && lrgNow() - (int) ($lr['at'] ?? 0) <= 60) {
        lrgLog("missed: escort npc=$npc - already said in her funcret turn (late=1), nothing more to tell", $cid);
        return;
    }
    $why = trim((string) ($m[3] ?? ''));
    lrgMemSet($npc, ['missed' => ['what' => 'come along with ' . (trim((string) ($GLOBALS['PLAYER_NAME'] ?? '')) ?: 'the player'),
        'why' => lrgVoicedWhy(['npc' => $npc, 'reason' => "$npc is in the middle of something she cannot leave" . ($why !== '' && !str_contains($why, 'keeps starting') ? " ($why)" : '')]),
        'at' => lrgNow(), 'code' => LRG_ACT_ESCORT]]);
    lrgLog("missed: escort npc=$npc - the game left her in her scene after all; she is told on the next player turn", $cid);
}

/**
 * The note for her next player turn when an order of hers visibly failed (lrgMoveWatchCheck, the escort's
 * late failure). '' = nothing to say. It permits nothing and names no action: it only keeps her from
 * acting as if it had worked, and gives her the reason for when he asks.
 */
function lrgMissedNote(array $turn): string
{
    $m = $turn['missed'] ?? null;
    if (!is_array($m)) { return ''; }
    $npc = (string) ($turn['npc'] ?? '') ?: 'she';
    return "$npc did not manage to " . (string) ($m['what'] ?? 'do what was asked') . ' just now (' . (string) ($m['why'] ?? 'it did not work')
        . "). $npc does not act as if it had worked; if the player asks about it, $npc says why in one short line.";
}

/**
 * The NPC a request is for, usable at main.php:193 where HERIKA_NAME is not loaded yet: CHIM addresses the profile
 * by md5 (main.php:295-297 does the same lookup). Falls back to HERIKA_NAME (offline tests, CLI).
 */
function lrgResolveRequestNpc(): string
{
    $name = (string) ($GLOBALS['HERIKA_NAME'] ?? '');
    $md5 = (string) ($_GET['profile'] ?? '');
    if ($md5 === '' || !preg_match('/^[a-f0-9]{32}$/i', $md5) || ($name !== '' && strcasecmp(md5($name), $md5) === 0)) { return $name; }
    $db = lrgDb();
    if ($db) {
        $row = $db->fetchOne("SELECT npc_name FROM core_npc_master WHERE md5=" . $db->escapeLiteral($md5) . " LIMIT 1");
        if (is_array($row) && !empty($row['npc_name'])) { return (string) $row['npc_name']; }
    }
    return $name;
}

/**
 * R2 (PROTOCOL 6.3): may this NPC make a move of her own right now? Runs before the MAIN lock, so an uninterested
 * NPC costs no LLM call. Returns ['admit' => bool, 'kind' => follow|own, 'word' => interest word, 'why' => reason].
 */
function lrgInitiativeAdmit(string $npc, string $text = 'approach'): array
{
    $cfg = lrgConfig()['initiative'] ?? [];
    $no = static fn(string $why) => ['admit' => false, 'kind' => '', 'word' => '', 'why' => $why];
    if (!lrgEnabled() || defined('LRG_SHARMAT_PRESENT') || !($cfg['enabled'] ?? true)) { return $no('switched off'); }
    if (!lrgIsTickText($text, 'approach')) { return $no('unknown text'); }
    // [0.4.2 / PT9] The two cheapest refusals first, both before any query: her own move must never queue
    // behind - or in front of - a line the player is waiting for. Measured: 3 of 12 ticks spoke and each
    // cost 7.0-10.4 s of MAIN-held LLM + TTS.
    $busy = lrgTickBusyReason('initiative');
    if ($busy !== '') { return $no($busy); }
    lrgEnsureSchema();
    $quiet = lrgLatencyLive() ? (int) (lrgLatencyCfg('initiative')['player_quiet_seconds'] ?? 10) : 0;
    if ($quiet > 0 && $npc !== '') {
        $ago = lrgPlayerSpokeAgo($npc);
        if ($ago >= 0 && $ago < $quiet) { return $no('the player spoke ' . $ago . 's ago (quiet window ' . $quiet . 's)'); }
    }
    $gate = lrgEvaluateGates($npc, 'lrg_initiative', true);
    $interest = $gate['interest'] ?? null;
    if (!$interest || !$interest['willing']) { return $no('not willing / ' . (implode(',', $gate['reasons']) ?: '-')); }
    $mem = lrgMemGet($npc);
    $invite = lrgInviteGet($npc, true, $mem);
    $mode = lrgGateMode($gate, $invite);
    $since = lrgNow() - (int) ($mem['initiative_at'] ?? 0);
    $kind = '';
    if ($mode === 'follow') {
        if ($since < (int) ($cfg['follow_gap_seconds'] ?? 30)) { return $no('follow-through: too soon after the last tick'); }
        $kind = 'follow';
    } elseif (($mode === 'public' || $mode === 'private') && $interest['may_initiate']) {
        if ($mode === 'public' && $invite !== null) { return $no('she has already invited the player'); }
        if ($since < (int) ($cfg['cooldown_seconds'] ?? 300)) { return $no('cooling down'); }
        // [0.3 / G3d] An admitted initiative tick IS the build-up (11.2 rule 4), so the two cooldowns
        // that guard the BeginIntimacy offer have to be checked HERE, before the MAIN lock. Otherwise a
        // tick 14 s after a reload would pay for an LLM call and a TTS line that can do nothing at all.
        $start = (array) (lrgConfig()['start'] ?? []);
        if (lrgNow() - (int) ($mem['last_scene_end_at'] ?? 0) < (int) ($start['post_scene_cooldown_seconds'] ?? 300)) { return $no('too soon after the last scene'); }
        if (lrgNow() - (int) ($mem['session_at'] ?? 0) < (int) ($start['post_reload_cooldown_seconds'] ?? 120)) { return $no('the game has only just loaded'); }
        $pace = lrgPace($gate['profile']);
        $chance = (int) ((($cfg['chance_percent'] ?? []) + ['slow' => 15, 'normal' => 30, 'eager' => 50])[$pace] ?? 30);
        if (lrgRoll('initiative') > $chance) { return $no("dice ($pace)"); }
        $kind = 'own';
    } else {
        return $no("mode $mode, interest " . $interest['word']);
    }
    lrgMemSet($npc, ['initiative_at' => lrgNow(), 'interest' => $interest['word'], 'interest_score' => $interest['score']]);
    return ['admit' => true, 'kind' => $kind, 'word' => $interest['word'], 'why' => ''];
}

/**
 * Body of the ext prerequest.php hook (main.php:1117): switch actions back on for the two non-speech requests on
 * which the NPC may act - the scene-lead tick and an admitted initiative tick. lrgPrepareTurn() strips the offer down
 * to the actions of that mode and the post-LLM gate still checks every action. Gives the same result whether it runs
 * before lrgPrepareTurn() (production) or after it (offline tests).
 */
function lrgPrerequest(): void
{
    if (!lrgEnabled() || defined('LRG_SHARMAT_PRESENT')) { return; }
    $npc = (string) ($GLOBALS['HERIKA_NAME'] ?? '');
    if ($npc === '') { return; }
    $type = strtolower((string) ($GLOBALS['gameRequest'][0] ?? ''));
    $on = false;
    if (lrgIsLeadTick()) {
        $scene = lrgGetActiveScene();
        $on = $scene !== null && strcasecmp((string) $scene['_npc'], $npc) === 0 && lrgLeadAllowed($scene)
            && (lrgGetNpcState($npc)['adult'] ?? '') === '1';
    } elseif ($type === 'lrg_initiative') {
        $on = strcasecmp((string) ($GLOBALS['LRG_INITIATIVE']['npc'] ?? ''), $npc) === 0;
    }
    if (!$on) { return; }
    $GLOBALS['FUNCTIONS_ARE_ENABLED'] = true;
    // the functions.php hook may already have run with actions off (or not at all): decide the offer again now
    if (isset($GLOBALS['ENABLED_FUNCTIONS'])) { lrgPrepareTurn(); }
}

// ---------------------------------------------------------------- the turn
/**
 * Runs once per request from the ext functions.php hook: decide the turn mode, what is offered, and
 * remember it for the post-LLM gate and for context_pre.php. Idempotent within one request.
 */
function lrgPrepareTurn(): void
{
    $npc = (string) ($GLOBALS['HERIKA_NAME'] ?? '');
    $type = strtolower((string) ($GLOBALS['gameRequest'][0] ?? ''));
    $req = md5($npc . '|' . json_encode($GLOBALS['gameRequest'] ?? []));
    $prev = $GLOBALS['LRG_TURN'] ?? null;
    $same = is_array($prev) && ($prev['req'] ?? '') === $req; // second pass of the same request (prerequest / context_pre): keep cid, dice and one-shot notes
    $turn = ['npc' => $npc, 'type' => $type, 'cid' => $same ? $prev['cid'] : 's' . substr(md5(uniqid('', true)), 0, 8), 'req' => $req,
        'mode' => 'silent', 'gate' => null, 'scene' => null, 'options' => [], 'can_act' => false, 'lead' => false, 'progress' => null, 'profile' => null, 'ctx' => null,
        'interest' => null, 'invite' => null, 'initiative' => false, 'places' => [], 'x' => null, 'announce' => false, 'furn_options' => [],
        'discreet' => false, 'fail' => $same ? ($prev['fail'] ?? null) : null, 'lead_idle' => 0, 'scene_blocked' => false, 'offered' => [],
        // [0.3]
        'intent' => lrgNoIntent(), 'directive' => '', 'acts' => [], 'propose' => null, 'heat' => 0, 'blunt' => false,
        'hidden' => [], 'say_first' => false, 'scene_confirmed' => false,
        // [0.3.1] R4: the player's own words are logged on EVERY turn, closed and silent ones included
        // (playtest 7 logged say="" for the whole eighteen-minute closed stretch). R3: the outro ticket.
        'said' => '', 'outro' => null,
        // [0.5.2 / pt10] blind = the turn is silent ONLY because the game has not reported in
        // (reasons = no_fresh_snapshot), and the last facts it ever sent do not themselves say
        // "off limits". blind_note = ... and it has really reported on her at some point, so the model
        // may be told why the glue is saying nothing. Never true for a silence the snapshot caused.
        'blind' => false, 'blind_note' => false,
        // [0.5.4 / pt13] the escort facts (lrgEscortFacts), filled on a turn that asked for follow / wait / release
        'escort' => null,
        // [0.5.5 / owner addendum 11] voiced = the lrgFuncretVerdict() record on a VOICED funcret turn;
        // missed = an order of hers that visibly failed (lrgMoveWatchCheck / the escort's late failure)
        'voiced' => null, 'missed' => null];

    if (!lrgEnabled() || defined('LRG_SHARMAT_PRESENT')) {
        // kill switch / feature off, or another intimacy plugin with a different consent model is installed: total silence
        lrgHideActions(LRG_GLUE_ACTIONS);
        $GLOBALS['LRG_TURN'] = $turn;
        return;
    }
    lrgEnsureSchema();
    lrgEnsureActions();
    if (!$same) { lrgCommandWatchdog($npc, $turn['cid']); }
    // [0.5.5 / owner addendum 11] THE VOICED TURN: CHIM's own funcret follow-up turn for a failure the player
    // has to hear about (lrgFuncretVerdict). She only talks: no glue action (CHIM switches every action off
    // on it anyway), no gate, no intimacy text - the cue (lrgVoicedCue, prompts.php) is the whole instruction.
    $vr = $GLOBALS['LRG_VOICED'] ?? null;
    if ($type === 'funcret' && is_array($vr) && strcasecmp((string) $vr['npc'], $npc) === 0) {
        $turn['mode'] = 'voiced';
        $turn['voiced'] = $vr;
        lrgHideActions(LRG_GLUE_ACTIONS);
        foreach (LRG_GLUE_ACTIONS as $code) { $turn['hidden'][$code] = 'voiced turn'; }
        $GLOBALS['LRG_TURN'] = $turn;
        return;
    }
    // [0.3.1 / R4] the player's words belong in the log whatever the turn turns out to be
    if (in_array($type, LRG_PLAYER_SPEECH_TYPES, true) || in_array($type, LRG_NARRATOR_SPEECH_TYPES, true)) {
        $turn['said'] = substr(lrgIntentClean((string) ($GLOBALS['gameRequest'][3] ?? '')), 0,
            (int) (lrgConfig()['intent']['log_utterance_chars'] ?? 160));
    }
    // [0.3.1 / R1d] the one-time, config-driven affinity repair. It has to happen inside a live game
    // turn so CHIM's timeline stamp lands on the save the owner is really playing.
    if (!$same && !empty($GLOBALS['gameRequest'])) { lrgMaybeRepairAffinity($npc); }
    // [0.4 / F2, owner complaint 1] The "left in OStim director mode" alarm. A player line that CHIM
    // routed to the Narrator right after a scene is the exact shape of the defect: the crosshair is
    // empty when the camera comes back, CHIM's router falls through to no_eligible_npc, and the glue
    // then goes silent on top of it because narrator_inputtext is not player speech. One WARN line, no
    // behaviour change - it is how the owner will see the listener hold doing its job.
    if (!$same) { lrgWarnNarratorAfterScene($npc, $type, $turn['cid']); }

    $scene = lrgGetActiveScene();
    $inSceneWithThisNpc = $scene !== null && strcasecmp((string) $scene['_npc'], $npc) === 0;
    $state = $inSceneWithThisNpc ? lrgGetNpcState($npc) : null;
    $mem = null;
    // A scene row can exist without the glue ever having gated anything (OStim's own menu is a supported
    // start path), and a snapshot goes on arriving while the scene runs. So every hard rail is re-checked
    // HERE, on every scene turn, exactly as it is outside a scene: a fresh snapshot, a confirmed adult,
    // no child in the radius, the owner's intimacy switch on, and an NPC who is not opted out.
    $sceneOk = false;
    $adultKnown = $inSceneWithThisNpc && $state !== null && ($state['adult'] ?? '') === '1';
    if ($adultKnown) {
        $maxAge = (int) (lrgConfig()['snapshot_max_age_seconds'] ?? 90);
        $sceneOk = (int) ($state['_age'] ?? 9999) <= $maxAge
            && ($state['witkid'] ?? '1') === '0'
            && ($state['on'] ?? '0') === '1'
            && empty(lrgBuildProfile($npc, $state)['never']);
    }
    // [0.3.1 / R3] THE OUTRO TURN. The scene row is already closed by the time the game fires its
    // `outro` request, so this can never be reached while a scene runs. Every hard rail is re-checked
    // here exactly as on a scene turn - a fresh snapshot, a confirmed adult, no child nearby, the
    // owner's switch on, the profile not `never` - and the turn offers NO glue action at all: she only
    // talks. It fires at most once per scene: the ticket is consumed below.
    $outroTicket = (!$inSceneWithThisNpc && lrgIsOutroTick()) ? lrgOutroTicket($npc) : null;
    if ($outroTicket !== null) {
        $st = lrgGetNpcState($npc);
        $maxAge = (int) (lrgConfig()['snapshot_max_age_seconds'] ?? 90);
        $ok = $st !== null && (int) ($st['_age'] ?? 9999) <= $maxAge && ($st['adult'] ?? '') === '1'
            && ($st['witkid'] ?? '1') === '0' && ($st['on'] ?? '0') === '1'
            && empty(lrgBuildProfile($npc, $st)['never']);
        if (!$ok) {
            lrgLog('outro dropped for ' . $npc . ': the rails do not hold (no fresh adult snapshot, a child nearby, or the feature is off)', $turn['cid']);
            lrgMemSet($npc, ['outro' => null]);
            $outroTicket = null;
        } else {
            $turn['mode'] = 'outro';
            $turn['outro'] = $outroTicket;
            $turn['profile'] = lrgBuildProfile($npc, $st);
            $turn['gate'] = ['ok' => false, 'reasons' => [], 'soft' => [], 'profile' => $turn['profile'], 'state' => $st,
                'affinity' => 0, 'interest' => null];
            $turn['x'] = lrgExplicitLevel(null, $st);
            $turn['discreet'] = lrgIsDiscreet($turn['profile'], $st, []);
            $turn['can_act'] = false;
            if (!$same) { lrgMemSet($npc, ['outro' => null]); } // one outro per scene, whatever happens next
        }
    }
    if ($outroTicket !== null) {
        lrgHideActions(array_merge(LRG_GLUE_ACTIONS, LRG_MOVEMENT_ACTIONS));
        foreach (LRG_GLUE_ACTIONS as $code) { $turn['hidden'][$code] = 'outro turn'; }
    } elseif ($inSceneWithThisNpc && !$adultKnown) {
        // the hardest rail: without a snapshot that confirmed an adult, nothing at all is said,
        // offered or described - not even the stop sentence (the stop hotkey stays, and OStim's keys)
        lrgHideActions(LRG_GLUE_ACTIONS);
    } elseif ($inSceneWithThisNpc && !$sceneOk) {
        // Fail closed, but NOT silent: the spoken "stop" has to keep working, so ChangeIntimacy stays
        // offered with no options and no context - lrgResolveControl answers do=stop first and lets
        // everything else fall through to null. No scene awareness, no wording permission, no lead tick.
        $turn['mode'] = 'scene';
        $turn['scene'] = $scene;
        $turn['scene_blocked'] = true;
        $turn['can_act'] = in_array($type, LRG_PLAYER_SPEECH_TYPES, true);
        $turn['x'] = null;
        $turn['options'] = [];
        $turn['ctx'] = null;
        lrgHideActions(array_merge([LRG_ACT_START, LRG_ACT_INVITE, LRG_ACT_CLOTHING, LRG_ACT_REQUESTACT], LRG_MOVEMENT_ACTIONS));
        if (!$turn['can_act']) { lrgHideActions([LRG_ACT_CONTROL]); }
        $turn['hidden'] = [LRG_ACT_START => 'scene blocked', LRG_ACT_INVITE => 'scene blocked', LRG_ACT_CLOTHING => 'scene blocked',
            LRG_ACT_REQUESTACT => 'scene blocked'] + ($turn['can_act'] ? [] : [LRG_ACT_CONTROL => 'not can_act']);
        // own prefix: PROTOCOL 9 promises exactly three lines that start with "turn npc=" / "llm npc=" /
        // "net ", and a second shape behind the same prefix is what makes a log unparseable by prefix
        lrgLog("blocked npc=$npc in_scene (rail re-check failed: stop still works)", $turn['cid']);
    } elseif ($inSceneWithThisNpc) {
        $isSpeech = in_array($type, LRG_PLAYER_SPEECH_TYPES, true);
        $mem = lrgMemGet($npc);
        // [0.5.0 PT9 reconciliation / verify D10] ARM THE SCENE-TALK INTERVAL HERE, not at admission.
        // This branch is reached only when every rail prompts.php re-checks has already held (a live
        // scene with THIS NPC, a fresh snapshot, a confirmed adult, no child in the radius, the owner's
        // switch on, the profile not `never`) - i.e. exactly when the glue's own cue is defined and the
        // turn really is going to the model with a "say one short line" instruction. Functions are
        // disabled for lrg_scenetalk (main.php:1078), so the post-LLM gate never runs on this request
        // type: context_pre.php -> lrgPrepareTurn() is the LAST point the glue controls on a scene-talk
        // turn, and therefore the closest honest stand-in for "she spoke". Lead ticks count too - they
        // share the interval - and the outro never reaches this branch (its scene row is closed).
        if (!$same && $type === 'lrg_scenetalk' && !lrgIsOutroTick()) { lrgNoteSceneTalk($npc); }
        $turn['mode'] = 'scene';
        $turn['scene'] = $scene;
        $turn['lead'] = lrgIsLeadTick() && lrgLeadAllowed($scene);
        $turn['can_act'] = $isSpeech || $turn['lead'];
        $turn['profile'] = lrgBuildProfile($npc, $state);
        $turn['x'] = lrgExplicitLevel($scene, $state);
        $turn['discreet'] = false; // they are alone and in the middle of it
        $turn['lead_idle'] = (int) ($mem['lead_idle'] ?? 0);
        $turn['say_first'] = is_array($mem['say_first'] ?? null)
            && lrgNow() - (int) ($mem['say_first']['at'] ?? 0) <= (int) (lrgConfig()['result_memory_seconds'] ?? 180);
        // [0.3 / 5.2 precondition 3] "a scene is really running" is confirmed from the SNAPSHOT, which is
        // periodic (~20 s), never from the scene row's age: lrg_scene is event driven and de-duplicated,
        // so in a calm stretch - exactly when the player steers by voice - the row is minutes old.
        $turn['scene_confirmed'] = ($state['ostim'] ?? '') === '1' && !lrgResultSaysNoScene($mem, $scene);
        $live = array_filter(explode(',', (string) ($scene['next'] ?? '')));
        $max = (int) (lrgConfig()['scene_index']['max_options'] ?? 8);
        $maxActs = (int) (lrgConfig()['scene_index']['max_acts'] ?? 8);
        $furn = strtolower(trim((string) ($scene['furn'] ?? '')));
        $furn = $furn === 'none' ? '' : $furn;
        $current = (string) ($scene['scene'] ?? '');
        $cur = lrgScene($current);
        $sexes = lrgSexes($state);
        $npos = (int) ($scene['npos'] ?? 1);
        $tierNow = $cur ? (int) LRG_TIERS[$cur['tier']] : 0;
        $say = $isSpeech ? (string) ($GLOBALS['gameRequest'][3] ?? '') : '';

        // ---- M5, step 1: a PROVISIONAL context with ceiling 4 and no act list. The recogniser must
        // never filter by tier - the ceiling limits HER pacing, not what the player may ask for.
        $base = ['current' => $current, 'live' => $live, 'sexes' => $sexes, 'furn' => $furn, 'tier' => $tierNow,
            'player' => (string) ($GLOBALS['PLAYER_NAME'] ?? ''), 'npc' => $npc, 'npos' => $npos,
            // [0.3.1 / D11] what "go again" means in here: the act families they have already landed on
            'acts_done' => array_values((array) ($scene['_acts_done'] ?? []))];
        $nearf = lrgCsv($scene['nearf'] ?? '');
        if ($turn['can_act'] && $nearf && function_exists('lrgFurnitureOptions')) {
            $turn['furn_options'] = (array) lrgFurnitureOptions($nearf, $current, $sexes, 4, $furn);
        }
        // ---- step 2: recognise. A proposal that has run out of time or out of turns is dropped FIRST,
        // so an unrelated "yes" two turns later can never execute a sex act nobody was answering about.
        $mem = lrgPruneProposal($npc, $mem, $isSpeech);
        if ($say !== '') {
            $turn['intent'] = lrgRecogniseIntent($say, $base + ['ceiling' => 4, 'reached' => 4, 'furn_options' => $turn['furn_options'], 'auto_ok' => false], $mem);
        }
        lrgApplyProposalAnswer($turn, $scene, $mem);
        // ---- step 3: the two ceilings. 'ceiling' drives what is OFFERED and the ladder sentence;
        // 'req_ceiling' is used only to resolve the player's own request and by the safety net.
        $turn['progress'] = lrgTierCeiling($scene, $turn['profile'], $isSpeech);
        $ceiling = (int) $turn['progress']['ceiling'];
        $turn['progress']['req_ceiling'] = (in_array($turn['intent']['kind'], ['act', 'yes'], true) && $turn['intent']['conf'] === 'high') ? 4 : $ceiling;
        // ---- step 4: options, acts, final context. Anti-circling applies to HER picks only.
        $avoid = $turn['lead'] ? array_values((array) ($scene['_visited'] ?? [])) : [];
        $turn['options'] = lrgSceneOptions($current, $live, $ceiling, $max, $sexes, $furn, $avoid);
        if (function_exists('lrgActOptions')) {
            $turn['acts'] = lrgActOptions($current, $live, $ceiling, $sexes, $furn, $npos, $avoid, $maxActs);
        }
        if ($turn['can_act']) {
            $chance = (int) ((lrgBluntChance() + ['quiet' => 40, 'normal' => 70, 'vocal' => 85, 'crude' => 90, 'romantic' => 75, 'never' => 0])[lrgTalkStyle($turn['profile'])] ?? 70);
            $turn['blunt'] = $same ? !empty($prev['blunt']) : lrgRoll('announce') <= $chance;
            $turn['announce'] = $turn['blunt']; // [0.3] kept as an alias: blunt decides HOW blunt her line is, never WHETHER she speaks
        }
        $turn['ctx'] = $base + ['ceiling' => $ceiling, 'req_ceiling' => (int) $turn['progress']['req_ceiling'],
            'reached' => (int) $turn['progress']['reached'], 'furn_options' => $turn['furn_options'],
            'acts' => $turn['acts'],
            'auto_ok' => (int) $turn['progress']['reached'] >= (int) (lrgConfig()['scene_progression']['auto_mode_min_tier'] ?? 4)];
        // ---- step 5: resolve the recognised request against the FINAL context. This is where too_soon
        // and the CANT directive are decided, so the directive can never name something the gate drops.
        if ($turn['intent']['kind'] !== 'none') {
            $turn['intent'] = lrgIntentResolve($turn['intent'], $turn['ctx'], (int) $turn['progress']['req_ceiling']);
            $turn['intent']['words'] = lrgIntentWords($turn['intent'], $turn['ctx']);
        }
        // ---- R11: does she want to ASK for something instead of simply doing it?
        $turn['propose'] = lrgPickProposal($turn, $scene, $mem);

        lrgHideActions(array_merge([LRG_ACT_START, LRG_ACT_INVITE], LRG_MOVEMENT_ACTIONS));
        $turn['hidden'][LRG_ACT_START] = 'in a scene';
        $turn['hidden'][LRG_ACT_INVITE] = 'in a scene';
        if (!$turn['can_act']) {
            lrgHideActions([LRG_ACT_CONTROL, LRG_ACT_CLOTHING, LRG_ACT_REQUESTACT]);
            $turn['hidden'][LRG_ACT_CONTROL] = 'not can_act';
            $turn['hidden'][LRG_ACT_CLOTHING] = 'not can_act';
            $turn['hidden'][LRG_ACT_REQUESTACT] = 'not can_act';
        } elseif ($turn['propose'] !== null) {
            // on a proposal turn only the act list goes away; ChangeIntimacy STAYS offered, because
            // hiding it would take the stop rail with it
            lrgHideActions([LRG_ACT_REQUESTACT]);
            $turn['hidden'][LRG_ACT_REQUESTACT] = 'she is asking first';
        }
        if ($turn['lead']) { lrgKeepOnlyActions([LRG_ACT_CONTROL, LRG_ACT_CLOTHING, LRG_ACT_REQUESTACT]); } // her own initiative: nothing but the scene itself
    } else {
        lrgHideActions([LRG_ACT_CONTROL, LRG_ACT_REQUESTACT]);
        $turn['hidden'][LRG_ACT_CONTROL] = 'no scene';
        $turn['hidden'][LRG_ACT_REQUESTACT] = 'no scene';
        $isInit = $type === 'lrg_initiative' && strcasecmp((string) ($GLOBALS['LRG_INITIATIVE']['npc'] ?? ''), $npc) === 0 && $npc !== '';
        $gate = lrgEvaluateGates($npc, $type, $isInit);
        $turn['gate'] = $gate;
        $turn['profile'] = $gate['profile'];
        $turn['interest'] = $gate['interest'];
        if ($gate['interest'] !== null) {
            $mem = lrgMemGet($npc);
            $turn['invite'] = lrgInviteGet($npc, (bool) $gate['interest']['willing'], $mem);
            if (($mem['interest'] ?? null) !== $gate['interest']['word'] || ($mem['interest_score'] ?? null) !== $gate['interest']['score']) {
                lrgMemSet($npc, ['interest' => $gate['interest']['word'], 'interest_score' => $gate['interest']['score']]);
            }
        }
        $mode = lrgGateMode($gate, $turn['invite']);
        if ($isInit && ($mode === 'closed' || (in_array($mode, ['public', 'private'], true) && empty($gate['interest']['may_initiate'])))) {
            $mode = 'silent'; // things changed between admission and now: she simply does nothing
        }
        $turn['mode'] = $mode;
        $turn['initiative'] = $isInit && $mode !== 'silent';
        if (in_array($mode, ['public', 'private', 'follow'], true)) {
            $turn['x'] = lrgExplicitLevel(null, $gate['state']);
            $turn['discreet'] = lrgIsDiscreet($gate['profile'], $gate['state'], $gate['soft']);
        }
        if ($mode === 'public') { $turn['places'] = lrgPlaces($gate['state']); }
        // [0.5.2 / pt10] THE BLIND TURN. The game has not reported in, so `no_fresh_snapshot` is the one
        // and only reason on the list: lrgEvaluateGates() returns the moment the snapshot is missing or
        // stale, so this reason can never be mixed with not_adult / child_nearby / never / feature_off.
        // It is the difference between "the glue knows nothing" and "the glue knows, and the answer is
        // no" - and the two must not be treated alike, because only the first one is a fault.
        $reasons = (array) ($gate['reasons'] ?? []);
        $last = (array) ($gate['state'] ?? []);   // the last facts the game EVER sent for her, however old
        // Stale facts are not facts and nothing is ever offered on them - but they are still good enough
        // to keep quiet on, exactly as every other rail fails closed. If the last thing the game said
        // about this NPC was "not a confirmed adult" or "the owner's switch is off", a blind turn stays
        // as mute as it is today: no recognition, no note, nothing in the log.
        $blind = $mode === 'silent' && in_array('no_fresh_snapshot', $reasons, true)
            && ($last['adult'] ?? '1') !== '0' && ($last['on'] ?? '1') !== '0';
        $turn['blind'] = $blind;
        // ... and the NOTE below it goes out only when the game has really reported on her at some point
        // and those facts were positively an adult with the feature on. An NPC the game has NEVER sent a
        // snapshot for is not a degradation - she is simply none of the glue's business (the player is
        // having an ordinary CHIM conversation), and PROTOCOL 6.2's "a silent turn injects nothing at
        // all" has to keep holding for every one of those, or the glue would be in every conversation in
        // the game the moment the game side is switched off.
        // It is also a PLAYER-SPEECH note ("answer in words"), so it belongs on a turn the player
        // started. An admitted initiative tick that somehow arrives blind simply stays mute.
        $turn['blind_note'] = $blind && ($last['adult'] ?? '') === '1' && ($last['on'] ?? '') === '1'
            && in_array($type, LRG_PLAYER_SPEECH_TYPES, true);
        // [0.3 / G3d] the recogniser also runs outside a scene: it costs one pass of regex and decides
        // whether the player asked for physical contact (which always opens the action at once).
        // [0.3.1 / D9] It is now given a REAL context - the two sexes and the furniture in reach are
        // already in the snapshot - so the act layer, the position index and the text search work out
        // of a scene too. With ctx=null they were all switched off, which is why 148 of 218 audited
        // phrases got a neutral restatement and not one of them ever named a scene to start in.
        //
        // [0.5.2 / pt10] ... AND IT NOW RUNS ON A `silent` TURN TOO, when the silence is only blindness.
        // Playtest 10 spent thirty minutes in mode silent because the game's BuildSnapshot() was being
        // killed by a Papyrus error on every call; `silent` was not in the list above, so "kiss me",
        // "come hug me" and "take your clothes off" were never PARSED. That cost far more than the
        // action nobody could have offered anyway: intent=none in every turn line (so the log could not
        // say what the player had asked for), no heat, no quote memory, and no directive.
        // UNDERSTANDING A SENTENCE IS NOT A SAFETY PROPERTY - offering an action is, and that is decided
        // below from $offer, which is [] for `silent`. Nothing here changes what is offered, and the
        // post-LLM gate, the safety net (mode `scene` only) and the second command are untouched.
        // The OTHER silences stay exactly as they were - no recognition at all when the snapshot says
        // this is not an adult, a child is in the room, the NPC is opted out or the owner's switch is
        // off: there the glue does know, and none of the player's words may be given a sexual reading.
        $fullScan = ($blind || in_array($mode, ['closed', 'public', 'private', 'follow'], true))
            && in_array($type, LRG_PLAYER_SPEECH_TYPES, true) && $npc !== '';
        if ($fullScan) {
            $mem = $mem ?? lrgMemGet($npc);
            // On a blind turn these are the LAST facts, not fresh ones - which is why the context is
            // used for nothing but recognition: the two sexes do not change, and `nearf` can at worst
            // make the recogniser resolve a furniture act that this turn will never offer anyway.
            $st = $last;
            $ctx = ['current' => '', 'live' => [], 'sexes' => lrgSexes($st), 'furn' => '', 'tier' => 0,
                'player' => (string) ($GLOBALS['PLAYER_NAME'] ?? ''), 'npc' => $npc, 'npos' => 1,
                'ceiling' => 4, 'req_ceiling' => 4, 'reached' => 4, 'furn_options' => [], 'acts' => [],
                'nearf' => lrgCsv($st['nearf'] ?? ''), 'acts_done' => [], 'auto_ok' => false,
                // [0.5.4 / pt13] no scene with her is running on this branch: follow / wait / release
                // are recognised first (lrgIntentEscort), whatever the mode
                'escort' => lrgEscortOn('recognise')];
            $turn['ctx'] = $ctx;
            $turn['intent'] = lrgRecogniseIntent((string) ($GLOBALS['gameRequest'][3] ?? ''), $ctx, $mem);
            // Heat is the build-up counter, not a rail (lrgStartHeldBack only decides whether the action
            // exists on THIS turn; every hard gate is recomputed from the next fresh snapshot). A blind
            // stretch is still a real conversation, so it counts - otherwise the first turn after the
            // snapshot comes back starts at heat 0 and answers the tenth request in a row with "a
            // question about this is a question", which is exactly what playtest 10 felt like.
            // Mode `closed` still does NOT count: pressing someone who has refused must not warm her up.
            if ($mode === 'closed') { $turn['heat'] = (int) ($mem['heat'] ?? 0); }
            else { $turn['heat'] = lrgBumpHeat($npc, $turn['intent'], $mem); }
            // [0.4] The player asked what it would take, or pushed back on the figure: the directive is
            // about to name her floor, so remember it - that is what a bare "deal" next turn closes.
            // Only when a price is really needed: a free NPC has no quote to close.
            // On a blind turn there is no $gate['price'] at all (the gate returned before it was
            // computed), so this is inert there: nothing is quoted that the glue cannot stand behind.
            // [pt15] ... and every other turn on which the directive makes her NAME her price: an offer
            // with no figure, or one below her floor ("not for 50 - 225").
            $ik = (string) ($turn['intent']['kind'] ?? '');
            $namesPrice = in_array($ik, ['askprice', 'haggle'], true)
                || ($ik === 'offer' && empty($gate['paid']['accepted'])
                    && ((int) ($turn['intent']['gold'] ?? 0) <= 0 || str_contains((string) ($gate['paid']['why'] ?? ''), 'floor')));
            if ($namesPrice
                && !empty($gate['price']['for_sale']) && empty($gate['price']['free'])
                && ($mode !== 'closed' || lrgPriceCouldOpen($gate))) {
                lrgRememberQuote($npc, (int) $gate['price']['gold']);
            }
        } elseif ($mem !== null) {
            $turn['heat'] = (int) ($mem['heat'] ?? 0);
        }
        // [0.5.4 / pt13] FOLLOW / WAIT / RELEASE IN EVERY OTHER MODE TOO. A silence the game KNOWS the
        // reason for (not an adult, a child nearby, the intimacy switch off, an opted-out NPC) still gets
        // no sexual reading at all - so it gets the escort-only recogniser, which has none to give.
        // Walking with somebody is not intimacy: "follow me" to a child or with the switch off is just
        // CHIM's own FollowPlayer, and the glue only adds the directive and, where needed, the carrier.
        if (!$fullScan && in_array($type, LRG_PLAYER_SPEECH_TYPES, true) && $npc !== '' && lrgEscortOn('recognise')) {
            $esc = lrgRecogniseEscort((string) ($GLOBALS['gameRequest'][3] ?? ''));
            if ((string) $esc['kind'] === LRG_INTENT_ESCORT) { $turn['intent'] = $esc; }
        }
        if ((string) ($turn['intent']['kind'] ?? '') === LRG_INTENT_ESCORT) { $turn['escort'] = lrgEscortFacts($npc); }
        // [0.3 / 8.3] a hard move of hers that was dropped for having no spoken line is re-asked here too:
        // BeginIntimacy and SuggestPrivacy happen OUTSIDE a scene, so the one-shot flag has to reach this block
        $turn['say_first'] = is_array(($mem ?? lrgMemGet($npc))['say_first'] ?? null)
            && lrgNow() - (int) ((($mem ?? lrgMemGet($npc))['say_first'])['at'] ?? 0) <= (int) (lrgConfig()['result_memory_seconds'] ?? 180);
        $offer = ['public' => [LRG_ACT_INVITE], 'private' => [LRG_ACT_START, LRG_ACT_CLOTHING], 'follow' => [LRG_ACT_START, LRG_ACT_CLOTHING]][$mode] ?? [];
        // [0.3 / G3d, 11.2] A sexual QUESTION on the first turn after a load is a question: she answers
        // it in words and starts nothing. The action is on the table when the player asked for physical
        // contact, when she has already invited him (mode follow), when the conversation has warmed up
        // and no cooldown is running, or on an admitted initiative tick (which IS the build-up).
        if (in_array($mode, ['private', 'follow'], true)) {
            $why = lrgStartHeldBack($turn, $mem ?? lrgMemGet($npc), $mode);
            if ($why !== '') {
                $offer = array_values(array_diff($offer, [LRG_ACT_START, LRG_ACT_CLOTHING]));
                $turn['hidden'][LRG_ACT_START] = $why;
                $turn['hidden'][LRG_ACT_CLOTHING] = $why;
            }
        }
        foreach (array_diff([LRG_ACT_START, LRG_ACT_CLOTHING, LRG_ACT_INVITE], $offer) as $code) {
            if (!isset($turn['hidden'][$code])) { $turn['hidden'][$code] = 'mode ' . $mode; }
        }
        lrgHideActions(array_diff([LRG_ACT_START, LRG_ACT_CLOTHING, LRG_ACT_INVITE], $offer)); // undressing outside a scene needs the same hard gates
        if ($turn['initiative']) { lrgKeepOnlyActions($offer); } // her own move: nothing but that
    }
    // What CHIM is really offering this turn, after all the hiding: the post-LLM gate accepts nothing
    // else, and the guidance only ever names an action from this list.
    $turn['offered'] = array_values(array_filter(LRG_GLUE_ACTIONS, 'lrgIsOffered'));

    // [0.5.5 / addendum 11 (c)] an order of hers that visibly failed (lrgMoveWatchCheck, the escort's late
    // failure) is told on the next PLAYER turn, once, in every mode but a scene with her (there it is stale)
    if (!$same && in_array($type, LRG_PLAYER_SPEECH_TYPES, true) && $npc !== '') {
        $miss = ($mem ?? lrgMemGet($npc))['missed'] ?? null;
        if (is_array($miss)) {
            if (!in_array((string) $turn['mode'], ['scene', 'outro'], true)
                && lrgNow() - (int) ($miss['at'] ?? 0) <= (int) (lrgConfig()['result_memory_seconds'] ?? 180)) { $turn['missed'] = $miss; }
            lrgMemSet($npc, ['missed' => null]);
        }
    }
    // a command of hers that failed in game and was NOT voiced (lrgFuncretVerdict) is told to her once
    if (!$same && $turn['mode'] !== 'silent' && $mem !== null) {
        $lr = $mem['last_result'] ?? null;
        if (is_array($lr) && empty($lr['ok']) && empty($lr['told'])) {
            // [game 507] her words (say) when the game sent them, else the technical reason as before; and never a
            // second note for the escort's late failure when the `missed` note above already carries it
            $dup = is_array($turn['missed']) && strcasecmp((string) ($turn['missed']['code'] ?? ''), (string) ($lr['cmd'] ?? '')) === 0;
            if (!$dup && lrgNow() - (int) ($lr['at'] ?? 0) <= (int) (lrgConfig()['result_memory_seconds'] ?? 180)) {
                $turn['fail'] = (string) (($lr['say'] ?? '') !== '' ? $lr['say'] : ($lr['reason'] ?? ''));
                // [pt17] a dry-run refusal that was NOT voiced (the 8 s gap, a dropped voiced turn, an old script whose
                // words were "cannot do that right now") is told with the switch and the page, never as a bare "cannot"
                $dryWhy = lrgVoicedDryWhy((string) ($lr['reason'] ?? ''));
                if ($dryWhy !== '') { $turn['fail'] = $dryWhy; }
            }
            lrgMemSet($npc, ['last_result' => ['told' => true] + $lr]);
        }
    }
    // [0.3 / 8.3] the say-it-first re-ask is one-shot too: asked once, then forgotten
    if (!$same && !empty($turn['say_first'])) { lrgMemSet($npc, ['say_first' => null]); }

    $GLOBALS['LRG_TURN'] = $turn;
    if ($same) { return; }
    // [0.3 / G6] ONE fixed "turn" line per turn, greppable, carrying what the last three playtests all
    // lacked: what the player said, what was recognised, what was offered and WHY each action was hidden.
    if (in_array($type, LRG_PLAYER_SPEECH_TYPES, true) || $type === 'lrg_initiative'
        || $turn['mode'] === 'scene' || $turn['mode'] === 'outro') {
        lrgLog(lrgTurnLogLine($turn), $turn['cid']);
    }
    if ($turn['gate'] !== null && (in_array($type, LRG_PLAYER_SPEECH_TYPES, true) || $type === 'lrg_initiative')) {
        // "gate npc=", not "turn npc=": the G6 turn line above owns that prefix (PROTOCOL 9)
        // [0.3.1 / R1c] every component of aff= is named, so "why is she suddenly closed" is one line
        // instead of an eighteen-minute forensic reconstruction.
        $i = (array) ($turn['interest'] ?? []);
        $parts = '';
        if ($i) {
            $parts = sprintf(' chim=%s lrg=%d hist=%d courting=%d rank=%d min=%d bonus=%d%s',
                array_key_exists('chim', $i) && $i['chim'] !== null ? (string) (int) $i['chim'] : 'missing',
                (int) ($i['lrg'] ?? 0), (int) ($i['history'] ?? 0), (int) ($i['courting'] ?? 0), (int) ($i['rank'] ?? 0),
                (int) ($i['min'] ?? 0), (int) ($i['bonus'] ?? 0), ($i['rescue'] ?? '') !== '' ? ' rescued=' . $i['rescue'] : '');
        }
        // [0.4] wit / witfol / door on the gate line: the next forensic pass on a "reasons=witnesses"
        // complaint must be able to see whether the game thought the room was shut (pt8 spent seven
        // minutes on exactly that question and the log could not answer it).
        $gs = (array) ($turn['gate']['state'] ?? []);
        lrgLog(sprintf('gate npc=%s mode=%s%s offer_start=%s reasons=%s aff=%d interest=%s(%d) invite=%s profile=%s wit=%d/%d door=%s%s', $npc, $turn['mode'], $turn['initiative'] ? ' INITIATIVE' : '',
            $turn['gate']['ok'] ? 'yes' : 'no', implode(',', $turn['gate']['reasons']) ?: '-', $turn['gate']['affinity'] ?? 0, $turn['interest']['word'] ?? '-', $turn['interest']['score'] ?? 0,
            $turn['invite']['state'] ?? '-', $turn['gate']['profile']['id'] ?? '-',
            (int) ($gs['wit'] ?? -1), (int) ($gs['witfol'] ?? -1), ($gs['door'] ?? '') === '1' ? 'shut' : (($gs['door'] ?? '') === '0' ? 'open' : '-'),
            $parts)
            // [pt15, ruling B] additive, last: not_close_enough was lifted because he BOUGHT it (firm, affordable)
            . (in_array('paid', (array) ($turn['gate']['soft'] ?? []), true) ? ' lifted=bought:' . (int) (($turn['gate']['paid'] ?? [])['offer'] ?? 0) : ''),
            $turn['cid']);
    }
    // [0.5.2 / pt10] The line that names the thirty-minute hole in playtest 10 in the owner's own terms.
    // `reasons=no_fresh_snapshot` was already on the gate line, but it is one of nine reasons on the most
    // crowded line the glue writes, and nothing said what it COST. Its own prefix (PROTOCOL 9: `turn` /
    // `llm` / `net` / `gate` / `price` are contract and may not carry a second shape), and it carries the
    // age of the facts the server does have, so "the game stopped sending" and "the game is late" read
    // differently at a glance.
    if (!empty($turn['blind'])) {
        $st = ($turn['gate'] ?? [])['state'] ?? null;
        $age = is_array($st) ? (int) ($st['_age'] ?? -1) . 's' : 'none';
        lrgLog(sprintf('silent: no fresh snapshot (age=%s) npc=%s - no action offered, nothing executed; she answers in words%s',
            $age, $npc, ($turn['intent']['kind'] ?? 'none') !== 'none' ? ' (the player asked for ' . (string) $turn['intent']['kind'] . ')' : ''), $turn['cid']);
    }
}

// ---------------------------------------------------------------- [0.3] turn helpers
/** The empty recognition, so every consumer can read $turn['intent'] without isset() gymnastics. */
function lrgNoIntent(): array
{
    return ['kind' => 'none', 'conf' => 'none', 'kv' => [], 'act' => '', 'scene' => '', 'words' => '', 'why' => '',
        'text' => '', 'frag' => '', 'again' => false, 'extra' => [],
        // [0.4] the money kinds carry a figure and where it came from
        'gold' => 0, 'from' => ''];
}

/**
 * [0.4 / F2] One WARN line when a player line was answered by CHIM's Narrator shortly after a scene -
 * the fingerprint of the owner's "left in OStim director mode" complaint (pt8: three of them in
 * thirteen minutes). Costs nothing when it never happens, and it is the only way to see from the log
 * whether the listener hold in LRG_OStim.TickListener is doing its job.
 * It stays a WARN line on purpose: the `turn` / `llm` / `net` / `gate` / `price` prefixes are contract.
 */
function lrgWarnNarratorAfterScene(string $npc, string $type, string $cid): void
{
    if (!in_array($type, LRG_NARRATOR_SPEECH_TYPES, true)) { return; }
    $window = (int) (lrgConfig()['narrator_warn_seconds'] ?? 120);
    if ($window <= 0) { return; }
    $db = lrgDb();
    if (!$db) { return; }
    // The newest CLOSED scene row, of ANY NPC: the request that landed on the Narrator is attributed to
    // the Narrator, so the partner's own name is exactly what is missing from it. lrgStoreScene() stamps
    // updated_at on the ev=end push and sets active=0, so that row's timestamp IS the scene's end.
    $row = $db->fetchOne('SELECT npc_name, updated_at, active FROM lrg_scene_state WHERE active=0 ORDER BY updated_at DESC LIMIT 1');
    if (!is_array($row) || !$row || (int) ($row['active'] ?? 1) !== 0) { return; }
    $who = (string) ($row['npc_name'] ?? '');
    $age = lrgNow() - (int) ($row['updated_at'] ?? 0);
    if ($age < 0 || $age > $window) { return; }
    lrgLog(sprintf('WARN the player\'s line went to the Narrator %ds after a scene with %s ended (CHIM found no NPC under the crosshair)',
        $age, $who !== '' ? $who : 'an NPC'), $cid);
}

/** scene_talk.blunt_chance, with the 0.2 key announce_chance still readable so an existing user config works. */
function lrgBluntChance(): array
{
    $c = (array) (lrgConfig()['scene_talk'] ?? []);
    return (array) ($c['blunt_chance'] ?? $c['announce_chance'] ?? []);
}

/** True while an unresolved "no scene is running" from the game is newer than the scene row (5.2 / G4). */
function lrgResultSaysNoScene(array $mem, array $scene): bool
{
    $lr = $mem['last_result'] ?? null;
    if (!is_array($lr) || !empty($lr['ok'])) { return false; }
    if (strcasecmp((string) ($lr['reason'] ?? ''), 'no scene is running') !== 0) { return false; }
    return (int) ($lr['at'] ?? 0) >= lrgNow() - (int) ($scene['_age'] ?? 0);
}

/**
 * [0.3 / G6] The emitted-vs-confirmed watchdog. One of ten commands in playtest 6 passed the gate and
 * never reached the game, and nothing noticed. No retry: a retry could double-execute.
 */
function lrgCommandWatchdog(string $npc, string $cid): void
{
    if ($npc === '') { return; }
    $mem = lrgMemGet($npc);
    $p = is_array($mem['pending_cmd'] ?? null) ? $mem['pending_cmd'] : null;
    if ($p === null) { return; }
    $age = lrgNow() - (int) ($p['at'] ?? 0);
    if ($age < (int) (lrgConfig()['command_confirm_seconds'] ?? 20)) { return; }
    lrgLog(sprintf('WARN command not confirmed by the game: %s do=%s cid=%s age=%ds', (string) ($p['code'] ?? '?'),
        (string) ($p['do'] ?? '-'), (string) ($p['cid'] ?? '-'), $age), $cid);
    lrgMemSet($npc, ['pending_cmd' => null]);
}

/**
 * [0.3 / G3d] The heat counter: how warm this conversation already is. Incremented FIRST, then compared,
 * so the second romantic exchange can already open the action. Reset after heat_window_seconds of nothing.
 */
function lrgBumpHeat(string $npc, array $intent, array $mem): int
{
    $cfg = (array) (lrgConfig()['start'] ?? []);
    $window = (int) ($cfg['heat_window_seconds'] ?? 600);
    $heat = (int) ($mem['heat'] ?? 0);
    if (lrgNow() - (int) ($mem['heat_at'] ?? 0) > $window) { $heat = 0; }
    $pattern = (string) ($cfg['heat_pattern'] ?? '');
    // [0.4] offering money for it, asking the price or haggling over it all warm the conversation: they
    // are as plain a statement of what the player is after as "get naked" is.
    // [pt15] ... except taking an offer back, which is the opposite
    $warm = in_array((string) ($intent['kind'] ?? 'none'), array_diff(array_merge(['act', 'undress', 'dress', 'climax'], LRG_INTENT_MONEY), ['withdraw']), true)
        || ($pattern !== '' && @preg_match('/' . $pattern . '/i', (string) ($intent['text'] ?? '')) === 1);
    if (!$warm) { return $heat; }
    $heat++;
    lrgMemSet($npc, ['heat' => $heat, 'heat_at' => lrgNow()]);
    return $heat;
}

/**
 * [0.3 / 11.2] Why BeginIntimacy (and an unasked-for ChangeClothing) is NOT on the table, '' = it is.
 * The hard gates decide whether she is willing at all; this only decides whether the action exists
 * on THIS turn, so a sexual question right after a load is answered in words instead of acted on.
 */
function lrgStartHeldBack(array $turn, array $mem, string $mode): string
{
    $cfg = (array) (lrgConfig()['start'] ?? []);
    $kind = (string) ($turn['intent']['kind'] ?? 'none');
    if (in_array($kind, ['act', 'undress'], true)) { return ''; }   // the player asked for contact: always at once
    // [0.4] an offer of gold IS the build-up: the player has named a figure out loud for exactly this,
    // which is at least as explicit as asking for contact. Asking the price and haggling are NOT here -
    // their own directives say "choose no action this turn", so the action would only be noise.
    if ($kind === 'offer') { return ''; }
    if ($mode === 'follow') { return ''; }                          // the invitation was the build-up
    if (!empty($turn['initiative'])) { return ''; }                 // an admitted tick is itself the build-up
    $minHeat = (int) ($cfg['min_heat'] ?? 2);
    $heat = (int) ($turn['heat'] ?? 0);
    if ($heat < $minHeat) { return sprintf('heat %d<%d', $heat, $minHeat); }
    $sinceScene = lrgNow() - (int) ($mem['last_scene_end_at'] ?? 0);
    $needScene = (int) ($cfg['post_scene_cooldown_seconds'] ?? 300);
    if ((int) ($mem['last_scene_end_at'] ?? 0) > 0 && $sinceScene < $needScene) { return sprintf('post-scene %ds<%ds', $sinceScene, $needScene); }
    $sinceLoad = lrgNow() - (int) ($mem['session_at'] ?? 0);
    $needLoad = (int) ($cfg['post_reload_cooldown_seconds'] ?? 120);
    if ((int) ($mem['session_at'] ?? 0) > 0 && $sinceLoad < $needLoad) { return sprintf('post-reload %ds<%ds', $sinceLoad, $needLoad); }
    return '';
}

/**
 * [0.3 / R11] The act she would rather ASK for than simply do. All six conditions must hold (condition 1
 * of the design, "it is a lead tick", is deliberately gone: a proposal changes nothing on screen and
 * gating it behind a lead tick would make it unreachable whenever the player is steering - which is most
 * of a scene - and would cost an extra LLM turn for nothing).
 */
function lrgPickProposal(array $turn, array $scene, array $mem): ?array
{
    $cfg = (array) ((lrgConfig()['lead'] ?? [])['ask_first'] ?? []);
    if (!($cfg['enabled'] ?? true) || empty($turn['can_act']) || !empty($turn['scene_blocked'])) { return null; }
    // The player asked for something: do THAT. The notes for a proposal say "choose no action this turn",
    // which flatly contradicts the turn directive's "Do it now: choose ... Do not refuse, stall, negotiate"
    // - two orders in one prompt, and she answers with an unrelated question, which reads as "she ignored
    // me". Stepping aside for 'act' and 'yes' alone left faster / slower / undress / dress / furniture /
    // climax / stop / hold / release / lead all able to collide with it.
    // [0.4] the money kinds are in this list for exactly the reason the comment above gives: their own
    // directive ends in "choose no action this turn", and a proposal turn on top of it is two orders.
    if (in_array((string) ($turn['intent']['kind'] ?? 'none'), array_merge(LRG_INTENT_ACTIONABLE, LRG_INTENT_MONEY, ['yes', 'no']), true)) { return null; }
    $now = lrgNow();
    if ($now - (int) ($scene['_tier_since'] ?? $now) < (int) ($cfg['min_act_seconds'] ?? 75)) { return null; }
    if ($now - (int) ($scene['_last_prop_at'] ?? 0) < (int) ($cfg['cooldown_seconds'] ?? 120)) { return null; }
    if ((int) ($scene['_proposals'] ?? 0) >= (int) ($cfg['max_per_scene'] ?? 3)) { return null; }
    if (is_array($mem['proposal'] ?? null) && $now <= (int) ($mem['proposal']['expires'] ?? 0)) { return null; }
    $done = array_map('strval', (array) ($scene['_acts_done'] ?? []));
    $no = array_map('strval', (array) ($scene['_prop_no'] ?? []));
    foreach ((array) ($turn['acts'] ?? []) as $id => $a) {
        if ((string) $a['tier'] !== 'sexual') { continue; }
        $fam = explode(':', (string) $id)[0];
        if (in_array($fam, $done, true) || in_array((string) $id, $no, true)) { continue; }
        // the display name carries the two real names, not the placeholders the act table stores
        return ['act' => (string) $id, 'name' => lrgActLabel((string) $id, (string) $turn['npc'], (string) ($turn['ctx']['player'] ?? '')),
            'scene' => (string) $a['scene']];
    }
    return null;
}

/**
 * [0.3 / R11] A pending proposal only answers for the FIRST or SECOND player speech turn after she asked,
 * and only inside its ttl. Anything later is not an answer to it. Returns the memory to recognise against.
 */
function lrgPruneProposal(string $npc, array $mem, bool $isSpeech): array
{
    $prop = is_array($mem['proposal'] ?? null) ? $mem['proposal'] : null;
    if ($prop === null) { return $mem; }
    $turns = (int) ($prop['turns'] ?? 0) + ($isSpeech ? 1 : 0);
    if (lrgNow() > (int) ($prop['expires'] ?? 0) || $turns > 2) {
        lrgMemSet($npc, ['proposal' => null]);
        unset($mem['proposal']);
        lrgLog('proposal ' . (string) ($prop['act'] ?? '?') . ' expired');
        return $mem;
    }
    if ($turns !== (int) ($prop['turns'] ?? 0)) {
        $prop['turns'] = $turns;
        lrgMemSet($npc, ['proposal' => $prop]);
        $mem['proposal'] = $prop;
    }
    return $mem;
}

/**
 * [0.3 / R11] The player's answer to her proposal. A no is remembered for the rest of the scene so she
 * does not nag; any other request simply wins and clears it WITHOUT recording a no (she may ask later).
 */
function lrgApplyProposalAnswer(array &$turn, array $scene, array $mem): void
{
    $prop = is_array($mem['proposal'] ?? null) ? $mem['proposal'] : null;
    if ($prop === null) { return; }
    $npc = (string) $turn['npc'];
    $kind = (string) ($turn['intent']['kind'] ?? 'none');
    if ($kind === 'no') {
        // ev / sync belong to the game push this row came from: see lrgRecordProposal() for why handing
        // them back to lrgStoreScene() would restart the scene's own clocks
        $row = $scene;
        unset($row['_npc'], $row['_age'], $row['ev'], $row['sync']);
        $no = array_values(array_unique(array_merge((array) ($row['_prop_no'] ?? []), [(string) $prop['act']])));
        $row['_prop_no'] = array_slice($no, -12);
        lrgStoreScene($npc, $row);
        lrgMemSet($npc, ['proposal' => null]);
        lrgLog('proposal ' . (string) $prop['act'] . ': the player said no - not suggested again this scene', (string) $turn['cid']);
        return;
    }
    if ($kind === 'yes' || in_array($kind, LRG_INTENT_ACTIONABLE, true)) {
        lrgMemSet($npc, ['proposal' => null]);
        lrgLog('proposal ' . (string) $prop['act'] . ': ' . ($kind === 'yes' ? 'the player said yes' : 'overtaken by another request'), (string) $turn['cid']);
    }
}

/** G6 (a): the one fixed turn line. Field order is fixed so the next forensics pass can grep it. */
function lrgTurnLogLine(array $turn): string
{
    $mem = $turn['npc'] !== '' ? lrgMemGet((string) $turn['npc']) : [];
    $cfg = (array) (lrgConfig()['lead'] ?? []);
    $now = lrgNow();
    $holdReq = max(0, (int) ($mem['player_request_at'] ?? 0) + (int) ($cfg['hold_seconds'] ?? 150) - $now);
    $holdSay = max(0, (int) ($mem['player_spoke_at'] ?? 0) + (int) ($cfg['speech_hold_seconds'] ?? 45) - $now);
    $in = (array) ($turn['intent'] ?? []);
    $what = ($in['act'] ?? '') !== '' ? $in['act'] : (($in['kv']['who'] ?? '') !== '' ? $in['kv']['who'] : ($in['kv']['do'] ?? '-'));
    $hidden = [];
    foreach ((array) ($turn['hidden'] ?? []) as $code => $why) { $hidden[] = lrgShortCode((string) $code) . '(' . $why . ')'; }
    $offered = array_map('lrgShortCode', (array) ($turn['offered'] ?? []));
    // [0.3.1 / R4] The player's words go in EVERY turn line. In playtest 7 the eighteen minutes the
    // owner complained about were all logged say="" because the recogniser never ran in mode closed,
    // and the only record of what she actually said was CHIM's own context log.
    $say = (string) ($in['text'] ?? '');
    if ($say === '') { $say = (string) ($turn['said'] ?? ''); }
    // and a closed turn now says WHY it was closed, in the same plain words the NPC is given
    $tail = '';
    if ((string) $turn['mode'] === 'closed') {
        $why = function_exists('lrgReasonWords') ? lrgReasonWords((array) ($turn['gate']['reasons'] ?? [])) : [];
        $tail = ' closed="' . str_replace('"', "'", implode('; ', $why) ?: implode(',', (array) ($turn['gate']['reasons'] ?? [])) ?: 'unknown') . '"';
    }
    if (!empty($turn['outro'])) {
        $o = (array) $turn['outro'];
        $tail .= sprintf(' outro=dur:%ds/%s/acts:%s/first:%s', (int) ($o['dur'] ?? 0), (string) ($o['how'] ?? '?'),
            implode('+', (array) ($o['acts'] ?? [])) ?: '-', !empty($o['first_time']) ? 'yes' : 'no');
    }
    if (!empty($in['extra']['kind'])) { $tail .= ' also=' . (string) $in['extra']['kind'] . '/' . ((string) ($in['extra']['act'] ?? '') ?: '-'); }
    // [0.5.5] an order of hers that visibly failed is told on this turn (lrgMissedNote)
    if (!empty($turn['missed'])) { $tail .= ' missed=' . lrgShortCode((string) ($turn['missed']['code'] ?? '?')); }
    // [0.4 / OWNER ADDENDA 6] one additive `paid=` segment, exactly like outro= and also=, and only on
    // a turn where coin is really in play: her floor, the band it came from, what he offered and the
    // one-word decision. It is what the owner greps to see why a price was what it was.
    $pr = (array) (($turn['gate'] ?? [])['price'] ?? []);
    $po = (array) (($turn['gate'] ?? [])['paid'] ?? []);
    if ($pr && (!empty($pr['for_sale']) || (int) ($po['offer'] ?? 0) > 0 || (string) ($po['kind'] ?? '') !== '')) {
        $nm = static fn(float $f): string => rtrim(rtrim(number_format($f, 2, '.', ''), '0'), '.') ?: '0';
        $quoted = is_array($mem['price_quoted'] ?? null) ? (int) ($mem['price_quoted']['gold'] ?? 0) : 0;
        // [pt15 / addenda 12e-f] wage:<tier>@<day wage> - whose day the band's days are counted in
        $tail .= sprintf(' paid=floor:%d/band:%s-%sd/tier:%s/wage:%s@%s/infl:%s/stance:%s/quoted:%s/offer:%d(%s)/purse:%s/decision:%s',
            (int) ($pr['gold'] ?? 0), $nm((float) ($pr['band'][0] ?? 0)), $nm((float) ($pr['band'][1] ?? 0)),
            (string) ($pr['tier'] ?? '') ?: '-', (string) ($pr['wage_tier'] ?? '') ?: '-', $nm((float) ($pr['wage_day'] ?? 0)),
            $nm((float) ($pr['infl'] ?? 1)), (string) ($pr['stance'] ?? '') ?: '-',
            $quoted > 0 ? (string) $quoted : '-', (int) ($po['offer'] ?? 0), (string) ($po['source'] ?? 'none'),
            lrgPurseWord((int) ((($turn['gate'] ?? [])['state'] ?? [])['pgold'] ?? 0), (int) ($pr['gold'] ?? 0)),
            lrgPriceDecision($pr, $po));
    }
    return sprintf('turn npc=%s type=%s mode=%s scene=%s say="%s" intent=%s/%s conf=%s why=%s offered=%s hidden=%s lead=%s hold=%d/%d heat=%d prop=%s reached=%d ceiling=%d req=%d pace=%s options=%d acts=%d blunt=%s idle=%d%s',
        (string) $turn['npc'], (string) $turn['type'], (string) $turn['mode'], (string) ($turn['ctx']['current'] ?? '-'),
        str_replace('"', "'", $say), (string) ($in['kind'] ?? 'none'), $what !== '' ? $what : '-', (string) ($in['conf'] ?? 'none'),
        ($in['why'] ?? '') !== '' ? $in['why'] : '-', $offered ? implode(',', $offered) : 'none', $hidden ? implode(',', $hidden) : '-',
        !empty($turn['lead']) ? 'yes' : 'no', $holdReq, $holdSay, (int) ($turn['heat'] ?? 0),
        $turn['propose'] !== null ? (string) $turn['propose']['act'] : '-',
        (int) ($turn['progress']['reached'] ?? 0), (int) ($turn['progress']['ceiling'] ?? 0), (int) ($turn['progress']['req_ceiling'] ?? 0),
        (string) ($turn['progress']['pace'] ?? '-'), count((array) $turn['options']), count((array) $turn['acts']),
        !empty($turn['blunt']) ? 'yes' : 'no', (int) ($turn['lead_idle'] ?? 0), $tail);
}

/** ExtCmdLRG_SceneControl -> Control, for the log lines. */
function lrgShortCode(string $code): string
{
    return str_replace(['ExtCmdLRG_', 'StartIntimacy', 'SceneControl'], ['', 'Start', 'Control'], $code);
}

/**
 * R7 + R5 runtime switches for this request (called from context_pre.php, before the connector opens):
 *  - FORCE_MAX_TOKENS on scene / follow / initiative turns: a safety net behind the one-short-sentence instruction
 *    (TTS time is linear in characters and runs under CHIM's MAIN lock)
 *  - OPENAI_FILTER_DISABLED: CHIM's word-scoring refusal detector (lib/chat_helper_functions.php:589-655, :1381) replaces
 *    a sentence that happens to contain three of its trigger words (sorry, can't, request, explicit, inappropriate ...)
 *    with a canned line and stops the reply; blunt talk and in-character refusals trip it. The core's own bypass flag
 *    is set for glue-guided turns only. Nothing in CHIM is edited.
 */
function lrgApplyTurnRuntime(?array $turn): void
{
    if (!$turn || ($turn['mode'] ?? 'silent') === 'silent' || !empty($turn['scene_blocked'])) { return; }
    $cfg = lrgConfig();
    if ($cfg['language']['disable_chim_refusal_filter'] ?? true) { $GLOBALS['OPENAI_FILTER_DISABLED'] = true; }
    $tok = ($cfg['scene_talk']['max_tokens'] ?? []) + ['talk' => 140, 'act' => 200, 'outro' => 300];
    if ($turn['mode'] === 'voiced') {
        // [0.5.5] ONE short line with the real reason, under CHIM's MAIN lock like every spoken line: the cap is
        // the safety net behind the cue, and the refusal filter bypass above is what keeps an in-character "not
        // now, because ..." from being swapped for CHIM's canned refusal line
        $GLOBALS['FORCE_MAX_TOKENS'] = (int) lrgVoiceCfg('max_tokens', (int) $tok['talk']);
        return;
    }
    if ($turn['mode'] === 'outro') {
        // [0.3.1 / R3-O4] The outro is two to four sentences, and FOUR separate caps used to force it
        // into one line (the scene-talk cue, the scene notes' last line, lrgOneSentence() and this
        // token cap). This is the only turn that gets its own budget; none of the other three is
        // emitted on it at all.
        $GLOBALS['FORCE_MAX_TOKENS'] = (int) $tok['outro'];
    } elseif ($turn['mode'] === 'scene') {
        $GLOBALS['FORCE_MAX_TOKENS'] = (int) (!empty($turn['can_act']) ? $tok['act'] : $tok['talk']);
    } elseif (in_array($turn['mode'], ['follow', 'public', 'private'], true) || !empty($turn['initiative'])) {
        // every mode whose guidance asks for ONE short sentence gets the matching cap: the proposition
        // and invitation turns are intimate speech and pay full TTS time under CHIM's MAIN lock too
        $GLOBALS['FORCE_MAX_TOKENS'] = (int) $tok['act'];
    }
}

// ---------------------------------------------------------------- the post-LLM gate
/**
 * Layer-1 repeat AFTER the LLM answered: the reply is never trusted. Unknown, unoffered
 * or malformed glue actions are dropped; accepted ones get a server-built parameter that
 * the LLM could not have produced (ok=1 + correlation id + npc). ExtCmdLRG_Invite is recorded and removed.
 */
function lrgPostProcessActions(array $actions): array
{
    $turn = $GLOBALS['LRG_TURN'] ?? null;
    $out = [];
    $passed = false;
    $emitted = [];     // [0.3] what really went out this turn, for the safety net's precondition 5
    $dropped = false;  // [0.3] a self-initiated change was dropped for having no spoken line (8.3)
    if ($turn && lrgEnabled() && !defined('LRG_SHARMAT_PRESENT')) { lrgLogLlm($turn, $actions); }
    foreach ($actions as $line) {
        $raw = rtrim((string) $line, "\r\n");
        $eol = substr((string) $line, strlen($raw));
        $parts = explode('|', $raw, 3);
        $cmd = explode('@', $parts[2] ?? '', 2);
        $code = trim($cmd[0] ?? '');
        // real wire format (functions.php queueFunctionExecutionCommand): "Actor|channel|Code@param\r\n".
        // When the core cannot map the display name back to the code it sends the display name (stored snake-cased): gate that too.
        $code = ['beginintimacy' => LRG_ACT_START, 'changeintimacy' => LRG_ACT_CONTROL, 'changeclothing' => LRG_ACT_CLOTHING,
            'suggestprivacy' => LRG_ACT_INVITE, 'requestact' => LRG_ACT_REQUESTACT][str_replace(['_', ' '], '', strtolower($code))] ?? $code;
        if (stripos($code, 'ExtCmdLRG_') !== 0) { $out[] = $line; continue; }
        // [0.4 integrator, D-12] Phase 2's own post-gate owns ExtCmdLRG_SelectTopic: it is emitted by
        // lib/lrg_dialogue.php, never offered through Phase 1's catalog, and Phase 1 knows nothing about
        // it. Without this pass-through every one of them dies four lines below as "not offered this
        // turn" / "dropped unknown glue action" - silently, and under SHARMAT even while Phase 1 is idle.
        if (strcasecmp($code, 'ExtCmdLRG_SelectTopic') === 0) { $out[] = $line; continue; }

        $actor = $parts[0] ?? '';
        $cid = $turn['cid'] ?? 'none';
        if (!$turn || strcasecmp($actor, (string) $turn['npc']) !== 0 || !lrgEnabled() || defined('LRG_SHARMAT_PRESENT')) {
            lrgLog("gate: dropped $code from '$actor' (no turn / wrong actor / disabled)", $cid);
            continue;
        }
        $mode = (string) ($turn['mode'] ?? 'silent');
        // an action CHIM never offered this turn cannot have been chosen legitimately (the catalog row
        // may be deactivated, or the DB not ready). SuggestPrivacy is server-only and is gated on mode
        // + places below instead.
        if (strcasecmp($code, LRG_ACT_INVITE) !== 0
            && !in_array(strtolower($code), array_map('strtolower', (array) ($turn['offered'] ?? [])), true)) {
            lrgLog("gate: dropped $code - it was not offered this turn (mode $mode)", $cid);
            continue;
        }
        // [0.3] Every outgoing command goes through lrgDecorate() (hold / wait / nowarp / warp) and through
        // the say-it-first check (R10): a change SHE chose, with no spoken line in the same reply, does not
        // happen at all - it is dropped and re-asked next turn, never patched with a canned line.
        $emit = function (string $code, array $kv, string $what) use (&$out, &$passed, &$emitted, &$dropped, $parts, $eol, $cid, $turn) {
            $self = !lrgIntentSatisfiedBy($turn, $code, $kv);
            if ($self && lrgNeedsSpokenLine($code, $kv) && lrgSpokenThisTurn() === '') {
                lrgMemSet((string) $turn['npc'], ['say_first' => ['what' => $code . '/' . (string) ($kv['do'] ?? ''), 'at' => lrgNow()]]);
                lrgLog("gate: dropped $code - a self-initiated change with no spoken line (R10)", $cid);
                $dropped = true;
                return;
            }
            $kv = lrgDecorate($turn, $code, $kv, $self);
            $param = lrgKv(['ok' => 1, 'cid' => $cid, 'npc' => $turn['npc']] + $kv);
            $out[] = $parts[0] . '|' . $parts[1] . '|' . $code . '@' . $param . $eol;
            $passed = true;
            $emitted[] = ['code' => $code, 'kv' => $kv];
            lrgNotePending($turn, $code, $kv);
            lrgLog("gate: $what passed -> $param", $cid);
        };
        if (strcasecmp($code, LRG_ACT_START) === 0) {
            $gate = $turn['gate'];
            if (!$gate || empty($gate['ok']) || !in_array($mode, ['private', 'follow'], true)) { lrgLog("gate: dropped StartIntimacy - it was not offered this turn (mode $mode)", $cid); continue; }
            // undress=1 leaves undressing to the player's own OStim settings (per-action stripping);
            // undress=0 tells OStim never to undress in this thread
            $undress = !empty(lrgConfig()['scene_start']['use_ostim_undress_rules']) ? 1 : 0;
            $sexes = lrgSexes($gate['state'] ?? null);
            // [0.3.1 / R2-D10, w1] The scene starts in what the player asked for. lrgPickStart() never
            // read the request before, so "I want you to ride me" began at the standing kiss (pt7 F4).
            // Only a request the recogniser is SURE about steers the start; her own move still begins
            // gently, and the server still never starts a scene by itself - she chose the action.
            $want = (string) ($turn['intent']['act'] ?? '');
            if ((string) ($turn['intent']['conf'] ?? '') !== 'high' || !in_array((string) ($turn['intent']['kind'] ?? ''), ['act', 'yes'], true)
                || !empty($turn['intent']['cant'])) { $want = ''; }
            // [0.3.1 fix pass / D2] "take my clothes off and then let's do missionary" out of a scene:
            // the PRIMARY is the undress, so $want was empty and the missionary half only survived as
            // `after=`. The act the player named in the same breath steers the start itself, exactly as
            // it would if he had named it alone; lrgStartAfter() still sets `after=` when the thread
            // cannot begin there (a furniture start won, or only a gentle scene was startable).
            if ($want === '') {
                $ex = (array) (($turn['intent']['extra'] ?? []));
                if (in_array((string) ($ex['kind'] ?? ''), ['act', 'yes'], true) && empty($ex['cant'])) { $want = (string) ($ex['act'] ?? ''); }
            }
            $pick = function_exists('lrgPickStart') ? (array) lrgPickStart($sexes, lrgCsv($gate['state']['nearf'] ?? ''), $want) : [];
            $start = ['scene' => (string) ($pick['scene'] ?? ''), 'furn' => (string) ($pick['furn'] ?? ''),
                'fscene' => (string) ($pick['fscene'] ?? ''), 'after' => (string) ($pick['after'] ?? '')];
            if ($start['scene'] === '') { $start['scene'] = lrgPickStartScene($sexes); } // the standing start is also the game's fallback when no furniture ref is found
            // a compound "kiss me and then fuck me" lands its second half a few seconds after the start
            $begins = $start['fscene'] !== '' ? $start['fscene'] : $start['scene'];
            if ($start['after'] === '' && ($ea = (string) (($turn['intent']['extra'] ?? [])['scene'] ?? '')) !== ''
                && strcasecmp($ea, $begins) !== 0) { $start['after'] = $ea; }
            if (strcasecmp((string) $start['after'], $begins) === 0) { $start['after'] = ''; } // it already begins there
            $kvStart = ['scene' => $start['scene'], 'undress' => $undress, 'furn' => $start['furn'], 'fscene' => $start['fscene']];
            if ($start['after'] !== '') { $kvStart['after'] = $start['after']; } // additive: an old game script ignores it
            // [0.4 / OWNER ADDENDA 6] the whole safety of paid intimacy is in lrgPayForStart(): it
            // returns the gold that may really move, or -1 when this acceptance must not stand at all
            $pay = lrgPayForStart($turn, (string) ($cmd[1] ?? ''), (string) $cid);
            if ($pay < 0) { continue; }               // dropped, with the reason already logged
            if ($pay > 0) { $kvStart['pay'] = $pay; } // additive: an old game script ignores it and the scene is free
            $emit(LRG_ACT_START, $kvStart + ($gate['game_params'] ?? []), 'StartIntimacy');
            lrgInviteFollowed($turn);
            continue;
        }
        if (strcasecmp($code, LRG_ACT_CONTROL) === 0) {
            if (empty($turn['scene']) || empty($turn['can_act'])) { lrgLog('gate: dropped SceneControl - no scene with this NPC, or not a turn on which it was offered', $cid); continue; }
            $kv = lrgResolveControl((string) ($cmd[1] ?? ''), $turn['options'], $turn['ctx'] ?? null);
            if (!empty($turn['scene_blocked']) && (!is_array($kv) || ($kv['do'] ?? '') !== 'stop')) {
                lrgLog('gate: dropped SceneControl - a rail is blocking this scene, only "stop" gets through', $cid);
                continue;
            }
            if ($kv === null || isset($kv['too_soon'])) {
                lrgLog("gate: dropped SceneControl - '" . ($cmd[1] ?? '') . "' " . ($kv === null ? 'matches no offered option, verb or known position' : 'is above the current step (tier ' . $kv['too_soon'] . ', ceiling ' . ($turn['ctx']['ceiling'] ?? '?') . ')'), $cid);
                continue;
            }
            $emit(LRG_ACT_CONTROL, $kv, 'SceneControl');
            continue;
        }
        if (strcasecmp($code, LRG_ACT_REQUESTACT) === 0) {
            // [0.3 / G5] server-only: the act id is resolved into an ordinary do=goto here, so a game on
            // script version 200 needs no new branch at all.
            if (empty($turn['scene']) || empty($turn['can_act'])) { lrgLog('gate: dropped RequestAct - no scene with this NPC, or not a turn on which it was offered', $cid); continue; }
            $kv = lrgResolveAct((string) ($cmd[1] ?? ''), $turn);
            if ($kv === null) {
                lrgLog("gate: dropped RequestAct - '" . ($cmd[1] ?? '') . "' matches no act that is open right now", $cid);
                continue;
            }
            $emit(LRG_ACT_CONTROL, $kv, 'RequestAct');
            continue;
        }
        if (strcasecmp($code, LRG_ACT_CLOTHING) === 0) {
            $inScene = !empty($turn['scene']) && !empty($turn['can_act']);
            if (!$inScene && (empty($turn['gate']['ok']) || !in_array($mode, ['private', 'follow'], true))) { lrgLog('gate: dropped Clothing - no scene with this NPC and the hard gates did not pass', $cid); continue; }
            $kv = lrgResolveClothing((string) ($cmd[1] ?? ''));
            if ($kv === null) { lrgLog("gate: dropped Clothing - '" . ($cmd[1] ?? '') . "' is not undress / dress", $cid); continue; }
            // the model echoes the player, and "my clothes" / "your clothes" invert between the two mouths:
            // when the player said it plainly, the player's reading wins over the model's
            $kv = lrgCorrectFromIntent($turn, $kv, (string) $cid);
            // outside a scene the game repeats the witness gate: hand it the same limits BeginIntimacy gets
            // (without them the game assumes the strictest reading: 0 witnesses, companions count)
            if (!$inScene) { $kv += ($turn['gate']['game_params'] ?? []); }
            $emit(LRG_ACT_CLOTHING, $kv, 'Clothing');
            if (!$inScene) { lrgInviteFollowed($turn); }
            continue;
        }
        if (strcasecmp($code, LRG_ACT_INVITE) === 0) {
            // SERVER-ONLY: recorded here, never sent to the game
            if ($mode !== 'public' || empty($turn['places'])) { lrgLog("gate: dropped Invite - not offered this turn (mode $mode)", $cid); continue; }
            // R10(c) / 6.8: SuggestPrivacy is one of her hard moves. It never goes through $emit, so its
            // say-it-first check lives here: a silent invitation is one the owner never hears, while the
            // server would still flip to mode follow and she would act on it later.
            if (lrgSpokenThisTurn() === '') {
                lrgMemSet((string) $turn['npc'], ['say_first' => ['what' => lrgShortCode(LRG_ACT_INVITE), 'at' => lrgNow()]]);
                lrgLog('gate: dropped Invite - a self-initiated move with no spoken line (R10)', $cid);
                $dropped = true;
                continue;
            }
            $place = lrgResolvePlace((string) ($cmd[1] ?? ''), array_keys($turn['places']));
            $ttl = (int) (lrgConfig()['invitation']['ttl_seconds'] ?? 1800);
            $state = $turn['gate']['state'] ?? [];
            lrgMemSet((string) $turn['npc'], ['invite' => ['at' => lrgNow(), 'expires' => lrgNow() + $ttl, 'place' => $place, 'loc' => (string) ($state['loc'] ?? ''), 'state' => 'pending']]);
            $passed = true;
            lrgLog("gate: Invite recorded (place=$place, ttl={$ttl}s) - not sent to the game", $cid);
            $move = lrgLeadTheWayLine($turn, $place, $parts, $eol);
            if ($move !== null) { $out[] = $move; }
            continue;
        }
        lrgLog("gate: dropped unknown glue action $code", $cid);
    }
    // [pt18-quest] THE CLICK-FREE QUEST ENTRY (lib/lrg_factions.php lrgFacQuestNet): a plan Phase 2 decided BEFORE the
    // LLM from the player's own words becomes ONE ExtCmdLRG_QuestEntry line here, after the model's own lines - D1
    // only, the escort pattern. It reads Phase 2's turn (LRG_DLG_TURN), never Phase 1's, so it needs no Phase 1 turn;
    // under SHARMAT this gate is not registered at all (functions.php), so the entry is idle there like the escort.
    if (lrgEnabled() && !defined('LRG_SHARMAT_PRESENT') && function_exists('lrgFacQuestNet')) { $out = lrgFacQuestNet($out); }
    // [0.3] Fixed order after the LLM's own lines: the say-first check (above, inside $emit), then the
    // safety net, then the carrier - and only when nothing else went out.
    if ($turn && lrgEnabled() && !defined('LRG_SHARMAT_PRESENT') && (string) ($turn['npc'] ?? '') !== '') {
        // [0.5.4 / pt13] follow / wait / release: ExtCmdLRG_Escort, outside a scene with her only
        $out = lrgEscortNet($turn, $out, $emitted);
        $out = lrgDropUnaskedGoto($turn, $out, $emitted);
        $net = lrgSafetyNet($turn, $emitted);
        if ($net !== null) {
            $out[] = $net['line'];
            $passed = true;
            $emitted[] = ['code' => $net['code'], 'kv' => $net['kv']];
            lrgNotePending($turn, $net['code'], $net['kv']);
        }
        // [0.3.1 / D8, wire agreement w2] the SECOND half of a compound request, as an ordinary command
        // of its own with its own cid - "take my clothes off and then missionary" now does both
        $second = lrgSecondCommand($turn, $emitted);
        if ($second !== null) {
            $out[] = $second['line'];
            $passed = true;
            $emitted[] = ['code' => $second['code'], 'kv' => $second['kv']];
            // [fix pass] the emitted-vs-confirmed watchdog covers it too: a second line the game never
            // answers is exactly the failure w2 is most likely to produce, and it used to WARN nothing
            lrgNotePending($turn, $second['code'], $second['kv'], (string) ($second['cid'] ?? ''));
        }
        if (!$emitted) { $carrier = lrgHoldCarrier($turn); if ($carrier !== null) { $out[] = $carrier; } }
        lrgRecordProposal($turn, $emitted);
        // [0.5.5 / addendum 11 (c)] CHIM's own ComeCloser / MoveTo / TravelTo / FollowPlayer: judged by the next snapshots
        lrgMoveWatchNote($turn, $out);
    }
    // R6: remember whether she actually moved things along on her lead tick (the next tick is worded accordingly).
    // [0.3] A turn whose command was dropped for having no spoken line does NOT count as idle: the next turn
    // already re-asks her to say the line, and "you let the moment pass, make a change" on top of that would
    // be two contradictory pressures and a second wasted LLM + TTS turn.
    if ($turn && ($turn['mode'] ?? '') === 'scene' && !empty($turn['can_act']) && lrgEnabled() && !$dropped) {
        $idle = (!empty($turn['lead']) && !$passed) ? (int) ($turn['lead_idle'] ?? 0) + 1 : 0;
        if ($idle !== (int) ($turn['lead_idle'] ?? 0) || $idle === 0) { lrgMemSet((string) $turn['npc'], ['lead_idle' => $idle > 0 ? $idle : null]); }
    }
    return $out;
}

// ---------------------------------------------------------------- [0.4] paid intimacy, the gate half
/**
 * [0.4] The amount the LLM put in the AMOUNT slot of BeginIntimacy, read out of the wire parameter.
 * Two readings on purpose: `amount=<n>` inside a k=v string, and a bare integer (which is what
 * metadata.parameter_template '{{parameters.amount}}' produces). ANYTHING ELSE IS 0, so a catalog row
 * left over from an older version - which puts the target's NAME there - degrades to a free scene
 * instead of being misread as a figure.
 */
function lrgAmountFromParam(string $param): int
{
    $p = trim($param);
    if ($p === '') { return 0; }
    if (stripos($p, 'amount=') !== false) {
        $kv = lrgParseKv($p);
        return max(0, (int) ($kv['amount'] ?? 0));
    }
    return preg_match('/^\d{1,7}$/', $p) ? max(0, (int) $p) : 0;
}

/**
 * [0.4 / OWNER ADDENDA 6] How much gold may really leave the player's purse for this start, or -1 when
 * the acceptance must not stand at all. Five rules, and they are the whole safety of the feature:
 *
 *  1. pay = min(what SHE accepted, the highest figure the PLAYER said out loud). Gold may never exceed
 *     a number the player named himself: if she names 200 and he never said a figure, pay is 0.
 *  2. she accepted BELOW her own floor and is not willing anyway -> DROP the action. (A willing NPC may
 *     accept anything, including a token: that is a gift, not a price.)
 *  3. the player cannot afford it -> DROP the action, and leave the reason in lrg_memory.last_result so
 *     her very NEXT turn says, in character, that his purse was empty.
 *  4. she is not for sale -> drop the PAY and keep the action. She was willing for reasons of her own;
 *     no gold moves. Never the other way round.
 *  5. otherwise the figure goes out as pay=<n>, and `paid=` on the game's own scene push is what the
 *     halved relationship gain and the memory are built from - never this number.
 */
function lrgPayForStart(array $turn, string $param, string $cid): int
{
    $cfg = (array) (lrgConfig()['paid_intimacy'] ?? []);
    $gate = (array) ($turn['gate'] ?? []);
    $price = (array) ($gate['price'] ?? []);
    $paid = (array) ($gate['paid'] ?? []);
    $npc = (string) ($turn['npc'] ?? '');
    $amount = lrgAmountFromParam($param);
    if (empty($cfg['enabled'])) { return 0; }

    // rule 1
    // [pt15, ruling A] an "if" / an implied figure is never money on the table: only a FIRM one caps pay
    $said = (array_key_exists('firm', $paid) && empty($paid['firm'])) ? 0 : max(0, (int) ($paid['offer'] ?? 0));
    $pay = min($amount, $said);
    if ($amount > 0 && $said <= 0) {
        lrgLog("gate: StartIntimacy - she named $amount septims but the player never offered a figure: nothing is charged", $cid);
    } elseif ($amount > $said && $said > 0) {
        lrgLog("gate: StartIntimacy - she asked $amount septims, the player offered $said: the lower figure stands", $cid);
    }
    if ($pay <= 0) { return 0; }

    // rule 4 before the rest: no gold ever moves for somebody who is not for sale
    if (empty($price['for_sale'])) {
        lrgLog('gate: StartIntimacy keeps the action and drops the payment - ' . $npc
            . ' is not for sale (' . (string) ($price['why'] ?? '?') . '), so she wanted this for her own reasons', $cid);
        return 0;
    }
    // rule 2
    $floor = (int) ($price['gold'] ?? 0);
    $willing = !empty($gate['interest']['willing']);
    if (!$willing && empty($price['free']) && $pay < $floor) {
        lrgLog("gate: dropped StartIntimacy - accepted $pay septims, below her own floor of $floor", $cid);
        return -1;
    }
    // rule 3
    $purse = (int) (($gate['state'] ?? [])['pgold'] ?? 0);
    if ($pay > $purse) {
        lrgLog("gate: dropped StartIntimacy - the player cannot afford $pay septims (he is carrying $purse)", $cid);
        if ($npc !== '') {
            lrgMemSet($npc, ['last_result' => ['cmd' => LRG_ACT_START, 'do' => 'start', 'ok' => false,
                'reason' => 'the player does not have that much gold', 'at' => lrgNow(), 'told' => false]]);
        }
        return -1;
    }
    lrgLog("gate: StartIntimacy is paid for - $pay septims (her floor $floor, the player is carrying $purse)", $cid);
    return $pay;
}

// ---------------------------------------------------------------- [0.3] gate helpers
/** "undress" | "dress" | "goto" ... : which resolved commands are a physical change SHE must announce (R10). */
function lrgNeedsSpokenLine(string $code, array $kv): bool
{
    if (strcasecmp($code, LRG_ACT_START) === 0 || strcasecmp($code, LRG_ACT_INVITE) === 0) { return true; }
    return in_array((string) ($kv['do'] ?? ''), ['goto', 'furniture', 'undress', 'dress'], true);
}

/**
 * What she actually said this turn. $GLOBALS['talkedSoFar'] is filled by returnLines()
 * (lib/chat_helper_functions.php:1635) BEFORE the post-process hooks run (lib/data_functions.php:6036);
 * DEBUG_DATA['response'] is the documented fallback for a non-streaming connector, without which every
 * self-initiated change would silently turn into a drop.
 */
function lrgSpokenThisTurn(): string
{
    $t = $GLOBALS['talkedSoFar'] ?? null;
    if (is_array($t)) { $t = implode(' ', array_map('strval', $t)); }
    $t = trim((string) $t);
    if ($t !== '') { return $t; }
    $d = $GLOBALS['DEBUG_DATA']['response'] ?? '';
    return trim(is_string($d) ? $d : '');
}

/**
 * Did the player ask for exactly this command this turn? Then it is not self-initiated (8.2).
 *
 * $strict also compares the PAYLOAD, and is what the safety net asks for. Without it the net could not
 * correct a command that carries the right verb and the wrong target - playtest 6's who-inversion ("take
 * MY clothes off" came back as `who=npc`) survived end to end, because the verb alone matched and the net
 * logged "the reply already carries it". The loose reading stays the default for the say-it-first test:
 * the player DID ask for a change of clothes, so it is not a move of hers that needs announcing.
 *
 * `StartIntimacy` is never satisfied by an intent (G3a/b): an asked-for start is still a start, and a start
 * with no spoken line must not slip through the say-it-first check because the player happened to ask.
 */
function lrgIntentSatisfiedBy(?array $turn, string $code, array $kv, bool $strict = false): bool
{
    $in = (array) ($turn['intent'] ?? []);
    $kind = (string) ($in['kind'] ?? 'none');
    if ($kind === 'none') { return false; }
    $ikv = (array) ($in['kv'] ?? []);
    $do = (string) ($kv['do'] ?? '');
    $same = static function (array $keys) use ($kv, $ikv, $strict): bool {
        if (!$strict) { return true; }
        foreach ($keys as $k) {
            if (isset($ikv[$k]) && (string) ($kv[$k] ?? '') !== (string) $ikv[$k]) { return false; }
        }
        return true;
    };
    if (strcasecmp($code, LRG_ACT_CLOTHING) === 0) { return $kind === $do && $same(['who', 'part']); }
    if (strcasecmp($code, LRG_ACT_START) === 0) { return false; }
    if (strcasecmp($code, LRG_ACT_CONTROL) !== 0) { return false; }
    if ($do === 'goto') { return in_array($kind, ['act', 'yes'], true); }   // lrgDropUnaskedGoto judges the target
    if ($do === 'furniture') { return $kind === 'furniture' && $same(['furn']); }
    if ($do === 'lead') { return $kind === 'lead_npc' || $kind === 'lead_player'; }
    if ($do === 'speed') { return $kind === 'speed' && $same(['speed']); }
    return $kind === $do; // faster / slower / hold / release / climax / winddown / stop / pullout
}

/**
 * [0.3 / G1] The player's own words decide the TARGET; the model only decides the verb. The model echoes
 * the player, so "I want you to take MY clothes off" comes back as `undress you` -> `who=npc`, which
 * undressed the wrong person for a whole playtest. Only a HIGH-confidence recognition of the SAME verb may
 * correct it, and only the payload keys - never the verb, never the decision to act at all.
 */
function lrgCorrectFromIntent(array $turn, array $kv, string $cid): array
{
    $in = (array) ($turn['intent'] ?? []);
    if ((string) ($in['conf'] ?? '') !== 'high') { return $kv; }
    $kind = (string) ($in['kind'] ?? 'none');
    if (!in_array($kind, ['undress', 'dress'], true) || (string) ($kv['do'] ?? '') !== $kind) { return $kv; }
    $ikv = (array) ($in['kv'] ?? []);
    foreach (['who', 'part'] as $k) {
        if (!isset($ikv[$k]) || (string) ($kv[$k] ?? '') === (string) $ikv[$k]) { continue; }
        lrgLog(sprintf('gate: Clothing %s corrected from the player\'s own words: %s -> %s', $k,
            (string) ($kv[$k] ?? '-'), (string) $ikv[$k]), $cid);
        $kv[$k] = $ikv[$k];
    }
    return $kv;
}

/**
 * [0.3 / G2 + G3a + G5.5] The ONE place that assembles an outgoing command, so section 6, 7 and 8 cannot
 * drift apart. Key order is fixed (PROTOCOL 2.2) so the offline tests can match on substrings.
 *  hold=<n>    do not take a lead turn for the next n seconds (clamped 0..600, missing = 0 = today)
 *  wait=end    hold the call until her spoken line is finished (only the start of a scene: OStim's intro
 *              fade would otherwise cut across it)
 *  wait=begin  hold it until her line has started (a change SHE chose)
 *  nowarp=1    never fade-jump; refuse instead. Only on HER moves: what the player asked for may warp.
 */
function lrgDecorate(array $turn, string $code, array $kv, bool $selfInitiated): array
{
    unset($kv['nowarp'], $kv['wait'], $kv['hold']);
    $do = (string) ($kv['do'] ?? '');
    if (in_array($do, ['goto', 'furniture'], true)) {
        // warp and nowarp are mutually exclusive and never both sent. lrgResolveControl sets warp=1 by
        // itself on the free-text path, so a self-initiated goto resolved that way must lose it here.
        $warp = $kv['warp'] ?? null;
        unset($kv['warp']);
        if ($selfInitiated) { $kv['nowarp'] = 1; } elseif ($warp !== null && (string) $warp !== '') { $kv['warp'] = $warp; }
    }
    // every other verb keeps its own key order exactly as v0.2 emitted it (winddown: scene;warp;linger)
    if (strcasecmp($code, LRG_ACT_START) === 0) {
        $kv['wait'] = 'end';
        return $kv;                                          // a start carries no hold: nothing is running yet
    }
    if ($selfInitiated && in_array($do, ['goto', 'furniture', 'undress', 'dress'], true)) { $kv['wait'] = 'begin'; }
    // [0.3 / G2] THE ONE EXCEPTION to "every command on a player speech turn carries hold=": the command
    // that HANDS HER THE LEAD because the player asked for it ("you lead", "surprise me"). LRG_OStim.CmdLead
    // clears leadHoldUntil and then re-applies whatever hold= the same command carries, so decorating this
    // one would gag her for hold_seconds - the exact opposite of what the player said. Any OTHER lead flip
    // (one the model chose while the player is steering) keeps its hold, and lrgHoldCarrier() builds its own
    // kv and is untouched.
    $handOver = $do === 'lead' && (string) ($kv['who'] ?? '') === 'npc'
        && (string) ($turn['intent']['kind'] ?? '') === 'lead_npc';
    $hold = lrgHoldSeconds($turn);
    if ($hold > 0 && !$handOver) { $kv['hold'] = $hold; }
    return $kv;
}

/** How long she must leave the lead alone, from this turn's point of view. 0 = no hold. */
function lrgHoldSeconds(array $turn): int
{
    if (($turn['mode'] ?? '') !== 'scene') { return 0; }
    $cfg = (array) (lrgConfig()['lead'] ?? []);
    $hold = 0;
    if (in_array((string) ($turn['type'] ?? ''), LRG_PLAYER_SPEECH_TYPES, true)) { $hold = (int) ($cfg['hold_seconds'] ?? 150); }
    $mem = lrgMemGet((string) ($turn['npc'] ?? ''));
    if (is_array($mem['proposal'] ?? null)) { $hold = max($hold, (int) ($mem['proposal']['expires'] ?? 0) - lrgNow()); }
    return max(0, min(600, $hold));
}

/** G6: remember an emitted command so the watchdog can say within command_confirm_seconds if it never arrived. */
function lrgNotePending(array $turn, string $code, array $kv, string $cid = ''): void
{
    if (strcasecmp($code, LRG_ACT_INVITE) === 0 || (string) ($turn['npc'] ?? '') === '') { return; }
    // [0.5.5] one memory write for both: the watchdog's pending_cmd and the `sent` record lrgFuncretVerdict reads
    lrgNoteSent($turn, $code, $kv, '', $cid, ['pending_cmd' => ['cid' => (string) ($turn['cid'] ?? ''), 'code' => $code,
        'do' => (string) ($kv['do'] ?? ''), 'at' => lrgNow()]]);
}

/**
 * RequestAct item -> do=goto;scene=<id>, or null.
 * [0.3.1 / F3] The item may now carry a position ("vaginal/reversecowgirl"), and a bare family is
 * resolved through the installed pool instead of through a plain-words search. In playtest 7 the model
 * sent item="vaginal" and the old code fell through to the free-text search, which scored
 * OARE_SpooningFingering highest - the player asked for sex and got fingering. Resolution order:
 * the exact offered key · the act table (with the position) · the whole installed pool · plain words.
 */
function lrgResolveAct(string $item, array $turn): ?array
{
    $t = lrgItemText($item);
    if ($t === '') { return null; }
    $acts = (array) ($turn['acts'] ?? []);
    $ctx = (array) ($turn['ctx'] ?? []);
    $sexes = $ctx['sexes'] ?? null;
    $furn = (string) ($ctx['furn'] ?? '');
    $current = (string) ($ctx['current'] ?? '');
    $npos = (int) ($ctx['npos'] ?? 1);
    $ceiling = (int) ($ctx['req_ceiling'] ?? ($ctx['ceiling'] ?? 4));
    $walk = $current !== '' ? array_keys(lrgSceneWalk($current, (array) ($ctx['live'] ?? []), $ceiling, 3, $sexes, $furn)) : [];
    // 1. exactly what the notes offered, position and all
    $id = isset($acts[$t]) ? $t : '';
    if ($id === '' && function_exists('lrgActBase')) {
        $base = lrgActBase($t);
        if (isset($acts[$base]) && str_contains($t, '/')) { $id = $t; }   // "<offered key>/<position>"
        elseif (isset($acts[$base])) { $id = $base; }
    }
    // 2. the act table, which understands spoken words, roles, positions and the two bodies
    if ($id === '') { $id = lrgMatchAct($t, array_keys($acts), $sexes); }
    // [0.3.1 fix pass] The OFFERED list is what SHE may pick unprompted - a niche family (spanking,
    // footjob, rimjob, facesitting) is deliberately never on it, and an act above the walk is not
    // either. The model only ever sends RequestAct because the PLAYER named something, so a second
    // pass with no filter follows: playtest 7's `item="vaginal"` was "dropped - matches no act that
    // is open right now", and lrgResolveAct('spanking') / ('oralpenis') returned NULL, i.e. silence.
    if ($id === '') { $id = lrgMatchAct($t, [], $sexes); }
    if ($id !== '') {
        $hit = lrgFindActScene($id, $sexes, $furn, $ceiling, $walk, $current, $npos, $current !== '' ? (int) (lrgScene($current)['actors'] ?? 2) : 2);
        if ($hit !== null) {
            $kv = ['do' => 'goto', 'scene' => (string) $hit['id']];
            if (empty($hit['routed'])) { $kv['warp'] = 1; } // no route: the game fades rather than refusing
            return $kv;
        }
        if (isset($acts[lrgActBase($id)])) { return ['do' => 'goto', 'scene' => (string) $acts[lrgActBase($id)]['scene']]; }
    }
    // 3. the plain-words escape hatch stays, filtered EXACTLY like the offered list (in playtest 6 it
    //    was not, which is how an unasked-for warp into rear sex happened)
    if (!function_exists('lrgFindSceneByText') || !$ctx) { return null; }
    $hit = lrgFindSceneByText($t, $current, $sexes, $furn, $ceiling, $walk);
    if (!is_array($hit) || !empty($hit['too_soon']) || empty($hit['id'])) { return null; }
    return ['do' => 'goto', 'scene' => (string) $hit['id']];
}

/**
 * [0.3 / G1] The player asked for an ACT and the model answered with a goto to something else - exactly
 * playtest 6's 19:36:36, where "doggy style" was answered with a kissing scene. That line is dropped so
 * the net can emit the right one. One command per turn stays the rule.
 */
function lrgDropUnaskedGoto(array $turn, array $out, array &$emitted): array
{
    $in = (array) ($turn['intent'] ?? []);
    if (($in['conf'] ?? '') !== 'high') { return $out; }
    $kind = (string) ($in['kind'] ?? '');
    $act = (string) ($in['act'] ?? '');
    // 'act' with a resolved act id: the model may have answered with a goto to something else.
    // 'speed' / 'furniture': the model may have answered with the right verb and the wrong value, which
    // the safety net is about to correct - so the wrong one must not travel alongside the right one.
    if (!in_array($kind, ['speed', 'furniture'], true) && !($kind === 'act' && $act !== '')) { return $out; }
    $npos = (int) ($turn['ctx']['npos'] ?? 1);
    $cid = (string) ($turn['cid'] ?? '');
    $keep = [];
    $kept = [];
    foreach ($emitted as $i => $e) {
        $do = (string) ($e['kv']['do'] ?? '');
        if (strcasecmp($e['code'], LRG_ACT_CONTROL) !== 0) { $kept[] = $e; $keep[$i] = true; continue; }
        if ($kind === 'act') {
            if ($do !== 'goto') { $kept[] = $e; $keep[$i] = true; continue; }
            $s = lrgScene((string) ($e['kv']['scene'] ?? ''));
            if ($s && in_array($act, lrgSceneActs($s, $npos, $turn['ctx']['sexes'] ?? null), true)) { $kept[] = $e; $keep[$i] = true; continue; }
            lrgLog("gate: dropped SceneControl - the player asked for $act", $cid);
            continue;
        }
        if ($do !== $kind || lrgIntentSatisfiedBy($turn, $e['code'], $e['kv'], true)) { $kept[] = $e; $keep[$i] = true; continue; }
        lrgLog(sprintf('gate: dropped SceneControl - the player asked for %s "%s", not "%s"', $kind,
            (string) ($in['kv'][$kind === 'speed' ? 'speed' : 'furn'] ?? '?'),
            (string) ($e['kv'][$kind === 'speed' ? 'speed' : 'furn'] ?? '?')), $cid);
    }
    if (count($kept) === count($emitted)) { return $out; }
    $emitted = $kept;
    $n = -1;
    return array_values(array_filter($out, static function ($line) use (&$n, $keep) {
        if (stripos((string) $line, 'ExtCmdLRG_') === false) { return true; }
        $n++;
        return isset($keep[$n]);
    }));
}

/**
 * [0.3 / G1] The safety net. A running scene, a high-confidence spoken request, and a reply that does not
 * carry the matching action -> the server carries it out itself. There is NO refusal check and no Decline
 * action (owner addendum 3b/3d): inside a running scene the player is never refused.
 * It never starts a scene and never acts outside one. At most one line per turn.
 */
function lrgSafetyNet(array $turn, array $emitted): ?array
{
    $cid = (string) ($turn['cid'] ?? '');
    $skip = static function (string $why) use ($cid) { lrgLog("net skipped: $why", $cid); return null; };
    $cfg = (array) (lrgConfig()['intent'] ?? []);
    if (!($cfg['safety_net'] ?? true)) { return $skip('switched off'); }
    $in = (array) ($turn['intent'] ?? []);
    if (($in['kind'] ?? 'none') === 'none') { return null; }   // nothing was recognised: not worth a line
    if (($turn['mode'] ?? '') !== 'scene' || !empty($turn['scene_blocked']) || empty($turn['can_act'])) { return $skip('not an open scene turn'); }
    if (empty($turn['scene_confirmed'])) { return $skip('the game has not confirmed a running scene'); }
    if (($in['conf'] ?? '') !== 'high') { return $skip('confidence ' . (string) ($in['conf'] ?? '-')); }
    $kinds = (array) ($cfg['net_kinds'] ?? ['undress', 'dress', 'act', 'faster', 'slower', 'speed', 'stop', 'hold', 'release', 'climax', 'winddown', 'furniture', 'lead_npc', 'lead_player', 'yes']);
    if (!in_array((string) $in['kind'], $kinds, true)) { return $skip('kind ' . (string) $in['kind'] . ' is not in net_kinds'); }
    if (!empty($in['cant'])) { return $skip('the game cannot do it: ' . (string) $in['cant']); }
    $kv = (array) ($in['kv'] ?? []);
    if (!$kv) { return $skip('nothing resolved'); }
    $code = in_array((string) $in['kind'], ['undress', 'dress'], true) ? LRG_ACT_CLOTHING : LRG_ACT_CONTROL;
    // strict: the same VERB with a different TARGET is not "already carried". The who-inversion of
    // playtest 6 survived end to end because this compared the verb alone.
    foreach ($emitted as $e) {
        if (lrgIntentSatisfiedBy($turn, $e['code'], $e['kv'], true)) { return $skip('the reply already carries it'); }
    }
    // a net line is by definition player-requested: hold=, never wait=, never nowarp=, never dropped by 8.3
    $kv = lrgDecorate($turn, $code, $kv, false);
    $param = lrgKv(['ok' => 1, 'cid' => $cid, 'npc' => (string) $turn['npc']] + $kv);
    lrgLog('net fired ' . lrgShortCode($code) . ' ' . $param, $cid);
    return ['code' => $code, 'kv' => $kv, 'line' => (string) $turn['npc'] . '|command|' . $code . '@' . $param . "\r\n"];
}

/**
 * [0.3.1 / D8, wire agreement w2] The second command of a COMPOUND request.
 *
 * "take my clothes off and then let's book a missionary" lost its missionary half entirely in playtest
 * 7 (F2): lrgIntentScan() returns on the first pattern that matches. The recogniser now carries the
 * second request in $intent['extra'], and it goes out as an ORDINARY second command in the same reply,
 * with its OWN cid - which is what keeps it alive on the game side:
 *   · LRG_Main.HandleCommand de-duplicates on the key `npc|command|parameter` within 5 s
 *     (LRG_Main.psc:377-383), and the differing cid= already makes the parameter different;
 *   · LRG_OStim refuses a second command only while one is PARKED for her spoken line
 *     (pendingVerb, LRG_OStim.psc:2334 / :2140) - and a player-requested command never carries wait=,
 *     so nothing is ever parked on this path.
 * goto+goto is QUEUED, not dropped, from game script 310 on: LRG_OStim holds the second one in a
 * one-slot queue (DeferCommand :964 / TickDeferred :1002) and retries it about once a second for
 * fQueueWait (MCM, default 20 s), answering it with its original reason only if the deadline passes.
 * On script 300 there is no queue and it is refused at once with "still moving into the previous
 * position" - which is why the two halves of 0.3.1 ship together (PROTOCOL section 0).
 * Same rails as the safety net: a running, confirmed scene and nothing else. OUT of a scene there is
 * no second command; the start carries the act half in scene= / after= (w1).
 */
function lrgSecondCommand(array $turn, array $emitted): ?array
{
    $cfg = (array) (lrgConfig()['intent'] ?? []);
    if (!($cfg['compound'] ?? true)) { return null; }
    $extra = (array) (($turn['intent'] ?? [])['extra'] ?? []);
    $kind = (string) ($extra['kind'] ?? '');
    if ($kind === '' || $kind === 'none') { return null; }
    $cid = (string) ($turn['cid'] ?? '');
    $skip = static function (string $why) use ($cid) { lrgLog("second command skipped: $why", $cid); return null; };
    if (($turn['mode'] ?? '') !== 'scene' || !empty($turn['scene_blocked']) || empty($turn['can_act'])) { return $skip('not an open scene turn'); }
    if (empty($turn['scene_confirmed'])) { return $skip('the game has not confirmed a running scene'); }
    if (!empty($extra['cant']) || !empty($extra['already'])) { return $skip('the game cannot do it: ' . (string) ($extra['cant'] ?? 'already there')); }
    $kinds = (array) ($cfg['net_kinds'] ?? []);
    if ($kinds && !in_array($kind, $kinds, true)) { return $skip('kind ' . $kind . ' is not in net_kinds'); }
    $kv = (array) ($extra['kv'] ?? []);
    if (!$kv) { return $skip('nothing resolved'); }
    $code = in_array($kind, ['undress', 'dress'], true) ? LRG_ACT_CLOTHING : LRG_ACT_CONTROL;
    // never repeat something the reply (or the net) already carries
    $fake = ['intent' => $extra] + $turn;
    foreach ($emitted as $e) {
        if (lrgIntentSatisfiedBy($fake, $e['code'], $e['kv'], true)) { return $skip('the reply already carries it'); }
    }
    $kv = lrgDecorate($turn, $code, $kv, false);
    $cid2 = $cid . 'b'; // its own correlation id: the game de-duplicates on the whole parameter string
    $param = lrgKv(['ok' => 1, 'cid' => $cid2, 'npc' => (string) $turn['npc']] + $kv);
    lrgLog('second command ' . lrgShortCode($code) . ' ' . $param, $cid);
    return ['code' => $code, 'kv' => $kv, 'cid' => $cid2, 'line' => (string) $turn['npc'] . '|command|' . $code . '@' . $param . "\r\n"];
}

// ---------------------------------------------------------------- [0.5.4 / pt13] ESCORT: follow / wait / release
/*
 * PLAYTEST 13, "I couldn't get her to follow me". CHIM chose Follow_<player> three times and CHIM's
 * FollowPlayer really ran (stayAtPlace -> a priority-100 package override, CHIM_FollowPlayerActive=1),
 * but Lisette was back on stage in a running ENGINE SCENE (BardSongs / aaLisetteIdle, snapshot scene=1,
 * gate reason quest_scene) - and a running scene outranks every package. CHIM's own approach logic
 * refuses scene NPCs, and its only tool for them (InterruptScene) is destructive. The glue closes that
 * gap in three places, none of which is intimacy and none of which needs the adult gate:
 *   1. the player's words are recognised in every mode outside a scene with her (lrgIntentEscort);
 *   2. the directive tells the model plainly to choose Follow_<player> (or her real follower entry when
 *      the menuless follower kind is live) - wait / release have no CHIM action here (WaitHere is off in
 *      the catalog), so the glue carries them;
 *   3. the post-LLM gate appends ExtCmdLRG_Escort@...;do=follow|wait|release;safe=<quest patterns> so
 *      the GAME stops a STOPPABLE scene (safe= only) and lets the package win. Never for a companion a
 *      follower framework owns: her own dialogue does that.
 * An old game script answers the unknown code with one "Error: unknown command" funcret and nothing
 * else (LRG_Main.HandleCommand's last branch); the server swallows that funcret and tells her nothing.
 */

/** The lrg_memory row that is about the game install, not about one NPC. */
const LRG_ESCORT_MEM = '*glue*';

/**
 * Did the running game script already answer ExtCmdLRG_Escort with "unknown command" in THIS session?
 * Compared on the session tag of the NPC's snapshot; with no tag at all, for half an hour.
 */
function lrgEscortGameUnknown(string $npc): bool
{
    $m = lrgMemGet(LRG_ESCORT_MEM)['escort_unknown'] ?? null;
    if (!is_array($m)) { return false; }
    $was = (string) ($m['sess'] ?? '');
    if ($was === '') { return lrgNow() - (int) ($m['at'] ?? 0) < 1800; }
    if (isset($GLOBALS['LRG_TEST_NPCSTATE'])) { $st = $GLOBALS['LRG_TEST_NPCSTATE'][$npc] ?? null; }
    else { try { $st = lrgGetNpcState($npc); } catch (Throwable $e) { $st = null; } }
    return (string) (($st ?? [])['sess'] ?? '') === $was;
}

/** The display name CHIM gives one of its own actions this turn ("Follow_Jordan" for FollowPlayer). */
function lrgChimActionName(string $code): string
{
    $n = (string) ($GLOBALS['F_NAMES'][$code] ?? ($code === 'FollowPlayer' ? 'Follow_#PLAYER_NAME#' : $code));
    return str_replace('#PLAYER_NAME#', (string) ($GLOBALS['PLAYER_NAME'] ?? 'Player'), $n);
}

/**
 * What the game last said about her that an escort depends on. Snapshot facts only (the dialogue
 * lane's own view is read live by lrgEscortDlg()):
 *   known  0/1 a snapshot at most escort.snapshot_max_age_seconds old exists
 *   age    its age in seconds (-1 = none)
 *   scene  0/1 she is in an ENGINE scene (snapshot scene=1; not OStim, which is `ostim`)
 *   owner  '' or why a follower framework / the player's party owns her: fw:sff, fw:custom,
 *          teammate, ghost, faction:<name> - the escort then never fires and her own dialogue answers
 *   fol    live | remembered | fac | none - where the follower facts came from
 *   chim   0/1 CHIM's package follow is on (fol chim:1)
 *   sff    0/1 [pt17] the snapshot carries SFF's own half (slot/cap): the framework is installed and answered
 *   take   0/1 [pt17] the game could hand her to Simple Follower Framework right now - nobody's (owner ''),
 *          PotentialFollowerFaction (pff >= 0) and a free slot (cap 1, SFF_CanRecruitMore); `why` says what is missing
 */
function lrgEscortFacts(string $npc): array
{
    $out = ['known' => 0, 'age' => -1, 'scene' => 0, 'owner' => '', 'fol' => 'none', 'chim' => 0, 'sff' => 0, 'take' => 0, 'why' => ''];
    if ($npc === '') { return $out; }
    if (isset($GLOBALS['LRG_TEST_NPCSTATE'])) {
        $st = $GLOBALS['LRG_TEST_NPCSTATE'][$npc] ?? null;
    } else {
        try { $st = lrgGetNpcState($npc); } catch (Throwable $e) { $st = null; }
    }
    if (!is_array($st)) { return $out; }
    $age = (int) ($st['_age'] ?? 9999);
    $out['age'] = $age;
    if ($age > max(1, (int) lrgEscortCfg('snapshot_max_age_seconds', 300))) { return $out; }
    $out['known'] = 1;
    $out['scene'] = (string) ($st['scene'] ?? '0') === '1' ? 1 : 0;
    $fol = lrgFolState($st);
    if ($fol) {
        $out['fol'] = !empty($fol['_remembered']) ? 'remembered' : 'live';
        $fw = (string) ($fol['fw'] ?? 'none');
        if (in_array($fw, ['sff', 'custom'], true)) { $out['owner'] = 'fw:' . $fw; }
        elseif ((int) ($fol['mate'] ?? 0) === 1) { $out['owner'] = 'teammate'; }
        elseif ((int) ($fol['ghost'] ?? 0) === 1) { $out['owner'] = 'ghost'; }
        $out['chim'] = (int) ($fol['chim'] ?? 0) === 1 ? 1 : 0;
        $out['sff'] = isset($fol['slot']) ? 1 : 0;
        if ($out['owner'] === '') {
            // [pt17] the same three tests LRG_Followers.SffRefuseReason makes in the game, from the facts it sent
            $no = [];
            if (!$out['sff']) { $no[] = 'no follower framework answered on the snapshot'; }
            if ((int) ($fol['pff'] ?? -1) < 0) { $no[] = 'not in PotentialFollowerFaction'; }
            if ((int) ($fol['cap'] ?? 0) !== 1) { $no[] = 'no free companion slot'; }
            $out['take'] = $no ? 0 : 1;
            $out['why'] = implode(', ', $no);
        }
    } else {
        // no follower block at all (awareness off, or retired and never seen this session): the two
        // core-path facts that still say "somebody's follower" - the teammate flag and the faction
        $fac = ',' . strtolower((string) ($st['fac'] ?? '')) . ',';
        foreach ((array) lrgEscortCfg('follower_factions', []) as $f) {
            $f = strtolower(trim((string) $f));
            if ($f !== '' && str_contains($fac, ',' . $f . ',')) { $out['owner'] = 'faction:' . $f; $out['fol'] = 'fac'; break; }
        }
        $out['why'] = 'no follower facts on the snapshot';
    }
    if ($out['owner'] === '' && (string) ($st['mate'] ?? '') === '1') { $out['owner'] = 'teammate'; $out['take'] = 0; }
    return $out;
}

/**
 * [pt17] Is this owner the GAME's to route through the escort (escort.sff_owned)? A companion of Simple Follower
 * Framework (SFF's own wait / follow-again through its calls) and CHIM's half-recruited ghost (recruited properly, or
 * walked the old way with the reason voiced). A custom follower's own quest, a teammate without SFF and a
 * follower_factions hit on fac= stay their own dialogue's - and the directive makes her SAY so.
 */
function lrgEscortSffRouted(string $owner): bool
{
    return $owner !== '' && !empty(lrgEscortCfg('sff_owned', true)) && in_array($owner, ['fw:sff', 'ghost'], true);
}

/** The dialogue lane's view of her this request: ['scene' => 0/1, 'verbs' => follower verbs her live list answers]. */
function lrgEscortDlg(string $npc): array
{
    $d = $GLOBALS['LRG_DLG_TURN'] ?? null;
    if (!is_array($d) || $npc === '' || strcasecmp((string) ($d['npc'] ?? ''), $npc) !== 0) { return ['scene' => 0, 'verbs' => []]; }
    return ['scene' => (int) ($d['scene'] ?? 0) === 1 ? 1 : 0,
        'verbs' => array_values(array_map('strval', (array) (($d['fol'] ?? [])['verbs'] ?? [])))];
}

/** Her real follower entries that answer this escort verb (menuless follower kind live), or []. */
function lrgEscortDlgVerbs(string $npc, string $do): array
{
    $map = ['follow' => ['follow', 'recruit'], 'wait' => ['wait'], 'release' => ['dismiss']];
    return array_values(array_intersect($map[$do] ?? [], lrgEscortDlg($npc)['verbs']));
}

/** Is this wire line one of CHIM's own "follow the player" actions, from this NPC? */
function lrgIsChimFollowLine(string $line, string $npc): bool
{
    $parts = explode('|', rtrim($line, "\r\n"), 3);
    if (count($parts) < 3 || ($npc !== '' && strcasecmp(trim($parts[0]), $npc) !== 0)) { return false; }
    $code = trim((string) explode('@', $parts[2], 2)[0]);
    // the code, or the display name the core sends when it cannot map it back ("Follow_Jordan")
    return strcasecmp($code, 'FollowPlayer') === 0 || (bool) preg_match('/^follow_\S/i', $code);
}

/** The code of a wire line, '' for anything that is not "Actor|channel|Code@param". */
function lrgLineCode(string $line): string
{
    $parts = explode('|', rtrim($line, "\r\n"), 3);
    return count($parts) < 3 ? '' : trim((string) explode('@', $parts[2], 2)[0]);
}

/**
 * [0.5.4] The directive for follow / wait / release (lrgBuildRequestDirective's escort branch). Every
 * shape ends in ONE short line of hers; only follow names an action, and only one CHIM really offers.
 */
function lrgEscortDirective(array $turn, array $intent): string
{
    $npc = (string) ($turn['npc'] ?? '') ?: 'she';
    $player = trim((string) ($GLOBALS['PLAYER_NAME'] ?? '')) ?: 'the player';
    $do = (string) ($intent['kv']['do'] ?? 'follow');
    $f = is_array($turn['escort'] ?? null) ? $turn['escort'] : lrgEscortFacts((string) ($turn['npc'] ?? ''));
    $dlg = lrgEscortDlg((string) ($turn['npc'] ?? ''));
    $inScene = !empty($f['scene']) || !empty($dlg['scene']);
    $what = ['follow' => "$player just asked $npc to come along.",
        'wait' => "$player just asked $npc to wait here.",
        'release' => "$player just told $npc she can go back to what she was doing."][$do] ?? "$player just asked $npc to come along.";
    $head = "<player_request>$what This is not intimacy: nothing said above about intimacy applies to it. ";
    // the second thing said in the same breath is named, never promised (mode closed / silent: its plain kind only)
    $ex = (array) ($intent['extra'] ?? []);
    $exWords = '';
    if ($ex) {
        $exWords = in_array((string) ($turn['mode'] ?? ''), ['closed', 'silent'], true) ? lrgIntentPlainKind($ex) : (string) ($ex['words'] ?? '');
    }
    $tail = $exWords !== '' ? " $player also asked for something else in the same breath ($exWords): answer that in $npc's own voice." : '';
    $verbs = lrgEscortDlgVerbs((string) ($turn['npc'] ?? ''), $do);
    // [0.5.5 / owner addendum 11] every shape ends in "never ignore it"; the follow shapes on a stage also say
    // what happens when the GAME refuses (she is told why, in her funcret turn, and says so then) - so she never
    // makes up a reason to stay, and never stays silent either
    if ($verbs) {
        return $head . "It is answered by $npc's own follower dialogue: in this same reply take it up with the matching key from"
            . " <follower_commands> and say one short line - never ignore it. Choose no other action.$tail</player_request>";
    }
    // whether the glue's own line will really carry it (lrgEscortPlan's rails, in the same words)
    $carries = !empty($f['known']) && lrgEscortOn('emit') && !lrgEscortGameUnknown((string) ($turn['npc'] ?? ''));
    $owner = (string) ($f['owner'] ?? '');
    $routed = $owner !== '' && $carries && lrgEscortSffRouted($owner);
    if ($owner !== '' && !$routed) {
        // [pt17 / owner addendum 11] her own companion dialogue is the only way, and it is NOT open in this conversation
        // (the live-verbs shape above is the one that is): she says so plainly instead of agreeing to something nobody carries
        return $head . "$npc already travels with $player as a companion, and that order goes through $npc's own companion dialogue,"
            . " which is not open in this conversation: $npc says so in one short line, in $npc's own voice (ask her the way he always"
            . " does) - never ignore it - and chooses no movement action.$tail</player_request>";
    }
    if ($routed && $owner === 'fw:sff') {
        // [pt17] a companion of Simple Follower Framework: the game does it through SFF's own calls (LRG_Main.EscortFollow /
        // EscortWait / EscortRelease); "you can go" is a wait, parting ways for good is commit-class and stays her own dialogue's
        $game = ['follow' => "the game itself makes $npc follow $player again",
            'wait' => "the game itself makes $npc wait where she is",
            'release' => "the game itself makes $npc stop following and wait where she is - parting ways for good is said in $npc's own companion dialogue"]
            [$do] ?? "the game itself makes $npc follow $player again";
        return $head . "$npc travels with $player as his companion: $game, there is nothing to choose for it. Answer in one short line,"
            . " in $npc's own voice - never ignore it - and choose no movement action.$tail</player_request>";
    }
    $follow = lrgIsOffered('FollowPlayer') ? lrgChimActionName('FollowPlayer') : '';
    if ($do === 'follow') {
        // [pt17] the framework may take her on: a ghost (routed), or a stranger SFF can take right now (escort.sff_promote)
        $handoff = $carries && ($routed || (!empty(lrgEscortCfg('sff_promote', true)) && !empty($f['take'])));
        $join = $handoff ? " If the game can take $npc on as $player's companion it does so by itself; if it cannot, $npc is told why and says so then." : '';
        // addendum 11 (d): a bard mid-song can be stopped - she may say she needs a moment; a scene the game
        // may not end is refused by the GAME, and that refusal is spoken in her funcret turn
        $stage = ($carries && $inScene) ? " The game takes $npc away from what she is busy with first - $npc may say she needs a"
            . " moment. Do not make up a reason to stay: if the game cannot take $npc away, $npc is told why and says so then." : '';
        if ($follow !== '') {
            return $head . "Do it now: choose $follow in this same reply - that is what makes $npc actually walk with $player - and"
                . " answer in one short line - never ignore it.$stage$join Do not describe walking anywhere before it has started.$tail</player_request>";
        }
        if ($carries && ($inScene || $handoff)) {
            return $head . "The game makes $npc walk with $player by itself: agree in one short line, in $npc's own voice - never ignore it - and choose"
                . " no action.$stage$join$tail</player_request>";
        }
        if (!empty($f['chim'])) {
            // [pt17] CHIM's own follow is already on her (Follow_<player> is withheld for that): the truth, not "nothing can"
            return $head . "$npc is already walking with $player: $npc says so in one short line, in $npc's own voice - never ignore it -"
                . " and chooses no movement action.$tail</player_request>";
        }
        return $head . "Nothing can make $npc walk with $player on this turn: $npc says so in one short line, in $npc's own voice,"
            . " with a plain reason (not right now) - never ignore it - and does not describe walking anywhere.$tail</player_request>";
    }
    $noMove = 'choose no movement action this turn' . ($follow !== '' ? " - not $follow either" : '') . '.';
    $how = $do === 'wait'
        ? ($carries ? "There is nothing to choose for it: the game stops $npc following $player by itself." : 'There is no action for it this turn.')
        : ($carries ? "There is nothing to choose for it: the game lets $npc go by itself." : 'There is no action for it this turn.');
    return $head . "$how Answer in one short line, in $npc's own voice - never ignore it - and $noMove$tail</player_request>";
}

/**
 * [0.5.4] Does this reply need the glue's escort line, and where? Pure: reads the turn and the lines.
 * Returns ['do' => follow|wait|release|'', 'why' => reason, 'drop' => [line indexes], 'at' => index | -1,
 *          'log' => whether the turn had anything to do with following at all].
 *  follow   the reply carries CHIM's FollowPlayer (or the player asked with conf=high and the reply
 *           carries no movement action at all - the safety-net shape) AND she is in an ENGINE scene - or, [pt17],
 *           outside one when the player asked and the game can hand her to Simple Follower Framework (take=1)
 *  wait / release   the player asked, conf=high; a FollowPlayer the model chose against those words is dropped
 * Never on a turn inside an intimate scene with the player, never for a companion another framework owns ([pt17] a companion
 * of Simple Follower Framework and CHIM's half-recruited ghost ARE routed - lrgEscortSffRouted),
 * never when her own follower entry answers it, never without a recent snapshot.
 */
function lrgEscortPlan(array $turn, array $lines): array
{
    $npc = (string) ($turn['npc'] ?? '');
    $res = ['do' => '', 'why' => '', 'drop' => [], 'at' => -1, 'log' => false];
    if ($npc === '') { return $res; }
    $in = (array) ($turn['intent'] ?? []);
    $asked = ((string) ($in['kind'] ?? '') === LRG_INTENT_ESCORT && (string) ($in['conf'] ?? '') === 'high')
        ? (string) ($in['kv']['do'] ?? '') : '';
    $follows = [];
    $otherMove = '';
    foreach (array_values($lines) as $i => $line) {
        if (lrgIsChimFollowLine((string) $line, $npc)) { $follows[] = $i; continue; }
        $code = lrgLineCode((string) $line);
        if ($otherMove === '' && $code !== '' && in_array(strtolower($code), array_map('strtolower', LRG_MOVEMENT_ACTIONS), true)) { $otherMove = $code; }
    }
    if ($asked === '' && !$follows) { return $res; }   // nothing about following on this turn: no line at all
    $res['log'] = true;
    $skip = static function (string $why) use ($res): array { $res['why'] = $why; return $res; };
    if (!lrgEscortOn('emit')) { return $skip('switched off (escort.emit)'); }
    if (in_array((string) ($turn['mode'] ?? ''), ['scene', 'outro'], true) || !empty($turn['scene_blocked'])) {
        return $skip('an intimate scene with the player is running');
    }
    if (!in_array((string) ($turn['type'] ?? ''), LRG_PLAYER_SPEECH_TYPES, true)) { return $skip('not a player speech turn'); }
    $do = in_array($asked, ['wait', 'release'], true) ? $asked : (($follows || $asked === 'follow') ? 'follow' : '');
    if ($do === '') { return $skip('nothing to carry'); }
    if ($do === 'follow' && !$follows && $otherMove !== '') { return $skip("the reply chose $otherMove instead"); }
    $f = lrgEscortFacts($npc);
    if (empty($f['known'])) { return $skip('the game has not reported on her recently (snapshot age ' . ($f['age'] < 0 ? 'none' : $f['age'] . 's') . ')'); }
    // [pt17] a companion of Simple Follower Framework, and CHIM's half-recruited ghost, are the GAME's to route now
    // (SFF's own wait / follow-again through its calls; the ghost's proper recruit) - lrgEscortSffRouted; everybody
    // else's companion stays her own dialogue's, and lrgEscortDirective then makes her SAY so instead of agreeing
    $owner = (string) $f['owner'];
    $routed = lrgEscortSffRouted($owner);
    if ($owner !== '' && !$routed) { return $skip('a follower framework owns her (' . $owner . '): her own dialogue does this'); }
    if (lrgEscortDlgVerbs($npc, $do)) { return $skip('her own follower entry answers it this turn'); }
    $dlg = lrgEscortDlg($npc);
    // [pt19h r2 / reach P4] her menu is driven by his words this moment - the fast path clicked a quest line for this sentence ("let's go"
    // at a scene speaker's list) or her dialogue session is open: the escort stands aside, ONE carrier (the click) moves the quest
    if ($do === 'follow' && function_exists('lrgDlgState')) {
        $ds = (array) lrgDlgState($npc);
        $lx = (array) ($ds['last_exec'] ?? []);
        $recent = (string) ($lx['do'] ?? '') === 'pick' && lrgNow() - (int) ($lx['at'] ?? 0) <= 15;
        if ($recent || (function_exists('lrgDlgSessionOpen') && lrgDlgSessionOpen((array) ($ds['session'] ?? [])))) {
            return $skip('her menu is driven by his words this moment (a quest line was just clicked, or her list is open) - the click carries it');
        }
    }
    $inScene = !empty($f['scene']) || !empty($dlg['scene'])
        || in_array('quest_scene', (array) (($turn['gate'] ?? [])['reasons'] ?? []), true);
    // [pt17] the framework may take her: she is SFF's or a ghost (routed), or a stranger SFF can take right now
    // (escort.sff_promote: nobody's, PotentialFollowerFaction, a free slot - lrgEscortFacts `take`). Then the escort
    // carries the follow and CHIM's own Follow_<player> line comes OUT of the batch: CHIM's stayAtPlace would lay its
    // priority-100 override on the new companion; the game re-adds CHIM's follow itself when it has to fall back.
    $canTake = !empty(lrgEscortCfg('sff_promote', true)) && !empty($f['take']);
    $handoff = $routed || $canTake;
    if ($do === 'follow' && !$inScene && !$routed && !($asked === 'follow' && $canTake)) {
        // outside a scene only the player's OWN words (conf=high) send a stranger to the framework; the model's own
        // Follow_<player> stays CHIM's package follow, exactly as before
        $note = ($asked !== 'follow' && $canTake) ? ' (the player did not ask - the model chose it)'
            : ((string) $f['why'] !== '' ? ' (not promotable: ' . $f['why'] . ')' : '');
        return $skip('she is in no engine scene - CHIM\'s own follow is enough' . $note);
    }
    if (lrgEscortGameUnknown($npc)) { return $skip('the game script answered "unknown command" to it earlier this session (it predates the escort)'); }
    $res['do'] = $do;
    if ($do === 'follow') {
        $res['why'] = ($follows ? 'the reply chose ' . lrgLineCode((string) array_values($lines)[$follows[0]]) : 'the player asked, the reply carries no movement')
            . ($inScene ? ' and she is in an engine scene' : '')
            . ($routed ? " - $owner is the game's to route" : ($canTake ? ' - the game can make her his companion' : ''));
    } else {
        $res['why'] = 'the player asked her to ' . ($do === 'wait' ? 'wait' : 'go back');
    }
    $res['drop'] = ($do !== 'follow' || $handoff) ? $follows : [];
    $res['at'] = ($do === 'follow' && $follows) ? $follows[0] : -1;
    return $res;
}

/** [0.5.4] Apply lrgEscortPlan() to the outgoing lines: drop, then insert / append ExtCmdLRG_Escort. */
function lrgEscortNet(array $turn, array $out, array $emitted): array
{
    $out = array_values($out);
    $plan = lrgEscortPlan($turn, $out);
    if (!$plan['log']) { return $out; }
    $cid = (string) ($turn['cid'] ?? '');
    if ($plan['do'] === '') { lrgLog('escort skipped: ' . $plan['why'], $cid); return $out; }
    $npc = (string) $turn['npc'];
    $safe = [];
    foreach ((array) lrgEscortCfg('safe_quests', []) as $p) {
        $p = trim((string) preg_replace('/[^A-Za-z0-9_*?]/', '', (string) $p));
        if ($p !== '') { $safe[] = $p; }
    }
    $param = lrgKv(['ok' => 1, 'cid' => $cid, 'npc' => $npc, 'do' => $plan['do'], 'safe' => implode(',', $safe)]);
    $line = $npc . '|command|' . LRG_ACT_ESCORT . '@' . $param . "\r\n";
    foreach ($plan['drop'] as $i) {
        $dropWhy = $plan['do'] === 'follow'
            ? 'the escort carries the follow to her framework (the game re-adds CHIM\'s follow itself if it has to fall back)'
            : 'the player asked her to ' . ($plan['do'] === 'wait' ? 'wait' : 'go back');
        lrgLog('escort: dropped ' . lrgLineCode((string) $out[$i]) . ' - ' . $dropWhy, $cid);
        unset($out[$i]);
    }
    $out = array_values($out);
    if ($plan['at'] >= 0) { array_splice($out, $plan['at'], 0, [$line]); } else { $out[] = $line; }
    $f = lrgEscortFacts($npc);
    lrgLog(sprintf('escort %s npc=%s scene=%d fol=%s owner=%s take=%d why=%s -> %s', $plan['do'], $npc, (int) $f['scene'], (string) $f['fol'],
        (string) $f['owner'] !== '' ? (string) $f['owner'] : '-', (int) $f['take'], $plan['why'], $param), $cid);
    // the watchdog's one pending slot stays with the glue command of the same reply, if there is one; the
    // `sent` record (what lrgFuncretVerdict judges the escort's answer by) is written either way
    if (!$emitted) { lrgNotePending($turn, LRG_ACT_ESCORT, ['do' => $plan['do']]); } else { lrgNoteSent($turn, LRG_ACT_ESCORT, ['do' => $plan['do']]); }
    return $out;
}

/**
 * [0.3 / G2] When a player speech turn inside a scene emitted nothing at all, one no-op command still
 * carries hold= to the game - with the CURRENT leader, never "npc", which would silently flip a scene
 * the player had taken over. Throttled: the game's own leadHoldUntil is monotonic, so a skipped carrier
 * costs nothing, while an unthrottled one would pay a funcret round trip on every single turn.
 */
function lrgHoldCarrier(array $turn): ?string
{
    if (($turn['mode'] ?? '') !== 'scene' || !empty($turn['scene_blocked']) || empty($turn['can_act'])) { return null; }
    if (!in_array((string) ($turn['type'] ?? ''), LRG_PLAYER_SPEECH_TYPES, true)) { return null; }
    // Never during a wind-down. The carrier is a no-op `do=lead` with the CURRENT leader, but the game's
    // CmdLead cancels a running wind-down, so the player who asked to wind down and then said anything
    // during the linger would find the scene never ending. The game holds the same rail on its side; this
    // one also protects a game that has not been updated yet.
    if ((string) (($turn['scene']['wd'] ?? '0')) === '1') { return null; }
    $hold = lrgHoldSeconds($turn);
    if ($hold <= 0) { return null; }
    $npc = (string) $turn['npc'];
    $mem = lrgMemGet($npc);
    $every = max(1, intdiv((int) ((lrgConfig()['lead'] ?? [])['hold_seconds'] ?? 150), 2));
    if (lrgNow() - (int) ($mem['hold_sent_at'] ?? 0) < $every) { return null; }
    $leader = strtolower((string) ($turn['scene']['leader'] ?? 'npc'));
    if (!in_array($leader, ['npc', 'player', 'auto'], true)) { $leader = 'npc'; }
    $param = lrgKv(['ok' => 1, 'cid' => (string) $turn['cid'], 'npc' => $npc, 'do' => 'lead', 'who' => $leader, 'hold' => $hold]);
    // [0.5.5] src=carrier: whatever the game answers to a no-op nobody asked for is never spoken
    lrgNoteSent($turn, LRG_ACT_CONTROL, ['do' => 'lead'], 'carrier', '', ['hold_sent_at' => lrgNow()]);
    lrgLog('hold carried -> ' . $param, (string) $turn['cid']);
    return $npc . '|command|' . LRG_ACT_CONTROL . '@' . $param . "\r\n";
}

/**
 * [0.3 / R11] The proposal is only PENDING when she really asked - i.e. when she said something this turn.
 * The cooldown and the per-scene cap, on the other hand, count the ASKING: the notes cost a turn whether or
 * not the model put words to them, and counting only the spoken ones let her pick the same act again on the
 * very next turn.
 * Nothing is recorded at all when the same reply already changed the act: the question would be about a
 * change that has already happened, and a "yes" inside the ttl would fire a SECOND one.
 */
function lrgRecordProposal(array $turn, array $emitted = []): void
{
    $p = $turn['propose'] ?? null;
    if (!is_array($p)) { return; }
    $npc = (string) $turn['npc'];
    $cid = (string) ($turn['cid'] ?? '');
    foreach ($emitted as $e) {
        if (strcasecmp((string) $e['code'], LRG_ACT_CONTROL) === 0 && (string) ($e['kv']['do'] ?? '') === 'goto') {
            lrgLog('proposal ' . (string) $p['act'] . ' not recorded: the same reply already changed the act', $cid);
            return;
        }
    }
    $cfg = (array) ((lrgConfig()['lead'] ?? [])['ask_first'] ?? []);
    // `ev` (and `sync`) belong to the GAME push this row was built from. Handing them back to
    // lrgStoreScene() makes lrgTrackProgression() read an ev=start row as a brand-new scene - resetting
    // _tier_since, i.e. her tier-ceiling clock AND R11's own min_act_seconds clock - or count a second
    // climax off an ev=climax row. The row stays active: lrgStoreScene only closes on ev === 'end'.
    $scene = (array) ($turn['scene'] ?? []);
    unset($scene['_npc'], $scene['_age'], $scene['ev'], $scene['sync']);
    $scene['_proposals'] = (int) ($scene['_proposals'] ?? 0) + 1;
    $scene['_last_prop_at'] = lrgNow();
    lrgStoreScene($npc, $scene);
    if (lrgSpokenThisTurn() === '') {
        lrgLog('proposal ' . (string) $p['act'] . ' counted but not pending: she said nothing this turn', $cid);
        return;
    }
    lrgMemSet($npc, ['proposal' => ['act' => (string) $p['act'], 'at' => lrgNow(),
        'expires' => lrgNow() + (int) ($cfg['ttl_seconds'] ?? 90), 'turns' => 0]]);
    lrgLog('proposal recorded: ' . (string) $p['act'], $cid);
}

/** G6 (b): what the LLM actually chose, before the gate judged it. */
function lrgLogLlm(array $turn, array $actions): void
{
    $code = 'none'; $item = ''; $chim = '';
    foreach ($actions as $line) {
        $parts = explode('|', rtrim((string) $line, "\r\n"), 3);
        $cmd = explode('@', $parts[2] ?? '', 2);
        if (stripos((string) ($cmd[0] ?? ''), 'ExtCmdLRG_') === 0 || preg_match('/^(Begin_?Intimacy|Change_?Intimacy|Change_?Clothing|Suggest_?Privacy|Request_?Act)$/i', trim((string) ($cmd[0] ?? '')))) {
            if ($code === 'none') {
                $code = trim((string) $cmd[0]);
                $item = substr(trim((string) ($cmd[1] ?? '')), 0, 80);
            }
        } elseif ($chim === '' && count($parts) === 3 && strtolower(trim((string) $parts[1])) === 'command' && trim((string) ($cmd[0] ?? '')) !== '') {
            // [0.5.4 / pt13] one of CHIM's OWN actions: playtest 13 logged "action=none" three times while
            // CHIM was sending FollowPlayer, and only output_to_plugin.log could say so. Additive tail.
            $chim = substr((string) preg_replace('/[^A-Za-z0-9_#]/', '', (string) $cmd[0]), 0, 40);
        }
    }
    lrgLog(sprintf('llm npc=%s action=%s item="%s" spoke=%d%s', (string) $turn['npc'], lrgShortCode($code), $item, strlen(lrgSpokenThisTurn()),
        $chim !== '' ? ' chim=' . $chim : ''), (string) ($turn['cid'] ?? ''));
}

/** A pending invitation is "followed" once BeginIntimacy or ChangeClothing passed the gate on a follow turn. */
function lrgInviteFollowed(array $turn): void
{
    if (($turn['mode'] ?? '') !== 'follow' || !is_array($turn['invite'] ?? null)) { return; }
    lrgMemSet((string) $turn['npc'], ['invite' => ['state' => 'followed'] + $turn['invite']]);
}

/** home | room | quiet, only one that is backed by facts this turn; anything else falls back to the vaguest real place. */
function lrgResolvePlace(string $item, array $allowed): string
{
    $t = lrgItemText($item);
    $want = preg_match('/\b(home|house|my place|mine|farm|shop|hall)\b/', $t) ? 'home'
        : (preg_match('/\b(room|inn|upstairs|bed|rent\w*)\b/', $t) ? 'room' : 'quiet');
    if (in_array($want, $allowed, true)) { return $want; }
    return in_array('quiet', $allowed, true) ? 'quiet' : (string) ($allowed[0] ?? 'quiet');
}

/**
 * Optional, default OFF (invitation.lead_the_way): after recording an invite, replace the line with a CHIM movement
 * action - only one whose code is in this turn's ENABLED_FUNCTIONS. Not verified in game.
 */
function lrgLeadTheWayLine(array $turn, string $place, array $parts, string $eol): ?string
{
    if (empty(lrgConfig()['invitation']['lead_the_way'])) { return null; }
    $state = $turn['gate']['state'] ?? [];
    $nhome = trim((string) ($state['nhome'] ?? ''));
    if ($place === 'home' && $nhome !== '' && ($state['home'] ?? '') !== '1' && lrgIsOffered('TravelTo')) {
        return $parts[0] . '|' . $parts[1] . '|TravelTo@' . str_replace(['@', '|', "\r", "\n"], ' ', $nhome) . $eol;
    }
    if ($place === 'room' && ($state['prent'] ?? '') === '1' && lrgIsOffered('FollowPlayer')) {
        return $parts[0] . '|' . $parts[1] . '|FollowPlayer@' . $eol;
    }
    return null;
}

/** Bare value or {"item": ..} JSON -> lowercase text. */
function lrgItemText(string $item): string
{
    $item = trim($item);
    if ($item !== '' && $item[0] === '{') { // multi-property schemas arrive as JSON, single-property ones as the bare value
        $j = json_decode($item, true);
        $item = is_array($j) ? (string) ($j['item'] ?? $j['target'] ?? '') : '';
    }
    return strtolower(trim($item, " \t\r\n\"'."));
}

/**
 * Map what the LLM wrote in "item" onto a verb of the contract (PROTOCOL 3 / 6.4), an offered option, or any position
 * of the index named in plain words. Resolution order: stop > winddown > P-key > pace > hold / release > climax >
 * pullout > lead > furniture label > label / id echo > free-text position search.
 * $ctx = ['current','live','sexes','furn','ceiling','tier','reached','furn_options','auto_ok'] (null = keys only).
 * Null = no match (dropped, NPC just talks); ['too_soon' => tier] = exists, but above the current step.
 */
function lrgResolveControl(string $item, array $options, ?array $ctx = null): ?array
{
    $t = lrgItemText($item);
    if ($t === '') { return null; }
    // stop is a rail: it wins every tie, from every state, with any other words around it - but a
    // NEGATED stop ("don't stop") is a negation, not a tie
    if (!preg_match('/\b(?:dont|don\'t|do not|never|no need to|rather not)\s+(?:want\s+to\s+)?(?:stop|quit|halt|end)\b|\bnot enough\b/', $t)
        && preg_match('/\b(stop|stopping|enough|halt|quit)\b|\bno more\b|^end\b|\bend (it|this|the scene)\b/', $t)) { return ['do' => 'stop']; }
    if (preg_match('/\b(wind(ing)?[ -]?down|afterglow|cool(ing)?[ -]?down)\b/', $t)) {
        $scene = ''; $warp = 0;
        if ($ctx !== null) {
            // the index does the walk once and reports whether it found a route (its own hop budget,
            // scene_index.afterglow_max_hops, instead of a second walk with the number written in here)
            if (function_exists('lrgPickAfterglow')) {
                $a = (array) lrgPickAfterglow($ctx['current'], $ctx['live'], $ctx['sexes'], $ctx['furn']);
                $scene = (string) ($a['scene'] ?? '');
                $warp = ($scene !== '' && empty($a['routed'])) ? 1 : 0;
            } else {
                $scene = (string) lrgPickAfterglowScene($ctx['current'], $ctx['live'], $ctx['sexes'], $ctx['furn']);
                if ($scene !== '' && !in_array(strtolower($scene), array_map('strtolower', $ctx['live']), true)
                    && !isset(lrgSceneWalk($ctx['current'], $ctx['live'], LRG_TIERS['sexual'], 4, $ctx['sexes'], $ctx['furn'])[strtolower($scene)])) { $warp = 1; }
            }
        }
        return ['do' => 'winddown', 'scene' => $scene, 'warp' => $warp, 'linger' => max(5, min(120, (int) (lrgConfig()['winddown']['linger_seconds'] ?? 20)))];
    }
    if (preg_match('/^p\s*(\d+)\b/', $t, $m) && isset($options['P' . $m[1]])) { return ['do' => 'goto', 'scene' => $options['P' . $m[1]]['id']]; }
    if (preg_match('/^(?:speed|pace)\s*(?:to\s*)?(\d)$/', $t, $m)) { return ['do' => 'speed', 'speed' => (int) $m[1]]; }
    if (preg_match('/\b(faster|quicker|harder|speed up)\b/', $t)) { return ['do' => 'faster']; }
    if (preg_match('/\b(slower|gentler|softer|slow down)\b/', $t)) { return ['do' => 'slower']; }
    // not anchored: "hold it there" is the phrase the notes tell the player to say, and it must not
    // fall through to the free-text search, where "hold" is a synonym for an embrace. "hold me" /
    // "hold my hand" carry an object and still reach the position search below.
    if (preg_match('/\bhold (it|on|back|there|still)\b|^hold$|\bnot yet\b|\bstay (like )?(this|that)\b|^wait\b|\bstall\b/', $t)) { return ['do' => 'hold']; }
    if (preg_match('/\b(release|let go|carry on|keep going|go on)\b/', $t)) { return ['do' => 'release']; }
    if (preg_match('/\b(climax\w*|orgasm\w*|cum|cumming|finish\w*)\b|\bcome (now|together)\b|\bcome (for|with) me\b/', $t)) {
        if ($ctx === null || (int) ($ctx['tier'] ?? 0) < LRG_TIERS['sensual']) { return null; } // offered only once things are at least sensual
        $who = preg_match('/\b(both|together|us|we)\b|\bwith (me|you)\b/', $t) ? 'both'
            : (preg_match('/\b(you|your|player)\b|\b(inside|in|for) me\b/', $t) ? 'player' : 'npc');
        return ['do' => 'climax', 'who' => $who];
    }
    if (preg_match('/\bpull(ing)? ?out\b|\bwithdraw\b/', $t)) { return ['do' => 'pullout']; }
    if (preg_match('/\b(lead|leads|leading|take over|takes over|in charge|auto|automatic)\b/', $t)) {
        if (preg_match('/\bauto(matic)?\b/', $t)) {
            if (empty($ctx['auto_ok'])) { return null; } // OStim's auto mode would skip the ladder
            return ['do' => 'lead', 'who' => 'auto'];
        }
        // Only the unambiguous keys the notes offer are accepted: a name, or the word "player".
        // "you lead" / "i lead" mean opposite things depending on who says them, and the model echoes
        // the player's wording, so a bare second-person phrase is dropped: she just answers in words.
        $pn = strtolower(trim((string) ($ctx['player'] ?? '')));
        $nn = strtolower(trim((string) ($ctx['npc'] ?? '')));
        if (preg_match('/\bplayer\b/', $t) || ($pn !== '' && str_contains($t, $pn))) { return ['do' => 'lead', 'who' => 'player']; }
        if ($nn !== '' && str_contains($t, $nn)) { return ['do' => 'lead', 'who' => 'npc']; }
        return null;
    }
    foreach ((array) ($ctx['furn_options'] ?? []) as $type => $fo) {
        $words = array_filter(array_map('strtolower', array_merge([(string) $type, (string) ($fo['label'] ?? '')], (array) ($fo['words'] ?? []))), 'strlen');
        foreach ($words as $w) {
            if (preg_match('/\b' . preg_quote($w, '/') . '\b/', $t) && !empty($fo['scene'])) {
                return ['do' => 'furniture', 'furn' => (string) ($fo['furn'] ?? $type), 'scene' => (string) $fo['scene']]; // always a scene id: OStim must not pick one itself
            }
        }
    }
    foreach ($options as $o) { // the model sometimes echoes the label or the scene id instead of the key
        if (strcasecmp($t, $o['id']) === 0 || stripos($o['label'], $t) === 0) { return ['do' => 'goto', 'scene' => $o['id']]; }
    }
    if ($ctx === null) { return null; }
    // plain words: search the whole index; scenes that are routable right now win ties
    $walk = lrgSceneWalk($ctx['current'], $ctx['live'], (int) $ctx['ceiling'], 3, $ctx['sexes'], $ctx['furn']);
    $live = array_map('strtolower', $ctx['live']);
    $hit = lrgFindSceneByText($t, $ctx['current'], $ctx['sexes'], (string) $ctx['furn'], (int) $ctx['ceiling'], array_merge(array_keys($walk), $live));
    if ($hit === null) { return null; }
    if (!empty($hit['too_soon'])) { return ['too_soon' => $hit['tier']]; }
    $k = strtolower($hit['id']);
    $kv = ['do' => 'goto', 'scene' => $hit['id']];
    if (!isset($walk[$k]) && !in_array($k, $live, true)) { $kv['warp'] = 1; } // no confirmed route: the game may warp (MCM bAllowWarp)
    return $kv;
}

/**
 * "undress" | "dress", optionally + both / you, optionally + one part -> do / who / part. Unknown text = null (dropped).
 * who=player only when the verb is aimed straight at "you" / "the player" ("undress you"): the model often echoes the
 * player's own words ("take your clothes off"), and those mean the NPC.
 */
function lrgResolveClothing(string $item): ?array
{
    $t = lrgItemText($item);
    if ($t === '') { return null; }
    // [0.3] "my <garment>" in the MODEL's item can only mean the player's own clothes: the one reading
    // that was ambiguous here too. Everything else keeps its v0.2 NPC-perspective behaviour.
    $who = preg_match('/\b(both|us|we|each other|together|everyone)\b/', $t) ? 'both'
        : (preg_match('/\b(?:un|re)?(?:dress\w*|strip\w*|disrobe\w*)\s+(?:you|me|the player|player)\b|^(?:you|me|player)\b|\b(?:off|from) (?:you|me)\b/', $t)
            || preg_match('/\bmy (clothes|armou?r|shirt|boots|things|gear|tunic|robes?|pants|trousers|gloves|helmet|hood)\b/', $t) ? 'player' : 'npc');
    $part = 'all';
    foreach (['feet' => 'feet|foot|boots?|shoes?', 'hands' => 'hands?|gloves?|gauntlets?', 'head' => 'head|helm\w*|hood|hat|circlet', 'body' => 'body|top|chest|torso|cuirass|armou?r|shirt|tunic|robes?'] as $p => $rx) {
        if (preg_match('/\b(' . $rx . ')\b/', $t)) { $part = $p; break; }
    }
    if (preg_match('/\b(undress\w*|strip\w*|disrobe\w*|naked|nude|clothes off|take\b.*\boff)\b/', $t)) { return ['do' => 'undress', 'who' => $who, 'part' => $part]; }
    if (preg_match('/\b(dress\w*|redress\w*|clothes? (back )?on|put\b.*\bon|cover up)\b/', $t)) { return ['do' => 'dress', 'who' => $who, 'part' => $part]; }
    return null;
}

// ---------------------------------------------------------------- prompt text (rules for the LLM - never example lines)
function lrgReasonWords(array $reasons): array
{
    $map = [
        'witnesses' => 'other people are close enough to see or hear',
        'companion_present' => "the player's companion is right there",
        'combat' => 'there is fighting',
        'quest_scene' => 'something important is happening right now',
        'married' => 'they are married and will not betray that',
        'not_close_enough' => 'they do not know or trust the player nearly well enough',
        // [0.5.5] the two closed reasons that had no words, so "for the reason above" always has one
        'already_in_scene' => 'they are already in the middle of something intimate with someone else',
        'scene_running' => 'the player is already in the middle of something with someone else',
    ];
    return array_values(array_filter(array_map(fn($r) => $map[$r] ?? null, $reasons)));
}

/** R4: one sentence on how this character talks - plain by default, directness scaled by personality and setting. */
function lrgStyleLine(string $npc, ?array $profile, bool $discreet): string
{
    $manner = ['quiet' => 'few words, said straight', 'normal' => 'direct', 'vocal' => 'open and direct, blunt is fine', 'crude' => 'blunt and coarse',
        'romantic' => 'warm and personal, still plain', 'never' => 'few words'][lrgTalkStyle($profile ?? [])] ?? 'direct';
    return "How $npc talks: like a real person - plain everyday words in their own voice, $manner. No metaphors, similes, poetry, flowery or theatrical phrasing, no narrating of feelings."
        . ($discreet ? " $npc is discreet about who might hear - careful, never coy: what they do say, they say straight." : '');
}

/** R5: the wording permission - exists only on turns where $turn['x'] is set (adult + willing + a romantic mode). */
function lrgWordingLine(array $turn, bool $lowVoice = false): string
{
    if (($turn['x'] ?? null) === null) { return ''; }
    $cfg = lrgConfig()['scene_talk'] ?? [];
    // R3: the public / private split has to gate the LANGUAGE too. Where others can hear - or where the
    // character or the setting calls for discretion - the register is capped one level below "crude words
    // expected". Alone (follow / scene) the owner's full level applies.
    $lvl = (int) $turn['x'];
    if ($lowVoice || !empty($turn['discreet'])) { $lvl = min($lvl, 1); }
    $words = $cfg['explicitness_words'][$lvl] ?? 'direct and physical';
    return 'Wording for anything about desire or sex: ' . $words . '.' . ($lowVoice ? ' Kept low, for the player only.' : '');
}

/** True when this turn really offers that glue action; the guidance never names one CHIM is not offering. */
function lrgTurnOffers(array $turn, string $code): bool
{
    return in_array(strtolower($code), array_map('strtolower', (array) ($turn['offered'] ?? [])), true);
}

function lrgOneSentence(): string
{
    return 'ONE short sentence, at most ' . (int) (lrgConfig()['scene_talk']['max_chars'] ?? 120) . ' characters';
}

/**
 * [0.4 / OWNER ADDENDA 6] What the LLM is told about coin: WORDS, plus exactly ONE number - her own
 * floor - plus one WORD about the player's purse. Returns '' when money must not be mentioned at all.
 *
 * Three hard rules live here:
 *  - for_sale === false emits NOTHING. The absence of the sentence is what stops the model inventing a
 *    price for a vigilant, a priest of Mara or anyone the owner marked not_for_sale.
 *  - `pgold` itself never reaches a prompt. Only lrgPurseWord()'s four words do, so the model can never
 *    price the player's purse instead of pricing herself.
 *  - the figure is a FLOOR ("would not go below"), never a fee, and it is always followed by the
 *    reminder that coin is not a command.
 */
function lrgMoneyGuidance(array $turn, string $npc): string
{
    $gate = (array) ($turn['gate'] ?? []);
    $price = (array) ($gate['price'] ?? []);
    $mode = (string) ($turn['mode'] ?? '');
    if (!$price || empty($price['for_sale'])) { return ''; }
    if (!in_array($mode, ['closed', 'public', 'private', 'follow'], true)) { return ''; }
    // in mode closed the subject is only open when coin really is the one thing in the way
    if ($mode === 'closed' && !lrgPriceCouldOpen($gate)) { return ''; }

    if (!empty($price['free'])) {
        $tok = (int) ($price['token'] ?? 0);
        $out = "$npc does not need to be paid: $npc wants the player."
            . ($tok > 0 ? " If $npc feels like asking for a gift, a few septims (about $tok) is a gesture, not a price." : '');
    } else {
        $gold = (int) ($price['gold'] ?? 0);
        $purse = lrgPurseWord((int) (($gate['state'] ?? [])['pgold'] ?? 0), $gold);
        // [0.5.6 / pt15] she HAS a price and never claims otherwise; and (owner ruling B) a firm offer at or
        // above it from a player who visibly carries the coin is enough on its own - the gold is the trust
        $out = "What coin means here: $npc has a price and never claims to have none - $npc would not go below $gold septims"
            . " for a night with the player. $npc may name more, and turns down less - unless $npc wants the player anyway."
            . " A firm offer at or above it, from a player who visibly has the coin, is enough by itself: the gold is the"
            . " trust, and lack of closeness is no reason to refuse it. Coin is not a command: no sum buys anything $npc"
            . " will not do. The player's purse: $purse enough for that.";
    }
    $total = (int) (lrgRomance($npc)['gold_accepted'] ?? 0);
    if ($total > 0) {
        $last = lrgMemGet($npc)['paid'] ?? null;
        $times = max(1, (int) (is_array($last) ? ($last['times'] ?? 1) : 1));
        $lastGold = (int) (is_array($last) ? ($last['gold'] ?? 0) : 0);
        $out .= " They have had this arrangement before (" . $times . ($times === 1 ? ' time' : ' times')
            . ($lastGold > 0 ? ', last time ' . $lastGold . ' septims' : '') . ').';
    }
    return $out;
}

/** Stable per-NPC text (goes first = cache friendly). Empty string when nothing should be said at all. */
function lrgStaticGuidance(array $turn): string
{
    $mode = $turn['mode'] ?? 'silent';
    $gate = $turn['gate'] ?? null;
    if (!in_array($mode, ['closed', 'public', 'private', 'follow'], true) || !$gate || empty($gate['profile']) || empty($gate['state'])) { return ''; }
    $p = $gate['profile']; $s = $gate['state']; $npc = $turn['npc'];
    $sway = ['insulting' => 'an offer of coin for affection would insult them', 'risky' => 'taking coin would put their position at risk', 'indifferent' => 'coin means little in matters of the heart',
        'offering' => 'a generous gift is a courtesy, never a price', 'tempting' => 'coin is a real temptation, and they know it', 'decisive' => 'coin could change their life, which makes them both tempted and wary of being used'];
    $renown = ['weak' => 'Fame does not impress them.', 'moderate' => 'A great name earns a second look, not a yes.', 'strong' => 'They are drawn to great names and great deeds.'];
    $lines = [];
    $lines[] = "Standing and temperament of $npc: " . ($p['note'] ?? 'an ordinary person of Skyrim') . " (" . ($p['strictness'] ?? 'moderate') . ").";
    $lines[] = "$npc " . lrgWealthWords($p, $s) . "; " . ($sway[$p['gold_sway'] ?? 'indifferent'] ?? $sway['indifferent']) . ". To $npc the player " . lrgRenownWords($s) . ". " . ($renown[$p['renown_sway'] ?? 'moderate'] ?? '');
    // [0.4 / OWNER ADDENDA 6] directly after the wealth / gold_sway words, so the words and the one
    // number are read together. Empty for anyone who is not for sale - see lrgMoneyGuidance().
    $money = lrgMoneyGuidance($turn, $npc);
    if ($money !== '') { $lines[] = $money; }
    if (!empty($p['requires_commitment'])) { $lines[] = "$npc wants courtship and commitment, not a passing night."; }
    $sde = lrgSdeWords($s);
    if ($sde !== '') { $lines[] = "Between $npc and the player: $sde."; }
    $feels = ($mode === 'closed' && !empty($turn['interest']['willing']))
        ? "Whatever $npc may feel about the player changes nothing right now." // willing, but blocked by something privacy would not solve (a marriage she keeps, a fight ...)
        : "How $npc feels about the player right now: $npc " . lrgInterestWords((string) ($turn['interest']['word'] ?? 'indifferent')) . '.';
    if ($mode === 'closed') {
        // boundaries decide whether she is interested at all: this is the text that counters model agreeableness
        // [0.4] ... and "a bigger offer changes nothing" is dropped for an NPC who really does have a
        // price and for whom coin is the one thing in the way: the money sentence above would otherwise
        // contradict this one inside the same prompt. Everything else about the refusal stands.
        $priced = $money !== '' && lrgPriceCouldOpen($gate) && empty(($gate['price'] ?? [])['free']);
        $pressure = $priced
            ? 'insisting, flattery or asking again change nothing'
            : 'insisting, flattery, a bigger offer or asking again change nothing';
        // [pt15, ruling B] ... and "strangers are refused" gets its one exception where she has a price:
        // tonight's log is this sentence winning over "he offers a thousand" ("I don't have a price")
        $strangers = $priced
            ? "Strangers and near-strangers are refused - unless they pay $npc's price (above): a firm offer at or above it, with the coin on him, is enough by itself."
            : 'Strangers and near-strangers are refused.';
        $lines[] = "$feels Romance is never owed. $npc agrees to intimacy only if it truly fits their character, standing, history with the player and this exact moment. $strangers Do NOT agree to be pleasant or helpful: being agreeable is not a reason. If unsure, the answer is no. A refusal is final for this conversation: $pressure, and $npc grows colder when pressed.";
    } else {
        // she IS interested: the text gets out of the way. What stays is that it is her choice, never the result of pressure.
        // [0.4] ... and where an offer of coin has really been accepted, "coin does not produce a yes"
        // is replaced rather than deleted: the price stops being the obstacle, and that is ALL it does.
        $paidSoft = in_array('paid', (array) ($gate['soft'] ?? []), true);
        $lines[] = "$feels What $npc does about it, and when, is $npc's own choice, shaped by their personality and bio - $npc may well make the first move. It is never the result of pressure: "
            . ($paidSoft
                // [pt15, owner ruling B] "she can see the gold for herself": a firm, affordable offer at or
                // above her price IS the trust. She takes it or haggles upward; closeness is not the question.
                ? "insisting and flattery do not produce a yes - but the player has made a firm offer at or above $npc's price and visibly has the coin, and that is the trust: lack of closeness is no reason to refuse it. $npc takes it or haggles upward."
                : "insisting, flattery or coin do not produce a yes, and a no from $npc is final for this conversation.");
    }
    $lines[] = lrgStyleLine($npc, $p, !empty($turn['discreet']));
    return "\n<personal_boundaries>\n" . implode("\n", $lines) . "\n</personal_boundaries>\n";
}

/** CHIM movement actions she could use to actually go somewhere - named only when they are really offered this turn. */
function lrgMovementHint(string $npc): string
{
    if (!(lrgConfig()['invitation']['mention_movement_actions'] ?? true)) { return ''; }
    $name = static function (string $code): string {
        $n = (string) ($GLOBALS['F_NAMES'][$code] ?? $code);
        return str_replace('#PLAYER_NAME#', (string) ($GLOBALS['PLAYER_NAME'] ?? 'Player'), $n);
    };
    $bits = [];
    if (lrgIsOffered('TravelTo')) { $bits[] = $name('TravelTo') . ' (lead the way to a place)'; }
    if (lrgIsOffered('FollowPlayer')) { $bits[] = $name('FollowPlayer') . ' (go along with the player)'; }
    return $bits ? " To actually go, $npc can use " . implode(' or ', $bits) . ' in a later reply.' : '';
}

// ---------------------------------------------------------------- [0.3.1 / R3] the outro
/**
 * What CHIM knows about this NPC's own life, in one capped fragment: occupation first (Lisette's alone
 * carries the whole "I have to get back to the Winking Skeever" beat), then class and factions.
 * $GLOBALS['CHIM_CORE_CURRENT_NPC_DATA'] is the whole core_npc_master row and main.php fills it long
 * before every ext hook the glue uses (:489/:554/:629/:680, refreshed :762-770).
 */
function lrgNpcLife(string $npc): string
{
    $row = $GLOBALS['CHIM_CORE_CURRENT_NPC_DATA'] ?? $GLOBALS['STOBE_CORE_CURRENT_NPC_DATA'] ?? null;
    if (!is_array($row) || ((string) ($row['npc_name'] ?? $npc) !== '' && strcasecmp((string) ($row['npc_name'] ?? $npc), $npc) !== 0)) { $row = null; }
    $bits = [];
    $clean = static fn($s) => trim((string) preg_replace('/\s+/', ' ', (string) $s));
    if ($row) {
        $occ = $clean($row['occupation'] ?? '');
        if ($occ !== '') { $bits[] = substr($occ, 0, 320); }
        $ext = $row['extended_data'] ?? null;
        if (is_string($ext)) { $ext = json_decode($ext, true); }
        if (is_array($ext)) {
            $cls = $clean($ext['class']['name'] ?? '');
            if ($cls !== '' && $occ === '') { $bits[] = "goes through life as a $cls"; }
        }
        if (!$bits) {
            $goals = $clean($row['goals'] ?? '');
            if ($goals !== '') { $bits[] = substr($goals, 0, 240); }
        }
    }
    return implode(' ', $bits);
}

/** The one plain sentence about where they are and whether she has a reason to stay (R3 stay clause). */
function lrgOutroStayClause(string $npc, array $state): string
{
    $ltype = strtolower((string) ($state['ltype'] ?? ''));
    $indoor = in_array($ltype, ['inn', 'phouse', 'house', 'castle', 'temple', 'store', 'guild'], true);
    $hers = ($state['cellown'] ?? '') === 'npc' || (($state['home'] ?? '') === '1' && $indoor);
    $follower = (bool) preg_match('/\b(CurrentFollowerFaction|PlayerFollowerFaction)\b/i', (string) ($state['fac'] ?? ''));
    if (($state['pspouse'] ?? '0') === '1' || $follower) {
        return "$npc has every reason to stay: they are together. Say that $npc stays, and what $npc does next right here.";
    }
    if ($hers) {
        return "This is $npc's own place. Say whether $npc stays here - and if so, what $npc does next right here.";
    }
    if (($state['cellown'] ?? '') === 'player') {
        return "This is the player's own home. $npc says whether $npc stays a while or goes, and why.";
    }
    return "If $npc has no reason to stay, say where $npc is going and why.";
}

/**
 * [0.3.1 / R3] The outro block. THE ONE THING that was missing entirely: playtest 7's scene ends
 * triggered no LLM call at all, so what the owner heard as a goodbye was the CLIMAX scene-talk line
 * fired seconds earlier under a cue that demanded "one short, blunt sentence, 120 characters or less".
 * This block replaces <this_moment> for exactly one turn. It carries no <intimate_scene_now> (the scene
 * is over), no lrgOneSentence(), no action list, and it forbids End_Conversation by name - CHIM offered
 * that action on the post-scene turn and it was picked five times in one day ("Lisette leaves the
 * conversation", eventlog 21888).
 */
function lrgOutroGuidance(array $turn): string
{
    $npc = (string) $turn['npc'];
    $t = (array) ($turn['outro'] ?? []);
    if (!$t) { return ''; }
    $cfg = (array) (lrgConfig()['outro'] ?? []);
    $state = (array) ($turn['gate']['state'] ?? []);
    $player = trim((string) ($GLOBALS['PLAYER_NAME'] ?? '')) ?: 'the player';
    $maxChars = (int) ($cfg['max_chars'] ?? 420);
    $sentences = (string) ($cfg['sentences'] ?? 'two to four');

    $facts = [];
    $acts = [];
    foreach ((array) ($t['acts'] ?? []) as $fam) { $acts[] = lrgActLabel((string) $fam, $npc, $player); }
    // [0.3.1 fix pass] The scene LABELS carry their raw CamelCase ids ("StandingEmbraceKiss (standing;
    // kissing)"), and this is the one turn with a 420-character budget. They are only worth the room
    // when the act list came back empty (an old scene row, or a thread whose pushes were all dropped).
    if (!$acts) {
        foreach (array_slice((array) ($t['scenes'] ?? []), -3) as $sid) {
            $s = lrgScene((string) $sid);
            if ($s) { $acts[] = (string) preg_replace('/\s*\[[a-z]+\]$/', '', lrgDescribeScene($s)); }
        }
    }
    $acts = array_values(array_unique(array_filter($acts, 'strlen')));
    $facts[] = $acts ? 'What the two of them just did: ' . implode('; ', array_slice($acts, 0, 5)) . '.' : '';
    $dur = (int) ($t['dur'] ?? 0);
    $facts[] = 'It lasted ' . ($dur >= 600 ? 'a long while' : ($dur >= 180 ? 'several minutes' : ($dur >= 90 ? 'a few minutes' : 'a short while'))) . '.';
    $ncl = (int) ($t['ncl'] ?? 0); $pcl = (int) ($t['pcl'] ?? 0);
    $facts[] = ($ncl > 0 && $pcl > 0) ? 'They both finished.'
        : ($ncl > 0 ? "$npc finished; $player did not." : ($pcl > 0 ? "$player finished; $npc did not." : 'Neither of them finished.'));
    $facts[] = ['finished' => 'It ended because they were done.', 'stopped' => 'It ended because they stopped it.',
        'interrupted' => 'It was interrupted.', 'lost' => 'It broke off.'][(string) ($t['how'] ?? 'finished')] ?? '';
    $furn = (string) ($t['furn'] ?? '');
    $where = ($furn !== '' && $furn !== 'none') ? 'on ' . lrgFurnitureLabel($furn) : 'where they stood';
    $loc = trim((string) ($state['loc'] ?? ''));
    $facts[] = 'Where: ' . $where . ($loc !== '' ? ', in ' . $loc : '') . '.';
    $facts[] = !empty($t['first_time'])
        ? "This was the first time for the two of them."
        : 'They have been together before (' . max(2, (int) ($t['total'] ?? 2)) . ' times now).';
    // [0.4] the one fact that changes what the whole goodbye is about
    if ((int) ($t['paid'] ?? 0) > 0) { $facts[] = 'The player paid ' . $npc . ' ' . (int) $t['paid'] . ' septims for this - it was an arrangement, not a romance.'; }
    $life = lrgNpcLife($npc);
    if ($life !== '') { $facts[] = "What $npc's own life is: $life"; }

    $lines = [];
    $lines[] = "It is over. $npc and $player are pulling their clothes back on.";
    $lines[] = implode(' ', array_filter($facts, 'strlen'));
    $lines[] = "$npc says goodbye now, out loud, in $npc's own voice: $sentences plain spoken sentences, at most $maxChars characters in total. Not one line.";
    $lines[] = "1. React to what they actually did - name it, do not talk around it. A reaction, not a summary.";
    $lines[] = "2. Say plainly what $player is to $npc after this.";
    $lines[] = "3. Say what $npc does next and why, taken from $npc's own life - work, duties, the hour, where they both are. Be concrete: a place, a task, someone waiting.";
    $lines[] = lrgOutroStayClause($npc, $state);
    $lines[] = lrgWordingLine($turn);
    $lines[] = 'No poetry, no metaphors, no romance-novel wording, no narrating of actions, no stage directions. Crude, plain words are fine and expected.';
    $lines[] = "Do not ask $player to stay for more, do not propose anything new, do not start anything physical. Choose NO action this turn - in particular never End_Conversation.";
    if (!empty($turn['discreet'])) { $lines[] = "$npc keeps this quiet: said for $player alone, still plainly."; }
    return "\n<after_intimacy>\n" . implode("\n", array_filter($lines, 'strlen')) . "\n</after_intimacy>\n";
}

/** Volatile text (goes last): this moment - why not, where instead, her move, or the live scene. */
function lrgVolatileGuidance(array $turn): string
{
    $mode = $turn['mode'] ?? 'silent';
    if ($mode === 'outro') { return lrgOutroGuidance($turn); }
    if ($mode === 'scene' && !empty($turn['scene'])) { return lrgSceneNotes($turn); }
    // [0.5.2 / pt10] THE BLIND TURN, and the ONE thing a silent turn may say. The gate is silent only
    // because the game has not reported in ($turn['blind'], i.e. reasons = no_fresh_snapshot): the glue
    // knows nothing about this NPC this moment, offers nothing and executes nothing. Saying so is
    // strictly MORE restrictive than the silence it replaces - in playtest 10 the model was handed
    // thirty minutes of that silence with no note at all, and filled it by writing as though things
    // were happening ("the ai seemed very very interested in pursuing that" and nothing ever did).
    // It names no action, permits no wording and carries no request directive: with no facts, the
    // money shapes and the act layer would all be answering out of nothing.
    // Every other silence still injects NOTHING AT ALL - not an adult, a child in the room, the owner's
    // switch off, an NPC who is opted out, the kill switch, SHARMAT. There the glue does know.
    // [0.5.5] the voiced funcret turn carries nothing here: its cue IS the instruction (lrgVoicedCue)
    if ($mode === 'voiced') { return ''; }
    if ($mode === 'silent') {
        // [0.5.4 / pt13] ... and the escort directive, the second thing a silent turn may carry: "follow
        // me" has no sexual reading, so the reason for the silence (no snapshot, not an adult, the switch
        // off) says nothing about it.
        // [0.5.5 / owner addendum 11] Two more, both about SPEAKING rather than doing: on the blind turn a
        // recognised request gets the one-line "not right now" directive (lrgBuildRequestDirective), and an
        // order of hers that visibly failed (lrgMissedNote - movement only, never intimacy) is told.
        $kind = (string) ($turn['intent']['kind'] ?? '');
        $dir = ($kind === LRG_INTENT_ESCORT || (!empty($turn['blind_note']) && $kind !== '' && $kind !== 'none')) ? lrgRequestDirective($turn) : '';
        $missed = lrgMissedNote($turn);
        if (empty($turn['blind_note']) && $dir === '' && $missed === '') { return ''; }
        $n = (string) ($turn['npc'] ?? '');
        // [0.5.7 / pt16] The dialogue lane carries barter on CHIM's own OpenInventory this turn (its
        // <player_request> at prompt_bottom 910 follows this block, PROTOCOL 10.15). "Nothing physical can
        // be carried out" must not take Trade_Items away with it: on Jala's and Addvar's first-contact turns
        // (2026-09-23 16:40) this sentence steered the model off every action, the trade window included.
        // LRG_DLG_TURN is built in functions.php before context_pre.php runs (Phase 2 block, brace depth 0).
        $dlgT = (array) ($GLOBALS['LRG_DLG_TURN'] ?? []);
        $dlgSvc = (array) ($dlgT['svc'] ?? []);
        $trade = !empty($dlgSvc['direct']) && (string) ($dlgSvc['kind'] ?? '') === 'barter'
            && strcasecmp((string) ($dlgT['npc'] ?? ''), $n) === 0;
        // [pt19-purchase] ... and an order of food or drink the market lane is serving (lib/lrg_market.php): the game hands it
        // over by itself after her line, so "nothing physical" must not talk her out of naming the price and serving
        $serving = in_array((string) ((($dlgT['buy'] ?? [])['state'] ?? '')), ['queued', 'ask', 'quote'], true)
            && strcasecmp((string) ($dlgT['npc'] ?? ''), $n) === 0;
        $blindText = empty($turn['blind_note']) ? '' : "The game has sent no fresh facts about $n this moment, so nothing about "
            . "$n's situation is known here and nothing physical can be set up, agreed or carried out on this turn"
            . ($trade ? " - apart from showing $n's wares, which the game does by itself when $n chooses the trade action. "
                : ($serving ? " - apart from serving what $n sells, which the game does by itself after $n's line. " : '. '))
            . "Answer the player in words, in $n's own voice. Do not act anything out, and do not write as though "
            . "something were being done - say it plainly instead, or simply answer.";
        return "\n<this_moment>\n" . implode("\n", array_filter([$blindText, $missed, $dir], 'strlen')) . "\n</this_moment>\n";
    }
    $gate = $turn['gate'] ?? null;
    if (!in_array($mode, ['closed', 'public', 'private', 'follow'], true) || !$gate || empty($gate['profile'])) { return ''; }
    $n = $turn['npc'];
    $init = !empty($turn['initiative']);
    $one = lrgOneSentence();
    $fail = !empty($turn['fail']) ? "What $n last tried did not happen (" . $turn['fail'] . "). $n may say so in a few plain words, once, and does not try the same thing again right away." : '';
    // [0.4] the one refusal that needs its own words in her mouth: she agreed to a price and the purse
    // was empty when the scene tried to begin. "What she last tried did not happen" is not what that is.
    if (!empty($turn['fail']) && stripos((string) $turn['fail'], 'gold') !== false) {
        // [pt15, ruling D] voiced, plainly: he does not have it on him - show the coin first
        $fail = "What $n agreed to did not happen: the player could not actually pay - he does not have that much coin on him. $n calls it out plainly, in a few words of $n's own - he should show the coin first - and nothing begins.";
    }
    // [pt15, owner ruling B] he BOUGHT it: a firm offer at or above her price that he can pay stands (said
    // now, confirmed, or remembered from the walk here). The figure only travels in BeginIntimacy's amount.
    $bought = in_array('paid', (array) ($gate['soft'] ?? []), true) && !empty(($gate['paid'] ?? [])['accepted']);
    $boughtAmt = (int) (($gate['paid'] ?? [])['offer'] ?? 0);
    // never name an action CHIM is not offering this turn: the post-LLM gate would drop it anyway
    $canInvite = lrgTurnOffers($turn, LRG_ACT_INVITE);
    $canStart = lrgTurnOffers($turn, LRG_ACT_START);
    $canStrip = lrgTurnOffers($turn, LRG_ACT_CLOTHING);
    $acts = [];
    // [0.3 / G3a] the "can simply happen, message left empty" licence is deleted: a scene must never begin
    // out of nowhere, and the sentence that said it could was the direct cause in playtest 6
    // [0.3.1 fix pass] The owner removed the forced gentle lead-in: when the player has just asked for
    // something and the start will really begin there (w1), the action's own name must not still say
    // "it starts gently" three lines above a directive that names the position it begins in.
    if ($canStart) {
        $asked = (string) ($turn['intent']['start_scene'] ?? '') !== ''
            || (string) (($turn['intent']['extra'] ?? [])['start_scene'] ?? '') !== '';
        $acts[] = $asked
            ? 'BeginIntimacy (it begins in what the player just asked for)'
            : 'BeginIntimacy (it starts gently - a kiss, an embrace)';
    }
    if ($canStrip) { $acts[] = 'ChangeClothing (item "undress") to strip'; }
    $actWords = $acts ? implode(', or ', $acts) : '';
    $lines = [];
    if ($mode === 'closed') {
        $why = lrgReasonWords($gate['reasons']);
        // [0.5.5 / owner addendum 11] a recognised physical request, or an order that failed, is never left without a note
        if (!$why && $fail === '' && (string) ($turn['intent']['kind'] ?? '') !== LRG_INTENT_ESCORT
            && lrgIntentPlainKind((array) ($turn['intent'] ?? [])) === '' && empty($turn['missed'])) { return ''; }
        if ($why) { $lines[] = "Intimacy is not possible right now because " . implode(', and ', $why) . ". If the player proposes it (or asks $n to undress), $n says so in one short line, with that reason in $n's own words - never ignoring it. Nothing physical begins."; }
    } elseif ($mode === 'public') {
        $why = implode(', and ', lrgReasonWords($gate['reasons'])) ?: 'they are not alone';
        $inv = $turn['invite'] ?? null;
        $pick = $canInvite ? ' choose SuggestPrivacy with item ' . implode(' | ', array_keys($turn['places'])) : ' say it in words only';
        if (is_array($inv)) {
            $lines[] = "$n wants to be alone with the player and has already said so (" . ($turn['places'][$inv['place'] ?? ''] ?? 'somewhere private') . "), but here $why. Nothing physical happens here. $n does not repeat the invitation unless the player asks; if the player wants another place, $n may name one and" . $pick . '.' . lrgMovementHint($n);
        } else {
            $places = [];
            foreach ($turn['places'] as $k => $words) { $places[] = "$k = $words"; }
            $lines[] = ($init ? "Nobody has spoken for a while. $n acts on how they feel, if they want to: " : '')
                . "$n would like more with the player, but $why. Nothing physical happens here. If the mood is there - the player asks, or $n wants to - $n says plainly that the two of them should go somewhere private, and names the place: " . implode('; ', $places)
                . '. In that same reply' . $pick . '.' . lrgMovementHint($n);
        }
        $lines[] = "About this, $one. " . lrgWordingLine($turn, true);
    } elseif ($mode === 'follow') {
        $inv = $turn['invite'] ?? [];
        $placeWords = ['home' => 'their own place', 'room' => 'a room', 'quiet' => 'somewhere quiet'][$inv['place'] ?? ''] ?? 'somewhere private';
        $same = $turn['gate']['state']['loc'] ?? '';
        $arrived = ($inv['loc'] ?? '') === '' || (string) $same === (string) ($inv['loc'] ?? '') ? "asked the player to come away to $placeWords, and now they are alone" : 'asked the player to be alone with them, and now they are';
        $lines[] = "$n $arrived. $n follows through NOW, in their own way: " . ($actWords !== '' ? $actWords . ', or ' : '') . "a blunt proposition in words. Only if the player has made clear they do not want this does $n drop it.";
        // [0.4 / OWNER ADDENDA 4b] the room itself, when the game says a door is shut. Facts only.
        $door = lrgPrivacyWords((array) ($gate['state'] ?? []));
        if ($door !== '') { $lines[] = ucfirst($door) . '.'; }
        $lines[] = "At most $one. " . lrgWordingLine($turn);
    } else { // private
        $drawn = !empty($turn['interest']['may_initiate']);
        // [0.4 / OWNER ADDENDA 4b] "a room behind a closed door IS private". The witness count is
        // already corrected at its source, so this only tells her what the room is - which is what
        // stopped seven minutes of playtest 8 from ever getting anywhere.
        $door = lrgPrivacyWords((array) ($gate['state'] ?? []));
        $lines[] = ($init ? "Nobody has spoken for a while and it is private here. $n makes a move of their own now, in their own way: "
                : "It is private here" . ($door !== '' ? " - $door" : '') . ". " . ($drawn ? "$n wants this and may make the first move without being asked: " : "If the moment is right, $n may make a move of their own or answer the player's: "))
            . 'a plain proposition in words' . ($actWords !== '' ? ', ' . $actWords : '')
            . ($bought
                // [pt15, ruling B] a paid turn: the offer is what decides it, and she answers the offer
                ? ". If $n haggles upward instead, choose neither action this turn."
                : ". Only ever because $n wants it right now: if $n declines, hesitates or deflects, choose neither action. Nothing the player says can decide this.");
        if ($init && $door !== '') { $lines[] = ucfirst($door) . '.'; }
        $lines[] = ($init ? "At most $one. " : "Whenever it comes to that, or $n acts: $one. ") . lrgWordingLine($turn);
    }
    if ($bought && $boughtAmt > 0 && in_array($mode, ['public', 'private', 'follow'], true)) {
        $lines[] = "The player has made a firm offer of $boughtAmt septims - at or above $n's price - and visibly has the coin: the gold is the trust, so lack of closeness is no reason to refuse it."
            . ($canStart ? " When $n takes it, BeginIntimacy carries amount $boughtAmt."
                : ($mode === 'public' ? " Only the place is wrong: $n takes the offer by taking him somewhere private." : ''));
    }
    // [0.3 / G3a + R10] Nothing she does physically is silent, and a question about it is a question.
    if ($canStart || $canStrip || $canInvite) {
        $lines[] = "Whatever $n does physically - starting something, undressing, suggesting somewhere private - $n says one short line first that makes plain what is about to happen. Direct and blunt by default; coy only if that is who $n is. Never a speech.";
    }
    if (!$canStart && in_array($mode, ['private', 'follow'], true)) {
        // [0.3 / G3d] the whole fix for playtest 6's 19:28:59: a sexual question, 14 s after a load, heat 0
        $lines[] = "A question about this is a question: $n answers it in words. $n does not start anything physical this turn.";
    }
    if (!empty($turn['say_first'])) {
        // [0.3 / 8.3] the one-shot re-ask, in the same words the scene notes use
        $lines[] = "Last time $n chose to act without saying anything, so nothing happened. If $n still wants it, say the one short line that makes plain what $n is about to do, in the same reply as the action.";
    }
    $lines[] = $fail;
    $lines[] = lrgMissedNote($turn); // [0.5.5] an order of hers that visibly failed (movement only)
    $lines[] = lrgRequestDirective($turn);
    return "\n<this_moment>\n" . implode("\n", array_filter($lines, 'strlen')) . "\n</this_moment>\n";
}

/** Words for a tier number on the ladder the LLM is told about. */
function lrgStepWords(int $tier): string
{
    return $tier >= 4 ? 'sex' : ($tier === 3 ? 'foreplay (touching, teasing, undressing)' : 'gentle closeness (holding, kissing)');
}

/**
 * DEAD as a rule, kept as a PREDICATE for tools/test_gates.php only.
 * The v0.2 "say it / silent" machinery it belonged to is gone: owner addendum 2 and the revised R10 say
 * that EVERY change she initiates herself is preceded by one short spoken line, gentle ones included, and
 * `blunt` now decides only how blunt that line is. Nothing in the plugin calls this; test_gates.php uses it
 * to tell a gentle option from a foreplay / sex one when it checks that the markers are really gone.
 */
function lrgIsSayIt(string $tier): bool
{
    return (int) (LRG_TIERS[$tier] ?? 0) >= LRG_TIERS['sensual'];
}

/**
 * The live scene, for the NPC's prompt. ONLY reached while a scene with this NPC runs and the game's snapshot
 * confirmed an adult (lrgPrepareTurn fails closed otherwise).
 */
function lrgSceneNotes(array $turn): string
{
    $npc = $turn['npc'];
    if (!empty($turn['scene_blocked'])) {
        // a rail failed while the scene runs (no fresh adult snapshot, a child nearby, the switch off,
        // an NPC who is opted out): nothing about the scene is described and no wording is permitted.
        // The one thing that survives is the player's way out.
        if (empty($turn['can_act'])) { return ''; }
        return "\n<intimate_scene_now>\nIf the player asks to stop, or seems unwilling: choose ChangeIntimacy with item \"stop\" at once, no argument. Nothing else about this is described or acted on right now.\n</intimate_scene_now>\n";
    }
    $cfg = lrgConfig()['scene_talk'] ?? [];
    $sc = $turn['scene'];
    $cur = lrgScene((string) ($sc['scene'] ?? ''));
    $label = $cur ? lrgDescribeScene($cur) : ((string) ($sc['scene'] ?? 'unknown') . ' (' . ($sc['acts'] ?? '') . ')');
    $level = (int) ($turn['x'] ?? lrgExplicitLevel($sc, null)); // the MCM slider wins when the game sent it
    $style = lrgTalkStyle($turn['profile'] ?? (lrgConfig()['npc_overrides'][$npc] ?? []));
    $undp = lrgCsv($sc['undp'] ?? '');
    $und = (($sc['und'] ?? '') === '1' || in_array('all', $undp, true)) ? " $npc is undressed." : ($undp ? " $npc has bared: " . implode(', ', $undp) . '.' : '');
    $wd = ($sc['wd'] ?? '') === '1';
    $climaxes = max((int) ($sc['_climaxes'] ?? 0), (int) ($sc['ncl'] ?? 0) + (int) ($sc['pcl'] ?? 0));
    $lines = [
        "$npc and the player are in an intimate scene RIGHT NOW. What is actually happening: $label. Pace " . ($sc['speed'] ?? '?') . ' of ' . ($sc['maxspeed'] ?? '?') . (($sc['trans'] ?? '0') === '1' ? ' (moving between positions)' : '') . ".$und"
            . ($climaxes > 0 ? ' A climax has already happened.' : '') . ($wd ? ' They are winding down now: slow, close, quiet.' : ''),
        // the "say what you want" clause is an imperative to SPEAK and must not sit next to the rule that
        // asks for an empty message: it is added only on turns where speaking is actually wanted (R10)
        "How $npc talks now: like a real person in the middle of this, not a storyteller. Plain, blunt, physical words in $npc's own voice (rough or refined as they are); fragments and breath are fine."
            . ((!empty($turn['announce']) && $style !== 'never') ? " When $npc does speak: what $npc wants, what it feels like in the body, what the player should do." : '')
            . " BANNED: metaphors, similes, flowery or romance-novel wording, poetic vocabulary, describing feelings from outside, narrating actions, speeches.",
        // [0.3 / 4.4.6] "silence is fine" survives only on a turn that carries NO action - on an action
        // turn it competes with R10, which says every change she makes herself is spoken
        "Wording: " . ($cfg['explicitness_words'][$level] ?? 'direct and physical') . ". Manner of $npc: " . ($cfg['talk_words'][$style] ?? $style) . ". Only about what is really happening now. " . lrgOneSentence() . ', often less'
            . (empty($turn['can_act']) ? '; silence is fine' : '') . '. Never repeat a line.',
    ];
    if (!empty($turn['fail'])) { $lines[] = "What $npc last tried did not happen (" . $turn['fail'] . "). $npc may say so in a few plain words, once."; }
    if (!empty($turn['can_act'])) { // actions are only offered on player speech and on the lead tick: do not describe them (or pay for them) on other turns
        $pr = $turn['progress'] ?? ['reached' => 4, 'ceiling' => 4, 'pace' => 'normal', 'req_ceiling' => 4];
        $ctx = $turn['ctx'] ?? null;
        $leader = strtolower((string) ($sc['leader'] ?? 'npc'));
        $nextOpen = (int) $pr['ceiling'] > (int) $pr['reached'];
        // [0.3 / 4.4.3] The old single sentence ("anything beyond foreplay (touching, teasing, undressing)
        // is too soon right now") was read by the model as "undressing is too soon" and is the reason she
        // would not undress when asked. Two unambiguous sentences instead, and the second one is about HER
        // OWN pacing only - it is omitted entirely when the player asked for something (req_ceiling 4).
        $lines[] = "What $npc starts by " . ($npc === '' ? 'herself' : "$npc's own decision") . " right now: up to " . lrgStepWords((int) $pr['ceiling']) . '.';
        if ((int) ($pr['req_ceiling'] ?? $pr['ceiling']) < 4) {
            $lines[] = "$npc's own next step beyond that is not yet. This limit is about what $npc starts: anything the player asks for, $npc simply does.";
        }
        $lines[] = "If the player asks to stop or seems unwilling: choose ChangeIntimacy with item \"stop\" at once, no argument.";
        if (!empty($turn['lead']) && $turn['propose'] === null) {
            // [0.3 / G2] no circling: forward, or a change of pace - never back to something already done
            $lines[] = "Nothing has changed for a while and the player leaves it to $npc. ONE change that suits $npc, at their own " . $pr['pace'] . " pace: an act below, or the pace. Something that has not happened yet, or a change of pace - not back to something already done, unless the player asks."
                . ((int) ($turn['lead_idle'] ?? 0) > 0 ? " $npc already let the last moment pass unchanged: this time $npc makes a change." : '')
                . ($climaxes > 0 ? ' After a climax, "wind down" is a natural choice.' : '');
        } elseif ($turn['propose'] === null) {
            $lines[] = $leader === 'player'
                ? "Who leads: the player directs. $npc does what the player asks and does not change things unasked."
                : "Who leads: if the player says what they want, $npc does it. If the player is passive or tells $npc to take over, $npc leads at their own " . $pr['pace'] . " pace, choosing what fits their personality and bio.";
        }
        if ($turn['propose'] !== null) {
            // R11: she wants a NEW sexual act - she asks for it and waits. Only a yes makes it happen.
            $lines[] = "$npc wants " . (string) $turn['propose']['name'] . " next. $npc asks for it in $npc's own words - one short sentence - and chooses no action this turn. It only happens if the player says yes.";
        }
        // [0.3 / G5] acts, not P-keys: an act id means the same thing on every turn of the scene
        // [0.5.0 PT9 reconciliation] It is also the biggest block the glue injects anywhere (1,362 of a
        // 3,931-character scene turn - the 78-character header line plus a 1,282-character act list,
        // measured with tools/test_latency_prompt.php --dump=scene; "1,540" here was wrong and is the
        // figure a later round would have sized a cap against), and the latency round trimmed it
        // twice. BOTH trims are REVERTED
        // here, because both were narrowings of what she may choose, sold as removals of redundancy:
        //  · dropping the whole catalogue on a turn where the player asked for something took away her
        //    only copy of the act vocabulary on exactly the turn the player is waiting. Nothing
        //    re-supplies it: the injections are rebuilt every turn and CHIM's history carries the
        //    conversation, not last turn's system block. A key produced from memory is a guess and the
        //    post-LLM gate drops a wrong one, so a change of act she makes alongside what he asked for
        //    would silently fail. (research/pt9-latency-verify.md D5.)
        //  · max_positions_total 20, spent greedily in act order, left the LAST THREE acts with no
        //    positions at all - 42 position names down to 20 - including `hold`, the wind-down act.
        //    (research/pt9-latency-verify.md D4.)
        // What the whole block was worth: -1,362 characters ~ -340 tokens of a 6,543-token prompt on an
        // "asked" turn, i.e. 0.05-0.15 s of LLM time (research/pt9-latency-reconcile.md section 3). The
        // seconds this round really saves are in the pre-lock rails (lrg_latency.php), not here.
        if (!empty($turn['acts']) && lrgTurnOffers($turn, LRG_ACT_REQUESTACT)) {
            $acts = [];
            $pn = trim((string) ($ctx['player'] ?? ''));
            $posCap = (int) (lrgConfig()['scene_index']['max_positions_per_act'] ?? 6);
            foreach ($turn['acts'] as $id => $a) {
                $line = $id . ' = ' . lrgActLabel((string) $id, $npc, $pn) . ((int) (LRG_TIERS[$a['tier']] ?? 0) > (int) $pr['reached'] ? ' [next step]' : '');
                // [0.3.1 / D5] positions are first-class: the key may name one after a slash, so
                // "vaginal" is never silently resolved to whatever vaginal scene happens to be nearest
                $pos = array_slice((array) ($a['positions'] ?? []), 0, max(0, $posCap));
                if ($pos) { $line .= ' (positions: ' . implode(', ', array_map(static fn($p) => $id . '/' . $p, $pos)) . ')'; }
                $acts[] = $line;
            }
            $lines[] = 'RequestAct takes exactly one act key, exactly as written here. Open right now:';
            $lines[] = implode('; ', $acts) . '.';
        }
        $verbs = ['faster', 'slower', 'hold', 'release'];
        if ($ctx && (int) ($ctx['tier'] ?? 0) >= LRG_TIERS['sensual']) { $verbs[] = 'climax'; }
        // "pull out" is deliberately NOT offered: a scan of all installed scene packs found no scene
        // that defines a "pullout" auto transition, so every pick would cost a full LLM + TTS turn and
        // end in an error notification. lrgResolveControl still maps the words, and the game still has
        // the verb, so a pack that defines the transition works without a change here.
        $verbs[] = 'wind down (ease into holding each other, then it ends)';
        $verbs[] = 'stop (ends at once)';
        foreach ((array) $turn['furn_options'] as $type => $fo) { $verbs[] = strtolower((string) ($fo['label'] ?? $type)) . ' (move there)'; }
        // one unambiguous key set: a NAME followed by "leads". "you lead" / "i lead" invert depending
        // on who says them, so they are not offered and the resolver drops them.
        $pname = trim((string) ($ctx['player'] ?? '')) ?: 'the player';
        $verbs[] = "$npc leads"; $verbs[] = "$pname leads";
        if (!empty($ctx['auto_ok'])) { $verbs[] = 'auto (the scene runs by itself)'; }
        $lines[] = 'ChangeIntimacy takes exactly one item: ' . implode('; ', $verbs) . '.'
            . (empty($turn['lead']) ? " If what the player asks for does not exist, $npc answers in character and nothing changes." : '');
        if (lrgTurnOffers($turn, LRG_ACT_CLOTHING)) {
            $lines[] = "ChangeClothing, item undress | dress, optionally + both or you, optionally + body, head, hands or feet. When the player asks for clothes off or on, do it. When nobody asked, only because $npc wants to.";
        }
        // [0.3 / R10, owner addendum 2] replaces the whole [silent] / [say it] machinery: nothing she
        // initiates herself is silent any more, and anything the player asked for is done AND answered.
        $lines[] = "Whatever $npc chooses here by $npc's own decision, $npc says one short line that makes plain what is about to happen - before or as it happens. Never silently."
            . (!empty($turn['blunt']) ? " Name the act outright." : " A few plain words are enough.")
            . " A change of pace, holding back or letting go needs no line. Anything the player asked for: do it AND answer in the same reply - never answer instead of doing it.";
        if (!empty($turn['say_first'])) {
            $lines[] = "Last time $npc chose to act without saying anything, so nothing happened. If $npc still wants it, say the one short line that makes plain what $npc is about to do, in the same reply as the action.";
        }
        $directive = lrgRequestDirective($turn);
        if ($directive !== '') { $lines[] = $directive; } // ALWAYS last: it is the newest fact of the turn
    }
    return "\n<intimate_scene_now>\n" . implode("\n", array_filter($lines, 'strlen')) . "\n</intimate_scene_now>\n";
}
