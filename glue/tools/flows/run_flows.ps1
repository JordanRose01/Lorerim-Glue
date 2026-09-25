# LoreRim Glue - one command from Windows: stage glue\ where the WSL distro can see it, lint the flow-test files, run them.
#   powershell -NoProfile -ExecutionPolicy Bypass -File glue\tools\flows\run_flows.ps1                  (everything)
#   powershell -NoProfile -ExecutionPolicy Bypass -File glue\tools\flows\run_flows.ps1 "--only=03,07 --trace"
#   powershell -NoProfile -ExecutionPolicy Bypass -File glue\tools\flows\run_flows.ps1 "--strict"       (release gate: pending / warnings fail too)
# Stages to %TEMP%\lrg_test_flows - its OWN folder, not %TEMP%\lrg_test: the kill-switch variant writes a config override
# into the staged plugin for a few seconds, and tools\test_gates.php does the same in its folder; sharing one would make both flaky.
# Nothing in the project, in MO2 or inside the distro's web root is written. Exit code = exit code of run_flows.php.
param(
    [string]$FlowArgs = "",
    [string]$Glue = (Resolve-Path (Join-Path $PSScriptRoot "..\..")).Path,
    [string]$Distro = "DwemerAI4Skyrim3"
)
$stage = Join-Path $env:TEMP "lrg_test_flows"
robocopy $Glue $stage /MIR /NFL /NDL /NJH /NJS /NP /XD data | Out-Null   # /XD data keeps the staged scene index between runs
$w = "/mnt/" + $stage.Substring(0, 1).ToLower() + $stage.Substring(2).Replace("\", "/")
$lintFailed = $false
foreach ($f in Get-ChildItem (Join-Path $Glue "tools\flows") -Recurse -Filter *.php) {
    $rel = $f.FullName.Substring((Join-Path $Glue "tools\flows").Length + 1).Replace("\", "/")
    $lint = wsl -d $Distro --cd / -- php -l "$w/tools/flows/$rel" 2>&1
    if ($LASTEXITCODE -ne 0) { $lintFailed = $true; Write-Output "LINT FAIL $rel"; Write-Output $lint }
}
if ($lintFailed) { exit 2 }
if ($FlowArgs -eq "") { wsl -d $Distro --cd / -- php "$w/tools/flows/run_flows.php" }
else { $a = $FlowArgs.Split(" ", [StringSplitOptions]::RemoveEmptyEntries); wsl -d $Distro --cd / -- php "$w/tools/flows/run_flows.php" @a }
exit $LASTEXITCODE
