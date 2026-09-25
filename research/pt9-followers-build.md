# pt9 - FOLLOWER COMPATIBILITY, built (LoreRim Glue v0.5.1)

Owner addendum 10. Built from the investigation in `research\pt9-followers.md`; nothing in this round
re-litigates it. Shipped 2026-09-22, **untested in game** - no playtest has ever had a real follower
present (`lorerim_glue.log` still has zero lines with `mate=1` or `witfol>0`), so every behaviour below
is verified offline and in the deployed server, never in Skyrim.

Status of the two inert switches is unchanged: `bMenuless = 0`, `bDlgDryRun = 1`. The follower VERBS
therefore do nothing in game until the owner turns menuless questing on; everything else in this round
(the facts, the prompt block, the hide rule, the ghost repair, the hold skip) is live at once.

---

## 1. What was built, against the brief's six items

| # | asked for | built | where |
|---|---|---|---|
| 1 | RepairGhost on load / on MakeFollower, promote via `SetFollower` when a slot is free, else strip both factions and fall back to CHIM's follow | `LRG_Followers.RepairGhost()`, queued onto `LRG_Main`'s tick from three hook points; the `DialogueFollowerScript` signatures were **read out of SFF's shipped `.pex`**, not assumed | `LRG_Followers.psc`, `LRG_Main.psc` |
| 2 | hide CHIM's MakeFollower / follower shortcuts when a session list can carry the real entries; always hide MakeFollower when she is a teammate or SFF's cap says no (read `SFF_CanRecruitMore` in the snapshot) | two independent rules: `lrgFollowerPolicy()` (snapshot facts, last filter of all) and `services.kinds.follower.hide` (a list that really carries follower entries) | `lib\lrg_core.php`, `lib\lrg_dialogue.php`, `functions.php` |
| 3 | the follower frameworks' dialogue as service kind `follower` with the seven intents, exact containment, never double-firing | `services.follower.verbs` (8 verbs), `lrgDlgFollowerArbitrate()` first in the gate's intent branch, plus a hard rail for the topics that must never be selectable | `lib\lrg_dialogue.php` |
| 4 | awareness: teammate / slot / cap / role on the snapshot, the LLM told it is a framework follower, the hold keeps skipping teammates, intimacy "follow me somewhere private" must not recruit | `fol=` on the snapshot, `<companion_status>`, `IsFollowerLike()` in the hold, and the no-recruit property of the invite **asserted in a test** | `LRG_Profile.psc`, `LRG_Main.psc`, `lib\lrg_core.php`, `context_pre.php`, `test_gates.php` |
| 5 | Requiem's recruit gates respected because the real entry is used - assert it | flow d62: with the recruit entry conditioned away, "come with me" clicks nothing **and** CHIM's `MakeFollower` is not offered as a substitute | `flows\scenarios\d62_followers.php` |
| 6 | tests for each item, compile OK, php -l + every test + flows green, every MCM key with an ini default and a reader | done, see section 6 | |

---

## 2. Game side

### 2.1 `LRG_Followers.psc` (new, `Hidden`, global functions only - no ESP change)

Facts, cheap, one pass per snapshot:

```
fol=fw:<none|sff|custom|chim>,mate:0/1,cff:<n>,pff:<n>,wait:<n>,chim:0/1,ghost:0/1[,slot:u/m,cap:0/1,prim:0/1]
```

* `fw=sff` is `SFF_SKSE.IsVanillaFollower()`; `custom` is a teammate SFF does not own (Inigo, Lucien,
  Auri, Remiel, Taliesin, Serana); `chim` is CHIM's package follow or its half-recruit.
* `ghost=1` is the 4.1 state: vanilla `CurrentFollowerFaction` rank >= 1 **and not** a teammate, **and
  not** Inigo's `WaitingForPlayer == -1`, **and not** in `DismissedFollowerFaction`. Those last two exclusions
  are what keeps a dismissed custom follower out of the repair.
* `cap` is SFF's own `SFF_CanRecruitMore` - the framework's own "a slot is free". **CORRECTED in the
  0.5.1 fix pass:** it is *not* the global the real recruit INFO is conditioned on. That INFO
  (`Skyrim.esm` 000D8DE0 / 0005C829) is gated on `PlayerFollowerCount == 0`, and SFF's
  `SFF_UpdateFollowerGlobals` sets `PlayerFollowerCount` to 1 as soon as it has any follower while
  `SFF_CanRecruitMore` stays 1 until every slot is used. `cap:0` is the looser of the two, so
  `hide_when_cap_full` only ever hides an action that genuinely cannot work - it does not mirror the
  entry.

**SFF is optional.** Every SFF read is behind `SffPresent()`, which is a po3 editor-id lookup of
`SFF_CanRecruitMore` - **with the underscore**. v0.5.1 shipped `SFFCanRecruitMore` / `SFFCurrentFollowerCount`,
which exist only as Papyrus property names inside SFF's VMAD and resolve to nothing, so `SffPresent()`
was false on every call and the whole SFF half of this file was dead. Fixed in the 0.5.1 fix pass
(`Simple Follower Framework.esp` GLOB 05000001 `SFF_CanRecruitMore`, 05000002 `SFF_CurrentFollowerCount`). With SFF absent no SFF script and no SFF native is ever touched, `fw` is `none`,
and the whole file degrades to the vanilla faction reads.

**`RepairGhost(actor, mode)`** - mode 0 promote-when-a-slot-is-free else undo, 1 always undo, 2 report
only:

* promote = `DialogueFollowerScript.SetFollower(akNpc)`. SFF's own recruit: it enforces the slot cap
  itself, sets the teammate flag, raises the relationship to Ally and registers her with SFF's DLL - it
  makes her exactly the follower the real dialogue entry would have made. If she is still a ghost
  afterwards (the cap moved) the undo path runs anyway, in the same call.
* undo = remove **`CurrentFollowerFaction` only**, then `ChimFollowPlayer()`. **CORRECTED in the
  0.5.1 fix pass:** it used to remove `PotentialFollowerFaction` as well. That faction sits on the
  BASE RECORD of every recruitable NPC and the vanilla recruit line is conditioned on being IN it
  (`GetInFaction PotentialFollowerFaction == 1`), so the "undo" permanently deleted her own
  "Follow me. I need your help." entry for the rest of that save - unrecoverable, and strictly more
  than the edit it claimed to undo (CHIM's `AIAgentNpcUtil` only ever adds it / sets rank 0).
  Removing `CurrentFollowerFaction` alone fully clears the ghost: `IsGhost()`, CHIM's own
  `isInFaction(VanillaCurrentFollowerFaction)` tests and SFF's `GetDialogueFollowerTarget` all key
  off it. `RepairGhost` now also returns **0**, not 2, when the faction could not be resolved, so it
  never reports an edit it did not make.

**`ChimFollowPlayer()` is a deliberate transcription, not a call.** It is the three statements of
`AIAgentAIMind.stayAtPlace(npc, 1)` (`AIAgentAIMind.psc:913-922`) with CHIM's own forms, so CHIM's
`ResetPackages` still removes the package by form and clears `CHIM_FollowPlayerActive`. Calling
`AIAgentAIMind.stayAtPlace()` directly makes the compiler build CHIM's whole `AIAgentAIMind.psc`, which
does not compile outside CHIM's own build here (RaceMenu, ConsoleUtil, UIExtensions, VRIK and six
vanilla script types are missing) - **the glue stops building**, which is how this was found. The copy
also drops the two things `stayAtPlace` does that we do not want: its `ResetPackages()` and its
permanent `BardAudienceExcludedFaction` rank 1 (investigation risk R7).

### 2.2 `LRG_Main.psc` (version 500 -> 501)

* **Repair queue.** `ArmFollowerRepair()` + `TickFollowerRepair()`: the repair runs on this quest's own
  tick, never inline. **CORRECTED in the 0.5.1 fix pass:** `SetFollower` / `PrepareFollowerActor` do
  NOT wait - the single `Utility.Wait` in `dialoguefollowerscript.pex` is in
  `CleanupDismissedFollowerActor`. The deferral stays for the reasons that do hold: `ArmFollowerRepair`
  can be reached from a CHIM speech event, the repair must not run while CHIM's own faction writes are
  still settling, and `SetFollower` moves relationship rank, teammate flag, alias and SFF's DLL at once.
  The queue is **four slots** since the fix pass (it was one, and a second ghost silently replaced the
  first - scenario P-F2 exactly).
* **Three hook points.** `NoteFollower()` from the snapshot path (one throttled check per NPC per 20 s,
  two native calls unless she really is in the follower faction); `FollowerSweep()` once per load over
  the actors already in high process (bounded at 40); `NoteChimAction()` when a `MakeFollower` command
  arrives - moved **before** the conversation-hold guard, because it has nothing to do with a hold.
  (That third one is dormant today for the reason the file already records: CHIM raises no mod event
  for its own catalog actions.)
* **Guards before a repair ever runs**: feature on, not dry run, `bFollowerRepair`, still a ghost, not
  in one of our scenes, no dialogue session, no menu, not in combat, loaded and alive. Every repair
  writes a loud `FOLLOWER GHOST <name> [<fol before>] mode=<n> -> <what>` line, an `lrg_log` message to
  the server, and a corner note.
* **The conversation hold now skips `IsFollowerLike()`** - a teammate, a CHIM package follow, or a
  ghost - not just `IsPlayerTeammate()`. `bFollowerHoldSkip` returns the 0.5.0 behaviour.

### 2.3 `LRG_Profile.psc`

* `witchim` in the witness block: the player's own companions the teammate flag does not see. **They
  are still counted in `wit` as well**, so this key can only ever make the privacy gate stricter -
  `companion_present` now fires on `witfol + witchim > 0`. One extra native call per checked adult, and
  only while `bFollowerAware` is on.
* `fol=` on the snapshot, gated on `bFollowerAware`. **`fv=` carries the VALUE of
  `bFollowerVerbsReal` and is sent whenever `LRG_Main` exists** (`LRG_Profile.psc:570`, `if m`) - it is
  not itself gated on `bFollowerAware`, which an earlier copy of this table stated. With
  `bFollowerAware` off the server gets `fv` and no `fol`, i.e. a routing preference and no follower
  facts, which is exactly "nothing about followers is sent".

### 2.4 The compile-only header, and the rail in front of it

`tools\stubs\DialogueFollowerScript.psc` declares five functions, transcribed from the **shipped**
`Scripts\dialoguefollowerscript.pex` (PEX v3.2, 36 functions, read with a read-only reader written for
this round - exact return and parameter types):

```
None  SetFollower(objectreference FollowerRef)
Bool  IsManagedFollower(Actor akActor)
Bool  SFF_IsPrimaryFollower(Actor akActor)
Int   SFF_GetTrackedFollowerCount()
Int   SFF_GetMaxFollowersSafe()
```

SFF's own `Source\Scripts\DialogueFollowerScript.psc` is the **stale vanilla 1.9 script** and contains
none of them, so compiling against SFF's source folder cannot work (investigation 4.8 / risk R4).
`SFF_SKSE` is exported the OStim way - declarations only, straight from the installed source.

**Deploying `DialogueFollowerScript.pex` would overwrite SFF's real script and break every follower in
the save.** `compile.ps1` now carries a denylist: it refuses the build if any stub name appears among
the shipped scripts or turns up in `game\LoreRimGlue\Scripts`.

---

## 3. Server side

### 3.1 `lib\lrg_core.php`

* `lrgFolState()` / `lrgFolFor()` - the reader. **An absent `fol=` is "say nothing"** in every
  direction; a truncated value without `fw` is refused whole rather than half-believed; a snapshot
  older than `snapshot_max_age_seconds` is not a fact and hides nothing.
* `lrgFollowerBlock()` -> `<companion_status>`, `character_bottom`, priority 204 (just before the
  locked facts at 205), <= 520 chars. Facts only: her role, following or waiting, the slot count, and
  the sentence that does the work - *"Those are things she does, not things you describe: never say
  that one of them has happened unless it has."* A ghost is deliberately **not** called a companion
  ("nothing has been settled properly between them"). It never duplicates CHIM's own
  `<adventuring_party>`, which keys off `conf_opts.CurrentParty` and appears by itself once she is a
  real teammate.
* `lrgFollowerPolicy()` - called at brace depth 0 from `functions.php`, **after every other filter**,
  so it can only ever remove. Three independent reasons, each a fact the game sent this moment:
  `MakeFollower` while `mate=1`; `MakeFollower` while `cap=0`; `MakeFollower` + `Follow` +
  `FollowPlayer` while `ghost=1`. Verified on the deployed server:

  ```
  no fol= at all       -> Talk,MakeFollower,Follow,FollowPlayer
  ghost=1              -> Talk
  already a teammate   -> Talk,Follow,FollowPlayer
  party full (cap:0)   -> Talk,Follow,FollowPlayer
  ordinary stranger    -> Talk,MakeFollower,Follow,FollowPlayer
  ```
* `lrgFollowerCatalogHealth()` - one log line per server start when `MakeFollower` or `Follow` is still
  `is_activated = t`. **Nothing is disabled**: switching an owner's action off is the owner's call.

### 3.2 `lib\lrg_dialogue.php` - the follower verbs

`services.follower.verbs`, eight verbs, each with `say` (what the player may say), `entry` (what the
entry itself says) and `topics` (the topic EditorID family). All three were read out of **this load
order's 37,561-row index**, not invented - the vanilla set on quest `DialogueFollower` (won by Missing
Follower Dialogue Fix), Set Follower Home's real topics `SetHomeSetTopic` / `SetHomeUnsetTopic` (not
the names the investigation guessed at), and the custom followers' own copies.

`tools\test_services.php` section 6 now proves that table against the live index; every verb matched
real topics here:

| verb | matched, for example |
|---|---|
| recruit | `DialogueFavorGenericFollowBranchTopic` (the vanilla "Follow me. I need your help.", added in the 0.5.1 fix pass - the old `*FollowerRecruit*` glob missed it and only the entry-text fallback resolved it), `ksws07FollowerRecruitTopic`, `GRVEShadowFollowerRecruit_01Topic` |
| follow | `DialogueFollowerFollowTopic`, `DLC2HirelingQuestFollowerFollowTopic` |
| wait | `DialogueFollowerWaitTopic`, `DialogueFollowerWaitDummyTopic` |
| dismiss | `DialogueFollowerDismissTopic`, `DLC2HirelingQuestFollowerDismissTopic` |
| trade | `DialogueFollowerTradeTopic`, `ksws07FollowerTradeTopic` |
| favor | `DialogueFollowerFavorStateTopic` |
| home / unhome | `SetHomeSetTopic` / `SetHomeUnsetTopic` |

**Matching is exact token containment, longest wins, with the negation guard** - the same rule as the
price slots, never similarity. The player lines are two words long ("wait here", "follow me") and a
wrong pick dismisses the player's real follower. `lrgDlgFollowerArbitrate()` runs FIRST in
`lrgDlgGateItem()`'s intent branch and returns one of three verdicts: run that entry, run **nothing**
(no entry for the verb, an ambiguity, or the player negated it), or "not a follower turn at all", in
which case ordinary matching is untouched.

Three rules that were added because the flow test caught the absence of them:

1. **A negated order cannot be revived by the model's paraphrase.** "Don't wait here, come with me
   instead" used to reach the wait entry through the model's own `item`. Now, when the player's words
   carried a follower order and every occurrence of it was negated, the turn settles nothing and the
   item is not consulted.
2. **A hard rail on the topics that must never be selectable by voice**, however they were resolved -
   including by the similarity matcher, which will happily match a favour-BLOCKING topic word for word.
   The globs are follower-specific (`*DoingFavorBlocking*`, `*FollowerAnimal*`,
   `DialogueFollowerAnimal*`, `*FollowerRecruitReject*`) so an ordinary quest topic about animals is
   untouched.
3. **`dismiss` and `home` are marked `commit`**, so the existing two-step confirmation always runs on
   them whatever the index says about the entry.

**The hide rule has its own, narrower condition.** `hide_follower` is a **third** shipped list beside
`hide_chim` / `hide_always`, because "she has some list" is no reason to take CHIM's
`Follow_<player>` away - only a list that really carries follower entries is a replacement for it.
d56 now asserts both that the kind's hide list is a subset of the three, and that `hide_follower` and
`hide_chim` do not overlap, so the narrow condition cannot quietly widen.

### 3.3 Requiem's recruitment gates

No code at all. They are INFO conditions, so using the real entry respects them by construction. What
the build had to guarantee is the other half: **the glue never fabricates a recruit and never offers
CHIM's shortcut as a substitute for one the engine is not showing.** d62 asserts exactly that, and it
is the check that would have failed if the fallback had been left in.

---

## 4. MCM - five new controls on a new "Followers" page

| key | default | meaning | reader |
|---|---|---|---|
| `bFollowerAware:Followers` | 1 | send `fol=` and `witchim` at all | wire key `fol` |
| `bFollowerVerbsReal:Followers` | 1 | route the follower verbs to her real entry | wire key `fv` |
| `bFollowerHoldSkip:Followers` | 1 | the hold skips anyone already walking with the player | `LRG_Main.psc` |
| `bFollowerRepair:Followers` | 1 | repair a half-recruited follower | `LRG_Main.psc` |
| `iFollowerRepairMode:Followers` | 0 | 0 make her real if there is room, 1 always undo, 2 only tell me | `LRG_Main.psc` |

All five have a `settings.ini` line (a missing key reads as 0/FALSE in this project) and a reader;
`test_mcm_wiring.php` asserts both halves for each, and both wire keys are asserted as
written-by-a-`.psc` **and** read-by-a-`.php`.

---

## 5. Wire contract

PROTOCOL.md is now **v0.5.1**, section **10.19**, additive only - `v` stays `2` and all three new
snapshot keys (`witchim`, `fol`, `fv`) are optional. A 0.5.0 game script produces exactly the 0.5.0
behaviour in both directions. The key table in section 1.1 points at 10.19.

---

## 6. Verification

Everything below was run from the staged copy, against the real load order and the real database.

```
php -l: 74 files, 0 errors
test_gates          316 passed, 0 failed     (20 new follower checks)
test_intent         190 passed, 0 failed
test_phrases         14 passed, 0 failed
test_scene_index    ALL CHECKS PASSED
test_dialogue       132 passed, 0 failed     (20 new follower-verb checks)
test_prompt_index    67 passed, 0 failed  /  --db 112 passed, 0 failed
test_mcm_wiring       4 passed, 0 failed     (all 5 new controls wired)
test_services        35 passed, 0 failed     (11 new: the verb table vs the live index)
test_latency         26 passed, 0 failed
flows --strict      74 scenarios: 74 passed, 0 FAILED, 0 pending; 1161 checks, 0 warnings
compile.ps1         OK - 10 .pex, 0 errors, 0 warnings
```

New flow scenario **d62** (33 checks) covers: the shortcuts stepping aside only for a real follower
list; "wait here" / "follow me" / "let's trade" reaching the right entry by position with the wire key
order unchanged; the dismissal two-step; a verb with no entry executing nothing; a negated order; the
Requiem case; the blocking topic; the three snapshot rules; and the prompt block and turn line.

**The three defects d62 found before it passed** (all now fixed, each with its own check):
a negated order revived through the model's `item`; the favour-blocking topic reachable through the
similarity matcher; and - not a defect, but worth recording - "Come with me, I need your help" is
independently classified as a persuasion attempt by the v0.5 free-conversation check, so the assertion
had to be "no entry was clicked", not "nothing at all happened".

### A coordination note

Another lane was editing the shared tree while this round ran (`lib\lrg_actions.php`,
`lib\lrg_latency.php`, `lib\lrg_prompt_index.php`, `tools\test_latency.php`, `tools\bench_llm*.php`,
`LRG_Dialogue` / `LRG_DlgUI` / `LRG_DlgProbe` / `LRG_MCM`, and four flow scenarios). The compile, the
install and the server deploy are whole-tree operations, so they carried that lane's work along with
this one. Everything was green together at the moment it shipped - `php -l` over 74 files, every
offline test, and all 74 flow scenarios - but the .pex files of the other lane's scripts were rebuilt
and re-copied in the same pass, and the numbers quoted above are of the combined tree.

### Installed

* **Game side**: `tools\install_mo2.ps1` **refused, because Mod Organizer is open** (Skyrim is not
  running). Per the round's install rule, our own changed files only were copied into
  `F:\Modlists\LoreRim\mods\LoreRim Glue` and verified by SHA-256: 10 `.pex` (including the new
  `LRG_Followers.pex`), `LRG_Followers.psc`, `LRG_Main.psc`, `LRG_Profile.psc`, `config.json`,
  `settings.ini`. `LoreRimGlue.esp` and `Seq\LoreRimGlue.seq` were already identical - **no ESP change
  was needed**, `LRG_Followers` is a `Hidden` script like `LRG_Profile`. No profile file was read or
  written, and nothing outside the glue's own mod folder was touched.
* **Server side**: `tools\deploy_server.ps1` - anchor pre-flight 28/28 (five new anchors for this
  round's wiring), scene index rebuilt (607 scenes), prompt index rebuilt and loaded (37,561 prompts /
  5,718 layers into `lrg_index`), index pre-flight passed, `LRG_VERSION` and `manifest.json` both read
  `0.5.1` on the live copy.

---

## 7. OWNER STEPS - please do these

### 7.1 CHIM web UI, port 8081 -> Actions (this is the one that matters)

Both of these are still `is_activated = t` in the live catalog (checked just now):

| action as shown | code | do | why |
|---|---|---|---|
| **`Join_<player>_Party`** | `MakeFollower` | **switch OFF** | It is written for Nether's Follower Framework, which is not installed here. All it can do is put the NPC in the vanilla follower faction and stop, and the game's dismiss line then goes to your REAL follower. The glue now hides it wherever it can see that it cannot work and repairs the damage if it happens anyway - but the only way it can never happen is this switch. |
| **`Follow`** | `Follow` | **switch OFF** | Follow *another actor* at priority 100, with no owner and no end condition. Nothing in the glue uses it. (`Follow_<player>` / `FollowPlayer` is a different row - **leave that one ON**.) |

`Wait_Here` is already off and its Papyrus body is a deprecated no-op; leave it off.

### 7.2 In-game MCM

* **LoreRim Glue -> Followers**: the page is new. The defaults are the ones you want; the only choice
  worth a thought is *"How to fix her"* - leave it on *"Make her real if there is room"*.
* **CHIM MCM -> Behavior**: switch **"NPCs Sandbox Near Player"** and **"NPCs Walk To Target"** off
  while you are testing with a real follower. They are the priority-55 layer and they compete with her
  follow package for no benefit.
* **CHIM MCM -> Keys, the K2 hotkey** ("double-tap to make the NPC in your crosshair wait here"): do
  not use it on a follower. It applies a package, not `WaitingForPlayer`, so she stands still while
  every follower mod still thinks she is following you.
* **"force default voice"**: leave it off. It is an RDO workaround, RDO is not installed, and turning
  it on kills custom-voiced follower audio.

### 7.3 Simple Follower Framework - your call, no change required

`mods\Simple Follower Framework\SKSE\Plugins\SimpleFollowerFramework.ini` has
`bFollowerOptionSelector=2` and `iSpeechLevelsPerSlot=25`, so **your companion slots are Speech / 25 +
1** - early game that is **one slot**. If you want to exercise the follower paths now, either raise
Speech or temporarily set `bFollowerOptionSelector=0` with `iMaxFollowers=2`. SFF has no MCM; the ini is
the control surface.

### 7.4 MO2

The install ran with MO2 open, so the mod's `meta.ini` version was not refreshed. It reads **`0.1.0`**, not `0.5.0` as this line first said - that version line has never been written since the very first install. If you want MO2's
left pane to show `0.5.1`, close MO2 and run `tools\install_mo2.ps1` once - it is safe to re-run and it
will also re-check the profile entries.

---

## 8. What is still unknown, and what to watch for in the playtest

Everything here is `[U]`: no playtest has ever had a follower present.

| # | test | pass |
|---|---|---|
| P-F1 | Recruit Lydia normally, then talk to her with CHIM | the log line carries `fol=fw:sff/...`; `<companion_status>` appears; `MakeFollower` is not offered |
| P-F2 | With Lydia recruited, force `MakeFollower` on a second NPC (temporarily re-enable it) | a `FOLLOWER GHOST` line and a corner note within ~20 s; **then** say "it's time we parted ways" to the ghost - **Lydia must NOT be dismissed** |
| P-F3 | With Lydia following, issue CHIM `TravelTo` / `Sandbox` on her, then walk away | closes the investigation's U2: does `AddPackageOverride` at 50-100 really beat SFF's alias package? If it does, `TravelTo` / `Sandbox` / `MoveTo` should be hidden for anyone `IsFollowerLike` too - that edit is **not** in this round |
| P-F4 | Say "wait here" / "follow me" / "let's trade" / "I need a favour" / "settle down here" (needs `bMenuless` on) | each maps to the real entry, exactly one command per turn, no CHIM shortcut in the same reply |
| P-F5 | Conversation hold on a follower and on a CHIM pseudo-follower | neither is frozen; the log reason reads "she walks with you already" for the second |
| P-F6 | A follower present during an OStim gate | `witfol >= 1`; `companion_present` fires on a `followers_ok:false` profile; closes U5 (does Considerate Followers mute her during a menuless session?) |
| P-F7 | Dismiss the SFF primary while an extra follower exists | `SFF_PromoteExtraToPrimaryIfNeeded` runs; `fol=` follows the promotion within one snapshot |
| P-F8 | Inigo | `fw:custom`; the glue never writes his `WaitingForPlayer` (it never writes that value at all); his own dialogue untouched |

Two things this round deliberately did **not** do, both recorded in the investigation:

* **The action set flips when she becomes a real follower** (`IS_NPC` is derived from CHIM's
  `CurrentParty`, which is empty today): she loses `FollowPlayer`, `MakeFollower`, `MoveTo` and
  `EndConversation` and gains `SheatheWeapon`. Every glue row already carries
  `available_to_followers = 1`, so nothing of ours disappears - but the whole v0.4 / v0.5 test matrix
  has only ever been played with `IS_NPC = true`, and it should be run once with a real follower.
* **`stayAtPlace` leaves an NPC in `BardAudienceExcludedFaction` forever.** Cosmetic, and it is another
  mod's actor edit made by CHIM; our own copy of that function does not do it.
