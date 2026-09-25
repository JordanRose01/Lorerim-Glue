<?php
// LoreRim Glue - main.php:2540, just before the system prompt is assembled.
// Layer 2 (guidance). Static, per-NPC text goes to "character_bottom" so the prompt
// prefix stays stable for provider-side caching; volatile text (this moment, the live
// scene and its options) goes to "prompt_bottom", last. A turn in mode "silent" injects nothing at all -
// with ONE exception added in 0.5.2 (pt10): when the ONLY reason for the silence is that the game has
// stopped reporting on an NPC it HAS reported on before as an adult with the feature on, one short
// volatile note says so, so the model answers in words instead of filling the silence by itself
// (lrgVolatileGuidance / $turn['blind_note']). It names no action and permits no wording, and the
// static <personal_boundaries> block still stays empty. Every other silence injects nothing at all.
// [0.5.4 / 0.5.5] Since then a silent turn may also carry the escort directive, the blind turn's one-line
// "not right now" answer to a recognised request, and the note that an order of hers visibly failed
// (PROTOCOL 6.2 lists them). The VOICED funcret turn (mode `voiced`) injects nothing here: its cue is set
// from prompts.php (lrgVoicedCue).
require_once __DIR__ . '/lib/lrg_actions.php';
// [pt17-replies] NEVER EMPTY: main.php:2540 runs this file on EVERY request, functions enabled or not, and it runs
// before call_llm() (main.php:2817) - so the validator / retry seams of lib/lrg_replies.php are in place for the
// voiced funcret turn as well. Idempotent with the registration in functions.php.
require_once __DIR__ . '/lib/lrg_replies.php';
lrgNeRegister();

// [0.5.5 / owner addendum 11] A glue failure that preprocessing decided to VOICE reaches this point on its way
// to CHIM's funcret follow-up turn (processor/funcret.php, main.php:2657). It is stopped HERE when that turn
// must not happen after all - the request is for another character than the one the result belongs to, or
// CHIM's catalog row has no follow-up yet (rows older than actions v11) - and she is told on her next turn.
if (strtolower((string) ($GLOBALS['gameRequest'][0] ?? '')) === 'funcret' && lrgVoicedGate() !== '') { terminate(); }

// functions are disabled for lrg_scenetalk and funcret (main.php:1078), so the functions.php hook may
// not have run: build the turn here, otherwise those lines are generated without scene notes/boundaries
if (!isset($GLOBALS['LRG_TURN']) && lrgEnabled() && !defined('LRG_SHARMAT_PRESENT')) { lrgPrepareTurn(); }
$lrgTurn = $GLOBALS['LRG_TURN'] ?? null;
if ($lrgTurn && lrgEnabled() && !defined('LRG_SHARMAT_PRESENT')) {
    if (function_exists('chimRegisterPromptInjection')) {
        $lrgStatic = lrgStaticGuidance($lrgTurn);
        if ($lrgStatic !== '') { chimRegisterPromptInjection('character_bottom', 'lorerim_glue_boundaries', $lrgStatic, 200); }
        $lrgVolatile = lrgVolatileGuidance($lrgTurn);
        if ($lrgVolatile !== '') { chimRegisterPromptInjection('prompt_bottom', 'lorerim_glue_moment', $lrgVolatile, 900); }
    }
    // R7 token cap on scene / follow / initiative turns; CHIM's word-scoring refusal filter off for glue-guided turns (R5)
    lrgApplyTurnRuntime($lrgTurn);
}

// [pt19 / script 512] QUIET MODE, NEVER SILENT - at brace depth 0 like the follower policy that prepares it (functions.php:81
// -> lib/lrg_core.php lrgQuietPolicy): the one prompt_bottom line that gives the model the reason for the withheld movement
// actions. Nothing is injected when the game did not say quiet=1 for this NPC.
if (lrgEnabled() && function_exists('chimRegisterPromptInjection')) {
    $lrgQuietLine = trim((string) (((array) ($GLOBALS['LRG_QUIET'] ?? []))['line'] ?? ''));
    if ($lrgQuietLine !== '') { chimRegisterPromptInjection('prompt_bottom', 'lorerim_glue_quiet', $lrgQuietLine, 905); }
}

// ---------------------------------------------------------------------------------------------------
// [0.4.0] PHASE 2 (menuless questing). Outside the SHARMAT guard above on purpose: the business blocks
// must be injected on both branches (design 6.5). Two injections only, with their own ids, so Phase 1's
// two are untouched: <real_business> is static and cacheable, everything about this moment is volatile.
require_once __DIR__ . '/lib/lrg_dialogue.php';
if (lrgDlgEnabled()) {
    if (!isset($GLOBALS['LRG_DLG_TURN'])) { lrgDlgPrepareTurn(); }
    $lrgDlgTurn = $GLOBALS['LRG_DLG_TURN'] ?? null;
    if (is_array($lrgDlgTurn) && function_exists('chimRegisterPromptInjection')) {
        $lrgDlgStatic = lrgDlgStaticGuidance($lrgDlgTurn);
        if ($lrgDlgStatic !== '') { chimRegisterPromptInjection('character_bottom', 'lorerim_glue_business_rules', $lrgDlgStatic, 210); }
        // [0.5.0 / E7] THE LOCKED FACTS, in CHIM's own injection slot. Priority 205 sits between our
        // boundaries (200) and our business rules (210), so the facts are read BEFORE the rules that
        // refer to them. What CHIM itself calls "fact locking" (lock_profile, relationships_locked) is
        // write protection on the owner's authored profile, not read-time truth enforcement - see the
        // inventory in V05_EXPANSION_PLAN section 19.1. This block is the read-time half, capped at
        // dialogue.truth.max_chars (600) and carrying nothing the game has not confirmed this moment.
        $lrgDlgLocked = lrgDlgLockedBlock($lrgDlgTurn);
        if ($lrgDlgLocked !== '') { chimRegisterPromptInjection('character_bottom', 'lorerim_glue_locked_facts', $lrgDlgLocked, 205); }
        $lrgDlgVol = lrgDlgVolatileGuidance($lrgDlgTurn);
        if ($lrgDlgVol !== '') { chimRegisterPromptInjection('prompt_bottom', 'lorerim_glue_business', $lrgDlgVol, 910); }
    }
    // design 4.6 layer 2: the mute. CHAINED - any transformer prompt.includes.php:68 already installed
    // keeps running, and ours only ever returns '' when the pick will really be emitted this turn.
    if (!isset($GLOBALS['LRG_DLG_TRANSFORMER'])) {
        $lrgDlgPrev = $GLOBALS['TRANSFORMER_FUNCTION'] ?? null;
        if (is_callable($lrgDlgPrev)) { $GLOBALS['LRG_DLG_PREV_TRANSFORMER'] = $lrgDlgPrev; }
        $GLOBALS['LRG_DLG_TRANSFORMER'] = static function ($s) { return lrgDlgTransformer((string) $s); };
        $GLOBALS['TRANSFORMER_FUNCTION'] = $GLOBALS['LRG_DLG_TRANSFORMER'];
    }
}

// ---------------------------------------------------------------------------------------------------
// [0.5.1 / owner addendum 10] <companion_status>. OUTSIDE the SHARMAT guard, exactly like the Phase 2
// blocks: who travels with the player is not an intimacy fact and it has to be true on both branches.
// Priority 204 puts it just before the locked facts (205) and our boundaries (200), so a rule that
// refers to "his companion" is read after the statement that she is one.
// It emits nothing at all unless the game's own fol= block says she really travels with him.
if (lrgEnabled() && function_exists('chimRegisterPromptInjection')) {
    $lrgFolNpc = (string) ($GLOBALS['HERIKA_NAME'] ?? '');
    $lrgFolBlock = lrgFollowerBlock($lrgFolNpc, lrgFolFor($lrgFolNpc));
    if ($lrgFolBlock !== '') {
        chimRegisterPromptInjection('character_bottom', 'lorerim_glue_companion', $lrgFolBlock, 204);
    }
}

// CHIM attaches IdleDialogueExpressiveStart to 1 reply in 6 (lib/chat_helper_functions.php:1667-1673);
// on an actor OStim is animating that idle can break the scene pose. Marking it as already sent blocks it.
$lrgReq3 = (string) ($GLOBALS['gameRequest'][3] ?? '');
if (lrgEnabled() && !defined('LRG_SHARMAT_PRESENT') && (!empty($lrgTurn['scene']) || lrgGetActiveScene() !== null || stripos($lrgReq3, 'ExtCmdLRG_') !== false
    || strtolower((string) ($GLOBALS['gameRequest'][0] ?? '')) === 'lrg_scenetalk')) {
    $GLOBALS['SCRIPTLINE_ANIMATION_SENT'] = true;
}
