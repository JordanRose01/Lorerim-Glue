# pt8 — RELEASE lane report (v0.4.0 "menuless questing", fix pass)

Role: RELEASE. Confirm the fix pass, run every offline gate, deploy the server, install the mod,
update the owner notes. Appended as work proceeded.

---

## A. Defect confirmation (each one opened in the code)

| # | Defect | Verdict | Where I looked |
|---|---|---|---|
| 1 | emergency hotkey no-op in MANUAL | **FIXED** | `LRG_Dialogue.psc:2031-2040` — `GoManual()`'s MANUAL early return now runs `if NeedsUnhide() / DoUnhide(asWhy)` first. `NeedsUnhide()` (`:2015-2030`) tests `isHidden \|\| guarded \|\| parked`, then `LRG_DlgUI.IsHidden()` (the StorageUtil flag the probe also writes), then the `_x` itself. |
| 2 | read mode 4 on a clickable menu | **FIXED** | three demotions: `LRG_Dialogue.psc:1363` (ReadList, right after the CalibrateRead block), `:1442` (the inherited-mode path in CalibrateRead), `:1887` (StepManual, before the `EntryText(0, readMode)` probe). |
| 3 | probe P11 could not measure ALLOW_PROGRESS_DELAY | **FIXED** | `LRG_Dialogue.psc:1086` reads `apd0` immediately before `GuardReset()`; `:1215` appends `+ " apd=" + apd0` to the `arming sid=…` LogC line. |
| 4 | OStim start / clothing gates had no dialogue test | **FIXED** | new `DialogueBusy()` helper `LRG_OStim.psc:2620-2630`; called in `StartBlockedReason` (`:2647`), in the outside-scene clothing gate (`:3158`) and in `TickControls` (`:971`). Reason string is PROTOCOL 1.6's `a conversation is in progress`. |
| 5 | game half not additive against server 0.3.1 | **FIXED** | `bDlgWire` default line `settings.ini:121`, toggle `config.json:109`, read `LRG_Dialogue.psc:400`, early return in all seven senders (`:2197, :2303, :2324, :2334, :2343, :2375, :2408`) plus `lrg_dlgtalk` (`:1997`) and the two probe presses (`LRG_DlgProbe.psc:941, :988`). `PROTOCOL.md:9-21` is now a SHIPPING rule, not an additivity claim. |
| 6 | `lrgCsv()` undefined → fatal on any Speech perk | **FIXED** | `lib/lrg_dialogue.php:417-420` — the split is inline, behaviour-identical to `lrgCsv()`. Regression: `test_dialogue.php`. |
| 7 | post-LLM gate never matched the real wire name | **FIXED** | `lib/lrg_dialogue.php:1483-1492` — `$norm` strips `_` **and** space, and is compared against `LRG_DLG_GATE_NAMES` (all three shapes). |
| 8 | `want=1` fast path applied no rails | **FIXED** | `lib/lrg_dialogue.php:1398-1412` — scene, session `crit=2`, entry `crit>=1`, `commit`, `walkaway`, class `commit`/`meta`, each with its own log line. |
| 9 | prompt-index layer tier bypassed the risk merge | **FIXED** | `lib/lrg_prompt_index.php:410` — `lrgPromptEscalate($recs[0], $recs)` inside `lrgPromptLayerFor()`. |
| 10 | seven dead MCM controls | **PARTLY** — see section B1 | `bi/rw/io` travel on `ev=open` + `lrg_topics` (`DlgCfgWire()`, `LRG_Dialogue.psc:2319`) and are consumed (`lrgDlgMcm`). `chk/bias/ql` are sent on the **snapshot** (`LRG_Profile.psc:473-484`) but the server read them only out of `lrgDlgFactsFrom()`. `rwd` was sent and read by nobody. Fixed here. |
| 11 | "everyone has a price" implemented twice | **FIXED** | `lrgDlgPrice()/Words()/Remember()` deleted (`lib/lrg_speech.php:591-598` is the tombstone); the only implementation is `lrgPriceFor()` (`lib/lrg_core.php:863`, live at `:1165`); `d40_quests.php:62` asserts `!function_exists('lrgDlgPrice')`. |
| 12 | probe key dead in the session it is armed in | **OPEN** — fixed here | `LRG_MCM.psc` had only the `:Keys` branch. |
| 13 | §3.3's probe grep matches nothing | **OPEN** — fixed here | owner notes. |
| 14 | emergency key could not undo a probe hide | **FIXED** | `LRG_Dialogue.psc:577-582` — `if LRG_DlgUI.IsOpen() && NeedsUnhide()` in the `!IsSessionOpen()` branch, returns true so `LRG_Main` does not Activate on top of it. |
| 15 | ordinary money talk read as paying for sex | **FIXED** | `lrgIntentGoodsTalk()` (`lib/lrg_intent.php:267-290`) + the gate at `:327` (`if (!$hasQuote && lrgIntentGoodsTalk($t)) return null;`). |
| 16 | narrow check recognition, no haggle kind | **FIXED** | `lrgDlgCheckKind()` (`lib/lrg_speech.php:291-…`) — widened intimidate/bribe/deceive patterns, new `barter` kind, `lrgIntentAmount()` reused for spelled-out sums inside a giving frame, money-turn precedence so "everyone has a price" never also runs a bribe check. |

---

## B. What I fixed in this lane (everything else was already done)

### B1 [major, D10 residual] three of the seven MCM controls still reached nobody, and a fourth was read as the wrong thing

The two lanes met in the middle and missed. The GAME puts `chk` / `bias` / `ql` on the ordinary
`lrg_npcstate` SNAPSHOT (`LRG_Profile.psc:473-484`); the SERVER read the MCM keys only out of
`lrgDlgFactsFrom()`, i.e. only from `lrg_topics` / `lrg_dlg ev=*` (`lib/lrg_dialogue.php:428-441`).
Measured: `grep -rn "'chk'" server/` returned **only** the facts parser, and nothing in
`lrgStoreNpcState()` forwards anything into `LRG_DLG_MCM`. So `bFreeChecks`, `bCheckHostility` and
`iCheckBias` still changed nothing - and the carrier could not simply be moved, because a
FREE-conversation check happens in ordinary CHIM talk where no dialogue message is ever sent.

* **Fix (server side only, no game change, no new wire key)**: new `lrgDlgMcmFromSnapshot($npc)` in
  `lib/lrg_dialogue.php` reads the three keys back out of the Phase 1 snapshot row that already
  stores them, with the same clamps and the same "malformed = keep the default" rule, cached per NPC
  per request, exceptions swallowed. `lrgDlgMcm()` gains it as a third tier (globals -> facts ->
  snapshot -> config default), and the `dlg turn` line's `mcm=` now shows it too.
* **`rwd` was the fourth**: the game sends `rw` (bRewalk, a BOOLEAN) and `rwd` (iRewalkDepth) but the
  server accepted no `rwd` at all and used the boolean as the depth, so `iRewalkDepth` did nothing
  and "on" always meant depth 1. `lrgDlgContinuationOk()` now reads `rw` as the on/off and `rwd` as
  the depth, and falls back to `match.cont_depth` when the game sends neither.
* **`PROTOCOL.md` 10.12** corrected: the two carriers are named and the reason each key uses the one
  it uses is written down, `rw` is `0`/`1`, `rwd` is the new `0..4` row.
* **Regression**: `tools/test_dialogue.php` sections 9(c3) and 9(c4), 6 new checks - a snapshot-only
  knob is read back, a malformed snapshot value keeps the default, and the four `rw`/`rwd`
  combinations. The offline seam is `$GLOBALS['LRG_TEST_NPCSTATE']`, inside my own file, so no other
  lane's file was touched.

### B2 [critical, D12] the probe key was dead in the session the owner arms it in

`LRG_MCM.psc` reacted to `:Keys` only, and `LRG_Main.RegisterKeys()` starts with a form-wide
`UnregisterForAllKeys()` that re-registers LRG_Main's four keys and not the probe's. `bProbe:Dialogue`
was not handled at all. So arming the probe did nothing until the next game load, and any hotkey
change silently unbound it - while `PLAYTEST8_NOTES.md` told the owner to arm it and press it in the
same session.

* **Fix**: `LRG_MCM.OnSettingChange` now also fires on `bProbe:Dialogue`, and after `RegisterKeys()`
  it calls `m.GetProbe().Maintenance()` (idempotent, re-reads both `bProbe` and `iKeyProbe`, and logs
  `probe armed, key <n>`). Order matters and is correct: the unregister happens first, the probe
  re-registers after it.

### B3 [minor, found here] probe press 32 could not be selected

`bProbe`'s own MCM help says "press 32 gives it back on demand" and `LRG_DlgProbe.psc:181/260`
implements it, but `iProbePress`'s slider stopped at **31**. Raised to 32 and named in the help.
It is the probe's own escape hatch, so being unreachable mattered.

### B4 [docs, D13 + the five owner-notes defects the other lanes could not reach]

`PLAYTEST8_NOTES.md`, all verified against the code or a real log line:

* **3.3** - the grep was replaced with `grep -E "PROBE P[0-9]+[a-z]?"` and the Windows fallback with
  "every line containing `GAME PROBE`". Measured on realistic lines: old pattern **0** matches, new
  pattern **2 of 2**, `GAME PROBE` **2 of 2**. The line shape is shown so it is unambiguous.
* **3.1** - "the key works straight away, no reload needed" (B2), and the confirmation line to look
  for is named: `probe armed, key <n>`.
* **3.2** - press **16** added as its own row with what it is worth; press **23** corrected to **two**
  presses with a hand re-open between them; the cycle counter resetting on a game load is stated,
  because rows 13 and 14 depend on it; and the free `apd=` measurement on every `arming` line is
  explained (that is the R1 number, design 11 step 0).
* **4.2** - **both** `WOULD CLICK` shapes, with the instruction to grep `WOULD CLICK` and nothing
  more; "one line per conversation, so ten turns means ten conversations"; and the one-extra-LLM-turn
  cost of a first business contact, with the toggle that switches it off.
* **5** - the emergency key now gives back a probe-hidden menu too (D14), and press 32 is named as
  the MCM equivalent.
* **6** - the `read OFF` test is scoped to the four ids it really covers, and the `dlg self-test v400`
  line is given as the one that answers the same question for menuless questing, with
  **`dryrun=0` on a fresh install** called out as the dangerous reading. The speech-check log line is
  written out with what `where=free` and `res=` mean.
* **6 (table)** - `bDlgWire` added with what it is for.
* **10** - the numbers restated from this pass's own runs.
* **11** - NEW: a plain table of what changed since the fast ship, and the MO2 `0.1.0` label
  explained again.

---

## C. The release gate, every command and its output

```
powershell -File glue\tools\compile.ps1
  rounds: 2 ; auto-stubs created: 23
  compiled: LRG.pex, LRG_Dialogue.pex, LRG_DlgProbe.pex, LRG_DlgUI.pex, LRG_Main.pex,
            LRG_MCM.pex, LRG_OStim.pex, LRG_PlayerAlias.pex, LRG_Profile.pex
  OK - compiler reported 0 errors, 0 warnings

python3 tools/esp_dump.py LoreRimGlue.esp                                  (683 bytes)
  QUST 01000800 LRG_MainQuest  VMAD scripts=['LRG_Main','LRG_OStim','LRG_Dialogue','LRG_DlgProbe']
                               alias obj scripts=['LRG_PlayerAlias']       bytes consumed 108/108
  QUST 01000801 LRG_MCMQuest   VMAD scripts=['LRG_MCM']                    bytes consumed 67/67
  HEDR version=1.70 records=3 nextObjectId=00000802   one master, Skyrim.esm

php -l on every plugin + tool file                    61 files, 0 failures
php tools/test_dialogue.php                          112 passed, 0 failed      (106 before, +6 mine)
php tools/test_gates.php                             296 passed, 0 failed
php tools/test_intent.php                            190 passed, 0 failed
php tools/test_phrases.php                            14 passed, 0 failed  (hit rate 91.9%, floor 82%)
php tools/test_scene_index.php                       ALL CHECKS PASSED
php tools/test_prompt_index.php                       67 passed, 0 failed
php tools/test_prompt_index.php --db                  74 passed, 0 failed      <- NOT run by any
    ... source=db, rows=37561, layers=5718, a real lookup hits, an invented line stays unindexed
php tools/flows/run_flows.php --strict
    60 scenarios: 60 passed, 0 FAILED, 0 pending; 924 checks, 0 warnings

MCM cross-check (my own script, both directions):
    JSON OK, pages=7, controls=83, duplicate ids: []
    ini keys=83 ; controls with NO ini default: [] ; ini keys on NO page: []
    settings a script really reads: 83 ; read-but-no-default: none ; default-but-unread: none
    (the 84th hit is Game.GetGameSettingFloat("fAIInDialogueModeWithPlayerDistance"), not ours)

probe grep, measured on realistic log lines:
    old (notes)  grep -E "^.{0,40}(P[0-9]+[a-z]?) "   -> 0 of 2
    new          grep -E "PROBE P[0-9]+[a-z]?"        -> 2 of 2
    Windows      every line containing "GAME PROBE"   -> 2 of 2
```

### C1 deploy

`tools\deploy_server.ps1`, run twice (the second time after my edits):

```
anchor pre-flight: 14 anchors, one match each
scene index: rebuilt, 607 scenes, 5.7s
loaded 37561 prompts, 5718 layers from .../data/prompt_index.ndjson
prompt index v1  source=db  rows=37561  layers=5718  file_rows=37561  built=2026-09-22T06:22:14+00:00
check kinds found in this load order: bribe, intimidate, persuade
unreadable (engine-only): 1                      (Sanguine Symphony.esp, as before)
deployed to /var/www/html/HerikaServer/ext/lorerim_glue
```

* **The FIRST run failed the index load** - `FAILED: no database`, `source=file rows=0 layers=0` -
  because **Postgres was down** (the CHIM stack was not running). I started the distro's own
  `postgresql` service, confirmed the table contents, re-ran the load, and the second deploy was
  clean. Postgres is **left running**; CHIM starts it itself, and `service postgresql start` is
  idempotent, so nothing about the owner's normal start-up changes. Nothing else on the machine was
  started, stopped or configured.
* Index content is unchanged by the rebuild: **hash 6b8e1114** both before and after.
* Verified after deploying: every `.php` / `.json` / `.sql` of the plugin is byte-identical to the
  project tree (20 of 20; the only two differences are `data/scene_index*.json`, which are generated
  caches the deploy is documented not to copy). `manifest.json` version **0.4.0**.
* **No plugin errors in the log.** Nothing error-shaped in `lorerim_glue.log` since 02:00; the last
  lines are the two index rebuilds and the load.

### C2 install

`tools\install_mo2.ps1` **refused**: `Mod Organizer is running. Close it first`. Skyrim was **not**
running (`Get-Process` showed `ModOrganizer` only, pid 42744, and no `SkyrimSE`). Under the
authorisation for that exact case I copied **our own files only** into
`F:\Modlists\LoreRim\mods\LoreRim Glue`, by SHA-256 compare, copying only what differed:

```
22 files considered:  LoreRimGlue.esp, Seq\LoreRimGlue.seq, Scripts\LRG*.pex (9),
                      Source\Scripts\LRG*.psc (9), MCM\Config\LoreRimGlue\{config.json,settings.ini}
18 replaced (9 .pex, 6 .psc, config.json twice, settings.ini) ; 4 already identical
final verify: 22 files, mismatches=0
```

* `LoreRimGlue.esp` was already byte-identical (the fast ship had already installed the four-script
  version) - **verified by hash**, not assumed.
* **No profile file was touched.** `profiles\Ultra\*` timestamps are unchanged (newest is
  `plugins.txt`, 2026-09-21 22:18). `+LoreRim Glue` is already line 2 of `modlist.txt` and
  `*LoreRimGlue.esp` is already the last line of `plugins.txt`, from the earlier install.
* **No other mod, no `meta.ini`, no Nemesis, no LOOT.** The MO2 version label therefore still reads
  `0.1.0`; that is in the owner notes (section 11) with the one command that fixes it.

---

## D. Not done, and why

* **In-game T2 (dump parity on 10 NPCs) and T3 (dry-run WOULD CLICK)** - the owner's evening. Both
  are fully specified in `PLAYTEST8_NOTES.md` 4.1 / 4.2.
* **`install_mo2.ps1` itself was not run to completion**, so `meta.ini`, `modlist.txt`,
  `plugins.txt` and `loadorder.txt` were not written and no `*.bak-<stamp>` backups were made. The
  first three already carry the right entries; only the version label is stale.
* **Moving `lrg_prompt` / `lrg_prompt_layer` to their own schema** (the deeper fix for a CHIM
  playthrough switch dropping the Phase 2 tables) - unchanged from the server lane's position: it
  touches `migrations/006`, the loader and `deploy_server.ps1` together, and the table probe added
  this round makes the failure loud and recoverable instead. Left as recorded.
* **No C++ / SKSE / SWF tooling was installed or touched**, and nothing needed it.
* **PROTOCOL 10.4 `res=`** (read by no Papyrus `ParamGet`) - confirmed harmless from both sides by
  the two build lanes; not re-opened here.

---

## E. Still open after this pass (nothing critical or major)

1. **The seven-controls item is closed on the wire, but only the server was changed for `chk` /
   `bias` / `ql`.** The game already sends them; if a future round ever wants them per-NPC rather
   than global, they will need a real dialogue-message carrier. Today's tier reads the newest
   snapshot for that NPC, which is what "an MCM slider" means anyway.
2. **`lrg_prompt` / `lrg_prompt_layer` still live in `public`**, so a CHIM playthrough switch drops
   them. The table probe added this round makes it loud and recoverable; a schema of their own is
   still the deeper answer (migrations/006 + loader + deploy together).
3. **9 PNAM-inverted check topics** in this load order (Borgakh, Ghorbash, Esbern, CWMission03,
   ccKRTSSE001, aaJenassaDBD3) - upstream data bugs, decision D8 is report-only. Section 9 of the
   owner notes carries the two the owner needs to know about.
4. **1 plugin unreadable** (Sanguine Symphony.esp, an engine-only compression) - unchanged, and it
   contributes no dialogue we need.
5. **Nothing has been run in the game.** Every claim in this report is from code, from an offline
   test, from the deployed server, or from a hash.

## F. What the owner has to do

1. Start the game normally - the mod is installed and the server is live. Nothing clicks anything.
2. Section 2 of `PLAYTEST8_NOTES.md`: the five settings that are NOT ours, especially Smart Talk's
   `bSkipImmediateOnInput = 0` / `bHoldToSkip = 0` and IACC's `bForceFirstPerson = 0` /
   `bHideDialogueMenu = 0`.
3. Bind the emergency key (Keys page) before anything else.
4. The probe evening, section 3 - the key now works in the same session you arm it.
5. The dry-run conversation test, section 4.
6. Optional and cosmetic: close MO2, run `tools\install_mo2.ps1` once, so MO2's left pane says 0.4.0
   instead of 0.1.0.
