# pt7 RELEASE (final) - v0.3.1

Role: release. Confirm the ten defects are closed, run every gate, deploy the server, install the
game side, write PLAYTEST7_NOTES.md. Ran 2026-09-21 20:25-21:00 local.

Manifest 0.3.1 · script version 310 (`LRG_Main.CurrentVersion`) · `LRG_ACTIONS_VERSION` 9 · schema v3.

## 1. Defect verdicts

| # | defect | verdict | where I looked |
|---|--------|---------|----------------|
| V1 | `lrgActFixSexes` swaps the ACT FAMILY instead of the ROLE | **CLOSED** | lrg_scene_index.php:1413-1438 (`$roleAssumed` mirror-first branch) + lrg_scene_index.php:1458-1494 (`$assumed` set where nothing in the sentence named a direction). Probe: `lrgResolveAct('cunnilingus')` → `OARE_KneelingCL` (was `OARE_SittingFellatio`); `lrgResolveAct('fingering')` → `OARE_StandingKissFingering` (was `OARE_AceStandingHandjob`). |
| V2 | a bare directional act id with no role suffix is DROPPED | **CLOSED** | lrg_scene_index.php:1518-1522 + lrg_actions.php:1645-1650 (second unfiltered pass). Probe: `oralpenis` → `OARE_SittingFellatio`, `oralvulva` → `OARE_KneelingCL`, `vaginal` → `OARE_StandingCarryingSex`, `spanking` resolves. None NULL. |
| V3 | an aborted scene counts as a real scene | **CLOSED** | lrg_actions.php:341-347 now gates on `lrgSceneCounted()` (:493-502), the same two guards as the gain. Probe A5: 8 s `how=stopped` → `scenes=0 history=+0`; A5b: the next real scene still gets `adjust:Hulda:8`. |
| V4 | act/position coverage cluster | **CLOSED for everything named except two furniture rows** | end-to-end probe, in a running scene: `sit on my lap`→`OARE_SittingSex`, `pick me up`→`OARE_StandingCarryingSex`, `grab my ass`→`OStim2PStandingBehindJerkToButtMF`, `fuck my cock with your tits`→`OARE_MountedTitfuck`, `turn around and ride me`→`OARE_ReverseCowgirlMounted`, `i want to be inside you`→vaginal. Remaining: `get on the bedroll` = `intent=none`; a furniture half inside a compound is not split (`get undressed and get on the bed`, `put me on the table and fuck me`). Listed as known limits, not blockers. |
| V5 | `fQueueWait` 20 s < the game's own 25 s navigation deadline | **CLOSED** | settings.ini:32 `fQueueWait = 30`; MCM slider help says "keep it at or above 30"; TickDeferred :1070-1077 also refuses to expire while `starting \|\| pendingStart \|\| navInFlight \|\| pendingVerb != ""`. **I additionally raised the script's own fallback** `SettingFloat("fQueueWait:Intimacy", 20.0)` → `30.0` at LRG_OStim.psc:1022 - a missing MCM key reads as 0 and landed on that 20. |
| V6 | the outro is a fifth wire item documented nowhere; no `dur=`/`how=`/`after=` | **CLOSED** | PROTOCOL.md 1.2 ("two new keys on `ev=end`", w3 table), 1.4 ("Text exactly `outro` = the goodbye turn (R3)"), section 2 (`after=` on a start), 5 (`lrg_memory.outro` ticket), 6.11. Additivity statement at PROTOCOL.md:5. |
| V7 | the outro hold can time out mid-sentence | **CLOSED** | LRG_OStim.psc:947-969 - the timeout branch checks `isActorTalking(outroName)` first, plus a 4 s grace after `outroSpeakStop` for CHIM's per-sentence gap, and extends `outroUntil` by 5 s. Self-heal at :905 (`2.0 * budget + 30.0`) still bounds it. |
| V8 | out-of-scene undress still got the neutral "her own choice" directive | **CLOSED** | lrg_intent.php:807-836 - `$isCloth = in_array($kind, ['undress','dress'])`, gated on `lrgTurnOffers(LRG_ACT_CLOTHING)`, and names ChangeClothing with the exact item. Verbatim probe: *"...AND choose ChangeClothing with item \"undress\" in the same reply - that is the only way it happens."* |
| V9 | out-of-scene compound promised "Both happen" and dropped the second half | **CLOSED** | lrg_intent.php:770-773 splits `$mention` from `$both`; :826-834 appends `$both` only when ChangeClothing+BeginIntimacy (or the reverse) really carry it; :839 (the plain out-of-scene fallback) uses `$mention` alone. Verbatim probes confirm both shapes. |
| V10 | PROTOCOL.md still said the game drops goto+goto | **CLOSED** | PROTOCOL.md:118 rewritten. **I corrected one stale number there myself**: it still said `fQueueWait` default 20 s. |

## 2. Fixes I made in this pass (release lane, small and still-open)

1. `glue/tools/install_mo2.ps1` - `meta.ini` version was hard-coded `0.1.0`. It now reads
   `server/lorerim_glue/manifest.json` and refreshes the `version=` line of an existing meta.ini too.
   (The game builder flagged this as out of its lane; tools/ is in mine.)
2. `glue/PROTOCOL.md` section 2 - `fQueueWait` default corrected 20 → 30, with the reason and the
   in-flight hold-open rule spelled out. PROTOCOL wins over code, so it must not carry a stale number.
3. `glue/game/LoreRimGlue/Source/Scripts/LRG_OStim.psc:1022` - the script's own fallback for a missing
   `fQueueWait` MCM key raised 20.0 → 30.0, so V5 cannot come back through the missing-key path.
   Recompiled; `compile.ps1` printed OK.

## 3. Gates

| gate | result |
|---|---|
| `compile.ps1` | **OK** - LRG.pex, LRG_Main.pex, LRG_MCM.pex, LRG_OStim.pex, LRG_PlayerAlias.pex, LRG_Profile.pex; 0 errors, 0 warnings; the >500-char .pex string refusal did not trip |
| `php -l` | 53 files, 0 lint failures (PHP 8.2.28); re-run on the deployed tree after deploy, also 0 |
| `test_scene_index.php` | ALL CHECKS PASSED, 607 scenes, 3 pack folders, 0 dangling edges |
| `test_gates.php` | 238 passed, 0 failed |
| `test_intent.php` | 142 passed, 0 failed |
| `test_phrases.php` | 13 passed, 0 failed; 594/654 rows hit = 90.8% (floor 82%); every MUST group at 100% (`owner pt7`, `pt7 log F1/F2/F4`, `pt7 log`, `must NOT trigger`, `clothes`) |
| `flows/run_flows.php --strict` | 32 scenarios, 672 checks, 0 FAILED, 0 pending, 0 warnings, RESULT: OK |
| audit probes `probe_verify.php` / `probe2.php` | re-run against the fixed code; every R1/R2/R3/R4 assertion passes except the three noted in §5 |

## 4. Deploy and install

**Server.** `deploy_server.ps1` at 20:31 → `/var/www/html/HerikaServer/ext/lorerim_glue`, manifest
0.3.1, scene index rebuilt (607 scenes, 5.8 s). `php -l` clean on the deployed tree. The user override
`config/lrg_config.json` was preserved (it still holds the 17:58 `Lisette.min_affinity = -5`).
No plugin errors in `log/lorerim_glue.log` or `/var/log/apache2/error.log` - the only apache entries
around the deploy are CHIM's own npc_master UI traffic from 17:45-17:53.

**Game.** `install_mo2.ps1` refused: *"Mod Organizer is running"*. Skyrim was **not** running
(checked: `ModOrganizer` pid 42744 present, `SkyrimSE` absent). Under the main session's standing
authorisation I copied **our own files only** from `glue/game/LoreRimGlue` into
`F:\Modlists\LoreRim\mods\LoreRim Glue`: 6 × `Scripts\LRG*.pex`, 6 × `Source\Scripts\LRG*.psc`,
`MCM\Config\LoreRimGlue\config.json` and `settings.ini` - 14 files, all SHA-256 verified equal to
source. No profile file, no other mod, no `meta.ini` (outside the authorised list), no `.esp`, no
meshes. The Ultra profile already carried `+LoreRim Glue`, `*LoreRimGlue.esp` and the loadorder entry
from the first install, so nothing needed adding. No saved `MCM\Settings\LoreRimGlue.ini` exists in
Overwrite, so the shipped settings.ini defaults (fQueueWait 30, fOutroHold 25) are what the game reads.

## 5. Open, listed rather than fixed

1. **The Lisette repair has not fired yet.** Before state captured 20:30:
   `core_npc_master.extended_data->'relationships' = []`, `relationships_locked = false`,
   `lrg_romance(Lisette) = scenes 2, last_affinity 0`, and `lrg_memory(Lisette)` carries **no**
   `affinity_repaired_at`, i.e. the one-shot is armed and unspent. `lrgMaybeRepairAffinity()`
   (lrg_core.php:705-737) only runs inside a live game turn, deliberately - the config's own readme
   says a CLI write would not carry the game timestamp and the next reload would roll it back. I did
   **not** force it: doing so would burn the one shot before the very save load the diagnosis warns
   about (the 17:53 npc_master UI save wrote `relationships: []` into her history). Exact-once
   behaviour is proven offline instead (probe A7/A7d/A7e: one `set:Lisette:<n>:null` call however many
   times it is invoked, no other NPC touched, and it fires from an ordinary turn).
2. **The repair number is 26, not the 30 the round prompt recommended.** The fix pass changed it with
   a written reason (`relationship._repair_readme`): 18 was CHIM's last good value, and the shipped
   gain rule would have granted exactly one gain that evening (+8; the second scene was 3 minutes
   later, inside the 1800 s window). I left the builder's 26 rather than silently overriding either
   side, and put the one-line change and the deadline (before her next turn) in the owner notes.
3. **The corner note (`Debug.Notification`) for an impossible in-scene request still does not exist.**
   It needs a sixth additive wire item and the main session's word. The never-silence rail holds:
   she says it out loud. Unchanged from the server build report.
4. **anal, 69, rimming and massage have zero installed scenes** in these three packs. Verified again
   tonight by act-family installability probe. Answered with a spoken "there is no way to do that at
   all", never a substitute.
5. **No against-the-wall sex animation exists**, so "against the wall" resolves to `vaginal/wall` and
   lands on the nearest standing scene rather than a spoken can't. That differs from the audit note's
   expectation but is the better behaviour and is documented as a limit.
6. **Furniture as the second half of a compound request is not split out** (`get undressed and get on
   the bed` → undress only; `put me on the table and fuck me` → the act only). `get on the bedroll` is
   `intent=none` - bedroll is not in the furniture vocabulary.
7. **60 of 654 `test_phrases` rows still miss**, all floor rows, none in a MUST group. Unchanged from
   the build report.
8. **`meta.ini` in the MO2 mod folder still says version 0.1.0.** The fix is in `install_mo2.ps1` now,
   but that file was outside the copy I was authorised to make, so it needs one run of the installer
   with MO2 closed. Cosmetic only.

## 6. Owner decisions surfaced in PLAYTEST7_NOTES.md

- Turn on CHIM's `NEVER_CLEAR_RELATIONSHIP_DATA` (`conf_schema.json:117`, default false) or the wipe
  recurs on every reload of an older save.
- Repair number 26 vs 30, and it must be decided before her next turn.
- Remove the 17:58 `Lisette.min_affinity = -5` override now that the real fix is in.
- Install an anal pack if "i want to fuck your asshole" should work; nothing installed provides 69.
- Keep footjob (taste-listed) and facesitting (2 scenes) behind the say-it-by-name gate, or open/close
  them fully.
- Confirm the hard hold and `fOutroHold = 25` as shipped. Note: the build did **not** add a
  `bOutroDontMove` toggle - the hard hold is unconditional (`outroActor.SetDontMove(true)`,
  LRG_OStim.psc:794), released by `ReleaseOutroHold` (:826) on every path and unconditionally first
  inside `Maintenance` (:313) because SetDontMove is written into the save. That is the behaviour the
  owner asked to confirm, and there is no MCM key that could read as a missing 0. `fOutroHold = 0`
  switches the whole outro off.
- Run `install_mo2.ps1` with MO2 closed to fix meta.ini.
