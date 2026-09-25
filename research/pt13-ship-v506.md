# pt13 ship: LoreRim Glue server 0.5.4 + game script 506 ("follow me")

Verifier + release, 2026-09-23 (~02:30-02:50 local). Both lanes' reports were read
(`pt13-game-fix.md`, `pt13-server-fix.md`) and checked against the code, the playtest-13 logs, CHIM's
sources, `AIAgent.esp`, `Skyrim.esm` and `FDE Lisette.esp`. **One real defect was found and fixed**
(wait / release did not stop a follow that CHIM had put on). Two small alignments were also made.
**The server is deployed and the game files are installed**, both SHA-256 verified.

---

## 0. Status in one screen

| item | result |
|---|---|
| `tools\compile.ps1` | `OK` - 10 scripts, 0 errors, 0 warnings. Longest docstring 290 chars (pre-existing, `LRG_Dialogue.ServiceMenuOpen`) |
| `php -l` | 80 files, 0 errors |
| tests | test_gates 339/0 · test_intent 255/0 · test_phrases 31/0 · test_scene_index ALL PASSED · test_dialogue 174/0 · test_prompt_index 67/0 · test_mcm_wiring 3/0 · test_services 35/0 · test_latency 26/0 |
| flows `--strict` | **76 scenarios, 0 failed, 0 pending, 1252 checks, 0 warnings - RESULT: OK** (includes `28_escort`) |
| server | **0.5.4 deployed** to `/var/www/html/HerikaServer/ext/lorerim_glue`: 29 anchors, scene index rebuilt (607), prompt index reloaded (37,561 rows, source=db). All 25 deployed php/json/sql files match the staged source. Postgres was already up. `config/lrg_config.json` (the Lisette `min_affinity` override) was not touched |
| game | **script 506 installed** into `F:\Modlists\LoreRim\mods\LoreRim Glue`: 10 `.pex`, 10 `.psc`, `config.json`, `settings.ini`. 22 files, 0 hash mismatches. The ESP (`82a20db39da4dbf4`) and SEQ (`a881f50a2b2b30a3`) are unchanged and identical to the installed copies. No stub `.pex` shipped. No `LRG*` file in MO2's `overwrite`. SkyrimSE was not running. MO2 (pid 41132) was open, and no profile file was touched |
| 505 backup | `%TEMP%\lrg_v506\installed505\` (the files that were installed before). Sources before this pass: `%TEMP%\lrg_v506\bak\` |

Installed hashes (SHA-256, first 16 hex; full hashes were compared):
`LRG_Main.pex B4C686AE3AA83BF7` · `LRG_Dialogue.pex 80B4D752E27700AA` · `LRG_Profile.pex 847323B390A38752` ·
`LRG_Followers.pex A12A0D6226911FC7` · `LRG_OStim.pex 090CB883C8A37BAB` · `LRG.pex 077C9B15C85DDA78` ·
`LRG_DlgProbe.pex 5C1AFC1E1CE139AF` · `LRG_DlgUI.pex FC052D709EC2551C` · `LRG_MCM.pex 534001725BDF915A` ·
`LRG_PlayerAlias.pex 754124E6388E6461` · `LRG_Main.psc 55EFC914FD359B3C` · `config.json F3D03D4E30FD4C61` ·
`settings.ini 330718AA09BA0E78`. (The `.pex` hashes differ from the game lane's table because this pass
recompiled the scripts.)

---

## 1. None-to-array casts: confirmed fixed

* **Log:** there are 123 cast / mismatch error lines. Every one of them that touches the glue quest is at one
  of five sites, all in `LRG_Dialogue`: QobjCsv 3682 (43), QuestCsv 3638 (21), QobjCsv 3668 (21),
  QalCsv 3398 (21), VkSweep 3581 (17). There is **no error in `LRG_Main`, `LRG_Profile` or `LRG_Followers`**.
* **Own source scan** (independent of the game lane's `vpex` tool). A type-aware scan tracks every
  `T[]` local, parameter, script variable and array-returning function or native. It then checks every
  `== None` / `!= None` / `= None` / `return None` against those types.
  * On the 505 backup it finds **exactly six** sites: 3399, 3582, 3639, 3669, 3683 and 3714. That is each
    logged line + 1, plus `HasActiveJournalQuest`.
  * On 506 it finds **0**.
* **Bytecode** (`verify506.py` on the recompiled `.pex`): 0 None-to-array casts in all ten scripts.
* The new code (`!arr` / `if arr`) is the VM's own truth test and cannot fail on None. The
  `QobjCsv` data loss (only the first quest's objectives) goes away with it.

## 2. The abort detector against the playtest-13 timeline

**The new logic could not have produced the six old SNAPSHOT ABORTED lines.** The 505 rule fired the moment
a second event found the rail busy, with no time limit. So every reported attempt began after the previous
report, or after the load for the first one. That bounds each attempt's age when it was reported:

| 505 report | stage | attempt began no earlier than | age at report |
|---|---|---|---|
| 01:32:44 | 23 | load 01:32:42 | <= 2 s |
| 01:33:08 | 3 (fol breadcrumb; retired fol=) | 01:32:44 | <= 24 s |
| 01:33:31 | 3 | 01:33:08 | <= 23 s |
| 01:33:50 | 1 | 01:33:31 | <= 19 s |
| 01:34:27 | 1 | 01:33:50 | <= 37 s |
| 01:34:44 | 1 | 01:34:27 | <= 17 s |

All six are under `SNAP_LOST_SECS` = 45. In 506 each of those events would have **skipped** (`snapSkips`) or,
if forced, waited 0.5 s and gone ahead with its own token. Nothing would have been judged or retired.

**Stack dumps confirm no attempt was ever long-lived.** Eleven dumps come ~62 s apart (01:33:43 ... 01:44:23).
Every `LRG_Main.MaybeSnapshot` stack in them appears in exactly one dump.

**The four BOOT STEP ABORTED lines** (steps 1, 2, 8, 9, all begun 01:32:42-45) need 60 s of silence in 506.
The 01:33:43 dump (58-61 s after they began) holds no `BootTick` / `FollowerSweep` / `RegisterKeys` stack of
the new quest, so all four had returned. The only `FECF6802` stack in that dump is one `MaybeSnapshot` from
`OnChimSpeechStarted`. The reason for the old lines is the game lane's: overlapping steps, and one shared
`bootDone` number.

**A real abort, on paper (snapshot).** Attempt A takes token 17 at t0, reaches stage 7, raises
`SnapFolTry(true, 17)` and never returns (an SFF native deadlock, like pt12's frozen pair).
1. From t0 to t0+45 s, crosshair and speech events skip. A forced end-of-scene snapshot waits 0.5 s and goes
   ahead beside A.
2. The first event after t0+45 s calls `SnapJudgeLost`. It frees the rail and logs `SNAPSHOT LOST: an attempt began 4x s ago in the follower block (LRG_Followers.FolState, SFF natives) (stage 7) and has not returned - this snapshot was in the follower block (1 of 3 in a row before fol= is switched off)`.
3. That event runs its own attempt. If it sticks in the same block, the count goes to 2, then to 3:
   `fol= / witchim are off for this session (... 3 times in a row)`.
4. Any clean pass through the block (`SnapFolTry(false)`) resets the count to 0. If A ever returns, it logs
   `SNAPSHOT LATE` and takes back its count, which can switch fol= back on.

**A real abort, on paper (boot).** Step 9 stuck in po3 at t0. `BootJudge` runs on every boot tick and every
30 s heartbeat. At the first heartbeat after t0+60 s it logs `BOOT STEP LOST: 9 (FollowerSweep ...)` plus
"1 of 3". It logs `BOOT STEP LATE` if the step comes back.

**fol= is never retired on one suspicion.** `snapFolBad = true` is written in exactly one place,
`SnapFolCount`, and only when `snapFolFails >= 3`. `snapFolFails` only grows on a LOST verdict (45 s or 60 s).

Residuals (not defects, accepted):
- A forced snapshot that goes ahead beside a stuck holder takes the rail, so that one stuck attempt is never
  judged. A block that is really broken sticks the next ordinary attempt too, and that one is judged.
- After the boot queue, `BootJudge` only runs while `bHeartbeat` is on (the default).

## 3. Escort: the whole trace

**Player -> intent (server, deployed code, smoke-run).**
* The three pt13 lines read: "...come with me and talk to me in private..." -> `escort/follow`,
  "come on follow me now" -> `escort/follow`, "hey are you gonna follow me?" -> `none (question)`.
* The last one still works: CHIM chose `FollowPlayer` for it in pt13, and `lrgEscortPlan` fires on CHIM's
  `FollowPlayer` line alone (with `scene=1`), with or without an intent.
* Negations, hypotheticals, quotes, "oh come on", "I'll wait here" and "they will follow me" stay nothing.
* Inside an OStim scene, "wait here" = hold and "come with me" = climax, as before.

**Intent -> directive -> CHIM.** "Do it now: choose Follow_<player> in this same reply ..." (+ "the game takes
her away from what she is busy with first").

**CHIM -> Escort line.** `lrgEscortNet` inserts `Lisette|command|ExtCmdLRG_Escort@ok=1;cid=<turn cid>;npc=Lisette;do=follow;safe=BardSongs,BardSongsInstrumental,*Idle*,*Sandbox*,WI*`
immediately before CHIM's `FollowPlayer` line. It does so only for a player speech turn, outside a scene with
her, with a snapshot <= 300 s old showing `scene=1` or gate reason `quest_scene`, and never when a follower
framework or the party owns her.

**Game** (`HandleCommand` -> `CmdEscort` -> `EscortFollow`):
* Guards: alive, 3D, **not a teammate, not SFF**, not hostile, no combat on either side, no arrest, not in the
  glue's OStim scene.
* Scene verdict, in order: deny list -> open journal objective -> safe list.
* Stop: bards get `BardSongsScript.StopAllSongs()`, then `Scene.Stop()`. **No do-nothing package** (bytecode:
  the only package writes in the escort code are `RemovePackageOverride` and the one `AddPackageOverride`
  inside `LRG_Followers.ChimFollowPlayer`).
* Follow: CHIM's exact recipe (`AIAgentAIMind.stayAtPlace(npc,1)`, AIAgentAIMind.psc:913-922) - FollowFaction
  0x1BC24 rank 1, `AddPackageOverride(0x2226D, 100, 0)`, `CHIM_FollowPlayerActive=1`, `EvaluatePackage`.
  The glue leaves out `ResetPackages` and the never-removed `BardAudienceExcludedFaction` on purpose.
  If the flag is already 1, only `EvaluatePackage`.
* Every path answers a funcret through `ReportResult`. The server swallows it as `handled` before the lock.
* Follow-up tick at +2.5 s and +6 s.
* In either delivery order (Escort first, or CHIM's `stayAtPlace` first) she ends up with the scene stopped
  and the follow on.

**Default patterns against real EditorIDs** (read from the plugins, not assumed):

| quest | where | verdict |
|---|---|---|
| `BardSongs` 0x74A55 | Skyrim.esm, carries BardSongsScript (+ QF) | safe; `StopAllSongs` on it |
| `BardSongsInstrumental` 0x6E53F | Skyrim.esm, only the QF fragment script | safe. The cast to BardSongsScript is None, so the fallback calls `StopAllSongs` on `BardSongs`. USSEP's BardSongsScript on BardSongs holds **all 20 song scenes including every `BardSongsInstrumental*` scene**, and `StopSong` gates the end fragment. So the fallback is the right call |
| `aaLisette` / `aaLisetteIdle` | FDE Lisette.esp: 2 quests, **0 scenes, 0 objectives** | `aaLisetteIdle` matches `*Idle*`, but it owns no scene, so her stage scene is always a BardSongs one |
| `WITavern` 0xDEE92 | Skyrim.esm, 0 objectives | safe (`WI*`) |
| `WI*` in Skyrim.esm | ~60 radiant quests | `WICourier` denied; `WIKill*` / `WIAssault*` actors are hostile or fighting and refused by the guards; `WIAddItem` / `WIRemoveItem` deliveries are refused while their objective is on screen |
| `*Idle*` / `*Sandbox*` | no vanilla or DLC quest has either in its EditorID | only mod quests |

`PlaySong` in USSEP also aborts for a bard in `CurrentFollowerFaction`, but CHIM's follow faction is a
different one. So a quest that restarts a song is caught by the follow-up tick, at most twice.

**Defect found and fixed: wait / release did not stop CHIM's follow.**
* CHIM's `AIAgentFollowPlayerPackage` (AIAgent.esp PACK 0x2226D) has one condition:
  `GetFactionRank(AIAgentFactionFollow) == 1`. It never reads `WaitingForPlayer`.
* The 506 `do=wait` / `do=release` only removed a follow the glue itself had put on (`LRG_EscortApplied`).
* In pt13 CHIM's flag was already 1 before the glue could act ("she walks with you already"). CHIM's own line
  also re-applies the follow in the same batch. On this install CHIM has no NPC action that ends its follow
  (`WaitHere` is a stub and off; `StopWalk` is not offered to NPCs).
* So "wait here" / "you can go" would have been answered "waits here" while she kept following. The server
  had just told her "the game keeps her here", and PROTOCOL 10.20 section 4 asked the game to remove CHIM's
  override.
* **Fix** (`LRG_Main.EscortStopFollowing`, used by `EscortWait` and `EscortRelease`):
  * a follow the glue applied goes as before (with the faction the glue added);
  * otherwise, if `CHIM_FollowPlayerActive == 1` and she is **not a teammate and not SFF**, the glue does the
    follow half of CHIM's own `ResetPackages`: `RemovePackageOverride(0x2226D)` and
    `CHIM_FollowPlayerActive=0`;
  * the faction is left, as CHIM's `ResetPackages` leaves it;
  * no stand-still package is added. After "wait here" she stops following and her own AI takes over (for a
    bard, her stage).
* The server's wait wording now says what really happens ("the game stops <npc> following <player> by
  itself"): `lrg_actions.php`, `test_intent.php`, flow 28, PROTOCOL.

## 4. The wire, three ways

| | shape |
|---|---|
| PHP (`lrgEscortNet` -> `lrgKv`) | `ok=1;cid=<cid>;npc=<name>;do=<follow\|wait\|release>;safe=<p1,p2,...>`. Values have `; = @ \| "` blanked; patterns are filtered to `[A-Za-z0-9_*?]` |
| Papyrus (`CmdEscort` / `EscortFollow`) | `ParamGet` of `cid`, `do`, `npc`, `safe` (absent `safe` = the identical built-in default); `ok` is not needed |
| PROTOCOL.md | section 2 row and 10.20 section 3: the same key order. **Section 10.20 section 4 was rewritten to the game as built**: StopAllSongs for bards, the deny list and journal check, the real error strings, wait / release ending the follow whoever put it on, the success strings and the self-heal. The section 2 row's wait text was aligned too |

`README.md` version markers are now 0.5.4 / 506.

Residual: if CHIM's DLL ever cut a command parameter at a `,`, `safe=` would shrink to `BardSongs`. That only
narrows what may be stopped, never widens it. The `param=` on the `command ExtCmdLRG_Escort` log line shows
what arrived.

---

## 5. Owner steps

**Nothing to install.** The server is live, and the game files are in the mod folder under their existing
names, so MO2 needs no refresh. Start the game from MO2 as usual. On load the corner should say
**`LoreRim Glue v506 loaded - session NNNN`**. If it says v505, stop and send the Papyrus log.

### What to say to make a bard follow

* Look at her and say it plainly, as an order or a polite request:
  * **"follow me"**
  * **"come with me"** (also "come with me upstairs", "come with me somewhere private")
  * **"come along"**, **"walk with me"**, **"let's go"**, or just **"come on"**
  * **"will you follow me?"**
* A bare question like "are you gonna follow me?" is not read as a request. It only works when she decides to
  follow on her own.
* She answers in one short line and should stop playing and walk after you within a second or two.
* **To stop her following:** "wait here" / "stay here" / "wait for me". She stops following you. There is no
  stand-still order in the game, so she then goes back to her own routine; a bard returns to her stage.
* **To send her back:** "you can go", "you can go back to singing", "stop following me", "you're free to go",
  "that'll be all".

### Corner notes

| corner note | meaning |
|---|---|
| `LoreRim Glue: <name> is in the middle of something she cannot leave (a quest in your journal)` | her scene belongs to a quest with an objective on your screen. Deliberately left alone |
| `... (a main, faction or courier quest)` | the scene's quest is on the game's deny list (MQ, CW, DB, TG, MG, Companions, Daedric, courier, arrest...) |
| `... (not a performance or idle scene she may leave)` | the scene's quest is not on the safe list. The log names the quest; send it if you think it should be |
| `... (the MCM keeps her in her scene (bEscortStopScene))` | MCM > Followers > "'Follow me' can pull her out of a performance" is off |
| `LoreRim Glue: <name> is in the middle of something she cannot leave` (no reason in brackets) | her scene was ended but kept starting again (checked twice). She is left in it |
| `LoreRim Glue: <name> will not do that now (hostile / combat / an arrest / a scene with you)` | a guard refused it |
| `... (she is your follower - her own follow and wait apply)` | she is a recruited follower; use her follower dialogue |
| `LoreRim Glue: <name> is not here` | CHIM could not find her as an agent |
| `LoreRim Glue: unknown command` | the game is still running script 505 (the new script did not load) |

### Log lines

**`Papyrus.0.log`, and the same lines as `GAME ...` in `lorerim_glue.log`:**

* The command arrived:
  `command ExtCmdLRG_Escort from Lisette via ... param=ok=1;cid=...;do=follow;safe=...`
* It worked:
  * `escort Lisette: stopped scene BardSongs (BardSongsScript.StopAllSongs + Scene.Stop) and following - CHIM's follow was already on`
  * then `escort Lisette: free of her scene - following`
* `escort Lisette: no scene to end - following - ...`: she was not in a scene; the follow went on anyway.
* `escort Lisette: her scene started again (...) - ended it again (...), try N`: the quest restarted it.
* `escort Lisette: she went back to her scene (...) and stays - ...`: the glue gave up (see the corner note).
* `escort Lisette: waits here (WaitingForPlayer 1, CHIM's follow is off (...))` and
  `escort Lisette: released - ...`: wait and release.
* `result ExtCmdLRG_Escort: <sentence or Error: ...>`: the answer that went back to the server.

**The rails** (these should be quiet):
* No `ABORTED` strings exist any more.
* `BOOT STEP LOST` / `SNAPSHOT LOST` means something was silent for 60 s / 45 s. `... LATE` means it came
  back.
* The second `maintenance done` line should end with
  `still running none, lost 0, ..., snapshots in flight skipped N, lost 0`. N may be any number: those are
  normal skips.

**Other game lines:**
* No `Cannot cast from None` and no `Mismatched types` for `LRG_MainQuest (FECF6802)`.
* `facts` lines now carry `al=<n>`.

**Server** (`lorerim_glue.log`):
* `turn npc=Lisette ... intent=escort/follow conf=high`
* `escort follow npc=Lisette scene=1 fol=... why=... -> ok=1;...`. Instead of this you may see
  `escort skipped: <why>` (for example "she is in no engine scene - CHIM's own follow is enough", or
  "the game has not reported on her recently").
* `llm npc=Lisette ... chim=FollowPlayer`

### If it still fails, send

1. **The time and your exact words**, what she said back, what the corner showed, and whether she was
   playing or singing at that moment.
2. **`Papyrus.0.log`** from `Documents\My Games\Skyrim Special Edition\Logs\Script\`. Copy it **before**
   starting the game again: the next launch rotates it.
3. **`lorerim_glue.log`** for those minutes (HerikaServer `log` folder), and CHIM's
   **`output_to_plugin.log`** if it is at hand. That shows whether CHIM chose `FollowPlayer` and whether the
   `ExtCmdLRG_Escort` line went out.

### Optional, unchanged from before

CHIM's catalog still has `MakeFollower` (id 28) and `Follow` (id 15) switched **on**. Switching both off in
the CHIM web UI (Actions) keeps CHIM from picking its half-recruit instead of `Follow_<you>`.
