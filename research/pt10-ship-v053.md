# pt10 ship - LoreRim Glue 0.5.3 verified, deployed and installed

Release + verification pass over `research/pt10-intent-silent-fix.md` (SERVER lane) and
`research/pt10-rail-gaps-fix.md` (GAME lane), on top of `research/pt10-snapshot-fix.md` and
`research/pt10-snapshot-verify.md`. Written 2026-09-22 19:30 local.

**Verdict: both fixes hold, and 0.5.3 is live on both sides.** The server plugin is deployed to
`/var/www/html/HerikaServer/ext/lorerim_glue` and the game files are installed into
`F:\Modlists\LoreRim\mods\LoreRim Glue` - `install_mo2.ps1` did **not** refuse: MO2, SkyrimSE and
skse64_loader were confirmed not running immediately before it, its own two process guards did not
trip, and the four profile files are byte-identical to the backups it took a second earlier.

Nothing in this pass is taken from the two fix reports: every claim below was re-derived from the
code, from hashes, or from a test this pass wrote and ran itself. **Five observations** are listed in
section 6; none of them blocks the playtest, and none is a safety hole.

---

## 0. What shipped

| marker | was | now |
|---|---|---|
| `LRG_Main.CurrentVersion` | 502 (installed) | **503** |
| `manifest.json` | 0.5.1 | **0.5.3** |
| `LRG_VERSION` (`lrg_core.php:16`) | 0.5.1 | **0.5.3** |
| `meta.ini` version (what MO2 shows) | 0.5.1 | **0.5.3** |
| `README.md` / `PROTOCOL.md` title | 0.5.1 | **0.5.3** |
| snapshot wire `v=` | 2 | **2** (unchanged) |

The version numbers were out of step in three places and the intent lane flagged it rather than
fixing it, because the bump touches the installer. It is a release decision, so this pass made it:
one number, 0.5.3, everywhere. Nothing reads `manifest.json` except `install_mo2.ps1` (it writes
`meta.ini`), and `LRG_VERSION` is only ever printed - no test asserts either, so the bump cannot move
behaviour. `LRG_SCHEMA_VERSION`, `LRG_ACTIONS_VERSION`, `LRG_INDEX_VERSION` and the wire are all
untouched.

---

## 1. The server fix: understood on a blind turn, and still nothing offered

### 1.1 The rail, traced in the code (not in the report)

`lrgEvaluateGates()` (`lib/lrg_core.php:1149`) returns **the moment** the snapshot is missing or
stale, before any other reason can be added:

```php
$state = lrgGetNpcState($npc);
$res['state'] = $state;                       // the LAST facts, however old - set BEFORE the check
if (!$state || ($state['_age'] ?? 9999) > $maxAge) { $res['reasons'][] = 'no_fresh_snapshot'; return $res; }
```

`disabled`, `not_a_person` and `not_player_speech` all return even earlier, so on a blind turn
`reasons === ['no_fresh_snapshot']` **exactly** - which is what makes "the glue knows nothing"
separable from "the glue knows, and the answer is no". The separation is real, not asserted.

`lrgPrepareTurn()` (`lib/lrg_actions.php:1130-1142`) then computes

```php
$blind = $mode === 'silent' && in_array('no_fresh_snapshot', $reasons, true)
      && ($last['adult'] ?? '1') !== '0' && ($last['on'] ?? '1') !== '0';
$turn['blind_note'] = $blind && ($last['adult'] ?? '') === '1' && ($last['on'] ?? '') === '1'
      && in_array($type, LRG_PLAYER_SPEECH_TYPES, true);
```

and `:1163` adds `$blind ||` to the recogniser's mode list. **Everything that decides what is offered
sits above that line and did not move**: `$offer = [...][$mode] ?? []` (`:1201`) is `[]` for
`silent`, `lrgHideActions()` hides Start / Clothing / Invite with `mode silent`, Control and
RequestAct are already hidden with `no scene`, and `$turn['offered']` is therefore `[]`.

The post-LLM gate drops all five **twice over**, and the second drop never looks at the intent:

| action | why it dies on a blind turn | line |
|---|---|---|
| any | `!in_array(strtolower($code), $turn['offered'])` -> "not offered this turn" | `:1619` |
| `BeginIntimacy` | needs `$gate['ok']` **and** mode `private`/`follow` | `:1643` |
| `SceneControl` / `RequestAct` | need `$turn['scene']` + `can_act` | `:1685`, `:1701` |
| `ChangeClothing` | needs a live scene, or `$gate['ok']` + `private`/`follow` | `:1712` |
| `SuggestPrivacy` | needs mode `public` **and** `$turn['places']` | `:1727` |

`lrgSafetyNet()` (`:2117`), `lrgSecondCommand()` (`:2167`) and `lrgHoldCarrier()` (`:2195`) each
begin with `($turn['mode'] ?? '') !== 'scene'` and return. `lrgDropUnaskedGoto()` iterates
`$emitted`, which is empty. `lrgApplyTurnRuntime()` (`:1557`) returns on `silent`, so CHIM's token
budget and its refusal filter are left exactly as CHIM made them. `lrgStaticGuidance()` (`:2523`)
returns `''` for any mode outside `closed/public/private/follow`, so `<personal_boundaries>` stays
empty and `x` (the explicit-wording permission) is never set.

`lrgRememberQuote()` is reachable in the new branch but inert by construction: the gate returned
before `$res['price']` was ever computed, so `!empty($gate['price']['for_sale'])` is false.

### 1.2 An independent adversarial pass (28 checks, all passed)

Written for this pass, not by either lane - `tools/verify_blind.php` in the staged copy only
(`%TEMP%\lrg_test`, never in the project tree), run under a `set_error_handler` that turns **every**
PHP notice into a visible failure:

| group | what it proves | result |
|---|---|---|
| A | an NPC the game has **never** reported on + *"get on the bed and take your clothes off, I want to fuck you"*: mode `silent`, the sentence **is** parsed (`intent=undress`), `blind` true but `blind_note` false, **not one character** of guidance (volatile and static both `''`), `offered=[]`, `x` null, and **12 command spellings** (code names, CHIM display names, `begin_intimacy`, an `amount=500` paid start) all dropped | 7/7 ok |
| B | stale facts that are *not* reassuring - `witkid=1`, `combat=1`, `married=1`: still silent, nothing offered, all 12 spellings dropped, the note names no action and no body word, 390 chars, no boundaries block | 6/6 ok |
| C | an **admitted `lrg_initiative` tick** that arrives blind: silent, nothing recognised, nothing injected, nothing offered | 4/4 ok |
| D | the OTHER silences, on a **fresh** snapshot - `adult=0`, `on=0`, `witkid=1`: silent, `blind` false, **nothing recognised**, nothing injected, every command dropped | 9/9 ok |
| E | the blind stretch ends: the first fresh snapshot gives mode `private` again and no note | 2/2 ok |

Group D is the one that matters most and it is the one the fix could most easily have broken: a
recogniser that ran on *every* silent turn would have given a sexual reading to the player's words in
front of a child or a minor. It does not - `$blind` is false there, because the reason list is not
`['no_fresh_snapshot']`.

### 1.3 The directive

Verbatim, as the flow harness printed it:

> The game has sent no fresh facts about <npc> this moment, so nothing about <npc>'s situation is
> known here and nothing physical can be set up, agreed or carried out on this turn. Answer the
> player in words, in <npc>'s own voice. Do not act anything out, and do not write as though
> something were being done - say it plainly instead, or simply answer.

**Short and plain: yes.** 390 characters, four sentences, one `<this_moment>` block. No action name,
no `<player_request>` directive, no wording permission, no scene vocabulary, no jargon, and nothing a
model could read as a licence. It is strictly more restrictive than the silence it replaces, which is
the claim that matters. The first sentence is long (33 words) and slightly doubles back on itself
("no fresh facts ... nothing about her situation is known here"); that is a style note, not a defect,
and it is not worth re-opening a shipped string for.

---

## 2. The game fix: the rail cannot abort, and the payload is byte-identical

### 2.1 Only one file moved, and it is not the one that builds the payload

SHA-256, project `game\LoreRimGlue\Source\Scripts\*.psc` vs the **installed 502** sources:

```
LRG.psc LRG_Dialogue.psc LRG_DlgProbe.psc LRG_DlgUI.psc LRG_Followers.psc
LRG_MCM.psc LRG_OStim.psc LRG_PlayerAlias.psc LRG_Profile.psc      identical
LRG_Main.psc                                                        DIFFERENT
```

`LRG_Profile.psc` **is** the payload (`BuildSnapshot`) and `LRG_Followers.psc` is the `fol=` block;
both are byte-identical to what was running, so **no key can have been added, removed or reordered**.
The only way `LRG_Main.psc` could still change the payload is through the arguments it passes, and
the diff shows the call itself untouched:

```
	snapWhere = 1
	string payload = lrg_profile.BuildSnapshot(akNpc, player, sessionTag, ConvHoldFor(akNpc), \
		inScene, IsIntimacyEnabled(), PlaceFacts(akNpc, player))
	snapWhere = 11
	SendNpcMessage(MSG_NPCSTATE, payload, akNpc.GetDisplayName())
```

On a healthy install `snapDoorBad` / `snapFolBad` are false (cleared by `Maintenance()` on every
load, verified at `LRG_Main.psc:187-192`), so `SnapDoorOk()` / `SnapFolOk()` are true and `door=`,
`witchim=` and `fol=` all go out exactly as in 501/502.

### 2.2 Nothing added can itself abort

Every statement the 503 diff adds is one of: a member write (`snapWhere`, `snapBusy`,
`snapBusyTime`, `lastSnapActor`, `lastSnapTime`, `lastAnySnapTime`), a call to `SnapFolTry()` (one
member write), `Utility.GetCurrentRealTime()`, or a string literal inside `SnapStageName()`. No
native of another mod, no member call on anything that can be `None`, no array access, no cast.
Checked statement by statement against the diff, not sampled.

**No latent call was added.** The only `Utility.Wait` in the whole snapshot path is `:1949`, inside
`MaybeSnapshot`'s forced branch, and it is reached only while `snapWhere == 0` (the detector above it
clears `snapWhere` and `snapBusy` when it is greater than 0). So a thread parked at that wait can
never be mistaken for an abort by another thread - there is no false-positive path into
`SnapReportAbort()`.

**The new `Maintenance()` bracket is re-entrancy-safe.** `snapBusy` is now held across
`FollowerSweep()`. `FollowerSweep()` calls `PO3_SKSEFunctions.GetActorsByProcessingLevel`,
`LRG_Followers.IsGhost`, `Log()` and `ArmFollowerRepair()` - and `ArmFollowerRepair` only writes the
four-slot queue and calls `RegisterForSingleUpdate` (`:1662-1690`). **None of them is latent**, so no
other event can interleave inside the object and read the held flag. The load-time crosshair
snapshot, which *can* wait 0.25 s, now runs **before** the bracket is taken, which is the right way
round.

**The one thing the rail cannot report on itself.** `Log()` -> `LogC()` ends in
`AIAgentFunctions.logMessageForActor(...)` plus `Game.GetPlayer().GetDisplayName()`
(`LRG_Main.psc:493-499`) - CHIM's own native. If CHIM's native is what is aborting (stage 22 is
exactly that suspicion), the `SNAPSHOT ABORTED` line cannot come out. This is pre-existing (all glue
logging has always gone that way) and it **self-limits**: `snapAborts += 1` is written *before* the
`Log()` call, so after six attempts the reporter no longer logs, returns cleanly, and the flags are
cleared - the feature recovers instead of looping. The Papyrus log (switched on in the verify pass)
is the backstop for exactly this case.

### 2.3 The compiled `.pex` really carries it

`tools\compile.ps1`: **`OK - .pex files copied ...; compiler reported 0 errors, 0 warnings`**,
2 rounds, 22 auto-stubs. Longest docstring per file (the 300-char rule), measured independently:
`LRG_Main` 263 · `LRG_Profile` 271 · `LRG_Followers` 192 · highest of all ten 290 (`LRG_Dialogue`,
`LRG_MCM`). All under 300.

The installed `Scripts\LRG_Main.pex` contains all eight new strings - `the entry checks (enabled /
dead / ActorTypeNPC / distance)`, `CHIM's getAgentByName`, `the OStim scene check`, `the arguments
(PlaceFacts, MCM reads) or the head of the payload`, `the witness scan (no SFF native runs here)`,
`the follower note after the send`, `the follower sweep on load`, `CORE path` - so this build is
distinguishable from 502 by inspection, and `LRG_Followers.pex` still contains `SffBlock`.

---

## 3. Tests - everything, on the release candidate

Staged copy in `%TEMP%\lrg_test`, WSL `DwemerAI4Skyrim3`, PHP 8.2.28. The plugin header printed
`plugin 0.5.3`, so these ran against the numbers that shipped.

| | result |
|---|---|
| `php -l`, every `.php` under `server\lorerim_glue` and `tools` | **79 files, 0 errors** |
| `test_gates` | **332 passed, 0 failed** (section 8b = the blind turn, 13 checks) |
| `test_intent` | **190 passed, 0 failed** |
| `test_phrases` | **14 passed, 0 failed** (hit rate 91.9 %, floor 82 %) |
| `test_scene_index` | **ALL CHECKS PASSED** (607 scenes) |
| `test_dialogue` | **174 passed, 0 failed** |
| `test_prompt_index` | **67 passed, 0 failed** |
| `test_prompt_index --db` | **112 passed, 0 failed** against the live database |
| `test_mcm_wiring` | **4 passed, 0 failed** (113 controls, every one with an ini line and a reader) |
| `test_services` | **35 passed, 0 failed** |
| `test_latency` | **26 passed, 0 failed** |
| `flows\run_flows.php --strict` | **75 scenarios, 75 passed, 1,203 checks, 0 FAILED, 0 pending, 0 warnings - RESULT: OK** |
| `verify_blind.php` (this pass, independent) | **28 passed, 0 failed** |
| `compile.ps1` | **OK, 0 errors, 0 warnings**, 10 `.pex` |
| `esp_dump.py` on the INSTALLED esp | valid, all VMAD byte counts consumed exactly |

Headers read, as the brief asks: the flow runner reported `contract capabilities: ... modes=yes`
with every capability `yes`, `scene index: 607 scenes`, variant `main` plus the four child variants,
and **no PHP notice was raised** (the harness's own error handler fails the run on one).

Two standing notes the index tests print, neither a failure and neither new: one engine-only plugin
is unreadable (`Sanguine Symphony.esp`), and nine topics in this load order have an inverted PNAM
flag (upstream data).

`test_prompt_index` and `test_services` need `data/prompt_index.ndjson` and
`data/service_catalog.json`, which live in the deployed plugin and not in the source tree; they were
copied read-only from `/var/www/.../data` into the staged copy before running (they FAIL with a clear
"no index file" message otherwise, which is what a first run shows).

---

## 4. Deploy (server) - done

`tools\deploy_server.ps1`, after `service postgresql start` (it was **down**; now
`15/main (port 5432): online`, `pg_isready` accepting connections):

```
anchor pre-flight: 29 anchors, one match each
scene index: rebuilt, 607 scenes, 5.5s
loaded 37561 prompts, 5718 layers into schema lrg_index
prompt index v1  source=db  schema=lrg_index  rows=37561  layers=5718  coverage_rows=432
index pre-flight: lrg_index.lrg_prompt has 37561 rows, service catalog present
deployed to /var/www/html/HerikaServer/ext/lorerim_glue
EXIT=0
```

The deploy runs `php -l` over every file it installs and fails on the first error; it reported none.
Verified afterwards on the live tree: `LRG_VERSION` `0.5.3`, `manifest.json` `0.5.3`, the recogniser
condition at `:1163` (`$blind || in_array($mode, ...)`), the `<this_moment>` block at `:2714`, the
`silent: no fresh snapshot` line at `:1274`. **All 25 deployed files are byte-identical (CRLF
normalised) to the project sources; 0 different, 0 missing.** The owner's
`config/lrg_config.json` (95 bytes) was not touched, `data/` was not touched, and no PHP fatal has
been logged.

## 5. Install (game) - done, no refusal

`tools\install_mo2.ps1`. Before running it, `Get-Process` for `ModOrganizer|SkyrimSE|skse64`
returned **0 processes**; the script's own two guards therefore did not trip and it ran end to end
to `Done.`

| step | result |
|---|---|
| profile backups | `modlist.txt` `plugins.txt` `loadorder.txt` `settings.ini` -> `*.bak-20260922-191940` |
| mod files | robocopy into `F:\Modlists\LoreRim\mods\LoreRim Glue`, 24 mesh files |
| `meta.ini` | `version=0.5.1` -> **`0.5.3`** (read from `manifest.json`) |
| `modlist.txt` / `plugins.txt` / `loadorder.txt` | entries already present, **left as is** |

**Hash comparison, source vs installed** (SHA-256, every file, recursive): **61 source files, 61
identical, 0 different, 0 missing**; the only extra file in the mod folder is `meta.ini`, which the
installer generates. The four profile files are **byte-identical to the backups taken minutes
before**, so nothing about MO2's load order moved.

Installed markers: `Source\Scripts\LRG_Main.psc` reads `int Property CurrentVersion = 503
AutoReadOnly`; `Scripts\LRG_Main.pex` carries the stage-21 name and `CORE path`;
`Scripts\LRG_Followers.pex` carries `SffBlock`. `esp_dump.py` on the installed
`LoreRimGlue.esp` (683 bytes, 3 records): `LRG_MainQuest` with `LRG_Main`, `LRG_OStim`,
`LRG_Dialogue`, `LRG_DlgProbe` + alias `LRG_PlayerAlias`; `LRG_MCMQuest` with `LRG_MCM` +
`SKI_PlayerLoadGameAlias`; all VMAD byte counts consumed exactly. Unchanged and correct.

---

## 6. Observations (none blocking, most useful first)

**O1 - the `silent:` line is also written for an admitted initiative tick, and its wording does not
fit that case.** `$turn['blind']` does not require player speech (only `blind_note` does), so an
`lrg_initiative` tick that arrives while the snapshot is stale produces
`silent: no fresh snapshot (age=600s) npc=Ysolda - ... she answers in words` although nothing was
injected and she is not answering anything. Reproduced in group C of the independent pass and seen
in the log. It is one diagnostic line in a rare case, costs nothing and hides nothing - but the
clause is untrue there. One-line fix if anyone is in the file: append the "she answers in words"
half only when `$turn['blind_note']` is set.

**O2 - `blind_note` only fail-closes on `adult` and `on`, not on the rest of the stale facts.** An
NPC whose last snapshot said `witkid=1` (a child was in the room a few minutes ago), `combat=1` or
`married=1` still gets the note. Verified deliberately (group B). It is defensible - the note names
no action, permits no wording, offers nothing and says nothing sexual - and the alternative (fail
closed on every stale flag) would mute the note exactly when the game has stopped reporting, which is
when it is wanted. Recorded so the choice is visible rather than accidental.

**O3 - heat now counts during a blind stretch, which is a deliberate departure from the shape
`pt10-snapshot-fix.md` section 3 suggested** ("keep `heat` bumping behind `mode != 'silent'` exactly
as now"). The intent lane argued the change and this pass agrees: `heat` only feeds
`lrgStartHeldBack()`, which decides whether the action exists *on a turn where every hard gate has
already passed on a fresh snapshot* - and that function returns `''` immediately for a direct request
(`act` / `undress` / `offer`) regardless of heat, so what heat really buys is the indirect build-up.
Mode `closed` still does not warm up. No hard gate is reachable through it.

**O4 - `ost.RefreshRentedBed()` is still outside the rail**, as the game lane says in its own section
2. It cannot take the bracket, because it re-enters `MaybeSnapshot` and a held `snapBusy` would read
as an abort to our own call. It is the glue's own script over furniture references, inns only, at
most once per cell per 120 s, and it sits after the send, so an abort there costs no data - only that
event's `Watch()`. Accepted as shipped.

**O5 - the root cause is still identified by elimination.** `pt10-snapshot-verify.md` section 2
showed the save-file leg of the original argument does not hold, and nothing in this pass changes
that: what is *established* is that the stack was unwound somewhere inside `MaybeSnapshot`; that it
was the SFF block is still the last candidate standing rather than an observation. 503 is built so
that this no longer matters - stages 21, 22 and 23 now cover the stretch that was never ruled out -
and the Papyrus log will name the function and line the first time it happens.

---

## 7. What the owner does next

1. Start MO2 (profile **Ultra**), start the game, load a save.
2. **Proof the new build loaded**, in
   `\\wsl.localhost\DwemerAI4Skyrim3\var\www\html\HerikaServer\log\lorerim_glue.log`:
   `GAME maintenance done, version 503, save <n>, session <n>`.
   If it still says 502 or 501, the new `.pex` did not load and nothing below means anything.
3. **The one line that proves the whole round.** Talk to any CHIM NPC and look at the gate line.
   Playtest 10 read `gate npc=Lisette mode=silent ... reasons=no_fresh_snapshot ... profile=-`.
   It must now read `gate npc=<name> mode=private|public|closed|follow ... profile=<word>`, with
   `reasons=` no longer carrying `no_fresh_snapshot`.
4. If a block still dies, **one** line names it (at most six per session), and traffic resumes from
   the **next** event:
   ```
   GAME SNAPSHOT ABORTED in the follower block (fol=) (stage 7): fol= / witchim are off for this session
   GAME SNAPSHOT ABORTED in CHIM's getAgentByName (stage 22): no optional block was running - this is the CORE path, please send the Papyrus log
   ```
   A stage 21/22/23 line is the case the elimination argument never covered: send
   `Documents\My Games\Skyrim Special Edition\Logs\Script\Papyrus.0.log` with it.
5. If the game goes quiet again anyway, the server now says so in the owner's own terms, once per
   request: `silent: no fresh snapshot (age=142s) npc=<name> - no action offered, nothing executed;
   she answers in words (the player asked for undress)`. `age=none` means the game has never reported
   on her at all. That line is the proof the recogniser is running - and the turn line will carry
   `intent=` instead of `intent=none`.

## 8. Files this pass changed

| file | change |
|---|---|
| `glue\server\lorerim_glue\manifest.json` | `version` 0.5.1 -> **0.5.3** (this is what `meta.ini` gets) |
| `glue\server\lorerim_glue\lib\lrg_core.php` | `LRG_VERSION` 0.5.1 -> **0.5.3** |
| `glue\README.md` | title and version markers -> 0.5.3 / script 503; the 0.5.3 summary paragraph (both lanes, wire unchanged); the test table refreshed with this pass's real numbers |
| `glue\PROTOCOL.md` | title -> build 0.5.3 + the "nothing on the wire changed" paragraph; **6.2 mode table, the `silent` row** - the one documented exception the intent lane flagged as out of date and not theirs to edit; **section 9** - the new `silent:` prefix and the `SNAPSHOT ABORTED` GAME line with its stage numbering |

No game script, no server library function and no test was edited by this pass: the two lanes' code
shipped exactly as they wrote it.
