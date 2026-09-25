# LoreRim Glue - do the flow tests have teeth?
# Breaks the server plugin on purpose, one rail at a time, in a THROW-AWAY copy under %TEMP%, and expects the matching
# scenario to fail. Nothing in the project, in MO2 or inside the WSL distro's web root is touched.
#   powershell -NoProfile -ExecutionPolicy Bypass -File glue\tools\flows\mutation_check.ps1
# "MUTATION DID NOT APPLY" means the plugin line the sed pattern aimed at has changed: update the pattern below (they are
# tied to the exact source text on purpose - a mutation that silently does nothing would prove nothing).
param(
    [string]$Glue = (Resolve-Path (Join-Path $PSScriptRoot "..\..")).Path,
    [string]$Distro = "DwemerAI4Skyrim3"
)
$mut = Join-Path $env:TEMP "lrg_test_mut"
$w = "/mnt/" + $mut.Substring(0, 1).ToLower() + $mut.Substring(2).Replace("\", "/")
# the sed programs live in a bash file so that PowerShell quoting cannot mangle them
$sh = @'
#!/bin/bash
# usage: mutate.sh <staged glue dir> <mutation id>
P="$1/server/lorerim_glue"
case "$2" in
 M1) sed -i "s/if ((\$state\['adult'\] ?? '0') !== '1')  { \$res\['reasons'\]\[\] = 'not_adult'; }/if (false) { }/" "$P/lib/lrg_core.php" ;;
 M2) sed -i "s/if ((\$state\['witkid'\] ?? '1') !== '0') { \$res\['reasons'\]\[\] = 'child_nearby'; }/if (false) { }/" "$P/lib/lrg_core.php" ;;
 M3) sed -i "s/min(4, \$max + (\$open ? 1 : 0))/min(4, \$max + (\$open ? 2 : 0))/" "$P/lib/lrg_core.php" ;;
 M4) sed -i "s/\&\& preg_match('\/\\\\b(stop|stopping|enough|halt|quit)\\\\b/\&\& preg_match('\/^(stop|stopping|enough|halt|quit)\$/" "$P/lib/lrg_actions.php" ;;
 M5) sed -i "s/'followup' => \['enabled' => false/'followup' => ['enabled' => true/" "$P/lib/lrg_actions.php" ;;
 M6) sed -i "s/((\$announce \&\& lrgIsSayIt((string) \$o\['tier'\])) ? ' \[say it\]' : ' \[silent\]')/' [say it]'/" "$P/lib/lrg_actions.php" ;;
 M7) sed -i "s/if (!\$willing || lrgNow() > (int) (\$inv\['expires'\] ?? 0)) {/if (!\$willing) {/" "$P/lib/lrg_core.php" ;;
 M8) sed -i "s/if ((int) (\$state\['wit'\] ?? 99) > \$maxWit) { \$res\['reasons'\]\[\] = 'witnesses'; }/if (false) { }/" "$P/lib/lrg_core.php" ;;
 M9) sed -i "s/if (lrgRoll('initiative') > \$chance) { return \$no(\"dice (\$pace)\"); }/if (false) { }/" "$P/lib/lrg_actions.php" ;;
 M10) sed -i "s/if (\$since < (int) (\$cfg\['cooldown_seconds'\] ?? 300)) { return \$no('cooling down'); }/if (false) { }/" "$P/lib/lrg_actions.php" ;;
 M11) sed -i "s/if (\$inSceneWithThisNpc \&\& !\$adultKnown) {/if (false) {/" "$P/lib/lrg_actions.php" ;;
 M16) sed -i "s/&& empty(lrgBuildProfile(\$npc, \$state)\['never'\]);/;/" "$P/lib/lrg_actions.php" ;;
 M17) sed -i "s/&& (\$state\['witkid'\] ?? '1') === '0'//" "$P/lib/lrg_actions.php" ;;
 M18) sed -i "s/\$sceneOk = (int) (\$state\['_age'\] ?? 9999) <= \$maxAge/\$sceneOk = true \&\& (int) (\$state['_age'] ?? 9999) <= 999999/" "$P/lib/lrg_actions.php" ;;
 M19) sed -i "s/&& (\$state\['on'\] ?? '0') === '1'//" "$P/lib/lrg_actions.php" ;;
 M12) sed -i "s/        if (in_array(\$mode, \['public', 'private', 'follow'\], true)) {/        if (true) {/" "$P/lib/lrg_actions.php" ;;
 M13) sed -i "s/if (\$mode !== 'public' || empty(\$turn\['places'\])) {/if (false) {/" "$P/lib/lrg_actions.php" ;;
 M14) sed -i "s/if (defined('LRG_SHARMAT_PRESENT')) {/if (false) {/" "$P/functions.php"; sed -i "s/ \&\& !defined('LRG_SHARMAT_PRESENT')//g" "$P/context_pre.php"; sed -i "s/|| defined('LRG_SHARMAT_PRESENT')//g" "$P/lib/lrg_actions.php" "$P/lib/lrg_core.php" ;;
 M15) sed -i "s/'pending') ? 'follow' : 'private'/'pending') ? 'private' : 'private'/" "$P/lib/lrg_core.php" ;;
esac
'@
$mutations = @(
    @{ id = "M1";  n = "adults-only rail removed (gate)";        only = "05" },
    @{ id = "M2";  n = "child-nearby rail removed";              only = "05" },
    @{ id = "M3";  n = "ladder climbs two steps";                only = "07" },
    @{ id = "M4";  n = "stop loses its priority";                only = "10" },
    @{ id = "M5";  n = "follow-up switched back on";             only = "13" },
    @{ id = "M6";  n = "every option marked say-it";             only = "14" },
    @{ id = "M7";  n = "invitations never expire";               only = "02,03" },
    @{ id = "M8";  n = "witnesses ignored";                      only = "02" },
    @{ id = "M9";  n = "initiative dice ignored";                only = "15" },
    @{ id = "M10"; n = "initiative cooldown ignored";            only = "15" },
    @{ id = "M11"; n = "adults-only rail removed (live scene)";  only = "05" },
    @{ id = "M12"; n = "explicit level set in every mode";       only = "01,04,06" },
    @{ id = "M13"; n = "invite recorded in any mode";            only = "01,12" },
    @{ id = "M14"; n = "SHARMAT guard removed everywhere";       only = "11b,11d" },
    @{ id = "M15"; n = "follow-through never happens";           only = "03" },
    @{ id = "M16"; n = "profile opt-out ignored inside a scene"; only = "05" },
    @{ id = "M17"; n = "child nearby ignored inside a scene";    only = "05" },
    @{ id = "M18"; n = "stale snapshot trusted inside a scene";  only = "17" },
    @{ id = "M19"; n = "MCM intimacy switch ignored in a scene"; only = "05" }
)
$detected = 0; $stale = 0
foreach ($m in $mutations) {
    robocopy $Glue $mut /MIR /NFL /NDL /NJH /NJS /NP /XD data | Out-Null   # /XD data: the scene index built by the first run survives the next mirror
    [IO.File]::WriteAllText((Join-Path $mut "mutate.sh"), $sh.Replace("`r`n", "`n"))
    $files = Get-ChildItem (Join-Path $mut "server\lorerim_glue") -Recurse -Filter *.php
    $before = ($files | Get-FileHash | ForEach-Object Hash) -join ","
    wsl -d $Distro --cd / -- bash "$w/mutate.sh" $w $m.id
    $after = ($files | Get-FileHash | ForEach-Object Hash) -join ","
    if ($before -eq $after) { $stale++; Write-Output "$($m.id) $($m.n): MUTATION DID NOT APPLY (the plugin line changed - update the pattern)"; continue }
    $out = wsl -d $Distro --cd / -- php "$w/tools/flows/run_flows.php" "--only=$($m.only)" "--strict" 2>&1
    $code = $LASTEXITCODE
    $fails = ($out | Select-String -Pattern "^\s+FAIL  " | Measure-Object).Count
    if ($code -ne 0) { $detected++ }
    Write-Output "$($m.id) $($m.n): scenarios $($m.only) -> exit $code, $fails failing checks  $(if ($code -ne 0) { 'DETECTED' } else { 'NOT DETECTED <<<' })"
    $out | Select-String -Pattern "^\s+FAIL  " | Select-Object -First 2 | ForEach-Object { "      " + $_.Line.Trim().Substring(0, [Math]::Min(180, $_.Line.Trim().Length)) }
}
Write-Output "$detected of $($mutations.Count) mutations detected"
Write-Output "STALE: $stale (a stale pattern proves nothing - update it, never delete the mutation)"
Remove-Item -Recurse -Force $mut
if ($detected -lt $mutations.Count) { exit 1 }
