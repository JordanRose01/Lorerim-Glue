# Playtest 13 - server lane: "I couldn't get her to follow me"

Server 0.5.3 -> **0.5.4** (manifest + `LRG_VERSION`). **Not deployed.** Everything is offline-tested and green:
`php -l` 80 files / 0 errors; test_gates 339, test_intent 255, test_phrases 31, test_scene_index all passed,
test_dialogue 174, test_prompt_index 67, test_mcm_wiring 3, test_services 35, test_latency 26 (all 0 failed);
flows `--strict` **76 scenarios / 1252 checks / 0 fail / 0 pending / 0 warn** (before this round: 75 / 1203).
Staged and run from `%TEMP%\lrg_test\srv13` (own subfolder, so the game lane's staging is untouched; scripts
`%TEMP%\lrg_test\srv13_stage.ps1`, `srv13_lint.sh`, `srv13_tests.sh`, `srv13_flows.sh`).

## 1. What really happened (server view, from lorerim_glue.log + the DB + CHIM's catalog)

- 01:43:10 / 01:43:38 / 01:44:01 - three player lines to Lisette, each logged `mode=closed ... intent=none`
  (`why=no pattern matched`, `no pattern matched`, `question`) with `reasons=quest_scene,witnesses`.
  The recogniser simply had no follow / wait / release vocabulary.
- `llm npc=Lisette action=none` three times, although CHIM sent `Lisette|command|FollowPlayer@` each time
  (output_to_plugin.log). The `llm` line only knew glue actions - fixed (now `... chim=FollowPlayer`).
- Papyrus.0.log confirms CHIM's side ran: `START stayAtPlace Lisette` / `END stayAtPlace` at 01:43:14 and
  01:43:40, `AIAgentFollowPlayerPackage start/changed` at 01:43:49. She stayed because her snapshot said
  `scene=1` (an engine scene: BardSongs / aaLisetteIdle on the stage), which outranks every package.
- Lisette's stored snapshot (01:44:19): `scene=1`, `mate=0`, `hold=0`, `witchim=0`, **no `fol=` key** - and
  no NPC of this session has one: the game's abort rail retired it (the false alarm the game lane owns).
- CHIM catalog on this install (`public.core_action`): **`WaitHere` id 53 is_activated = false**
  (available_to_npc true); `FollowPlayer` id 16 active, available_to_followers false; `StopWalk` id 43
  active but not available to NPCs; **`MakeFollower` id 28 and `Follow` id 15 are still switched ON** (the
  owner to-do in MORNING_SUMMARY asked to switch both off - the glue's HEALTH line keeps saying so).
  CHIM's own `AIAgentAIMind.WaitHere()` is a deprecated notification stub. So "wait" has no CHIM action
  here and is routed through the glue (`do=wait`).

## 2. What changed

### (1) INTENT - `lib/lrg_intent.php`
New kind **`escort`** with the verb in `kv.do` = `follow | wait | release`, so the turn line reads
`intent=escort/follow conf=high`. It is deliberately NOT in `LRG_INTENT_ACTIONABLE` (the scene's own verbs,
where `release` already means "stop holding back") and not in `intent.net_kinds`.
- `lrgIntentEscort()` (regex only, per clause after the DLL context prefix and the speaker prefix are
  stripped): follow = follow me/us, come with me (also "come upstairs with me"), come along, come to my room,
  walk with me, tag along, you're coming with me, let's go (+ upstairs / somewhere ...), and a BARE "come on";
  wait = wait/stay here/there, wait for me, stay put, stay where you are, hold position; release = you can go
  (back / back to ...), go back to singing / your work, you're free to go, stop following me, you don't have
  to follow me, that'll be all. Owner extras: `escort.phrases.*`.
- Blockers stay as they are: questions that are not polite requests ("hey are you gonna follow me?" ->
  `why=question`), negations ("don't follow me" -> `why=negated`), hypotheticals, quotations, "oh come on",
  the player as subject ("I'll wait here"), somebody else ("they will follow me"). Polite forms are requests
  ("will you follow me?", "why don't you come with me").
- **Every mode outside a scene with her**: in closed / public / private / follow and on the blind turn the
  recogniser gets `ctx.escort = true` and asks the escort FIRST (before money / stop / blockers); on a silence
  the game knows the reason for (not adult, child nearby, switch off, `never`) the new escort-only
  `lrgRecogniseEscort()` runs, which has no sexual reading at all. Witnesses / quest_scene never block it.
  Inside a scene with her nothing changes ("wait here" = hold, "come with me" = climax).
- The three pt13 lines now read: follow / follow / none (question).

### (2) DIRECTIVE - `lrgEscortDirective()` (`lib/lrg_actions.php`), dispatched from `lrgBuildRequestDirective()`
Always "This is not intimacy: nothing said above about intimacy applies to it" and ONE short line. First match:
her live menuless follower list answers it -> "take it up with the matching key from `<follower_commands>`";
a follower framework owns her -> "her own follower dialogue carries that out", no action named; follow with
`FollowPlayer` offered -> **"Do it now: choose Follow_<player> in this same reply ... answer in one short line"**
(+ "the game takes her away from what she is busy with first" on a stage); follow not offered -> either "the
game makes her walk by itself" (stage) or "nothing can make her walk on this turn"; wait / release ->
"nothing to choose for it: the game keeps her here / lets her go by itself" and "choose no movement action -
not Follow_<player> either". In mode `closed` it replaces the intimacy refusal shape; on a `silent` turn it is
the only directive (the second, and last, exception to "a silent turn injects nothing", PROTOCOL 6.2).
Real output for the pt13 turn (flow 28 trace):
> Intimacy is not possible right now because something important is happening right now, and other people
> are close enough to see or hear, ... Nothing physical begins. <player_request>Flowtest Player just asked
> Lisette Flowtest to come along. This is not intimacy: nothing said above about intimacy applies to it. Do it
> now: choose Follow_Flowtest Player in this same reply - that is what makes Lisette Flowtest actually walk
> with Flowtest Player - and answer in one short line. The game takes Lisette Flowtest away from what she is
> busy with first. Do not describe walking anywhere before it has started.</player_request>

The dialogue lane's conversation-hold test (`lrgDlgPlayerAskedToMove()`) now also asks the escort recogniser,
so a HELD NPC told "come along" / "you can go back" keeps CHIM's movement actions on the table.

### (3) ESCORT - `lrgEscortPlan()` / `lrgEscortNet()`, first thing in the existing post-LLM closure
Wire (PROTOCOL section 2 row + new **section 10.20**):
`<npc>|command|ExtCmdLRG_Escort@ok=1;cid=<turn cid>;npc=<name>;do=<follow|wait|release>;safe=BardSongs,BardSongsInstrumental,*Idle*,*Sandbox*,WI*`
- `do=follow`: the reply carries `FollowPlayer` (or the display name `Follow_<player>`), or the follow intent was
  high and the reply carries no movement at all (safety-net shape) - AND she is in an engine scene (snapshot
  `scene=1`, the dialogue lane's `scene=1`, or gate reason `quest_scene`). Inserted IMMEDIATELY BEFORE CHIM's
  `FollowPlayer` line. Not on a stage -> nothing added ("CHIM's own follow is enough"). A reply that chose
  another movement (TravelTo ...) is not overridden.
- `do=wait` / `do=release`: on the player's own words (high); appended; a `FollowPlayer` the model chose
  against them is removed from the batch.
- Never: inside a scene with the player, on a non-speech turn, without a snapshot <= 300 s old, for a companion
  a framework / the party owns (`fol=` fw sff / custom, mate:1, ghost:1 - remembered value included; with no
  `fol=` the snapshot's own `mate=1` or `CurrentFollowerFaction` on `fac=`), when her live menuless list
  answers the verb, with `escort.emit` off. No adult gate (not intimacy); kill switch and SHARMAT still
  silence it like every Phase 1 line. A line of it FROM the model is dropped ("not offered").
- Config `escort` block (defaults in code and JSON): enabled, recognise, directive, emit, safe_quests,
  snapshot_max_age_seconds 300, follower_factions, phrases, words.
- Log: new prefix `escort follow npc=.. scene=1 fol=.. why=.. -> <param>` / `escort skipped: <rail>`.
- **The funcret** is recorded in `last_result` and then answered `handled` in preprocessing (before the MAIN
  lock): the code has no catalog row, so CHIM's `processor/funcret.php` would only write an
  "issued ACTION ExtCmdLRG_Escort: ..." infoaction into her event log and then `terminate()` anyway.

**Verification of the old-game claim - it is NOT "logs and nothing else".** `LRG_Main.HandleCommand`
(script 505) for an unknown `ExtCmdLRG_` code: `NoteChimAction` (no effect - Escort is not in
`CONV_MOVE_ACTIONS`), the 5 s de-dup ring, `LogC "command ExtCmdLRG_Escort from <npc> via <path> param=..."`,
the `IsEnabled` check, then the last branch `ReportResult(..., "Error: unknown command")` - which sends a
funcret, calls `commandEndedForActor`, logs `result ExtCmdLRG_Escort: Error: unknown command` AND, while
`bNotifyErrors:General` is on (default), shows **one corner note "LoreRim Glue: unknown command"**. Nothing
moves in game. (The string "unknown glue action" is the SERVER's own drop message, not the game's.)
Server mitigation: that funcret is swallowed, never told to her (`told=true`), logged once, and **no further
escort is sent for the rest of that game session** (session tag in `lrg_memory` row `*glue*`); after a reload
it tries again. Net effect on script 505: at most one corner note per game session.

### (4) fol= tolerance - `lrgCarryFol()` in `lrgStoreNpcState()` + `lrgFolState()` (`lib/lrg_core.php`)
A snapshot with `fol=` stamps `_fol_last/_fol_at/_fol_sess` into its row; a snapshot WITHOUT `fol=` inherits
them from the previous row when the session tag (`sess`) is the same. `lrgFolState()` then uses the remembered
value (marked `_remembered`) for `followers.remember_seconds` (900; 0 = old behaviour). No `sess` -> nothing
remembered (keeps every old test and game 200 unchanged); a reload forgets; the keys are never taken from the
wire; `<companion_status>` from a remembered value drops the volatile "right now following / waiting" line.
So the companion block, the MakeFollower hide rule and the escort's framework rail no longer go quiet for the
session when the game's abort rail retires `fol=`. Known cost: switching `bFollowerAware` OFF mid-session
keeps the last facts for up to 900 s of that session.

### (5) Tests
- `tools/test_intent.php` section J (+65): the three pt13 lines verbatim, every phrase above, negations /
  questions / hypotheticals / quotes / subjects, compound + extra, the context prefix, the scene readings
  unchanged, escort-only has no sexual reading, all directive shapes (closed with/without FollowPlayer,
  silent, wait, release, no snapshot, SFF, menuless follower live).
- `tools/test_phrases.php` section D (+17 MUST rows): each phrase in all six out-of-scene modes (closed,
  public, private, follow, silent-blind, silent-known) incl. the directive shape, and never escort inside
  the two scene states.
- `tools/test_gates.php` 34 (a2) (+7): remembered fol= through snapshots, expiry, reload, no sess, wire keys
  ignored, block wording.
- New flow `tools/flows/scenarios/28_escort.php` (49 checks): recognition + turn line + directive in mode
  closed; FollowPlayer -> Escort first with exact key order and cid; display-name form; funcret handled +
  recorded (a normal glue funcret still passes); net shape; other movement not overridden; no stage -> no
  escort; wait (contradicting FollowPlayer dropped) and release; SFF never; remembered fol= and reload;
  old game "unknown command" -> handled, not told, paused for the session, retried after reload; silent
  (feature_off) turn still recognises + injects + emits while "take off your clothes" stays unparsed; a forged
  model line dropped; the hold hears "come along"; inside an OStim scene "wait here" stays hold and no escort.

## 3. What the GAME lane has to build (PROTOCOL 10.20 section 4; the server depends on none of it)

1. Route `ExtCmdLRG_Escort` in `LRG_Main.HandleCommand`; resolve the actor by `npc=`; refuse
   `Error: a follower framework owns her` for teammates / SFF / ghosts.
2. `do=follow`: if `GetCurrentScene()` is set, stop it ONLY when its owning quest's EditorID matches a `safe`
   pattern, with `Scene.Stop()` on that scene alone - never CHIM's destructive `InterruptScene`; otherwise
   `Error: the scene she is in cannot be interrupted`. Then `EvaluatePackage()` and, if
   `CHIM_FollowPlayerActive` != 1 (CHIM's own line can arrive later or never; the ExtCmd mod-event path and
   CHIM's direct Papyrus call are not ordered), apply the follow as CHIM does (FollowPlayerPackage 0x2226d
   AIAgent.esp, priority 100, `CHIM_FollowPlayerActive=1`). Watch for BardSongs re-picking her for the next
   song (BardAudienceExcludedFaction / one re-check tick).
3. `do=wait`: remove CHIM's follow override (`CHIM_FollowPlayerActive=0`) and keep her in place until
   follow / release / load / a limit. `do=release`: remove CHIM's follow override and any glue wait,
   `EvaluatePackage()`.
4. Answer through `ReportResult`; suggested neutral successes `She comes along.` / `She waits here.` /
   `She goes back to what she was doing.`

## 4. Notes and residual risks

- **Ship both halves together.** A 0.5.4 server against script 505 costs one "LoreRim Glue: unknown command"
  corner note per game session (first follow / wait / release on a stage), then pauses itself. To avoid even
  that until the game script ships, set `escort.emit=false` in `config/lrg_config.json`.
- The follow directive is an order ("Do it now"), like the in-scene DO-IT shape - a stranger in mode closed is
  no longer given room to refuse walking with the player. That is what was asked; if the owner wants refusals
  back for strangers, it is one sentence in `lrgEscortDirective()`.
- The dialogue lane's existing `move_request_words` still counts a QUESTION containing "follow me" as a
  request to move (pre-existing; my addition only applies when no listed phrase hit).
- Escort decisions use the snapshot's `scene` flag (<= 300 s old). If she left the stage in the meantime the
  game answers with a reason and the server tells her once - never silent.
- Not touched: CHIM core, other mods, profile files, anything under F:\Modlists, the deploy script.

## 5. Files changed

server/lorerim_glue/lib/lrg_core.php · lib/lrg_intent.php · lib/lrg_actions.php · lib/lrg_dialogue.php (one
guarded call in `lrgDlgPlayerAskedToMove`) · config/lrg_config.default.json (`escort` block,
`followers.remember_seconds`) · manifest.json (0.5.4) · PROTOCOL.md (header, section 2 row, 6.2, 7.2, 9,
10.19 item 0, new 10.20) · tools/test_intent.php · tools/test_phrases.php · tools/test_gates.php ·
tools/flows/scenarios/28_escort.php (new). The deploy script's anchor strings are all still present exactly
once.
