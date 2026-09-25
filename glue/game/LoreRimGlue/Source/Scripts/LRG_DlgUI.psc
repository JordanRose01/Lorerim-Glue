Scriptname LRG_DlgUI Hidden
{LoreRim Glue - the ONLY place a UI.* call or a GFx path string exists in this project.
 Global functions only. Phase 2 menuless questing, design 1.2. Also the DLL seam (design 8):
 every function keeps its signature so a native twin can replace its body later.}

;/ ---------------------------------------------------------------------------------------------
 CONSTANTS (design 1.2, all verified in the disassembly of the winning dialoguemenu.swf):

   M   = "Dialogue Menu"
   D   = "_root.DialogueMenu_mc"
   L   = D + ".TopicList"                         ; AS member assigned in the constructor
   L2  = D + ".TopicListHolder.List_mc"           ; fallback spelling (timeline instance names)
   G   = "_global.DialogueMenu"                   ; the class statics live here

   menu states : SHOW_GREETING 0 / TOPIC_LIST_SHOWN 1 / TOPIC_CLICKED 2 / TRANSITIONING 3
   ALLOW_PROGRESS_DELAY default 750

 The literals are written out in every function on purpose: a helper that returned them would
 add a Papyrus call to every one of the 56 reads a layer costs, and this file is the only place
 they may appear anyway.

 WHY STORAGEUTIL (deviation from design 1.2's "script-local state", reported in
 research/pt8-build-ui.md 0.1): a Papyrus GLOBAL function cannot see a script variable. Measured
 with this project's own compiler: "variable mode is undefined". The signatures of design 1.2
 ("EntryCount()" with no mode argument, "Hide()"/"Unhide()" with no arguments) therefore need a
 store outside the script, and StorageUtil (PapyrusUtil, installed and enabled in this list) is
 the only one reachable from a global function. Keys are all "LRG.du.*", Form key None:
   cm   int   calibrated count mode 1=A 2=B 3=C, 0 = not calibrated, -1 = no mode works
   rm   int   calibrated read mode 3 or 4, 0 = not calibrated
   lp   int   list path spelling 1 = L, 2 = L2
   hid  int   1 = we are hiding the list right now
   hok  int   1 = the stored originals validated (rule 10)
   hfam int   the SWF family the originals were read under (rule 10)
   hmd  int   the hide mode that was applied (1 topics-only, 2 full)
   hx/hy/hex  float  TopicListHolder._x, ._y, ExitButton._x as read BEFORE hiding
   sti  int   the topicIndex SelectAndVerify last verified, -1 = none
   stx  string the text prefix SelectAndVerify last verified
   sat  float when SelectAndVerify last verified an identity (Utility.GetCurrentRealTime)
   clk  int   outcome of the last Click(), see ClickResult()
   unh  int   outcome of the last Unhide(), see LastUnhide()
   sto  string the first offending Smart Talk setting found by the last SmartTalkOffender()

 THE SECOND STORE, "LRG.cal.*" (v0.5, plan 11.1). The E1 calibration keeps its answers in a
 store of its own so it can never collide with the "LRG.du.*" state above: that state is what
 the UI primitives need to work AT ALL (the hide originals, the verified selection), while the
 calibration is what the DRIVER reads to decide how to behave. They have different lifetimes -
 CalForget() wipes one and must not touch the other - and different writers: LRG_DlgProbe is the
 only script that writes LRG.cal.*, through CalSetInt / CalSetFloat / CalSetStr below. This file
 gives the accessors and nothing else; it does not know what a single calibration key MEANS.
 Both stores are StorageUtil for the same reason (a Papyrus global function cannot see a script
 variable) and both are therefore per-SAVE, not per-profile.

 v0.5 adds NO UI function, changes none and removes none. Every signature above and below stays
 frozen for the DLL seam of V04_BUILD_PLAN 11.

 SAFETY CONTRACT for every function in this file: correct and silent with the menu CLOSED
 (UI.Set* reaches nothing when the movie is gone, UI.Get* returns a default) - return
 false / 0 / "" / -1, never throw, never wait, never raise a notification.
 --------------------------------------------------------------------------------------------- /;

; ---------------------------------------------------------------------------
; internal state helpers (StorageUtil, global keys). Not delayed natives, so
; they cost no frame - unlike every UI.Get*/Set*.
; ---------------------------------------------------------------------------
int Function SInt(string asKey, int aiDefault) Global
	return StorageUtil.GetIntValue(None, "LRG.du." + asKey, aiDefault)
EndFunction

Function SSetInt(string asKey, int aiValue) Global
	StorageUtil.SetIntValue(None, "LRG.du." + asKey, aiValue)
EndFunction

float Function SFloat(string asKey, float afDefault) Global
	return StorageUtil.GetFloatValue(None, "LRG.du." + asKey, afDefault)
EndFunction

Function SSetFloat(string asKey, float afValue) Global
	StorageUtil.SetFloatValue(None, "LRG.du." + asKey, afValue)
EndFunction

string Function SStr(string asKey, string asDefault) Global
	return StorageUtil.GetStringValue(None, "LRG.du." + asKey, asDefault)
EndFunction

Function SSetStr(string asKey, string asValue) Global
	StorageUtil.SetStringValue(None, "LRG.du." + asKey, asValue)
EndFunction

; ---------------------------------------------------------------------------
; the CALIBRATION store, "LRG.cal.*" (v0.5, plan 11.1). Same mechanism, second
; prefix; LRG_DlgProbe is its only writer (v1.0: LRG_Dialogue writes route through
; LRG_DlgProbe.CalSet, never here). No UI call, so no frame cost.
; ---------------------------------------------------------------------------
int Function CalInt(string asKey, int aiDefault) Global
	{One calibration integer. A key that was never written reads as the default.}
	return StorageUtil.GetIntValue(None, "LRG.cal." + asKey, aiDefault)
EndFunction

Function CalSetInt(string asKey, int aiValue) Global
	StorageUtil.SetIntValue(None, "LRG.cal." + asKey, aiValue)
EndFunction

float Function CalFloat(string asKey, float afDefault) Global
	return StorageUtil.GetFloatValue(None, "LRG.cal." + asKey, afDefault)
EndFunction

Function CalSetFloat(string asKey, float afValue) Global
	StorageUtil.SetFloatValue(None, "LRG.cal." + asKey, afValue)
EndFunction

string Function CalStr(string asKey, string asDefault) Global
	return StorageUtil.GetStringValue(None, "LRG.cal." + asKey, asDefault)
EndFunction

Function CalSetStr(string asKey, string asValue) Global
	StorageUtil.SetStringValue(None, "LRG.cal." + asKey, asValue)
EndFunction

Function CalClear() Global
	{Forget every calibration answer. The LRG.du.* store is NOT touched: that one is what the
	 primitives need to work at all. Keys are named explicitly, in three groups.}
	;/ Named rather than looped ON PURPOSE. StorageUtil does have ClearAllPrefix(), but an
	 explicit list is auditable: it is the one place that says what the whole key set IS, and a
	 key added to LRG_DlgProbe without a line here shows up as a key CalForget cannot forget.
	 [v1.0, spec S3.1] The set is the four gate rows, the route and its live proof (rproof, rclicks),
	 the measurements and the probe's own bookkeeping - gnote (the once-per-install "menu is learned"
	 note was said, so Forget re-arms it) and famu (armings on a dialogue interface it does not know). The retired keys (hide guard rb reopen park
	 runs armed auto autob gopen and the active pass's apdr inj ms4 flash hidems poke) have no
	 writer any more; a value an older save still holds is read by nothing. /;
	string[] k1 = new string[12]
	k1[0] = "cm"
	k1[1] = "rm"
	k1[2] = "fam"
	k1[3] = "st"
	k1[4] = "route"
	k1[5] = "rproof"
	k1[6] = "rclicks"
	k1[7] = "timer"
	k1[8] = "x1"
	k1[9] = "x1s"
	k1[10] = "x1d"
	k1[11] = "x1u"
	string[] k2 = new string[12]
	k2[0] = "apd"
	k2[1] = "apdb"
	k2[2] = "ms3"
	k2[3] = "tail"
	k2[4] = "col"
	k2[5] = "colL"
	k2[6] = "row"
	k2[7] = "rowN"
	k2[8] = "pay"
	k2[9] = "actms"
	k2[10] = "actry"
	k2[11] = "actbad"
	; actbad = opens that really needed a second Activate; rmN = how often the four-read mode-3
	; probe has come back empty, so it stops re-probing and re-warning on every layer for ever
	string[] k3 = new string[9]
	k3[0] = "actn"
	k3[1] = "items"
	k3[2] = "plat"
	k3[3] = "lp"
	k3[4] = "cmx"
	k3[5] = "rmN"
	k3[6] = "dirty"
	k3[7] = "gnote"
	k3[8] = "famu"
	CalClearList(k1)
	CalClearList(k2)
	CalClearList(k3)
EndFunction

Function CalClearList(string[] asKeys) Global
	{Unset one block of calibration keys in both the int and the float store. A key that was
	 never written is simply not there and the unset is a no-op.}
	int i = 0
	while i < asKeys.Length
		StorageUtil.UnsetIntValue(None, "LRG.cal." + asKeys[i])
		StorageUtil.UnsetFloatValue(None, "LRG.cal." + asKeys[i])
		i += 1
	endwhile
EndFunction

; ---------------------------------------------------------------------------
; [pt18] THE INSTALL FILE. The LRG.cal.* store above is per SAVE (StorageUtil), and on
; 2026-09-23 the same five rows were learned from zero at 04:40, 16:42 and 20:09 and were
; zero again at 23:15. PROTOCOL 10.13 says the calibration is a property of the INSTALL, so
; the answer rows are mirrored to one ini-shaped text file the GAME writes at runtime
; (MiscUtil, which already compiles here - JsonUtil would need a compile.ps1 change), under
; Data/SKSE/Plugins, where MO2 routes it into its overwrite folder exactly as MCM Helper's own
; settings ini lands. A new game session seeds every UNANSWERED key from it (LRG_DlgProbe
; .Maintenance); a key the save already answered always wins. CalForget wipes it too.
; ---------------------------------------------------------------------------
string Function CalFilePath() Global
	return "Data/SKSE/Plugins/LoreRimGlue_calibration.ini"
EndFunction

string[] Function CalFileKeys() Global
	{The keys the install file carries (v1.0): the four gate rows, the route and its live proof,
	 the measurements, and gnote (the "menu is learned" note was said on this install). NEVER the
	 dirty flag or a per-session counter. A key that is a prefix of another (cm / cmx) is listed
	 FIRST, which is the order IniInt finds them in.}
	string[] k = new string[21]
	k[0] = "cm"
	k[1] = "rm"
	k[2] = "fam"
	k[3] = "st"
	k[4] = "route"
	k[5] = "rproof"
	k[6] = "rclicks"
	k[7] = "lp"
	k[8] = "apd"
	k[9] = "timer"
	k[10] = "x1"
	k[11] = "items"
	k[12] = "plat"
	k[13] = "row"
	k[14] = "col"
	k[15] = "pay"
	k[16] = "ms3"
	k[17] = "tail"
	k[18] = "actms"
	k[19] = "cmx"
	k[20] = "gnote"
	return k
EndFunction

bool Function CalFileSync() Global
	{Write the whole answer set to the install file. One file write; called only where an answer
	 really changed (CalSet) and once per session close, never per poll.}
	string[] k = CalFileKeys()
	string body = "; LoreRim Glue - the dialogue-menu calibration of THIS INSTALL, written by the game (do not edit)\n"
	body += "v = 1\n"
	int i = 0
	while i < k.Length
		; IniInt reads digits only, so a NEGATIVE answer (cm=-1, rm=-3) is written as 0 = "measure
		; it again"; and a key that is a prefix of another (cm/cmx) is listed FIRST in CalFileKeys,
		; which is the order IniInt finds them in
		int v = CalInt(k[i], 0)
		if v < 0
			v = 0
		endif
		body += k[i] + " = " + v + "\n"
		i += 1
	endwhile
	return MiscUtil.WriteToFile(CalFilePath(), body, false, false)
EndFunction

int Function CalFileRestore() Global
	{Seed every key that reads 0 in THIS save from the install file. Returns how many were seeded;
	 -1 when there is no file. A key the save already answered is never overwritten.}
	string path = CalFilePath()
	if !MiscUtil.FileExists(path)
		return -1
	endif
	string body = MiscUtil.ReadFromFile(path)
	if StringUtil.GetLength(body) < 8 || IniInt(body, "v", 0) != 1
		return -1
	endif
	string[] k = CalFileKeys()
	int n = 0
	int i = 0
	while i < k.Length
		if CalInt(k[i], 0) == 0
			int v = IniInt(body, k[i], 0)
			if v != 0
				CalSetInt(k[i], v)
				n += 1
			endif
		endif
		i += 1
	endwhile
	return n
EndFunction

Function CalFileWipe() Global
	{"Forget everything it learned" reaches the install file too, or the next load would seed the
	 forgotten answers straight back.}
	MiscUtil.WriteToFile(CalFilePath(), "; LoreRim Glue calibration - forgotten\nv = 0\n", false, false)
EndFunction

bool Function Usable(float afValue) Global
	{Hard rule 10: a stored display property is usable only if it is finite, not NaN and
	 abs() < 10000. NaN fails every comparison, so this one test rejects NaN and both
	 infinities as well as an absurd value.}
	return afValue > -10000.0 && afValue < 10000.0
EndFunction

string Function ListPath() Global
	{The calibrated spelling of the topic list clip (L, or the L2 fallback).}
	if SInt("lp", 1) == 2
		return "_root.DialogueMenu_mc.TopicListHolder.List_mc"
	endif
	return "_root.DialogueMenu_mc.TopicList"
EndFunction

int Function ListPathId() Global
	{1 = _root.DialogueMenu_mc.TopicList, 2 = the TopicListHolder.List_mc fallback.}
	return SInt("lp", 1)
EndFunction

; ---------------------------------------------------------------------------
; existence / readiness
; ---------------------------------------------------------------------------
bool Function IsOpen() Global
	return UI.IsMenuOpen("Dialogue Menu")
EndFunction

int Function MenuState() Global
	{eMenuState: 0/2 = speaking, 1 = ready, 3 = animating OR parked by us (no rule may read
	 meaning into a 3). -1 = the menu is not open.}
	if !UI.IsMenuOpen("Dialogue Menu")
		return -1
	endif
	return UI.GetInt("Dialogue Menu", "_root.DialogueMenu_mc.eMenuState")
EndFunction

int Function CountRaw(int aiMode) Global
	{One entry-count mode without calibration or cross-check (probe P2 logs all three).
	 1 = EntriesA.length, 2 = entryList.length, 3 = iMaxScrollPosition + 1.}
	if !UI.IsMenuOpen("Dialogue Menu")
		return 0
	endif
	return CountRawOn(aiMode, ListPath())
EndFunction

int Function CountRawOn(int aiMode, string asList) Global
	if aiMode == 1
		return UI.GetInt("Dialogue Menu", asList + ".EntriesA.length")
	elseif aiMode == 2
		return UI.GetInt("Dialogue Menu", asList + ".entryList.length")
	elseif aiMode == 3
		; mode C is a LOWER BOUND: CalculateMaxScrollPosition yields 0 for one entry and for
		; none, which is why EntryCount() only accepts it together with a readable text.
		float mx = UI.GetFloat("Dialogue Menu", asList + ".iMaxScrollPosition")
		if !(mx > -1.0 && mx < 1000.0)
			return 0 ; missing member, NaN or nonsense
		endif
		return (mx as int) + 1
	endif
	return 0
EndFunction

string Function TextProbeOn(string asList) Global
	{Read-only "is there a list at all" test: the array-position text first, then the screen
	 row 0 text. Never writes, so it is safe in a read-only probe press.}
	string t = UI.GetString("Dialogue Menu", asList + ".EntriesA.0.text")
	if t != ""
		return t
	endif
	return UI.GetString("Dialogue Menu", asList + ".Entry0.textField.text")
EndFunction

int Function EntryCount() Global
	{Mode-calibrated entry count (design D-1 / R4). 0 = the menu is closed or the list is empty,
	 -1 = the menu is open, a text is readable and NO count mode works (why=no-count).}
	if !UI.IsMenuOpen("Dialogue Menu")
		return 0
	endif
	int cm = SInt("cm", 0)
	if cm >= 1 && cm <= 3
		int n = CountRawOn(cm, ListPath())
		if n > 0
			return n
		endif
		; A calibrated mode reporting nothing is normally the truth (the list is gone while she
		; answers), and the driver polls this every 0.1 s - so believe it unless a text is
		; readable, which would mean the mode itself broke. Without this test every empty poll
		; would pay for a full six-read re-calibration.
		if TextProbeOn(ListPath()) == ""
			return 0
		endif
	elseif cm == -1
		if TextProbeOn(ListPath()) == ""
			return 0
		endif
	endif
	; (re)calibrate over both list spellings and all three modes, cheapest first
	int lpId = 1
	while lpId <= 2
		string p = "_root.DialogueMenu_mc.TopicList"
		if lpId == 2
			p = "_root.DialogueMenu_mc.TopicListHolder.List_mc"
		endif
		int m = 1
		while m <= 3
			int n2 = CountRawOn(m, p)
			if n2 > 0 && m == 3 && TextProbeOn(p) == ""
				n2 = 0 ; mode C cannot tell one entry from none
			endif
			if n2 > 0
				SSetInt("cm", m)
				SSetInt("lp", lpId)
				return n2
			endif
			m += 1
		endwhile
		lpId += 1
	endwhile
	if TextProbeOn(ListPath()) != ""
		SSetInt("cm", -1)
		return -1 ; a list is there but nothing counts it
	endif
	return 0 ; no list yet
EndFunction

int Function SwfFamily() Global
	{2 = gate-less (Norden / Dear Diary), 1 = CHIM's gate SWF, 0 = unknown (never guess a route).}
	if !UI.IsMenuOpen("Dialogue Menu")
		return 0
	endif
	if UI.GetString("Dialogue Menu", "_global.DialogueMenu.HIDE_TOPICS") != ""
		return 2
	endif
	int m = UI.GetInt("Dialogue Menu", ListPath() + ".iMaxItemsShown")
	if m == 0
		; the list spelling may not be calibrated yet (EntryCount does that): try the other one
		; before reporting "unknown", because an unknown family means MANUAL.
		if SInt("lp", 1) == 2
			m = UI.GetInt("Dialogue Menu", "_root.DialogueMenu_mc.TopicList.iMaxItemsShown")
		else
			m = UI.GetInt("Dialogue Menu", "_root.DialogueMenu_mc.TopicListHolder.List_mc.iMaxItemsShown")
		endif
	endif
	if m == 8
		return 1
	elseif m == 13
		return 2
	endif
	return 0
EndFunction

int Function MaxItemsShown() Global
	{Laid-out entry clip count: 8 on CHIM's SWF, 13 on Norden's. Valid at ARMING.}
	if !UI.IsMenuOpen("Dialogue Menu")
		return 0
	endif
	return UI.GetInt("Dialogue Menu", ListPath() + ".iMaxItemsShown")
EndFunction

int Function Platform() Global
	{iPlatform, 1 by default. Non-zero means every repopulate re-centres the selection, which
	 is the residual window of design D-2 and the reason route A writes iScrollPosition.}
	if !UI.IsMenuOpen("Dialogue Menu")
		return 0
	endif
	return UI.GetInt("Dialogue Menu", ListPath() + ".iPlatform")
EndFunction

; ---------------------------------------------------------------------------
; reading. Mode 3 and 4 index ARRAY POSITIONS (clickable), mode 5 indexes SCREEN
; ROWS and is READ-ONLY (hard rule 11 / R5).
; ---------------------------------------------------------------------------
string Function EntryText(int aiPos, int aiMode) Global
	{Entry text. Mode 3 = EntriesA.<pos>.text, mode 4 = write iSelectedIndex then
	 selectedEntry.text (only while parked), mode 5 = Entry<row>.textField.text (READ-ONLY).}
	if !UI.IsMenuOpen("Dialogue Menu") || aiPos < 0
		return ""
	endif
	string lp = ListPath()
	if aiMode == 4
		UI.SetInt("Dialogue Menu", lp + ".iSelectedIndex", aiPos)
		return UI.GetString("Dialogue Menu", lp + ".selectedEntry.text")
	elseif aiMode == 5
		return UI.GetString("Dialogue Menu", lp + ".Entry" + aiPos + ".textField.text")
	endif
	return UI.GetString("Dialogue Menu", lp + ".EntriesA." + aiPos + ".text")
EndFunction

int Function EntryTopicIndex(int aiPos, int aiMode) Global
	{The engine's topicIndex for an entry. A topicIndex of 0 is LEGITIMATE (hard rule 6):
	 validate a read by a positive EntryCount() plus a non-empty text, never by a non-zero
	 index. -1 means the read could not be made at all.}
	if !UI.IsMenuOpen("Dialogue Menu") || aiPos < 0
		return -1
	endif
	string lp = ListPath()
	if aiMode == 4
		UI.SetInt("Dialogue Menu", lp + ".iSelectedIndex", aiPos)
		return UI.GetInt("Dialogue Menu", lp + ".selectedEntry.topicIndex")
	elseif aiMode == 5
		;/[0.4 fix pass, D9] The laid-out clip carries itemIndex, NOT topicIndex (that is why
		 EntryRowItem() exists), so the old read returned a false 0 into the parity log - exactly
		 the missing-getter trap of hard rule 6. Go through the row's array position instead./;
		int item = EntryRowItem(aiPos)
		if item < 0
			return -1
		endif
		return UI.GetInt("Dialogue Menu", lp + ".EntriesA." + item + ".topicIndex")
	endif
	return UI.GetInt("Dialogue Menu", lp + ".EntriesA." + aiPos + ".topicIndex")
EndFunction

bool Function EntryIsNew(int aiPos, int aiMode) Global
	{topicIsNew. GetBool, because the AS member is a boolean (hard rule 6).}
	if !UI.IsMenuOpen("Dialogue Menu") || aiPos < 0
		return false
	endif
	string lp = ListPath()
	if aiMode == 4
		UI.SetInt("Dialogue Menu", lp + ".iSelectedIndex", aiPos)
		return UI.GetBool("Dialogue Menu", lp + ".selectedEntry.topicIsNew")
	elseif aiMode == 5
		return UI.GetBool("Dialogue Menu", lp + ".Entry" + aiPos + ".topicIsNew")
	endif
	return UI.GetBool("Dialogue Menu", lp + ".EntriesA." + aiPos + ".topicIsNew")
EndFunction

int Function EntryColour(int aiRow) Global
	{textColor of a laid-out SCREEN ROW (dump and probe P17 only). -1 = not readable.
	 16777215 / 6316128 are the SWF's own new / seen colours, 16767334 is Smart Talk's
	 cQuestEntryColor 0xFFD966.}
	if !UI.IsMenuOpen("Dialogue Menu") || aiRow < 0
		return -1
	endif
	return UI.GetInt("Dialogue Menu", ListPath() + ".Entry" + aiRow + ".textField.textColor")
EndFunction

int Function EntryRowItem(int aiRow) Global
	{The array position a SCREEN ROW currently shows (UpdateList writes itemIndex on the clip).
	 Row k != position k on any scrolled or long list. Dump and probe only - the driver never
	 clicks a row (hard rule 11). -1 = not readable.}
	if !UI.IsMenuOpen("Dialogue Menu") || aiRow < 0
		return -1
	endif
	return UI.GetInt("Dialogue Menu", ListPath() + ".Entry" + aiRow + ".itemIndex")
EndFunction

string Function Subtitle() Global
	{The NPC subtitle. " " or "" = none.}
	if !UI.IsMenuOpen("Dialogue Menu")
		return ""
	endif
	return UI.GetString("Dialogue Menu", "_root.DialogueMenu_mc.SubtitleText.text")
EndFunction

bool Function SubtitlesOn() Global
	{The player's own subtitle toggle - never a correctness dependency (design D-5).}
	return Utility.GetINIBool("bDialogueSubtitles:Interface")
EndFunction

int Function ProgressTimerId() Global
	{iAllowProgressTimerID. Re-assigned by clearInterval/setInterval on every
	 StartProgressTimer, so a CHANGED value means "a line started" (R2 / R7).
	 -1 = the menu is not open. Probe P1 decides whether it moves per line or per session.}
	if !UI.IsMenuOpen("Dialogue Menu")
		return -1
	endif
	return UI.GetInt("Dialogue Menu", "_root.DialogueMenu_mc.iAllowProgressTimerID")
EndFunction

bool Function GateArmed() Global
	{timerBool - CHIM's click gate. Family 1 only; always false on a gate-less SWF.}
	if !UI.IsMenuOpen("Dialogue Menu")
		return false
	endif
	return UI.GetBool("Dialogue Menu", "_root.DialogueMenu_mc.timerBool")
EndFunction

; ---------------------------------------------------------------------------
; selecting and clicking
; ---------------------------------------------------------------------------
bool Function SelectAndVerify(int aiPos, int aiTopicIndex, string asTextPrefix, bool abAlsoScroll) Global
	{Select an ARRAY POSITION and verify what is now selected really is that entry.
	 false = STALE, the caller must NOT click.}
	; Defence against F7: SetSelectedTopic scans for a matching topicIndex and an unknown one
	; silently leaves entry 0 selected. With aiTopicIndex < 0 AND an empty prefix this is a
	; bare selection write that does NOT authorise a click.
	SSetInt("sti", -1)
	SSetStr("stx", "")
	SSetFloat("sat", -1.0)
	if !UI.IsMenuOpen("Dialogue Menu") || aiPos < 0
		return false
	endif
	string lp = ListPath()
	UI.SetInt("Dialogue Menu", lp + ".iSelectedIndex", aiPos)
	if abAlsoScroll
		; route A only: onSelectionClick recentres through UpdateList when the scroll position
		; and the selection disagree, and UpdateList overwrites iSelectedIndex when
		; iPlatform != 0 (live default 1).
		UI.SetInt("Dialogue Menu", lp + ".iScrollPosition", aiPos)
	endif
	if asTextPrefix != ""
		if StringUtil.Find(UI.GetString("Dialogue Menu", lp + ".selectedEntry.text"), asTextPrefix) != 0
			return false
		endif
	endif
	if aiTopicIndex >= 0
		if UI.GetInt("Dialogue Menu", lp + ".selectedEntry.topicIndex") != aiTopicIndex
			return false
		endif
	endif
	if aiTopicIndex >= 0 || asTextPrefix != ""
		; remember the verified identity: Click() re-checks it as the LAST frame-synced pair
		; before the queued Invoke (design 1.7, ENG-O11), and the signatures are frozen, so
		; the expectation cannot travel as an argument.
		SSetInt("sti", aiTopicIndex)
		SSetStr("stx", asTextPrefix)
		SSetFloat("sat", Utility.GetCurrentRealTime())
	endif
	return true
EndFunction

Function Click(int aiRoute) Global
	{Route 2 = B: eMenuState 2, read back, Invoke topicClicked. Route 1 = A: eMenuState 1, read
	 back, InvokeBool onSelectionClick false. A failed check clicks NOTHING - see ClickResult().}
	; Exactly ONE queued Invoke, and the identity pair is the last frame-synced work before it
	; (design 1.7 / ENG-O11): Get*/Set* are delayed natives, only Invoke* is queued.
	SSetInt("clk", 0)
	if !UI.IsMenuOpen("Dialogue Menu")
		SSetInt("clk", -4)
		return
	endif
	if aiRoute != 1 && aiRoute != 2
		SSetInt("clk", -4)
		return
	endif
	float at = SFloat("sat", -1.0)
	float now = Utility.GetCurrentRealTime()
	if at < 0.0 || (now - at) > 2.0 || (now - at) < -1.0
		SSetInt("clk", -3) ; no fresh verified selection: never click on trust
		return
	endif
	string lp = ListPath()
	int want = SInt("sti", -1)
	string pre = SStr("stx", "")
	int wantState = 2
	if aiRoute == 1
		wantState = 1
	endif
	UI.SetInt("Dialogue Menu", "_root.DialogueMenu_mc.eMenuState", wantState)
	if UI.GetInt("Dialogue Menu", "_root.DialogueMenu_mc.eMenuState") != wantState
		SSetInt("clk", -1) ; the read-back gate of SWF G2
		return
	endif
	; --- the identity pair, last before the queued Invoke -------------------------------
	if pre != ""
		if StringUtil.Find(UI.GetString("Dialogue Menu", lp + ".selectedEntry.text"), pre) != 0
			SSetInt("clk", -2)
			return
		endif
	endif
	if want >= 0
		if UI.GetInt("Dialogue Menu", lp + ".selectedEntry.topicIndex") != want
			SSetInt("clk", -2)
			return
		endif
	endif
	; NOTE on an abort after this point: nothing can abort after this point. On an abort ABOVE,
	; the menu is left in the click state; the caller's ShowList() is the recovery (design 1.10).
	SSetFloat("sat", -1.0) ; one click per verified selection, never a second
	if aiRoute == 2
		UI.Invoke("Dialogue Menu", "_root.DialogueMenu_mc.topicClicked")
		SSetInt("clk", 1)
	else
		; UI.Invoke is a wrapper that always passes false; InvokeBool(..., false) is the
		; explicit form. A truthy argument is forbidden (hard rule 3).
		UI.InvokeBool("Dialogue Menu", "_root.DialogueMenu_mc.onSelectionClick", false)
		SSetInt("clk", 2)
	endif
EndFunction

int Function ClickResult() Global
	{1 = invoked route B, 2 = invoked route A, 0 = never tried. Negative = nothing was clicked:
	 -1 state read-back failed, -2 identity mismatch, -3 no fresh selection, -4 closed/route.}
	; Additive to design 1.2, because Click() is void and cannot report an abort.
	return SInt("clk", 0)
EndFunction

int Function StateWriteBack(int aiValue) Global
	{Write eMenuState and return what a read gives back. No click, no Invoke. [v1.0] Its only
	 caller (probe P10 / active test A2) is retired; kept unused, like every primitive here.}
	if !UI.IsMenuOpen("Dialogue Menu")
		return -1
	endif
	UI.SetInt("Dialogue Menu", "_root.DialogueMenu_mc.eMenuState", aiValue)
	return UI.GetInt("Dialogue Menu", "_root.DialogueMenu_mc.eMenuState")
EndFunction

; ---------------------------------------------------------------------------
; hiding / guarding / closing
; ---------------------------------------------------------------------------
bool Function Hide(int aiMode) Global
	{1 = topics-only (default: the subtitle stays readable), 2 = full (probe only). An unusable
	 original hides NOTHING and returns false.}
	; The originals are read as Papyrus FLOATS and validated (hard rules 9 + 10). A session that
	; cannot store them runs assisted rather than risking a menu that cannot be given back.
	if !UI.IsMenuOpen("Dialogue Menu")
		return false
	endif
	int fam = SwfFamily()
	float hx = UI.GetFloat("Dialogue Menu", "_root.DialogueMenu_mc.TopicListHolder._x")
	if hx > 4000.0 && SInt("hid", 0) == 1 && SInt("hok", 0) == 1 && SInt("hfam", -1) == fam
		; the list is ALREADY off-screen because we put it there in this same open: re-apply,
		; but never overwrite the stored originals with our own 5000 - that would make Unhide()
		; "restore" the hidden position and the emergency key would hand back an empty menu.
		ReassertHide()
		return true
	endif
	float hy = UI.GetFloat("Dialogue Menu", "_root.DialogueMenu_mc.TopicListHolder._y")
	float ex = UI.GetFloat("Dialogue Menu", "_root.DialogueMenu_mc.ExitButton._x")
	if !Usable(hx) || !Usable(hy) || !Usable(ex)
		; Norden's SWF writes TopicListHolder._x relatively from an asynchronous config load,
		; so it can still be NaN on an early open.
		SSetInt("hok", 0)
		SSetInt("hid", 0)
		return false
	endif
	SSetFloat("hx", hx)
	SSetFloat("hy", hy)
	SSetFloat("hex", ex)
	SSetInt("hfam", fam)
	SSetInt("hok", 1)
	SSetInt("hmd", aiMode)
	SSetInt("hid", 1)
	if aiMode == 2
		UI.SetBool("Dialogue Menu", "_root.DialogueMenu_mc._visible", false)
		return true
	endif
	; never _visible / _alpha on the topic list: the SWF's InitExtensions and IACC rewrite
	; both every frame. Move it with _x (hard rule 4).
	UI.SetFloat("Dialogue Menu", "_root.DialogueMenu_mc.TopicListHolder._x", 5000.0)
	UI.SetFloat("Dialogue Menu", "_root.DialogueMenu_mc.ExitButton._x", 5000.0)
	UI.SetBool("Dialogue Menu", "_root.DialogueMenu_mc.SpeakerName._visible", false)
	return true
EndFunction

Function ReassertHide() Global
	{Re-read the hidden values and rewrite any that were lost (IACC re-applies its whole
	 DisplayInfo block, and the movie can be rebuilt). Cheap: two reads, a write only on loss.}
	if !UI.IsMenuOpen("Dialogue Menu") || SInt("hid", 0) != 1
		return
	endif
	if SInt("hmd", 1) == 2
		if UI.GetBool("Dialogue Menu", "_root.DialogueMenu_mc._visible")
			UI.SetBool("Dialogue Menu", "_root.DialogueMenu_mc._visible", false)
		endif
		return
	endif
	; the negated test also rewrites on a NaN read
	if !(UI.GetFloat("Dialogue Menu", "_root.DialogueMenu_mc.TopicListHolder._x") > 4000.0)
		UI.SetFloat("Dialogue Menu", "_root.DialogueMenu_mc.TopicListHolder._x", 5000.0)
	endif
	if !(UI.GetFloat("Dialogue Menu", "_root.DialogueMenu_mc.ExitButton._x") > 4000.0)
		UI.SetFloat("Dialogue Menu", "_root.DialogueMenu_mc.ExitButton._x", 5000.0)
	endif
EndFunction

Function HideCursor(bool abHide) Global
	{The cursor lives in its own menu movie. [V name, U effect - probe P6 reads it back.]}
	UI.SetBool("Cursor Menu", "_root.mc_Cursor._visible", !abHide)
EndFunction

Function Guard(bool abOn) Global
	{G1 + G2. ON is SetInt ALLOW_PROGRESS_DELAY 100000000 and THEN Invoke StartProgressTimer,
	 in that order, because the delay is read when the timer is ARMED and the greeting already
	 armed a 750 ms one. OFF resets the delay to 750 first (GuardReset) so the next line
	 re-enables input.}
	if !UI.IsMenuOpen("Dialogue Menu")
		return
	endif
	if abOn
		UI.SetInt("Dialogue Menu", "_global.DialogueMenu.ALLOW_PROGRESS_DELAY", 100000000)
		UI.Invoke("Dialogue Menu", "_root.DialogueMenu_mc.StartProgressTimer")
		UI.SetBool("Dialogue Menu", ListPath() + ".bDisableInput", true)
	else
		GuardReset()
		UI.SetBool("Dialogue Menu", ListPath() + ".bDisableInput", false)
		UI.SetBool("Dialogue Menu", "_root.DialogueMenu_mc.bAllowProgress", true)
	endif
EndFunction

Function GuardReset() Global
	{R1 / G0: put ALLOW_PROGRESS_DELAY back to its default 750 and re-arm the timer with it.
	 Idempotent, two natives: the driver calls it at the top of EVERY arming on EVERY branch.}
	; Without it a leaked 100000000 would leave the player's next VANILLA dialogue unclickable
	; by mouse, E and Enter - only TAB would close it. Probe P11 says whether it can leak.
	if !UI.IsMenuOpen("Dialogue Menu")
		return
	endif
	UI.SetInt("Dialogue Menu", "_global.DialogueMenu.ALLOW_PROGRESS_DELAY", 750)
	UI.Invoke("Dialogue Menu", "_root.DialogueMenu_mc.StartProgressTimer")
EndFunction

Function GuardPoke() Global
	{G1b: ONE native, on every poll in every state - the counter to Smart Talk's native
	 bAllowProgress = true. Deliberately without an IsOpen() test: that would double the cost
	 of the cheapest thing in the loop, and a Set on a closed menu reaches nothing.}
	UI.SetBool("Dialogue Menu", "_root.DialogueMenu_mc.bAllowProgress", false)
EndFunction

Function Park() Global
	{G3: hold eMenuState at 3, ONLY from 1, and ONLY inside the click window. While parked a
	 list the engine pushes is DROPPED, not deferred, so the driver must unpark with ShowList()
	 as soon as the count or the signature changes.}
	if UI.GetInt("Dialogue Menu", "_root.DialogueMenu_mc.eMenuState") == 1
		UI.SetInt("Dialogue Menu", "_root.DialogueMenu_mc.eMenuState", 3)
	endif
EndFunction

Function ShowList() Global
	{The reliable un-park / re-show: ShowDialogueList sets TopicListHolder._visible = true,
	 plays fadeListIn, and its frame-33 script sets menuState = TOPIC_LIST_SHOWN by itself.
	 A manual eMenuState = 1 (ForceListState) is only ever the SECOND attempt.}
	if !UI.IsMenuOpen("Dialogue Menu")
		return
	endif
	UI.InvokeBool("Dialogue Menu", "_root.DialogueMenu_mc.ShowDialogueList", false)
EndFunction

Function ForceListState() Global
	{Second attempt only, ~0.5 s after ShowList() left the state somewhere other than 1
	 (design 1.10 step 4). Additive to design 1.2, which names the write but no primitive.}
	if !UI.IsMenuOpen("Dialogue Menu")
		return
	endif
	UI.SetInt("Dialogue Menu", "_root.DialogueMenu_mc.eMenuState", 1)
EndFunction

Function Unhide() Global
	{U1, the single recovery primitive (design 1.10): release guard and cursor, restore the
	 VALIDATED stored floats, make menu, subtitle and speaker name visible, re-show the list.}
	; Nothing is written when a stored value was never read successfully; LastUnhide() then
	; says 2 and the caller reports why=no-stored-x. ShowList() runs only when entries exist
	; and the state is not 1 - after a service menu the engine's own list was dropped.
	SSetInt("unh", 0)
	if !UI.IsMenuOpen("Dialogue Menu")
		SSetInt("hid", 0)
		SSetInt("unh", 3)
		return
	endif
	Guard(false)
	HideCursor(false)
	float hx = SFloat("hx", 99999.0)
	float hy = SFloat("hy", 99999.0)
	float ex = SFloat("hex", 99999.0)
	;/[0.4 fix pass, D1] The family test used to be an equality: SwfFamily() returns 0 whenever
	 HIDE_TOPICS is empty AND iMaxItemsShown reads 0, so an unreadable family made the restore
	 refuse and left the list at _x = 5000 with nothing able to bring it back. 0 means UNKNOWN,
	 not "a different SWF": only a DIFFERENT KNOWN family may veto the restore now. The stored
	 floats are still validated (rule 9/10) - that guard is untouched./;
	int famNow = SwfFamily()
	int famWas = SInt("hfam", -1)
	bool ok = SInt("hok", 0) == 1 && Usable(hx) && Usable(hy) && Usable(ex)
	if ok && famNow != 0 && famWas >= 0 && famWas != famNow
		ok = false
	endif
	if ok
		UI.SetFloat("Dialogue Menu", "_root.DialogueMenu_mc.TopicListHolder._x", hx)
		UI.SetFloat("Dialogue Menu", "_root.DialogueMenu_mc.TopicListHolder._y", hy)
		UI.SetFloat("Dialogue Menu", "_root.DialogueMenu_mc.ExitButton._x", ex)
		if famNow == 0
			SSetInt("unh", 4) ; restored although the family could not be read - see LastUnhide()
		else
			SSetInt("unh", 1)
		endif
	else
		; hard rule 10: refuse to restore an unusable value. UI.GetInt is (UInt32)number, so a
		; negative _x would come back as ~4.29e9 and the "restore" would put the list
		; 4,294,966,976 px off-screen - the emergency key producing the state it prevents.
		SSetInt("unh", 2)
	endif
	; StartHideMenu clears these three itself, so they are restored unconditionally
	UI.SetBool("Dialogue Menu", "_root.DialogueMenu_mc._visible", true)
	UI.SetBool("Dialogue Menu", "_root.DialogueMenu_mc.SubtitleText._visible", true)
	UI.SetBool("Dialogue Menu", "_root.DialogueMenu_mc.SpeakerName._visible", true)
	SSetInt("hid", 0)
	if EntryCount() > 0 && MenuState() != 1
		ShowList()
	endif
EndFunction

int Function LastUnhide() Global
	{1 = the stored originals were restored, 2 = no usable stored value, nothing was written
	 (the caller reports why=no-stored-x), 3 = the menu was closed, 4 = restored although the
	 SWF family could not be read, 0 = Unhide() never ran.}
	return SInt("unh", 0)
EndFunction

bool Function IsHidden() Global
	{True while this script is holding the list off-screen (or invisible in mode 2).}
	return SInt("hid", 0) == 1
EndFunction

Function CloseClean() Global
	{The menu's own close path: StartHideMenu -> GameDelegate.call("CloseMenu").}
	if !UI.IsMenuOpen("Dialogue Menu")
		return
	endif
	UI.Invoke("Dialogue Menu", "_root.DialogueMenu_mc.StartHideMenu")
EndFunction

Function CloseForce(int aiStep) Global
	{Step 1 = _global.skse.CloseMenu, step 2 = PO3 HideMenu.}
	; [v1.0] UNCALLED: its only caller, probe press P11b, is retired with the presses. Kept as a
	; primitive; any future caller owes the old rule - only on a harmless non-critical closed
	; layer, never on a guard, never during an arrest. The driver's escalation ends at MANUAL.
	if !UI.IsMenuOpen("Dialogue Menu")
		return
	endif
	if aiStep == 1
		UI.InvokeString("HUD Menu", "_global.skse.CloseMenu", "Dialogue Menu")
	elseif aiStep == 2
		PO3_SKSEFunctions.HideMenu("Dialogue Menu")
	endif
EndFunction

; ---------------------------------------------------------------------------
; calibration accessors (lane B owns persistence into MCM: a Hidden script keeps
; nothing across a save, and StorageUtil is per-save, not per-profile)
; ---------------------------------------------------------------------------
int Function ReadMode() Global
	{The calibrated read mode, 3 or 4. 0 = not calibrated yet.}
	return SInt("rm", 0)
EndFunction

Function SetReadMode(int aiMode) Global
	{3 or 4 only. Mode 5 indexes screen rows and can never feed a click, so it is refused
	 here as well as in the MCM (hard rule 11 / R5).}
	if aiMode == 3 || aiMode == 4 || aiMode == 0
		SSetInt("rm", aiMode)
	endif
EndFunction

int Function ReadModeAuto() Global
	{The calibrated read mode, calibrating first if necessary. 0 = neither mode works.}
	int m = SInt("rm", 0)
	if m == 3 || m == 4
		return m
	endif
	return CalibrateReadMode()
EndFunction

int Function CalibrateReadMode() Global
	{Try mode 3 on position 0; if it is empty while a list exists, try mode 4. Stores and
	 returns 3, 4 or 0. Mode 4 writes iSelectedIndex, so the caller should hold the park.}
	if !UI.IsMenuOpen("Dialogue Menu")
		return 0
	endif
	if EntryCount() <= 0
		return 0
	endif
	if EntryText(0, 3) != ""
		SSetInt("rm", 3)
		return 3
	endif
	if EntryText(0, 4) != ""
		SSetInt("rm", 4)
		return 4
	endif
	SSetInt("rm", 0)
	return 0
EndFunction

int Function CountMode() Global
	{The calibrated count mode: 1 = EntriesA.length, 2 = entryList.length,
	 3 = iMaxScrollPosition + 1, 0 = not calibrated, -1 = no mode works.}
	return SInt("cm", 0)
EndFunction

Function SetCountMode(int aiMode) Global
	if aiMode >= -1 && aiMode <= 3
		SSetInt("cm", aiMode)
	endif
EndFunction

Function ResetCalibration() Global
	{Forget the read mode, the count mode, the list spelling AND any latched hide (probe, and a new
	 game session).}
	SSetInt("cm", 0)
	SSetInt("rm", 0)
	SSetInt("lp", 1)
	;/[0.5.1 pt9 go-live, G7] THE LATCHED HIDE. hid / hok / hfam had no load-time reset at all, so a
	 hide lost to a dumped loop stack, a quit or a crash - HardReset() only calls Unhide() while the
	 menu is still OPEN, and a menu that closed first leaves hid = 1 behind, which is exactly what G1's
	 TAB exit produces - made IsHidden() return true for every menu for the rest of the save. Every
	 MANUAL transition and every Finish() then performed a full DoUnhide (two WaitMenuMode(0.5), a guard
	 release, a ShowList, an ev=unhide) on a perfectly healthy vanilla menu, and the probe refused every
	 active calibration test for ever with driver-hidden. Hide() re-reads and re-validates the stored
	 floats on every use, so clearing these three here costs nothing./;
	SSetInt("hid", 0)
	SSetInt("hok", 0)
	SSetInt("hfam", -1)
EndFunction

; ---------------------------------------------------------------------------
; preconditions
; ---------------------------------------------------------------------------
bool Function SmartTalkSafe() Global
	{False while Smart Talk may skip a line natively, or when the ini cannot be read. Live
	 values are 1 / 1 / 0, so this is FALSE today - the machine-checked precondition of D-21.}
	; Offenders: bSkipImmediateOnInput, bHoldToSkip, bSkipOnInteraction. An unreadable ini
	; leaves the owner's MCM acknowledgement as the only way out of dry-run.
	return SmartTalkOffender() == ""
EndFunction

string Function SmartTalkOffender() Global
	{The name of the first offending Smart Talk setting, "unreadable" when the ini cannot be
	 read, "" when everything is 0. One file read; the answer is also kept in the store so a
	 diagnostics page can ask twice for free.}
	string path = "Data/SKSE/Plugins/SmartTalk.ini"
	if !MiscUtil.FileExists(path)
		SSetStr("sto", "unreadable")
		return "unreadable"
	endif
	string body = MiscUtil.ReadFromFile(path)
	if StringUtil.GetLength(body) < 16
		SSetStr("sto", "unreadable")
		return "unreadable"
	endif
	string bad = ""
	if IniInt(body, "bSkipImmediateOnInput", -1) > 0
		bad = "bSkipImmediateOnInput"
	elseif IniInt(body, "bHoldToSkip", -1) > 0
		bad = "bHoldToSkip"
	elseif IniInt(body, "bSkipOnInteraction", -1) > 0
		bad = "bSkipOnInteraction"
	endif
	SSetStr("sto", bad)
	return bad
EndFunction

int Function SmartTalkSetting(string asKey, int aiDefault) Global
	{One integer out of SmartTalk.ini (the MCM help quotes iPapyrusHandle, live 3, which must
	 never be changed). Returns the default when the file or the key is missing.}
	string path = "Data/SKSE/Plugins/SmartTalk.ini"
	if !MiscUtil.FileExists(path)
		return aiDefault
	endif
	return IniInt(MiscUtil.ReadFromFile(path), asKey, aiDefault)
EndFunction

int Function IniInt(string asBody, string asKey, int aiDefault) Global
	{Read "<key> = <int>" out of an ini BODY. Only an occurrence at the start of a line counts,
	 so a commented mention ("# bHoldToSkip ...") is ignored. No StringUtil.Split: SKSE string
	 arrays cap at 128 entries and this file has more lines than that.}
	int from = 0
	int p = StringUtil.Find(asBody, asKey, from)
	int guard = 0
	while p >= 0 && guard < 64
		bool lineStart = p == 0
		if p > 0
			string c = StringUtil.GetNthChar(asBody, p - 1)
			; Papyrus knows no \r escape: CR comes from its character code
			lineStart = c == "\n" || c == StringUtil.AsChar(13)
		endif
		if lineStart
			int v = IniDigitsAt(asBody, p + StringUtil.GetLength(asKey))
			if v >= 0
				return v
			endif
			return aiDefault
		endif
		from = p + 1
		p = StringUtil.Find(asBody, asKey, from)
		guard += 1
	endwhile
	return aiDefault
EndFunction

int Function IniDigitsAt(string asBody, int aiFrom) Global
	{The first run of digits after aiFrom, stopping at the end of the line. -1 = none.}
	int i = aiFrom
	int n = StringUtil.GetLength(asBody)
	int guard = 0
	string digits = ""
	bool done = false
	string cr = StringUtil.AsChar(13)
	while !done && i < n && guard < 48
		string c = StringUtil.GetNthChar(asBody, i)
		if c == "\n" || c == cr
			done = true
		elseif StringUtil.IsDigit(c)
			digits += c
		elseif digits != ""
			done = true
		endif
		i += 1
		guard += 1
	endwhile
	if digits == ""
		return -1
	endif
	return digits as int
EndFunction

; ---------------------------------------------------------------------------
; probe-support reads. LRG_DlgProbe must contain no UI.* call of its own, so every
; raw value it needs has its own accessor here. All read-only. [v1.0] The presses
; that used most of them are retired; the accessors stay, unused, as primitives.
; ---------------------------------------------------------------------------
int Function HolderXInt() Global
	{TopicListHolder._x read the WRONG way on purpose (probe P6): UI.GetInt is (UInt32)number,
	 so a negative _x reads back as ~4.29e9. The two readings differing is the proof of rule 9.}
	if !UI.IsMenuOpen("Dialogue Menu")
		return 0
	endif
	return UI.GetInt("Dialogue Menu", "_root.DialogueMenu_mc.TopicListHolder._x")
EndFunction

float Function HolderX() Global
	if !UI.IsMenuOpen("Dialogue Menu")
		return 0.0
	endif
	return UI.GetFloat("Dialogue Menu", "_root.DialogueMenu_mc.TopicListHolder._x")
EndFunction

float Function HolderY() Global
	if !UI.IsMenuOpen("Dialogue Menu")
		return 0.0
	endif
	return UI.GetFloat("Dialogue Menu", "_root.DialogueMenu_mc.TopicListHolder._y")
EndFunction

float Function ExitButtonX() Global
	if !UI.IsMenuOpen("Dialogue Menu")
		return 0.0
	endif
	return UI.GetFloat("Dialogue Menu", "_root.DialogueMenu_mc.ExitButton._x")
EndFunction

bool Function ExitButtonShown() Global
	if !UI.IsMenuOpen("Dialogue Menu")
		return false
	endif
	return UI.GetBool("Dialogue Menu", "_root.DialogueMenu_mc.ExitButton._visible")
EndFunction

bool Function SubtitleShown() Global
	if !UI.IsMenuOpen("Dialogue Menu")
		return false
	endif
	return UI.GetBool("Dialogue Menu", "_root.DialogueMenu_mc.SubtitleText._visible")
EndFunction

bool Function SpeakerNameShown() Global
	if !UI.IsMenuOpen("Dialogue Menu")
		return false
	endif
	return UI.GetBool("Dialogue Menu", "_root.DialogueMenu_mc.SpeakerName._visible")
EndFunction

bool Function CursorShown() Global
	return UI.GetBool("Cursor Menu", "_root.mc_Cursor._visible")
EndFunction

bool Function AllowProgress() Global
	{bAllowProgress - false while the guard holds. SetAllowProgress is the only writer of true,
	 and Smart Talk writes it natively, which is what GuardPoke() counters.}
	if !UI.IsMenuOpen("Dialogue Menu")
		return false
	endif
	return UI.GetBool("Dialogue Menu", "_root.DialogueMenu_mc.bAllowProgress")
EndFunction

int Function AllowProgressDelay() Global
	{ALLOW_PROGRESS_DELAY on the class static. Probe P11 reads it on a HAND-opened menu before
	 anything writes it: it must read 750, not 100000000. -1 = the menu is not open.}
	if !UI.IsMenuOpen("Dialogue Menu")
		return -1
	endif
	return UI.GetInt("Dialogue Menu", "_global.DialogueMenu.ALLOW_PROGRESS_DELAY")
EndFunction

bool Function DisableInput() Global
	if !UI.IsMenuOpen("Dialogue Menu")
		return false
	endif
	return UI.GetBool("Dialogue Menu", ListPath() + ".bDisableInput")
EndFunction

int Function SelectedIndex() Global
	{iSelectedIndex - the engine pre-selects an entry on every new list, so this is never -1
	 for long. -1 = the menu is not open.}
	if !UI.IsMenuOpen("Dialogue Menu")
		return -1
	endif
	return UI.GetInt("Dialogue Menu", ListPath() + ".iSelectedIndex")
EndFunction

int Function SelectedTopicIndex() Global
	if !UI.IsMenuOpen("Dialogue Menu")
		return -1
	endif
	return UI.GetInt("Dialogue Menu", ListPath() + ".selectedEntry.topicIndex")
EndFunction

string Function SelectedText() Global
	if !UI.IsMenuOpen("Dialogue Menu")
		return ""
	endif
	return UI.GetString("Dialogue Menu", ListPath() + ".selectedEntry.text")
EndFunction

string Function HideTopicsVar() Global
	{_global.DialogueMenu.HIDE_TOPICS: non-empty only on the gate-less SWF family.}
	if !UI.IsMenuOpen("Dialogue Menu")
		return ""
	endif
	return UI.GetString("Dialogue Menu", "_global.DialogueMenu.HIDE_TOPICS")
EndFunction

bool Function TextInputOn() Global
	{Scaleform text-input mode (CHIM's Prisma chatbox, UITextEntryMenu, the console). A key
	 handler must return early on a true. Here, because every UI.* call lives in this file.}
	return UI.IsTextInputEnabled()
EndFunction

bool Function OtherMenuOpen(string asMenuName) Global
	{Any other menu by name (probe P15 watches BarterMenu). Here, because every UI.* call in
	 the project lives in this file.}
	return UI.IsMenuOpen(asMenuName)
EndFunction

bool Function CreateProbeClip() Global
	{Probe P12: can a movie clip be created from Papyrus at all (the helper-SWF question,
	 decision D1 plan C)? Creates _root.lrgProbe and returns whether it can be read back.}
	if !UI.IsMenuOpen("Dialogue Menu")
		return false
	endif
	string[] args = new string[2]
	args[0] = "lrgProbe"
	args[1] = "9731"
	UI.InvokeStringA("Dialogue Menu", "_root.createEmptyMovieClip", args)
	bool made = ProbeClipName() != ""
	;/[v0.5 fix pass, defect m7] TAKE IT BACK OUT. createEmptyMovieClip REPLACES whatever already
	 occupies that depth on _root, and nothing in the feature uses the clip: it settles one design
	 question (decision D1 plan C, "could a helper SWF exist at all?") and is then dead weight
	 sitting in the live Dialogue Menu of an ordinary conversation with no removal path anywhere
	 in the project. One extra native, once per install, on an opt-in test./;
	UI.Invoke("Dialogue Menu", "_root.lrgProbe.removeMovieClip")
	return made
EndFunction

string Function ProbeClipName() Global
	if !UI.IsMenuOpen("Dialogue Menu")
		return ""
	endif
	return UI.GetString("Dialogue Menu", "_root.lrgProbe._name")
EndFunction
