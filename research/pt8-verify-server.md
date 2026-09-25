# pt8-verify-server.md - INDEPENDENT VERIFIER, lens: SERVER + INDEX

v0.4.0, dry-run ship. Verifier is read-only except this file. Everything below was RUN, not read.
Staged copy `C:\Users\Jordan\AppData\Local\Temp\lrg_test\vsrv\glue` (fresh copy of `glue\`), WSL
`DwemerAI4Skyrim3`, PHP 8.2.28, python3 3.11.2. Nothing was written to Postgres, to HerikaServer, to
`F:\Modlists` or to the project.

**VERDICT: FIX FIRST.** Two CRITICAL defects make menuless questing non-functional on the real wire
(C1, C4); a third (C5) executes engine dialogue entries with none of the round's safety rails. All three
are invisible to the existing suites because every offline test feeds a shape the game never sends.

---

## 0. What I ran, and what reproduced

| claim in a build report | my result |
|---|---|
| `php -l` clean on 60 files | **REPRODUCED** - 60 files, 0 failures; `build_prompt_index.py`, `make_esp.py`, `esp_dump.py` all compile |
| `test_dialogue.php` 85 / 0 | **REPRODUCED** exactly, exit 0 |
| `test_gates.php` 296 / 0 | **REPRODUCED** exactly, exit 0 |
| `test_intent.php` 183 / 0 | **REPRODUCED** exactly, exit 0 |
| `test_phrases.php` 14 / 0, hit rate 91.8% vs floor 82%, money 75/75 | **REPRODUCED** exactly, exit 0 |
| `test_scene_index.php` ALL CHECKS PASSED | **REPRODUCED**, exit 0 |
| `run_flows.php --strict` 58 scenarios, 897 checks, 0 FAILED, 0 warnings | **REPRODUCED** exactly |
| server lane CAVEAT "run_flows still exits 1 (pre-existing DB read `lrg_scene_state ... active=0`)" | **DOES NOT REPRODUCE.** exit=**0**, `RESULT: OK`, no mutation report at all. The INTIMACY lane rewrote `lrg_actions.php` (mtime 00:37) after the server lane's run. The release gate is green on exit code alone; that "forIntegrator" note is stale - do not action it. |
| index: 3,496/3,496 plugins, 37,561 prompts, 5,718 layers, 1 unreadable, DSD DIALOGUE 0 | **REPRODUCED byte-for-byte** on my own rebuild (23.4 s read, 35.0 s total). The live Postgres already holds exactly 37,561 / 5,718, so the build is deterministic. |
| `test_prompt_index.php` "71 passed" | **67 passed**, 0 failed, exit 0, on an index identical to theirs (see C8) |
| migrations idempotent | **CONFIRMED.** 005/006 are `CREATE TABLE/INDEX/EXTENSION IF NOT EXISTS` only; `lrgDlgEnsureSchema()` does not write its marker when there is no DB. `pg_trgm` is installed and `dwemer` is superuser, so `CREATE EXTENSION` cannot fail here. |
| "the server never starts a scene itself" | **CONFIRMED** on code with comments stripped: no `SetStage` / `SetObjectiveCompleted` / `CompleteQuest` / `SetBribed` / `SetIntimidated` / `SetCrimeGold` call anywhere in the three Phase 2 libs; the only action code Phase 2 ever puts on a wire line is `LRG_ACT_TOPIC`. |

Throw-away checks I wrote live in `C:\Users\Jordan\AppData\Local\Temp\lrg_test\vsrv\` (`vcheck1..6.php`,
`verify_plugin.py`, `cover.py`). They are not part of the project.

---

## 1. CRITICAL

### C1 - every `lrg_topics` and `ev=facts` message kills its request as soon as the player owns one Speech perk or the Amulet of Articulation

**side: server. `lib/lrg_dialogue.php:353`**

```php
if (isset($kv['perk'])) { $f['perk'] = $kv['perk'] === '-' ? [] : lrgCsv((string) $kv['perk']); }
```

`lrgCsv()` lives in `lib/lrg_actions.php:37` - the INTIMACY lane's file. `lib/lrg_dialogue.php` requires
only `lrg_core.php`, `lrg_prompt_index.php` and `lrg_speech.php`, and `preprocessing.php` handles Phase 2
**first, and terminates**:

```
preprocessing.php:14     require_once .../lib/lrg_core.php
preprocessing.php:21-24  lrg_topics | lrg_dlg | lrg_dlgtalk -> require lib/lrg_dialogue.php; handle; terminate()
preprocessing.php:36     require_once .../lib/lrg_actions.php      <- never reached for a Phase 2 message
```

Reproduced with preprocessing's exact require graph (`vcheck2.php`):

```
loaded: lrg_core + lrg_dialogue only (exactly what preprocessing.php has at line 23)
lrgCsv defined? NO
--- perk=-                    handled='handled'  -> OK
--- perk=silvertongue         Error: Call to undefined function lrgCsv()
--- perk=amulet               Error: Call to undefined function lrgCsv()
--- perk=silvertongue,amulet  Error: Call to undefined function lrgCsv()
ev=facts;...;perk=amulet;...  Error: Call to undefined function lrgCsv()
```

Not hypothetical: `LRG_Dialogue.psc:2141` puts `;perk=" + PerkCsv()` on **every** `lrg_topics` payload and
`:2334` on **every** `ev=facts`. `PerkCsv()` (`LRG_Dialogue.psc:2718-2763`) returns `"-"` **only** while the
player has neither `REQ_Speech_SilverTongue` nor the Persuasion perk and is not wearing an Amulet of
Articulation. The first Speech perk or amulet turns the whole feature off: the list never reaches the
server, no `<business>` block is ever built, `want=1` is never answered - and because it is a PHP fatal
inside `preprocessing.php`, `terminate()` never runs and the game gets a broken reply instead of a
diagnosable one. Dry-run does not mask it; dry-run only stops the click.

Every offline suite is green because `test_dialogue.php`, `test_prompt_index.php` and the flow adapter all
load the whole plugin. Nothing tests preprocessing's own require graph.

**Smallest fix** (Phase 2's own file, no ownership problem):

```php
if (isset($kv['perk'])) {
    $f['perk'] = $kv['perk'] === '-' ? []
        : array_values(array_filter(array_map(
            static fn($x) => strtolower(trim((string) $x)), explode(',', (string) $kv['perk'])), 'strlen'));
}
```

`lrgCsv()` is exactly lowercase + trim + drop-empties, so this is behaviour-identical. Add one regression
check that loads *only* `lrg_core.php` + `lrg_dialogue.php` and pushes `lrg_topics` with `perk=amulet`.

### C2 - Phase 2's post-LLM gate never fires on the line CHIM really sends, so every LLM-chosen business turn goes silent and does nothing

**side: server. `lib/lrg_dialogue.php:1323-1324`**

```php
$norm = str_replace(['_', ' '], '', strtolower($code));
if ($norm !== strtolower(LRG_ACT_TOPIC) && $norm !== strtolower(LRG_DLG_NAME) && $norm !== 'takeupbusiness') {
    $out[] = $line; continue;                       // pass through, ungated
}
```

The underscore is stripped from `$code` but **not** from `LRG_ACT_TOPIC`, so that comparison can never be
true:

```
norm($code)                = 'extcmdlrgselecttopic'
strtolower(LRG_ACT_TOPIC)  = 'extcmdlrg_selecttopic'   equal? NO
strtolower(LRG_DLG_NAME)   = 'takeupbusiness'          equal? NO
```

And the code name is exactly what the wire carries. `HerikaServer/functions/functions.php:2543`:

```php
$commandStr = $actorName . "|" . $commandChannel . "|" . $functionCodeName . "@" . $parameter_string . "\r\n";
```

with `$functionCodeName = $executionContext["function_code_name"]` (`:2498`) = the catalog row's
`code_name` = `ExtCmdLRG_SelectTopic`. Phase 1's own gate documents the same fact at
`lib/lrg_actions.php:1428` and matches on `stripos($code, 'ExtCmdLRG_') !== 0` - the mirror image of the
mistake. Measured (`vcheck3.php`):

```
ExtCmdLRG_SelectTopic  (the CODE name - what CHIM really sends)   gate fired: NO   emitted a real command: NO
                       -> passed through RAW: Ysolda|command|ExtCmdLRG_SelectTopic@item=T1
TakeUpBusiness         (display name - only when the core cannot map it back) gate fired: YES  emitted: YES
take_up_business       (snake-cased display name)                 gate fired: YES  emitted: YES
```

Consequences, all at once:

* The raw line reaches the game with the LLM's `item=T1` as the parameter. `CmdSelectTopic` finds no
  `ok=1` and refuses it (`LRG_Dialogue.psc:652`) - **fail-closed, so no wrong effect**, but no right one either.
* Phase 1's gate passes `ExtCmdLRG_SelectTopic` through by design (integrator edit D-12), so nothing
  upstream catches it.
* The TTS mute keys off the model's **action name**, which *does* match
  (`lrgDlgTransformer():1738`), so the sentence is dropped: `lrgDlgWillEmit(turn,'T1') = true ;
  transformer returns '' (SENTENCE DROPPED)`. **The NPC says nothing and nothing happens.**
* Every rail in `lrgDlgGateItem()` - two-step confirmation, LETHAL hand-back, freeze rule, afford, check
  rails, scoff-first, retry suppression - is unreachable in production. I verified that logic is itself
  correct when it is reached (`vcheck6.php`, 10/10), which is the sad part.

Why no test caught it: `tools/flows/dlg_adapter.php:21` sets `const FX_DLG_NAME = 'TakeUpBusiness'` and
`:213` builds every line from it. **No Phase 2 scenario or unit check anywhere feeds the string
`ExtCmdLRG_SelectTopic`** (`grep ExtCmdLRG_SelectTopic tools/flows/scenarios/d*.php` = 0 hits), while Phase
1's scenarios use `FX_ACT_START`, which *is* the code. 26 scenarios and 85 unit checks share one blind spot.

**Smallest fix** - normalise both sides:

```php
$norm = str_replace(['_', ' '], '', strtolower($code));
$want = [str_replace('_', '', strtolower(LRG_ACT_TOPIC)), strtolower(LRG_DLG_NAME)];
if (!in_array($norm, $want, true)) { $out[] = $line; continue; }
```

and change `FX_DLG_NAME` to the code name (or add one scenario that uses it) so the suite tests the real shape.

---

## 2. MAJOR

### C3 - the `want=1` fast path applies NONE of the round's rails: it clicks commit, walk-away and LETHAL entries on the first selection, and clicks for an NPC in a scene

**side: server. `lib/lrg_dialogue.php:1241-1283` (`lrgDlgAnswerWant`)**

This is the path that runs inside `preprocessing.php` with **no LLM in the loop at all**. Its only rail is
`in_array($e['class'], ['plain','service'])` (+ `'pay'` under continuation). `class` is decided by
`lrgDlgClass()`, where the service-word test (step 8) runs **before** the commit test (step 9) - so an entry
can be `class = service` and `commit = true` at the same time, and `crit` never affects `class` at all.
Measured (`vcheck5.php`, section G), each case a real `lrg_topics ... want=1` payload:

```
entry: class=service commit=true crit=0 scripted=1 goodbye=1 indexed=1
  [FAIL] RAIL (two-step confirmation): must not execute on the first selection
         -> ...do=pick;...;txt=I'll buy the house from you.
  [FAIL] RAIL: a walk-away entry must not be clicked from intent mode      -> do=pick
  [FAIL] RAIL: a LETHAL entry (index crit=2) must not be clicked           -> do=pick
  [FAIL] RAIL: a LETHAL SESSION (the game's own crit=2) must not be clicked -> do=pick
  [FAIL] RAIL (D-17): a speaker in a scene is not clicked for either        -> do=pick
  [ok]   control: an ordinary plain entry does execute
```

The session-level `crit=2` and `scene=1` cases do not depend on the index at all - they come straight off
the game's own payload, and `lrgDlgAnswerWant()` reads neither. Design 1.8's "a wrong match can never cause
a wrong effect" and D-17 are both violated on this path. It ships behind `bDlgDryRun=1`, but dry-run is the
probe gate, not the safety design.

**Smallest fix** - reuse the rails that already exist. In `lrgDlgAnswerWant()`, immediately after `$e` is
chosen:

```php
if ((int) ($sess['scene'] ?? 0) === 1) { lrgDlgLog('want=1 npc=' . $npc . ': speaker is in a scene (D-17)', $cid); return; }
if ((int) ($sess['crit'] ?? 0) === 2 || (int) $e['crit'] === 2 || $e['commit']
    || (int) $e['crit'] >= 1 || (int) $e['walkaway'] === 1) {
    lrgDlgLog('want=1 npc=' . $npc . ': ' . substr((string) $e['text'], 0, 40)
        . ' is consequential - intent mode never executes it without confirmation', $cid);
    return;
}
```

### C4 - the layer tier bypasses the fail-safe risk merge, and all 7 LETHAL-ambiguous prompts in this load order go through exactly that tier

**side: server/index. `lib/lrg_prompt_index.php:395-405` (`lrgPromptLayerFor`)**

```php
$recs = $rows[$n] ?? [];
if ($recs) { $r = $recs[0]; $r['matched'] = 'layer'; ... $byNorm[$n] = $r; }
```

`$recs[0]` is taken raw. `lrgPromptEscalate()` - the function written so that "the index may over-claim
risk, never under-claim it" - is called **only** from `lrgPromptBest()`, i.e. only on tier 2 (exact norm).
The layer tier is tier **1** and wins over tier 2 (`lrgPromptLookup():201`). Measured (`vcheck1.php`):

```
[ok]   tier 2 (exact norm) escalates an ambiguous norm to LETHAL
[ok]   a live arrest layer is matched by fingerprint            (matched=layer)
[FAIL] the layer tier must NOT under-claim risk - crit stays 2  -> crit=0  info_key=A:1
[FAIL] the layer tier must not lose the walk-away target either -> twat=''
```

Blast radius, measured on the real index (`cover.py` / `cover2.sh`):

```
ambiguous norms with a LETHAL (crit=2) candidate and a non-lethal one : 7
  of those, inside a KNOWN closed layer (where the escalation is skipped) : 7   <- all of them
     e.g. "any chance i could talk you into overlooking this"
          "i'm the jarl's thane i demand you let me go at once"
          "i'm with the guild how about you look the other way"
          "i'm with the guild is this enough to clear my bounty"
ambiguous norms disagreeing only about COSTLY (crit=1)                : 98  (76 inside a known layer)
ambiguous about walkaway : 116 norms (344 occurrences inside known layers)
ambiguous about scripted : 954 norms (1709 occurrences inside known layers)
```

Those four lines are the guard-arrest layer. With `crit` flattened to 0 the gate's
`lrgDlgGateItem():1423` LETHAL branch does not fire, `lrgDlgIsCommit()` loses its `crit >= 1` hit and the
label is not `[leaving now ends this]` - an arrest layer is treated as ordinary business.

**Smallest fix** - one line:

```php
if ($recs) { $r = lrgPromptEscalate($recs[0], $recs); $r['matched'] = 'layer'; $r['layer'] = $bestFp; $byNorm[$n] = $r; }
```

### C5 - "everyone has a price" exists twice, and the implementation PROTOCOL documents is dead code with its own private config namespace

**side: server/docs. `lib/lrg_speech.php:524` vs `lib/lrg_core.php:835`**

| | `lrgDlgPrice()` (server lane) | `lrgPriceFor()` (intimacy lane) |
|---|---|---|
| called from | `tools/flows/dlg_adapter.php:48` and scenario d41 only | `lib/lrg_core.php:1137`, i.e. the live turn |
| config read | `lrgDlgCfg('paid_intimacy')` = defaults in `lrg_speech.php` + `lrgConfig()['dialogue']['paid_intimacy']` | `lrgConfig()['paid_intimacy']` - the block the owner actually has |
| wage key | `day_wage` | `gold_per_day_of_wage` |
| bands key | `band_by_wealth` | `bands_by_wealth` |
| money influence | **divides** by it | **multiplies** the days by it |
| MCM `pm` / `paidok` | ignored entirely | honoured |

`grep -rn 'lrgDlgPrice\b'` finds no production call site at all, and `lrgDlgPriceRemember()` is called from
nowhere. Yet `PROTOCOL.md` 10.7 states "The price band itself is `lrgDlgPrice()` (`lib/lrg_speech.php`)"
and 10.9 lists it as a contract function. So flow scenario **d41** ("the band comes from the wage anchor, a
poor commoner is cheap and a jarl is ridiculous, a Vigilant is never for sale, interest makes it free, a
repeat is cheaper and the multiplier rescales everything", 15 checks, green) proves nothing about shipped
behaviour, and two implementations of one owner feature will drift.

The **live** one is good - I measured it end to end against the owner's 3-5 gold/hour anchor
(`vcheck4.php`, section J; a day = 35 gold):

```
Lisette  indifferent  tier=poor        band=[1,4]d    infl=0.80 -> 110 gold  = 3.1 days
Lisette  curious      tier=poor                                 -> 35 gold   = 1.0 day
Lisette  interested                                             -> FREE (token 20)
Ysolda   indifferent  tier=comfortable band=[4,15]d   infl=1.60 -> 840 gold  = 24 days (~3.5 weeks)
Lydia    indifferent  tier=comfortable band=[60,300]d           -> 10500 gold = 300 days
Elisif   indifferent  tier=noble       band=[300,3000]d         -> 105000 gold = 3000 days (absurd, as asked)
Vigilant indifferent  for_sale=NO  why=never
```

plus: married-elsewhere refuses or triples, `pm=2.0` rescales, `paidok=0` disables everything, and
`test_gates.php` section 32 (19 numbered cases, reproduced green) covers the offer/floor/affordability/
`pay=110`/halved-gain/`gold_accepted` chain. So the **feature** is sound; the **duplicate and the contract
text** are the defect.

**Smallest fix**: delete `lrgDlgPrice()`/`lrgDlgPriceWords()`/`lrgDlgPriceRemember()` from
`lib/lrg_speech.php`, point d41's adapter capability at `lrgPriceFor()`, and correct PROTOCOL 10.7/10.9 to
name `lrgPriceFor()` (`lib/lrg_core.php`). If the function must stay as a seam, make it a thin wrapper that
calls `lrgPriceFor()` so the two can never disagree.

### C6 - seven MCM controls do nothing, and one of them is half-wired in the dangerous direction

**side: both. `MCM/Config/LoreRimGlue/settings.ini` vs the scripts and the server**

My cross-check (`mcm.sh`) is otherwise clean - 79 settings read, 82 ini defaults, 82 page controls, **no
missing default, no missing page control, no duplicate id**. (The INTIMACY lane's "six of the eight MCM ids
are still missing" is **stale**: `bPrivacyDoors`, `fHearRadius`, `fDoorRadius`, `fListenerHold`,
`fListenerGrace`, `bFixControlsAfterScene`, `bPaidIntimacy` and `fPriceMultiplier` are all present.)

But three ini keys are read by no script at all, and four more are read and then only printed in a log line:

```
settings.ini lines NO script reads : bCheckHostility:Checks, iCheckBias:Checks, iQuestLines:Quests
read, then used ONLY in the diagnostics line (LRG_Dialogue.psc:362):
    iBranchInput, bRewalk, iRewalkDepth (:401-403), bIntentOpen (:413)
```

All seven are *server*-side knobs and there is no wire carrier for them (the driver lane flagged this in its
own section 6d and correctly refused to invent a key). Two of them are things the owner asked for by name:
addendum 5c says "MCM: a master toggle for free-conversation checks (default on) and a difficulty bias
slider" - `iCheckBias` moves nothing anywhere.

Worse, `bFreeChecks` is wired on the **game** side only (`LRG_Dialogue.psc:848` refuses `do=award`) while the
server never hears about it. With `bFreeChecks = 0` the server still runs the check, still injects
`<what_just_happened>` telling the NPC the outcome as fact, and still emits `do=award` - which the game then
throws away. The owner gets the narration of a check they switched off, with no XP and no gold. A half-off
switch is worse than no switch.

**Smallest fix**: carry them on the snapshot the way `paidok` / `pm` already travel - one additive
`lrg_npcstate` (or `ev=facts`) key, e.g. `chk=<bFreeChecks><bCheckHostility>;bias=<iCheckBias>;ql=<iQuestLines>`
- and have `lrgDlgCheck()` / `lrgDlgQuestBlock()` prefer it over the config default. Until that exists, the
honest short-term fix is to remove those seven controls from `config.json` rather than ship dead knobs.

---

## 3. MINOR

### C7 - `lrgPromptEscalate()`'s `?? 0` is inoperative; every ambiguous prompt emits PHP warnings

`lib/lrg_prompt_index.php:322`

```php
$flags[$f] = max((int) ($flags[$f] ?? 0), (int) ((array) ($r['flags'] ?? []))[$f] ?? 0);
```

The cast binds tighter than `??`, so this is `((int) $x) ?? 0` and the coalesce can never suppress the
missing key. Observed on every escalation:

```
PHP Warning: Undefined array key "goodbye"  in lib/lrg_prompt_index.php on line 322
PHP Warning: Undefined array key "walkaway" in lib/lrg_prompt_index.php on line 322
PHP Warning: Undefined array key "sayonce"  in lib/lrg_prompt_index.php on line 322
```

The value is right anyway, but it is noise on a hot path - and on any PHP with `display_errors` on it prints
into the same response body the **D1** route echoes the command line into.
**Fix**: `max((int) ($flags[$f] ?? 0), (int) (((array) ($r['flags'] ?? []))[$f] ?? 0))`.

### C8 - `test_prompt_index.php` reports 67, not the claimed 71

Same file, same index (37,561 / 5,718 - I rebuilt it and the live DB agrees). 67 passed, 0 failed, exit 0.
The extra 4 in the build report are not reproducible from anything in the file. Low impact; the run is green
either way. **Fix**: re-state the number in `pt8-build-server.md`.

### C9 - the schema marker makes a CHIM playthrough switch a one-way trip for Phase 2

`lib/lrg_dialogue.php:161-162` returns early whenever `data/.dlg_schema_v1` exists. CHIM's playthrough
switch does `DROP SCHEMA IF EXISTS public CASCADE; CREATE SCHEMA public`
(`HerikaServer/lib/playthrough_schema.php:226-227`) and clones the saved profile schema back in. I confirmed
the split is already live here:

```
public                 lrg_dialogue, lrg_prompt, lrg_prompt_layer, lrg_memory, lrg_npc_state, lrg_romance, lrg_scene_state, lrg_turn
chim_profile_default   lrg_memory, lrg_npc_state, lrg_romance, lrg_scene_state, lrg_turn      <- no Phase 2 tables
```

so switching to `default` today restores a `public` with no `lrg_dialogue` / `lrg_prompt` - and the marker
file stops them ever being recreated. Every state read then throws (caught and logged) and Phase 2 goes
silently dead, index included. Phase 1 has the same marker pattern, so this is a class problem, not a
regression - but Phase 2 adds a 30 MB / 37,561-row table that also gets cloned on every playthrough save.
**Fix**: cheapest is to make the marker a table probe instead of a file - `if (is_file($marker) &&
$db->fetchOne("SELECT to_regclass('lrg_dialogue') AS t")['t']) { return; }` - and to consider putting
`lrg_prompt` / `lrg_prompt_layer` in a schema of their own, since the index is load-order data, not
playthrough data.

### C10 - dead ternary

`lib/lrg_dialogue.php:382`

```php
'scene' => (int) ($kv['sg'] ?? 0) === 0 ? (int) ($kv['scene'] ?? 0) : (int) ($kv['scene'] ?? 0),
```

Both branches are identical, and `sg` has nothing to do with `scene`. Harmless, but it reads as an intent
that was never finished. **Fix**: `'scene' => (int) ($kv['scene'] ?? 0),`.

### C11 - `res=` is documented and sent but read by nobody

`PROTOCOL.md` 10.4 lists `res=<pass|fail|unknown>` and `lrgDlgEmit()` always sends it, but
`LRG_Dialogue.psc` reads `ok cid x ref do sid gen pos i txt kind cost ask vt take xp stat` and never `res`.
Both sides ignore unknown keys so nothing breaks; it is one wasted field on every command.
Informational - either drop it or note in 10.4 that it is diagnostic only.

---

## 4. What I checked and found CORRECT

* **The index is right where it matters.** I wrote an independent minimal TES5 reader
  (`verify_plugin.py`, written from the record format, not derived from `build_prompt_index.py`) and decoded
  the raw CTDA of a stratified sample. Every claim matched the bytes:
  * `MG03CallerBookPersuade` (winning override **Immersive Speech Dialogues.esp**, `0001A0B3`): fn 14
    `GetActorValue` param1 `0x11` (Speechcraft) `>=` `GLOBAL:000D16A5` with the OR flag, plus fn 182
    `GetEquipped` `000F759C` (the Amulet list) with the OR flag, VMAD present, `ENAM 0x2001` (Goodbye).
    Index: `kind=persuade variant=success scripted=1 amulet=1 SpeechAverage` - **exact**.
  * `DialogueMorKhazgurBorgakhPersuade` (winning override **LoreRim - Dialogue Patch.esp**, `0006F7D2`):
    two OR-branches, `SpeechAverage` (`000D16A5`) and `SpeechVeryHard` (`000D1954`), plus a non-speech fn 69
    branch. Index: `kind=persuade compound=1 amulet=1`, both globals listed - **exact**, and this is a
    Requiem/LoreRim-patched record, not vanilla.
  * `DA01NelacarIntimidate` `00024663`: fn **655** `GetIntimidateSuccess` -> `kind=intimidate`. **exact**.
  * `DA01NelacarBribe` `000243BC`: fn **654** `GetBribeSuccess` -> `kind=bribe`. **exact**.
  * The five Speech globals resolved by the builder are the five the game reads, in the same order:
    `ResolveGlobals()` (`LRG_Dialogue.psc`) maps `000D16A3/16A4/16A5/1953/1954` to sgv[0..4] and
    `lrgDlgFactsFrom()` maps `sg[0..4]` to `SpeechVeryEasy..SpeechVeryHard`; my decode independently shows
    `000D16A5 = SpeechAverage` and `000D1954 = SpeechVeryHard`. Census: VeryEasy 17, Easy 73, Average 101,
    Hard 72, VeryHard 32; kinds present = persuade 366, intimidate 135, bribe 86 and nothing else.
* **Coverage on ten quest NPCs' quests** (`cover.py`) - MG03, MS09, DA01, TG00, MQ104, C00 (Companions
  Dialogue Bundle wins), DA03, MS11, ANDR_AJO_Quest (Andrealphus Jobs Overhaul), InigofollowerDialogue
  (Inigo.esp): **3,041 prompts, 100% resolve** - every live line would find a record. Caveat worth knowing:
  identification *from the prompt alone* is much weaker than the design's "93.1% unique" - I measure
  **83.8% of 21,148 distinct norms unique**, and per quest the prompt-alone exact rate runs 41-92%
  (0% for ANDR_AJO_Quest, whose 1,328 rows repeat generic prompts across 830 topics; it is on
  `filler_patterns`, so it ranks down, which is the right mitigation). The layer tier is what carries the
  rest - which is why C4 matters.
* **Two-step confirmation** (`vcheck6.php`, 10/10 through the name the gate does accept): no utterance
  releases the first selection (`yes`, `I confirm`, `ignore the confirmation`, `I already confirmed this`,
  `T1 T1`, ...); a repeat inside the same request is still step one; only a new request releases; a park
  older than `confirm.park_seconds` re-parks; a single-token voice answer is not a confirmation.
* **Crit grades:** a LETHAL entry produces `do=show`, never `do=pick`, and `lrgDlgWillEmit()` agrees so the
  TTS is not muted on that turn.
* **Matching modes:** `min_score 0.55 / min_margin 0.15`, continuation `0.70 / 0.25` depth 1, and intent
  mode through the gate refuses `commit`, `check`, `meta` and `pay` classes (4/4).
* **Scene gate and coexistence** (`vcheck5.php` section H, through the real `lrgHideActions` over
  `$GLOBALS['ENABLED_FUNCTIONS']`): a speaker in a scene has `TakeUpBusiness` hidden; an open matter hides
  all four Phase 1 intimacy actions plus `EndConversation` while keeping `TakeUpBusiness`; quest ->
  intimacy -> quest works, i.e. once the session closes the intimacy actions come back; `ForgiveCrime` is
  hidden (D4); a known list hides CHIM's own `RentRoom` so the real entry wins.
* **Speech checks never let the LLM narrate an outcome:** the failure directive states the outcome as fact,
  forbids "an outcome of your own", and leaks no threshold and no skill number; `<real_business>` carries
  "Never say whether a persuasion, a threat or a bribe worked"; an `unknown` outcome attaches no verdict at
  all; the action row's description ends "Never announce whether a persuasion, a threat or a bribe worked".
  `do=award` is emitted only by the server's own post-gate and carries no `pos` / `i` / `sid`.
* **Old game scripts (310):** `ev=facts` with no `sp`/`perk`/`lvl`/`wis`/`sg`/`qobj` is handled, the check
  falls back to the config thresholds and the log says `(fallback)`; an unknown `ev` is ignored, not an
  error; a `lrg_topics` payload with almost no keys still parses. (Note the flip side: with 310 scripts the
  catalog still offers `TakeUpBusiness` to the model, which answers `Error: unknown command` - acknowledged
  for `do=award` in PROTOCOL 10.5 and equally true for `do=pick`.)
* **Catalog row vs CHIM's strict schema:** `code_name`/`action_name` non-empty, `parameters_json` survives
  `herikaActionCatalogNormalizeParameterSchema()` (`type=object`, `properties.item`, `required=['item']`),
  `metadata.dispatch='plugin_command'` so CHIM does not fall back to `script_proxy`, `game_function=1`,
  its own marker `.dlg_actions_v1` and not inside Phase 1's `LRG_GLUE_ACTIONS`. One cosmetic mismatch:
  CHIM stores the display name snake-cased (`herikaNormalizeActionCatalogDisplayActionName` inserts `_`
  before each capital), so the strict enum offers `Take_Up_Business` while `<business>` tells the model to
  "Use TakeUpBusiness". The gate and the transformer both strip `_`, so it works - but the prompt and the
  enum should say the same string.
* **Wire, three ways:** the PHP param key order is **exactly** PROTOCOL 10.4's
  (`ok cid npc ref do sid gen pos i txt kind cost res xp stat take ask vt x z`); the game reads no key the
  server never sends; `ev=result`'s keys match 10.2; `lrgDlgSafePrefix()` strips `; = @ | " ~` and yields
  `''` under 12 chars. Only `res` is sent-but-unread (C11).
* **Decisions D3/D4/D5/D7 as taken in OWNER_ADDENDA item 4:** D3 `reorder_json=true` and the real
  `HOOKS['JSON_TEMPLATE']` hook; D4 `ForgiveCrime` on `hide_always`; D5 `scoff_first=true`,
  `auto_advance.grace_seconds=2.5`, `fSilenceTimeout=45`, `iEngineOpen=0`, `iCritical=0`, `iSceneGate=0`;
  D7 `bRewalk=0`. Ships inert as §11 step 4 requires: `bMenuless=0`, `bDlgDryRun=1`.
* **Migrations** are idempotent, and `lrg_dialogue` is empty (0 rows) so nothing has been written by a test.

---

## 5. The shortest path to SHIP

1. C1 - one line in `lrg_dialogue.php:353` (inline the csv split). **Without this the feature dies the moment the player takes a Speech perk.**
2. C2 - one line in `lrg_dialogue.php:1323` (strip `_` on both sides), and point `FX_DLG_NAME` at the code name so the suite tests the real shape. **Without this the feature never works at all and the NPC goes mute.**
3. C3 - five lines in `lrgDlgAnswerWant()` (scene, session crit, entry crit, commit, walkaway).
4. C4 - one line in `lrgPromptLayerFor()` (`lrgPromptEscalate($recs[0], $recs)`).
5. C6 - either carry the seven server knobs on the snapshot, or remove them from `config.json` for now; `bFreeChecks` must reach the server either way.
6. C5, C7-C11 can ride the next round, but C5's PROTOCOL text should be corrected before anyone builds on 10.7.

After 1-4, re-run `run_flows.php --strict`, `test_dialogue.php` and `test_prompt_index.php`, and add the two
regression checks named in C1 and C2 - they are the two the whole suite was missing.
