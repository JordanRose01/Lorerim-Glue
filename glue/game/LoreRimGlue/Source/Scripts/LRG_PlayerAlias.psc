Scriptname LRG_PlayerAlias extends ReferenceAlias
{LoreRim Glue - re-registers events and keys on every game load.}

Event OnPlayerLoadGame()
	LRG_Main m = GetOwningQuest() as LRG_Main
	if m
		m.Maintenance()
	endif
EndEvent
