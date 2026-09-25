<?php
/**
 * LoreRim Glue - flow-test adapter for PHASE 2 (menuless questing). The Phase 1 harness (harness.php) and
 * the Phase 1 adapter (adapter.php) are used UNCHANGED; everything Phase 2 specific lives here.
 *
 * Scenario ids are d20..d41, not the design's 20..41: Phase 1 already owns 20_lead_hold.php ...
 * 26_diagnostics.php and the design's numbering would have collided with six live scenarios.
 * 'd' sorts after '9', so the Phase 2 block runs last in one run.
 *
 * Seams this adapter sets (so no flow run ever touches a real table or a real process):
 *   $GLOBALS['LRG_TEST_INDEX']         the prompt index, in memory (lib/lrg_prompt_index.php)
 *   $GLOBALS['LRG_DLG_TEST_QUEUE']     the D2 responselog insert, captured instead of written
 *   $GLOBALS['LRG_DLG_TEST_QUESTLOG']  CHIM's questlog rows, in memory
 *   $GLOBALS['LRG_DLG_STATE']          the per-request state cache, cleared between scenarios
 */

require_once __DIR__ . '/harness.php';
require_once __DIR__ . '/adapter.php';

const FX_DLG_ACT = 'ExtCmdLRG_SelectTopic';   // the CODE name - what the wire line really carries
const FX_DLG_NAME = 'TakeUpBusiness';         // the name written into the catalog row and the prompts
const FX_DLG_DISPLAY = 'Take_Up_Business';    // what CHIM stores and the strict JSON enum offers back

/**
 * Load Phase 2's library. run_flows.php requires the scenario files BEFORE fxLoadPlugin(), so every helper
 * that touches a plugin function has to do this itself - a top-level require here would run with an empty
 * Fx::$pluginDir.
 */
function fxDlgLoad(): void
{
    if (function_exists('lrgDlgPrepareTurn')) { return; }
    require_once fxPluginDir() . '/lib/lrg_dialogue.php';
}

/** Phase 2 capabilities, probed lazily: a scenario that needs an undelivered function reports PENDING. */
function fxDlgCaps(): void
{
    static $done = false;
    if ($done) { return; }
    $done = true;
    $need = [
        'dlg_wire' => ['lrgDlgHandleGameMessage', 'lrgDlgGet', 'lrgDlgSet'],
        'dlg_turn' => ['lrgDlgPrepareTurn', 'lrgDlgStaticGuidance', 'lrgDlgVolatileGuidance'],
        'dlg_gate' => ['lrgDlgPostProcessActions', 'lrgDlgEnsureActions'],
        'dlg_json' => ['lrgDlgJsonTemplate', 'lrgDlgTransformer', 'lrgDlgFilterChat'],
        'dlg_index' => ['lrgPromptLookup', 'lrgPromptLayerKind', 'lrgPromptIndexStatus', 'lrgPromptKinds', 'lrgPromptNorm'],
        'dlg_checks' => ['lrgDlgCheck', 'lrgDlgCheckDirective'],
        'dlg_quests' => ['lrgDlgQuestBlock'],
        // "everyone has a price" has exactly ONE implementation, and it is Phase 1's: lrgPriceFor()
        // (lib/lrg_core.php). The Phase 2 twin lrgDlgPrice() was deleted in the 0.4.0 fix pass - a
        // capability pointed at a function no production code called proved nothing about shipping.
        'dlg_price' => ['lrgPriceFor', 'lrgDlgWage'],
        'dlg_queue' => ['lrgDlgQueue'],
    ];
    foreach ($need as $cap => $fns) {
        $missing = array_values(array_filter($fns, static fn($f) => !function_exists($f)));
        Fx::$caps[$cap] = !$missing;
        Fx::$capWhy[$cap] = $missing ? implode('(), ', $missing) . '() missing' : '';
    }
    Fx::$caps['dlg_actions_v1'] = defined('LRG_DLG_ACTIONS_VERSION') && (int) LRG_DLG_ACTIONS_VERSION >= 1;
    Fx::$capWhy['dlg_actions_v1'] = Fx::$caps['dlg_actions_v1'] ? '' : 'LRG_DLG_ACTIONS_VERSION is undefined';
}

/** Fresh Phase 2 world. Call it FIRST in every d* scenario (fxReset() has already run). */
function fxDlgReset(array $index = [], array $layers = []): void
{
    fxDlgLoad();
    fxDlgCaps();
    unset($GLOBALS['LRG_DLG_TURN'], $GLOBALS['LRG_DLG_TALK'], $GLOBALS['LRG_DLG_STATE'], $GLOBALS['LRG_DLG_MCM'],
        $GLOBALS['LRG_DLG_POSTGATE'], $GLOBALS['LRG_DLG_JSONHOOK'], $GLOBALS['LRG_DLG_TRANSFORMER'],
        $GLOBALS['LRG_DLG_PREV_TRANSFORMER'], $GLOBALS['TRANSFORMER_FUNCTION'], $GLOBALS['LAST_LLM_RESPONSE'],
        $GLOBALS['structuredOutputTemplate'], $GLOBALS['responseTemplate'], $GLOBALS['HOOKS'],
        // [0.5.0] the per-REQUEST caches and the v0.5 policy scratchpads. In production each of these
        // lives for one request; in a flow run one process plays many, so they are cleared here too.
        $GLOBALS['LRG_DLG_MCM_SNAP'], $GLOBALS['LRG_DLG_SVC_HIDDEN'], $GLOBALS['LRG_DLG_HOLD_HIDDEN'],
        $GLOBALS['LRG_DLG_HOLD_MOVE'], $GLOBALS['LRG_DLG_TEST_SERVICE_CATALOG'],
        $GLOBALS['LRG_PROMPT_SCHEMA_ACTIVE']);
    $GLOBALS['LRG_TEST_INDEX'] = ['rows' => $index, 'layers' => $layers];
    $GLOBALS['LRG_DLG_TEST_QUEUE'] = [];
    $GLOBALS['LRG_DLG_TEST_QUESTLOG'] = [];
    $GLOBALS['LRG_DLG_TEST_QSIG'] = 1;
    // the action row is reinstalled per scenario, so its shape can be asserted
    @unlink(LRG_DIR . '/data/.dlg_actions_v' . (defined('LRG_DLG_ACTIONS_VERSION') ? LRG_DLG_ACTIONS_VERSION : 1));
    @touch(LRG_DIR . '/data/.dlg_schema_v' . (defined('LRG_DLG_SCHEMA_VERSION') ? LRG_DLG_SCHEMA_VERSION : 1));
}

/** One fixture index row. Everything the real builder emits, with sane defaults. */
function fxDlgRow(string $text, array $over = []): array
{
    fxDlgLoad();
    $r = ['norm' => lrgPromptNorm($text), 'pattern' => '', 'txt' => $text, 'topic_key' => 'fx:' . substr(md5($text), 0, 6),
        'info_key' => 'fxi:' . substr(md5($text), 0, 6), 'topic' => 'FxTopic', 'quest' => '', 'journal' => 0,
        'toplevel' => 1, 'kind' => '', 'variant' => 'na',
        'flags' => ['goodbye' => 0, 'sayonce' => 0, 'walkaway' => 0, 'invis' => 0, 'random' => 0, 'favor' => 0, 'placeholder' => 0],
        'scripted' => 0, 'compound' => 0, 'amulet' => 0, 'twat' => '', 'crit' => 0, 'cost' => 0, 'dyn' => 0,
        'links' => [], 'resp' => 'Very well.', 'shared' => 1, 'subtype' => 'CUST', 'plugin' => 'Fx.esp'];
    foreach ($over as $k => $v) {
        if ($k === 'flags' && is_array($v)) { $r['flags'] = $v + $r['flags']; } else { $r[$k] = $v; }
    }
    if (!isset($over['norm'])) { $r['norm'] = lrgPromptNorm((string) $r['txt']); }
    return $r;
}

/** An index fixture from [text => overrides]. */
function fxDlgIndexFrom(array $spec): array
{
    $rows = [];
    foreach ($spec as $text => $over) {
        if (is_int($text)) { $text = (string) $over; $over = []; }
        $rows[] = fxDlgRow((string) $text, (array) $over);
    }
    return $rows;
}

/** One e= chunk: '<pos>~<topicIndex>~<new>~<col>~<text>'. The game replaces | with / and ~ with -. */
function fxDlgEntry(int $pos, string $text, array $o = []): string
{
    $text = str_replace(['|', '~'], ['/', '-'], $text);
    return $pos . '~' . (int) ($o['i'] ?? (100 + $pos)) . '~' . (int) ($o['new'] ?? 0) . '~'
        . (isset($o['col']) ? (int) $o['col'] : '-') . '~' . $text;
}

/**
 * lrg_topics, exactly as the game builds it: fixed key order, e= LAST and raw. Returns
 * ['res','echo','queue','state'] - 'echo' is what the D1 route wrote into the HTTP reply.
 */
function fxDlgTopics(string $npc, array $kv, array $entries): array
{
    $base = ['v' => 1, 'sid' => 's1', 'gen' => 0, 'layer' => 0, 'origin' => 'glue', 'ref' => '0x00012345',
        'npc' => $npc, 'st' => 1, 'n' => count($entries), 'sent' => count($entries), 'part' => 1, 'cid' => 'dflow01',
        'want' => 0, 'ask' => '', 'crit' => 0, 'scene' => 0, 'fam' => 1, 'sub' => 1, 'pg' => 300, 'bamt' => 0,
        'vt' => '0x0001D70E', 'q' => '', 'sp' => 30, 'perk' => '-', 'lvl' => 12];
    $kv = array_replace($base, $kv);
    $kv['n'] = (int) ($kv['n'] ?: count($entries));
    $kv['sent'] = count($entries);
    $payload = fxKvString($kv) . ';e=' . implode('~~', $entries);
    $GLOBALS['gameRequest'] = ['lrg_topics', fxNow(), 100000, FX_CTX . $payload];
    $GLOBALS['HERIKA_NAME'] = $npc;
    ob_start();
    $res = (string) lrgDlgHandleGameMessage($GLOBALS['gameRequest']);
    $echo = (string) ob_get_clean();
    Fx::trace("game -> lrg_topics npc=$npc want=" . $kv['want'] . ' n=' . $kv['n'] . ' entries=' . count($entries)
        . " => $res" . ($echo !== '' ? ' D1:' . fx_short($echo, 160) : ''));
    return ['res' => $res, 'echo' => $echo, 'queue' => fxDlgQueue(), 'state' => fxDlgStateOf($npc)];
}

/** lrg_dlg, keyed by ev. The last key of ev=line / ev=result is raw, exactly as on the wire. */
function fxDlgEvent(string $npc, string $ev, array $kv = [], string $tail = ''): array
{
    $kv = ['ev' => $ev, 'npc' => $npc] + $kv;
    // [0.5.0] ev=calib carries k= LAST and RAW, exactly as ev=line carries t= and ev=result carries txt=
    $payload = fxKvString($kv) . ($tail !== '' ? (';' . (['line' => 't', 'result' => 'txt', 'calib' => 'k'][$ev] ?? 'x') . '=' . $tail) : '');
    $GLOBALS['gameRequest'] = ['lrg_dlg', fxNow(), 100000, FX_CTX . $payload];
    $GLOBALS['HERIKA_NAME'] = $npc;
    ob_start();
    $res = (string) lrgDlgHandleGameMessage($GLOBALS['gameRequest']);
    $echo = (string) ob_get_clean();
    Fx::trace("game -> lrg_dlg ev=$ev npc=$npc => $res");
    return ['res' => $res, 'echo' => $echo, 'state' => fxDlgStateOf($npc)];
}

/** lrg_dlgtalk: admitted ('pass') or dropped before the lock ('handled'). */
function fxDlgTalk(string $npc): string
{
    $GLOBALS['gameRequest'] = ['lrg_dlgtalk', fxNow(), 100000, FX_CTX . 'again'];
    $GLOBALS['HERIKA_NAME'] = $npc;
    $res = (string) lrgDlgHandleGameMessage($GLOBALS['gameRequest']);
    Fx::trace("game -> lrg_dlgtalk npc=$npc => $res");
    return $res;
}

/** The plugin's own Phase 2 state for one NPC. */
function fxDlgStateOf(string $npc): array { return (array) lrgDlgGet($npc); }

/** Everything the D2 route queued this scenario. */
function fxDlgQueue(): array { return (array) ($GLOBALS['LRG_DLG_TEST_QUEUE'] ?? []); }

function fxDlgClearQueue(): void { $GLOBALS['LRG_DLG_TEST_QUEUE'] = []; }

/** One player-speech turn through Phase 2's own hook order. Returns the turn record + both blocks. */
function fxDlgSay(string $npc, ?array $snap, string $text, string $type = 'inputtext', ?array $enabled = null): array
{
    if ($snap !== null) { fxSendSnapshot($npc, $snap); }
    // [0.5.0] the snapshot MCM tier is a per-REQUEST cache; a new simulated turn is a new request
    unset($GLOBALS['LRG_DLG_TURN'], $GLOBALS['LRG_DLG_MCM_SNAP'], $GLOBALS['LRG_DLG_HOLD_MOVE'],
        $GLOBALS['LRG_DLG_SVC_HIDDEN'], $GLOBALS['LRG_DLG_HOLD_HIDDEN']);
    $GLOBALS['gameRequest'] = [$type, fxNow(), 100000, FX_CTX . $text];
    $GLOBALS['HERIKA_NAME'] = $npc;
    $GLOBALS['ENABLED_FUNCTIONS'] = $enabled ?? array_merge(fxEnabledDefault(), [FX_DLG_ACT, 'ForgiveCrime', 'PayBounty']);
    $GLOBALS['FUNCTIONS_ARE_ENABLED'] = in_array(strtolower($type), FX_CHIM_ACTION_TYPES, true);
    if ($type === 'lrg_dlgtalk') { lrgDlgPrerequest(); }
    lrgDlgEnsureActions();
    lrgDlgPrepareTurn();
    // [0.4.1] the conversation hold's server half, in functions.php's own order: brace depth 0, after
    // both lanes have built the offer, and it only ever removes EndConversation.
    if (function_exists('lrgDlgHideEndConversationOnHold')) { lrgDlgHideEndConversationOnHold(); }
    // [0.5.1] and the follower policy LAST of all, exactly as functions.php orders it: it only ever
    // removes, and it reads nothing but the snapshot's fol= block.
    if (function_exists('lrgFollowerPolicy')) { lrgFollowerPolicy(); }
    $t = $GLOBALS['LRG_DLG_TURN'] ?? null;
    $out = ['turn' => $t, 'static' => '', 'volatile' => '', 'enabled' => array_values((array) ($GLOBALS['ENABLED_FUNCTIONS'] ?? []))];
    if (is_array($t)) {
        $out['static'] = (string) lrgDlgStaticGuidance($t);
        $out['volatile'] = (string) lrgDlgVolatileGuidance($t);
    }
    $out['keys'] = fxDlgKeys($out['volatile']);
    Fx::trace("dlg turn $type '$text' npc=$npc => on=" . (!empty($t['on']) ? 1 : 0) . ' list=' . (string) ($t['list'] ?? '-')
        . ' keys=' . count($out['keys']) . ' offered=' . (in_array(FX_DLG_ACT, $out['enabled'], true) ? 'yes' : 'NO'));
    if (Fx::$trace && $out['volatile'] !== '') { Fx::trace('business block: ' . fx_short($out['volatile'], 1400)); }
    return $out;
}

/** The T-keys the <business> block really showed, key => the text after the label. */
function fxDlgKeys(string $vol): array
{
    $out = [];
    foreach (explode("\n", $vol) as $line) {
        if (preg_match('/^(T\d{1,2})\s+(?:(\[[^\]]*\])\s+)?(.*)$/', trim($line), $m)) {
            $out[$m[1]] = ['label' => (string) ($m[2] ?? ''), 'text' => trim((string) $m[3])];
        }
    }
    return $out;
}

/**
 * The simulated LLM answer on a business turn: one TakeUpBusiness line with this item.
 *
 * THE REAL SHAPE, which this used to get wrong: the WIRE line carries the action's CODE name
 * (HerikaServer/functions/functions.php builds "$actorName|$channel|$functionCodeName@$param"), while
 * $LAST_LLM_RESPONSE['action'] - which the TTS transformer keys off - carries the DISPLAY name CHIM
 * stores, and CHIM snake-cases that (Take_Up_Business). Building both from 'TakeUpBusiness' meant no
 * scenario ever exercised the string the gate really has to match, and the gate's failure to match it
 * went unnoticed. $name overrides the display name, $wire the code name.
 */
function fxDlgLlm(string $npc, string $item, ?string $message = null, string $name = FX_DLG_DISPLAY,
                  string $wire = FX_DLG_ACT): array
{
    $GLOBALS['LAST_LLM_RESPONSE'] = ['action' => $name, 'item' => $item, 'target' => $npc,
        'message' => $message === null ? 'one short spoken line' : $message];
    fxSpoke($message);
    $lines = [$npc . '|command|' . $wire . '@' . $item . "\r\n"];
    $out = array_values((array) lrgDlgPostProcessActions($lines));
    unset($GLOBALS['talkedSoFar']);
    Fx::trace("LLM -> $npc $name item='$item' => " . ($out ? fx_short(trim(implode(' ', $out))) : 'dropped'));
    return $out;
}

/** The LLM answered with speech only. The free-conversation check's do=award still rides on this path. */
function fxDlgLlmNothing(string $npc, ?string $message = null): array
{
    $GLOBALS['LAST_LLM_RESPONSE'] = ['action' => '', 'item' => '', 'target' => $npc, 'message' => (string) $message];
    fxSpoke($message);
    $out = array_values((array) lrgDlgPostProcessActions([]));
    unset($GLOBALS['talkedSoFar']);
    return $out;
}

/** k=v of one Phase 2 wire line. */
function fxDlgKv(string $line): array { return fxParamKv($line); }

function fxDlgDo(string $line): string { return (string) (fxDlgKv($line)['do'] ?? ''); }

/**
 * Does every Phase 2 param key appear, in the order PROTOCOL v0.4 section 8 fixes?
 * [0.5.0] `z=1` is no longer necessarily the last key: note= (W9) and svc= (W12) may follow it, in that
 * order. Everything UP TO z=1 is still byte-for-byte fixed, which is what an older game script parses.
 */
function fxDlgOrderOk(string $line): bool
{
    $p = fxParam($line);
    return (bool) preg_match('/^ok=1;cid=[^;]*;npc=[^;]*;ref=[^;]*;do=[^;]*;sid=[^;]*;gen=[^;]*;pos=[^;]*;'
        . 'i=[^;]*;txt=[^;]*;kind=[^;]*;cost=[^;]*;res=[^;]*;xp=[^;]*;stat=[^;]*;take=[^;]*;ask=[^;]*;'
        . 'vt=[^;]*;x=[0-9a-f]{10};z=1(?:;note=[^;]*)?(?:;svc=[a-z]{2,10})?$/', $p);
}

/** A closed-layer fixture: N entries under one fingerprint, so the layer lookup can be exercised. */
function fxDlgLayer(array $texts, string $fp = 'fxlayer01'): array
{
    fxDlgLoad();
    return [['fingerprint' => $fp, 'parent_info' => 'fxi:parent', 'kind' => 'closed', 'n' => count($texts),
        'norms' => array_values(array_map('lrgPromptNorm', $texts))]];
}

/**
 * The glue log APPENDED since a byte offset. lorerim_glue.log is never truncated (it is the owner's
 * diagnostics record), so counting occurrences over the whole file counts every earlier run too.
 */
function fxDlgLogFrom(int $offset): string
{
    $f = (string) @file_get_contents(fxLogFile());
    return $offset > 0 && strlen($f) > $offset ? substr($f, $offset) : $f;
}

function fxDlgLogMark(): int { clearstatcache(); return (int) @filesize(fxLogFile()); }

/** Phase 1's snapshot with the keys Phase 2 cares about. */
function fxDlgSnap(array $over = []): array
{
    return fxSnap(['pgold' => '300', 'pspeech' => '30', 'gold' => '120'] + $over);
}

// =================================================================== [0.5.0] v0.5 helpers
/**
 * The offline service catalog seam. Without it lrgDlgServiceCatalog() reads
 * <plugin>/data/service_catalog.json, which a flow run must never depend on.
 * Passing [] means "the census has never run", which is itself a case worth testing.
 */
function fxDlgServiceCatalog(array $cat): void
{
    $GLOBALS['LRG_DLG_TEST_SERVICE_CATALOG'] = $cat + [
        '_' => 'service_catalog', 'v' => 1, 'destinations' => [], 'destinations_travel' => [],
        'skills' => [], 'price_globals' => [], 'crime_topics' => [], 'service_plugins' => [],
    ];
}

/** A closed PRICE LIST exactly as a CFTO destination layer arrives: short names, a real fare each. */
function fxDlgPriceLayer(string $npc, array $texts, array $kv = [], int $clicks = 1): array
{
    fxDlgLoad();
    $spec = [];
    foreach ($texts as $txt) { $spec[$txt] = ['toplevel' => 0, 'cost' => lrgPromptCost((string) $txt)]; }
    fxDlgReset(fxDlgIndexFrom($spec), fxDlgLayer($texts));
    // [pt19 v1.0 / S3.3] the stage rail: before this install's first verified click only an indexed PLAIN line is clicked,
    // and a priced slot is none - a price list is measured on an install whose clicks are proven (d26b walks the rail)
    lrgDlgPut('*install*', ['clicks_ok' => $clicks]);
    fxSendSnapshot($npc, fxDlgSnap());
    $ent = [];
    foreach ($texts as $i => $txt) { $ent[] = fxDlgEntry($i, (string) $txt); }
    return [$ent, array_replace(['layer' => 1, 'gen' => 1, 'pg' => 300], $kv)];
}

/** LRG_Main.CONV_MOVE_ACTIONS, parsed out of the shipped .psc - the ONE source of truth for d52. */
function fxConvMoveActions(): array
{
    $f = dirname(__DIR__, 2) . '/game/LoreRimGlue/Source/Scripts/LRG_Main.psc';
    $src = (string) @file_get_contents($f);
    if ($src === '') { return []; }
    if (!preg_match('/CONV_MOVE_ACTIONS\s*=\s*((?:"[^"]*"\s*(?:\+\s*\\\\?\s*)?)+)/s', $src, $m)) { return []; }
    preg_match_all('/"([^"]*)"/', $m[1], $q);
    $joined = implode('', $q[1]);
    return array_values(array_filter(array_map('trim', explode('|', $joined)), 'strlen'));
}

/** Everything the log gained since $mark, as lines. */
function fxDlgLogLines(int $mark): array
{
    return array_values(array_filter(preg_split('/\R/', fxDlgLogFrom($mark)) ?: [], 'strlen'));
}
