# pt7 - SERVER build report (v0.3.1)

Role: SERVER BUILDER. Files touched: `glue/server/lorerim_glue/**`, `glue/PROTOCOL.md`,
`glue/tools/test_*.php`, `glue/tools/flows/**`. **Nothing was deployed.** No file of OStim, OARE,
LoreRim, CHIM or HerikaServer was changed; no database write was made; no game or MO2 process touched.

---

## 0. Test result (the gate for this round)

Run from a staged copy in WSL against the REAL installed packs (index built from `/mnt/f/Modlists/LoreRim`):

| | before | after |
|---|---|---|
| `php -l` (all 13 files) | clean | clean |
| `test_scene_index.php` | ALL PASSED | ALL PASSED |
| `test_gates.php` | 206 / 0 | **235 / 0** (3 new sections, 29 new checks) |
| `test_intent.php` | 141 / 0 | 141 / 0 |
| `test_phrases.php` | did not exist | **13 / 0**, hit rate **84.7 %** (554 of 654), floor 82 % |
| `flows --strict` | 32 scenarios, 666 checks, 0 fail | 32 scenarios, 666 checks, **0 fail** |

The owner's own six phrases pass in **every state in which they make sense**, and every row of the
`must NOT trigger`, `clothes`, `pt7 log`, `pt7 log F1`, `pt7 log F2` and `pt7 log F4` groups passes.

---

## 1. R1 - the relationship

### 1a. The headline defect, fixed: the glue had NEVER read CHIM's affinity
`lrgAffinity()` was guarded by `class_exists('RelationshipManager')`, and that class is loaded only by
`ext/relationship_system/context_pre.php:32`, which `main.php` requires at `:2540` - **after** the ext
prerequest hook at `:1117` where `lrgPrepareTurn()` runs. The guard was false at every call site the
gate uses, so the CHIM term was permanently 0 and every `aff=` ever logged was snapshot bonuses only.

`lrgRelationshipReady()` (`lib/lrg_core.php`) now loads `lib/core/npc_master.class.php` and
`lib/relationship_manager.php` itself, guarded by `is_file()`, memoised, and with the `class_exists`
guard kept around every call so the offline stand-in still works.

### 1b. Missing is not zero
Reads go through `RelationshipManager::getRelationships($npc)` + `isset($rels['Player'])`.
`getRelationship()` returns the same `['aff'=>0]` for "no entry" and for a real 0, which is how a wiped
entry became gate reason `not_close_enough` with nothing in the log to say so.
`lrgChimRelationship()` returns `['found','aff','type']`; a stand-in that only implements the v0.2
reader is still accepted (the flow tests define exactly that).

### 1c. The glue keeps its own copy, and it carries the gate when CHIM's is gone
`migrations/003_lrg_romance_affinity.sql` adds `lrg_romance.last_affinity` / `last_affinity_at`
(`LRG_SCHEMA_VERSION` 2 -> 3). `lrgAffinityInfo()`:

| CHIM | what the gate uses | log |
|---|---|---|
| entry present, non-zero | CHIM's value, and it is stored as our last known | - |
| **no entry at all** | our last known value | `rescued=missing` |
| entry present but 0, ours higher | our last known value | `rescued=zeroed` |

Switch: `affinity.trust_our_last_known` (default true). This is the defence against
`NpcMaster::restoreNPC()`, which runs on every game load and whose `chimRelationshipRestoreQuery` has
no `lock_profile` filter - a history row without a `relationships` key deletes the live entry outright.
**We did not patch CHIM.** See section 7 for the one CHIM *setting* the owner should turn on.

### 1d. History counts
`lrgHistoryBonus()`: `min(30, 15 + 5 * (scenes - 1))` when `lrg_romance.scenes > 0`, added to the
INTEREST score (not folded into affinity, so the two stay separable in the log and a history bonus can
never be written back into CHIM's store). Config `leverage.history_bonus*`.

### 1e. Sex raises the relationship (the owner's third point)
At `ev=end`, when the scene was really open, ran at least `relationship.min_scene_seconds` (60) and
ended `finished` or `stopped`:
`RelationshipManager::adjustRelationship($npc, 'Player', $gain)` - **never with a `$type`**, so the
existing type is preserved and CHIM's reserved romantic types are untouched. `first_scene_gain` 8,
`scene_gain` 4, at most one per `gain_window_seconds` (6 real hours, measured on `lrg_memory.aff_gain_at`).
The write happens inside a live game turn, so `chimRelationshipTimelineStamp` puts it on the game
timeline the owner is actually playing. `extended_data.relationships_locked` is checked by the glue
itself (`lrgRelationshipLocked()`), because CHIM's own setters do not check it.

### 1f. The one-time repair of Lisette (R1d) - READ THIS BEFORE DEPLOY
Config `relationship.repair`, **enabled by default for this deploy only**:

```json
"repair": { "enabled": true, "npc": "Lisette", "affinity": 30,
            "why": "playtest 7: CHIM's own restoreNPC wiped the entry on four reloads; 18 was the last
                    good value, plus the two scenes completed that day" }
```

It runs **once**, from the first live game turn with that NPC after the deploy (never from the CLI: a
write with no `gameRequest[2]` lands on the wrong timeline and the next reload rolls it straight back),
through `setRelationship($npc, 'Player', $target, null)`, and marks itself done in
`lrg_memory.affinity_repaired_at` so it can never run twice. It refuses when the NPC is locked in the
NPC manager, and it does nothing when CHIM already reads that value or higher. Every path is logged.

**The number is a deviation from the round's prompt and the owner must confirm it.** The prompt says
"restore Lisette's affinity to what it was (10)". `research/pt7-relationship.md` proves **10 was never
CHIM's value** - it was `affinity.courting_bonus`, and CHIM's last good value before the rollback
cascade was **+18** (written 17:19:58, history row 279, type `neutral`). 30 = that 18 plus the two
scene gains of that day (+8 first, +4 second). Setting it to 10 would be a downgrade.
Turn it off after it has run (`enabled: false`).

---

## 2. R2 - every act and position phrase

### 2a. The act table is rebuilt from what is really installed
72 of 112 installed action names matched no family, and every glob was prefix-anchored so all nine of
OCR's `3pp_*` actions were invisible. Globs are now `*<name>*` wherever a pack prefix could appear, the
table is ordered by specificity (`lrgActFamily()` returns the first match), and six families are new:
**footjob, rimjob, spanking, massage, facesitting** (tag-driven: no OStim action name exists for it)
and **deepthroat** as an `oralpenis` alias. `frenchkissing` and `lickingear` joined `kiss`,
`lickingnipple` joined `nipples`, `breastsliding` joined `titfuck`.
A family marked `niche` (footjob, rimjob, spanking, facesitting) is **never offered by itself** and is
reachable only when the player names it (D13 stays).

### 2b. Positions are first-class
An act id may carry a position after a slash: `vaginal/reversecowgirl`, `vaginal/doggy`,
`anal/missionary`. Position ids are derived at query time from each scene's tags, actor tags, id words
and furniture (`lrgScenePositions()`), so a new animation pack is covered with no config change.
`lrgActBase()` strips the suffix, so every comparison written against a bare family still holds.
The scene notes now read `vaginal = vaginal sex (positions: vaginal/doggy, vaginal/cowgirl, ...)`, and
`lrgResolveAct()` accepts those keys - which is the fix for **F3** (`item="vaginal"` used to fall
through to a plain-words search and land on `OARE_SpooningFingering`).

Measured installed positions for a male player + female NPC:
`vaginal` -> allfours, bendover, carrying, cowgirl, doggy, kneeling, missionary, prone, reversecowgirl,
sitting, spooning, standing · `oralpenis:npc` -> allfours, kneeling, prone, sitting, standing ·
`anal` -> **none**.

### 2c. The recogniser
- **D1 (92 of 654 audited rows)**: `LRG_INTENT_FRAME1`'s verb list is the one people really use, and
  `lrgIntentFrame()` tests it on the utterance **and on every clause** - "now get down on your knees
  and suck my deck" opens with "now", and that one word used to cost the whole request. Frame 2 gained
  `let me`; `lrgIntentPolite()` gained `can i / may i / could i`.
  An unmistakable act phrase, a named sex position, "go again", or a bare act noun that resolves to one
  family (`intent.bare_act_max_words`, default 4) is `conf=high` on its own.
- **D2**: the `put ... on` clothing reading now requires a garment word, and an unmistakable act phrase
  vetoes the clothing branches - unless the sentence carries strong clothing evidence of its own, which
  is what keeps the first half of "take my clothes off and then missionary" intact.
- **D3**: `lrgMatchAct()` is no longer a longest-word contest. Order: exact act id · a **priority
  phrase table** (an anatomy+verb phrase beats every bare noun) · a named sex position · the families'
  own words · the synonym vocabulary. `LRG_ACT_BODY` then drops or flips a direction these two bodies
  cannot perform (`oralvulva:npc` -> `oralpenis:npc` for a male player, `handjob` <-> `fingering`, ...).
- **D12**: `lrgActSpeechFixes()` repairs the speech-to-text and split-word forms that really arrived -
  `suck my deck`, `doggy style socks`, `from behind sack`, `book a missionary`, `cow girl`, `blow job`,
  `sixty nine`, `striped naked`. Config-extendable (`intent.speech_fixes`), deliberately a closed table
  and **never** applied to the blocker patterns.
- **D11**: "go again / another round / round two / do that again" is a request - to START out of a
  scene, to repeat the last act family inside one.
- **D8**: `lrgIntentParts()` splits on `and then / then / after that / , / ; / and`, and the second
  request travels as `intent['extra']`.

### 2d. Unreachability never kills a request again (F1)
`lrgActPool()` indexes **every installed scene these two actors can be in**, grouped by act id and by
position, cached per request. `lrgIntentResolve()` resolves a player request against that pool, with
the 3-hop walk used only as a *preference* (a real route beats a fade). A scene with no route is sent
with `warp=1`; the game already warps a player-requested `goto` whenever `bAllowWarp:Intimacy` is on.
Three honest answers replace the old blanket "not from here":

| answer | when | what she does |
|---|---|---|
| `cant = not installed` | the packs cannot do it at all | a short in-character "there is no way to do that", plus what IS possible. Never a substitute |
| `already` | they are doing exactly that | "we're already doing that", in her own words, no command |
| `cant = too soon` | it exists, but every scene of it is above the step reached | as before |

**69 and anal are not installed on this setup** and now produce the first answer instead of silence
(69) or a silent vaginal substitute (anal). See section 7.

### 2e. Out of a scene (D9/D10, F4)
`lrgPrepareTurn()` now hands the recogniser a **real context** out of a scene (the two sexes and the
furniture in reach are already in the snapshot). With `ctx = null` the act layer, the position index
and the text search were all switched off, which is why 148 of 218 audited phrases got a neutral
restatement and not one ever named a scene to start in.
- The directive for an explicit request, when she is willing, alone and `BeginIntimacy` really is on
  the table, says so plainly: *this is the moment - say one short line and choose BeginIntimacy in the
  same reply; if you do not want it, say no plainly and choose nothing.* The server still never starts
  a scene, and the safety net still never fires outside one.
- `lrgPickStart($sexes, $nearf, $actId)` starts **directly in the requested act** (the owner removed the
  forced gentle lead-in). Her own unasked-for move still starts gently. The additive `after=` key (w1)
  is set only when the scene the thread really begins on cannot carry the act.

### 2f. Compound requests (w2)
The second request goes out as an ordinary second command in the same reply, with its own cid
(`<cid>b`). Verified read-only against the game source:
- `LRG_Main.HandleCommand` de-duplicates on `npc|command|parameter` within 5 s (LRG_Main.psc:377-383) -
  the differing `cid=` already makes the parameters differ, so nothing is swallowed;
- `LRG_OStim` refuses a second command only while one is **parked** for her spoken line (`pendingVerb`,
  LRG_OStim.psc:2334 / :2140), and a player-requested command never carries `wait=`, so nothing is ever
  parked on this path.

**One game-side need, written out exactly as asked (section 6).**

---

## 3. R3 - a real outro

`ev=end` triggered **no LLM call at all** in playtest 7. What the owner heard as a goodbye was the
CLIMAX scene-talk line fired seconds earlier under a cue demanding "one short, blunt sentence - 120
characters or less".

- **The case fix first (O3), one line, load-bearing for everything else.** `lrgHandleSceneMessage()`
  lower-cases `ev`, `leader` and `how` on arrival. In production the wire delivers capitalised values
  (26 `scene Change`, 5 `Start`, 4 `Climax`, 35 `leader=NPC` against 4 lowercase `scene end`), so every
  `=== 'start'|'change'|'climax'` test failed silently: `_visited`, `_acts_done` and `_climaxes` stayed
  empty, the landing notes and the scene-start line never fired once, and every summary said "<npc> led".
- **`_started_at`** is stamped at the start of a scene. `_tier_since` was being used as the duration,
  which is why a 2.5-minute scene was summarised as "about a minute" (O9).
- **The outro ticket**: written at `ev=end` into `lrg_memory` with acts, visited scenes, `ncl`/`pcl`,
  duration, how it ended, furniture, leader, first-time and the total count. Valid for
  `outro.window_seconds` (60), **consumed after one use**.
- **`prompts.php` accepts the ticket in place of a live scene row** when the request text is `outro`
  (O5: after `ev=end` the row is `active=0`, so the request would otherwise get an empty cue and fall
  back to `TEMPLATE_DIALOG`). Every other safety test is unchanged.
- **Turn mode `outro`**: offers **no glue action at all**, re-checks the same rails a scene turn does,
  and emits `<after_intimacy>` instead of `<this_moment>` / `<intimate_scene_now>`.
- **All four one-sentence caps are gone on that turn** (O4): no `lrgOneSentence()`, no scene block,
  `outro.max_chars` 420 and `scene_talk.max_tokens.outro` 300 instead of 140/200.
- **`End_Conversation` is forbidden by name** (O7) - CHIM offers it on the post-scene turn and it was
  picked five times in one day ("Lisette leaves the conversation", eventlog 21888).
- **The facts she is given**: what they did (act labels + scene labels), how long in words, who came,
  how it ended, where, first time or not, and what CHIM knows about her own life
  (`$GLOBALS['CHIM_CORE_CURRENT_NPC_DATA']` - `occupation` first, then class and goals), plus one stay
  clause chosen from the snapshot (her own place / the player's home / a spouse or follower / else
  "say where you are going and why").

The one-scene memory summary is unchanged and still written once per scene.

---

## 4. R4 - logging

- `say=` now carries the player's words on **every** turn line, closed and silent ones included. In
  playtest 7 the whole eighteen-minute stretch the owner complained about was logged `say=""`.
- A closed turn appends ` closed="<the plain reason, the same words the NPC is given>"`.
- The `gate` line carries every component of `aff=`:
  ` chim=<int|missing> lrg=<int> hist=<int> courting=<int> rank=<int> min=<int> bonus=<int>[ rescued=...]`.
- An outro turn appends ` outro=dur:<n>s/<how>/acts:<a+b>/first:<yes|no>`; a compound request appends
  ` also=<kind>/<act>`.
- Every new decision writes its own line: `affinity rescued for <npc>: ...`,
  `relationship: <npc> -> Player +8 (their first time together, was 10, now 18, type neutral kept)`,
  `relationship repair: ... set to 30 (was no entry). Reason: ...`, `outro ticket for <npc>: ...`,
  `outro dropped for <npc>: ...`, `second command SceneControl ...`.
- **"maintenance done, version" is game side.** The server prints `GAME <msg>` verbatim from the
  `lrg_log` message; the version in that text is chosen by `LRG_Main`. Nothing to do here.

---

## 5. Everything that changed, by file

| file | what |
|---|---|
| `lib/lrg_core.php` | `LRG_VERSION` 0.3.1, `LRG_SCHEMA_VERSION` 3; `_started_at`; `lrgRomanceSet()`; `lrgEnginePath()`, `lrgRelationshipReady()`, `lrgChimRelationship()`, `lrgAffinityInfo()`, `lrgHistoryBonus()`, `lrgRelationshipLocked()`, `lrgAdjustAffinity()`, `lrgMaybeRepairAffinity()`; `lrgInterest()` gains the history bonus and reports its components |
| `lib/lrg_scene_index.php` | rebuilt act table (6 new families, widened globs, `niche`); `LRG_POSITIONS`, `lrgScenePositions()`, `lrgActPool()`, `lrgActParse()`, `lrgActBase()`, `lrgActInstalled()`, `lrgFindActScene()`, `lrgActPositions()`; rewritten `lrgMatchAct()` + `lrgMatchActStrong()`, `lrgActNormalise()`, `lrgActSpeechFixes()`, `lrgPositionWordsTable()`, `lrgMatchPosition()`, `lrgActPhrases()`, `LRG_ACT_BODY`, `lrgActFixSexes()`, `LRG_ACT_WEAK_SYNONYMS`, `lrgActStripWeak()`; `lrgActLabel()` and `lrgPositionLabel()` understand positions; `lrgActOptions()` rows gain `positions`; `lrgPickStart()` takes an act and returns `after`; `lrgFindSceneByText()` gains `$requireCore` |
| `lib/lrg_intent.php` | widened frames tested per clause; `lrgIntentParts()`, `lrgIntentExtra()`, `lrgIntentBareAct()`, `lrgIntentAgainAct()`; garment-guarded clothing branches with an act veto; "go again"; rewritten `lrgIntentResolve()` (pool, warp, already, not-installed, out-of-scene start); new directive shapes |
| `lib/lrg_actions.php` | `LRG_ACTIONS_VERSION` 9; case fold on arrival; `lrgSceneDuration()`, `lrgSceneHow()`, `lrgAwardSceneAffinity()`, `lrgWriteOutroTicket()`, `lrgOutroTicket()`, `lrgIsOutroTick()`; turn mode `outro` + `lrgOutroGuidance()` + `lrgNpcLife()` + `lrgOutroStayClause()`; real out-of-scene ctx; `lrgSecondCommand()`; start uses the request and carries `after=`; rewritten `lrgResolveAct()`; act notes carry positions; logging |
| `prompts.php` | the outro cue, accepted against a ticket instead of a live row; its own token budget |
| `config/lrg_config.default.json` | `relationship.*`, `outro.*`, `affinity.trust_our_last_known/log_rescue`, `leverage.history_bonus*`, `intent.{compound,bare_act_max_words,speech_fixes,words.again}`, `scene_start.start_in_requested_act`, `scene_index.{max_positions_per_act,position_phrases,position_labels}`, `scene_talk.max_tokens.outro` |
| `migrations/003_lrg_romance_affinity.sql` | new |
| `manifest.json` | 0.3.1 |
| `glue/PROTOCOL.md` | v0.3.1: the compatibility statement, `dur`/`how`, the case rule, the `outro` request, `after=`, two commands in one reply, `lrg_romance` and `lrg_memory` keys, mode `outro`, 6.1 history, 6.7 positions + reachability, new 6.10 relationships and 6.11 outro, 7.1/7.2 signatures, config keys, §9 logging, ownership |
| `tools/test_phrases.php` | **new** - the 218-sentence matrix x 3 states, MUST groups + a coverage floor |
| `tools/test_gates.php` | a realistic `RelationshipManager` stand-in; sections 29/30/31 (relationship, outro, positions/compound/log); `$resetMem()` clears `lrg_romance` too |
| `tools/test_intent.php` | act expectations compare the FAMILY, so a position on top of it passes |
| `tools/test_scene_index.php` | the empty-index check covers the new signatures |
| `tools/flows/scenarios/23_acts.php` | "we're already doing that" accepted as a third non-silent answer |

---

## 6. What the GAME side must do (for the fix pass)

1. ~~**`goto` + `goto` in one reply.**~~ **STRUCK in the fix pass: already built.** The game half of
   this round added exactly that one-slot queue - `LRG_OStim.DeferCommand` :964, `TickDeferred` :1002,
   call sites :1441 / :2641 / :2648 / :2826 / :2853 / :2939 / :3171, wait budget `fQueueWait` (MCM,
   default 20 s), retried about once a second, answered with its original reason at the deadline, a
   third command still refused at once. PROTOCOL.md section 2 was still repeating the old claim and
   has been corrected. Nothing is needed from the game side here.
2. **`after=` on `StartIntimacy`** (w1): navigate to that scene a few seconds after the thread starts,
   as a player-requested goto (warp allowed). Absent key = today.
3. **`dur=` and `how=` on `ev=end`** (w3): seconds the scene ran, and
   `finished | stopped | interrupted | lost`. Without them the server falls back to its own
   `_started_at` and to the climax count, which is correct but coarser.
4. **The `outro` request** (`AIAgentFunctions.requestMessageForActor("outro", "lrg_scenetalk", name)`)
   after a forced snapshot, plus the hold. The server answers it; the server sends nothing back on the
   wire for the hold, exactly as agreed.
5. **`LRG_Profile.IsCourting`** (LRG_Profile.psc:96) calls `HasAssociation(courting)` without the second
   argument, which means "with ANYONE". Live snapshots show `courting=1` for three NPCs who are not
   courting the player, each silently worth +10 affinity. It should be
   `akActor.HasAssociation(courting, akPlayer)`.
6. **The corner note for a "can't"** - see deviations.

---

## 7. For the owner

1. **69 and anal are not installed.** Confirmed in the audit and re-confirmed here: no scene in the
   three installed packs declares `analsex`, and nothing anywhere contains 69 / sixty / sixtynine.
   The glue now says so in character instead of ignoring it (69) or silently doing vaginal instead
   (anal). If she wants them, an animation pack has to be installed; the glue cannot invent animations.
2. **Turn on `NEVER_CLEAR_RELATIONSHIP_DATA` in CHIM's conf.** That is a CHIM *setting*, not a code
   change, and it stops `restoreNPC` deleting relationships on every load. The glue's own memory covers
   the damage either way, but this removes the cause. We did not set it.
3. **The Lisette repair number is 30, not 10** - see 1f. It needs her word before deploy, and
   `relationship.repair.enabled` should go back to `false` once it has run.
4. Two things that appeared on the server AFTER the playtest and are still live: the NPC-manager save
   at 17:53 that left `relationships: []`, and the override
   `ext/lorerim_glue/config/lrg_config.json` = `{"npc_overrides":{"Lisette":{"min_affinity":-5}}}`.
   Neither is ours. The override will now stack with the history bonus and the restored affinity; the
   main session should decide whether to keep it.

---

## 8. Deviations and things deliberately not done

- **The repair target is 30, not the 10 the round's prompt named** (1f). Evidence-based, flagged,
  config-driven, reversible.
- **The corner note for a "can't" is game side.** The server produces the spoken in-character line on
  every impossible request, but a `Debug.Notification` can only come from the game, and the wire
  agreement forbade a new key this round. Emitting a doomed `goto` just to make the game print its own
  error was rejected: it would be a lie to OStim and could warp somewhere wrong. Written up as a need.
- **"maintenance done, version" is game side** (section 4).
- **No flow scenario was added.** The new machinery is covered end-to-end in `test_gates.php` sections
  29-31 (including a real `prompts.php` include for the outro cue) and by `test_phrases.php`. The 32
  existing flow scenarios all still pass under `--strict`.
- **`test_phrases.php` has a coverage floor, not 100 %.** The MUST groups are at 100 %; the remaining
  15 % are rows whose expectation is arguable for a male player + female NPC pair (for example
  "finger me", which this pair cannot do, so it resolves to a handjob), plus furniture and posture rows
  that are not act requests at all. They are printed on every run, so nothing is hidden.
- **`LRG_INDEX_VERSION` stays 4.** The digest format did not change - positions are derived at query
  time from data the digest already stores - and the index signature covers this library's mtime, so a
  deploy rebuilds anyway.
- **Not deployed**, as instructed. `deploy_server.ps1` is not mine to edit and was not touched.

---

# FIX PASS (server lane) - 2026-09-21, after the critic's defect list

Lane: `glue/server/lorerim_glue/**`, `glue/PROTOCOL.md`, `glue/tools/test_*.php`, `glue/tools/flows/**`. No deploy.
This section is appended as the work happens; the ordering is the order the defects were taken.

## F0. Plan (defect -> intended fix)
- V1 [major] `lrgActFixSexes` swaps the FAMILY instead of the ROLE -> try the same family with the opposite role first.
- V2 [major] a bare directional family (`oralvulva`, `oralpenis`, ...) is DROPPED -> default it to the installed role.
- V3 [major] `lrgRomanceBump($npc,'scenes')` runs on every closed scene -> gate it on dur + how, like the gain.
- V4 [major] act/position coverage cluster (lap, spoon, wall, carry, turn-around+ride, tits-veto, grope split, compound split).
- V5 [major] PROTOCOL.md: the outro is an undocumented fifth wire item; `dur=`/`how=`/`after=` missing.
- V6 [major] out-of-scene `undress` gets the neutral directive -> add `undress`/`dress` to the OPEN directive kinds.
- V7 [major] out-of-scene compound promises "Both happen" but drops the second half.
- V8 [major] PROTOCOL.md still says the game drops `goto`+`goto`; the game queues it now.
- plus the cheap minors: label possessive, outro scene-id leak, closed-turn crude directive, gentle-start wording,
  watchdog on the second command, a flow assertion for w2, the repair arithmetic, the 45/60 threshold note,
  the compatibility claim, the 1.2 self-contradiction.

## F1. What was changed, defect by defect

Files touched (server lane only): `server/lorerim_glue/lib/lrg_scene_index.php`,
`lib/lrg_intent.php`, `lib/lrg_actions.php`, `config/lrg_config.default.json`,
`glue/PROTOCOL.md`, `tools/test_gates.php`, `tools/test_intent.php`,
`tools/flows/scenarios/19_intent_directive_net.php`, `tools/flows/scenarios/23_acts.php`.
Nothing was deployed. No OStim / OARE / LoreRim / CHIM / HerikaServer file was touched.

### V1 [major] the anatomical fix swapped the ACT instead of the ROLE - FIXED
`lrgActFixSexes()` now knows WHERE the role came from (new third parameter `$roleAssumed`,
default false, set by `lrgMatchAct()` when nothing in the sentence named a direction):

- **role stated by the player** ("put your tongue on me", "finger me"): WHO does it is the part he
  really said, so the cross-family fallback keeps it and adapts the organ - unchanged behaviour.
- **role ASSUMED by the code** (a bare `item="oralvulva"`, the word "cunnilingus"): there is no
  "who" to preserve, so the same family with the roles mirrored wins and the FAMILY is never
  swapped.

Probe, player male / NPC female:

| id | role stated | role assumed |
|---|---|---|
| `oralvulva:npc` | `oralpenis:npc` | `oralvulva:you` |
| `fingering:npc` | `handjob:npc` | `fingering:you` |
| `handjob:you` | `fingering:you` | `handjob:npc` |

The downstream evidence the critic gave is gone: `lrgResolveAct('cunnilingus')` -> `OARE_KneelingCL`
(a cunnilingus scene, was `OARE_SittingFellatio`, a blowjob); `lrgResolveAct('fingering')` ->
`OARE_StandingKissFingering` (was `OARE_AceStandingHandjob`).
A mirror is also tried as a LAST resort when the cross-family fallback is empty, so a request is
never dropped where a mirror exists.

### V2 [major] a bare directional family was DROPPED (the F1 failure mode) - FIXED
Three changes:

1. `lrgMatchAct()` step 1 now also matches the BARE family name (`vaginal`, `oralpenis`,
   `spanking`), which is what the model really sends.
2. `$pick()` no longer defaults to `:npc` when the sentence named no direction: it takes the role
   that is really INSTALLED for these two bodies (only when the sexes are known - with `$sexes`
   null this is the pre-lock light pass, which must not decode the index).
3. `lrgResolveAct()` resolves the `item` against the offered list first and then, if that fails,
   against the whole act table. The offered list is what SHE may pick unprompted; a `RequestAct`
   only ever exists because the PLAYER named something, so a niche family and an act outside the
   3-hop walk are now reachable.

| `lrgResolveAct(item)` | before | now |
|---|---|---|
| `vaginal` | dropped ("matches no act that is open right now") | `do=goto;scene=OARE_StandingCarryingSex` |
| `oralpenis` | NULL | `OARE_SittingFellatio` (`warp=1`) |
| `oralvulva` | NULL | `OARE_KneelingCL` |
| `spanking` | NULL | `OStim2PDoggyStylePullingHairMF` (`warp=1`) |
| `anal`, `sixtynine` | NULL | NULL - **correct**: nothing installed does them, and the player path answers in words |

### V3 [major] an aborted scene counted as a real one - FIXED
New single predicate `lrgSceneCounted($npc, $kv, $prev)` (`lrg_actions.php`), used by BOTH the
`lrg_romance.scenes` counter and `lrgAwardSceneAffinity()`: duration at least
`relationship.min_scene_seconds`, and `how` in `finished|stopped`. Verified: an 8 s `how=stopped`
start leaves `scenes=0` and `lrgHistoryBonus()=0`, and the next real 200 s scene is still their
FIRST (`adjust:Hulda:8`). Two regression checks added to `test_gates.php` section 29.

### V4 [major] the act / position coverage cluster - FIXED (measured)
`tools/test_phrases.php` over the same 218 sentences x 3 states: **554/654 -> 594/654
(84.7% -> 90.8%)**, every MUST group still at 100 %, no group below its own starting value.
What was added:

- **position phrases**: `turn around and ride me` -> reverse cowgirl (the owner's own complaint),
  `let me be on top` / `i want to be on top` -> missionary (against `on top` = her on top),
  `sit on my lap`, `pick me up` / `carry me`, `lay down` / `lie down` (only with a sex verb - see
  `LRG_POS_WEAK_PHRASES`, so a bare "lie down" stays a posture and fires nothing).
- **act phrases**: lap sex, being picked up, "get that pussy/ass over here", "(be|get) inside you",
  "i want you right now", "your fingers inside you" -> masturbation, a head in a lap -> holding.
- **the tits veto over fuck**: the titfuck row's verb-to-noun gap was 12 characters and "fuck my
  cock with your tits" has 18 of them in between; it is 26 now, so the owner's sentence is a titjob.
- **a butt / breasts split on groping**: two new `LRG_POSITIONS` entries plus the phrases for them,
  and `lrgMatchAct()` lets a position ride along on `grope` as it does on `vaginal` / `anal`.
  "grab my ass" -> `grope:npc/butt` -> `OStim2PStandingBehindJerkToButtMF`.
- **`LRG_POS_NEAR`**: a named position with no installed scene falls back to the NEAREST one
  (wall -> standing, lap -> sitting/cowgirl, table -> bent over, carrying -> standing) before it
  falls back to "any scene of this act". No installed scene declares wall sex on these packs, so
  "against the wall" now lands standing instead of lying on their sides.
- **the gentle families were invisible**: `OARE_SpooningCuddling1..4`, `OARE_LapPillow`,
  `OARE_PrincessCarryEmbrace`, `OARE_CuddleFromBehind` and two dozen more declare the OStim action
  "default", so `lrgSceneActs()` returned NOTHING for them and the whole `hold` family was the two
  scenes that happen to declare a hugging action - which is why every "let's spoon" ended in the
  same standing hug. `lrgActPool()` now reads the scene's own position and id for a gentle scene
  with no acts, exactly as it already did for facesitting.
- **compound**: `lrgIntentScanOrdered()` - the first clause wins when it means something ELSE than
  the whole-utterance scan. The pattern order in `lrgIntentScan` is a priority list (clothing before
  acts), not the order of the sentence, so "first kiss me then take your clothes off" came back as a
  plain undress. It is now `act/kiss` plus `extra=undress`. "take my clothes off and then let's do
  missionary" and "bend over and let me start doing doggy style" keep exactly the reading they had.
- **"get undressed"** matched nothing at all: the `undress` pattern was anchored on both sides
  (`\bundress\b` against "undressed"). Now `undress\w*` / `strip\w*` / `disrob\w*`.
- `you do the work` / `you drive` added to the hand-over-the-lead pattern.

### V5 [major] the outro was a fifth wire item, documented nowhere - FIXED in PROTOCOL.md
Section 0 now lists **all five wire items of the round** and names w5 explicitly (the
`lrg_scenetalk` request whose text is `outro`, game to server, answered as an ordinary CHIM speech
turn, no wire reply of its own). 1.2's "`ev=end` carries only ..." sentence now points at the
[0.3.1] table (`dur` / `how`) and the `sess` row says every push including the ordinary `ev=end`
(LRG_OStim.psc:3550). `after=` on `StartIntimacy` was already in section 2 and is now
cross-referenced. **w5 still needs the main session's explicit blessing** - it is written down, not
decided.

### V6 [major] out of a scene an undress request got the neutral directive (pt7 F4) - FIXED
The OPEN directive now covers `undress` / `dress`, gated on `lrgTurnOffers($turn, LRG_ACT_CLOTHING)`
and naming **ChangeClothing with the item the player's own words resolve to**. It is still not an
order: the consent sentence ("nobody but <npc> decides that") is in every branch.
The two tests that pinned the old neutral shape were updated to assert the RAIL (no "Do it now", no
"Do not refuse", and the consent sentence present) instead of the wording - `test_intent.php`
section H and flow scenario 19 - because those assertions are exactly what kept F4 broken.

### V7 [major] out of a scene "Both happen" was a promise nothing kept - FIXED
- The promise is now separated from the mention: "Both happen, in that order" is only appended where
  a path really carries the second half.
- Out of a scene, when the primary is `undress`/`dress` and the extra is an act, the directive names
  BOTH actions, and the post-gate takes the act from `intent.extra` as the start's own act - so the
  thread begins in the requested scene, with `after=` only when it cannot begin there.
- Flow 23 now asserts both halves: in a scene TWO wire lines with cids differing by the trailing
  `b`; out of a scene, the promise only where both actions are named, and the start carrying the act.
  Verified in the flow trace: `...Clothing@...do=undress;who=player` plus
  `...SceneControl@...cid=<cid>b;do=goto;scene=OARE_Missionary;warp=1`, and out of a scene
  `StartIntimacy@...scene=OARE_Missionary`.

### V8 [major] PROTOCOL.md still said the game drops goto+goto - FIXED
Replaced with the real behaviour (one slot, `fQueueWait` default 20 s, retried about once a second,
answered with its original reason at the deadline, a third command still refused at once; script 300
has no queue). The `lrgSecondCommand()` docblock was corrected too, and the stale "game-side need"
bullet in section 6 of this report is struck.

### The minors
- **BeginIntimacy's "it starts gently" wording** is now conditional: when the start will really begin
  in what the player asked for (his own request or the act half of a compound), the action's own name
  says so.
- **The outro prompt no longer leaks raw CamelCase scene ids**: the `_visited` labels are only added
  when the act list is empty.
- **Act labels**: `%n uses %n's feet on %p` became `%n's feet on %p` (and the nipples row), so the
  outro directive no longer says "Hulda uses Hulda's feet on Dovah".
- **A closed turn gets a plain-word directive** (`lrgIntentPlainKind()`: "something physical",
  "clothes off"), never `lrgIntentWords()`'s explicit label. Mode `closed` is the one mode with no
  wording permission, and the whole stretch the owner complained about was mode closed. New check in
  `test_gates.php`.
- **The second command is registered with the emitted-vs-confirmed watchdog** (`lrgNotePending`), so
  a lost second line WARNs like every other command.
- **The repair arithmetic** now matches the shipped rule: `relationship.repair.affinity` 30 became
  **26** (18, CHIM's last good value, plus the ONE first-scene gain of +8 that the one-gain-per-window
  rule would really have granted that day), with the reasoning spelled out in `_repair_readme` and a
  plain instruction to set `enabled=false` once it has run. See the OWNER section - the number is
  hers to change.
- **`relationship.gain_window_seconds` 21600 became 1800.** Six real hours meant a whole evening
  together was worth one gain, which is not what "sex should slightly improve the relationship"
  reads like. Half an hour lets two separate times together in one evening count twice, while a
  scene restarted right after a stop still counts once. Flagged as a deviation.
- **The 45 / 60 asymmetry is documented, not changed**: `outro.min_scene_seconds` 45 (a short scene
  still earns a proper goodbye) against `relationship.min_scene_seconds` 60 (it does not move the
  relationship). Written into both config readmes and PROTOCOL 6.10.
- **PROTOCOL 1.2's self-contradiction** about `ev=end` and `sess` is resolved (see V5).
- **The compatibility claim** at the top of PROTOCOL.md is corrected: the round is additive in both
  directions EXCEPT the compound path, which needs game script 310, so the two halves ship together
  or `intent.compound` is set to false.

## F2. NOT DONE, and why
- **The corner note for an impossible in-scene request** (`Debug.Notification`) still does not exist.
  It cannot: the server only reaches the game through a command, and the CANT branch deliberately
  emits none. It needs one more additive wire key - `note=<text>` accepted on any `ExtCmdLRG_`
  command, or `do=note` on `SceneControl`, answered by the game with a `Debug.Notification` and
  nothing else. That is a SIXTH wire item and needs the main session's word. The never-silence rail
  holds meanwhile: she says it out loud.
- **`anal` and `sixtynine`** remain unreachable, confirmed against the installed packs (zero scenes
  declare `analsex`; no scene declares both a penis-oral and a vulva-oral action). Both are answered
  with a spoken "there is no way to do that at all", which is what R2 asks for.
- **`test_phrases` misses that remain** (60 of 654, all floor rows, none in a MUST group): rows whose
  expectation cannot be satisfied by these packs ("against the wall" wants a wall scene id; nothing
  installed has one), rows where the matrix and the act-id convention disagree ("play with your tits"
  expects `grope:npc`, i.e. HER doing it, while `:you` = the player is the one groping and is what
  the sentence says), rows where a furniture move is the better answer than a position change ("put
  me on the table and fuck me" resolves to `do=furniture` with a table sex scene when the furniture
  is in reach, and the matrix expects an act), and three rows ("eat my pussy", "finger me", "finger
  my ass") where the matrix expects a spoken "can't" for an anatomically impossible direction while
  the code repairs it into the nearest possible act. Every one of these is listed here rather than
  fixed by editing the expectation.

## F3. FOR THE OWNER
1. **Both halves of 0.3.1 are already deployed and installed** (server `ext/lorerim_glue` mtime
   2026-09-21 19:20, the `.pex` files 19:17), against what both build reports said. Nothing in this
   fix pass has been deployed: what is on the server is still the pre-fix build.
2. **The one-time Lisette affinity repair has NOT fired yet** - there is no `relationship repair:`
   line in `lorerim_glue.log`. It is configured in the DEPLOYED `lrg_config.default.json`
   (`enabled: true, npc: Lisette, affinity: 30`); her own override file `config/lrg_config.json`
   contains only `npc_overrides.Lisette.min_affinity = -5` and no repair block. So the number that
   fires is whatever the default file says at deploy time: **30 today, 26 after this fix pass is
   deployed**. 26 is what the code's own rule produces (18 plus the first-scene +8). If she wants
   the round 30, that is a one-line change.
3. **After it has run**, confirm `relationship repair: Lisette -> Player set to <n>` in
   `/var/www/html/HerikaServer/log/lorerim_glue.log` and set `relationship.repair.enabled` to false
   in her override file, so it can never be re-armed by a future deploy.
4. **The two halves must be deployed together** (the compound request needs game script 310).

## F4. Tests - all green, all re-run after every change
- `php -l` over all 50 PHP files in the staged tree: 0 failures.
- `tools/test_scene_index.php`: ALL CHECKS PASSED.
- `tools/test_gates.php`: **238 passed, 0 failed** (235 plus 3 new).
- `tools/test_intent.php`: **142 passed, 0 failed** (141 plus 1 new).
- `tools/test_phrases.php`: 13 passed, 0 failed, **hit rate 90.8 %** (was 84.7 %), floor 82 %.
- `tools/flows/run_flows.php --strict`: **32 scenarios, 672 checks, 0 failures, 0 warnings, 0 pending.**
