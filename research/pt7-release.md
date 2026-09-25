# pt7 release pass (v0.3.1) - running log

Role: fast release. Verify builds/tests, check the two risky spots, deploy server, install mod, write PLAYTEST7_NOTES.md.
Started 2026-09-21 (owner mid-playtest, waiting).

Owner addenda read: she speaks before any change she initiates; inside a running scene she never refuses;
refusal only BEFORE a scene. Confirmed in force.

## 1. Build + test verification  -- ALL GREEN, builders' claims confirmed independently
- compile.ps1 (fresh run): "compiled: LRG.pex, LRG_Main.pex, LRG_MCM.pex, LRG_OStim.pex,
  LRG_PlayerAlias.pex, LRG_Profile.pex" + "OK ... 0 errors, 0 warnings". 500-char .pex guard passed.
- php -l on a FRESH stage of the project tree (not the builder's stage): server plugin 10 files 0 errors,
  tools 36 files 0 errors. lrg_config.default.json 39 keys OK, manifest.json 4 keys, version 0.3.1.
- scene index warm --force: rebuilt, 607 scenes, 5.8s (real installed packs via /mnt/f).
- test_scene_index.php: ALL CHECKS PASSED (exit 0)
- test_gates.php: 235 passed, 0 failed
- test_intent.php: 141 passed, 0 failed
- test_phrases.php: 13 passed, 0 failed; hit rate 84.7% vs floor 82%
- flows/run_flows.php --strict: 32 scenarios, 32 passed, 0 FAILED, 0 pending, 666 checks, 0 warnings

## 2. Risk review (two spots) -- BOTH SAFE, nothing switched off

### 2a. The outro hold always releases her (LRG_OStim.psc)
Verified by reading the whole mechanism, not just the timeout.
- ONE release function, `ReleaseOutroHold()` (:772). Re-entrant: the early return at :775 only fires when
  all three fields are already clear, so a call on a hold that only the SAVE remembers still runs.
- `SetDontMove(false)` + `EvaluatePackage()` are inside `if who != None && wasHeld`, so the inverse is
  always applied to the actor that was actually held.
- Only `SetDontMove` is used -- no AddPackageOverride, which is the classic "stuck forever" bug.
- GAME LOAD: LRG_PlayerAlias.OnPlayerLoadGame -> LRG_Main.Maintenance -> LRG_OStim.Maintenance:295, whose
  FIRST statement (:300) is `ReleaseOutroHold("the game was loaded")`, before anything else reads state.
- REGULAR TICK: LRG_OStim.Tick:3591 calls TickOutro FIRST, guarded by
  `if outroActive || outroActor != None || outroHeld` -- so a leftover actor with outroActive false is
  still caught (:860-864 "a hold was left over").
- SELF-HEAL :877 `afNow < outroStart || (afNow-outroStart) > (2*budget+30)` covers the real-time clock
  restarting at 0 on a new game launch and a dumped stack.
- Other exits: budget 0, feature off, new scene, dead/disabled/unloaded/unconscious, combat, player dead,
  distance (fOutroFar, with a code fallback of 1500 because a MISSING MCM key reads 0 -- :902-904, correct),
  another cell (only compared when one of them is in an interior), timeout, line finished.
  Plus :1435 (a new scene starting), :3292 (stop asked for), :3319 (a new scene began).
- Tick chain: TickOutro ends with `m.RequestTick(0.5)`; RequestTick/OnUpdate (LRG_Main:320-342) clears
  tickAt in OnUpdate before dispatch, so the chain cannot deadlock itself.
VERDICT: safe. Left ON at fOutroHold=25.

### 2b. Affinity gain + Lisette repair
- `lrgAdjustAffinity` (lrg_core.php:672) goes through `RelationshipManager::adjustRelationship($npc,'Player',$delta)`
  -- CHIM's own API, no $type passed, wrapped in try/catch, and it honours the owner's
  `relationships_locked` itself (:679) because CHIM's own setters do NOT check that flag.
- Scene gain `lrgAwardSceneAffinity` (lrg_actions.php:489): duration >= 60 s, how in finished|stopped, and a
  6 h window marker `aff_gain_at` in lrg_memory written only after a successful write (:508). Cannot double-fire.
- Repair `lrgMaybeRepairAffinity` (lrg_core.php:705): config-gated + NPC-name-gated, marker
  `affinity_repaired_at` in lrg_memory, and -- the important part -- it uses `setRelationship` to an
  ABSOLUTE value, so even a marker that failed to persist cannot compound the number. It also early-exits
  when the current value is already >= target. Genuinely idempotent.
- Call site lrg_actions.php:733 `if (!$same && !empty($GLOBALS['gameRequest']))` -- inside a live game turn
  only, which is required: the write is stamped onto CHIM's timeline from $GLOBALS['gameRequest'][2].
  Running it from the CLI would land on the wrong timeline and the next reload would roll it back.
  So the repair CANNOT be forced from here; it is armed and fires on her first spoken turn.
VERDICT: safe. Left ON.

## 3. Deploy (done)
deploy_server.ps1 -> "deployed to /var/www/html/HerikaServer/ext/lorerim_glue"
- manifest on the server: 0.3.1
- `schema ensured (v3, 3 migration files)` -- migration 003 ran; lrg_romance now has last_affinity +
  last_affinity_at (verified with \d). LRG_SCHEMA_VERSION was correctly bumped to 3, otherwise the marker
  file .data/.schema_v2 would have skipped it and every last_affinity write would have failed.
- `action catalog: rows installed (v9)`
- `scene index rebuilt: 607 scenes from 3 pack folder(s), 0 dangling edge(s), 5.8s, filter=open,
  taste-listed=38, always excluded=0`
- php -l over the DEPLOYED tree: 10 files, 0 errors.
- Live config/lrg_config.json PRESERVED exactly (deploy_server.ps1 excludes it via robocopy /XF):
  {"npc_overrides":{"Lisette":{"min_affinity":-5}}}
- apache error log: no lorerim_glue errors, no PHP warnings/fatals from our plugin. The only entries are
  CHIM's own notices.

### Lisette's relationship entry (post-deploy, PRE-repair)
core_npc_master id=2209: relationships = `[]`, relationships_locked = `false`, gamets_last_updated=5423094
lrg_romance: scenes=2, last_affinity=0, last_scene_at=1790025570
lrg_memory: no affinity_repaired_at, no aff_gain_at -> THE REPAIR IS ARMED and will fire on her first
live turn. It sets 30 (the builder's documented deviation: 18 was CHIM's last good value, not 10).
Because scenes=2 already, her next completed scene gives +4, not +8.

## 4. Install (MO2 open, Skyrim NOT running)
install_mo2.ps1 refused: "Mod Organizer is running." Skyrim/skse64_loader/SkyrimSELauncher: none running
(checked twice). Per the main session's authorisation I copied OUR OWN FILES ONLY into
F:\Modlists\LoreRim\mods\LoreRim Glue:
  Scripts\LRG*.pex (6), Source\Scripts\LRG*.psc (6), MCM\Config\LoreRimGlue\{config.json,settings.ini}
14 files, all SHA256-verified source == installed, 0 mismatches.
NOT touched: no profile file (modlist/plugins/loadorder/settings.ini in profiles\Ultra), no .esp, no
meshes, no meta.ini, no other mod. The .esp and meshes were already installed from the earlier round and
did not change this round.
MCM: settings.ini carries a default line for every new key (fQueueWait=20, fOutroHold=25, fOutroSettle=2.5,
fOutroMinScene=45, fOutroFar=1500), so nothing reads as 0.

## 5. Installed-act ground truth (for the notes, and it settles F7)
lrgActPool(male player, female NPC, no furniture) = 19 keys:
  facesitting:npc, fingering:you, footjob:npc, footjob:you, grinding, grope:npc, grope:you, handjob:npc,
  hold, kiss, masturbation:npc, masturbation:you, nipples:you, oralpenis:npc, oralvulva:you, spanking:you,
  thighjob:npc, titfuck:npc, vaginal
vaginal has all 12 positions (allfours, bendover, carrying, cowgirl, doggy, kneeling, missionary, prone,
reversecowgirl, sitting, spooning, standing).
F7 CONFIRMED: across all 607 scenes there is NO `analsex` action type at all -- only `analfingering`
(2 occurrences). `sixtynine` likewise has no scene. So "fuck your asshole" and "lets do 69" correctly get a
spoken "can't"; that is a missing animation pack, not a glue defect. All four of the owner's other pt7
phrases map to installed acts: blowjob -> oralpenis:npc (25 scenes), hug -> hold (24), eat your pussy ->
oralvulva:you (14), reverse cowgirl -> vaginal/reversecowgirl.

## 6. Left for the next pass (not fixed here, none of it blocking)
- PROTOCOL.md is stale: 1.2 still says ev=end carries only ev/npc/cid/scene/byglue, and section 2 lists no
  after= on StartIntimacy. Both need an additive row. I did not edit it - it is the wire contract and the
  verification pass runs next.
- The game now fires a FIFTH wire item, requestMessageForActor("outro","lrg_scenetalk",npc) ~1.5 s after
  ev=end. Beyond w1-w3; needs the main session's explicit blessing.
- No corner Debug.Notification for an impossible request (no wire key for it). Spoken line only.
- install_mo2.ps1:54 still writes version=0.1.0 into meta.ini (cosmetic).
- goto+goto in one reply is still refused by the game (navInFlight); the one-slot queue is a later fix.
- relationship.repair.enabled must go back to false once it has fired.

