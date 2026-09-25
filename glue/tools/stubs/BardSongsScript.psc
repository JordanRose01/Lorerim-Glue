Scriptname BardSongsScript extends Quest Hidden
{COMPILE-ONLY HEADER - NEVER DEPLOYED. See Debug.psc for the pattern.}

;/ [0.5.6] ExtCmdLRG_Escort ends a bard's performance the way the game's own "stop playing" line
 does: StopAllSongs() on the BardSongs quest (Skyrim.esm 0x00074A55). The script the game really
 runs here is the Unofficial Skyrim Special Edition Patch's (its BSA carries both
 scripts\bardsongsscript.pex and scripts\source\bardsongsscript.psc; no loose copy and no other BSA
 in the Ultra profile has one). Read from that .pex with a read-only reader:

     BardSongsScript extends Quest
     None  StopAllSongs()        - sets StopSong, stops all twenty song scenes, Playing = 0

 Only the signature matters: Papyrus resolves the call by name against the script the game has
 attached at runtime. The body is whatever makes the compiler happy.

 DEPLOYING A COMPILED COPY OF THIS FILE WOULD OVERWRITE THE BARDS' SCRIPT FOR THE WHOLE GAME.
 tools/compile.ps1 only compiles game\LoreRimGlue\Source\Scripts and refuses the build if a stub
 name ever turns up among the shipped scripts. /;

Function StopAllSongs()
EndFunction
