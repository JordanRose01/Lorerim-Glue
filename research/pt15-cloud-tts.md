# PT15 - Cloud TTS and cloud STT for CHIM: which one, and how to switch

Researched 2026-09-23. Role: RESEARCHER. **Nothing live was changed**: no config, no DB row, no service.
Writes: this file, plus throwaway read-only scripts `pt15_*.sh` in `%TEMP%\lrg_test`.
The distro was up but **postgres/apache were stopped** (launcher closed), so DB facts below come from code
and from `pt9-tts-options.md` (read live on 2026-09-22), not from a fresh query.
Web pages were read on 2026-09-23; every policy quote gives its URL and the page's own date.

> Owner: "consider maybe using a cloud based [TTS] instead of a cpu/gpu" / "use something higher quality
> that allows for nsfw" / speech-to-text "misses names and cuts first words".

---

## 0. The short answer

| | Pick | Why, in one line |
|---|---|---|
| **Best cloud TTS** | **ElevenLabs**, model `eleven_flash_v2_5` (or `eleven_multilingual_v2` / `eleven_v3` for max quality) with stock/library voices | Best quality and by far the biggest voice catalogue (10,000+ library voices, accents, ages); its Prohibited Use Policy (17 Aug 2026) bans sexual content only around minors, real people's voices and "unauthorized sexualization" - consensual adult content is **not listed** (silent, not permission); CHIM's driver never uploads audio. |
| **Runner-up TTS** | **Cartesia** Sonic-3 | Fastest (25 ms TCP from this box, ~90-190 ms first audio) and the **only** CHIM cloud driver with a real per-voicetype map (no code) - but that driver **auto-uploads the game's voice files to clone them** unless every voicetype is pre-mapped, and its AUP bans output that "relates to... individuals under the age of 18" (child NPCs). |
| **Best cloud STT** | **OpenAI Whisper** (CHIM driver `whisper`, model `whisper-1`) | The only shipped cloud STT whose name hints actually reach the model (it sends the NPC name + recent proper nouns as a prompt). Costs ~0.5-1.5 s extra per turn (estimate) vs Parakeet's 0.125 s, and `whisper-1` shuts down **2027-02-26**. |
| **Not the STT's fault** | first-word cut | The game DLL starts delivering mic audio 110-270 ms **after** you press Left Ctrl (AIAgent.log). No STT provider can recover audio that was never recorded. Press, beat, speak. |

**Honest latency note.** CHIM's cloud TTS drivers are **blocking and non-streaming**, open a fresh TLS connection
per sentence, and (ElevenLabs/OpenAI/Deepgram) transcode MP3 to WAV with ffmpeg. Expect roughly
**0.4-0.9 s per sentence** (ESTIMATE, not measured - no key on this box). That is about the same as PocketTTS
on CPU (0.615 s measured, `pt9-tts-cpu.md`) and much better than PocketTTS starved on the game GPU (3.4 s median).
The cloud win is **voice quality + zero local CPU/GPU load**, not raw speed.

---

## 1. What CHIM actually ships (code facts)

### 1a. Cloud TTS drivers selectable in the UI (`lib/core/tts_connector.class.php:9-49`)

ElevenLabs (`11labs`), Cartesia (`cartesia`), Inworld (`inworld`), OpenAI (`openai`), Deepgram (`deepgram`),
Azure (`azure`). **Not shipped:** Google Cloud TTS (`tts/tts-gcp.php` exists but is not in the driver list),
Play.ht, Minimax, a cloud Kokoro (the `kokoro` driver is a *local* server on `127.0.0.1:8880`).

### 1b. How an NPC's voice reaches a cloud driver - this decides the mapping question

1. `core_npc_master.voiceid` holds the **voicetype name** (e.g. `femalenord`), derived from the
   `Voicetype/<codename>` conf_opts row written by `vsx.php` (`lib/data_functions.php:7143-7174`).
2. `npc_master.class.php:1070-1081` resolves it (`tts_connector.class.php:434-473`: NPC voiceid, else race
   fallback from `core_tts_fallback`, else the connector's `fallback_male/female`) and sets
   `$GLOBALS['PATCH_OVERRIDE_VOICE']` + every driver's voice global (`:1790-1808`).
3. Every cloud driver sends `PATCH_OVERRIDE_VOICE` **as the provider voice id**. There is **no
   voicetype-to-provider-voice table** for ElevenLabs, OpenAI, Deepgram or Azure (only the *local* Kokoro
   driver has a hard-coded map, `tts/tts-kokoro.php:38-132`).
4. If synthesis fails, `callNpcTtsWithFallback()` (`lib/chat_helper_functions.php:181-211`) retries with
   the race fallback, then the connector fallback - **but not for "The Narrator"** (`:171`).

Consequences per driver:

| Driver | What `femalenord` does | Per-voicetype stock voices without code? |
|---|---|---|
| **ElevenLabs** `tts/tts-11labs.php` | `POST /v1/text-to-speech/femalenord` -> error (expected 404; not tested without a key) -> `false` -> CHIM retries with the race fallback voice | **No.** Workable interim: put ElevenLabs voice IDs in the **global race x gender fallback matrix** (30 slots) -> each NPC gets a race+gender voice, at the cost of one failed call (~0.15-0.25 s) per line. Named NPCs can get their own voice ID in the NPC editor. A true 30-voicetype map needs a small glue-side mapping (build task). |
| **OpenAI** `tts/tts-openai.php` | same (400 invalid voice -> `false` -> fallback) | Same as ElevenLabs, but only 13 voices exist. |
| **Deepgram** `tts/tts-deepgram.php` | `'ignore_errors' => true` (`:64`): the JSON error body is written as `.mp3`, ffmpeg fails, the driver still returns the wav path (`:71-88`) -> **silent line, fallback never runs** | **No, and fails silently.** Every NPC voiceid would have to be a Deepgram model name. |
| **Cartesia** `tts/tts-cartesia.php` | `getOrCreateCartesiaVoice('femalenord')` (`:439-513`): (1) cached id in `conf_opts` row `cartesia_voice_id_femalenord` (or its connector-scoped copy, `:153-209`); (2) an **owned** voice named `femalenord`; (3) otherwise **uploads `data/voices/femalenord.wav` to `/voices/clone`** (`:496`, `:523-656`) | **Yes** - pre-insert one `conf_opts` row per voicetype pointing at a stock voice UUID. Any voicetype you forget that has a wav gets **auto-cloned from the game audio**. |
| **Inworld** `tts/tts-inworld.php` | lists only `source = "IVC"` (cloned) voices (`:554`); rejects any voice id without `workspace__` (`:1309-1324`) and then **clones the game wav** (`:709`) | **No.** Stock Inworld voices cannot be used without a code change. |
| **Azure** `tts/tts-azure.php` | sends it as the SSML voice name | Moot - policy disqualifies Azure (§2). |

Other driver quirks worth knowing before switching:

- **ElevenLabs** connector default `model_id` is `eleven_monolingual_v1` (`tts_connector.class.php:161`,
  `tts-11labs.php:79`). ElevenLabs removed the v1 models on **2026-07-09** -> set the model explicitly.
  Driver default voice `EXAVITQu4vr4xnSDxMaL` is a legacy "Default" voice; ElevenLabs says all Default voices
  **expire 2026-12-31** (elevenlabs.io/docs/overview/capabilities/voices, read 2026-09-23). No request
  timeout is set (`:160` commented out) -> PHP's `default_socket_timeout` (usually 60 s) applies.
- **Cartesia** appends `adelay=150|150` (`:1215-1218`): **every line starts with 150 ms of added silence**.
  Sends `Cartesia-Version: 2024-11-13` (`:836`) - untested against today's API. Returns WAV (no MP3 decode).
- **OpenAI** sends an extra `'style'` field (`tts-openai.php:43`) the API does not document - may be
  rejected (untested; use the connector's Test button). Default model `tts-1`; request `gpt-4o-mini-tts` for
  `instructions` support (connector-wide, not per NPC). MP3 -> ffmpeg `speechnorm`.
- All cloud drivers: blocking `file_get_contents`/curl, **no streaming**, new TLS handshake each call.

### 1c. Cloud STT drivers shipped (`lib/core/stt_connector.class.php:9-18`, one global connector)

`whisper` (OpenAI), `deepgram`, `gemini`, `inworld`, `azure` (+ local `parakeet`, `localwhisper`, `none`).
No AssemblyAI, no ElevenLabs Scribe, no Google Cloud STT driver.

| Driver | Name boosting as shipped | Code evidence |
|---|---|---|
| **Whisper** `stt/stt-whisper.php` | **Works.** Model hard-coded `whisper-1` (`:41`); `prompt` = `HERIKA_NAME,Dragonborn,Whiterun,` + recent words from the `memory` table (`:18`, `:44`, `lastKeyWords()` `chat_helper_functions.php:2199-2254`). Whisper-1 honours prompts up to 224 tokens. | |
| **Deepgram** `stt/stt-deepgram.php` | **Broken.** Builds `&keyterm=Lisette%3A1...` from recent speakers/locations (`:36-57`), then **overwrites `$url`** on `:60` (`$url = "https://api.deepgram.com/v1/listen?..."`) so no keyterm is ever sent. Also appends `:1`, which Deepgram's `keyterm` does not accept ("keyterm accepts plain terms only", developers.deepgram.com/docs/keyterm). | one-line core fix would make it the best option |
| **Gemini** `stt/stt-gemini.php` | Works (proper-noun list in the prompt, `:70-73`). LLM transcription; **prefixes the transcript with a tone tag** like `(playful)` (`:167-168`). | |
| **Inworld** `stt/stt-inworld.php` | None (no prompt field; default `groq/whisper-large-v3`, `:40`). | |
| **Azure** `stt/stt-azure.php` | None; reads a `profanity` setting (`:31`) but never sends it (`:35`) -> Azure default **masks profanity**. | |
| **Parakeet (current, local)** `stt/stt-parakeet.php` | Sends the same prompt, but the server ignores it: `parakeet-api-server/server.py:184` "Optional prompt (not used)". | measured below |

**Measured today (apache error.log, last 255 transcriptions):** Parakeet median **125 ms**, p90 256 ms.
"Lisette" came out as **"Lizette"/"Lazette" in 10 of 255** lines - the prompt never reaches the model.

**First-word cut (AIAgent.log 2026-09-23):** `Now recording audio` at 04:24:47.066 -> `Audio data available`
at 04:24:47.334 (268 ms); same pattern on every press (256-268 ms). Comparing held-key time with WAV length
(16 kHz mono = 32 000 B/s): 5.22 s held -> 5.00 s audio; 1.40 s -> 1.25 s; 2.86 s -> 2.75 s. So **110-220 ms
at the start of every utterance is never captured** by the game DLL. That is independent of the STT engine.

---

## 2. Policies - read today, quoted, with dates

"Silent" means the provider does not forbid consensual adult content **and does not permit it either**.
Unclear is not permission.

### TTS with the provider's own stock voices

| Provider | Explicit / adult content | Source |
|---|---|---|
| **ElevenLabs** | **Silent on consensual adult content.** Sexual clauses cover only minors ("Create, distribute or promote sexually explicit material involving minors"), "age-inappropriate material, including material that targets minors", and replicating someone's voice "including via unauthorized sexualization". Enforcement: "We use a combination of automated systems, user reports, and human review". Some library voices have **Live Moderation**, which checks "whether the text being generated belongs to a number of prohibited categories" (Voice Library docs). A third-party tracker (aihaven.com, 2026-07-05) reads it the same way: "no blanket ban on consensual adult audio content". | elevenlabs.io/use-policy, **last updated 17 Aug 2026**; ToS elevenlabs.io/terms-of-use 31 Mar 2026 (defers to the policy) |
| **Cartesia** | **Silent, with a catch-all.** Prohibited list: deceptive; illegal/abusive/fraudulent; minor exploitation; political; psychologically/emotionally harmful; violent/hateful; and "Any other content that, in our sole discretion, contradicts our values or is objectionable". Also: "You may also not use the Services to create Output that relates to, or appears in our sole discretion to relate to or represent, individuals under the age of 18" - literally covers **child NPC lines**. | cartesia.ai/legal/acceptable-use, **last updated 23 Jul 2025** |
| **OpenAI** | **Silent on consensual adult content** in the text I could read: bans "sexual violence or non-consensual intimate content", CSAM, "exposing minors to age-inappropriate content, such as ... sexual ... content", "underaged sexual or violent roleplay". Speech guide: you must disclose "that the TTS voice they are hearing is AI-generated". **Caveat:** openai.com/policies returned HTTP 403 to my fetch; the text above is OpenAI's page as mirrored by Databricks (captured 2025-11-07, "Effective: October 29, 2025"). ChatGPT's "adult mode" was reported paused indefinitely on 2026-03-26 - that is ChatGPT, not the API. | databricks mirror of openai.com/policies/usage-policies (effective 2025-10-29); developers.openai.com/api/docs/guides/text-to-speech |
| **Deepgram** | **Silent.** Terms §2.4 bar "illegal, harmful, or abusive purposes", fraud/defamation, hate/violence, impersonation; nothing on sexual or adult content. **Trains on your content** by default (§3.2-3.3 "irrevocable, perpetual ... license to use ... Your Content" to improve models; per-request opt-out exists, CHIM's driver does not send it). | deepgram.com/terms, **last updated 6 Aug 2026** |
| **Inworld** | **Leans prohibited / ambiguous:** "Don't build tools that may be inappropriate for minors, including but not limited to, sexually explicit or suggestive content, graphic violence, obscenity, or other mature themes." | inworld.ai/aup/, **last updated 11 Jun 2025**; service terms inworld.ai/service-specific-terms 14 May 2026 |
| **Azure** | **Banned twice.** "Microsoft prohibits content that is erotic, pornographic, or otherwise sexually explicit, as well as the use of Microsoft AI Services in applications that are sexually explicit. This includes sexually suggestive content..." and usage restriction 13: chatbots that "are erotic, romantic, or used for erotic or romantic purposes". | learn.microsoft.com/en-us/legal/ai-code-of-conduct, **v4.0, 2026-05-01** |
| **Google** (Gemini STT; no TTS driver) | **Banned:** "Sexually explicit content -- for example, content created for the purpose of pornography or sexual gratification", with a discretionary exception for "educational, documentary, scientific, or artistic considerations". | policies.google.com/terms/generative-ai/use-policy, **last modified 17 Dec 2024** |

### Voice cloning - why cloning the game's voices is out

All of these require the voice owner's consent; Bethesda's voice actors never gave it:
- ElevenLabs: may not "intentionally replicate the voice of another person: without consent or legal right".
- Cartesia: "You may only submit your own voice and audio recordings or those of others with explicit consent".
- Inworld: a voice model must be "your voice or a voice you are authorized to share with us".
- OpenAI: may not use "someone's likeness, including their photorealistic image or voice, without their consent".
- Azure: external users "may create voice models based only on their own voice", with a recorded consent statement.
- Deepgram: no cloning product.

**And CHIM's Cartesia and Inworld drivers clone automatically** the first time an unmapped voicetype speaks
(`tts-cartesia.php:496`, `tts-inworld.php:709`), and the TTS Studio page's "Sync"/"Batch" buttons for those tabs do
the same on purpose (`ui/xtts_clone.php:1286-1412, 2076-2210`). Never press them.

---

## 3. TTS comparison

Monthly volume used for cost: **3,000 NPC lines x 70 chars = 210,000 characters** (~230 minutes of audio).

| Provider (CHIM driver) | Latency: published / community (labelled) | Network from this box (measured today, median of 6; TCP / TLS done / first byte of an unauthenticated GET) | Price | ~210k chars/month | Stock voices | Per-voicetype in CHIM |
|---|---|---|---|---|---|---|
| **ElevenLabs** (`11labs`) | Vendor: Flash v2.5 "~75ms" (model only, docs). Community: Coval, streaming, Paris, 15-25 words: **Flash v2.5 P50 185 ms / P95 225 ms** (8 Sep 2026 window, via gradium.ai - a vendor article); an earlier snapshot put Flash at 288 ms. | 45 ms / 90 ms / 168 ms | API list: Flash/Turbo **$0.05 / 1k chars**, v3 & Multilingual v2 **$0.10 / 1k** (elevenlabs.io/pricing/api). Plans: Creator **$22/mo, 121,000 credits**, Flash = 0.5 credit/char (third-party, verified against ElevenLabs Aug-Sep 2026); pricing pages disagree on some plan figures - check in the account. | Flash ~**$10.50** at list rate; fits Creator ($22). v3/Multilingual ~**$21** at list rate; exceeds Creator's credits. | **10,000+** community library voices (usable directly via API on any paid plan, no slot needed), Voice Design, new replacement default voices; every accent/age. | Fallback-matrix interim (30 race x gender slots) or per-NPC voice IDs; true per-voicetype needs glue code. |
| **Cartesia** (`cartesia`) | Vendor: "sub-90ms" (model only). Community: Coval Sonic-3 **P50 188 ms** (earlier snapshot, date not shown). | **25 ms / 60 ms / 103 ms** (fastest) | ~1 credit/char. Pro **$5 = 100k credits**, Startup **$49 = 1.25M**; overage optional (rate not published); concurrency 3 (Pro) / 5 (Startup). | Pro + ~110k overage (unpublished rate) or Startup **$49**. | "low hundreds" (third-party), en-US / en-GB etc., 44 languages. | **Yes, no code:** one `conf_opts` row per voicetype (SQL, no UI). Unmapped = auto-clone. |
| **OpenAI** (`openai`) | No published TTFA; third-party: "often less than 500 milliseconds" for short inputs (OrcaRouter). | 29 ms / 54 ms / 157 ms | tts-1 **$0.015/1k**, tts-1-hd $0.030/1k, gpt-4o-mini-tts ~$0.015/min | **~$3-7** | **13** (gpt-4o-mini-tts), 9 (tts-1); steerable by `instructions`, connector-wide | Fallback-matrix interim only; 13 voices for 30 voicetypes. |
| **Deepgram** Aura-2 (`deepgram`) | Community: Coval **P50 313 ms** (earlier snapshot); vendor "sub-200ms". | 92 ms / 186 ms / 272 ms | **$0.030/1k**; $200 free credit | **~$6.30** (free credit lasts years) | ~40 English, mostly American business voices, a few British/Australian | **No** - errors become silent lines. |
| **Inworld** (`inworld`) | Coval TTS 2 **P50 170 ms**, Flash 2 75 ms (8 Sep 2026 via gradium.ai) | 45 ms / 86 ms / 141 ms | TTS-2 $25/1M chars on demand; 1.5 mini $5/1M, max $10/1M (third-party, Aug 2026) | ~$1-5 | stock voices exist | **No** - driver is clone-only. |
| **Azure** (`azure`) | not researched (disqualified) | 129 ms / 260 ms / 387 ms (CHIM default region `westeurope`) | - | - | 400+ | Moot. |

---

## 4. STT comparison

Volume guess: ~1,000 player utterances x ~4 s = ~67 min/month. Every option is well under $1/month.

| Provider (driver) | Names ('Lisette') | Latency | Price | Policy | Verdict |
|---|---|---|---|---|---|
| **OpenAI Whisper** (`whisper`) | **Yes** - prompt with NPC name + recent proper nouns reaches `whisper-1` | No published short-clip figure; ESTIMATE 0.5-1.5 s for a 2-5 s clip (batch model, upload of 60-160 KB WAV). Network: 29 ms TCP. | $0.006/min -> ~$0.40/mo | Silent on adult (Oct 2025 text) | **Best shipped cloud STT.** `whisper-1` is **deprecated: shutdown 2027-02-26** (developers.openai.com/api/docs/deprecations); CHIM will need `gpt-transcribe` ($0.0045/min, supports prompts + `keywords`) by then - a one-word driver change. |
| **Deepgram Nova-3** (`deepgram`) | **No as shipped** (keyterms discarded, `:60`). Nova-3 `keyterm` (500 tokens) is designed exactly for this once fixed. | Fastest cloud option; vendor/community "sub-300 ms". Network 92 ms TCP. | $0.0043/min, $200 credit | Silent; **trains on your audio** unless opted out per request (driver doesn't) | Runner-up **only with a one-line CHIM core fix** (concatenate instead of overwrite; drop `%3A1`). |
| **Gemini** (`gemini`) | Yes (prompt) | LLM call; ESTIMATE ~1-2 s | token-priced, cents | **Google bans sexually explicit content**; risk of blocked/empty transcripts | Not for this owner. Also injects `(tone)` into the transcript. |
| **Inworld** (`inworld`) | No | Groq Whisper via Inworld | cheap | AUP minors clause ambiguous | No. |
| **Azure** (`azure`) | No | - | - | Banned; profanity masked by default | No. |
| **Parakeet (current, local CPU)** | **No** (prompt ignored) | **125 ms median measured** | free | none | Fastest; only names suffer. Alternative to going cloud: keep Parakeet and add a glue-side fuzzy name fix (e.g. "Lazette" -> "Lisette" against nearby NPC names) - no network, no extra latency. |

---

## 5. Recommendation for this owner

**TTS: ElevenLabs** (Flash v2.5 first; try `eleven_multilingual_v2` or `eleven_v3` if Flash sounds flat and the
extra ~0.2-0.5 s is acceptable). Reasons: best quality; the richest voice catalogue for ~30 Skyrim voicetypes
(gruff Nord, haughty Altmer, raspy old, sultry, drunk...); its current policy lists sexual prohibitions only
around minors, real voices and non-consent, and says nothing against consensual adult content; its CHIM driver
**never uploads audio**. Use ElevenLabs-made voices or Voice Design voices for the voices that will carry explicit
scenes - community library voices are real people's cloned voices, and "unauthorized sexualization" of a real
voice is exactly the clause you do not want to test. Voice mapping: start with the race x gender fallback matrix
(no code), give key NPCs (Lisette, followers) their own voice IDs in the NPC editor, and treat a real 30-voicetype
map as a small glue build task.

**Runner-up: Cartesia Sonic-3.** Fastest from this box and the only zero-code per-voicetype map - but only if
**every** voicetype is pre-mapped **before** the first line is spoken (otherwise CHIM clones the game's voice files),
and its under-18 output clause makes child NPC lines a literal violation.

**Budget alternative: OpenAI gpt-4o-mini-tts** (~$3-7/month, 13 voices, test that the driver's stray `style` field
is accepted). **Do not use:** Azure (bans it), Inworld (driver clone-only + ambiguous AUP), Deepgram TTS (driver
fails silently on voicetype names; trains on data).

**STT: OpenAI Whisper** via CHIM's `Whisper` STT connector, to fix names - accept ~0.5-1.5 s more per turn and a
February 2027 model shutdown. If that latency hurts, the better long-term fix is either Deepgram Nova-3 with the
one-line keyterm fix, or Parakeet + a glue-side name corrector. **Neither fixes the first word**: that is the
game DLL's ~0.1-0.27 s capture delay - press Left Ctrl, wait a beat, then speak.

---

## 6. Exact setup steps (web UI on port 8081) - the owner does these

Before anything: note the current state so revert is one field. Connector id 1 `ddistro pockettts` must be
**left untouched** - it is the revert target.

### 6a. ElevenLabs TTS

1. **ElevenLabs account** (owner): paid plan (Creator is enough for Flash at this volume). In the account's
   *Terms and Privacy -> Data use*, opt out of training. Optional: set a usage/billing limit.
2. **Pick voices** in ElevenLabs *Voices* (prefer ElevenLabs-made / Voice Design voices; avoid legacy Default
   voices - they expire 2026-12-31). Copy each **Voice ID**. You need up to 30 for the matrix (15 races x M/F) plus
   one for the Narrator; suggested descriptors: Nord M "deep, gruff, northern" / F "strong, plain-spoken";
   Imperial/Breton M/F "neutral educated"; Redguard M "warm, accented"; High Elf "haughty, precise";
   Wood Elf "young, quick"; Dark Elf "dry, raspy"; Orc "very deep, rough"; Argonian/Khajiit "unusual, accented";
   Elder "old, kindly"; children - ordinary child voices (never used for adult content; the glue's adults-only
   rail stays).
3. **API key**: `http://localhost:8081/HerikaServer/ui/core/api_badge.php` -> *Preset Keys* -> **ElevenLabs**
   card -> paste the key (auto-saves) -> *Save Keys*.
4. **TTS connector**: `http://localhost:8081/HerikaServer/ui/core/tts_connectors.php` (Admin Tools -> TTS
   Connectors) -> **New** ->
   - *Name*: `ElevenLabs Flash`
   - *Service*: **ElevenLabs**
   - *API Badge*: **🟢 ElevenLabs**
   - `model_id`: **`eleven_flash_v2_5`** (the default `eleven_monolingual_v1` no longer exists)
   - `optimize_streaming_latency`: `0`; `stability`/`similarity_boost`/`style`/`speed`: leave defaults
   - *NPC Fallbacks*: `fallback_male` = a male Voice ID, `fallback_female` = a female Voice ID
   - **Save**, then **Test** (the page warns: save first).
5. **Voicetype -> voice mapping (no code)**: `http://localhost:8081/HerikaServer/ui/xtts_clone.php?tab=fallbacks`
   (TTS Studio -> *Fallback Voices*). **First write down the current values** (defaults from
   `lib/core/tts_fallback.class.php`: Nord `malenord/femalenord`; Imperial & Breton `maleeventoned/femaleeventoned`;
   Redguard `maleeventonedaccented/femaleeventoned`; High Elf `maleelfhaughty/femaleelfhaughty`; Wood Elf
   `maleyoungeager/femaleyoungeager`; Dark Elf `maledarkelf/femaledarkelf`; Orc `maleorc/femaleorc`; Argonian
   `maleargonian/femaleargonian`; Khajiit `malekhajiit/femalekhajiit`; Elder `maleoldkindly/femaleoldkindly`;
   the four child races `malechild/femalechild`). Paste one ElevenLabs Voice ID per race x gender -> **Save**.
   Effect: each NPC's voicetype name is rejected by ElevenLabs (no characters used), CHIM retries with the race
   voice. Cost: one failed round trip (~0.15-0.25 s) per line. This matrix is **global** (all connectors), but
   PocketTTS only consults it when a voicetype wav is missing, so it degrades gracefully on revert.
6. **Named NPCs** (optional, removes the failed call for them): NPC editor
   `http://localhost:8081/HerikaServer/ui/core/npc_master.php` -> the NPC -> *voiceid* = her ElevenLabs Voice ID ->
   Save. Record the old value (e.g. `femaleeventoned`) to restore on revert.
7. **Narrator**: `http://localhost:8081/HerikaServer/ui/core/narrator_management.php` -> *Voice ID* = an
   ElevenLabs Voice ID (the default `TheNarrator` is not a valid ElevenLabs id and the Narrator gets **no** fallback
   retry, so it would be silent) -> Save. Check which profile the Narrator uses and set its TTS connector too.
8. **Assign**: `http://localhost:8081/HerikaServer/ui/core/core_profiles.php` -> **Default Profile** ->
   *TTS Connector* -> `ElevenLabs Flash` -> Save. Repeat for any other profile that shows `ddistro pockettts`.
9. **Revert in one field**: Default Profile -> *TTS Connector* -> **`ddistro pockettts`** -> Save. (Also put the
   Narrator's Voice ID back to `TheNarrator` and restore any NPC voiceids / matrix values you changed.)

### 6b. Cartesia TTS (runner-up) - map first, then switch

1. Key: API Keys page -> **Cartesia** card -> paste -> Save Keys. In the Cartesia console, **disable overages**
   if you want a hard cap.
2. **Before creating/testing the connector**, insert one mapping row per voice name (35 wavs in
   `data/voices`: `TheNarrator` + 14 female + 20 male voicetypes, plus the race-fallback names that have no wav:
   `maleelfhaughty maledarkelf malekhajiit femaleargonian femaleorc femalekhajiit maleoldkindly`). There is no UI
   for this. With the CHIM server running, open a shell in the distro (`wsl -d DwemerAI4Skyrim3`), back up, then
   run a file of INSERTs (one line per name, UUIDs from the Cartesia voice library) - a file avoids the
   PowerShell/WSL quoting problems:
   ```
   PGPASSWORD=dwemer pg_dump -h localhost -U dwemer -d dwemer -t conf_opts > ~/conf_opts.bak.sql
   cat > ~/cartesia_map.sql <<'SQL'
   INSERT INTO conf_opts (id,value) VALUES ('cartesia_voice_id_femalenord','<stock-voice-uuid>')
     ON CONFLICT (id) DO UPDATE SET value=EXCLUDED.value;
   -- ...one INSERT per voice name...
   SQL
   PGPASSWORD=dwemer psql -h localhost -U dwemer -d dwemer -f ~/cartesia_map.sql
   ```
   The driver honours this legacy prefix and copies it into its connector-scoped key (`tts-cartesia.php:182-209`).
3. TTS Connectors -> New -> *Service* **Cartesia**, *API Badge* **🟢 Cartesia**, `model_id` **sonic-3**,
   `language` en, `speed` normal, NPC Fallbacks = two mapped names (e.g. `malenord` / `femalenord`) -> Save -> Test
   (Test uses the voice `TheNarrator` - map it first).
4. **Never** press Sync / Batch / Resync on TTS Studio's Cartesia tab (they upload game audio to clone).
5. Assign to Default Profile as in 6a step 8; revert the same way. Remove mappings later with
   `DELETE FROM conf_opts WHERE id LIKE 'cartesia_voice_%';` (inert for PocketTTS, so optional).

### 6c. OpenAI TTS (budget)

API Keys -> **OpenAI** card -> paste. TTS Connectors -> New -> *Service* **OpenAI**, *API Badge* 🟢 OpenAI,
`model_id` **gpt-4o-mini-tts**, `instructions` e.g. "Voice acting a character in a gritty medieval fantasy game",
NPC Fallbacks = two of `alloy ash ballad coral echo fable nova onyx sage shimmer verse marin cedar` -> Save -> **Test**
(checks the undocumented `style` field). Mapping, narrator, profile and revert exactly as 6a steps 5-9.

### 6d. STT: OpenAI Whisper

1. API Keys -> **OpenAI** card -> paste key (same key as 6c if used).
2. `http://localhost:8081/HerikaServer/ui/stt_connectors.php` - this page edits the **single global** STT
   connector. Click the **Whisper** service card -> *API Badge* 🟢 OpenAI -> `LANG` `en` -> `TRANSLATE` off ->
   **Save**.
3. Test in game with a line containing "Lisette"; check `/var/log/apache2/error.log` is quiet.
4. **Revert in one field**: same page -> **Parakeet** card -> Save.
5. (Deepgram alternative only after the owner approves the one-line core fix to `stt/stt-deepgram.php:60`: Deepgram
   card, 🟢 Deepgram badge, `MODEL` `nova-3`, `LANG` `en`. A CHIM update would overwrite the fix.)

---

## 7. Caveats

- **Privacy.** Every NPC line (explicit text included) goes to the TTS provider; every push-to-talk clip goes to
  the STT provider. The LLM already sees all of it (OpenRouter -> xAI), so this adds processors, not a new kind of
  exposure. ElevenLabs trains on non-enterprise data by default (opt out in *Data use*); Deepgram trains unless a
  per-request opt-out is sent (CHIM does not); Cartesia's zero-retention is enterprise-only; OpenAI API data use
  was not re-read today.
- **Moderation / account risk.** "Silent" policies can change and are enforced by automated systems plus human
  review; an account can be suspended. Keep the PocketTTS connector intact as the fallback.
- **Offline / outage.** There is **no automatic cross-connector fallback**: if the provider is down, CHIM retries
  the fallback voices on the same provider, each fails, and the NPC line has **no audio**. ElevenLabs/OpenAI
  drivers set no timeout, so a hanging API can stall a line for up to `default_socket_timeout` (usually 60 s).
  Revert = one field (6a step 9).
- **Rate limits.** Cartesia concurrency 3 (Pro) / 5 (Startup); Deepgram PAYG TTS 45; ElevenLabs by plan.
  CHIM mostly synthesises sentence by sentence, so limits are unlikely to bite.
- **Cost caps.** ElevenLabs: subscription credits cap usage unless usage-based billing is turned on. Cartesia:
  turn overages off. OpenAI: set a project budget. Deepgram: prepaid $200 credit.
- **GPU.** Moving NPC voices to the cloud removes TTS compute from the 4080, but `audiocpp_server` still starts and
  holds ~1.5-2.4 GB VRAM while idle unless the owner disables that service (separate decision).
- **Leading silence.** Cartesia adds 150 ms by design; other drivers add ffmpeg time (~50-100 ms, estimate).
- **Model lifecycles.** ElevenLabs Default voices end 2026-12-31; `eleven_monolingual_v1` already gone;
  `whisper-1` ends 2027-02-26.

## 8. What I could not verify

1. Any end-to-end latency through CHIM for a cloud provider - **no API key on this box**; every provider latency
   above is published or community-measured and labelled. The network numbers are measured.
2. The exact error each provider returns for an invalid voice id (`femalenord`) - inferred from API docs and the
   drivers' error handling; confirm with the connector's Test button and one NPC line.
3. OpenAI's live usage-policy page (HTTP 403); the text used is the 2025-10-29 policy from a mirror.
4. Whether OpenAI's speech endpoint rejects the driver's extra `style` field.
5. Whether Cartesia still accepts `Cartesia-Version: 2024-11-13` and the string `speed` value.
6. ElevenLabs plan details (credits, voice slots, overage) - two ElevenLabs pages disagree; confirm in the account.
7. Current DB rows (profiles other than Default, Narrator profile, distinct NPC voiceids) - postgres was stopped;
   the owner can confirm in the UI.
8. `HERIKA_NAME` at STT time (which name leads Whisper's prompt) - the recent-keyword part is what carries
   companion names either way.
