# v0.2 adversarial review - FLOWTESTS work, lens: owner intent, rails, prompt quality (2026-09-21)

Reviewer is READ-ONLY for project files; the only file written is this one. Probe scripts live under
`%TEMP%\lrg_rev_probe\` (probe.php, probe2.php, names.sh, slots.sh, empty.sh); the plugin was staged read-only to
`%TEMP%\lrg_rev_intent` (robocopy /MIR, `/XD data`). Nothing in the project, in MO2, in CHIM or inside the WSL web root
was modified.

## 1. What I ran myself

| run | result |
|---|---|
| `run_flows.php --strict` on my own stage | 23 scenarios, **466 checks, 0 FAIL, 0 pending, 0 warn, exit 0** - the builder's number reproduces exactly |
| `mutation_check.ps1` (full, 15 mutations) | **15 of 15 detected, 0 stale patterns**, exit 0 - reproduces |
| `probe.php` | rail attacks A-G against the staged plugin through the harness |
| `probe2.php` | rail attacks K-O (profile `never` / married-refuse inside a live scene) |
| CHIM source checks in WSL (`names.sh`, `slots.sh`, `empty.sh`) | every CHIM API name and file:line the plugin and the harness stubs rely on |

**What is genuinely good, and I could not break it.** The harness/adapter split is real: I changed nothing in the
scenarios to run my own attacks, only called adapter functions. The CHIM facts the suite stubs are all true in the
installed 3.3.2 source - `herikaActionCatalogUpsertCustomRow` (lib/core/action_catalog.php:3379),
`herikaGetActionCatalogRow`, `herikaActionCatalogResetCache` (:2016), `chimRegisterPromptInjection`
(lib/prompt_injections.php:10) with the slots `character_bottom` / `prompt_bottom` really rendered at main.php:2553 and
:2564 (i.e. after context_pre at :2540), `suppress_placeholder_infoaction` honoured at funcret.php:63,
`followup.enabled=false` really terminating with no second LLM call at funcret.php:134, `action_post_process_fnct_ex`
at data_functions.php:6036, and the ext `functions.php` hook loaded **unconditionally** at functions/functions.php:2752
(so the post-LLM gate really is registered on lead ticks). The R10 silence claim also holds: with an empty message and
an action, main.php:2832-2846 takes the "AI only issued commands" branch and the `returnLines(["Sure thing!"])` filler
is commented out - and with neither message nor action the `returnLines(array($randomSentence))` fallback is commented
out too. **No explicit example dialogue anywhere under `tools/flows/`** (grepped for crude vocabulary and for
line-shaped phrases; zero hits). Scenario 14/14b is the best work in the round: "they don't always have to say
something" is pinned properly (dice, talk style, `roll <= chance`, `never` at die 1, silence allowed on every kind of
scene turn).

So the suite is real and it has teeth. The problem is **where it points**.

## 2. The headline: the suite proves the rails hold *outside* a scene, and never checks them *inside* one

`lrgPrepareTurn()` has two branches. The out-of-scene branch runs the full gate (`lrgEvaluateGates()`,
lrg_core.php:448-500: fresh snapshot, adult, witkid, `on`, combat, quest scene, profile `never`, married rule,
witnesses, willing). The in-scene branch re-checks **exactly one** thing:

```php
// lib/lrg_actions.php:358-363
$state = $inSceneWithThisNpc ? lrgGetNpcState($npc) : null;
...
if ($inSceneWithThisNpc && ($state['adult'] ?? '') !== '1') {
    lrgHideActions(LRG_GLUE_ACTIONS);          // fail closed
} elseif ($inSceneWithThisNpc) {
    $turn['mode'] = 'scene';                    // everything else is trusted
```

Once a row exists in `lrg_scene_state`, nothing else is ever looked at again. And a row can exist without the glue
ever having gated anything: PROTOCOL 3 deliberately keeps OStim's own keys as the owner's fallback, and
PLAYTEST2_NOTES.md line 44 records that a snapshot is now forced at every scene start **"so scenes started from
OStim's menu also get scene awareness"** (`byglue=0`). That path is the attack surface.

The 23 scenarios test the in-scene re-gate for `adult` only (05, lines 48-63) and mutation M11 mutates that one guard.
Every other hard gate is tested only out of scene: `witkid` in 05's `$cases` loop (no scene row), profile `never` in 06
lines 41-54 ("Vigilant, alone / in public"), snapshot freshness in 17 lines 11-20. Mutation testing cannot help here -
you cannot mutate a guard that does not exist.

### F1 [CRITICAL, rail] A `never` NPC and a married-refuse NPC get the full explicit scene treatment inside a scene

`probe2.php` PROBE K, verbatim (affinity forced to -100, i.e. she refuses everything):

```
vigilant aff=-100 OUTSIDE                          mode=silent  x=NULL  offered=none
vigilant aff=-100 IN SCENE (menu-started)          mode=scene   x=2     offered=ChangeIntimacy+ChangeClothing  vol=3154
    lead tick: mode=scene actions_on=true  P1 passed=true  undress passed=true  explicit=true
vigilant +married-to-other aff=-100 IN SCENE       mode=scene   x=2     offered=ChangeIntimacy+ChangeClothing
housecarl +married-to-other aff=-100 IN SCENE      mode=scene   x=2     offered=ChangeIntimacy+ChangeClothing
```

PROTOCOL 6.2 lists profile `never` under mode **silent**, with no in-scene exception. `never` is the owner's hard
opt-out for a whole class of NPCs (Vigilants of Stendarr in the shipped `status_rules`, plus anything they put in
`npc_overrides`). Today that opt-out silently stops applying the moment an OStim thread exists, and she is then given
level-2 explicit wording, admitted lead ticks, and a `ChangeClothing@undress` that passes the gate. The rail "the NPC
must be willing" is not re-asserted either.

### F2 [CRITICAL, rail] A child walking in mid-scene changes nothing, and the game does not catch it either

`probe.php` PROBE A (same NPC, same scene, the only difference is `witkid=1` on the next snapshot):

```
in scene, adult=1, nothing wrong     mode=scene  x=2  offered=ChangeIntimacy+ChangeClothing  volatile=3092
player speech, witkid=1              mode=scene  x=2  offered=ChangeIntimacy+ChangeClothing  volatile=3092
  explicit permission present: true
  ChangeIntimacy P1 passed the gate: true   ...;do=goto;scene=OARE_GropingButtKiss
  ChangeClothing undress passed: true       ...;do=undress;who=npc;part=all
LEAD TICK, witkid=1                  mode=scene  x=2  offered=ChangeIntimacy+ChangeClothing  actions_on=true
OUTSIDE a scene, witkid=1 (contrast) mode=silent x=NULL offered=none
```

This one has **no second layer**. The game re-checks `witkid` only in `PrivacyBlockedReason()`
(LRG_OStim.psc:710-728), and that is called from exactly two places: the scene start (`:757`) and *undress outside a
scene* (`:1143-1145`). An in-scene `ExtCmdLRG_Clothing@do=undress` reaches LRG_OStim.psc:1140 with `inScene=true` and
skips the block entirely; `ExtCmdLRG_SceneControl` never calls it at all. So server and game both wave it through.
PROTOCOL 6.2 puts "child nearby" under silent, unqualified, and the project rail is "adults only, failing closed -
nothing romantic or explicit is ever generated for or about a non-adult".

### F3 [HIGH, rail] Inside a scene, `adult=1` may be arbitrarily stale

PROTOCOL section 0 states the rail as "adults only, fail closed (**`adult=1` on a fresh snapshot**, re-checked in
game)". `probe.php` PROBE B:

```
speech, snapshot 690s old   mode=scene  x=2  offered=ChangeIntimacy+ChangeClothing  volatile=3092
  snapshot _age = 690s, max allowed = 90s
  explicit permission present: true
```

`snapshot_max_age_seconds` (90) is applied only in `lrgEvaluateGates()` (lrg_core.php:458-459), which the scene branch
never calls. The scene row itself lives for `scene_stale_seconds` = 900 s, so the licensing `adult=1` can legitimately
be ten times older than the contract allows. Scenario 17 checks staleness, but only out of scene (17:11-20).

### F4 [HIGH] The player's own MCM switch, combat and a quest scene are ignored mid-scene

`probe.php` PROBE C, one snapshot key changed at a time on a running scene:

```
in scene with on=0        mode=scene  x=2  offered=ChangeIntimacy+ChangeClothing   -> ChangeIntimacy P1 passed: true
in scene with combat=1    mode=scene  x=2  offered=ChangeIntimacy+ChangeClothing   -> P1 passed: true
in scene with scene=1     mode=scene  x=2  offered=ChangeIntimacy+ChangeClothing   -> P1 passed: true
in scene with adult=0     mode=silent x=NULL offered=none                          -> P1 passed: false
```

`on=0` is the owner flipping intimacy off in the MCM. The glue keeps driving. (The game refuses `ExtCmdLRG_Clothing`
on `IsIntimacyEnabled()` at LRG_OStim.psc:1111, but not `ExtCmdLRG_SceneControl`, and the explicit prompt text is
injected regardless.)

### Smallest correct fix for F1-F4 (one place)

The fix must **not** make the turn silent, or the verbal "stop" rail dies with it (`lrgPostProcessActions` drops
`ExtCmdLRG_SceneControl` when `$turn['scene']` is empty). Replace the guard at lib/lrg_actions.php:360 with a
"blocked scene" branch:

```php
$cfgAge = (int) (lrgConfig()['snapshot_max_age_seconds'] ?? 90);
$sceneOk = $inSceneWithThisNpc && $state !== null
    && (int) ($state['_age'] ?? 9999) <= $cfgAge
    && ($state['adult']  ?? '')  === '1'
    && ($state['witkid'] ?? '1') === '0'
    && ($state['on']     ?? '0') === '1'
    && empty(lrgBuildProfile($npc, $state)['never']);
if ($inSceneWithThisNpc && !$sceneOk) {
    // rails came back on mid-scene: keep ONLY the way out, no explicit wording, no lead ticks, no clothes
    $turn['mode'] = 'scene'; $turn['scene'] = $scene; $turn['blocked'] = true;
    $turn['can_act'] = in_array($type, LRG_PLAYER_SPEECH_TYPES, true);
    $turn['x'] = null; $turn['options'] = []; $turn['ctx'] = null; $turn['lead'] = false;
    lrgHideActions(array_merge([LRG_ACT_START, LRG_ACT_INVITE, LRG_ACT_CLOTHING], LRG_MOVEMENT_ACTIONS));
} elseif ($inSceneWithThisNpc) { ... existing branch ... }
```

and in `lrgSceneNotes()` return early when `!empty($turn['blocked'])` with two plain lines: this cannot go on, if the
player says anything choose `ChangeIntimacy` item `stop`. `lrgResolveControl()` already returns `do=stop` first
(lrg_actions.php:623) and, with `$options = []` and `$ctx = null`, every other item falls through to `null`. Keep the
snapshot-age part behind the config key so the owner can widen it.

**Tests FLOWTESTS must add (these are the missing R9 items, not extras):** scenario 05 gains the same in-scene sweep
it already does for `adult` but for `witkid`, `on`, stale and a `never` profile; scenario 06 gains "Vigilant inside a
menu-started scene stays out"; scenario 17 gains "stale snapshot inside a scene"; and four matching mutations
(M16-M19) that each remove one of the new guards and must be detected. Without them the suite's 466 green checks keep
reading as "the rails hold", which is what sent this work out the door.

## 3. Prompt quality (hunt item 6) - would this really produce plain, direct, unsanitised speech?

### F5 [MEDIUM, R4/R5] The wording line contradicts itself inside one paragraph for quiet and `never` characters

The injected text for a strict/quiet NPC at explicitness 2 (`probe.php` PROBE D, verbatim from the live plugin):

> Wording: fully explicit and vulgar - crude words for body parts and acts are expected, not avoided: never soften
> them, dodge them or swap in euphemisms. Manner of Sigrun Flowtest: says little - a few terse words, mostly breath;
> **stays plain and terse rather than crude.** Only about what is really happening now.

One clause orders crude words and forbids softening them; the next clause, same sentence pair, forbids crude. With
`announce_chance.quiet = 40` this is live: on a winning die she is simultaneously told to name the act crudely and to
stay non-crude. The `never` style is worse - `talk_words.never` = "does not speak during intimacy" is injected
together with the same "fully explicit and vulgar" order. An instruction-following model resolves a contradiction like
this by hedging, and hedging is exactly the sanitised output R5 exists to kill. Smallest fix (BEHAVIOUR owns it):
`lrgSceneNotes()` should pick the *manner* words for the level, not concatenate two independent tables - e.g. drop the
`rather than crude` / `does not speak` tails from `scene_talk.talk_words` and express volume separately from
vocabulary ("says little" is about quantity, "crude" is about register; they must not be phrased as opposites).

**Why the suite cannot see it:** `fxHasExplicitPermission()` (adapter.php:399-403) returns true if the text contains
the literal `scene_talk.explicitness_words[2]` string. It therefore tests routing - did the level-2 string reach this
turn - and is blind to anything the prompt says afterwards. There is no check anywhere in 23 scenarios that the
guidance is internally consistent. Minimum new check: for every talk style x every explicitness level, the injected
guidance must not contain both an "expected, not avoided" wording clause and a clause that negates it; cheapest form
is a curated antonym list applied to the `Wording:` line.

### F6 [MEDIUM, R1] "you lead / I'll lead" is ambiguous, and scenario 09 pins the ambiguity as acceptable

PROTOCOL 6.5 says the `ChangeIntimacy` item set includes `you lead / i lead / auto`; the catalog description
(lrg_actions.php:83) repeats it; but the scene notes offer a *different* key set, `"<npc> leads"` / `"player leads"`
(lrg_actions.php:894). Two key sets for one control in the same prompt is itself the kind of contradiction R4/R6 will
pay for. Worse, `lrgResolveControl()` (lrg_actions.php:645-652) reads a bare `"you lead"` as `who=player`, which is
correct from the NPC's mouth and the exact opposite of what the *player* means when they say "you lead" out loud and
the model echoes the phrase.

The suite encodes this rather than flagging it: 09:60-61 accepts `do=lead;who=(npc|player)` for **both** `i lead` and
`you lead`, and the only check that pins the perspective (09:81-82) is a `shouldCap` - a warning, which `--strict`
would catch but which was written to pass. A test that accepts either answer for an owner-facing control is not a
test. Smallest fix: make the catalog description and the notes use the same two unambiguous keys with no second-person
pronoun (`<npc> leads` / `<player name> leads` / `auto`), and have the resolver drop a bare `you lead` (fail closed,
she just talks) instead of guessing; then turn 09:81-82 into a `must` and add "the catalog description's item list and
the notes' item list name the same lead keys".

### F7 [MEDIUM, R7] The token cap is missing on exactly the turns the owner complained about

`probe.php` PROBE G, running `lrgApplyTurnRuntime()` on each mode:

```
mode=closed   FORCE_MAX_TOKENS=NULL  OPENAI_FILTER_DISABLED=true  asks-one-sentence=false
mode=public   FORCE_MAX_TOKENS=NULL  OPENAI_FILTER_DISABLED=true  asks-one-sentence=true
mode=private  FORCE_MAX_TOKENS=NULL  OPENAI_FILTER_DISABLED=true  asks-one-sentence=true
```

`lrgApplyTurnRuntime()` (lrg_actions.php:458-469) caps `scene`, `follow` and `initiative` only. But the `public` and
`private` guidance already asks for "ONE short sentence, at most 120 characters" (PROBE E), so the instruction is
there and the safety net behind it is not - on the proposition/invitation turn, which is intimate speech and
therefore full TTS time under CHIM's MAIN lock. Scenario 13 asserts the cap for scene talk, lead tick and the
initiative tick (13:38-39, :52-53) and for `follow` only asserts the sentence, not the cap (13:67); `public` and
`private` are not asserted at all. The test matches the code, not PROTOCOL 6.4. Smallest fix: extend the
`elseif` at lrg_actions.php:466 to `in_array($turn['mode'], ['follow','public','private'], true) || !empty($turn['initiative'])`,
and make 13 assert the cap for every mode whose guidance calls `lrgOneSentence()`.

### F8 [LOW-MEDIUM, R5 scope] `OPENAI_FILTER_DISABLED` is set on `closed` turns too

PROBE G line 1: a stranger being refused gets CHIM's refusal-filter bypass. The owner's R5 is explicit that this is
"NOT a global mode". The builder did surface this in their `forIntegrator` note 2, which is to their credit, but
scenario 16:89-90 only pins that *silent* turns leave it alone - so the suite certifies the broad behaviour as
correct. It should instead assert the contract question: the bypass is set only when `$turn['x'] !== null`. (There is
a real reason for `closed` - in-character refusals trip CHIM's word scorer - so this may be a deliberate exception;
then it belongs in PROTOCOL 6.4 in writing, not only in a code comment.)

### F9 [LOW] `lrgIsSayIt()` fails open

`lrg_actions.php:833`: `(int) (LRG_TIERS[$tier] ?? 4) >= LRG_TIERS['sensual']`. An unknown/missing tier defaults to
**4**, i.e. "say it". Everywhere else in this project a missing value means the strict reading. If the index ever
hands back a scene whose tier string is not in `LRG_TIERS`, a gentle position gets the naming instruction. Fix:
`?? 0`. No scenario covers an unknown tier.

## 4. Smaller things, ranked

1. **`fxSceneProblem()` / scenario 99 is a look-back over what this run happened to touch** (99:7 gives up under 5
   ids). With `--only` it silently judges almost nothing, and it can never see a scene the suite did not provoke. It
   reads as "the hard exclusion list holds" and is really "nothing the 22 other scenarios did tripped it". Say so in
   the report's scenario table, or fold the standing check into SCENEINDEX's own tests where the whole index is walked.
2. **`fxOptionMarks()` layout coupling** (adapter.php:413-436) is honest about being a parser, but a plugin edit that
   moves the marks onto their own line would flip 14's checks from FAIL to a silently different layout being accepted
   by branch (b). Have `fxOptionMarks()` return the layout it used and make 14 assert it, so a layout change is
   visible rather than absorbed.
3. **`fxHasExplicitPermission()`'s config-string arm makes the x=2 assertions near-tautological** (see F5). Keep the
   meta-vocabulary regex as the real check and treat the config-string match as a secondary signal.
4. `--strict` is the release gate, but `run_flows.ps1` does not pass it by default and `mutation_check.ps1` runs the
   suite **without** `--strict` (line 62), so a mutation that turns a `must` into a passing run with new warnings
   counts as NOT DETECTED. Cheap: add `--strict` to line 62.
5. Note 4 in the builder's report ("keep statics for config and index only") is right and important; it belongs in
   PROTOCOL 7.3 rather than in a report nobody re-reads.

## 5. Verdict: **REWORK**

The engineering is good and the claims are honest - I reproduced 466/466 green, 15/15 mutations killed, and every CHIM
fact the harness stubs checks out against the installed 3.3.2 source, including the load-bearing "an empty message
produces no filler line". But the suite's shape encodes a wrong belief: that the hard gates only have to hold *before*
a scene, and that once `lrg_scene_state` has a row the glue may trust it. Four of the owner's own non-negotiables -
child nearby, the NPC must be willing, the `never` opt-out, and adult-on-a-fresh-snapshot - stop applying the moment
an OStim thread exists, and because OStim's own menu is a supported way to start one (and PLAYTEST2 deliberately gave
menu-started threads full scene awareness), that is a path the owner will actually walk. The game side does not catch
it either: `PrivacyBlockedReason()` is never called for an in-scene command. On top of that, the two prompt defects
that decide whether R5 and R1 are felt in game at all - the "crude / not crude" self-contradiction for quiet
characters and the two conflicting lead key sets - are both invisible to a suite that checks markers rather than
coherence, and the lead ambiguity was actively pinned as acceptable by a `shouldCap`. Ship the harness, but not as
proof: F1-F4 need BEHAVIOUR's guard plus four new scenarios and four new mutations, F5-F7 need the prompt and the cap
fixed with checks that would have caught them, and only then does "23/23, 0 FAIL" mean what the owner will read it to
mean.
