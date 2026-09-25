# v0.2 flow tests - FLOWTESTS builder report (2026-09-21)

Status: DONE. Last full run (against the plugin as it stood at the end of the round: `LRG_VERSION 0.2.0`, actions v7, all contract
capabilities present): **23 scenarios, 466 checks, 0 failed, 0 pending, 0 warnings, 7 s** (scene index cached; about 13 s on a cold stage).
`--strict` also exits 0. Mutation check: **15 of 15 deliberate breaks detected**.

Owner of: new files under `glue/tools/flows/` only. Nothing else was edited; nothing was written to MO2, to the distro's web root or to CHIM.

## What was built
An offline, plugin-level scenario runner (no game, no HerikaServer, no LLM), written against `glue/PROTOCOL.md` v2 (sections 5, 6, 7.2, 7.3).
| file | role |
|---|---|
| `tools/flows/run_flows.php` | entry point; runs every scenario, then the child-process variants; exit 1 on any FAIL (with `--strict` also on pending / warnings); refuses to run inside `/var/www` |
| `tools/flows/run_flows.ps1` | one command from Windows: stage to `%TEMP%\lrg_test_flows`, lint every flow file, run |
| `tools/flows/harness.php` | knows nothing about the plugin: in-memory `FxDb` (exactly the query shapes PROTOCOL 5 allows, anything else is reported), CHIM stubs that record (`terminate`, `chimRegisterPromptInjection`, `logEvent`, the three `herikaActionCatalog*` functions, `RelationshipManager`), check bookkeeping, PHP warning collector |
| `tools/flows/adapter.php` | the ONE file that knows the plugin: function names, state keys, action codes, text markers, the "play one turn" drivers (library level per PROTOCOL 7.3 and hook level in CHIM's real order), the cast, the known-pending list |
| `tools/flows/scenarios/NN_*.php` | 20 files, 23 scenarios. They never call a plugin function by name and contain no scene id, no position word and no line of dialogue: scenes and words are discovered from the installed index and the plugin's own config at run time |
| `tools/flows/mutation_check.ps1` | breaks the plugin on purpose in a throw-away copy (15 mutations, one rail each) and expects the matching scenario to fail |

Check levels: **FAIL** = the contract says otherwise. **warn** = a reasonable expectation beyond the letter of the contract (prose markers, mostly). **pend** = cannot be judged: a contract function is not delivered (auto-detected capabilities: clock, dice, handle, prerequest, memory, interest, index_v3, actions_v7, modes), the installed packs lack the data, or the scenario is listed in `fxKnownPending()` with a dated reason. A known-pending scenario that passes prints a note asking to be removed. Verified degradation: against a read-only copy of the DEPLOYED v0.1.1 plugin (actions v6, none of the nine capabilities) the same suite ends with 0 FAIL, 22 scenarios pending, 1 passed, no fatal error: v1 behaviour is still checked, everything [NEW] reads "pend" with the missing function named.

## Run
```
powershell -NoProfile -ExecutionPolicy Bypass -File "<project>\glue\tools\flows\run_flows.ps1"
powershell -NoProfile -ExecutionPolicy Bypass -File "<project>\glue\tools\flows\run_flows.ps1" "--only=03,07 --trace"
powershell -NoProfile -ExecutionPolicy Bypass -File "<project>\glue\tools\flows\run_flows.ps1" "--strict"
powershell -NoProfile -ExecutionPolicy Bypass -File "<project>\glue\tools\flows\mutation_check.ps1"
```
or by hand, the brief's way: stage with robocopy, then `wsl -d DwemerAI4Skyrim3 --cd / -- php /mnt/c/Users/Jordan/AppData/Local/Temp/lrg_test/tools/flows/run_flows.php`.
Options: `--only=ids`, `--trace` (every played step + the volatile guidance), `--strict`, `--list`, `--no-variants`, `--allow-empty-index`.

## Scenarios (R9 numbering in brackets)
| id | what it proves |
|---|---|
| 00 | the seams themselves: `lrgNow` / `LRG_TEST_NOW`, `lrgRoll` / `LRG_TEST_ROLL`, time travel (+100000 s, fresh snapshot is fresh), state messages stored under the payload's `npc=` (HERIKA_NAME is set to a decoy for them), `lrg_log`, `lrgMemGet/Set` semantics, only allowed DB shapes |
| 01 (1) | stranger: mode closed, word curious / indifferent, nothing offered, boundaries block, no explicit permission, no invitation text, hallucinated BeginIntimacy / ChangeClothing / SuggestPrivacy dropped and not recorded, pressure changes nothing, a stranger in public is closed (never public), her tick is dropped |
| 02 (2) | interested + witnesses: mode public, ONLY SuggestPrivacy, `places` from facts (market + nhome -> home, quiet; inn + rented bed -> room, quiet; own inn -> all three; v1 snapshot -> quiet only), the place name reaches the guidance, x follows the MCM level, invite recorded `{pending, place, loc, at, expires = at + ttl}`, server-only (wire `[]`), unbacked place never recorded, display name + JSON form, still public later = still public, gone after the ttl |
| 03 (3) | follow-through: mode follow on speech and on an admitted tick (dice irrelevant), BeginIntimacy + ChangeClothing only, explicit permission at x=2, x=0 wins, missing x -> config level, gentle-tier start param in the exact shape, invite -> followed, second tick dropped; after ttl -> ordinary private; ttl - 1 s still follow; no longer willing -> closed + invite removed; `ev=end` removes it |
| 04 (4) | not interested and alone: nothing; boundary score -1 / 0; `lrgInterest` thresholds -25 / 0 / 20, willing, may_initiate; Speech bonus steps; renown by sway; cap 15; missing `pspeech` = no bonus; bonus really opens the gate; non-adult never willing |
| 05 (5) | adult=0, adult key missing, adult=0 with the best relationship data, witkid=1, witkid missing: in 9 request types nothing offered, both guidance strings `''`, x null, every glue line dropped (code and display name), tick dropped, nothing recorded, event log empty; a live scene row with an unconfirmed adult gives no notes and no actions; a bystander sees nothing of someone else's scene |
| 06 (6) | married-refuse (closed alone and in public, tick dropped, spouse of the player is fine), married-secret (+20 effective threshold, score uses it, companion blocks -> public, strict limits on the wire), Vigilant (silent everywhere), Jarl (stranger closed; trusted + companion -> public / SuggestPrivacy only, also on her own tick; alone offered; `folok=0` on the wire), relaxed profile tolerates a companion |
| 07 (7) | ladder: gentle start, one neutral event-log line, ceiling reached + 0 / + 1, no option above it, above-ceiling request dropped, lead tick = Talk + ChangeIntimacy + ChangeClothing with actions switched on (both hook orders), ordinary scene talk = speech only, after the pace time exactly + 1, she takes it, `_maxtier` / `_tier_since` move, the next tick may not climb again, same-tier change keeps the clock, going back down keeps `_maxtier`, end closes, new scene new ladder |
| 08 (8) | position by name: discovered words -> one `do=goto` at or below the ceiling, sentence + JSON + display name, server cid and `npc=`, `warp=1` only without a route, P-key and id echo, above the ceiling -> no command + too-soon rule (and the same word works at the top), nonsense dropped |
| 09 (9) | every verb -> exact param: start (incl. bed in reach: `furn` / `fscene` pair, gentle), P-key, faster, slower, hold, release, stop, climax (dropped below sensual), pull out, wind down (`scene;warp;linger`), lead npc / player / auto (auto dropped before tier 4), speed n, furniture from `furn_options` with a non-empty scene (and dropped when not in reach), clothing who = npc / both / player x part = all / body / head / hands / feet, in a scene and outside (limits attached); every item key the notes offer resolves to a command |
| 10 (10) | stop in 7 wordings, 7 ties (next to faster, a P-key, wind down, hold, climax, lead), display name, lead tick, mid wind-down, top tier, climax held + auto mode, mid transition, adopted thread; wind-down has its own shape and is never a stop; `ev=winddown` keeps the row active |
| 11a-d (11) | kill switch (child process with a config override): silent in speech, public, tick, lead, scene talk; `lrg_log` still written. SHARMAT: the same through the REAL hook files (11b) and at library level (11d). 11c: `initiative.enabled=false` drops every tick, speech-driven follow-through still works |
| 12 (12) | wrong actor, unknown glue code, ChangeIntimacy outside a scene, SuggestPrivacy in private, forged `ok=1;cid=..` param (rebuilt when offered, dropped when not), mixed answer keeps foreign lines byte for byte and in order, late line after another NPC's turn, no prepared turn, non-speech request types, in-scene BeginIntimacy / SuggestPrivacy, bystander cannot touch the scene |
| 13 (13) | catalog rows as handed to CHIM: four rows, display names, `followup.enabled=false` on ALL, automatic confirmation, placeholder suppressed, bridge script, `request_types_any` per 6.5, no explicit vocabulary in descriptions; cues for `lrg_scenetalk` / `lrg_initiative` exist, token cap set, ONE short sentence + `max_chars` on scene, lead, tick and follow turns, an ordinary closed turn is not squeezed; glue request types are not fast commands; `last_result` recorded from the funcret via the echoed `npc=`, told once |
| 14, 14b (14) | R10: with the announce die at 1 every sensual / sexual option carries the say-it mark and every gentle one the silent mark; the rule text (one short sentence naming the choice; how to be silent = empty `message`); with the die at 100 no say-it mark at all; the same roll splits a vocal and a quiet character; `roll <= chance`; lead tick same marks; **a gentle pick with an empty message yields exactly the goto line and nothing else; an empty answer with no action is a valid turn; every kind of scene turn allows silence** (the owner's "they don't always have to say something"); 14b: talk style `never` never announces, even at die 1 |
| 15 (R2) | tick admission: drawn + private admitted, mode / flags / `initiative_at`, only Talk + the mode's actions, x available, cooldown, die at chance / chance + 1 for eager and slow, public tick -> SuggestPrivacy only and recorded, not drawn dropped, every hard gate incl. missing keys, stale / no snapshot, exact text, scene running, nothing on the event log |
| 16 | the same story through the real hook files in CHIM order: preprocessing terminates / passes, injections in the right slots, exactly one post-LLM filter, invite stripped from the wire, tick admitted -> prerequest switches actions on -> functions prunes, lead tick, expressive idle suppressed, funcret recorded; **silent turns (non-adult, `never`, no snapshot) leave CHIM's refusal filter and token budget untouched - R5 is not a global mode** |
| 17 (R8) | stale snapshot -> silent; stray lead ticks while `leader=player`, `auto=1`, `wd=1` are harmless while the player can still direct and stop; `_climaxes` counted, not logged; clothing state told; `sde` arrives as words in the STABLE block, is information and not permission, and changes nothing for an unconfirmed adult |
| 99 | look back over the whole run: all 26 scene ids that were offered or put on the wire are in the index, not excluded, not on the hard list, two-person, not transitions |

## Reconciliations made during the round (all in `adapter.php`)
- `$turn['places']` is a map `key => words for the LLM`; PROTOCOL 6.2 says "subset of home, room, quiet". The adapter accepts a list or a map and compares KEYS.
- R10 marks are `[say it]` / `[silent]` right after the option label, options on a line of their own; `fxOptionMarks()` reads the tail after the verbatim label (labels contain `;`) and also understands a grouped layout.
- "Other items: a; b (explanation); ..." is the layout `fxOfferedItemKeys()` parses for the prompt-vs-resolver agreement check.
- Token cap: the contract names no number; BEHAVIOUR uses 140 / 200 from `context_pre.php`. The test demands a cap (must) and <= 250 (warn).
- One scenario was listed known-pending for about an hour (library-level SHARMAT guard); BEHAVIOUR added the guard, the list is empty again.

## Findings for the integrator
1. **Lead perspective (playtest item).** PROTOCOL 6.5 lists the items "you lead / i lead / auto"; the notes offer "<npc> leads" / "player leads"; the resolver reads "you lead" written by the NPC as who=player. If the PLAYER says "you lead" and the model echoes those words as the item, the result is the opposite of what the player meant. The tests pin the current mapping (09) but cannot judge the model's habit; watch `do=lead;who=` in the log during the playtest.
2. `OPENAI_FILTER_DISABLED` is set by the plugin on every NON-silent glue turn, `closed` included (documented reason: in-character refusals trip CHIM's word-scoring filter). Scenario 16 pins that silent turns never touch it. The owner should know this switch exists when reading the R5 audit.
3. PROTOCOL 6.2 wording for `places` should say "map keyed by home / room / quiet" to match the code.
4. The flows play many requests in ONE PHP process; production is one process per request. Any future per-request `static` cache in the plugin (memory, snapshot, turn) would pass in production and fail here - that is intended, keep statics for config and index only. (`lrgPrepareTurn()` recognises a second pass by a request hash; the drivers unset `LRG_TURN` per request, so dice are rolled per turn as in production.)
5. Do not run `test_gates.php` and the flows at the same time in the same staged folder: both write `config/lrg_config.json` for their kill-switch check. `run_flows.ps1` therefore stages to `%TEMP%\lrg_test_flows`.
6. The scene scenarios need the real index (`/mnt/f` mounted). An empty index is a FAIL with the reason printed; `--allow-empty-index` downgrades it to pending.
7. `mutation_check.ps1` patterns are tied to exact source lines on purpose. After BEHAVIOUR edits those lines it prints "MUTATION DID NOT APPLY" for the stale ones; update the sed pattern, do not delete the mutation.

## Not done
- No check of what the LLM actually says (no LLM here by design): style rules are asserted as markers in the guidance only.
- Game-side behaviour (Papyrus): dedupe, furniture ref search, wind-down timer, cold exemption - out of reach of a server-level harness.
- `invitation.lead_the_way` (default off) is not exercised.
