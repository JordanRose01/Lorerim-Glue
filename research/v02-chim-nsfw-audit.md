# R5 audit - what in CHIM 3.3.2 can sanitise or block adult language, and what to change

Date 2026-09-21. Read-only. Nothing in CHIM was changed: no file, no setting, no DB row.
Sources: HerikaServer 3.3.2 source under `\\wsl.localhost\DwemerAI4Skyrim3\var\www\html\HerikaServer`, and the owner's LIVE settings read
from the `dwemer` database with `SELECT` statements only (session forced to `default_transaction_read_only = on`; API keys never selected).
Line numbers are from the installed files.

## Verdict
CHIM itself contains almost nothing that sanitises content. There is **one real blocker in code** (a crude "refusal detector" that can replace an
NPC sentence), **one model-side risk that does not apply to the current connector assignment** (Google safety settings), **one feature that works
against the glue's per-NPC consent model once it is repaired** (Scene Classifier), and **one CHIM setting that silently freezes interest** (locked
relationships). Prompts, the action catalog and the confirmation system are clean for the glue's actions. The "too SFW" language of playtest 2
came from the glue's own v1 prompt text (it forbade explicit wording outside a scene and pushed even willing NPCs toward restraint) - that is
what this round rewrote.

## Findings

### 1. `checkOAIComplains` - a word-scoring "refusal detector" that rewrites NPC sentences. BLOCKER, handled by the glue, nothing for the owner to do
- `lib/chat_helper_functions.php:589-655` scores EVERY sentence before it is voiced: +1 for each of `can't`, `apologi`, `sorry`, `not able`,
  `won't be able`, `generate`, `request`, `policy`, `to provide`, `context`, `unable`, `assist`, `inappropriate`, `explicit`, `roleplay`;
  +2 `that direction`; +3 `openai`, `please provide an alternative scenario`; +4 `AI language model`.
- `lib/chat_helper_functions.php:1380-1385`: score >= 3 -> the sentence is replaced by `$ERROR_OPENAI_POLICY` (the canned line defined at
  `prompts/command_prompt.php:66`), `$ERROR_TRIGGERED = true`, `$FORCED_STOP = true` (the rest of the reply is cut).
- Why it matters here: it does not look for a provider refusal, it looks for ordinary words. An in-character refusal ("sorry", "can't",
  "inappropriate" in one sentence) or blunt talk that happens to contain three trigger words is thrown away and the NPC says the canned line.
  That is exactly the kind of sentence the glue asks for (a plain "no", or direct talk).
- There is NO setting for it in the web UI. The only switch is the global `OPENAI_FILTER_DISABLED` (`lib/chat_helper_functions.php:593`), which
  CHIM's own script-writer add-on sets (`ui/addons/scriptwriter/HerikaScriptWriter.php:43`).
- What the glue does (its own runtime flag, no CHIM file or setting touched): `lrgApplyTurnRuntime()` sets `$GLOBALS['OPENAI_FILTER_DISABLED']`
  for the current request only, and only on turns the glue guides (mode closed / public / private / follow / scene). Silent turns (non-adults,
  strangers to the feature, other request types) keep CHIM's filter. Switch: `language.disable_chim_refusal_filter` (default `true`) in
  `lrg_config.json`.
- Trade-off, stated honestly: if the provider ever really refuses, the refusal text would be voiced instead of the canned line. With Grok 4.3 and
  a strict JSON schema playtest 2 had 0 refusals in 52 generations (`research/pt2-latency.md`, section 5).

### 2. Google / Gemini safety settings - NOT active in the current assignment; keep Google models out of every slot
- `connector/openrouterjson.php:657-667` (streaming) and `:1354-1364` (fast request): `safety_settings` with `BLOCK_NONE` for all four harm
  categories are only sent when the connector's metadata contains `block_none` AND the model is a Google model. Without it Google's default
  filters apply, and Gemini then blocks or softens sexual content on the provider side.
- No file under `ui/` references `block_none`: there is no checkbox for it in 3.3.2; it can only be set inside the connector row's metadata JSON.
- Live connector table: id 2 `google/gemini-2.5-flash-lite` and id 7 `google/gemma-3n-e4b-it` have metadata `{}` (no `block_none`).
- Live slot assignment (profile 1 "Default Profile" + Global Connectors):

| slot | connector | model |
|---|---|---|
| NPC dialogue (primary) | 10 | x-ai/grok-4.3 (reasoning effort none) |
| secondary / fallback / diary | 1 | deepseek/deepseek-v4-flash |
| tertiary | 3 | z-ai/glm-5.2 |
| quaternary | 4 | deepseek/deepseek-v4-pro |
| formatter | 6 | mistralai/ministral-8b-2512 |
| Player Respeech (`CORE_CONNECTOR_PLAYER`, enabled) | 1 | deepseek/deepseek-v4-flash |
| Summaries (`CORE_CONNECTOR_SUMMARY`) | 4 | deepseek/deepseek-v4-pro |
| Background & Memory Tasks (`CORE_CONNECTOR_MEDIUMTERM`) | 4 | deepseek/deepseek-v4-pro |
| Profile Tasks / Director Mode / BGL | 1 | deepseek/deepseek-v4-flash |
| Scene Classifier (`CORE_CONNECTOR_SCENECLASSIFIER`, enabled) | 7 | google/gemma-3n-e4b-it (answers HTTP 404, see 3) |
| Relationship system (`RELLLM_CONNECTOR`) | 5 | mistralai/mistral-small-3.2-24b-instruct |

  So the earlier note "Player connector is still Gemini Flash Lite" is OUT OF DATE: it is DeepSeek V4 Flash now, and no dialogue, summary,
  diary or memory slot uses a Google model.
- Owner action: none needed today. Rule for the future: web UI -> Global settings -> section "Global Connectors", and the profile's LLM slots:
  never pick "Gemini 2.5 Flash Lite" or "Gemma 3N E4B" for Player Respeech, Summaries, Background & Memory Tasks, Profile Tasks or Diary.

### 3. Scene Classifier - dead today (the 404 of playtest 2); if repaired it would work AGAINST per-NPC consent. Recommended: switch it OFF
- `processor/postrequest.php:95-160`: after every player turn a fast request classifies the last dialogue into `horror, action, thriller, mystery,
  romance, comedy, drama, nsfw`. It uses `CORE_CONNECTOR_SCENECLASSIFIER` = connector 7 = `google/gemma-3n-e4b-it`, which OpenRouter answers
  with 404. This IS the failing fast request measured in `research/pt2-latency.md` (43 of 43 failed; after the MAIN lock, so no latency).
- `processor/postrequest.php:197-208`: when the result is `romance` CHIM inserts a scene note, valid 60 s, for the whole scene:
  "Overall ambient seems intimate. Actors should behave in a intimate way". `scene_notes` is an enabled prompt section (`PROMPT_CONTEXT_OPTIONS`).
  That note is addressed to every actor present, including NPCs who are not interested - the opposite of the rail "not a global mode; an NPC who is
  not interested gets nothing".
- Owner action: web UI -> Global settings -> Global Connectors -> "Scene Classifier" -> switch the availability toggle OFF
  (`SCENE_CLASSIFIER_ENABLED = false`). That also removes the failing call after every turn. Do not "fix" it by pointing it at a working model.

### 4. Locked relationships freeze interest - not a content filter, but it stops R2 from ever moving
- chim.log in playtest 2: "relationships are LOCKED in the editor - skipping AI re-evaluation" for Lisette (`research/pt2-latency.md`, cause 2).
  The glue's interest word is derived from CHIM's relationship affinity; a locked relationship never changes, so a locked NPC stays at the same
  word (for example "curious") forever, whatever the player does.
- Owner action: web UI -> the NPC's relationship editor (plugin "relationship_system") -> unlock the relationship with the player, or set the
  affinity by hand. `RELATIONSHIP_UPDATE_CHANCE = 50` (Global settings; `lib/relationship_manager.php:166`, `ext/relationship_system/postrequest.php:232`) is a percent gate on the automatic evaluation: about every second exchange is evaluated; raise it if interest feels slow. The lock itself is checked at `ext/relationship_system/postrequest.php:212-220`.

### 5. Action confirmation defaults - clean for the glue's code names
- `lib/core/action_catalog.php:364-379` `herikaActionCatalogGetDefaultConfirmationPolicy()`: ask-by-default only for the code names `arrestplayer,
  acceptsex, kiss, makelove, removeclothes, sexaction, takehelditem, takegoldfromplayer` (the adult-looking ones belong to other plugins).
  The glue's `ExtCmdLRG_*` names are not on the list, and every glue row sets `confirmation.default_policy = automatic` explicitly.
- `lib/core/action_catalog.php:415-449`: a per-row "Require Confirmation" switch in the Action Editor overrides that (`custom_config.confirmation_required`)
  and turns the wire channel into `confirmcommand`. Owner: leave "Require Confirmation" OFF for Begin_Intimacy, Change_Intimacy, Change_Clothing
  and Suggest_Privacy, otherwise every step waits for a message box. (CHIM shows the display names snake-cased: `action_catalog.php:704-728`.)
- The glue's post-LLM gate keeps whatever channel CHIM chose, so a confirmed action still passes through it.

### 6. Prompts - clean
- `prompts/command_prompt.php` (78 lines): action boilerplate only. No content rule.
- `general_settings.PROMPT_HEAD` (live): pure role-play framing ("embody this persona with absolute conviction"), no content limits.
- DB table `prompts`: 62 keys, 0 customised. A scan of every text for limiting language (nsfw / sfw / explicit / sexual / graphic / censor /
  appropriate / family / tasteful / avoid / non-graphic / mature / vulgar / profan) found no content rule: the only hits are "avoid repeating
  sentences" in the `dialogue_line_*` templates and "Do not include any introductory text" in the `dynamic_prompt_*` templates.
  `summary_prompt`, `memory_subsystem_summary`, `middleterm_narrative_*`, `player_diary_prompt`, the profile's `DIARY_PROMPT`,
  `player_respeech_*`, `rel_llm_*`: neutral.
- `EMOTEMOODS` includes `sexy` and `seductive`; nothing is filtered there.
- No moderation endpoint is called anywhere (`grep -rn moderation connector lib processor main.php`: no hit).

### 7. Summary / diary / memory models - cannot be proven either way from the source
- They are DeepSeek V4 Pro / Flash (table above). Their prompts are neutral; whether the models soften what they summarise can only be seen in
  the results. The glue keeps its own footprint small on purpose: only one neutral line at scene start and one at scene end enter CHIM's event
  log; pace / position / clothing results never do (`suppress_placeholder_infoaction`). The NPC's spoken lines are CHIM's normal speech log.
- Owner check after the next playtest: web UI -> Memories / Diary of that NPC. If the night reads as if nothing happened, set Global settings ->
  Global Connectors -> "Summaries" and "Background & Memory Tasks" to connector "Grok 4.3". Cost goes up; dialogue is unaffected either way.

### 8. Not applicable on this install
- `stt/stt-azure.php:31` masks profanity in Azure speech-to-text by default. The owner uses Parakeet (local), which has no such option.
- The LLM randomizer (`lib/llm_randomizer.php:48`) could move dialogue turns to GLM / DeepSeek; playtest 2 shows it is off (52 of 52 dialogue
  generations were Grok).
- `LLM_FALLBACK_ENABLED = 1` -> connector 1 (DeepSeek V4 Flash) answers when Grok fails. A fallback turn may read tamer; it is rare and harmless.

## What the owner should change, in one list
1. Global settings -> Global Connectors -> **Scene Classifier: OFF**. (Removes the 404 after every turn and a global "behave intimately" note.)
2. Relationship editor -> **unlock** the relationship of NPCs you want to court (Lisette is locked), otherwise interest never changes.
3. Action Editor -> leave **Require Confirmation OFF** on the four LoreRim Glue actions.
4. Keep every Google model out of all slots (nothing to change today).
5. Optional, only if memories read sanitised after a playtest: Summaries + Background & Memory Tasks -> Grok 4.3.
6. Already in the playtest-2 notes, unchanged: PocketTTS off the game's GPU (or an FPS cap); keep "AI Quest Progression" off (it is: `CHIM_AI_QUEST_PROGRESSION = false`).
Nothing else in CHIM needs touching. The glue's MCM explicitness slider (0 / 1 / 2) is the one content control; level 2 is the default.
