# LoreRim Glue 0.1.0 - integration-risk review (game <-> AIAgent.dll <-> HerikaServer)

Read-only review. No project file was edited. Evidence: CHIM 3.3.2 Papyrus sources, `strings` of AIAgent.dll (line numbers = `strings -n 4` output), HerikaServer source, Apache config in the distro. HS = /var/www/html/HerikaServer.

## Cleared (no change needed)

| # | Question | Verdict | Evidence |
|---|---|---|---|
| 1 | Does the DLL forward arbitrary type strings? | YES, safe. | CHIM's own Papyrus sends types that do not exist anywhere in the DLL: `togglemodel` (AIAgentPapyrusFunctions.psc:441,444), `status_msg` (AIAgentItemAliasScript.psc:32), `diary_narrator` / `updateprofile_narrator` (AIAgentPapyrusFunctions.psc:195,232) - all 0 hits in DLL strings. The DLL has the generic template `{}|{}|{}|{}` directly after the `logMessage` literal (strings 46608-46612). "Unknown configuration code" (46605) belongs to `setConf` only. Server: nothing rejects unknown types before main.php:193; only 4 deprecated types exit early (main.php:154). No fallback type needed. |
| 2 | Payload size | Safe. | Request is `GET comm.php?DATA=<base64>&profile=<md5>` (DLL 47696); distro has `LimitRequestLine 65535` (/etc/apache2/sites-available/000-default.conf:30). 1.3 KB -> ~1.8 KB base64. Server reads the raw query string so base64 '+' is fine (main.php:88-93). |
| 2b | Characters | `|` is the only fatal one game->server (main.php:135 unlimited explode). `@` is fatal only inside funcret (funcret.php:101 unlimited explode: command@Code@param@result). Our k=v;k=v lists contain neither. `;` `=` `,` spaces, apostrophes are inert on both sides. Double quote in funcret field 2 breaks the tool-call JSON (funcret.php:165) - already avoided. |
| 3 | Bridge resolution | `ExtCmdLRG_StartIntimacy` -> script `LRG` (C++ `find('_', 6)`, research/chim-docs-web.md:132,350; DLL literals 46049+). Mod-event fallback only if the static call fails. The glue's 5 s de-dup is fine because every param carries a unique cid. |
| 3b | Async start, commandEnded 20 s late | Harmless. "busy with command" is only consulted by Brawl / MoveTo (DLL 45953, 45961); speech is not gated by it. A pending command actually protects the NPC from MoveTo while the scene is starting. |
| 4 | Follow-up loop | Cannot loop. funcret is not in the action-enabled type list (main.php:1078) and `use_functions_again` is false (funcret.php:139). |
| 5 | setLocked vs setAnimationBusy | Keep setAnimationBusy. setLocked(1) mutes the NPC: psc comment "1 locks agent for talking" (AIAgentFunctions.psc:32) and DLL "[SPEAKERMANAGER] {} is locked, cannot speak" (47607). NEVER use setLocked for scenes. Both flags are cleared by CHIM's package-reset function (AIAgentAIMind.psc:61-62). |

## Ranked risks with patches

### R1 - MAJOR - likely - scene notes / boundaries may be missing on `lrg_scenetalk` and `funcret` turns. DO IT NOW
context_pre.php only injects when `$GLOBALS['LRG_TURN']` exists, and that is set only by the ext functions.php hook (lrg_actions.php:84-113). main.php forces `$FUNCTIONS_ARE_ENABLED=false` for every type outside the player-speech list (main.php:1078) and main.php has no direct include of functions/functions.php (it is pulled in conditionally further down the chain). If the hook does not run on `lrg_scenetalk` / `funcret`, scene talk is generated with NO scene description, NO explicitness setting and NO boundary text - the NPC would improvise blind. Defensive patch, harmless if the hook did run:

File `server/lorerim_glue/context_pre.php`
old: `$lrgTurn = $GLOBALS['LRG_TURN'] ?? null;`
new:
```php
// the functions.php hook is not guaranteed on every request type (funcret, lrg_scenetalk)
if (!isset($GLOBALS['LRG_TURN']) && lrgEnabled() && !defined('LRG_SHARMAT_PRESENT')) { lrgPrepareTurn(); }
$lrgTurn = $GLOBALS['LRG_TURN'] ?? null;
```
First playtest check: server log must show scene notes on a scenetalk turn (add a temporary lrgLog in context_pre if unsure).

### R2 - MAJOR - ~17% of NPC lines - CHIM's speech idle breaks the OStim animation. DO IT NOW
The server attaches `IdleDialogueExpressiveStart` to the first line of a reply with a 1-in-6 chance (HS/lib/chat_helper_functions.php:1667-1673). Played on an actor OStim is animating, it snaps the NPC out of the scene pose. Whether setAnimationBusy(1) suppresses it in the DLL cannot be proven from strings, so block it at the source: the same code skips the animation when `SCRIPTLINE_ANIMATION_SENT` is already true.

File `server/lorerim_glue/context_pre.php`, append at end of file:
```php
// No CHIM talk-idle on an actor OStim is animating (chat_helper_functions.php:1667-1673).
$lrgReq3 = (string) ($GLOBALS['gameRequest'][3] ?? '');
if (lrgEnabled() && ((!empty($lrgTurn['scene'])) || lrgGetActiveScene() !== null
    || stripos($lrgReq3, 'ExtCmdLRG_') !== false || strtolower((string) ($GLOBALS['gameRequest'][0] ?? '')) === 'lrg_scenetalk')) {
    $GLOBALS['SCRIPTLINE_ANIMATION_SENT'] = true;
}
```

### R3 - MAJOR - certain - every ChangeIntimacy / BeginIntimacy result is written to CHIM's event log (memory, diary). DO IT NOW
funcret.php:123 calls chimLogFuncretResultInfoAction for every funcret; with an empty return_message it logs "<NPC> issued ACTION ChangeIntimacy: <result>" (HS/functions/functions.php:2277-2282), errors as "<NPC> issued ACTION, but Error: ..." (2256-2258). This contradicts the design rule "only start and end enter memory" and duplicates the start line preprocessing.php:24-29 already logs. CHIM provides an opt-out flag (funcret.php:62-63 `suppress_placeholder_infoaction`).

File `server/lorerim_glue/lib/lrg_actions.php`
old: `const LRG_ACTIONS_VERSION = 3;`
new: `const LRG_ACTIONS_VERSION = 4;`
old: `        'confirmation' => ['default_policy' => 'automatic'],`
new:
```php
        'confirmation' => ['default_policy' => 'automatic'],
        'suppress_placeholder_infoaction' => true, // results stay out of memory; start/end lines come from preprocessing.php
```
(the version bump makes lrgEnsureActions re-upsert the rows; re-run tools/test_gates.php afterwards.)

### R4 - MINOR/MAJOR - likely - two NPC lines at once. DO IT NOW
On a glue-started scene the funcret follow-up ("React in character...") fires at the same moment as MaybeSceneTalk("start") (chance 35+35 %), giving two overlapping LLM requests/TTS lines; the same happens after a conversational position change (follow-up + "change" talk).

File `game/LoreRimGlue/Source/Scripts/LRG_OStim.psc`, in `MaybeSceneTalk`
old:
```
	float now = Utility.GetCurrentRealTime()
	if (now - lastTalkTime) < m.SettingFloat("fSceneTalkGap:SceneTalk", 25.0)
```
new:
```
	float now = Utility.GetCurrentRealTime()
	if asEvent == "start" && startedByGlue
		lastTalkTime = now ; the funcret follow-up line already covers this moment
		return
	endif
	if (now - lastTalkTime) < m.SettingFloat("fSceneTalkGap:SceneTalk", 25.0)
```
and in `CmdControl`, directly after the `ok` check block (before `if m.IsDryRun() && what != "stop"`), add:
```
	lastTalkTime = Utility.GetCurrentRealTime() ; the follow-up line speaks for this change
```
Recompile with tools/compile.ps1.

### R5 - MINOR - certain - scenetalk cue carries the DLL's location prefix. DO IT NOW (one line)
requestMessageForActor wraps the text as `type|ts|gamets|(Context location: X)...` (DLL 46614-46619), so the cue becomes "((Context location: Whiterun)the moment has just begun)".

File `server/lorerim_glue/prompts.php`
old: `'player_request' => ['(' . trim((string) ($GLOBALS['gameRequest'][3] ?? 'a quiet moment')) . ')'],`
new: `'player_request' => ['(' . trim((string) preg_replace('/^\(Context[^)]*\)\s*/', '', (string) ($GLOBALS['gameRequest'][3] ?? 'a quiet moment'))) . ')'],`

### R6 - MINOR - possible - CHIM clears animation-busy mid-scene. LEAVE FOR FIRST PLAYTEST (optional 3-line patch)
CHIM's package reset clears both flags (AIAgentAIMind.psc:61-62), e.g. after StopCurrent / EndConversation. Optional: in `OnOStimSceneChanged` after the thread-id check add `if partnerName != ""` / `AIAgentFunctions.setAnimationBusy(1, partnerName)` / `endif`. Movement actions are already hidden server-side during a scene (LRG_MOVEMENT_ACTIONS), so this is a belt-and-braces item.

### R7 - LEAVE FOR FIRST PLAYTEST
- requestMessageForActor may be silently dropped by the DLL's availability checks ("{} is on conversation cooldown", 48094; "ACTOR IN SCENE (not allowed) by conf", 48099 - vanilla scenes only, OStim does not use them). If scene talk never fires, look in AIAgent.log for those strings.
- Bored events: the DLL only suppresses them for vanilla scenes / dialogue (48302-48310). A nearby CHIM NPC could start chatting during a scene; the privacy gate makes this rare.
- Input: CHIM's chatbox installs its input sink "at highest priority" (research/chim-dll-esp.md:157), so typing should not leak into OStim hotkeys; a push-to-talk key that collides with an OStim key (speed up/down, end) must be rebound by the user. Do NOT add OThreadBuilder.NoPlayerControl: it removes the player's manual fallback.
- Names with apostrophes/spaces: the ForActor natives send `&profile=md5(name)`; preprocessing.php correctly takes the name from the payload, not from HERIKA_NAME.
- Follow-up wording: "Error: someone is watching" as tool result + "do not try again" prompt reads fine. Keep follow-up enabled for the start action (it is the only place a refusal by the game gate gets voiced).

## Tests
No project code was changed, so no test was re-run. After applying R1-R5 the integrator must run: php -l on context_pre.php, prompts.php, lib/lrg_actions.php; tools/test_gates.php; tools/compile.ps1; then deploy_server.ps1 and install_mo2.ps1.
