<?php
// LoreRim Glue - main.php:193, before the LLM semaphore (main.php:243).
// Game -> server messages are handled in lrgHandleGameMessage() (lib/lrg_actions.php, PROTOCOL.md 7.2):
//   lrg_npcstate|ts|gamets|k=v;...   hard facts about the NPC the player deals with        -> stored, request ends
//   lrg_scene|ts|gamets|k=v;...      live OStim scene state (start / change / climax / winddown / end) -> stored, request ends
//   lrg_log|ts|gamets|cid=..;msg=..  game-side diagnostics -> "GAME <msg>" in lorerim_glue.log -> request ends
//   lrg_initiative|..|approach       "the NPC may make a move of her own": admitted (continues to the LLM) or
//                                    dropped HERE, before the lock - an uninterested NPC costs no LLM call and no TTS
//   funcret|..|command@ExtCmdLRG_..  result of a glue command: recorded, then [0.5.5] decided HERE - a success (and any
//                                    result that must stay silent) ends before the lock; a failure the player has to
//                                    hear about passes on to CHIM's own funcret follow-up turn (lrgFuncretVerdict)
// State messages never enter the dialogue pipeline and never touch the event log (no memory pollution).
//   inputtext / ginputtext / narrator_inputtext  the PLAYER speaking: [0.3] the hold clock (G2) and the
//                                    diagnostics line are written HERE, before the lock, and the request
//                                    is then passed through untouched. It must never be 'handled'.
require_once __DIR__ . '/lib/lrg_core.php';

$lrgType = strtolower((string) ($gameRequest[0] ?? ''));

// [0.5.6 / PT15] THE PLAYER'S OWN WORDS ARE REPAIRED FIRST, before any reader below (Phase 2, the intent recogniser,
// CHIM's event log and subtitle): an EMPTY transcript is transcribed again from CHIM's own copy of the recording
// (the NPC would otherwise never answer), a far-too-short one is tried once more, a mangled name ("Lazette") is put
// right. lib/lrg_stt.php; config key stt. Only rewrites $gameRequest[3] when something was repaired, never throws.
if (in_array($lrgType, LRG_PLAYER_SPEECH_TYPES, true) || in_array($lrgType, LRG_NARRATOR_SPEECH_TYPES, true)) {
    require_once __DIR__ . '/lib/lrg_stt.php';
    try { lrgSttRepairRequest(); } catch (Throwable $lrgE) { lrgLog('stt repair failed: ' . $lrgE->getMessage()); }
}

// [0.4.0] PHASE 2 FIRST, and deliberately BEFORE Phase 1's block: the menuless-questing module must keep
// working under SHARMAT, where Phase 1's intimacy half goes idle. lrg_topics / lrg_dlg are handled and the
// request ends here; lrg_dlgtalk is admitted (continues to the LLM) or dropped before the MAIN lock.
if ($lrgType === 'lrg_topics' || $lrgType === 'lrg_dlg' || $lrgType === 'lrg_dlgtalk') {
    require_once __DIR__ . '/lib/lrg_dialogue.php';
    if (lrgDlgHandleGameMessage($gameRequest) === 'handled') { terminate(); }
}
// [0.4.0 / design 4.6] CHIM logs the clicked prompt as the player's own speech, right after the player
// already spoke in their own words. Relabel that one duplicate row; the NPC row is never touched.
if ($lrgType === 'chat') {
    require_once __DIR__ . '/lib/lrg_dialogue.php';
    lrgDlgFilterChat($gameRequest);
}

// [0.3] the guard has to cover player speech too: lrg_* and funcret alone would mean the hold clock is
// never written, "you lead" never clears it, and the narrator WARN never appears.
if (strncmp($lrgType, 'lrg_', 4) === 0 || $lrgType === 'funcret'
    || in_array($lrgType, LRG_PLAYER_SPEECH_TYPES, true) || in_array($lrgType, LRG_NARRATOR_SPEECH_TYPES, true)) {
    require_once __DIR__ . '/lib/lrg_actions.php';
    if (lrgHandleGameMessage($gameRequest) === 'handled') {
        // R7: the scene index is never built inside an LLM request. A snapshot is a fast message (not behind the
        // MAIN lock): this is where a stale or missing index gets its rebuild started (at most one stat per minute).
        if ($lrgType === 'lrg_npcstate' && lrgEnabled() && function_exists('lrgIndexMaybeRebuildAsync')) {
            try { lrgIndexMaybeRebuildAsync(); } catch (Throwable $lrgE) { lrgLog('index rebuild check failed: ' . $lrgE->getMessage()); }
        }
        terminate();
    }
}
