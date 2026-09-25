# PT9 - verification and release of the owner's follow-ups

Role: VERIFIER + RELEASE. Ran 2026-09-22, 16:05-16:40 local, against the live `DwemerAI4Skyrim3`
distro and the live MO2 install.

**Status: SHIPPED.** The audiofilterd daemon is deployed, running, and proven live end to end through
CHIM's own code path. The three INI overrides were already installed and are verified correct. Three
defects were found; the three small ones are fixed and redeployed, the fourth is an upstream coupling
the owner should know about but which is inert today.

---

## 0. What I changed

| | |
|---|---|
| Fixed in our plugin | `glue/server/lorerim_glue/tts/lrg_audiofilterd_boot.php` - new `lrgTtsTouchAhead()` and a guard that refuses to spawn when the rate-limit marker cannot be written (defect 1). `glue/server/lorerim_glue/tts/lrg_audiofilterd.php` - `--status` now reports the daemon's pid, not the forked child's (defect 2). |
| Fixed in our tools | `glue/tools/deploy_server.ps1` - added the missing anchor for `lrgTtsEnsureAudiofilterd();` (defect 3). The deploy now checks **29** anchors. |
| Deployed | `tools\deploy_server.ps1`, twice (once to ship, once to ship the `--status` fix). Both clean, exit 0. |
| Changed on the game side | **nothing.** The three INI files were already installed by the previous lane; I only verified them. No file under `F:\Modlists\LoreRim` was written by me. |
| Changed in CHIM / HerikaServer core | **nothing.** No file under `/var/www/html/HerikaServer` outside `ext/lorerim_glue` was written. |
| Written in WSL | `/tmp/lrg_v` (a staged copy of the project for the test run), `/tmp/lrg_perm`, `/tmp/lrg_conc`, `/tmp/lrg_storm*`, `/tmp/lrg_s1..3`, `/tmp/lrg_f1..3`, `/tmp/lrg_own`, `/tmp/v_onset.php`, `/tmp/v_lead.php`. All throwaway. |
| Side effects of the live proof | 5 new `soundcache` wav pairs and their sidecars, and log lines in `chim.log` and `lorerim_glue.log`. CHIM's own TTS test page sets `PATCH_DONT_STORE_SPEECH_ON_DB`, so **nothing was written to the database** and no stored setting was touched. |
| Daemon left running | **yes, one** - pid 46557, on `/var/www/html/HerikaServer/tts/audiofilterd.sock`. That is the shipped state. |

---

## 1. AUDIOFILTERD

### 1.1 The protocol matches the real client

I did not take the build report's word for it. `tools/test_audiofilterd.php` `require`s
`/var/www/html/HerikaServer/tts/audiofilterd_client.php` and calls `processAudio()` with the same
filter list and the same `250.0` that `tts-pockettts.php:483` uses. I ran it myself, **twice**: as
`root` and as **`www-data`, the user Apache really runs as**.

```
75 passed, 0 failed     (as root)
75 passed, 0 failed     (as www-data)
```

I re-read the client line by line against the daemon. Every load-bearing detail holds:

* unix **stream** socket, no framing - `stream_socket_shutdown(WR)` is the end of the request
  (client line 46), `stream_get_contents()` to EOF is the end of the reply (line 48). The daemon
  half-closes after writing. ✅
* `($error['code'] ?? -1) !== 0` at client line 61 is a **strict** comparison, so the code must be an
  integer. `lrgAfRespond()` casts with `(int)`. A `"0"` would have made every success look like a
  failure. ✅
* the client, not the daemon, writes the output file (line 77). The daemon never touches the
  filesystem for it. ✅
* the client's CLI block (line 88) only fires when `realpath($argv[0]) === realpath(__FILE__)`, so
  requiring it from a test does not run it. ✅

### 1.2 Failures always return the original audio with code 0

Verified by the suite's §3 and re-read in the source. `lrgAfHandle()` wraps `lrgAfApplyFilters()` in
`try/catch (Throwable)` and falls back to the input bytes; an empty result also falls back. Garbage,
an mp3-looking payload, a `RIFF` that is not `WAVE`, a truncated header, 8-bit PCM, an unknown filter
type and an empty filter list all come back **byte-identical with code 0**.

An error object is only ever returned when there is no audio to return at all - unparseable JSON,
undecodable base64, or a request over `max_request_bytes`. In those cases CHIM's ffmpeg fallback does
exactly what it does today. The limit is 32 MB; **the largest real `_o.wav` on this box is 418,604
bytes**, so base64 of the worst case is ~0.56 MB, 60x under the limit.

### 1.3 The auto-start cannot spawn a second daemon or loop - after my fix

This is where I found the real defect. See §4, defect 1. Measured, on this box:

| scenario | before my fix | after |
|---|---|---|
| 40 hook calls, daemon cannot bind, markers owned by the caller | 1 spawn | 1 spawn |
| 40 hook calls, daemon cannot bind, **markers owned by `dwemer` (what every deploy leaves)** | **40 spawns, 73 log lines** | **1 spawn, 1 log line** |
| 40 hook calls, daemon healthy | 0 spawns | 0 spawns |
| 12 concurrent supervisors against a dead socket | 7 spawn attempts, **exactly 1 surviving daemon** | same |
| 400 hook calls, daemon healthy | - | **0.0015 ms each** |

The 12-way concurrency result is worth stating plainly: the rate-limit marker is racy under a
thundering herd, so several Apache children can each start a daemon in the same instant - but
`lrgAfServe()` probes first and, on losing the `stream_socket_server` race, exits 0 without touching
the winner's socket. The outcome is bounded and self-correcting: **one daemon, always.** Live, after
the deploy, 20 further web requests produced 0 extra spawns and the process count stayed at 1.

### 1.4 Socket permissions work for www-data

Verified directly, not inferred. A daemon started **as www-data** creates
`srw-rw---- www-data www-data` and www-data can connect to it. The containing directory
`/var/www/html/HerikaServer/tts/` is `drwxrws--- dwemer:www-data`, setgid, so nothing wider is ever
needed. Live, the real socket is `srw-rw---- 1 www-data www-data`.

### 1.5 A stale socket / pid is handled

Verified by the suite's §7 and re-read: singleton is decided by **socket connectability**, never by
the pid file, so a pid file naming a dead pid can never block a start; a socket file with nothing
behind it is unlinked and rebound; a second spawn against a live socket is a no-op. `lrgAfCleanup()`
only unlinks a socket whose inode is still the one it bound, and only in the process that bound it
(a forked child owns nothing).

### 1.6 Every existing suite still passes

Run on a staged copy of the **whole** project tree (`glue/game/` included - that is why these numbers
are higher than the build report's, whose staging omitted the game folder and so failed four checks
for want of files):

| suite | result |
|---|---|
| `php -l`, 17 plugin files + all tools | 0 failures |
| `test_gates` | **319 passed, 0 failed** |
| `test_intent` | 190 passed, 0 failed |
| `test_phrases` | 14 passed, 0 failed |
| `test_scene_index` | ALL CHECKS PASSED |
| `test_dialogue` | 174 passed, 0 failed |
| `test_prompt_index` | 67 passed, 0 failed |
| `test_mcm_wiring` | 4 passed, 0 failed |
| `test_services` | 35 passed, 0 failed |
| `test_latency` | 26 passed, 0 failed |
| `tools/flows/run_flows.php --strict` | **74 scenarios, 74 passed, 0 FAILED, 1179 checks, 0 warnings** |
| `tools/test_audiofilterd.php` | 75 passed, 0 failed (root **and** www-data) |

All of it was run again after my fix, with identical results. **The build report's four "failures"
were staging artefacts and are not real.** `test_prompt_index` and `test_services` need the two
artefacts the deploy builds (`data/prompt_index.ndjson`, `data/service_catalog.json`); I borrowed the
live copies read-only.

And the guard that matters: running all of that started **no daemon** and created **no marker** on
the live socket. `lrgTtsEnsureAudiofilterd()` is inert under CLI unless a test opts in.

### 1.7 PROVEN LIVE

The build report's §4.1 said the spawn had never been exercised by a real Apache request. **It has
now.** CHIM's own TTS test page, `ui/tests/tts-test.php`, is the right lever for three reasons: line
37 does `requireFilesRecursively(ext/, "globals.php")`, so it loads **our supervisor inside a real
mod_php request as www-data**; it runs a real synthesis through `tts-pockettts.php` and therefore
through `processAudio()`; and it sets `PATCH_DONT_STORE_SPEECH_ON_DB`, `AVOID_TTS_CACHE` and
`TTS_FFMPEG_FILTERS = []` itself, so it writes nothing to the database and changes no stored setting.

**Baseline, before the deploy** - one POST, connector 1 (`ddistro pockettts`), voice `femalenord`:

```
'Audio processing failed for PocketTTS response' : 61 -> 62   (+1)
'FFMPEG command executed'                        : 61 -> 62   (+1)
edd9bf116a2e_o.wav  leading silence 382.5 ms
edd9bf116a2e.wav    leading silence 382.5 ms   <-- the ffmpeg fallback removed nothing
```

**After the deploy** - the very first POST started the daemon and its own line already went through
it:

```
audiofilterd: nothing answering on .../audiofilterd.sock - starting the daemon (ok via proc_open)
audiofilterd: listening on .../audiofilterd.sock (pid 45799, v0.5.2, fork=yes, signals=yes)
audiofilterd: 126764 -> 126764 bytes (+0), trim_start(250 ms) removed 0 ms [onset at 1 ms -> nothing to remove], 3.2 ms
audiofilterd: 142124 -> 124604 bytes (-17520), trim_start(250 ms) removed 365 ms [onset at 405 ms], 3.2 ms
audiofilterd: 138284 -> 135354 bytes (-2930), trim_start(250 ms) removed 61 ms [onset at 101 ms], 3.3 ms
```

```
'Audio processing failed for PocketTTS response' : 62 -> 62   (delta 0)   OVER FOUR MORE REAL CALLS
'FFMPEG command executed'                        : 62 -> 62   (delta 0)
```

**Leading silence, measured with `ffmpeg silencedetect=noise=-40dB` on each pair:**

| line | raw `_o.wav` | played `.wav` | |
|---|---|---|---|
| `edd9bf116a2e` (baseline, before) | 382.5 ms | **382.5 ms** | the bug |
| `5046e6267f10` | 407.7 ms | **42.7 ms** | ✅ |
| `8a8627345004` | 101.0 ms | **40.0 ms** | ✅ |
| `a5c52e0e6ace` | 100.1 ms | **40.0 ms** | ✅ |
| `1335a8eef642` | none | none | correctly left alone |
| `c5a2e1528cec` | none | none | correctly left alone |

All three trimmed lines land **under the ~60 ms bar**, at exactly the 40 ms lead-in the design keeps
so the first consonant is never clipped.

**`ffmpeg transcoding` in the sidecars** (which is really "time in `processAudio` or ffmpeg"):
**median 0.0550 s** over the 138 older files → **median 0.0050 s** on the ones written through the
daemon. An 11x drop, and no ffmpeg process spawned at all.

**Filter cost:** 3.2-3.3 ms per request across every line served (budget 250 ms).

### 1.8 A correction to my own first reading

My first pass at the leading-silence check grepped the *first* `silence_end` anywhere in the file and
appeared to show two lines going through untrimmed. That was my error, not the daemon's: on these
lines the first silencedetect region is a **mid-word gap**, not leading silence. Re-measured properly
(a region counts only if it starts at t≤0.01 s), and cross-checked against the detector over **all
138 real files**:

```
false negatives (ffmpeg sees >150 ms of leading silence, we trim nothing): 0 of 138
agreement on files that do have it:   421.9 vs 426.6 | 507.5 vs 509 | 518.2 vs 521.2
                                      471.0 vs 474.0 | 101.6 vs 101.6 | 415.6 vs 415.7  ms
```

And the population figure, which is the case for the adaptive trim over a flat 250 ms:

```
110 of 138 files (80%) have leading silence: min 72, median 417, p90 556, max 769 ms
the other 28 (20%) start speaking immediately - a flat 250 ms cut would have eaten the first word
of every one of them.
```

**No defect in the trimmer.** The build report's claim that a fixed 250 ms is wrong in both
directions is independently confirmed on the owner's own audio.

---

## 2. INI OVERRIDES

All three files verified against the file that was winning **before** us, key by key.

### 2.1 Faithful copies, only the intended keys changed

| file | body lines, orig → ours | keys changed | lost | added |
|---|---|---|---|---|
| `SmartTalk.ini` | 95 → 95 | `bSkipImmediateOnInput` 1→0, `bHoldToSkip` 1→0, `bDBVOIntegration` 1→0 | none | none |
| `AlternateConversationCamera.ini` | 33 → 33 | `bForceFirstPerson` 1→0, `bHideDialogueMenu` 1→0 | none | none |
| `Fuz Ro D'oh.ini` | 3 → 3 | `WordsPerSecondSilence` 2→3 | none | none |

("body lines" = every non-blank line that is not a `;` comment.) **Exactly the intended keys differ
and nothing else.** All three are CRLF, pure ASCII, no BOM, and every header line begins with `;` -
which matters, because `LRG_DlgUI.IniInt()` only counts a key that sits at the start of a line, so
the key names inside our headers are inert.

The two keys the build report said must stay untouched are untouched:
`iPapyrusHandle = 3`, `bSkipOnInteraction = 0`. And `bIaccToggle = 0` is still 0 in our own
`MCM\Config\LoreRimGlue\settings.ini`, so the glue writes no IACC setting at runtime.

### 2.2 The IACC finding holds

`AlternateConversationCamera.ini` really does exist twice, and the file that was winning was **not**
the mod author's:

```
SKSE\Plugins\AlternateConversationCamera.ini
   line     3  LoreRim Glue                                 5511B   <-- ours, wins
   line   145  LoreRim - MCM and INI Settings               3502B   <-- what was winning (bForceFirstPerson=1)
   line  3712  Improved Alternate Conversation Camera       3509B   (bForceFirstPerson=0, never read)
```

Our copy is a copy of **line 145's**, so LoreRim's own 30-odd tuned values carry over. Reading the
mod author's copy would have reported "already 0, nothing to do" and left the first-person snap in
place.

### 2.3 Our mod really wins

Every one of the **3,951 enabled mods** in `profiles\Ultra\modlist.txt` was probed for each path:

```
SKSE\Plugins\SmartTalk.ini                    line 3 LoreRim Glue | line 3845 Smart Talk
SKSE\Plugins\AlternateConversationCamera.ini  line 3 LoreRim Glue | line 145 LoreRim - MCM and INI | line 3712 IACC
SKSE\Plugins\Fuz Ro D'oh.ini                  line 3 LoreRim Glue | line 145 LoreRim - MCM and INI (58 B)
```

MO2's `modlist.txt` is highest-priority-first, so **line 3 wins all three.**

### 2.4 Installed copies hash-match, and nothing else was touched

| file | bytes | SHA-256 (first 16) | source == installed |
|---|---|---|---|
| `SKSE\Plugins\SmartTalk.ini` | 6881 | `0FE637CE876C5155` | MATCH |
| `SKSE\Plugins\AlternateConversationCamera.ini` | 5511 | `ADF69E038608925A` | MATCH |
| `SKSE\Plugins\Fuz Ro D'oh.ini` | 1192 | `9BC2EF3336E0F6B0` | MATCH |
| `OVERRIDES.md` | 20778 | `4B9D36082F5EC8DA` | MATCH |

All four match the hashes the build report published.

**Profile timestamps: nothing was edited.** The newest file in `F:\Modlists\LoreRim\profiles\Ultra`
is from **21 Sep 16:54** (`archives.txt`); `modlist.txt` 21 Sep 16:54, `plugins.txt` 21 Sep 22:18,
`settings.ini` 21 Sep 16:54. **No profile file carries a 22 Sep timestamp.** No mod folder other than
`LoreRim Glue` was written today. Skyrim is not running; MO2 is (1 process), which is why the
fallback install route was correct.

Not verifiable from disk: that the game reads the new values. The in-game check remains the glue's
Calibration status line no longer saying `blocked - Smart Talk bSkipImmediateOnInput must be 0`.

### 2.5 Helmet Toggle 2

Confirmed: no file shipped by us, and the mod's own `settings.ini` already reads `iEnableDialogue=0`.
Shipping one would have been the wrong lever anyway - it is an MCM Helper setting whose live value
lives in the save.

---

## 3. TTS RESEARCH - three spot-checks

### 3.1 Supported-engine claim, against the installed files ✅

| claim | verdict |
|---|---|
| "17 selectable drivers, hard-coded in `lib/core/tts_connector.class.php`" | ✅ `$driverMap` holds 18 entries, one of which is `none` => Disabled. **17 real drivers**, exactly as listed. |
| "Chatterbox uses `\"task\": \"clon\"`" - the report's own most-uncertain line | ✅ **verbatim** in `/home/dwemer/audio.cpp/docs/tts.md:31`: `| Task | \`clon\` |`, with a matching CLI example on line 38. |
| "only `models/pocket-tts` is downloaded" | ✅ `ls models/` shows exactly one directory. |
| "XTTS is source-only, no venv, nothing on 8020" | ✅ 7.1 MB, no `venv`, 0 listeners on 8020. |
| "Piper looks enabled but cannot start" | ✅ `start.sh` exists, `/home/dwemer/python-piper` does not, the CUDA keyring `.deb` does not, 0 listeners on 5000. |
| "connector id 2 OmniVoice points at a service that is not installed" | ✅ row exists, `http://127.0.0.1:8021`, nothing there. |

**One factual error found:** §1a says "26 `tts-*.php` files ship". There are **24**. The conclusion
drawn from it (17 selectable, 7 dead) is arithmetically right; only the total is wrong.

### 3.2 NSFW-policy quote, against the provider page ✅

Claim: *Azure - Microsoft AI Services Code of Conduct, v4.0, dated 2026-05-01; the "Sexually explicit
content" section prohibits content that is erotic, pornographic or otherwise sexually explicit and
applications that are sexually explicit, naming sexually suggestive and fetish content; usage
restriction 13 separately bans chatbots that are erotic or romantic.*

Fetched `learn.microsoft.com/en-us/legal/ai-code-of-conduct` today. **Every part checks out:**

* document history table: **version 4.0, 5/1/2026**. ✅
* the "Sexually explicit content" heading exists and prohibits content that is
  "erotic, pornographic, or otherwise sexually explicit" (Microsoft, Code of Conduct for Microsoft
  AI Services), plus applications that are sexually explicit, and it names sexually suggestive
  content, depictions of sexual activity and fetish content. ✅
* usage restriction **number 13** is exactly the chatbot one: erotic, romantic, or used for erotic or
  romantic purposes. ✅

Azure is correctly disqualified, twice over, and the citation is precise enough for the owner to
check himself.

### 3.3 Latency figure ✅

Claim: *PocketTTS, live, warm median 0.095 s for 62 chars; first call with a cold voice 0.259 s.*

Re-measured myself, live, game not running, 57-char line, same `femalenord.wav`:

```
call 1: 0.291 s  (cold)
call 2: 0.103    call 3: 0.093    call 4: 0.110
call 5: 0.099    call 6: 0.100    call 7: 0.079    call 8: 0.107   -> warm median 0.100 s
3.76 s of audio produced
```

**Within a few milliseconds of the published figure.** The synthesiser is ~37x faster than realtime
when the GPU is free; the owner's multi-second waits are GPU contention with Skyrim, exactly as the
report says. Nothing invented.

**Also confirmed, and important for E1:** `/home/dwemer/audio.cpp/server.json` still reads
`"backend": "cuda", "threads": 1` and `/health` returns `"backend":"cuda"`. **The CPU move in §5a has
not been done.**

---

## 4. DEFECTS

### Defect 1 - MEDIUM - the spawn rate-limit dies after the second deploy. **FIXED**

`lrgTtsEnsureAudiofilterd()` rate-limited itself with `@touch($marker, $future)`. But `utime()` with
an **explicit** time requires **ownership** of the file, not write permission - and
`tools\deploy_server.ps1` runs `chown -R dwemer:www-data '$Target'` over the whole plugin, `data/`
included. So the sequence is:

1. deploy #1 - `data/` has no markers;
2. first CHIM request - www-data creates them and owns them, everything works;
3. deploy #2 - both markers are chowned to `dwemer`;
4. from then on **www-data can never refresh either marker again.**

Measured on this box: with the markers owned by `dwemer`, **40 of 40 hook calls tried to start a
daemon**, writing 73 log lines. Every CHIM request would fork a detached `/bin/sh` + `php` pair and
write a log line - a process storm and a log flood, arriving exactly when the daemon is already
failing. (When the daemon is healthy the cost is only a lost fast path, ~0.1 ms per request.)

Fixed in `tts/lrg_audiofilterd_boot.php`:

* new `lrgTtsTouchAhead($file, $when)` - tries `touch()`, and on failure unlinks and recreates the
  file, which makes the caller its owner again (the directory is group-writable, so this always
  works on a deployed install). All five marker writes now go through it.
* and a guard: **if the rate-limit marker cannot be written at all, do not spawn.** A start that
  cannot be rate-limited is a start on every request; losing the feature is the cheaper failure. One
  log line says so, once per interval.

Re-measured after the fix: **1 spawn in 40 calls, 1 log line.** Verified live too - the second deploy
chowned the markers to `dwemer`, and the next web request took ownership back and restarted the
daemon cleanly.

### Defect 2 - LOW - `--status` printed the wrong pid. **FIXED**

With `fork: true` (the shipped default) a status request is answered by a forked child, which
reported its own throwaway pid. The owner is told to use `--status` to find the daemon, so the number
has to be the one `kill` would want. Now reports `$G['owner']`, with `answered_by` alongside:

```
{"pid":46557,"version":"0.5.2","socket":"...","served":4,"children":0,"answered_by":47414}
```
(`46557` is the real daemon.)

### Defect 3 - LOW - the deploy had no anchor for the one line that turns the feature on. **FIXED**

The whole auto-start is `lrgTtsEnsureAudiofilterd();` in `globals.php`. Losing it would have meant
"nothing changed" with no error anywhere - precisely the failure the anchor pre-flight exists to
catch. The build report suggested the anchor but could not add it (not its lane). Added; the deploy
now checks 29 anchors.

### Defect 4 - MEDIUM - **not fixed, needs the owner.** Fixing CHIM's filter turns off CHIM's other filters

The build report says `$FFMPEG_FILTER` is "hard-coded EMPTY on the audio.cpp path". That is not quite
true. `tts-pockettts.php:453` sets it to `''`, but **lines 461-463 then rebuild it from
`$GLOBALS["TTS_FFMPEG_FILTERS"]` regardless of which path was taken**:

```php
if (is_array($GLOBALS["TTS_FFMPEG_FILTERS"]))
    if (sizeof($GLOBALS["TTS_FFMPEG_FILTERS"])>0)
        $FFMPEG_FILTER='-af "'.implode(",",$GLOBALS["TTS_FFMPEG_FILTERS"]).'"';
```

Three code paths populate that array:

* `lib/chat_helper_functions.php:1478` - `FEATURES.MISC.TTS_RANDOM_PITCH`, a per-character
  `rubberband=pitch=…` applied to **every line that NPC speaks**;
* `lib/data_functions.php:6846/6857` - mood `drunk` → `atempo=0.65`, mood `high` → `atempo=1.45`;
* `lib/core/book_read.class.php:1010-1019` - the book-reading voice, a 10-filter audiobook chain.

Today those filters reach the wav **only because `processAudio()` always throws** and the ffmpeg
fallback runs. With our daemon answering, ffmpeg never runs and they are silently dropped. This is
CHIM's own design gap - they moved to `audiofilterd` and never ported the ffmpeg filters - but our
fix is what exposes it.

**It is inert today, and I checked rather than assumed:**

* all **138** real sidecars in `soundcache` recorded an **empty** `$FFMPEG_FILTER`, so none of these
  paths fired in any of the owner's play sessions;
* `TTS_RANDOM_PITCH` is `false` in `conf.sample.php` and in the 21 Sep backup, and
  `conf/conf_schema.json` marks it **"WIP DO NOT USE!"**;
* `book_reading_task` appears in `chim.log` only as an empty prompt section - book reading has never
  run.

So nothing is lost right now. But if the owner ever turns random pitch on, or an NPC is given the
`drunk` / `high` mood, or he uses CHIM's book reader, that effect will silently not happen while our
daemon is up. The switch is `{"tts":{"audiofilterd":{"enabled":false}}}` in
`ext/lorerim_glue/config/lrg_config.json`. I did not change anything: the trade is 0.3 s of dead air
on every line against a WIP pitch feature and two rare moods, and that is the owner's call, not mine.

### Defect 5 - LOW, informational - one line can fall back right after a deploy

By design the daemon exits when the plugin's files change, so a redeploy is picked up. But the
supervisor trusts a successful probe for `probe_interval_seconds` (10 s), so a request arriving
inside that window does **not** notice the daemon has gone and does not restart it. Observed live:
after the second deploy the first request did nothing, the one after the window restarted it. Worst
case one spoken line keeps its dead air after a deploy. Self-healing; not worth code.

### Defect 6 - LOW, factual - `pt9-tts-options.md` §1a

"26 `tts-*.php` files ship" - there are **24**. Everything the section concludes from that count is
correct; only the total is wrong.

### Not a defect, but worth writing down

**12 concurrent supervisors produce up to 7 spawn attempts and exactly 1 daemon.** The rate-limit
marker is not atomic, so a thundering herd can slip several starters through; they collide on
`stream_socket_server`, and every loser exits 0 without touching the winner's socket. Bounded,
self-correcting, and it never leaves two daemons behind. I tried to break it and could not.

---

## 5. Shipped state

```
daemon         pid 46557, v0.5.2, fork=yes, signals=yes, 1 process
socket         srw-rw---- www-data www-data /var/www/html/HerikaServer/tts/audiofilterd.sock
plugin         /var/www/html/HerikaServer/ext/lorerim_glue  (manifest still says 0.5.1)
hashes         tts/*.php and globals.php: deployed == project source, all MATCH
chim.log       'Audio processing failed for PocketTTS response' frozen at 62
game side      unchanged; the three INI files verified, installed, hash-matched
server.json    still backend=cuda threads=1 - the CPU move (E1) has NOT been done
```

**One loose end for whoever owns the manifest:** `manifest.json` still reads `"version": "0.5.1"`
while the new files carry `0.5.2` in their headers and in the daemon's own `LRG_AF_VERSION`. Nothing
reads it for behaviour, but the two should be reconciled at the next version bump.
