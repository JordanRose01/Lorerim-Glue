# PT6 RELEASE stage report (v0.3.0)

Owner addenda read first: 3 items (G3 say-it-first, R10 every self-initiated change is announced,
no in-scene refusals). Nothing in addenda 1-3 needed a late fix at this stage - all three are
already carried by the code (see D3/D10 below and flow scenarios 14, 19, 21, 24).

## 1. Defect verification - all 13 listed defects CONFIRMED FIXED (each spot opened)

| # | defect | where confirmed |
|---|--------|-----------------|
| D1/D5/D7/D12 | "you lead" decorated with hold=150 (4 duplicate reports) | `lib/lrg_actions.php:1302-1311` `$handOver` early-skip. Probe P6: model path and net path both emit `do=lead;who=npc` with NO `hold=`; an UNASKED lead flip still carries `hold=150`. |
| D2 | safety net cannot correct a wrong-`who` clothing command | `lib/lrg_actions.php:1244` strict payload compare (`who`,`part`; `furn`; `speed`) + `lrgCorrectFromIntent()` :1260-1274 called at :1137. Probe P1: "I want you to take my clothes off" + model `undress you` -> wire `do=undress;who=player;part=all` on BOTH paths; "take your clothes off" correctly stays `who=npc`. |
| D3 | empty-message BeginIntimacy still started the scene | `lib/lrg_actions.php:1245` `LRG_ACT_START -> return false`. Probe P2: with an empty message NOTHING is sent after "kiss me" / "get naked" / "I want you right now" / plain speech; with a line the start goes out with `wait=end`. |
| D4/D11 | `lrg_initiative` cue still licensed "an action with an empty message" | `prompts.php:56-61`. Probe P8: the cue no longer contains those words. |
| D6 | hold carrier cancelled a running wind-down | GAME `LRG_OStim.psc:2694-2700` (`if who != leader` around `CancelWindDown`) AND server `lrgHoldCarrier` no longer emits while `wd=1`. Probe P7: nothing sent during a wind-down. |
| D8 | a hedged "yes" executed the proposed sex act | `lib/lrg_intent.php:151-163` (bare-yes pattern + `lrgIntentBlocked` first). Probe P3: "yes, but not now"/"yes, not yet" -> `no`; "okay, hold on" -> `hold`; "sure, in a minute"/"ok, so what were you saying"/"go ahead and tell me more" -> `none`. Plain "yes"/"yes please" still `yes`. |
| D9 | "that's enough, fuck me" ended the scene | `lib/lrg_intent.php:104-107` `lrgIntentAfterStop()` + :240-252 frame judged on the remainder. Probe P4: -> `act`, `do=goto`; "that's enough"/"stop"/"stop, I can't take any more" still `stop`. |
| D10 | ~90 s snapshot-staleness gap (in-scene requests lost) | GAME `LRG_OStim.psc:3097-3099` `Main().MaybeSnapshot(partner, false)` inside ThreadTick's 4.5 s branch; server staleness rail untouched. |
| D13 | contradictory orders: pending proposal vs turn directive | `lrgPickProposal()` returns null for every `LRG_INTENT_ACTIONABLE` kind plus yes/no. |
| D14 | announce gate had no look-back, 12 s budget | GAME `LRG_OStim.psc:504-515` 3 s look-back; `fSayFirstMaxWait` default 20 (`settings.ini:39`, `LRG_OStim.psc:488`, MCM slider max 25). |

Carried-over minors from the build reports, re-checked here:
- `command_confirm_seconds` 20 -> 40: DONE (`config/lrg_config.default.json:221`, PROTOCOL.md:322).
- MCM: 35 config.json ids, 35 settings.ini keys, none missing, none extra.
  `fSceneLeadInterval=75`, `fLeadHold=150`, `fSayFirstMaxWait=20`, `fSayFirstSettle=1`, `fSayFirstWait=6`.
- Versions: manifest 0.3.0, `LRG_ACTIONS_VERSION = 8`, `CurrentVersion = 300`.

## 2. Test runs - all green

- `tools/compile.ps1` -> `OK - .pex files copied ... 0 errors, 0 warnings` (exit 0).
- `php -l` on every php file under server/ and tools/ -> lint OK.
- scene index warm --force -> 607 scenes, 5.9 s.
- `tools/test_gates.php` -> 206 passed, 0 failed.
- `tools/test_intent.php` -> 141 passed, 0 failed, OK.
- `tools/test_scene_index.php` -> ALL CHECKS PASSED.
- `tools/flows/run_flows.php --strict` -> 32 scenarios, 32 passed, 0 FAILED, 0 pending, 666 checks, 0 warnings, RESULT OK.

## 3. Deploy - DONE

`tools/deploy_server.ps1` run twice (the second after three comment-only doc fixes, below):
`scene index: rebuilt, 607 scenes, 5.9s` / `deployed to /var/www/html/HerikaServer/ext/lorerim_glue`,
exit 0 both times. Post-deploy checks on the LIVE tree: `php -l` on every deployed php file -> OK;
`lrg_config.default.json` parses; index status `{"scenes":607,"stale":false,"error":""}`;
manifest 0.3.0; ownership dwemer:www-data. Glue log after the deploy shows only
`action catalog: rows installed (v8)` and `scene index rebuilt: 607 scenes ... filter=open`, with the
NEW stamp format `2026-09-21 16:45:37 -04:00` - i.e. the G6 timezone fix is live and the log is now
in the owner's own clock with the offset printed. No PHP notice, warning or error from our plugin in
the apache log after the deploy (the `settings_pkey` duplicate and the TTS socket errors there are
CHIM's own, pre-existing).

Three comment-only fixes made at this stage (no behaviour, re-tested and re-deployed):
- `config/lrg_config.default.json` `_log_readme`: "fSayFirstMaxWait (12 s by default)" -> 20 s.
- `config/lrg_config.default.json` `npc_overrides._readme`: stale `scene_talk.announce_chance`
  reference -> `blunt_chance`, plus "since 0.3 she always says something".
- `PROTOCOL.md:322`: same 12 s -> 20 s.
All suites re-run after the edit: 206 / 141 / ALL CHECKS PASSED / 32 scenarios, RESULT OK.

## 4. Install - DONE

`tools/install_mo2.ps1` ran (MO2 and Skyrim were both closed, the refusal did not trigger), exit 0:
backups `*.bak-20260921-164622`, 24 nude-body meshes, mod folder
`F:\Modlists\LoreRim\mods\LoreRim Glue`, modlist/plugins/loadorder entries already present.
Verified in the installed copy: six `.pex` files from this compile run (16:41), `settings.ini` with
`fSceneLeadInterval=75 fLeadHold=150 fSayFirstMaxWait=20 fSayFirstSettle=1`, `config.json`.
No Nemesis, no LOOT, nothing else touched.

NOTE for the owner: MCM Helper keeps the values an EXISTING save already holds. There is no
`MCM\Settings\LoreRimGlue.ini` anywhere under `F:\Modlists\LoreRim`, so a fresh registration takes
the new defaults - but on the current save the two CHANGED sliders (lead interval 45 -> 75,
scene-start wait 12 -> 20) may still show the old numbers and have to be set by hand once. This is
in `PLAYTEST6_NOTES.md` section 4.

## 5. Owner notes

`PLAYTEST6_NOTES.md` written (plain language, 7 sections): what went wrong in playtest 6 and why
(narrator routing, "the player is passive" text, our own prompt forbidding ChangeClothing, the
empty-message start licence, the plumbing losses, the sewer privacy reading); what changed; how to
talk to her now with example phrases per verb; the MCM table with defaults; what the owner must do
by hand (OStim free cam OFF first, the fade decision, hotkey collisions, auto mode, the push-to-talk
key check, the mic check); how to read the new log lines when something is ignored; known limits.

Owner-action items from the diagnosis, as they stand now:
- stale scene row: NO action needed, G4 closes it on the first load (said so in the notes).
- `scene_talk.announce_chance`: repurposed as `blunt_chance` (bluntness only) - explained.
- lead interval 45 -> 75 and the 150 s hold: in the game plugin, confirmed installed.
- log timezone config key: live, default `America/New_York` = the owner's own clock (not
  Europe/Madrid as the note suggested - the owner's clock reads better beside OStim.log, and the UTC
  offset is always printed).
- privacy counts humans only (sewer creatures): left as is, flagged in the notes as a config knob.
- empty STT transcript: `WARN empty transcript` in the log; still no on-screen "didn't catch that",
  listed under known limits.

## 6. Unresolved (handed to the next round)

- [minor, KNOWN RESIDUAL of D15] Generic synonym words still reach an act at high confidence:
  `scene_index.synonyms` maps `feel` -> groping* and `arms` -> hugging/embrace, so "can you feel
  that?" recognises `act grope:npc` at conf=high (probe P5). Harmless in practice - it can only move
  a RUNNING scene to a groping/hugging position, never start a scene and never reach a sex act - and
  in the probe it was refused anyway because the act was not reachable. The clean fix is a confidence
  downgrade when the only evidence is a generic word, which needs a design call (pruning the synonym
  keys would degrade the position search that `test_scene_index.php` asserts on). Documented in
  `tools/test_intent.php:128-134`.
- [minor] `glue/README.md` is still the 0.2.0 playtest-4 page (version line, MCM ids, 0.3 config
  keys). Owner-facing content for this round is in `PLAYTEST6_NOTES.md` instead.
- [minor] The two extra `turn npc=` log prefixes (`lib/lrg_actions.php:799`, `:628`) are still not
  renamed / listed in PROTOCOL section 9.
- [minor, by design] The CHIM speech/command ordering assumption is still unverified in a real game.
  The instrumentation is in place: read the `announce ... reason=` lines in playtest 7
  (`reason=spoke` = the design holds; a run of `reason=timeout` = downgrade `wait=` to `begin`).
