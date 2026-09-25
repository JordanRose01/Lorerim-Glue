# PT15 - NPC memory reset for the real playthrough

Owner: "i will need to reset the npcs memory now that im gonna do my real playthrough."

**Status: ready, NOT run** (the owner has not said which moment). Installed to a normal Windows path, because the
project folder sits in the Claude app's virtualized AppData and a normal PowerShell cannot see it:

```
C:\Users\Jordan\Documents\LoreRimGlue\reset_playthrough.ps1   SHA-256 93129A4BBBD700DD... (matches glue\tools)
C:\Users\Jordan\Documents\LoreRimGlue\reset_playthrough.sh    SHA-256 986C52FFE44D7A4D... (matches glue\tools)
```

**The command** (Skyrim closed, CHIM server running, right before "New Game"):

```
powershell -ExecutionPolicy Bypass -File "C:\Users\Jordan\Documents\LoreRimGlue\reset_playthrough.ps1" -Yes -ChimToo
```

Run it without `-Yes` first to see the plan (read-only). Without `-ChimToo` it resets only the glue's own memory.

---

## 1. How CHIM 3.3.2 handles a new playthrough (read from the live server)

- **The Playthrough Manager has no "new/clean playthrough" button.** `ui/playthrough_manager.php` only does
  *create* (clone `public` into a `chim_profile_<name>` schema, "💾 Save Snapshot"), *switch* ("Copy to Public":
  auto-saves the active one, `DROP SCHEMA public CASCADE`, clones the snapshot in, `TRUNCATE database_versioning`,
  restart required) and *delete*. The only snapshot today is `default` (`chim_profile_default`, 2026-09-21
  16:56, 45 MB, 836 events). It already holds test data, so it is not a clean slate either.
- **CHIM's own reset is a game message, `wipe`** (`processor/comm.php:301`): deletes all of `eventlog, quests,
  speech, currentmission, diarylog, books, memory_summary, memory` and resets the quest-engine runtime. It is sent
  by AIAgent.dll (the string is in the DLL). No Papyrus script sends it, so what triggers it can't be confirmed.
  Don't count on it.
- **Every game load (`init`, `comm.php:103-226`)** deletes rows at or after the loaded game time from eventlog,
  speech, currentmission, diarylog, books, actions/moods, rumors, named_cell, sneq_quests_saved, bgl_history,
  memory_summary and memory. It clears responselog and rolemaster, resets the quest runtime and calls
  `NpcMaster::restoreNPC(ts)` (`lib/core/npc_master.class.php:1400`). That call **deletes every unlocked NPC
  row** and puts back the newest history snapshot at or before `ts`. NPCs without one are gone and get
  regenerated when you meet them. CHIM's own NPC-manager text says: "Loading an older save will roll back
  unlocked profiles ... NPCs created after the save's timestamp may disappear entirely." Relationship keys are
  then restored from history or cleared (`chimRelationshipFutureClearQuery`, `:237`).
  `NEVER_CLEAR_RELATIONSHIP_DATA` is **false** here, so this pruning is active.
- **So a New Game alone does most of the work, but not all of it.** Today's test data spans game time
  5,041,505-6,505,383 (about 0.15 game days). A new game starts near 5.04 M, so the load prune catches almost
  everything, but any row earlier than the new start survives. **`questlog` (319 rows) is never cleared by
  anything**, and the glue reads it for `<your_quests>` / `<shared_business>`. The glue's `lrg_*` tables are not
  keyed on game time at all. Dragon Break auto-snapshots only trigger on a rollback of 3 days or more, so this
  one would **not** be snapshotted.
- **UI-only tools**: Roleplay -> Events -> delete dropdown "Delete ALL" -> type `Delete` (clears `eventlog` and
  `log`). Roleplay -> Memories -> "Delete All Memory Summaries" -> type `Delete` (plain `DELETE FROM
  memory_summary`; embeddings are in-table and the txtai helper is obsolete). Nothing in the UI clears speech,
  memory, questlog or relationships.

## 2. Where NPC memory lives (live counts 04:4x today)

| Store | Rows | Script action |
|---|---|---|
| eventlog / speech / memory_summary / memory | 1556 / 140 / 3 / 1 | clear (-ChimToo) |
| questlog (journal history, read by the glue) | 319 | clear (-ChimToo) |
| responselog, rolemaster, actions/moods_issued, rumors, named_cell, sneq_quests_saved, bgl_history, log, physical_npc_diaries, diarylog, books, quests, currentmission, relationship_*_queue | small | clear (-ChimToo) |
| core_npc_master.extended_data relationship keys | 42 NPCs | strip the 6 keys CHIM's own clear uses (-ChimToo) |
| core_npc_master_history relationship keys (the timeline) | 452 snapshots | strip the same keys, so no reload can bring them back (-ChimToo) |
| lrg_npc_state / lrg_memory / lrg_dialogue / lrg_romance / lrg_scene_state / lrg_turn | 54 / 39 / 38 / 2 / 1 / 0 | clear (always) |
| lrg_config.json `npc_overrides.Lisette.min_affinity = -5` | - | removed (always) |
| default config `relationship.repair.enabled = true` (one-shot Lisette -> 26) | - | set false in lrg_config.json (always). It **must** be, because its done-marker lives in lrg_memory and would fire again |
| lorerim_glue.log | - | moved into the backup (`-KeepLog` leaves it) |

**Kept**: NPC profiles and voices (voiceid, voice_refresh_*, `Nametype/*` conf_opts), `relationships_locked`,
The Narrator, all settings and connectors, the action catalog (incl. ExtCmdLRG_*), prompts, Oghma, templates,
translations, quest assets, Playthrough Manager snapshots, `lrg_index.*` (prompt index = load-order data),
`ext/lorerim_glue/data` (scene index, service catalog), MCM settings. Game side: the calibration store lives in
the save, so a new game starts it empty. It was already empty anyway (`CALIB SUMMARY answered=0/10`, 01:32 today).
Nothing of the glue's is kept in an external JsonUtil file.

## 3. The script

- `reset_playthrough.ps1`: refuses if SkyrimSE is running. It stages an LF copy of the .sh in `%TEMP%\lrg_reset`
  and runs it with `wsl -d DwemerAI4Skyrim3 -u root`. The default is **plan only**; `-WhatIf` also forces plan.
- `reset_playthrough.sh <plan|run> <backup> <chim> <keeplog>`:
  - **Backs up first.** It writes `pg_dump --data-only` of every table it will clear, CSVs of `id, extended_data`
    for every NPC and history row it will change, and a copy of lrg_config.json into
    `Documents\LoreRimGlue\backups\reset-<stamp>\`. It stops before touching anything if a backup fails or is empty.
  - Writes **restore.sh** into that folder.
  - Clears everything in **one transaction** (all or nothing). Then it edits the config with PHP objects, so `{}`
    stays `{}`: lrgMerge would treat a `[]` as a list and replace a default map. It keeps
    dwemer:www-data 660 on the config.
  - Refuses a database that has no `lrg_*` tables. Without that guard, an empty `-t` list would make pg_dump dump
    the whole database.
  - Idempotent.
- **Undo**: `wsl -d DwemerAI4Skyrim3 -u root --cd / -- bash /mnt/c/Users/Jordan/Documents/LoreRimGlue/backups/reset-<stamp>/restore.sh`
  (the script prints the exact line).
- **Tested** on a private throwaway Postgres cluster (port 55432, CHIM `database_default.sql` + glue migrations
  001-005 + seeded rows). Both plan modes worked. The run cleared everything. The Narrator and
  `relationships_locked` were untouched, `_chim_history_source` stayed on history rows, and `{}` was preserved.
  A second run was a no-op. The restore gave **byte-identical** md5 of both NPC tables and the same row counts. The
  wrong-database guard fired. The wrapper also ran in plan mode from both paths. The owner's own database was
  never written: it was read for the research and was down during the tests. `bash -n` passes and the files have
  LF endings.

## 4. Owner page section (paste-ready)

> ### Starting your real playthrough: wipe the NPCs' memory of the test run
> 1. **Quit Skyrim.** Keep the DwemerDistro/CHIM server running. MO2 can stay open.
> 2. *(Optional, one click: a restore point inside CHIM.)* CHIM web UI (http://localhost:8081) -> **Control
>    Panel** -> **Playthrough Manager** tab -> "📦 Save Current Public Database": type `testing` in *Snapshot name*
>    -> **💾 Save Snapshot**.
> 3. Open PowerShell and run:
>    `powershell -ExecutionPolicy Bypass -File "C:\Users\Jordan\Documents\LoreRimGlue\reset_playthrough.ps1" -Yes -ChimToo`
>    It backs everything up first, then clears what NPCs remember about the test run: conversations, memories,
>    relationships, quest history and the glue's own notes. Voices, NPC profiles, settings and your CHIM setup
>    are kept. It also removes the Lisette test tweak.
> 4. Start **New Game**. On that first load CHIM also resets NPC profiles to the new timeline by itself. NPCs you
>    met in testing get a fresh profile when you meet them again. Lock an NPC in CHIM's NPC manager first
>    (lock icon, "Lock Profile") if you want to keep a hand-edited profile or voice.
> 5. Changed your mind? The script prints an undo command. Your test world is also in the Playthrough Manager
>    snapshot from step 2 ("Copy to Public").

## 5. Flags for the lead / other lanes

1. **Voice lane:** CHIM's `restoreNPC` on the new game's first load **deletes unlocked NPC rows** that have no
   history at or before the new start. Their `voiceid` goes with them. Set per-NPC voices **after** starting the
   new game, or lock those NPCs, or work at the voice-type/connector level, which survives. The reset script
   itself never touches voices.
2. **Server default:** `lrg_config.default.json` still ships `relationship.repair.enabled: true` (Lisette -> 26).
   The script overrides it in lrg_config.json. Flip the default to `false` in the next server release so a
   reinstall can't re-arm it. That file is not in this lane.
3. **The WSL distro was restarted by something else at 05:06 EDT.** Postgres shut down at 11:06:04 CEST and init
   uptime was 41 s. **Postgres and Apache are DOWN** and I did not start them. The owner must press Start in the
   DwemerDistro launcher (or the lane that restarted WSL should), before playing and before running the script.

## 6. Not done

- The reset itself was not run (by instruction). The live lrg_config.json is unchanged: Lisette `min_affinity -5`
  is still there, and the repair is still armed by the default.
- No CHIM Playthrough Manager snapshot was created by the script. It stays a one-click owner step, with the
  script's own backup as the safety net.
