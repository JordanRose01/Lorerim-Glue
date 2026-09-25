# v0.2 adversarial review - BEHAVIOUR builder
Lens: OWNER INTENT, RAILS AND PROMPT QUALITY (hunt items 3, 5, 6). Reviewer is READ-ONLY on project files;
this report is the only file written. Date 2026-09-21.

## What I reproduced myself
| check | result |
|---|---|
| `php -l` on all nine plugin files + tools + flows (PHP 8.2.28, DwemerAI4Skyrim3) | clean |
| `tools/test_gates.php` | **196 passed / 0 failed** - claim confirmed |
| `tools/flows/run_flows.php` | **23 scenarios, 466 checks, 0 failed** (report says 22/464: FLOWTESTS added one after their run) |
| `tools/test_scene_index.php` | not re-run (other reviewer's lens) |
| CHIM R5 audit claims | independently re-verified in the installed source, see below |

Independent verification of the three CHIM facts the whole design rests on - all three hold:
- `checkOAIComplains` really is bypassed by the glue's flag: `lib/chat_helper_functions.php:593` is
  `if (isset($GLOBALS["OPENAI_FILTER_DISABLED"])) return 0;`, and the filter runs *after* the LLM call
  (`:1380-1385`), so setting it in `context_pre.php` (main.php:2540) is in time.
- Empty `message` really is silent: `connector/openrouterjson.php:1017` returns an empty mangled buffer,
  `lib/data_functions.php:6004` is guarded by `trim($buffer)`, the action still runs at `:6029` (gated only on
  `FUNCTIONS_ARE_ENABLED`), and the `returnLines(["Sure thing!"])` filler at `main.php:2846` is commented out.
  The JSON schema requires `message` but sets no `minLength` (`functions/json_response.php:472-512`), so `""` is legal.
- `prerequest.php` switching actions back on is safe: the only later `FUNCTIONS_ARE_ENABLED=false` after main.php:1117
  are `main.php:1358` (inside `if (in_array($gameRequest[0],["rechat","narration"]))`, main.php:1127) and
  `main.php:2713` (inside `&& false`). `functions/functions.php` is loaded unconditionally (`prompt.includes.php:55`)
  and its final filter (`functions/functions.php:2790`) prunes `FUNCTIONS` down to whatever the glue left in
  `ENABLED_FUNCTIONS`. Also confirmed `unsetFunction("TravelTo"/"FollowPlayer")` at main.php:2161-2168 is inside the
  rechat/narration branch only, so the builder's "come with me" analysis stands for speech turns.
- `lrgResolveRequestNpc()` is correct and necessary: `$GLOBALS["db"]` exists from main.php:167 (before the
  preprocessing hook at :193) and CHIM itself does the same `core_npc_master.md5` lookup at main.php:295-297 with the
  comment "Profile isn't loaded yet at this point".

So: the plumbing claims are true. The defects below are all about intent, rails and prompt text.

---

# DEFECTS

## D1 (HIGH, rail + PROTOCOL 6.2) `prompts.php` touches CHIM's runtime on turns the glue decided are SILENT
`glue/server/lorerim_glue/prompts.php:12` guards the whole block with `lrgEnabled()` only. It does not look at the
turn mode, at `adult`, at whether a scene row exists, or at `LRG_SHARMAT_PRESENT`. It then defines
`$GLOBALS['PROMPTS']['lrg_scenetalk']` (`:21-30`) and sets `FORCE_MAX_TOKENS` (`:35-41`).

PROTOCOL 6.2 defines mode `silent` as "BOTH guidance strings are `''`" and the report claims "silent = ... CHIM
runtime untouched". It is not untouched.

**Evidence** (my probe, real hook files in CHIM order, `fxHookTurn`):
```
snapshot adult=0, ostim=1, request lrg_scenetalk
mode=silent  guidance_silent=yes          <- library is correct
PROMPTS['lrg_scenetalk'] defined: YES
  cue[0] = "(Probe Kid says one short, blunt sentence - 120 characters or less - about what is happening
            right now, the way a real person talks in the middle of it, or only a breath or a sound; ...)"
FORCE_MAX_TOKENS: 140
```
The same happens when the scene row is missing or stale (lost `ev=end`, reload, a scene whose state key has not
changed for `scene_stale_seconds`=900 s while `MaybeLead` keeps firing every 45 s - `LRG_OStim.psc:1977` only sends
`lrg_scene` when `stateKey` changes): mode `silent`, no notes at all, but the NPC still gets an "in the middle of it"
cue and a 140-token cap, so she improvises an intimate line out of nothing. With SHARMAT installed the cue is defined too.

This is the one place where the "adults only, fail closed" rail is not actually closed: the guidance is empty, but the
*cue itself* is intimate content, and the cue is the last thing in the prompt.

**Smallest fix** (prompts.php, BEHAVIOUR-owned): make the definition conditional on the same facts `lrgPrerequest()`
already uses, and bail out for SHARMAT:
```php
if (lrgEnabled() && !defined('LRG_SHARMAT_PRESENT')) {
    ...
    $lrgSc = ($lrgIsTalk || $lrgReqType === 'lrg_initiative') ? lrgGetActiveScene() : null;   // needs lib/lrg_actions.php
    $lrgOk = $lrgReqType === 'lrg_initiative'
        ? strcasecmp((string)($GLOBALS['LRG_INITIATIVE']['npc'] ?? ''), $lrgName) === 0
        : ($lrgSc !== null && strcasecmp((string)$lrgSc['_npc'], $lrgName) === 0
           && (lrgGetNpcState($lrgName)['adult'] ?? '') === '1'
           && (int)(lrgGetNpcState($lrgName)['_age'] ?? 9999) <= (int)(lrgConfig()['snapshot_max_age_seconds'] ?? 90));
    if ($lrgOk) { ...define PROMPTS + FORCE_MAX_TOKENS... }
}
```
Dropping the entry is safe: `processor/request.php:171-175` logs `Request cue is empty!` and falls back to
`TEMPLATE_DIALOG`, i.e. an ordinary neutral reply. No crash, no intimate cue.

## D2 (HIGH, rail) A bare refusal in `item` escalates to a sex position and warps there
`lrgResolveControl()` (`lib/lrg_actions.php:618-676`) falls through to the free-text index search at `:667-675` for
anything it does not recognise. There is no minimum length and no negation guard.

**Evidence** (scene at sexual tier, ceiling 4):
```
'no'          -> {"do":"goto","scene":"OARE_StandingRearSexNoHands","warp":1}
'wait no'     -> {"do":"goto","scene":"OARE_StandingRearSexNoHands","warp":1}
'no thanks'   -> {"do":"goto","scene":"OARE_StandingRearSexNoHands","warp":1}
```
("no" fuzzy-matches `...NoHands`. `warp=1` means fade-to-black and jump, so it is not even a visible transition.)
The realistic path: the model echoes the player's short utterance into `item` - the notes explicitly invite plain
words ("or any position in plain words"). The player says "no", the scene *changes to a sex position*. That is the
exact opposite of the consent model, and the scene notes' own "If what the player asks for does not exist, she answers
in character and nothing changes" is not what happens.

**Smallest fix** - one line in `lrgResolveControl`, immediately after the `stop` rail at `:623`:
```php
if (preg_match('/^(no|nope|nah|not|n[o0]t? ?that|do ?n[o\']?t|never ?mind|nothing|no thanks?|wait no)\b/', $t)) { return null; }
if (strlen($t) < 3) { return null; }   // one- and two-letter items are never a position
```
(Returning `null` = dropped, nothing changes, she answers in words - which matches the rail. Mapping them to `stop`
would be the owner-aligned alternative; that decision is the owner's, but the current behaviour is not defensible.)

## D3 (HIGH, rail + PROTOCOL 0) Scene mode never re-checks snapshot freshness
`lrgPrepareTurn()` `:356-362` picks the scene branch on `lrgGetActiveScene()` and then checks only
`($state['adult'] ?? '') !== '1'`. It never looks at `$state['_age']`, although every other path goes through
`lrgEvaluateGates()` which fails closed on `_age > snapshot_max_age_seconds` (90 s, `lrg_core.php:458-459`).
PROTOCOL section 0 states the rail as "adults only, fail closed (`adult=1` **on a fresh snapshot**)", and 6.2 lists
"no fresh snapshot" under mode `silent`.

**Evidence**:
```
snapshot sent, scene started, clock +600 s, player speech
snapshot age=600s   mode=scene   x=2
volatile has "fully explicit and vulgar" wording: YES
```
`lrg_scene_state.active` survives for `scene_stale_seconds` = 900 s, so a 15-minute-old adult confirmation keeps
producing level-2 explicit guidance and offering ChangeIntimacy / ChangeClothing. Reachable whenever the game stops
snapshotting (crosshair off the partner, script lag, save/load, the `snapBusy` stuck-flag path in
`LRG_Main.psc:685-690`).

**Smallest fix** (`lrg_actions.php:360`):
```php
$fresh = $state !== null && (int)($state['_age'] ?? 9999) <= (int)(lrgConfig()['snapshot_max_age_seconds'] ?? 90);
if ($inSceneWithThisNpc && (!$fresh || ($state['adult'] ?? '') !== '1')) { lrgHideActions(LRG_GLUE_ACTIONS); }
```
(Keeping `scene_stale_seconds` as it is; this only stops the *content*, the game still runs its own scene.)

## D4 (MEDIUM, R3 not really delivered) The public/private split gates ACTIONS but not LANGUAGE
R3 is about *hard romantic moves* in public; the owner's own example of such a move is explicit speech
("stripping naked and asking if you like what you see"). The implementation gates the actions perfectly
(mode `public` offers only SuggestPrivacy) but hands the model the unchanged level-2 wording permission.

**Evidence** - willing NPC, 6 witnesses, inn:
```
x=2 mode=public: "Wording for anything about desire or sex: fully explicit and vulgar - crude words for body
parts and acts are expected, not avoided: never soften them, dodge them or swap in euphemisms. Kept low, for
the player only."
```
and in a `castle` (where `lrgIsDiscreet()` fires) the static block says "discreet ... careful, never coy" while the
volatile block still says "crude words ... are expected, not avoided" three lines later. "Kept low" is about volume,
not vocabulary. At explicitness 2 this produces crude propositions shouted across the Bannered Mare - exactly the
"hard move in public" R3 asked to avoid, and it contradicts the discreet line in the same prompt.

**Smallest fix** (`lrgWordingLine`, `lib/lrg_actions.php:722-728`): cap the level when others can hear.
```php
$lvl = (int) $turn['x'];
if ($lowVoice || !empty($turn['discreet'])) { $lvl = min($lvl, 1); }   // public / castle / temple / married-secret
$words = $cfg['explicitness_words'][$lvl] ?? 'direct and physical';
```
The follow-through and scene turns (where they are alone) keep the full level, which is what R5 asked for.

## D5 (MEDIUM, R10 + hunt 6) The scene notes order her to speak and to stay silent in the same block
`lib/lrg_actions.php:855` is unconditional: *"Say what $npc wants, what it feels like in the body, what the player
should do."* It is followed, in the same injected block, by either
- `:905` "Whatever $npc chooses this time simply happens: leave message empty." (announce dice failed), or
- `:856` "Manner of $npc: does not speak during intimacy - only breath or a short sound." (`talk: never`).

**Evidence** (`talk: never` override, in a scene):
```
| ... Say what Probe Silent wants, what it feels like in the body, what the player should do. ...
| Wording: fully explicit and vulgar ... Manner of Probe Silent: does not speak during intimacy - only breath or a short sound. ...
```
and with `LRG_TEST_ROLL['announce']=100`:
```
| ... Say what Probe Lover wants, what it feels like in the body, what the player should do. ...
| Whatever Probe Lover chooses this time simply happens: leave message empty.
```
A model resolving a direct imperative against a later conditional will speak. This is the single most likely reason
R10's "make silence really silent" will fail in game, and it is fixable now rather than after the playtest.

**Smallest fix** (`lrgSceneNotes`, `:855`): make that clause conditional instead of unconditional -
```php
. (!empty($turn['announce']) && lrgTalkStyle($turn['profile'] ?? []) !== 'never'
     ? ' Say what ' . $npc . ' wants, what it feels like in the body, what the player should do.' : '')
```
(and move it out of the fixed sentence). Same block already has "silence is fine", so nothing else needs touching.

## D6 (MEDIUM, R1 not really delivered) "hold it there" changes position instead of stalling
`:637` anchors the hold verb: `/^(hold|hold on|hold it|hold back|wait|not yet)$/`. The owner's own playtest script
(`PLAYTEST2_NOTES.md:24`) lists **"hold it there"** as the phrase for hold.
```
'hold it there' -> {"do":"goto","scene":"OARE_StandingHug","warp":1}
'release me'    -> NULL
```
So the documented phrase fades to black and moves them into a hug. R1 says everything OStim's keys do must be
reachable by talking; hold/release is one of the five verbs the owner named.

**Smallest fix** (`:637-638`): drop the anchors and widen slightly -
```php
if (preg_match('/\b(hold (it|on|back|there|still)|hold)\b|\bnot yet\b|\bstay (like )?(this|that)\b|\bwait\b/', $t)) { return ['do' => 'hold']; }
if (preg_match('/\b(release|let go|carry on|keep going|go on)\b/', $t)) { return ['do' => 'release']; }
```
Note the order matters: this must stay *after* the stop rail and *before* the free-text search, where it already is.

## D7 (MEDIUM, rail) A child walking in mid-scene does not silence the glue
`witkid` is a hard gate for *starting* (`lrg_core.php:467`) but the scene branch never reads it.
```
scene running, snapshot witkid=1 -> mode=scene  x=2  explicit wording present: YES
```
The owner's rail is "nothing romantic or explicit is ever generated for or about a non-adult". Explicit dialogue
generated in a room the game says a child is in is not what the rail intends, and the game has no rule that stops the
scene either (no `witkid` handling in `LRG_OStim.psc`).

**Smallest fix**: add `witkid` to the scene-branch fail-closed test in D3's one-liner
(`|| ($state['witkid'] ?? '1') !== '0'`). That drops the glue to silence; whether the *scene* should also stop is a
GAME decision - put it in `forIntegrator`.

## D8 (LOW, R1) `who` for clothing does not understand "me"
`lrgResolveClothing` `:688` recognises `you` / `the player` / `player` but not `me` / `my`.
```
'dress me'    -> {"do":"dress","who":"npc","part":"all"}
'undress me'  -> {"do":"undress","who":"npc","part":"all"}   (strips the NPC, not the player)
```
Fix: add `|me|my` to that alternation (`...\s+(?:you|me|the player|player)\b|^(?:you|me|player)\b`).

## D9 (LOW, R1) `finish inside me` climaxes the wrong actor; a player echo of "I'll lead" flips the lead
`:641` `who` = `both` if both/together/us/we, `player` if you/your/player, else `npc`. "finish inside me" -> `npc`.
`:645-652` resolves "i lead" / "take over" to `who=npc` by design (documented in the comment), so a model echoing the
*player's* "I'll lead" hands the lead to the NPC. Both are edge cases; the notes do give unambiguous keys
(`<npc> leads` / `player leads`). Worth one sentence in the notes rather than more regex.

## D10 (LOW, contract) An explicit line is written into a test file
`glue/tools/test_gates.php:167` uses a crude two-word sentence as its check label and as the test input. PROTOCOL
section 0: "No explicit example dialogue in code, prompts, tests or docs: write rules, not lines." Replace the string
with a neutral above-ceiling phrase (e.g. an index word plus "now"); the assertion is unaffected.

## D11 (LOW, R7 consistency) `private` turns are not capped, `follow` turns are
`lrgApplyTurnRuntime` `:464-468` caps `scene`, `follow` and `initiative`. A `private` player-speech turn offers the
same BeginIntimacy / ChangeClothing and carries the same "ONE short sentence" instruction but gets no
`FORCE_MAX_TOKENS`, so the turn that most often starts a scene is the one that is not speed-protected. Either add
`|| $turn['mode'] === 'private'` or drop the one-sentence instruction there - the current pair is inconsistent.

## D12 (LOW) CHIM's refusal filter is also disabled on `closed` turns
`:460-462` bypasses `checkOAIComplains` for every non-silent mode, including `closed` (a stranger being refused).
That is defensible (in-character refusals trip the word scorer), but it also means a *genuine* provider refusal would
now be spoken aloud by the NPC instead of being replaced by CHIM's canned line. Worth one line in the owner notes.

## D13 (LOW) The married-`secret` +20 is eroded by the new renown/Speech bonus
`lrgInterest` adds `+20` to `min` for the secret rule and then subtracts up to 15 of bonus, so a Dragonborn with 100
Speech needs only +5 over the ordinary threshold. Only reachable through the `priest_dibella` status rule (the only
`married_rule: secret` in the shipped config; `fallback` is `refuse`), so impact is small - but if the +20 is meant as
"real trust", the bonus should be computed before it, or excluded when `married_secret` is in `soft`.

---

# R1-R10: delivered or not, one traced example each

| R | verdict | traced example |
|---|---|---|
| R1 menuless OStim | **mostly** | Full verb set emits the exact contract shapes (`do=goto;scene=..`, `do=winddown;scene=..;warp=0;linger=20`, `do=furniture;furn=bed;scene=..`, `do=lead;who=auto`, `do=undress;who=both;part=feet`). Gaps: D2, D6, D8, D9. "If I say a position it should go to that position" works: `'missionary'` at a kissing scene -> `goto OStim2PMissionaryIdleMF` with `warp=1`. |
| R2 NPC-initiated romance | **yes** | `lrgInitiativeAdmit()` drops a not-drawn / cooling-down / unwilling tick before the MAIN lock (verified: admission requires `may_initiate`, 300 s cooldown and `lrgRoll('initiative') <= chance[pace]`); the LLM sees only the word ("wants the player, and is ready to show it"), never the score. Measured word/willing curve: aff 20 -> interested/willing, aff 45 -> drawn/may_initiate. |
| R3 public vs private | **half** | Actions: correct (public offers only SuggestPrivacy, places built from `nhome`/`ltype=inn`/`prent`, invite recorded with `expires=+1800`, follow-through flips the guidance, `state=followed` after BeginIntimacy). Language: **not gated** - see D4. |
| R4 direct, not poetic | **yes** | "plain everyday words in their own voice ... No metaphors, similes, poetry, flowery or theatrical phrasing, no narrating of feelings"; manner scaled by talk style; discreet only for very strict / married-secret / castle / temple. No example lines anywhere in the prompt text. |
| R5 no NSFW blocks | **yes**, with D4 | Level-2 wording reaches the prompt verbatim only when `x !== null`; unwilling NPCs get none (probed three ways: private, witnesses, married - `x=null`, no wording line). CHIM's one real blocker is bypassed per request. Audit re-verified against source. |
| R6 progression | **yes** | Ladder holds: at a kissing scene with pace eager, `progress={"reached":2,"ceiling":3}`; a two-tier request returns `too_soon`; the lead tick marks the newly opened tier `[next step]` and says "moves things along: choose ONE change"; `lead_idle` escalates the next tick. |
| R7 speed | **yes** | All four rows `followup.enabled=false`; `FORCE_MAX_TOKENS` 140/200; no index build can start from an LLM request (`lrgSceneIndex()` never builds outside CLI, `lrg_scene_index.php:77-84`); `lrgIndexMaybeRebuildAsync()` only from the `lrg_npcstate` fast path. Guidance sizes measured: closed 1352, public 1682, private 1604, scene 2807-3084 chars - short. Inconsistency D11. |
| R8 bridge completeness | **server side yes** | furniture start (`lrgPickStart` -> `furn`/`fscene`), climax, wind-down as a separate verb with its own afterglow scene, auto-mode gated on `auto_mode_min_tier`, movement actions hidden during a scene, SDE read. Gap: `LRG_MOVEMENT_ACTIONS` misses `ArrestPlayer`, `GiveItemTo`, `GiveGoldTo`, `TakeHeldItem`, `CheckInventory` - all real CHIM codes (`functions/functions.php:17-66`) that would animate or re-task her mid-scene. |
| R9 offline flow tests | **yes** | 23 scenarios / 466 checks reproduced, covering every observable outcome PROTOCOL 7.3 lists. But note the suite passes *with* D1-D3 present: the non-adult scenario asserts on the library, not on `prompts.php`; nothing asserts scene-mode snapshot freshness; nothing feeds a refusal word to `lrgResolveControl`. Three regression tests to add. |
| R10 speak the choice | **server side yes, prompt side no** | `[say it]` only on sensual/sexual options and only when the per-talk-style dice pass (`quiet 40 ... never 0` - verified `talk: never` never announces); gentle options always `[silent]`; the silent pick still produces the command. But D5 means the block simultaneously orders her to speak. |

# Rails - attack results
| attack | result |
|---|---|
| `adult=0` outside a scene, every request type | silent, nothing offered, nothing written - **holds** |
| `adult=0` during a scene | library silent and ChangeIntimacy dropped - **holds**; but `prompts.php` still cues her (D1) |
| `witkid=1` before a scene | hard block - **holds** |
| `witkid=1` during a scene | **fails** (D7) |
| stale snapshot outside a scene | `no_fresh_snapshot` -> silent - **holds** |
| stale snapshot during a scene | **fails** (D3) |
| unwilling NPC (low affinity / witnesses / married) | `x=null`, no wording permission, no offers - **holds** |
| interest drops after an invitation | invite dropped, mode `closed` - **holds** |
| `stop` from 11 phrasings incl. mid wind-down and "stop, but keep kissing me" | `do=stop` every time - **holds** |
| `stop` synonyms "cease" / "stahp" / "get off me" | `NULL` (nothing happens) - acceptable, the hotkey remains |
| a bare "no" | **fails** (D2) |
| kill switch / SHARMAT | library silent, all lines dropped - **holds** (`prompts.php` excepted, D1) |
| hallucinated / forged / wrong-actor glue lines | dropped - **holds** |
| explicit example dialogue in owned files | one breach (D10); the `scene_index` synonym tables are SCENEINDEX-owned and are required for R1 |

# Notes for the integrator (not BEHAVIOUR defects)
1. `scene_index.content_filter` now defaults to **`open`** (SCENEINDEX section, `lrg_config.default.json:273`), i.e.
   the 0.1 taste lists are OFF. In my probe the highest-tier scene the index offered was a footjob position. The owner
   should be told that his old exclusions (feet, spanking, hair pulling, vampire feeding...) are no longer applied by
   default and that `"content_filter": "standard"` restores them.
2. GAME: no `witkid` handling exists inside a running scene. If the owner wants the scene to *end* when a child walks
   in (rather than only the glue going quiet), that is a game-side rule.
3. GAME/BEHAVIOUR: `LRG_MOVEMENT_ACTIONS` should gain `ArrestPlayer`, `GiveItemTo`, `GiveGoldTo`, `TakeHeldItem`,
   `CheckInventory` (see R8 row).
4. The audit's connector table is worth acting on as written; I re-verified findings 1, 3 and 5 in the source
   (`processor/postrequest.php:197-208` really does insert a global 60 s "Actors should behave in a intimate way"
   scene note; `lib/core/action_catalog.php:364-379` really does not list the glue's code names).
5. `FORCE_MAX_TOKENS` 140/200 is sent to OpenRouter as `max_tokens` (`connector/openrouterjson.php:622-630`). The
   audit records the dialogue model as `x-ai/grok-4.3` with **reasoning effort none**, so this is safe today; if the
   owner ever switches to a reasoning model, `isReasoningModel()` (`:99-130`) does not know grok-4.x, reasoning tokens
   would eat the 140-token budget and scene lines would come back empty or truncated. One line in the owner notes.
6. Declared deviation I agree with: wording permission from "curious" (workflow brief) vs adult+**willing**
   (PROTOCOL 6.4). The builder followed the contract and said so. Since `thresholds.interested = 0` equals the
   willing cut-off, "curious" is by construction always unwilling, so the brief's wording would have loosened the
   consent gate. Leave as is; the owner should confirm.

# VERDICT: SHIP AFTER FIXES

The behaviour layer is genuinely good work: the mode machine, the interest word, the invitation lifecycle, the
initiative admission before the lock, the R7 measures and the R10 tier/dice marking are all real, all match
PROTOCOL v2, and I could not break the offer gates, the post-LLM gate, the kill switch, the SHARMAT guard or the
unwilling-NPC path. The prompt text is also genuinely direct and genuinely unsanitised at level 2 - the "too SFW"
complaint is answered. What stops it shipping as-is are three things that a playtest will find within an hour and
that the owner will read as broken rails, not as polish: an intimate prompt cue that fires on turns the glue itself
declared silent (including a non-adult snapshot), a bare "no" that changes the scene to a sex position, and a scene
branch that never re-checks how old its adult confirmation is. D4 and D5 are the two that will make the owner say
"that is not what I asked for" - crude language in a crowded inn, and an NPC who narrates the choice she was told to
make silently. All seven of D1-D7 are small, local edits inside BEHAVIOUR's own files, each with a regression test
that the existing suites can absorb.
