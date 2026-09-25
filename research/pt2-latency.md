# Playtest 2 - why replies got slow when the conversation turned intimate

Read-only analysis, 2026-09-21. Sources (all inside WSL distro DwemerAI4Skyrim3):
`/var/www/html/HerikaServer/log/chim.log` (CHIM's own `[PERF]` phase timings, `*TRACE SemaphoreManager`, `Audit ... PRE LLM CALL`, TTS lines),
`log/debugStream.log` (raw OpenRouter SSE incl. `usage`, `provider`, `native_finish_reason`), `log/context_sent_to_llm*.log`,
`log/lorerim_glue.log`, `/var/log/apache2/error.log`, `/home/dwemer/audio.cpp/server.json|server.log`,
`Documents/My Games/Skyrim Special Edition/SKSE/crash-2026-09-21-06-40-12.log`.
Clock note: chim.log / glue log are +02:00 ("12:38"), WSL system clock and the game are EDT ("06:38"). Same instants.

## Verdict in one paragraph

The LLM did NOT get slower. In every slow turn Grok answered in 2-3 s, exactly like the ordinary turns
(same provider xAI, 0 reasoning tokens, ~100-160 completion tokens, no retry, no fallback, no abort).
The 24-43 s were spent in **TTS**: CHIM synthesises each sentence synchronously *inside* the request, while holding the
MAIN semaphore, and the PocketTTS server (audio.cpp, **CUDA on the same RTX 4080 the game renders on**, `threads: 1`)
dropped from ~59 characters/s (12:10-12:30) to ~7-9 characters/s from 12:36:52 until the crash at 12:40.
One 329-character sentence took 38 s. The slowdown began on a turn that chose `ComeCloser`, not the glue's action, and
the prompt was actually *smaller* in the slow turns. The plugin's only real self-inflicted cost was the one-off 6.6 s
scene-index build inside the 12:33:16 request. "Intimate" correlates with the slowdown in time only; nothing in the
logs shows content-dependent latency at the provider.

## Measured timings (Lisette, same NPC/voice throughout)

`pre-LLM` = lock acquired -> `[PRE LLM CALL]` audit line. `first sentence` = PRE LLM CALL -> first sentence handed to TTS.
`TTS` = per sentence, `[INLINE_NARRATION]` line -> `Speech sent` line (1 s resolution). Tokens from OpenRouter `usage`.

| turn (lock acquired) | type / action | prompt chars / prompt tok | compl. tok | lock wait | pre-LLM | first sentence from LLM | TTS per sentence (chars) | llm_complete phase | total |
|---|---|---|---|---|---|---|---|---|---|
| 12:34:41 | inputtext / Talk | 27,436 / 7,919 | 112 | 0 | 0.56 s | ~1 s | 3+0 s (115+48) | 4.3 s | 5.3 s |
| 12:35:00 | inputtext / Talk | 27,573 / 7,952 | 134 | 0 | 0.56 s | 2 s | 6+1+1 s (61+95+115) | 10.4 s | 11.3 s |
| 12:35:19 | inputtext / FollowPlayer | 27,838 / 8,010 | 133 | 0 | 0.52 s | 2 s | 7+0 s (129+145) | 9.2 s | 10.2 s |
| 12:35:41 | inputtext / FollowPlayer | 28,310 / 8,134 | 101 | 0 | 0.55 s | 2 s | 6+0 s (96+183) | 8.1 s | 9.2 s |
| 12:33:16 | inputtext / **StartIntimacy** #1 | 27,204 / 7,840 | 185 | 0 | 0.47 s | 2 s | 5+1+1+0 s (451 total) | 16.5 s = ~2 LLM + 7 TTS + **6.6 index build** | 17.5 s |
| 12:33:36 | funcret follow-up (LLM call #2) | 17,458 / 5,519 | 65 | 0 | 0.27 s | whole reply in ~1 s | 7 s (126) | 8.0 s | 8.3 s |
| 12:36:49 | inputtext / ComeCloser (not glue) | 22,397 / 6,910 | 160 | 0 | 0.47 s | 2 s | **13+12+16 s** (96+126+130) | 43.1 s | 44.6 s |
| 12:37:33 (sent 12:37:31) | inputtext / **StartIntimacy** #2 | 22,865 / 6,956 | 98 | 1.95 s | 3.19 s | 2 s | **14+20 s** (92+157) | 36.0 s | 39.8 s |
| 12:38:11 | inputtext / **StartIntimacy** #3 | 22,650 / 6,913 | 120 | 0 | 0.48 s | whole reply in 3 s (12:38:12 -> 12:38:15 "REMAINING DATA") | **38 s** (one 329-char sentence) | 41.6 s | 43.2 s |
| 12:38:53 (sent ~12:38:47) | inputtext / Talk | 21,605 / 6,676 | 114 | 6.56 s | 7.84 s | 2 s | **15+7 s** (119+61) | 24.4 s | 32.8 s |

Whole session since 11:25: 49 `inputtext` requests, typical `llm_complete` 2.3-10 s, server-side PHP before the LLM call
always 0.47-0.56 s (profile ~130 ms, history ~85, memory ~90, oghma ~170, prompt ~57 ms) - identical in slow turns.

PocketTTS throughput from chim.log (all voices; chars / seconds between the two log lines):

| window | TTS calls | chars | seconds | chars/s | slowest call |
|---|---|---|---|---|---|
| 11:30-11:40 | 15 | 1,548 | 56 | 27.6 | 13 s |
| 11:40-11:50 | 13 | 1,199 | 44 | 27.2 | 9 s |
| 12:00-12:10 | 22 | 1,847 | 46 | 40.2 | 8 s |
| 12:10-12:20 | 23 | 1,763 | 30 | 58.8 | 6 s |
| 12:20-12:30 | 5 | 595 | 10 | 59.5 | 5 s |
| 12:30-12:40 | 37 | 3,723 | 185 | 20.1 (7-9 from 12:36:52) | 38 s |

All seven TTS calls after 12:36:52 took 12-38 s; before that the only calls >= 8 s were the first line of a new voice
(Hjorunn 11 s, Sayma 13 s, Corpulus 8 s = voice-state computation, `voice_state_cache_slots: 16`).

## Ranked causes

### 1. TTS (PocketTTS on the game's GPU) - 85-95 % of every slow turn. Outside the plugin.
Evidence: table above; chim.log 12:38:15 `REMAINING DATA` (stream finished) -> 12:38:53 `Speech sent ... size: 329`;
`/home/dwemer/audio.cpp/server.json`: `"backend": "cuda", "device": 0, "threads": 1`; server.log: `ggml_cuda_init ... RTX 4080`.
The wait is inside the HTTP call to audiocpp_server (the `audiofilterd.sock` warning, ffmpeg line and `Speech sent` all
land in the same second, after the gap). The crash log shows VRAM was not exhausted (GPU MEMORY 3.71/14.92 GB, crash =
`Actor::UnequipItem` access violation), so the likely mechanism is GPU time-slice contention: an autoregressive TTS issues
thousands of tiny CUDA kernels from one CPU thread and each waits behind the game's render queue (WSL2 GPU-PV). The logs
prove TTS was the slow stage; they cannot prove *why* it slowed at 12:36 (location changed to Solitude Sewers at that point).
Fixes (owner settings, not plugin):
- Take PocketTTS off the contended GPU: run audio.cpp with the CPU backend and several threads (Ryzen 9 7950X, 32 logical
  cores, 27 GB free in WSL; Pocket TTS is a ~100M-parameter model designed for faster-than-real-time CPU use), or pick a
  CPU engine already installed in the distro (piper, MeloTTS). Test by timing one 300-character line with the game running.
- Or give the GPU headroom: cap FPS (the GPU should not sit at 99 %), especially in interiors where uncapped FPS saturates it.
- Optional: the audio filter daemon is not running (`audiofilterd.sock` missing, 117 errors) - harmless for latency, but it is
  noise on every sentence; start it or disable the filter in the TTS settings.
Plugin-side mitigation (real, because TTS time is linear in characters, ~8.5 chars/s under load): in the glue's
`this_moment` / scene guidance ask for ONE short spoken sentence (<= ~120 characters) on intimate turns and in scene talk.
Turn 12:38:11 would have cost ~14 s instead of 38 s. Also lower `max_tokens` (currently 750) is NOT needed; replies are ~100-185 tokens.

### 2. MAIN semaphore is held for the whole TTS time, so everything queues behind a slow turn.
Evidence: `SemaphoreManager ... Lock acquired/released for 'MAIN'` bracket the TTS lines; `[PERF]` `lock_ready`:
player lines waited 1.95 s (12:37:31) and 6.56 s (12:38:47); `infoitems` game-state posts waited up to 79 s
(run ...3d6d56), 70 s, 61 s, 52 s. No generation was aborted or restarted: 0 abort lines, all 54 generations in
debugStream.log end with `usage` and `native_finish_reason: completed`. The player simply spoke again while the previous
reply was still being voiced, so turns ran back-to-back (12:36:49 -> 12:39:20 = four turns, 2 min 31 s, of which ~135 s TTS).
Plugin consequences:
- Every extra LLM+TTS request the glue causes is paid at full TTS price under MAIN. The `followup.enabled` row metadata
  (lib/lrg_actions.php:47) produced one second call this session: funcret 12:33:36, 8.3 s (1 s LLM + 7 s TTS) directly after
  the 17.5 s turn. The other 6 funcret requests took ~0.3 s (no LLM call). Recommendation: set `followup.enabled=false` on the
  glue's Start/Control rows (the NPC already spoke in the same turn; scene talk covers the rest). Saves 8 s now, 15-40 s
  when TTS is contended.
- `lrg_scenetalk`: zero requests of that type reached the server this session (request-type census: none), so it cost
  nothing here. By design it is a normal, non-fast request = MAIN + TTS. Keep it to one short sentence, enforce a minimum
  interval (>= 25-30 s), and skip it when the player spoke in the last ~10 s. Using the Fast connector only changes the
  ~2 s LLM part, not the TTS part, so it is a minor win. The fast path seen in the logs (openrouterjson.php ~1393) is a
  plain non-streaming text call; whether its output can be voiced without going through the MAIN pipeline was NOT verified
  here, so treat "scene talk via fast_request" as unproven rather than as a drop-in fix.
- relationship_system: for Lisette it logs "relationships are LOCKED in the editor - skipping AI re-evaluation"; its worker
  is a separate process and its fast requests fire after MAIN is released. No measurable effect on the player's turns.

### 3. Scene index built lazily inside an LLM request: 6.6 s once (12:33:26 -> 12:33:33), will recur.
Evidence: lorerim_glue.log `12:33:33 scene index rebuilt: 607 scenes ... 6.6s`; chim.log 12:33:26 `Prepared command
payload for ExtCmdLRG_StartIntimacy` -> 12:33:33 `mkdir(): File exists ... lrg_scene_index.php on line 194` -> lock released.
It ran in `lrgPostProcessActions` -> `lrgPickStartScene()` (lib/lrg_actions.php:168) -> `lrgSceneIndex()`, i.e. after the
LLM answered, under MAIN, delaying the game command by 6.6 s. It was the FIRST build (no earlier "rebuilt" line, file
created 12:33). Why it will happen again (lib/lrg_scene_index.php:27-50):
  (a) line 33: an index older than 24 h is never "fresh" -> unconditional rebuild once per day even if nothing changed;
  (b) signature change (line 52-60): mtime of `F:\Modlists\LoreRim\profiles\Ultra\modlist.txt`, of the 3 scene folders, or the
      `scene_index` config. MO2 rewrites modlist.txt whenever the mod list is changed (enable/disable/reorder/install).
      Today it did NOT change from launching/closing the game: mtime 2026-09-21 08:22:15 UTC (before the session) and the
      signature computed now still equals the cached one (checked read-only: match=YES, stat cost 12.8 ms).
Per-request cost otherwise is negligible: the index is NOT touched on ordinary turns (only in-scene, or when the LLM picked
Start); reading+decoding the 263 KB file takes 1.08 ms (20-run average, php CLI); the signature stat is 12.8 ms at most once
a minute. `lrgPrepareTurn()` could not be called read-only (it writes the log line and may touch marker files), but CHIM's
own numbers bound it: all hooks + DB + prompt build before the LLM call total 0.47-0.56 s, the same in slow and normal turns.
Fixes (plugin): never build inside an LLM request. Build from the `lrg_npcstate` fast message (124 of them this session,
avg 7.6 ms, not behind MAIN) by spawning a detached CLI builder with a lock file, or from an install/warm-up CLI step;
inside LLM requests serve the last good index (or the configured default start scene if none) even when stale; drop the
24 h age rule (signature is enough). APCu / per-scene files are unnecessary at 1 ms. Cosmetic: line 36 `@filemtime($stamp)` on
a missing file still lands in chim.log as a warning (12:38:10) - guard with is_file().

### 4. Prompt size / injected text - not a factor.
CHIM `[PROMPT-COMPOSITION]`: normal turn 12:35:41 = 28,310 chars (character 6,229, knowledge 3,192, actions 5,074,
nearby_actors 7,541, plugin_injections 225); slow turn 12:37:34 = 22,865 chars (nearby_actors 1,657, plugin_injections 367).
The glue's blocks in context_sent_to_llm.log: `<personal_boundaries>` avg 803 chars (735-881, 32 turns), `<this_moment>` avg
255 chars (197-340); no `intimate_scene_now` block was ever sent (no scene registered server-side). Total glue text ~1.3-1.4 k
chars = 5-6 % of the prompt (~350 tokens of ~7,000). Time to first sentence stayed at 2 s. Shortening is optional (cost only).
Side note (cost, not latency): OpenRouter reports only 128-384 cached prompt tokens of ~7,000 per call, so prompt caching
is effectively not working for this prompt layout.

### 5. Provider - clean.
debugStream.log, all 52 Grok generations: `"provider":"xAI"`, `reasoning_tokens: 0` (request sends `effort => 'none'`),
`native_finish_reason: completed`, `max_tokens 750`, `stream true`, no 429/5xx, no second attempt, no connector fallback;
the two DeepSeek V4 Flash generations (11:44, 11:47) are CHIM's own tasks (max_tokens 4000). No refusal/retry pattern.
Unrelated defect worth fixing in CHIM settings: after every player turn a non-streaming fast request with model
`google/gemma-3n-e4b-it` (max_tokens 256) fails with `HTTP/1.1 404 Not Found` (43 of 43 times,
connector/openrouterjson.php:1393; apache error.log has 129 matching lines). It fails quickly and after MAIN is released, so it
does not add latency, but whatever feature uses that connector slot is silently dead - point it at a model that exists
(e.g. the configured DeepSeek V4 Flash).

## Single most useful change

Fix TTS throughput (owner): move PocketTTS off the GPU the game is saturating (CPU backend with 8+ threads, or a CPU TTS
engine), or cap the game's FPS so the GPU has headroom. That alone turns the 24-43 s turns back into ~5-10 s.
Best plugin-only changes, in order: (1) ask for one short sentence on intimate/scene turns (TTS time is linear in length),
(2) `followup.enabled=false` on the glue's action rows (removes a whole LLM+TTS request under MAIN after each command),
(3) build the scene index outside LLM requests and drop the 24 h expiry (removes the recurring 6.6 s stall).
