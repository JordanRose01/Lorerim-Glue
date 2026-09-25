# PT9 latency - reconciliation

Written 2026-09-22, after `research/pt9-latency-verify.md` returned **FIX FIRST**.
Inputs read in full: `pt9-latency-verify.md`, `pt9-latency-fix.md`, `pt9-tts-cpu.md`, `pt9-latency.md`
(for the stage numbers), `glue/OWNER_ADDENDA.md` items 8-10.

**The short version.** Three of the round's engineering changes stand and are worth their place. Two
of them were narrowings of what the NPC may choose, sold as removals of redundancy, and they are
**out**. Two more were small correctness bugs and are **fixed**. The round's headline saving was
about four times too large and is now the replayed figure. And the one file that could spend the
owner's money by being named `test_*` has been renamed and made dry by default.

After all of that, the honest accounting of what this round buys is:

| | |
|---|---|
| pre-lock pacing rails | **~33 s** of MAIN-held LLM + TTS per nine-hour session (~5 % of player-turn server time), and it removes two of the three worst pile-ups of the evening |
| prompt size | **0.** Both trims are reverted; there was nothing duplicated or dead to remove |
| the actual big lever | **TTS**, and it is owner-side: `"backend": "cpu"`, `"threads": 8` in `/home/dwemer/audio.cpp/server.json` |

---

## 1. Each flagged behaviour change: reverted, or justified

### 1.1 The position cap - **REVERTED** (verify D4)

`scene_index.max_positions_per_act` 6 -> 4 plus a new `max_positions_total` 20.

**Not duplicates.** I dumped the rendered act block on the real scene fixture with the caps off and
read all 42 entries: every `act/position` pair is distinct, and the repeated act-id prefix
(`fingering:you/doggy, fingering:you/facesitting, ...`) is not redundancy - it is the exact key the
next sentence tells the model to write back *verbatim* ("RequestAct takes exactly one act key,
exactly as written here"). Compressing the prefix away would change the contract, not the fat.

What the cap actually did, because the total was spent greedily in act order: **42 position names
down to 20**, with the last three acts - `thighjob:npc`, `grope:you` and **`hold`**, the wind-down
act - left with **none at all**. That is a narrowing of what *she* can pick, for a measured
0.05-0.15 s of LLM time. The player was never affected either way: a position asked for in plain
words is resolved against the full index, and `test_intent` is byte-identical in both trees.

Reverted to `max_positions_per_act: 6`; `max_positions_total` removed from code and config. The
config readme now records why, and says that if the block is ever capped again the budget must be
spent **round-robin** across acts so no act reaches zero.

### 1.2 The RequestAct catalogue drop on "asked" turns - **REVERTED** (verify D5)

The claim that made it safe was that the post-LLM gate still bounds "a RequestAct key the model
produces from memory". Nothing re-supplies that vocabulary on that turn: the injections are rebuilt
per turn and CHIM's history carries the conversation, not last turn's system block. A key produced
from memory is a guess, and the gate drops a wrong one - so on the one turn the player is actually
waiting, an act change she decides to make *alongside* what he asked for would fail silently.

The gate keeps the catalogue sentence on every turn that offers `RequestAct`, exactly as before the
round. `&& !$asked` and the `$asked` computation are gone.

### 1.3 The scene-talk interval clock armed before she spoke - **FIXED** (verify D10)

`lrgNoteSceneTalk()` was called from `lrgHandleGameMessage`, i.e. the moment the pre-lock hook
*admitted* a tick. Admission is not a spoken turn: `prompts.php` still re-checks a live scene with
this NPC, a snapshot no older than 90 s, `adult=1`, `witkid=0`, the owner's switch on and a profile
that is not `never` before it defines a cue at all. A tick admitted and then refused there blocked
the next 45 s for a line nobody heard.

It is now armed inside `lrgPrepareTurn()`, in the branch that is reached only when every one of
those rails has held - i.e. exactly when the glue's cue really goes to the model. That is as late as
the glue can get on this request type: **functions are disabled for `lrg_scenetalk`**
(`main.php:1078`), so `functions.php` never runs and the post-LLM gate is never registered;
`context_pre.php -> lrgPrepareTurn()` is the last point we control. Lead ticks still share the
interval; the outro still never touches it.

Residual, stated plainly: if the model returns an empty `message` after all that, the clock is still
armed. That is unknowable before the reply on a request type that has no post-LLM hook.

### 1.4 The "one small write pre-lock" - **MADE LAZY** (verify D11)

It was never one write: `lrgResolveRequestNpc()` (which can run a `core_npc_master` SELECT when
`?profile=` is set), then `lrgMemGet`, then an **upsert** - creating an `lrg_memory` row for every
NPC the player ever spoke to, a table that grows with the population of Skyrim to protect a rail
worth **0.1 s** in the measured session.

Now, in order: the two free refusals first (not live / narrator turn; initiative rail switched off or
`player_quiet_seconds` 0), then resolve, then **update-only** - `lrgMemGet($who) === []` returns
without writing. `lrgInitiativeAdmit()` writes `initiative_at` the first time it admits a tick for an
NPC, so from her first own move onwards the clock is kept for her. The only thing laziness costs is
the quiet-window check on that very first tick, where the MAIN-busy refusal and the gates still run.

### 1.5 Kept, and why

* **Rail 1 (MAIN busy)** and **rail 2 (the player just spoke)** on scene talk: these are the 33 s.
* **Rail 3 (initiative)**: kept for its worst case, not its median - it buys ~0.1 s in this session
  and the report now says so instead of claiming 20.1 s.
* **`gap_exempt_moments: ["peak"]`**: a climax line may skip the interval, never the other two rails.
* **The outro is never gated.** Unchanged.
* **The `[0.5.0 / E7(a)]` `requirements` block** in `lib/lrg_actions.php` (verify D3) is **kept**, not
  reverted: it is the condition-truthfulness work the owner asked for in addendum 9c, it is covered
  by flow scenario `d59`, and it has already shipped. It is not a latency change, it belongs to E7,
  and its consequence is now written down in both notes files: **the five `ExtCmdLRG_*` catalog rows
  are reinstalled once, on the first request after deploy**, because the installed rows carry no
  `metadata.requirements.activity`.

---

## 2. The prompt-token delta, measured honestly

`tools/test_latency_prompt.php`, three trees staged under `%TEMP%\lrg_test\`, everything except this
lane's files held byte-identical, real scene index (607 scenes). Tokens are the chars/4 estimate, the
same ratio PT9 measured live (176 tok for 705 characters).

| flow fixture | pre-round | **BEFORE** = v0.5.0 as shipped | **AFTER** = reconciled |
|---|---|---|---|
| scene, player asked for something | 3,931 ch / ~983 tok | 2,569 ch / ~643 tok | **3,931 ch / ~983 tok** |
| scene, player speaks, no request | 3,684 ch / ~921 tok | 3,247 ch / ~812 tok | **3,684 ch / ~921 tok** |
| scene, lead tick | 2,215 ch / ~554 tok | 2,215 ch / ~554 tok | **2,215 ch / ~554 tok** |
| mean over all ten fixtures | 2,271 ch / ~568 tok | 2,091 ch / ~523 tok | **2,271 ch / ~568 tok** |

Read it in the direction that matters: **BEFORE -> AFTER is +1,362 ch / +340 tok on an "asked" turn
and +437 ch / +109 tok on a no-request turn** - the glue is giving her back her vocabulary. The
reconciled numbers land exactly on the pre-round ones, which is the arithmetic proof that the two
reverts are complete and that nothing else in the round touched prompt size.

**So this round's prompt saving is zero,** and that is the honest answer. The 340 tokens were worth
0.05-0.15 s against a 6,543-token prompt. There were no "pure wins" to keep: the diff between the
round's base and its shipped version contains no dedupe, no dead sentence and no compressed list -
only the two trims above. Anyone who wants to look: `diff` the round's base `lib/lrg_actions.php`
against the shipped one; the whole prompt-side change is the `!$asked` condition and the two `$pos`
lines.

**One caveat on the table itself** (verify D13): it is built through `fxTurn`, which reads
`lrgStaticGuidance` / `lrgVolatileGuidance` directly and never runs `context_pre.php`. So Phase 2's
`lorerim_glue_business_rules`, `lorerim_glue_locked_facts` and `lorerim_glue_business` are outside
every number in it. For the expanded scope of addendum 9a those are precisely the blocks that grow.

---

## 3. The savings claim, corrected

`pt9-latency-fix.md` section 5 said the round *"removes ~133 s of MAIN-held LLM + TTS per nine-hour
session"*. 133 s is `112.8 + 20.1` - the entire cost of every scene-talk and initiative turn of the
session, i.e. what the rails would save if they dropped **all** of them.

Replayed tick by tick against the real session (verify section 2, D1), with each rail's actual
conditions - tick start = its PERF end minus `total_ms`, "MAIN busy" = any other request's interval
covering that instant, quiet window 10 s, server gap 45 s, gap armed on admission as the code does it:

```
scene talk:  7 admitted, 3 dropped  ->  32.9 s removed of 112.8 s
initiative:  3 admitted, 9 dropped  ->   0.1 s removed of  20.1 s
```

The nine initiative ticks the rails drop are the 4 ms ones that were already being refused
downstream; the three that actually spoke (7.0 / 9.7 / 10.4 s) all still pass, because the player had
been quiet for longer than 10 s and MAIN was free each time.

**Corrected claim: ~33 s per nine-hour session, about 5 % of player-turn server time.** It is worth
shipping - it removes two of the three worst convoy contributors - and it is not worth claiming four
times over. The wording in `pt9-latency-fix.md` section 5 and in `PLAYTEST9_NOTES.md` section 7 now
says exactly this.

Two further honesty corrections carried into `pt9-latency-fix.md`:

* the flow-test line quoted under "Tests" was `%TEMP%\lrg_pt9_base`'s output - the tree *without* this
  round's changes (verify D2). The conclusion it carried is independently true; the quote was not.
  It is replaced with a run of the whole v0.5.0 tree, every other lane's work in place.
* the model recommendation now leads with **6/6 vs 4/6 strict-JSON compliance and $1.76 vs $8.39 per
  1,000 turns**, and says in the same breath that **n = 6 cannot separate 0.79 s from 0.92 s**
  (verify D6). The benchmark can now save its raw samples (`--out`) and prints `n` and the per-model
  prompt-token median beside every row, so a conclusion can be re-checked without paying again.

---

## 4. The benchmark can no longer spend money by accident

`tools/test_latency_models.php` -> **`tools/bench_llm_models.php`**, and **`--dry` is now the
default**. Nothing is sent and nothing is spent unless `--live` (or `--go`) is passed; the run prints
`MODE: DRY` / `MODE: LIVE` on its second line and ends with `DRY RUN: nothing was sent, $0.0000
spent.` Under its old name it was one careless `for f in tools/test_*.php` away from a release pass
spending the owner's money - and the staged release script really does iterate `tools/`.

Also added while the file was open (verify D6): `--out <file>` writes every raw sample (model, index,
ttft, speech, total, provider, json verdict, prompt/completion tokens, cost - never any reply text),
`n` is printed beside every median, and the provider's own median `prompt_tokens` is printed per
model, because the same characters tokenise differently per model.

`tools/bench_llm.php` is deliberately **left as it is**: it is not named `test_*`, so no test sweep
picks it up, and it is the file `PLAYTEST9_NOTES.md` tells the owner to run with explicit flags.
Making it dry-by-default would have made a documented command silently do nothing.

---

## 5. The owner notes, corrected (`PLAYTEST9_NOTES.md` section 7)

The whole latency section was rewritten. What changed:

* **The model-switch setting.** Kept and sharpened: the reply the player waits for comes from the
  **profile's Standard LLM**, `core_profiles.llm_primary_id` - **Profiles** ->
  `http://localhost:8081/HerikaServer/ui/core/core_profiles.php` -> **`Default Profile`** -> the
  **🕹️ Standard** slot (10 = `Grok 4.3` today). `CORE_CONNECTOR_DIRECTOR` is the **Director Mode 🎬**
  slot on Global Settings, a different feature that happens also to be 10.
* **The TTS numbers are `pt9-tts-cpu.md`'s.** GPU idle **0.089 s** (33x realtime); in-game **3.41 s
  median / 6.90 s p90 / 97.6 s max** = GPU starvation, not a slow synthesiser (a 28-character line
  took 0.12 s at 17:43 and 97.60 s at 17:08 on the same box). CPU **threads 8**: 0.615 s median /
  1.447 s p90 - and threads 8 rather than 12, because 8/12/16/24 are within noise of each other and 8
  leaves 24 of 32 logical cores for Skyrim. The expected net (**-2.8 s median, -5.5 s p90, tail
  gone**) is labelled as inference from the session's own bimodal log, with the revert (`.bak`) and
  the one-scene acceptance test in the same breath.
* **`audiofilterd` does not exist in this distro** - not stopped, never shipped: only the client PHP,
  no binary, no package, no socket, no service script, nothing in the HerikaServer git history. So
  the silence trim is a **one-line edit to a CHIM core file** (`tts/tts-pockettts.php` ~448,
  `$FFMPEG_FILTER=''`), which makes it the **OWNER's decision** - it is lost on a CHIM update. "Do
  nothing" is presented as a defensible third option.
* **`:8081` on every UI link** (`/etc/apache2/ports.conf` `Listen 0.0.0.0:8081`).
* **CHIM settings named as the UI names them**: Global Settings -> card **Context Selections** ->
  **Top-Level Sections**, with the checkboxes spelled **`<nearby_items>`**,
  **`<points_of_interest>`**, **`<group_descriptions>`**; **LLM Connectors** rows `Grok 4.3`,
  `DeepSeek V4 Flash`, `Gemma 3N E4B`; the profile slots **🕹️ Standard / ⚡ Fast LLM / 💪 Powerful
  LLM / 🧪 Experimental LLM**; the global slots **Director Mode 🎬 / Profile Tasks 👥 / Scene
  Classifier 🎭 / Background Life ⏱️ / Background & Memory Tasks 🧠 / Summaries 📝 / Player
  Respeech 🎮**; `CONTEXT_HISTORY` under **Profiles -> Default Profile -> metadata -> Context**
  (75 is the live value; the `conf_opts` 50 has no reader).
* **The `ev=lat` caveat and how to read the LAT lines**: every field (`total` / `reply` / `voice` /
  `first` / `SLOW`) is explained, `voice=` is named as the number that should fall when PocketTTS
  moves to the CPU, and the caveat is stated as a condition rather than a footnote - the measurement
  starts when CHIM finishes voicing the **player's own** line, so with CHIM's player TTS / re-speech
  off **no LAT line appears at all**. `dlg self-test chim tts=` says which the owner has.
* **The corrected savings claim** and the two reverts, in the owner's own terms.
* Section 9's parenthetical now names `bench_llm_models.php` and its dry default; section 8's test
  count is 21 -> **26**.

---

## 6. Tests - all green

Run inside `DwemerAI4Skyrim3` from a staged copy of the whole v0.5.0 tree with every other lane's
work in place (`%TEMP%\lrg_test\pt9r_recon`), 2026-09-22:

```
php -l  over 73 files                 0 errors
tools/test_gates.php                296 passed, 0 failed
tools/test_intent.php               190 passed, 0 failed
tools/test_phrases.php               14 passed, 0 failed   (hit rate 91.9 %, floor 82 %)
tools/test_latency.php               26 passed, 0 failed   (21 before)
tools/bench_llm_models.php           DRY RUN: nothing was sent, $0.0000 spent
tools/flows/run_flows.php --strict   73 scenarios, 73 passed, 0 FAILED, 0 pending, 1127 checks, 0 warnings
```

The same tree with only this lane's files at their as-shipped version (`%TEMP%\lrg_test\pt9r_ship`)
gives an identical 296 / 190 / 14 and 73-of-73 flows, with `test_latency` at 21. So the
reconciliation changes exactly what it says it changes and nothing else.

The five new `test_latency` checks are the two fixes' regression tests:

* an **admitted** tick that never becomes a turn does not arm the interval; the next one is still
  admitted; and a real turn behind an admitted tick **does** arm it (1.3);
* an NPC the glue does not track gets **no** memory row from a player line, and her own tick is still
  refused - by the gates rather than by the clock (1.4).

`test_latency.php` also had to learn the difference: `lat_admit()` plays the pre-lock hook alone,
`lat_talk()` plays the whole turn.

---

## 7. What is NOT done, and whose call it is

**No game file changed.** This lane touched no `.psc`, no `.pex`, no `.esp`, no seq file and neither
MCM file, so `install_mo2.ps1` had nothing to do - the game side is byte-identical to what shipped at
06:30.

**The two server files ARE deployed.** (Corrected 2026-09-22 in the 0.5.1 fix pass: this section used
to say "Nothing was deployed ... Exactly **two** files differ from it" and gave the owner a step
"decide whether to deploy the two changed server files". Both were already stale.) The followers
lane's whole-tree deploy at 07:19 / 07:30 carried `lib/lrg_actions.php` and
`config/lrg_config.default.json`. Every `.php` / `.json` / `.sql` in `server/lorerim_glue` has since
been byte-compared against `/var/www/html/HerikaServer/ext/lorerim_glue`: **zero differences in both**
**directions**, with only `config/lrg_config.json` (the owner's own overrides) and `data/` live-only,
as designed. The owner's install no longer narrows her act list and no longer drops the catalogue on
an "asked" turn, and there is no deploy decision left for anyone to take. Evidence:
`pt9-ship-v051.md` section 2 and `pt9-ship-v051-final.md` section 4.

**Still owner-side, still unmeasured in game:** the PocketTTS backend change. It is the single
biggest number on the table and the one this lane cannot settle from outside the game.
