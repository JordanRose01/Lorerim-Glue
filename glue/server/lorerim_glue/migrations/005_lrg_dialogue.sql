-- LoreRim Glue 0.4.0 - Phase 2 per-NPC dialogue state (design 3.3).
-- Same shape as lrg_memory: npc_name PK, payload jsonb, updated_at - so every read is
-- "... FROM lrg_dialogue WHERE npc_name=<escapeLiteral(name)>" and the offline flow tests' in-memory
-- fake can serve it (PROTOCOL section 5).
-- Numbered 005 (not the design's 003): Phase 1 already ships 003_lrg_romance_affinity.sql and the
-- INTIMACY lane owns 004_* this round. 005/006 keep lrgEnsureSchema()'s name-order run unambiguous.
--
-- payload keys (LRG_DLG_STATE_VERSION 1):
--   ref vt q[] sg{} facts{}          what the game last said about this NPC
--   root    {sid, gen, at, loc, qsig, entries[]}         the last ROOT list (the cache)
--   session {sid, origin, crit, state, layer, gen, at, scene, entries[], path[], asked_line}
--   offer   {turn_cid, keys{T1: idx}, at}                what THIS turn's prompt showed
--   parked  {norm, text, kind, at, expires, req}         the two-step confirmation
--   attempts{<norm>: {kind, result, at, sp, gold, wis, bamt}}
--   last_exec {x, cid, at, text, kind, entry, told}      last emitted decision
--   last_result {...}                                    the composed ground truth for the next turn
--   utter   {text, at, type}                             the player's last words for this NPC
--   checks  {<key>: {kind, result, at, sp, wis, pg, tries}}   free-conversation check memory (plan 6.10)
--   qobj    {<quest>: {stage, obj[]}}                    quest-tree facts (plan 7.2)
--   price   {ask, paid, at, times}                       "everyone has a price" memory (plan 8.3)
CREATE TABLE IF NOT EXISTS lrg_dialogue (
    npc_name   text PRIMARY KEY,
    payload    jsonb NOT NULL DEFAULT '{}'::jsonb,
    updated_at bigint NOT NULL DEFAULT 0
)
