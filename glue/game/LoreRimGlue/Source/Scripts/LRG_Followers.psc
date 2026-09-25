Scriptname LRG_Followers Hidden
;/LoreRim Glue v0.5.1 - FOLLOWER COMPATIBILITY (owner addendum 10, research/pt9-followers.md).

 Facts only, like LRG_Profile: nothing here decides anything about romance, dialogue or packages.
 It answers one question cheaply - "what is this NPC to the player's party, and who owns her" -
 and it repairs the ONE state CHIM can create that no follower framework knows about.

 THE FRAMEWORK IN THIS LIST IS NOT NFF. Nether's Follower Framework is not installed; Simple
 Follower Framework (SFF) 1.4.1.1 is, and CHIM's AIAgentNpcUtil.MakeFollower ends in a dead
 `if (nwsFF)` branch here. What it DOES do is add the vanilla PotentialFollower / CurrentFollower
 factions and stop - no SetPlayerTeammate, no SFF alias, no slot accounting. That half-recruited
 NPC is what this file calls a GHOST, and it is the cause of the wrong-target dismiss:
 SFF's GetDialogueFollowerTarget() falls through to the primary-follower alias for an actor that is
 in no alias, so "it's time for us to part ways" said to the ghost dismisses the REAL follower.

 SFF IS OPTIONAL HERE. Every SFF call is behind SffPresent(), which is a po3 editor-id lookup of
 SFF's own global - no SFF script and no SFF native is touched when the mod is not installed, so a
 load order without it degrades to fw=none and changes nothing.

 NEVER SHIP DialogueFollowerScript.pex. tools/stubs/DialogueFollowerScript.psc is a COMPILE-ONLY
 header; deploying a compiled copy would overwrite SFF's own script and break every follower in the
 save. tools/compile.ps1 compiles only game/LoreRimGlue/Source/Scripts and refuses the build if a
 stub .pex ever appears./;

; ---------------------------------------------------------------------------
; Forms. Looked up per call on purpose: these are natives, and the only caller that runs more than
; once in a while is the snapshot (about every 20 s) - FolState makes at most ten of them.
; ---------------------------------------------------------------------------
Faction Function FacCurrentFollower() Global
	{Skyrim.esm CurrentFollowerFaction 0x0005C84E - the faction CHIM's MakeFollower writes rank 1 into.}
	return Game.GetForm(0x0005C84E) as Faction
EndFunction

Faction Function FacPotentialFollower() Global
	{Skyrim.esm PotentialFollowerFaction 0x0005C84D.}
	return Game.GetForm(0x0005C84D) as Faction
EndFunction

Faction Function FacDismissed() Global
	{Skyrim.esm DismissedFollowerFaction 0x0005C84C. By FormID: no plugin in this load order carries an
	 EditorID "DismissedFollower", so the editor-id lookup this used to do always returned None.}
	return Game.GetForm(0x0005C84C) as Faction
EndFunction

Quest Function FollowerQuest() Global
	{Skyrim.esm DialogueFollower 0x000750BA - SFF replaces its script, it does not replace the quest.}
	Quest q = Quest.GetQuest("DialogueFollower")
	if !q
		q = Game.GetFormFromFile(0x000750BA, "Skyrim.esm") as Quest
	endif
	return q
EndFunction

GlobalVariable Function GvCanRecruit() Global
	;/SFF's own "a slot is free" global, and this file's SFF-is-installed probe. The EditorID carries an
	 UNDERSCORE: "Simple Follower Framework.esp" defines GLOB 05000001 SFF_CanRecruitMore. The
	 underscore-less spelling exists only as a Papyrus property name inside the DialogueFollower VMAD
	 and resolves to nothing, which made SffPresent() false for the whole of v0.5.1./;
	return PO3_SKSEFunctions.GetFormFromEditorID("SFF_CanRecruitMore") as GlobalVariable
EndFunction

GlobalVariable Function GvFollowerCount() Global
	{GLOB 05000002 SFF_CurrentFollowerCount - underscore, like SFF_CanRecruitMore above.}
	return PO3_SKSEFunctions.GetFormFromEditorID("SFF_CurrentFollowerCount") as GlobalVariable
EndFunction

bool Function SffPresent() Global
	{True only when SFF's plugin is really loaded. EVERY SFF_SKSE / DialogueFollowerScript call in this
	 file is behind this test, so a load order without SFF never reaches an unresolved script.}
	return GvCanRecruit() != None
EndFunction

; ---------------------------------------------------------------------------
; Cheap per-actor facts
; ---------------------------------------------------------------------------
int Function CffRank(Actor akNpc) Global
	{Vanilla CurrentFollowerFaction rank, -1 when she is not in it.}
	if !akNpc
		return -1
	endif
	Faction f = FacCurrentFollower()
	if !f
		return -1
	endif
	return akNpc.GetFactionRank(f)
EndFunction

int Function PffRank(Actor akNpc) Global
	if !akNpc
		return -1
	endif
	Faction f = FacPotentialFollower()
	if !f
		return -1
	endif
	return akNpc.GetFactionRank(f)
EndFunction

int Function WaitAv(Actor akNpc) Global
	{WaitingForPlayer: 0 following, 1 waiting, -1 = Inigo's own "dismissed" convention. Written by the
	 glue only through SffWait below (SFF's own FollowerWait / FollowerFollow first, the value itself as
	 the fallback), never -1 - the real dialogue entry otherwise owns the wait verb (pt9 section 6).}
	if !akNpc
		return 0
	endif
	return akNpc.GetActorValue("WaitingForPlayer") as int
EndFunction

int Function ChimFollowActive(Actor akNpc) Global
	{CHIM's own package follow (AIAgentAIMind.stayAtPlace(npc,1) sets it). Not a recruitment.}
	if !akNpc
		return 0
	endif
	return StorageUtil.GetIntValue(akNpc, "CHIM_FollowPlayerActive", 0)
EndFunction

bool Function IsSff(Actor akNpc) Global
	{The cheapest truthful "is she a follower of the framework": SFF's own SKSE native.}
	if !akNpc || !SffPresent()
		return false
	endif
	return SFF_SKSE.IsVanillaFollower(akNpc)
EndFunction

bool Function IsGhost(Actor akNpc) Global
	;/The 4.1 state, and nothing else: in the vanilla CurrentFollowerFaction but NOT a teammate.
	 A real recruit of any framework in this list sets the teammate flag (SFF's PrepareFollowerActor,
	 and the custom followers' own quests), and a dismissal clears the faction with the alias - so
	 this pair is CHIM's signature. The two extra tests keep a dismissed custom follower out of it:
	 Inigo parks himself at WaitingForPlayer -1, and every framework here adds DismissedFollowerFaction./;
	if !akNpc || akNpc.IsPlayerTeammate()
		return false
	endif
	if CffRank(akNpc) < 1
		return false
	endif
	if WaitAv(akNpc) == -1
		return false ; Inigo's dismissed convention - never touch it
	endif
	Faction dis = FacDismissed()
	if dis && akNpc.IsInFaction(dis)
		return false
	endif
	return true
EndFunction

bool Function IsFollowerLike(Actor akNpc) Global
	;/"She already walks with the player", whoever arranged it: a real teammate (SFF, vanilla, Inigo,
	 Lucien, Auri, Remiel, Taliesin, Serana), a CHIM package follow, or a CHIM ghost. This is what the
	 conversation hold and the witness scan ask instead of the bare IsPlayerTeammate() they used to./;
	if !akNpc
		return false
	endif
	if akNpc.IsPlayerTeammate()
		return true
	endif
	if ChimFollowActive(akNpc) == 1
		return true
	endif
	return CffRank(akNpc) >= 1
EndFunction

string Function Framework(Actor akNpc) Global
	{none | sff | custom | chim. custom = a teammate no SFF alias owns (Inigo, Lucien, Auri, ...).}
	if !akNpc
		return "none"
	endif
	if IsSff(akNpc)
		return "sff"
	endif
	if akNpc.IsPlayerTeammate()
		return "custom"
	endif
	if CffRank(akNpc) >= 1 || ChimFollowActive(akNpc) == 1
		return "chim"
	endif
	return "none"
EndFunction

; ---------------------------------------------------------------------------
; The wire block. One additive snapshot key, "fol=", k:v pairs separated by commas so the whole
; thing is one value of the k=v;k=v snapshot and an older server simply ignores it.
; ---------------------------------------------------------------------------
string Function SffBlock(Actor akNpc) Global
	;/[0.5.2] The heaviest SFF part of fol=, split out of FolState: GetMaxFollowers, the two globals
	 and the call on the script SFF puts on DialogueFollower. Together with IsVanillaFollower (used
	 by Framework/IsSff above) this is the only code in the glue that runs inside another mod, and
	 it had never once executed in game before playtest 10 - the EditorID SffPresent() probes was
	 misspelled until the go-live pass, so the whole SFF half was dead in every build before it.
	 Every value is taken into a local and range-checked before it reaches the string, and the block
	 returns "" whenever SFF is not really there: an ABSENT slot:/cap:/prim: already means "say
	 nothing" to the server, so losing it costs a hint and never a snapshot./;
	if !akNpc || !SffPresent()
		return ""
	endif
	int used = 0
	GlobalVariable gc = GvFollowerCount()
	if gc
		used = gc.GetValue() as int
	endif
	if used < 0
		used = 0
	endif
	int cap = 0
	GlobalVariable gr = GvCanRecruit()
	if gr && gr.GetValue() >= 1.0
		cap = 1
	endif
	int slots = SFF_SKSE.GetMaxFollowers()
	if slots < 0
		slots = 0
	endif
	string s = ",slot:" + used + "/" + slots
	s += ",cap:" + cap
	if IsSff(akNpc)
		; the role is only asked for once she really is one of SFF's, so the cast costs nothing
		; on an ordinary townsperson's snapshot
		Quest fq = FollowerQuest()
		if fq
			DialogueFollowerScript dfs = fq as DialogueFollowerScript
			if dfs
				s += ",prim:" + LRG_Profile.B2I(dfs.SFF_IsPrimaryFollower(akNpc))
			endif
		endif
	endif
	return s
EndFunction

string Function FolState(Actor akNpc) Global
	{fw:<none|sff|custom|chim>,mate:0/1,cff:<n>,pff:<n>,wait:<n>,chim:0/1,ghost:0/1[,slot:u/m,cap:0/1,prim:0/1]}
	if !akNpc
		return "fw:none"
	endif
	string s = "fw:" + Framework(akNpc)
	s += ",mate:" + LRG_Profile.B2I(akNpc.IsPlayerTeammate())
	s += ",cff:" + CffRank(akNpc)
	s += ",pff:" + PffRank(akNpc)
	s += ",wait:" + WaitAv(akNpc)
	s += ",chim:" + ChimFollowActive(akNpc)
	s += ",ghost:" + LRG_Profile.B2I(IsGhost(akNpc))
	return s + SffBlock(akNpc)
EndFunction

; ---------------------------------------------------------------------------
; THE REPAIR (research pt9 G3). Never called from a CHIM event: LRG_Main schedules it onto its own
; tick. (0.5.1 fix pass: SetFollower / PrepareFollowerActor do NOT wait - the one Utility.Wait in
; DialogueFollowerScript.pex is in CleanupDismissedFollowerActor. The deferral stays anyway: the
; repair must not run while CHIM's own faction writes for this actor are still settling, and
; SetFollower moves relationship rank, teammate flag, alias and the SFF DLL in one go.)
; aiMode: 0 = promote when a slot is free, otherwise undo CHIM's edit
;         1 = always undo CHIM's edit
;         2 = report only, change nothing
; Returns: 0 nothing to do  1 promoted to a real follower  2 CHIM's edit undone  3 reported only
;          -1 refused (she is not a ghost any more, or SFF cannot take her and mode said promote-only)
; ---------------------------------------------------------------------------
int Function RepairGhost(Actor akNpc, int aiMode = 0) Global
	if !akNpc || !IsGhost(akNpc)
		return 0
	endif
	if aiMode == 2
		return 3
	endif
	if aiMode == 0 && SffPresent()
		GlobalVariable gr = GvCanRecruit()
		if gr && gr.GetValue() >= 1.0
			DialogueFollowerScript dfs = FollowerQuest() as DialogueFollowerScript
			if dfs
				; SetFollower enforces the slot cap itself, raises the relationship to Ally, sets the
				; teammate flag and registers her with SFF's DLL - i.e. it makes her exactly the
				; follower the real dialogue entry would have made.
				; [pt17] CHIM's follow-type moves come off her FIRST: a priority-100 override of
				; CHIM's would out-drive SFF's alias packages on the follower she is about to be.
				ChimFollowVeto(akNpc)
				dfs.SetFollower(akNpc as ObjectReference)
				if !IsGhost(akNpc)
					StorageUtil.SetIntValue(akNpc, "LRG_SffRecruit", 1)
					return 1
				endif
			endif
		endif
	endif
	;/Undo CHIM's faction edit. She keeps walking with the player through CHIM's own package follow,
	 which is not a recruitment and which no framework mistakes for one.

	 ONLY CurrentFollowerFaction is removed, and that is deliberate. PotentialFollowerFaction
	 (0005C84D) is a BASE-RECORD faction of every recruitable NPC in the game and the vanilla recruit
	 line is conditioned on being IN it (Skyrim.esm INFO 000D8DE0 / 0005C829, CTDA GetInFaction
	 PotentialFollowerFaction == 1). Removing it would delete her own "Follow me. I need your help."
	 entry for the rest of that save - a permanent loss, and strictly more than the edit we claim to
	 undo: CHIM's AIAgentNpcUtil only ever AddToFaction / SetFactionRank(PFF, 0), it never removes it.
	 Removing CurrentFollowerFaction alone fully clears the ghost - IsGhost(), CHIM's own
	 isInFaction(VanillaCurrentFollowerFaction) tests and SFF's GetDialogueFollowerTarget all key off
	 CurrentFollowerFaction. If CHIM's rank write ever has to be reverted, use SetFactionRank(pf, 0)./;
	Faction cf = FacCurrentFollower()
	if !cf
		return 0 ; nothing was resolved, so nothing was changed - never report an edit we did not make
	endif
	akNpc.RemoveFromFaction(cf)
	if ChimFollowActive(akNpc) != 1
		ChimFollowPlayer(akNpc)
	endif
	return 2
EndFunction

Function ChimFollowPlayer(Actor akNpc) Global
	;/CHIM's own package follow, applied with CHIM's own forms - the three statements of
	 AIAgentAIMind.stayAtPlace(npc, 1) (AIAgentAIMind.psc:913-922), transcribed.

	 Why transcribed and not called: a direct AIAgentAIMind.stayAtPlace() call makes the compiler
	 build CHIM's whole AIAgentAIMind.psc, which does not compile outside CHIM's own build (RaceMenu,
	 ConsoleUtil, UIExtensions, VRIK and six vanilla script types are missing here) - the glue would
	 stop building. Using CHIM's forms keeps CHIM the OWNER: its ResetPackages removes
	 FollowPlayerPackage by form and clears CHIM_FollowPlayerActive, so its next EndConversation
	 cleans this up exactly as if CHIM had applied it.

	 Deliberately NOT copied from stayAtPlace: its ResetPackages() call (we are adding one package,
	 not re-tasking her) and its BardAudienceExcludedFaction rank 1, which that function never
	 removes again - a permanent, invisible edit on somebody else's actor (pt9 risk R7)./;
	if !akNpc
		return
	endif
	Package p = Game.GetFormFromFile(0x0002226D, "AIAgent.esp") as Package
	Faction ff = Game.GetFormFromFile(0x0001BC24, "AIAgent.esp") as Faction
	if !p || !ff
		return ; no CHIM: she simply keeps her own schedule, which is the honest outcome
	endif
	akNpc.SetFactionRank(ff, 1)
	ActorUtil.AddPackageOverride(akNpc, p, 100, 0)
	StorageUtil.SetIntValue(akNpc, "CHIM_FollowPlayerActive", 1)
	akNpc.EvaluatePackage()
EndFunction

; ---------------------------------------------------------------------------
; [pt16] THE NATURAL FOLLOW (research/pt16-follow.md, playtest 16: "she would just sprint as fast as
; she could and then stop"). CHIM's own follow package (AIAgent.esp PACK 0x2226D) is a Follow procedure
; authored with Min Radius 512 / Max Radius 1024 units and Need LOS = 1 - four times the radii of the
; game's own follower packages (Skyrim.esm FollowPlayer 0x750BE: 128 / 256, no LOS rule). Package
; inputs cannot be changed at runtime and AIAgent.esp is read-only, so the glue carries a PACK of its
; own (LoreRimGlue.esp 0x803 LRG_FollowPackage: CHIM's record byte for byte with those three inputs
; patched, no condition - tools/make_esp.py) and lays it OVER CHIM's follow. PapyrusUtil runs the LAST
; ADDED of two overrides at the same priority (ActorUtil.psc:6-9) and CHIM's sits at 100, the ceiling,
; so ours goes on after CHIM's and is put on again whenever CHIM re-adds its own (LRG_Main.TickGlueFollow).
; CHIM stays the owner of CHIM_FollowPlayerActive and of its own package; nothing on the wire changes.
; These are the helpers only - every decision (the MCM toggle, teammates, CHIM's own moves, the ring
; that watches her) is LRG_Main's, in its escort section.
; StorageUtil key on the NPC, the glue's own: LRG_GlueFollow (1 while our package is on her).
; ---------------------------------------------------------------------------
Package Function GluePkg() Global
	{LoreRimGlue.esp PACK 0x803 LRG_FollowPackage (tools/make_esp.py). None = the plugin is not loaded.}
	return Game.GetFormFromFile(0x00000803, "LoreRimGlue.esp") as Package
EndFunction

Package Function ChimFollowPkg() Global
	{AIAgent.esp PACK 0x2226D AIAgentFollowPlayerPackage - the one stayAtPlace(npc, 1) puts on at 100.}
	return Game.GetFormFromFile(0x0002226D, "AIAgent.esp") as Package
EndFunction

Keyword Function ChimMoveTargetKw() Global
	{AIAgent.esp KYWD 0x21245 - the linked-ref keyword CHIM's own soft moves set (FollowSoft) and clear.}
	return Game.GetFormFromFile(0x00021245, "AIAgent.esp") as Keyword
EndFunction

int Function GlueFollowMine(Actor akNpc) Global
	{1 while the glue's own follow package is on her (StorageUtil LRG_GlueFollow, written only below).}
	if !akNpc
		return 0
	endif
	return StorageUtil.GetIntValue(akNpc, "LRG_GlueFollow", 0)
EndFunction

bool Function GlueFollowOn(Actor akNpc) Global
	;/Puts the glue's follow package on top of CHIM's. Remove-then-add on purpose: PapyrusUtil runs the
	 LAST ADDED of two overrides at the same priority and CHIM's is at 100, the ceiling, so ours has to
	 be the newest one every single time. Returns false, and changes nothing, when the plugin's PACK
	 cannot be resolved - a caller never logs a follow that is not on./;
	if !akNpc
		return false
	endif
	Package p = GluePkg()
	if !p
		return false
	endif
	ActorUtil.RemovePackageOverride(akNpc, p)
	ActorUtil.AddPackageOverride(akNpc, p, 100, 0)
	StorageUtil.SetIntValue(akNpc, "LRG_GlueFollow", 1)
	akNpc.EvaluatePackage()
	return true
EndFunction

bool Function GlueFollowOff(Actor akNpc) Global
	{Takes the glue's package off and forgets it. One StorageUtil read when it was never on. Returns whether it was on.}
	if !akNpc
		return false
	endif
	if StorageUtil.GetIntValue(akNpc, "LRG_GlueFollow", 0) != 1
		return false
	endif
	Package p = GluePkg()
	if p
		ActorUtil.RemovePackageOverride(akNpc, p)
	endif
	StorageUtil.UnsetIntValue(akNpc, "LRG_GlueFollow")
	akNpc.EvaluatePackage()
	return true
EndFunction

bool Function GlueFollowLost(Actor akNpc) Global
	;/True when CHIM's own follow package is the one RUNNING on her: CHIM re-added it after ours
	 (stayAtPlace again, EndFollowSoft, MoveToPlayer) and it wins the tie as the newest, so ours has to
	 be put on again. One SKSE native (Actor.GetCurrentPackage, the call LRG_Main.ConvQuestPackage and
	 CHIM's AIAgentAIMind.psc:1896 already make) and one GetFormFromFile./;
	if !akNpc
		return false
	endif
	Package cur = akNpc.GetCurrentPackage()
	if !cur
		return false
	endif
	Package chim = ChimFollowPkg()
	if !chim
		return false
	endif
	return cur == chim
EndFunction

bool Function ChimSoftMoveOn(Actor akNpc) Global
	;/True while CHIM is moving her itself: FollowSoft (ComeCloser, GetIntoConversation, "NPCs Walk To
	 Target") sets the MoveTarget linked ref and adds its soft package at priority 55, which an override
	 of ours at 100 would starve - its end fragment (PF_AIAgentFollowPackageSoft -> EndFollowSoft, the
	 one place CHIM restores its own follow afterwards) would never fire. ResetPackages, EndFollowSoft,
	 GetIntoConversation's arrival and the walk-to-target release all clear the ref again. One
	 GetFormFromFile and one GetLinkedRef./;
	if !akNpc
		return false
	endif
	Keyword kw = ChimMoveTargetKw()
	if !kw
		return false
	endif
	return akNpc.GetLinkedRef(kw) != None
EndFunction

; ---------------------------------------------------------------------------
; [pt17] THE HAND-OFF TO SIMPLE FOLLOWER FRAMEWORK (research/pt17-followers.md). Owner, 2026-09-23:
; "if chim is managing it or if you are managing it lets change it to whatever follower mod is
; managing it and work with it that way because the follower just looks straight clunky in game."
; Helpers only - LRG_Main's escort section decides (bSffFollow:Followers). Every SFF call is behind
; SffPresent(); every CHIM form is looked up by file; a load order without either is a no-op.
; StorageUtil key on the NPC, the glue's own: LRG_SffRecruit (1 = the glue recruited her).
; What a script recruit bypasses (pt17 2.4): the recruit INFO's conditions - so SffRefuseReason
; re-applies the ones a script can (PotentialFollowerFaction, SFF's own slot global, a hireling's
; price line). Requiem's per-NPC gates are INFO conditions and stay the real entry's business.
; ---------------------------------------------------------------------------
Package Function ChimFollowSoftPkg() Global
	{AIAgent.esp PACK 0x268B0 AIAgentFollowPackageSoft - FollowSoft's Always-Run soft move (ComeCloser, walk-to) at 55.}
	return Game.GetFormFromFile(0x000268B0, "AIAgent.esp") as Package
EndFunction

Package Function ChimFollowOldPkg() Global
	{AIAgent.esp PACK 0x1BC25 AIAgentFollowPackage - CHIM's "Follow" (another actor) package.}
	return Game.GetFormFromFile(0x0001BC25, "AIAgent.esp") as Package
EndFunction

Faction Function ChimFollowFaction() Global
	{AIAgent.esp FACT 0x1BC24 AIAgentFactionFollow - rank 1 is what CHIM's follow packages run on.}
	return Game.GetFormFromFile(0x0001BC24, "AIAgent.esp") as Faction
EndFunction

bool Function ChimFollowVeto(Actor akNpc) Global
	;/Takes EVERY follow-type move of CHIM's off her, in the one order that cannot race CHIM's own end
	 fragment: PF_AIAgentFollowPackageSoft.Fragment_3 -> AIAgentAIMind.EndFollowSoft runs on its own
	 thread the moment the soft package ends and RE-ADDS FollowPlayerPackage at 100 when
	 CHIM_FollowPlayerActive still reads 1. So: the flag is written 0 FIRST; the MoveTarget linked ref
	 is cleared; CHIM's bookkeeping for the soft move is cleared (PackageSoft / WalkToTarget*, exactly
	 what GetIntoConversation and CheckAndReleaseWalkToTargetNPCs clear - or the former would still
	 believe a soft package runs on her and StartWaitSoft a companion at < 300 units); the two hard
	 follow overrides and CHIM's follow faction go; the glue's own overlay goes; the soft package goes
	 LAST (its end fragment then finds the flag 0 and only tidies up). The same removals by form as
	 CHIM's ResetPackages, so CHIM's later EndConversation is unaffected; a missing override is a
	 no-op. Returns whether anything of CHIM's or ours was on her./;
	if !akNpc
		return false
	endif
	bool was = ChimFollowActive(akNpc) == 1 || ChimSoftMoveOn(akNpc) || GlueFollowMine(akNpc) == 1
	Faction ff = ChimFollowFaction()
	if ff && akNpc.IsInFaction(ff)
		was = true
	endif
	StorageUtil.SetIntValue(akNpc, "CHIM_FollowPlayerActive", 0)
	Keyword kw = ChimMoveTargetKw()
	if kw
		PO3_SKSEFunctions.SetLinkedRef(akNpc, None, kw)
	endif
	StorageUtil.SetFormValue(akNpc, "PackageSoft", None)
	StorageUtil.UnsetFloatValue(akNpc, "WalkToTargetStartTime")
	StorageUtil.UnsetFormValue(akNpc, "WalkToTargetListener")
	Package p = ChimFollowPkg()
	if p
		ActorUtil.RemovePackageOverride(akNpc, p)
	endif
	p = ChimFollowOldPkg()
	if p
		ActorUtil.RemovePackageOverride(akNpc, p)
	endif
	if ff
		akNpc.RemoveFromFaction(ff)
	endif
	GlueFollowOff(akNpc)
	p = ChimFollowSoftPkg()
	if p
		ActorUtil.RemovePackageOverride(akNpc, p)
	endif
	akNpc.EvaluatePackage()
	return was
EndFunction

int Function PlayerSpeech() Global
	{The player's Speech as the game has it now - SFF's slot cap is Speech / iSpeechLevelsPerSlot + 1.}
	return Game.GetPlayer().GetActorValue("Speechcraft") as int
EndFunction

string Function SffSlotText() Global
	{"<used>/<max>" from SFF's own count global and the DLL's cap; "" without SFF. Two natives.}
	if !SffPresent()
		return ""
	endif
	int used = 0
	GlobalVariable gc = GvFollowerCount()
	if gc
		used = gc.GetValue() as int
	endif
	if used < 0
		used = 0
	endif
	int slots = SFF_SKSE.GetMaxFollowers()
	if slots < 0
		slots = 0
	endif
	return used + "/" + slots
EndFunction

Faction Function FacHireling() Global
	;/Skyrim.esm JobMercenaryFaction - the six hirelings' "What's your price?" line is conditioned on
	 it, and a script recruit would skip the 500 gold. Looked up by EditorID; None when it cannot be
	 resolved, and the rail is then simply not applied (fail-open: it is an extra rail, not a gate)./;
	return PO3_SKSEFunctions.GetFormFromEditorID("JobMercenaryFaction") as Faction
EndFunction

string Function SffRefuseReason(Actor akNpc) Global
	;/"" = SFF may take her on through SetFollower right now. Otherwise the TECHNICAL reason (the log,
	 the corner note, the fb= key on the OK funcret - the server puts it in her words). Order: a
	 teammate of another framework first; then the framework's own truths (installed; a free slot -
	 SFF_CanRecruitMore, the global its own recruit entry is conditioned on, tested BEFORE SetFollower
	 because SetFollower refuses a full party silently); then the game's (PotentialFollowerFaction -
	 the vanilla recruit line's own condition, which SetFollower does not consult; the hireling rail).
	 An SFF follower herself answers "" - the caller asks IsSff first./;
	if !akNpc
		return "she is not here"
	endif
	if akNpc.IsPlayerTeammate() && !IsSff(akNpc)
		return "she already travels with you as somebody else's companion"
	endif
	if !SffPresent()
		return "Simple Follower Framework is not installed"
	endif
	if IsSff(akNpc)
		return ""
	endif
	GlobalVariable gr = GvCanRecruit()
	if !gr || gr.GetValue() < 1.0
		return "you have no free companion slot (" + SffSlotText() + " at Speech " + PlayerSpeech() + ")"
	endif
	if PffRank(akNpc) < 0
		return "the game does not let you recruit her yet"
	endif
	Faction hire = FacHireling()
	if hire && akNpc.IsInFaction(hire)
		return "she is a hireling - her price is in her own dialogue"
	endif
	return ""
EndFunction

int Function SffRecruit(Actor akNpc) Global
	;/The recruit itself: CHIM's follow off her FIRST (ChimFollowVeto - a priority-100 override of
	 CHIM's would out-drive SFF's alias packages), then SetFollower, the real recruit fragment's own
	 call (Missing Follower Dialogue Edit MFMD_FollowerRecruit.psc:9), and the framework's own answer
	 (SFF_SKSE.IsVanillaFollower) decides the result. 1 = she is SFF's now; -1 = SetFollower ran and
	 she is not (the framework refused, silently, as its cap check does); 0 = SFF's script could not be
	 reached, nothing was called. The caller has asked SffRefuseReason first./;
	if !akNpc || !SffPresent()
		return 0
	endif
	DialogueFollowerScript dfs = FollowerQuest() as DialogueFollowerScript
	if !dfs
		return 0
	endif
	ChimFollowVeto(akNpc)
	dfs.SetFollower(akNpc as ObjectReference)
	if !IsSff(akNpc)
		return -1
	endif
	StorageUtil.SetIntValue(akNpc, "LRG_SffRecruit", 1)
	return 1
EndFunction

string Function SffWait(Actor akNpc, bool abWait) Global
	;/Wait / follow-again for a follower SFF owns, through SFF's OWN functions where they can be aimed
	 at her: SFF_SetLastSpeaker(her), and only when GetDialogueFollowerTarget() then really answers HER
	 (it prefers Game.GetDialogueTarget(), None or stale outside a menu, and falls back to the PRIMARY
	 alias - the wrong-follower trap of pt9 4.2) FollowerWait() / FollowerFollow() - the calls her own
	 "Wait here" / "Follow me" entries make, SFF's 72-hour "dismiss if forgotten" timer included. When
	 the target check fails, or SFF's call did not take, the value is written directly (WaitingForPlayer
	 1 / 0, never -1 - Inigo's own convention), which is what SFF's alias packages read anyway. CHIM's
	 follow-type moves come off her either way. Returns which path ran, for the log:
	 "sff" | "sff+av" | "av" | "" (she is not SFF's - nothing was done)./;
	if !akNpc || !IsSff(akNpc)
		return ""
	endif
	int want = 0
	if abWait
		want = 1
	endif
	string how = "av"
	DialogueFollowerScript dfs = FollowerQuest() as DialogueFollowerScript
	if dfs
		dfs.SFF_SetLastSpeaker(akNpc)
		if dfs.GetDialogueFollowerTarget() == akNpc
			if abWait
				dfs.FollowerWait()
			else
				dfs.FollowerFollow()
			endif
			how = "sff"
		endif
	endif
	if (akNpc.GetActorValue("WaitingForPlayer") as int) != want
		akNpc.SetActorValue("WaitingForPlayer", want as float)
		if how == "sff"
			how = "sff+av"
		endif
	endif
	ChimFollowVeto(akNpc)
	akNpc.EvaluatePackage()
	return how
EndFunction
