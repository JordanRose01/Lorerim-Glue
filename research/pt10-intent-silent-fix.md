# pt10 - the second bug: the recogniser that never ran

LoreRim Glue, **server lane only**. Written 2026-09-22 after `research/pt10-snapshot-fix.md` section 3
and `research/pt10-snapshot-verify.md` section 1.6 named it. Nothing is deployed: the change lives in
`glue\server\lorerim_glue\**` in the project tree only. `/var/www/html/HerikaServer/ext/lorerim_glue`
is **untouched**, and so are the game scripts, MO2 and CHIM core.

---

## 0. The short version

`lib\lrg_actions.php:1121` ran `lrgRecogniseIntent()` only for `mode in [closed, public, private,
follow]`. `lrg_core.php:1222` turns `no_fresh_snapshot` into `mode = silent`. So for the whole of
playtest 10 - thirty minutes in which the game never sent a snapshot - the player's sentences were
**never parsed at all**. Two different failures were wearing one face:

* **the rail working**: no action was offered and nothing was executed. That must not move, and it
  has not moved.
* **the defect**: "kiss me", "come hug me", "take your clothes off" produced `intent=none` in every
  turn line, no `heat`, no quote memory, no directive - and nothing anywhere in the log said what the
  player had actually asked for. That is not a safety property. Understanding a sentence never was.

The recogniser now runs on **every player-speech turn** whose silence is only blindness, the turn line
always carries the real intent, and a blind turn tells the model plainly - in a short, action-free,
wording-free note - that the glue has no fresh facts, instead of leaving it to fill thirty minutes of
silence by itself.

---

## 1. What changed

All of it in `glue\server\lorerim_glue\lib\lrg_actions.php`, plus one header comment in
`context_pre.php`. **The wire is unchanged in both directions. No config key was added. No migration.**

### 1.1 One new fact on the turn: `blind`

`lrgPrepareTurn()` :1120-1142

```php
$reasons = (array) ($gate['reasons'] ?? []);
$last    = (array) ($gate['state'] ?? []);   // the last facts the game EVER sent for her, however old
$blind   = $mode === 'silent' && in_array('no_fresh_snapshot', $reasons, true)
        && ($last['adult'] ?? '1') !== '0' && ($last['on'] ?? '1') !== '0';
$turn['blind_note'] = $blind && ($last['adult'] ?? '') === '1' && ($last['on'] ?? '') === '1'
        && in_array($type, LRG_PLAYER_SPEECH_TYPES, true);
```

`lrgEvaluateGates()` **returns the moment the snapshot is missing or stale** (`lrg_core.php:1149`), so
`no_fresh_snapshot` can never be mixed with `not_adult`, `child_nearby`, `never` or `feature_off`:
`reasons === ['no_fresh_snapshot']` exactly. That is what makes "the glue knows nothing" separable
from "the glue knows, and the answer is no" - and only the first of those is a fault.

Two flags, because the two consequences are not the same:

| flag | drives | rule |
|---|---|---|
| `blind` | the recogniser, `heat`, the log line | silent for no other reason, **and** the last facts the game ever sent do not themselves say "off limits" |
| `blind_note` | the one injected sentence | `blind`, **and** the game really has reported on her at some point as `adult=1` + `on=1`, **and** this is player speech |

### 1.2 The recogniser runs on a blind turn

:1163 - the condition became `($blind || in_array($mode, ['closed','public','private','follow'], true))
&& in_array($type, LRG_PLAYER_SPEECH_TYPES, true) && $npc !== ''`.

Everything inside the block is as it was. `$turn['ctx']` is built from the LAST facts rather than
fresh ones, which is why that context is used **for nothing but recognition**: the two sexes do not
change, and `nearf` can at worst make the recogniser resolve a furniture act that this turn will never
offer anyway.

`heat` now counts on a blind turn (`lrgBumpHeat`), and **mode `closed` still does not** - pressing
somebody who has refused must never warm her up. Heat is the conversation's own build-up counter, not
a rail: `lrgStartHeldBack()` only decides whether the action exists on *this* turn, and every hard gate
is recomputed from the next fresh snapshot before anything can be offered. Losing it is exactly why
the first good turn after a blind stretch would still have answered the tenth request in a row with
"a question about this is a question".

`lrgRememberQuote()` is inert on a blind turn by construction: there is no `$gate['price']` at all
(the gate returned before it was computed), so nothing is ever quoted that the glue cannot stand behind.

### 1.3 The directive

`lrgVolatileGuidance()` :2701-2718, ahead of the `closed/public/private/follow` branch:

```
<this_moment>
The game has sent no fresh facts about <npc> this moment, so nothing about <npc>'s situation is known
here and nothing physical can be set up, agreed or carried out on this turn. Answer the player in
words, in <npc>'s own voice. Do not act anything out, and do not write as though something were being
done - say it plainly instead, or simply answer.
</this_moment>
```

It is **strictly more restrictive than the silence it replaces**. It names no action, permits no
wording, carries no `<player_request>` directive (with no facts, the money shapes and the act layer
would be answering out of nothing), leaves the static `<personal_boundaries>` block empty, and
`lrgApplyTurnRuntime()` still returns early on `silent`, so CHIM's own token budget and refusal filter
are left exactly as CHIM made them.

### 1.4 The log line

End of `lrgPrepareTurn()` :1271, next to the `gate` line, once per request:

```
silent: no fresh snapshot (age=142s) npc=Lisette - no action offered, nothing executed; she answers in words (the player asked for undress)
silent: no fresh snapshot (age=none) npc=Belethor - no action offered, nothing executed; she answers in words
```

Its own prefix: PROTOCOL 9 promises exactly the `turn` / `llm` / `net` / `gate` / `price` shapes behind
those prefixes, and a second shape behind one of them is what makes a log unparseable. `age=none`
(the game never reported on her at all) reads differently from `age=142s` (the game is late or has just
gone quiet) at a glance - which is the difference between pt10 and an ordinary conversation.

---

## 2. The SAFETY property, held exactly

Asserted end to end, not argued:

* **Nothing is offered.** `$offer` is `[]` for mode `silent`; all five glue actions are hidden with
  `hidden=... (mode silent)`. `$turn['offered'] === []`.
* **Nothing can be executed.** Every branch of `lrgPostProcessActions()` drops on a blind turn for a
  reason that has nothing to do with the intent: `StartIntimacy` needs `gate['ok']` + mode
  private/follow, `ChangeIntimacy` / `RequestAct` need `$turn['scene']`, `ChangeClothing` needs a scene
  or `gate['ok']` + private/follow, `SuggestPrivacy` needs mode `public` + places. The generic
  "not offered this turn" check drops them before that. Tested with all five codes **and** their CHIM
  display names, five item spellings each.
* **The safety net never fires.** `lrgSafetyNet()` requires `mode === 'scene'` and
  `scene_confirmed`; out of a scene it now logs `net skipped: not an open scene turn` instead of
  returning silently on `intent=none`, which is strictly more visible. `lrgSecondCommand()` and
  `lrgHoldCarrier()` are bound to the same rail and are untouched.
* **`x` stays null** (no explicit wording) and `lrgStaticGuidance()` still returns `''`.

And the silences that are **not** blindness are bit-for-bit as they were - no recognition, no note,
nothing in the log:

* the snapshot says `adult=0`, or `witkid=1`, or the profile is `never`, or the owner's switch is off;
* the stale facts the glue still holds say `adult=0` or `on=0` (stale facts are not facts, but they are
  good enough to stay **quiet** on - the same fail-closed reading every other rail uses);
* the kill switch, SHARMAT, `not_a_person` (the Narrator), and every non-speech request type.

**An NPC the game has never reported on gets nothing at all.** That is the one narrowing of the brief
and it is deliberate: `blind_note` requires a row that positively said `adult=1` + `on=1`. Without it,
the glue would have injected that note into **every ordinary CHIM conversation in the game** the moment
the game side was switched off or simply was not watching that NPC - which is the opposite of
PROTOCOL 6.2. The log line still names her, with `age=none`, because the log costs nothing and that is
the owner's diagnostic.

---

## 3. Tests

**`tools\test_gates.php` - new section 8b, 13 checks** (319 -> **332 passed, 0 failed**), with a
`blindTurn()` helper that ages the stored snapshot exactly as section 8 does and can also play an NPC
with no row at all:

```
8b. [0.5.2 / pt10] a blind turn is still UNDERSTOOD - and still offers nothing
  ok   the turn is silent for exactly one reason: no fresh snapshot  [no_fresh_snapshot]
  ok   the request is RECOGNISED although the turn is silent  ["undress"/"high"]
  ok   ... and the turn line carries it, so the log says what the player asked for
       [turn npc=Hulda type=inputtext mode=silent ... say="take your clothes off" intent=undress/npc
        conf=high ... offered=none hidden=...,Start(mode silent),Clothing(mode silent),Invite(mode silent)
        ... heat=1 ...]
  ok   the conversation still warms up while the game is quiet (heat is a build-up counter, not a rail)
  ok   NOTHING is offered on a blind turn
  ok   no explicit wording is permitted and no boundaries block is injected
  ok   the safety net never fires on a blind turn
  ok   every glue action the model could choose is still dropped
  ok   the model is told there are no fresh facts, and to answer in words
  ok   ... in one short block that names no action and carries no player_request directive
  ok   a minor whose snapshot went stale stays totally silent: nothing recognised, nothing injected
  ok   ... and so does an NPC whose last snapshot said the owner's switch was off
  ok   an NPC the game never sent a snapshot for gets NOT ONE CHARACTER of guidance
```

**`tools\flows\scenarios\27_blind_snapshot.php` - new scenario, 23 checks**, seven parts: (a) the
sentence is understood and reaches the turn line; (b) nothing is offered, every glue line from the
model is dropped, the net does not fire and says which precondition failed, nothing reaches the game;
(c) the note is there, is short, names no action and leaves CHIM's runtime alone; (d) an NPC the game
never reported on gets no injection (log line only, `age=none`); (e) a stale `adult=0` and a stale
`on=0` stay totally mute, **including no recognition**; (f) the build-up survives the blind stretch and
the ordinary turn comes straight back when a snapshot arrives; (g) mode `closed` still does not warm up.

**`tools\flows\scenarios\17_bridge_facts.php`** - the one existing assertion that contradicted the new
behaviour ("mode silent, both guidance strings empty") was replaced by two: the static block and the
wording permission are still empty, and the model is now told in words only, with no directive and no
action named. Scenario 16's "an NPC without any snapshot ... nothing injected" passes **unchanged** -
that is the case section 2 narrows for.

### Full runs (staged copy, WSL `DwemerAI4Skyrim3`, PHP 8.2.28)

| | before | after |
|---|---|---|
| `php -l` on every `.php` under `server\lorerim_glue` and `tools` | clean | **clean** |
| `test_gates` | 319 / 0 | **332 / 0** |
| `test_intent` | 190 / 0 | **190 / 0** |
| `test_phrases` | 14 / 0 (hit rate 91.9 %) | **14 / 0** |
| `test_scene_index` | ALL CHECKS PASSED | **ALL CHECKS PASSED** |
| `test_dialogue` | 174 / 0 | **174 / 0** |
| `test_prompt_index` | 67 / 0 | **67 / 0** |
| `test_mcm_wiring` | 4 / 0 | **4 / 0** |
| `test_services` | 35 / 0 | **35 / 0** |
| `test_latency` | 26 / 0 | **26 / 0** |
| `flows\run_flows.php --strict` (main + 4 child variants) | 74 scenarios, 1179 checks, 0 FAIL, 0 pending, 0 warn | **75 scenarios, 1203 checks, 0 FAIL, 0 pending, 0 warn - RESULT: OK** |

The flow runner's own gates are part of that: no PHP notice was raised and no database call fell
outside PROTOCOL section 5.

---

## 4. Files changed

| file | change |
|---|---|
| `glue\server\lorerim_glue\lib\lrg_actions.php` | `lrgPrepareTurn()`: `blind` / `blind_note` on the turn (:893, :1120-1142); the recogniser condition (:1163); `heat` on a blind turn, `closed` unchanged (:1176-1183); the `silent: no fresh snapshot` line (:1271). `lrgVolatileGuidance()`: the blind-turn note (:2701-2718) |
| `glue\server\lorerim_glue\context_pre.php` | header comment: the one documented exception to "a silent turn injects nothing at all" |
| `glue\tools\test_gates.php` | `blindTurn()` + section 8b (13 checks) |
| `glue\tools\flows\scenarios\27_blind_snapshot.php` | **new** - 23 checks |
| `glue\tools\flows\scenarios\17_bridge_facts.php` | the stale-snapshot silence assertion re-stated |

---

## 5. Two things for the owner / other lanes

1. **`glue\PROTOCOL.md:298` is now out of date and is NOT mine to edit.** The mode table says
   mode `silent` -> "BOTH guidance strings are `''`". That is still true for every silence except the
   one this change adds. The row needs: *silent, and the only reason is `no_fresh_snapshot` on an NPC
   the game has reported on before as an adult with the feature on -> the volatile string carries one
   short note that names no action; the static string is still `''` and `x` is still null.*
   Line 513's flow-test description is about the non-adult case and is unaffected.
2. **Versions are out of step and I left them alone.** The game script is `CurrentVersion = 502` and
   the project is being called v0.5.2, while `server\lorerim_glue\manifest.json` and `LRG_VERSION`
   (`lrg_core.php:16`) both still say `0.5.1` - which is also the `version=` line
   `tools\install_mo2.ps1` writes into `meta.ini`. Bumping it is a release decision that touches the
   installer, so it is flagged rather than done.

**Not deployed.** `tools\deploy_server.ps1` was not run, postgres was not started, and
`install_mo2.ps1` was not run either (nothing in this change touches the game side).
