# PT15 - voice-to-text lane (server 0.5.6, deployed 23 Sep 05:20)

Owner's words: "it misunderstands me sometimes ... cuts off the first word ... doesn't understand when I say names";
"sometimes when I talked to people they still didn't always answer me".

## Short version

| Symptom | What the data says | What was done |
|---|---|---|
| NPC sometimes doesn't answer | parakeet returned an EMPTY transcript for 3 of 54 lines on 23 Sep (5.6 %), 9 of 164 on 21 Sep, 1 of 37 on 22 Sep, even though the recordings clearly contain speech. The DLL still sends `inputtext` as `"Jordan:\r\n\n"` and CHIM drops it after the lock, so nothing happens (at 04:33:09 the owner typed the line instead). | **Fixed in the glue.** An empty transcript is transcribed again from CHIM's own copy of the recording with 250 ms of silence appended, then with 500 ms of silence in front if the first retry is empty too. Live test on the real recordings: 2 of 3 recovered. |
| Names ("Lazette") | 12 of the 121 different player lines in the glue log have a mangled "Lisette": *lazette* ×6, *lizette* ×3, *lazat* ×3. CHIM's parakeet driver sends a name prompt, but parakeet ignores it (`server.py`: "prompt: Optional prompt (not used)"). | **Fixed in the glue.** A word that isn't an English word and is close to the name of someone present gets replaced with that name. Across all 121 lines it changed exactly those 12 and nothing else. |
| First word cut | **The mic capture does not cut the start.** All 54 recordings begin with 0.22-1.61 s of room tone before the voice (-55 to -70 dBFS), and none starts mid-word. The model itself drops words: in "Up man, I was looking to join the Legion" and "Like to take guard duty" the voice starts 0.65 s and 0.74 s into the recording. | Can't be repaired after the fact. The only fix is a better STT engine (section 3). |
| End of a sentence cut | **The game drops the unfinished last 250 ms buffer when the key is released.** Measured on all 27 recordings of the 04:21 session (AIAgent.log key-hold time vs WAV length): 0.02-0.26 s lost, mean 0.16 s. 22 of 54 recordings still have voice in their last 50 ms. Examples: "train me in Smith" (smithing), "Did you hear what happened in". A cut-off ending also makes parakeet INT8 unstable, which is what produces the empties and the dropped middle words. | Owner habit, free: **keep holding the talk key about half a second after your last word.** Too-short transcripts get one retry, kept only when it adds words and keeps all the original ones (e.g. "Wow, isn't it?" becomes "Wow this city's just beautiful, isn't it?"). |

## 1. Diagnosis

**Pipeline.** Push-to-talk on Left Ctrl (AIAgent.log: "Using mapped key code: 29 -> 17"). The DLL records with waveIn at 16 kHz, mono, 16-bit, in 250 ms buffers, so every WAV length is a multiple of 0.25 s. It posts to `stt.php`, which copies the upload to `soundcache/_stt_<md5>.wav` and calls `stt/stt-parakeet.php`. That driver talks to `127.0.0.1:8022`: parakeet-tdt-0.6b-v3 **INT8** through sherpa-onnx, CPU only, greedy search, multilingual v3 model. The DLL then sends `inputtext|..|..|Jordan:\r\n<text>\n|<routing snapshot b64>` to streamv2.php. A typed line arrives as `Jordan:<text>` with no CR/LF. The soundcache only held 23 Sep's 54 recordings (651 files, all from today).

**Capture settings.** The AIAgent.dll strings contain no pre-roll or post-roll setting for push-to-talk. The only voice settings are open mic (`_openmic_enabled`, `_openmic_sensitivity`, `_openmic_enddelay`, a level threshold). **Do not switch to open mic:** it starts recording at a threshold, which would really cut first syllables. CHIM's web UI has no STT capture settings, only the engine choice.

**What the model does to the same audio** (54 real WAVs, decoded directly with the installed model; decode median 95 ms):

| variant | empty results |
|---|---|
| as recorded | 3 |
| +250 ms silence at the end | 2 |
| +500 ms at the end | 4 |
| +1 s at the end | 5 |
| +500 ms in front | 2 |

Results flip in both directions, so padding every line would not help. Retrying only suspect results is safe. Examples of flips: "Everybody's gotta pray" becomes "got a price" with the 250 ms tail. "And everybody has a price." becomes empty with the same tail.

**Empty rate by day.** From apache error.log `Transcription time` lines: 21 Sep 9/164, 22 Sep 1/37, 23 Sep 3/54. No failed HTTP calls. The glue's `WARN empty transcript` only fires inside a scene, so it logged just 1 (21 Sep).

## 2. Implemented (server 0.5.6)

- `server/lorerim_glue/lib/lrg_stt.php` (new) holds the repair logic.
- `preprocessing.php` runs the repair as its first step for inputtext / ginputtext / narrator_inputtext requests, before the lock and before any other code reads the text.
- `lrg_stt_words.txt` (new) is CMUdict's 123,208 words from the distro's own nltk_data. It is the "is this an English word" check and is only read when a name candidate exists.
- `lrg_config.default.json` has a new `stt` block with `_stt_readme`.
- `deploy_server.ps1` has one new anchor (34 in total).
- Version is 0.5.6 in `manifest.json` and `LRG_VERSION`.

**Retry rules.**
- It only runs on lines that came from the microphone (the `Name:\r\n` shape), only while CHIM's STT is parakeet, and only with a recording under 6 s old that has at least 0.3 s of voice.
- An empty transcript tries `tail:250`, then `lead:500`.
- A short transcript (fewer than 1.8 words per voiced second, at least 1.2 s of voice) gets one retry. The retry is kept only if it contains every original word in the same order and adds more.
- Normal lines cost nothing. Each retry adds about 0.1-0.14 s, and only on those lines.
- Log lines: `stt retry: empty transcript -> "..." (tail:250, N ms, X s voiced)` and `stt retry: "..." -> "..."`.

**Name rules.**
- The names come from the routing snapshot the DLL attaches to every player line (speaker, listener, companions, present_actors without creatures), plus the player, the current NPC and `stt.names`.
- `[role]` tags and titles are removed, and parts shorter than 4 letters are never used.
- Every one of these must hold before a word is replaced:
  - it has 4+ letters and isn't an acronym;
  - it isn't already a name;
  - it starts with the same sound (c/k/q, s/z, any vowel);
  - its length is within 2 of the name's;
  - its edit distance is at most 1 for 4-5 letter names, 2 for 6-8, 3 for 9+ (or it has the same consonant outline, as in l-s-t for "lazat", within 60 % of the name's length);
  - exactly one name is closest;
  - it isn't in the dictionary, the built-in protect list (slang, skills, places and gods of Tamriel) or `stt.protect`.
- Text inside `(...)` is never touched.
- Log line: `stt fix: Lazette -> Lisette`.

**Verification.**
- New `tools/test_stt.php`: 60 passed, 0 failed.
  - A: all 54 real lines, checked against every name present that night, are unchanged except "Hey Lazette", which is corrected.
  - B: 28 edge cases plus 5 unit checks, including brass/Braste, breast/Braste, octave/Octieve, brand/Beirand, last and list/Lisette, alias/Aldis, Mara vs Maria, a tie between two names, a dictionary name (Mikel), text in brackets, titles, possessives and hyphenated names.
  - C: the request rewrite keeps the label and whitespace byte for byte.
  - D: the retry, with a stand-in server.
- Corpus scan: all 121 different player lines in the live glue log, against 57 name parts from 44 NPCs. 12 changed, all of them the Lisette misses, 0 false positives, 0.29 ms per line.
- Live check: I started a temporary parakeet instance on port 8023 (8022 and its config untouched, stopped afterwards) and ran `test_stt.php --live`. All three real empty recordings came back `""` as originally sent, which reproduces the bug. After the retry, 04:31:27 gave "And stop following me." (168 ms) and 04:32:35 gave "Well I miss you're looking mighty beautiful tonight." (277 ms). 04:36:17 stayed empty (232 ms).
- Full suite:
  - `php -l`: 84 files, 0 errors.
  - Unit tests: gates 385, intent 255, phrases 31, scene_index all passed, dialogue 174, prompt_index 67, mcm_wiring 3, services 35, latency 30, stt 60 (all 0 failures).
  - `run_flows --strict`: 78 of 78 scenarios, 1314 checks, 0 warnings, no PHP warnings.
- Deploy:
  - `deploy_server.ps1` ran; its pre-flight passed with all 34 anchors, and all 27 files are SHA-256 identical on the server.
  - Its last step (loading the prompt index into Postgres) failed: at 05:21 every distro service was stopped (Postgres, apache, parakeet, audio.cpp), which looks like the launcher's server was closed. I started Postgres only for the load (37,561 prompts, source=db, built from the current load order) and stopped it again.
  - Side effect: the glue log's "prompt index is STALE" warning should be gone next session.
  - Skyrim was not running.

## 3. STT engine options in CHIM 3.3.2 (`stt/stt-*.php`)

| Engine | Where | Installed? | Names | Notes |
|---|---|---|---|---|
| **parakeet** (current) | local CPU | yes | prompt ignored by the server; glue name fix | ~0.1 s. Empties and dropped words come from the INT8 model when the audio ends mid-word; the glue retry covers the empties. |
| **deepgram** (nova-3) | cloud | driver only | **yes**: the driver sends up to 30 `keyterm`s (speaker, location and companion names from recent speech rows) | Best fit for the name problem. Needs an API key and is paid per minute. The driver's URL does not enable Deepgram's profanity filter, so explicit speech is transcribed as spoken. Latency is network-bound; not measured here. |
| whisper (OpenAI whisper-1) | cloud | driver only | yes (prompt with HERIKA_NAME, Dragonborn, Whiterun and recent keywords) | Needs an OpenAI key; typically slower than Deepgram. |
| inworld (default groq/whisper-large-v3) | cloud | driver only | no | Needs an Inworld key. |
| azure | cloud | driver only | no | Profanity setting must be `raw`. |
| gemini | cloud | driver only | no | The driver sets no safety settings, so explicit lines may come back blocked (untested). Not advised for this playthrough. |
| localwhisper | local | **not installed**: no `/home/dwemer/python-stt` venv, `remote-faster-whisper/models` is empty | no: the server passes no initial_prompt/hotwords; the glue name fix still applies | Configs offered: Small-CPU-EN, Small/Medium/Tiny-GPU-EN, numbat-GPU-Skyrim-EN. GPU variants share the RTX 4080 with Skyrim, where TTS was measured at 3.4 s in game vs 89 ms idle (pt9), so use CPU. Whisper always encodes a 30 s window, so large-v3/turbo on CPU will be much slower than parakeet's 0.1 s (an estimate, not measured). |

**Recommendation.** Keep parakeet with the 0.5.6 repairs for this playthrough; it costs nothing on normal lines. Add the half-second key-hold habit, which removes the end cuts that trigger most of the model's flips. If names or dropped words still bother you after that, switch to **Deepgram nova-3**: it is the only engine here that is biased toward the names in the scene. I did not measure Whisper, because it needs about 2 GB of downloads (torch, faster-whisper and a model), and downloading needs your OK.

**Steps for Deepgram.** Create a Deepgram API key. Open the CHIM web UI (http://localhost:8081), go to the Speech-to-Text service and set it to `deepgram`, with MODEL `nova-3`, LANG `en` and the API key. Save and test in game. To revert, set it back to `parakeet`. While it is set to deepgram the glue retry switches itself off (`retry_engines`); the name fix stays on.

**Steps for local Whisper (only if you want to test it).**
1. Run `wsl -d DwemerAI4Skyrim3 -u dwemer -- bash /home/dwemer/remote-faster-whisper/ddistro_install.sh`. It creates the venv and installs torch (CPU or cu128), faster_whisper and ctranslate2 4.4.0 (downloads).
2. Run `conf.sh` and pick `config-Small-CPU-EN.yaml`, or copy it and set `model: large-v3-turbo`, `device: cpu`, `compute_type: int8`, `beam_size: 1`, and remove `translate: yes`.
3. Start it with `start.sh` (port 9876).
4. In the CHIM UI set STT to `localwhisper`, the URL to `http://127.0.0.1:9876/api/v0/transcribe` and FORMFIELD to `audio_file`.
5. Measure a few lines before committing to it.

## 4. Owner steps

1. **Nothing is needed to get the fix.** It is deployed and becomes active the next time the CHIM server is started.
2. When talking, keep holding the key about half a second after your last word, and start talking after you press it (you already do this).
3. Optional: in `ext/lorerim_glue/config/lrg_config.json`, add `"stt": {"names": ["..."]}` for names you say that aren't present (for example an absent follower), or `"stt": {"protect": ["..."]}` for a word that must never be changed.
4. To see the repair working, look for `stt fix:` and `stt retry:` lines in `HerikaServer/log/lorerim_glue.log`.

## Not done
- Local Whisper was not benchmarked (needs downloads; owner's call).
- No live request went through apache: the services were stopped, so the path was verified with the unit tests, the stand-in server and the temporary parakeet instance.
- 1 of the 3 real empties (04:36:17) can't be recovered by any padding. It may have been a mumble; only another engine could tell.
