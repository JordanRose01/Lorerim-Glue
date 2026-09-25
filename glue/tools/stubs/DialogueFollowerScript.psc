Scriptname DialogueFollowerScript extends Quest Hidden
{COMPILE-ONLY HEADER - NEVER DEPLOYED. See Debug.psc for the pattern and LRG_Followers.psc for why.}

;/ Simple Follower Framework 1.4.1.1 replaces the vanilla script on quest DialogueFollower
 (Skyrim.esm 0x000750BA) with its own, and the .psc SFF ships in Source\Scripts is the STALE
 vanilla 1.9 script - it does not contain one SFF_* function, so compiling against SFF's own source
 folder cannot work. These nine declarations are the ones LRG_Followers calls, transcribed from the
 SHIPPED Scripts\dialoguefollowerscript.pex (PEX v3.2, read with a read-only reader; 36 functions,
 exact return types and parameter types):

     None  SetFollower(objectreference FollowerRef)
     Bool  IsManagedFollower(Actor akActor)
     Bool  SFF_IsPrimaryFollower(Actor akActor)
     Int   SFF_GetTrackedFollowerCount()
     Int   SFF_GetMaxFollowersSafe()
     None  SFF_SetLastSpeaker(Actor akActor)          [script 509 / pt17: LRG_Followers.SffWait]
     Actor GetDialogueFollowerTarget()                [script 509 / pt17: LRG_Followers.SffWait]
     None  FollowerWait()                             [script 509 / pt17: LRG_Followers.SffWait]
     None  FollowerFollow()                           [script 509 / pt17: LRG_Followers.SffWait]

 The bodies are whatever makes the compiler happy: only the SIGNATURES matter, because Papyrus
 resolves the call by name against the script the game really has attached at runtime.

 DEPLOYING A COMPILED COPY OF THIS FILE WOULD OVERWRITE SFF'S REAL SCRIPT AND BREAK EVERY FOLLOWER
 IN THE SAVE. tools/compile.ps1 only ever compiles game\LoreRimGlue\Source\Scripts and copies only
 those .pex files, and it fails the build if a stub name ever turns up among them. /;

Function SetFollower(ObjectReference FollowerRef)
EndFunction

bool Function IsManagedFollower(Actor akActor)
	return false
EndFunction

bool Function SFF_IsPrimaryFollower(Actor akActor)
	return false
EndFunction

int Function SFF_GetTrackedFollowerCount()
	return 0
EndFunction

int Function SFF_GetMaxFollowersSafe()
	return 0
EndFunction

Function SFF_SetLastSpeaker(Actor akActor)
EndFunction

Actor Function GetDialogueFollowerTarget()
	return None
EndFunction

Function FollowerWait()
EndFunction

Function FollowerFollow()
EndFunction
