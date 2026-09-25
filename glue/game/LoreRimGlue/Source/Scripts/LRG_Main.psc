Scriptname LRG_Main extends Quest
;/LoreRim Glue - core: settings, CHIM command intake, NPC snapshots, NPC initiative tick, logging.
 No properties are filled by the plugin on purpose: every vanilla form is looked up at
 runtime, so the ESP stays tiny and has no masters besides Skyrim.esm.
 Wire contract: glue/PROTOCOL.md v0.5 (snapshot v=2 - additive keys only, script version 500).
 [pt19c] script 513: MENULESS QUESTING v1.0 (research/pt19-menuless-v1-spec.md) - the emergency key is gone
 (the menu is never hidden), the talk key iKeyPushToTalk tells the driver he is about to speak (NoteSpeech 0,
 the auto-advance cancel), CHIM's speech events reach the driver with the speaker and HER voice start as its
 own kind (2), the stage rail's corner note is shown once per session, and SayReason words the driver's new
 refusals (S7). The quest entry (CmdQuestEntry, Qe*) is untouched.
 [pt19] script 511: QUIET MODE - while a curated scripted quest runs (QUIET_QUESTS: MQ101 from stage 5) the
 glue takes no snapshot, holds and follows nobody, refuses escort and quest entry with the reason voiced,
 skips the initiative tick (and no conversation is opened for him) - see the block before QuietOn() and
 research/pt19-helgen.md section 7. Snapshot key quiet= (LRG_Profile), facts key quiet= (LRG_Dialogue).
 v0.5.6 (script 506, research/pt13-game-fix.md): the boot and snapshot abort detectors judge an
 attempt by its OWN token and only after it has been silent for a long time - an attempt that is
 merely waiting in a native on another stack is never called aborted again, and an optional block
 is retired only after it was open in three lost attempts in a row. New command ExtCmdLRG_Escort
 (do=follow|wait|release): "follow me" for an NPC held by her own performance or idle scene.
 v0.5.7 (script 507, research/pt14-never-silent-game.md, owner addendum 11 "NEVER SILENT"): every
 funcret is "OK: <neutral sentence>" or "Error: <her reason>" - a refusal in plain words she can
 repeat, the technical reason in the log line, the corner note and the additive err= key only
 (ReportResult / SayReason). A follow-up that undoes an OK: (the escort's scene came back, an after=
 position that turned out impossible) sends one late Error: funcret (late=1, ReportLateError).
 v0.5.5 (script 505): the main quest is a NEW form (0x802, LRG.GetMain), every OnInit only sets
 members and registers, and the three modules are ASKED to boot by a mod event and confirm it -
 see the block before OnInit() and research/pt12-stuck-oninit-fix.md.
 v0.5 additions here: the three CHIM speech events forward to the driver (X1, calibration), the
 corner note is gated on bQuestHint, and ev=lat measures how long a reply really takes - up to and
 including the moment her VOICE starts, which no other number in this project has ever covered.
 v0.4.1 adds THE CONVERSATION HOLD (owner addendum 8): while a CHIM conversation is live the NPC
 stops and faces the player instead of walking back to her schedule. See the block before
 ConvWindow() for the whole design; nothing else in this script changed.
 v0.4 additions here: GetDialogue()/GetProbe(), the ExtCmdLRG_SelectTopic branch, the leave key,
 the real topic-dump key and the narrowed D-13 guard in OnKeyDown. Everything else is unchanged.
 v0.3 additions here: sess= in the snapshot (a session tag re-rolled on every load, so the server
 can close a scene row that belongs to the previous session), one forced snapshot of the crosshair
 actor right after a load, and the partner's speech timestamps forwarded to LRG_OStim for the
 announce gate (wait=begin|end)./;

; ---------------------------------------------------------------------------
; Constants / state
; ---------------------------------------------------------------------------
string Property ModName = "LoreRimGlue" AutoReadOnly
int Property CurrentVersion = 513 AutoReadOnly
; [0.5.4] the boot queue runs steps 1..BOOT_LAST, one per OnUpdate, each on its own Papyrus stack.
; [0.5.5] eleven steps: the player-name cache is step 1, and steps 5-7 ASK the modules to boot.
int Property BOOT_LAST = 11 AutoReadOnly
int Property BOOT_AGAIN = 10 AutoReadOnly   ; the step that repeats the version line once CHIM is up
float Property BOOT_STALL_SECS = 20.0 AutoReadOnly
string Property BOOT_EVENT = "LRG_Boot" AutoReadOnly
; [0.5.6] how long a boot step / a snapshot attempt may stay silent before it is judged LOST. Far
; above anything legitimate (a boot step is well under a second, a snapshot about one second - the
; slowest of playtest 13 took 1.4 s - even with the VM crowded right after a load), so a verdict
; means "stuck", never "slow". A late return after a verdict is reported and rolls the verdict back.
float Property BOOT_LOST_SECS = 60.0 AutoReadOnly
float Property SNAP_LOST_SECS = 45.0 AutoReadOnly
int Property SNAP_RETIRE_AFTER = 3 AutoReadOnly  ; lost attempts IN A ROW with a block open before it is retired
; [0.5.6] ExtCmdLRG_Escort: the quests whose scenes "follow me" may end when the server sends no
; safe= list of its own (the server's escort.safe_quests has the same default). Case-insensitive;
; "*" any run of characters, "?" one character. The deny list below is checked FIRST and no safe=
; list can get past it.
string Property ESCORT_SAFE_DEFAULT = "BardSongs,BardSongsInstrumental,*Idle*,*Sandbox*,WI*" AutoReadOnly
string Property ESCORT_DENY = "MQ*,DLC1MQ*,DLC2MQ*,DLC1VQ*,CW*,DB*,TG*,MG*,C0*,DA*,*Courier*,*ForceGreet*,*Arrest*,Windhelm*,Winterhold*" AutoReadOnly
; [pt19 / script 511] QUIET MODE: the curated scripted quests the glue stands aside for. A row is an EditorID
; with an optional stage floor, "<EditorID>>=<stage>", comma-separated, at most 8 rows. MQ101 (Unbound) runs
; at stage 0 from the first minute of an Alternate Perspective game and only becomes the intro at stage 5
; (the cart), which is why the floor is there. An empty list means this default, never "quiet everywhere".
string Property QUIET_QUESTS = "MQ101>=5" AutoReadOnly
float Property QUIET_LOOK_SECS = 5.0 AutoReadOnly ; how long one look at the quest stands (a member read between looks)

; CHIM catalog actions that MOVE or re-task an NPC. While the conversation hold is on, one of these
; for the held NPC means CHIM wants her legs back: the hold gets out of the way instead of fighting
; it (research/pt8-walkaway.md 5.10 / risk 5). Names from ext/lorerim_glue/lib/lrg_actions.php.
; EndConversation is deliberately NOT in here - it KEEPS the hold, see NoteChimAction.
string Property CONV_MOVE_ACTIONS = "|ComeCloser|FollowPlayer|Follow|MakeFollower|MoveTo|TravelTo|TravelToRaw|LeadTheWayTo|ReturnBackHome|Sandbox|GoToSleep|TakeASeat|Relax|WaitHere|HireCarriage|HireFerry|" AutoReadOnly

; server message types (handled by ext/lorerim_glue/preprocessing.php)
string Property MSG_NPCSTATE = "lrg_npcstate" AutoReadOnly
string Property MSG_SCENE = "lrg_scene" AutoReadOnly
string Property MSG_LOG = "lrg_log" AutoReadOnly
string Property MSG_INITIATIVE = "lrg_initiative" AutoReadOnly

int installedVersion = 0
int sessionTag = 0
int cidCounter = 0

; v0.3.1: the last FOUR commands, not the last one. Every command arrives twice (the bridge script
; and the CHIM_CommandReceived mod event) and a reply may now carry TWO commands (a compound
; request, PROTOCOL w2). With a single slot the order A-bridge, B-bridge, A-event, B-event would
; make A look new again and carry it out a second time.
string[] cmdKeys
float[] cmdTimes
int cmdSlot = 0

Actor lastSnapActor = None
float lastSnapTime = 0.0
float lastAnySnapTime = 0.0
Actor lastMissActor = None ; last NPC that turned out not to be a CHIM agent (negative cache)
float lastMissTime = 0.0

;/[0.5.2] SNAPSHOT SAFETY - playtest 10, research/pt10-snapshot-fix.md.
 Papyrus has no try/catch: ONE runtime error anywhere inside BuildSnapshot (a member call on a
 None, a native of another mod that is not there, an out-of-range index) unwinds the WHOLE stack,
 so the send never happens AND the rest of the CHIM event that called it - the watch, the
 conversation hold, the latency mark - is lost with it. That is exactly what playtest 10 was:
 0 lrg_npcstate in a 30-minute session, 394 the session before.
 The three fields below make that failure survivable and self-describing:
   snapWhere : how far the LAST attempt got. Written as the payload is built - one VM
               instruction each - and read on the NEXT call if that attempt never finished.
   snapDoorRun / snapFolRun : true while an OPTIONAL block is running. Still true on the next
               call = that block aborted the snapshot, so it is switched off for this session and
               said out loud. Every other fact still goes out.
 The Bad flags are cleared by Maintenance(), so a new build always gets a fresh try.
 THE STAGE NUMBERS (SnapStageName has the words). They are labels, not an order:
    1..10 the payload as LRG_Profile.BuildSnapshot builds it, 11 the send itself.
   [0.5.3] 21..23 the stretch of MaybeSnapshot BEFORE the payload - the entry checks, CHIM's
           getAgentByName, OStim's IsActorInOurScene. Until 0.5.3 nothing was stamped there, so
           an abort in it left snapWhere at 0 and the detector below never fired: no line, no
           retirement, and the same abort every single event (verifier defect D1).
   [0.5.3] 12 NoteFollower after the send (D3). (13, FollowerSweep on load, left the rail in 0.5.6:
           it is boot step 9 and the boot rail judges it.)

 [0.5.6] WHAT PLAYTEST 13 SHOWED, AND THE RAIL SINCE (research/pt13-game-fix.md section 2).
 Six "SNAPSHOT ABORTED" lines and fol= / witchim retired for the session - with not one Papyrus
 error in LRG_Main, LRG_Profile or LRG_Followers, and snapshots flowing all evening. Two facts the
 0.5.2 design did not have:
   1. A Papyrus stack is NOT alone in this script while it builds a snapshot. Every call out of the
      script - a native on an actor, LRG_Profile.BuildSnapshot, LRG_OStim, CHIM, MCM Helper - lets
      every other stack in (Creation Kit wiki, "Threading Notes"). A crosshair event, a CHIM speech
      event and the tick regularly arrive while an attempt waits in a native, and they found the
      flag up and the stamp set: "aborted", when it was simply still running.
   2. A Papyrus error does not unwind the stack in the first place. It aborts the one call and the
      function carries on (the same log shows LRG_Dialogue.SendFacts logging its line right after
      three errors of its own, and another mod's OnPlayerLoadGame erroring on two lines in a row).
      What CAN leave an attempt unfinished is a stack that never comes back - the frozen pair of the
      0.5.4 saves is the one real example this project has.
 So from 0.5.6 each attempt holds the rail with its OWN token (snapRunTok). Every stamp and
 breadcrumb carries that token and is ignored when it is not the holder's. Another event that finds
 the rail held simply skips (a forced one waits half a second and then goes ahead beside it). An
 attempt is judged LOST only when it has held the rail for SNAP_LOST_SECS; a lost attempt that comes
 back later is reported as LATE and its verdict is taken back. An optional block (door=, fol=) is
 retired only after it was open in SNAP_RETIRE_AFTER lost attempts IN A ROW - one success in
 between resets its count./;
int snapWhere = 0          ; the stage of the attempt holding the rail
int snapTok = 0            ; the last token handed out (never reused within a session)
int snapRunTok = 0         ; the token of the attempt holding the rail, 0 = free
float snapRunAt = 0.0      ; when that attempt took the rail (real time)
int snapLost = 0           ; attempts judged lost this session - the log line stops after six
int snapLostTok = 0        ; the last attempt judged lost: if it ever comes back, the verdict is undone
bool snapLostDoor = false  ; ...and which optional block it had open
bool snapLostFol = false
int snapSkips = 0          ; events that found another attempt in flight and let it be (not an error)
int snapDoorFails = 0      ; lost attempts IN A ROW with the door block open
int snapFolFails = 0       ; lost attempts IN A ROW with the follower block open (and a lost step 9)
bool snapDoorRun = false
bool snapDoorBad = false
bool snapFolRun = false
bool snapFolBad = false

;/[0.5.4] THE BOOT RAIL - playtest 11, research/pt11-dead-scripts-fix.md.
 Until 0.5.3 the whole load path was ONE Papyrus stack: ReleaseConvHold, RegisterKeys,
 LRG_OStim.Maintenance, LRG_Dialogue.Maintenance, LRG_DlgProbe.Maintenance, the load snapshot and
 FollowerSweep, all in front of the first line that tells anybody the mod is alive. Five of those
 blocks call into PapyrusUtil, MCM Helper, OStim, StorageUtil and CHIM, and Papyrus has no
 try/catch: one error in any of them unwound the rest of the load AND the version line with it.
 The save of 22 Sep 17:38 shows exactly that outcome - Maintenance() ran, RegisterKeys() ran, and
 then nothing else ever happened again for the rest of the session.
 The queue below puts every one of those blocks on its OWN stack (one per OnUpdate) and arms the
 next tick BEFORE running the step, so an abort can cost at most the step it happened in.
 [0.5.6] ...and the steps OVERLAP. The next tick is armed 0.25 s after a step begins, and a step that
 waits in a native (the player's name while the VM is crowded after a load, four MCM Helper reads, a
 whole snapshot) is still running when the next one starts. 0.5.4 kept ONE "last finished" number
 for all steps, so step 2 found step 1 "aborted" while it was only waiting, and step 8 finishing
 after step 9 wrote 8 over 9 and made step 9 look aborted 22 s later - playtest 13's four BOOT STEP
 ABORTED lines, all false. Every step now has its own state and start time (bootSt / bootBegan),
 the step is CLAIMED before any call, and a step is called LOST only after BOOT_LOST_SECS of
 silence; if it returns after that it is reported LATE and the verdict is taken back./;
int bootStep = 0          ; the step the next tick will run. 0 = the queue is idle
int bootGen = 0           ; bumped by every Maintenance(): a step of an earlier boot never marks this one
int[] bootSt              ; per step (index = step): 0 not begun, 1 running, 2 returned, 3 judged lost
float[] bootBegan         ; per step: when it began (real time)
int bootRunning = 0       ; steps in state 1 - BootJudge costs one compare while this is 0
int bootLost = 0          ; steps judged lost this load (a late return takes one back)
float bootAt = 0.0        ; when this load happened (real time): step 9 waits for CHIM to be up
bool bootNotify = true    ; corner note on load. TRUE in code; only the MCM ever turns it off
bool hbOn = true          ; the heartbeat. TRUE in code, for the same reason
;/[pt15 performance] THE LOG LEVEL, iLogLevel:General - 0 errors only, 1 normal (the default for play),
 2 everything. Cached here, never read per line: LogRefresh() reads it with bDebugLog at boot step 11
 and on every heartbeat (30 s). 1 in code, so a load whose MCM read dies still logs normally./;
int logLevel = 1
bool logSend = true       ; bDebugLog:General, cached with it
;/[pt17] bDryRun:General, cached with them. The DEVELOPER dry run refuses every glue command, and
 playtest 2026-09-23 showed it can be switched on by mistake and never noticed: it is announced by a
 message box at boot step 11, by a corner note every fourth heartbeat, and by the NPCs' own words
 (SayReason). FALSE in code, so built-in defaults never announce anything./;
bool devDry = false
float hbAt = 0.0
int hbCount = 0
;/[0.5.4] MCM Helper returns false/0 for a key it does not know - it never falls back to the
 caller's default - so ONE missing key in settings.ini used to switch the whole mod off in
 silence. SettingsLive() reads a canary key that no menu can change; when it is absent, every
 Setting* call returns the caller's default and the mod stays fully on./;
bool mcmLive = false
bool mcmChecked = false
;/[0.5.5] THE STUCK OnInit - playtest 12, research/pt12-stuck-oninit-fix.md.
 The Papyrus log of 23 Sep 00:14 finally showed what "the glue is dead" was: two stacks frozen
 against each other INSIDE the save. Playtest 10 added LRG_Dialogue to the quest, so its OnInit ran
 during that load and called LRG_Main.LogC -> Actor.GetDisplayName while OnPlayerLoadGame was
 running LRG_Main.Maintenance -> LRG_Dialogue.Maintenance. A script is closed to every other stack
 until its OnInit returns, so Maintenance waited for LRG_Dialogue, LogC waited for LRG_Main, and
 neither moved again. Both stacks were written into Save14 and Save15 and resumed - still frozen -
 on every load since, and every event of this quest queued up behind them for ever.
 The cure has three parts: the main quest is a NEW form (0x802), so the frozen instances of the
 old form are left behind with it; every OnInit only sets members and registers; and the three
 modules are ASKED to boot by a mod event (BootAsk), never called, so this script can never wait
 on one of them. Until a module confirms (BootConfirm) its accessor answers None./;
bool initBoot = false        ; OnInit armed the first boot: the next OnUpdate runs Maintenance()
string playerName = "Player" ; LogC's actor name, cached by boot step 1 - LogC calls no native for it
; per module - 0 LRG_OStim, 1 LRG_Dialogue, 2 LRG_DlgProbe (ModScript has the names):
; 0 not asked yet, 1 asked, 2 confirmed its boot, 3 stalled (BOOT STALL said), 4 not attached
int[] modSt
float[] modAsk               ; when each module was asked (real time)
int bootStalls = 0
bool calibAsked = false      ; this load's ev=calib request has gone to LRG_Dialogue

; snapshot refresh: the agent the player is dealing with right now
Actor watchActor = None
float watchTalkTime = 0.0   ; last line of the watched NPC (start or end of speech)
float playerTalkTime = 0.0  ; last voiced player line CHIM told us about
float initAt = 0.0          ; last lrg_initiative tick (any NPC)
; [pt19 / script 511] QUIET MODE - see the block before QuietOn(). Cleared by Maintenance, which also makes
; the two arrays (QuietLook re-makes them once for a save from before this script).
bool quietOn = false
float quietAt = 0.0        ; when QuietOn() last really looked (real time); 0 = never
string quietWhy = ""       ; "<EditorID> stage <n>" while quiet - the text of the engage line
Quest quietQuest = None    ; the curated quest that is quiet right now
string[] quietIds          ; the curated rows, parsed from QUIET_QUESTS on the first look
int[] quietFloors
int quietRows = -1         ; -1 = not parsed yet
; the ONE update timer of this quest form (shared with LRG_OStim, see RequestTick)
float tickAt = 0.0
float snapTickAt = 0.0   ; last time SnapTick really looked
float snapTickDue = 0.0  ; when it wants to look again

; place-fact caches (snapshot v=2). Location facts change with the location, ownership / home
; facts with NPC + cell: nothing here is recomputed on a plain 20 s refresh.
bool lcValid = false
Location lcLoc = None
string lcType = "wild"
string lcFacts = "loc=;ltype=wild"
Actor pcNpc = None
Cell pcCell = None
Location pcLoc = None
float pcTime = 0.0
string pcCellOwn = "none"
string pcFacts = "cellown=none;home=0;nhome="
string pcSde = "sde=-1"

int keyStopScene = 0
int keyDumpTopics = 0
int keyLeave = 0
int keyTalk = 0          ; [v1.0 / S4.6] iKeyPushToTalk:Dialogue - CHIM's push-to-talk key, 0 = off
string railNoteSid = ""  ; [v1.0 / S3.3] the conversation whose stage-rail corner note was shown

; --- v0.4.1 THE CONVERSATION HOLD -------------------------------------------------------------
; convActor is its own field, exactly as LRG_OStim's outroActor is: clearing watchActor (the snapshot
; refresh gives it up after 20 s of silence) must never orphan a live hold. convHeld is its own field
; too, because SetDontMove IS written into the save and has to be released on load even when
; convActive came back false.
Actor convActor = None
string convName = ""
string convCid = ""
bool convActive = false
bool convHeld = false      ; SetDontMove(true) is really in force on convActor
bool convPkgOn = false     ; the opt-in package layer is really in force on convActor
float convStart = 0.0      ; when the hold began (real time)
float convUntil = 0.0      ; the refreshed deadline
float convLastLine = 0.0   ; last line from either side
float convLogAt = 0.0      ; last refresh line written (CHIM speaks once per SENTENCE: throttled)
float convReassert = 0.0   ; > 0: say SetDontMove again at this time (after CHIM reset her packages)
Actor convSkipActor = None ; last NPC a hold was refused for (the refusal line is throttled too)
float convSkipAt = 0.0
Package convPkg = None     ; AIAgent.esp AIAgentDoNothing, looked up once per session
bool convPkgTried = false

; --- v0.5 ev=lat: THE ONLY NUMBER THAT INCLUDES HER VOICE ---------------------------------------
; CHIM's own [PERF] log stops at llm_complete - the text being ready. It does not cover TTS, the
; trip back to the game, or the moment her voice actually starts, which is the thing the owner
; asked about. These four stamps come from the three CHIM events this script already handles, so
; the measurement costs nothing and never asks the server for anything.
;   latAskStart/latAskStop : CHIM voicing the PLAYER's own line (start / end)
;   latTextAt   : CHIM_TextReceived - her words arrived
;   latVoice    : CHIM_SpeechStarted for her - her voice started (the send happens here)
; latSent makes it ONE message per reply: CHIM raises SpeechStarted once per SENTENCE.
float latAskStart = 0.0
float latAskStop = 0.0
float latTextAt = 0.0
string latTextNpc = ""
bool latSent = true
string latFirstNpc = ""    ; the NPC whose list the glue just read for the first time (the L1 cost)
float latFirstAt = 0.0

; --- v0.5.1 FOLLOWER COMPATIBILITY (owner addendum 10) ------------------------------------------
; CHIM's MakeFollower leaves an NPC in the vanilla CurrentFollowerFaction with no teammate flag, no
; SFF alias and no slot accounting - a GHOST no framework owns, and the reason a dismiss spoken to
; her dismisses the player's REAL follower instead (research/pt9-followers.md 4.1 / 4.2).
; The repair is scheduled onto this quest's own tick and never run inside an event: it must not run
; while CHIM's own faction writes for that actor are still settling, and SetFollower moves the
; relationship rank, the teammate flag, the alias and SFF's DLL in one go.
; [0.5.1 fix pass] both of these were SINGLE slots, which two half-recruited NPCs defeated - the second ghost
; silently replaced the first in the queue, and alternating between two NPCs defeated the 20 s
; throttle. Four slots each, deduplicated; a fifth ghost simply waits for its own next snapshot.
Actor[] folFixQ            ; the ghosts waiting to be repaired
float[] folFixT            ; when each may be done (a short settle after CHIM's own faction writes)
int folFixCount = 0        ; how many slots of folFixQ are taken (the tick's one hot-path test)
Actor[] folSeenQ           ; the NPCs the snapshot path looked at last (the "say it once" throttle)
float[] folSeenT
int folSeenSlot = 0

; --- v0.5.6 ExtCmdLRG_Escort: "follow me" for an NPC held by her own performance / idle scene ------
; The follow itself is CHIM's own package (LRG_Followers.ChimFollowPlayer). What is new is the one
; thing CHIM cannot do non-destructively: end the scene that outranks that package. One NPC at a
; time is watched for a few seconds afterwards, because a bard's quest or an idle quest may simply
; start its scene again. Real-time fields: cleared by Maintenance like every other one.
Actor escortActor = None   ; the NPC whose scene was just stopped (the follow-up check)
string escortName = ""
string escortCid = ""
string escortSafe = ""     ; the safe= list that came with the command ("" = the built-in default)
float escortCheckAt = 0.0
int escortTries = 0        ; how often the scene was ended again after it came back (at most 2)
int escortFree = 0         ; checks that found her free of any scene (2 = done watching)
; [0.5.7] what the late funcret needs if her scene comes back after "comes with you" (TickEscort)
string escortCall = ""     ; the name CHIM addressed the command to
string escortParam = ""    ; the command's parameter, echoed in the late funcret

; ---------------------------------------------------------------------------
; Lifecycle
; ---------------------------------------------------------------------------
Event OnInit()
	;/[0.5.5] OnInit ONLY sets members and arms the timer. Until OnInit returns this script is closed
	 to every other stack, so anything slow in here - a call into another script, a native on an
	 actor, a log line through LogC - can freeze the whole quest (the 0.5.4 saves prove it). The real
	 first boot is the next OnUpdate, on a stack of its own. On a game load OnPlayerLoadGame calls
	 Maintenance() exactly as before./;
	initBoot = true
	RegisterForSingleUpdate(0.5)
EndEvent

Function Maintenance()
	{Called on every game load (OnPlayerLoadGame) and, for a new instance, by the first OnUpdate
	 after OnInit. Safe to call repeatedly.}
	if installedVersion < CurrentVersion
		; stepwise upgrades go here (if installedVersion < 101 ... endif)
		installedVersion = CurrentVersion
	endif
	; [0.5.6] the boot generation goes up FIRST, before any call out of this script: a step of the
	; previous boot that is still waiting in a native can never mark this boot's table.
	bootGen += 1
	bootStep = 0
	bootSt = new int[12]      ; index = step, 1..BOOT_LAST (11)
	bootBegan = new float[12]
	bootRunning = 0
	bootLost = 0
	sessionTag = Utility.RandomInt(1000, 9999)
	cidCounter = 0
	; [0.5.2] the snapshot black box. The Bad flags are cleared on every load on purpose: a new
	; .pex may well have fixed the block, and it costs at most one snapshot to find out again.
	; [0.5.6] tokens: snapTok is NOT reset, so an attempt of the previous load that is still in flight
	; can never match a token of this one.
	snapRunTok = 0
	snapRunAt = 0.0
	snapWhere = 0
	snapLost = 0
	snapLostTok = 0
	snapLostDoor = false
	snapLostFol = false
	snapSkips = 0
	snapDoorFails = 0
	snapFolFails = 0
	snapDoorRun = false
	snapDoorBad = false
	snapFolRun = false
	snapFolBad = false
	escortActor = None
	escortName = ""
	escortCid = ""
	escortSafe = ""
	escortCheckAt = 0.0
	escortTries = 0
	escortFree = 0
	escortCall = ""
	escortParam = ""
	bootAt = Utility.GetCurrentRealTime()
	hbAt = 0.0
	hbCount = 0
	mcmChecked = false
	mcmLive = false
	; [0.5.5] the module boot table of this load: nobody is asked yet, nobody has confirmed
	initBoot = false
	modSt = new int[3]
	modAsk = new float[3]
	bootStalls = 0
	calibAsked = false
	; Utility.GetCurrentRealTime() restarts at 0 on every game launch: timestamps kept in the
	; save would lie in the "future" and silently block snapshots / commands for hours.
	cmdKeys = new string[4]
	cmdTimes = new float[4]
	cmdSlot = 0
	lastSnapActor = None
	lastSnapTime = 0.0
	lastAnySnapTime = 0.0
	lastMissActor = None
	lastMissTime = 0.0
	watchActor = None
	watchTalkTime = 0.0
	playerTalkTime = 0.0
	initAt = 0.0
	tickAt = 0.0
	snapTickAt = 0.0
	snapTickDue = 0.0
	; [pt19] quiet mode: the real-time cache and the parsed rows are fresh on every load
	quietOn = false
	quietAt = 0.0
	quietWhy = ""
	quietQuest = None
	quietIds = new string[8]
	quietFloors = new int[8]
	quietRows = -1
	lcValid = false
	lcLoc = None
	pcNpc = None
	pcCell = None
	pcLoc = None
	pcTime = 0.0
	Debug.OpenUserLog(ModName)
	;/v0.4.1: a hold the save remembers - from a session that ended in the middle of a conversation
	 - has to be given back on load: SetDontMove and a package override are both written into the
	 save. [0.5.4] It is boot step 2 now, NOT a call from here: ReleaseConvHold touches
	 ActorUtil.RemovePackageOverride (PapyrusUtil) and two Actor natives on a reference the save
	 carried over, so it is one of the blocks that can unwind a load. It runs about half a second
	 later on its own stack instead, still before anything can speak to her, and a session that
	 never held anybody returns from it on the first line./;
	convSkipActor = None
	convSkipAt = 0.0
	convPkg = None
	convPkgTried = false
	; v0.5: the latency stamps are real-time values, so the same rule as every other timestamp here -
	; nothing from the save may survive a load. latSent starts TRUE, i.e. "no reply is in flight".
	latAskStart = 0.0
	latAskStop = 0.0
	latTextAt = 0.0
	latTextNpc = ""
	latSent = true
	latFirstNpc = ""
	latFirstAt = 0.0
	; v0.5.1: the follower repair queue is real-time too, and a ghost from the previous session is
	; found again by FollowerSweep() below - never carried over in a field.
	folFixQ = new Actor[4]
	folFixT = new float[4]
	folFixCount = 0
	folSeenQ = new Actor[4]
	folSeenT = new float[4]
	folSeenSlot = 0

	;/[0.5.4] From here to the end of this function there is NOTHING that calls into another mod,
	 and the order is the order of what must survive:
	   1. the boot queue is armed FIRST, so that not even the four lines below it can stop the load;
	   2. the registrations - vanilla / SKSE, and the life of the whole feature, so they stay inline
	      (the heartbeat re-asserts them every two minutes anyway);
	   3. the proof of life, through three vanilla Debug natives.
	 Everything that reaches into CHIM, OStim, MCM Helper, PapyrusUtil or StorageUtil is a boot step
	 and runs on its own Papyrus stack - see BootTick./;
	bootStep = 1
	RegisterForSingleUpdate(0.4)
	tickAt = bootAt + 0.4
	UnregisterForAllModEvents()
	RegisterForModEvent("CHIM_CommandReceived", "OnChimCommand")
	RegisterForModEvent("CHIM_SpeechStarted", "OnChimSpeechStarted")
	RegisterForModEvent("CHIM_SpeechStopped", "OnChimSpeechStopped")
	RegisterForModEvent("CHIM_TextReceived", "OnChimTextReceived")
	; [0.5.5] the boot request of steps 5-7. Mod-event registrations belong to the FORM, so this one
	; line reaches OnLrgBoot in LRG_OStim, LRG_Dialogue and LRG_DlgProbe alike.
	RegisterForModEvent(BOOT_EVENT, "OnLrgBoot")
	RegisterForCrosshairRef()
	BootAnnounce()
EndFunction

Function BootAnnounce()
	;/[0.5.4] THE ONE STATEMENT THAT CANNOT BE STOPPED. Debug.Trace goes to Papyrus.0.log,
	 Debug.TraceUser to the mod's own user log and Debug.Notification to the corner of the screen -
	 three vanilla natives, none of which needs CHIM, MCM Helper, the server or any other mod.
	 Playtest 11 produced not one byte of evidence that the scripts had run at all, because every
	 channel the glue had went through CHIM's logMessageForActor behind two MCM reads. This answers
	 "are the scripts running" on its own.
	 bootNotify is TRUE in code and is only ever turned OFF by the MCM (read at boot step 10 and
	 kept in the save), so a fresh install, a broken MCM and a missing settings.ini all show it./;
	string line = "LoreRim Glue v" + CurrentVersion + " loaded - save " + installedVersion + ", session " + sessionTag
	Debug.Trace("[LRG] " + line)
	Debug.TraceUser(ModName, "[LRG] " + line)
	if bootNotify
		Debug.Notification("LoreRim Glue v" + CurrentVersion + " loaded - session " + sessionTag)
	endif
EndFunction

string Function BootStepName(int aiStep)
	;/The words behind a BOOT STEP LOST / LATE line. [0.5.6] named after what really runs in the step,
	 so a report points at the block and the mod behind it./;
	if aiStep == 1
		return "caching the player's name (Game.GetPlayer + GetDisplayName)"
	elseif aiStep == 2
		return "RegisterKeys (four MCM Helper reads, then RegisterForKey)"
	elseif aiStep == 3
		return "ReleaseConvHold (a hold the save remembered; PapyrusUtil ActorUtil)"
	elseif aiStep == 4
		return "the version line to the server (CHIM logMessageForActor)"
	elseif aiStep == 5
		return "asking LRG_OStim to boot (a mod event)"
	elseif aiStep == 6
		return "asking LRG_Dialogue to boot (a mod event)"
	elseif aiStep == 7
		return "asking LRG_DlgProbe to boot (a mod event)"
	elseif aiStep == 8
		return "the load-time snapshot of the crosshair NPC (MaybeSnapshot)"
	elseif aiStep == 9
		return "FollowerSweep (po3 GetActorsByProcessingLevel + LRG_Followers.IsGhost per actor)"
	elseif aiStep == 10
		return "the version line again, after CHIM is up"
	elseif aiStep == 11
		return "reading bBootNotify / bHeartbeat (MCM Helper)"
	endif
	return "step " + aiStep
EndFunction

bool Function BootBegin(int aiStep, float afNow)
	;/[0.5.6] Marks a CLAIMED step as running. Member writes only - nothing here calls out of the
	 script, so the claim in BootTick and this mark are one uninterrupted stretch./;
	if !bootSt || bootSt.Length != 12 || aiStep < 1 || aiStep > BOOT_LAST
		return false
	endif
	if bootSt[aiStep] == 1
		bootRunning -= 1 ; defensive: the claim means a step never begins twice in one boot
	endif
	bootSt[aiStep] = 1
	bootBegan[aiStep] = afNow
	bootRunning += 1
	return true
EndFunction

Function BootEnd(int aiStep, int aiGen, float afFin)
	;/[0.5.6] A step has RETURNED. aiGen is the boot generation the step began in: a step of an earlier
	 boot (Maintenance ran again while it waited) marks nothing. A step that had already been judged
	 lost says so - it was slow, not dead - and the verdict is taken back./;
	if aiGen != bootGen || !bootSt || bootSt.Length != 12 || aiStep < 1 || aiStep > BOOT_LAST
		return
	endif
	int was = bootSt[aiStep]
	bootSt[aiStep] = 2
	if was == 1
		bootRunning -= 1
		if bootRunning < 0
			bootRunning = 0
		endif
		if aiStep == 9
			snapFolFails = 0 ; the sweep went through the follower natives cleanly: the count starts over
		endif
	elseif was == 3
		bootLost -= 1
		if bootLost < 0
			bootLost = 0
		endif
		string back = ""
		if aiStep == 9
			back = SnapFolUncount()
		endif
		LogE("", "BOOT STEP LATE: " + aiStep + " (" + BootStepName(aiStep) + ") returned after " \
			+ ((afFin - bootBegan[aiStep]) as int) + " s - it was slow, not lost" + back, "")
	endif
EndFunction

Function BootJudge(float afNow)
	;/[0.5.6] The only place a boot step is ever called LOST: it began BOOT_LOST_SECS ago and has not
	 returned. A step that is merely waiting in a native on its own stack - which is what every one
	 of playtest 13's four "aborted" steps was doing - is never judged before that. Called from every
	 boot tick and every heartbeat; one compare while nothing is running./;
	if bootRunning <= 0 || !bootSt || bootSt.Length != 12
		return
	endif
	int i = 1
	while i <= BOOT_LAST
		if bootSt[i] == 1
			float age = afNow - bootBegan[i]
			if age >= BOOT_LOST_SECS || age < 0.0
				bootSt[i] = 3
				bootRunning -= 1
				bootLost += 1
				string extra = ""
				if i == 9
					extra = " - " + SnapFolCount("the follower sweep")
				endif
				LogE("", "BOOT STEP LOST: " + i + " (" + BootStepName(i) + ") began " + (age as int) \
					+ " s ago and has not returned - it is stuck in that call; the rest of the load carried on" + extra, "")
			endif
		endif
		i += 1
	endwhile
	if bootRunning < 0
		bootRunning = 0
	endif
EndFunction

string Function BootOpenText(int aiSelf)
	{The steps still running (other than aiSelf), for the second version line. "none" when all returned.}
	if !bootSt || bootSt.Length != 12
		return "-"
	endif
	string out = ""
	int i = 1
	while i <= BOOT_LAST
		if i != aiSelf && bootSt[i] == 1
			if out != ""
				out += ","
			endif
			out += i
		endif
		i += 1
	endwhile
	if out == ""
		return "none"
	endif
	return out
EndFunction

Function BootTick()
	;/[0.5.4] ONE step per OnUpdate, and the next tick is armed BEFORE the step runs, so an abort
	 inside a step cannot stop the step after it. bootStep is advanced first for the same reason:
	 a step that dies is never retried in a loop.
	 [0.5.5] Steps 5-7 no longer CALL the three modules - they ask them with a mod event and return
	 (BootAsk). A module that hangs therefore hangs on its own stack, never on this one, and the
	 watchdog (BootWatch) names it after BOOT_STALL_SECS.
	 [0.5.6] Every call this function makes BEFORE the claim (the clock, ModPending) is made first,
	 and bootStep is then read, tested and advanced with no call in between - so two stacks can never
	 run the same step. Step 8's "wait for LRG_OStim" and step 10's "wait for its time" no longer
	 claim the step at all; they only re-arm the tick. Each step is marked running (BootBegin) and
	 returned (BootEnd) under its own number and this boot's generation, and BootJudge is the only
	 thing that may call one lost./;
	float now = Utility.GetCurrentRealTime()
	bool ostPending = ModPending(0)
	int s = bootStep
	if s <= 0
		return
	endif
	if s == BOOT_AGAIN && now >= bootAt && now < (bootAt + 25.0)
		;/[0.5.5, verifier D3] Woken early - by another requester or by the watchdog. This step exists
		 to speak AFTER CHIM's load-time queue clear, so it waits for its time instead of running./;
		float rest = (bootAt + 25.0) - now
		tickAt = now + rest
		RegisterForSingleUpdate(rest)
		return
	endif
	if s == 8 && ostPending
		;/G4 / [0.5.5]: the load-time snapshot waits for LRG_OStim's boot (a save made mid-scene must not
		 be reported as "no scene") - never longer than the watchdog, after which ModPending is false./;
		tickAt = now + 0.25
		RegisterForSingleUpdate(0.25)
		return
	endif
	; THE CLAIM: from reading bootStep above to these two writes there is no call out of this script.
	bootStep = s + 1
	int gen = bootGen
	BootBegin(s, now)
	if s < BOOT_LAST
		float wait = 0.25
		if (s + 1) == BOOT_AGAIN
			;/CHIM clears its own outgoing queues at OnLoadedGame, which is the same moment
			 OnPlayerLoadGame runs, so a line handed to logMessageForActor during the load window
			 can be thrown away before it is ever sent. The version line is said again once CHIM is up./;
			wait = (bootAt + 25.0) - now
			if now < bootAt
				wait = 25.0
			elseif wait < 0.25
				wait = 0.25
			endif
		endif
		tickAt = now + wait
		RegisterForSingleUpdate(wait)
	else
		;/The queue is done and the heartbeat owns the timer from here - so the heartbeat's FIRST
		 beat is armed right now, before the last step runs. The last step is the one that reads the
		 MCM, and an abort in it must not be able to leave this quest without a timer for ever./;
		bootStep = 0
		hbAt = now
		hbCount = 0
		; 5 s, not 30: the normal fan-out (LRG_OStim.Tick, SnapTick) has been standing still while
		; the queue owned the timer, so it gets its first look shortly after the load instead of
		; half a minute later. Heartbeat() renews the timer from there.
		tickAt = now + 5.0
		RegisterForSingleUpdate(5.0)
	endif
	BootJudge(now)
	if s == 1
		;/[0.5.5] LogC's actor name, read ONCE, here, on a stack of its own. LogC used to ask for the
		 player's display name on every single line, and that native call is exactly where the frozen
		 OnInit of the 0.5.4 saves is parked./;
		string nm = Game.GetPlayer().GetDisplayName()
		if nm != ""
			playerName = nm
		endif
	elseif s == 2
		RegisterKeys()
	elseif s == 3
		; v0.4.1: a hold the save remembers is given back before anything can speak to her
		ReleaseConvHold("the game was loaded")
	elseif s == 4
		;/R4: the RUNNING script version, not the one the save happens to carry. installedVersion is
		 a saved variable, so a .pex that never loaded (a failed install, a stale Overwrite copy)
		 would otherwise be invisible; CurrentVersion is compiled into this .pex and cannot lie./;
		Log("maintenance done, version " + CurrentVersion + ", save " + installedVersion + ", session " + sessionTag)
	elseif s == 5
		; the intimacy module (OStim). It re-registers its OStim events itself, inside its own boot.
		BootAsk(0)
	elseif s == 6
		; v0.4: the menuless questing module - menus, its pump event, crosshair - on its own boot.
		BootAsk(1)
	elseif s == 7
		;/The probe and the calibration. v0.5 W1's ev=calib on load goes out once BOTH LRG_Dialogue
		 and LRG_DlgProbe have confirmed (BootConfirm), from LRG_Dialogue's own stack./;
		BootAsk(2)
	elseif s == 8
		;/G4, path 3: after a load the server may still hold a scene row from the session before
		 this one. This forced snapshot carries ostim=0 and the NEW sess, so a row left over from
		 another partner is closed in the same second. Only when the player is looking at an agent.
		 [0.5.5] It waits for LRG_OStim's boot (above), and never longer than the watchdog./;
		Actor look = Game.GetCurrentCrosshairRef() as Actor
		if look
			if MaybeSnapshot(look, true)
				Watch(look, false)
			endif
		endif
	elseif s == 9
		;/v0.5.1 (pt9 G3): a ghost the save carries over is found on load, once.
		 [0.5.6] It no longer borrows the SNAPSHOT rail (0.5.3 held snapBusy and the fol= breadcrumb
		 across it, so every snapshot that arrived meanwhile read it as an aborted snapshot and
		 retired fol= - playtest 13's "stage 3 ... fol= / witchim are off"). It is boot step 9 and the
		 boot rail judges it; a LOST step 9 counts once toward retiring fol= (SnapFolCount), a clean
		 one resets that count (BootEnd)./;
		FollowerSweep()
	elseif s == BOOT_AGAIN
		Log("maintenance done, version " + CurrentVersion + ", save " + installedVersion \
			+ ", session " + sessionTag + ", boot steps " + BOOT_LAST + ", still running " + BootOpenText(s) \
			+ ", lost " + bootLost + ", modules " + ModStateText() + ", stalled " + bootStalls \
			+ ", snapshots in flight skipped " + snapSkips + ", lost " + snapLost \
			+ ", devdry " + LRG_Profile.B2I(IsDryRun())) ; [pt17] the developer dry run, distinguishable from bDlgDryRun in the log
	elseif s == 11
		;/The settings the hot paths must not read from MCM every time, and the heartbeat that keeps
		 this quest ticking. Both default to TRUE in code and are kept in the save, so a load whose
		 MCM read dies still shows the corner note the NEXT time./;
		if !SettingsLive() && MCM.IsInstalled()
			LogE("", "MCM Helper has no settings for " + ModName + " - running on the built-in defaults", "")
		endif
		bootNotify = SettingBool("bBootNotify:General", true)
		hbOn = SettingBool("bHeartbeat:General", true)
		LogRefresh()
		;/[pt17] The developer dry run is LOUD on every load it is on: a message box (blocks until
		 dismissed - intended), an error line in the log, and Heartbeat repeats a corner note every
		 two minutes. SettingBool answers FALSE on built-in defaults, so this never fires without
		 MCM Helper's settings. Its sister switch bDlgDryRun (menuless questing) is LRG_Dialogue's
		 and is reported by its own self-test line./;
		if devDry
			LogE("", "DEV DRY RUN IS ON (bDryRun:General, Diagnostics page) - every glue command is refused and only logged as WOULD; switch it off for play", "")
			Debug.MessageBox("LoreRim Glue: the DEVELOPER dry run is ON (MCM > LoreRim Glue > Diagnostics, bottom). Every command NPCs try will fail until it is off - undressing, scenes, follow / wait / release, clicks, services. It is not the menuless-questing dry run; you never need it for play.")
		else
			Log("dev dry run off (bDryRun:General 0)")
		endif
	endif
	BootEnd(s, gen, Utility.GetCurrentRealTime())
EndFunction

Function Heartbeat()
	;/[0.5.4] Playtest 10's second session and playtest 11 both ended with this quest completely
	 inert: no CHIM event, no crosshair event, no tick, for the rest of the session. Nothing in the
	 mod could restart it, because every RequestTick call site sits inside a handler that was not
	 running. A beat every 30 s costs one OnUpdate and two float compares, and it makes that state
	 impossible to stay in: every fourth beat re-asserts the crosshair and mod-event registrations.
	 It is the FIRST thing in OnUpdate and it asks for its next tick BEFORE it touches a single
	 native, so nothing that runs later on that stack can stop it./;
	if !hbOn || bootStep > 0
		return
	endif
	float now = Utility.GetCurrentRealTime()
	;/The timer is renewed on EVERY tick, not only on a beat. OnUpdate is often woken by somebody
	 else - LRG_OStim's 0.8 s state pushes, a 20 s snapshot refresh - and every one of those
	 requesters may find nothing due and ask for nothing back. Without this line the quest would be
	 left with no timer at all the moment that happens, which is the hole this whole function
	 exists to close. RequestTick keeps the EARLIEST wanted time, so it never delays anybody./;
	RequestTick(30.0)
	if now >= hbAt && (now - hbAt) < 25.0
		return
	endif
	hbAt = now
	hbCount += 1
	LogRefresh() ; [pt15] two MCM reads every 30 s, so a level change applies in-session
	;/[0.5.6] Once the queue is done nobody else looks at the boot table, so every beat does: a step
	 still out after BOOT_LOST_SECS is named here (one compare while nothing is running, i.e. always
	 once the load has settled)./;
	BootJudge(now)
	if (hbCount % 4) == 0
		if devDry
			Debug.Notification(DevDryNote()) ; [pt17] every two minutes while the developer dry run is on
		endif
		RegisterForCrosshairRef()
		RegisterForModEvent("CHIM_CommandReceived", "OnChimCommand")
		RegisterForModEvent("CHIM_SpeechStarted", "OnChimSpeechStarted")
		RegisterForModEvent("CHIM_SpeechStopped", "OnChimSpeechStopped")
		RegisterForModEvent("CHIM_TextReceived", "OnChimTextReceived")
		RegisterForModEvent(BOOT_EVENT, "OnLrgBoot")
	endif
EndFunction

LRG_OStim Function GetOStim()
	;/[0.5.5] None until LRG_OStim has confirmed its boot in THIS load (BootConfirm), and None again
	 once the watchdog has called it stalled. Every caller already treats None as "not there", so a
	 module that is still booting - or frozen - is never called from here, and this script can never
	 be parked waiting on it./;
	if !ModReady(0)
		return None
	endif
	return (self as Quest) as LRG_OStim
EndFunction

LRG_Dialogue Function GetDialogue()
	;/v0.4: the menuless questing driver. It sits on this same quest form, so it is reached by a
	 cast, exactly like LRG_OStim. None means the script is not attached to this save's quest
	 (a save made after the ESP of a previous version) - the module then stays off, silently.
	 [0.5.5] ...or that it has not confirmed its boot in this load - see GetOStim./;
	if !ModReady(1)
		return None
	endif
	return (self as Quest) as LRG_Dialogue
EndFunction

LRG_DlgProbe Function GetProbe()
	;/v0.4: the first-playtest probe. Armed by bProbe + iKeyProbe in the MCM; it registers its own
	 key inside its Maintenance(), which runs after RegisterKeys().
	 [0.5.5] None until it has confirmed its boot in this load - see GetOStim./;
	if !ModReady(2)
		return None
	endif
	return (self as Quest) as LRG_DlgProbe
EndFunction

; ---------------------------------------------------------------------------
; [0.5.5] The module boot table: ask (a mod event), confirm (a call back), watchdog.
; This script only ever reads its OWN members here - never a member of a module - because a read
; of another script is a call, and a call into a script that is not free parks the caller.
; ---------------------------------------------------------------------------
string Function ModScript(int aiMod)
	if aiMod == 0
		return "LRG_OStim"
	elseif aiMod == 1
		return "LRG_Dialogue"
	endif
	return "LRG_DlgProbe"
EndFunction

bool Function ModAttached(int aiMod)
	{A cast only - it never runs code in the module.}
	Quest q = self as Quest
	if aiMod == 0
		return (q as LRG_OStim) != None
	elseif aiMod == 1
		return (q as LRG_Dialogue) != None
	endif
	return (q as LRG_DlgProbe) != None
EndFunction

bool Function ModReady(int aiMod)
	if modSt.Length != 3
		return false
	endif
	return modSt[aiMod] == 2
EndFunction

bool Function ModPending(int aiMod)
	{True while this load's boot has not yet asked that module, or asked it and has not heard back -
	 never longer than BOOT_STALL_SECS + 5 s, even if no tick ever came.}
	if modSt.Length != 3
		return false
	endif
	int st = modSt[aiMod]
	if st != 0 && st != 1
		return false
	endif
	if st == 0 && bootStep <= 0
		return false
	endif
	float since = bootAt
	if st == 1
		since = modAsk[aiMod]
	endif
	float now = Utility.GetCurrentRealTime()
	return now >= since && (now - since) < (BOOT_STALL_SECS + 5.0)
EndFunction

string Function ModStateText()
	{For the second version line: ostim/dialogue/probe = ok, wait, STALL, absent or not asked.}
	if modSt.Length != 3
		return "-"
	endif
	string out = ""
	int i = 0
	int st = 0
	string w = ""
	while i < 3
		st = modSt[i]
		w = "not asked"
		if st == 1
			w = "wait"
		elseif st == 2
			w = "ok"
		elseif st == 3
			w = "STALL"
		elseif st == 4
			w = "absent"
		endif
		if i > 0
			out += "/"
		endif
		out += w
		i += 1
	endwhile
	return out
EndFunction

string Function ModDownWhy(int aiMod, string asAbsent)
	{The funcret text when a module accessor answered None.}
	if !ModAttached(aiMod)
		return asAbsent
	endif
	if ModPending(aiMod)
		return "Error: the glue is still starting after the load - say it again in a moment"
	endif
	return "Error: this part of the glue did not start this session"
EndFunction

bool Function SendBoot(string asWho)
	{One LRG_Boot mod event (who, session). Every script on this form receives it; only asWho acts.}
	int h = ModEvent.Create(BOOT_EVENT)
	if !h
		return false
	endif
	ModEvent.PushString(h, asWho)
	ModEvent.PushInt(h, sessionTag)
	return ModEvent.Send(h)
EndFunction

Function BootAsk(int aiMod)
	;/[0.5.5] Boot steps 5-7. The module is ASKED to boot - a mod event that it receives on a stack of
	 its own - and this function returns at once. Until 0.5.4 this was a direct call, and a direct
	 call into a script that is not free parks THIS stack, and with it every event of this quest,
	 until that script is free again: with a frozen OnInit, never. The module answers BootConfirm()./;
	if modSt.Length != 3
		modSt = new int[3]
		modAsk = new float[3]
	endif
	if !ModAttached(aiMod)
		modSt[aiMod] = 4
		LogE("", ModScript(aiMod) + " is not attached to this save's quest - that part of the glue stays off", "")
		return
	endif
	modSt[aiMod] = 1
	modAsk[aiMod] = Utility.GetCurrentRealTime()
	if !SendBoot(ModScript(aiMod))
		LogE("", "BOOT: the boot event for " + ModScript(aiMod) + " could not be sent - the watchdog will name it", "")
	endif
EndFunction

Function BootConfirm(int aiMod, int aiSess)
	{Called by LRG_OStim / LRG_Dialogue / LRG_DlgProbe at the END of their own boot, on their own
	 stack. A confirmation that belongs to an earlier load is ignored.}
	if aiSess != sessionTag || modSt.Length != 3 || aiMod < 0 || aiMod > 2
		return
	endif
	int was = modSt[aiMod]
	modSt[aiMod] = 2
	if was == 3
		LogE("", "BOOT LATE: " + ModScript(aiMod) + " confirmed its boot after all - it is back on", "")
	endif
	if !calibAsked && modSt[1] == 2 && modSt[2] == 2
		;/v0.5 W1: the calibration belongs to the INSTALL, so one ev=calib goes out per load, after
		 the probe has re-armed its per-session state. LRG_Dialogue sends it on its own stack./;
		calibAsked = true
		SendBoot("calib")
	endif
EndFunction

Function BootWatch()
	;/[0.5.5] THE BOOT WATCHDOG. A module that was asked and has not confirmed within BOOT_STALL_SECS
	 is named once - BOOT STALL - through LogC (whose trace comes first and calls no native before it),
	 is left out for the rest of this load (its accessor stays None), and is asked once more in case
	 the first event was lost. Nothing waits for it: the queue, the tick and the snapshot go on.
	 Reads this script's own members only./;
	if modSt.Length != 3
		return
	endif
	if modSt[0] != 1 && modSt[1] != 1 && modSt[2] != 1
		return
	endif
	float now = Utility.GetCurrentRealTime()
	float due = 0.0
	float age = 0.0
	float left = 0.0
	int i = 0
	while i < 3
		if modSt[i] == 1
			age = now - modAsk[i]
			if age >= BOOT_STALL_SECS || age < 0.0
				modSt[i] = 3
				bootStalls += 1
				BootStall(i)
			else
				left = BOOT_STALL_SECS - age
				if due <= 0.0 || left < due
					due = left
				endif
			endif
		endif
		i += 1
	endwhile
	if due > 0.0
		RequestTick(due + 0.2)
	endif
EndFunction

Function BootStall(int aiMod)
	LogE("", "BOOT STALL: " + ModScript(aiMod) + " did not confirm its boot within " + (BOOT_STALL_SECS as int) \
		+ " s - the rest of the glue carries on without it this session", "")
	if bootNotify
		Debug.Notification("LoreRim Glue: " + ModScript(aiMod) + " did not start (BOOT STALL)")
	endif
	SendBoot(ModScript(aiMod))
EndFunction

; ---------------------------------------------------------------------------
; Settings (MCM Helper). Every read goes through here so a missing MCM Helper
; degrades to safe defaults instead of breaking the script.
; ---------------------------------------------------------------------------
bool Function SettingsLive()
	;/[0.5.4] THE CANARY. MCM Helper's GetModSetting* returns false / 0 / 0.0 for a key it does not
	 have - it never falls back to the caller's default - so an unregistered config, a settings.ini
	 that a hand-copy missed, or one renamed key used to read bEnabled = FALSE and switch the whole
	 mod off with no message anywhere. iSettingsVersion is in settings.ini, is not on any MCM page
	 and therefore cannot be changed by the owner; if it comes back 0, MCM Helper does not have our
	 settings at all and every Setting* call below answers with the CALLER'S default instead.
	 Read once per load (cleared by Maintenance) so the hot paths cost nothing extra./;
	if mcmChecked
		return mcmLive
	endif
	mcmChecked = true
	mcmLive = false
	if MCM.IsInstalled()
		mcmLive = MCM.GetModSettingInt(ModName, "iSettingsVersion:General") > 0
	endif
	return mcmLive
EndFunction

bool Function SettingBool(string asKey, bool abDefault)
	if !SettingsLive()
		return abDefault
	endif
	return MCM.GetModSettingBool(ModName, asKey)
EndFunction

int Function SettingInt(string asKey, int aiDefault)
	if !SettingsLive()
		return aiDefault
	endif
	return MCM.GetModSettingInt(ModName, asKey)
EndFunction

float Function SettingFloat(string asKey, float afDefault)
	if !SettingsLive()
		return afDefault
	endif
	return MCM.GetModSettingFloat(ModName, asKey)
EndFunction

bool Function IsEnabled()
	{Global kill switch. When off, nothing is sent, offered or executed.}
	return SettingBool("bEnabled:General", true) && !SettingBool("bKillSwitch:General", false)
EndFunction

bool Function IsIntimacyEnabled()
	return IsEnabled() && SettingBool("bIntimacyEnabled:Intimacy", true)
EndFunction

bool Function IsDryRun()
	return SettingBool("bDryRun:General", false)
EndFunction

float Function InitiativeInterval()
	float v = SettingFloat("fInitiativeInterval:Initiative", 90.0)
	if v < 30.0
		v = 30.0 ; contract minimum
	endif
	return v
EndFunction

; ---------------------------------------------------------------------------
; [pt19 / script 511] QUIET MODE (research/pt19-helgen.md section 7; PROTOCOL 10.28)
; Helgen, 2026-09-24: the keep intro scene never reached MQ101 stage 240 (the only stage that gives the
; player his hands back after Alduin) and the log could not prove the glue's innocence, because the
; snapshots, witness scans, the facts sweep, the 4 s follow poll and the initiative tick all run on
; scene actors without one line at level Normal. While a CURATED quest (QUIET_QUESTS) is running, not
; complete and at or past its stage floor, and bQuietIntro:Quests is on, the glue stands aside: no
; snapshot of anybody (a forced one too), no watch / SnapTick / initiative, no conversation hold, no
; natural follow, no follower repair, no escort and no quest entry (both refused with the real reason,
; voiced); [v1.0] the menuless driver opens no conversation and drives none (read-only, model F17).
; CHIM's own agents, its scene refusal and its packages are untouched;
; the OStim stop key, the kill switch and the never-silent voice stay. One log line on engage, one on
; release. The decision is cached for QUIET_LOOK_SECS: a member read between looks, then per row
; GetCurrentStageID first (AP runs MQ101 at stage 0 from the first minute of a new game, so below the
; floor a row costs one native), IsRunning and IsCompleted. No PO3 objective walk and no GetCurrentScene:
; MQ101 shows no objective and runs no scene between stage 200 and 250, exactly where the stall sits.
; ---------------------------------------------------------------------------
bool Function QuietOn()
	{True while the glue must stand aside for a scripted intro. Cached QUIET_LOOK_SECS; logs the engage and the release.}
	float now = Utility.GetCurrentRealTime()
	if quietAt > 0.0 && now >= quietAt && (now - quietAt) < QUIET_LOOK_SECS
		return quietOn
	endif
	quietAt = now
	bool was = quietOn
	string why = ""
	if SettingBool("bQuietIntro:Quests", true)
		why = QuietLook()
	endif
	quietOn = (why != "")
	if quietOn
		quietWhy = why ; [review] refreshed on EVERY look, so err= and the release line name the current stage, not the one it engaged at
	endif
	if quietOn && !was
		Log("QUIET on: " + why + " - no snapshots, holds, natural follow, follower repair, escort, quest entry, opened conversations or initiative until it ends (bQuietIntro:Quests)")
	elseif !quietOn && was
		Log("QUIET off: " + quietWhy + " - " + QuietReleaseWhy() + " - the glue is back")
		quietWhy = ""
		quietQuest = None
	endif
	return quietOn
EndFunction

string Function QuietLook()
	{"" = no curated quest runs past its floor; else "<EditorID> stage <n>" of the first one that does (at most 8 rows).}
	if quietRows < 0 || !quietIds || quietIds.Length != 8 || !quietFloors || quietFloors.Length != 8
		QuietParse() ; a fresh load has the arrays but no rows; a save from before script 511 has neither
	endif
	int i = 0
	while i < quietRows
		Quest q = QuietQuest(quietIds[i])
		if q != None
			int st = q.GetCurrentStageID()
			if st >= quietFloors[i] && q.IsRunning() && !q.IsCompleted()
				quietQuest = q
				return quietIds[i] + " stage " + st
			endif
		endif
		i += 1
	endwhile
	return ""
EndFunction

string Function QuietReleaseWhy()
	{The middle of the release line: what ended the quiet spell.}
	if !SettingBool("bQuietIntro:Quests", true)
		return "switched off in the MCM"
	endif
	if quietQuest == None
		return "no curated quest runs"
	endif
	if quietQuest.IsCompleted()
		return "the quest is complete"
	endif
	if !quietQuest.IsRunning()
		return "the quest stopped"
	endif
	return "the quest fell below its stage floor"
EndFunction

Quest Function QuietQuest(string asId)
	{The quest of a curated row: by EditorID (SKSE), and by FormID for MQ101 (Skyrim.esm 0003372B) when the ID lookup fails.}
	Quest q = Quest.GetQuest(asId)
	if q == None && asId == "MQ101"
		q = Game.GetFormFromFile(0x0003372B, "Skyrim.esm") as Quest
	endif
	return q
EndFunction

Function QuietParse()
	{QUIET_QUESTS -> quietIds / quietFloors. A row is "<EditorID>" or "<EditorID>>=<stage>", spaces ignored, at most 8; an empty list means MQ101>=5, never "quiet everywhere".}
	quietIds = new string[8]
	quietFloors = new int[8]
	quietRows = 0
	string csv = QUIET_QUESTS
	if EscortTrim(csv) == ""
		csv = "MQ101>=5"
	endif
	int len = StringUtil.GetLength(csv)
	int start = 0
	while start < len && quietRows < 8
		int comma = StringUtil.Find(csv, ",", start)
		string row = ""
		if comma < 0
			row = StringUtil.Substring(csv, start)
			start = len
		else
			if comma > start
				row = StringUtil.Substring(csv, start, comma - start)
			endif
			start = comma + 1
		endif
		row = EscortTrim(row)
		int fl = 0
		int ge = StringUtil.Find(row, ">=")
		if ge > 0
			fl = EscortTrim(StringUtil.Substring(row, ge + 2)) as int
			row = EscortTrim(StringUtil.Substring(row, 0, ge))
		endif
		if row != ""
			quietIds[quietRows] = row
			quietFloors[quietRows] = fl
			quietRows += 1
		endif
	endwhile
EndFunction

string Function QuietWords()
	{Her words for what is running: "the Helgen business" for MQ101, else "a scripted scene of the game".}
	if StringUtil.Find(quietWhy, "MQ101") == 0
		return "the Helgen business"
	endif
	return "a scripted scene of the game"
EndFunction

Function RegisterKeys()
	UnregisterForAllKeys()
	keyStopScene = SettingInt("iKeyStopScene:Keys", 0)
	keyDumpTopics = SettingInt("iKeyDumpTopics:Keys", 0)
	keyLeave = SettingInt("iKeyLeave:Keys", 0)
	;/[v1.0 / S4.6, S10] the talk key: CHIM's own push-to-talk key (29 = Left Ctrl on this install, per
	 AIAgent.log "Using mapped key code: 29"). CHIM raises no event for it, so the glue listens itself -
	 one OnKeyDown per press, no polling. 0 (or a cleared keymap, -1) = off./;
	keyTalk = SettingInt("iKeyPushToTalk:Dialogue", 29)
	if keyStopScene > 0
		RegisterForKey(keyStopScene)
	endif
	if keyDumpTopics > 0
		RegisterForKey(keyDumpTopics)
	endif
	if keyLeave > 0
		RegisterForKey(keyLeave)
	endif
	if keyTalk > 0
		RegisterForKey(keyTalk)
	endif
EndFunction

Event OnKeyDown(int aiKey)
	;/v0.4 (design D-13, NARROWED). A text field always wins and always returns early: one letter
	 typed into CHIM's Prisma chatbox, UITextEntryMenu or the console must never leave a conversation.
	 Only the Utility.IsInMenuMode() half is bypassed, only for the leave key, and only while the
	 menuless module really has a session open. Through LRG_DlgUI, not UI.* directly (build plan 11).
	 [v1.0] The emergency key is gone with the hidden menu: the list is always on screen. The talk key
	 is CHIM's push-to-talk: a press only tells the menuless driver that he is about to speak, so an
	 auto-advance that is waiting stands down (S4.6) - CHIM handles the key itself as it always did./;
	if LRG_DlgUI.TextInputOn()
		return
	endif
	if keyTalk > 0 && aiKey == keyTalk
		NoteSpeechToDriver(0, Utility.GetCurrentRealTime(), None)
	endif
	if Utility.IsInMenuMode()
		if aiKey != keyLeave
			return
		endif
		LRG_Dialogue live = GetDialogue()
		if !live || !live.IsSessionOpen()
			return
		endif
	endif
	if aiKey == keyStopScene
		; v0.4.1: one panic key for both holds - it already frees the outro hold inside StopScene
		ReleaseConvHold("the stop key")
		LRG_OStim ost = GetOStim()
		if ost
			ost.StopScene("hotkey")
		endif
	elseif aiKey == keyLeave
		LRG_Dialogue dlg2 = GetDialogue()
		if dlg2
			dlg2.HandleLeaveKey()
		endif
	elseif aiKey == keyDumpTopics
		LRG_Dialogue dlg3 = GetDialogue()
		if dlg3
			dlg3.DumpTopics()
		else
			Debug.Notification("LoreRim Glue: the menuless questing module is not attached")
		endif
	endif
EndEvent

; ---------------------------------------------------------------------------
; Logging / correlation ids
; ---------------------------------------------------------------------------
string Function NextCid()
	cidCounter += 1
	return "g" + sessionTag + "-" + cidCounter
EndFunction

Function Log(string asMsg)
	LogC("", asMsg, "")
EndFunction

Function LogC(string asCid, string asMsg, string asNpcName)
	{Debug log, level 1 (normal). Also LogV (level 2, chatter) and LogE (level 0, errors). Sent to the
	 server as lrg_log (lorerim_glue.log, prefixed GAME). Payload: cid=<cid>;msg=<text>.}
	;/[pt15 performance] LRG_DlgProbe's calibration chatter reaches this function too, and that file
	 is not touched this round, so its periodic lines are demoted here by their prefix: CALIB SUMMARY /
	 GATE / probe / X1 / active go to level 2. CALIB set, CALIB armed and CALIB WARNING stay at level 1.
	 One StringUtil.Find per line; a CALIB line pays up to five more./;
	int lvl = 1
	if StringUtil.Find(asMsg, "CALIB ") == 0
		if StringUtil.Find(asMsg, "CALIB SUMMARY") == 0 || StringUtil.Find(asMsg, "CALIB GATE") == 0 \
			|| StringUtil.Find(asMsg, "CALIB probe") == 0 || StringUtil.Find(asMsg, "CALIB X1") == 0 \
			|| StringUtil.Find(asMsg, "CALIB active") == 0
			lvl = 2
		endif
	endif
	LogAt(lvl, asCid, asMsg, asNpcName)
EndFunction

Function LogV(string asCid, string asMsg, string asNpcName)
	{Level 2 - chatter: conversation holds, ev=facts, initiative ticks, listener re-asserts, LAT.}
	if logLevel < 2
		return ; the cheapest possible exit: one member compare
	endif
	LogAt(2, asCid, asMsg, asNpcName)
EndFunction

Function LogE(string asCid, string asMsg, string asNpcName)
	{Level 0 - errors and warnings: logged at every level, and always traced to the Papyrus log.}
	LogAt(0, asCid, asMsg, asNpcName)
EndFunction

Function LogRefresh()
	{[pt15] Reads iLogLevel and bDebugLog into the cache LogAt uses. Boot step 11 and every heartbeat.}
	int lv = SettingInt("iLogLevel:General", 1)
	if lv < 0 || lv > 2
		lv = 1
	endif
	logLevel = lv
	logSend = SettingBool("bDebugLog:General", true)
	devDry = SettingBool("bDryRun:General", false) ; [pt17] the developer dry run, announced by Heartbeat
EndFunction

string Function DevDryNote()
	{[pt17] The one sentence every announcement of the developer dry run uses.}
	return "LoreRim Glue: DEV dry-run is ON (MCM > LoreRim Glue > Diagnostics, bottom) - nothing NPCs try will happen until it is off"
EndFunction

Function LogAt(int aiLevel, string asCid, string asMsg, string asNpcName)
	;/[pt15 performance] THE ONE WRITER. Until 507 every line cost a Debug.Trace, a Debug.TraceUser,
	 three MCM reads and a server request. Now: a line above the cached level costs one compare; the
	 Papyrus traces are written only at level 2 or for an error (level 0); bDebugLog is the cached copy.
	 The 0.5.4 rule still holds - nothing here reads the MCM before the trace: the level is a member,
	 so a Papyrus error inside MCM Helper can never silence an error line. The load line itself is
	 BootAnnounce's, which traces unconditionally through three vanilla natives./;
	if aiLevel > logLevel
		return
	endif
	if aiLevel == 0 || logLevel >= 2
		string tline = "[LRG] " + asMsg
		if asCid != ""
			tline = "[LRG] cid=" + asCid + " " + asMsg
		endif
		Debug.Trace(tline)
		Debug.TraceUser(ModName, tline)
	endif
	if !logSend
		return
	endif
	if !IsEnabled()
		return ; the promise of the master switches: nothing is sent
	endif
	;/[0.5.5] the player's name is the one cached by boot step 1 ("Player" until then). LogC calls
	 no native on an actor any more: the frozen stack in the 0.5.4 saves is parked in exactly that
	 call (Actor.GetDisplayName inside LogC, reached from an OnInit)./;
	string who = asNpcName
	if who == ""
		who = playerName
	endif
	string msg = CleanForWire(asMsg)
	if StringUtil.GetLength(msg) > 300
		msg = StringUtil.Substring(msg, 0, 300)
	endif
	AIAgentFunctions.logMessageForActor("cid=" + asCid + ";msg=" + msg, MSG_LOG, who)
EndFunction

string Function CleanForWire(string asText)
	{The wire format forbids ; | @ " and newlines inside a value.}
	string out = ReplaceChar(asText, ";", ",")
	out = ReplaceChar(out, "|", "/")
	out = ReplaceChar(out, "@", " at ")
	out = ReplaceChar(out, "\"", "'")
	out = ReplaceChar(out, "\n", " ")
	return out
EndFunction

string Function ReplaceChar(string asText, string asFrom, string asTo)
	string rest = asText
	string out = ""
	int guard = 0
	int i = StringUtil.Find(rest, asFrom)
	; the guard must outlast the longest wire value (300 chars), or a forbidden character could
	; survive in the untouched remainder
	while i >= 0 && guard < 300
		if i > 0
			out += StringUtil.Substring(rest, 0, i) ; (never len 0: that would mean "to the end")
		endif
		out += asTo
		if i + 1 >= StringUtil.GetLength(rest)
			rest = ""
			i = -1
		else
			rest = StringUtil.Substring(rest, i + 1)
			i = StringUtil.Find(rest, asFrom)
		endif
		guard += 1
	endwhile
	return out + rest
EndFunction

; ---------------------------------------------------------------------------
; The one update timer. RegisterForSingleUpdate is per FORM, and LRG_OStim sits on the same
; quest: two scripts registering independently would overwrite each other's timer (and both
; would receive every OnUpdate). So only this script registers and owns OnUpdate; everybody
; asks for a tick through RequestTick (the EARLIEST wanted time wins) and OnUpdate fans out to
; LRG_OStim.Tick() and SnapTick(). Both are cheap and decide by their own timestamps whether
; anything is due, then ask for their next tick themselves. Users of the timer (v0.2):
;   LRG_OStim.Tick  : start timeout, navigation timeout, debounced state push (0.8 s), the 5 s
;                     scene duties (busy flag, auto-mode poll, lead tick, cold exemption) and the
;                     wind-down stop time
;   SnapTick        : 20 s snapshot refresh and the NPC initiative tick (default every 90 s)
; A requester that gets woken early simply finds nothing due and asks again.
; ---------------------------------------------------------------------------
Function RequestTick(float afDelay)
	float now = Utility.GetCurrentRealTime()
	if afDelay < 0.2
		afDelay = 0.2
	endif
	if tickAt > now && tickAt <= (now + afDelay)
		return ; an earlier tick is already on its way
	endif
	if tickAt > 0.0 && tickAt <= now && (now - tickAt) < 3.0
		return ; that tick is due this moment (the script engine is behind): do not push it back
	endif
	tickAt = now + afDelay
	RegisterForSingleUpdate(afDelay)
EndFunction

Event OnUpdate()
	tickAt = 0.0
	if initBoot
		;/[0.5.5] The first boot of a NEW instance (a new game, or the first load after the main quest
		 moved to its new form). OnInit only armed this tick, so Maintenance() runs HERE, on an
		 ordinary stack, and arms the boot queue itself./;
		initBoot = false
		Maintenance()
		return
	endif
	;/[0.5.4] THE HEARTBEAT IS FIRST, and the boot queue is second, both before anything that walks
	 into another mod. Heartbeat() asks for its next tick before it touches a native, and BootTick()
	 arms the next boot tick before it runs its step, so nothing below this point can leave the
	 quest without a timer - which is the state playtest 10 and playtest 11 both ended in./;
	Heartbeat()
	if bootStep > 0
		BootTick()
		BootWatch()
		return ; one boot step owns its tick; the fan-out below resumes when the queue is done
	endif
	BootWatch() ; [0.5.5] free unless a module is still being waited for
	; v0.4.1: the conversation hold goes first - it is the cheapest of the three and it is also the
	; self-heal for a hold left in the save. Nothing at all is spent while there is no hold.
	if convActive || convActor != None || convHeld || convPkgOn
		TickConvHold(Utility.GetCurrentRealTime())
	endif
	; v0.5.1: the queued follower repair. One field test while nothing is queued, which is always.
	if folFixCount > 0
		TickFollowerRepair(Utility.GetCurrentRealTime())
	endif
	; v0.5.6: the few seconds after "follow me" ended a scene. One field test otherwise.
	if escortActor != None
		TickEscort(Utility.GetCurrentRealTime())
	endif
	; [pt16] the natural follow: ours re-asserted over CHIM's, stood down for CHIM's own moves, and the
	; watched NPC looked at until she is tracked. One or two field tests while nothing is on.
	if gfCount > 0 || watchActor != None
		TickGlueFollow(Utility.GetCurrentRealTime())
	endif
	LRG_OStim ost = GetOStim()
	if ost
		ost.Tick()
	endif
	SnapTick()
EndEvent

; ---------------------------------------------------------------------------
; Server messaging (fire and forget; the server plugin stores it and replies nothing)
; ---------------------------------------------------------------------------
Function SendNpcMessage(string asType, string asPayload, string asNpcName)
	if !IsEnabled()
		return
	endif
	AIAgentFunctions.logMessageForActor(asPayload, asType, asNpcName)
EndFunction

Function ReportResult(string asNpcName, string asCommand, string asParam, string asResult)
	;/funcret format verified against CHIM 3.3.2: command@Code@param@result.
	 Field 2 must not contain double quotes, so our parameters are k=v lists.
	 The catalog rows have no follow-up any more (speed), so a failure would be invisible:
	 every "Error: <reason>" is also shown to the player as a notification.
	 [0.5.7 / owner addendum 11, NEVER SILENT] every result carries one of two markers:
	   "OK: <neutral sentence>" - it was done; there is nothing for her to say about it.
	   "Error: <her reason>"    - it was NOT done; the reason is in plain words she can repeat
	                              ("we are not doing anything right now").
	 Callers hand in "Error: <technical reason>" (PROTOCOL 1.6's closed list). SayReason() turns it
	 into her words. The technical reason stays in the log line, in the corner note (unchanged from
	 506) and in the additive key err=<technical reason> appended to field 2, so the server can
	 classify without matching her wording. A caller that already has her words uses ReportError().
	 A result with neither marker is a success from an unmarked caller: it gets "OK: " (none is left
	 in 507; the log line says "unmarked" if one ever turns up)./;
	if StringUtil.Find(asResult, "Error: ") == 0
		string tech = ""
		if StringUtil.GetLength(asResult) > 7
			tech = StringUtil.Substring(asResult, 7)
		endif
		SendFuncret(asNpcName, asCommand, asParam, SayReason(tech, asCommand), tech, true, true)
		return
	endif
	string okText = asResult
	if StringUtil.Find(okText, "OK: ") != 0
		okText = "OK: " + asResult
		LogC(ParamGet(asParam, "cid"), "unmarked result of " + asCommand + " sent as a success", asNpcName)
	endif
	SendFuncret(asNpcName, asCommand, asParam, okText, "", true, true)
EndFunction

Function ReportError(string asNpcName, string asCommand, string asParam, string asSay, string asTech)
	{A refusal in words the caller chose (asSay, what she is told). asTech is the technical reason: the log line, the corner note and err= only.}
	string sayText = asSay
	if sayText == ""
		sayText = SayReason(asTech, asCommand)
	endif
	SendFuncret(asNpcName, asCommand, asParam, sayText, asTech, true, true)
EndFunction

Function ReportLateError(string asNpcName, string asCommand, string asParam, string asSay, string asTech)
	;/[0.5.7] A command that was already answered "OK: ..." came undone afterwards - the escort's
	 scene started again and kept her, an after= position turned out impossible. One more funcret for
	 the SAME command and cid, "Error: <her words>", with err= and late=1 appended to field 2. No
	 commandEndedForActor (the command ended with its first answer) and no corner note (the caller
	 keeps its own, as in 506). An old server records it like any failed result and tells her once./;
	SendFuncret(asNpcName, asCommand, asParam + ";late=1", asSay, asTech, false, false)
EndFunction

Function SendFuncret(string asNpcName, string asCommand, string asParam, string asText, string asTech, bool abEnd, bool abNote)
	;/The one place a funcret is written. asTech "" = a success, asText goes out as it is ("OK: ...").
	 Otherwise asText is her reason: "Error: <asText>", and err=<asTech> is appended to field 2./;
	string param = asParam
	string result = asText
	if asTech != ""
		result = "Error: " + asText
		param = asParam + ";err=" + LRG_Profile.Clip(CleanForWire(asTech), 160)
	endif
	AIAgentFunctions.logMessageForActor("command@" + asCommand + "@" + param + "@" + result, "funcret", asNpcName)
	if abEnd
		AIAgentFunctions.commandEndedForActor(asCommand, asNpcName)
	endif
	string cid = ParamGet(asParam, "cid")
	if asTech == ""
		LogC(cid, "result " + asCommand + ": " + result, asNpcName)
		return
	endif
	string late = ""
	if !abEnd
		late = " (late)"
	endif
	LogE(cid, "result " + asCommand + late + ": " + result + " [why: " + asTech + "]", asNpcName) ; [pt15] a refusal is an error line
	if abNote && SettingBool("bNotifyErrors:General", true)
		Debug.Notification("LoreRim Glue: " + asTech)
	endif
EndFunction

string Function SayReason(string asTech, string asCommand)
	;/[0.5.7] Her words for a technical refusal reason (PROTOCOL 1.6's closed list plus the glue's own
	 start-up reasons): short, plain, something she can say in one line. It never returns technical
	 text - a reason with no in-world meaning (the feature is off, OStim is missing, dry-run, a part of
	 the glue did not start) becomes "I cannot do that right now". asCommand only picks the menuless
	 dialogue's wording where the same reason means something else there./;
	string t = asTech
	bool dlg = asCommand == "ExtCmdLRG_SelectTopic"
	; the scene with the player
	if t == "no scene is running"
		return "we are not doing anything right now"
	elseif t == "a scene is already running"
		if dlg
			return "not now, I am busy with something"
		endif
		return "not now, something is already going on"
	elseif t == "a scene is just starting" || t == "the scene is still starting"
		return "give me a moment, we are only just starting"
	elseif t == "still moving into the previous position" || t == "in the middle of a transition"
		return "give me a moment, I am still moving"
	elseif t == "a scene with someone else is running"
		return "you are busy with someone else right now"
	elseif t == "already there"
		return "we are already doing that"
	elseif t == "no position was named"
		return "you did not say what you want"
	elseif t == "that position does not exist for the two of you"
		return "that is not something the two of us can do"
	elseif t == "that is not a position"
		return "that is not something we can do"
	elseif t == "that position cannot be reached from here" || t == "unreachable"
		return "I cannot get into that from here"
	elseif t == "not possible in this position"
		return "that does not work the way we are now"
	elseif t == "no suitable furniture nearby"
		return "there is nothing here we could use for that"
	elseif t == "the scene did not start" || t == "OStim rejected the actors"
		return "something got in the way, it did not happen"
	elseif t == "OStim does not accept these actors"
		return "that cannot happen between us right now"
	elseif t == "the weapons could not be put away"
		return "not while we still have weapons in our hands"
	; where they are and who is around
	elseif t == "combat"
		return "not in the middle of a fight"
	elseif t == "a quest scene is running"
		return "I am in the middle of something I cannot leave"
	elseif t == "too far apart"
		return "you are too far away"
	elseif t == "a child is nearby"
		return "not with a child nearby"
	elseif t == "someone is watching"
		return "not here, someone could see us"
	elseif t == "a companion is present"
		return "not with your companion right here"
	elseif t == "adults only"
		return "no, I will not do that"
	; money - both keep the word gold: the server maps any reason with "gold" onto the paid refusal
	elseif t == "the player does not have that much gold"
		return "you do not have that much gold"
	elseif t == "not enough gold"
		return "you do not have enough gold"
	; talking
	elseif t == "a conversation is in progress"
		if dlg
			return "you are already talking to someone"
		endif
		return "not in the middle of a conversation"
	elseif t == "they cannot talk right now"
		return "I cannot talk right now"
	elseif t == "her voice is not ready"
		return "give me a moment, I am not ready to answer"
	elseif t == "that is not on the table right now"
		return "that is not on the table right now"
	elseif t == "that moment has passed"
		return "that moment has passed"
	; [v1.0 / S7] the menuless driver's own refusals, each in the words the spec gives her
	elseif t == "choose that one on the list yourself"
		return "choose that one on the list yourself"
	elseif t == "the entry moved before the click"
		return "I lost the thread - say that again"
	elseif t == "the click did not take"
		return "that did not take - choose it on the menu"
	elseif t == "the list could not be read"
		return "I did not catch what we could talk about - choose it on the menu yourself"
	elseif t == "the conversation was interrupted"
		return "we were interrupted - ask me again"
	elseif t == "nothing came of that"
		return "nothing came of that"
	elseif t == "the actor could not be found" && dlg
		return "I cannot talk right now"
	; what she does not understand, and what she did not agree to
	elseif t == "unknown scene request" || t == "unknown clothing request" || t == "unknown command"
		return "I do not know what you mean"
	elseif t == "not authorised by the server gate"
		if dlg
			return "I cannot do that right now"
		endif
		return "I have not agreed to that"
	elseif t == "the glue is still starting after the load - say it again in a moment"
		return "give me a moment, then ask me again"
	;/[pt17 / owner addendum 11] the two dry runs are named, not hidden: seven refusals on 2026-09-23
	 were answered "I cannot do that right now" and the owner never learned why. The developer
	 switch is out of character on purpose - it is the owner's own switch and he has to find it./;
	elseif StringUtil.Find(t, "still learning the dialogue menu") >= 0
		return "I cannot take that up by voice yet - I am still learning how our talk works here, so choose it on the menu yourself"
	elseif StringUtil.Find(t, "menuless dry run") >= 0
		return "I cannot take that up by voice - the menuless-questing dry run is on, so choose it on the menu yourself"
	elseif StringUtil.Find(t, "dry-run") >= 0 || StringUtil.Find(t, "dry run") >= 0
		return "I cannot - the LoreRim Glue dry-run switch is on in its settings, on the Diagnostics page"
	endif
	return "I cannot do that right now"
EndFunction

; ---------------------------------------------------------------------------
; CHIM command intake (two delivery paths, one handler)
; ---------------------------------------------------------------------------
Event OnChimCommand(string asNpcName, string asCommand, string asParameter)
	HandleCommand(asNpcName, asCommand, asParameter, "modevent")
EndEvent

bool Function HandleCommand(string asNpcName, string asCommand, string asParameter, string asVia)
	; v0.4.1: every command that arrives is first shown to the conversation hold (free while no hold
	; is live), THEN the "not ours" test below runs unchanged.
	NoteChimAction(asNpcName, asCommand)
	if StringUtil.Find(asCommand, "ExtCmdLRG_") != 0
		return false ; not ours
	endif
	float now = Utility.GetCurrentRealTime()
	string cmdKey = asNpcName + "|" + asCommand + "|" + asParameter
	if cmdKeys.Length != 4
		cmdKeys = new string[4] ; a save from 300 has none
		cmdTimes = new float[4]
		cmdSlot = 0
	endif
	int i = 0
	while i < 4
		if cmdKeys[i] == cmdKey && (now - cmdTimes[i]) < 5.0 && now >= cmdTimes[i]
			return true ; same command delivered through the second path
		endif
		i += 1
	endwhile
	cmdKeys[cmdSlot] = cmdKey
	cmdTimes[cmdSlot] = now
	cmdSlot += 1
	if cmdSlot >= 4
		cmdSlot = 0
	endif
	LogC(ParamGet(asParameter, "cid"), "command " + asCommand + " from " + asNpcName + " via " + asVia + " param=" + asParameter, asNpcName)

	if !IsEnabled()
		ReportResult(asNpcName, asCommand, asParameter, "Error: the feature is switched off")
		return true
	endif

	;/v0.3.1 fix pass, RECEIVE SIDE ONLY: note=<text> on any ExtCmdLRG_ command puts that text in the
	 corner. It shipped dormant in 0.4 and v0.5 wakes it up - E2(d)'s quest hint rides this key and
	 the server half really is the one line the old comment predicted.
	 bQuestHint:Quests is read HERE, not on the server, so a server that somehow sends a hint can
	 never spam a player who turned the note off. Any other note (a refusal reason, a service note)
	 is not a quest hint and is shown whatever that toggle says - kind=hint marks the quest one./;
	string cornerNote = ParamGet(asParameter, "note")
	if cornerNote != ""
		bool noteOk = true
		string noteKind = ParamGet(asParameter, "kind")
		if noteKind == "hint"
			noteOk = SettingBool("bQuestHint:Quests", true)
		elseif noteKind == "rail"
			;/[v1.0 / S3.3] the stage rail's note ("nothing picked yet - ask her something simple first"):
			 not a quest hint, so bQuestHint does not silence it, and ONCE per conversation (its sid),
			 whatever the server sends./;
			string railSid = ParamGet(asParameter, "sid")
			noteOk = railSid == "" || railSid != railNoteSid
			railNoteSid = railSid
		endif
		if noteOk
			Debug.Notification("LoreRim Glue: " + LRG_Profile.Clip(cornerNote, 120))
		endif
	endif

	; Every verb of the verbal set lives in LRG_OStim (the only script that touches OStim):
	;   ExtCmdLRG_StartIntimacy -> CmdStart   (start, optionally on furniture)
	;   ExtCmdLRG_SceneControl  -> CmdControl (goto, faster, slower, speed, stop, hold, release,
	;                                          climax, pullout, winddown, furniture, lead)
	;   ExtCmdLRG_Clothing      -> CmdClothing (undress / dress, who, part)
	; ExtCmdLRG_Invite is server-only and never reaches the game; if it ever does, the last
	; branch answers it.
	LRG_OStim ost = GetOStim()
	if asCommand == "ExtCmdLRG_StartIntimacy"
		if ost
			ost.CmdStart(asNpcName, asCommand, asParameter)
		else
			ReportResult(asNpcName, asCommand, asParameter, ModDownWhy(0, "Error: OStim is not installed"))
		endif
	elseif asCommand == "ExtCmdLRG_SceneControl"
		if ost
			ost.CmdControl(asNpcName, asCommand, asParameter)
		else
			ReportResult(asNpcName, asCommand, asParameter, ModDownWhy(0, "Error: OStim is not installed"))
		endif
	elseif asCommand == "ExtCmdLRG_Clothing"
		if ost
			ost.CmdClothing(asNpcName, asCommand, asParameter)
		else
			ReportResult(asNpcName, asCommand, asParameter, ModDownWhy(0, "Error: not available"))
		endif
	elseif asCommand == "ExtCmdLRG_SelectTopic"
		; v0.4 menuless questing (also carries do=award). No LRG_OStim guard: this command has
		; nothing to do with OStim. CmdSelectTopic validates, stamps and returns in one frame.
		LRG_Dialogue dlg = GetDialogue()
		if dlg
			dlg.CmdSelectTopic(asNpcName, asCommand, asParameter)
		else
			ReportResult(asNpcName, asCommand, asParameter, ModDownWhy(1, "Error: not available"))
		endif
	elseif asCommand == "ExtCmdLRG_Escort"
		; [0.5.6] "follow me" / "wait here" / "you can go" for an NPC her own scene holds. Lives here, not
		; in a module: it needs nothing but CHIM's forms, and it must work while a module is booting.
		CmdEscort(asNpcName, asCommand, asParameter)
	elseif asCommand == "ExtCmdLRG_QuestEntry"
		; [pt18 / script 510] the stage of a scripted join line the player chose by voice, when the driver
		; could not click. Like the escort it lives here: quests and actors only, no module needed.
		CmdQuestEntry(asNpcName, asCommand, asParameter)
	elseif asCommand == "ExtCmdLRG_Buy"
		; [pt19-purchase / script 512] food or drink the player ordered by voice from a vendor's real stock, at the
		; price the game asks (PROTOCOL 10.27). Like the escort and the quest entry it lives here: forms and actors only.
		CmdBuy(asNpcName, asCommand, asParameter)
	else
		ReportResult(asNpcName, asCommand, asParameter, "Error: unknown command")
	endif
	return true
EndFunction

; k=v;k=v parameter helper
string Function ParamGet(string asParams, string asKey)
	string needle = asKey + "="
	int start = StringUtil.Find(";" + asParams, ";" + needle)
	if start < 0
		return ""
	endif
	start += StringUtil.GetLength(needle) ; (the leading ";" we added shifts by one, the needle by its length)
	int stop = StringUtil.Find(asParams, ";", start)
	if stop == start || start >= StringUtil.GetLength(asParams)
		return "" ; empty value. (Substring with len 0 means "to the end of the string"!)
	endif
	if stop < 0
		return StringUtil.Substring(asParams, start)
	endif
	return StringUtil.Substring(asParams, start, stop - start)
EndFunction

; ---------------------------------------------------------------------------
; [0.5.6] ExtCmdLRG_Escort - "follow me" for an NPC her own scene holds (playtest 13,
; research/pt13-game-fix.md section 3)
; ---------------------------------------------------------------------------
;/The owner asked Lisette - a recruitable bard, back on her stage - to follow him. CHIM chose
 FollowPlayer three times and applied its priority-100 follow package three times, and she did not
 move: an actor in a running scene is driven by the scene, which outranks every package override.
 CHIM's own way out (IntCmd InterruptScene, AIAgentPapyrusFunctions.psc:1311) stops the songs AND
 leaves a priority-99 do-nothing package on her that nothing ever removes; CHIM's approach and
 walk-to refuse a scene NPC outright (AIAgentAIMind.psc:1560 / :1757).
 This command ends the scene the way the game's own "stop playing" line does - BardSongsScript.
 StopAllSongs() for the bard quests (USSEP's script, the one this list runs; it sets StopSong so the
 song's end fragment does not start the next song), Scene.Stop() for an idle / sandbox /
 world-interaction scene - and then puts on CHIM's OWN follow package if CHIM's is not already on
 (LRG_Followers.ChimFollowPlayer: CHIM's forms, CHIM's recipe, so CHIM's next ResetPackages cleans
 it up exactly like its own). [pt16] ONE package of the glue's own on top of it, and only on top of
 it: the natural-follow overlay (the block after TickEscort) - CHIM's follow with the game's own
 follower distances; CHIM's flag and package stay CHIM's.
 Only a quest on the safe list, never one on the deny list, never one with an open journal entry.
 Wire (server -> game): <npc>|command|ExtCmdLRG_Escort@ok=1;cid=<id>;npc=<name>;do=follow|wait|release
   [;safe=<pattern>,<pattern>,...]  - patterns as ESCORT_SAFE_DEFAULT; absent = that default.
 Result (funcret): a plain sentence when done, "Error: <why>" when it cannot be honoured. Every
 Error: result is also the corner note (ReportResult, bNotifyErrors) - "she is in the middle of
 something she cannot leave".
 StorageUtil keys on the NPC, all the glue's own: LRG_EscortApplied (the glue put CHIM's follow on),
 LRG_EscortFaction (...and added CHIM's follow faction), LRG_EscortWait / LRG_EscortWaitPrev (the
 glue wrote WaitingForPlayer, and what it was before). wait and release take the follow off whoever put
 it on (EscortStopFollowing - never on a teammate / SFF follower) and release gives WaitingForPlayer back.
 [pt17 / script 509] THE FRAMEWORK FIRST (owner: "lets change it to whatever follower mod is managing
 it and work with it that way"; research/pt17-followers.md; MCM bSffFollow:Followers): do=follow makes
 her the player's REAL companion through Simple Follower Framework when it can take her (SffRefuseReason
 / SffRecruit in LRG_Followers) - SFF's own follow package, wait and dismissal then apply and every
 follower mod sees her; a companion of SFF's follows again / waits through SFF's own calls (SffWait);
 do=release on her is a WAIT, never a dismissal (parting ways is commit-class: her own dialogue). When
 SFF cannot take her the old path runs (CHIM's follow + the pt16 overlay) and the OK funcret carries
 the additive key fb=<reason>, which the server voices. Any follow-type move of CHIM's found on a
 teammate is taken off her (LRG_Followers.ChimFollowVeto): her framework owns follow./;
Function CmdEscort(string asNpcName, string asCommand, string asParam)
	string cid = ParamGet(asParam, "cid")
	string what = ParamGet(asParam, "do")
	string want = ParamGet(asParam, "npc")
	if want == ""
		want = asNpcName
	endif
	Actor npc = AIAgentFunctions.getAgentByName(want)
	if npc == None && want != asNpcName
		npc = AIAgentFunctions.getAgentByName(asNpcName)
	endif
	if npc == None
		LogC(cid, "escort " + want + " do=" + what + ": refused - no such agent nearby", asNpcName)
		ReportError(asNpcName, asCommand, asParam, want + " is not here", want + " is not here")
		return
	endif
	string nm = npc.GetDisplayName()
	if what != "follow" && what != "wait" && what != "release"
		LogC(cid, "escort " + nm + ": refused - unknown do=" + what, nm)
		ReportError(asNpcName, asCommand, asParam, nm + " does not know what you want her to do", "unknown escort request (" + what + ")")
		return
	endif
	if what == "release"
		; [pt17] a release still runs under the developer dry run: it only takes the glue's own leftovers off
		if IsDryRun()
			LogC(cid, "escort " + nm + " do=release under the developer dry run: only the glue's own leftovers come off", nm)
		endif
		; release only ever takes back the glue's OWN edits, so it needs none of the guards below
		; ([pt17] on a companion of SFF's it is a wait, with its own two guards inside EscortRelease)
		EscortRelease(npc, nm, cid, asNpcName, asCommand, asParam)
		return
	endif
	if IsDryRun()
		;/[pt17] asSay "" so SayReason names the switch (an explicit asSay used to bypass it), and the
		 technical text names the page, which the corner note shows./;
		LogC(cid, "escort " + nm + " do=" + what + ": nothing changed (dry run)", nm)
		ReportError(asNpcName, asCommand, asParam, "", "dry-run mode is ON in the LoreRim Glue MCM (Diagnostics page) - nothing was changed")
		return
	endif
	string why = EscortRefuseReason(npc)
	if why != ""
		LogC(cid, "escort " + nm + " do=" + what + ": refused - " + why, nm)
		ReportError(asNpcName, asCommand, asParam, EscortSayRefusal(nm, why), nm + " will not do that now (" + why + ")")
		return
	endif
	if what == "follow"
		EscortFollow(npc, nm, cid, asNpcName, asCommand, asParam)
	else
		EscortWait(npc, nm, cid, asNpcName, asCommand, asParam)
	endif
EndFunction

; ---------------------------------------------------------------------------
; [pt18 / script 510] ExtCmdLRG_QuestEntry - the stage of a scripted join line, chosen by voice
; (research/pt18-quest.md; PROTOCOL 10.26)
; ---------------------------------------------------------------------------
;/Playtest 2026-09-23 23:19: the owner asked General Tullius by voice to join the Legion three times; the
 menuless driver could not click (calibration 0 of 10, an ambient map-table scene the game never opens on),
 the model emitted no action, and nothing reached the game - CW00A stayed at stage 0 while Tullius "swore
 him in" with words. The server now matches such an ask, from the PLAYER's own words and before the LLM,
 to ONE scripted join line whose whole fragment is GetOwningQuest().SetStage(n) (decoded, not inferred:
 alternateperspective.esp:206783 = CW00A stage 10), re-checks that line's engine conditions on the facts
 line, and sends this command on ONE route (D1, the escort pattern). This function re-checks every
 condition on the LIVE forms and applies the stage with Quest.SetStage - the very call the line's own
 fragment makes - or refuses with the exact reason, in her words and, as err=, in technical words.
 Idempotent: a stage already done is a plain OK; a quest already past it is never lowered.
 Wire (server -> game): <npc>|command|ExtCmdLRG_QuestEntry@ok=1;cid=<id>;npc=<name>;quest=<EditorID>;
   stage=<n>;isid=<decimal base form id, low 24 bits>;notdone=<n,n>;max=<n>;qdone=<EditorID,..>;
   qnd=<EditorID:stage,..>;entry=<topic EditorID>;hint=<corner note>;x=<id>;z=1 - every cond key is
   optional and additive; an unknown key is ignored. qdone = quests that must be COMPLETE (MQ101: AP's
   Helgen); notdone = stages of the quest that must not be done; max = the highest current stage allowed;
   qnd = quest:stage pairs that must not be done (CW00B:10 stands in for CW.PlayerGotIntro == 0 this
   round - the CWScript stub is not built). Result: "OK: <quest> now at stage <n> (<before> before): <hint>"
   with ;qs=<stage after>;qj=<0|1 journal objective shown> appended to field 2, or "Error: <her words>"
   with err=<technical reason>. The developer dry run refuses (named); the menuless dry run does not
   apply - this is not a click. The corner note (hint=) shows only AFTER a success, gated on bQuestHint./;
Function CmdQuestEntry(string asNpcName, string asCommand, string asParam)
	string cid = ParamGet(asParam, "cid")
	string qid = ParamGet(asParam, "quest")
	int stage = ParamGet(asParam, "stage") as int
	string want = ParamGet(asParam, "npc")
	if want == ""
		want = asNpcName
	endif
	if qid == "" || stage <= 0
		LogC(cid, "questentry refused - no quest / stage in " + asParam, asNpcName)
		ReportError(asNpcName, asCommand, asParam, "I do not know what you mean", "unknown quest entry request")
		return
	endif
	if IsDryRun()
		;/[pt17 pattern] asSay "" so SayReason names the switch, and the technical text names the page,
		 which the corner note shows./;
		LogC(cid, "questentry " + qid + " stage " + stage + ": nothing changed (dry run)", asNpcName)
		ReportError(asNpcName, asCommand, asParam, "", "dry-run mode is ON in the LoreRim Glue MCM (Diagnostics page) - the quest was not touched")
		return
	endif
	if QuietOn()
		; [pt19] quiet mode: never a SetStage while the game runs a scripted intro - refused with the real reason, in her words
		LogC(cid, "questentry " + qid + " stage " + stage + ": refused - quiet mode (" + quietWhy + ")", asNpcName)
		ReportError(asNpcName, asCommand, asParam, "not now - " + QuietWords() + " is under way and I will not take anything up until it is over", "quiet mode: " + quietWhy + " is running - the glue touches no quest while it does")
		return
	endif
	Actor npc = AIAgentFunctions.getAgentByName(want)
	if npc == None && want != asNpcName
		npc = AIAgentFunctions.getAgentByName(asNpcName)
	endif
	if npc == None
		LogC(cid, "questentry " + qid + " stage " + stage + ": refused - no such agent nearby (" + want + ")", asNpcName)
		ReportError(asNpcName, asCommand, asParam, want + " is not here", want + " is not here")
		return
	endif
	string nm = npc.GetDisplayName()
	if npc.IsDead() || npc.IsDisabled() || !npc.Is3DLoaded()
		LogC(cid, "questentry " + qid + " stage " + stage + ": refused - " + nm + " is not loaded", nm)
		ReportError(asNpcName, asCommand, asParam, nm + " is not here", nm + " is not here")
		return
	endif
	; the line's own GetIsID: the speaker must be THAT actor base (Tullius 0x01327E), never a namesake
	int isid = ParamGet(asParam, "isid") as int
	if isid > 0 && !QeIsActorBase(npc, isid)
		LogC(cid, "questentry " + qid + " stage " + stage + ": refused - " + nm + " is not actor base " + isid, nm)
		ReportError(asNpcName, asCommand, asParam, "I am not the one who can take that up", "that is not really " + nm + " (the " + qid + " line is for a different actor)")
		return
	endif
	Quest q = QeQuest(qid)
	if q == None
		LogC(cid, "questentry " + qid + " stage " + stage + ": refused - quest not found", nm)
		ReportError(asNpcName, asCommand, asParam, "I cannot do that right now", "the quest " + qid + " is not in this game")
		return
	endif
	if !q.IsRunning()
		LogC(cid, "questentry " + qid + " stage " + stage + ": refused - not running", nm)
		ReportError(asNpcName, asCommand, asParam, "nothing of that is under way for you", qid + " is not running")
		return
	endif
	; quests that must be COMPLETE first (the greet's GetQuestCompleted: MQ101 - Helgen - on this load order)
	string miss = QeFirstNotComplete(ParamGet(asParam, "qdone"))
	if miss != ""
		LogC(cid, "questentry " + qid + " stage " + stage + ": refused - " + miss + " is not complete", nm)
		ReportError(asNpcName, asCommand, asParam, "that road is not open to you yet - there is something you must see through first", qid + " waits on " + miss + " being complete")
		return
	endif
	int cur = q.GetCurrentStageID()
	; idempotent: the stage is already recorded - a plain OK, nothing to do, nothing to say
	if q.GetStageDone(stage)
		LogC(cid, "questentry " + qid + " stage " + stage + ": already done (current " + cur + ") - OK, nothing to do", nm)
		ReportResult(asNpcName, asCommand, asParam + ";qs=" + cur + ";qj=" + QeJournal(q), "OK: " + qid + " already at stage " + stage + " - nothing to do")
		return
	endif
	; never lower a quest: the ceiling, and the stages the line's own conditions say must not be done
	int maxStage = ParamGet(asParam, "max") as int
	if maxStage > 0 && cur > maxStage
		LogC(cid, "questentry " + qid + " stage " + stage + ": refused - already past it (current " + cur + " > max " + maxStage + ")", nm)
		ReportError(asNpcName, asCommand, asParam, "that step is already behind you", qid + " is already past that (stage " + cur + ")")
		return
	endif
	int nd = QeFirstStageDone(q, ParamGet(asParam, "notdone"))
	if nd >= 0
		LogC(cid, "questentry " + qid + " stage " + stage + ": refused - stage " + nd + " is done", nm)
		ReportError(asNpcName, asCommand, asParam, "that step is already behind you", qid + " is already past that (stage " + nd + " is done)")
		return
	endif
	; quest:stage pairs that must NOT be done (CW00B:10 = the other side's introduction, CW.PlayerGotIntro 2)
	string bad = QeFirstPairDone(ParamGet(asParam, "qnd"))
	if bad != ""
		LogC(cid, "questentry " + qid + " stage " + stage + ": refused - " + bad + " is done", nm)
		ReportError(asNpcName, asCommand, asParam, "that line of mine is closed to you now", "he has already had the other side's introduction (" + bad + " is done)")
		return
	endif
	; apply: the very call the line's fragment makes, then read the outcome back
	int before = cur
	bool okSet = q.SetStage(stage)
	int after = q.GetCurrentStageID()
	if !okSet || !q.GetStageDone(stage)
		LogE(cid, "questentry " + qid + " stage " + stage + ": SetStage refused (" + before + " before, " + after + " after)", nm)
		ReportError(asNpcName, asCommand, asParam, "I cannot do that right now", "the game refused stage " + stage + " of " + qid + " (stage " + before + " before, " + after + " after)")
		return
	endif
	string hint = ParamGet(asParam, "hint")
	if hint != "" && SettingBool("bQuestHint:Quests", true)
		Debug.Notification("LoreRim Glue: " + LRG_Profile.Clip(hint, 120))
	endif
	int qj = QeJournal(q)
	LogC(cid, "questentry " + qid + " stage " + stage + " applied for " + nm + " (" + before + " before, " + after + " after, journal " + qj + ", entry " + ParamGet(asParam, "entry") + ")", nm)
	string okText = "OK: " + qid + " now at stage " + stage + " (" + before + " before)"
	if hint != ""
		okText += ": " + hint
	endif
	ReportResult(asNpcName, asCommand, asParam + ";qs=" + after + ";qj=" + qj, okText)
EndFunction

Quest Function QeQuest(string asId)
	{The quest by EditorID (SKSE), with the one FormID fallback the glue's own table needs (CW00A 0x000D3C5F).}
	Quest q = Quest.GetQuest(asId)
	if q == None && asId == "CW00A"
		q = Game.GetFormFromFile(0x000D3C5F, "Skyrim.esm") as Quest
	endif
	return q
EndFunction

int Function QeJournal(Quest akQuest)
	{1 when an objective of the quest is on the player's screen and not done (the journal truth, read back).}
	if EscortHasJournal(akQuest)
		return 1
	endif
	return 0
EndFunction

bool Function QeIsActorBase(Actor akNpc, int aiLow24)
	{Is this actor's base (or leveled base) form the given load-order-independent id (low 24 bits)?}
	ActorBase b = akNpc.GetActorBase()
	if b != None && Math.LogicalAnd(b.GetFormID(), 0x00FFFFFF) == aiLow24
		return true
	endif
	b = akNpc.GetLeveledActorBase()
	return b != None && Math.LogicalAnd(b.GetFormID(), 0x00FFFFFF) == aiLow24
EndFunction

string Function QeCsvItem(string asCsv, int aiIndex)
	{The aiIndex-th comma-separated item of asCsv (no spaces: the server writes none); "" past the end. At most 16 looked at.}
	int len = StringUtil.GetLength(asCsv)
	int start = 0
	int n = 0
	while start <= len && n < 16
		int stop = StringUtil.Find(asCsv, ",", start)
		if stop < 0
			stop = len
		endif
		if n == aiIndex
			if stop <= start
				return ""
			endif
			return StringUtil.Substring(asCsv, start, stop - start)
		endif
		start = stop + 1
		n += 1
	endwhile
	return ""
EndFunction

string Function QeFirstNotComplete(string asCsv)
	{The first quest EditorID of the csv that is not complete ("" = every one is, or none named). Unknown = not complete.}
	int i = 0
	while i < 8
		string id = QeCsvItem(asCsv, i)
		if id == ""
			return ""
		endif
		Quest q = Quest.GetQuest(id)
		if q == None || !q.IsCompleted()
			return id
		endif
		i += 1
	endwhile
	return ""
EndFunction

int Function QeFirstStageDone(Quest akQuest, string asCsv)
	{The first stage of the csv that akQuest has done, or -1.}
	int i = 0
	while i < 8
		string s = QeCsvItem(asCsv, i)
		if s == ""
			return -1
		endif
		int st = s as int
		if st > 0 && akQuest.GetStageDone(st)
			return st
		endif
		i += 1
	endwhile
	return -1
EndFunction

string Function QeFirstPairDone(string asCsv)
	{The first <EditorID>:<stage> pair of the csv whose quest exists and has that stage done, or "". A quest that is not in the game is not done.}
	int i = 0
	while i < 8
		string pair = QeCsvItem(asCsv, i)
		if pair == ""
			return ""
		endif
		int colon = StringUtil.Find(pair, ":")
		if colon > 0 && colon < StringUtil.GetLength(pair) - 1
			Quest q = Quest.GetQuest(StringUtil.Substring(pair, 0, colon))
			int st = StringUtil.Substring(pair, colon + 1) as int
			if q != None && st > 0 && q.GetStageDone(st)
				return pair
			endif
		endif
		i += 1
	endwhile
	return ""
EndFunction

; ---------------------------------------------------------------------------
; [pt19-purchase / script 512] ExtCmdLRG_Buy - food or drink ordered by voice (PROTOCOL 10.27)
; ---------------------------------------------------------------------------
Function CmdBuy(string asNpcName, string asCommand, string asParam)
	{The server's carrier for an order of food or drink (lib/lrg_market.php lrgMktNet). Every condition is re-checked
	 here, the price recomputed from the game's own inputs, her line waited for, and only then the item and the septims
	 move. Every refusal is voiced with the real reason (never silent); a moved price is re-quoted, never charged.}
	string cid = ParamGet(asParam, "cid")
	string want = ParamGet(asParam, "npc")
	if want == ""
		want = asNpcName
	endif
	int itemId = HexToInt(ParamGet(asParam, "item"))
	int n = ParamGet(asParam, "n") as int
	int quoted = ParamGet(asParam, "price") as int
	string label = ParamGet(asParam, "name")
	if itemId == 0 || n <= 0
		LogC(cid, "buy refused - no item / count in " + asParam, asNpcName)
		ReportError(asNpcName, asCommand, asParam, "I do not know what you mean", "unknown buy request")
		return
	endif
	if n > 5
		n = 5
	endif
	if IsDryRun()
		LogC(cid, "buy " + label + " x" + n + ": nothing changed (dry run)", asNpcName)
		ReportError(asNpcName, asCommand, asParam, "", "dry-run mode is ON in the LoreRim Glue MCM (Diagnostics page) - nothing was sold")
		return
	endif
	if !SettingBool("bServiceDialogue:Services", true)
		ReportResult(asNpcName, asCommand, asParam, "Error: the feature is switched off")
		return
	endif
	if QuietOn()
		LogC(cid, "buy " + label + ": refused - quiet mode (" + quietWhy + ")", asNpcName)
		ReportError(asNpcName, asCommand, asParam, "not now - " + QuietWords() + " is under way", "quiet mode: " + quietWhy + " is running - the glue sells nothing while it does")
		return
	endif
	Actor npc = AIAgentFunctions.getAgentByName(want)
	if npc == None && want != asNpcName
		npc = AIAgentFunctions.getAgentByName(asNpcName)
	endif
	if npc == None
		LogC(cid, "buy " + label + ": refused - no such agent nearby (" + want + ")", asNpcName)
		ReportError(asNpcName, asCommand, asParam, want + " is not here", want + " is not here")
		return
	endif
	string nm = npc.GetDisplayName()
	Actor player = Game.GetPlayer()
	if npc.IsDead() || npc.IsDisabled() || !npc.Is3DLoaded()
		LogC(cid, "buy " + label + ": refused - " + nm + " is not loaded", nm)
		ReportError(asNpcName, asCommand, asParam, nm + " is not here", nm + " is not here")
		return
	endif
	string no = BuyRefuseReason(npc, player)
	if no != ""
		LogC(cid, "buy " + label + ": refused - " + no, nm)
		ReportError(asNpcName, asCommand, asParam, EscortSayRefusal(nm, no), no)
		return
	endif
	Faction vf = PO3_SKSEFunctions.GetVendorFaction(npc)
	if vf == None || !vf.IsVendor()
		LogC(cid, "buy " + label + ": refused - no vendor faction", nm)
		ReportError(asNpcName, asCommand, asParam, "I do not sell anything", "no vendor faction")
		return
	endif
	; [review] no IsNotSellBuy() refusal: the flag only makes the vendor list an EXCLUSION list (every general-goods vendor
	; and Riften's food stall carry it and sell food); the chest / her inventory below decide what she really has.
	int h1 = vf.GetVendorStartHour()
	int h2 = vf.GetVendorEndHour()
	if h1 != h2 && !BuyHoursOpen(h1, h2)
		LogC(cid, "buy " + label + ": refused - closed (vendor hours " + h1 + "-" + h2 + ")", nm)
		ReportError(asNpcName, asCommand, asParam, "we are closed just now, come back later", "closed (vendor hours " + h1 + "-" + h2 + ")")
		return
	endif
	; [review] GetFormEx, not GetForm: the snapshot sends the RUNTIME id, and an ESL-flagged plugin's is >= 0x80000000
	; (SKSE Game.psc: "GetFormEx ... also works for formIds >= 0x80000000"; LRG_Dialogue does the same for voice types)
	Form item = Game.GetFormEx(itemId)
	Potion pot = item as Potion
	if item == None || pot == None || !pot.IsFood()
		LogC(cid, "buy " + label + ": refused - not a food/drink form (" + ParamGet(asParam, "item") + ")", nm)
		ReportError(asNpcName, asCommand, asParam, "I do not know what you mean", "unknown item form " + ParamGet(asParam, "item"))
		return
	endif
	string iname = item.GetName()
	if iname == ""
		iname = label
	endif
	; the source: the merchant chest with enough of it, else what she carries herself
	ObjectReference chest = vf.GetMerchantContainer()
	ObjectReference src = None
	int have = 0
	if chest != None
		have = chest.GetItemCount(item)
		if have >= n
			src = chest
		endif
	endif
	if src == None
		int own = npc.GetItemCount(item)
		if own >= n
			src = npc as ObjectReference
			have = own
		elseif own > have
			have = own
		endif
	endif
	if src == None
		if have > 0
			LogC(cid, "buy " + iname + " x" + n + ": refused - only " + have + " left", nm)
			ReportError(asNpcName, asCommand, asParam, "I only have " + have + " of those left", "she is out of " + iname + " (has " + have + ", wants " + n + ")")
		else
			LogC(cid, "buy " + iname + " x" + n + ": refused - none in the chest or on her", nm)
			ReportError(asNpcName, asCommand, asParam, "I am out of " + iname, "she is out of " + iname)
		endif
		return
	endif
	; the price, recomputed NOW from the game's own inputs: a moved price is re-quoted, never charged (never false)
	int unit = LRG_Profile.BuyPrice(item, npc, player, true)
	if quoted > 0 && unit != quoted
		LogC(cid, "buy " + iname + ": refused - the price moved (" + unit + " now, " + quoted + " quoted)", nm)
		; [review] drop the 60 s stock cache too: the next snapshot re-prices her stock, so "say the word" re-orders at the
		; live number instead of the stale quote (the server keeps the re-quote open as its own one-item offer)
		StorageUtil.UnsetFloatValue(npc, "LRG.mkt.at")
		ReportError(asNpcName, asCommand, asParam + ";unit=" + unit, "it is " + unit + " septims now, not " + quoted + " - say the word and it is yours", "the price is " + unit + " septims, not " + quoted)
		return
	endif
	int total = unit * n
	int purse = player.GetGoldAmount()
	if purse < total
		LogC(cid, "buy " + iname + " x" + n + ": refused - not enough gold (has " + purse + ", needs " + total + ")", nm)
		ReportError(asNpcName, asCommand, asParam + ";unit=" + unit, "you cannot pay " + total + " septims with the " + purse + " you carry", "not enough gold (has " + purse + ", needs " + total + ")")
		return
	endif
	; say-first: her line comes before the coin (CHIM dispatches the command with the reply; her TTS starts 1-3 s later)
	float waited = BuyWaitForLine(nm)
	; the transfer: the vanilla "<name> added" message is the receipt; the septims go where the stock came from
	Form gold = Game.GetForm(0x0000000F)
	src.RemoveItem(item, n, false, player)
	if gold != None
		player.RemoveItem(gold, total, true, src)
	endif
	Debug.SendAnimationEvent(npc, "IdleGive")
	StorageUtil.UnsetFloatValue(npc, "LRG.mkt.at") ; the next snapshot rescans her stock
	int left = player.GetGoldAmount()
	int stock = src.GetItemCount(item)
	string from = "npc"
	if src == chest
		from = "chest"
	endif
	LogC(cid, "buy " + iname + " x" + n + " for " + total + " (" + unit + " each) from " + from + " - purse " + purse + " -> " + left + ", stock left " + stock + ", waited " + ConvSecs(waited) + "s", nm)
	ReportResult(asNpcName, asCommand, asParam + ";unit=" + unit + ";paid=" + total + ";left=" + left + ";stock=" + stock, "OK: " + n + " " + iname + " handed over for " + total + " septims")
EndFunction

string Function BuyRefuseReason(Actor akNpc, Actor akPlayer)
	{"" = she may serve. Hostile, fighting, arresting, or in an intimate scene with the player. An AMBIENT quest
	 scene is no reason at all: Hulda serves inside the Bannered Mare's own scene, and the transfer needs no package.}
	if akNpc == akPlayer
		return "that is you"
	endif
	if akNpc.IsHostileToActor(akPlayer) || akNpc.GetCombatState() != 0
		return "hostile"
	endif
	if akNpc.IsInCombat() || akPlayer.IsInCombat()
		return "combat"
	endif
	if ConvIsArrest(akNpc, akPlayer)
		return "an arrest"
	endif
	LRG_OStim ost = GetOStim()
	if ost && ost.IsActorInOurScene(akNpc)
		return "a scene with you"
	endif
	return ""
EndFunction

bool Function BuyHoursOpen(int aiStart, int aiEnd)
	{The vendor's hours against the game clock (GLOB GameHour, Skyrim.esm 0x38); a wrapped range (20-8) crosses midnight.}
	GlobalVariable gh = Game.GetFormFromFile(0x00000038, "Skyrim.esm") as GlobalVariable
	if gh == None
		return true
	endif
	float h = gh.GetValue()
	if aiStart < aiEnd
		return h >= aiStart && h < aiEnd
	endif
	return h >= aiStart || h < aiEnd
EndFunction

float Function BuyWaitForLine(string asName)
	{Wait for her spoken line: until it has started (fSayFirstWait) and stopped (fSayFirstMaxWait, SceneTalk page),
	 polled every 0.25 s. Returns the seconds waited. A line that never comes simply ends the wait - never the sale.}
	float t0 = Utility.GetCurrentRealTime()
	float startBudget = SettingFloat("fSayFirstWait:SceneTalk", 6.0)
	float endBudget = SettingFloat("fSayFirstMaxWait:SceneTalk", 20.0)
	if startBudget <= 0.0
		startBudget = 4.0
	elseif startBudget > 10.0
		startBudget = 10.0
	endif
	if endBudget <= 0.0
		endBudget = 12.0
	elseif endBudget > 30.0
		endBudget = 30.0
	endif
	bool started = false
	float now = t0
	while !started && (now - t0) < startBudget
		if AIAgentFunctions.isActorTalking(asName) != 0
			started = true
		else
			Utility.Wait(0.25)
			now = Utility.GetCurrentRealTime()
		endif
	endwhile
	if !started
		return now - t0
	endif
	while AIAgentFunctions.isActorTalking(asName) != 0 && (now - t0) < endBudget
		Utility.Wait(0.25)
		now = Utility.GetCurrentRealTime()
	endwhile
	return now - t0
EndFunction

int Function HexToInt(string asHex)
	{"000508CA" -> 329930 (the runtime FormID the snapshot sent, LRG_Profile.Hex8G); 0 when not hex. Papyrus has no parser for it.}
	int n = StringUtil.GetLength(asHex)
	if n == 0 || n > 8
		return 0
	endif
	int out = 0
	int i = 0
	while i < n
		string c = StringUtil.Substring(asHex, i, 1)
		int d = StringUtil.Find("0123456789ABCDEF", c)
		if d < 0
			d = StringUtil.Find("0123456789abcdef", c)
		endif
		if d < 0
			return 0
		endif
		out = out * 16 + d
		i += 1
	endwhile
	return out
EndFunction

string Function EscortRefuseReason(Actor akNpc)
	{"" = she may be asked to follow / wait. Never a hostile, fighting, arresting or recruited NPC.}
	if QuietOn()
		return "a scripted intro" ; [pt19] quiet mode: the game's own intro runs - she is neither moved nor parked by us
	endif
	Actor player = Game.GetPlayer()
	if akNpc == player
		return "that is you"
	endif
	if akNpc.IsDead() || akNpc.IsDisabled() || !akNpc.Is3DLoaded()
		return "she is not here"
	endif
	; [pt17] a companion of Simple Follower Framework passes while the hand-off is on (her follow /
	; wait go through SFF's own calls); a custom follower's own quest still owns hers
	bool sff = LRG_Followers.IsSff(akNpc)
	if (akNpc.IsPlayerTeammate() || sff) && !(sff && SffFollowOn())
		return "she is your follower - her own follow and wait apply"
	endif
	if akNpc.IsHostileToActor(player) || akNpc.GetCombatState() != 0
		return "hostile"
	endif
	if akNpc.IsInCombat() || player.IsInCombat()
		return "combat"
	endif
	if ConvIsArrest(akNpc, player)
		return "an arrest"
	endif
	LRG_OStim ost = GetOStim()
	if ost && ost.IsActorInOurScene(akNpc)
		return "a scene with you"
	endif
	return ""
EndFunction

string Function EscortSayRefusal(string asName, string asWhy)
	{[0.5.7] Her words for an EscortRefuseReason. The reason itself stays the technical text (log, corner, err=).}
	if asWhy == "she is not here"
		return asName + " is not here"
	elseif asWhy == "she is your follower - her own follow and wait apply"
		return asName + " already travels with you, ask her the way you always do"
	elseif asWhy == "hostile"
		return asName + " is hostile to you"
	elseif asWhy == "combat"
		return asName + " cannot, not in the middle of a fight"
	elseif asWhy == "an arrest"
		return asName + " is here to arrest you"
	elseif asWhy == "a scene with you"
		return asName + " is busy with you right now"
	elseif asWhy == "a scripted intro"
		return asName + " is in the middle of " + QuietWords() + " and cannot leave it" ; [pt19]
	endif
	return asName + " cannot do that right now"
EndFunction

string Function EscortSayBusy(string asName, string asQid, string asNo)
	{[0.5.7] Her words for a scene "follow me" may not end (asNo: EscortSceneVerdict, or the MCM switch).}
	if asNo == "a quest in your journal"
		return asName + " is in the middle of something for your quest and cannot leave"
	elseif asNo == "a main, faction or courier quest"
		return asName + " is in the middle of something important and cannot leave"
	elseif EscortMatch(asQid, "BardSongs*")
		return asName + " is in the middle of a performance she cannot leave"
	endif
	return asName + " is in the middle of something she cannot leave"
EndFunction

bool Function SffFollowOn()
	{[pt17] The hand-off switch: the mod on and bSffFollow:Followers (default on; a MISSING ini key reads 0).}
	return IsEnabled() && SettingBool("bSffFollow:Followers", true)
EndFunction

string Function EscortFacts(Actor akNpc)
	;/[pt17] The tail of every escort log line: her follower facts exactly as the snapshot sends them
	 (fw / mate / cff / pff / wait / chim / ghost / slot / cap) and CHIM's own "NPCs Walk To Target"
	 switch - save-side StorageUtil, readable by any script - so the owner steps are checkable in the
	 log instead of inferred from AIAgent.log./;
	if akNpc == None
		return ""
	endif
	return " fol=[" + LRG_Followers.FolState(akNpc) + "] walkto=" + StorageUtil.GetIntValue(None, "AIAgentNpcWalkToTarget", 0)
EndFunction

Function EscortFollow(Actor akNpc, string asName, string asCid, string asNpcName, string asCommand, string asParam)
	string safe = ParamGet(asParam, "safe")
	string how = ""
	string qid = ""
	Scene sc = akNpc.GetCurrentScene()
	if sc != None
		Quest owner = sc.GetOwningQuest()
		if owner
			qid = owner.GetID()
		endif
		string no = EscortSceneVerdict(owner, qid, safe)
		if no == "" && !SettingBool("bEscortStopScene:Followers", true)
			no = "the MCM keeps her in her scene (bEscortStopScene)"
		endif
		if no != ""
			LogC(asCid, "escort " + asName + " do=follow: refused - her scene belongs to " + EscortQ(qid) + ": " + no, asName)
			ReportError(asNpcName, asCommand, asParam, EscortSayBusy(asName, qid, no), asName + " is in the middle of something she cannot leave (" + no + ")")
			return
		endif
		how = EscortStopScene(akNpc, sc, owner, qid)
	endif
	if convActor == akNpc
		ReleaseConvHold("she follows you now")
	endif
	EscortUnwait(akNpc)
	;/[pt17] THE FRAMEWORK FIRST. A companion SFF already owns follows again through SFF's own call; a
	 stranger SFF can take right now (SffRefuseReason: installed, a free slot, PotentialFollowerFaction,
	 not a hireling) is recruited exactly as her own "Follow me, I need your help" line would (SffRecruit:
	 CHIM's follow vetoed off her first, then SetFollower, verified with SFF's own IsVanillaFollower).
	 Everybody else gets the old path below - CHIM's follow + the pt16 overlay - and the reason rides on
	 the OK funcret as fb=<reason>, which the server voices (never silent: she comes, and says why not as
	 a companion). bSffFollow off = the old path for everyone, with nothing to tell./;
	string fb = ""
	string pkg = ""
	if SffFollowOn()
		if LRG_Followers.IsSff(akNpc)
			string via = LRG_Followers.SffWait(akNpc, false)
			pkg = "your companion follows again (Simple Follower Framework, WaitingForPlayer 0 via " + via + ")"
		else
			fb = LRG_Followers.SffRefuseReason(akNpc)
			if fb == ""
				int did = LRG_Followers.SffRecruit(akNpc)
				if did == 1
					string slot = LRG_Followers.SffSlotText()
					pkg = "recruited as your companion through Simple Follower Framework (slot " + slot + ")"
					SendNpcMessage(MSG_LOG, "follower recruited npc=" + CleanForWire(asName) + ";slot=" + slot + ";cid=" + asCid, asName)
				elseif did == -1
					fb = "the framework did not take her"
				else
					fb = "Simple Follower Framework could not be reached"
				endif
			endif
		endif
	endif
	if pkg == ""
		pkg = "CHIM's follow was already on"
		bool followFailed = false
		if LRG_Followers.ChimFollowActive(akNpc) != 1
			Faction ff = Game.GetFormFromFile(0x0001BC24, "AIAgent.esp") as Faction
			bool hadFaction = ff != None && akNpc.IsInFaction(ff)
			LRG_Followers.ChimFollowPlayer(akNpc)
			if LRG_Followers.ChimFollowActive(akNpc) == 1
				StorageUtil.SetIntValue(akNpc, "LRG_EscortApplied", 1)
				if ff != None && !hadFaction
					StorageUtil.SetIntValue(akNpc, "LRG_EscortFaction", 1)
				endif
				pkg = "the glue put CHIM's follow on (it was not on)"
			else
				pkg = "CHIM's follow could not be put on (AIAgent.esp forms not found)"
				followFailed = true
			endif
		else
			akNpc.EvaluatePackage()
		endif
		if followFailed
			; [0.5.7] never "comes with you" for a follow that is not on: she says she cannot
			string done = "no scene to end"
			if how != ""
				done = "stopped scene " + EscortQ(qid) + " (" + how + ")"
			endif
			LogC(asCid, "escort " + asName + ": " + done + " - NOT following: " + pkg + EscortFacts(akNpc), asName)
			ReportError(asNpcName, asCommand, asParam, asName + " cannot come with you right now", asName + " cannot follow you: " + pkg)
			return
		endif
		; [pt16] CHIM's follow is on her: the glue's own package goes on top of it (the game's follower
		; distances), and the log line says whether it really did
		pkg += GlueFollowApply(akNpc, asName)
		if fb != ""
			pkg += " - NOT as your companion: " + fb
		endif
	endif
	; [pt17] the fallback's reason travels on the OK funcret (additive key, field 2) and is the corner note
	string extra = ""
	if fb != ""
		extra = ";fb=" + LRG_Profile.Clip(CleanForWire(fb), 120)
		if SettingBool("bNotifyErrors:General", true)
			Debug.Notification("LoreRim Glue: " + asName + " walks with you, but not as a companion - " + fb)
		endif
	endif
	if how == ""
		LogC(asCid, "escort " + asName + ": no scene to end - following - " + pkg + EscortFacts(akNpc), asName)
		ReportResult(asNpcName, asCommand, asParam + extra, "OK: " + asName + " comes with you.")
		return
	endif
	; the quest behind her scene may simply start it again: look twice over the next few seconds
	escortActor = akNpc
	escortName = asName
	escortCid = asCid
	escortSafe = safe
	escortCall = asNpcName
	escortParam = asParam
	escortTries = 0
	escortFree = 0
	escortCheckAt = Utility.GetCurrentRealTime() + 2.5
	RequestTick(2.5)
	LogC(asCid, "escort " + asName + ": stopped scene " + EscortQ(qid) + " (" + how + ") and following - " + pkg + EscortFacts(akNpc), asName)
	ReportResult(asNpcName, asCommand, asParam + extra, "OK: " + asName + " stops what she was doing and comes with you.")
EndFunction

Function EscortWait(Actor akNpc, string asName, string asCid, string asNpcName, string asCommand, string asParam)
	;/WaitingForPlayer 1 - the value every follower package reads. Never on a recruited follower (the
	 guard above): her own dialogue owns wait. What it was before is kept, so follow / release can
	 give the exact value back; and the follow is taken off (EscortStopFollowing), or she would walk
	 after the player while "waiting".
	 [0.5.6 verifier] CHIM's FollowPlayerPackage runs on AIAgentFactionFollow rank 1 only - it never
	 reads WaitingForPlayer - so the follow itself has to come off, whoever put it on.
	 [pt17] A companion of Simple Follower Framework waits through SFF's own call (SffWait: FollowerWait
	 with its 72-hour timer, the value itself as the fallback) - no LRG_EscortWait bookkeeping: the
	 framework owns the value from here, exactly as her own "Wait here" entry would have left it./;
	if escortActor == akNpc
		escortActor = None
	endif
	if SffFollowOn() && LRG_Followers.IsSff(akNpc)
		string via = LRG_Followers.SffWait(akNpc, true)
		LogC(asCid, "escort " + asName + ": waits here (your companion - Simple Follower Framework, WaitingForPlayer 1 via " + via + ")" + EscortFacts(akNpc), asName)
		ReportResult(asNpcName, asCommand, asParam, "OK: " + asName + " waits here.")
		return
	endif
	if StorageUtil.GetIntValue(akNpc, "LRG_EscortWait", 0) != 1
		StorageUtil.SetIntValue(akNpc, "LRG_EscortWaitPrev", akNpc.GetActorValue("WaitingForPlayer") as int)
		StorageUtil.SetIntValue(akNpc, "LRG_EscortWait", 1)
	endif
	akNpc.SetActorValue("WaitingForPlayer", 1.0)
	string drop = EscortStopFollowing(akNpc)
	if drop != ""
		drop = ", " + drop
	endif
	akNpc.EvaluatePackage()
	LogC(asCid, "escort " + asName + ": waits here (WaitingForPlayer 1" + drop + ")", asName)
	ReportResult(asNpcName, asCommand, asParam, "OK: " + asName + " waits here.")
EndFunction

Function EscortRelease(Actor akNpc, string asName, string asCid, string asNpcName, string asCommand, string asParam)
	if escortActor == akNpc
		escortActor = None
	endif
	if SffFollowOn() && LRG_Followers.IsSff(akNpc)
		;/[pt17] "stop following me" / "you can go" to a companion of Simple Follower Framework is a WAIT
		 (WaitingForPlayer 1 through SFF's own FollowerWait), never a dismissal: parting ways is
		 commit-class (pt9 6.2, the two-step of the menuless follower verb) and stays her own dialogue's.
		 Two guards of its own, voiced through EscortSayRefusal, because this is no longer "only undo"./;
		string no = ""
		if akNpc.IsDead() || akNpc.IsDisabled() || !akNpc.Is3DLoaded()
			no = "she is not here"
		elseif akNpc.IsInCombat()
			no = "combat"
		endif
		if no != ""
			LogC(asCid, "escort " + asName + " do=release: refused - " + no, asName)
			ReportError(asNpcName, asCommand, asParam, EscortSayRefusal(asName, no), asName + " will not do that now (" + no + ")")
			return
		endif
		string via = LRG_Followers.SffWait(akNpc, true)
		LogC(asCid, "escort " + asName + ": released - stops following and waits (your companion - Simple Follower Framework, WaitingForPlayer 1 via " + via + "; parting ways for good is her own follower dialogue)" + EscortFacts(akNpc), asName)
		ReportResult(asNpcName, asCommand, asParam, "OK: " + asName + " stops following and waits here.")
		return
	endif
	bool waited = StorageUtil.GetIntValue(akNpc, "LRG_EscortWait", 0) == 1
	string what = EscortStopFollowing(akNpc)
	EscortUnwait(akNpc)
	akNpc.EvaluatePackage()
	if waited
		if what != ""
			what += "; "
		endif
		what += "the glue's wait is off"
	endif
	if what == ""
		what = "no follow and no wait of the glue's or CHIM's was on her"
	endif
	LogC(asCid, "escort " + asName + ": released - " + what, asName)
	ReportResult(asNpcName, asCommand, asParam, "OK: " + asName + " goes back to her own business.")
EndFunction

string Function EscortStopFollowing(Actor akNpc)
	;/[0.5.6 verifier] wait / release end the follow WHOEVER put it on - PROTOCOL 10.20 section 4. On this
	 install CHIM cannot end its own follow for an NPC (WaitHere is a notification stub and off in the
	 catalog; StopWalk is not offered to NPCs), and the server has just told her "the game keeps her here
	 / lets her go". A follow the glue put on goes with its faction (EscortDropFollow). CHIM's own:
	 exactly the follow half of CHIM's ResetPackages - the FollowPlayerPackage override off and
	 CHIM_FollowPlayerActive 0; the follow faction stays, as ResetPackages leaves it. Returns what it
	 did ("" = nothing).
	 [pt16] The glue's own follow package (the natural-follow overlay) comes off FIRST, whoever put
	 CHIM's on: it is unconditioned and would keep her walking after CHIM's follow is gone.
	 [pt17] On a teammate / SFF follower every follow-type move of CHIM's comes off (ChimFollowVeto)
	 instead of being left on her: her framework owns follow, and CHIM's priority-100 override or its
	 soft package would out-drive every alias package of hers. (508 returned "" here.)/;
	string ours = ""
	if GlueFollowRelease(akNpc)
		ours = " + the glue's own follow package"
	endif
	if StorageUtil.GetIntValue(akNpc, "LRG_EscortApplied", 0) == 1
		EscortDropFollow(akNpc)
		return "the follow the glue put on is off" + ours
	endif
	if akNpc.IsPlayerTeammate() || LRG_Followers.IsSff(akNpc)
		if SffFollowOn() && LRG_Followers.ChimFollowVeto(akNpc)
			return "CHIM's follow taken off your companion (her framework owns follow)" + ours
		endif
		if ours != ""
			return "the glue's own follow package is off"
		endif
		return ""
	endif
	if LRG_Followers.ChimFollowActive(akNpc) != 1
		if ours != ""
			return "the glue's own follow package is off"
		endif
		return ""
	endif
	Package p = Game.GetFormFromFile(0x0002226D, "AIAgent.esp") as Package
	if p
		ActorUtil.RemovePackageOverride(akNpc, p)
	endif
	StorageUtil.SetIntValue(akNpc, "CHIM_FollowPlayerActive", 0)
	return "CHIM's follow is off (its package override and CHIM_FollowPlayerActive, as CHIM's ResetPackages does)" + ours
EndFunction

Function EscortDropFollow(Actor akNpc)
	{Takes off the CHIM follow the glue put on (LRG_EscortApplied), and CHIM's follow faction if the
	 glue added it. Never touches a follow CHIM put on by itself. [pt16] The glue's own overlay goes first.}
	GlueFollowRelease(akNpc)
	Package p = Game.GetFormFromFile(0x0002226D, "AIAgent.esp") as Package
	if p
		ActorUtil.RemovePackageOverride(akNpc, p)
	endif
	StorageUtil.SetIntValue(akNpc, "CHIM_FollowPlayerActive", 0)
	if StorageUtil.GetIntValue(akNpc, "LRG_EscortFaction", 0) == 1
		Faction ff = Game.GetFormFromFile(0x0001BC24, "AIAgent.esp") as Faction
		if ff
			akNpc.RemoveFromFaction(ff)
		endif
	endif
	StorageUtil.UnsetIntValue(akNpc, "LRG_EscortApplied")
	StorageUtil.UnsetIntValue(akNpc, "LRG_EscortFaction")
EndFunction

Function EscortUnwait(Actor akNpc)
	{Gives WaitingForPlayer back its old value - only if the glue wrote it.}
	if StorageUtil.GetIntValue(akNpc, "LRG_EscortWait", 0) != 1
		return
	endif
	akNpc.SetActorValue("WaitingForPlayer", StorageUtil.GetIntValue(akNpc, "LRG_EscortWaitPrev", 0) as float)
	StorageUtil.UnsetIntValue(akNpc, "LRG_EscortWait")
	StorageUtil.UnsetIntValue(akNpc, "LRG_EscortWaitPrev")
EndFunction

string Function EscortQ(string asQid)
	if asQid == ""
		return "(no quest)"
	endif
	return asQid
EndFunction

string Function EscortSceneVerdict(Quest akOwner, string asQid, string asSafe)
	;/"" = this scene may be ended for "follow me". The deny list and the journal test come first and
	 no safe= list from the server can get past them./;
	if akOwner == None || asQid == ""
		return "a scene the glue cannot identify"
	endif
	if EscortMatchAny(asQid, ESCORT_DENY)
		return "a main, faction or courier quest"
	endif
	if EscortHasJournal(akOwner)
		return "a quest in your journal"
	endif
	string pats = asSafe
	if pats == ""
		pats = ESCORT_SAFE_DEFAULT
	endif
	if !EscortMatchAny(asQid, pats)
		return "not a performance or idle scene she may leave"
	endif
	return ""
EndFunction

bool Function EscortHasJournal(Quest akQuest)
	{An objective of this quest is on the player's screen and not done: its scene is never ended.}
	int[] objs = PO3_SKSEFunctions.GetAllQuestObjectives(akQuest)
	if !objs
		return false
	endif
	int n = objs.Length
	if n > 40
		n = 40
	endif
	int i = 0
	while i < n
		int o = objs[i]
		if akQuest.IsObjectiveDisplayed(o) && !akQuest.IsObjectiveCompleted(o) && !akQuest.IsObjectiveFailed(o)
			return true
		endif
		i += 1
	endwhile
	return false
EndFunction

bool Function EscortMatchAny(string asId, string asCsv)
	{asCsv: comma-separated patterns, spaces around them ignored, at most 24 looked at.}
	int len = StringUtil.GetLength(asCsv)
	int start = 0
	int count = 0
	while start < len && count < 24
		int comma = StringUtil.Find(asCsv, ",", start)
		string pat = ""
		if comma < 0
			pat = StringUtil.Substring(asCsv, start)
			start = len
		else
			if comma > start
				pat = StringUtil.Substring(asCsv, start, comma - start)
			endif
			start = comma + 1
		endif
		pat = EscortTrim(pat)
		if pat != "" && EscortMatch(asId, pat)
			return true
		endif
		count += 1
	endwhile
	return false
EndFunction

string Function EscortTrim(string asText)
	string s = asText
	int guard = 0
	while guard < 8 && StringUtil.GetLength(s) > 0 && StringUtil.Substring(s, 0, 1) == " "
		if StringUtil.GetLength(s) == 1
			return ""
		endif
		s = StringUtil.Substring(s, 1)
		guard += 1
	endwhile
	guard = 0
	int n = StringUtil.GetLength(s)
	while guard < 8 && n > 1 && StringUtil.Substring(s, n - 1, 1) == " "
		s = StringUtil.Substring(s, 0, n - 1)
		n -= 1
		guard += 1
	endwhile
	if s == " "
		return ""
	endif
	return s
EndFunction

bool Function EscortMatch(string asId, string asPat)
	;/A quest EditorID against one pattern - the server's own syntax for safe_quests: "*" any run of
	 characters, "?" exactly one, anything else literal. Case-insensitive, because Papyrus compares
	 strings without case. A pattern with fewer than two literal characters matches NOTHING ("*",
	 "?*", "**"): no list may make every scene in Skyrim fair game./;
	int pl = StringUtil.GetLength(asPat)
	int il = StringUtil.GetLength(asId)
	if pl <= 0 || il <= 0
		return false
	endif
	int lits = 0
	int stars = 0
	int quests = 0
	int k = 0
	string c = ""
	while k < pl
		c = StringUtil.Substring(asPat, k, 1)
		if c == "*"
			stars += 1
		elseif c == "?"
			quests += 1
		else
			lits += 1
		endif
		k += 1
	endwhile
	if lits < 2
		return false
	endif
	if stars == 0 && quests == 0
		return asId == asPat
	endif
	if quests == 0 && stars == 1 && StringUtil.Substring(asPat, pl - 1, 1) == "*"
		; "Prefix*" - the common case, one native
		if (pl - 1) > il
			return false
		endif
		return StringUtil.Substring(asId, 0, pl - 1) == StringUtil.Substring(asPat, 0, pl - 1)
	endif
	; the general case: the classic wildcard walk with one back-track point, bounded by length
	int i = 0
	int p = 0
	int star = -1
	int mark = 0
	int guard = 0
	string pc = ""
	while i < il && guard < 4000
		guard += 1
		pc = ""
		if p < pl
			pc = StringUtil.Substring(asPat, p, 1)
		endif
		if pc != "" && pc != "*" && (pc == "?" || pc == StringUtil.Substring(asId, i, 1))
			i += 1
			p += 1
		elseif pc == "*"
			star = p
			mark = i
			p += 1
		elseif star >= 0
			p = star + 1
			mark += 1
			i = mark
		else
			return false
		endif
	endwhile
	if guard >= 4000
		return false
	endif
	while p < pl && StringUtil.Substring(asPat, p, 1) == "*"
		p += 1
	endwhile
	return p == pl
EndFunction

string Function EscortStopScene(Actor akNpc, Scene akScene, Quest akOwner, string asQid)
	;/Ends her scene the non-destructive way and says how. A bard: BardSongsScript.StopAllSongs() on
	 the scene's own quest, or - if that quest does not carry the script - on Skyrim.esm BardSongs
	 (0x00074A55, the quest CHIM stops too), checked by its editor id first. Anything else: Stop()./;
	string how = ""
	if EscortMatch(asQid, "BardSongs*")
		BardSongsScript bs = akOwner as BardSongsScript
		if bs == None
			Quest bq = Game.GetForm(0x00074A55) as Quest
			if bq && bq.GetID() == "BardSongs"
				bs = bq as BardSongsScript
			endif
		endif
		if bs
			bs.StopAllSongs()
			how = "BardSongsScript.StopAllSongs"
			if akNpc.GetSitState() == 0
				Debug.SendAnimationEvent(akNpc, "IdleForceDefaultState") ; the instrument pose, as CHIM does
			endif
		endif
	endif
	if akScene.IsPlaying()
		akScene.Stop()
		if how != ""
			how += " + Scene.Stop"
		else
			how = "Scene.Stop"
		endif
	endif
	if how == ""
		how = "it had just ended by itself"
	endif
	return how
EndFunction

Function TickEscort(float afNow)
	;/The few seconds after a scene was ended for "follow me". A bard's or an idle quest may simply
	 start the scene again; it is ended at most twice more (same verdict as the first time), and
	 then she is left in it with a corner note - the glue never fights a quest in a loop./;
	if escortActor == None
		return
	endif
	if afNow < escortCheckAt && (escortCheckAt - afNow) < 30.0
		RequestTick(escortCheckAt - afNow)
		return
	endif
	Actor a = escortActor
	string nm = escortName
	if a.IsDead() || a.IsDisabled() || !a.Is3DLoaded()
		escortActor = None
		return
	endif
	Scene sc = a.GetCurrentScene()
	if sc == None
		escortFree += 1
		if escortFree >= 2
			escortActor = None
			LogC(escortCid, "escort " + nm + ": free of her scene - following", nm)
			return
		endif
		escortCheckAt = afNow + 6.0
		RequestTick(6.0)
		return
	endif
	Quest owner = sc.GetOwningQuest()
	string qid = ""
	if owner
		qid = owner.GetID()
	endif
	string no = EscortSceneVerdict(owner, qid, escortSafe)
	if no == "" && escortTries < 2 && SettingBool("bEscortStopScene:Followers", true) && !IsDryRun()
		escortTries += 1
		escortFree = 0
		string how = EscortStopScene(a, sc, owner, qid)
		a.EvaluatePackage()
		LogC(escortCid, "escort " + nm + ": her scene started again (" + EscortQ(qid) + ") - ended it again (" + how + "), try " + escortTries, nm)
		escortCheckAt = afNow + 2.5
		RequestTick(2.5)
		return
	endif
	escortActor = None
	if no == ""
		no = "it keeps starting again"
	endif
	LogC(escortCid, "escort " + nm + ": she went back to her scene (" + EscortQ(qid) + ") and stays - " + no, nm)
	Debug.Notification("LoreRim Glue: " + nm + " is in the middle of something she cannot leave")
	;/[0.5.7] NEVER SILENT: she was answered "comes with you" a few seconds ago and is now back in her
	 scene. One late Error: funcret for the same command and cid, so she can say so herself./;
	if escortCall != ""
		string back = nm + " had to go back to what she was doing"
		if EscortMatch(qid, "BardSongs*")
			back = nm + " had to go back to her performance"
		endif
		ReportLateError(escortCall, "ExtCmdLRG_Escort", escortParam, back, "she went back to her scene (" + EscortQ(qid) + ") and stays - " + no)
	endif
	escortCall = ""
	escortParam = ""
EndFunction

; ---------------------------------------------------------------------------
; [pt16] THE NATURAL FOLLOW - the glue's own follow package laid over CHIM's (research/pt16-follow.md)
; ---------------------------------------------------------------------------
;/Playtest 16: "she wouldn't walk at all, she would just sprint as fast as she could and then stop".
 What was on Lisette was CHIM's own follow package, AIAgent.esp PACK 0x2226D - a Follow procedure
 authored with Min Radius 512 / Max Radius 1024 units and Need LOS, four times the radii of the
 game's own follower packages (Skyrim.esm FollowPlayer 0x750BE: 128 / 256, no LOS rule): she stands
 still until the player is far off, closes the gap at a run and stops well short of him, and indoors
 the LOS rule makes it worse. Package inputs cannot be changed at runtime, so LoreRimGlue.esp carries
 one PACK of its own (0x803 LRG_FollowPackage: CHIM's record with those three inputs patched, no
 condition - tools/make_esp.py) and this block lays it OVER CHIM's follow at PapyrusUtil priority
 100, where the LAST ADDED override wins a tie. CHIM keeps owning CHIM_FollowPlayerActive and its
 own package; the glue only shadows it:
   - EscortFollow puts ours on right after CHIM's (GlueFollowApply);
   - NoteGlueFollow, on every snapshot BEFORE NoteFollower's 20 s throttle and before FollowerAware(),
     shadows a follow CHIM's own FollowPlayer put on (the glue gets no event for CHIM's catalog
     actions - NoteChimAction is dormant for them) and takes ours off when CHIM's is over
     (ResetPackages), when she becomes a teammate / SFF follower, or when the MCM toggle is off;
   - TickGlueFollow, every 4 s while somebody is tracked (and while the watched NPC is not yet
     tracked, so a fresh FollowPlayer is caught within seconds): puts ours back when CHIM re-adds
     its own (stayAtPlace again, EndFollowSoft, MoveToPlayer - GlueFollowLost), STANDS DOWN while
     CHIM moves her itself (its MoveTarget linked ref is set: ComeCloser, walk-to-target - ours at
     100 would starve CHIM's soft package at 55 and its end fragment would never restore CHIM's
     follow), and takes ours off under the snapshot path's rules.
 The ring gfQ is SAVED on purpose (not cleared by Maintenance): the overrides themselves live in
 the save (PapyrusUtil), so what watches them has to survive the load as well - the first tick after
 the boot queue re-asserts ours whatever order PapyrusUtil restored the two in. A save from before
 script 508 has no ring: GfRing() makes one on first use, never in OnInit. Wait / release / a drop
 take ours off first (GlueFollowRelease). MCM: bNaturalFollow:Followers, default on.
 StorageUtil key on the NPC, the glue's own: LRG_GlueFollow (LRG_Followers). Nothing on the wire
 changes in either direction./;
Actor[] gfQ                ; the NPCs whose CHIM follow the glue shadows (4 slots, saved)
float[] gfT                ; per slot: when she was last seen loaded (real time; a load resets it)
int gfCount = 0            ; taken slots - the OnUpdate fan-out's one field test
int gfSlot = 0             ; the slot that goes when all four are taken (round robin)
bool gfOn = false          ; the MCM toggle as the tick last read it
float gfOnAt = 0.0         ; ...and when (refreshed every 30 s; a load resets it)
float gfPollAt = 0.0       ; when the watched-but-untracked NPC was last looked at (OnUpdate also wakes every 0.8 s in a scene)

bool Function NaturalFollowOn()
	{The [pt16] switch: the mod on, the kill switch off, and the MCM toggle (default on; a missing ini key reads 0).}
	return IsEnabled() && SettingBool("bNaturalFollow:Followers", true)
EndFunction

Function GfRing()
	{The ring, made on first use - a save from before script 508 carries none. Never from OnInit.}
	if !gfQ || gfQ.Length != 4
		gfQ = new Actor[4]
		gfT = new float[4]
		gfCount = 0
		gfSlot = 0
	elseif !gfT || gfT.Length != 4
		gfT = new float[4]
	endif
EndFunction

int Function GfIndex(Actor akNpc)
	{Her slot in the ring, -1 when she is not tracked. No native.}
	if akNpc == None || !gfQ || gfQ.Length != 4
		return -1
	endif
	int i = 0
	while i < 4
		if gfQ[i] == akNpc
			return i
		endif
		i += 1
	endwhile
	return -1
EndFunction

Function GfTrack(Actor akNpc)
	;/Takes a slot for her so TickGlueFollow keeps ours on top of CHIM's. A full ring gives up its
	 oldest slot, and that NPC's overlay comes OFF with it: never a dormant, unconditioned override on
	 somebody nobody watches (CHIM's ResetPackages does not know our form)./;
	if akNpc == None
		return
	endif
	GfRing()
	float now = Utility.GetCurrentRealTime()
	int i = GfIndex(akNpc)
	if i >= 0
		gfT[i] = now
		RequestTick(4.0)
		return
	endif
	i = 0
	while i < 4
		if gfQ[i] == None
			gfQ[i] = akNpc
			gfT[i] = now
			gfCount += 1
			RequestTick(4.0)
			return
		endif
		i += 1
	endwhile
	Actor old = gfQ[gfSlot]
	if old != None
		if LRG_Followers.GlueFollowOff(old)
			Log("natural follow " + old.GetDisplayName() + ": off - the ring is full and " + akNpc.GetDisplayName() + " takes her slot (she keeps CHIM's follow as it is)")
		endif
	endif
	gfQ[gfSlot] = akNpc
	gfT[gfSlot] = now
	gfSlot = (gfSlot + 1) % 4
	RequestTick(4.0)
EndFunction

Function GfDrop(int aiSlot)
	{Frees one slot. Member writes only.}
	if aiSlot < 0 || aiSlot > 3 || !gfQ || gfQ.Length != 4
		return
	endif
	if gfQ[aiSlot] != None
		gfQ[aiSlot] = None
		gfT[aiSlot] = 0.0
		gfCount -= 1
	endif
	if gfCount < 0
		gfCount = 0
	endif
EndFunction

Function QuietFollowOff()
	{[pt19] Quiet mode: the glue's overlay comes off every tracked NPC and the slots are freed. Member writes plus one GlueFollowOff per tracked NPC.}
	int i = 0
	while i < 4 && gfCount > 0
		Actor a = gfQ[i]
		if a != None
			GfDrop(i)
			if LRG_Followers.GlueFollowOff(a)
				Log("natural follow " + a.GetDisplayName() + ": off - quiet mode (" + quietWhy + ")")
			endif
		endif
		i += 1
	endwhile
EndFunction

bool Function GlueFollowRelease(Actor akNpc)
	{Ours off and untracked - wait, release, a drop of the glue's escort follow. Returns whether ours was on.}
	if akNpc == None
		return false
	endif
	GfDrop(GfIndex(akNpc))
	return LRG_Followers.GlueFollowOff(akNpc)
EndFunction

string Function GlueFollowApply(Actor akNpc, string asName)
	;/EscortFollow's half: puts the glue's package over CHIM's (which the caller has just made sure is
	 on) and tracks her. Returns the fragment for the escort log line, and that fragment says "not on"
	 when it is not - the line never claims an overlay that is not there./;
	if akNpc == None
		return ""
	endif
	if !NaturalFollowOn()
		return " - natural follow off (bNaturalFollow)"
	endif
	if IsDryRun()
		return " - natural follow NOT applied (dry run)"
	endif
	if LRG_Followers.ChimSoftMoveOn(akNpc)
		GfTrack(akNpc) ; CHIM is moving her itself: ours goes on once that move has ended (the tick)
		return " - natural follow waits for CHIM's own move to end"
	endif
	if !LRG_Followers.GlueFollowOn(akNpc)
		return " - natural follow NOT on (LoreRimGlue.esp PACK 0x803 not found)"
	endif
	GfTrack(akNpc)
	return " - natural follow on (the glue's package over CHIM's: 128 / 256 units, no LOS rule)"
EndFunction

Function NoteGlueFollow(Actor akNpc)
	;/The snapshot path's look at her, BEFORE NoteFollower's 20-second throttle and BEFORE
	 FollowerAware() (which SnapFolOk() can switch off for a session): a follow CHIM's own FollowPlayer
	 put on a moment ago is shadowed on her very next snapshot, and one CHIM ended loses our overlay
	 the same way. Two StorageUtil reads while nothing is on, which is nearly always. The tick's
	 decision for the watched NPC is this one too./;
	if akNpc == None
		return
	endif
	if QuietOn()
		; [pt19] quiet mode: ours comes OFF her if it is on (a dormant override on an intro actor is the worst case), never on
		if LRG_Followers.GlueFollowMine(akNpc) == 1 && GlueFollowRelease(akNpc)
			Log("natural follow " + akNpc.GetDisplayName() + ": off - quiet mode (" + quietWhy + ")")
		endif
		return
	endif
	int chim = LRG_Followers.ChimFollowActive(akNpc)
	int mine = LRG_Followers.GlueFollowMine(akNpc)
	bool mate = akNpc.IsPlayerTeammate()
	if chim != 1 && mine != 1
		;/[pt17] ...unless CHIM is moving a COMPANION itself (ComeCloser / walk-to on a teammate with
		 CHIM_FollowPlayerActive 0): one GetLinkedRef per snapshot of a teammate, nothing for anyone else/;
		if !(mate && SffFollowOn() && LRG_Followers.ChimSoftMoveOn(akNpc))
			return
		endif
	endif
	string nm = akNpc.GetDisplayName()
	if IsDryRun()
		; [pt17] the developer dry run takes the glue's own overlay off and never puts it on
		if mine == 1
			if GlueFollowRelease(akNpc)
				Log("natural follow " + nm + ": off - the dry run takes the glue's own overlay off and never puts it on")
			endif
		endif
		return
	endif
	if mate
		;/[pt17] HER FRAMEWORK OWNS FOLLOW: a follow-type move of CHIM's on a teammate (its priority-100
		 override, its soft package) out-drives every alias package of SFF's or a custom follower's own
		 quest - so it comes off her, the glue's overlay with it (508 only took the overlay off)./;
		GfDrop(GfIndex(akNpc))
		if SffFollowOn()
			if LRG_Followers.ChimFollowVeto(akNpc)
				Log("natural follow " + nm + ": off - she is your follower now; CHIM's follow taken off her (her framework owns follow)")
			endif
		elseif mine == 1
			if LRG_Followers.GlueFollowOff(akNpc)
				Log("natural follow " + nm + ": off - she is your follower now - her framework owns follow")
			endif
		endif
		return
	endif
	if !NaturalFollowOn()
		if mine == 1
			if GlueFollowRelease(akNpc)
				Log("natural follow " + nm + ": off - switched off in the MCM (CHIM's follow stays as it is)")
			endif
		endif
		return
	endif
	if chim != 1
		if mine == 1
			if GlueFollowRelease(akNpc)
				Log("natural follow " + nm + ": off - CHIM's follow is over (CHIM_FollowPlayerActive 0)")
			endif
		endif
		return
	endif
	; CHIM's follow is on her, and it is CHIM's to run
	if LRG_Followers.ChimSoftMoveOn(akNpc)
		GfTrack(akNpc)
		if mine == 1
			if LRG_Followers.GlueFollowOff(akNpc)
				Log("natural follow " + nm + ": standing down while CHIM moves her itself (ComeCloser / walk-to); back when that move ends")
			endif
		endif
		return
	endif
	if mine != 1
		if LRG_Followers.GlueFollowOn(akNpc)
			GfTrack(akNpc)
			Log("natural follow " + nm + ": shadowing CHIM's follow (the glue's package over CHIM's: 128 / 256 units, no LOS rule)")
		endif
		return
	endif
	if GfIndex(akNpc) < 0
		GfTrack(akNpc) ; ours is on her but nobody watched it (a load, a slot given up): watched again
	endif
	if LRG_Followers.GlueFollowLost(akNpc)
		if LRG_Followers.GlueFollowOn(akNpc)
			Log("natural follow " + nm + ": re-asserted (CHIM put its own package back on top)")
		endif
	endif
EndFunction

Function TickGlueFollow(float afNow)
	;/Every 4 s while somebody is tracked, and while an NPC is watched (watchActor) but not yet
	 tracked - CHIM's FollowPlayer lands a moment after her reply, and this catches it within seconds
	 instead of at her next 20-second snapshot (one StorageUtil read per look while she is not under
	 CHIM's follow). Nothing is logged per tick, only a change. The MCM toggle is read at most every
	 30 s. Called from the OnUpdate fan-out while gfCount > 0 or watchActor != None./;
	GfRing()
	if QuietOn()
		QuietFollowOff() ; [pt19] every tracked slot stands down; no poll and no next tick until quiet ends (her next snapshot tracks her again)
		return
	endif
	if gfOnAt <= 0.0 || afNow < gfOnAt || (afNow - gfOnAt) >= 30.0
		gfOn = NaturalFollowOn() && !IsDryRun()   ; [pt17] the developer dry run never lays the overlay
		gfOnAt = afNow
	endif
	int i = 0
	while i < 4 && gfCount > 0
		Actor a = gfQ[i]
		if a != None
			TickGlueSlot(i, a, afNow)
		endif
		i += 1
	endwhile
	if gfOn && (gfPollAt <= 0.0 || afNow < gfPollAt || (afNow - gfPollAt) >= 3.5)
		gfPollAt = afNow
		Actor w = watchActor
		if w != None && GfIndex(w) < 0 && LRG_Followers.ChimFollowActive(w) == 1 && !w.IsDead() && w.Is3DLoaded()
			NoteGlueFollow(w) ; the same decision as on her snapshot
		endif
	endif
	if gfCount > 0 || (gfOn && watchActor != None)
		RequestTick(4.0)
	endif
EndFunction

Function TickGlueSlot(int aiSlot, Actor a, float afNow)
	{One tracked NPC. Off and dropped: gone / dead / disabled, CHIM's follow over, a teammate, the toggle off. Stood down: CHIM's own move.}
	if a.IsDead() || a.IsDisabled()
		GfDrop(aiSlot)
		if LRG_Followers.GlueFollowOff(a)
			Log("natural follow " + a.GetDisplayName() + ": off - she is gone")
		endif
		return
	endif
	int mine = LRG_Followers.GlueFollowMine(a)
	if !a.Is3DLoaded()
		;/In another cell: nothing can be judged. Ours stays on for a while (she may be a door away) and
		 comes off after two minutes, so no dormant override outlives the follow it shadows; her next
		 snapshot puts it back if CHIM's follow still runs./;
		if afNow < gfT[aiSlot]
			gfT[aiSlot] = afNow ; a load reset the clock
		elseif (afNow - gfT[aiSlot]) > 120.0
			GfDrop(aiSlot)
			if mine == 1
				if LRG_Followers.GlueFollowOff(a)
					Log("natural follow " + a.GetDisplayName() + ": off - out of sight for two minutes (back on her next snapshot if CHIM's follow still runs)")
				endif
			endif
		endif
		return
	endif
	gfT[aiSlot] = afNow
	int chim = LRG_Followers.ChimFollowActive(a)
	bool mate = a.IsPlayerTeammate()
	if !gfOn || chim != 1 || mate
		GfDrop(aiSlot)
		if mate && SffFollowOn()
			; [pt17] her framework owns follow: CHIM's follow-type moves come off her, ours with them
			if LRG_Followers.ChimFollowVeto(a)
				Log("natural follow " + a.GetDisplayName() + ": off - she is your follower now; CHIM's follow taken off her (her framework owns follow)")
			endif
			return
		endif
		if mine == 1
			if LRG_Followers.GlueFollowOff(a)
				string why = "CHIM's follow is over (CHIM_FollowPlayerActive 0)"
				if !gfOn
					why = "switched off in the MCM (CHIM's follow stays as it is)"
				elseif chim == 1
					why = "she is your follower now - her framework owns follow"
				endif
				Log("natural follow " + a.GetDisplayName() + ": off - " + why)
			endif
		endif
		return
	endif
	if LRG_Followers.ChimSoftMoveOn(a)
		if mine == 1
			if LRG_Followers.GlueFollowOff(a)
				Log("natural follow " + a.GetDisplayName() + ": standing down while CHIM moves her itself (ComeCloser / walk-to); back when that move ends")
			endif
		endif
		return
	endif
	if mine != 1
		if LRG_Followers.GlueFollowOn(a)
			Log("natural follow " + a.GetDisplayName() + ": on - CHIM's own move has ended")
		endif
		return
	endif
	if LRG_Followers.GlueFollowLost(a)
		if LRG_Followers.GlueFollowOn(a)
			Log("natural follow " + a.GetDisplayName() + ": re-asserted (CHIM put its own package back on top)")
		endif
	endif
EndFunction

; ---------------------------------------------------------------------------
; NPC snapshots: the hard facts only the game knows, sent BEFORE the player
; speaks so the server can gate actions on fresh data.
; Freshness: the server trusts a snapshot for 90 s, so while the player keeps dealing with the
; same agent it is refreshed every ~20 s (SnapTick) and on every line the NPC says.
; ---------------------------------------------------------------------------
Event OnCrosshairRefChange(ObjectReference akRef)
	Actor npc = akRef as Actor
	if npc
		MaybeSnapshot(npc, false)
		Watch(npc, false)
		; v0.4.1 WEAK refresh only: looking at her is not talking to her, so this can extend a hold
		; that is already live but can never start one. Free (one bool) while nothing is held, which
		; matters because this event fires constantly.
		NoteConvLine(npc, Utility.GetCurrentRealTime(), true)
	endif
EndEvent

Event OnChimSpeechStarted(Form akNpc)
	{CHIM sends this once per SENTENCE (AIAgentAIMind.FakeDialogueWith / FakeDialogue), for the
	 player's own voiced line too. LRG_OStim needs both timestamps for the announce gate, so every
	 one of them is forwarded; the decision which of them counts is made there.}
	Actor npc = akNpc as Actor
	if npc == None
		return
	endif
	float now = Utility.GetCurrentRealTime()
	LRG_OStim ost = GetOStim()
	if npc == Game.GetPlayer()
		playerTalkTime = now ; CHIM voices the player's line: a spoken line "either way"
		latAskStart = now    ; v0.5: his own line begins - the reply cannot arrive before it ends
		NoteConvLine(npc, now, false) ; v0.4.1: the player's own line refreshes the hold too
		NoteSpeechToDriver(0, now, npc) ; v0.5 X1, [v1.0] and the auto-advance cancel: he is speaking
		if ost
			ost.NotePlayerSpeech(now) ; the player is steering: no scene-lead turn right now
		endif
		return
	endif
	;/[0.5.2] ORDER MATTERS HERE. MaybeSnapshot reaches into three other mods; a Papyrus error in any
	 of them unwinds this whole event, and everything below the call is then simply not done. In
	 playtest 10 that cost the latency mark, the OStim pacing and - through NoteConvLine - the
	 conversation hold, which is why "she would not stay" and "follow me took ages" arrived
	 together with the missing snapshots. Everything that does NOT need the snapshot now happens
	 first; only Watch and NoteConvLine stay behind it, because both are keyed on lastSnapActor./;
	; v0.5: HER voice has started. This is the end of the clock ev=lat measures, and the ONE place
	; it is sent - LatStarted() is a no-op unless a reply of hers is waiting to be timed.
	LatStarted(npc, now)
	NoteSpeechToDriver(2, now, npc) ; [v1.0 / model F12] HER VOICE started: its own kind, the re-armed anchor
	if ost
		ost.NoteActivity(npc)
		ost.NotePartnerSpeech(npc, true, now)
	endif
	MaybeSnapshot(npc, false)
	Watch(npc, true)
	NoteConvLine(npc, now, false)
EndEvent

Event OnChimSpeechStopped(Form akNpc)
	{End of a voiced line: the "quiet for 20 s" rule of the initiative tick counts from here.}
	Actor npc = akNpc as Actor
	if npc == None
		return
	endif
	float now = Utility.GetCurrentRealTime()
	if npc == Game.GetPlayer()
		playerTalkTime = now
		; v0.5: the player has stopped talking. THIS is where the owner's own wait begins, so it is
		; the zero of ev=lat's total, and it arms the next reply for timing.
		latAskStop = now
		latSent = false
		latTextAt = 0.0
		latTextNpc = ""
		NoteConvLine(npc, now, false)
		NoteSpeechToDriver(3, now, npc) ; [v1.0 review] his line ENDED: the X1 probe only, never the auto-advance cancel
		LRG_OStim pst = GetOStim()
		if pst
			pst.NotePlayerSpeech(now)
		endif
		return
	endif
	if npc == watchActor
		watchTalkTime = now
	endif
	NoteConvLine(npc, now, false)
	NoteSpeechToDriver(1, now, npc)
	LRG_OStim ost = GetOStim()
	if ost
		ost.NotePartnerSpeech(npc, false, now)
	endif
EndEvent

Event OnChimTextReceived(string asNpcName, string asText)
	{An NPC line arrived: the conversation is alive, keep that NPC's snapshot fresh
	 (throttled inside MaybeSnapshot: 10 s per NPC).}
	if asNpcName == "" || !IsEnabled()
		return
	endif
	Actor npc = watchActor
	if npc == None || npc.GetDisplayName() != asNpcName
		npc = AIAgentFunctions.getAgentByName(asNpcName)
	endif
	if npc == None || npc == Game.GetPlayer()
		return
	endif
	float now = Utility.GetCurrentRealTime()
	; v0.5: her WORDS are ready. Everything after this point is TTS and playback - the half of the
	; wait CHIM's own [PERF] numbers stop short of.
	; the FIRST text of this reply is the mark; CHIM raises this event again for later sentences and
	; moving the mark forward would hide exactly the TTS gap this measurement exists to show
	; [0.5.2] marked BEFORE the snapshot, for the reason spelled out in OnChimSpeechStarted.
	if !latSent && latTextAt <= 0.0
		latTextAt = now
		latTextNpc = asNpcName
	endif
	NoteSpeechToDriver(1, now, npc)
	LRG_OStim ost = GetOStim()
	if ost
		ost.NoteActivity(npc)
	endif
	MaybeSnapshot(npc, false)
	Watch(npc, true)
	NoteConvLine(npc, now, false)
EndEvent

Function NoteSpeechToDriver(int aiWho, float afNow, Actor akWho)
	;/v0.5 X1 (design 2.6 / plan 6.2): the driver stamps the moment and watches its own next polls;
	 one cheap test per speech event and nothing at all while no session is open. [v1.0 / S4.6] The
	 same stamps drive the auto-advance: 0 = the player (his voiced line or his talk key - the cancel),
	 1 = an NPC's CHIM line started, stopped or its text arrived, 2 = an NPC's CHIM VOICE started (the
	 re-armed anchor, model F12), 3 = the player's voiced line ENDED (the X1 probe only). akWho lets the
	 driver count only the session speaker (CHIM brief P7)./;
	LRG_Dialogue live = GetDialogue()
	if live && live.IsSessionOpen()
		live.NoteSpeech(aiWho, afNow, akWho)
	endif
EndFunction

Function NoteFirstBusiness(string asNpcName)
	{v0.5 (L1), [v1.0] the glue has just OPENED this NPC's list for the player's words (S2.1: one paid
	 turn, never a second one). The reply of that turn is the first-contact reply: ev=lat marks it first=1.}
	latFirstNpc = asNpcName
	latFirstAt = Utility.GetCurrentRealTime()
EndFunction

Function LatStarted(Actor akNpc, float afNow)
	;/v0.5 ev=lat, THE ONLY SEND. Called when her voice starts. It answers the owner's own question -
	 "the timeliness of the response from the npc's voice in game" - by measuring past the point
	 every other number stops: reply = his last word to her words, voice = her words to her VOICE.
	 One message per reply (latSent), never LLM-bearing, and nothing at all while the toggle is off./;
	if latSent || latTextAt <= 0.0 || latAskStop <= 0.0
		return
	endif
	latSent = true
	if afNow < latTextAt || (afNow - latTextAt) > 60.0
		return ; a stale pairing (her words never reached a voice, and this is some other line)
	endif
	if afNow < latAskStop || (afNow - latAskStop) > 180.0
		return
	endif
	if !SettingBool("bLatencyLog:Diagnostics", true) || !SettingBool("bDlgWire:Dialogue", true)
		return
	endif
	string who = akNpc.GetDisplayName()
	if latTextNpc != "" && latTextNpc != who
		return ; her words and this voice belong to two different people
	endif
	int ask = 0
	if latAskStart > 0.0 && latAskStop > latAskStart
		ask = ((latAskStop - latAskStart) * 1000.0) as int
	endif
	int reply = ((latTextAt - latAskStop) * 1000.0) as int
	int voice = ((afNow - latTextAt) * 1000.0) as int
	int total = ((afNow - latAskStop) * 1000.0) as int
	int first = 0
	if latFirstNpc == who && latFirstAt > 0.0 && afNow >= latFirstAt && (afNow - latFirstAt) < 120.0
		first = 1
		latFirstNpc = ""
	endif
	LRG_Dialogue dlg = GetDialogue()
	if dlg
		dlg.SendLat(akNpc, who, ask, reply, voice, total, first)
	endif
	LogV("", "LAT npc=" + who + " ask=" + ask + " reply=" + reply + " voice=" + voice \
		+ " total=" + total + " first=" + first, who)
	int warn = SettingInt("iLatencyWarnMs:Diagnostics", 9000)
	if warn < 2000
		warn = 9000 ; a missing MCM key reads as 0 and 0 would warn on every single reply
	endif
	if total > warn
		LogC("", "LAT SLOW npc=" + who + " total=" + total + " over=" + warn, who)
		Debug.Notification("LoreRim Glue: that reply took " + (total / 1000) + " seconds")
	endif
EndFunction

Function Watch(Actor akNpc, bool abTalking)
	{Only an NPC that really got a snapshot (= a CHIM agent in range) is watched.}
	if akNpc == None || akNpc != lastSnapActor || QuietOn() ; [pt19] no watch while quiet
		return
	endif
	float now = Utility.GetCurrentRealTime()
	if abTalking
		watchTalkTime = now
	endif
	if watchActor != akNpc
		; a new watch target gets at least 20 s before the first initiative tick, and the
		; interval since the last tick (whoever it was for) still holds
		float earliest = now - InitiativeInterval() + 20.0
		if initAt < earliest || initAt > now
			initAt = earliest
		endif
	endif
	watchActor = akNpc
	RequestTick(20.0)
EndFunction

Function SnapTick()
	;/Refresh loop: every ~20 s while the player still looks at the same agent, or exchanged
	 words with it during the last 90 s and it is still close. With NPC initiative on, the watch
	 lingers (without snapshots) up to 5 minutes after the last line while the player stays within
	 600 units, so she can still make her move. Ends by itself otherwise./;
	if watchActor == None
		return
	endif
	float now = Utility.GetCurrentRealTime()
	; woken early by LRG_OStim's faster ticks (0.8 s state pushes, 5 s scene duties): nothing of
	; ours is due, so not a single native call is spent here
	if now >= snapTickAt && now < (snapTickDue - 0.3)
		RequestTick(snapTickDue - now)
		return
	endif
	snapTickAt = now
	snapTickDue = now + 20.0
	Actor player = Game.GetPlayer()
	bool keep = IsEnabled() && !QuietOn() && !watchActor.IsDead() && watchActor.Is3DLoaded() ; [pt19] quiet mode drops the watch
	float dist = 0.0
	if keep
		dist = watchActor.GetDistance(player)
		keep = dist <= 1500.0
	endif
	bool initiativeOn = false
	bool fresh = false
	if keep
		initiativeOn = SettingBool("bInitiative:Initiative", true)
		float sinceTalk = now - watchTalkTime
		fresh = (Game.GetCurrentCrosshairRef() == watchActor) || (sinceTalk < 90.0 && sinceTalk >= 0.0)
		; an invitation the server remembers stands for invitation.ttl_seconds (1800 by default):
		; the watch must outlive it, or "walk to her house and she makes her move" cannot happen
		; without the player speaking first. A tick with nothing pending is dropped on the server
		; before the MAIN lock, at no LLM cost.
		keep = fresh || (initiativeOn && dist <= 600.0 && sinceTalk < 1800.0 && sinceTalk >= 0.0)
	endif
	if !keep
		watchActor = None
		LRG_OStim ost = GetOStim()
		if ost
			ost.OnWatchEnded() ; somebody the glue undressed outside a scene gets dressed again
		endif
		return
	endif
	float due = 20.0
	if fresh
		due = 20.0 - (now - lastSnapTime)
		if due <= 0.5 || now < lastSnapTime
			MaybeSnapshot(watchActor, false)
			due = 20.0
		endif
	endif
	if initiativeOn
		float interval = InitiativeInterval()
		MaybeInitiative(now, interval, dist)
		float initDue = interval - (now - initAt)
		if initDue < 10.0
			initDue = 10.0 ; due but not possible right now (talking, menu, too far): look again soon
		endif
		if initDue < due
			due = initDue
		endif
	endif
	snapTickDue = now + due
	RequestTick(due)
EndFunction

; ---------------------------------------------------------------------------
; NPC initiative (PROTOCOL 1.5): "the NPC may make a move of her own". The game only offers
; the moment; the server admits or drops the tick before CHIM's request lock, so an NPC who is
; not interested costs no LLM call. Cheapest checks first.
; ---------------------------------------------------------------------------
Function MaybeInitiative(float afNow, float afInterval, float afDist)
	if watchActor == None || QuietOn() ; [pt19] no move of her own inside a scripted intro
		return
	endif
	if (afNow - initAt) < afInterval && afNow >= initAt
		return
	endif
	if ((afNow - watchTalkTime) < 20.0 && afNow >= watchTalkTime) || ((afNow - playerTalkTime) < 20.0 && afNow >= playerTalkTime)
		return ; somebody spoke a moment ago
	endif
	if afDist > 600.0 || !IsIntimacyEnabled()
		return
	endif
	LRG_OStim ost = GetOStim()
	if ost
		if ost.IsSceneActiveOrStarting()
			return
		endif
		; v0.3.1 fix pass: the scene is over during the outro hold, so the line above is false and an
		; initiative tick would have her proposition the player in the middle of her own goodbye.
		if ost.IsOutroHolding()
			return
		endif
	endif
	Actor player = Game.GetPlayer()
	if watchActor.IsInCombat() || player.IsInCombat() || player.IsOnMount()
		return
	endif
	if Utility.IsInMenuMode() || LRG_DlgUI.TextInputOn() || LRG_DlgUI.OtherMenuOpen("Dialogue Menu")
		return
	endif
	string name = watchActor.GetDisplayName()
	if AIAgentFunctions.isActorTalking(name) != 0
		return
	endif
	; the server decides on a snapshot that is seconds old, never on a stale one
	; (the 20 s refresh of this very tick counts: no second witness scan right behind it)
	bool justSent = (lastSnapActor == watchActor) && (afNow - lastSnapTime) < 5.0 && afNow >= lastSnapTime
	if !justSent
		if !MaybeSnapshot(watchActor, true)
			return
		endif
	endif
	initAt = afNow
	LogV("", "initiative tick", name)
	AIAgentFunctions.requestMessageForActor("approach", MSG_INITIATIVE, name)
EndFunction

; ---------------------------------------------------------------------------
; v0.4.1: THE CONVERSATION HOLD (owner addendum 8, research/pt8-walkaway.md section 5)
; The complaint: "the AI can be quick to walk away from you". CHIM has no hold for talking to the
; player at all - FakeDialogue sets a head look-at and nothing else, its one "stay near" feature
; returns immediately unless the player is SITTING, and the LLM is offered EndConversation, which
; calls ResetPackages + EvaluatePackage and hands her straight back to AI Overhaul / Jobs Overhaul.
; What this does is items 1-3 of what the engine's own dialogue menu does, re-implemented: she stops
; walking, she faces the player, and her own package does not get to resume until the conversation
; is over.
; MECHANISM: SetDontMove, exactly the outro hold of LRG_OStim (three clean releases in playtest 8).
; It is ONE boolean with an exact inverse, it touches no AI package, and she can still turn, talk,
; gesture and play idles while it is on. The package layer below is opt-in and OFF by default: an
; AddPackageOverride IS written into the save, which is the classic "NPC frozen forever" bug.
; Facing is CHIM's: it calls SetLookAt on every sentence and clears it ~90 s after the last line, so
; the look-at is set ONCE here and NEVER cleared.
; The one defect this must not have is a frozen NPC. ReleaseConvHold is the single exit, it is
; idempotent, and it is called by: the window expiring, distance, the player sneaking off, combat on
; either side, the kill switch / the feature off / a dry run, a game load, an OStim scene or the
; outro hold, a Dialogue Menu session, her death / unload / another cell, an arrest, a quest taking
; her over, the stop hotkey, a CHIM movement command, and a self-heal at 3 x window + 30 s.
; ---------------------------------------------------------------------------
float Function ConvWindow()
	{How long she stays after the last line, 0..180 s. 0 = the hold is off. A MISSING MCM key reads
	 as 0/FALSE, i.e. off - which is why settings.ini ships a line for every key of this page.}
	if !SettingBool("bConvHold:Conversation", true)
		return 0.0
	endif
	float v = SettingFloat("fConvHoldWindow:Conversation", 45.0)
	if v <= 0.0
		return 0.0
	elseif v > 180.0
		return 180.0
	endif
	return v
EndFunction

float Function ConvFarUnits()
	{Let her go at this distance. Clamped like fOutroFar, including the missing-key guard.}
	float v = SettingFloat("fConvHoldFar:Conversation", 900.0)
	if v < 300.0
		v = 900.0 ; a missing MCM key reads as 0 and would release her before she said a word
	elseif v > 4000.0
		v = 4000.0
	endif
	return v
EndFunction

bool Function IsConvHeldActor(Actor akNpc)
	{True when THIS NPC is the one being held. Read by the snapshot (hold=).}
	return convActive && convHeld && akNpc != None && akNpc == convActor
EndFunction

string Function ConvSecs(float afSeconds)
	{One decimal, for the log ("held=12.8").}
	if afSeconds < 0.0
		afSeconds = 0.0
	endif
	int tenths = (afSeconds * 10.0) as int
	return (tenths / 10) + "." + (tenths % 10)
EndFunction

Function NoteConvLine(Actor akNpc, float afNow, bool abWeak)
	;/One line of the live conversation, from either side. It refreshes the window; the FIRST line
	 from an NPC begins the hold. abWeak = the player merely looked at her, which may only extend a
	 hold that already exists./;
	if abWeak && !convActive
		return ; the crosshair event fires constantly: one bool and out
	endif
	float window = ConvWindow()
	if window <= 0.0
		return
	endif
	if akNpc == None || akNpc == Game.GetPlayer()
		; the player's own line refreshes an EXISTING hold; it never starts one
		if convActive
			convUntil = afNow + window
			convLastLine = afNow
			RequestTick(1.0)
		endif
		return
	endif
	if convActive && akNpc == convActor
		convUntil = afNow + window
		convLastLine = afNow
		; CHIM raises a speech event once per SENTENCE, so the refresh line is throttled
		if !abWeak && (afNow - convLogAt) >= 10.0
			convLogAt = afNow
			LogV(convCid, "conv hold " + convName + " refresh reason=a line held=" + ConvSecs(afNow - convStart), convName)
		endif
		RequestTick(1.0)
		return
	endif
	if abWeak
		return ; looking at somebody else is not talking to them
	endif
	; a live hold is handed over INSIDE BeginConvHold, and only once this NPC has passed every
	; refusal. Releasing here would free the person the player is talking to whenever any other CHIM
	; voice speaks - the Narrator, a bored-event NPC two tables away, a bard mid-song - and nothing
	; would take the hold, which is the walk-away this whole feature exists to stop.
	BeginConvHold(akNpc, afNow)
EndFunction

Function BeginConvHold(Actor akNpc, float afNow)
	{Begins the hold. Every reason not to is checked here, once, on entry.}
	float window = ConvWindow()
	if window <= 0.0 || !IsEnabled() || IsDryRun()
		return
	endif
	if akNpc == None || akNpc == Game.GetPlayer()
		return
	endif
	if akNpc != lastSnapActor
		return ; not a CHIM agent in range - the same test Watch() uses, and it costs nothing
	endif
	string why = ConvRefuseReason(akNpc)
	if why != ""
		ConvLogSkip(akNpc, why, afNow)
		return
	endif
	; the handover, and it lives HERE so that a hold is only ever given up for somebody who really
	; takes it. No refusal reason looks at the hold's own state, so the old hold is still live while
	; they are checked; a refused speaker leaves it exactly as it was and her own window still ends it
	; (another NPC's line never refreshes it).
	if convActive
		ReleaseConvHold("somebody else is talking")
	endif
	convActor = akNpc
	convName = akNpc.GetDisplayName()
	convCid = NextCid()
	convActive = true
	convHeld = true
	convStart = afNow
	convLastLine = afNow
	convLogAt = afNow
	convUntil = afNow + window
	convReassert = 0.0
	akNpc.SetDontMove(true)
	akNpc.SetLookAt(Game.GetPlayer(), false) ; never cleared here: CHIM owns the look-at
	ConvApplyPackage(akNpc)
	LogV(convCid, "conv hold " + convName + " on reason=a line window=" + (window as int), convName)
	; and the server is told AT ONCE, because hold= is what stops it offering the LLM
	; EndConversation. The snapshot that arrived a moment before this hold began still says hold=0,
	; and the next ordinary refresh is up to 20 s away - a whole turn or two of the conversation the
	; hold is meant to protect. [0.5.6] abNoWait = true: this can never reach the one Utility.Wait
	; inside MaybeSnapshot (no wait ever runs inside a CHIM speech event) - with another attempt in
	; flight it simply skips, and that attempt carries hold=1 anyway if it has not reached stage 6.
	MaybeSnapshot(akNpc, true, true)
	RequestTick(1.0)
EndFunction

string Function ConvRefuseReason(Actor akNpc)
	;/Every reason this NPC is never held. "" = she may be held.
	 Order: the three "somebody else already owns her" states first, because during a scene or a
	 dialogue session CHIM raises a speech event per SENTENCE and every one of them lands here./;
	; The three states below are NORMAL and frequent (a whole scene is one of them), so they are the
	; ones ConvLogSkip stays quiet about - see its list.
	if QuietOn()
		return "a scripted intro" ; [pt19] quiet mode: the game's own intro runs - nobody is held
	endif
	LRG_OStim ost = GetOStim()
	if ost && (ost.IsOutroHolding() || ost.IsSceneActiveOrStarting())
		return "a scene of ours" ; OStim owns her: it has its own hold and its own release
	endif
	LRG_Dialogue dlg = GetDialogue()
	if dlg && dlg.IsSessionOpen()
		return "a conversation menu"
	endif
	if Utility.IsInMenuMode() || LRG_DlgUI.OtherMenuOpen("Dialogue Menu")
		return "a menu" ; the engine's own dialogue state already holds her, and better
	endif
	Actor player = Game.GetPlayer()
	if akNpc.IsDead() || akNpc.IsDisabled() || !akNpc.Is3DLoaded() || akNpc.IsUnconscious()
		return "she is not there"
	endif
	if akNpc.IsChild() || !akNpc.HasKeywordString("ActorTypeNPC")
		return "a child"
	endif
	;/v0.5.1 (pt9 section 5): the bare IsPlayerTeammate() was the RIGHT IDEA WITH THE WRONG FLAG. It
	 protected a real SFF / Inigo / Lucien follower, and missed the two kinds of follower CHIM makes
	 itself - a package follow (CHIM_FollowPlayerActive) and a MakeFollower ghost (the vanilla
	 follower faction with no teammate flag). Both of those walk with the player too, and freezing
	 one while a priority-100 follow package runs underneath is exactly the "she fidgets" report.
	 bFollowerHoldSkip is the way back to the 0.5.0 behaviour; a MISSING ini key reads as FALSE, and
	 FALSE here means "only a real teammate is skipped", i.e. no worse than 0.5.0./;
	if akNpc.IsPlayerTeammate()
		return "she follows you already" ; harmless but pointless, and freezing a follower reads as a bug
	endif
	if SettingBool("bFollowerHoldSkip:Followers", true) && LRG_Followers.IsFollowerLike(akNpc)
		return "she walks with you already"
	endif
	if akNpc.IsInCombat() || player.IsInCombat() || player.IsDead()
		return "combat"
	endif
	if akNpc.IsHostileToActor(player) || akNpc.GetCombatState() != 0
		return "hostile"
	endif
	if akNpc.IsBleedingOut() || akNpc.IsInKillMove() || akNpc.IsOnMount() || akNpc.IsSwimming() || akNpc.IsFlying()
		return "she is busy"
	endif
	if akNpc.GetSitState() != 0 || akNpc.GetSleepState() != 0
		return "she is seated or asleep" ; she cannot walk off anyway, and freezing risks a stuck get-up
	endif
	if akNpc.GetCurrentScene() != None || player.GetCurrentScene() != None
		return "a scene" ; a bard mid-song, a scripted argument: never touch one
	endif
	if ConvIsArrest(akNpc, player)
		return "an arrest"
	endif
	if ConvQuestPackage(akNpc)
		return "a quest package"
	endif
	if akNpc.GetDistance(player) > ConvFarUnits()
		return "too far away"
	endif
	return ""
EndFunction

bool Function ConvIsArrest(Actor akNpc, Actor akPlayer)
	{A guard with business with a wanted player, or an arrest already under way.}
	if akNpc.IsArrested() || akPlayer.IsArrested()
		return true
	endif
	if !akNpc.IsGuard()
		return false
	endif
	Faction cf = akNpc.GetCrimeFaction()
	if cf == None
		return false
	endif
	return cf.GetCrimeGold() > 0 || cf.GetCrimeGoldViolent() > 0
EndFunction

bool Function ConvQuestPackage(Actor akNpc)
	{A package a quest owns is scripted movement by definition: the glue never freezes that.}
	Package p = akNpc.GetCurrentPackage()
	if p == None
		return false
	endif
	return p.GetOwningQuest() != None
EndFunction

Package Function ConvDoNothingPackage()
	;/AIAgent.esp 0x027374 = AIAgentDoNothing (PF_AIAgentDoNothing_02027374.psc). Looked up once per
	 session and kept: None simply means the opt-in package layer is not available./;
	if convPkgTried
		return convPkg
	endif
	convPkgTried = true
	convPkg = Game.GetFormFromFile(0x00027374, "AIAgent.esp") as Package
	return convPkg
EndFunction

Function ConvApplyPackage(Actor akNpc)
	;/OPT-IN second layer (bConvHoldPackage, default OFF). SetDontMove stops the legs but her package
	 keeps "running" underneath, which can show as turning on the spot against a high-priority AI
	 Overhaul / Jobs Overhaul package. Priority 60 beats those without touching CHIM's own overrides,
	 and CHIM's ResetPackages removes a doNothing form by name - so even an override this script
	 somehow failed to remove is cleaned up by CHIM's next EndConversation./;
	if !SettingBool("bConvHoldPackage:Conversation", false)
		return
	endif
	Package p = ConvDoNothingPackage()
	if p == None
		return
	endif
	ActorUtil.AddPackageOverride(akNpc, p, 60, 0)
	akNpc.EvaluatePackage()
	convPkgOn = true
EndFunction

Function ReleaseConvHold(string asWhy)
	{The ONLY way out. Safe at any time, from any path, twice in a row, and on a hold that only the
	 save remembers. Every field is cleared BEFORE the actor is touched.}
	if !convActive && convActor == None && !convHeld && !convPkgOn
		return
	endif
	Actor who = convActor
	string nm = convName
	string cid = convCid
	bool wasHeld = convHeld
	bool wasPkg = convPkgOn
	float now = Utility.GetCurrentRealTime()
	float held = 0.0
	if convStart > 0.0 && now >= convStart
		held = now - convStart
	endif
	convActive = false
	convHeld = false
	convPkgOn = false
	convActor = None
	convName = ""
	convCid = ""
	convStart = 0.0
	convUntil = 0.0
	convLastLine = 0.0
	convLogAt = 0.0
	convReassert = 0.0
	if who != None
		if wasPkg
			Package p = ConvDoNothingPackage()
			if p != None
				ActorUtil.RemovePackageOverride(who, p)
			endif
		endif
		if wasHeld
			who.SetDontMove(false)
		endif
		if wasHeld || wasPkg
			who.EvaluatePackage() ; her own package picks up cleanly instead of on the next AI poll
		endif
	endif
	if nm != ""
		LogV(cid, "conv hold " + nm + " release reason=" + asWhy + " held=" + ConvSecs(held), nm)
	endif
EndFunction

Function NoteChimAction(string asNpcName, string asCommand)
	;/A CHIM action addressed to the NPC being held. EndConversation KEEPS the hold for the rest of
	 the window (nothing here fights CHIM's ResetPackages - SetDontMove survives it, and it is simply
	 said again on the next tick); after the window CHIM's own behaviour applies. Anything that moves
	 or re-tasks her releases instead of stalling her against it.
	 NOTE, CHIM 3.3.2: this is DORMANT for CHIM's own catalog actions. The DLL raises
	 CHIM_CommandReceived only through AIAgentAIMind.SendExternalEvent, which it calls on the ExtCmd
	 branch alone, and it calls AIAgentAIMind.EndConversation directly - so no core action ever
	 reaches any event this script can register for. The working half is server-side
	 (dialogue.hold_hides_end_conversation): the LLM is not offered EndConversation while the hold is
	 live. This stays because it costs one string compare per command and is correct the day CHIM
	 routes its own actions here./;
	if asCommand == "" || asNpcName == ""
		return
	endif
	;/v0.5.1 (pt9 G3): MakeFollower is handled FIRST and has nothing to do with the hold. CHIM's
	 MakeFollower half-recruits her, so the repair is armed for the actor it names - with a settle
	 delay, because CHIM's own faction writes happen after the command is dispatched. Dormant for
	 the same reason the rest of this function is (CHIM raises no event for its own catalog actions),
	 which is why the snapshot path and the load sweep are the paths that really run today./;
	if asCommand == "MakeFollower" && FollowerAware()
		Actor made = AIAgentFunctions.getAgentByName(asNpcName)
		if made
			Log("CHIM sent MakeFollower to " + asNpcName + " - checking for a half-recruited follower")
			ArmFollowerRepair(made, 3.0)
		endif
	endif
	if !convActive || convActor == None
		return
	endif
	if asNpcName != convName && AIAgentFunctions.getAgentByName(asNpcName) != convActor
		return
	endif
	float now = Utility.GetCurrentRealTime()
	if asCommand == "EndConversation"
		convReassert = now + 0.5
		LogV(convCid, "conv hold " + convName + " keep reason=CHIM ended the conversation held=" + ConvSecs(now - convStart), convName)
		RequestTick(0.5)
		return
	endif
	if StringUtil.Find(CONV_MOVE_ACTIONS, "|" + asCommand + "|") >= 0
		ReleaseConvHold("CHIM sent " + asCommand)
	endif
EndFunction

Function ConvLogSkip(Actor akNpc, string asWhy, float afNow)
	;/One refusal line per NPC per 30 s: CHIM raises a speech event once per SENTENCE. The three
	 "somebody else owns her" states say nothing at all - a whole OStim scene is one of them and it
	 would put a refusal in the log every half minute for something that is simply normal. The
	 refusals the owner wants to see (a bard mid-song, a guard mid-arrest, a quest errand) all speak./;
	if asWhy == "a scene of ours" || asWhy == "a menu" || asWhy == "a conversation menu"
		return
	endif
	if akNpc == convSkipActor && (afNow - convSkipAt) < 30.0 && afNow >= convSkipAt
		return
	endif
	convSkipActor = akNpc
	convSkipAt = afNow
	string nm = akNpc.GetDisplayName()
	LogV("", "conv hold " + nm + " skip reason=" + asWhy, nm)
EndFunction

Function TickConvHold(float afNow)
	{Runs first in every OnUpdate while a hold exists, and it is the self-heal: a hold that outlived
	 its own window, or one only the save remembers, ends here.}
	if !convActive
		if convActor != None || convHeld || convPkgOn
			ReleaseConvHold("a hold was left over")
		endif
		return
	endif
	if convActor == None
		ReleaseConvHold("the NPC is gone")
		return
	endif
	float window = ConvWindow()
	if window <= 0.0 || !IsEnabled() || IsDryRun()
		ReleaseConvHold("the feature was switched off")
		return
	endif
	; the real-time clock restarts at 0 on every game launch and a dumped stack can strand a hold:
	; whatever happens, it never lives longer than three windows plus half a minute
	if afNow < convStart || (afNow - convStart) > (3.0 * window + 30.0)
		ReleaseConvHold("self-heal")
		return
	endif
	LRG_OStim ost = GetOStim()
	if ost && (ost.IsOutroHolding() || ost.IsSceneActiveOrStarting())
		ReleaseConvHold("a scene")
		return
	endif
	LRG_Dialogue dlg = GetDialogue()
	if LRG_DlgUI.OtherMenuOpen("Dialogue Menu") || (dlg && dlg.IsSessionOpen())
		ReleaseConvHold("a conversation menu")
		return
	endif
	if convActor.IsDead() || convActor.IsDisabled() || !convActor.Is3DLoaded() || convActor.IsUnconscious()
		ReleaseConvHold("she is gone")
		return
	endif
	Actor player = Game.GetPlayer()
	if convActor.IsInCombat() || player.IsInCombat() || player.IsDead()
		ReleaseConvHold("combat")
		return
	endif
	if convActor.GetCurrentScene() != None
		ReleaseConvHold("a scene started")
		return
	endif
	if convActor.IsInKillMove() || convActor.IsBleedingOut()
		ReleaseConvHold("she is fighting")
		return
	endif
	if ConvIsArrest(convActor, player)
		ReleaseConvHold("an arrest")
		return
	endif
	if ConvQuestPackage(convActor)
		ReleaseConvHold("a quest needs her")
		return
	endif
	float far = ConvFarUnits()
	float dist = convActor.GetDistance(player)
	if dist > far
		ReleaseConvHold("you walked away")
		return
	endif
	if player.IsSneaking() && dist > (far * 0.5)
		ReleaseConvHold("you are sneaking away")
		return
	endif
	; cells are only compared where they mean something: two actors standing next to each other
	; outdoors are regularly in different exterior cells
	if player.IsInInterior() || convActor.IsInInterior()
		if convActor.GetParentCell() != player.GetParentCell()
			ReleaseConvHold("another cell")
			return
		endif
	endif
	if convReassert > 0.0 && afNow >= convReassert
		convReassert = 0.0
		convActor.SetDontMove(true) ; CHIM reset her packages: say it again, and nothing else
		convActor.SetLookAt(player, false)
	endif
	if afNow >= convUntil
		; the window may never cut across her own sentence. CHIM raises SpeechStarted / SpeechStopped
		; once per SENTENCE and isActorTalking reads 0 in the gap between two of them, so a line that
		; has just been heard counts as still speaking. The self-heal above still bounds the whole
		; hold, so this can never freeze her.
		bool talking = AIAgentFunctions.isActorTalking(convName) != 0
		if !talking && convLastLine > 0.0 && (afNow - convLastLine) < 4.0 && afNow >= convLastLine
			talking = true
		endif
		if talking
			convUntil = afNow + 5.0
			RequestTick(0.5)
			return
		endif
		ReleaseConvHold("nobody spoke")
		return
	endif
	RequestTick(1.0)
EndFunction

; ---------------------------------------------------------------------------
; Place facts for the snapshot (v=2 keys x, loc, ltype, cellown, home, nhome, prent, nearf,
; bedown, sde - in that order, inserted before class).
; ---------------------------------------------------------------------------
string Function PlaceFacts(Actor akNpc, Actor akPlayer)
	float now = Utility.GetCurrentRealTime()
	Location loc = akPlayer.GetCurrentLocation()
	Cell c = akPlayer.GetParentCell()

	; per location: name + type (up to 13 keyword tests, once per location change)
	if !lcValid || loc != lcLoc
		lcLoc = loc
		lcValid = true
		lcType = "wild"
		string locName = ""
		if loc
			locName = LRG_Profile.Clip(CleanForWire(loc.GetName()), 40)
			lcType = LRG_Profile.LocationType(loc)
		endif
		lcFacts = "loc=" + locName + ";ltype=" + lcType
	endif

	; per NPC + cell (+ location): ownership, home, Serana state. Re-read after 120 s.
	if akNpc != pcNpc || c != pcCell || loc != pcLoc || (now - pcTime) > 120.0 || now < pcTime
		pcNpc = akNpc
		pcCell = c
		pcLoc = loc
		pcTime = now
		pcCellOwn = "none"
		if c
			pcCellOwn = LRG_Profile.OwnerWord(c.GetActorOwner(), c.GetFactionOwner(), akNpc, akPlayer)
		endif
		int isHome = 0
		string homeName = ""
		Location ed = akNpc.GetEditorLocation()
		if ed
			homeName = LRG_Profile.Clip(CleanForWire(ed.GetName()), 40)
			if loc && ed.IsSameLocation(loc)
				isHome = 1
			endif
		endif
		pcFacts = "cellown=" + pcCellOwn + ";home=" + isHome + ";nhome=" + homeName
		pcSde = "sde=" + LRG_Profile.SdeState(akNpc)
	endif

	; OStim-dependent facts live in LRG_OStim (cached there): rented bed (inns only), furniture
	int prent = 0
	string furn = "nearf=;bedown="
	LRG_OStim ost = GetOStim()
	if ost
		if lcType == "inn"
			prent = ost.RentedBedValue(c)
		endif
		furn = ost.FurnitureFacts(akNpc, akPlayer, pcCellOwn)
	endif
	; sess= goes LAST of the place facts, i.e. straight before class (fac stays last of all):
	; the server compares it against the session tag stamped on an open scene row and closes a row
	; that belongs to the session before this one (v0.3, additive - an old server ignores it).
	return "x=" + SettingInt("iExplicitness:SceneTalk", 2) + ";" + lcFacts + ";" + pcFacts + ";prent=" + prent + ";" + furn + ";" + pcSde + ";sess=" + sessionTag
EndFunction

int Function SessionTag()
	{The tag of this game session (1000..9999, re-rolled on every load). LRG_OStim stamps it on
	 every scene message so the server can tell one session's rows from the next.}
	return sessionTag
EndFunction

; ---------------------------------------------------------------------------
; v0.5.1 THE FOLLOWER REPAIR (owner addendum 10, research/pt9-followers.md G3)
; ---------------------------------------------------------------------------
bool Function FollowerAware()
	;/The master switch for everything this block does. A MISSING ini key reads as FALSE, i.e. off.
	 [0.5.2] SnapFolOk() joins it: once the follower block has been caught unwinding a snapshot it
	 stays out of every path for the rest of the session, not just out of the payload./;
	return IsEnabled() && SnapFolOk() && SettingBool("bFollowerAware:Followers", true)
EndFunction

Function NoteFollower(Actor akNpc)
	;/Called from the snapshot path, i.e. only for an NPC CHIM really drives and at most every ten
	 seconds per NPC. Two native calls in the common case (she is not in the follower faction).
	 [0.5.1 fix pass] the throttle is a FOUR-entry ring, not one slot - with one slot, alternating between two
	 NPCs meant every snapshot of either paid for a full IsGhost() and neither was ever throttled./;
	if QuietOn()
		return ; [pt19] quiet mode: nothing about followers is looked at or repaired (the tick's path has its own gate)
	endif
	NoteGlueFollow(akNpc) ; [pt16] before the throttle and before FollowerAware(): its own gate, two StorageUtil reads
	if akNpc == None || !FollowerAware()
		return
	endif
	if folSeenQ.Length != 4
		folSeenQ = new Actor[4] ; a save from before this fix pass has none
		folSeenT = new float[4]
		folSeenSlot = 0
	endif
	float now = Utility.GetCurrentRealTime()
	bool known = false
	int i = 0
	while i < 4
		if folSeenQ[i] == akNpc
			if (now - folSeenT[i]) < 20.0 && now >= folSeenT[i]
				return ; looked at her a moment ago
			endif
			folSeenT[i] = now ; refreshed in place, so one actor never takes two slots
			known = true
			i = 4
		else
			i += 1
		endif
	endwhile
	if !known
		folSeenQ[folSeenSlot] = akNpc
		folSeenT[folSeenSlot] = now
		folSeenSlot = (folSeenSlot + 1) % 4
	endif
	;/[0.5.6] ExtCmdLRG_Escort's edits give way to a real recruit. Once she is the player's teammate her
	 framework owns follow and wait: a WaitingForPlayer the glue wrote would park her the moment she
	 joined, and a CHIM follow the glue put on would outrank the framework's own package. One
	 StorageUtil read per look (at most every 20 s per NPC) while neither was ever written./;
	if StorageUtil.GetIntValue(akNpc, "LRG_EscortWait", 0) == 1 || StorageUtil.GetIntValue(akNpc, "LRG_EscortApplied", 0) == 1
		if akNpc.IsPlayerTeammate()
			if StorageUtil.GetIntValue(akNpc, "LRG_EscortApplied", 0) == 1
				EscortDropFollow(akNpc)
			endif
			EscortUnwait(akNpc)
			if SffFollowOn()
				LRG_Followers.ChimFollowVeto(akNpc) ; [pt17] a follow CHIM put on her before her real recruit
			endif
			akNpc.EvaluatePackage()
			Log("escort " + akNpc.GetDisplayName() + ": she is your follower now - the glue's follow / wait edits are taken back")
		endif
	endif
	if LRG_Followers.IsGhost(akNpc)
		ArmFollowerRepair(akNpc, 0.5)
	endif
EndFunction

Function ArmFollowerRepair(Actor akNpc, float afDelay)
	;/Queues the repair onto OnUpdate. It is never done inline: this can be reached from a CHIM speech
	 event, and the repair must not run while CHIM's own faction writes are still settling.
	 [0.5.1 fix pass] FOUR slots. With one, a second ghost silently replaced the first and was never repaired -
	 which is exactly playtest scenario P-F2 (MakeFollower a second NPC while Lydia is recruited)./;
	if akNpc == None || !FollowerAware() || !SettingBool("bFollowerRepair:Followers", true) || QuietOn() ; [pt19] no repair while quiet
		return
	endif
	if folFixQ.Length != 4
		folFixQ = new Actor[4]
		folFixT = new float[4]
		folFixCount = 0
	endif
	int free = -1
	int i = 0
	while i < 4
		if folFixQ[i] == akNpc
			return ; already queued
		endif
		if folFixQ[i] == None && free < 0
			free = i
		endif
		i += 1
	endwhile
	if free < 0
		; never silently: the fifth ghost is caught by her own next snapshot, 20 s later at worst
		Log("follower repair queue full - " + akNpc.GetDisplayName() + " waits for her next snapshot")
		return
	endif
	folFixQ[free] = akNpc
	folFixT[free] = Utility.GetCurrentRealTime() + afDelay
	folFixCount += 1
	RequestTick(afDelay)
EndFunction

Function TickFollowerRepair(float afNow)
	{Runs every queued repair whose settle time has passed. Safe to call with nothing queued.}
	if folFixCount <= 0 || folFixQ.Length != 4
		folFixCount = 0
		return
	endif
	float soonest = 0.0
	int i = 0
	while i < 4
		Actor who = folFixQ[i]
		if who != None
			if folFixT[i] > afNow && (folFixT[i] - afNow) < 30.0
				float wait = folFixT[i] - afNow
				if soonest <= 0.0 || wait < soonest
					soonest = wait
				endif
			else
				folFixQ[i] = None
				folFixT[i] = 0.0
				folFixCount -= 1
				RepairFollower(who)
			endif
		endif
		i += 1
	endwhile
	if folFixCount < 0
		folFixCount = 0
	endif
	if soonest > 0.0
		RequestTick(soonest)
	endif
EndFunction

Function RepairFollower(Actor akNpc)
	;/The one place the repair is really carried out, with every reason NOT to in front of it.
	 Modes (iFollowerRepairMode): 0 promote when a slot is free else undo, 1 always undo, 2 report only./;
	if akNpc == None || !FollowerAware() || !SettingBool("bFollowerRepair:Followers", true)
		return
	endif
	if IsDryRun()
		if LRG_Followers.IsGhost(akNpc)
			Log("follower ghost " + akNpc.GetDisplayName() + " would be repaired (dry run)")
		endif
		return
	endif
	if !LRG_Followers.IsGhost(akNpc)
		return ; she fixed herself, or somebody recruited her properly in the meantime
	endif
	; never while somebody else owns her: a scene, a dialogue session, a menu, combat
	LRG_OStim ost = GetOStim()
	if ost && (ost.IsOutroHolding() || ost.IsSceneActiveOrStarting())
		return
	endif
	LRG_Dialogue dlg = GetDialogue()
	if dlg && dlg.IsSessionOpen()
		return
	endif
	if Utility.IsInMenuMode() || LRG_DlgUI.OtherMenuOpen("Dialogue Menu")
		return
	endif
	Actor player = Game.GetPlayer()
	if akNpc.IsDead() || akNpc.IsDisabled() || !akNpc.Is3DLoaded() || akNpc.IsInCombat() || player.IsInCombat()
		return
	endif
	int mode = SettingInt("iFollowerRepairMode:Followers", 0)
	if mode < 0 || mode > 2
		mode = 0
	endif
	string nm = akNpc.GetDisplayName()
	string before = LRG_Followers.FolState(akNpc)
	int did = LRG_Followers.RepairGhost(akNpc, mode)
	string what = "nothing"
	if did == 1
		what = "promoted to a real follower of the framework"
	elseif did == 2
		what = "CHIM's CurrentFollowerFaction edit undone; she walks with you through CHIM's own follow instead"
	elseif did == 3
		what = "reported only (repair mode 2)"
	endif
	if did != 0
		; loud on purpose: this is the state the owner has to be able to find in the log
		Log("FOLLOWER GHOST " + nm + " [" + before + "] mode=" + mode + " -> " + what)
		SendNpcMessage(MSG_LOG, "follower ghost repaired npc=" + CleanForWire(nm) + ";mode=" + mode \
			+ ";did=" + did + ";was=" + before, nm)
		if SettingBool("bNotifyErrors:General", true)
			Debug.Notification("LoreRim Glue: " + nm + " was half-recruited by CHIM - " + what)
		endif
	endif
EndFunction

Function FollowerSweep()
	;/ON LOAD ONLY. A ghost made in an earlier session is still a ghost, and the player may not look
	 at her again for an hour. This walks the actors the engine already has in high process - the
	 same list LRG_Profile.WitnessScan uses - and queues every ghost it finds up to the queue's four
	 slots ([0.5.1 fix pass] it used to stop at the first); the rest are caught by their own snapshots.
	 Bounded: at most 40 actors, two native calls each./;
	if !FollowerAware() || !SettingBool("bFollowerRepair:Followers", true)
		return
	endif
	if QuietOn()
		Log("follower sweep skipped - quiet mode (" + quietWhy + ")") ; [pt19] a load inside the intro
		return
	endif
	Actor[] near = PO3_SKSEFunctions.GetActorsByProcessingLevel(0)
	if !near
		return ; [0.5.6] the array's own truth test - never "== None" (research/pt13-game-fix.md 1)
	endif
	Actor player = Game.GetPlayer()
	int i = 0
	while i < near.Length && i < 40 && folFixCount < 4
		Actor a = near[i]
		i += 1
		if a && a != player && LRG_Followers.IsGhost(a)
			Log("follower sweep found a ghost on load: " + a.GetDisplayName())
			ArmFollowerRepair(a, 2.0)
		endif
	endwhile
EndFunction

; ---------------------------------------------------------------------------
; [0.5.2] The snapshot's own black box. LRG_Profile.BuildSnapshot is a Global and cannot keep
; state, so it marks its progress here. Every one of these is member writes only - no natives.
; [0.5.6] Every mark carries the token of the attempt that makes it and is IGNORED unless that
; attempt holds the rail: a second attempt beside it, or an old one coming back late, can never
; move the holder's stage or breadcrumbs again. See the block before snapWhere.
; ---------------------------------------------------------------------------
Function SnapStage(int aiStage, int aiTok = 0)
	{How far the payload got. Read only when an attempt is judged lost.}
	if aiTok != 0 && aiTok == snapRunTok
		snapWhere = aiStage
	endif
EndFunction

bool Function SnapDoorOk()
	{False once the door block has been open in SNAP_RETIRE_AFTER lost attempts in a row.}
	return !snapDoorBad
EndFunction

Function SnapDoorTry(bool abOn, int aiTok = 0)
	if aiTok == 0 || aiTok != snapRunTok
		return
	endif
	snapDoorRun = abOn
	if !abOn
		snapDoorFails = 0 ; the block went through: a run of lost attempts is broken
	endif
EndFunction

bool Function SnapFolOk()
	{False once the follower block has been open in SNAP_RETIRE_AFTER lost attempts in a row.}
	return !snapFolBad
EndFunction

Function SnapFolTry(bool abOn, int aiTok = 0)
	if aiTok == 0 || aiTok != snapRunTok
		return
	endif
	snapFolRun = abOn
	if !abOn
		snapFolFails = 0
	endif
EndFunction

string Function SnapFolCount(string asWho)
	;/[0.5.6] One lost attempt with the follower block open (a snapshot, or boot step 9). fol= and
	 witchim are retired only when this is the SNAP_RETIRE_AFTER-th in a row. Returns the words./;
	snapFolFails += 1
	if snapFolBad
		return "fol= / witchim were already off"
	endif
	if snapFolFails >= SNAP_RETIRE_AFTER
		snapFolBad = true
		return "fol= / witchim are off for this session (" + asWho + " was stuck in the follower block " + snapFolFails + " times in a row)"
	endif
	return asWho + " was in the follower block (" + snapFolFails + " of " + SNAP_RETIRE_AFTER + " in a row before fol= is switched off)"
EndFunction

string Function SnapFolUncount()
	{[0.5.6] A lost attempt came back after all: its follower-block count is taken back.}
	if snapFolFails > 0
		snapFolFails -= 1
	endif
	if snapFolBad && snapFolFails < SNAP_RETIRE_AFTER
		snapFolBad = false
		return "; fol= / witchim are back on"
	endif
	return ""
EndFunction

string Function SnapStageName(int aiStage)
	;/What was RUNNING when the attempt went silent. Each number is stamped when the step before it
	 finished, so the name here is the block that comes after the stamp.
	 [0.5.3 D5] stage 1 also covers LRG_Main.PlaceFacts() - Papyrus evaluates a call's ARGUMENTS at
	 the call site, so PlaceFacts and the four MCM reads run before BuildSnapshot is even entered.
	 [0.5.6] Each name now says which calls - and whose - make up that block./;
	if aiStage == 21
		return "the entry checks (MCM enabled, IsDead, HasKeywordString, GetDistance)"
	elseif aiStage == 22
		return "CHIM's getAgentByName"
	elseif aiStage == 23
		return "LRG_OStim.IsActorInOurScene (OStim)"
	elseif aiStage <= 1
		return "the arguments (PlaceFacts, four MCM reads) or the head of the payload (actor natives, po3 GetFormEditorID)"
	elseif aiStage == 2
		return "the door block (LRG_OStim.DoorFacts / po3 FindAllReferencesOfFormType)"
	elseif aiStage == 3
		return "the witness scan (po3 GetActorsByProcessingLevel, LRG_Followers.FacCurrentFollower)"
	elseif aiStage == 4
		return "the player facts"
	elseif aiStage == 5
		return "the MCM key block (about twenty MCM Helper reads)"
	elseif aiStage == 6
		return "distance / conversation hold"
	elseif aiStage == 7
		return "the follower block (LRG_Followers.FolState, SFF natives)"
	elseif aiStage == 8
		return "place facts / class"
	elseif aiStage == 9
		return "the faction list (GetFactions)"
	elseif aiStage == 10
		return "nothing - the payload was complete, the send had not begun"
	elseif aiStage == 12
		return "NoteFollower after the send (LRG_Followers.IsGhost)"
	endif
	return "the send itself (CHIM logMessageForActor)"
EndFunction

Function SnapJudgeLost(float afNow)
	;/[0.5.6] The attempt holding the rail has been silent for SNAP_LOST_SECS: it is stuck in a call and
	 is not coming back soon. The rail is freed FIRST (member writes only), then the verdict is said.
	 Its optional block, if one was open, gets one count toward retirement (SnapFolCount and the
	 door's twin) - never a retirement on the first suspicion./;
	int tok = snapRunTok
	int where = snapWhere
	bool door = snapDoorRun
	bool fol = snapFolRun
	float age = afNow - snapRunAt
	snapRunTok = 0
	snapWhere = 0
	snapDoorRun = false
	snapFolRun = false
	snapLostTok = tok
	snapLostDoor = door
	snapLostFol = fol
	snapLost += 1
	string what = ""
	if door
		snapDoorFails += 1
		if !snapDoorBad && snapDoorFails >= SNAP_RETIRE_AFTER
			snapDoorBad = true
			what = "door= is off for this session (the door block was stuck " + snapDoorFails + " times in a row)"
		else
			what = "the door block was open (" + snapDoorFails + " of " + SNAP_RETIRE_AFTER + " in a row before door= is switched off)"
		endif
	endif
	if fol
		if what != ""
			what += "; "
		endif
		what += SnapFolCount("this snapshot")
	endif
	if what == ""
		what = "no optional block was open - the core path; please send the Papyrus log"
	endif
	if snapLost <= 6
		LogE("", "SNAPSHOT LOST: an attempt began " + (age as int) + " s ago in " + SnapStageName(where) \
			+ " (stage " + where + ") and has not returned - " + what, "")
		if snapLost == 6
			LogE("", "SNAPSHOT: that is the sixth lost attempt - no more of these lines this session", "")
		endif
	endif
EndFunction

Function SnapRelease(int aiTok)
	;/[0.5.6] An attempt is done (sent, or returned early). It frees the rail only if it still holds
	 it. An attempt that was judged lost and comes back here takes its verdict back and says so; one
	 that a forced snapshot went ahead beside simply leaves - nothing of the rail is its own any more./;
	if aiTok == 0
		return
	endif
	if aiTok == snapRunTok
		snapRunTok = 0
		snapWhere = 0
		snapDoorRun = false
		snapFolRun = false
		return
	endif
	if aiTok != snapLostTok
		return
	endif
	snapLostTok = 0
	if snapLost > 0
		snapLost -= 1
	endif
	string back = ""
	if snapLostDoor
		if snapDoorFails > 0
			snapDoorFails -= 1
		endif
		if snapDoorBad && snapDoorFails < SNAP_RETIRE_AFTER
			snapDoorBad = false
			back += "; door= is back on"
		endif
	endif
	if snapLostFol
		back += SnapFolUncount()
	endif
	snapLostDoor = false
	snapLostFol = false
	LogE("", "SNAPSHOT LATE: the attempt judged lost has returned after all - it was slow, not dead" + back, "")
EndFunction

bool Function MaybeSnapshot(Actor akNpc, bool abForce, bool abNoWait = false)
	{Returns true when a snapshot was sent. abNoWait: a forced snapshot that must never wait (it is
	 called from inside a CHIM speech event) skips instead when another attempt is in flight.}
	;/[0.5.5] While LRG_OStim is still booting after a load GetOStim() answers None, and a snapshot
	 taken now would tell the server "no scene" about a scene that may be running. The event that
	 wanted it comes again within seconds; a FORCED snapshot never waits./;
	if !abForce && ModPending(0)
		return false
	endif
	;/[pt19] QUIET MODE: no snapshot of anybody while a curated scripted intro runs - a forced one included
	 (nothing of ours needs one in an intro; the server's adult / freshness rails then fail closed, which is
	 the point, and the log says "QUIET on", not "snapshot failed"). A member read between the 5 s looks./;
	if QuietOn()
		return false
	endif
	; Cheapest checks first: OnCrosshairRefChange fires constantly, and most calls end in
	; the throttle below after a single native call.
	float now = Utility.GetCurrentRealTime()
	if snapRunTok != 0
		;/[0.5.6] Another attempt holds the rail. It is IN FLIGHT - waiting in a native on its own
		 stack - unless it has been silent for SNAP_LOST_SECS. Finding it there is normal and is not
		 an abort (see the block before snapWhere)./;
		if now < snapRunAt || (now - snapRunAt) >= SNAP_LOST_SECS
			SnapJudgeLost(now)
		elseif !abForce || abNoWait
			snapSkips += 1
			return false
		else
			; v0.3.1 fix pass: a FORCED snapshot is never dropped. The end-of-scene snapshot is what
			; the server's freshness rail (its adults-only fail-closed rail, never softened) measures
			; the outro against. Half a second for the attempt in flight, then go ahead beside it.
			int w = 0
			while snapRunTok != 0 && w < 2
				Utility.Wait(0.25)
				w += 1
			endwhile
			now = Utility.GetCurrentRealTime()
		endif
	endif
	if !abForce
		if (now - lastAnySnapTime) < 2.0 && now >= lastAnySnapTime
			return false
		endif
		if akNpc == lastSnapActor && (now - lastSnapTime) < 10.0 && now >= lastSnapTime
			return false
		endif
		if akNpc == lastMissActor && (now - lastMissTime) < 5.0 && now >= lastMissTime
			return false
		endif
	endif
	;/[0.5.6] THE CLAIM. From this test to the token write there is no call out of this script, so two
	 attempts can never both believe they hold the rail. A forced snapshot that still finds one in
	 flight goes ahead with a token of its own; the older attempt's marks are ignored from here on./;
	if snapRunTok != 0 && (!abForce || abNoWait)
		snapSkips += 1
		return false
	endif
	snapTok += 1
	if snapTok <= 0
		snapTok = 1 ; (wrap-around after two billion snapshots - 0 must stay "nobody")
	endif
	int my = snapTok
	snapRunTok = my
	snapRunAt = now
	snapWhere = 21
	snapDoorRun = false
	snapFolRun = false
	Actor player = Game.GetPlayer()
	if !IsEnabled() || akNpc == player || akNpc.IsDead() || !akNpc.HasKeywordString("ActorTypeNPC")
		SnapRelease(my)
		return false
	endif
	if !abForce && akNpc.GetDistance(player) > 1500.0
		SnapRelease(my)
		return false
	endif
	; only NPCs CHIM is actually driving
	SnapStage(22, my)
	if AIAgentFunctions.getAgentByName(akNpc.GetDisplayName()) == None
		lastMissActor = akNpc
		lastMissTime = now
		SnapRelease(my)
		return false
	endif
	lastSnapActor = akNpc
	lastSnapTime = now
	lastAnySnapTime = now

	SnapStage(23, my)
	bool inScene = false
	LRG_OStim ost = GetOStim()
	if ost
		inScene = ost.IsActorInOurScene(akNpc)
	endif
	; [0.5.2] from here to the send, every step stamps snapWhere - with this attempt's token.
	SnapStage(1, my)
	string payload = LRG_Profile.BuildSnapshot(akNpc, player, \
		SettingFloat("fWitnessRadiusInterior:Intimacy", 1200.0), \
		SettingFloat("fWitnessRadiusExterior:Intimacy", 2500.0), \
		inScene, IsIntimacyEnabled(), PlaceFacts(akNpc, player), my)
	SnapStage(11, my)
	SendNpcMessage(MSG_NPCSTATE, payload, akNpc.GetDisplayName())
	; v0.5.1: the snapshot has just told the server what she is; this is the game-side half of the
	; same fact. It costs two native calls unless she really is in the vanilla follower faction.
	;/[0.5.3 D3] NoteFollower keeps the rail instead of running outside it: stage 12 with the follower
	 breadcrumb on, because FollowerAware() - which gates every path NoteFollower reaches - is exactly
	 what SnapFolOk() switches off. Given back BEFORE the rented-bed rescan below, which can call this
	 function again./;
	SnapStage(12, my)
	SnapFolTry(true, my)
	NoteFollower(akNpc)
	SnapFolTry(false, my)
	SnapRelease(my)

	; The rented-bed scan (inns only, at most once per cell per 120 s) loops over furniture
	; references, so it runs AFTER the snapshot went out; only if its answer changed is a
	; second snapshot sent.
	if ost && lcType == "inn"
		if ost.RefreshRentedBed(player)
			MaybeSnapshot(akNpc, true, abNoWait)
		endif
	endif
	return true
EndFunction
