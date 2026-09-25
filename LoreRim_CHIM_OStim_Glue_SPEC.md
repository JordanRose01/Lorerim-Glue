# LoreRim × CHIM × OStim × OARE Integration Layer ("the glue") — Build Spec

## What this is (read first)
This project is **the glue** between four systems: **LoreRim**, **CHIM**, **OStim Standalone** and **OARE**. Its most important feature is **MENULESS QUESTING**: the player never opens a vanilla dialogue menu. Every conversation, including quest dialogue, runs through CHIM's AI talk, and quests still start, progress and complete correctly.

**Core requirements, which override everything else in this spec:**
1. **Menuless questing is the headline feature.** Quest dialogue in LoreRim works through CHIM conversation instead of dialogue menus.
2. **Menuless questing must be fully compatible with LoreRim.** It must work with LoreRim's quest content as installed (vanilla, DLC, Requiem, and LoreRim's added quest and expansion mods), without editing LoreRim's plugins. It has to be generic and data-driven, not hand-patched quest by quest.
3. **Menuless questing must be fully compatible with OStim.** Romance, seduction and intimate scenes are part of the same conversation system, not a separate mode. A quest conversation can lead into romance and back, and an OStim scene never breaks quest state or dialogue.
4. **One shared foundation.** The OStim features and menuless questing are built on the same core (conversation → action mapping, event reporting, per-NPC profiles, context injection, safety gates). Everything below is a module of that one system.

**Build in phases on that shared foundation:**
- **Phase 0 (research, before any code):** find out exactly what CHIM 3.3.x already does toward menuless dialogue and quests (its stated goal is to never press "E"), what MinAI did, and how vanilla dialogue topics, conditions and quest stages can be triggered programmatically (Papyrus/SKSE). Report findings and a feasibility assessment, including hard limits (scripted scenes, forced greetings, choices with branching consequences, voiced vs. silent lines), with a proposed approach for each.
- **Phase 1:** core foundation plus the OStim/OARE module (sections below).
- **Phase 2:** the menuless questing module (section below).
- **Phase 3:** hooks for the AI Director and consequences.

Grounded "Game of Thrones" tone. No fetish content.

## Environment
- LoreRim 5.x (Wabbajack), portable MO2 2.5.2 at `F:\Modlists\LoreRim`, active profile **Ultra**. Game: `F:\Modlists\LoreRim\Stock Game`, Skyrim **1.6.1170** (not 1.7.x).
- CHIM = AIAgent **3.3.2** + Prisma UI 1.5.1, installed at the bottom of MO2's left pane. `AIAgent.esp` is last in the load order.
- CHIM server: DwemerDistro **3.2.0** at `F:\DwemerDistro`, WSL distro `DwemerAI4Skyrim3`. Find HerikaServer and its `ext/` plugin folder yourself.
- LLM: OpenRouter, Grok 4.3 (reasoning none) as Standard. Note: Grok tends to be agreeable, so the design must counter that.
- To be installed before testing: OStim Standalone 7.5.1 (1.6.1170 build), **Open Animations RE for OStim Standalone 1.52.1 (OARE)**, OStim Community Resource 1.17.6, possibly OBody NG 4.4.3. Amorous Adventures is **not** used. No arousal framework.
- Already present in LoreRim: SKSE, Address Library, PapyrusUtil, JContainers, powerofthree's Papyrus Extender, ConsoleUtilSSE NG, SkyUI, MCM Helper, SPID, KID, Fuz Ro D-oh.
- A build log exists (`lorerim-chim-build-log.md`). Read it if provided.

## Architecture
Two halves that use CHIM's existing systems. Add to them, never replace.

1. **Game side**: a new ESL-flagged plugin plus Papyrus scripts (and an SKSE/C++ component only if clearly justified).
   - Listen for OStim Standalone scene start/end and scene/stage changes, and report them to CHIM as game events.
   - Listen for CHIM's plugin command event and start an OStim scene between the named NPC and the player.
   - Read per-NPC data: factions, class, vanilla actor values (Confidence, Morality, Aggression), marriage status, relationship rank, whether others are nearby or can see.
2. **Server side**: a CHIM plugin (manifest.json plus files, following the CHIM plugin docs).
   - Register an AI-selectable action (for example "StartIntimacy"), offered **only** when the gates pass.
   - Inject scene state and a short per-NPC status/personality note into NPC context.
   - Scene events flow through CHIM's normal event stream, so the existing memory, diary and TXT2VEC systems record them. Do not build a separate memory system.

## Seduction model
**Per-NPC strictness, not global rules.** Compute a strictness profile from:
- Social status from factions and class: Jarl, noble, housecarl, guard, priest (by deity), merchant, innkeeper, commoner, beggar, adventurer.
- Vanilla actor values: Confidence, Morality, Aggression.
- Marriage and relationship status.
- Relationship rank with the player and interaction history.
- CHIM's existing NPC bio and personality.

**Status rules in a data-driven config file** (JSON/INI, editable without recompiling). They must also cover NPCs and factions added by LoreRim's mods (new towns, followers, expansions), not just vanilla ones: scan the actual load order and fall back gracefully for unknown factions using class and actor values. For example:
- Jarls and nobility: very strict. Require a high relationship and total privacy; they fear scandal.
- Priests: depends on the deity. Dibella open, Mara wants commitment, Vigilants refuse.
- Married NPCs: mostly refuse; any yes is secret and risky.
- Commoners, tavern folk, adventurers: more relaxed.

**Layer 1: hard gates.** If these fail, the action is not offered to the LLM at all:
- Relationship threshold (per profile), not in combat, privacy (no witnesses nearby or in view), marriage rule, adult check, feature toggle on.

**Layer 2: LLM guidance** injected into context:
- Agree only if it fits the NPC's personality, status, history with the player, and the moment.
- Refusal is normal and final. Strangers almost always refuse.
- Explicitly counter LLM agreeableness.
- A short status note, for example "Jarl Elisif: high status, cautious, fears scandal."

**Optional (build behind a toggle):** a receptiveness hint derived from Speech skill plus relationship, passed to the LLM as a word ("hesitant" or "interested"), never as a number.

## Scene awareness and control
**Animation library awareness**
- At startup (and after changes), build an index of every OStim scene actually installed: OARE plus any other packs. Use OStim's own scene data (scene IDs, actions, tags, actor counts, furniture requirements, transitions/navigation). Never hardcode animation lists.
- Map scenes to short, neutral, human-readable descriptions for the LLM, derived from OStim metadata/tags.
- Only ever offer or request scenes that exist in the index and are valid for the current actors, furniture and position.

**OARE 1.52.1 integration (primary target)**
- OARE is the main animation pack and must work fully and fluidly. Study its actual scene files, tags, actions, navigation/transition graph, furniture scenes, and romantic/non-explicit scenes (kissing, embracing, cuddling) directly from the installed files.
- Use OARE's own transitions and navigation paths for every change. No snapping, teleporting, or restarting scenes. Changes happen at natural transition points.
- Use OARE's gentler scenes for build-up and afterglow (for example a kiss or embrace first, cuddling after), so scenes flow naturally from conversation into intimacy and back out.
- Sync dialogue timing to the animation (don't speak over a transition; react after it lands).
- Never modify OARE's files. Integrate through OStim's API and scene data only.
- Keep it version-tolerant: rebuild the index when OARE updates, and log (don't crash on) missing or renamed scenes. Other packs should still work through the same generic index.

**Live scene awareness**
- Track the current scene and stage in real time (scene ID, action, participants, furniture). Push changes into the NPC's context so dialogue during the scene matches what is actually happening.
- Record notable scene changes in CHIM's normal event stream (compact, not every stage tick) so memories and diary reflect them.

**Conversational control**
- The player can ask for a specific position or change in natural language. The LLM maps the request onto the index (via tags) and the plugin transitions using OStim's navigation/API. It must pick a valid path, not teleport into an incompatible scene.
- The NPC can also suggest changes on their own, based on personality and the moment.
- Consent applies per change: the NPC may decline a specific request based on personality and status, and a decline is respected.
- Graceful fallbacks: if a request has no matching or reachable scene, the NPC responds in character and nothing breaks.
- Ending the scene by conversation ("let's stop") must always work immediately.

**In-scene dialogue**
- NPCs occasionally speak during scenes through CHIM's normal AI dialogue and TTS, including explicit or dirty talk when it fits the character.
- *Occasional*, not constant: a configurable frequency plus random cooldowns, and triggers on meaningful moments (scene start, position change, climax, player speaking to them). No looping chatter.
- Personality- and status-dependent: some NPCs are vocal and crude, some shy and quiet, some romantic, some never talk dirty at all. Drive this from the per-NPC profile plus the CHIM bio, with an optional per-NPC override in the config.
- Must reflect the actual current scene and relationship, and avoid repetition (keep recent lines in context).
- The NPC responds naturally when the player talks during a scene.
- Keep lines short so TTS latency stays low. Use the Fast LLM connector for in-scene lines if that proves faster.
- Don't write every line into memory. Only the scene summary, which goes through CHIM's normal event and memory flow.
- MCM: global on/off, frequency slider, explicitness level (suggestive ↔ explicit).

**Performance**
- Throttle and debounce LLM calls during scenes (latency and cost). Batch stage updates. Never block the game thread waiting on the LLM.

## Menuless questing module (headline feature)
**Goal:** the player talks naturally; the NPC gives, discusses, progresses and completes quests through CHIM; the dialogue menu never appears.

**How it should work (validate in Phase 0):**
- When the player talks to an NPC, read that NPC's currently *available* dialogue topics and responses, with conditions evaluated live against game state: quest starts, quest progress, persuade/intimidate/bribe options, services, and the choices in branching quests.
- Pass the available options to the LLM as selectable actions, with short summaries of what each does and its consequences. The NPC works the quest information into natural speech in their own voice and personality, and keeps the key facts (names, places, objectives) intact.
- When the conversation clearly reaches one of those options, the plugin executes that dialogue topic's real effect (its result scripts, quest stage changes, item transfers, and so on), exactly as if the player had clicked it. Quest logic stays 100% vanilla or mod-authored, and nothing is reimplemented.
- Branching choices: the player's intent in conversation decides the branch. If the intent is ambiguous, the NPC asks. Irreversible choices need clear intent.
- Persuade/intimidate/bribe: the player argues it out in conversation; the LLM judges how convincing it was, and the plugin gates it on the real skill/gold checks from the game data.
- Forced greetings, scripted scenes and quest-critical voiced lines: handle them gracefully (let them play, then continue through CHIM). Never break a scripted scene.
- Quest awareness: NPCs know the player's active quests and objectives relevant to them, and react to them.
- Fallback: an MCM hotkey opens the vanilla dialogue menu as an emergency escape if a quest gets stuck. It should rarely be needed, but it must always be there.

**LoreRim compatibility (required):**
- Generic and data-driven: works from the dialogue and quest data at runtime, so it covers vanilla, DLC and LoreRim's added quest mods without per-quest patches.
- No edits to LoreRim plugins, no load-order changes to LoreRim's list, no conflicts with Requiem, Fuz Ro D-oh or LoreRim's dialogue/UI mods (note: CHIM's `dialoguemenu.swf` already overrides Norden UI's).
- Must survive LoreRim updates; log unsupported cases instead of breaking.
- Where a specific quest needs special handling, use a small, optional, data-only override file, never a plugin edit.

**OStim compatibility (required):**
- The same conversation can move between quest talk, romance and intimacy. The OStim gates and profiles apply unchanged.
- OStim scenes never advance, fail or corrupt quest stages. Quest-critical NPCs remain protected by the safety rules.
- Romance progression (relationship rank) is shared with quest outcomes, so helping someone in a quest can improve the relationship.

## Hard safety rules (non-negotiable)
- The player must be a participant in every scene. No NPC-only scenes started by this plugin.
- Adults only. Rely on OStim's own child exclusion **and** add an independent check.
- The NPC must have clearly agreed in conversation. A refusal cannot be overridden or forced.
- A global kill switch in the MCM or config.

## Extensibility
- Design clean hooks for a later **consequences** system (scandal, reputation, witnesses, jealous spouses).
- Design clean hooks for a planned **AI Director** plugin (tension/story-beat driven) and a later menuless questing patch.

## Constraints
- Never modify LoreRim's own mods or files. All new content goes in new MO2 mods at the bottom of the left pane.
- Do not regenerate Nemesis output. The user will do that separately.
- Verify every OStim, CHIM and SKSE event, function and API name against actual source or docs. Do not guess.
- Reference implementation: MinAI (github.com/MinLL/MinAI), which solved a similar OStim ↔ CHIM bridge for an older CHIM. Study it, but target the CHIM 3.3.x APIs.
- Papyrus compilation needs the Creation Kit compiler and script sources. Check for these and tell the user what is missing.
- Menuless questing must never break LoreRim quests or OStim. When in doubt, prefer the solution that keeps vanilla/mod quest logic untouched.
- If you are unsure about a design decision, ask the user before building.

## Deliverables
1. Source code (game side and server side) with a clear folder structure.
2. The config file for status rules and thresholds, with sensible defaults.
3. MCM (via MCM Helper) for toggles and thresholds.
4. Step-by-step install instructions for MO2 and the server plugin.
5. A test plan:
   - Confirm OStim works manually.
   - Serana only: a refusal as a near-stranger, success after relationship build-up, a refusal in public, and memory recall afterwards.
   - Then a Jarl, a married NPC, a priest of Dibella, and a tavern NPC.
   - Confirm the gates block the action and that the kill switch works.
   - Scene control: the index lists OARE scenes; the NPC correctly describes the current scene; a verbal position request transitions correctly; an impossible request fails gracefully; the NPC declines a request that doesn't fit their personality; a verbal "stop" ends the scene instantly.
   - Menuless questing: complete a simple vanilla quest (e.g. a Whiterun quest), a branching quest, a persuade check, and a quest from one of LoreRim's added quest mods, entirely without the dialogue menu. Confirm the quest stages match a normal playthrough.
   - Compatibility: in the same conversation, go from quest talk into romance and back; confirm an OStim scene with a quest NPC doesn't alter quest state; confirm the emergency dialogue hotkey works.
6. A Phase 0 research report before any code: what CHIM 3.3.x already offers, the feasibility of each menuless-questing case, risks, and the proposed approach.
