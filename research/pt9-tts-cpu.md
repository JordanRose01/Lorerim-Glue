# PT9 - PocketTTS on CPU vs GPU, audiofilterd, and the exact web-UI steps

Measured 2026-09-22 04:55-05:15 local, inside `DwemerAI4Skyrim3`.
Role: MEASURER. Nothing live was changed. See §0 for exactly what was and was not touched.

**Headline: the "move TTS to CPU" recommendation in `pt9-latency.md` was right by accident and wrong in
its reasoning.** PocketTTS is *not* slower than realtime. On this box it synthesises a typical NPC line
in **0.089 s on the GPU** and **0.62 s on the CPU** - 30x and 4.7x realtime respectively. The 3.41 s
median in the 2026-09-21 play session is almost entirely *waiting*, not synthesis, and the same session's
own logs contain calls as fast as **0.12 s**. The CPU case is still worth doing, but for a different
reason (bounded worst case, no GPU sharing with the game), and with a different expected number.

---

## 0. What was touched

| | |
|---|---|
| Changed | **nothing live.** `server.json`, `conf.php`, the DB, every service: untouched. |
| Started | temporary `audiocpp_server` processes on free ports 8186-8194, run as `dwemer`, cwd `/tmp/pt9tts`, using `--config /home/dwemer/audio.cpp/server.json` (read-only) with `--port/--backend/--threads` **CLI overrides** - so not even a copied config file had to be written next to the live one. |
| Killed | all of them. Verified: `pgrep -af audiocpp_server` -> `(no audiocpp_server running)`; `ss -lnt` -> `(no temp TTS ports listening)`. |
| Wrote | `/tmp/pt9tts/**` (wavs, scripts, results) and this file. |
| DB | read-only `SELECT`s as the `postgres` user. |

**The live stack was already down when I started.** The distro has been up 2h43m but runs only
postgres: no `apache2`, no `audiocpp_server`, nothing on :8086. `/home/dwemer/audio.cpp/server.log`
ends with `audiocpp_server stopped`. Skyrim is not running either (only `ModOrganizer.exe`, PID 42744).
So there was no live GPU instance to send the 12 read-only requests to; I measured a **temporary GPU
instance with the identical config on a free port** instead. That is a small extension of the stated
permission, taken because a CPU number with no like-for-like GPU number is exactly the unmeasured
comparison the verifier objected to. Nothing was restarted, enabled or reconfigured.

---

## 1. How the live PocketTTS is started, and how CHIM calls it

**Binary and service**

- Binary: `/home/dwemer/audio.cpp/runtime/bin/audiocpp_server` (348 MB, built 2026-09-21 00:15).
  `/home/dwemer/audio.cpp/build/bin/audiocpp_server` is a symlink to it.
- Start script: `/home/dwemer/audio.cpp/start-audiocpp-pockettts.sh`, reached through the symlink
  `/home/dwemer/audio.cpp/start.sh` (the symlink's presence **is** the enable flag).
  It sets `CUDA_HOME` from `/var/lib/dwemerdistro/cuda-selection.env` or `/usr/local/cuda`,
  prepends `/usr/lib/wsl/lib` to `LD_LIBRARY_PATH`, `mkdir -p speakers`, then:
  `runtime/bin/audiocpp_server --config server.json >> server.log 2>&1 &`
- There is **no systemd** in this distro (`/run/systemd/system` does not exist) and no init script for
  it. `/etc/wsl.conf` boot command is `/usr/local/bin/initialize_fresh_distro`, which only does
  machine-id/ssh-host-key work. Services are started by the Windows-side launcher running the
  per-component `start.sh` scripts; enable/disable is `/home/dwemer/audio.cpp/conf.sh`
  (menu: `1. Enable service` / `0. Disable service`), also reachable from `/usr/local/bin/conf_services`.
- Config: `/home/dwemer/audio.cpp/server.json` -
  `host 127.0.0.1`, `port 8086`, `backend cuda`, `device 0`, `threads 1`, one model
  `id pocket-tts`, `family pocket_tts`, `path /home/dwemer/audio.cpp/models/pocket-tts`,
  `session_options.pocket_tts.voice_state_cache_slots 16`, `default_voice_preset.voice_id alba`.
- Model: `/home/dwemer/audio.cpp/models/pocket-tts` (368 MB), `languages/english/{tokenizer.model,
  embeddings/*.safetensors}` - ~40 built-in speaker embeddings of 4-8 MB each.
- CLI overrides exist and are what I used:
  `--host --port --backend cpu|cuda|vulkan|metal --device --threads --log --log-file`.
  Endpoints: `GET /health`, `GET /v1/models`, `GET /v1/audio/voices?model=`,
  `POST /v1/audio/speech`, `POST /v1/audio/transcriptions`, `POST /v1/tasks/run`.

**How CHIM calls it** (`/var/www/html/HerikaServer/tts/tts-pockettts.php`)

- Connector row `core_tts_connector` id 1, driver `pockettts`, url `http://127.0.0.1:8086`,
  label `ddistro pockettts`; `core_profiles` id 1 has `tts_connector_id = 1`.
- `pockettts_is_audio_cpp()` switches on `:8086` in the endpoint (or a `/v1/audio/speech` suffix),
  so the audio.cpp shape is used:
  `POST http://127.0.0.1:8086/v1/audio/speech`,
  headers `Content-type: application/json`, `Accept: audio/wav`, body
  `{"model":"pocket-tts","input":<text>,"language":"en","voice_ref":"/var/www/html/HerikaServer/data/voices/<voicetype>.wav"}`
  (falls back to `{"voice":"alba"}` when no reference wav is readable).
- The call is a **blocking, non-streaming** `file_get_contents()` with a stream context - CHIM waits
  for the whole wav. There is no streaming mode on this path, so "first byte" and "done" are the same
  moment (my measurements confirm: `first_byte_s` == `wall_s` to 4 decimals on every call).
- Response handling: raw bytes -> `soundcache/<md5>_o.wav`; then `processAudio()` via
  `audiofilterd_client.php` (see §4); on failure, `shell_exec("ffmpeg -y -i <_o.wav>  <filter>  <.wav>")`
  where for the audio.cpp path `$FFMPEG_FILTER` is hard-coded to **`''`**; then a `<md5>.txt` sidecar
  with `total call time` and `ffmpeg transcoding` is written next to the wav.

---

## 2. Measured: GPU vs CPU, same 12 sentences

12 clean NPC-style lines, 20-161 chars, same `voice_ref`
(`/var/www/html/HerikaServer/data/voices/femalenord.wav`), 2 warm-up calls excluded, sequential calls,
duration from `ffprobe`. 2 passes (n=24) for the main configs, 1 pass (n=12) for the thread-curve extras.

**The game was NOT running during any of this** (no `SkyrimSE.exe`; `nvidia-smi` idle 9-33 % / 1.1-1.6 GB
before each run). This is the best case for the GPU and a neutral case for the CPU.

| config | n | median | p90 | min | max | median realtime factor | fixed cost | slope |
|---|---|---|---|---|---|---|---|---|
| **GPU** `cuda`, threads 1 (= live config) | 24 | **0.089 s** | 0.172 s | 0.039 s | 0.299 s | **0.030** (33x faster than realtime) | 0.019 s | 0.143 s / 100 chars |
| CPU threads 4 | 12 | 0.703 s | 1.690 s | 0.422 s | 2.174 s | 0.258 | 0.109 s | 1.281 s / 100 chars |
| **CPU threads 8** | 24 | **0.615 s** | 1.447 s | 0.328 s | 1.768 s | **0.214** (4.7x) | 0.119 s | 1.022 s / 100 chars |
| CPU threads 12 | 24 | 0.618 s | 1.404 s | 0.362 s | 1.778 s | 0.225 | 0.173 s | 0.975 s / 100 chars |
| CPU threads 16 | 24 | 0.627 s | 1.390 s | 0.329 s | 1.883 s | 0.226 | 0.140 s | 1.054 s / 100 chars |
| CPU threads 24 | 12 | 0.632 s | 1.524 s | 0.337 s | 2.163 s | 0.236 | 0.033 s | 1.253 s / 100 chars |

Per-sentence medians (seconds):

| chars | GPU t1 | CPU t4 | CPU t8 | CPU t12 | CPU t16 | CPU t24 |
|---|---|---|---|---|---|---|
| 20 | 0.065 | 0.422 | 0.347 | 0.455 | 0.354 | 0.374 |
| 24 | 0.061 | 0.514 | 0.379 | 0.378 | 0.390 | 0.337 |
| 25 | 0.046 | 0.461 | 0.407 | 0.508 | 0.592 | 0.429 |
| 35 | 0.074 | 0.519 | 0.421 | 0.460 | 0.455 | 0.477 |
| 36 | 0.073 | 0.476 | 0.484 | 0.512 | 0.454 | 0.393 |
| 42 | 0.085 | 0.679 | 0.638 | 0.618 | 0.635 | 0.655 |
| 61 | 0.085 | 0.727 | 0.615 | 0.615 | 0.613 | 0.610 |
| 77 | 0.125 | 1.104 | 0.851 | 0.905 | 0.940 | 1.021 |
| 99 | 0.167 | 1.473 | 1.147 | 1.146 | 1.250 | 1.333 |
| 104 | 0.147 | 1.281 | 1.169 | 1.089 | 1.159 | 1.076 |
| 115 | 0.161 | 1.714 | 1.375 | 1.401 | 1.381 | 1.545 |
| 161 | 0.278 | 2.174 | 1.758 | 1.774 | 1.879 | 2.163 |

Findings:

1. **Both backends are faster than realtime by a wide margin.** GPU 33x, CPU 4.7x. The earlier
   "1.26x realtime, slower than realtime" characterisation does not describe the synthesiser.
2. **Threads past 8 buy nothing.** t8 / t12 / t16 / t24 are within noise of each other (0.615-0.632 s
   median); t4 is 14 % slower. **Use `threads: 8`**, not 12 or 16 - it leaves 24 of the 32 logical cores
   for Skyrim at no measured TTS cost. (The earlier report suggested 12.)
3. **Cost is nearly all per-character, not fixed.** GPU 0.019 s fixed + 0.143 s/100 chars;
   CPU 0.12 s fixed + ~1.0 s/100 chars. The earlier fit (`2.74 s fixed + 1 s per 38 chars`) is an
   artefact of the in-game waiting, not of the model. Consequence: **"ask for shorter sentences" does
   help**, roughly linearly, once the waiting is removed.
4. **No text caching.** Pass 1 and pass 2 medians are identical (GPU 0.089 / 0.085; CPU t8 0.592 /
   0.661), so every timing above is a real synthesis.
5. **Leading silence is the model, not the backend.** Median leading silence measured with
   `silencedetect=noise=-40dB:d=0.05` on the raw server output: GPU 0.283 s, CPU t8 0.309 s,
   t12 0.248 s, t16 0.263 s, t4 0.236 s (t24's 0.029 s median is one outlier batch; its max is 0.392 s).
   So the 250 ms `trim_start` filter is worth the same ~0.25-0.30 s on **either** backend. Moving to CPU
   does not fix it; §4 does.

---

## 3. Why the play session saw 3.41 s - it was not synthesis

Three extra experiments, all on the temporary instances.

**3a. One `audiocpp_server` serialises requests, perfectly.** `--threads` is the *intra-op* thread count;
concurrency across HTTP requests is 1.

GPU (cuda, threads 1), same 42-char line, N simultaneous POSTs:

| concurrency | per-request walls (s) | last finishes |
|---|---|---|
| 1 | 0.088 | 0.089 s |
| 2 | 0.090, 0.176 | 0.176 s |
| 4 | 0.096, 0.186, 0.281, 0.372 | 0.373 s |
| 6 | 0.066, 0.131, 0.228, 0.316, 0.406, 0.499 | 0.501 s |

CPU (cpu, threads 12): 1 -> 0.808 s; 2 -> 0.582/1.239; 4 -> 0.627/1.316/1.974/2.663;
**6 -> 0.621/1.310/2.010/2.694/3.302/3.897**.

This matters for CHIM, which has several PHP processes that can hit TTS at once (the main reply, the
narrator, the background processor, `lrg_scenetalk`, `lrg_initiative`). On GPU the whole queue is still
0.5 s, so **queueing alone cannot explain 3.41 s**. On CPU a 6-deep queue costs 3.9 s - so if the glue
is going to CPU, keeping concurrent TTS callers down matters more than it does today.

**3b. GPU contention is real but modest between compute jobs.** A second temporary GPU instance hammered
by 3 threads drove the card to 88-91 % util and 5-8 GB; the measured instance then ran the same 12
sentences at **median 0.199 s / p90 0.466 s** - 2.2x the idle 0.089 s, still nowhere near 3.4 s. Compute
contention from another CUDA process is not enough on its own either. (Skyrim's contention is a
*graphics* workload through WSL2 GPU-PV plus VRAM pressure, which this test cannot reproduce - see §6.)

**3c. Cold `voice_ref` costs 0.09-0.54 s, once per voice.** First call with a never-used reference wav
vs the immediately following call with the same one, GPU idle:

| voice | cold | warm | delta |
|---|---|---|---|
| femaleeventoned | 0.662 s | 0.120 s | +0.542 s |
| malenord | 0.250 s | 0.098 s | +0.153 s |
| maleguard | 0.213 s | 0.097 s | +0.117 s |
| femalesultry | 0.170 s | 0.082 s | +0.088 s |
| maleoldgrumpy | 0.199 s | 0.093 s | +0.106 s |

`voice_state_cache_slots` is 16, and the box has ~30 voicetypes, so a crowded cell can evict and re-pay
this. Small, but it is the only genuine *fixed* cost on the path.

**3d. The decisive evidence: re-reading the play session's own sidecars.**
132 `soundcache/*.txt` sidecars survive from 2026-09-21 17:06-22:17. They record `total call time` and
`ffmpeg transcoding` separately, so the two can be split:

```
total call time     median 3.411 s   p90 6.900 s   max 97.602 s   n=132
ffmpeg transcoding  median 0.055 s   p90 0.059 s   max  0.168 s   n=132
HTTP to audio.cpp   median 3.356 s   p90 6.845 s   max 97.544 s
audio produced      median 2.72 s
```

So ffmpeg is **55 ms** - irrelevant - and the 3.4 s is entirely the HTTP call. But the distribution is
**bimodal**, and the fast mode matches my idle-GPU numbers almost exactly:

| fastest logged calls | slowest logged calls |
|---|---|
| 0.12 s (21 chars), 0.12 s (28), 0.13 s (35), 0.15 s (51), 0.16 s (70), 0.17 s (31), 0.20 s (56), 0.22 s (86) | 97.60 s (28 chars), 47.57 s (22), 12.81 s (119), 11.81 s (130), 11.60 s (130), 11.27 s (47), 8.91 s (147), 8.66 s (90) |

A 28-character line took **0.12 s** at 17:43 and **97.60 s** at 17:08 on the same box, same service,
same model. Length is uncorrelated with the slow mode (the two worst calls are 28 and 22 chars). That is
not a slow synthesiser; it is the synthesiser being *starved*, in bursts, while the game had the GPU.

**Corrected statement of the problem:** PocketTTS costs ~0.09 s of GPU work per line. In the play session
it cost 3.41 s median / 6.9 s p90 / 97.6 s max because it was sharing an RTX 4080 with a rendering
Skyrim through WSL2 GPU-PV. The fix is to stop sharing, and the CPU measurement above says what that
costs.

---

## 4. `audiofilterd` - what it is, why the socket is missing, what the owner can do

**What it is.** A daemon speaking a line-less JSON protocol over a Unix socket. The client is
`/var/www/html/HerikaServer/tts/audiofilterd_client.php`:
send `{"audio_base64": <wav bytes b64>, "filters":[{"type":"trim_start","milliseconds":250.0}]}`,
half-close the write side, read back `{"error":{"code":0,...},"audio_base64": <processed wav b64>}`.
The client's default socket is `/tmp/audiofilterd.sock`; `tts-pockettts.php:492` passes
`/var/www/html/HerikaServer/tts/audiofilterd.sock`. The doc it points at, `SUPPORTED_EFFECTS.md`, is
**not present** anywhere on the box.

**What it does when present.** In `tts-pockettts.php` the daemon's output is written *directly* to the
game-facing `soundcache/<md5>.wav`, so it replaces the ffmpeg transcode entirely: it (a) trims 250 ms of
leading silence and (b) saves the ~55 ms ffmpeg spawn. With the measured 0.25-0.31 s of leading silence,
that is ~0.25-0.30 s of dead air removed from the front of **every** spoken line.

**Why the socket is missing: the daemon was never installed. It does not exist on this system.**
Verified:

- `find / -xdev \( -iname "*audiofilter*" -o -iname "*SUPPORTED_EFFECTS*" \)` returns exactly one file:
  the client PHP.
- Nothing in `/usr/local/bin` (`ddistro_server`, `ddistro_doctor`, `update_gws`, `conf_services`,
  `install_*`) mentions it. No init script, no systemd unit (there is no systemd), no `.sock` anywhere.
- `dpkg -l | grep -i audiofilter` - empty.
- In the HerikaServer git history the client arrives in the single squashed commit
  `be82f38 "Bootstrap HerikaServer production snapshot"` (2026-08-29). There is no commit that ever
  added or removed a daemon, and no submodule. Remote is `github.com/Dwemer-Dynamics/HerikaServer`.

So this is not "crashed" or "a service the owner forgot to enable". **The CHIM 3.3.2 snapshot on this
box ships the client half of a feature whose daemon half is not in the distro.** Every PocketTTS call
therefore throws `Audio processing failed for PocketTTS response`, falls through to
`shell_exec(ffmpeg …)` with `$FFMPEG_FILTER = ''` (hard-coded empty for the audio.cpp path), and
produces a byte-for-byte re-encode with the silence intact - which is exactly what `pt9-latency.md`
observed (identical durations, ~0.40 s of leading silence in 50 of 60 files).

**Exact commands for the owner - honest version.**

1. *There is nothing to start.* Any instruction of the form "run `audiofilterd`" or "enable the
   audiofilterd service" would be fabricated. If a future CHIM update ships it, the persistence pattern
   in this distro is the same one audio.cpp uses: a `start.sh` in the component directory, enabled by a
   symlink, started by the Windows-side launcher - not systemd.
2. *To confirm the state at any time* (read-only, safe to paste):
   ```
   wsl -d DwemerAI4Skyrim3 -- bash -c 'ls -l /var/www/html/HerikaServer/tts/audiofilterd.sock /tmp/audiofilterd.sock 2>&1; find / -xdev -iname "*audiofilterd*" 2>/dev/null'
   ```
   Today that prints "No such file or directory" twice and lists only `audiofilterd_client.php`.
3. *The real fix is one line of PHP*, and it belongs to us, not to the web UI. In
   `tts/tts-pockettts.php` around line 448 the audio.cpp branch sets `$FFMPEG_FILTER=''` with the
   commented-out trim right above it:
   ```php
   // audio.cpp seems to ad a big silence at the beginnig
   //$FFMPEG_FILTER='-filter:a "atrim=start=0.3,asetpts=PTS-STARTPTS"';
   $FFMPEG_FILTER='';
   ```
   Setting it to `-af "silenceremove=start_periods=1:start_silence=0.05:start_threshold=-40dB"`
   (or the simpler `atrim=start=0.25,asetpts=PTS-STARTPTS`) makes the ffmpeg fallback do what the
   daemon would have done, at a measured cost of ~55 ms. Measured leading silence is 0.25-0.31 s median
   with a 0.63 s max, so a fixed 0.25 s trim is safe but leaves some; `silenceremove` is the better
   choice. **This is a core-file edit** (CHIM owns `tts-pockettts.php`), so it needs the usual
   patch-on-update care - flag it, do not do it silently.
4. *Do nothing* is a defensible third option: it costs ~0.28 s of dead air per line and 2 log lines per
   call, and it survives CHIM updates.

---

## 5. Exact web-UI wording for the owner steps

UI base URL is **`http://localhost:8081/HerikaServer/ui/`** - port 8081, not 80
(`/etc/apache2/ports.conf`: `Listen 0.0.0.0:8081`; `ddistro_doctor` `HERIKA_UI_PORT=8081`).
`PLAYTEST8_NOTES.md` wrote these links without the port; add `:8081`.
All values below are the **current** ones, read from the live database today.

### 5a. Prompt sections - drop three of them

Page: **Global Settings** -> `http://localhost:8081/HerikaServer/ui/global_settings.php`
Card: **Context Selections** -> sub-heading **Top-Level Sections**.
The checkboxes are labelled with the literal tag names.

Untick, in **Top-Level Sections**:

- **`<nearby_items>`** - "Ground items and item descriptions near the actor."
- **`<points_of_interest>`** - "Nearby doors, passages, and notable destinations."
- **`<group_descriptions>`** - "Faction and group descriptions for nearby actors."

Leave ticked: `<roleplay_instructions>`, `<world>`, `<knowledge>`, `<available_actions_list>`,
`<nearby_actors>`, `<adventuring_party>`, `<scene_notes>`, `<paralinguistic_tags>`.
Then **Save**. (Stored as `general_settings.PROMPT_CONTEXT_OPTIONS`; current value has all eleven
top-level sections enabled.)

### 5b. Connector 7 "Gemma 3N E4B" - delete or repoint

Page: **🧠 Admin Tools -> LLM Connectors** ->
`http://localhost:8081/HerikaServer/ui/core/llm_connectors.php`
Row: **`Gemma 3N E4B`**, id **7**, Model `google/gemma-3n-e4b-it`, Max Tokens 128, driver
`openrouterjson`, provider `openrouter`.

Good news, verified today: **no slot points at it.** `core_profiles` id 1 uses 10/1/3/4/6/1 and
`diary_connector_id` 1; the global slots use 1, 10 and 4 (see 5d). Connector 7 is orphaned.
So either action is safe:

- **Delete**: open the row, **Delete**; or
- **Repoint**: in the row's edit form set **Model** to `google/gemini-3.1-flash-lite`, clear **Provider**
  (its placeholder reads "(Optional) leave empty to use recommended provider"), set **Max Tokens** to
  750 to match the others, **Save**.

### 5c. `CONTEXT_HISTORY` 75 vs 50 - which one is real

They are two different rows and only one is read:

- **`core_profiles.metadata.CONTEXT_HISTORY = "75"`** - this is the live value. `core_profiles.class.php`
  decodes the profile's metadata and copies every key into `$GLOBALS`, which is what the prompt builder
  reads.
- **`conf_opts.CONTEXT_HISTORY = 50`** - **dead**. A grep across `lib/` and `main.php` finds no reader
  for that row (the only writes are `dynamic_update_util.php` populating a per-NPC config blob from
  `$GLOBALS`). It is a leftover from the pre-profile layout.

To change the live one: **Profiles** -> `http://localhost:8081/HerikaServer/ui/core/core_profiles.php`
-> edit **`Default Profile`** -> metadata editor -> section **Context** -> field **`CONTEXT_HISTORY`**
(integer, 0-200) -> set the value -> **Save**. Schema help text: *"Amount of context history (dialogue
and events) that will be sent to LLM… Higher Context = more tokens used and slower response time. We
recommend you do not go over 100."*
Nothing needs to be done about the `conf_opts` 50; if it bothers you, it can be deleted, but it changes
no behaviour.

### 5d. Which slots point at Grok 4.3 today

Connector **id 10 = `Grok 4.3`** (`x-ai/grok-4.3`, provider `x-ai`, driver `openrouterjson`,
max_tokens 750). Slots, with the names the UI prints:

| UI label (Global Settings) | key | current connector | note |
|---|---|---|---|
| **Director Mode** 🎬 | `CORE_CONNECTOR_DIRECTOR` | **10 Grok 4.3** | enabled (`..._ENABLED = true`) |
| **Profile Tasks** 👥 | `CORE_CONNECTOR_PROFILES` | **10 Grok 4.3** | enabled |
| **Scene Classifier** 🎭 | `CORE_CONNECTOR_SCENECLASSIFIER` | **10 Grok 4.3** | **inert** - `SCENE_CLASSIFIER_ENABLED = false` |
| Background Life ⏱️ | `CORE_CONNECTOR_BGL` | 1 DeepSeek V4 Flash | enabled |
| Background & Memory Tasks 🧠 | `CORE_CONNECTOR_MEDIUMTERM` | 4 DeepSeek V4 Pro | enabled |
| Summaries 📝 | `CORE_CONNECTOR_SUMMARY` | 4 DeepSeek V4 Pro | enabled |
| Player Respeech 🎮 | `CORE_CONNECTOR_PLAYER` | 1 DeepSeek V4 Flash | |

Plus the profile's own slots (Profiles -> Default Profile): **Primary = 10 Grok 4.3**,
Secondary = 1, Tertiary = 3 (GLM 5.2), Quaternary = 4, Formatter = 6 (Ministral 8B),
Fallback = 1, Diary = 1.

So **two live background slots use Grok 4.3: Director Mode and Profile Tasks** (Scene Classifier is a
third but is switched off). Neither is on the voice path, so neither affects time-to-first-audio; they
affect cost.

### 5e. Connector 1 "DeepSeek V4 Flash" - reasoning effort and provider pinning

Page: **LLM Connectors** -> row **`DeepSeek V4 Flash`**, id 1, Model `deepseek/deepseek-v4-flash`,
Provider `openrouter`, driver `openrouterjson`, Max Tokens 750.
Its `metadata` is currently **`{}`** - no extra parameters at all. Grok's row (id 10) has the pattern
to copy: `{"extra_parameters":{"reasoning":{"effort":"none"}},"extra_parameters_enabled":true,"disable_streaming":false,"remove_action_prompt":false}`.

In the connector's edit form:

1. Field **`Include Body Parameters (YAML)`** (the Ace editor near the bottom of the form; posts as
   `extra_parameters_yaml`, stored as `metadata.extra_parameters`). Enter:
   ```yaml
   reasoning:
     effort: none
   ```
2. Tick the toggle next to it so `extra_parameters_enabled` is set (the form's hidden field defaults it
   to off). Grok's row has it `true`.
3. Leave **Disable Streaming** unticked - tooltip: *"Disable SSE streaming for this connector and wait
   for the full JSON reply before parsing…"*. Grok has it `false` and streaming is working.
4. **Provider pinning**: the field is **`Provider`**, a free-text input directly under **`Model`**,
   placeholder *"(Optional) leave empty to use recommended provider"*. Clicking it opens an
   **OpenRouter Providers** dropdown (*"Click to select. Value set to provider slug."*) fetched live from
   `https://openrouter.ai/api/v1/providers`, filtered to the slug in front of the `/` in **Model**.
   For `deepseek/deepseek-v4-flash` it offers the `deepseek` slug. Pin it only if you want to stop
   OpenRouter routing to a slow reseller; leaving it empty keeps OpenRouter's own choice.
   Note the JS auto-fills this field from the Model string on OpenRouter connectors, so check it after
   editing Model.
5. **Save**.

### 5f. The three walk-away items from PLAYTEST8_NOTES (verified against today's DB/UI)

1. **Disable the `EndConversation` action.** Action Editor ->
   `http://localhost:8081/HerikaServer/ui/function_editor.php`. Still **enabled** today
   (`combined_core_action.EndConversation.is_activated = t`). Find the `EndConversation` row, flip its
   state pill from **Enabled** to **Disabled**, then save. (The page's own summary cards are labelled
   **Enabled** / **Disabled** and the filter accepts `all|enabled|disabled`.)
2. **`END_CONVERSATION_COOLDOWN` -> 0.** Global Settings (⏳ icon). Currently **60**.
   Help text: *"Seconds an NPC is ineligible for AI dialogue if they wish to stop talking. Default: 60"*.
3. **`RECHAT_MODE` -> `conversational`.** Global Settings (🔁 icon). Currently **`random`**.
   It is a select with exactly four values: `tight`, `conversational`, `group`, `random`.
   Help text: *"Tight = listener-only, Conversational = prefers the current partner, Group = lets nearby
   NPCs rotate into the exchange, Random = picks one of the other modes at the start of each rechat
   chain."*
   Related and already set the way you'd want: `OPEN_RECHAT = true`,
   `ENFORCE_STRICT_RECHAT_RESPONSE = false`.

### 5g. The TTS change itself - **not** a web-UI change

There is no web-UI field for the PocketTTS backend. `backend` / `threads` live in
`/home/dwemer/audio.cpp/server.json`, which the CHIM UI does not expose. The owner's edit is:

```
# with Skyrim and the CHIM server stopped
wsl -d DwemerAI4Skyrim3 -- bash -c 'cp /home/dwemer/audio.cpp/server.json /home/dwemer/audio.cpp/server.json.bak'
# then edit /home/dwemer/audio.cpp/server.json:  "backend": "cpu",  "threads": 8
# and restart the distro / CHIM server the normal way so start.sh re-runs
```
Revert = restore the `.bak`. The `device: 0` key is ignored on the cpu backend.

---

## 6. What this measurement cannot tell you

1. **In-game GPU contention is not reproduced here.** Skyrim was not running. The GPU numbers above are
   a *floor*. The 3d hypothesis - that the 3.41 s median is the game starving the card - is strongly
   supported by the session's own bimodal data (0.12 s and 97.6 s for the same length of line) but was
   not reproduced directly. My synthetic contention test (another CUDA process at ~90 % util) produced
   only a 2.2x slowdown, so if Skyrim causes 30x, the mechanism is probably **VRAM pressure and
   GPU-PV graphics/compute scheduling**, not raw SM contention. LoreRim on a 16 GB 4080 plus ~2.9 GB for
   the TTS model is a plausible oversubscription; the 97.6 s outlier looks like eviction, not queueing.
2. **CPU contention with the game is not measured either.** Skyrim was not running, so the CPU numbers
   are also a floor. A CPU run costs ~0.6 s of 8 busy threads per line on a 16C/32T 7950X; Skyrim is
   mostly 1-4 heavy threads, so the collision risk is low, but it is untested. This is the main reason
   to use `threads: 8` rather than 12/16 - the measurements say 8 is free.
3. **Thermals / clocks** were not controlled. Runs were minutes apart on an otherwise idle machine.
4. **One voice, one language, one model.** All numbers use `femalenord.wav` + `en`. Voice-state cache
   behaviour with 30+ voicetypes and 16 slots in a crowded cell is only sampled by 3c.
5. **n is small** (24 per main config, 12 for the thread-curve extras). The medians are tight and the
   configs are far apart, so the ranking is safe; the p90s are indicative only.
6. **The live service was down** the whole time, so nothing here is a measurement *of the live
   instance* - it is a measurement of the same binary, model and config on the same hardware.

**Also worth flagging, found while looking for the TTS config:**
`/var/www/html/HerikaServer/conf/conf.php` is **0 bytes** (mtime 2026-09-21 00:24), with a 44,934-byte
`conf.php.backup.20260921_042425` beside it that is exactly the size of `conf.sample.php`. There are no
`conf_<profile>.php` files at all. Current CHIM reads configuration from postgres
(`general_settings`, `conf_opts`, `core_profiles.metadata`), so this may be harmless - but an empty
`conf.php` is not a state anyone chose, and the timezone in `chim.log` shifted from `+02:00` to `+00:00`
at the same time. Worth a look by whoever owns the config path; I changed nothing.

---

## 7. Revised recommendation, and the one-scene test

**Revised #1 (replaces "Take PocketTTS off the game's GPU… Expected: -1.5 to -3 s median").**

Set `/home/dwemer/audio.cpp/server.json` to `"backend": "cpu"`, `"threads": 8`.
Measured cost of the move, game not running: **median 0.089 s -> 0.615 s (+0.53 s), p90 0.172 s ->
1.447 s**. Measured benefit: the synthesiser stops competing with the renderer for the 4080, which in
the 2026-09-21 session was worth **3.41 s median, 6.90 s p90 and a 97.6 s worst case**. Expected net,
if the contention reading is right: **time-to-first-audio drops ~2.8 s at the median and ~5.5 s at p90,
and the multi-second tail disappears**. The claim that is now *measured* rather than projected is the
CPU cost; the contention component is inferred from the session's own bimodal log (§3d) and still needs
the in-game confirmation below.

Keep the GPU option open: if the owner would rather cap Skyrim's framerate / lower VRAM use and stay on
CUDA, the ceiling is far better (0.089 s median). CPU is the *robust* choice, not the fast one.

**One-scene owner test (10 minutes, no rebuild).**

1. Stop Skyrim and the CHIM server. Back up `server.json`. Set `"backend":"cpu"`, `"threads":8`.
   Start CHIM, start Skyrim.
2. Play one scene you know produces long replies - the Winking Skeever with Lisette is the session's own
   worst case. Aim for ~20 spoken lines, at least one over 120 characters.
3. Quit, then run (read-only, prints the same split as §3d):
   ```
   wsl -d DwemerAI4Skyrim3 -- bash -c "grep -h 'total call time' /var/www/html/HerikaServer/soundcache/*.txt | sed 's/.*total call time://' | sort -n | awk '{a[NR]=\$1} END {print \"n=\" NR, \" median=\" a[int(NR/2)], \" p90=\" a[int(NR*0.9)], \" max=\" a[NR]}'"
   ```
   (Clear or move the old `soundcache/*.txt` first, or compare by timestamp, so you are reading only the
   new scene.)
4. **Pass** = median under ~1.0 s and p90 under ~2.0 s, with no call over 5 s. That matches the CPU
   bench plus PHP overhead and proves the tail is gone.
   **Fail** = medians still in seconds. Then the problem is not the GPU at all and the next suspect is
   the serialisation in §3a (count how many CHIM processes call TTS per turn) - revert to
   `"backend":"cuda","threads":1` from the backup while that is investigated.
5. Watch the CPU side too: if Skyrim's frametimes get worse, drop `threads` to 4 (14 % slower TTS,
   measured) before giving up on the CPU path.
