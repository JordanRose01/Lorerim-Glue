Scriptname GlobalVariable extends Form Hidden
{COMPILE-ONLY STUB (see Debug.psc). Needed because SKSE's Actor.psc / Quest.psc call these
 members in function bodies. Never deployed.}

float Function GetValue() native
Function SetValue(float afNewValue) native

int Function GetValueInt()
	return GetValue() as int
EndFunction

Function SetValueInt(int aiNewValue)
	SetValue(aiNewValue as float)
EndFunction

float Function Mod(float afHowMuch)
	SetValue(GetValue() + afHowMuch)
	return GetValue()
EndFunction

float Property Value Hidden
	float Function Get()
		return GetValue()
	EndFunction
	Function Set(float afValue)
		SetValue(afValue)
	EndFunction
EndProperty
