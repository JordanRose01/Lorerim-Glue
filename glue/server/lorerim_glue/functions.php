<?php
// LoreRim Glue - loaded by functions/functions.php after ENABLED_FUNCTIONS is read from
// the action catalog and BEFORE the core's final filter. This is where the turn mode is decided
// (silent | closed | public | private | follow | scene), where Layer 1 (hard gates) decides what the LLM is even
// allowed to see this turn, and where the post-LLM gate is registered. NOTE: this is not the legacy
// "define actions in globals" hook use - the action rows themselves live in CHIM's action catalog.
require_once __DIR__ . '/lib/lrg_actions.php';

if (defined('LRG_SHARMAT_PRESENT')) {
    // two intimacy plugins with different consent rules must never both act
    lrgHideActions(LRG_GLUE_ACTIONS);
    lrgLog('intimacy module idle: ext/aiagent_nsfw (SHARMAT) is installed');
} else {
    lrgPrepareTurn();
    // register the post-LLM gate exactly once per filter list (the gate must never see its own output)
    if (!isset($GLOBALS['LRG_POSTGATE'])) {
        $GLOBALS['LRG_POSTGATE'] = static function ($actions) {
            return lrgPostProcessActions(is_array($actions) ? $actions : []);
        };
    }
    if (!in_array($GLOBALS['LRG_POSTGATE'], (array) ($GLOBALS['action_post_process_fnct_ex'] ?? []), true)) {
        $GLOBALS['action_post_process_fnct_ex'][] = $GLOBALS['LRG_POSTGATE'];
    }
}

// ---------------------------------------------------------------------------------------------------
// [0.4.0] PHASE 2 (menuless questing) - these three statements are at BRACE DEPTH 0 on purpose, outside
// both SHARMAT branches. That placement is the whole reason design 6.3/6.5 hold: under SHARMAT the if
// branch above runs, lrgPrepareTurn() never runs and $GLOBALS['LRG_TURN'] is never built, so a Phase 2
// gate expressed in Phase 1's terms would evaluate to "not blocked" exactly where ext/aiagent_nsfw is
// running its own scenes. Phase 2 reads only its OWN state.
// Registration order matters too: Phase 1's post-gate is registered above and passes
// ExtCmdLRG_SelectTopic through untouched (integrator edit D-12), then Phase 2's gate sees it.
require_once __DIR__ . '/lib/lrg_dialogue.php';
lrgDlgEnsureActions();
lrgDlgPrepareTurn();
if (!isset($GLOBALS['LRG_DLG_POSTGATE'])) {
    $GLOBALS['LRG_DLG_POSTGATE'] = static function ($actions) {
        return lrgDlgPostProcessActions(is_array($actions) ? $actions : []);
    };
}
if (!in_array($GLOBALS['LRG_DLG_POSTGATE'], (array) ($GLOBALS['action_post_process_fnct_ex'] ?? []), true)) {
    $GLOBALS['action_post_process_fnct_ex'][] = $GLOBALS['LRG_DLG_POSTGATE'];
}
// [pt17-replies] NEVER EMPTY (owner addendum 11; lib/lrg_replies.php). Brace depth 0, right after the two post-gates
// and BEFORE the hide policies below (those are pre-LLM ENABLED_FUNCTIONS filters, not post-process entries): the
// only post-process filters ahead of this one are LRG_POSTGATE and LRG_DLG_POSTGATE, so it sees the final action
// list of both lanes and runs under SHARMAT too. It also chains CHIM's VALIDATE_LLM_OUTPUT_FNCT / LLM_RETRY_FNCT,
// which run on every LLM turn - the voiced funcret turn included, where FUNCTIONS_ARE_ENABLED is false and no
// post-process hook ever runs. context_pre.php registers the same three again (idempotent).
require_once __DIR__ . '/lib/lrg_replies.php';
lrgNeRegister();
// decision D3: action / target / item ahead of message on a business turn, through CHIM's own real hook
// (functions/json_response.php:43-51 calls every HOOKS['JSON_TEMPLATE'] callable at the end of
// chimRefreshJsonResponseState(), after setStructuredOutputTemplate()).
if (!isset($GLOBALS['LRG_DLG_JSONHOOK'])) {
    $GLOBALS['LRG_DLG_JSONHOOK'] = static function () { lrgDlgJsonTemplate(); };
}
if (!in_array($GLOBALS['LRG_DLG_JSONHOOK'], (array) ($GLOBALS['HOOKS']['JSON_TEMPLATE'] ?? []), true)) {
    $GLOBALS['HOOKS']['JSON_TEMPLATE'][] = $GLOBALS['LRG_DLG_JSONHOOK'];
}
// [0.4.1 / owner addendum 8] THE CONVERSATION HOLD, server half - LAST, and at brace depth 0 on
// purpose (like the Phase 2 block above): it must see the final offer of BOTH lanes, it only ever
// removes one code, and under SHARMAT it still has to run because the hold is a game-side feature
// that has nothing to do with which intimacy plugin is installed. While the game is holding THIS
// NPC for a live one-on-one, the model is not offered EndConversation - the single leave cause with
// hard log evidence (research/pt8-walkaway.md 3.1). Off: dialogue.hold_hides_end_conversation.
lrgDlgHideEndConversationOnHold();
// [0.5.0 / E4(b), owner addendum 8] THE MOVEMENT HALF OF THE SAME HOLD, and it must come LAST of all.
// While bConvHold holds her with SetDontMove(true), CHIM may still be told ComeCloser / FollowPlayer -
// and the game-side release is dormant for CHIM's own catalog actions, so she simply refuses to move.
// Evaluated after every other filter, including E6's service hiding, so a service toggle can never put
// one of these actions back on the table while she is held (V05 section 21.5, risk R21).
lrgDlgHoldMovementPolicy();
// [0.5.1 / owner addendum 10] THE FOLLOWER POLICY, and it is deliberately the very last filter of
// all. Brace depth 0 for the same reason Phase 2's block is: it has nothing to do with which
// intimacy plugin is installed, and under SHARMAT lrgPrepareTurn() never runs. It reads only the
// snapshot's fol= block, it only ever REMOVES (MakeFollower where the framework would refuse the
// recruit anyway; MakeFollower / Follow / FollowPlayer while she is half-recruited), and an absent
// fol= - an older game script, or bFollowerAware off - changes nothing at all.
lrgFollowerPolicy();
