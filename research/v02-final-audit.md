# LoreRim Glue 0.2.0 - FINAL AUDIT (fresh eyes, read-only)

Auditor role: verify the integrator's "finished and deployed" claim without trusting it.
Written 2026-09-21. Only file this pass writes. Everything below was re-derived from the
installed sources, the deployed plugin, the installed MO2 mod and the CHIM 3.3.2 server source.

**VERDICT: SHIP** - with six documentation / polish fixes listed in section 6. No rail is broken,
no wire contract is violated in a way that can reach the game, and what the owner will run really
is this build. The FIX items are all owner-facing notes or dead-code polish; none of them needs a
rebuild of the game side, and five of the six are text changes in `PLAYTEST3_NOTES.md` / `README.md`.

---

## 1. Tests actually run (all green)

| Command | Result |
|---|---|
| `tools\compile.ps1` | `compiled: LRG.pex, LRG_Main.pex, LRG_MCM.pex, LRG_OStim.pex, LRG_PlayerAlias.pex, LRG_Profile.pex` - **0 errors, 0 warnings**, 2 rounds, 23 auto-stubs |
| `tools/test_gates.php` (staged, WSL) | **201 passed, 0 failed** |
| `tools/test_scene_index.php` (staged, WSL) | **ALL CHECKS PASSED**, 199 `[ok]` lines, peak memory 9.0 MB |
| `tools/flows/run_flows.php --strict` | **24 scenarios, 24 passed, 0 FAILED, 0 pending; 487 checks, 0 warnings - RESULT: OK** |
| `tools/flows/mutation_check.ps1` | **19 of 19 mutations detected**, 0 stale patterns |
| `php -l` over every plugin + tool PHP file | **35 files linted, 0 failures** |

The README's claimed counts (201 / 199 / 487 / 19) are all exactly right.

## 2. Is what the owner will run really this build?

### 2.1 Server plugin - deployed vs project: **IDENTICAL**
SHA-256 of all 13 files under `glue/server/lorerim_glue` compared against
`\\wsl.localhost\DwemerAI4Skyrim3\var\www\html\HerikaServer\ext\lorerim_glue`:
`config/lrg_config.default.json`, `context_pre.php`, `functions.php`, `globals.php`,
`lib/lrg_actions.php`, `lib/lrg_core.php`, `lib/lrg_scene_index.php`, `manifest.json`,
`migrations/001_lrg_tables.sql`, `migrations/002_lrg_memory.sql`, `preprocessing.php`,
`prerequest.php`, `prompts.php` - **all SAME**.

Runtime state on the server:
- ownership `dwemer:www-data`, setgid dirs, `ug+rwX,o-rwx` - the same shape as CHIM's other `ext/*` plugins.
- `data/.schema_v2` = `2026-09-21T13:58:26+00:00`, `data/.actions_v7` = same second -> schema v2 and the
  four catalog rows really were installed at deploy time (also in the log: `action catalog: rows installed (v7)`).
- `data/scene_index.meta.json`: `{"v":3, "scenes":607, "packs":3, "seconds":5.8, "error":""}`, built 14:03:16 UTC,
  `data/.index_checked` = `fresh`. Dirs scanned = OARE, OStim Community Resource, OStim Standalone.
- `ext/aiagent_nsfw` is **not** present, so the SHARMAT guard is correctly inactive and the module is live.
- No user `config/lrg_config.json` exists (see FIX-1).

### 2.2 Game side - MO2 vs project
- `LoreRimGlue.esp`, `Seq\LoreRimGlue.seq`, `MCM\Config\LoreRimGlue\config.json`, `settings.ini`,
  all six `Source\Scripts\*.psc`: **byte-identical** (SHA-256).
- The six `.pex`: **identical size, identical string table CONTENTS, and - decisive - identical embedded
  DebugInfo `modificationTime` per file, each matching the current `.psc` mtime**
  (`LRG_Main.pex` modTime 13:25:04 == `LRG_Main.psc` 13:25:04 UTC; `LRG_OStim` 13:24:39;
  `LRG_Profile` 13:25:09; `LRG_MCM` 07:47:15; `LRG`, `LRG_PlayerAlias` 07:29:57 / 07:30:01).
  **So the installed `.pex` were compiled from exactly the sources that are on disk now.**
  Installed `.pex` compile timestamp 13:58:17 UTC > every `.psc` mtime -> **the .pex are newer than the .psc.**
  The only byte differences against a fresh recompile are the compile timestamp and the ORDER of the
  string table (the Papyrus compiler emits it from a hash set; the sizes and the string SET are identical).
  See NOTE-A: running `compile.ps1` for this audit replaced the project `.pex` with equivalent ones.
- MO2 profile `Ultra`: `modlist.txt` line 2 `+LoreRim Glue` (bottom of the left pane),
  `plugins.txt` last line `*LoreRimGlue.esp`, `loadorder.txt` last line `LoreRimGlue.esp`.
  Profile backups `*.bak-20260921-095904` present. No other mod touched.
- ESP re-dumped with `tools/esp_dump.py`: TES4 flags `0x00000200` (ESL), HEDR 1.70, nextObjectId `0x802`,
  single master `Skyrim.esm`, QUST `0x01000800` (`LRG_MainQuest`, scripts LRG_Main + LRG_OStim, alias script
  LRG_PlayerAlias) and QUST `0x01000801` (`LRG_MCMQuest`, LRG_MCM, alias SKI_PlayerLoadGameAlias). Unchanged.
- No `MCM\Settings\LoreRimGlue.ini` exists anywhere under `F:\Modlists\LoreRim` -> the `settings.ini`
  defaults (Explicitness = Explicit, Initiative on/90 s, furniture on/1200, lead = she, cold on/10,
  **all three hotkeys unbound**) will apply. PLAYTEST3's conditional wording about Overwrite is correct.

## 3. One full story, walked line by line on both sides

Interested NPC in a public inn -> invitation -> alone -> her move -> gentle scene -> lead tick ->
foreplay pick she names -> gentle pick stays silent -> player names a position -> wind-down -> stop.
Every string below was checked against `glue/PROTOCOL.md`.

**(0) Snapshot.** `LRG_Main.MaybeSnapshot` -> `LRG_Profile.BuildSnapshot(... , PlaceFacts(...))`.
Key order emitted: `v=2;npc;ref;on;adult;sex;lvl;combat;scene;ostim;mate;married;pspouse;courting;rank;
conf;moral;aggr;gold;interior;wit;witfol;witkid;plvl;pgold;pspeech;pdb;psouls;pquests;psex;
x;loc;ltype;cellown;home;nhome;prent;nearf;bedown;sde;class;fac`. Matches PROTOCOL 1.1 exactly:
the ten v=2 keys sit immediately before `class`, `fac` is last. Sent through
`AIAgentFunctions.logMessageForActor(payload,"lrg_npcstate",name)` (verified `Global Native`,
`CHIM/Source/Scripts/AIAgentFunctions.psc:28`).

**(1) Server, public turn.** `preprocessing.php` (CHIM `main.php:193`, verified - the MAIN semaphore is
`main.php:243`, and `globals.php` at `main.php:54` merges `external_fast_commands`, read at `main.php:233`)
-> `lrgHandleGameMessage` stores it, `terminate()`. On the player's next line
`lrgEvaluateGates` -> reasons `['witnesses']` -> `lrgGateMode` -> **`public`**; `lrgPlaces()` builds
`room` (ltype=inn, worded by `prent`/`cellown`) + `quiet` (+`home` when `nhome`/`cellown=npc`).
`lrgPrepareTurn` hides `ExtCmdLRG_StartIntimacy` + `ExtCmdLRG_Clothing`, leaves `ExtCmdLRG_Invite`;
`x` = snapshot `x`, wording capped to level 1 by `lrgWordingLine($turn, true)` (PROTOCOL 6.4). ✔

**(2) Invitation.** The LLM answers `Lisette|command|ExtCmdLRG_Invite@room`. `lrgPostProcessActions`:
mode must be `public` and `places` non-empty -> `lrgResolvePlace` -> `room` -> `lrgMemSet('invite' =>
{at, expires = at+1800, place, loc, state:'pending'})`, the line is **removed from the wire**
(`$out` never receives it). Log: `gate: Invite recorded (place=room, ttl=1800s) - not sent to the game`. ✔
`invitation.lead_the_way` is `false`, so no movement action is substituted.

**(3) Alone.** Next snapshot has `wit=0` -> gates pass -> `lrgGateMode(..., invite pending)` -> **`follow`**.
Offer = `BeginIntimacy` + `ChangeClothing`. Guidance: follow-through, `lrgWordingLine($turn)` at the full
level (they are alone). Reached either by the player speaking or by an admitted `lrg_initiative` tick
(`lrgInitiativeAdmit` kind `follow`, needs `>= follow_gap_seconds` 30 since the last tick - correctly does
**not** require `may_initiate`, PROTOCOL 6.3a). ✔

**(4) Her move.** `Lisette|command|ExtCmdLRG_StartIntimacy@` -> gate requires `$gate['ok']` and mode in
`private|follow` -> `lrgPickStart($sexes, lrgCsv(nearf))` -> emitted param
`ok=1;cid=..;npc=Lisette;scene=<gentle standing id>;undress=1;furn=<bed type or empty>;fscene=<id or empty>;maxwit=0;folok=1`
- key order identical to PROTOCOL 2. `lrgInviteFollowed()` flips the invite to `state=followed`. ✔
Game: `LRG_Main.HandleCommand` (both delivery paths, de-duplicated 5 s) -> `LRG_OStim.CmdStart` ->
`StartBlockedReason` re-checks OStim present / MCM / `ok=1` / no thread / `PairBlockedReason`
(adults, combat, quest scene, 1200 units) / `OActor.VerifyActors` / `PrivacyBlockedReason`
(fresh witness scan against `maxwit`/`folok`; a missing key reads as the strictest value).
Then `PrepareWeapons` (crash workaround) -> `CaptureBaseline` -> `OActorUtil.ToArray`/`Sort` ->
`OThreadBuilder.Create` -> `SetFurniture`+`SetStartingAnimation` **or** `NoFurniture`(+start id)
-> `NoUndressing` when `undress!=1` -> `NoPostDialogue` -> `Start`. All eight natives re-verified in
the installed `OThreadBuilder.psc` at the exact lines PROTOCOL 3 cites. ✔
Result string on success: `They draw close. The scene begins.` (PROTOCOL 1.6). ✔

**(5) Scene state.** `OnOStimThreadStart` -> stops OStim auto mode if on -> `ScanNearFurniture` ->
`MarkDirty("start")` -> **forces a snapshot** so the server has `adult=1` even for menu-started threads.
`PushState` emits `ev;npc;cid;scene;speed;maxspeed;trans;furn;ppos;npos;actors;byglue;und;[who];auto;
leader;stall;ncl;pcl;wd;nearf;undp;tags;acts;x;[next]` - `next` last, absent on transitions, `furn` taken
from `OThread.GetFurniture(0)` + `OFurniture.GetFurnitureType(ref)` (the *specific* type, as PROTOCOL 1.2
insists, not `OThread.GetFurnitureType`). ✔

**(6) Lead tick.** `MaybeLead` fires after `fSceneLeadInterval` (45 s) only when
`leader=="npc" && !lastAuto && !windDown && !navInFlight && !IsInAutoMode && !isActorTalking` - exactly
PROTOCOL 1.4's four suppressors. Sends `requestMessageForActor("lead","lrg_scenetalk",npc)`.
Server: `prerequest.php` at `main.php:1117` (verified to run right after `main.php:1078` switches actions
off for unknown request types) -> `lrgPrerequest()` -> `FUNCTIONS_ARE_ENABLED = true`. `lrgPrepareTurn`
sets `lead=true`, `can_act=true`, `lrgKeepOnlyActions([SceneControl, Clothing])`.
**Timing check done explicitly:** CHIM's `processor/request.php` (which overwrites `$gameRequest[3]` with
`PROMPTS[..]["player_request"]`) is required at `main.php:1708` - *after* `prompt.includes.php` (1615), where
`functions/functions.php` unconditionally loads the ext `functions.php` hook and `LRG_TURN` (with `lead=true`)
is built and memoised. `context_pre.php` (2540) reuses it. So the lead flag cannot be lost. ✔

**(7) R10 - she names a foreplay pick, a gentle pick is silent.** `lrgPrepareTurn` rolls
`announce = lrgRoll('announce') <= announce_chance[talk style]` (quiet 40 / normal 70 / vocal 85 /
crude 90 / romantic 75 / **never 0**) once per request and carries it across the second pass.
`lrgSceneNotes` marks every option `[say it]` only when `announce && lrgIsSayIt(tier)`
(`tier >= sensual`); everything else is `[silent]`, and an unknown tier reads as silent.
When `announce` is false the closing line is "Whatever ... simply happens: leave message empty".
CHIM side verified in source: an action with an empty message produces no TTS and **no filler line** -
`main.php:2831-2846` takes the `sizeof($talkedSoFar)==0 && sizeof($alreadysent)>0` branch, which only
logs (the `returnLines(["Sure thing!"])` fallback is commented out). ✔

**(8) Player names a position.** `ExtCmdLRG_SceneControl@<plain words>` -> `lrgResolveControl` in the
contract's order (stop > winddown > P-key > pace > hold/release > climax > pullout > lead > furniture >
label/id echo > free-text) -> `lrgFindSceneByText` -> `do=goto;scene=<id>` (+`;warp=1` when no confirmed
route). Game `NavigateBlockedReason` -> `OLibrary.GetScenesInRange(cur, actors, iNavigationReach=5)`;
`unreachable` + `bAllowWarp` on -> `OThread.WarpTo(0,id,true)`, else `OThread.NavigateTo`. Refusal only
when the MCM toggle is off. That is the owner's "if I say a position it should go to that position". ✔
Naming the current position: `NavigateBlockedReason` -> `already there`, and the index also returns null.

**(9) Wind-down.** `do=winddown;scene=<afterglow id or empty>;warp=<0|1>;linger=<5..120>` ->
`CmdWindDown`: stops auto mode, `windDown=true`, optional `GoToScene`, `SetSpeed(0,0)`,
`PushState("winddown")` (the only `ev=winddown`), then `WindDownLanded` -> `SetSpeed(0,0)` again ->
`wdStopAt = now + linger` -> `Tick` -> `StopScene`. Cancelled by a later `goto/furniture/climax/lead`
or by a scene change the glue did not ask for. ✔

**(10) Stop.** `CmdControl` answers `stop` **before** the OStim / `ok=1` / partner / adult / dry-run
checks; `starting==true` is handled by `abortStart` and still answers `The scene ends.`; the MCM hotkey
path `LRG_Main.OnKeyDown` -> `StopScene` is not gated by any switch. In the server, `scene_blocked` turns
let **only** a resolved `do=stop` through the post-gate. ✔

**(11) End.** `FinishThread` -> `ev=end;npc;cid;scene;byglue` (exactly the five keys PROTOCOL 1.2 allows)
-> `ResetState()` releases `setAnimationBusy(0,...)` -> `RestoreWeapons` -> `RedressRemembered` ->
`RedressBaseline`. Server: `lrgHandleSceneMessage` closes the row, drops the invitation, bumps
`lrg_romance.scenes` and writes **one** neutral `infoaction` line - and only if an `ev=start` was really
seen under this cid (`lrgSceneWasOpen`), so a cancelled start writes nothing to CHIM's memory. ✔

### CHIM-side facts re-verified from source this pass (not taken on trust)
`chimRegisterPromptInjection` (`lib/prompt_injections.php:10`), `herikaActionCatalogUpsertCustomRow`
(`lib/core/action_catalog.php:3379`, stores `metadata` as jsonb, `ON CONFLICT (code_name) DO UPDATE`),
`herikaGetActionCatalogRow` (:2494), `herikaActionCatalogResetCache` (:2016),
`action_post_process_fnct_ex` (`lib/data_functions.php:6036`, runs only while `FUNCTIONS_ARE_ENABLED`),
`requirements.request_types_any` really is enforced (:1864, `request_type` = `gameRequest[0]`, :1584),
`confirmation.default_policy=automatic` resolves to channel `command` (:437-448),
`followup.enabled=false` -> `processor/funcret.php` `if (!$followupEnabled) { terminate(); }` (no second
LLM call, no second TTS), `suppress_placeholder_infoaction` really suppresses the funcret infoaction
(`funcret.php:50-64`), `checkOAIComplains` returns 0 when `OPENAI_FILTER_DISABLED` is set
(`lib/chat_helper_functions.php:589-594`), `SCRIPTLINE_ANIMATION_SENT` blanks the expressive idle
(:1667-1673), and the `rechat` block that rewrites `ENABLED_FUNCTIONS` (`main.php:2136-2205`) is guarded
by `in_array($gameRequest[0],["rechat","narration"])`, so it cannot undo the glue's pruning.
**Bridge naming confirmed from the DLL**: `AIAgent.dll` contains, adjacent in its string pool,
`ExtCmd` / `DispatchExternalCommand` / `SendExternalEvent` (and `IntCmd` / `SendInternalEvent`), i.e. a
prefix table - so the `ExtCmdLRG_*` + `LRG.DispatchExternalCommand` convention is the right one and the
`CHIM_CommandReceived` fallback exists (`AIAgentAIMind.psc:1395`, three pushed strings, matching
`OnChimCommand(string,string,string)`). The README's "still open" note on this can be closed.
All OStim / SKSE / po3 / MCM Helper / SMI names used were re-grepped in the installed sources
(`OFurniture.FindFurnitureOfType:55`, `OThread.GetFurniture:268`, `OThread.AutoTransitionForActor:152`,
`OActor.VerifyActors:468`, `SurvivalModeImprovedApi.RestoreColdLevel:4` - and `SurvivalModeImproved.esp`
is enabled in profile Ultra; `Game.IsPluginInstalled` `Game.psc:296`; MCM Helper's `MCMHelper.bsa`
contains `GetModSettingBool/Int/Float/String`, `IsInstalled`, `MCM_ConfigBase`, `OnSettingChange`).

## 4. Rails attacked again

| Attack | Result |
|---|---|
| Non-adult snapshot | `lrgEvaluateGates` returns after `not_adult` without computing anything else; `lrgGateMode` -> `silent`; both guidance strings `''`, `x=null`, every glue action hidden, every glue wire line dropped. Inside a live scene an NPC whose snapshot never confirmed `adult=1` gets `lrgHideActions(LRG_GLUE_ACTIONS)` and **no** stop sentence either (the hotkey stays). Game repeats it: `LRG_Profile.IsAdult` fails closed on race/voice/class tokens and is re-checked on every verb but `stop`. Mutation M11 covers it. |
| Child nearby (`witkid=1`) | Same early return; `WitnessScan` aborts the scan the moment it sees a non-adult and reports `witkid=1`. Mutation M17 covers the mid-scene case. |
| Unwilling NPC | `lrgInterest().willing = score >= 0`; `not_close_enough` -> mode `closed`; no invitation, no `x`, boundary text that explicitly counters agreeableness. `lrgInitiativeAdmit` drops the tick before the MAIN lock. Mutations M8/M9/M10/M12/M13 cover it. |
| "stop" | Answered first in `CmdControl`, before `ok=1`/partner/adult/dry-run; works while `starting` (`abortStart`), mid wind-down, and in dry-run; the hotkey path is ungated. The server's blocked-scene mode passes only `do=stop`. A negated "don't stop" is correctly not a stop. |
| Kill switch | `lrgEnabled()` (config) -> total silence + `lrgHideActions`; MCM `bEnabled`/`bKillSwitch` stop every send (`SendNpcMessage`, `LogC`), stop `setAnimationBusy`, stop `ColdTick`, stop scene talk and lead ticks - while OStim keeps playing and the stop key still works (PROTOCOL 4.4). Mutation M11a/11c. |
| SHARMAT | `globals.php` defines `LRG_SHARMAT_PRESENT` if `ext/aiagent_nsfw` exists; checked in `functions.php`, `context_pre.php`, `prompts.php`, `lrgPrerequest`, `lrgPrepareTurn`, `lrgInitiativeAdmit` and the post-gate. Mutations M14 (hook files) and M11d (library) both detect removal. Not installed today. |
| Hallucinated / forged / foreign-actor actions | Dropped unless the code is in `$turn['offered']` **and** the actor matches `$turn['npc']`; `Invite` additionally needs mode `public` + non-empty `places`. Scenario 12, 23 checks. |
| Index content rails | `LRG_HARD_WORDS/GLOBS/INCAPACITATED` live in code, are folded into the index signature (so a rail change forces a rebuild on deploy), and no config value can switch them off. `content_filter=open` only relaxes the *taste* lists (38 scenes). |

## 5. Reading PLAYTEST3_NOTES.md as the owner

Everything in sections 1, 2, 4, 5 and 7 is true as written and matches the code. Section 3's tuning table
matches `lrg_config.default.json` value for value (initiative 90 s/min 30, cooldown 300, chance 15/30/50,
ttl 1800, lead 45 s, explicitness 2, announce 40/70/85/90/75/0, furniture 1200, warmth 10).
Section 6's ten steps are all doable except where FIX-1 and FIX-2 below say otherwise.

## 6. Defects, with evidence and the smallest correct fix

**FIX-1 - MEDIUM - the config override path the owner is given does not work.**
`PLAYTEST3_NOTES.md` section 3 ("The config file is `server/lorerim_glue/config/lrg_config.default.json`;
copy it to `lrg_config.json` beside it to override") and `glue/README.md` ("copy to `lrg_config.json`
next to it") point at the **project** copy. The running server only ever reads
`LRG_DIR . '/config/lrg_config.json'` = `/var/www/html/HerikaServer/ext/lorerim_glue/config/lrg_config.json`
(`lib/lrg_core.php:29`), and `tools/deploy_server.ps1:26` deliberately excludes that filename from the
copy (`robocopy ... /XF lrg_config.json`). So a file created where the notes say has **no effect, ever**.
Three separate playtest instructions depend on it: section 6 step 2 (`scene_start.prefer_furniture` ->
`"never"`), section 6's last line (`"initiative": {"log_drops": true}`) and section 3's
`invitation.lead_the_way`. Verified: no `lrg_config.json` exists in either location today.
*Fix:* in both documents give the absolute path
`\\wsl.localhost\DwemerAI4Skyrim3\var\www\html\HerikaServer\ext\lorerim_glue\config\lrg_config.json`
and add "it takes effect on the next request; a redeploy never overwrites it".

**FIX-2 - LOW/MEDIUM - PLAYTEST3 section 6 step 8 asks for something the build cannot do by itself.**
"Then walk to that place with her and wait: she should follow through." Nothing in 0.2.0 makes the NPC
move: `invitation.lead_the_way` is `false` by default (and section 7 admits it is untested), and the
glue never emits a movement action otherwise. Worse, `LRG_Main.SnapTick` drops the watch when the NPC is
further than 1500 units, so an NPC left behind gets no further initiative tick at all.
*Fix:* one clause - "ask her to follow you first (CHIM's own 'follow me'); she will not lead the way in
this build" - in step 8.

**FIX-3 - LOW - the shared log mixes two time zones and is not in chronological order.**
`lrgLog()` uses `date()`, and PHP's timezone differs between Apache (UTC) and the CLI used by
`deploy_server.ps1` (UTC+2). Evidence from the live log, same deploy run:
`2026-09-21 13:58:26 action catalog: rows installed (v7)` followed by
`2026-09-21 15:58:39 [cid=deploy] GAME smoke test`. Older entries show the same 2 h split
(`09:50:49 schema ensured (v1)` vs `07:50:53 action catalog: rows installed (v3)`).
PLAYTEST3 section 6 tells the owner to read this file.
*Fix:* either `gmdate()` in `lrgLog()` (one word, server-side only) or a sentence in the notes:
"timestamps are UTC; correlate by `cid=`, not by clock time".

**FIX-4 - LOW - a lead tick on a rail-blocked scene leaves CHIM's other actions switched on.**
`lrgPrerequest()` (`lib/lrg_actions.php:332-338`) sets `FUNCTIONS_ARE_ENABLED = true` for a lead tick
after checking only that the stored snapshot says `adult=1` - it does not repeat the scene branch's other
rails (snapshot age, `witkid`, `on=1`, profile `never`). When `lrgPrepareTurn()` then takes the
`scene_blocked` branch (`:392-405`) it calls `lrgHideActions(...)` but never `lrgKeepOnlyActions(...)`,
so every non-glue CHIM action stays offered on a turn the glue itself declared blocked - whose contract
(PROTOCOL 6.2, last row) is "one sentence: choose stop if the player asks. No scene description, no
options". No rail is broken (no glue command can pass, and the guidance is stripped), but the NPC can
pick an unrelated CHIM action on that tick.
*Fix:* in the `scene_blocked` branch add `if (lrgIsLeadTick()) { lrgKeepOnlyActions([]); }` right after
the existing `lrgHideActions(...)` - or have `lrgPrerequest()` require the same four rails before
switching functions on.

**FIX-5 - LOW - two wire keys the game pays for are never read.**
`bedown` (snapshot 1.1) and `stall` (scene 1.2) are emitted by `LRG_OStim`/`LRG_Profile` and specified in
PROTOCOL, but `grep` over the whole server plugin finds **no** reader for either.
Consequences: (a) `lrgPlaces()` cannot tell "her bed" from a stranger's bed, which is what `bedown` was
specified for; (b) after "hold it there" the NPC is never told a climax is being held back, so on a lead
tick she may offer `climax`/`release` blind. `bedown` also costs two extra natives per furniture scan.
*Fix (behaviour side, cheapest):* add one clause to `lrgSceneNotes` - when `($sc['stall'] ?? '0') === '1'`,
say the peak is being held back and that `release` undoes it. Leave `bedown` as documented-but-unused, or
drop it from PROTOCOL 1.1 and from `LRG_OStim.FurnitureFacts`.

**FIX-6 - LOW - "take mine off" maps to the wrong actor when the model echoes it.**
`lrgResolveClothing` (`lib/lrg_actions.php:766-767`) only yields `who=player` for
`undress you|me|the player`, `^you|me|player`, or `off|from you|me`. PLAYTEST3 section 2 lists
"take mine off" as a phrase the owner can say; echoed verbatim into `item` it resolves to `who=npc`, so
**she** undresses instead of the player. (The action description tells the model to write "undress you",
which resolves correctly - this only bites when the model parrots the player.)
*Fix:* add `|\bmine\b|\bmy (?:clothes|armou?r|boots|gloves|helmet)\b` to that player pattern, or remove
"take mine off" from the table in PLAYTEST3.

### Notes (not defects)
- **NOTE-A:** running `compile.ps1` as instructed replaced the project's six `.pex` with fresh ones. They
  are behaviourally identical to the installed ones (same size, same string set, same embedded source
  mtimes) but differ byte-wise (compile timestamp + string-table order). No re-install is needed; if
  `install_mo2.ps1` is re-run it will copy equivalent files.
- **NOTE-B:** `funcret` is not in CHIM's `fast_commands`, so every glue command result still takes the
  MAIN semaphore before `funcret.php` terminates it. No LLM and no TTS is spent (the R7 claim holds), but
  the result request does queue behind a generation in flight. Nothing to fix; worth knowing if the log
  ever shows `result ...` arriving late.
- **NOTE-C:** the `npc=` value in `lrg_npcstate` and `lrg_scene` is the raw `GetDisplayName()`, not passed
  through `CleanForWire` like every other value (PROTOCOL 0). It cannot be cleaned without breaking the
  server's display-name key, and no vanilla or LoreRim display name contains `; = @ | "`. Leave it;
  do not "fix" it by cleaning, or the DB key stops matching `HERIKA_NAME`.
- **NOTE-D:** `data/.index_lock` is a zero-byte file left behind by `flock`; that is normal and does not
  block later builds (`lrgIndexBuilding()` tests the lock, not the file's existence).
- **NOTE-E:** section 4 of PLAYTEST3 (CHIM settings the owner should change) could not be re-verified
  end to end from here - the connector/relationship values live in CHIM's Postgres, and this pass does not
  touch CHIM's DB or settings. The *source-level* claims behind it (Gemini needs `block_none`,
  `checkOAIComplains` scoring, `confirmation.default_policy`) were all re-checked and hold.

## 7. Bottom line

The build is internally consistent, the deployed and installed artefacts are exactly the audited sources,
all five test suites pass including a 19/19 mutation sweep, and every rail survived a fresh attack.
The six findings are documentation and polish. **SHIP** - apply FIX-1 and FIX-2 to `PLAYTEST3_NOTES.md`
before the owner starts, because those two are instructions that would silently not work.
