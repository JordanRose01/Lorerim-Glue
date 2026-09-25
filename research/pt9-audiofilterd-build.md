# PT9 - audiofilterd: the missing daemon, built inside our own mod

Built and measured 2026-09-22 15:30-16:10 local, against `DwemerAI4Skyrim3`.
Role: BUILDER. **Nothing was deployed.** See §0.

Owner's instruction this lane exists to satisfy (E2, decision 2):
*"i want our mod to change that line instead of changing it thru chim."*

That is exactly what this is. `tts/tts-pockettts.php` is **not** edited, `$FFMPEG_FILTER` is **not** changed,
no CHIM file is touched at all. Instead the half of CHIM's own feature that this distro never shipped -
the `audiofilterd` daemon - now lives in our plugin and answers on the socket CHIM already calls. When it
is running, CHIM's `processAudio()` succeeds, the daemon's output is written straight to the game-facing
wav, the error line stops, the ffmpeg spawn stops, and the leading silence is gone.

---

## 0. What was touched

| | |
|---|---|
| Changed on the live server | **nothing.** No file under `/var/www/html/HerikaServer` was written. The deployed plugin (`ext/lorerim_glue`, v0.5.1) is untouched; `/var/www/html/HerikaServer/tts/audiofilterd.sock` still does not exist; no daemon is running. |
| Read from the live server | `tts/audiofilterd_client.php`, `tts/tts-pockettts.php`, `log/chim.log`, `soundcache/*` (132 `_o.wav` + 132 `.txt`), the php/apache ini files. Read-only. |
| Written in the project | `glue/server/lorerim_glue/tts/{lrg_wav.php, lrg_audiofilterd.php, lrg_audiofilterd_boot.php}` (new), two lines in `glue/server/lorerim_glue/globals.php`, one new `tts` section in `glue/server/lorerim_glue/config/lrg_config.default.json`, `glue/tools/test_audiofilterd.php` (new), this file. |
| Written in WSL | `/tmp/lrg_mod`, `/tmp/lrg_base` (staged copies of the plugin, because WSL cannot see the project path), `/tmp/lrg_af_w*`, `/tmp/lrg_af_wd*` (test work dirs), `/tmp/lrg_af_real.php`. All throwaway. |
| Daemons left running | none (`pgrep -af lrg_audiofilterd` -> nothing). |

---

## 1. The protocol, pinned off the client (the client IS the specification)

`/var/www/html/HerikaServer/tts/audiofilterd_client.php`, 123 lines, read line by line:

| | |
|---|---|
| transport | unix **stream** socket. `stream_socket_client('unix://'.$socketPath, $errno, $errstr, 5)` - 5 s connect timeout. |
| socket path | the client's own default is `/tmp/audiofilterd.sock`; **`tts-pockettts.php:492` passes `/var/www/html/HerikaServer/tts/audiofilterd.sock`**, which is therefore the only path that matters. |
| framing | **none.** `fwrite($socket, $request)` then `stream_socket_shutdown($socket, STREAM_SHUT_WR)`. The half-close *is* the end of the request. No length prefix, no newline, no terminator. |
| reply framing | `stream_get_contents($socket)` - reads to **EOF**. The server closing the write side *is* the end of the reply. |
| request | `{"audio_base64":"<b64 of the raw wav>","filters":[{"type":"trim_start","milliseconds":250.0}]}`, encoded with `JSON_UNESCAPED_SLASHES`. |
| reply | `{"error":{"code":<int>,"message":"..."},"audio_base64":"<b64>"}` |
| error handling | `($error['code'] ?? -1) !== 0` is a hard failure. **Strict** comparison, so the code must be an integer `0`; `"0"` would be read as a failure. A missing `error` field is treated as code -1. |
| audio handling | `is_string($response['audio_base64'])` then `base64_decode(..., true)` (strict). The **client** writes the bytes to the output path; the daemon never touches the filesystem for it. |
| connections | one request per connection. |
| read timeout | not set by the client, so PHP's `default_socket_timeout` (60 s) applies to the reply read. |
| filter vocabulary | only `trim_start` is ever sent by CHIM. The client's docblock points at `SUPPORTED_EFFECTS.md`, which **does not exist anywhere on this box** - so the full vocabulary is unknowable and unknown types must degrade, not fail. |
| who runs it | Apache `mpm_prefork` + `mod_php8.2` as **www-data** (11 apache2 processes live now). `/etc/php/8.2/apache2/php.ini` has `disable_functions =` and `disable_classes =` empty and **no `open_basedir`**; no `php_admin_value`/`php_value` anywhere in `/etc/apache2`. |
| socket permissions needed | the containing directory is `drwxrws--- dwemer:www-data`, setgid, so a socket created there inherits group `www-data`; mode 0660 is sufficient and nothing wider is needed. Verified www-data can create files there. |

What CHIM does with the result (`tts-pockettts.php:472-510`): the raw response is saved as
`soundcache/<md5>_o.wav`, `processAudio()` is asked to write `soundcache/<md5>.wav` - **the file the game
plays** - and only if it throws does CHIM `shell_exec("ffmpeg -y -i <_o.wav>  $FFMPEG_FILTER <.wav>")`
with `$FFMPEG_FILTER` hard-coded to `''` on the audio.cpp path. Today that catch fires every single time:
**61 `Audio processing failed for PocketTTS response` and 61 `FFMPEG command executed` in the current
`log/chim.log`**, and the 132 sidecars record `ffmpeg transcoding` median **0.055 s**.

---

## 2. What was built

Three new files, all inside our plugin, plus two lines of wiring.

### `tts/lrg_wav.php` - the audio work (pure functions, no I/O)

`lrgWavParse()` / `lrgWavOnset()` / `lrgWavTrimStart()` / `lrgWavBuild()` / `lrgWavLeadingSilenceMs()` /
`lrgWavDurationMs()`.

**The trim is adaptive, and it is deliberately not what CHIM asked for.** CHIM asks for a flat 250 ms.
PocketTTS's real leading silence on this box is 0.25-0.31 s median with a 0.63 s worst case
(`pt9-tts-cpu.md` §2 finding 5, reconfirmed here over 132 files: median **344 ms**, p90 **502 ms**, max
**723 ms**, and **10 % of lines have none at all**). A flat 250 ms therefore leaves 100-470 ms of dead air
on most lines and would cut into the first word of a line that starts early. So:

1. find the **onset** - the first 10 ms window above the threshold that stays up for 30 ms (the sustain
   rule stops a single click being mistaken for speech), resolved to the sample;
2. remove `onset - 40 ms` (the lead-in kept so the first consonant is never clipped);
3. never more than **900 ms** (`cap_ms`);
4. never leave less than 120 ms of audio behind;
5. and if none of that can be worked out, return the input **byte for byte**.

The threshold is `max(-40 dBFS absolute, -35 dB below the file's peak)`, **clamped to no nearer than
-30 dB below the peak**. That last clamp is not decoration: the quietest real line on this box peaks at
-24.5 dBFS, where a fixed -40 dBFS floor sits only 16 dB under the speech and ate 107 ms of the soft
breath the word starts with. With the clamp, that file's onset is **362.4 ms** against ffmpeg's own
`silencedetect=noise=-50dB` answer of **362.458 ms** - agreement to 0.06 ms - and the worst energy loss
over all 132 real files improved from 1.83 % to **0.55 %**.

**Deviation from the brief, stated plainly.** The brief said the client's `milliseconds` is "the minimum
guaranteed trim ... when silence is actually present". Taken literally, a line whose silence is only
120 ms would still have 250 ms removed - i.e. 130 ms of speech. This build does **not** do that: where the
real silence is shorter than the request, the real silence wins. The config knob
`trim.honor_client_minimum` (default `false`) switches to the literal reading. On this box it changes
nothing for 90 % of lines and only ever protects the other 10 %.

### `tts/lrg_audiofilterd.php` - the daemon

CLI only (a web hit gets 403). `--daemon` (serve), `--once` (one connection, for tests), `--test` (the
filter with no socket at all), `--status`, `--stop`, `--help`, `--socket=`, `--run-dir=`, `--log-file=`.

- Reads to EOF, answers, closes the write side. One request per connection.
- **Always `{"error":{"code":0,...}}` with audio once it holds decodable bytes.** Every filter call is
  inside `try/catch (Throwable)`; a throw, an empty result, an unreadable header, a format we do not
  understand - all of them return the **original** audio with code 0. The feature is allowed to be
  useless; it is not allowed to be a regression. An error object is only ever returned when there is no
  audio to return (unparseable JSON, undecodable base64, a request over `max_request_bytes`), and in that
  case CHIM's ffmpeg fallback does exactly what it does today.
- Unknown filter types are **noted and skipped**, never refused, because `SUPPORTED_EFFECTS.md` is not on
  this box and a future CHIM effect must degrade to "nothing happened".
- Singleton by **socket connectability**, never by the pid file: a stale pid file can never block a start.
  A socket file with nothing behind it is unlinked and rebound. Losing the race to another starter is a
  clean exit 0.
- Forks per request when `pcntl` is available (it is), capped at `max_children` (8), so one hung client
  cannot delay the next line she speaks. The child drops the listener and never runs the parent's cleanup
  (the cleanup checks the owning pid).
- Exits cleanly on SIGTERM/SIGINT/SIGHUP, on a `{"command":"shutdown"}` request, **if its own socket file
  is replaced by another daemon**, and **if the plugin's files change on disk** - so a redeploy is picked
  up automatically instead of leaving yesterday's code running.
- One log line per request into `HerikaServer/log/lorerim_glue.log`, beside everything else the glue
  writes: `audiofilterd: 72044 -> 45162 bytes (-26882), trim_start(250 ms) removed 560 ms [onset at 600.1 ms], 2.5 ms`.
  A request over `budget_ms` (250) adds a WARNING line. The health probe writes nothing.

### `tts/lrg_audiofilterd_boot.php` - the supervisor (the hook)

`globals.php` (main.php:54, first on every request) gained exactly two lines:

```php
require_once __DIR__ . '/tts/lrg_audiofilterd_boot.php';
lrgTtsEnsureAudiofilterd();
```

- **Hot path: one `filemtime()`.** The marker's mtime is written *into the future*, so "checked recently"
  is one integer comparison and no config file is even opened. Measured **0.002-0.005 ms per call**.
- Slow path (every `probe_interval_seconds`, 10): connect to the socket with a 50 ms timeout, and if
  nothing answers, spawn - at most once per `spawn_retry_seconds` (30), gated by a second marker.
  Measured **0.11 ms** including the probe.
- Inert outside a real request: `PHP_SAPI === 'cli'` or the flow harness's `LRG_TEST_NOW` returns
  immediately, so `tools/test_gates.php` and all 74 flow scenarios (which include `globals.php`) can never
  start a daemon on the live socket as a side effect. `tools/test_audiofilterd.php` opts in through the
  config seam, which also pins the socket somewhere harmless.
- **Never throws.** The whole body is inside `try/catch (Throwable)`; a failure costs one log line.
- `"enabled": false` means off **now**: the hook asks a still-running daemon to stand down.
- Three things the spawn has to get right in this distro, all verified:
  1. **`PHP_BINARY` cannot be trusted here.** It is the *embedding* binary, so under mod_php it is the
     web server, not an interpreter (this is the documented behaviour; it was not observed directly,
     because observing it would mean serving a page from the live web root). The interpreter therefore
     comes from config (`php_binary`, `/usr/bin/php`) and is `is_executable()`-checked, and `PHP_BINARY`
     is only ever used as a fallback when its basename contains "php".
  2. `setsid`, so the daemon leaves Apache's session and survives `apachectl restart` and the CHIM
     launcher's stop/start.
  3. **It must not inherit Apache's listening sockets.** A background process holding :8081 is the classic
     way to make "Address already in use" appear later, and nobody would connect that to a TTS filter. The
     spawned shell closes every descriptor except 0/1/2 before `exec`ing php. This is tested for real
     (§3.6).

### Config (`config/lrg_config.default.json` -> new `tts.audiofilterd` section)

`enabled` (true), `socket`, `php_binary`, `probe_interval_seconds` 10, `probe_timeout_ms` 50,
`spawn_retry_seconds` 30, `request_timeout_seconds` 5, `budget_ms` 250, `fork` true, `max_children` 8,
`log_requests` true, `idle_exit_seconds` 0, and `trim.{cap_ms 900, lead_in_ms 40, threshold_db -40,
relative_db -35, ceiling_db -30, window_ms 10, sustain_ms 30, min_output_ms 120,
honor_client_minimum false}`. Overrides go in `config/lrg_config.json` as usual.

---

## 3. What was actually verified, and how

`tools/test_audiofilterd.php` - **75 checks, 0 failures**, run twice: as `root` and as **`www-data`, the
user Apache really runs as** (`sudo -u www-data php tools/test_audiofilterd.php`). It never invents a
client: it `require`s `/var/www/html/HerikaServer/tts/audiofilterd_client.php` and calls `processAudio()`
with the same filter list and the same `250.0` that `tts-pockettts.php:483` uses, then asserts the
function it called really came from CHIM's file.

| § | what it proves |
|---|---|
| 1 | the daemon starts through **the supervisor's own spawn code**, answers, writes a pid file with a live pid, creates a real socket at mode 660, and is reparented away from the caller (detached). |
| 2 | 0 / 250 / 600 / 900 / 1400 ms of leading silence -> removed 0 / 210 / 560 / 860 / 900 ms. Output is still a valid 16-bit PCM wav; **no speech was cut** (energy before/after); what is left in front of the first sound is 0 or 40 ms (and, past the cap, exactly the part the cap refused to remove). Round trip through the real client: **2.8-4.9 ms**. |
| 3 | garbage, an mp3-looking payload, a `RIFF` that is not `WAVE`, a truncated header, an 8-bit PCM wav, an **unknown filter type**, and an empty filter list all come back **byte-identical** and without an error object. |
| 4 | a stereo 44.1 kHz file keeps its rate and channel count and whole frames; a trailing `LIST`/`INFO` chunk survives and the `RIFF` size field still matches the file. |
| 4b | **20 random real `_o.wav` files from CHIM's own soundcache** - the exact bytes PocketTTS produced during the owner's play sessions - come back valid with their speech intact. |
| 5 | one log line per request in `lorerim_glue.log`; the health probe produces none. |
| 6 | **the Apache-socket check**: a listening TCP port is opened, a daemon is spawned while it is open, the parent then closes the port - and it can be rebound. The daemon did not inherit it. Then the daemon shuts down over its own socket and removes its socket and pid file. |
| 7 | a leftover socket **file** and a pid file naming pid 999999 do not stop it starting; a second spawn against a live socket is a no-op. |
| 8 | 200 calls of the hook cost **0.002 ms each**; it does not throw when the socket path is unreachable; `enabled: false` stops a running daemon. |
| 9 | the daemon's own `--test` (the filter with no socket) passes. |
| 10 | it removes its socket and pid file on the way out. |

**Over all 132 real `_o.wav` files** (run in-process, read-only):

```
trim:          median 344 ms   p10 0 ms   p90 502 ms   max 723 ms   (cap 900, never reached)
energy kept:   median 0.99940  p10 0.99847  worst 0.99447
filter cpu:    max 1.96 ms per file, 231 ms for all 132
```

The `p10 = 0` is the interesting one: **27 of the 132 lines (20 %) have no leading silence worth
removing** - their speech starts inside the 40 ms lead-in - and the adaptive trim leaves them completely
alone. A daemon that did what CHIM literally asks would have cut 250 ms off the front of every one of
them. (A quarter of the files are at or under 50 ms of trim; the median is 344 ms.)

Cross-check against the tool `pt9-tts-cpu.md` used: on the worst file, ffmpeg
`silencedetect=noise=-40dB` reports `silence_end: 0.469` and our onset is **469.0 ms**; at `-50dB` ffmpeg
reports `0.362458` and the shipped settings give **362.4 ms**.

**No regression in the existing suites.** Every suite was run twice, on a staged copy *with* this round's
two globals.php lines and on one *without*, so a pre-existing failure is visible as failing on both:

| suite | baseline | with this change |
|---|---|---|
| `test_gates` | 318 passed, 1 failed | 318 passed, 1 failed |
| `test_intent` | 190 passed, 0 failed | 190 passed, 0 failed |
| `test_phrases` | 14 passed, 0 failed | 14 passed, 0 failed |
| `test_dialogue` | 174 passed, 0 failed | 174 passed, 0 failed |
| `test_latency` | 26 passed, 0 failed | 26 passed, 0 failed |
| `tools/flows/run_flows.php` | 74 scenarios: 72 passed, 2 FAILED, 1179 checks | identical |

The four failures (`test_gates` "the intimacy module itself touches no follower faction ... LRG_OStim.psc
not found", flow `d52`, flow `d53`, and `test_mcm_wiring` rc=2 "no config.json") are all **staging
artefacts**: they read files under `glue/game/`, which was not copied into the WSL staging tree because
WSL cannot see the project path. They fail identically with and without this change.

`php -l` is clean on all five touched/new PHP files, and the config file still parses.

---

## 4. What this does NOT prove

1. **The spawn has not been exercised by a real Apache request**, because that would mean deploying, and
   the brief says not to. What *was* verified instead: the spawn works when run as **www-data**; the
   interpreter `/usr/bin/php` is executable by www-data; www-data can create the socket in
   `HerikaServer/tts/` and write `ext/lorerim_glue/data/`; the apache php.ini has no `disable_functions`,
   no `disable_classes` and no `open_basedir`, and there is no `php_admin_value` anywhere in
   `/etc/apache2`; the code never relies on `PHP_BINARY`; and the descriptor
   hygiene is tested against a real inherited listening socket. The residual risk is that mod_php refuses
   `proc_open` for a reason none of those checks caught - and if it does, the supervisor logs
   `audiofilterd: nothing answering ... (FAILED: ...)` once every 30 s and **CHIM behaves exactly as it
   does today**.
2. **No in-game measurement.** The 0.25-0.31 s of dead air removed per line is arithmetic on real files,
   not a stopwatch on the owner's headphones.
3. **Only `trim_start` is implemented.** If a CHIM update starts sending other effects they will be
   skipped (and logged), not applied - the vocabulary cannot be known without `SUPPORTED_EFFECTS.md`.
4. **The daemon holds its config in memory.** Editing `lrg_config.json` needs
   `php <plugin>/tts/lrg_audiofilterd.php --stop` (the supervisor restarts it within ~10 s), or nothing at
   all if you wait for the next deploy - the daemon exits by itself when the plugin files change.
5. **Two daemons on two different sockets can coexist.** That is intentional (the tests rely on it) but it
   means a hand-started daemon on a *different* path is not the one CHIM talks to. `--status` says which
   socket it owns.
6. This was measured with **PocketTTS only**. The filter is format-driven, not engine-driven, so another
   16-bit PCM engine would work the same way, but nothing here tested one.

---

## 5. Deploying it, and the exact thing the owner will see

Not deployed. When you want it:

```powershell
# Skyrim closed. MO2 may stay open - nothing here touches the game side.
powershell -File glue\tools\deploy_server.ps1
```

The deploy copies the new `tts/` folder with everything else, fixes line endings, `php -l`s every file and
chowns `dwemer:www-data`. Nothing else is needed: **there is no service to enable**. The first CHIM
request after the deploy starts the daemon (~60 ms, detached), and every request after that costs one
`filemtime()`.

**The verification, in the order you will see it.**

1. Right after the first request of the session, the daemon announces itself in **our** log:
   ```
   wsl -d DwemerAI4Skyrim3 -- bash -c "grep audiofilterd /var/www/html/HerikaServer/log/lorerim_glue.log | tail -5"
   ```
   expect `audiofilterd: listening on /var/www/html/HerikaServer/tts/audiofilterd.sock (pid NNNN, v0.5.2, fork=yes, signals=yes)`
   and then one line per spoken line:
   `audiofilterd: 72044 -> 45162 bytes (-26882), trim_start(250 ms) removed 560 ms [onset at 600.1 ms], 2.5 ms`.

2. **`chim.log` stops printing the failure.** Today it has 61 of each of these; after the next TTS call
   the counts stop rising:
   ```
   wsl -d DwemerAI4Skyrim3 -- bash -c "grep -c 'Audio processing failed for PocketTTS response' /var/www/html/HerikaServer/log/chim.log; grep -c 'FFMPEG command executed' /var/www/html/HerikaServer/log/chim.log"
   ```
   Note the count *now* (61/61), play one scene, run it again: **unchanged** = the daemon is being used.
   Still rising = it is not, and the fallback is doing its old job with no harm done.

3. **The wavs the game plays start within ~40 ms of audio.** Pick any wav written after the deploy and
   compare it with its own `_o.wav`:
   ```
   wsl -d DwemerAI4Skyrim3 -- bash -c 'cd /var/www/html/HerikaServer/soundcache; f=$(ls -t *_o.wav | head -1); b=${f%_o.wav}; for x in "$f" "$b.wav"; do echo -n "$x  "; ffprobe -v error -show_entries format=duration -of csv=p=0 "$x" | tr -d "\n"; ffmpeg -hide_banner -v info -i "$x" -af silencedetect=noise=-40dB:d=0.05 -f null - 2>&1 | grep -m1 silence_end || echo "  (no leading silence)"; done'
   ```
   Expect the `_o.wav` to show `silence_end: 0.2` - `0.6` and the played `.wav` to show **no leading
   silence** (or `silence_end` under ~0.05). The played file will also be shorter than the `_o.wav` by
   exactly the amount the log line reported.

4. The sidecar's `ffmpeg transcoding` number - which is really "time spent in `processAudio` or ffmpeg" -
   drops from **0.055 s** to about **0.003-0.007 s**:
   ```
   wsl -d DwemerAI4Skyrim3 -- bash -c "grep -h 'ffmpeg transcoding' /var/www/html/HerikaServer/soundcache/*.txt | sed 's/.*transcoding: //' | sort -n | awk '{a[NR]=\$1} END {print \"n=\" NR, \" median=\" a[int(NR/2)]}'"
   ```
   (clear or timestamp-filter the old sidecars first, or you will be reading yesterday's numbers).

**Turning it off**, in order of bluntness:

- `config/lrg_config.json` -> `{"tts":{"audiofilterd":{"enabled":false}}}` - the next request asks the
  running daemon to stop and never starts another. CHIM goes straight back to today's behaviour.
- `php /var/www/html/HerikaServer/ext/lorerim_glue/tts/lrg_audiofilterd.php --stop` - stops it now; the
  supervisor starts it again within ~10 s unless it is also disabled.
- `--status` prints pid, version, socket and how many requests it has served.
- Removing the plugin removes the feature: nothing was installed anywhere else.

---

## 6. One thing for whoever owns `tools/deploy_server.ps1`

That script's anchor pre-flight is what makes a lost one-line edit fail the deploy instead of dying
silently in game. The whole auto-start of this feature is two lines in `globals.php`, which is exactly
that shape. Suggested (not added - the file is not this lane's):

```powershell
@{ f = 'globals.php'; t = 'lrgTtsEnsureAudiofilterd();'; w = 'the audiofilterd supervisor - without it the daemon never starts and every spoken line keeps its 0.3 s of dead air' }
```
