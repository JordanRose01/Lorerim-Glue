# pt8-build-server.md — LANE C (SERVER + INDEX) build log, v0.4.0 round

Builder: LANE C. 2026-09-21/22. Plan: `glue/V04_BUILD_PLAN.md` §5 (+ §2 wire, §6 checks, §7 quest tree,
§8 price). Design: `glue/PHASE2_DESIGN.md` rev 2 §2–§7. **Nothing was deployed. Nothing under
`F:\Modlists\**` was written — every read of the load order was read-only.**

**Status: GREEN.** Final run, from the staged copy, all of it inside WSL:

```
php -l clean on 60 files                       build_prompt_index.py syntax OK
tools/test_dialogue.php                        85 passed, 0 failed   ALL CHECKS PASSED
tools/test_prompt_index.php --db               74 passed, 0 failed   ALL CHECKS PASSED
tools/flows/run_flows.php --strict             58 scenarios: 58 passed, 0 FAILED, 0 pending;
                                               897 checks, 0 warnings
tools/test_intent.php / test_gates.php / test_phrases.php / test_scene_index.php   all exit 0
```

The prompt index was built for real over all **3,496** active plugins in **33 s** (37,561 prompts + 5,718
layers) and loaded into Postgres, where a norm lookup plans in 0.7 ms and executes in 0.07 ms. The one
remaining non-zero exit of `run_flows.php` is a **pre-existing Phase 1** database shape, proved
pre-existing by a Phase-1-only baseline run (§7.3).

---

## 0. Ownership as this lane applied it (and the forced deviations)

The round prompt's ownership list is narrower than `V04_BUILD_PLAN.md` §0.1's for lane C. Where they
disagree the round prompt wins, because it is the operative instruction and it names the other lane's files
explicitly ("Do NOT edit those").

| Plan §0.1 gave lane C | Round prompt | What this lane did |
|---|---|---|
| `lib/lrg_core.php` (constants `LRG_ACT_TOPIC`, `LRG_SCHEMA_VERSION` 5, `LRG_VERSION` 0.4.0) | INTIMACY lane owns it | **not touched** (`grep -c lrgDlg lib/lrg_core.php` = 0). `LRG_ACT_TOPIC` is defined defensively in `lib/lrg_dialogue.php` (`if (!defined(...))`), and Phase 2 runs its **own** schema ensure with its own marker, so it never waits on `LRG_SCHEMA_VERSION` moving. Hand-off in §8. |
| `lib/lrg_actions.php` (the D-12 pass-through and the `lrgRecordResult` guard) | INTIMACY lane owns it | **not touched** (`grep -c` = 0). Both edits are written out verbatim with their anchors and their verification in **§8.1** — they are the two that fail *silently* if skipped or misplaced. |
| `migrations/004_lrg_dialogue.sql` | 004_* owned by the INTIMACY lane | renumbered to **`005_lrg_dialogue.sql`**; the index to **`006_lrg_prompt_index.sql`**. Both sort after Phase 1's `003_*` and after any `004_*` the other lane adds, so `lrgEnsureSchema()`'s name-order run stays unambiguous. |
| `config/lrg_dialogue.default.json`, `config/lrg_dialogue_overrides.default.json` | not in the allowed list | **defaults live in code** (`lrgDlgDefaults()` + `lrgDlgCheckDefaults()` + `lrgDlgPriceDefaults()`), overridden by a `dialogue` block in the owner's `config/lrg_config.json`. The design §5.4 override file is read from `config/lrg_dialogue_overrides.json` **if the owner creates one**; no default file is shipped. |
| `config/lrg_config.default.json` (`checks`, `paid_intimacy`, `price_days`) | INTIMACY lane owns it | **not touched.** Every default is in code; a `dialogue.*` block in `lrg_config.json` overrides it. |
| `tools/flows/scenarios/2*.php`–`3*.php` | — | **ids `d20`…`d41`, files `d20_*.php`…`d41_*.php`.** Phase 1 already owns `20_lead_hold.php` … `26_diagnostics.php`; the design's numbering would have collided with six live scenarios. `'d'` sorts after `'9'`, so the Phase 2 block runs last in one run. |
| §8 paid intimacy (the emission half) | INTIMACY lane owns the mechanism | this lane built **`lrgDlgPrice()` only** — a pure price-band function. The acceptance (`BeginIntimacy`'s `amount`), the post-gate drop and `pay=` on `ExtCmdLRG_StartIntimacy` are in `lib/lrg_actions.php` / `LRG_OStim.psc`. Contract written into `PROTOCOL.md` §10.7; hand-off in §8.3. |

**One file was edited that is nominally lane B's:** `tools/deploy_server.ps1`. Lane B had already written
the anchor pre-flight and an index build step into it; this lane fixed the builder invocation (its default
`--out` is relative to the *staged* script folder, which is outside the plugin) and added five
"did-my-edit-survive" anchors. Details in §6.2 — `forIntegrator`.

**One Phase 1 scenario file was edited:** `tools/flows/scenarios/16_hook_wiring.php`, one assertion. It
asserted "`functions.php` registers exactly **one** post-LLM filter"; design §6.1 requires **two**, in
order. The new assertion checks both identity and order. See §7.4.

---

## 1. What was built

| File | Lines | What |
|---|---|---|
| `glue/tools/build_prompt_index.py` | 1,063 | **NEW.** The offline prompt index. Pure-python ESM/ESP reader on the `python3` the WSL distro already has. Read-only; writes one ndjson. |
| `glue/server/lorerim_glue/lib/lrg_prompt_index.php` | 573 | **NEW.** The loader, the lookup API, the norm rule, the fail-safe merge, the staleness check, a CLI (`load` / `status`). |
| `glue/server/lorerim_glue/lib/lrg_dialogue.php` | 1,848 | **NEW.** The module: config defaults, state, the wire, entry classification, ranking, the prompt blocks, the three matching modes, two-step confirmation, the post-gate, both delivery routes, the action row, the JSON-template hook, the transformer, the chat relabel. |
| `glue/server/lorerim_glue/lib/lrg_speech.php` | 652 | **NEW.** The three added features: free-conversation checks (`lrgDlgCheck`, the difficulty pick, immunity, memory, the directive, the G6 line), the quest-tree block (`lrgDlgQuestBlock`), the price band (`lrgDlgPrice`). |
| `glue/server/lorerim_glue/migrations/005_lrg_dialogue.sql` | 30 | **NEW.** `lrg_dialogue(npc_name PK, payload jsonb, updated_at)`. |
| `glue/server/lorerim_glue/migrations/006_lrg_prompt_index.sql` | 60 | **NEW.** `lrg_prompt` (28 columns, `pg_trgm` GIN on `norm`) + `lrg_prompt_layer` (GIN on `norms`). |
| `glue/tools/test_prompt_index.php` | 300 | **NEW.** 67 checks against the REAL built index, +3 with `--db`. |
| `glue/tools/test_dialogue.php` | 260 | **NEW.** 85 table-driven checks of matching and classification. |
| `glue/tools/flows/dlg_adapter.php` | 270 | **NEW.** The Phase 2 flow adapter. Phase 1's `harness.php` and `adapter.php` are used **unchanged**. |
| `glue/tools/flows/scenarios/d2*.php` … `d4*.php` | 8 files | **NEW.** Scenarios d20, d20b, d21, d21b, d22, d23, d24, d25, d26, d27, d28, d29, d30, d30b, d31, d32, d33, d33s (SHARMAT child process), d34, d35, d36, d37, d38, d39, d40, d41. |
| `globals.php`, `preprocessing.php`, `prerequest.php`, `functions.php`, `prompts.php`, `context_pre.php`, `manifest.json` | — | **anchored one-call edits** (§6.1). |
| `glue/PROTOCOL.md` | +196 | **v0.4.** Header + versions bullet + the new `funcret` reasons/success strings + the whole new **§10** (the Phase 2 wire) + two ownership rows. Sections 0–9 unchanged except where marked `[0.4]`. |
| `glue/tools/deploy_server.ps1` | +12 | the builder invocation fix and five Phase-2 anchors (§6.2). |

---

## 2. The offline prompt index — run for real, and what it found

### 2.1 The parser decision (needed no install)

A **pure-Python ESM/ESP record reader**, derived from `research/p2_speech_tags.py`'s reader
(`BSA`, `LoadOrder`, `records`/`subrecords`, `parse_strings`) but **standalone** — the research script is
not imported and not modified, so a later edit of it can never change what the server indexes.

* `plugins.txt` / `loadorder.txt` / `modlist.txt` read from `F:\Modlists\LoreRim\profiles\Ultra\`
  (read-only); the file index is every enabled mod's top level by modlist priority + `Stock Game\Data` +
  `overwrite`; canonical record id `<origin master>:<24-bit id>`; zlib-compressed records handled with
  stdlib `zlib`; the winner is the last plugin in load order that carries the record.
* Only the `DIAL` (+ child `INFO`), `QUST`, `GLOB`, `PERK`, `KYWD`, `FACT`, `VTYP`, `CLAS`, `RACE` and
  `FLST` top-level groups are decoded; everything else is seeked over by header.
* **Stated deviation from "BSA-less", as the plan allows:** the five vanilla masters are localized and
  there are no loose `Strings/*.STRINGS` here, so vanilla `FULL`/`RNAM`/`NAM1` text is read from the
  **uncompressed** `Skyrim - Interface.bsa` string tables — a plain read-only header walk. **The BSA
  reader supports zlib (v104) only and raises `Unreadable` on an LZ4-compressed entry**, so no LZ4 module
  exists anywhere in this build and decision D6 is enforced by construction, not by a flag.

### 2.2 The real run

```
python3 tools/build_prompt_index.py --stats --out server/lorerim_glue/data/prompt_index.ndjson
profile Ultra   plugins read 3496/3496   localized 87   unreadable 1   33.1 s   file 30.1 MB
rows 37561   layers 5718   toplevel 14731   with response 37553   truly silent 8
shared (DNAM) responses resolved 13670   player INFOs seen 44352   no prompt text 6405   <strid> 0
checks: persuade 366  intimidate 135  bribe 86     compound 18   amulet-OR 176
speech_base_av (a trainer cap, NOT a check) 5      PNAM-unreachable 9
prices: literal 252   token 974   token patterns 1158   scripted 14838   twat 879 (LETHAL 12)
PNAM chains: complete 47367, partial (file order used) 2323     unresolved string ids: none
DSD: 192 files in 175 folders under 3 mod roots, DIALOGUE entries 0  (plugin text == displayed text)
DSD types: ACTI RNAM 8076, FLOR RNAM 552, PERK EPFD 120, GMST DATA 38
```

**Two numbers differ from the plan and the difference is real, not a bug:**

1. **`unreadable` is 1, not 82.** The plan expected the 82 localized Creation Club plugins to have no
   readable strings. With this reader **86 of the 87 localized plugins resolve their string tables**
   (`unresolved string ids: none`), because their own `.bsa` files hold the `.STRINGS` entries
   **uncompressed**, so the zlib-only reader reads them without touching LZ4. The one genuinely unreadable
   plugin is **`Sanguine Symphony.esp`**, whose `Sanguine Symphony.bsa` is LZ4-compressed. So decision D6
   costs 1 plugin, not 82 — the 22 CC speech topics the plan wrote off as engine-only are in fact indexed.
2. **DSD is 192 files in 175 folders under 3 mod roots, not "277 files across 4 folders".** The types are
   the same four and **zero** are `DIAL`/`INFO`, so the conclusion is unchanged (plugin text == displayed
   text) and the builder fails loudly if that ever changes. The count difference is a counting rule
   (leaf folders vs config folders); the roots are `Norden UI 16x9`, `Oblivion Interaction Icons -
   Phoenix's Patches`, `Oblivion Interaction Icons`.

### 2.3 CHECK-KIND DISCOVERY BY CONDITION FUNCTION — the census, and the answer

The builder never looks at a text tag to decide a kind. It emits, into the index header and into
`lrgPromptKinds()`, **every distinct `(function id, name, actor value, comparand kind, comparand, operator)`
tuple seen on a player INFO**, with counts and example topics, plus a per-function census of all conditions.

**The answer for THIS load order, stated plainly:**

* **Exactly three engine check kinds exist, and only three**: `persuade` (`GetActorValue` /
  `GetPermanentActorValue` / `GetActorValuePercent` on actor value **17 = Speechcraft**, **366** INFOs),
  `intimidate` (`GetIntimidateSuccess`, function **655**, **135**), `bribe` (`GetBribeSuccess`, function
  **654**, **86**). 587 check rows in total.
* **No mod in this list adds a deceive, charm or seduce ENGINE check.** The census contains no such
  function on any player INFO, and `test_prompt_index.php` asserts it every run. `(lie)`, `(bribe)`,
  `(brawl)`, `(vigilant)`, `(karma)`, the romance/family follower switches and the META `(skip quest)` /
  `(fail quest)` tags carry **no** condition that implements a check. **So the deception the owner asked
  for can only be the free-conversation check of §4.1 — that is now a measured fact, not an assumption.**
* `GetBaseActorValue(Speechcraft)` (function **277**) is tagged `speech_base_av` and is **not** a check:
  5 INFOs, all trainer caps.
* **Comparands are not five tiers.** Over the persuade rows: `SpeechAverage` 101, `SpeechEasy` 73,
  `SpeechHard` 71, `SpeechVeryHard` 32, `SpeechVeryEasy` 17, and **literals** 50 (×19), 40 (×17), 75 (×13),
  60 (×10), 25, 80, 45, 30, 15, 35, 85. Mods add their own globals too. Nothing in this lane assumes
  "global" or "five tiers": the difficulty pick maps a band to a global **name** and the value is read live.
* **`compound` is 18 rows, and the Amulet of Articulation is counted apart (176).** Function **182 is
  `GetEquipped`** and the amulet OR-branch (`FLST 0x0F759C TGAmuletofArticulationList`) sits on most
  vanilla persuades; counting it as "compound" would have switched retry-suppression off almost everywhere.
  `compound` now means what the design means: a weapon skill, level, race or perk passes **instead** of the
  check. Verified by hand against the research: `DialogueDushnikhYalGhorbashPersuade` decodes to
  `GetIsID AND (Speech >= SpeechEasy OR amulet) AND (GetLevel >= 35 OR Speech >= SpeechVeryHard OR amulet)`
  — exactly `p2-requiem.md`'s own hand-decode.
* The DIAL-subtype filter is in force **before** anything is called a check: the allow-list is `CUST` plus
  the favour subtypes, and the subtype census on player prompts is `CUST 37522, RUMO 23, PFGT 16`. No
  `HELO` INFO can be indexed as persuasion.

### 2.4 PNAM re-ordering — the orc topics are flagged, and there are nine of them

`INFO PNAM` is the previous-INFO link; the builder reconstructs the real order from it (complete chains for
**47,367** topics, file order for 2,323) and flags a check INFO as `unreachable=1` when a sibling that is
**unconditional for that speaker** (no condition beyond `GetIsID` / race / sex / faction scoping) sorts
before it. Nine topics, reported to the owner and **nothing changed**:

```
persuade   DialogueMorKhazgurBorgakhPersuade       persuade   DialogueDushnikhYalGhorbashPersuade
intimidate DialogueDushnikhYalGhorbashIntimidate   bribe      MQ202RatwayEsbernBribe
persuade   MQ202RatwayEsbernPersuade               intimidate CWMission03Intimidate
persuade   CWMission03InnkeeperPersuade            persuade   ccKRTSSE001_QF_Branch1TopicB
persuade   aaJenassaDBD3
```

The plan expected "four orc topics"; three of the four orc check topics are here (Borgakh's bribe is not:
its success INFO sorts first), plus five more the plan did not know about. **[U]** whether these checks are
really unpassable in game is **T6 / E21**; the index only reports it.

### 2.5 Coverage — and the one finding that changed the code

`lrgPromptNorm()` maps a menu line to an index key. Measured over the real index:

| | share of the 37,561 rows |
|---|---|
| the norm maps to exactly ONE index row | 47.2 % |
| several rows, but they **agree on every claim the gate uses** | 21.9 % |
| **unambiguous for the gate** | **69.1 %** |
| several rows that disagree on a gate claim | 30.9 % |
| …of those, where a check / crit / price is involved at all | **8.95 %** |

The design's "93.1 % of prompts unique" does not hold at row level in this load order: 1,601 distinct norms
are carried by more than one topic, led by `"any thoughts"` (622 rows), `"hello"` (320),
`"i need to trade some things with you"` (284), `"wait here"` (219), `"nevermind"` (173).

**Why that is still safe, and the one place it was not.** Execution is always "click the LIVE entry at its
LIVE position", so an ambiguous index can only mis-state a *claim* (class, kind, crit, cost), which costs a
confirmation too many or too few. The exception is the **crit grade**: exactly **7 norms have one LETHAL
candidate (a `DGCrimeResistArrest` arrest line) and one ordinary one** —
`"what's the problem"`, `"you're making a mistake"`, `"i'm the jarl's thane i demand you let me go at once"`,
`"i'm with the guild how about you look the other way"`, `"too rich for my blood"` and two more. Picking the
ordinary candidate would have let the driver treat an **arrest layer as ordinary business** instead of
handing the menu back.

So `lrgPromptBest()` now ends in **`lrgPromptEscalate()`**: after the preference order picks a row, every
risk claim is raised to the strictest value **any** candidate makes — `crit` = max, `cost` = max (a token
price wins), `kind` / `topic_kind` / `twat` = the first non-empty, `scripted` / `goodbye` / `walkaway` /
`sayonce` = OR, and `compound` = max (because compound switches retry-suppression *off*, which is the
strictest reading: never talk the player out of an attempt the engine might pass). The index may
over-claim risk; **it may never under-claim it.** Asserted in `test_prompt_index.php` §5b against the real
DGCrime rows and against a synthetic worst case.

### 2.6 The named assertions of design §7.3, all green against the real load order

* `MG03CallerBookPersuade` carries both variants; the success INFO is the one with the persuade condition;
  both INFOs carry `topic_kind=persuade`; the full condition list is stored and names the Speech global and
  the amulet.
* `MS11` (Blood on the Ice) has scripted + Goodbye prompts — "commit and close".
* Every `DGCrime*` prompt whose walk-away resolves to `DGCrimeResistArrest` is graded **LETHAL (crit=2)**;
  12 rows in total.
* The Xtended Stay hub `RentRoomStartTopic` is indexed, carries **no** price tag, and **does** resolve a
  shared response ("Of course." / "Sure thing.") — `BRANCH D1`'s correction of "silent hub" confirmed.
  Priced duration entries exist one layer down.
* 1,158 token prompts became wildcard patterns, `"How about <Alias=SonsMajorHoldCapital>?"` among them, and
  the live menu line **"How about Riften?" matches it through the pattern tier**.
* 5,718 closed-layer fingerprints were built.

### 2.7 The new index columns beyond the design's list

`topic_kind` (the TOPIC's check kind on **every** INFO of it — without it a shown FAILURE variant is
unrecognisable, and design §4.5's scoff-first rule is written in exactly those terms), `fail_brawl`,
`fail_hard`, `unreachable`, `amulet`, `shared` (how many topics carry this norm — the "questions shared by
more than 20 topics" rank rule needs the real count, not a capped query), `txt`, `conds`, `subtype`,
`plugin`. All additive.

---

## 3. The server module

### 3.1 The wire (PROTOCOL.md §10)

`lrg_topics` (with the `part=2` tail re-assembly, `n` always the full count, and the raw `e=` last key),
`lrg_dlg` with all six `ev`s (`line` de-duplicated on the **pair** `(gen, string)`; `sg` accepted on
`ev=facts` as well as `ev=open`), `lrg_dlgtalk` (admitted only with a player utterance ≤ 30 s old, no open
session and no scene speaker), and `ExtCmdLRG_SelectTopic` with the fixed key order, `z=1` last, emitted on
**both** delivery routes with the **same `x`** (D1 `echo` + `terminate()`, D2 a `responselog` row in the
verbatim production shape). `do=award` is the free-conversation effect carrier and is emitted by the
server's own post-gate, never chosen by the LLM.

### 3.2 The three matching modes, and only three

* **Key mode** — a `T`-key from **this** turn's `offer.keys`; every class, under §4.5's rails. A key from
  an older turn, or one not in this turn's list any more, is dropped `stale`.
* **Intent mode** — `plain` and `service` **only**, score ≥ 0.55 with margin ≥ 0.15. It now tries **two**
  candidate strings and takes the better: the words the model named the business with (`ask=`) and the
  player's own last utterance. The token tier is an **F1 over the meaning-carrying words** (symmetric, so a
  long utterance cannot out-score a short entry), max'd with containment (0.85) and `similar_text`.
* **Same-session continuation** — `service` and `pay` only, with **all** of D-18's conditions: the session
  is open and the layer closed, the utterance **names the value** the layer asks for, score ≥ 0.70 with
  margin ≥ 0.25, `pg >= N`, no `twat`, never `check`/`commit`/`meta`, and **depth 1** (carried on the
  session across its layers, so a second continuation is refused).

The margin rule is what keeps ambiguity from being resolved by list order; `test_dialogue.php` asserts that
an utterance fitting two entries almost equally tops on one of them but is **refused** by the margin.

### 3.3 Two-step confirmation, speech-check rails, no narrated outcome

Commit heuristics in the documented order, first hit wins, with the **UI-only word list LAST and only for
unindexed text** (50 % recall / 28 % false alarms). A vanilla "Are you sure?" sub-layer (a two-entry
yes/no layer) **is** the confirmation and no second one is stacked. The park is keyed on `(norm, request)`,
so the release needs a **different request** — which is why the turn cid now carries a per-request sequence
number, not just the clock (two turns in one second would otherwise never release a park).

Retry suppression compares the player's **purse** against the purse recorded at the attempt (the first
version compared the purse against the click's gold *delta*, which made every retry look "changed" and
silently disabled the rule). Scoff-first and retry suppression are **off** for `compound` and unindexed
entries. The freeze rule (B1) refuses a click when the player's gold moved between "list read" and "click".

Anti-narration: the `<real_business>` rules block, the `JSON_TEMPLATE` hook (action/target/item ahead of
message **plus** the two appended description clauses, because CHIM's schema is `strict` and `item`'s own
description tells the model to leave it blank), the chained transformer that returns `''` **only** when the
pick will really be emitted, tolerance of a non-empty `message`, and `<what_just_happened>` as fact with
**no verdict** attached to an `unknown`.

### 3.4 What the NPC is told

`<real_business>` (static, `character_bottom`) · `<business …>` (volatile) · `<shared_business>` (quest
tree) · `<what_just_happened>` (ground truth). Entries **verbatim**, only the leading tag replaced by a
label from the index kind. A closed layer shows ALL entries (cap 12, engine order) and is **never
bucketed** — its overflow is stated in a plain sentence, not a `(+N more)` group. A root list shows a
ranked top 8 + `(+N more: …)`, ranked over **head ∪ tail**. While `sent < n` the NPC **may not deny that a
matter exists** — the wording is spelled out in the block.

**Deviation:** `<real_business>` is injected **only when a list is actually known**. On a cold turn it
would be 70 wasted words on every ordinary CHIM exchange, and it broke Phase 1's own contract ("an NPC with
no snapshot gets nothing injected", flow 16). Intent mode still works there because the action row's own
`description` carries the two rules that matter, paid for once in the catalog rather than per turn.

### 3.5 Coexistence with Phase 1

`$GLOBALS['LRG_DLG_TURN']` is Phase 2's own; `LRG_TURN` is never read. Scene/OStim come from
`lrg_npc_state` **and** `ev=facts`. An open or pending matter hides `StartIntimacy`, `Clothing`,
`RequestAct`, `Invite` and `EndConversation`. `ForgiveCrime` is hidden **always** while menuless is on
(D4); `PayBounty` is on no hide list. The service actions (`RentRoom`, `HireCarriage`, `HireFerry`,
`Brawl`, `Training`, `OpenInventory`, `OpenInventory2`) are hidden only when the real list is known, and
`GiveGoldTo`/`TakeGoldFromPlayer` only while a priced or bribe entry is listed. There is **no**
`TradeItems` code on any list (the code is `OpenInventory`). `ScriptProxy` gets a **health check only**.
`chimQuestEngineFeatureEnabled()` true ⇒ the whole module stands down and says so **once**.
Scenario **d33s** proves all of this with `LRG_SHARMAT_PRESENT` defined, in its own child process.

---

## 4. The three added features

### 4.1 Free-conversation speech checks (owner addenda 5b and 7)

`lrgDlgCheck()` runs only when: `bFreeChecks` on, **no engine entry of that kind is listed this turn**
(an engine check always wins), the kind is `persuade|intimidate|bribe|deceive`, ≥ 4 words on voice input,
and the module is not stood down. A negated, quoted or questioned utterance is not an attempt.

| kind | the rule, replicating the engine's own |
|---|---|
| `persuade` / `deceive` | pass when the live Speech actor value ≥ the **live value** of the `Speech*` global the server picked for the band, **or** the game confirmed the **Amulet of Articulation** (`perk` contains `amulet`) — which is exactly how the amulet passes *instead of* the comparison in dialogue. `deceive` is one band harder. |
| `intimidate` | pass **only** when the game's own `wis=1` (`WillIntimidateSucceed()`). **`wis` absent ⇒ the check is refused outright, never guessed** — guessing an intimidation is precisely what must not happen. Profiles in `checks.immune` (`jarl`, `court`, `vigilant`, `priest_mara`) always fail. |
| `bribe` | the server names N from the wage anchor (or `bamt` when the engine would price this NPC); no amount named ⇒ she names her price and **nothing moves**; below N or unaffordable ⇒ fail. |

Difficulty = `clamp(stakes + stance + bias + deceive_band, 0, 4)` → one of the five live globals. Stakes
come from a word→band config table (`small`/`price`/`access`/`secret`/`crime`/`grave`); stance from the
profile, guard/merchant/hostile and the affinity band; bias from `iCheckBias`. **No formula, threshold or
number ever reaches the LLM or the player** — asserted in d38 and d39.

Consequences: success ⇒ `do=award` (`xp=1`, `take=N` for a bribe, `stat=` only when `bCheckStats` is on —
**off by default**, because Beneficial Speech Checks pays out on that delta); failure ⇒ one
`lrgAdjustAffinity($npc, -2, 'check failed')` **at most once per attempt**, hostility only with the toggle
on (default off). Per-NPC memory keyed `kind|md5(norm)`: inside `checks.memory_seconds` the remembered
result is handed over instead of a re-roll, and a `deceive` against an NPC who already caught a lie
**auto-fails** until affinity moves. One greppable G6 line per attempt with every input that decided it.

**Deviation / [U]:** the design gives `sg=` (the five live Speech globals) on `ev=open` only, but a
free-conversation check usually happens with **no session at all**. This lane accepts `sg=` on `ev=facts`
too (additive — lane B need not change anything for it to work, and it is now in PROTOCOL §10.2). Until a
session has ever run, `checks.fallback_thresholds` (10/25/50/75/100, owner-overridable) speaks and the G6
line says **`(fallback)`** so it can never be mistaken for a live read. **Ask for `sg` on `ev=facts`** —
§8.2.

### 4.2 Quest-tree awareness (owner addendum 4)

`lrgDlgQuestBlock()` joins the game's `q` / `qobj` to CHIM's `questlog`, newest row per quest, keeping only
quests that have shown an objective. Bookkeeping quests are dropped by pattern (`000FCQuest*`, `*Status*`,
`DialogueGeneric*`, `WI*`, …). Leftover tags are rewritten: `<Alias=QuestGiver>` → the NPC's own name,
any other `<Alias…=X>` → "the x", `<Global=…>` → "some". **Current objective only, never a future stage,
no stage numbers, nothing from `skyrim_quest_definitions`.** A quest in the journal but **not** in `q` is
never mentioned. At most 3 clauses, ≤ 120 chars each; with an empty `q` the block is **absent**, not empty.
A quest name appears in braces in `<business>` only when `questlog` already has a row for it.

**Bug found and fixed while testing:** `q` and `qobj` were being lower-cased by Phase 1's `lrgCsv()`.
`questlog.id_quest` is **case sensitive**, so a live `IN (...)` would have found nothing. `lrgDlgIds()` now
preserves case; asserted in d40.

### 4.3 "Everyone has a price" (owner addendum 6)

`lrgDlgPrice($npc, $state, $profile)` — a **pure** function, no emission, no gold movement, no scene.
From the owner's own anchor: 3–5 gold/hour ⇒ `day_wage` **35**, and every price is expressed in **days of
wage** so one knob rescales the whole economy. Bands by profile, falling back to bands by wealth tier,
where the wealth tier is the **higher** of her profile's and the one her real gold puts her in:

```
beggar / destitute 0.5-2 d   tavern_folk / commoner 1-4 d   innkeeper / merchant / adventurer 4-15 d
guard / soldier 15-60 d      housecarl / court 60-300 d      jarl / noble 300-3000 d
priest_mara, vigilant: not_for_sale     any "never" profile: not_for_sale
```

Divided by her `money_influence` (from the **existing** `gold_sway` word: decisive 1.5 … insulting 0.25),
then: genuinely interested (`interest.score >= 0`) ⇒ **no price at all**; `curious` ⇒ the floor;
`indifferent` ⇒ the ceiling; married elsewhere ⇒ `refuse` means never, `secret` means ×3; renown lowers it
the way `renown_sway` already works; a remembered arrangement ⇒ ×0.85; rounded to 5.

She is told her stance **in words plus ONE number** and names the figure herself. Measured in d41 with the
real Phase 1 profiles: a commoner's floor is one to a few days' wage; a jarl's floor is ≥ 10× a commoner's
and her ceiling ≥ 300 days of wage ("ridiculous", as asked); a Vigilant is told "No amount of gold would
move her." Adults only, the consent gates, the kill switch and the no-fetish rail are untouched — a price
never overrides a `never` profile or a refusal she gives for other reasons.

`lrgDlgPriceRemember()` stores `{ask, paid, at, times}` for 7 days so she can refer to it and price him
next time. **The acceptance half is the INTIMACY lane's** (§8.3).

---

## 5. Flow tests and the two new suites

`tools/flows/scenarios/d20…d41` cover every scenario of design §7.3 plus the five the plan added:

| id | what it proves (one line each) |
|---|---|
| d20 / d20b | wire parsing with `;` and `=` inside a text; `e` last; the cap; `n` is the full count in both parts; the tail re-assembles; **ranking over head ∪ tail pulls a position-19 Missives topic into the block**; the deny prohibition fires while `sent < n` and stops when the tail arrives |
| d21 / d21b | no list ⇒ nothing injected; `do=open` only with `bIntentOpen` **and** a named marker; `want=1` answers on **both** routes with one `x`; the D2 row's production shape; `z=1` last; no value contains `; = @ \| "` |
| d22 | intent mode never executes a check; the list is cached anyway; `lrg_dlgtalk` refused with a session open, admitted with a fresh utterance, refused again after 30 s |
| d23 / d24 / d25 | keys from the cache; `do=pick` with the cached pos/i; a `txt` prefix free of the forbidden characters; an unoffered key is `stale`; age / location / journal each invalidate the cache; a closed layer shows all (cap 12, engine order, **never bucketed**); a root list is top 8 + the more-line; a follower command framework and a 44-topic question rank down |
| d26 / d27 / d28 | the first commit selection **parks and is not muted**; the second from a different request executes; another key replaces the park; 60 s expires it; a single-token voice answer is refused; the label comes from the index kind although the text says `(Intimidate)`; the min-words rule; a FAILED result is stated as fact and told once; retry suppressed until `sp` moves; **off for compound**; a bribe's price is told, the exchange **is** the two-step, `pg < N` and `A < N` never click, gold actions hidden |
| d29 | service actions hidden only when the list is known; hub + "three nights" completes in **one** session under continuation and moves gold once; a check sub-layer, a weak score and a second continuation all execute nothing |
| d30 / d30b / d31 | LETHAL ⇒ `do=show`, never a click, never a leave; COSTLY stays menuless with `[leaving now ends this]`; a scene speaker gets no action, no block, no `lrg_dlgtalk`, from `ev=facts` **and** from the snapshot; a LOST layer keeps its keys, the pick carries the **dead** `sid` and a `txt` prefix; after two losses closed layers are handed back |
| d32–d36 | the transformer mutes **only** an emitted pick and chains the previous one; the schema order and the two appended description clauses; a non-empty message is tolerated; an open session hides Phase 1's actions; a Narrator-routed reply is offered and executes nothing; the quest engine stands the module down **once**; the freeze rule refuses a click after gold moved |
| d33s | **all of the above still true with `LRG_SHARMAT_PRESENT` defined**, in its own process, with `LRG_TURN` never built |
| d37 / d38 / d39 | scoff-first parks once then executes; compound executes at once; the attempt memory; **one** `do=award` at most, `xp=1`, no pos/sid, `stat=` empty, **no `SetBribed`/`SetIntimidated`/stage ever**; the memory hit; a deceive after a caught lie auto-fails; an engine entry stands the free check down; `wis=0` cannot pass at any skill; a jarl and a Vigilant are never intimidated; the affinity drop is asked for exactly once |
| d40 / d41 | the quest block's join, tag rewrites, bookkeeping drop, 3-line cap, no stage numbers, absent-not-empty; the price bands, the not-for-sale profiles, free-when-interested, the repeat discount, one number and no formula |

`test_dialogue.php` (85 checks) is the fast table-driven half: the entry parser, the raw-last-key split,
the norm and price rules, the match thresholds, the whole class vocabulary, the commit heuristics **in
order** (including that an *indexed* ordinary line does **not** commit on the word list alone, while an
*unindexed* one does), every label, the safe prefix, ranking, the own-confirmation layer, amounts, every
contract default, and the action row's exact shape.

`test_prompt_index.php` (67 checks, 70 with `--db`) runs against the **real** built index: the norm rule
table, the corrected price regex (`(3 days left)`, `(takes 6 hours)`, `(goldfish)` all reject), the header
facts, the census answer, the named design §7.3 assertions, the pattern tier on a live line, the
PNAM-inverted topics, and the fail-safe merge on the real DGCrime rows.

---

## 6. The anchored edits, with their greps

### 6.1 The seven edits this lane made (each one call, each verified)

```
server/lorerim_glue/globals.php        external_fast_commands              -> 1   ('lrg_topics','lrg_dlg' added)
server/lorerim_glue/preprocessing.php  lrgDlgHandleGameMessage             -> 1   (inserted BEFORE the strncmp lrg_ block)
server/lorerim_glue/preprocessing.php  lrgDlgFilterChat                    -> 1   (the chat relabel)
server/lorerim_glue/functions.php      lrgDlgPrepareTurn();                -> 1   (brace depth 0)
server/lorerim_glue/functions.php      LRG_DLG_POSTGATE'];                 -> 1   (registered AFTER Phase 1's)
server/lorerim_glue/prerequest.php     lrgDlgPrerequest();                 -> 1   (after lrgPrerequest();)
server/lorerim_glue/prompts.php        PROMPTS']['lrg_dlgtalk']            -> 1   (rules only, no example lines)
server/lorerim_glue/context_pre.php    lorerim_glue_business'              -> 1   (+ the chained transformer)
server/lorerim_glue/manifest.json      "version": "0.4.0"                  -> 1
```

**Brace-depth check on `functions.php`** (`awk` counting braces; the requirement is "outside BOTH SHARMAT
branches"):

```
depth 0 : lrgDlgEnsureActions();
depth 0 : lrgDlgPrepareTurn();
depth 1 :     $GLOBALS['action_post_process_fnct_ex'][] = $GLOBALS['LRG_DLG_POSTGATE'];
depth 1 :     $GLOBALS['HOOKS']['JSON_TEMPLATE'][] = $GLOBALS['LRG_DLG_JSONHOOK'];
```

The two depth-1 lines sit inside their own one-line `if (!in_array(...)) { … }` guard (the "register
exactly once" rule), whose `if` is itself at depth 0 **after** the closing brace of the SHARMAT if/else.
Scenario **d33s** proves the placement behaviourally: with `LRG_SHARMAT_PRESENT` defined and `LRG_TURN`
never built, the module still works and the scene gate still fires.

**Files this lane did NOT touch** (`grep -c "lrgDlg\|lrg_dialogue\|lrg_speech\|SelectTopic"` = 0 in each):
`lib/lrg_core.php`, `lib/lrg_actions.php`, `lib/lrg_intent.php`, `lib/lrg_scene_index.php`,
`config/lrg_config.default.json`, `tools/test_gates.php`, `tools/test_intent.php`,
`tools/test_phrases.php`, `tools/flows/harness.php`, `tools/flows/adapter.php`,
`tools/flows/run_flows.php`, `migrations/001..004`.

### 6.2 `tools/deploy_server.ps1` — forIntegrator

Lane B had **already** written the §1.2 anchor pre-flight and a prompt-index step into it. Two fixes:

1. **The builder invocation was wrong.** It ran `( cd '$Target' && python3 '$indexWsl' )`, but the
   builder's default `--out` is relative to **its own** folder (`os.path.dirname(__file__)`), and it is
   staged to `$env:TEMP\lrg_index`, **outside** the plugin. The index would have been written to
   `…/lrg_index/../server/lorerim_glue/data/…`, which does not exist. Now:
   `python3 '$indexWsl' --quiet --out '$Target/data/prompt_index.ndjson'`, then
   `php '$Target/lib/lrg_prompt_index.php' load '$Target/data/prompt_index.ndjson'`, then a `status` print.
2. **Five Phase-2 anchors added** so a lost or duplicated edit of *this* round is caught by the pre-flight
   too, not only the Phase 1 anchors it must land between: the `preprocessing.php` handler, the
   `functions.php` turn and post-gate registration, `lrgDlgPrerequest();`, and the `context_pre.php`
   volatile injection. The pre-flight now covers **13** anchors, each required exactly once.

---

## 7. Verification — every command and its result

All runs from the staged copy in `$env:TEMP\lrg_test`, through
`wsl -d DwemerAI4Skyrim3 --cd / -- bash /mnt/c/Users/Jordan/AppData/Local/Temp/lrg_test/<script>.sh`
(LF-ended scripts, never inline-quoted through `wsl`).

### 7.1 Lint

`php -l` over every `server/lorerim_glue/*.php`, `lib/*.php`, `tools/test_*.php`, `tools/flows/*.php` and
`tools/flows/scenarios/*.php`: **clean, no output**. `ast.parse` over `tools/build_prompt_index.py`: **OK**.

### 7.2 The two new suites

```
php tools/test_dialogue.php        -> 85 passed, 0 failed   ALL CHECKS PASSED   (exit 0)
php tools/test_prompt_index.php    -> 71 passed, 0 failed   ALL CHECKS PASSED   (exit 0)
php tools/test_prompt_index.php --db
                                   -> 74 passed, 0 failed   ALL CHECKS PASSED   (exit 0)
python3 tools/build_prompt_index.py --stats
                                   -> 3496/3496 plugins, 37,561 rows, 5,718 layers, 33.1 s
```

The `--db` run loaded the real index into the live Postgres and queried it back:

```
\dt lrg_prompt*        lrg_prompt | lrg_prompt_layer      (both owned by dwemer)
select count(*)        37561  /  5718  /  587 check rows  /  12 crit=2
DialogueMorKhazgurBorgakh* : both variants, topic_kind carried on both, cost=-1 on the bribe
explain analyze where norm='i need work'   Planning 0.688 ms, Execution 0.071 ms
```

**Note for the owner:** that `--db` run created and filled `lrg_prompt` and `lrg_prompt_layer` in the live
`dwemer` database. They are new, additive and read by nothing until the plugin is deployed; no Phase 1
table was touched. Drop them with `DROP TABLE lrg_prompt, lrg_prompt_layer;` if a clean slate is wanted.

### 7.3 The flow suite, strict, all variants

```
php tools/flows/run_flows.php --strict
58 scenarios: 58 passed, 0 FAILED, 0 pending; 897 checks, 0 warnings
Database calls outside the shapes PROTOCOL.md section 5 allows (the fake could not serve them):
  1x  SELECT npc_name, updated_at, active FROM lrg_scene_state WHERE active=0 ORDER BY updated_at DESC LIMIT 1
RESULT: FAILED - 1 database call(s) outside PROTOCOL section 5
```

**That one database call is PRE-EXISTING Phase 1 code and not this lane's.** It is
`lib/lrg_actions.php:1133`, inside Phase 1's proposal bookkeeping. Proved by building a Phase-1-only
baseline (the Phase 2 scenario files removed and the Phase 2 hook additions stripped) and running the same
command: the baseline reports **the same 1 call** and therefore the same non-zero exit. So
`run_flows.php --strict` does not exit 0 on this tree **with or without** Phase 2; this lane added no
failure and no warning. **forIntegrator:** either reshape that read to a PROTOCOL §5 shape or have the
harness whitelist it, otherwise the release gate can never be green on exit code alone.

### 7.4 The Phase 1 suites, all unchanged and green

```
php tools/test_intent.php        183 passed, 0 failed    exit 0
php tools/test_gates.php         296 passed, 0 failed    exit 0
php tools/test_phrases.php        14 passed, 0 failed    exit 0  (hit rate 91.8 %, floor 82 %)
php tools/test_scene_index.php   ALL CHECKS PASSED       exit 0
```

**One Phase 1 flow assertion was changed**, in `tools/flows/scenarios/16_hook_wiring.php`: it asserted
"`functions.php` registers exactly **one** post-LLM filter". Design §6.1 requires **two**, in order
(Phase 1 first — it must pass `ExtCmdLRG_SelectTopic` through untouched — then Phase 2). The new assertion
checks the count **and** the identity **and** the order, which is the stronger claim. Nothing else in
Phase 1's flow files, harness or adapter was touched.

---

## 8. Integrator hand-off — the edits this lane could not make

### 8.1 `lib/lrg_actions.php` — the two edits that fail SILENTLY if skipped or misplaced

**(a) The D-12 pass-through.** Insert immediately **after** the statement

```php
if (stripos($code, 'ExtCmdLRG_') !== 0) { $out[] = $line; continue; }      // hint :1300, verified this round
```

this line:

```php
if (strcasecmp($code, 'ExtCmdLRG_SelectTopic') === 0) { $out[] = $line; continue; } // Phase 2 gate handles it
```

and it must land **before**

```php
if (!$turn || strcasecmp($actor, (string) $turn['npc']) !== 0 || !lrgEnabled() || defined('LRG_SHARMAT_PRESENT')) {   // hint :1304
```

**Verify:** the inserted line's index is greater than the first anchor's and less than the second's.
Without it every `ExtCmdLRG_SelectTopic` line dies as `gate: dropped unknown glue action` (hint `:1437`) —
silently, and **under SHARMAT it dies even when Phase 1 is idle**.

**(b) `lrgRecordResult`'s guard.** Extend

```php
if (stripos($raw, 'command@ExtCmdLRG_') !== 0 || !lrgEnabled()) { return; }        // hint :608
```

to also ignore `command@ExtCmdLRG_SelectTopic`, e.g.

```php
if (stripos($raw, 'command@ExtCmdLRG_') !== 0 || !lrgEnabled()
    || stripos($raw, 'command@ExtCmdLRG_SelectTopic') === 0) { return; }
```

Otherwise Phase 1 stores the dialogue result in `lrg_memory.last_result` and the NPC is told about it a
second time — Phase 2 composes `<what_just_happened>` itself.

Both anchors are already in `deploy_server.ps1`'s pre-flight, so a misplaced edit fails the **deploy**.

### 8.2 `lib/lrg_core.php` — three constants, and one ask for lane B

* `LRG_VERSION` `0.3.1` → **`0.4.0`** (this lane already set `manifest.json` to 0.4.0).
* `LRG_SCHEMA_VERSION` 3 → **5** *(optional this round)*. Phase 2 runs its own ensure over
  `005_lrg_dialogue.sql` and `006_lrg_prompt_index.sql` with its own marker `data/.dlg_schema_v1`, so the
  feature works either way. Moving it keeps the CHIM package channel honest.
* `LRG_ACT_TOPIC` — optional: `lib/lrg_dialogue.php` defines it defensively.
* **Ask for lane B:** add `sg=<five Speech globals, csv>` to `lrg_dlg ev=facts` (it is already in
  PROTOCOL §10.2 and already read here). Without it a free-conversation check on an NPC no session has ever
  been opened on falls back to `checks.fallback_thresholds` and logs `(fallback)`.

### 8.3 The paid-intimacy half the INTIMACY lane owns

`lrgDlgPrice()` is ready and tested. What still has to meet it:

1. `BeginIntimacy`'s `parameters_json` gains an `amount` string ⇒ `LRG_ACTIONS_VERSION` 9 → **10**.
2. The post-gate drops an acceptance **below her floor** unless `interest.score >= interest_free_at`, and
   any acceptance the player **cannot afford** (`pgold` is already in the snapshot).
3. On pass, `ExtCmdLRG_StartIntimacy` gains **`pay=<n>`** (PROTOCOL §10.7).
4. `lrgAwardSceneAffinity` multiplies the gain by `paid_intimacy.relationship_gain_factor` (0.5) when the
   start carried `pay > 0`, and calls `lrgDlgPriceRemember($npc, $ask, $paid)`.
5. The stance words for the prompt are `lrgDlgPrice(...)['words']` — one number, never a formula.
6. MCM (lane B): `bPaidIntimacy` **1**, `fPriceMultiplier` **1.0**, both `[Intimacy]`. `bPaidIntimacy=0`
   must remove the whole mechanism: `lrgDlgPrice()` already answers `ok=false` when
   `dialogue.paid_intimacy.enabled` is false.

### 8.4 MCM keys this lane's code reads (lane B)

Everything Phase 2 reads server-side comes from `lrgConfig()['dialogue']`, so the MCM does **not** have to
carry it. The keys that must exist for the OWNER to calibrate, with this lane's in-code defaults:
`bFreeChecks` → `dialogue.checks.enabled` **true** · `iCheckBias` → `dialogue.checks.bias` **0** ·
`bCheckStats` → `dialogue.checks.stats` **false** · `bCheckHostility` → `dialogue.checks.hostility`
**false** · `bQuestAware` → `dialogue.quests.enabled` **true** · `iQuestLines` → `dialogue.quests.lines`
**3** · `bIntentOpen` → `dialogue.intent_open` **true** · `bQuestColour` → `dialogue.quest_colour`
**false** · `bPaidIntimacy` → `dialogue.paid_intimacy.enabled` **true** · `fPriceMultiplier` →
`dialogue.paid_intimacy.multiplier` **1.0**. A missing `settings.ini` key reads as 0/FALSE in game, so
each still needs its default line **there**; the server side is already safe because its defaults are in
code, not in a file that can be missing a key.

---

## 9. Deviations from the plan, in one place

| # | Deviation | Why |
|---|---|---|
| 1 | migrations `005_lrg_dialogue.sql` / `006_lrg_prompt_index.sql`, not 004/005 | the INTIMACY lane owns the `004_*` namespace this round (§0) |
| 2 | config defaults in code + a `dialogue` block, no `config/lrg_dialogue*.default.json` | the round prompt's allowed-file list (§0) |
| 3 | flow ids `d20`…`d41`, files `d*_*.php` | Phase 1 already owns 20–26 (§0) |
| 4 | `lib/lrg_core.php` / `lib/lrg_actions.php` not edited; both edits written out instead | another lane owns them (§8.1, §8.2) |
| 5 | only `lrgDlgPrice()` of §8, not the emission half | the INTIMACY lane owns paid intimacy (§8.3) |
| 6 | the vanilla masters' strings read from the uncompressed `Skyrim - Interface.bsa` | the plan's stated deviation; there are no loose `.STRINGS` here and every vanilla prompt would otherwise be `<strid N>` (§2.1) |
| 7 | 86 of 87 localized plugins ARE readable; only `Sanguine Symphony.esp` is not | measured, not assumed. D6 costs 1 plugin, not 82 (§2.2) |
| 8 | `<real_business>` injected only when a list is known | 70 wasted words per ordinary turn otherwise, and it broke Phase 1's flow-16 contract (§3.4) |
| 9 | `compound` excludes the Amulet-of-Articulation OR-branch (`amulet` is its own flag) | counting it would switch retry-suppression off on 176 rows instead of 18 (§2.3) |
| 10 | a new index column `topic_kind` | design §4.5's scoff-first rule is written in terms of "the shown FAILURE variant", which has no check condition of its own (§2.7) |
| 11 | `lrgPromptEscalate()` — the fail-safe merge | 7 norms have one LETHAL and one ordinary candidate; under-claiming crit there is the only way an advisory index could cause harm (§2.5) |
| 12 | `sg=` also accepted on `ev=facts`; a config fallback with a `(fallback)` log tag | a free-conversation check usually happens with no session at all (§4.1) |
| 13 | a per-request sequence in the turn cid | two turns in one second would otherwise share a cid and a park could never release (§3.3) |
| 14 | `lrgDlgIds()` instead of `lrgCsv()` for `q` / `qobj` | `questlog.id_quest` is case sensitive (§4.2) |
| 15 | retry suppression compares purse-to-purse, not purse-to-delta | the first version silently never suppressed anything (§3.3) |
| 16 | one assertion changed in `16_hook_wiring.php` | two post-gates are now the contract (§7.4) |
| 17 | `deploy_server.ps1`: the builder's `--out` made explicit, five anchors added | the staged builder would have written the index outside the plugin (§6.2) |
| 18 | a closed layer's overflow is a plain sentence, not a `(+N more)` group | "never bucketed" (design §4.3) (§3.4) |

---

## 10. Open `[U]` items this lane leaves, and what answers each

| `[U]` | Question | Answered by |
|---|---|---|
| P14a/b | which delivery route works, and how fast (a D1 failure makes a business turn 11–14 s) | probe **P14**. Both routes are implemented and carry the same `x`; d21 proves that offline |
| P13 | does the payload arrive intact, and how long is it | probe **P13**. The 1,600-char `e=` cap is the game's; the server re-assembles head ∪ tail (d20b) |
| X3 | does CHIM's `traditional_*` capture work at all | owner experiment. The module never depends on it: `ev=line` is its own ground truth |
| E13 | does a Papyrus `Game.IncrementStat` raise `OnTrackedStatsEvent` | why `bCheckStats` ships **off**; `stat=` is empty by default (d38) |
| T6b | does `Game.AdvanceSkill` outside dialogue grant Speech XP at Requiem's rate | new in-game test. `do=award` replicates `FavorDialogueScript`'s own call and reads the global live |
| T6 / E18 | does `wis` match the verdict the engine uses inside dialogue | in-game, 5 NPCs incl. a Foolhardy one. The check **refuses** rather than guessing when `wis` is absent |
| T6 / E21 | are the 9 PNAM-inverted checks really unpassable here | in-game. The index flags them; **nothing is changed** (D8b) |
| E15 | does `GetBribeSuccess` include affordability | in-game. Rule F (never click when `pg < N`) protects either way (d28) |
| — | `sg` on `ev=facts` | lane B (§8.2). Until then: `(fallback)` in the G6 line |
| — | the pre-existing PROTOCOL §5 database shape | forIntegrator (§7.3) |

---

## 11. The DLL question

**Nothing in this lane needed a DLL, an SKSE plugin build, an AS2/SWF compiler, an LZ4 module or any other
install.** The one thing that came close was the Creation Club string tables: the alternative would have
been an LZ4 decoder inside WSL. The Papyrus-only fallback was built instead — a **zlib-only** BSA reader
that raises `Unreadable` on an LZ4 entry — and it turned out to cost **one** plugin
(`Sanguine Symphony.esp`), not the 82 the plan expected, because the CC archives store their `.STRINGS`
entries uncompressed. What the LZ4 route would have added: that one plugin's prompts, which stay
engine-only (the engine still runs them perfectly; the glue simply never labels or ranks them, and they
show as `unindexed`, which costs one confirmation too many and never a wrong effect).

---
---

# SERVER FIX PASS (playtest 8 review round) - 2026-09-22

Lane: server only. Files I may touch: `glue/server/lorerim_glue/**`, `glue/tools/build_prompt_index.py`,
`glue/tools/test_*.php`, `glue/tools/flows/**`, `glue/PROTOCOL.md`. Nothing was deployed.

Baseline before any edit (staged copy in `$env:TEMP\lrg_test\glue`, live 43,280-line index copied in from
`/var/www/html/HerikaServer/ext/lorerim_glue/data/prompt_index.ndjson`):

| suite | baseline |
|---|---|
| `php -l` over every plugin + tool + scenario file | clean |
| `tools/test_dialogue.php` | 85 passed, 0 failed |
| `tools/test_prompt_index.php` | **67 passed, 0 failed** (the report's "71" is not reproducible - see below) |
| `tools/test_gates.php` | 296 passed, 0 failed |
| `tools/test_intent.php` | 183 passed, 0 failed |
| `tools/test_phrases.php` | 14 passed, 0 failed (hit rate 91.8 %) |
| `tools/test_scene_index.php` | ALL CHECKS PASSED |
| `tools/flows/run_flows.php --strict` | 63 scenarios, 61 passed, **2 FAILED** (the verifier's own `d91`/`d92` traces, staged only) |

The two failing scenarios are the reviewer's `d90_ownertrace.php` / `d92_recall.php` / `d94_money.php`,
which exist only in the staging copy and are the evidence for the two recognition defects below.


## A. What was fixed, defect by defect

Every line below is a change in `glue/server/lorerim_glue/**`, `glue/tools/test_*.php`,
`glue/tools/flows/**` or `glue/PROTOCOL.md`. Nothing else was touched and **nothing was deployed**.

### A1 [critical] `lrgCsv()` fatalled every Phase 2 message once the player owned a Speech perk

`preprocessing.php` handles `lrg_topics` / `lrg_dlg` at line 21 with **`lib/lrg_dialogue.php` alone
loaded**; `lib/lrg_actions.php` is required at line 36, which that branch never reaches. `lrgCsv()` lives
in `lrg_actions.php`, so the call at `lrg_dialogue.php:353` was a fatal on every message carrying a perk -
i.e. every message from the moment the player owns `REQ_Speech_SilverTongue`, the Persuasion perk or wears
an Amulet of Articulation (`PerkCsv()` returns `-` only for a player with none of the three). The topic
list never reached the server, no `<business>` block was ever built, `want=1` was never answered and
`terminate()` never ran, so the game got a broken reply. Dry-run did not mask it.

* **Fix**: the split is now INLINE in `lrgDlgFactsFrom()` - trim, lowercase, drop empties - behaviour
  identical to `lrgCsv()`, with a comment naming the require graph so it cannot be "tidied" back.
* **Second half of the same class of bug**: `lrgHideActions()` at `lrg_dialogue.php:1006` is the only other
  cross-lane call reachable from Phase 2 code. It is only ever reached from the four hooks that require
  `lrg_actions.php` first, but it is now `function_exists()`-guarded so moving a call can never resurrect
  the trap. A scripted audit (`comm` of the two files' function lists against every call site in
  `lrg_dialogue.php` / `lrg_speech.php` / `lrg_prompt_index.php`) found **no third case**;
  `lrgResolveRequestNpc()` was already guarded.
* **Regression**: `tools/test_dialogue.php` section 9(a). That file's own `require` **is** the broken
  graph - it loads `lrg_dialogue.php` and nothing else - and the first check asserts that
  (`!function_exists('lrgCsv')`), so the test cannot silently stop guarding the bug. Then a real
  `lrg_topics` with `perk=amulet`, one with a mixed-case csv, and one with `perk=-`.

### A2 [critical] the post-LLM gate never fired on the line CHIM really sends

`lrg_dialogue.php:1323` stripped `_` from the incoming code and compared against
`strtolower(LRG_ACT_TOPIC)`, which still contains one: `extcmdlrgselecttopic` vs `extcmdlrg_selecttopic`.
`ExtCmdLRG_SelectTopic` - the string the wire really carries - matched nothing, so the raw LLM line was
passed through **ungated**. The game then refused it for lack of `ok=1` (fail-closed: no wrong effect ever
happened) while the TTS transformer, which keys off the model's action NAME and *did* match, muted the
sentence. Net effect in production: **the NPC says nothing and nothing happens**, and every rail in
`lrgDlgGateItem()` - two-step confirmation, LETHAL hand-back, the freeze rule, afford, the check rails,
scoff-first, retry suppression - was unreachable.

* **Fix**: a new constant `LRG_DLG_GATE_NAMES = ['extcmdlrgselecttopic', 'takeupbusiness']` holds every
  spelling **already normalised**, and both the gate and `lrgDlgTransformer()` test against it. The three
  shapes that can arrive are the code name, CHIM's snake-cased display name `Take_Up_Business` and the
  plain display name.
* **Why the suite never caught it**: `tools/flows/dlg_adapter.php` built *both* the wire line and
  `$LAST_LLM_RESPONSE['action']` from `'TakeUpBusiness'`, so no scenario ever exercised the real shape.
  `fxDlgLlm()` now builds the wire line from the **code** name (`FX_DLG_ACT`) and the model's action from
  the **snake-cased display** name (new `FX_DLG_DISPLAY`), which is what production does. All 33 existing
  call sites inherit the fix; none passed the old 4th argument.
* **Regression**: `test_dialogue.php` section 9(b) asserts all five spellings normalise into
  `LRG_DLG_GATE_NAMES`, that `ExtCmdLRG_StartIntimacy` does **not**, and that a real code-name line with no
  turn record is dropped rather than passed through raw.
* **Related, same root**: CHIM stores the display name snake-cased, so the strict JSON enum offered
  `Take_Up_Business` while the prompt said "Use TakeUpBusiness". New `lrgDlgActionName()` reads the stored
  name back from `herikaGetActionCatalogRow(LRG_ACT_TOPIC)` (and ignores a row that does not normalise to
  our own name, so nothing outside can rename the action). All four model-facing sentences use it.

### A3 [critical] the probe key / section 3.3 probe grep - NOT MINE

Both are game-side (`LRG_MCM.psc`, `LRG_DlgProbe.psc`) or owner-notes items. Left to the game fixer and
the docs pass. The server emits probe lines through `lrgLog()` unchanged, and the working collector is
`grep -E "PROBE P[0-9]+[a-z]?" /var/www/html/HerikaServer/log/lorerim_glue.log` (the notes' `^.{0,40}`
regex cannot reach column 48, where `PROBE` actually starts).

### A4 [major] the `want=1` fast path applied none of the round's rails

`lrgDlgAnswerWant()` runs in preprocessing with no LLM and no turn record, so `lrgDlgGateItem()`'s rails
never saw it. Its only test was `class in {plain, service}` - and `lrgDlgClass()` runs the service-word
test **before** the commit test, so an entry can be `class=service` **and** `commit=true` at once, while
`crit` never reaches `class` at all. Measured: it clicked a commit entry on the first selection with no
confirmation, clicked walk-aways, clicked entries the index grades LETHAL, clicked while the game's own
payload said `crit=2`, and clicked for a speaker the payload said was in a scene (D-17).

* **Fix**, in this order, immediately after the entry is chosen: `scene=1` -> nothing; `crit=2` on the
  session **or** the entry -> nothing (the menu is the owner's); `crit>=1` / `commit` / `walkaway=1` /
  `class in {commit, meta}` -> nothing, logged as "consequential - intent mode never executes it without
  confirmation"; an unaffordable priced entry -> nothing. Each with its own log line, so the owner can see
  which rail spoke.

### A5 [major] the prompt index's layer tier bypassed the fail-safe risk merge

`lrgPromptLayerFor()` took `$recs[0]` raw. `lrgPromptEscalate()` was called only from `lrgPromptBest()`,
i.e. only on tier 2 (exact norm) - while the layer tier is **tier 1 and wins over it**. On the real 37,561-
row index, **all seven** ambiguous norms that carry both a LETHAL (`crit=2`) candidate and a non-lethal one
sit inside a known closed layer, so the highest-risk prompts in the load order were exactly the ones routed
through the broken tier. With `crit` flattened to 0 the gate's LETHAL hand-back does not fire,
`lrgDlgIsCommit()` loses its `crit>=1` hit and the back-out is not labelled `[leaving now ends this]`: an
arrest layer reads as ordinary business.

* **Fix**: one line - the layer tier now runs `lrgPromptEscalate($recs[0], $recs)` before stamping
  `matched=layer`. The index may over-claim risk (one confirmation too many); it may never under-claim it.
* **Same function, separate defect**: `lrgPromptEscalate()`'s null-coalesce was inoperative because `(int)`
  binds tighter than `??`, so every ambiguous prompt whose candidates did not all carry all three flags
  emitted PHP warnings **on a hot path** - into the same response body the D1 delivery route echoes the
  command line into. The coalesce is now inside the cast.
* **Regression**: `test_dialogue.php` section 9(d) merges a LETHAL candidate over a plain one and asserts
  `crit`, `twat` and the `walkaway` flag all come back at the strictest value.

### A6 [major] "everyone has a price" was implemented twice, and the documented one was dead

`lrgDlgPrice()` (`lib/lrg_speech.php:524`) was called only by the flow adapter and scenario `d41`;
`lrgDlgPriceRemember()` was called from nowhere. The live implementation is `lrgPriceFor()`
(`lib/lrg_core.php:835`, called on the live turn at `:1137`). They read **different config keys**
(`day_wage` vs `gold_per_day_of_wage`, `band_by_wealth` vs `bands_by_wealth`), applied money influence in
**opposite directions** (divide vs multiply), and only the live one honours the MCM `pm` / `paidok`. So
`d41`'s 15 green checks proved nothing about shipped behaviour.

* **Fix**: `lrgDlgPrice()`, `lrgDlgPriceWords()`, `lrgDlgPriceRemember()` and `lrgDlgWealthByGold()` are
  **deleted**, with a block comment at the deletion point saying why. `lrgDlgPriceDefaults()` shrinks to
  the two keys still needed (the wage anchor), and the new `lrgDlgWage()` / `lrgDlgPriceRound()` **prefer
  the live key** `paid_intimacy.gold_per_day_of_wage`, so one number still rescales the whole economy and
  the two can never disagree again. `lrgDlgBribePrice()` reads them.
* `d41` is rewritten against `lrgPriceFor()` (19 checks): one implementation only (it asserts
  `!function_exists('lrgDlgPrice')`), the wage anchor is shared with the bribe floor, a commoner is days of
  wage and a jarl is orders of magnitude more and still **for sale**, a Vigilant produces no figure at all,
  interest makes it free with only a token, and - the point of the whole defect - **the MCM knobs `pm` and
  `paidok` really move it**, which the deleted twin never read.
* `dlg_adapter.php`'s `dlg_price` capability now points at `lrgPriceFor` + `lrgDlgWage`.
* `PROTOCOL.md` 10.7 and 10.9 corrected to name `lrgPriceFor()` (`lib/lrg_core.php`).

### A7 [major] ordinary money talk was read as paying for sex

`lrgIntentMoney()` had **no test for what is being bought**. Traced against the deployed plugin:
"Here is two hundred septims for the room and the food." to an innkeeper produced "That is at or above
what Brenna would take ... choose BeginIntimacy with amount 200 in the same reply"; "I will give you thirty
gold for the sword." produced "That is below what she would take"; "How much do you want for it?" to a
jarl produced "name a figure at or above 105000 septims". All at affinity 0.

* **Fix**: a new `lrgIntentGoodsTalk()` pre-test, and `lrgIntentMoney()` returns `null` when it fires and
  **no live price quote from her exists** (once she has named a figure, the subject is already hers).
  Three signals, all of them things you can only say about a THING: (1) a trade word - buy / sell / trade /
  barter / wares / shop / stock / merchandise / purchase / for sale; (2) `for the <goods noun>` over a list
  of about 60 nouns (room, board, meal, sword, horse, carriage, potion, lot, ...); (3) an OBJECT frame -
  `do you want|would you take|do you charge|will you take ... for it|that|this|the lot`, or a figure tied
  to an object pronoun ("thirty for it").
* **What is deliberately NOT caught**, because it is genuinely about her: "I'll pay you well for it" and
  "can I pay you for it" (no figure, no asking verb), "what's your price", "how much for a night",
  "what would it take", and any haggling after she has quoted a number.
* **The one existing expectation that changed**: `test_intent.php` had
  `"I'll pay for the room, not for you"` marked as an "acceptable miss" (offer/0). It is now correctly
  refused. `test_phrases.php` row **M4** changed from "what do you charge for that" to "what do you
  charge" - the trailing object is exactly the ambiguity this fix resolves against, and the four other
  askprice rows (M1/M2/M3/M5) already cover the feature.
* **New rails, in both suites**: five rows in `test_intent.php` and five in `test_phrases.php` (the money
  group is a MUST group), all three traced sentences among them. `money` is now **90/90**.

### A8 [major] two of the owner's own three check examples produced no check at all

Recognition rested on ~30 literal phrases. Measured before: the merchant haggle -> `null`, the lie to a
guard -> `null`, and recall over 18 natural phrasings **6/18**. Three separate causes:

1. **The negation blocker was backwards for this feature.** Phase 1 blocks negated utterances because
   "don't get naked yet" is not a request. But a LIE is usually a negative statement - "That bounty is not
   mine", "I have never set foot in Riften" - and a threat often is too ("You do not want me as an
   enemy"). Blocking every `never` / `do not` / `don't` threw away exactly the sentences the feature is
   for. Only the HYPOTHETICAL framings (`should I`, `shall I`, `what if`, `if I were to`, `could I`,
   `suppose I`) and a quoted utterance are refused now.
2. **There was no haggling kind**, so "knock the price down" matched nothing. `barter` is new: a
   **persuade-type** check (the index builder's census found exactly three ENGINE check kinds in this load
   order and no mod adds a fourth, so haggling can only ever be a free-conversation check). It maps to
   `Persuasions` for `bCheckStats`, a real persuade entry on the list still wins over it, and it has its
   own `<what_just_happened>` wording that never promises a number a shop cannot honour.
3. **The bribe pattern wanted digits**, so "Here is two hundred septims" missed. It now reuses
   `lrgIntentAmount()`, which understands spelled-out sums - but only inside a giving frame, so a sum in a
   sentence ("I found fifty gold in the cave") is still not a bribe.

* **The collision that had to be resolved at the same time**: coin offered to an NPC is either "pay for
  sex" or "bribe them to do X". The discriminator is the **purpose**: a purpose phrase (look the other
  way, forget my face, let me through, drop the charges, for your silence, no questions) is a bribe check
  and wins even on a money turn; without one, a recognised money turn (`offer` / `askprice` / `haggle`,
  conf=high) belongs to "everyone has a price" and no bribe check runs.
* **Result**: recall is now **18 of 18**, and all three of the owner's examples produce a real check the
  server decides (`barter`/pass, `deceive`/fail at SpeechVeryHard, `intimidate`/pass on the engine's own
  `wis` verdict), each with the live Speech global, none of them leaking a threshold into the prompt.
* **Regression**: a new shipped scenario file `tools/flows/scenarios/d42_checknet.php` - **d42** (the
  owner's three examples, 18 checks) and **d43** (all 18 phrasings, plus three non-attempts, plus the
  price/bribe collision, 5 checks). They are written in the owner's words, not the patterns' words, so
  widening a pattern to fit one sentence shows up here as another sentence no longer covered.
* **The dead `lrgRecogniseIntent` mapping**: kept, documented as a forward-compatible seam, and explicitly
  **not** wired to the money kinds - mapping `haggle` to `barter` would have run a Speech check in the
  middle of a paid-intimacy negotiation, which is the opposite of design decision D5.

### A9 [major] seven MCM controls changed nothing, and `bFreeChecks` was half-wired the dangerous way

`bCheckHostility`, `iCheckBias` and `iQuestLines` were read by no script at all; `iBranchInput`, `bRewalk`,
`iRewalkDepth` and `bIntentOpen` were read and then used only in a diagnostics log line. All seven are
server-side knobs with no wire carrier. `iCheckBias` and the free-checks master toggle were asked for **by
name** in OWNER_ADDENDA 5c. Worst of them: `bFreeChecks` was honoured game-side only, so with it OFF the
server still ran the check, still injected `<what_just_happened>` stating the outcome as fact and still
emitted `do=award`, which the game discarded - the owner got the narration of a check they had switched
off, with no XP and no gold.

* **Fix (server half; the game half sends them)**: six additive keys on `lrg_dlg ev=facts`, also accepted
  on `lrg_topics` because both go through `lrgDlgFactsFrom()`. `chk=<bFreeChecks><bCheckHostility>`,
  `bias`, `ql`, `io`, `bi`, `rw`. All optional, all clamped; an absent or malformed key keeps the server's
  own config default, so script 310 is byte-for-byte unchanged.
* Read back with the new `lrgDlgMcm(key, default, npc)`: newest value this request wins (MCM is global, the
  facts row is per NPC), then that NPC's stored facts, then the config default.
* Honoured at: `lrgDlgCheck()` (returns null **before anything is computed** when `bFreeChecks` is off),
  the difficulty band (`bias`), the hostility consequence, `lrgDlgQuestBlock()` (`ql`, with **0 = no block
  at all**), `lrgDlgMaybeOpen()` (`io`), `lrgDlgContinuationOk()` (`rw`, 0 = never walk a second layer),
  and the branching rule (`bi=2` -> a closed layer is handed back with `do=show` and never clicked, in the
  post-gate **and** in the `want=1` fast path).
* The `dlg turn` log line gains `mcm=<k>:<v>,...` **only when the game really sent them** - its absence is
  itself the answer to "is my MCM reaching the server at all?".
* `PROTOCOL.md` **10.12** is the new contract table. `test_dialogue.php` 9(c2) covers arrival, clamping,
  the default-when-absent rule and that `bFreeChecks=0` really silences the check.

### A10 [major] PROTOCOL's own additivity claim was false (server half)

A game script 400 against a rolled-back server 0.3.1 does **not** get its messages dropped as an unknown
type. They fall into Phase 1's `strncmp($lrgType,'lrg_',4)===0` branch, reach `lrgHandleGameMessage()`,
match nothing, return `'pass'` - and `main.php`'s `if (!in_array($gameRequest[0], $fast_commands))` is then
true, so **the MAIN LLM semaphore is taken**, `processor/request.php` logs "Request cue is empty!" and
falls back to `TEMPLATE_DIALOG`. One LLM call plus TTS per dialogue open and per subtitle line, with the
raw wire payload read aloud as the player's words.

* **Fix (my half)**: `PROTOCOL.md` section 0 now states this as a **SHIPPING rule** in the shape of
  0.3.1's own `w2` exception - the two halves of 0.4 ship together, server first - names the exact
  mechanism, and names the game half's `bDlgWire` escape hatch (`[Dialogue]` in `settings.ini`, default 1,
  default line mandatory because a missing ini key reads as 0) so a rollback is one MCM toggle.
* The game half of this (the setting, the toggle, the early returns in `SendTopics` / `SendOpen` /
  `SendClosed` / `SendUnhide` / `SendResult` / `SendFacts` / `HarvestLine`) belongs to the game fixer.

### A11 [minor] a CHIM playthrough switch made Phase 2 permanently dead

`lrgDlgEnsureSchema()` returned early on a marker **file** while switching playthrough runs
`DROP SCHEMA IF EXISTS public CASCADE` (`HerikaServer/lib/playthrough_schema.php`) and
`lib/postgresql.class.php` pins `search_path` to `public` on connect. Live DB read confirms: `public` holds
`lrg_dialogue`, `lrg_prompt`, `lrg_prompt_layer` plus the Phase 1 tables, while `chim_profile_default`
holds only the Phase 1 five - so restoring a cloned profile schema removes the Phase 2 tables and the
surviving marker stops them ever being recreated. Every state read then throws (caught and logged) and
Phase 2 goes silent, index included.

* **Fix**: new `lrgTableExists()` in `lib/lrg_core.php` - `SELECT to_regclass('<t>') AS t`, answered once
  per table per request, and treating an unknown failure as "present" so a broken probe can never make a
  migration run on every request. Both ensures now require the marker **and** the table:
  `lrgEnsureSchema()` probes `lrg_npc_state` (Phase 1 shares the pattern), `lrgDlgEnsureSchema()` probes
  `lrg_dialogue`.
* When the tables had to be recreated the log says so in the owner's own words and names the one command
  that puts the 37k-row index back, because the tables come back **empty** and only the loader can refill
  them: "re-load it with `php ext/lorerim_glue/lib/lrg_prompt_index.php load` ... until then every prompt
  shows as unindexed - one confirmation too many, never a wrong effect."
* `PROTOCOL.md` section 5 documents the one new name-free query shape and why it exists; the flow harness's
  in-memory DB serves it (it answered `unhandled` before, which failed the run).
* **Left open, deliberately**: moving `lrg_prompt` / `lrg_prompt_layer` to a schema of their own. It is the
  right answer - the index is 30 MB of LOAD-ORDER data, not playthrough data, and it is cloned on every
  playthrough save - but it changes `migrations/006`, the loader and the deploy script together, and this
  was a fix pass. The probe makes the failure loud and recoverable, which is what the round needed.

### A12 [minor] the rest

| defect | fix |
|---|---|
| dead ternary at `lrg_dialogue.php:382` (both branches identical) | replaced with `'scene' => (int) ($kv['scene'] ?? 0)`. `sg=` still reaches the facts through `lrgDlgFactsFrom()`, which is where it belongs |
| `res=` documented as a contract key that no Papyrus code reads | `PROTOCOL.md` 10.4 now says in so many words that it is **diagnostic only**, lists the 17 keys `ParamGet` really asks for, and says not to write a consumer. Dropping it from a fixed key order would have cost more than it saves |
| prompt / strict-enum name disagreement (`TakeUpBusiness` vs `Take_Up_Business`) | `lrgDlgActionName()`, see A2 |
| 54 check-tagged lines graded as ordinary entries | new `lrgDlgKindFromTag()`: the visible `(Persuade)` / `(Intimidate)` / `(Bribe N gold)` tag is a **second source of truth** when the index has no kind. Applied where the row is built (so the label, the check rails, scoff-first and `kind=` on the wire are all right) **and** in `lrgDlgClass()` (so a direct call answers the same way). Restricted to the three kinds the engine really implements here, because an invented kind would reach `kind=` and `<what_just_happened>` |
| `test_prompt_index.php` reports 67, not the 71 the build report claims | re-run on an index identical to the builder's (43,280 lines / 37,561 rows, md5 `0f7a07b241f87a329b36360f524ab1fc`, copied from the deployed plugin): **67 passed, 0 failed, exit 0**. The "71" is not reproducible. `--db` was NOT re-run: it writes the index into the live Postgres, and this pass does not deploy |

## B. The three build reports re-stated against the tree (release-gate item)

| report | claim | the tree, 2026-09-22 |
|---|---|---|
| INTIMACY | `compile.ps1` fails at `LRG_Dialogue.psc(651,13) IsDriving is not a function` | **stale**. `IsDriving()` is declared at `LRG_Dialogue.psc:548` and called at `:689`; all nine `.pex` files carry a timestamp **newer than every `.psc`**, so a full compile has succeeded since |
| SERVER | "No deploy was run" | **wrong**. `/var/www/html/HerikaServer/ext/lorerim_glue` holds the full 0.4.0 plugin - `manifest.json` 0.4.0, `lib/lrg_dialogue.php` 98 KB, `lib/lrg_prompt_index.php`, `lib/lrg_speech.php`, the Phase 2 `preprocessing.php` block, the `external_fast_commands` merge - every file mtime **2026-09-22 00:44**, `data/.actions_v10` written 00:40 and `data/prompt_index.ndjson` (31.5 MB, 43,280 lines) 00:45. The new code has also **run live** |
| SERVER | `lrg_actions.php`'s two D-12 edits and `lrg_core.php`'s constants are NOT made | **all four are present**: `lrg_actions.php:1436` (the `SelectTopic` pass-through in the post-gate) and `:668` (the `funcret` exclusion, D-12b); `lrg_core.php:13` `LRG_VERSION '0.4.0'`; `lrg_actions.php:25` `LRG_ACTIONS_VERSION 10` with the `amount` slot and `parameter_template` at `:97` |

**Nothing in the release gate should read those three NOT DONE lists.** The tree is the record.

## C. Test results

Clean-room run of **only what the source tree ships** (the reviewer's `d90` / `d92` / `d94` traces removed,
since they are staging-only and `d90` reads a machine-local fixture file):

| suite | before | after |
|---|---|---|
| `php -l`, 9 plugin + 7 tool + 37 scenario files | clean | clean |
| `tools/test_dialogue.php` | 85 / 0 | **106 / 0** (+21: the whole of new section 9) |
| `tools/test_prompt_index.php` | 67 / 0 | 67 / 0 |
| `tools/test_gates.php` | 296 / 0 | 296 / 0 |
| `tools/test_intent.php` | 183 / 0 | **190 / 0** (+7 money rails) |
| `tools/test_phrases.php` | 14 / 0, money 75/75, 91.8 % | **14 / 0, money 90/90, 91.9 %** |
| `tools/test_scene_index.php` | pass | pass |
| `tools/flows/run_flows.php --strict` | 58 shipped scenarios (2 of the staged traces FAILED) | **60 scenarios, 60 passed, 0 FAILED, 0 pending, 924 checks, 0 warnings** |

With the reviewer's traces staged as well: **65 scenarios, 65 passed, 938 checks, 0 warnings** - including
`d91` (the owner's three examples: 9 ok, was 5 ok / 4 fail) and `d92` (recall **18/18**, was 6/18).

**Nothing was deployed.** `tools/deploy_server.ps1` was not run and the live
`/var/www/html/HerikaServer/ext/lorerim_glue` still holds the 00:44 build.

## D. Files changed in this pass

`glue/server/lorerim_glue/lib/lrg_dialogue.php` - `lib/lrg_core.php` - `lib/lrg_speech.php` -
`lib/lrg_prompt_index.php` - `lib/lrg_intent.php` - `glue/PROTOCOL.md` -
`glue/tools/test_dialogue.php` - `tools/test_intent.php` - `tools/test_phrases.php` -
`tools/flows/harness.php` - `tools/flows/dlg_adapter.php` - `tools/flows/scenarios/d40_quests.php` -
**new** `tools/flows/scenarios/d42_checknet.php`.
`tools/build_prompt_index.py` needed no change (`lrgPromptNorm()`'s twin is untouched).

## E. Left for other lanes (named here so the release gate can find them)

1. **GAME**: `LRG_OStim.psc` has no `dlg.IsSessionOpen()` test in `StartBlockedReason` or the clothing
   gate (design 7.2 item 3). `PROTOCOL.md` 1.6 already carries the reason string
   `a conversation is in progress`, waiting for the edit. Same blind spot in `TickControls` at `:970`.
2. **GAME**: `bDlgWire` - the setting, the `settings.ini` default line, the `config.json` toggle,
   `ReadSettings()` and the seven early returns (A10). PROTOCOL section 0 is written for it.
3. **GAME**: the emission half of the 10.12 MCM keys. The server accepts them today and falls back to its
   own defaults until they arrive, so this can land in any order. Until it lands the seven controls still
   do nothing, and the alternative the defect offers - removing them from `config.json` - is the game
   lane's call, not mine.
4. **GAME**: re-register `iKeyProbe` on an MCM change (`LRG_MCM.OnSettingChange` -> `m.GetProbe().Maintenance()`).
5. **DOCS**: the `PROBE` grep of section 3.3, both `WOULD CLICK` shapes, probe press 16, press 23 needing
   two presses, the reload resetting the cycle, the `check npc=... where=free ...` line's location, the
   `dlg self-test v400` line for the "read OFF" acceptance test, and the one-extra-LLM-turn cost of a first
   business contact.
6. **SCHEMA, after this round**: move `lrg_prompt` / `lrg_prompt_layer` out of `public` (A11).

## F. The DLL question

**Nothing in this pass needed a DLL, an SKSE plugin build, an AS2/SWF compiler or any other install.**
Every fix is PHP, Markdown or a PHP test; the one thing that touches the game half's shape (the action's
display name) is read back from CHIM's own catalog at runtime rather than compiled anywhere.
