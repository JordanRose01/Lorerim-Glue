-- LoreRim Glue 0.3.1 / R1c - the glue keeps its OWN copy of CHIM's affinity.
-- Why: NpcMaster::restoreNPC() runs on every game load (processor/comm.php:221) and
-- chimRelationshipRestoreQuery (lib/core/npc_master.class.php:176-233, called at :1520) rewrites
-- extended_data.relationships from the newest history row at or before the loaded game timestamp -
-- with NO lock_profile filter, and a history row that carries no 'relationships' key DELETES the live
-- entry outright. In playtest 7 that happened on four reloads in a row and the gate read aff=0 for
-- eighteen minutes. lrg_romance survived all of it, so this is where the last known good value lives.
-- Idempotent: this file may be run by the plugin and again by CHIM's package manager.
ALTER TABLE lrg_romance ADD COLUMN IF NOT EXISTS last_affinity integer NOT NULL DEFAULT 0;
ALTER TABLE lrg_romance ADD COLUMN IF NOT EXISTS last_affinity_at bigint NOT NULL DEFAULT 0
