Scriptname LRG_MCM extends MCM_ConfigBase
{LoreRim Glue - MCM Helper menu. The menu itself is data (MCM\Config\LoreRimGlue\config.json);
 this script reacts to changes that need a re-registration, publishes the calibration status line,
 and carries the Calibration page's one button, "Forget everything it learned" - MCM Helper's
 CallFunction can only reach THIS script.}

;/v0.5. The Calibration page's status line is a plain string property refreshed when the page is
 opened and after any setting change, because a property with only a getter is not a shape MCM
 Helper is documented to accept. It is a CONVENIENCE, never the record: the same picture is written
 to the log as CALIB SUMMARY / CALIB GATE on every load and on every topic-dump press.
 [v1.0, spec S10] The five press buttons (the click test both ways, the menu-still-works test, the
 held-mouse and force-close tests), the probe arming and the opt-in calibration switches are gone
 with the presses they ran./;
string Property CalStatus Auto

Event OnConfigOpen()
	RefreshStatus()
EndEvent

Event OnSettingChange(string a_ID)
	;/[0.4 fix pass, D12] A hotkey change must take effect in the same session: RegisterKeys() begins
	 with a form-wide UnregisterForAllKeys() and re-registers every key LRG_Main owns.
	 [v1.0, spec S10] The talk key (iKeyPushToTalk:Dialogue) is a hotkey on the Keys page whose id
	 does not end in :Keys, so it is named here. The probe key and the probe / opt-in calibration
	 toggles, which also re-ran LRG_DlgProbe.Maintenance() here, are retired./;
	RefreshStatus()
	;/[pt17] THE DEVELOPER DRY RUN IS NEVER SILENT. Playtest 2026-09-23 19:57: told to "leave dry
	 run on", the owner found "Dry-run mode" OFF on page 1, switched it on, and every request of the
	 evening was refused without a word (the menuless questing dry run was meant). The switch now
	 lives at the bottom of the Diagnostics page as "DEV: dry-run everything", and ticking it ON says
	 so at once: a message box here, LRG_Main's own boot / heartbeat notes, and the NPCs' words./;
	if a_ID == "bDryRun:General"
		LRG_Main dm = LRG.GetMain()
		if MCM.GetModSettingBool("LoreRimGlue", "bDryRun:General")
			Debug.MessageBox("LoreRim Glue: you switched on the DEVELOPER dry run (Diagnostics page, bottom). Every command NPCs try will fail until it is off - undressing, scenes, follow / wait / release, clicks, services. NPCs will say so. To try menuless questing use 'Menuless questing: dry run' on the Menuless questing page instead.")
			if dm
				dm.LogE("", "DEV DRY RUN switched ON in the MCM (Diagnostics page) - every glue command is refused until it is off", "")
			endif
		elseif dm
			dm.LogC("", "DEV dry run switched OFF in the MCM", "")
		endif
		if dm
			dm.LogRefresh()
		endif
		return
	endif
	bool keysChanged = StringUtil.Find(a_ID, ":Keys") >= 0 || a_ID == "iKeyPushToTalk:Dialogue"
	if !keysChanged
		return
	endif
	LRG_Main m = LRG.GetMain()
	if m
		m.RegisterKeys()
	endif
EndEvent

Function RefreshStatus()
	LRG_DlgProbe p = Probe()
	if p
		CalStatus = p.CalStatusText()
	else
		CalStatus = "not available - the menuless questing scripts are not attached to this save"
	endif
EndFunction

LRG_DlgProbe Function Probe()
	LRG_Main m = LRG.GetMain()
	if !m
		return None
	endif
	return m.GetProbe()
EndFunction

; ---------------------------------------------------------------------------
; The Calibration page's one button. It only forwards; what "forget" means
; lives in LRG_DlgProbe.
; ---------------------------------------------------------------------------
Function CalForget()
	{Starts the measuring again from nothing: the four rows, the route and its proof, the install
	 file, and (ev=calib reset=1) the server's clicks_ok.}
	LRG_DlgProbe p = Probe()
	if p
		; LRG_DlgProbe.CalForget() says so in the corner itself - one note, not two
		p.CalForget()
		RefreshStatus()
	else
		Debug.Notification("LoreRim Glue: the menuless questing module is not attached - nothing to forget")
	endif
EndFunction
