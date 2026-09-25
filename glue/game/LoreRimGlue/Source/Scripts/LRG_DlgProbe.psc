Scriptname LRG_DlgProbe extends Quest
{LoreRim Glue - the dialogue-menu CALIBRATION of the menuless questing module (v1.0). Four
 passive rows learned from the first dialogue menu of any kind, a handful of measurements, and
 the click-route proof the driver writes after its first verified click. It never touches the
 menu. Every verdict is one greppable CALIB line through LRG_Main.LogC.}

;/ =============================================================================================
 v1.0 - THE CALIBRATION (spec pt19 S3; PROTOCOL 10.13)

 WHAT IT IS. One collector, PASSIVE only. LRG_Dialogue calls the hooks below from its own loop
 (CalArm / CalLayer / CalPoll / CalSpeech / CalClose, and CalPayload / CalOpened / CalNewOpen /
 CalDirty); each READS what the driver already holds or one bounded UI read through LRG_DlgUI,
 and NEVER writes to the menu or clicks anything (hard rail CAL-1). Every collector short-
 circuits once its key is answered, so a calibrated install pays nothing per poll.

 THE GATE. FOUR rows, all written on the first menu of ANY kind (an E-press counts):
   cm   counting   (CalLayer mirrors the UI layer's own count mode)
   rm   reading    (CalLayer -> CalReadProbe: mode 3, or the mode LRG_DlgUI proved)
   fam  layout     (CalArm: the SWF family the driver read at arming)
   st   Smart Talk (CalArm: SmartTalk.ini read once while red)
 CalGreen() = all four. CalMissing() / CalAnswered() ("N of 4") / CalStatusText() are what the
 driver and the MCM read. MEASUREMENTS, never gates: timer (CalPoll, one native every 5th poll
 until answered), x1 (CalSpeech / CalX1Poll / CalX1Verdict - log only), apd (read once at
 arming), ms3, tail, col, row, pay, actms.

 THE ROUTE IS PROVEN BY DOING (S3.2). The driver starts from its own fam table and, when the
 first click it made shows RESPONDING, calls CalSet("route", r, "live"). That call also writes
 rproof = r and counts rclicks, so CalRouteLive() - "route > 0 and proven by a real click on this
 install" - is what lets a journal-scene menu be driven. Any other writer of route clears it.
 "The menu is learned" is said once per INSTALL (CalGreenNote, key gnote); what talking cannot
 fix is named, not "talk to anyone once" (CalStuckWhy / CalLearnable).

 WHERE THE ANSWERS LIVE. StorageUtil "LRG.cal.*" through LRG_DlgUI.CalInt / CalSetInt, mirrored
 to the install file Data/SKSE/Plugins/LoreRimGlue_calibration.ini (LRG_DlgUI.CalFileKeys) so a
 reload of another save does not relearn. CalForget() wipes both and tells the server
 (ev=calib reset=1), which clears its clicks_ok proof on the *install* row.

 RETIRED IN v1.0: the first-playtest probe presses and hotkey, the opt-in active pass (A1-A5),
 the manual click / re-open presses, the automatic calibration session, and the rows hide,
 guard, rb, reopen and park (spec section 4).
 ============================================================================================= /;

; --- calibration, per GAME session (reset in Maintenance, which runs on every load) ---------
int calSid = 0               ; the LRG_Main session tag this state belongs to
float cStAt = -1.0           ; when SmartTalk.ini was last read (real time restarts at 0 on load)
bool cGreenSaid = false      ; the first list of this game session was checked for an already-green gate
; --- calibration, per DIALOGUE session (reset in CalArm) ------------------------------------
bool cOpen = false           ; a session is armed and not yet closed
string cNpcName = ""         ; the speaker, resolved once per session so no poll costs a native
int cLayers = 0              ; layers seen this session; 1 = the root list
int cLastN = 0
int cPollN = 0
int cTimerLast = -1
int cTimerN = 0              ; distinct progress-timer ids seen this session
int cSubN = 0                ; subtitle changes seen this session (the timer's other half)
string cSubLast = ""
; --- X1: does a choice layer survive the player speaking? (a measurement, log only) ---------
bool cX1Armed = false
float cX1At = 0.0
int cX1N = 0
int cX1Layer = 0
int cX1Talking = -1
string cX1Route = "none"

; ---------------------------------------------------------------------------
; lifecycle
; ---------------------------------------------------------------------------
Event OnInit()
	;/[0.5.5] Registers and returns - nothing else: a script is closed to every other stack until its
	 OnInit returns, and an OnInit that called into LRG_Main is what froze the 0.5.4 saves. The boot
	 itself is the LRG_Boot mod event (OnLrgBoot), sent by LRG_Main's boot queue./;
	RegisterForModEvent("LRG_Boot", "OnLrgBoot")
EndEvent

Event OnLrgBoot(string asWho, int aiSess)
	;/[0.5.5] The boot entry point (LRG_Main boot step 7). The same event reaches every script on the
	 form: only our own name is for us./;
	if asWho != "LRG_DlgProbe"
		return
	endif
	Maintenance()
	LRG_Main m = Main()
	if m
		m.BootConfirm(2, aiSess)
	endif
EndEvent

Function Maintenance()
	{Idempotent. Called by OnLrgBoot on every load, after LRG_Main's form-wide
	 UnregisterForAllModEvents() and RegisterKeys(). Resets the per-game-session state once per
	 session and seeds unanswered keys from the install file. Registers nothing.}
	LRG_Main m = Main()
	;/[v0.5 E1] Utility.GetCurrentRealTime() restarts at 0 on every launch, so a timestamp kept in
	 the save would sit in the "future" and block a collector for hours: the per-GAME-session state
	 is reset here, ONLY on a new game session (SessionTag() is re-rolled in LRG_Main.Maintenance(),
	 which runs BEFORE this one). [v1.0] The probe key, the probe's menu listener and its mod event
	 are gone with the presses; a registration an older save still carries reaches no handler here./;
	int sidNow = 0
	if m
		sidNow = m.SessionTag()
	endif
	if sidNow == 0 || sidNow != calSid
		calSid = sidNow
		cOpen = false
		cNpcName = ""
		cX1Armed = false
		cStAt = -1.0
		cGreenSaid = false
		;/[pt18] THE INSTALL FILE. The LRG.cal.* store is per save: whichever save the owner loads
		 carries whatever THAT save had (the same rows were relearned from zero three times on
		 2026-09-23). Every unanswered key is seeded from the file the game wrote on the last save
		 that learned something; an answered key always wins./;
		int seeded = LRG_DlgUI.CalFileRestore()
		if seeded > 0 && m
			m.LogC("", "CALIB restored from the install file: " + seeded + " keys (" \
				+ LRG_DlgUI.CalFilePath() + ")", "")
		endif
	endif
EndFunction

LRG_Main Function Main()
	return (self as Quest) as LRG_Main
EndFunction

; ---------------------------------------------------------------------------
; small helpers
; ---------------------------------------------------------------------------
Actor Function Speaker()
	Actor a = Game.GetDialogueTarget() as Actor
	if !a
		a = Game.GetCurrentCrosshairRef() as Actor
	endif
	return a
EndFunction

string Function Clip(string asText, int aiMax)
	if StringUtil.GetLength(asText) <= aiMax
		return asText
	endif
	return StringUtil.Substring(asText, 0, aiMax)
EndFunction

string Function B(bool abValue)
	if abValue
		return "1"
	endif
	return "0"
EndFunction

int Function Ms(float afFrom, float afTo)
	return ((afTo - afFrom) * 1000.0) as int
EndFunction

; ===========================================================================
; v1.0 - THE CALIBRATION. Store accessors and logging first, then the passive
; hooks, "forget", and the readers the driver and the MCM use.
; ===========================================================================

int Function CalG(string asKey)
	{One calibration key, defaulting to 0 - which is what "not answered yet" means for all of
	 them. StorageUtil, so no frame cost.}
	return LRG_DlgUI.CalInt(asKey, 0)
EndFunction

int Function CalGet(string asKey, int aiDefault)
	{The driver's reader (plan 6.5): iReadMode / iCountMode / iClickRoute resolve their "auto"
	 through this, and the timings read ms3 / tail / timer.}
	return LRG_DlgUI.CalInt(asKey, aiDefault)
EndFunction

Function CalSet(string asKey, int aiValue, string asSrc, int aiSamples)
	{Write one answer, and log it ONLY when it really changed - except the route PROOF: a route the
	 driver writes with src "live" after its first verified click is always recorded (CalRouteProof).
	 Every branch of every collector ends here or in a CALIB WARNING.}
	int was = LRG_DlgUI.CalInt(asKey, 0)
	string proof = ""
	if asKey == "route"
		proof = CalRouteProof(aiValue, was, asSrc)
	endif
	if was == aiValue && proof == ""
		return
	endif
	bool gateRow = asKey == "cm" || asKey == "rm" || asKey == "fam" || asKey == "st"
	bool greenWas = false
	if gateRow
		greenWas = CalGreen()
	endif
	LRG_DlgUI.CalSetInt(asKey, aiValue)
	LRG_DlgUI.CalSetInt("dirty", 1)
	; [pt18] every changed answer reaches the install file at once (one file write, and only here)
	LRG_DlgUI.CalFileSync()
	LRG_Main m = Main()
	if !m
		return
	endif
	; [v1.0 fix 1] a NEW route proof carries the literal "CALIB set route src=live" (5.3 step 2)
	string head = "CALIB set " + asKey + "=" + aiValue + " src=" + asSrc
	if proof != ""
		head = "CALIB set route src=live route=" + aiValue
	endif
	m.LogC("", head + " was=" + was + " n=" + aiSamples + proof + " npc=" + CalWho(), CalLogWho())
	; [v1.0] the red -> green edge of a CHANGED row (an already-green gate is CalLayer's to announce)
	if gateRow && !greenWas && CalGreen()
		CalGreenNote("learned now")
	endif
EndFunction

Function CalGreenNote(string asHow)
	;/[v1.0 fix 1] First-evening step 1's corner note, ONCE PER INSTALL however the gate got green: a
	 row that just changed (CalSet) or rows already answered when the session began - restored from
	 the install file, or learned by an earlier build (CalLayer). gnote (store + install file) keeps
	 it to once; CalForget clears it. dirty = 1, so the close sends one ev=calib (server: cal k=)./;
	if CalG("gnote") != 0 || !CalGreen()
		return
	endif
	LRG_DlgUI.CalSetInt("gnote", 1)
	LRG_DlgUI.CalSetInt("dirty", 1)
	LRG_DlgUI.CalFileSync()
	LRG_Main m = Main()
	if m
		m.LogC("", "CALIB GREEN 4 of 4 learned (" + asHow + ") cm=" + CalG("cm") + " rm=" + CalG("rm") + " fam=" \
			+ CalG("fam") + " st=" + CalG("st") + " route proven=" + B(CalRouteLive()) + " npc=" + CalWho(), CalLogWho())
	endif
	CalNotify("the dialogue menu is learned (4 of 4)")
EndFunction

string Function CalRouteProof(int aiRoute, int aiWas, string asSrc)
	;/[v1.0, spec S3.2] THE ROUTE IS PROVEN BY DOING. The driver writes route with src "live" when a
	 click it made showed RESPONDING: rproof = that route, rclicks counts the live proofs of it. Any
	 OTHER writer that CHANGES route (a flip after failed clicks, a manual setting) clears the proof,
	 so CalRouteLive() is never true for a route no real click has taken. A same-value write by
	 another source changes nothing. Returns the log suffix (" proven=<r> clicks=<n>") when a NEW
	 proof was recorded - the caller then logs and syncs even though route itself did not change -
	 and "" otherwise (a repeat proof only counts, in the store, with no file write and no line)./;
	if asSrc == "live" && aiRoute > 0
		if CalG("rproof") == aiRoute
			LRG_DlgUI.CalSetInt("rclicks", CalG("rclicks") + 1)
			return ""
		endif
		LRG_DlgUI.CalSetInt("rproof", aiRoute)
		LRG_DlgUI.CalSetInt("rclicks", 1)
		return " proven=" + aiRoute + " clicks=1"
	endif
	if aiRoute != aiWas && CalG("rproof") != 0
		LRG_DlgUI.CalSetInt("rproof", 0)
		LRG_DlgUI.CalSetInt("rclicks", 0)
	endif
	return ""
EndFunction

bool Function CalRouteLive()
	{True when the click route is known AND a real click on this install proved it (CalSet with src
	 "live"). The driver's condition for driving a journal-scene menu (spec S1.1: route src=live).}
	int r = CalG("route")
	return r > 0 && CalG("rproof") == r
EndFunction

string Function CalWho()
	{The speaker's name for the body of a log line, never empty.}
	if cNpcName == ""
		return "-"
	endif
	return cNpcName
EndFunction

string Function CalLogWho()
	{The name LogC routes the message under. "" means the player, which is what an unknown
	 speaker has to be - a literal "-" would send CHIM looking for an actor called "-".}
	return cNpcName
EndFunction

Function CalWarn(string asText)
	{One CALIB WARNING. Rare by construction, and always something the owner can act on.}
	LRG_Main m = Main()
	if m
		m.LogC("", "CALIB WARNING " + asText, CalLogWho())
	endif
EndFunction

Function CalNotify(string asText)
	Debug.Notification("LoreRim Glue: " + asText)
EndFunction

; ---------------------------------------------------------------------------
; the PASSIVE hooks. LRG_Dialogue calls these; none of them writes to the
; menu, and each one short-circuits as soon as its key is answered.
; ---------------------------------------------------------------------------
Function CalArm(int aiApd, int aiFam, int aiItems, int aiPlat, bool abHidden, bool abGlueOpened)
	{Hook 1, at the top of every arming (any menu, the glue's or the engine's). Every argument is a
	 value the driver already holds. Starts a fresh passive session and settles the fam and st rows
	 and the apd measurement. abHidden is kept for the frozen signature: v1.0 never hides.}
	float now = Utility.GetCurrentRealTime()
	if cOpen
		; a session that was never closed - a load, a crash out of the loop - must not poison
		; this one, and its own half-finished X1 window must be settled before it is dropped
		CalClose("no-close", cLayers, false, false)
	endif
	cOpen = true
	Actor npc = Speaker()
	cNpcName = ""
	if npc
		cNpcName = npc.GetDisplayName()
	endif
	cLayers = 0
	cLastN = 0
	cPollN = 0
	cTimerLast = -1
	cTimerN = 0
	cSubN = 0
	cSubLast = ""
	cX1Armed = false
	; --- apd (a measurement): the PRISTINE ALLOW_PROGRESS_DELAY, read before GuardReset() ----
	if aiApd > 0
		if aiApd != 750
			LRG_DlgUI.CalSetInt("apdb", CalG("apdb") + 1)
			CalWarn("ALLOW_PROGRESS_DELAY was " + aiApd + " on a fresh open - it leaked. Vanilla dialogue can be unclickable until the next arming resets it")
		endif
		CalSet("apd", aiApd, "passive", 1)
	endif
	; --- fam, the menu layout. An unknown family (0) is never written: it means MANUAL -------
	if aiFam == 1 || aiFam == 2
		CalSet("fam", aiFam, "passive", 1)
	elseif CalG("fam") == 0 && LRG_DlgUI.IsOpen()
		; [v1.0 fix 1] an unknown interface on an OPEN menu is counted for CalStuckWhy (one native,
		; only while fam is unanswered) - talking would never turn this row green
		LRG_DlgUI.CalSetInt("famu", CalG("famu") + 1)
	endif
	if aiItems > 0
		LRG_DlgUI.CalSetInt("items", aiItems)
	endif
	if aiPlat >= 0
		LRG_DlgUI.CalSetInt("plat", aiPlat)
	endif
	if abGlueOpened
		LRG_DlgUI.CalSetInt("actn", CalG("actn") + 1)
	endif
	; --- st, the D-21 precondition. ONE file read, and only while the answer is still red ----
	if CalG("st") != 1 && (cStAt < 0.0 || (now - cStAt) > 60.0 || now < cStAt)
		cStAt = now
		if LRG_DlgUI.SmartTalkSafe()
			CalSet("st", 1, "passive", 1)
		endif
	endif
EndFunction

Function CalPoll(int aiState, int aiCount, int aiTimerId, string asSubtitle)
	{Hook 2, at the end of every driver poll. The cheapest thing in the loop: it returns on the
	 first test for a calibrated install, and never costs more than ONE extra delayed native on
	 any single poll (the timer measurement, every 5th poll, until it is answered).}
	if !cOpen
		return
	endif
	cPollN += 1
	if aiCount >= 0
		cLastN = aiCount
	endif
	if cX1Armed
		CalX1Poll(aiCount)
	endif
	; --- timer: does iAllowProgressTimerID move per LINE or only once per SESSION? -----------
	if CalG("timer") != 0
		return
	endif
	;/ THE ONE-EXTRA-NATIVE BUDGET. A value the driver already read is ALWAYS used, whatever poll
	 this is - the driver's own cadence (its pollCount) and this counter (calls to CalPoll) are
	 not the same clock and drift apart, so keying off our own fifth poll alone would throw the
	 free readings away and pay for our own. Only when nothing was handed over do we read, and
	 then exactly once: the timer on every 5th poll, the subtitle on the 3rd of every 10th (3 is
	 not a multiple of 5 on purpose - an "offset 5 of every 10" sampler would collide with the
	 timer on every one of its polls and never run at all), and never both on the same poll. /;
	int tid = aiTimerId
	bool tookRead = false
	if tid < 0 && (cPollN % 5) == 0
		tid = LRG_DlgUI.ProgressTimerId()
		tookRead = true
	endif
	if tid >= 0
		if cTimerLast >= 0 && tid != cTimerLast
			cTimerN += 1
		endif
		cTimerLast = tid
	endif
	if !tookRead && (cPollN % 10) == 3
		string s = asSubtitle
		if s == ""
			s = LRG_DlgUI.Subtitle()
		endif
		if StringUtil.GetLength(s) > 1 && s != cSubLast
			cSubN += 1
			cSubLast = s
		endif
	endif
EndFunction

Function CalLayer(int aiTotal, int aiHead, int aiReadMode)
	{Hook 3, once per LAYER (the driver calls it only where the signature changed). The one
	 collector that reads the menu, and it is bounded: the counting census runs once per install,
	 reading once, rows six times, colours twenty times - then it is free forever.}
	if !cOpen
		return
	endif
	cLayers += 1
	if aiTotal >= 0
		cLastN = aiTotal
	endif
	; --- cm, counting: EntryCount() has already worked this out, so mirror it at zero cost ----
	int cm = LRG_DlgUI.CountMode()
	if cm >= 1 && cm <= 3
		CalSet("cm", cm, "passive", 1)
		LRG_DlgUI.CalSetInt("lp", LRG_DlgUI.ListPathId())
	elseif cm == -1
		CalSet("cm", -1, "passive", 1)
	endif
	; --- P2 census: WHICH modes work, three reads, once per install (bit 8 = it has run) ------
	if CalG("cmx") == 0 && aiTotal >= 1
		int mask = 8
		if LRG_DlgUI.CountRaw(1) > 0
			mask += 1
		endif
		if LRG_DlgUI.CountRaw(2) > 0
			mask += 2
		endif
		if LRG_DlgUI.CountRaw(3) > 0
			mask += 4
		endif
		LRG_DlgUI.CalSetInt("cmx", mask)
	endif
	CalReadProbe(aiTotal, aiHead, aiReadMode)
	CalRowProbe(aiTotal)
	CalColourProbe()
	; [v1.0 fix 1] once per game session: rows come back without CalSet only through Maintenance
	if !cGreenSaid
		cGreenSaid = true
		CalGreenNote("already learned")
	endif
EndFunction

Function CalReadProbe(int aiTotal, int aiHead, int aiReadMode)
	{P3 / P4. LRG_DlgUI's own calibrated read mode is already PROOF (it only stores a mode whose
	 position-0 text came back non-empty), so the normal path costs nothing. The four-read probe
	 below only runs while nothing has proved a mode yet.}
	int rm = CalG("rm")
	if rm == 3 || rm == 4
		return
	endif
	int urm = LRG_DlgUI.ReadMode()
	if urm == 3 || urm == 4
		CalSet("rm", urm, "passive", 1)
		return
	endif
	if aiTotal < 1
		return
	endif
	;/[v0.5 fix pass, defect m1] rm = -3 means "mode 3 came back empty on a list that exists"; only
	 the driver's own read (LRG_DlgUI.ReadModeAuto, which tries mode 4) can still prove a mode, and
	 the cheap recovery above picks that up. Without a cap the four-read probe below ran again on EVERY layer
	 for ever, and CalWarn fired every time (CalSet is silent on an unchanged value, CalWarn is
	 not) - five delayed natives and one HTTP line per layer, on exactly the install that cannot
	 read its menu. Capped the way rowN / colL are. The cheap recovery above is untouched: it is
	 a StorageUtil read, so a mode the UI layer proves later is still picked up at once./;
	if rm == -3 && CalG("rmN") >= 3
		return
	endif
	int cap = aiTotal
	if cap > 4
		cap = 4
	endif
	float t0 = Utility.GetCurrentRealTime()
	int good = 0
	int k = 0
	while k < cap
		if LRG_DlgUI.EntryText(k, 3) != ""
			good += 1
		endif
		k += 1
	endwhile
	CalNoteReadCost(Ms(t0, Utility.GetCurrentRealTime()), cap)
	if good == cap
		CalSet("rm", 3, "passive", cap)
	elseif good == 0 && LRG_DlgUI.TextProbeOn(LRG_DlgUI.ListPath()) != ""
		;/ Mode 3 comes back empty while a text probe can still see a list: mode 4 is the only
		 candidate left, and mode 4 WRITES iSelectedIndex, so this collector never tries it (CAL-1).
		 -3 records the fact; the reading row stays red until the driver's own read proves a mode. /;
		int n = CalG("rmN") + 1
		LRG_DlgUI.CalSetInt("rmN", n)
		CalSet("rm", -3, "passive", cap)
		if n <= 1
			CalWarn("reading mode 3 came back empty on a list of " + aiTotal + " with head " + aiHead \
				+ " and driver mode " + aiReadMode + " - the reading row stays red until the driver's own read proves mode 3 or 4")
		endif
	endif
EndFunction

Function CalNoteReadCost(int aiMs, int aiReads)
	{P3t. ms3 is milliseconds for TWELVE entry reads and tail for TWENTY-FOUR, extrapolated from
	 the sample just taken and kept at the WORST seen, because the driver's iMaxEntries cap has
	 to hold on a bad frame, not on a good one.}
	if aiReads <= 0 || aiMs < 0
		return
	endif
	int per12 = (aiMs * 12) / aiReads
	int per24 = (aiMs * 24) / aiReads
	if per12 > CalG("ms3")
		LRG_DlgUI.CalSetInt("ms3", per12)
		LRG_DlgUI.CalSetInt("dirty", 1)
	endif
	if per24 > CalG("tail")
		LRG_DlgUI.CalSetInt("tail", per24)
	endif
EndFunction

Function CalRowProbe(int aiTotal)
	{P5 / P17's first half. Is a SCREEN ROW the same thing as an array position? Four itemIndex
	 reads on a long list, at most six lists, then never again.}
	if CalG("row") == 1
		return
	endif
	if aiTotal < 10
		return
	endif
	int n = CalG("rowN")
	if n >= 6
		return
	endif
	LRG_DlgUI.CalSetInt("rowN", n + 1)
	int differs = 0
	int k = 0
	while k < 4
		int item = LRG_DlgUI.EntryRowItem(k)
		if item >= 0 && item != k
			differs += 1
		endif
		k += 1
	endwhile
	if differs > 0
		CalSet("row", 1, "passive", n + 1)
	else
		CalSet("row", 2, "passive", n + 1)
	endif
EndFunction

Function CalColourProbe()
	{P17. Is Smart Talk's quest colour 0xFFD966 = 16767334 ever really on a row, or is it inert
	 on this install? Four colour reads per layer for the first twenty layers, then a verdict.}
	if CalG("col") == 1
		return
	endif
	int seen = CalG("colL")
	if seen >= 20
		if CalG("col") == 0
			CalSet("col", 2, "passive", seen)
		endif
		return
	endif
	LRG_DlgUI.CalSetInt("colL", seen + 1)
	bool found = false
	int k = 0
	while k < 4 && !found
		if LRG_DlgUI.EntryColour(k) == 16767334
			found = true
		endif
		k += 1
	endwhile
	if found
		CalSet("col", 1, "passive", seen + 1)
	endif
EndFunction

Function CalSpeech(int aiWho, float afAt)
	{Hook 4, forwarded from CHIM's own speech events. 0 = the PLAYER spoke, 1 = the NPC did.
	 The player's own voiced line on a CHOICE layer is the X1 measurement (plan 6.2): does the
	 layer survive it? Kept for the log; nothing decides on it.}
	if !cOpen
		return
	endif
	float at = afAt
	if at <= 0.0
		at = Utility.GetCurrentRealTime()
	endif
	if aiWho != 0
		if cX1Armed
			;/[v0.5 fix pass, defect m5] "npc-spoke", NOT "self". LRG_Main forwards EVERY CHIM NPC
			 speech event for EVERY agent, and NoteSpeech filters only on "is a session live" - so
			 a bystander agent answering somebody else two rooms away looked exactly like the
			 speaker in front of us. All this event proves is that SOME NPC spoke inside the
			 window; deviation D15 promised the recorder would not pretend otherwise./;
			cX1Route = "npc-spoke"
		endif
		return
	endif
	if CalG("x1") != 0 || cX1Armed
		return
	endif
	if cLayers < 2 || cLastN < 2
		return ; the root greeting is not a choice layer, and X1 is a question about a branch
	endif
	cX1Armed = true
	cX1At = at
	cX1N = cLastN
	cX1Layer = cLayers
	cX1Route = "none"
	cX1Talking = 0
	if cNpcName != ""
		cX1Talking = AIAgentFunctions.isActorTalking(cNpcName)
	endif
EndFunction

Function CalX1Poll(int aiCount)
	{The six seconds after the player spoke. No extra read: the count is the one CalPoll was
	 handed. A new layer inside the window proves nothing either way and is recorded as unknown.}
	float now = Utility.GetCurrentRealTime()
	float dt = now - cX1At
	if dt < 0.0
		cX1Armed = false
		return
	endif
	if cLayers != cX1Layer
		CalX1Verdict("unknown", aiCount, Ms(cX1At, now))
		return
	endif
	if dt < 6.0
		return
	endif
	;/[v0.5 fix pass, defect M3] ONE fresh read, once per window, instead of trusting the count the
	 driver handed over: LRG_Dialogue.lastN is assigned in five places and NONE of them is in
	 StepDeciding / StepSuspended / StepResponding, which is exactly where these six seconds are
	 normally spent. A stale "n from four states ago" was being written into the survived/died
	 tally of the whole install. The read is bounded by construction:
	 the window fires once and then disarms./;
	int n = 0
	if LRG_DlgUI.IsOpen()
		n = LRG_DlgUI.EntryCount()
	endif
	if n > 0
		CalX1Verdict("survived", n, Ms(cX1At, now))
	else
		CalX1Verdict("died", n, Ms(cX1At, now))
	endif
EndFunction

Function CalX1Verdict(string asWhat, int aiCount, int aiMs)
	{One X1 observation, and the running verdict. x1 is a MEASUREMENT (v1.0): it rides ev=calib
	 and the log, and nothing - gate, driver or server - decides on it.}
	cX1Armed = false
	; NOT "key": Key is a vanilla form type, and Papyrus refuses a variable named after a script
	string bucket = "x1u"
	if asWhat == "survived"
		bucket = "x1s"
	elseif asWhat == "died"
		bucket = "x1d"
	endif
	LRG_DlgUI.CalSetInt(bucket, LRG_DlgUI.CalInt(bucket, 0) + 1)
	LRG_DlgUI.CalSetInt("dirty", 1)
	int s = CalG("x1s")
	int d = CalG("x1d")
	int verdict = 0
	if (s + d) >= 3
		if s >= (d * 3)
			verdict = 1
		elseif d >= (s * 3)
			verdict = 2
		endif
	endif
	CalSet("x1", verdict, "passive", s + d)
	LRG_Main m = Main()
	if m
		m.LogC("", "CALIB X1 menu=" + asWhat + " sid=- after_player_speech_ms=" + aiMs \
			+ " layer_alive=" + B(aiCount > 0) + " n_before=" + cX1N + " n_after=" + aiCount \
			+ " talking=" + cX1Talking + " routed=" + cX1Route + " npc=" + CalWho(), CalLogWho())
	endif
EndFunction

Function CalClose(string asWhy, int aiLayer, bool abPending, bool abAnyClick)
	{Hook 5, at the end of a session. Settles the timer measurement and any X1 window still open.
	 Only a close the driver did not cause counts as "the layer died"; every other is "unknown".
	 abPending is kept for the frozen signature (v1.0 has no PENDING state).}
	if !cOpen
		return
	endif
	cOpen = false
	if cX1Armed
		;/[v0.5 fix pass, defect M3] The two arguments this hook was handed were ignored, so the
		 driver's OWN farewell click - and a refusal, a hand-back, a handoff to a shop - were all
		 written down as "the choice layer died when the player spoke", the install's X1 verdict.
		 LRG_Dialogue classifies the close for us: only
		 external (nobody here closed it) with nothing ever clicked is evidence about X1 at all./;
		string verdict = "unknown"
		if asWhy == "external" && !abAnyClick
			verdict = "died"
		endif
		CalX1Verdict(verdict, 0, Ms(cX1At, Utility.GetCurrentRealTime()))
	endif
	if CalG("timer") == 0
		if cTimerN >= 3
			CalSet("timer", 1, "passive", cTimerN)
		elseif cSubN >= 3 && cTimerN <= 1
			CalSet("timer", 2, "passive", cSubN)
		endif
	endif
	; [pt18] the measurements written with CalSetInt (ms3, pay, items ...) reach the install file
	; once per session, here, so no poll ever pays for a file write
	if CalG("dirty") == 1
		LRG_DlgUI.CalFileSync()
	endif
EndFunction

Function CalPayload(int aiChars, bool abPart2, int aiTailChars)
	{Hook 6, a measurement for free: the wire cost of the largest layer this install has produced,
	 both parts together (pay, in CALIB SUMMARY). LRG_Dialogue calls it on every topics send.}
	int total = aiChars + aiTailChars
	if total > CalG("pay")
		LRG_DlgUI.CalSetInt("pay", total)
		LRG_DlgUI.CalSetInt("dirty", 1)
	endif
EndFunction

Function CalOpened(int aiTries, int aiMs)
	{Hook 7, a measurement: the driver calls it from DoOpen() with the retry count and the
	 milliseconds to IsOpen() (actry, actms - the longest wait - and actbad). Nothing breaks if it
	 is never called; the three then stay 0.}
	if aiTries > CalG("actry")
		LRG_DlgUI.CalSetInt("actry", aiTries)
	endif
	if aiMs > CalG("actms")
		LRG_DlgUI.CalSetInt("actms", aiMs)
	endif
	;/[v0.5 fix pass, defect M2] Its OWN key. actn is CalArm's counter of glue-opened sessions
	 (+1 on every one, retry or not), so sharing it meant the "try plain activation" warning fired
	 on the third glue-opened conversation of the install whether or not a single Activate had
	 ever needed a second try - and, because the test is == 3, could then never fire again.
	 actbad counts only the opens that really did need more than one try./;
	if aiTries > 1
		int bad = CalG("actbad") + 1
		LRG_DlgUI.CalSetInt("actbad", bad)
		if bad == 3
			; actn is the denominator - the glue-opened sessions CalArm counted - so the warning
			; says three out of how many, and that counter now has a reader again
			CalWarn("opening a conversation needed more than one try 3 times out of " + CalG("actn") \
				+ " - try 'Plain activation' on the Menuless questing page of the MCM")
		endif
	endif
EndFunction

Function CalNewOpen(bool abHidden)
	;/[v1.0] Kept for the frozen probe API (spec 2.2, Lane D) and nothing else: its two assignments
	 reset the opt-in active pass's per-open state, and that pass is gone. It writes nothing and
	 reads nothing; the driver may stop calling it. CalArm now runs on EVERY arming (the passive
	 collector has no switch any more), so no arming goes unseen./;
EndFunction

bool Function CalDirty(bool abClear)
	{ADDITIVE to plan 11.2: wire W1 sends ev=calib only when at least one key CHANGED, and this
	 is how the driver asks. Pass true to clear the flag in the same call.}
	bool d = CalG("dirty") == 1
	if d && abClear
		LRG_DlgUI.CalSetInt("dirty", 0)
	endif
	return d
EndFunction

Function CalForget()
	{Start the measuring again from nothing: the LRG.cal.* store (route and its proof included),
	 the read/count calibration the UI primitives keep, the install file - and the server's own
	 proof, clicks_ok, through ev=calib reset=1 (spec S3.2).}
	LRG_DlgUI.CalClear()
	LRG_DlgUI.ResetCalibration()
	cOpen = false
	cNpcName = ""
	cX1Armed = false
	cStAt = -1.0
	cGreenSaid = false ; gnote went with CalClear: the next learning says "learned" once more
	; [pt18] the install file too, or the next load would seed the forgotten answers straight back
	LRG_DlgUI.CalFileWipe()
	CalWarn("everything the calibration learned was forgotten (manual) - it starts again from nothing (the install file " + LRG_DlgUI.CalFilePath() + " was wiped too)")
	CalSendReset()
	CalNotify("calibration forgotten - talk to anyone once and it learns again")
EndFunction

Function CalSendReset()
	;/[v1.0, spec S3.2 / S8] "Forget everything it learned" must reach the server as well: its
	 *install* row keeps clicks_ok (the stage rail's proof that a real click went through), and a
	 forgotten route with clicks_ok still set would let scripted picks go out on an unproven route.
	 One additive message, the same shape as LRG_Dialogue.SendCalib's W1 ev=calib plus reset=1;
	 k= stays LAST and RAW (CalWire, all zeroes now). bDlgWire = 0 sends nothing, as every lrg_dlg
	 message. An old server ignores the unknown key and simply records the zeroes./;
	LRG_Main m = Main()
	if !m || !m.SettingBool("bDlgWire:Dialogue", true)
		return
	endif
	string who = Game.GetPlayer().GetDisplayName()
	string miss = CalMissing()
	if miss == ""
		miss = "-"
	endif
	m.SendNpcMessage("lrg_dlg", "ev=calib;v=1;ref=00000000;npc=" + m.CleanForWire(who) + ";src=forget;gate=" \
		+ B(CalGreen()) + ";reset=1;miss=" + m.CleanForWire(miss) + ";k=" + CalWire(), who)
	m.LogC("", "CALIB reset sent: ev=calib reset=1 - the server forgets its clicks_ok proof too", "")
EndFunction

string Function CalMissing()
	{The human names of the gate rows still unanswered, comma separated; "" means green. FOUR rows
	 (v1.0, spec S3.1), all passive, all written on the first dialogue menu of any kind: counting
	 (cm), reading (rm), menu layout (fam), Smart Talk settings (st).}
	string out = ""
	int cm = CalG("cm")
	if !(cm >= 1 && cm <= 3)
		out = CalAdd(out, "counting")
	endif
	int rm = CalG("rm")
	if rm != 3 && rm != 4
		out = CalAdd(out, "reading")
	endif
	int fm = CalG("fam")
	if fm != 1 && fm != 2
		out = CalAdd(out, "menu layout")
	endif
	if CalG("st") != 1
		out = CalAdd(out, "Smart Talk settings")
	endif
	return out
EndFunction

string Function CalAdd(string asList, string asName)
	if asList == ""
		return asName
	endif
	return asList + ", " + asName
EndFunction

bool Function CalGreen()
	{True when all four rows are answered. While false the driver refuses a click with
	 "still learning the dialogue menu" (spec S3.4); it never forces or clears the dry run.}
	return CalMissing() == ""
EndFunction

bool Function CalLearnable()
	{False when talking to somebody will NOT turn the gate green by itself: a Smart Talk setting
	 blocks (or SmartTalk.ini cannot be read), no way of counting the list works, the list could not
	 be read three times, or the dialogue interface is not one it knows. CalStatusText() says which.
	 For the driver's refusal words. No UI or file read (Smart Talk: the verdict CalArm cached).}
	if CalGreen()
		return true
	endif
	if CalG("st") != 1 && LRG_DlgUI.SStr("sto", "") != ""
		return false
	endif
	return CalStuckWhy() == ""
EndFunction

string Function CalStuckWhy()
	;/[v1.0 fix 1] Why one more conversation will not turn the gate green, in the owner's words; ""
	 when it will. cm = -1: a readable list no counting mode counts (EntryCount); rm = -3 three times:
	 CalReadProbe has stopped; famu: armings on an interface SwfFamily() does not know./;
	if CalG("cm") == -1
		return "no way of counting her list works yet - see CALIB in lorerim_glue.log"
	endif
	if CalG("rm") == -3 && CalG("rmN") >= 3
		return "her list cannot be read on this install - see CALIB WARNING in lorerim_glue.log"
	endif
	int fm = CalG("fam")
	if fm != 1 && fm != 2 && CalG("famu") >= 3
		return "this dialogue interface is not one it knows"
	endif
	return ""
EndFunction

int Function CalAnswered()
	{How many of the four rows are answered: the N of "N of 4".}
	string miss = CalMissing()
	if miss == ""
		return 4
	endif
	int commas = 0
	int i = StringUtil.Find(miss, ",")
	int guard = 0
	while i >= 0 && guard < 4
		commas += 1
		i = StringUtil.Find(miss, ",", i + 1)
		guard += 1
	endwhile
	return 4 - (commas + 1)
EndFunction

string Function CalStatusText()
	{The Calibration page's read-only line, at most 200 characters (spec S10):
	 "4 of 4 learned, route proven by N click(s)" or "learning: N of 4 - talk to anyone once".
	 What talking to somebody does NOT fix is named instead: a Smart Talk setting that blocks or an
	 unreadable SmartTalk.ini first, then CalStuckWhy() (a list nothing counts or reads, an
	 interface it does not know).}
	if CalG("st") != 1
		string off = LRG_DlgUI.SmartTalkOffender()
		if off == "unreadable"
			return "blocked - Data/SKSE/Plugins/SmartTalk.ini could not be read - set bSkipImmediateOnInput, bHoldToSkip and bSkipOnInteraction to 0"
		elseif off != ""
			return Clip("blocked - Smart Talk " + off + " must be 0 in Data/SKSE/Plugins/SmartTalk.ini", 200)
		endif
	endif
	string miss = CalMissing()
	if miss != ""
		string stuck = CalStuckWhy()
		if stuck != ""
			return Clip("learning: " + CalAnswered() + " of 4 - " + stuck + " (still to learn: " + miss + ")", 200)
		endif
		if CalG("rm") == -3
			; mode 3 came back empty once or twice: one more conversation may still prove a mode
			return Clip("learning: " + CalAnswered() + " of 4 - the list could not be read yet (lorerim_glue.log, CALIB WARNING); talk to anyone once more (still to learn: " + miss + ")", 200)
		endif
		return Clip("learning: " + CalAnswered() + " of 4 - talk to anyone once (a conversation opened with E counts; still to learn: " + miss + ")", 200)
	endif
	if CalRouteLive()
		int n = CalG("rclicks")
		if n <= 1
			return "4 of 4 learned, route proven by 1 click"
		endif
		return "4 of 4 learned, route proven by " + n + " clicks"
	endif
	; [v1.0 fix 1] "the next line": after a route flip clicks_ok is still set, so any line may be next
	return "4 of 4 learned, route not proven yet - the next line she picks for you proves it"
EndFunction

string Function CalWire()
	{The raw body of ev=calib's k= (wire W1): name:int pairs, commas, no spaces, never a
	 semicolon, so it is safe as the LAST and raw key of a message. v1.0 (spec S8): the four gate
	 rows, the route, and five measurements. The server's parser is generic and ignores a name
	 it does not know, so an old ten-row body still parses and this one does too.}
	string s = "cm:" + CalG("cm") + ",rm:" + CalG("rm") + ",fam:" + CalG("fam") + ",st:" + CalG("st")
	s += ",route:" + CalG("route") + ",timer:" + CalG("timer") + ",x1:" + CalG("x1")
	s += ",apd:" + CalG("apd") + ",ms3:" + CalG("ms3") + ",tail:" + CalG("tail")
	return s
EndFunction

Function CalLogSummary()
	{The whole picture in two lines, from SelfTest() on every load and on every topic dump: the
	 four rows, the route and its proof, and every measurement (the ones not on the wire too).}
	LRG_Main m = Main()
	if !m
		return
	endif
	m.LogC("", "CALIB SUMMARY gate=" + B(CalGreen()) + " answered=" + CalAnswered() + "/4" \
		+ " cm=" + CalG("cm") + " rm=" + CalG("rm") + " fam=" + CalG("fam") + " st=" + CalG("st") \
		+ " route=" + CalG("route") + " proven=" + B(CalRouteLive()) + " clicks=" + CalG("rclicks") \
		+ " lp=" + CalG("lp") + " timer=" + CalG("timer") + " x1=" + CalG("x1") + " apd=" + CalG("apd") \
		+ " ms3=" + CalG("ms3") + " tail=" + CalG("tail") + " col=" + CalG("col") + " row=" + CalG("row") \
		+ " pay=" + CalG("pay") + " actms=" + CalG("actms"), "")
	string miss = CalMissing()
	if miss == ""
		miss = "none"
	endif
	m.LogC("", "CALIB GATE missing=" + miss, "")
EndFunction
