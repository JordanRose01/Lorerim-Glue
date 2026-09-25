-- LoreRim Glue 0.4.0 - the offline PROMPT INDEX (design 4.2 / plan 5.1).
-- Idempotent by design: this file may be run by lrgPromptEnsureSchema(), by lrgDlgEnsureSchema() and again
-- by CHIM's package manager. Never add a statement here that fails when it has already been applied.
-- Numbered 006 (not the design's 004): Phase 1 already ships 003_lrg_romance_affinity.sql and the
-- INTIMACY lane owns the 004_* namespace this round, so 005/006 keep lrgEnsureSchema()'s name-order
-- run unambiguous.
--
-- The index is ADVISORY. Every row is a claim ABOUT a prompt; execution is always "click the live entry".
CREATE EXTENSION IF NOT EXISTS pg_trgm;

CREATE TABLE IF NOT EXISTS lrg_prompt (
    info_key   text PRIMARY KEY,          -- <origin master>:<24-bit id> of the INFO
    norm       text NOT NULL DEFAULT '',  -- lrgPromptNorm() of the player prompt: THE match key
    pattern    text NOT NULL DEFAULT '',  -- token prompts only: the same with <Token> as '*'
    txt        text NOT NULL DEFAULT '',  -- the raw prompt, 160 chars, for diagnostics only
    topic_key  text NOT NULL DEFAULT '',
    topic      text NOT NULL DEFAULT '',  -- DIAL EditorID
    quest      text NOT NULL DEFAULT '',  -- QUST EditorID
    journal    smallint NOT NULL DEFAULT 0,   -- the quest shows objectives
    toplevel   smallint NOT NULL DEFAULT 0,   -- the topic is no INFO's TCLT target
    kind       text NOT NULL DEFAULT '',      -- persuade | intimidate | bribe | ''  (BY CONDITION FUNCTION)
    topic_kind text NOT NULL DEFAULT '',      -- the kind of the TOPIC, on every INFO of it (scoff-first)
    variant    text NOT NULL DEFAULT 'na',    -- success | failure | na
    fail_brawl smallint NOT NULL DEFAULT 0,   -- the failure variant of this topic ends in (Brawl)
    fail_hard  smallint NOT NULL DEFAULT 0,   -- that failure INFO is scripted or Goodbye
    unreachable smallint NOT NULL DEFAULT 0,  -- PNAM inversion: an always-passing sibling sorts first
    flags      jsonb NOT NULL DEFAULT '{}'::jsonb,   -- goodbye sayonce walkaway invis random favor placeholder
    scripted   smallint NOT NULL DEFAULT 0,
    compound   smallint NOT NULL DEFAULT 0,   -- a weapon skill / level / race / perk passes INSTEAD of the check
    amulet     smallint NOT NULL DEFAULT 0,   -- the Amulet-of-Articulation OR-branch (not 'compound')
    twat       text NOT NULL DEFAULT '',      -- walk-away target EditorID
    crit       smallint NOT NULL DEFAULT 0,   -- 0 none | 1 COSTLY | 2 LETHAL (DGCrimeResistArrest)
    cost       integer NOT NULL DEFAULT 0,    -- >0 literal price, -1 the price is a token, 0 none
    links      jsonb NOT NULL DEFAULT '[]'::jsonb,
    resp       text NOT NULL DEFAULT '',      -- the NPC's response, DNAM shared responses resolved
    conds      jsonb NOT NULL DEFAULT '[]'::jsonb,   -- full condition list, check / walk-away rows only
    subtype    text NOT NULL DEFAULT '',
    plugin     text NOT NULL DEFAULT '',
    shared     smallint NOT NULL DEFAULT 1    -- how many distinct topics carry this exact norm (rank rule)
);
CREATE INDEX IF NOT EXISTS lrg_prompt_norm_idx ON lrg_prompt (norm);
CREATE INDEX IF NOT EXISTS lrg_prompt_norm_trgm ON lrg_prompt USING gin (norm gin_trgm_ops);
CREATE INDEX IF NOT EXISTS lrg_prompt_topic_idx ON lrg_prompt (topic_key);
CREATE INDEX IF NOT EXISTS lrg_prompt_pattern_idx ON lrg_prompt (pattern) WHERE pattern <> '';

-- One row per known closed layer: the set of prompt norms its parent INFO leads to. A live list is matched
-- against these subset-tolerantly, which is what disambiguates a prompt several topics share.
CREATE TABLE IF NOT EXISTS lrg_prompt_layer (
    fingerprint text PRIMARY KEY,
    parent_info text NOT NULL DEFAULT '',
    kind        text NOT NULL DEFAULT 'closed',
    n           smallint NOT NULL DEFAULT 0,
    norms       jsonb NOT NULL DEFAULT '[]'::jsonb
);
CREATE INDEX IF NOT EXISTS lrg_prompt_layer_norms ON lrg_prompt_layer USING gin (norms)
