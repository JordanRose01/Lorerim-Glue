# pt14 ship: LoreRim Glue server 0.5.5 + game script 507 ("never silent")

Verifier + release, 2026-09-23 (~03:35-04:00 local). Owner addendum 11, verbatim: "if she's busy she should say
that then and it will be fine, she never told me she was busy, she just didn't do anything."

I read both lane reports (`pt14-never-silent-server.md`, `pt14-never-silent-game.md`). I checked them against the
real code and against CHIM's own funcret handling in the installed HerikaServer (read-only): `processor/funcret.php`,
`chimLogFuncretResultInfoAction`, `processor/request.php`, `lib/core/action_catalog.php`, `main.php` and the
`lib/data_functions.php` post-filter.

**The two halves did not fit together.** The two lanes were built in parallel:
* The server lane classified every result by its `Error:` text.
* The game lane changed that text to her own words. It moved the technical reason into a new `err=` key and added
  `OK:` and `late=1`.

Shipped as they were, an "adults only" refusal and a dry run would have been SPOKEN. The early scene close on
"no scene is running" would have been lost. A late escort failure would have been told twice. I fixed these on the
server side (section 1). The game script is as the game lane built it. I recompiled it.

**The server is deployed and the game files are installed.** Both were checked with SHA-256.

---

## 0. Status in one screen

| item | result |
|---|---|
| `tools\compile.ps1` | `OK`: 10 scripts, 0 errors, 0 warnings (21 auto-stubs, compile-only). Longest docstring is 290 characters (all under 300) |
| `php -l` | 82 files, 0 errors (staged). On the server, 17 deployed files, 0 errors |
| tests | test_gates **385**/0 (the lane had 377) · test_intent 255/0 · test_phrases 31/0 · test_scene_index ALL PASSED · test_dialogue 174/0 · test_prompt_index 67/0 · test_mcm_wiring 3/0 · test_services 35/0 · test_latency 30/0 |
| flows `--strict` | **78 scenarios, 0 failed, 0 pending, 1314 checks, 0 warnings. RESULT: OK.** This includes the new `30_wire_507` (28 checks) |
| mutation check | Flow 30 run against the server lane's code without my integration: **14 of 28 checks fail**. The flow catches the integration defects listed in section 1 |
| server | **0.5.5 deployed** to `/var/www/html/HerikaServer/ext/lorerim_glue`. Anchor pre-flight passed (**33 anchors**, 4 of them new, one match each). Scene index rebuilt (607). Prompt index reloaded (37,561 rows, source=db). All 25 deployed php/json/sql files match the source. The owner's `config/lrg_config.json` was not touched (`00b216906e6fcb2d` before and after) |
| CHIM catalog (real DB, read-only check) | The glue re-installed its rows as **v11** on the first request after the deploy (`action catalog: rows installed (v11)`, 03:53:48). Every glue row has the follow-up ON, a 160-character rule prompt, `use_functions_again` false and `suppress_placeholder_infoaction` true. **`ExtCmdLRG_Escort` is `is_activated` false and not available to NPCs.** `SelectTopic` is unchanged (follow-up off). I also passed the rows through CHIM's own `herikaActionCatalogGetResolvedFollowupConfig`: `enabled: true` for all six |
| game | **script 507 installed** into `F:\Modlists\LoreRim\mods\LoreRim Glue`: 10 `.pex`, 10 `.psc`, `config.json`, `settings.ini`. **22 files, 0 hash mismatches.** No stray `.pex`. No `LRG*` file in MO2's `overwrite`. The ESP (`82A20DB39DA4DBF4`) is unchanged. SkyrimSE was not running. MO2 (pid 41132) was open. No profile file was touched |
| backups | Installed 506 files: `%TEMP%\lrg_v507\installed506\` (22 files). Deployed 0.5.4 server: `%TEMP%\lrg_v507\deployed054.tgz`. Both lanes' sources before this pass: `%TEMP%\lrg_v507\bak_lanes\` |

Installed hashes (SHA-256, first 16 hex; the full hashes were compared):

| file | hash |
|---|---|
| `LRG_Main.pex` | `1A34059452F07300` |
| `LRG_OStim.pex` | `2AEC72163E332330` |
| `LRG_Dialogue.pex` | `3C6D583414437BE5` |
| `LRG_Profile.pex` | `6886F68CA593D78A` |
| `LRG_Followers.pex` | `289692FB634BC473` |
| `LRG.pex` | `C5101370C7C82241` |
| `LRG_DlgProbe.pex` | `5801941987F665F0` |
| `LRG_DlgUI.pex` | `5132BA818C497AF7` |
| `LRG_MCM.pex` | `63DC9A19B95CDA30` |
| `LRG_PlayerAlias.pex` | `E2D4B003D809894A` |
| `LRG_Main.psc` | `1E81E1E56EF19AA5` |
| `LRG_OStim.psc` | `3427B781AD215DA5` |
| `LRG_Dialogue.psc` | `561934784015E2DD` |
| `config.json` | `F3D03D4E30FD4C61` |
| `settings.ini` | `330718AA09BA0E78` |

The `.psc` hashes are the game lane's. The `.pex` hashes differ from the game lane's table because this pass
recompiled the scripts.

---

## 1. Defects found and fixed in this pass (all server side; the game script is as the lane built it)

The server lane stated "nothing on the wire changed". The game lane changed the result text in the same round:
* `Error: <her words>`, with the technical reason moved to `err=`;
* `OK: ...` for a success;
* `late=1` for a command that came undone.

Every match the server makes on a reason therefore stopped working against script 507:

| # | defect (507 against the lane's server) | effect in game | fix |
|---|---|---|---|
| 1 | `lrgVoiceSkip` compared `adults only` / `the feature is switched off` / `dry-run` with her words (`no, I will not do that`, `I cannot do that right now`) | **An "adults only" refusal and a dry run were VOICED.** The hard rail says no line about intimacy is ever asked for there | `lrgRecordResult` sets `reason` = **`err=`** when present, else the `Error:` text (scripts <= 506). Her words go into a separate `say`. Every server match stays on `reason` |
| 2 | The G4 early close matched `no scene is running` against her words | The scene row was not closed at once, so the next turn could still be built as an in-scene turn | same fix |
| 3 | The escort's late failure arrives twice: as the `late=1` funcret (voiced at once) and as the game's `lrg_log` line (`lrgNoteEscortLate` then `missed`) | She says why, and **then she is told the same thing again on her next turn** | A voiced late escort result clears the `missed` note. A log line that arrives after it leaves none. When the late result was NOT voiced (inside the 8 s gap), the next turn carries the `missed` note only: the "what she last tried did not happen" note steps aside for the same code |
| 4 | A late StartIntimacy failure (the `after=` position cannot follow) was described as "nothing began between X and Y" | Untrue: the scene had begun | `lrgVoicedWhat`: late start = "the change of position did not happen" |
| 5 | The cue quoted her first-person words inside a third-person fact ("... did not come off - I have nothing left to take off"). Technical texts could reach the cue raw. With 507 they are always raw, and some are also raw from 506: "OStim's 'Remove Weapons at Start' is on and would crash the game - turn it off in OStim's MCM" and "... cannot follow you: CHIM's follow could not be put on (AIAgent.esp forms not found)" | She could have been asked to explain OStim's MCM | `lrgVoicedWhy` works from the technical reason and gains four rules. (a) The escort's late reason becomes "keeps being pulled back into it" / "a performance", never the quest EditorID. (b) `unreachable` becomes the closed-list sentence. (c) A clothing command that changed nothing (`not possible in this position`) becomes "there was nothing left to take off" / "nothing to put back on". (d) Any reason naming OStim, CHIM, the MCM, the glue, a script, plugin or package, a dry run, `.esp` or `cannot follow you:` becomes "it just would not work right now". Her own words are never put into a cue (the "no example lines" rail) |

Smaller changes:
* **Her next-turn "did not happen (...)" note** (a failure that was not voiced) uses her words when the game sent
  them, as the game lane intended.
* **Log lines:** `result <Code> (late) ... [why: <err>]` and `voiced <Code> (late)`.
* **`deploy_server.ps1`** has the four anchors the server lane suggested, plus one for the `err=` reader.
* **`PROTOCOL.md`:**
  * the header now states the one additive game-to-server shape;
  * **1.6** defines `OK:` / `Error: <her words>` / `err=` / `late=1`;
  * the section 2 escort row and 10.20 section 4 cover the 507 behaviour;
  * the new **10.21 section 9** describes how the server reads it;
  * the section 9 log line is updated.
* **`README.md`** version markers are now 0.5.5 / actions 11 / 507.
* **Tests:** `test_gates` 35 (1b) and (1c) (+8 checks), one exact-array assertion updated, and the new flow
  **`30_wire_507`**, which covers every row of the table above end to end through the real hook files.

Nothing in the Papyrus needed fixing. `LRG_Main.SendFuncret` is the only funcret writer and the only
`commandEndedForActor` in the sources. A grep of every `ReportResult` / `ReportOnce` call shows each one is
literally `"OK: ..."` or `"Error: ..."`, except two variables. I traced both (`parkedWhy` and
`LRG_Dialogue.ReportOnce(asResult)`), and each is only ever an `"Error: ..."` or `"OK: ..."` literal.

---

## 2. The six traces, against the real code and CHIM's funcret handling

How CHIM voices a funcret (installed source, read-only):
1. `main.php:193` runs our `preprocessing.php` BEFORE the MAIN lock.
2. `lrgFuncretVerdict` decides: `handled` means `terminate()`, with no LLM and no TTS.
3. For `pass`: `processor/request.php` (main.php:1708) takes `$PROMPTS['afterfunc']['cue'][<code>]` as the last
   user line. Our `prompts.php` sets it only for a voiced result, for the NPC it belongs to.
4. `context_pre.php` (main.php:2540) runs `lrgVoicedGate`: wrong character, or a row without a follow-up, means
   `terminate()` and she is told on her next turn.
5. `processor/funcret.php` (main.php:2657) reads the row's follow-up. Enabled and a prompt give ONE LLM turn. With
   `use_functions_again` false it sets `FUNCTIONS_ARE_ENABLED = false`.
6. `lib/data_functions.php:6029` runs action post-processing only while `FUNCTIONS_ARE_ENABLED`. So the voiced
   turn can emit no command at all, and therefore no new funcret.

The texts below come from the deployed code, through the flow harness (`zz_sample`, staging only).

### (a) "follow me" to a bard in a quest-critical scene: refused, and she SAYS why (one turn)

1. **Player turn.** Type `inputtext`. Mode `closed` (reasons `quest_scene, witnesses, not_close_enough`), intent
   `escort / follow`. The directive: "Do it now: choose Follow_<player> in this same reply ... answer in one short
   line - never ignore it. The game takes Lisette away from what she is busy with first - Lisette may say she needs
   a moment. Do not make up a reason to stay: if the game cannot take Lisette away, Lisette is told why and says so
   then."
2. **Wire.** `Lisette|command|ExtCmdLRG_Escort@ok=1;cid=..;npc=Lisette;do=follow;safe=BardSongs,BardSongsInstrumental,*Idle*,*Sandbox*,WI*`,
   then CHIM's own `Lisette|command|FollowPlayer@`.
3. **Game (507).** The scene's quest has an objective in the journal, so the scene is left alone:
   `command@ExtCmdLRG_Escort@ok=1;...;err=Lisette is in the middle of something she cannot leave (a quest in your journal)@Error: Lisette is in the middle of something for your quest and cannot leave`.
   The corner note shows the technical reason, as in 506.
4. **Server (pre-lock).** The result is recorded (`reason` = the `err=` text). `lrgVoiceSkip` returns '' (source
   `speech`, no gap). The result is **VOICED**: `told=true`, `voiced_at`, and field 2 and 3 are rewritten to
   `command@ExtCmdLRG_Escort@follow@Error: Lisette could not come along with Jordan - she is in the middle of something important that she cannot walk away from`.
5. **CHIM's funcret turn.** Request type **`funcret`** (the same request). Glue mode `voiced`: no glue action,
   nothing injected, 140-token cap, CHIM's refusal filter off. Last user line = CHIM's row prompt + our cue:
   "What just happened: Lisette could not come along with Jordan - she is in the middle of something important that
   she cannot walk away from. Lisette says so now, in ONE short line in Lisette's own voice - the real reason, in
   plain words, never a word about the game, commands or errors. Lisette does not pretend it happened, does not
   promise it, and makes no speech of it."
6. **No loop.** `FUNCTIONS_ARE_ENABLED` is false, so she emits no command. CHIM's own `FollowPlayer` row has its
   follow-up off (DB: `{"enabled": false}`), so that result stays silent. **Exactly one extra LLM + TTS turn.**

If the scene CAN be left (a bard's BardSongs performance), the game stops it and answers `OK: ... stops what she was
doing and comes with you.`. That is quiet: she has already answered on the request turn and may have said "one
moment". If the song restarts twice, the late `Error:` (`late=1`) is voiced once: "... keeps being pulled back into
it".

### (b) "get naked" inside a scene, and the clothing command fails: voiced

The directive (scene mode) says: "Do it now: choose ChangeClothing with item "undress" ... never ignore it. Do not
refuse, stall, negotiate". That is owner addendum 3: inside a scene, only the GAME can say no.

The wire is `ExtCmdLRG_Clothing@ok=1;cid=..;npc=Brenna;do=undress;who=npc;part=all;hold=150`. The game answers
`...;err=not possible in this position@Error: I have nothing left to take off`, and the result is VOICED with the
cue "What just happened: Brenna's clothes did not come off - there was nothing left to take off. ...". A refusal
such as `someone is watching` becomes "somebody is watching". An `adults only` refusal is **never** voiced.

### (c) "faster": quiet, as before

The wire is `do=faster;hold=150` and the game answers `OK: The pace changes.`. The server returns `handled` before
the lock: no cue, no LLM, no TTS. This is cheaper than 0.5.4, which waited for the MAIN lock and a full prompt
build before ending it.

### (d) A closed-mode request ("kiss me" with witnesses): she gives the human reason

This path has no funcret: nothing is emitted. Mode `closed` (`witnesses, not_close_enough`):

> Intimacy is not possible right now because other people are close enough to see or hear, and they do not know or
> trust the player nearly well enough. ... <player_request>The player just asked for something physical. It is not
> happening right now, for the reason above: Hroda says so in one short line, with that reason in Hroda's own words
> - never ignoring it - and chooses no action.</player_request>

If she is willing but the room is watching (mode `public`): "Not here - other people are close enough to see or
hear: Hroda says so in one short line, with that reason in Hroda's own words, and may name somewhere private
instead. Never ignore it."

### (e) "silent: no fresh snapshot": a voiced human reason

The game has not reported on her within 90 s. Mode `silent`, nothing is offered, and the log line reads
`silent: no fresh snapshot (age=..) ... she answers in words (the player asked for ...)`. The injected text:

> <player_request>The player just asked for something physical. Nothing can be set up or carried out on this turn:
> Hroda says so in one short line, in Hroda's own voice, with a plain human reason (not right now, not here) - never
> a word about the game or missing facts, never ignoring it, and never acting as though it were happening.</player_request>

### (f) No funcret is voiced twice, and none bounces

* **One voiced turn per failure.** `told=true` means the next turn does not repeat it. A second failure for the
  same NPC within `voice.min_gap_seconds` (8) is told on her next turn instead (for example, a compound request
  that fails twice speaks once). The late escort failure is spoken once in either delivery order (flow 30, three
  cases).
* **No bounce.** The voiced turn cannot act. `use_functions_again` false makes CHIM switch actions off, the
  post-filter closures only run while actions are on, and the escort and hold carrier only ever go out on
  player-speech turns. A voiced turn therefore produces no command, so no funcret.
* **No wrong mouth.** `lrgVoicedGate` ends the request when CHIM routes it to another character, and when the row
  has no follow-up yet.
* **Successes and silent results never reach CHIM's funcret turn.** Every one is ended pre-lock. That includes the
  kill switch, SHARMAT, a missing `npc=`, and her own scene-lead moves.

---

## 3. The wire, three ways

| | shape |
|---|---|
| Papyrus (`LRG_Main.SendFuncret`, the only writer) | `command@<Code>@<param echoed>[;late=1][;err=<CleanForWire(tech), 160>]@OK: <neutral>` or `...@Error: <her words>`. `commandEndedForActor` is skipped only on `late=1`. The corner note uses the technical reason, unchanged from 506 |
| PHP (`lrgRecordResult` then `lrgFuncretVerdict`) | `ok` = the result does not start `Error:`. `reason` = `err` ?? the `Error:` text. `say` = her words (507). `late` = `late=1`. All matches are on `reason`. The escort's command param is unchanged (`ok=1;cid;npc;do;safe`, key order fixed) |
| PROTOCOL.md | 1.6 (markers, `err=`, `late=1`, "every machine match uses `err=`"), section 2 escort row, 10.20 section 4 (507 note), 10.21 section 9 (server reading), section 9 (log lines) |

Every `ReportResult` / `ReportOnce` site is `OK:` or `Error:`. The server never sends `err=` or `late=`.

Mixed versions:
* **Script 507 with a 0.5.4 server:** works. It only loses the early close on "no scene is running".
* **Script 506 with a 0.5.5 server:** works as the server lane built it.

Both halves shipped together.

## 4. Latency: added only on failures

* **Success:** the verdict takes 3.5 ms and ends before the MAIN lock. In 0.5.4 the same result waited for the lock
  and a prompt build (pt9 measured a 255 ms median and an 8.2 s worst case).
* **Voiced failure:** the verdict takes 5.0 ms (test_latency section 6). After it comes ONE LLM + TTS turn, CHIM's
  own funcret turn, capped at 140 tokens: about 1.5 s to the first sentence and about 7-8 s held under MAIN (pt9
  numbers). This replaces a silence.
* **The glue's own cost:** no new LLM call. The `lrg_log` reader adds one memory read, only for the escort
  give-up line.

---

## 5. Owner steps

**Nothing to install.** The server is live and the game files are in the mod folder under their existing names, so
MO2 needs no refresh. Start the game from MO2 as usual.

1. On load, the corner should say **`LoreRim Glue v507 loaded - session NNNN`**. If it says v506, stop and send
   `Papyrus.0.log`.
2. **Do not edit the glue rows in CHIM's web Action Editor.** They were re-installed as v11: this resets any earlier
   edit to them. A new row **`GlueEscort` (`ExtCmdLRG_Escort`) is there and switched OFF**. Leave it off. It exists
   only so that her "I can't come" can be spoken. It is never offered to the AI, even if it is switched on.
3. `MakeFollower` and `Follow` are already off in CHIM's catalog (checked in the DB). Nothing else to change.
4. Try a few requests in game (below), and check the log lines.

### What you will now hear when something cannot be done

She answers **in the same exchange, in one short line, in her own voice, with the real reason**, right after the
request. The corner note still appears while `bNotifyErrors` is on. The AI chooses the exact words; the lines
below are only examples.

| you ask | what the game finds | what you now hear (example) |
|---|---|---|
| "follow me" to Lisette while her scene belongs to a quest in your journal | may not be interrupted | first "alright, one moment", then right after: "I can't leave right now - this is important." |
| "follow me" to a bard mid-song | the song can be stopped | she stops playing and comes; nothing extra is said |
| ... and her song keeps restarting | the game gives up after two tries | "Sorry, I have to get back to my performance." |
| "follow me" to a hostile NPC, in a fight, or during an arrest | refused | a line with that reason ("not in the middle of a fight") |
| "wait here" to a recruited (framework) follower | no escort is sent: the server's directive says her own follower dialogue carries it | one line in her voice, never nothing |
| "get naked" in a scene when nothing is left to take off | nothing changed | "There's nothing left to take off." |
| undress outside a scene with someone watching | refused | "Not here - someone could see." |
| a position that cannot be reached (warp off) | refused | "I can't do that from here." |
| a paid start you cannot afford | refused at the start | she remarks that you can't pay |
| a position asked together with the start that cannot follow | refused a few seconds after the start | "We can't do that one from here." |
| "kiss me" in a crowded room | nobody has done anything yet | "Not here, with everyone watching." (or she names somewhere private, if she is willing) |
| anything while the game has not reported on her | nothing can be set up | "Not right now." with a plain human reason |
| "faster", "slower", "stop", a position that works | done | nothing extra: successes stay quiet |

**Still quiet by design** (told to her on her next turn instead, so she does not act as if it had happened):
* her own scene-lead moves that fail;
* the hold carrier;
* dry runs;
* "adults only" and the in-game feature switch;
* a second failure within 8 s;
* CHIM's own `ComeCloser` / `MoveTo` / `TravelTo`, and `FollowPlayer` without the escort. The next snapshots judge
  these. If one failed, she is told on your next line and says why if you ask.
* menuless-questing (`SelectTopic`) results, which have their own path.

### Log lines to look at

**`lorerim_glue.log`** (HerikaServer `log` folder):
* `result ExtCmdLRG_Escort do=follow npc=Lisette -> Error: Lisette is in the middle of ... [why: ...]`
* `voiced Escort do=follow npc=Lisette src=speech reason="..." -> her funcret turn says why`: she was asked to
  say it.
* `voiced: no (<why>) - ... is told on her next turn`: a failure that was kept quiet on purpose.
* `voiced: dropped - <why>; told on her next turn instead`: CHIM routed it elsewhere.
* `result ... (late)` / `voiced Escort (late)`: the song came back.
* `missed: escort npc=... - already said in her funcret turn (late=1)`: this line is normal.

**`Papyrus.0.log`:**
* `result <Code>: OK: ...` for successes.
* `result <Code>: Error: <her words> [why: <technical>]` for refusals.
* `result ExtCmdLRG_Escort (late): Error: ...` when the song came back.
* `unmarked result of ... sent as a success` must never appear.

### If she is still silent after a request, send

1. **The time, your exact words**, and what the corner note showed.
2. **`lorerim_glue.log`** for those minutes: look for `voiced` / `result` near the time.
3. **`Papyrus.0.log`**. Copy it **before** you start the game again: the next launch rotates it.
4. CHIM's `output_to_plugin.log` if it is at hand.

### Rollback (only if needed)

* **Server:** restore `%TEMP%\lrg_v507\deployed054.tgz` into `ext/`.
* **Game:** copy `%TEMP%\lrg_v507\installed506\*` back into the mod folder. MO2 can stay open; the game must be
  closed.
* **Quick switch:** `voice.enabled: false` in `config/lrg_config.json` returns to "told on her next turn".

---

## 6. Residuals (accepted, not defects)

* **Two lines on a refused "follow me".** She first agrees on the request turn, as the directive allows ("one
  moment"), then says why in the funcret turn. The server cannot know the game's verdict in advance.
* **CHIM's own follow can linger.** It stays on after the escort is refused, as in 506, because CHIM's
  `stayAtPlace` ran. When her quest scene ends she may start following. "wait here" or "you can go" ends it.
* **CHIM's own movement is not spoken at once.** A visible failure of `ComeCloser` / `MoveTo` / `TravelTo` is told
  on her next player turn, not spoken immediately. No request exists to attach a spoken turn to without changing
  CHIM's own rows. Its `FollowPlayer` row has the follow-up off, and that is the owner's CHIM configuration.
* **An `after=` that gives up only on its timeout** is still only logged. The player usually changed course.
* **A late escort failure that is NOT voiced** (inside the 8 s gap) is told on her next turn. The note comes from
  the game's log line (`bDebugLog:General`, on by default) or, in the modes that carry it, from the last-result
  note. The late funcret itself does not depend on the log line.
* **Cosmetic log noise.** CHIM's `chim.log` shows one `mkdir(): File exists` warning from the row re-install. It is
  pre-existing and harmless.
* **The rows are re-installed once.** Any owner edit of the glue rows in the Action Editor is reset (step 2 above).

## 7. Files changed in this pass

* **Server:**
  * `server/lorerim_glue/lib/lrg_actions.php`: `lrgRecordResult`, `lrgFuncretVerdict`, `lrgVoicedWhat`,
    `lrgVoicedWhy`, `lrgNoteEscortLate`, and the next-turn fail note in `lrgPrepareTurn`.
* **Tests:**
  * `tools/test_gates.php` (35 (1b) / (1c), and 24's exact array);
  * `tools/flows/scenarios/30_wire_507.php` (new).
* **Tools:** `tools/deploy_server.ps1` (4 anchors).
* **Docs:** `PROTOCOL.md` and `README.md`.
* **Game:** `game/LoreRimGlue/Scripts/*.pex` (recompiled from the game lane's sources, which are unchanged).

**Not touched:**
* CHIM core, and every other mod;
* profile files;
* the ESP / SEQ;
* the owner's `lrg_config.json`.
