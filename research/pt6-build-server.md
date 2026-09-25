# pt6 SERVER FIX PASS - build report

Scope of this agent: `glue/server/lorerim_glue/**`, `glue/PROTOCOL.md`, `glue/tools/test_*.php`,
`glue/tools/flows/**`. No deploy. No game-side edits.

Status: IN PROGRESS (file written early, appended as work proceeds).

## 0. Inputs read

- Round prompt (defect list) - the only user voice is the relayed request
  "if she initiates any new scene/animation she should say something signifying it"
  (= owner addendum 2 / revised R10). It wins over everything else.
- `glue/OWNER_ADDENDA.md` - read FIRST as instructed. Three addenda:
  1. she must speak before initiating a makeout;
  2. **every** self-initiated scene/animation change needs a short line (the relayed request);
  3. **no in-scene refusals** - remove the Decline action, refusal-marker detection, per-act
     decline memory, "a decline is final for that act". Consent is decided before the scene only.
     R11 (SHE proposes a sex act, player may say yes/no) is explicitly KEPT.
- Not read (hard rule): `research/*.md`, `PHASE*.md`, `REVIEW*.md`, `REFINE*.md`, old playtest notes.

## 1. Defect ledger

Baseline before any edit: flows 32/32, 644 checks, 0 fail; test_gates 206/0; test_intent 105/0;
test_scene_index ALL CHECKS PASSED. So every defect below was invisible to the suite, exactly as reported.

### FIXED (code)

| # | sev | defect | where fixed |
|---|---|---|---|
| D1 | major | "you lead" decorated with `hold=` -> the game gags her for 150 s (reported 3x: D1/D8/D14) | `lib/lrg_actions.php` `lrgDecorate()` |
| D2 | major | safety net cannot correct a wrong-`who` clothing command (who-inversion survives) | `lrgPostProcessActions` CLOTHING branch + new `lrgCorrectFromIntent()` + `lrgIntentSatisfiedBy($strict)` + `lrgDropUnaskedGoto()` |
| D3 | major | `BeginIntimacy` with an EMPTY message still starts when the player asked for contact | `lrgIntentSatisfiedBy()`: START is never intent-satisfied |
| D4 | major | `lrg_initiative` cue still licenses "an action with an empty message" (reported 2x) | `prompts.php` |
| D5 | major | hedged "yes" / unrelated "ok" executes the proposed sex act (R11) | `lib/lrg_intent.php` yes branch + `lrgIntentBareYes()` |
| D6 | major | "that's enough, fuck me" ends the scene (^-anchored frame) | `lib/lrg_intent.php` `$isStop` branch + `lrgIntentAfterStop()` |
| D7 | major | proposal notes ("choose no action") collide with the turn directive ("Do it now") | `lrgPickProposal()`: steps aside for every `LRG_INTENT_ACTIONABLE` kind |
| D8 | major | no-op hold carrier cancels a running wind-down | `lrgHoldCarrier()` returns null while `wd=1` (server half; game half is the primary fix) |
| D9 | minor | `command_confirm_seconds` 20 < the game's worst-case confirm time | `config/lrg_config.default.json` -> 40 |
| D10 | minor | R11 proposal paths re-store the scene row with its own `ev` (resets `_tier_since`, double-counts `_climaxes`) | `lrgRecordProposal()` + `lrgApplyProposalAnswer()` |
| D11 | minor | proposal recorded although the same reply already carried a `do=goto` | `lrgRecordProposal($turn, $emitted)` |
| D12 | minor | R11 cooldown / cap only counted turns she spoke on | `lrgRecordProposal()` counts the asking, pends only the spoken one |
| D13 | minor | silent `SuggestPrivacy` was not dropped (R10c / 6.8) | INVITE branch of `lrgPostProcessActions` |
| D14 | minor | two log lines share the prefix `turn npc=` (reported 2x) | `gate npc=` (:799) and `blocked npc=` (:628) |
| D15 | minor | very generic act words make a rhetorical question a high-confidence request | `lib/lrg_scene_index.php` `lrgActsTable()` |
| D16 | minor | any utterance containing "don't" is discarded whole ("don't stop, harder") | `lib/lrg_intent.php` negation re-test on the matched fragment |
| D17 | minor | a first meeting is treated like a reload (120 s gag) | `lib/lrg_core.php` `lrgNoteSession()` |
| D18 | minor | config readme still states the in-scene decline rule addendum 3b removed | `config/lrg_config.default.json` |
| D19 | minor | `lrgIsSayIt()` dead but undocumented | commented as a test-only predicate; dead marker assertion removed from `test_gates.php` |

## 2. What each fix does, and the reasoning where I did not follow the letter

**D1 - the lead hand-over (`lrgDecorate`).** Three of the listed defects describe this; two of the three
fix texts ask for the intent check and one asks for an unconditional `do=lead;who=npc` skip. I took the
intent-checked version:

```php
$handOver = $do === 'lead' && (string) ($kv['who'] ?? '') === 'npc'
    && (string) ($turn['intent']['kind'] ?? '') === 'lead_npc';
$hold = lrgHoldSeconds($turn);
if ($hold > 0 && !$handOver) { $kv['hold'] = $hold; }
```

The unconditional version would also strip the hold from a lead flip the MODEL chose while the player is
steering, which is the one case where the hold is still wanted. `lrgHoldCarrier()` builds its own kv and
never passes through `lrgDecorate()`, so the §9 carrier is untouched. Both probe paths (model-chosen and
net-built) are covered because both run on a `lead_npc` turn.

**D2 - the who-inversion.** Three layers instead of one, because the literal fix ("compare the payload in
`lrgIntentSatisfiedBy`") would have let the model's wrong line and the net's right line BOTH go out. I
verified that: with the source correction mutated out, flow 19 prints two `ExtCmdLRG_Clothing` lines on one
turn. Layers: (1) `lrgCorrectFromIntent()` overwrites `who` / `part` in the CLOTHING branch from a
high-confidence intent of the same verb; (2) `lrgDropUnaskedGoto()` also drops a `speed` / `furniture` line
whose value contradicts the recognised one (those resolve through `lrgResolveControl` and cannot be
corrected in place); (3) `lrgIntentSatisfiedBy(..., $strict = true)`, used ONLY by the net, compares
`who` / `part` / `speed` / `furn`. The loose reading stays the default for the 6.8 say-it-first test, so a
player-requested change of clothes is still not treated as a move of hers that needs announcing.

**D3 - `StartIntimacy` is never intent-satisfied.** Exactly the literal fix. `lrgDecorate()` is unaffected
(START returns early with `wait=end` before the `do`-dependent keys). Confirmed by mutation: with the old
line restored, "kiss me" and "get naked" both let a message-less start through.

**D5 / D6 / D16 - the recogniser.** The three fix texts were directionally right but under-specified, and
two of them do not work as written on this code. Recorded here because the critic will want the reasoning:

- *hedged yes*: the suggested "fall through when the remainder still matches another actionable pattern"
  is what I built, but implemented as a **bare-affirmation** test (`lrgIntentBareYes()`), which is the
  stronger form: everything that is not purely the affirmation falls through to the ordinary scan.
  "okay, hold on" therefore becomes a `hold` (the thing the player actually asked for). I also added
  "not now" / "not yet" / "maybe later" to the `no` pattern's second alternation, so "yes, but not now"
  is read as the refusal it is instead of vanishing.
- *"that's enough, fuck me"*: the suggested `$frame || lrgIntentFrame($scan['frag'])` does **not** fix it -
  the act branch of `lrgIntentScan()` hands back the WHOLE utterance as its fragment, so the two tests are
  identical. And the suggested alternative ("a resolved payload of another kind wins") breaks the stop
  rail: "stop, I can't take any more of this fucking" resolves `vaginal`. I judge the frame on the
  **remainder after the stop clause** (`lrgIntentAfterStop()`) and removed `$frame`-on-the-whole-utterance
  from that test entirely - `stop` is itself an `LRG_INTENT_FRAME1` verb, so every "stop, …" counted as
  framed, a pre-existing hole the new tests now pin down. A fragment is trusted only when the scan really
  narrowed one down. When the remainder wins it also sets `$frame`, so the request keeps `conf=high`.
- *"don't stop, harder"*: testing the negation on the scan's fragment is unsafe here, because the
  `$viaControl` branches hand back a CANONICAL word ("slower") rather than the matched text - "don't slow
  down" would have become a `slower`. Instead the RAW utterance is split on `, ; but` (commas do not
  survive `lrgIntentClean()`) and only clauses free of the negation are scanned. A single-clause negation
  stays blocked, so "don't get naked yet" (owner addendum 3d, verbatim) is unchanged.

**D9 - `command_confirm_seconds`.** The list gives 40 twice and 35 twice against a stated floor of
`20 + fSayFirstMaxWait` (32). I used **40**: it satisfies every stated floor, and the value is log-only
(no retry), so erring high costs nothing but a slightly later WARN. Both the config readme and PROTOCOL
now carry the derivation so it is raised with `fSayFirstMaxWait`.

**D8 - the wind-down carrier.** The defect prefers the GAME fix and I did not touch the game. I added the
server guard as well (`lrgHoldCarrier()` returns null while `wd=1`) because it is two lines, cannot
misfire (a carrier during a wind-down is a no-op by construction), and protects an un-updated game.
PROTOCOL now names both halves.

**D15 - generic act words.** Done as written for the acts table. **Residual, deliberately not fixed** - see
section 4.

## 3. PROTOCOL.md (v0.3) changes

| § | change |
|---|---|
| 1.1 | new paragraph: a first meeting is not a reload; `session_at` needs evidence |
| 1.2 | `sess` table row corrected: "every push EXCEPT the ordinary `ev=end`", with the three code sites and the note that nothing depends on it |
| 2 | `hold=` row now says "with ONE exception"; new paragraph **The ONE `hold=` exception** (the `lead_npc` hand-over), and a new paragraph on the wind-down guard for the hold carrier |
| 6.4 | the whole v0.2 "say it / silent" R10 bullet REPLACED by the v0.3 rule (every self-initiated change is preceded by a spoken line; `blunt` decides only how blunt). `lrgIsSayIt()` named as a test-only predicate |
| 6.8 | new paragraph **What is exempt, exactly** - `StartIntimacy` and `SuggestPrivacy` are NEVER exempt; explicitly overrides the old "anything the player asked for is exempt" reading, which contradicted §6.5 |
| 6.9 | NEW section: R11 in full (steps aside for every actionable intent; counting vs pending; not recorded after a `do=goto`; the row is re-stored without `ev` / `sync` and why) |
| 7.2 | recogniser: `yes` is no longer blocker-exempt and must be the whole answer; the stop-vs-frame rule; the clause-level negation. Safety net: "already satisfies" compares the payload, in three layers |
| 7.x test contract | the "say-it / silent" test bullet replaced by the v0.3 expectation |
| 8 | `command_confirm_seconds` 40 with its derivation; `announce_chance` marked as renamed |
| 9 | the `turn npc=` prefix belongs to the G6 line ALONE; `gate npc=` and `blocked npc=` listed as the extra lines they are, plus the other recurring prefixes |

## 4. NOT fixed here - handed on

1. **[major] The ~90 s snapshot-staleness gap** (a scene turn goes BLOCKED and every spoken request except
   "stop" is lost). The defect's own fix is GAME side (`LRG_OStim.ThreadTick` -> `Main().MaybeSnapshot()`)
   and says explicitly: *"Do NOT soften the server-side staleness rule; it is the adults-only fail-closed
   rail."* No server change made. The server half is already correct.
2. **[minor] The announce/TTS ordering assumption.** No code change by design - the instrumentation exists.
   Read the `announce ... reason=` lines in playtest 7: `reason=spoke` means the design holds, a run of
   `reason=timeout` means `wait=` should be downgraded to `begin` everywhere.
3. **[minor] `glue/README.md` is still the 0.2.0 page.** Outside my file scope (README is
   ARCHITECT / INTEGRATOR-owned per PROTOCOL 9). Still needs: the version line, the MCM options table
   (35 ids) and the 0.3 config-key list.
4. **[minor] The OStim free-cam / fade owner note.** Outside my file scope (README "Known gaps" or the MCM
   Intimacy help text). The glue is forbidden to change OStim MCM values, so this has to be one sentence
   telling the owner to turn free cam at scene start OFF (or press numpad `/` during a scene) and
   optionally to turn the intro/outro fades off.
5. **RESIDUAL of D15, new finding.** Dropping `feel` / `taste` / `mouth` / `arms` / `neck` / `inside` from
   `lrgActsTable()` does NOT fully close the hole: `lrgMatchAct()` step 3
   (`lib/lrg_scene_index.php:969-980`) also reads `scene_index.synonyms`, where
   `config/lrg_config.default.json:371` still maps `"feel" -> groping*` and `:377` maps
   `"arms" -> hugging/embrace`. So "can you feel that?" (which `lrgIntentPolite()` reads as a request
   frame) still resolves to `grope:npc` at `conf=high`. I did **not** remove those synonym keys:
   `mouth` and `neck` are asserted on by `tools/test_scene_index.php:337` and `:347` ("use your mouth",
   "kiss my neck"), they are SCENEINDEX vocabulary the position search needs, and hand-pruning them would
   degrade real requests. The clean fix is a confidence downgrade, not a vocabulary edit: when the ONLY
   act evidence in an utterance is a generic word, the recognition should be `low` (directive = "may have
   asked", net silent). That is a design decision, so it is handed to the next stage rather than taken
   unilaterally. `tools/test_intent.php` now asserts the acts-table half and documents the residual.
6. **`LRG_ACTIONS_VERSION` left at 8, manifest left at `0.3.0`.** Both were already bumped by the earlier
   stage (`manifest.json` = 0.3.0, `LRG_ACTIONS_VERSION` = 8 with the five-row v8 catalog). Nothing in this
   pass changed a catalog ROW - the only prompt change is the `lrg_initiative` cue in `prompts.php`, which
   is not a catalog row - so a further bump would force a pointless catalog rewrite on first request.
   `tools/test_gates.php:23` asserts `LRG_ACTIONS_VERSION === 8`.
7. **Not deployed**, as instructed. `tools/deploy_server.ps1` was not run.

## 5. Tests

Every suite re-run to green after every edit.

| suite | before | after |
|---|---|---|
| `tools/flows/run_flows.ps1` | 32 scenarios, 644 checks, 0 fail, 0 pending, 0 warn | **32 scenarios, 666 checks, 0 fail, 0 pending, 0 warn** |
| `tools/test_gates.php` | 206 passed, 0 failed | **206 passed, 0 failed** |
| `tools/test_intent.php` | 105 passed, 0 failed | **141 passed, 0 failed** |
| `tools/test_scene_index.php` | ALL CHECKS PASSED | **ALL CHECKS PASSED** |
| `php -l` | clean | clean (every plugin + tool file) |
| `lrg_config.default.json`, `manifest.json` | valid | valid JSON |

### New / changed test coverage (the defects noted the gaps explicitly)

- `flows/19` (+6): the who-inversion end to end - the intent, the directive wording, the model's wrong
  target corrected to ONE line with `who=player`, the net building the same line, and the speed value case.
- `flows/20` (+7): the hand-over reaches the game with `who=npc` and **no `hold=`**, on both the
  model-chosen and the net-built path; a lead flip nobody asked for still carries the hold; no hold carrier
  during a wind-down.
- `flows/21` (+4): "kiss me" / "get naked" / "I want you right now" followed by a message-less
  `BeginIntimacy` still does not start the scene; with her line it starts at once.
- `flows/02` (+1): a silent `SuggestPrivacy` is dropped, nothing is recorded, `say_first` is set.
- `flows/24` (+4): the asking is counted even when she said nothing (cooldown really holds), and recording
  a proposal does not restart `_tier_since` / `_climaxes` and does not close the row.
- `flows/25` (+2): a reload needs a DIFFERENT session tag; the first snapshot an NPC ever sends is a first
  meeting. (The old assertion asserted the buggy behaviour and was rewritten - see deviations.)
- `test_intent.php` (+36): hedged yes / bare yes tables, the stop-vs-request table in both directions,
  the clause-level negation, and the acts-table word assertions.

### Mutation proof

To prove the new tests are real regression tests and not tautologies, three fixes were reverted in place
and the suite re-run:

| reverted | result |
|---|---|
| `$handOver = false` | `flows/20` FAIL x2 - wire showed `do=lead;who=npc;hold=150` on both paths |
| `lrgCorrectFromIntent()` removed | `flows/19` FAIL - **two** `ExtCmdLRG_Clothing` lines on one turn, the model's `who=npc` and the net's `who=player` |
| `LRG_ACT_START` intent-satisfied again | `flows/21` FAIL x2 - "kiss me" and "get naked" let a message-less start through |

The file was restored from backup and all suites re-run to green afterwards.

## 6. Deviations from the letter of the task

1. **Three fix texts were implemented differently** (D2, D6, D16) because the literal versions do not work
   on this code or break a harder rail. Reasoning in section 2; each is covered by a new test.
2. **`command_confirm_seconds` = 40** where the list gave 40 twice and 35 twice. 40 satisfies every floor.
3. **Two flow assertions were rewritten rather than kept green.** `flows/25` asserted that the first
   snapshot an NPC ever sends is treated as a reload - that IS defect D17, so the test was asserting the
   bug; it now asserts the corrected contract and a second case was added for the real reload.
   `flows/24`'s proposal counters changed by design (D12), so its `_proposals` expectations moved from
   1 to 2 and a cooldown step was inserted. No other test was weakened.
4. **`lrgNoteSession()` is slightly stronger than the defect's fix text.** "Only stamp when a previous
   sess existed" would lose the stamp for a bystander NPC during a REAL reload (flow 22 proves it). The
   rule is now "a previous tag for this NPC, **or** an open row from another session was just closed".
5. **The server half of the wind-down carrier guard was added** although the defect prefers the game fix.
   Additive, cannot misfire, protects an old game. Both halves documented.
6. **A pre-existing undefined variable was fixed** in `flows/scenarios/24_ask_first.php:42` (`$sensual`
   -> `$stage`, on the pending path only), and a dead marker assertion was removed from
   `tools/test_gates.php` (it computed `$marksOk` from the deleted `[say it]` / `[silent]` markers and
   never asserted it).
7. **Scratch files live in `%TEMP%`, not the session scratchpad.** The scratchpad path is ~270 characters,
   past Windows' `MAX_PATH`: PowerShell can enumerate a file there but cannot execute or resolve it. The
   runner is `%TEMP%\lrg_run_unit.ps1`; nothing was written into the project outside my file scope.
8. **`glue/OWNER_ADDENDA.md` addendum 3b (no in-scene refusals) was already fully implemented** by the
   earlier stage - no `Decline` action, no refusal-marker detection, no per-act decline memory anywhere in
   the plugin (`lrg_actions.php:1313-1314` documents it). Nothing to remove. The only addendum-3 residue I
   found was the config readme text, fixed as D18.

## 7. Files changed by this agent

```
glue/PROTOCOL.md
glue/server/lorerim_glue/prompts.php
glue/server/lorerim_glue/lib/lrg_actions.php
glue/server/lorerim_glue/lib/lrg_core.php
glue/server/lorerim_glue/lib/lrg_intent.php
glue/server/lorerim_glue/lib/lrg_scene_index.php
glue/server/lorerim_glue/config/lrg_config.default.json
glue/tools/test_gates.php
glue/tools/test_intent.php
glue/tools/flows/scenarios/02_public_invitation.php
glue/tools/flows/scenarios/19_intent_directive_net.php
glue/tools/flows/scenarios/20_lead_hold.php
glue/tools/flows/scenarios/21_say_first.php
glue/tools/flows/scenarios/24_ask_first.php
glue/tools/flows/scenarios/25_heat_and_start.php
```

Nothing outside the allowed scope was touched: no game scripts, no MCM, no OStim / OARE / LoreRim / CHIM /
HerikaServer file, no deploy.

Status: **COMPLETE**.
