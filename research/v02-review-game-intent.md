# v0.2 - adversarial review of the GAME builder's work
Lens: owner intent (R1-R10), rails, prompt quality (hunt items 3, 5, 6). Reviewer is READ-ONLY for project files; this file is the only thing written.

**Verdict: SHIP AFTER FIXES.** Details at the end.

## What I ran myself
| what | result |
|---|---|
| `tools/compile.ps1` on a staged copy (`%TEMP%\lrg_rev2`, work dir `%TEMP%\lrg_bld2`) | OK - 6 .pex, 0 errors, 0 warnings, 23 auto-stubs (none of them a type whose members the glue calls) |
| freshly built .pex vs the .pex committed in `glue/game/LoreRimGlue/Scripts` | byte sizes identical for all 6 (hashes differ only by the embedded source path / timestamp) - the shipped .pex really is this source |
| `php -l` on all 9 server files + all tools + all 23 flow scenarios, PHP 8.2.28 | clean |
| `tools/test_gates.php` | 196 passed, 0 failed |
| `tools/flows/run_flows.php` | 23 scenarios, 466 checks, 0 failed, RESULT: OK |
| every OStim / SKSE / po3 / CHIM / SMI signature the glue calls, grepped in the INSTALLED declaring file | all match (see "verified" below) |
| all 940 installed OStim/OARE/Community-Resource scene JSONs, scanned for `autoTransitions` | 291 files mention it; **0 contain `pullout`**; the only ids present are actor-level `climax` (6) and `devour0/1` (4) |
| `ReportResult` string audit | every literal and every `Error: <why>` path is inside the PROTOCOL 1.6 closed list - their claim holds |

### Verified as claimed (so I am not re-litigating it)
- `OFurniture.GetFurnitureType:16 / IsChildOf:28 / FindFurniture:41`, `OThread.NavigateTo:96 / WarpTo:119 / AutoTransition:141 / AutoTransitionForActor:152 / IsClimaxStalled:251 / GetFurnitureType:277 / ChangeFurniture:288 / IsInAutoMode:305 / StartAutoMode:312 / StopAutoMode:321` - signatures verbatim as in PROTOCOL 3.
- `ostim_furniturechanged(string EventName, string FurnitureType, float ThreadID, Form Sender)` and `ostim_thread_end(.., string Json, float ThreadID, ..)`: exactly as used (`list of mod events.txt`).
- `SurvivalModeImprovedApi.RestoreColdLevel(float) global native` exists; `Game.IsPluginInstalled(string) native global` exists (SKSE `Game.psc:296`). Their deviation from PROTOCOL 4.3 is the *correct* call for an ESL - PROTOCOL's `GetModByName != 255` is the wrong test. **PROTOCOL 4.3 needs the correction, not the code.**
- Furniture type names in `IsBedType`'s fast list match the installed `furniture types/*.json` set exactly (17 types). Case-sensitivity of `StringUtil.Find` there is harmless: a case miss just falls through to `OFurniture.IsChildOf`, which answers correctly.
- `PO3_SKSEFunctions.FindAllReferencesOfFormType(ObjectReference, int, float)` argument order correct; FormType 40 = Furniture confirmed by the glue's own `t41/t42/t26` item-type log legend.
- 27 MCM keys used in the scripts = 27 in `config.json` = 27 in `settings.ini`. `iExplicitness` enum is Suggestive/Direct/Explicit = 0/1/2, default 2, as R5 requires.
- **Silence really is silent**: CHIM 3.3.2 `main.php:2831-2863` - when the reply carries only actions and no talk, the filler `returnLines(["Sure thing!"])` is commented out and nothing is voiced. R10's foundation is sound.
- No explicit example dialogue anywhere in the files GAME owns (`glue/game/**`, `tools/compile.ps1`, `research/v02-game.md`). The success strings are the neutral PROTOCOL ones.

---

## Findings

### 1. HIGH - the kill switch does not reach the OStim event path (rail: "global kill switch")
`LRG_OStim.MaybeSceneTalk` (`LRG_OStim.psc:2025-2056`) gates only on `bSceneTalk:SceneTalk`. It never asks `IsEnabled()` or `IsIntimacyEnabled()`, and it does **not** go through `LRG_Main.SendNpcMessage` (which is gated) - it calls `AIAgentFunctions.requestMessageForActor(...)` directly.

Concrete trace with `bKillSwitch = 1`:
`Maintenance()` registers the OStim mod events unconditionally (`:173-178`) -> owner starts a scene from **OStim's own menu** -> `OnOStimThreadStart` (`:1627`) -> `AdoptRunningThread` (`:1670`) sets `partnerName` and calls `AIAgentFunctions.setAnimationBusy(1, partnerName)` (`:1683`) -> `MarkDirty("start")` -> `LRG_Main.OnUpdate` -> `Tick()` -> `PushState` (`:1946`). `SendNpcMessage` swallows the wire message, but `PushState` then calls `MaybeSceneTalk("start")` (`:2016`), which fires a full `lrg_scenetalk` LLM request (70 % chance on `start`/`climax`) - an LLM call plus TTS under CHIM's MAIN lock, from a module the owner has switched off. The same unswitched path keeps re-asserting `setAnimationBusy(1, ..)` every scene change (`:1724`) and every 5 s (`:1868`), and runs `SurvivalModeImprovedApi.RestoreColdLevel` via `ColdTick` (`:1906-1929`).

The MCM text promises the opposite for both master switches: *"Off = nothing is sent to CHIM, nothing is offered to NPCs, nothing is executed"* and *"Emergency stop. Overrides every other setting."*

**Smallest fix** - one guard at the two entry points that are not already gated:
- `MaybeSceneTalk`, first line: `if !m.IsIntimacyEnabled() || !m.SettingBool("bSceneTalk:SceneTalk", true) || partnerName == "" || windDown` (`MaybeLead:2075` already has exactly this check - copy it).
- `Tick()` (`:1813`), after the `ostimPresent` check: `if !Main().IsEnabled() \n return \n endif` - that also silences the busy-flag upkeep and `ColdTick` in one line. (`StopScene`/`OnKeyDown` stay ungated, which is right: stop must always work.)

### 2. MEDIUM-HIGH - R6 breaks silently when OStim's auto mode comes on after the thread started
PROTOCOL 3 (start + lead rows) requires auto mode to be stopped on a glue-started thread *"so OStim's auto mode cannot skip the ladder"*. The game does that exactly once, at `ostim_thread_start` (`LRG_OStim.psc:1650-1653`) - and the builder's own report lists "`StopAutoMode` timing at start being early enough" as unproven.

There is no second line of defence. The 5 s poll in `ThreadTick` (`:1869-1876`) only *records* a late auto-mode:
```papyrus
bool isAuto = OThread.IsInAutoMode(0)
if isAuto != lastAuto
    lastAuto = isAuto
    if !isAuto && leader == "auto"   ; only handles auto -> off
```
Consequence on a **glue-started** scene where OStim's own MCM auto mode wins the race: `lastAuto = true` -> `MaybeLead` returns at `:2069` -> `PushState` reports `leader=auto` (`:1971`) -> the server's `lrgLeadAllowed()` (`lrg_actions.php:155-159`) refuses lead ticks -> OStim walks positions by itself at full speed. The tier ladder (`_maxtier` / `lrgTierCeiling`) is bypassed entirely, the NPC never paces anything, and nothing tells the player. That is R6 ("one step at a time, NPC-paced") failing quietly, in the exact scenario the contract wrote the `StopAutoMode` rule for.

**Smallest fix**, in `ThreadTick` inside the existing 5 s block:
```papyrus
bool isAuto = OThread.IsInAutoMode(0)
if isAuto && startedByGlue && leader != "auto"
    OThread.StopAutoMode(0)          ; the glue never asked for it: undo it
    isAuto = false
endif
```
(adopted threads and an explicit `do=lead;who=auto` keep today's behaviour, because `leader` is then `"auto"`).

### 3. MEDIUM - R3: `home=1` can mean "a whole city", so she invites the player to "her own place, right here" in the open market
`LRG_Main.PlaceFacts:659` uses `ed.IsSameLocation(loc)`. With no keyword argument that call is **plain equality** - the installed SKSE `Location.psc:27-41` reads `bool bmatching = self == akOtherLocation` and only consults parents/children when a Keyword is passed. PROTOCOL 1.1 asks for exactly this call, so the game is contract-correct; the *fact* it produces is not always what R3 wants.

For any NPC whose editor reference sits in an exterior city cell (guards, market vendors, several LoreRim-added NPCs), `GetEditorLocation()` returns the city location. Standing with her in the Whiterun market: `ltype=city`, `home=1`, `cellown=none` -> `lrgPlaces()` (`lrg_core.php:535-536`) picks `home_here` = *"a room of their own right here - this is their own place"*, and the public-mode guidance (`lrg_actions.php:804`) tells her to name that as the private place. She invites the player to go somewhere private that is the square they are standing in - and because `mode` stays `public` until the witnesses clear, the invitation is then remembered for 30 minutes pointing at nothing.

**Smallest fix** (server, `lrg_core.php:535`), one condition:
```php
$indoor = in_array($state['ltype'] ?? '', ['inn','phouse','house','castle','temple','store','guild'], true);
if (($ownsCell || ($state['home'] ?? '') === '1') && $indoor) { $places['home'] = $w['home_here']; }
elseif ($nhome !== '') { $places['home'] = sprintf($w['home'], $nhome); }
```

### 4. MEDIUM - R1/R7: "pull out" is offered on every sexual turn and can never succeed on this install
My own scan (940 scene JSONs, OStim + OARE + Community Resource) confirms the builder's claim and sharpens it: **no `pullout` auto transition exists anywhere**; the only auto transitions installed are actor-level `climax` (6) and `devour0/1` (4). `OThread.AutoTransition` and `AutoTransitionForActor` will both return false forever, so `CmdPullOut` (`LRG_OStim.psc:1388-1423`) always answers `Error: not possible in this position`.

The builder filed this as "needs a server-side decision", but the work shipped with the server still listing it: `lrg_actions.php:890` adds `'pull out'` to `$verbs` whenever the current tier is `sexual`. Each time the LLM picks it the owner pays a full LLM+TTS turn, gets a `Debug.Notification`, and the NPC is fed a "what she last tried did not happen" line on her next turn (`lrg_actions.php:429-435`, `:858`). That is R7 noise on the hottest turns in the mod.

**Smallest fix** (server): guard line 890 the same way `auto` is guarded (`$ctx['auto_ok']`) - only offer `pull out` when the index actually knows a pullout transition for `$ctx['current']`; on this install the list is then simply never shown. Leave the game verb in place for packs that define it.

### 5. MEDIUM - R10: when `announce` is true, a gentle choice asked for by the player gets total silence
`lrgSceneNotes` (`lrg_actions.php:903-905`) has two branches. The `announce = false` branch carries the escape hatch *"leave message empty **unless the player asked something that needs an answer**"*. The `announce = true` branch does not:

> "[silent] choices (kissing, holding), and pace, hold or clothes: leave message empty - it simply happens."

On a player-speech turn (`can_act` true, `lead` false) with the dice above the announce threshold, the player says "kiss me", she kisses and says **nothing at all**. The owner's rule is about *her own* pick ("if **they choose** kissing... they probably shouldn't say anything"), not about ignoring a direct request. This is also the one branch where the block's own earlier line ("Say what $npc wants... what the player should do", `:855`) and the final instruction pull in opposite directions.

**Smallest fix**: append the existing clause to the announce=true string when `empty($turn['lead'])`, i.e. `... it simply happens" . (empty($turn['lead']) ? ", unless the player asked something that needs an answer." : ".")`.

### 6. MEDIUM - R1 "if I say a position it should go to that position": a route disagreement is refused, never warped
Two different oracles decide reachability and only one of them can set the warp flag:
- server `lrg_actions.php:667-675`: `warp = 1` only when the target is in neither `$ctx['live']` (the game's `next=`) nor its own 3-hop `lrgSceneWalk` over the index.
- game `NavigateBlockedReason` (`LRG_OStim.psc:1601-1607`): the target must appear in `OLibrary.GetScenesInRange(cur, actors, iNavigationReach)`, OStim's live oracle, which additionally filters by the **actual actors** (requirements/tags per position), not by sex alone.

So whenever the index graph has an edge OStim will not give these two actors, the server sends `do=goto;scene=X` with no warp flag, and the game answers `Error: that position cannot be reached from here`. `bAllowWarp` is on by default but is unreachable in that case, because it is gated on the server's flag. The player named a position and got a refusal plus a notification.

**Smallest fix** (game, one line, still inside PROTOCOL 3's intent - the MCM toggle stays the authority): in `CmdControl`'s goto branch (`:1309-1316`), when `why == "unreachable"` fall back to `useWarp = m.SettingBool("bAllowWarp:Intimacy", true)` regardless of the `warp` param. Alternative (server): set `warp = 1` whenever the target is not in `$live`, treating the index walk as advisory. Either way PROTOCOL 2's `[;warp=1]` wording should be relaxed to say the flag is a *hint*.

### 7. LOW-MEDIUM - an aborted start writes a false "their intimate time came to an end" into CHIM's memory
`OnOStimThreadStart:1639-1644`: when a `stop` arrived during the build, the game first reports the **success** string `They draw close. The scene begins.` and only then stops the thread. It returns before `MarkDirty("start")`, so the server never sees `ev=start` - but `FinishThread:1777` still sends `ev=end`. On the server (`lrg_actions.php:221-231`) that bumps `lrg_romance.scenes` and calls `logEvent()` with *"<npc> and <player>'s intimate time together comes to an end"*, which goes into CHIM's event stream, memory and diary. The player cancelled before anything happened, and the NPC now remembers a night with them.

**Smallest fix** (server, safer than touching the game): in `lrgHandleSceneMessage`, only emit the `end` line when the row being closed had actually been opened by a `start` for this `cid` (the payload `_maxtier`/`_tier_since` already prove it), i.e. `if ($ev === 'end' && ($prevSeenStart))`. Game-side alternative: report `The scene ends.` on the abort path and suppress the `ev=end` push when no `ev=start` was ever sent for this cid.

### 8. LOW - "I'll lead" does not survive a save/load (R1)
`Maintenance()` (`:180-190`) goes to great lengths to preserve `startedByGlue` across a mid-scene reload (`wasGlue` / `oldPartner`), but `AdoptRunningThread():1691-1693` unconditionally overwrites `leader` with `DefaultLeader()` or `"auto"`. The player's spoken "I'll lead" reverts to the MCM default, and lead ticks start again.
**Fix**: same dance - remember `leader` before `AdoptRunningThread()` and restore it when `wasGlue && partner == oldPartner`.

### 9. LOW - the "guaranteed route" to stop is unbound out of the box (rail: the player can always stop)
`settings.ini:39` ships `iKeyStopScene = 0`, while the MCM help for it says *"Saying 'let's stop' works too; this is the guaranteed route"*, the kill-switch help says *"A running scene can still be ended with the stop key"*, and `PLAYTEST2_NOTES.md` tells the owner to test "the stop hotkey too". Until the owner binds a key, the only stop is the voice path - which needs the server, the LLM and CHIM's lock to be healthy, i.e. exactly the things a guaranteed route exists for.
**Fix**: one line in the owner notes ("bind Intimacy -> Keys -> Stop scene now before the first scene"), or ship a default keycode.

### 10. LOW - "stop" can be delayed by up to ~9 s during the weapon prep
`CmdStart` runs `PrepareWeapons` twice (`:482-485`), each of which can spend `12 x Utility.Wait(0.25) + Wait(0.15) + 4 x Wait(0.3)` (`:563-612`) - about 4.4 s per actor - on the calling script's stack. A `stop` that is delivered through the same script queue waits behind it. It is never *lost* (`abortStart` catches it at `ostim_thread_start`), but the rail says "at once". Worth one sentence in the notes; the crash workaround is worth the delay.

### 11. LOW - a partial undress that removes nothing still reports success
`SceneUndress(npc, "head")` -> `ScenePartMask` (`:906-924`) returns 0 when she wears nothing on that part, `UndressPartial` is skipped, and `CmdClothing:1190` nevertheless answers `<npc> undresses.`. `undp` will not change either, so neither the player nor the NPC learns that nothing happened.
**Fix**: return the mask from `SceneUndress` and answer `Error: not available` when it is 0 and nothing was stripped.

### 12. Notes on the report text (no code change)
- "`SnapTick` spends zero natives when woken early by LRG_OStim's fast ticks" is not accurate: `LRG_Main.psc:519-526` spends `Utility.GetCurrentRealTime()` and then `RequestTick` spends another one (plus a possible `RegisterForSingleUpdate`). It is *cheap*, not free.
- The `RequestTick` comment asserts "RegisterForSingleUpdate is per FORM". That premise is not established (the usual reading is per script instance). The single-owner design is harmless either way, so this is a comment correction, not a defect.
- Contract drift to hand the integrator: PROTOCOL 4.3 (`GetModByName` -> `IsPluginInstalled`), PROTOCOL 2 (`warp=1` is a hint, see finding 6), and PROTOCOL 3's furniture row (MCM-off answers `not available`, not `no suitable furniture nearby` - legal, but the table implies otherwise).

---

## Rails: what I attacked and what held
| rail | attack | result |
|---|---|---|
| adults only, fail closed | `CmdStart` -> `PairBlockedReason` re-checks `IsAdult` for both; `CmdClothing:1123` re-checks; `PrivacyBlockedReason` re-checks `witkid`; `WitnessScan` returns `witkid=1` and aborts the scan on the first non-adult; server hides every action when the stored snapshot is not `adult=1`, including for adopted OStim-menu threads (`lrg_actions.php:360-362`) | **holds**. `CmdControl` alone does not re-check adulthood, but it is unreachable without a live thread whose snapshot already said `adult=1`, and OStim refuses children itself. Acceptable defence in depth. |
| the NPC must be willing | game never decides willingness; it enforces `ok=1` on every verb (`:741`, `:1103`, `:1251`) and repeats pair + privacy gates for undressing outside a scene (`:1141-1152`) | **holds** |
| `stop` ends at once and wins every tie | first branch of `CmdControl` (`:1258`), before the partner check and before dry-run; `abortStart` path for a stop during the build; hotkey path ungated by `IsEnabled` | **holds**, with findings 9 and 10 as caveats |
| global kill switch | see finding 1 | **BREACHED** for the OStim event path |
| the player is always a participant | `OActorUtil.ToArray(player, npc)` on start; `CmdClimax` guards `partner`; no NPC-only start | holds |
| forced / creature scenes never indexed | SCENEINDEX's hard in-code list; nothing in GAME can reach past it | holds (not my file) |
| never modify LoreRim/CHIM/OStim/OARE files or OStim settings | nothing under `F:\Modlists` written; `compile.ps1` only reads; `OThreadBuilder.NoAutoMode` correctly not used; no OStim MCM write anywhere | holds |
| no explicit example dialogue in GAME's files | grepped `glue/game/**`, `tools/compile.ps1`, `research/v02-game.md` | clean. (The crude tokens that exist live in SCENEINDEX's synonym table and one BEHAVIOUR test name - parser vocabulary, not NPC lines. Not GAME's, and defensible, but the integrator should be aware they are in the repo.) |

## Prompt quality (hunt 6) - would it really produce plain, direct, unsanitised speech at x=2?
Mostly yes, and it is short enough.
- `explicitness_words[2]` = *"fully explicit and vulgar - crude words for body parts and acts are expected, not avoided: never soften them, dodge them or swap in euphemisms"* - a real permission, stated as a rule with no example lines. Combined with the BANNED list in `lrgSceneNotes:855` (metaphors, similes, romance-novel wording, narrating from outside) and `lrgStyleLine:717`, the anti-poetry pressure is strong and consistent. The v1 problem the owner complained about (text that pushed willing NPCs toward restraint) is genuinely gone.
- `OPENAI_FILTER_DISABLED` is set per-request for glue-guided turns only (`lrgApplyTurnRuntime:462`), which removes CHIM's word-scoring rewriter - the one code-level sanitiser in CHIM. Correct and correctly scoped.
- Length: `<intimate_scene_now>` is ~9-11 lines / ~2.2 kB on an action turn. That is input tokens, not TTS, and the volatile block is deliberately kept out of the cached prefix. `FORCE_MAX_TOKENS` 140/200 plus "ONE short sentence, at most 120 characters" is a sound belt-and-braces for R7.
- The one real contradiction is finding 5. A second, milder one: `:855` ("Say what $npc wants... what the player should do") sits above instructions that on some turns demand an empty message; the ordering saves it (the silence rule comes last), but a reader-model with weak recency handling could split the difference. If a cheap edit is wanted, move the "say what you want" clause into the `[say it]` line instead of the always-on style line.
- Everything else I checked is rules, not lines: `lrgInterestWords`, `talk_words`, `lrgReasonWords`, the `<personal_boundaries>` refusal text (which is the strongest anti-agreeableness text in the project and should not be weakened).

## R1-R10 traced, one concrete example each
| req | traced through | delivered? |
|---|---|---|
| R1 menuless OStim | "get on top" -> LLM `ChangeIntimacy@get on top` -> `lrgResolveControl:667-675` -> `do=goto;scene=..` -> `CmdControl:1305` -> `NavigateBlockedReason` -> `OThread.NavigateTo` | **mostly** - every menu verb except alignment/freecam/hide-UI (no native exists; confirmed by listing every `Global Native` in the installed OStim sources) is reachable by voice. Two dents: finding 6 (a named position can still be refused) and finding 4 (pull out is dead). |
| R2 NPC-initiated romance | `SnapTick` -> `MaybeInitiative:579-620` (all PROTOCOL 1.5 conditions, cheapest first, forces a snapshot) -> `requestMessageForActor("approach")` -> `lrgInitiativeAdmit` drops before the MAIN lock -> interest **word** only in the prompt | **yes** |
| R3 public -> private | `PlaceFacts` -> `ltype/cellown/home/nhome/prent/bedown` -> `lrgPlaces` -> `SuggestPrivacy` recorded for 1800 s -> alone -> mode `follow` -> follow-through guidance | **yes, with finding 3** (a city-wide `home=1` produces a nonsense place) |
| R4 direct not poetic | `lrgStyleLine` + the BANNED list; discreet only for very-strict / married-secret / castle / temple | **yes** |
| R5 no NSFW blocks | MCM `iExplicitness` default 2 -> snapshot `x=2` (`PlaceFacts:677`) and scene `x=2` (`PushState:2009`) -> `lrgWordingLine` only when `$turn['x'] !== null`; CHIM audit is thorough and changes nothing in CHIM | **yes** |
| R6 progression | `_maxtier`/`_tier_since` + `lrgTierCeiling`, one step per pace time, `too_soon` drop | **yes on the server, defeatable in game** - finding 2 |
| R7 speed | `followup.enabled=false` on all four rows; one-sentence + `FORCE_MAX_TOKENS`; index never built in an LLM request; no lead tick under player/auto/wd | **yes**, minus the wasted turns from finding 4 |
| R8 bridge completeness | furniture start + `ChangeFurniture`; climax; wind-down as its own verb with `stop` still immediate; redress baseline; `setAnimationBusy` re-assert + the 2.5 s "thread gone" safety net; `RestoreColdLevel` through the real hook; `sde` read for Serana only | **yes**, apart from finding 2 (auto mode) and finding 1 (all of this also runs when the module is off) |
| R9 offline flow tests | ran them: 23 scenarios / 466 checks green, covering every scenario the owner listed | **yes** (FLOWTESTS' file, not GAME's) |
| R10 speak the choice | `announce` dice per talk style, `[say it]` / `[silent]` marks per option tier, empty `message` verified silent in CHIM 3.3.2 | **yes, with finding 5** |

## Verdict
**SHIP AFTER FIXES.** The game half is the strongest work in this round: every OStim, SKSE, po3, CHIM and SMI call matches the installed declaring source, the compiled .pex really is the committed source, the closed-list result strings are exact, the hard gates are repeated in game and fail closed, and the wind-down / stop precedence is right where the rail needs it. But two of the defects are not cosmetic. Finding 1 means the owner's emergency stop leaves the glue firing LLM requests and locking NPCs in CHIM whenever an OStim scene runs - a rail the owner explicitly listed as non-negotiable, breached on the one path (OStim's own menu) that stays available while the switch is off. Finding 2 means the headline requirement of this round, NPC-paced progression, can be silently switched off by OStim itself on a glue-started scene, with no notice to anyone, and the only defence is a single check whose timing the builder himself could not prove. Findings 3, 4 and 5 each make the owner's own words come out wrong in play - an invitation to a place that is the square they are standing in, a verb that always fails, and an NPC who answers "kiss me" with silence. All five have one- to five-line fixes; none needs a redesign. Fix 1, 2, 4 and 5 before the next playtest, take 3 and 6 with it if there is time, and this is ready for the game.
