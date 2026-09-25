# pt9 - INDEPENDENT VERIFICATION of v0.5.1: server, latency, docs

Read-only pass, 2026-09-22. Lens: **SERVER + LATENCY + DOCS**. Nothing in the project tree, the MO2
mod folder, the live server or the database was modified. All work was done from a staged copy at
`%TEMP%\lrg_test\v_lat` (the whole `glue` tree, plus `%TEMP%\lrg_test\v_before`, a reconstruction of
the v0.5.0 prompt code built only for the BEFORE/AFTER diff).

Inputs read in full: `research/pt9-followers-build.md`, `research/pt9-latency-reconcile.md`,
`research/pt9-latency-fix.md`, `research/pt9-ship-v051.md`, `research/pt9-tts-cpu.md` (numbers),
`glue/OWNER_ADDENDA.md` items 8-10, `PLAYTEST9_NOTES.md`.

**VERDICT: SHIP.** No critical or major code defect on the server/latency side. Both reverts are
complete and *proven* rather than asserted, every test and every flow reproduces exactly, the
benchmark cannot spend money by accident, and the corrected savings claim survives an independent
replay. The one **MAJOR** finding is documentation: `PLAYTEST9_NOTES.md` - the only document the
owner actually reads - was never updated for v0.5.1 and contains none of the follower round, including
the owner action that round calls "the one that matters". Fix that before the notes are handed over;
it does not block the build.

---

## 1. Everything re-run here, from the staged tree

| gate | claimed | **measured here** |
|---|---|---|
| `php -l` | 74 files, 0 errors | **74 files, 0 errors** |
| `test_gates` | 316 / 0 | **316 passed, 0 failed** |
| `test_intent` | 190 / 0 | **190 passed, 0 failed** |
| `test_phrases` | 14 / 0, 91.9 % | **14 passed, 0 failed, 91.9 %** |
| `test_scene_index` | ALL PASSED | **ALL CHECKS PASSED** |
| `test_dialogue` | 132 / 0 | **132 passed, 0 failed** |
| `test_prompt_index` | 67 / 0 | **67 passed, 0 failed** |
| `test_prompt_index --db` | 112 / 0 | **112 passed, 0 failed** |
| `test_mcm_wiring` | 4 / 0 | **4 passed, 0 failed** (113 controls, not 17 - see D5) |
| `test_services` | 35 / 0 | **35 passed, 0 failed** |
| `test_latency` | 26 / 0 | **26 passed, 0 failed** |
| `flows --strict` | 74 scenarios, 1161 checks, 0 warnings | **74 passed, 0 FAILED, 0 pending, 1161 checks, 0 warnings** |
| `bench_llm_models.php` (no args) | dry by default | **`MODE: DRY` … `DRY RUN: nothing was sent, $0.0000 spent.`** |

`test_services` needs `server/lorerim_glue/data/service_catalog.json`, which is not in the project
tree (it is a build artifact). I copied the live one in; the test then passes. The tool prints the
exact rebuild command when it is missing, so this is not a defect.

**Live server vs project tree, byte-compared in both directions:** *zero* differences across every
`.php` / `.json` / `.sql`, with only the owner's `config/lrg_config.json` and `data/` live-only.
`manifest.json` = `0.5.1`. `lrg_index.lrg_prompt` = 37,561 rows. The six glue catalog rows are present
and activated (`import_version` 10 for the five Phase-1 rows, 1 for `Take_Up_Business`); my test runs
did not disturb them.

---

## 2. The prompt blocks, BEFORE vs AFTER, read as the LLM would

No git and no 0.5.0 backup exists, so I reconstructed **BEFORE** by applying *only* the two changes the
reconciliation says it reverted - `&& !$asked` on the catalogue, `max_positions_per_act` 4 plus a
greedy `max_positions_total` 20 - to a copy of the shipped tree, then ran
`tools/test_latency_prompt.php` on both.

```
                                        BEFORE (reconstructed)   AFTER (shipped 0.5.1)
scene  (player speaks, acts offered)      2,569 ch / ~643 tok      3,931 ch / ~983 tok
scene  (player speaks, no request)        3,247 ch / ~812 tok      3,684 ch / ~921 tok
scene  (lead tick)                        2,215 ch / ~554 tok      2,215 ch / ~554 tok
MEAN over all ten fixtures                2,091 ch / ~523 tok      2,271 ch / ~568 tok
```

**My reconstruction reproduces the reconciliation's "as shipped" column exactly, to the character,
on all ten fixtures.** That is the strongest available evidence that the report's account is complete:
if any *third* prompt change had shipped in that round, re-applying only these two could not have
landed on 2,569 / 3,247 / 2,091.

The textual diff of the three scene fixtures contains **nothing but the act block**:

* the "asked" turn: BEFORE has no `RequestAct takes exactly one act key…` line and no act list at all;
* the "no request" turn: BEFORE carries 20 position names against AFTER's 42, and
  **`thighjob:npc`, `grope:you` and `hold` have no positions at all** - exactly the failure the
  reconciliation describes, reproduced;
* the lead tick is byte-identical (md5 equal).

No rail, no gate and no wording differs anywhere else. Both reverts are complete, and neither trim was
duplicate-only: I dumped the full act line and counted **42 distinct `act/position` pairs**, no repeats.

**Block size, measured:** header 78 + list 1,282 + 2 newlines = **1,362 characters**, which is exactly
the `-1,362` the report quotes. (Both the shipped config readme and the source comment say **1,540** -
see D3.)

D10 and D11 verified in code, not just in the report:

* `lrgNoteSceneTalk()` has exactly one call site, `lib/lrg_actions.php:1000`, inside `lrgPrepareTurn()`
  behind `!$same && $type === 'lrg_scenetalk' && !lrgIsOutroTick()`, in the `$inSceneWithThisNpc`
  branch. I checked the branch conditions against `prompts.php:26-35`: `lrgPrepareTurn` re-tests a
  fresh snapshot, `adult=1`, `witkid=0`, `on=1`, a live scene with this NPC **and** `profile not
  never`. That is a strict superset of what `prompts.php` re-checks, so the arming point is
  at-or-later than every rail that could still refuse the cue - the fix holds regardless of hook order.
* `lrgNotePlayerSpeech()`'s out-of-scene branch takes the two free refusals first and then returns on
  `lrgMemGet($who) === []`: **update-only**, no row is created. `test_latency.php:132-140` asserts it
  with a real "Passing Stranger" fixture.

---

## 3. The savings claim, replayed independently

I extracted every `[PERF] … "status":"complete"` record from `/var/www/html/HerikaServer/log/chim.log`
(8,680 records) and replayed the ticks myself.

```
lrg_scenetalk    n=10   sum = 112.764 s     (report: "112.8 s")
lrg_initiative   n=12   sum =  20.064 s     (report: "20.1 s")
inputtext        n=71   sum = 603.355 s     (report: "71 player lines")
```

The session totals the report builds on are **exactly right**. The drop counts depend on one modelling
choice the report does not spell out - whether "the player spoke" is stamped at the player request's
start (which is what the code does: `lrgNotePlayerSpeech` runs at the pre-lock hook) or at its end -
and my two models bracket the claim:

| model | scene talk | removed |
|---|---|---|
| player clock at request **start** (faithful to the code) | 8 admitted / 2 dropped | 24.9 s |
| **the report** | 7 admitted / 3 dropped | **32.9 s** |
| player clock at request **end** | 4 admitted / 6 dropped | 82.0 s |

Initiative: my replay drops 5 of 12 for **0.0 s**; the report drops 9 for 0.1 s. Every model agrees the
initiative rail buys ~nothing in this session, which is what the report now says.

**Conclusion: the corrected "~33 s, about 5 % of player-turn server time" is honest and of the right
order** (32.9 / 603.4 = 5.5 %), and the retracted ~133 s is definitively wrong. The claim is not
inflated; if anything it is on the conservative side of the band. No defect.

TTS: the numbers in `PLAYTEST9_NOTES.md` section 7 match `pt9-tts-cpu.md` line for line - GPU 0.089 /
0.172, CPU t8 **0.615 / 1.447**, t4 0.703 (= 14 % slower), t12 0.618, t16 0.627, in-game 3.41 / 6.90 /
97.6. The `audiofilterd` account is correct and the core one-liner is presented as the owner's
decision with "do nothing" as a third option. The `ev=lat` caveat is stated as a condition, and the log
format quoted in the notes (`lat npc=… total=…ms reply=…ms voice=…ms first=…`, plus the `SLOW` line at
`iLatencyWarnMs`) matches `lrgDlgOnLatency()` at `lib/lrg_dialogue.php:901-906` exactly. The UI links
all carry `:8081`. No defect in the latency section.

**Money:** `bench_llm_models.php` sets `$dry = empty($args['live']) && empty($args['go'])`, gates the
request loop on `!$dry`, and never even reads the API key in dry mode (`$key = $dry ? 'dry' : latKey()`).
`tools/test_latency_models.php` is gone, so no `tools/test_*.php` sweep can reach it. `deploy_server.ps1`
only `php -l`s the deployed server directory and never executes anything under `tools/`, so
`bench_llm.php` staying non-dry is not exposed by any automated pass in this repo.

---

## 4. The follower lane's server half

All three items in the lens check out.

* **`MakeFollower` hide conditions** (`lrgFollowerPolicy()`, `lib/lrg_core.php:1452`): called at brace
  depth 0 from `functions.php:73`, **last of all**, and it only ever removes. Three independent
  reasons, each a fact from this moment's snapshot - `mate=1`, `cap=0`, `ghost=1` (which also removes
  `Follow` and `FollowPlayer`). An absent or stale `fol=` changes nothing (`lrgFolFresh`, 90 s).
  `test_gates.php:1044-1070` asserts all five rows of the report's table plus the stale-snapshot case,
  and they are substantive assertions, not smoke.
  The owner steps were re-checked against the live database by `code_name` (which is the key
  `herikaGetActionCatalogRow()` really uses - `lib/core/action_catalog.php:2494`):
  `Follow`=t, `MakeFollower` (`Join_#PLAYER_NAME#_Party`)=t, `FollowPlayer` (`Follow_#PLAYER_NAME#`)=t,
  `WaitHere`=f. The owner instructions are accurate.
* **Follower service kind**: `services.kinds.follower.hide` is exactly `hide_follower`, and
  `lrgDlgServiceHidePolicy()` special-cases it (`lib/lrg_dialogue.php:3512-3517`) so it fires only when
  `$menuless && lrgDlgFollowerOn($npc) && $turn['fol']['verbs']` - a list that really carries follower
  entries - and never on the `bServiceShortcut=off` path. That is genuinely narrower than `$known`, as
  claimed. Flow `d56` asserts the subset property and `d62` (33 checks) covers the verbs; both pass.
* **Snapshot intake reads every new key**: `lrgParseKv()` has no whitelist and no truncation, and
  `lrgStoreNpcState()` stores the whole map as JSON, so `fol=`, `witchim=` and `fv=` arrive intact
  (`fol=`'s commas and colons are safe - the wire separators are `;` and `=`). Readers exist for all
  three: `lrgFolState()` (`lrg_core.php:1352`), the privacy gate (`lrg_core.php:1156`), and the MCM
  wire-key range map (`lrg_dialogue.php:446-447`) feeding `lrgDlgFollowerOn()`. `witchim` can only ever
  *add* the `companion_present` reason (`>0` test, so double counting cannot change a verdict).

---

## 5. Defects

### D1 - MAJOR (docs) - `PLAYTEST9_NOTES.md` was never updated for v0.5.1

The owner's only playtest document still says **"Playtest 9 - v0.5.0"** and contains **zero**
occurrences of *follower*, *companion*, *SFF*, *ghost* or *0.5.1* (verified by grep). Consequences:

* the new **MCM → LoreRim Glue → Followers** page (5 controls) is undocumented for the owner;
* the follower round's own "this is the one that matters" owner action - CHIM web UI (`:8081`) →
  **Actions** → switch **OFF** `Join_<player>_Party` (`MakeFollower`) and `Follow` - exists only in
  `research/pt9-followers-build.md` and `research/pt9-ship-v051.md`. Left on, `MakeFollower` is the
  exact mechanism that sends the game's dismiss line to the *real* follower;
* the K2 hotkey warning, the "force default voice" warning, the CHIM Behavior toggles and the SFF
  `iSpeechLevelsPerSlot` note are likewise absent;
* section 8's verification list is all 0.5.0-era and now wrong: "73 files" (74), "gates 296" (316),
  "dialogue 112" (132), "services 24" (35), "MCM wiring (108 controls…)" (113), "73 scenarios, 1127
  checks" (74 / 1161), "deployed at 06:30: 23 anchors" (07:30, 28 anchors), "all 9 scripts" (10 `.pex`);
* section 9 item 1 says "verified all 22 by hash: … the 9 `.pex`, the 9 `.psc` sources", where the
  0.5.1 install compared 24 files, copied 10 `.pex` and left 14 byte-identical.

**Smallest fix:** add one "### v0.5.1 - followers" subsection to section 1 carrying the two CHIM
Actions switches, the new MCM page, and the K2 / force-default-voice warnings; refresh the numbers in
sections 8 and 9; change the title to v0.5.1.

### D2 - minor (docs) - `pt9-latency-reconcile.md` section 7 "NOT DEPLOYED" is false

It states "Nothing was deployed … Exactly **two** files differ from it (checked … today):
`lib/lrg_actions.php`, `config/lrg_config.default.json`", and asks the owner to "Decide whether to
deploy the two changed server files". Both files went live with the followers lane's whole-tree deploy;
I byte-compared the entire extension against the live copy today and found **zero** differences in both
directions. `pt9-ship-v051.md` section 2 already records this, but the reconcile file - which the owner
or a later round may read on its own - still carries the false statement and the stale owner step.

**Smallest fix:** replace section 7's first two paragraphs with "deployed at 07:19/07:30 by the
followers lane; verified byte-identical", and delete the "decide whether to deploy" owner item.

### D3 - minor (server + docs) - the "1,540 characters" figure is wrong in a *shipped* file

`server/lorerim_glue/config/lrg_config.default.json:490` (`_pt9_positions_readme`) and the source
comment at `lib/lrg_actions.php:2808` both say the act list is *"1,540 of 3,931 characters on a scene
turn"*. Measured on that exact fixture the block is **1,362** characters (header 78 + list 1,282 + 2
newlines) - which is also the `-1,362` delta the same reconciliation quotes two paragraphs later. The
config readme is the note a future round will trust when it decides whether the block is worth capping.

**Smallest fix:** `1,540` → `1,362` in both places.

### D4 - minor (server) - `<companion_status>` can be emitted with a broken closing tag

`lrgFollowerBlock()` (`lib/lrg_core.php:1439-1440`) applies its 520-character cap with a raw `substr`
on the *finished* string, closing tag included. Measured:

```
npc="Lydia"                          player="Jordan"                      len=437  closed=yes
npc="Jordis the Sword-Maiden"        player="Dovahkiin the Unbroken"      len=503  closed=yes
npc="Aela the Huntress of Jorrvaskr" player="Thane Ysolda-Slayer of Alduin" len=520  closed=NO
    tail: …cannot take anyone else with him at the moment.\n</compan
```

The player name appears three times in the block, so a long character name plus a long follower name
crosses the cap and the model is handed an unterminated pseudo-tag. 503 characters is already reachable
with names that exist in this save.

**Smallest fix:** cap the joined *body* at `max_chars - strlen("<companion_status>\n\n</companion_status>")`
and re-append the tags, or drop whole lines from the end until it fits.

### D5 - minor (docs) - the MCM control count is wrong in two places

`tools/test_mcm_wiring.php` prints `MCM wiring: **113 controls** in config.json`.
`pt9-ship-v051.md` section 1 says "**17** controls listed"; `PLAYTEST9_NOTES.md` section 8 says
"**108** controls" (the 0.5.0 figure). The test's own conclusion ("every one has an ini line and a
reader") is correct either way, so this is a reporting error, not a wiring gap.

### D6 - minor (server, efficiency) - two extra uncached state reads per request

`lrgGetNpcState()` (`lib/lrg_core.php:328`) does a `SELECT` every call and has no per-request memo. The
follower round adds two new call sites that run on requests that previously did no state read at all:
`functions.php:73 → lrgFollowerPolicy() → lrgFolFor()` (brace depth 0, every request, SHARMAT included)
and `context_pre.php:63 → lrgFolFor()`. Worth ~1-3 ms, so not a player-visible cost - but it is a
latency *addition* shipped in the same release as a latency round, and neither build report accounts
for it.

**Smallest fix:** memoise `lrgGetNpcState()` per request in a `static` keyed by npc name (the snapshot
cannot change inside one request), or have `context_pre.php` reuse the array `lrgFollowerPolicy()`
already read.

### D7 - minor (docs) - `PROTOCOL.md` header not carried forward

The version line reads `v0.5.1 (build 0.5.1)`, but the paragraph under it still says *"Everything 0.5
adds is in **sections 10.13 - 10.17**"* and enumerates only the 0.5.0 additions, while the follower keys
live in the new **10.19**. The key table at line 118 does point at 10.19, so nothing is unreachable.

**Smallest fix:** "sections 10.13 - 10.17" → "sections 10.13 - 10.19", and add
`witchim` / `fol` / `fv` to the enumeration.

---

## 6. Claims I checked and found sound (no defect)

* Both prompt trims reverted, completely, and proven by reconstruction rather than inspection (§2).
* The `[0.5.0 / E7(a)]` `requirements` block is kept deliberately; `LRG_GLUE_ACTIONS` really is **five**
  rows (`Take_Up_Business` has its own marker `data/.dlg_actions_v1`), so "five rows reinstalled once"
  is correct and `pt9-ship-v051.md`'s "six glue rows" is a different, also correct, count.
* The pacing config is what the reports say: `skip_when_busy` true, `player_quiet_seconds` 10,
  `server_min_gap_seconds` 45, `gap_exempt_moments ["peak"]`, `max_positions_per_act` 6, and
  `max_positions_total` is absent from code *and* config.
* `test_latency.php`'s five new checks genuinely exercise D10 (admitted-but-not-spoken does not arm the
  clock; a real turn behind it does) and D11 (an untracked NPC gets no memory row).
* The follower verb table, the `never_topics` hard rail and the two-step `commit` on dismiss/home are
  real and asserted against the live 37,561-row index by `test_services` section 6 and flow `d62`.
* `lorerim_glue.log` on the live server still has **0** lines with `mate=1` and **0** with `witfol>0`:
  the "no playtest has ever had a follower present" claim is true, and every follower behaviour is `[U]`.

---

## 7. What is still unknown after this pass

* Everything in Skyrim. This verification is offline plus live-server reads only.
* The exact rail conditions behind the report's 7/3 split (§3). The claim is inside the band my two
  models bracket and the session totals are exact, so I accept it; a byte-exact reproduction would need
  the replay script the reconciliation lane used, which is not in the tree.
* The PocketTTS backend benefit, which remains inferred from the session's bimodal log, exactly as the
  notes say.
