# LoreRim Glue 0.1.0 - refinement report (2026-09-21)

Four specialists (Papyrus, server, scene index, integration analyst) reviewed the built mod against the installed CHIM / OStim sources. This is the integrator's summary: what was changed on top of their work, what was verified, what was left alone, and what to watch on the first in-game run. Specialist detail: `research\refine-papyrus.md`, `refine-server.md`, `refine-sceneindex.md`, `refine-integration.md`.

State: compiled, all offline tests pass, server plugin redeployed, MO2 mod refreshed (MO2 was closed; backups `*.bak-20260921-040923`). Still never run in game.

## Changes made in this pass

### Major
- **Scene talk and follow-up lines had no scene notes** (`server/lorerim_glue/context_pre.php`). CHIM disables functions for `lrg_scenetalk` and `funcret` requests, so the hook that builds the turn might not run and the NPC would speak with no description of the scene, no explicitness setting and no boundaries. `context_pre.php` now builds the turn itself when it is missing (guarded by the kill switch and the SHARMAT check).
- **CHIM's random talk gesture could break the scene pose** (`context_pre.php`). CHIM attaches `IdleDialogueExpressiveStart` to 1 reply in 6. During a scene, on `lrg_scenetalk`, and on the follow-up to an `ExtCmdLRG_*` command the plugin now marks the animation as already sent, so CHIM does not add it.
- **Every pace/position change and error went into CHIM's event log and memory** (`lib/lrg_actions.php`). Added `suppress_placeholder_infoaction` to both action rows, so only the glue's own start and end lines enter memory. `LRG_ACTIONS_VERSION` is now 5 (the server specialist had already used 4), so the rows are re-installed on the next request.
- **Positions and start scene are now filtered by the participants' sexes and furniture** (`lib/lrg_actions.php`, new helper `lrgSexes`). Uses the new snapshot key `psex` (player) and `sex` (NPC); unknown values fall back to the old unfiltered behaviour. Furniture `none` is normalised to empty. Without this, a female player or a same-sex pair could be offered positions OStim then declines.

### Minor
- **Double speech** (`game/.../LRG_OStim.psc`): on a glue-started scene the follow-up line and the "start" scene talk fired together; same after a conversational position change. Scene talk is now skipped for a glue-started "start", and a control command resets the talk timer.
- **Scene-talk cue text** (`prompts.php`): the DLL prefixes the text with `(Context location: X)`; the prefix is stripped so the cue reads cleanly.
- **README**: test counts, first-request note (rows are offered from the second request on), new config keys, refreshed known gaps.

## Verified
- `tools\compile.ps1`: OK, all six .pex rebuilt.
- `tools\test_gates.php`: 38 passed, 0 failed.
- `tools\test_scene_index.php`: ALL CHECKS PASSED (607 scenes).
- `php -l`: `context_pre.php`, `prompts.php`, `lib/lrg_actions.php` clean; the deploy script lints again on the server.
- `deploy_server.ps1`: deployed. `install_mo2.ps1`: ran, entries already present, ESP not regenerated.

## Deliberately not changed
- **Afterglow on "stop"**: the index can pick a wind-down scene, but "stop" must end the scene at once (non-negotiable), so it is not wired in. It would need a separate "wind down" option; that is a feature, not a fix.
- **Sorted thread actors**: already done (`LRG_OStim.psc` uses `OActorUtil.Sort` before `OThreadBuilder.Create`). No change needed.
- **`psex` snapshot parsing**: the server stores snapshots as generic key=value, so the new key is accepted without a parser change.
- **`scene_stale_seconds` 900 -> 300**: not changed; it is unproven that the game sends scene events often enough, and a too-short value would drop a live scene. The server already treats a newer `ostim=0` snapshot as proof the scene ended.
- **Re-asserting `setAnimationBusy` on scene change**: left for the playtest, as the analyst advised. `setLocked` is never used (it mutes the NPC).
- **Snapshot with `ostim=0` during the async scene start**: not chased; the next scene event re-activates the row, so it self-heals.
- **Display-name keying** (two guards share a row): needs a refid in the game snapshot; out of scope for this pass.

## Watch on the first in-game run
1. Server log `lorerim_glue.log`: `action catalog: rows installed (v5)` and `scene index rebuilt` on the first request. Send one throwaway line first; actions are offered from the second request on.
2. Does `ExtCmdLRG_StartIntimacy` reach the game (game log line with the same `cid`)? This is the one delivery path still unproven.
3. During a scene: the NPC's line describes the real position (scene notes present), no hand-gesture idle breaks the pose, no two lines spoken on top of each other.
4. After a scene: CHIM's memory/diary holds one start and one end line, not every pace change.
5. Female player or same-sex pair: the offered positions actually play. If few are offered and OStim's MCM "intended sex only" is off, set `scene_index.intended_sex_only` to `false` in `lrg_config.json`.
6. If scene talk never fires: search `AIAgent.log` for `is on conversation cooldown` / `ACTOR IN SCENE (not allowed) by conf`.
7. If the NPC walks off or re-paths mid-scene: apply the prepared `setAnimationBusy(1, partnerName)` re-assert in `OnOStimSceneChanged`.
