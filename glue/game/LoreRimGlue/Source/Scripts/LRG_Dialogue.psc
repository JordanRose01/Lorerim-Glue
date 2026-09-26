Scriptname LRG_Dialogue extends Quest
;/LoreRim Glue - PHASE 2: the menuless questing session driver (design PHASE2_DESIGN.md 1.3).

 WHAT THIS SCRIPT DOES, AND WHAT IT MUST NEVER DO.
 [v1.0 / script 513, spec research/pt19-menuless-v1-spec.md S1-S4] It drives the ENGINE's own
 dialogue session ON SCREEN: the real menu stays visible the whole time, the player may click it by
 hand at any moment, and when the server decides his words name an entry the driver clicks that
 entry through the path the menu itself uses. The engine then picks the INFO, plays the line and
 runs the fragments. Nothing is hidden, guarded, parked or timed out behind a hidden menu.
 It never calls a TIF fragment, never SetStage / SetObjectiveCompleted / CompleteQuest, never
 SetBribed / SetIntimidated / SetCrimeGold, and never fakes a dialogue effect (design 1.8).

 SHAPE (the two rules that make it safe):
  * ONE loop owns the UI. OnMenuOpen("Dialogue Menu") starts RunSession(); commands and keys only
    set request variables that the loop consumes on its next poll.
  * Every UI string and every UI.* call lives in LRG_DlgUI. This script only calls its functions.

 WHO DRIVES A SESSION (S1.1). Arm() grades every open: drv=1 unless the module is off, Smart Talk
 is unsafe, the dump asked for a plain menu, the speaker is a guard with a bounty (lethal), the
 speaker is in a scene whose OWNING quest shows an unfinished journal objective and the click route
 is not proven live on this install, quiet mode runs, or the interface is unknown. An undriven
 session is still READ and forwarded (drv=0); a pick that reaches it anyway is answered "choose
 that one on the list yourself". StopDriving(why) is the one way a driven session becomes read-only.

 TIMER DISCIPLINE. LRG_Main owns the one RegisterForSingleUpdate of this quest form. This script
 registers NO timer: the session poll is the loop's own Utility.WaitMenuMode, and the two things
 that need a stack of their own outside a session (opening a menu, the voice-keeper sweep) run on
 a private SKSE mod event ("LRG_DlgPump") that costs nothing when idle.

 Wire: glue/PROTOCOL.md - lrg_topics (+ sj, drv, hc), lrg_dlg (ev=open|line|result|closed|facts|
 calib|lat|stopped) and the single action ExtCmdLRG_SelectTopic (do=pick|open|leave|show|noop|award|
 release; adv= / rearm= on a pick). lrg_dlgtalk, ev=unhide and ev=resume are never sent any more.
/;

; ---------------------------------------------------------------------------
; Constants
; ---------------------------------------------------------------------------
string Property MSG_TOPICS = "lrg_topics" AutoReadOnly
string Property MSG_DLG = "lrg_dlg" AutoReadOnly
string Property MENU_DLG = "Dialogue Menu" AutoReadOnly
string Property PUMP_EVENT = "LRG_DlgPump" AutoReadOnly

; the states of the design 1.3 machine ("state" is a Papyrus keyword, hence dlgState). [v1.0] 5 was
; PENDING (a hidden menu parked while she waited): gone. A read layer with nothing outstanding is
; LISTENING with held = true (HELD, spec S1.1 / model F10).
int Property ST_IDLE = 0 AutoReadOnly
int Property ST_OPENING = 1 AutoReadOnly
int Property ST_LISTENING = 2 AutoReadOnly
int Property ST_READING = 3 AutoReadOnly
int Property ST_DECIDING = 4 AutoReadOnly
int Property ST_CLICKING = 6 AutoReadOnly
int Property ST_RESPONDING = 7 AutoReadOnly
int Property ST_SUSPENDED = 8 AutoReadOnly
int Property ST_MANUAL = 9 AutoReadOnly
int Property ST_CLOSING = 10 AutoReadOnly

; ---------------------------------------------------------------------------
; State
; ---------------------------------------------------------------------------
bool attached = false
bool booted = false      ; [0.5.5] true once a boot has finished (OnLrgBoot); every event handler waits for it
int dlgState = 0
int suspFrom = 0
bool loopRunning = false
;/[0.5.1 pt9 go-live, G6] True only while Finish() is winding the session down. loopRunning stays true
 through all of it while loopBeat keeps being refreshed, so "a live loop already owns this menu" was
 true for a loop that owns nothing any more - and a conversation opened in those seconds got no Arm()
 at all: no GuardReset (rule R1's only repair), no hide, no lrg_topics, no ev=open./;
bool finishing = false
; the menu that opened during that wind-down, waiting for the pump to arm it on a stack of its own
bool rearmWanted = false
float rearmAt = 0.0
float loopBeat = 0.0
int pollCount = 0

; the live session
string sid = ""
int gen = 0
int layer = 0
string origin = "engine"
Actor speaker = None
bool dlgListenerHeld = false ; [v1.0.1] CHIM's listener is forced to the speaker while her list is open (HoldListener)
bool sceneSafetyRelaxed = false ; [v1.0.1] CHIM's "NPC Scene Safety" (_restrict_onscene) relaxed once this game session (HoldListener)
string npcName = ""
string sessCid = ""
int crit = 0
bool inScene = false     ; scene= on the wire: ANY scene (today's meaning, spec S1.1)
int fam = 0
int maxItems = 0
int platform = 0
bool subsOn = false
bool glueOpened = false
bool mmBase = false
int route = 0
float openTime = 0.0
float stateTime = 0.0
bool forceVisible = false
float forceVisibleAt = 0.0
bool closeAsked = false
bool anyClick = false
bool pendingLayer = false
bool sentThisGen = false
float noCountAt = 0.0
float decideStart = 0.0
float clickStart = 0.0
float clickAt = 0.0
float respStart = 0.0
float closeAt = 0.0
bool readySeen = false
string settleVal = ""
int settleInt = 0
float settleAt = 0.0
string sessQ = "-"
;/[v1.0 / S1.1] the scene basis of the live session: sq= the EditorID of the quest owning her scene,
 sqj= 1 when that quest shows an unfinished journal objective, sjScene = a scene with sqj=1 (sj=1 on
 the wire). sessScene is the Scene form it was read for, so a changed layer re-tests only a NEW scene./;
bool sjScene = false
string sessSq = "-"
int sessSqj = 0
Scene sessScene = None
bool quietArm = false     ; quiet mode ran when this session armed (ev=open quiet=1, drv=0; model F17)
string openAsk = ""       ; [model F4] ask= of the stamped do=open, carried to the first lrg_topics
;/[v1.0 / model F10] HELD: the layer is read and answered, nothing is outstanding, the list is on
 screen. The poll then reads EntryCount() and the harvested subtitle only; a changed count, a new
 line or a stamped pick moves on, and the whole list is read again only on a change./;
bool held = false
int heldLines = 0
int lnSeq = 0             ; new harvested lines so far (HELD compares it)
string lastSig = ""       ; the signature of the last layer really read ("" forced re-reads keep it)
bool ourClick = false     ; a driven click produced the next layer (so that layer is not his own click)
int hcGen = -1            ; [model F9] the gen whose layer HIS click produced: lrg_topics hc=1
int layerGen = 0          ; the gen at which the list last really CHANGED (a pick older than it is overtaken)
float layerStart = 0.0    ; what produced this layer: our click, his click, or the open (model F11)
;/[v1.0 / S4.6, model F11-F13, F30] the auto-advance anchors and the speech stamps. lineSeenAt = the
 last non-blank subtitle read, blankSince = the start of the current blank run (blank = shorter
 than 2 characters). speechAt = the player (his CHIM-voiced line, or his talk key); npcSpeechAt =
 any CHIM event of the SESSION speaker; herVoiceAt = her CHIM voice starting./;
float lineSeenAt = 0.0
float blankSince = 0.0
float speechAt = 0.0
float npcSpeechAt = 0.0
float herVoiceAt = 0.0
bool clickAuto = false    ; the click in flight was an auto-advance (ev=result auto=1)
string advLog = ""        ; the anchor that released it, for the clicked line
bool advVoice = false     ; a rearm=1 pick: her voice has begun (or 8 s passed without one)
float advQuietAt = 0.0    ; a rearm=1 pick: isActorTalking has read 0 since then
bool advSaid = false      ; "adv wait: her line" was logged for this pick
bool routeProved = false  ; this session already wrote CalSet("route", r, "live") (spec S3.2)
int rlSess = -1             ; this install's route was proven before this session: -1 not asked yet, 0 no, 1 yes (one probe call per session)
bool routeFlipped = false ; this click already flipped the route (no signal in 9 s on route A)
int clickFails = 0        ; clicks of this session that did not take (ClickResult <= 0); two flip the route

; the list of the live layer
string[] eText
int[] eIdx
string[] tText
int eHead = 0
int tCount = 0
int nTotal = 0
int lastSent = 0
string sig = ""
int readMode = 0
int readFails = 0
bool readNoteLogged = false   ; [0.5 fix pass] the "mode 3 is empty" note is worth one line, not one per poll
int staleCount = 0

; the request a command or a key left for the loop
bool reqLeave = false
bool reqShow = false
bool reqDump = false
bool reqPickReady = false
bool reqWant = false
bool reqReported = true
string reqDo = ""
string reqCid = ""
string reqX = ""
string reqNpc = ""
string reqRef = ""
string reqTxt = ""
string reqKind = ""
string reqAsk = ""
string reqVt = ""
string reqSid = ""
string reqParam = ""
string reqCmd = ""
int reqPos = -1
int reqIdx = -1
int reqGen = 0
int reqCost = 0
float reqAt = 0.0
int reqAdv = -1           ; [v1.0 / S4.6] adv=<ms> on a pick: an auto-advance (-1 = none, 0 = a continuer)
bool reqRearm = false     ; rearm=1: the CHIM anchor (her answer ends first), not the engine line
;/[v1.0 review] clickX = the x the click window was opened for (a request replaced under it gets a
 window of its own); clickTxt = the RAW live prefix SelectAndVerify checks once txt= has resolved
 (txt= is the server's wire form, not the menu's text); staleWhy = S7's sentence for a second miss
 on the same layer; scanX/scanGen = this pick already failed to resolve on this read layer./;
string clickX = ""
string clickTxt = ""
string staleWhy = ""
string scanX = ""
int scanGen = -1
;/[v1.0 review / model row 21] the one-slot queue: a command that arrives while a click is being
 verified waits here and is replayed once the click has settled./;
string qX = ""
string qNpc = ""
string qCmd = ""
string qParam = ""
float dumpAt = 0.0
bool openWanted = false
Actor openNpc = None
int staleSentGen = -1

; the executed ring (8), so two delivery routes of one decision execute once
string[] xRing
int xSlot = 0

; PRE facts of a click (design 1.3 CLICKING / 6.7)
int preP = 0
int preB = 0
int preI = 0
int preGold = 0
int preBamt = 0
int preSp = 0
bool preBribed = false
bool preIntim = false
bool preWis = false
int preTimer = 0
string preSub = ""
bool rsSignal = false
bool rsProof = false      ; the signal was one her CHIM line cannot cause (route proof, S3.2)
bool rsWatch = false      ; a non-proof signal came first: keep looking for the proof while RESPONDING (unproven route only)
bool rsClosed = false
string rsOther = ""

; the freeze rule (B1)
int frzGold = 0
int frzSp = 0
int frzHits = 0

; ev=line de-duplication, keyed on the PAIR (gen, string)
string[] lnText
int[] lnGen
int lnSlot = 0

; voice keeper (design 1.5)
Form[] vkRef
VoiceType[] vkVoice
int vkSlot = 0
float vkSweepAt = 0.0

; ev=facts throttle, 60 s per NPC
Form[] fxRef
float[] fxAt
int fxSlot = 0
; [v1.0 / S1.1] the last facts line's scene answer (SendFacts), reused by Arm() for 30 s when it is
; the SAME Scene of the same NPC: 0 new natives for the journal-scene test on a fresh E-press
Actor fxScNpc = None
Scene fxScScene = None
string fxScSq = "-"
int fxScSqj = 0
float fxScAt = 0.0

; speech globals (design D-6): 0..4 = VeryEasy..VeryHard, 5 = SpeechSkillMult
GlobalVariable[] sgv
float[] sgOpen
bool sgDone = false

; forms, all looked up at runtime (the ESP has zero properties)
Form goldForm = None
Perk perkSilver = None
Perk perkPers = None
FormList amuletList = None
Form raceWolf = None
Form raceVamp = None
VoiceType nullVT = None
bool formsDone = false
string perkCsv = "-"
float perkAt = 0.0

; settings cache (re-read at most every 5 s). A MISSING ini key reads as 0/FALSE, so every default
; below is mirrored by a line in MCM\Config\LoreRimGlue\settings.ini.
float setAt = 0.0
bool sMenuless = true    ; [pt17] ON out of the box (settings.ini bMenuless = 1)
bool sDryRun = false     ; [v1.0 / S3.4] the owner's menuless dry run ships OFF and nothing clears or forces it
bool sAllowNull = false
bool sActivateDefault = false
bool sQuestAware = true
bool sCheckStats = false
bool sFreeChecks = true
bool sIntentOpen = true
; [0.4 fix pass] bDlgWire: the Phase 2 wire itself. OFF = not one lrg_dlg / lrg_topics message
; leaves the game, which is the one-toggle rollback for a server that is still on 0.3.1 (where
; those types would take the MAIN LLM semaphore and be answered as an ordinary paid turn).
bool sWire = true
int sSceneGate = 1       ; [v1.0 / S2.2] ships 1: open on a scene actor unless her scene's quest shows a journal objective
int sClickRoute = 0
int sReadMode = 0
int sCountMode = 0
int sMaxEntries = 16
int sTailMax = 16        ; [v1.0 / S1.1] head 16 x 2 reads + tail 16 = at most 48 UI reads per changed layer
float sDecide = 4.0
float sLineSettle = 0.4
float sOpenDist = 200.0
bool smartSafe = false
bool sServiceDlg = true
bool sAutoAdvance = true ; [v1.0 / S4.6] bAutoAdvance:Dialogue - an unscripted single line carries on by itself
bool sDriveScene = true  ; [v1.0 / S1.1] bDriveSceneMenus:Dialogue (ini only) - journal-scene menus once the route is proven live

; the calibration, read once per ReadSettings() from LRG_DlgProbe and cached with it
int calRm = 0
int calCm = 0
int calRoute = 0
bool calGreen = false
string calMiss = ""
bool calNoProbe = false  ; the probe script is not attached: the gate cannot be evaluated at all
bool calTimerWanted = false ; this session still owes the calibration a progress-timer sample
float calSentAt = 0.0    ; ev=calib throttle, one per 30 s
string calSub = ""       ; the last subtitle HarvestLine already read (free for CalPoll)
int lastN = 0            ; the live EntryCount() of this poll, recorded where it is already read
bool qgiver = false      ; set by QalCsv: one of this NPC's alias names looks like a quest giver
; [0.5.6] set by QalCsv: how many aliases po3 reported for her at all (-1 = none/None). Log only:
; every facts line of playtest 13 said qal=-, and this tells "she has no alias" from "none passed".
int qalRaw = -1

; v0.5 E6: what kind of service list this session is looking at (diagnostic; nothing branches on it)
string sessSvc = "-"
string layerSvc = "-"
int svcPriced = 0
int svcShort = 0

; MCM diagnostics counters (per game session; the topic-dump key writes them to the log)
int dOpensNoMatch = 0
int dSceneRefusals = 0
int dLayersLost = 0
int dStops = 0           ; StopDriving: sessions the driver stopped driving (was "layers assisted")
int dCountFails = 0
int dAwards = 0
int dDrifts = 0
int dClickAborts = 0

; ---------------------------------------------------------------------------
; Lifecycle
; ---------------------------------------------------------------------------
Event OnInit()
	;/[0.5.5] Registers and returns - nothing else. THIS OnInit is the one frozen in the 0.5.4 saves:
	 it ran Maintenance -> SelfTest -> LRG_Main.LogC during a load while LRG_Main.Maintenance was
	 calling in here, and a script is closed to every other stack until its OnInit returns. The boot
	 itself arrives as the LRG_Boot mod event (OnLrgBoot), on a stack of its own, when LRG_Main's boot
	 queue gets to this module./;
	RegisterForModEvent("LRG_Boot", "OnLrgBoot")
EndEvent

Event OnLrgBoot(string asWho, int aiSess)
	;/[0.5.5] The boot entry point. LRG_Main's boot step 6 sends LRG_Boot with this script's name; the
	 same event reaches every script on the form, so any other name is not for us - except "calib",
	 LRG_Main's request for this load's ev=calib once the probe has booted as well (v0.5 W1)./;
	if asWho == "calib"
		if booted
			SendCalib("load")
		endif
		return
	endif
	if asWho != "LRG_Dialogue"
		return
	endif
	Maintenance()
	booted = true
	LRG_Main m = Main()
	if m
		m.BootConfirm(1, aiSess)
	endif
	; the self-test is diagnostics only, so it runs AFTER the confirmation: an error in it can no
	; longer cost the module its session
	SelfTest()
EndEvent

Function Maintenance()
	{[0.5.5] Called by OnLrgBoot on every game load, after LRG_Main's UnregisterForAllModEvents()
	 and RegisterKeys(), so every registration this module needs is made here again.}
	sceneSafetyRelaxed = false ; [v1.0.1] CHIM's conf does not survive a game restart: relax scene safety again on the first scene list
	;/[v1.0.1] CHIM's "NPC Scene Safety" (Behavior page; conf _restrict_onscene, ON by default) refuses anyone inside a
	 Skyrim scene as the player's listener and hands his words to the Narrator - every conversation an NPC starts.
	 The owner could not find the toggle: the glue switches it off at every load (the call CHIM's own MCM makes),
	 and again on the first scene list, in case CHIM re-applies its saved state after us./;
	AIAgentFunctions.setConf("_restrict_onscene", 0.0, 0, "")
	LRG_Main mm = Main()
	if mm != None
		mm.LogC("", "CHIM scene safety switched off at load (_restrict_onscene 0): people inside a scene can be talked to", "")
	endif
	attached = true
	EnsureArrays()
	setAt = 0.0
	ReadSettings()
	; nothing about a session may survive a load
	dlgState = ST_IDLE
	loopRunning = false
	; [0.5.1 pt9 go-live, G6] a wind-down and a pending re-arm belong to the session that was lost
	finishing = false
	rearmWanted = false
	rearmAt = 0.0
	loopBeat = 0.0
	readMode = 0
	formsDone = false
	sgDone = false
	perkAt = 0.0
	vkSweepAt = 0.0
	forceVisible = false
	ClearSession()
	ClearRequest(true)
	int i = 0
	while i < 8
		xRing[i] = ""
		fxRef[i] = None
		fxAt[i] = 0.0
		i += 1
	endwhile
	xSlot = 0
	fxSlot = 0
	fxScNpc = None
	fxScScene = None
	fxScAt = 0.0
	dOpensNoMatch = 0
	dSceneRefusals = 0
	dLayersLost = 0
	dStops = 0
	dCountFails = 0
	dAwards = 0
	dDrifts = 0
	dClickAborts = 0
	calSentAt = 0.0
	calNoProbe = false
	calSub = ""

	UnregisterForMenu(MENU_DLG)
	RegisterForMenu(MENU_DLG)
	RegisterForModEvent(PUMP_EVENT, "OnLrgDlgPump")
	RegisterForCrosshairRef()

	; LRG_DlgUI keeps its calibration in a per-save store, so a new game session starts from
	; scratch; an MCM value of 3 (read) or 1..3 (count) then FORCES the mode instead. [v1.0] the
	; menu is never hidden, so mode 4 (it walks iSelectedIndex) is never used: a forced 4 reads as 3.
	LRG_DlgUI.ResetCalibration()
	if sReadMode == 3 || sReadMode == 4
		LRG_DlgUI.SetReadMode(3)
		readMode = 3
	endif
	if sCountMode >= 1 && sCountMode <= 3
		LRG_DlgUI.SetCountMode(sCountMode)
	endif
	; [0.5.5] SelfTest() is called by OnLrgBoot, after the boot has been confirmed to LRG_Main.
EndFunction

LRG_DlgProbe Function Probe()
	;/The calibration engine (lane A's file). One cast, exactly like Main(). None means the probe
	 script is not attached to this save's quest form - every calibration hook then does nothing and
	 the dry-run gate refuses to judge rather than locking the owner out (plan R3)./;
	return (self as Quest) as LRG_DlgProbe
EndFunction

; ---------------------------------------------------------------------------
; [v1.0] the two dry runs, the learning counter, the route proof and the journal-scene test
; ---------------------------------------------------------------------------
bool Function MenulessLive()
	{The driver's state for LRG_Profile's ml= and the facts line: bMenuless and NOT the owner's
	 menuless dry run (spec S3.4: unchanged formula, nothing forces it; ml=1 is the normal state).}
	ReadSettings()
	return sMenuless && !sDryRun
EndFunction

int Function CalAnsweredNow()
	{How many of the four calibration rows are answered (0 when the probe is not attached): cal=.}
	LRG_DlgProbe p = Probe()
	if !p
		return 0
	endif
	return p.CalAnswered()
EndFunction

bool Function CalGreenNow()
	;/The calibration gate for a click, refreshed from the probe while it is still red: the four
	 passive rows are learned DURING a session (CalArm at arming, CalLayer on the first list), so
	 the 5 s settings cache must not hold back the first click of the evening. A missing probe
	 script never locks the owner out (ReadCalibration sets calGreen then)./;
	if !calGreen
		LRG_DlgProbe p = Probe()
		if p
			calGreen = p.CalGreen()
		endif
	endif
	return calGreen
EndFunction

bool Function RouteLive()
	{True when a real click on this install proved the click route (spec S3.2: route src=live).}
	LRG_DlgProbe p = Probe()
	if !p
		return false
	endif
	return p.CalRouteLive()
EndFunction

bool Function CanDriveScene()
	{S1.1 as amended [v1.0.1]: a menu inside a journal-quest scene is driven with bDriveSceneMenus alone.
	 The route proof (RouteLive) no longer gates it: the owner wants Arngeir's summons to work at first
	 contact, and the click's own verification (SelectAndVerify) is the safety.}
	return sDriveScene
EndFunction

string Function DlgDryReason()
	;/[v1.0 / S3.4] Why a click was refused while the menu stays on screen and the session goes on.
	 Two switches, two texts: the owner's own bDlgDryRun, and a calibration that is not green yet
	 (its four rows are learned from the next conversation of any kind - unless the probe knows that
	 talking will not finish it, CalLearnable, and then it says what will). Neither contains
	 "dry-run", so the developer switch's wording never claims them; LRG_Main.SayReason puts each
	 into her words, and the server's lrgVoicedDryKind reads the same two phrases./;
	if sDryRun
		return "the menuless dry run is on (Menuless questing page)"
	endif
	LRG_DlgProbe p = Probe()
	if p && !p.CalLearnable()
		string stuck = p.CalStuckWhy()
		if stuck == ""
			stuck = "a Smart Talk setting blocks it (see the Calibration page)"
		endif
		return "still learning the dialogue menu - another conversation will not finish it: " + stuck
	endif
	return "still learning the dialogue menu - the next conversation of any kind measures it"
EndFunction

string Function SceneBasis(Actor akNpc, Scene akScene, bool abQuiet, bool abFresh)
	;/[v1.0 / S1.1] "<sq>|<sqj>" for akScene - the test SendFacts has always run for sq= / sqj=: the
	 EditorID of the scene's OWNING quest, and 1 when that quest shows an objective that is displayed,
	 not completed and not failed (LRG_Main.EscortHasJournal). Never the PO3 alias sweep
	 (HasActiveJournalQuest) and never a bare scene: Hulda's tavern patter and a bard's song are scenes
	 without a journal objective, Irileth at the gate and Balgruuf's court are not. While quiet mode
	 runs nothing is walked (sqj 0; Arm() grades the session read-only anyway). abFresh = false may
	 reuse the last facts line's answer for the SAME Scene of the same NPC when < 30 s old (0 natives).
	 A string, not members: SendFacts (crosshair) and Arm (the loop) may run at the same time./;
	if akScene == None || akNpc == None
		return "-|0"
	endif
	float now = Utility.GetCurrentRealTime()
	if !abFresh && !abQuiet && akNpc == fxScNpc && akScene == fxScScene && now >= fxScAt && (now - fxScAt) < 30.0
		return fxScSq + "|" + fxScSqj
	endif
	LRG_Main m = Main()
	if !m
		return "-|0"
	endif
	Quest q = akScene.GetOwningQuest()
	if q == None
		return "-|0"
	endif
	string sq = "-"
	string qid = q.GetID()
	if qid != ""
		sq = m.CleanForWire(qid)
	endif
	if abQuiet
		return sq + "|0"
	endif
	int sqj = 0
	if m.EscortHasJournal(q)
		sqj = 1
	endif
	fxScNpc = akNpc
	fxScScene = akScene
	fxScSq = sq
	fxScSqj = sqj
	fxScAt = now
	return sq + "|" + sqj
EndFunction

Function SceneTake(string asBasis)
	{Splits a SceneBasis() answer into the session's sessSq / sessSqj.}
	int bar = StringUtil.Find(asBasis, "|")
	if bar < 0
		sessSq = "-"
		sessSqj = 0
		return
	endif
	sessSq = StringUtil.Substring(asBasis, 0, bar)
	sessSqj = StringUtil.Substring(asBasis, bar + 1) as int
EndFunction

Function EnsureArrays()
	if xRing.Length != 8
		xRing = new string[8]
	endif
	if eText.Length != 24
		eText = new string[24]
		eIdx = new int[24]
	endif
	if tText.Length != 40
		tText = new string[40]
	endif
	if lnText.Length != 4
		lnText = new string[4]
		lnGen = new int[4]
	endif
	if vkRef.Length != 128
		vkRef = new Form[128]
		vkVoice = new VoiceType[128]
	endif
	if fxRef.Length != 8
		fxRef = new Form[8]
		fxAt = new float[8]
	endif
	if sgv.Length != 6
		sgv = new GlobalVariable[6]
	endif
	if sgOpen.Length != 6
		sgOpen = new float[6]
	endif
EndFunction

LRG_Main Function Main()
	return (self as Quest) as LRG_Main
EndFunction

Function SelfTest()
	;/The self-test block of design 1.11. Papyrus logging is off in LoreRim, so these lines are the
	 record: they go to the server log as GAME lines. The SWF family, iMaxItemsShown and iPlatform
	 can only be read while the menu is open, so they are logged at ARMING instead./;
	LRG_Main m = Main()
	if !m
		return
	endif
	m.LogC("", "dlg self-test v513 menuless=" + I(sMenuless) + " dryrun=" + I(sDryRun) \
		+ " devdry=" + I(m.IsDryRun()) + " cal=" + CalAnsweredNow() + " wire=" + I(sWire) \
		+ " scenegate=" + sSceneGate + " drivescene=" + I(sDriveScene) + " autoadvance=" + I(sAutoAdvance) \
		+ " route=" + sClickRoute + " read=" + sReadMode + " count=" + sCountMode + " max=" + sMaxEntries \
		+ " tail=" + sTailMax, "")
	m.LogC("", "dlg self-test chim tts=" + AIAgentFunctions.get_conf_i("_player_tts_traditional_dialogue") \
		+ " capbg=" + AIAgentFunctions.get_conf_i("_capture_background_chat") \
		+ " onscene=" + AIAgentFunctions.get_conf_i("_restrict_onscene") \
		+ " openmic=" + AIAgentFunctions.get_conf_i("_openmic_enabled") \
		+ " autoradius=" + AIAgentFunctions.get_conf_i("_player_auto_include_radius_m") \
		+ " subs=" + I(LRG_DlgUI.SubtitlesOn()) + " smarttalk=" + I(smartSafe) \
		+ " offender=" + LRG_DlgUI.SmartTalkOffender(), "")
	m.LogC("", "dlg self-test speech globals " + SgCsv(ReadSpeechGlobals()) \
		+ " (VeryEasy,Easy,Average,Hard,VeryHard) mult=" + (SkillMult() as int) \
		+ " live readmode=" + LRG_DlgUI.ReadMode() + " countmode=" + LRG_DlgUI.CountMode(), "")
	if !smartSafe
		; Smart Talk writes bAllowProgress natively, so an ini line is the only real fix (D-21).
		Debug.Notification("LoreRim Glue: Smart Talk " + LRG_DlgUI.SmartTalkOffender() + " must be 0")
	endif
	;/v0.5: the whole calibration picture on every load and on every topic-dump press. CalLogSummary()
	 is the probe's own CALIB SUMMARY / CALIB GATE pair; the line below is the driver's half./;
	LRG_DlgProbe p = Probe()
	if p
		p.CalLogSummary()
		m.LogC("", "dlg self-test calibration green=" + I(calGreen) + " missing=" + Missing(calMiss) \
			+ " rm=" + calRm + " cm=" + calCm + " route=" + calRoute + " route_live=" + I(p.CalRouteLive()) \
			+ " x1=" + p.CalGet("x1", 0), "")
	else
		m.LogC("", "dlg self-test calibration: LRG_DlgProbe is not attached - no gate, no hooks", "")
	endif
EndFunction

string Function Missing(string asMiss)
	{"" is green; the log never prints an empty value.}
	if asMiss == ""
		return "none"
	endif
	return asMiss
EndFunction

Function ReadSettings(float afMaxAge = 5.0)
	;/[pt15 performance] afMaxAge: every caller keeps the 5 s cache except the crosshair (ev=facts), which
	 accepts 30 s. [v1.0] every in-code default equals its settings.ini line (tools/test_mcm_wiring.php)./;
	float now = Utility.GetCurrentRealTime()
	if setAt > 0.0 && now >= setAt && (now - setAt) < afMaxAge
		return
	endif
	setAt = now
	LRG_Main m = Main()
	if !m
		return
	endif
	sMenuless = m.SettingBool("bMenuless:Dialogue", true)
	sDryRun = m.SettingBool("bDlgDryRun:Dialogue", false)
	sWire = m.SettingBool("bDlgWire:Dialogue", true)
	sSceneGate = m.SettingInt("iSceneGate:Dialogue", 1)
	sAutoAdvance = m.SettingBool("bAutoAdvance:Dialogue", true)
	sDriveScene = m.SettingBool("bDriveSceneMenus:Dialogue", true)
	sDecide = m.SettingFloat("fDecideTimeout:Dialogue", 4.0)
	sLineSettle = m.SettingFloat("fLineSettle:Dialogue", 0.4)
	sOpenDist = m.SettingFloat("fOpenDistance:Dialogue", 200.0)
	sClickRoute = m.SettingInt("iClickRoute:Dialogue", 0)
	sReadMode = m.SettingInt("iReadMode:Dialogue", 0)
	sCountMode = m.SettingInt("iCountMode:Dialogue", 0)
	sMaxEntries = m.SettingInt("iMaxEntries:Dialogue", 16)
	sTailMax = m.SettingInt("iTailMax:Dialogue", 16)
	sIntentOpen = m.SettingBool("bIntentOpen:Dialogue", true)
	sAllowNull = m.SettingBool("bAllowNullVoice:Dialogue", false)
	sActivateDefault = m.SettingBool("bActivateDefaultOnly:Dialogue", false)
	sQuestAware = m.SettingBool("bQuestAware:Quests", true)
	sCheckStats = m.SettingBool("bCheckStats:Checks", false)
	sFreeChecks = m.SettingBool("bFreeChecks:Checks", true)
	sServiceDlg = m.SettingBool("bServiceDialogue:Services", true)
	;/[0.5.1 pt9 go-live, G11] smartSafe is re-read here, at most one MiscUtil.ReadFromFile per five
	 seconds (the answer is kept in the store anyway): the Calibration page turns green as soon as the
	 owner saves SmartTalk.ini, and Arm() must see the same answer./;
	smartSafe = LRG_DlgUI.SmartTalkSafe()
	ReadCalibration()
	; clamps: a value the owner typed must never break the driver
	if sMaxEntries < 1
		sMaxEntries = 1
	endif
	if sMaxEntries > 24
		sMaxEntries = 24
	endif
	if sTailMax < 0
		sTailMax = 0
	endif
	if sTailMax > 40
		sTailMax = 40 ; tText holds 40
	endif
	if sDecide < 1.0
		sDecide = 1.0
	endif
	if sLineSettle < 0.0
		sLineSettle = 0.0
	endif
	if sOpenDist < 50.0
		sOpenDist = 50.0
	endif
EndFunction

Function ReadCalibration()
	;/v0.5 E1(e), [v1.0 / S3.4] (a)-(c) only. Every setting that ships at "automatic" has a MEASURED
	 answer to fall back on before the live guesswork runs, and the owner's own number always wins
	 when he typed one. Called from ReadSettings(), so the probe is asked at most every 5 s. The
	 timings can only ever be made LONGER than the MCM value - his number is the floor, never the
	 ceiling (plan 6.5). Nothing here forces, holds or clears a dry run any more./;
	LRG_Main m = Main()
	LRG_DlgProbe p = Probe()
	if !p
		if !calNoProbe
			calNoProbe = true
			calGreen = true  ; nothing to judge with: never lock the owner out over a missing script
			calMiss = ""
			if m
				m.LogC("", "dlg calibration: LRG_DlgProbe is not attached - the click gate cannot judge and does not refuse", "")
			endif
		endif
		return
	endif
	calNoProbe = false
	calRm = p.CalGet("rm", 0)
	calCm = p.CalGet("cm", 0)
	calRoute = p.CalGet("route", 0)
	calGreen = p.CalGreen()
	calMiss = p.CalMissing()
	; (a) reading and counting. 0 in the MCM means "use what the glue measured for itself"; the
	; live CalibrateRead() still runs when the calibration has no answer either.
	if sReadMode == 0 && (calRm == 3 || calRm == 4)
		sReadMode = 3 ; [v1.0] a visible menu is read in mode 3 (mode 4 walks iSelectedIndex)
	endif
	if sCountMode == 0 && calCm >= 1 && calCm <= 3
		sCountMode = calCm
		LRG_DlgUI.SetCountMode(calCm)
	endif
	; (b) how many entries one layer can afford: keep a full read under ~0.9 s on THIS machine.
	; ms3 is the measured cost of twelve reads, so 900 * 12 / ms3 entries fit in the budget.
	if sMaxEntries == 0
		int ms3 = p.CalGet("ms3", 0)
		int cap = 16
		if ms3 > 0
			cap = (900 * 12) / ms3
		endif
		if cap < 4
			cap = 4
		elseif cap > 24
			cap = 24
		endif
		sMaxEntries = cap
	endif
	; (c) the two timings, only ever upwards (the bAutoTimings switch is retired: always on)
	int timer = p.CalGet("timer", 0)
	if timer == 2 && sLineSettle < 0.8
		sLineSettle = 0.8   ; the progress timer only moves once per SESSION here: settle longer
	elseif timer == 1 && sLineSettle < 0.3
		sLineSettle = 0.3
	endif
	float payload = ((p.CalGet("ms3", 0) + p.CalGet("tail", 0)) as float) / 1000.0
	if sDecide < (1.5 + payload)
		sDecide = 1.5 + payload
	endif
EndFunction

Function EnsureForms()
	if formsDone
		return
	endif
	formsDone = true
	goldForm = Game.GetFormFromFile(0x0000000F, "Skyrim.esm")
	perkSilver = Game.GetFormFromFile(0x00058F72, "Skyrim.esm") as Perk
	perkPers = Game.GetFormFromFile(0x001090A2, "Skyrim.esm") as Perk
	amuletList = Game.GetFormFromFile(0x000F759C, "Skyrim.esm") as FormList
	raceWolf = Game.GetFormFromFile(0x000CDD84, "Skyrim.esm")
	raceVamp = Game.GetFormFromFile(0x0000283A, "Dawnguard.esm")
	nullVT = Game.GetFormFromFile(0x0001D70E, "AIAgent.esp") as VoiceType
EndFunction

Function ResolveGlobals()
	;/The five Speech difficulty globals and SpeechSkillMult, with the FULL form ids - never the
	 truncated 0x16A3 that Speechcraft Randomization's own bug uses. Their values are per-save
	 random numbers (taking Silver Tongue re-rolls four of them once), so they are read LIVE./;
	if sgDone
		return
	endif
	sgDone = true
	EnsureArrays()
	sgv[0] = Game.GetFormFromFile(0x000D16A3, "Skyrim.esm") as GlobalVariable
	sgv[1] = Game.GetFormFromFile(0x000D16A4, "Skyrim.esm") as GlobalVariable
	sgv[2] = Game.GetFormFromFile(0x000D16A5, "Skyrim.esm") as GlobalVariable
	sgv[3] = Game.GetFormFromFile(0x000D1953, "Skyrim.esm") as GlobalVariable
	sgv[4] = Game.GetFormFromFile(0x000D1954, "Skyrim.esm") as GlobalVariable
	sgv[5] = Game.GetFormFromFile(0x0010E725, "Skyrim.esm") as GlobalVariable
EndFunction

float[] Function ReadSpeechGlobals()
	ResolveGlobals()
	float[] out = new float[6]
	int i = 0
	while i < 6
		if sgv[i]
			out[i] = sgv[i].GetValue()
		endif
		i += 1
	endwhile
	return out
EndFunction

float Function SkillMult()
	ResolveGlobals()
	if sgv[5]
		return sgv[5].GetValue()
	endif
	return 0.0
EndFunction

string Function SgCsv(float[] afValues)
	string s = ""
	int i = 0
	while i < 5
		if i > 0
			s += ","
		endif
		s += (afValues[i] as int)
		i += 1
	endwhile
	return s
EndFunction

; ---------------------------------------------------------------------------
; The public surface LRG_Main uses (design 7.1)
; ---------------------------------------------------------------------------
bool Function IsSessionOpen()
	{True while this module is opening, driving or watching a live Dialogue Menu session. False as
	 soon as the loop's stack has been lost for 10 s, so no other feature is ever blocked forever.}
	if dlgState == ST_IDLE
		return false
	endif
	float now = Utility.GetCurrentRealTime()
	if now < loopBeat || (now - loopBeat) > 10.0
		return false
	endif
	return true
EndFunction

bool Function IsDriving()
	{True only while the glue really owns this session. In MANUAL, CLOSING and OPENING the menu is
	 the player's or on its way out, and no decision from the server may be carried out.}
	if !IsSessionOpen()
		return false
	endif
	if dlgState == ST_MANUAL || dlgState == ST_CLOSING || dlgState == ST_OPENING
		return false
	endif
	return true
EndFunction

Function HandleLeaveKey()
	{The leave key: the same path the server's do=leave takes. On a critical session nothing is
	 closed - the list is his (the lethal path).}
	if !attached || !IsSessionOpen()
		return
	endif
	if crit == 2
		reqShow = true
		return
	endif
	reqLeave = true
EndFunction

Function DumpTopics()
	{iKeyDumpTopics: logs the list the glue sees entry by entry in every read mode and sends it as
	 lrg_topics origin=dump. The menu stays visible. This is the T2 parity tool.}
	if !attached
		Debug.Notification("LoreRim Glue: the menuless questing module is not attached")
		return
	endif
	ReadSettings()
	dumpAt = Utility.GetCurrentRealTime()
	if LRG_DlgUI.IsOpen()
		if loopRunning
			reqDump = true
		else
			DoDump()
		endif
		return
	endif
	Actor npc = Game.GetCurrentCrosshairRef() as Actor
	if npc == None || npc.IsDead()
		Debug.Notification("LoreRim Glue: look at an NPC first")
		return
	endif
	; a dump is never a business session: it opens a menu the driver does not drive (drv=0) and never
	; clicks. forceVisibleAt bounds the wish: an Activate that never opened must not make the NEXT
	; conversation (minutes later, anyone) an undriven one.
	forceVisible = true
	forceVisibleAt = Utility.GetCurrentRealTime()
	reqDump = true
	glueOpened = false
	Main().LogC("", "dump: opening a visible menu with " + npc.GetDisplayName(), npc.GetDisplayName())
	npc.Activate(Game.GetPlayer())
EndFunction

; ---------------------------------------------------------------------------
; ExtCmdLRG_SelectTopic - validate, stamp, RETURN. No wait of any kind (design D-20).
; ---------------------------------------------------------------------------
bool Function CmdSelectTopic(string asNpcName, string asCommand, string asParam)
	{The single Phase 2 action. It returns inside one Papyrus frame and emits a funcret only for a
	 synchronous refusal of design 1.5; every other outcome is reported by the loop, once per x.}
	LRG_Main m = Main()
	if !m
		return false
	endif
	string verb = m.ParamGet(asParam, "do")
	string cid = m.ParamGet(asParam, "cid")
	string x = m.ParamGet(asParam, "x")
	if verb == "award"
		return CmdAward(asNpcName, asCommand, asParam)
	endif
	if verb == "release"
		;/v0.5 W8 / E4(b). The server has admitted a movement command for an NPC the conversation
		 hold is holding still. Releasing a hold is always safe, from any path, at any time - so this
		 branch is gated by the HOLD's own switch and by nothing else: not by bMenuless (it has
		 nothing to do with the menu) and not by dry-run (leaving her frozen is the unsafe state).
		 ReleaseConvHold() is idempotent and clears every field before it touches the actor./;
		if x != "" && XSeen(x)
			return true
		endif
		m.LogC(cid, "select do=release: the server admitted a movement command", asNpcName)
		m.ReleaseConvHold("the server admitted a movement command")
		XPush(x)
		m.ReportResult(asNpcName, asCommand, asParam, "OK: Noted.")
		return true
	endif
	; the second delivery route of one decision: silent, no funcret, no commandEndedForActor
	if x != "" && XSeen(x)
		m.LogC(cid, "select: x=" + x + " already handled, second delivery dropped", asNpcName)
		return true
	endif
	;/[v1.0 / model F5] ...and its twin while the FIRST copy is still waiting (a pick for its list, an
	 adv pick for its anchor): the ring is written at click time only, so the D2 copy used to pass
	 XSeen, close the first copy with an error and stamp itself again. The waiting copy answers for both./;
	if x != "" && ((x == reqX && !reqReported) || x == qX)
		m.LogC(cid, "select: x=" + x + " is already waiting - second delivery dropped", asNpcName)
		return true
	endif
	if !attached
		m.ReportResult(asNpcName, asCommand, asParam, "Error: not available")
		return true
	endif
	; [v1.0 review / model F5] every x answered here goes into the ring first: its D2 twin is dropped, never answered twice
	ReadSettings()
	if !sMenuless || !m.IsEnabled()
		XPush(x)
		m.ReportResult(asNpcName, asCommand, asParam, "Error: the feature is switched off")
		return true
	endif
	if m.ParamGet(asParam, "ok") != "1"
		XPush(x)
		m.ReportResult(asNpcName, asCommand, asParam, "Error: not authorised by the server gate")
		return true
	endif
	Actor npc = AIAgentFunctions.getAgentByName(asNpcName)
	if npc == None
		XPush(x)
		m.ReportResult(asNpcName, asCommand, asParam, "Error: they cannot talk right now")
		return true
	endif
	if !RefMatches(m.ParamGet(asParam, "ref"), npc)
		XPush(x)
		m.ReportResult(asNpcName, asCommand, asParam, "Error: the actor could not be found")
		return true
	endif
	bool live = IsSessionOpen()
	if live && speaker != None && speaker != npc
		XPush(x)
		m.ReportResult(asNpcName, asCommand, asParam, "Error: a conversation is in progress")
		return true
	endif
	if live && dlgState == ST_CLOSING
		; [v1.0 review] the menu is closing (a leave): no list is on his screen any more
		XPush(x)
		if verb == "leave"
			m.ReportResult(asNpcName, asCommand, asParam, "OK: The conversation is left.")
		elseif verb == "pick"
			m.ReportResult(asNpcName, asCommand, asParam, "Error: that moment has passed")
		else
			m.ReportResult(asNpcName, asCommand, asParam, "OK: Noted.")
		endif
		return true
	endif
	if live && !IsDriving() && dlgState != ST_OPENING
		XPush(x)
		;/[v1.0 / S1.1] A session is open but the driver does not drive it (drv=0 - a journal scene
		 before the route is proven, lethal, quiet, the dump - or StopDriving). The server never emits
		 a pick to such a session, so this is the version-skew backstop: nothing is clicked and the
		 decision is closed here in words that are TRUE of a line on his screen - "that is not on the
		 table" only for a pick whose entry is not on the live list. A session still OPENING is not
		 refused: the decision is stamped and resolved against its list once it is read./;
		if verb == "pick"
			if PickOnList(m.ParamGet(asParam, "txt"), ToInt(m.ParamGet(asParam, "pos"), -1))
				m.LogC(cid, "select refused: the session is not driven - the entry is on his list", asNpcName)
				m.ReportResult(asNpcName, asCommand, asParam, "Error: choose that one on the list yourself")
			else
				m.LogC(cid, "select refused: the session is not driven and the entry is not on the list", asNpcName)
				m.ReportResult(asNpcName, asCommand, asParam, "Error: that is not on the table right now")
			endif
		elseif verb == "show"
			m.ReportResult(asNpcName, asCommand, asParam, "OK: The menu is shown.")
		elseif verb == "noop" || verb == "open"
			m.ReportResult(asNpcName, asCommand, asParam, "OK: Noted.")
		else
			m.LogC(cid, "select refused: the session is not driven (do=" + verb + ")", asNpcName)
			m.ReportResult(asNpcName, asCommand, asParam, "Error: choose that one on the list yourself")
		endif
		return true
	endif
	;/[v1.0 review / model rows 21, 27] A click is being verified: its request stays whole until it has
	 settled (SettleAndReport answers ITS x, ev=result describes ITS entry). A newer command waits in
	 the one-slot queue and Step replays it after the settle; an older waiting one is overtaken./;
	if live && dlgState == ST_RESPONDING && reqCmd != "" && !reqReported
		if qCmd != ""
			m.LogC(cid, "select: pick overtaken - the waiting x=" + qX + " is closed by the newer x=" + x, asNpcName)
			XPush(qX)
			m.ReportResult(qNpc, qCmd, qParam, "OK: Noted.")
		endif
		qX = x
		qNpc = asNpcName
		qCmd = asCommand
		qParam = asParam
		m.LogC(cid, "select: x=" + x + " waits - the click in flight is being verified", asNpcName)
		return true
	endif
	;/[0.5.1 pt9 go-live, G5] + [v1.0 / model F26] CLOSE THE REQUEST THIS ONE IS ABOUT TO OVERWRITE.
	 Two decisions for the same NPC can be in flight at once (the D2 echo of an earlier x while a
	 want=1 x is stamped, a do=show after a do=pick). The first x must still get exactly one funcret,
	 and a newer decision OVERTAKES the older one: it is closed silently (OK: Noted., the x into the
	 ring), never with an error the NPC would apologise for./;
	if reqX != "" && !reqReported
		m.LogC(cid, "select: pick overtaken - x=" + reqX + " is closed by the newer x=" + x, asNpcName)
		XPush(reqX)
		ReportOnce("OK: Noted.")
	endif
	; stamp the request for the loop
	reqDo = verb
	reqCid = cid
	reqX = x
	reqNpc = asNpcName
	reqRef = m.ParamGet(asParam, "ref")
	reqSid = m.ParamGet(asParam, "sid")
	reqGen = ToInt(m.ParamGet(asParam, "gen"), 0)
	reqPos = ToInt(m.ParamGet(asParam, "pos"), -1)
	reqIdx = ToInt(m.ParamGet(asParam, "i"), -1)
	reqTxt = m.ParamGet(asParam, "txt")
	reqKind = m.ParamGet(asParam, "kind")
	reqCost = ToInt(m.ParamGet(asParam, "cost"), 0)
	reqAsk = m.ParamGet(asParam, "ask")
	reqVt = m.ParamGet(asParam, "vt")
	reqParam = asParam
	reqCmd = asCommand
	reqAt = Utility.GetCurrentRealTime()
	reqReported = false
	reqPickReady = false
	staleCount = 0 ; [v1.0 review / model row 20] two misses of THIS pick, not of the session
	staleWhy = ""
	clickTxt = ""
	; [v1.0 / S4.6] adv=<ms> (and rearm=1) on a pick: an auto-advance, clicked on its anchor
	reqAdv = -1
	reqRearm = false
	if verb == "pick"
		string adv = m.ParamGet(asParam, "adv")
		if adv != ""
			reqAdv = ToInt(adv, -1)
			reqRearm = m.ParamGet(asParam, "rearm") == "1"
		endif
	endif
	; [v1.0 review r2 / F7-F8] a cid licenses only its own session: a live one's first decision, or the pick/open that
	; OPENS one (replacing any leftover). A leave/show/noop/unknown with no session open opens nothing: no licence
	if live
		if sessCid == ""
			sessCid = cid
		endif
	elseif verb == "pick" || verb == "open"
		sessCid = cid
	endif

	if verb == "leave"
		if !live
			ReportOnce("Error: that moment has passed")
			ClearRequest(false)
		else
			reqLeave = true
		endif
	elseif verb == "show"
		if live
			reqShow = true
		else
			; [v1.0] the menu is never hidden, so there is nothing to show and nothing to remember
			ReportOnce("OK: The menu is shown.")
			ClearRequest(false)
		endif
	elseif verb == "noop"
		m.LogC(cid, "select do=noop ok", asNpcName)
		ReportOnce("OK: Noted.")
		ClearRequest(false)
	elseif verb == "pick"
		if live
			if reqSid != "" && reqSid == sid && reqGen > 0 && reqGen < layerGen
				; [model F26] the layer CHANGED under this pick (his own click, the engine's push): it is
				; overtaken - closed silently, and the fresh list goes out once for a re-match. [v1.0 review /
				; P2] layerGen, not gen: a forced re-read of the SAME list moves gen only, and such a pick still
				; resolves on it (TryResolvePick)
				if staleSentGen != gen
					staleSentGen = gen
					reqWant = true
				endif
				m.LogC(cid, "pick overtaken - it was made for gen " + reqGen + ", the list changed at gen " + layerGen, asNpcName)
				XPush(x)
				ReportOnce("OK: Noted.")
				ClearRequest(false)
			else
				reqPickReady = true
			endif
		else
			;/[v1.0 review / model row 2, S7, PHASE2_DESIGN first contact step 3] a pick for a session that is not
			 open (her cached root, sid=0, or a session that died mid-turn) re-opens her list and is found
			 on it by its txt prefix (TryResolvePick). Without reqPickReady that match was never tried, and
			 the decide window closed the pick unclicked - after her line had been muted for the
			 click. Only with a txt: without one (an entry shorter than 12 characters) only the server can
			 find it, so the first list must still go out want=1. StartOpen's refusal clears it again./;
			reqPickReady = reqTxt != ""
			StartOpen(npc, asNpcName)
		endif
	elseif verb == "open"
		if live
			reqWant = true
			ReportOnce("OK: Noted.")
			ClearRequest(false)
		else
			StartOpen(npc, asNpcName)
		endif
	else
		ReportOnce("Error: unknown command")
		ClearRequest(false)
	endif
	return true
EndFunction

bool Function PickOnList(string asTxt, int aiPos)
	{Is the entry a pick names on the live list? By its txt= prefix (the wire form, TxtMatch) over the
	 head AND the tail read, else by a position inside the list.}
	if asTxt != ""
		int i = 0
		while i < eHead
			if TxtMatch(eText[i], asTxt)
				return true
			endif
			i += 1
		endwhile
		i = 0
		while i < tCount
			if TxtMatch(tText[i], asTxt)
				return true
			endif
			i += 1
		endwhile
		return false
	endif
	return aiPos >= 0 && aiPos < nTotal
EndFunction

Function StartOpen(Actor akNpc, string asNpcName)
	;/Runs the design 1.5 gates (all non-latent natives) and hands the actual Activate to the pump,
	 so the command still returns inside one frame./;
	LRG_Main m = Main()
	string why = OpenBlockedReason(akNpc)
	if why != ""
		m.LogC(reqCid, "open refused: " + why, asNpcName)
		if reqDo == "pick" && reqSid != "" && reqSid != "0"
			;/[v1.0 / S7] a pick made for a session that has since closed (he tabbed out, she walked
			 off, the engine ended it): the re-open was refused, so her words say what happened to the
			 talk - the refusal itself stays in the log line above./;
			ReportOnce("Error: the conversation was interrupted")
		else
			ReportOnce("Error: " + why)
		endif
		ClearRequest(false)
		sessCid = "" ; [v1.0 review / model F7-F8] a refused open's cid must not license the next E-press
		return
	endif
	openNpc = akNpc
	openWanted = true
	dlgState = ST_OPENING
	loopBeat = Utility.GetCurrentRealTime()
	SendPump()
EndFunction

string Function OpenBlockedReason(Actor akNpc)
	;/Every reason here is a verbatim entry on PROTOCOL 1.6's closed list. Cheapest natives first;
	 nothing in this function waits./;
	if akNpc == None || akNpc.IsDead() || !akNpc.Is3DLoaded()
		return "the actor could not be found"
	endif
	Actor player = Game.GetPlayer()
	if akNpc.IsInCombat() || player.IsInCombat()
		return "combat"
	endif
	if LRG_DlgUI.IsOpen()
		return "a conversation is in progress"
	endif
	if Utility.IsInMenuMode()
		return "they cannot talk right now"
	endif
	LRG_Main m = Main()
	if m && m.QuietOn()
		; [v1.0 / model F17] quiet mode (PROTOCOL 10.28): no conversation is opened for him while a curated intro runs
		dSceneRefusals += 1
		return "a quest scene is running"
	endif
	Scene sc = akNpc.GetCurrentScene()
	if sc != None
		;/[v1.0 / S1.1, S2.2] iSceneGate 1 (ships): open on a scene actor unless the scene's OWNING
		 quest shows an unfinished journal objective - the same test as Arm() (SceneBasis), never the
		 PO3 alias sweep, which fired on every innkeeper who is an alias of a tracked quest. 0: never
		 open on a scene actor. The game still never OPENS on a journal-scene actor./;
		bool journal = true
		if sSceneGate != 0
			string sb = SceneBasis(akNpc, sc, false, false)
			journal = StringUtil.Find(sb, "|1") >= 0
		endif
		if journal
			dSceneRefusals += 1
			return "a quest scene is running"
		endif
	endif
	LRG_OStim ost = None
	if m
		ost = m.GetOStim()
	endif
	if ost
		if ost.IsSceneActiveOrStarting() || ost.IsActorInOurScene(akNpc)
			return "a scene is already running"
		endif
	endif
	if akNpc.GetSleepState() > 0 || akNpc.IsUnconscious() || akNpc.IsBleedingOut()
		return "they cannot talk right now"
	endif
	if player.IsSneaking() || player.IsOnMount() || PlayerIsBeast(player)
		return "they cannot talk right now"
	endif
	float lim = sOpenDist
	float engine = 0.85 * Game.GetGameSettingFloat("fAIInDialogueModeWithPlayerDistance")
	if engine > 0.0 && engine < lim
		lim = engine
	endif
	if akNpc.GetDistance(player) > lim
		return "too far apart"
	endif
	return ""
EndFunction

bool Function CmdAward(string asNpcName, string asCommand, string asParam)
	{do=award (design 6.9): the free-conversation effect carrier. Speech XP the way the engine
	 grants it, optionally gold, optionally one stat. It touches no menu, no flag and no stage.}
	LRG_Main m = Main()
	if !m
		return false
	endif
	string cid = m.ParamGet(asParam, "cid")
	string x = m.ParamGet(asParam, "x")
	if x != "" && XSeen(x)
		m.LogC(cid, "award: x=" + x + " already handled, second delivery dropped", asNpcName)
		return true
	endif
	ReadSettings()
	; do=award is the FREE-CONVERSATION carrier: it touches no menu, so it is gated by the checks
	; switch and the kill switch, NOT by bMenuless. Dry-run still blocks it (design 6.9 step 1).
	if !attached || !m.IsEnabled() || !sFreeChecks
		m.ReportResult(asNpcName, asCommand, asParam, "Error: the feature is switched off")
		return true
	endif
	if m.ParamGet(asParam, "ok") != "1"
		m.ReportResult(asNpcName, asCommand, asParam, "Error: not authorised by the server gate")
		return true
	endif
	Actor npc = AIAgentFunctions.getAgentByName(asNpcName)
	if npc == None
		m.ReportResult(asNpcName, asCommand, asParam, "Error: they cannot talk right now")
		return true
	endif
	if !RefMatches(m.ParamGet(asParam, "ref"), npc)
		m.ReportResult(asNpcName, asCommand, asParam, "Error: the actor could not be found")
		return true
	endif
	int take = ToInt(m.ParamGet(asParam, "take"), 0)
	bool xp = m.ParamGet(asParam, "xp") == "1"
	string stat = m.ParamGet(asParam, "stat")
	if take < 0
		take = 0
	endif
	;/[v1.0 / S6.2, gate B - ships inert: the server sends give= only once checks.reward.enabled is on]
	 give=<n>: a small, REAL bonus from HER OWN purse after a real Speech check. Never CHIM's GiveGoldTo,
	 which mints gold from a short purse. She can only hand over what she carries: a short purse gives
	 what there is, and the OK text says how much, so the server words it as "she found she had only N"./;
	int give = ToInt(m.ParamGet(asParam, "give"), 0)
	if give < 0
		give = 0
	endif
	Actor player = Game.GetPlayer()
	if sDryRun
		m.LogC(cid, "WOULD AWARD xp=" + I(xp) + " take=" + take + " give=" + give + " stat=" + stat, asNpcName)
		XPush(x)
		m.ReportResult(asNpcName, asCommand, asParam, "Error: " + DlgDryReason())
		return true
	endif
	EnsureForms()
	if take > 0
		if goldForm == None || player.GetItemCount(goldForm) < take
			m.ReportResult(asNpcName, asCommand, asParam, "Error: not enough gold")
			return true
		endif
		player.RemoveItem(goldForm, take, true, npc)
	endif
	float mult = 0.0
	float grant = 0.0
	if xp
		; verbatim what FavorDialogueScript.Persuade/Bribe/Intimidate do: SpeechSkillMult is read
		; LIVE (never hardcoded 75) and multiplied by the player's current Speechcraft.
		mult = SkillMult()
		if mult > 0.0
			grant = mult * player.GetActorValue("Speechcraft")
			Game.AdvanceSkill("Speechcraft", grant)
		endif
	endif
	if stat != "" && sCheckStats
		if stat == "Persuasions" || stat == "Bribes" || stat == "Intimidations"
			Game.IncrementStat(stat, 1)
		endif
	endif
	int gave = -1
	if give > 0 && goldForm != None
		gave = npc.GetItemCount(goldForm)
		if gave > give
			gave = give
		endif
		if gave > 0
			npc.RemoveItem(goldForm, gave, true, player)
		endif
	endif
	XPush(x)
	dAwards += 1
	m.LogC(cid, "award xp=" + I(xp) + " mult=" + mult + " grant=" + grant + " take=" + take \
		+ " give=" + give + " gave=" + gave + " stat=" + stat + " stats_on=" + I(sCheckStats), asNpcName)
	if gave >= 0
		m.ReportResult(asNpcName, asCommand, asParam, "OK: gave " + gave + " septims")
	else
		m.ReportResult(asNpcName, asCommand, asParam, "OK: Noted.")
	endif
	return true
EndFunction

; ---------------------------------------------------------------------------
; The pump: the two things that need a stack of their own outside a session
; ---------------------------------------------------------------------------
Function SendPump()
	int h = ModEvent.Create(PUMP_EVENT)
	if h
		ModEvent.Send(h)
	endif
EndFunction

Event OnLrgDlgPump()
	if !booted
		return
	endif
	;/[0.5.1 pt9 go-live, G6] The re-arm OnMenuOpen could not do itself. It waits for the old loop to
	 be completely gone, then arms the menu that is still open on this fresh stack. Bounded: if the old
	 stack was dumped and loopRunning never clears, the wish is dropped after 15 s rather than pumped
	 for ever, and the menu is then the player's - which is what an unarmed menu already is./;
	if rearmWanted
		float rn = Utility.GetCurrentRealTime()
		if rn < rearmAt || (rn - rearmAt) > 15.0
			rearmWanted = false
		elseif loopRunning
			SendPump() ; the previous session is still winding down
		else
			rearmWanted = false
			if LRG_DlgUI.IsOpen() && dlgState == ST_IDLE
				Main().LogC("", "a conversation opened while the previous one was closing - arming it now", "")
				RunSession()
			endif
		endif
	endif
	if openWanted
		DoOpen()
	endif
	float now = Utility.GetCurrentRealTime()
	if dlgState == ST_IDLE && ((now - vkSweepAt) > 30.0 || now < vkSweepAt)
		VkSweep()
	endif
EndEvent

Event OnUpdate()
	;/LRG_Main owns the one timer of this form and fans its own work out; this module registers no
	 timer and only piggybacks on the event. If it never arrives, the voice keeper still works from
	 the crosshair, from ARMING and from ev=facts.
	 [0.5.5] booted, not attached: that timer wakes this script from LRG_Main's very first boot
	 tick on, well before this module's own boot has run./;
	if !booted || dlgState != ST_IDLE
		return
	endif
	float now = Utility.GetCurrentRealTime()
	if (now - vkSweepAt) > 30.0 || now < vkSweepAt
		VkSweep()
	endif
EndEvent

Function DoOpen()
	openWanted = false
	Actor npc = openNpc
	LRG_Main m = Main()
	if npc == None || !attached || !m
		return
	endif
	if LRG_DlgUI.IsOpen()
		FailOpen("a conversation is in progress")
		return
	endif
	dlgState = ST_OPENING
	glueOpened = true
	sid = NewSid()
	gen = 0
	layer = 0
	anyClick = false
	loopBeat = Utility.GetCurrentRealTime()
	; the voice keeper, before Activate: a session opened seconds after the player spoke would
	; otherwise play the real voiced line silent and evaluate GetIsVoiceType wrongly (design F2)
	float t0 = Utility.GetCurrentRealTime()
	bool voiceOk = VkRestore(npc)
	while !voiceOk && (Utility.GetCurrentRealTime() - t0) < 3.0
		Utility.WaitMenuMode(0.3)
		loopBeat = Utility.GetCurrentRealTime()
		voiceOk = VkRestore(npc)
	endwhile
	if !voiceOk && !sAllowNull
		FailOpen("her voice is not ready")
		return
	endif
	Actor player = Game.GetPlayer()
	float tOpen = Utility.GetCurrentRealTime() ; v0.5: the clock of P0b's passive half starts HERE,
	int tries = 0                              ; after the voice keeper, at the first Activate
	bool opened = false
	while tries < 3 && !opened
		if sActivateDefault
			npc.Activate(player, true)
		else
			npc.Activate(player)
		endif
		float waitStart = Utility.GetCurrentRealTime()
		while !opened && (Utility.GetCurrentRealTime() - waitStart) < 1.0
			Utility.WaitMenuMode(0.1)
			loopBeat = Utility.GetCurrentRealTime()
			opened = LRG_DlgUI.IsOpen()
		endwhile
		tries += 1
	endwhile
	int openMs = ((Utility.GetCurrentRealTime() - tOpen) * 1000.0) as int
	if !opened
		FailOpen("they cannot talk right now")
		return
	endif
	;/v0.5, P0b's passive half. The probe's own P0b opens and closes six conversations to compare
	 the two Activate forms - that cannot happen behind the owner's back. This is the half that
	 matters and it is free: whenever the GLUE opens a session, how many tries it took and how long
	 it waited are recorded, and three bad opens in a row raise the bActivateDefaultOnly warning./;
	LRG_DlgProbe p = Probe()
	if p
		p.CalOpened(tries, openMs)
	endif
	m.LogC(reqCid, "opened sid=" + sid + " origin=glue tries=" + tries + " ms=" + openMs, npc.GetDisplayName())
	; [v1.0] ev=lat first=1 now marks the reply of the turn on which the glue opened her list (S2.1:
	; one paid turn, never a second one) - the first-contact latency the owner's evening report reads
	m.NoteFirstBusiness(npc.GetDisplayName())
	; OnMenuOpen has started (or is about to start) the loop, which arms the session
EndFunction

Function FailOpen(string asReason)
	Main().LogE(reqCid, "open failed: " + asReason, reqNpc)
	ReportOnce("Error: " + asReason)
	ClearRequest(false)
	glueOpened = false
	openNpc = None
	sessCid = ""
	dlgState = ST_IDLE
EndFunction

; ---------------------------------------------------------------------------
; The session loop (design 1.3). One loop owns the UI.
; ---------------------------------------------------------------------------
Event OnMenuOpen(string asMenuName)
	if asMenuName != MENU_DLG || !booted
		return
	endif
	float now = Utility.GetCurrentRealTime()
	if loopRunning
		;/[0.5.1 pt9 go-live, G6] A menu that opens while the PREVIOUS loop is inside Finish() used to
		 be refused an ARMING entirely - no GuardReset (rule R1's only repair), no hide, no lrg_topics,
		 no ev=open - because loopRunning is true and Finish() keeps refreshing loopBeat to its last
		 line. It cannot be armed HERE either: Finish() is still on its own stack and would tear the
		 new session down again on its way out (ClearSession, dlgState = ST_IDLE). So it is handed to
		 the pump, which arms it on a fresh stack the moment the old loop is really gone./;
		if finishing
			if !rearmWanted
				rearmWanted = true
				rearmAt = now
				SendPump()
			endif
			return
		endif
		if now >= loopBeat && (now - loopBeat) < 5.0
			return ; a live loop already owns this menu
		endif
		;/The previous loop's stack was lost (the script engine dumped it, or a load cut it). Take
		 this open over instead of returning: ARMING's GuardReset() has to run on EVERY open, or a
		 poisoned ALLOW_PROGRESS_DELAY could leave the next VANILLA menu unclickable (rule R1)./;
		Main().LogC("", "the previous session loop was lost - re-arming this dialogue open", "")
		loopRunning = false
		dlgState = ST_IDLE
	endif
	RunSession()
EndEvent

Function RunSession()
	loopRunning = true
	loopBeat = Utility.GetCurrentRealTime()
	Arm()
	float poll = 0.1
	bool go = dlgState != ST_IDLE
	while go
		float now = Utility.GetCurrentRealTime()
		loopBeat = now
		pollCount += 1
		Step(now)
		; [v1.0 / S1.2, model F28] 0.1 s only where a click is being placed or verified; 0.25 s while a
		; driven list waits; MANUAL / SUSPENDED keep their 0.5 s
		if dlgState == ST_CLICKING || dlgState == ST_RESPONDING
			poll = 0.1
		elseif dlgState == ST_MANUAL || dlgState == ST_SUSPENDED
			poll = 0.5
		else
			poll = 0.25
		endif
		Utility.WaitMenuMode(poll)
		now = Utility.GetCurrentRealTime()
		loopBeat = now
		go = dlgState != ST_IDLE && LRG_DlgUI.IsOpen() && now >= openTime && (now - openTime) < 900.0
	endwhile
	Finish()
	;/[0.5.1 pt9 go-live, G6] Order matters: loopRunning first, then finishing. Clearing finishing
	 while loopRunning were still true would put an open menu back into the "a live loop already owns
	 this" branch for the one statement in between./;
	loopRunning = false
	finishing = false
EndFunction

Function Arm()
	;/ARMING. Step 0 is GuardReset(), unconditionally, on EVERY branch (design R1): a poisoned
	 ALLOW_PROGRESS_DELAY that survived from an older build would leave the owner's next VANILLA
	 dialogue unclickable by mouse, E and Enter. The apd= measurement is taken first (one native).
	 [v1.0 / S1.1] Then classify - and that is all: nothing is hidden, guarded or parked. The list
	 stays on screen and the grade decides only whether the driver may click it (drv=1|0)./;
	int apd0 = LRG_DlgUI.AllowProgressDelay()
	LRG_DlgUI.GuardReset()
	LRG_Main m = Main()
	ReadSettings()
	EnsureArrays()
	float now = Utility.GetCurrentRealTime()
	openTime = now
	stateTime = now
	pollCount = 0
	pendingLayer = false
	readFails = 0
	readNoteLogged = false
	staleCount = 0
	frzHits = 0
	sig = ""
	lastSig = ""
	sentThisGen = false
	noCountAt = 0.0
	closeAsked = false
	rsSignal = false
	rsProof = false
	rsWatch = false
	rsClosed = false
	rsOther = ""
	staleSentGen = -1
	clickX = ""
	scanGen = -1
	eHead = 0
	tCount = 0
	nTotal = 0
	sessSvc = "-"
	layerSvc = "-"
	svcPriced = 0
	svcShort = 0
	calSub = ""
	held = false
	heldLines = 0
	lnSeq = 0
	ourClick = false
	hcGen = -1
	layerGen = 0
	layerStart = now
	lineSeenAt = 0.0
	blankSince = now
	clickAuto = false
	advLog = ""
	routeProved = false
	rlSess = -1
	clickFails = 0
	int i = 0
	while i < 4
		lnText[i] = ""
		lnGen[i] = -1
		i += 1
	endwhile
	mmBase = Utility.IsInMenuMode()
	fam = LRG_DlgUI.SwfFamily()
	maxItems = LRG_DlgUI.MaxItemsShown()
	platform = LRG_DlgUI.Platform()
	subsOn = LRG_DlgUI.SubtitlesOn()
	speaker = Game.GetDialogueTarget() as Actor
	if speaker == None
		speaker = Game.GetCurrentCrosshairRef() as Actor
	endif
	if speaker == None
		dlgState = ST_MANUAL
		if m
			m.LogC(sessCid, "arming: no dialogue target - the list is the player's", "")
		endif
		return
	endif
	npcName = speaker.GetDisplayName()
	if glueOpened && openNpc != None && speaker != openNpc
		;/The engine opened a conversation with somebody else in the same moment. The glue's open is
		 closed with its refusal, and THIS conversation arms as what it is: one the engine opened./;
		if m
			m.LogC(sessCid, "arming: the conversation opened with " + npcName + ", not " + openNpc.GetDisplayName() \
				+ " - the glue's open is closed and this one arms as the engine's", npcName)
		endif
		ReportOnce("Error: they cannot talk right now")
		ClearRequest(false)
		glueOpened = false
	endif
	openNpc = None
	if glueOpened
		origin = "glue"
	else
		origin = "engine"
		sid = NewSid()
		gen = 0
		layer = 0
		anyClick = false
		;/[v1.0 review / model F7-F8] an E-press carries no cid of an earlier request: a leftover one (a
		 refused open) would license a stored utterance of ANY age to decide this list. A do=open still
		 stamped here takes its own cid below./;
		sessCid = ""
	endif
	;/[v1.0 / model F4] The do=open that opened this conversation is answered HERE, once its session is
	 armed: OK: Noted., its x into the ring. Its ask= and cid= are kept for the first lrg_topics. It used
	 to be closed by the first pick as "that moment has passed" - a corner note on every good first
	 contact, and the server read it as a refused open./;
	openAsk = ""
	if reqDo == "open" && !reqReported
		openAsk = reqAsk
		if reqCid != ""
			sessCid = reqCid
		endif
		XPush(reqX)
		ReportOnce("OK: Noted.")
		if m
			m.LogC(sessCid, "select: do=open reported OK at arming (x=" + reqX + ")", npcName)
		endif
		ClearRequest(false)
	endif
	VkRecord(speaker)
	VkRestore(speaker)
	crit = ClassifyCrit(speaker)
	;/[v1.0 / S1.1, rev2 game R1] scene= keeps today's meaning (any scene); sj = a scene whose OWNING
	 quest shows an unfinished journal objective (SceneBasis: the facts line's answer when it is < 30 s
	 old and the same Scene, else GetOwningQuest + the objective walk). Quiet mode (model F17) never
	 walks and never reuses a cached answer; it makes the session read-only below./;
	Scene sc = speaker.GetCurrentScene()
	inScene = sc != None
	sessScene = sc
	quietArm = false
	if m
		quietArm = m.QuietOn()
	endif
	SceneTake(SceneBasis(speaker, sc, quietArm, false))
	sjScene = inScene && sessSqj == 1
	route = ChooseRoute()
	sessQ = "-"
	if sQuestAware && !quietArm
		sessQ = QuestCsv(speaker)
	endif
	sgOpen = ReadSpeechGlobals()
	;/v0.5 E1(a), the FIRST calibration hook, [v1.0 / model F22] on EVERY arming - the passive pass has
	 no switch any more. calTimerWanted asks the probe ONCE per session whether it still needs the
	 progress-timer sample, so a calibrated install pays nothing per poll afterwards./;
	LRG_DlgProbe p = Probe()
	calTimerWanted = false
	if p
		p.CalArm(apd0, fam, maxItems, platform, false, glueOpened)
		calTimerWanted = p.CalGet("timer", 0) == 0
	endif
	;/[v1.0 / S1.1] WHO DRIVES. The visible-reason ladder: module off, smart talk skip, asked for (the
	 dump), lethal (a guard with a bounty), scene (a journal-quest scene before the route is proven
	 live, or quiet mode), unknown swf family. Anything else is driven - an E-press on Hulda while the
	 bard sings included. A dry run is NOT a reason: the driven session runs and the click logs
	 WOULD CLICK (S3.4)./;
	string vis = ""
	if !sMenuless
		vis = "module off"
	elseif !smartSafe
		vis = "smart talk skip"
	elseif forceVisible && now >= forceVisibleAt && (now - forceVisibleAt) < 5.0
		vis = "asked for"
	elseif crit == 2
		vis = "lethal"
	elseif quietArm
		vis = "scene"
	elseif sjScene && !CanDriveScene()
		vis = "scene"
	elseif route == 0
		vis = "unknown swf family"
	endif
	forceVisible = false
	bool drv = vis == ""
	SendOpen(drv)
	HoldListener() ; [v1.0.1] his talk key reaches her while the list is on screen (driven or read-only alike)
	if m
		string line = "arming sid=" + sid + " origin=" + origin + " fam=" + fam + " items=" + maxItems \
			+ " plat=" + platform + " route=" + route + " crit=" + crit + " scene=" + I(inScene) \
			+ " sq=" + sessSq + " sqj=" + sessSqj + " sj=" + I(sjScene) + " drv=" + I(drv) \
			+ " subs=" + I(subsOn) + " dryrun=" + I(sDryRun) + " mm=" + I(mmBase) + " apd=" + apd0
		if vis != ""
			line += " visible=" + vis
		endif
		if quietArm
			line += " quiet=1"
		endif
		m.LogC(sessCid, line, npcName)
		VoiceType vt = VoiceOf(speaker)
		if vt
			m.LogC(sessCid, "voice of " + npcName + " at open = " + PO3_SKSEFunctions.GetFormEditorID(vt), npcName)
		else
			m.LogC(sessCid, "voice of " + npcName + " at open = none", npcName)
		endif
	endif
	if !drv
		if vis == "lethal"
			; the one read-only grade the player is told about at once: it is the one that surprises him
			StopDriving("lethal", false)
		else
			dlgState = ST_MANUAL
			stateTime = now
		endif
	else
		dlgState = ST_LISTENING
	endif
EndFunction

Function Step(float afNow)
	;/[v1.0 / S1.2] At most three natives per poll outside the click window: EntryCount (the state
	 step), Subtitle (HarvestLine) and IsInMenuMode. The guard upkeep that cost two more on every poll
	 is gone with the guard./;
	if qCmd != "" && dlgState != ST_RESPONDING
		; [v1.0 review / model row 21] the command that waited for the click in flight, now that it has settled
		string qn = qNpc
		string qc = qCmd
		string qp = qParam
		qX = ""
		qNpc = ""
		qCmd = ""
		qParam = ""
		CmdSelectTopic(qn, qc, qp)
	endif
	if reqDump
		if afNow >= dumpAt && (afNow - dumpAt) < 30.0
			reqDump = false
			DoDump()
		else
			reqDump = false
		endif
	endif
	if reqWant
		reqWant = false
		if eHead > 0
			SendTopics(1, "")
		endif
	endif
	if reqShow
		reqShow = false
		;/[v1.0 / S4.2, model F3] do=show kind=back is the LEAVE GUARD: no line on this layer backs out
		 cleanly, and her own line says so. It is answered OK and NOTHING changes - the list stays on
		 screen and stays driven. Every other do=show (lethal, an arrest, resist-arrest, kind=meta) is
		 the server saying "this one is his": answered first, then StopDriving("lethal")./;
		ReportOnce("OK: The menu is shown.")
		if reqKind == "back"
			ClearRequest(false)
		else
			StopDriving("lethal", true)
		endif
	endif
	if reqLeave
		; the leave key and do=leave reach every state the glue is actually driving; in MANUAL the
		; list is the player's and nothing is ever closed from here (design 1.3 MANUAL)
		if dlgState == ST_LISTENING || dlgState == ST_READING || dlgState == ST_DECIDING || dlgState == ST_CLICKING
			BeginLeave() ; [v1.0 review] CLICKING too: a pick still waiting there (an auto-advance) is overtaken
		elseif dlgState == ST_MANUAL
			reqLeave = false
			ReportOnce("OK: The menu is shown.")
			ClearRequest(false)
		endif
	endif
	;/a pausing menu on top (design 1.3 SUSPENDED). RESPONDING reads it as its own signal 2.
	 [0.5.1 pt9 go-live, G9] asked for by NAME as well, and only on the poll where the entry count has
	 just gone to zero: what Utility.IsInMenuMode() returns inside a Dialogue Menu is unmeasured here./;
	if dlgState != ST_SUSPENDED && dlgState != ST_RESPONDING
		if !mmBase && Utility.IsInMenuMode()
			EnterSuspended()
		elseif eHead > 0 && lastN == 0 && ServiceMenuOpen()
			; eHead > 0 = this session has really read a list, so a silent NPC pays nothing at all
			EnterSuspended()
		endif
	endif
	if subsOn
		HarvestLine(afNow)
	endif
	;/[0.5.1 G10] a fight that started after ARMING stops the driving - looked for once a second (every
	 4th 0.25 s poll) in the two states where a list waits for an answer, DECIDING and HELD, so the
	 other polls keep to their three natives (S1.2). [v1.0 review / model row 30] and a pick in flight
	 (CLICKING, every 10th 0.1 s poll): an auto-advance may wait there up to 30 s./;
	bool lookCombat = false
	if dlgState == ST_DECIDING || (dlgState == ST_LISTENING && held)
		lookCombat = (pollCount % 4) == 0
	elseif dlgState == ST_CLICKING
		lookCombat = (pollCount % 10) == 0
	endif
	if lookCombat && CombatBroke()
		StopDriving("combat", true)
	endif
	if dlgState == ST_LISTENING
		StepListening(afNow)
	elseif dlgState == ST_READING
		StepReading(afNow)
	elseif dlgState == ST_DECIDING
		StepDeciding(afNow)
	elseif dlgState == ST_CLICKING
		StepClicking(afNow)
	elseif dlgState == ST_RESPONDING
		StepResponding(afNow)
	elseif dlgState == ST_SUSPENDED
		StepSuspended(afNow)
	elseif dlgState == ST_MANUAL
		StepManual(afNow)
	elseif dlgState == ST_CLOSING
		StepClosing(afNow)
	endif
	;/v0.5 E1(a), the per-poll hook, [v1.0 / F22] with no switch. The budget is ONE extra delayed
	 native and only on every fifth poll, and even that is skipped once the progress-timer question
	 has been answered. The subtitle is whatever HarvestLine() already read this poll./;
	if dlgState != ST_IDLE
		LRG_DlgProbe p = Probe()
		if p
			int timerId = -1
			if calTimerWanted && (pollCount % 5) == 0
				timerId = LRG_DlgUI.ProgressTimerId()
			endif
			p.CalPoll(dlgState, lastN, timerId, calSub)
		endif
	endif
EndFunction

Function StepListening(float afNow)
	;/LISTENING has two faces. No list yet: wait for one (EntryCount). HELD (held = true, spec S1.1 /
	 model F10): the layer was read and answered, nothing is outstanding and the list is on screen -
	 he may talk or click. Then only the count and the harvested subtitle are watched; a stamped pick
	 is resolved against the arrays already held; the list is read again only when it CHANGED./;
	int n = LRG_DlgUI.EntryCount()
	lastN = n
	if held
		if n == nTotal && lnSeq == heldLines
			if reqPickReady
				held = false
				if TryResolvePick()
					EnterClicking(afNow)
				else
					dlgState = ST_DECIDING
					decideStart = afNow
					stateTime = afNow
				endif
			endif
			return
		endif
		;/the list moved while nothing of ours was in flight: HIS click (or the engine's own push). The
		 layer it produces began at the latest one poll ago, so a line first read on this very poll
		 counts as seen after it (the auto-advance anchor, model F11)./;
		held = false
		layerStart = afNow - 0.3
		if n <= 0
			return ; her line plays; the next list is read when it appears
		endif
		dlgState = ST_READING
		stateTime = afNow
		return
	endif
	if n > 0
		noCountAt = 0.0
		dlgState = ST_READING
		stateTime = afNow
		return
	endif
	if n < 0
		; -1 = no count mode works at all. Reported apart from "no entries" (design R4), because a
		; dead read path and a silent NPC look identical otherwise.
		if noCountAt <= 0.0
			noCountAt = afNow
		elseif (afNow - noCountAt) > 3.0
			dCountFails += 1
			StopDriving("read-failed", true)
		endif
		return
	endif
	noCountAt = 0.0
EndFunction

Function EnterHeld(float afNow)
	{[v1.0 / S1.1] "stay LISTENING with no decision outstanding" (was EnterPendingOrManual): no park,
	 no corner note, no silence timer. The list is on screen; his next words or his click decide.}
	held = true
	heldLines = lnSeq
	dlgState = ST_LISTENING
	stateTime = afNow
EndFunction

Function EnterClicking(float afNow)
	{A resolved pick moves to CLICKING with a fresh click window, opened for this x.}
	dlgState = ST_CLICKING
	clickX = reqX
	clickStart = afNow
	stateTime = afNow
	readySeen = false
	settleVal = ""
	settleInt = 0
	settleAt = afNow
	advVoice = false
	advQuietAt = 0.0
	advSaid = false
	advLog = ""
EndFunction

Function StepReading(float afNow)
	int n = LRG_DlgUI.EntryCount()
	lastN = n
	if n <= 0
		dlgState = ST_LISTENING
		return
	endif
	if !ReadList(n)
		return
	endif
	dlgState = ST_DECIDING
	decideStart = afNow
	stateTime = afNow
EndFunction

bool Function ReadList(int aiCount)
	;/Reads the layer the ENGINE built: text + topicIndex for the head, text only for the tail.
	 Returns false when the read failed. Array positions only - mode 5 indexes screen rows and is
	 read-only (design R5). [v1.0] always mode 3: the menu is visible, and mode 4 walks iSelectedIndex./;
	LRG_Main m = Main()
	if readMode != 3
		CalibrateRead(aiCount)
	endif
	if readMode != 3
		readFails += 1
		if readFails >= 2
			StopDriving("read-failed", true)
		endif
		return false
	endif
	nTotal = aiCount
	int cap = sMaxEntries
	if cap > aiCount
		cap = aiCount
	endif
	int i = 0
	int got = 0
	while i < cap
		eText[i] = LRG_DlgUI.EntryText(i, readMode)
		eIdx[i] = LRG_DlgUI.EntryTopicIndex(i, readMode)
		if eText[i] != ""
			got += 1
		endif
		i += 1
	endwhile
	eHead = cap
	if got == 0
		readFails += 1
		if m
			m.LogC(sessCid, "read failed n=" + aiCount + " mode=" + readMode + " try=" + readFails, npcName)
		endif
		if readFails >= 2
			StopDriving("read-failed", true)
		endif
		return false
	endif
	readFails = 0
	; the tail: never truncate before the server has ranked (design R12 / D-22)
	tCount = 0
	int tailTo = aiCount
	if tailTo > sTailMax
		tailTo = sTailMax
	endif
	i = cap
	while i < tailTo && tCount < 40
		tText[tCount] = LRG_DlgUI.EntryText(i, readMode)
		tCount += 1
		i += 1
	endwhile
	;/[0.5.1 pt9 go-live, G8] The topic IDENTITIES ride in the signature too, so a MIDDLE-only change
	 (a persuade entry swapped after the freeze) bumps gen and is re-sent. No extra UI read./;
	string newSig = aiCount + "|" + eText[0] + "|" + eText[eHead - 1] \
		+ "|" + (eIdx[0] + eIdx[eHead / 2] + eIdx[eHead - 1])
	if newSig == sig
		return true
	endif
	;/[v1.0] sig is cleared to force a re-read (freeze, stale, a settled click); lastSig is not, so a
	 re-read of the SAME layer is told from a layer that really changed./;
	bool changed = newSig != lastSig
	sig = newSig
	lastSig = newSig
	if changed && gen > 0 && !ourClick
		;/[model F9] the layer changed while no driven pick of ours was in flight: HIS click (or the
		 engine's own push). hc=1 on this layer's lrg_topics: an older sentence of his and a park must
		 not act on a layer he chose himself. The first read after OUR click consumes ourClick, changed
		 or not (a line whose answer leaves the same list)./;
		hcGen = gen + 1
	endif
	ourClick = false
	gen += 1
	if changed
		layerGen = gen ; forced re-reads of the SAME layer move gen, never layerGen (F26 "overtaken")
	endif
	sentThisGen = false
	if dlgState != ST_MANUAL
		pendingLayer = true
	endif
	;/[v1.0 / model F24] a scene that began INSIDE this session (a clicked quest line starts one): the
	 same owning-quest test as Arm(), once per NEW scene and on a changed layer only - one native when
	 there is no scene. A journal-quest scene with the route proven live keeps being driven and says
	 sj=1 on this layer's lrg_topics; otherwise the driver stops (StopDriving "scene", ev=stopped)
	 and this layer still goes out, read-only. A scene without a journal objective changes nothing./;
	if changed && speaker != None && !sjScene
		Scene sc = speaker.GetCurrentScene()
		if sc != None && sc != sessScene
			inScene = true
			sessScene = sc
			bool quiet = false
			if m
				quiet = m.QuietOn()
			endif
			SceneTake(SceneBasis(speaker, sc, quiet, true))
			if sessSqj == 1
				sjScene = true
				if m
					m.LogC(sessCid, "scene: a journal-quest scene (sq=" + sessSq + ") started on " + npcName \
						+ " inside this session - driven=" + I(CanDriveScene()), npcName)
				endif
				if dlgState != ST_MANUAL && !CanDriveScene()
					FreezeCapture()
					StopDriving("scene", true)
					sentThisGen = true
					SendTopics(0, "")
					return false ; MANUAL now - the caller must not move this session on to DECIDING
				endif
			elseif m
				m.LogC(sessCid, "scene: " + npcName + " is in a scene without a journal objective (sq=" + sessSq \
					+ ") - still driven", npcName)
			endif
		endif
	endif
	FreezeCapture()
	;/v0.5: both per-LAYER passes, and only on the branch where the signature really changed. CalLayer
	 is the probe's census (counting, reading, rows, colours) - [v1.0 / F22] no switch any more;
	 SvcScan is E6's own "is this a price list, and of what" verdict./;
	LRG_DlgProbe p = Probe()
	if p
		p.CalLayer(nTotal, eHead, readMode)
	endif
	SvcScan()
	return true
EndFunction

Function SvcScan()
	;/E6 / W13, the driver's own cheap half. "Is this a price list?" = at least min_entries entries
	 of at most three words, at least two of them carrying a real price in the LIVE text. That is
	 exactly the shape a CFTO destination layer has (Morthal. (35 gold)) and exactly the shape the
	 similarity matcher must never be let loose on. The KIND comes from words the entries actually
	 use; a session remembers the kind its root proved, because the destination layer itself says
	 nothing about carriages. Nothing in the driver branches on any of this - it is diagnostic, the
	 server re-derives it, and a mismatch is the server's own "svck drift" line./;
	svcShort = 0
	svcPriced = 0
	layerSvc = "-"
	if !sServiceDlg || eHead <= 0
		return
	endif
	string hay = ""
	int i = 0
	while i < eHead && i < 8
		hay += "|" + LRG_Profile.Clip(eText[i], 60)
		i += 1
	endwhile
	i = 0
	while i < eHead
		if WordCount(eText[i]) <= 3
			svcShort += 1
			if EntryCost(eText[i]) > 0
				svcPriced += 1
			endif
		endif
		i += 1
	endwhile
	string kind = SvcKindOf(hay)
	if kind != "-"
		sessSvc = kind
		layerSvc = kind
	elseif layer > 0 && svcShort >= 3 && svcPriced >= 2
		; a closed price list with no word of its own: it belongs to whatever this session proved
		layerSvc = sessSvc
	endif
	if layerSvc != "-" || (svcShort >= 3 && svcPriced >= 2)
		LRG_Main m = Main()
		if m
			m.LogC(sessCid, "SVC npc=" + npcName + " kind=" + layerSvc + " layer=" + layer \
				+ " priced=" + svcPriced + "/" + svcShort, npcName)
		endif
	endif
EndFunction

string Function SvcKindOf(string asHay)
	;/One pass over ONE concatenated haystack, not one pass per entry: about two dozen native Find
	 calls per layer instead of several hundred. Papyrus has no ToLower, so each word is tried in
	 its sentence-middle and its sentence-initial spelling. Crime is tested first, because that is
	 the one kind where being wrong matters./;
	if Has2(asHay, "bounty", "Bounty") || Has2(asHay, "jail", "Jail") || Has2(asHay, "arrest", "Arrest")
		return "crime"
	endif
	if Has2(asHay, "train", "Train") || Has2(asHay, "teach", "Teach")
		return "train"
	endif
	if Has2(asHay, "room", "Room") || Has2(asHay, "bed", "Bed")
		return "inn"
	endif
	if Has2(asHay, "ferry", "Ferry") || Has2(asHay, "boat", "Boat")
		return "ferry"
	endif
	if Has2(asHay, "carriage", "Carriage") || Has2(asHay, "stable", "Stable")
		return "carriage"
	endif
	if Has2(asHay, "wares", "Wares") || Has2(asHay, "trade", "Trade") || Has2(asHay, "sale", "Sale")
		return "barter"
	endif
	return "-"
EndFunction

bool Function Has2(string asHay, string asA, string asB)
	if StringUtil.Find(asHay, asA) >= 0
		return true
	endif
	return StringUtil.Find(asHay, asB) >= 0
EndFunction

int Function WordCount(string asText)
	{Meaning-carrying words of an entry, the price parenthetical excluded. Used only by the
	 price-list test, so it stops counting at four - nothing above three is interesting.}
	string s = asText
	int br = StringUtil.Find(s, "(")
	if br > 0
		s = StringUtil.Substring(s, 0, br)
	endif
	int n = StringUtil.GetLength(s)
	if n > 40
		n = 40 ; an entry of three words or fewer is short by definition; this is the cost ceiling
	endif
	int words = 0
	bool inWord = false
	int i = 0
	while i < n && words < 5
		string ch = StringUtil.GetNthChar(s, i)
		if ch == " " || ch == "	"
			inWord = false
		elseif !inWord
			inWord = true
			words += 1
		endif
		i += 1
	endwhile
	return words
EndFunction

int Function EntryCost(string asText)
	{The price the LIVE entry shows - "Three nights. (34 gold)" -> 34. Never the index's template:
	 a <Global=...> token has no number and correctly returns 0.}
	int br = StringUtil.Find(asText, "(")
	if br < 0
		return 0
	endif
	int n = StringUtil.GetLength(asText)
	int v = -1
	int i = br + 1
	while i < n
		string ch = StringUtil.GetNthChar(asText, i)
		int d = StringUtil.Find("0123456789", ch)
		if d >= 0
			if v < 0
				v = 0
			endif
			v = v * 10 + d
		elseif v >= 0
			i = n ; the first run of digits after the bracket is the price
		endif
		i += 1
	endwhile
	if v < 0
		return 0
	endif
	return v
EndFunction

Function CalibrateRead(int aiCount)
	;/Once per game session. Mode 3 reads EntriesA.<i>.text and is expected to work (a published
	 mod reads a plain AS2 array the same way). [v1.0] Mode 4 walks iSelectedIndex, which moves the
	 highlight on a menu the player can see and click - it is never used: the menu is never hidden./;
	if sReadMode == 3 || sReadMode == 4
		readMode = 3
		return
	endif
	if LRG_DlgUI.ReadMode() == 3
		readMode = 3
		return
	endif
	if LRG_DlgUI.EntryText(0, 3) != ""
		readMode = 3
		LRG_DlgUI.SetReadMode(3)
		return
	endif
	readMode = 0
	;/[v0.5 fix pass, minor] ONCE per session: StepManual calls this on every poll for as long as
	 mode 3 reads nothing, and a log line per poll would be one HTTP request per poll./;
	if !readNoteLogged
		readNoteLogged = true
		Main().LogC(sessCid, "read mode 3 is empty on this list - nothing can be read, iSelectedIndex is not walked", npcName)
	endif
EndFunction

bool Function CombatBroke()
	;/[0.5.1 pt9 go-live, G10] Combat is tested in OpenBlockedReason(), whose ONLY caller is StartOpen -
	 so a bandit ambush or a guard's arrest ForceGreet landing AFTER the session armed was never seen
	 again. Three natives, once a second while a list waits (Step): a fight stops the driving./;
	if Game.GetPlayer().IsInCombat()
		return true
	endif
	return speaker != None && speaker.IsInCombat()
EndFunction

Function StepDeciding(float afNow)
	if reqPickReady && !PickDead(afNow)
		if TryResolvePick()
			EnterClicking(afNow)
			return
		endif
	endif
	;/[v1.0 review / model F10, rows 10 + 15] the list is on screen during the decide window too and he
	 may click it by hand: a changed count is HIS click (or the engine's own push). One native, the
	 EntryCount S1.2 allows. The new layer is read when it is back (hc=1); a pick made for the old one
	 is overtaken there (layerGen) and closed silently by PickDead before that layer goes out./;
	int n = LRG_DlgUI.EntryCount()
	lastN = n
	if n >= 0 && n != nTotal
		layerStart = afNow - 0.3
		stateTime = afNow
		if n > 0
			dlgState = ST_READING
		else
			dlgState = ST_LISTENING
		endif
		return
	endif
	if !sentThisGen
		sentThisGen = true
		int want = 1
		if reqPickReady
			want = 0
		endif
		SendTopics(want, "")
		return
	endif
	if (afNow - decideStart) > sDecide
		;/fDecideTimeout NEVER closes a session and never gives anything back (design R8, v1.0 S1.1): a
		 decision still outstanding is closed and the layer is HELD. "Not on the table" ONLY when its entry
		 is not on the live list; [v1.0 review / P4] an entry that IS there but did not resolve on this
		 layer (two lines share its first 40 characters, its position moved) is S7's stale sentence; an
		 auto-advance or anything but a pick closes silently (model F26)./;
		if reqCmd != "" && !reqReported
			if reqDo == "pick" && reqAdv < 0
				if !PickOnList(reqTxt, reqPos)
					ReportOnce("Error: that is not on the table right now")
				else
					Main().LogC(reqCid, "pick unresolved - its entry is on this layer (x=" + reqX + " pos=" + reqPos + ")", npcName)
					ReportOnce("Error: the entry moved before the click")
				endif
			else
				ReportOnce("OK: Noted.")
			endif
			ClearRequest(false)
		endif
		EnterHeld(afNow)
	endif
EndFunction

bool Function PickDead(float afNow)
	;/[v1.0 review / model rows 15, 20, 21, F26] A stamped pick that can no longer be clicked is closed at
	 once - BEFORE this layer goes out, so the list he is looking at is decided (want=1): overtaken (the
	 list changed since it was made) -> silent; older than 20 s -> "that moment has passed"; a second miss
	 on the SAME layer -> S7's sentence (staleWhy). An auto-advance is the glue's own initiative: always
	 silent. True = it was closed./;
	if reqDo != "pick" || reqCmd == "" || reqReported
		return false
	endif
	string why = ""
	string err = ""
	if reqSid == sid && reqGen > 0 && reqGen < layerGen
		why = "pick overtaken - it was made for gen " + reqGen + ", the list changed at gen " + layerGen
	elseif afNow < reqAt || (afNow - reqAt) > 20.0
		why = "pick expired - it was made " + ((afNow - reqAt) as int) + " s ago"
		err = "that moment has passed"
	elseif staleCount >= 2
		why = "pick missed twice on this layer"
		err = staleWhy
	else
		return false
	endif
	if reqAdv >= 0 || err == ""
		Main().LogC(reqCid, why + " - closed silently (x=" + reqX + ")", npcName)
		ReportOnce("OK: Noted.")
	else
		Main().LogC(reqCid, why + " (x=" + reqX + ")", npcName)
		ReportOnce("Error: " + err)
	endif
	ClearRequest(false)
	return true
EndFunction

string Function PosText(int aiPos)
	{The text of an array position for a LOG line. eText holds the head only, so an entry past
	 iMaxEntries (design G3) is read live; out of range is "?" and never an array access.}
	if aiPos < 0 || aiPos >= nTotal
		return "?"
	endif
	if aiPos < eHead
		return eText[aiPos]
	endif
	if readMode != 3
		return "?"
	endif
	return LRG_DlgUI.EntryText(aiPos, readMode)
EndFunction

bool Function TryResolvePick()
	;/Resolves the server's decision against the list that is live NOW. (a) pos + i on this layer (made
	 for it, or for the same list before a forced re-read), (b) the local prefix match on txt when exactly
	 one entry matches. Nothing else clicks. [v1.0 review / P1] txt= is the server's WIRE form of the
	 text (TxtMatch); once it resolves, the click verifies the RAW live prefix (clickTxt). A pick that is
	 overtaken or too old never resolves here - PickDead closes it./;
	if reqDo != "pick"
		return false
	endif
	float now = Utility.GetCurrentRealTime()
	if now < reqAt || (now - reqAt) > 20.0
		return false
	endif
	if reqSid == sid && reqGen > 0 && reqGen < layerGen
		return false
	endif
	if reqX == scanX && gen == scanGen
		return false ; this pick already failed on this very read of the list: 0 work per poll
	endif
	if reqPos >= 0 && reqPos < eHead && reqSid == sid && (reqGen == 0 || (reqGen >= layerGen && reqGen <= gen))
		if reqTxt == "" || TxtMatch(eText[reqPos], reqTxt)
			if reqTxt != ""
				clickTxt = LRG_Profile.Clip(eText[reqPos], 40)
			endif
			if reqGen > 0 && reqGen != gen
				SendTopics(0, "") ; the list was re-read since the server saw it: it learns the gen, as (b)
			endif
			return true
		endif
	endif
	;/[0.5.1 pt9 go-live, G3] THE TAIL. An entry past iMaxEntries is a real entry of this list: its
	 LIVE text is read once, here, and the prefix must match from character 0 exactly as for the
	 head - a tail pick with no txt= is refused (position alone is no evidence for an unread entry)./;
	if reqPos >= eHead && reqPos < nTotal && reqTxt != "" && readMode == 3
		string live = LRG_DlgUI.EntryText(reqPos, readMode)
		if TxtMatch(live, reqTxt)
			reqIdx = LRG_DlgUI.EntryTopicIndex(reqPos, readMode)
			clickTxt = LRG_Profile.Clip(live, 40)
			return true
		endif
	endif
	if reqTxt != ""
		int hits = 0
		int at = -1
		int i = 0
		while i < eHead
			if TxtMatch(eText[i], reqTxt)
				hits += 1
				at = i
			endif
			i += 1
		endwhile
		if hits == 1
			reqPos = at
			reqIdx = eIdx[at]
			clickTxt = LRG_Profile.Clip(eText[at], 40)
			SendTopics(0, "")
			return true
		endif
	endif
	scanX = reqX
	scanGen = gen
	return false
EndFunction

Function StepClicking(float afNow)
	;/The click window. Nothing is clicked before the list has been shown and the line has settled,
	 and a wrong match can never click: SelectAndVerify is the last frame-synced pair before the
	 single queued Invoke and aborts on any mismatch. [v1.0] The menu is on screen: the highlight
	 moves to the entry and the entry is clicked - exactly what his mouse would do./;
	LRG_Main m = Main()
	;/[v1.0 review / model row 21] the request was replaced while it waited here (a newer server pick, a
	 leave, a show): the newer one gets a window of its own - a pick is resolved against the live list
	 first (DECIDING), Step routes a leave or a show; nothing outstanding -> HELD./;
	if reqX != clickX || reqCmd == "" || reqReported
		if reqCmd != "" && !reqReported
			dlgState = ST_DECIDING
			decideStart = afNow
			stateTime = afNow
		else
			EnterHeld(afNow)
		endif
		return
	endif
	if (afNow - openTime) < 0.6
		return ; Little Lessons and the trait system write the Speech globals inside OnMenuOpen
	endif
	;/[0.5.1 pt9 go-live, G3] nTotal, not eHead: an entry past iMaxEntries is a real entry of this list
	 and TryResolvePick() has already verified its LIVE text and taken its live topicIndex./;
	if reqPos < 0 || reqPos >= nTotal
		ReportOnce("Error: that is not on the table right now")
		ClearRequest(false)
		dlgState = ST_DECIDING
		decideStart = afNow
		return
	endif
	if reqAdv >= 0
		;/[v1.0 review / model row 15, F26] HIS click while an auto-advance waits: the list clears and his
		 layer follows. The waiting pick is overtaken - closed silently - and the layer he chose is read
		 and decided (want=1). EntryCount on every second poll, and only while an auto-advance waits./;
		if (pollCount % 2) == 0
			int cnt = LRG_DlgUI.EntryCount()
			lastN = cnt
			if cnt >= 0 && cnt != nTotal
				if m
					m.LogC(reqCid, "pick overtaken - the list changed under the waiting auto-advance (x=" + reqX + ")", npcName)
				endif
				ReportOnce("OK: Noted.")
				ClearRequest(false)
				held = false
				layerStart = afNow - 0.2
				; his layer is a NEW layer even when it reads the same ("..." after "..."): gen+1, hc=1, decided
				sig = ""
				lastSig = ""
				stateTime = afNow
				if cnt > 0
					dlgState = ST_READING
				else
					dlgState = ST_LISTENING
				endif
				return
			endif
		endif
		; [v1.0 / S4.6] an auto-advance waits for its anchor instead of the first-click settle
		if !AdvReady(afNow)
			return
		endif
	else
		if !anyClick && !ReadyGate(afNow)
			if !readySeen && (afNow - clickStart) > 8.0
				; the list never came up for this pick: it is closed, the session goes on
				ReportOnce("Error: that moment has passed")
				ClearRequest(false)
				EnterHeld(afNow)
			endif
			return
		endif
		;/never click over CHIM's own voice (at most 8 s), [model F30] nor within 1.0 s of a CHIM event of
		 hers: her words may be a sentence away from her voice. 0 natives for the second test./;
		if (afNow - clickStart) < 8.0
			if afNow >= npcSpeechAt && (afNow - npcSpeechAt) < 1.0
				return
			endif
			if AIAgentFunctions.isActorTalking(npcName) != 0
				return
			endif
		endif
	endif
	;/[v1.0 review / model row 21] From here to the settle the request belongs to the click: the natives
	 below let other threads in, so a command that arrives now waits in the one-slot queue (RESPONDING)
	 instead of rewriting the request under the click. Every exit that does not click sets its state./;
	dlgState = ST_RESPONDING
	if reqX != clickX
		dlgState = ST_CLICKING
		return
	endif
	if !VkRestore(speaker) && !sAllowNull
		ReportOnce("Error: her voice is not ready")
		ClearRequest(false)
		dlgState = ST_DECIDING
		decideStart = afNow
		return
	endif
	; the freeze rule (B1): anything that moved the player's gold or Speechcraft between the read
	; and the click invalidates the server's decision
	if FreezeMoved() && frzHits < 2
		frzHits += 1
		if m
			m.LogC(reqCid, "freeze: gold or Speechcraft moved since the list was read - re-reading", npcName)
		endif
		sig = ""
		dlgState = ST_READING
		return
	endif
	if reqCost > 0 && Game.GetPlayer().GetGoldAmount() < reqCost
		ReportOnce("Error: not enough gold")
		ClearRequest(false)
		dlgState = ST_DECIDING
		decideStart = afNow
		return
	endif
	CapturePre()
	if sDryRun || !CalGreenNow()
		;/[v1.0 / S3.4] the owner's menuless dry run, or a calibration that is not green yet: WOULD
		 CLICK and the refusal in words - and nothing else. No hand-back: the menu stays on screen and
		 the session stays driven, so the next pick is judged the same way./;
		if m
			m.LogC(reqCid, "WOULD CLICK cid=" + reqCid + " pos=" + reqPos + " i=" + reqIdx + " kind=" + reqKind \
				+ " route=" + route + " dryrun=" + I(sDryRun) + " green=" + I(calGreen) + " auto=" + I(reqAdv >= 0) + advLog \
				+ " text=" + LRG_Profile.Clip(PosText(reqPos), 80), npcName)
		endif
		XPush(reqX)
		ReportOnce("Error: " + DlgDryReason())
		ClearRequest(false)
		EnterHeld(afNow)
		return
	endif
	if !LRG_DlgUI.SelectAndVerify(reqPos, reqIdx, clickTxt, route == 1)
		;/[v1.0 review / model rows 20, 21] every miss re-reads first: a layer that moved under the pick
		 (his click) closes it silently as overtaken; only a second miss on the SAME layer is S7's sentence
		 (PickDead). clickTxt is the entry's RAW live prefix (TryResolvePick), never the wire form./;
		staleCount += 1
		staleWhy = "the entry moved before the click"
		if m
			m.LogC(reqCid, "stale: pos=" + reqPos + " is not the entry the server picked (try " + staleCount + ")", npcName)
		endif
		sig = ""
		dlgState = ST_READING
		return
	endif
	; the x goes into the ring BEFORE the click: a second delivery of the same decision must never
	; reach the menu, whatever the click itself does
	XPush(reqX)
	clickAt = afNow
	LRG_DlgUI.Click(route)
	int cr = LRG_DlgUI.ClickResult()
	if cr <= 0
		; Click() aborted and NOTHING was clicked: -1 state read-back, -2 identity mismatch (his own
		; mouse may have moved the highlight), -3 no fresh verified selection, -4 closed. ShowList()
		; is the recovery; the click is never repeated through the other route in the same poll.
		dClickAborts += 1
		LRG_DlgUI.ShowList()
		staleCount += 1
		staleWhy = "the click did not take"
		if m
			m.LogC(reqCid, "click aborted result=" + cr + " pos=" + reqPos + " route=" + route + " - nothing was clicked", npcName)
		endif
		;/[v1.0 review / S3.2] only -1 (the eMenuState read-back) says anything about the route: -2 (his
		 own mouse moved the highlight), -3 (a stale selection) and -4 (closed) never flip it./;
		if cr == -1
			clickFails += 1
			if clickFails >= 2
				clickFails = 0
				FlipRoute("two clicks did not take, result " + cr)
			endif
		endif
		sig = ""
		dlgState = ST_READING
		return
	endif
	anyClick = true
	ourClick = true
	clickFails = 0
	staleCount = 0
	layer += 1
	clickAuto = reqAdv >= 0
	layerStart = afNow
	respStart = afNow
	rsSignal = false
	rsProof = false
	rsWatch = false
	rsClosed = false
	rsOther = ""
	routeFlipped = false
	pendingLayer = false
	dlgState = ST_RESPONDING
	if m
		m.LogC(reqCid, "clicked pos=" + reqPos + " origin=" + origin + " sj=" + I(sjScene) + " i=" + reqIdx \
			+ " route=" + route + " result=" + cr + " kind=" + reqKind + " auto=" + I(clickAuto) + advLog \
			+ " text=" + LRG_Profile.Clip(PosText(reqPos), 60), npcName)
	endif
EndFunction

bool Function ReadyGate(float afNow)
	;/The first click's gate: the menu has read ready (MenuState 1, latched), then the line has settled -
	 the subtitle HarvestLine read this poll or, with subtitles off, the progress timer unchanged for
	 fLineSettle. isActorTalking only ever sees CHIM's own TTS, so the engine's line is measured (R2)./;
	if !readySeen
		if LRG_DlgUI.MenuState() != 1
			return false
		endif
		readySeen = true
	endif
	if subsOn
		if calSub != settleVal
			settleVal = calSub
			settleAt = afNow
			return false
		endif
	else
		int t = LRG_DlgUI.ProgressTimerId()
		if t != settleInt
			settleInt = t
			settleAt = afNow
			return false
		endif
	endif
	return (afNow - settleAt) >= sLineSettle
EndFunction

bool Function AdvReady(float afNow)
	;/[v1.0 / S4.6, model F11-F13, F25] An auto-advance pick (adv=<ms>) waits for its ANCHOR; true = click
	 now. Every way out that is not a click (auto-advance off, subtitles off for an engine anchor, 30 s
	 without an anchor, his talk key or his voiced line) closes the pick SILENTLY and holds the layer:
	 his next words decide (S4.5). Scripted singles never arrive here (the server never sends adv=)./;
	float grace = (reqAdv as float) / 1000.0
	if !sAutoAdvance
		AdvClose("adv off: bAutoAdvance is off - his words decide", afNow)
		return false
	endif
	;/[v1.0 review] the 30 s cap counts from the later of the pick and the last sign of a line (a
	 subtitle read, a CHIM event of hers): a long multi-response speech is not a missing anchor./;
	float capFrom = reqAt
	if lineSeenAt > capFrom
		capFrom = lineSeenAt
	endif
	if npcSpeechAt > capFrom
		capFrom = npcSpeechAt
	endif
	if afNow >= capFrom && (afNow - capFrom) > 30.0
		AdvClose("adv expired: no anchor 30 s after the pick or her last line", afNow)
		return false
	endif
	; [model F13] the cancel: his talk key (or his own voiced line) after the layer appeared - after
	; the pick itself for a re-armed one, whose question came before it by design
	float cancelFrom = layerStart
	if reqRearm
		cancelFrom = reqAt
	endif
	if speechAt > cancelFrom
		AdvClose("adv cancelled by speech", afNow)
		return false
	endif
	float from = 0.0
	if reqRearm
		;/THE CHIM ANCHOR: the END of her answer. Counting starts once her voice has begun after the pick,
		 or she is heard talking now, or 8 s passed with no voice (a muted or empty reply). Then
		 isActorTalking must read 0 for 1.0 s with no CHIM event of hers in that second, then the grace.
		 +1 native per 0.1 s poll, only while such a pick waits./;
		if !advSaid
			advSaid = true
			Main().LogC(reqCid, "adv wait: her line (x=" + reqX + " pos=" + reqPos + ")", npcName)
		endif
		bool talking = AIAgentFunctions.isActorTalking(npcName) != 0
		if !advVoice
			if talking || herVoiceAt > reqAt || (afNow - reqAt) >= 8.0
				advVoice = true
			else
				return false
			endif
		endif
		if talking
			advQuietAt = 0.0
			return false
		endif
		if advQuietAt <= 0.0
			advQuietAt = afNow
		endif
		from = advQuietAt
		if npcSpeechAt > from
			from = npcSpeechAt
		endif
		if (afNow - from) < (1.0 + grace)
			return false
		endif
		advLog = " adv anchor: her line ended, quiet since=" + advQuietAt
		return true
	endif
	; [model F30, rows 19 + 23] never within 1.0 s of a CHIM event of hers, whatever the grace (0 natives)
	if afNow >= npcSpeechAt && (afNow - npcSpeechAt) < 1.0
		return false
	endif
	if reqAdv == 0
		;/[v1.0 review / S4.6 "reqAdv > 0", model F25 "0 = an immediate continuer"] a continuer ("...", "Go
		 on.") takes no breath and needs no line anchor: it is clicked once the line he answers is over -
		 the subtitle blank for 0.5 s (the gap between two voice files does not fire it) or, with subtitles
		 off, the progress timer unchanged for fLineSettle - and the menu reads ready (MenuState 1). So it
		 works with subtitles off too./;
		if subsOn
			if blankSince <= 0.0 || (afNow - blankSince) < 0.5
				return false
			endif
		else
			int t = LRG_DlgUI.ProgressTimerId()
			if t != settleInt
				settleInt = t
				settleAt = afNow
				return false
			endif
			if (afNow - settleAt) < sLineSettle
				return false
			endif
		endif
		if LRG_DlgUI.MenuState() != 1 || AIAgentFunctions.isActorTalking(npcName) != 0
			return false
		endif
		advLog = " adv anchor: continuer, menu ready"
		return true
	endif
	if !subsOn
		AdvClose("adv off: dialogue subtitles are off - his words decide", afNow)
		return false
	endif
	;/THE ENGINE-LINE ANCHOR: a line was SEEN after this layer began and the subtitle has been blank
	 ever since, for the grace. HarvestLine keeps both stamps and any non-blank read restarts it, so a
	 multi-response line and the gap between two voice files never fire it. The progress timer is
	 never an anchor. A CHIM event of hers restarts the count, and her CHIM voice must be silent at
	 the click (CHIM brief P8)./;
	if lineSeenAt <= layerStart || blankSince <= 0.0
		return false
	endif
	from = blankSince
	if npcSpeechAt > from
		from = npcSpeechAt
	endif
	if (afNow - from) < grace
		return false
	endif
	if AIAgentFunctions.isActorTalking(npcName) != 0
		return false
	endif
	advLog = " adv anchor: line seen at=" + lineSeenAt + " blank since=" + blankSince
	return true
EndFunction

Function AdvClose(string asWhy, float afNow)
	{A waiting auto-advance that will not click: closed silently (OK: Noted., its x into the ring, no
	 corner note) and the layer held for his words.}
	Main().LogC(reqCid, asWhy + " (x=" + reqX + " pos=" + reqPos + ")", npcName)
	XPush(reqX)
	ReportOnce("OK: Noted.")
	ClearRequest(false)
	EnterHeld(afNow)
EndFunction

Function FlipRoute(string asWhy)
	;/[v1.0 / S3.2] The 1 / 1b logic moved from the press to the click: two clicks that did not take,
	 or no signal within 9 s on route A, flip the route for the NEXT attempt. Never a route the owner
	 forced with iClickRoute, never one a real click has proven on this install (CalRouteLive), never
	 a guess (route 0). The flip is written to the calibration with the source flip, so the next
	 session starts from it too./;
	if sClickRoute == 1 || sClickRoute == 2 || (route != 1 && route != 2)
		return
	endif
	LRG_DlgProbe p = Probe()
	if !p || p.CalRouteLive()
		return
	endif
	int was = route
	route = 3 - route
	calRoute = route
	p.CalSet("route", route, "flip", 1)
	Main().LogC(sessCid, "route flipped " + was + " -> " + route + ": " + asWhy, npcName)
EndFunction

Function RouteProof()
	;/[v1.0 / S3.2] ROUTE BY DOING: the first click of this session that the ENGINE answered (the list
	 cleared or a menu on top - never a subtitle or the progress timer alone, never her CHIM voice)
	 proves the route on this install - CalSet with the source live (the probe records rproof and
	 rclicks and logs CALIB set route src=live on a new proof; CalRouteLive then lets a journal-scene
	 menu be driven). One call per session; a repeat proof of the same route is only counted./;
	if routeProved || route <= 0
		return
	endif
	routeProved = true
	LRG_DlgProbe p = Probe()
	if p
		p.CalSet("route", route, "live", 1)
		calRoute = route
	endif
EndFunction

Function StepResponding(float afNow)
	;/Five signals, any ONE of which verifies the click (design R7), the PRE facts in SettleAndReport
	 the sixth. None of them needs subtitles. ok=0 is never reported when a delta was seen.
	 [v1.0 review / S3.2, CHIM brief 4 + 8] isActorTalking is NOT a signal any more: it hears only
	 CHIM's own TTS, and on first contact her bridging voice starts 1-3 s after the click whether it
	 took or not - it must neither prove the route nor count as clicks_ok. Only what her CHIM line can
	 never cause proves the route (rsProof: the list cleared, a menu on top - whether CHIM's playback
	 moves the menu's subtitle or progress timer is unmeasured); no signal 9 s after a click on route A
	 flips it./;
	if !rsSignal
		if LRG_DlgUI.EntryCount() == 0
			rsSignal = true ; the engine always ClearList()s before it repopulates
			rsProof = true
		endif
		if !rsSignal && LRG_DlgUI.ProgressTimerId() != preTimer
			rsSignal = true
		endif
		if !rsSignal && !mmBase && Utility.IsInMenuMode()
			; a menu opened on top of ours after our click: that is the hand-off = SUCCESS
			rsSignal = true
			rsProof = true
			rsOther = OtherMenuName()
		endif
		if !rsSignal && subsOn && calSub != preSub && StringUtil.GetLength(calSub) > 1
			rsSignal = true ; a new line (the subtitle HarvestLine read this poll): verified, but no route proof
		endif
		if !rsSignal
			if route == 1 && !routeFlipped && (afNow - clickAt) > 9.0
				routeFlipped = true
				FlipRoute("no signal 9 s after a click on route A")
			endif
			if (afNow - respStart) > 4.0 && IsCheckKind(reqKind)
				SettleAndReport(false)
			elseif (afNow - respStart) > 20.0
				SettleAndReport(false)
			endif
			return
		endif
		if rsProof
			RouteProof()
		else
			; [v1.0 review r2] a subtitle / the progress timer came first: the list may still clear during her line -
			; looked for until the menu reads ready, only while the install's route is unproven (a proven one pays nothing)
			if rlSess < 0 && !routeProved
				rlSess = RouteLive() as int
			endif
			rsWatch = !routeProved && rlSess == 0
		endif
	elseif rsWatch && LRG_DlgUI.EntryCount() == 0 && LRG_DlgUI.IsOpen()
		rsWatch = false
		rsProof = true
		RouteProof()
	endif
	if route == 1 && LRG_DlgUI.GateArmed() && (afNow - clickAt) > 9.0
		; never self-release the gate and never click again: report, then stop driving
		SettleAndReport(true)
		if dlgState != ST_IDLE
			StopDriving("unverified", true)
		endif
		return
	endif
	if LRG_DlgUI.MenuState() == 1
		SettleAndReport(true)
		return
	endif
	if (afNow - respStart) > 90.0
		SettleAndReport(true)
	endif
EndFunction

Function SettleAndReport(bool abVerified)
	;/The settle poll: for a check or a payment, wait up to 4 s for one of the PRE facts to move.
	 Any movement is positive verification and overrides an otherwise unverified click./;
	float t0 = Utility.GetCurrentRealTime()
	bool moved = PreFactsMoved()
	if !moved && IsCheckKind(reqKind)
		while !moved && (Utility.GetCurrentRealTime() - t0) < 4.0
			Utility.WaitMenuMode(0.25)
			loopBeat = Utility.GetCurrentRealTime()
			moved = PreFactsMoved()
		endwhile
	endif
	bool ok = abVerified || rsSignal || moved
	string why = ""
	if !ok
		why = "unverified"
	endif
	rsClosed = !LRG_DlgUI.IsOpen()
	SendResult(ok, why, Utility.GetCurrentRealTime() - clickAt)
	clickAuto = false
	if ok
		ReportOnce("OK: The matter is raised.")
	else
		; [v1.0 review / S7, model row 28] nothing came of the click: never a false OK - her words say so
		ReportOnce("Error: nothing came of that")
	endif
	ClearRequest(false)
	if rsClosed
		dlgState = ST_IDLE
	elseif ok
		sig = ""
		dlgState = ST_READING
	else
		; nothing came of the click: the list is his from here (S7 "nothing came of that", model row 28)
		StopDriving("unverified", true)
	endif
EndFunction

Function EnterSuspended()
	held = false
	suspFrom = dlgState
	dlgState = ST_SUSPENDED
	stateTime = Utility.GetCurrentRealTime()
	Main().LogC(sessCid, "suspended: " + OtherMenuName() + " opened on top", npcName)
EndFunction

bool Function ServiceMenuOpen()
	{[0.5.1 pt9 go-live, G9] The three windows a dialogue entry can hand the player off to, asked for by
	 NAME instead of through Utility.IsInMenuMode(), whose value inside a Dialogue Menu is unmeasured on
	 this install. Three natives, and only on a poll where the topic list has just emptied.}
	if LRG_DlgUI.OtherMenuOpen("BarterMenu")
		return true
	elseif LRG_DlgUI.OtherMenuOpen("Training Menu")
		return true
	endif
	return LRG_DlgUI.OtherMenuOpen("GiftMenu")
EndFunction

string Function OtherMenuName()
	;/Which pausing menu is on top. Only ever called once, when a suspension or a hand-off has
	 already been detected through Utility.IsInMenuMode(), so the nine reads cost nothing per poll./;
	if LRG_DlgUI.OtherMenuOpen("BarterMenu")
		return "barter"
	elseif LRG_DlgUI.OtherMenuOpen("Training Menu")
		return "training"
	elseif LRG_DlgUI.OtherMenuOpen("GiftMenu")
		return "gift"
	elseif LRG_DlgUI.OtherMenuOpen("ContainerMenu")
		return "container"
	elseif LRG_DlgUI.OtherMenuOpen("MessageBoxMenu")
		return "messagebox"
	elseif LRG_DlgUI.OtherMenuOpen("Crafting Menu")
		return "crafting"
	elseif LRG_DlgUI.OtherMenuOpen("Book Menu")
		return "book"
	elseif LRG_DlgUI.OtherMenuOpen("Journal Menu")
		return "journal"
	elseif LRG_DlgUI.OtherMenuOpen("CustomMenu")
		return "custom"
	endif
	return "menu"
EndFunction

Function StepSuspended(float afNow)
	;/A service window (trade, training, a gift) sits on top of the conversation. [v1.0] Nothing was
	 hidden or parked, so nothing is re-asserted: when the window closes, the list is read again.
	 Where the dialogue itself reads as menu mode (mmBase), the three service windows are asked for
	 by name instead, or a suspended session could never come back./;
	if !mmBase && Utility.IsInMenuMode()
		return
	endif
	if mmBase && ServiceMenuOpen()
		return
	endif
	sig = ""
	held = false
	if suspFrom == ST_MANUAL
		dlgState = ST_MANUAL
	else
		dlgState = ST_READING
	endif
	stateTime = afNow
	Main().LogC(sessCid, "suspended: the other menu closed, back to reading", npcName)
EndFunction

Function StepManual(float afNow)
	;/MANUAL: the list is the player's (drv=0). The driver still reads it and forwards it, so the
	 server learns the business either way, but it never clicks and never closes./;
	int n = LRG_DlgUI.EntryCount()
	lastN = n
	if n <= 0
		return
	endif
	if readMode != 3
		CalibrateRead(n)
		if readMode != 3
			return
		endif
	endif
	if n != nTotal || eHead <= 0 || LRG_DlgUI.EntryText(0, readMode) != eText[0]
		if ReadList(n)
			if !sentThisGen
				sentThisGen = true
				SendTopics(0, "")
			endif
		endif
	endif
	;/[v1.0 review / model row 32] a pick stamped before this session armed read-only (the sid=0 pick
	 that opened her list from the cached root) is answered once the list is read, in words true of
	 his screen./;
	if eHead > 0 && reqDo == "pick" && reqCmd != "" && !reqReported
		if PickOnList(reqTxt, reqPos)
			ReportOnce("Error: choose that one on the list yourself")
		else
			ReportOnce("Error: that is not on the table right now")
		endif
		ClearRequest(false)
	endif
EndFunction

Function BeginLeave()
	;/do=leave or the leave key. A back-out entry the server named is preferred over any script
	 close; a LETHAL session is never closed at all - the list is his. [v1.0 review / P3] Only
	 do=leave;pos=-1 and the leave key close the conversation: a back-out line that is no longer on
	 this layer (his click, a newer layer, 20 s) is a decision overtaken - closed silently, the layer
	 held. The glue never closes a layer the server has not decided about./;
	reqLeave = false
	if crit == 2
		ReportOnce("OK: The menu is shown.")
		StopDriving("lethal", true)
		return
	endif
	float now = Utility.GetCurrentRealTime()
	if reqDo == "leave" && reqPos >= 0
		reqDo = "pick"
		reqPickReady = true
		if TryResolvePick()
			held = false
			EnterClicking(now)
			readySeen = true
			return
		endif
		Main().LogC(reqCid, "leave overtaken - the back-out line pos=" + reqPos + " is not on this layer (x=" + reqX + ")", npcName)
		ReportOnce("OK: Noted.")
		ClearRequest(false)
		EnterHeld(now)
		return
	endif
	if reqDo != "leave" && reqCmd != "" && !reqReported
		; his leave key overtakes a decision of the server still waiting (an auto-advance, a pick)
		Main().LogC(reqCid, "pick overtaken - the leave key (x=" + reqX + ")", npcName)
		ReportOnce("OK: Noted.")
		ClearRequest(false)
	endif
	closeAsked = true
	held = false
	dlgState = ST_CLOSING
	closeAt = now
	LRG_DlgUI.CloseClean()
	ReportOnce("OK: The conversation is left.")
	ClearRequest(false)
EndFunction

Function StepClosing(float afNow)
	; CloseForce is never called from the driver: a close that did not take leaves the list his (design 2.7)
	if (afNow - closeAt) > 2.0
		StopDriving("close-failed", true)
	endif
EndFunction

Function Finish()
	;/The menu is gone. [v1.0] Nothing was hidden, so nothing is given back: the session is reported
	 and forgotten. It never asks the server for a second paid turn - no lrg_dlgtalk "again" (S2.1)./;
	finishing = true ; [0.5.1 pt9 go-live, G6] see OnMenuOpen and the pump
	LRG_Main m = Main()
	float now = Utility.GetCurrentRealTime()
	bool quick = (now - openTime) < 1.0 && !anyClick
	if dlgState == ST_RESPONDING
		rsClosed = true
		SettleAndReport(true)
	endif
	if reqCmd != "" && !reqReported
		if reqAdv >= 0
			; [v1.0 review / F25, F26] an auto-advance still waiting is the glue's own initiative: silent
			ReportOnce("OK: Noted.")
		else
			ReportOnce("Error: that moment has passed")
		endif
	endif
	ClearRequest(false)
	if qCmd != "" && m
		; a command that waited for the click in flight: the conversation is over now
		string qr = "Error: that moment has passed"
		string qv = m.ParamGet(qParam, "do")
		if qv == "leave"
			qr = "OK: The conversation is left."
		elseif qv != "pick" || m.ParamGet(qParam, "adv") != ""
			qr = "OK: Noted."
		endif
		XPush(qX)
		m.ReportResult(qNpc, qCmd, qParam, qr)
		qX = ""
		qNpc = ""
		qCmd = ""
		qParam = ""
	endif
	string why = "external"
	if quick
		why = "refused"
	elseif closeAsked
		why = "asked"
	elseif (!mmBase && Utility.IsInMenuMode()) || ServiceMenuOpen()
		; [0.5.1 pt9 go-live, G9] by name as well as by menu mode - once per session, so it is free here
		why = "handoff"
	elseif anyClick && !pendingLayer
		why = "goodbye"
	endif
	if pendingLayer && why == "external"
		dLayersLost += 1
	endif
	if glueOpened && !anyClick
		dOpensNoMatch += 1
	endif
	float[] sgNow = ReadSpeechGlobals()
	string drift = DriftCsv(sgNow)
	if drift != "0,0,0,0,0"
		dDrifts += 1
		if dDrifts >= 3 && m
			m.LogE(sessCid, "WARNING the five Speech globals have drifted in " + dDrifts \
				+ " sessions - a LoreRim/Little Lessons data bug, not the glue (see the MCM help)", npcName)
		endif
	endif
	SendClosed(why, SgCsv(sgNow), drift)
	if m
		m.LogC(sessCid, "closed sid=" + sid + " why=" + why + " layer=" + layer + " clicked=" + I(anyClick) \
			+ " pending=" + I(pendingLayer) + " drift=" + drift + " svc=" + sessSvc + " sj=" + I(sjScene), npcName)
	endif
	;/v0.5: the close hook, then ev=calib - only when this session really taught the calibration
	 something (CalDirty, cleared in the same call) and at most once per 30 s, so a calibrated install
	 sends nothing at all (W1). [v1.0 / F22] no switch./;
	LRG_DlgProbe p = Probe()
	if p
		p.CalClose(why, layer, pendingLayer, anyClick)
		if p.CalDirty(true)
			SendCalib("passive")
		endif
	endif
	ClearSession()
	dlgState = ST_IDLE
EndFunction

Function StopDriving(string asWhy, bool abTell)
	;/[v1.0 / S1.1] THE ONE WAY A DRIVEN SESSION BECOMES READ-ONLY (it replaces the hand-back): a fight,
	 a journal-quest scene before the route is proven, a list that cannot be read, a click nothing
	 came of, a close that did not take, the lethal rule. Nothing is un-hidden - nothing was hidden.
	 Order: close the decision in flight (exactly one funcret per x), MANUAL (the list is still read
	 and forwarded, drv=0), one log line, and - abTell - one ev=stopped so the server stops offering
	 keys and says why once (model F2). Only lethal puts up a corner note: it surprises the player./;
	reqPickReady = false
	bool keep = false
	if reqCmd != "" && !reqReported
		if reqAdv >= 0
			ReportOnce("OK: Noted.") ; an auto-advance was the glue's own idea; ev=stopped carries the why
		elseif asWhy == "lethal" && reqDo == "pick"
			; [v1.0 review r2] true only of a line on HIS list; not read yet (a lethal Arm): StepManual answers it
			if eHead <= 0
				keep = true
			elseif PickOnList(reqTxt, reqPos)
				ReportOnce("Error: choose that one on the list yourself")
			else
				ReportOnce("Error: that is not on the table right now")
			endif
		else
			ReportOnce("Error: " + StopReason(asWhy))
		endif
	endif
	if !keep
		ClearRequest(false)
	endif
	held = false
	if dlgState == ST_MANUAL
		return
	endif
	dlgState = ST_MANUAL
	stateTime = Utility.GetCurrentRealTime()
	dStops += 1
	LRG_Main m = Main()
	if m
		m.LogC(sessCid, "stopped driving why=" + asWhy + " sid=" + sid + " layer=" + layer + " n=" + eHead, npcName)
	endif
	if asWhy == "lethal"
		Debug.Notification("LoreRim Glue: choose this one by hand")
	endif
	if abTell
		SendStopped(asWhy)
	endif
EndFunction

string Function StopReason(string asWhy)
	{The closed-list reason a decision still in flight is closed with when the driver stops.}
	if asWhy == "read-failed"
		return "the list could not be read"
	elseif asWhy == "combat"
		return "combat"
	elseif asWhy == "scene"
		return "a quest scene is running"
	elseif asWhy == "lethal"
		return "choose that one on the list yourself" ; [v1.0 review / model row 32] the list is his
	endif
	return "that moment has passed"
EndFunction

Function NoteSpeech(int aiWho, float afAt, Actor akWho = None)
	;/LRG_Main forwards CHIM's speech events and the talk key here while a session is live.
	 0 = the PLAYER: his CHIM-voiced line or his talk key (speechAt - the auto-advance cancel);
	 1 = an NPC's CHIM line started, stopped or its text arrived; 2 = an NPC's CHIM VOICE started
	 (herVoiceAt - the re-armed anchor, model F12). [CHIM brief P7] Only the SESSION speaker counts
	 for 1 and 2: a bystander's chatter must not hold her auto-advance. The probe's X1 measurement
	 still hears every event, as it always did. [v1.0 review] 3 = his CHIM-voiced line ENDED: the
	 probe hears it as the player, the auto-advance does not - the end of a line he already spoke is
	 no new utterance (S4.6's cancel is his talk key or his voice starting)./;
	if dlgState == ST_IDLE
		return
	endif
	if aiWho == 0
		speechAt = afAt
	elseif aiWho == 1 || aiWho == 2
		Actor who = speaker
		if who == None
			who = openNpc
		endif
		if akWho == None || who == None || akWho == who
			npcSpeechAt = afAt
			if aiWho == 2
				herVoiceAt = afAt
			endif
		endif
	endif
	LRG_DlgProbe p = Probe()
	if p
		int w = aiWho
		if w == 3
			w = 0
		elseif w > 1
			w = 1
		endif
		p.CalSpeech(w, afAt)
	endif
EndFunction

; ---------------------------------------------------------------------------
; The topic dump (design 1.10) - the T2 parity tool
; ---------------------------------------------------------------------------
Function DoDump()
	LRG_Main m = Main()
	if !m || !LRG_DlgUI.IsOpen()
		return
	endif
	string cid = m.NextCid()
	Actor npc = speaker
	if npc == None
		npc = Game.GetDialogueTarget() as Actor
	endif
	string who = npcName
	if npc != None
		who = npc.GetDisplayName()
	endif
	int n = LRG_DlgUI.EntryCount()
	lastN = n
	int rows = LRG_DlgUI.MaxItemsShown()
	m.LogC(cid, "DUMP n=" + n + " st=" + LRG_DlgUI.MenuState() + " fam=" + LRG_DlgUI.SwfFamily() \
		+ " items=" + rows + " plat=" + LRG_DlgUI.Platform() + " subs=" + I(LRG_DlgUI.SubtitlesOn()) \
		+ " mode=" + readMode, who)
	if n <= 0
		LogDiagnostics()
		return
	endif
	; [v1.0] mode 4 (it walks iSelectedIndex) is never read: the menu is never hidden or parked
	int cap = n
	if cap > 24
		cap = 24
	endif
	int i = 0
	while i < cap
		string t3 = LRG_DlgUI.EntryText(i, 3)
		string t5 = "-"
		int col = -1
		int row = -1
		if i < rows
			t5 = LRG_DlgUI.EntryText(i, 5)
			col = LRG_DlgUI.EntryColour(i)
			row = LRG_DlgUI.EntryRowItem(i)
		endif
		eText[i] = t3
		eIdx[i] = LRG_DlgUI.EntryTopicIndex(i, 3)
		m.LogC(cid, "DUMP pos=" + i + " ti=" + eIdx[i] + " new=" + I(LRG_DlgUI.EntryIsNew(i, 3)) \
			+ " col=" + col + " row=" + row + " t3=" + LRG_Profile.Clip(t3, 60) \
			+ " t5=" + LRG_Profile.Clip(t5, 40), who)
		i += 1
		if (i % 4) == 0
			Utility.WaitMenuMode(0.05)
			loopBeat = Utility.GetCurrentRealTime()
		endif
	endwhile
	nTotal = n
	eHead = cap
	tCount = 0
	if speaker == None && npc != None
		speaker = npc
		npcName = who
	endif
	if sid == ""
		sid = NewSid()
	endif
	SendTopics(0, "dump")
	sig = "" ; the session must read the list again after a dump
	LogDiagnostics()
EndFunction

; ---------------------------------------------------------------------------
; Wire: game -> server (every send goes through LRG_Main's existing path)
; ---------------------------------------------------------------------------
Function SendTopics(int aiWant, string asOriginOverride)
	;/lrg_topics, key order fixed, e LAST and capped by BOTH iMaxEntries and 1,600 raw chars; n is
	 ALWAYS the full EntryCount(). Everything past the cap goes out as a part=2 tail of texts.
	 [v1.0] sj= / drv= on EVERY list (model F1: the session's verdict survives its layers, and a scene
	 that began mid-session says so here), hc=1 on a layer HIS click produced (F9), want=0 on a list
	 the driver does not drive, and the stamped open's ask= on the first list of its session (F4)./;
	LRG_Main m = Main()
	if !m || !sWire || speaker == None || eHead <= 0
		return
	endif
	string org = origin
	if asOriginOverride != ""
		org = asOriginOverride
	endif
	string cid = reqCid
	if cid == ""
		cid = sessCid
	endif
	bool drv = dlgState != ST_MANUAL
	int want = aiWant
	if !drv
		want = 0
	endif
	string ask = reqAsk
	if ask == "" && want == 1
		ask = openAsk
		openAsk = ""
	endif
	bool gold = HasGoldTag()
	string ents = BuildEntries(0, eHead, 1600)
	int firstTail = lastSent
	string mid = ";part=1;cid=" + cid + ";want=" + want + ";ask=" + m.CleanForWire(ask) \
		+ ";crit=" + crit + ";scene=" + I(inScene) + ";fam=" + fam + ";sub=" + I(subsOn)
	if gold
		mid += ";pg=" + Game.GetPlayer().GetGoldAmount() + ";bamt=" + speaker.GetBribeAmount()
	endif
	mid += ";vt=" + Hex8(VoiceId(speaker)) + ";q=" + sessQ + ";sp=" + PlayerSpeech() \
		+ ";perk=" + PerkCsv() + ";lvl=" + Game.GetPlayer().GetLevel() + DlgCfgWire()
	;/v0.5: svck= rides HERE as well as on ev=open (W13): this is the carrier that describes the layer
	 it belongs to. Additive, like every key after it; `e=` stays LAST./;
	mid += ";svck=" + layerSvc + ";sj=" + I(sjScene) + ";drv=" + I(drv)
	if hcGen == gen
		mid += ";hc=1"
	endif
	m.SendNpcMessage(MSG_TOPICS, TopicHead(org, firstTail) + mid + ";e=" + ents, npcName)
	int payload = StringUtil.GetLength(ents)
	bool part2 = false
	int tailChars = 0
	if firstTail < eHead || tCount > 0
		; part 2: whatever the byte budget cut from the head, then the tail - texts only
		string tail = BuildTail(firstTail, 1600)
		if tail != ""
			part2 = true
			tailChars = StringUtil.GetLength(tail)
			m.SendNpcMessage(MSG_TOPICS, TopicHead(org, lastSent) + ";part=2;cid=" + cid \
				+ ";want=0;e=" + tail, npcName)
		endif
	endif
	; v0.5 P13 for free: the wire cost of the largest layer, measured on the payload just built
	LRG_DlgProbe p = Probe()
	if p
		p.CalPayload(payload, part2, tailChars)
	endif
EndFunction

string Function TopicHead(string asOrigin, int aiSent)
	return "v=1;sid=" + sid + ";gen=" + gen + ";layer=" + layer + ";origin=" + asOrigin \
		+ ";ref=" + Hex8(speaker.GetFormID()) + ";npc=" + Main().CleanForWire(npcName) \
		+ ";st=" + LRG_DlgUI.MenuState() + ";n=" + nTotal + ";sent=" + aiSent
EndFunction

string Function BuildEntries(int aiFrom, int aiTo, int aiBudget)
	;/<pos>~<topicIndex>~<new>~<col>~<text>, joined by ~~. new and the row colour are only read in
	 the dump and by the probe (design 1.7 cost control), so both are "-" here - [v1.0] the quest
	 colour hint (bQuestColour) is retired. lastSent carries the number of entries that fitted./;
	string out = ""
	int i = aiFrom
	lastSent = aiFrom
	while i < aiTo
		string one = ""
		if i > aiFrom
			one = "~~"
		endif
		one += i + "~" + eIdx[i] + "~-~-~" + CleanEntry(eText[i])
		if (StringUtil.GetLength(out) + StringUtil.GetLength(one)) > aiBudget
			return out
		endif
		out += one
		lastSent = i + 1
		i += 1
	endwhile
	return out
EndFunction

string Function BuildTail(int aiFromHead, int aiBudget)
	string out = ""
	int sent = 0
	int i = aiFromHead
	while i < eHead
		string one = ""
		if out != ""
			one = "~~"
		endif
		one += i + "~-~-~-~" + CleanEntry(eText[i])
		if (StringUtil.GetLength(out) + StringUtil.GetLength(one)) > aiBudget
			lastSent = sent
			return out
		endif
		out += one
		sent += 1
		i += 1
	endwhile
	int t = 0
	while t < tCount
		string one = ""
		if out != ""
			one = "~~"
		endif
		one += (eHead + t) + "~-~-~-~" + CleanEntry(tText[t])
		if (StringUtil.GetLength(out) + StringUtil.GetLength(one)) > aiBudget
			lastSent = sent
			return out
		endif
		out += one
		sent += 1
		t += 1
	endwhile
	lastSent = sent
	return out
EndFunction

Function SendOpen(bool abDrv)
	;/[0.4 fix pass, D5] Every sender is gated on bDlgWire. OFF = the Phase 2 message types never
	 leave the game, so a server still on 0.3.1 cannot answer them as ordinary paid LLM turns.
	 [v1.0 / S1.1, S8] Additive after svck=: scene= (ANY scene, the meaning lrg_topics has always
	 given it), sj=1 only for a scene whose owning quest shows an unfinished journal objective, sq= /
	 sqj= (the basis, so a wrong verdict is one grep), drv=1|0 (who drives), hid=0 (nothing is ever
	 hidden) and quiet=1 while quiet mode runs (model F17)./;
	LRG_Main m = Main()
	if !m || !sWire || speaker == None
		return
	endif
	float dist = speaker.GetDistance(Game.GetPlayer())
	string tail = ";scene=" + I(inScene) + ";sj=" + I(sjScene) + ";sq=" + sessSq + ";sqj=" + sessSqj \
		+ ";drv=" + I(abDrv) + ";hid=0"
	if quietArm
		tail += ";quiet=1"
	endif
	m.SendNpcMessage(MSG_DLG, "ev=open;sid=" + sid + ";origin=" + origin \
		+ ";ref=" + Hex8(speaker.GetFormID()) + ";npc=" + m.CleanForWire(npcName) \
		+ ";crit=" + crit + ";fam=" + fam + ";vt=" + Hex8(VoiceId(speaker)) \
		+ ";dist=" + (dist as int) + ";sub=" + I(subsOn) + ";sg=" + SgCsv(sgOpen) \
		+ DlgCfgWire() + ";cal=" + CalWireShort() + ";svck=" + layerSvc + tail, npcName)
EndFunction

string Function CalWireShort()
	;/W2: the resolved calibration in four letters, on every ev=open, so the server can reason about
	 a session without waiting for the next ev=calib. rm = how the list is read, cm = how it is
	 counted, rt = which click the menu takes, g = is the gate green./;
	return "rm" + calRm + "cm" + calCm + "rt" + calRoute + "g" + I(calGreen)
EndFunction

Function SendCalib(string asSrc)
	;/W1 ev=calib. The calibration belongs to the INSTALL, not to a save or an NPC, so this is the
	 one message that is really about the machine. k= is LAST and RAW by contract (it carries
	 commas), it is never CleanForWire'd, and lane A guarantees it holds no ';'.
	 Throttled to one per 30 s: an install that has stopped learning sends nothing at all./;
	LRG_Main m = Main()
	LRG_DlgProbe p = Probe()
	if !m || !p || !sWire
		return
	endif
	float now = Utility.GetCurrentRealTime()
	if calSentAt > 0.0 && now >= calSentAt && (now - calSentAt) < 30.0
		return
	endif
	string raw = p.CalWire()
	if raw == ""
		return
	endif
	calSentAt = now
	string who = npcName
	string ref = "00000000"
	if speaker != None
		ref = Hex8(speaker.GetFormID())
	endif
	if who == ""
		who = Game.GetPlayer().GetDisplayName()
	endif
	string miss = p.CalMissing()
	if miss == ""
		miss = "-"
	endif
	m.SendNpcMessage(MSG_DLG, "ev=calib;v=1;ref=" + ref + ";npc=" + m.CleanForWire(who) \
		+ ";src=" + asSrc + ";gate=" + I(p.CalGreen()) \
		+ ";miss=" + m.CleanForWire(miss) + ";k=" + raw, who)
EndFunction

Function SendLat(Actor akNpc, string asWho, int aiAsk, int aiReply, int aiVoice, int aiTotal, int aiFirst)
	;/W14 ev=lat, sent by LRG_Main when her VOICE starts. It is never LLM-bearing and it is the only
	 number in this project that includes TTS and playback. The formatting lives here because every
	 other lrg_dlg message does; the measurement lives in LRG_Main, which owns the CHIM events./;
	LRG_Main m = Main()
	if !m || !sWire || akNpc == None
		return
	endif
	string s = "0"
	if dlgState != ST_IDLE && sid != ""
		s = sid
	endif
	m.SendNpcMessage(MSG_DLG, "ev=lat;v=1;ref=" + Hex8(akNpc.GetFormID()) \
		+ ";npc=" + m.CleanForWire(asWho) + ";ask=" + aiAsk + ";reply=" + aiReply \
		+ ";voice=" + aiVoice + ";total=" + aiTotal + ";sid=" + s + ";first=" + aiFirst, asWho)
EndFunction

string Function DlgCfgWire()
	;/[0.4 fix pass, D11] The DIALOGUE knob the server reads from the game: io = bIntentOpen.
	 [v1.0] bi (iBranchInput), rw (bRewalk) and rwd (iRewalkDepth) are retired with their controls
	 and are no longer sent; the server keeps its own default for an absent key./;
	return ";io=" + I(sIntentOpen)
EndFunction

Function SendClosed(string asWhy, string asSg, string asDrift)
	LRG_Main m = Main()
	if !m || !sWire || speaker == None
		return
	endif
	m.SendNpcMessage(MSG_DLG, "ev=closed;sid=" + sid + ";ref=" + Hex8(speaker.GetFormID()) \
		+ ";npc=" + m.CleanForWire(npcName) + ";why=" + asWhy + ";pending=" + I(pendingLayer) \
		+ ";layer=" + layer + ";sg=" + asSg + ";drift=" + asDrift, npcName)
EndFunction

Function SendStopped(string asWhy)
	{[v1.0 / model F2] ev=stopped: the driver has stopped driving this session (StopDriving). The
	 server makes it read-only (drv=0) and may say why once next turn. An old server logs "unknown ev".}
	LRG_Main m = Main()
	if !m || !sWire || speaker == None
		return
	endif
	m.SendNpcMessage(MSG_DLG, "ev=stopped;sid=" + sid + ";ref=" + Hex8(speaker.GetFormID()) \
		+ ";npc=" + m.CleanForWire(npcName) + ";why=" + asWhy + ";layer=" + layer + ";n=" + eHead, npcName)
EndFunction

Function SendResult(bool abOk, string asWhy, float afDur)
	LRG_Main m = Main()
	if !m || !sWire || speaker == None
		return
	endif
	Actor player = Game.GetPlayer()
	int dP = Game.QueryStat("Persuasions") - preP
	int dB = Game.QueryStat("Bribes") - preB
	int dI = Game.QueryStat("Intimidations") - preI
	int dGold = player.GetGoldAmount() - preGold
	int bribed = 0
	if speaker.IsBribed() != preBribed
		bribed = 1
	endif
	int intim = 0
	if speaker.IsIntimidated() != preIntim
		intim = 1
	endif
	string txt = ""
	if reqPos >= 0 && reqPos < eHead
		txt = CleanEntry(eText[reqPos])
	endif
	m.SendNpcMessage(MSG_DLG, "ev=result;sid=" + sid + ";gen=" + gen + ";cid=" + reqCid \
		+ ";x=" + reqX + ";ref=" + Hex8(speaker.GetFormID()) + ";npc=" + m.CleanForWire(npcName) \
		+ ";pos=" + reqPos + ";i=" + reqIdx + ";kind=" + reqKind + ";ok=" + I(abOk) \
		+ ";why=" + asWhy + ";closed=" + I(rsClosed) + ";other=" + rsOther \
		+ ";dP=" + dP + ";dB=" + dB + ";dI=" + dI + ";gold=" + dGold + ";bribed=" + bribed \
		+ ";intim=" + intim + ";bamt=" + preBamt + ";wis=" + I(preWis) + ";sp=" + preSp \
		+ ";dur=" + afDur + ";auto=" + I(clickAuto) + ";txt=" + txt, npcName)
EndFunction

Function HarvestLine(float afNow)
	;/A new non-blank subtitle is the glue's own ground truth. De-duplication is keyed on the PAIR
	 (gen, string): shared DNAM responses repeat, so the same line in two layers is two lines.
	 [v1.0 / model F10, F11] The same read keeps the auto-advance stamps - lineSeenAt (the last
	 non-blank read) and blankSince (the start of the current blank run; blank = shorter than 2
	 characters) - and counts new lines for HELD. 0 new natives: the read was happening anyway./;
	string s = LRG_DlgUI.Subtitle()
	calSub = s ; v0.5: the calibration's subtitle sample, from a read that was happening anyway
	if StringUtil.GetLength(s) < 2
		if blankSince <= 0.0
			blankSince = afNow
		endif
		return
	endif
	lineSeenAt = afNow
	blankSince = 0.0
	int i = 0
	while i < 4
		if lnGen[i] == gen && lnText[i] == s
			return
		endif
		i += 1
	endwhile
	lnText[lnSlot] = s
	lnGen[lnSlot] = gen
	lnSlot += 1
	if lnSlot >= 4
		lnSlot = 0
	endif
	lnSeq += 1
	LRG_Main m = Main()
	if !m || !sWire || speaker == None
		return
	endif
	m.SendNpcMessage(MSG_DLG, "ev=line;sid=" + sid + ";gen=" + gen \
		+ ";ref=" + Hex8(speaker.GetFormID()) + ";npc=" + m.CleanForWire(npcName) \
		+ ";t=" + LRG_Profile.Clip(m.CleanForWire(s), 300), npcName)
EndFunction

Function SendFacts(Actor akNpc, float afNow)
	;/ev=facts: the ONE place quest and speech facts travel outside a session. Throttled 60 s per
	 NPC, never sent while a session is open, and bounded (<= 6 quests x <= 2 objectives)./;
	LRG_Main m = Main()
	if !m || !sWire
		return
	endif
	string who = akNpc.GetDisplayName()
	string qcsv = "-"
	string qobj = "-"
	string qal = "-"
	string qst = "-"
	int quiet = 0
	if m.QuietOn()
		quiet = 1 ; [pt19 / script 512] quiet mode: the flag the server mirrors (lrgDlgFactsFrom quiet=); no PO3 sweep while it is on
	endif
	if sQuestAware && quiet == 0
		; [pt15] ONE po3 quest sweep feeds both lists (it used to run twice back to back); [pt17] and qst=
		Quest[] qs = PO3_SKSEFunctions.GetActiveAssociatedQuests(akNpc, true)
		qcsv = QuestCsvOf(qs)
		qobj = QobjCsvOf(qs)
		qst = QstCsvOf(qs)
		qal = QalCsv(akNpc)
	endif
	int dlgOn = 0
	if sMenuless && !sDryRun
		dlgOn = 1
	endif
	;/[pt17] Three additive keys. cal= how many of the four calibration rows are answered (v1.0:
	 0..4). sq= the EditorID of the quest owning the scene she is in ("-" for none / unknown) and sqj=
	 1 when that quest shows an unfinished journal objective: the server keeps its facts and words ON
	 for an ambient scene (Rikke at the map table) and OFF for a real one. qst= <quest>:<current
	 stage> for the quests she is in, so a recruiter's "before" line can be keyed on the real stage.
	 [v1.0 / S1.1] sq / sqj come from SceneBasis, the SAME test Arm() and OpenBlockedReason use; this
	 fresh answer is what Arm() may reuse for 30 s (0 natives on the E-press that follows)./;
	string sq = "-"
	int sqj = 0
	Scene sc = akNpc.GetCurrentScene()
	if sc != None
		string sb = SceneBasis(akNpc, sc, quiet == 1, true)
		int bar = StringUtil.Find(sb, "|")
		if bar > 0
			sq = StringUtil.Substring(sb, 0, bar)
			sqj = StringUtil.Substring(sb, bar + 1) as int
		endif
	endif
	;/[pt18-quest / script 510] three additive keys for the Legion road on this load order (lib/lrg_factions.php
	 lrgFacRoad): mqq= GLOB MQQuickstart (AlternatePerspective.esp pins it at 7.0 until its Helgen path), mq101= MQ101's
	 current stage, mq101c= 1 when MQ101 is complete (a completed quest leaves the PO3 sweep, so qst= can never show it).
	 By FormID, never an editor-ID lookup: Skyrim.esm 0004679E / 0003372B. "-" = unknown (the server skips it)./;
	string mqq = "-"
	GlobalVariable gvq = Game.GetFormFromFile(0x0004679E, "Skyrim.esm") as GlobalVariable
	if gvq != None
		mqq = (gvq.GetValue() as int) as string
	endif
	string mq101 = "-"
	string mq101c = "-"
	Quest qHelgen = Quest.GetQuest("MQ101")
	if qHelgen == None
		qHelgen = Game.GetFormFromFile(0x0003372B, "Skyrim.esm") as Quest
	endif
	if qHelgen != None
		mq101 = qHelgen.GetCurrentStageID() as string
		if qHelgen.IsCompleted()
			mq101c = "1"
		else
			mq101c = "0"
		endif
	endif
	m.SendNpcMessage(MSG_DLG, "ev=facts;ref=" + Hex8(akNpc.GetFormID()) + ";npc=" + m.CleanForWire(who) \
		+ ";q=" + qcsv + ";vt=" + Hex8(VoiceId(akNpc)) + ";dlg=" + dlgOn + ";sp=" + PlayerSpeech() \
		+ ";perk=" + PerkCsv() + ";lvl=" + Game.GetPlayer().GetLevel() \
		+ ";wis=" + I(akNpc.WillIntimidateSucceed()) + ";qobj=" + qobj \
		+ ";sg=" + SgCsv(ReadSpeechGlobals()) + ";qal=" + qal + ";qgiver=" + I(qgiver) \
		+ ";cal=" + CalAnsweredNow() + ";sq=" + sq + ";sqj=" + sqj + ";qst=" + qst + ";mqq=" + mqq + ";mq101=" + mq101 + ";mq101c=" + mq101c + ";quiet=" + quiet \
		+ CrimeWire(akNpc), who)
	m.LogV("", "facts npc=" + who + " q=" + qcsv + " qal=" + qal + " al=" + qalRaw + " sq=" + sq + " sqj=" + sqj + " ms=" \
		+ (((Utility.GetCurrentRealTime() - afNow) * 1000.0) as int), who)
EndFunction

string Function QstCsvOf(Quest[] qs)
	{[pt17] <questEditorID>:<current stage id> for at most six quests of a sweep the caller made; "-" for none.}
	if !qs
		return "-"
	endif
	string s = ""
	int used = 0
	int i = 0
	while i < qs.Length && used < 6
		if qs[i] != None
			string id = qs[i].GetID()
			if id != ""
				if s != ""
					s += ","
				endif
				s += id + ":" + qs[i].GetCurrentStageID()
				used += 1
			endif
		endif
		i += 1
	endwhile
	if s == ""
		return "-"
	endif
	return s
EndFunction

string Function CrimeWire(Actor akNpc)
	;/W10 (E7): the bounty is a FACT, not something the model may invent. One native decides it -
	 an NPC with no crime faction is not a guard and has no bounty to talk about, and that case
	 costs exactly that one call. An ABSENT key means the server has no bounty fact and allows no
	 bounty claim, which is the strict reading the wire's ground rules ask for./;
	Faction cf = akNpc.GetCrimeFaction()
	if cf == None
		return ";guard=0;bounty=0;cf=-"
	endif
	return ";guard=" + I(akNpc.IsGuard()) + ";bounty=" + cf.GetCrimeGold() \
		+ ";cf=" + Hex8(cf.GetFormID())
EndFunction

string Function QalCsv(Actor akNpc)
	;/W3 / W4 (E2e): what this NPC IS in the quests she is part of - "DialogueWhiterun:QuestGiver",
	 "MS11:Adrianne". The alias name is a fact she would know about herself, never a spoiler about
	 the quest; the server drops it entirely when the journal has no row for that quest.
	 Bounded on purpose: at most six aliases, 24 characters each, and the two separators of this
	 value (":" and ",") are stripped out of the name before it reaches the wire./;
	qgiver = false
	qalRaw = -1
	Alias[] al = PO3_SKSEFunctions.GetRefAliases(akNpc as ObjectReference)
	if !al
		return "-"
	endif
	qalRaw = al.Length
	LRG_Main m = Main()
	string s = ""
	int used = 0
	int i = 0
	while i < al.Length && used < 6
		Alias a = al[i]
		if a != None
			Quest q = a.GetOwningQuest()
			if q != None && q.IsActive()
				string id = q.GetID()
				string nm = a.GetName()
				if id != "" && nm != ""
					if StringUtil.Find(nm, "Giver") >= 0 || StringUtil.Find(nm, "giver") >= 0
						qgiver = true
					endif
					nm = m.ReplaceChar(m.ReplaceChar(m.CleanForWire(nm), ":", " "), ",", " ")
					if s != ""
						s += ","
					endif
					s += id + ":" + LRG_Profile.Clip(nm, 24)
					used += 1
				endif
			endif
		endif
		i += 1
	endwhile
	if s == ""
		return "-"
	endif
	return s
EndFunction

; ---------------------------------------------------------------------------
; Crosshair: the voice keeper and ev=facts
; ---------------------------------------------------------------------------
Event OnCrosshairRefChange(ObjectReference akRef)
	; this event reaches every script on the quest form and fires constantly: cheapest tests first
	; [0.5.5] and LRG_Main registers the crosshair for the whole form before this module has booted
	if !booted || dlgState != ST_IDLE
		return
	endif
	Actor npc = akRef as Actor
	if npc == None
		return
	endif
	float now = Utility.GetCurrentRealTime()
	if FxThrottled(npc, now)
		return
	endif
	if npc.IsDead() || npc == Game.GetPlayer()
		return
	endif
	FxStamp(npc, now)
	ReadSettings(30.0)
	if !sMenuless && !sQuestAware
		return
	endif
	if AIAgentFunctions.getAgentByName(npc.GetDisplayName()) == None
		return
	endif
	VkRecord(npc)
	SendFacts(npc, now)
EndEvent

bool Function FxThrottled(Actor akNpc, float afNow)
	int i = 0
	while i < 8
		if fxRef[i] == (akNpc as Form)
			if afNow >= fxAt[i] && (afNow - fxAt[i]) < 60.0
				return true
			endif
			return false
		endif
		i += 1
	endwhile
	return false
EndFunction

Function FxStamp(Actor akNpc, float afNow)
	int i = 0
	while i < 8
		if fxRef[i] == (akNpc as Form)
			fxAt[i] = afNow
			return
		endif
		i += 1
	endwhile
	fxRef[fxSlot] = akNpc as Form
	fxAt[fxSlot] = afNow
	fxSlot += 1
	if fxSlot >= 8
		fxSlot = 0
	endif
EndFunction

; ---------------------------------------------------------------------------
; The voice keeper (design 1.5 / F2)
; ---------------------------------------------------------------------------
VoiceType Function VoiceOf(Actor akNpc)
	ActorBase b = akNpc.GetLeveledActorBase()
	if b == None
		return None
	endif
	return b.GetVoiceType()
EndFunction

int Function VoiceId(Actor akNpc)
	EnsureForms()
	VoiceType v = VoiceOf(akNpc)
	if v == None || v == nullVT
		v = VkLookup(akNpc)
	endif
	if v == None
		return 0
	endif
	return v.GetFormID()
EndFunction

Function VkRecord(Actor akNpc)
	EnsureForms()
	VoiceType v = VoiceOf(akNpc)
	if v == None || v == nullVT
		return
	endif
	Form f = akNpc as Form
	int i = 0
	while i < 128
		if vkRef[i] == f
			vkVoice[i] = v
			return
		endif
		i += 1
	endwhile
	vkRef[vkSlot] = f
	vkVoice[vkSlot] = v
	vkSlot += 1
	if vkSlot >= 128
		vkSlot = 0
	endif
EndFunction

VoiceType Function VkLookup(Actor akNpc)
	Form f = akNpc as Form
	int i = 0
	while i < 128
		if vkRef[i] == f
			return vkVoice[i]
		endif
		i += 1
	endwhile
	return None
EndFunction

bool Function VkRestore(Actor akNpc)
	;/Before Activate and before every click: if CHIM's DLL has written NullVoiceType into the
	 runtime base, put the real voice back. Returns false when no usable voice is known./;
	EnsureForms()
	ActorBase b = akNpc.GetLeveledActorBase()
	if b == None
		return false
	endif
	VoiceType cur = b.GetVoiceType()
	if cur != None && cur != nullVT
		VkRecord(akNpc)
		return true
	endif
	VoiceType kept = VkLookup(akNpc)
	if kept == None && reqVt != "" && reqVt != "0"
		kept = Game.GetFormEx(ParseId(reqVt)) as VoiceType
	endif
	if kept == None || kept == nullVT
		return false
	endif
	b.SetVoiceType(kept)
	return true
EndFunction

Function VkSweep()
	vkSweepAt = Utility.GetCurrentRealTime()
	Actor[] near = AIAgentFunctions.findAllNearbyAgents()
	; [0.5.6] NEVER "arr == None": see the block before QuestCsv. "!arr" is the array's own truth
	; test (a bool cast) and cannot fail; a None or an empty list both simply mean "nobody".
	if !near
		return
	endif
	int n = near.Length
	if n > 24
		n = 24
	endif
	int i = 0
	while i < n
		if near[i] != None
			VkRecord(near[i])
		endif
		i += 1
	endwhile
EndFunction

; ---------------------------------------------------------------------------
; Classification, route, quests
; ---------------------------------------------------------------------------
int Function ClassifyCrit(Actor akSpeaker)
	;/LETHAL = IsGuard() AND crime gold > 0 in her crime faction (spec S1.1, model F31). [v1.0] An
	 E-press on a guard with nothing against the player (ILQE's DB01 route) is crit 0 and driven: the
	 old "engine-opened guard" clause made every guard read-only. The arrest half - a DGCrime* or
	 arrest topic on the list - cannot be known at arming; it stays the server's arrest-class rail
	 (do=show, StopDriving lethal). COSTLY (crit 1) is a server-side grade; the game reports 0 or 2./;
	if !akSpeaker.IsGuard()
		return 0
	endif
	Faction f = akSpeaker.GetCrimeFaction()
	if f != None && f.GetCrimeGold() > 0
		return 2
	endif
	return 0
EndFunction

int Function ChooseRoute()
	if sClickRoute == 1 || sClickRoute == 2
		return sClickRoute
	endif
	;/v0.5: "automatic" now means "use the route the click test PROVED on this install" before it
	 means "work it out from the interface mod". The fam table below is still the answer when the
	 owner has not run press 1 yet, and 0 (never guess) is still the answer when neither knows./;
	if calRoute == 1 || calRoute == 2
		return calRoute
	endif
	if fam == 1
		return 2 ; route B: topicClicked() on CHIM's gate SWF
	endif
	if fam == 2
		return 1 ; route A: onSelectionClick(false) on the gate-less SWF
	endif
	return 0 ; never guess
EndFunction

;/[0.5.6] THE None-TO-ARRAY CAST - playtest 13, research/pt13-game-fix.md section 1.
 246 "Cannot cast from None to Quest[] / Actor[] / Alias[] / Int[]" errors, and not one of them came
 from a native returning None. The Papyrus compiler turns "arr == None" / "arr != None" into
 "cast <temp of the array type> None" followed by a compare, and the game's VM refuses to cast None
 to ANY array type. The natives were fine: the same error fired on calls that DID return quests
 (q=aaLisette,aaLisetteIdle arrived in the very same facts line). The failed cast also leaves its
 temp register holding an untyped None, which is why QobjCsv then logged "Mismatched types
 assigning to ::temp577" once per extra quest - exactly (quests - 1) times in every call - and
 lost the objectives of every quest after the first.
 The rule from 0.5.6 on: an array is tested with "!arr" / "if arr" (the VM's own truth test for an
 array: never an error, false for None and for an empty array), and its Length after that.
 The proof is a scan of the compiled .pex files for "cast <array-typed register> None": six before
 0.5.6 (all in this file), none after (research/pt13-game-fix.md)./;
string Function QuestCsv(Actor akNpc)
	return QuestCsvOf(PO3_SKSEFunctions.GetActiveAssociatedQuests(akNpc, true))
EndFunction

string Function QuestCsvOf(Quest[] qs)
	; [pt15] the list from a sweep the caller already made. "!qs" is the None-safe test (see above).
	if !qs
		return "-"
	endif
	string s = ""
	int used = 0
	int i = 0
	while i < qs.Length && used < 6
		if qs[i] != None
			string id = qs[i].GetID()
			if id != ""
				if s != ""
					s += ","
				endif
				s += id
				used += 1
			endif
		endif
		i += 1
	endwhile
	if s == ""
		return "-"
	endif
	return s
EndFunction

string Function QobjCsvOf(Quest[] qs)
	;/<questEditorID>:<stage>:<objectiveId>.<displayed><completed><failed>, at most two objectives
	 per quest and six quests. Papyrus cannot read objective TEXT anywhere, so the game sends
	 identifiers and the server supplies the words from CHIM's own journal./;
	if !qs
		return "-"
	endif
	string s = ""
	int used = 0
	int i = 0
	while i < qs.Length && used < 6
		Quest q = qs[i]
		if q != None
			string id = q.GetID()
			if id != ""
				used += 1
				int stage = q.GetCurrentStageID()
				int[] objs = PO3_SKSEFunctions.GetAllQuestObjectives(q)
				if objs
					int j = 0
					int taken = 0
					int scan = objs.Length
					if scan > 40
						scan = 40
					endif
					while j < scan && taken < 2
						int o = objs[j]
						if q.IsObjectiveDisplayed(o) && !q.IsObjectiveCompleted(o) && !q.IsObjectiveFailed(o)
							if s != ""
								s += ","
							endif
							s += id + ":" + stage + ":" + o + ".100"
							taken += 1
						endif
						j += 1
					endwhile
				endif
			endif
		endif
		i += 1
	endwhile
	if s == ""
		return "-"
	endif
	return s
EndFunction

bool Function HasActiveJournalQuest(Actor akNpc)
	Quest[] qs = PO3_SKSEFunctions.GetActiveAssociatedQuests(akNpc, true)
	if !qs
		return false
	endif
	int i = 0
	while i < qs.Length && i < 8
		if qs[i] != None && qs[i].IsActive()
			return true
		endif
		i += 1
	endwhile
	return false
EndFunction

bool Function PlayerIsBeast(Actor akPlayer)
	EnsureForms()
	Form r = akPlayer.GetRace() as Form
	if r == None
		return false
	endif
	if raceWolf != None && r == raceWolf
		return true
	endif
	if raceVamp != None && r == raceVamp
		return true
	endif
	return false
EndFunction

; ---------------------------------------------------------------------------
; PRE facts, the freeze rule, small helpers
; ---------------------------------------------------------------------------
bool Function IsCheckKind(string asKind)
	if asKind == "persuade" || asKind == "intimidate" || asKind == "bribe" || asKind == "pay"
		return true
	endif
	if asKind == "commit" || asKind == "deceive"
		return true
	endif
	return false
EndFunction

Function CapturePre()
	Actor player = Game.GetPlayer()
	preP = Game.QueryStat("Persuasions")
	preB = Game.QueryStat("Bribes")
	preI = Game.QueryStat("Intimidations")
	preGold = player.GetGoldAmount()
	preSp = PlayerSpeech()
	preTimer = LRG_DlgUI.ProgressTimerId()
	preSub = calSub ; the subtitle HarvestLine read this poll (her CHIM voice is no signal any more)
	if speaker != None
		preBribed = speaker.IsBribed()
		preIntim = speaker.IsIntimidated()
		preBamt = speaker.GetBribeAmount()
		preWis = speaker.WillIntimidateSucceed()
	endif
EndFunction

bool Function PreFactsMoved()
	if Game.QueryStat("Persuasions") != preP || Game.QueryStat("Bribes") != preB
		return true
	endif
	if Game.QueryStat("Intimidations") != preI
		return true
	endif
	if Game.GetPlayer().GetGoldAmount() != preGold
		return true
	endif
	if speaker != None
		if speaker.IsBribed() != preBribed || speaker.IsIntimidated() != preIntim
			return true
		endif
	endif
	return false
EndFunction

Function FreezeCapture()
	frzGold = Game.GetPlayer().GetGoldAmount()
	frzSp = PlayerSpeech()
EndFunction

bool Function FreezeMoved()
	return Game.GetPlayer().GetGoldAmount() != frzGold || PlayerSpeech() != frzSp
EndFunction

int Function PlayerSpeech()
	return Game.GetPlayer().GetActorValue("Speechcraft") as int
EndFunction

bool Function HasGoldTag()
	int i = 0
	while i < eHead
		if StringUtil.Find(eText[i], "gold") >= 0 || StringUtil.Find(eText[i], "septim") >= 0
			return true
		endif
		if StringUtil.Find(eText[i], "Gold") >= 0 || StringUtil.Find(eText[i], "Septim") >= 0
			return true
		endif
		i += 1
	endwhile
	return false
EndFunction

string Function PerkCsv()
	;/The speech-relevant perks and the Amulet of Articulation list, so the server can evaluate the
	 SAME conditions the engine's own persuade INFOs use. Vanilla Bribery is REQ_Speech_SilverTongue
	 in this load order and vanilla Persuasion is nulled - both are sent anyway, None-guarded./;
	float now = Utility.GetCurrentRealTime()
	if perkAt > 0.0 && now >= perkAt && (now - perkAt) < 30.0
		return perkCsv
	endif
	EnsureForms()
	perkAt = now
	Actor player = Game.GetPlayer()
	string s = ""
	if perkSilver != None && player.HasPerk(perkSilver)
		s = "silvertongue"
	endif
	if perkPers != None && player.HasPerk(perkPers)
		if s != ""
			s += ","
		endif
		s += "persuasion"
	endif
	if amuletList != None
		int n = amuletList.GetSize()
		if n > 8
			n = 8
		endif
		int i = 0
		bool worn = false
		while i < n && !worn
			Form f = amuletList.GetAt(i)
			if f != None && player.IsEquipped(f)
				worn = true
			endif
			i += 1
		endwhile
		if worn
			if s != ""
				s += ","
			endif
			s += "amulet"
		endif
	endif
	if s == ""
		s = "-"
	endif
	perkCsv = s
	return s
EndFunction

string Function DriftCsv(float[] afNow)
	string s = ""
	if afNow.Length < 5 || sgOpen.Length < 5
		return "0,0,0,0,0"
	endif
	int i = 0
	while i < 5
		if i > 0
			s += ","
		endif
		s += ((afNow[i] - sgOpen[i]) as int)
		i += 1
	endwhile
	return s
EndFunction

string Function CleanEntry(string asText)
	;/The wire rule of PROTOCOL 0: a value never contains ; | @ " or a newline. e= is the LAST key
	 and the server takes it raw, so only | ~ " and newlines are replaced - the text the dump has
	 to match entry for entry is otherwise untouched./;
	LRG_Main m = Main()
	string out = m.ReplaceChar(asText, "|", "/")
	out = m.ReplaceChar(out, "~", "-")
	out = m.ReplaceChar(out, "\"", "'")
	out = m.ReplaceChar(out, "\n", " ")
	return LRG_Profile.Clip(out, 140)
EndFunction

string Function WireKey(string asText)
	;/[v1.0 review / P1] The txt= of a pick is the server's lrgDlgSafePrefix of the WIRE text: CleanEntry
	 (| ~ " newline), then ; = @ -> a space, every run of spaces -> one, trimmed, the first 40
	 characters. The same steps here, so a line with a quote in its first 40 characters (the Legion and
	 Stormcloak oaths, the Frostfall password, "Dragonborn") is found on the list./;
	LRG_Main m = Main()
	string s = CleanEntry(asText)
	s = m.ReplaceChar(s, ";", " ")
	s = m.ReplaceChar(s, "=", " ")
	s = m.ReplaceChar(s, "@", " ")
	int at = StringUtil.Find(s, "  ")
	int guard = 0
	while at >= 0 && guard < 160
		if at == 0
			s = StringUtil.Substring(s, 1)
		else
			s = StringUtil.Substring(s, 0, at) + StringUtil.Substring(s, at + 1)
		endif
		at = StringUtil.Find(s, "  ")
		guard += 1
	endwhile
	if StringUtil.GetNthChar(s, 0) == " "
		s = StringUtil.Substring(s, 1) ; (" " alone -> "": a start past the end is empty)
	endif
	int n = StringUtil.GetLength(s)
	if n > 1 && StringUtil.GetNthChar(s, n - 1) == " "
		s = StringUtil.Substring(s, 0, n - 1) ; (never length 0: that would mean "to the end")
	endif
	return LRG_Profile.Clip(s, 40)
EndFunction

bool Function TxtMatch(string asRaw, string asTxt)
	;/Does the live entry asRaw carry the server's txt= prefix (P1)? The raw text first (most lines);
	 then, only when the first characters can still agree (a character the wire rewrites comes first,
	 or both start alike), the wire form - so a scan over a list stays cheap./;
	if StringUtil.Find(asRaw, asTxt) == 0
		return true
	endif
	string c = StringUtil.GetNthChar(asRaw, 0)
	if c != StringUtil.GetNthChar(asTxt, 0) && StringUtil.Find(" \"|~;=@\n", c) < 0
		return false
	endif
	return StringUtil.Find(WireKey(asRaw), asTxt) == 0
EndFunction

Function ReportOnce(string asResult)
	;/Exactly one funcret per x, and commandEndedForActor exactly once per x in total: the second
	 delivery route is dropped long before it reaches here.
	 [0.5.7] asResult is "OK: ..." or "Error: <technical reason>"; LRG_Main.ReportResult puts the
	 reason into words she can say (SayReason) and keeps the technical one for the log and err=./;
	if reqReported || reqCmd == ""
		return
	endif
	reqReported = true
	; [v1.0 review / model F5] every x answered goes into the ring: its late D2 twin is dropped, never
	; stamped again (after Finish it would re-open the conversation, after StopDriving answer twice)
	if reqX != "" && !XSeen(reqX)
		XPush(reqX)
	endif
	LRG_Main m = Main()
	if m
		m.ReportResult(reqNpc, reqCmd, reqParam, asResult)
	endif
EndFunction

Function ClearRequest(bool abHard)
	reqPickReady = false
	reqLeave = false
	reqShow = false
	reqDo = ""
	reqX = ""
	reqCid = ""
	reqTxt = ""
	reqKind = ""
	reqAsk = ""
	reqVt = ""
	reqSid = ""
	reqParam = ""
	reqCmd = ""
	reqNpc = ""
	reqRef = ""
	reqPos = -1
	reqIdx = -1
	reqGen = 0
	reqCost = 0
	reqAt = 0.0
	reqAdv = -1
	reqRearm = false
	clickTxt = ""
	reqReported = true
	if abHard
		reqDump = false
		reqWant = false
		openWanted = false
		openNpc = None
	endif
EndFunction

Function HoldListener()
	{[v1.0.1] While her list is on screen the talk key must reach HER. Read from CHIM's source
	 (Plugin/PlayerConversationRouter.cpp, Papyrus.cpp): the router only ever considers CHIM AGENTS,
	 so a speaker CHIM has not activated is invisible and the Narrator answers; with nobody under the
	 crosshair it falls back to the nearest AUTO-eligible agent, and "in a Skyrim scene" removes that
	 eligibility unless conf _restrict_onscene is 0. setDrivenByAIA is a TOGGLE for a manually
	 activated agent (a second call REMOVES her), so it is called only for a speaker CHIM does not know.}
	if speaker == None || dlgListenerHeld || quietArm || npcName == ""
		return
	endif
	LRG_Main m = Main()
	if m == None
		return
	endif
	LRG_OStim ost = m.GetOStim()
	if ost != None
		if ost.HeldListener() != None
			return
		endif
	endif
	;/CHIM's "NPC Scene Safety" (Behavior page; conf _restrict_onscene, ON by default): an actor inside a Skyrim
	 scene is never auto-eligible, so his words go to the Narrator - Arngeir's summons, Irileth at the door,
	 Balgruuf's court. The list is open on that actor, so the player IS talking to him: relax it once per game
	 session with the call CHIM's own MCM makes (f_Value 0 -> AllowActorsOnScene true)./;
	if (inScene || sjScene) && !sceneSafetyRelaxed
		AIAgentFunctions.setConf("_restrict_onscene", 0.0, 0, "")
		sceneSafetyRelaxed = true
		m.LogC(sessCid, "CHIM scene safety relaxed (_restrict_onscene 0): " + npcName + "'s list is open inside a scene", npcName)
	endif
	dlgListenerHeld = true
	if AIAgentFunctions.getAgentByName(npcName) != None
		m.LogV(sessCid, "listener: " + npcName + " is already a CHIM agent (never toggled)", npcName)
		return
	endif
	AIAgentFunctions.setDrivenByAIA(speaker, false)
	m.LogC(sessCid, "listener: " + npcName + " activated as a CHIM agent (her list is open; she was none)", npcName)
EndFunction

Function ReleaseHeldListener(string asWhy)
	{[v1.0.1] The activation stays (she is an agent now, as any NPC the player talked to); only the
	 session's own flag is cleared. setDrivenByAI() is NOT called: it would activate whoever is under
	 the crosshair, which is nobody's wish at a list close.}
	if !dlgListenerHeld
		return
	endif
	dlgListenerHeld = false
	LRG_Main m = Main()
	if m != None
		m.LogV(sessCid, "listener hold cleared (" + asWhy + ")", npcName)
	endif
EndFunction

Function ClearSession()
	ReleaseHeldListener("the list closed")
	sid = ""
	gen = 0
	layer = 0
	speaker = None
	npcName = ""
	sessCid = ""
	crit = 0
	inScene = false
	sjScene = false
	sessSq = "-"
	sessSqj = 0
	sessScene = None
	quietArm = false
	openAsk = ""
	glueOpened = false
	anyClick = false
	pendingLayer = false
	sentThisGen = false
	held = false
	ourClick = false
	hcGen = -1
	layerGen = 0
	clickFails = 0
	clickAuto = false
	speechAt = 0.0
	npcSpeechAt = 0.0
	herVoiceAt = 0.0
	eHead = 0
	tCount = 0
	nTotal = 0
	sig = ""
	lastSig = ""
	closeAsked = false
	sessQ = "-"
	sessSvc = "-"
	layerSvc = "-"
	svcPriced = 0
	svcShort = 0
	lastN = 0
	calSub = ""
	;/[0.5.1 pt9 go-live, G2] THE HARD FLAGS DIE WITH THEIR SESSION. ClearRequest(false) leaves them
	 standing, so a dump press or a stale re-match that arrived too late to be polled would fire on the
	 NEXT session - minutes later, a different NPC. openWanted is deliberately NOT here: it belongs to
	 the pump, not to this session, and DoOpen() clears it itself./;
	reqDump = false
	reqWant = false
	qX = ""
	qNpc = ""
	qCmd = ""
	qParam = ""
EndFunction

string Function NewSid()
	LRG_Main m = Main()
	int tag = 0
	if m
		tag = m.SessionTag()
	endif
	return "d" + tag + "-" + (Utility.GetCurrentRealTime() as int)
EndFunction

bool Function XSeen(string asX)
	int i = 0
	while i < 8
		if xRing[i] == asX
			return true
		endif
		i += 1
	endwhile
	return false
EndFunction

Function XPush(string asX)
	if asX == ""
		return
	endif
	xRing[xSlot] = asX
	xSlot += 1
	if xSlot >= 8
		xSlot = 0
	endif
EndFunction

bool Function RefMatches(string asRef, Actor akNpc)
	;/Speaker binding is by reference FormID end to end. Phase 1 sends decimal ids and Phase 2 hex,
	 so both spellings are accepted; an empty ref is refused (a missing key fails closed)./;
	if asRef == "" || akNpc == None
		return false
	endif
	return ParseId(asRef) == akNpc.GetFormID()
EndFunction

int Function ParseId(string asText)
	{Accepts 0x-prefixed hex, bare hex and plain decimal; returns the int FormID.}
	string s = asText
	bool hex = false
	if StringUtil.Find(s, "0x") == 0 || StringUtil.Find(s, "0X") == 0
		s = StringUtil.Substring(s, 2)
		hex = true
	endif
	int n = StringUtil.GetLength(s)
	if !hex
		int k = 0
		while k < n && !hex
			if StringUtil.Find("abcdefABCDEF", StringUtil.GetNthChar(s, k)) >= 0
				hex = true
			endif
			k += 1
		endwhile
	endif
	if !hex
		return s as int
	endif
	int v = 0
	int i = 0
	while i < n && i < 8
		string ch = StringUtil.GetNthChar(s, i)
		int d = StringUtil.Find("0123456789abcdef", ch)
		if d < 0
			d = StringUtil.Find("0123456789ABCDEF", ch)
		endif
		if d >= 0
			v = v * 16 + d
		endif
		i += 1
	endwhile
	return v
EndFunction

string Function Hex8(int aiValue)
	{Eight upper-case hex digits, no prefix. Papyrus has no hex formatter, and the sign trouble of
	 an int FormID must never reach the wire.}
	string digits = "0123456789ABCDEF"
	string out = ""
	int i = 0
	while i < 8
		int nib = Math.LogicalAnd(Math.RightShift(aiValue, (7 - i) * 4), 15)
		out += StringUtil.Substring(digits, nib, 1)
		i += 1
	endwhile
	return out
EndFunction

int Function ToInt(string asText, int aiDefault)
	if asText == ""
		return aiDefault
	endif
	return asText as int
EndFunction

int Function I(bool abValue)
	return LRG_Profile.B2I(abValue)
EndFunction

Function LogDiagnostics()
	{Writes the counters and the live calibration to the server log. The topic-dump hotkey calls
	 this, so the owner has one press that produces the whole picture.}
	LRG_Main m = Main()
	if !m
		return
	endif
	m.LogC("", "dlg diagnostics opens_without_match=" + dOpensNoMatch + " scene_refusals=" + dSceneRefusals \
		+ " layers_lost=" + dLayersLost + " stopped_driving=" + dStops + " count_mode_failures=" + dCountFails \
		+ " click_aborts=" + dClickAborts + " awards=" + dAwards + " drift_sessions=" + dDrifts \
		+ " readmode=" + readMode + " state=" + dlgState + " held=" + I(held), "")
	SelfTest()
EndFunction
