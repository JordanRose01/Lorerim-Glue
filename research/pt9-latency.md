# PT9 - Where the NPC's voice latency actually goes

Read-only measurement, 2026-09-22. Session measured: **2026-09-21, 13:00-22:30 local (EDT)**.
Nothing was changed. Scripts used (throwaway, in `C:\Users\Jordan\AppData\Local\Temp\lrg_test\`):
`lrg_latency.py`, `lrg_chain2.py`, `lrg_tts.py`, `lrg_tts2.py`, `lrg_glue_cost.py`, `probe_audio.sh`, `or_endpoints.sh`.

## 0. Clocks (verified, not assumed)

| source | stamp | conversion to local EDT |
|---|---|---|
| `/var/log/apache2/other_vhosts_access.log` | `[21/Sep/2026:22:17:59 -0400]` | already local |
| `/var/log/apache2/error.log` | `[Mon Sep 21 22:16:23.258 2026]` | already local |
| `log/chim.log` (Logger) | `2026-09-21T23:13:28+02:00` | local = t - 6 h |
| `log/chim.log` (error_log path) | `2026-09-21T21:13:59+00:00` | local = t - 4 h |
| `audit_request.created_at` | naive `2026-09-22 04:17:45` | local = t - 6 h |
| `log/lorerim_glue.log` | `-04:00` since 0.3 | already local |

Cross-check: chim.log's last PERF line `04:17:59+02:00` and the access log's last line `22:17:59 -0400` are the same instant.

**Coverage warning.** `chim.log` had been trimmed: it only starts at **17:13:28 local**, so the
millisecond-resolution server breakdown covers 17:13-22:17 (71 player lines). The apache logs cover the
whole day (92 player lines, 97 STT uploads in the window), and the STT and end-to-end numbers use those.

## 1. The chain, reconstructed

A player line is served by **`streamv2.php`** (which includes `main.php`), not by `comm.php`. The game
polls `comm.php` about twice a second (19,701 polls in the access log) and pulls the finished audio with a
separate `GET /HerikaServer/soundcache/<hash>.wav`, which is the first thing that can be played.

```
PTT release
  -> POST /HerikaServer/stt.php            (game DLL, HTTPUploader)
       -> parakeet @ 127.0.0.1:8022        (in the DwemerAI4Skyrim3 distro, NOT on the game GPU)
  -> GET  /HerikaServer/streamv2.php?DATA=inputtext|...
       -> MAIN semaphore
       -> profile / history / memory / oghma / prompt build
       -> [PRE LLM CALL] -> OpenRouter x-ai/grok-4.3, stream=true
       -> per sentence: PocketTTS @ 127.0.0.1:8086 (audio.cpp, CUDA device 0, threads 1)
          -> audiofilterd.sock (DOWN) -> ffmpeg fallback -> soundcache/<hash>.wav
       -> sentence pushed over SSE
  -> GET /HerikaServer/soundcache/<hash>.wav   <- playback starts here
```

### Stage table, medians and p90

| stage | median | p90 | n | evidence |
|---|---|---|---|---|
| PTT release -> STT upload | not instrumented | | | first server trace is the `stt.php` POST |
| STT transcription (parakeet, in-distro) | **0.128 s** | 0.255 s | 90 | apache error.log `Transcription time: …ms`, max 0.381 s, 0 failures |
| STT POST -> streamv2 start | 0 s | 1 s | 90 | access log, 1 s resolution |
| MAIN semaphore wait | **0.007 s** | 0.105 s | 71 | `[PERF] lock_ready`, **max 12.87 s** |
| prompt build (profile+history+memory+oghma+prompt) | **0.59 s** | 0.70 s | 70 | `[PERF]` phases, max 1.42 s |
| request start -> `[PRE LLM CALL]` | 0.61 s | 0.78 s | 70 | `Audit … [PRE LLM CALL] … elapsed time` |
| LLM -> **first spoken sentence** | **1.47 s** | 2.45 s | 71 | PRE LLM CALL -> first `[INLINE_NARRATION]` |
| LLM total, whole reply, TTS removed | 1.86 s | 2.73 s | 70 | `llm_complete` minus summed TTS |
| **TTS, one sentence** | **3.41 s** | 6.94 s | 132 | soundcache `.txt` sidecars, `total call time` |
| ffmpeg fallback inside that | 0.055 s | 0.059 s | 132 | same sidecars |
| TTS finished -> game fetches the wav | 0 s | 1 s | 71 | `Speech sent` -> `GET /soundcache/*.wav` |
| **PTT upload -> first audio at the game** | **7 s** | **21 s** | 90 | `stt.php` POST -> first wav GET |
| streamv2 server total | 7.73 s | 11.88 s | 71 | `[PERF] total_ms`, max 53.6 s |
| leading silence inside the wav | 0.40 s | 0.52 s | 60 | `ffmpeg silencedetect`, max 0.77 s |

**The player hears the NPC about 7.4 s after releasing push-to-talk (7 s + 0.4 s of untrimmed silence),
and 21 s or worse one turn in ten.**

### Where the server seconds go (71 player turns = 603 s of `streamv2` time)

| | seconds | share |
|---|---|---|
| TTS | 338 | **56 %** |
| LLM | 163 | 27 % |
| prompt build | 43 | 7 % |
| MAIN lock wait | 34 | 6 % |

## 2. Slowest links, ranked

**1. PocketTTS - 56 % of server time and 3.4 s of the 7 s to first audio.**
`/home/dwemer/audio.cpp/server.json`: `"backend": "cuda", "device": 0, "threads": 1` - the same RTX 4080
the game renders on, through WSL2 GPU-PV, one thread.
Measured: **3.41 s median per call to produce 2.64 s of audio = 1.26x realtime** (n=60). It is
*slower than realtime*. The cost is almost all fixed:

```
fit over 124 calls:  seconds = 2.74 + 0.0267 x chars     (2.7 s fixed + 1 s per 38 chars)
10 chars -> 3.0 s    30 chars -> 3.0 s    50 -> 5.0 s    90 -> 7.0 s    120 -> 10.0 s
whole-session throughput: 11.2 chars/s   (PT2 measured 27-59 chars/s in healthy windows)
worst single call: 97.6 s; second worst 47.6 s
```

Because the cost is mostly fixed, **"ask for shorter sentences" barely helps time-to-first-audio** - the
median first sentence is already only 30 characters and still costs 3-4 s. What helps is getting the
synthesiser off the contended GPU, and emitting *fewer* sentences.

**2. Multi-sentence replies.** Median 2 sentences per reply, p90 4, max 6. Only the first affects
time-to-first-audio, but every later sentence costs another ~2.7 s of fixed TTS **while MAIN is held**.

**3. MAIN semaphore convoy.** 245 MAIN holds in the window: median 0 s, p90 9 s, **max 53 s**.
31 requests waited over a second; player lines waited 4.4 s, 6.0 s, 6.5 s and **12.9 s**. Background
`infoitems` posts waited up to 9.1 s. The lock is held for the whole LLM+TTS stretch, so one slow turn
delays everything behind it. This is what turns a 7 s median into a 21 s p90.

**4. LLM - not the problem.** 1.86 s median / 2.73 s p90 for the whole reply; 1.47 s to the first spoken
sentence. Every one of the 116 standard calls in the window: provider xAI, `reasoning_tokens: 0`,
no retry, no fallback, median 6,543 prompt / 83 completion tokens.

**5. Prompt build 0.59 s.** Inside it, oghma 0.20-0.39 s and profile 0.13-0.16 s dominate. Small.

**6. STT - not the problem.** 128 ms median, 255 ms p90, 381 ms worst, zero failures, 97 uploads.
Parakeet runs inside the distro on port 8022 and never touched the GPU budget.

**7. Untrimmed leading silence - 0.40 s of pure perceived latency on every line.**
`audiofilterd.sock` is missing, so all 124 TTS calls threw `Audio processing failed for PocketTTS response`
and fell back to `shell_exec(ffmpeg …)`. For the audio.cpp path CHIM sets `$FFMPEG_FILTER=''`, so the
fallback transcodes with **no filters at all** and the 250 ms trim the daemon was supposed to do never
happens. Proof: the filtered `.wav` and the raw `_o.wav` have identical durations (median 130,604 vs
130,638 bytes - a 34-byte header difference) and `silencedetect` finds 0.40 s median of silence at the
start of 50 of 60 files. The code comment in `tts/tts-pockettts.php:448` says it outright:
"audio.cpp seems to ad a big silence at the beginnig".

## 3. The glue's own overhead, itemised

Over 71 player lines in the chim.log window:

| item | per player line | cost | holds MAIN? |
|---|---|---|---|
| `lrg_scenetalk` | 0.14 | **7.9 s median, 18.9 s p90, 22.6 s max - 112.8 s total** | YES - all 10 made a full LLM + TTS call |
| `lrg_initiative` | 0.17 | 4 ms median, but **3 of 12 spoke** (7.0 / 9.7 / 10.4 s) - 20.1 s total | YES when it speaks |
| `funcret` | 0.34 | 255 ms median, 8.2 s max - 14.3 s total | YES (the 8.2 s was lock *wait*) |
| `lrg_npcstate` | 2.58 | 4.5 ms - 3.5 s total | no |
| `lrg_scene` | 0.82 | 7.3 ms - 0.7 s total | no |
| `lrg_log` | 2.14 | 1.3 ms - 0.5 s total | no |
| injected prompt text | every turn | median **176 tok (3.1 %)**, p90 798 (11.3 %), max 982, of ~5,755 | ~0.05-0.25 s of LLM time |
| game-side say-first hold | scene starts | waits for her line to **finish**, budget `fSayFirstMaxWait` 20 s + `fSayFirstSettle` 1 s | game side |

**Total glue-attributable server time: 151.9 s on top of 603 s of player-turn time = +25 %.
74 % of that is `lrg_scenetalk` alone.**

Good news worth recording: **`followup.enabled=false` is working** - zero of the 24 `funcret` requests in
the window made an LLM call (PT2 saw one costing 8.3 s). And `lrg_npcstate` / `lrg_scene` / `lrg_log`,
the high-frequency traffic, cost 4.7 s combined over nine hours and never take MAIN.

The say-first hold is the glue's biggest *perceived* cost on scene-start turns: at 2 sentences x 3.4 s
her line finishes ~7 s after it starts, and `fSayFirstSettle` adds another 1 s before OStim's fade.
`fSayFirstWait` (position changes) is 6 s; `fSayFirstMaxWait` (scene start) is 20 s.

## 4. CHIM-side settings the owner can change (report only - nothing was touched)

Ordered by expected saving.

1. **Take PocketTTS off the game's GPU.** `/home/dwemer/audio.cpp/server.json`:
   `"backend": "cuda" -> "cpu"`, `"threads": 1 -> 12` (Ryzen 9 7950X, 32 logical cores). Restart
   audio.cpp and re-time one 300-character line with the game running.
   **Expected: -1.5 to -3 s median, -8 to -15 s p90.** If CPU is not fast enough, the alternative is to
   cap the game's FPS so the GPU is not at 99 % - the synthesiser is currently at 1.26x realtime.
2. **Start `audiofilterd`** (socket `/var/www/html/HerikaServer/tts/audiofilterd.sock`), or accept the
   ffmpeg fallback but give it a trim filter. **Expected: -0.4 s perceived on every single line, free.**
   It also removes 248 error lines per session from chim.log.
3. **Leave streaming on.** Grok connector metadata already has `"disable_streaming": false` and the wire
   request carries `'stream' => true`. Confirmed in `context_sent_to_llm.log`.
4. **Leave `max_tokens` alone.** The connector says 750 but the actual requests carry `max_tokens => 200`
   (the glue's `speech.max_tokens`) and the median reply is 83 tokens. With streaming, lowering it saves
   nothing.
5. **Kill or repoint connector id 7, "Gemma 3N E4B" (`google/gemma-3n-e4b-it`, max_tokens 128).**
   Verified against OpenRouter's live public model list on 2026-09-22: **that model no longer exists**
   (`gemma-3n*` is absent; only `gemma-2-27b`, `gemma-3-4b/12b/27b`, `gemma-4-26b/31b` are listed).
   It produced 129 `HTTP/1.1 404` lines in apache error.log, the last at **06:39 local** - so it cost
   **nothing** in the 13:00-22:30 window, but any slot pointed at it is silently dead.
   Repoint to `google/gemini-3.1-flash-lite` or delete the row.
6. **Prompt caching is effectively off.** OpenRouter reports **128 cached of 6,543 prompt tokens (2 %)**
   on standard calls, while the relationship-eval calls on DeepSeek V4 Flash get **989 of 1,086 (91 %)**.
   Cause: the only stable prefix is `<roleplay_instructions>` (~208 tok); `<world>` (location, in-game
   date, weather) comes second and changes every turn, invalidating the ~4 k tokens of character /
   knowledge / actions behind it. `PROMPT_TIMESTAMP` is already `false`. If CHIM ever exposes section
   ordering, moving `world` and `nearby_actors` to the end is worth ~4 k cached tokens.
   This is mostly a **cost** lever (~$0.0082 of prompt per turn at Grok's $1.25/M), with a modest TTFT bonus.
7. **`nearby_actors` is the volatile part of the prompt:** median 784 tokens, **p90 2,229**. Crowded cells
   nearly triple it. `PROMPT_CONTEXT_OPTIONS.enabled_sections` still includes `nearby_items`,
   `points_of_interest` and `group_descriptions`; dropping those is the cheapest prompt-size lever.
   Full section medians for `inputtext`: character 1,629 / actions 1,217 / nearby_actors 784 /
   knowledge 662 / roleplay_instructions 208 / plugin_injections 176 / world 52 / general 38.
8. **`CONTEXT_HISTORY` is inconsistent:** 75 in `core_profiles.metadata`, 50 in `conf_opts`. One of them is
   dead config. History is worth roughly 1 k tokens of the prompt.
9. **`RELATIONSHIP_UPDATE_CHANCE = 50`** produced 54 DeepSeek V4 Flash eval calls in the window
   (0.76 per player line). They run in the separate worker and never hold MAIN - **no latency effect**,
   leave them unless you want the cost back.
10. **Background load, CHIM core:** `addnpc` fired 275 times (3.87 per player line) at 216 ms median =
    101 s of PHP; `infoitems` 85 times with p90 3.15 s / max 9.1 s, all of it lock wait. These are not
    on the voice path but they are what the MAIN convoy backs up.

## 5. Models - the honest answer to "should I switch off Grok?"

**The LLM is not the bottleneck.** Grok 4.3 measured **1.86 s median / 2.73 s p90** for a whole reply and
**1.47 s to the first spoken sentence**, on ~5.8 k prompt and 83 completion tokens, with
`reasoning: {effort: none}` and 0 reasoning tokens on all 116 calls. Switching models can take at most
about a second off a 7.4 s wait. Fixing TTS takes 2-4 s off it. Do TTS first.

That said, the configured connectors all go through OpenRouter with driver `openrouterjson`, and CHIM
requires strict JSON (`enforce_json=1`, `json_schema=1`), so every candidate must support
`response_format`. Verified present in OpenRouter's live model list on 2026-09-22:

| model | prompt / completion per M | ctx | endpoints | note |
|---|---|---|---|---|
| `x-ai/grok-4.3` (**configured, id 10, primary**) | $1.25 / $2.50 | 1 M | 4, all xAI | measured baseline, 1.86 s |
| `deepseek/deepseek-v4-flash` (**configured, id 1, secondary+fallback**) | $0.089 / $0.177 | 1 M | 15 | **exists**, 14x cheaper - see warnings |
| `deepseek/deepseek-v4.1-flash` | $0.30 / $1.20 | 1 M | 23 | newer flash line |
| `google/gemini-3.1-flash-lite` | $0.25 / $1.50 | 1 M | 8, Google / AI Studio | usually the lowest-TTFT class |
| `z-ai/glm-5.3-flash` | $0.15 / $0.50 | 1.3 M | 32 | |
| `qwen/qwen3.8-flash` | $0.15 / $0.47 | 1 M | 1, Alibaba only | single endpoint = predictable p90 |
| `mistralai/mistral-small-2603` | $0.15 / $0.60 | 262 k | 3, Mistral | |
| `openai/gpt-5.4-nano` | $0.20 / $1.25 | 400 k | 4, OpenAI / Azure | |

**Warnings before promoting DeepSeek V4 Flash to primary:**

- Connector id 1 has `reasoning_model = 1` and **`metadata = {}`** - it carries none of Grok's
  `{"extra_parameters":{"reasoning":{"effort":"none"}},"extra_parameters_enabled":true}`.
  Promoted as-is it will think before it speaks and be **slower**, not faster. Copy Grok's metadata
  across first. (For reference, the one connector in the session that does emit reasoning is the
  summary slot, DeepSeek V4 Pro: median 336 reasoning tokens.)
- Grok routes only to xAI; `deepseek-v4-flash` has 15 OpenRouter endpoints of very different speed
  (OpenInference, StreamLake, DeepInfra, GMICloud, Venice, DigitalOcean, …). Without
  `extra_parameters.provider = {"order":[…],"allow_fallbacks":false}` or `{"sort":"throughput"}`,
  the p90 can be worse than Grok's even when the median is better.
- OpenRouter's public `/api/v1/models/{id}/endpoints` no longer returns `p50_latency` or
  `p50_throughput` (all null on 2026-09-22), so no provider-side numbers can be quoted here.
  An in-game A/B is the only honest measurement.

**Benchmark recipe.** For each candidate, 20 real turns: ~6 k-token prompt, `stream: true`,
`response_format: json_schema` with CHIM's action schema, `max_tokens: 200`, `reasoning.effort: none`.
Measure `[PRE LLM CALL]` -> first `[INLINE_NARRATION]` (time to first *spoken* sentence, which is what
the player feels) and `llm_complete` minus summed TTS. The bar to beat is **1.47 s / 2.45 s p90**.
Reject anything that emits reasoning tokens or breaks strict JSON - a JSON retry costs a whole extra turn.

## 6. Ranked fix list with expected savings

| # | owner-side | expected |
|---|---|---|
| 1 | PocketTTS `backend: cpu`, `threads: 12` (or cap game FPS) | **-1.5 to -3 s median, -8 to -15 s p90** |
| 2 | start `audiofilterd` so the 250 ms trim runs | **-0.4 s perceived, every line** |
| 3 | trim `nearby_items` / `points_of_interest` / `group_descriptions` from `PROMPT_CONTEXT_OPTIONS` | -0.1 to -0.4 s on crowded turns |
| 4 | repoint or delete connector id 7 (dead `google/gemma-3n-e4b-it`) | 0 s now, removes a landmine |
| 5 | model swap (after 1 and 2) | -0 to -1 s, and -90 % cost |

| # | glue-side (for whoever edits the plugin) | expected |
|---|---|---|
| 1 | raise `lrg_scenetalk`'s minimum interval and skip it when the player spoke in the last ~10 s | removes 7.9 s median (18.9 s p90) of MAIN-held LLM+TTS from 1 turn in 7 |
| 2 | lower `fSayFirstMaxWait` 20 -> ~8 s and `fSayFirstSettle` 1.0 -> 0.5 s | -1 to -12 s before a scene actually starts |
| 3 | ask for ONE sentence on ordinary turns too (p90 is 4 sentences) | -5 to -8 s of MAIN hold on the long turns |
| 4 | keep `followup.enabled=false` - verified working, 0/24 funcrets called the LLM | already banked |
| 5 | the injected prompt block is 176 tok median / 982 max - **leave it alone**, it is 3 % | n/a |
