-- LoreRim Glue - idempotent by design: this file may be run by the plugin itself (dev
-- channel) and again by CHIM's package manager (release channel). Never add a statement
-- here that fails when it has already been applied.
CREATE TABLE IF NOT EXISTS lrg_npc_state (
    npc_name   text PRIMARY KEY,
    payload    jsonb NOT NULL DEFAULT '{}'::jsonb,
    updated_at bigint NOT NULL DEFAULT 0
);
CREATE TABLE IF NOT EXISTS lrg_scene_state (
    npc_name   text PRIMARY KEY,
    payload    jsonb NOT NULL DEFAULT '{}'::jsonb,
    active     integer NOT NULL DEFAULT 0,
    updated_at bigint NOT NULL DEFAULT 0
);
CREATE TABLE IF NOT EXISTS lrg_romance (
    npc_name        text PRIMARY KEY,
    stage           integer NOT NULL DEFAULT 0,
    scenes          integer NOT NULL DEFAULT 0,
    refusals        integer NOT NULL DEFAULT 0,
    gold_accepted   integer NOT NULL DEFAULT 0,
    last_scene_at   bigint NOT NULL DEFAULT 0,
    last_refusal_at bigint NOT NULL DEFAULT 0
);
CREATE TABLE IF NOT EXISTS lrg_turn (
    npc_name   text PRIMARY KEY,
    payload    jsonb NOT NULL DEFAULT '{}'::jsonb,
    updated_at bigint NOT NULL DEFAULT 0
)
