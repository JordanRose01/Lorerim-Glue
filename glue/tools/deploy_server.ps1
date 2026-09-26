# LoreRim Glue - DEV deployment of the server plugin into HerikaServer (WSL).
# One channel per environment: this script is the dev channel. Do NOT also ship a
# Data/CHIM/server-plugins package while using it (CHIM's package sync compares version
# strings only and would overwrite a newer dev copy at game start).
# Safe to re-run. Never touches config/lrg_config.json (your overrides) or data/ (caches).
# The HerikaServer updater never runs "git clean" and ext/* is git-ignored, so the plugin
# survives server updates. It does not survive "uninstall ... PURGE-HERIKA".
param(
    [string]$Distro = "DwemerAI4Skyrim3",
    [string]$Target = "/var/www/html/HerikaServer/ext/lorerim_glue",
    [switch]$Remove
)
$ErrorActionPreference = "Stop"
$here = Split-Path -Parent $MyInvocation.MyCommand.Path
$source = Join-Path (Split-Path -Parent $here) "server\lorerim_glue"

if ($Remove) {
    wsl -d $Distro -u root --cd / -- bash -c "rm -rf '$Target' && echo removed $Target"
    "Tables lrg_* and the two action catalog rows (ExtCmdLRG_*) are left in place; drop them by hand if you want a clean slate."
    exit 0
}

# ---------------------------------------------------------------------------
# v0.4 ANCHOR PRE-FLIGHT (build plan 1.2 / 4.8).
# Every shared-file edit of this round is expressed as "the statement that reads <verbatim code>",
# never as a line number - the numbers drift with every commit of another lane (this round they
# drifted by ~130 lines while the plan was being written). A missing anchor means an edit landed
# somewhere else; TWO matches mean a line-numbered patch could silently pick the wrong one. Either
# way the deploy stops here and names the anchor, because the D-12 pass-through failing silently
# is exactly the bug this check exists to prevent.
# ---------------------------------------------------------------------------
$anchors = @(
    @{ f = 'lib\lrg_actions.php'; t = 'if (stripos($code, ''ExtCmdLRG_'') !== 0) { $out[] = $line; continue; }'; w = 'D-12 pass-through anchor' },
    @{ f = 'lib\lrg_actions.php'; t = 'if (!$turn || strcasecmp($actor, (string) $turn[''npc'']) !== 0 || !lrgEnabled() || defined(''LRG_SHARMAT_PRESENT'')) {'; w = 'the turn/SHARMAT drop the pass-through must precede' },
    # [0.4 integrator] both halves of D-12 are now IN lrg_actions.php, so the anchors moved from
    # "the statement the edit goes next to" to "the edited statement itself": a lost or duplicated
    # pass-through fails the deploy instead of dying silently in game.
    @{ f = 'lib\lrg_actions.php'; t = 'if (strcasecmp($code, ''ExtCmdLRG_SelectTopic'') === 0) { $out[] = $line; continue; }'; w = 'the D-12 pass-through itself' },
    @{ f = 'lib\lrg_actions.php'; t = 'if (stripos($raw, ''command@ExtCmdLRG_SelectTopic'') === 0) { lrgDlgTopicFuncret($raw); return; }'; w = 'lrgRecordResult guard, D-12b half (v1.0: the SelectTopic funcret is read by lrgDlgTopicFuncret, then dropped)' },
    @{ f = 'lib\lrg_actions.php'; t = 'lrgLog("gate: dropped unknown glue action $code", $cid);'; w = 'where an unrecognised glue action dies' },
    @{ f = 'globals.php'; t = '$GLOBALS[''external_fast_commands''] = array_values(array_unique(array_merge('; w = 'the fast-command merge' },
    @{ f = 'preprocessing.php'; t = 'if (strncmp($lrgType, ''lrg_'', 4) === 0'; w = 'the Phase 1 message block the Phase 2 block goes BEFORE' },
    @{ f = 'functions.php'; t = 'if (defined(''LRG_SHARMAT_PRESENT''))'; w = 'the SHARMAT branch the Phase 2 hooks must sit OUTSIDE' },
    @{ f = 'prerequest.php'; t = 'lrgPrerequest();'; w = 'where lrgDlgPrerequest() goes after' },
    # The Phase 2 edits themselves, so a lost or duplicated one is caught here rather than in game.
    # Each of these five statements IS the whole wiring of the menuless-questing module: without the
    # preprocessing one no list ever arrives, without the functions one no turn is ever built and no gate
    # ever runs, without the context_pre one the NPC is never told what her business is.
    @{ f = 'preprocessing.php'; t = 'if (lrgDlgHandleGameMessage($gameRequest) === ''handled'') { terminate(); }'; w = 'the Phase 2 fast-message handler' },
    @{ f = 'functions.php'; t = 'lrgDlgPrepareTurn();'; w = 'the Phase 2 turn, at brace depth 0 (outside BOTH SHARMAT branches)' },
    @{ f = 'functions.php'; t = '$GLOBALS[''action_post_process_fnct_ex''][] = $GLOBALS[''LRG_DLG_POSTGATE''];'; w = 'the Phase 2 post-gate, registered AFTER Phase 1''s' },
    @{ f = 'prerequest.php'; t = 'lrgDlgPrerequest();'; w = 'actions switched back on for lrg_dlgtalk' },
    @{ f = 'context_pre.php'; t = 'chimRegisterPromptInjection(''prompt_bottom'', ''lorerim_glue_business'''; w = 'the Phase 2 volatile injection' }
    # ---- v0.5 anchors. Each of these IS a whole feature's wiring; a lost or duplicated one dies silently.
    @{ f = 'lib\lrg_prompt_index.php'; t = 'function lrgPromptSchema(): string'; w = 'the index schema helper (E4/C9) - without it the index lives where a playthrough switch drops it' }
    @{ f = 'functions.php'; t = 'lrgDlgHoldMovementPolicy();'; w = 'the movement half of the conversation hold (E4b), LAST and at brace depth 0' }
    @{ f = 'context_pre.php'; t = 'chimRegisterPromptInjection(''character_bottom'', ''lorerim_glue_locked_facts'''; w = 'the locked-facts injection (E7), priority 205' }
    @{ f = 'lib\lrg_dialogue.php'; t = 'function lrgDlgServiceSlot'; w = 'the service slot matcher (E6) - the rule that stops a wrong teleport' }
    @{ f = 'lib\lrg_dialogue.php'; t = 'function lrgDlgTruthCheck'; w = 'the truth gate (E7)' }
    @{ f = 'lib\lrg_dialogue.php'; t = 'case ''lat'':'; w = 'the ev=lat intake (E8)' }
    # ---- v0.5.0 fix-pass anchors. All three are ONE-TOKEN edits whose loss is invisible in testing and
    # expensive in game, which is exactly what this pre-flight is for.
    @{ f = 'lib\lrg_dialogue.php'; t = '''min_priced'' => 0,'; w = 'R12: min_priced must be 0 - at 2 the slot guard is OFF on the vanilla carriage list, and a price question or a negation clicks a real destination' }
    @{ f = 'lib\lrg_dialogue.php'; t = '$menuless = (int) lrgDlgMcm(''ml'', 1, (string) $turn[''npc'']) === 1;'; w = 'the ml gate - without it the server hides CHIM''s RentRoom / HireCarriage / Training while bMenuless = 0 and the game then refuses the replacement' }
    @{ f = 'lib\lrg_dialogue.php'; t = 'lrgDlgMcm(''tg'', 1,'; w = 'bTruthGate on its OWN carrier - it used to read lf, so bLockedFacts off silently disabled the truth gate too' }
    # ---- v0.5.1 anchors (owner addendum 10, followers). Each of these IS a whole feature's wiring.
    @{ f = 'functions.php'; t = 'lrgFollowerPolicy();'; w = 'the follower hide rule - LAST of all and at brace depth 0, so it can only ever remove' }
    @{ f = 'context_pre.php'; t = 'chimRegisterPromptInjection(''character_bottom'', ''lorerim_glue_companion'''; w = 'the <companion_status> injection, priority 204' }
    @{ f = 'lib\lrg_core.php'; t = 'function lrgFolState'; w = 'the fol= reader - without it every follower fact on the wire is dropped in silence' }
    @{ f = 'lib\lrg_dialogue.php'; t = 'function lrgDlgFollowerArbitrate'; w = 'the follower verbs: the rule that stops "it''s time we parted ways" dismissing the WRONG follower' }
    @{ f = 'lib\lrg_dialogue.php'; t = ' - a follower topic that is never selectable by voice'')'; w = 'the hard rail: a favour-blocking / ANIMAL follower topic is never selectable by voice, however it was resolved (v1.0: lrgDlgNo on the gate; the fast path shares the rule)' }
    # ---- v0.5.2 anchor (PT9, audiofilterd). The whole auto-start of the feature is ONE line in
    # globals.php: lose it and the daemon is never started from a request, every spoken line keeps its
    # 0.25-0.31 s of dead air, and the only symptom is that nothing changed. That is exactly the shape
    # this pre-flight exists for.
    @{ f = 'globals.php'; t = 'lrgTtsEnsureAudiofilterd();'; w = 'the audiofilterd supervisor - without it the daemon never starts and every spoken line keeps its 0.3 s of dead air' }
    # ---- v0.5.5 anchors (owner addendum 11, NEVER SILENT). Each of these IS the whole wiring of one half: lose the
    # verdict and every result waits for the MAIN lock again and no failure is ever spoken; lose the cue and CHIM's
    # funcret turn runs on its default cue; lose the gate and a failure can be spoken by the wrong character; lose
    # the err= reader and game 507's refusals are classified on her words (adults-only / dry-run would be spoken).
    @{ f = 'lib\lrg_actions.php'; t = 'return lrgFuncretVerdict($text);'; w = 'the pre-lock verdict on every glue funcret (quiet success / voiced failure)' }
    @{ f = 'prompts.php'; t = 'lrgVoicedCue('; w = 'the cue of a voiced failure in CHIM''s funcret turn' }
    @{ f = 'context_pre.php'; t = 'lrgVoicedGate() !== '''') { terminate(); }'; w = 'the voiced-turn gate (right character, row with a follow-up)' }
    @{ f = 'lib\lrg_actions.php'; t = '$tech = $ok ? '''' : trim((string) ($kv[''err''] ?? ''''));'; w = 'the game-507 err= reader: every server match is on the technical reason' }
    # ---- v0.5.6 anchor (PT15, the player's own words). The whole repair is ONE line: lose it and an empty transcript is
    # dropped by CHIM again (the NPC never answers) and "Lazette" reaches the model - with nothing in any log.
    @{ f = 'preprocessing.php'; t = 'try { lrgSttRepairRequest(); }'; w = 'the STT repair (empty / short retry, name fix), before every other reader of the words' }
    # ---- pt18-quest anchors. Each IS the whole wiring of the click-free quest entry: lose the plan and a join by voice under ml=0
    # is words only again; lose the net call and a queued plan never reaches the game; lose the verdict branch and the game's OK is
    # never spoken (the one glue success that is voiced).
    @{ f = 'lib\lrg_factions.php'; t = 'function lrgFacQuestPlan'; w = 'the click-free quest entry plan (pt18-quest)' }
    @{ f = 'lib\lrg_actions.php'; t = '$out = lrgFacQuestNet($out); }'; w = 'the quest-entry net call in Phase 1''s post-gate (pt18-quest)' }
    @{ f = 'lib\lrg_actions.php'; t = '$qv = lrgQuestEntryResult($r);'; w = 'the quest-entry verdict branch: the voiced success / cleared facexec (pt18-quest)' }
    # ---- pt19-purchase anchors. Each IS the whole wiring of buying food and drink by voice: lose the plan and an order is words
    # only again; lose the net call and a queued order never reaches the game; lose the verdict branch and the game's refusal
    # ("it is 39 septims now, not 19", "he cannot pay") is never spoken and the cached price never refreshed.
    @{ f = 'lib\lrg_market.php'; t = 'function lrgMktPlan('; w = 'the buy plan (pt19-purchase)' }
    @{ f = 'lib\lrg_dialogue.php'; t = '$out = lrgMktNet($t, $out); }'; w = 'the buy net call in Phase 2''s post-gate, before the corner hint (pt19-purchase)' }
    @{ f = 'lib\lrg_actions.php'; t = '$bv = lrgMktResult($r);'; w = 'the buy verdict branch: the quiet OK / the voiced refusal (pt19-purchase)' }
)
$bad = @()
foreach ($a in $anchors) {
    $p = Join-Path $source $a.f
    if (-not (Test-Path -LiteralPath $p)) { $bad += "MISSING FILE $($a.f)"; continue }
    $raw = Get-Content -LiteralPath $p -Raw
    $n = ($raw -split [regex]::Escape($a.t)).Count - 1
    if ($n -ne 1) { $bad += "$($a.f): anchor matches $n times (want 1) - $($a.w): $($a.t)" }
}
if ($bad.Count) {
    "ANCHOR PRE-FLIGHT FAILED - nothing was deployed:"
    $bad | ForEach-Object { "  $_" }
    exit 1
}
"anchor pre-flight: $($anchors.Count) anchors, one match each"

# WSL cannot see every Windows path (the Claude app's session folder is virtualized), so
# stage through a short real path first.
$stage = Join-Path $env:TEMP "lrg_deploy"
robocopy $source $stage /MIR /NFL /NDL /NJH /NJS /NP /XD data /XF lrg_config.json | Out-Null
$stageWsl = "/mnt/" + $stage.Substring(0,1).ToLower() + ($stage.Substring(2) -replace '\\','/')

# The offline prompt-index builder lives in tools\ (Windows side) and runs on the python3 of the
# WSL distro. It is staged separately so it never lands inside the deployed plugin folder.
$indexPy = Join-Path $here "build_prompt_index.py"
$indexStage = Join-Path $env:TEMP "lrg_index"
$indexWsl = ""
if (Test-Path -LiteralPath $indexPy) {
    New-Item -ItemType Directory -Force $indexStage | Out-Null
    Copy-Item -LiteralPath $indexPy (Join-Path $indexStage "build_prompt_index.py") -Force
    $indexWsl = "/mnt/" + $indexStage.Substring(0,1).ToLower() + ($indexStage.Substring(2) -replace '\\','/') + "/build_prompt_index.py"
}

$cmd = @"
set -e
mkdir -p '$Target'
cp -r '$stageWsl/.' '$Target/'
mkdir -p '$Target/data'
find '$Target' -type f \( -name '*.php' -o -name '*.sql' -o -name '*.json' \) -exec sed -i 's/\r$//' {} +
chown -R dwemer:www-data '$Target'
chmod -R ug+rwX,o-rwx '$Target'
find '$Target' -type d -exec chmod g+s {} +
for f in `$(find '$Target' -name '*.php'); do php -l "`$f" > /dev/null || { echo "SYNTAX ERROR in `$f"; exit 1; }; done
# Scene index warm-up. The server never builds the index inside a request any more, so build it now (about 6 s).
# --force: the consent hard list lives in the library and is frozen into the cache at build time, so a deploy must
# rebuild unconditionally - a deploy is not a hot path. Run as the web user: it must be able to read the MO2 folder
# and to replace the cache later on its own. A failure is not fatal - the server retries from the next state message.
if id www-data > /dev/null 2>&1 && command -v runuser > /dev/null 2>&1; then
  runuser -u www-data -- php '$Target/lib/lrg_scene_index.php' warm --force || echo "WARNING: scene index warm-up failed - see HerikaServer/log/lorerim_glue.log"
else
  php '$Target/lib/lrg_scene_index.php' warm --force || echo "WARNING: scene index warm-up failed - see HerikaServer/log/lorerim_glue.log"
fi
# v0.4 prompt index (build plan 4.8). Both halves are lane C's and may not exist yet, so every
# step is guarded and a failure is a WARNING, never a failed deploy: the dialogue module treats a
# missing index as "unindexed", which costs a confirmation too many, never a wrong effect.
if [ -f '$indexWsl' ] && [ -f '$Target/lib/lrg_prompt_index.php' ]; then
  # --out is EXPLICIT: the builder's own default is relative to its own folder, and it is staged outside
  # the plugin. It is read-only over the MO2 tree and takes about 35 s over all 3,496 active plugins.
  # [0.5.0] --services writes data/service_catalog.json (the carriage / ferry / training / crime census
  # E6 reads instead of guessing) and --coverage writes data/prompt_coverage.csv. Both are second
  # accumulators over the same pass: no second scan, no extra run time.
  python3 '$indexWsl' --quiet --out '$Target/data/prompt_index.ndjson' --services --coverage || echo "WARNING: prompt index build failed - see HerikaServer/log/lorerim_glue.log"
  php '$Target/lib/lrg_prompt_index.php' ensure || echo "WARNING: migration 007 (the index schema) failed"
  php '$Target/lib/lrg_prompt_index.php' load '$Target/data/prompt_index.ndjson' || echo "WARNING: prompt index load failed"
  php '$Target/lib/lrg_prompt_index.php' status || true
elif [ -f '$Target/lib/lrg_prompt_index.php' ]; then
  echo "note: tools/build_prompt_index.py not found, the prompt index was not rebuilt"
fi
chown -R dwemer:www-data '$Target/data'
chmod -R ug+rwX,o-rwx '$Target/data'
# ---------------------------------------------------------------------------
# [0.5.0 / E4(a)] INDEX PRE-FLIGHT. It FAILS the deploy; it does not warn.
# The whole point of migration 007 is that the 37,561-row index stops living somewhere CHIM's
# playthrough switch can drop it. A deploy that leaves it unloaded, or loaded into the wrong schema,
# would run the whole feature "unindexed" - one confirmation too many on every single entry - and the
# only symptom is a quieter NPC. Better to stop here and say so.
IDX_SCHEMA=`$(php -r "require '$Target/lib/lrg_prompt_index.php'; echo lrgPromptSchema();" 2>/dev/null || echo lrg_index)
IDX_SCHEMA=`${IDX_SCHEMA:-lrg_index}
ROWS=`$(PGPASSWORD=dwemer psql -h localhost -U dwemer -d dwemer -tAc "select count(*) from `${IDX_SCHEMA}.lrg_prompt" 2>/dev/null | tr -d '[:space:]')
if [ -z "`$ROWS" ] || [ "`$ROWS" -le 0 ] 2>/dev/null; then
  echo "DEPLOY FAILED: `$IDX_SCHEMA.lrg_prompt is empty or unreadable (count='`$ROWS')."
  echo "  The prompt index is load-order data and the server needs it in its own schema (migration 007)."
  echo "  Fix: python3 tools/build_prompt_index.py --out <plugin>/data/prompt_index.ndjson --services --coverage"
  echo "       php <plugin>/lib/lrg_prompt_index.php ensure && php <plugin>/lib/lrg_prompt_index.php load"
  exit 1
fi
if ! php '$Target/lib/lrg_prompt_index.php' status | grep -q 'source=db'; then
  echo "DEPLOY FAILED: lrg_prompt_index.php status does not report source=db."
  exit 1
fi
if [ ! -s '$Target/data/service_catalog.json' ]; then
  echo "DEPLOY FAILED: data/service_catalog.json is missing or empty - the service census (E6) never ran,"
  echo "  and without it the glue would fall back to CHIM's 19 vanilla carriage destinations on a load"
  echo "  order that runs CFTO.esp. Re-run the builder with --services."
  exit 1
fi
grep -q 'CFTO.esp' '$Target/data/service_catalog.json' || echo "WARNING: the service census found no CFTO.esp - check the load order"
echo "index pre-flight: `$IDX_SCHEMA.lrg_prompt has `$ROWS rows, service catalog present"
echo deployed to $Target
"@
$bat = Join-Path $env:TEMP "lrg_deploy.sh"
[System.IO.File]::WriteAllText($bat, ($cmd -replace "`r`n", "`n"))
$batWsl = "/mnt/" + $bat.Substring(0,1).ToLower() + ($bat.Substring(2) -replace '\\','/')
wsl -d $Distro -u root --cd / -- bash $batWsl
