<?php
// LoreRim Glue - main.php:1117, right after the core's request-type whitelist (main.php:1078) has switched
// actions OFF for every type it does not know - including our lrg_scenetalk and lrg_initiative.
//  - scene-lead tick (lrg_scenetalk, text "lead") while a scene with THIS NPC runs and she is the one leading
//  - initiative tick (lrg_initiative) that preprocessing.php admitted for THIS NPC
// -> actions are switched back on for this one request. lrgPrepareTurn() then strips the offer down to the actions
// of that turn mode (plus Talk) and the post-LLM gate still checks every action. Nothing in CHIM is edited:
// FUNCTIONS_ARE_ENABLED is the same global the core itself reads afterwards (functions/json_response.php,
// prompts/dialogue_prompt.php, lib/data_functions.php call_llm). functions/functions.php is loaded later
// (prompt.includes.php:55, from main.php:1615), so in production this runs BEFORE lrgPrepareTurn().
require_once __DIR__ . '/lib/lrg_actions.php';

lrgPrerequest();
// [0.4.0] the same problem for Phase 2's one LLM-triggering type, lrg_dlgtalk ("answer the player's last
// words yourself; your business list is now known"): main.php:1078 switched actions off for it.
require_once __DIR__ . '/lib/lrg_dialogue.php';
lrgDlgPrerequest();
