# pt14 - server lane: NEVER SILENT (owner addendum 11)

Server 0.5.4 -> **0.5.5** (manifest + `LRG_VERSION`), catalog `LRG_ACTIONS_VERSION` 10 -> **11**. **Not deployed.**
Nothing on the wire changed in either direction; the game needs nothing new (works with scripts 401-506).

Verification (staged to `%TEMP%\lrg_test\srv14`, scripts `srv14_stage.ps1`, `srv14_lint.sh`, `srv14_tests.sh`,
`srv14_flows.sh`, `srv14_sample.sh`):
`php -l` 81 files / 0 errors · test_gates **377**/0 (was 339) · test_intent 255/0 · test_phrases 31/0 ·
test_scene_index ALL PASSED · test_dialogue 174/0 · test_prompt_index 67/0 · test_mcm_wiring 3/0 ·
test_services 35/0 · test_latency **30**/0 (was 26) · flows `--strict` **77 scenarios / 1286 checks / 0 fail /
0 pending / 0 warn** (was 76 / 1252), no PHP warnings. Deploy anchor pre-flight simulated: 29 anchors, one match each.

## 1. How CHIM really decides to voice a funcret (read in the installed HerikaServer)

- `main.php:2657` -> `processor/funcret.php`. The follow-up config is resolved from the **catalog row of the code**
  (`herikaActionCatalogGetResolvedFollowupConfig()`, `lib/core/action_catalog.php:1436`). `followup.enabled` false or an
  empty `followup.prompt` -> `terminate()`. Otherwise ONE LLM turn: last user line `"(<followup.prompt>) <cue>"`, where
  `<cue>` = `$PROMPTS['afterfunc']['cue'][<code>]` (`processor/request.php:14-18`); tool call `{"<arg_name>":"<field 2>"}`
  (raw interpolation), tool result = field 3; `use_functions_again` false switches all actions off for that turn.
- **Correction to the task's premise:** `suppress_placeholder_infoaction` never switched the LLM turn off. It only
  drops the "issued ACTION ..." placeholder line from her event log (`chimLogFuncretResultInfoAction`). The silence came
  from `followup.enabled = false` on every glue row. Also: in 0.5.4 only the ESCORT funcret ended in preprocessing;
  every other glue funcret was passed on, waited for the MAIN lock and a full prompt build, and was then ended by
  `funcret.php`.
- Code with no row (the escort) resolves to `[]` -> can never be voiced -> it needed a row.
- `action_catalog.php` is only loaded by `functions/functions.php` (after preprocessing), so the "will CHIM really
  run it" check has to happen at `context_pre.php`, not in preprocessing.

## 2. The design (per RESULT, through CHIM's supported path)

The switch is per row, so: **every glue row has the follow-up ON** (with a rule-only prompt, `use_functions_again`
false), and **the glue itself ends every result that must stay silent in preprocessing, before the MAIN lock**.
Only a failure the player has to hear about reaches `funcret.php`, which runs its own follow-up turn with our cue.

| result | verdict (`lrgFuncretVerdict`, pre-lock) |
|---|---|
| CHIM's own action; Phase 2's `SelectTopic` | `pass`, untouched |
| kill switch / SHARMAT / no `npc=` | `handled`, silent |
| any SUCCESS (pace, position, clothing, hold/release, escort incl. "stops what she was doing", start ...) | `handled` - quiet as before, and now cheaper |
| escort `unknown command` (script <= 505) | `handled` (as 0.5.4) |
| failure but skipped: `voice.enabled` off, dry run, `adults only`, `the feature is switched off`, the hold carrier, her own move on a scene-lead tick (`voice.lead_failures` false), a second failure within `voice.min_gap_seconds` (8) | `handled`, `told=false` -> told on her next turn (old path) |
| every other failure / refusal | **VOICED**: `told=true`, `voiced_at`, `$GLOBALS['LRG_VOICED']`; this request's funcret is rewritten to `command@<Code>@<verb>@Error: <plain fact>` (the k=v parameter would otherwise land in her event log as the action's target); `pass` |

- How a result is attributed: new `lrg_memory.sent` ring (last 8, 15 min) written with every emitted command
  (`$emit`, net, second command with its own `cid..b`, escort, carrier) with `src` = speech / lead / initiative /
  carrier / other. A cid not found = voiced unless it has the carrier's exact shape.
- `prompts.php` sets `$PROMPTS['afterfunc']['cue'][<code>]` = `lrgVoicedCue()` + `TEMPLATE_DIALOG` (only for the NPC
  the result belongs to). `lrgPrepareTurn()` makes that funcret turn mode **`voiced`**: no glue action, nothing
  injected, no gate. `lrgApplyTurnRuntime()`: 140-token cap + CHIM's refusal-filter bypass (an in-character "not now,
  because ..." must not be swapped for CHIM's canned refusal).
- `context_pre.php` calls `lrgVoicedGate()` first: another character than the result's NPC, or CHIM's row has no
  follow-up yet (rows older than v11) -> log, `told=false` again, `terminate()`; she is told next turn instead.
- The escort gets an **inactive** catalog row `ExtCmdLRG_Escort` / `GlueEscort` (`is_activated` 0, available to
  nobody, `request_types_any ['lrg_never']`) purely so its refusals can be voiced; a line of it from the model is
  still dropped by the post-gate.

Real cues (from `srv14_sample.sh`, player "Jordan"):
> What just happened: Lisette could not come along with Jordan - she is in the middle of something important that
> she cannot walk away from. Lisette says so now, in ONE short line in Lisette's own voice - the real reason, in plain
> words, never a word about the game, commands or errors. Lisette does not pretend it happened, does not promise it,
> and makes no speech of it.

Other `<what> - <why>` facts: "Lisette Flowtest could not come along with Flowtest Player - she is in the middle of
a performance that she cannot just leave" (bard by `fac=`) · "could not stay behind and wait for Jordan - she already
travels with Jordan as a companion, and that goes through her own companion orders" · "Lisette's clothes did not come
off - somebody is watching" · "the change of position did not happen - that position cannot be reached from here" ·
"nothing began between Lisette and Jordan - Jordan cannot pay what was agreed". Technical reasons (not installed,
unknown request, not authorised ...) become "it just would not work right now"; "quest"/"journal" never appear.

**Escort success that stopped her scene:** stays quiet (no second LLM turn). The optional "one moment" is carried by
the follow directive on the request turn: "The game takes her away from what she is busy with first - she may say she
needs a moment. Do not make up a reason to stay: if the game cannot take her away, she is told why and says so then."

## 3. Directives (item 2) - every recognised request says "do it, or say why - never ignore it"

- Escort: all six shapes end in "never ignore it"; the no-FollowPlayer/no-scene shape now asks for a plain reason.
- In-scene DO-IT gains "never ignore it" but keeps "Do not refuse, stall, negotiate" (owner addendum 3: inside a
  scene the only "cannot" is the GAME's - the CANT shape now, or the voiced funcret afterwards). MAYBE / not-offered /
  CANT ("in one short line") / ALREADY updated.
- Out of scene: OPEN ("a no comes with its reason, in one short line. Never ignore it."), neutral, and a NEW public
  shape for physical requests: "Not here - <reason>: one short line with that reason, and she may name somewhere
  private instead. Never ignore it."
- Closed: "It is not happening right now, for the reason above: one short line, with that reason in her own words -
  never ignoring it" (+ the closed intimacy note reworded; the two closed reasons that had no words,
  `already_in_scene` / `scene_running`, now have words, so "the reason above" always exists; fallback "for a reason
  of her own").
- Money: all ten shapes gained "Never ignore it".
- test_gates 35 has a **sweep** asserting that 17 directive shapes (scene / open / neutral / public / closed / blind /
  money) all carry it.

## 4. CHIM's own movement (item 3) - cheap, no LLM

- Post-gate `lrgMoveWatchNote()`: CHIM's `ComeCloser` / `MoveTo` / `TravelTo`, and `FollowPlayer` when no escort line
  went out, on a player-speech turn outside a scene -> `move_watch {code, at, dist0 = snapshot dist, scene0}`.
- `lrgMoveWatchCheck()` (called from `lrgStoreNpcState()`, pre-lock, one memory read per snapshot): after 6 s,
  FAILED if she is still in an engine scene, or (ComeCloser/FollowPlayer) still > 350 units away and not 100 closer;
  success clears silently; no evidence within 30 s clears silently. Failure -> `missed`.
- The escort's LATE failure ("she went back to her scene ... and stays", which the game only logs) is parsed from
  the `lrg_log` line (`lrgNoteEscortLate`) -> `missed`.
- `missed` is told ONCE on her next player turn (any mode but a scene with her): "<npc> did not manage to <what> just
  now (<why>). <npc> does not act as if it had worked; if the player asks about it, <npc> says why in one short
  line." Turn line gets ` missed=<Code>`.

## 5. Blind and closed turns (item 4)

- Blind (`no_fresh_snapshot` only, last facts adult/on): a recognised request now gets ONE `<player_request>`:
  "Nothing can be set up or carried out on this turn: she says so in one short line, in her own voice, with a plain
  human reason (not right now, not here) - never a word about the game or missing facts, never ignoring it, and never
  acting as though it were happening." Plain kind only, no action, `x` null; a blind turn with no request still carries
  the note alone. Every other silence still injects nothing (except escort / missed, both non-intimate).
- Closed: see section 3.

## 6. Cost (item 5)

- No new LLM call of the glue's own. A voiced failure = ONE LLM + TTS turn, the funcret turn CHIM already supports;
  it replaces a silence (pt9: ~1.5 s to first sentence, ~7-8 s MAIN-held median per spoken turn with TTS on the GPU).
- A success is now **cheaper** than 0.5.4: ended before the MAIN lock instead of waiting for it + a full prompt build
  (pt9 measured 24 funcrets at 255 ms median under MAIN, 8.2 s worst lock wait).
- Measured pre-lock verdict (test_latency 6, in-memory DB, log file on /mnt/c): **success 3.4 ms, voiced failure
  5.0 ms** per funcret - mostly the log append over 9P; less on the distro's own disk.

## 7. Tests (item 6)

- test_gates: 23 (catalog v11, the inactive escort row, follow-up ON + prompt on every row), 24 (voiced failure:
  pass, told, record, rewritten funcret, mode voiced, cue, 140 tokens + filter bypass, gate; quiet success handled;
  foreign / SelectTopic untouched; quiet failures: lead move, carrier, dry run, adults only, the gap; gate mismatch),
  35 (reasons in words, closed / public / private directives, the 17-shape sweep, ComeCloser failed / came / did not
  move / expired, escort late failure), 8b (blind turn answers a request).
- New flow **`29_never_silent`** through the real hook files (new adapter `fxFuncretTurn()`): escort refused (voiced),
  clothing failed (voiced), goto unreachable in a scene (voiced), quiet success (silent), her own lead move (quiet,
  told next turn), rows without follow-up (context_pre ends it, told next turn), closed / public / blind requests
  (voiced reason), ComeCloser failed and succeeded, escort late failure.
- Updated: flow 13 (rows + voiced goto), 16 (success ends pre-lock), 27 (blind directive), 28 (success handled),
  11 (off variants never voice a failure); test_latency 6.

## 8. Notes and residual risks

- **On deploy the glue's catalog rows are rewritten once** (v11) on the first turn: any owner edit to the glue rows in
  CHIM's Action Editor is reset, and a new **inactive `GlueEscort`** row appears - it must stay switched off (it is
  never offered even if on; the post-gate would drop it). Until the rows are rewritten a failure is told next turn
  (gate), never lost.
- Order of speech: on "follow me" she answers on the request turn ("do it now"), and if the game then refuses she
  says why in the funcret turn right after - two lines, by design (addendum 11 d).
- Not voiced by design: her own scene-lead moves (`voice.lead_failures` to change), the hold carrier, dry runs,
  `adults only` / the in-game feature switch, a second failure within 8 s (told next turn), Phase 2 `SelectTopic`
  results (the dialogue lane's own `<what_just_happened>` path), and a paid start the post-gate drops as unaffordable
  (no funcret exists; told next turn as before).
- The movement watch needs snapshots of that NPC (only while she is watched) and `dist=` (game >= 401) for the
  distance test; without them only the engine-scene test applies.
- The escort's late failure is read from a GAME log line (sent only while `bDebugLog:General` is on, the default).
  Suggestion for the game lane: report it as a fact of its own; the server would only need a second reader.
- Suggested deploy-script anchors (outside this lane): `lrgVoicedGate() !== '') { terminate(); }` in
  `context_pre.php`, `lrgVoicedCue(` in `prompts.php`, `return lrgFuncretVerdict($text);` in `lib/lrg_actions.php`.
- `glue\README.md` version marker still says 0.5.4 (outside this lane).
- Not touched: CHIM core, other mods, profile files, anything under F:\Modlists, the game scripts, the deploy script.

## 9. Files changed

`server/lorerim_glue/lib/lrg_actions.php` (rows v11 + escort row, `lrgFuncretVerdict`, `lrgVoiceSkip`,
`lrgVoicedWhat/Why/Fact/Directive/Cue`, `lrgVoicedGate`, `lrgChimWillVoice`, `lrgNoteSent`, `lrgSentFind`,
`lrgMoveWatchNote`, `lrgNoteEscortLate`, `lrgMissedNote`, voiced mode, runtime, directives, reason words) ·
`lib/lrg_core.php` (`LRG_VOICE_DEFAULTS`, `lrgVoiceCfg`, `lrgMoveWatchCheck`, version) · `lib/lrg_intent.php`
(directive wording, blind shape, public shape, money) · `prompts.php` (voiced cue) · `context_pre.php` (voice gate) ·
`preprocessing.php` (comment) · `config/lrg_config.default.json` (`voice` block) · `manifest.json` (0.5.5) ·
`PROTOCOL.md` (header, 1.6, 2, 5, 6.2, 7.2, 9, 10.20, new 10.21) · `tools/test_gates.php` · `tools/test_latency.php` ·
`tools/flows/adapter.php` · `tools/flows/scenarios/29_never_silent.php` (new) · `11_kill_switch_sharmat.php` ·
`13_speed.php` · `16_hook_wiring.php` · `27_blind_snapshot.php` · `28_escort.php`.
