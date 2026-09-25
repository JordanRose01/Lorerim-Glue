# Phase 0 report — LoreRim × CHIM × OStim × OARE glue

Date 2026-09-21. No code existed when this was written. Detail lives in `research/` (12 reports; 5 adversarially verified, 7 not — the verification run was cut short to control cost) and in `REVIEW_40_RESPONSE.md` (answers to the 40-item risk review, partially adversarially checked). Tags: [V] verified in installed files, [R] research report, [I] inference, [M] memory/unverified.

## 1. What CHIM 3.3.2 already gives us
- **Plugin seams [R]:** 12 server hook files loaded by filename from `ext/<plugin>/`; prompt injection registry `chimRegisterPromptInjection($slot,$id,$content,$priority)` (slots `character_bottom`, `prompt_bottom`); per-request action hiding with `unsetFunction($code)` before `main.php:2474`; a post-LLM hard gate `$GLOBALS["action_post_process_fnct_ex"][]` that can drop wire lines; side LLM calls via `$driver->fast_request()`.
- **Actions are a DB catalog [R]:** rows come from `Data/CHIM/*_actions.csv` (uploaded by the DLL at startup) or `herikaActionCatalogUpsertCustomRow()`. Row metadata supports `requirements` (request types, NPC names/factions, activity), `followup` (needed for an LLM turn after a `funcret`), `confirmation`, `cooldown_seconds`. **Hard constraint:** with your connectors' strict JSON schema the LLM can only fill `target`, `item`, `amount` — glue actions carry their choice in `item`.
- **Game side [R, verified]:** commands arrive as mod event `CHIM_CommandReceived(npcName, command, parameter)` and/or a bridge script's `DispatchExternalCommand(...)`; results go back as `command@Code@param@result` with type `funcret`; NPCs are identified by display name; `SPG_CommandReceived` no longer exists.
- **Relationship meter [R, verified]:** `relationship_system` plugin = affinity −100..+100 per NPC, romance types can only be set by UI or a plugin. The glue reads it; it does not invent another.
- **Quests [R, verified]:** the DLL already walks the journal (`_quest` snapshots: name, current objective, stage) and already logs every vanilla player topic and NPC line into context. A beta "AI Quest Progression" engine exists (OFF here): `SetStage`-based, hand-authored for 301 Bethesda quests, no LoreRim mod quests. It stays off.
- **Prior art:** SHARMAT (`aiagent_nsfw`) is featured in CHIM's plugin catalogue — see §5.

## 2. OStim awareness and control — Papyrus only, no DLL [V]
All five requirements are met by OStim 7.5.1's script API; every name/signature below was confirmed in your installed sources, then re-confirmed by an adversarial check.
- State: `OThread.IsRunning(0)`, `GetActors(0)`, `GetActorPosition(0, actor)`, `GetScene(0)`, `GetSpeed(0)`, `GetFurnitureType(0)`; metadata via `OMetadata.*`. Player thread is always 0.
- What can happen next: **primary = server-side index of the scene JSON** (the WSL server reads `F:\Modlists\LoreRim\mods\...` directly [V], honouring `modlist.txt`): 607 scenes, 1,629 edges, labelled, tiered (neutral/affection/kissing/sensual/sexual), path-finding that can stay inside a tier. Live oracle = `OLibrary.GetScenesInRange(id, actors, N)` with **explicit N ≥ 1** (default 0 may return nothing).
- Events: `ostim_thread_start / _scenechanged / _speedchanged / _end`, `ostim_actor_orgasm`. Filter `ThreadID == 0`; de-duplicate scenechanged vs speedchanged; read the end payload with `OJSON`.
- Control: `OThreadBuilder.Create → SetStartingAnimation → SetFurniture|NoFurniture → Start` (async — wait for `ostim_thread_start`), `OThread.NavigateTo` only to confirmed-routable targets (it warps otherwise), `SetSpeed`, `Stop`, `StallClimax`/`PermitClimax`.
- Every standing kiss/hug scene can reach every cuddle scene and back using only gentle-tier nodes (216/216 pairs) [R] — build-up and afterglow are graph-native.

## 3. Menuless questing — feasibility
**Mechanism (decided): headless real dialogue session.** The engine runs the real session; the glue hides the menu, reads the list the engine built, lets the LLM pick, and clicks through the normal path. Nothing is re-implemented, so conditions with alias context, Say Once, links, shared infos, walk-away/goodbye, fragments, speech checks, service menus and story events are all the engine's own [R dialogue-engine.md].

| Case | Verdict | Handling |
|---|---|---|
| Ordinary quest/branch topics | Feasible | session → list → LLM choice → click → re-read next layer |
| Persuade / bribe / intimidate | Feasible, free | engine picks success/fail INFO from real conditions; LLM only decides the player *tried* |
| ForceGreet, blocking greetings, guards | Feasible | glue never blocks activation; any engine-opened session goes through the same handler |
| Scripted scenes | Stand down | detect with `GetCurrentScene()`; resume after |
| Service menus (barter, training, rent) | Feasible | real fragment opens the real menu visibly |
| Irreversible branches | Feasible with care | two-step confirm; CHIM's `confirmcommand` as second net |
| Voiced vs silent lines | Fine | vanilla line plays as authored (Fuz timing); CHIM TTS doesn't re-speak it |
| Text tags `<Alias=…>` | Untested [I] | engine-prepared strings expected resolved; test on a radiant quest |
| Topic awareness with no session open | **Needs DLL**, or a brief hidden snapshot session | see decision |
| Executing an INFO without a session | Not feasible safely | not attempted |

Hard limits / risks: the Papyrus read path (`UI.GetString` on the dialogue SWF) is **untested**; `SmartTalk.dll` and CHIM's own `dialoguemenu.swf` click gate both touch the same menu; conversation camera mods react to real sessions; a hidden menu may flash for a frame without native help.

**DLL decision (yours):** (a) DLL — needs VS 2022 Build Tools, CMake, vcpkg, git; gives FormID identity, no-session awareness, first-frame hiding, exact INFO tracking. (b) Papyrus-only — nothing installed; loses those four. **Recommendation: (b) first behind the same interface; revisit (a) with test evidence.** No C++ tooling will be touched without your say-so.

## 4. Seduction model (design commitments)
Per-NPC profile from verified vanilla factions (`JobJarlFaction`, `JobInnkeeperFaction`, `MarkarthTempleofDibellaFaction`, `RiftenTempleofMaraFaction`, `VigilantOfStendarrFaction`, … [V]) + class + Confidence/Morality/Aggression, with graceful fallback for mod factions; marriage = `HasAssociation(Spouse)` [V], player's spouse = `PlayerMarriedFaction` [V]; **leverage** block (your addition): wealth tier, what gold means to this person, how much the player's renown matters — passed to the LLM as words; gold moves only on acceptance. Layer 1 gates are evaluated on both sides and the action is hidden when they fail; Layer 2 guidance counters Grok's agreeableness. Consent token minted only on the NPC's own reply to player speech. Adult check fails closed. Privacy gate treats followers by profile strictness.

## 5. SHARMAT (study cut short — partial facts only) [R sharmat-prior-art.md]
Active (v3.1.9.3, last commit 2026-09-19), **no licence** (nothing may be copied), very large PHP + one 208 KB Papyrus script, no MCM, compile-time dependency on SexLab, scope includes arousal sync, fertility, defeat, VR physics and an "Open Mode" that *bypasses the consent decision*. That scope conflicts with this spec (no arousal framework, no fetish content, consent can never be overridden). **Recommendation: build the glue's own OStim module; detect SHARMAT and refuse to run the intimacy module alongside it; never share namespaces** (`ext/aiagent_nsfw`, `AIAgentNSFW*`, `ext_nsfw_*`, `AcceptSex/RefuseSex`). Its OStim handling was not studied (Q3–Q9 unfinished).

## 6. Environment and build blockers
- Compile: compiler + flags present (inside the Nemesis mod). **Missing:** vanilla base sources (CK `Scripts.zip`), SkyUI SDK, MCM Helper SDK. Until you add them, the glue ships declaration stubs under `tools/stubs/` (compile-only, never deployed).
- No Creation Kit → the ESL plugin is generated by script and should be opened once in SSEEdit to validate.
- OStim has never loaded in-game on this install — your manual OStim test (test plan step 1) comes first.
- Server deploy: updater never runs `git clean`, so `ext/<glue>` survives updates [V by check]; one deployment channel per environment; WSL default user is root → chown is mandatory.
- Keep `CHIM_AI_QUEST_PROGRESSION` off. `CORE_CONNECTOR_PLAYER` (Gemini Flash Lite) will likely sanitise adult summaries — your planned swap is advised.

## 7. Plan
- **Phase 1:** shared foundation (events, profiles, gates, context injection, config, MCM, logging with correlation ids) + OStim/OARE module (index, awareness, control, in-scene dialogue).
- **Phase 2:** menuless questing, Papyrus-only session driver first, with dry-run mode and the topic parity dump.
- **Phase 3:** Director/consequences hooks (event seams only).

## 8. Open items (honest)
Unverified: AIAgent message size limit; survival-mod exemption hook (Survival Mode Improved is enabled); quest-critical package detection; child race/voice EditorIDs in data (compressed records — needs the load-order index script); how the DLL resolves a bridge script name; vanilla-rank side effects [M]; ESL 1.71 rule [M]; seven research reports unverified; SHARMAT Q3–Q9.
