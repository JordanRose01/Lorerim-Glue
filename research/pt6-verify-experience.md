# pt6 verify - the OWNER'S EXPERIENCE lens (independent verifier)

Read-only verifier. Nothing outside this file was written. Probes were run from a staged copy in
`%TEMP%\lrg_test` and deleted again; the project tree is untouched. Nothing was deployed or installed.

**VERDICT: FIX FIRST.** Findings 1-3 each reproduce one of the owner's own playtest-6 complaints.

Addenda: `glue/OWNER_ADDENDA.md` was read FIRST. Item 3 (no in-scene refusal) is honoured in code -
no `Decline` row, no refusal-marker scan, no per-act decline memory anywhere in the plugin
(`_prop_no` is R11's "the player said no to HER proposal", which addendum 3g keeps).

---

## 1. What I confirmed myself (not from the build reports)

| claim | verdict |
|---|---|
| offline tests | Reproduced exactly in WSL (PHP 8.2.28) from a fresh staged copy: `php -l` clean on every plugin/tool file; `test_intent.php` **105 passed / 0 failed**; `test_gates.php` **206 / 0**; `test_scene_index.php` **ALL CHECKS PASSED**; `run_flows.php --strict` **32 scenarios, 32 passed, 0 FAILED, 644 checks, 0 warnings** |
| `lrgSpokenThisTurn()` sees her words at post-gate time | TRUE, verified in the installed HerikaServer: `returnLines()` fills `$talkedSoFar` (`lib/chat_helper_functions.php:1635`) and is called at `lib/data_functions.php:6027`, before `action_post_process_fnct_ex` runs at `:6036`. R10's drop will not misfire on every turn |
| CHIM really emits both announce events | TRUE: `CHIM_SpeechStarted` per SENTENCE (`AIAgentAIMind.psc:1719` FakeDialogueWith, `:1790` FakeDialogue), `CHIM_SpeechStopped` (`:1845 EndDialogue`, driven from `AIAgent.dll`'s Speaker Manager) |
| MCM completeness | All **35** control ids in `config.json` have a default line in `settings.ini` (checked mechanically). No setting can silently read FALSE/0 |
| versions | `LRG_Main.CurrentVersion = 300`, `manifest.json 0.3.0`, `LRG_ACTIONS_VERSION 8`; all six `.pex` are newer than every `.psc` (15:47 vs 15:04) |
| G6 timestamps | Fixed properly: `lrgLog()` formats `lrgNow()` in an explicit `DateTimeZone` and always prints the UTC offset; `date_default_timezone_set()` is never called. Default `America/New_York` = the owner's own clock |
| added prompt size | outside a scene **1,753 chars** (static 994 + volatile 759, ~440 tokens); in a scene **2,672 chars** (~670 tokens). Directive included, last in the block |
| added latency | Server work per in-scene turn **~13 ms** (avg of 6 utterances, worst 15.7 ms). **No extra LLM request**: held lead ticks and dropped initiative ticks die pre-lock; the safety net line and the hold carrier only add a `funcret` round trip that ends without an LLM call (`followup.enabled=false`) |
| prompt tone | Blunt and un-softened as the owner asked. Level-2 wording is explicit; act names are explicit; the BANNED list kills the poetry. Rendered directive, verbatim from a probe: `<player_request>The player just asked for this: vaginal sex. Do it now: choose RequestAct with item "vaginal", and answer in your own voice in the same reply. Do not refuse, stall, negotiate or ask whether it is a good idea.</player_request>` |

---

## 2. Findings

### 1. MAJOR - both (fix game side). After ~90 s of NPC silence a scene turn goes BLOCKED and every spoken request except "stop" is silently lost
**Evidence (probe, reproduced):** with a fresh snapshot, `"get naked"` -> `intent undress/high`, and the
safety net carries it out with **no** LLM action at all. Advance 95 s with no new snapshot and repeat the
same words: `blocked=true offered=ExtCmdLRG_SceneControl`, net does not fire, request gone.

Why it happens: `lrg_actions.php:600-628` demands `_age <= snapshot_max_age_seconds` (90,
`lrg_config.default.json:12`); `scene_confirmed` (`:645`) and therefore the whole safety net
(`lrgSafetyNet` `:1325-1326`) hang off the same snapshot. But the only snapshot forced during a scene is
the one at thread start (`LRG_OStim.psc:2711`). `LRG_Main.SnapTick` refreshes only while
`fresh = crosshair on her || she spoke < 90 s ago` (`LRG_Main.psc:575`, `:591-597`), and the in-scene 5 s
tick (`LRG_OStim.psc:2941-3005`) re-asserts `setAnimationBusy`, the listener and the cold exemption but
never takes a snapshot. `fLeadHold` is 150 s > 90 s, so the ordinary rhythm "player asks -> she answers ->
quiet stretch -> player asks again" lands in the gap. The owner's reply then refreshes it, so the **worst
case is 2 tries, not 1** - which is exactly playtest 6's "she wasn't really responding to my commands, it
took a couple of tries".

The whole flow suite is blind to this: `fxSay()` sends a fresh snapshot before every utterance
(`tools/flows/adapter.php:292-296`).

**Smallest fix (game):** inside `ThreadTick`'s 4.5 s branch add `Main().MaybeSnapshot(partner, false)`.
`MaybeSnapshot`'s own 10 s-per-NPC throttle caps the cost, `lrg_npcstate` is a pre-lock fast message, and
nothing on the wire changes. (A server-side softening is the wrong fix - it would weaken the adults-only
fail-closed rail.)

### 2. MAJOR - server. "You lead / surprise me" holds her for 150 s instead of handing her the lead
**Evidence (probe, reproduced):** the player says `you lead` inside a scene; the emitted line is
`...|ExtCmdLRG_SceneControl@ok=1;cid=..;npc=..;do=lead;who=npc;hold=150`.
`LRG_OStim.CmdLead` clears `leadHoldUntil` and then re-applies whatever `hold=` the same command carried
(`LRG_OStim.psc:2588-2593`) - which is the hand-over the game build report wrote down ("do not decorate a
`lead;who=npc` that answers a `lead_npc` intent with a long `hold=`"). The server never implemented it:
`lrgDecorate()` (`lrg_actions.php:1222-1242`) and `lrgHoldSeconds()` (`:1245-1254`) have no exemption. The
server clears its own clocks correctly, but the GAME sends no lead tick for 150 s, so she does nothing.
Flow 20 only asserts the server clocks (`20_lead_hold.php:39-40`) and never inspects the emitted `hold=`.

**Smallest fix (server):** in `lrgDecorate()`, skip `hold` when
`$do === 'lead' && ($kv['who'] ?? '') === 'npc' && ($turn['intent']['kind'] ?? '') === 'lead_npc'`.

### 3. MAJOR - server. The initiative cue still licenses the empty message that R10 forbids, so her own approach dies in the gate
`prompts.php:57` still tells her: *"an action with an empty message, or one short plain spoken sentence"*.
That cue is the LAST text in the prompt. On an admitted initiative tick `BeginIntimacy` **is** offered
(`lrg_actions.php:875` - the tick is itself the build-up), and a `StartIntimacy` with no spoken line is
dropped (`lrgNeedsSpokenLine` `:1176`, drop at `:1066`). So the most likely outcome of "she walks over and
makes her move" is: a full LLM + TTS turn paid, she says nothing, nothing happens, and the re-ask arrives
next turn. That reads as the older "NPCs won't act" complaint.
PROTOCOL 6.5 states the licence "is deleted everywhere" - this is the one place it survived.

**Smallest fix:** delete `an action with an empty message, or ` from the `lrg_initiative` cue.

### 4. MAJOR - server. Two contradictory orders in the same prompt when she has something to ask for
**Evidence (probe, reproduced):** with a pending R11 proposal the notes say *"... asks for it in her own
words - one short sentence - and **chooses no action this turn**"* (`lrg_actions.php:1798`) while the
directive in the same block says *"**Do it now**: choose ChangeIntimacy with item "faster" ... Do not
refuse, stall, negotiate"* (`lrg_intent.php:462`). Probe output:
`propose={"act":"fingering:you",...} | notes_no_action=YES | directive_order=YES`.
`lrgPickProposal()` steps aside only for intent kinds `act`/`yes` (`lrg_actions.php:898`), so every other
request (`faster`, `undress`, `furniture`, `climax`, ...) can collide with it. The safety net still carries
the request out, so the pace does change - but she answers with an unrelated proposal, and to the owner
that is "she ignored me".

**Smallest fix:** in `lrgPickProposal()` return `null` whenever `$turn['intent']['kind']` is in
`LRG_INTENT_ACTIONABLE`, not only for `act`/`yes`.

### 5. MAJOR - game. The announce gate only accepts a sentence that STARTS after the command arrives
`AnnounceState()` computes `started = partnerSpeakStart > afSince` with `afSince = cmdAt` and no look-back
(`LRG_OStim.psc:490`), and for `wait=end` returns 0 while `!started` (`:500-502`). The guidance asks for ONE
sentence (`scene_talk.max_chars` 120), so there is normally exactly one `CHIM_SpeechStarted`. If CHIM
delivers the command while - or after - her line is already playing, that event never comes and **every
glue-started scene burns the whole `fSayFirstMaxWait` (12 s) of dead air** before OStim's fade.

Which order really happens could not be settled without a game, and both are live: HerikaServer echoes her
line first (`lib/chat_helper_functions.php:1841`, after server-side TTS generation) and the action lines
afterwards (`lib/data_functions.php:6036` -> `~:6479`); in the game the speech line goes through the DLL's
Speaker Manager queue + `DownloadAndPlay` while `ExtCmd` dispatch is immediate - which probably wins, but
"probably" is not a guarantee the owner should pay 12 s for. The budget is also tight even in the good
case: download + a 120-char line + `fSayFirstSettle` can reach 12 s, and a timeout then lets the fade cut
across her - the one thing G3a exists to prevent.

**Smallest fix (game):** `bool started = partnerSpeakStart > (afSince - 3.0)` (a 3 s look-back is far
shorter than a CHIM round trip, so it cannot pick up the previous reply), and raise the
`fSayFirstMaxWait` default from 12 to 20. `LogWait`'s `reason=timeout` already makes this measurable in
playtest 7 - read it first.

### 6. MINOR - server. `command_confirm_seconds` contradicts the game's hand-over
`lrg_config.default.json:221` sets 20; the game build report requires `>= 20 + fSayFirstMaxWait` (32 with
today's defaults), because a parked command's `funcret` only fires when the OStim call really happens.
Result: false `WARN command not confirmed by the game` lines in exactly the log G6 exists to clean up.
**Fix:** 35 (and keep it above `fSayFirstMaxWait` if that is raised per finding 5).

### 7. MINOR - server. A silent `SuggestPrivacy` is not dropped
PROTOCOL 6.8 and R10(c) list `SuggestPrivacy` among the moves that must not happen without a spoken line,
but the INVITE branch records the invitation directly (`lrg_actions.php:1132-1144`) and never goes through
`$emit`, so `lrgNeedsSpokenLine` / `lrgSpokenThisTurn` never run. The owner hears no invitation, yet the
server flips to mode `follow` and she makes her move the next time they are alone. Neither flow 14 nor
flow 21 covers it (no `Invite` / `privacy` assertion in either file).
**Fix:** in that branch, `if (lrgSpokenThisTurn() === '') { lrgMemSet(.., ['say_first' => ..]); log; continue; }`.

### 8. MINOR - server. Very generic act words can produce a high-confidence false positive inside a scene
`lrgActsTable()` gives `grope` the words `touch` / `feel`, `oralvulva` the words `mouth` / `taste` / `eat` /
`lick` / `oral` / `tongue`, `hold` the word `arms` (`lrg_scene_index.php`, acts table). A polite frame makes
those high confidence (`lrg_intent.php:63-65`), so *"can you feel that?"* is recognised as a request and the
safety net changes the position on a rhetorical question.
**Fix:** drop `feel`, `taste`, `mouth`, `arms`, `neck`, `inside` from `words`; `lrgFindSceneByText()` still
reaches those scenes from plain words.

### 9. MINOR - server. Any utterance containing "don't" is discarded whole
`lrg_intent.php:73` blocks on `don't|do not|never|...` across the entire utterance, so *"don't stop, harder"*
loses the "harder" (the stop rail correctly does not fire). Fail-safe, but it costs the owner a request.
**Fix:** apply the negation test to the matched fragment, or exempt an utterance that also carries a clear
imperative.

### 10. MINOR - server. A first meeting is treated like a reload
`lrg_core.php:193-195` stamps `session_at` on the first snapshot an NPC ever gets, so
`post_reload_cooldown_seconds` (120) suppresses her own first move and her first initiative tick on a
first conversation too. Harmless but unintended.

### 11. MINOR - docs. `glue/README.md` is still "LoreRim Glue 0.2.0 ... playtest-4 build"
No 0.3 MCM settings, no new config keys, no acts, no intent/safety net. It is the INTEGRATOR's file
(PROTOCOL 9), so this is a note for the later step, not a builder defect - but it is the page the owner
reads.

### 12. MINOR - docs. `scene_progression._readme` still says "the NPC may still decline"
`lrg_config.default.json:317`, against owner addendum 3b. Readme text only (never sent to the LLM), but it
is what the owner reads when tuning.

### 13. MINOR - docs / owner action. Nothing in this round surfaces the real cause of "director mode"
`research/pt6-game-audit.md:112-130`: there is no OStim settings file anywhere under `F:\Modlists\LoreRim`,
so OStim runs on plugin defaults - **free cam at scene start is ON** (escape: numpad `/`), and
`OStimUseFades = 1` is the "makeout animation cutscene". That is almost word for word the owner's "it
seemed to put me in director mode ... i had really no control". The glue may not write OStim settings
(forbidden rail), so it can only be an owner action - and it is currently written down nowhere the owner
will see it (`grep` for free cam across `glue/` finds nothing).

---

## 3. The round played through, as the owner will live it

**(1) Flirting in a private room.** Turn 1 ("You look beautiful tonight") matches `start.heat_pattern` ->
heat 1 -> `BeginIntimacy` and `ChangeClothing` are hidden with a logged reason `Start(heat 1<2)`, and the
notes add "a question about this is a question ... does not start anything physical this turn". Turn 2
("I want you") -> heat 2 -> the action is on the table. So: **not trigger-happy** (playtest 6's 11-second
start is closed by heat + the 120 s post-reload cooldown) and **not passive** (two exchanges, or one
direct request, or an invitation, or an initiative tick). When she starts, her line is guaranteed
server-side: a `StartIntimacy` with `lrgSpokenThisTurn() === ''` is dropped, remembered as `say_first` and
re-asked next turn - never patched with a canned line. Game-side the start is parked until her line is
finished (`wait=end`) and started anyway when the budget runs out, so a scene is never lost. Two caveats:
finding 5 (up to 12 s of dead air, or a fade across her line if TTS is slow) and finding 3 (on an
initiative tick the cue still invites the empty message that gets the action dropped).

**(2) "get naked" in a running scene.** With a fresh snapshot: **worst case 1 try** - confirmed by probe.
Recognised `undress/high`, the directive names `ChangeClothing` with item `undress`, and if the model
answers with no action at all the safety net emits it. With a stale snapshot (finding 1): **2 tries**.

**(3) Acts by name.** Reachable only by a warp: the act layer only walks routable scenes, but
`lrgIntentResolve()`'s text refinement can still land an unroutable scene, and the server then sends a
plain `do=goto` (no `warp=1`), so OStim's `NavigateTo` warps silently - the position is reached, no fade,
no refusal. Not reachable at all: `cant` -> the CANT directive, she says so plainly and names up to three
alternatives, the net stands down. "Declined earlier" no longer exists inside a scene (addendum 3b), and
an act the PLAYER said no to when SHE proposed it only stops HER proposing it again - his own request for
it still works. All correct. One rough edge: the CANT directive ends "Choose no action this turn" while
the notes two lines above say "Anything the player asked for: do it AND answer" - the directive is last,
so it should win, but the pair is avoidable.

**(4) Silent for five minutes.** Lead ticks every ~75 s of stillness, dropped pre-lock while a hold runs
(zero tokens), never back into a visited scene, and for a SEX act she asks first (>= 75 s at the current
act, cooldown 120 s, max 3 per scene) and waits for a yes. That is neither autopilot nor nagging. Two
dents: after she asks, a silent player freezes her for the proposal's 90 s ttl; and if she stays quiet
past 90 s the snapshot goes stale (finding 1), so the first thing the owner says on breaking the silence
can be lost.

**(5) Reload mid-scene, talk at once.** Three independent paths now close the row: the game's own
`ev=end` when the partner survived the save, the new session tag on the first snapshot (closes **every**
open row, name-free), and a `funcret` of exactly `Error: no scene is running`. Playtest 6's 19:33:54
"Error: no scene is running" is closed. The only hole left is a reload where the partner name did not
survive **and** the crosshair is on nothing - then the row waits for the first snapshot, which the first
NPC the owner looks at supplies.

**(6) Prompt texts.** Short, plain, blunt, explicitly anti-poetic, and level 2 is genuinely crude. Act
labels are explicit. What fights: finding 4 (proposal vs directive - reproduced), finding 3 (empty-message
licence vs the "never an empty message" rail), and the CANT/"do it anyway" pair above. In mode `private`
with the action hidden, the guidance still says "if she declines ... choose neither action" although no
action is offered - harmless noise.

**(7) Size and latency.** ~440 added tokens outside a scene, ~670 inside one; ~13 ms of server work per
turn; **no additional LLM request anywhere** - held lead ticks and dropped initiative ticks end before
CHIM's MAIN semaphore, and the safety net / hold carrier only cost a `funcret` that terminates without a
generation. The hold carrier is throttled to once per 75 s.

---

## 4. What the owner really has to do by hand

1. **OStim MCM -> free cam at scene start OFF** (or press numpad `/` every scene). This is the actual
   "director mode"; optionally turn the intro/outro fades off too. Nothing in the glue can do it.
2. **OStim MCM -> Undressing**: `Remove Weapons at Start`, `Undress Mid-Scene`, `Partial Undressing` off -
   needed **only** for scenes started from OStim's own menu; the glue handles its own scenes itself.
3. **CHIM web UI**: Global settings -> Global Connectors -> **Scene Classifier OFF**; Relationship editor ->
   **unlock** the relationship of any NPC he wants to court (Lisette was locked, so interest never moved);
   Action Editor -> leave **Require Confirmation OFF** on the glue actions (now **five** - `RequestAct` is
   new this round); keep every Google model out of all slots.
4. **Run the two installers before playtest 7** - neither builder ran them: `tools/deploy_server.ps1`
   (server) and `tools/install_mo2.ps1` (game, with MO2 **and** Skyrim closed). Deploying only one half is
   safe by design, but then the new behaviour is only half live.
5. Nothing else. The glue's MCM explicitness slider (default 2) is the content control.
