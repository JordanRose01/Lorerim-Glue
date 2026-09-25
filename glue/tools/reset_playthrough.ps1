# LoreRim Glue - reset NPC memory for a NEW PLAYTHROUGH. Backs up first, then clears.
#
#   (no switch)  -> PLAN ONLY: shows what is there and what would change. Nothing is touched.
#   -WhatIf      -> same as no switch.
#   -Yes         -> really do it: back up, then clear the glue's own per-NPC state and fix lrg_config.json
#                   (removes the Lisette test min_affinity, disables the one-shot Lisette repair).
#   -ChimToo     -> ALSO clear CHIM's playthrough memory (events, speech, memories, diaries, quest
#                   journal history, NPC relationships) with CHIM's own wipe statements. NPC profiles,
#                   voices, settings, connectors, actions and Playthrough Manager snapshots are kept.
#   -KeepLog     -> leave lorerim_glue.log where it is (default: it is moved into the backup).
#
# Run it with SKYRIM CLOSED, right before you start the new game. MO2 may stay open (nothing of
# MO2's is touched). The backup folder gets a restore.sh that puts everything back.
param(
    [switch]$Yes,
    [switch]$WhatIf,
    [switch]$ChimToo,
    [switch]$KeepLog,
    [string]$Distro = "DwemerAI4Skyrim3",
    [string]$BackupRoot = (Join-Path $env:USERPROFILE "Documents\LoreRimGlue\backups")
)
$ErrorActionPreference = "Stop"
if (Get-Process -Name "SkyrimSE" -ErrorAction SilentlyContinue) { throw "Skyrim is running. Quit the game first - it keeps writing to the database while it runs." }

function ConvertTo-WslPath([string]$p) { "/mnt/" + $p.Substring(0, 1).ToLower() + ($p.Substring(2) -replace '\\', '/') }

$here = Split-Path -Parent $MyInvocation.MyCommand.Path
$sh = Join-Path $here "reset_playthrough.sh"
if (-not (Test-Path -LiteralPath $sh)) { throw "reset_playthrough.sh is missing next to this script ($here)" }

# WSL needs LF line endings and a path it can see: stage a clean copy in TEMP.
$stage = Join-Path $env:TEMP "lrg_reset"
New-Item -ItemType Directory -Force $stage | Out-Null
$staged = Join-Path $stage "reset_playthrough.sh"
$text = [IO.File]::ReadAllText($sh) -replace "`r`n", "`n"
[IO.File]::WriteAllText($staged, $text, (New-Object Text.UTF8Encoding $false))

$run = $Yes -and -not $WhatIf
$bkWsl = "-"
if ($run) {
    $bk = Join-Path $BackupRoot ("reset-" + (Get-Date -Format "yyyyMMdd-HHmmss"))
    if ($bk -match ' ') { throw "the backup path must not contain spaces: $bk (use -BackupRoot)" }
    New-Item -ItemType Directory -Force $bk | Out-Null
    $bkWsl = ConvertTo-WslPath $bk
}
$mode = if ($run) { "run" } else { "plan" }
$chim = if ($ChimToo) { "1" } else { "0" }
$keep = if ($KeepLog) { "1" } else { "0" }

wsl -d $Distro -u root --cd / -- bash (ConvertTo-WslPath $staged) $mode $bkWsl $chim $keep
$rc = $LASTEXITCODE

if (-not $run) {
    ""
    "To really do it (Skyrim closed):"
    "  powershell -ExecutionPolicy Bypass -File `"$($MyInvocation.MyCommand.Path)`" -Yes -ChimToo"
    "  (leave out -ChimToo to reset only the glue's own memory)"
    exit $rc
}
if ($rc -ne 0) { "RESET FAILED (exit $rc). Read the lines above; the backup folder is $bk"; exit $rc }
""
"Backup + undo script: $bk"
"To undo:  wsl -d $Distro -u root --cd / -- bash $bkWsl/restore.sh"
"Next: start the NEW game. CHIM's own new-game load finishes the job (it rolls NPC profiles back)."
