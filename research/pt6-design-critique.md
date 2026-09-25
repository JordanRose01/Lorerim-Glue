# pt6 design critique - adversarial review of `glue/V03_DESIGN.md`

Status: COMPLETE. Role: adversarial design critic, read-only apart from this file.

Read in full: `glue/OWNER_ADDENDA.md` (3 addenda), `glue/V03_DESIGN.md` (1041 lines),
`research/pt6-forensics.md`, `research/pt6-server-audit.md`, `research/pt6-game-audit.md`.
Code spot-checked on disk: `lib/lrg_core.php`, `lib/lrg_actions.php`, `lib/lrg_scene_index.php`,
`preprocessing.php`, `prerequest.php`, `functions.php`, `context_pre.php`, `prompts.php`,
`LRG_OStim.psc`, `LRG_Main.psc`, `MCM/.../settings.ini`, `glue/PROTOCOL.md`, and CHIM's
`AIAgentPapyrusFunctions.psc` / `AIAgentMCMConfigScript.psc` / `AIAgentFunctions.psc` for
`setDrivenByAIA`.

**Verdict: APPROVE WITH AMENDMENTS.** The design is unusually well grounded - every line
reference I checked in `lrg_actions.php` (`:885`, `:914-918`, `:966-969`, `:977-982`, `:992`,
`:1022-1028`, `:611-612`) is accurate, and the diagnosis-to-mechanism chain is sound. But four
defects would ship a broken or unsafe round, and ten more would cost a playtest each. The
amendment table at the end is binding for the builders.

Owner addendum 3 arrived before this stage and §0.2 handles it correctly; addendum 2 (revised
R10) is handled in §8/§4.5. Nothing in this critique re-introduces an in-scene refusal.

---

## 1. Do the owner's five complaints become impossible, or only less likely?

Walked step by step through the specified mechanism. Verdict per complaint.

### 1.1 "I told her to get naked and she wasn't listening" - **IMPOSSIBLE, once C1-C4 are fixed**

Chain, for the exact playtest-6 turn (19:36:10, `inputtext`, " Take off your clothes. "):

| step | mechanism | holds? |
|---|---|---|
| utterance reaches the server | `inputtext` -> `main.php:193` | yes (F1) |
| pre-lock hold clock + log | §3.7 | **NO - C2**: `preprocessing.php:14` only dispatches `lrg_*` / `funcret`; the hook is never called for `inputtext` |
| recognised `undress/high` | §3.3 row 15 + frame 1 + frame 4 | yes |
| ceiling not in the way | §6.6 | yes for clothing (clothing is not tier-gated) |
| directive names `ChangeClothing` + `undress` | §4.2 | yes |
| the three prompt passages that caused the refusal are gone | §4.4.1/.2/.3 | yes - verified those are the live texts |
| LLM picks it | probabilistic | ~coin flip (forensics: 1 of 2 in two playtests) |
| net carries it out | §5 | **NO - C4**: precondition 3 (`scene row _age <= 60 s`) is false most of the time |

So the deterministic half works only after C2 and C4. With both fixed this complaint is
genuinely **impossible** for any utterance that reaches the NPC: the net fires whatever the LLM
answers, and `lrgResolveClothing` / the game's `CmdClothing` path was proven working
(18:48:34-35). The residual is the routing problem (§1.4) and empty STT (2 of 12 uploads), both
outside the net's reach by construction.

`I want you to take my clothes off.` (the who-inversion) is genuinely fixed by §3.4 - I checked
`lrgResolveClothing():762-775` and confirmed the design's reading: today `\byour clothes\b` and
`take my clothes off` both resolve to `npc`, because that resolver is written for the *model's*
item. A separate `lrgIntentClothingWho()` is the right call.

### 1.2 "it took a couple of tries" - **LESS LIKELY, not impossible**

Four independent loss channels from the forensics; the design closes two:

| channel | pt6 rate | after v0.3 |
|---|---|---|
| prompt told her not to obey | 8 offers / 0 uses | closed (§4.4 + net) |
| wrong act chosen for the right request | 1 of 6 ("doggy style" -> a kiss) | **still open - M2**: precondition 5 counts *any* `do=goto` as satisfying an `act` intent |
| narrator routing | 5 of 13 | mitigated only if `setDrivenByAIA` works (M8); unproven |
| empty STT | 2 of 12 | logged, not fixed - the player still gets silence |
| command lost server->game | 1 of 10 | measured only (§12.4), by design |

Expected residual with the amendments: ~2 of 13 (STT) plus whatever routing costs. Without M2 the
"several tries" feeling survives in its most infuriating form - the scene changes to something
the player did **not** ask for, which reads as "she ignored me" plus "and did something random".

### 1.3 "flirting / sexual questions auto-started the makeout" - **IMPOSSIBLE**

19:28:59 walked through §11.2: kind `none` (question blocker, §3.5 - the utterance starts with
"Tell me," but contains `what do you picture`, and §3.5's `question` rule fires on the leading
`what`-family word; see m14 for a wording risk here) -> rule 1 fails; mode `private`, not
`follow` -> rule 2 fails; `session_at` 14 s < 120 and `heat` 0 < 2 -> rule 3 fails. `START` is
hidden, `hidden=Start(post-reload 14s<120)` is logged, and the block says "a question about this
is a question". The action is not in the enum, so the model *cannot* pick it. Impossible, not
unlikely. Good.

19:35:43 ("I want you to get naked.") still offers `START` via rule 1 - correct per the owner
(an explicit request for contact is always offered at once), and now she must speak first
(§8.1/§8.3) and the game holds the fade until her line is out (§14.5). That is exactly addendum 1.

### 1.4 "director mode / no control" - **PARTIALLY FIXED; the biggest residual risk in the round**

Three causes, three different fates:

1. **OStim free cam** (`OStimUseFreeCam` ships 1). Correctly identified as the owner's own MCM
   setting and left alone (§17.9). Nothing in v0.3 changes it - so if this was the dominant
   cause, playtest 7 will feel identical. The design should say this louder: **tell the owner to
   turn it off before playtest 7**, or the round will be judged on a setting it deliberately did
   not touch.
2. **She changes the position every ~50 s** - fixed by lead interval 75 s + hold + no-circling.
   Over-fixed, in fact: see §3 below and M6.
3. **Narrator routing** (5 of 13 utterances). §14.6 rests on `setDrivenByAIA` doing something it
   has not been observed doing - see M8. Server-side, §3.7's hold clock still advances from a
   narrator turn, which is the right consolation prize, but it depends on C2.

### 1.5 "stale scene after reload" - **IMPOSSIBLE** (four paths, and path 1 alone suffices)

Path 1 (§10.1, funcret `Error: no scene is running` closes the row) is correct and cheap; I
verified `lrgRecordResult()` already parses exactly the fields it needs and that the game really
answers that string from `CmdControl:1609-1611`. Path 2 (`sess`) is the right name-free
backstop. Two implementation defects: M3 (the `ev=sync` shape is *not* backward-compatible and
has no addressee) and M4 (`lrgGetActiveScene()` returns one row, so "close every active row" and
"close this NPC's row" both need a different query).

---

## 2. Safety: can the net do something it must not?

I attacked precondition by precondition. Summary: the net's *structural* rails are sound - it can
never start a scene (precondition 7 names `START` and `INVITE` explicitly, and mode `scene` is
only reachable when a row exists), never act for a non-adult (`lrgPrepareTurn:388-391` leaves the
turn in mode `silent` when `adult` is not confirmed, so precondition 2 fails), never act under
the kill switch or with SHARMAT present (precondition 1), and never act while a rail blocks the
scene (precondition 2's `scene_blocked`). Dry run is refused game-side. Addendum 3b removes the
refusal check and that is correct - "whatever her words were" is the owner's instruction.

The holes are in **recognition**, not in the rails:

| attack | result today | verdict |
|---|---|---|
| `don't get naked yet` | `negated` blocker | safe |
| `should I take this off?` | `question` blocker | safe |
| `you said you'd take your clothes off` | `quoted` blocker | safe |
| `what if I took your clothes off` | `hypothetical` blocker | safe |
| **`stop teasing me and fuck me`** | kind 1 stop rail fires -> `do=stop`, conf high, net **ENDS THE SCENE** | **C3** |
| **`I can't get enough of you`** | `\benough\b` in the stop rail -> `do=stop` -> net ends the scene | **C3** |
| `don't stop, harder` | `negated` blocker (contains `don't`) -> kind none | safe but a false negative; the "harder" is lost |
| `your mouth tastes like honey` | kind 16 `lrgFindSceneByText` over the whole index may hit an oral scene -> conf **low** -> net does not fire, but §4.2 still writes an imperative "Do it now" | **M10** |
| `yes` said 80 s after a proposal, answering something else | kind 3 -> executes the pending act | m6 (shorten TTL / require it to be the next utterance) |
| net outside a running scene | preconditions 2+3 | safe once C4 replaces the broken confirmation |

**C3 is the serious one.** `lrgResolveControl:684-685`'s stop rail was written for the *model's*
`item`, where "stop" is a deliberate keyword. Re-pointing it at raw player speech, and then
wiring a deterministic executor behind it, turns two very ordinary in-scene sentences into a
scene-ending command. The owner's rail is "stop always works" - it is not "anything containing
the word stop ends the scene".

One more structural point in the net's favour that the design does not claim: because
`lrgPrepareTurn` leaves the turn in mode `silent` (not `scene`) when the snapshot does not
confirm an adult, and because `lrgEvaluateGates` re-runs every rail on a scene turn, there is no
path by which the net can act on a turn the gate itself would have blocked. That rail is real.

---

## 3. Does G3 (+G2, +R11) make her passive again?

**Yes, on the numbers as written.** This is the single biggest behavioural risk in the round and
it contradicts the owner's *earlier* complaint that NPCs would not act.

Playtest-6 cadence: 13 player utterances in 11 minutes ≈ **one every 50 s**.

- §3.7 writes `player_request_at` on **any** player speech turn inside a scene - not only on a
  recognised request. §7.2 then refuses every lead tick for `lead.hold_seconds` = **150 s**.
- At one utterance per 50 s the hold never expires. **Zero lead ticks for the entire scene.**
- R11 needs a lead tick to exist before she can even ask (condition 1: "it is a lead tick"), plus
  `_tier_since >= 75 s`, plus a 120 s proposal cooldown. With zero lead ticks, **she can never
  propose a sex act, ever**, and the only escalation path left is the player asking.
- Outside a scene, §11.2 hides `BeginIntimacy` on her own `lrg_initiative` tick whenever
  `heat < 2` - and `heat` only ever increments on **player speech** turns. A silent player walking
  her into a private room gives her an admitted initiative tick (interest `drawn`, 300 s cooldown,
  dice already passed) on which she has **no action at all** (`lrgKeepOnlyActions($offer)` with an
  empty offer) - an LLM call plus TTS that can do nothing but talk. That is both the owner's old
  complaint and a token cost the design's own priority 2 forbids.

Three amendments (M6, M11 and the R11 re-siting) bring this back to the owner's actual words -
"when the player is passive she may lead" - without re-introducing autopilot. The key insight the
design misses is that **asking is not acting**: R11's proposal changes nothing on screen, so it
does not need its own turn and must not be gated behind one.

---

## 4. Wire contract: ambiguity, non-additive changes, cross-version breakage

Verified against the live parsers. `LRG_Main.ParamGet:409-424` is a tolerant `k=v` scanner and
`CmdControl` / `CmdClothing` read only keys they know, so `hold=`, `nowarp=` and `wait=` are
genuinely ignored by script version 200. §1.3's "old game + new server" cell holds.

The "new game + old server" cell does **not** hold for one message:

- `ev=sync;running=0` on an **old** server falls through `lrgHandleSceneMessage()` ->
  `lrgStoreScene($npc, $kv)` with `ev != 'end'`, i.e. `active = 1`. It *writes a fresh open scene
  row* with no `scene` id and restarts the 900 s staleness clock. A reload would therefore
  **create** the stale-state bug on an old server instead of curing it. See M3.
- The same message carries `npc` possibly empty (§2.1 says so explicitly), but the transport is
  `AIAgentFunctions.logMessageForActor(payload, type, npcName)` - every existing call site passes
  a real display name. An empty addressee is unprecedented and has no defined behaviour. See M3.

Other ambiguities a parallel builder would have to guess:

- **§2.2 contradicts itself on `hold=`**: "the player steered `n` seconds ago; do not fire until
  `now + n`" vs §14.3's `leadHoldUntil = now + hold`. (m2)
- `warp=1` and `nowarp=1` are declared mutually exclusive, but `lrgResolveControl:753` sets
  `warp=1` by itself on the free-text path. `lrgDecorate` must *strip* it, not merely not add it. (m3)
- Whether the net-fired line goes through `lrgDecorate` at all (it must get `hold=`, must never
  get `wait=` / `nowarp=`, and must never be dropped by §8.3). (M14)
- The order of "net line" vs "carrier line" in §5.1/§9 - both append at the end of the same
  closure and §9's trigger is "produced no command at all". (M14)
- `RequestAct` is added as a catalog row (§6.5) but the design never says to add it to
  `LRG_GLUE_ACTIONS` (`lrg_actions.php:25`), to the snake-case display-name map at `:538`, or to
  the kill-switch hide list. Miss any one and G5 silently never works. (m1)

---

## 5. CHIM mechanics

**The safety-net hook is real.** I re-read the plugin's own closure registration
(`functions.php:16-23` registers `$GLOBALS['action_post_process_fnct_ex'][]` exactly once per
filter list) and the existing append branch (`lrg_actions.php:611-612`). The server audit's
`data_functions.php:6029-6041/6483` chain is consistent with both. Appending from the closure is
the right mechanism and needs no new hook.

**Catalog rows and the strict schema** are respected: `RequestAct` uses only `item`, which is one
of the three fields the strict schema permits; `parameters_json` mirrors the existing `$item()`
helper; `available_to_narrator = 0` is inherited from `$common`, which is also why the actions
genuinely do not exist on a narrator turn.

**`LRG_ACTIONS_VERSION` 8** is mandatory and correctly identified - `lrgEnsureActions()` keys on
the `data/.actions_v<N>` marker (`:43`) and also re-installs a row deleted in the web editor
(`:45-52`), so the bump really does re-push the changed descriptions.

**Semaphore collisions** are handled better than the design claims. Dropping the lead tick in
`preprocessing.php` (`main.php:193`, before the lock at `:243`) removes the measured 4-5 s
collision entirely, and `lrg_scenetalk` does start with `lrg_`, so the existing dispatch guard
already routes it - the only hook file that needs editing for §7.2 is none. (The *player speech*
half of §3.7 is the one that needs the guard widened - C2.) One new collision the design creates:
the §9 carrier command produces a `funcret` on **every** player speech turn inside a scene, i.e.
one extra HTTP round trip and one extra short MAIN acquisition per turn, plus two extra log lines.
Throttle it. (m11)

**`setDrivenByAIA` is weaker evidence than §14.6 claims.** Signature
`AIAgentFunctions.psc:58 int function setDrivenByAIA(Actor forcedActor, bool salutation) Global Native`.
Call sites: CHIM's crosshair/control hotkey (`AIAgentPapyrusFunctions.psc:469`) and
`TriggerManualAIActivateAction` (`:835`) - both with `salutation=false`, and both paired with
`setDrivenByAI()` (no args) when nothing is under the crosshair; but *also*
`AIAgentMCMConfigScript.psc:1241` and `:2727` with `salutation=true` when the user **adds an
NPC as an agent**, and `AIAgentAIMind.psc:2281` immediately after `finalActor.Enable(true)` when
**spawning** an agent. Read across all five sites the function looks like "make this actor an
AI-driven agent (optionally greeting the player)", not "set the current listener". It may well
also re-target - CHIM's hotkey behaviour implies it - but the design's "the lever exists and is
CHIM's own" is an over-claim, and §17.1's mitigation does not cover the failure mode that
actually worries me: **a forced agent that is never released**. §14.6 says "Do not call anything
at scene end: the crosshair takes over again by itself" - that is an assumption with no evidence
behind it, and if it is wrong the player's conversations stay glued to the ex-partner after every
scene. See M8.

---

## 6. Papyrus feasibility, game side

Checked each item against the live script.

| item | feasible? | note |
|---|---|---|
| §14.1 lead interval 45 -> 75 | yes | one line in `settings.ini:33` + `config.json` |
| §14.2.1 clear `glueStripped` on redress | yes | the bit is set at `:760`, read at `:758`; clearing it in the `dress` branch does not touch `HoldOStimGates`/`GlueUndressNode` logic |
| §14.2.1 key the mask on `GetActorPosition` | yes | `OThread.GetActorPosition(0, actor)` is already used in `PushState:2408-2412` |
| §14.2.2 prefer `partner` via `IsPartnerName` | yes | mirrors `CmdControl:1628` exactly |
| §14.2.3 `no scene is running` instead of the privacy branch | yes | and it now *closes the server row* (§10.1) - a good interaction |
| §14.3 `hold=` + `leadHoldUntil` | yes | `MaybeLead:2482` returns early in six places already; one more is trivial. `leadHoldUntil` must be cleared in `ResetState():235` and `Maintenance():172` |
| §14.4 load sync | yes, but see M3 | `ResetState()` really does send nothing today (`:235-257`); `AdoptRunningThread` really does not clear `lastSentKey` (`:2052-2082` vs `:2392-2396`) |
| §14.4.3 forced crosshair snapshot | yes | `MaybeSnapshot` + `Watch` are both public on `LRG_Main` |
| §14.4.4/.5 `sess=` / `prev=` | yes | additive string concat in `PlaceFacts` / `PushState` |
| §14.5 `wait=` deferral of `goto`/`furniture`/clothing | yes, **under-specified** | see M9 |
| §14.5 `pendingStart` restructure | yes, largest item | `CmdStart:463-628` sets `partner/partnerName/starting/startCid/startParam` at `:498-506` *before* the weapon prep, so the park point must be **after** `CaptureBaseline:563` and **before** `OThreadBuilder.Create:583`. Everything that must survive the park is a local today (`furnRef`, `sceneId`, `furnScene`, `startNoUndress`) - all storable as script vars; `ObjectReference` survives fine. Do **not** park between `Create` and `Start`. |
| §14.5 `NotePartnerSpeech` | yes | `LRG_Main.OnChimSpeechStarted:440-455` and `OnChimSpeechStopped:457-469` already exist and already forward to `ost.NoteActivity` |
| §14.6 `setDrivenByAIA` | compiles, semantics unproven | M8 |
| §14.7 `nowarp=1` | yes | `CmdControl:1682-1692` and `CmdFurniture` |
| §15 MCM | yes | every new id needs its `settings.ini` line - the design says so and the list is complete |

Two feasibility traps the design does not mention:

- **`setAnimationBusy(1, partnerName)` is asserted at `CmdStart:507`, i.e. before any `wait=end`
  budget starts.** The game audit's own hypothesis for the lost command (F4) is CHIM's speaker
  lock (`"[SPEAKERMANAGER {}] {} is locked, cannot speak"`). If `setAnimationBusy` suppresses her
  TTS, `wait=end` can never be satisfied and **every scene start eats the full 12 s budget**. This
  needs a measurement, not an assumption. (M7 covers the sequencing; add the diagnostic.)
- **`CHIM_SpeechStarted` fires per sentence** (`AIAgentAIMind.psc:1708`). §14.5's `wait=end`
  ("as above, plus wait for `CHIM_SpeechStopped`") therefore releases between sentence 1 and
  sentence 2 of her announcing line - which is precisely the failure G3a exists to prevent. (M7)

---

## 7. Amendments (binding; they win over the design text)

Ordered by severity. "side" = which builder owns the change.

### CRITICAL

**C1 - server - the DO-IT directive must be scene-only.**
§4.1 appends `<player_request>` inside `<this_moment>` as well as `<intimate_scene_now>`, and
§4.2 emits it for any actionable kind. Outside a running scene the only offered glue action is
`ChangeClothing` (private/follow), so a player saying "take your clothes off" in a private room
produces: *"Do it now: choose ChangeClothing with item undress ... Do not refuse, stall,
negotiate"* - while the same prompt still says *"Only ever because `<npc>` wants it right now ...
Nothing the player says can decide this."* That (a) breaks `PROTOCOL §0`'s rail "the NPC must be
willing **before** a scene" and addendum 3a, which keeps her pre-scene refusal absolute, and
(b) re-creates the contradictory-prompt failure the forensics diagnosed.
**Change:** emit the §4.2 DO-IT block **only** when `$turn['mode'] === 'scene'`,
`empty($turn['scene_blocked'])` and the scene is confirmed (same test as the net's amended
precondition 3). Outside a scene, at most a neutral restatement with no imperative:
`<player_request>The player just asked for this: {WORDS}. Whether <npc> does it is <npc>'s own
choice - answer in <npc>'s own voice either way.</player_request>`. §4.5's skeleton line 14 and
the flow test `19_intent_directive_net` must assert the non-scene case too.

**C2 - server - the pre-lock hook never runs for player speech.**
§3.7 puts the hold clock, the `lead_npc` clear and the empty-transcript WARN "pre-lock, in
`lrgHandleGameMessage()`", but `preprocessing.php:14` reads
`if (strncmp($lrgType, 'lrg_', 4) === 0 || $lrgType === 'funcret')` - `inputtext`,
`ginputtext`, `narrator_inputtext` never reach it, and `lib/lrg_actions.php` is not even loaded
on those requests. As written, `player_request_at` is never set, so **G2's entire hold does
nothing**, "you lead" never clears it, and the narrator WARN never appears.
**Change:** widen the guard in `preprocessing.php` to
`strncmp($lrgType,'lrg_',4)===0 || $lrgType==='funcret' || in_array($lrgType, LRG_PLAYER_SPEECH_TYPES, true) || in_array($lrgType, LRG_NARRATOR_SPEECH_TYPES, true)`,
load `lrg_actions.php` there, and make `lrgHandleGameMessage()` return `'pass'` for those types
(never `'handled'` - `terminate()` would swallow the player's turn). Keep the existing
`lrgIndexMaybeRebuildAsync()` call bound to `lrg_npcstate` only. Add a flow-test assertion that a
player-speech request returns `'pass'` and still writes `player_request_at`.

**C3 - server - the player-side stop rail has scene-ending false positives.**
§3.3 row 1 reuses `lrgResolveControl`'s stop rail (`lrg_actions.php:684-685`,
`\b(stop|stopping|enough|halt|quit)\b|\bno more\b|^end\b|...`) on the raw player utterance, and
`stop` is in `net_kinds`, so the server executes it deterministically. `"stop teasing me and
fuck me"` and `"I can't get enough of you"` both end the scene. That rail was written for the
model's deliberate keyword, not for speech.
**Change:** add `lrgIntentStop(string $t): bool`, used only by the recogniser (leave
`lrgResolveControl` untouched for the LLM path). It fires only when the text is a standalone
stop: `^(?:please\s+)?(stop|stop it|stop this|halt|quit)\b(?!\s+\w+ing\b)` ·
`\b(we|let'?s|i)\s+(should|need to|want to|have to)\s+stop\b` · `\b(that'?s|thats)\s+enough\b` ·
`^enough$` · `\bno more\b` · `\bend (it|this|the scene)\b`. It must NOT fire on
`stop <verb>ing`, on `enough` with any preceding `\b(can'?t get|never|not)\b`, or when the same
utterance also carries a REQUEST FRAME plus another actionable kind (then the later kind wins).
Add to `tools/test_intent.php`: `stop teasing me and fuck me` -> `act`/high;
`I can't get enough of you` -> `none`; `that's enough` -> `stop`/high; `stop` -> `stop`/high;
`we should stop` -> `stop`/high.

**C4 - server - the net's "a scene is really running" test disables the net most of the time.**
§5.2 precondition 3 requires the **scene row's** `_age <= intent.scene_confirm_seconds` (60).
`lrg_scene` is event-driven, not periodic (`PROTOCOL 1.2`), and `PushState:2392-2396`
de-duplicates identical state, so `updated_at` only advances when something actually changes. In
a calm stretch - which is exactly when the player is steering by voice - the row is minutes old
and the net silently never fires. Playtest 6 has 60-110 s gaps between pushes while the player
was talking.
**Change:** confirm the scene from the **snapshot**, which is periodic (~20 s, `SnapTick:513`)
and which `lrgPrepareTurn:379-387` already requires to be `<= snapshot_max_age_seconds` for mode
`scene` to be reachable at all. Precondition 3 becomes: `$turn['mode']==='scene'` (which already
implies a fresh, adult-confirmed snapshot for this NPC) **and**
`(lrgGetNpcState($turn['npc'])['ostim'] ?? '') === '1'` **and** `lrg_memory.last_result` is not an
unresolved `no scene is running`. Delete `intent.scene_confirm_seconds`, or repurpose it as a cap
on the **snapshot** age (default = `snapshot_max_age_seconds`). Flow test `19` must include a
turn whose scene row is 300 s old and assert the net still fires.

### MAJOR

**M5 - server - lifting the ceiling to 4 also lifts what she may choose herself that turn.**
§6.6 sets `$turn['progress']['ceiling'] = 4` on a high-confidence `act` turn, and that same
value feeds `lrgSceneOptions()` / `lrgActOptions()` / the ladder sentence, so the LLM is offered
the full sexual tier for *her own* picks on that turn too. Addendum 3e lifts the ceiling for the
**player's request**, not for her pacing.
**Change:** keep two values in `$turn['progress']`: `ceiling` (unchanged, `lrgTierCeiling()`,
drives the offered list and the ladder sentence) and `req_ceiling` (4 on a high-confidence
player act request, else `ceiling`), used **only** when resolving the player's own request and by
the net. §4.4.3's second ladder sentence is then omitted when `req_ceiling == 4`, not when
`ceiling == 4`.

**M1 - server - circular dependency between intent, ctx and ceiling.**
§3.1 says the recogniser needs `$turn['ctx']`; §3.7 says it runs inside `lrgPrepareTurn()`
because of that; §6.6 says the ceiling is set from the intent *before the options are built*;
`$turn['ctx']` contains the ceiling and the act options. A parallel builder cannot resolve this
from the text.
**Change:** specify the order explicitly in §3.7: (1) build a *provisional* ctx with
`ceiling = 4` and no act options - the recogniser must never filter by tier, so `lrgMatchAct()`
and the `lrgFindSceneByText()` fallback run against the unfiltered index; (2) run
`lrgRecogniseIntent()`; (3) compute `ceiling` / `req_ceiling` (M5); (4) build `options`,
`acts` and the final `ctx`; (5) resolve the intent's `kv` against the final ctx (this is where a
`too_soon` or a CANT is decided, §4.3). Add the ordering to the `PROTOCOL §7.2` function list.

**M2 - server - "any `do=goto` satisfies an `act` intent" reproduces the worst pt6 failure.**
§5.2 precondition 5. At 19:36:36 the player asked for doggy style and the model answered
`Change_Intimacy P1` = a kissing scene. Under the rule as written that line "satisfies" the
intent and the net stays out.
**Change:** for kind `act`, "satisfies" means the emitted line's resolved `scene` id yields the
requested act id through `lrgSceneActs()` (role included). Any other `do=goto` does **not**
satisfy it: drop the LLM's line (log `gate: dropped SceneControl - the player asked for <act>`)
and let the net emit the right one. One command per turn stays the rule. Add to flow test `23`.

**M6 - server - the hold silences her for whole scenes.**
§3.7 writes `player_request_at` on *any* player speech turn; §7.2 then blocks every lead tick for
150 s. At playtest-6 cadence (one utterance per ~50 s) that is zero lead ticks per scene, and
R11 (which requires a lead tick) becomes unreachable.
**Change:** two clocks. `player_request_at` is written **only** when the recogniser returned an
actionable kind (any confidence) or a `yes`/`no`; any other player speech writes
`player_spoke_at`. §7.2 refuses a lead tick while
`now < max(player_request_at + lead.hold_seconds, player_spoke_at + lead.speech_hold_seconds)`.
New config `lead.speech_hold_seconds`, default **45** (one lead interval). Log both in §12.2a's
`hold=` field as `hold=<req>/<speech>`. `lead_npc` clears both.

**M11 - server - the heat gate makes her unable to act on her own initiative tick.**
§11.2's rule 3 is the only rule an `lrg_initiative` turn can satisfy, and `heat` only increments
on player speech. A silent player gives her an admitted tick with an **empty** offer
(`lrgKeepOnlyActions($offer)` at `lrg_actions.php:468`) - an LLM call and TTS that cannot act.
That is the owner's older complaint and it violates the design's own cost priority.
**Change:** add rule 4 to §11.2: `!empty($turn['initiative'])` - an admitted initiative tick is
itself the build-up (`lrgInitiativeAdmit` already required interest `drawn`, a 300 s cooldown and
the pace dice). The post-scene and post-reload cooldowns still apply to it:
`START` is offered on an initiative tick when
`lrgNow() - last_scene_end_at >= start.post_scene_cooldown_seconds` and
`lrgNow() - session_at >= start.post_reload_cooldown_seconds`. If those fail, drop the tick in
`lrgInitiativeAdmit()` **before the MAIN lock** instead of paying for a turn that can do nothing.
Flow test `25` must cover it.

**R11 re-siting (part of M6) - server - asking is not acting.**
§7.4 condition 1 ("it is a lead tick") makes her proposal impossible whenever the player is
steering, which is most of the time. A proposal changes nothing on screen, costs no command and
needs no turn of its own.
**Change:** delete condition 1. `$turn['propose']` may be set on **any** turn inside a scene where
`can_act` is true (a lead tick **or** a player speech turn) and conditions 2-6 hold, and on a
player speech turn she answers the player *and* asks in the same reply. Keep the §7.4 recording
rule (`lrgSpokenThisTurn() !== ''`) and the `_prop_no` memory unchanged. On a player-speech
proposal turn nothing is hidden (the player's own request must still be carried out) - the
proposal paragraph is simply appended after the `<player_request>` block.

**M3 - both - `ev=sync` is not backward compatible and has no addressee.**
(a) On an **old** server, `ev=sync;running=0` is stored by `lrgStoreScene()` with `active=1`,
creating a fresh open scene row with no scene id and restarting the 900 s clock - the reload bug,
manufactured. (b) `AIAgentFunctions.logMessageForActor(payload, type, npcName)` with an empty
`npcName` has no defined behaviour; every existing call site passes a real display name.
**Change:** drop `ev=sync` from the wire. Game side: the no-thread branch sends
`ev=end;npc=<partnerName>;cid=<startCid>;scene=;byglue=<n>;sess=<tag>` **only when
`partnerName != ""`** (an old server closes the row correctly; a new one also learns `sess`); the
nameless case is covered entirely by §14.4.3's forced crosshair snapshot carrying `sess` +
`ostim=0`. The running branch sends its normal `ev=change` push with one additive key `sync=1`
(plus `lastSentKey = ""` first). Server side: `sync=1` on an `ev=change` only forces the
`_sess` stamp and skips the de-duplication; there is no new `ev` to handle. Update §2.1, §10.3,
§14.4.1-2 and flow test `22`.

**M4 - server - "close every active scene row" needs a query that exists.**
`lrgGetActiveScene()` (`lrg_core.php:243`) returns the single newest `active=1` row. §2.1 asks to
close *every* active row, and §10.1 closes "the" row only if its `_npc` matches the funcret's NPC
- if a stale row for another NPC is newer, the right row is never closed.
**Change:** add `lrgCloseScenesFor(string $npc, string $why): int` (closes that NPC's own
`active=1` row, selected by `npc_name`) and `lrgCloseAllScenes(string $why): int` (selects all
`active=1` rows and closes each with `lrgStoreScene($row_npc, ['ev'=>'end'] + $payload)`). §10.1
uses the first, §10.2 (`sess` mismatch) uses the second. Both log one line per closed row.

**M7 - game - `wait=end` releases between sentences.**
`CHIM_SpeechStarted` fires per sentence (`AIAgentAIMind.psc:1708`) and §14.5's `wait=end` is
defined as "`wait=begin` (satisfied immediately if `isActorTalking != 0` when the command
arrives) plus a `CHIM_SpeechStopped`". Both halves are wrong for `end`: the immediate-satisfaction
clause can latch onto a *previous* reply's sentence, and the first `SpeechStopped` can arrive
between sentence 1 and sentence 2 of her announcing line - the fade then cuts across her, which
is the exact thing G3a exists to prevent.
**Change:** `wait=end` requires, in order: (1) a `CHIM_SpeechStarted` for the partner strictly
after the command arrived (never the "already talking" shortcut); (2) a `CHIM_SpeechStopped` for
the partner; (3) a quiet settle of `fSayFirstSettle:SceneTalk` seconds (new MCM key, default
**1.0**, range 0-3) during which `AIAgentFunctions.isActorTalking(partnerName) == 0` - any new
start inside the settle re-arms step 2. The overall budget `fSayFirstMaxWait` still ends in "do it
anyway". Also: log one `LogC` line per wait with `waited=<s> reason=<spoke|timeout>` so playtest 7
can measure whether `setAnimationBusy(1)` (asserted at `CmdStart:507`, before the wait) is
suppressing her TTS - if every start reports `reason=timeout`, move the `setAnimationBusy` call to
after the wait.

**M8 - game - `setDrivenByAIA` is unproven and is never released.**
Signature `AIAgentFunctions.psc:58`. Of its five call sites, two are CHIM's crosshair hotkey
(`AIAgentPapyrusFunctions.psc:469`, `:835`, `salutation=false`), two are the MCM's "add this NPC
as an agent" (`AIAgentMCMConfigScript.psc:1241`, `:2727`, `salutation=true`) and one is agent
spawning (`AIAgentAIMind.psc:2281`). "Make this actor an AI-driven agent" fits all five; "set the
conversation listener" fits only two. §14.6 also assumes it auto-releases at scene end, with no
evidence.
**Change:** (a) call it **once** per thread (`OnOStimThreadStart` for a glue thread,
`AdoptRunningThread`) and re-assert at most every **60 s**, not 15 s, until it is proven
side-effect-free; (b) at `FinishThread` / `StopScene`, explicitly restore CHIM's own default by
calling `AIAgentFunctions.setDrivenByAI()` (the no-argument form CHIM itself uses when nothing is
under the crosshair) behind the same `bForceListener:Intimacy` toggle - never leave a forced
listener behind; (c) `LogC` every call and every release; (d) §17.1 must state the "it may only
register an agent" reading, and the MCM help text for `bForceListener` must tell the owner what to
look for (a greeting, a stuck conversation partner after a scene) and that turning it off is safe.

**M9 - game - the deferred verbs have no defined result timing.**
§14.5 parks `goto` / `furniture` / clothing in `Tick`, but never says when
`NavigateBlockedReason` / the adult and pair re-checks run, when `ReportResult` (and therefore
`commandEndedForActor`) fires, or what happens if the scene changes during the wait.
**Change:** blocking checks (`ok=1`, `IsPartnerName`, `IsAdult`, dry run, `NavigateBlockedReason`
/ furniture ref lookup) run **at command time**, as today, and a refusal is reported immediately.
Only the OStim call itself is parked. `ReportResult` fires when the parked call is performed
(success) so the funcret reflects reality and §12.4's watchdog stays honest; the watchdog's
`command_confirm_seconds` must therefore be `>= 20 + fSayFirstMaxWait`. If the thread ends, the
scene id changes, or `navInFlight` becomes true while parked, drop the parked call and report
`Error: still moving into the previous position` (or `Error: no scene is running`). Exactly one
parked verb at a time: a second command replaces the first and the first is reported
`Error: unknown scene request`... no - report the first as performed-or-refused **before**
accepting the second, and if that is impossible, refuse the second with
`Error: still moving into the previous position`. `stop` bypasses the park and cancels it.

**M10 - server - a low-confidence intent must not produce an imperative.**
§4.2 emits the DO-IT block at `conf` high **or** low. Kind 16 runs `lrgFindSceneByText()` over the
whole index on raw speech, so an incidental noun ("your mouth ...", "on your knees" said about
something else) yields a low-confidence act and the prompt then says *"The player just asked for
this: oral sex. Do it now ... Do not refuse."*
**Change:** at `conf === 'low'` emit the neutral form only:
`<player_request>The player may have asked for this: {WORDS}. If that is what the player meant,
{ACTION_NAME} with {FIELD} "{VALUE}" - otherwise just answer.</player_request>`. The imperative
DO-IT form is `conf === 'high'` only. Flow test `19`'s utterance #7 (`naked`, low) must assert the
neutral wording and zero wire lines.

**M12 - server - role direction for a candidate scene is assumed, not derived.**
§6.3 derives `:npc` / `:you` by comparing `actorSlot` against `$npcSlot` from the wire key `npos`
- which is the NPC's position in the **current** scene. `lrgActOptions()` then applies that same
slot to *candidate* scenes reached by `lrgSceneWalk()`. If OStim assigns positions differently in
the candidate (its actor slots carry their own `intendedSex` / requirements), every directional
act of that candidate is offered - and performed - the wrong way round.
**Change:** derive the direction per candidate: if the candidate's slot sexes
(`$scene['sex'][$i]`, already digested) are not both `any`, match them against the actual actor
sexes from `lrgSexes($state)` and use that mapping; only when the candidate's slots are
indistinguishable, fall back to `$npcSlot` from `npos`. Count and log the fallbacks once per turn
(`acts: <n> directions assumed`). Add a `test_scene_index.php` case with an asymmetric two-actor
scene.

**M13 - server - a say-first drop must not also count as idleness.**
`lrgPostProcessActions:618-621` increments `lead_idle` whenever a lead tick emitted nothing
(`!$passed`). §8.3 drops her command precisely when she *did* choose but said nothing, so the next
turn both re-asks her to speak (§8.3) **and** tells her "she already let the last moment pass
unchanged: this time she makes a change" (`:986`) - two contradictory pressures and a second
wasted turn.
**Change:** when §8.3 drops a command, set `say_first` and leave `lead_idle` unchanged (treat the
turn as if it had passed for the purpose of that counter). Flow test `21` asserts it.

**M14 - server - net line decoration and append order.**
**Change:** state explicitly in §9: a net-fired line is by definition player-requested, so
`lrgDecorate($turn, $code, $kv, false)` - it receives `hold=`, never `wait=`, never `nowarp=`, and
is never subject to §8.3's say-first drop. In `lrgPostProcessActions()` the order is fixed: the
LLM's lines, then the §8.3 say-first check, then the net (§5.1), then the §9 carrier **only if the
array is still empty of glue commands**. Guard the net against `$turn['npc'] === ''`.

### MINOR

**m1 - server -** `RequestAct` must be added to `LRG_GLUE_ACTIONS` (`lrg_actions.php:25`), to the
snake-case display-name map at `:538` (`'requestact' => LRG_ACT_REQUESTACT`), to the kill-switch /
SHARMAT hide list, and to `lrgHideActions()`'s scene-blocked list at `:403`. Missing any one is a
silent, total failure of G5.

**m2 - both -** §2.2's `hold=` prose ("the player steered `n` seconds ago") contradicts §14.3
(`leadHoldUntil = now + hold`). Define it once: **`hold=<n>` = do not take a lead turn for the
next `n` seconds.** Fix `PROTOCOL §2` to match.

**m3 - server -** `lrgDecorate` must **remove** any `warp` key when `$selfInitiated` is true (the
free-text path in `lrgResolveControl:753` sets `warp=1` by itself), so `warp=1` and `nowarp=1` can
never both appear.

**m4 - server -** §7.4's "`RequestAct` and `ChangeIntimacy` are hidden for that turn except for
pace / hold / stop" is not implementable - an action is hidden whole. Keep `ChangeIntimacy`
**offered** (the stop rail must never be removed) and simply omit the act list from the notes;
hide `RequestAct` only.

**m5 - server -** §11.1/§11.2 do not say whether the current utterance's increment counts toward
the `heat >= min_heat` comparison on the same turn. Specify: it does (increment first, then
compare), so "two romantic exchanges" means the second one can already open the action. Flow test
`25` asserts it.

**m6 - server -** §11.1 increments `heat` in mode `public` from the recogniser's kind, but §3.7
only runs the recogniser for modes `scene`, `private`, `follow`. Either add `public` to §3.7's
list (cheap - it is pure regex) or state that in `public` only `start.heat_pattern` contributes.

**m7 - server -** §11.2 leaves out-of-scene `ChangeClothing` on today's rule, so with `START`
hidden for heat/cooldown reasons the model's most physical remaining option is for her to strip
unasked, 14 s after a reload. Apply the same condition to a **self-initiated** out-of-scene
`undress`: offer `ChangeClothing` when the player asked (§11.2 rule 1) or when rule 2/3/4 pass;
otherwise hide it with `hidden=Clothing(heat 0<2)`.

**m8 - server -** `log_timezone` defaults to `Europe/Madrid`, which is the owner's local time
**+6 h**; both this round's audits had to do that arithmetic by hand. Either default to
`America/New_York` (the WSL system zone = the owner's clock = `OStim.log`'s clock) or write one
line at plugin load stating the mapping. Keep the printed `P` offset either way.

**m9 - server -** §3.3's note "the recogniser calls `lrgResolveControl()` with the matched
fragment" must be a rule, not a note: never pass the whole utterance, or the free-text position
search at `lrg_actions.php:744-754` will fire on incidental words.

**m10 - server -** §7.2's `'handled'` return for a dropped lead tick is correct
(`preprocessing.php` already routes `lrg_scenetalk` because it starts with `lrg_`), but the same
function must keep returning `'pass'` for a non-lead `lrg_scenetalk`. Add the assertion to flow
test `20`.

**m11 - server -** the §9 carrier command produces a funcret, a short MAIN acquisition and two log
lines on **every** player speech turn inside a scene. Throttle: send it only when
`lrgNow() - (mem['hold_sent_at'] ?? 0) >= lead.hold_seconds / 2`, and record `hold_sent_at`. The
game's own `leadHoldUntil` is monotonic, so a skipped carrier costs nothing.

**m12 - server -** §7.4's proposal TTL (90 s) lets an unrelated "yes" two turns later execute a
sex act. Require the `yes` to arrive on the **first or second** player speech turn after the
proposal (count them in the proposal record) as well as inside the TTL.

**m13 - server -** §16.1 row 13 asserts `act=vaginal / bendover` - two different expectations. Pick
one (`vaginal`, with the scene chosen through the `bendover` synonym) so the test is decidable.

**m14 - server -** §3.5's `question` blocker keys on the **leading** word. Playtest 6's utterance
was *"Tell me, what do you picture my cock looking like?"*, which starts with `tell`, an
imperative in REQUEST FRAME 1 - so on a literal reading it is framed, not blocked. Make the
`question` blocker fire on a leading question word **or** on a trailing `?` with no imperative
verb outside a `tell me,` / `so,` / `hey,` lead-in; simplest reliable rule: strip a leading
`(tell me|so|hey|listen|say)[,]?\s+` before applying §3.5. Add the verbatim utterance to
`test_intent.php` (it is already row 8) and assert `none`.

**m15 - both -** the round does not change `OStimUseFreeCam` (correctly - it is the owner's
setting, `PROTOCOL §0` rail). Say so in the hand-over: **ask the owner to turn "Switch to free cam
mode on start" off before playtest 7**, or half of "director mode" will still be there and the
round will be blamed for it. Same for `OStimUseFades` if the `wait=end` measurement (M7) shows
timeouts.

---

## 8. Things I attacked and could not break

Recorded so the builders do not re-litigate them:

- The post-process append mechanism (§5.1) - the closure's return value is what is echoed, our
  plugin already appends in one branch (`:611-612`), and `lrgKv()` already strips `; = @ | "`.
- The rails around the net: no `START`, no `INVITE`, never outside a scene, never for a
  non-adult (mode stays `silent`, `lrg_actions.php:388-391`), never under the kill switch,
  dry run refused game-side.
- Removing the `Decline` action and refusal detection (addendum 3b) - §0.2 is faithful and
  §4.4.5 deletes the right sentence (`:992`).
- `lrgResolveControl` staying the single verb implementation (§3.3 note) - correct, and the
  negated-stop guard at `:684` really is there.
- The prompt-passage surgery in §4.4 - all five citations verified verbatim against the file.
- Wire additivity of `hold=` / `nowarp=` / `wait=` - `ParamGet:409-424` and the `Cmd*` readers
  ignore unknown keys; an old game is genuinely unaffected.
- `LRG_ACTIONS_VERSION` 8 being mandatory - `lrgEnsureActions():43-52` keys on the marker and
  re-installs deleted rows.
- The `pendingStart` restructure being feasible - the park point after `CaptureBaseline:563` and
  before `OThreadBuilder.Create:583` keeps every OStim call on one stack.
- Path 1 of G4 (funcret closes the row) - `lrgRecordResult:250-264` already parses everything it
  needs, and `no scene is running` is on the closed list (`PROTOCOL §1.6`).
