-- LoreRim Glue 0.2.0 - per-NPC memory of the glue itself (PROTOCOL.md section 5). Idempotent, like 001.
-- payload keys: interest, interest_score, invite {at, expires, place, loc, state}, initiative_at,
--               last_result {cmd, do, ok, reason, at, told}, lead_idle
CREATE TABLE IF NOT EXISTS lrg_memory (
    npc_name   text PRIMARY KEY,
    payload    jsonb NOT NULL DEFAULT '{}'::jsonb,
    updated_at bigint NOT NULL DEFAULT 0
)
