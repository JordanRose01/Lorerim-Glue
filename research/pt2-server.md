# Playtest pass 2 - server side (PHP)

Tool calls used: 48 of 66. All seven priority items done. `php -l` clean on every server file and both test files;
`test_gates.php` 80 passed / 0 failed; `test_scene_index.php` ALL CHECKS PASSED.

## Files changed
- `glue/server/lorerim_glue/lib/lrg_actions.php` (rewritten: v6 rows, clothing, lead tick, name search fallback, new prompt text)
- `glue/server/lorerim_glue/lib/lrg_core.php` (LRG_ACT_CLOTHING, progression memory, lrgPace / lrgTalkStyle / lrgTierCeiling / lrgRequestText, snapshot default 90)
- `glue/server/lorerim_glue/lib/lrg_scene_index.php` (lrgSceneWords, lrgTextTermGroups, lrgFindSceneByText, lrgPositionWords)
- `glue/server/lorerim_glue/prerequest.php` (NEW hook file - main.php:1117)
- `glue/server/lorerim_glue/preprocessing.php` (lrg_log; loads the scene index when storing scene events)
- `glue/server/lorerim_glue/prompts.php` (unpoetic cues; separate lead cue; FORCE_MAX_TOKENS 140 on the lead tick)
- `glue/server/lorerim_glue/globals.php` (lrg_log is a fast command)
- `glue/server/lorerim_glue/functions.php` (SHARMAT guard hides all three glue actions)
- `glue/server/lorerim_glue/config/lrg_config.default.json` (snapshot 90; scene_talk explicitness 2 + new explicitness_words + talk_words; scene_progression; scene_index.synonyms / position_words)
- `glue/tools/test_gates.php` (+sections 11-16), `glue/tools/test_scene_index.php` (+positions by name)

## 1. Dialogue style
- `lrgSceneNotes()` now carries a style block used ONLY while a scene with this NPC runs AND the snapshot confirms `adult=1`
  (new fail-closed check in `lrgPrepareTurn`: no adult confirmation = no scene notes, no scene actions).
  Instructions, no example lines: talk like a real person in the middle of it; plain, blunt, physical, own voice, fragments / breath fine;
  BANNED list (metaphors, similes, flowery / romance-novel wording, poetic vocabulary, feelings described from outside, narrated actions, speeches).
- Explicitness: config `scene_talk.explicitness` default 2; the MCM `x=` in the scene payload still wins. Level texts are in
  `scene_talk.explicitness_words` (0 never names anything, 1 mild everyday words, 2 common crude words expected, no euphemisms).
- Manner: `scene_talk.talk_words[quiet|normal|vocal|crude|romantic|never]`; `lrgTalkStyle()` = profile/override `talk`, else
  strict/guarded -> quiet, relaxed/open -> vocal, otherwise normal. A quiet character is told to stay terse rather than crude.
- Speech-only scenetalk turn = 3 lines, ~1030 chars. Action text is only added on turns where actions are offered.
- Outside a scene: gate-ok private moment gets ONE line asking for short, plain, unpoetic speech - nothing explicit is instructed
  (test 15 asserts no crude/explicit/vulgar wording there). Non-adult / child nearby / feature off: still total silence (tests 4, 9c).
- `prompts.php` cues rewritten to the same register (no "murmurs").

## 2. Positions by name
- `lrgFindSceneByText($text, $current, $sexes, $furniture, $maxTier, $prefer = [])` searches the whole index: same actor count as the
  current scene, sex / furniture compatible, not excluded, not a transition. Scores request words against tags + action types (3),
  actor tags and name / id words (2), prefix match for words >= 4 letters, phrase synonyms first ("from behind", "all fours", "on top").
  Returns `['id','label','tier','score']`, `['too_soon'=>true,'tier'=>..]` when the only matches are above the ceiling, or null.
  19 searches = 7 ms. Synonym table `scene_index.synonyms` was built from the printed vocabulary of the installed packs.
- `lrgResolveControl($item, $options, $ctx)`: after stop / P-keys / pace words / label echo it falls back to the search.
  `warp=1` is added when the target is neither in the live `next` list nor within 3 index hops under the ceiling.
  `hold` / `release` now only match as the whole item, so "hold me" is a position request.
- Scene notes list `P1..P8` plus "or name any position in plain words (for example: ...)" - up to 10 words from
  `scene_index.position_words` that really resolve for this pair / furniture / ceiling.

## 3. Progression
- `lrgStoreScene` -> `lrgTrackProgression`: `_maxtier` and `_tier_since` live inside the lrg_scene_state payload JSON; reset on
  `ev=start`, closed previous row, or cid change; a higher tier restarts the clock.
- `lrgTierCeiling()`: ceiling = reached + 1 once the NPC's pace time has passed (`scene_progression.pace_seconds` slow 90 / normal 45 / eager 20;
  `lrgPace()` = profile `pace`, else from strictness). When the PLAYER asks aloud the next step is open at once
  (`scene_progression.player_request_skips_wait`, default true) - still only one step, and she may decline. Never two steps.
- The ceiling goes into `lrgSceneOptions` and the text search. Above-ceiling requests are dropped by the post-gate (logged as
  "above the current step") and the notes tell the NPC it is too soon and that she may offer the next step. Notes also say who leads.
- Observation: "missionary" at a foreplay ceiling resolves to `OStim2PMissionaryIdleMF` (tier sensual) - the pose without the act. Intended.

## 4. NPC initiative - POSSIBLE from a hook, implemented
- main.php:1078 sets `$FUNCTIONS_ARE_ENABLED=false` for every type outside its whitelist; the ext `prerequest.php` hook runs at
  main.php:1117, right after. Everything downstream reads the same global (`functions/json_response.php` chimShouldExposePromptActions / setActions,
  `prompts/dialogue_prompt.php:360`, `lib/data_functions.php:6029` call_llm -> processActions). The only later writers are rechat/narration (2139)
  and dead code (2704 `&& false`).
- `prerequest.php` sets `$GLOBALS['FUNCTIONS_ARE_ENABLED']=true` only when: enabled, no SHARMAT, type `lrg_scenetalk`, text `lead`
  (after stripping the "(Context location: ..)" prefix), a scene with THIS NPC is active and the snapshot says adult.
- On that turn `lrgPrepareTurn` keeps ONLY Talk + ChangeIntimacy + ChangeClothing (`lrgKeepOnlyActions`). Ordinary scenetalk: no actions, and
  the post-gate drops hallucinated ones (`can_act`). ChangeIntimacy / ChangeClothing rows list `lrg_scenetalk` in `requirements.request_types_any`.
- NOT verified in game: I could not find (within budget) where the core loads `functions/functions.php`, so whether the ext functions.php hook
  runs before or after line 1117 is unknown. Both orders are handled: prerequest re-runs `lrgPrepareTurn()` when ENABLED_FUNCTIONS already exists,
  and context_pre.php still builds the turn when the hook never ran. If the log shows a LEAD turn but no action ever comes back, check that first.

## 5. Clothing
- Row `ExtCmdLRG_Clothing` / `ChangeClothing`, parameter `item`; `LRG_ACTIONS_VERSION` 6 (marker check includes the new row).
- Offered in a scene on player-speech and lead turns; outside a scene only when the BeginIntimacy hard gates pass (so player speech only).
- Post-gate: `lrgResolveClothing()` -> `ok=1;cid=<cid>;do=undress|dress;who=npc|both`; unknown text dropped; display name `ChangeClothing` and JSON parameter handled.

## 6. lrg_log, snapshot age
- `lrg_log` handled first in preprocessing.php, written as `[cid=..] GAME <msg>` (control chars stripped, 600 chars max), works even with the
  feature disabled, then `terminate()`. `msg=` must be the LAST key (it may contain `;` and `=`). Registered as a fast command.
- `snapshot_max_age_seconds` default 90 (config and code fallback).

## 7. Kept
Consent only on player-speech turns for BeginIntimacy; post-LLM gate; SHARMAT idle guard (now all three actions); only start / end lines enter
CHIM's event log; `suppress_placeholder_infoaction` on all three rows.

## For the integrator
1. Deploy the whole `server/lorerim_glue` folder (deploy_server.ps1 already mirrors it, so `prerequest.php` ships). The v6 marker reinstalls the
   catalog rows on the first request; check lorerim_glue.log for "action catalog: rows installed (v6)".
2. A user `lrg_config.json` that overrides `scene_talk.explicitness_words` or `explicitness` keeps the old wording / level - lists are replaced, not merged.
3. Game side, clothing outside a scene: the wire parameter carries no maxwit/folok (protocol A is fixed), so `CmdClothing` should repeat its own
   privacy check before undressing outside a scene.
4. Game side, `lrg_log`: `msg=` last in the payload. `lrg_scene` should carry `cid` on every event (progression resets on a cid change) and may carry `und=0|1`.
5. Lead tick text must be exactly `lead`. Any other lrg_scenetalk text stays speech-only.
6. `warp=1` appeared on most named-position jumps in the offline test (empty live list, 3-hop limit). With `bAllowWarp` off the game should
   try a normal navigation first and report failure through `lrg_log`.
7. MCM explicitness default should become 2 to match the server default (the game's `x=` wins over the config).
8. Pace is tunable per NPC / rule: `"pace": "slow|normal|eager"` in a status rule or `npc_overrides`.
