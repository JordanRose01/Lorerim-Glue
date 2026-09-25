# PT9 verify - the latency round, checked against the logs, the code and the live CHIM install

Read-only verification, 2026-09-22. Inputs: `research/pt9-latency.md` and `research/pt9-latency-fix.md`,
both read in full. Nothing in the plugin, in CHIM or in the deployed copy was changed. Probe scripts are
throwaway, in `C:\Users\Jordan\AppData\Local\Temp\lrg_test\` (`v3_derive.py`, `v4_tts.py`, `v18_rails.py`,
`v20_bins.py`, `v22_share.py`, `v6_run.sh`, `v7_prompt.sh`, `v9_dump.sh`, `v10_actdiff.sh`, `v13-v19_*.sh`).

**VERDICT: FIX FIRST.** The engineering is sound and I could not make it change behaviour anywhere - but
three of the report's load-bearing statements do not survive checking: the headline saving is about four
times too large, the quoted flow-test result was produced by the tree *without* this round's changes, and
an undocumented non-latency change ships inside this round's file.

---

## 1. What checks out

### 1.1 The chain numbers are real (re-derived independently from the raw logs)

I parsed `log/chim.log` and the 132 `soundcache/*.txt` sidecars myself, without reusing any of the round's
scripts. Re-derived vs. reported:

| quantity | pt9-latency.md | re-derived | |
|---|---|---|---|
| player lines in the window | 71 | **71** | ok |
| `streamv2` total, sum over player turns | 603 s | **603.4 s** | ok |
| ... p90 / max | 11.88 s / 53.6 s | **11.83 s / 53.64 s** | ok |
| ... median | 7.73 s | 7.54 s | small gap, see D12 note |
| MAIN lock wait med/p90/max/sum | 0.007 / 0.105 / 12.87 / 34 s | **0.007 / 0.105 / 12.87 / 33.5 s** | ok |
| TTS per sentence med / p90 / n | 3.41 s / 6.94 s / 132 | **3.411 s / 6.937 s / 132** | ok |
| TTS worst / second worst | 97.6 s / 47.6 s | **97.6 s / 47.57 s** | ok |
| ffmpeg fallback med / p90 | 0.055 / 0.059 s | **0.0551 / 0.0586 s** | ok |
| TTS realtime factor | 1.26x | **1.254x** (call 3.411 s / audio 2.720 s); audio.cpp's own `X-AudioCPP-RTF` median **1.22** | ok |
| LLM whole reply, TTS removed, median | 1.86 s | **1.81 s** | ok |
| `lrg_scenetalk` n / med / p90 / max / sum | 10 / 7.9 / 18.9 / 22.6 / 112.8 s | **10 / 7.92 / 18.97 / 22.81 / 112.8 s** | ok |
| `lrg_initiative` n / spoke / sum | 12 / 3 / 20.1 s | **12 / 3 / 20.1 s** | ok |
| `funcret` / `lrg_npcstate` / `lrg_scene` / `lrg_log` | 24-14.3 s / 183-3.5 s / 58-0.7 s / 152-0.5 s | **identical** | ok |
| glue total, share of player-turn time | 151.9 s, +25 %, 74 % is scene talk | **151.9 s, 25.2 %, 74.3 %** | ok |
| `addnpc` 275 @ 216 ms / `infoitems` 85, p90 3.15 s, max 9.1 s | | **101.4 s / 85, 3.15 s, 9.08 s** | ok |

The stage-share table needed its own derivation (the report does not say how TTS was attributed to a
request). Attributing each sidecar by mtime into the request window that contains it:

```
TTS inside player turns   364.3 s   60 %   (report: 338 s, 56 %)
LLM net of TTS            136.9 s   23 %   (report: 163 s, 27 %)
prompt build               42.6 s    7 %   (report:  43 s,  7 %)   ok
MAIN lock wait             33.5 s    6 %   (report:  34 s,  6 %)   ok
```

The two attributed rows differ by the width of the matching window, not by the conclusion: **TTS is the
majority of server time and the LLM is under a quarter of it.** That finding stands on its own.

Live-install facts, all confirmed today: `/home/dwemer/audio.cpp/server.json` really is
`"backend": "cuda", "device": 0, "threads": 1`; `tts/audiofilterd.sock` really is absent and no
`audiofilterd` process is running; `CONTEXT_HISTORY` really is `"75"` in `core_profiles.metadata` and `50`
in `conf_opts`; profile 1's slots really are `10 / 1 / 3 / 4 / 6 / 1 / 1`; connector 7 is
`google/gemma-3n-e4b-it` with `max_tokens 128`; connector 1 has `reasoning_model = 1` and `metadata = {}`;
connector 10 carries `{"extra_parameters":{"reasoning":{"effort":"none"}},"disable_streaming":false,...}`;
connector 3 is GLM **5.2**. I extended the "nothing points at connector 7" check past `core_profiles` to
every `CORE_CONNECTOR_*` / `RELLLM_CONNECTOR` general setting - **still nothing points at it**, so the
claim is not just true, it is stronger than stated.

### 1.2 The glue fixes change no behaviour - verified on a clean isolation

The round's own before/after pair is not isolated (see D2), so I built my own. `%TEMP%\lrg_v3_base` and
`%TEMP%\lrg_v3_fin` are the same snapshot of the live source; BASE only has `lib/lrg_actions.php` and
`config/lrg_config.default.json` reverted to the round's starting versions and `lib/lrg_latency.php`
removed. Everything the other round owns is held byte-identical between the two.

```
                      BASE                                  FIN
php -l                67 files ok                           68 files ok
test_gates            296 passed, 0 failed                  296 passed, 0 failed
test_intent           190 passed, 0 failed                  190 passed, 0 failed
test_phrases           14 passed, 0 failed                   14 passed, 0 failed
test_dialogue         112 passed, 0 failed                  112 passed, 0 failed
run_flows --strict    64 scen, 61 pass, 3 FAIL, 969 checks  64 scen, 61 pass, 3 FAIL, 969 checks
test_latency           n/a                                   21 passed, 0 failed
```

Identical, line for line, including the three failures (`d52` hold-movement and two business-topic checks)
and the one out-of-contract `CREATE SCHEMA lrg_index` call. Those belong to the other round, not this one -
the report's "they are not from this round" is correct, although the live tree now has three failures, not
the two (`d23`, `d29`) the report names.

The prompt-size table reproduces **exactly** on that clean isolation: scene/asked 3,931 -> 2,569;
scene/no-request 3,684 -> 3,247; every other fixture unchanged; mean 2,271 -> 2,091. No contamination from
the other round's new `character_bottom` injection, because `fxTurn` reads `lrgStaticGuidance` /
`lrgVolatileGuidance` directly and never runs `context_pre.php` (which is also D13).

The pre-lock claim checks out in the code: `lrgInitiativeAdmit` and `lrgSceneTalkHoldLeft` are reached
only from `lrgHandleGameMessage`, which is called only from `preprocessing.php` - genuinely before CHIM
takes MAIN. `lrgEnsureSchema()` runs before the new `lrgMemSet`. The deploy anchor pre-flight passes
(20 anchors, one match each) and every file in the tree lints.

### 1.3 The benchmark's method is fair

One `$system` string built once and sent to all six models; one strict `json_schema`; the same
`stream: true`, `max_tokens: 200`, `reasoning: {effort:'none', exclude:true}`; one `latRun()` stopwatch, so
`ttft` / `speech` / `total` are measured identically; one `latCorrect()` judge applied to every reply;
`$/1k turns` computed from OpenRouter's public list price against PT9's real 6,543/83 token turn, the same
formula for every row. Spend comes from the provider's own `usage.cost` and failed requests are skipped
before it accumulates, so "**$0.0306, the failed GLM requests cost nothing**" is consistent with the code.
Nothing of the owner's is transmitted - the prompt is invented in the file and the key is read from
`core_api_badge` and never printed. The GLM 5.3 disqualification (`reasoning` cannot be disabled on that
endpoint) is a real, cheap, well-reported finding, as is "DeepSeek V4 Flash is the slowest of the six as
this connector has it" - that one is genuinely useful and cuts against the easy answer the owner expected.

---

## 2. Defects

### D1 - HIGH - the headline saving is about four times too large

`pt9-latency-fix.md` section 5: *"this round removes ~133 s of MAIN-held LLM + TTS per nine-hour session"*.
133 s is simply `112.8 + 20.1` - the entire cost of every scene-talk and initiative turn in the session,
i.e. what you would save if the rails dropped **all** of them. They do not.

I replayed the three rails against the real session (`v18_rails.py`: each tick's start taken as its PERF
end minus `total_ms`, "MAIN busy" = any other request's interval covering that instant, quiet window 10 s,
server gap 45 s, gap armed on admission exactly as the code does it):

```
 #  time      dur     busy  spoke_ago  gap    verdict
 1  17:14:03   4.90s  no          -1     -1   admitted
 2  17:17:17  22.81s  no          19    194   admitted
 3  17:19:26   3.60s  no          16    129   admitted
 4  21:53:54   7.82s  no          15  16468   admitted
 5  21:54:51  18.54s  no          14     57   admitted
 6  21:57:32   6.98s  YES          2    161   DROPPED (MAIN busy, player spoke 2s ago)
 7  21:58:50   7.84s  no          39    239   admitted
 8  21:59:22  17.87s  no          21     32   DROPPED (her last spoken turn 32s ago)
 9  22:12:39   8.00s  YES         11    830   DROPPED (MAIN busy)
10  22:17:20  14.38s  no          37   1110   admitted

scene talk: 7 admitted, 3 dropped ->  32.9 s removed of 112.8 s
initiative: 3 admitted, 9 dropped ->   0.1 s removed of  20.1 s
```

The initiative result is the one that matters: the nine ticks the rails drop are the 4 ms ones that were
already being refused downstream, and the **three that actually spoke (7.0 / 9.7 / 10.4 s) all still pass**
- the player had been quiet for longer than 10 s and MAIN was free each time. So rail 3 buys essentially
nothing in this session.

Real removal: **~33 s per nine-hour session, not ~133 s** - about 5 % of player-turn server time, and it
does bite where it hurts (it removes two of the three worst convoy contributors). That is worth shipping.
It is not worth claiming four times over.

**Smallest fix:** replace "~133 s" in section 5 with the replayed figure and the method, and say plainly
that the rails drop *some* unsolicited turns, not all of them. In section 1's table, replace item 3's
"20.1 s per session" with "0.1 s in the measured session - the three ticks that actually spoke all pass
the rails" or drop rail 3 as not yet justified.

### D2 - HIGH - the quoted flow-test result is the BEFORE tree's

Section 1 "Tests" quotes, under `%TEMP%\lrg_pt9_fin`:

> `tools/flows/run_flows.php --strict` 61 scenarios, 61 passed, 0 FAILED, 0 pending, 934 checks, 0 warnings

Running that tree now:

```
%TEMP%\lrg_pt9_fin   61 scenarios: 58 passed, 0 FAILED, 3 pending; 892 checks   RESULT: FAILED - 3 pending (--strict)
                     pend scenario stopped early [plugin function lrgDlgHoldMovementPolicy() is not delivered yet]  (x3)
%TEMP%\lrg_pt9_base  61 scenarios: 61 passed, 0 FAILED, 0 pending; 934 checks   RESULT: OK
```

The quoted line is `lrg_pt9_base`'s output character for character - the tree **without** this round's
changes. The cause: the isolation rolled back `lib/lrg_dialogue.php`, `lib/lrg_prompt_index.php` and
`lib/lrg_speech.php`, but the other round had *also* edited `context_pre.php` and `functions.php` at 04:19
(adding the `lorerim_glue_locked_facts` injection and `lrgDlgHoldMovementPolicy();`), and those two were
left at the new version. The tree therefore calls two functions that do not exist in it.

The other four quoted lines (`test_latency` 21, `test_gates` 296, `test_intent` 190, `test_phrases` 14) do
reproduce in `lrg_pt9_fin`. Only the flow line is wrong - and it is the one that carries the report's whole
"nothing changed" claim. The claim itself is true (section 1.2 above), it just is not what was quoted.

**Smallest fix:** re-run `--strict` on a tree where the other round's five files are consistent with each
other (roll back `context_pre.php` and `functions.php` too, or roll nothing back), and quote that run.

### D3 - HIGH - an undocumented, non-latency change ships in this round's file

`lib/lrg_actions.php` grew a `[0.5.0 / E7(a)]` block between the round's base and its final version:

```php
const LRG_ACT_ALIVE_REQ = ['activity' => ['current_action_not_in' =>
    ['dead', 'unconscious', 'sleeping', 'combat', 'attacking']]];
function lrgActionsNeedRequirements(): bool { ... }          // triggers a catalog reinstall
'requirements' => ['request_types_any' => $types] + LRG_ACT_ALIVE_REQ,   // all five glue rows
```

The change table in section 1 has six rows and none of them is this, and the paragraph under it says
"**No rail, no gate and no wording changed.**" This *is* a gate: it hands CHIM's own
`herikaActionCatalogRequirementsMatch()` a new precondition on all five `ExtCmdLRG_*` rows, and
`lrgEnsureActions()` will reinstall the catalog rows on the first request after deploy because the
installed rows carry no `metadata.requirements.activity`. It is a reasonable change - it is the
condition-truthfulness work the owner asked for - but it is not a latency change, it is not mentioned, and
a reader of this report would deploy it without knowing a catalog reinstall is about to fire.

**Smallest fix:** add it to the change table with the reinstall-on-first-request consequence, or hand it to
the round that owns E7 and take it back out of this one.

### D4 - MEDIUM - the position cap is a real narrowing, framed as removing redundancy

Reading the three scene fixtures as the LLM would (`--dump=scene`, base vs fin):

- **scene / no request**: the catalogue survives but shrinks from **42 position names to 20**, and because
  `max_positions_total` is spent greedily in act order the last three acts lose **all** of theirs:
  `thighjob:npc`, `grope:you` and `hold` now appear with no positions at all. `hold` is the wind-down act.
- Gone from her vocabulary this turn: `fingering:you/spooning`, `/standing`, `kiss/sitting`, `/spooning`,
  `oralvulva:you/prone`, `/sitting`, `grinding/prone`, `/reversecowgirl`, and every position of the last
  three acts.

Section 1 item 5 describes this as "the list was 8 acts x 6 positions, each repeating its own key", and the
config readme says it "only narrows what SHE picks from". Both are true and neither tells the owner that a
third of the acts end up with no positions. The player-asks path is unaffected (I confirmed `test_intent`
is identical in both trees and the scene index is untouched), so this costs her *initiative*, not his
*control* - which is exactly the distinction worth stating.

**Smallest fix:** say "42 -> 20 position names, and three acts lose theirs entirely", and spend the total
budget round-robin across acts instead of in order, so no act reaches zero.

### D5 - MEDIUM - the RequestAct drop rests on a claim nothing supports

On the "player asked for something" turn the whole block goes, *including* the sentence "RequestAct takes
exactly one act key, exactly as written here." The code comment and the report both say the gate still
bounds "a RequestAct key the model produces from memory". Nothing re-supplies that vocabulary on that turn:
the injections are rebuilt per turn and CHIM's history carries the conversation, not last turn's system
block. A key produced from memory is a guess, and the gate drops a wrong one - so on the one turn the
player is actually waiting, an act change she decides to make alongside what he asked for silently fails.

**Smallest fix:** keep one short line listing the act keys without their positions (~150 of the 1,362
characters saved) even on an "asked" turn, or drop the "from memory" sentence and say the model may not
change act on that turn.

### D6 - MEDIUM - the model recommendation outruns its evidence, and the run is not reproducible

The method is fair (section 1.3). The conclusions drawn from it are not proportionate to n:

- **n = 6 per model**, sequential, model by model, over a shared network, and the medians are quoted to
  0.01 s. The primary recommendation turns on **0.79 s vs 0.92 s to a finished sentence - 0.13 s**. Six
  samples cannot separate that from routing noise; the same table shows a 4.19 s outlier inside one
  model's six requests.
- **No raw output is stored.** I searched `%TEMP%\lrg_pt9*`, `%TEMP%\lrg_test` and the project tree: only
  the script exists. The table in the report cannot be re-checked without spending the money again.
- The script collects `usage.prompt_tokens` per model but never prints it, so "same prompt size per model"
  is verifiably the same *characters* (25,340) but unverified in *tokens*, which differ by tokenizer.

The honest reasons to try Gemini are in the table already and do not need the 0.13 s: **6/6 strict-JSON
compliance with the right action against Grok's 4/6**, and **$1.76 vs $8.39 per 1,000 turns**. The report's
own caveat - that no synthetic prompt can tell you how a Google model behaves on the owner's explicit
content, and that one refusal costs more than 0.13 s ever saves - is exactly right and should be the
headline of that section rather than a footnote.

**Smallest fix:** save the run output next to the report, print n beside every median, and re-lead the
recommendation with compliance and cost rather than with speed.

### D7 - MEDIUM - the settings are named as database columns, not as the UI names them

Checked against the live UI source in `/var/www/html/HerikaServer`:

| the report says | the UI actually shows |
|---|---|
| `llm_primary_id` (profile 1) | Profiles page, **"🕹️ Standard"** |
| "secondary" slot | **"⚡ Fast LLM"** (`lib/core/prisma_settings_catalog.php:212`, `lib/profile_llm_mode.php:15`) |
| "tertiary" slot | **"💪 Powerful LLM"** |
| "quaternary" slot | **"🧪 Experimental LLM"** |
| `PROMPT_CONTEXT_OPTIONS` -> `enabled_sections` | Global Settings -> **"Top-Level Sections"** (`ui/global_settings.php:256`) |
| untick `nearby_items`, `points_of_interest`, `group_descriptions` | the checkboxes read **`<nearby_items>`**, **`<points_of_interest>`**, **`<group_descriptions>`**, with the angle brackets (`lib/settings.php:592+`) |
| "connector id 7 / id 1 / id 10" | page **"LLM Connectors"**; the rows read **"Gemma 3N E4B"**, **"DeepSeek V4 Flash"**, **"Grok 4.3"**, **"GLM 5.2"** (3), **"DeepSeek V4 Pro"** (4), **"Ministral 8B"** (6) |
| "profile 1" | **"Default Profile"** |

"Primary / secondary / tertiary / quaternary" appear nowhere the owner can see. **Smallest fix:** use the
UI strings above, keeping the column name in brackets for whoever edits the database.

### D8 - MEDIUM - the cheap-slot advice misses the background slots that are on Grok today

`general_settings` as it stands:

```
CORE_CONNECTOR_DIRECTOR        10   (Grok 4.3)
CORE_CONNECTOR_SCENECLASSIFIER 10   (Grok 4.3)
CORE_CONNECTOR_PROFILES        10   (Grok 4.3)   <- the report calls this "dynamic profile", unnamed
CORE_CONNECTOR_SUMMARY          4   (DeepSeek V4 Pro)
CORE_CONNECTOR_MEDIUMTERM       4
CORE_CONNECTOR_BGL              1
RELLLM_CONNECTOR                1
core_profiles.llm_formatter_id  6 / diary_connector_id 1 / llm_secondary_id 1
```

Three Grok-priced slots the player never waits on, and section 3's "fast / cheap slots" names none of them.
Two more facts the owner will want: **"⚡ Fast LLM" is already DeepSeek V4 Flash**, so half of that advice
is already in place; and connector **2 "Gemini 2.5 Flash Lite" already exists and is unused**, so the
Gemini trial needs no new connector row - repoint or clone row 2.

**Smallest fix:** list those four settings with their current values in section 4.

### D9 - MEDIUM - the biggest promised win is an unmeasured projection

Section 4 item 1 promises **"-1.5 to -3 s median, -8 to -15 s p90"** from `"backend": "cpu"`,
`"threads": 12`. Neither document measures PocketTTS on this CPU even once. The diagnosis is solid and
verified (the file really says cuda/0/1; audio.cpp's own header reports RTF 1.22 on the contended GPU), but
the number is a guess, and a CPU run of a neural TTS at 12 threads can easily land the wrong side of
realtime. This is the owner's single biggest recommended change and the one hardest for him to evaluate.

**Smallest fix:** mark it untested, give the revert in the same breath (restore `"backend": "cuda"`,
`"threads": 1`, restart audio.cpp) and name the acceptance test - re-time one ~300-character line with the
game running and compare against the 3.41 s / 6.94 s baseline.

### D10 - LOW - the interval clock is armed before she has actually spoken

`lrgHandleGameMessage` calls `lrgNoteSceneTalk($npc)` the moment a tick is admitted, i.e. before
`lrgPrepareTurn` / `prompts.php` decide whether she says anything at all. A tick that is admitted and then
silenced downstream still blocks the next 45 s. Effect: occasional extra silence, never extra latency.
**Fix:** write `scenetalk_at` where the line is really produced, or clear it when the turn ends up silent.

### D11 - LOW - "one small write" is up to three queries on every player line

Section 1 item 3 calls the new out-of-scene clock "one small write, still pre-lock". It is
`lrgResolveRequestNpc()` (which can run `SELECT npc_name FROM core_npc_master` when `?profile=` is set),
then `lrgMemGet`, then an upsert - and it now creates an `lrg_memory` row for **every NPC the player ever
speaks to**, not only scene NPCs. Still cheap and still pre-lock, but not one write, and the table grows
where it did not before. **Fix:** say so, or key the clock off the existing `lrg_npcstate` row.

### D12 - LOW - pt9-latency.md's TTS cost model is not reproducible

- *"fit over 124 calls: seconds = 2.74 + 0.0267 x chars"*: over all 132 sidecars I get
  `3.91 + 0.0180 x`; excluding the calls >= 20 s (n=130) `1.66 + 0.0448 x`; excluding >= 12 s (n=129)
  `1.83 + 0.0400 x`. No time cut produces exactly n=124, and the subset is not named.
- The line under it (*"10 -> 3.0 s, 30 -> 3.0 s, 50 -> 5.0 s, 90 -> 7.0 s, 120 -> 10.0 s"*) matches
  neither that fit (which gives 3.0 / 3.5 / 4.1 / 5.1 / 5.9) nor the data. Binned medians:
  `<15 ch 2.91 s | 15-35 ch 3.05 s | 35-60 ch 3.53 s | 60-100 ch 4.12 s | 100-200 ch 8.44 s`.
- *"whole-session throughput 11.2 chars/s"*: 9.4 over all calls, 12.2 excluding the two outliers.
- *"the median first sentence is already only 30 characters"*: the median TTS call text is **37**
  characters (p25 27, p75 51).

The conclusion these support - the cost is mostly fixed, so asking for shorter sentences barely helps
time-to-first-audio - **survives** (2.91 s at under 15 characters against 3.53 s at 35-60). Only the
coefficients are wrong. The same rounding explains the one stage-table gap I found (`streamv2` median
7.54 s, reported 7.73 s). **Fix:** requote the fit with its subset named, or drop it and keep the binned
medians, which say the same thing and are reproducible.

### D13 - LOW - the prompt-size table measures only the Phase 1 injections

`test_latency_prompt.php` builds its rows through `fxTurn`, which reads `lrgStaticGuidance` and
`lrgVolatileGuidance` directly and never runs `context_pre.php`. So `lorerim_glue_business_rules`,
`lorerim_glue_locked_facts` (new this week, up to 600 characters) and `lorerim_glue_business` are outside
every number in that table - including section 4's "the glue's injected prompt block ... is now ~490 tokens
mean, ~3 % of the prompt". For the expanded scope the owner asked about - bartering, training, inns,
carriages, guard and crime dialogue - those are precisely the blocks that grow.
**Fix:** add a `fxHookTurn` column, or say the table covers the Phase 1 injections only.

---

## 3. Deployment

**Not deployed, and it should not be from here.** The verdict is FIX FIRST, and independently of that the
other round has files staged right now: `lib/lrg_dialogue.php`, `lib/lrg_prompt_index.php`,
`context_pre.php` and `functions.php` were all written within the last half hour, and the live source tree
fails its own `--strict` flow run with three scenarios (`d52` hold-movement and two business-topic checks)
plus one out-of-contract `CREATE SCHEMA lrg_index` call.

For the record, the two preconditions the task named do both hold at this moment: `lib/lrg_dialogue.php`
lints clean on disk (as do all 68 PHP files in the tree), and a dry run of the deploy script's anchor
pre-flight passes with 20 anchors matching once each. **Deployment is the main session's call**, after the
other round's three flow failures are resolved.
