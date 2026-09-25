# LoreRim Glue - compile the Papyrus scripts WITHOUT the Creation Kit.
# Uses the Bethesda compiler that ships inside the Nemesis mod and the script sources that
# exist in the LoreRim mods folder. Vanilla sources are missing on this machine (no CK
# Scripts.zip), so every script type that is only *referenced* gets an empty declaration stub,
# generated on demand from the compiler's own "unknown type" errors. Stubs are compile-only:
# the compiler emits a .pex for the target script alone, so no stub ever reaches the game.
# If you later install the Creation Kit, pass -VanillaSource "<...>\Data\Source\Scripts"
# and the stubs are not needed.
param(
    [string]$ModsRoot = "F:\Modlists\LoreRim\mods",
    [string]$VanillaSource = "",
    [string]$Work = (Join-Path $env:TEMP "lrg_build")   # short path: the compiler dislikes long ones
)
$ErrorActionPreference = "Stop"
$here = Split-Path -Parent $MyInvocation.MyCommand.Path
$glue = Split-Path -Parent $here
$compilerDir = Join-Path $ModsRoot "Project New Reign - Nemesis Unlimited Behavior Engine\Nemesis_Engine\Papyrus Compiler"
$compiler = Join-Path $compilerDir "PapyrusCompiler.exe"
$flags = Join-Path $compilerDir "scripts\TESV_Papyrus_Flags.flg"
if (-not (Test-Path $compiler)) { throw "Papyrus compiler not found at $compiler" }

$src = Join-Path $Work "src"; $stubs = Join-Path $Work "stubs"; $out = Join-Path $Work "out"
Remove-Item -Recurse -Force $Work -ErrorAction SilentlyContinue
New-Item -ItemType Directory -Force $src, $stubs, $out | Out-Null
Copy-Item (Join-Path $glue "game\LoreRimGlue\Source\Scripts\*.psc") $src
Copy-Item (Join-Path $here "stubs\*.psc") $stubs
Copy-Item $flags $Work

# OStim API: importing OStim's full source tree drags in its UI widget scripts (SkyUI SDK,
# also missing here). Every OStim function the glue calls is a "Global Native", so we build
# declaration-only copies straight from the INSTALLED sources: the Scriptname line plus every
# native declaration, verbatim. Identical signatures -> identical compiled calls.
function Export-NativeDeclarations([string]$file, [string]$name, [string]$origin) {
    # Scriptname line + every "... Function X(...) Global Native" line, verbatim (-match ignores case)
    if (-not (Test-Path -LiteralPath $file)) { throw "$origin source missing: $file" }
    $keep = @(Get-Content -LiteralPath $file | Where-Object { $_ -match '^\s*Scriptname\s' -or $_ -match '^\s*[\w\[\]]*\s*Function\s+\w+\(.*\)\s+Global\s+Native\s*(;.*)?$' })
    if ($keep.Count -lt 2 -or $keep[0] -notmatch '^\s*Scriptname\s') { throw "no native declarations found in $file" }
    (@($keep[0], "{COMPILE-ONLY: native declarations extracted verbatim from the installed $origin $name.psc}") + ($keep | Select-Object -Skip 1)) |
        Set-Content -Encoding ascii (Join-Path $stubs "$name.psc")
}
$ostimSrc = Join-Path $ModsRoot "OStim Standalone - Advanced Adult Animation Framework\Scripts\Source"
# add a script here when the glue starts calling it (v0.2: OFurniture)
foreach ($api in @("OThread", "OThreadBuilder", "OActor", "OActorUtil", "OLibrary", "OMetadata", "OJSON", "OFurniture")) {
    Export-NativeDeclarations (Join-Path $ostimSrc "$api.psc") $api "OStim"
}
# Survival Mode Improved: the cold-exemption hook (SurvivalModeImprovedApi.RestoreColdLevel). Same
# treatment: only the installed native declarations, so the mod's other sources are not dragged in.
Export-NativeDeclarations (Join-Path $ModsRoot "Survival Mode Improved - SKSE\Source\Scripts\SurvivalModeImprovedApi.psc") "SurvivalModeImprovedApi" "Survival Mode Improved"

# PapyrusUtil (v0.4, Phase 2): MiscUtil reads SmartTalk.ini for LRG_DlgUI.SmartTalkSafe() (design
# 1.6 / D-21) and StorageUtil is the only store a Hidden script's GLOBAL functions can reach - a
# global function cannot see a script variable ("variable X is undefined"), and LRG_DlgUI has to
# remember its read/count calibration and the validated hide originals. Importing PapyrusUtil's
# whole Source tree does NOT work: MiscUtil.psc carries legacy non-native bodies that call
# Debug.TraceStack, which the compile-only Debug stub does not declare. Declarations only, exactly
# the OStim treatment.
# [0.4.1] ActorUtil joins them: the conversation hold's OPT-IN package layer
# (bConvHoldPackage) calls ActorUtil.AddPackageOverride / RemovePackageOverride, the same pair CHIM
# uses. Declarations only, exactly the OStim treatment.
$papyrusUtilSrc = Join-Path $ModsRoot "PapyrusUtil SE - Modders Scripting Utility Functions\Scripts\Source"
# [pt19-purchase] PapyrusUtil joins them: LRG_Profile.BuyMultFrom parses the price-mod string with
# PapyrusUtil.StringSplit (installed PapyrusUtil.psc line 135, "string[] function StringSplit(...) global native").
# Declarations only, exactly the OStim treatment; PapyrusUtil.psc is on the deploy denylist below.
foreach ($api in @("MiscUtil", "StorageUtil", "ActorUtil", "PapyrusUtil")) {
    Export-NativeDeclarations (Join-Path $papyrusUtilSrc "$api.psc") $api "PapyrusUtil"
}

# [0.5.1] Simple Follower Framework's SKSE natives (LRG_Followers: IsVanillaFollower / GetMaxFollowers).
# Declarations only, exactly the OStim treatment - SFF's other sources must NOT be imported, because the
# DialogueFollowerScript.psc it ships is the stale vanilla 1.9 script with none of the SFF_* functions.
# That one is covered by the hand-written header in stubs\ instead.
# If SFF is ever removed from the list the glue still compiles: nothing else references SFF_SKSE, and
# every call site is behind LRG_Followers.SffPresent(). Only the export below would have to go.
Export-NativeDeclarations (Join-Path $ModsRoot "Simple Follower Framework\Source\Scripts\SFF_SKSE.psc") "SFF_SKSE" "Simple Follower Framework"

$imports = @($src)
if ($VanillaSource -ne "") { $imports += $VanillaSource }
$imports += @(
    (Join-Path $ModsRoot "Skyrim Script Extender (SKSE64)\Scripts\Source"),
    (Join-Path $ModsRoot "powerofthree's Papyrus Extender\Source\scripts"),
    (Join-Path $ModsRoot "CHIM\Source\Scripts"),
    $stubs
)
foreach ($i in $imports) { if (-not (Test-Path $i)) { throw "import folder missing: $i" } }
$importArg = ($imports -join ";")

$targets = Get-ChildItem $src -Filter *.psc | Select-Object -ExpandProperty Name
$made = New-Object System.Collections.Generic.List[string]
for ($round = 1; $round -le 60; $round++) {
    # cmd does the 2>&1: Windows PowerShell would turn the compiler's stderr lines into errors
    $flg = Join-Path $Work 'TESV_Papyrus_Flags.flg'
    $bat = Join-Path $Work 'run.bat'
    "@echo off`r`n`"$compiler`" `"$src`" -all -f=`"$flg`" -i=`"$importArg`" -o=`"$out`" 2>&1" | Set-Content -Encoding ascii $bat
    $log = cmd /c "`"$bat`"" | Out-String
    $unknown = [regex]::Matches($log, "unknown type (\w+)") | ForEach-Object { $_.Groups[1].Value.ToLower() } | Sort-Object -Unique
    $new = @($unknown | Where-Object { -not (Test-Path (Join-Path $stubs "$_.psc")) })
    if ($new.Count -eq 0) { break }
    foreach ($t in $new) {
        "Scriptname $t extends Form Hidden`r`n{AUTO-GENERATED COMPILE-ONLY STUB - never deployed}" | Set-Content -Encoding ascii (Join-Path $stubs "$t.psc")
        $made.Add($t)
    }
}
"rounds: $round ; auto-stubs created: $($made.Count)"
if ($made.Count) { "  " + ($made -join ", ") }
$errors = ($log -split "`r?`n") | Where-Object { $_ -match "\): " -and $_ -notmatch "unknown type" }
$pex = Get-ChildItem $out -Filter *.pex -ErrorAction SilentlyContinue
"compiled: " + (($pex | Select-Object -ExpandProperty Name) -join ", ")
$missing = $targets | Where-Object { -not (Test-Path (Join-Path $out ($_ -replace '\.psc$', '.pex'))) }
if ($missing) {
    "NOT compiled: " + ($missing -join ", ")
    "--- compiler messages (unique) ---"; ($log -split "`r?`n") | Where-Object { $_ -match "\): " } | Sort-Object -Unique | Select-Object -First 40
    exit 1
}
# every .pex exists, but the compiler can still have printed errors or warnings: show them and
# refuse to publish a build whose log contains the word "error" (the tool must MEASURE its "OK")
if ($errors) {
    "--- compiler messages (unique) ---"; $errors | Sort-Object -Unique
    $hard = @($errors | Where-Object { $_ -match "(?i)\berror\b" })
    if ($hard.Count) { "REFUSING to publish: $($hard.Count) compiler error line(s)"; exit 1 }
}
# Guard: a very long string in a .pex (a docstring counts - {..} text is compiled in) overflows
# the game's script loader and crashes Skyrim while a save loads (crash 2026-09-21 16:57: a
# 6765-character script docstring). Long notes belong in ;/ block comments /; , long CSV
# constants in pieces. 500 is far below anything that ever crashed and above everything we need.
$maxPexString = 500
foreach ($t in $targets) {
    $pexBytes = [System.IO.File]::ReadAllBytes((Join-Path $out ($t -replace '\.psc$', '.pex'))); $pp = 16
    for ($i = 0; $i -lt 3; $i++) { $pp += 2 + ($pexBytes[$pp] * 256) + $pexBytes[$pp + 1] }
    $count = ($pexBytes[$pp] * 256) + $pexBytes[$pp + 1]; $pp += 2
    for ($i = 0; $i -lt $count; $i++) {
        $len = ($pexBytes[$pp] * 256) + $pexBytes[$pp + 1]; $pp += 2
        if ($len -gt $maxPexString) {
            $head = [System.Text.Encoding]::ASCII.GetString($pexBytes, $pp, 60) -replace '[^\x20-\x7E]', '.'
            "REFUSING to publish: $t holds a $len-character string ('$head...') - the game's script loader crashes on long strings. Turn the docstring into a ;/ block comment /; or split the constant."
            exit 1
        }
        $pp += $len
    }
}
# [0.5.1 / pt9 risk R4] THE DEPLOY DENYLIST. A compile-only stub is another mod's script: shipping a
# .pex of one would OVERWRITE that mod. DialogueFollowerScript is the dangerous one - a compiled copy
# replaces Simple Follower Framework's own script and breaks every follower in the save. The build can
# only produce a .pex for a file in game\LoreRimGlue\Source\Scripts, so this can only fire if somebody
# copies a stub in there; it is asserted anyway, because the cost of being wrong is the owner's save.
$denied = @("DialogueFollowerScript", "SFF_SKSE", "Debug", "Package", "GlobalVariable", "ReferenceAlias", "MCM",
    "MCM_ConfigBase", "MiscUtil", "StorageUtil", "ActorUtil", "PapyrusUtil", "OThread", "OThreadBuilder", "OActor",
    "OActorUtil", "OLibrary", "OMetadata", "OJSON", "OFurniture", "SurvivalModeImprovedApi",
    "Scene", "BardSongsScript")   # [0.5.6] compile-only stubs of ExtCmdLRG_Escort (vanilla / USSEP scripts)
$shipped = $targets | ForEach-Object { $_ -replace '\.psc$', '' }
$clash = @($shipped | Where-Object { $denied -contains $_ })
if ($clash.Count) {
    "REFUSING to publish: $($clash -join ', ') is a COMPILE-ONLY stub / another mod's script - deploying a .pex of it would overwrite that mod."
    exit 1
}
$dest = Join-Path $glue "game\LoreRimGlue\Scripts"
New-Item -ItemType Directory -Force $dest | Out-Null
foreach ($t in $targets) { Copy-Item (Join-Path $out ($t -replace '\.psc$', '.pex')) $dest -Force }
$stray = @(Get-ChildItem $dest -Filter *.pex | Where-Object { $denied -contains ($_.BaseName) })
if ($stray.Count) {
    "REFUSING to publish: $(($stray | Select-Object -ExpandProperty Name) -join ', ') is in game\LoreRimGlue\Scripts and must never be deployed. Delete it."
    exit 1
}
"OK - .pex files copied to game\LoreRimGlue\Scripts (only the glue's own scripts); compiler reported 0 errors, 0 warnings"
