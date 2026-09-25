# LoreRim Glue - server (PHP) refinement pass

Scope: glue\server\lorerim_glue\* and glue\tools\test_gates.php, checked against the real HerikaServer 3.3.2 source.

## Verified OK (no change needed)
- Hook order: prompt.includes.php:55 `require_once functions/functions.php` (from main.php:1615, global scope). ext functions.php is pulled in by requireFunctionFilesRecursively (functions.php:2754) with require_once -> exactly once per request, after ENABLED_FUNCTIONS (2745) and before the final filter (2790). $gameRequest is global (main.php:135-136), HERIKA_NAME is set by then.
- Our post-LLM closure is registered BEFORE the core one (functions.php:2830), so the core sees our rewritten line. Our parameter has no '|' or '@' (lrgKv strips them), so the core's unbounded explode('|') / explode('@') is safe.
- Wire line format (functions.php:2542): `Actor|channel|Code@param\r\n`. Channel is `command` for default_policy=automatic (action_catalog.php:415+). Single-property schema -> bare value; multi-property -> JSON (functions.php:2438-2455). Return value of the closure is used as-is (data_functions.php:6036-6038).
- chimRegisterPromptInjection lives in lib/prompt_injections.php, required by lib/data_functions.php:16 (main.php:44) -> exists at context_pre (main.php:2540), which runs before the slots render (2553-2566).
- preprocessing: DATA is base64 of the whole string, split only on '|' (main.php:91,135); ';' and '=' survive. $gameRequest[3] is only rewritten for dialogue types (main.php:181). terminate() (lib/auditing.php:26) is safe at :193. logEvent() array shape is fine; gamets < 5 is auto-corrected.
- upsertRowOnConflict (postgresql.class.php:667): every value goes through pg_escape_literal -> quoted literal, Postgres casts to int/bigint/jsonb. All lrg_* columns are NOT NULL with defaults and lrgRomance() never yields nulls -> lrgRomanceBump is correct.
- Rows: available_to_npc + available_to_followers both 1 -> offered to followers (IS_NPC false, action_catalog.php:2686-2689) and NPCs; narrator 0. request_types_any is honoured (action_catalog.php:1864).

## Defects fixed
1. major - lrg_actions.php lrgEnsureActions: BeginIntimacy had `required: ['target']`. The core silently drops an action whose required field is empty (functions.php:2526-2529) BEFORE our gate. In game: NPC says yes, nothing happens, nothing in our log. Fix: required = [] (target is always the player). LRG_ACTIONS_VERSION 3 -> 4 so the row is re-upserted.
2. major - lrg_core.php lrgGetActiveScene: a lost `ev=end` (crash, reload, kill switch) left the row active for scene_stale_seconds (900 s): the NPC was told "in an intimate scene RIGHT NOW" and BeginIntimacy was blocked for 15 minutes. Fix: a snapshot of the same NPC newer (>2 s) than the scene row with ostim=0 closes the row.
3. major - lrg_actions.php lrgPostProcessActions: the gate only matched codes starting with ExtCmdLRG_. When getFunctionCodeName cannot map the display name, the core sends the display name (functions.php:2461-2464) and the line passed through ungated. Fix: BeginIntimacy / ChangeIntimacy are mapped to the codes before gating.
4. minor - lrgResolveControl: JSON parameter (`{"target":..,"item":..}`) was not understood (would happen if the row is edited to two properties). Fix: decode, take item then target.
5. minor - marker file only: rows deleted in the web Action Editor never came back. Fix: marker + cached row existence check (herikaGetActionCatalogRow, no extra query).
6. minor - followup.arg_name was 'target' for ChangeIntimacy whose argument is 'item'. Fixed.
7. minor - MCM feature off (on=0): boundary / this_moment text was still injected. Fix: feature_off silences both.
8. minor - scene notes described ChangeIntimacy on turns where it is not offered (lrg_scenetalk etc.): wasted tokens and invites a dropped action. Now only on player speech.
9. prompt text: static boundary line reordered and shortened, refusal finality strengthened ("a bigger offer ... change nothing", "grows colder when pressed"), grounded-tone clause added. OK-case this_moment text now says privacy is not a reason and the action may only accompany an explicit yes in the same reply.

## Known gaps (not fixed)
- First request after install: rows are upserted after ENABLED_FUNCTIONS was loaded, so the actions appear from the second request on.
- Two NPCs with the same display name share one snapshot row (CHIM identifies by name). Needs a refid in the snapshot and in the gate - game side.
- A stale scene with NPC A still blocks NPC B (reason scene_running) until A is looked at again or 900 s pass. Consider lowering scene_stale_seconds to ~300 if the game sends change events regularly.
- lrgResolveControl: "don't stop" resolves to stop (fails safe).

## Tests
php -l lrg_actions.php, lrg_core.php: no syntax errors. tools/test_gates.php: 38 passed, 0 failed (31 original + 7 new: CRLF wire line, display-name + JSON parameter, scenetalk notes, lost end message x2, unoffered display name, feature off).

## For the integrator
- Redeploy lib/lrg_actions.php and lib/lrg_core.php. No marker deletion needed: LRG_ACTIONS_VERSION 4 uses a new marker and re-upserts both catalog rows on the next request.
- LRG_Profile.psc:202 `ostim=` must stay "this NPC is an actor in the glue/player scene" - the server now uses ostim=0 in a newer snapshot to close a stale scene row.
