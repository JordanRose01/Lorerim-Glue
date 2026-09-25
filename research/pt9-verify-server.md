# PT9 — independent verification, SERVER + INDEX lens (v0.5.0)

Verifier: independent, read-only except this file and two scratch Postgres databases (`lrg_v`, `lrg_m`,
both created and dropped again inside §2). Nothing under `glue\`, `/var/www`, `F:\Modlists` or the live
`dwemer` database was written by me. Every number below was produced by me, not copied from a build report.

Staging: the project was re-copied to `$env:TEMP\lrg_ver` (`/mnt/c/Users/Jordan/AppData/Local/Temp/lrg_ver`)
and every test ran from that copy inside `wsl -d DwemerAI4Skyrim3`. PHP 8.2.28.

## VERDICT: FIX FIRST

One critical defect (S-1), two major (S-2, S-3) and eight minor. No hard rail is breached: the driver still
calls no TIF fragment, resist-arrest is still unselectable at every setting, the emergency key is untouched,
and the module still ships dry-run. But **S-1 lets a price QUESTION and a NEGATED sentence buy a carriage
ride on the most common destination layer in the game**, which is the exact risk (R12) the round names as
"the worst bug this round could ship". S-1's fix is one config number.

| # | sev | side | one line |
|---|---|---|---|
| S-1 | **critical** | server | `services.slot.min_priced = 2` switches the R12 slot guard OFF on 7 of the 16 real destination layers, incl. the vanilla carriage list; "How much to Morthal?" and "Don't take me to Morthal." then both click "Morthal." |
| S-2 | **major** | both | seven MCM controls are dead: the game sends `qig` `qx` `svx` `tg`, no `.php` reads any of them |
| S-3 | **major** | docs | the build report says the live database is untouched and the server undeployed; both are false — `dwemer` is migrated and `/var/www/.../lorerim_glue` is 0.5.0 |
| S-4 | minor | server | `test_mcm_wiring.php`'s reader test is satisfied by a bare mention, so a comment defeats the gate (mutation-proven) |
| S-5 | minor | server | `pg_trgm` lives in `public`, so a playthrough drop takes the (unused) `lrg_prompt_norm_trgm` GIN index with it |
| S-6 | minor | server | the training-skill census truncates hyphenated names (`One`) and misses `train me to X` / `training in the art of X` — Block, Sneak, Speech, Pickpocket, Two-Handed are absent |
| S-7 | minor | server | `quests.initiative.per_topic_once` is a config key nothing reads |
| S-8 | minor | docs | `LRG_VERSION` is still `'0.4.1'` while `manifest.json` is `0.5.0` |
| S-9 | minor | server | `LRG_ACT_ALIVE_REQ`'s docstring claims it is spelt "exactly as CHIM spells it for RentRoom" — RentRoom's builtin has no `combat` / `attacking` |
| S-10 | minor | server | `service_words` has no `sale` / `goods`, so the owner's own headline barter phrase grades `commit`, not `service` |
| S-11 | minor | server | `min_margin 0.15` leaves only 0.19 of headroom between "One-Handed" and "Two-Handed" on a five-skill trainer, and a training layer can never qualify for the slot guard |

---

## 1. Gate: every claimed number, re-run by me

| check | builder claimed | I measured |
|---|---|---|
| `php -l` over the tree | 73 files, 0 errors | **73 files, 0 errors** |
| `test_gates.php` | 296 / 0 | **296 passed, 0 failed** |
| `test_intent.php` | 190 / 0 | **190 passed, 0 failed** |
| `test_phrases.php` | 14 / 0, hit 91.9% | **14 passed, 0 failed, 91.9% (floor 82%)** |
| `test_scene_index.php` | ALL PASSED | **ALL CHECKS PASSED** |
| `test_dialogue.php` | ALL PASSED | **112 passed, 0 failed** |
| `test_prompt_index.php` | ALL PASSED | **67 passed, 0 failed** (no `--db`) |
| `test_prompt_index.php --db` | 112 checks, scratch db | **112 passed, 0 failed** (my own scratch db) |
| `test_mcm_wiring.php` | ALL PASSED, 108 ids | **ALL CHECKS PASSED, 108 controls** |
| `test_services.php` | 22 checks | **22 passed, 0 failed** |
| `test_latency*.php` (parallel round) | — | **21 / 0**; model and prompt tables print |
| `run_flows.php --strict` | 73 / 73, 1083 checks | **73 scenarios, 73 passed, 0 FAILED, 0 pending, 1083 checks, 0 warnings, RESULT OK** |
| index `--services --coverage` | 31 s, 37,561 / 5,718, 98.98% | **33.8 s, 37,561 rows / 5,718 layers, 98.98% over 432 plugins** |

Every claimed number reproduced exactly. The gate is honest as far as it goes — §4 and §3 are where it
does not go far enough.

---

## 2. THE INDEX AND ITS NEW SCHEMA (E4(a), migration 007)

### 2.1 I rebuilt the index myself
33.8 s, `prompt_index.ndjson` 31,573,391 bytes, 37,561 rows / 5,718 layers, coverage 98.98% of the 37,947
prompts-with-text over 432 plugins. Census: 26 service plugins led by `CFTO.esp` (296), `Skyrim.esm` (157),
USSEP (108), `Andrealphus Jobs Overhaul - MASTER.esp` (64); 15 crime topics incl. `DGCrimeResistArrest`;
142 price globals incl. `<Global=KmodFerryCost>(CFTO.esp)`; 97 destinations / 56 travel destinations.

### 2.2 Migration 007 is a MOVE, not a rebuild — PROVEN
Seeded a scratch db `lrg_m` the 0.4.1 way (migration 006 into `public`) with 1,000 prompt rows and 50 layer
rows, then ran 007 exactly as `lrgDlgEnsureSchema()` does (`lrgPromptSqlStatements()` + `{s}` substitution):
all 11 statements `ok`; `lrg_index.lrg_prompt` = **1000**, `lrg_index.lrg_prompt_layer` = **50**, the public
copies gone; the 7 existing indexes travelled with the tables and `lrg_prompt_quest_idx` was added; a
**second** run reports **0 failing statements** and the same row count. Idempotent, non-destructive.
The column lists of 006 and 007 are identical, so a moved table is never missing a column.

### 2.3 A public-schema drop does NOT lose the index — PROVEN
On `lrg_v` (the full 37,561-row load) I ran CHIM's own playthrough statement:

```
DROP SCHEMA IF EXISTS public CASCADE; CREATE SCHEMA public;
NOTICE:  drop cascades to 3 other objects
DETAIL:  drop cascades to extension pg_trgm
         drop cascades to index lrg_index.lrg_prompt_norm_trgm
         drop cascades to table lrg_dialogue
```

After the drop: `lrg_index.lrg_prompt` = **37,561**, `lrg_index.lrg_prompt_layer` = **5,718**. **E4(a) does
exactly what it was built to do.** Re-running 005+007 the way `lrgDlgEnsureSchema()` does restores the
extension and the index (11/11 `ok`, `similarity()` works again, 8 indexes back).

### 2.4 The ten-NPC sample passes
`test_prompt_index.php --db` against `lrg_v`: **112 passed, 0 failed**, including all 14 named rows of
`tools/fixtures/lrg_quest_npcs.json` — Brynjolf (≥8 rows, ≥3 top-level, ≥1 journal), a hold guard (≥1 row
with `crit >= 2`), Adrianne Avenicci (≥1 scripted), Inigo (≥500), an AJO job giver (≥500), a Missives board
giver (≥100), a CFTO carriage driver (≥40, ≥1 priced, "Solitude Lighthouse" present), an innkeeper (winner
from Xtended Stay / LoreRim - Dialogue Patch / Skyrim.esm, `<Global=RoomCost>` present), a Requiem trainer
(≥10), a carriage hub (CFTO / LoreRim - Dialogue Patch / Better Carriage Destinations). The two upstream
data bugs (1 engine-only plugin `Sanguine Symphony.esp`, 9 PNAM-inverted topics) are reported and nothing
is changed for them — correct.

---

## 3. S-3 (major, docs) — the live database and the deploy

`research/pt9-build-server.md` NOT-DONE says verbatim: *"The server was NOT deployed, as instructed… The
live dwemer database is untouched (public.lrg_prompt still holds 37,561 rows; schema lrg_index does not
exist there)."*

What is actually there now:

```
dwemer schemas:  chim_meta, chim_profile_default, information_schema, lrg_index, plugins, public
lrg_index.lrg_prompt         37561 rows
lrg_index.lrg_prompt_layer    5718 rows
public.lrg_prompt            ERROR: relation "public.lrg_prompt" does not exist
/var/www/html/HerikaServer/ext/lorerim_glue/manifest.json     -> "version": "0.5.0"
   every lib/*.php stamped 2026-09-22 05:17:27 · data/prompt_index.ndjson + .prompt_index_v1 at 05:18
```

The migration itself is *correct* — the row counts are right and the playthrough tables
(`lrg_dialogue`, `lrg_memory`, `lrg_npc_state`, `lrg_romance`, `lrg_scene_state`, `lrg_turn`) are still in
`public` where they belong. The defect is the sentence, not the state: the owner reads that line as "my
database is still 0.4.1, reverting the files rolls me back". It does not — 0.4.1 code looks for
`public.lrg_prompt`, will not find it, and runs unindexed (one confirmation too many on every entry).
**Smallest fix:** correct that NOT-DONE paragraph and add one line to the owner notes — *rolling back to
0.4.1 needs `ALTER TABLE lrg_index.lrg_prompt SET SCHEMA public;` (and the layer table) first.*
(If a later release step did the deploy, the report is merely stale — the rollback note is still needed.)

Also still present: the builder's scratch database `lrg_t` (`dropdb -h localhost -U dwemer lrg_t` removes it).

---

## 4. S-1 (CRITICAL, server) — the R12 slot guard is off on 7 of 16 real destination layers

### 4.1 What the code promises
`lrg_dialogue.php:3089-3108` calls this "THE ONE GENUINELY HARD PROBLEM E6 HAS TO SOLVE" and
"risk R12, the worst bug this round could ship": on a price list the similarity matcher is **replaced** by
exact slot containment, longest-wins, ambiguity asks, **a price question executes nothing**, and a
**negated** sentence never matches. `lrg_dialogue.php:1847-1879` repeats it on the `want=1` fast path —
"want=1 is exactly the message a freshly-opened DESTINATION layer arrives on".

### 4.2 The qualifier that decides it
```php
// lrg_dialogue.php:3127
if ($short < $minEntries || $priced < $minPriced || count($slots) < 2) { return null; }
```
with `'slot' => ['min_entries' => 3, 'max_words' => 3, 'min_priced' => 2, …]` (`:131`).
`$priced` counts entries whose LIVE text carries a number — `(int)($e['cost'] ?? 0) > 0`.

### 4.3 Real LoreRim layers do not carry two visible prices
I replayed every one of the 5,718 real layers, resolving `<Global=…>` to a number the way the game does,
and kept the ones that contain at least three names from the census's own `destinations_travel`:

```
layers that look like a real destination list:                16
  R12 slot guard ENGAGES:                                      9
  R12 slot guard DOES NOT engage:                              7   <-- all 7 because priced < 2
     parent=skyrim.esm:0CDF9E            10 entries, priced=1   dawnstar | falkreath | markarth | morthal | …
     parent=update.esm:002F02             8 entries, priced=1   dawnstar | falkreath | markarth | riften | solitude …
     parent=update.esm:002F0E            18 entries, priced=0   bloodchill manor | breezehome | dead man's dread …
     parent=hearthfires.esm:007874       19 entries, priced=1   darkwater crossing | dawnstar | dragon bridge …
     parent=journey to baan malur.esp:12926B  6 entries, priced=0  baan malur | cormaris | llethrin fel | pryai …
     parent=waitcarriageinns.esp:000802  21 entries, priced=1   darkwater crossing | dawnstar | dragon bridge …
     parent=cfto.esp:019DC5               4 entries, priced=1   heartwood mill | honeyside | ivarstead | riften
```
`skyrim.esm:0CDF9E` is the **vanilla carriage destination list** — the one a player meets first and most.

### 4.4 What then happens — measured, not reasoned
On `skyrim.esm:0CDF9E` (Dawnstar · Falkreath · Markarth · Morthal · Never mind · Riften · Solitude (50 gold)
· Whiterun · Windhelm · Winterhold), all bare names decorate `class=plain`, which **is** in `want=1`'s
allow list (`$allowed = ['plain','service']`, `:1931`). Running the shipped `lrgDlgMatchText()` with the
shipped thresholds (`min_score 0.55`, `min_margin 0.15`):

```
say "How much to Morthal?"                 "Morthal."   0.85/0.61  -> *** WOULD CLICK ***
say "What does it cost to Riften?"         "Riften."    0.85/0.58  -> *** WOULD CLICK ***
say "Don't take me to Morthal."            "Morthal."   0.85/0.60  -> *** WOULD CLICK ***
say "I do not want to go to Riften."       "Riften."    0.85/0.63  -> *** WOULD CLICK ***
say "Never take me to Whiterun."           "Whiterun."  0.85/0.24  -> *** WOULD CLICK ***
say "Is Morthal far?"                      "Morthal."   0.85/0.56  -> *** WOULD CLICK ***
say "What would you charge for Dawnstar?"  "Dawnstar."  0.85/0.66  -> *** WOULD CLICK ***
```
Each of those clicks a real carriage destination: the fare leaves the player's purse and he is teleported
across Skyrim, from a sentence that asked a price or said *no*.

The guards are unreachable by construction: `lrgDlgIsPriceQuestion()` and `lrgDlgNegatedAt()` are called
from **inside `lrgDlgServiceSlot()` only** (`:3130`, `:3138`; the only other `lrgDlgNegatedAt` caller is
`lrgDlgPlayerAskedToMove`, `:3034`). When the layer does not qualify, neither runs — on the `want=1` path
(`:1854-1855`) and on the post-LLM gate (`:2117` → `:3174`) alike.

Add **one** priced sibling to the same list and everything is correct again:
```
say "How much to Morthal?"     SLOT=PRICE   (executes nothing)
say "Don't take me to Morthal." SLOT=NONE   (executes nothing)
say "Take me to Morthal."      SLOT=PICK    (the real entry)
```

### 4.5 The root cause is that the two halves disagree about what a price list is
`tools/build_prompt_index.py:796-801` defines it with **no price requirement at all**:
> `# "a price list" is a LAYER property, not a row property: >= 3 siblings that are one to three words.`

The server then adds `min_priced >= 2` on top. Sensitivity run over the seven layers above:

| `services.slot.min_priced` | guard ON |
|---|---|
| **2 (shipped)** | **0 of 7** |
| 1 | 5 of 7 |
| 0 | 7 of 7 |

### 4.6 Smallest fix
`lrg_dialogue.php:131` — `'min_priced' => 2` → `'min_priced' => 0`, matching the index builder's own
definition. That is the whole fix; it only ever makes the matcher *stricter* (a qualifying layer must still
name an exact slot to execute anything). If a narrower change is wanted, keep 2 for an unknown layer and
use 0 when `$kind` is one of `carriage|ferry|inn|train`, i.e. in `lrgDlgServiceSlot()`:
```php
$minPriced = in_array($kind, ['carriage','ferry','inn','train'], true) ? 0 : max(0, (int)($cfg['min_priced'] ?? 2));
```
Either way `d57_slots` should gain a case built from a **priced=1** layer, because today every
price-list scenario in `flows/scenarios/` supplies a hand-written catalogue with prices on every row
(`d56_services.php:11-21`, `fxDlgRealCatalog()`), which is why 1,083 green checks never saw this.

---

## 5. S-2 (major, both) — seven MCM controls are dead again

`LRG_Profile.psc:499-514` puts four new knobs on the snapshot, exactly as `chk`/`bias`/`ql` do:

```
:499  s += ";qi="  + B2I(m.SettingBool("bQuestInitiative:Quests", false))
:500  s += ";qig=" + qig                       ; iQuestInitiativeGap
:507  s += ";qx="  + B2I(bQuestSummary) + B2I(bQuestNext)
:509  s += ";sv="  + B2I(bServiceDialogue)
:510  s += ";svx=" + B2I(bServiceShortcut) + B2I(bCarriageByName) + B2I(bNamePrices)
:513  s += ";lf="  + B2I(bLockedFacts)
:514  s += ";tg="  + B2I(bTruthGate)
```

The server's only snapshot reader is `lrgDlgMcmFromSnapshot()` (`lrg_dialogue.php:288-302`), and it parses
**`chk`, `bias`, `ql`, `qi`, `sv`, `lf` — and nothing else**. `grep -n "'qig'\|'qx'\|'svx'\|'tg'"` over
`server/lorerim_glue/lib/*.php` returns **nothing**. So these seven controls do nothing at all:

| MCM control | carrier | what the help text promises | what the server actually uses |
|---|---|---|---|
| `iQuestInitiativeGap:Quests` | `qig` | "Seconds before the same person may bring something up again" | `lrgDlgCfg('quests.initiative.gap_seconds', 600)` — `:2850`, `:2912` |
| `bQuestSummary:Quests` | `qx[0]` | "She can say what you can ask her" | `lrgDlgCfg('quests.summary.enabled', true)` — `:2738` |
| `bQuestNext:Quests` | `qx[1]` | "She can tell you where you left off" | `lrgDlgCfg('quests.next.enabled', true)` — `:2785` |
| `bServiceShortcut:Services` | `svx[0]` | "Fall back to CHIM's own shortcuts" | `lrgDlgCfg('services.shortcut_fallback', true)` — `:3242` |
| `bCarriageByName:Services` | `svx[1]` | "Let me name the destination" | nothing |
| `bNamePrices:Services` | `svx[2]` | "She may say prices out loud" | nothing |
| `bTruthGate:Truth` | `tg` | "Do not let a wrong number cost me gold" | `lrgDlgCfg('truth.gate', true)` — `:2041`, `:3498` |

This is the E4(c) scar verbatim — and the gate built to catch it (`test_mcm_wiring.php`) passes them,
because each id *is* mentioned in a `.psc` (path "a: read by a script") and the test never asks whether the
**wire key** it writes has a reader. `bTruthGate:Truth` is even in `SERVER_KNOBS` (`test_mcm_wiring.php:56`,
mapped to `lf`), but path (a) matches first at `:132` so the wire half is never evaluated.

**Smallest fix**, two lines of server plus two of test:
1. `lrgDlgMcmFromSnapshot()` `:297` — add the four keys and widen the numeric shape for `qig`:
   ```php
   foreach (['bias'=>[-2,2],'ql'=>[0,6],'qi'=>[0,1],'sv'=>[0,1],'lf'=>[0,1],'tg'=>[0,1]] as $mk=>$range) { … }
   if (isset($kv['qig']) && preg_match('/^\d{2,4}$/',(string)$kv['qig'])) { $out['qig'] = max(60, min(1800, (int)$kv['qig'])); }
   foreach (['qx'=>['summary','next'], 'svx'=>['shortcut','byname','prices']] as $mk=>$names) {
       $v = (string)($kv[$mk] ?? '');
       if (preg_match('/^[01]{'.count($names).'}$/',$v)) { foreach ($names as $ix=>$nm) { $out[$mk.'_'.$nm] = (int)$v[$ix]; } }
   }
   ```
   then consult them at the seven sites in the table (same `lrgDlgMcm(…, <config default>, $npc)` idiom
   already used for `qi`/`sv`/`lf`).
2. `test_mcm_wiring.php:130-152` — evaluate `SERVER_KNOBS` **before** path (a), and add the seven ids to
   the map, so a control that only rides the wire must prove both halves.

---

## 6. Service dialogue, traced on real LoreRim index rows

Everything below is the shipped code run against layers rebuilt from my own index, with `<Global=…>`
resolved to a number the way the game resolves it.

**Carriage — works when the guard engages.** On the real CFTO layer `cfto.esp:09D8C7` (28 destinations,
6 priced):
```
"Take me to Whiterun please."          SLOT=PICK  -> "whiterun" pos=25
"Take me to the lighthouse."           SLOT=NONE  (refuses to guess)
"How much to Morthal?"                 SLOT=PRICE (executes nothing)
"Don't take me to Morthal."            SLOT=NONE  (negation, six-token window)
"Take me to Solitude."                 SLOT=NONE  (Solitude is not on this local route — correct)
```
…and does not engage at all on the 7 layers of §4.3. That is S-1.

**Inn — correct.** `RentRoomTopic` (Xtended Stay / LoreRim - Dialogue Patch) decorates
`I'd like to rent a room. (50 gold)` as `class=pay cost=50`. "I'd like to rent a room." matches 1.00/0.28,
and `want=1` refuses it anyway because `pay` is not in `['plain','service']` (`:1931`) and
`lrgDlgContinuationOk()` returns false while `bRewalk` is off. Renting therefore goes through the key +
two-step confirmation path. Correct, and `<Global=RoomCost>` is never quoted as a price.

**Barter — works, with one rough edge (S-10).** `OfferServicesTopic` entries are VMAD-scripted, so on a
closed layer with ≥2 scripted rows `lrgDlgIsCommit()` (`:1113`) grades them `commit` and the two-step
confirmation applies. That is the rail behaving as designed. But the owner's own headline phrase —
`services.kinds.barter.phrases[0] = 'what have you got for sale'` — would not have been `service` even
without that, because `service_words` (`:181`) has `sell` and `wares` but not **`sale`** or **`goods`**.
Smallest fix: add `'sale'`, `'goods'`, `'merchandise'` to `service_words`.

**Training — works on realistic layers.** A three-skill Requiem/LoreRim trainer:
```
"Train me in One-Handed."         -> "I'd like training in One-Handed."   0.72/0.39  would click (class=service)
"Can you train me in one handed?" -> "I'd like training in One-Handed."   0.68/0.33  would click
"Teach me Block."                 -> "I'd like training in Block."        0.61/0.40  would click
```
S-11: a training layer can **never** qualify for the slot guard (training prompts carry no visible price),
so "One-Handed" vs "Two-Handed" is always decided by similarity. On a five-skill trainer the margin falls
to **0.19** against a floor of 0.15, and training costs gold. Worth either lowering `min_priced` (§4.6,
which also fixes this) or raising `match.min_margin` for `svc.kind === 'train'`.

**Guard / crime — correct, all three routes.** Built from the real `DGCrime*` family with a 350 bounty:
```
[1] pay    cost=350 crit=0                          You caught me. I'll pay off my bounty. (350 gold)
[2] commit cost=0   crit=0                          I submit. Take me to jail.
[3] commit cost=0   crit=0                          I'd rather die than go to prison!
[4] check  cost=350 crit=2 twat=DGCrimeResistArrest I'm with the Guild. How about you look the other way…
[6] check  cost=0   crit=2 twat=DGCrimeResistArrest Any chance I could talk you into overlooking this?
arrest class of this layer: 'topic'
```
* the arrest is detected (`lrgDlgArrestClass` → `topic`), so `:2153` hands the menu back **visible** at the
  shipped `iCritical = 0` — nothing is chosen by voice;
* `lrgDlgIsResistArrest()` (`:3371-3382`) catches the resist line **twice** over, by topic id
  (`DGCrimeResistArrest` ∈ lethal twats) and by its own text (`/i.?d rather (die|fight)/`), and it is the
  **first** rail in the gate (`:2145`), ahead of every setting;
* `crit=2` rows hand the menu back at `:2179`;
* no double fire: `services.kinds.crime.hide = ['ForgiveCrime']` and `hide_always` carry the same code, and
  `d56` asserts `kinds.*.hide ⊆ hide_chim + hide_always`, so a service toggle can never put a shortcut back;
  `lrgDlgHoldMovementPolicy()` runs last and "only ever REMOVES" (`:2980-2981`).
* `PayBounty` survives (it checks the gold itself), `ForgiveCrime` never appears. Matches `d58`.

---

## 7. The other items on the lens

**Calibration status logic — sound.** `ev=calib` never reaches the LLM (answered in preprocessing,
`lrgDlgOnCalib`, `:681`); `k=` is parsed as `name:int` with a `^-?\d{1,12}$` guard and anything malformed is
dropped (`:687-695`); the install-wide answers go to the `*install*` row and only `gate`/`x1`/`rm`/`cm`/`rt`
are copied per-NPC (`:699-703`). `cal=rm3cm1rt2g1` round-trips: `LRG_Dialogue.CalWireShort()` emits
`rm|cm|rt|g`, `lrgDlgParseCal()` (`:664-672`) reads exactly those, and a half-read string is "dropped whole".
The `k=`-side name is `route` and the server reads `$keys['route']` — they agree.
`calib.accept = false` degrades to a log line and nothing else. No defect found.

**NPC-initiated quest talk — every cooldown is real.** `lrgDlgInitiativeCandidate()` (`:2841-2886`):
off unless `qi` (`:2846`); needs a live speech/talk turn (`:2847`); **never** while a session is open, in a
scene, or in OStim (`:2848`); `gap_seconds` since `init_at` (`:2851`); `max_per_session` per `sess` tag
(`:2855`); **once per topic ever** via `init_said` (`:2877`); never a check, payment, commitment, walk-away
or crit row (`:2869-2871`); `min_entry_words` (`:2876`). The slot is spent when the BLOCK is emitted
(`:2909`), not when she speaks, so a turn she declines still costs the slot — she cannot nag.
S-7: `'per_topic_once' => true` (`:97`) is never read — `init_said` is checked unconditionally. Either
honour the key or delete it.

**"What can I ask you" — cache first, index marked approximate.** `lrgDlgAskListBlock()` (`:2736-2776`)
reads `lrgDlgRoot()` first, which is itself bounded by `cache.max_age_seconds`, location and quest
signature (`:1215-1225`). A cache hit emits `certain="1"` with the entries verbatim; a miss falls to
`lrgPromptByQuest()` and emits `certain="0"` with "You are NOT certain", "Speak like somebody who is not
sure", and an explicit ban on the action ("never use TakeUpBusiness from this list"). Index rows containing
`<` are dropped, so `<Global=KmodFerryCost>` can never be offered (`:2765`). Correct.

**"What next" — never invents.** `lrgDlgAskNextBlock()` (`:2783-2808`) reads CHIM's own `questlog`, drops
bookkeeping quests, runs each briefing through the same `lrgDlgCleanObjective()` `<shared_business>` uses,
caps at `quests.next.lines` and `quests.max_chars`, and returns `''` when there is nothing — no fallback
text, no stage number, and the block says "Never mention a stage, a number or a step he has not been shown."
Correct.

**Movement hide — only while `hold=1`, only for the held NPC.** `lrgDlgHoldMovementPolicy()` (`:2983-3011`)
reads the snapshot of `$GLOBALS['HERIKA_NAME']` only, returns unless `hold === '1'`, returns when
`_age > hold_max_age_seconds` (45), and hides only codes actually offered. When the player's own words asked
her to move it hides nothing and arms `do=release` to go first (`:2996-3001`, `:3043-3061`), with the
negation guard applied to the request (`:3034`). It is placed after `lrgDlgHideEndConversationOnHold()` so
it only ever removes. Correct.

**The wire, three ways.** I extracted every `";key="` the `.psc` files write, then asked whether any `.php`
reads it and whether `PROTOCOL.md` v0.5 names it. The new v0.5 keys `qi`, `sv`, `lf`, `cal`, `svck`, `k`,
`gate`, `runs`, `miss`, `resume`, `after`, `reply`, `voice`, `total` are consistent in all three. The four
exceptions are `qig`, `qx`, `svx`, `tg` — written by the game, read by nobody, documented nowhere (S-2).
Everything else flagged by the scan is a PROBE/CALIB **log** string, not a wire key.

**Old scripts (401) still work.** `d50_wire05` passes both directions and the fallback is structural:
`lrgDlgOnEvent()`'s default arm logs "unknown ev … ignored (additive wire: this is not an error)" (`:656`),
every snapshot read is `?? <config default>`, and `do=release` degrades to one "unknown command" funcret
that the server never waits on (`:3038-3041`).

---

## 8. S-4 — the dead-control gate, mutation-tested

Run against the staged copy, restoring the files afterwards:

| mutation | expected | result |
|---|---|---|
| baseline | pass | `4 passed, 0 failed` |
| a real dead control added to `config.json` + `settings.ini` | **fail** | `3 passed, 1 failed` ✔ |
| the same dead control, its id also written in **a Papyrus `;` comment** | fail | **`4 passed, 0 failed` ✘** |
| a live control loses its `settings.ini` line | **fail** | `3 passed, 1 failed` ✔ |

So the gate does fail on a dead control — but a bare mention defeats it, because `:132-133` ends with
`strpos($psc, $id) !== false` over the concatenation of every `.psc`, comments included. I checked whether
any control is *currently* passing that way: **none is** — all 108 appear in real code (I stripped `;`
comments while honouring `"` string literals, since ids legitimately follow a `;` inside `";qi="`). So this
is latent, not live. **Smallest fix:** require the id to appear outside a `;` comment, and evaluate
`SERVER_KNOBS` first (which also fixes S-2's blind spot).

---

## 9. The remaining minors

**S-5 — `pg_trgm` and an index nothing uses.** `grep` over `lib/lrg_prompt_index.php` finds no trigram
operator anywhere; every index SELECT is at `:243, :245, :299, :421, :526, :595` and none can use
`lrg_prompt_norm_trgm`. The PHP matcher is pure PHP (`lrg_dialogue.php:1800`). The GIN index costs build
time and disk on 37,561 rows, is the only reason `pg_trgm` is a dependency, and is the only object a
playthrough drop destroys (§2.3). **Fix:** drop `CREATE EXTENSION IF NOT EXISTS pg_trgm;` and the
`lrg_prompt_norm_trgm` line from `007`, or keep them and say in the file why. A second, smaller point:
`lrgPromptMissingTable()` (`:70-73`) matches **any** `does not exist`, so a missing *function* error would
flip the request to `public` and print "Run tools/deploy_server.ps1" — misleading advice. Unreachable while
no SQL calls a function, but tighten the regex to `relation .* does not exist|42P01` while you are there.

**S-6 — the training census.** `RE_TRAIN` (`build_prompt_index.py:763-764`) captures
`([A-Z][a-z]{2,14}(?:\s+[A-Z][a-z]{2,14})?)` — no hyphen — so `"I need training in One-Handed."`
(LoreRim - Dialogue Patch) yields the skill **`One`**, which is what `service_catalog.json` ships. The
trigger list `train me in|train you in|teach me|training in|lessons in` also misses the two commonest
vanilla forms, `"Can you train me to Block?"` and `"I'd like training in the art of Speech."`, so
**Block, Sneak, Pickpocket, Speech and Two-Handed are absent from the census entirely**. `test_services.php`
does not catch it: `:103-107` only asks for ≥6 names and ≥3 of the vanilla six.
This is diagnostic only — the server never reads `skills` (`grep "'skills'"` over `lib/` finds nothing;
only `destinations_travel` `:1407` and `crime_topics` `:3258` are consumed) — but it is the evidence the
owner asked for under addendum 9(a). **Fix:** allow `[-']` inside the capture and add `train me to` /
`training in the art of` to the trigger, then tighten `test_services.php` to assert `One-Handed` and `Block`
by name. (Worth knowing: `destinations_travel` also contains the non-places `Carriage` and `Ferry`; harmless,
because E7's off-list check only ever *fails to accuse*, but it makes the list look wrong in the report.)

**S-8 — `lib/lrg_core.php:13` is still `define('LRG_VERSION', '0.4.1')`** while `manifest.json` is `0.5.0`.
The round's binding list does not name `LRG_VERSION`, so this is not a broken rule — but it is printed by
`run_flows.php:89` and `test_latency.php:46`, and V04 bumped it in lockstep. One character.

**S-9 — `lrg_actions.php:46-50`** says `LRG_ACT_ALIVE_REQ` is spelt "exactly as CHIM spells it for
RentRoom / HireCarriage / GoToSleep". Verified against the installed
`lib/core/action_catalog.php:1148-1160`: **RentRoom's** builtin is
`['dead','unconscious','sleeping']` with **no** `combat` / `attacking`; only HireCarriage / HireFerry carry
the five. The glue's five-element list is the stricter and better choice — only the sentence is wrong.

---

## 10. FACT-LOCKING AND CONDITION TRUTHFULNESS — verified against the installed CHIM

Every file:line in `lrg_dialogue.php:3386-3393` and `lrg_actions.php:44-99` checks out against
`/var/www/html/HerikaServer` as installed:

| claim | verified |
|---|---|
| CHIM's `lock_profile` is write protection, not fact locking | ✔ `lib/core/npc_master.class.php:1030` sets `$GLOBALS['LOCK_PROFILE']`; `core_npc_master.lock_profile integer DEFAULT 0`; `npc_master.class.php:1415` `WHERE … COALESCE(c.lock_profile,0)=0` guards regeneration |
| `relationships_locked` likewise | ✔ `ext/relationship_system/relationship_llm.php:638` — *"Locked NPCs are user-curated; never machine-write their map"* → `SKIP saveRelationships` |
| there is no post-hoc verifier of the model's claims in CHIM core | ✔ nothing else in `lib/`, `prompts/`, `functions/` matches a fact-check mechanism |
| the real mechanism is the catalog's `requirements` | ✔ `herikaActionCatalogGetBuiltinRequirements()` `action_catalog.php:1145`; `herikaActionCatalogRequirementsMatch()` `:1834` |
| …applied at `functions/functions.php:2745` | ✔ `$dbEnabledFunctions = herikaLoadEnabledActionCodesForMode($isNpcMode, true);` — the `true` is `$applyRequirements`, and `action_catalog.php:2678` then drops any row whose requirements are false, **before** `ENABLED_FUNCTIONS` and the strict JSON enum are built |
| the glue's rows really declare them | ✔ `lrg_actions.php:99` `'requirements' => ['request_types_any' => $types] + LRG_ACT_ALIVE_REQ` on every row, with `lrgActionsNeedRequirements()` (`:53-67`) reinstalling rows that predate the block without bumping `LRG_ACTIONS_VERSION` |
| the locked-facts block uses CHIM's own injection slot | ✔ `context_pre.php:41` `chimRegisterPromptInjection('character_bottom', 'lorerim_glue_locked_facts', …, 205)`; the function is `lib/prompt_injections.php:10` and the slot is rendered at `main.php:2554`, between the glue's boundaries (200) and business rules (210) |

So the honest answer to addendum 9(c) is the one the code already gives: **CHIM offers condition
truthfulness (catalog `requirements`) and the glue now uses it; CHIM offers no fact locking, and the glue
supplies its own `<locked_facts>` block through CHIM's injection slot.** The block itself is bounded as
promised — `truth.max_lines` 6, `truth.max_chars` 600, seven classes, every one freshness-bounded
(`bounty_max_age_seconds` 45, check result 180 s), prices only from a LIVE entry, and `'<' or '>' anywhere
in the line rejects it (`:3412`) so an index template can never become a price. The truth gate drops the
ACTION and never the words (`:3488-3496`). `d59`/`d60` exercise both, and `d59` calls CHIM's **real**
`herikaActionCatalogRequirementsMatch()` when the file is present rather than a stub
(`flows/harness.php:43-82`, `scenarios/d59_truth.php:38-63`) — the strongest form of that test.

---

## 11. What I did not test

No game ran, so nothing here proves in-game behaviour: the `want=1` traces are the shipped functions on
real index rows with `<Global=…>` resolved the way the game resolves it, not a real menu. `bench_llm.php`
was not run against a paid endpoint. `deploy_server.ps1` was not executed (the tree is already deployed —
§3). `esp_dump.py` was not re-run because `make_esp.py` is unchanged. The parallel 0.4.2 latency module was
linted and its three tests run, but it is another round's work and is not assessed here beyond noting that
`lib/lrg_latency.php`'s header still says `0.4.2` while `manifest.json` says `0.5.0`.

Snapshot integrity: I staged at 05:24 and a parallel session recompiled the nine `.pex` at 05:36. Every
`.psc` is older than my copy (newest `LRG_Dialogue.psc` 04:27, `lrg_dialogue.php` 04:49), so the sources I
verified are the sources that were compiled — but the owner should know that two sessions were writing into
`glue\` while this verification ran (see also the build report's own CROSS-LANE OBSERVATION).
