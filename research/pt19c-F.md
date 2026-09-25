# pt19c-F - Lane F (docs, integration, release gates), menuless questing v1.0

Implementer notes for `research/pt19-menuless-v1-spec.md` rev 2, Lane F (section 2.2 is the authority), with the
orchestrator's corrections (script 513, PROTOCOL 10.29 in place of the spec's "10.27"). Built on the tree as Lanes A-E,
their fixers and the walkthrough fixer left it (last code change 2026-09-25 01:23; nothing in code changed while this
lane worked - checked by manifest). **No code was edited, nothing was compiled, deployed or installed**, and
`LRG_Main.CurrentVersion` was not touched. Backups: `glue/.backup/pt19c-F/{PROTOCOL.md,README.md,V05_EXPANSION_PLAN.md}.bak`
(the owner page is new).

**Fixer round 1 (2026-09-25, section 9 below):** every reviewer item is resolved in the lane's files or answered with
evidence; the owner page, PROTOCOL (1.6, 10.6, 10.29 sections 4-8 and 13, the banner), README (keys table, section 3,
section 10) and V05 27.2 / 27.3 were changed again; still no code, test, config or script file was touched, and nothing
was compiled, deployed or installed. Backups of that round: `glue/.backup/pt19c-Ffix/*.bak`. Sections 1-8 below are the
implementer's record; where section 9 changes them, section 9 wins.

## 1. Files

| file | change |
|---|---|
| `glue/OWNER_MENULESS_V1.md` | NEW. Spec section 5 with the capability map's section-8 corrections (addressed to Lane F) and the corrections the code forced (section 2 below), plus spec section 6 (the four open decisions, in septims) and a table of where every sentence was checked. 249 lines, ASCII, LF |
| `glue/PROTOCOL.md` | 2011 -> 2367 lines. Title + v1.0 banner; 1.6 (the new closed-list reasons, `gave <n> septims`); 10 (the rails paragraph: the session is visible); 10.1 (`sj= drv= hc=1`); 10.2 (`ev=open` keys, `ev=stopped`, `auto=1`, `reset=1`, `unhide` not sent); 10.3 (`lrg_dlgtalk` never requested); 10.4 (`res=` empty + THE v1.0 WIRE TABLE: `adv= rearm=1 amb=1 give= res=` / `sj sq sqj drv hid quiet` / `hc` / `auto=1` / `ev=stopped` / `reset=1` / `k=` rows / `gave=` / retired messages and keys / `clicks_ok`); 10.5 (`give=`, gate B); 10.12a (retired carriers `bi rw rwd qi qig`); 10.13 (four rows, route by doing, Forget clears `clicks_ok`, the stage rail, no automatic session, `ev=resume` / `ev=unhide` retired; the pt18 E1f paragraph marked RETIRED); 10.15 crime (`iCritical` / `bCrimeManual` retired); 10.16 (W5 / W6 / W7 and `<she_may_raise>` retired); 10.24 (ambient narrowed and openable on the five clauses, `amb=1`, `cal=` 0-4); 10.26 (the changed condition, `open-first`, fallback until the open is proven, gate B's deletion rule); 10.27 (the 'buying' calibration candidate is gone); 10.28 (quiet mode: the automatic calibration hooks and the emergency key gone, sessions read-only while quiet, the real QUIET log text); **NEW 10.29 THE VISIBLE MENU, VOICE-DRIVEN** (13 parts: what changed, who drives, the server's view, calibration / route / stage rail / dry run, opening, classes and the three releases, the line-end grace and the talk key, voiced failures, rewards, config, MCM, log, tests, release gates) |
| `glue/README.md` | 405 -> 506 lines. Title, a v1.0 status box (version markers, state: gate A not green), section 1 (menuless v1.0, arrests, the retired pt18 calibration note, quiet mode's text), section 2 (the driver / UI / probe / MCM rows, `lrg_dlgtalk`), section 3 (what is live in v1.0), section 4 (98 controls + 3 text rows; the Keys, Menuless questing, Calibration and Services rows; **the keys table** and **the Menuless questing controls table** with exact MCM labels, ids and ship values; the list of removed controls), section 5 (89 scenarios, the questline harness, the owner page), section 7 (the v1.0 integration table, red rows named with their owners), section 8 (Smart Talk no longer tied to a dry run that clears itself), section 9 (nothing hidden; arrests never picked; no emergency key), section 10 (v1.0 not seen in game; the known offline limits) |
| `glue/V05_EXPANSION_PLAN.md` | PART III, section 27: the v1.0 addendum (what v1.0 supersedes in this plan, what it keeps, what is new, the release gates) |
| `tools/deploy_server.ps1`, `tools/install_mo2.ps1` | unchanged: no path changed. deploy copies `server/lorerim_glue` whole (robocopy /MIR, then `cp -r`), so the NEW `config/lrg_dialogue_overrides.default.json` ships and gets its CRLF strip like every `.json`; no server file was deleted. install copies `game/LoreRimGlue` whole (robocopy /E); no game file was added or deleted (the retired MCM ids live inside config.json / settings.ini, which are replaced) |

## 2. The owner page - where it departs from spec section 5 "verbatim", and why

Every change is either a capability-map section 8 row addressed to Lane F, or a claim of the spec text that the code
makes false (never-false). The language brief's section 6 rules were applied: septims everywhere, "gold" nowhere, no
machinery words in the prose (the only ones left are on-screen text - the MCM labels, "Mod Configuration", the
Calibration page's name - and the quoted log strings); a refusal names what cannot happen and what to do.

| spec 5.x | as the spec wrote it | on the page | why |
|---|---|---|---|
| 5.1, 5.2, 5.4, 6.4 | "first simple question / first real click of the evening" | "... on this install" (and again after "Forget everything it learned") | `clicks_ok` lives on the server's `*install*` row and the route proof in the install file; both persist across evenings (capability map 8) |
| 5.1 | "There is no key to remember" | "There is no new key to remember" | 5.2 / 5.5 name the talk key (capability map 8) |
| 5.2 trade | "before it she asks for one" | before the first pick: she tells him to ask something simple first - or, when no list of hers is known, she answers and her trade window opens after her line | F19: at `clicks_ok 0` the kind open is refused; with a cached list the stage rail line speaks, with none 10.15's direct barter opens CHIM's window (`lrgDlgServiceDirect`, `real entry` vs no entries) |
| 5.2 / 5.3 (4) rooms | "I'd like a room" - no question under 100 septims; `do=pick cost=10` | "I'd like a room for the night": the room line, then one day, **25 septims**; asks at 100 septims **or a quarter of the purse**; "I'd like a room" alone leaves the days to him; log: two `emit ... do=pick` lines, the second `cost=25` | Xtended Stay owns the room line on this install and a days list follows (U3, FE.hulda.room.chain: purse 300 picks, purse 80 asks - F29); the room is 25 septims (10.27) |
| 5.2 | (the Whiterun gate missing) | a gate bullet + step (5a): "Riverwood calls for the jarl's aid"; never "I have news from Helgen..." at the gate | at the gate that sentence is the PERSUADE line and can fail (MQ102.gate.persuade; capability map 8) |
| 5.2 / 5.3 (5b) | "Irileth at the Whiterun gate" | "Irileth, when she stops you inside Dragonsreach" | her MQ102 intro is at the Dragonsreach door (FE.irileth.gate, `sq=MQ102`); the gate is the guard's |
| 5.2 choices | "anything over 100 septims: she asks once unless you already said it plainly" | an irreversible line asks once unless said plainly; anything at 100 septims or a quarter of the purse ALWAYS asks | S4.3: explicit is never true for cost >= `confirm.min_gold` or the purse fraction (S4.10: "always asks") |
| 5.3 intro | log files `Data/SKSE/Plugins/LoreRimGlue/lorerim_glue.log` and the server log | ONE file, the server's `HerikaServer/log/lorerim_glue.log`; the game's lines start `GAME` | `LRG_Main.LogAt` sends every game line to the server as `lrg_log` (written `GAME <msg>`, `lrg_actions.php:325`); the game writes only Papyrus traces at level 2 |
| 5.3 (1) log | `cal k=... fam:1 st:1 cm: rm:` | `GAME CALIB GREEN 4 of 4 learned` (first time) and `calib npc=Hulda ... gate=1` | the real strings (`LRG_DlgProbe.psc:221`, `lrg_dialogue.php` OnCalib `calib npc=%s src=%s gate=%d miss=%s k=%s`) |
| 5.3 (2) | "the line is picked in front of you" | + "then she asks who you are - answer, or press Tab" | her follow-up list (capability map 8; FE.hulda.plain.followup / refugee) |
| 5.3 (2) log | `result ok=1 clicks_ok=1` | `result npc=Hulda ... ok=1 ... clicks_ok=1` | the real line has other keys between (`lrg_dialogue.php:1334`) |
| 5.3 (2b) log | arming `sq=BardAudienceQuest sqj=0` | arming `sqj=0 sj=0 drv=1` (her scene's quest - the bard's audience or her own tavern patter - is named but not predicted) | FE.hulda.engine runs `sq=DialogueWhiterunBanneredMareScene3`; which quest owns her scene while the bard sings is not knowable offline; `sqj=0` is what matters |
| 5.3 (3) / (5b) / (6) log | `clicked pos= mode=kind`, `clicked pos= ... mode=explicit` | `emit npc=<npc> do=pick mode=kind|explicit` (server) + `GAME clicked pos=` (game) | the game's click line carries no `mode=`; the server's `emit` line does (measured per sentence with `test_questline --trace`: trade `mode=kind`, Irileth / Kodlak / the gate `mode=explicit`) |
| 5.3 (5) log | `clicked pos=` | `emit npc=Faralda do=pick mode=faction` | measured: "may I enter the College" resolves through `lrgFacArbitrate`'s exact join line, emitted as `mode=faction` |
| 5.3 (8) | "Alvor and Gerdur are inside the main quest's scene and their lists stay yours until that first click" | "Hadvar said you could help me out" is on Alvor's list and simple; his supplies line waits for the first pick; log `sq=MQ102A sqj=0 sj=0 drv=1` | false by S1.1's own test: MQ102A has no journal row, so `sj=0` (capability map U9, Lane E 6) |
| 5.3 (8) | - | "please note how many lines Sven's list has (three)" | Lane E section 7 item 4: the offline index carries no conditions; a fourth line voids FE.riverwood.plain's measurements |
| 5.3 | - | "if your save starts somewhere else": Vilod in AP's Helgen, Corpulus in Solitude | the owner's save is a new Alternate Perspective game in Helgen (capability map 7); both sentences are verified (section 4 below) |
| 5.2 | - | "Where it is still clumsy in v1.0": paraphrased quest lines that ask once (Alvor's supplies); rides and training open after her line | measured red rows of the harness (@merge) and U1's empty `open.kind_factions` - never-false toward the owner |
| 6.3 | "a farmhand 40, a merchant 100, a jarl's court 500" | "40 septims ... 100 septims ... 500 septims" | septims (capability map 8) |
| 6.4 | "one server config line, `session.drive_scene = false`" | `"dialogue": {"session": {"drive_scene": false}}` in `config/lrg_config.json`, no rebuild | the real key path and the owner's override file |
| 5.5 | the three labels | + "Guards, arrests and bounties: always yours to click" (a text row), "NPCs inside a quest scene" = "Unless a journal quest owns it", the Calibration page's two items with their real status strings | exact MCM labels as built (Lane D fix 2 retired `iCritical` / `bCrimeManual` for the text row) |

The page's service sentences carry `mode kind (<kind>)` in section 7's first table: `tools/test_services.php` section 8
parses them (Lane B's hand-off) - 99 passed. The regex `"([^"]{3,80})"...` spans newlines, so a service row must not
follow a row that ends in a quoted sentence within 80 characters (the first draft did, and a false capture failed the
test); the service rows therefore sit in their own table after a quote-free paragraph.

## 3. Integration - the staged run on WSL, twice (spec 2.1)

Scripts: `%TEMP%\lrg_test\pt19c-F_stage.sh <run>` (Git Bash: stage `glue/` without `.backup`, write an md5 manifest)
then `pt19c-F_run.sh <run>` (WSL: copy the live `data/`, `php -l` every `.php`, the JSON checks, every suite, the
questline harness in five modes, the flows). Outputs: `%TEMP%\lrg_test\pt19c-F\<run>\out\*.txt`, logs `<run>.log`.
run0 = the baseline before any docs change; run1 / run2 = the first docs draft (test_services red on the page, section
2); **run3 / run4 = the final tree** (identical code manifest to run0; they differ from each other only by one prose
sentence of the owner page that no test reads). run3 and run4 gave identical results:

```
== run4 (staged 2026-09-25 02:03) and run3 (02:01:55): the same lines
php -l: 97 files, 0 with errors
lrg_config.default.json / lrg_dialogue_overrides.default.json / MCM config.json / both questline fixtures: ok bom=no crlf=no
test_gates            789 passed, 0 failed
test_intent           459 passed, 0 failed
test_phrases           47 passed, 0 failed
test_dialogue        1008 passed, 0 failed   ALL CHECKS PASSED
test_prompt_index      99 passed, 0 failed   ALL CHECKS PASSED
test_mcm_wiring        49 passed, 0 failed   ALL CHECKS PASSED   (98 controls, each with an ini line and a reader)
test_services          99 passed, 0 failed   ALL CHECKS PASSED   ("[note] owner page read"; run1/run2: 98/1 - section 2)
test_scene_index       ALL CHECKS PASSED
test_latency_prompt    MEAN per turn 2306 chars / 576 tokens (10 fixtures)
test_latency           34 passed, 0 failed   (6b per sentence: warm 0.95 / 0.94 ms avg, cold 1.06 / 1.08 ms avg; timings vary per run)
test_stt               60 passed, 0 failed
test_questline                 1811 passed, 145 failed, 0 known   RESULT: FAILED   (every failure a `known` entry, owner named)
test_questline --first-evening  221 passed,   1 failed, 0 known   RESULT: FAILED   (FE.riverwood.plain never line, spec owner)
test_questline --words         2049 passed, 145 failed            (totals: ask 1, breath 24, nothing 721, resolve 724)
test_questline --stage=B         39 passed,   0 failed            ALL CHECKS PASSED
test_questline --allow-known   1811 passed, 0 failed, 145 known   NOT A RELEASE GREEN
run_flows             89 scenarios: 79 passed, 9 FAILED, 1 pending; 1596 checks, 0 warnings   RESULT: FAILED
                      FAILED: d23 d29 d30b d31 d33 d33s d44 d56 d57 (pre-v1.0 assertions); PENDING: d68 (gate B)
walkthrough supplement (--first-evening --fixture=pt19c-walk/walk_fixture.json): 182 passed, 12 failed - the walkthrough's
                      own expectations (W.corpulus.plain rail flag x4, W.irileth.B1, W.trainer layer, W.open.balgruuf marker,
                      W.open.anyone) and U1 (W.open.driver x2, W.open.trainer x2); none is a sentence of the owner page
```
The 145: MQ103.balgruuf.blocking 16, DB02.captive.who 16, MQ106.delphine.mound 15, MQ106.delphine.go 15, MG01.learn 15,
DB01.aventus.ok 15, MQ102A.alvor.help 14, TG00.brynjolf.chain06 9 (@merge: Lane A, or a ruling on `lrgPromptEscalate`),
MQ102.balgruuf.intro 15, MQ105.arngeir.summons 15 (@spec_row: the spec owner). Compared with Lane E's last run (139
known): Irileth (FE.irileth.gate, MQ102.irileth.news - the merge-lent persuade kind), C00.kodlak.handle and the
@check_park, @assent_sure, @negation, @single_neg and @filler_choice lines no longer fail after the walkthrough fixer (the
fixture's `known_reasons` now name only `merge` and `spec_row`); the fixture now carries 15 paraphrases per beat, hence
the larger per-beat counts. The same code manifest as run0 (before any docs change): the
docs lane changed no test outcome except test_services, which now reads the owner page.

## 4. The owner page's sentences, verified (the proof lane F owes: 3.7 and `--first-evening`)

- `--first-evening` (the FE beats): every sentence the page tells the owner to say passes on its NPC - Hulda's plain
  line (cold `marker=toplevel`, warm `marker=root`), her follow-ups, the trade line (`marker=kind`, `mode=kind`), the room
  (one sentence, two clicks, 25 septims, asks at purse 80), the days slot, Sven's ballads line, Alvor's Hadvar line,
  Irileth's news line (`mode=explicit`, the merge-lent persuade kind no longer attached - the walkthrough fixer's F3),
  Kodlak's join. The single red line is FE.riverwood.plain's NEVER line ("sing me something about dragons" is picked,
  s=0.610) - a spec-owner ruling, and a sentence the page never tells him to say.
- The main fixture: MG01.faralda.enter (`mode=faction`), MQ102.gate.note, MQ102.gate.persuade, C00.kodlak.join,
  CW01A.oath.1 / oath.4, MQ102A.alvor.hadvar / alvor.help (the verbatim line clicks; paraphrases ask - @merge, named on
  the page as "still clumsy") all pass or behave as the page says.
- The walkthrough's supplement fixture (`%TEMP%\lrg_test\pt19c-walk\walk_fixture.json`, `--first-evening
  --fixture=`) on the final tree: W.vilod.plain passes; W.corpulus.plain's lines are picked (its four red rows assert a
  "rail" flag the line does not carry - the walkthrough's own wrong expectation, as its section 7 says); the other red
  rows are U1 (rides / training open after her line) and harness limits, none of them a page sentence.

## 5. Release gate A (spec 2.4) - the checklist, as of this run

| gate A item | state | owner / next step |
|---|---|---|
| every suite green twice on the final tree | **RED** on two: `test_questline` (145 known) and `run_flows` (9 legacy flows); every other suite green twice | see the two rows below |
| `test_questline.php` green over the live index | **RED**: 115 lines @merge on MQ103.balgruuf.blocking, DB02.captive.who, MQ106.delphine.mound, MQ106.delphine.go, MG01.learn, DB01.aventus.ok, MQ102A.alvor.help, TG00.brynjolf.chain06 - Lane A, or the spec owner's ruling that `lrgPromptEscalate` be scoped to the line's own topic (Lane E measured: all resolve with the merge scoped); 30 lines @spec_row on MQ102.balgruuf.intro and MQ105.arngeir.summons - the spec owner (grade by the live INFO, or re-class the 3.6 rows) | Lane A / spec owner |
| `--first-evening` green | **RED** by one line: FE.riverwood.plain's never line - the spec owner's ruling (the harm is low: a plain question gets picked) | spec owner |
| the `sc` beats at both `clicks_ok` values | green (scene beats run at 0 read-only and at 1 driven, inside the 1,811 passes) | - |
| `run_flows.php` | **RED**: d23 d29 d30b d31 d33 d33s d44 d56 d57 assert pre-v1.0 behaviour (Lane A 4.3's rebase list; Lane E owns new files only) - they need an owner; d68 PENDING is correct (gate B) | orchestrator: assign the rebase |
| `test_mcm_wiring.php` green | green (49/0) | - |
| `compile.ps1` clean (no .pex string > 500, no None cast to a typed array) | NOT RUN by this lane (implementer rule) | Build stage |
| settings: `bDriveSceneMenus = 1`, `session.drive_scene = true`, `iKeyPushToTalk = 29`, `checks.reward.enabled = false` | verified in `settings.ini` and `lrg_config.default.json` (and `bMenuless = 1`, `bDlgDryRun = 0`, `bAutoAdvance = 1`, `iSceneGate = 1`, `iTailMax = 16`) | - |
| the owner's saved MCM values cannot mask the new defaults | checked read-only: `F:\Modlists\LoreRim\overwrite\MCM\Settings\LoreRimGlue.ini` holds only `[General] bDryRun = 0` | re-check at install time |
| deploy + install with backups | NOT DONE (the orchestrator's) - no script change needed | orchestrator |
| docs: PROTOCOL 10.29 and the 10.x updates, README keys / MCM tables, V05 addendum, the owner page | done | - |
| the memory note after the owner's first evening | NOT DONE - the evening has not happened | after the evening |

**Gate B evidence to collect on the first evening** (PROTOCOL 10.29 section 13): `clicked pos=` + a `result` line with
`ok=1 ... clicks_ok=1` on a free-standing NPC; `CALIB set route src=live`; `GAME clicked pos= origin=engine sj=1` at
Irileth with arming `sq=MQ102 sqj=1`; a `closed why=goodbye` after a driven click; the four oath lines whole; the count
of engine-opened sessions. Gate B also needs Lane E's finding 5.1 fixed first (the never-false `reward` class misses a
bare amount; d68 is 13/15 with gate B forced on).
[fixer round 1] **Also collect the CHIM checks** (PROTOCOL 10.29 section 13 (a)-(e); keep that session's
`AIAgent.log` beside `lorerim_glue.log`): (a) the pre-LLM `do=open` emit's `x=` against AIAgent.log `Pushed
command,1,ExtCmdLRG_SelectTopic@...x=` (D2 latency mid-request); (b) AIAgent.log `Queueing new line - Actor:` /
`Starting DownloadAndPlay` against `GAME clicked pos=` (the audible order at first contact) and the owner's report of
what he heard; (c) `Generation stopped because user_input`, (d) `Audit:Lock acquired by funcret` beside the next
`inputtext` lock, (e) `Function not found for` - (c)-(e) in `HerikaServer/log/chim.log`. And Sven's list must have
exactly three lines (owner page step 3).

## 6. What the reviewers must check

1. The owner page against the code, sentence by sentence: section 2's table gives the source of every departure; the
   log strings were taken from the code (`LRG_Dialogue.psc:1494, 1756, 2653, 3236`; `LRG_DlgProbe.psc:199, 221`;
   `lrg_dialogue.php` `emit` / `result` / `calib` / `open marker=` formats) and the modes from `test_questline --trace`.
2. PROTOCOL 10.4's wire table against `lrgDlgEmit` (`adv=` / `rearm=1` / `amb=1` after `svc=`), `lrgDlgAwardLine`
   (`give=`), `LRG_Dialogue.SendOpen` / `SendStopped` / `SendTopics` and `DlgCfgWire` (only `io` left).
3. PROTOCOL 10.29 section 3 says the stage rail's corner note goes out as `kind=hint` (so bQuestHint can silence it):
   that is the code today (`lrgDlgRailNote`, lrg_dialogue.php ~4974) - Lane C's `kind=rail` branch (LRG_Main:1771) is
   unused until Lane A sends `kind=rail`. Documented as is, flagged in 7.
4. README section 3 / 4 values against `settings.ini` and `config.json` (98 controls and 3 text rows by
   `test_mcm_wiring`'s own count).
5. That nothing in the docs promises a retired thing as current: `grep -n "emergency\|bCalibActive\|iCritical"` in the
   four files hits only lines marked RETIRED / removed / history.

## 7. Hand-offs and open items (each with its owner)

- **Lane A (or the spec owner):** the 115 @merge lines (scope `lrgPromptEscalate` to the line's own topic, measured by
  Lane E to resolve all of them); `lrgDlgRailNote` should send `kind=rail` (Lane C's P5, still open).
- **Spec owner:** the @spec_row rulings (MQ102.balgruuf.intro, MQ105.arngeir.summons, FE.riverwood.plain's never line);
  S4.4's oath tail ("yes, long live Ulfric" on the Legion oath - Lane E's probe).
- **Orchestrator:** an owner for the nine legacy flows; compile, deploy, install; the Sven check and the gate-B evidence
  on the first evening; the memory note after it.
- **Lane B (before gate B):** Lane E's finding 5.1 (the never-false `reward` class).
- **Lane B (wording, walkthrough W1):** `lrgVoicedWhy` still voices "that did not take - choose it on the menu" and "I
  did not catch what we could talk about - choose it on the menu yourself" (lrg_actions.php:1354-1355), which the
  capability map asked to lose "menu". Not a docs file; the owner page does not quote them.

## 8. Not done, and why

- `compile.ps1`, deploy, install: the implementer role forbids them here (the orchestrator's task text); no path
  changed, so the deploy and install scripts need no edit.
- The memory note update: spec 2.2 ties it to the owner's first evening, which has not happened.
- Making `test_questline` and `run_flows` green: the red lines are in Lane A / Lane E / spec-owner territory (section 5);
  Lane F edits docs only.

## 9. Fixer round 1 - every reviewer item, resolved or answered

Files changed in this round (all docs; backups `glue/.backup/pt19c-Ffix/*.bak`): `glue/OWNER_MENULESS_V1.md` (249 ->
346 lines, ASCII, LF), `glue/PROTOCOL.md` (2367 -> 2473), `glue/README.md` (506 -> 524), `glue/V05_EXPANSION_PLAN.md`
(2171 -> 2176), this file. Verification of every NEW sentence the page tells him to say: the extra run
`%TEMP%\lrg_test\pt19c-Ffix\fix_fixture.json` (`test_questline.php --first-evening --fixture=`), its `F.` beats written
out in the appendix below so they survive Temp; the walkthrough fixture `%TEMP%\lrg_test\pt19c-walk\walk_fixture.json`
re-run beside it.

### 9.1 Resolved in the lane's files

| reviewer item | what changed | evidence |
|---|---|---|
| [game] P1 - "nobody asks 'do you mean it?' unless you say you are leaving" is false | the "Delphine's room" bullet is now "Quest conversations": said as written (or very close) a line is picked at once; said loosely ("let's go", "Kynesgrove mound") she may ask once, quoting it, and "yes" is enough; a walk-out line ("I don't have time for this") only as written. It no longer contradicts "Where it is still clumsy" | extra run: F.delphine.mound "I know that mound - high on the hill east of Kynesgrove" -> explicit, "Kynesgrove mound" -> park; F.delphine.go "Let's go kill a dragon" -> explicit, "let's go" -> park; run4 / r3 / r4 MQ106.delphine.mound / .go 0/15 each (@merge). **Re-sync both bullets when the lrgPromptEscalate ruling lands** (scoped: several named lines stop asking) |
| [game] P2, [use] Path C - section 3 does not fit a new AP game in Helgen and stops at Irileth | section 3 now leads with the path his save reaches: (1) Vilod in AP's Helgen (the install's first pick, with the calibration note), (2) quiet mode at the dragon, (3) Riverwood - Sven; Alvor after Hadvar (+ his "how do you know Hadvar" answer, + the supplies line as written); Gerdur after Ralof (+ her answer), (4) the gate, with the first-pick case (he says the U7 sentence and leaves it to the click), (5) Irileth, (6) Balgruuf (his intro as written, the Helgen line, the reward single that WAITS for "what else can I help with" / "yes"), (7) Farengar (the open, the assignment single that waits for "what do you need done?" / "yes", the tablet turn-in), each with its log line. Hulda, Corpulus (+ the "You kept a skeever as a pet?" follow-up), Faralda (+ her "what do you expect to find within" answer), Kodlak and the bounty follow as "later, or on a save that starts in a town" | main fixture green in r3 / r4: MQ102.balgruuf.helgen, .reward, MQ103.farengar.intro, .assignment, .turnin, MG01.faralda.seek, MQ102.gate.note; extra run F.gerdur.ralof (0 and 1), F.open.gerdur (toplevel @0), F.gerdur.answer, F.alvor.answer, F.alvor.help, F.balgruuf.intro (read-only @0, explicit @1), F.balgruuf.helgen, F.balgruuf.reward ("yes" -> single; "no, nothing else" -> nothing), F.open.farengar (qrows / toplevel), F.farengar.intro, F.farengar.assign ("yes" -> single; "uh, what now" -> nothing), F.farengar.turnin, F.faralda.seek, F.gate (@0: nothing clicked, the rail sentence true), F.corpulus.pet |
| [code] 4 / [game] P2(c) - Gerdur missing | named, with her follow-up answer | F.gerdur.ralof 4/4 + never 1/1, F.open.gerdur 1/1, F.gerdur.answer 1/1 (index: `skyrim.esm:052CB0` "Ralof said you could help me out." TL, scripted 0, MQ102B journal 0) |
| [game] P4 - "still clumsy" understates; Alvor's supplies row cites a red beat | "still clumsy" now names the measured beats in the owner's words (Balgruuf's intro and his "I need to talk to you", Arngeir, Delphine x2, Alvor's supplies, Aventus, the DB02 captive) and adds Brynjolf's "What do I have to do?" (it waits now). Section 7 marks each such row "picked at once when said as written" and names the red beat beside the extra-run beat that proves the verbatim line | run4 / r3 / r4: MQ103.balgruuf.blocking 16, DB02.captive.who 16, MQ106.delphine.mound 15, .go 15, MG01.learn 15, DB01.aventus.ok 15, MQ102A.alvor.help 14, TG00.brynjolf.chain06 9, MQ102.balgruuf.intro 15, MQ105.arngeir.summons 15; the verbatim lines: F.alvor.help / F.balgruuf.intro / F.delphine.* explicit; the fixture notes give explicit/pick for "I am answering your summons", "Are you all right?", "Who are you?", "I need to talk to you" |
| [game] P5 - rides / training after her line is unmeasured | softened to what is measured: they do not open before she answers; what happens after is "not checked yet" - her list may come up, or it may go as before v1.0; please report which. README section 10 says the same | W.open.driver / W.open.trainer red on U1 (`open.kind_factions` empty); no suite covers `lrgDlgMaybeOpen` on a carriage or train item |
| [game] P6 - "never promise" is not protected in v1.0 | section 4 "A different reward" no longer says "never": what a quest pays is fixed, she is told so when he bargains, and in v1.0 nothing checks her words afterwards - a promised house, title or septims is a slip, nothing is paid, send the log. "Extra septims": "she should only refuse". PROTOCOL 10.29 section 8 gains the v1.0 gap; README section 10 too | `lrg_replies.php` ~712: `$rows['reward']` only while `checks.reward.enabled` (ships false). The fix is Lane B's / the spec owner's (section 9.3) |
| [game] P7(b) - the rail note rides `kind=hint` | owner page section 5: "Tell me when she has something" is on - leave it on for the first evening, the "not picked yet - ask something simple first" note rides on it | `lrgDlgRailNote` (lrg_dialogue.php ~4956-4976) sends `kind=hint`; `LRG_Main.psc` ~1770 gates `kind=hint` on `bQuestHint:Quests` (settings.ini:215 ships 1). The code fix stays Lane A's |
| [chim] 10.6 - D2 "5 s poll", "11-14 s", "ALWAYS both routes" | 10.6's heading says "two exceptions"; the 5 s and the 11-14 s sentences are struck as withdrawn; a [v1.0] bullet states the dequeue on every poll (`comm.php` :369-372) against the sampled eventlog row (:374-376), the observed 0.12-0.53 s, "mid-request UNPROVEN", and the two single-route commands (the pre-LLM open D2 only, QuestEntry D1 only). 10.29 section 4 repeats the D2-only fact; V05 27.2 says "D1 + D2, with two single-route exceptions" | read-only: `HerikaServer/processor/comm.php` 367-376 (`DataDequeue(time() + 1)` then `if (time() % 5 == 0) logEvent`); `lrgDlgPreOpen` `lrg_dialogue.php` ~2417-2424 (`lrgDlgEmit(...)` return value unused, log `do=open queued (D2)`) |
| [chim] 1.6 - "SPOKEN in the same exchange" does not hold for SelectTopic | 1.6 gains a [v1.0] paragraph with the three deliveries (a synchronous game refusal = corner note only; a loop outcome / a skipped gate refusal = her words on his NEXT turn; only the foreseen clauses in the same turn); 10.29 section 7 is retitled "Failures are words - but not all in the same exchange" and adds the MAIN-lock cost; the owner page's bullet now says: known before she answers -> her words; a game refusal -> the corner note at once, and if the game found out only after trying, she tells him the next time he speaks. V05 27.3 qualified | `lrg_dialogue.php` ~5863 `followup.enabled => false`; `lrgFuncretVerdict` `lrg_actions.php` ~1070-1071 `pass`; `lrgDlgTopicFuncret` ~912 (open refusals and award only); `LRG_Dialogue.psc` 1017 / 1028 / 3060 `Error: choose that one on the list yourself`; `lrgDlgGroundTruth` "Nothing came of it" ~2947 / ~2981 |
| [chim] the talk key (owner page x3, README keys table, 10.29 section 6) | owner page section 2 (the oath): hold it and speak; a quick tap is CHIM's own hush; a double tap makes the person you look at wait; section 5 Keys page: "LoreRim Glue only listens to it ... everything else the key does is CHIM's own"; section 6.2 keeps "press" for the glue side only. README keys row rewritten. 10.29 section 6 gains "The key is CHIM's too": the glue's OnKeyDown fires on every key-down, even in paused menus; CHIM's hold / tap / double tap with line numbers | CHIM `AIAgentPapyrusFunctions.psc` 646-665 (BeginVoiceHotkey, `SafeProcess`, tap -> `stopAllDialogue`), 682-711 (FinishVoiceHotkey / WaitForCrosshairNpc -> `StartWait`, "[CHIM] <name> will wait here"), 743-764 (hold >= 0.35 s -> `recordSoundEx`; the tap's `stopAllDialogue`), 1228-1250 (SafeProcess); glue `LRG_Main.psc` 1267-1280 (`NoteSpeechToDriver(0)` before the `IsInMenuMode` return). Hand-off: the MCM help (config.json:113) |
| [chim] the trade window mutes the microphone | owner page: the Trade bullet and step (10) say "Close the shop (Tab) before you speak again: she cannot hear you while a trade, gift or training window is open"; section 4 "Windows that need the mouse" repeats it | `SafeProcess()` requires `!Utility.IsInMenuMode()` and no ContainerMenu (AIAgentPapyrusFunctions.psc 1228-1250); `BeginVoiceHotkey` returns without it (646-648) |
| [chim] "she says a word first, then her real line plays" | first-contact bullet: "She may say a short word while it comes up ... Which of the two you hear first is not certain yet - her word can come over her real line. Please tell us which it was."; step (1) and (9) no longer assert the order; the report paragraph asks for it. 10.29 section 4 states the text-time race | CHIM brief C2 / P6 / section 9 (TTS audio ~4.8 s vs the D2 open + fast pick ~2-3 s; `isActorTalking` blind to a not-yet-queued line) |
| [chim] the first-evening evidence list omits the CHIM checks | 10.29 section 13 gains (a)-(e) with their grep strings and which log each needs; the owner page's report paragraph asks him to keep `AIAgent.log` (path, rewritten each start) beside `lorerim_glue.log`; section 5 of this file lists them | strings verified read-only: AIAgent.log `Pushed command,1,ExtCmdLRG_SelectTopic@...do=open`, `Queueing new line - Actor:`, `Starting DownloadAndPlay`; chim.log (`lib/logger.php` DEFAULT_LOG) `Audit:Lock acquired by <type>` (main.php:248; 14 `funcret` rows in today's chim.log), `Generation stopped because user_input` (data_functions.php:5994), `Function not found for` (functions.php:2516). **One correction to the reviewer:** (e) is a chim.log line, not an AIAgent.log one - the docs say (a) and (b) need AIAgent.log, (c)-(e) chim.log |
| [chim] minor - the open_pending hides | 10.29 section 4 now names `hide_in_session` (EndConversation) and the opening kind's `services.kinds.<kind>.hide` codes, and why (CHIM's EndConversation cooldown under a visible list) | `lrg_dialogue.php` 2461 (open_pending -> hide_in_session), 2500-2506 (the kind's hide), :135 (`hide_in_session => ['EndConversation']`), :289 (the follower kind's six codes) |
| [use] U10 | owner page "still clumsy": a quest line to someone outside that quest opens nothing; "Press E on her, and if the line is on her list, say it". Section 7 row (a passer-by). README section 3 row and section 10 bullet | walkthrough W.open.anyone @1 red with "clause 4: skyrim.esm:01F810 is MG01InitialBranchTopic (MG01) - a top-level line her list cannot carry"; extra run F.open.anyone asserts "opens nothing" (green) |
| [use] lone quest lines wait; Faralda / Corpulus follow-ups | section 2 (the oath) and 6.2: only lines that change nothing by themselves play on; a lone line that moves a quest waits for his words or "yes" (Balgruuf's, Farengar's named). Faralda step (13) and Corpulus step (12) name their follow-up sentences | PROTOCOL 10.29 section 6 (`adv` only for `scripted=0`, not goodbye); MQ102.balgruuf.reward (0D553E scripted 1, goodbye 1) and MQ103.farengar.assignment (0D50E1 scripted 1) are singles; MG01.faralda.seek 3/3; W.corpulus.pet / F.corpulus.pet 3/3 |
| [use] the mouse-only windows and food by voice | owner page section 4 "Windows that need the mouse" (shop, training, gift / inventory; a walk-away list left by hand) with the exception "A mead, please" - she names what she has with the price in septims and asks which | capability map section 6 closing line and section 5; `tools/test_dialogue.php` (d) / (e) "a mead please" -> ask with the two meads |
| [use] 10.29 clause 5 and the days list | clause 5 restated with the U5 branch (exact / contain >= 0.85 with >= 1 shared strict word, q-scoped, negation clash refuses) and its examples; a new paragraph at the end of 10.29 section 5 documents `lrgDlgDaysSlot` (live digits, number words, nights = days, "tonight" = 1, STT folds, the guards) and the chained sentence (`lrgDlgUtterFor` chain, `chain_utter` after a released park). README section 3 gains the U5 opens, the days list and food by voice | `lrgDlgBusinessMarker` clause 5 (`lrg_dialogue.php` ~5380-5398: `$exact = ... 'exact','contain' ... >= 0.85 && $shared >= 1`); `lrgDlgServiceSlot` ~6355-6357 -> `lrgDlgDaysSlot` ~6431; `chain_utter` written ~4897, read ~4162; W.open.u5.aela / .irileth / .delphine (qrows), W.open.u5.hulda (none); FE.hulda.room.chain / .days; test_dialogue v29 |

### 9.2 Answered - not a Lane F file, owner named

| reviewer item | answer |
|---|---|
| [code] 1 / [game] P3 - gate A is red (the lane's own Tests line) | Confirmed and unchanged by this round; no Lane F file can make it green. r3 / r4 on the final tree reproduce run3 / run4 exactly (section 9.4). The red lines and their owners: `test_questline` 145 (115 @merge -> Lane A, or the spec owner's ruling to scope `lrgPromptEscalate` to the line's own topic and its lethal rows; 30 @spec_row MQ102.balgruuf.intro / MQ105.arngeir.summons -> spec owner); `--first-evening` 1 (FE.riverwood.plain's never line -> spec owner); `run_flows` 9 legacy flows (d23 d29 d30b d31 d33 d33s d44 d56 d57 -> the orchestrator assigns an owner; pt19c-A 4.3 has each rebase; Lane E owns `tools/flows/scenarios`). The game reviewer's recommended rulings are recorded for the spec owner: (1) scope the merge to the line's own INFO / topic (+ lethal rows) - Lane E measured all 115 resolve; (2) MQ102.balgruuf.intro and MQ105.arngeir.summons should pick (each is its scene's one forward line); (3) re-class FE.riverwood.plain's never row as a say row ("sing me something about dragons" picking Sven's effect-free ballads line is flexibility, not a defect) |
| [code] 2 - compile / deploy / install not run | Correct and by instruction (the fixer, like the implementer, is told not to compile, deploy or install). No script change is needed: `deploy_server.ps1` copies `server/lorerim_glue` whole, so `config/lrg_dialogue_overrides.default.json` ships |
| [code] 3 - Vilod / Corpulus verified only outside the tree | The page now says so in section 7 (walkthrough / extra run, "being moved into the standing first-evening check"), and the `F.` beats - including corrected copies of Vilod's and Corpulus's (F.corpulus.plain without the walkthrough's wrong rail flag) - are written out in the appendix. Folding them into `tools/fixtures/lrg_questline.json` as FE beats is Lane E's file (hand-off 9.3); no further Lane F change is needed once they are there |
| [game] P7(a) - `lrgVoicedWhy` "choose it on the menu" | Lane B's file (`lrg_actions.php` 1354-1355), already handed off (section 7); the owner page quotes neither string |
| [chim] MCM help text of `iKeyPushToTalk:Dialogue` | `game/LoreRimGlue/MCM/Config/LoreRimGlue/config.json` line 113 still says "It only tells her you are about to speak" - the MCM config is not a Lane F file (Lane D / C); the owner page, README and PROTOCOL now say what the key really does |

### 9.3 Hand-offs added by this round

- **Lane E:** fold into `tools/fixtures/lrg_questline.json` as FE beats: the appendix's `F.` beats (Gerdur, Alvor's
  answer, Balgruuf's intro / Helgen / reward with "yes", Farengar's open / intro / assignment with "yes" / turn-in,
  Faralda's answers, Kodlak's "I want to join the Companions" open, Delphine's verbatim lines, the gate at 0, the U10
  passer-by, Vilod, Corpulus with the pet follow-up). After that, section 7 of the owner page can drop its "extra run" /
  "walkthrough" marks.
  [fixer round 2] Proven on two staged copies and reduced to a pure-addition diff, with six more beats - section 10.3.
- **Lane B / spec owner:** judge a promised septims amount on a quest turn while `checks.reward.enabled` is false (the
  widened class Lane E proved, d68 15/15) - the owner page no longer promises "never" in v1.0; restore that word when
  it lands.
- **Lane D / Lane C:** the MCM help of `iKeyPushToTalk:Dialogue` (config.json:113): replace "It only tells her you are
  about to speak" with the tap / double-tap truth (PROTOCOL 10.29 section 6 gives the wording).
- **Lane A:** the stale comment "5 s spacing" above `lrgDlgEmit` (`lrg_dialogue.php` ~5528); `kind=rail` for the rail
  note (unchanged, section 7); a pre-lock `handled` verdict for SelectTopic funcrets once the first evening measures
  their MAIN cost (10.29 section 13 (d)).
- **Whoever lands the `lrgPromptEscalate` ruling:** re-sync owner page section 2 ("Quest conversations", "Where it is
  still clumsy") and section 7's "still red" marks in the same change.

### 9.4 Integration on the final tree (twice)

Scripts: `%TEMP%\lrg_test\pt19c-Ffix_stage.sh <run>` (Git Bash: stage `glue/` without `.backup`, md5 manifest) then
`pt19c-Ffix_run.sh <run>` (WSL: the live `data/`, `php -l`, the JSON checks, every suite, the questline harness in
five modes, the flows, then the extra run and the walkthrough fixture). Outputs `%TEMP%\lrg_test\pt19c-Ffix\<run>\out\*.txt`,
logs `<run>.log`, this round's diff `pt19c-Ffix.diff`. r1 / r2 ran on the tree before the last two edits of section 7
of the owner page; **r3 (staged 02:49:28) and r4 (02:51:25) are the final tree** - the four docs in both stagings are
byte-identical to `glue/`, and the code manifest is the same as run0-run4 and r1 (md5 `7721e580c798`: no code, test,
config or script changed). r3 and r4 are line-for-line identical:

```
php -l: 97 files, 0 with errors
lrg_config.default.json / lrg_dialogue_overrides.default.json / MCM config.json / both questline fixtures: ok bom=no crlf=no
test_gates            789 passed, 0 failed
test_intent           459 passed, 0 failed
test_phrases           47 passed, 0 failed
test_dialogue        1008 passed, 0 failed   ALL CHECKS PASSED
test_prompt_index      99 passed, 0 failed   ALL CHECKS PASSED
test_mcm_wiring        49 passed, 0 failed   ALL CHECKS PASSED
test_services          99 passed, 0 failed   ALL CHECKS PASSED   (the rewritten owner page: the same 5 service rows parse)
test_scene_index       ALL CHECKS PASSED
test_latency_prompt    MEAN per turn 2306 chars / 576 tokens
test_latency           34 passed, 0 failed
test_stt               60 passed, 0 failed
test_questline                 1811 passed, 145 failed, 0 known   RESULT: FAILED   (unchanged: 115 @merge, 30 @spec_row)
test_questline --first-evening  221 passed,   1 failed, 0 known   RESULT: FAILED   (unchanged: FE.riverwood.plain never line)
test_questline --words         2049 passed, 145 failed            (ask 1, breath 24, nothing 721, resolve 724)
test_questline --stage=B         39 passed,   0 failed            ALL CHECKS PASSED
test_questline --allow-known   1811 passed, 0 failed, 145 known   NOT A RELEASE GREEN
run_flows             89 scenarios: 79 passed, 9 FAILED, 1 pending; 1596 checks, 0 warnings   RESULT: FAILED
                      FAILED: d23 d29 d30b d31 d33 d33s d44 d56 d57; PENDING: d68 (gate B)
extra run (fix_fixture.json, 24 beats, --first-evening --fixture=)   57 passed, 0 failed   ALL CHECKS PASSED
walkthrough (walk_fixture.json)                                      182 passed, 12 failed  (unchanged; none is a page sentence:
                      W.corpulus.plain rail flag x4 - corrected as F.corpulus.plain; W.irileth.B1; W.trainer layer;
                      W.open.balgruuf marker; W.open.anyone - the U10 gap the page now states; U1 W.open.driver x2 /
                      W.open.trainer x2 - the rides / training the page no longer describes as measured)
```
Gate A therefore stays RED on exactly the items of section 5, with the same owners; this round changed no test outcome
except that the extra run now exists (and is green).

## 10. Fixer round 2 - every reviewer item, resolved or answered

Files changed in this round (docs only; backups `glue/.backup/pt19c-Ffix2/{OWNER_MENULESS_V1.md,pt19c-F.md}.bak`):
`glue/OWNER_MENULESS_V1.md` (346 -> 356 lines, ASCII, LF) and this file. PROTOCOL.md, README.md and V05_EXPANSION_PLAN.md
are unchanged in this round (none of them carries the "very close" claim; checked by grep). No code, test, fixture,
config or script file was touched: the staged code manifest is md5 `7721e580c798` again, the same as run0-run4, r1-r4
and the reviewer's v1 / v2.

### 10.1 Resolved in the lane's files

| reviewer item | what changed | evidence |
|---|---|---|
| the P1 nit - "Say the line as it is written, or very close to it, and it is picked at once" (owner page section 2) | "or very close to it" is gone. The bullet now reads: said as written it is picked at once; said any other way - loosely, "or only a word or two off the written line" - she MAY ask once, quoting it, and "yes" is enough. The Alvor step (section 3) and his section-7 row say "he may ask once" (some of his own-words lines click, some ask). | the two counterexamples are now asserted as `park` in the extra run: F.arngeir.summons "am answering your summons" -> park, F.delphine.mound "I know the burial mound you speak of, on the hill east of Kynesgrove" -> park; the written lines -> explicit (F.arngeir.summons, F.delphine.mound, F.delphine.go, F.balgruuf.intro, F.balgruuf.blocking, F.alvor.help, F.captive.who, F.delphine.walkout) |
| (found while fixing the nit) the "still clumsy" bullet says "Saying the line as it is written picks it at once" for Balgruuf's later "I need to talk to you", Arngeir, Aventus and the captive, but section 7 had no row proving those four | section 7 gains rows for them, plus Delphine's walk-out line (named in section 2) and Brynjolf's "What do I have to do?" (named in "still clumsy") | new extra-run beats, each copied from its tree beat (layer, target, facts unchanged; `fe_note` says so): F.balgruuf.blocking ("I need to talk to you" -> explicit), F.arngeir.summons (explicit + the park row), F.aventus.ok ("Are you all right?" -> `single/pick: fast pick mode=single` - picked at once; the tree's lower-case "are you all right" gets mode=explicit, also at once), F.captive.who ("Who are you?" -> explicit), F.brynjolf.what ("What do I have to do?" -> single, never rows kept), F.delphine.walkout ("I don't have time for this" -> explicit, never row kept). All read-only at clicks_ok 0 (journal scene) as well |
| (found while fixing the nit) section 7's "X is still red on its other wordings" was imprecise: the tree rows are red on the written line too (the check wants the plain `pick`; the written line gets `explicit`, which is picked at once with the confirming grade) | every such cell now says "still red, awaiting a fix", and section 7's intro defines it in one sentence ("that check wants every wording of the line picked without her asking. Today the line as written is picked at once, and some looser wordings make her ask once") | t1 / t2 `questline.txt`: e.g. `MQ105.arngeir.summons @clicks_ok 1: "I am answering your summons" -> pick [got explicit/pick: fast pick pos=0 mode=explicit \| s=1.000 ...]` |
| [code] 3 - the F. / W. beats are not in `tools/fixtures/lrg_questline.json` | Lane F cannot land them (next table), so the fold-in is now PROVEN rather than described: section 7 of the owner page says it was run and that nothing else changes. The builder `%TEMP%\lrg_test\pt19c-Ffix2_build.php` makes `fix2_fixture.json` (round 1's 24 beats + this round's 6) and the merged tree fixture (every F. beat appended to `beats`, `quest` FIX / WALK -> FE, `fe` 1); `pt19c-Ffix2_merge.sh` swaps it into a copy of each staging and runs the harness in all five modes | see 10.3: the fold-in diff is a pure addition (the existing 7,578 lines re-encode byte-identical with PHP `JSON_PRETTY_PRINT \| JSON_UNESCAPED_SLASHES \| JSON_UNESCAPED_UNICODE` + LF), and the merged `--first-evening` prints exactly the union of the tree's 222 checks and the extra run's 75, with the same result on every line, except that the one whole-run check (`lrgDlgWillEmit === (a pick was emitted) over every gate decision of this run`) is printed once over 103 decisions instead of twice (30 + 73): 296 checks, the single red line still FE.riverwood.plain's never line |

### 10.2 Answered - not a Lane F file (spec 2.2), owner named

| reviewer item | answer, with evidence |
|---|---|
| [code] 1 / [game] P3 - gate A is RED | Agreed, and unchanged: t1 / t2 (10.4) reproduce the reviewer's v1 / v2 and round 1's r3 / r4 line for line (`test_questline` 1811 / 145 = 115 @merge + 30 @spec_row; `--first-evening` 221 / 1 = FE.riverwood.plain's never line; `run_flows` 79 of 89, 9 FAILED d23 d29 d30b d31 d33 d33s d44 d56 d57, d68 pending). None of the red lines is in a Lane F file: spec 2.2 (pt19-menuless-v1-spec.md:1178-1180) gives Lane F `PROTOCOL.md`, `README.md`, `V05_EXPANSION_PLAN.md`, the owner page and the two ps1 scripts; the harness, its fixture and the flows are Lane E's (:1148-1149) and the merge is Lane A's (`lrgPromptEscalate`). What clears it: the spec owner's three rulings (scope `lrgPromptEscalate` to the line's own topic - clears the 115 @merge; MQ102.balgruuf.intro / MQ105.arngeir.summons; FE.riverwood.plain's never row), Lane A / Lane E landing them, and the orchestrator assigning the rebase of the nine legacy flows (Lane A pt19c-A 4.3 gives each fix). Section 5's checklist is unchanged. |
| [code] 2 - compile / deploy / install not run | Agreed: correct by instruction ("Do not compile, deploy or install"); the Build stage runs `compile.ps1` (0 errors, no .pex string over 500 characters, no None cast to a typed array) and the orchestrator deploys and installs with backups. No script change is needed (`deploy_server.ps1` copies `server/lorerim_glue` whole, so `config/lrg_dialogue_overrides.default.json` ships). |
| [code] 3 - the fold-in itself | `tools/fixtures/lrg_questline.json` is Lane E's file (spec :1148-1149, "Owns NEW files only: `tools/test_questline.php`, `tools/fixtures/lrg_questline.json`, ..."), so Lane F does not edit it. The hand-off is now mechanical: apply `%TEMP%\lrg_test\pt19c-Ffix2\lrg_questline.fold-in.diff` (a copy of t2's; `fix2_fixture.json` sits beside it) (or re-run the builder on the tree), then run `php tools/test_questline.php --first-evening`; the expected result is 10.3's. After it lands, owner page section 7 drops its "extra run" / "walkthrough" marks (Lane F, or whoever lands it, in the same change). The 30 beats are also written out in the appendix, so they survive Temp. |
| hand-offs (a)-(e) in other lanes' files | Re-checked on the current tree; all five are unchanged and stay handed off (section 9.3): (a) `game/LoreRimGlue/MCM/Config/LoreRimGlue/config.json:113`, `iKeyPushToTalk:Dialogue` help, still "It only tells her you are about to speak, so she waits ..." - Lane D / C; (b) `server/lorerim_glue/lib/lrg_actions.php:1354-1355`, `lrgVoicedWhy` still "... choose it on the menu" / "... choose it on the menu yourself" - Lane B (the owner page quotes neither); (c) `lrg_dialogue.php:4974`, `lrgDlgRailNote` still sends `'kind' => 'hint'` - Lane A; the owner page's "leave 'Tell me when she has something' on" work-around is right because `LRG_Main.psc:1768-1769` gates `kind=hint` on `bQuestHint:Quests`, while the unused `kind=rail` branch (:1771-1776) would show it once per conversation regardless; (d) `lrg_replies.php:712`, the `reward` pseudo-row exists only while `checks.reward.enabled` is true (ships false), so a promised bonus is not judged in v1.0 - Lane B / spec owner; the docs say so instead of "never"; (e) `lrg_dialogue.php:5528`, "(5 s spacing)" above `lrgDlgEmit` - Lane A; PROTOCOL 10.6 flags it. |

### 10.3 The fold-in, proven on the staged copies (for Lane E)

`%TEMP%\lrg_test\pt19c-Ffix2_merge.sh <run>` (WSL) copies the staged `glue/`, runs `pt19c-Ffix2_build.php` on it,
drops the merged fixture in as `tools/fixtures/lrg_questline.json`, writes `lrg_questline.fold-in.diff` (labels
`a/` / `b/`, so it applies at the glue root) and runs the harness in the five modes; `pt19c-Ffix2_cmp.sh <run>`
(Git Bash) compares the merged `--first-evening` against the two separate runs line by line. On t1 and t2:

```
fold-in diff:            0 lines removed, 1067 added (30 beats appended after the last FE beat; nothing else moves)
merged questline               1811 passed, 145 failed   (= the tree: every F. beat is fe=1, so the default run is untouched)
merged --first-evening          295 passed,   1 failed   FAIL only: FE.riverwood.plain never "sing me something about dragons"
merged --words                 2049 passed, 145 failed   (ask 1, breath 24, nothing 721, resolve 724 - unchanged)
merged --stage=B                 39 passed,   0 failed
merged --allow-known           1811 passed, 0 failed, 145 known   NOT A RELEASE GREEN (unchanged)
line-by-line: the merged --first-evening's 295 per-beat lines == the tree run's 221 + the extra run's 74 (same text,
  same verdict, 0 diff lines); the whole-run line "lrgDlgWillEmit === (a pick was emitted) over every gate decision of
  this run" is printed once over 103 decisions instead of twice (73 + 30)
```

So Lane E's change is: apply the diff (or re-run the builder), confirm the numbers above, and nothing else in the
fixture or the harness has to move. The one judgement left to Lane E is naming: the beats keep their `F.` ids (renaming
them `FE.` would need the owner page's section 7 cells renamed in the same change).

### 10.4 Integration on the final tree (twice)

Scripts: `%TEMP%\lrg_test\pt19c-Ffix2_stage.sh <run>` (Git Bash; stage `glue/` without `.backup`, md5 manifest),
`pt19c-Ffix2_run.sh <run>` (WSL; round 1's run - every suite, the questline harness in five modes, the flows, the
extra run now on `fix2_fixture.json`, the walkthrough - followed by `pt19c-Ffix2_merge.sh <run>`), then
`pt19c-Ffix2_cmp.sh <run>`. Outputs `%TEMP%\lrg_test\pt19c-Ffix2\<run>\out\*.txt`, `<run>\merge\out\*`, logs
`<run>.log`. **t1 (staged 03:12:59) and t2 (03:14:48) are the final tree**: the four docs in both stagings are
byte-identical to `glue/`, the code manifest is md5 `7721e580c798` (unchanged since run0). t1 and t2 are identical
apart from timings:

```
php -l: 97 files, 0 with errors; the five JSON files ok, no BOM, LF
test_gates 789/0  test_intent 459/0  test_phrases 47/0  test_dialogue 1008/0  test_prompt_index 99/0
test_mcm_wiring 49/0  test_services 99/0 (the page's 5 service rows parse; the new section-7 rows carry no marker)
test_scene_index ALL CHECKS PASSED  test_latency 34/0  test_latency_prompt MEAN 2306 chars / 576 tokens  test_stt 60/0
test_questline                 1811 passed, 145 failed   RED (unchanged: 115 @merge Lane A, 30 @spec_row spec owner)
test_questline --first-evening  221 passed,   1 failed   RED (unchanged: FE.riverwood.plain never line, spec owner)
test_questline --words         2049 passed, 145 failed   (ask 1, breath 24, nothing 721, resolve 724)
test_questline --stage=B         39 passed,   0 failed
test_questline --allow-known   1811 passed, 0 failed, 145 known   NOT A RELEASE GREEN
run_flows   89 scenarios: 79 passed, 9 FAILED (d23 d29 d30b d31 d33 d33s d44 d56 d57), 1 pending (d68, gate B); 1596 checks
extra run (fix2_fixture.json, 30 beats)   75 passed, 0 failed   ALL CHECKS PASSED
walkthrough (walk_fixture.json)          182 passed, 12 failed  (unchanged, none a page sentence - section 9.4)
the fold-in proof                        section 10.3 (identical on t1 and t2; merged fixture md5 dd8de74f194e)
```
Gate A therefore stays RED on exactly section 5's items, with the same owners. This round changed no test outcome in
the tree; it added six proven beats to the extra run and proved the fold-in.

## Appendix - the extra-run beats (`fix2_fixture.json`, one beat per line; fixer rounds 1 and 2)

Fixture header: `{"_":"lrg_questline","v":1,"index_hash":"e756e311aefaeab54a1184d609adb77b","quests":["FIX","WALK"],"known":{},"known_reasons":<copied from the walkthrough fixture>,"cross":{}}`; run with `php tools/test_questline.php --first-evening --fixture=<file>`. The `F.vilod.*`, `F.open.corpulus`, `F.corpulus.pet` and `F.open.anyone` beats are copies of the walkthrough's `W.` beats (`F.open.anyone` asserts "opens nothing"); `F.corpulus.plain` is `W.corpulus.plain` without its wrong `rail` flag. Round 2 added the last six beats (copies of their tree beats with the owner page's sentence; each carries an `fe_note`) and the `park` row "I know the burial mound you speak of, on the hill east of Kynesgrove" on `F.delphine.mound`. The fold-in rule for Lane E (section 10.3): append every line below to `beats` in `tools/fixtures/lrg_questline.json`, set `quest` FIX / WALK -> FE and `fe` 1, and write the file back with PHP `json_encode(..., JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"` (the existing file re-encodes byte-identical that way, so the change is a pure addition). `%TEMP%\lrg_test\pt19c-Ffix2_build.php <staged glue> <out dir>` does exactly that and also writes `fix2_fixture.json`.

```
{"id":"F.gerdur.ralof","quest":"FIX","npc":"Gerdur","origin":"engine","scene":"MQ102B","sc_expected":0,"expect":"pick","clicks":[0,1],"fe":1,"facts":{"clicks_ok":0,"fac":"TownRiverwoodFaction"},"layer":{"kind":"root","topics":[{"topic":"MQ102BRiverwoodHelpTopic","info_key":"skyrim.esm:052CB0"},"MQ102BWhiterunTopicTopic","MQ102BJarlBalgruufTopic"]},"target":{"topic":"MQ102BRiverwoodHelpTopic","info_key":"skyrim.esm:052CB0"},"say":[{"t":"Ralof said you could help me out","via":"pick"},{"t":"Ralof told me you could help","via":"pick"}],"never":["Ralof didn't send me"]}
{"id":"F.gerdur.answer","quest":"FIX","npc":"Gerdur","origin":"glue","expect":"pick","fe":1,"followup":1,"facts":{"clicks_ok":1,"fac":"TownRiverwoodFaction"},"layer":{"kind":"closed","parent_info":"skyrim.esm:052CB0"},"target":{"topic":"MQ102GerdurHelpA2","info_key":"skyrim.esm:052CB2"},"say":[{"t":"We escaped from the Imperials together","via":"pick"}]}
{"id":"F.alvor.answer","quest":"FIX","npc":"Alvor","origin":"glue","expect":"pick","fe":1,"followup":1,"facts":{"clicks_ok":1,"fac":"TownRiverwoodFaction"},"layer":{"kind":"closed","parent_info":"skyrim.esm:041F16"},"target":{"topic":"MQ102AlvorHelpA2","info_key":"skyrim.esm:041F14"},"say":[{"t":"He helped me escape from prison","via":"pick"}]}
{"id":"F.open.gerdur","quest":"MQ102B","fe":1,"npc":"Gerdur","origin":"glue","expect":"open","facts":{"q":"MQ102B","clicks_ok":0},"passes":[{"name":"@0","say":[{"t":"Ralof said you could help me out","via":"open","marker":"toplevel"}]}],"seam_rows":["skyrim.esm:052CB0","skyrim.esm:0C65A9","skyrim.esm:09A03B","skyrim.esm:0DF022","skyrim.esm:052CAF","skyrim.esm:0E163E","skyrim.esm:052CB1","skyrim.esm:052CAB","skyrim.esm:0DF1F6"]}
{"id":"F.open.kodlak","quest":"C00","fe":1,"npc":"Kodlak Whitemane","origin":"glue","expect":"open","facts":{"q":"C00","clicks_ok":1},"passes":[{"name":"@1","say":[{"t":"I want to join the Companions","via":"open","marker":"join"},{"t":"I would like to join the Companions","via":"open","marker":"join"}]}],"seam_rows":["skyrim.esm:0A3E7A"]}
{"id":"F.balgruuf.intro","quest":"FIX","npc":"Jarl Balgruuf the Greater","origin":"engine","scene":"MQ102","sc_expected":1,"expect":"scene","stageB":"pick","layer":{"kind":"root","topics":["MQ102BalgruufIntroTopic","ACFDialogueWhiterunBalgruufBranchProminentsTopic","ACFDialogueWhiterunBalgruufBranchAudienceTopic"]},"target":{"topic":"MQ102BalgruufIntroTopic","info_key":"skyrim.esm:0D07FD"},"say":[{"t":"I need to talk to you about Helgen","via":"explicit"}],"ruling_note":"the target's OWN topic MQ102BalgruufIntroTopic carries this prompt on five INFOs, among them 0DF024 (goodbye) and 0D50EB (an Invisible Continue line, graded scripted - capability map U2). Which INFO is on screen cannot be told from the text, so the fail-safe merge grades the line a commit EVEN SCOPED TO THE TOPIC (--scoped-merge): his own plain sentence clicks as the confirmation (explicit) and a looser one asks first (park). Spec 3.6 says pick and spec 3.4 step 5 allows a re-class only to park / words, so the fixture asserts the spec's pick and each line is a known @spec_row entry until the SPEC OWNER rules: either the 3.6 row becomes explicit / park (the fixture follows - its measured paths are in each line's note - and the entries go), or the build must give pick here (a defect for Lane A: grade by the live INFO, not the topic's worst). Round 1 had re-classed the beat without a ruling - reverted (reviewer round 2)","fe":1}
{"id":"F.balgruuf.helgen","quest":"FIX","npc":"Jarl Balgruuf the Greater","origin":"engine","scene":"MQ102","sc_expected":1,"expect":"scene","stageB":"explicit","layer":{"kind":"closed","parent_info":"skyrim.esm:0D07FD"},"target":{"topic":"MQ102BalgruufIntroHelgenB1","info_key":"skyrim.esm:0D50E0"},"say":[{"t":"The dragon destroyed Helgen, and last I saw it was heading this way","via":"explicit"}],"fe":1}
{"id":"F.balgruuf.reward","quest":"FIX","npc":"Jarl Balgruuf the Greater","origin":"glue","expect":"single","layer":{"kind":"single","parent_info":"skyrim.esm:0D50EB"},"target":{"topic":"MQ102BalgruufReward","info_key":"skyrim.esm:0D553E"},"say":[{"t":"what else can I help with","via":"single"},{"t":"yes, I'll help","via":"single"},{"t":"yes","via":"single"}],"fe":1,"never":["no, nothing else"]}
{"id":"F.open.farengar","quest":"MQ103","fe":1,"npc":"Farengar Secret-Fire","origin":"glue","expect":"open","facts":{"q":"MQ103","clicks_ok":1},"passes":[{"name":"@1","say":[{"t":"Do you need any help with the dragons?","via":"open","marker":"qrows"},{"t":"I have the stone tablet you wanted","via":"open","marker":"toplevel"}]}],"seam_rows":["skyrim.esm:0D50E7","skyrim.esm:0D50E8","skyrim.esm:0E0EB9"]}
{"id":"F.farengar.intro","quest":"FIX","npc":"Farengar Secret-Fire","origin":"glue","expect":"single","note":"the index has no layer line carrying this line and no parent row: a one-choice layer after his greeting (spec 3.6 said pick)","layer":{"kind":"single","parent_info":"-"},"target":{"topic":"MQ103FarengarIntroTopic","info_key":"skyrim.esm:0D50E8"},"say":[{"t":"Do you need any help with the dragons?","via":"single"}],"fe":1}
{"id":"F.farengar.assign","quest":"FIX","npc":"Farengar Secret-Fire","origin":"glue","expect":"single","layer":{"kind":"single","parent_info":"skyrim.esm:0D50ED"},"target":{"topic":"MQ103FarengarIntroB1","info_key":"skyrim.esm:0D50E1"},"say":[{"t":"what do you need done?","via":"single"},{"t":"yes","via":"single"}],"never":["uh, what now"],"fe":1}
{"id":"F.farengar.turnin","quest":"FIX","npc":"Farengar Secret-Fire","origin":"glue","expect":"pick","layer":{"kind":"root","topics":["MQ103FarengarRetrieveBookTopic","MQ103FarengarBleakFallsBarrowTopic","MQ103FarengarDragonWarTopic"]},"target":{"topic":"MQ103FarengarRetrieveBookTopic","info_key":"skyrim.esm:0E0EB9"},"say":[{"t":"I have the stone tablet you wanted","via":"pick"}],"fe":1}
{"id":"F.faralda.seek","quest":"FIX","npc":"Faralda","origin":"glue","expect":"explicit","note":"capability map U2 (NOD): an Invisible Continue line is visible and graded scripted","layer":{"kind":"closed","parent_info":"skyrim.esm:0AF0DF"},"target":{"topic":"MG01FaraldaHereResponse2","info_key":"skyrim.esm:0AF0E6"},"say":[{"t":"I seek the knowledge of the Elder Scrolls","via":"explicit"}],"fe":1}
{"id":"F.faralda.aetherius","quest":"FIX","npc":"Faralda","origin":"glue","expect":"explicit","note":"capability map U2 (NOD): an Invisible Continue line is visible and graded scripted","layer":{"kind":"closed","parent_info":"skyrim.esm:0AF0DF"},"target":{"topic":"MG01FaraldaHereResponse4","info_key":"skyrim.esm:0B8110"},"say":[{"t":"I want to unravel the mysteries of Aetherius","via":"explicit"}],"fe":1}
{"id":"F.delphine.mound","quest":"FIX","npc":"Delphine","origin":"glue","expect":"pick","layer":{"kind":"closed","parent_info":"skyrim.esm:032906"},"target":{"topic":"MQ106DelphineIntroEndA1","info_key":"skyrim.esm:0CA649"},"say":[{"t":"I know that mound - high on the hill east of Kynesgrove","via":"explicit"},{"t":"Kynesgrove mound","via":"park"},{"t":"I know the burial mound you speak of, on the hill east of Kynesgrove","via":"park"}],"merge_note":"the fail-safe merge (lrgPromptEscalate) grades this target above its own index row: the target 0CA649 (topic MQ106DelphineIntroEndA1) is scripted=1 goodbye=0, and the merge borrows goodbye from skyrim.esm:0CA633 - topic MQ106KynegroveEntryA1 (topic_key 0CA629), same prompt, same quest, ANOTHER topic, which parent 032906 does not link (it links 0CA62C, 0CA62B, 0CA62A). The spec's pick (3.6) is asserted; each line the merge moves (explicit / park) is a known @merge entry. With the merge scoped to each line's own topic (--scoped-merge, fixer round 2) all 10 resolve to pick (fast pick mode=intent; the rambling line by its T-key). Round 1 had re-classed the beat explicit - that absorbed the merge's damage (reviewers game P1, arch, use P3, lang) and is reverted","fe":1}
{"id":"F.delphine.go","quest":"FIX","npc":"Delphine","origin":"glue","expect":"pick","layer":{"kind":"closed","parent_info":"skyrim.esm:032906"},"target":{"topic":"MQ106DelphineIntroEndA2","info_key":"skyrim.esm:0CA637"},"say":[{"t":"Let's go kill a dragon","via":"explicit"},{"t":"let's go","via":"park"}],"never":["I'm ready to go"],"merge_note":"the fail-safe merge (lrgPromptEscalate) grades this target above its own index row (the harness prints what it borrows); the spec path is asserted, and each line the merge moves is a `known` entry (@merge) - the harness prints beside it what the line does with the merge scoped (--scoped-merge)","fe":1}
{"id":"F.gate","quest":"FIX","npc":"Whiterun Guard","origin":"engine","expect":"explicit","facts":{"fac":"IsGuardFaction,CrimeFactionWhiterun","bamt":50},"note":"capability map U6 (BINDING): the Whiterun gate guard is missing from 3.6/5.3; at clicks_ok 0 nothing on his list passes the rail (the U7 sentence)","layer":{"kind":"closed","parent_info":"skyrim.esm:0D197C"},"target":{"topic":"DialogueWhiterunGuardGateStopNote","info_key":"skyrim.esm:0D1980"},"say":[{"t":"Riverwood calls for the jarl's aid","via":"explicit"}],"rail0":1,"fe":1,"never":["I don't have any news","nobody sent me"]}
{"id":"F.alvor.help","quest":"FIX","npc":"Alvor","origin":"engine","scene":"MQ102A","sc_expected":0,"expect":"pick","note":"capability map U9 (BINDING): MQ102A has no journal row, so Alvor's scene (MQ102HadvarAlvorScene, owned by MQ102A - Skyrim.esm SCEN 0002BFAC) is sj=0, held only by the stage rail. This INFO (02C44F, scripted) shows from MQ102A stage 40 to 50 - AFTER the Hadvar line (041F16 / 0C65A8, stages 20-40, unscripted): one INFO of the topic at a time, so the Hadvar sentence is MQ102A.alvor.hadvar's, never a paraphrase of this line","layer":{"kind":"root","topics":["MQ102RiverwoodHelpTopic","MQ102WhiterunTopicTopic","MQ102AJarlBalgruufTopic","MQ102ACivilWarTopic","MQ102ATalosTopic"]},"target":{"topic":"MQ102RiverwoodHelpTopic","info_key":"skyrim.esm:02C44F"},"say":[{"t":"Do you have any supplies I could take?","via":"explicit"}],"merge_note":"the fail-safe merge (lrgPromptEscalate) grades this target above its own index row (the harness prints what it borrows); the spec path is asserted, and each line the merge moves is a `known` entry (@merge) - the harness prints beside it what the line does with the merge scoped (--scoped-merge)","fe":1}
{"id":"F.open.anyone","quest":"WALK","fe":1,"npc":"Ysolda","origin":"glue","expect":"open","facts":{"fac":"TownWhiterunFaction","clicks_ok":1},"passes":[{"name":"@1","say":[{"t":"Where can I learn more about magic?","via":"none"}]}],"seam_rows":["skyrim.esm:01F810","skyrim.esm:08EC35","skyrim.esm:08EC36","skyrim.esm:08EC37","skyrim.esm:08EC38","skyrim.esm:08EC39","skyrim.esm:08EC3A","skyrim.esm:08EC3B","skyrim.esm:08EC3C","skyrim.esm:08EC3D","skyrim.esm:08EC3E","skyrim.esm:08EC3F","skyrim.esm:08EC40","skyrim.esm:08EC43","skyrim.esm:08EC44","skyrim.esm:08EC45","skyrim.esm:08EC46","skyrim.esm:08EC47","skyrim.esm:08EC48","skyrim.esm:08EC49","skyrim.esm:08EC4A","skyrim.esm:08EC4B","skyrim.esm:08EC4C","skyrim.esm:08EC4D","skyrim.esm:08EC4E","surwr.esp:3B2F16"]}
{"id":"F.vilod.plain","quest":"WALK","fe":1,"npc":"Vilod","origin":"glue","expect":"pick","facts":{"clicks_ok":0,"fac":""},"layer":{"kind":"root","topics":[{"topic":"VilodMeaderyTalkTopic","info_key":"alternateperspective.esp:201653"}]},"target":{"topic":"VilodMeaderyTalkTopic","info_key":"alternateperspective.esp:201653"},"say":[{"t":"I've heard you are brewing a special kind of mead?","via":"pick"},{"t":"I heard you're brewing a special mead","via":"pick"},{"t":"what's this special mead you're brewing?","via":"pick"}]}
{"id":"F.open.vilod","quest":"AP_DialogueHelgen","fe":1,"npc":"Vilod","origin":"glue","expect":"open","facts":{"clicks_ok":0},"passes":[{"name":"@0","say":[{"t":"I've heard you are brewing a special kind of mead?","via":"open","marker":"toplevel"}]}],"seam_rows":["alternateperspective.esp:201653"]}
{"id":"F.corpulus.plain","quest":"WALK","fe":1,"npc":"Corpulus Vinius","origin":"glue","expect":"pick","facts":{"fac":"JobInnkeeperFaction,TownSolitudeFaction","clicks_ok":0},"layer":{"kind":"root","topics":[{"topic":"DialogueSolitudeCorpulusBranchTopic","info_key":"skyrim.esm:084987"},{"topic":"OfferServicesTopic","info_key":"skyrim.esm:08069D"},{"topic":"RentRoomStartTopic","info_key":"xtended stay.esp:000D84"},{"topic":"DBRumorsTopic","info_key":"skyrim.esm:0153B8"}]},"target":{"topic":"DialogueSolitudeCorpulusBranchTopic","info_key":"skyrim.esm:084987"},"say":[{"t":"Why is this place called the Winking Skeever?","via":"pick"},{"t":"why's it called the Winking Skeever","via":"pick"},{"t":"how did this place get its name","via":"pick"},{"t":"what's with the name, the Winking Skeever?","via":"pick"}]}
{"id":"F.open.corpulus","quest":"WALK","fe":1,"npc":"Corpulus Vinius","origin":"glue","expect":"open","facts":{"fac":"JobInnkeeperFaction,TownSolitudeFaction","clicks_ok":0},"passes":[{"name":"@0","say":[{"t":"Why is this place called the Winking Skeever?","via":"open","marker":"toplevel"},{"t":"What have you got for sale?","via":"none","log":"marker=kind"},{"t":"Heard any rumors?","via":"none"}]},{"name":"@1","clicks_ok":1,"say":[{"t":"What have you got for sale?","via":"open","marker":"kind"},{"t":"I'd like a room for the night","via":"open","marker":"kind"},{"t":"Heard any rumors?","via":"none"},{"t":"A mead, please","via":"none"}]}],"seam_rows":["betalilleshammerfellquestbundle.esp:0014CE","betalilleshammerfellquestbundle.esp:0014CF","betalilleshammerfellquestbundle.esp:0014D0","dawnguard.esm:003DA9","dawnguard.esm:00E7A9","dawnguard.esm:00E7AA","dawnguard.esm:00E7AB","dawnguard.esm:00F81E","dawnguard.esm:00F81F","dawnguard.esm:00F820","dawnguard.esm:00F821","dawnguard.esm:00F822","dawnguard.esm:00F823","dawnguard.esm:00F824","dawnguard.esm:00F825","dawnguard.esm:00F826","dawnguard.esm:010663","dawnguard.esm:010664","dawnguard.esm:010665","dawnguard.esm:010666","dawnguard.esm:010667","dawnguard.esm:010668","dawnguard.esm:010669","dawnguard.esm:01066A","dawnguard.esm:01066B","dawnguard.esm:01066C","dawnguard.esm:01066D","dawnguard.esm:01066E","dawnguard.esm:01066F","dawnguard.esm:010670","dawnguard.esm:010671","dawnguard.esm:010672","dragonborn.esm:03689B","dragonborn.esm:03689C","dragonborn.esm:03689D","forgottencity.esp:077A4B","forgottencity.esp:0B7B97","hearthfires.esm:003E64","meridia.esp:0319C3","meridia.esp:0319C6","meridia.esp:07AF7D","meridia.esp:07AF7E","meridia.esp:07AF7F","meridia.esp:08ECDB","skyrim.esm:07F6BC","skyrim.esm:08069D","skyrim.esm:08069E","skyrim.esm:0806A0","skyrim.esm:0806A2","skyrim.esm:0806A3","skyrim.esm:0806A4","skyrim.esm:0806A5","skyrim.esm:0806A6","skyrim.esm:0806A7","skyrim.esm:084987","skyrim.esm:0A9634","skyrim.esm:0A9635","skyrim.esm:0A9636","skyrim.esm:0A9637","skyrim.esm:0A9638","skyrim.esm:0A9639","skyrim.esm:0A963A","skyrim.esm:0A963B","skyrim.esm:0A963C","skyrim.esm:0A963D","skyrim.esm:0A963E","skyrim.esm:0A963F","skyrim.esm:0A9640","skyrim.esm:0A9641","skyrim.esm:0A9642","skyrim.esm:0A9643","skyrim.esm:0A9687","skyrim.esm:109E69","skyrim.esm:109E6A","skyrim.esm:109E6B","skyrim.esm:10FF7D","undeath.esp:304B79","undeath.esp:304B7A","undeath.esp:304B7B","undeath.esp:304B7C"]}
{"id":"F.corpulus.pet","quest":"WALK","fe":1,"npc":"Corpulus Vinius","origin":"glue","expect":"pick","facts":{"fac":"JobInnkeeperFaction,TownSolitudeFaction","clicks_ok":1},"layer":{"kind":"closed","parent_info":"skyrim.esm:084987"},"target":{"topic":"DialogueSolitudeCorpulusPetTopic","info_key":"skyrim.esm:085308"},"say":[{"t":"You kept a skeever as a pet?","via":"pick"},{"t":"a pet skeever?","via":"pick"},{"t":"you had a pet skeever","via":"pick"}]}
{"id":"F.balgruuf.blocking","quest":"FIX","npc":"Jarl Balgruuf the Greater","origin":"engine","scene":"MQ103","sc_expected":1,"expect":"scene","stageB":"pick","layer":{"kind":"root","topics":["MQ103BalgruufBlockingTopic","ACFDialogueWhiterunBalgruufBranchProminentsTopic","ACFDialogueWhiterunBalgruufBranchAudienceTopic"]},"target":{"topic":"MQ103BalgruufBlockingTopic","info_key":"skyrim.esm:0D5533"},"merge_note":"the fail-safe merge (lrgPromptEscalate) grades this target above its own index row (the harness prints what it borrows); the spec path is asserted, and each line the merge moves is a `known` entry (@merge) - the harness prints beside it what the line does with the merge scoped (--scoped-merge)","fe":1,"say":[{"t":"I need to talk to you","via":"explicit"}],"fe_note":"Lane F round 2: the owner page's sentence as written (and, where the page says so, a near variant that asks once), copied from MQ103.balgruuf.blocking (layer, target, facts unchanged)"}
{"id":"F.arngeir.summons","quest":"FIX","npc":"Arngeir","origin":"engine","scene":"MQ105","sc_expected":1,"expect":"scene","stageB":"pick","layer":{"kind":"root","topics":["MQ105ArngeirIntroTopic","MQArngeirDragonbornTopic","MQArngeirShoutingTopic"]},"target":{"topic":"MQ105ArngeirIntroTopic","info_key":"skyrim.esm:0649B7"},"ruling_note":"the target's OWN topic MQ105ArngeirIntroTopic carries this prompt on four INFOs, among them 02F2C4 (scripted + goodbye) and 02F2C6 (walk-away, crit 1); Arngeir's own row 0649B7 is scripted=1. Which INFO is on screen cannot be told from the text, so the fail-safe merge grades the line a commit EVEN SCOPED TO THE TOPIC (--scoped-merge): his own plain sentence clicks as the confirmation (explicit) and a looser one asks first (park). Spec 3.6 says pick and spec 3.4 step 5 allows a re-class only to park / words, so the fixture asserts the spec's pick and each line is a known @spec_row entry until the SPEC OWNER rules: either the 3.6 row becomes explicit / park (the fixture follows - its measured paths are in each line's note - and the entries go), or the build must give pick here (a defect for Lane A: grade by the live INFO, not the topic's worst). Round 1 had re-classed the beat without a ruling - reverted (reviewer round 2)","fe":1,"say":[{"t":"I am answering your summons","via":"explicit"},{"t":"am answering your summons","via":"park"}],"fe_note":"Lane F round 2: the owner page's sentence as written (and, where the page says so, a near variant that asks once), copied from MQ105.arngeir.summons (layer, target, facts unchanged)"}
{"id":"F.aventus.ok","quest":"FIX","npc":"Aventus Aretino","origin":"engine","scene":"DB01","sc_expected":1,"expect":"scene","stageB":"pick","layer":{"kind":"root","topics":["DB01AventusQuestBranchTopic"]},"target":{"topic":"DB01AventusQuestBranchTopic","info_key":"skyrim.esm:01F342"},"merge_note":"the fail-safe merge (lrgPromptEscalate) grades this target above its own index row (the harness prints what it borrows); the spec path is asserted, and each line the merge moves is a `known` entry (@merge) - the harness prints beside it what the line does with the merge scoped (--scoped-merge)","fe":1,"say":[{"t":"Are you all right?","via":"single"}],"fe_note":"Lane F round 2: the owner page's sentence as written (and, where the page says so, a near variant that asks once), copied from DB01.aventus.ok (layer, target, facts unchanged)"}
{"id":"F.captive.who","quest":"FIX","npc":"Alea Quintus","origin":"engine","scene":"DB02","sc_expected":1,"expect":"scene","stageB":"pick","layer":{"kind":"root","topics":["DB02CaptiveWhoAreYouBranchTopic","DB02CaptivePayToKillTopicTopic"]},"target":{"topic":"DB02CaptiveWhoAreYouBranchTopic","info_key":"skyrim.esm:01F5E2"},"merge_note":"the fail-safe merge (lrgPromptEscalate) grades this target above its own index row (the harness prints what it borrows); the spec path is asserted, and each line the merge moves is a `known` entry (@merge) - the harness prints beside it what the line does with the merge scoped (--scoped-merge)","fe":1,"say":[{"t":"Who are you?","via":"explicit"}],"fe_note":"Lane F round 2: the owner page's sentence as written (and, where the page says so, a near variant that asks once), copied from DB02.captive.who (layer, target, facts unchanged)"}
{"id":"F.brynjolf.what","quest":"FIX","npc":"Brynjolf","origin":"engine","scene":"TG00","sc_expected":1,"expect":"scene","stageB":"auto","layer":{"kind":"single","parent_info":"skyrim.esm:0352DA"},"target":{"topic":"TG00BrynjolfIntroBranch06","info_key":"skyrim.esm:0352D7"},"never":["what must I do","no, I won't do that"],"merge_note":"the fail-safe merge (lrgPromptEscalate) grades this target above its own index row (the harness prints what it borrows); the spec path is asserted, and each line the merge moves is a `known` entry (@merge) - the harness prints beside it what the line does with the merge scoped (--scoped-merge)","fe":1,"say":[{"t":"What do I have to do?","via":"single"}],"fe_note":"Lane F round 2: the owner page's sentence as written (and, where the page says so, a near variant that asks once), copied from TG00.brynjolf.chain06 (layer, target, facts unchanged)"}
{"id":"F.delphine.walkout","quest":"FIX","npc":"Delphine","origin":"engine","scene":"MQ106","sc_expected":1,"expect":"scene","stageB":"explicit","layer":{"kind":"closed","parent_info":"skyrim.esm:083055"},"target":{"topic":"MQ106DelphineIntroExclusiveA3","info_key":"skyrim.esm:032908"},"never":["I have time for this"],"fe":1,"say":[{"t":"I don't have time for this","via":"explicit"}],"fe_note":"Lane F round 2: the owner page's sentence as written (and, where the page says so, a near variant that asks once), copied from MQ106.delphine.walkout (layer, target, facts unchanged)"}
```
