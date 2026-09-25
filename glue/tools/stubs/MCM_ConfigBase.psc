Scriptname MCM_ConfigBase extends Quest Hidden
{COMPILE-ONLY STUB. The real MCM_ConfigBase (MCM Helper) extends SkyUI's SKI_ConfigBase; at
 runtime the game binds LRG_MCM to the real parent. Only the members LRG_MCM overrides are
 declared here. Never deployed.}

Event OnSettingChange(string a_ID)
EndEvent

; v0.5: LRG_MCM refreshes its read-only calibration status line when the page is opened.
Event OnConfigOpen()
EndEvent
