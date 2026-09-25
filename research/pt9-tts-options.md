# PT9 - TTS engine options: what CHIM 3.3.2 supports on this box, and what to switch to

Researched 2026-09-22. Role: RESEARCHER (read-only). Nothing was installed, enabled, configured or
deleted. The only writes were this file, seven throwaway `.sh` scripts in `$env:TEMP\lrg_test`, and a
temporary `/tmp/lrg_tts_probe` directory in WSL that was removed at the end of the session.

Companion document: `research/pt9-tts-cpu.md` (measurements of the current engine). This file does not
repeat those measurements, it builds on them.

---

## 0. First, the correction: you are not on XTTS

> "i want to stop using xtts and use something higher quality that allows for nsfw"

**You are not running XTTS.** You have never run XTTS on this install. What is actually speaking for
your NPCs is **PocketTTS-100M, served by the `audio.cpp` C++ runtime** on `127.0.0.1:8086`.

Evidence, all read live today:

| Thing | Value |
|---|---|
| Profile `Default Profile` (id 1) | `tts_connector_id = 1` |
| Connector id 1 | driver `pockettts`, label `ddistro pockettts`, url `http://127.0.0.1:8086` |
| Process on 8086 | `runtime/bin/audiocpp_server --config server.json`, PID 28663 |
| `GET /v1/models` | `{"id":"pocket-tts","family":"pocket_tts","task":"tts","mode":"offline"}` |
| Model on disk | `/home/dwemer/audio.cpp/models/pocket-tts` (368 MB, PocketTTS-100M, from `kyutai/pocket-tts`) |
| XTTS | `/home/dwemer/xtts-api-server` exists as **source only** - 7.1 MB, **no `venv`**, no `start.sh`, nothing listening on 8020. `conf_services` treats "installed" as `conf.sh` + `venv`, so by the distro's own test XTTS is not installed. |

So "stop using XTTS" is already true. The real question is: PocketTTS-100M is a **100-million-parameter**
model - the smallest real TTS in the distro. That is why it sounds the way it does. There are much
better models **already supported by the runtime you have installed**, and §3 explains the switch.

Second correction, from `pt9-tts-cpu.md`: PocketTTS is not slow. Measured again today against the
**live** server, game not running, 62-character line, same `femalenord.wav` reference:

```
call 1: 0.259 s   (cold voice_ref)
call 2: 0.107 s
call 3: 0.095 s
call 4: 0.098 s
call 5: 0.087 s
call 6: 0.085 s
call 7: 0.091 s      -> warm median 0.095 s for 62 chars, 3.28 s of audio produced
```

That is ~34x faster than realtime. Your ~7 s wait is **not** the synthesiser being slow; it is the
synthesiser being starved of GPU while Skyrim renders (`pt9-tts-cpu.md` §3d: the same 28-character line
took 0.12 s at 17:43 and 97.60 s at 17:08 in the same play session). Keep that in mind while reading the
quality options below - **a bigger model makes the starvation worse, not better.**

---

## 1. Inventory: every TTS engine this CHIM can drive

### 1a. Engine files present in `/var/www/html/HerikaServer/tts/`

26 `tts-*.php` files ship. **Only 17 drivers are selectable in the web UI** - the list is hard-coded in
`lib/core/tts_connector.class.php` lines 10-27. The rest are dead files from older CHIM versions:

| Dead file (not selectable) | Note |
|---|---|
| `tts-gcp.php` | Google Cloud TTS - no `gcp` driver in the UI list. The `Google` API-badge row exists but nothing consumes it for TTS. |
| `tts-convai.php`, `tts-coqui-ai.php`, `tts-stylettsv2.php`, `tts-xtts.php` (old non-FastAPI), `tts-melotts_pronunciation.php` (helper), `tts-phpunit.php` (test stub) | legacy / support files |

### 1b. The 17 selectable drivers

"Installed here" is the distro's own test: component dir + `conf.sh` + `venv` (or, for audio.cpp, a
built `audiocpp_server`). "Clone" means it can speak in a voice taken from an arbitrary reference WAV -
which for CHIM means the game's own voicetype samples in
`/var/www/html/HerikaServer/data/voices/*.wav` (30 voicetypes present, plus `TheNarrator.wav`).

| Driver (UI name) | Where it runs | Installed here? | Clones game voices? | Default URL | Voice field |
|---|---|---|---|---|---|
| **PocketTTS** | local GPU or CPU | **YES - this is what you use** | **yes** (`voice_ref`) | `127.0.0.1:8086` | `voiceid` |
| Chatterbox | local GPU (python service) | no - source only, no venv | yes (`speaker_wav`) | `127.0.0.1:8023` | `voiceid` |
| XTTS | local GPU (python FastAPI) | no - source only, no venv | yes (`speaker_wav`) | `127.0.0.1:8020` | `voiceid` |
| OmniVoice | local GPU | no (connector row 2 exists, service does not) | yes (`speaker_wav`) | `127.0.0.1:8021` | `voiceid` |
| MeloTTS | local GPU/CPU | no - source only, no venv | **no** (preset speakers) | `127.0.0.1:8084` | `voiceid` |
| Piper TTS | local CPU | **no** - see §1c | **no** (preset `speaker_id`) | `127.0.0.1:5000` | `voiceid` |
| Mimic3 | local CPU | no - source only, no venv | no | `127.0.0.1:59125` | `voice` |
| Kokoro | local (separate server) | no - not present at all | **no** (82M, preset voices) | `127.0.0.1:8880` | `voiceid` |
| Zonos | local (Gradio) | no - not present at all | (gradio app, not wired for `voice_ref` in the PHP) | `127.0.0.1:7860` | `voiceid` |
| KoboldCPP | local (your LLM server) | n/a | no | `127.0.0.1:5001/api/extra/tts` | `voice` |
| xVASynth | **Windows-side** app | no | no (per-character models) | `192.168.0.1:8008` | `model` |
| **ElevenLabs** | cloud | key row exists, **empty** | yes (their IVC/PVC, consent required) | - | `voice_id` |
| **Azure** | cloud | key row exists, empty | only via Custom Neural Voice (gated) | - | `voice` |
| **OpenAI** | cloud | key row exists, empty | **no** - preset voices only | `api.openai.com/v1/audio/speech` | `voice` |
| **Deepgram** | cloud | key row exists, empty | **no** - preset voices only | - | `model` |
| **Cartesia** | cloud | key row exists, empty | yes (consent required) | - | `voice_id` |
| **Inworld** | cloud | key row exists, empty | yes (consent required) | - | `voiceid` |

API-key rows (`core_api_badge`, page `ui/api_badge.php`): OpenRouter **set**; OpenAI, Deepgram, Google,
Azure, ElevenLabs, Replicate, Cartesia, Nano-GPT, DeepL, Inworld, Groq all **empty**. You have no cloud
TTS key on this box today.

### 1c. Piper - looks enabled, is not

`/home/dwemer/piper/start.sh` exists (which is normally the "enabled" flag), and 545 MB of ONNX voices
are present. But **it cannot start**: its venv `/home/dwemer/python-piper` does not exist, and
`/etc/start_env` only launches Piper when `/home/dwemer/piper/cuda-keyring_1.1-1_all.deb` is present,
which it is not. Nothing listens on :5000. Piper is inert. (It also cannot clone - it is a
preset-voice model - so it is not a candidate for you regardless.)

### 1d. Distro install scripts in `/usr/local/bin`

`install_audiocpp_pockettts` (what built your current stack), `install_higgs_tts`, `install_mimic3`,
`install_cuda_dependencies`, `migrate_tts_service_ports`, `conf_services`. There is **no** install
script for XTTS, Chatterbox, MeloTTS, Kokoro or Zonos - those come through `update_gws` git updates and
their own `ddistro_install.sh`, and none of them has been run here.

Note `install_higgs_tts`: it clones `Dwemer-Dynamics/Higgs-TTS` and its `conf_services` hook tests for
`/home/dwemer/higgs-tts/runtime/audiocpp_server` - i.e. **Higgs is shipped as another audio.cpp
runtime**, not a python service. It is not installed here (`/home/dwemer/higgs-tts` does not exist).

---

## 2. The finding that matters: your runtime already supports 10 better TTS models

`/home/dwemer/audio.cpp` is a 1.3 GB build of the Dwemer-Dynamics fork of `audio.cpp`. It is a
**generic multi-family C++ inference runtime**, not a PocketTTS-only program. From its own
`README.md` model table and `docs/tts.md`, the TTS families it implements:

| Family | Params | Clone from a WAV? | Status in this build | Notes from the project's own docs |
|---|---|---|---|---|
| `pocket_tts` | 100M | yes | released | **what you run now** |
| `chatterbox` | 0.5B | **required** | released | 19 languages; upstream card claims it is preferred over ElevenLabs in side-by-side evals; MIT licence; emotion-exaggeration control |
| `voxcpm2` | 2B | yes (+ optional transcript) | released | 48 kHz output, Apache-2.0, voice *design* from a text description |
| `miotts` | 1.7B | required | released | en/ja |
| `omnivoice` | 0.6B | yes | released | 600+ langs, non-verbal tags `[laughter]`, `[sigh]`… |
| `qwen3_tts` | 0.6B / 1.7B | yes | released | incl. CustomVoice and VoiceDesign variants |
| `vibevoice` | 1.5B / 7B | yes (up to 4 speakers) | released | long-form multi-speaker dialogue |
| `higgs_tts` | 4B | required | **testing** | Higgs Audio v3; 21 emotion tokens, whisper/shout/sing |
| `kokoro_tts` | 82M | no | testing | preset voices only |
| `moss_tts` | 100M | yes | testing | |
| `supertonic` | small | no | testing | preset voices `M1`/`F1` |

Only `models/pocket-tts` is downloaded. The others are one `tools/model_manager.py install <id>` away
(package ids: `chatterbox`, `voxcpm2`, `higgs_audio_v3_tts_4b`, `miotts_1_7b`, `omnivoice`,
`qwen3_tts_1_7b_base`, `vibevoice_1_5b`, `kokoro_82m_bf16`, `moss_tts`, `supertonic_3`).

**And CHIM can point at them without any code change.** Three facts make this work:

1. `server.json` takes a **list** of models, each with its own `id`, and supports `"lazy_load": true`
   so an unused one costs nothing until first request (`app/server/README.md`).
2. The HTTP handler is family-agnostic: `POST /v1/audio/speech` reads `model`, `input`, `language`,
   `voice_ref`, `reference_text`, `instructions` and hands them to whichever family that model id
   names (`app/server/runtime.cpp:487-577`).
3. CHIM's `tts-pockettts.php` sends exactly that shape, and the model id it sends is **a field in the
   connector's metadata** - `pockettts_audio_cpp_model()` returns `$GLOBALS["TTS"]["POCKETTTS"]["model"]`,
   which is the **Model** box on the TTS Connectors page, default `pocket-tts`.

So: download a model, add ~10 lines to `server.json`, restart, then change **one text field in the web
UI** to switch engines - and change it back to switch back. That is the whole mechanism. §3b has the
exact steps.

**Voice cloning carries over untouched.** `pockettts_backend_voice_payload()` resolves the NPC's
voicetype to `data/voices/<voicetype>.wav` and sends it as `voice_ref`. Every clone-capable family
above takes `voice_ref`. Your NPC→voice mapping lives in `core_tts_fallback` (20 race/gender rows),
`conf_opts` `Voicetype/*` and `Nametype/*` rows, and each NPC's `voiceid` - **none of it is
engine-specific**, so it survives a family switch with zero edits.

---

## 3. Per-engine detail: quality, latency, VRAM, cost, NSFW

### 3a. Local engines

Latency below is **measured** only where marked. Everything else is the project's own published figure
and is labelled as such. No numbers are invented.

| Engine | Naturalness (source) | 60-char latency | VRAM / RAM | Cost | NSFW policy |
|---|---|---|---|---|---|
| **PocketTTS-100M** (current) | lowest of the set - 100M params | **MEASURED today, live, GPU: 0.095 s median warm (62 chars); first call with a cold voice 0.259 s.** MEASURED (pt9-tts-cpu.md): CPU threads 8, 61 chars: 0.615 s median | ~1.5-2.4 GB VRAM observed on the live server; CPU path uses RAM only | free | **none** - runs on your machine, no filter, no terms |
| **Chatterbox 0.5B** | model card claims it is "consistently preferred" over leading closed-source systems in side-by-side evals (Resemble AI's own claim, not an independent eval) | **not measured here - not installed.** Third-party report on a 4090: ~0.47 s to first chunk, RTF 0.499 (streaming build, not this runtime). audio.cpp's own release note claims a 33.56 % inference-time reduction for Chatterbox vs its previous release | ~2-3 GB VRAM at FP16 (third-party figure) | free, **MIT** | **none** (local) |
| **VoxCPM2 2B** | card claims SOTA-or-competitive on Seed-TTS-eval, CV3-eval, InstructTTSEval; 48 kHz output | not measured - not installed; no published figure for this runtime | 2B at bf16 ≈ 4-5 GB before overhead (arithmetic, not a published figure) | free, **Apache-2.0** | **none** (local) |
| **Higgs Audio v3 4B** | card claims single-digit WER/CER across 102 languages, 85 of them <5; 21 emotion tokens; whisper/shout/sing control | not measured - not installed. Card's throughput figure is for an H100, not a 4080 | ~5B tensors at BF16 ≈ 10 GB+ before KV cache - **will not co-exist with LoreRim on a 16 GB 4080** | free but **research / non-commercial licence** (Creator Use Grant, attribution) | **none** (local) |
| MioTTS 1.7B / Qwen3-TTS 1.7B / OmniVoice 0.6B | no independent eval cited in the repo | not measured | 1-4 GB class | free | none (local) |
| Kokoro 82M / Supertonic / Piper / MeloTTS / Mimic3 | small preset-voice models | Piper/Kokoro are CPU-cheap | <1 GB | free | none (local) - **but none of them can clone your game voices**, so every NPC would sound like a stock voice. Disqualified for your use. |
| XTTS v2 | the 2023-era baseline; superseded by everything above | not installed | ~4 GB | free, **Coqui CPML - non-commercial** | none (local) |

### 3b. Cloud engines - NSFW policy, read today

I read each provider's current published policy. Quotes are kept to short fragments; links are given so
you can check them yourself.

| Provider | Adult/NSFW position | Cloning consent | Verdict for you |
|---|---|---|---|
| **Azure** (Microsoft AI Services Code of Conduct, v4.0, dated 2026-05-01) | **Explicitly banned.** The "Sexually explicit content" section prohibits content that is erotic, pornographic or otherwise sexually explicit, *and* applications that are sexually explicit - it names sexually suggestive content and fetish content. Usage restriction 13 separately bans chatbots that are erotic or romantic, or used for erotic or romantic purposes. | Custom Neural Voice is a Limited Access service; the speech section requires each external user to record their own consent statement | **Disqualified.** This bans your use case twice over. |
| **ElevenLabs** (Prohibited Use Policy) | **Not explicitly banned for TTS.** The published prohibited-use list covers child safety, illegal behaviour, fraud, impersonation, political content, hate/harassment - there is no general adult-content category. **However**, their separate Agents product terms do prohibit generating sexual content, so the company's position is inconsistent and could change. | Prohibited to replicate another person's voice without consent | **Possible but unsettled**; see the cloning problem below. |
| **Cartesia** (Acceptable Use Policy) | **Not explicitly banned.** Seven prohibited categories: deceptive content; illegal/abusive/fraudulent; minor exploitation; political campaigning; psychologically or emotionally harmful; violent/hateful/threatening; and a catch-all for anything contradicting company values. No adult-content category. | Policy requires you submit only your own voice or others' **with explicit consent** | Most permissive of the cloud options on paper - but the catch-all clause means it is at their discretion. |
| **OpenAI** | Their usage-policies page blocked my fetch (HTTP 403). From search results: OpenAI updated policy to allow some consensual adult themes for verified adults, but reporting from 2026-03-26 says the "adult mode" rollout is **paused indefinitely**. I could not verify the current API text directly. | **Cannot clone** - preset voices only | Disqualified anyway by "no cloning". |
| **Deepgram** | Could not verify (their AUP URL 404s; terms page not read) | **Cannot clone** - preset voices only | Disqualified by "no cloning". |
| **Inworld** | **Could not verify** - both the AUP and ToS URLs I tried returned 404. Their public safety page talks in generalities about unlawful/harmful content. | their marketing describes voice cloning; consent terms unread | Unknown. Do not assume it is permissive. |

**The cloning problem that kills all of them.** Your whole voice design is "NPC speaks in the voice
Bethesda's voice actor recorded for that voicetype." Every cloud provider that can clone
(ElevenLabs, Cartesia, Inworld) requires that you have the **consent of the person whose voice it is**.
You do not have consent from Bethesda's voice actors, and you cannot get it. Uploading
`femalenord.wav` to any of these services to clone from it is a policy violation independent of
anything sexual. Locally, no such term exists - the audio never leaves your machine.

**Cost, for scale.** ElevenLabs Flash v2.5 is listed at **$0.05 per 1,000 characters** with ~75 ms
model latency (their figure, excluding network). A play session that produces the 132 lines in your
2026-09-21 log at ~80 chars each is ~10,500 characters ≈ **$0.53/hour** on the cheapest model, more on
their higher-quality ones. I did not verify Cartesia's or Inworld's current per-character prices.

---

## 4. The honest recommendation

Your constraints: explicit NSFW dialogue, ~7 s current wait, one RTX 4080 (16 GB) shared with LoreRim,
a 7950X (16C/32T) that is mostly idle during play.

### Ranking

**1. Local CPU - PocketTTS, `threads: 8`. Do this first, today.**
Measured: 0.615 s median, 1.447 s p90 for a typical line, with the GPU untouched. It removes the
3.41 s median / 6.90 s p90 / 97.6 s worst case that the play-session logs actually show. It costs
nothing, needs no download, and reverts by restoring one backup file. **It does not improve quality** -
it fixes latency. That is the honest split: the wait and the voice quality are two separate problems and
this fixes only the first.

**2. Local GPU - Chatterbox 0.5B via your existing audio.cpp. This is the best quality-per-latency
option that allows NSFW.**
Why it wins: it is local, so there is **no acceptable-use policy at all** and no consent clause blocking
you from cloning the game's own voices; it takes the same `voice_ref` WAVs you already use, so your
entire NPC voice mapping carries over untouched; at 0.5B and ~2-3 GB it is the largest model that has a
realistic chance of co-existing with LoreRim on a 16 GB card; it is MIT-licensed; and it adds
emotion-exaggeration control, which is the single most useful knob for the kind of dialogue you are
writing. The catch is honest and large: **it puts TTS back on the GPU**, which is exactly what caused
your 3.4 s median. Run it only with GPU headroom - cap Skyrim's framerate, or drop a texture tier, and
measure before and after with the sidecar check in §5d.

**3. Runner-up: VoxCPM2 2B, same runtime, same switch.** 48 kHz output, Apache-2.0, and it adds voice
*design* (describe a voice in words) on top of cloning. Higher fidelity than Chatterbox on paper, at
roughly twice the parameters and correspondingly more VRAM and time. Take it if you find the 4080 has
room after testing Chatterbox, or if you move TTS to a second machine later.

**Not recommended, and why:**
- **Higgs Audio v3 4B** is the best-sounding thing in the catalogue and the wrong choice here: ~10 GB
  of weights on a card that LoreRim already fills, plus a non-commercial research licence.
- **Any cloud provider**, because of the cloning-consent problem above, plus per-line cost, plus
  sending explicit text about your save game to a third party, plus network latency on top of
  synthesis. **Azure is flatly disqualified** - it bans erotic content and erotic chatbots by name.
- **Kokoro / Piper / Supertonic / Mimic3 / MeloTTS**: cannot clone. Every NPC would lose its voice.
- **XTTS**: the thing you thought you were on. Older and worse than everything in §2, non-commercial
  licence, and it would mean installing a whole Python service instead of reusing the C++ runtime that
  is already built and working.

### If you only do one thing
Do §5a (CPU move). It is 10 minutes and it is where the 3 seconds are. Treat §5b (Chatterbox) as a
separate experiment for a quiet evening, because it trades latency back for quality.

---

## 5. Exact steps

### 5a. Move PocketTTS to CPU, `threads: 8`

**There is no web-UI field for this.** The CHIM UI does not expose the audio.cpp backend; `backend` and
`threads` live in `/home/dwemer/audio.cpp/server.json`, which the UI never reads.

1. **Quit Skyrim.** Stop the CHIM server from the launcher window (the same button you use to start it).
2. Back up the config, from a Windows PowerShell prompt:
   ```
   wsl -d DwemerAI4Skyrim3 -- bash -c "cp /home/dwemer/audio.cpp/server.json /home/dwemer/audio.cpp/server.json.bak && ls -l /home/dwemer/audio.cpp/server.json*"
   ```
3. Edit the two values. Safe in-place edit, no editor needed:
   ```
   wsl -d DwemerAI4Skyrim3 -- bash -c "sed -i 's/\"backend\": \"cuda\"/\"backend\": \"cpu\"/; s/\"threads\": 1/\"threads\": 8/' /home/dwemer/audio.cpp/server.json && cat /home/dwemer/audio.cpp/server.json"
   ```
   Confirm the printed JSON now reads `"backend": "cpu"` and `"threads": 8`. Leave `"device": 0` alone -
   it is ignored on the CPU backend.
4. **Restart the distro from the CHIM launcher** (Stop, then Start). This is what re-runs
   `/etc/start_env`, which contains
   `if [ -f /home/dwemer/audio.cpp/start.sh ]; then su dwemer -c /home/dwemer/audio.cpp/start.sh`
   and then waits for port 8086. That is the only supported restart path - there is no systemd in this
   distro and no per-service restart command.
5. Verify, before launching the game:
   ```
   wsl -d DwemerAI4Skyrim3 -- bash -c "curl -s http://127.0.0.1:8086/health; echo; tail -2 /home/dwemer/audio.cpp/server.log"
   ```
   `/health` must return `"backend":"cpu"`. If it still says `cuda`, the restart did not pick up the
   file - stop and start the launcher again.

Expected: ~0.62 s per line instead of 0.09 s idle / 3.4 s under load, and no multi-second tail.
`threads: 8` is the measured sweet spot - 12, 16 and 24 are no faster, and 8 leaves 24 of your 32
logical cores for Skyrim (`pt9-tts-cpu.md` §2).

### 5b. Switch to Chatterbox (or any other audio.cpp family)

Do this **only with Skyrim closed and the CHIM server stopped**. It needs ~3 GB of downloads and about
20 minutes. Disk is fine: 941 GB free on the distro's root.

**Step 1 - download the model.** `tools/model_manager.py` needs `torch`, which the distro does not keep
installed, so build a throwaway venv exactly the way `install_audiocpp_pockettts` does and delete it
afterwards:

```
wsl -d DwemerAI4Skyrim3 -u dwemer -- bash -lc "cd /home/dwemer/audio.cpp && python3 -m venv /tmp/mmvenv && source /tmp/mmvenv/bin/activate && pip install --no-cache-dir --upgrade pip && pip install --no-cache-dir pyyaml safetensors numpy && pip install --no-cache-dir torch --index-url https://download.pytorch.org/whl/cpu && python3 tools/model_manager.py install chatterbox --models-root models"
wsl -d DwemerAI4Skyrim3 -u dwemer -- bash -lc "rm -rf /tmp/mmvenv; ls -la /home/dwemer/audio.cpp/models/"
```

`ResembleAI/chatterbox` is a public repo, so **no Hugging Face token is needed** (unlike `pocket-tts`,
which is gated - that is why the original installer prompted you for one). The package lands in
`models/chatterbox`. For VoxCPM2 instead, use `install voxcpm2` (lands in `models/VoxCPM2`); for Higgs,
`install higgs_audio_v3_tts_4b` (lands in `models/higgs-audio-v3-tts-4b`).

**Step 2 - declare it in `server.json`, keeping PocketTTS.** Back up first
(`cp server.json server.json.bak` as in §5a step 2), then make the file look like this - note
`"lazy_load": true` at the top level, which means the second model costs nothing until it is first
asked for:

```json
{
  "host": "127.0.0.1",
  "port": 8086,
  "backend": "cuda",
  "device": 0,
  "threads": 1,
  "lazy_load": true,
  "models": [
    {
      "id": "pocket-tts",
      "family": "pocket_tts",
      "path": "/home/dwemer/audio.cpp/models/pocket-tts",
      "task": "tts",
      "mode": "offline",
      "load_options": { "language": "english" },
      "session_options": { "language": "english", "pocket_tts.voice_state_cache_slots": 16 },
      "default_voice_preset": { "voice_id": "alba" }
    },
    {
      "id": "chatterbox",
      "family": "chatterbox",
      "path": "/home/dwemer/audio.cpp/models/chatterbox",
      "task": "clon",
      "mode": "offline",
      "session_options": { "language": "en" }
    }
  ]
}
```

`"task": "clon"` is what `docs/tts.md` gives for the Chatterbox family (VoxCPM2 and Higgs use
`"task": "tts"`). **This specific server.json shape for Chatterbox is the part I could not test** - see
§6.1. If the server refuses to start, `server.log` will name the offending key; restore
`server.json.bak` and you are back where you started.

**Step 3 - restart the distro from the launcher**, then check both models are registered:

```
wsl -d DwemerAI4Skyrim3 -- bash -c "curl -s http://127.0.0.1:8086/v1/models; echo"
```
You should see two entries, `pocket-tts` and `chatterbox`.

**Step 4 - point CHIM at it. This is the only web-UI step.**

- Open **`http://localhost:8081/HerikaServer/ui/core/tts_connectors.php`**
  (navbar: 🧠 Admin Tools → TTS Connectors). Port **8081**, not 80.
- Click the row **`ddistro pockettts`** (id 1 - it is the one your Default Profile uses).
- Leave **Name**, **Service** (= `PocketTTS`) and **URL** (`http://127.0.0.1:8086`) alone. The driver
  stays `pockettts`; it is the transport, not the model.
- Find the **Model** field - its help text reads "audio.cpp model id. Default: pocket-tts."
  Change `pocket-tts` to **`chatterbox`**.
- Leave **Language** `en`, and leave **NPC Fallbacks** (`malenord` / `femalenord`) as they are.
- **Save.**
- Use the page's **Test** button (save first - the page warns that testing uses the stored settings).

**What carries over, unchanged:** every NPC's voicetype, the 20 race/gender rows in `core_tts_fallback`,
the per-NPC `Voicetype/*` and `Nametype/*` rows written by `vsx.php`, the `data/voices/*.wav` samples,
and the male/female fallbacks. CHIM sends the same `voice_ref` path to the new family. **Nothing about
voice assignment needs to be redone.**

**Optional, if Chatterbox sounds flat:** it accepts `temperature`, `top_p`, `repetition_penalty`,
`guidance_scale` and `max_tokens` through the same request body, and `audio.cpp` forwards them - but
`tts-pockettts.php` does not currently send them, so tuning them would need either a
`default_voice_preset`-style entry in `server.json` or a change to a CHIM core file. Flag it rather than
doing it silently.

### 5c. If you insist on a cloud engine instead

I do not recommend it (§4), but the mechanics are:

1. **You enter the key, not me.** `http://localhost:8081/HerikaServer/ui/api_badge.php` → the row for
   the provider (ElevenLabs id 6, Cartesia id 8, Inworld id 11 all already exist with empty keys) →
   paste the key → Save. The row turns from 🔴 to 🟢 in the connector picker.
2. `.../ui/core/tts_connectors.php` → **New** → **Service** = ElevenLabs / Cartesia / Inworld →
   **API Badge** = the row you just filled → fill the provider-specific metadata fields the form shows.
3. `.../ui/core/core_profiles.php` → **Default Profile** → set its **TTS connector** to the new row.
4. **Your voice mapping does not carry over.** Cloud drivers use a provider voice id (`voice_id` /
   `voice`), not a local WAV. You would have to map all 30 voicetypes to provider voices by hand, or
   upload the game's WAVs to clone from - which is the consent violation described in §3b.
5. Revert = set the profile's TTS connector back to id 1 (`ddistro pockettts`).

### 5d. Reverting anything

| Change | Revert |
|---|---|
| CPU move (§5a) | `wsl -d DwemerAI4Skyrim3 -- bash -c "cp /home/dwemer/audio.cpp/server.json.bak /home/dwemer/audio.cpp/server.json"`, restart from the launcher, confirm `/health` says `cuda` |
| Engine switch (§5b) | Set the **Model** field back to `pocket-tts` and Save. That alone restores the old voice instantly - no restart, no file edit. Optionally also restore `server.json.bak` and delete `models/chatterbox` |
| Cloud connector (§5c) | Point the profile back at connector id 1; delete the new connector row; blank the API key |

**How to tell whether a change helped** (read-only, run after a scene; same split as
`pt9-tts-cpu.md` §3d):

```
wsl -d DwemerAI4Skyrim3 -- bash -c "grep -h 'total call time' /var/www/html/HerikaServer/soundcache/*.txt | sed 's/.*total call time://' | sort -n | awk '{a[NR]=\$1} END {print \"n=\" NR, \" median=\" a[int(NR/2)], \" p90=\" a[int(NR*0.9)], \" max=\" a[NR]}'"
```
Move or delete the old `soundcache/*.txt` first so you are reading only the new scene. Pass for the CPU
move: median under ~1.0 s, p90 under ~2.0 s, nothing over 5 s.

---

## 6. What I could not verify

1. **That Chatterbox actually loads and serves through `audiocpp_server` with the `server.json` shape in
   §5b.** The model is not downloaded, so nothing was run. The runtime's docs give the family, model
   directory and task (`clon`) for the CLI; the server's config parser accepts `family`/`task`/`mode`
   per model; but the combination was not executed. Treat §5b step 2 as the most likely failure point.
2. **Any latency number for a non-PocketTTS engine on this hardware.** Nothing else is installed.
   Every figure in §3a that is not marked MEASURED is the vendor's or a third party's, on their
   hardware, and the Chatterbox 0.47 s figure is from a *streaming Python* build, not this C++ runtime.
3. **VRAM for Chatterbox / VoxCPM2 / Higgs under audio.cpp specifically.** The numbers given are
   third-party FP16 figures or parameter-count arithmetic. audio.cpp supports quantised weight types
   (`--inspect ... weight-type controls`) that would change them, and I did not enumerate those.
4. **Whether Chatterbox or VoxCPM2 will co-exist with LoreRim in 16 GB.** This is the whole question for
   option 2 and it can only be answered by trying it with the game running. `pt9-tts-cpu.md` §6.1 makes
   the same point about PocketTTS: in-game GPU contention was never reproduced in a bench.
5. **OpenAI's current API text on adult content.** `openai.com/policies/usage-policies/` returned
   HTTP 403 to my fetch. The summary in §3b comes from search results, including a report that the adult
   mode is paused indefinitely as of 2026-03-26. Do not act on it without reading the page yourself.
6. **Deepgram's and Inworld's acceptable-use policies.** Both of the obvious URLs 404'd. Neither matters
   much - Deepgram cannot clone, and Inworld is not a candidate for other reasons - but "unknown" is not
   "permissive".
7. **Cartesia's and Inworld's current pricing.** Not checked. Only the ElevenLabs Flash figure
   ($0.05/1k chars, ~75 ms) was found, and that is their own published number.
8. **Whether ElevenLabs would actually tolerate explicit TTS in practice.** Their published
   prohibited-use list has no adult category, but their Agents terms do ban sexual content, and
   enforcement is described as automated systems plus human review. The policy and the practice may not
   match.
9. **The Chatterbox watermark.** Its model card says every generated file carries Resemble AI's "Perth"
   neural watermark. Whether the audio.cpp reimplementation applies it was not checked. It is
   inaudible and harmless for personal use, but it is a fact about the output.

## 7. Incidental findings (not in my lane, flagged not fixed)

- **Piper is half-enabled and cannot run** (§1c). Harmless today - `/etc/start_env` skips it because the
  CUDA keyring file is missing - but if that file ever appears, boot will try to start a service whose
  venv does not exist.
- **Connector id 2, "OmniVoice Default"** (`http://127.0.0.1:8021`) points at a service that is not
  installed. Nothing uses it (the profile uses id 1), so it is inert, but it is a trap if someone
  picks it from the profile's TTS dropdown.
- The `audiofilterd` leading-silence issue from `pt9-tts-cpu.md` §4 is **unchanged** and is independent
  of everything here: whichever engine you pick, the ~0.25-0.30 s of leading silence is trimmed only if
  that daemon exists, and it does not exist in this distro.
