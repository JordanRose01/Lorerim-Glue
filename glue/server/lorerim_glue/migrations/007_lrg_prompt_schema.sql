-- LoreRim Glue 0.5.0 - THE PROMPT INDEX GETS ITS OWN POSTGRES SCHEMA (plan section 9.1, verify report C9).
--
-- Why. CHIM's playthrough switch runs `DROP SCHEMA IF EXISTS public CASCADE; CREATE SCHEMA public` and then
-- clones a saved profile schema back in. chim_profile_default contains no Phase 2 table at all, so the
-- 37,561-row prompt index - which is LOAD-ORDER data, not playthrough data - was dropped on every
-- playthrough switch and cloned into every saved profile at 30 MB a copy. lrg_dialogue, lrg_memory,
-- lrg_npc_state, lrg_romance, lrg_scene_state and lrg_turn STAY in public: they really are playthrough
-- data and being cloned is the point.
--
-- Idempotent by design, like 005 and 006: this file may be run by lrgDlgEnsureSchema(), by
-- lrgPromptEnsureSchema() and again by CHIM's package manager. It is a MOVE, not a rebuild - a live
-- install with 37k rows in public keeps them, and nothing has to be re-loaded.
--
-- The statements are separated by ';' and run one at a time by the PHP runner, so the DO block below
-- must contain no bare ';' outside its quoted body... which it cannot avoid. It is therefore written as
-- ONE statement whose inner ';' characters live inside the $lrg$ dollar-quoted string, and the runner's
-- splitter is taught about dollar quoting (lrgPromptSqlStatements()).
--
-- {s} is the schema name, substituted by the runner from dialogue.index.schema (default lrg_index) -
-- NOT hard-coded, so the config key and this file can never name two different schemas, and so the
-- offline --db test can run the whole migration into a scratch database without touching the owner's.

CREATE SCHEMA IF NOT EXISTS {s};

DO $lrg$
BEGIN
  IF to_regclass('public.lrg_prompt') IS NOT NULL AND to_regclass('{s}.lrg_prompt') IS NULL THEN
    EXECUTE 'ALTER TABLE public.lrg_prompt SET SCHEMA {s}';
  END IF;
  IF to_regclass('public.lrg_prompt_layer') IS NOT NULL AND to_regclass('{s}.lrg_prompt_layer') IS NULL THEN
    EXECUTE 'ALTER TABLE public.lrg_prompt_layer SET SCHEMA {s}';
  END IF;
END
$lrg$;

-- [0.5.0 fix pass / S-5] `CREATE EXTENSION IF NOT EXISTS pg_trgm;` used to stand here and a
-- `USING gin (norm gin_trgm_ops)` index on {s}.lrg_prompt(norm) below it. Both are gone. The matcher is
-- pure PHP (lrgDlgMatchText, lib/lrg_dialogue.php) and NO query in lib/lrg_prompt_index.php uses
-- similarity(), <->, %> or any trigram operator, so the index could never be chosen by the planner.
-- It also created the one real casualty of a CHIM playthrough switch: because an extension installed
-- into `public` is dropped with the schema, `DROP SCHEMA public CASCADE` on a scratch copy printed
-- "drop cascades to extension pg_trgm / drop cascades to index {s}.lrg_prompt_norm_trgm" while the
-- 37,561 rows and 5,718 layers survived untouched. An existing install keeps its copies harmlessly
-- (IF NOT EXISTS never drops); they are simply never recreated.

CREATE TABLE IF NOT EXISTS {s}.lrg_prompt (
    info_key   text PRIMARY KEY,
    norm       text NOT NULL DEFAULT '',
    pattern    text NOT NULL DEFAULT '',
    txt        text NOT NULL DEFAULT '',
    topic_key  text NOT NULL DEFAULT '',
    topic      text NOT NULL DEFAULT '',
    quest      text NOT NULL DEFAULT '',
    journal    smallint NOT NULL DEFAULT 0,
    toplevel   smallint NOT NULL DEFAULT 0,
    kind       text NOT NULL DEFAULT '',
    topic_kind text NOT NULL DEFAULT '',
    variant    text NOT NULL DEFAULT 'na',
    fail_brawl smallint NOT NULL DEFAULT 0,
    fail_hard  smallint NOT NULL DEFAULT 0,
    unreachable smallint NOT NULL DEFAULT 0,
    flags      jsonb NOT NULL DEFAULT '{}'::jsonb,
    scripted   smallint NOT NULL DEFAULT 0,
    compound   smallint NOT NULL DEFAULT 0,
    amulet     smallint NOT NULL DEFAULT 0,
    twat       text NOT NULL DEFAULT '',
    crit       smallint NOT NULL DEFAULT 0,
    cost       integer NOT NULL DEFAULT 0,
    links      jsonb NOT NULL DEFAULT '[]'::jsonb,
    resp       text NOT NULL DEFAULT '',
    conds      jsonb NOT NULL DEFAULT '[]'::jsonb,
    subtype    text NOT NULL DEFAULT '',
    plugin     text NOT NULL DEFAULT '',
    shared     smallint NOT NULL DEFAULT 1
);
CREATE INDEX IF NOT EXISTS lrg_prompt_norm_idx ON {s}.lrg_prompt (norm);
CREATE INDEX IF NOT EXISTS lrg_prompt_topic_idx ON {s}.lrg_prompt (topic_key);
CREATE INDEX IF NOT EXISTS lrg_prompt_pattern_idx ON {s}.lrg_prompt (pattern) WHERE pattern <> '';

-- E2(b): "what can I ask you" falls back to the index BY QUEST when this NPC's list cache is empty.
-- Without this index that lookup is a sequential scan over 37k rows on a turn the player is waiting on.
CREATE INDEX IF NOT EXISTS lrg_prompt_quest_idx ON {s}.lrg_prompt (quest) WHERE quest <> '';

CREATE TABLE IF NOT EXISTS {s}.lrg_prompt_layer (
    fingerprint text PRIMARY KEY,
    parent_info text NOT NULL DEFAULT '',
    kind        text NOT NULL DEFAULT 'closed',
    n           smallint NOT NULL DEFAULT 0,
    norms       jsonb NOT NULL DEFAULT '[]'::jsonb
);
CREATE INDEX IF NOT EXISTS lrg_prompt_layer_norms ON {s}.lrg_prompt_layer USING gin (norms)
