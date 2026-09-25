Scriptname LRG_OStim extends Quest
;/LoreRim Glue - everything that touches OStim lives in this one script, so the rest of
 the mod still loads if OStim is removed. Attached to the same quest as LRG_Main.
 Wire contract: glue/PROTOCOL.md v0.3. This script is the whole "menuless OStim" verb set:
   start (optionally on furniture), goto, faster / slower / speed, stop, hold / release,
   climax, pullout, winddown, furniture, lead, undress / dress (who, part).

 Verified against the installed OStim 7.5.1 sources (Scripts\Source\<file>:<line>):
   OThreadBuilder Create:46 SetFurniture:63 SetStartingAnimation:83 NoPostDialogue:166
     NoUndressing:176 NoFurniture:189 Start:216
   OThread IsRunning:46 Stop:53 GetScene:86 NavigateTo:96 WarpTo:119 AutoTransition:141
     AutoTransitionForActor:152 GetSpeed:161 SetSpeed:170 GetActors:198 GetActorPosition:218
     StallClimax:234 PermitClimax:242 IsClimaxStalled:251 GetFurnitureType:277
     ChangeFurniture:288 IsInAutoMode:305 StartAutoMode:312 StopAutoMode:321
   OActor Climax:99 GetTimesClimaxed:108 Undress:200 Redress:207 UndressPartial:216
     RedressPartial:225 IsInOStim:448 VerifyActors:468
   OFurniture GetFurnitureType:16 IsChildOf:28 FindFurniture:41
   OActorUtil / OLibrary / OMetadata / OJSON as in v1
   events ostim_thread_start / _scenechanged / _speedchanged / _end, ostim_actor_orgasm,
     ostim_furniturechanged (list of mod events.txt)
 Rules baked in:
   - the player thread is always ThreadID 0; events of other threads are ignored
   - OThreadBuilder.Start is asynchronous: nothing queries the thread until ostim_thread_start
   - scenechanged + speedchanged both fire on a scene change: state pushes are debounced
   - NavigateTo warps when no route exists, so we only navigate to confirmed-routable targets
     (an explicit, faded WarpTo is used only when the server asks for it AND the MCM allows it)
   - the player is always a participant; NPC-only scenes are never started here
   - "stop" ends the scene at once, from every state (dry-run, wind-down, transition)
   - OThreadBuilder.NoAutoMode is NOT used (it would also block a later StartAutoMode); auto
     mode is stopped at ostim_thread_start of a glue-started thread instead
   - OStim's furniture message boxes never appear: a start always passes SetFurniture or
     NoFurniture explicitly
   - this script has NO OnUpdate: the quest form's single update timer belongs to LRG_Main,
     which calls Tick() here (see LRG_Main.RequestTick)
   - PLAYTEST CRASH 2026-09-21: OStim's ThreadActor constructor calls the game's UnequipItem
     native directly (GameActor::unequipWeaponry: right hand, left hand, ammo) and crashed the
     game on the NPC. CmdStart therefore empties hands + ammo of both actors first, through the
     normal Papyrus calls, so OStim finds nothing to unequip; everything is re-equipped when
     the thread ends. v0.1.1 unequipped WITHOUT the prevent-equip flag and the NPC's AI put the
     dagger back inside the second between the check and OStim's (asynchronous) actor
     construction - it crashed again. v0.2.0: prevent-equip, a settle loop, and a guard that
     keeps both pairs of hands empty until OStim reports the thread. See PrepareWeapons.
   - PLAYTEST CRASH 4, 2026-09-21 (mid-scene): the same native, reached from a SECOND place.
     OStim's own mid-scene undressing (OStimNG Thread.cpp ChangeNode) runs removeWeapons() every
     time the scene moves to a node that strips an actor - and that call is NOT gated by the
     "Remove Weapons at Start" option the glue already turns off, and NOT by the thread's
     NoUndressing flag either (the flag only stops undress(), the weapon call right next to it
     still runs). Its only gates are OStim's two MCM options "Fully undress mid Scene"
     (OStimUndressMidScene, OStim.esp 0xDAC) and "Partial Undressing" (OStimPartialUndressing,
     0xDAD). A glue-started scene therefore switches BOTH off for its duration (HoldOStimGates)
     and puts the owner's values back at the end (ReleaseOStimGates), and the glue undresses the
     actors itself at the nodes where OStim would have (GlueUndressNode). See also
     PLAYTEST4_NOTES.md, "Crash 4 (mid-scene) and the complete fix".
   - v0.3 (playtest 6): three additive readings of the wire, each with a neutral default, so an old
     server (which sends none of them) gets exactly today's behaviour.
       hold=<0..600>  no scene-lead turn for the next n seconds (the player is steering)
       wait=begin|end hold the OStim call back until her spoken line has started / finished, so a
                      change SHE chose is never seen before it is heard (G3a / R10). Both budgets
                      end in "do it anyway", so nothing is ever lost. The waiting happens in Tick:
                      CmdStart already blocks its own thread ~6.5 s in SettleWeapons / GuardStart,
                      and 12 s more on the bridge call stack would invite a dumped stack. The
                      blocking checks still run at COMMAND time, so a refusal is immediate; only
                      the OStim call itself is parked, and ReportResult follows the real call.
       nowarp=1       never fade-jump for this move (a move she chose herself), even where the MCM
                      would allow it. A separate key, NOT a re-reading of warp=0.
     Plus setDrivenByAIA, so the player keeps talking to the partner while a scene runs (during a
     scene nobody is under the crosshair and CHIM routed five of thirteen player utterances of
     playtest 6 to the Narrator); released again with setDrivenByAI() when the scene ends.
   - v0.3 review pass (same day, before playtest 7). Six things the first v0.3 build got wrong:
       * AnnounceState now looks 3 s BACK from the command as well as forward. CHIM's delivery
         order (speech line vs. ExtCmd dispatch) is not guaranteed, and without the look-back a
         command that lands a moment after her TTS burned the whole budget as dead air.
       * ThreadTick refreshes the snapshot (Main().MaybeSnapshot, throttled to one per 10 s):
         after ~90 s of her silence the server's staleness rail turned every spoken request except
         "stop" into a blocked turn and the request was lost. The rail itself is untouched.
       * CmdLead only clears the lead hold for a command with NO hold= (the real "you lead"), and
         only cancels a wind-down when the lead really changes: the §9 hold carrier names the
         current leader and must do neither.
       * a start parked for her line is abandoned when a FOREIGN thread starts inside the wait.
       * the clothing verb answers "not possible in this position" when it touched nothing.
       * CmdControl applies hold= only after its refusals, and ev=end carries sess=.
   - v0.3.1 (playtest 7). Four game-side additions, all additive on the wire:
       * THE OUTRO HOLD (R3). A scene used to end with three releases in a row (setAnimationBusy(0),
         setDrivenByAI(), OStim's own) and nothing holding her, so her AI package took over inside
         the same second and she walked off. FinishThread now keeps her on the spot with
         Actor.SetDontMove(true) BEFORE anything is released, asks CHIM for one closing line
         (requestMessageForActor "outro", type lrg_scenetalk) and lets her go again the moment that
         line is out (the existing say-first machinery, with its own longer settle) or at
         fOutroHold:SceneTalk, default 25 s. A stuck NPC is the worst defect this can have, so the
         hold is released through ONE function reached from every exit: her line, the timeout, a
         game load, combat, distance, another cell, a new scene, death / unload, the stop hotkey,
         the kill switch, the setting going to 0, and a self-heal in the regular tick.
       * ev=end carries dur=<seconds the scene ran> and how=<finished|stopped|interrupted|lost>.
         Both need a real start stamp, which did not exist: sceneStartedAt.
       * StartIntimacy after=<scene id>: the position the PLAYER asked for when OStim could not
         start in it. A few seconds after the thread is up the glue moves there, warping when
         bAllowWarp allows it - this is the player's own request, so the nowarp rules for HER moves
         do not apply. An old server never sends the key and nothing changes.
       * TWO COMMANDS IN ONE REPLY (a compound request: "take my clothes off and then missionary").
         The second used to arrive while the first was still being carried out and was answered with
         "still moving into the previous position" / "a scene is just starting" and lost. It is now
         held in ONE queue slot and retried once a second until the first is done (fQueueWait,
         default 30 s), then answered with the same reason it would have got at once.
   - v0.3.1 fix pass (the playtest-7 audit). No new wire keys; behaviour only:
       * the outro timeout can no longer cut across her: while she is audibly speaking the deadline
         moves on in 5 s steps, bounded by the self-heal at twice the budget + 30 s.
       * how=lost gets no hold (the server writes no outro ticket for it, so she would be held for
         an ordinary line), and an initiative tick can no longer fire during a hold.
       * the queue budget is 30 s (a navigation alone is allowed 25) and it is not given up while
         the command before it is demonstrably still in flight - up to twice the budget.
       * the transient "still moving into the previous position" is tested LAST, so a command that
         must be refused anyway never occupies the single queue slot.
       * the deferred command's resume is keyed on command+parameter, not on the cid. /;

bool ostimPresent = false
bool booted = false ; [0.5.5] true once a boot has finished (OnLrgBoot)
bool smiPresent = false ; Survival Mode Improved (cold exemption hook)

Actor partner = None
string partnerName = ""
bool starting = false
float startDeadline = 0.0
string startCid = ""
string startCmd = ""
string startParam = ""
bool startedByGlue = false
bool abortStart = false ; "stop" arrived while the thread was still being built
bool finishing = false

bool dirty = false
string dirtyEvent = ""
string lastSentKey = ""
float stateRecheckAt = 0.0 ; one more state push a few seconds after clothes changed

; a scene change the glue itself asked for is on its way (goto, warp, furniture, pullout)
bool navInFlight = false
float navDeadline = 0.0
string navTarget = ""
string lastSceneId = ""   ; last scene id OStim reported (a repeated event must not look like a change)
float goneSince = 0.0     ; Tick saw the thread gone although no end event arrived yet
string lastPosScene = ""  ; last NON-transition scene (what the player is actually seeing)
string prevPosScene = ""  ; the one before that: lrg_scene prev= on ev=change (v0.3)
float sceneEndedAt = 0.0  ; when a glue-run thread ended (a command from that turn is stale)

; --- v0.3 -------------------------------------------------------------------------------------
; the player steered: no scene-lead turn before this real-time stamp (hold=<n> from the server,
; and one lead interval whenever CHIM voices a line of the player's). Monotonic: a shorter hold
; never shortens a longer one. Survives MarkDirty, cleared by ResetState / Maintenance.
float leadHoldUntil = 0.0
; the partner's CHIM speech clock, fed by LRG_Main (CHIM_SpeechStarted fires per SENTENCE, so
; "she has started" only counts when it arrived AFTER the command did)
float partnerSpeakStart = 0.0
float partnerSpeakStop = 0.0
; a start parked until her announcing line is out (wait=end)
bool pendingStart = false
float pendingStartAt = 0.0
float pendingStartUntil = 0.0
bool pendingStartEnd = false
string pendingStartScene = ""
string pendingStartFurnType = ""
ObjectReference pendingStartFurn = None
; ONE parked verb at a time (goto | furniture | clothing), waiting for the start of her line
string pendingVerb = ""
string pendingCmd = ""
string pendingNpcName = ""
string pendingParam = ""
string pendingCid = ""
float pendingAt = 0.0
float pendingUntil = 0.0
bool pendingEnd = false
bool pendingInScene = false
string pendingScene = ""       ; the scene that was running when the verb was parked
bool pendingWarp = false
Actor pendingActor = None
ObjectReference pendingFurnRef = None
; listener routing (setDrivenByAIA): forced for the length of a scene, released afterwards
bool listenerForced = false
float listenerAt = 0.0
; [0.4] THE LISTENER HOLD (owner complaint 1: "at the end of the sex scene it will leave u in the ostim
; director mode"). A scene used to END by handing CHIM's listener back on the line after the outro hold
; was armed, and right then the crosshair is empty (the camera has just been given back wherever OStim
; left it and she is standing still under SetDontMove), so CHIM's router falls through to
; no_eligible_npc and the player's next lines become narrator_inputtext - which the glue then answers
; with silence, because a narrator request is not player speech. Playtest 8 has three of them in
; thirteen minutes, every one naming her. So the listener is now the LAST thing to go, not the first.
Actor listenerActor = None
string listenerName = ""
float listenerUntil = 0.0
; [0.4 / F3] the evidence-gated camera / controls self-repair window after a scene
float ctlFixUntil = 0.0

; --- v0.3.1 ------------------------------------------------------------------------------------
; when the running thread began (real time). dur= on ev=end, and the "did the scene really run"
; test for the outro. Only sceneEndedAt existed before.
float sceneStartedAt = 0.0
; how the scene ended: finished | stopped | interrupted | lost. Set by whoever causes the end,
; read once by FinishThread and cleared there.
string endHow = ""
; THE OUTRO HOLD. outroActor is deliberately its own field: ResetState clears partner /
; partnerName a moment after the hold begins. Everything here survives a save, which is why
; Maintenance releases first and unconditionally - SetDontMove is written into the save.
Actor outroActor = None
string outroName = ""
string outroCid = ""
bool outroActive = false
bool outroHeld = false     ; SetDontMove(true) is really in force on outroActor
float outroStart = 0.0     ; when the scene ended
float outroAskedAt = 0.0   ; when the closing line was asked for (0 = not asked yet)
float outroUntil = 0.0     ; hard release time
float outroSpeakStart = 0.0 ; her CHIM speech clock during the hold (per SENTENCE, like the scene one)
float outroSpeakStop = 0.0
float outroDoneAt = 0.0    ; [0.4] when she FINISHED her goodbye (0 = she never did): the listener grace
; StartIntimacy after=<id>: move there a few seconds after the thread is up (the player's request)
string afterScene = ""
float afterAt = 0.0
float afterUntil = 0.0
; ONE deferred command (the second half of a compound request), retried until the first is done
string defCmd = ""
string defParam = ""
string defNpc = ""
string defCid = ""
string defWhy = ""
float defNextAt = 0.0
float defUntil = 0.0
; the hard ceiling (2x the budget). The ordinary deadline may be extended while the command before
; this one is demonstrably still in flight; this one never is.
float defHardUntil = 0.0
; the command being retried right now: a re-defer keeps its deadline. Keyed on command+parameter,
; NOT on the cid - a malformed command without a cid used to start a fresh budget on every retry
; and could then never expire.
string defResumeKey = ""
float defResumeUntil = 0.0
float defResumeHard = 0.0

float lastTalkTime = 0.0
float lastActivity = 0.0 ; last scene change / command / spoken line (scene-lead timer)
float lastThreadTick = 0.0
float lastColdTime = 0.0

; who drives the scene: npc (lead ticks on) | player (no lead ticks) | auto (OStim auto mode)
string leader = "npc"
bool lastAuto = false

; wind-down (its own verb; "stop" still ends at once)
bool windDown = false
bool wdWaitLanding = false
bool wdSlowAgain = false
float wdLinger = 20.0
float wdStopAt = 0.0
string wdCid = ""

; furniture in reach (cached per cell + player position)
Cell nfCell = None
float nfX = 0.0
float nfY = 0.0
float nfTime = 0.0
string nfList = ""
ObjectReference nfBed = None
string sceneNearf = "" ; lrg_scene copy: refreshed only at thread start and after a furniture change

; rented bed (inns only, cached per cell)
Cell prCell = None
float prTime = 0.0
int prVal = 0
bool prBusy = false
float prBusyTime = 0.0

; hands + ammo emptied by CmdStart, index 0 = player, 1 = npc
Actor[] prepActor
Form[] prepRight
Form[] prepLeft
Form[] prepAmmo
; anything an actor pulled out AFTER the first pass (a second weapon, a torch): 4 slots per
; actor, block aiIdx * 4. Everything in here was unequipped with the prevent-equip flag, so it
; MUST be given back (only EquipItem lifts that flag again).
Form[] prepExtra

; OStim's two mid-scene undressing options, held at 0 while a glue-started scene runs (they are
; the only gates on the weapon-removal native that crashed the game mid-scene - see the header).
; The owner's own values are remembered here and put back by ReleaseOStimGates.
; [0.4 / OWNER ADDENDA 6] PAID INTIMACY. The server sends pay=<n> on a start it has already bounded
; (never more than a figure the PLAYER said out loud, never more than his purse, never below her own
; floor). The game is what really moves the coin, and what it confirms with paid=<n> on every push of
; that scene is the only truth the halved relationship gain and her memory are built from.
; NO REFUND, EVER - the owner's rule: "gives nothing back if the player stops early". There is no code
; here for that, only this comment, so that nobody adds one later.
int startPayGold = 0   ; what THIS start is supposed to cost (cleared the moment it is charged)
int scenePaidGold = 0  ; what really left the purse for the scene that is running
Form goldForm = None   ; Gold001 (0x0000000F), fetched once

; [0.4] the door scan's cache (see DoorFacts): same cell, moved < 200 units, < 15 s old
Cell dfCell = None
float dfX = 0.0
float dfY = 0.0
float dfTime = 0.0
int dfVal = 0

bool gateHeld = false
int gatePrevMid = -1   ; OStimUndressMidScene   (OStim.esp 0xDAC) before the glue lowered it
int gatePrevPart = -1  ; OStimPartialUndressing (OStim.esp 0xDAD) before the glue lowered it
int glueStripped = 0   ; one bit per thread position the glue has already undressed in this scene
bool startNoUndress = false ; this scene was built with OThreadBuilder.NoUndressing

; clothes removed by the glue itself outside a scene (OStim's own undressing is OStim's)
Actor cloNpc = None
Form[] cloNpcItems
Form[] cloPlayerItems

; what both wore when a glue-started scene began: [0..7] player, [8..15] npc (slot order of SlotBit)
Form[] baseForms
Actor baseNpc = None
bool baseValid = false
int baseBitsNpc = 0 ; parts the NPC had something on (1 body, 2 head, 4 hands, 8 feet)

LRG_Main Function Main()
	return (self as Quest) as LRG_Main
EndFunction

Function EnsureArrays()
	if prepActor.Length != 2
		prepActor = new Actor[2]
		prepRight = new Form[2]
		prepLeft = new Form[2]
		prepAmmo = new Form[2]
	endif
	if prepExtra.Length != 8
		prepExtra = new Form[8] ; added in v0.2.0: a save from v0.1.x has none
	endif
	if cloNpcItems.Length != 8
		if cloNpcItems.Length > 0
			RedressRemembered("script upgrade") ; an older save used 5 slots
		endif
		cloNpcItems = new Form[8]
		cloPlayerItems = new Form[8]
	endif
	if baseForms.Length != 16
		baseForms = new Form[16]
		baseValid = false
	endif
EndFunction

; ---------------------------------------------------------------------------
Event OnInit()
	;/[0.5.5] Registers and returns - nothing else: a script is closed to every other stack until its
	 OnInit returns, and an OnInit that called into LRG_Main is what froze the 0.5.4 saves./;
	RegisterForModEvent("LRG_Boot", "OnLrgBoot")
EndEvent

Event OnLrgBoot(string asWho, int aiSess)
	;/[0.5.5] The boot entry point (LRG_Main boot step 5), on a stack of its own. LRG_Main no longer
	 calls Maintenance() itself, so this module can never park LRG_Main's stack. The same event
	 reaches every script on the form: only our own name is for us./;
	if asWho != "LRG_OStim"
		return
	endif
	Maintenance()
	booted = true
	LRG_Main m = Main()
	if m
		m.BootConfirm(0, aiSess)
	endif
EndEvent

Function Maintenance()
	; v0.3.1, FIRST of all and unconditionally: SetDontMove is written into the save, so a hold that
	; was live when the player saved (or when the game crashed) would outlive this session and freeze
	; her forever. Released before anything else looks at the state, and also when only the actor
	; survived (outroActive false, outroActor set) - see ReleaseOutroHold.
	ReleaseOutroHold("the game was loaded")
	; a queued command from the session before this one is simply forgotten: CHIM's own command
	; state went with the load, so a funcret for it would describe a command nobody is waiting for
	ClearDeferred()
	defResumeKey = ""
	defResumeUntil = 0.0
	defResumeHard = 0.0
	afterScene = ""
	afterAt = 0.0
	afterUntil = 0.0
	sceneStartedAt = 0.0
	endHow = ""
	; [0.4] One line per load when an ON-BY-DEFAULT 0.4 toggle reads off. A missing MCM ini key reads
	; as FALSE, so a settings.ini that has not got its new default lines yet silently turns the three
	; fixes of this round back into the old behaviour - and the owner would be left wondering why
	; nothing changed. This is the only way to see it without reading the ini.
	; Contract note: whoever adds these ids to MCM/Config/LoreRimGlue/config.json must add their
	; default lines to settings.ini in the same change (see research/pt8-intimacy-handoff.md).
	string offList = ""
	if !SettingOn("bPrivacyDoors:Intimacy")
		offList = "bPrivacyDoors"
	endif
	if !SettingOn("bPaidIntimacy:Intimacy")
		offList = offList + " bPaidIntimacy"
	endif
	if !SettingOn("bFixControlsAfterScene:SceneTalk")
		offList = offList + " bFixControlsAfterScene"
	endif
	if ListenerHoldSeconds() <= 0.0
		offList = offList + " fListenerHold=0"
	endif
	if offList != ""
		Main().LogE("", "NOTE these 0.4 settings read OFF (a missing settings.ini default line reads as 0):" + offList, "")
	endif
	ostimPresent = SKSE.GetPluginVersion("OStim") > 0
	; SurvivalModeImproved.esp is ESL-flagged on this list: GetModByName only knows full plugins
	smiPresent = Game.IsPluginInstalled("SurvivalModeImproved.esp")
	EnsureArrays()
	; Real-time stamps restart at 0 with every game launch: drop everything timed from the save,
	; or "starting" / the talk gap would stay blocked until the new session outlives the old one.
	starting = false
	abortStart = false
	finishing = false
	navInFlight = false
	windDown = false
	wdWaitLanding = false
	wdSlowAgain = false
	prBusy = false
	prCell = None
	prTime = 0.0
	nfCell = None
	nfTime = 0.0
	nfBed = None
	dfCell = None ; [0.4] the door cache is real-time stamped too
	dfTime = 0.0
	lastTalkTime = 0.0
	lastThreadTick = 0.0
	lastColdTime = 0.0
	stateRecheckAt = 0.0
	lastActivity = Utility.GetCurrentRealTime()
	; v0.3: everything timed or latched from the old session goes too
	leadHoldUntil = 0.0
	partnerSpeakStart = 0.0
	partnerSpeakStop = 0.0
	pendingStart = false
	pendingStartFurn = None
	ClearPendingVerb()
	; listenerForced is deliberately NOT cleared here: if the save was made during a scene, the
	; branches below either force the listener again (a thread is still running) or go through
	; ResetState, which releases it. Only its real-time stamp is dropped.
	listenerAt = 0.0
	; [0.4] the HOLD, on the other hand, is entirely a real-time affair and a clock that restarts at 0
	; would keep it alive for the rest of the session: it is dropped outright, and the branch below
	; either forces the listener again or releases it through ResetState.
	listenerUntil = 0.0
	listenerActor = None
	listenerName = ""
	outroDoneAt = 0.0
	ctlFixUntil = 0.0
	startPayGold = 0
	scenePaidGold = 0
	sceneEndedAt = 0.0
	if !ostimPresent
		Main().Log("OStim not detected - intimacy module idle")
		ResetState()
		RedressRemembered("game loaded")
		return
	endif
	RegisterForModEvent("ostim_thread_start", "OnOStimThreadStart")
	RegisterForModEvent("ostim_thread_scenechanged", "OnOStimSceneChanged")
	RegisterForModEvent("ostim_thread_speedchanged", "OnOStimSpeedChanged")
	RegisterForModEvent("ostim_actor_orgasm", "OnOStimActorOrgasm")
	RegisterForModEvent("ostim_furniturechanged", "OnOStimFurnitureChanged")
	RegisterForModEvent("ostim_thread_end", "OnOStimThreadEnd")
	; a save made mid-scene: resync
	if OThread.IsRunning(0)
		bool wasGlue = startedByGlue
		Actor oldPartner = partner
		string oldLeader = leader
		AdoptRunningThread()
		if wasGlue && partner == oldPartner
			startedByGlue = true ; still our thread: keep the end-of-scene redress
			if oldLeader != "" && !lastAuto
				leader = oldLeader ; a spoken "I'll lead" survives a save / load
			endif
		else
			baseValid = false
			; a scene that is not ours is running: OStim gets its own undressing options back
			ReleaseOStimGates("a scene the glue did not start is running after the load")
		endif
		; v0.3.1: the real start of this thread is in the session that is gone. The load counts as
		; the start, which is honest for dur= and means a scene resumed across a load is normally
		; too short for an outro - the safe direction.
		sceneStartedAt = Utility.GetCurrentRealTime()
		endHow = ""
		sceneNearf = ScanNearFurniture(Game.GetPlayer())
		; G4, path 3 (running branch): the server may still hold a scene row from the session
		; before this one. The first push after a load must therefore never be de-duplicated away
		; (the state can be byte-for-byte the same as the one in the row), and it carries sync=1 so
		; the server knows it is a statement about the load, not a change. An old server reads it
		; as an ordinary ev=change and simply refreshes its row - which is also correct.
		lastSentKey = ""
		lastTalkTime = Utility.GetCurrentRealTime() ; a load is no moment for an unprompted line
		lastPosScene = OThread.GetScene(0)
		prevPosScene = ""
		PushState("change", "sync=1")
		Main().RequestTick(5.0)
	else
		; G4, path 3 (no-thread branch): whatever the save says, nothing is running NOW. When the
		; partner survived the save we can say so by name, in the shape every server understands:
		; ev=end closes the row on an old server as well as on a new one. The nameless case (a
		; reload onto a save whose partner was somebody else) is covered by the forced crosshair
		; snapshot LRG_Main.Maintenance sends right after this call - it carries ostim=0 and sess=.
		if partnerName != ""
			if startCid == ""
				startCid = Main().NextCid()
			endif
			; dur= / how= are v0.3.1 and additive: a load is never a finished scene, so it says
			; interrupted and the server writes no outro ticket for it.
			Main().SendNpcMessage(Main().MSG_SCENE, "ev=end;npc=" + partnerName + ";cid=" + startCid + ";scene=;byglue=" + LRG_Profile.B2I(startedByGlue) + ";sess=" + Main().SessionTag() + ";dur=0;how=interrupted", partnerName)
			Main().LogC(startCid, "after the load no scene is running: the scene row for " + partnerName + " is closed", partnerName)
		endif
		ResetState()
		RestoreWeapons() ; a save made between "hands emptied" and the thread end
		RedressRemembered("game loaded") ; nobody is being watched after a load: nobody stays undressed
		baseValid = false
	endif
EndFunction

Function ResetState()
	; [0.4] ... UNLESS a listener hold was just armed. FinishThread arms it and calls ResetState two
	; lines later, the same ordering trap the outro hold already has a comment about below - and the
	; whole point of the hold is that it outlives the scene state.
	if listenerUntil <= 0.0
		ReleaseListener("the scene state was reset") ; CHIM talks to whoever is under the crosshair again
	endif
	scenePaidGold = 0
	startPayGold = 0
	if partnerName != ""
		AIAgentFunctions.setAnimationBusy(0, partnerName)
	endif
	partner = None
	partnerName = ""
	leadHoldUntil = 0.0
	pendingStart = false
	pendingStartFurn = None
	partnerSpeakStart = 0.0
	partnerSpeakStop = 0.0
	lastPosScene = ""
	prevPosScene = ""
	if pendingVerb != ""
		Main().Log("a parked " + pendingVerb + " was dropped: the scene state was reset")
		ClearPendingVerb()
	endif
	; v0.3.1: a queued second command is ANSWERED, never left open - CHIM waits for the funcret
	; before the NPC is free again. The outro fields are deliberately NOT touched here: FinishThread
	; begins the hold before it calls ResetState, and the hold outlives the scene state by design.
	DropDeferred("no scene is running")
	afterScene = ""
	afterAt = 0.0
	afterUntil = 0.0
	sceneStartedAt = 0.0
	starting = false
	abortStart = false
	startedByGlue = false
	startNoUndress = false
	dirty = false
	navInFlight = false
	windDown = false
	wdWaitLanding = false
	wdSlowAgain = false
	lastAuto = false
	leader = "npc"
	stateRecheckAt = 0.0
	sceneNearf = ""
	lastSentKey = ""
	lastSceneId = ""
	goneSince = 0.0
EndFunction

bool Function IsActorInOurScene(Actor akActor)
	if !ostimPresent
		return false
	endif
	return OActor.IsInOStim(akActor)
EndFunction

bool Function IsSceneActiveOrStarting()
	if starting
		return true
	endif
	if !ostimPresent
		return false
	endif
	return OThread.IsRunning(0)
EndFunction

bool Function IsOutroHolding()
	{v0.3.1 fix pass: true while she is being held for her goodbye. The scene is over by then, so
	 IsSceneActiveOrStarting() is already false and anything that must not talk over the outro has
	 to ask for this one too (LRG_Main.MaybeInitiative).}
	return outroActive
EndFunction

Function NoteActivity(Actor akNpc)
	{LRG_Main tells us when CHIM speech involves an NPC: the scene-lead timer starts over. [0.4] And
	 if it is the NPC the listener is being held for, the hold is pushed out: the conversation after
	 the scene is alive, which is the whole point of the hold.}
	float now = Utility.GetCurrentRealTime()
	if akNpc && akNpc == partner
		lastActivity = now
	endif
	if akNpc && akNpc == listenerActor
		lastActivity = now
		ExtendListener(now)
	endif
EndFunction

string Function DefaultLeader()
	if Main().SettingInt("iDefaultLead:Intimacy", 0) == 1
		return "player"
	endif
	return "npc"
EndFunction

bool Function IsPartnerName(string asNpcName)
	{The command must come from the NPC who is in the scene.}
	if partnerName == "" || asNpcName == partnerName
		return true
	endif
	Actor a = AIAgentFunctions.getAgentByName(asNpcName)
	return a != None && a == partner
EndFunction

; ---------------------------------------------------------------------------
; v0.3: the lead hold, the announce gate (wait=) and the listener lock.
; Everything in this section is a no-op when the server sends none of the new keys.
; ---------------------------------------------------------------------------
Function NotePartnerSpeech(Actor akNpc, bool abStarted, float afNow)
	{LRG_Main forwards every CHIM speech event here. CHIM sends CHIM_SpeechStarted once per
	 SENTENCE, so the announce gate never asks "is she talking" alone: it asks whether a line
	 started AFTER the command arrived, and (for wait=end) whether it has stopped since.}
	if akNpc == None
		return
	endif
	; v0.3.1: during the outro hold she is no longer "partner" (ResetState cleared it), so her
	; closing line is counted on its own clock. Same per-sentence rule, its own longer settle.
	if outroActive && akNpc == outroActor
		if abStarted
			outroSpeakStart = afNow
		else
			outroSpeakStop = afNow
		endif
		Main().RequestTick(0.5) ; look again soon: the hold may be over
	endif
	if akNpc != partner
		if pendingVerb == "" || akNpc != pendingActor
			return
		endif
	endif
	if abStarted
		partnerSpeakStart = afNow
	else
		partnerSpeakStop = afNow
	endif
EndFunction

Function NotePlayerSpeech(float afNow)
	{CHIM voiced a line of the PLAYER's: the player is steering this moment. Her scene-lead timer
	 starts over, exactly as it does when she speaks herself (NoteActivity). Belt and braces for
	 G2: it also works with a server that sends no hold= at all.}
	; [0.4] the player still talking after a scene is exactly what the listener hold exists for: it is
	; pushed out here FIRST, before the early return that only cares about a running scene.
	if listenerUntil > 0.0
		lastActivity = afNow
		ExtendListener(afNow)
	endif
	if partnerName == ""
		return
	endif
	lastActivity = afNow
EndFunction

Function ApplyHold(string asParam)
	{hold=<n>: "do not take a scene-lead turn for the next n seconds". Clamped 0..600; a missing
	 key means 0 = today's behaviour. Monotonic - a short hold never shortens a longer one, so the
	 server may skip a carrier without losing anything.}
	LRG_Main m = Main()
	string raw = m.ParamGet(asParam, "hold")
	if raw == ""
		return
	endif
	float secs = raw as float
	if secs <= 0.0 && raw != "0"
		secs = m.SettingFloat("fLeadHold:SceneTalk", 150.0) ; hold= without a usable number
	endif
	if secs < 0.0
		secs = 0.0
	elseif secs > 600.0
		secs = 600.0
	endif
	if secs <= 0.0
		return
	endif
	float until = Utility.GetCurrentRealTime() + secs
	if until > leadHoldUntil
		leadHoldUntil = until
	endif
EndFunction

float Function AnnounceBudget(string asParam)
	{How long this command may be held back for her spoken line. 0 = act now: no wait= key (an old
	 server), the master toggle is off, or the owner set the budget to 0.}
	LRG_Main m = Main()
	string w = m.ParamGet(asParam, "wait")
	if w != "begin" && w != "end"
		return 0.0
	endif
	if !m.SettingBool("bAnnounceWait:Intimacy", true)
		return 0.0
	endif
	float budget = m.SettingFloat("fSayFirstWait:SceneTalk", 6.0)
	if w == "end"
		; 20 s, not 12: her TTS has to be generated, downloaded and queued behind whatever the
		; speaker manager is already playing, and a budget that runs out means the fade cuts across
		; her - the one thing R10 / G3a exist to prevent. The wait costs nothing when she speaks in
		; time (LogWait reason=spoke ends it the moment her line is out).
		budget = m.SettingFloat("fSayFirstMaxWait:SceneTalk", 20.0)
	endif
	if budget < 0.0
		budget = 0.0
	elseif budget > 30.0
		budget = 30.0
	endif
	return budget
EndFunction

int Function AnnounceState(string asName, float afNow, float afSince, bool abNeedEnd)
	;/1 = her line is out, 0 = keep waiting.
	 wait=begin is satisfied by a line that started after the command OR by her already speaking.
	 wait=end is stricter, because the intro fade would cut across her: it needs a line that
	 STARTED after the command (never the "already talking" shortcut - that could be the previous
	 reply's last sentence), then a stop, then a quiet settle in which nothing started again.
	 LOOK-BACK (playtest 6 review): the order in which CHIM delivers a reply is not guaranteed -
	 the speech line and the command lines leave HerikaServer in the same answer, and in the game
	 the line goes through the DLL's speaker queue while the ExtCmd dispatch is immediate. A line
	 that began in the fLookBack seconds BEFORE the command arrived therefore counts as this
	 reply's line: without that, a command that lands a moment late burns the whole budget as dead
	 air and the fade can still cut across her. The window is far shorter than a CHIM round trip,
	 so it can never pick up the previous reply./;
	if asName == ""
		return 1 ; nobody to wait for
	endif
	float lookBack = afSince - 3.0
	bool started = partnerSpeakStart > 0.0 && partnerSpeakStart > lookBack
	if !abNeedEnd
		if started
			return 1
		endif
		if AIAgentFunctions.isActorTalking(asName) != 0
			return 1
		endif
		return 0
	endif
	if !started
		return 0
	endif
	if partnerSpeakStop <= partnerSpeakStart
		return 0 ; still speaking, or the next sentence has started: the settle is re-armed
	endif
	if AIAgentFunctions.isActorTalking(asName) != 0
		return 0
	endif
	float settle = Main().SettingFloat("fSayFirstSettle:SceneTalk", 1.0)
	if settle < 0.0
		settle = 0.0
	elseif settle > 3.0
		settle = 3.0
	endif
	if (afNow - partnerSpeakStop) < settle && afNow >= partnerSpeakStop
		return 0
	endif
	return 1
EndFunction

string Function Secs(float afSeconds)
	{One decimal, for the log ("waited=3.4").}
	if afSeconds < 0.0
		afSeconds = 0.0
	endif
	int tenths = (afSeconds * 10.0) as int
	return (tenths / 10) + "." + (tenths % 10)
EndFunction

Function LogWait(string asCid, string asWhat, float afWaited, int aiState)
	{One line per announce wait, so playtest 7 can measure whether her TTS ever arrives in time
	 (reason=spoke) or the budget always runs out (reason=timeout - then CHIM is not speaking
	 before the command, and setAnimationBusy may be the reason).}
	string reason = "timeout"
	if aiState == 1
		reason = "spoke"
	endif
	Main().LogC(asCid, "announce " + asWhat + " waited=" + Secs(afWaited) + " reason=" + reason, partnerName)
EndFunction

Function ForceListener(string asWhy)
	{The partner is the one the player is talking to. Kept as the old one-argument shape for every
	 in-scene caller; ForceListenerOn does the work.}
	ForceListenerOn(partner, partnerName, asWhy)
EndFunction

Function ForceListenerOn(Actor akWho, string asName, string asWhy)
	;/During a scene nobody is under the crosshair, so CHIM can route what the player says to the
	 Narrator - who has none of our actions and whose answer the partner never hears. This is
	 CHIM's own lever for "who am I talking to": AIAgentPapyrusFunctions:469 / :835 call exactly
	 this for the crosshair hotkey. salutation=false, so no greeting is asked for./;
	; [0.4] WHY THIS TAKES THE ACTOR AS AN ARGUMENT: the old ForceListener() returned early when
	; partner == None, and FinishThread calls ResetState (which clears partner / partnerName) two lines
	; after the hold is armed - so after a scene there would be nothing left to force. BeginOutroHold
	; keeps its own outroActor / outroName for exactly the same reason.
	if akWho == None || asName == ""
		return
	endif
	LRG_Main m = Main()
	if !m.IsEnabled() || !m.SettingBool("bForceListener:Intimacy", true)
		return
	endif
	listenerAt = Utility.GetCurrentRealTime()
	listenerForced = true
	listenerActor = akWho
	listenerName = asName
	AIAgentFunctions.setDrivenByAIA(akWho, false)
	m.LogV(startCid, "listener forced to " + asName + " (" + asWhy + ")", asName)
EndFunction

Function ReleaseListener(string asWhy)
	{Give CHIM its own routing back. The no-argument form is what CHIM itself uses when nothing is
	 under the crosshair, so this cannot leave the player glued to the ex-partner.}
	; [0.4] the hold's own state goes with it, always - including on a path that releases without the
	; hold ever having been armed, so a stale actor can never keep TickListener alive.
	listenerUntil = 0.0
	listenerActor = None
	string nm = listenerName
	listenerName = ""
	if !listenerForced
		return
	endif
	listenerForced = false
	listenerAt = 0.0
	AIAgentFunctions.setDrivenByAI()
	if nm == ""
		nm = partnerName
	endif
	Main().LogV(startCid, "listener released (" + asWhy + ")", nm)
EndFunction

float Function ListenerHoldSeconds()
	{How long after a scene the player keeps talking to HER rather than to whoever is under the
	 crosshair, 0..60. 0 = off, and a scene then ends exactly as it did in 0.3.1.}
	float v = Main().SettingFloat("fListenerHold:SceneTalk", 20.0)
	if v < 0.0
		return 0.0
	elseif v > 60.0
		return 60.0
	endif
	return v
EndFunction

Function ExtendListener(float afNow)
	{The conversation is alive: push the hold out again. Called from NoteActivity / NotePlayerSpeech,
	 which LRG_Main raises for every CHIM speech event on either side.}
	if listenerUntil <= 0.0 || listenerActor == None
		return
	endif
	float until = afNow + ListenerHoldSeconds()
	if until > listenerUntil
		listenerUntil = until
		Main().RequestTick(1.0)
	endif
EndFunction

Function TickListener(float afNow)
	;/[0.4] The bounded, self-healing clock of the listener hold. Called from Tick() right after the
	 outro block and cheap when no hold exists. Every exit goes through ReleaseListener./;
	; The exits, in the order they are tested: the switches; the self-heal (the real-time clock restarts
	; at 0 on every game launch, and a dumped stack can leave the hold behind); a new scene; she is
	; dead / disabled / unloaded / unconscious; combat or the player dead; distance; another cell; the
	; player turned to somebody else; her goodbye is finished and he let it lie; and finally the clock.
	; Worst case at the defaults: 2 x fListenerHold + fOutroHold + 30 s = 95 s.
	if listenerUntil <= 0.0
		return
	endif
	if listenerActor == None || listenerName == ""
		ReleaseListener("nobody to hold it for")
		return
	endif
	LRG_Main m = Main()
	float hold = ListenerHoldSeconds()
	if !m.IsEnabled() || !m.IsIntimacyEnabled() || !m.SettingBool("bForceListener:Intimacy", true) || hold <= 0.0
		ReleaseListener("the feature was switched off")
		return
	endif
	float ceiling = 2.0 * hold + OutroHoldSeconds() + 30.0
	if afNow > listenerUntil + ceiling || listenerUntil > afNow + ceiling
		ReleaseListener("self-heal")
		return
	endif
	if starting || pendingStart || (ostimPresent && !finishing && OThread.IsRunning(0))
		ReleaseListener("a new scene")
		return
	endif
	Actor player = Game.GetPlayer()
	if listenerActor.IsDead() || listenerActor.IsDisabled() || !listenerActor.Is3DLoaded() || listenerActor.IsUnconscious()
		ReleaseListener("she is gone")
		return
	endif
	if listenerActor.IsInCombat() || player.IsInCombat() || player.IsDead()
		ReleaseListener("combat")
		return
	endif
	float far = m.SettingFloat("fOutroFar:SceneTalk", 1500.0)
	if far < 200.0
		far = 1500.0 ; a missing MCM key reads as 0 and would release her at once
	elseif far > 4000.0
		far = 4000.0
	endif
	if listenerActor.GetDistance(player) > far
		ReleaseListener("the player walked away")
		return
	endif
	; cells are only compared where they mean something (two actors standing next to each other
	; outdoors are regularly in different exterior cells) - the same guarded test TickOutro uses
	if player.IsInInterior() || listenerActor.IsInInterior()
		if listenerActor.GetParentCell() != player.GetParentCell()
			ReleaseListener("another cell")
			return
		endif
	endif
	; the player turned to SOMEBODY ELSE and they are close enough to mean it: never steal another
	; NPC's conversation, whatever our clock says
	Actor look = Game.GetCurrentCrosshairRef() as Actor
	if look != None && look != listenerActor && look != player && look.GetDistance(player) < 400.0
		ReleaseListener("the player is looking at " + look.GetDisplayName())
		return
	endif
	; she is audibly talking, or stopped less than 4 s ago: never cut the hold across her. CHIM raises
	; SpeechStarted / SpeechStopped once per SENTENCE, so isActorTalking reads 0 in the gap between two.
	bool talking = AIAgentFunctions.isActorTalking(listenerName) != 0
	if !talking && outroSpeakStop > 0.0 && (afNow - outroSpeakStop) < 4.0 && afNow >= outroSpeakStop
		talking = true
	endif
	if talking
		if listenerUntil < afNow + 5.0
			listenerUntil = afNow + 5.0
		endif
		m.RequestTick(1.0)
		return
	endif
	; her goodbye is out and the player has said nothing since: let go after the grace period. Only when
	; she really finished it (outroDoneAt stays 0 for a hold that timed out or was released early).
	if !outroActive && outroDoneAt > 0.0
		float grace = m.SettingFloat("fListenerGrace:SceneTalk", 2.5)
		if grace < 0.0
			grace = 0.0
		elseif grace > 10.0
			grace = 10.0
		endif
		if afNow >= outroDoneAt + grace && afNow >= lastActivity + grace
			ReleaseListener("she has said her goodbye and the player let it lie")
			return
		endif
	endif
	if afNow >= listenerUntil
		ReleaseListener("the hold ran out")
		return
	endif
	m.RequestTick(1.0)
EndFunction

Function TickControls(float afNow)
	;/[0.4 / F3] Evidence-gated camera and controls self-repair, in the seconds after a scene.
	 It fires ONLY when the camera really is still OStim's free camera, or movement / look controls
	 really are off, and never while anything legitimate could own them. It logs every time it fires:
	 if the line never appears, the "director mode" complaint was the narrator half all along./;
	if ctlFixUntil <= 0.0
		return
	endif
	if afNow > ctlFixUntil || afNow < ctlFixUntil - 60.0
		ctlFixUntil = 0.0
		return
	endif
	LRG_Main m = Main()
	if !m.IsEnabled() || !m.SettingBool("bFixControlsAfterScene:SceneTalk", true)
		ctlFixUntil = 0.0
		return
	endif
	if starting || pendingStart || (ostimPresent && OThread.IsRunning(0))
		return ; a scene is running or being built: the camera is legitimately not the player's
	endif
	Actor player = Game.GetPlayer()
	; Utility.IsInMenuMode() rather than UI.IsMenuOpen("Dialogue Menu") / ("Console"): every UI.* call
	; in this mod belongs to LRG_DlgUI (PHASE2_DESIGN 1.2), and "any menu at all is up" is the stricter
	; and cheaper test anyway - a menu of any kind legitimately owns the controls.
	if player.GetCurrentScene() != None || Utility.IsInMenuMode() || DialogueBusy(m)
		; [0.4 fix pass, D10] ... and a live Dialogue Menu session, which is NOT menu mode (D-13)
		return ; a quest scene, a menu or a conversation owns the controls right now
	endif
	; Game.psc (installed): GetCameraState :389 with the state table at :370-388 (3 = free camera),
	; IsMovementControlsEnabled :167, IsLookingControlsEnabled :161, EnablePlayerControls :32,
	; ForceThirdPerson :93. Free camera is the one state a Papyrus caller cannot leave any other way.
	int cam = Game.GetCameraState()
	bool noMove = !Game.IsMovementControlsEnabled()
	bool noLook = !Game.IsLookingControlsEnabled()
	if cam != 3 && !noMove && !noLook
		return ; nothing is wrong: this is the normal case and costs three natives
	endif
	ctlFixUntil = 0.0
	Game.EnablePlayerControls()
	if cam == 3
		Game.ForceThirdPerson()
	endif
	m.LogC(startCid, "controls repaired after the scene (camera state " + cam + ", movement " + LRG_Profile.B2I(!noMove) + ", look " + LRG_Profile.B2I(!noLook) + ")", listenerName)
EndFunction

bool Function SettingOn(string asKey)
	{True when this ON-by-default toggle really reads on. Only used for the load-time note.}
	return Main().SettingBool(asKey, true)
EndFunction

Form Function Gold()
	{Gold001, 0x0000000F. Fetched once and kept: it is the same form in every load order.}
	if goldForm == None
		goldForm = Game.GetForm(0x0000000F)
	endif
	return goldForm
EndFunction

int Function DoorFacts(Actor akPlayer, bool abFresh = false)
	;/[0.4] LRG_Profile.DoorState with a cache, so the snapshot does not pay for a reference scan every
	 time. Cached per cell, per position (200 units - doors are room-scale) and for 15 s, which is much
	 shorter than the furniture cache: an NPC can open a door at any moment and this decides a privacy
	 gate. abFresh = true skips the cache outright, which is what the game-side re-check at scene start
	 uses - that one must never allow a scene on a stale reading.
	 The cache lives here rather than in LRG_Main.PlaceFacts (where the design put it) because that
	 file belongs to another lane this round; BuildSnapshot reaches it through LRG_Main.GetOStim()./;
	float now = Utility.GetCurrentRealTime()
	float dr = Main().SettingFloat("fDoorRadius:Intimacy", 250.0)
	if !abFresh && akPlayer.GetParentCell() == dfCell && (now - dfTime) < 15.0 && now >= dfTime
		float dx = akPlayer.GetPositionX() - dfX
		float dy = akPlayer.GetPositionY() - dfY
		if (dx * dx + dy * dy) <= 40000.0
			return dfVal
		endif
	endif
	dfCell = akPlayer.GetParentCell()
	dfX = akPlayer.GetPositionX()
	dfY = akPlayer.GetPositionY()
	dfTime = now
	dfVal = LRG_Profile.DoorState(akPlayer, dr)
	return dfVal
EndFunction

; ---------------------------------------------------------------------------
; v0.3.1: THE OUTRO HOLD (R3)
; A scene used to end with three releases in a row and nothing holding her, so her AI package took
; over inside the same second: "they say something short and just leave". Now she is kept on the
; spot until her closing line is out, and let go again by ONE function that every exit path calls.
; Why SetDontMove and nothing else: it is a single boolean with an exact inverse, it touches no AI
; package (an AddPackageOverride is written into the save and outlives the mod - the classic
; "NPC stuck forever" bug) and she can still turn, talk and play idles while it is on. Facing is
; CHIM's: it calls SetLookAt on both sides every time she speaks and clears it ~90 s after her last
; line, so the glue sets the look-at once and NEVER clears it.
; The one defect this must not have is a frozen NPC, so: the hold is released by her line, by the
; timeout, by a game load, by combat, by distance, by another cell, by a new scene, by her death or
; unload, by the stop hotkey, by the kill switch, by the setting going to 0, and by a self-heal in
; the regular tick - all through ReleaseOutroHold.
; ---------------------------------------------------------------------------
float Function OutroHoldSeconds()
	{How long she may be kept with the player after a scene, 0..60. 0 = the outro is off and a scene
	 ends exactly as it did in 0.3.}
	float v = Main().SettingFloat("fOutroHold:SceneTalk", 25.0)
	if v < 0.0
		return 0.0
	elseif v > 60.0
		return 60.0
	endif
	return v
EndFunction

Function BeginOutroHold(float afNow, string asHow, int aiDur)
	;/Called from FinishThread BEFORE the first release, so there is no window in which her package
	 can take over. Every reason not to hold her is checked here, once: the feature off, a dry run,
	 a scene that never really ran, an interrupted end (a load), combat, she is gone, a new scene is
	 already on its way. outroActor / outroName are own fields because ResetState clears partner and
	 partnerName a moment later./;
	LRG_Main m = Main()
	float budget = OutroHoldSeconds()
	; how=lost is here for the same reason as how=interrupted: the server writes no outro ticket for
	; either (lib/lrg_actions.php), so holding her would only buy an ordinary CHIM line - she would
	; stand still for the whole budget and then say something that is not a goodbye.
	if budget <= 0.0 || partner == None || partnerName == "" || asHow == "interrupted" || asHow == "lost"
		return
	endif
	if !m.IsIntimacyEnabled() || m.IsDryRun()
		return
	endif
	float minScene = m.SettingFloat("fOutroMinScene:SceneTalk", 45.0)
	if minScene < 0.0
		minScene = 0.0
	elseif minScene > 600.0
		minScene = 600.0
	endif
	if aiDur < (minScene as int)
		m.LogC(startCid, "no outro: the scene ran " + aiDur + " s, less than " + (minScene as int), partnerName)
		return
	endif
	Actor player = Game.GetPlayer()
	if partner.IsDead() || partner.IsDisabled() || !partner.Is3DLoaded() || partner.IsUnconscious()
		return
	endif
	if partner.IsInCombat() || player.IsInCombat()
		return
	endif
	if starting || pendingStart || OThread.IsRunning(0)
		return
	endif
	; [0.4.1] the two holds are mutually exclusive: the outro takes her over from here, with its own
	; budget and its own release. (The scene start already released it - this is the belt.)
	m.ReleaseConvHold("a scene is ending")
	outroActor = partner
	outroName = partnerName
	outroCid = startCid
	outroActive = true
	outroStart = afNow
	outroAskedAt = 0.0
	outroSpeakStart = 0.0
	outroSpeakStop = 0.0
	; provisional deadline: the request goes out after the redress wait and sets the real one. If it
	; never goes out (the server is gone, CHIM refuses, a stack dump), this still releases her.
	outroUntil = afNow + budget + 10.0
	outroHeld = true
	outroActor.SetDontMove(true)
	outroActor.SetLookAt(player, false) ; never cleared here: CHIM owns the look-at (EndDialogueClear)
	m.LogC(outroCid, "outro hold: " + outroName + " stays with you (" + asHow + ", the scene ran " + aiDur + " s, up to " + (budget as int) + " s)", outroName)
	m.RequestTick(0.5)
EndFunction

Function ReleaseOutroHold(string asWhy)
	{The ONLY way out of the hold. Safe to call at any time, from any path, twice in a row, and on a
	 hold that only the save remembers.}
	if !outroActive && outroActor == None && !outroHeld
		return
	endif
	Actor who = outroActor
	string nm = outroName
	string cid = outroCid
	bool wasHeld = outroHeld
	float now = Utility.GetCurrentRealTime()
	float held = 0.0
	if outroStart > 0.0 && now >= outroStart
		held = now - outroStart
	endif
	outroActive = false
	outroHeld = false
	outroActor = None
	outroName = ""
	outroCid = ""
	outroStart = 0.0
	outroAskedAt = 0.0
	outroUntil = 0.0
	outroSpeakStart = 0.0
	outroSpeakStop = 0.0
	if who != None && wasHeld
		who.SetDontMove(false)
		who.EvaluatePackage() ; her own package picks up cleanly instead of on the next AI poll
	endif
	; [0.4] the listener hold's "she has said her piece and he let it lie" exit needs to know that she
	; really FINISHED the goodbye. Every other release reason (timeout, a load, distance, combat, a new
	; scene) leaves this at 0, and then only the ordinary clock applies.
	if asWhy == "she has said her piece"
		outroDoneAt = now
	endif
	if nm != ""
		Main().LogC(cid, "outro released (" + asWhy + ") held=" + Secs(held), nm)
	endif
EndFunction

int Function OutroLineState(float afNow)
	;/1 = her closing line is out. The same shape as AnnounceState's wait=end - started after we
	 asked (3 s look-back, because CHIM's delivery order is not guaranteed), then stopped, then a
	 quiet settle in which nothing started again - but with its own, longer settle: the outro is two
	 to four sentences and CHIM raises SpeechStarted / SpeechStopped once per SENTENCE./;
	if outroName == "" || outroAskedAt <= 0.0
		return 0
	endif
	if outroSpeakStart <= 0.0 || outroSpeakStart < (outroAskedAt - 3.0)
		return 0
	endif
	if outroSpeakStop <= outroSpeakStart
		return 0
	endif
	if AIAgentFunctions.isActorTalking(outroName) != 0
		return 0
	endif
	float settle = Main().SettingFloat("fOutroSettle:SceneTalk", 2.5)
	if settle < 0.0
		settle = 0.0
	elseif settle > 6.0
		settle = 6.0
	endif
	if (afNow - outroSpeakStop) < settle && afNow >= outroSpeakStop
		return 0
	endif
	return 1
EndFunction

Function RequestOutroLine(float afNow)
	;/One closing line, asked for as an ordinary CHIM request of the existing type lrg_scenetalk with
	 the text "outro". A server that knows nothing about it finds no cue and CHIM falls back to its
	 own reply - i.e. today's behaviour, no regression. The snapshot is forced in FinishThread right
	 before this, so the server's freshness rail cannot turn the answer into a silent turn./;
	if !outroActive || outroActor == None || outroName == ""
		return
	endif
	LRG_Main m = Main()
	if !m.IsIntimacyEnabled()
		ReleaseOutroHold("the feature was switched off")
		return
	endif
	outroAskedAt = afNow
	outroUntil = afNow + OutroHoldSeconds()
	outroSpeakStart = 0.0
	outroSpeakStop = 0.0
	m.LogC(outroCid, "outro line requested from " + outroName, outroName)
	AIAgentFunctions.requestMessageForActor("outro", "lrg_scenetalk", outroName)
	m.RequestTick(0.5)
EndFunction

Function TickOutro(float afNow)
	{Runs first in every Tick while a hold exists. Cheap when there is none, and it is also the
	 self-heal: a hold that outlived its own budget, or one only the save remembers, ends here.}
	if !outroActive
		if outroActor != None || outroHeld
			ReleaseOutroHold("a hold was left over")
		endif
		return
	endif
	if outroActor == None
		ReleaseOutroHold("the partner is gone")
		return
	endif
	float budget = OutroHoldSeconds()
	if budget <= 0.0
		ReleaseOutroHold("the outro was switched off")
		return
	endif
	; the real-time clock restarts at 0 with every game launch, and a dumped stack can leave the
	; hold behind: whatever happens, it never lives longer than twice its own budget
	if afNow < outroStart || (afNow - outroStart) > (2.0 * budget + 30.0)
		ReleaseOutroHold("self-heal")
		return
	endif
	LRG_Main m = Main()
	if !m.IsEnabled() || !m.IsIntimacyEnabled()
		ReleaseOutroHold("the feature was switched off")
		return
	endif
	; !finishing: FinishThread is still running (it waits for OStim's own redressing), and a thread
	; that has not quite let go yet must not read as "a new scene" and end the hold before it began
	if starting || pendingStart || (ostimPresent && !finishing && OThread.IsRunning(0))
		ReleaseOutroHold("a new scene")
		return
	endif
	if outroActor.IsDead() || outroActor.IsDisabled() || !outroActor.Is3DLoaded() || outroActor.IsUnconscious()
		ReleaseOutroHold("she is gone")
		return
	endif
	Actor player = Game.GetPlayer()
	if outroActor.IsInCombat() || player.IsInCombat() || player.IsDead()
		ReleaseOutroHold("combat")
		return
	endif
	float far = m.SettingFloat("fOutroFar:SceneTalk", 1500.0)
	if far < 200.0
		far = 1500.0 ; a missing MCM key reads as 0 and would release her before she said a word
	elseif far > 4000.0
		far = 4000.0
	endif
	if outroActor.GetDistance(player) > far
		ReleaseOutroHold("the player walked away")
		return
	endif
	; cells are only compared where they mean something: two actors standing next to each other
	; outdoors are regularly in different exterior cells
	if player.IsInInterior() || outroActor.IsInInterior()
		if outroActor.GetParentCell() != player.GetParentCell()
			ReleaseOutroHold("another cell")
			return
		endif
	endif
	if afNow >= outroUntil
		; v0.3.1 fix pass: the budget may never cut across her own goodbye. Her line is two to four
		; sentences (the server caps it at 420 characters, every other line at 120) and CHIM needs
		; seconds to generate and queue the TTS, so the deadline can fall while she is mid-sentence -
		; exactly the "they say something short and just leave" complaint R3 exists to end. While she
		; is audibly talking the deadline moves on in small steps; the self-heal above (2x budget
		; + 30 s) still bounds the whole hold, so this can never freeze her.
		; CHIM raises SpeechStarted / SpeechStopped once per SENTENCE and isActorTalking reads 0 in
		; the gap between two of them, so a line that has started and not yet settled counts as
		; still speaking.
		bool talking = AIAgentFunctions.isActorTalking(outroName) != 0
		if !talking && outroSpeakStart > 0.0 && outroSpeakStop > 0.0
			if (afNow - outroSpeakStop) < 4.0 && afNow >= outroSpeakStop
				talking = true
			endif
		endif
		if talking
			outroUntil = afNow + 5.0
			m.RequestTick(0.5)
			return
		endif
		ReleaseOutroHold("timeout")
		return
	endif
	if OutroLineState(afNow) == 1
		ReleaseOutroHold("she has said her piece")
		return
	endif
	m.RequestTick(0.5)
EndFunction

; ---------------------------------------------------------------------------
; v0.3.1: ONE deferred command (w2 - the second half of a compound request)
; "take my clothes off and then let's do missionary" arrives as TWO ordinary commands in the same
; reply, each with its own cid. The second used to land while the first was still being carried out
; (a parked verb waiting for her line, a navigation in flight, a scene still being built) and was
; answered "still moving into the previous position" / "a scene is just starting" and lost. It is
; now held in one slot and retried once a second until the first is done. Nothing is ever left
; open: at the deadline it is answered with exactly the reason it would have got at once.
; ---------------------------------------------------------------------------
Function ClearDeferred()
	defCmd = ""
	defParam = ""
	defNpc = ""
	defCid = ""
	defWhy = ""
	defNextAt = 0.0
	defUntil = 0.0
	defHardUntil = 0.0
EndFunction

Function DropDeferred(string asError)
	{The queued command is answered, never left open - CHIM waits for the funcret before the NPC is
	 free again. asError must be one of the closed list in PROTOCOL 1.6.}
	if defCmd == ""
		return
	endif
	string cmd = defCmd
	string param = defParam
	string npc = defNpc
	string cid = defCid
	ClearDeferred() ; cleared first: nothing below may find the queue again
	Main().LogC(cid, "the queued " + cmd + " was dropped: " + asError, npc)
	Main().ReportResult(npc, cmd, param, "Error: " + asError)
EndFunction

bool Function DeferCommand(string asNpcName, string asCommand, string asParam, string asCid, string asWhy)
	;/true = this command is now queued behind the one before it and will be tried again. Only ever
	 called where the refusal is TRANSIENT (something of ours is still running); a real refusal -
	 not authorised, adults only, a scene with someone else, nothing installed - is answered at once
	 as it always was. One slot only: a third command in the same reply is refused as before./;
	if defCmd != ""
		return false
	endif
	LRG_Main m = Main()
	; 30, not 20: a missing MCM key reads as 0 and lands on this fallback, and 20 is BELOW the game's
	; own 25 s navigation deadline (GoToScene) - the exact mismatch that dropped the second half of a
	; compound request while the first half was still legitimately in flight. settings.ini ships 30 too.
	float budget = m.SettingFloat("fQueueWait:Intimacy", 30.0)
	if budget < 0.0
		budget = 0.0
	elseif budget > 60.0
		budget = 60.0
	endif
	if budget <= 0.0
		return false ; the owner switched queueing off: exactly 0.3's behaviour
	endif
	float now = Utility.GetCurrentRealTime()
	float until = now + budget
	float hard = now + (2.0 * budget) + 10.0
	; v0.3.1 fix pass: the resume is keyed on command+parameter, not on the cid. With an empty cid
	; the old test never matched, every retry started a fresh budget and the queued command could
	; never expire - its funcret would then arrive arbitrarily late (CHIM holds the NPC until it).
	if defResumeUntil > 0.0 && defResumeKey == (asCommand + "|" + asParam)
		until = defResumeUntil ; a retry of the same command keeps its ORIGINAL deadline
		hard = defResumeHard
	endif
	if now >= until
		return false
	endif
	if hard < until
		hard = until ; a save from an older build carries no ceiling: the ordinary deadline stands
	endif
	defCmd = asCommand
	defParam = asParam
	defNpc = asNpcName
	defCid = asCid
	defWhy = asWhy
	defUntil = until
	defHardUntil = hard
	defNextAt = now + 1.0
	m.LogC(asCid, "queued behind the command before it (" + asWhy + ")", asNpcName)
	m.RequestTick(1.0)
	return true
EndFunction

Function TickDeferred(float afNow)
	{Retries the queued command once a second until the one before it is done, then answers it with
	 the reason it would have got at once.}
	if defCmd == ""
		return
	endif
	if afNow < defNextAt
		Main().RequestTick(defNextAt - afNow)
		return
	endif
	if afNow > defUntil || afNow < (defUntil - 90.0)
		; v0.3.1 fix pass: do not give up while the command BEFORE this one is demonstrably still
		; being carried out. A navigation is allowed 25 s (GoToScene) and a start waits for her
		; spoken line plus OStim's build time, both longer than the queue budget used to be - so the
		; second half of a compound request was dropped exactly while the first half was working.
		; The hard ceiling (2x the budget) still ends it, and the clock-restart guard still applies.
		bool firstStillRunning = starting || pendingStart || navInFlight || pendingVerb != ""
		if !firstStillRunning || afNow > defHardUntil || afNow < (defHardUntil - 180.0)
			DropDeferred(defWhy) ; the deadline, or a real-time clock that restarted under it
			return
		endif
		; the deadline itself moves, in small steps and never past the ceiling: the retry below
		; hands it to DeferCommand again, which would otherwise refuse an already expired budget
		defUntil = afNow + 5.0
		if defUntil > defHardUntil
			defUntil = defHardUntil
		endif
		Main().LogC(defCid, "the queued command waits on: the one before it is still running", defNpc)
	endif
	string cmd = defCmd
	string param = defParam
	string npc = defNpc
	string cid = defCid
	; the slot is freed BEFORE the call, so the command may queue itself again - and then it keeps
	; the deadline it already had, instead of starting a new budget every second
	defResumeKey = cmd + "|" + param
	defResumeUntil = defUntil
	defResumeHard = defHardUntil
	ClearDeferred()
	if cmd == "ExtCmdLRG_StartIntimacy"
		CmdStart(npc, cmd, param)
	elseif cmd == "ExtCmdLRG_SceneControl"
		CmdControl(npc, cmd, param)
	elseif cmd == "ExtCmdLRG_Clothing"
		CmdClothing(npc, cmd, param)
	else
		Main().ReportResult(npc, cmd, param, "Error: unknown command")
	endif
	defResumeKey = ""
	defResumeUntil = 0.0
	defResumeHard = 0.0
EndFunction

; ---------------------------------------------------------------------------
; v0.3.1: StartIntimacy after=<scene id> (w1)
; The position the PLAYER asked for, when OStim could not start the thread in it. The server sends
; the scene it COULD start in as scene= / fscene= and the one the player named as after=; a few
; seconds after the thread is up the glue moves there. This is the player's own request, so warping
; is allowed exactly as it is for a spoken goto (bAllowWarp:Intimacy) - the nowarp rule exists for
; moves SHE chooses, not for his. An old server never sends the key and nothing here ever runs.
; ---------------------------------------------------------------------------
float Function TickAfterScene(float afNow)
	{Returns how long to wait before looking again. Clears afterScene as soon as the move is on its
	 way, is impossible, or the window is over.}
	if afterScene == "" || afNow < afterAt
		return 5.0
	endif
	LRG_Main m = Main()
	if !OThread.IsRunning(0)
		afterScene = ""
		return 5.0
	endif
	if afNow > afterUntil || afNow < (afterUntil - 120.0)
		m.LogC(startCid, "after= gave up: " + afterScene + " never became possible", partnerName)
		afterScene = ""
		return 5.0
	endif
	if starting || navInFlight || pendingVerb != "" || windDown
		return 1.0
	endif
	string target = afterScene
	string why = NavigateBlockedReason(target)
	bool useWarp = false
	if why == "unreachable"
		if m.SettingBool("bAllowWarp:Intimacy", true)
			useWarp = true
			why = ""
		else
			why = "that position cannot be reached from here"
		endif
	endif
	if why == "the scene is still starting" || why == "in the middle of a transition" || why == "still moving into the previous position"
		return 1.0 ; not yet - try again in a moment, inside the window
	endif
	if why != ""
		m.LogC(startCid, "after= dropped target=" + target + " reason=" + why, partnerName)
		;/[0.5.7] NEVER SILENT: the start was answered "OK: They draw close..." and the position the
		 player asked for cannot follow. One late Error: funcret for the start's own cid, in her words.
		 Not for "already there" (the scene is already the one he asked for)./;
		if why != "already there" && startCmd != ""
			m.ReportLateError(partnerName, startCmd, startParam, m.SayReason(why, startCmd), why)
		endif
		afterScene = ""
		return 5.0
	endif
	afterScene = ""
	CancelWindDown("the position the player asked for")
	GoToScene(target, useWarp, afNow, startCid)
	return 2.0
EndFunction

Function ClearPendingVerb()
	pendingVerb = ""
	pendingCmd = ""
	pendingNpcName = ""
	pendingParam = ""
	pendingCid = ""
	pendingAt = 0.0
	pendingUntil = 0.0
	pendingEnd = false
	pendingInScene = false
	pendingScene = ""
	pendingWarp = false
	pendingActor = None
	pendingFurnRef = None
EndFunction

Function DropPendingVerb(string asError)
	{The world moved on while the command waited for her line. It is answered, never left open:
	 CHIM waits for the funcret before the NPC is free again.}
	if pendingVerb == ""
		return
	endif
	string verb = pendingVerb
	string npcName = pendingNpcName
	string cmd = pendingCmd
	string param = pendingParam
	string cid = pendingCid
	ClearPendingVerb() ; cleared first: nothing below may find the park again
	Main().LogC(cid, "parked " + verb + " dropped: " + asError, npcName)
	Main().ReportResult(npcName, cmd, param, "Error: " + asError)
EndFunction

bool Function ParkVerb(string asVerb, string asNpcName, string asCommand, string asParam, string asCid, float afNow, bool abInScene, Actor akActor, bool abWarp, ObjectReference akFurn)
	{true = this command is now parked until her spoken line has started (wait=begin). Every
	 blocking check has already run, so a refusal was reported long before this point; what is
	 parked is only the OStim call itself, and the result follows the real call.}
	float budget = AnnounceBudget(asParam)
	if budget <= 0.0
		return false
	endif
	string nm = partnerName
	if nm == ""
		nm = asNpcName ; outside a scene (an undress she chose herself) there is no partner yet
	endif
	bool needEnd = Main().ParamGet(asParam, "wait") == "end"
	if AnnounceState(nm, afNow, afNow, needEnd) == 1
		return false ; she is speaking already: nothing to wait for
	endif
	pendingVerb = asVerb
	pendingCmd = asCommand
	pendingNpcName = asNpcName
	pendingParam = asParam
	pendingCid = asCid
	pendingAt = afNow
	pendingUntil = afNow + budget
	pendingEnd = needEnd
	pendingInScene = abInScene
	pendingScene = ""
	if abInScene
		pendingScene = OThread.GetScene(0)
	endif
	pendingWarp = abWarp
	pendingActor = akActor
	pendingFurnRef = akFurn
	Main().LogC(asCid, "waiting for " + nm + " to say it before " + asVerb + " (budget " + Secs(budget) + " s)", asNpcName)
	Main().RequestTick(0.5)
	return true
EndFunction

Function NoteSceneStrip(Actor akActor, string asPart, bool abUndressed)
	;/Keeps GlueUndressNode's "already undressed in this scene" bitmask honest when the clothing
	 verb changes the same thing by hand. Without the clearing half, one mid-scene "get dressed
	 again" used to mean she stayed dressed for the rest of the scene - through a node that OStim
	 itself would have stripped her for./;
	if !gateHeld
		return
	endif
	int bit = StripBitFor(akActor)
	if bit == 0
		return
	endif
	bool had = Math.LogicalAnd(glueStripped, bit) != 0
	if abUndressed
		if asPart == "all" && !had
			glueStripped += bit ; already bare: the ladder need not strip this actor again
		endif
	elseif had
		glueStripped -= bit ; dressed again: the next node that wants her bare may strip her
	endif
EndFunction

Function TickPendingVerb(float afNow)
	{A goto / furniture / clothing call parked for her spoken line. It is performed as soon as she
	 has started speaking, or when the budget runs out - unless the world moved on in between, in
	 which case it is answered with the same reason a late command would have got anyway.}
	string err = ""
	if pendingVerb == "clothing" && pendingActor == None
		err = "the actor could not be found"
	elseif pendingInScene
		if !ostimPresent || !OThread.IsRunning(0)
			err = "no scene is running"
		elseif navInFlight
			err = "still moving into the previous position"
		elseif OThread.GetScene(0) != pendingScene
			err = "still moving into the previous position" ; the scene changed under the command
		endif
	elseif starting || (ostimPresent && OThread.IsRunning(0))
		err = "a scene is just starting"
	endif
	if err != ""
		DropPendingVerb(err)
		return
	endif
	string nm = partnerName
	if nm == ""
		nm = pendingNpcName
	endif
	int st = AnnounceState(nm, afNow, pendingAt, pendingEnd)
	if st == 0 && afNow < pendingUntil
		Main().RequestTick(0.5)
		return
	endif
	string w = "wait=begin"
	if pendingEnd
		w = "wait=end"
	endif
	LogWait(pendingCid, w + " (" + pendingVerb + ")", afNow - pendingAt, st)
	; read the park out and clear it BEFORE the call: the call reports its own result
	string verb = pendingVerb
	string npcName = pendingNpcName
	string cmd = pendingCmd
	string param = pendingParam
	string cid = pendingCid
	bool useWarp = pendingWarp
	bool inScene = pendingInScene
	Actor who = pendingActor
	ObjectReference furnRef = pendingFurnRef
	LRG_Main m = Main()
	ClearPendingVerb()
	if verb == "goto"
		PerformGoto(npcName, cmd, param, cid, afNow, m.ParamGet(param, "scene"), useWarp)
	elseif verb == "furniture"
		PerformFurniture(npcName, cmd, param, cid, afNow, m.ParamGet(param, "furn"), m.ParamGet(param, "scene"), furnRef)
	elseif verb == "clothing"
		string part = m.ParamGet(param, "part")
		if part == ""
			part = "all"
		endif
		string whoKey = m.ParamGet(param, "who")
		if whoKey == ""
			whoKey = "npc"
		endif
		PerformClothing(npcName, cmd, param, cid, afNow, m.ParamGet(param, "do"), whoKey, part, inScene, who)
	endif
EndFunction

int Function StripBitFor(Actor akActor)
	{One bit per THREAD POSITION (OThread.GetActorPosition), not per index of the GetActors array:
	 the array order can differ from the positions, and then the bits would describe the wrong
	 actor. 0 = no position (no thread, or an actor that is not in it).}
	if akActor == None || !ostimPresent
		return 0
	endif
	if !OThread.IsRunning(0)
		return 0
	endif
	int pos = OThread.GetActorPosition(0, akActor)
	if pos < 0 || pos > 4
		return 0
	endif
	return Math.LeftShift(1, pos)
EndFunction

; ---------------------------------------------------------------------------
; FURNITURE (OFurniture natives). FindFurniture returns the closest FREE reference of each
; furniture type, sorted by distance, so one native call answers "what is in reach".
; ---------------------------------------------------------------------------
bool Function IsBedType(string asType)
	if asType == "" || asType == "none"
		return false
	endif
	if asType == "bed" || asType == "doublebed" || asType == "singlebed" || asType == "bedroll"
		return true
	endif
	; the stock non-bed types need no native call; anything a mod added is asked about
	if StringUtil.Find(",chair,bench,table,shelf,wall,cookingpot,alchemytable,enchantingtable,wardrobe,wardrobethick,wardrobethin,tableleanmarker,tableleanmarkerBBLS,", "," + asType + ",") >= 0
		return false
	endif
	return OFurniture.IsChildOf("bed", asType)
EndFunction

string Function ScanNearFurniture(Actor akCenter)
	{csv of furniture TYPES with a free ref in reach, closest first, max 6 (a bed further down
	 the list takes the 6th place: it is the one that matters). Also remembers the closest bed.}
	LRG_Main m = Main()
	nfCell = akCenter.GetParentCell()
	nfX = akCenter.GetPositionX()
	nfY = akCenter.GetPositionY()
	nfTime = Utility.GetCurrentRealTime()
	nfList = ""
	nfBed = None
	if !ostimPresent || !m.SettingBool("bFurniture:Intimacy", true)
		return ""
	endif
	float radius = m.SettingFloat("fFurnitureRadius:Intimacy", 1200.0)
	ObjectReference[] refs = OFurniture.FindFurniture(2, akCenter, radius, 96.0)
	string firstFive = ""
	string sixth = ""
	int i = 0
	int n = 0
	while i < refs.Length && i < 18 && (n < 6 || nfBed == None) ; 17 installed furniture types
		if refs[i]
			string t = OFurniture.GetFurnitureType(refs[i])
			if t != "" && t != "none"
				bool isBed = (nfBed == None) && IsBedType(t)
				if n < 5
					if firstFive != ""
						firstFive += ","
					endif
					firstFive += t
					n += 1
				elseif n == 5
					sixth = t
					n += 1
				elseif isBed
					sixth = t ; the list is full but this is the closest bed: it replaces the 6th entry
				endif
				if isBed
					nfBed = refs[i]
				endif
			endif
		endif
		i += 1
	endwhile
	nfList = firstFive
	if sixth != ""
		nfList += "," + sixth
	endif
	return nfList
EndFunction

string Function FurnitureFacts(Actor akNpc, Actor akPlayer, string asCellOwn)
	;/Snapshot keys nearf + bedown. Cached: same cell, player moved < 500 units, < 60 s old.
	 A slightly stale list is harmless: the start re-finds the furniture itself and falls back
	 to a standing start, and a running scene gets its own fresh list.
	 An unowned bed inside an owned cell belongs to the cell's owner (the game's own rule)./;
	if !ostimPresent
		return "nearf=;bedown="
	endif
	float now = Utility.GetCurrentRealTime()
	bool stale = akPlayer.GetParentCell() != nfCell || (now - nfTime) > 60.0 || now < nfTime
	if !stale
		float dx = akPlayer.GetPositionX() - nfX
		float dy = akPlayer.GetPositionY() - nfY
		stale = (dx * dx + dy * dy) > 250000.0
	endif
	if stale
		ScanNearFurniture(akPlayer)
	endif
	string own = ""
	if nfBed
		own = LRG_Profile.OwnerWord(nfBed.GetActorOwner(), nfBed.GetFactionOwner(), akNpc, akPlayer)
		if own == "none"
			own = asCellOwn
		endif
	endif
	return "nearf=" + nfList + ";bedown=" + own
EndFunction

ObjectReference Function FindFurnitureRef(string asType, Actor akCenter)
	{Closest free reference of EXACTLY that OStim furniture type, or None.}
	if asType == "" || asType == "none"
		return None
	endif
	float radius = Main().SettingFloat("fFurnitureRadius:Intimacy", 1200.0)
	; OStim's own native does exactly this, including the sub-type chain (a request for "bed"
	; matches a doublebed), which is what SetFurniture / ChangeFurniture want
	return OFurniture.FindFurnitureOfType(asType, akCenter, radius, 96.0)
EndFunction

int Function RentedBedValue(Cell akCell)
	if akCell != None && akCell == prCell
		return prVal
	endif
	return 0
EndFunction

bool Function RefreshRentedBed(Actor akPlayer)
	;/Inns only (LRG_Main decides). The installed RentRoomScript (Xtended Stay, line 23) makes the
	 rented bed owned by the player's ActorBase. Looks at the furniture references around the
	 player: owner test first (one native each), bed test only on a hit. At most once per cell
	 per 120 s. Returns true when the cached answer changed./;
	if !ostimPresent
		return false
	endif
	float now = Utility.GetCurrentRealTime()
	if prBusy && (now - prBusyTime) < 30.0 && now >= prBusyTime
		return false ; (older than that = a stuck flag from a dumped stack: carry on)
	endif
	Cell c = akPlayer.GetParentCell()
	if c == prCell && (now - prTime) < 120.0 && now >= prTime
		return false
	endif
	prBusy = true
	prBusyTime = now
	int old = RentedBedValue(c)
	int found = 0
	ActorBase pb = akPlayer.GetActorBase()
	ObjectReference[] refs = PO3_SKSEFunctions.FindAllReferencesOfFormType(akPlayer, 40, 6000.0) ; 40 = Furniture
	int n = refs.Length
	if n > 120
		n = 120
	endif
	int i = 0
	while i < n && found == 0
		ObjectReference r = refs[i]
		if r
			if r.GetActorOwner() == pb
				if IsBedType(OFurniture.GetFurnitureType(r))
					found = 1
				endif
			endif
		endif
		i += 1
	endwhile
	prCell = c
	prTime = now
	prVal = found
	prBusy = false
	return found != old
EndFunction

; ---------------------------------------------------------------------------
; START (command from CHIM; the server gate rewrote the parameter)
; param: ok=1;cid=<id>;npc=<name>;scene=<standing start id or empty>;undress=<0|1>;
;        furn=<furniture type or empty>;fscene=<start id on that furniture>;maxwit=<n>;folok=<0|1>
;        [;wait=end]   v0.3: do not let OStim's intro fade cut across her announcing line
; Two halves since v0.3: CmdStart does every check and the weapon preparation and then either
; builds the thread at once (no wait= key, or she is already speaking) or parks in pendingStart,
; where Tick builds it as soon as her line is out - or the budget runs out and it is built anyway.
; ---------------------------------------------------------------------------
Function CmdStart(string asNpcName, string asCommand, string asParam)
	LRG_Main m = Main()
	string cid = m.ParamGet(asParam, "cid")
	float cmdAt = Utility.GetCurrentRealTime() ; the wait is measured from the command's ARRIVAL
	ReleaseOutroHold("a new scene is starting") ; v0.3.1: the goodbye is over, she is needed again
	m.ReleaseConvHold("a scene is starting") ; [0.4.1] the scene has its own handling from here
	; [0.4] and the listener hold of the PREVIOUS scene goes with it: the new scene forces its own
	; listener at ostim_thread_start, on whoever this scene's partner turns out to be.
	if listenerUntil > 0.0
		ReleaseListener("a new scene is starting")
	endif
	ctlFixUntil = 0.0
	string why = StartBlockedReason(asNpcName, asParam)
	if why != ""
		; v0.3.1 (w2): "still moving into the previous position" means something of OURS is still
		; running - the first half of a compound request. Queue instead of losing the start.
		if why == "still moving into the previous position"
			if DeferCommand(asNpcName, asCommand, asParam, cid, why)
				return
			endif
		endif
		m.LogC(cid, "start refused reason=" + why, asNpcName)
		m.ReportResult(asNpcName, asCommand, asParam, "Error: " + why)
		return
	endif
	Actor npc = AIAgentFunctions.getAgentByName(asNpcName)
	Actor player = Game.GetPlayer()

	; furniture: only when the server named a type AND a valid start scene for it AND a free
	; reference of exactly that type is in reach; anything else = standing start as before
	string sceneId = m.ParamGet(asParam, "scene")
	string furnType = m.ParamGet(asParam, "furn")
	string furnScene = m.ParamGet(asParam, "fscene")
	ObjectReference furnRef = None
	if furnType != "" && furnType != "none" && furnScene != "" && m.SettingBool("bFurniture:Intimacy", true)
		if OMetadata.GetActorCount(furnScene) == 2
			furnRef = FindFurnitureRef(furnType, player)
		endif
	endif

	int payGold = m.ParamGet(asParam, "pay") as int
	if payGold < 0
		payGold = 0
	endif
	if m.IsDryRun()
		if furnRef
			m.LogC(cid, "DRY RUN WOULD START scene '" + furnScene + "' on " + furnType + " with " + asNpcName, asNpcName)
		else
			m.LogC(cid, "DRY RUN WOULD START scene '" + sceneId + "' standing with " + asNpcName, asNpcName)
		endif
		if payGold > 0
			m.LogC(cid, "DRY RUN WOULD PAY " + payGold + " gold to " + asNpcName + " - no coin moved", asNpcName)
		endif
		m.ReportResult(asNpcName, asCommand, asParam, "Error: dry-run mode is ON in the LoreRim Glue MCM (Diagnostics page) - nothing was started")
		return
	endif

	; from here on a second start command is refused ("a scene is already running")
	partner = npc
	partnerName = asNpcName
	starting = true
	abortStart = false
	startedByGlue = true
	startCid = cid
	startCmd = asCommand
	startParam = asParam
	startPayGold = payGold ; [0.4] charged in BeginStartThread, the one place the scene is certainly real
	scenePaidGold = 0
	startDeadline = cmdAt + 40.0 + AnnounceBudget(asParam) ; the announce wait must not time the start out
	AIAgentFunctions.setAnimationBusy(1, asNpcName)

	; crash workaround: leave OStim's native weapon removal nothing to do (see header)
	EnsureArrays()
	RestoreWeapons() ; nothing may still be blocked from an earlier scene when a new one begins
	int owr = OStimWeaponRemoval()
	if owr == 1 && m.SettingBool("bFixOStimWeaponCrash:Intimacy", true)
		; Playtest 4 (2026-09-21): both actors read EMPTY for three rounds and OStim's own weapon
		; removal still crashed the game on the NPC, so re-arming is not the cause - the native call
		; itself faults. The only gate on that call is this OStim global, so the glue turns it OFF
		; (the glue already puts the weapons away and gives them back). The owner can switch it back
		; on in OStim's MCM (Undressing page); this MCM option stops the glue from touching it.
		GlobalVariable g = Game.GetFormFromFile(0x00000DAB, "OStim.esp") as GlobalVariable
		if g
			g.SetValue(0.0)
		endif
		owr = OStimWeaponRemoval()
		if owr == 0
			m.LogC(cid, "OStim 'Remove Weapons at Start' was ON - turned OFF by the glue (crash protection)", asNpcName)
		endif
	endif
	if owr == 1
		m.LogC(cid, "OStim 'Remove Weapons at Start' is ON: OStim will unequip both actors itself (this is the call that crashed)", asNpcName)
		if m.SettingBool("bRefuseIfArmed:Intimacy", true)
			m.ReportError(asNpcName, asCommand, asParam, "I cannot, not right now", "OStim's 'Remove Weapons at Start' is on and would crash the game - turn it off in OStim's MCM (Undressing page)")
			Debug.Notification("LoreRim Glue: turn OFF 'Remove Weapons at Start' in OStim's MCM (Undressing) - scene not started")
			ResetState()
			return
		endif
	elseif owr == 0
		m.LogC(cid, "OStim 'Remove Weapons at Start' is OFF: OStim will not touch the weapons at the start", asNpcName)
	else
		m.LogE(cid, "OStim 'Remove Weapons at Start' could not be read", asNpcName)
	endif
	; crash 4: the same native is also reached from OStim's mid-scene undressing on every node
	; change. Its two gates go off for the length of this scene and are restored afterwards.
	if m.SettingBool("bFixOStimWeaponCrash:Intimacy", true)
		HoldOStimGates(cid)
	endif
	string prepWhy = PrepareWeapons(player, 0, cid)
	if prepWhy == ""
		prepWhy = PrepareWeapons(npc, 1, cid)
	endif
	if prepWhy == ""
		prepWhy = SettleWeapons(player, npc, cid)
	endif
	if prepWhy == "" && (npc.IsInCombat() || player.IsInCombat() || npc.IsDead())
		prepWhy = "combat"
	endif
	if prepWhy != ""
		m.LogC(cid, "start aborted in preparation: " + prepWhy, asNpcName)
		m.ReportResult(asNpcName, asCommand, asParam, "Error: " + prepWhy)
		ResetState()
		RestoreWeapons()
		return
	endif
	CaptureBaseline(player, npc) ; what both wear now: parts report + redress at the end

	if furnRef
		if furnRef.IsFurnitureInUse()
			furnRef = None ; somebody sat / lay down while the hands were being emptied
		endif
	endif
	if furnRef == None && sceneId == ""
		; neither furniture nor a starting animation would let OStim choose any scene of any tier
		; by itself and bypass the gentle-first ladder: fail closed instead
		m.LogC(cid, "start refused: no furniture reference and no starting scene", asNpcName)
		m.ReportResult(asNpcName, asCommand, asParam, "Error: the scene did not start")
		ResetState()
		RestoreWeapons()
		baseValid = false
		return
	endif

	; G3a: OStim's intro fade must not cut across the line she says to give the moment context.
	; Everything above has already happened (the gates, the hands, the baseline); only the thread
	; is held back, and only until her line is out or the budget is spent.
	pendingStartScene = sceneId
	pendingStartFurn = furnRef
	pendingStartFurnType = furnType
	float budget = AnnounceBudget(asParam)
	if budget > 0.0
		bool needEnd = m.ParamGet(asParam, "wait") == "end"
		if AnnounceState(asNpcName, cmdAt, cmdAt, needEnd) == 0
			pendingStart = true
			pendingStartAt = cmdAt
			pendingStartUntil = cmdAt + budget
			pendingStartEnd = needEnd
			m.LogC(cid, "waiting for " + asNpcName + " to say it before the scene starts (budget " + Secs(budget) + " s)", asNpcName)
			m.RequestTick(0.5)
			return
		endif
		LogWait(cid, "wait=" + m.ParamGet(asParam, "wait"), 0.0, 1) ; she was already speaking
	endif
	BeginStartThread(cid)
EndFunction

Function BeginStartThread(string asCid)
	;/The second half of CmdStart: build OStim's thread and start it. Called straight from
	 CmdStart, or from Tick when the announce wait is over. Every check and the whole weapon
	 preparation have already run; what can still have changed in the meantime is the furniture
	 (somebody sat down) and what the two of them are holding - both are re-checked here./;
	LRG_Main m = Main()
	Actor player = Game.GetPlayer()
	Actor npc = partner
	if npc == None
		m.LogC(asCid, "start aborted: the partner is gone", partnerName)
		m.ReportResult(partnerName, startCmd, startParam, "Error: the actor could not be found")
		ResetState()
		RestoreWeapons()
		baseValid = false
		return
	endif
	string sceneId = pendingStartScene
	string furnType = pendingStartFurnType
	ObjectReference furnRef = pendingStartFurn
	pendingStartFurn = None
	if furnRef
		if furnRef.IsFurnitureInUse()
			furnRef = None ; somebody sat / lay down while she was speaking
		endif
	endif
	if furnRef == None && sceneId == ""
		m.LogC(asCid, "start refused: no furniture reference and no starting scene", partnerName)
		m.ReportResult(partnerName, startCmd, startParam, "Error: the scene did not start")
		ResetState()
		RestoreWeapons()
		baseValid = false
		return
	endif
	; the prevent-equip flag still holds from PrepareWeapons, but an announce wait gives an NPC's
	; AI seconds to reach for something: one more pass costs two natives and keeps the crash fix
	; watertight (see the header - OStim's ThreadActor constructor is what dies on a full hand).
	int again = ClearHands(player, 0) + ClearHands(npc, 1)
	if again > 0
		m.LogC(asCid, "guard: " + again + " item(s) had to be taken off again before the start", partnerName)
	endif

	Actor[] actors = OActorUtil.ToArray(player, npc)
	actors = OActorUtil.Sort(actors, OActorUtil.EmptyArray())
	int builder = OThreadBuilder.Create(actors)
	if builder < 0
		m.ReportResult(partnerName, startCmd, startParam, "Error: OStim rejected the actors")
		ResetState()
		RestoreWeapons()
		baseValid = false
		return
	endif
	string furnScene = m.ParamGet(startParam, "fscene")
	string usedScene = sceneId
	if furnRef
		OThreadBuilder.SetFurniture(builder, furnRef) ; explicit furniture: no message box either
		OThreadBuilder.SetStartingAnimation(builder, furnScene)
		usedScene = furnScene
	else
		OThreadBuilder.NoFurniture(builder) ; explicit: avoids OStim's furniture message boxes
		if sceneId != "" && OMetadata.GetActorCount(sceneId) == 2
			OThreadBuilder.SetStartingAnimation(builder, sceneId)
		endif
	endif
	startNoUndress = m.ParamGet(startParam, "undress") != "1"
	if startNoUndress
		; NOTE: this flag only stops OStim's undress(), never its weapon removal - see the header
		OThreadBuilder.NoUndressing(builder)
	endif
	OThreadBuilder.NoPostDialogue(builder) ; OStim's own voiced after-scene lines would talk over CHIM

	startDeadline = Utility.GetCurrentRealTime() + 20.0
	if furnRef
		m.LogC(asCid, "calling OThreadBuilder.Start scene=" + usedScene + " furniture=" + furnType + " ref=" + furnRef.GetFormID(), partnerName)
	else
		m.LogC(asCid, "calling OThreadBuilder.Start scene=" + usedScene + " standing", partnerName)
	endif
	int tid = OThreadBuilder.Start(builder)
	if tid < 0
		; OStim refused outright: answer now instead of leaving the NPC busy for the 20 s timeout
		m.LogE(asCid, "start failed immediately", partnerName)
		m.ReportResult(partnerName, startCmd, startParam, "Error: the scene did not start")
		ResetState()
		RestoreWeapons()
		baseValid = false
		return
	endif
	m.LogC(asCid, "start requested tid=" + tid, partnerName)
	; [0.4 / OWNER ADDENDA 6] THE COIN CHANGES HANDS HERE, and nowhere else: this is the first line at
	; which the scene is certainly real (OStim accepted the thread). The purse is re-checked because the
	; announce wait may have been seconds long; the window between that check and the transfer is
	; milliseconds. A scene that has already started is NEVER cancelled over gold - it runs, paid=0 is
	; reported, the server's halved gain simply does not apply, and the line below says so. That is the
	; safe direction (a scene the player got for free) and it is always logged.
	if startPayGold > 0
		Form g = Gold()
		if g != None && player.GetItemCount(g) >= startPayGold
			; abSilent = false on purpose: the owner sees the coin leave in the corner of the screen
			player.RemoveItem(g, startPayGold, false, npc)
			scenePaidGold = startPayGold
			m.LogC(asCid, "paid " + startPayGold + " gold to " + partnerName, partnerName)
		else
			scenePaidGold = 0
			m.LogC(asCid, "NOT paid: the player no longer has " + startPayGold + " gold", partnerName)
		endif
		startPayGold = 0
	endif
	m.RequestTick(2.0)
	GuardStart(player, npc, asCid) ; OStim builds its actors a moment from now: keep the hands empty
EndFunction

Function TickPendingStart(float afNow)
	{The start is parked until her announcing line is out. Never longer than the budget: a start is
	 never lost, it is only late. "stop" reaches this state through abortStart, as it does when
	 OStim is already building the thread.}
	if abortStart
		pendingStart = false
		abortStart = false
		starting = false
		LRG_Main m = Main()
		m.LogC(startCid, "start dropped while waiting for her line: stop was asked for", partnerName)
		m.ReportResult(partnerName, startCmd, startParam, "Error: the scene did not start")
		ResetState()
		RestoreWeapons()
		baseValid = false
		return
	endif
	int st = AnnounceState(partnerName, afNow, pendingStartAt, pendingStartEnd)
	if st == 0 && afNow < pendingStartUntil
		Main().RequestTick(0.5)
		return
	endif
	pendingStart = false
	string w = "wait=begin"
	if pendingStartEnd
		w = "wait=end"
	endif
	LogWait(startCid, w, afNow - pendingStartAt, st)
	BeginStartThread(startCid)
EndFunction

; ---------------------------------------------------------------------------
; Weapon preparation / restore (the 2026-09-21 crash)
; OStim's ThreadActor constructor calls the game's UnequipItem native on whatever each actor is
; holding (OStimNG ThreadActor.cpp:61 -> removeWeapons ThreadActor.cpp:600 -> GameActor.cpp:222
; unequipWeaponry: right hand, left hand, ammo) and that native died on the NPC. unequipWeaponry
; skips every hand it finds EMPTY, so the whole crash is avoided by making sure both actors hold
; nothing at the moment OStim builds its ThreadActors - which is a second AFTER OThreadBuilder.Start
; returns. v0.1.1 unequipped without the prevent-equip flag and an armed NPC re-armed herself
; inside that second. Everything here therefore unequips with abPreventEquip = true (the flag is
; lifted again by EquipItem, which is why every blocked item is remembered and given back).
; GetEquippedObject: 0 = left hand, 1 = right hand. UnequipItemEx slot: 1 = right, 2 = left.
; ---------------------------------------------------------------------------
int Function OStimWeaponRemoval()
	;/Is OStim's own "Remove Weapons at Start" on? 1 yes, 0 no, -1 could not be read. That MCM
	 toggle (MCM > OStim > Undressing) is the only gate on the crashing native call: with it OFF
	 OStim never touches the weapons. READ ONLY - the glue never writes an OStim setting.
	 0xDAB in OStim.esp is the global OStimRemoveWeaponsAtStart (verified in the installed plugin;
	 it is the same id OStim's own MCMTable reads)./;
	GlobalVariable g = Game.GetFormFromFile(0x00000DAB, "OStim.esp") as GlobalVariable
	if g == None
		return -1
	endif
	if g.GetValue() != 0.0
		return 1
	endif
	return 0
EndFunction

GlobalVariable Function OStimGlobal(int aiFormId)
	;/One of OStim's setting globals out of the installed OStim.esp, or None when it is not there.
	 Every id used here was read out of the installed plugin's GLOB records and matches the ids
	 OStim's own MCMTable uses: 0xDAA UndressAtStart, 0xDAB RemoveWeaponsAtStart,
	 0xDAC UndressMidScene, 0xDAD PartialUndressing, 0xDAE RemoveWeaponsWithSlot./;
	return Game.GetFormFromFile(aiFormId, "OStim.esp") as GlobalVariable
EndFunction

Function HoldOStimGates(string asCid)
	;/Switches OStim's two mid-scene undressing options OFF for the duration of a glue-started
	 scene and remembers what they were. Both of them let OStim run the weapon-removal native on
	 every node change (crash 4), and neither the "Remove Weapons at Start" option nor the thread's
	 NoUndressing flag stops that. The glue does the undressing instead (GlueUndressNode).
	 Called once per start, after the hands are empty and before OThreadBuilder.Start./;
	if gateHeld
		return
	endif
	LRG_Main m = Main()
	GlobalVariable gMid = OStimGlobal(0x00000DAC)
	GlobalVariable gPart = OStimGlobal(0x00000DAD)
	if gMid == None || gPart == None
		m.LogE(asCid, "OStim undressing gates not found (OStim.esp 0xDAC/0xDAD) - MID-SCENE CRASH GUARD IS OFF", "")
		return
	endif
	gatePrevMid = gMid.GetValue() as int
	gatePrevPart = gPart.GetValue() as int
	glueStripped = 0
	gateHeld = true ; set BEFORE the writes: whatever happens now, the values are given back
	gMid.SetValue(0.0)
	gPart.SetValue(0.0)
	m.LogC(asCid, "OStim mid-scene undressing paused: UndressMidScene " + gatePrevMid + "->0, PartialUndressing " + gatePrevPart + "->0 (crash protection; the glue undresses instead)", "")
EndFunction

Function ReleaseOStimGates(string asWhy)
	;/Puts OStim's two mid-scene undressing options back the way the owner had them. Called from
	 RestoreWeapons, which every path that ends or abandons a scene goes through, and once more
	 from FinishThread. Deliberately does NOT look at the MCM switch: whatever the glue lowered
	 has to come back up even if the switch was turned off in the meantime.
	 OStimRemoveWeaponsAtStart (0xDAB) is NOT restored here - it stays off on purpose, because
	 that call crashes at every start; the owner turns it back on in OStim's MCM if they want it./;
	if !gateHeld
		return
	endif
	gateHeld = false
	glueStripped = 0
	GlobalVariable gMid = OStimGlobal(0x00000DAC)
	GlobalVariable gPart = OStimGlobal(0x00000DAD)
	if gMid && gatePrevMid >= 0
		gMid.SetValue(gatePrevMid as float)
	endif
	if gPart && gatePrevPart >= 0
		gPart.SetValue(gatePrevPart as float)
	endif
	Main().Log("OStim mid-scene undressing restored (UndressMidScene=" + gatePrevMid + " PartialUndressing=" + gatePrevPart + "): " + asWhy)
	gatePrevMid = -1
	gatePrevPart = -1
EndFunction

string Function FullStripActor()
	{Action types whose ACTOR role carries "fullStrip": true, read out of the installed
	 SKSE\Plugins\OStim\actions\*.json. These are exactly the actions that make OStim undress the
	 actor performing them when a node starts.}
	return "analsex,boobjob,breastsliding,buttjob,cumonbutt,cumonchest,cumonvulva,facial,grindingfoot,grindingobject,grindingpenis,grindingthigh,rubbingpenisagainstface,thighjob,tribbing,vaginalsex,3pp_boobjob,3pp_buttjob,ejaculation_on_butt,ejaculation_on_chest,ejaculation_on_face,ejaculation_on_hands,ejaculation_on_vulva"
EndFunction

string Function FullStripTarget()
	{The same for the TARGET role of an action.}
	; kept in short pieces on purpose: one very long string in a .pex crashes the game's script
	; loader when a save is loaded (crash 2026-09-21 16:57, a 6765-character docstring)
	string a = "analfingering,analfisting,anallicking,analsex,analtailsex,analtoying,blowjob,boobjob,buttjob,cumonbutt,cumonchest,cumonvulva,deepthroating,femalemasturbation,footjob,grindingpenis,gropingtesticles,handjob,"
	string b = "lickingnipple,malemasturbation,penilelicking,rimjob,suckingnipple,tailjob,testicularlicking,thighjob,tribbing,vaginalfingering,vaginalfisting,vaginalsex,vaginaltailsex,vaginaltoying,vulvaleating,vulvallicking,vulvalrubbing,"
	return a + b + "3pp_boobjob,3pp_buttjob,3pp_cunnilingus,3pp_femalemasturbation,3pp_gropingtesticles,3pp_kissfellatio1,3pp_kissfellatio2,3pp_lickingpenis,3pp_vaginalfingering,ejaculation_on_butt,ejaculation_on_chest,ejaculation_on_vulva"
EndFunction

Function GlueUndressNode(string asSceneID, string asWhy)
	;/The glue's stand-in for OStim's own mid-scene undressing, which is paused while a glue scene
	 runs. When the scene reaches a node with an action that would have made OStim strip somebody
	 (the fullStrip lists above), that actor is undressed through OStim's own OActor.Undress - the
	 same call the "undress" verb uses, so OStim knows about the clothes and redresses them at the
	 end. Each actor is undressed at most once per scene.
	 What is deliberately NOT copied: OStim's PARTIAL stripping (a node that only pulls the body
	 slot aside). Papyrus cannot read a node's stripping mask, and partial stripping was the other
	 way into the crashing native, so it stays off; nobody is left naked who would not have been./;
	if !gateHeld || asSceneID == ""
		return
	endif
	if startNoUndress
		return ; built with NoUndressing: OStim would not have undressed anybody either
	endif
	if gatePrevMid != 1
		return ; the owner has "Fully undress mid Scene" off in OStim's MCM: respect that
	endif
	LRG_Main m = Main()
	if !m.SettingBool("bGlueUndressMidScene:Intimacy", true)
		return
	endif
	if OMetadata.IsTransition(asSceneID)
		return
	endif
	Actor[] actors = OThread.GetActors(0)
	int i = 0
	int done = 0
	while i < actors.Length && i < 5
		; the SLOT of an actor is its thread position, which is what the scene's actions are
		; indexed by; the order of the GetActors array is not guaranteed to be the same thing.
		int pos = -1
		int bit = 0
		if actors[i]
			pos = OThread.GetActorPosition(0, actors[i])
			if pos >= 0 && pos <= 4
				bit = Math.LeftShift(1, pos)
			endif
		endif
		if bit != 0 && Math.LogicalAnd(glueStripped, bit) == 0
			if OMetadata.FindAnyActionForActorCSV(asSceneID, pos, FullStripActor()) >= 0 || OMetadata.FindAnyActionForTargetCSV(asSceneID, pos, FullStripTarget()) >= 0
				glueStripped = Math.LogicalOr(glueStripped, bit)
				SceneUndress(actors[i], "all")
				done += 1
			endif
		endif
		i += 1
	endwhile
	if done > 0
		m.LogC(startCid, "glue undressed " + done + " actor(s) at scene " + asSceneID + " (" + asWhy + ")", partnerName)
	endif
EndFunction

bool Function RememberPrep(int aiIdx, Form akForm, int aiSlot)
	{Remembers an item so RestoreWeapons gives it back AND lifts its prevent-equip flag.
	 slot 1 = right hand, 0 = left hand, 2 = ammo. Returns false when there is no room left:
	 the caller then unequips WITHOUT the flag, so nothing can stay blocked forever.}
	if akForm == None
		return false
	endif
	if aiSlot == 1 && prepRight[aiIdx] == None
		prepRight[aiIdx] = akForm
		return true
	elseif aiSlot == 0 && prepLeft[aiIdx] == None
		prepLeft[aiIdx] = akForm
		return true
	elseif aiSlot == 2 && prepAmmo[aiIdx] == None
		prepAmmo[aiIdx] = akForm
		return true
	endif
	if prepRight[aiIdx] == akForm || prepLeft[aiIdx] == akForm || prepAmmo[aiIdx] == akForm
		return true ; already remembered: it will be given back anyway
	endif
	if akForm as Spell
		return false ; UnequipSpell has no prevent flag, so there is nothing to lift later
	endif
	if prepExtra.Length != 8
		return false
	endif
	int i = aiIdx * 4
	int last = i + 3
	while i <= last
		if prepExtra[i] == akForm
			return true
		endif
		if prepExtra[i] == None
			prepExtra[i] = akForm
			return true
		endif
		i += 1
	endwhile
	return false
EndFunction

int Function ClearHands(Actor akActor, int aiIdx)
	{Takes whatever is in either hand and in the ammo slot off this actor, with the prevent-equip
	 flag, and remembers it. Returns how many items it found (0 = the actor is already empty).
	 Three natives per actor when there is nothing to do.}
	if akActor == None
		return 0
	endif
	int n = 0
	Form r = akActor.GetEquippedObject(1)
	if r
		UnequipHand(akActor, r, 1, RememberPrep(aiIdx, r, 1))
		n += 1
	endif
	Form l = akActor.GetEquippedObject(0) ; read AFTER the right hand: a two-hander is gone by now
	if l
		UnequipHand(akActor, l, 0, RememberPrep(aiIdx, l, 0))
		n += 1
	endif
	Form am = PO3_SKSEFunctions.GetEquippedAmmo(akActor) as Form
	if am
		akActor.UnequipItem(am, RememberPrep(aiIdx, am, 2), true)
		n += 1
	endif
	return n
EndFunction

string Function PrepareWeapons(Actor akActor, int aiIdx, string asCid)
	{First pass for one actor: sheathe, then empty both hands + the ammo slot. The verification
	 is SettleWeapons, which watches both actors together.}
	prepActor[aiIdx] = akActor
	prepRight[aiIdx] = None
	prepLeft[aiIdx] = None
	prepAmmo[aiIdx] = None
	if prepExtra.Length == 8
		int j = aiIdx * 4
		while j < aiIdx * 4 + 4
			prepExtra[j] = None
			j += 1
		endwhile
	endif
	if akActor == None
		return "the actor could not be found"
	endif
	int n = 0
	if akActor.IsWeaponDrawn()
		akActor.SheatheWeapon()
		while akActor.IsWeaponDrawn() && n < 12
			Utility.Wait(0.25)
			n += 1
		endwhile
	endif
	ClearHands(akActor, aiIdx)
	; a shield does sit in the left hand, but it is cheap insurance against a shield the hand
	; read missed (torch + shield, staggered equip): the same prevent-equip treatment
	Armor sh = akActor.GetEquippedShield()
	if sh
		akActor.UnequipItem(sh as Form, RememberPrep(aiIdx, sh as Form, 0), true)
	endif
	return ""
EndFunction

string Function SettleWeapons(Actor akPlayer, Actor akNpc, string asCid)
	{Both actors must read "nothing in either hand, no ammo" three checks in a row before OStim
	 is allowed to start; whatever reappears in between (an NPC pulls a second weapon out of her
	 inventory) is taken off again. Gives up after ~4 s. Returns "" or a refusal reason.}
	LRG_Main m = Main()
	int clean = 0
	int rounds = 0
	while clean < 3 && rounds < 16 ; 16 * 0.25 s = 4 s
		Utility.Wait(0.25)
		rounds += 1
		int again = ClearHands(akPlayer, 0) + ClearHands(akNpc, 1)
		if again == 0
			clean += 1
		else
			clean = 0
		endif
	endwhile
	bool ok = clean >= 3
	m.LogV(asCid, "prep player rounds=" + rounds + " " + PrepTag(0) + " empty=" + LRG_Profile.B2I(ok), "")
	string who = "npc"
	if akNpc
		who = akNpc.GetDisplayName()
	endif
	m.LogV(asCid, "prep " + who + " rounds=" + rounds + " " + PrepTag(1) + " empty=" + LRG_Profile.B2I(ok), "")
	if !ok
		if m.SettingBool("bRefuseIfArmed:Intimacy", true)
			return "the weapons could not be put away" ; no scene is better than a crash
		endif
		m.LogE(asCid, "WARNING starting with a hand that keeps filling up again (bRefuseIfArmed is off)", "")
	endif
	return ""
EndFunction

Function GuardStart(Actor akPlayer, Actor akNpc, string asCid)
	{OThreadBuilder.Start is asynchronous: OStim builds its ThreadActors - and runs the native
	 weapon removal that crashed the game - about a second later. Keep watching both pairs of
	 hands until the thread reports itself (starting == false) or 2.5 s have passed.}
	int rounds = 0
	int again = 0
	while starting && rounds < 10
		Utility.Wait(0.25)
		rounds += 1
		again += ClearHands(akPlayer, 0) + ClearHands(akNpc, 1)
	endwhile
	if again > 0
		Main().LogC(asCid, "guard: " + again + " item(s) had to be taken off again while OStim was starting", "")
	endif
EndFunction

string Function PrepTag(int aiIdx)
	{What the preparation took out of this actor's hands, for the log.}
	string out = "R=" + FormTag(prepRight[aiIdx]) + " L=" + FormTag(prepLeft[aiIdx]) + " ammo=" + FormTag(prepAmmo[aiIdx])
	if prepExtra.Length != 8
		return out
	endif
	string more = ""
	int i = aiIdx * 4
	int last = i + 3
	while i <= last
		if prepExtra[i]
			if more != ""
				more += ","
			endif
			more += FormTag(prepExtra[i])
		endif
		i += 1
	endwhile
	if more != ""
		out += " again=" + more
	endif
	return out
EndFunction

string Function FormTag(Form akForm)
	if akForm == None
		return "none"
	endif
	return akForm.GetFormID() + "/t" + akForm.GetType()
EndFunction

Function UnequipHand(Actor akActor, Form akForm, int aiHand, bool abPrevent)
	{abPrevent = the AI may not put it straight back. Only ever true for an item the glue
	 remembered: EquipItem is the only thing that lifts the flag again.}
	Spell sp = akForm as Spell
	if sp
		akActor.UnequipSpell(sp, aiHand) ; spells have no prevent flag
	elseif akForm as Weapon
		; the plain call carries the prevent-equip flag, the Ex call makes sure the copy in THIS
		; hand goes too when the same weapon is held in both
		akActor.UnequipItem(akForm, abPrevent, true)
		if aiHand == 1
			akActor.UnequipItemEx(akForm, 1, abPrevent)
		else
			akActor.UnequipItemEx(akForm, 2, abPrevent)
		endif
	else
		akActor.UnequipItem(akForm, abPrevent, true) ; shield, torch, scroll ...
	endif
EndFunction

Function EquipHand(Actor akActor, Form akForm, int aiHand)
	Spell sp = akForm as Spell
	if sp
		akActor.EquipSpell(sp, aiHand)
	elseif akActor.GetItemCount(akForm) > 0
		if akForm as Weapon
			if aiHand == 1
				akActor.EquipItemEx(akForm, 1, false, false)
			else
				akActor.EquipItemEx(akForm, 2, false, false)
			endif
		else
			akActor.EquipItem(akForm, false, true)
		endif
	endif
EndFunction

Function RestoreWeapons()
	;/Gives both actors back what the preparation took out of their hands and lifts the
	 prevent-equip flag it set. Safe to call any time, and called on EVERY path that can follow a
	 preparation: a refused start, an OStim refusal, the start timeout in Tick, the end of the
	 thread (FinishThread) and a game load with no scene running (Maintenance). An item that is
	 no longer in the actor's inventory is skipped: the flag went with it.
	 OStim's undressing gates were lowered at the same moment the weapons were taken, so they are
	 raised again here - every exit path passes through this function./;
	; not while a thread runs: a start that was only late (start timeout in Tick) would otherwise
	; run with the gates up again. FinishThread releases them when that thread ends.
	if !OThread.IsRunning(0)
		ReleaseOStimGates("weapons given back")
	endif
	if prepActor.Length != 2
		return
	endif
	bool hasExtra = prepExtra.Length == 8
	int i = 0
	while i < 2
		Actor a = prepActor[i]
		if a && !a.IsDead()
			if prepRight[i]
				EquipHand(a, prepRight[i], 1)
			endif
			if prepLeft[i]
				EquipHand(a, prepLeft[i], 0)
			endif
			if prepAmmo[i] && a.GetItemCount(prepAmmo[i]) > 0
				a.EquipItem(prepAmmo[i], false, true)
			endif
			if hasExtra
				int j = i * 4
				while j < i * 4 + 4
					Form f = prepExtra[j]
					if f && f != prepRight[i] && f != prepLeft[i] && f != prepAmmo[i]
						if a.GetItemCount(f) > 0
							a.EquipItem(f, false, true) ; she reached for it herself: lift the block
						endif
					endif
					j += 1
				endwhile
			endif
		endif
		prepActor[i] = None
		prepRight[i] = None
		prepLeft[i] = None
		prepAmmo[i] = None
		if hasExtra
			int k = i * 4
			while k < i * 4 + 4
				prepExtra[k] = None
				k += 1
			endwhile
		endif
		i += 1
	endwhile
EndFunction

; ---------------------------------------------------------------------------
; Hard gates, game side
; ---------------------------------------------------------------------------
string Function PairBlockedReason(Actor npc, Actor player)
	if !npc || npc == player || npc.IsDead()
		return "the actor could not be found"
	endif
	if !LRG_Profile.IsAdult(npc) || !LRG_Profile.IsAdult(player)
		return "adults only"
	endif
	if npc.IsInCombat() || player.IsInCombat()
		return "combat"
	endif
	if npc.GetCurrentScene() != None || player.GetCurrentScene() != None
		return "a quest scene is running"
	endif
	if npc.GetDistance(player) > 1200.0
		return "too far apart"
	endif
	return ""
EndFunction

string Function PrivacyBlockedReason(Actor npc, Actor player, string asParam)
	{Witness re-scan against the limits the server computed for this NPC's profile.
	 Missing maxwit / folok mean the strictest reading (0 witnesses, companions count).}
	LRG_Main m = Main()
	float radius = m.SettingFloat("fWitnessRadiusExterior:Intimacy", 2500.0)
	Cell c = player.GetParentCell()
	if c && c.IsInterior()
		radius = m.SettingFloat("fWitnessRadiusInterior:Intimacy", 1200.0)
	endif
	; [0.4] The game-side re-check MUST compute the same two door arguments as the snapshot did, or it
	; would refuse a start the server had already allowed ("someone is watching" in a room whose door
	; is shut - the whole of the owner's second complaint, moved from the server to here).
	int doorShut = 0
	float hearRadius = m.SettingFloat("fHearRadius:Intimacy", 600.0)
	if hearRadius < 100.0
		hearRadius = 600.0
	elseif hearRadius > 2000.0
		hearRadius = 2000.0
	endif
	if m.SettingBool("bPrivacyDoors:Intimacy", true)
		doorShut = DoorFacts(player, true) ; FRESH: a start must never be allowed on a stale reading
	endif
	string scan = LRG_Profile.WitnessScan(npc, player, radius, doorShut == 1, hearRadius)
	if m.ParamGet(scan, "witkid") == "1"
		return "a child is nearby"
	endif
	if (m.ParamGet(scan, "wit") as int) > (m.ParamGet(asParam, "maxwit") as int)
		return "someone is watching"
	endif
	if m.ParamGet(asParam, "folok") != "1" && (m.ParamGet(scan, "witfol") as int) > 0
		return "a companion is present"
	endif
	return ""
EndFunction

bool Function DialogueBusy(LRG_Main akMain)
	{[0.4 fix pass, D4] True while the menuless questing module has a live Dialogue Menu session.
	 False when that module is not attached, so a load order without it is unaffected.}
	if !akMain
		return false
	endif
	LRG_Dialogue dlg = akMain.GetDialogue()
	if !dlg
		return false
	endif
	return dlg.IsSessionOpen()
EndFunction

string Function StartBlockedReason(string asNpcName, string asParam)
	{Game-side repeat of every hard gate. Returns "" when the scene may start.}
	LRG_Main m = Main()
	if !ostimPresent
		return "OStim is not installed"
	endif
	if !m.IsIntimacyEnabled()
		return "the feature is switched off"
	endif
	;/[0.4 fix pass, D4 / PHASE2_DESIGN 7.2 item 3] A Dialogue Menu session is live. At the shipped
	 defaults (iEngineOpen = 0) every ordinary E-press conversation makes this true, and OStim's own
	 hotkeys are dead while that menu is up (design F14), so the player would be inside a scene he
	 cannot control. Utility.IsInMenuMode() cannot stand in for this: a Dialogue Menu is NOT menu
	 mode (design D-13). The reason string is the one PROTOCOL 1.6 already carries./;
	if DialogueBusy(m)
		return "a conversation is in progress"
	endif
	if m.ParamGet(asParam, "ok") != "1"
		return "not authorised by the server gate"
	endif
	if OThread.IsRunning(0) || starting
		return "a scene is already running"
	endif
	Actor npc = AIAgentFunctions.getAgentByName(asNpcName)
	Actor player = Game.GetPlayer()
	string why = PairBlockedReason(npc, player)
	if why != ""
		return why
	endif
	Actor[] pair = OActorUtil.ToArray(player, npc)
	if !OActor.VerifyActors(pair)
		return "OStim does not accept these actors"
	endif
	why = PrivacyBlockedReason(npc, player, asParam)
	if why != ""
		return why
	endif
	; [0.4 / OWNER ADDENDA 6] Can he actually pay what was agreed? Checked BEFORE the transient
	; "still moving into the previous position" below, so an unaffordable start is refused outright and
	; never occupies the single queue slot. The reason string is a contract: the server maps it onto
	; her next turn's "the player could not actually pay" line (PROTOCOL 1.6).
	int pay = m.ParamGet(asParam, "pay") as int
	if pay > 0
		Form g = Gold()
		if g == None || player.GetItemCount(g) < pay
			return "the player does not have that much gold"
		endif
	endif
	; v0.3.1 fix pass: the one TRANSIENT reason comes last. It is the only one CmdStart queues on,
	; and a start that has to be refused outright (wrong actors, not adults, someone watching) must
	; never sit in the single queue slot while the real second command waits behind it.
	if pendingVerb != ""
		return "still moving into the previous position" ; something is waiting for her line
	endif
	return ""
EndFunction

; ---------------------------------------------------------------------------
; CLOTHING (command from CHIM)
; param: ok=1;cid=<id>;npc=<name>;do=<undress|dress>;who=<npc|player|both>;
;        part=<all|body|head|hands|feet>   (optional maxwit / folok are honoured outside a scene)
; In a scene with that NPC: OStim's own OActor.Undress / Redress (part=all) or UndressPartial /
; RedressPartial (a part). Outside a scene (OActor natives do nothing there): the full hard
; gates again, then the glue's own slot strip; what it took off is remembered and put back on
; "dress", at scene end or when the conversation ends.
; Contract masks: body 0x4, head 0x1003, hands 0x18, feet 0x180. Refinement: for head / hands /
; feet an item that ALSO covers the body slot (a robe with sleeves) is left alone - asking for
; the gloves must not take the dress off.
; ---------------------------------------------------------------------------
int Function SlotBit(int aiIdx)
	if aiIdx == 0
		return 0x00000004 ; body
	elseif aiIdx == 1
		return 0x00000001 ; head
	elseif aiIdx == 2
		return 0x00000002 ; hair / hood
	elseif aiIdx == 3
		return 0x00001000 ; circlet
	elseif aiIdx == 4
		return 0x00000008 ; hands
	elseif aiIdx == 5
		return 0x00000010 ; forearms
	elseif aiIdx == 6
		return 0x00000080 ; feet
	endif
	return 0x00000100 ; calves
EndFunction

bool Function IsPart(string asPart)
	return asPart == "all" || asPart == "body" || asPart == "head" || asPart == "hands" || asPart == "feet"
EndFunction

int Function PartFirst(string asPart)
	if asPart == "head"
		return 1
	elseif asPart == "hands"
		return 4
	elseif asPart == "feet"
		return 6
	endif
	return 0 ; body, all
EndFunction

int Function PartLast(string asPart)
	if asPart == "body"
		return 0
	elseif asPart == "head"
		return 3
	elseif asPart == "hands"
		return 5
	endif
	return 7 ; feet, all
EndFunction

int Function PartFullMask(string asPart)
	if asPart == "body"
		return 0x00000004
	elseif asPart == "head"
		return 0x00001003
	elseif asPart == "hands"
		return 0x00000018
	elseif asPart == "feet"
		return 0x00000180
	endif
	return 0x0000119F
EndFunction

bool Function CoversBody(Form akForm)
	Armor ar = akForm as Armor
	if !ar
		return false
	endif
	return Math.LogicalAnd(ar.GetSlotMask(), 0x00000004) != 0
EndFunction

Function Remember(Form[] akStore, Form akForm)
	int free = -1
	int i = 0
	while i < akStore.Length
		if akStore[i] == akForm
			return
		endif
		if akStore[i] == None && free < 0
			free = i
		endif
		i += 1
	endwhile
	if free >= 0
		akStore[free] = akForm
	endif
EndFunction

int Function StripPart(Actor akActor, Form[] akStore, string asPart)
	{The glue's own strip (outside a scene). Returns the number of items taken off.}
	int count = 0
	int i = PartFirst(asPart)
	int last = PartLast(asPart)
	while i <= last
		Form f = akActor.GetWornForm(SlotBit(i))
		if f
			if i == 0 || asPart == "all" || !CoversBody(f)
				akActor.UnequipItem(f, false, true)
				Remember(akStore, f)
				count += 1
			endif
		endif
		i += 1
	endwhile
	return count
EndFunction

int Function DressPart(Actor akActor, Form[] akStore, string asPart)
	{Puts back remembered items that belong to the part ("all" = everything).}
	int count = 0
	int mask = PartFullMask(asPart)
	int i = 0
	while i < akStore.Length
		Form f = akStore[i]
		if f
			bool mine = (asPart == "all")
			if !mine
				Armor ar = f as Armor
				if ar
					int sm = ar.GetSlotMask()
					mine = Math.LogicalAnd(sm, mask) != 0
					if mine && asPart != "body" && Math.LogicalAnd(sm, 0x00000004) != 0
						mine = false ; the robe comes back with "body", not with "hands"
					endif
				endif
			endif
			if mine
				if akActor && !akActor.IsDead() && akActor.GetItemCount(f) > 0
					akActor.EquipItem(f, false, true)
					count += 1
				endif
				akStore[i] = None
			endif
		endif
		i += 1
	endwhile
	return count
EndFunction

int Function ScenePartMask(Actor akActor, string asPart)
	{Slot mask for OActor.UndressPartial: the contract mask minus slots whose item also covers the body.}
	if asPart == "body"
		return 0x00000004
	endif
	int mask = 0
	int i = PartFirst(asPart)
	int last = PartLast(asPart)
	while i <= last
		Form f = akActor.GetWornForm(SlotBit(i))
		if f
			if !CoversBody(f)
				mask += SlotBit(i)
			endif
		endif
		i += 1
	endwhile
	return mask
EndFunction

int Function ScenePartWorn(Actor akActor, string asPart)
	{1 = something is on that part right now, 0 = it is already bare. GetWornForm answers for a
	 whole mask at once, so this is one native per call ("all" is four, through WornBits).}
	if akActor == None
		return 0
	endif
	if asPart == "all"
		if WornBits(akActor) != 0
			return 1
		endif
		return 0
	endif
	if akActor.GetWornForm(PartFullMask(asPart))
		return 1
	endif
	return 0
EndFunction

int Function SceneUndress(Actor akActor, string asPart)
	{Returns 1 when OStim was really asked to take something off, 0 when there was nothing there.
	 A request that changes nothing must NOT be answered as a success: the server counts a success
	 as "carried out" (G1's safety-net bookkeeping), so a false success is worse than a refusal.}
	if ScenePartWorn(akActor, asPart) == 0
		return 0
	endif
	if asPart == "all"
		OActor.Undress(akActor)
		return 1
	endif
	int mask = ScenePartMask(akActor, asPart)
	if mask == 0
		return 0 ; the only thing on that part is an item that covers the body: not ours to pull
	endif
	OActor.UndressPartial(akActor, mask)
	return 1
EndFunction

int Function ScenePartFree(Actor akActor, string asPart)
	{How many of the part's slots have nothing on them right now: 0 means a redress has nothing
	 left to put back there.}
	if akActor == None
		return 0
	endif
	int free = 0
	int i = PartFirst(asPart)
	int last = PartLast(asPart)
	while i <= last
		if !akActor.GetWornForm(SlotBit(i))
			free += 1
		endif
		i += 1
	endwhile
	return free
EndFunction

int Function SceneRedress(Actor akActor, string asPart)
	;/Returns the number of empty slots the redress could fill - 0 = she is already dressed there.
	 The native is called either way, because OStim also restores items outside the eight slots
	 this script knows about; only the ANSWER changes, so a "get dressed" for somebody who already
	 is gets the corner note instead of a false success. Deliberately conservative: an actor who
	 simply owns no boots reads as "a slot is empty" and reports success exactly as before./;
	int freeSlots = ScenePartFree(akActor, asPart)
	if asPart == "all"
		OActor.Redress(akActor)
	else
		OActor.RedressPartial(akActor, PartFullMask(asPart))
	endif
	return freeSlots
EndFunction

int Function WornBits(Actor akActor)
	{1 body, 2 head, 4 hands, 8 feet: which parts have anything on right now (4 natives;
	 GetWornForm matches any overlap with the mask).}
	int bits = 0
	if akActor.GetWornForm(0x00000004)
		bits += 1
	endif
	if akActor.GetWornForm(0x00001003)
		bits += 2
	endif
	if akActor.GetWornForm(0x00000018)
		bits += 4
	endif
	if akActor.GetWornForm(0x00000180)
		bits += 8
	endif
	return bits
EndFunction

string Function OffParts(int aiBase, int aiNow)
	{lrg_scene undp: "all" when nothing is worn on any of the four parts, else the parts that
	 were on when the scene began and are off now (a bare body always counts).}
	if aiNow == 0
		return "all"
	endif
	string out = ""
	if Math.LogicalAnd(aiNow, 1) == 0
		out = "body"
	endif
	if Math.LogicalAnd(aiBase, 2) != 0 && Math.LogicalAnd(aiNow, 2) == 0
		if out != ""
			out += ","
		endif
		out += "head"
	endif
	if Math.LogicalAnd(aiBase, 4) != 0 && Math.LogicalAnd(aiNow, 4) == 0
		if out != ""
			out += ","
		endif
		out += "hands"
	endif
	if Math.LogicalAnd(aiBase, 8) != 0 && Math.LogicalAnd(aiNow, 8) == 0
		if out != ""
			out += ","
		endif
		out += "feet"
	endif
	return out
EndFunction

Function CaptureBaseline(Actor akPlayer, Actor akNpc)
	{What both wear when a glue-started scene begins (16 natives, once per scene).}
	EnsureArrays()
	int i = 0
	while i < 8
		baseForms[i] = akPlayer.GetWornForm(SlotBit(i))
		baseForms[8 + i] = akNpc.GetWornForm(SlotBit(i))
		i += 1
	endwhile
	baseNpc = akNpc
	baseValid = true
	baseBitsNpc = 0
	if baseForms[8]
		baseBitsNpc += 1
	endif
	if baseForms[9] || baseForms[10] || baseForms[11]
		baseBitsNpc += 2
	endif
	if baseForms[12] || baseForms[13]
		baseBitsNpc += 4
	endif
	if baseForms[14] || baseForms[15]
		baseBitsNpc += 8
	endif
EndFunction

Function RedressBaseline(string asCid)
	{End of a glue-started scene: whatever either of you wore at the start and is still off is
	 put back on, whatever OStim's own redress setting says. No-op for items already back on.}
	if !baseValid || baseForms.Length != 16
		return
	endif
	baseValid = false
	Actor player = Game.GetPlayer()
	int n = 0
	int i = 0
	while i < 16
		Form f = baseForms[i]
		if f
			Actor a = player
			if i >= 8
				a = baseNpc
			endif
			if a
				if !a.IsDead() && a.GetItemCount(f) > 0 && !a.IsEquipped(f)
					a.EquipItem(f, false, true)
					n += 1
				endif
			endif
			baseForms[i] = None
		endif
		i += 1
	endwhile
	baseNpc = None
	if n > 0
		Main().LogC(asCid, "re-dressed " + n + " item(s) after the scene", "")
	endif
EndFunction

Function RedressRemembered(string asWhy)
	{Puts back whatever the glue itself took off (not what OStim undressed: OStim redresses that).}
	if cloNpcItems.Length == 0
		return
	endif
	int n = 0
	if cloNpc
		n += DressPart(cloNpc, cloNpcItems, "all")
	endif
	n += DressPart(Game.GetPlayer(), cloPlayerItems, "all")
	cloNpc = None
	if n > 0
		Main().Log("re-dressed " + n + " remembered item(s): " + asWhy)
	endif
EndFunction

bool Function HoldsPlayerClothes()
	int i = 0
	while i < cloPlayerItems.Length
		if cloPlayerItems[i]
			return true
		endif
		i += 1
	endwhile
	return false
EndFunction

Function OnWatchEnded()
	{The player turned away from / left the NPC: nobody stays undressed by the glue.}
	if ostimPresent
		if starting || OThread.IsRunning(0)
			return
		endif
	endif
	RedressRemembered("the conversation ended")
EndFunction

Function CmdClothing(string asNpcName, string asCommand, string asParam)
	LRG_Main m = Main()
	string cid = m.ParamGet(asParam, "cid")
	string what = m.ParamGet(asParam, "do")
	string who = m.ParamGet(asParam, "who")
	string part = m.ParamGet(asParam, "part")
	if who == ""
		who = "npc"
	endif
	if part == ""
		part = "all"
	endif
	if m.ParamGet(asParam, "ok") != "1"
		m.ReportResult(asNpcName, asCommand, asParam, "Error: not authorised by the server gate")
		return
	endif
	if (what != "undress" && what != "dress") || (who != "npc" && who != "player" && who != "both") || !IsPart(part)
		m.ReportResult(asNpcName, asCommand, asParam, "Error: unknown clothing request")
		return
	endif
	if !m.IsIntimacyEnabled()
		m.ReportResult(asNpcName, asCommand, asParam, "Error: the feature is switched off")
		return
	endif
	bool doNpc = who != "player"
	bool doPlayer = who != "npc"
	Actor player = Game.GetPlayer()
	; in a running scene the partner is the actor this script already holds: getAgentByName can
	; come back empty for an NPC CHIM has stopped driving mid-scene, and then a command meant for
	; the woman in the scene died as "the actor could not be found".
	Actor npc = None
	if partner != None && ostimPresent && IsPartnerName(asNpcName)
		if OThread.IsRunning(0) || starting
			npc = partner
		endif
	endif
	if npc == None
		npc = AIAgentFunctions.getAgentByName(asNpcName)
	endif
	if !npc || npc == player || npc.IsDead()
		m.ReportResult(asNpcName, asCommand, asParam, "Error: the actor could not be found")
		return
	endif
	if !LRG_Profile.IsAdult(npc) || !LRG_Profile.IsAdult(player)
		m.ReportResult(asNpcName, asCommand, asParam, "Error: adults only")
		return
	endif
	; v0.3.1 (w2): both of these mean the command before this one - from the same reply - is still
	; being carried out ("start, and then take my clothes off"). Queue it, do not lose it.
	if starting
		if DeferCommand(asNpcName, asCommand, asParam, cid, "a scene is just starting")
			return
		endif
		m.ReportResult(asNpcName, asCommand, asParam, "Error: a scene is just starting")
		return
	endif
	if pendingVerb != ""
		if DeferCommand(asNpcName, asCommand, asParam, cid, "still moving into the previous position")
			return
		endif
		m.ReportResult(asNpcName, asCommand, asParam, "Error: still moving into the previous position")
		return
	endif
	bool inScene = false
	if ostimPresent
		if OThread.IsRunning(0)
			inScene = OActor.IsInOStim(npc) && OActor.IsInOStim(player)
			if !inScene
				m.ReportResult(asNpcName, asCommand, asParam, "Error: a scene with someone else is running")
				return
			endif
		endif
	endif
	if !inScene && what == "undress" && sceneEndedAt > 0.0
		; the scene ended a moment ago and this command belongs to it: the privacy branch below
		; would answer "someone is watching" for a request that was perfectly fine in the scene
		float sinceEnd = Utility.GetCurrentRealTime() - sceneEndedAt
		if sinceEnd >= 0.0 && sinceEnd < 2.0
			m.ReportResult(asNpcName, asCommand, asParam, "Error: no scene is running")
			return
		endif
	endif
	if !inScene && what == "undress"
		; outside a scene undressing needs everything a scene start needs - including the
		; [0.4 fix pass, D4] dialogue gate: never strip somebody while a hidden conversation is live
		string why = ""
		if DialogueBusy(m)
			why = "a conversation is in progress"
		endif
		if why == ""
			why = PairBlockedReason(npc, player)
		endif
		if why == ""
			why = PrivacyBlockedReason(npc, player, asParam)
		endif
		if why != ""
			m.LogC(cid, "clothing refused reason=" + why, asNpcName)
			m.ReportResult(asNpcName, asCommand, asParam, "Error: " + why)
			return
		endif
	endif
	if m.IsDryRun()
		m.LogC(cid, "DRY RUN WOULD " + what + " who=" + who + " part=" + part + " inscene=" + LRG_Profile.B2I(inScene), asNpcName)
		m.ReportResult(asNpcName, asCommand, asParam, "Error: dry-run mode is ON in the LoreRim Glue MCM (Diagnostics page) - nothing changed")
		return
	endif

	float now = Utility.GetCurrentRealTime()
	ApplyHold(asParam) ; v0.3: the player steered - no scene-lead turn for the next hold= seconds
	lastActivity = now
	lastTalkTime = now ; she spoke in the same turn: no scene talk on top
	; a change she chose herself waits for her line (wait=begin); anything the player asked for
	; carries no wait= at all and happens now, as it always did
	if !ParkVerb("clothing", asNpcName, asCommand, asParam, cid, now, inScene, npc, false, None)
		PerformClothing(asNpcName, asCommand, asParam, cid, Utility.GetCurrentRealTime(), what, who, part, inScene, npc)
	endif
EndFunction

string Function ClothingNothingSay(string asWho, string asPart, bool abUndress)
	{[0.5.7] Her words when a clothing command changed nothing: there was nothing left to take off / put back on.}
	string whose = "I have"
	if asWho == "player"
		whose = "you have"
	elseif asWho == "both"
		whose = "neither of us has"
	endif
	string where = ""
	if asPart != "all" && asPart != ""
		where = " there"
	endif
	if abUndress
		if asWho == "both"
			return whose + " anything left to take off" + where
		endif
		return whose + " nothing left to take off" + where
	endif
	if asWho == "both"
		return whose + " anything to put back on" + where
	endif
	return whose + " nothing to put back on" + where
EndFunction

Function PerformClothing(string asNpcName, string asCommand, string asParam, string asCid, float afNow, string asWhat, string asWho, string asPart, bool abInScene, Actor akNpc)
	{The half of the clothing verb that touches OStim / the inventory (see PerformGoto). Every
	 check has already run at command time.}
	LRG_Main m = Main()
	string what = asWhat
	string who = asWho
	string part = asPart
	bool inScene = abInScene
	Actor npc = akNpc
	Actor player = Game.GetPlayer()
	string cid = asCid
	float now = afNow
	bool doNpc = who != "player"
	bool doPlayer = who != "npc"
	EnsureArrays()
	; How many slots this command really touched. 0 on every requested actor means the request
	; changed nothing at all (she was already bare / already dressed there): that is answered with
	; a closed-list reason, never with a success - the server treats a success as "carried out".
	int touched = 0
	if what == "undress"
		if inScene
			if doNpc
				touched += SceneUndress(npc, part)
				NoteSceneStrip(npc, part, true)
			endif
			if doPlayer
				touched += SceneUndress(player, part)
				NoteSceneStrip(player, part, true)
			endif
		else
			if doNpc
				if cloNpc && cloNpc != npc
					RedressRemembered("another NPC undresses") ; one remembered NPC at a time
				endif
				cloNpc = npc
				touched += StripPart(npc, cloNpcItems, part)
			endif
			if doPlayer
				touched += StripPart(player, cloPlayerItems, part)
				m.RequestTick(5.0) ; cold exemption while the glue holds the player's clothes
			endif
		endif
		if touched == 0
			m.LogC(cid, "undress changed nothing who=" + who + " part=" + part + " inscene=" + LRG_Profile.B2I(inScene), asNpcName)
			m.ReportError(asNpcName, asCommand, asParam, ClothingNothingSay(who, part, true), "not possible in this position")
			return
		endif
		m.LogC(cid, "undress done who=" + who + " part=" + part + " inscene=" + LRG_Profile.B2I(inScene), asNpcName)
		if who == "both"
			m.ReportResult(asNpcName, asCommand, asParam, "OK: " + asNpcName + " undresses, and so do you.")
		elseif who == "player"
			m.ReportResult(asNpcName, asCommand, asParam, "OK: You undress.")
		else
			m.ReportResult(asNpcName, asCommand, asParam, "OK: " + asNpcName + " undresses.")
		endif
	else
		if inScene
			if doNpc
				touched += SceneRedress(npc, part)
				NoteSceneStrip(npc, part, false)
			endif
			if doPlayer
				touched += SceneRedress(player, part)
				NoteSceneStrip(player, part, false)
			endif
		endif
		; whatever the glue itself took off before (outside a scene) comes back the same way
		if doNpc && cloNpc == npc
			touched += DressPart(npc, cloNpcItems, part)
		endif
		if doPlayer
			touched += DressPart(player, cloPlayerItems, part)
		endif
		if touched == 0
			m.LogC(cid, "dress changed nothing who=" + who + " part=" + part + " inscene=" + LRG_Profile.B2I(inScene), asNpcName)
			m.ReportError(asNpcName, asCommand, asParam, ClothingNothingSay(who, part, false), "not possible in this position")
			return
		endif
		m.LogC(cid, "dress done who=" + who + " part=" + part + " inscene=" + LRG_Profile.B2I(inScene), asNpcName)
		if who == "both"
			m.ReportResult(asNpcName, asCommand, asParam, "OK: Both of you get dressed again.")
		elseif who == "player"
			m.ReportResult(asNpcName, asCommand, asParam, "OK: You get dressed again.")
		else
			m.ReportResult(asNpcName, asCommand, asParam, "OK: " + asNpcName + " gets dressed again.")
		endif
	endif
	if inScene
		MarkDirty("change", true) ; the server learns und= / undp= with the next state push
		stateRecheckAt = now + 3.0 ; equipment changes are not instant: look once more
	endif
EndFunction

; ---------------------------------------------------------------------------
; CONTROL (command from CHIM)
; param: ok=1;cid=<id>;npc=<name>;do=<verb>;...
;   goto      scene=<id>[;warp=1]
;   faster | slower | speed;speed=<n>
;   stop                                   (always, also in dry-run, also mid wind-down)
;   hold | release
;   climax    who=<npc|player|both>
;   pullout
;   winddown  scene=<afterglow id or empty>;warp=<0|1>;linger=<seconds>
;   furniture furn=<type>;scene=<id>       (the server ALWAYS names the scene)
;   lead      who=<npc|player|auto>
; ---------------------------------------------------------------------------
Function CmdControl(string asNpcName, string asCommand, string asParam)
	LRG_Main m = Main()
	string cid = m.ParamGet(asParam, "cid")
	string what = m.ParamGet(asParam, "do")
	; v0.3.1 fix pass, dormant in the same way as the note= key in LRG_Main.HandleCommand: a verb
	; that does nothing but put a line in the corner, for the case where the server has ruled an act
	; impossible and has no other command to hang the note on. It needs no scene and no partner,
	; changes nothing in the game, and nothing sends it yet.
	if what == "note"
		if m.ParamGet(asParam, "ok") != "1"
			m.ReportResult(asNpcName, asCommand, asParam, "Error: not authorised by the server gate")
			return
		endif
		string noteText = m.ParamGet(asParam, "text")
		if noteText != "" && m.ParamGet(asParam, "note") == "" ; note= was shown in HandleCommand already
			Debug.Notification("LoreRim Glue: " + LRG_Profile.Clip(noteText, 120))
		endif
		m.ReportResult(asNpcName, asCommand, asParam, "OK: Noted.")
		return
	endif
	if what == "stop" && starting && m.ParamGet(asParam, "ok") == "1"
		; "stop" while OStim is still building the thread - or while a start is parked waiting for
		; her line (v0.3): end it the moment it exists, and never wait for anything first
		abortStart = true
		DropPendingVerb("no scene is running")
		m.LogC(cid, "stop during start: the scene will be ended as soon as it begins", asNpcName)
		m.ReportResult(asNpcName, asCommand, asParam, "OK: The scene ends.")
		m.RequestTick(0.2)
		return
	endif
	if !ostimPresent || !OThread.IsRunning(0)
		; v0.3.1 (w2): a start from the SAME reply may still be building the thread ("start and then
		; put me on all fours"). The gate check comes first, so nothing unauthorised is ever queued.
		; v0.3.1 fix pass: and only for the NPC whose scene is being built, for the same reason as
		; below - the one queue slot belongs to the real partner's second command
		if ostimPresent && starting && m.ParamGet(asParam, "ok") == "1" && IsPartnerName(asNpcName)
			if partner != None && LRG_Profile.IsAdult(partner) && LRG_Profile.IsAdult(Game.GetPlayer())
				if DeferCommand(asNpcName, asCommand, asParam, cid, "the scene is still starting")
					return
				endif
			endif
		endif
		m.ReportResult(asNpcName, asCommand, asParam, "Error: no scene is running")
		return
	endif
	if m.ParamGet(asParam, "ok") != "1"
		m.ReportResult(asNpcName, asCommand, asParam, "Error: not authorised by the server gate")
		return
	endif
	float now = Utility.GetCurrentRealTime()

	; STOP first: nothing below may ever stand between the player and the end of the scene.
	; It is never waited on and it cancels anything that is waiting.
	if what == "stop"
		windDown = false
		wdWaitLanding = false
		DropPendingVerb("no scene is running")
		StopScene("conversation cid=" + cid)
		m.ReportResult(asNpcName, asCommand, asParam, "OK: The scene ends.")
		return
	endif

	if !IsPartnerName(asNpcName)
		m.ReportResult(asNpcName, asCommand, asParam, "Error: a scene with someone else is running")
		return
	endif
	; defence in depth: every hard gate is re-checked in the game, this one on every verb but stop
	if partner == None || !LRG_Profile.IsAdult(partner) || !LRG_Profile.IsAdult(Game.GetPlayer())
		m.ReportResult(asNpcName, asCommand, asParam, "Error: adults only")
		return
	endif

	; exactly one command may be parked for her line at a time.
	; v0.3.1 fix pass: this test comes AFTER the partner and adults-only re-checks. It used to come
	; before them, so a command that has to be refused anyway took the single queue slot and blocked
	; the real partner's second command for up to fQueueWait seconds - the very failure w2 exists to
	; remove. No gate is skipped by queueing (the retry re-runs this whole chain from the top).
	if pendingVerb != ""
		; v0.3.1 (w2): the command before this one is waiting for her spoken line - queue, do not lose
		if DeferCommand(asNpcName, asCommand, asParam, cid, "still moving into the previous position")
			return
		endif
		m.ReportResult(asNpcName, asCommand, asParam, "Error: still moving into the previous position")
		return
	endif
	lastTalkTime = now ; she spoke in the same turn: no scene talk on top
	lastActivity = now
	if m.IsDryRun()
		m.LogC(cid, "DRY RUN WOULD " + what + " " + asParam, asNpcName)
		m.ReportResult(asNpcName, asCommand, asParam, "Error: dry-run mode is ON in the LoreRim Glue MCM (Diagnostics page) - nothing changed")
		return
	endif
	; v0.3: the player steered - no scene-lead turn for the next hold= seconds. Applied only once
	; every refusal above is behind us: a command answered with "a scene with someone else is
	; running", "adults only" or "dry-run mode" changed nothing, and it must not silence her lead
	; for up to 600 s either. "stop" returns above and needs no hold at all.
	ApplyHold(asParam)

	if what == "faster" || what == "slower" || what == "speed"
		string cur = OThread.GetScene(0)
		if cur == ""
			; during thread construction GetSpeed returns -1, so "faster" would silently
			; clamp to the minimum and report success
			m.ReportResult(asNpcName, asCommand, asParam, "Error: the scene is still starting")
			return
		endif
		int speed = OThread.GetSpeed(0)
		if what == "faster"
			speed += 1
		elseif what == "slower"
			speed -= 1
		else
			speed = m.ParamGet(asParam, "speed") as int
		endif
		int maxSpeed = OMetadata.GetMaxSpeed(cur)
		if speed > maxSpeed
			speed = maxSpeed
		endif
		if speed < 0
			speed = 0
		endif
		OThread.SetSpeed(0, speed)
		m.ReportResult(asNpcName, asCommand, asParam, "OK: The pace changes.")
	elseif what == "hold"
		OThread.StallClimax(0)
		MarkDirty("change", true)
		m.ReportResult(asNpcName, asCommand, asParam, "OK: Holding back.")
	elseif what == "release"
		OThread.PermitClimax(0, true)
		MarkDirty("change", true)
		m.ReportResult(asNpcName, asCommand, asParam, "OK: No longer holding back.")
	elseif what == "goto"
		string target = m.ParamGet(asParam, "scene")
		string why = NavigateBlockedReason(target)
		bool useWarp = false
		if why == "unreachable"
			; "if I say a position it should go to that position": the server's route oracle and
			; OStim's live one are not nested sets, so a disagreement must not end in a refusal.
			; warp= from the server is only a hint; the MCM toggle is the authority.
			; nowarp=1 (v0.3) is the exception: a move SHE chose herself never fades out, so a
			; position she cannot reach is simply answered as unreachable. A missing key means
			; exactly today's behaviour, so an old server loses nothing.
			if m.ParamGet(asParam, "nowarp") == "1"
				why = "that position cannot be reached from here"
			elseif m.SettingBool("bAllowWarp:Intimacy", true)
				useWarp = true
				why = ""
			else
				why = "that position cannot be reached from here"
			endif
		endif
		if why != ""
			; v0.3.1 (w2): "something of ours is still running" is not a refusal, it is a moment -
			; queue the move and try again a second later ("...and then take me to the bed").
			if why == "still moving into the previous position" || why == "the scene is still starting" || why == "in the middle of a transition"
				if DeferCommand(asNpcName, asCommand, asParam, cid, why)
					return
				endif
			endif
			m.LogC(cid, "navigate refused target=" + target + " reason=" + why, asNpcName)
			m.ReportResult(asNpcName, asCommand, asParam, "Error: " + why)
			return
		endif
		; every check has passed: from here it either happens now or as soon as she has spoken
		if !ParkVerb("goto", asNpcName, asCommand, asParam, cid, now, true, partner, useWarp, None)
			PerformGoto(asNpcName, asCommand, asParam, cid, Utility.GetCurrentRealTime(), target, useWarp)
		endif
	elseif what == "climax"
		CmdClimax(asNpcName, asCommand, asParam, cid)
	elseif what == "pullout"
		CmdPullOut(asNpcName, asCommand, asParam, cid, now)
	elseif what == "winddown"
		CmdWindDown(asNpcName, asCommand, asParam, cid, now)
	elseif what == "furniture"
		CmdFurniture(asNpcName, asCommand, asParam, cid, now)
	elseif what == "lead"
		CmdLead(asNpcName, asCommand, asParam, cid)
	else
		m.ReportResult(asNpcName, asCommand, asParam, "Error: unknown scene request")
	endif
EndFunction

Function PerformGoto(string asNpcName, string asCommand, string asParam, string asCid, float afNow, string asTarget, bool abWarp)
	{The half of the goto verb that touches OStim. Reached straight from CmdControl, or from Tick
	 when the command waited for her spoken line: the result is reported when the call really
	 happens, so the funcret always describes what the game did.}
	CancelWindDown("a new position was asked for")
	GoToScene(asTarget, abWarp, afNow, asCid)
	Main().ReportResult(asNpcName, asCommand, asParam, "OK: Moving into a new position.")
	Main().RequestTick(2.0)
EndFunction

Function GoToScene(string asTarget, bool abWarp, float afNow, string asCid)
	navInFlight = true
	navTarget = asTarget
	if abWarp
		navDeadline = afNow + 10.0
		OThread.WarpTo(0, asTarget, true)
		Main().LogC(asCid, "warp -> " + asTarget, partnerName)
	else
		navDeadline = afNow + 25.0
		OThread.NavigateTo(0, asTarget)
		Main().LogC(asCid, "navigate -> " + asTarget, partnerName)
	endif
EndFunction

Function CmdClimax(string asNpcName, string asCommand, string asParam, string asCid)
	LRG_Main m = Main()
	string who = m.ParamGet(asParam, "who")
	if who == ""
		who = "npc"
	endif
	if who != "npc" && who != "player" && who != "both"
		m.ReportResult(asNpcName, asCommand, asParam, "Error: unknown scene request")
		return
	endif
	Actor player = Game.GetPlayer()
	if who != "player"
		if partner == None
			m.ReportResult(asNpcName, asCommand, asParam, "Error: the actor could not be found")
			return
		endif
		if !OActor.IsInOStim(partner)
			m.ReportResult(asNpcName, asCommand, asParam, "Error: the actor could not be found")
			return
		endif
	endif
	CancelWindDown("a peak was asked for")
	OThread.PermitClimax(0, true) ; a held-back peak is released first
	if who != "player"
		OActor.Climax(partner, false)
	endif
	if who != "npc"
		OActor.Climax(player, false)
	endif
	MarkDirty("change", true) ; stall= changed; the orgasm event itself pushes ev=climax
	m.ReportResult(asNpcName, asCommand, asParam, "OK: A peak is reached.")
EndFunction

Function CmdPullOut(string asNpcName, string asCommand, string asParam, string asCid, float afNow)
	LRG_Main m = Main()
	string cur = OThread.GetScene(0)
	string why = ""
	if cur == ""
		why = "the scene is still starting"
	elseif navInFlight
		why = "still moving into the previous position"
	elseif OMetadata.IsTransition(cur)
		why = "in the middle of a transition"
	endif
	if why != ""
		m.ReportResult(asNpcName, asCommand, asParam, "Error: " + why)
		return
	endif
	; the scene-level transition first, then the per-actor ones (scenes define either)
	bool ok = OThread.AutoTransition(0, "pullout")
	if !ok
		Actor[] actors = OThread.GetActors(0)
		int i = 0
		while i < actors.Length && !ok
			ok = OThread.AutoTransitionForActor(0, i, "pullout")
			i += 1
		endwhile
	endif
	if !ok
		m.LogC(asCid, "pullout: scene " + cur + " defines no such transition", asNpcName)
		m.ReportResult(asNpcName, asCommand, asParam, "Error: not possible in this position")
		return
	endif
	navInFlight = true ; a scene change the glue asked for: it does not cancel a wind-down
	navTarget = ""
	navDeadline = afNow + 15.0
	m.ReportResult(asNpcName, asCommand, asParam, "OK: They ease apart.")
	m.RequestTick(2.0)
EndFunction

Function CmdWindDown(string asNpcName, string asCommand, string asParam, string asCid, float afNow)
	{Its own verb: move to the afterglow scene the server picked (if allowed), slow right down,
	 linger, then end. Cancelled by a later goto / furniture / climax / lead or by a scene change
	 the glue did not ask for. "stop" still ends everything at once.}
	LRG_Main m = Main()
	float linger = m.ParamGet(asParam, "linger") as float
	if linger <= 0.0
		linger = 20.0
	endif
	if linger < 5.0
		linger = 5.0
	elseif linger > 120.0
		linger = 120.0
	endif
	if OThread.IsInAutoMode(0)
		OThread.StopAutoMode(0) ; OStim's auto mode would navigate away again
	endif
	lastAuto = false
	if leader == "auto"
		leader = DefaultLeader()
	endif
	windDown = true
	wdLinger = linger
	wdCid = asCid
	wdSlowAgain = false

	; a position change that is already on its way is waited for, not overridden
	bool moving = navInFlight
	string target = m.ParamGet(asParam, "scene")
	if target != "" && !moving
		string why = NavigateBlockedReason(target)
		bool useWarp = false
		if why == "unreachable" && m.ParamGet(asParam, "warp") == "1" && m.SettingBool("bAllowWarp:Intimacy", true)
			useWarp = true
			why = ""
		endif
		if why == ""
			GoToScene(target, useWarp, afNow, asCid)
			moving = true
		else
			m.LogC(asCid, "wind-down stays in this position: " + why, asNpcName)
		endif
	endif
	OThread.SetSpeed(0, 0)
	wdWaitLanding = moving
	if !moving
		wdStopAt = afNow + linger
	endif
	m.LogC(asCid, "wind-down begins linger=" + (linger as int) + " moving=" + LRG_Profile.B2I(moving), asNpcName)
	if dirty && dirtyEvent != "start"
		dirty = false ; the push below carries everything a pending "change" would
	endif
	PushState("winddown", "") ; sent once, right now
	m.ReportResult(asNpcName, asCommand, asParam, "OK: Winding down.")
	if moving
		m.RequestTick(2.0)
	else
		m.RequestTick(linger)
	endif
EndFunction

Function WindDownLanded(float afNow)
	wdWaitLanding = false
	wdSlowAgain = true ; the new scene came up at its own default speed
	OThread.SetSpeed(0, 0)
	wdStopAt = afNow + wdLinger
	Main().LogC(wdCid, "wind-down landed, ending in " + (wdLinger as int) + " s", partnerName)
	Main().RequestTick(wdLinger)
EndFunction

Function CancelWindDown(string asWhy)
	if !windDown
		return
	endif
	windDown = false
	wdWaitLanding = false
	wdSlowAgain = false
	Main().LogC(wdCid, "wind-down cancelled: " + asWhy, partnerName)
	MarkDirty("change", false)
EndFunction

Function CmdFurniture(string asNpcName, string asCommand, string asParam, string asCid, float afNow)
	LRG_Main m = Main()
	if !m.SettingBool("bFurniture:Intimacy", true)
		m.ReportResult(asNpcName, asCommand, asParam, "Error: not available")
		return
	endif
	string furnType = m.ParamGet(asParam, "furn")
	string sceneId = m.ParamGet(asParam, "scene")
	; an empty scene id would let OStim pick any scene for that furniture and skip the ladder
	if furnType == "" || furnType == "none" || sceneId == ""
		m.ReportResult(asNpcName, asCommand, asParam, "Error: no suitable furniture nearby")
		return
	endif
	string cur = OThread.GetScene(0)
	string why = ""
	if cur == ""
		why = "the scene is still starting"
	elseif navInFlight
		why = "still moving into the previous position"
	elseif OMetadata.IsTransition(cur)
		why = "in the middle of a transition"
	elseif OMetadata.GetActorCount(sceneId) != 2
		why = "that position does not exist for the two of you"
	elseif OMetadata.IsTransition(sceneId)
		why = "that is not a position"
	endif
	if why != ""
		; v0.3.1 (w2): the same three transient reasons as the goto verb - the command before this
		; one is still being carried out, so this one waits instead of being lost
		if why == "still moving into the previous position" || why == "the scene is still starting" || why == "in the middle of a transition"
			if DeferCommand(asNpcName, asCommand, asParam, asCid, why)
				return
			endif
		endif
		m.LogC(asCid, "furniture refused reason=" + why, asNpcName)
		m.ReportResult(asNpcName, asCommand, asParam, "Error: " + why)
		return
	endif
	ObjectReference furnRef = FindFurnitureRef(furnType, Game.GetPlayer())
	if !furnRef
		m.LogC(asCid, "furniture refused: no free " + furnType + " in reach", asNpcName)
		m.ReportResult(asNpcName, asCommand, asParam, "Error: no suitable furniture nearby")
		return
	endif
	; nowarp= is accepted here for symmetry with goto and has nothing to gate: moving to furniture
	; is always OStim's own ChangeFurniture, which never fades a position in behind the player's back.
	if !ParkVerb("furniture", asNpcName, asCommand, asParam, asCid, afNow, true, partner, false, furnRef)
		PerformFurniture(asNpcName, asCommand, asParam, asCid, Utility.GetCurrentRealTime(), furnType, sceneId, furnRef)
	endif
EndFunction

Function PerformFurniture(string asNpcName, string asCommand, string asParam, string asCid, float afNow, string asFurnType, string asSceneId, ObjectReference akFurnRef)
	{The half of the furniture verb that touches OStim (see PerformGoto).}
	LRG_Main m = Main()
	if akFurnRef == None || akFurnRef.IsFurnitureInUse()
		m.LogC(asCid, "furniture refused: the " + asFurnType + " is no longer free", asNpcName)
		m.ReportResult(asNpcName, asCommand, asParam, "Error: no suitable furniture nearby")
		return
	endif
	CancelWindDown("moving to furniture")
	navInFlight = true
	navTarget = asSceneId
	navDeadline = afNow + 20.0
	OThread.ChangeFurniture(0, akFurnRef, asSceneId)
	m.LogC(asCid, "furniture -> " + asFurnType + " ref=" + akFurnRef.GetFormID() + " scene=" + asSceneId, asNpcName)
	m.ReportResult(asNpcName, asCommand, asParam, "OK: Moving to the " + asFurnType + ".")
	m.RequestTick(2.0)
EndFunction

Function CmdLead(string asNpcName, string asCommand, string asParam, string asCid)
	LRG_Main m = Main()
	string who = m.ParamGet(asParam, "who")
	if who == ""
		who = "npc"
	endif
	if who != "npc" && who != "player" && who != "auto"
		m.ReportResult(asNpcName, asCommand, asParam, "Error: unknown scene request")
		return
	endif
	if who == "npc"
		; "you lead" / "surprise me" hands the moment to her: whatever hold was running is over.
		; Only a command that carries NO hold= (or an explicit hold=0) is that hand-over. The §9
		; hold carrier - which the server sends on a player-speech turn that produced no other
		; command, naming the CURRENT leader - always carries a hold=, and CmdControl has already
		; applied it: clearing it here would silence her for the whole period instead of freeing
		; her (G2 inverted), and re-applying it afterwards broke the monotonic-hold guarantee
		; PROTOCOL §2 states for the carrier. So: no clear, no re-apply, nothing to re-order.
		string holdKey = m.ParamGet(asParam, "hold")
		if holdKey == "" || holdKey == "0"
			leadHoldUntil = 0.0
		endif
	endif
	; A wind-down is only cancelled by a lead that really CHANGES something. The carrier above is a
	; no-op that names the leader the scene already has, and it fires on any player speech - so an
	; unconditional cancel here meant the player could ask to wind down, say one word during the
	; linger, and the scene would never end.
	if who != leader
		CancelWindDown("the lead changed")
	endif
	if who == "auto"
		OThread.StartAutoMode(0)
		lastAuto = true
	else
		OThread.StopAutoMode(0)
		lastAuto = false
	endif
	leader = who
	MarkDirty("change", true)
	m.ReportResult(asNpcName, asCommand, asParam, "OK: Lead: " + who + ".")
EndFunction

string Function NavigateBlockedReason(string asTarget)
	{"" = routable, "unreachable" = valid target without a route (warp candidate), else a refusal.}
	if asTarget == ""
		return "no position was named"
	endif
	if navInFlight
		return "still moving into the previous position"
	endif
	string cur = OThread.GetScene(0)
	if cur == ""
		return "the scene is still starting"
	endif
	if OMetadata.IsTransition(cur)
		return "in the middle of a transition"
	endif
	if asTarget == cur
		return "already there"
	endif
	Actor[] actors = OThread.GetActors(0)
	if OMetadata.GetActorCount(asTarget) != actors.Length || actors.Length != 2
		return "that position does not exist for the two of you"
	endif
	if OMetadata.IsTransition(asTarget)
		return "that is not a position"
	endif
	; only NAVIGATE where OStim itself says these actors can be routed (explicit distance: the
	; default 0 may return nothing). Anything else would make NavigateTo warp without fades.
	int reach = Main().SettingInt("iNavigationReach:Intimacy", 5)
	string[] ok = OLibrary.GetScenesInRange(cur, actors, reach)
	if ok.Find(asTarget) < 0
		return "unreachable"
	endif
	return ""
EndFunction

Function StopScene(string asWhy)
	{Ends the player's scene at once - whoever started it, whatever state it is in. Nothing here
	 ever waits for a spoken line, and anything that is waiting is cancelled first.}
	; v0.3.1: the stop key is also the way out of the outro hold, and an end nobody else claimed
	; first was asked for by the player (how=stopped; the wind-down claims "finished" itself).
	ReleaseOutroHold("stop was asked for")
	; [0.4] the stop hotkey is also the way out of the listener hold. It must clear listenerUntil, or
	; the hold would outlive the thing it was armed for (the release below only runs while a thread is).
	if listenerUntil > 0.0
		ReleaseListener("stop was asked for")
	endif
	if endHow == ""
		endHow = "stopped"
	endif
	DropPendingVerb("no scene is running")
	if ostimPresent
		if OThread.IsRunning(0)
			Main().Log("stop scene: " + asWhy)
			ReleaseListener("the scene was stopped")
			OThread.Stop(0)
		elseif starting
			abortStart = true ; the stop key was pressed while OStim is still building the thread
			Main().Log("stop scene during start: " + asWhy)
			Main().RequestTick(0.2) ; a start parked for her line is dropped on the next tick
		endif
	endif
EndFunction

; ---------------------------------------------------------------------------
; OStim events (ThreadID 0 only)
; ---------------------------------------------------------------------------
Event OnOStimThreadStart(string asEventName, string asStrArg, float afThreadID, Form akSender)
	if (afThreadID as int) != 0
		return
	endif
	LRG_Main m = Main()
	float now = Utility.GetCurrentRealTime()
	ReleaseOutroHold("a new scene began") ; v0.3.1: never hold her into the next scene
	m.ReleaseConvHold("a scene began") ; [0.4.1] same reason, for the conversation hold
	if listenerUntil > 0.0
		ReleaseListener("a new scene began") ; [0.4] the hold of the previous scene, same reason
	endif
	ctlFixUntil = 0.0
	sceneStartedAt = now ; v0.3.1: dur= on ev=end, and the outro's "did it really run" test
	endHow = ""
	afterScene = ""
	afterAt = 0.0
	afterUntil = 0.0
	lastActivity = now
	windDown = false
	wdWaitLanding = false
	navInFlight = false
	; A start of ours is parked, waiting for her announcing line, and a thread began anyway (OStim's
	; own menu or another mod). Our start is over before it began: it must not be built on top of
	; this thread a moment later (OThreadBuilder.Create would answer -1 and the funcret would be a
	; second one for the same command), and the glue must track the thread that is really running.
	; Cleared BEFORE the branches below, so the else branch adopts it in the ordinary way.
	if pendingStart
		pendingStart = false
		pendingStartFurn = None
		pendingStartScene = ""
		pendingStartFurnType = ""
		starting = false
		string parkedWhy = "Error: a scene is already running"
		if abortStart
			parkedWhy = "Error: the scene did not start" ; the player had already asked for the stop
		endif
		abortStart = false
		m.LogC(startCid, "the parked start was dropped: another scene began while she was speaking", partnerName)
		m.ReportResult(partnerName, startCmd, startParam, parkedWhy)
	endif
	if starting
		starting = false
		if abortStart
			abortStart = false
			; the player cancelled it while OStim was still building: it never became a scene, so
			; it must not be reported as one (the stop itself was already answered "The scene ends.")
			m.ReportResult(partnerName, startCmd, startParam, "Error: the scene did not start")
			m.LogC(startCid, "ended at once: stop was asked for while the scene was starting", partnerName)
			OThread.Stop(0) ; the end event cleans up and tells the server
			return
		endif
		m.ReportResult(partnerName, startCmd, startParam, "OK: They draw close. The scene begins.")
		ForceListener("the scene began") ; the player talks to her, not to the Narrator
		leader = DefaultLeader()
		; OStim's own auto mode (if the owner has it on) would walk through positions by itself
		; and skip the step-by-step ladder: switch it off for this thread. "lead auto" turns it
		; back on later.
		if OThread.IsInAutoMode(0)
			OThread.StopAutoMode(0)
			m.LogC(startCid, "OStim auto mode stopped at start (lead=" + leader + ")", partnerName)
		endif
		lastAuto = false
		; v0.3.1 (w1): after=<id> is the position the PLAYER asked for when OStim could not start
		; the thread in it. The scene is up now; the move follows in a few seconds (TickAfterScene).
		string afterId = m.ParamGet(startParam, "after")
		if afterId != "" && afterId != OThread.GetScene(0)
			afterScene = afterId
			afterAt = now + 4.0
			afterUntil = now + 40.0
			m.LogC(startCid, "after=" + afterId + ": moving there in a few seconds", partnerName)
		endif
	else
		baseValid = false
		AdoptRunningThread() ; started from OStim's own UI or another mod: still be aware of it
	endif
	sceneNearf = ScanNearFurniture(Game.GetPlayer())
	lastPosScene = OThread.GetScene(0) ; the first position; prev= names it after the first change
	prevPosScene = ""
	; OStim's own mid-scene undressing is paused for a glue scene (crash 4): the glue does the
	; undressing of the very first node here, and of every later node in OnOStimSceneChanged
	GlueUndressNode(OThread.GetScene(0), "scene start")
	stateRecheckAt = now + 6.0 ; OStim may undress a moment after the start
	MarkDirty("start", true)
	m.RequestTick(5.0)
	; the server only gives scene awareness / scene actions to an NPC whose stored snapshot says
	; adult=1 (fail closed): make sure one exists, also for scenes started from OStim's own menu
	if partner
		m.MaybeSnapshot(partner, true)
	endif
EndEvent

Function AdoptRunningThread()
	Actor player = Game.GetPlayer()
	Actor[] actors = OThread.GetActors(0)
	partner = None
	int i = 0
	while i < actors.Length
		if actors[i] != player && partner == None
			partner = actors[i]
		endif
		i += 1
	endwhile
	if partner
		partnerName = partner.GetDisplayName()
		if Main().IsEnabled()
			AIAgentFunctions.setAnimationBusy(1, partnerName)
		endif
		baseBitsNpc = WornBits(partner)
		ForceListener("a running scene was adopted")
	endif
	lastPosScene = OThread.GetScene(0)
	prevPosScene = ""
	startedByGlue = false
	; a thread somebody put into auto mode is left alone: report it, send no lead ticks
	lastAuto = OThread.IsInAutoMode(0)
	if lastAuto
		leader = "auto"
	else
		leader = DefaultLeader()
	endif
	lastActivity = Utility.GetCurrentRealTime()
	if startCid == ""
		startCid = Main().NextCid()
	endif
EndFunction

Event OnOStimSceneChanged(string asEventName, string asSceneID, float afThreadID, Form akSender)
	if (afThreadID as int) != 0
		return
	endif
	float now = Utility.GetCurrentRealTime()
	bool isTransition = OMetadata.IsTransition(asSceneID)
	bool sameScene = (asSceneID == lastSceneId)
	lastSceneId = asSceneID
	; prev= on the wire: the position before this one, transitions skipped. The server uses it to
	; see that she is circling back to something she has already done (v0.3, additive).
	if !isTransition && asSceneID != lastPosScene
		prevPosScene = lastPosScene
		lastPosScene = asSceneID
	endif
	if navInFlight
		if !isTransition
			if navTarget == "" || asSceneID == navTarget
				navInFlight = false ; landed
				if windDown && wdWaitLanding
					WindDownLanded(now)
				endif
			elseif navDeadline > now + 8.0
				navDeadline = now + 8.0 ; a stop on OStim's route: a little longer, then give up
			endif
		endif
	elseif windDown && !sameScene
		CancelWindDown("the position was changed by hand") ; not a change the glue asked for
	endif
	; CHIM's package resets clear the busy flag: put it back on every scene change
	if partnerName != "" && Main().IsEnabled()
		AIAgentFunctions.setAnimationBusy(1, partnerName)
	endif
	if !isTransition
		GlueUndressNode(asSceneID, "node change") ; OStim's own mid-scene undressing is paused
	endif
	MarkDirty("change", true)
EndEvent

Event OnOStimSpeedChanged(string asEventName, string asStrArg, float afThreadID, Form akSender)
	if (afThreadID as int) != 0
		return
	endif
	MarkDirty("change", true)
EndEvent

Event OnOStimFurnitureChanged(string asEventName, string asFurnitureType, float afThreadID, Form akSender)
	if (afThreadID as int) != 0
		return
	endif
	sceneNearf = ScanNearFurniture(Game.GetPlayer())
	Main().LogC(startCid, "furniture changed to " + asFurnitureType, partnerName)
	lastSentKey = ""
	MarkDirty("change", true) ; carries the new furn= and nearf=
EndEvent

Event OnOStimActorOrgasm(string asEventName, string asSceneID, float afThreadID, Form akSender)
	if (afThreadID as int) != 0
		return
	endif
	Actor who = akSender as Actor
	string name = "someone"
	if who
		name = Main().CleanForWire(who.GetDisplayName())
	endif
	lastActivity = Utility.GetCurrentRealTime()
	PushState("climax", "who=" + name)
EndEvent

Event OnOStimThreadEnd(string asEventName, string asJson, float afThreadID, Form akSender)
	if (afThreadID as int) != 0
		return
	endif
	FinishThread(OJSON.GetScene(asJson), "thread end")
EndEvent

Function FinishThread(string asLastScene, string asWhy)
	{The one place a scene ends for the glue: tell the server, release the NPC for CHIM, then give
	 back weapons and clothes. Also reached from Tick() when the end event never arrived.}
	if finishing
		return
	endif
	finishing = true
	; first thing at the end of a scene: OStim's own undressing options go back to the owner's
	; values (the thread is over, so nothing can reach the crashing native any more)
	ReleaseOStimGates("the scene ended")
	startNoUndress = false
	float endAt = Utility.GetCurrentRealTime()
	sceneEndedAt = endAt ; a command from the last turn is stale from now on
	afterScene = "" ; a queued after= move has no scene left to move in
	DropPendingVerb("no scene is running") ; anything still waiting for her line is answered, not lost
	LRG_Main m = Main()
	string cid = startCid
	bool byGlue = startedByGlue
	; v0.3.1 (w3): how long it ran and how it ended. Both keys are additive; an old server ignores
	; them. how= is set by whoever caused the end (stop / hotkey / wind-down / a load / a lost
	; thread) and is "finished" when nobody claimed it - OStim ended the scene by itself.
	int dur = 0
	if sceneStartedAt > 0.0 && endAt >= sceneStartedAt
		dur = (endAt - sceneStartedAt) as int
	endif
	string how = endHow
	if how == ""
		how = "finished"
	endif
	endHow = ""
	; R3: the hold goes on BEFORE the first release. Everything below hands her back to her own AI,
	; and without this she is walking away before the glue has even finished dressing her.
	BeginOutroHold(endAt, how, dur)
	; [0.4 / owner complaint 1] THE LISTENER IS THE LAST THING TO GO, NOT THE FIRST. What the player
	; says in the next minute is still meant for her, and at this exact moment the crosshair is empty -
	; so handing CHIM's routing back here is what produced three narrator_inputtext turns in thirteen
	; minutes of playtest 8. It is held on her instead, with the bounded clock in TickListener.
	Actor lWho = partner
	string lName = partnerName
	if outroActive && outroActor != None
		lWho = outroActor
		lName = outroName
	endif
	float lHold = ListenerHoldSeconds()
	; every reason NOT to hold it is checked here, once, before the clock is armed - so listenerUntil is
	; never left pointing at a hold that ForceListenerOn would have refused
	if lHold > 0.0 && lWho != None && lName != "" && m.IsIntimacyEnabled() && m.SettingBool("bForceListener:Intimacy", true)
		listenerUntil = endAt + lHold + OutroHoldSeconds() ; the goodbye window is ON TOP of the hold
		outroDoneAt = 0.0
		ForceListenerOn(lWho, lName, "the scene ended - she is still the one you are talking to")
		m.RequestTick(1.0)
	else
		ReleaseListener("the scene ended")
	endif
	; [0.4 / F3] and the window in which the camera / controls self-repair may look at all
	ctlFixUntil = endAt + 10.0
	m.RequestTick(1.5) ; ... which needs a tick of its own when no listener hold is asking for one
	; the server answers an outro only on a fresh snapshot (its 90 s staleness rail, which is the
	; adults-only fail-closed rail and is never softened): force one while the row is still open
	if outroActive && outroActor != None
		m.MaybeSnapshot(outroActor, true)
	endif
	if partnerName != ""
		; sess= is on every lrg_scene push (PROTOCOL 1.2 [0.3]) - this was the only one without it
		; [0.4] paid= rides along here as well. The server would find it in the previous push anyway,
		; but ev=end is the message the halved gain and the outro fact are computed from, so the fact
		; travels with it rather than depending on which pushes survived de-duplication.
		string endPaid = ""
		if scenePaidGold > 0
			endPaid = ";paid=" + scenePaidGold
		endif
		m.SendNpcMessage(m.MSG_SCENE, "ev=end;npc=" + partnerName + ";cid=" + cid + ";scene=" + asLastScene + ";byglue=" + LRG_Profile.B2I(byGlue) + ";sess=" + m.SessionTag() + ";dur=" + dur + ";how=" + how + endPaid, partnerName)
	endif
	m.LogC(cid, asWhy + " last scene=" + asLastScene + " dur=" + dur + " how=" + how, partnerName)
	startCid = ""
	ResetState() ; releases CHIM's animation-busy flag, so she is free to speak
	; let OStim finish its own redressing, then give back what the glue took away
	Utility.Wait(1.5)
	; and only now ask for the closing line: the server has certainly seen ev=end (and written its
	; outro ticket), and she is not asked to talk in the same instant the scene stops.
	if outroActive
		RequestOutroLine(Utility.GetCurrentRealTime())
	endif
	if !starting && !OThread.IsRunning(0)
		RestoreWeapons()
		RedressRemembered("the scene ended")
		if byGlue && baseValid && m.SettingBool("bRedressAfter:Intimacy", true)
			Utility.Wait(2.5)
			if !starting && !OThread.IsRunning(0)
				RedressBaseline(cid)
			endif
		else
			baseValid = false
		endif
	endif
	finishing = false
EndFunction

; ---------------------------------------------------------------------------
; Debounced state push: batches stage/speed ticks into one message
; ---------------------------------------------------------------------------
Function MarkDirty(string asEvent, bool abActivity)
	if !dirty || asEvent == "start"
		dirtyEvent = asEvent
	endif
	dirty = true
	if abActivity
		lastActivity = Utility.GetCurrentRealTime()
	endif
	Main().RequestTick(0.8)
EndFunction

Function Tick()
	{Called by LRG_Main.OnUpdate (the quest's only update timer). Cheap when nothing is due.}
	if !booted
		return ; [0.5.5] never before this module's own boot (LRG_Main only calls it once confirmed)
	endif
	; v0.3.1: the outro hold is looked at FIRST and on every single tick, with or without OStim.
	; A frozen NPC is the worst thing this script can do, so its release never depends on anything
	; else being in order - TickOutro is also the self-heal for a hold left over in a save.
	if outroActive || outroActor != None || outroHeld
		TickOutro(Utility.GetCurrentRealTime())
	endif
	; [0.4] and then the listener hold and the controls repair, for the same reason and with the same
	; unconditional placement: both outlive the scene state, both are bounded, and both are their own
	; self-heal for a value only the save remembers.
	if listenerUntil > 0.0
		TickListener(Utility.GetCurrentRealTime())
	endif
	if ctlFixUntil > 0.0
		TickControls(Utility.GetCurrentRealTime())
	endif
	; v0.3: anything parked for her spoken line is looked at first - it is the only state that
	; wants a fast tick, and both paths end in "do it anyway" at the latest at their deadline.
	; The verb park is handled even without OStim (an undress outside a scene is still ours), or a
	; parked command would never be answered at all.
	if pendingVerb != ""
		TickPendingVerb(Utility.GetCurrentRealTime())
	endif
	; v0.3.1 (w2): and only then the queued SECOND command of a compound request - the first one
	; always gets its turn before the one waiting behind it
	if defCmd != ""
		TickDeferred(Utility.GetCurrentRealTime())
	endif
	if !ostimPresent
		return
	endif
	LRG_Main m = Main()
	float now = Utility.GetCurrentRealTime()
	if pendingStart
		TickPendingStart(now)
		if pendingStart
			return ; still waiting: TickPendingStart has asked for the next tick
		endif
	endif
	if starting && now > startDeadline
		starting = false
		pendingStart = false
		m.ReportResult(partnerName, startCmd, startParam, "Error: the scene did not start")
		ResetState()
		RestoreWeapons()
		baseValid = false
		return
	endif
	if navInFlight && now > navDeadline
		navInFlight = false
		if windDown && wdWaitLanding
			WindDownLanded(now) ; never landed where it should: wind down right here
		endif
	endif
	if dirty
		dirty = false
		PushState(dirtyEvent, "")
	endif
	if starting
		m.RequestTick(2.0)
	elseif partnerName != ""
		if OThread.IsRunning(0)
			goneSince = 0.0
			m.RequestTick(ThreadTick(now))
		elseif goneSince <= 0.0 || now < goneSince
			goneSince = now ; the end event is probably just behind us: give it 3 s
			m.RequestTick(3.0)
		elseif (now - goneSince) >= 2.5
			goneSince = 0.0
			if endHow == ""
				endHow = "lost" ; v0.3.1: no end event ever arrived - not a finished scene
			endif
			FinishThread("", "thread gone without an end event") ; never leave her locked for CHIM
		else
			m.RequestTick(1.0)
		endif
	elseif HoldsPlayerClothes()
		if m.IsEnabled()
			ColdTick(now) ; the glue undressed the player outside a scene
		endif
		m.RequestTick(5.0)
	endif
EndFunction

float Function ThreadTick(float afNow)
	{While the thread runs. Returns the delay until the next tick.}
	float nextIn = 5.0
	if navInFlight
		nextIn = 2.0
	endif
	; v0.3.1 (w1): after=<id> - move into the position the player asked for a few seconds after the
	; thread came up. Checked before everything else, and it asks for its own fast ticks.
	if afterScene != ""
		float afterIn = TickAfterScene(afNow)
		if afterIn < nextIn
			nextIn = afterIn
		endif
	endif
	if (afNow - lastThreadTick) >= 4.5 || afNow < lastThreadTick
		lastThreadTick = afNow
		; the master switches must reach this path too: with the glue off it neither locks the NPC
		; for CHIM nor keeps the player warm. A running wind-down below still finishes, and the
		; scene itself stays under OStim's / the stop key's control.
		bool glueOn = Main().IsEnabled()
		if glueOn
			; CHIM's package resets (any movement command, end of conversation) clear this flag
			AIAgentFunctions.setAnimationBusy(1, partnerName)
			; and the same resets can put the conversation back on the crosshair (= the Narrator,
			; because during a scene nobody is under it). Re-asserted rarely on purpose: this is a
			; CHIM native whose side effects are not documented, so it is asked for as seldom as
			; the job allows and switched off entirely by bForceListener.
			if listenerForced && ((afNow - listenerAt) >= 60.0 || afNow < listenerAt)
				ForceListener("re-asserted")
			endif
			; G1 / playtest 6: the server gives an NPC scene awareness, the scene actions and the
			; deterministic safety net only on a FRESH snapshot (snapshot_max_age_seconds, 90 s) -
			; the adults-only fail-closed rail, which must not be softened there. During a scene
			; nobody is under the crosshair, and LRG_Main's own refresh loop stops as soon as she
			; has not spoken for 90 s: the ordinary rhythm (lead interval 75 s, lead hold 150 s)
			; therefore ran straight into the gap, and a spoken "get naked" came back BLOCKED and
			; was silently lost. The thread tick is the one clock that keeps running, so it keeps
			; the snapshot alive. MaybeSnapshot throttles itself to one per NPC per 10 s, and
			; lrg_npcstate is a fast message the server handles before the MAIN lock: no LLM cost.
			if partner != None
				Main().MaybeSnapshot(partner, false)
			endif
		endif
		bool isAuto = OThread.IsInAutoMode(0) ; OStim's own key can toggle it behind our back
		; auto mode switched on AFTER the start of a glue-started scene would walk the positions
		; by itself and bypass the whole step-by-step ladder: switch it off again unless the
		; player explicitly handed OStim the lead ("auto").
		if glueOn && isAuto && startedByGlue && leader != "auto"
			OThread.StopAutoMode(0)
			Main().LogC(startCid, "OStim auto mode stopped again (lead=" + leader + ")", partnerName)
			isAuto = false
		endif
		if isAuto != lastAuto
			lastAuto = isAuto
			if !isAuto && leader == "auto"
				leader = DefaultLeader()
			endif
			MarkDirty("change", false)
		endif
		if stateRecheckAt > 0.0 && afNow >= stateRecheckAt
			stateRecheckAt = 0.0
			MarkDirty("change", false)
		endif
		if glueOn
			ColdTick(afNow)
		endif
	endif
	if windDown
		if !wdWaitLanding
			if wdSlowAgain
				wdSlowAgain = false
				OThread.SetSpeed(0, 0)
			endif
			if afNow >= wdStopAt
				Main().LogC(wdCid, "wind-down finished", partnerName)
				windDown = false
				endHow = "finished" ; v0.3.1: a wind-down is the intended end, not a "stop"
				StopScene("wind-down finished")
				return 2.0
			endif
			float left = (wdStopAt - afNow) + 0.1
			if left < nextIn
				nextIn = left
			endif
		endif
	elseif !navInFlight
		MaybeLead(afNow)
	endif
	return nextIn
EndFunction

Function ColdTick(float afNow)
	{Survival Mode Improved: being undressed for the glue must not freeze the player. Real hook:
	 SurvivalModeImprovedApi.RestoreColdLevel(float) (global native, installed source line 4).
	 Player only - SMI tracks no NPC cold. The amount is an MCM value per 5 seconds.}
	if !smiPresent
		return
	endif
	float elapsed = afNow - lastColdTime
	if elapsed < 4.0 && elapsed >= 0.0
		return
	endif
	lastColdTime = afNow
	LRG_Main m = Main()
	if !m.SettingBool("bColdExempt:Survival", true)
		return
	endif
	if elapsed > 10.0 || elapsed < 0.0
		elapsed = 5.0
	endif
	float amount = m.SettingFloat("fColdRestore:Survival", 10.0) * elapsed / 5.0
	if amount > 0.0
		SurvivalModeImprovedApi.RestoreColdLevel(amount)
	endif
EndFunction

string Function JoinCapped(string[] asList, int aiMax, int aiMaxChars)
	string out = ""
	int i = 0
	while i < asList.Length && i < aiMax && StringUtil.GetLength(out) < aiMaxChars
		if asList[i] != ""
			if out != ""
				out += ","
			endif
			out += asList[i]
		endif
		i += 1
	endwhile
	return out
EndFunction

Function PushState(string asEvent, string asExtra)
	if !ostimPresent || !OThread.IsRunning(0) || partnerName == ""
		return
	endif
	string sceneId = OThread.GetScene(0)
	if sceneId == ""
		return ; still in startup
	endif
	Actor player = Game.GetPlayer()
	int speed = OThread.GetSpeed(0)
	; OThread.GetFurnitureType returns the LIST type ("bed"), but every installed bed scene is
	; authored "doublebed" / "singlebed": the index would then find no option at all. Report the
	; furniture object's OWN specific type, the same vocabulary as nearf= and the command params.
	string furn = "none"
	ObjectReference threadFurn = OThread.GetFurniture(0)
	if threadFurn
		furn = OFurniture.GetFurnitureType(threadFurn) ; "none" for a ref that is not furniture
	endif
	int isAuto = LRG_Profile.B2I(OThread.IsInAutoMode(0))
	int stall = LRG_Profile.B2I(OThread.IsClimaxStalled(0))
	int pcl = OActor.GetTimesClimaxed(player)
	int ncl = 0
	int und = 0
	string undp = ""
	if partner
		ncl = OActor.GetTimesClimaxed(partner)
		int worn = WornBits(partner)
		und = LRG_Profile.B2I(Math.LogicalAnd(worn, 1) == 0)
		undp = OffParts(baseBitsNpc, worn)
	endif
	string shownLeader = leader
	if isAuto == 1
		shownLeader = "auto" ; whoever switched it on (the lead verb or OStim's own key): OStim drives
	endif
	int wd = LRG_Profile.B2I(windDown)

	; everything the server acts on is part of the de-duplication key
	string stateKey = asEvent + "|" + sceneId + "|" + speed + "|" + asExtra + "|" + undp + "|" + shownLeader + "|" + isAuto + "|" + stall + "|" + wd + "|" + furn + "|" + ncl + "|" + pcl + "|" + sceneNearf
	if stateKey == lastSentKey
		return
	endif
	lastSentKey = stateKey
	if startCid == ""
		startCid = Main().NextCid()
	endif
	Actor[] actors = OThread.GetActors(0)
	bool isTransition = OMetadata.IsTransition(sceneId)

	; sess= (every push) and prev= (on a change) are v0.3 and additive: a server that does not know
	; them ignores them. sess lets the server tell this game session from the one before it, so a
	; scene row left open by a reload is closed instead of being believed.
	string s = "ev=" + asEvent + ";npc=" + partnerName + ";cid=" + startCid + ";sess=" + Main().SessionTag()
	s += ";scene=" + sceneId
	if asEvent == "change"
		s += ";prev=" + prevPosScene
	endif
	s += ";speed=" + speed + ";maxspeed=" + OMetadata.GetMaxSpeed(sceneId)
	s += ";trans=" + LRG_Profile.B2I(isTransition)
	s += ";furn=" + furn
	s += ";ppos=" + OThread.GetActorPosition(0, player)
	int npos = -1
	if partner
		npos = OThread.GetActorPosition(0, partner)
	endif
	s += ";npos=" + npos
	s += ";actors=" + actors.Length
	s += ";byglue=" + LRG_Profile.B2I(startedByGlue)
	s += ";und=" + und
	if asExtra != ""
		s += ";" + asExtra
	endif
	s += ";auto=" + isAuto + ";leader=" + shownLeader + ";stall=" + stall
	s += ";ncl=" + ncl + ";pcl=" + pcl + ";wd=" + wd
	s += ";nearf=" + sceneNearf + ";undp=" + undp
	; [0.4] paid= is on EVERY push of a paid scene, not only on ev=start: the server reads it from the
	; last stored push at ev=end, and any individual push can be de-duplicated away or lost.
	if scenePaidGold > 0
		s += ";paid=" + scenePaidGold
	endif
	s += ";tags=" + JoinCapped(OMetadata.GetSceneTags(sceneId), 12, 160)
	s += ";acts=" + JoinCapped(OMetadata.GetActionTypes(sceneId), 12, 160)
	s += ";x=" + Main().SettingInt("iExplicitness:SceneTalk", 2)
	if !isTransition
		; live "routable for these actors" oracle; labels and tiers come from the server index
		s += ";next=" + JoinCapped(OLibrary.GetScenesInRange(sceneId, actors, 1), 24, 700)
	endif
	Main().SendNpcMessage(Main().MSG_SCENE, s, partnerName)
	if !isTransition
		MaybeSceneTalk(asEvent)
	endif
EndFunction

; ---------------------------------------------------------------------------
; Occasional in-scene speech. Decided here (cheap, no LLM involved in the decision):
; only on meaningful moments, never during a transition or a wind-down, random chance +
; minimum gap, never while the NPC is already talking. The request itself is asynchronous.
; ---------------------------------------------------------------------------
Function MaybeSceneTalk(string asEvent)
	LRG_Main m = Main()
	; the kill switch / intimacy switch must silence this too, including a scene that was started
	; from OStim's own menu (MaybeLead already carries exactly this check)
	if !m.IsIntimacyEnabled() || !m.SettingBool("bSceneTalk:SceneTalk", true) || partnerName == "" || windDown
		return
	endif
	float now = Utility.GetCurrentRealTime()
	if asEvent == "start" && startedByGlue
		lastTalkTime = now ; she spoke in the turn that started the scene
		return
	endif
	if (now - lastTalkTime) < m.SettingFloat("fSceneTalkGap:SceneTalk", 25.0) && now >= lastTalkTime
		return
	endif
	int chance = m.SettingInt("iSceneTalkChance:SceneTalk", 35)
	if asEvent == "climax" || asEvent == "start"
		chance += 35 ; the moments that matter most
	endif
	if Utility.RandomInt(1, 100) > chance
		return
	endif
	if AIAgentFunctions.isActorTalking(partnerName) != 0
		return
	endif
	lastTalkTime = now
	string moment = "the position just changed"
	if asEvent == "start"
		moment = "the moment has just begun"
	elseif asEvent == "climax"
		moment = "a peak was just reached"
	endif
	AIAgentFunctions.requestMessageForActor(moment, "lrg_scenetalk", partnerName)
EndFunction

; ---------------------------------------------------------------------------
; Scene lead: when nothing happened for fSceneLeadInterval seconds the NPC gets a turn to
; move things along herself (text "lead"; the server offers her ChangeIntimacy /
; ChangeClothing on that turn). Never during a transition or while she is talking, and only
; while SHE leads: not with leader=player, leader=auto, OStim auto mode on, or a wind-down.
; ---------------------------------------------------------------------------
Function MaybeLead(float afNow)
	LRG_Main m = Main()
	if partnerName == "" || starting || navInFlight || windDown
		return
	endif
	if pendingVerb != ""
		return ; something she already chose is waiting to be seen
	endif
	if leader != "npc" || lastAuto
		return
	endif
	; G2: the player steered a moment ago (hold= from the server, or CHIM voicing a line of the
	; player's). She answers and follows, but she does not take a turn of her own on top of it.
	if afNow < leadHoldUntil
		return
	endif
	if (afNow - lastActivity) < m.SettingFloat("fSceneLeadInterval:SceneTalk", 75.0) && afNow >= lastActivity
		return
	endif
	if !m.IsIntimacyEnabled() || !m.SettingBool("bSceneTalk:SceneTalk", true)
		return
	endif
	string sceneId = OThread.GetScene(0)
	if sceneId == ""
		return
	endif
	if OMetadata.IsTransition(sceneId)
		return
	endif
	if OThread.IsInAutoMode(0)
		lastAuto = true ; switched on by OStim's key since the last poll
		MarkDirty("change", false)
		return
	endif
	if AIAgentFunctions.isActorTalking(partnerName) != 0
		return ; try again with the next tick
	endif
	lastActivity = afNow
	lastTalkTime = afNow
	AIAgentFunctions.setAnimationBusy(1, partnerName)
	m.LogV(startCid, "lead request scene=" + sceneId, partnerName)
	AIAgentFunctions.requestMessageForActor("lead", "lrg_scenetalk", partnerName)
EndFunction
