Scriptname ReferenceAlias extends Alias Hidden
{COMPILE-ONLY STUB (see Debug.psc). Never deployed.}

ObjectReference Function GetReference() native
Actor Function GetActorReference()
	return GetReference() as Actor
EndFunction

Event OnPlayerLoadGame()
EndEvent
