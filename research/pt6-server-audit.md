# Playtest 6 - Server prompt & action audit (read-only)

Scope: `glue/server/lorerim_glue/**` + `glue/PROTOCOL.md` + HerikaServer 3.3.2 core (read-only, staged
out of WSL to read). Nothing was modified except this file.
Owner addenda read FIRST (`glue/OWNER_ADDENDA.md`): #1 she must speak before starting a makeout;
#2 verbatim "if she initiates any new scene/animation she should say something signifying it" - EVERY
self-initiated scene/animation change, gentle ones included. Both are handled in this audit: see
section 1 (why she starts silently today) and section 3 (why lead-turn changes are silent by design
today) - the current code explicitly instructs the opposite of addendum #2 and must be changed.

Paths below are relative to the project root; HerikaServer paths are absolute
(`/var/www/html/HerikaServer/...`). Line numbers are from the files as they are on disk today
(plugin v0.2.0, `LRG_ACTIONS_VERSION = 7`).

---

## 0. TL;DR - the five complaints, in one table

| # | Complaint | Root cause (one line) | Evidence |
|---|---|---|---|
| 1 | BeginIntimacy on the first flirty sentence, silently | The action is offered on *every* private speech turn as soon as the gates pass, and the guidance literally tells the model it "can simply happen, message left empty" | `lib/lrg_actions.php:466`, `:885`, `:914-918`, `:75` |
| 2 | "get naked" in a scene produces no action | ChangeClothing *is* offered, but it is named last, hedged ("only if she wants to"), and the R10 rule adds "leave message empty ... **unless the player asked something that needs an answer**" - so a direct request steers the model to answer in words and do nothing | `:1026-1028`, `:1022-1025`, `:992` |
| 3 | Lead turns circle inside a tier, ignore the player | Options are ranked by graph distance only; **no scene is ever remembered as visited**; a lead tick is never suppressed after a player request (the server has no notion of "the player just asked for something") | `lib/lrg_scene_index.php:736-762`, `lib/lrg_core.php:175-192`, `lib/lrg_actions.php:411-412` |
| 4 | Scene row stays "in scene" after a reload | The row is only closed by `ev=end`, by a *newer* snapshot with `ostim=0`, or after 900 s. A funcret `Error: no scene is running` is recorded but **never closes the row** | `lib/lrg_core.php:243-264`, `lib/lrg_actions.php:250-264` |
| 5 | Mixed UTC / UTC+2 stamps | `lrgLog()` uses `date()`, and PHP's timezone is *not* set in php.ini (default UTC). Only `main.php:11` sets `Europe/Madrid`; CLI runs, the `gamedata.php` endpoint and everything after `backupAllNpcs()` run in UTC | `lib/lrg_core.php:62`, `main.php:11`, `lib/core/npc_master.class.php:1370`, `/etc/php/8.2/*/php.ini:979` |

---

## 1. Why BeginIntimacy fires on the first flirty/sexual sentence - and silently

### 1.1 The offer has no build-up condition at all
`lrgPrepareTurn()` (`lib/lrg_actions.php:350-493`), non-scene branch:

* `:444` `lrgEvaluateGates($npc, $type, $isInit)` - hard rails only: fresh snapshot, `on=1`, `adult=1`,
  no combat, no quest scene, not already in a thread, no child, no witnesses over the profile limit,
  and `interest.willing` (`lib/lrg_core.php:448-500`).
* `:455` `lrgGateMode()` - with no blocking reason and no pending invite the mode is **`private`**
  (`lib/lrg_core.php:506-516`).
* `:466` `$offer = [... 'private' => [LRG_ACT_START, LRG_ACT_CLOTHING] ...]` - BeginIntimacy and
  ChangeClothing are offered on **the very first player speech turn** in a private room.

`willing` is `score >= 0` where `score = affinity - min_affinity + bonus` (`lib/lrg_core.php:420-438`).
For Lisette: `tavern_folk` profile, `min_affinity: 10` (`config/lrg_config.default.json:110`), affinity 10
-> score 0 -> willing -> `private`. The glue log confirms it on the first turn after the load:

```
19:28:59 turn npc=Lisette mode=private offer_start=yes reasons=- aff=10 interest=interested(0) profile=tavern_folk
19:29:10 gate: StartIntimacy passed -> ok=1;...;scene=OARE_StandingEmbraceKiss
```
(`/var/www/html/HerikaServer/log/lorerim_glue.log:136-137`)

There is **nothing** that counts romantic exchanges, nothing that looks at how long they have been
talking, and nothing that blocks a restart right after a scene or a reload:

* no "heat" counter anywhere in the plugin (grep: no such key in `lrg_core.php` / `lrg_actions.php`);
* `lrg_romance.last_scene_at` is **written** (`lib/lrg_core.php:279`) and never read again - so the
  second start at `19:35:43-51` (log lines 185-187), ~6 minutes and one reload after the first, passed
  every gate as if nothing had happened.

=> G3(d) ("at least 2 romantic/sexual exchanges, not right after a scene or a reload") has **no
implementation whatsoever** today.

### 1.2 The wording actively invites the action, and invites it silently
* Catalog description, `:75`: *"Begin physical intimacy with the player; it always starts gently ...
  **You may make the first move yourself.**"*
* Static guidance, `:849`: *"... **$npc may well make the first move.**"*
* Volatile guidance, `:885`: `'BeginIntimacy (it starts gently - a kiss, an embrace - and **can simply
  happen, message left empty**)'`
* Private branch, `:914-918`: *"It is private here. $n wants this and may make the first move without
  being asked: a plain proposition in words, BeginIntimacy (...can simply happen, message left
  empty), ChangeClothing (item "undress") to strip."*

The "message left empty" clause is a documented CHIM capability (an action with an empty `message`
produces no TTS at all) and it is offered to the model as an equal alternative to speaking. That is
exactly the owner's complaint: the makeout cutscene started out of nowhere. The clause must be
removed for BeginIntimacy / SuggestPrivacy / self-undressing and replaced by a *hard* "one short line
first" rule plus the server-side guarantee of G3(b) - the post-LLM gate is the place that can enforce
it (it already sees the whole action list, and `$GLOBALS['talkedSoFar']` holds what she said this turn,
see section 7.4).

### 1.3 Nothing distinguishes "she asks a sexual question" from "she acts"
The private guidance is one paragraph for both cases; a question about sex and a proposition get the
same offer. G3(d) ("a question about sex is a question") has no counterpart in the code either.

---

## 2. Why a direct in-scene request ("get naked") can end with no action

The `19:36:10` turn (log line 198) was a speech turn in a live scene: `in_scene=OARE_StandingEmbraceKiss
speech reached=2 ceiling=3 options=8 announce=yes` and **no** gate line follows - the LLM chose no
action at all.

### 2.1 The action IS offered - the prompt is what fails
`lrgPrepareTurn()` scene branch: `:438` hides only `START` + `INVITE` (+ movement actions); `:439`
keeps `CONTROL` and `CLOTHING` whenever `can_act` (player speech or lead tick). So `ChangeClothing`
was in `ENABLED_FUNCTIONS`.

### 2.2 What the model actually reads (in this order), `lrgSceneNotes()` `:941-1031`
1. `:962-963` what is happening now, pace, undress state;
2. `:966-969` **a large block about how she talks**, ending with "Only about what is really happening
   now. ONE short sentence ..., often less; **silence is fine**. Never repeat a line.";
3. `:977-982` the ladder ("one step at a time ... reached so far ...");
4. `:988-992` `"If the player asks to stop ... choose ChangeIntimacy with item stop"` and
   `"Who leads: if the player says what they want, $npc does it (**or declines what does not suit them
   - then nothing changes**)"` - an explicit, cost-free way out;
5. `:995-1019` the **option list**: up to 8 `P1..Pn = <label> [say it|silent] [next step]` plus ~10
   "other items" (faster, slower, hold, release, climax, wind down, stop, furniture, `<npc> leads`,
   `<player> leads`, auto) plus up to 10 position words. This is by far the longest part of the
   injection and it is all about `ChangeIntimacy`;
6. `:1022-1025` the R10 rule, whose `$unless` clause is the killer for a direct request:
   *"[silent] choices (kissing, holding), **and pace, hold or clothes: leave message empty - it simply
   happens, unless the player asked something that needs an answer.**"*
   "Get naked" is unambiguously "something that needs an answer", so the model resolves the conflict
   by answering and not acting.
7. `:1026-1028` - **last, one line, hedged**: `"ChangeClothing, item undress | dress, ... : only if
   $npc wants to."`

### 2.3 Everything else that pushes the same way
* The player's utterance is **never restated by us**. Our injection sits at `prompt_bottom` with
  priority 900 (`context_pre.php:17`), i.e. it is the *last* thing in the prompt - so the last thing
  the model reads is the option list and the say-it rules, not the request.
* No turn-specific directive naming the action and target exists (G1's "directive" is missing).
* `FORCE_MAX_TOKENS` is 200 on an act turn (`:511`, config `scene_talk.max_tokens.act`); a reply that
  contains both a spoken line and an action fits, but there is no slack for reasoning.
* Row follow-ups are off (`:66`) and `suppress_placeholder_infoaction` is set (`:63`), so when she
  does nothing there is no feedback loop at all - `$turn['fail']` (`:475-481`) only reports a command
  that *failed in game*, never a request that was silently dropped.
* No intent recognition on the player's text exists anywhere: the only thing the plugin ever reads out
  of `gameRequest[3]` is the tick word `lead` / `approach` (`lib/lrg_core.php:138-141`,
  `lib/lrg_actions.php:144-152`).

---

## 3. Why lead turns circle inside a tier and ignore what the player just asked

### 3.1 No memory of what has already been played
The only per-scene memory is `lrgTrackProgression()` (`lib/lrg_core.php:175-192`): `_maxtier`,
`_tier_since`, `_climaxes`. **There is no list of visited scenes** - not in the scene payload, not in
`lrg_memory`. Consequently the ranking cannot avoid a repeat, and the playtest shows exactly that
(log lines 150-176):

```
19:30:06 LEAD ... -> goto OARE_GropingButtKiss
19:30:55 LEAD ... -> goto OARE_GropingButtKiss   (again - the first navigate had not landed yet)
19:31:45 LEAD ... -> goto OARE_GropingButtKissOA
19:32:45 LEAD ... -> goto OARE_StandingEmbraceKiss   (back to the start)
```

### 3.2 How the options are ranked (`lib/lrg_scene_index.php:736-762`)
* `lrgSceneWalk()` (`:686-726`) does a BFS of at most 3 hops over OStim's transition graph inside the
  tier ceiling.
* `:749` `rank = hops + (neutral ? 3 : 0) + (name does not fit the sexes ? 1 : 0) + (taste-listed ? 1 : 0)`
* `:753` sort by `[rank, |tier - current tier|, hops]` - i.e. **nearest and most similar first**. A
  scene one hop away in the same tier always outranks the next step.
* `:756` only `max(1, intdiv($max, 3))` slots (2 of 8) are reserved for ceiling-tier scenes.
* Nothing in the ranking knows about history, and nothing knows what the player asked for.

The `[next step]` mark (`:999`) and the lead sentence (`:983-987`) are the only forward pressure, and
they compete with 6 same-tier options that all look equally good to the model.

### 3.3 Lead turns are a different prompt - one that asks for a *silent* change
* `lrgPrepareTurn:411-412`: `lead = lrgIsLeadTick() && lrgLeadAllowed()`; `:440`
  `lrgKeepOnlyActions([CONTROL, CLOTHING])` - on a lead tick *only* scene actions exist, Talk is
  stripped out.
* `prompts.php:44` (the cue, the last text in the prompt): *"($lrgName leads now and makes ONE change
  that suits them ... **a [silent] choice simply happens with an empty message**; otherwise at most one
  short spoken sentence ...)"*
* `lrg_actions.php:1022-1025` on a lead turn: `$unless = "."`, so the rule reads *"[silent] choices
  (kissing, holding), and pace, hold or clothes: leave message empty - it simply happens."*
* `lrgIsSayIt()` (`:931-935`) marks only sensual/sexual options `[say it]`, and even then only if the
  `announce` dice passed (`:427-428`, config `scene_talk.announce_chance`, 85 % for `vocal`).

**This is the code that owner addendum #2 overrides.** Today "kissing / holding / furniture / clothes"
changes she initiates herself are *instructed* to be silent, and the `announce_chance` dice can make
even a sexual pick silent. Under the revised R10 every self-initiated change needs a line; the dice
should only ever decide *how* blunt, never *whether* she speaks.

### 3.4 Nothing suppresses a lead tick after the player spoke
* The lead tick is a **game-side timer** (MCM interval, 45 s in playtest 6; PROTOCOL 1.4). The server
  can only refuse it, and the only refusals are `lrgLeadAllowed()` (`:155-159`: leader must be `npc`,
  not auto, not winding down) and the hard rails.
* The server stores **no timestamp of the last player request** - `lrg_memory` holds only `interest`,
  `interest_score`, `invite`, `initiative_at`, `last_result`, `lead_idle` (`lib/lrg_core.php:286`).
* `lead_idle` (`:618-621`) pushes in the *opposite* direction: if she did nothing on the last lead
  tick, the next prompt says *"$npc already let the last moment pass unchanged: this time $npc makes a
  change."* (`:986`).
* There is also no "leader" handover from words: `lrgResolveControl` (`:719-731`) deliberately drops
  bare "you lead" / "I lead" because it cannot tell who said it - so the player cannot take the lead
  back by voice today, only through a `<Name> leads` phrasing the LLM has to echo exactly.

=> "Director mode": every 45 s she acts, whatever the player said 5 s earlier. G2's hold period needs
(a) a `player_request_at` in `lrg_memory`, (b) a refusal of the lead tick inside `lrgPrerequest()` /
`lrgPrepareTurn()` while the hold lasts, and ideally (c) a game-side interval bump to 75 s.

---

## 4. Why the scene row stays "in scene" after a reload

`lrgGetActiveScene()` (`lib/lrg_core.php:243-264`) treats a row as live unless:
1. `active=0` (only set by an `ev=end` message, `:163`), **or**
2. `updated_at <= now - scene_stale_seconds` (900 s by default, `:247-250`), **or**
3. a *newer* snapshot of the same NPC says `ostim=0` **and** is more than ~2 s newer than the scene
   row: `:256` `if ($st && ($st['ostim'] ?? '') === '0' && (int) $st['_age'] + 2 < lrgNow() - (int) $row['updated_at'])`.

After a save reload the game never sends `ev=end` (the thread died with the old session), so only rule
3 can help - and it needs a snapshot that is *newer than the last scene message by more than 2 s*.
Snapshots are sent every ~20 s (PROTOCOL 1.1), so there is a window of up to ~20 s in which the server
still claims a live scene. The playtest shows exactly that window:

```
19:33:47 GAME maintenance done, version 200, session 6403     <- the reload
19:33:54 turn npc=Lisette in_scene=OARE_StandingEmbraceKiss speech ...   <- still "in scene"
19:33:57 gate: SceneControl passed -> do=goto;scene=OARE_StandingCarryingSex
19:33:57 result ... -> Error: no scene is running
17:34:04 scene row for Lisette closed: a newer snapshot says the scene is over
```
(log lines 177-183)

Two concrete gaps for G4:
* **The funcret does not close the row.** `lrgRecordResult()` (`lib/lrg_actions.php:250-264`) parses
  the result, stores `last_result.reason`, logs it - and stops. `Error: no scene is running` is the
  single most reliable statement the game can make, and the closed error list (PROTOCOL 1.6) makes it
  safe to match on. It must call `lrgStoreScene($npc, ['ev' => 'end'] + ...)` immediately.
* **There is no load-time sync message.** `maintenance done` arrives as a plain `lrg_log` line
  (`lib/lrg_actions.php:171-179` just writes it to the log). The game should send an `lrg_scene`
  message with `ev=end` (or a new additive `ev=sync;running=0`) on maintenance/load, and a forced
  snapshot with `ostim=0`. Note that rule 3's `+2` slack means a sync snapshot sent *in the same
  second* as the last scene message would still not close the row - the new path must not depend on it.

---

## 5. Why the log stamps mix UTC and UTC+2

`lrgLog()` (`lib/lrg_core.php:60-64`) formats with `date('Y-m-d H:i:s')`, i.e. whatever the current
process timezone happens to be. Verified in the installation:

| fact | evidence |
|---|---|
| `date.timezone` is **unset** in php.ini (both SAPIs) -> PHP default = **UTC** | `/etc/php/8.2/cli/php.ini:979`, `/etc/php/8.2/apache2/php.ini:979` (`;date.timezone =`); `php -r 'echo date_default_timezone_get()'` -> `UTC` |
| `Europe/Madrid` is set **only** by `main.php` | `/var/www/html/HerikaServer/main.php:11` `date_default_timezone_set('Europe/Madrid');` |
| any CLI run of our code logs in UTC | out-of-order glue lines are exactly the CLI ones: `scene index rebuilt ...` (spawned by `lrgIndexMaybeRebuildAsync()`, `lib/lrg_scene_index.php:236-248`) and `action catalog: rows installed (vN)` (offline tools / deploy) |
| a second HTTP entry point never sets a timezone | `gamedata.php` has no `date_default_timezone_set`; its log lines are `+00:00` while neighbours are `+02:00` - `log/chim.log:17435` |
| one core call flips the timezone **mid-request** | `lib/core/npc_master.class.php:1370` `date_default_timezone_set('UTC');` inside `backupAllNpcs()`, called from `processor/comm.php:1337` on every `infosave`. Proof in CHIM's own log: the `infosave` request logs `Audit:Lock acquired by infosave` at `12:36:04+02:00` and its own end-of-request PERF line at `10:36:04+00:00` (`log/chim.log:8671` / `:8673`) |

The single UTC-stamped glue line of playtest 6 (`17:34:04 scene row for Lisette closed`, log line 183)
is a DB-touching line, so it was written by a request that had a DB handle and a timezone that was no
longer Madrid - the class of causes is proven above; which of the three wrote that exact line is not
worth more digging, because the fix removes all of them at once:

**Fix:** make `lrgLog()` timezone-independent - build the stamp from a fixed zone instead of the
ambient one, e.g. `(new DateTimeImmutable('@' . lrgNow()))->setTimezone(new DateTimeZone($cfg['log_timezone'] ?? 'Europe/Madrid'))->format('Y-m-d H:i:s')`, with a config key and the offset in the line
(`... +02:00`) so a future mismatch is visible instead of silent. Keep `lrgNow()` as the time source so
the flow tests can still move the clock (PROTOCOL 7.2).

---

## 6. What already exists that G1-G6 can reuse

| Goal | Existing building block | Where |
|---|---|---|
| G1 recogniser | **`lrgResolveControl()`** is already a full verb parser: stop / wind down / P-key / `speed n` / faster / slower / hold / release / climax(+who) / pullout / lead(+who) / furniture words / label or id echo / free-text position. It just runs on the LLM's `item` instead of the player's sentence | `lib/lrg_actions.php:678-755` |
| G1 recogniser | **`lrgResolveClothing()`**: undress/dress + who (npc/player/both) + part (body/head/hands/feet) | `:762-775` |
| G1 act-by-name | **`lrgFindSceneByText()`** (synonyms, tier check, `too_soon`, `prefer` list) + `lrgSceneWalk()` + `lrgPositionWords()` + `lrgFurnitureOptions()` | `lib/lrg_scene_index.php:1060`, `:686`, `:1168`, `:858` |
| G1 vocabulary | `scene_index.synonyms` (~150 phrases incl. `@sexual/@sensual/@gentle/@any` targets) and `position_words` | `config/lrg_config.default.json:296-336` |
| G1 safety net | **`lrgPostProcessActions()`** - the post-LLM gate, already appends an extra wire line in one branch (`lrgLeadTheWayLine`) | `lib/lrg_actions.php:525-623`, `:611-612`, `:646-658` |
| G1 directive | `chimRegisterPromptInjection('prompt_bottom', ..., 900)` - our text is already last in the prompt | `context_pre.php:13-18` |
| G2 hold / lead | `lrgMemGet/lrgMemSet` (shallow merge, `null` deletes) on `lrg_memory`; `lead_idle` shows the pattern; `lrgLeadAllowed()` is the single refusal point for a lead tick | `lib/lrg_core.php:287-313`, `lib/lrg_actions.php:155-159`, `:618-621` |
| G2 R11 proposal | `lrg_memory` for the pending proposal; `_tier_since` already measures "how long the current act has run"; `lrgTierCeiling` already has a pace clock | `lib/lrg_core.php:175-192`, `:218-226` |
| G3 say-first | the post-LLM gate sees the actions **and** `$GLOBALS['talkedSoFar']` (what she said this turn) - both in one place, before anything is echoed | see 7.4 |
| G4 | `lrgStoreScene()` with `ev=end` closes the row in one call; `lrgRecordResult()` already parses every funcret | `lib/lrg_core.php:155-166`, `lib/lrg_actions.php:250-264` |
| G5 acts/roles | the index digests every scene's actions, actor tags, sexes, furniture and tier (`lrgDigestScene`, `lrgSceneTier`), and `lrgSceneOptions` already filters by sexes/furniture/ceiling | `lib/lrg_scene_index.php:466-548`, `:736-762` |
| G5(9)/(10) memory | `logEvent(['infoaction', ts, gamets, $line])` already writes a neutral line into CHIM's event stream at scene start/end - the same call can carry the per-landing description and the end-of-scene summary | `lib/lrg_actions.php:229-235` |
| G6 | `lrgLog($msg, $cid)` with a per-turn cid, and `$turn['offered']` is already computed | `lib/lrg_core.php:60-64`, `lib/lrg_actions.php:357`, `:472` |
| everywhere | `lrgNow()` / `lrgRoll()` seams for the offline flow tests | `lib/lrg_core.php:73-87` |

### What is missing (nothing of the following exists today)
1. Any reading of the player's utterance beyond the two tick words - **no intent recogniser**.
2. Any per-turn directive naming an action/target - the injection is generic.
3. Any server-side execution of an action the LLM did not emit - **no safety net**.
4. Any record of *when the player last asked for something* - no hold period, no lead suppression.
5. Any record of *which scenes have been played in this thread* - no anti-circling.
6. Any "ask first" mechanism: no pending proposal, no yes/no recognition, no per-act consent/decline map.
7. Any heat / build-up counter, any post-scene or post-reload cooldown (`last_scene_at` is write-only).
8. Any guarantee that a self-initiated change carries a spoken line (the code instructs the opposite).
9. Any close-the-row-on-error path, any load-time scene sync.
10. Any diagnostics of the utterance, the offer, the hidden actions and their reason, or the LLM's answer.
11. An act+roles layer: the index has tiers and actions, but there is no "act -> who gives / who receives"
    projection and no `RequestAct` catalog row.

---

## 7. The G1 safety net: how the server can carry out an action the LLM did not emit

**Verified answer: append the wire line from our existing
`$GLOBALS['action_post_process_fnct_ex']` closure. No new hook, no queue table, no second request.**

### 7.1 The chain, with line numbers (`/var/www/html/HerikaServer/lib/data_functions.php`, `call_llm_internal()`)
```
6029  if ($GLOBALS["FUNCTIONS_ARE_ENABLED"] && $outputWasValid)  {
6030      $actions=$connectionHandler->processActions();
6031-6033 if (isset($GLOBALS["action_post_process_fnct"]))  $actions=$GLOBALS[...]($actions);
6036-6039 foreach ($GLOBALS["action_post_process_fnct_ex"] as $f)  $actions=$f($actions);   <-- our closure
6041      if (is_array($actions) && (sizeof($actions)>0)) {
6052          foreach ($actions as $n=>$action) { $copyActions[$n]=$actions[$n]; ... }       <-- post-filter
6455-6473     foreach ($actions as $n=>$singleaction) { ...insert into actions_issued... }
6479-6481     foreach ($actions as $action) Logger::info("Echoing action to plugin: {$action}");
6483          echo implode("\r\n", $actions)."\r\n";                                         <-- reaches the DLL
```
* The closure receives the **final** array and its return value **is** what gets echoed. Appending is
  therefore sufficient; nothing re-validates the line against `ENABLED_FUNCTIONS` or the catalog.
* `$copyActions` is rebuilt from the post-hook array at `:6052`, so an appended line does not break the
  `actions_issued` insert at `:6465-6470`.
* Our plugin already relies on this (`lib/lrg_actions.php:611-612` appends a `TravelTo` line), so the
  mechanism is in the codebase - it has simply never been exercised in game.

### 7.2 It also works when the LLM emitted NOTHING
`openrouterjson::processActions()` returns `[]` when the reply carries no usable action
(`/var/www/html/HerikaServer/connector/openrouterjson.php:1150-1153` `Logger::info("No actions"); return [];`);
a speech-only JSON reply reaches `queueFunctionExecutionCommand()` which returns `false` for `Talk` /
unknown functions (`/var/www/html/HerikaServer/functions/functions.php:2507-2530`) and leaves the
buffer empty (`:1163` returns the empty `_commandBuffer`). Either way the closure still runs (the
`sizeof>0` test at `:6041` happens **after** the hooks), and a one-element return is echoed normally.
Our own closure already normalises a `null` to `[]` (`functions.php:17-19`).

### 7.3 The exact line to append
Format (`/var/www/html/HerikaServer/functions/functions.php:2542`):
`"{Actor}|{channel}|{CodeName}@{param}\r\n"`.
Channel for our rows = **`command`**: `herikaActionCatalogGetConfirmationCommandChannel()`
(`/var/www/html/HerikaServer/lib/core/action_catalog.php:414-447`) returns `'approvedcommand'` only when
`custom_config.confirmation_policy === 'automatic'` was saved in the web editor; our rows only set
`metadata.confirmation.default_policy = 'automatic'` (`lib/lrg_actions.php:65`), so `$selectedPolicy`
stays `'default'` and the function falls through to `return ... : 'command';`. That matches
PROTOCOL.md:51 and the observed wire lines.

So the safety net emits, verbatim:
```php
$out[] = $turn['npc'] . '|command|' . LRG_ACT_CONTROL . '@'
       . lrgKv(['ok' => 1, 'cid' => $turn['cid'], 'npc' => $turn['npc']] + $kv) . "\r\n";
```
i.e. exactly what `$emit()` (`lib/lrg_actions.php:556-561`) builds today, except that the actor and the
channel are synthesised instead of copied from an existing line. `lrgKv()` already strips `; = @ | "`
and newlines (`lib/lrg_core.php:122-129`), which satisfies the "no double quotes in params" rule.
Dedup: the DLL de-duplicates identical command lines for 5 s (PROTOCOL 2); our `cid` is per turn, so a
net-fired line can never collide with the LLM's own.

### 7.4 What the closure can see when it decides
* `$GLOBALS['LRG_TURN']` - mode, scene, options, ctx, offered, cid (`lib/lrg_actions.php:483`).
* **`$GLOBALS['talkedSoFar']`** - the sentences she actually spoke this turn. `call_llm_internal()`
  declares it global (`lib/data_functions.php:5745`) and `returnLines()` fills it
  (`lib/chat_helper_functions.php:1287` `global ... $talkedSoFar`, `:1635` `$talkedSoFar[] = $responseText;`).
  It is populated **before** the post-process hooks run (`:6018-6023` -> `:6036`). This is the
  refusal-marker source for G1 and the "did she say anything at all" source for G3(b)/R10.
* `$GLOBALS['DEBUG_DATA']['response']` - the raw and processed reply chunks (`:5971`, `:6018`), a
  fallback if a connector ever bypasses `returnLines()`.

### 7.5 Why the alternatives are worse
* **`prepostrequest` / `postrequest` ext hooks**: they run at `main.php:2944-2946`, i.e. **after**
  `echo 'X-CUSTOM-CLOSE'` (`main.php:2920`) and after `ob_end_flush()`; the DLL treats that marker as
  the end of the response, so a line echoed there is lost. Use them only for bookkeeping.
* **A command queue table the game polls**: CHIM does have that pattern - the quest engine's
  `skyrim_quest_action_outbox` (`lib/chim_quest_engine.php:1454`, fetch at `:3296`/`:3431`, ack at
  `:3448`) - but it needs a game-side poller and an ack round trip we do not have, and it would add
  latency to a voice command. Not needed for G1.
* **A second LLM call** (follow-up): explicitly off for our rows (`lib/lrg_actions.php:66`,
  `processor/funcret.php:131-136`) and would cost a TTS turn under the MAIN lock.

### 7.6 Preconditions the design must respect
* The chain only runs when `FUNCTIONS_ARE_ENABLED` is true for that request. Player speech
  (`inputtext*` / `ginputtext*`) is whitelisted at `main.php:1077-1079`, so G1's scope (a running scene,
  the player speaking) is always covered. On a `lrg_scenetalk` **non-lead** turn functions stay off
  (our `lrgPrerequest()` re-enables them only for lead ticks, `lib/lrg_actions.php:332-343`), so the net
  cannot fire there - which is correct, since those turns are speech-only.
* `$outputWasValid` must be true: if the connector errored, no actions are echoed at all.
* The net must re-run the same rails the gate runs (`scene_blocked`, ceiling/`too_soon`, stop-only)
  before appending - i.e. build the `kv` through `lrgResolveControl()` / `lrgResolveClothing()` exactly
  as the LLM path does, so a net-fired action can never exceed what the LLM was allowed to choose.
* Per the owner's rails: the net must never emit `ExtCmdLRG_StartIntimacy`, never act outside a running
  scene, and must be skipped when a decline was recognised in `talkedSoFar` or a `Decline` action was
  emitted.

---

## 8. Earliest point where the player's utterance and the request type are available

**`ext/lorerim_glue/preprocessing.php`, i.e. `main.php:193`** - and that is already our hook.

| step | line | what is available |
|---|---|---|
| `requireFilesRecursively(ext, "globals.php")` | `main.php:54` | **too early** - `$gameRequest` does not exist yet |
| `$gameRequest = explode("|", $receivedData);` | `main.php:135` | `[0]` type, `[1]` localts, `[2]` gamets, `[3]` text |
| whisper/close/shout rewrite of `[3]` | `main.php:181-190` | the text is rewritten for `inputtext*` / `ginputtext*` |
| **`requireFilesRecursively(ext, "preprocessing.php")`** | **`main.php:193`** | **`$gameRequest[0]` and `$gameRequest[3]` in final form, before the MAIN semaphore (`main.php:~243`)** |
| `prerequest.php` | `main.php:1117` | same, plus `HERIKA_NAME`, plus `FUNCTIONS_ARE_ENABLED` already narrowed by the whitelist at `:1077-1079` |
| `functions.php` (our `lrgPrepareTurn`) | `main.php:1615` | same, plus `ENABLED_FUNCTIONS` |
| `context_pre.php` | `main.php:2540` | same, plus the prompt assembly point |

Notes for the recogniser:
* In the hook files `$gameRequest` is in local scope (`preprocessing.php:13` uses it directly);
  everywhere else use `$GLOBALS['gameRequest']`.
* The text may carry a `"<Speaker>: "` prefix and the DLL's `"(Context location: X)"` prefix -
  `lrgStripContext()` (`lib/lrg_core.php:132-135`) handles the latter; the former is stripped by CHIM
  only for `instruction`/`suggestion` (`main.php:1092`, `:1101`), so the recogniser must strip it
  itself (the same `/^[^:]{1,40}:\s*/` pattern `lrgIsTickText` already uses).
* Doing the recognition in `preprocessing.php` costs nothing (before the lock) but the result must be
  stashed in a `$GLOBALS['LRG_...']` for the later hooks, exactly as `LRG_INITIATIVE` is today
  (`lib/lrg_actions.php:206`). Recognising it in `lrgPrepareTurn()` is equally valid and keeps
  everything in one place; the utterance is unchanged between the two points.

---

## 9. Server-side ordering facts relevant to G3(a) "she speaks before she moves"

* Spoken sentences are streamed to the game **as they are parsed**, during the LLM stream
  (`returnLines()` at `lib/data_functions.php:6021`, and per-sentence inside the stream loop at
  `:5975-5977`).
* The action line is echoed **once, at the very end of the response body** (`:6483`), after all speech
  lines and after the post-process chain.
* So on the wire the order is already "speech first, command last". Whether the *game* waits for the
  TTS to finish before executing the command is a game-side question (out of scope here) - the server
  cannot make that guarantee, it can only guarantee that a command is never sent **without** a line,
  which is the post-LLM gate's job (7.4).
* One related side effect the design should keep in mind: `context_pre.php:26-29` sets
  `SCRIPTLINE_ANIMATION_SENT = true` on scene turns to stop CHIM attaching an expressive idle - that
  logic keys on `$turn['scene']` / `lrgGetActiveScene()`, so a stale scene row (section 4) also
  suppresses idles outside a scene.

---

## 10. Small, concrete corrections the design stage should schedule

1. `lib/lrg_actions.php:885` and `:914-918` - delete "can simply happen, message left empty" for
   BeginIntimacy; replace with "say one short line first that makes plain what you are about to do".
2. `:1022-1025` - the `$unless` clause is the direct cause of complaint 2; the "[silent]" branch must
   disappear entirely under addendum #2, and a player request must be answered **with** the action, not
   instead of it.
3. `:1026-1028` - ChangeClothing must move up next to the ChangeIntimacy block and lose "only if $npc
   wants to" for player-requested undressing (consent default yes per act, G1).
4. `:992` - "or declines what does not suit them - then nothing changes" needs a machine-readable
   Decline action instead, otherwise the safety net cannot tell a refusal from laziness.
5. `config/lrg_config.default.json:245` `announce_chance` - under the revised R10 this can no longer
   gate *whether* she speaks; repurpose it (bluntness) or drop it.
6. `lib/lrg_core.php:62` - timezone-independent log stamp (section 5).
7. `lib/lrg_actions.php:250-264` - close the scene row on `Error: no scene is running` (section 4).
8. `lib/lrg_actions.php:486-491` - the two `turn ...` log lines are the natural place for G6's fields
   (utterance excerpt, intent + confidence, `offered` + why hidden, chosen action, net fired).
9. `LRG_ACTIONS_VERSION` must go to 8 when a row's description or `request_types_any` changes
   (`:24`, `:43` marker file), and `manifest.json` to 0.3.0.
10. `:1016-1019` - the option block is ~60 % of the injected text; if a `RequestAct` row lands (G5.2),
    shrinking this list is the cheapest way to pay for the new directive without raising token cost.
