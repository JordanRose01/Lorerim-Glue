#!/bin/bash
# LoreRim Glue - NEW PLAYTHROUGH RESET, the WSL half. Called by reset_playthrough.ps1 (which stages an
# LF copy); by hand:  bash reset_playthrough.sh <plan|run> <backup dir, WSL path> <chim 0|1> <keeplog 0|1>
#   plan = count and describe only, change nothing.   run = back up FIRST, then clear.
#
# What it clears (research/pt15-memory-reset.md has the why):
#   always  - the glue's own per-NPC playthrough state in public (lrg_*), NOT lrg_index.* (load-order data)
#           - lrg_config.json: npc_overrides.Lisette.min_affinity (a test value) and relationship.repair
#             (the one-shot Lisette repair; with lrg_memory empty it would fire again in the new game)
#           - moves lorerim_glue.log into the backup (keeplog=1 leaves it)
#   chim=1  - CHIM's playthrough memory with CHIM's OWN statements: its 'wipe' message list, its 'init'
#             prune list taken whole, the quest journal history, NPC diaries, relationship queues and
#             the relationship keys (same keys as chimRelationshipFutureClearQuery) on every NPC and on
#             every history snapshot. NPC profiles, voices, settings, connectors, actions, prompts,
#             Oghma, templates and the Playthrough Manager snapshots are never touched.
set -u
MODE="${1:-plan}"; BK="${2:-}"; CHIM="${3:-0}"; KEEPLOG="${4:-0}"
H=/var/www/html/HerikaServer
# LRG_RESET_DB / _CFG / _LOG exist only so the offline test can run the whole thing on a scratch copy.
CFG=${LRG_RESET_CFG:-$H/ext/lorerim_glue/config/lrg_config.json}
LOG=${LRG_RESET_LOG:-$H/log/lorerim_glue.log}
DBN=${LRG_RESET_DB:-dwemer}
export PGPASSWORD=dwemer
DBA=(-h localhost -U dwemer -d "$DBN")
q() { psql "${DBA[@]}" -X -At -v ON_ERROR_STOP=1 -c "$1"; }
fail() { echo "FAILED: $*"; exit 1; }

GLUE="lrg_npc_state lrg_scene_state lrg_romance lrg_turn lrg_memory lrg_dialogue"
CHIM_WIPE="eventlog quests speech currentmission diarylog books memory_summary memory"
CHIM_INIT="responselog rolemaster actions_issued moods_issued rumors named_cell sneq_quests_saved bgl_history"
CHIM_MORE="questlog physical_npc_diaries relationship_eval_queue relationship_init_queue log"
KEYS="array['relationships','relationships_analyzed','relationships_inferred','relationships_last_eval','relationships_model','relationships_updated']"
STRIP="- 'relationships' - 'relationships_analyzed' - 'relationships_inferred' - 'relationships_last_eval' - 'relationships_model' - 'relationships_updated'"

q "select 1" >/dev/null || fail "cannot reach the CHIM database (is the DwemerDistro running?)"
present() { local out=""; for t in $1; do [ "$(q "select to_regclass('public.$t') is not null")" = "t" ] && out="$out $t"; done; echo $out; }
G=$(present "$GLUE")
C=""; [ "$CHIM" = "1" ] && C=$(present "$CHIM_WIPE $CHIM_INIT $CHIM_MORE")
# An empty list would make pg_dump dump the WHOLE database and the clear a no-op: refuse instead.
[ -n "$G" ] || fail "no lrg_* table in public of database $DBN - wrong database?"
[ "$CHIM" != "1" ] || [ -n "$C" ] || fail "no CHIM memory table in public of database $DBN - wrong database?"

# The config edit, as PHP so {} stays {} (lrgMerge treats [] as a list and would REPLACE a default map).
cfgphp() {
php -r '
$f = $argv[1]; $write = $argv[2] === "1";
$j = is_file($f) ? json_decode((string) file_get_contents($f)) : new stdClass();
if (!is_object($j)) { fwrite(STDERR, "lrg_config.json is not valid JSON - not touched\n"); exit(2); }
$did = [];
if (isset($j->npc_overrides->Lisette) && property_exists($j->npc_overrides->Lisette, "min_affinity")) {
    unset($j->npc_overrides->Lisette->min_affinity); $did[] = "remove npc_overrides.Lisette.min_affinity";
    if (count(get_object_vars($j->npc_overrides->Lisette)) === 0) { unset($j->npc_overrides->Lisette); }
    if (count(get_object_vars($j->npc_overrides)) === 0) { unset($j->npc_overrides); }
}
if (!isset($j->relationship) || !is_object($j->relationship)) { $j->relationship = new stdClass(); }
if (!isset($j->relationship->repair) || !is_object($j->relationship->repair)) { $j->relationship->repair = new stdClass(); }
if (($j->relationship->repair->enabled ?? null) !== false) { $j->relationship->repair->enabled = false; $did[] = "set relationship.repair.enabled=false"; }
echo $did ? "  lrg_config.json: " . implode("; ", $did) . "\n" : "  lrg_config.json: already clean\n";
if ($write && $did) {
    $out = json_encode($j, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    if (file_put_contents($f, $out) === false) { fwrite(STDERR, "cannot write $f\n"); exit(3); }
}' "$CFG" "$1"
}

report() {
    echo "Glue state (public):"
    for t in $G; do echo "  $t: $(q "select count(*) from public.$t") rows"; done
    if [ "$CHIM" = "1" ]; then
        echo "CHIM playthrough memory (public):"
        for t in $C; do echo "  $t: $(q "select count(*) from public.$t") rows"; done
        echo "  core_npc_master: $(q "select count(*) from public.core_npc_master where npc_name<>'The Narrator' and extended_data ?| $KEYS") NPCs carry relationship data"
        echo "  core_npc_master_history: $(q "select count(*) from public.core_npc_master_history where npc_name<>'The Narrator' and extended_data ?| $KEYS") snapshots carry relationship data"
    fi
}

echo "=== LoreRim Glue playthrough reset ($MODE, chim=$CHIM, database $DBN) ==="
report
if [ "$MODE" != "run" ]; then
    echo "Would change:"
    cfgphp 0 || true
    [ "$KEEPLOG" = "1" ] || { [ -f "$LOG" ] && echo "  lorerim_glue.log ($(du -h "$LOG" | cut -f1)) moved into the backup"; }
    echo "Kept: lrg_index.* (prompt index), ext/lorerim_glue/data (scene index, catalogs), MCM settings."
    [ "$CHIM" = "1" ] || echo "CHIM memory NOT included (add -ChimToo)."
    echo "PLAN ONLY - nothing was changed."
    exit 0
fi

# ---------------- run: back up first, stop on any failure before a single row is touched
[ -n "$BK" ] || fail "no backup directory given"
mkdir -p "$BK" || fail "cannot create $BK"
args=(); for t in $G; do args+=(-t "public.$t"); done
pg_dump "${DBA[@]}" --data-only "${args[@]}" -f "$BK/glue_tables.sql" || fail "pg_dump of the glue tables"
[ -s "$BK/glue_tables.sql" ] || fail "glue backup is empty"
if [ -f "$CFG" ]; then cp "$CFG" "$BK/lrg_config.json" || fail "config backup"; fi
if [ "$CHIM" = "1" ]; then
    args=(); for t in $C; do args+=(-t "public.$t"); done
    pg_dump "${DBA[@]}" --data-only "${args[@]}" -f "$BK/chim_tables.sql" || fail "pg_dump of the CHIM tables"
    [ -s "$BK/chim_tables.sql" ] || fail "CHIM backup is empty"
    psql "${DBA[@]}" -X -v ON_ERROR_STOP=1 -c "\\copy (select id, extended_data from public.core_npc_master where extended_data ?| $KEYS) to '$BK/npc_extended_data.csv' with (format csv)" >/dev/null || fail "NPC relationship backup"
    psql "${DBA[@]}" -X -v ON_ERROR_STOP=1 -c "\\copy (select history_id, extended_data from public.core_npc_master_history where extended_data ?| $KEYS) to '$BK/npc_history_extended_data.csv' with (format csv)" >/dev/null || fail "NPC history backup"
fi
echo "Backup written: $BK"

# restore.sh - puts every row and the config back exactly as they were at backup time.
{
    echo '#!/bin/bash'
    echo "# Undo the playthrough reset of $(date '+%Y-%m-%d %H:%M'). Run with Skyrim closed."
    echo 'set -e; D="$(cd "$(dirname "$0")" && pwd)"; export PGPASSWORD=dwemer'
    echo "P=\"psql -h localhost -U dwemer -d $DBN -X -v ON_ERROR_STOP=1\""
    echo "{ echo 'BEGIN;'; for t in $G; do echo \"DELETE FROM public.\$t;\"; done; cat \"\$D/glue_tables.sql\"; echo 'COMMIT;'; } | \$P -q >/dev/null"
    if [ "$CHIM" = "1" ]; then
        echo "{ echo 'BEGIN;'; for t in $C; do echo \"DELETE FROM public.\$t;\"; done; cat \"\$D/chim_tables.sql\"; echo 'COMMIT;'; } | \$P -q >/dev/null"
        echo "\$P -q -c 'CREATE TEMP TABLE r (id bigint, x jsonb)' -c \"\\\\copy r from '\$D/npc_extended_data.csv' with (format csv)\" -c 'UPDATE public.core_npc_master c SET extended_data = r.x FROM r WHERE c.id = r.id'"
        echo "\$P -q -c 'CREATE TEMP TABLE r (id bigint, x jsonb)' -c \"\\\\copy r from '\$D/npc_history_extended_data.csv' with (format csv)\" -c 'UPDATE public.core_npc_master_history c SET extended_data = r.x FROM r WHERE c.history_id = r.id'"
    fi
    echo "[ -f \"\$D/lrg_config.json\" ] && cat \"\$D/lrg_config.json\" > '$CFG'"
    echo 'echo "restored from $D"'
} > "$BK/restore.sh"

# ---------------- clear: ONE transaction, all or nothing
{
    echo "BEGIN;"
    for t in $G; do echo "DELETE FROM public.$t;"; done
    if [ "$CHIM" = "1" ]; then
        for t in $C; do echo "DELETE FROM public.$t;"; done
        echo "UPDATE public.core_npc_master SET extended_data = extended_data $STRIP - '_chim_history_source' WHERE npc_name <> 'The Narrator' AND extended_data ?| $KEYS;"
        echo "UPDATE public.core_npc_master_history SET extended_data = extended_data $STRIP WHERE npc_name <> 'The Narrator' AND extended_data ?| $KEYS;"
    fi
    echo "COMMIT;"
} | psql "${DBA[@]}" -X -q -v ON_ERROR_STOP=1 >/dev/null || fail "clear rolled back - NOTHING was deleted (backup is in $BK)"

cfgphp 1 || fail "database cleared, but lrg_config.json was not edited - remove npc_overrides.Lisette.min_affinity and set relationship.repair.enabled=false by hand"
# same owner and mode the deploy leaves (the web server reads it through the www-data group)
if [ -z "${LRG_RESET_CFG:-}" ]; then chown dwemer:www-data "$CFG" 2>/dev/null; chmod 660 "$CFG" 2>/dev/null; fi
if [ "$KEEPLOG" != "1" ] && [ -f "$LOG" ]; then mv "$LOG" "$BK/lorerim_glue.log" && echo "  lorerim_glue.log moved into the backup"; fi

echo "After:"
report
echo "DONE."
