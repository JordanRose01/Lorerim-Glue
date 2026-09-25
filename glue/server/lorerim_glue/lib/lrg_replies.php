<?php
/**
 * LoreRim Glue - [pt17-replies] NEVER EMPTY: the floor under a reply that carried no words.
 *
 * Playtest 2026-09-23 20:10:31, Captain Aldis, Solitude: the player said "it says it's locked" and grok-4.3 (openrouterjson,
 * strict json_schema) answered a schema-valid JSON whose `message` was "" (audit request 850). CHIM core has no floor for
 * that: the stream loop never reaches a sentence, `returnLines()` never runs, `processActions()` yields 0 commands,
 * `call_llm_internal()` returns TRUE (the JSON was valid), `LLM_RETRY_FNCT` is consulted only for an INVALID reply and
 * nobody sets it, main.php:2830-2846 only writes a log row, and X-CUSTOM-CLOSE goes out. Nothing was spoken. The glue
 * saw it (`llm npc=Captain Aldis action=none item="" spoke=0`) and had no rail either. 17 of the 322 logged replies
 * since the log began (5.3 %) have `"message": ""` - research/pt17-replies.md has the table.
 *
 * Owner addendum 11: "Silence after a request is a defect in every case." This file is that rail, in three layers:
 *
 *  1. VALIDATOR ($GLOBALS['VALIDATE_LLM_OUTPUT_FNCT'], lib/data_functions.php:5937). It runs per streamed chunk on EVERY
 *     LLM turn - including the voiced funcret turn, where CHIM has switched functions off (processor/funcret.php:199)
 *     and the post-process hooks never run. It judges only a COMPLETE decoded reply (every schema-required key present,
 *     the schema's last property present, and `message` not the last key the model wrote - connector/__jpd.php closes
 *     a partial object with "}, so a mid-stream "" is not a verdict): trimmed message "" on one of OUR turns -> the
 *     reply is rejected (`LRG_NE_REJECT`), CHIM marks the output invalid, discards it, and calls the retry hook.
 *     A business pick the game answers itself (lrgDlgWillEmit: the real dialogue line plays and the mute would drop any
 *     line of ours) is let through untouched; a scene / outro turn is log-only unless never_empty.in_scene.
 *  2. RETRY ($GLOBALS['LLM_RETRY_FNCT'], main.php:2825). For an empty message it RE-ASKS ONCE (never_empty.reask): the
 *     rule "answer in words" is appended to the last user message and `call_llm()` runs again with
 *     $GLOBALS['IN_FALLBACK_MODE'] set - exactly what CHIM's own fallback re-entry sets (data_functions.php:5810), and
 *     what skips processor/player_tts.php on the second pass; nothing of the rejected attempt was echoed, so nothing
 *     is duplicated. Real words in her own voice beat any line of ours (addendum 11: "in her own words"). Only when
 *     the second reply is empty too, or the reply was invalid / never came (connector error, timeout, non-JSON - the
 *     branch main.php already had and nobody used), does she say ONE short floor line through CHIM's own
 *     `returnLines()`: TTS, $talkedSoFar, the chat eventlog row (so the next prompt does not show two player lines in
 *     a row), the ScriptQueue echo - all before X-CUSTOM-CLOSE.
 *  3. HOOK (the glue's LAST entry of $GLOBALS['action_post_process_fnct_ex'], after LRG_POSTGATE and LRG_DLG_POSTGATE - but
 *     NOT the last word on the lines: CHIM appends its own core closure after the glue's hooks (functions.php:2830) and runs its
 *     ACTION POST-FILTER after that (data_functions.php:6042-6452), which may still drop or execute an action server-side or
 *     rewrite its params (CHIM brief P3) - nothing here may rely on seeing the FINAL lines). It floors
 *     what the validator let through on purpose: an action-bearing empty reply when the re-ask is OFF (the validator
 *     would otherwise discard Follow / Come_Closer / Trade_Items with the words), and a reply whose `message` came last.
 *     The line is spoken BEFORE CHIM echoes the command lines (data_functions.php:6483), so "she says a line before
 *     starting anything herself" holds for CHIM's own actions too. It never touches the action list.
 *
 * The floor lines are spoken text, not prompt text: short, neutral, non-committal, never pretending to have answered,
 * rotated per NPC through lrg_memory.empty_reply so the same line is not heard twice running, and the owner edits them
 * in lrg_config.json (`never_empty.lines.{question|statement|trouble}`). `trouble` is for the no-reply / invalid branch:
 * it must not imply she heard and understood. Next turn, prevention (rules only): lrgNeNote() gives Phase 2's volatile
 * guidance one sentence while empty_reply.at is within note_seconds.
 *
 * Log: `never-empty npc=<n> action=<code> item="<i>" verdict=rejected|let-through|log-only|idle` (the validator / hook)
 * and `never-empty npc=<n> action=<code> why=<empty message|empty message twice|no reply|invalid output|the re-ask
 * failed> said="<line>"` / `reask=spoke chars=<n>` (the retry). grep 'never-empty' counts how often the model does it.
 *
 * Offline seams (PROTOCOL 7.2): $GLOBALS['LRG_TEST_SAY'] collects the line when returnLines() does not exist (and
 * $GLOBALS['talkedSoFar'] grows the way CHIM's returnLines makes it grow); $GLOBALS['LRG_TEST_REASK'] stands in for
 * call_llm(); $GLOBALS['LRG_NE_TEST_OVERRIDE'] merges over the config.
 *
 * [pt18-words] NEVER FALSE rides the same three seams (the section at the end of this file): on a faction turn the
 * WORDS are judged - a claimed enlistment, oath, rank, next step or appointment the game did not record is rejected
 * in the chunk that completed the sentence (or dropped per sentence by the transformer when a rejection would throw a
 * dialogue pick away), re-asked once with the model's own sentence quoted as false, and floored with one truthful line
 * by role. General Tullius, 2026-09-23 23:19: "Then you are a Legionnaire." with qst=CW00A:0 (research/pt18-words.md).
 */

if (defined('LRG_REPLIES_LOADED')) { return; }
define('LRG_REPLIES_LOADED', true);

require_once __DIR__ . '/lrg_core.php';
require_once __DIR__ . '/lrg_actions.php';    // lrgSpokenThisTurn(), lrgShortCode(), LRG_PLAYER_SPEECH_TYPES (core)
require_once __DIR__ . '/lrg_dialogue.php';   // lrgDlgWillEmit(), LRG_DLG_GATE_NAMES, $GLOBALS['LRG_DLG_TURN']

// ================================================================== config
function lrgNeDefaults(): array
{
    return [
        'enabled' => true,
        // one more LLM call with the words rule before any floor line (about 1.5-2 s on the ~5 % of turns it happens)
        'reask' => true,
        // inside an OStim scene / the outro a canned line is worse than a breath: log only, unless the owner wants it
        'in_scene' => false,
        // the next-turn rule ("<npc>'s last reply carried no words ...") rides Phase 2's volatile guidance this long
        'note_seconds' => 120,
        'max_chars' => 60,
        // SPOKEN lines, not prompt text. Neutral, non-committal, never pretending to have answered. Owner-editable.
        'lines' => [
            'question' => ['Hm. Ask me that again.', "I'm not sure what to tell you.", 'Give me a moment to think on that.'],
            'statement' => ['Mm-hm.', 'Is that so.', 'I hear you.', 'Go on.'],
            // no reply / invalid output: she did NOT catch it - the line must not imply she heard and understood
            'trouble' => ['Say that again?', "Sorry, I didn't catch that."],
        ],
    ];
}

/** Defaults in code + the owner's top-level `never_empty` block (lrgMerge: maps merge, lists replace) + the test seam. */
function lrgNeCfg(string $path = '', $default = null)
{
    static $cfg = null;
    static $seam = false;
    if ($cfg === null || $seam || array_key_exists('LRG_NE_TEST_OVERRIDE', $GLOBALS)) {
        $cfg = lrgNeDefaults();
        $over = lrgConfig()['never_empty'] ?? null;
        if (is_array($over)) { $cfg = lrgMerge($cfg, $over); }
        $seam = is_array($GLOBALS['LRG_NE_TEST_OVERRIDE'] ?? null);
        if ($seam) { $cfg = lrgMerge($cfg, $GLOBALS['LRG_NE_TEST_OVERRIDE']); }
    }
    if ($path === '') { return $cfg; }
    $v = $cfg;
    foreach (explode('.', $path) as $k) {
        if (!is_array($v) || !array_key_exists($k, $v)) { return $default; }
        $v = $v[$k];
    }
    return $v;
}

// ================================================================== registration
/**
 * Idempotent, called from functions.php (brace depth 0, after LRG_DLG_POSTGATE) and again from context_pre.php
 * (main.php:2540, which runs on every request whether or not functions are enabled). The hook is appended LAST so it
 * sees every other filter's final output; the validator and the retry are CHAINED onto whatever another plugin set.
 */
function lrgNeRegister(): void
{
    if (!isset($GLOBALS['LRG_NE_VALIDATOR'])) {
        $GLOBALS['LRG_NE_VALIDATOR'] = static function ($chunk) { return lrgNeValidate($chunk); };
    }
    $prevV = $GLOBALS['VALIDATE_LLM_OUTPUT_FNCT'] ?? null;
    if (is_callable($prevV) && $prevV !== $GLOBALS['LRG_NE_VALIDATOR']) { $GLOBALS['LRG_NE_PREV_VALIDATOR'] = $prevV; }
    $GLOBALS['VALIDATE_LLM_OUTPUT_FNCT'] = $GLOBALS['LRG_NE_VALIDATOR'];

    if (!isset($GLOBALS['LRG_NE_RETRY'])) {
        $GLOBALS['LRG_NE_RETRY'] = static function () { lrgNeRetry(); };
    }
    $prevR = $GLOBALS['LLM_RETRY_FNCT'] ?? null;
    if (is_callable($prevR) && $prevR !== $GLOBALS['LRG_NE_RETRY']) { $GLOBALS['LRG_NE_PREV_RETRY'] = $prevR; }
    $GLOBALS['LLM_RETRY_FNCT'] = $GLOBALS['LRG_NE_RETRY'];

    if (!isset($GLOBALS['LRG_NE_HOOK'])) {
        $GLOBALS['LRG_NE_HOOK'] = static function ($actions) { return lrgNeHook(is_array($actions) ? $actions : []); };
    }
    $list = (array) ($GLOBALS['action_post_process_fnct_ex'] ?? []);
    if (!in_array($GLOBALS['LRG_NE_HOOK'], $list, true)) { $GLOBALS['action_post_process_fnct_ex'][] = $GLOBALS['LRG_NE_HOOK']; }
    // [pt18-words] NEVER FALSE's per-sentence drop (shape mute), chained ONCE per process onto whatever transformer is
    // installed (prompt.includes.php:68); context_pre.php chains lrgDlgTransformer onto ours afterwards, so a false
    // sentence is dropped before the dialogue mute decides. Installed once: a second chaining would loop the chain.
    if (!isset($GLOBALS['LRG_NF_TRANSFORMER'])) {
        $prevT = $GLOBALS['TRANSFORMER_FUNCTION'] ?? null;
        if (is_callable($prevT)) { $GLOBALS['LRG_NF_PREV_TRANSFORMER'] = $prevT; }
        $GLOBALS['LRG_NF_TRANSFORMER'] = static function ($s) { return lrgNfTransformer((string) $s); };
        $GLOBALS['TRANSFORMER_FUNCTION'] = $GLOBALS['LRG_NF_TRANSFORMER'];
    } elseif (!isset($GLOBALS['TRANSFORMER_FUNCTION'])) {
        $GLOBALS['TRANSFORMER_FUNCTION'] = $GLOBALS['LRG_NF_TRANSFORMER'];
    }
    // a new request (this runs before call_llm on every one; a process serves one request live, many offline)
    unset($GLOBALS['LRG_NF_SEEN'], $GLOBALS['LRG_NF_DROPPED'], $GLOBALS['LRG_NF_MUTED'], $GLOBALS['LRG_NF_LATE'],
        $GLOBALS['LRG_NF_REASKED'], $GLOBALS['LRG_NF_SHAPE'], $GLOBALS['LRG_NF_SAYING']);
}

// ================================================================== is this one of our turns?
/**
 * ['kind' => '' | speech | talk | voiced, 'npc', 'type', 'scene' => bool, 'why' => ''].
 * speech = the player's own line (LRG_PLAYER_SPEECH_TYPES); talk = lrg_dlgtalk (Phase 2's "answer his last words");
 * voiced = CHIM's funcret follow-up turn that Phase 1 marked mode `voiced` (PROTOCOL 10.21). A rechat, a scene tick,
 * an initiative tick, the diary, an infoaction and everything else: not ours - nothing was asked that needs an answer.
 * Under SHARMAT there is no LRG_TURN: that is "not a scene", and speech turns still count.
 */
function lrgNeKind(): array
{
    $type = strtolower((string) ($GLOBALS['gameRequest'][0] ?? ''));
    $npc = (string) ($GLOBALS['HERIKA_NAME'] ?? '');
    $out = ['kind' => '', 'npc' => $npc, 'type' => $type, 'scene' => false, 'why' => ''];
    if (empty(lrgNeCfg('enabled', true))) { $out['why'] = 'never_empty.enabled is off'; return $out; }
    if (!lrgEnabled()) { $out['why'] = 'the glue is off (kill switch)'; return $out; }
    $player = strtolower(trim((string) ($GLOBALS['PLAYER_NAME'] ?? '')));
    $lo = strtolower(trim($npc));
    if ($lo === '' || in_array($lo, ['player', 'the narrator', 'narrator'], true) || ($player !== '' && $lo === $player)) {
        $out['why'] = 'no NPC'; return $out;
    }
    $turn = $GLOBALS['LRG_TURN'] ?? null;
    $mode = is_array($turn) ? (string) ($turn['mode'] ?? '') : '';
    if (in_array($type, LRG_PLAYER_SPEECH_TYPES, true)) { $out['kind'] = 'speech'; }
    elseif ($type === 'lrg_dlgtalk') { $out['kind'] = 'talk'; }
    elseif ($type === 'funcret' && $mode === 'voiced') { $out['kind'] = 'voiced'; }
    else { $out['why'] = 'type ' . ($type !== '' ? $type : '(none)'); return $out; }
    $out['scene'] = in_array($mode, ['scene', 'outro'], true);
    return $out;
}

function lrgNeCid(): string
{
    $t = $GLOBALS['LRG_TURN'] ?? null;
    return is_array($t) ? (string) ($t['cid'] ?? '') : '';
}

/** The raw model text of this reply, '' when the model wrote none, null when no decoded reply exists at all. */
function lrgNeRawMessage(): ?string
{
    $r = $GLOBALS['LAST_LLM_RESPONSE'] ?? null;
    if (!is_array($r) || !array_key_exists('message', $r)) { return null; }
    $m = $r['message'];
    if (is_array($m)) { $m = implode(',', array_map('strval', $m)); }
    return trim((string) $m);
}

/** '' / none / Talk normalised: an action that carries nothing worth keeping over the words. */
function lrgNeActionless(string $act): bool
{
    $n = str_replace(['_', ' '], '', strtolower(trim($act)));
    return $n === '' || $n === 'none' || $n === 'talk';
}

/**
 * A business pick the game answers ITSELF this turn: the real dialogue line plays, the gate emits the pick and
 * lrgDlgTransformer mutes any spoken line (returnLines drops a transformed line under 2 chars without touching
 * $talkedSoFar). A floor line there would be recorded and never heard - so the rail stays out of it.
 */
function lrgNeWillEmitPick(string $act, string $item): bool
{
    $n = str_replace(['_', ' '], '', strtolower(trim($act)));
    if ($n === '' || !in_array($n, LRG_DLG_GATE_NAMES, true)) { return false; }
    $t = $GLOBALS['LRG_DLG_TURN'] ?? null;
    if (!is_array($t) || empty($t['on'])) { return false; }
    return lrgDlgWillEmit($t, $item);
}

/**
 * An emitted pick among the FINAL command lines (the dialogue gate ran before this hook).
 * [pt19 v1.0 / model F14, table 2.6] a pick carrying adv= (the auto-advance, or the re-armed breath rearm=1) is NOT "the game
 * answers itself": it waits for her line to end, so her words are the reply and an empty message must not be let through.
 * [pt19c-B fix 1 / architect review, table 2.6 "parked pick appended by the gate [F27] | WillEmit FALSE"] neither is the bare-yes
 * release lrgDlgPostProcessActions appends to a words-only reply (last_exec.mode 'bare-yes' for that x): her words play.
 */
function lrgNeLinesCarryPick(array $lines): bool
{
    foreach ($lines as $l) {
        $parts = explode('|', rtrim((string) $l, "\r\n"), 3);
        $code = trim((string) (explode('@', $parts[2] ?? '', 2)[0] ?? ''));
        $param = (string) ($parts[2] ?? '');
        if (strcasecmp($code, 'ExtCmdLRG_SelectTopic') !== 0 || !str_contains($param, ';do=pick') || preg_match('/;adv=\d/', $param)) { continue; }
        if (function_exists('lrgDlgState') && preg_match('/;npc=([^;@]*);/', $param, $mn) && preg_match('/;x=([^;@]+);/', $param, $mx)) {
            $le = (array) (lrgDlgState((string) $mn[1])['last_exec'] ?? []);
            if ((string) ($le['x'] ?? '') === (string) $mx[1] && (string) ($le['mode'] ?? '') === 'bare-yes') { continue; }
        }
        return true;
    }
    return false;
}

// ================================================================== layer 1: the validator
/**
 * COMPLETE = every schema-required key present, the schema's LAST property present, and `message` not the last key
 * the model wrote. The connector re-decodes the whole buffer per chunk and __jpd closes a partial object with "}, so
 * before the tail keys arrive a "" message is the model still writing, not a verdict. In both key orders CHIM uses
 * (character-first, and the glue's D3 action-first) `message` sits in the middle; a model that put it LAST is left
 * to the hook, which judges only when the stream has ended.
 */
function lrgNeComplete(array $r): bool
{
    $sch = $GLOBALS['structuredOutputTemplate']['json_schema']['schema'] ?? null;
    $props = is_array($sch) && is_array($sch['properties'] ?? null) ? array_map('strval', array_keys($sch['properties'])) : [];
    $req = is_array($sch) && is_array($sch['required'] ?? null) ? array_map('strval', $sch['required']) : [];
    if (!$props) {
        // json_object mode: the text template's keys are what the model imitates
        $props = is_array($GLOBALS['responseTemplate'] ?? null) ? array_map('strval', array_keys($GLOBALS['responseTemplate'])) : [];
        $req = $props;
    }
    if (!$props) { return false; }
    foreach ($req as $k) { if (!array_key_exists($k, $r)) { return false; } }
    if (!array_key_exists((string) end($props), $r)) { return false; }
    $keys = array_keys($r);
    if ((string) end($keys) === 'message') { return false; }
    return true;
}

function lrgNeValidate($chunk): bool
{
    $prev = $GLOBALS['LRG_NE_PREV_VALIDATOR'] ?? null;
    if (is_callable($prev) && !$prev($chunk)) { return false; }
    if (!empty($GLOBALS['LRG_NE_REJECT']) || !empty($GLOBALS['LRG_NE_DONE'])) { return true; }
    // [pt18-words] NEVER FALSE first: a faction turn's words are judged per chunk BEFORE the sentence can reach
    // returnLines (its own kind: the faction record, a rechat included - lrgNeKind() leaves a rechat out on purpose)
    if (!lrgNfValidate()) { return false; }
    $k = lrgNeKind();
    if ($k['kind'] === '') { return true; }
    $msg = lrgNeRawMessage();
    if ($msg === null || $msg !== '') { return true; }
    $r = (array) $GLOBALS['LAST_LLM_RESPONSE'];
    if (!lrgNeComplete($r)) { return true; }
    $act = (string) ($r['action'] ?? '');
    $item = (string) ($r['item'] ?? '');
    $cid = lrgNeCid();
    $tag = sprintf('never-empty npc=%s action=%s item="%s"', $k['npc'], lrgShortCode($act === '' ? 'none' : $act), substr($item, 0, 40));
    if (lrgNeWillEmitPick($act, $item)) {
        if (empty($GLOBALS['LRG_NE_SEEN'])) { $GLOBALS['LRG_NE_SEEN'] = 1; lrgLog($tag . ' verdict=let-through (a business pick the game answers itself)', $cid); }
        return true;
    }
    if ($k['scene'] && empty(lrgNeCfg('in_scene', false))) {
        if (empty($GLOBALS['LRG_NE_SEEN'])) { $GLOBALS['LRG_NE_SEEN'] = 1; lrgLog($tag . ' verdict=log-only (a scene turn; never_empty.in_scene is off)', $cid); }
        return true;
    }
    $keep = !lrgNeActionless($act);
    if ($keep && empty(lrgNeCfg('reask', true))) {
        // no re-ask: a rejection would throw the action away with the words - the hook floors it with the action kept
        if (empty($GLOBALS['LRG_NE_SEEN'])) { $GLOBALS['LRG_NE_SEEN'] = 1; lrgLog($tag . ' verdict=let-through (an action to keep; the hook adds the line)', $cid); }
        return true;
    }
    $GLOBALS['LRG_NE_REJECT'] = ['why' => 'empty message', 'action' => $act, 'item' => $item, 'at' => lrgNow()];
    lrgLog($tag . ' verdict=rejected (the model wrote no words' . ($keep ? '; the action rides the re-ask' : '') . ')', $cid);
    return false;
}

// ================================================================== layer 2: the retry (main.php:2825)
function lrgNeRetry(): void
{
    $prev = $GLOBALS['LRG_NE_PREV_RETRY'] ?? null;
    $k = lrgNeKind();
    $rej = $GLOBALS['LRG_NE_REJECT'] ?? null;
    // [pt18-words] a FALSE CLAIM was rejected mid-stream: its own retry (a rechat has kind '' here and must not fall silent)
    if (is_array($rej) && (string) ($rej['why'] ?? '') === 'false claim') { lrgNfRetry($k, $rej); return; }
    if ($k['kind'] === '') { if (is_callable($prev)) { $prev(); } return; }
    $why = is_array($rej) ? 'empty message' : (is_array($GLOBALS['LAST_LLM_RESPONSE'] ?? null) ? 'invalid output' : 'no reply');
    $act = is_array($rej) ? (string) ($rej['action'] ?? '') : '';
    $cid = lrgNeCid();
    if ($why === 'empty message' && !empty(lrgNeCfg('reask', true)) && empty($GLOBALS['LRG_NE_REASKED'])) {
        $GLOBALS['LRG_NE_REASKED'] = 1;
        unset($GLOBALS['LRG_NE_REJECT']);
        $before = strlen(lrgSpokenThisTurn());
        $ok = lrgNeReask($k);
        $after = strlen(lrgSpokenThisTurn());
        if ($ok && $after > $before) {
            lrgLog(sprintf('never-empty npc=%s action=%s why=empty message reask=spoke chars=%d', $k['npc'], lrgShortCode($act === '' ? 'none' : $act), $after - $before), $cid);
            lrgNeRemember($k['npc'], $cid, 'empty message', '', -1, 'reask');
            return;
        }
        $rej2 = $GLOBALS['LRG_NE_REJECT'] ?? null;
        $why = is_array($rej2) ? 'empty message twice' : 'the re-ask failed';
        if (is_array($rej2)) { $act = (string) ($rej2['action'] ?? $act); }
    }
    if (is_callable($prev)) { $prev(); }
    lrgNeFloor($k, $why, $act);
}

/** The rule appended to the last user message for the second call. Rules only, no example lines (PROTOCOL 0). */
function lrgNeNudge(string $npc): string
{
    return '(Rule for this reply: ' . $npc . ' answers in words - the message field carries at least one short spoken sentence in '
        . $npc . "'s own voice, then the action if any. An empty message is not an answer.)";
}

/**
 * One more call_llm() with the rule, under IN_FALLBACK_MODE (no second player TTS). True = it produced words.
 * [pt18-words] $nudge: the never-false rule instead of the never-empty one (lrgNfNudge); '' = the words rule.
 */
function lrgNeReask(array $k, string $nudge = ''): bool
{
    if ($nudge === '') { $nudge = lrgNeNudge((string) $k['npc']); }
    $restore = null;
    $ctx = $GLOBALS['contextData'] ?? null;
    if (is_array($ctx) && $ctx) {
        for ($i = count($ctx) - 1; $i >= 0; $i--) {
            if ((string) ($ctx[$i]['role'] ?? '') === 'user' && is_string($ctx[$i]['content'] ?? null)) {
                $restore = $ctx;
                $GLOBALS['contextData'][$i]['content'] = rtrim($ctx[$i]['content']) . "\n" . $nudge;
                break;
            }
        }
    }
    unset($GLOBALS['patch_openrouter_timeout']);   // the connector's 60 s receive clock is per process: start it afresh
    $GLOBALS['IN_FALLBACK_MODE'] = true;
    $before = strlen(lrgSpokenThisTurn());
    $ok = false;
    try {
        if (isset($GLOBALS['LRG_TEST_REASK']) && is_callable($GLOBALS['LRG_TEST_REASK'])) { $ok = (bool) $GLOBALS['LRG_TEST_REASK']($nudge); }
        elseif (function_exists('call_llm')) { $ok = (bool) call_llm(); }
    } finally {
        unset($GLOBALS['IN_FALLBACK_MODE']);
        if ($restore !== null) { $GLOBALS['contextData'] = $restore; }
    }
    return $ok && strlen(lrgSpokenThisTurn()) > $before;
}

// ================================================================== layer 3: the hook (last post-process filter)
function lrgNeHook(array $actions): array
{
    // [pt18-words] NEVER FALSE: the floor after a muted sentence, or a late verdict in the message-last key order
    $actions = lrgNfHook($actions);
    $k = lrgNeKind();
    if ($k['kind'] === '' || !empty($GLOBALS['LRG_NE_DONE'])) { return $actions; }
    $msg = lrgNeRawMessage();
    if ($msg === null || $msg !== '') { return $actions; }
    $r = (array) $GLOBALS['LAST_LLM_RESPONSE'];
    $act = (string) ($r['action'] ?? '');
    $item = (string) ($r['item'] ?? '');
    if (lrgNeWillEmitPick($act, $item) || lrgNeLinesCarryPick($actions)) {
        lrgLog(sprintf('never-empty npc=%s action=%s item="%s" verdict=idle (a business pick the game answers itself)',
            $k['npc'], lrgShortCode($act === '' ? 'none' : $act), substr($item, 0, 40)), lrgNeCid());
        return $actions;
    }
    lrgNeFloor($k, 'empty message', $act);
    return $actions;
}

// ================================================================== the floor line
function lrgNeFloor(array $k, string $why, string $act): void
{
    if (!empty($GLOBALS['LRG_NE_DONE'])) { return; }
    $GLOBALS['LRG_NE_DONE'] = 1;
    $npc = (string) $k['npc'];
    $cid = lrgNeCid();
    $code = lrgShortCode($act === '' ? 'none' : $act);
    $already = lrgSpokenThisTurn();
    if ($already !== '') {
        // the invalid-output branch: sentences may already have been spoken mid-stream - nothing is added
        lrgLog(sprintf('never-empty npc=%s action=%s why=%s said=(she already spoke %d chars this turn; nothing added)', $npc, $code, $why, strlen($already)), $cid);
        return;
    }
    if (!empty($k['scene']) && empty(lrgNeCfg('in_scene', false))) {
        lrgLog(sprintf('never-empty npc=%s action=%s why=%s verdict=log-only (a scene turn; never_empty.in_scene is off)', $npc, $code, $why), $cid);
        lrgNeRemember($npc, $cid, $why, '', -1, 'scene');
        return;
    }
    $kind = in_array($why, ['empty message', 'empty message twice'], true) ? (lrgNeIsQuestion() ? 'question' : 'statement') : 'trouble';
    [$line, $idx] = lrgNePick($npc, $kind);
    if ($line === '') {
        lrgLog(sprintf('never-empty npc=%s action=%s why=%s said=(no %s line configured)', $npc, $code, $why, $kind), $cid);
        return;
    }
    lrgNeSay($line);
    if (lrgSpokenThisTurn() === '') {
        // the transformer chain dropped it (a line shorter than 2 chars after every transformer): not a spoken line
        lrgLog(sprintf('never-empty npc=%s action=%s why=%s said="%s" BUT it was not spoken (dropped by a transformer)', $npc, $code, $why, $line), $cid);
        return;
    }
    lrgLog(sprintf('never-empty npc=%s action=%s why=%s said="%s"', $npc, $code, $why, $line), $cid);
    lrgNeRemember($npc, $cid, $why, $line, $idx, $kind);
}

/** CHIM's own spoken path when it is there (TTS, $talkedSoFar, the chat row, the ScriptQueue echo); the seam offline. */
function lrgNeSay(string $line): void
{
    if (function_exists('returnLines')) { returnLines([$line]); return; }
    $GLOBALS['LRG_TEST_SAY'][] = $line;
    $GLOBALS['talkedSoFar'][] = $line;   // what returnLines() does at lib/chat_helper_functions.php:1635
}

/** Did the player's words this turn ask something? A '?' or a leading question word; a funcret text never does. */
function lrgNeIsQuestion(): bool
{
    $type = strtolower((string) ($GLOBALS['gameRequest'][0] ?? ''));
    if (!in_array($type, LRG_PLAYER_SPEECH_TYPES, true) && $type !== 'lrg_dlgtalk') { return false; }
    $s = trim(lrgStripContext((string) ($GLOBALS['gameRequest'][3] ?? '')));
    if ($type === 'lrg_dlgtalk') { $s = trim(lrgStripContext((string) (($GLOBALS['LRG_DLG_TALK']['utter'] ?? [])['text'] ?? $s))); }
    if ($s === '') { return false; }
    if (str_ends_with($s, '?')) { return true; }
    return (bool) preg_match('/^(who|what|where|when|why|how|do|does|did|is|are|can|could|would|will|should|have|has|which|am)\b/i', $s);
}

/** [line, index] from never_empty.lines.<kind>, rotated per NPC so the same line is not heard twice running. */
function lrgNePick(string $npc, string $kind): array
{
    $lines = array_values(array_filter(array_map('strval', (array) lrgNeCfg('lines.' . $kind, [])), static fn($l) => trim($l) !== ''));
    if (!$lines) { return ['', -1]; }
    $max = max(12, (int) lrgNeCfg('max_chars', 60));
    $e = (array) (lrgMemGet($npc)['empty_reply'] ?? []);
    $n = (int) ($e['n'] ?? 0);
    $i = $n % count($lines);
    if (count($lines) > 1 && (string) ($e['line'] ?? '') === $lines[$i]) { $i = ($i + 1) % count($lines); }
    return [substr(trim($lines[$i]), 0, $max), $i];
}

/** lrg_memory.empty_reply {at, cid, n, why, line, i, kind, told}: the count for the owner's greps and the next-turn note. */
function lrgNeRemember(string $npc, string $cid, string $why, string $line, int $idx, string $kind): void
{
    if ($npc === '') { return; }
    $e = (array) (lrgMemGet($npc)['empty_reply'] ?? []);
    lrgMemSet($npc, ['empty_reply' => ['at' => lrgNow(), 'cid' => $cid, 'n' => (int) ($e['n'] ?? 0) + 1, 'why' => $why,
        'line' => $line, 'i' => $idx, 'kind' => $kind, 'told' => false]]);
}

// ================================================================== next turn: the rule (Phase 2 volatile guidance)
/**
 * One sentence for lrgDlgVolatileGuidance() while empty_reply.at is within never_empty.note_seconds, on the player's
 * next speech / lrg_dlgtalk turn with this NPC, told once. Rules only, no example lines.
 */
function lrgNeNote(string $npc): string
{
    // [pt18-words] the never-empty sentence, then the never-false one (lrgNfNote) - each once, each on its own clock
    $ne = lrgNeNoteEmpty($npc);
    $nf = function_exists('lrgNfNote') ? lrgNfNote($npc) : '';
    return $ne . ($ne !== '' && $nf !== '' ? "\n" : '') . $nf;
}

function lrgNeNoteEmpty(string $npc): string
{
    if ($npc === '' || empty(lrgNeCfg('enabled', true))) { return ''; }
    $type = strtolower((string) ($GLOBALS['gameRequest'][0] ?? ''));
    if (!in_array($type, LRG_PLAYER_SPEECH_TYPES, true) && $type !== 'lrg_dlgtalk') { return ''; }
    $e = (array) (lrgMemGet($npc)['empty_reply'] ?? []);
    if (!$e || !empty($e['told'])) { return ''; }
    if (lrgNow() - (int) ($e['at'] ?? 0) > max(10, (int) lrgNeCfg('note_seconds', 120))) { return ''; }
    lrgMemSet($npc, ['empty_reply' => ['told' => true] + $e]);
    return $npc . "'s last reply carried no words at all. Whatever the player says now, " . $npc
        . ' answers in words: one or two short spoken sentences, then the action if any.';
}

// ================================================================== [pt18-words] NEVER FALSE
/**
 * NEVER FALSE: an NPC never claims an enlistment, an oath, a rank, orders or a next step the game did not record.
 *
 * Playtest 2026-09-23 23:19 -04:00, General Tullius at the map table (ml=0, ambient scene, no session, no command):
 * "i swear to uphold the imperial vows" got "Then you are a Legionnaire. Report to Legate Rikke at the training yard for
 * your first orders." (output_from_llm.log Request ID 858) with the facts line saying qst=CW00A:0 before and after.
 * The prompt rule had said "nothing has begun" twice and grok-4.3 enacted the oath anyway - CHIM core's own
 * <roleplay_instructions> tell the model to treat the director's prompts as established fact and build the next story
 * beat on them, so a rule alone is structurally losing. No rail judged the WORDS: the truth gate drops only a money
 * action, the never-empty validator tests emptiness. research/pt18-words.md has the evidence and the design.
 *
 * WHAT IS JUDGED. Only a turn where $GLOBALS['LRG_DLG_TURN'] is on and this NPC has a faction role (lrgFacRoles: recruiter
 * or redirect for a row) or a faction ask is on the turn (lrgFacTurn, role none included) - a player-speech, lrg_dlgtalk
 * or rechat turn. Every sentence is judged once; a question, a negated sentence and a conditional clause are never a
 * claim. Four classes, per row (never_false.rows.<id>, defaults in code; a faction row's own cells of the same name win):
 *   member - "you are a legionnaire / one of us / sworn in", "welcome to the Legion", "your oath is accepted";
 *   oath   - the oath administered NOW ("swear your oath here", "repeat after me");
 *   next   - the recruiter's next step by name ("report to Legate Rikke") - RECRUITER only; a redirect's or a guard's
 *            "speak to Legate Rikke in Castle Dour" is his own real line and is exempt;
 *   orders - an appointment the game never records (training yard, barracks, "for your first orders", "at dawn",
 *            "when the bell rings", "your training begins") - false whatever the stage, unless the row's `allow` map
 *            names the phrase for a stage (the Fort Hraggstad test at CW01A >= 1).
 * WHAT MAKES A CLAIM TRUE. The row's `truth` map (class -> quest -> minimum stage; UESP, the URL is in the row) against
 * the EFFECTIVE quest stages: the game's own qst= (lrgDlgFactsFrom, the facts line, capped at six quests on both sides),
 * the quest lane's $turn['faction']['executed'] (a stage the lane has just queued through the real entry, set before
 * call_llm), a persisted `exec_qst` in lrgDlgState while it is fresher than the last facts line (SendFacts is throttled
 * 60 s and never sent while a session is open), and - quest stages are global - the freshest cached facts line of the
 * row's recruiters. A quest ABSENT from every source is UNKNOWN and the claim is let through with `verdict=unknown`
 * (never stage 0: a completed CW01A is not running and never appears in qst=), except where the row's `derive` map
 * knows better (CW01A cannot have started while CW00A is running below 10). A redirect / none never administers an oath
 * or gives orders. Membership is judged by stage for every role (a guard welcoming a real legionnaire passes).
 * THE THREE LAYERS ride lrg_replies.php's own seams, and the SHAPE of the reply decides which:
 *   reject - shape `reject` (never_false.mode reask, a live turn, the action known and not a dialogue pick, or any turn
 *            functions are off for - a rechat - because no post-process hook runs there): lrgNfValidate() judges the
 *            PARTIAL message per chunk (openrouterjson.php:1038 sets LAST_LLM_RESPONSE before returning the delta, and
 *            data_functions.php:5937 runs the validator before the chunk is appended and before findFastSentencePosition,
 *            which splits only on .?! + whitespace - so a terminated false sentence is rejected in the chunk that completed
 *            it, before returnLines can take it), rejects it, and the retry re-asks ONCE with the nudge that quotes the
 *            model's own sentence (lrgNfNudge); a second false reply, or no words, -> one truthful floor line after any
 *            truthful partial (never_false.lines.<role>, spoken through returnLines).
 *   mute   - shape `mute` (never_false.mode mute, or a reply carrying a dialogue pick, or the action not known yet - the
 *            character-first key order - because a rejection would throw a real pick away): lrgNfTransformer(), chained
 *            on CHIM's TRANSFORMER_FUNCTION, drops the false sentence inside returnLines (no TTS, not in talkedSoFar), the
 *            stream and the action go on, and lrgNfHook() speaks the floor line after the stream - unless an emitted
 *            pick answers the turn itself. No second LLM call ever.
 *   log    - never_false.mode log: judge and log only (today's behaviour plus the log line).
 * A false tail in the message-LAST key order is judged by the hook when the stream has ended (`verdict=late`): the words
 * were spoken, the floor line is added after them and the next turn carries the note (lrgNfNote via lrgNeNote).
 *
 * Log: `never-false npc=<n> role=<r> class=<c> said="<sentence>" fact="<quest>:<stage>[(src)]" verdict=rejected|muted|
 * late|log-only|true|unknown|exempt ml=<0|1> cal=<n|-> ambient=<0|1> open=<0|1> type=<t>` (the owner sees WHY enlistment
 * cannot start by voice at the map table: ml=0 cal=0 ambient=1 open=0) and `never-false npc=<n> role=<r> why=<why>
 * said="<floor line>"` / `reask=spoke chars=<n>`. grep never-false = how often the model does it.
 * Offline seams: $GLOBALS['LRG_NF_TEST_OVERRIDE'] merges over the config; LRG_TEST_SAY / LRG_TEST_REASK as above.
 */
function lrgNfDefaults(): array
{
    return [
        'enabled' => true,
        // reask: a false sentence on a live player turn stops the stream and she is asked ONCE more with the rule (her own
        // words, addendum 11); a reply carrying a dialogue pick, a reply whose action is not known yet, and mode=mute
        // use the per-sentence drop + one floor line instead (no second LLM call). log: judge and log only.
        'mode' => 'reask',
        // [pt19-purchase] `price`: a price frame with a number the vendor's list does not carry (digits or words: "one septim")
        // [pt19 v1.0 / S6.2, gate B] `reward`: "I'll add fifty septims on top" is true only when this turn's reward check PASSED
        // and granted that bonus (lrgDlgCheck give=); judged only while checks.reward.enabled (the pseudo-row 'reward')
        'classes' => ['member', 'oath', 'next', 'orders', 'price', 'reward'],
        'max_chars' => 100,
        'note_seconds' => 120,
        // another NPC's facts line (quest stages are global) stands in for this one's this long
        'cache_seconds' => 1800,
        // SPOKEN floor lines by role, rotated per NPC. They name the real reason in her words: nothing is settled by
        // talk, enlistment happens the proper way (the real dialogue entry), and nothing has begun. Owner-editable.
        'lines' => [
            'recruiter' => ['Nobody is sworn in by a word at this table. Enlistment is done the proper way, or not at all.',
                'I take no oath here. Come to me properly and we will see about your enlistment.'],
            'redirect' => ['That is not mine to grant. Enlistment is done properly, by the ones who take recruits.',
                'I cannot sign anyone up. Ask the ones who take recruits, the proper way.'],
            'none' => ['I cannot enlist anyone. Nothing has begun.', 'That is not something I can settle. Nothing has begun.'],
            // [pt19-purchase] the vendor's floor line: {item} / {price} are the list's own (lrgMktPriceVerdict), never a guess
            'vendor' => ['I only ask what my list says: {item} is {price} septims.', 'The price is what it is - {item}, {price} septims, no more and no less.'],
            // [pt19 v1.0 / S6.2, gate B] the reward pseudo-row's floor: the reward is fixed, nothing is promised
            'reward' => ['The reward is what it is. I have nothing to add to it.', 'I add nothing to the reward - it is what it is.'],
        ],
        // per faction row: ranks (a "you are a <rank>" is a member claim), next_names (the recruiter's next step by name),
        // truth (class -> quest -> minimum stage that makes the claim true), derive (quest -> [gate quest, stage]: the
        // quest is at 0 while the gate quest is running below that stage), allow (orders phrases true from a stage),
        // stages (what a recorded stage means, for the executed locked line). Unverified rows carry ranks only:
        // a recruiter's claim on them is UNKNOWN (let through, logged), a redirect's oath / orders still false.
        'rows' => [
            'default' => ['ranks' => ['member', 'recruit', 'initiate'], 'next_names' => [], 'truth' => [], 'derive' => [], 'allow' => [], 'stages' => []],
            'legion' => [
                'ranks' => ['legionnaire', 'legionary', 'auxiliary', 'soldier', 'recruit', 'imperial soldier', 'soldier of the empire'],
                'next_names' => ['rikke'],
                // https://en.uesp.net/wiki/Skyrim:Joining_the_Legion : CW00A 1 / 7 / 10 (10 = spoken directly to General
                // Tullius); CW01A 1 = "Clear out Fort Hraggstad" (Rikke has set the test), 100 = "Report to Legate Rikke",
                // 160 = "Take the oath", 200 = done (the quest stops running: it never appears in qst= again);
                // https://en.uesp.net/wiki/Skyrim:The_Jagged_Crown_(Imperial) : CW02A 10 = the first assignment (a member).
                'truth' => [
                    'member' => ['CW01A' => 200, 'CW02A' => 10],
                    'oath' => ['CW01A' => 160],
                    'next' => ['CW00A' => 10, 'CW01A' => 100, 'CW02A' => 10],
                ],
                'derive' => ['CW01A' => ['CW00A', 10], 'CW02A' => ['CW00A', 10]],
                'allow' => ['fort hraggstad|the fort|the bandits' => ['CW01A' => 1]],
                'stages' => ['CW00A' => [10 => 'he is sent to Legate Rikke'],
                    'CW01A' => [1 => 'Legate Rikke has set him the test at Fort Hraggstad', 100 => 'he is to report back to Legate Rikke',
                        160 => 'he is to take the oath', 200 => 'he is a Legionnaire']],
            ],
            'stormcloaks' => ['ranks' => ['stormcloak', 'soldier', 'recruit', 'unblooded', 'ice-veins', 'bone-breaker', 'snow-hammer', 'stormblade'],
                'next_names' => ['galmar']],
            'companions' => ['ranks' => ['companion', 'shield-brother', 'shield-sister', 'whelp', 'one of the circle']],
            'college' => ['ranks' => ['apprentice', 'apprentice of the college', 'member of the college', 'student of the college']],
            'thieves_guild' => ['ranks' => ['thief', 'member of the guild', 'one of the guild']],
            'dark_brotherhood' => ['ranks' => ['assassin', 'one of the brotherhood', 'member of the brotherhood']],
            'bards' => ['ranks' => ['bard of the college', 'student of the college', 'member of the college']],
            'dawnguard' => ['ranks' => ['dawnguard', 'vampire hunter', 'member of the dawnguard']],
            'volkihar' => ['ranks' => ['vampire', 'one of us', 'member of the court']],
            'blades' => ['ranks' => ['blade', 'one of the blades']],
            'penitus' => ['ranks' => ['agent', 'member of the penitus oculatus']],
            'vigilants' => ['ranks' => ['vigilant', 'vigilant of stendarr']],
            'guards' => ['ranks' => ['guard', 'guardsman', 'member of the guard']],
            'eec' => ['ranks' => ['employee of the company', 'member of the company']],
            'thalmor' => ['ranks' => ['thalmor', 'agent of the dominion', 'justiciar']],
        ],
    ];
}

/** Defaults in code + the owner's top-level `never_false` block + the test seam (like lrgNeCfg). */
function lrgNfCfg(string $path = '', $default = null)
{
    static $cfg = null;
    static $seam = false;
    if ($cfg === null || $seam || array_key_exists('LRG_NF_TEST_OVERRIDE', $GLOBALS)) {
        $cfg = lrgNfDefaults();
        $over = lrgConfig()['never_false'] ?? null;
        if (is_array($over)) { $cfg = lrgMerge($cfg, $over); }
        $seam = is_array($GLOBALS['LRG_NF_TEST_OVERRIDE'] ?? null);
        if ($seam) { $cfg = lrgMerge($cfg, $GLOBALS['LRG_NF_TEST_OVERRIDE']); }
    }
    if ($path === '') { return $cfg; }
    $v = $cfg;
    foreach (explode('.', $path) as $k) {
        if (!is_array($v) || !array_key_exists($k, $v)) { return $default; }
        $v = $v[$k];
    }
    return $v;
}

/**
 * The judge's data for one faction row: never_false.rows.default, then never_false.rows.<id>, then the faction row's
 * own cells of the same name (so the quest lane may move them into lib/lrg_factions.php later), plus the faction row's
 * words / name / how / recruiters.
 */
function lrgNfRow(string $id): array
{
    $row = (array) lrgNfCfg('rows.default', []);
    $own = lrgNfCfg('rows.' . $id);
    if (is_array($own)) { $row = lrgMerge($row, $own); }
    $fac = function_exists('lrgFacRow') ? (array) lrgFacRow($id) : [];
    foreach (['ranks', 'next_names', 'truth', 'derive', 'allow', 'stages', 'floor'] as $cell) {
        if (isset($fac[$cell]) && (is_array($fac[$cell]) ? $fac[$cell] !== [] : trim((string) $fac[$cell]) !== '')) { $row[$cell] = $fac[$cell]; }
    }
    $row['id'] = $id;
    $row['name'] = (string) ($fac['name'] ?? $id);
    $row['how'] = trim((string) ($fac['how'] ?? ''));
    $row['words'] = array_values(array_filter(array_map('strval', (array) ($fac['words'] ?? []))));
    $row['recruiters'] = array_map('strval', array_keys((array) ($fac['recruiters'] ?? [])));
    return $row;
}

/** What a recorded stage means, from the row's `stages` map ('' when unknown). Bracket-free. */
function lrgNfStageMeaning(string $id, string $quest, int $stage): string
{
    $row = lrgNfRow($id);
    $m = (string) ((($row['stages'] ?? [])[$quest] ?? [])[$stage] ?? '');
    return str_replace(['<', '>'], '', trim($m));
}

/**
 * Is this a turn the judge covers? ['t', 'npc', 'type', 'live', 'rechat', 'rows' => [id => role] (the asked row first),
 * 'stages', 'cid'] or null. Decided by the dialogue turn record and the faction record - not by lrgNeKind(), which
 * leaves a rechat out (the 16:38 double-down came on a rechat).
 */
function lrgNfContext(): ?array
{
    if (empty(lrgNfCfg('enabled', true)) || !lrgEnabled()) { return null; }
    $t = $GLOBALS['LRG_DLG_TURN'] ?? null;
    if (!is_array($t) || empty($t['on'])) { return null; }
    $npc = (string) ($t['npc'] ?? '');
    if ($npc === '') { return null; }
    $her = (string) ($GLOBALS['HERIKA_NAME'] ?? '');
    if ($her !== '' && strcasecmp($her, $npc) !== 0) { return null; }
    $type = strtolower((string) ($t['type'] ?? ($GLOBALS['gameRequest'][0] ?? '')));
    $live = in_array($type, LRG_PLAYER_SPEECH_TYPES, true) || $type === 'lrg_dlgtalk';
    $rechatTypes = function_exists('lrgFacCfg') ? array_map('strval', (array) lrgFacCfg('rechat_types', ['rechat'])) : ['rechat'];
    $rechat = in_array($type, $rechatTypes, true);
    if (!$live && !$rechat) { return null; }
    $rows = [];
    $f = (array) ($t['faction'] ?? []);
    if (function_exists('lrgFacRoles')) {
        if (!empty($f['join']) && (string) ($f['asked'] ?? '') !== '') { $rows[(string) $f['asked']] = (string) ($f['role'] ?? 'none'); }
        foreach (lrgFacRoles($t) as $id => $role) { if (!isset($rows[(string) $id])) { $rows[(string) $id] = (string) $role; } }
    }
    // [pt19-purchase] a vendor turn with fresh stock / a room price on the wire: the PRICE class is judged (the pseudo-row
    // 'vendor' carries no ranks, oath or orders - lrgNfClassify judges only prices on it)
    if (function_exists('lrgMktRailFacts') && lrgMktRailFacts($t)) { $rows['vendor'] = 'vendor'; }
    // [pt19 v1.0 / S6.2, gate B] a quest turn while the bounded bonus is on: her claim of a bonus is judged (the pseudo-row
    // 'reward' carries only that class)
    if (!empty(lrgDlgCfg('checks.reward.enabled', false)) && function_exists('lrgDlgQuestTurn') && lrgDlgQuestTurn($t)) { $rows['reward'] = 'reward'; }
    if (!$rows) { return null; }
    return ['t' => $t, 'npc' => $npc, 'type' => $type, 'live' => $live, 'rechat' => $rechat, 'rows' => $rows,
        'stages' => lrgNfStages($t, array_keys($rows)), 'cid' => (string) ($t['cid'] ?? '')];
}

/**
 * The EFFECTIVE quest stages this turn: quest => ['stage', 'src', 'at'], the freshest source per quest. Sources: the
 * facts line (qst=), the quest lane's executed flag this turn, a persisted exec_qst fresher than the facts line, and
 * the cached facts lines of the row's recruiters (quest stages are global) within never_false.cache_seconds.
 */
function lrgNfStages(array $t, array $rowIds): array
{
    $out = [];
    $put = static function (string $q, int $stage, string $src, int $at) use (&$out): void {
        if ($q === '') { return; }
        if (!isset($out[$q]) || $at >= (int) $out[$q]['at']) { $out[$q] = ['stage' => $stage, 'src' => $src, 'at' => $at]; }
    };
    $npc = (string) ($t['npc'] ?? '');
    $facts = (array) ($t['facts'] ?? []);
    $fat = (int) ($facts['at'] ?? 0);
    foreach ((array) ($facts['qst'] ?? []) as $q => $s) { $put((string) $q, (int) $s, 'qst', $fat); }
    $now = lrgNow();
    $ex = (array) (((array) ($t['faction'] ?? []))['executed'] ?? []);
    if ((string) ($ex['quest'] ?? '') !== '' && isset($ex['stage'])) { $put((string) $ex['quest'], (int) $ex['stage'], 'executed', $now + 1); }
    $max = max(60, (int) lrgNfCfg('cache_seconds', 1800));
    $stateOf = static fn(string $who): array => (function_exists('lrgDlgState') && $who !== '') ? (array) lrgDlgState($who) : [];
    $pe = (array) ($stateOf($npc)['exec_qst'] ?? []);
    if ((string) ($pe['quest'] ?? '') !== '' && isset($pe['stage']) && (int) ($pe['at'] ?? 0) > $fat && $now - (int) ($pe['at'] ?? 0) <= $max) {
        $put((string) $pe['quest'], (int) $pe['stage'], 'exec', (int) $pe['at']);
    }
    $me = function_exists('lrgFacName') ? lrgFacName($npc) : $npc;
    foreach ($rowIds as $id) {
        foreach (lrgNfRow((string) $id)['recruiters'] as $name) {
            if ($name === '' || strcasecmp($name, $me) === 0) { continue; }
            $ost = $stateOf($name);
            $of = (array) ($ost['facts'] ?? []);
            $oat = (int) ($of['at'] ?? 0);
            if ($oat > 0 && $now - $oat <= $max) {
                foreach ((array) ($of['qst'] ?? []) as $q => $s) { $put((string) $q, (int) $s, 'cache:' . $name, $oat); }
            }
            $oe = (array) ($ost['exec_qst'] ?? []);
            if ((string) ($oe['quest'] ?? '') !== '' && isset($oe['stage']) && (int) ($oe['at'] ?? 0) > $oat && $now - (int) ($oe['at'] ?? 0) <= $max) {
                $put((string) $oe['quest'], (int) $oe['stage'], 'exec:' . $name, (int) $oe['at']);
            }
        }
    }
    return $out;
}

/** One quest's effective stage: ['known' => bool, 'stage', 'src'], with the row's `derive` map when it is absent. */
function lrgNfStageOf(array $stages, string $q, array $row): array
{
    if (isset($stages[$q])) { return ['known' => true, 'stage' => (int) $stages[$q]['stage'], 'src' => (string) $stages[$q]['src']]; }
    $d = array_values((array) (($row['derive'] ?? [])[$q] ?? []));
    if (count($d) >= 2) {
        $gate = (string) $d[0];
        if (isset($stages[$gate])) {
            $gs = (int) $stages[$gate]['stage'];
            $gsrc = (string) $stages[$gate]['src'];
            // the gate quest runs below the stage that starts this one - or OUR OWN pick has just set exactly that
            // stage (executed / exec): nothing later can have happened yet
            if ($gs < (int) $d[1] || ($gs === (int) $d[1] && str_starts_with($gsrc, 'exec'))) {
                return ['known' => true, 'stage' => 0, 'src' => 'derived:' . $gate . ':' . $gs . ($gsrc !== 'qst' ? '(' . $gsrc . ')' : '') . ($gs < (int) $d[1] ? '<' : '=') . (int) $d[1]];
            }
        }
    }
    return ['known' => false, 'stage' => 0, 'src' => ''];
}

/** true / false / unknown against a quest -> minimum stage map. */
function lrgNfMeets(array $need, array $stages, array $row): array
{
    $known = false;
    $facts = [];
    foreach ($need as $q => $min) {
        $st = lrgNfStageOf($stages, (string) $q, $row);
        if (!$st['known']) { $facts[] = $q . ':?'; continue; }
        $tag = $q . ':' . $st['stage'] . ($st['src'] !== 'qst' ? '(' . $st['src'] . ')' : '');
        if ($st['stage'] >= (int) $min) { return ['verdict' => 'true', 'fact' => $tag . '>=' . (int) $min]; }
        $facts[] = $tag . '<' . (int) $min;
        $known = true;
    }
    return ['verdict' => $known ? 'false' : 'unknown', 'fact' => implode(',', $facts) ?: '-'];
}

/** The sentences of a (partial) message: [['s' => text, 'last' => bool]]. CHIM's own rule: .?! followed by whitespace. */
function lrgNfSentences(string $msg): array
{
    $msg = trim((string) preg_replace('/\s+/', ' ', $msg));
    if ($msg === '') { return []; }
    $parts = preg_split('/(?<=[.!?])(?<!\.\.)\s+/', $msg) ?: [];
    $parts = array_values(array_filter(array_map('trim', $parts), 'strlen'));
    $out = [];
    $n = count($parts);
    foreach ($parts as $i => $p) { $out[] = ['s' => $p, 'last' => $i === $n - 1]; }
    return $out;
}

/** Lowercase, plain apostrophes, narration spans and quotes stripped, one space. */
function lrgNfNorm(string $s): string
{
    $s = str_replace(["\u{2019}", "\u{2018}", '`'], "'", $s);
    $s = (string) preg_replace('/\*[^*]*\*/', ' ', $s);
    $s = str_replace(['"', "\u{201C}", "\u{201D}"], ' ', $s);
    $s = strtolower(trim((string) preg_replace('/\s+/', ' ', $s)));
    return trim($s, " \t'");
}

/** Never a claim: a negation anywhere, a conditional at the start of the sentence or of a clause, a modal of doubt. */
function lrgNfGuarded(string $s): bool
{
    if (preg_match('/\b(?:not|never|no one|nobody|nothing|none|without|until|unless|only after|only once|only when|before that|yet|cannot|neither|nor'
        . '|no (?:oath|rank|orders?|enlistment|word|man|recruit|soldier|legionnaire|promise|place|time|appointment))\b/', $s)) { return true; }
    if (preg_match("/\\b\\w+n't\\b/", $s)) { return true; }
    if (preg_match('/^(?:if|once|when|should|were|unless|supposing|provided|assuming|after|until)\b/', $s)) { return true; }
    if (preg_match('/[,;:] ?(?:if|once|when|should|unless|provided|after|but only|so long as|as long as|only)\b/', $s)) { return true; }
    if (preg_match('/\b(?:would|could|might|perhaps|maybe|someday|one day)\b/', $s)) { return true; }
    return false;
}

/** 'a|b|c' of quoted alternatives, longest first, for a character class of words ('' when none). */
function lrgNfAlt(array $words): string
{
    $w = array_values(array_unique(array_filter(array_map(static fn($x) => strtolower(trim((string) $x)), $words), 'strlen')));
    usort($w, static fn($a, $b) => strlen($b) <=> strlen($a));
    return implode('|', array_map(static fn($x) => preg_quote($x, '/'), $w));
}

/** The claim classes a normalised sentence carries for one row: [class => matched text]. */
function lrgNfClassify(string $s, array $row): array
{
    $classes = array_map('strval', (array) lrgNfCfg('classes', ['member', 'oath', 'next', 'orders']));
    // [pt19-purchase] the PRICE class: a price frame around a number, digits or words ("that'll be one septim"). On the
    // vendor pseudo-row it is the ONLY class (a merchant's "welcome to the ranks" is nobody's enlistment); on a faction
    // row it rides beside the others (a recruiter with stock on the wire is judged on both).
    $price = [];
    if (in_array('price', $classes, true) && function_exists('lrgMktPriceMentions')) {
        $mn = lrgMktPriceMentions($s);
        if ($mn) { $price['price'] = (string) $mn[0]['said']; }
    }
    if ((string) ($row['id'] ?? '') === 'vendor') { return $price; }
    // [pt19 v1.0 / S6.2, gate B] the reward pseudo-row: a GIVING verb + money + a bonus word ("I'll add fifty septims on top")
    if ((string) ($row['id'] ?? '') === 'reward') {
        if (!in_array('reward', $classes, true)) { return []; }
        if (preg_match("/\b(?:i(?:'ll| will| can| shall)? (?:add|give|pay|throw in|hand you|double)|here(?:'s| is)|take (?:these|this|it)|you(?:'ll| will) (?:get|have|receive))\b[^.!?]*\b(?:\d+|septims?|gold|coins?|purse)\b/", $s, $m)
            && preg_match('/\b(?:on top|extra|more|bonus|additional|added|double|besides|as well)\b/', $s)) { return ['reward' => (string) $m[0]]; }
        // [v1.0.1, gate B on - flow d68] a sum she hands over with no giving verb and no bonus word is still a grant: a sum paid
        // "from my own purse" / "out of my pocket", or a sum right after her assent ("Very well - a hundred septims.")
        $sum = '(?:\d[\d,]*|(?:a |an )?(?:one|two|three|four|five|six|seven|eight|nine|ten|fifteen|twenty|thirty|forty|fifty|sixty|seventy|eighty|ninety|hundred|thousand)(?:[ -](?:hundred|thousand|and|one|two|three|four|five|six|seven|eight|nine|ten|twenty|thirty|forty|fifty|sixty|seventy|eighty|ninety))*)';
        if (preg_match('/\b' . $sum . ' (?:more |extra )?(?:septims?|gold|coins?)\b[^.!?]*\b(?:from|out of) (?:my|me) (?:own )?(?:purse|pocket|coffers?|coin|gold|savings|treasury)\b/', $s, $m)
            || preg_match('/\b(?:very well|fine|deal|agreed|all right|alright|done|so be it)\b[\s,;:.\x{2013}\x{2014}-]+' . $sum . ' (?:more |extra )?(?:septims?|gold|coins?)\b/u', $s, $m)) {
            return ['reward' => (string) $m[0]];
        }
        return [];
    }
    $ranks = lrgNfAlt((array) ($row['ranks'] ?? []));
    $facWords = [];
    foreach (array_merge((array) ($row['words'] ?? []), [(string) ($row['name'] ?? '')]) as $w) {
        $w = strtolower(trim((string) $w));
        if ($w === '') { continue; }
        $facWords[] = preg_replace('/^(?:the|a|an) /', '', $w);
    }
    $fac = lrgNfAlt($facWords);
    $names = lrgNfAlt((array) ($row['next_names'] ?? []));
    $pats = [];
    $pats['oath'] = [
        '/^(?:now |so |then |good, |good\. |very well, |very well\. |right, |fine, |then, )?(?:swear|take|say|speak|recite|repeat|give me|make) (?:your |the |an |this |me your |me the |me an |me |a )?(?:oath|vows?)\b/',
        '/\b(?:swear|take|recite|repeat|say|speak|make) (?:your |the |an |this |his |a )?(?:oath|vows?)\b[^.!?]*\b(?:here|now|to me|before me|at once|today|this instant|this moment|this very)\b/',
        '/\b(?:here|now|before me|to me),? (?:swear|take|recite|repeat) (?:your |the |an |this |a )?(?:oath|vows?)\b/',
        '/\brepeat after me\b/', '/\braise your (?:right |sword |left )?(?:hand|arm)\b/',
        "/\\b(?:i|we)(?:'ll| will| shall| am going to| are going to) (?:hear|take|administer|receive|accept) (?:your|the|his|this) (?:oath|vows?)\\b/",
        '/\byour (?:oath|vows?),? (?:now|here|then)[.!]?$/',
    ];
    $memb = [];
    $rankAlt = $ranks !== '' ? $ranks . '|' : '';
    $facAlt = $fac !== '' ? '|in the (?:' . $fac . ')|of the (?:' . $fac . ')|with the (?:' . $fac . ')|part of the (?:' . $fac . ')' : '';
    $memb[] = "/\\b(?:you(?:'re| are)(?: now| hereby| officially| already| henceforth)?|consider yourself|that makes you|this makes you|you(?:'ve| have) (?:just )?(?:become|been made)"
        . '|you (?:will|shall) be|you stand(?: here)?(?: now)?|i (?:name|make|declare|appoint|pronounce|accept) you|we (?:name|make|declare|accept) you)'
        . ' (?:a |an |as |as a |as an |one of |now a |now an |the newest |our newest )?(?:' . $rankAlt . 'one of us|one of mine|one of ours|enlisted|sworn in|sworn|in our ranks' . $facAlt . ')\b/';
    if ($fac !== '') { $memb[] = '/\bwelcome (?:to|aboard|into|in) (?:the )?(?:' . $fac . ')\b/'; }
    if ($ranks !== '') { $memb[] = '/\bwelcome,? (?:' . $ranks . ')\b/'; }
    $memb[] = '/\bwelcome to (?:the )?(?:ranks|service|army|our ranks)\b/';
    $memb[] = '/\b(?:your (?:oath|vows?) (?:is|are|has been|have been|was|were) (?:accepted|taken|heard|received|sworn|witnessed)|i accept (?:your|the|that|this) (?:oath|vows?)|(?:oath|vows?) accepted|so sworn|i hold you to (?:your|that|the) (?:oath|vows?)|the (?:oath|vows?) (?:is|are) (?:taken|sworn|done|made)(?! (?:at|in|before|by|after|with|when|once|where|only|there)\b))\b/';   // [reviewer fix] "the oath is sworn at Castle Dour, before the General" describes the process, it claims nothing
    $memb[] = "/\\byou(?:'ve| have) (?:sworn|taken (?:the |your )?(?:oath|vows?)|enlisted|joined(?: up| us)?|signed (?:on|up))\\b/";
    $memb[] = "/\\byou(?:'re| are) (?:in|one of us|with us)(?: now)?[.!]?$/";
    $pats['member'] = $memb;
    if ($names !== '') {
        $pats['next'] = [
            '/\b(?:report|go|get|head|speak|talk|present yourself|see|answer|proceed|run along|off you go|take yourself|make your way) (?:to|and (?:see|find)|along to|over to|straight to) (?:the )?(?:legate |captain |general |commander |lady |lord )?(?:' . $names . ')\b/',
            '/\b(?:find|seek out|look for) (?:the )?(?:legate |captain |general |commander |lady |lord )?(?:' . $names . ')\b/',
            "/\\b(?:legate |captain |general |commander |lady |lord )?(?:" . $names . ") (?:will|'ll|shall|is going to|is waiting to|can) (?:see|take|test|have|handle|deal with|assign|expect|sort|put) you\\b/",
            "/\\b(?:legate |captain |general |commander |lady |lord )?(?:" . $names . ") (?:is|'s|will be) (?:expecting|waiting for) you\\b/",
        ];
    }
    $pats['orders'] = [
        '/\b(?:report|present yourself|go|get|head|proceed|be|come|muster|assemble|meet(?: me| us)?|wait|train|drill) (?:to|at|for|in|on|by|with) (?:the |your |my |our )?(?:training yard|yard|barracks|drill(?: yard| ground)?|muster|fort\b|camp|garrison|quartermaster|duty|post|parade ground|armou?ry)\b/',
        '/\bfor your (?:first |new |next |initial )?(?:orders|posting|assignment|duties|drill|training|commission|kit|uniform|armou?r)\b/',
        '/\byour (?:first |new |next )?(?:orders|posting|assignment|duties) (?:are|is|will be|come|await)\b/',
        "/\\breport for duty\\b/", "/\\byou (?:have|'ve got|have got) (?:your )?orders\\b/",
        '/\b(?:your|the|his) (?:training|test|trial|drill|service|enlistment|commission|posting|induction|quest|first assignment) (?:begins|starts|has begun|has started|is set|is arranged|will begin|begins now|starts now|starts today|begins tomorrow)\b/',
        '/\b(?:the quest|it|this|training|service|your service) (?:begins|has begun|starts|started) (?:now|today|here|tomorrow|at dawn|at first light|tonight)\b/',
        '/\byou (?:start|begin|report|drill|train|muster) (?:at|in|by|from) (?:dawn|first light|sunrise|noon|midnight|tomorrow|tonight|the morning|the next bell|the bell)\b/',
        '/\b(?:clear|clear out|take|hold|retake|sweep) (?:the |a |that |those |out the )?(?:fort|bandits?|camp|ruins?)\b/',
    ];
    $timeWords = '/\b(?:at|by|before|after|come|when) (?:dawn|first light|sunrise|sundown|sunset|nightfall|noon|midday|midnight|dusk|the bell(?: rings| tolls| sounds)?|the (?:next |morning |evening )?bell|tomorrow(?: morning| night)?|tonight|the morning|the hour)\b/';
    $timeVerbs = '/\b(?:report|be there|be here|meet|drill|training|test|muster|present|come back|return|start|begin|begins|assemble|ready|march|see you)\b/';
    $out = [];
    foreach ($classes as $cls) {
        foreach ((array) ($pats[$cls] ?? []) as $re) {
            if (preg_match($re, $s, $m, PREG_OFFSET_CAPTURE)) {
                // [reviewer fix] orders only when ADDRESSED to the player (lrgNfAddressed): "we drill at dawn", "the soldiers
                // drill in the yard", "we hold the fort" are a soldier's own routine, not an appointment given to him
                if ($cls === 'orders' && !lrgNfAddressed($s, (int) $m[0][1])) { continue; }
                $out[$cls] = (string) $m[0][0];
                continue 2;
            }
        }
        if ($cls === 'orders' && preg_match($timeWords, $s, $m) && preg_match($timeVerbs, $s, $mv, PREG_OFFSET_CAPTURE)
            && lrgNfAddressed($s, (int) $mv[0][1])) { $out['orders'] = (string) $m[0]; }
    }
    return $out + $price;   // [pt19-purchase] a price quoted on a faction turn with stock on the wire is judged too
}

/**
 * [reviewer fix] Is the phrase at byte $off of the normalised sentence addressed to the player: the sentence says you /
 * your, or the phrase is an imperative at the start of the sentence (after an interjection at most)? A third-person
 * description of the unit's own routine ("we march at dawn", "the men muster by noon") is never an order to him.
 */
function lrgNfAddressed(string $s, int $off): bool
{
    if (preg_match('/\b(?:you|your|yourself)\b/', $s)) { return true; }
    $lead = substr($s, 0, max(0, $off));
    return (bool) preg_match('/^(?:(?:now|then|so|good|fine|right|very well|first|next|and|just|go|come|recruit|soldier|listen)[,.!]?\s*)*$/', $lead);
}

/** The verdict of one class on one row for this NPC's role: ['verdict' => true|false|unknown|exempt, 'fact' => ..]. */
function lrgNfVerdict(string $cls, string $s, array $ctx, array $row, string $role): array
{
    // [pt19-purchase] a price: true when every number she quoted is on the list (the stock, the order's total, a live entry,
    // the room, the bounty, the purse), false otherwise - unknown when the turn carries no price fact to judge against
    if ($cls === 'price') {
        if (!function_exists('lrgMktPriceVerdict') || !function_exists('lrgMktRailFacts') || !lrgMktRailFacts((array) $ctx['t'])) {
            return ['verdict' => 'unknown', 'fact' => 'no price fact on this turn'];
        }
        $pv = lrgMktPriceVerdict($s, (array) $ctx['t']);
        if ($pv === null) { return ['verdict' => 'unknown', 'fact' => 'no price quoted']; }
        return ['verdict' => (string) $pv['verdict'], 'fact' => (string) $pv['fact'], 'mkt' => (array) ($pv['mkt'] ?? [])];
    }
    // [pt19 v1.0 / S6.2, gate B] a bonus claim is true only when this turn's reward check PASSED and granted it, and a number she
    // names is that bonus
    if ($cls === 'reward') {
        $c = (array) ((((array) $ctx['t'])['check'] ?? null) ?: []);
        $give = (!empty($c['reward']) && (string) ($c['result'] ?? '') === 'pass' && !empty($c['award'])) ? (int) ($c['give'] ?? 0) : 0;
        // [pt19h-money] her figure through the one sum reader: "I'll add fifty septims on top" is 50 (the digits-only read gave 0,
        // which passed ANY spelled-out bonus as true), "1,000 septims" is 1000 (it read 1); a bare digit group is the fallback
        $said = function_exists('lrgIntentAmount') ? (int) lrgIntentAmount(strtolower($s), false) : 0;
        if ($said === 0 && preg_match('/\b(\d{1,3}(?:,\d{3})+|\d+)\b/', $s, $m)) { $said = (int) str_replace(',', '', $m[1]); }
        $ok = $give > 0 && ($said === 0 || $said === $give);
        return ['verdict' => $ok ? 'true' : 'false', 'fact' => $give > 0 ? 'the bonus is ' . $give . ' septims' : 'no bonus was granted'];
    }
    if ($cls === 'next' && $role !== 'recruiter') { return ['verdict' => 'exempt', 'fact' => $role . ' names the recruiter, his own real line']; }
    if ($cls === 'orders') {
        foreach ((array) ($row['allow'] ?? []) as $pat => $need) {
            if ((string) $pat !== '' && is_array($need) && preg_match('/\b(?:' . (string) $pat . ')\b/i', $s)) {
                $r = lrgNfMeets($need, $ctx['stages'], $row);
                if ($r['verdict'] !== 'false') { return $r; }
                return ['verdict' => 'false', 'fact' => $r['fact']];
            }
        }
        return ['verdict' => 'false', 'fact' => 'no stage records an appointment, a place or a time'];
    }
    if ($cls === 'oath' && $role !== 'recruiter') { return ['verdict' => 'false', 'fact' => $role . ' administers no oath']; }
    $need = (array) (($row['truth'] ?? [])[$cls] ?? []);
    if (!$need) { return ['verdict' => 'unknown', 'fact' => 'no truth map for ' . (string) $row['id'] . '.' . $cls]; }
    return lrgNfMeets($need, $ctx['stages'], $row);
}

/**
 * Judge ONE sentence: null when it carries no claim; else ['class', 'hit', 'row', 'role', 'said', 'verdict', 'fact'] -
 * the first FALSE class when there is one, else the best of the rest (true > unknown > exempt) for the log.
 */
function lrgNfJudgeSentence(string $raw, array $ctx): ?array
{
    $s = lrgNfNorm($raw);
    if ($s === '' || str_ends_with($s, '?') || lrgNfGuarded($s)) { return null; }
    $best = null;
    $rank = ['true' => 3, 'unknown' => 2, 'exempt' => 1];
    foreach ($ctx['rows'] as $id => $role) {
        $row = lrgNfRow((string) $id);
        foreach (lrgNfClassify($s, $row) as $cls => $hit) {
            $v = lrgNfVerdict((string) $cls, $s, $ctx, $row, (string) $role);
            $rec = ['class' => (string) $cls, 'hit' => (string) $hit, 'row' => (string) $id, 'role' => (string) $role, 'said' => trim($raw)] + $v;
            if ($v['verdict'] === 'false') { return $rec; }
            if ($best === null || ($rank[$v['verdict']] ?? 0) > ($rank[$best['verdict']] ?? 0)) { $best = $rec; }
        }
    }
    return $best;
}

/**
 * The SHAPE of this reply decides the layer (decided once per request): `reject` (the validator stops the stream, the
 * retry re-asks or floors), `mute` (the transformer drops the sentence, the hook floors), `log`. A reply carrying a
 * dialogue pick, or whose action is not known yet, is never rejected (the pick would die with the words); a turn
 * functions are off for (a rechat) has no post-process hook, so only the retry seam can floor it.
 */
function lrgNfShape(array $ctx): string
{
    if (isset($GLOBALS['LRG_NF_SHAPE'])) { return (string) $GLOBALS['LRG_NF_SHAPE']; }
    $mode = strtolower(trim((string) lrgNfCfg('mode', 'reask')));
    if ($mode === 'log') { return 'log'; }   // not sticky: the owner may flip it between requests, and nothing else depends on it
    $shape = 'reject';
    if (!empty($GLOBALS['FUNCTIONS_ARE_ENABLED']) && isset($GLOBALS['TRANSFORMER_FUNCTION']) && isset($GLOBALS['LRG_NF_TRANSFORMER'])) {
        $r = (array) ($GLOBALS['LAST_LLM_RESPONSE'] ?? []);
        if (!array_key_exists('action', $r)) { $shape = 'mute'; }
        else {
            $n = str_replace(['_', ' '], '', strtolower(trim((string) $r['action'])));
            if (in_array($n, LRG_DLG_GATE_NAMES, true) || $mode === 'mute') { $shape = 'mute'; }
        }
    }
    $GLOBALS['LRG_NF_SHAPE'] = $shape;
    return $shape;
}

function lrgNfLog(array $ctx, array $j, string $verdict): void
{
    $t = $ctx['t'];
    $npc = $ctx['npc'];
    $ml = function_exists('lrgDlgMcm') ? lrgDlgMcm('ml', 1, $npc) : 1;
    $cal = function_exists('lrgDlgMcm') ? lrgDlgMcm('cal', -1, $npc) : -1;
    lrgLog(sprintf('never-false npc=%s role=%s class=%s said="%s" fact="%s" verdict=%s ml=%d cal=%s ambient=%d open=%d type=%s',
        $npc, (string) $j['role'], (string) $j['class'], substr(str_replace('"', "'", (string) $j['said']), 0, 90), (string) $j['fact'], $verdict,
        (int) $ml, (is_int($cal) && $cal >= 0) ? (string) $cal : '-', (int) ($t['ambient'] ?? 0), (int) ($t['open'] ?? 0), (string) $ctx['type']), $ctx['cid']);
}

// ---------------------------------------------------------------- layer 1: the validator (per chunk, shape reject)
/** false = reject the stream here (LRG_NE_REJECT carries why=false claim); true = nothing to do (yet). */
function lrgNfValidate(): bool
{
    if (!empty($GLOBALS['LRG_NF_SAYING'])) { return true; }
    $ctx = lrgNfContext();
    if (!$ctx) { return true; }
    $r = $GLOBALS['LAST_LLM_RESPONSE'] ?? null;
    if (!is_array($r) || !array_key_exists('message', $r)) { return true; }
    $msg = $r['message'];
    if (is_array($msg)) { $msg = implode(',', array_map('strval', $msg)); }
    $msg = (string) $msg;
    if (trim($msg) === '') { return true; }
    $shape = lrgNfShape($ctx);
    if ($shape === 'mute') { return true; }   // the transformer judges each sentence as CHIM hands it to TTS
    $complete = lrgNeComplete($r);
    // [reviewer fix] CHIM's own splitter (findFastSentencePosition, chat_helper_functions.php:377) takes a sentence as soon
    // as its .?! is followed by whitespace - a trailing space or newline in the partial message already ends the tail
    // (a delta of "\n" alone does that), so it has to be judged in THIS chunk or returnLines takes it first
    $tailDone = (bool) preg_match('/[.!?](?<!\.\.)\s+$/', $msg);
    foreach (lrgNfSentences($msg) as $p) {
        if ($p['last'] && !$complete && !$tailDone) { break; }   // the tail is still being written (or message came last: the hook)
        $key = md5(lrgNfNorm((string) $p['s']));
        if (isset($GLOBALS['LRG_NF_SEEN'][$key])) { continue; }
        $GLOBALS['LRG_NF_SEEN'][$key] = 1;
        $j = lrgNfJudgeSentence((string) $p['s'], $ctx);
        if (!$j) { continue; }
        if ($j['verdict'] !== 'false') { lrgNfLog($ctx, $j, $j['verdict']); continue; }
        if ($shape === 'log') { lrgNfLog($ctx, $j, 'log-only'); lrgNfRemember($ctx['npc'], $ctx['cid'], 'log-only', $j, '', -1, 'log'); continue; }
        lrgNfLog($ctx, $j, 'rejected');
        $GLOBALS['LRG_NE_REJECT'] = ['why' => 'false claim', 'action' => (string) ($r['action'] ?? ''), 'item' => (string) ($r['item'] ?? ''),
            'class' => $j['class'], 'said' => (string) $p['s'], 'fact' => $j['fact'], 'row' => $j['row'], 'role' => $j['role'], 'npc' => $ctx['npc'], 'at' => lrgNow(),
            'mkt' => (array) ($j['mkt'] ?? [])];   // [pt19-purchase] the list's own item / price for the nudge and the floor line
        return false;
    }
    return true;
}

// ---------------------------------------------------------------- layer 1b: the transformer (per sentence, shape mute)
function lrgNfTransformer(string $s): string
{
    $prev = $GLOBALS['LRG_NF_PREV_TRANSFORMER'] ?? null;
    if (is_callable($prev)) { $s = (string) $prev($s); }
    if (strlen($s) < 2 || !empty($GLOBALS['LRG_NF_SAYING'])) { return $s; }
    $ctx = lrgNfContext();
    if (!$ctx || lrgNfShape($ctx) !== 'mute') { return $s; }
    $key = md5(lrgNfNorm($s));
    if (isset($GLOBALS['LRG_NF_SEEN'][$key])) { return isset($GLOBALS['LRG_NF_DROPPED'][$key]) ? '' : $s; }
    $GLOBALS['LRG_NF_SEEN'][$key] = 1;
    $j = lrgNfJudgeSentence($s, $ctx);
    if (!$j) { return $s; }
    if ($j['verdict'] !== 'false') { lrgNfLog($ctx, $j, $j['verdict']); return $s; }
    lrgNfLog($ctx, $j, 'muted');
    $GLOBALS['LRG_NF_DROPPED'][$key] = 1;
    $GLOBALS['LRG_NF_MUTED'][] = $j;
    return '';
}

// ---------------------------------------------------------------- layer 2: the retry (a false claim was rejected)
function lrgNfRetry(array $k, array $rej): void
{
    $prev = $GLOBALS['LRG_NE_PREV_RETRY'] ?? null;
    $npc = (string) ($rej['npc'] ?? ($k['npc'] ?? ($GLOBALS['HERIKA_NAME'] ?? '')));
    $kf = ['kind' => 'false', 'npc' => $npc, 'type' => (string) ($k['type'] ?? ''), 'scene' => false, 'why' => '',
        'role' => (string) ($rej['role'] ?? 'none'), 'row' => (string) ($rej['row'] ?? ''), 'class' => (string) ($rej['class'] ?? '')];
    $act = (string) ($rej['action'] ?? '');
    $cid = lrgNeCid();
    $mode = strtolower(trim((string) lrgNfCfg('mode', 'reask')));
    $why = 'false claim';
    if ($mode === 'reask' && empty($GLOBALS['LRG_NF_REASKED'])) {
        $GLOBALS['LRG_NF_REASKED'] = 1;
        unset($GLOBALS['LRG_NE_REJECT'], $GLOBALS['LRG_NF_SHAPE']);
        $GLOBALS['LRG_NF_SEEN'] = [];
        $before = strlen(lrgSpokenThisTurn());
        $ok = lrgNeReask(['npc' => $npc] + $k, lrgNfNudge($npc, $rej));
        $after = strlen(lrgSpokenThisTurn());
        if ($ok && $after > $before) {
            lrgLog(sprintf('never-false npc=%s role=%s class=%s why=false claim reask=spoke chars=%d', $npc, $kf['role'], $kf['class'], $after - $before), $cid);
            lrgNfRemember($npc, $cid, 'false claim', $rej, '', -1, 'reask');
            return;
        }
        $rej2 = $GLOBALS['LRG_NE_REJECT'] ?? null;
        if (is_array($rej2)) {
            $why = ((string) ($rej2['why'] ?? '') === 'false claim') ? 'false claim twice' : 'false claim, then ' . (string) ($rej2['why'] ?? '');
            $act = (string) ($rej2['action'] ?? $act);
            if ((string) ($rej2['why'] ?? '') === 'false claim') { $kf['class'] = (string) ($rej2['class'] ?? $kf['class']); }
        } else {
            $why = 'false claim, the re-ask failed';
        }
    } elseif ($mode === 'reask') {
        $why = 'false claim twice';
    }
    if (is_callable($prev)) { $prev(); }
    lrgNfFloor($kf, $why, $act, $rej);
}

/**
 * The rule appended to the last user message for the second call. It quotes the model's OWN sentence (state, not an
 * authored line), says that the GAME decides what has happened - CHIM core's roleplay instructions say the opposite -
 * and carries what she already said so the second reply continues instead of repeating. Single quotes only.
 */
function lrgNfNudge(string $npc, array $rej): string
{
    $player = trim((string) ($GLOBALS['PLAYER_NAME'] ?? ''));
    if ($player === '' || strcasecmp($player, 'player') === 0) { $player = 'the player'; }
    $said = substr(trim((string) ($rej['said'] ?? '')), 0, 160);
    $row = lrgNfRow((string) ($rej['row'] ?? ''));
    $how = (string) ($row['how'] ?? '');
    $ranks = implode(', ', array_slice(array_map('strval', (array) ($row['ranks'] ?? [])), 0, 4));
    // [pt19-purchase] a price she invented: her sentence, quoted, and the ONLY prices she may name (the list's own)
    if ((string) ($rej['class'] ?? '') === 'price') {
        $t = $GLOBALS['LRG_DLG_TURN'] ?? [];
        $allowed = function_exists('lrgMktAllowedPrices') && is_array($t) ? lrgMktAllowedPrices($t) : [];
        $mk = (array) ($rej['mkt'] ?? []);
        $s = "(Rule for this reply: the sentence '" . $said . "' names a price the game has not set - it is false. The game, not the story,"
            . ' sets every price here' . (trim((string) ($mk['item'] ?? '')) !== '' && (int) ($mk['price'] ?? 0) > 0 ? ': ' . (string) $mk['item'] . ' is ' . (int) $mk['price'] . ' septims' : '')
            . ($allowed ? ', and the only figures ' . $npc . ' may name are ' . implode(', ', array_slice($allowed, 0, 8)) . ' septims' : '')
            . '. ' . $npc . ' names that price and no other number, and never a different one.';
        $spoken = trim(lrgSpokenThisTurn());
        if ($spoken !== '') { $s .= ' ' . $npc . " already said: '" . substr($spoken, 0, 200) . "' - continue from it without repeating it."; }
        $s .= ' Answer in words, in ' . $npc . "'s own voice.)";
        return str_replace(['"', '<', '>'], ["'", '', ''], $s);
    }
    $s = "(Rule for this reply: the sentence '" . $said . "' is false. The game, not the story, decides what has happened, and it has"
        . ' recorded no enlistment, no oath, no rank and no orders for ' . $player . " - his words do not make it so; nothing has begun. "
        . $npc . ' may want him and may say how enlistment really goes' . ($how !== '' ? ' (' . $how . ')' : '')
        . ', but may not swear him in, give him a rank' . ($ranks !== '' ? ' (' . $ranks . ')' : '')
        . ', accept an oath, give orders, or name where or when to report.';
    $spoken = trim(lrgSpokenThisTurn());
    if ($spoken !== '') { $s .= ' ' . $npc . " already said: '" . substr($spoken, 0, 200) . "' - continue from it without repeating it."; }
    $s .= ' Answer in words, in ' . $npc . "'s own voice.)";
    return str_replace(['"', '<', '>'], ["'", '', ''], $s);
}

// ---------------------------------------------------------------- layer 3: the hook (shape mute: the floor; message-last: late)
function lrgNfHook(array $actions): array
{
    $ctx = lrgNfContext();
    if (!$ctx) { return $actions; }
    $r = (array) ($GLOBALS['LAST_LLM_RESPONSE'] ?? []);
    $shape = lrgNfShape($ctx);
    // the message-LAST key order: the validator could not judge the tail in time - judge the final message now
    if ($shape !== 'mute' && array_key_exists('message', $r)) {
        $keys = array_keys($r);
        $msg = is_array($r['message']) ? implode(',', array_map('strval', $r['message'])) : (string) $r['message'];
        if ((string) end($keys) === 'message' && trim($msg) !== '') {
            foreach (lrgNfSentences($msg) as $p) {
                $key = md5(lrgNfNorm((string) $p['s']));
                if (isset($GLOBALS['LRG_NF_SEEN'][$key])) { continue; }
                $GLOBALS['LRG_NF_SEEN'][$key] = 1;
                $j = lrgNfJudgeSentence((string) $p['s'], $ctx);
                if (!$j) { continue; }
                if ($j['verdict'] !== 'false') { lrgNfLog($ctx, $j, $j['verdict']); continue; }
                lrgNfLog($ctx, $j, $shape === 'log' ? 'log-only' : 'late');
                if ($shape === 'log') { lrgNfRemember($ctx['npc'], $ctx['cid'], 'log-only', $j, '', -1, 'log'); continue; }
                $GLOBALS['LRG_NF_LATE'][] = $j;
            }
        }
    }
    $found = array_merge((array) ($GLOBALS['LRG_NF_MUTED'] ?? []), (array) ($GLOBALS['LRG_NF_LATE'] ?? []));
    if (!$found || !empty($GLOBALS['LRG_NE_DONE'])) { return $actions; }
    $j = $found[0];
    $act = (string) ($r['action'] ?? '');
    $item = (string) ($r['item'] ?? '');
    if (lrgNeWillEmitPick($act, $item) || lrgNeLinesCarryPick($actions)) {
        lrgLog(sprintf('never-false npc=%s role=%s class=%s verdict=idle (a business pick the game answers itself; the sentence was dropped)',
            $ctx['npc'], (string) $j['role'], (string) $j['class']), $ctx['cid']);
        lrgNfRemember($ctx['npc'], $ctx['cid'], 'false claim', $j, '', -1, 'pick');
        return $actions;
    }
    $kf = ['kind' => 'false', 'npc' => $ctx['npc'], 'type' => $ctx['type'], 'scene' => false, 'why' => '',
        'role' => (string) $j['role'], 'row' => (string) $j['row'], 'class' => (string) $j['class']];
    lrgNfFloor($kf, !empty($GLOBALS['LRG_NF_LATE']) && empty($GLOBALS['LRG_NF_MUTED']) ? 'false claim (late)' : 'false claim (muted)', $act, $j);
    return $actions;
}

// ---------------------------------------------------------------- the floor line: after any truthful partial
function lrgNfFloor(array $k, string $why, string $act, array $j): void
{
    if (!empty($GLOBALS['LRG_NE_DONE'])) { return; }
    $GLOBALS['LRG_NE_DONE'] = 1;
    $npc = (string) $k['npc'];
    $cid = lrgNeCid();
    $code = lrgShortCode($act === '' ? 'none' : $act);
    $role = (string) ($k['role'] ?? 'none');
    [$line, $idx] = lrgNfPick($npc, $role, (string) ($k['row'] ?? ''));
    // [pt19-purchase] the vendor line names the list's own item and price ({item} / {price} from lrgMktPriceVerdict)
    $mk = (array) ($j['mkt'] ?? []);
    if ($line !== '' && (str_contains($line, '{item}') || str_contains($line, '{price}') || str_contains($line, '{npc}'))) {
        $line = str_replace(['{npc}', '{item}', '{price}'], [$npc, (string) ($mk['item'] ?? 'that'), (string) ((int) ($mk['price'] ?? 0))], $line);
        if ((int) ($mk['price'] ?? 0) <= 0) { $line = 'I name no price the game has not set.'; }
    }
    if ($line === '') {
        lrgLog(sprintf('never-false npc=%s role=%s action=%s why=%s said=(no %s line configured)', $npc, $role, $code, $why, $role), $cid);
        return;
    }
    $before = lrgSpokenThisTurn();
    $GLOBALS['LRG_NF_SAYING'] = 1;   // the floor line is config text: never judged, never muted by this rail
    try { lrgNeSay($line); } finally { unset($GLOBALS['LRG_NF_SAYING']); }
    if (strlen(lrgSpokenThisTurn()) <= strlen($before)) {
        lrgLog(sprintf('never-false npc=%s role=%s action=%s why=%s said="%s" BUT it was not spoken (dropped by a transformer)', $npc, $role, $code, $why, $line), $cid);
        return;
    }
    lrgLog(sprintf('never-false npc=%s role=%s action=%s why=%s said="%s"%s', $npc, $role, $code, $why, $line,
        $before !== '' ? ' after=' . strlen($before) . ' chars she had already spoken' : ''), $cid);
    lrgNfRemember($npc, $cid, $why, $j, $line, $idx, 'floor');
}

/** [line, index] from never_false.lines.<role> (a per-row `floor` cell wins), rotated per NPC. */
function lrgNfPick(string $npc, string $role, string $rowId): array
{
    $lines = [];
    if ($rowId !== '') {
        $floor = lrgNfRow($rowId)['floor'] ?? '';
        foreach ((array) $floor as $l) { if (trim((string) $l) !== '') { $lines[] = (string) $l; } }
    }
    if (!$lines) {
        $lines = (array) lrgNfCfg('lines.' . (in_array($role, ['recruiter', 'redirect', 'vendor', 'reward'], true) ? $role : 'none'), []);   // [pt19-purchase] + vendor, [pt19 v1.0] + reward
        $lines = array_values(array_filter(array_map('strval', $lines), static fn($l) => trim($l) !== ''));
    }
    if (!$lines) { return ['', -1]; }
    $max = max(12, (int) lrgNfCfg('max_chars', 100));
    $e = (array) (lrgMemGet($npc)['false_claim'] ?? []);
    $n = (int) ($e['n'] ?? 0);
    $i = $n % count($lines);
    if (count($lines) > 1 && (string) ($e['line'] ?? '') === $lines[$i]) { $i = ($i + 1) % count($lines); }
    return [substr(trim($lines[$i]), 0, $max), $i];
}

/** lrg_memory.false_claim {at, cid, n, why, class, said, fact, line, i, kind, told}: the count, the rotation, the note. */
function lrgNfRemember(string $npc, string $cid, string $why, array $j, string $line, int $idx, string $kind): void
{
    if ($npc === '') { return; }
    $e = (array) (lrgMemGet($npc)['false_claim'] ?? []);
    lrgMemSet($npc, ['false_claim' => ['at' => lrgNow(), 'cid' => $cid, 'n' => (int) ($e['n'] ?? 0) + 1, 'why' => $why,
        'class' => (string) ($j['class'] ?? ''), 'said' => substr((string) ($j['said'] ?? ''), 0, 80), 'fact' => (string) ($j['fact'] ?? ''),
        'line' => $line, 'i' => $idx, 'kind' => $kind, 'told' => false]]);
}

/** Next turn (rules only): one sentence while false_claim.at is within never_false.note_seconds, told once. */
function lrgNfNote(string $npc): string
{
    if ($npc === '' || empty(lrgNfCfg('enabled', true))) { return ''; }
    $type = strtolower((string) ($GLOBALS['gameRequest'][0] ?? ''));
    if (!in_array($type, LRG_PLAYER_SPEECH_TYPES, true) && $type !== 'lrg_dlgtalk') { return ''; }
    $e = (array) (lrgMemGet($npc)['false_claim'] ?? []);
    if (!$e || !empty($e['told'])) { return ''; }
    if (lrgNow() - (int) ($e['at'] ?? 0) > max(10, (int) lrgNfCfg('note_seconds', 120))) { return ''; }
    lrgMemSet($npc, ['false_claim' => ['told' => true] + $e]);
    $what = ['member' => 'an enlistment or a rank', 'oath' => 'an oath', 'next' => 'a next step', 'orders' => 'orders, a place or a time'][(string) ($e['class'] ?? '')] ?? 'a quest step';
    $spoken = (string) ($e['kind'] ?? '') === 'log' ? '' : '; that sentence was not spoken';
    // [pt19-purchase] a price the game had not set: the rule for the next turn names the list, not enlistment
    if ((string) ($e['class'] ?? '') === 'price') {
        return $npc . "'s last reply claimed a price the game had not set" . $spoken . '. ' . $npc
            . ' names only the prices on ' . $npc . "'s own list (" . (string) ($e['fact'] ?? 'the listed price') . '), never another figure, and answers in words.';
    }
    return $npc . "'s last reply claimed " . $what . ' the game had not recorded' . $spoken . '. Nothing has begun: ' . $npc
        . ' claims no enlistment, oath, rank, orders or next step the game has not recorded, and answers in words.';
}
