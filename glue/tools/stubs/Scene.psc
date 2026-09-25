Scriptname Scene extends Form Hidden
{COMPILE-ONLY declaration stub - never deployed}
; [0.5.6] The Creation Kit's vanilla Scene.psc is not on this machine (no Scripts.zip) and SKSE does not
; extend Scene, so the auto-stub generator produced an EMPTY Scene type - fine while the glue only ever
; compared GetCurrentScene() with None, not enough for ExtCmdLRG_Escort (LRG_Main.EscortStopScene),
; which has to ask a scene for its quest and stop it. Declared in vanilla's own shape - native, no
; arguments, the same names - so each compiled call is the CallMethod the game resolves against its
; real Scene script. CHIM compiles the same calls: AIAgentPapyrusFunctions.psc:1320-1328.

Quest Function GetOwningQuest() native
bool Function IsPlaying() native
Function Stop() native
