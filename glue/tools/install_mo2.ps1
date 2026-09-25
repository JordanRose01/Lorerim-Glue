# LoreRim Glue - install the game-side mod into the LoreRim MO2 instance (profile Ultra only).
# Rules this script follows: MO2 must be CLOSED; modlist.txt, plugins.txt, loadorder.txt and
# settings.ini are backed up first; nothing is sorted; the Default and Extreme profiles are
# never touched; no LoreRim mod is modified. Safe to re-run (updates files, adds no duplicates).
#
# -NoNudeBody : install everything EXCEPT the nude female body meshes, and remove them if a
#               previous run installed them. Default (no switch) = meshes included.
param(
    [string]$Mo2Root = "F:\Modlists\LoreRim",
    [string]$Profile = "Ultra",
    [string]$ModName = "LoreRim Glue",
    [switch]$NoNudeBody
)
$ErrorActionPreference = "Stop"
if (Get-Process -Name "ModOrganizer" -ErrorAction SilentlyContinue) { throw "Mod Organizer is running. Close it first - profile files must not be edited while MO2 is open." }
if (Get-Process -Name "SkyrimSE" -ErrorAction SilentlyContinue) { throw "Skyrim is running. Close it first." }

$here = Split-Path -Parent $MyInvocation.MyCommand.Path
$source = Join-Path (Split-Path -Parent $here) "game\LoreRimGlue"
$needed = @("LoreRimGlue.esp", "Scripts\LRG_Main.pex", "Scripts\LRG_OStim.pex", "MCM\Config\LoreRimGlue\config.json")
if (-not $NoNudeBody) { $needed += "meshes\actors\character\character assets\femalebody_0.nif" }
foreach ($need in $needed) {
    if (-not (Test-Path (Join-Path $source $need))) { throw "missing $need - run tools\make_esp.py / tools\compile.ps1 first" }
}
$profileDir = Join-Path $Mo2Root "profiles\$Profile"
$modDir = Join-Path $Mo2Root "mods\$ModName"

# 1. backups
$stamp = Get-Date -Format "yyyyMMdd-HHmmss"
foreach ($f in @("modlist.txt", "plugins.txt", "loadorder.txt", "settings.ini")) {
    Copy-Item (Join-Path $profileDir $f) (Join-Path $profileDir "$f.bak-$stamp")
}
"backups written: *.bak-$stamp"

# 2. mod files (a NEW mod folder; nothing of LoreRim's is overwritten)
#    The nude female body lives in <mod>\meshes and is part of THIS mod. -NoNudeBody skips it and
#    deletes a previously installed copy - our own files only, never another mod's.
$modMeshes = Join-Path $modDir "meshes"
if ($NoNudeBody) {
    robocopy $source $modDir /E /XD (Join-Path $source "meshes") /NFL /NDL /NJH /NJS /NP | Out-Null
    $guardOk = ($modDir -eq (Join-Path $Mo2Root "mods\$ModName")) -and (Test-Path (Join-Path $modDir "LoreRimGlue.esp"))
    if ((Test-Path $modMeshes) -and $guardOk) {
        Remove-Item -LiteralPath $modMeshes -Recurse -Force
        "nude body: REMOVED $modMeshes (-NoNudeBody)"
    } else {
        "nude body: not installed (-NoNudeBody)"
    }
} else {
    robocopy $source $modDir /E /NFL /NDL /NJH /NJS /NP | Out-Null
    $n = (Get-ChildItem $modMeshes -Recurse -File -ErrorAction SilentlyContinue | Measure-Object).Count
    "nude body: $n mesh file(s) installed under $modMeshes"
}
# meta.ini carries the version MO2 shows in its left pane. It used to be hard-coded to 0.1.0, so
# every release since looked like the first one. The single source of truth is the server plugin's
# manifest, which is what the round's version number is set in.
$manifest = Join-Path (Split-Path -Parent $here) "server\lorerim_glue\manifest.json"
$modVersion = "0.1.0"
if (Test-Path $manifest) {
    $v = (Get-Content $manifest -Raw | ConvertFrom-Json).version
    if ($v) { $modVersion = [string]$v }
}
$metaIni = Join-Path $modDir "meta.ini"
if (-not (Test-Path $metaIni)) {
    "[General]`r`nmodid=0`r`nversion=$modVersion`r`nnewestVersion=`r`ncategory=0`r`ninstallationFile=`r`nrepository=`r`n" | Set-Content -Encoding ascii $metaIni
} else {
    # an existing meta.ini keeps everything MO2 put there; only the version line is refreshed
    $meta = Get-Content $metaIni
    if ($meta | Where-Object { $_ -match "^version=" }) {
        $meta = $meta | ForEach-Object { if ($_ -match "^version=") { "version=$modVersion" } else { $_ } }
    } else {
        $meta = $meta + "version=$modVersion"
    }
    [System.IO.File]::WriteAllLines($metaIni, $meta)
}
"meta.ini: version=$modVersion"
"mod folder: $modDir"

# 3. modlist.txt: highest priority = first line after the header comment. Insert, never sort.
$modlist = Join-Path $profileDir "modlist.txt"
$lines = [System.Collections.Generic.List[string]](Get-Content $modlist)
if (-not ($lines | Where-Object { $_ -eq "+$ModName" -or $_ -eq "-$ModName" })) {
    $lines.Insert(1, "+$ModName")
    [System.IO.File]::WriteAllLines($modlist, $lines)
    "modlist.txt: added +$ModName at the top (bottom of MO2's left pane)"
} else { "modlist.txt: entry already present, left as is" }

# 4. plugins.txt / loadorder.txt: append at the end (after AIAgent.esp and OStim.esp)
foreach ($pair in @(@("plugins.txt", "*LoreRimGlue.esp"), @("loadorder.txt", "LoreRimGlue.esp"))) {
    $path = Join-Path $profileDir $pair[0]
    $content = Get-Content $path
    if (-not ($content | Where-Object { $_ -match "^\*?LoreRimGlue\.esp$" })) {
        Add-Content -Path $path -Value $pair[1] -Encoding ascii
        "$($pair[0]): appended $($pair[1])"
    } else { "$($pair[0]): entry already present" }
}
"Done. Start MO2 and check: '$ModName' ticked at the bottom of the left pane, LoreRimGlue.esp ticked at the end of the right pane. Nemesis does NOT need to be run for this mod."
if (-not $NoNudeBody) {
    "Nude female body is part of this mod (meshes\actors\character\...\femalebody_0/1.nif) and wins over 'LoreRim - BodySlide Output'."
    "To turn it off again: re-run this script with  -NoNudeBody   (or hide the mod's 'meshes' folder in MO2)."
}
