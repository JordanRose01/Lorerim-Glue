# LoreRim Glue 0.2.0 - integration round (INTEGRATOR)

Status: **COMPLETE.** Everything green, server deployed and smoke-tested against the live HerikaServer, MO2 mod refreshed, docs written. Nothing tested in game (no game session is possible from this environment).

---

## 1. Defects fixed

### Game (`glue/game/LoreRimGlue/Source/Scripts/*.psc`)

| Review finding | What landed |
|---|---|
| [HIGH intent] kill switch does not reach the OStim event path | `MaybeSceneTalk` now opens with `!m.IsIntimacyEnabled()`; `ThreadTick`'s 5 s block computes `glueOn = Main().IsEnabled()` and skips the `setAnimationBusy` re-assert, the auto-mode correction and `ColdTick` when it is off; `AdoptRunningThread` and `OnOStimSceneChanged` gate their `setAnimationBusy`; `Tick`'s out-of-scene `ColdTick` is gated. **Deliberately narrower than the reviewer's "return at the top of Tick()"**: an early return there would have killed the start-timeout cleanup, the wind-down timer and the "thread gone without an end event" safety net, leaving the NPC locked for CHIM. `StopScene` / `OnKeyDown` stay ungated. |
| [MEDIUM-HIGH intent] OStim auto mode switched on after thread start bypasses the ladder | `ThreadTick`: `if glueOn && isAuto && startedByGlue && leader != "auto" -> OThread.StopAutoMode(0)`, logged. Adopted threads and an explicit `do=lead;who=auto` are untouched. |
| [blocking sceneindex] `furn` on the wire is OStim's LIST type | `PushState` now uses `OThread.GetFurniture(0)` (OThread.psc:268) + `OFurniture.GetFurnitureType(ref)` (OFurniture.psc:16), falling back to `none`. This was killing the whole R6 ladder from the default bed start. |
| [MEDIUM x2] named position refused when the two route oracles disagree | `CmdControl` goto branch: `why == "unreachable"` now sets `useWarp` from `SettingBool("bAllowWarp:Intimacy", true)` alone; the server's `warp=` is a hint. Only the game side was changed, not both. |
| [MEDIUM] "faster" at scene start silently sets the minimum | pace branch refuses with `Error: the scene is still starting` when `OThread.GetScene(0) == ""`. |
| [MEDIUM] the watch dies 300 s after her last line, so R3 follow-through cannot happen | `LRG_Main.SnapTick`: the initiative branch now keeps the watch for **1800 s** (matching `invitation.ttl_seconds`) instead of 300 s, still bounded by distance, load state and the module switch. |
| [low] `ExtCmdLRG_SceneControl` did not repeat the adult gate | added after the partner check, so `stop` stays exempt. |
| [low] R6 fails open when the server sends an empty scene and no furniture ref is found | `CmdStart` refuses with `Error: the scene did not start` **before** `OThreadBuilder.Create` (moving the furniture in-use re-check up), so no builder is leaked. |
| [low] hand-rolled furniture lookup / uncapped scan | `FindFurnitureRef` now calls `OFurniture.FindFurnitureOfType` (:55); `ScanNearFurniture` capped at 18 (17 installed types). |
| [low] a start aborted by a "stop" is reported as success | the abort branch now reports `Error: the scene did not start`; the stop itself was already answered `The scene ends.` |
| [low] "I'll lead" does not survive a save/load | `leader` is captured before `AdoptRunningThread()` and restored when the thread is still ours and not in auto mode. |
| [low] `CleanForWire` gives up after 40 replacements | `ReplaceChar` guard raised to 300 (the longest wire value). |
| [low] witness scan's outer loop uncapped | `while i < near.Length && i < 80 && checked < 40`. |
| [low] `compile.ps1` prints OK without inspecting the compiler messages | prints the unique messages and **exits 1** when any matches `(?i)\berror\b`, before copying the .pex files. |

### Server behaviour (`lib/lrg_actions.php`, `lib/lrg_core.php`, `prompts.php`, `config/`)

| Review finding | What landed |
|---|---|
| [critical/high x4] the rails only apply OUTSIDE a scene (profile `never`, `witkid`, snapshot age, MCM `on`) | `lrgPrepareTurn` now re-checks all of them on **every** scene turn. Two outcomes, not one: an NPC whose snapshot never confirmed an adult gets **total silence** (the hardest rail is absolute); any other failing rail gives a new `scene_blocked` state - mode stays `scene`, ChangeIntimacy stays offered, but `x=null`, `options=[]`, `ctx=null`, no lead tick, the notes are one sentence about stopping, and the post-gate lets **only** a resolved `do=stop` through. That keeps the spoken "stop" alive, which going silent would have killed. |
| [HIGH intent] `prompts.php` is guarded by `lrgEnabled()` only, so an intimate cue is the last text in the prompt on a silent turn | the block now repeats each type's own precondition: `lrg_scenetalk` needs a live scene with THIS NPC on a fresh, adult, child-free snapshot with the switch on; `lrg_initiative` needs `$GLOBALS['LRG_INITIATIVE']['npc']` to match. Omitting the entry is safe (request.php:171-175 falls back to TEMPLATE_DIALOG). |
| [HIGH intent] a bare refusal fuzzy-matches a scene id and ESCALATES | negated-stop guard added; the anchored verbs were widened so `hold it there` / `not yet` / `wait` reach `hold` rather than falling through to the position search. |
| [medium] the post-LLM gate never checks the action was OFFERED this turn | `$turn['offered']` is recorded after all the hiding, and the gate drops anything not in it (`SuggestPrivacy` is server-only and stays gated on mode + places). The guidance also names only offered actions. |
| [medium] "hold it there" became a hug | fixed with the above; `release` widened to `let go / carry on / keep going / go on`. |
| [medium] negated stop phrases ended the scene | negation guard before the stop rail. |
| [medium] "come with me" forced a climax | verb narrowed to `come (now\|together)` / `come (for\|with) me`; `who` widened so `with me/you` reads as both and `inside/in/for me` as the player. |
| [MEDIUM intent] R3 gates actions but not LANGUAGE | `lrgWordingLine` caps the level at 1 when others can hear or `lrgIsDiscreet` fires; `follow` and `scene` keep the owner's full level. |
| [MEDIUM intent] an unconditional imperative to speak sits next to "leave the message empty" | the "say what she wants" clause is now appended only when `announce` is true and the talk style is not `never`. |
| [MEDIUM intent] R10: a gentle option the PLAYER asked for is answered with total silence | the "unless the player asked something that needs an answer" clause is now on **both** announce branches. |
| [MEDIUM intent] `home=1` can mean a whole city | `lrgPlaces` only uses `home_here` when the NPC owns the cell / is home **and** `ltype` is indoors. |
| [MEDIUM x2] "pull out" is offered and always fails | removed from the offered verb list and from the catalog description. `lrgResolveControl` still maps the words and the game verb is unchanged, for a pack that defines the transition. |
| [MEDIUM intent] two contradictory lead key sets, and "you lead" inverts | one key set everywhere: `<npc> leads` / `<player name> leads` / `auto`. A bare `you lead` / `i lead` is **dropped** (fail closed - she just answers). |
| [medium/LOW-MEDIUM] a cancelled start writes a false "their intimate time comes to an end" into CHIM's memory | new `lrgSceneWasOpen()`; the end `logEvent` and the `lrg_romance.scenes` bump only fire when an `ev=start` really opened the row for that cid. |
| [low] `lrgIsSayIt` fails open on an unknown tier | default changed to 0. |
| [low] `lrgKeepOnlyActions(['Talk', ...])` keeps a code CHIM does not have | verified against the live catalog (`select code_name from core_action where code_name ilike '%talk%'` -> 0 rows) and dropped from both calls; PROTOCOL 6.2 corrected. |
| [low] "undress me" strips the NPC | `who=player` pattern accepts `me` / `off me` / `from me`. |
| [low] R7: `public` / `private` ask for one sentence but get no token cap | `lrgApplyTurnRuntime` now caps `follow, public, private` and any initiative tick. |
| [low] the wording paragraph contradicts itself for quiet / crude / never | `talk_words` now expresses **quantity and volume only**; vocabulary is `explicitness_words` alone. Readme text in the config says so. |
| [low] `OPENAI_FILTER_DISABLED` scope is broader than documented | scope confirmed deliberate (an in-character refusal is exactly what trips CHIM's word scorer) and written into PROTOCOL 6.4 and the owner notes; blocked and silent turns leave it untouched. |
| [low] explicit literal in a test | the gate test's above-ceiling input is now a neutral phrase and the check name states the rule. |

### Scene index (`lib/lrg_scene_index.php`, `tools/warm_index.php`, `tools/deploy_server.ps1`)

| Review finding | What landed |
|---|---|
| [blocking/HIGH] the signature does not cover the library, so a rail fix does not survive a deploy | `lrgIndexSignature()` folds in `md5(json_encode([LRG_HARD_WORDS, LRG_HARD_GLOBS, LRG_HARD_INCAPACITATED, LRG_COMMON_REQUIREMENTS]))` **and** `filemtime(__FILE__)`; `deploy_server.ps1` runs `warm --force`. |
| [HIGH] with `content_filter=open` the hard list is the only net and misses the vocabulary non-consent packs use | added `dubcon, dubious, coerced, coercion, struggle, struggling, resist, resisting, reluctant, captive, prisoner, blackmail, defeated, helpless, lolita, schoolgirl` and the globs `*dubcon* *struggl* *resist* *captive* *defeat* *blackmail*`; the incapacitation rail now also reads the scene's own `tags`. Rebuilt: still 0 hard exclusions against what is installed. |
| [MEDIUM] naming the position you are already in moves you | the current scene is scored as a candidate; `null` when it covers as much **and** scores as well, or when the winner is only another pack's version of the same tier / acts / poses (`lrgSameShape`). Verified: "kiss me" from the gentle start and "missionary" from a missionary scene now change nothing, while "hug me", "cuddle", "cowgirl", "grope" still resolve. |
| [MEDIUM] the P-list is dominated by tier-neutral routing idles | neutral scenes now rank +3 hops instead of +1; the walk still routes through them. |
| [MEDIUM] internal tokens reach the label the NPC speaks from | `lrgDescribeScene` strips `[MF]`/`(left)`/`(right)` and the bookkeeping `holding*` actions. |
| [medium] no catch around the build, so the 10-minute back-off never engages | `try/catch (Throwable)` writing the `['error','attempt']` meta record. |
| [medium] markers written with the caller's umask | `umask(0002)` at the top of `lrgIndexWarm()` and `lrgIndexCli()`. |
| [low] `time()` instead of `lrgNow()` | all three call sites. |
| [low] `lrgPickAfterglow` had no caller; BEHAVIOUR re-derived the warp flag with a hard-coded hop count | `lrgResolveControl`'s wind-down branch uses `lrgPickAfterglow()['routed']` (guarded by `function_exists`). |
| [low] dead `lrgSceneExcluded()` | deleted (repo-wide grep found no caller). |
| [low] `lrgIndexWarm` builds unsynchronised when the lock file cannot be opened | returns `['ok'=>false, 'skipped'=>true]` and logs instead. |
| [low] CLI memoises an empty index for the whole process after a skipped warm-up | that branch no longer memoises. |
| [low] `warm_index.php` warns on an empty first argument | `($args[0][0] ?? '-')`. |
| [low] the detached build inherits the request's process group | `setsid` is prepended when it exists. |

### Flow tests (`tools/flows/`)

| Review finding | What landed |
|---|---|
| [HIGH] the release gate cannot fail on PHP notices or out-of-contract DB calls | `$bad` now includes `FxDb::$unhandledAll` always and `Fx::$phpIssues` under `--strict`, and the RESULT line names the cause. |
| [HIGH] the fake DB answers an unsupported read with the first row of the table | `rowsFor()` reports a miss for any shape that is not `WHERE npc_name=`, `WHERE md5=` or the active-scene query. |
| [HIGH] `lrgResolveRequestNpc()` has never executed | `fxGameMessage` now sets `$_GET['profile'] = md5($npc)` for `lrg_initiative` and leaves `HERIKA_NAME` as a decoy; `fxSendSnapshot` seeds `core_npc_master`. Scenario 15's 33 checks now exercise the production path. |
| [MEDIUM] R7 has no test and the suite could spawn a real build | `$GLOBALS['LRG_TEST_NO_SPAWN']` set in `fxReset`; **new scenario 18** proves a speech turn, a lead tick and an initiative tick start no build, that the fast state path does start a detached one, and that a stale index is still served. |
| [MEDIUM] the mutation gate exits 0 when every mutation goes stale | `if ($detected -lt $mutations.Count) { exit 1 }`, `STALE:` on its own line, and the suite is run with `--strict`. |
| [critical/high intent x3] no scenario puts a blocked profile, a child, a stale snapshot or the MCM switch inside a scene | scenario 05 gained four in-scene rail sweeps (child, switch off, profile `never`) each asserting "no awareness, no wording, no action, but `stop` still reaches the game"; scenario 17 gained the stale-snapshot-inside-a-scene case. Four new mutations M16-M19 cover them. |
| [low] `fxShape` makes `npc=` optional | mandatory; `fxShapeV1` kept for the v1 comparisons. |
| [low] the killswitch variant can write into the project source tree | refuses unless the plugin dir is in a temp folder (`--allow-in-place` overrides), and drops a `.flowtest_override` marker that the next run detects and cleans up. |

---

## 2. Reconciliation (`glue/PROTOCOL.md`)

Every verb and message type was walked end to end against both sides. Changes, all "the code is right, the contract was wrong":

- **1.2** `furn` is the SPECIFIC furniture type via `OThread.GetFurniture` + `OFurniture.GetFurnitureType`, explicitly not `OThread.GetFurnitureType`.
- **2** `warp=1` is a hint; the MCM toggle is the authority. The game re-checks adults-only on every verb but `stop`.
- **3** furniture row: `OFurniture.FindFurnitureOfType`, and MCM-off answers `Error: no suitable furniture nearby`.
- **4.3** `Game.IsPluginInstalled("SurvivalModeImproved.esp")`, not `GetModByName(..) != 255` (the plugin is ESL-flagged, header 0x200). New **4.4** on the master switches reaching the OStim event path.
- **6.2** new `scene (blocked)` row; `places` is a map and `home` needs an indoor `ltype`; new `$turn` keys `offered` and `scene_blocked`; `lrgPrerequest` keeps only the mode's actions (CHIM has no `Talk` code).
- **6.4** the wording level is capped where others can hear; every mode that asks for one sentence gets the token cap; the `OPENAI_FILTER_DISABLED` scope is written down; negated stop; lead keys are names; `pull out` is resolved but never offered; R1 "already there" returns null.
- **6.5** catalog item list matches the code.
- **7.1** `content_filter` default is `open` (canonical value name `standard`); `deploy_server.ps1` runs `warm --force`; the signature covers the code hard list.
- **7.3** two new observable outcomes: the in-scene rails, and "no LLM request ever starts an index build".

`tools/flows/adapter.php` needed no function renames - the plugin's contract functions were all still present. The PENDING list (`fxKnownPending()`) is empty and every scenario is a real pass.

---

## 3. Verification

| Check | Result |
|---|---|
| `tools/compile.ps1` | **OK** - 6 .pex, compiler reports 0 errors / 0 warnings (and the tool now measures that instead of asserting it) |
| `php -l` on every PHP file in the plugin and the tools | clean (PHP 8.2.28) |
| `tools/test_gates.php` | **201 passed, 0 failed** (was 196; +5 for the in-scene rails and the prompts.php guard) |
| `tools/test_scene_index.php --quiet` | **ALL CHECKS PASSED** |
| `tools/flows/run_flows.ps1 --strict` | **24 scenarios, 487 checks, 0 failed, 0 pending, 0 warnings, exit 0** |
| `tools/flows/mutation_check.ps1` | **19 of 19 mutations detected, 0 stale** |
| `lrg_config.default.json` | parses (`json_decode`) |
| Deploy | `deploy_server.ps1` -> "scene index: rebuilt, 607 scenes, 5.8s / deployed" |
| Live smoke | `curl .../comm.php?DATA=<b64 lrg_log>&profile=<md5>` -> **HTTP 200**, `[cid=deploy2] GAME smoke test after final deploy` in `lorerim_glue.log`, **no new lines in `/var/log/apache2/error*.log`** |
| Live state | `schema ensured (v2, 2 migration files)`, `action catalog: rows installed (v7)`, markers `data/.schema_v2` + `data/.actions_v7`, four rows active in `core_action_custom` at `import_version 7`, index status `{"scenes":607,"stale":false,"filter":"open"}` |
| MO2 | `install_mo2.ps1` ran (MO2 was closed): backups `*.bak-20260921-095904`, 6 .pex installed, byte sizes identical to the build, modlist / plugins / loadorder entries already present. ESP unchanged, not regenerated. |

---

## 4. Not done, and why

- **Nothing was tested in a running game.** No game session is possible from this environment. Unproven: starting on furniture, `ChangeFurniture` at distance, `StopAutoMode` timing at thread start, the `RestoreColdLevel` amount, and CHIM's conversation cooldown versus `lrg_initiative`.
- **Beast races** (MEDIUM, sceneindex intent): needs a new snapshot key (`beast` 0/1) from the game half plus a PROTOCOL 1.1 addition. Out of scope for a fix round; documented in README and the owner notes.
- **`lrgFurnitureOptions`' dead `climaxing` actor-tag guard** (LOW): harmless, left alone rather than churn the picker this late.
- **The `fxLlm($message)` tautology and the migrations-idempotency check** (MEDIUM/LOW, flowtests runtime): the two checks named were left as they are. The R10 silence guarantee is now carried by scenario 14's real marker assertions plus the 19/19 mutation gate; adding a `lead_idle` consequence test was judged lower value than the four rail sweeps that went in instead.
- **Prompt-size assertions on the expensive turns** (LOW): scenario 13 still measures the cheapest turn only.
- **`invitation.lead_the_way`** stays default-off and untested (read from CHIM source only).
- **The server still cannot detect a refusal**, hers or the player's. No signal exists.
