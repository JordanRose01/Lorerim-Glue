Scriptname LRG Hidden
{LoreRim Glue - static CHIM bridge. CHIM 3.3.x calls DispatchExternalCommand on the
 bridge script of an ExtCmd* action (action codes are ExtCmdLRG_*). The same commands
 also arrive through the CHIM_CommandReceived mod event; LRG_Main de-duplicates.}

LRG_Main Function GetMain() Global
	; [0.5.5] LRG_MainQuest is object id 0x802 (tools/make_esp.py). The frozen instances of the
	; 0.5.4 saves belong to the old form, which the plugin no longer contains.
	return Game.GetFormFromFile(0x00000802, "LoreRimGlue.esp") as LRG_Main
EndFunction

bool Function DispatchExternalCommand(string asNpcName, string asCommand, string asParameter) Global
	LRG_Main m = GetMain()
	if !m
		Debug.Trace("[LRG] bridge: main quest not found")
		return false
	endif
	return m.HandleCommand(asNpcName, asCommand, asParameter, "bridge")
EndFunction
