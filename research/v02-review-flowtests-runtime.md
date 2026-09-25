# Adversarial review of FLOWTESTS (`glue/tools/flows/`) - correctness and runtime lens

Reviewer role: READ-ONLY for project files. Only this file was written.
Lens: hunt (1) PROTOCOL violations, (2) API names/signatures vs the installed sources, (4) runtime traps, plus (3) rails
and (5) claims that are asserted but not delivered.

**Verdict: SHIP AFTER FIXES.**

---

## 0. What I ran myself

| what | result |
|---|---|
| staged the project to my own `%TEMP%\lrg_rev_flows` (`robocopy /MIR /XD data`) and ran `run_flows.php` | 23 scenarios, **466 checks, 0 FAILED, 0 pending, 0 warnings**, `RESULT: OK` - the builder's headline number reproduces |
| `run_flows.php --strict` | exit 0 |
| independent mutation: `lrgGetNpcState()` read changed from `npc_name=` to `npc_name ILIKE` | **detected** - 3 scenarios failed (05/15/16/17 checks), exit 1. The suite does have teeth on this class |
| independent mutation: deliberate `Undefined array key` read inside `lrgPlaces()` | **NOT detected** - see F1 |
| probe of `FxDb` with four unsupported read shapes | **wrong rows returned, nothing reported** - see F2 |
| probe of the pre-lock entry point in its production shape (`HERIKA_NAME` empty, `?profile=md5`) | **tick dropped, name resolves to `''`** - see F3 |
| hot-path timing with the real 607-scene index | snapshot 0.0 ms; private speech turn 4.9 ms; in-scene speech turn 9.6 ms; lead tick 14.7 ms; post-LLM free-text search 2.0 ms; per-request index read+decode 4.9 ms (292 KB). **No performance defect on the server side.** |

### What checks out (so it is not re-litigated)
- **CHIM stub signatures match the installed 3.3.2 source exactly**: `chimRegisterPromptInjection(string,string,$content,int=100)` = `lib/prompt_injections.php:10`; `logEvent($dataArray,$forcePeople='')` = `lib/chat_helper_functions.php:5345`; `herikaActionCatalogUpsertCustomRow` = `lib/core/action_catalog.php:3379`; `herikaGetActionCatalogRow` = `:2494`; `herikaActionCatalogResetCache` = `:2016`; `terminate()` = `lib/auditing.php:26`.
- **Scenario 16 replays CHIM's real hook order.** Verified in the installed server: `ext/*/globals.php` at `main.php:54`, `preprocessing.php` at `:193` (before the MAIN semaphore at `:243`), `prerequest.php` at `:1117`, `context_pre.php` at `:2540`; `ext/*/prompts.php` from `prompts/prompts.php:285` (inside `prompt.includes.php:19`); `ext/*/functions.php` from `functions/functions.php:2752` via `requireFunctionFilesRecursively()`, i.e. **after** `$GLOBALS["ENABLED_FUNCTIONS"]` is loaded at `:2745`, the whole file pulled in at `prompt.includes.php:55`. `fxHookTurn()` (adapter.php:472-503) sets `ENABLED_FUNCTIONS` between `prompts.php` and `functions.php`, which is faithful.
- **`FxDb::upsertRowOnConflict` semantics match production**: CHIM's `ON CONFLICT ... DO UPDATE SET col = EXCLUDED.col` only for the given columns (`lib/phpunit.class.php:278-296`, same shape in `postgresql.class.php`), i.e. columns not passed survive - exactly what harness.php:75 does.
- **Rail: no example dialogue.** A grep for quoted sentences >= 14 characters and for scene ids / position words across `glue/tools/flows/` and `research/v02-flowtests.md` returns nothing but a format template (`adapter.php:419`) and a distro name. Scenes and words really are discovered at run time. The rail holds.
- **Migrations are genuinely idempotent** (`CREATE TABLE IF NOT EXISTS`, no embedded `;`), so the naive `explode(';')` in `lrgEnsureSchema()` is safe today - see F10 for why the suite could not tell you that.
- R9's fourteen required flows are all present and are real checks, not placeholders (01, 02+03, 04, 05, 07, 08, 09, 10, 11, 12, 13, 14, 15).

---

## F1 - HIGH - the release gate cannot fail on the two things the runner itself calls bugs

`run_flows.php:140`
```php
$bad = $tot['FAIL'] > 0 || ($strict && ($tot['PENDING'] > 0 || $warn > 0));
```
Neither `Fx::$phpIssues` nor `FxDb::$unhandledAll` is in `$bad`, although the runner prints them under the headings
*"PHP warnings / notices raised while the scenarios ran (each is a latent bug on the live server)"* (`run_flows.php:131`)
and *"Database calls outside the shapes PROTOCOL.md section 5 allows"* (`:135`).

**Proof.** I added one deliberate undefined-key read to `lrgPlaces()` in a throw-away copy and ran with `--strict`:
```
PHP warnings / notices raised while the scenarios ran (each is a latent bug on the live server):
  23x  plugin/lrg_core.php:532  Undefined array key "no_such_key_at_all"
23 scenarios: 23 passed, 0 FAILED, 0 pending; 466 checks, 0 warnings
RESULT: OK
EXIT_WITH_STRICT=0
```
A PHP 8.2 deprecation or undefined-index storm in the plugin is a green run. Hunt item (4) ("PHP notices under PHP 8.2")
is therefore *reported* but not *gated*.

**Smallest fix** (run_flows.php:140-141):
```php
$bad = $tot['FAIL'] > 0 || FxDb::$unhandledAll !== []
    || ($strict && ($tot['PENDING'] > 0 || $warn > 0 || Fx::$phpIssues !== []));
```
and name the cause in the `RESULT: FAILED` line.

---

## F2 - HIGH - `FxDb` never reports an unsupported READ, and answers it with the wrong row

`harness.php:97-98` route `fetchOne`/`fetchAll` through `rowsFor()` (`:84-95`), which never calls `miss()`. Only
`execQuery` (`:111`, `:115`) and `__call` (`:119`) record anything. So the file-header promise *"Anything else is recorded
in `$unhandled`"* and the report's *"exactly the PROTOCOL section 5 query shapes (anything else is reported)"* are false
for every read - and reads are almost all of the plugin's DB traffic.

**Proof** (two rows, Alice and Bob, in `lrg_npc_state`):
```
query with LOWER(): got npc = Alice   (asked for Bob)
query with ANY():   got npc = Alice   (asked for Bob)
query with an extra time predicate: row returned = YES (predicate ignored)
FxDb::$unhandledAll after four unsupported reads: 0 entries
```
`nameIn()` (`harness.php:79-82`) only matches the literal `npc_name='...'`; anything else falls through to "no name
filter", and `fetchOne` then returns **`array_values($rows)[0]`, i.e. the first NPC in the table**. Consequences:

- Scenario 00's last check, `$t->must('every DB call so far used a shape PROTOCOL.md section 5 allows', fxDb()->unhandled === [])`
  (`00_seams.php:60`), is structurally incapable of failing for a read. It is the suite's only contract guard on section 5.
- A read that is *stricter* in production (e.g. someone adds `AND updated_at > <now-90>` to `lrgGetNpcState`) behaves
  identically in the fake and differently on the live server - the classic "green tests, broken game".
- A cross-NPC read is served as a *plausible wrong answer* rather than an error. I only found it because my ILIKE mutation
  happened to trip the adults-only rail in scenarios 05/16/17; a shape that returned the *same* NPC by luck would pass silently.

**Smallest fix** (`harness.php`, inside `rowsFor()` right after the table match):
```php
$allowed = preg_match("/WHERE\s+npc_name\s*=\s*'/i", $q)
        || preg_match('/WHERE\s+active\s*=\s*1\s+AND\s+updated_at\s*>\s*-?\d+/i', $q);
if (!$allowed) { $this->miss($q); return []; }
```
(plus the `core_npc_master` shape from F3, deliberately allow-listed.)

---

## F3 - HIGH - the one production-only path that decides *which NPC* an initiative tick belongs to is never executed

`lrgResolveRequestNpc()` (`lib/lrg_actions.php:255-266`) exists precisely because `HERIKA_NAME` is not resolved at
`main.php:193`: CHIM itself derives the name from `$_GET["profile"]` with
`SELECT npc_name FROM core_npc_master WHERE md5='…' LIMIT 1` (main.php ~:295; `$GLOBALS["active_profile"]=md5(HERIKA_NAME)`
is only set at `main.php:817`, long after the preprocessing hook).

The harness always hands the pre-lock entry point a ready name: `fxGameMessage()` sets
`$GLOBALS['HERIKA_NAME'] = $herikaName` (`adapter.php:138`) and `fxTurn()` passes the target NPC itself
(`adapter.php:228`). `$_GET['profile']` is never set anywhere in `tools/flows/`. So `lrgResolveRequestNpc()` returns at
line 259 on every one of the 466 checks and the md5 branch has never run.

**Proof** - the same tick played twice, once the harness way and once the production way:
```
(a) harness style  (HERIKA_NAME set, no ?profile): pass
(b) production style (HERIKA_NAME empty, ?profile=md5): handled      <-- dropped
    resolved npc = ''
    FxDb unhandled reports for the core_npc_master lookup: 0
    db queries seen:
      SELECT npc_name FROM core_npc_master WHERE md5='cd6455…' LIMIT 1
```
With an empty name, `lrgEvaluateGates('')` returns `not_a_person` (`lrg_core.php:452`) and **every initiative tick is
dropped**. Whether that happens in game depends entirely on whether CHIM really puts `?profile=` on an
`lrg_initiative` request and whether that table/column pair is right - and the suite says nothing about either, while
scenario 15 reports 33 green checks under the title *"lrg_initiative admission … every hard gate"*. R2 is the owner's
second-biggest requirement this round; this is the single most likely way for it to be silently dead in game.

**Smallest fix** (all in `adapter.php` + `harness.php`, no scenario edits):
1. `fxGameMessage()`: for the pre-lock types set `$_GET['profile'] = md5($npcOfThisRequest)` and leave `HERIKA_NAME` at
   the decoy, so the resolver is forced to do its job.
2. `FxDb`: seed `core_npc_master` from `fxCast()` (`['md5' => md5($name), 'npc_name' => $name]`) and teach `rowsFor()`
   the `WHERE md5='…'` shape (allow-listed, per F2).
3. One check in 00 or 15: *"a tick is admitted when only `?profile=` identifies her (HERIKA_NAME is the decoy)"*.
4. Integrator note: confirm in the next playtest log that `initiative npc=<name> admitted` carries a real name.

---

## F4 - MEDIUM - R10's headline check is a tautology: `fxLlm()`'s `$message` is never used

`adapter.php:273-278`:
```php
function fxLlm(string $npc, string $codeOrName, string $item, string $message = ''): array
{
    $out = fxRememberWireScenes(lrgPostProcessActions([fxLine($npc, $codeOrName, $item)]));
    Fx::trace("… message=" . ($message === '' ? '(empty)' : '(one line)') . …);
    return array_values($out);
}
```
`$message` appears only inside the trace string. Therefore in `14_say_it_or_silent.php`:
- `:52` *"gentle pick (Pn) with an empty message: exactly one line reaches the game - the goto"* and
- `:58` *"sensual / sexual pick with a spoken line …"*

are **the same call** with a different trace label. Both would pass unchanged if the plugin emitted a paragraph of speech.
Likewise `:60` and `:109` (`fxLlmLines([]) === []`) cannot fail: `lrgPostProcessActions([])` has no input line to emit.

The builder's report puts exactly these in bold as the pin for the owner's *"they don't always have to say something"*.
The *marks* in the notes (`[say it]` / `[silent]`) and the `announce` die **are** properly tested - that part is fine -
but "silence is really silent" is not evidence from this suite. Silence is a CHIM behaviour
(`connector/openrouterjson.php`, `lib/data_functions.php:6004`, `main.php:2831-2846` per PROTOCOL 6.4), verified by
reading, not by the harness.

**Smallest fix**: drop the `$message` parameter (or `assert` it is unused), rename those two checks to what they prove
("the gate emits exactly one command line and adds nothing else"), and replace the two empty-array tautologies with a
falsifiable consequence that already exists in the plugin: a lead tick answered with nothing must increment `lead_idle`
(`lrg_actions.php:558-561`), and the following lead tick's notes must say so. Then state in the report that
empty-message-implies-no-TTS is CHIM's behaviour, asserted from source, not exercised here.

---

## F5 - MEDIUM - R7's central rule ("never build the index inside an LLM request") has no test, and the seam built for it is unused

`lrgIndexMaybeRebuildAsync()` (`lib/lrg_scene_index.php:192-222`) even ships the seam:
```php
if (!empty($GLOBALS['LRG_TEST_NO_SPAWN'])) { $GLOBALS['LRG_TEST_SPAWNED'] = $cmd; return true; }
```
A grep over `glue/tools/flows/` finds **zero** references to `lrgIndexMaybeRebuildAsync`, `lrgIndexStatus`,
`LRG_TEST_NO_SPAWN` or `LRG_TEST_SPAWNED`. The only index calls are the capability probe (`adapter.php:74`) and
`fxWarmIndex()` (`:337-342`). So nothing proves that

- an LLM-path turn (`fxTurn` / `fxHookTurn`) never builds,
- the 24 h forced rebuild the owner asked to be dropped is really gone,
- a *stale* cached index is still served rather than rebuilt.

Scenario 16's one timing check (`:20-23`) measures only the **fresh** case (`< 1.0 s`) and would also pass if a detached
build were spawned. Worse, scenario 16 drives the real `preprocessing.php`, which calls `lrgIndexMaybeRebuildAsync()`
unguarded (`preprocessing.php:19-21`): on a stage whose `data/` is empty or stale the suite itself can
`exec('nohup php … warm &')` against `/mnt/f` while it runs. That is a side effect the harness is not supposed to have.

**Smallest fix**: set `$GLOBALS['LRG_TEST_NO_SPAWN'] = true` in `fxReset()` (so no flow run can ever spawn a build), and
add one scenario: delete `data/.index_checked`, then assert (i) a speech turn and a lead tick leave `LRG_TEST_SPAWNED`
unset and `lrgIndexStatus()['building'] === false`; (ii) an `lrg_npcstate` with a stale signature does set it;
(iii) with the cached file present but stale, `lrgSceneIndex()` still returns scenes.

---

## F6 - MEDIUM - `mutation_check.ps1` exits 0 when every mutation goes stale

`mutation_check.ps1:71`
```powershell
if ($detected + $stale -lt $mutations.Count) { exit 1 }
```
A stale pattern proves nothing - the script's own header says so (`:5-6`) - yet it counts toward success. After any
BEHAVIOUR edit to those 15 source lines the script prints 15 `MUTATION DID NOT APPLY` and still exits 0, so the
"15 of 15 detected" guarantee silently becomes "0 of 15, and nobody notices". The builder even warned the integrator
about stale patterns in their own findings (#7) without closing this.

**Smallest fix**: `if ($detected -lt $mutations.Count) { exit 1 }`, and print `STALE: <n>` as its own summary line.

---

## F7 - LOW - the only prompt-size assertion is applied to the cheapest turn

`13_speed.php:42` checks `strlen($talk->volatile) < 1500` where `$talk` is a **speech-only** `lrg_scenetalk`
(`can_act = false`, so `lrgSceneNotes()` skips the whole option/verb block, `lrg_actions.php:859`). Measured on the real
607-scene index, the turn that actually happens on every line the player speaks inside a scene injects **3008 characters**
of volatile notes (8 options + 3 furniture options) and the lead tick is comparable. Under R7 prompt size is input
latency, so the budget is being checked where it does not apply.

**Smallest fix**: apply the same `should` to `fxSay(...)->volatile` and to `fxLeadTick(...)->volatile` with a budget
BEHAVIOUR agrees to (measured today: ~3.0 KB, so e.g. 3500).

---

## F8 - LOW - `fxShape()` makes PROTOCOL 2's `npc=` optional

`adapter.php:302`: `'/^ok=1;cid=[^;]+;(?:npc=[^;]*;)?' . $shape . '$/'`. PROTOCOL 2 says every param carries
`npc=<display name>`; `fxShape` is the helper roughly thirty checks use. The key is in fact covered elsewhere
(`09:13`, `09:73`, `13:81` all fail without it - I confirmed `last_result` attribution dies when it is missing), so this
is redundancy loss, not a hole. Make it mandatory in `fxShape` and keep a separate `fxShapeV1()` for the two v1-wire
comparisons that must tolerate its absence.

---

## F9 - LOW - `run_flows.php` run in place writes a kill-switch config into the source tree

`run_flows.php:60-69` refuses only `/var/www`. Run against the project copy (WSL reaches it at `/mnt/c/...`), the
`killswitch` variant writes `server/lorerim_glue/config/lrg_config.json` with `{"kill_switch":true}` into the project and
relies on `register_shutdown_function` to restore it - which a CLI Ctrl-C does not run. `deploy_server.ps1:26` excludes
`lrg_config.json` (`/XF`), so it can never reach the live server; the damage is limited to silently disabling the plugin
for later local runs (and `tools/test_gates.php:548-550` writes the same file and `unlink`s it unconditionally rather
than restoring).

**Smallest fix**: refuse unless the resolved plugin dir is under `sys_get_temp_dir()`, or an explicit `--allow-in-place`
is passed; drop a `.flowtest_override` marker next to the file so a stale one is detected and removed on the next run.

---

## F10 - LOW - migrations are never executed by the suite

`fxLoadPlugin()` touches `data/.schema_v2` (`adapter.php:44`, per PROTOCOL 7.3), so `lrgEnsureSchema()` returns at
`lrg_core.php:94` in all 466 checks and not one SQL statement is ever produced. I read both files and they are fine
(`CREATE TABLE IF NOT EXISTS`, no embedded `;`, the last statement without a trailing `;` survives the
`explode(';')` at `lrg_core.php:101`), so there is no bug today - but hunt item (4)'s "SQL that fails on re-run" is
outside the suite, and the next migration will be too.

**Smallest fix**: one check in scenario 00 - unlink the marker, call `lrgEnsureSchema()` against a recording `FxDb`, and
assert that every statement it emits matches `/^CREATE TABLE IF NOT EXISTS/i` and that the statement count equals the
number of `CREATE TABLE` lines in `migrations/*.sql` (catching a statement lost to the naive split).

---

## Claims in `research/v02-flowtests.md` that should be corrected

| claim | reality |
|---|---|
| "in-memory `FxDb` (exactly the query shapes PROTOCOL 5 allows, **anything else is reported**)" | reads are never reported and are answered with the first row of the table (F2) |
| "**a gentle pick with an empty message yields exactly the goto line** and nothing else; an empty answer with no action is a valid turn" | the message argument is unused; both checks are tautologies (F4) |
| "`--strict` also fails on pending/warnings" (true) implying a release gate | PHP warnings and out-of-contract DB calls never fail the run, `--strict` included (F1) |
| "Mutation check: 15 of 15 deliberate breaks detected" | the script cannot report otherwise once the patterns go stale (F6) |
| scenario 15 "tick admission … every hard gate" | the admission path is played with an NPC name production does not have at that point (F3) |
| scenario 13 titled "speed" | measures catalog shape and token caps; no index-build rule (F5), and the size budget is on the wrong turn (F7) |

Not defects, but worth keeping in the report: their own findings #1 (lead perspective), #2 (`OPENAI_FILTER_DISABLED` on
every non-silent turn, `closed` included) and #4 (statics) are accurate and important; #2 in particular belongs in the
owner-facing R5 audit.

---

## Verdict

**SHIP AFTER FIXES.** This is the strongest piece of work in the round: it drives the real hook files in CHIM's real
order (which I verified line by line against the installed server), it uses the real 607-scene index and invents no scene
ids or dialogue, it isolates process-wide state into child processes, it degrades to PENDING instead of lying when a
contract function is absent, and it ships its own mutation harness - which is why an independent mutation I wrote was
caught within seconds. But three of its load-bearing guarantees do not hold under inspection, and I proved each by
running it: the release gate cannot fail on PHP warnings or on out-of-contract database calls (F1), the fake database
answers unsupported reads with another NPC's row and never reports them, making scenario 00's section-5 guard vacuous
(F2), and the only code that decides which NPC an initiative tick belongs to on the live server has never once executed,
so R2's 33 green checks do not tell the owner whether a single tick will ever be admitted in game (F3). Those three are
about thirty lines across `run_flows.php`, `harness.php`, `adapter.php` and `mutation_check.ps1` plus one new scenario -
cheap, and they should land before the playtest, because the whole point of this suite is to be the thing the owner
trusts instead of the game.
