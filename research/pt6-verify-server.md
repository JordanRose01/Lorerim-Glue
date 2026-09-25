# pt6-verify-server.md — independent verification, SERVER CORRECTNESS lens

Verifier: read-only except this file and throw-away scratch in `%TEMP%\lrg_verify`.
Date 2026-09-21. Nothing deployed, nothing installed, no game run, no project file modified.

## VERDICT: **FIX FIRST**

Four of the round's own goals do not hold in the built code (G1 twice, G2, G3). Everything else I
could check held up, and every test claim in both build reports reproduced exactly.

---

## 0. What I reproduced myself

Staged the project to `%TEMP%\lrg_verify` (the builders' `%TEMP%\lrg_test` was clobbered by another
process mid-run, so I moved to an isolated folder), ran everything inside WSL `DwemerAI4Skyrim3`,
PHP 8.2.28, scene index rebuilt from the live MO2 profile (607 scenes, 5.9 s):

| check | result |
|---|---|
| `php -l` on all 45 plugin + tool files | clean |
| `tools/test_intent.php` | 105 passed / 0 failed (OK) |
| `tools/test_gates.php` | 206 passed / 0 failed |
| `tools/test_scene_index.php` | ALL CHECKS PASSED |
| `tools/flows/run_flows.php --strict` | 32 scenarios, 32 passed, 0 FAILED, 0 pending, 644 checks, 0 warnings |

Version bumps confirmed against the **deployed v0.2** tree in WSL
(`/var/www/html/HerikaServer/ext/lorerim_glue`): manifest `0.2.0 -> 0.3.0`,
`LRG_ACTIONS_VERSION 7 -> 8`, `LRG_SCHEMA_VERSION` unchanged at 2 (correct: v0.3 only adds keys
inside existing jsonb payloads).

Then I wrote my own throw-away replay (`%TEMP%\lrg_verify\replay*.php`, ~30 checks) that drives the
REAL `lrgHandleGameMessage` / `lrgPrepareTurn` / `lrgVolatileGuidance` / `lrgPostProcessActions`
against a fake DB and the real index, with the utterance list from the round prompt and four
fake-LLM reply shapes each. That is where the defects below come from — the shipped suite passes
through all of them.

## 1. Defects

### D1 — MAJOR / server (+ docs) — "you lead" is answered with `hold=150`, so the game holds her lead anyway

`lrg_actions.php:1239-1240` (`lrgDecorate`) appends `hold=` to **every** command emitted on a player
speech turn, from `lrgHoldSeconds()` (`:1245-1254`), which looks only at `$turn['type']` and never at
the intent. So a `do=lead;who=npc` that exists *because the player said "you lead"* carries a 150 s
hold.

Measured, both paths:

```
the model complied  -> ExtCmdLRG_SceneControl@ok=1;cid=..;npc=Lisette;do=lead;who=npc;hold=150
the net fired       -> ExtCmdLRG_SceneControl@ok=1;cid=..;npc=Lisette;do=lead;who=npc;hold=150
```

The game build report's own hand-over says the opposite in plain words:

> "SERVER-SIDE NOTE: do not decorate a `lead;who=npc` that answers a `lead_npc` intent with a long
> `hold=`, or the game will hold her anyway."

because game side "clears `leadHoldUntil` and then re-applies whatever `hold=` the same command
carried". The server side of that hand-over was not implemented. `PROTOCOL.md:91` likewise documents
`hold=` on "every `SceneControl` verb" with no exception.

The server's OWN clocks are cleared correctly (`lrgNotePlayerSpeech`, `lrg_actions.php:266-268`) and
flow 20 asserts exactly that — which is why the suite is green while the wire is wrong.

**Effect:** G2's "'You lead / do what you want / surprise me' hands her the lead" fails. She is muted
for 150 s at the one moment the player asked her to take over.

**Smallest fix** — `lrg_actions.php`, end of `lrgDecorate()`:

```php
$hold = lrgHoldSeconds($turn);
$handOver = $do === 'lead' && (string) ($kv['who'] ?? '') === 'npc'
    && (string) ($turn['intent']['kind'] ?? '') === 'lead_npc';
if ($hold > 0 && !$handOver) { $kv['hold'] = $hold; }
```
plus one sentence in `PROTOCOL.md:91`.

### D2 — MAJOR / server — the safety net cannot correct a wrong-`who` clothing command: the playtest-6 who-inversion survives end to end

`lrgIntentSatisfiedBy()` compares only the VERB, never the payload
(`lrg_actions.php:1203` `if (strcasecmp($code, LRG_ACT_CLOTHING) === 0) { return $kind === $do; }`).
So an `undress who=npc` counts as satisfying an `undress who=player` request and the net stands down
(`lrgSafetyNet`, `:1334-1336`, logs `net skipped: the reply already carries it`).

Measured (player: *"I want you to take my clothes off"*):

```
intent       do=undress; who=player; part=all        (correct)
directive    ... choose ChangeClothing with item "undress you" ...   (correct)
LLM emits    ChangeClothing@undress                  (the wrong who, as in playtest 6)
WIRE         ExtCmdLRG_Clothing@ok=1;..;do=undress;who=npc;part=all;hold=150
```

Her clothes come off, the player's do not, and nothing in the log says anything was wrong. This is
the exact failure class `lrg_intent.php:10-12` says the file exists to fix — the recogniser was
fixed, the enforcement path was not.

Same blind spot for `speed` (value ignored, `:1209`) and `furniture` (type ignored, `:1207`); I
confirmed the speed case directly.

**Smallest fix** — make the comparison payload-aware, and (to keep "one command per turn") correct
the model's own line instead of adding a second one. In the `LRG_ACT_CLOTHING` branch of
`lrgPostProcessActions` (`:1120-1131`), right after `lrgResolveClothing()`:

```php
$in = (array) ($turn['intent'] ?? []);
if (in_array((string) ($in['kind'] ?? ''), ['undress', 'dress'], true) && ($in['conf'] ?? '') === 'high'
    && (string) ($in['kind']) === (string) $kv['do']) {
    $kv['who'] = (string) ($in['kv']['who'] ?? $kv['who']);
    $kv['part'] = (string) ($in['kv']['part'] ?? $kv['part']);
}
```
and tighten `lrgIntentSatisfiedBy` for `speed` / `furniture` the same way (compare `speed` / `furn`).

### D3 — MAJOR / server (+ docs) — a `BeginIntimacy` with an EMPTY message still starts the scene whenever the player asked for contact

`lrgIntentSatisfiedBy()` line `:1204`:

```php
if (strcasecmp($code, LRG_ACT_START) === 0) { return in_array($kind, ['act', 'undress'], true); }
```

makes the start "not self-initiated", so `$emit`'s say-it-first check (`:1066`) is skipped entirely.

Measured, `talkedSoFar = ''` (no spoken text at all in the reply):

```
player "I want you right now" (no contact request) -> StartIntimacy DROPPED   (correct)
player "kiss me"                                   -> ExtCmdLRG_StartIntimacy@...;wait=end  SENT
player "get naked"                                 -> ExtCmdLRG_StartIntimacy@...;wait=end  SENT
```

The game then waits `fSayFirstMaxWait` for a line that never arrives and starts anyway: a dead pause
followed by a wordless OStim fade — the owner's headline complaint, on the commonest path (the
player usually does ask).

G3(a) is unconditional ("**whenever** she calls BeginIntimacy her reply must FIRST carry a spoken
line") and G3(b) is the server-side guarantee for it. R10's "changes the PLAYER asked for need no
announcement" is about *changes inside a scene*, not about the start. `PROTOCOL.md` contradicts
itself here: `:200` "BeginIntimacy … an empty message is never allowed" vs `:216` "…and anything the
player asked for are exempt".

**Smallest fix** — `lrg_actions.php:1204`, delete the branch so a start is always treated as needing
her line:

```php
if (strcasecmp($code, LRG_ACT_START) === 0) { return false; }
```
(`lrgDecorate` is unaffected: `StartIntimacy`'s kv has no `do`, so no `nowarp` is added; flow 21 and
flow 14 keep passing.) Then correct `PROTOCOL.md:216` to exempt only `goto` / `furniture` /
`undress` / `dress`, never `StartIntimacy` / `SuggestPrivacy`.

### D4 — MAJOR / server — the `lrg_initiative` prompt cue still licenses "an action with an empty message", and the post-gate then throws that turn away

`prompts.php:57`:

```php
'cue' => ["($lrgName acts on how they feel about the player right now, in their own way, as this
moment's notes describe: an action with an empty message, or one short plain spoken sentence ...)"]
```

The cue is the LAST text in the prompt. On an initiative tick the recognised intent is always `none`,
so every action she picks is self-initiated and a reply with an empty message is dropped at
`lrg_actions.php:1066-1070`. `PROTOCOL.md:200` claims the licence "is deleted everywhere"; it is
still here, and it is the only place in the prompt that tells her she may act silently.

**Effect:** a paid LLM + TTS turn that produces nothing, she visibly fails to act, and the one-shot
re-ask costs another turn — a re-creation of the older "NPCs would not act" complaint, on the one
request type that exists to make her act.

**Smallest fix** — `prompts.php:57`:

```php
'cue' => ["($lrgName acts on how they feel about the player right now, in their own way, as this moment's notes describe: one short plain spoken sentence of $lrgMax characters or less that makes plain what they are about to do, then the action; no description, no poetry) $lrgTpl"],
```

### D5 — MINOR / server + docs — `command_confirm_seconds` 20 is below the game's say-first wait budget, so the G6 watchdog will cry wolf

`config/lrg_config.default.json:221` ships `"command_confirm_seconds": 20`, and its own readme at
`:219` says "it must stay above the game's own say-first wait budget". The game build report's
hand-over is explicit: ">= 20 + fSayFirstMaxWait (32 s with the defaults), because a parked command's
funcret only fires when the OStim call really happens." `PROTOCOL.md:303` documents 20 as well.

**Effect:** healthy `wait=begin` / `wait=end` commands produce
`WARN command not confirmed by the game …` and `pending_cmd` is cleared, so the real watchdog is
blinded exactly on the commands that need it most. No functional damage (no retry).

**Fix:** `"command_confirm_seconds": 40` in the default config, and the same number in
`PROTOCOL.md:303`.

### D6 — MINOR / server — the R11 proposal paths re-store the scene row with its own `ev`, resetting `_tier_since` and double-counting `_climaxes`

`lrgRecordProposal()` (`lrg_actions.php:1380-1384`) and the "no" branch of
`lrgApplyProposalAnswer()` (`:951-955`) hand the scene payload straight back to `lrgStoreScene()`
with its `ev` key intact. `lrgTrackProgression()` (`lrg_core.php:228-235`) then treats a row whose
last event was `start` as a NEW scene (`_tier_since = lrgNow()`), and adds another climax whenever
the last event was `climax`.

Measured:

```
row after ev=start:            _tier_since=1790020799  _maxtier=2
after lrgRecordProposal:       _tier_since=1790021099   (reset 300 s forward)
after the "no" branch:         _tier_since reset again (300 s)
_climaxes after ev=climax: 1 -> after a proposal record: 2   (no second climax happened)
```

**Effect:** her tier ceiling clock and R11's `min_act_seconds` clock restart after every proposal and
every "no"; the scene notes say "a climax has already happened" too eagerly and the G5.10 end-of-scene
summary is wrong.

**Fix** — in both callers, drop the event before re-storing:

```php
unset($row['_npc'], $row['_age'], $row['ev'], $row['sync']);   // lrgApplyProposalAnswer
unset($scene['_npc'], $scene['_age'], $scene['ev'], $scene['sync']); // lrgRecordProposal
```
(`active` stays 1, because `lrgStoreScene` only closes on `ev === 'end'`.)

### D7 — MINOR / docs — `PROTOCOL.md` still documents the deleted v0.2 "say it / silent" machinery as the current R10

`PROTOCOL.md:188` (section 6.4, not marked historical):

> "R10: an offered option is 'say it' when its tier is `sensual` or `sexual`, 'silent' when
> `neutral / affection / kissing` … when `announce` is false or the option is silent, the notes tell
> her to leave `message` empty."

That is exactly the rule owner addendum 2 / revised R10 replaced, and it contradicts `:200` and 6.8
(`:216`) inside the same file. It also still names `announce_chance`, renamed to `blunt_chance`.
`lrgIsSayIt()` is confirmed dead (1 occurrence in the whole plugin).

`PROTOCOL.md` is the one document the next round is told to read as the contract, so this is the most
likely way the rule gets re-broken.

**Fix:** replace the bullet with the v0.3 rule (every change she initiates herself is preceded by one
short spoken line; `blunt_chance` only decides how blunt it is, never whether she speaks), and either
delete `lrgIsSayIt()` or comment it as kept for `test_gates.php` only.

### D8 — MINOR / server + docs — two different log lines share the prefix `turn npc=`, contradicting the build report's stated deviation and PROTOCOL §9

The build report claims: "The old second 'turn npc=… in_scene=…' log line was deleted … two lines
with the same prefix made the log unparseable by prefix, which is what G6 exists to prevent."

It was not: `lrg_actions.php:799` still writes
`turn npc=%s mode=%s%s offer_start=%s reasons=… aff=… interest=… invite=… profile=…`
alongside the G6 line at `:979`, and `:628` writes a third shape
(`turn npc=$npc in_scene BLOCKED …`). Measured on one out-of-scene speech turn: **2** `turn npc=`
lines with different field sets. `PROTOCOL.md:308-310` promises "three fixed, greppable lines per
turn".

**Fix:** rename `:799` to `gate npc=…` and `:628` to `blocked npc=…`, and list them under
`PROTOCOL.md` §9 as the extra lines they are.

## 2. Observations (not defects)

- The `heard npc=… say="…" intent=…` line carries no `[cid=]`, because it is written pre-lock in
  `lrgNotePlayerSpeech()` before a cid exists. PROTOCOL §9 says the turn lines are "all under the
  cid" and does not list `heard` at all. G6 is still satisfied — the `turn` line repeats
  say / intent / conf under the cid — but §9 should mention it.
- *"kiss me"* while the current scene already IS a kissing scene resolves to
  `cant = "not from here"` and the directive says "That is not possible from here". Correct per
  G5.8 (never silence) but it reads oddly; an "you already are" branch would be nicer.
- `intent.words.lead_player` is hardcoded `"to lead himself"`; it is config-editable, so only a nit.
- `lrgHoldCarrier()` still fires on a scene turn the snapshot has NOT confirmed
  (`scene_confirmed = false`). It costs one funcret round trip, but the game answers
  `Error: no scene is running`, which closes the stale row (G4 path 1). Net positive; leaving it.

## 3. What I checked and found sound

- **Intent recogniser (G1).** All 14 prompt utterances classified as intended, in and out of a scene:
  `get naked` / `take your clothes off` / `strip for me` -> `undress/high`;
  `don't get naked yet` -> blocked `negated`; `should I take my clothes off?` and
  `do you like it rough?` -> blocked `question`; `fuck me from behind` -> `act/vaginal/high` ->
  `do=goto;scene=OARE_RearSexFPerformer`; `faster` / `slow down` / `stop` / `you lead` / `I'll lead`
  all `high`; `naked` alone correctly `low` (net stands down); `come to bed with me` -> `furniture`
  once a bed is in `nearf`. Word-order variants and the `who` inversion are recognised correctly.
- **Directive shapes.** DO-IT only inside a confirmed running scene at `conf=high`; MAYBE at `low`;
  CANT when the game cannot do it, naming real alternatives; the "no to her proposal" shape; and
  outside a scene the same words never produce an order (verified for all five out-of-scene
  utterances). No example dialogue anywhere in it.
- **Safety net preconditions.** Does not fire without `scene_confirmed` (snapshot `ostim=0`), under
  `scene_blocked`, with the owner switch off, at `conf != high`, or when the kind is outside
  `net_kinds`. Never emits `StartIntimacy` or `SuggestPrivacy`, never outside a scene, at most one
  line. `lrgDropUnaskedGoto` correctly removes a `goto` whose scene does not carry the requested act
  and lets the net emit the right one.
- **Owner addendum 3b fully honoured.** No `Decline` action, no refusal-marker scan, no per-act
  decline memory in any of the three libs (grepped for `ExtCmdLRG_Decline|LRG_ACT_DECLINE|
  refusal_markers|lrgIsRefusal|_declined|decline_memory`). R11's `_prop_no` (the player's no to HER
  proposal) is retained, as the addendum requires.
- **G4.** All three close paths work: `funcret … Error: no scene is running` closes the row at once;
  a changed `sess` on a snapshot closes every open row; a newer `ostim=0` snapshot closes a row whose
  `end` was lost.
- **Old game script (version 200 / v0.2 messages).** A scene message with no `sess` / `npos` / `prev`
  / `sync` and a snapshot with no `sess` still produce a correct scene turn with
  `scene_confirmed = true`, and only additive keys (`hold` / `wait` / `nowarp`) are added to the
  otherwise-v0.2 wire shape.
- **DB.** Both migrations are `CREATE TABLE IF NOT EXISTS`, run behind a version marker — idempotent.
- **Catalog rows vs CHIM's strict schema.** All five rows fill only `target` / `item`, `required` is
  always a subset of `properties`, descriptions 139-313 chars, `import_version = 8` on every row.
- **Timestamps (G6).** Every line in a captured run carries one offset (`-04:00`, `America/New_York`
  = the owner's own clock); no raw `date()` remains on any logging path (only the two marker files).
- **Heat gate (G3d).** Increment-then-compare: the 2nd romantic exchange opens `BeginIntimacy`; a
  player request for contact (`act` / `undress`) opens it at once; the post-reload and post-scene
  cooldowns are checked both pre-lock (`lrgInitiativeAdmit`) and at offer time
  (`lrgStartHeldBack`), with the reason in `hidden=`.
- **R10 for changes she initiates.** A silent `RequestAct` / `goto` / `undress` / furniture move on a
  lead tick is dropped and re-asked; with a line it goes out carrying `wait=begin;nowarp=1`; the drop
  does not count as `lead_idle`.

## 4. Test-suite blind spots that let D1-D3 through

- Flow 20 asserts that "you lead" clears the server's own clocks and that the next lead tick is
  served, but never inspects `hold=` on the command that same turn emits.
- Flow 21 covers a *self-initiated* silent `BeginIntimacy` but never the player-requested one.
- Nothing anywhere feeds a wrong-`who` `ChangeClothing` against a `who=player` intent;
  `09_verb_set.php:129` only checks that the LLM's literal `"undress you"` resolves.

Three assertions would close them:
`hold` absent on a `lead_npc` answer · `StartIntimacy` with an empty message dropped after
`"kiss me"` · `ChangeClothing@undress` against a `who=player` intent ends up `who=player` on the wire.
