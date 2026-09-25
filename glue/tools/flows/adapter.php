<?php
/**
 * LoreRim Glue - flow-test ADAPTER: the one file that knows the plugin.
 *
 * Every assumption about the plugin under test is here and nowhere else:
 *   A. where the plugin is and how it is loaded                         (fxLoadPlugin)
 *   B. which contract capabilities are delivered                        (fxProbeCapabilities)
 *   C. clock and dice seams                                             (fxNow, fxAdvance, fxRoll)
 *   D. how one game message / one LLM turn is played (PROTOCOL.md 7.3)  (fxSendSnapshot, fxSendScene, fxTurn, fxLlm, fxFuncret)
 *   E. state reads and thin wrappers around plugin functions            (fxMem, fxInterest, fxCall ..., FxTurnResult accessors)
 *   F. scene-index reads, always DISCOVERED from the installed packs    (fxSceneTier, fxSceneOfTier, fxWords ...)
 *   G. text markers for the injected guidance                           (FX_RE_*, fxOptionMarks, fxOfferedItemKeys ...)
 *   H. CHIM's real hook order, for the wiring scenario                  (fxHookTurn, fxHookLlm)
 *   J. the cast, composite moves, child-process variants                (fxCast, fxSetScore, fxBeginScene, fxClimbToTop, fxVariants)
 *   I. the known-pending list                                           (fxKnownPending)
 * If a builder chose another function name, key or wording, reconcile it HERE; scenarios should not need edits.
 * Scenarios never call a plugin function by name and never contain a scene id, a position word or a line of dialogue.
 */

// ------------------------------------------------------------------ A. plugin location, codes, names
const FX_ACT_START    = 'ExtCmdLRG_StartIntimacy'; // PROTOCOL 2 / 6.5
const FX_ACT_CONTROL  = 'ExtCmdLRG_SceneControl';
const FX_ACT_CLOTHING = 'ExtCmdLRG_Clothing';
const FX_ACT_INVITE   = 'ExtCmdLRG_Invite';
const FX_ACT_REQUESTACT = 'ExtCmdLRG_RequestAct'; // [0.3] server-only, resolved into do=goto by the gate
const FX_NAME = [FX_ACT_START => 'BeginIntimacy', FX_ACT_CONTROL => 'ChangeIntimacy', FX_ACT_CLOTHING => 'ChangeClothing',
    FX_ACT_INVITE => 'SuggestPrivacy', FX_ACT_REQUESTACT => 'RequestAct'];
const FX_GLUE = [FX_ACT_START, FX_ACT_CONTROL, FX_ACT_CLOTHING, FX_ACT_INVITE, FX_ACT_REQUESTACT];
/** What CHIM typically has enabled for an NPC (codes). The glue codes are appended by fxEnabledDefault(). */
const FX_CHIM_ACTIONS = ['Talk', 'Follow', 'MoveTo', 'GiveGoldTo', 'TakeASeat', 'RentRoom', 'EndConversation', 'Inspect'];
const FX_SPEECH_TYPES = ['inputtext', 'inputtext_s', 'ginputtext', 'ginputtext_s'];
/** main.php:1078 - request types that keep actions enabled; everything else gets FUNCTIONS_ARE_ENABLED=false. */
const FX_CHIM_ACTION_TYPES = ['inputtext', 'inputtext_s', 'ginputtext', 'ginputtext_s', 'narrator_inputtext', 'instruction', 'welcome', 'cheatmode'];
/** The DLL prefixes every requestMessageForActor text like this. */
const FX_CTX = '(Context location: Flowtest Inn) ';
const FX_SEXES = ['0', '1']; // [player, npc] as the game sends them: 0 male / 1 female

function fxPluginDir(): string { return Fx::$pluginDir; }

function fxLoadPlugin(): void
{
    Fx::$pluginDir = realpath(__DIR__ . '/../../server/lorerim_glue') ?: (__DIR__ . '/../../server/lorerim_glue');
    require_once Fx::$pluginDir . '/lib/lrg_actions.php';
    @mkdir(LRG_DIR . '/data', 0770, true);
    @mkdir(dirname(LRG_DIR, 2) . '/log', 0770, true);          // lrgLog() target: gate decisions end up here
    @touch(LRG_DIR . '/data/.schema_v' . LRG_SCHEMA_VERSION);   // PROTOCOL 7.3: skips the real migrations
}

function fxLogFile(): string { return dirname(LRG_DIR, 2) . '/log/lorerim_glue.log'; }

/** Value from the plugin's merged config, or the contract default when the key is not there (yet). */
function fxCfg(string $path, $default)
{
    $v = function_exists('lrgConfig') ? lrgConfig() : [];
    foreach (explode('.', $path) as $k) {
        if (!is_array($v) || !array_key_exists($k, $v)) { return $default; }
        $v = $v[$k];
    }
    return $v;
}

// ------------------------------------------------------------------ B. capabilities
function fxProbeCapabilities(): void
{
    $fn = static function (string $cap, array $functions) {
        $missing = array_values(array_filter($functions, fn($f) => !function_exists($f)));
        Fx::$caps[$cap] = !$missing;
        Fx::$capWhy[$cap] = $missing ? implode('(), ', $missing) . '() missing' : '';
    };
    $fn('clock', ['lrgNow']);
    $fn('dice', ['lrgRoll']);
    $fn('handle', ['lrgHandleGameMessage']);
    $fn('prerequest', ['lrgPrerequest']);
    $fn('memory', ['lrgMemGet', 'lrgMemSet']);
    $fn('interest', ['lrgInterest']);
    $fn('index_v3', ['lrgIndexWarm', 'lrgIndexStatus', 'lrgPickStart', 'lrgFurnitureOptions']);
    $fn('intent', ['lrgRecogniseIntent', 'lrgIntentClothingWho', 'lrgRequestDirective']);           // [0.3] G1
    $fn('acts', ['lrgSceneActs', 'lrgMatchAct', 'lrgActLabel', 'lrgActOptions']);                   // [0.3] G5
    $fn('decorate', ['lrgDecorate', 'lrgSpokenThisTurn', 'lrgSafetyNet']);                          // [0.3] G1 / G3
    $fn('stale', ['lrgCloseScenesFor', 'lrgCloseAllScenes']);                                       // [0.3] G4
    Fx::$caps['actions_v7'] = defined('LRG_ACTIONS_VERSION') && (int) LRG_ACTIONS_VERSION >= 7;
    Fx::$capWhy['actions_v7'] = Fx::$caps['actions_v7'] ? '' : 'LRG_ACTIONS_VERSION is ' . (defined('LRG_ACTIONS_VERSION') ? LRG_ACTIONS_VERSION : 'undefined') . ', contract says 7';
    Fx::$caps['actions_v8'] = defined('LRG_ACTIONS_VERSION') && (int) LRG_ACTIONS_VERSION >= 8 && defined('LRG_ACT_REQUESTACT');
    Fx::$capWhy['actions_v8'] = Fx::$caps['actions_v8'] ? '' : 'LRG_ACTIONS_VERSION is ' . (defined('LRG_ACTIONS_VERSION') ? LRG_ACTIONS_VERSION : 'undefined') . ', contract says 8 (+ RequestAct)';
    // turn modes: play one throw-away turn and look at the key
    fxReset();
    $r = fxSay('Capability Probe', fxSnap(), 'Hello.');
    Fx::$caps['modes'] = is_array($r->turn) && array_key_exists('mode', $r->turn);
    Fx::$capWhy['modes'] = Fx::$caps['modes'] ? '' : "\$GLOBALS['LRG_TURN']['mode'] is not set by lrgPrepareTurn()";
    fxReset();
}

// ------------------------------------------------------------------ C. clock and dice (PROTOCOL 7.2)
function fxNow(): int { return (int) ($GLOBALS['LRG_TEST_NOW'] ?? time()); }
function fxAdvance(int $seconds): void { $GLOBALS['LRG_TEST_NOW'] = fxNow() + $seconds; Fx::trace("clock +{$seconds}s"); }
/** $what = 'initiative' | 'announce' | '*'. 1 = always succeeds, 100 = never succeeds (the contract compares roll <= chance). */
function fxRoll(string $what, int $value): void { $GLOBALS['LRG_TEST_ROLL'][$what] = $value; }

/** Fresh world: empty DB, no relationships, clock at "now", every die failing unless a scenario says otherwise. */
function fxReset(): void
{
    $GLOBALS['db'] = new FxDb();
    RelationshipManager::$aff = [];
    $GLOBALS['LRG_TEST_NOW'] = time();
    $GLOBALS['LRG_TEST_ROLL'] = ['*' => 100];
    $GLOBALS['PLAYER_NAME'] = 'Flowtest Player';
    $GLOBALS['LRG_TEST_NO_SPAWN'] = true; // R7 seam: no flow run may ever start a real index build
    // [0.5.1 fix pass] lrgGetNpcState() memoises per REQUEST; a fresh world is a fresh request
    unset($GLOBALS['LRG_NPCSTATE_MEMO']);
    unset($GLOBALS['LRG_TEST_SPAWNED'], $_GET['profile']);
    unset($GLOBALS['LRG_TURN'], $GLOBALS['ENABLED_FUNCTIONS'], $GLOBALS['FUNCTIONS_ARE_ENABLED'], $GLOBALS['HERIKA_NAME'], $GLOBALS['gameRequest'],
        $GLOBALS['action_post_process_fnct_ex'], $GLOBALS['PROMPTS'], $GLOBALS['FORCE_MAX_TOKENS'], $GLOBALS['SCRIPTLINE_ANIMATION_SENT'], $GLOBALS['OPENAI_FILTER_DISABLED']);
    fxNeReset();
    Fx::$injections = []; Fx::$events = [];
}
/** [pt17-replies] The never-empty rail's per-request state and its offline seams (lib/lrg_replies.php). */
function fxNeReset(): void
{
    unset($GLOBALS['LRG_NE_REJECT'], $GLOBALS['LRG_NE_DONE'], $GLOBALS['LRG_NE_REASKED'], $GLOBALS['LRG_NE_SEEN'], $GLOBALS['LRG_TEST_SAY'],
        $GLOBALS['LRG_TEST_REASK'], $GLOBALS['LAST_LLM_RESPONSE'], $GLOBALS['VALIDATE_LLM_OUTPUT_FNCT'], $GLOBALS['LLM_RETRY_FNCT'],
        $GLOBALS['LRG_NE_PREV_VALIDATOR'], $GLOBALS['LRG_NE_PREV_RETRY'], $GLOBALS['structuredOutputTemplate'], $GLOBALS['responseTemplate'],
        $GLOBALS['contextData'], $GLOBALS['IN_FALLBACK_MODE']);
}
/** [pt17-replies] What the rail said through the offline seam this request (the ScriptQueue lines it would have sent). */
function fxSaid(): array { return array_values(array_map('strval', (array) ($GLOBALS['LRG_TEST_SAY'] ?? []))); }

function fxDb(): FxDb { return $GLOBALS['db']; }
function fxAff(string $npc, int $aff): void { RelationshipManager::$aff[$npc] = $aff; }

// ------------------------------------------------------------------ D. playing messages and turns
function fxKvString(array $kv): string
{
    $parts = [];
    foreach ($kv as $k => $v) { if ($v !== null) { $parts[] = $k . '=' . $v; } } // null = the key is missing on the wire
    return implode(';', $parts);
}

/**
 * Snapshot v=2 (PROTOCOL 1.1), key order as the game sends it (new keys before class, fac last).
 * Default: a confirmed adult woman, feature on, alone with a male player indoors, nobody watching, no relationship data.
 */
function fxSnap(array $over = []): array
{
    $base = ['v' => '2', 'npc' => '', 'ref' => '0x00012345', 'on' => '1', 'adult' => '1', 'sex' => '1', 'psex' => '0', 'lvl' => '10', 'plvl' => '12',
        'combat' => '0', 'scene' => '0', 'ostim' => '0', 'mate' => '', 'married' => '0', 'pspouse' => '0', 'courting' => '0', 'rank' => '0',
        'conf' => '2', 'moral' => '2', 'aggr' => '0', 'gold' => '40', 'pgold' => '300', 'interior' => '1', 'wit' => '0', 'witfol' => '0', 'witkid' => '0',
        'pspeech' => '15', 'pdb' => '0', 'psouls' => '0', 'pquests' => '3',
        'x' => '2', 'loc' => 'Flowtest Inn', 'ltype' => 'inn', 'cellown' => 'none', 'home' => '0', 'nhome' => '', 'prent' => '0', 'nearf' => '', 'bedown' => '', 'sde' => '-1',
        'class' => '', 'fac' => 'JobInnkeeperFaction,TownWhiterunFaction'];
    $snap = $base;
    foreach ($over as $k => $v) {
        if ($v === null) { unset($snap[$k]); } else { $snap[$k] = (string) $v; } // null = the key is missing on the wire
    }
    return $snap;
}

/** Any game -> server message through the pre-lock entry point. Returns 'handled' or 'pass'. */
function fxGameMessage(array $gameRequest, string $herikaName = 'Somebody Else'): string
{
    $GLOBALS['gameRequest'] = $gameRequest;
    $GLOBALS['HERIKA_NAME'] = $herikaName; // PROTOCOL 2: HERIKA_NAME is unreliable at main.php:193 - state messages must not depend on it
    // Production shape: at main.php:193 CHIM has only ?profile=<md5 of the NPC name>; HERIKA_NAME is
    // resolved later. Drive the real resolver (lrgResolveRequestNpc) instead of handing it the answer.
    $type = strtolower((string) ($gameRequest[0] ?? ''));
    if ($type === 'lrg_initiative' && $herikaName !== '' && $herikaName !== 'Somebody Else') {
        $_GET['profile'] = md5($herikaName);
        $GLOBALS['HERIKA_NAME'] = 'Somebody Else'; // the decoy the core has not replaced yet
    } else {
        unset($_GET['profile']);
    }
    if (function_exists('lrgHandleGameMessage')) {
        try { return (string) lrgHandleGameMessage($gameRequest); } catch (FxTerminated $e) { return 'handled'; }
    }
    return fxIncludeHook('preprocessing.php', $gameRequest) ? 'handled' : 'pass'; // v1: the logic lives in the hook file
}

function fxSendSnapshot(string $npc, array $snap): string
{
    $snap['npc'] = $npc;
    // CHIM's own table: the md5 -> name mapping the pre-lock hook has to go through (main.php:295-297)
    fxDb()->upsertRowOnConflict('core_npc_master', ['md5' => md5($npc), 'npc_name' => $npc], 'md5');
    $res = fxGameMessage(['lrg_npcstate', fxNow(), 100000, fxKvString($snap)]);
    Fx::trace("game -> lrg_npcstate npc=$npc adult=" . ($snap['adult'] ?? '(missing)') . ' wit=' . ($snap['wit'] ?? '?') . ' ltype=' . ($snap['ltype'] ?? '') . " => $res");
    return $res;
}

/** lrg_scene (PROTOCOL 1.2). `next` is kept last, as on the wire; ev=end carries only the five keys the game sends. */
function fxSendScene(string $npc, string $ev, array $kv = []): string
{
    $base = ['ev' => $ev, 'npc' => $npc, 'cid' => 'sflow0001', 'scene' => '', 'speed' => '1', 'maxspeed' => '3', 'trans' => '0', 'furn' => '', 'ppos' => '0', 'npos' => '1',
        'actors' => '2', 'byglue' => '1', 'und' => '0', 'tags' => '', 'acts' => '', 'x' => '2', 'auto' => '0', 'leader' => 'npc', 'stall' => '0', 'ncl' => '0', 'pcl' => '0',
        'wd' => '0', 'nearf' => '', 'undp' => ''];
    $msg = $kv + $base;
    if ($ev === 'end') { $msg = array_intersect_key($msg, array_flip(['ev', 'npc', 'cid', 'scene', 'byglue'])); }
    $next = $msg['next'] ?? null; unset($msg['next']);
    $ordered = ['ev' => $ev] + $msg;
    if ($next !== null) { $ordered['next'] = $next; }
    $res = fxGameMessage(['lrg_scene', fxNow(), 100000, fxKvString($ordered)]);
    Fx::trace("game -> lrg_scene ev=$ev scene=" . ($msg['scene'] ?? '') . " => $res");
    return $res;
}

function fxEnabledDefault(): array
{
    $glue = [FX_ACT_START, FX_ACT_CONTROL, FX_ACT_CLOTHING];
    if (Fx::has('actions_v7')) { $glue[] = FX_ACT_INVITE; } // a v6 plugin does not know the row, CHIM would not have it either
    if (Fx::has('actions_v8')) { $glue[] = FX_ACT_REQUESTACT; }
    return array_merge(FX_CHIM_ACTIONS, $glue);
}

final class FxTurnResult
{
    public string $npc = '';
    public string $type = '';
    public bool $handled = false;       // the request was dropped before the lock ('handled')
    public ?array $turn = null;         // $GLOBALS['LRG_TURN']
    public array $enabled = [];         // $GLOBALS['ENABLED_FUNCTIONS'] after the plugin pruned it
    public bool $functionsOn = false;   // $GLOBALS['FUNCTIONS_ARE_ENABLED'] after lrgPrerequest()
    public string $static = '';
    public string $volatile = '';
    public array $prompts = [];         // hook-level only: $GLOBALS['PROMPTS'][type]
    public ?int $maxTokens = null;      // hook-level only
    public array $closures = [];        // hook-level only: registered post-LLM filters
    public bool $refusalFilterOff = false; // hook-level only: the plugin set CHIM's OPENAI_FILTER_DISABLED for this request
    // [0.5.5] a funcret turn (fxFuncretTurn): the afterfunc cue CHIM would use, whether context_pre.php ended the
    // request, and the funcret payload as CHIM's processor/funcret.php would read it after preprocessing
    public string $cue = '';
    public bool $ended = false;
    public string $funcret = '';

    public function mode(): ?string { return isset($this->turn['mode']) ? (string) $this->turn['mode'] : null; }
    /** Explicitness level in force this turn, null = no explicit wording at all (PROTOCOL 6.4). */
    public function x(): ?int { return isset($this->turn['x']) ? (int) $this->turn['x'] : null; }
    public function hasX(): bool { return is_array($this->turn) && array_key_exists('x', $this->turn); }
    public function offered(string $code): bool { return in_array(strtolower($code), array_map('strtolower', array_map('strval', $this->enabled)), true); }
    public function glueOffered(): array { return array_values(array_filter(FX_GLUE, fn($c) => $this->offered($c))); }
    public function glueNames(): string { return implode('+', array_map(fn($c) => FX_NAME[$c], $this->glueOffered())) ?: 'none'; }
    public function reasons(): array { return (array) ($this->turn['gate']['reasons'] ?? []); }
    public function profileId(): string { return (string) ($this->turn['gate']['profile']['id'] ?? $this->turn['profile']['id'] ?? ''); }
    public function interestWord(): ?string { return isset($this->turn['interest']['word']) ? (string) $this->turn['interest']['word'] : null; }
    /** Place KEYS (home / room / quiet). The plugin keeps [key => words for the LLM]; a plain list of keys is accepted too. */
    public function places(): array
    {
        $p = (array) ($this->turn['places'] ?? []);
        return array_map('strval', array_is_list($p) ? $p : array_keys($p));
    }
    public function guidance(): string { return $this->static . "\n" . $this->volatile; }
    public function silent(): bool { return $this->static === '' && $this->volatile === ''; }
    public function progress(): array { return (array) ($this->turn['progress'] ?? []); }
    public function options(): array { return (array) ($this->turn['options'] ?? []); }
    // [0.3]
    public function intent(): array { return (array) ($this->turn['intent'] ?? []); }
    public function intentIs(string $kind, ?string $conf = null): bool
    {
        $i = $this->intent();
        return (string) ($i['kind'] ?? '') === $kind && ($conf === null || (string) ($i['conf'] ?? '') === $conf);
    }
    public function directive(): string { return preg_match('~<player_request>(.*?)</player_request>~s', $this->guidance(), $m) ? (string) $m[1] : ''; }
    public function hasDirective(): bool { return str_contains($this->guidance(), '<player_request>'); }
    public function acts(): array { return (array) ($this->turn['acts'] ?? []); }
    public function hidden(): array { return (array) ($this->turn['hidden'] ?? []); }
    public function propose(): ?array { $p = $this->turn['propose'] ?? null; return is_array($p) ? $p : null; }
    public function heat(): int { return (int) ($this->turn['heat'] ?? 0); }
    public function summary(): string
    {
        return 'mode=' . ($this->mode() ?? '-') . ' offered=' . $this->glueNames() . ' x=' . ($this->x() ?? 'null') . ' interest=' . ($this->interestWord() ?? '-')
            . ' reasons=' . (implode(',', $this->reasons()) ?: '-') . ($this->handled ? ' HANDLED' : '');
    }
}

/**
 * One LLM-triggering request, played as PROTOCOL 7.3 describes: globals, lrgHandleGameMessage, lrgPrepareTurn + lrgPrerequest, guidance.
 * $opt: 'enabled' => codes CHIM has enabled; 'order' => 'prepare_first' (7.3, default) | 'prerequest_first' (CHIM's real order).
 */
function fxTurn(string $npc, string $type = 'inputtext', string $text = 'Hello.', array $opt = []): FxTurnResult
{
    $r = new FxTurnResult();
    $r->npc = $npc; $r->type = $type;
    unset($GLOBALS['LRG_TURN']);
    $req = [$type, fxNow(), 100000, $text];
    $res = fxGameMessage($req, $npc);
    if ($res === 'handled') {
        $r->handled = true;
        Fx::trace("request $type '$text' for $npc => handled (dropped before the lock)");
        return $r;
    }
    $GLOBALS['HERIKA_NAME'] = $npc;
    $GLOBALS['gameRequest'] = $req;
    $GLOBALS['ENABLED_FUNCTIONS'] = $opt['enabled'] ?? fxEnabledDefault();
    $GLOBALS['FUNCTIONS_ARE_ENABLED'] = in_array(strtolower($type), FX_CHIM_ACTION_TYPES, true);
    $pre = static function () use ($req) {
        if (function_exists('lrgPrerequest')) { lrgPrerequest(); } else { fxIncludeHook('prerequest.php', $req); }
    };
    if (($opt['order'] ?? 'prepare_first') === 'prerequest_first') { $pre(); lrgPrepareTurn(); } else { lrgPrepareTurn(); $pre(); }
    $r->turn = $GLOBALS['LRG_TURN'] ?? null;
    $r->enabled = array_values((array) ($GLOBALS['ENABLED_FUNCTIONS'] ?? []));
    $r->functionsOn = !empty($GLOBALS['FUNCTIONS_ARE_ENABLED']);
    if (is_array($r->turn)) {
        $r->static = (string) lrgStaticGuidance($r->turn);
        $r->volatile = (string) lrgVolatileGuidance($r->turn);
    }
    foreach ($r->options() as $o) { Fx::$seenScenes[(string) $o['id']] = 'option'; }
    foreach ((array) ($r->turn['furn_options'] ?? []) as $fo) { if (!empty($fo['scene'])) { Fx::$seenScenes[(string) $fo['scene']] = 'furniture option'; } }
    Fx::trace("request $type '$text' for $npc => " . $r->summary() . ' actions_on=' . ($r->functionsOn ? 1 : 0));
    if (Fx::$trace && $r->volatile !== '') { Fx::trace('volatile guidance: ' . fx_short($r->volatile, 1400)); }
    return $r;
}

/** Fresh snapshot, then the request - what the game really does (20 s snapshots; forced before an initiative tick). */
function fxSay(string $npc, array $snap, string $text = 'Hello.', string $type = 'inputtext', array $opt = []): FxTurnResult
{
    fxSendSnapshot($npc, $snap);
    return fxTurn($npc, $type, $text, $opt);
}
function fxLeadTick(string $npc, array $snap, array $opt = []): FxTurnResult { return fxSay($npc, $snap, FX_CTX . 'lead', 'lrg_scenetalk', $opt); }
function fxSceneTalk(string $npc, array $snap, string $moment = 'a quiet moment'): FxTurnResult { return fxSay($npc, $snap, FX_CTX . $moment, 'lrg_scenetalk'); }
function fxInitiativeTick(string $npc, array $snap, array $opt = []): FxTurnResult { return fxSay($npc, $snap, FX_CTX . 'approach', 'lrg_initiative', $opt); }

/** Wire line as functions.php queueFunctionExecutionCommand writes it. */
function fxLine(string $npc, string $codeOrName, string $param, string $eol = "\r\n"): string { return $npc . '|command|' . $codeOrName . '@' . $param . $eol; }

/**
 * The simulated LLM answer. CHIM turns {"message": .., "action": .., "item": ..} into a spoken line (only when the message
 * is not empty) and one command line; only command lines go through the post-LLM filters. Returns what reaches the game.
 *
 * [0.3] What she SAID matters now (R10): the plugin reads $GLOBALS['talkedSoFar'], which CHIM fills in
 * returnLines() before the post-process hooks run, and drops a change she chose herself when it is empty.
 *   $message = null (default) -> she said something (the ordinary case)
 *   $message = ''             -> she said nothing at all: the silent path, asserted explicitly
 */
function fxSpoke(?string $message): void
{
    $GLOBALS['talkedSoFar'] = $message === '' ? [] : [$message ?? 'one short spoken line'];
    // [pt17-replies] the RAW model text as every JSON connector stores it (openrouterjson.php:1038): the never-empty
    // rail keys on this, not on talkedSoFar, because a muted business pick has words here and none there
    if (!isset($GLOBALS['LAST_LLM_RESPONSE']) || !is_array($GLOBALS['LAST_LLM_RESPONSE'])) { $GLOBALS['LAST_LLM_RESPONSE'] = []; }
    $GLOBALS['LAST_LLM_RESPONSE']['message'] = $message === '' ? '' : ($message ?? 'one short spoken line');
}
function fxLlm(string $npc, string $codeOrName, string $item, ?string $message = null): array
{
    fxSpoke($message);
    $out = fxRememberWireScenes(lrgPostProcessActions([fxLine($npc, $codeOrName, $item)]));
    unset($GLOBALS['talkedSoFar']);
    Fx::trace("LLM -> $npc " . (FX_NAME[$codeOrName] ?? $codeOrName) . " item='$item' message=" . ($message === '' ? '(empty)' : '(one line)') . ' => ' . ($out ? fx_short(trim(implode(' ', $out))) : 'dropped'));
    return array_values($out);
}
function fxLlmLines(array $lines, ?string $message = null): array
{
    fxSpoke($message);
    $out = array_values(fxRememberWireScenes(lrgPostProcessActions($lines)));
    unset($GLOBALS['talkedSoFar']);
    return $out;
}
/**
 * [0.3] The LLM answered with speech only (or with nothing at all) - no action line. This is the path the
 * safety net exists for: the player asked for something and the model did not choose the action.
 */
function fxLlmNothing(string $npc, ?string $message = null): array
{
    fxSpoke($message);
    $out = array_values(fxRememberWireScenes(lrgPostProcessActions([])));
    unset($GLOBALS['talkedSoFar']);
    Fx::trace("LLM -> $npc (no action, message=" . ($message === '' ? 'empty' : 'one line') . ') => ' . ($out ? fx_short(trim(implode(' ', $out))) : 'nothing'));
    return $out;
}

/**
 * [0.3 / G3d] Two romantic exchanges, so BeginIntimacy is on the table at all. A question about sex on a
 * cold first turn is answered in words now (heat gate, 11.2), which is exactly what playtest 6 asked for -
 * so every scenario that wants her own first move has to warm the conversation up first, as a player does.
 * These are the PLAYER's utterances (search input), never a line an NPC says.
 */
function fxWarm(string $npc, ?array $snap, int $times = 2): void
{
    $lines = ['You look beautiful tonight.', 'I want you.'];
    for ($i = 0; $i < $times; $i++) {
        // $snap = null: play the turns WITHOUT refreshing the snapshot (for the staleness scenarios)
        if ($snap === null) { fxTurn($npc, 'inputtext', $lines[$i % count($lines)]); } else { fxSay($npc, $snap, $lines[$i % count($lines)]); }
    }
    Fx::trace("warm-up: $times romantic exchange(s), heat=" . (int) (fxMem($npc)['heat'] ?? 0));
}
/** Bookkeeping for scenario 99: every scene id that reached the wire. */
function fxRememberWireScenes(array $out): array
{
    foreach ($out as $l) {
        if (stripos((string) $l, 'ExtCmdLRG_') === false) { continue; }
        foreach (['scene', 'fscene'] as $k) { $id = fxParamKv((string) $l)[$k] ?? ''; if ($id !== '') { Fx::$seenScenes[$id] = 'wire ' . $k; } }
    }
    return $out;
}

/** The part after Code@ of a wire line. */
function fxParam(string $line): string { $p = strpos($line, '@'); return $p === false ? '' : rtrim(substr($line, $p + 1), "\r\n"); }
function fxCode(string $line): string { $parts = explode('|', $line, 3); return explode('@', $parts[2] ?? '', 2)[0]; }
function fxParamKv(string $line): array
{
    $kv = [];
    foreach (explode(';', fxParam($line)) as $pair) { $p = strpos($pair, '='); if ($p !== false) { $kv[substr($pair, 0, $p)] = substr($pair, $p + 1); } }
    return $kv;
}
/** PROTOCOL 2: every param starts ok=1;cid=<cid> and carries npc=<display name>; the shape follows. */
function fxPrefixOk(string $line, string $npc): bool { return (bool) preg_match('/^ok=1;cid=[A-Za-z0-9_-]+;npc=' . preg_quote($npc, '/') . '(;|$)/', fxParam($line)); }
/**
 * [0.3] The four decoration keys the server appends LAST on every command (PROTOCOL 2.2), all optional
 * and all with a neutral reading when absent - so a v0.2 shape assertion stays valid with them present.
 */
const FX_RE_DECOR = '(?:;(?:warp|nowarp|wait|hold)=[^;]*)*';
/** Does the param contain this shape right after the ok / cid / npc prefix? $shape is a regex body. */
/** PROTOCOL 2: every param the server emits is ok=1;cid=..;npc=..;<shape>[;<decoration>]. */
function fxShape(string $line, string $shape): bool { return (bool) preg_match('/^ok=1;cid=[^;]+;npc=[^;]*;' . $shape . FX_RE_DECOR . '$/', fxParam($line)); }
/** The v1 wire, for the two comparisons that must tolerate a missing npc= key. */
function fxShapeV1(string $line, string $shape): bool { return (bool) preg_match('/^ok=1;cid=[^;]+;(?:npc=[^;]*;)?' . $shape . FX_RE_DECOR . '$/', fxParam($line)); }
/** The resolved verb of a wire line ('' = none): use this instead of anchoring a regex at the end. */
function fxDo(string $line): string { return (string) (fxParamKv($line)['do'] ?? ''); }
/**
 * [0.3 / G2] True when nothing the model asked for reached the game. The one line that may still be there
 * is the HOLD CARRIER: on a player speech turn inside a scene that produced no command of its own, the
 * server appends do=lead;who=<the leader the scene already has>;hold=<n> so the game also stops taking
 * lead turns. It changes nothing in the scene, and it is throttled to once per half a hold period.
 */
function fxCarrier(string $line): bool
{
    $kv = fxParamKv($line);
    return fxCode($line) === FX_ACT_CONTROL && ($kv['do'] ?? '') === 'lead' && isset($kv['hold']) && count($kv) === 6;
}
function fxNothingSent(array $w): bool
{
    foreach ($w as $l) { if (!fxCarrier($l)) { return false; } }
    return true;
}
/** The wire lines without the hold carrier. */
function fxReal(array $w): array { return array_values(array_filter($w, fn($l) => !fxCarrier($l))); }

/** The game answers a command: funcret "command@<Code>@<param echoed unchanged>@<result>" (PROTOCOL 1.6). */
function fxFuncret(string $wireLine, string $result): string
{
    $res = fxGameMessage(['funcret', fxNow(), 100000, 'command@' . fxCode($wireLine) . '@' . fxParam($wireLine) . '@' . $result]);
    Fx::trace('game -> funcret ' . fxCode($wireLine) . " '$result' => $res");
    return $res;
}

// ------------------------------------------------------------------ E. state and thin wrappers (scenarios never call a plugin function by name)
/** Throws FxPending when the plugin function is not there, so the check reads "pending", not "fatal". */
function fxCall(string $fn, ...$args)
{
    if (!function_exists($fn)) { throw new FxPending("plugin function $fn() is not delivered (or was renamed: reconcile in adapter.php)"); }
    return $fn(...$args);
}
function fxPluginNow(): int { return (int) fxCall('lrgNow'); }                                  // [NEW] 7.2
function fxPluginRoll(string $what): int { return (int) fxCall('lrgRoll', $what); }             // [NEW] 7.2
function fxMemSet(string $npc, array $patch): void { fxCall('lrgMemSet', $npc, $patch); }       // [NEW] 7.2
function fxNpcState(string $npc): ?array { return fxCall('lrgGetNpcState', $npc); }             // [v1] section 5
function fxProfile(string $npc, array $snap): array { return (array) fxCall('lrgBuildProfile', $npc, $snap); } // [v1]
/** [NEW] 6.1 - ['word','score','willing','may_initiate'] for this snapshot, profile built the plugin's own way. */
function fxInterest(string $npc, array $snap): array { return (array) fxCall('lrgInterest', $npc, $snap, fxProfile($npc, $snap)); }
/** [v1] internal resolver of a ChangeIntimacy item; used only for two diagnostics (too_soon vs unknown, hold vs "hold me"). */
function fxResolveControl(string $item, array $options, ?array $ctx): ?array { return fxCall('lrgResolveControl', $item, $options, $ctx); }
/** Is $id reachable from $from over declared navigations (any tier, 3 hops), by the frozen [v1] lrgSceneWalk()? */
function fxRoutable(string $from, string $id): bool { return isset(fxCall('lrgSceneWalk', $from, [], 4, 3, FX_SEXES, '')[strtolower($id)]); }

function fxMem(string $npc): array { return function_exists('lrgMemGet') ? (array) lrgMemGet($npc) : fxDb()->payload('lrg_memory', $npc); }
function fxInvite(string $npc): ?array { $i = fxMem($npc)['invite'] ?? null; return is_array($i) ? $i : null; }
function fxSceneRow(string $npc): array { return fxDb()->payload('lrg_scene_state', $npc); }
function fxSceneActive(string $npc): bool { return (int) (fxDb()->t['lrg_scene_state'][$npc]['active'] ?? 0) === 1; }

// ------------------------------------------------------------------ F. scene index (discovered, never hard-coded)
function fxWarmIndex(): array
{
    if (function_exists('lrgIndexWarm')) { $r = (array) lrgIndexWarm(false); }
    $n = count(lrgSceneIndex()['scenes'] ?? []);
    return ['scenes' => $n, 'warm' => $r ?? null];
}
function fxSceneTier(string $id): ?string { $s = $id !== '' ? lrgScene($id) : null; return $s ? (string) $s['tier'] : null; }
function fxTierNum(?string $tier): int { return $tier === null ? -1 : (int) (LRG_TIERS[$tier] ?? -1); }
function fxIsGentle(?string $tier): bool { return in_array($tier, ['neutral', 'affection', 'kissing'], true); }
/**
 * Why this scene must never have been offered or sent, '' = it is fine. Reads the index digest: unknown id, excluded (taste or the
 * hard list in code: forced / non-consensual / creature ...), not a two-person scene, or a transition (never a target).
 */
function fxSceneProblem(string $id): string
{
    $s = lrgScene($id);
    if (!$s) { return 'not in the index'; }
    if (!empty($s['excluded'])) { return 'excluded'; }
    if ((string) ($s['hard'] ?? '') !== '') { return 'hard list: ' . $s['hard']; }
    if ((int) ($s['actors'] ?? 0) !== 2) { return 'actors=' . ($s['actors'] ?? '?'); }
    if (!empty($s['transition'])) { return 'a transition'; }
    return '';
}

/** A no-furniture two-person scene of exactly this tier that the index can route to from $from (else any such scene), '' = none installed. */
function fxSceneOfTier(string $from, string $tier): string
{
    foreach (lrgSceneWalk($from, [], 4, 4, FX_SEXES, '') as $w) {
        if (($w['scene']['tier'] ?? '') === $tier) { return (string) $w['scene']['id']; }
    }
    foreach ((lrgSceneIndex()['scenes'] ?? []) as $s) {
        if (($s['tier'] ?? '') !== $tier || !empty($s['excluded']) || !empty($s['transition']) || (int) ($s['actors'] ?? 0) !== 2) { continue; }
        if (!in_array((string) ($s['furniture'] ?? ''), ['', 'none'], true)) { continue; }
        if (function_exists('lrgSceneSexOk') && !lrgSceneSexOk($s, FX_SEXES)) { continue; }
        return (string) $s['id'];
    }
    return '';
}
/** The start scene the plugin itself would pick for this pair (standing, no furniture). */
function fxStartScene(): string { return (string) lrgPickStartScene(FX_SEXES); }
/** Plain position words that lead somewhere for this pair under $ceiling (gentlest last). */
function fxWords(string $current, int $ceiling): array { return (array) lrgPositionWords($current, FX_SEXES, '', $ceiling, 16); }
/** A configured position word whose only matches lie ABOVE $ceiling, '' = none. */
function fxTooSoonWord(string $current, int $ceiling): string
{
    foreach ((array) fxCfg('scene_index.position_words', []) as $w) {
        $r = lrgFindSceneByText((string) $w, $current, FX_SEXES, '', $ceiling);
        if (is_array($r) && !empty($r['too_soon'])) { return (string) $w; }
    }
    return '';
}

// ------------------------------------------------------------------ G. text markers (rules, never lines)
/** Words that only appear when explicit wording is being permitted or asked for. Meta vocabulary only. */
const FX_RE_EXPLICIT = '/\b(crude\w*|explicit\w*|vulgar\w*|obscen\w*|foul-mouthed|dirtiest)\b/i';
/** "one short sentence" in any of the usual phrasings. */
const FX_RE_ONE_SENTENCE = '/\b(one|a single|single|1)\b[^.\n]{0,40}\bsentence\b/i';
const FX_RE_TOO_SOON = '/too soon/i';
const FX_RE_SAY_IT = '/\b(say|says|name|names|naming|tell|tells|aloud|out loud|announce\w*|speak\w*)\b/i';
const FX_RE_SILENT = '/\b(silent\w*|silence|wordless\w*|without a word|without words|no words|says? nothing|empty|unspoken)\b/i';

/** Does this text permit explicit wording? True when the meta vocabulary or the configured level-2 wording rule is present. */
function fxHasExplicitPermission(string $text): bool
{
    $lvl2 = (string) (fxCfg('scene_talk.explicitness_words', [])[2] ?? '');
    return (bool) preg_match(FX_RE_EXPLICIT, $text) || ($lvl2 !== '' && str_contains($text, $lvl2));
}
/** Asks for one short sentence within scene_talk.max_chars? Looks at the prompt cue and the guidance together. */
function fxAsksOneShortSentence(string $text): bool { return (bool) preg_match(FX_RE_ONE_SENTENCE, $text); }
function fxMaxChars(): int { return (int) fxCfg('scene_talk.max_chars', 120); }

/**
 * R10 marks in the scene notes: [option key => 'say' | 'silent' | 'both' | null].
 * Two layouts are understood: (a) inline - the mark stands in the option's own segment ("P2 = <label> (...mark...)");
 * (b) grouped - a sentence that carries the mark lists the keys ("... P2, P4 ... say ..." / "... P1, P3 ... silent ...").
 */
function fxOptionMarks(string $notes, array $options): array
{
    $marks = [];
    $keys = array_keys($options);
    foreach ($keys as $k) {
        $marks[$k] = null;
        // (a) inline. Preferred: "Pn = <label verbatim><tail>" - the tail runs to the next ";" / "Pm =" / line end (labels contain ";" themselves).
        $label = (string) ($options[$k]['label'] ?? '');
        $seg = null;
        if ($label !== '' && preg_match('/\b' . preg_quote($k, '/') . '\s*=\s*' . preg_quote($label, '/') . '([^;\n]*)/', $notes, $m)) { $seg = $m[1]; }
        elseif (preg_match('/\b' . preg_quote($k, '/') . '\s*=\s*(.*?)(?=;\s*\bP\d+\s*=|\n|$)/s', $notes, $m)) { $seg = str_replace($label, '', $m[1]); } // the label is pack data, not a mark
        if ($seg !== null) {
            $say = (bool) preg_match(FX_RE_SAY_IT, $seg); $sil = (bool) preg_match(FX_RE_SILENT, $seg);
            if ($say || $sil) { $marks[$k] = $say && $sil ? 'both' : ($say ? 'say' : 'silent'); continue; }
        }
        // (b) grouped: a sentence / line that names the key outside of its "Pn =" definition
        foreach (preg_split('/(?<=[.!?])\s+|\n/', $notes) as $sentence) {
            if (!preg_match('/\b' . preg_quote($k, '/') . '\b(?!\s*=)/', $sentence)) { continue; }
            $say = (bool) preg_match(FX_RE_SAY_IT, $sentence); $sil = (bool) preg_match(FX_RE_SILENT, $sentence);
            if ($say || $sil) { $marks[$k] = $say && $sil ? 'both' : ($say ? 'say' : 'silent'); break; }
        }
    }
    return $marks;
}

/**
 * Item keys the scene notes offer besides the P-keys. Layout understood: "Other items: a; b (explanation); c ... [ - or any position ...]."
 * [] = layout not recognised (the check that uses this then reads "pending": adjust the pattern here).
 */
function fxOfferedItemKeys(string $notes): array
{
    // [0.3] the verb list moved into the ChangeIntimacy sentence; the v0.2 "Other items:" layout still parses
    if (!preg_match('/(?:Other items|ChangeIntimacy takes exactly one item):\s*(.+?)(?:\s+-\s+or any position|\.\s|\.\n|\n|$)/s', $notes, $m)) { return []; }
    $keys = [];
    foreach (explode(';', $m[1]) as $part) {
        $k = trim((string) preg_replace('/\s*\(.*$/s', '', $part)); // drop the explanation in parentheses
        if ($k !== '') { $keys[] = $k; }
    }
    return $keys;
}

// ------------------------------------------------------------------ H. CHIM's real hook order (wiring scenario, SHARMAT variant)
/** Include one hook file of the plugin the way CHIM does (own scope, $gameRequest visible). True = it called terminate(). */
function fxIncludeHook(string $file, array $gameRequest): bool
{
    $path = Fx::$pluginDir . '/' . $file;
    if (!is_file($path)) { return false; }
    $GLOBALS['gameRequest'] = $gameRequest; // main.php: $gameRequest IS a global
    try {
        (static function () use ($path, $gameRequest) { include $path; })();
    } catch (FxTerminated $e) {
        return true;
    }
    return false;
}

/**
 * main.php order: globals.php (54) -> preprocessing.php (193) -> [lock] -> request-type whitelist (1078) -> prerequest.php (1117)
 * -> prompts.php (prompt.includes.php:19) -> functions.php (prompt.includes.php:55, after ENABLED_FUNCTIONS is loaded) -> context_pre.php (2540).
 */
function fxHookTurn(string $npc, string $type, string $text, ?array $enabled = null): FxTurnResult
{
    $r = new FxTurnResult();
    $r->npc = $npc; $r->type = $type;
    $req = [$type, fxNow(), 100000, $text];
    unset($GLOBALS['LRG_TURN'], $GLOBALS['ENABLED_FUNCTIONS'], $GLOBALS['action_post_process_fnct_ex'], $GLOBALS['PROMPTS'], $GLOBALS['FORCE_MAX_TOKENS'],
        $GLOBALS['SCRIPTLINE_ANIMATION_SENT'], $GLOBALS['external_fast_commands'], $GLOBALS['OPENAI_FILTER_DISABLED']);
    fxNeReset();   // [pt17-replies] a new request: the rail's seams are registered afresh by the hook files (talkedSoFar is the scenario's: fxSpoke)
    Fx::$injections = [];
    $GLOBALS['gameRequest'] = $req;
    $GLOBALS['HERIKA_NAME'] = $npc;
    $GLOBALS['TEMPLATE_DIALOG'] = '';
    fxIncludeHook('globals.php', $req);
    if (fxIncludeHook('preprocessing.php', $req)) { $r->handled = true; return $r; }
    $GLOBALS['FUNCTIONS_ARE_ENABLED'] = in_array(strtolower($type), FX_CHIM_ACTION_TYPES, true);
    fxIncludeHook('prerequest.php', $req);
    fxIncludeHook('prompts.php', $req);
    $GLOBALS['ENABLED_FUNCTIONS'] = $enabled ?? fxEnabledDefault();
    fxIncludeHook('functions.php', $req);
    fxIncludeHook('context_pre.php', $req);
    $r->turn = $GLOBALS['LRG_TURN'] ?? null;
    $r->enabled = array_values((array) ($GLOBALS['ENABLED_FUNCTIONS'] ?? []));
    $r->functionsOn = !empty($GLOBALS['FUNCTIONS_ARE_ENABLED']);
    foreach (Fx::$injections as $inj) {
        if ($inj['slot'] === 'character_bottom') { $r->static .= $inj['text']; } else { $r->volatile .= $inj['text']; }
    }
    $r->prompts = (array) ($GLOBALS['PROMPTS'][$type] ?? []);
    $r->maxTokens = isset($GLOBALS['FORCE_MAX_TOKENS']) ? (int) $GLOBALS['FORCE_MAX_TOKENS'] : null;
    $r->closures = (array) ($GLOBALS['action_post_process_fnct_ex'] ?? []);
    $r->refusalFilterOff = !empty($GLOBALS['OPENAI_FILTER_DISABLED']);
    Fx::trace("hooks: $type '$text' for $npc => " . $r->summary() . ' actions_on=' . ($r->functionsOn ? 1 : 0) . ' injections=' . count(Fx::$injections));
    return $r;
}

/**
 * [0.5.5 / owner addendum 11] The game's answer to a command, played as the WHOLE request CHIM makes of it:
 * preprocessing.php (main.php:193, before the lock) -> [lock] -> prerequest.php -> prompts.php (the afterfunc cue,
 * read by processor/request.php) -> functions.php -> context_pre.php (main.php:2540) -> processor/funcret.php, which
 * runs its follow-up turn with that cue as the last user line when the row's follow-up is on.
 * ->handled: it ended before the lock (a result that stays silent) · ->ended: context_pre.php stopped it ·
 * ->cue: CHIM's $PROMPTS['afterfunc']['cue'][<code>] · ->funcret: gameRequest[3] as funcret.php reads it.
 */
function fxFuncretTurn(string $npc, string $wireLine, string $result): FxTurnResult
{
    $code = fxCode($wireLine);
    $req = ['funcret', fxNow(), 100000, 'command@' . $code . '@' . fxParam($wireLine) . '@' . $result];
    $r = new FxTurnResult();
    $r->npc = $npc; $r->type = 'funcret';
    unset($GLOBALS['LRG_TURN'], $GLOBALS['ENABLED_FUNCTIONS'], $GLOBALS['action_post_process_fnct_ex'], $GLOBALS['PROMPTS'], $GLOBALS['FORCE_MAX_TOKENS'],
        $GLOBALS['SCRIPTLINE_ANIMATION_SENT'], $GLOBALS['OPENAI_FILTER_DISABLED'], $GLOBALS['LRG_VOICED']);
    Fx::$injections = [];
    $GLOBALS['HERIKA_NAME'] = 'Somebody Else'; // PROTOCOL 2: not resolved yet at main.php:193
    $GLOBALS['TEMPLATE_DIALOG'] = '';
    // CHIM's own default cue for every code it has none for (prompts/prompts.php "afterfunc")
    $GLOBALS['PROMPTS'] = ['afterfunc' => ['cue' => ['default' => '(the default afterfunc cue)']]];
    if (fxIncludeHook('preprocessing.php', $req)) { $r->handled = true; $r->funcret = (string) ($GLOBALS['gameRequest'][3] ?? ''); return $r; }
    $r->funcret = (string) ($GLOBALS['gameRequest'][3] ?? '');
    $req = $GLOBALS['gameRequest'];          // what CHIM carries on with (preprocessing may have rewritten field 2 / 3)
    $GLOBALS['HERIKA_NAME'] = $npc;
    $GLOBALS['FUNCTIONS_ARE_ENABLED'] = false; // main.php:1078: funcret is not an action-bearing type
    fxIncludeHook('prerequest.php', $req);
    fxIncludeHook('prompts.php', $req);
    $GLOBALS['ENABLED_FUNCTIONS'] = array_merge(fxEnabledDefault(), ['FollowPlayer', 'ComeCloser']);
    fxIncludeHook('functions.php', $req);
    $r->ended = fxIncludeHook('context_pre.php', $req);
    $r->turn = $GLOBALS['LRG_TURN'] ?? null;
    $r->enabled = array_values((array) ($GLOBALS['ENABLED_FUNCTIONS'] ?? []));
    $r->functionsOn = !empty($GLOBALS['FUNCTIONS_ARE_ENABLED']);
    foreach (Fx::$injections as $inj) {
        if ($inj['id'] === 'lorerim_glue_boundaries') { $r->static .= $inj['text']; }
        if ($inj['id'] === 'lorerim_glue_moment') { $r->volatile .= $inj['text']; }
    }
    $r->cue = (string) ($GLOBALS['PROMPTS']['afterfunc']['cue'][$code] ?? '');
    $r->maxTokens = isset($GLOBALS['FORCE_MAX_TOKENS']) ? (int) $GLOBALS['FORCE_MAX_TOKENS'] : null;
    $r->refusalFilterOff = !empty($GLOBALS['OPENAI_FILTER_DISABLED']);
    Fx::trace("funcret $code '$result' for $npc => " . ($r->ended ? 'ended in context_pre' : 'mode=' . ($r->mode() ?? '?')) . ' cue=' . fx_short($r->cue, 200));
    return $r;
}

/** [0.5.5] The voiced-failure record of the current request ($GLOBALS['LRG_VOICED']), or null. */
function fxVoiced(): ?array { $v = $GLOBALS['LRG_VOICED'] ?? null; return is_array($v) ? $v : null; }

/** lib/data_functions.php:6029-6040: actions are only processed while FUNCTIONS_ARE_ENABLED, then every registered filter runs in order. */
function fxHookLlm(FxTurnResult $r, array $lines): array
{
    if (!$r->functionsOn) { return []; }
    foreach ($r->closures as $f) { $lines = $f($lines); }
    return array_values((array) $lines);
}

// ------------------------------------------------------------------ J. the cast and composite moves
/**
 * Invented adults (no name of a real NPC, so no npc_override of the owner's config can interfere). The profile comes from the
 * faction list through the [v1] status rules of lrg_config.default.json; 'profile' is what the rule id should be.
 */
function fxCast(string $who): array
{
    $cast = [
        'innkeeper' => ['name' => 'Brenna Flowtest',    'profile' => 'innkeeper',   'snap' => ['fac' => 'JobInnkeeperFaction,TownWhiterunFaction']],              // relaxed: eager pace, vocal
        'commoner'  => ['name' => 'Hroda Flowtest',     'profile' => 'commoner',    'snap' => ['fac' => 'JobFarmerFaction,TownRoriksteadFaction']],               // moderate: normal pace / talk, renown sways strongly
        'patron'    => ['name' => 'Lisbet Flowtest',    'profile' => 'tavern_folk', 'snap' => ['fac' => 'JobBardFaction']],                                        // relaxed, married rule "secret"
        'housecarl' => ['name' => 'Sigrun Flowtest',    'profile' => 'housecarl',   'snap' => ['fac' => 'JobHousecarlFaction']],                                   // strict: slow pace, quiet
        'jarl'      => ['name' => 'Jarl Thyra Flowtest', 'profile' => 'jarl',       'snap' => ['fac' => 'JobJarlFaction', 'ltype' => 'castle', 'loc' => 'Flowtest Keep']], // very strict, companions are not tolerated
        'vigilant'  => ['name' => 'Vigilant Orla Flowtest', 'profile' => 'vigilant', 'snap' => ['fac' => 'VigilantOfStendarrFaction']],                            // never
        'bystander' => ['name' => 'Mikkel Flowtest',    'profile' => 'commoner',    'snap' => ['fac' => 'JobFarmerFaction', 'sex' => '0']],
        'minor'     => ['name' => 'Flowtest Minor',     'profile' => '',            'snap' => ['adult' => '0', 'fac' => 'TownWhiterunFaction']],                   // the game did NOT confirm an adult
    ];
    return $cast[$who];
}

/**
 * Give the NPC the CHIM affinity that yields exactly this interest score under PROTOCOL 6.1 when no renown / Speech bonus applies
 * (the default snapshot has none): score = affinity - effective min_affinity. Returns the effective threshold used.
 */
function fxSetScore(string $npc, array $snap, int $score): int
{
    $profile = lrgBuildProfile($npc, $snap);
    $min = (int) $profile['min_affinity'];
    $marriedElsewhere = ($snap['married'] ?? '0') === '1' && ($snap['pspouse'] ?? '0') !== '1';
    if ($marriedElsewhere && ($profile['married_rule'] ?? 'refuse') === 'secret') { $min += 20; }
    $bonus = (int) ($snap['rank'] ?? 0) * (int) fxCfg('affinity.vanilla_rank_bonus_per_point', 6)
        + (($snap['courting'] ?? '0') === '1' ? (int) fxCfg('affinity.courting_bonus', 10) : 0)
        + (($snap['pspouse'] ?? '0') === '1' ? (int) fxCfg('affinity.player_spouse_bonus', 60) : 0);
    fxAff($npc, $min + $score - $bonus);
    return $min;
}

/** Scene scenarios need the real index. Empty index = FAIL (or PENDING with --allow-empty-index). */
function fxNeedIndex(FxT $t): void
{
    if (count(lrgSceneIndex()['scenes'] ?? []) > 0) { return; }
    $why = 'the scene index is empty: ' . ((string) (lrgSceneIndex()['error'] ?? '') ?: 'no pack could be read') . ' (mo2.root=' . fxCfg('mo2.root', '?') . ')';
    if (empty($GLOBALS['FX_ALLOW_EMPTY_INDEX'])) { $t->must('the scene index has scenes', false, $why); }
    throw new FxPending($why);
}

/**
 * From "alone and willing" to a running scene: speech turn, BeginIntimacy, the game's success result, forced snapshot, ev=start.
 * Returns ['first' => FxTurnResult, 'wire' => line, 'start' => scene id, 'cid' => cid, 'snap' => in-scene snapshot].
 */
function fxBeginScene(FxT $t, string $npc, array $snap, array $sceneKv = []): array
{
    fxNeedIndex($t);
    fxWarm($npc, $snap); // [0.3] her own first move needs a warmed-up conversation (11.2)
    $first = fxSay($npc, $snap, 'Hello.');
    $wire = fxLlm($npc, FX_ACT_START, 'Player');
    if (count($wire) !== 1) { throw new RuntimeException('BeginIntimacy did not pass the gate for a willing NPC in private: ' . $first->summary()); }
    $kv = fxParamKv($wire[0]);
    $start = (($kv['furn'] ?? '') !== '' && ($kv['fscene'] ?? '') !== '') ? $kv['fscene'] : (string) ($kv['scene'] ?? '');
    if ($start === '') { $start = fxStartScene(); }
    fxFuncret($wire[0], 'They draw close. The scene begins.');
    $in = ['ostim' => '1'] + $snap;
    fxSendSnapshot($npc, $in); // the game forces a snapshot at every scene start
    $cid = (string) ($kv['cid'] ?? 'sflow0001');
    fxSendScene($npc, 'start', ['scene' => $start, 'cid' => $cid, 'furn' => (string) ($kv['furn'] ?? '')] + $sceneKv);
    return ['first' => $first, 'wire' => $wire[0], 'start' => $start, 'cid' => $cid, 'snap' => $in];
}

/** The game reports what is really playing; the server's ladder memory (_maxtier) follows it. Returns [sensual id, sexual id] ('' = not installed). */
function fxClimbToTop(string $npc, string $from, string $cid, array $sceneKv = []): array
{
    $sensual = fxSceneOfTier($from, 'sensual');
    if ($sensual !== '') { fxSendScene($npc, 'change', ['scene' => $sensual, 'cid' => $cid] + $sceneKv); }
    $sexual = fxSceneOfTier($sensual !== '' ? $sensual : $from, 'sexual');
    if ($sexual !== '') { fxSendScene($npc, 'change', ['scene' => $sexual, 'cid' => $cid, 'und' => '1'] + $sceneKv); }
    return [$sensual, $sexual];
}

/** Variants that need process-wide state; each runs in its own PHP process (see run_flows.php). */
function fxVariants(): array
{
    return [
        'killswitch'     => ['config' => ['kill_switch' => true]],
        'sharmat'        => ['define' => ['LRG_SHARMAT_PRESENT' => true]],
        'initiative_off' => ['config' => ['initiative' => ['enabled' => false]]],
        'talk_never'     => ['config' => ['npc_overrides' => [fxCast('innkeeper')['name'] => ['talk' => 'never']]]],
    ];
}

// ------------------------------------------------------------------ I. known pending
/**
 * [scenario id => reason]. A listed scenario that FAILS is reported as PENDING. Keep this list short and dated;
 * a listed scenario that passes prints a note asking to be removed.
 */
function fxKnownPending(): array
{
    return [
        // example: '11d' => '2026-09-21 library-level SHARMAT guard missing in lrgPrepareTurn() (hook-level guard works, see 11b)',
        // (that one was listed for an hour on 2026-09-21 and removed when BEHAVIOUR added the guard; the list is empty on purpose)
    ];
}
