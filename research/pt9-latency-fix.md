# PT9 fix pass - the glue's own latency, and what a model swap really buys

> **CORRECTED 2026-09-22 by the reconciliation pass. Read `research/pt9-latency-reconcile.md` first.**
> Four things below are wrong or out of date and are marked `[CORRECTED]` where they appear:
> * the headline saving in section 5 (**~33 s per session, not ~133 s** - the rails drop *some*
>   unsolicited turns, not all of them);
> * two of the six changes in section 1 (items **4** and **5**, the RequestAct drop and the position
>   cap) have been **reverted** - they narrowed what she may choose. The measured prompt saving of
>   this round after the revert is **zero**;
> * the flow-test line quoted under "Tests" was produced by the tree *without* this round's changes;
> * section 4 items 1 and 2 (TTS) are superseded by the real measurements in
>   `research/pt9-tts-cpu.md`: PocketTTS costs **0.089 s** on an idle GPU, the 3.41 s was GPU
>   starvation, `threads: 8` not 12, and `audiofilterd` **does not exist in this distro**.
>
> `tools/test_latency_models.php` is now `tools/bench_llm_models.php` and **defaults to `--dry`**.

Written 2026-09-22. Input: `research/pt9-latency.md` (read in full). Two halves:

1. **Fixed in the plugin** - only inside this round's file scope (`lib/lrg_actions.php`, `lib/lrg_core.php`,
   `lib/lrg_intent.php`, `lib/lrg_scene_index.php`, `config/lrg_config.default.json`, new `lib/lrg_latency.php`
   and `tools/test_latency*.php`). **Nothing is deployed** - the source of truth is updated and tested.
2. **Measured, not guessed** - a capped benchmark of six models through the owner's own OpenRouter
   connector, with synthetic prompts. Total spend: **$0.0306**.

Nothing in CHIM was changed. Section 4 lists the owner-side settings with exact values.

---

## 1. What changed in the glue

| # | change | file | why (PT9 evidence) |
|---|---|---|---|
| 1 | an ordinary in-scene `lrg_scenetalk` tick is now dropped **before the MAIN lock** when the player spoke in the last 10 s, when her last spoken scene turn was under 45 s ago, or when another request already holds MAIN | `lib/lrg_latency.php` (new) + `lrgHandleGameMessage` | scene talk was **74 % of the plugin's whole latency cost**: 7.9 s median, 18.9 s p90, 22.6 s max of MAIN-held LLM + TTS, 112.8 s over 71 player lines |
| 2 | her lead tick keeps its old hold **and** is dropped when MAIN is busy | same | 3 of 12 lead/initiative turns spoke, 7.0-10.4 s each |
| 3 | an `lrg_initiative` tick is refused when MAIN is busy or the player spoke in the last 10 s; the "player spoke" clock is now written **outside** a scene too (~~one small write, still pre-lock~~ **[CORRECTED]**: it was a name resolution that can hit `core_npc_master`, a read and an **upsert** - see item 8, it is update-only now) | `lrgInitiativeAdmit`, `lrgNotePlayerSpeech` | ~~20.1 s per session~~ **[CORRECTED]**: **0.1 s** in the replayed session - the three ticks that actually spoke all pass the rails. Kept for its worst case (a tick landing on top of a player line), not for its median |
| 4 | ~~the RequestAct catalogue is **not injected** on a turn where the player asked for something specific~~ **[CORRECTED - REVERTED]** | `lrgSceneNotes` | ~~1,540 dead characters~~ (the block is in fact **1,362** characters - 78 header + 1,282 act list; the 1,540 figure was wrong everywhere it appeared and is corrected in the 0.5.1 fix pass) Not dead: nothing re-supplies the act vocabulary on that turn, so a change of act she makes alongside what he asked for would have silently failed the gate |
| 5 | ~~positions are capped per act **and** in total (`max_positions_per_act` 6 -> 4, new `max_positions_total` 20)~~ **[CORRECTED - REVERTED]** | same + config | ~~each repeating its own key~~ There are no duplicates in that list: spent greedily in act order the total cap took 42 position names to 20 and left the last three acts - `hold`, the wind-down act, among them - with none at all |
| 6 | the outro is never gated by any of this, and `followup.enabled=false` is untouched | - | one closing line per scene, with the NPC held still for it; funcret follow-ups were verified off (0 of 24 called the LLM) |
| 7 | **[ADDED by the reconciliation]** the scene-talk interval clock is armed where the line is really produced (`lrgPrepareTurn`), not at pre-lock admission | `lrgHandleGameMessage` -> `lrgPrepareTurn` | an admitted tick that prompts.php then refused blocked the next 45 s for a line nobody heard |
| 8 | **[ADDED by the reconciliation]** the out-of-scene "player spoke" clock is **update-only** | `lrgNotePlayerSpeech` | it used to upsert, i.e. create an `lrg_memory` row for every NPC the player ever spoke to |

**No rail, no gate and no wording changed** - **[CORRECTED]** which was true of items 1-3 and 6 and
was never true of items 4 and 5: both changed what the model is shown, and both are now out. One
undocumented non-latency change also shipped in `lib/lrg_actions.php` in this round's file: the
`[0.5.0 / E7(a)]` `requirements` block (condition truthfulness), which causes a **one-time catalog
reinstall** on the first request after deploy. It is kept - it is work the owner asked for - but it
belongs to E7, not to this round, and it is named here so nobody deploys it unaware.

### Measured prompt-token delta (fixtures = the flow tests' own turn modes)

`tools/test_latency_prompt.php` (new) replays ten turn modes and measures the two injections. Tokens are
the chars/4 estimate, the same ratio PT9 measured live (176 tok median for 705 characters).

**[CORRECTED]** The table below is what the round shipped. The reconciliation reverted both trims and
re-measured on the same three scene fixtures, so the final column is what the round is actually worth:

| turn | pre-round | as shipped (reverted) | **after reconciliation** |
|---|---|---|---|
| scene, player asked for something | 3,931 ch / ~983 tok | 2,569 ch / ~643 tok | **3,931 ch / ~983 tok** |
| scene, player speaks, no request | 3,684 ch / ~921 tok | 3,247 ch / ~812 tok | **3,684 ch / ~921 tok** |
| scene, lead tick | 2,215 / ~554 | 2,215 / ~554 | **2,215 / ~554** |
| scene-talk tick | 985 / ~247 | 985 / ~247 | 985 / ~247 |
| outro | 1,473 / ~369 | 1,473 / ~369 | 1,473 / ~369 |
| closed / public / private / follow / initiative | 1,815-2,162 | unchanged | unchanged |
| **mean over all ten fixtures** | **2,271 ch / ~568 tok** | 2,091 ch / ~523 tok | **2,271 ch / ~568 tok** |

Honest reading, **[CORRECTED]**: this round's prompt saving is **zero**. The 340 tokens it removed on
an "asked" turn were worth 0.05-0.15 s of LLM time against a 6,543-token prompt, and they were paid
for by narrowing her own choices. There were no duplicate or dead strings to remove: every
`act/position` pair in the list is distinct and the repeated act-id prefix is the key the model is
told to write back verbatim. The seconds this round buys are items 1-3, and far more of them are in
TTS (section 4 as corrected by `research/pt9-tts-cpu.md`).

### Tests

**[CORRECTED].** The run originally quoted here as `%TEMP%\lrg_pt9_fin` was, for the flow line only,
the output of `%TEMP%\lrg_pt9_base` - the tree *without* this round's changes (the isolation rolled
back three library files but left `context_pre.php` and `functions.php` at the new version, so the
"fin" tree called two functions that did not exist in it). The other four lines did reproduce. The
conclusion the line was carrying - that this round changes no behaviour - is independently true
(`research/pt9-latency-verify.md` section 1.2), it just was not what was quoted.

Re-run after the reconciliation, on the whole v0.5.0 tree with every other lane's work in place
(`%TEMP%\lrg_test\pt9r_recon`, 2026-09-22):

```
php -l  over 73 files                 0 errors
tools/test_latency.php       26 passed, 0 failed   (21 before; 5 new checks for the two fixes)
tools/test_gates.php        296 passed, 0 failed
tools/test_intent.php       190 passed, 0 failed
tools/test_phrases.php       14 passed, 0 failed   (hit rate 91.9 %, floor 82 %)
tools/bench_llm_models.php  dry by default: nothing sent, $0.0000 spent
tools/flows/run_flows.php --strict    73 scenarios, 73 passed, 0 FAILED, 0 pending, 1127 checks, 0 warnings
```

The same tree with only this lane's files at their as-shipped version (`pt9r_ship`) gives an
identical 296 / 190 / 14 / 73-of-73 - so the reconciliation changes exactly what it says it changes
and nothing else.

### A seam worth knowing about

The three new rails answer questions about *real* elapsed time and *real* concurrency, so they are inert
whenever the glue is driven by the offline harness's simulated clock (`$GLOBALS['LRG_TEST_NOW']`) unless a
test opts in with `$GLOBALS['LRG_LATENCY_LIVE']`. A flow fixture deliberately plays a tick in the same
simulated second as a player line - exactly the shape the rails drop - and a contract test about *what she
may say* must not start failing over *when* it plays its steps. `tools/test_latency.php` opts in and drives
every rail, including a fake "MAIN is busy" seam. On the live server neither global is ever set.

The MAIN probe is a non-blocking acquire of CHIM's own System V semaphore (`abs(crc32("MAIN"))`,
`lib/semaphore_manager.class.php`), taken and released in the same breath, at `main.php:193` - before this
request would take the lock. It answers "is another request in flight?" once per request, never runs under
CLI, and if a release were ever missed PHP's own `auto_release` frees it at the end of the request.

### New config keys (defaults; the owner's `lrg_config.json` only overrides `npc_overrides`, so these are live on deploy)

```
scene_talk.skip_when_busy          true
scene_talk.player_quiet_seconds    10
scene_talk.server_min_gap_seconds  45      (the game's own MCM gap is 25 s, its chance 35 %)
scene_talk.gap_exempt_moments      ["peak"]   a climax line may ignore the interval, never the other rails
initiative.skip_when_busy          true
initiative.player_quiet_seconds    10
```

Set any of them to `0` / `false` to switch that rail off.

**[CORRECTED]** `scene_index.max_positions_per_act` is back at its old **6** and
`scene_index.max_positions_total` has been **removed** (see items 4 and 5 above). If the act block is
ever capped again, spend the budget round-robin across acts so no act reaches zero.

---

## 2. The benchmark

`tools/bench_llm_models.php` (new; called `tools/test_latency_models.php` when this was written -
**[CORRECTED]**: renamed and switched to **dry by default** so no test sweep can spend money, and it
now prints `n` and the per-model prompt-token median and can write its raw samples with `--out`).
Method, all of it verifiable in the file:

- **One synthetic turn, invented in the file**: 25,340 characters (~6,335 tokens) in CHIM's own section
  shape (`<roleplay_instructions> <character> <actions> <nearby_actors> <knowledge> <world> <history>`),
  against PT9's real median of 6,543 prompt tokens. **No game, log, prompt or database text of the owner's
  is sent to any provider.**
- Same wire shape as CHIM: `stream: true`, `response_format` = strict `json_schema` with a
  message/action/target/item action schema, `max_tokens: 200`, `reasoning: {effort: none}`, usage included.
- Through **the owner's own connector**: his OpenRouter key, read from `core_api_badge` and never printed.
- **6 requests per model, 6 models**, 300 ms apart. Medians below; "worst" is the slowest of the six.
- `speech` = the moment the **first sentence inside `"message"` is finished** - that is what the player
  waits for, because CHIM only hands a *complete* sentence to TTS.
- The single right answer to the player's line is `TravelTo` + the named inn; `json` counts replies that
  parsed against the strict schema **and** chose it.

| model | ttft | speech | total | worst | $/1k turns | json | routing | note |
|---|---|---|---|---|---|---|---|---|
| **google/gemini-3.1-flash-lite** | **0.71 s** | **0.79 s** | **0.90 s** | **1.09 s** | $1.76 | **6/6** | Google + AI Studio (8 ep) | fastest and most reliable of the six |
| openai/gpt-5.4-nano | 0.80 s | 0.81 s | 1.13 s | 4.19 s | $1.41 | 6/6 | OpenAI + Azure (4 ep) | one 4.2 s outlier |
| **x-ai/grok-4.3** (configured primary) | 0.83 s | 0.92 s | 0.98 s | 1.16 s | **$8.39** | 4/6 | xAI only (4 ep) | twice answered with `action=none` |
| qwen/qwen3.8-flash | 1.68 s | 1.89 s | 2.43 s | 3.33 s | $1.02 | 6/6 | Alibaba (1 ep) | slowest that still never broke JSON |
| **deepseek/deepseek-v4-flash** (configured secondary+fallback) | 2.35 s | 2.41 s | 2.53 s | **5.85 s** | **$0.59** | 4/6 | AtlasCloud + DigitalOcean | one reply was not JSON |
| z-ai/glm-5.3-flash | - | - | - | - | $1.02 | **0/6** | - | **HTTP 400: "Reasoning is mandatory for this endpoint and cannot be disabled."** |

`$/1k turns` = the provider's list price applied to PT9's real turn (6,543 prompt + 83 completion tokens),
not to the synthetic one. **Total spent by this benchmark: $0.0306** (the failed GLM requests cost nothing).

Three findings that matter more than the ranking:

1. **DeepSeek V4 Flash, as the owner's connector has it, is the SLOWEST of the six** - 2.41 s to a finished
   sentence against Grok's 0.92 s, and a 5.85 s worst case - because OpenRouter routed it to two different
   providers inside six requests (AtlasCloud, DigitalOcean) out of its fifteen. It is 14x cheaper and that
   is real; the speed is not. Do **not** promote it to the main NPC slot as it stands.
2. **GLM 5.3 Flash is disqualified**, and cheaply: its endpoint refuses `reasoning: {effort: none}` outright.
   It would think before every line. (The owner's connector id 3 is GLM **5.2** on the tertiary slot - worth
   the same check.)
3. **Grok is not slow.** 0.92 s to a finished spoken sentence. The whole model question is worth at most
   ~1 s of a 7.4 s wait, exactly as PT9 said. It is worth **money**, not seconds: $8.39 per 1,000 turns
   against $1.76 for the fastest model in the table.

**What this benchmark cannot tell you:** how each model behaves on the owner's *explicit* content. The
synthetic prompt is clean by design. Google and OpenAI models are the likeliest of this set to refuse or
soften an explicit scene line, and a refusal costs a whole turn plus a retry - far more than the 0.13 s
Gemini wins on speed. That test can only be done in game, and it takes one scene.

---

## 3. Recommendation

### Main NPC slot (Profiles -> Default Profile -> **🕹️ Standard**, `core_profiles.llm_primary_id`, today connector 10 = `Grok 4.3`)

- **Trial primary: `google/gemini-3.1-flash-lite`** - **[CORRECTED] for the right reasons**: 6/6
  strict JSON with the right action against Grok's 4/6, and **$1.76 per 1,000 turns against $8.39 -
  a 79 % cost cut**. It is also 0.79 s vs 0.92 s to a finished sentence, but **n = 6 cannot separate
  0.13 s from routing noise** (the same table has a 4.19 s outlier inside one model's six requests),
  so speed is not a reason to move. Worst case 1.09 s vs 1.16 s; routing confined to Google's own two
  endpoints.
  It is also the natural replacement for the dead Gemma slot.
  **Decide it in one scene**: if it softens or refuses a line the owner asked for, revert the same minute -
  that single risk is the only thing standing against it, and it is not measurable from outside the game.
- **Keep `x-ai/grok-4.3` as the fallback** (`llm_fallback_id`, today connector 1). It is the one model in
  this install with a known-good record on the owner's content, and a fallback slot costs nothing until
  it is used.
- **If the owner would rather not risk a single refusal: change nothing here.** Grok stays primary, and
  the ~1 s the swap could buy stays on the table - it is dwarfed by item 1 of section 4 anyway.

### Fast / cheap slots

**[CORRECTED]** Named as the UI names them, with their current values, because the first draft named
none of the three that matter: **Director Mode 🎬** (`CORE_CONNECTOR_DIRECTOR`) = **10 Grok 4.3**,
**Profile Tasks 👥** (`CORE_CONNECTOR_PROFILES`) = **10 Grok 4.3**, **Scene Classifier 🎭**
(`CORE_CONNECTOR_SCENECLASSIFIER`) = 10 but switched off; **Background Life ⏱️** = 1,
**Background & Memory Tasks 🧠** = 4, **Summaries 📝** = 4, **Player Respeech 🎮** = 1. On the profile:
**⚡ Fast LLM** = 1 (already DeepSeek V4 Flash, so half of the advice below is already in place),
**💪 Powerful LLM** = 3 (GLM 5.2), **🧪 Experimental LLM** = 4, formatter = 6 (Ministral 8B).
Also: connector **2 `Gemini 2.5 Flash Lite` already exists and is unused**, so a Gemini trial needs
no new row - repoint or clone that one.

- **`deepseek/deepseek-v4-flash` ($0.59 per 1,000 turns)** for everything that is not the voice the player
  is waiting on - but only after **both** fixes below, which its connector row is missing today:
  - copy Grok's metadata: `{"extra_parameters":{"reasoning":{"effort":"none"}},"extra_parameters_enabled":true}`
    (connector 1 has `metadata = {}` and `reasoning_model = 1`: as it stands it thinks before it speaks),
  - pin the routing: `extra_parameters.provider = {"sort":"throughput"}`, or an explicit
    `{"order":[...],"allow_fallbacks":false}`. Fifteen endpoints of very different speed is exactly what
    the 5.85 s worst case above was.
- **If routing fiddling is not wanted: `qwen/qwen3.8-flash` ($1.02 per 1,000 turns)** - one single endpoint
  (Alibaba), so no routing variance at all, and 6/6 on strict JSON. It is slower (1.89 s), which does not
  matter for a diary entry or a formatter.
- **Leave the formatter (connector 6, Ministral 8B) alone** unless it misbehaves: known-good under CHIM's
  JSON schema and already cheap.

---

## 4. CHIM settings - exact values, expected saving per stage (nothing was changed)

Ordered by what they are worth. Stage numbers are PT9's measurements.

**[CORRECTED]** Items 1 and 2 were written before PocketTTS was measured. `research/pt9-tts-cpu.md`
has the real numbers and supersedes both; the table below is updated to match it, and the UI names
are the ones the owner actually sees (`research/pt9-latency-verify.md` D7).

| # | where (UI name) | change | expected |
|---|---|---|---|
| 1 | `/home/dwemer/audio.cpp/server.json` - **no web-UI field** (verified: `"backend": "cuda", "device": 0, "threads": 1`) | `"backend": "cpu"`, **`"threads": 8`** (not 12: 8/12/16/24 are within noise, 4 is 14 % slower), restart audio.cpp | **[CORRECTED]** measured cost of the move 0.089 s -> 0.615 s median; measured benefit is removing the GPU starvation that cost 3.41 s median / 6.90 s p90 / 97.6 s max in the session. Expected net **-2.8 s median, -5.5 s p90, tail gone** - the cost is measured, the benefit is inferred from the session's own bimodal log and needs the one-scene test in `pt9-tts-cpu.md` section 7 |
| 2 | `audiofilterd` | **[CORRECTED] there is nothing to start.** The daemon is not installed and has never been in this distro: only `tts/audiofilterd_client.php` exists, no binary, no package, no socket, no service script, nothing in the HerikaServer git history. The trim is a **one-line edit to a CHIM core file** (`tts/tts-pockettts.php` ~448, `$FFMPEG_FILTER=''` -> a `silenceremove` filter) | ~0.25-0.30 s of dead air per line (measured leading silence, same on both backends) at ~55 ms cost; also removes ~248 error lines per session. **A core edit is the OWNER's decision** - it is lost on a CHIM update. Doing nothing is defensible |
| 3 | **Global Settings** (`:8081/HerikaServer/ui/global_settings.php`) -> card **Context Selections** -> **Top-Level Sections** | untick **`<nearby_items>`**, **`<points_of_interest>`**, **`<group_descriptions>`** (the labels carry the angle brackets) | -0.1 to -0.4 s on crowded turns (`nearby_actors` is 784 tok median, **2,229 p90**) |
| 4 | **LLM Connectors** (`:8081/HerikaServer/ui/core/llm_connectors.php`) row **`Gemma 3N E4B`** (id 7) | delete it, or repoint **Model** to `google/gemini-3.1-flash-lite` | 0 s today - **verified: nothing points at it**, not one profile slot and not one `CORE_CONNECTOR_*` / `RELLLM_CONNECTOR` general setting, and the model is absent from OpenRouter's live list. A landmine, not a cost |
| 5 | **LLM Connectors** row **`DeepSeek V4 Flash`** (id 1) | **`Include Body Parameters (YAML)`** -> `reasoning:` / `effort: none`, tick the toggle beside it, leave **Disable Streaming** unticked, optionally pin **`Provider`** | it is the **⚡ Fast LLM, fallback and diary** slot today: as it stands a fallback turn thinks first |
| 6 | **Profiles** (`:8081/HerikaServer/ui/core/core_profiles.php`) -> **`Default Profile`** -> metadata -> **Context** -> **`CONTEXT_HISTORY`** | it is **75** there (the live value) and 50 in `conf_opts` (**dead** - no reader) | ~1 k prompt tokens per turn if lowered; no latency risk either way |
| 7 | **Profiles** -> **`Default Profile`** -> the **🕹️ Standard** slot (`core_profiles.llm_primary_id`, today 10) | model swap, section 3 | -0 to -0.13 s, **-79 % LLM cost**. **[CORRECTED]** the 0.13 s is inside the noise of n=6; lead with compliance (6/6 vs 4/6) and cost, not speed |
| 8 | **[ADDED]** **Global Settings** -> **Director Mode 🎬** (`CORE_CONNECTOR_DIRECTOR`) and **Profile Tasks 👥** (`CORE_CONNECTOR_PROFILES`) | both are Grok 4.3 today | 0 s - neither is on the voice path - but they are billed at Grok's $8.39/1k. **Scene Classifier 🎭** is a third Grok slot and is switched off |

**What NOT to change**

- **Streaming**: leave on (`"disable_streaming": false` on connector 10, `stream => true` on the wire).
- **`max_tokens`**: leave. The connector says 750, the real requests carry 200 (the glue's `speech.max_tokens`)
  and the median reply is 83 tokens. With streaming, lowering it saves nothing.
- **STT**: leave. Parakeet is 128 ms median, 255 ms p90, 381 ms worst, zero failures.
- **The glue's injected prompt block**: **[CORRECTED]** it is back at ~568 tokens mean, ~3 % of the
  prompt after the two trims were reverted, and the ten-fixture table above measures only the Phase 1
  injections - it is built through `fxTurn`, which reads `lrgStaticGuidance` / `lrgVolatileGuidance`
  directly and never runs `context_pre.php`, so `lorerim_glue_business_rules`,
  `lorerim_glue_locked_facts` and `lorerim_glue_business` are outside every number in it. Further
  cutting buys milliseconds and costs rails.
- **`followup.enabled=false` on the glue's action rows**: verified working (0 of 24 funcrets called the LLM).
  Keep it false.
- **`lrg_npcstate` / `lrg_scene` / `lrg_log`**: 5.5 requests per player line, 4.7 s total over nine hours,
  and none of them takes MAIN.
- **`RELATIONSHIP_UPDATE_CHANCE`**: runs in the separate worker, never holds MAIN, no latency effect.
- **The glue's `command_confirm_seconds` (40 s)**: already comfortably above the game-side say-first budget
  even after the MCM change below. No change needed.

**Game side (MCM, outside this round's file scope, unchanged):** `fSayFirstMaxWait` 20,000 -> ~8,000 ms and
`fSayFirstSettle` 1,000 -> 500 ms. Her line now takes ~7 s to finish, not 20, and the scene starts that much
sooner. This is the biggest *perceived* glue cost on a scene-start turn.

---

## 5. Where the 7.4 s goes after all of this

Nothing here touches the two biggest links - they are owner-side (section 4, items 1 and 2):

**[CORRECTED] - every line of the old block below was either superseded or overstated:**

```
PTT -> STT        0.13 s   unchanged
prompt build      0.59 s   UNCHANGED: the two prompt trims are reverted, the saving is 0
LLM -> 1st line   1.47 s   -0.1 s with the model swap, or unchanged (inside the noise of n=6)
TTS one sentence  3.41 s   ~-2.8 s median / -5.5 s p90 with backend: cpu, threads 8   <- the fix that matters
leading silence   0.28 s   -0.25 to -0.30 s, but ONLY via a CHIM core one-liner: the daemon
                           does not exist in this distro. The owner's call, not ours
MAIN convoy       p90      this round removes ~33 s of MAIN-held LLM + TTS per nine-hour
                           session, not ~133 s - replayed tick by tick against the real
                           session: scene talk 7 admitted / 3 dropped = 32.9 s of 112.8 s,
                           initiative 3 admitted / 9 dropped = 0.1 s of 20.1 s (the nine it
                           drops were the 4 ms ticks that were already refused downstream).
                           ~5 % of player-turn server time, and it removes two of the three
                           worst pile-ups of the evening. Rail 3 (initiative) buys ~nothing
                           in this session and is kept for its worst case, not its median.
```
