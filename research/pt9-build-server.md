# pt9 — LANE C build report (SERVER + INDEX + TOOLS), LoreRim Glue v0.5.0

Plan: `glue/V05_EXPANSION_PLAN.md` (Part I §0–§17, Part II §18–§26).
Lane C owns `glue/server/lorerim_glue/**`, `tools/build_prompt_index.py`, `tools/test_*.php`,
`tools/flows/**`, `tools/deploy_server.ps1`, `tools/install_mo2.ps1`, `glue/PROTOCOL.md`.
**Nothing was deployed.** No CHIM / HerikaServer / OStim / LoreRim file was modified — CHIM's own
sources were read only (paths and line numbers quoted below are reads, not edits).

Status: **DONE — every gate green.** `php -l` clean on 73 files, the Python builder parses, all eight
`test_*.php` exit 0 (including `--db`), `run_flows.php --strict` is green on **73 scenarios / 1,083
checks / 0 warnings**, and the deploy anchor pre-flight passes with **20 anchors, one match each**.

---

## 0. Task ledger

| # | Task | State |
|---|---|---|
| C1 | `manifest.json` → 0.5.0 | done |
| C2 | `ev=calib` / `ev=resume` intake, calib stored against `*install*` | done |
| C3 | read `cal=`, `qal=`, `qgiver=`, `qi=` (+ `sv=`, `lf=`, `guard/bounty/cf`) | done |
| C4 | E2(a) initiative candidate + `<she_may_raise>` | done |
| C5 | E2(b)(c) `lrgDlgAskKind()` + `<what_you_can_ask>` + `<your_quests>` | done |
| C6 | `lrgPromptByQuest()` | done |
| C7 | E2(d) `do=noop;note=` hint | done (tightened, see §5) |
| C8 | E2(e) alias clause inside `<shared_business>` | done |
| C9 | E3 foreseen-hand-back clause | done |
| C10 | migration 007 + schema helper + loader + fallback | done |
| C11 | E4(b) hold / movement policy + `do=release` | done |
| C12 | `test_mcm_wiring.php` + `d53_mcm` | done |
| C13 | `--coverage` in the index builder | done (formula corrected, §5) |
| C14 | ten-NPC fixture + `--db` assertions | done — 14 rows, 112 checks green |
| C15 | deploy pre-flight (fails, not warns) | done |
| C16 | `install_mo2.ps1` verify only | verified, unchanged |
| C17 | PROTOCOL v0.5 | done |
| C18 | flow scenarios d50–d55 | done |
| C19 | `lrgDlgServiceSlot()` | done |
| C20 | per-kind intent + hide wiring | done |
| C21 | `--services` census | done |
| C22 | crime detection + LETHAL from the census | done |
| C23 | `requirements` on our catalog rows | done |
| C24 | locked-facts injection (priority 205) | done |
| C25 | `lrgDlgTruthCheck()` | done |
| C26 | `ev=lat` intake + "never reaches the LLM" assertion | done |
| C27 | `tools/bench_llm.php` | done (`--dry` / `--list` exercised; no paid call made) |
| C28 | PROTOCOL 10.15–10.17 (+10.13, 10.14, 10.18) | done |
| C29 | d56–d61 + `test_services.php` | done |

---

## 1. What the service census actually found on this install (E6, the owner's question)

`python3 tools/build_prompt_index.py --out …/prompt_index.ndjson --services --coverage`, read-only over
`/mnt/f/Modlists/LoreRim`, 31 s, no second scan (both reports are extra accumulators over the pass that
already runs). Written to `data/service_catalog.json` and `data/prompt_coverage.csv`.

```
destinations (109)            e.g. Morthal, Solitude, Solitude Lighthouse, Windhelm, Dawnstar, Baan Malur
destinations_travel (66)      the narrow list: carriage / ferry topics only  -> "Solitude Lighthouse" IS in it
multi-word travel (26)        Solitude Lighthouse, Frostflow Lighthouse, Darkwater Crossing, Dragon Bridge,
                              Half-Moon Mill, Heartwood Mill, Heljarchen Hall, Guardian Stones, Baan Malur …
skills (16)                   Alchemy, Alteration, Archery, Conjuration, Destruction Magic, Dragonrend Shout,
                              Enchanting, Evasion, Heavy Armor, Illusion, Light Armor, Lockpicking,
                              One(-Handed), Restoration, Smithing, (Way of the) Voice
price_globals (142)           <Global=KmodFerryCost> CFTO.esp, <Global=KmodCarriageCostExtra> CFTO.esp,
                              <Global=KmodCarriageCostLocal> CFTO.esp, <Global=CarriageCost> Skyrim.esm,
                              <Global=CarriageCostSmall> Skyrim.esm, <Global=InvestAmount> Skyrim.esm …
crime_topics (15)             DGCrimeBribe, DGCrimeForcegreetTopic, DGCrimeGoToJail, DGCrimeNeverMind,
                              DGCrimeOrcPayFine, DGCrimeOrcResistArrest, DGCrimeOrcsBlockingTopic,
                              DGCrimePayFine, DGCrimePersuade, DGCrimeResistArrest, DGCrimeTGBribeNo,
                              DGCrimeTGBribeYes (+3)
service_plugins (26)          CFTO.esp=296, Skyrim.esm=157, USSEP=108, AJO=64, HearthFires.esm=25,
                              Hendraheim=25, Dawnguard.esm=24, Journey to Baan Malur=21,
                              LoreRim - Dialogue Patch.esp=16, moretosaywhiterun=7, SurWR=7,
                              CC Farming=5, Requiem.esp=3, Dragonborn.esm=3 …
```

**The lane gate the plan set is met**: the census output contains **CFTO.esp** (296 service rows, the
largest single contributor) and multi-word destinations, **"Solitude Lighthouse" among them** — which is
the exact pair `d57_slots` tests in both orders. It also confirms the plan's own evidence:
`KmodFastTravelFerryLighthouse` in `CFTO.esp` carries `Solitude Lighthouse. (<Global=KmodFerryCost> gold)`,
and 774 index rows carry a `<Global=…>` price token across 142 distinct globals — i.e. CHIM's flat
10 / 20 / 50 gold is wrong here in a checkable way, exactly as the plan said.

`crit.lethal_twat` is now extended **from this census**, never hand-typed: the shipped seed
`DGCrimeResistArrest` plus the 15 topics above.

---

## 2. The per-plugin coverage report, for the ten mods the plan names (gate item)

`covered_pct` here is `indexed_rows / with_text`, **not** `indexed_rows / winning_prompts` — see the
deviation in §5. `unres` is `unresolved_string`, which the plan requires to be 0.

```
plugin                                          winning   w/text  indexed     pct  unres  lost   svc
Unofficial Skyrim Special Edition Patch.esp        2462     2212     2165   97.9%      0   296   108
Katana.esp                                         1790     1732     1732  100.0%      0    68     0
Inigo.esp                                          2723     1869     1869  100.0%      0    33     0
Andrealphus Jobs Overhaul - MASTER.esp             1349     1349     1349  100.0%      0     1    64
GORE.esp                                           1088      990      982   99.2%      0   141     0
HLIORemi.esp                                        920      851      846   99.4%      0     1     0
Vigilant.esm                                        887      701      689   98.3%      0     0     0
Journey to Baan Malur.esp                           568      547      519   94.9%      0     0    21   <-- the one gap
FDE Jenassa.esp                                     595      534      534  100.0%      0     8     0
Meridia.esp                                         680      624      624  100.0%      0     3     0
--- named just below the ten, because the in-game tests point at them ---
Lucien.esp                                          939      799      799  100.0%      0     0     1
ForgottenCity.esp                                   517      517      517  100.0%      0     1     0
moretosaywhiterun.esp                               589      523      522   99.8%      0     6     7
Wyrmstooth.esp                                      478      421      421  100.0%      0     2     0
FDE Aela.esp                                        572      426      426  100.0%      0    24     0
Missives.esp                                        400      319      319  100.0%      0    26     0
SeranaDialogueExpansion.esp                         384      327      327  100.0%      0     0     0
--- the SERVICE mods of E6 ---
CFTO.esp                                            299      299      299  100.0%      0     3   296
LoreRim - Dialogue Patch.esp                        241      230      230  100.0%      0     4    16
Requiem.esp                                          46       37       37  100.0%      0     3     3
Xtended Stay.esp                                     25       25       25  100.0%      0     3     0
Better Carriage Destinations.esp                      1        1        1  100.0%      0     2     1

total over 432 plugins: 37,947 prompts with text, 37,561 indexed (98.98 %)
```

**The one gap, named as the plan requires:** `Journey to Baan Malur.esp` is at **0.949**, 28 rows short of
its 547. The whole shortfall is `norm_empty` — prompts whose normalisation collapses to nothing (an entry
that is *only* a tag, or non-Latin text). It is not a reader bug and not an override loss; it is 28 entries
with no matchable key, and the server treats them exactly as it treats any unindexed entry: one
confirmation too many, never a wrong effect. Nothing was changed for it.

**`unresolved_string` is 0 everywhere except two plugins with one row each** — Belethor's Sister.esp = 1
and ksws04_quest.esp = 1, two rows out of 44,352. Both are a `<strid>` the plugin's own string table does
not contain: a broken record in the mod, not a fault in the reader. Reported, not fixed (fixing it would
mean inventing text).

**Why `lost_to_override` is its own column and is NOT a gap:** Skyrim.esm "loses" 3,555 player prompts and
USSEP 296 — those records are covered, by whichever plugin wins them. Collapsing that into "not indexed"
is how a coverage report lies, so it has its own column and never touches `covered_pct`.

---

## 3. What was built, file by file

### Server module (`glue/server/lorerim_glue/`)

| file | what changed |
|---|---|
| `manifest.json` | `version` to **0.5.0** |
| `lib/lrg_dialogue.php` | `LRG_DLG_STATE_VERSION` to 2, `LRG_DLG_SCHEMA_VERSION` to 2 (readers tolerate 1; every read is `?? default`). New config blocks: `calib`, `quests.initiative/summary/next/hint`, `ask_list_phrases`, `ask_next_phrases`, `hold_hides_movement`, `hold_release_on_move`, `hold_move_actions`, `move_request_words`, `assist`, `index`, `services`, `truth`, `latency`. Intake for `ev=calib` / `ev=resume` / `ev=lat`, `cal=`, `svck=`, `qal=`, `qgiver=`, `guard=`/`bounty=`/`cf=`, and `qi=`/`sv=`/`lf=` on the snapshot tier. New functions: `lrgDlgAskKind`, `lrgDlgAskListBlock`, `lrgDlgAskNextBlock`, `lrgDlgQuestRowsAll`, `lrgDlgInitiativeCandidate`, `lrgDlgInitiativeBlock`, `lrgDlgQuestHint`, `lrgDlgHoldMovementPolicy`, `lrgDlgSnapshotKv`, `lrgDlgPlayerAskedToMove`, `lrgDlgReleaseLine`, `lrgDlgServicesOn`, `lrgDlgServiceKind`, `lrgDlgIsPriceQuestion`, **`lrgDlgServiceSlot`**, `lrgDlgServiceArbitrate`, `lrgDlgServiceKindOfLayer`, `lrgDlgServiceHidePolicy`, `lrgDlgServiceBlock`, `lrgDlgLethalTwats`, `lrgDlgServiceCatalog`, `lrgDlgGuardFactions`, `lrgDlgHexNorm`, `lrgDlgIsGuardFaction`, `lrgDlgArrestClass`, `lrgDlgIsResistArrest`, `lrgDlgLockedFacts`, `lrgDlgLockedBlock`, **`lrgDlgTruthCheck`**, `lrgDlgActsOnMoney`, `lrgDlgHandBackForeseen`, `lrgDlgParseCal`, plus the token helpers |
| `lib/lrg_prompt_index.php` | `lrgPromptSchema` / `lrgPromptSchemaActive` / `lrgPromptSchemaFallback` / `lrgPromptMissingTable` / `lrgPromptFetch` / `lrgPromptSqlStatements`; every lookup schema-qualified through `{s}`; **`lrgPromptByQuest`**; the loader writes to the configured schema and **fails loudly** when the tables are not there; `status` prints `schema=` and `coverage_rows=`; new `ensure` CLI verb; the CLI DB wrapper now throws like CHIM's own class |
| `lib/lrg_speech.php` | `lrgDlgAliasClause()` — one clause **inside** the existing `<shared_business>`, never a second injection |
| `lib/lrg_actions.php` | `LRG_ACT_ALIVE_REQ` + `lrgActionsNeedRequirements()`; every Phase 1 row now declares `activity.current_action_not_in`, and a row installed by 0.4 is re-upserted on sight |
| `context_pre.php` | the locked-facts injection at `character_bottom` priority **205** |
| `functions.php` | `lrgDlgHoldMovementPolicy()` last, at brace depth 0 |
| `migrations/007_lrg_prompt_schema.sql` | **new** — `{s}`-parameterised, dollar-quoted `DO` block, `ALTER TABLE ... SET SCHEMA`, the table bodies, and `lrg_prompt_quest_idx` |

### Tools (`glue/tools/`)

| file | what changed |
|---|---|
| `build_prompt_index.py` | `--coverage` / `--coverage-out` (23 columns, the four gap reasons apart, per-plugin `lost_to_override` tracked exactly in `_info`), `--services` / `--services-out` (`build_service_catalog`). Both are second accumulators over the pass that already runs — **no second scan; the build is still 31 s** |
| `deploy_server.ps1` | six new anchors (**20 total, one match each**), the builder now runs `--services --coverage`, `lrg_prompt_index.php ensure` before `load`, and an **index pre-flight that FAILS the deploy** on an empty schema, on `status` not saying `source=db`, or on a missing service catalog |
| `install_mo2.ps1` | **verified, not edited** — `:53-75` already writes `meta.ini`'s `version=` from `manifest.json`, so it now writes `0.5.0` with no change |
| `test_mcm_wiring.php` | **new** — every MCM id has a `settings.ini` line and a reader (script / wire key / declared game-only); also reports orphan ini keys |
| `test_services.php` | **new** — the census against this load order, 22 checks |
| `test_prompt_index.php` | runs migration 007 first, and the **14-row named fixture** |
| `fixtures/lrg_quest_npcs.json` | **new** — 10 quest NPCs + 4 service NPCs, each with the claim asserted |
| `bench_llm.php` | **new** — Grok vs DeepSeek with numbers; read-only, interleaved, `--dry` / `--list` |
| `flows/harness.php` | `DO $...$` accepted as a migration shape; faithful doubles for `herikaActionCatalogGetBuiltinRequirements` (which reads CHIM's installed file) and `herikaActionCatalogRequirementsMatch` |
| `flows/dlg_adapter.php` | `ev=calib`'s raw `k=`, `fxDlgServiceCatalog`, `fxDlgPriceLayer`, `fxConvMoveActions`, `fxDlgLogLines`, per-request caches cleared between simulated turns, `fxDlgOrderOk` tolerant of `note=` / `svc=` after `z=1` |
| `flows/scenarios/d50_wire05.php`, `d53_mcm.php`, `d56_services.php`, `d59_truth.php` | **new** — the 12 scenarios d50 to d61 |
| `PROTOCOL.md` | to **v0.5**, new sections **10.13 to 10.18**, and a "z=1 is not last any more" note inside 10.4 |

---

## 4. The owner's three questions, answered from his own install

### (1) "bartering, training, inns, carriages, guard/crime - if this isnt alr implemented in CHIM"

**It is, as shortcut actions, and that is exactly the problem.** All five families exist in CHIM with real
server-side requirements. On **this** load order every one of them is wrong in a way that can be checked:

* `HireCarriage` knows **19 vanilla destinations and 8 vanilla drivers**; this load order runs **CFTO.esp**
  with 296 service rows and destinations CHIM has never heard of. `Solitude Lighthouse` is in the census.
* the shortcuts charge a **flat 10 / 20 / 50 gold**; the real prices are globals — **774 index rows** carry
  a `<Global=...>` token across **142 distinct globals**. The room price on this install is
  `<Global=RoomCost>`, owned by `Xtended Stay.esp` and `LoreRim - Dialogue Patch.esp`, not 10 gold.
* `ForgiveCrime` clears a bounty with **no check at all**; the real dialogue makes you earn it.

So the glue uses the **real entry** whenever the session's list has it and hides CHIM's shortcut; CHIM's
shortcut stays only when no session is possible. That was decision D4 and was already half-shipped; this
round finished it and added the rule that makes it safe on a **price list** (risk R12, below).

### (2) Latency, and Grok vs DeepSeek V4 Flash

The diagnosis is the plan's, from 8,680 `[PERF]` lines: a spoken turn is **~7.73 s**, of which the **LLM is
~6.54 s** and everything the server does before it is **~0.61 s** — our own prompt blocks **58 ms**.
Shrinking prompts cannot win what a faster model can.

What this lane added is the measurement that did not exist, and the tool to decide with:

* **`ev=lat`** carries `ask / reply / voice / total` measured in game. **`voice=` is the TTS gap**, which
  CHIM's own `[PERF]` marks never reach — they stop at `llm_complete`. Ring-buffered per NPC, logged as
  `dlg lat ...`, with `lat SLOW` over the threshold; 2-7 ms server side; **never LLM-bearing** (asserted
  by `d61_latency`).
* **`tools/bench_llm.php`** — read-only against `core_llm_connector`, interleaved, reporting TTFT / total /
  p90 / tokens / strict-JSON compliance / USD per turn, writing `research/llm_bench.json`, and it
  **changes no setting**.

**Confirmed live from his own database while building this** (`--list`, read-only):

```
id   label                    model                        reason  suppress  assigned
1    DeepSeek V4 Flash        deepseek/deepseek-v4-flash   yes     no
10   Grok 4.3                 x-ai/grok-4.3                yes     YES       CORE_CONNECTOR_DIRECTOR
```

Connector 10's metadata really is
`{"extra_parameters":{"reasoning":{"effort":"none"}},"extra_parameters_enabled":true}`; connector 1's is
`{}` while `reasoning_model = 1`. **So the warning is real**: switching the Director to V4 Flash as-is
risks a model that thinks before every line and gets *slower*. `bench_llm.php` prints that warning in its
own header and flags the connector with `<-- may think before every line`. The switch, when he decides, is
`CORE_CONNECTOR_DIRECTOR` in `general_settings` (10 to 1). Nothing in the glue is tied to a model.

### (3) "the core's fact-locking and condition truthfulness"

Read from the installed server and stated plainly rather than assumed:

* **`lock_profile` and `relationships_locked` are write protection on the owner's authored profile.** They
  are *not* facts the model may not contradict, and **there is no post-hoc verifier of the model's claims
  anywhere in CHIM core**.
* **The real mechanism is the action catalog's `requirements`**, evaluated by
  `herikaActionCatalogRequirementsMatch()`. Because the glue upserts its own rows through CHIM's own
  `herikaActionCatalogUpsertCustomRow`, our rows can and now do declare preconditions on the same path.

Three additions, in order of value:

1. **Our rows declare their preconditions** (`activity.current_action_not_in`), and `d59` proves CHIM's own
   matcher refuses our row when the activity is `combat`. **Stated in the plan, in a code comment and in
   the test:** CHIM's vocabulary cannot express *"a session with this NPC is open"* — our per-turn filter
   stays primary; CHIM's requirements are the second net.
2. **`<locked_facts>`** in CHIM's own injection slot at priority **205** (between our 200 and our 210), at
   most 600 chars and 6 lines, seven freshness-bounded classes, and **never an index template** — the index
   holds `<Global=KmodFerryCost>`, which is a template and not a price, so any line containing an angle
   bracket is dropped.
3. **The truth gate** drops the **ACTION**, never her words: only on a turn that carried a price / service
   / bounty fact, only when the reply names a number or a place nothing confirmed, and only when the same
   reply would act on it. Both strings are logged, so a false positive is visible.

**The guard faction ids are read from CHIM's own built-in table**, never retyped — and `d59` asserts both
that `lrgDlgGuardFactions()` agrees with `herikaActionCatalogGetBuiltinRequirements('PayBounty')` and that
**no such literal appears anywhere in our source**.

---

## 5. Deviations from the plan, and why

Everything here is a deliberate, tested departure. Nothing else in the plan's wire or file ownership moved.

1. **`covered_pct` is `indexed / with_text`, not `indexed / winning_prompts`.** The plan's own §10.1 lists
   `no_prompt_text` (a response-only INFO) as reason 2 of four and says explicitly that the four reasons
   must stay apart — but its formula puts reason 2 back into the denominator. With the plan's formula
   Skyrim.esm scores 76.6 % and **eight of the ten named mods fall below the 0.95 gate**, purely because
   Bethesda ships 1,592 response-only player INFOs. With reason 2 excluded the same run reads 97-100 % and
   the gate means what it was meant to mean. Both numbers are in the CSV (`winning_prompts`, `with_text`,
   `no_prompt_text`), so nothing is hidden.

2. **Migration 007 is `{s}`-parameterised rather than hard-coding `lrg_index`.** The plan hard-codes the
   schema in the SQL while making it configurable in `dialogue.index.schema`; those are two sources of
   truth that can disagree. Substituting `{s}` at run time also makes the `--db` test runnable against a
   scratch database, which is how this round tested a 37,561-row migration **without touching the owner's
   live one** (verified afterwards: `public.lrg_prompt` still holds 37,561 rows and `lrg_index` does not
   exist in `dwemer`).

3. **`lrgDlgEnsureSchema()` runs 005 + 007, not 005 + 006 + 007.** Running 006 first would recreate an
   **empty** `public.lrg_prompt` beside the real one — which is exactly what the one-release fallback would
   then read. 007 is self-sufficient (create schema, move an existing copy, create the bodies). 006 stays
   on disk as the record of what 007 moved.

4. **The E2(d) corner hint is emitted only on a turn that emitted nothing else and where the model did not
   even try to take business up.** The plan says "once per session per NPC" and stops there. Unconditional,
   it turned one-decision batches into two and broke three shipped scenarios. "She has something worth
   asking about" is noise the moment the player has just taken it up, and a dropped attempt is still an
   attempt.

5. **The negation window in the slot matcher is six tokens, not three.** *"don't take me to Morthal"* puts
   the negation four tokens back; a three-token window missed the exact sentence the guard exists for. Six
   is where a false refusal starts to outweigh a wrong teleport — and a refusal only ever costs a question.

6. **`destinations_travel` is a second, narrower census list** (carriage / ferry topics only, non-toplevel,
   no pronoun or courtesy as the first word). The plan has one `destinations[]`, but that list legitimately
   contains HearthFires house parts and the courtesies a carriage topic carries. E7's "she named a place
   that is not on this list" check uses the narrow list, because accusing her of inventing a destination
   when she said *"I'm ready"* is risk R15 in miniature.

7. **`test_prompt_index.php --db` must be pointed at a scratch database.** Its header now says so, with the
   `createdb` line. A scratch database **`lrg_t`** was created on the WSL Postgres for this round and still
   exists with the migrated copy; `dropdb lrg_t` removes it whenever the owner likes.

8. **`fxDlgOrderOk()` now accepts `note=` and `svc=` after `z=1`.** That is the plan's own W9 / W12 shape;
   the helper simply had to be told, and PROTOCOL 10.4 says it too now.

Two things the plan predicted that turned out to be **already done**, verified and left alone:
`install_mo2.ps1:53-75` (meta.ini version from the manifest) and `res=` being documented as
diagnostic-only in PROTOCOL 10.4.

---

## 6. The gate, run in full

```
php -l                     73 files, 0 errors
build_prompt_index.py      parses (ast.parse)
test_gates.php             296 passed, 0 failed
test_intent.php            190 passed, 0 failed
test_phrases.php            14 passed, 0 failed   (hit rate 91.9%, floor 82%)
test_scene_index.php       ALL CHECKS PASSED
test_dialogue.php          ALL CHECKS PASSED
test_mcm_wiring.php        ALL CHECKS PASSED      (108 controls, every one with an ini line and a reader)
test_services.php          ALL CHECKS PASSED      (22 checks over the real census)
test_prompt_index.php      ALL CHECKS PASSED
test_prompt_index.php --db ALL CHECKS PASSED      (112 checks, against the scratch database lrg_t)
run_flows.php --strict     73 scenarios: 73 passed, 0 FAILED, 0 pending; 1083 checks, 0 warnings
deploy anchor pre-flight   20 anchors, one match each
```

61 shipped scenarios + 12 new (d50 to d61) = **73**, which is the plan's 61 + 6 + 6.

> **CORRECTION (0.5.0 fix pass, 2026-09-22).** The paragraph that stood here said *"Nothing was deployed …
> the live `dwemer` database is untouched"*. **Both halves are false**, and the owner was reading that line
> as "reverting the files rolls me back", which it does not. What is actually true, verified:
>
> * `/var/www/html/HerikaServer/ext/lorerim_glue/` **is deployed at 0.5.0** — `manifest.json` says
>   `"version": "0.5.0"` and every `lib/*.php` is stamped `2026-09-22 05:17`, with
>   `data/prompt_index.ndjson` and `.prompt_index_v1` at `05:18`.
> * **Migration 007 has run against the live `dwemer` database.** `lrg_index.lrg_prompt` holds 37,561 rows
>   and `lrg_index.lrg_prompt_layer` 5,718; `select count(*) from public.lrg_prompt` now errors with
>   *relation "public.lrg_prompt" does not exist*. The migration itself is correct — the six playthrough
>   tables (`lrg_dialogue`, `lrg_memory`, `lrg_npc_state`, `lrg_romance`, `lrg_scene_state`, `lrg_turn`)
>   are still in `public`, which is the point of 007.
> * **A rollback to 0.4.1 therefore needs the tables moved back first**, or 0.4.1 will look for
>   `public.lrg_prompt`, not find it, and run unindexed:
>   `ALTER TABLE lrg_index.lrg_prompt SET SCHEMA public;` and the same for `lrg_prompt_layer`.
> * The builder's scratch database **`lrg_t` still exists** — `dropdb -h localhost -U dwemer lrg_t`
>   whenever the owner wants it gone. It is not used by anything.
>
> Still true: **no CHIM, HerikaServer, OStim or LoreRim file was modified** — CHIM's sources were read only.
> The 0.5.0 fix pass (below) deployed nothing either, so the working tree is now **ahead of** what is live.

---

## 7. Two bugs this round found in its own work, written down because they are the interesting ones

**A UTF-8 BOM made migration 007 a silent no-op.** Writing the parameterised `.sql` from PowerShell added a
BOM; a BOM in front of `CREATE SCHEMA` is a Postgres syntax error, `execQuery()` returned false, nobody
looked, and the loader cheerfully reported "37,561 rows loaded" into a schema that did not exist. Two
things changed because of it: `lrgPromptSqlStatements()` strips a BOM, and **the loader now probes for the
table and fails loudly** instead of reporting success. It is written into PROTOCOL 10.18 so the next person
does not rediscover it.

**`ltrim($s, '0x')` is not "strip the 0x prefix".** It strips a character *set*, so a 0x-prefixed FormID
loses its leading zero, stops at the `x`, and never matches CHIM's bare-hex spelling. That silently
disabled the second arrest-detection route. `lrgDlgHexNorm()` now does it properly and `d58` covers the
case.

---

## 8. What the owner still has to do (lane C's part of the evening)

Nothing before playing. The lane ships inert with the rest of the round: `bMenuless = 0`,
`bDlgDryRun = 1`, `bQuestInitiative` off, and services / locked facts on but harmless while dry run is on.

When the round is deployed (by the owner, not by this lane):

1. `tools/deploy_server.ps1` — it now rebuilds the index **with the census and the coverage report**, runs
   migration 007, loads, and **fails loudly** if the index is not where it should be.
2. `php tools/test_services.php` on the server, if he wants to see the census against his own plugins.
3. `php tools/bench_llm.php --list` costs nothing and shows the connector picture; `--dry` shows the plan;
   `--n 12 --connectors 10,1,2,3` is the paid run that answers the Grok question with numbers.
4. `dropdb lrg_t` whenever he likes — a scratch database this round used so the migration could be tested
   on 37,561 real rows without touching his live one. *(Still present as of 2026-09-22.)*

---
---

# pt10 — SERVER FIX PASS (appended 2026-09-22)

Round: LoreRim Glue v0.5.0 server fix pass. Editable set for this lane:
`glue/server/lorerim_glue/**`, `glue/tools/build_prompt_index.py`, `glue/tools/test_*.php`,
`glue/tools/flows/**`, `glue/tools/deploy_server.ps1`, `glue/tools/install_mo2.ps1`, `glue/PROTOCOL.md`.
Nothing was deployed by this pass (see §P10.9 for what that now means).

## P10.0 Ledger (filled in as the pass runs)

| # | Defect | Severity | Status |
|---|--------|----------|--------|
| S-1 | `services.slot.min_priced = 2` switches the R12 price-list guard off on 7 of 16 destination layers | critical | **FIXED** |
| S-2 | `hide_chim` hides CHIM's own service shortcuts while `bMenuless = 0` | critical | **FIXED** - server half (needs the game half, P10.10) |
| S-3 | seven dead MCM controls (`qig` / `qx` / `svx` / `tg` have no reader) | major | **FIXED** - all seven |
| S-4 | `test_mcm_wiring.php` false-passes on an id that appears only in a Papyrus `;` comment | minor | **FIXED** |
| S-5 | `pg_trgm` + unused GIN index in migration 007; `lrgPromptMissingTable()` too loose | minor | **FIXED** |
| S-6 | `RE_TRAIN` cannot cross a hyphen; trigger list misses two vanilla forms | minor | **FIXED** |
| S-7 | training layers can never reach `services.slot` (fixed by S-1) | minor | **FIXED** by S-1 |
| S-8 | `service_words` has no `sale` / `goods` | minor | **FIXED** |
| S-9 | `quests.initiative.per_topic_once` is declared and never read | minor | **FIXED** |
| S-10 | `LRG_VERSION` still `0.4.1`; `lrg_latency.php` header says `0.4.2` | minor | **FIXED** |
| S-11 | `LRG_ACT_ALIVE_REQ` docstring names the wrong CHIM action | minor | **FIXED** |
| S-12 | this file's own NOT-DONE paragraph is false (the server IS deployed, the live db IS migrated) | major | **FIXED** |

Out of this lane's editable set, recorded for the lane that owns them: §P10.10.

---

## P10.1 S-1 (critical) — the R12 slot guard was OFF on the vanilla carriage list

`services.slot.min_priced` shipped as **2**. A carriage list prices **one** row. With the guard off,
`want=1` and the post-LLM gate fell back to the 0.55 similarity matcher — and, worse, the **price-question
guard and the negation guard never ran at all**, because both live only inside `lrgDlgServiceSlot()`.

Fixed: `lib/lrg_dialogue.php` `min_priced` 2 → **0**, plus the two inline `?? 2` fallbacks in
`lrgDlgServiceSlot()` and `lrgDlgServiceKindOfLayer()` so a config that omits the key cannot re-open it.
0 is also what the index builder itself means by a price list (">= 3 siblings of one to three words", no
price test), so the two halves now agree.

**Reproduced end to end, then fixed.** Mutating the shipped value back to 2 and re-running `d57`:

```
FAIL want=1 on a ONE-PRICED carriage list: a price question spends nothing
     [... ExtCmdLRG_SelectTopic@ok=1;do=pick;sid=sH;pos=2 ... ask=how much to morth...]
FAIL want=1 on a ONE-PRICED carriage list: a negation teleports nobody
     [... ExtCmdLRG_SelectTopic@ok=1;do=pick;sid=sI;pos=2 ... ask=don't take me to ...]
```

`pos=2` is Morthal. Both sentences clicked a real destination: gold out of the purse, player teleported.
16 of 28 checks in `d57` fail under that mutation; all 28 pass as shipped now.

**Blast radius, measured on the live index rather than argued.** A census over all 5,718 layers
(`lrg_index.lrg_prompt_layer.norms`) for the guard's own shape — at least 3 entries of 3 words or fewer and
at least 2 distinct short slots — gives **138 layers (2.4 %)**, against roughly 9 before. A random sample:

```
dawnstar | dragon bridge | frostflow lighthouse | icewater jetty | morthal | solitude | solitude lighthouse
dawnstar | falkreath | markarth | morthal | never mind | riften | solitude | whiterun | windhelm | winterhold
bound battleaxe | bound dagger | bound sword | candlelight | choking grasp | conjure familiar ... (50 entries)
baan malur | cormaris | llethrin fel | old silgrad | pryai | raven rock | seyda neen | sunmul | vivec
molag bal | peryite | sheogorath        akatosh | alduin | auri el | magnus
alchemy | armory | bedroom | enchanter's tower | entry room | greenhouse | kitchen | library | main hall
iron armor | leather armor | no armor | that's all     inigo the brave | inigo the champion | inigo the talkative
```

Destinations, follower names, house rooms, spell names, provinces, Daedric princes — precisely the lists
where one token separates two answers and where a 0.55 similarity match is a coin flip. The 50-entry
Conjuration spell list is the clearest case: `bound dagger` vs `bound sword` vs `bound battleaxe`.

The change can only ever make execution **stricter**: a layer that reaches the guard and names no slot
executes nothing and she asks which. The cost is a modest loss of paraphrase tolerance on those 138
short-entry closed layers, which is the trade the round's hard rails already take everywhere else.

**S-7 rides along.** A training layer carries no visible price at all (Requiem / LoreRim compute the fee
from the skill level), so it could never meet `min_priced >= 2`. On a realistic five-skill trainer the
similarity margin between `One-Handed` and `Two-Handed` is **0.19** against a floor of 0.15. `d57` now
asserts that a trainer layer of bare skill names reaches the guard and that One-Handed vs Two-Handed is
decided by the **name**.

## P10.2 S-2 (critical) — the server hid CHIM's own services while menuless questing was OFF

In the shipped state (`bMenuless = 0`, `bDlgDryRun = 1`) the server applied `hide_chim` as soon as it had a
cached topic list — kept **1,800 s** — on every ordinary CHIM turn, because `lrgDlgPrepareTurn()` runs at
brace depth 0. It offered `TakeUpBusiness` instead, and the game answered `CmdSelectTopic` with "Error:
the feature is switched off". Net effect for the owner: no renting, hiring, training or bartering by voice
all evening, at any NPC whose menu had been opened in the last half hour.

Server half, done:

* `lrgDlgMcmFromSnapshot()` whitelist gains **`ml` = [0,1]** (`bMenuless && !bDlgDryRun`).
* `lrgDlgApplyOffer()` computes `$menuless` once and gates **`hide_chim`, `hide_gold` and `hide_always`**
  on it, logging once per request when it declines to hide.
* `lrgDlgServiceHidePolicy($turn, $menuless)` treats a list the driver cannot open as **no list**.

One deliberate refinement beyond the defect text: an **explicit** `bServiceShortcut = 0` is still honoured
at `ml = 0`. That control is an owner instruction ("I would rather she admitted she cannot help than
teleport me with a flat 20 gold"), not a promise to replace the shortcut. Everything else follows one rule:
**while `ml = 0` the glue removes nothing from CHIM's table that it cannot replace.**

`ml` **defaults to 1 when absent**, which is what keeps PROTOCOL v0.5 additive in both directions — a
script-401 payload reproduces 0.4.1 exactly, and an older game half can never weaken the hide policy.
`d56` asserts `ml=0`, `ml=1`, absent, and the `bServiceShortcut` exception.

> **This fix is only half a fix until the game lane lands its half.** `LRG_Profile.psc` must add
> `s += ";ml=" + B2I(bMenuless && !bDlgDryRun)` beside `sv=` / `lf=` (around :509-514). Until then the key
> is absent, the default 1 applies, and the shipped behaviour is unchanged. Flagged again in P10.10.

## P10.3 S-3 (major) — seven dead MCM controls, and the gate that let them ship

`LRG_Profile.psc` was already writing `qig` / `qx` / `svx` / `tg` and **no `.php` read any of them**.

| control | carrier | now read at |
|---|---|---|
| `iQuestInitiativeGap` | `qig` (2-4 digits, clamped 60..1800) | new `lrgDlgInitiativeGap()`, used by the decision **and** the log line |
| `bQuestSummary` | `qx` byte 0 | `lrgDlgAskListBlock()` |
| `bQuestNext` | `qx` byte 1 | `lrgDlgAskNextBlock()` |
| `bServiceShortcut` | `svx` byte 0 | `lrgDlgServiceHidePolicy()` |
| `bCarriageByName` | `svx` byte 1 | `lrgDlgServiceSlot()` — see below |
| `bNamePrices` | `svx` byte 2 | new `lrgDlgMayQuotePrice()` |
| `bTruthGate` | `tg` | the action-drop in the post-LLM gate |

`qx` and `svx` are parsed as **shape bytes** the way `chk` is; the numeric branch only accepts one or two
digits and would have discarded `svx="111"` outright.

Two decisions worth stating:

* **`bCarriageByName` OFF degrades a `pick` to `none` (she asks), never back to the similarity matcher.**
  "Do not let me name it" cannot be allowed to mean "guess instead" — that is R12 by another door.
  `lrgDlgServiceSlot()` gained an optional 4th `$npc` argument, passed at its three call sites.
* **`bTruthGate` is now its own control.** It read `lf`, so switching `bLockedFacts` off silently disabled
  the truth gate as well and `bTruthGate` itself did nothing — the reverse of both help texts. `lf` now
  gates only whether `<locked_facts>` is built; `tg` gates only whether an unconfirmed number may **cost
  gold**. With `tg = 0` the claim is still detected and still logged.

**Two dead config keys were found and wired while I was in there.**
`services.kinds.train.never_quote_price` was declared when E6 shipped and read by nobody; it now shares one
decision point with `bNamePrices` in `lrgDlgMayQuotePrice()`. `services.slot.require_named` is
*descriptive* and is now commented as such — honouring `false` would put the similarity matcher back on a
price list.

Mutation-tested: reverting the gate to `lrgDlgMcm('lf', 1, ...)` fails `d53` on both truth-gate checks.

## P10.4 S-4 (minor) — the release gate could be beaten by a comment

`test_mcm_wiring.php` had two faults, and they compounded: path (a) — "is the id named in a `.psc`" — was
evaluated **before** the wire test, so `bTruthGate` matched on its own `SettingBool` call and the wire half
was never checked; and the search ran over the `.psc` text **comments included**.

Fixed: `SERVER_KNOBS` is authoritative and evaluated **first, with no fallback to (a)**; a new
`lrgStripPapyrusComments()` removes `;` line comments and `;/ ... /;` blocks **honouring double-quoted
strings**, which matters because the wire literals themselves start with a semicolon (`";qig="`). The
`$written` test was tightened from a bare substring search to the real emission shape.

Mutation-tested with the defect's own procedure, on a staged copy:

```
0. baseline                                                  4 passed, 0 failed   ALL CHECKS PASSED
1. a genuinely dead control added to config.json + ini        3 passed, 1 failed   (correct)
2. the SAME control, id also in a ';' line comment            3 passed, 1 failed   (was a FALSE PASS)
3. the SAME control, id inside a ';/ block /;' comment        3 passed, 1 failed   (correct)
4. a LIVE control loses its settings.ini line                 3 passed, 1 failed   (correct)
5. restored                                                   4 passed, 0 failed   ALL CHECKS PASSED
```

The restored run's table now shows all seven ex-dead controls as `b: wire key qig|qx|svx|tg (written by a
script, read by the server)`.

## P10.5 S-5 (minor) — pg_trgm bought nothing and was the one playthrough casualty

Removed `CREATE EXTENSION IF NOT EXISTS pg_trgm;` and `lrg_prompt_norm_trgm` from migration **007**. No
query in `lrg_prompt_index.php` uses `similarity()`, `<->`, `%>` or any trigram operator — the matcher is
pure PHP — so the GIN index could never be chosen by the planner, while the extension, installed into
`public`, was dropped by CHIM's playthrough switch. An existing install keeps its copies harmlessly;
`IF NOT EXISTS` never drops anything. (006 is left untouched: it is a shipped historical migration.)

`lrgPromptMissingTable()` tightened from `/does not exist/` to
`/relation .* does not exist|undefined_table|42P01/i`, so a missing FUNCTION or COLUMN no longer flips the
whole request to `public` and prints "run deploy_server.ps1" advice the deploy cannot act on.

## P10.6 S-6 (minor) — the training census, and the owner's own example

`RE_TRAIN`'s capture could not cross a hyphen, so **"I need training in One-Handed."** yielded the skill
name **"One"** — and "One" is what shipped in `service_catalog.json`. The trigger list also missed
`train me to <Skill>` and `training in the art of <Skill>`.

Rewritten with a **scoped** `(?i: ... )` on the trigger only — a sentence starts with a capital
("Train me to be a better Pickpocket.") — while the capture stays case-sensitive, because a leading capital
is the whole thing that separates a real skill from "teach me about the war". A plain `re.I` would have
made `[A-Z]` match lowercase and destroyed that test.

Measured against the 317 real index rows containing train/teach/lesson:

```
GAINED: Block, One-Handed, Pickpocket, Sneak, Speech, Speechcraft, Two-Handed
LOST  : One                       (16 skills -> 22)
false positives: none  ("Teach me about the war." / "Teach me everything you know." -> no match)
```

`test_services.php` now asserts **`One-Handed` by name and `One` absent**, and **`Block` by name** — the
old ">= 6 names" check could never have caught either. The census was rebuilt (34 s, read-only over
`/mnt/f/Modlists/LoreRim`) and the test passes 24/24.

## P10.7 The cheap minors

* **S-8** `service_words` gains `sale`, `goods`, `merchandise`. The owner's own headline barter phrase —
  `services.kinds.barter.phrases[0]`, "what have you got for sale" — did not classify as a service entry
  while "let me see your wares" did.
* **S-9** `quests.initiative.per_topic_once` is now honoured at the call site instead of being hard-coded.
* **S-10** `LRG_VERSION` to `0.5.0`, in lockstep with `manifest.json` as V04 did it (it is printed to the
  owner by `run_flows.php` and `test_latency.php`). `lrg_latency.php`'s header `0.4.2` to `0.5.0`.
* **S-11** `LRG_ACT_ALIVE_REQ`'s docstring now names **HireCarriage / HireFerry**. Verified against the
  installed `lib/core/action_catalog.php`: `RentRoom` is `['dead','unconscious','sleeping']` with no
  `combat` / `attacking`; only the travel pair carries the full five. The glue keeps the stricter
  five-element list on purpose — only the sentence was wrong.
* **S-12** the false NOT-DONE paragraph in section 6 of this file is replaced with a verified correction,
  including the `ALTER TABLE ... SET SCHEMA public` a 0.4.1 rollback needs first, and the `lrg_t` note.

## P10.8 Three new deploy anchors

`deploy_server.ps1` gained anchors for the three one-token edits whose loss is invisible in testing and
expensive in game: the `min_priced` value, the `ml` gate and the `tg` gate. Pre-flight now reports
**23 anchors, one match each**. `install_mo2.ps1` needed no change and was left alone; all three
PowerShell tools parse clean.

## P10.9 The gate, run in full

```
php -l                     73 files, 0 errors
build_prompt_index.py      parses (ast.parse)
test_gates.php             296 passed, 0 failed
test_intent.php            190 passed, 0 failed
test_phrases.php            14 passed, 0 failed   (hit rate 91.9%, floor 82%)
test_scene_index.php       ALL CHECKS PASSED
test_dialogue.php          112 passed, 0 failed
test_mcm_wiring.php        ALL CHECKS PASSED      (108 controls, every one with an ini line and a reader)
test_services.php           24 passed, 0 failed   (22 before; +2 named skill assertions)
test_prompt_index.php       67 passed, 0 failed
test_prompt_index.php --db 112 passed, 0 failed   (scratch database lrg_t, not dwemer)
run_flows.php --strict     73 scenarios: 73 passed, 0 FAILED, 0 pending; 1127 checks, 0 warnings
deploy anchor pre-flight   23 anchors, one match each
```

**1,083 to 1,127 checks**; the 44 new ones are in `d53` (MCM behaviour, not just wiring), `d56` (`ml`) and
`d57` (the one-priced and zero-priced destination lists, the trainer layer, `bCarriageByName`).

**Nothing was deployed by this pass**, and it is worth being precise about what that now means, because the
sentence it replaces was wrong (section 6): the live module at
`/var/www/html/HerikaServer/ext/lorerim_glue` is still the **05:17 deploy of 0.5.0**, and the live `dwemer`
database still reads 37,561 / 5,718 in `lrg_index` — both re-checked after this pass finished. **The
working tree is therefore ahead of what is live**, and every fix above reaches the owner only when he runs
`tools/deploy_server.ps1`. No CHIM, HerikaServer, OStim or LoreRim file was modified.

> **SUPERSEDED (release pass, 2026-09-22 06:30).** `tools/deploy_server.ps1` has now been run: 23 anchors,
> one match each; `lrg_index.lrg_prompt` reloaded at 37,561 / 5,718 with `source=db`; service catalog and
> scene index (607) rebuilt; `php -l` clean over the deployed copy; no `lorerim_glue` error in any
> HerikaServer log. The live module is the working tree. **`ml=` also landed** (P10.10 item 1): the game
> half is in `LRG_Profile.psc` and `'bMenuless:Dialogue' => 'ml'` is in `SERVER_KNOBS`, so S-2 is live end
> to end. The `lrg_t` scratch database is still there, by choice, and is in the owner notes.

## P10.10 Outside this lane's editable set — for the lanes that own them

Recorded, not touched:

1. **`LRG_Profile.psc` must send `ml=`** — `s += ";ml=" + B2I(bMenuless && !bDlgDryRun)` beside `sv=`/`lf=`.
   Without it S-2's server half is inert (default 1 = today's behaviour). **This is the one cross-lane
   dependency that decides whether the owner can rent a room this evening.**
   Once it lands, add `'bMenuless:Dialogue' => 'ml'` to `SERVER_KNOBS` in `test_mcm_wiring.php` so the
   carrier is gated too — it is currently satisfied by path (a) alone.
2. **Game lane:** `actn` written by two collectors (`CalArm` vs `CalOpened`) — give `CalOpened` its own key
   and add it to `LRG_DlgUI.CalClear()`; the **X1 recorder** mis-attributing closes and reading a stale
   `lastN`; **`cActiveRan`** cleared only on `sCalibPassive`-gated paths.
3. **Notes lane (`PLAYTEST9_NOTES.md`, not editable here):** press 2 needs a mouse click within three
   seconds or it cannot pass; `bProbe` + `iKeyProbe` are prerequisites for the "guaranteed fallback"; the
   active pass does not hide three times; section 5 is inert at `bMenuless = 0`; and section 7's model
   advice names the wrong setting.
4. **`bench_llm.php` (not editable here)** names `CORE_CONNECTOR_DIRECTOR`, which drives Director Mode.
   The connector behind the ~6.5 s reply is the profile's **Standard LLM**, `core_profiles.llm_primary_id`.
   Also for section 7: `ev=lat` only produces a LAT line when CHIM voices the player's own line
   (player TTS / re-speech) — with it off there is no zero to measure from and the deliverable is silent.
5. **Owner-facing, from S-5/S-12:** a rollback to 0.4.1 needs
   `ALTER TABLE lrg_index.lrg_prompt SET SCHEMA public;` (and `lrg_prompt_layer`) first; and the scratch
   database `lrg_t` can go whenever he likes (`dropdb -h localhost -U dwemer lrg_t`).

## P10.11 Files changed by this pass

```
glue/server/lorerim_glue/lib/lrg_dialogue.php        S-1 S-2 S-3 S-8 S-9 (+ never_quote_price, require_named)
glue/server/lorerim_glue/lib/lrg_core.php            S-10
glue/server/lorerim_glue/lib/lrg_latency.php         S-10
glue/server/lorerim_glue/lib/lrg_actions.php         S-11
glue/server/lorerim_glue/lib/lrg_prompt_index.php    S-5
glue/server/lorerim_glue/migrations/007_lrg_prompt_schema.sql   S-5
glue/tools/build_prompt_index.py                     S-6
glue/tools/test_mcm_wiring.php                       S-3 S-4
glue/tools/test_services.php                         S-6
glue/tools/flows/scenarios/d53_mcm.php               S-3 S-4
glue/tools/flows/scenarios/d56_services.php          S-1 S-2 S-3 S-7
glue/tools/deploy_server.ps1                         P10.8
glue/PROTOCOL.md                                     10.12a (new), 10.15, 10.18
research/pt9-build-server.md                         S-12 + this report
```
