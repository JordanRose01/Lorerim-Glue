# pt8 - INTIMACY lane build report (v0.4.0 round)

Lane D (INTIMACY). Built from `research/pt8-intimacy-fixes.md` (the investigator's design) plus
OWNER_ADDENDA items 4 and 6. Nothing was deployed or installed.

Files I may edit and did edit are listed in section 9. Everything else was read only.

STATUS: **DONE.** Not deployed, not installed.

---

## 0. The three deliverables

| # | feature | design source | state |
|---|---|---|---|
| 1 | "EVERYONE HAS A PRICE" - paid intimacy | pt8-intimacy-fixes §1 + §7, OWNER_ADDENDA 6 | **built**, 296/183/14 tests green |
| 2 | post-scene control on every scene-end path (listener hold, controls self-repair) | §2.3 F1-F3 | **built**, compiles clean |
| 3 | closed-door privacy (LOS / door witness rule, `door=` fact, server words) | §3.3 P1-P5 | **built**, both halves |

### Files changed (all inside this lane's allowed set)

```
glue/server/lorerim_glue/config/lrg_config.default.json    paid_intimacy block, per-rule price keys, privacy readme
glue/server/lorerim_glue/lib/lrg_core.php                  price engine, offer, gate hook, privacy words
glue/server/lorerim_glue/lib/lrg_intent.php                money recogniser + the money directives
glue/server/lorerim_glue/lib/lrg_actions.php               catalog row v10, post-gate pay rules, prompts, logs, F2
glue/game/LoreRimGlue/Source/Scripts/LRG_Profile.psc       DoorState, the witness rule, door=/paidok=/pm= on the snapshot
glue/game/LoreRimGlue/Source/Scripts/LRG_OStim.psc         listener hold, controls repair, the gold transfer, paid=
glue/tools/test_gates.php                                  pgold fixture + sections 32 and 33
glue/tools/test_intent.php                                 section I (money)
glue/tools/test_phrases.php                                the "money" MUST group, 25 rows x 3 states
research/pt8-intimacy-handoff.md                           the handoff (MCM keys, wire keys, actions version)
```

Untouched, as required: `lrg_scene_index.php`, `migrations/` (no `004_*` was needed - the
`gold_accepted` column already exists in `001`), every other lane's file, and everything outside the
glue.

---

## 1. Code reconnaissance (what the build has to fit into)

Verified by reading the installed sources this round (line numbers are the PRE-edit file):

| fact | where |
|---|---|
| `WitnessScan`'s no-LOS hearing clause | `LRG_Profile.psc:158` `elseif d < 600.0 \|\| a.HasLOS(akPlayer) \|\| a.HasLOS(akNpc)` |
| the one and only witness counter, re-run game side | `LRG_OStim.PrivacyBlockedReason:2243` calls the same global |
| `pgold=` already in the snapshot | `LRG_Profile.psc:178` |
| `BuildSnapshot` is a GLOBAL in a `Hidden` script | `LRG_Profile.psc:305` - no script state, so no cache can live there |
| `PlaceFacts` (where the design wanted the door cache) is in `LRG_Main.psc:704` | **NOT MY LANE** - see §3 for how the door fact gets in without touching it |
| `LRG.GetMain()` is a global accessor | `LRG.psc:6` - lets a `Hidden` global read MCM settings |
| `FinishThread` releases the listener one line after arming the outro | `LRG_OStim.psc:3650` `BeginOutroHold(...)` / `:3651` `ReleaseListener(...)` |
| `ResetState` releases the listener too | `LRG_OStim.psc:433` - runs at `:3663`, i.e. AFTER the hold would be armed |
| `ForceListener` early-returns on `partner == None` | `LRG_OStim.psc:693` - hence the `ForceListenerOn` refactor |
| `StopScene` releases the listener | `LRG_OStim.psc:3409` |
| `OnOStimThreadStart` / `CmdStart` already release the outro hold | `:3428` / `:1513` |
| `Tick()` calls `TickOutro` first | `LRG_OStim.psc:3705-3707` |
| `PushState` de-dup key + emission | `LRG_OStim.psc:3944` / `:3958-3988` |
| `BeginStartThread`'s `tid < 0` early return | `LRG_OStim.psc:1736-1744` - the transfer goes after it |

---

## 2. SERVER SIDE - built and green

`php -l` clean; `test_gates` **296 pass / 0 fail**, `test_intent` **183 / 0**, `test_phrases` **14 / 0**
(hit rate 91.8%, floor 82%). Baseline before this round was 238 / 142 / 13.

### 2.1 "Everyone has a price" - what the code does now

| piece | where | note |
|---|---|---|
| config block `paid_intimacy` | `config/lrg_config.default.json` | days-of-wage economy, one rescaling number |
| per-rule `price_band` / `money_influence` / `not_for_sale` | every shipped status rule + `fallback` + `npc_overrides.Serana` | reproduces the design's recommended table exactly |
| `lrgWealthTier()` | `lib/lrg_core.php` | the twin `lrgWealthWords()` never had; the words now come from it, so they can never disagree with the band |
| `lrgPriceFor()` | `lib/lrg_core.php` | the nine numbered steps, all of them logged |
| `lrgPurseWord()` | `lib/lrg_core.php` | the ONLY thing said about the player's purse (`pgold` never reaches a prompt) |
| `lrgPaidOffer()` | `lib/lrg_core.php` | its own regex pass, because the gate runs before the recogniser (§7.1) |
| `lrgPriceCouldOpen()` | `lib/lrg_core.php` | **new, not in the design** - see the deviations in §5 |
| `lrgRememberQuote()` / `lrgPriceDecision()` / `lrgLogPrice()` | `lib/lrg_core.php` | idempotent quote memory, the one-word decision, the `price` log line |
| the gate hook | `lrgEvaluateGates()` | removes `not_close_enough` and nothing else |
| `lrgIntentMoney()` + `lrgIntentAmount()` + `lrgWordAmount()` | `lib/lrg_intent.php` | pre-blocker scan, closed number-word table |
| `lrgMoneyDirective()` | `lib/lrg_intent.php` | six shapes, words only, never an order to accept |
| `lrgMoneyGuidance()` | `lib/lrg_actions.php` | the words + exactly ONE number + one purse word |
| `lrgPayForStart()` + `lrgAmountFromParam()` | `lib/lrg_actions.php` | the five post-gate rules |
| halved gain / `gold_accepted` / memory / outro fact / turn-line tail | `lib/lrg_actions.php` | all keyed on the game-confirmed `paid=`, never on the `pay=` the server sent |

Worked prices, asserted in `test_gates` section 32 (gold_per_day_of_wage 35, round_to 5):

| rule | band (days) | influence | indifferent | curious |
|---|---|---|---|---|
| beggar | 0.5-2 | 0.5 | 35 | 20 |
| tavern_folk / commoner | 1-4 | 0.8 | **110** | 35 |
| priest_dibella | 1-4 | 1.2 | 170 | 40 |
| innkeeper | 4-15 | 0.8 | 420 | 140 |
| merchant / adventurer | 4-15 | 1.6 | 840 | **225** |
| guard | 15-60 | 2.5 | 5250 | 1315 |
| housecarl / court / priest | 60-300 | 1.0 | 10500 | 2100 |
| jarl | 300-3000 | 1.0 | **105000** | 10500 |
| priest_mara | - | - | `not_for_sale` | - |
| vigilant | - | - | `never` (was already) | - |
| fallback (unknown mod faction) | 4-15 (modest) | 1.6 | 840 | 225 |

Modifiers, each with its own asserted case: married-secret x3 (420 -> 1260), Dragonborn x0.75 (110 -> 85),
repeat customer x0.85 on the DAYS (110 -> 95), `pm` absent or 0 -> x1.0, `pm=2.00` -> 225 (the rounding
is applied once, at the end - so it is not exactly double).

### 2.2 Closed-door privacy, server half

`lrgPrivacyWords()` (new) + `lrgPlaces()`'s `quiet` wording + `door=`/`wit=` on the `gate` log line.
**No server gate logic changed at all**: the witness count is corrected at its source in the game, so
`wit=` keeps exactly one meaning.

### 2.3 Post-scene control, server half

`lrgWarnNarratorAfterScene()` (F2): one `WARN` line when a player utterance is answered by CHIM's
Narrator within `narrator_warn_seconds` (120) of a scene end. No behaviour change - it is the owner's
way of seeing the game-side listener hold work.

---

## 3. GAME SIDE - built and compiling

`LRG_OStim.psc` 4082 -> 4509 lines, `LRG_Profile.psc` 357 -> 474 lines. Compiler: **0 errors,
0 warnings** (see §6 for the one thing that blocks the full `compile.ps1` run, which is not mine).

### 3.1 F1 - the listener is the LAST thing to go, not the first

New state: `listenerActor` / `listenerName` / `listenerUntil`, plus `outroDoneAt`.

| change | where |
|---|---|
| `ForceListener(why)` -> a thin wrapper over the new `ForceListenerOn(akWho, asName, asWhy)` | the old one returned early on `partner == None`, and `FinishThread -> ResetState` clears `partner` two lines after the hold is armed |
| `FinishThread` arms the hold instead of releasing the listener | `listenerUntil = endAt + fListenerHold + fOutroHold`, on `outroActor` when the outro hold took, otherwise on the partner - so a `how=lost` thread (no outro at all) is still covered |
| every reason not to hold is checked BEFORE the clock is armed | `bForceListener`, `bIntimacyEnabled`, `fListenerHold > 0`, an actor and a name - so `listenerUntil` never points at a hold `ForceListenerOn` would have refused |
| `ResetState` releases only when `listenerUntil <= 0.0` | the same ordering trap the outro hold already documents |
| `ReleaseListener` always clears the hold's own state | so a stale actor can never keep `TickListener` alive, even on a path that never armed it |
| `TickListener(now)` - eleven exits | switches off; self-heal; a new scene; she is dead/disabled/unloaded/unconscious; combat or the player dead; distance (`fOutroFar`); another cell (the guarded interior test); the player looking at a different actor within 400 units; she is audibly talking (EXTEND, never cut); her goodbye finished + `fListenerGrace` with nothing said since; the clock |
| `ExtendListener` from `NoteActivity` / `NotePlayerSpeech` | the conversation being alive is the whole point |
| the hold is dropped by `CmdStart`, `OnOStimThreadStart`, `StopScene` and `Maintenance` | a new scene, the stop hotkey, a game load |
| `outroDoneAt` set in `ReleaseOutroHold` only for `"she has said her piece"` | every other reason leaves it 0, and then only the ordinary clock applies |

Worst case the listener stays forced for `2 x fListenerHold + fOutroHold + 30 s` = **95 s** at the
defaults, and the 60 s re-assert in `ThreadTick` is not used for it (that path only runs while a
thread runs), so the number of `setDrivenByAIA` calls does not go up.

### 3.2 F3 - the camera / controls self-repair

`TickControls(now)`, in the 10 s after a scene end, behind `bFixControlsAfterScene`. It fires only
when `Game.GetCameraState() == 3` (free) **or** `IsMovementControlsEnabled()` /
`IsLookingControlsEnabled()` read false, and only with no running or starting thread, no quest scene
and no menu open. Then `EnablePlayerControls()` + `ForceThirdPerson()` (only for the free camera), and
it logs every time. If the line never appears, the camera half of the complaint was the narrator half.

### 3.3 Closed-door privacy, game half

| change | where |
|---|---|
| `LRG_Profile.DoorState(akPlayer, afRadius)` | `FindAllReferencesOfFormType(player, 29, r)` capped at 12 refs, `GetOpenState()` 1/2 = open -> 0; interiors only |
| `LRG_OStim.DoorFacts(akPlayer, abFresh)` | the cache (same cell, moved < 200 units, < 15 s). The design put this in `LRG_Main.PlaceFacts`, which is another lane's file this round - see the deviations |
| `WitnessScan(..., abDoorShut = false, afHearRadius = 600.0)` | **the actual behaviour change, three lines**: LOS to either actor is always a witness; the no-LOS hearing clause applies only when no door is shut. The defaults reproduce the old behaviour exactly |
| `BuildSnapshot` | `door=` right after `interior=`, and the two new arguments into `WitnessScan` |
| `PrivacyBlockedReason` | computes the same two arguments with `abFresh = true`, or the game-side re-check would refuse what the server allowed |

### 3.4 Paid intimacy, game half

| change | where |
|---|---|
| `StartBlockedReason` | after `PrivacyBlockedReason`, before the transient `pendingVerb` check: `pay > 0` and the purse is short -> `"the player does not have that much gold"`. Refused outright, so it never occupies the single queue slot |
| `CmdStart` | reads `pay=`, remembers it in `startPayGold`, and in dry run logs `DRY RUN WOULD PAY <n> gold` and moves nothing |
| `BeginStartThread` | **the transfer**, immediately after `tid >= 0`: re-check the purse, then `RemoveItem(Gold(), n, false, npc)`, `scenePaidGold = n`, logged. Before `GuardStart` on purpose, so `paid=` is already set when `ostim_thread_start` triggers the first push |
| a scene that has already started is NEVER cancelled over gold | it runs, `paid=0` is reported, the halved gain does not apply, and the log says so - the safe direction |
| `PushState` + the hand-built `ev=end` | `paid=<n>` on every push of a paid scene |
| `ResetState` / `Maintenance` | both counters cleared |
| **no refund, ever** | the owner's rule; there is no code for it, only a comment saying so |

### 3.5 The load-time NOTE line

`Maintenance` writes one line per game load when an ON-by-default 0.4 toggle reads off:

```
NOTE these 0.4 settings read OFF (a missing settings.ini default line reads as 0): bPrivacyDoors bFixControlsAfterScene fListenerHold=0
```

This exists because a missing MCM ini key reads as FALSE and `LRG_Main.SettingBool` can only honour
its code default when MCM Helper is absent entirely. It is how the owner sees, in one line, that a
fix of this round is inert because its ini default line has not landed yet.

---

## 4. Handoff

`research/pt8-intimacy-handoff.md` - the MCM `config.json` + `settings.ini` entries for all eight ids
(six still missing), every additive wire key for `PROTOCOL.md` v0.4, and `LRG_ACTIONS_VERSION = 10`.

---

## 5. Deviations from the design, and why

| # | design said | built | why |
|---|---|---|---|
| 1 | the money sentence is emitted in modes `closed\|public\|private\|follow` | in mode `closed` only when `lrgPriceCouldOpen()` - new function: `not_close_enough` is in the reason list and nothing else except `witnesses` / `companion_present` | **the design would not have worked.** An indifferent NPC always carries `not_close_enough`, so `lrgGateMode()` puts her in mode `closed`, and the closed boundary text says in so many words that "a bigger offer changes nothing". The player could offer gold and have it work, but could never ASK the price. `lrgPriceCouldOpen()` opens the subject exactly where coin really is the obstacle, and swaps that one clause of the boundary text so the prompt does not contradict itself. Where something a price cannot fix is in the way (a marriage she keeps, a fight, a quest scene) no figure is named at all |
| 2 | the money directive is built after the mode-`closed` branch | before it | same reason as 1; `lrgMoneyDirective()` applies its own closed-mode rule |
| 3 | rule 3 (unaffordable) also writes `lrg_memory.last_result` for a gate-level refusal | only for the post-gate drop (a gift on the FREE path). A gate-level refusal is answered by that turn's own directive instead ("That is more coin than the player is actually carrying") | the gate's affordability check and the post-gate's read the SAME `pgold`, so a gate-accepted offer can only turn out unaffordable on the free path. The directive is better than `last_result` anyway: it is in her mouth this turn, not the next |
| 4 | `haggle` patterns as one list | split into unmistakable phrases and weak ones (`come down`, `half that`, `that's a lot`, `less than that`), the weak half gated on a coin word, the word "price", or a live quote | the money scan runs before every other pattern, so an unqualified `come down` mid-scene would have been answered with "nothing is bought or sold" instead of being read as the request it is |
| 5 | `askprice` before `haggle` | `haggle` before `askprice` | "come down on your price" carries both and the haggle reading is the informative one. Every `askprice` phrase still wins on its own |
| 6 | the door cache lives in `LRG_Main.PlaceFacts` | in `LRG_OStim.DoorFacts`, reached from `BuildSnapshot` through `LRG_Main.GetOStim()` | `LRG_Main.psc` is another lane's file this round. Same cache shape as `FurnitureFacts`, but 15 s / 200 units rather than 60 s / 500: a door can be opened at any moment and this decides a privacy gate. The game-side re-check at scene start passes `abFresh = true` |
| 7 | `pm=2.00` "doubles" the price | 110 -> **225**, not 220 | the rounding to `round_to` is applied once, at the end - which is the design's own step 8. Asserted as 225 |
| 8 | `price_gold` skips steps 2-8 | as written, including the `free` check | an owner-pinned figure is the figure. For a willing NPC it changes only the prompt words, because `not_close_enough` is not in her reason list anyway |
| 9 | `Serana`: `not_for_sale` recommended | left computed (`money_influence: 4.0` from her `gold_sway`), with the recommendation written into her `_price_note` | the design says "not decided here" and it is a taste call. One line either way |
| 10 | (not in the design) | `paid_intimacy.ignore_game_switch`, default false | the escape hatch for `bPaidIntimacy` reading FALSE because its ini line is missing. One server key instead of an MCM file edit |

Also worth knowing: the figures that used to sit in two shipped status-rule `note` strings (the tavern
girl's 110, the jarl's 105000) were moved into `_price_note` keys. A `note` goes straight into the
prompt, and "the absence of a number is the rail" has to hold for an NPC whose price is switched off.

---

## 6. What is NOT green, and what is not mine

**`tools/compile.ps1` does not print OK** - and the only reason is another lane's file:

```
C:\...\lrg_build\src\LRG_Dialogue.psc(651,13): IsDriving is not a function or does not exist
```

`LRG_Dialogue.psc` is the menuless-questing lane's script, mid-edit. Because `LRG_Main.psc` now
references `LRG_Dialogue` and `LRG_DlgProbe`, the whole batch fails with it.

**This lane's scripts compile clean in isolation**, the way `compile.ps1` itself handles a missing
type - declaration-only stand-ins in the import path, no file of another lane modified:

```
staged: LRG.psc, LRG_Main.psc, LRG_MCM.psc, LRG_OStim.psc, LRG_PlayerAlias.psc, LRG_Profile.psc
stubs:  LRG_Dialogue.psc, LRG_DlgProbe.psc, LRG_DlgUI.psc   (Scriptname + the members LRG_Main calls)
-> 0 error(s), 0 warning(s); Compilation succeeded
-> LRG.pex, LRG_Main.pex, LRG_MCM.pex, LRG_OStim.pex, LRG_PlayerAlias.pex, LRG_Profile.pex
```

Longest string in my sources: a pre-existing 312-character literal (`LRG_OStim.psc:2220`), well under
`compile.ps1`'s 500-character rule; longest docstring 284 characters, also pre-existing.
**Re-run `tools/compile.ps1` once `IsDriving` is resolved** - nothing of mine has to change for it.

Not deployed, not installed, no game file, no CHIM file, no HerikaServer file and no Postgres row
touched. `LRG_Main.CurrentVersion` is still 310 and `manifest.json` still 0.3.1: both belong to other
lanes (see the handoff, §4).

---

## 7. Risks and what to watch on the owner's first paid turn

| # | risk | how it shows, and what to do |
|---|---|---|
| 1 | **no live request has ever carried an `amount`** for one of our rows. If CHIM's strict JSON schema omits `amount` entirely, `parameter_template` resolves to `''` and every acceptance reads 0 | the failure mode is safe by construction: free scenes, never a wrong charge. `grep "gate: StartIntimacy passed" lorerim_glue.log` for `pay=`; if it is never there but `price ... decision=accepted` is, the template did not resolve |
| 2 | the catalog row must reach the DB before the first paid turn | `grep "action catalog: rows installed (v10)"`. Until then every acceptance is free |
| 3 | `setDrivenByAIA` is documented as a **toggle** that can remove an active agent, and the hold adds calls after a scene | the hold is bounded and behind `bForceListener`. If she ever stops answering right after a scene, set `fListenerHold = 0` and the old behaviour is back |
| 4 | `door=1` in a large interior with no door within `fDoorRadius` | then anyone who can SEE either of them still counts, so the practical effect is small. Lower `fDoorRadius` if a hall reads as private |
| 5 | whether `FindAllReferencesOfFormType` type 29 returns load doors as well as in-cell doors is unmeasured | either answer is fine: a wrong one gives `door=0` = the old behaviour |
| 6 | `paid_gain_factor` halves the gain for a willing NPC's token GIFT too | the owner's literal wording ("paid sex counts half"). One config key: set it to 1.0, or ask for a `token_free_of_penalty` flag |
| 7 | the three ON-by-default MCM toggles read OFF until their ini lines land | the `NOTE these 0.4 settings read OFF` line, one per game load |

### Owner decisions still open

1. **`Serana`: `not_for_sale`?** Computed she is about 2100 septims to somebody indifferent. Her
   `npc_overrides` entry carries the recommendation in `_price_note`; add `"not_for_sale": true` there
   to close the subject entirely.
2. **`relationship.repair` is still `enabled: true`** with `npc: "Lisette", affinity: 26`
   (`lrg_config.default.json`). The investigator's §5.6 says to switch it off now that it has run - a
   second repair would push her back up to 26 from whatever she has honestly earned since. It also
   bit this build: every test fixture that pins Lisette a negative affinity has to mark the repair as
   already done first, which is what the `$pin()` helper in `test_gates.php` section 32 does.
3. **The CHIM-side privacy levers** (unchanged by decision D2 - the glue writes no CHIM setting):
   `_auto_hearing_radius_m` (CHIM MCM -> Auto Activate -> Hearing, default 8 m, recommended 4-5) and
   the `<nearby_actors>` prompt section, which is what really carries knowledge through walls.
   `chim_mode` should stay `WHISPER`: it is already what stops other NPCs receiving the player's lines.
