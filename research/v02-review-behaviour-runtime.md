# v0.2 REVIEW - BEHAVIOUR builder, lens: correctness and runtime (2026-09-21)

Adversarial review of the server-behaviour work (`glue/server/lorerim_glue/**`, `glue/tools/test_gates.php`).
Read-only for project files; the only file written is this report. Nothing was deployed, nothing under `F:\Modlists`,
inside WSL or in CHIM was changed. CHIM's source and the installed OStim / CHIM Papyrus sources were read; the CHIM
database was not written.

**VERDICT: SHIP AFTER FIXES.** The architecture is right and almost everything the builder claims is really there and
really verified against the installed sources. But one binding rail in `PROTOCOL.md` (a child nearby must silence the
glue **in every request type**) is not implemented inside a live scene, and the two test suites that claim to cover it
only exercise it outside a scene - a false green on the single most sensitive requirement in the project. Two more
defects make the post-LLM gate weaker than its own docblock claims and make three plausible `item` strings do the wrong
thing at the wire (a position change instead of "hold", a scene end instead of "don't stop", a forced climax on
"come with me"). None of these are architectural; all have small, local fixes.

## What I ran myself

| what | result |
|---|---|
| `php -l` on all 30 staged PHP files (PHP 8.2.28) | clean |
| `tools/test_gates.php` | 196 passed, 0 failed |
| `tools/flows/run_flows.php` | 23 scenarios, 0 FAILED, 466 checks, 0 warnings |
| `tools/test_scene_index.php` | ALL CHECKS PASSED |
| own probes (`zprobe1..5`, staged copy only) | verb resolution table, clothing table, rails at hook level, cross-NPC initiative, offered-vs-guidance |

No PHP 8.2 notices or warnings were emitted anywhere (both suites run under `error_reporting(E_ALL)` with
`display_errors=stderr`; my probes did too).

## Defects

### D1 - HIGH - a child nearby does NOT silence a live scene (PROTOCOL 6.2 / 7.3 violation, rail)
`glue/server/lorerim_glue/lib/lrg_actions.php:360`

```php
if ($inSceneWithThisNpc && ($state['adult'] ?? '') !== '1') {
```

The scene branch fails closed on `adult` only. `witkid` is never consulted once a scene row is active.
`PROTOCOL.md` section 6.2 lists "child nearby" under mode `silent`, and 7.3 states it without an exception:
*"non-adult snapshot or `witkid=1`: `mode=silent`, both guidance strings `''`, `x` null, nothing offered,
**in every request type**."*

Evidence, hook level (through the real `globals/preprocessing/prerequest/prompts/functions/context_pre` files, a live
scene row for the same NPC, snapshot `ostim=1;witkid=1`):

```
mode=scene x=2
offered=ChangeIntimacy+ChangeClothing
guidance empty? false          refusal filter disabled? true   maxTokens=200
explicit permission present? true
ChangeIntimacy@P1  -> Probe Woman|command|ExtCmdLRG_SceneControl@ok=1;cid=..;npc=..;do=goto;scene=OARE_GropingButtKiss
ChangeClothing@undress -> Probe Woman|command|ExtCmdLRG_Clothing@ok=1;cid=..;npc=..;do=undress;who=npc;part=all
```

So with a child in the room the glue still injects the level-2 "fully explicit and vulgar" wording permission, still
offers the scene actions, and still passes a position change and an undress command to the game.

**Why the tests did not catch it.** Both suites test `witkid=1` only with no scene row:
- `tools/flows/scenarios/05_non_adult_silence.php:13-14` puts `witkid=1` in the nine request-type loop, but the
  in-scene block at :48-63 uses the **minor** cast (`adult=0`), never `witkid=1`.
- `tools/test_gates.php:520-530` ("26. non-adult / child nearby: TOTAL silence in every request type") never creates an
  active `lrg_scene_state` row.

**Smallest fix** (`lrg_actions.php:360`):

```php
if ($inSceneWithThisNpc && (($state['adult'] ?? '') !== '1' || ($state['witkid'] ?? '0') === '1')) {
```

**Open tension the fix creates - needs an architect decision, not a silent choice.** Total silence also removes the
verbal "stop": with `$turn['scene']` null the post-gate drops `ChangeIntimacy@stop` too, so the only way out becomes
OStim's own key. The rail "the player can always stop, and `stop` ends the scene immediately" outranks silence.
Recommended shape: go silent for wording, guidance and every offer, but keep one exception in
`lrgPostProcessActions()` - while a scene row for this NPC is active, a resolved `['do' => 'stop']` still passes.
Alternatively GAME ends the thread itself on `witkid=1`; that belongs in `forIntegrator`.

### D2 - MEDIUM - the post-LLM gate never checks that the action was actually OFFERED this turn
`lrg_actions.php:473-475` (docblock), `:506-554` (the four branches)

The docblock says *"Unknown, **unoffered** or malformed glue actions are dropped"*. No branch calls `lrgIsOffered()`.
`StartIntimacy` checks `$gate['ok']` + mode; `SceneControl` checks `$turn['scene']` + `can_act`; `Clothing` checks
gate/mode; `Invite` checks mode + places. `lrgHideActions()` only ever **removes** codes, so a turn where CHIM never
offered the code at all still accepts it.

Evidence (`ENABLED_FUNCTIONS` = CHIM's list with no glue codes, mode `private`):

```
enabled = Talk,Follow,MoveTo,GiveGoldTo,TakeASeat,RentRoom,EndConversation,Inspect
BeginIntimacy -> Probe Woman|command|ExtCmdLRG_StartIntimacy@ok=1;cid=..;npc=..;scene=OARE_StandingEmbraceKiss;undress=1;furn=;fscene=;maxwit=0;folok=1
```

This is reachable in production: `functions/functions.php:2746-2748` falls back to the hard-coded
`$ENABLED_FUNCTIONS_LOCAL` when `herikaActionCatalogDbReady()` is false, and the owner can deactivate a row in the web
Action Editor at any time. The hard consent gates still hold (mode `private` needs `$gate['ok']`), so this is not a
consent breach - it is the glue acting on an action the owner switched off.

The same gap shows in the guidance: `lrgVolatileGuidance()` (:799, :805, :811, :817) and `lrgSceneNotes()` (:897, :906)
name `BeginIntimacy` / `ChangeClothing` / `ChangeIntimacy` / `SuggestPrivacy` unconditionally, while
`lrgMovementHint()` (:774-775) correctly guards CHIM's own actions with `lrgIsOffered()`. Same file, two rules.

**Smallest fix:** in `lrgPrepareTurn()` record what survived pruning -
`$turn['offered'] = array_values(array_filter($offer, 'lrgIsOffered'));` in the else-branch and the equivalent
(`LRG_ACT_CONTROL`, `LRG_ACT_CLOTHING`) in the scene branch - then in `lrgPostProcessActions()` add one guard after the
display-name mapping (`if ($code !== LRG_ACT_INVITE && !in_array($code, $turn['offered'], true)) { log; continue; }`;
`SuggestPrivacy` is server-only and stays gated on mode + places), and name only `$turn['offered']` entries in the
guidance, falling back to "in words only" when the list is empty.

### D3 - MEDIUM - "hold it there" moves the pair into a hug instead of stalling the climax (R1)
`lrg_actions.php:637`

```php
if (preg_match('/^(hold|hold on|hold it|hold back|wait|not yet)$/', $t)) { return ['do' => 'hold']; }
```

Fully anchored, so anything with a trailing word falls through to the free-text position search, where `hold` is a
configured synonym for `hugging / embrace / holdingbody`. Measured with a full `ctx`:

```
item 'hold'           => {"do":"hold"}
item 'hold on'        => {"do":"hold"}
item 'hold back'      => {"do":"hold"}
item 'hold it there'  => {"do":"goto","scene":"OARE_StandingHug"}
item 'hold still'     => {"do":"goto","scene":"OARE_StandingHug"}
item 'hold me'        => {"do":"goto","scene":"OARE_StandingHug"}   <- correct
```

`PLAYTEST2_NOTES.md:24` and `glue/README.md` both tell the player to say exactly **"hold it there"**, so the model will
echo it into `item` routinely. The result is a scene change down to `kissing` tier in the middle of a climax stall.

**Smallest fix:** add the variants to the anchored list (keeping it anchored, so "hold me" / "hold my hand" still reach
an embrace): `'/^(hold|hold on|hold it|hold it there|hold back|hold still|stall|wait|not yet)$/'`.

### D4 - MEDIUM - negated stop phrases end the scene
`lrg_actions.php:623`

```
item "don't stop"  => {"do":"stop"}
item 'do not stop' => {"do":"stop"}
item 'never stop'  => {"do":"stop"}
item 'not enough'  => {"do":"stop"}
```

The rail is "`stop` wins every **tie**"; these are negations, not ties. With row follow-ups off (R7) the player gets
only `The scene ends.` and no explanation. The direction is safe, the behaviour is wrong.

**Smallest fix:** one negation guard immediately before the stop branch -

```php
if (!preg_match('/\b(?:don\'?t|do not|never|no need to)\s+(?:want\s+to\s+)?(?:stop|quit|halt|end)\b|\bnot enough\b/', $t)
    && preg_match('/\b(stop|stopping|enough|halt|quit)\b|\bno more\b|^end\b|\bend (it|this|the scene)\b/', $t)) {
    return ['do' => 'stop'];
}
```

### D5 - MEDIUM - "come with me" inside a scene forces a climax
`lrg_actions.php:639` - `\bcome (now|for|with)\b` matches "come **with** me".

```
item 'come with me'  => {"do":"climax","who":"npc"}     (ctx tier 4)
```

"Come with me" is the exact phrasing R3 puts in her mouth for a privacy invitation, and a model that carries it into a
`ChangeIntimacy` item triggers an orgasm. Even on the charitable reading ("climax together") the `who` is wrong:
`\b(both|together|us|we)\b` does not match "with me", so it resolves to `npc`.

**Smallest fix:** narrow the alternative and widen the `who` test -
`\bcome (now|together)\b|\bcome (for|with) me\b` for the verb, and
`\b(both|together|us|we|with me|with you)\b` for `who`.

### D6 - LOW/MEDIUM - CHIM's refusal filter is switched off on almost every conversation turn, not just romantic ones
`lrg_actions.php:458-462`

```php
if (!$turn || ($turn['mode'] ?? 'silent') === 'silent') { return; }
...
if ($cfg['language']['disable_chim_refusal_filter'] ?? true) { $GLOBALS['OPENAI_FILTER_DISABLED'] = true; }
```

`closed` is the normal mode for **any adult NPC with a fresh snapshot who is not willing** - i.e. most ordinary
dialogue while the feature is on. So `checkOAIComplains()` (`lib/chat_helper_functions.php:589-655`, `:1380-1385`) is
effectively off for the whole game, and a genuine provider refusal would be voiced verbatim instead of the canned line.
The audit describes this as "only on turns the glue guides", which is literally true but reads much narrower than the
practical effect.

Defensible - an in-character "no" is exactly the sentence that trips the filter, and that is a `closed` turn - so this
is a decision to confirm rather than an outright bug. If the owner wants it narrower: gate on
`$turn['x'] !== null || ($turn['mode'] === 'closed' && !empty($turn['interest']['willing']))`, or make
`language.disable_chim_refusal_filter` three-state (`always | glue_romantic | never`).

### D7 - LOW - an explicit literal is written into a test (owner rail)
`glue/tools/test_gates.php:167`

```php
check('"fuck me" while still kissing: dropped, the NPC answers in words', lrgPostProcessActions([...."@fuck me"]) === []);
```

The rail is explicit: *"Do not write explicit example lines into code, prompts, tests or docs - describe the rule."*
This is a test input rather than example dialogue, but it is a literal in a file this builder owns and edited.
**Fix:** drive it from data - take the item text from `lrgConfig()['scene_index']['synonyms']` (e.g. the first key whose
target list contains `@sexual`) so the file carries no explicit literal, and reword the check name to the rule.

### D8 - LOW - R10 fails open on an unknown tier
`lrg_actions.php:831-834` - `(int) (LRG_TIERS[$tier] ?? 4) >= LRG_TIERS['sensual']`. An unrecognised tier string is
marked `[say it]`, against R10's "gentle choices are always silent". **Fix:** default to `0`.

### D9 - LOW - `Talk` is not a CHIM action code
`lrg_actions.php:397, 425` - `lrgKeepOnlyActions(['Talk', ...])`. `Talk` does not exist in
`HerikaServer/data/core_action_seed.sql`, so on a lead or initiative tick `ENABLED_FUNCTIONS` is reduced to the glue
codes alone. Harmless (that is the intent), but the code comment and `PROTOCOL.md` 6.2 ("keeps only Talk + the actions
of that mode") describe something that does not happen. Either drop `Talk` and correct the contract line, or confirm
the real code name with the integrator.

### D10 - INFO - the invitation's `loc` is recorded but never used
`lrg_actions.php:548`, `lrg_core.php:319-330`. An invitation made in an inn follows the player anywhere; the
follow-through guidance then asserts *"asked the player to come away to <place>, and now they are alone"* regardless of
where they actually are. `PROTOCOL.md` 5 does not require a place check, so this is a design note, not a defect -
flagging it because the assertion can read as a factual error to the model and R3's own wording implies arrival.

## What I checked and found correct

**Protocol, both sides.** Every command shape the server emits was compared against the game's dispatchers
(`LRG_Main.psc:336-400`, `LRG_OStim.psc:1236-1340`, `CmdWindDown`, `CmdClimax`, `CmdPullOut`, `CmdFurniture`,
`CmdLead`, `CmdClothing`): key names, key order, verb set, `ok=1;cid=;npc=` prefix, `do=speed;speed=`,
`do=winddown;scene=;warp=;linger=` with an empty `scene` (handled, `LRG_OStim.psc` waits `linger` then stops),
`do=furniture` always carrying a scene id, `who` values, `part` masks. The funcret format
`command@Code@param@result` matches `ReportResult` (`LRG_Main.psc:341`) and `lrgRecordResult()`'s
`explode('@', $raw, 4)`. Snapshot v=2 key order (new keys before `class`, `fac` last) matches
`LRG_Profile.BuildSnapshot:321-353`.

**CHIM API names and hook points**, all against the installed 3.3.2 source:
- `requireFilesRecursively` call sites: `globals.php` main.php:54, `preprocessing.php` :193 (before the MAIN lock),
  `prerequest.php` :1117 (right after the request-type whitelist at :1078), `context_pre.php` :2540. ext `functions.php`
  is loaded by `requireFunctionFilesRecursively()` at `functions/functions.php:2754`, after `ENABLED_FUNCTIONS` is
  filled at :2746 - so the glue really does see and prune CHIM's list, and `prompt.includes.php:55` requires
  `functions/functions.php` unconditionally, so `lrgPrepareTurn()` runs on every LLM request.
- `$GLOBALS['FUNCTIONS_ARE_ENABLED']` is the same global main.php:1079 writes; setting it in the prerequest hook works
  because the hook body runs inside a function.
- `action_post_process_fnct_ex` is applied at `lib/data_functions.php:6036-6041`, before the size check, so a lead tick
  that produced no action still reaches the gate (the `lead_idle` bookkeeping is sound).
  The glue registers at :2754, the core's own closure at :2830 - the glue runs first and sees raw LLM output.
- `chimRegisterPromptInjection` exists (`lib/prompt_injections.php:10`) and both slots are rendered
  (`main.php:2553-2565`).
- `followup.enabled = false` really does terminate without a second LLM call: `herikaActionCatalogGetResolvedFollowupConfig`
  (`lib/core/action_catalog.php:1436-1485`) passes the literal `false` through, and `processor/funcret.php:129-137`
  calls `terminate()`.
- `confirmation.default_policy = 'automatic'` resolves to channel `command` (`action_catalog.php:431-448` +
  `:364-379`), which is what the wire format needs - and none of the `ExtCmdLRG_*` codes are in the `askByDefault` list.
- `suppress_placeholder_infoaction` is read from the top level of `metadata`
  (`herikaActionCatalogMetadataFlagEnabled`, `action_catalog.php:2505-2523`) - the row shape is right.
- `requirements.request_types_any` is enforced when `ENABLED_FUNCTIONS` is built
  (`herikaLoadEnabledActionCodesForMode` :2678 -> `herikaActionCatalogRowMatchesRequirements` :1864-1866,
  `$context['request_type']` = `$gameRequest[0]`, :1584). So the custom types `lrg_scenetalk` / `lrg_initiative` work
  as designed and `SceneControl` is correctly absent from an initiative tick.
- `OPENAI_FILTER_DISABLED` (`chat_helper_functions.php:593`), `SCRIPTLINE_ANIMATION_SENT` (`:1667-1673`),
  `F_NAMES` (`functions/functions.php:2017-2049`), `escapeLiteral` / `fetchOne` / `upsertRowOnConflict` / `execQuery`
  (`lib/postgresql.class.php:448/400/667/307`) all exist with the assumed shapes.
- An empty `message` really is silent: `data_functions.php:6004` only calls `returnLines()` when the buffer is
  non-empty, while :6029 processes actions regardless. R10's "leave message empty" is sound server-side.

**R7 speed, end to end.** `FORCE_MAX_TOKENS` is honoured by the connector actually in use -
`connector/openrouterjson.php:622-623` (streaming) and `:1232-1233` (fast request); I checked specifically because the
non-JSON `openrouter.php` is a different file. The dialogue slot is `x-ai/grok-4.3` with reasoning effort none and
`disable_model_reasoning` unset (`?? true`, `openrouterjson.php:233`), so reasoning tokens cannot eat the 140/200 caps.
No index build can start inside an LLM request: `lrgSceneIndex()` only builds under `PHP_SAPI === 'cli'`
(`lib/lrg_scene_index.php:71-87`) and `lrgIndexMaybeRebuildAsync()` is called only from the `lrg_npcstate` fast path
(`preprocessing.php:19-21`). Markers in `data/` are writable by `www-data` on the live install (setgid dir,
`.actions_v6` is owned by `www-data`), so `lrgEnsureSchema` / `lrgEnsureActions` do not re-run per request.

**Rails that hold.** Cross-NPC initiative leakage is blocked (a tick admitted for A, arriving as B, gives
`mode=silent`, `initiative=false`, functions off). `adult=0` mid-scene is fully silent. A stranger gets
`mode=closed`, `x` null, no offers and the anti-agreeableness block. Unwilling NPCs never reach mode `public`, so they
never invite. `BeginIntimacy` in mode `public` is dropped. Migrations are `CREATE TABLE IF NOT EXISTS` only and the
comment-strip + `explode(';')` runner is safe for both files.

**Verb / clothing resolution** (measured, full `ctx`, tier 4): `faster/harder`, `slower/slow down`, `speed n`,
`release/let go`, `pull out`, `climax/cum`, `auto` (only once `auto_ok`), `player leads` / `<npc> leads` /
`<player name> leads`, furniture by label and by bare type, `wind down`, and every clothing form
(`undress`, `undress you` -> `player`, `take your clothes off` -> `npc`, `undress both`, `undress body`,
`get dressed`) all resolve exactly as `PROTOCOL.md` section 2 specifies. The resolution **order** matches 6.4.

## For the integrator (cross-file)

1. **D1's stop exception is a cross-owner decision.** If BEHAVIOUR silences the scene on `witkid=1`, either BEHAVIOUR
   keeps a `stop`-only path through the post-gate, or GAME must end the thread itself when a child is detected during a
   scene. Pick one and put it in `PROTOCOL.md` - today neither side does it.
2. `PROTOCOL.md` 6.2 says `lrgPrerequest()` "keeps only Talk + the actions of that mode". `Talk` is not a code in
   `core_action_seed.sql` (D9). Either correct the contract line or name the real code.
3. Environment observation, low confidence and outside this round's scope:
   `HerikaServer/conf/conf.php` is **0 bytes** (`-rwxrwx--- dwemer:www-data`, mtime Sep 21 00:24) next to a
   44 934-byte `conf.php.backup.20260921_042425`. `lib/runtime_bootstrap.php:193-201` loads `conf.sample.php` first and
   `conf.php` only if it exists, so the server still boots on sample values plus the DB-backed settings - which is
   presumably why the 12:35-12:38 playtest turns are in `lorerim_glue.log`. Worth confirming before the next playtest
   that nothing personal was lost in an interrupted UI save.

## Claims I could not confirm or refute

- Whether the model obeys "leave `message` empty" and the `[say it]` rule (playtest question; the server side and
  CHIM's empty-message path are both verified).
- `invitation.lead_the_way`'s `TravelTo@<nhome>` / `FollowPlayer@` wire format (default off; unchanged from the
  builder's own "not tested" note).
- Provider-side softening by the summary / diary models.
